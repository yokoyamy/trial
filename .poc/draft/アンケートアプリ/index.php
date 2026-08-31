<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし
 * PHP cURLなし
 * 単一エントリーポイント
 *
 * 保存先:
 *   _data/data.json
 *   _data/settings.json
 *   _data/.secret
 *
 * 外部サービス:
 *   kintone REST API
 *   SMTP
 *
 * kintone:
 *   ログイン名 + パスワード
 *   X-Cybozu-Authorization
 *
 * APIトークン認証は使用しない。
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';

const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const KEY_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . '.secret';

const KINTONE_TIMEOUT = 30;
const SMTP_TIMEOUT = 30;

const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;

/* =========================================================
 * 基本
 * ========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function get_string(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function post_string(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function post_bool(string $key): bool
{
    return in_array(
        strtolower(post_string($key)),
        ['1', 'true', 'yes', 'on'],
        true
    );
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

function redirect_screen(string $screen, array $extra = []): never
{
    $params = array_merge(['screen' => $screen], $extra);

    header(
        'Location: ' .
        app_url($params),
        true,
        303
    );

    exit;
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
        (
            !empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        ) ||
        (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

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
 * Flash
 * ========================================================= */

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($value) ? $value : null;
}

/* =========================================================
 * JSON保存
 * ========================================================= */

function ensure_data_dir(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (!@mkdir(DATA_DIR, 0770, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException(
            'データ保存領域を作成できません。'
        );
    }
}

function load_json(string $file, array $fallback): array
{
    if (!is_file($file)) {
        return $fallback;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
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

    $decoded = json_decode(
        $raw,
        true
    );

    return is_array($decoded)
        ? $decoded
        : $fallback;
}

function save_json(string $file, array $data): void
{
    ensure_data_dir();

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

    $tmp = $file .
        '.tmp.' .
        bin2hex(random_bytes(8));

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
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

        if (
            $written === false ||
            $written < strlen($json)
        ) {
            throw new RuntimeException(
                'データを書き込めません。'
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
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
 * 初期データ
 * ========================================================= */

function default_data(): array
{
    $t = now();

    return [
        'surveys' => [[
            'id' => 'survey-001',
            'title' => '顧客満足度アンケート',
            'description' =>
                'サービスについてのご意見をお聞かせください。',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date(
                'Y-m-d\TH:i',
                strtotime('+30 days')
            ),
            'status' => 'draft',
            'numbering' => 'global',
            'createdAt' => $t,
            'updatedAt' => $t,
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
            'verify_ssl' => false,
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
            'last_error' => '',
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
            'last_error' => '',
        ],
    ];
}

function load_data(): array
{
    ensure_data_dir();

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

function load_settings(): array
{
    ensure_data_dir();

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

/* =========================================================
 * ローカル機密情報保護
 *
 * 外部環境変数には依存しない。
 * 初回利用時にランダムな鍵を _data/.secret に生成する。
 * ========================================================= */

function local_secret_key(): string
{
    ensure_data_dir();

    if (is_file(KEY_FILE)) {
        $raw = @file_get_contents(KEY_FILE);

        if (
            is_string($raw) &&
            strlen($raw) >= 32
        ) {
            return substr($raw, 0, 32);
        }
    }

    $key = random_bytes(32);

    if (
        @file_put_contents(
            KEY_FILE,
            $key,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            '機密情報の保存領域を作成できません。'
        );
    }

    @chmod(KEY_FILE, 0600);

    return $key;
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $key = local_secret_key();
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

    return base64_encode(
        json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        )
    );
}

function encrypted_secret(string $value): bool
{
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

    return is_array($payload) &&
        (int)($payload['v'] ?? 0) === 1 &&
        isset(
            $payload['iv'],
            $payload['tag'],
            $payload['data']
        );
}

function decrypt_secret(string $encrypted): string
{
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
        local_secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plain === false) {
        throw new RuntimeException(
            '保存された機密情報を復号できません。'
        );
    }

    return $plain;
}

function runtime_settings(array $settings): array
{
    foreach (
        ['kintone', 'mail'] as $service
    ) {
        $password =
            (string)(
                $settings[$service]['password']
                ?? ''
            );

        if (encrypted_secret($password)) {
            $settings[$service]['password'] =
                decrypt_secret($password);
        }
    }

    return $settings;
}

function settings_for_save(array $settings): array
{
    foreach (
        ['kintone', 'mail'] as $service
    ) {
        $password =
            (string)(
                $settings[$service]['password']
                ?? ''
            );

        if (
            $password !== '' &&
            !encrypted_secret($password)
        ) {
            $settings[$service]['password'] =
                encrypt_secret($password);
        }
    }

    return $settings;
}

function save_settings(array $settings): void
{
    save_json(
        SET_FILE,
        settings_for_save($settings)
    );
}

/* =========================================================
 * アンケート
 * ========================================================= */

function survey_index(
    array $surveys,
    string $id
): int {
    foreach ($surveys as $i => $survey) {
        if (
            (string)($survey['id'] ?? '') ===
            $id
        ) {
            return $i;
        }
    }

    return -1;
}

function survey_get(
    array $surveys,
    string $id
): ?array {
    $i = survey_index($surveys, $id);

    return $i >= 0
        ? $surveys[$i]
        : null;
}

function all_questions(array $survey): array
{
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

function recalc_numbers(array &$survey): void
{
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

function refresh_status(array &$data): void
{
    $changed = false;

    foreach (
        $data['surveys'] as &$survey
    ) {
        if (
            ($survey['status'] ?? '') === 'published' &&
            !empty($survey['endAt'])
        ) {
            $end = strtotime(
                (string)$survey['endAt']
            );

            if (
                $end !== false &&
                $end < time()
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

function status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'gray',
    };
}

function normalize_groups(mixed $input): array
{
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
                        'label' => mb_substr(
                            trim(
                                (string)(
                                    $option['label'] ?? ''
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
                'number' =>
                    (string)(
                        $question['number'] ?? ''
                    ),
                'text' => mb_substr(
                    trim(
                        (string)(
                            $question['text'] ?? ''
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

function validate_survey(array $survey): array
{
    $errors = [];

    $title = trim(
        (string)($survey['title'] ?? '')
    );

    if ($title === '') {
        $errors[] =
            'アンケートタイトルを入力してください。';
    }

    if (
        mb_strlen($title) > MAX_TITLE
    ) {
        $errors[] =
            'アンケートタイトルが長すぎます。';
    }

    $start = (string)(
        $survey['startAt'] ?? ''
    );

    $end = (string)(
        $survey['endAt'] ?? ''
    );

    if ($start !== '' && strtotime($start) === false) {
        $errors[] =
            '開始日時が不正です。';
    }

    if ($end !== '' && strtotime($end) === false) {
        $errors[] =
            '終了日時が不正です。';
    }

    if (
        $start !== '' &&
        $end !== '' &&
        strtotime($start) !== false &&
        strtotime($end) !== false &&
        strtotime($start) >= strtotime($end)
    ) {
        $errors[] =
            '終了日時は開始日時より後にしてください。';
    }

    $questions = all_questions($survey);

    foreach ($questions as $question) {
        if (
            trim(
                (string)(
                    $question['text'] ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                '質問文を入力してください。';
        }

        if (
            in_array(
                $question['type'] ?? '',
                ['single', 'multiple'],
                true
            )
        ) {
            if (
                count(
                    $question['options'] ?? []
                ) === 0
            ) {
                $errors[] =
                    '選択式質問には選択肢を1つ以上設定してください。';
            }
        }
    }

    return $errors;
}

/* =========================================================
 * kintone設定
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

    $proxy = trim(
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

/* =========================================================
 * kintone HTTP
 *
 * 外部API通信だけを担当する。
 * 画面遷移は行わない。
 * ========================================================= */

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

    /*
     * kintone公式仕様:
     * ログイン名:パスワードをBase64化して
     * X-Cybozu-Authorizationへ設定する。
     */
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
        'User-Agent: SurveyApp/1.0',
        'Connection: close',
    ];

    $content = '';

    /*
     * GETのURLパラメータ方式では
     * Content-Typeを付与しない。
     *
     * bodyを送る場合のみJSONを付与する。
     */
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
            'method' => strtoupper($method),
            'header' => implode(
                "\r\n",
                $headers
            ),
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

    if ($content !== '') {
        $options['http']['content'] = $content;
    }

    $proxy = trim(
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
        stream_context_create($options);

    $responseHeaders = [];

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders =
        $http_response_header ?? [];

    $status = 0;

    foreach (
        $responseHeaders as $header
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

    /*
     * APIから302/303が返った場合は
     * 成功とは扱わない。
     */
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

    $json = json_decode(
        $response,
        true
    );

    if (
        $status < 200 ||
        $status >= 300
    ) {
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

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintoneから正常なJSON応答を取得できませんでした。'
        );
    }

    return [
        'status' => $status,
        'body' => $json,
        'headers' => $responseHeaders,
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
        )
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
            'label' => (string)(
                $field['label'] ??
                $code
            ),
            'type' => (string)(
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
                string $v
            ): bool => $v !== ''
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

        $address = [];

        foreach (
            $mapping['address'] ?? [] as $code
        ) {
            $v = krecord(
                $record,
                (string)$code
            );

            if ($v !== '') {
                $address[] = $v;
            }
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
                        $mapping['name'] ?? ''
                    )
                ),
            'email' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['email'] ?? ''
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
                        $mapping['phone'] ?? ''
                    )
                ),
            'address' =>
                implode(
                    ' ',
                    $address
                ),
            'syncedAt' => now(),
        ];
    }

    return $customers;
}

/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail(array $config): array
{
    $errors = [];

    $host = trim(
        (string)($config['host'] ?? '')
    );

    if ($host === '') {
        $errors[] =
            'SMTPサーバを入力してください。';
    }

    $port = (int)(
        $config['port'] ?? 0
    );

    if (
        $port < 1 ||
        $port > 65535
    ) {
        $errors[] =
            'SMTPポートが不正です。';
    }

    $encryption =
        (string)(
            $config['encryption'] ?? ''
        );

    if (
        !in_array(
            $encryption,
            ['tls', 'ssl', 'none'],
            true
        )
    ) {
        $errors[] =
            'SMTP暗号化方式が不正です。';
    }

    $from =
        trim(
            (string)(
                $config['from_email'] ?? ''
            )
        );

    if (
        $from !== '' &&
        !filter_var(
            $from,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    $reply =
        trim(
            (string)(
                $config['reply_to'] ?? ''
            )
        );

    if (
        $reply !== '' &&
        !filter_var(
            $reply,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '返信先メールアドレスが不正です。';
    }

    return $errors;
}

function smtp_read(
    $socket,
    int $timeout = SMTP_TIMEOUT
): string {
    stream_set_timeout(
        $socket,
        $timeout
    );

    $result = '';

    while (!feof($socket)) {
        $line = fgets(
            $socket,
            4096
        );

        if ($line === false) {
            break;
        }

        $result .= $line;

        if (
            preg_match(
                '/^\d{3}\s/',
                $line
            )
        ) {
            break;
        }
    }

    if ($result === '') {
        throw new RuntimeException(
            'SMTPサーバから応答を取得できませんでした。'
        );
    }

    return $result;
}

function smtp_expect(
    $socket,
    array $codes
): string {
    $response =
        smtp_read($socket);

    $code = (int)substr(
        trim($response),
        0,
        3
    );

    if (!in_array(
        $code,
        $codes,
        true
    )) {
        throw new RuntimeException(
            'SMTPエラー: ' .
            $code
        );
    }

    return $response;
}

function smtp_write(
    $socket,
    string $command
): void {
    if (
        fwrite(
            $socket,
            $command . "\r\n"
        ) === false
    ) {
        throw new RuntimeException(
            'SMTPサーバへの送信に失敗しました。'
        );
    }
}

function smtp_connect(
    array $config
): array {
    $errors =
        validate_mail($config);

    if ($errors !== []) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $host = trim(
        (string)$config['host']
    );

    $port = (int)$config['port'];

    $encryption =
        (string)$config['encryption'];

    if ($encryption === 'ssl') {
        $target =
            'ssl://' . $host . ':' . $port;
    } else {
        $target =
            $host . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。'
        );
    }

    stream_set_timeout(
        $socket,
        SMTP_TIMEOUT
    );

    try {
        smtp_expect(
            $socket,
            [220]
        );

        smtp_write(
            $socket,
            'EHLO localhost'
        );

        smtp_expect(
            $socket,
            [250]
        );

        if ($encryption === 'tls') {
            smtp_write(
                $socket,
                'STARTTLS'
            );

            smtp_expect(
                $socket,
                [220]
            );

            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTP TLS接続に失敗しました。'
                );
            }

            smtp_write(
                $socket,
                'EHLO localhost'
            );

            smtp_expect(
                $socket,
                [250]
            );
        }

        if (!empty($config['auth'])) {
            $username =
                (string)(
                    $config['username'] ?? ''
                );

            $password =
                (string)(
                    $config['password'] ?? ''
                );

            smtp_write(
                $socket,
                'AUTH LOGIN'
            );

            smtp_expect(
                $socket,
                [334]
            );

            smtp_write(
                $socket,
                base64_encode($username)
            );

            smtp_expect(
                $socket,
                [334]
            );

            smtp_write(
                $socket,
                base64_encode($password)
            );

            smtp_expect(
                $socket,
                [235]
            );
        }

        return [$socket, null];
    } catch (Throwable $e) {
        @fclose($socket);
        throw $e;
    }
}

function smtp_test(array $config): void
{
    [$socket] =
        smtp_connect($config);

    smtp_write(
        $socket,
        'QUIT'
    );

    @smtp_read(
        $socket
    );

    @fclose($socket);
}

function smtp_send_mail(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new RuntimeException(
            '送信先メールアドレスが不正です。'
        );
    }

    [$socket] =
        smtp_connect($config);

    try {
        $from =
            (string)$config['from_email'];

        smtp_write(
            $socket,
            'MAIL FROM:<' . $from . '>'
        );

        smtp_expect(
            $socket,
            [250]
        );

        smtp_write(
            $socket,
            'RCPT TO:<' . $to . '>'
        );

        smtp_expect(
            $socket,
            [250, 251]
        );

        smtp_write(
            $socket,
            'DATA'
        );

        smtp_expect(
            $socket,
            [354]
        );

        $headers = [
            'From: ' .
                mb_encode_mimeheader(
                    (string)(
                        $config['from_name'] ?? ''
                    ) ?: $from,
                    'UTF-8'
                ) .
                ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' .
                mb_encode_mimeheader(
                    $subject,
                    'UTF-8'
                ),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

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
                "\n.",
                "\n..",
                str_replace(
                    "\r\n",
                    "\n",
                    $body
                )
            ) .
            "\r\n.";

        smtp_write(
            $socket,
            $message
        );

        smtp_expect(
            $socket,
            [250]
        );

        smtp_write(
            $socket,
            'QUIT'
        );

        @smtp_read(
            $socket
        );
    } finally {
        @fclose($socket);
    }
}

/* =========================================================
 * エラー表示用
 * ========================================================= */

function safe_external_error(
    Throwable $e
): string {
    $message = trim(
        $e->getMessage()
    );

    /*
     * 認証ヘッダー等を誤って表示しない。
     */
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

    return mb_substr(
        $message,
        0,
        1000
    );
}


/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): ?array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }

    $action = post_string('action');

    if ($action === '') {
        return null;
    }

    /* -----------------------------------------------------
     * アンケート保存
     * ----------------------------------------------------- */

    if ($action === 'save_survey') {
        $id = post_string('id');

        $index =
            $id !== ''
                ? survey_index(
                    $data['surveys'],
                    $id
                )
                : -1;

        $existing =
            $index >= 0
                ? $data['surveys'][$index]
                : null;

        $survey = [
            'id' =>
                $id !== ''
                    ? $id
                    : uid('survey'),
            'title' =>
                mb_substr(
                    post_string('title'),
                    0,
                    MAX_TITLE
                ),
            'description' =>
                mb_substr(
                    post_string('description'),
                    0,
                    MAX_DESCRIPTION
                ),
            'startAt' =>
                post_string('startAt'),
            'endAt' =>
                post_string('endAt'),
            'status' =>
                (string)(
                    $existing['status'] ??
                    'draft'
                ),
            'numbering' =>
                post_string(
                    'numbering',
                    'global'
                ) === 'group'
                    ? 'group'
                    : 'global',
            'createdAt' =>
                $existing['createdAt'] ??
                now(),
            'updatedAt' => now(),
            'groups' =>
                normalize_groups(
                    $_POST['groups'] ?? []
                ),
        ];

        recalc_numbers($survey);

        $errors =
            validate_survey($survey);

        /*
         * 終了状態は手動変更不可。
         */
        if (
            ($existing['status'] ?? '') ===
            'ended'
        ) {
            $survey['status'] = 'ended';
        }

        if ($errors !== []) {
            flash(
                'error',
                implode(
                    "\n",
                    $errors
                )
            );

            return [
                'screen' =>
                    $id !== ''
                        ? 'edit'
                        : 'edit',
                'id' =>
                    $survey['id'],
            ];
        }

        if ($index >= 0) {
            $data['surveys'][$index] =
                $survey;
        } else {
            $data['surveys'][] =
                $survey;
        }

        save_data($data);

        flash(
            'success',
            'アンケートを保存しました。'
        );

        return [
            'screen' => 'list',
        ];
    }

    /* -----------------------------------------------------
     * 公開
     * ----------------------------------------------------- */

    if ($action === 'publish_survey') {
        $id = post_string('id');

        $index =
            survey_index(
                $data['surveys'],
                $id
            );

        if ($index < 0) {
            flash(
                'error',
                '対象アンケートが見つかりません。'
            );

            return [
                'screen' => 'list',
            ];
        }

        $survey =
            $data['surveys'][$index];

        if (
            ($survey['status'] ?? '') ===
            'ended'
        ) {
            flash(
                'error',
                '終了したアンケートは公開できません。'
            );

            return [
                'screen' => 'list',
            ];
        }

        $errors =
            validate_survey($survey);

        if ($errors !== []) {
            flash(
                'error',
                implode(
                    "\n",
                    $errors
                )
            );

            return [
                'screen' => 'edit',
                'id' => $id,
            ];
        }

        $survey['status'] =
            'published';

        $survey['updatedAt'] =
            now();

        $data['surveys'][$index] =
            $survey;

        save_data($data);

        flash(
            'success',
            'アンケートを公開しました。'
        );

        return [
            'screen' => 'list',
        ];
    }

    /* -----------------------------------------------------
     * 停止
     * ----------------------------------------------------- */

    if ($action === 'stop_survey') {
        $id = post_string('id');

        $index =
            survey_index(
                $data['surveys'],
                $id
            );

        if ($index < 0) {
            flash(
                'error',
                '対象アンケートが見つかりません。'
            );

            return [
                'screen' => 'list',
            ];
        }

        if (
            ($data['surveys'][$index]['status'] ?? '') ===
            'ended'
        ) {
            flash(
                'error',
                '終了したアンケートは停止状態へ変更できません。'
            );

            return [
                'screen' => 'list',
            ];
        }

        $data['surveys'][$index]['status'] =
            'stopped';

        $data['surveys'][$index]['updatedAt'] =
            now();

        save_data($data);

        flash(
            'success',
            'アンケートを停止しました。'
        );

        return [
            'screen' => 'list',
        ];
    }

    /* -----------------------------------------------------
     * 複製
     * ----------------------------------------------------- */

    if ($action === 'duplicate_survey') {
        $id = post_string('id');

        $survey =
            survey_get(
                $data['surveys'],
                $id
            );

        if ($survey === null) {
            flash(
                'error',
                '対象アンケートが見つかりません。'
            );

            return [
                'screen' => 'list',
            ];
        }

        $survey['id'] =
            uid('survey');

        $survey['title'] =
            mb_substr(
                (string)$survey['title'] .
                '（複製）',
                0,
                MAX_TITLE
            );

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
    }

    /* -----------------------------------------------------
     * 削除
     * ----------------------------------------------------- */

    if ($action === 'delete_survey') {
        $id = post_string('id');

        $index =
            survey_index(
                $data['surveys'],
                $id
            );

        if ($index < 0) {
            flash(
                'error',
                '対象アンケートが見つかりません。'
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
    }

    /* -----------------------------------------------------
     * kintone設定保存
     * ----------------------------------------------------- */

    if ($action === 'save_kintone') {
        $current =
            runtime_settings($settings);

        $password =
            post_string('password');

        if ($password === '') {
            $password =
                (string)(
                    $current['kintone'][
                        'password'
                    ] ?? ''
                );
        }

        $config = [
            'subdomain' =>
                post_string('subdomain'),
            'app_id' =>
                post_string('app_id'),
            'username' =>
                post_string('username'),
            'password' =>
                $password,
            'proxy' =>
                post_string('proxy'),
            'verify_ssl' =>
                post_bool('verify_ssl'),
            'mapping' =>
                $current['kintone']['mapping']
                ?? [],
            'fields' =>
                $current['kintone']['fields']
                ?? [],
            'last_test' =>
                $current['kintone']['last_test']
                ?? null,
            'last_sync' =>
                $current['kintone']['last_sync']
                ?? null,
            'last_error' => '',
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

        save_settings($settings);

        flash(
            'success',
            'kintone接続設定を保存しました。'
        );

        return [
            'screen' => 'kintone',
        ];
    }

    /* -----------------------------------------------------
     * kintone接続テスト
     *
     * 保存と分離。
     * API通信結果を取得してから画面処理する。
     * ----------------------------------------------------- */

    if ($action === 'test_kintone') {
        try {
            $runtime =
                runtime_settings(
                    $settings
                );

            $response =
                kintone_test(
                    $runtime['kintone']
                );

            if (
                ($response['status'] ?? 0) !==
                200
            ) {
                throw new RuntimeException(
                    'kintone接続テストが正常終了しませんでした。'
                );
            }

            $settings['kintone']['last_test'] =
                now();

            $settings['kintone']['last_error'] =
                '';

            save_settings($settings);

            flash(
                'success',
                'kintone接続テスト成功。'
            );
        } catch (Throwable $e) {
            $settings['kintone']['last_error'] =
                safe_external_error($e);

            save_settings($settings);

            flash(
                'error',
                'kintone接続テスト失敗：' .
                safe_external_error($e)
            );
        }

        return [
            'screen' => 'kintone',
        ];
    }

    /* -----------------------------------------------------
     * kintone項目取得
     * ----------------------------------------------------- */

    if ($action === 'load_kintone_fields') {
        try {
            $runtime =
                runtime_settings(
                    $settings
                );

            $response =
                kintone_fields(
                    $runtime['kintone']
                );

            $fields =
                kintone_field_list(
                    $response
                );

            $settings['kintone']['fields'] =
                $fields;

            $settings['kintone']['last_error'] =
                '';

            save_settings($settings);

            flash(
                'success',
                count($fields) .
                '件のkintone項目を取得しました。'
            );
        } catch (Throwable $e) {
            $settings['kintone']['last_error'] =
                safe_external_error($e);

            save_settings($settings);

            flash(
                'error',
                'kintone項目取得失敗：' .
                safe_external_error($e)
            );
        }

        return [
            'screen' => 'kintone',
        ];
    }

    /* -----------------------------------------------------
     * kintoneマッピング保存
     * ----------------------------------------------------- */

    if ($action === 'save_kintone_mapping') {
        $address =
            $_POST['mapping_address'] ?? [];

        if (!is_array($address)) {
            $address = [];
        }

        $settings['kintone']['mapping'] = [
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

        save_settings($settings);

        flash(
            'success',
            '顧客項目マッピングを保存しました。'
        );

        return [
            'screen' => 'kintone',
        ];
    }

    /* -----------------------------------------------------
     * kintone顧客同期
     *
     * 接続テストとは別処理。
     *
     * API通信
     * ↓
     * 顧客データ生成
     * ↓
     * サーバー保存
     * ↓
     * 顧客一覧
     * ----------------------------------------------------- */

    if ($action === 'sync_kintone') {
        try {
            $runtime =
                runtime_settings(
                    $settings
                );

            $customers =
                sync_kintone_customers(
                    $runtime['kintone']
                );

            $data['customers'] =
                $customers;

            $settings['kintone']['last_sync'] =
                now();

            $settings['kintone']['last_error'] =
                '';

            save_data($data);
            save_settings($settings);

            flash(
                'success',
                count($customers) .
                '件の顧客情報を同期しました。'
            );

            /*
             * 同期成功後は顧客一覧を表示する。
             */
            return [
                'screen' => 'customers',
            ];
        } catch (Throwable $e) {
            $settings['kintone']['last_error'] =
                safe_external_error($e);

            save_settings($settings);

            flash(
                'error',
                'kintone顧客同期失敗：' .
                safe_external_error($e)
            );

            return [
                'screen' => 'kintone',
            ];
        }
    }

    /* -----------------------------------------------------
     * SMTP設定保存
     * ----------------------------------------------------- */

    if ($action === 'save_mail') {
        $runtime =
            runtime_settings(
                $settings
            );

        $password =
            post_string('password');

        if ($password === '') {
            $password =
                (string)(
                    $runtime['mail']['password']
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
                post_string('from_email'),
            'from_name' =>
                post_string('from_name'),
            'reply_to' =>
                post_string('reply_to'),
            'last_test' =>
                $settings['mail']['last_test']
                ?? null,
            'last_error' => '',
        ];

        $errors =
            validate_mail($config);

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

        save_settings($settings);

        flash(
            'success',
            'メール設定を保存しました。'
        );

        return [
            'screen' => 'mail',
        ];
    }

    /* -----------------------------------------------------
     * SMTP接続テスト
     * ----------------------------------------------------- */

    if ($action === 'test_mail') {
        try {
            $runtime =
                runtime_settings(
                    $settings
                );

            smtp_test(
                $runtime['mail']
            );

            $settings['mail']['last_test'] =
                now();

            $settings['mail']['last_error'] =
                '';

            save_settings($settings);

            flash(
                'success',
                'SMTP接続テスト成功。'
            );
        } catch (Throwable $e) {
            $settings['mail']['last_error'] =
                safe_external_error($e);

            save_settings($settings);

            flash(
                'error',
                'SMTP接続テスト失敗：' .
                safe_external_error($e)
            );
        }

        return [
            'screen' => 'mail',
        ];
    }

    /* -----------------------------------------------------
     * テストメール
     * ----------------------------------------------------- */

    if ($action === 'send_test_mail') {
        $to =
            post_string('test_to');

        if (
            !filter_var(
                $to,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            flash(
                'error',
                'テスト送信先メールアドレスが不正です。'
            );

            return [
                'screen' => 'mail',
            ];
        }

        try {
            $runtime =
                runtime_settings(
                    $settings
                );

            smtp_send_mail(
                $runtime['mail'],
                $to,
                'アンケートアプリ テストメール',
                'SMTP接続およびメール送信テストです。'
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
    }

    /* -----------------------------------------------------
     * 回答
     * ----------------------------------------------------- */

    if ($action === 'answer_next') {
        $surveyId =
            post_string('survey_id');

        $survey =
            survey_get(
                $data['surveys'],
                $surveyId
            );

        if ($survey === null) {
            flash(
                'error',
                'アンケートが見つかりません。'
            );

            return [
                'screen' => 'answer',
                'id' => $surveyId,
            ];
        }

        $answers =
            $_POST['answers'] ?? [];

        if (!is_array($answers)) {
            $answers = [];
        }

        $_SESSION[
            'answer_' . $surveyId
        ] = $answers;

        $errors = [];

        foreach (
            all_questions($survey)
            as $question
        ) {
            if (
                empty($question['required'])
            ) {
                continue;
            }

            $qid =
                (string)$question['id'];

            $value =
                $answers[$qid] ?? '';

            $empty =
                is_array($value)
                    ? count($value) === 0
                    : trim(
                        (string)$value
                    ) === '';

            if ($empty) {
                $errors[] =
                    $question['number'] .
                    ' は必須です。';
            }
        }

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

        return [
            'screen' => 'confirm',
            'id' => $surveyId,
        ];
    }

    if ($action === 'answer_back') {
        $surveyId =
            post_string('survey_id');

        $_SESSION[
            'answer_' . $surveyId
        ] =
            is_array(
                $_POST['answers'] ?? null
            )
                ? $_POST['answers']
                : [];

        return [
            'screen' => 'answer',
            'id' => $surveyId,
        ];
    }

    if ($action === 'answer_submit') {
        $surveyId =
            post_string('survey_id');

        $survey =
            survey_get(
                $data['surveys'],
                $surveyId
            );

        if ($survey === null) {
            flash(
                'error',
                'アンケートが見つかりません。'
            );

            return [
                'screen' => 'answer',
                'id' => $surveyId,
            ];
        }

        $answers =
            $_SESSION[
                'answer_' . $surveyId
            ] ?? [];

        if (!is_array($answers)) {
            $answers = [];
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
         * 回答者フローはここで完了。
         * 管理者一覧へ戻さない。
         */
        return [
            'screen' => 'complete',
            'id' => $surveyId,
        ];
    }

    /* -----------------------------------------------------
     * 顧客への一括送信
     * ----------------------------------------------------- */

    if ($action === 'send_mail') {
        $surveyId =
            post_string('survey_id');

        $survey =
            survey_get(
                $data['surveys'],
                $surveyId
            );

        if ($survey === null) {
            flash(
                'error',
                '対象アンケートが見つかりません。'
            );

            return [
                'screen' => 'list',
            ];
        }

        $selected =
            $_POST['customers'] ?? [];

        if (!is_array($selected)) {
            $selected = [];
        }

        $selected =
            array_values(
                array_map(
                    'strval',
                    $selected
                )
            );

        if ($selected === []) {
            flash(
                'error',
                '送信対象を選択してください。'
            );

            return [
                'screen' => 'send',
                'id' => $surveyId,
            ];
        }

        $subject =
            post_string('subject');

        $body =
            post_string('body');

        if ($subject === '') {
            flash(
                'error',
                'メール件名を入力してください。'
            );

            return [
                'screen' => 'send',
                'id' => $surveyId,
            ];
        }

        $runtime =
            runtime_settings(
                $settings
            );

        $sent = 0;
        $failed = 0;

        foreach (
            $data['customers'] as $customer
        ) {
            $customerId =
                (string)(
                    $customer['id'] ?? ''
                );

            if (
                !in_array(
                    $customerId,
                    $selected,
                    true
                )
            ) {
                continue;
            }

            $email =
                trim(
                    (string)(
                        $customer['email'] ?? ''
                    )
                );

            if (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $failed++;
                continue;
            }

            $url =
                public_url(
                    $surveyId
                );

            $name =
                (string)(
                    $customer['name'] ?? ''
                );

            $mailBody =
                str_replace(
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

            try {
                smtp_send_mail(
                    $runtime['mail'],
                    $email,
                    $subject,
                    $mailBody
                );

                $sent++;

                $data['send_history'][] = [
                    'id' => uid('send'),
                    'surveyId' => $surveyId,
                    'customerId' =>
                        $customerId,
                    'email' => $email,
                    'subject' => $subject,
                    'status' => 'sent',
                    'createdAt' => now(),
                ];
            } catch (Throwable $e) {
                $failed++;

                $data['send_history'][] = [
                    'id' => uid('send'),
                    'surveyId' => $surveyId,
                    'customerId' =>
                        $customerId,
                    'email' => $email,
                    'subject' => $subject,
                    'status' => 'failed',
                    'error' =>
                        safe_external_error($e),
                    'createdAt' => now(),
                ];
            }
        }

        save_data($data);

        flash(
            $failed > 0
                ? 'warning'
                : 'success',
            '送信完了：成功 ' .
            $sent .
            '件 / 失敗 ' .
            $failed .
            '件'
        );

        return [
            'screen' => 'send',
            'id' => $surveyId,
        ];
    }

    return null;
}

/* =========================================================
 * 公開URL
 * ========================================================= */

function public_url(string $surveyId): string
{
    $https =
        (
            !empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        ) ||
        (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    $scheme =
        $https ? 'https' : 'http';

    $host =
        (string)(
            $_SERVER['HTTP_HOST'] ??
            'localhost'
        );

    return $scheme .
        '://' .
        $host .
        app_url([
            'screen' => 'answer',
            'id' => $surveyId,
        ]);
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
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",
 Meiryo,sans-serif;
}
header{
 background:#fff;
 border-bottom:1px solid var(--border);
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
 color:var(--primary);
 text-decoration:none;
 font-weight:600;
}
.wrap{
 max-width:1400px;
 margin:auto;
 padding:24px 20px 60px;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:14px;
 padding:22px;
 margin-bottom:18px;
 box-shadow:var(--shadow);
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
 border:0;
 border-radius:8px;
 padding:10px 15px;
 font:inherit;
 cursor:pointer;
 background:var(--primary);
 color:#fff;
 text-decoration:none;
}
button:hover,.btn:hover{
 background:var(--primary-dark);
}
.btn.secondary{
 background:#475569;
}
.btn.success{
 background:var(--success);
}
.btn.warning{
 background:var(--warning);
}
.btn.danger{
 background:var(--danger);
}
.btn.gray{
 background:#64748b;
}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 margin-top:16px;
}
.flash{
 white-space:pre-line;
 padding:14px 16px;
 border-radius:10px;
 margin-bottom:18px;
 font-weight:600;
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
.flash.info{
 background:#dbeafe;
 color:#1e40af;
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
 padding:11px 10px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:top;
}
th{
 background:#f8fafc;
}
.badge{
 display:inline-block;
 padding:4px 9px;
 border-radius:999px;
 font-size:13px;
 font-weight:700;
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
 border:1px solid var(--border);
 border-radius:12px;
 padding:16px;
 margin:12px 0;
 background:#fff;
}
.option{
 display:grid;
 grid-template-columns:1fr auto;
 gap:8px;
 margin:7px 0;
}
.group{
 border:2px solid #e2e8f0;
 border-radius:14px;
 padding:18px;
 margin:18px 0;
}
.group-title{
 font-size:18px;
 font-weight:700;
}
.stat-grid{
 display:grid;
 grid-template-columns:
 repeat(auto-fit,minmax(180px,1fr));
 gap:12px;
}
.stat{
 padding:18px;
 background:#f8fafc;
 border:1px solid var(--border);
 border-radius:12px;
}
.stat strong{
 display:block;
 font-size:28px;
 margin-top:5px;
}
.loading{
 opacity:.6;
 pointer-events:none;
}
.preview-question{
 padding:18px;
 border-bottom:1px solid var(--border);
}
@media(max-width:700px){
 .wrap{
  padding:14px 10px 40px;
 }
 .card{
  padding:15px;
 }
 .nav{
  padding:13px 10px;
 }
 .option{
  grid-template-columns:1fr;
 }
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
<div class="flash <?= h($flash['type'] ?? 'info') ?>">
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
 * 一覧
 * ========================================================= */

function render_list(
    array $data
): void {
    $q =
        get_string('q');

    $status =
        get_string('status');

    $surveys =
        $data['surveys'] ?? [];

    if ($q !== '') {
        $surveys =
            array_filter(
                $surveys,
                static function (
                    array $survey
                ) use ($q): bool {
                    return
                        mb_stripos(
                            (string)(
                                $survey['title'] ?? ''
                            ),
                            $q
                        ) !== false;
                }
            );
    }

    if ($status !== '') {
        $surveys =
            array_filter(
                $surveys,
                static function (
                    array $survey
                ) use ($status): bool {
                    return
                        (string)(
                            $survey['status'] ?? ''
                        ) === $status;
                }
            );
    }

    admin_header(
        'アンケート一覧',
        flash_get()
    );
?>
<div class="card">
<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
<h1 style="margin-right:auto">アンケート一覧</h1>
<a class="btn success"
   href="<?= h(app_url(['screen'=>'edit'])) ?>">
アンケートを作成
</a>
</div>

<form method="get">
<input type="hidden" name="screen" value="list">

<div class="grid">
<div class="field">
<label>検索</label>
<input type="text"
       name="q"
       value="<?= h($q) ?>">
</div>

<div class="field">
<label>状態</label>
<select name="status">
<option value="">すべて</option>
<option value="draft"
 <?= $status==='draft'?'selected':'' ?>>
下書き
</option>
<option value="published"
 <?= $status==='published'?'selected':'' ?>>
公開中
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
</div>

<div class="actions">
<button type="submit">検索</button>
<a class="btn secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
条件クリア
</a>
</div>
</form>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>状態</th>
<th>開始</th>
<th>終了</th>
<th>更新日時</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<?php if ($surveys === []): ?>
<tr>
<td colspan="6">
アンケートがありません。
</td>
</tr>
<?php endif; ?>

<?php foreach ($surveys as $survey): ?>
<tr>
<td>
<strong>
<?= h($survey['title'] ?? '') ?>
</strong>
</td>
<td>
<span class="badge <?= h(
 status_class(
  (string)($survey['status'] ?? '')
 )
) ?>">
<?= h(
 status_label(
  (string)($survey['status'] ?? '')
 )
) ?>
</span>
</td>
<td><?= h($survey['startAt'] ?? '') ?></td>
<td><?= h($survey['endAt'] ?? '') ?></td>
<td><?= h($survey['updatedAt'] ?? '') ?></td>
<td>
<div class="actions">
<a class="btn"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id']
   ])) ?>">
編集
</a>

<a class="btn gray"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id']
   ])) ?>">
プレビュー
</a>

<a class="btn success"
   href="<?= h(app_url([
       'screen'=>'analytics',
       'id'=>$survey['id']
   ])) ?>">
集計
</a>

<a class="btn warning"
   href="<?= h(app_url([
       'screen'=>'send',
       'id'=>$survey['id']
   ])) ?>">
送信
</a>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('このアンケートを複製しますか？')">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">
<button type="submit"
        class="btn secondary">
複製
</button>
</form>

<?php if (($survey['status'] ?? '') !== 'ended'): ?>

<?php if (($survey['status'] ?? '') !== 'published'): ?>
<form method="post"
      style="display:inline"
      onsubmit="return confirm('このアンケートを公開しますか？')">
<input type="hidden"
       name="action"
       value="publish_survey">
<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">
<button type="submit"
        class="btn success">
公開
</button>
</form>
<?php endif; ?>

<?php if (($survey['status'] ?? '') === 'published'): ?>
<form method="post"
      style="display:inline"
      onsubmit="return confirm('このアンケートを停止しますか？')">
<input type="hidden"
       name="action"
       value="stop_survey">
<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">
<button type="submit"
        class="btn warning">
停止
</button>
</form>
<?php endif; ?>

<?php endif; ?>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('本当に削除しますか？')">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">
<button type="submit"
        class="btn danger">
削除
</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php
    admin_footer();
}

/* =========================================================
 * 編集
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

    if ($survey === null) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' =>
                date('Y-m-d\TH:i'),
            'endAt' =>
                date(
                    'Y-m-d\TH:i',
                    strtotime('+30 days')
                ),
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [[
                'id' => uid('group'),
                'title' => '基本アンケート',
                'questions' => [],
            ]],
        ];
    }

    $flash =
        flash_get();

    admin_header(
        $id === null
            ? 'アンケート作成'
            : 'アンケート編集',
        $flash
    );
?>
<div class="card">
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
<h1 style="margin-right:auto">
<?= h(
 $id === null
  ? 'アンケート作成'
  : 'アンケート編集'
) ?>
</h1>

<a class="btn gray"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
キャンセル
</a>

<a class="btn secondary"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id']
   ])) ?>">
プレビュー
</a>
</div>

<form method="post">
<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<div class="grid">
<div class="field">
<label>アンケートタイトル *</label>
<input type="text"
       name="title"
       maxlength="<?= MAX_TITLE ?>"
       required
       value="<?= h($survey['title']) ?>">
</div>

<div class="field">
<label>採番方式</label>
<select name="numbering">
<option value="global"
 <?= ($survey['numbering'] ?? 'global') === 'global'
     ? 'selected'
     : '' ?>>
アンケート全体で通番（Q1、Q2、Q3）
</option>
<option value="group"
 <?= ($survey['numbering'] ?? '') === 'group'
     ? 'selected'
     : '' ?>>
グループ毎（Q1-1、Q1-2、Q2-1）
</option>
</select>
</div>

<div class="field">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt'] ?? '') ?>">
</div>

<div class="field">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt'] ?? '') ?>">
</div>
</div>

<div class="field">
<label>説明</label>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h(
    $survey['description'] ?? ''
) ?></textarea>
</div>

<div id="groups">
<?php foreach (
    $survey['groups'] as $gi => $group
): ?>

<div class="group"
     data-group>

<div class="grid">
<div class="field">
<label>グループタイトル</label>
<input type="hidden"
       name="groups[<?= h($group['id']) ?>][id]"
       value="<?= h($group['id']) ?>">
<input type="text"
       name="groups[<?= h($group['id']) ?>][title]"
       value="<?= h($group['title']) ?>">
</div>
</div>

<div class="questions">
<?php foreach (
    $group['questions'] as $qi => $question
): ?>

<div class="question"
     data-question>

<input type="hidden"
       name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][id]"
       value="<?= h($question['id']) ?>">

<div class="grid">
<div class="field">
<label>質問番号</label>
<input type="text"
       readonly
       value="<?= h($question['number']) ?>">
</div>

<div class="field">
<label>回答形式</label>
<select name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][type]"
        onchange="updateQuestion(this)">
<option value="single"
 <?= $question['type']==='single'?'selected':'' ?>>
単一選択
</option>
<option value="multiple"
 <?= $question['type']==='multiple'?'selected':'' ?>>
複数選択
</option>
<option value="text"
 <?= $question['type']==='text'?'selected':'' ?>>
自由記述
</option>
</select>
</div>
</div>

<div class="field">
<label>質問文 *</label>
<textarea name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][text]"
          maxlength="<?= MAX_QUESTION ?>"
          required><?= h($question['text']) ?></textarea>
</div>

<div class="field">
<label>
<input type="checkbox"
       name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][required]"
       value="1"
 <?= !empty($question['required'])
     ? 'checked'
     : '' ?>>
必須
</label>
</div>

<?php if ($question['type'] !== 'text'): ?>

<div class="options">
<?php foreach (
    $question['options'] as $option
): ?>

<div class="option">
<div>
<input type="hidden"
       name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][options][<?= h($option['id']) ?>][id]"
       value="<?= h($option['id']) ?>">

<input type="text"
       name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][options][<?= h($option['id']) ?>][label]"
       maxlength="<?= MAX_OPTION ?>"
       value="<?= h($option['label']) ?>"
       placeholder="選択肢">
</div>

<?php if ($question['type'] === 'single'): ?>
<div>
<label class="small">
次の質問
<select name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][options][<?= h($option['id']) ?>][nextQuestionId]">
<option value="">指定なし</option>
<?php foreach (
    all_questions($survey) as $target
): ?>
<option value="<?= h($target['id']) ?>"
 <?= ($option['nextQuestionId'] ?? '') ===
     $target['id']
     ? 'selected'
     : '' ?>>
<?= h($target['number']) ?> <?= h($target['text']) ?>
</option>
<?php endforeach; ?>
</select>
</label>
</div>
<?php endif; ?>
</div>

<?php endforeach; ?>
</div>

<div class="actions">
<button type="button"
        class="btn secondary"
        onclick="addOption(this)">
選択肢を追加
</button>
</div>

<?php endif; ?>

<div class="actions">
<button type="button"
        class="btn danger"
        onclick="removeQuestion(this)">
質問を削除
</button>
</div>

</div>
<?php endforeach; ?>
</div>

<!-- 質問追加はグループ末尾のみ -->
<div class="actions">
<button type="button"
        class="btn success"
        onclick="addQuestion(this)">
質問を追加
</button>

<button type="button"
        class="btn danger"
        onclick="removeGroup(this)">
グループを削除
</button>
</div>

</div>
<?php endforeach; ?>
</div>

<!-- グループ追加は一覧末尾のみ -->
<div class="actions">
<button type="button"
        class="btn success"
        onclick="addGroup()">
グループを追加
</button>
</div>

<div class="actions">
<button type="submit">
保存
</button>

<a class="btn gray"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
キャンセル
</a>
</div>
</form>
</div>

<script>
function esc(v){
    return String(v)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;');
}

function addGroup(){
    const groups =
      document.querySelectorAll('[data-group]');
    const id =
      'group-' +
      Math.random().toString(16).slice(2);

    const html = `
<div class="group" data-group>
<div class="field">
<label>グループタイトル</label>
<input type="hidden"
 name="groups[${id}][id]"
 value="${id}">
<input type="text"
 name="groups[${id}][title]"
 value="新しいグループ">
</div>

<div class="questions"></div>

<div class="actions">
<button type="button"
 class="btn success"
 onclick="addQuestion(this)">
質問を追加
</button>
<button type="button"
 class="btn danger"
 onclick="removeGroup(this)">
グループを削除
</button>
</div>
</div>`;

    document
      .getElementById('groups')
      .insertAdjacentHTML(
        'beforeend',
        html
      );
}

function addQuestion(button){
    const group =
      button.closest('[data-group]');
    const questions =
      group.querySelector('.questions');

    const gid =
      group.querySelector(
        'input[name*="[id]"]'
      ).value;

    const qid =
      'question-' +
      Math.random().toString(16).slice(2);

    const html = `
<div class="question" data-question>
<input type="hidden"
 name="groups[${gid}][questions][${qid}][id]"
 value="${qid}">

<div class="grid">
<div class="field">
<label>質問番号</label>
<input type="text"
 readonly
 value="自動採番">
</div>

<div class="field">
<label>回答形式</label>
<select
 name="groups[${gid}][questions][${qid}][type]"
 onchange="updateQuestion(this)">
<option value="single">単一選択</option>
<option value="multiple">複数選択</option>
<option value="text">自由記述</option>
</select>
</div>
</div>

<div class="field">
<label>質問文 *</label>
<textarea
 name="groups[${gid}][questions][${qid}][text]"
 maxlength="<?= MAX_QUESTION ?>"
 required></textarea>
</div>

<div class="field">
<label>
<input type="checkbox"
 name="groups[${gid}][questions][${qid}][required]"
 value="1">
必須
</label>
</div>

<div class="options">
<div class="option">
<input type="text"
 name="groups[${gid}][questions][${qid}][options][${qid}-option][label]"
 value=""
 placeholder="選択肢">
</div>
</div>

<div class="actions">
<button type="button"
 class="btn secondary"
 onclick="addOption(this)">
選択肢を追加
</button>
<button type="button"
 class="btn danger"
 onclick="removeQuestion(this)">
質問を削除
</button>
</div>
</div>`;

    questions.insertAdjacentHTML(
      'beforeend',
      html
    );
}

function addOption(button){
    const question =
      button.closest('[data-question]');

    const select =
      question.querySelector('select');

    if (
      select &&
      select.value === 'text'
    ) {
        return;
    }

    const match =
      question.querySelector(
        'input[name*="[questions]"]'
      );

    if (!match) {
        return;
    }

    const base =
      match.name.match(
        /^groups\[([^\]]+)\]\[questions\]\[([^\]]+)\]/
      );

    if (!base) {
        return;
    }

    const gid = base[1];
    const qid = base[2];

    const oid =
      'option-' +
      Math.random().toString(16).slice(2);

    const container =
      question.querySelector('.options');

    if (!container) {
        return;
    }

    const html = `
<div class="option">
<input type="text"
 name="groups[${gid}][questions][${qid}][options][${oid}][label]"
 value=""
 placeholder="選択肢">
</div>`;

    container.insertAdjacentHTML(
      'beforeend',
      html
    );
}

function updateQuestion(select){
    const question =
      select.closest('[data-question]');

    if (!question) {
        return;
    }

    const options =
      question.querySelector('.options');

    if (!options) {
        return;
    }

    if (select.value === 'text') {
        options.innerHTML = '';
        return;
    }

    if (!options.children.length) {
        const btn =
          question.querySelector(
            'button[onclick*="addOption"]'
          );

        if (btn) {
            addOption(btn);
        }
    }
}

function removeQuestion(button){
    const question =
      button.closest('[data-question]');

    if (
      question &&
      confirm('この質問を削除しますか？')
    ) {
        question.remove();
    }
}

function removeGroup(button){
    const group =
      button.closest('[data-group]');

    if (
      group &&
      confirm('このグループを削除しますか？')
    ) {
        group.remove();
    }
}
</script>
<?php
    admin_footer();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if ($survey === null) {
        admin_header(
            'プレビュー',
            [
                'type' => 'error',
                'message' =>
                    'アンケートが見つかりません。'
            ]
        );

        echo '<div class="card">';
        echo '<p>対象アンケートが見つかりません。</p>';
        echo '</div>';

        admin_footer();
        return;
    }

    admin_header(
        'プレビュー',
        flash_get()
    );
?>
<div class="card">
<div style="display:flex;gap:10px;flex-wrap:wrap">
<h1 style="margin-right:auto">
<?= h($survey['title']) ?>
</h1>
<a class="btn"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$id
   ])) ?>">
編集へ戻る
</a>
</div>

<p>
<?= nl2br(h($survey['description'] ?? '')) ?>
</p>

<?php foreach (
    $survey['groups'] as $group
): ?>

<div class="group">
<h2>
<?= h($group['title']) ?>
</h2>

<?php foreach (
    $group['questions'] as $question
): ?>

<div class="preview-question">
<strong>
<?= h($question['number']) ?>
</strong>

<p>
<?= nl2br(h($question['text'])) ?>
<?= !empty($question['required'])
    ? ' *'
    : '' ?>
</p>

<?php if (
    $question['type'] === 'single'
): ?>

<?php foreach (
    $question['options'] as $option
): ?>
<label style="display:block;margin:8px 0">
<input type="radio"
       disabled>
<?= h($option['label']) ?>

<?php if (
    !empty($option['nextQuestionId'])
): ?>
<span class="small">
→ <?= h(
    (string)$option['nextQuestionId']
) ?>
</span>
<?php endif; ?>
</label>
<?php endforeach; ?>

<?php elseif (
    $question['type'] === 'multiple'
): ?>

<?php foreach (
    $question['options'] as $option
): ?>
<label style="display:block;margin:8px 0">
<input type="checkbox"
       disabled>
<?= h($option['label']) ?>
</label>
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
<?php
    admin_footer();
}

/* =========================================================
 * 顧客一覧
 * ========================================================= */

function render_customers(
    array $data
): void {
    admin_header(
        '顧客一覧',
        flash_get()
    );
?>
<div class="card">
<div style="display:flex;align-items:center;gap:10px">
<h1 style="margin-right:auto">
顧客一覧
</h1>

<a class="btn"
   href="<?= h(app_url([
       'screen'=>'kintone'
   ])) ?>">
kintone設定へ戻る
</a>
</div>

<p class="small">
kintoneから同期した顧客情報です。
</p>

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

<?php if (
    empty($data['customers'])
): ?>
<tr>
<td colspan="7">
同期済み顧客はありません。
</td>
</tr>
<?php endif; ?>

<?php foreach (
    $data['customers'] as $customer
): ?>
<tr>
<td><?= h($customer['organization'] ?? '') ?></td>
<td><?= h($customer['name'] ?? '') ?></td>
<td><?= h($customer['email'] ?? '') ?></td>
<td><?= h($customer['department'] ?? '') ?></td>
<td><?= h($customer['phone'] ?? '') ?></td>
<td><?= h($customer['address'] ?? '') ?></td>
<td><?= h($customer['syncedAt'] ?? '') ?></td>
</tr>
<?php endforeach; ?>

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
    $k =
        runtime_settings(
            $settings
        )['kintone'];

    $flash =
        flash_get();

    admin_header(
        'kintone連携設定',
        $flash
    );
?>
<div class="card">
<h1>kintone連携設定</h1>

<p class="small">
kintone顧客管理アプリから顧客情報を取得します。
</p>

<form method="post"
      onsubmit="return busyForm(this)">
<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid">

<div class="field">
<label>サブドメイン *</label>
<input type="text"
       name="subdomain"
       placeholder="xxxx または xxxx.cybozu.com"
       required
       value="<?= h($k['subdomain']) ?>">
</div>

<div class="field">
<label>顧客管理アプリID *</label>
<input type="number"
       name="app_id"
       min="1"
       required
       value="<?= h($k['app_id']) ?>">
</div>

<div class="field">
<label>ログイン名 *</label>
<input type="text"
       name="username"
       required
       value="<?= h($k['username']) ?>">
</div>

<div class="field">
<label>パスワード *</label>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>

<div class="field">
<label>Proxy</label>
<input type="text"
       name="proxy"
       placeholder="host:port"
       value="<?= h($k['proxy']) ?>">
</div>

<div class="field">
<label>SSL証明書検証</label>
<label>
<input type="checkbox"
       name="verify_ssl"
       value="1"
 <?= !empty($k['verify_ssl'])
     ? 'checked'
     : '' ?>>
有効
</label>
</div>

</div>

<div class="actions">
<button type="submit">
設定を保存
</button>
</div>
</form>
</div>

<div class="card">
<h2>接続確認</h2>

<?php if (!empty($k['last_test'])): ?>
<p class="small">
最終成功日時：
<?= h($k['last_test']) ?>
</p>
<?php endif; ?>

<?php if (!empty($k['last_error'])): ?>
<div class="flash error">
<?= nl2br(h($k['last_error'])) ?>
</div>
<?php endif; ?>

<div class="actions">

<form method="post"
      onsubmit="return busyForm(this)">
<input type="hidden"
       name="action"
       value="test_kintone">
<button type="submit">
接続テスト
</button>
</form>

<form method="post"
      onsubmit="return busyForm(this)">
<input type="hidden"
       name="action"
       value="load_kintone_fields">
<button type="submit"
        class="btn secondary">
項目一覧を再取得
</button>
</form>

<form method="post"
      onsubmit="return busyForm(this)">
<input type="hidden"
       name="action"
       value="sync_kintone">
<button type="submit"
        class="btn success">
顧客情報を同期
</button>
</form>

<a class="btn gray"
   href="<?= h(app_url([
       'screen'=>'customers'
   ])) ?>">
顧客一覧
</a>

</div>
</div>

<div class="card">
<h2>顧客項目マッピング</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="grid">

<div class="field">
<label>組織名</label>
<select name="mapping_organization">
<option value="">未設定</option>
<?php foreach (
    $k['fields'] as $field
): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($k['mapping']['organization'] ?? '') ===
     $field['code']
     ? 'selected'
     : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>
<?php endforeach; ?>
</select>
</div>

<div class="field">
<label>氏名</label>
<select name="mapping_name">
<option value="">未設定</option>
<?php foreach (
    $k['fields'] as $field
): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($k['mapping']['name'] ?? '') ===
     $field['code']
     ? 'selected'
     : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>
<?php endforeach; ?>
</select>
</div>

<div class="field">
<label>メールアドレス</label>
<select name="mapping_email">
<option value="">未設定</option>
<?php foreach (
    $k['fields'] as $field
): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($k['mapping']['email'] ?? '') ===
     $field['code']
     ? 'selected'
     : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>
<?php endforeach; ?>
</select>
</div>

<div class="field">
<label>部署名</label>
<select name="mapping_department">
<option value="">未設定</option>
<?php foreach (
    $k['fields'] as $field
): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($k['mapping']['department'] ?? '') ===
     $field['code']
     ? 'selected'
     : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>
<?php endforeach; ?>
</select>
</div>

<div class="field">
<label>電話番号</label>
<select name="mapping_phone">
<option value="">未設定</option>
<?php foreach (
    $k['fields'] as $field
): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($k['mapping']['phone'] ?? '') ===
     $field['code']
     ? 'selected'
     : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>
<?php endforeach; ?>
</select>
</div>

</div>

<div class="field">
<label>住所（複数選択可）</label>

<?php foreach (
    $k['fields'] as $field
): ?>

<label style="display:block;margin:6px 0">
<input type="checkbox"
       name="mapping_address[]"
       value="<?= h($field['code']) ?>"
 <?= in_array(
     $field['code'],
     $k['mapping']['address'] ?? [],
     true
 )
     ? 'checked'
     : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</label>

<?php endforeach; ?>

</div>

<div class="actions">
<button type="submit">
マッピングを保存
</button>
</div>
</form>
</div>

<script>
function busyForm(form){
    form.classList.add('loading');

    const buttons =
      form.querySelectorAll('button');

    buttons.forEach(function(button){
        button.disabled = true;
        button.textContent = '処理中...';
    });

    return true;
}
</script>
<?php
    admin_footer();
}

/* =========================================================
 * メール設定
 * ========================================================= */

function render_mail(
    array $settings
): void {
    $m =
        runtime_settings(
            $settings
        )['mail'];

    admin_header(
        'メールサーバ設定',
        flash_get()
    );
?>
<div class="card">
<h1>メールサーバ設定</h1>

<form method="post"
      onsubmit="return busyForm(this)">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid">

<div class="field">
<label>SMTPサーバ *</label>
<input type="text"
       name="host"
       required
       value="<?= h($m['host']) ?>">
</div>

<div class="field">
<label>SMTPポート *</label>
<input type="number"
       name="port"
       min="1"
       max="65535"
       required
       value="<?= h($m['port']) ?>">
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
<option value="tls"
 <?= $m['encryption']==='tls'
     ? 'selected'
     : '' ?>>
TLS
</option>
<option value="ssl"
 <?= $m['encryption']==='ssl'
     ? 'selected'
     : '' ?>>
SSL
</option>
<option value="none"
 <?= $m['encryption']==='none'
     ? 'selected'
     : '' ?>>
なし
</option>
</select>
</div>

<div class="field">
<label>
<input type="checkbox"
       name="auth"
       value="1"
 <?= !empty($m['auth'])
     ? 'checked'
     : '' ?>>
SMTP認証を使用
</label>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input type="text"
       name="username"
       value="<?= h($m['username']) ?>">
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>

<div class="field">
<label>送信元メールアドレス *</label>
<input type="email"
       name="from_email"
       required
       value="<?= h($m['from_email']) ?>">
</div>

<div class="field">
<label>送信元名</label>
<input type="text"
       name="from_name"
       value="<?= h($m['from_name']) ?>">
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input type="email"
       name="reply_to"
       value="<?= h($m['reply_to']) ?>">
</div>

</div>

<div class="actions">
<button type="submit">
設定を保存
</button>
</div>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>

<?php if (!empty($m['last_test'])): ?>
<p class="small">
最終成功日時：
<?= h($m['last_test']) ?>
</p>
<?php endif; ?>

<?php if (!empty($m['last_error'])): ?>
<div class="flash error">
<?= nl2br(h($m['last_error'])) ?>
</div>
<?php endif; ?>

<form method="post"
      onsubmit="return busyForm(this)">
<input type="hidden"
       name="action"
       value="test_mail">

<div class="actions">
<button type="submit">
SMTP接続テスト
</button>
</div>
</form>

<hr>

<h2>テストメール</h2>

<form method="post"
      onsubmit="return busyForm(this)">
<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="field">
<label>送信先</label>
<input type="email"
       name="test_to"
       required>
</div>

<button type="submit"
        class="btn success">
テストメール送信
</button>
</form>
</div>

<script>
function busyForm(form){
    form.classList.add('loading');

    form.querySelectorAll('button')
      .forEach(function(button){
        button.disabled = true;
        button.textContent = '処理中...';
      });

    return true;
}
</script>
<?php
    admin_footer();
}

/* =========================================================
 * 送信
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

    if ($survey === null) {
        admin_header(
            '顧客選択・メール送信',
            [
                'type'=>'error',
                'message'=>
                    '対象アンケートが見つかりません。'
            ]
        );

        echo '<div class="card">対象アンケートが見つかりません。</div>';

        admin_footer();
        return;
    }

    $history =
        array_filter(
            $data['send_history'] ?? [],
            static fn(
                array $row
            ): bool =>
                (string)(
                    $row['surveyId'] ?? ''
                ) === $id
        );

    admin_header(
        '顧客選択・メール送信',
        flash_get()
    );
?>
<div class="card">
<div style="display:flex;align-items:center;gap:10px">
<h1 style="margin-right:auto">
顧客選択・メール送信
</h1>

<a class="btn gray"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>
</div>

<p>
対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>

<form method="post"
      onsubmit="return busyForm(this)">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<h2>顧客選択</h2>

<div class="actions">
<button type="button"
        class="btn secondary"
        onclick="toggleCustomers(true)">
全選択
</button>

<button type="button"
        class="btn gray"
        onclick="toggleCustomers(false)">
全解除
</button>
</div>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>選択</th>
<th>組織</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
</tr>
</thead>
<tbody>

<?php foreach (
    $data['customers'] ?? [] as $customer
): ?>
<tr>
<td>
<input type="checkbox"
       class="customer-check"
       name="customers[]"
       value="<?= h($customer['id']) ?>">
</td>
<td><?= h($customer['organization'] ?? '') ?></td>
<td><?= h($customer['name'] ?? '') ?></td>
<td><?= h($customer['email'] ?? '') ?></td>
<td><?= h($customer['department'] ?? '') ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<h2>メール作成</h2>

<div class="field">
<label>件名 *</label>
<input type="text"
       name="subject"
       required
       value="<?= h(
           '【アンケート】' .
           $survey['title']
       ) ?>">
</div>

<div class="field">
<label>本文</label>
<textarea name="body"><?= h(
    '{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。'
) ?></textarea>
</div>

<div class="actions">
<button type="submit"
        class="btn success">
選択した顧客へ一括送信
</button>
</div>
</form>
</div>

<div class="card">
<h2>送信履歴</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>メール</th>
<th>件名</th>
<th>結果</th>
</tr>
</thead>
<tbody>

<?php if ($history === []): ?>
<tr>
<td colspan="4">
送信履歴はありません。
</td>
</tr>
<?php endif; ?>

<?php foreach (
    $history as $row
): ?>
<tr>
<td><?= h($row['createdAt'] ?? '') ?></td>
<td><?= h($row['email'] ?? '') ?></td>
<td><?= h($row['subject'] ?? '') ?></td>
<td>
<?= ($row['status'] ?? '') === 'sent'
    ? '送信成功'
    : '送信失敗'
?>
<?php if (!empty($row['error'])): ?>
<div class="small">
<?= h($row['error']) ?>
</div>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>
</div>

<script>
function toggleCustomers(value){
    document
      .querySelectorAll('.customer-check')
      .forEach(function(el){
        el.checked = value;
      });
}

function busyForm(form){
    form.classList.add('loading');

    form.querySelectorAll('button[type=submit]')
      .forEach(function(button){
        button.disabled = true;
        button.textContent = '送信処理中...';
      });

    return true;
}
</script>
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

    if ($survey === null) {
        admin_header(
            '回答集計・分析',
            [
                'type'=>'error',
                'message'=>
                    '対象アンケートが見つかりません。'
            ]
        );

        echo '<div class="card">対象アンケートが見つかりません。</div>';

        admin_footer();
        return;
    }

    $answers =
        array_values(
            array_filter(
                $data['answers'] ?? [],
                static fn(
                    array $answer
                ): bool =>
                    (string)(
                        $answer['surveyId'] ?? ''
                    ) === $id
            )
        );

    $sentCustomers = [];

    foreach (
        $data['send_history'] ?? [] as $history
    ) {
        if (
            (string)(
                $history['surveyId'] ?? ''
            ) === $id &&
            ($history['status'] ?? '') === 'sent'
        ) {
            $sentCustomers[
                (string)(
                    $history['customerId'] ?? ''
                )
            ] = true;
        }
    }

    $sentCount =
        count($sentCustomers);

    $answerCount =
        count($answers);

    $rate =
        $sentCount > 0
            ? round(
                $answerCount /
                $sentCount *
                100,
                1
            )
            : 0;

    admin_header(
        '回答集計・分析',
        flash_get()
    );
?>
<div class="card">
<div style="display:flex;align-items:center;gap:10px">
<h1 style="margin-right:auto">
回答集計・分析
</h1>

<a class="btn gray"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>
</div>

<p>
対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>

<div class="stat-grid">

<div class="stat">
送信対象者数
<strong><?= h($sentCount) ?></strong>
</div>

<div class="stat">
回答数
<strong><?= h($answerCount) ?></strong>
</div>

<div class="stat">
未回答数
<strong><?= h(
    max(
        0,
        $sentCount -
        $answerCount
    )
) ?></strong>
</div>

<div class="stat">
回答率
<strong><?= h($rate) ?>%</strong>
</div>

</div>
</div>

<div class="card">
<h2>設問別集計</h2>

<?php if ($answerCount === 0): ?>

<p>
現在、回答データはありません
</p>

<?php else: ?>

<?php foreach (
    all_questions($survey) as $question
): ?>

<div class="question">
<h3>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</h3>

<?php
$counts = [];

foreach (
    $question['options'] ?? [] as $option
) {
    $counts[
        (string)$option['label']
    ] = 0;
}

foreach (
    $answers as $answer
) {
    $value =
        $answer['answers'][
            $question['id']
        ] ?? '';

    if (is_array($value)) {
        foreach ($value as $v) {
            $v = (string)$v;

            if (isset($counts[$v])) {
                $counts[$v]++;
            }
        }
    } else {
        $v = (string)$value;

        if (isset($counts[$v])) {
            $counts[$v]++;
        }
    }
}
?>

<?php if (
    $question['type'] === 'text'
): ?>

<p>
自由記述回答：
<?= h(
    count(
        array_filter(
            $answers,
            static function (
                array $a
            ) use ($question): bool {
                $v =
                    $a['answers'][
                        $question['id']
                    ] ?? '';

                return
                    trim(
                        (string)$v
                    ) !== '';
            }
        )
    )
) ?>件
</p>

<?php else: ?>

<table>
<thead>
<tr>
<th>選択肢</th>
<th>回答数</th>
</tr>
</thead>
<tbody>
<?php foreach (
    $counts as $label => $count
): ?>
<tr>
<td><?= h($label) ?></td>
<td><?= h($count) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>
</div>

<div class="card">
<h2>個別回答</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>回答日時</th>
<?php foreach (
    all_questions($survey) as $question
): ?>
<th><?= h($question['number']) ?></th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>

<?php foreach (
    $answers as $answer
): ?>
<tr>
<td><?= h($answer['createdAt'] ?? '') ?></td>

<?php foreach (
    all_questions($survey) as $question
): ?>
<td>
<?php
$v =
    $answer['answers'][
        $question['id']
    ] ?? '';

if (is_array($v)) {
    echo h(
        implode(
            ', ',
            array_map(
                'strval',
                $v
            )
        )
    );
} else {
    echo nl2br(
        h((string)$v)
    );
}
?>
</td>
<?php endforeach; ?>

</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>
</div>
<?php
    admin_footer();
}

/* =========================================================
 * 回答者画面
 * ========================================================= */

function respondent_header(
    string $title
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<style>
*{box-sizing:border-box}
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 Meiryo,sans-serif;
}
main{
 max-width:760px;
 margin:auto;
 padding:20px 14px 50px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:20px;
 margin-bottom:16px;
}
.question{
 padding:18px 0;
 border-bottom:1px solid #dbe2ea;
}
label{
 display:block;
 margin:12px 0;
 font-size:16px;
}
input[type=text],
textarea{
 width:100%;
 padding:13px;
 border:1px solid #cbd5e1;
 border-radius:8px;
 font:inherit;
}
textarea{
 min-height:150px;
}
button{
 border:0;
 border-radius:9px;
 padding:13px 18px;
 background:#2563eb;
 color:#fff;
 font:inherit;
 cursor:pointer;
}
.actions{
 display:flex;
 gap:10px;
 flex-wrap:wrap;
 margin-top:18px;
}
.error{
 background:#fee2e2;
 color:#991b1b;
 padding:13px;
 border-radius:8px;
 margin-bottom:16px;
}
</style>
</head>
<body>
<main>
<?php
}

function respondent_footer(): void
{
?>
</main>
</body>
</html>
<?php
}

function render_answer(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if ($survey === null) {
        respondent_header(
            'アンケート'
        );

        echo '<div class="card">';
        echo '<div class="error">';
        echo 'アンケートが見つかりません。';
        echo '</div>';
        echo '</div>';

        respondent_footer();
        return;
    }

    if (
        ($survey['status'] ?? '') !==
        'published'
    ) {
        respondent_header(
            $survey['title']
        );

        echo '<div class="card">';
        echo '<h1>' .
            h($survey['title']) .
            '</h1>';

        echo '<div class="error">';
        echo 'このアンケートは現在回答できません。';
        echo '</div>';
        echo '</div>';

        respondent_footer();
        return;
    }

    $answers =
        $_SESSION[
            'answer_' . $id
        ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    respondent_header(
        $survey['title']
    );
?>
<div class="card">
<h1><?= h($survey['title']) ?></h1>

<?php if (
    !empty($survey['description'])
): ?>
<p>
<?= nl2br(
    h($survey['description'])
) ?>
</p>
<?php endif; ?>

<form method="post">
<input type="hidden"
       name="action"
       value="answer_next">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<?php foreach (
    $survey['groups'] as $group
): ?>

<h2>
<?= h($group['title']) ?>
</h2>

<?php foreach (
    $group['questions'] as $question
): ?>

<div class="question">
<h3>
<?= h($question['number']) ?>
</h3>

<p>
<?= nl2br(
    h($question['text'])
) ?>

<?php if (
    !empty($question['required'])
): ?>
<strong> *</strong>
<?php endif; ?>

</p>

<?php
$qid =
    (string)$question['id'];

$current =
    $answers[$qid] ?? '';

if (
    $question['type'] === 'single'
):
?>

<?php foreach (
    $question['options'] as $option
): ?>
<label>
<input type="radio"
       name="answers[<?= h($qid) ?>]"
       value="<?= h($option['label']) ?>"
 <?= (string)$current ===
     (string)$option['label']
     ? 'checked'
     : '' ?>>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php elseif (
    $question['type'] === 'multiple'
): ?>

<?php
$currentArray =
    is_array($current)
        ? $current
        : [];
?>

<?php foreach (
    $question['options'] as $option
): ?>
<label>
<input type="checkbox"
       name="answers[<?= h($qid) ?>][]"
       value="<?= h($option['label']) ?>"
 <?= in_array(
     $option['label'],
     $currentArray,
     true
 )
     ? 'checked'
     : '' ?>>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php else: ?>

<textarea
 name="answers[<?= h($qid) ?>]"
><?= h((string)$current) ?></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

<div class="actions">
<button type="submit">
次へ
</button>
</div>
</form>
</div>
<?php
    respondent_footer();
}

/* =========================================================
 * 回答確認
 * ========================================================= */

function render_confirm(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if ($survey === null) {
        respondent_header(
            '回答確認'
        );

        echo '<div class="card">';
        echo 'アンケートが見つかりません。';
        echo '</div>';

        respondent_footer();
        return;
    }

    $answers =
        $_SESSION[
            'answer_' . $id
        ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    respondent_header(
        '回答確認 - ' .
        $survey['title']
    );
?>
<div class="card">
<h1>回答確認</h1>

<p>
送信前に回答内容をご確認ください。
</p>

<?php foreach (
    all_questions($survey) as $question
): ?>

<div class="question">
<h3>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</h3>

<?php
$value =
    $answers[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    echo '<p>' .
        nl2br(
            h(
                implode(
                    '、',
                    array_map(
                        'strval',
                        $value
                    )
                )
            )
        ) .
        '</p>';
} else {
    echo '<p>' .
        nl2br(
            h((string)$value)
        ) .
        '</p>';
}
?>

</div>

<?php endforeach; ?>

<div class="actions">

<form method="post">
<input type="hidden"
       name="action"
       value="answer_back">
<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<?php foreach (
    $answers as $qid => $value
): ?>

<?php if (is_array($value)): ?>

<?php foreach ($value as $v): ?>
<input type="hidden"
       name="answers[<?= h($qid) ?>][]"
       value="<?= h($v) ?>">
<?php endforeach; ?>

<?php else: ?>

<input type="hidden"
       name="answers[<?= h($qid) ?>]"
       value="<?= h((string)$value) ?>">

<?php endif; ?>

<?php endforeach; ?>

<button type="submit"
        style="background:#64748b">
戻る
</button>
</form>

<form method="post">
<input type="hidden"
       name="action"
       value="answer_submit">
<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">
<button type="submit">
回答を送信
</button>
</form>

</div>
</div>
<?php
    respondent_footer();
}

/* =========================================================
 * 回答完了
 * ========================================================= */

function render_complete(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    respondent_header(
        '回答完了'
    );
?>
<div class="card">
<h1>回答ありがとうございました。</h1>

<p>
<?= h(
    $survey['title'] ?? 'アンケート'
) ?>
への回答を受け付けました。
</p>

<p>
回答は正常に保存されました。
</p>
</div>
<?php
    respondent_footer();
}

/* =========================================================
 * GET画面制御
 * ========================================================= */

function render_screen(
    array $data,
    array $settings
): void {
    $screen =
        get_string(
            'screen',
            'list'
        );

    /*
     * 回答者系画面は管理者ヘッダーを使用しない。
     */
    if ($screen === 'answer') {
        render_answer(
            $data,
            get_string('id')
        );
        return;
    }

    if ($screen === 'confirm') {
        render_confirm(
            $data,
            get_string('id')
        );
        return;
    }

    if ($screen === 'complete') {
        render_complete(
            $data,
            get_string('id')
        );
        return;
    }

    switch ($screen) {
        case 'edit':
            render_edit(
                $data,
                get_string('id') !== ''
                    ? get_string('id')
                    : null
            );
            break;

        case 'preview':
            render_preview(
                $data,
                get_string('id')
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

        case 'send':
            $id = get_string('id');

            if ($id === '') {
                admin_header(
                    '顧客選択・メール送信',
                    [
                        'type'=>'error',
                        'message'=>
                            '対象アンケートを指定してください。'
                    ]
                );

                echo '<div class="card">';
                echo '送信対象のアンケートが指定されていません。';
                echo '</div>';

                admin_footer();
                break;
            }

            render_send(
                $data,
                $id
            );
            break;

        case 'analytics':
            $id = get_string('id');

            if ($id === '') {
                admin_header(
                    '回答集計・分析',
                    [
                        'type'=>'error',
                        'message'=>
                            '対象アンケートを指定してください。'
                    ]
                );

                echo '<div class="card">';
                echo '集計対象のアンケートが指定されていません。';
                echo '</div>';

                admin_footer();
                break;
            }

            render_analytics(
                $data,
                $id
            );
            break;

        case 'list':
        default:
            render_list(
                $data
            );
            break;
    }
}

/* =========================================================
 * 起動
 * ========================================================= */

try {
    start_session();

    ensure_data_dir();

    $data =
        load_data();

    $settings =
        load_settings();

    refresh_status(
        $data
    );

    /*
     * POST:
     * 入力
     * ↓
     * 検証
     * ↓
     * 業務処理
     * ↓
     * 外部通信
     * ↓
     * 保存
     * ↓
     * 結果確定
     *
     * 画面遷移はこの関数の戻り値を処理した後に行う。
     */
    if (
        $_SERVER['REQUEST_METHOD'] ===
        'POST'
    ) {
        $result =
            handle_post(
                $data,
                $settings
            );

        if (is_array($result)) {
            /*
             * POST処理の成否が確定してから
             * 303を返す。
             */
            redirect_screen(
                (string)(
                    $result['screen'] ??
                    'list'
                ),
                array_filter(
                    [
                        'id' =>
                            $result['id']
                            ?? null,
                    ],
                    static fn(
                        mixed $v
                    ): bool =>
                        $v !== null &&
                        $v !== ''
                )
            );
        }
    }

    render_screen(
        $data,
        $settings
    );
} catch (Throwable $e) {
    /*
     * システムエラー。
     *
     * 内部スタックトレースや認証情報は
     * 画面へ出さない。
     */
    http_response_code(500);

    try {
        start_session();

        admin_header(
            'システムエラー',
            [
                'type' => 'error',
                'message' =>
                    '処理中にエラーが発生しました。' .
                    "\n" .
                    'データ保存領域、PHPのファイル権限、' .
                    'セッション保存環境を確認してください。',
            ]
        );

        echo '<div class="card">';
        echo '<h1>システムエラー</h1>';
        echo '<p>';
        echo '処理中にエラーが発生しました。';
        echo '</p>';
        echo '</div>';

        admin_footer();
    } catch (Throwable) {
        echo
            '<!doctype html>' .
            '<html lang="ja"><meta charset="UTF-8">' .
            '<title>システムエラー</title>' .
            '<body>' .
            '<h1>システムエラー</h1>' .
            '<p>処理中にエラーが発生しました。</p>' .
            '</body></html>';
    }
}

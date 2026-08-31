<?php
declare(strict_types=1);

/*
 * アンケート管理アプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし
 * PHP cURLなし
 * 単一エントリーポイント
 *
 * 永続データ:
 *   _data/data.json
 *   _data/settings.json
 *   _data/.secret
 *
 * 管理者認証はPOCでは行わない。
 * 回答者画面と管理者画面はURL/画面構成上分離する。
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SECRET_FILE = DATA_DIR . DIRECTORY_SEPARATOR . '.secret';

const KINTONE_TIMEOUT = 30;
const SMTP_TIMEOUT = 30;

const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;

/* =========================================================
 * 共通
 * ========================================================= */

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function post_value(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function post_string(string $key): string
{
    $v = post_value($key, '');
    return is_scalar($v) ? trim((string)$v) : '';
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
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function app_url(array $params = []): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
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
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host .
        app_url([
            'screen' => 'answer',
            'id' => $surveyId,
        ]);
}

function safe_error(Throwable $e): string
{
    $m = trim($e->getMessage());

    $m = preg_replace(
        '/X-Cybozu-Authorization:\s*[^\s]+/i',
        'X-Cybozu-Authorization: [REDACTED]',
        $m
    ) ?? $m;

    $m = preg_replace(
        '/password\s*[=:]\s*[^\s]+/i',
        'password=[REDACTED]',
        $m
    ) ?? $m;

    return mb_substr($m, 0, 800);
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

    if ($dir === '.' || $dir === '/' || $dir === '\\') {
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
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
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
        throw new RuntimeException('セッションを開始できません。');
    }
}

/* =========================================================
 * JSON
 * ========================================================= */

function load_json(string $file, array $fallback): array
{
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

    if ($raw === false || trim($raw) === '') {
        return $fallback;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : $fallback;
}

function save_json(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('JSONデータを生成できません。');
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
        throw new RuntimeException(
            'データ保存領域へ一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データファイルをロックできません。');
        }

        $length = strlen($json);
        $written = 0;

        while ($written < $length) {
            $n = fwrite($fp, substr($json, $written));

            if ($n === false) {
                throw new RuntimeException('データを書き込めません。');
            }

            $written += $n;
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
 * ローカル秘密鍵
 *
 * アプリ固有のファイルとして _data/.secret を生成する。
 * 外部環境変数には依存しない。
 * ========================================================= */

function local_secret(): string
{
    if (is_file(SECRET_FILE)) {
        $raw = @file_get_contents(SECRET_FILE);

        if (is_string($raw) && strlen($raw) >= 32) {
            return trim($raw);
        }
    }

    $secret = base64_encode(random_bytes(48));

    if (@file_put_contents(SECRET_FILE, $secret, LOCK_EX) === false) {
        throw new RuntimeException(
            '機密情報保存用ファイルを作成できません。'
        );
    }

    return $secret;
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $key = hash('sha256', local_secret(), true);
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
        throw new RuntimeException('機密情報の暗号化に失敗しました。');
    }

    return base64_encode(json_encode([
        'v' => 1,
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'data' => base64_encode($cipher),
    ], JSON_UNESCAPED_SLASHES));
}

function decrypt_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $raw = base64_decode($value, true);

    if ($raw === false) {
        throw new RuntimeException('保存された機密情報の形式が不正です。');
    }

    $payload = json_decode($raw, true);

    if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) {
        throw new RuntimeException('保存された機密情報の形式が不正です。');
    }

    $iv = base64_decode((string)($payload['iv'] ?? ''), true);
    $tag = base64_decode((string)($payload['tag'] ?? ''), true);
    $cipher = base64_decode((string)($payload['data'] ?? ''), true);

    if ($iv === false || $tag === false || $cipher === false) {
        throw new RuntimeException('保存された機密情報を復号できません。');
    }

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        hash('sha256', local_secret(), true),
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

/* =========================================================
 * デフォルトデータ
 * ========================================================= */

function default_data(): array
{
    $t = now();

    return [
        'surveys' => [[
            'id' => 'survey-001',
            'title' => '顧客満足度アンケート',
            'description' => 'サービスについてのご意見をお聞かせください。',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
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
                    'text' => 'サービスの満足度を教えてください。',
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
                    'text' => 'ご意見・ご要望があれば入力してください。',
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

function normalize_data(array $data): array
{
    foreach (['surveys', 'answers', 'customers', 'send_history'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    $normalizedSurveys = [];

    foreach ($data['surveys'] as $survey) {
        if (!is_array($survey)) {
            continue;
        }

        $survey['id'] = trim((string)($survey['id'] ?? uid('survey')));
        $survey['title'] = mb_substr(
            trim((string)($survey['title'] ?? '')),
            0,
            MAX_TITLE
        );
        $survey['description'] = mb_substr(
            trim((string)($survey['description'] ?? '')),
            0,
            MAX_DESCRIPTION
        );

        $survey['status'] = in_array(
            (string)($survey['status'] ?? 'draft'),
            ['draft', 'published', 'stopped', 'ended'],
            true
        ) ? (string)$survey['status'] : 'draft';

        $survey['numbering'] =
            ($survey['numbering'] ?? 'global') === 'group'
                ? 'group'
                : 'global';

        $survey['groups'] = normalize_groups($survey['groups'] ?? []);

        $survey['createdAt'] =
            (string)($survey['createdAt'] ?? now());

        $survey['updatedAt'] =
            (string)($survey['updatedAt'] ?? $survey['createdAt']);

        $normalizedSurveys[] = $survey;
    }

    $data['surveys'] = $normalizedSurveys;

    $normalizedCustomers = [];

    foreach ($data['customers'] as $customer) {
        if (!is_array($customer)) {
            continue;
        }

        $normalizedCustomers[] = [
            'id' => (string)($customer['id'] ?? uid('customer')),
            'organization' => (string)($customer['organization'] ?? ''),
            'name' => (string)($customer['name'] ?? ''),
            'email' => (string)($customer['email'] ?? ''),
            'department' => (string)($customer['department'] ?? ''),
            'phone' => (string)($customer['phone'] ?? ''),
            'address' => (string)($customer['address'] ?? ''),
            'syncedAt' => (string)($customer['syncedAt'] ?? ''),
        ];
    }

    $data['customers'] = $normalizedCustomers;

    return $data;
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

        $gid = trim((string)($group['id'] ?? ''));

        if ($gid === '') {
            $gid = uid('group');
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $question) {
            if (!is_array($question)) {
                continue;
            }

            $qid = trim((string)($question['id'] ?? ''));

            if ($qid === '') {
                $qid = uid('question');
            }

            $type = (string)($question['type'] ?? 'text');

            if (!in_array($type, ['single', 'multiple', 'text'], true)) {
                $type = 'text';
            }

            $options = [];

            if ($type !== 'text') {
                foreach (($question['options'] ?? []) as $option) {
                    if (!is_array($option)) {
                        continue;
                    }

                    $oid = trim((string)($option['id'] ?? ''));

                    if ($oid === '') {
                        $oid = uid('option');
                    }

                    $options[] = [
                        'id' => $oid,
                        'label' => mb_substr(
                            trim((string)($option['label'] ?? '')),
                            0,
                            MAX_OPTION
                        ),
                        'nextQuestionId' =>
                            $type === 'single'
                                ? trim((string)($option['nextQuestionId'] ?? ''))
                                : '',
                    ];
                }
            }

            $questions[] = [
                'id' => $qid,
                'number' => '',
                'text' => mb_substr(
                    trim((string)($question['text'] ?? '')),
                    0,
                    MAX_QUESTION
                ),
                'type' => $type,
                'required' => !empty($question['required']),
                'options' => $options,
            ];
        }

        $groups[] = [
            'id' => $gid,
            'title' => mb_substr(
                trim((string)($group['title'] ?? '')),
                0,
                MAX_TITLE
            ),
            'questions' => $questions,
        ];
    }

    return $groups;
}

function load_data(): array
{
    return normalize_data(
        load_json(DATA_FILE, default_data())
    );
}

function save_data(array $data): void
{
    save_json(DATA_FILE, normalize_data($data));
}

function load_settings(): array
{
    $d = default_settings();
    $s = load_json(SET_FILE, $d);

    foreach (['kintone', 'mail'] as $service) {
        $s[$service] = array_replace_recursive(
            $d[$service],
            is_array($s[$service] ?? null)
                ? $s[$service]
                : []
        );
    }

    foreach (['kintone', 'mail'] as $service) {
        $password = (string)($s[$service]['password'] ?? '');

        if ($password !== '') {
            try {
                $s[$service]['password'] = decrypt_secret($password);
            } catch (Throwable) {
                /*
                 * 旧データ・破損データの場合は空欄として扱い、
                 * 一覧等の非外部サービス画面を壊さない。
                 */
                $s[$service]['password'] = '';
                $s[$service]['password_error'] = true;
            }
        }
    }

    return $s;
}

function save_settings(array $settings): void
{
    $copy = $settings;

    foreach (['kintone', 'mail'] as $service) {
        if (!isset($copy[$service]) || !is_array($copy[$service])) {
            continue;
        }

        $password = (string)($copy[$service]['password'] ?? '');

        if ($password !== '') {
            $copy[$service]['password'] = encrypt_secret($password);
        }

        unset($copy[$service]['password_error']);
    }

    save_json(SET_FILE, $copy);
}

/* =========================================================
 * ステータス・アンケート
 * ========================================================= */

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $i => $survey) {
        if (
            is_array($survey) &&
            (string)($survey['id'] ?? '') === $id
        ) {
            return $i;
        }
    }

    return -1;
}

function survey_get(array $surveys, string $id): ?array
{
    $i = survey_index($surveys, $id);

    return $i >= 0 && is_array($surveys[$i])
        ? $surveys[$i]
        : null;
}

function all_questions(array $survey): array
{
    $result = [];

    foreach (($survey['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }

        foreach (($group['questions'] ?? []) as $question) {
            if (is_array($question)) {
                $result[] = $question;
            }
        }
    }

    return $result;
}

function recalc_numbers(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] =
                    'Q' . $groupNo . '-' . $questionNo;
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
}

function refresh_status(array &$data): void
{
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (!is_array($survey)) {
            continue;
        }

        if (
            ($survey['status'] ?? '') === 'published'
            && !empty($survey['endAt'])
        ) {
            $end = strtotime((string)$survey['endAt']);

            if ($end !== false && $end < time()) {
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

function visible_questions(array $survey, array $answers): array
{
    $questions = all_questions($survey);

    if ($questions === []) {
        return [];
    }

    $byId = [];

    foreach ($questions as $question) {
        $byId[(string)$question['id']] = $question;
    }

    $result = [];
    $current = $questions[0];
    $visited = [];

    while (
        isset($current['id'])
        && !isset($visited[(string)$current['id']])
    ) {
        $id = (string)$current['id'];
        $visited[$id] = true;
        $result[] = $current;

        $answer = $answers[$id] ?? '';
        $next = '';

        if (($current['type'] ?? '') === 'single') {
            foreach (($current['options'] ?? []) as $option) {
                if (
                    (string)($option['id'] ?? '') ===
                    (string)$answer
                ) {
                    $next = (string)(
                        $option['nextQuestionId'] ?? ''
                    );
                    break;
                }
            }
        }

        if ($next === '' || !isset($byId[$next])) {
            break;
        }

        $current = $byId[$next];
    }

    return $result;
}

function validate_answers(array $survey, array $answers): array
{
    $errors = [];

    foreach (visible_questions($survey, $answers) as $question) {
        $id = (string)$question['id'];
        $value = $answers[$id] ?? '';

        if (!empty($question['required'])) {
            $empty =
                $value === ''
                || $value === null
                || (
                    is_array($value)
                    && count($value) === 0
                );

            if ($empty) {
                $errors[] =
                    (string)$question['number'] .
                    ' は必須です。';
            }
        }

        if (
            ($question['type'] ?? '') === 'multiple'
            && $value !== ''
            && !is_array($value)
        ) {
            $errors[] =
                (string)$question['number'] .
                ' の回答形式が不正です。';
        }
    }

    return $errors;
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '#\.cybozu\.com.*$#i',
        '',
        $value
    ) ?? $value;

    return trim($value, " \t\n\r\0\x0B/");
}

function validate_kintone(array $config, bool $requirePassword = true): array
{
    $errors = [];

    $subdomain = normalize_kintone_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    if (
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $subdomain
        )
    ) {
        $errors[] = 'kintoneサブドメインが不正です。';
    }

    $appId = trim((string)($config['app_id'] ?? ''));

    if (!ctype_digit($appId) || (int)$appId < 1) {
        $errors[] = '顧客管理アプリIDが不正です。';
    }

    if (trim((string)($config['username'] ?? '')) === '') {
        $errors[] = 'kintoneログイン名を入力してください。';
    }

    if (
        $requirePassword
        && trim((string)($config['password'] ?? '')) === ''
    ) {
        $errors[] = 'kintoneパスワードを入力してください。';
    }

    $proxy = trim((string)($config['proxy'] ?? ''));

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        $errors[] = 'Proxyはhost:port形式で入力してください。';
    }

    return $errors;
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $errors = validate_kintone($config, true);

    if ($errors !== []) {
        throw new RuntimeException(implode("\n", $errors));
    }

    $subdomain = normalize_kintone_subdomain(
        (string)$config['subdomain']
    );

    $url =
        'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;

    $authorization = base64_encode(
        (string)$config['username'] .
        ':' .
        (string)$config['password']
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'timeout' => KINTONE_TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => implode("\r\n", $headers),
        ],
        'ssl' => [
            'verify_peer' =>
                !empty($config['verify_ssl']),
            'verify_peer_name' =>
                !empty($config['verify_ssl']),
            'allow_self_signed' =>
                empty($config['verify_ssl']),
        ],
    ];

    if ($body !== null) {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(
                'kintone送信用JSONを生成できません。'
            );
        }

        $options['http']['content'] = $json;
    }

    if (
        trim((string)($config['proxy'] ?? '')) !== ''
    ) {
        $proxy = trim((string)$config['proxy']);

        $options['http']['proxy'] =
            'tcp://' . $proxy;

        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $bodyResponse = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders =
        $http_response_header ?? [];

    if ($bodyResponse === false) {
        throw new RuntimeException(
            'kintoneへの通信結果を取得できませんでした。'
        );
    }

    $status = 0;

    foreach ($responseHeaders as $header) {
        if (
            preg_match(
                '#^HTTP/\S+\s+(\d+)#i',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    if ($status === 301 || $status === 302 || $status === 303) {
        throw new RuntimeException(
            'kintone APIからリダイレクト応答が返されました。'
        );
    }

    $decoded = json_decode($bodyResponse, true);

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $bodyResponse,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function kintone_test(array $config): array
{
    $appId = (int)$config['app_id'];

    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id=' . $appId
    );
}

function kintone_fields(array $config): array
{
    $appId = (int)$config['app_id'];

    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' . $appId
    );
}

function kintone_records(array $config): array
{
    $appId = (int)$config['app_id'];

    return kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app=' . $appId .
        '&totalCount=true'
    );
}

function kintone_field_list(array $response): array
{
    if (($response['status'] ?? 0) !== 200) {
        $message =
            (string)(
                $response['json']['message']
                ?? '項目取得に失敗しました。'
            );

        throw new RuntimeException(
            'kintone項目取得失敗：' . $message
        );
    }

    $fields = $response['json']['properties'] ?? [];

    if (!is_array($fields)) {
        return [];
    }

    $result = [];

    foreach ($fields as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $result[] = [
            'code' => (string)$code,
            'label' => (string)($field['label'] ?? $code),
            'type' => (string)($field['type'] ?? ''),
        ];
    }

    return $result;
}

function kintone_error(array $response): string
{
    $json = $response['json'] ?? null;

    if (is_array($json)) {
        $code = (string)($json['code'] ?? '');
        $message = (string)($json['message'] ?? '');

        if ($code !== '' || $message !== '') {
            return trim(
                'HTTP ' .
                (int)($response['status'] ?? 0) .
                ' ' .
                $code .
                ' ' .
                $message
            );
        }
    }

    return 'HTTP ' .
        (int)($response['status'] ?? 0) .
        ' 応答内容を確認できません。';
}

function kintone_value(mixed $value): string
{
    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] = (string)(
                    $item['value']
                    ?? $item['name']
                    ?? ''
                );
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(', ', $parts);
    }

    return (string)$value;
}

function map_kintone_customer(
    array $record,
    array $mapping
): array {
    $get = static function (string $key) use (
        $record,
        $mapping
    ): string {
        $code = (string)($mapping[$key] ?? '');

        if ($code === '' || !isset($record[$code])) {
            return '';
        }

        return kintone_value(
            $record[$code]['value'] ?? ''
        );
    };

    $addresses = [];

    foreach (
        (array)($mapping['address'] ?? []) as $code
    ) {
        if (
            isset($record[$code])
        ) {
            $v = kintone_value(
                $record[$code]['value'] ?? ''
            );

            if ($v !== '') {
                $addresses[] = $v;
            }
        }
    }

    return [
        'id' => (string)(
            $record['$id']['value']
            ?? uid('customer')
        ),
        'organization' => $get('organization'),
        'name' => $get('name'),
        'email' => $get('email'),
        'department' => $get('department'),
        'phone' => $get('phone'),
        'address' => implode(' ', $addresses),
        'syncedAt' => now(),
    ];
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtp_connect(array $config)
{
    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port'] ?? 0);

    if ($host === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException(
            'SMTPサーバまたはポートが不正です。'
        );
    }

    $encryption = (string)(
        $config['encryption'] ?? 'tls'
    );

    $target = $host;

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $fp = @fsockopen(
        $target,
        $port,
        $errno,
        $errstr,
        SMTP_TIMEOUT
    );

    if (!$fp) {
        throw new RuntimeException(
            'SMTP接続失敗：' .
            ($errstr !== '' ? $errstr : '接続できません。')
        );
    }

    stream_set_timeout($fp, SMTP_TIMEOUT);

    smtp_expect($fp, [220]);

    smtp_command($fp, 'EHLO localhost', [250]);

    if ($encryption === 'tls') {
        smtp_command($fp, 'STARTTLS', [220]);

        $crypto = @stream_socket_enable_crypto(
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

        smtp_command($fp, 'EHLO localhost', [250]);
    }

    $auth = !empty($config['auth']);

    if ($auth) {
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');

        if ($username === '' || $password === '') {
            fclose($fp);
            throw new RuntimeException(
                'SMTP認証情報が設定されていません。'
            );
        }

        smtp_command($fp, 'AUTH LOGIN', [334]);
        smtp_command(
            $fp,
            base64_encode($username),
            [334]
        );
        smtp_command(
            $fp,
            base64_encode($password),
            [235]
        );
    }

    return $fp;
}

function smtp_read($fp): array
{
    $lines = [];

    while (($line = fgets($fp)) !== false) {
        $line = rtrim($line, "\r\n");
        $lines[] = $line;

        if (
            strlen($line) >= 4
            && $line[3] === ' '
        ) {
            break;
        }
    }

    return $lines;
}

function smtp_expect($fp, array $codes): void
{
    $lines = smtp_read($fp);

    if ($lines === []) {
        throw new RuntimeException(
            'SMTPサーバーから応答を取得できません。'
        );
    }

    $last = $lines[count($lines) - 1];
    $code = (int)substr($last, 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTP応答エラー：HTTPではないSMTP応答 ' .
            $code
        );
    }
}

function smtp_command(
    $fp,
    string $command,
    array $codes
): void {
    if (@fwrite($fp, $command . "\r\n") === false) {
        throw new RuntimeException(
            'SMTPコマンドを送信できません。'
        );
    }

    smtp_expect($fp, $codes);
}

function smtp_test(array $config): void
{
    $fp = smtp_connect($config);

    smtp_command($fp, 'QUIT', [221]);
    fclose($fp);
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            '宛先メールアドレスが不正です。'
        );
    }

    $from = trim((string)($config['from_email'] ?? ''));

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    $fp = smtp_connect($config);

    try {
        smtp_command(
            $fp,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_command(
            $fp,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_command($fp, 'DATA', [354]);

        $fromName = trim(
            (string)($config['from_name'] ?? '')
        );

        $fromHeader = $from;

        if ($fromName !== '') {
            $fromHeader =
                '=?UTF-8?B?' .
                base64_encode($fromName) .
                '?= <' .
                $from .
                '>';
        }

        $reply = trim(
            (string)($config['reply_to'] ?? '')
        );

        $headers = [
            'From: ' . $fromHeader,
            'To: ' . $to,
            'Subject: =?UTF-8?B?' .
                base64_encode($subject) .
                '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (
            filter_var(
                $reply,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $headers[] = 'Reply-To: ' . $reply;
        }

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            preg_replace(
                "/(?<!\r)\n/",
                "\r\n",
                $body
            );

        $message = preg_replace(
            '/^\./m',
            '..',
            $message
        ) ?? $message;

        if (@fwrite(
            $fp,
            $message . "\r\n.\r\n"
        ) === false) {
            throw new RuntimeException(
                'メール本文を送信できません。'
            );
        }

        smtp_expect($fp, [250]);

        smtp_command($fp, 'QUIT', [221]);
    } finally {
        fclose($fp);
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
    $v = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($v) ? $v : null;
}

/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): array {
    $action = post_string('action');

    switch ($action) {

        /* -------------------------------------------------
         * アンケート保存
         * ------------------------------------------------- */

        case 'save_survey':
            $id = post_string('survey_id');

            $survey = [
                'id' => $id !== '' ? $id : uid('survey'),
                'title' => mb_substr(
                    post_string('title'),
                    0,
                    MAX_TITLE
                ),
                'description' => mb_substr(
                    post_string('description'),
                    0,
                    MAX_DESCRIPTION
                ),
                'startAt' => post_string('startAt'),
                'endAt' => post_string('endAt'),
                'status' => post_string('status'),
                'numbering' =>
                    post_string('numbering') === 'group'
                        ? 'group'
                        : 'global',
                'createdAt' => now(),
                'updatedAt' => now(),
                'groups' => normalize_groups(
                    post_value('groups', [])
                ),
            ];

            if ($survey['title'] === '') {
                flash(
                    'error',
                    'アンケートタイトルを入力してください。'
                );

                return [
                    'screen' => 'edit',
                    'id' => $id,
                ];
            }

            if (
                !in_array(
                    $survey['status'],
                    ['draft', 'published', 'stopped', 'ended'],
                    true
                )
            ) {
                $survey['status'] = 'draft';
            }

            $old = survey_get(
                $data['surveys'],
                $id
            );

            if ($old) {
                $survey['createdAt'] =
                    (string)($old['createdAt'] ?? now());

                if (
                    ($old['status'] ?? 'draft') === 'ended'
                ) {
                    $survey['status'] = 'ended';
                }
            } else {
                $survey['status'] = 'draft';
            }

            recalc_numbers($survey);

            $index = survey_index(
                $data['surveys'],
                $survey['id']
            );

            if ($index >= 0) {
                $data['surveys'][$index] = $survey;
            } else {
                $data['surveys'][] = $survey;
            }

            save_data($data);

            flash(
                'success',
                'アンケートを保存しました。'
            );

            return ['screen' => 'list'];

        /* -------------------------------------------------
         * ステータス変更
         * ------------------------------------------------- */

        case 'change_status':
            $id = post_string('survey_id');
            $status = post_string('status');

            $allowed = [
                'draft' => ['published'],
                'published' => ['stopped'],
                'stopped' => ['published'],
                'ended' => [],
            ];

            $index = survey_index(
                $data['surveys'],
                $id
            );

            if ($index < 0) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return ['screen' => 'list'];
            }

            $current = (string)(
                $data['surveys'][$index]['status'] ?? 'draft'
            );

            if (
                !in_array(
                    $status,
                    $allowed[$current] ?? [],
                    true
                )
            ) {
                flash(
                    'error',
                    '指定された状態変更はできません。'
                );

                return ['screen' => 'list'];
            }

            $data['surveys'][$index]['status'] = $status;
            $data['surveys'][$index]['updatedAt'] = now();

            save_data($data);

            flash(
                'success',
                'アンケート状態を変更しました。'
            );

            return ['screen' => 'list'];

        /* -------------------------------------------------
         * 複製
         * ------------------------------------------------- */

        case 'duplicate_survey':
            $id = post_string('survey_id');

            $source = survey_get(
                $data['surveys'],
                $id
            );

            if (!$source) {
                flash(
                    'error',
                    '複製対象のアンケートがありません。'
                );

                return ['screen' => 'list'];
            }

            $copy = $source;
            $copy['id'] = uid('survey');
            $copy['title'] =
                mb_substr(
                    (string)$source['title'] . '（複製）',
                    0,
                    MAX_TITLE
                );
            $copy['status'] = 'draft';
            $copy['createdAt'] = now();
            $copy['updatedAt'] = now();

            foreach ($copy['groups'] as &$group) {
                $group['id'] = uid('group');

                foreach ($group['questions'] as &$question) {
                    $question['id'] = uid('question');

                    foreach ($question['options'] as &$option) {
                        $option['id'] = uid('option');
                    }

                    unset($option);
                }

                unset($question);
            }

            unset($group);

            recalc_numbers($copy);

            $data['surveys'][] = $copy;

            save_data($data);

            flash(
                'success',
                'アンケートを複製しました。'
            );

            return ['screen' => 'list'];

        /* -------------------------------------------------
         * 削除
         * ------------------------------------------------- */

        case 'delete_survey':
            $id = post_string('survey_id');

            $index = survey_index(
                $data['surveys'],
                $id
            );

            if ($index >= 0) {
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
            }

            return ['screen' => 'list'];

        /* -------------------------------------------------
         * kintone設定保存
         * ------------------------------------------------- */

        case 'save_kintone':
            $current = $settings['kintone'];

            $password = post_string('password');

            if ($password === '') {
                $password =
                    (string)($current['password'] ?? '');
            }

            $config = [
                'subdomain' =>
                    normalize_kintone_subdomain(
                        post_string('subdomain')
                    ),
                'app_id' => post_string('app_id'),
                'username' => post_string('username'),
                'password' => $password,
                'proxy' => post_string('proxy'),
                'verify_ssl' => post_bool('verify_ssl'),
                'mapping' =>
                    $current['mapping'] ?? [],
                'fields' =>
                    $current['fields'] ?? [],
                'last_test' =>
                    $current['last_test'] ?? null,
                'last_sync' =>
                    $current['last_sync'] ?? null,
            ];

            $errors = validate_kintone(
                $config,
                true
            );

            if ($errors !== []) {
                flash(
                    'error',
                    implode("\n", $errors)
                );

                return ['screen' => 'kintone'];
            }

            $settings['kintone'] = $config;

            save_settings($settings);

            flash(
                'success',
                'kintone接続設定を保存しました。'
            );

            return ['screen' => 'kintone'];

        /* -------------------------------------------------
         * kintone接続テスト
         * ------------------------------------------------- */

        case 'test_kintone':
            try {
                $response = kintone_test(
                    $settings['kintone']
                );

                if (
                    ($response['status'] ?? 0) !== 200
                ) {
                    throw new RuntimeException(
                        kintone_error($response)
                    );
                }

                $settings['kintone']['last_test'] = now();

                save_settings($settings);

                flash(
                    'success',
                    'kintone接続テスト成功。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone接続テスト失敗：' .
                    safe_error($e)
                );
            }

            return ['screen' => 'kintone'];

        /* -------------------------------------------------
         * kintone項目取得
         * ------------------------------------------------- */

        case 'load_kintone_fields':
            try {
                $response = kintone_fields(
                    $settings['kintone']
                );

                $settings['kintone']['fields'] =
                    kintone_field_list($response);

                save_settings($settings);

                flash(
                    'success',
                    'kintone項目一覧を取得しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone項目取得失敗：' .
                    safe_error($e)
                );
            }

            return ['screen' => 'kintone'];

        /* -------------------------------------------------
         * kintoneマッピング保存
         * ------------------------------------------------- */

        case 'save_kintone_mapping':
            $mapping = [
                'organization' =>
                    post_string('mapping_organization'),
                'name' =>
                    post_string('mapping_name'),
                'email' =>
                    post_string('mapping_email'),
                'department' =>
                    post_string('mapping_department'),
                'phone' =>
                    post_string('mapping_phone'),
                'address' =>
                    post_value('mapping_address', []),
            ];

            if (!is_array($mapping['address'])) {
                $mapping['address'] = [];
            }

            $settings['kintone']['mapping'] = $mapping;

            save_settings($settings);

            flash(
                'success',
                '顧客項目マッピングを保存しました。'
            );

            return ['screen' => 'kintone'];

        /* -------------------------------------------------
         * 顧客同期
         * ------------------------------------------------- */

        case 'sync_kintone':
            try {
                $response = kintone_records(
                    $settings['kintone']
                );

                if (
                    ($response['status'] ?? 0) !== 200
                ) {
                    throw new RuntimeException(
                        kintone_error($response)
                    );
                }

                $records =
                    $response['json']['records'] ?? [];

                if (!is_array($records)) {
                    throw new RuntimeException(
                        'kintoneの顧客レコード形式が不正です。'
                    );
                }

                $mapping =
                    $settings['kintone']['mapping'] ?? [];

                $customers = [];

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $customers[] =
                        map_kintone_customer(
                            $record,
                            $mapping
                        );
                }

                $data['customers'] = $customers;

                save_data($data);

                $settings['kintone']['last_sync'] = now();

                save_settings($settings);

                flash(
                    'success',
                    count($customers) .
                    '件の顧客情報を同期しました。'
                );

                return ['screen' => 'customers'];
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone顧客同期失敗：' .
                    safe_error($e)
                );

                return ['screen' => 'kintone'];
            }

        /* -------------------------------------------------
         * SMTP保存
         * ------------------------------------------------- */

        case 'save_mail':
            $current = $settings['mail'];

            $password = post_string('password');

            if ($password === '') {
                $password =
                    (string)($current['password'] ?? '');
            }

            $settings['mail'] = [
                'host' => post_string('host'),
                'port' => max(
                    1,
                    min(
                        65535,
                        (int)post_value('port', 587)
                    )
                ),
                'encryption' =>
                    in_array(
                        post_string('encryption'),
                        ['ssl', 'tls', 'none'],
                        true
                    )
                        ? post_string('encryption')
                        : 'tls',
                'auth' => post_bool('auth'),
                'username' => post_string('username'),
                'password' => $password,
                'from_email' =>
                    post_string('from_email'),
                'from_name' =>
                    post_string('from_name'),
                'reply_to' =>
                    post_string('reply_to'),
                'last_test' =>
                    $current['last_test'] ?? null,
            ];

            if (
                !filter_var(
                    $settings['mail']['from_email'],
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                flash(
                    'error',
                    '送信元メールアドレスを入力してください。'
                );

                return ['screen' => 'mail'];
            }

            save_settings($settings);

            flash(
                'success',
                'メールサーバ設定を保存しました。'
            );

            return ['screen' => 'mail'];

        /* -------------------------------------------------
         * SMTPテスト
         * ------------------------------------------------- */

        case 'test_mail':
            try {
                smtp_test($settings['mail']);

                $settings['mail']['last_test'] = now();

                save_settings($settings);

                flash(
                    'success',
                    'SMTP接続テスト成功。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'SMTP接続テスト失敗：' .
                    safe_error($e)
                );
            }

            return ['screen' => 'mail'];

        /* -------------------------------------------------
         * テストメール
         * ------------------------------------------------- */

        case 'send_test_mail':
            $to = post_string('test_email');

            try {
                smtp_send(
                    $settings['mail'],
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
                    safe_error($e)
                );
            }

            return ['screen' => 'mail'];

        /* -------------------------------------------------
         * 回答入力
         * ------------------------------------------------- */

        case 'answer_next':
            $surveyId = post_string('survey_id');
            $survey = survey_get(
                $data['surveys'],
                $surveyId
            );

            if (!$survey) {
                return [
                    'screen' => 'answer_message',
                ];
            }

            $answers = post_value('answers', []);

            if (!is_array($answers)) {
                $answers = [];
            }

            $_SESSION[
                'answer_' . $surveyId
            ] = $answers;

            $errors = validate_answers(
                $survey,
                $answers
            );

            if ($errors !== []) {
                flash(
                    'error',
                    implode("\n", $errors)
                );

                return [
                    'screen' => 'answer',
                    'id' => $surveyId,
                ];
            }

            return [
                'screen' => 'answer_confirm',
                'id' => $surveyId,
            ];

        case 'answer_back':
            return [
                'screen' => 'answer',
                'id' => post_string('survey_id'),
            ];

        case 'submit_answer':
            $surveyId = post_string('survey_id');

            $survey = survey_get(
                $data['surveys'],
                $surveyId
            );

            if (!$survey) {
                return [
                    'screen' => 'answer_message',
                ];
            }

            $answers =
                $_SESSION[
                    'answer_' . $surveyId
                ] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $errors = validate_answers(
                $survey,
                $answers
            );

            if ($errors !== []) {
                flash(
                    'error',
                    implode("\n", $errors)
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

            return [
                'screen' => 'complete',
                'id' => $surveyId,
            ];

        /* -------------------------------------------------
         * メール送信
         * ------------------------------------------------- */

        case 'send_bulk_mail':
            $surveyId = post_string('survey_id');
            $survey = survey_get(
                $data['surveys'],
                $surveyId
            );

            if (!$survey) {
                flash(
                    'error',
                    '送信対象のアンケートが見つかりません。'
                );

                return ['screen' => 'list'];
            }

            $selected = post_value(
                'customer_ids',
                []
            );

            if (!is_array($selected)) {
                $selected = [];
            }

            $subject = post_string('subject');
            $body = post_string('body');

            $sent = 0;
            $failed = 0;

            foreach ($data['customers'] as $customer) {
                if (!is_array($customer)) {
                    continue;
                }

                $customerId =
                    (string)($customer['id'] ?? '');

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
                    (string)($customer['email'] ?? '');

                if (
                    !filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $failed++;
                    continue;
                }

                $mailBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        (string)($customer['name'] ?? ''),
                        public_url($surveyId),
                    ],
                    $body
                );

                try {
                    smtp_send(
                        $settings['mail'],
                        $email,
                        $subject,
                        $mailBody
                    );

                    $sent++;

                    $data['send_history'][] = [
                        'id' => uid('send'),
                        'surveyId' => $surveyId,
                        'customerId' => $customerId,
                        'email' => $email,
                        'type' => post_string('send_type') ?: 'send',
                        'status' => 'sent',
                        'sentAt' => now(),
                    ];
                } catch (Throwable $e) {
                    $failed++;

                    $data['send_history'][] = [
                        'id' => uid('send'),
                        'surveyId' => $surveyId,
                        'customerId' => $customerId,
                        'email' => $email,
                        'type' => post_string('send_type') ?: 'send',
                        'status' => 'failed',
                        'error' => safe_error($e),
                        'sentAt' => now(),
                    ];
                }
            }

            save_data($data);

            flash(
                $failed > 0 ? 'warning' : 'success',
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

        default:
            flash(
                'error',
                '不明な操作です。'
            );

            return ['screen' => 'list'];
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
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP","Hiragino Kaku Gothic ProN",
 Meiryo,sans-serif;
}
header{
 background:#fff;
 border-bottom:1px solid #dbe2ea;
}
.nav{
 max-width:1450px;
 margin:auto;
 padding:15px 20px;
 display:flex;
 flex-wrap:wrap;
 gap:10px 16px;
 align-items:center;
}
.nav strong{margin-right:auto}
.nav a{
 color:#2563eb;
 text-decoration:none;
}
.wrap{
 max-width:1450px;
 margin:auto;
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
 grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
 gap:16px;
}
.field{margin-bottom:15px}
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
 min-height:120px;
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
.table-wrap{overflow-x:auto}
table{
 width:100%;
 border-collapse:collapse;
 min-width:850px;
}
th,td{
 padding:10px;
 border-bottom:1px solid #e2e8f0;
 text-align:left;
 vertical-align:top;
}
th{background:#f8fafc}
.flash{
 padding:14px;
 border-radius:10px;
 margin-bottom:18px;
 white-space:pre-line;
}
.flash.success{background:#dcfce7;color:#166534}
.flash.error{background:#fee2e2;color:#991b1b}
.flash.warning{background:#fef3c7;color:#92400e}
.badge{
 display:inline-block;
 padding:4px 8px;
 border-radius:999px;
 font-size:12px;
}
.badge.success{background:#dcfce7;color:#166534}
.badge.warning{background:#fef3c7;color:#92400e}
.badge.gray{background:#e2e8f0;color:#475569}
.small{color:#64748b;font-size:13px}
.question{
 border:1px solid #dbe2ea;
 border-radius:12px;
 padding:16px;
 margin:12px 0;
 background:#fbfdff;
}
.group{
 border:2px solid #dbe2ea;
 border-radius:14px;
 padding:18px;
 margin:18px 0;
 background:#fff;
}
.group.dragging,
.question.dragging{
 opacity:.45;
}
.drag-handle{
 cursor:grab;
 user-select:none;
 color:#64748b;
 font-size:13px;
}
.option-row{
 display:grid;
 grid-template-columns:1fr auto;
 gap:8px;
 margin:7px 0;
}
.preview{
 border:1px solid #cbd5e1;
 border-radius:12px;
 padding:18px;
 background:#fff;
}
.sticky-actions{
 position:sticky;
 top:0;
 z-index:10;
 background:#fff;
 padding:12px;
 margin:-22px -22px 18px;
 border-bottom:1px solid #e2e8f0;
}
.empty{
 padding:30px;
 text-align:center;
 color:#64748b;
}
@media(max-width:700px){
 .wrap{padding:15px 10px 40px}
 .card{padding:15px}
 .sticky-actions{margin:-15px -15px 15px}
}
</style>
</head>
<body>
<header>
<div class="nav">
<strong><?= h(APP_TITLE) ?></strong>
<a href="<?= h(app_url(['screen'=>'list'])) ?>">アンケート一覧</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">kintone</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">メール設定</a>
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
 * 一覧
 * ========================================================= */

function render_list(array $data): void
{
    $search = get_string('q');
    $status = get_string('status');
    $sort = get_string('sort');

    if (
        !in_array(
            $status,
            ['', 'all', 'published', 'draft', 'stopped', 'ended'],
            true
        )
    ) {
        $status = 'all';
    }

    if (
        !in_array(
            $sort,
            ['updated_desc', 'updated_asc',
             'answers_desc', 'answers_asc',
             'start_desc', 'start_asc'],
            true
        )
    ) {
        $sort = 'updated_desc';
    }

    $surveys = [];

    foreach ($data['surveys'] as $survey) {
        if (!is_array($survey)) {
            continue;
        }

        if (
            $search !== ''
            && mb_stripos(
                (string)($survey['title'] ?? ''),
                $search
            ) === false
        ) {
            continue;
        }

        if (
            $status !== ''
            && $status !== 'all'
            && (string)($survey['status'] ?? '') !== $status
        ) {
            continue;
        }

        $survey['_answerCount'] = 0;

        foreach ($data['answers'] as $answer) {
            if (
                is_array($answer)
                && (string)($answer['surveyId'] ?? '') ===
                   (string)($survey['id'] ?? '')
            ) {
                $survey['_answerCount']++;
            }
        }

        $surveys[] = $survey;
    }

    usort(
        $surveys,
        static function (
            array $a,
            array $b
        ) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)($a['updatedAt'] ?? ''),
                        (string)($b['updatedAt'] ?? '')
                    ),
                'answers_desc' =>
                    ((int)$b['_answerCount'])
                    <=>
                    ((int)$a['_answerCount']),
                'answers_asc' =>
                    ((int)$a['_answerCount'])
                    <=>
                    ((int)$b['_answerCount']),
                'start_desc' =>
                    strcmp(
                        (string)($b['startAt'] ?? ''),
                        (string)($a['startAt'] ?? '')
                    ),
                'start_asc' =>
                    strcmp(
                        (string)($a['startAt'] ?? ''),
                        (string)($b['startAt'] ?? '')
                    ),
                default =>
                    strcmp(
                        (string)($b['updatedAt'] ?? ''),
                        (string)($a['updatedAt'] ?? '')
                    ),
            };
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
<input type="hidden" name="screen" value="list">

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
<option value="all"
 <?= $status === 'all' || $status === '' ? 'selected' : '' ?>>
すべて
</option>
<option value="published"
 <?= $status === 'published' ? 'selected' : '' ?>>
公開中
</option>
<option value="draft"
 <?= $status === 'draft' ? 'selected' : '' ?>>
下書き
</option>
<option value="stopped"
 <?= $status === 'stopped' ? 'selected' : '' ?>>
停止
</option>
<option value="ended"
 <?= $status === 'ended' ? 'selected' : '' ?>>
終了
</option>
</select>
</div>

<div class="field">
<label>ソート</label>
<select name="sort">
<option value="updated_desc"
 <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
更新日：新しい順
</option>
<option value="updated_asc"
 <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
更新日：古い順
</option>
<option value="answers_desc"
 <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
回答数：多い順
</option>
<option value="answers_asc"
 <?= $sort === 'answers_asc' ? 'selected' : '' ?>>
回答数：少ない順
</option>
<option value="start_desc"
 <?= $sort === 'start_desc' ? 'selected' : '' ?>>
開始日：新しい順
</option>
<option value="start_asc"
 <?= $sort === 'start_asc' ? 'selected' : '' ?>>
開始日：古い順
</option>
</select>
</div>
</div>

<div class="actions">
<button class="primary">検索</button>
<a class="btn"
   href="<?= h(app_url(['screen'=>'edit'])) ?>">
新規作成
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
<th>作成日</th>
<th>更新日</th>
<th>期間</th>
<th>ステータス</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>

<?php if ($surveys === []): ?>
<tr>
<td colspan="7" class="empty">
該当するアンケートがありません。
</td>
</tr>
<?php endif; ?>

<?php foreach ($surveys as $survey): ?>
<tr>
<td>
<strong><?= h($survey['title'] ?? '') ?></strong>
</td>

<td><?= h($survey['createdAt'] ?? '') ?></td>
<td><?= h($survey['updatedAt'] ?? '') ?></td>

<td>
<?= h($survey['startAt'] ?? '') ?><br>
～<br>
<?= h($survey['endAt'] ?? '') ?>
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

<td><?= h($survey['_answerCount']) ?></td>

<td>
<div class="actions">

<a class="btn"
 href="<?= h(app_url([
     'screen' => 'edit',
     'id' => $survey['id'],
 ])) ?>">
確認・編集
</a>

<a class="btn"
 href="<?= h(app_url([
     'screen' => 'preview',
     'id' => $survey['id'],
 ])) ?>">
プレビュー
</a>

<a class="btn"
 href="<?= h(app_url([
     'screen' => 'analytics',
     'id' => $survey['id'],
 ])) ?>">
集計
</a>

<a class="btn"
 href="<?= h(app_url([
     'screen' => 'send',
     'id' => $survey['id'],
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

<?php if (($survey['status'] ?? '') === 'draft'): ?>
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
<?php elseif (($survey['status'] ?? '') === 'published'): ?>
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
<?php elseif (($survey['status'] ?? '') === 'stopped'): ?>
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

<?php if (($survey['status'] ?? '') !== 'published'): ?>
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
    admin_footer();
}

/* =========================================================
 * 顧客一覧
 * ========================================================= */

function render_customers(array $data): void
{
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

<?php if ($data['customers'] === []): ?>
<tr>
<td colspan="7" class="empty">
同期済み顧客がありません。
</td>
</tr>
<?php endif; ?>

<?php foreach ($data['customers'] as $customer): ?>
<?php if (!is_array($customer)) continue; ?>
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
 * kintone設定
 * ========================================================= */

function render_kintone(array $settings): void
{
    $flash = flash_get();
    $config = $settings['kintone'];

    $display = $config;
    $display['password'] = '';

    $mapping = $config['mapping'] ?? [];
    $fields = $config['fields'] ?? [];

    $mapFields = [
        'organization' => '組織',
        'name' => '氏名',
        'email' => 'メールアドレス',
        'department' => '部署',
        'phone' => '電話',
    ];

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
       value="<?= h($display['subdomain'] ?? '') ?>"
       placeholder="example / example.cybozu.com">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input type="number"
       name="app_id"
       value="<?= h($display['app_id'] ?? '') ?>"
       min="1">
</div>

<div class="field">
<label>ログイン名</label>
<input type="text"
       name="username"
       value="<?= h($display['username'] ?? '') ?>">
</div>

<div class="field">
<label>パスワード</label>
<input type="password"
       name="password"
       value=""
       autocomplete="new-password"
       placeholder="変更する場合のみ入力">
</div>

<div class="field">
<label>Proxy</label>
<input type="text"
       name="proxy"
       value="<?= h($display['proxy'] ?? '') ?>"
       placeholder="host:port">
</div>

</div>

<div class="field">
<label>
<input type="checkbox"
       name="verify_ssl"
       value="1"
 <?= !empty($display['verify_ssl']) ? 'checked' : '' ?>>
SSL証明書を検証する
</label>
</div>

<button class="primary">設定保存</button>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="test_kintone">

<button class="primary"
        onclick="this.disabled=true;this.form.submit();">
接続テスト
</button>
</form>

<?php if (!empty($config['last_test'])): ?>
<p class="small">
最終接続テスト：
<?= h($config['last_test']) ?>
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>項目一覧</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="load_kintone_fields">
<button>項目一覧を再取得</button>
</form>

<?php if ($fields !== []): ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>コード</th>
<th>ラベル</th>
<th>タイプ</th>
</tr>
</thead>
<tbody>
<?php foreach ($fields as $field): ?>
<tr>
<td><?= h($field['code'] ?? '') ?></td>
<td><?= h($field['label'] ?? '') ?></td>
<td><?= h($field['type'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>

<div class="card">
<h2>顧客情報同期</h2>
<p>
kintoneの顧客管理アプリから顧客情報を取得し、
同期した顧客一覧を表示します。
</p>

<form method="post"
      onsubmit="return confirm('顧客情報を同期しますか？')">
<input type="hidden"
       name="action"
       value="sync_kintone">
<button class="primary">顧客情報を同期</button>
</form>

<?php if (!empty($config['last_sync'])): ?>
<p class="small">
最終同期：
<?= h($config['last_sync']) ?>
</p>
<?php endif; ?>

<div class="actions">
<a class="btn"
   href="<?= h(app_url(['screen'=>'customers'])) ?>">
同期済み顧客一覧を表示
</a>
</div>
</div>

<div class="card">
<h2>顧客項目マッピング</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<?php foreach ($mapFields as $key => $label): ?>
<div class="field">
<label><?= h($label) ?></label>

<select name="mapping_<?= h($key) ?>">
<option value="">未設定</option>

<?php foreach ($fields as $field): ?>
<option value="<?= h($field['code'] ?? '') ?>"
 <?= (
     ($mapping[$key] ?? '') ===
     ($field['code'] ?? '')
 ) ? 'selected' : '' ?>>
<?= h(
    ($field['code'] ?? '') .
    ' / ' .
    ($field['label'] ?? '')
) ?>
</option>
<?php endforeach; ?>

</select>
</div>
<?php endforeach; ?>

<div class="field">
<label>住所（複数項目可）</label>

<?php foreach ($fields as $field): ?>
<label>
<input type="checkbox"
       name="mapping_address[]"
       value="<?= h($field['code'] ?? '') ?>"
 <?= in_array(
     $field['code'] ?? '',
     $mapping['address'] ?? [],
     true
 ) ? 'checked' : '' ?>>
<?= h(
    ($field['code'] ?? '') .
    ' / ' .
    ($field['label'] ?? '')
) ?>
</label>
<?php endforeach; ?>
</div>

<button class="primary">マッピング保存</button>
</form>
</div>
<?php
    admin_footer();
}

/* =========================================================
 * メール設定
 * ========================================================= */

function render_mail(array $settings): void
{
    $flash = flash_get();
    $config = $settings['mail'];
    $display = $config;
    $display['password'] = '';

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
       value="<?= h($display['host'] ?? '') ?>">
</div>

<div class="field">
<label>ポート</label>
<input type="number"
       name="port"
       value="<?= h($display['port'] ?? 587) ?>"
       min="1"
       max="65535">
</div>

<div class="field">
<label>暗号化</label>
<select name="encryption">
<option value="tls"
 <?= ($display['encryption'] ?? '') === 'tls'
     ? 'selected' : '' ?>>
TLS
</option>
<option value="ssl"
 <?= ($display['encryption'] ?? '') === 'ssl'
     ? 'selected' : '' ?>>
SSL
</option>
<option value="none"
 <?= ($display['encryption'] ?? '') === 'none'
     ? 'selected' : '' ?>>
なし
</option>
</select>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input type="text"
       name="username"
       value="<?= h($display['username'] ?? '') ?>">
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
       name="password"
       value=""
       autocomplete="new-password"
       placeholder="変更する場合のみ入力">
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input type="email"
       name="from_email"
       value="<?= h($display['from_email'] ?? '') ?>">
</div>

<div class="field">
<label>送信元名</label>
<input type="text"
       name="from_name"
       value="<?= h($display['from_name'] ?? '') ?>">
</div>

<div class="field">
<label>返信先</label>
<input type="email"
       name="reply_to"
       value="<?= h($display['reply_to'] ?? '') ?>">
</div>

</div>

<div class="field">
<label>
<input type="checkbox"
       name="auth"
       value="1"
 <?= !empty($display['auth']) ? 'checked' : '' ?>>
SMTP認証を使用する
</label>
</div>

<button class="primary">設定保存</button>
</form>
</div>

<div class="card">
<h2>SMTP接続テスト</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="test_mail">
<button class="primary">接続テスト</button>
</form>

<?php if (!empty($config['last_test'])): ?>
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

<button class="primary">テストメール送信</button>
</form>
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

    recalc_numbers($survey);

    $flash = flash_get();

    admin_header(
        $id ? 'アンケート編集' : 'アンケート作成',
        $flash
    );
?>
<div class="card">

<form method="post"
      id="survey-form"
      onsubmit="return prepareSurveyForm()">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="sticky-actions">

<div class="actions">

<a class="btn"
   href="<?= h(app_url(['screen'=>'list'])) ?>"
   onclick="return confirmCancel()">
キャンセル
</a>

<button class="primary">
保存して一覧へ
</button>

<?php if ($survey['id'] !== ''): ?>
<a class="btn"
   href="<?= h(app_url([
       'screen' => 'preview',
       'id' => $survey['id'],
   ])) ?>">
プレビュー
</a>
<?php endif; ?>

</div>

</div>

<div class="grid">

<div class="field">
<label>アンケートタイトル</label>
<input type="text"
       name="title"
       maxlength="<?= MAX_TITLE ?>"
       value="<?= h($survey['title']) ?>"
       required>
</div>

<div class="field">
<label>状態</label>
<select name="status"
        id="survey-status"
        <?= ($survey['status'] ?? '') === 'ended'
            ? 'disabled' : '' ?>>
<option value="draft"
 <?= $survey['status'] === 'draft'
     ? 'selected' : '' ?>>
下書き
</option>
<option value="published"
 <?= $survey['status'] === 'published'
     ? 'selected' : '' ?>>
公開中
</option>
<option value="stopped"
 <?= $survey['status'] === 'stopped'
     ? 'selected' : '' ?>>
停止
</option>
<option value="ended"
 <?= $survey['status'] === 'ended'
     ? 'selected' : '' ?>
 disabled>
終了
</option>
</select>

<?php if (($survey['status'] ?? '') === 'ended'): ?>
<input type="hidden"
       name="status"
       value="ended">
<?php endif; ?>
</div>

<div class="field">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt']) ?>">
</div>

<div class="field">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt']) ?>">
</div>

</div>

<div class="field">
<label>アンケート説明</label>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h(
    $survey['description']
) ?></textarea>
</div>

<div class="field">
<label>質問番号の採番方式</label>
<select name="numbering"
        id="numbering"
        onchange="recalculateClientNumbers()">
<option value="global"
 <?= ($survey['numbering'] ?? 'global') === 'global'
     ? 'selected' : '' ?>>
アンケート全体で通番（Q1、Q2、Q3…）
</option>
<option value="group"
 <?= ($survey['numbering'] ?? '') === 'group'
     ? 'selected' : '' ?>>
グループ毎（Q1-1、Q1-2、Q2-1…）
</option>
</select>
</div>

<div id="groups-container">

<?php foreach ($survey['groups'] as $groupIndex => $group): ?>
<div class="group"
     draggable="true"
     data-group-id="<?= h($group['id']) ?>">

<div class="actions">
<span class="drag-handle">↕ グループをドラッグして並び替え</span>

<button type="button"
        onclick="removeGroup(this)"
        class="danger">
グループ削除
</button>
</div>

<div class="field">
<label>グループタイトル</label>
<input type="text"
       name="groups[<?= h($group['id']) ?>][title]"
       value="<?= h($group['title']) ?>">
<input type="hidden"
       name="groups[<?= h($group['id']) ?>][id]"
       value="<?= h($group['id']) ?>">
</div>

<div class="questions-container">

<?php foreach ($group['questions'] as $question): ?>
<div class="question"
     draggable="true"
     data-question-id="<?= h($question['id']) ?>">

<div class="actions">
<span class="drag-handle">
↕ 質問をドラッグして並び替え
</span>

<span class="question-number">
<?= h($question['number']) ?>
</span>

<button type="button"
        onclick="removeQuestion(this)"
        class="danger">
質問削除
</button>
</div>

<input type="hidden"
       name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][id]"
       value="<?= h($question['id']) ?>">

<div class="field">
<label>質問文</label>
<textarea
 name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][text]"
 maxlength="<?= MAX_QUESTION ?>"><?= h(
     $question['text']
 ) ?></textarea>
</div>

<div class="grid">

<div class="field">
<label>回答形式</label>
<select
 name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][type]"
 onchange="questionTypeChanged(this)">
<option value="single"
 <?= $question['type'] === 'single'
     ? 'selected' : '' ?>>
単一選択
</option>
<option value="multiple"
 <?= $question['type'] === 'multiple'
     ? 'selected' : '' ?>>
複数選択
</option>
<option value="text"
 <?= $question['type'] === 'text'
     ? 'selected' : '' ?>>
自由記述
</option>
</select>
</div>

<div class="field">
<label>
<input type="checkbox"
 name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][required]"
 value="1"
 <?= !empty($question['required'])
     ? 'checked' : '' ?>>
必須
</label>
</div>

</div>

<div class="options-area">
<?php if ($question['type'] !== 'text'): ?>

<label>選択肢</label>

<div class="options-container">

<?php foreach ($question['options'] as $option): ?>
<div class="option-row">
<div>

<input type="hidden"
 name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][options][<?= h($option['id']) ?>][id]"
 value="<?= h($option['id']) ?>">

<input type="text"
 name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][options][<?= h($option['id']) ?>][label]"
 value="<?= h($option['label']) ?>"
 placeholder="選択肢">

<?php if ($question['type'] === 'single'): ?>
<select
 name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][options][<?= h($option['id']) ?>][nextQuestionId]">
<option value="">次の質問を指定しない</option>

<?php foreach (all_questions($survey) as $target): ?>
<?php if ($target['id'] === $question['id']) continue; ?>
<option value="<?= h($target['id']) ?>"
 <?= ($option['nextQuestionId'] ?? '') ===
     $target['id']
     ? 'selected' : '' ?>>
<?= h(
    $target['number'] .
    ' ' .
    $target['text']
) ?>
</option>
<?php endforeach; ?>

</select>
<?php endif; ?>

</div>

<button type="button"
        onclick="removeOption(this)">
削除
</button>
</div>
<?php endforeach; ?>

</div>

<button type="button"
        onclick="addOption(this)">
選択肢を追加
</button>

<?php endif; ?>
</div>

</div>
<?php endforeach; ?>

</div>

<button type="button"
        class="primary"
        onclick="addQuestion(this)">
質問を追加
</button>

</div>
<?php endforeach; ?>

</div>

<button type="button"
        class="primary"
        onclick="addGroup()">
グループを追加
</button>

</form>
</div>

<script>
let uidCounter = 0;

function clientId(prefix) {
    uidCounter++;
    return prefix + '-new-' +
        Date.now().toString(36) + '-' +
        uidCounter.toString(36);
}

function esc(value) {
    return String(value)
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

function getGroupId(group) {
    return group.dataset.groupId;
}

function getQuestionId(question) {
    return question.dataset.questionId;
}

function addGroup() {
    const container =
        document.getElementById('groups-container');

    const gid = clientId('group');

    const div = document.createElement('div');

    div.className = 'group';
    div.draggable = true;
    div.dataset.groupId = gid;

    div.innerHTML = `
        <div class="actions">
            <span class="drag-handle">
                ↕ グループをドラッグして並び替え
            </span>
            <button type="button"
                    class="danger"
                    onclick="removeGroup(this)">
                グループ削除
            </button>
        </div>

        <div class="field">
            <label>グループタイトル</label>
            <input type="hidden"
                   name="groups[${gid}][id]"
                   value="${gid}">
            <input type="text"
                   name="groups[${gid}][title]"
                   value="">
        </div>

        <div class="questions-container"></div>

        <button type="button"
                class="primary"
                onclick="addQuestion(this)">
            質問を追加
        </button>
    `;

    container.appendChild(div);

    recalculateClientNumbers();
    initDragDrop();
}

function removeGroup(button) {
    const group = button.closest('.group');

    if (!group) return;

    if (!confirm(
        'このグループと質問を削除しますか？'
    )) {
        return;
    }

    group.remove();

    recalculateClientNumbers();
}

function addQuestion(button) {
    const group =
        button.closest('.group');

    if (!group) return;

    const container =
        group.querySelector('.questions-container');

    const gid =
        getGroupId(group);

    const qid =
        clientId('question');

    const div =
        document.createElement('div');

    div.className = 'question';
    div.draggable = true;
    div.dataset.questionId = qid;

    div.innerHTML = `
        <div class="actions">
            <span class="drag-handle">
                ↕ 質問をドラッグして並び替え
            </span>
            <span class="question-number"></span>
            <button type="button"
                    class="danger"
                    onclick="removeQuestion(this)">
                質問削除
            </button>
        </div>

        <input type="hidden"
               name="groups[${gid}][questions][${qid}][id]"
               value="${qid}">

        <div class="field">
            <label>質問文</label>
            <textarea
             name="groups[${gid}][questions][${qid}][text]"
             maxlength="<?= MAX_QUESTION ?>"></textarea>
        </div>

        <div class="grid">
            <div class="field">
                <label>回答形式</label>
                <select
                 name="groups[${gid}][questions][${qid}][type]"
                 onchange="questionTypeChanged(this)">
                    <option value="single">
                        単一選択
                    </option>
                    <option value="multiple">
                        複数選択
                    </option>
                    <option value="text">
                        自由記述
                    </option>
                </select>
            </div>

            <div class="field">
                <label>
                    <input type="checkbox"
                     name="groups[${gid}][questions][${qid}][required]"
                     value="1">
                    必須
                </label>
            </div>
        </div>

        <div class="options-area">
            <label>選択肢</label>
            <div class="options-container"></div>
            <button type="button"
                    onclick="addOption(this)">
                選択肢を追加
            </button>
        </div>
    `;

    container.appendChild(div);

    addOption(
        div.querySelector(
            '.options-area button'
        )
    );

    addOption(
        div.querySelector(
            '.options-area button'
        )
    );

    questionTypeChanged(
        div.querySelector('select')
    );

    recalculateClientNumbers();
    initDragDrop();
}

function removeQuestion(button) {
    const q = button.closest('.question');

    if (!q) return;

    if (!confirm('この質問を削除しますか？')) {
        return;
    }

    q.remove();

    recalculateClientNumbers();
}

function addOption(button) {
    const question =
        button.closest('.question');

    if (!question) return;

    const gid =
        getGroupId(
            question.closest('.group')
        );

    const qid =
        getQuestionId(question);

    const type =
        question.querySelector(
            'select[name*="[type]"]'
        ).value;

    if (type === 'text') return;

    const oid =
        clientId('option');

    const row =
        document.createElement('div');

    row.className = 'option-row';

    row.innerHTML = `
        <div>
            <input type="hidden"
             name="groups[${gid}][questions][${qid}][options][${oid}][id]"
             value="${oid}">

            <input type="text"
             name="groups[${gid}][questions][${qid}][options][${oid}][label]"
             value=""
             placeholder="選択肢">

            ${type === 'single' ? `
            <select
             name="groups[${gid}][questions][${qid}][options][${oid}][nextQuestionId]">
                <option value="">
                    次の質問を指定しない
                </option>
            </select>
            ` : ''}
        </div>

        <button type="button"
                onclick="removeOption(this)">
            削除
        </button>
    `;

    question
        .querySelector('.options-container')
        .appendChild(row);

    refreshBranchTargets();
}

function removeOption(button) {
    const row =
        button.closest('.option-row');

    if (row) row.remove();
}

function questionTypeChanged(select) {
    const question =
        select.closest('.question');

    if (!question) return;

    const type = select.value;

    const area =
        question.querySelector('.options-area');

    if (!area) return;

    if (type === 'text') {
        area.innerHTML = '';
        return;
    }

    if (
        !area.querySelector('.options-container')
    ) {
        area.innerHTML = `
            <label>選択肢</label>
            <div class="options-container"></div>
            <button type="button"
                    onclick="addOption(this)">
                選択肢を追加
            </button>
        `;
    }

    const options =
        area.querySelector(
            '.options-container'
        );

    if (options.children.length === 0) {
        addOption(
            area.querySelector('button')
        );
        addOption(
            area.querySelector('button')
        );
    }

    refreshBranchTargets();
}

function refreshBranchTargets() {
    const questions =
        Array.from(
            document.querySelectorAll(
                '.question'
            )
        );

    questions.forEach(question => {
        const qid =
            getQuestionId(question);

        const number =
            question.querySelector(
                '.question-number'
            )?.textContent || '';

        const text =
            question.querySelector(
                'textarea'
            )?.value || '';

        document.querySelectorAll(
            'select[name*="[nextQuestionId]"]'
        ).forEach(select => {
            const current =
                select.value;

            if (
                select.closest('.question')
                === question
            ) {
                /*
                 * 自分自身への分岐は不可。
                 */
            }

            const existing =
                select.querySelectorAll(
                    'option[data-generated="1"]'
                );

            existing.forEach(o => o.remove());

            questions.forEach(target => {
                const targetId =
                    getQuestionId(target);

                if (targetId === qid) {
                    return;
                }

                const option =
                    document.createElement('option');

                option.dataset.generated = '1';
                option.value = targetId;

                const targetNumber =
                    target.querySelector(
                        '.question-number'
                    )?.textContent || '';

                const targetText =
                    target.querySelector(
                        'textarea'
                    )?.value || '';

                option.textContent =
                    targetNumber +
                    ' ' +
                    targetText.substring(0, 60);

                select.appendChild(option);
            });

            select.value = current;
        });
    });
}

function recalculateClientNumbers() {
    const mode =
        document.getElementById(
            'numbering'
        )?.value || 'global';

    let globalNo = 1;
    let groupNo = 1;

    document.querySelectorAll(
        '#groups-container > .group'
    ).forEach(group => {
        let questionNo = 1;

        group.querySelectorAll(
            ':scope > .questions-container > .question'
        ).forEach(question => {
            const label =
                question.querySelector(
                    '.question-number'
                );

            if (!label) return;

            label.textContent =
                mode === 'group'
                    ? 'Q' +
                      groupNo +
                      '-' +
                      questionNo
                    : 'Q' + globalNo;

            globalNo++;
            questionNo++;
        });

        groupNo++;
    });

    refreshBranchTargets();
}

function prepareSurveyForm() {
    recalculateClientNumbers();

    const status =
        document.getElementById(
            'survey-status'
        );

    if (
        status
        && status.value === 'published'
        && !confirm(
            'アンケートを公開中として保存しますか？'
        )
    ) {
        return false;
    }

    return true;
}

function confirmCancel() {
    return confirm(
        '編集内容を破棄して戻りますか？'
    );
}

function initDragDrop() {
    document.querySelectorAll(
        '.group,.question'
    ).forEach(element => {
        if (element.dataset.dragReady === '1') {
            return;
        }

        element.dataset.dragReady = '1';

        element.addEventListener(
            'dragstart',
            function() {
                this.classList.add('dragging');
            }
        );

        element.addEventListener(
            'dragend',
            function() {
                this.classList.remove('dragging');
                recalculateClientNumbers();
            }
        );

        element.addEventListener(
            'dragover',
            function(e) {
                e.preventDefault();

                const dragging =
                    document.querySelector(
                        '.dragging'
                    );

                if (!dragging || dragging === this) {
                    return;
                }

                if (
                    dragging.classList.contains('group')
                    && this.classList.contains('group')
                ) {
                    const container =
                        this.parentElement;

                    const rect =
                        this.getBoundingClientRect();

                    if (
                        e.clientY <
                        rect.top + rect.height / 2
                    ) {
                        container.insertBefore(
                            dragging,
                            this
                        );
                    } else {
                        container.insertBefore(
                            dragging,
                            this.nextSibling
                        );
                    }
                }

                if (
                    dragging.classList.contains('question')
                    && this.classList.contains('question')
                ) {
                    const sourceGroup =
                        dragging.closest('.group');

                    const targetGroup =
                        this.closest('.group');

                    const container =
                        this.closest(
                            '.questions-container'
                        );

                    const rect =
                        this.getBoundingClientRect();

                    if (
                        e.clientY <
                        rect.top + rect.height / 2
                    ) {
                        container.insertBefore(
                            dragging,
                            this
                        );
                    } else {
                        container.insertBefore(
                            dragging,
                            this.nextSibling
                        );
                    }

                    /*
                     * 質問は別グループへも移動可能。
                     * DOM移動後、保存時のnameを再生成する。
                     */
                }
            }
        );
    });
}

function normalizeFormNames() {
    document.querySelectorAll(
        '#groups-container > .group'
    ).forEach(group => {
        const gid =
            getGroupId(group);

        group.querySelectorAll(
            ':scope > input[name$="[id]"]'
        ).forEach(input => {
            input.name =
                `groups[${gid}][id]`;
        });

        const title =
            group.querySelector(
                ':scope > .field input[type="text"]'
            );

        if (title) {
            title.name =
                `groups[${gid}][title]`;
        }

        group.querySelectorAll(
            ':scope > .questions-container > .question'
        ).forEach(question => {
            const qid =
                getQuestionId(question);

            question.querySelectorAll(
                'input,textarea,select'
            ).forEach(input => {
                const name =
                    input.name || '';

                const match =
                    name.match(
                        /\[questions\]\[[^\]]+\](.*)$/
                    );

                if (match) {
                    input.name =
                        `groups[${gid}][questions][${qid}]` +
                        match[1];
                }
            });
        });
    });
}

document.addEventListener(
    'DOMContentLoaded',
    function() {
        initDragDrop();
        recalculateClientNumbers();

        const form =
            document.getElementById(
                'survey-form'
            );

        if (form) {
            form.addEventListener(
                'submit',
                function() {
                    normalizeFormNames();
                }
            );
        }
    }
);
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
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    if (!$survey) {
        render_error(
            '対象アンケートが見つかりません。'
        );
        return;
    }

    recalc_numbers($survey);

    admin_header(
        'アンケートプレビュー'
    );
?>
<div class="card">
<div class="actions">
<a class="btn"
   href="<?= h(app_url([
       'screen' => 'edit',
       'id' => $id,
   ])) ?>">
編集へ戻る
</a>

<a class="btn"
   href="<?= h(app_url([
       'screen' => 'list',
   ])) ?>">
一覧へ
</a>
</div>

<h1><?= h($survey['title']) ?></h1>

<p><?= nl2br(h($survey['description'])) ?></p>

<p class="small">
<?= h($survey['startAt']) ?>
～
<?= h($survey['endAt']) ?>
</p>
</div>

<div class="preview">

<?php foreach ($survey['groups'] as $group): ?>
<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>
<div class="question">
<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<?php if (!empty($question['required'])): ?>
<span class="badge warning">必須</span>
<?php endif; ?>

<?php if ($question['type'] === 'single'): ?>

<?php foreach ($question['options'] as $option): ?>
<div>
<label>
<input type="radio"
       disabled>
<?= h($option['label']) ?>
<?php if (!empty($option['nextQuestionId'])): ?>
<span class="small">
→ 条件分岐あり
</span>
<?php endif; ?>
</label>
</div>
<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>

<?php foreach ($question['options'] as $option): ?>
<div>
<label>
<input type="checkbox"
       disabled>
<?= h($option['label']) ?>
</label>
</div>
<?php endforeach; ?>

<?php else: ?>

<textarea disabled></textarea>

<?php endif; ?>
</div>
<?php endforeach; ?>

<?php endforeach; ?>

</div>
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
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    if (!$survey) {
        render_error(
            '送信対象のアンケートが見つかりません。'
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
<div class="actions">
<a class="btn"
   href="<?= h(app_url([
       'screen' => 'list',
   ])) ?>">
一覧へ
</a>

<a class="btn"
   href="<?= h(app_url([
       'screen' => 'analytics',
       'id' => $id,
   ])) ?>">
集計
</a>
</div>

<h1>顧客選択・メール送信</h1>

<p>
対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>
</div>

<div class="card">
<h2>メール作成</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="send_bulk_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<div class="field">
<label>送信種別</label>
<select name="send_type">
<option value="send">初回送信</option>
<option value="remind">リマインド</option>
<option value="resend">再送</option>
</select>
</div>

<div class="field">
<label>件名</label>
<input type="text"
       name="subject"
       value="<?= h(
           $survey['title'] . 'のご案内'
       ) ?>"
       required>
</div>

<div class="field">
<label>本文</label>
<textarea name="body"
          required> {顧客名} 様

アンケートへのご協力をお願いいたします。

回答URL：
{アンケートURL}</textarea>
</div>

<h3>顧客選択</h3>

<?php if ($data['customers'] === []): ?>
<div class="empty">
同期済み顧客がありません。
<a href="<?= h(
    app_url(['screen'=>'kintone'])
) ?>">
kintone設定
</a>
から顧客情報を同期してください。
</div>
<?php else: ?>

<div class="actions">
<button type="button"
        onclick="toggleAllCustomers(true)">
全選択
</button>
<button type="button"
        onclick="toggleAllCustomers(false)">
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

<?php foreach ($data['customers'] as $customer): ?>
<?php if (!is_array($customer)) continue; ?>
<tr>
<td>
<input type="checkbox"
       class="customer-check"
       name="customer_ids[]"
       value="<?= h($customer['id'] ?? '') ?>">
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

<button class="primary"
        onclick="return confirm('選択した顧客へ送信しますか？')">
一括送信
</button>

<?php endif; ?>

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
<th>種別</th>
<th>結果</th>
</tr>
</thead>
<tbody>

<?php
$history = [];

foreach ($data['send_history'] as $item) {
    if (
        is_array($item)
        && (string)($item['surveyId'] ?? '') === $id
    ) {
        $history[] = $item;
    }
}

usort(
    $history,
    static fn(array $a, array $b): int =>
        strcmp(
            (string)($b['sentAt'] ?? ''),
            (string)($a['sentAt'] ?? '')
        )
);
?>

<?php if ($history === []): ?>
<tr>
<td colspan="4" class="empty">
送信履歴はありません。
</td>
</tr>
<?php endif; ?>

<?php foreach ($history as $item): ?>
<tr>
<td><?= h($item['sentAt'] ?? '') ?></td>
<td><?= h($item['email'] ?? '') ?></td>
<td><?= h($item['type'] ?? '') ?></td>
<td><?= h($item['status'] ?? '') ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>
</div>

<script>
function toggleAllCustomers(value) {
    document.querySelectorAll(
        '.customer-check'
    ).forEach(function(el) {
        el.checked = value;
    });
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
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    if (!$survey) {
        render_error(
            '集計対象のアンケートが見つかりません。'
        );
        return;
    }

    $answers = [];

    foreach ($data['answers'] as $answer) {
        if (
            is_array($answer)
            && (string)($answer['surveyId'] ?? '') === $id
        ) {
            $answers[] = $answer;
        }
    }

    $sentCustomers = [];

    foreach ($data['send_history'] as $item) {
        if (
            is_array($item)
            && (string)($item['surveyId'] ?? '') === $id
            && ($item['status'] ?? '') === 'sent'
        ) {
            $sentCustomers[
                (string)($item['customerId'] ?? '')
            ] = true;
        }
    }

    $sentCount = count($sentCustomers);
    $answerCount = count($answers);
    $registered = 0;

    $customerIds = [];

    foreach ($data['customers'] as $customer) {
        if (is_array($customer)) {
            $customerIds[
                (string)($customer['id'] ?? '')
            ] = true;
        }
    }

    foreach ($answers as $answer) {
        if (
            isset(
                $answer['customerId']
            )
        ) {
            if (
                isset(
                    $customerIds[
                        (string)$answer['customerId']
                    ]
                )
            ) {
                $registered++;
            }
        }
    }

    $unregistered =
        $answerCount - $registered;

    $unanswered =
        max(
            0,
            $sentCount - $answerCount
        );

    $rate =
        $sentCount > 0
            ? round(
                $answerCount /
                $sentCount *
                100,
                1
            )
            : 0;

    $flash = flash_get();

    admin_header(
        '回答集計・分析',
        $flash
    );
?>
<div class="card">
<div class="actions">
<a class="btn"
   href="<?= h(app_url([
       'screen' => 'list',
   ])) ?>">
一覧へ
</a>

<a class="btn"
   href="<?= h(app_url([
       'screen' => 'send',
       'id' => $id,
   ])) ?>">
送信
</a>
</div>

<h1>回答集計・分析</h1>

<p>
対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>

<div class="grid">

<div class="card">
<strong>送信対象者数</strong>
<h2><?= h($sentCount) ?></h2>
</div>

<div class="card">
<strong>回答数</strong>
<h2><?= h($answerCount) ?></h2>
</div>

<div class="card">
<strong>未登録回答数</strong>
<h2><?= h($unregistered) ?></h2>
</div>

<div class="card">
<strong>未回答数</strong>
<h2><?= h($unanswered) ?></h2>
</div>

<div class="card">
<strong>回答率</strong>
<h2><?= h($rate) ?>%</h2>
</div>

</div>
</div>

<div class="card">
<h2>設問別集計</h2>

<?php if ($answerCount === 0): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach (all_questions($survey) as $question): ?>

<div class="question">

<h3>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</h3>

<?php if (
    in_array(
        $question['type'],
        ['single', 'multiple'],
        true
    )
): ?>

<?php
$counts = [];

foreach ($question['options'] as $option) {
    $counts[
        (string)$option['id']
    ] = 0;
}

foreach ($answers as $answer) {
    $values =
        $answer['answers'][
            $question['id']
        ] ?? '';

    if (is_array($values)) {
        foreach ($values as $value) {
            $value = (string)$value;

            if (isset($counts[$value])) {
                $counts[$value]++;
            }
        }
    } else {
        $value = (string)$values;

        if (isset($counts[$value])) {
            $counts[$value]++;
        }
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
<?php foreach ($question['options'] as $option): ?>
<tr>
<td><?= h($option['label']) ?></td>
<td><?= h(
    $counts[$option['id']] ?? 0
) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php else: ?>

<p>
自由記述回答：
<?=
h(
    array_sum(
        array_map(
            static function(array $answer) use ($question): int {
                $v =
                    $answer['answers'][
                        $question['id']
                    ] ?? '';

                return $v !== '' ? 1 : 0;
            },
            $answers
        )
    )
)
?>件
</p>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>
</div>

<div class="card">
<h2>個別回答</h2>

<?php if ($answerCount === 0): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach ($answers as $answer): ?>
<div class="question">

<p class="small">
<?= h($answer['createdAt'] ?? '') ?>
</p>

<?php
$answerValues =
    $answer['answers'] ?? [];
?>

<?php foreach (all_questions($survey) as $question): ?>

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<p>
<?php
$value =
    $answerValues[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $labels = [];

    foreach ($question['options'] as $option) {
        if (
            in_array(
                $option['id'],
                $value,
                true
            )
        ) {
            $labels[] = $option['label'];
        }
    }

    echo h(
        implode(', ', $labels)
    );
} else {
    $label = $value;

    foreach ($question['options'] as $option) {
        if (
            (string)$option['id'] ===
            (string)$value
        ) {
            $label = $option['label'];
            break;
        }
    }

    echo nl2br(
        h((string)$label)
    );
}
?>
</p>

<?php endforeach; ?>

</div>
<?php endforeach; ?>

<?php endif; ?>
</div>
<?php
    admin_footer();
}

/* =========================================================
 * 回答者
 * ========================================================= */

function render_answer(
    array $data,
    string $id
): void {
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    if (!$survey) {
        render_answer_message(
            'アンケートを表示できません。'
        );
        return;
    }

    if (
        ($survey['status'] ?? '') !== 'published'
    ) {
        render_answer_message(
            'このアンケートは現在回答できません。'
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

    $visible =
        visible_questions(
            $survey,
            $answers
        );

    $errors = flash_get();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($survey['title']) ?></title>
<style>
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.wrap{
 max-width:720px;
 margin:auto;
 padding:20px 14px 60px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:24px;
 margin-bottom:18px;
}
.question{
 margin:24px 0;
}
.option{
 display:block;
 padding:13px;
 margin:8px 0;
 border:1px solid #cbd5e1;
 border-radius:10px;
}
input[type=text],
textarea{
 width:100%;
 padding:12px;
 font:inherit;
 border:1px solid #cbd5e1;
 border-radius:8px;
}
textarea{
 min-height:150px;
}
button{
 border:0;
 border-radius:8px;
 padding:12px 20px;
 background:#2563eb;
 color:#fff;
 font:inherit;
 cursor:pointer;
}
.error{
 padding:12px;
 background:#fee2e2;
 color:#991b1b;
 border-radius:8px;
 white-space:pre-line;
}
</style>
</head>
<body>
<div class="wrap">

<div class="card">
<h1><?= h($survey['title']) ?></h1>
<p><?= nl2br(h($survey['description'])) ?></p>
</div>

<?php if ($errors): ?>
<div class="error">
<?= nl2br(h($errors['message'] ?? '')) ?>
</div>
<?php endif; ?>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="answer_next">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<?php foreach ($visible as $question): ?>

<div class="question">

<h2>
<?= h($question['number']) ?>
</h2>

<p>
<strong><?= h($question['text']) ?></strong>

<?php if (!empty($question['required'])): ?>
<span>（必須）</span>
<?php endif; ?>
</p>

<?php
$value =
    $answers[
        $question['id']
    ] ?? '';
?>

<?php if ($question['type'] === 'single'): ?>

<?php foreach ($question['options'] as $option): ?>
<label class="option">
<input type="radio"
       name="answers[<?= h($question['id']) ?>]"
       value="<?= h($option['id']) ?>"
 <?= (string)$value ===
     (string)$option['id']
     ? 'checked' : '' ?>>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>

<?php foreach ($question['options'] as $option): ?>
<label class="option">
<input type="checkbox"
       name="answers[<?= h($question['id']) ?>][]"
       value="<?= h($option['id']) ?>"
 <?= is_array($value)
     && in_array(
         $option['id'],
         $value,
         true
     )
     ? 'checked' : '' ?>>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php else: ?>

<textarea
 name="answers[<?= h($question['id']) ?>]"
><?= h((string)$value) ?></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

<button type="submit">
回答確認へ
</button>

</form>
</div>
</div>
</body>
</html>
<?php
}

function render_answer_confirm(
    array $data,
    string $id
): void {
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    if (!$survey) {
        render_answer_message(
            'アンケートを表示できません。'
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

    $visible =
        visible_questions(
            $survey,
            $answers
        );
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
 color:#1e293b;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.wrap{
 max-width:720px;
 margin:auto;
 padding:20px 14px 60px;
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
 flex-wrap:wrap;
 gap:10px;
}
button{
 padding:12px 20px;
 border-radius:8px;
 border:1px solid #cbd5e1;
 background:#fff;
 font:inherit;
}
.primary{
 background:#2563eb;
 border-color:#2563eb;
 color:#fff;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">

<h1>回答確認</h1>

<?php foreach ($visible as $question): ?>
<div>
<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<p>
<?php
$value =
    $answers[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $labels = [];

    foreach ($question['options'] as $option) {
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

    echo h(
        implode(', ', $labels)
    );
} else {
    $label = $value;

    foreach ($question['options'] as $option) {
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
</p>
<hr>
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
 font-family:-apple-system,BlinkMacSystemFont,
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
 font-family:-apple-system,BlinkMacSystemFont,
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
<p><?= nl2br(h($message)) ?></p>
</div>
</div>
</body>
</html>
<?php
}

/* =========================================================
 * エラー
 * ========================================================= */

function render_error(string $message): void
{
    admin_header('エラー');

?>
<div class="card">
<h1>エラー</h1>
<p><?= nl2br(h($message)) ?></p>

<div class="actions">
<a class="btn"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
アンケート一覧へ
</a>
</div>
</div>
<?php

    admin_footer();
}

/* =========================================================
 * 起動
 * ========================================================= */

try {

    /*
     * データ保存領域をアプリ起動時に確実に用意する。
     */
    if (!is_dir(DATA_DIR)) {
        if (
            !@mkdir(
                DATA_DIR,
                0775,
                true
            )
            && !is_dir(DATA_DIR)
        ) {
            throw new RuntimeException(
                'データ保存フォルダを作成できません。'
            );
        }
    }

    if (!is_writable(DATA_DIR)) {
        throw new RuntimeException(
            'データ保存フォルダにPHPから書き込めません。'
        );
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

    $data = load_data();
    $settings = load_settings();

    refresh_status($data);

    $route = [
        'screen' =>
            get_string('screen') !== ''
                ? get_string('screen')
                : 'list',
        'id' => get_string('id'),
    ];

    /*
     * POST処理を先に確定する。
     * 外部通信処理自身からリダイレクトしない。
     */
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        === 'POST'
    ) {
        $route = handle_post(
            $data,
            $settings
        );
    }

    $screen =
        (string)($route['screen'] ?? 'list');

    $id =
        (string)($route['id'] ?? '');

    /*
     * 回答者画面は管理者画面と完全に分離。
     */
    if ($screen === 'answer') {
        render_answer(
            $data,
            $id
        );
        exit;
    }

    if ($screen === 'answer_confirm') {
        render_answer_confirm(
            $data,
            $id
        );
        exit;
    }

    if ($screen === 'complete') {
        render_complete(
            $data,
            $id
        );
        exit;
    }

    if ($screen === 'answer_message') {
        render_answer_message(
            'アンケートを表示できません。'
        );
        exit;
    }

    /*
     * 管理者画面。
     */
    switch ($screen) {

        case 'list':
            render_list($data);
            break;

        case 'edit':
            render_edit(
                $data,
                $id !== '' ? $id : null
            );
            break;

        case 'preview':
            if ($id === '') {
                render_error(
                    'プレビュー対象のアンケートIDが指定されていません。'
                );
                break;
            }

            render_preview(
                $data,
                $id
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

    http_response_code(500);

    $message = safe_error($e);

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
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.card{
 max-width:850px;
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
.actions{
 margin-top:20px;
}
a{
 display:inline-block;
 padding:10px 15px;
 border-radius:8px;
 background:#2563eb;
 color:#fff;
 text-decoration:none;
}
</style>
</head>
<body>
<div class="card">
<h1>システムエラー</h1>
<p class="error"><?= nl2br(h($message)) ?></p>
<p>
データ保存領域、PHPのファイル権限、外部サービス設定を確認してください。
</p>
<div class="actions">
<a href="<?= h(app_url(['screen'=>'list'])) ?>">
アンケート一覧へ
</a>
</div>
</div>
</body>
</html>
<?php
}

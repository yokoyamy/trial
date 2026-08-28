<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし
 * PHP cURLなし
 * PHP mail()なし
 *
 * 単一エントリーポイント:
 *   index.php?screen=list
 *   index.php?screen=edit&id=survey-001
 *   index.php?screen=preview&id=survey-001
 *   index.php?screen=send&id=survey-001
 *   index.php?screen=analytics&id=survey-001
 *   index.php?screen=kintone
 *   index.php?screen=mail
 *   index.php?screen=answer&id=survey-001
 *   index.php?screen=confirm&id=survey-001
 *   index.php?screen=complete&id=survey-001
 *
 * 外部通信:
 * - kintone: PHP stream
 * - SMTP: PHP stream_socket_client
 *
 * 禁止事項:
 * - DB
 * - cURL
 * - mail()
 * - kintone API token
 * - 管理者認証
 * - 送信履歴専用画面
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';

const DATA_DIR      = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE     = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const HTTP_CONNECT_TIMEOUT = 10;
const HTTP_READ_TIMEOUT    = 30;

const MAX_TITLE       = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION    = 1000;
const MAX_OPTION      = 500;
const MAX_MAIL_BODY   = 20000;

const KINTONE_RECORD_LIMIT = 500;
const KINTONE_MAX_OFFSET   = 10000;

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

function post_string(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function get_string(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function post_bool(string $key): bool
{
    return isset($_POST[$key])
        && in_array(
            (string)$_POST[$key],
            ['1', 'on', 'true'],
            true
        );
}

function uuid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function app_url(array $params = []): string
{
    $script = str_replace(
        '\\',
        '/',
        (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
    );

    if (!$params) {
        return $script;
    }

    return $script . '?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function public_answer_url(string $surveyId): string
{
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    $scheme = $https ? 'https' : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    return $scheme
        . '://'
        . $host
        . app_url([
            'screen' => 'answer',
            'id'     => $surveyId,
        ]);
}

function selected(bool $condition): string
{
    return $condition ? ' selected' : '';
}

function checked(bool $condition): string
{
    return $condition ? ' checked' : '';
}

function scalar_string(mixed $value): string
{
    return is_scalar($value)
        ? trim((string)$value)
        : '';
}

/* =========================================================
 * セッション
 * ========================================================= */

function cookie_path(): string
{
    $script = str_replace(
        '\\',
        '/',
        (string)(
            $_SERVER['SCRIPT_NAME'] ?? '/index.php'
        )
    );

    $dir = dirname($script);

    if (
        $dir === '.'
        || $dir === '/'
        || $dir === '\\'
    ) {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

function start_app(): void
{
    if (!is_dir(DATA_DIR)) {
        if (
            !mkdir(DATA_DIR, 0775, true)
            && !is_dir(DATA_DIR)
        ) {
            throw new RuntimeException(
                'データ保存フォルダを作成できません。'
            );
        }
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, default_data());
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json(SETTINGS_FILE, default_settings());
    }

    if (
        session_status()
        !== PHP_SESSION_ACTIVE
    ) {
        $https =
            (!empty($_SERVER['HTTPS'])
                && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

        session_name('survey_app_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => cookie_path(),
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException(
                'セッションを開始できません。'
            );
        }
    }
}

/* =========================================================
 * 初期データ
 * ========================================================= */

function default_data(): array
{
    $now = date('Y-m-d H:i:s');

    return [
        'surveys' => [
            [
                'id'          => 'survey-001',
                'title'       => '顧客満足度アンケート',
                'description' => 'サービスについてのご意見をお聞かせください。',
                'startAt'     => date('Y-m-d\TH:i'),
                'endAt'       => date(
                    'Y-m-d\TH:i',
                    strtotime('+30 days')
                ),
                'status'      => 'draft',
                'numbering'   => 'global',
                'createdAt'   => $now,
                'updatedAt'   => $now,
                'groups'      => [
                    [
                        'id'    => 'group-001',
                        'title' => '基本アンケート',
                        'questions' => [
                            [
                                'id'       => 'question-001',
                                'number'   => 'Q1',
                                'text'     => 'サービスの満足度を教えてください。',
                                'type'     => 'single',
                                'required' => true,
                                'options'  => [
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
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'answers'      => [],
        'customers'    => [],
        'send_history' => [],
    ];
}

function default_settings(): array
{
    return [
        'kintone' => [
            'subdomain'  => '',
            'app_id'     => '',
            'username'   => '',
            'password'   => '',
            'proxy'      => '',
            'verify_ssl' => false,
            'mapping'    => [
                'organization' => '',
                'name'         => '',
                'email'        => '',
                'department'   => '',
                'phone'        => '',
                'address'      => [],
            ],
            'fields'      => [],
            'last_test'   => null,
            'last_sync'   => null,
        ],
        'mail' => [
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls',
            'auth'       => true,
            'username'   => '',
            'password'   => '',
            'from_email' => '',
            'from_name'  => '',
            'reply_to'   => '',
            'last_test'  => null,
        ],
    ];
}

/* =========================================================
 * JSON
 * ========================================================= */

function load_json(
    string $file,
    array $fallback
): array {
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

        if (
            $raw === false
            || trim($raw) === ''
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
    } catch (Throwable) {
        @fclose($fp);
        return $fallback;
    }
}

function save_json(
    string $file,
    array $data
): void {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException(
            'データのJSON化に失敗しました。'
        );
    }

    $tmp =
        $file
        . '.tmp.'
        . bin2hex(random_bytes(8));

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

        $written = fwrite(
            $fp,
            $json
        );

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
            !isset($data[$key])
            || !is_array($data[$key])
        ) {
            $data[$key] = [];
        }
    }

    return $data;
}

function save_data(array $data): void
{
    save_json(
        DATA_FILE,
        $data
    );
}

function load_settings(): array
{
    $default = default_settings();

    $settings = load_json(
        SETTINGS_FILE,
        $default
    );

    $settings['kintone'] =
        array_replace_recursive(
            $default['kintone'],
            is_array($settings['kintone'] ?? null)
                ? $settings['kintone']
                : []
        );

    $settings['mail'] =
        array_replace_recursive(
            $default['mail'],
            is_array($settings['mail'] ?? null)
                ? $settings['mail']
                : []
        );

    return $settings;
}

function save_settings(array $settings): void
{
    save_json(
        SETTINGS_FILE,
        $settings
    );
}

/* =========================================================
 * パスワード暗号化
 *
 * APP_ENCRYPTION_KEY 環境変数を優先。
 * 未設定の場合はアプリ親ディレクトリの秘密鍵ファイルを使用。
 * 平文保存は行わない。
 * ========================================================= */

function encryption_key(): string
{
    $env = getenv('APP_ENCRYPTION_KEY');

    if (
        is_string($env)
        && trim($env) !== ''
    ) {
        return hash(
            'sha256',
            $env,
            true
        );
    }

    $keyFile =
        dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '.survey_app_key';

    if (!is_file($keyFile)) {
        $key = random_bytes(32);

        if (
            @file_put_contents(
                $keyFile,
                base64_encode($key),
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'パスワード暗号化キーを保存できません。APP_ENCRYPTION_KEYを環境変数へ設定してください。'
            );
        }

        @chmod(
            $keyFile,
            0600
        );
    }

    $raw = @file_get_contents($keyFile);

    if (
        $raw === false
        || trim($raw) === ''
    ) {
        throw new RuntimeException(
            'パスワード暗号化キーを読み込めません。'
        );
    }

    $decoded = base64_decode(
        trim($raw),
        true
    );

    if (
        $decoded === false
        || strlen($decoded) < 32
    ) {
        throw new RuntimeException(
            'パスワード暗号化キーが不正です。'
        );
    }

    return substr(
        $decoded,
        0,
        32
    );
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $key = encryption_key();

    $iv = random_bytes(
        openssl_cipher_iv_length('aes-256-gcm')
    );

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

    return 'enc:v1:'
        . base64_encode($iv)
        . ':'
        . base64_encode($tag)
        . ':'
        . base64_encode($cipher);
}

function decrypt_secret(string $stored): string
{
    if ($stored === '') {
        return '';
    }

    /*
     * 旧版で平文保存された値については、
     * 読み出しは互換目的で許可する。
     * 次回保存時には必ず暗号化する。
     */
    if (!str_starts_with($stored, 'enc:v1:')) {
        return $stored;
    }

    $parts = explode(
        ':',
        $stored
    );

    if (count($parts) !== 5) {
        throw new RuntimeException(
            '保存済み機密情報の形式が不正です。'
        );
    }

    $iv = base64_decode(
        $parts[2],
        true
    );

    $tag = base64_decode(
        $parts[3],
        true
    );

    $cipher = base64_decode(
        $parts[4],
        true
    );

    if (
        $iv === false
        || $tag === false
        || $cipher === false
    ) {
        throw new RuntimeException(
            '保存済み機密情報を復号できません。'
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
            '保存済み機密情報を復号できません。'
        );
    }

    return $plain;
}

/* =========================================================
 * Flash
 * ========================================================= */

function flash(
    string $type,
    string $message
): void {
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $v = $_SESSION['flash'] ?? null;

    unset($_SESSION['flash']);

    return is_array($v)
        ? $v
        : null;
}

/* =========================================================
 * アンケート
 * ========================================================= */

function survey_index(
    array $surveys,
    string $id
): int {
    foreach (
        $surveys as $i => $survey
    ) {
        if (
            (string)($survey['id'] ?? '')
            === $id
        ) {
            return $i;
        }
    }

    return -1;
}

function survey_by_id(
    array $surveys,
    string $id
): ?array {
    $i = survey_index(
        $surveys,
        $id
    );

    return $i >= 0
        ? $surveys[$i]
        : null;
}

function auto_update_status(
    array &$survey
): bool {
    if (
        ($survey['status'] ?? '')
            === 'published'
        && !empty($survey['endAt'])
        && strtotime(
            (string)$survey['endAt']
        ) !== false
        && strtotime(
            (string)$survey['endAt']
        ) < time()
    ) {
        $survey['status'] = 'ended';
        $survey['updatedAt'] =
            date('Y-m-d H:i:s');

        return true;
    }

    return false;
}

function refresh_statuses(
    array &$data
): bool {
    $changed = false;

    foreach (
        $data['surveys'] as &$survey
    ) {
        if (
            auto_update_status($survey)
        ) {
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function recalc_numbers(
    array &$survey
): void {
    $global = 1;
    $groupNo = 1;

    foreach (
        $survey['groups'] as &$group
    ) {
        $questionNo = 1;

        foreach (
            $group['questions'] as &$question
        ) {
            if (
                ($survey['numbering'] ?? 'global')
                === 'group'
            ) {
                $question['number'] =
                    'Q'
                    . $groupNo
                    . '-'
                    . $questionNo;
            } else {
                $question['number'] =
                    'Q'
                    . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
}

function status_label(
    string $status
): string {
    return match ($status) {
        'published' => '公開中',
        'stopped'   => '停止',
        'ended'     => '終了',
        default     => '下書き',
    };
}

function status_class(
    string $status
): string {
    return match ($status) {
        'published' => 'success',
        'stopped'   => 'warning',
        default     => 'gray',
    };
}

function question_type_label(
    string $type
): string {
    return match ($type) {
        'single'   => '単一選択',
        'multiple' => '複数選択',
        'text'     => '自由記述',
        default    => '質問',
    };
}

function all_questions(
    array $survey
): array {
    $result = [];

    foreach (
        $survey['groups'] ?? []
        as $group
    ) {
        foreach (
            $group['questions'] ?? []
            as $question
        ) {
            $result[] = $question;
        }
    }

    return $result;
}

function question_by_id(
    array $survey,
    string $questionId
): ?array {
    foreach (
        $survey['groups'] ?? []
        as $group
    ) {
        foreach (
            $group['questions'] ?? []
            as $question
        ) {
            if (
                (string)($question['id'] ?? '')
                === $questionId
            ) {
                return $question;
            }
        }
    }

    return null;
}

function question_is_visible(
    array $survey,
    array $answers,
    string $questionId
): bool {
    $questions = all_questions($survey);

    $targetIndex = -1;

    foreach (
        $questions as $i => $question
    ) {
        if (
            (string)($question['id'] ?? '')
            === $questionId
        ) {
            $targetIndex = $i;
            break;
        }
    }

    if ($targetIndex <= 0) {
        return true;
    }

    /*
     * 前問までを確認し、
     * 選択肢のnextQuestionIdが設定されている場合は
     * 指定された質問だけを次に表示する。
     */
    foreach (
        array_slice(
            $questions,
            0,
            $targetIndex
        ) as $previous
    ) {
        if (
            ($previous['type'] ?? '')
            !== 'single'
        ) {
            continue;
        }

        $answer =
            $answers[$previous['id']]
            ?? null;

        if (
            is_array($answer)
            || $answer === null
            || $answer === ''
        ) {
            continue;
        }

        foreach (
            $previous['options'] ?? []
            as $option
        ) {
            if (
                (string)($option['label'] ?? '')
                === (string)$answer
            ) {
                $next =
                    (string)(
                        $option['nextQuestionId']
                        ?? ''
                    );

                if (
                    $next !== ''
                    && $next !== $questionId
                ) {
                    /*
                     * 指定先より前の質問は表示可能。
                     * 指定先より後ろで、かつ指定先でない質問は
                     * falseとする。
                     */
                    $nextIndex = -1;

                    foreach (
                        $questions as $j => $q
                    ) {
                        if (
                            (string)($q['id'] ?? '')
                            === $next
                        ) {
                            $nextIndex = $j;
                            break;
                        }
                    }

                    if (
                        $nextIndex >= 0
                        && $targetIndex > $nextIndex
                    ) {
                        return false;
                    }
                }
            }
        }
    }

    return true;
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

    $value = rtrim(
        $value,
        '/'
    );

    $suffix = '.cybozu.com';

    if (
        str_ends_with(
            strtolower($value),
            $suffix
        )
    ) {
        $value = substr(
            $value,
            0,
            -strlen($suffix)
        );
    }

    return $value;
}

function validate_kintone_config(
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
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $subdomain
        )
    ) {
        $errors[] =
            'kintoneサブドメインが不正です。';
    }

    $appId =
        (string)($config['app_id'] ?? '');

    if (
        !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] =
            'kintoneアプリIDが不正です。';
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
        $requirePassword
        && trim(
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
        $proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        $errors[] =
            'Proxyはhost:port形式で入力してください。';
    }

    return $errors;
}

function kintone_config_runtime(
    array $stored
): array {
    $config = $stored;

    $config['password'] =
        decrypt_secret(
            (string)(
                $stored['password'] ?? ''
            )
        );

    return $config;
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $errors =
        validate_kintone_config(
            $config,
            true
        );

    if ($errors) {
        throw new RuntimeException(
            implode(
                "\n",
                $errors
            )
        );
    }

    $subdomain =
        normalize_kintone_subdomain(
            (string)$config['subdomain']
        );

    $url =
        'https://'
        . $subdomain
        . '.cybozu.com'
        . $path;

    $authorization =
        base64_encode(
            (string)$config['username']
            . ':'
            . (string)$config['password']
        );

    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,
        'Accept: application/json',
        'Connection: close',
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException(
                'kintoneリクエスト生成に失敗しました。'
            );
        }

        $headers[] =
            'Content-Type: application/json';

        $headers[] =
            'Content-Length: '
            . strlen($content);
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
            'content' =>
                $content,
            'ignore_errors' =>
                true,
            'timeout' =>
                HTTP_READ_TIMEOUT,
            'follow_location' =>
                0,
            'max_redirects' =>
                0,
        ],
        'ssl' => [
            'verify_peer' =>
                $verify,
            'verify_peer_name' =>
                $verify,
            'allow_self_signed' =>
                !$verify,
            'SNI_enabled' =>
                true,
            'peer_name' =>
                $subdomain . '.cybozu.com',
        ],
    ];

    $proxy =
        trim(
            (string)(
                $config['proxy'] ?? ''
            )
        );

    if ($proxy !== '') {
        [$proxyHost, $proxyPort] =
            explode(
                ':',
                $proxy,
                2
            );

        $options['http']['proxy'] =
            'tcp://'
            . $proxyHost
            . ':'
            . (int)$proxyPort;

        $options['http']
            ['request_fulluri'] = true;
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
        $http_response_header ?? []
        as $header
    ) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    if ($response === false) {
        throw new RuntimeException(
            $status === 0
                ? 'kintoneからレスポンスを取得できませんでした。DNS、ネットワーク、Proxy、SSL設定を確認してください。'
                : 'kintone通信に失敗しました。HTTP '
                    . $status
        );
    }

    if ($status === 0) {
        throw new RuntimeException(
            'kintoneからHTTPステータスを取得できませんでした。'
        );
    }

    $json =
        json_decode(
            $response,
            true
        );

    if (
        $status === 302
        || $status === 303
    ) {
        throw new RuntimeException(
            'kintoneがリダイレクト応答 '
            . $status
            . ' を返しました。'
            . ' API URL・認証方式・ネットワーク設定を確認してください。'
        );
    }

    if (
        $status < 200
        || $status >= 300
    ) {
        $code =
            is_array($json)
                ? (string)(
                    $json['code'] ?? ''
                )
                : '';

        $message =
            is_array($json)
                ? (string)(
                    $json['message'] ?? ''
                )
                : '';

        $detail =
            'kintone APIエラー';

        if ($code !== '') {
            $detail .=
                ' [' . $code . ']';
        }

        if ($message !== '') {
            $detail .=
                ' ' . $message;
        }

        $detail .=
            ' HTTP ' . $status;

        throw new RuntimeException(
            $detail
        );
    }

    if (
        !is_array($json)
    ) {
        throw new RuntimeException(
            'kintoneのレスポンスをJSONとして解釈できませんでした。'
        );
    }

    return [
        'status' => $status,
        'body'   => $json,
        'raw'    => $response,
    ];
}

function kintone_test(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id='
        . rawurlencode(
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
        '/k/v1/app/form/fields.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
        . '&lang=ja'
    );
}

function kintone_records_page(
    array $config,
    int $offset
): array {
    $path =
        '/k/v1/records.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
        . '&totalCount=true'
        . '&query='
        . rawurlencode(
            'order by $id asc limit '
            . KINTONE_RECORD_LIMIT
            . ' offset '
            . $offset
        );

    return kintone_request(
        $config,
        'GET',
        $path
    );
}

function kintone_records(
    array $config
): array {
    $all = [];
    $offset = 0;
    $total = null;

    while (true) {
        $result =
            kintone_records_page(
                $config,
                $offset
            );

        $body =
            $result['body'];

        $records =
            is_array(
                $body['records'] ?? null
            )
                ? $body['records']
                : [];

        foreach (
            $records as $record
        ) {
            if (is_array($record)) {
                $all[] = $record;
            }
        }

        if (
            isset($body['totalCount'])
            && $body['totalCount'] !== null
        ) {
            $total =
                (int)$body['totalCount'];
        }

        if (
            count($records)
            < KINTONE_RECORD_LIMIT
        ) {
            break;
        }

        $offset +=
            KINTONE_RECORD_LIMIT;

        /*
         * kintone offset方式には上限がある。
         * POCでは超過時に明示的にエラーとする。
         */
        if (
            $offset > KINTONE_MAX_OFFSET
        ) {
            throw new RuntimeException(
                'kintoneのレコード件数が多すぎるため、同期できません。'
                . ' 10000件以下に絞り込んでください。'
            );
        }
    }

    return [
        'status' => 200,
        'body' => [
            'records' =>
                $all,
            'totalCount' =>
                $total === null
                    ? (string)count($all)
                    : (string)$total,
        ],
        'raw' => '',
    ];
}

/* =========================================================
 * kintone項目
 * ========================================================= */

function flatten_kintone_fields(
    array $properties
): array {
    $fields = [];

    foreach (
        $properties as $code => $property
    ) {
        if (!is_array($property)) {
            continue;
        }

        $type =
            (string)(
                $property['type'] ?? ''
            );

        if (
            in_array(
                $type,
                [
                    'GROUP',
                    'REFERENCE_TABLE',
                    'STATUS',
                    'STATUS_ASSIGNEE',
                    'CATEGORY',
                ],
                true
            )
        ) {
            continue;
        }

        $fields[] = [
            'code' =>
                (string)(
                    $property['code']
                    ?? $code
                ),
            'label' =>
                (string)(
                    $property['label']
                    ?? $code
                ),
            'type' =>
                $type,
        ];

        if (
            $type === 'SUBTABLE'
            && is_array(
                $property['fields'] ?? null
            )
        ) {
            foreach (
                flatten_kintone_fields(
                    $property['fields']
                ) as $child
            ) {
                $child['code'] =
                    (string)(
                        $code
                    )
                    . '.'
                    . (string)$child['code'];

                $fields[] = $child;
            }
        }
    }

    return $fields;
}

function normalize_mapping(
    array $mapping,
    array $availableFields
): array {
    $codes = [];

    foreach (
        $availableFields as $field
    ) {
        $codes[] =
            (string)($field['code'] ?? '');
    }

    $singleKeys = [
        'organization',
        'name',
        'email',
        'department',
        'phone',
    ];

    foreach (
        $singleKeys as $key
    ) {
        $value =
            (string)(
                $mapping[$key] ?? ''
            );

        if (
            $value !== ''
            && !in_array(
                $value,
                $codes,
                true
            )
        ) {
            $mapping[$key] = '';
        }
    }

    $address =
        is_array(
            $mapping['address'] ?? null
        )
            ? $mapping['address']
            : [];

    $mapping['address'] = array_values(
        array_filter(
            array_map(
                'strval',
                $address
            ),
            static fn(string $v): bool =>
                $v !== ''
                && in_array(
                    $v,
                    $codes,
                    true
                )
        )
    );

    return $mapping;
}

function kintone_record_value(
    array $record,
    string $code
): mixed {
    if (
        $code === ''
    ) {
        return '';
    }

    if (
        isset($record[$code])
        && is_array($record[$code])
    ) {
        return $record[$code]['value']
            ?? '';
    }

    /*
     * サブテーブル指定:
     * tableCode.childCode
     */
    if (
        str_contains(
            $code,
            '.'
        )
    ) {
        [$table, $child] =
            explode(
                '.',
                $code,
                2
            );

        $tableValue =
            $record[$table]['value']
            ?? [];

        $values = [];

        if (is_array($tableValue)) {
            foreach (
                $tableValue as $row
            ) {
                if (!is_array($row)) {
                    continue;
                }

                $v =
                    $row['value'][$child]['value']
                    ?? '';

                if (
                    is_array($v)
                ) {
                    foreach ($v as $item) {
                        $values[] =
                            is_array($item)
                                ? (
                                    $item['name']
                                    ?? $item['code']
                                    ?? ''
                                )
                                : $item;
                    }
                } else {
                    $values[] = $v;
                }
            }
        }

        return $values;
    }

    return '';
}

function flatten_record_value(
    mixed $value
): string {
    if (
        $value === null
        || $value === ''
    ) {
        return '';
    }

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    if (!is_array($value)) {
        return '';
    }

    $parts = [];

    foreach (
        $value as $item
    ) {
        if (is_scalar($item)) {
            $parts[] =
                trim((string)$item);
            continue;
        }

        if (!is_array($item)) {
            continue;
        }

        foreach (
            [
                'name',
                'label',
                'value',
                'code',
                'text',
            ] as $key
        ) {
            if (
                isset($item[$key])
                && is_scalar(
                    $item[$key]
                )
                && trim(
                    (string)$item[$key]
                ) !== ''
            ) {
                $parts[] =
                    trim(
                        (string)$item[$key]
                    );
                break;
            }
        }
    }

    return implode(
        ' / ',
        array_values(
            array_filter(
                $parts,
                static fn(string $v): bool =>
                    $v !== ''
            )
        )
    );
}

function build_customer_from_record(
    array $record,
    array $mapping
): array {
    $recordId =
        flatten_record_value(
            $record['$id']['value']
                ?? ''
        );

    $organization =
        flatten_record_value(
            kintone_record_value(
                $record,
                (string)(
                    $mapping['organization']
                    ?? ''
                )
            )
        );

    $name =
        flatten_record_value(
            kintone_record_value(
                $record,
                (string)(
                    $mapping['name']
                    ?? ''
                )
            )
        );

    $email =
        flatten_record_value(
            kintone_record_value(
                $record,
                (string)(
                    $mapping['email']
                    ?? ''
                )
            )
        );

    $department =
        flatten_record_value(
            kintone_record_value(
                $record,
                (string)(
                    $mapping['department']
                    ?? ''
                )
            )
        );

    $phone =
        flatten_record_value(
            kintone_record_value(
                $record,
                (string)(
                    $mapping['phone']
                    ?? ''
                )
            )
        );

    /*
     * 住所は複数フィールドを連結。
     * 例えば
     *   郵便番号
     *   都道府県
     *   市区町村
     *   番地
     *   建物名
     * を複数選択して組み立てられる。
     */
    $addressParts = [];

    foreach (
        $mapping['address'] ?? []
        as $addressCode
    ) {
        $v =
            flatten_record_value(
                kintone_record_value(
                    $record,
                    (string)$addressCode
                )
            );

        if ($v !== '') {
            $addressParts[] = $v;
        }
    }

    return [
        'id'           => uuid('customer'),
        'kintone_id'   => $recordId,
        'organization' => $organization,
        'name'         => $name,
        'email'        => $email,
        'department'   => $department,
        'phone'        => $phone,
        'address'      => implode(
            ' ',
            $addressParts
        ),
        'syncedAt'     => date('Y-m-d H:i:s'),
    ];
}

/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail_config(
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
        (int)($config['port'] ?? 0);

    if (
        $port < 1
        || $port > 65535
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
            '暗号化方式が不正です。';
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
        !empty($config['reply_to'])
        && !filter_var(
            (string)$config['reply_to'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '返信先メールアドレスが不正です。';
    }

    if (
        !empty($config['auth'])
        && (
            trim(
                (string)(
                    $config['username'] ?? ''
                )
            ) === ''
            || trim(
                (string)(
                    $config['password'] ?? ''
                )
            ) === ''
        )
    ) {
        $errors[] =
            'SMTP認証を使用する場合はユーザー名とパスワードが必要です。';
    }

    return $errors;
}

function smtp_runtime_config(
    array $stored
): array {
    $config = $stored;

    $config['password'] =
        decrypt_secret(
            (string)(
                $stored['password'] ?? ''
            )
        );

    return $config;
}

function smtp_read(
    $socket
): string {
    $response = '';

    while (
        ($line = fgets($socket))
        !== false
    ) {
        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([ -])/',
                $line,
                $m
            )
        ) {
            if ($m[2] === ' ') {
                return $response;
            }
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPから応答がありません。'
        );
    }

    return $response;
}

function smtp_expect(
    $socket,
    array $codes
): string {
    $response = '';

    while (
        ($line = fgets($socket))
        !== false
    ) {
        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([ -])/',
                $line,
                $m
            )
        ) {
            if ($m[2] === ' ') {
                $code = (int)$m[1];

                if (
                    !in_array(
                        $code,
                        $codes,
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'SMTPエラー: '
                        . $code
                        . ' '
                        . trim($response)
                    );
                }

                return $response;
            }
        }
    }

    throw new RuntimeException(
        'SMTPからレスポンスを取得できませんでした。'
    );
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): string {
    if (
        fwrite(
            $socket,
            $command . "\r\n"
        ) === false
    ) {
        throw new RuntimeException(
            'SMTPへコマンドを送信できません。'
        );
    }

    return smtp_expect(
        $socket,
        $codes
    );
}

function smtp_open(
    array $config
) {
    $errors =
        validate_mail_config(
            $config
        );

    if ($errors) {
        throw new RuntimeException(
            implode(
                "\n",
                $errors
            )
        );
    }

    $host =
        trim(
            (string)$config['host']
        );

    $port =
        (int)$config['port'];

    $encryption =
        (string)$config['encryption'];

    $target =
        $encryption === 'ssl'
            ? 'ssl://' . $host
            : 'tcp://' . $host;

    $errno = 0;
    $errstr = '';

    $socket =
        @stream_socket_client(
            $target . ':' . $port,
            $errno,
            $errstr,
            HTTP_CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

    if (!is_resource($socket)) {
        throw new RuntimeException(
            'SMTP接続失敗: '
            . $errstr
            . ' ('
            . $errno
            . ')'
        );
    }

    stream_set_timeout(
        $socket,
        HTTP_READ_TIMEOUT
    );

    smtp_expect(
        $socket,
        [220]
    );

    $ehlo =
        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );

    if (
        $encryption === 'tls'
    ) {
        if (
            stripos(
                $ehlo,
                'STARTTLS'
            ) === false
        ) {
            fclose($socket);

            throw new RuntimeException(
                'SMTPサーバがSTARTTLSをサポートしていません。'
            );
        }

        smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        $crypto =
            stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'SMTP TLS暗号化を開始できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );
    }

    if (
        !empty($config['auth'])
    ) {
        $username =
            (string)(
                $config['username'] ?? ''
            );

        $password =
            (string)(
                $config['password'] ?? ''
            );

        smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $socket,
            base64_encode(
                $username
            ),
            [334]
        );

        smtp_command(
            $socket,
            base64_encode(
                $password
            ),
            [235]
        );
    }

    return $socket;
}

function smtp_test(
    array $config
): void {
    $socket =
        smtp_open(
            $config
        );

    try {
        smtp_command(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}

function smtp_header(
    string $value
): string {
    if (
        function_exists('mb_encode_mimeheader')
    ) {
        return mb_encode_mimeheader(
            $value,
            'UTF-8',
            'B',
            "\r\n"
        );
    }

    return $value;
}

function smtp_body_encode(
    string $value
): string {
    return quoted_printable_encode(
        str_replace(
            ["\r\n", "\r"],
            "\n",
            $value
        )
    );
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
            '宛先メールアドレスが不正です。'
        );
    }

    if (
        trim($subject) === ''
    ) {
        throw new RuntimeException(
            'メール件名を入力してください。'
        );
    }

    $socket =
        smtp_open(
            $config
        );

    try {
        smtp_command(
            $socket,
            'MAIL FROM:<'
            . $config['from_email']
            . '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<'
            . $to
            . '>',
            [250, 251]
        );

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $headers = [];

        $headers[] =
            'Date: '
            . date(
                'r'
            );

        $headers[] =
            'From: '
            . smtp_header(
                (string)(
                    $config['from_name']
                    ?? ''
                )
            )
            . ' <'
            . $config['from_email']
            . '>';

        $headers[] =
            'To: <'
            . $to
            . '>';

        $headers[] =
            'Subject: '
            . smtp_header(
                $subject
            );

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        $headers[] =
            'Content-Transfer-Encoding: quoted-printable';

        if (
            !empty($config['reply_to'])
        ) {
            $headers[] =
                'Reply-To: <'
                . $config['reply_to']
                . '>';
        }

        $message =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . smtp_body_encode(
                $body
            )
            . "\r\n.";

        smtp_command(
            $socket,
            $message,
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
 * 入力検証
 * ========================================================= */

function validate_survey_input(): array
{
    $errors = [];

    $title =
        post_string('title');

    $description =
        (string)(
            $_POST['description'] ?? ''
        );

    $startAt =
        post_string('startAt');

    $endAt =
        post_string('endAt');

    $numbering =
        post_string('numbering');

    if ($title === '') {
        $errors[] =
            'アンケートタイトルを入力してください。';
    }

    if (
        mb_strlen($title)
        > MAX_TITLE
    ) {
        $errors[] =
            'アンケートタイトルが長すぎます。';
    }

    if (
        mb_strlen($description)
        > MAX_DESCRIPTION
    ) {
        $errors[] =
            'アンケート説明が長すぎます。';
    }

    if (
        $startAt !== ''
        && strtotime($startAt) === false
    ) {
        $errors[] =
            '開始日時が不正です。';
    }

    if (
        $endAt !== ''
        && strtotime($endAt) === false
    ) {
        $errors[] =
            '終了日時が不正です。';
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) !== false
        && strtotime($endAt) !== false
        && strtotime($endAt)
            < strtotime($startAt)
    ) {
        $errors[] =
            '終了日時は開始日時以降にしてください。';
    }

    if (
        !in_array(
            $numbering,
            ['global', 'group'],
            true
        )
    ) {
        $numbering = 'global';
    }

    return [
        'errors'     => $errors,
        'title'      => $title,
        'description'=> $description,
        'startAt'    => $startAt,
        'endAt'      => $endAt,
        'numbering'  => $numbering,
    ];
}

function validate_email(
    string $email
): bool {
    return filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    ) !== false;
}

/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): ?array {
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        !== 'POST'
    ) {
        return null;
    }

    $action =
        post_string('action');

    try {
        switch ($action) {

            /* =================================================
             * アンケート保存
             * ================================================= */

            case 'save_survey':

                $input =
                    validate_survey_input();

                if ($input['errors']) {
                    flash(
                        'error',
                        implode(
                            "\n",
                            $input['errors']
                        )
                    );

                    return [
                        'screen' => 'edit',
                        'id' =>
                            post_string(
                                'survey_id'
                            ),
                    ];
                }

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                $groups =
                    $_POST['groups']
                    ?? [];

                if (
                    !is_array($groups)
                ) {
                    $groups = [];
                }

                $normalizedGroups = [];

                foreach (
                    $groups as $groupInput
                ) {
                    if (
                        !is_array($groupInput)
                    ) {
                        continue;
                    }

                    $groupId =
                        scalar_string(
                            $groupInput['id']
                            ?? ''
                        );

                    if (
                        $groupId === ''
                    ) {
                        $groupId =
                            uuid('group');
                    }

                    $groupTitle =
                        scalar_string(
                            $groupInput['title']
                            ?? ''
                        );

                    $questions =
                        $groupInput['questions']
                        ?? [];

                    if (
                        !is_array($questions)
                    ) {
                        $questions = [];
                    }

                    $normalizedQuestions = [];

                    foreach (
                        $questions as $questionInput
                    ) {
                        if (
                            !is_array(
                                $questionInput
                            )
                        ) {
                            continue;
                        }

                        $questionId =
                            scalar_string(
                                $questionInput['id']
                                ?? ''
                            );

                        if (
                            $questionId === ''
                        ) {
                            $questionId =
                                uuid('question');
                        }

                        $text =
                            scalar_string(
                                $questionInput['text']
                                ?? ''
                            );

                        if (
                            $text === ''
                        ) {
                            continue;
                        }

                        if (
                            mb_strlen($text)
                            > MAX_QUESTION
                        ) {
                            $text =
                                mb_substr(
                                    $text,
                                    0,
                                    MAX_QUESTION
                                );
                        }

                        $type =
                            scalar_string(
                                $questionInput['type']
                                ?? 'text'
                            );

                        if (
                            !in_array(
                                $type,
                                [
                                    'single',
                                    'multiple',
                                    'text',
                                ],
                                true
                            )
                        ) {
                            $type = 'text';
                        }

                        $options =
                            $questionInput['options']
                            ?? [];

                        if (
                            !is_array($options)
                        ) {
                            $options = [];
                        }

                        $normalizedOptions = [];

                        foreach (
                            $options as $optionInput
                        ) {
                            if (
                                !is_array(
                                    $optionInput
                                )
                            ) {
                                continue;
                            }

                            $label =
                                scalar_string(
                                    $optionInput['label']
                                    ?? ''
                                );

                            if (
                                $label === ''
                            ) {
                                continue;
                            }

                            if (
                                mb_strlen($label)
                                > MAX_OPTION
                            ) {
                                $label =
                                    mb_substr(
                                        $label,
                                        0,
                                        MAX_OPTION
                                    );
                            }

                            $nextQuestionId =
                                scalar_string(
                                    $optionInput[
                                        'nextQuestionId'
                                    ] ?? ''
                                );

                            $normalizedOptions[] = [
                                'id' =>
                                    scalar_string(
                                        $optionInput['id']
                                        ?? ''
                                    ) ?: uuid(
                                        'option'
                                    ),
                                'label' =>
                                    $label,
                                'nextQuestionId' =>
                                    $nextQuestionId,
                            ];
                        }

                        $normalizedQuestions[] = [
                            'id' =>
                                $questionId,
                            'number' =>
                                '',
                            'text' =>
                                $text,
                            'type' =>
                                $type,
                            'required' =>
                                !empty(
                                    $questionInput['required']
                                ),
                            'options' =>
                                $normalizedOptions,
                        ];
                    }

                    $normalizedGroups[] = [
                        'id' =>
                            $groupId,
                        'title' =>
                            $groupTitle,
                        'questions' =>
                            $normalizedQuestions,
                    ];
                }

                if (!$normalizedGroups) {
                    $normalizedGroups[] = [
                        'id' =>
                            uuid('group'),
                        'title' =>
                            '基本アンケート',
                        'questions' =>
                            [],
                    ];
                }

                if (
                    $index < 0
                ) {
                    $surveyId =
                        uuid('survey');

                    $survey = [
                        'id' =>
                            $surveyId,
                        'title' =>
                            $input['title'],
                        'description' =>
                            $input['description'],
                        'startAt' =>
                            $input['startAt'],
                        'endAt' =>
                            $input['endAt'],
                        'status' =>
                            'draft',
                        'numbering' =>
                            $input['numbering'],
                        'createdAt' =>
                            date(
                                'Y-m-d H:i:s'
                            ),
                        'updatedAt' =>
                            date(
                                'Y-m-d H:i:s'
                            ),
                        'groups' =>
                            $normalizedGroups,
                    ];

                    recalc_numbers(
                        $survey
                    );

                    $data['surveys'][] =
                        $survey;
                } else {
                    $survey =
                        $data['surveys'][$index];

                    /*
                     * 編集時は状態を勝手に変更しない。
                     */
                    $survey['title'] =
                        $input['title'];

                    $survey['description'] =
                        $input['description'];

                    $survey['startAt'] =
                        $input['startAt'];

                    $survey['endAt'] =
                        $input['endAt'];

                    $survey['numbering'] =
                        $input['numbering'];

                    $survey['groups'] =
                        $normalizedGroups;

                    $survey['updatedAt'] =
                        date(
                            'Y-m-d H:i:s'
                        );

                    recalc_numbers(
                        $survey
                    );

                    $data['surveys'][$index] =
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

            /* =================================================
             * 公開
             * ================================================= */

            case 'publish_survey':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                if (
                    $index < 0
                ) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                if (
                    ($data['surveys'][$index]['status'] ?? '')
                    === 'ended'
                ) {
                    throw new RuntimeException(
                        '終了したアンケートは公開できません。'
                    );
                }

                $data['surveys'][$index]['status'] =
                    'published';

                $data['surveys'][$index]['updatedAt'] =
                    date(
                        'Y-m-d H:i:s'
                    );

                save_data($data);

                flash(
                    'success',
                    'アンケートを公開しました。'
                );

                return [
                    'screen' => 'list',
                ];

            /* =================================================
             * 停止
             * ================================================= */

            case 'stop_survey':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                if (
                    $index < 0
                ) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                if (
                    ($data['surveys'][$index]['status'] ?? '')
                    === 'ended'
                ) {
                    throw new RuntimeException(
                        '終了したアンケートは停止状態を変更できません。'
                    );
                }

                $data['surveys'][$index]['status'] =
                    'stopped';

                $data['surveys'][$index]['updatedAt'] =
                    date(
                        'Y-m-d H:i:s'
                    );

                save_data($data);

                flash(
                    'success',
                    'アンケートを停止しました。'
                );

                return [
                    'screen' => 'list',
                ];

            /* =================================================
             * 再開
             * ================================================= */

            case 'resume_survey':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                if (
                    $index < 0
                ) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                if (
                    ($data['surveys'][$index]['status'] ?? '')
                    === 'ended'
                ) {
                    throw new RuntimeException(
                        '終了したアンケートは再開できません。'
                    );
                }

                $data['surveys'][$index]['status'] =
                    'published';

                $data['surveys'][$index]['updatedAt'] =
                    date(
                        'Y-m-d H:i:s'
                    );

                save_data($data);

                flash(
                    'success',
                    'アンケートを再開しました。'
                );

                return [
                    'screen' => 'list',
                ];

            /* =================================================
             * 複製
             * ================================================= */

            case 'duplicate_survey':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if (
                    $survey === null
                ) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $survey['id'] =
                    uuid('survey');

                $survey['title'] =
                    (string)(
                        $survey['title']
                        ?? ''
                    )
                    . '（コピー）';

                $survey['status'] =
                    'draft';

                $survey['createdAt'] =
                    date(
                        'Y-m-d H:i:s'
                    );

                $survey['updatedAt'] =
                    date(
                        'Y-m-d H:i:s'
                    );

                foreach (
                    $survey['groups'] as &$group
                ) {
                    $group['id'] =
                        uuid('group');

                    foreach (
                        $group['questions']
                        as &$question
                    ) {
                        $question['id'] =
                            uuid('question');

                        foreach (
                            $question['options']
                            ?? []
                            as &$option
                        ) {
                            $option['id'] =
                                uuid('option');
                        }

                        unset($option);
                    }

                    unset($question);
                }

                unset($group);

                recalc_numbers(
                    $survey
                );

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

            /* =================================================
             * 削除
             * ================================================= */

            case 'delete_survey':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                if (
                    $index < 0
                ) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
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

            /* =================================================
             * kintone接続テスト
             * ================================================= */

            case 'test_kintone':

                $current =
                    $settings['kintone'];

                $password =
                    post_string(
                        'password'
                    );

                if (
                    $password === ''
                ) {
                    $password =
                        decrypt_secret(
                            (string)(
                                $current['password']
                                ?? ''
                            )
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
                        post_string(
                            'app_id'
                        ),
                    'username' =>
                        post_string(
                            'username'
                        ),
                    'password' =>
                        $password,
                    'proxy' =>
                        post_string(
                            'proxy'
                        ),
                    'verify_ssl' =>
                        post_bool(
                            'verify_ssl'
                        ),
                ];

                try {
                    $result =
                        kintone_test(
                            $config
                        );

                    $settings['kintone'] =
                        array_replace(
                            $settings['kintone'],
                            [
                                'subdomain' =>
                                    $config['subdomain'],
                                'app_id' =>
                                    $config['app_id'],
                                'username' =>
                                    $config['username'],
                                'password' =>
                                    encrypt_secret(
                                        $password
                                    ),
                                'proxy' =>
                                    $config['proxy'],
                                'verify_ssl' =>
                                    $config['verify_ssl'],
                                'last_test' =>
                                    date(
                                        'Y-m-d H:i:s'
                                    ),
                            ]
                        );

                    save_settings(
                        $settings
                    );

                    $name =
                        (string)(
                            $result['body']['name']
                            ?? ''
                        );

                    flash(
                        'success',
                        'kintone接続成功。'
                        . (
                            $name !== ''
                                ? ' アプリ: ' . $name
                                : ''
                        )
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'kintone接続失敗：'
                        . $e->getMessage()
                    );
                }

                return [
                    'screen' => 'kintone',
                ];

            /* =================================================
             * kintone設定保存
             * ================================================= */

            case 'save_kintone':

                $current =
                    $settings['kintone'];

                $password =
                    post_string(
                        'password'
                    );

                if (
                    $password === ''
                ) {
                    $password =
                        decrypt_secret(
                            (string)(
                                $current['password']
                                ?? ''
                            )
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
                        post_string(
                            'app_id'
                        ),
                    'username' =>
                        post_string(
                            'username'
                        ),
                    'password' =>
                        $password,
                    'proxy' =>
                        post_string(
                            'proxy'
                        ),
                    'verify_ssl' =>
                        post_bool(
                            'verify_ssl'
                        ),
                ];

                $errors =
                    validate_kintone_config(
                        $config,
                        true
                    );

                if ($errors) {
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
                    array_replace(
                        $settings['kintone'],
                        [
                            'subdomain' =>
                                $config['subdomain'],
                            'app_id' =>
                                $config['app_id'],
                            'username' =>
                                $config['username'],
                            'password' =>
                                encrypt_secret(
                                    $password
                                ),
                            'proxy' =>
                                $config['proxy'],
                            'verify_ssl' =>
                                $config['verify_ssl'],
                        ]
                    );

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                return [
                    'screen' => 'kintone',
                ];

            /* =================================================
             * kintone項目一覧再取得
             *
             * 接続テストとは別操作。
             * ================================================= */

            case 'refresh_kintone_fields':

                $config =
                    kintone_config_runtime(
                        $settings['kintone']
                    );

                $result =
                    kintone_fields(
                        $config
                    );

                $properties =
                    is_array(
                        $result['body']['properties']
                        ?? null
                    )
                        ? $result['body']['properties']
                        : [];

                if (!$properties) {
                    throw new RuntimeException(
                        'kintoneから項目一覧を取得できませんでした。'
                    );
                }

                $fields =
                    flatten_kintone_fields(
                        $properties
                    );

                if (!$fields) {
                    throw new RuntimeException(
                        'kintoneアプリに利用可能な項目がありません。'
                    );
                }

                $settings['kintone']['fields'] =
                    $fields;

                $settings['kintone']['mapping'] =
                    normalize_mapping(
                        is_array(
                            $settings['kintone']['mapping']
                            ?? null
                        )
                            ? $settings['kintone']['mapping']
                            : [],
                        $fields
                    );

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone項目一覧を再取得しました。'
                    . ' '
                    . count($fields)
                    . '項目を取得しました。'
                );

                return [
                    'screen' => 'kintone',
                ];

            /* =================================================
             * kintoneマッピング保存
             * ================================================= */

            case 'save_kintone_mapping':

                $fields =
                    is_array(
                        $settings['kintone']['fields']
                        ?? null
                    )
                        ? $settings['kintone']['fields']
                        : [];

                if (!$fields) {
                    throw new RuntimeException(
                        '先に「項目一覧を再取得」を実行してください。'
                    );
                }

                $mapping = [
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
                        is_array(
                            $_POST['mapping_address']
                            ?? null
                        )
                            ? array_values(
                                array_filter(
                                    array_map(
                                        'strval',
                                        $_POST[
                                            'mapping_address'
                                        ]
                                    )
                                )
                            )
                            : [],
                ];

                $mapping =
                    normalize_mapping(
                        $mapping,
                        $fields
                    );

                if (
                    $mapping['email'] === ''
                ) {
                    throw new RuntimeException(
                        'メールアドレスのマッピングを設定してください。'
                    );
                }

                if (
                    $mapping['name'] === ''
                ) {
                    throw new RuntimeException(
                        '氏名のマッピングを設定してください。'
                    );
                }

                $settings['kintone']['mapping'] =
                    $mapping;

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone項目マッピングを保存しました。'
                );

                return [
                    'screen' => 'kintone',
                ];

            /* =================================================
             * kintone顧客同期
             *
             * 接続テストと分離。
             * ================================================= */

            case 'sync_kintone_customers':

                $config =
                    kintone_config_runtime(
                        $settings['kintone']
                    );

                $mapping =
                    is_array(
                        $settings['kintone']['mapping']
                        ?? null
                    )
                        ? $settings['kintone']['mapping']
                        : [];

                if (
                    (string)(
                        $mapping['name']
                        ?? ''
                    ) === ''
                    || (string)(
                        $mapping['email']
                        ?? ''
                    ) === ''
                ) {
                    throw new RuntimeException(
                        '顧客同期の前に、氏名とメールアドレスのマッピングを設定してください。'
                    );
                }

                if (
                    empty(
                        $settings['kintone']['fields']
                    )
                ) {
                    throw new RuntimeException(
                        '顧客同期の前に「項目一覧を再取得」を実行してください。'
                    );
                }

                $result =
                    kintone_records(
                        $config
                    );

                $records =
                    is_array(
                        $result['body']['records']
                        ?? null
                    )
                        ? $result['body']['records']
                        : [];

                $customers = [];

                foreach (
                    $records as $record
                ) {
                    if (
                        !is_array($record)
                    ) {
                        continue;
                    }

                    $customer =
                        build_customer_from_record(
                            $record,
                            $mapping
                        );

                    if (
                        $customer['email'] === ''
                    ) {
                        continue;
                    }

                    $customers[] =
                        $customer;
                }

                $data['customers'] =
                    $customers;

                $settings['kintone']['last_sync'] =
                    date(
                        'Y-m-d H:i:s'
                    );

                save_data(
                    $data
                );

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    '顧客情報を同期しました。'
                    . ' '
                    . count($customers)
                    . '件を取得しました。'
                );

                return [
                    'screen' => 'kintone',
                ];

            /* =================================================
             * SMTP設定保存
             * ================================================= */

            case 'save_mail':

                $current =
                    $settings['mail'];

                $password =
                    post_string(
                        'password'
                    );

                if (
                    $password === ''
                ) {
                    $password =
                        decrypt_secret(
                            (string)(
                                $current['password']
                                ?? ''
                            )
                        );
                }

                $config = [
                    'host' =>
                        post_string(
                            'server'
                        ),
                    'port' =>
                        (int)post_string(
                            'port'
                        ),
                    'encryption' =>
                        post_string(
                            'encryption'
                        ),
                    'auth' =>
                        post_bool(
                            'auth'
                        ),
                    'username' =>
                        post_string(
                            'username'
                        ),
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
                    validate_mail_config(
                        $config
                    );

                if ($errors) {
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

                $config['password'] =
                    encrypt_secret(
                        $password
                    );

                $settings['mail'] =
                    $config;

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'SMTP設定を保存しました。'
                );

                return [
                    'screen' => 'mail',
                ];

            /* =================================================
             * SMTP接続テスト
             * ================================================= */

            case 'test_mail':

                try {
                    $config =
                        smtp_runtime_config(
                            $settings['mail']
                        );

                    smtp_test(
                        $config
                    );

                    $settings['mail']
                        ['last_test'] =
                        date(
                            'Y-m-d H:i:s'
                        );

                    save_settings(
                        $settings
                    );

                    flash(
                        'success',
                        'SMTP接続・認証に成功しました。'
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'SMTP接続失敗：'
                        . $e->getMessage()
                    );
                }

                return [
                    'screen' => 'mail',
                ];

            /* =================================================
             * SMTPテストメール
             * ================================================= */

            case 'send_test_mail':

                $to =
                    post_string(
                        'test_email'
                    );

                if (
                    !validate_email($to)
                ) {
                    throw new RuntimeException(
                        'テストメール宛先が不正です。'
                    );
                }

                $config =
                    smtp_runtime_config(
                        $settings['mail']
                    );

                $subject =
                    'アンケートアプリ SMTPテストメール';

                $body =
                    "これはアンケートアプリからのSMTPテストメールです。\n"
                    . "\n"
                    . '送信日時: '
                    . date(
                        'Y-m-d H:i:s'
                    );

                smtp_send_mail(
                    $config,
                    $to,
                    $subject,
                    $body
                );

                flash(
                    'success',
                    'テストメールを送信しました。'
                );

                return [
                    'screen' => 'mail',
                ];

            /* =================================================
             * 回答確認
             * ================================================= */

            case 'answer_confirm':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if (
                    $survey === null
                ) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                if (
                    ($survey['status'] ?? '')
                    !== 'published'
                ) {
                    throw new RuntimeException(
                        '現在、このアンケートには回答できません。'
                    );
                }

                $answers =
                    $_POST['answer']
                    ?? [];

                if (
                    !is_array($answers)
                ) {
                    $answers = [];
                }

                foreach (
                    all_questions($survey)
                    as $question
                ) {
                    $questionId =
                        (string)(
                            $question['id']
                            ?? ''
                        );

                    if (
                        !question_is_visible(
                            $survey,
                            $answers,
                            $questionId
                        )
                    ) {
                        continue;
                    }

                    if (
                        !empty(
                            $question['required']
                        )
                    ) {
                        $value =
                            $answers[
                                $questionId
                            ]
                            ?? '';

                        $empty =
                            $value === ''
                            || (
                                is_array($value)
                                && count($value) === 0
                            )
                            || (
                                is_string($value)
                                && trim($value) === ''
                            );

                        if ($empty) {
                            throw new RuntimeException(
                                '必須項目に未回答があります。'
                            );
                        }
                    }
                }

                $_SESSION['answer_draft'] =
                    $answers;

                return [
                    'screen' => 'confirm',
                    'id' =>
                        $surveyId,
                ];

            /* =================================================
             * 回答修正
             * ================================================= */

            case 'answer_back':

                return [
                    'screen' => 'answer',
                    'id' =>
                        post_string(
                            'survey_id'
                        ),
                ];

            /* =================================================
             * 回答送信
             * ================================================= */

            case 'submit_answer':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if (
                    $survey === null
                ) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $answers =
                    $_SESSION[
                        'answer_draft'
                    ]
                    ?? [];

                if (
                    !is_array($answers)
                ) {
                    $answers = [];
                }

                $data['answers'][] = [
                    'id' =>
                        uuid('answer'),
                    'survey_id' =>
                        $surveyId,
                    'submittedAt' =>
                        date(
                            'Y-m-d H:i:s'
                        ),
                    'answers' =>
                        $answers,
                ];

                save_data(
                    $data
                );

                unset(
                    $_SESSION[
                        'answer_draft'
                    ]
                );

                return [
                    'screen' => 'complete',
                    'id' =>
                        $surveyId,
                ];

            /* =================================================
             * 顧客メール送信
             * ================================================= */

            case 'send_customer_mail':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if (
                    $survey === null
                ) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $customerIds =
                    $_POST['customer_ids']
                    ?? [];

                if (
                    !is_array(
                        $customerIds
                    )
                ) {
                    $customerIds = [];
                }

                $customerIds =
                    array_values(
                        array_filter(
                            array_map(
                                'strval',
                                $customerIds
                            )
                        )
                    );

                if (!$customerIds) {
                    throw new RuntimeException(
                        '送信対象の顧客を選択してください。'
                    );
                }

                $subject =
                    post_string(
                        'mail_subject'
                    );

                $body =
                    (string)(
                        $_POST['mail_body']
                        ?? ''
                    );

                if (
                    $subject === ''
                ) {
                    throw new RuntimeException(
                        'メール件名を入力してください。'
                    );
                }

                if (
                    mb_strlen($body)
                    > MAX_MAIL_BODY
                ) {
                    throw new RuntimeException(
                        'メール本文が長すぎます。'
                    );
                }

                $config =
                    smtp_runtime_config(
                        $settings['mail']
                    );

                $results = [];

                foreach (
                    $data['customers']
                    as $customer
                ) {
                    if (
                        !in_array(
                            (string)(
                                $customer['id']
                                ?? ''
                            ),
                            $customerIds,
                            true
                        )
                    ) {
                        continue;
                    }

                    $email =
                        (string)(
                            $customer['email']
                            ?? ''
                        );

                    if (
                        !validate_email($email)
                    ) {
                        $results[] = [
                            'customer_id' =>
                                $customer['id']
                                ?? '',
                            'email' =>
                                $email,
                            'status' =>
                                'error',
                            'message' =>
                                'メールアドレス不正',
                        ];

                        continue;
                    }

                    $personalSubject =
                        str_replace(
                            '{顧客名}',
                            (string)(
                                $customer['name']
                                ?? ''
                            ),
                            $subject
                        );

                    $personalBody =
                        str_replace(
                            '{顧客名}',
                            (string)(
                                $customer['name']
                                ?? ''
                            ),
                            $body
                        );

                    $personalBody =
                        str_replace(
                            '{アンケートURL}',
                            public_answer_url(
                                $surveyId
                            ),
                            $personalBody
                        );

                    try {
                        smtp_send_mail(
                            $config,
                            $email,
                            $personalSubject,
                            $personalBody
                        );

                        $results[] = [
                            'customer_id' =>
                                $customer['id']
                                ?? '',
                            'email' =>
                                $email,
                            'status' =>
                                'success',
                            'message' =>
                                '送信成功',
                        ];

                        $data[
                            'send_history'
                        ][] = [
                            'id' =>
                                uuid('send'),
                            'survey_id' =>
                                $surveyId,
                            'customer_id' =>
                                $customer['id']
                                ?? '',
                            'email' =>
                                $email,
                            'type' =>
                                'send',
                            'status' =>
                                'success',
                            'sentAt' =>
                                date(
                                    'Y-m-d H:i:s'
                                ),
                            'subject' =>
                                $personalSubject,
                        ];
                    } catch (Throwable $e) {
                        $results[] = [
                            'customer_id' =>
                                $customer['id']
                                ?? '',
                            'email' =>
                                $email,
                            'status' =>
                                'error',
                            'message' =>
                                $e->getMessage(),
                        ];

                        $data[
                            'send_history'
                        ][] = [
                            'id' =>
                                uuid('send'),
                            'survey_id' =>
                                $surveyId,
                            'customer_id' =>
                                $customer['id']
                                ?? '',
                            'email' =>
                                $email,
                            'type' =>
                                'send',
                            'status' =>
                                'error',
                            'sentAt' =>
                                date(
                                    'Y-m-d H:i:s'
                                ),
                            'subject' =>
                                $personalSubject,
                        ];
                    }
                }

                save_data(
                    $data
                );

                $_SESSION[
                    'send_results'
                ] = $results;

                return [
                    'screen' => 'send',
                    'id' =>
                        $surveyId,
                ];

            /* =================================================
             * 再送
             * ================================================= */

            case 'resend_customer':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $customerId =
                    post_string(
                        'customer_id'
                    );

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if (
                    $survey === null
                ) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $customer = null;

                foreach (
                    $data['customers']
                    as $item
                ) {
                    if (
                        (string)(
                            $item['id']
                            ?? ''
                        )
                        === $customerId
                    ) {
                        $customer = $item;
                        break;
                    }
                }

                if (
                    $customer === null
                ) {
                    throw new RuntimeException(
                        '顧客が見つかりません。'
                    );
                }

                $config =
                    smtp_runtime_config(
                        $settings['mail']
                    );

                $subject =
                    '【再送】'
                    . $survey['title'];

                $body =
                    (string)(
                        $survey['title']
                    )
                    . "\n\n"
                    . 'アンケートURL: '
                    . public_answer_url(
                        $surveyId
                    );

                smtp_send_mail(
                    $config,
                    (string)$customer['email'],
                    $subject,
                    $body
                );

                $data['send_history'][] = [
                    'id' =>
                        uuid('send'),
                    'survey_id' =>
                        $surveyId,
                    'customer_id' =>
                        $customerId,
                    'email' =>
                        $customer['email'],
                    'type' =>
                        'resend',
                    'status' =>
                        'success',
                    'sentAt' =>
                        date(
                            'Y-m-d H:i:s'
                        ),
                    'subject' =>
                        $subject,
                ];

                save_data(
                    $data
                );

                flash(
                    'success',
                    '再送しました。'
                );

                return [
                    'screen' => 'send',
                    'id' =>
                        $surveyId,
                ];

            default:

                throw new RuntimeException(
                    '不正なリクエストです。ページを再読み込みしてください。'
                );
        }
    } catch (Throwable $e) {
        flash(
            'error',
            $e->getMessage()
        );

        $screen =
            get_string('screen');

        if (
            $screen === ''
        ) {
            $screen = 'list';
        }

        return [
            'screen' =>
                $screen,
            'id' =>
                post_string('survey_id'),
        ];
    }
}

/* =========================================================
 * 共通HTML
 * ========================================================= */

function render_head(
    string $title,
    bool $admin = true
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_TITLE) ?></title>
<style>
:root{
    --bg:#f5f7fb;
    --card:#fff;
    --text:#172033;
    --muted:#64748b;
    --border:#d9e1ec;
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#15803d;
    --success-bg:#dcfce7;
    --warning:#b45309;
    --warning-bg:#fef3c7;
    --danger:#b91c1c;
    --danger-bg:#fee2e2;
    --gray:#64748b;
    --gray-bg:#f1f5f9;
    --shadow:0 4px 18px rgba(15,23,42,.07);
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        Meiryo,
        sans-serif;
    line-height:1.6;
}
a{
    color:var(--primary);
    text-decoration:none;
}
a:hover{text-decoration:underline}
.header{
    background:#0f172a;
    color:#fff;
}
.header-inner{
    width:min(1180px,calc(100% - 32px));
    margin:auto;
    min-height:62px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}
.header-title{
    font-weight:700;
}
.nav{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
}
.nav a{
    color:#cbd5e1;
    font-size:14px;
}
.page{
    padding:30px 0 50px;
}
.container{
    width:min(1180px,calc(100% - 32px));
    margin:auto;
}
.answer-shell{
    width:min(760px,calc(100% - 28px));
    margin:0 auto;
    padding:28px 0 50px;
}
.page-title{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:22px;
}
.page-title h1{
    margin:0 0 6px;
    font-size:28px;
}
.page-title p{
    margin:0;
    color:var(--muted);
}
.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}
.card-body{
    padding:22px;
}
.grid{
    display:grid;
    gap:18px;
}
.grid-2{
    grid-template-columns:repeat(2,minmax(0,1fr));
}
.grid-3{
    grid-template-columns:repeat(3,minmax(0,1fr));
}
label{
    display:block;
}
label > span,
.field-label{
    display:block;
    margin-bottom:6px;
    font-weight:600;
    font-size:14px;
}
input,
textarea,
select{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    font:inherit;
    background:#fff;
    color:var(--text);
}
textarea{
    min-height:120px;
    resize:vertical;
}
input:focus,
textarea:focus,
select:focus{
    outline:2px solid rgba(37,99,235,.18);
    border-color:var(--primary);
}
input[type=checkbox],
input[type=radio]{
    width:auto;
}
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:0;
    border-radius:8px;
    padding:9px 14px;
    cursor:pointer;
    font:inherit;
    text-decoration:none;
}
.btn:hover{
    text-decoration:none;
}
.btn-primary{
    background:var(--primary);
    color:#fff;
}
.btn-primary:hover{
    background:var(--primary-dark);
}
.btn-secondary{
    background:#e2e8f0;
    color:#1e293b;
}
.btn-success{
    background:#16a34a;
    color:#fff;
}
.btn-warning{
    background:#d97706;
    color:#fff;
}
.btn-danger{
    background:#dc2626;
    color:#fff;
}
.btn-small{
    padding:6px 10px;
    font-size:13px;
}
.button-row{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.alert{
    padding:13px 15px;
    border-radius:8px;
    margin-bottom:18px;
    white-space:pre-line;
}
.alert-success{
    background:var(--success-bg);
    color:#166534;
    border:1px solid #bbf7d0;
}
.alert-error{
    background:var(--danger-bg);
    color:#991b1b;
    border:1px solid #fecaca;
}
.alert-warning{
    background:var(--warning-bg);
    color:#92400e;
    border:1px solid #fde68a;
}
.badge{
    display:inline-block;
    padding:3px 8px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.badge-success{
    background:var(--success-bg);
    color:var(--success);
}
.badge-warning{
    background:var(--warning-bg);
    color:var(--warning);
}
.badge-gray{
    background:var(--gray-bg);
    color:var(--gray);
}
.table-wrap{
    overflow-x:auto;
}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    padding:11px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}
th{
    background:#f8fafc;
    white-space:nowrap;
}
.muted{
    color:var(--muted);
}
.small{
    font-size:13px;
}
.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
}
.stat{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    background:#fff;
}
.stat-value{
    font-size:25px;
    font-weight:700;
}
.stat-label{
    color:var(--muted);
    font-size:13px;
}
.question-card,
.group-card{
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
    margin-bottom:14px;
}
.group-header,
.question-header{
    padding:12px 14px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
}
.question-body{
    padding:14px;
}
.option-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:8px;
    margin:7px 0;
}
.mapping-box{
    border:1px solid var(--border);
    border-radius:9px;
    padding:14px;
    background:#fafcff;
}
.mapping-box h3{
    margin:0 0 12px;
    font-size:16px;
}
.field-list{
    max-height:350px;
    overflow:auto;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
}
.field-item{
    padding:9px 11px;
    border-bottom:1px solid #edf1f6;
    display:flex;
    gap:9px;
    align-items:flex-start;
}
.field-item:last-child{
    border-bottom:0;
}
.drag{
    cursor:grab;
}
.dragging{
    opacity:.45;
}
.drop-target{
    outline:2px dashed var(--primary);
}
.kv{
    display:grid;
    grid-template-columns:180px 1fr;
    gap:8px;
}
.kv div{
    padding:8px 0;
    border-bottom:1px solid var(--border);
}
.mail-preview{
    white-space:pre-wrap;
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:8px;
    padding:14px;
}
.footer{
    color:var(--muted);
    text-align:center;
    padding:25px 0 35px;
    font-size:13px;
}
.sticky-actions{
    position:sticky;
    bottom:10px;
    z-index:5;
    background:rgba(255,255,255,.94);
    border:1px solid var(--border);
    border-radius:10px;
    padding:10px;
    box-shadow:var(--shadow);
}
@media(max-width:900px){
    .grid-2,
    .grid-3,
    .stat-grid{
        grid-template-columns:1fr;
    }
    .header-inner{
        align-items:flex-start;
        padding:12px 0;
        flex-direction:column;
    }
    .page-title{
        flex-direction:column;
    }
    .kv{
        grid-template-columns:1fr;
    }
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header class="header">
<div class="header-inner">
<div class="header-title">
<?= h(APP_TITLE) ?>
</div>
<nav class="nav">
<a href="<?= h(app_url(['screen'=>'list'])) ?>">アンケート一覧</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">kintone連携</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">メール設定</a>
</nav>
</div>
</header>
<?php endif; ?>
<?php
}

function render_footer(): void
{
?>
<div class="footer">
アンケート管理
</div>
</body>
</html>
<?php
}

function render_flash(): void
{
    $flash =
        consume_flash();

    if (!$flash) {
        return;
    }
?>
<div class="alert alert-<?= h(
    $flash['type'] === 'success'
        ? 'success'
        : (
            $flash['type'] === 'warning'
                ? 'warning'
                : 'error'
        )
) ?>">
<?= h($flash['message']) ?>
</div>
<?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(
    array $data
): void {
    render_head(
        'アンケート一覧'
    );
?>
<div class="page">
<div class="container">

<?php render_flash(); ?>

<div class="page-title">
<div>
<h1>アンケート一覧</h1>
<p>アンケートの作成・公開・集計・送信を管理します。</p>
</div>
<div class="button-row">
<a class="btn btn-primary"
   href="<?= h(
       app_url([
           'screen'=>'edit',
           'id'=>'new'
       ])
   ) ?>">
新規作成
</a>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="get"
      class="grid grid-2"
      style="margin-bottom:18px">
<input type="hidden"
       name="screen"
       value="list">

<label>
<span>検索</span>
<input name="q"
       value="<?= h(
           get_string('q')
       ) ?>"
       placeholder="タイトル">
</label>

<label>
<span>状態</span>
<select name="status">
<option value="">すべて</option>
<?php foreach (
    [
        'draft'=>'下書き',
        'published'=>'公開中',
        'stopped'=>'停止',
        'ended'=>'終了',
    ] as $value=>$label
): ?>
<option value="<?= h($value) ?>"
<?= selected(
    get_string('status') === $value
) ?>>
<?= h($label) ?>
</option>
<?php endforeach; ?>
</select>
</label>

<div class="button-row">
<button class="btn btn-secondary">
絞り込む
</button>
</div>
</form>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<?php
$q =
    get_string('q');

$statusFilter =
    get_string('status');

$visible = 0;

foreach (
    $data['surveys']
    as $survey
):
    if (
        $q !== ''
        && mb_stripos(
            (string)(
                $survey['title'] ?? ''
            ),
            $q
        ) === false
    ) {
        continue;
    }

    if (
        $statusFilter !== ''
        && ($survey['status'] ?? '')
            !== $statusFilter
    ) {
        continue;
    }

    $visible++;

    $answerCount = 0;

    foreach (
        $data['answers']
        as $answer
    ) {
        if (
            ($answer['survey_id'] ?? '')
            === ($survey['id'] ?? '')
        ) {
            $answerCount++;
        }
    }

    $status =
        (string)(
            $survey['status']
            ?? 'draft'
        );
?>
<tr>
<td>
<strong><?= h(
    $survey['title']
) ?></strong>
<div class="small muted">
<?= h(
    $survey['description']
) ?>
</div>
</td>
<td class="small">
<?= h(
    $survey['startAt']
    ?? ''
) ?>
<br>
〜
<br>
<?= h(
    $survey['endAt']
    ?? ''
) ?>
</td>
<td>
<span class="badge badge-<?= h(
    status_class($status)
) ?>">
<?= h(
    status_label($status)
) ?>
</span>
</td>
<td><?= h($answerCount) ?></td>
<td>
<div class="button-row">

<a class="btn btn-small btn-secondary"
   href="<?= h(
       app_url([
           'screen'=>'edit',
           'id'=>$survey['id']
       ])
   ) ?>">
編集
</a>

<a class="btn btn-small btn-secondary"
   href="<?= h(
       app_url([
           'screen'=>'preview',
           'id'=>$survey['id']
       ])
   ) ?>">
プレビュー
</a>

<a class="btn btn-small btn-secondary"
   href="<?= h(
       app_url([
           'screen'=>'analytics',
           'id'=>$survey['id']
       ])
   ) ?>">
集計
</a>

<a class="btn btn-small btn-primary"
   href="<?= h(
       app_url([
           'screen'=>'send',
           'id'=>$survey['id']
       ])
   ) ?>">
送信
</a>

<form method="post">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-small btn-secondary"
        onclick="return confirm('このアンケートを複製しますか？')">
複製
</button>
</form>

<form method="post">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-small btn-danger"
        onclick="return confirm('このアンケートを削除しますか？')">
削除
</button>
</form>

<?php if ($status === 'draft'): ?>
<form method="post">
<input type="hidden"
       name="action"
       value="publish_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-small btn-success"
        onclick="return confirm('公開しますか？')">
公開
</button>
</form>
<?php elseif ($status === 'published'): ?>
<form method="post">
<input type="hidden"
       name="action"
       value="stop_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-small btn-warning"
        onclick="return confirm('停止しますか？')">
停止
</button>
</form>
<?php elseif ($status === 'stopped'): ?>
<form method="post">
<input type="hidden"
       name="action"
       value="resume_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-small btn-success"
        onclick="return confirm('再開しますか？')">
再開
</button>
</form>
<?php endif; ?>

</div>
</td>
</tr>
<?php endforeach; ?>

<?php if ($visible === 0): ?>
<tr>
<td colspan="5"
    class="muted">
該当するアンケートがありません。
</td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>

</div>
</div>

</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(
    array $survey
): void {
    render_head(
        'アンケート作成・編集'
    );
?>
<div class="page">
<div class="container">

<?php render_flash(); ?>

<div class="page-title">
<div>
<h1>アンケート作成・編集</h1>
<p>質問番号は自動計算されます。</p>
</div>
</div>

<form method="post"
      id="survey-form">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<div class="card">
<div class="card-body">

<div class="grid grid-2">

<label>
<span>タイトル</span>
<input name="title"
       required
       maxlength="<?= MAX_TITLE ?>"
       value="<?= h(
           $survey['title']
       ) ?>">
</label>

<label>
<span>質問番号</span>
<select name="numbering">
<option value="global"
<?= selected(
    ($survey['numbering'] ?? 'global')
    === 'global'
) ?>>
全体で連番
</option>
<option value="group"
<?= selected(
    ($survey['numbering'] ?? '')
    === 'group'
) ?>>
グループごと
</option>
</select>
</label>

<label style="grid-column:1/-1">
<span>説明</span>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h(
              $survey['description']
          ) ?></textarea>
</label>

<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h(
           $survey['startAt']
       ) ?>">
</label>

<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h(
           $survey['endAt']
       ) ?>">
</label>

</div>
</div>
</div>

<div id="groups">
<?php foreach (
    $survey['groups']
    as $gi => $group
): ?>
<div class="group-card drag"
     draggable="true"
     data-group>
<div class="group-header">
<strong>
グループ
</strong>

<div class="button-row">
<button type="button"
        class="btn btn-small btn-danger"
        onclick="removeGroup(this)">
グループ削除
</button>
</div>
</div>

<div class="question-body">

<input type="hidden"
       name="groups[<?= $gi ?>][id]"
       value="<?= h(
           $group['id']
       ) ?>">

<label>
<span>グループ名</span>
<input name="groups[<?= $gi ?>][title]"
       value="<?= h(
           $group['title']
       ) ?>">
</label>

<div class="questions"
     data-questions>

<?php foreach (
    $group['questions']
    as $qi => $question
): ?>
<div class="question-card drag"
     draggable="true"
     data-question>

<div class="question-header">
<div>
<strong>
<span data-number>
<?= h(
    $question['number']
) ?>
</span>
</strong>
<span class="muted">
<?= h(
    question_type_label(
        (string)$question['type']
    )
) ?>
</span>
</div>

<button type="button"
        class="btn btn-small btn-danger"
        onclick="removeQuestion(this)">
質問削除
</button>
</div>

<div class="question-body">

<input type="hidden"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][id]"
       value="<?= h(
           $question['id']
       ) ?>">

<label>
<span>質問文</span>
<textarea name="groups[<?= $gi ?>][questions][<?= $qi ?>][text]"
          maxlength="<?= MAX_QUESTION ?>"><?= h(
              $question['text']
          ) ?></textarea>
</label>

<div class="grid grid-2"
     style="margin-top:12px">

<label>
<span>回答形式</span>
<select name="groups[<?= $gi ?>][questions][<?= $qi ?>][type]"
        onchange="toggleOptions(this)">
<option value="single"
<?= selected(
    ($question['type'] ?? '')
    === 'single'
) ?>>
単一選択
</option>
<option value="multiple"
<?= selected(
    ($question['type'] ?? '')
    === 'multiple'
) ?>>
複数選択
</option>
<option value="text"
<?= selected(
    ($question['type'] ?? '')
    === 'text'
) ?>>
自由記述
</option>
</select>
</label>

<label>
<span>必須</span>
<select name="groups[<?= $gi ?>][questions][<?= $qi ?>][required]">
<option value="0"
<?= selected(
    empty(
        $question['required']
    )
) ?>>
任意
</option>
<option value="1"
<?= selected(
    !empty(
        $question['required']
    )
) ?>>
必須
</option>
</select>
</label>

</div>

<div class="options"
     style="margin-top:14px"
     data-options>

<?php foreach (
    $question['options']
    ?? []
    as $oi => $option
): ?>
<div class="option-row">
<input name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][id]"
       value="<?= h(
           $option['id']
       ) ?>">

<input name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][label]"
       value="<?= h(
           $option['label']
       ) ?>"
       placeholder="選択肢">

<input type="hidden"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][nextQuestionId]"
       value="<?= h(
           $option['nextQuestionId']
           ?? ''
       ) ?>">
</div>
<?php endforeach; ?>

</div>

<?php if (
    in_array(
        $question['type'] ?? '',
        ['single','multiple'],
        true
    )
): ?>
<div class="small muted">
単一選択の場合、条件分岐の設定値は保存データから利用できます。
</div>
<?php endif; ?>

</div>
</div>
<?php endforeach; ?>

</div>

<div class="button-row"
     style="margin-top:12px">
<button type="button"
        class="btn btn-secondary"
        onclick="addQuestion(this)">
質問を追加
</button>
</div>

</div>
</div>
<?php endforeach; ?>
</div>

<div class="sticky-actions">
<div class="button-row">

<button class="btn btn-primary">
保存
</button>

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen'=>'preview',
           'id'=>$survey['id']
       ])
   ) ?>">
プレビュー
</a>

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen'=>'list'
       ])
   ) ?>"
   onclick="return confirm('編集内容を破棄しますか？')">
破棄
</a>

</div>
</div>

</form>

</div>
</div>

<script>
function renumber(){
    const numbering =
        document.querySelector(
            'select[name="numbering"]'
        )?.value || 'global';

    let global = 1;

    document.querySelectorAll(
        '[data-group]'
    ).forEach((group,gi)=>{
        group.querySelectorAll(
            '[data-question]'
        ).forEach((question,qi)=>{
            const span =
                question.querySelector(
                    '[data-number]'
                );

            if(!span)return;

            span.textContent =
                numbering === 'group'
                    ? 'Q' + (gi+1) + '-' + (qi+1)
                    : 'Q' + global;

            global++;
        });
    });
}

function removeGroup(button){
    const group =
        button.closest('[data-group]');

    if(!group)return;

    if(!confirm('このグループを削除しますか？')){
        return;
    }

    group.remove();
    renumber();
}

function removeQuestion(button){
    const question =
        button.closest('[data-question]');

    if(!question)return;

    if(!confirm('この質問を削除しますか？')){
        return;
    }

    question.remove();
    renumber();
}

function toggleOptions(select){
    const question =
        select.closest('[data-question]');

    if(!question)return;

    const options =
        question.querySelector('[data-options]');

    if(!options)return;

    options.style.display =
        select.value === 'text'
            ? 'none'
            : 'block';
}

function addQuestion(button){
    const group =
        button.closest('[data-group]');

    if(!group)return;

    const questions =
        group.querySelector('[data-questions]');

    const gi =
        Array.from(
            document.querySelectorAll(
                '[data-group]'
            )
        ).indexOf(group);

    const qi =
        questions.querySelectorAll(
            '[data-question]'
        ).length;

    const wrapper =
        document.createElement('div');

    wrapper.className =
        'question-card drag';

    wrapper.draggable = true;

    wrapper.setAttribute(
        'data-question',
        ''
    );

    wrapper.innerHTML = `
<div class="question-header">
<div>
<strong><span data-number>Q</span></strong>
<span class="muted">質問</span>
</div>
<button type="button"
        class="btn btn-small btn-danger"
        onclick="removeQuestion(this)">
質問削除
</button>
</div>
<div class="question-body">
<input type="hidden"
       name="groups[${gi}][questions][${qi}][id]"
       value="question-${Date.now()}">
<label>
<span>質問文</span
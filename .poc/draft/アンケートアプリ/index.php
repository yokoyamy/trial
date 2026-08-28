<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 *
 * 単一エントリーポイント
 *
 * 重要:
 * - PHP 8.5で非対応の括弧なしネスト三項演算子を使用しない
 * - 業務処理はPHP POSTを基本とする
 * - JavaScriptはUI補助
 * - データはサーバー側JSONへ永続化
 * - kintone通信はPHP標準stream
 * - SMTP通信はPHP標準stream_socket_client
 * - 認証情報をHTML/JavaScript/URLへ出力しない
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT = 30;

const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;


/* =========================================================
 * 初期化
 * ========================================================= */

function app_init(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存フォルダを作成できません。');
        }
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, default_data());
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json(SETTINGS_FILE, default_settings());
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $https = false;

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $https = true;
        }

        if ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443) {
            $https = true;
        }

        session_name('survey_app_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => cookie_path(),
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('セッションを開始できません。');
        }
    }
}

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


/* =========================================================
 * デフォルトデータ
 * ========================================================= */

function default_data(): array
{
    $now = date('Y-m-d H:i:s');

    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'title' => '顧客満足度アンケート',
                'description' => 'サービスについてのご意見をお聞かせください。',
                'startAt' => date('Y-m-d\TH:i'),
                'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
                'status' => 'published',
                'numbering' => 'global',
                'createdAt' => $now,
                'updatedAt' => $now,
                'groups' => [
                    [
                        'id' => 'group-001',
                        'title' => '基本アンケート',
                        'questions' => [
                            [
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
                            ],
                            [
                                'id' => 'question-002',
                                'number' => 'Q2',
                                'text' => 'ご意見・ご要望があれば入力してください。',
                                'type' => 'text',
                                'required' => false,
                                'options' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
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


/* =========================================================
 * JSON
 * ========================================================= */

function load_json(string $file, array $fallback): array
{
    if (!is_file($file)) {
        return $fallback;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        return $fallback;
    }

    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($raw === false || trim($raw) === '') {
        return $fallback;
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return $fallback;
    }

    return $decoded;
}

function save_json(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('JSONデータを生成できません。');
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('ファイルをロックできません。');
        }

        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('保存ファイルを更新できません。');
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

function load_data(): array
{
    $data = load_json(DATA_FILE, default_data());

    $keys = [
        'surveys',
        'answers',
        'customers',
        'send_history',
    ];

    foreach ($keys as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
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
    $defaults = default_settings();
    $settings = load_json(SETTINGS_FILE, $defaults);

    if (!isset($settings['kintone']) || !is_array($settings['kintone'])) {
        $settings['kintone'] = [];
    }

    if (!isset($settings['mail']) || !is_array($settings['mail'])) {
        $settings['mail'] = [];
    }

    $settings['kintone'] = array_replace_recursive(
        $defaults['kintone'],
        $settings['kintone']
    );

    $settings['mail'] = array_replace_recursive(
        $defaults['mail'],
        $settings['mail']
    );

    return $settings;
}

function save_settings(array $settings): void
{
    save_json(SETTINGS_FILE, $settings);
}


/* =========================================================
 * 入出力
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
    $value = $_POST[$key] ?? '';

    if (!is_scalar($value)) {
        return '';
    }

    return trim((string)$value);
}

function get_string(string $key): string
{
    $value = $_GET[$key] ?? '';

    if (!is_scalar($value)) {
        return '';
    }

    return trim((string)$value);
}

function post_array(string $key): array
{
    $value = $_POST[$key] ?? [];

    if (!is_array($value)) {
        return [];
    }

    return $value;
}

function post_bool(string $key): bool
{
    if (!isset($_POST[$key])) {
        return false;
    }

    return in_array(
        (string)$_POST[$key],
        ['1', 'on', 'true'],
        true
    );
}

function app_url(array $params = []): string
{
    $base = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php');

    if (!$params) {
        return $base;
    }

    return $base . '?' .
        http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}

function public_answer_url(string $surveyId): string
{
    $scheme = 'http';

    if (
        !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off'
    ) {
        $scheme = 'https';
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        return app_url([
            'screen' => 'answer',
            'id' => $surveyId,
        ]);
    }

    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');

    return $scheme . '://' .
        $host .
        $path .
        '?screen=answer&id=' .
        rawurlencode($surveyId);
}

function redirect_screen(string $screen, array $params = []): never
{
    $params = array_merge(
        ['screen' => $screen],
        $params
    );

    header('Location: ' . app_url($params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    if (!is_array($flash)) {
        return null;
    }

    return $flash;
}


/* =========================================================
 * 共通データ処理
 * ========================================================= */

function uuid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(6));
}

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $index => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function survey_by_id(array $surveys, string $id): ?array
{
    $index = survey_index($surveys, $id);

    if ($index < 0) {
        return null;
    }

    return $surveys[$index];
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

function auto_update_status(array &$survey): bool
{
    $status = (string)($survey['status'] ?? '');

    if ($status !== 'published') {
        return false;
    }

    $endAt = (string)($survey['endAt'] ?? '');

    if ($endAt === '') {
        return false;
    }

    $timestamp = strtotime($endAt);

    if ($timestamp === false) {
        return false;
    }

    if ($timestamp >= time()) {
        return false;
    }

    $survey['status'] = 'ended';

    return true;
}

function refresh_statuses(array &$data): bool
{
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (auto_update_status($survey)) {
            $survey['updatedAt'] = date('Y-m-d H:i:s');
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function recalc_numbers(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            $numbering = (string)($survey['numbering'] ?? 'global');

            if ($numbering === 'group') {
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

function status_label(string $status): string
{
    switch ($status) {
        case 'published':
            return '公開中';

        case 'stopped':
            return '停止';

        case 'ended':
            return '終了';

        default:
            return '下書き';
    }
}

function status_class(string $status): string
{
    switch ($status) {
        case 'published':
            return 'success';

        case 'stopped':
            return 'warning';

        case 'ended':
            return 'gray';

        default:
            return 'gray';
    }
}

function type_label(string $type): string
{
    switch ($type) {
        case 'single':
            return '単一選択';

        case 'multiple':
            return '複数選択';

        default:
            return '自由記述';
    }
}


/* =========================================================
 * アンケート入力検証
 * ========================================================= */

function validate_survey_input(): array
{
    $title = post_string('title');
    $description = post_string('description');
    $startAt = post_string('startAt');
    $endAt = post_string('endAt');
    $numbering = post_string('numbering');

    $errors = [];

    if ($title === '') {
        $errors[] = 'アンケートタイトルは必須です。';
    }

    if (mb_strlen($title) > MAX_TITLE) {
        $errors[] = 'アンケートタイトルが長すぎます。';
    }

    if (mb_strlen($description) > MAX_DESCRIPTION) {
        $errors[] = 'アンケート説明が長すぎます。';
    }

    if (!in_array($numbering, ['global', 'group'], true)) {
        $errors[] = '質問番号の採番方式が不正です。';
    }

    if ($startAt !== '' && strtotime($startAt) === false) {
        $errors[] = '開始日時が不正です。';
    }

    if ($endAt !== '' && strtotime($endAt) === false) {
        $errors[] = '終了日時が不正です。';
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) !== false
        && strtotime($endAt) !== false
        && strtotime($endAt) < strtotime($startAt)
    ) {
        $errors[] = '終了日時は開始日時以降にしてください。';
    }

    return [
        'errors' => $errors,
        'title' => $title,
        'description' => $description,
        'startAt' => $startAt,
        'endAt' => $endAt,
        'numbering' => $numbering,
    ];
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

    $value = rtrim($value, '/');

    if (str_ends_with(
        strtolower($value),
        '.cybozu.com'
    )) {
        return substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    return $value;
}

function validate_kintone_config(
    array $config,
    bool $requirePassword = true
): array {
    $errors = [];

    $subdomain = normalize_kintone_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    if (
        $subdomain === ''
        || !preg_match(
            '/^[a-zA-Z0-9][a-zA-Z0-9-]*$/',
            $subdomain
        )
    ) {
        $errors[] = 'kintoneサブドメインが不正です。';
    }

    $appId = (string)($config['app_id'] ?? '');

    if (
        !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] = 'kintoneアプリIDが不正です。';
    }

    if (
        trim((string)($config['username'] ?? '')) === ''
    ) {
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
    $errors = validate_kintone_config(
        $config,
        true
    );

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $subdomain = normalize_kintone_subdomain(
        (string)$config['subdomain']
    );

    $url =
        'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;

    $username = (string)$config['username'];
    $password = (string)$config['password'];

    $authorization = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException(
                'kintoneリクエストを生成できません。'
            );
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $verify = !empty($config['verify_ssl']);

    $ssl = [
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'allow_self_signed' => !$verify,
        'SNI_enabled' => true,
        'peer_name' => $subdomain . '.cybozu.com',
    ];

    $http = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'ignore_errors' => true,
        'timeout' => KINTONE_READ_TIMEOUT,
        'follow_location' => 0,
        'max_redirects' => 0,
    ];

    $proxy = trim(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy !== '') {
        $parts = explode(':', $proxy, 2);

        if (count($parts) !== 2) {
            throw new RuntimeException(
                'Proxyはhost:port形式で入力してください。'
            );
        }

        $http['proxy'] =
            'tcp://' .
            $parts[0] .
            ':' .
            (int)$parts[1];

        $http['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $http,
        'ssl' => $ssl,
    ]);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $match
            )
        ) {
            $status = (int)$match[1];
            break;
        }
    }

    $responseBody = '';

    if ($response !== false) {
        $responseBody = $response;
    }

    $json = json_decode(
        $responseBody,
        true
    );

    if ($status < 200 || $status >= 300) {
        $code = '';
        $message = '';

        if (is_array($json)) {
            $code = (string)($json['code'] ?? '');
            $message = (string)($json['message'] ?? '');
        }

        $detail = 'kintone APIエラー';

        if ($code !== '') {
            $detail .= ' [' . $code . ']';
        }

        if ($message !== '') {
            $detail .= ' ' . $message;
        }

        $detail .= ' HTTP ' . $status;

        throw new RuntimeException($detail);
    }

    return [
        'status' => $status,
        'body' => is_array($json) ? $json : [],
        'raw' => $responseBody,
    ];
}

function kintone_test(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id=' .
        rawurlencode((string)$config['app_id'])
    );
}

function kintone_fields(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode((string)$config['app_id'])
    );
}

function kintone_records(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode((string)$config['app_id']) .
        '&totalCount=true'
    );
}

function normalize_kintone_fields(array $response): array
{
    $properties = $response['properties'] ?? [];

    if (!is_array($properties)) {
        return [];
    }

    $fields = [];

    foreach ($properties as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' => (string)(
                $field['label'] ?? $code
            ),
            'type' => (string)(
                $field['type'] ?? ''
            ),
        ];
    }

    usort(
        $fields,
        static function (
            array $a,
            array $b
        ): int {
            return strnatcasecmp(
                $a['code'],
                $b['code']
            );
        }
    );

    return $fields;
}

function kintone_record_value(
    array $record,
    string $code
): string {
    if (
        !isset($record[$code])
        || !is_array($record[$code])
    ) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (!is_array($value)) {
        return (string)$value;
    }

    $parts = [];

    foreach ($value as $item) {
        if (is_array($item)) {
            if (isset($item['value'])) {
                $parts[] = (string)$item['value'];
            } elseif (isset($item['name'])) {
                $parts[] = (string)$item['name'];
            }
        } else {
            $parts[] = (string)$item;
        }
    }

    return implode(' ', $parts);
}


/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail_config(
    array $config,
    bool $requirePassword = false
): array {
    $errors = [];

    $host = trim(
        (string)($config['host'] ?? '')
    );

    if ($host === '') {
        $errors[] = 'SMTPサーバを入力してください。';
    }

    $port = (int)($config['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        $errors[] = 'SMTPポートが不正です。';
    }

    $encryption = (string)(
        $config['encryption'] ?? 'tls'
    );

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        $errors[] = '暗号化方式が不正です。';
    }

    if (!empty($config['auth'])) {
        if (
            trim((string)($config['username'] ?? '')) === ''
        ) {
            $errors[] = 'SMTPユーザー名を入力してください。';
        }

        if (
            $requirePassword
            && trim((string)($config['password'] ?? '')) === ''
        ) {
            $errors[] = 'SMTPパスワードを入力してください。';
        }
    }

    $from = trim(
        (string)($config['from_email'] ?? '')
    );

    if (
        $from === ''
        || !filter_var($from, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    $reply = trim(
        (string)($config['reply_to'] ?? '')
    );

    if (
        $reply !== ''
        && !filter_var(
            $reply,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '返信先メールアドレスが不正です。';
    }

    return $errors;
}

function smtp_read($socket): string
{
    $response = '';

    while (($line = fgets($socket)) !== false) {
        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([ -])/',
                $line,
                $match
            )
        ) {
            if ($match[2] === ' ') {
                break;
            }
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPサーバから応答がありません。'
        );
    }

    return $response;
}

function smtp_expect(
    $socket,
    array $codes
): string {
    $response = smtp_read($socket);

    if (
        !preg_match(
            '/^(\d{3})/',
            $response,
            $match
        )
    ) {
        throw new RuntimeException(
            'SMTP応答を解析できません。'
        );
    }

    $code = (int)$match[1];

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . $code
        );
    }

    return $response;
}

function smtp_write(
    $socket,
    string $command
): void {
    $written = fwrite(
        $socket,
        $command . "\r\n"
    );

    if ($written === false) {
        throw new RuntimeException(
            'SMTPへの送信に失敗しました。'
        );
    }
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): string {
    smtp_write($socket, $command);

    return smtp_expect(
        $socket,
        $codes
    );
}

function smtp_open(array $config)
{
    $errors = validate_mail_config(
        $config,
        true
    );

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $host = trim(
        (string)$config['host']
    );

    $port = (int)$config['port'];

    $encryption = (string)(
        $config['encryption'] ?? 'tls'
    );

    $target = $host;

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target . ':' . $port,
        $errno,
        $errstr,
        KINTONE_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。' .
            ' ' .
            $errstr
        );
    }

    stream_set_timeout(
        $socket,
        KINTONE_READ_TIMEOUT
    );

    smtp_expect($socket, [220]);

    $localHost =
        (string)($_SERVER['SERVER_NAME'] ?? 'localhost');

    smtp_command(
        $socket,
        'EHLO ' . $localHost,
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
                'SMTP TLS接続を確立できません。'
            );
        }

        smtp_command(
            $socket,
            'EHLO ' . $localHost,
            [250]
        );
    }

    if (!empty($config['auth'])) {
        $username = (string)(
            $config['username'] ?? ''
        );

        $password = (string)(
            $config['password'] ?? ''
        );

        if (
            $username === ''
            || $password === ''
        ) {
            fclose($socket);

            throw new RuntimeException(
                'SMTP認証情報を入力してください。'
            );
        }

        smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $socket,
            base64_encode($username),
            [334]
        );

        smtp_command(
            $socket,
            base64_encode($password),
            [235]
        );
    }

    return $socket;
}

function smtp_test(array $config): void
{
    $socket = smtp_open($config);

    smtp_command(
        $socket,
        'QUIT',
        [221]
    );

    fclose($socket);
}

function smtp_send(
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

    $from = trim(
        (string)$config['from_email']
    );

    if (
        !filter_var(
            $from,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    $socket = smtp_open($config);

    smtp_command(
        $socket,
        'MAIL FROM:<' . $from . '>',
        [250]
    );

    smtp_command(
        $socket,
        'RCPT TO:<' . $to . '>',
        [250, 251]
    );

    smtp_command(
        $socket,
        'DATA',
        [354]
    );

    $fromName = trim(
        (string)($config['from_name'] ?? '')
    );

    if ($fromName === '') {
        $fromName = $from;
    }

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' .
            mb_encode_mimeheader($fromName) .
            ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' .
            mb_encode_mimeheader($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $replyTo = trim(
        (string)($config['reply_to'] ?? '')
    );

    if ($replyTo !== '') {
        $headers[] =
            'Reply-To: ' . $replyTo;
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
        $safeBody .
        "\r\n.";

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

    fclose($socket);
}


/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): void {
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        !== 'POST'
    ) {
        return;
    }

    $action = post_string('action');

    try {
        switch ($action) {

            /* =========================================
             * アンケート保存
             * ========================================= */

            case 'save_survey':
                handle_save_survey($data);
                return;

            case 'change_status':
                handle_change_status($data);
                return;

            case 'add_group':
                handle_add_group($data);
                return;

            case 'delete_group':
                handle_delete_group($data);
                return;

            case 'add_question':
                handle_add_question($data);
                return;

            case 'delete_question':
                handle_delete_question($data);
                return;

            case 'save_question':
                handle_save_question($data);
                return;

            case 'reorder':
                handle_reorder($data);
                return;

            case 'duplicate_survey':
                handle_duplicate($data);
                return;

            case 'delete_survey':
                handle_delete_survey($data);
                return;

            /* =========================================
             * kintone
             * ========================================= */

            case 'save_kintone':
                handle_save_kintone($settings);
                return;

            case 'test_kintone':
                handle_test_kintone($settings);
                return;

            case 'fetch_kintone_fields':
                handle_fetch_kintone_fields($settings);
                return;

            case 'sync_kintone':
                handle_sync_kintone($data, $settings);
                return;

            /* =========================================
             * SMTP
             * ========================================= */

            case 'save_mail':
                handle_save_mail($settings);
                return;

            case 'test_mail':
                handle_test_mail($settings);
                return;

            case 'send_test_mail':
                handle_send_test_mail($settings);
                return;

            /* =========================================
             * 顧客メール
             * ========================================= */

            case 'send_mail':
                handle_send_mail(
                    $data,
                    $settings
                );
                return;

            /* =========================================
             * 回答
             * ========================================= */

            case 'answer_next':
                handle_answer_next($data);
                return;

            case 'answer_confirm':
                handle_answer_confirm($data);
                return;

            case 'answer_submit':
                handle_answer_submit($data);
                return;

            default:
                flash(
                    'error',
                    '不明な操作です。'
                );
                return;
        }
    } catch (Throwable $e) {
        flash(
            'error',
            safe_error_message($e)
        );
    }
}

function safe_error_message(Throwable $e): string
{
    $message = trim($e->getMessage());

    if ($message === '') {
        return '処理に失敗しました。';
    }

    return $message;
}


/* =========================================================
 * アンケート保存
 * ========================================================= */

function handle_save_survey(array &$data): void
{
    $input = validate_survey_input();

    if ($input['errors']) {
        flash(
            'error',
            implode("\n", $input['errors'])
        );

        return;
    }

    $id = post_string('survey_id');

    $index = survey_index(
        $data['surveys'],
        $id
    );

    if ($index < 0) {
        $survey = [
            'id' => uuid('survey'),
            'title' => $input['title'],
            'description' => $input['description'],
            'startAt' => $input['startAt'],
            'endAt' => $input['endAt'],
            'status' => 'draft',
            'numbering' => $input['numbering'],
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
            'groups' => [],
        ];
    } else {
        $survey = $data['surveys'][$index];

        $survey['title'] = $input['title'];
        $survey['description'] = $input['description'];
        $survey['startAt'] = $input['startAt'];
        $survey['endAt'] = $input['endAt'];
        $survey['numbering'] = $input['numbering'];
        $survey['updatedAt'] =
            date('Y-m-d H:i:s');
    }

    if (
        !isset($survey['groups'])
        || !is_array($survey['groups'])
    ) {
        $survey['groups'] = [];
    }

    recalc_numbers($survey);

    if ($index < 0) {
        $data['surveys'][] = $survey;
    } else {
        $data['surveys'][$index] = $survey;
    }

    save_data($data);

    flash(
        'success',
        'アンケートを保存しました。'
    );

    redirect_screen('list');
}


/* =========================================================
 * 状態変更
 * ========================================================= */

function handle_change_status(
    array &$data
): void {
    $id = post_string('survey_id');
    $next = post_string('next_status');

    $index = survey_index(
        $data['surveys'],
        $id
    );

    if ($index < 0) {
        flash(
            'error',
            'アンケートが見つかりません。'
        );

        return;
    }

    $survey = $data['surveys'][$index];

    auto_update_status($survey);

    $current = (string)(
        $survey['status'] ?? 'draft'
    );

    $allowed = [
        'draft' => 'published',
        'published' => 'stopped',
        'stopped' => 'published',
    ];

    if (
        $current === 'ended'
        || !isset($allowed[$current])
        || $allowed[$current] !== $next
    ) {
        flash(
            'error',
            '指定された状態変更はできません。'
        );

        return;
    }

    $survey['status'] = $next;
    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    $data['surveys'][$index] = $survey;

    save_data($data);

    flash(
        'success',
        '状態を変更しました。'
    );

    $returnScreen = post_string(
        'return_screen'
    );

    if ($returnScreen === 'edit') {
        redirect_screen(
            'edit',
            ['id' => $id]
        );
    }

    redirect_screen('list');
}


/* =========================================================
 * グループ操作
 * ========================================================= */

function handle_add_group(
    array &$data
): void {
    $surveyId = post_string('survey_id');

    $index = survey_index(
        $data['surveys'],
        $surveyId
    );

    if ($index < 0) {
        flash(
            'error',
            'アンケートが見つかりません。'
        );

        return;
    }

    $survey = $data['surveys'][$index];

    $survey['groups'][] = [
        'id' => uuid('group'),
        'title' => '新しいグループ',
        'questions' => [],
    ];

    recalc_numbers($survey);

    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    $data['surveys'][$index] = $survey;

    save_data($data);

    flash(
        'success',
        'グループを追加しました。'
    );

    redirect_screen(
        'edit',
        ['id' => $surveyId]
    );
}

function handle_delete_group(
    array &$data
): void {
    $surveyId = post_string('survey_id');
    $groupId = post_string('group_id');

    $index = survey_index(
        $data['surveys'],
        $surveyId
    );

    if ($index < 0) {
        flash(
            'error',
            'アンケートが見つかりません。'
        );

        return;
    }

    $survey = $data['surveys'][$index];
    $newGroups = [];

    foreach ($survey['groups'] as $group) {
        if (($group['id'] ?? '') !== $groupId) {
            $newGroups[] = $group;
        }
    }

    $survey['groups'] = $newGroups;

    recalc_numbers($survey);

    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    $data['surveys'][$index] = $survey;

    save_data($data);

    flash(
        'success',
        'グループを削除しました。'
    );

    redirect_screen(
        'edit',
        ['id' => $surveyId]
    );
}


/* =========================================================
 * 質問操作
 * ========================================================= */

function handle_add_question(
    array &$data
): void {
    $surveyId = post_string('survey_id');
    $groupId = post_string('group_id');

    $index = survey_index(
        $data['surveys'],
        $surveyId
    );

    if ($index < 0) {
        flash(
            'error',
            'アンケートが見つかりません。'
        );

        return;
    }

    $survey = $data['surveys'][$index];

    foreach ($survey['groups'] as &$group) {
        if (($group['id'] ?? '') !== $groupId) {
            continue;
        }

        $group['questions'][] = [
            'id' => uuid('question'),
            'number' => '',
            'text' => '新しい質問',
            'type' => 'single',
            'required' => false,
            'options' => [
                [
                    'id' => uuid('option'),
                    'label' => '選択肢1',
                    'nextQuestionId' => '',
                ],
                [
                    'id' => uuid('option'),
                    'label' => '選択肢2',
                    'nextQuestionId' => '',
                ],
            ],
        ];
    }

    unset($group);

    recalc_numbers($survey);

    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    $data['surveys'][$index] = $survey;

    save_data($data);

    flash(
        'success',
        '質問を追加しました。'
    );

    redirect_screen(
        'edit',
        ['id' => $surveyId]
    );
}

function handle_delete_question(
    array &$data
): void {
    $surveyId = post_string('survey_id');
    $questionId = post_string('question_id');

    $index = survey_index(
        $data['surveys'],
        $surveyId
    );

    if ($index < 0) {
        flash(
            'error',
            'アンケートが見つかりません。'
        );

        return;
    }

    $survey = $data['surveys'][$index];

    foreach ($survey['groups'] as &$group) {
        $questions = [];

        foreach ($group['questions'] as $question) {
            if (
                ($question['id'] ?? '')
                !== $questionId
            ) {
                $questions[] = $question;
            }
        }

        $group['questions'] = $questions;
    }

    unset($group);

    recalc_numbers($survey);

    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    $data['surveys'][$index] = $survey;

    save_data($data);

    flash(
        'success',
        '質問を削除しました。'
    );

    redirect_screen(
        'edit',
        ['id' => $surveyId]
    );
}

function handle_save_question(
    array &$data
): void {
    $surveyId = post_string('survey_id');
    $questionId = post_string('question_id');

    $index = survey_index(
        $data['surveys'],
        $surveyId
    );

    if ($index < 0) {
        flash(
            'error',
            'アンケートが見つかりません。'
        );

        return;
    }

    $survey = $data['surveys'][$index];

    $text = post_string('question_text');
    $type = post_string('question_type');
    $required = post_bool('question_required');

    if ($text === '') {
        flash(
            'error',
            '質問文は必須です。'
        );

        return;
    }

    if (!in_array(
        $type,
        ['single', 'multiple', 'text'],
        true
    )) {
        flash(
            'error',
            '回答形式が不正です。'
        );

        return;
    }

    $optionLabels = post_array(
        'option_label'
    );

    $optionIds = post_array(
        'option_id'
    );

    $nextIds = post_array(
        'option_next'
    );

    $options = [];

    foreach ($optionLabels as $key => $label) {
        $label = trim((string)$label);

        if ($label === '') {
            continue;
        }

        $optionId = '';

        if (isset($optionIds[$key])) {
            $optionId = trim(
                (string)$optionIds[$key]
            );
        }

        if ($optionId === '') {
            $optionId = uuid('option');
        }

        $nextQuestionId = '';

        if (isset($nextIds[$key])) {
            $nextQuestionId = trim(
                (string)$nextIds[$key]
            );
        }

        $options[] = [
            'id' => $optionId,
            'label' => $label,
            'nextQuestionId' => $nextQuestionId,
        ];
    }

    foreach ($survey['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            if (
                ($question['id'] ?? '')
                !== $questionId
            ) {
                continue;
            }

            $question['text'] = $text;
            $question['type'] = $type;
            $question['required'] = $required;

            if (
                $type === 'single'
                || $type === 'multiple'
            ) {
                $question['options'] = $options;
            } else {
                $question['options'] = [];
            }
        }
    }

    unset($question);
    unset($group);

    recalc_numbers($survey);

    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    $data['surveys'][$index] = $survey;

    save_data($data);

    flash(
        'success',
        '質問を保存しました。'
    );

    redirect_screen(
        'edit',
        ['id' => $surveyId]
    );
}


/* =========================================================
 * D&D並び替え
 * ========================================================= */

function handle_reorder(
    array &$data
): void {
    $surveyId = post_string('survey_id');
    $groupOrderRaw = post_string(
        'group_order'
    );

    $index = survey_index(
        $data['surveys'],
        $surveyId
    );

    if ($index < 0) {
        flash(
            'error',
            'アンケートが見つかりません。'
        );

        return;
    }

    $survey = $data['surveys'][$index];

    if ($groupOrderRaw !== '') {
        $groupOrder = json_decode(
            $groupOrderRaw,
            true
        );

        if (is_array($groupOrder)) {
            $byId = [];

            foreach ($survey['groups'] as $group) {
                $byId[(string)$group['id']] =
                    $group;
            }

            $newGroups = [];

            foreach ($groupOrder as $groupId) {
                $groupId = (string)$groupId;

                if (isset($byId[$groupId])) {
                    $newGroups[] =
                        $byId[$groupId];

                    unset($byId[$groupId]);
                }
            }

            foreach ($byId as $group) {
                $newGroups[] = $group;
            }

            $survey['groups'] = $newGroups;
        }
    }

    foreach ($survey['groups'] as &$group) {
        $key = 'question_order_' .
            (string)$group['id'];

        $raw = post_string($key);

        if ($raw === '') {
            continue;
        }

        $order = json_decode(
            $raw,
            true
        );

        if (!is_array($order)) {
            continue;
        }

        $byId = [];

        foreach ($group['questions'] as $question) {
            $byId[(string)$question['id']] =
                $question;
        }

        $newQuestions = [];

        foreach ($order as $questionId) {
            $questionId = (string)$questionId;

            if (isset($byId[$questionId])) {
                $newQuestions[] =
                    $byId[$questionId];

                unset($byId[$questionId]);
            }
        }

        foreach ($byId as $question) {
            $newQuestions[] = $question;
        }

        $group['questions'] = $newQuestions;
    }

    unset($group);

    recalc_numbers($survey);

    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    $data['surveys'][$index] = $survey;

    save_data($data);

    flash(
        'success',
        '並び順を保存しました。'
    );

    redirect_screen(
        'edit',
        ['id' => $surveyId]
    );
}


/* =========================================================
 * 複製・削除
 * ========================================================= */

function handle_duplicate(
    array &$data
): void {
    $id = post_string('survey_id');

    $survey = survey_by_id(
        $data['surveys'],
        $id
    );

    if ($survey === null) {
        flash(
            'error',
            'アンケートが見つかりません。'
        );

        return;
    }

    $survey['id'] = uuid('survey');
    $survey['title'] .= '（コピー）';
    $survey['status'] = 'draft';
    $survey['createdAt'] =
        date('Y-m-d H:i:s');
    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    $data['surveys'][] = $survey;

    save_data($data);

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirect_screen('list');
}

function handle_delete_survey(
    array &$data
): void {
    $id = post_string('survey_id');

    $index = survey_index(
        $data['surveys'],
        $id
    );

    if ($index < 0) {
        flash(
            'error',
            'アンケートが見つかりません。'
        );

        return;
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

    redirect_screen('list');
}


/* =========================================================
 * kintone設定
 * ========================================================= */

function handle_save_kintone(
    array &$settings
): void {
    $current = $settings['kintone'];

    $password = post_string('password');

    $config = [
        'subdomain' => post_string(
            'subdomain'
        ),
        'app_id' => post_string(
            'app_id'
        ),
        'username' => post_string(
            'username'
        ),
        'password' => $password,
        'proxy' => post_string(
            'proxy'
        ),
        'verify_ssl' => post_bool(
            'verify_ssl'
        ),
        'mapping' =>
            $current['mapping'] ?? [],
        'fields' =>
            $current['fields'] ?? [],
        'last_test' =>
            $current['last_test'] ?? null,
        'last_sync' =>
            $current['last_sync'] ?? null,
    ];

    if ($password === '') {
        $config['password'] =
            (string)($current['password'] ?? '');
    }

    $errors = validate_kintone_config(
        $config,
        false
    );

    if ($errors) {
        flash(
            'error',
            implode("\n", $errors)
        );

        return;
    }

    save_kintone_mapping_from_post(
        $config
    );

    $settings['kintone'] = $config;

    save_settings($settings);

    flash(
        'success',
        'kintone設定を保存しました。'
    );
}

function save_kintone_mapping_from_post(
    array &$config
): void {
    $mapping = [
        'organization' => post_string(
            'mapping_organization'
        ),
        'name' => post_string(
            'mapping_name'
        ),
        'email' => post_string(
            'mapping_email'
        ),
        'department' => post_string(
            'mapping_department'
        ),
        'phone' => post_string(
            'mapping_phone'
        ),
        'address' => [],
    ];

    $address = post_array(
        'mapping_address'
    );

    foreach ($address as $code) {
        $code = trim((string)$code);

        if ($code !== '') {
            $mapping['address'][] = $code;
        }
    }

    $config['mapping'] = $mapping;
}

function handle_test_kintone(
    array &$settings
): void {
    $config = $settings['kintone'];

    $password = post_string('password');

    if ($password !== '') {
        $config['password'] = $password;
    }

    $errors = validate_kintone_config(
        $config,
        true
    );

    if ($errors) {
        flash(
            'error',
            implode("\n", $errors)
        );

        return;
    }

    kintone_test($config);

    $settings['kintone']['last_test'] =
        date('Y-m-d H:i:s');

    if ($password !== '') {
        $settings['kintone']['password'] =
            $password;
    }

    save_settings($settings);

    flash(
        'success',
        'kintone接続テストに成功しました。'
    );
}

function handle_fetch_kintone_fields(
    array &$settings
): void {
    $config = $settings['kintone'];

    $password = post_string('password');

    if ($password !== '') {
        $config['password'] = $password;
    }

    $result = kintone_fields($config);

    $fields = normalize_kintone_fields(
        $result['body']
    );

    $settings['kintone']['fields'] =
        $fields;

    if ($password !== '') {
        $settings['kintone']['password'] =
            $password;
    }

    save_settings($settings);

    flash(
        'success',
        'kintoneの項目一覧を取得しました。'
    );
}

function handle_sync_kintone(
    array &$data,
    array &$settings
): void {
    $config = $settings['kintone'];

    $password = post_string('password');

    if ($password !== '') {
        $config['password'] = $password;
    }

    $mapping = $config['mapping'] ?? [];

    $recordsResponse = kintone_records(
        $config
    );

    $records = $recordsResponse['body']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $addressParts = [];

        $addressCodes = $mapping['address'] ?? [];

        if (!is_array($addressCodes)) {
            $addressCodes = [];
        }

        foreach ($addressCodes as $code) {
            $code = (string)$code;

            if ($code === '') {
                continue;
            }

            $value = kintone_record_value(
                $record,
                $code
            );

            if ($value !== '') {
                $addressParts[] = $value;
            }
        }

        $customers[] = [
            'id' => uuid('customer'),
            'organization' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['organization'] ?? ''
                    )
                ),
            'name' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['name'] ?? ''
                    )
                ),
            'email' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['email'] ?? ''
                    )
                ),
            'department' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['department'] ?? ''
                    )
                ),
            'phone' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['phone'] ?? ''
                    )
                ),
            'address' =>
                implode('', $addressParts),
        ];
    }

    $data['customers'] = $customers;

    if ($password !== '') {
        $settings['kintone']['password'] =
            $password;
    }

    $settings['kintone']['last_sync'] =
        date('Y-m-d H:i:s');

    save_data($data);
    save_settings($settings);

    flash(
        'success',
        count($customers) .
        '件の顧客情報を同期しました。'
    );
}


/* =========================================================
 * SMTP設定
 * ========================================================= */

function handle_save_mail(
    array &$settings
): void {
    $current = $settings['mail'];

    $password = post_string('password');

    $config = [
        'host' => post_string('host'),
        'port' => (int)post_string('port'),
        'encryption' => post_string(
            'encryption'
        ),
        'auth' => post_bool('auth'),
        'username' => post_string(
            'username'
        ),
        'password' => $password,
        'from_email' => post_string(
            'from_email'
        ),
        'from_name' => post_string(
            'from_name'
        ),
        'reply_to' => post_string(
            'reply_to'
        ),
        'last_test' =>
            $current['last_test'] ?? null,
    ];

    if ($password === '') {
        $config['password'] =
            (string)($current['password'] ?? '');
    }

    $errors = validate_mail_config(
        $config,
        false
    );

    if ($errors) {
        flash(
            'error',
            implode("\n", $errors)
        );

        return;
    }

    $settings['mail'] = $config;

    save_settings($settings);

    flash(
        'success',
        'SMTP設定を保存しました。'
    );
}

function handle_test_mail(
    array &$settings
): void {
    $config = $settings['mail'];

    $password = post_string('password');

    if ($password !== '') {
        $config['password'] = $password;
    }

    smtp_test($config);

    $settings['mail']['last_test'] =
        date('Y-m-d H:i:s');

    if ($password !== '') {
        $settings['mail']['password'] =
            $password;
    }

    save_settings($settings);

    flash(
        'success',
        'SMTP接続・認証テストに成功しました。'
    );
}

function handle_send_test_mail(
    array &$settings
): void {
    $config = $settings['mail'];

    $password = post_string('password');

    if ($password !== '') {
        $config['password'] = $password;
    }

    $to = post_string('test_email');

    smtp_send(
        $config,
        $to,
        'アンケートアプリ テストメール',
        "SMTP接続テストに成功しました。\n"
        . date('Y-m-d H:i:s')
    );

    $settings['mail']['last_test'] =
        date('Y-m-d H:i:s');

    if ($password !== '') {
        $settings['mail']['password'] =
            $password;
    }

    save_settings($settings);

    flash(
        'success',
        'テストメールを送信しました。'
    );
}


/* =========================================================
 * 顧客メール
 * ========================================================= */

function handle_send_mail(
    array &$data,
    array &$settings
): void {
    $surveyId = post_string('survey_id');

    $survey = survey_by_id(
        $data['surveys'],
        $surveyId
    );

    if ($survey === null) {
        flash(
            'error',
            '対象アンケートが見つかりません。'
        );

        return;
    }

    $customerIds = post_array(
        'customer_ids'
    );

    if (!$customerIds) {
        flash(
            'error',
            '送信対象の顧客を選択してください。'
        );

        return;
    }

    $subject = post_string('subject');
    $body = post_string('body');

    if ($subject === '' || $body === '') {
        flash(
            'error',
            'メール件名と本文を入力してください。'
        );

        return;
    }

    $mailConfig = $settings['mail'];

    $successCount = 0;
    $failureCount = 0;

    foreach ($data['customers'] as $customer) {
        if (
            !in_array(
                (string)($customer['id'] ?? ''),
                array_map(
                    'strval',
                    $customerIds
                ),
                true
            )
        ) {
            continue;
        }

        $customerName = (string)(
            $customer['name'] ?? ''
        );

        $email = (string)(
            $customer['email'] ?? ''
        );

        if (
            $email === ''
            || !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $failureCount++;
            continue;
        }

        $personalSubject =
            str_replace(
                '{顧客名}',
                $customerName,
                $subject
            );

        $personalBody =
            str_replace(
                '{顧客名}',
                $customerName,
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
            smtp_send(
                $mailConfig,
                $email,
                $personalSubject,
                $personalBody
            );

            $successCount++;

            $data['send_history'][] = [
                'id' => uuid('send'),
                'survey_id' => $surveyId,
                'customer_id' =>
                    (string)$customer['id'],
                'email' => $email,
                'sentAt' =>
                    date('Y-m-d H:i:s'),
                'status' => 'success',
            ];
        } catch (Throwable $e) {
            $failureCount++;

            $data['send_history'][] = [
                'id' => uuid('send'),
                'survey_id' => $surveyId,
                'customer_id' =>
                    (string)$customer['id'],
                'email' => $email,
                'sentAt' =>
                    date('Y-m-d H:i:s'),
                'status' => 'failure',
                'error' =>
                    safe_error_message($e),
            ];
        }
    }

    save_data($data);

    flash(
        'success',
        '送信完了：成功 ' .
        $successCount .
        '件 / 失敗 ' .
        $failureCount .
        '件'
    );

    redirect_screen(
        'send',
        ['id' => $surveyId]
    );
}


/* =========================================================
 * 回答フロー
 * ========================================================= */

function answer_questions(
    array $survey
): array {
    $questions = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $questions[] = $question;
        }
    }

    return $questions;
}

function validate_answers(
    array $survey,
    array $answers
): array {
    $errors = [];

    foreach (
        answer_questions($survey)
        as $question
    ) {
        $id = (string)(
            $question['id'] ?? ''
        );

        if (
            empty($question['required'])
        ) {
            continue;
        }

        $value = $answers[$id] ?? '';

        if (is_array($value)) {
            if (!$value) {
                $errors[] =
                    ($question['number'] ?? '') .
                    ' は必須です。';
            }
        } else {
            if (trim((string)$value) === '') {
                $errors[] =
                    ($question['number'] ?? '') .
                    ' は必須です。';
            }
        }
    }

    return $errors;
}

function handle_answer_next(
    array &$data
): void {
    $surveyId = post_string('survey_id');

    $survey = survey_by_id(
        $data['surveys'],
        $surveyId
    );

    if ($survey === null) {
        redirect_screen('list');
    }

    $answers = post_array('answers');

    $errors = validate_answers(
        $survey,
        $answers
    );

    if ($errors) {
        flash(
            'error',
            implode("\n", $errors)
        );

        redirect_screen(
            'answer',
            ['id' => $surveyId]
        );
    }

    $_SESSION['answer_draft'][$surveyId] =
        $answers;

    redirect_screen(
        'confirm',
        ['id' => $surveyId]
    );
}

function handle_answer_confirm(
    array &$data
): void {
    $surveyId = post_string('survey_id');

    $survey = survey_by_id(
        $data['surveys'],
        $surveyId
    );

    if ($survey === null) {
        redirect_screen('list');
    }

    $answers = $_SESSION['answer_draft'][$surveyId]
        ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $errors = validate_answers(
        $survey,
        $answers
    );

    if ($errors) {
        flash(
            'error',
            implode("\n", $errors)
        );

        redirect_screen(
            'answer',
            ['id' => $surveyId]
        );
    }

    $data['answers'][] = [
        'id' => uuid('answer'),
        'survey_id' => $surveyId,
        'answers' => $answers,
        'createdAt' =>
            date('Y-m-d H:i:s'),
    ];

    save_data($data);

    unset(
        $_SESSION['answer_draft'][$surveyId]
    );

    redirect_screen(
        'complete',
        ['id' => $surveyId]
    );
}

function handle_answer_submit(
    array &$data
): void {
    handle_answer_confirm($data);
}


/* =========================================================
 * HTML / CSS
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

*{
    box-sizing:border-box;
}

html,body{
    margin:0;
    padding:0;
    color:var(--text);
    background:#f8fafc;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
}

body{
    min-height:100vh;
}

a{
    color:var(--primary);
    text-decoration:none;
}

a:hover{
    text-decoration:underline;
}

button,
input,
select,
textarea{
    font:inherit;
}

button{
    cursor:pointer;
}

.app-header{
    background:#0f172a;
    color:#fff;
}

.header-inner{
    width:min(1240px,calc(100% - 32px));
    margin:0 auto;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand{
    color:#fff;
    font-weight:800;
    font-size:20px;
}

.brand:hover{
    text-decoration:none;
}

.header-nav{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.header-nav a{
    color:#cbd5e1;
    padding:8px 10px;
    border-radius:7px;
}

.header-nav a:hover{
    background:#1e293b;
    text-decoration:none;
    color:#fff;
}

.container{
    width:min(1240px,calc(100% - 32px));
    margin:0 auto;
    padding:28px 0 60px;
}

.page-title{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    margin-bottom:22px;
}

.page-title h1{
    margin:0 0 6px;
    font-size:28px;
}

.page-title p{
    margin:0;
    color:var(--gray);
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.card-header{
    padding:16px 18px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.card-header h2,
.card-header h3{
    margin:0;
    font-size:18px;
}

.card-body{
    padding:20px;
}

.grid{
    display:grid;
    gap:16px;
}

.grid-2{
    grid-template-columns:
        repeat(2,minmax(0,1fr));
}

.grid-3{
    grid-template-columns:
        repeat(3,minmax(0,1fr));
}

.form-group{
    margin-bottom:16px;
}

.form-group:last-child{
    margin-bottom:0;
}

label>span,
.field-label{
    display:block;
    font-weight:700;
    margin-bottom:7px;
}

input[type=text],
input[type=email],
input[type=password],
input[type=search],
input[type=number],
input[type=datetime-local],
select,
textarea{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    background:#fff;
    color:var(--text);
    padding:10px 12px;
    outline:none;
}

textarea{
    min-height:130px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus{
    border-color:var(--primary);
    box-shadow:
        0 0 0 3px rgba(37,99,235,.12);
}

.check{
    display:flex;
    align-items:center;
    gap:8px;
    cursor:pointer;
    font-weight:500;
}

.check input{
    width:18px;
    height:18px;
}

.button-row{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:8px;
}

.btn{
    appearance:none;
    border:1px solid transparent;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 14px;
    border-radius:8px;
    font-weight:700;
    transition:.15s;
    text-decoration:none;
}

.btn:hover{
    transform:translateY(-1px);
    text-decoration:none;
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-secondary,
.btn-light{
    background:#fff;
    border-color:var(--border);
    color:var(--text);
}

.btn-secondary:hover,
.btn-light:hover{
    background:var(--gray-light);
}

.btn-success{
    background:var(--success);
    color:#fff;
}

.btn-warning{
    background:var(--warning);
    color:#fff;
}

.btn-danger{
    background:var(--danger);
    color:#fff;
}

.btn-small{
    min-height:34px;
    padding:6px 10px;
    font-size:13px;
}

.badge{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:5px 10px;
    font-size:13px;
    font-weight:700;
}

.badge-success{
    background:#dcfce7;
    color:#166534;
}

.badge-warning{
    background:#fef3c7;
    color:#92400e;
}

.badge-gray{
    background:#e2e8f0;
    color:#475569;
}

.alert{
    white-space:pre-line;
    border-radius:10px;
    padding:13px 15px;
    margin-bottom:18px;
    border:1px solid;
}

.alert-success{
    background:#f0fdf4;
    color:#166534;
    border-color:#bbf7d0;
}

.alert-error{
    background:#fef2f2;
    color:#991b1b;
    border-color:#fecaca;
}

.alert-warning{
    background:#fffbeb;
    color:#92400e;
    border-color:#fde68a;
}

.notice{
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1e40af;
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:18px;
}

.help{
    color:var(--gray);
    font-size:13px;
}

.table-wrap{
    width:100%;
    overflow-x:auto;
}

table{
    width:100%;
    min-width:980px;
    border-collapse:collapse;
}

th,
td{
    padding:12px 13px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    white-space:nowrap;
    font-size:13px;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}

.group-card{
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
    margin-bottom:18px;
}

.group-head{
    padding:13px 16px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
}

.group-title-line{
    display:flex;
    align-items:center;
    gap:8px;
}

.drag-handle{
    color:var(--gray);
    cursor:grab;
    user-select:none;
}

.dragging{
    opacity:.45;
}

.drop-target{
    outline:2px dashed var(--primary);
    outline-offset:3px;
}

.question-card{
    border:1px solid var(--border);
    border-radius:9px;
    margin:14px;
    background:#fff;
}

.question-head{
    padding:10px 13px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    gap:10px;
}

.question-number{
    color:var(--primary);
    font-weight:800;
}

.question-body{
    padding:15px;
}

.option-row{
    display:grid;
    grid-template-columns:
        1fr auto;
    gap:7px;
    margin-bottom:8px;
}

.mapping-grid{
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:16px;
}

.mapping-address{
    border:1px solid var(--border);
    border-radius:8px;
    padding:13px;
    max-height:260px;
    overflow:auto;
}

.mapping-address label{
    display:flex;
    align-items:center;
    gap:8px;
    padding:6px 0;
}

.sticky-actions{
    position:sticky;
    bottom:0;
    z-index:10;
    background:rgba(255,255,255,.96);
    border-top:1px solid var(--border);
    padding:13px 0;
    backdrop-filter:blur(8px);
}

.answer-shell{
    width:min(760px,calc(100% - 28px));
    margin:0 auto;
    padding:30px 0 50px;
}

.answer-option{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:13px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:8px;
    cursor:pointer;
}

.answer-option:hover{
    background:#f8fafc;
}

.empty{
    text-align:center;
    padding:45px 20px;
    color:var(--gray);
}

.preview-question{
    padding:18px 0;
    border-bottom:1px solid var(--border);
}

.preview-question:last-child{
    border-bottom:0;
}

.url-box{
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:8px;
    padding:12px;
    word-break:break-all;
    font-family:monospace;
}

.stat-grid{
    display:grid;
    grid-template-columns:
        repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:17px;
}

.stat-label{
    color:var(--gray);
    font-size:13px;
}

.stat-value{
    margin-top:6px;
    font-size:25px;
    font-weight:800;
}

.footer{
    color:var(--gray);
    text-align:center;
    padding:25px 0;
}

@media(max-width:900px){
    .grid-2,
    .grid-3,
    .mapping-grid,
    .stat-grid{
        grid-template-columns:1fr;
    }

    .page-title{
        flex-direction:column;
    }

    .header-inner{
        flex-direction:column;
        align-items:flex-start;
        padding:12px 0;
    }
}

@media(max-width:640px){
    .container{
        width:min(100% - 20px,1240px);
        padding-top:18px;
    }

    .page-title h1{
        font-size:23px;
    }

    .card-body{
        padding:15px;
    }

    .btn{
        width:auto;
    }

    .question-card{
        margin:10px;
    }
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header class="app-header">
<div class="header-inner">
<a class="brand"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
<?= h(APP_TITLE) ?>
</a>
<nav class="header-nav">
<a href="<?= h(app_url(['screen'=>'list'])) ?>">
アンケート一覧
</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">
kintone連携
</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">
メール設定
</a>
</nav>
</div>
</header>
<?php endif; ?>
<?php
}

function render_footer(): void
{
?>
<footer class="footer">
アンケート管理
</footer>

<script>
(function(){
    "use strict";

    function confirmButtons(){
        document
            .querySelectorAll("[data-confirm]")
            .forEach(function(button){
                button.addEventListener("click",function(event){
                    var message =
                        button.getAttribute("data-confirm");

                    if(message && !window.confirm(message)){
                        event.preventDefault();
                    }
                });
            });
    }

    function selectAll(){
        var all =
            document.getElementById("selectAllCustomers");

        if(!all){
            return;
        }

        all.addEventListener("change",function(){
            document
                .querySelectorAll(".customer-check")
                .forEach(function(check){
                    var row = check.closest("tr");

                    if(!row || row.style.display !== "none"){
                        check.checked = all.checked;
                    }
                });
        });
    }

    function customerSearch(){
        var input =
            document.getElementById("customerSearch");

        if(!input){
            return;
        }

        input.addEventListener("input",function(){
            var q =
                input.value.toLowerCase().trim();

            document
                .querySelectorAll("[data-customer-row]")
                .forEach(function(row){
                    var text =
                        row.textContent.toLowerCase();

                    row.style.display =
                        q === "" || text.indexOf(q) !== -1
                            ? ""
                            : "none";
                });
        });
    }

    function dragAndDrop(){
        var form =
            document.getElementById("editSurveyForm");

        if(!form){
            return;
        }

        var dragging = null;

        document
            .querySelectorAll("[data-group]")
            .forEach(function(group){
                group.draggable = true;

                group.addEventListener("dragstart",function(){
                    dragging = group;
                    group.classList.add("dragging");
                });

                group.addEventListener("dragend",function(){
                    group.classList.remove("dragging");
                    dragging = null;
                    writeOrder();
                });

                group.addEventListener("dragover",function(event){
                    event.preventDefault();

                    if(!dragging || dragging === group){
                        return;
                    }

                    group.classList.add("drop-target");

                    var parent = group.parentNode;

                    if(!parent){
                        return;
                    }

                    var rect =
                        group.getBoundingClientRect();

                    if(event.clientY < rect.top + rect.height / 2){
                        parent.insertBefore(
                            dragging,
                            group
                        );
                    }else{
                        parent.insertBefore(
                            dragging,
                            group.nextSibling
                        );
                    }
                });

                group.addEventListener("dragleave",function(){
                    group.classList.remove("drop-target");
                });

                group.addEventListener("drop",function(){
                    group.classList.remove("drop-target");
                    writeOrder();
                });
            });

        document
            .querySelectorAll("[data-question-list]")
            .forEach(function(list){
                list.addEventListener("dragover",function(event){
                    event.preventDefault();

                    var target =
                        event.target.closest("[data-question]");

                    var source =
                        document.querySelector(
                            "[data-question].dragging"
                        );

                    if(!source || !target || source === target){
                        return;
                    }

                    var rect =
                        target.getBoundingClientRect();

                    if(event.clientY < rect.top + rect.height / 2){
                        list.insertBefore(source,target);
                    }else{
                        list.insertBefore(
                            source,
                            target.nextSibling
                        );
                    }

                    writeOrder();
                });

                list.querySelectorAll("[data-question]")
                    .forEach(function(question){
                        question.draggable = true;

                        question.addEventListener(
                            "dragstart",
                            function(){
                                question.classList.add("dragging");
                            }
                        );

                        question.addEventListener(
                            "dragend",
                            function(){
                                question.classList.remove("dragging");
                                writeOrder();
                            }
                        );
                    });
            });

        function writeOrder(){
            var groupInput =
                document.getElementById("groupOrder");

            if(groupInput){
                var ids = [];

                document
                    .querySelectorAll("[data-group]")
                    .forEach(function(group){
                        ids.push(
                            group.getAttribute("data-group")
                        );
                    });

                groupInput.value =
                    JSON.stringify(ids);
            }

            document
                .querySelectorAll("[data-question-list]")
                .forEach(function(list){
                    var groupId =
                        list.getAttribute("data-question-list");

                    var ids = [];

                    list.querySelectorAll(
                        "[data-question]"
                    ).forEach(function(question){
                        ids.push(
                            question.getAttribute("data-question")
                        );
                    });

                    var input =
                        document.getElementById(
                            "questionOrder_" + groupId
                        );

                    if(input){
                        input.value =
                            JSON.stringify(ids);
                    }
                });
        }

        form.addEventListener("submit",function(){
            writeOrder();
        });

        writeOrder();
    }

    confirmButtons();
    selectAll();
    customerSearch();
    dragAndDrop();
})();
</script>
</body>
</html>
<?php
}


/* =========================================================
 * Flash
 * ========================================================= */

function render_flash(): void
{
    $flash = consume_flash();

    if (!$flash) {
        return;
    }

    $type = (string)(
        $flash['type'] ?? 'success'
    );

    $class = 'alert-success';

    if ($type === 'error') {
        $class = 'alert-error';
    }

    if ($type === 'warning') {
        $class = 'alert-warning';
    }
?>
<div class="alert <?= h($class) ?>">
<?= h($flash['message'] ?? '') ?>
</div>
<?php
}


/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(
    array &$data
): void {
    render_head('アンケート一覧');

    render_flash();

    $surveys = $data['surveys'];

    $query = get_string('q');
    $filter = get_string('filter');
    $sort = get_string('sort');

    if ($query !== '') {
        $surveys = array_values(
            array_filter(
                $surveys,
                static function(array $survey) use ($query): bool {
                    return mb_stripos(
                        (string)($survey['title'] ?? ''),
                        $query
                    ) !== false;
                }
            )
        );
    }

    if (
        in_array(
            $filter,
            [
                'published',
                'draft',
                'stopped',
                'ended',
            ],
            true
        )
    ) {
        $surveys = array_values(
            array_filter(
                $surveys,
                static function(array $survey) use ($filter): bool {
                    return ($survey['status'] ?? '')
                        === $filter;
                }
            )
        );
    }

    usort(
        $surveys,
        static function(
            array $a,
            array $b
        ) use ($sort): int {
            switch ($sort) {
                case 'updated_old':
                    return strcmp(
                        (string)($a['updatedAt'] ?? ''),
                        (string)($b['updatedAt'] ?? '')
                    );

                case 'answers_desc':
                    return 0;

                case 'answers_asc':
                    return 0;

                case 'start_desc':
                    return strcmp(
                        (string)($b['startAt'] ?? ''),
                        (string)($a['startAt'] ?? '')
                    );

                case 'start_asc':
                    return strcmp(
                        (string)($a['startAt'] ?? ''),
                        (string)($b['startAt'] ?? '')
                    );

                default:
                    return strcmp(
                        (string)($b['updatedAt'] ?? ''),
                        (string)($a['updatedAt'] ?? '')
                    );
            }
        }
    );
?>
<div class="container">

<div class="page-title">
<div>
<h1>アンケート一覧</h1>
<p>アンケートの作成・公開・集計・送信を管理します。</p>
</div>

<a class="btn btn-primary"
   href="<?= h(app_url([
       'screen' => 'edit'
   ])) ?>">
新規作成
</a>
</div>

<div class="card">
<div class="card-body">

<form method="get">
<input type="hidden" name="screen" value="list">

<div class="grid grid-3">

<div class="form-group">
<label>
<span>検索</span>
<input type="search"
       name="q"
       value="<?= h($query) ?>"
       placeholder="タイトルを検索">
</label>
</div>

<div class="form-group">
<label>
<span>ステータス</span>
<select name="filter">
<option value="">すべて</option>
<option value="published"
<?= $filter === 'published' ? 'selected' : '' ?>>
公開中
</option>
<option value="draft"
<?= $filter === 'draft' ? 'selected' : '' ?>>
下書き
</option>
<option value="stopped"
<?= $filter === 'stopped' ? 'selected' : '' ?>>
停止
</option>
<option value="ended"
<?= $filter === 'ended' ? 'selected' : '' ?>>
終了
</option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>ソート</span>
<select name="sort">
<option value="updated_desc"
<?= $sort === 'updated_desc'
    || $sort === ''
    ? 'selected'
    : '' ?>>
更新日：新しい順
</option>
<option value="updated_old"
<?= $sort === 'updated_old'
    ? 'selected'
    : '' ?>>
更新日：古い順
</option>
<option value="start_desc"
<?= $sort === 'start_desc'
    ? 'selected'
    : '' ?>>
開始日：新しい順
</option>
<option value="start_asc"
<?= $sort === 'start_asc'
    ? 'selected'
    : '' ?>>
開始日：古い順
</option>
</select>
</label>
</div>

</div>

<button class="btn btn-secondary"
        type="submit">
検索
</button>

</form>

</div>
</div>

<div class="card">
<div class="card-body">

<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>作成日</th>
<th>更新日</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>

<?php if (!$surveys): ?>
<tr>
<td colspan="7">
<div class="empty">
アンケートがありません。
</div>
</td>
</tr>
<?php endif; ?>

<?php foreach ($surveys as $survey): ?>

<?php
$status =
    (string)($survey['status'] ?? 'draft');

$answerCount = 0;

foreach ($data['answers'] as $answer) {
    if (
        ($answer['survey_id'] ?? '')
        === ($survey['id'] ?? '')
    ) {
        $answerCount++;
    }
}
?>

<tr>
<td>
<strong><?= h($survey['title']) ?></strong>
</td>

<td><?= h($survey['createdAt'] ?? '') ?></td>

<td><?= h($survey['updatedAt'] ?? '') ?></td>

<td>
<?= h($survey['startAt'] ?? '') ?>
～
<?= h($survey['endAt'] ?? '') ?>
</td>

<td>
<span class="badge badge-<?= h(
    status_class($status)
) ?>">
<?= h(status_label($status)) ?>
</span>
</td>

<td><?= h($answerCount) ?></td>

<td>
<div class="actions">

<a class="btn btn-small btn-secondary"
   href="<?= h(app_url([
       'screen' => 'edit',
       'id' => $survey['id'],
   ])) ?>">
確認・編集
</a>

<a class="btn btn-small btn-secondary"
   href="<?= h(app_url([
       'screen' => 'preview',
       'id' => $survey['id'],
   ])) ?>">
プレビュー
</a>

<a class="btn btn-small btn-secondary"
   href="<?= h(app_url([
       'screen' => 'analytics',
       'id' => $survey['id'],
   ])) ?>">
集計
</a>

<a class="btn btn-small btn-secondary"
   href="<?= h(app_url([
       'screen' => 'send',
       'id' => $survey['id'],
   ])) ?>">
送信
</a>

<form method="post" style="display:inline">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-small btn-light"
        type="submit"
        data-confirm="このアンケートを複製しますか？">
複製
</button>
</form>

<form method="post" style="display:inline">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-small btn-danger"
        type="submit"
        data-confirm="このアンケートを削除しますか？">
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
    render_head('アンケート作成・編集');

    render_flash();

    $status =
        (string)($survey['status'] ?? 'draft');

    $questions =
        answer_questions($survey);
?>
<div class="container">

<div class="page-title">
<div>
<h1>アンケート作成・編集</h1>
<p><?= h($survey['title']) ?></p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>
</div>

<div class="card">
<div class="card-body">

<form method="post"
      id="editSurveyForm">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<input type="hidden"
       name="group_order"
       id="groupOrder">

<?php foreach ($survey['groups'] as $group): ?>
<input type="hidden"
       name="question_order_<?= h($group['id']) ?>"
       id="questionOrder_<?= h($group['id']) ?>">
<?php endforeach; ?>

<div class="button-row"
     style="margin-bottom:20px">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'list'
   ])) ?>">
キャンセル
</a>

<button class="btn btn-primary"
        type="submit">
保存して一覧へ
</button>

</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>アンケートタイトル</span>
<input type="text"
       name="title"
       value="<?= h($survey['title']) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>状態</span>
<select disabled>
<option>
<?= h(status_label($status)) ?>
</option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt']) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt']) ?>">
</label>
</div>

</div>

<div class="form-group">
<label>
<span>アンケート説明</span>
<textarea name="description"><?= h(
    $survey['description']
) ?></textarea>
</label>
</div>

<div class="form-group">
<label>
<span>質問番号の採番方式</span>
<select name="numbering">
<option value="global"
<?= ($survey['numbering'] ?? 'global')
    === 'global'
    ? 'selected'
    : '' ?>>
アンケート全体で通番：Q1、Q2、Q3...
</option>
<option value="group"
<?= ($survey['numbering'] ?? 'global')
    === 'group'
    ? 'selected'
    : '' ?>>
グループ毎：Q1-1、Q1-2、Q2-1...
</option>
</select>
</label>
</div>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>状態変更</h2>
</div>
<div class="card-body">

<div class="button-row">

<?php if ($status === 'draft'): ?>

<form method="post">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="next_status"
       value="published">
<input type="hidden"
       name="return_screen"
       value="edit">
<button class="btn btn-success"
        type="submit"
        data-confirm="このアンケートを公開しますか？">
公開する
</button>
</form>

<?php elseif ($status === 'published'): ?>

<form method="post">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="next_status"
       value="stopped">
<input type="hidden"
       name="return_screen"
       value="edit">
<button class="btn btn-warning"
        type="submit"
        data-confirm="このアンケートを停止しますか？">
停止する
</button>
</form>

<?php elseif ($status === 'stopped'): ?>

<form method="post">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="next_status"
       value="published">
<input type="hidden"
       name="return_screen"
       value="edit">
<button class="btn btn-success"
        type="submit"
        data-confirm="このアンケートを再開しますか？">
再開する
</button>
</form>

<?php else: ?>

<span class="help">
終了状態では変更できません。
</span>

<?php endif; ?>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'preview',
       'id' => $survey['id'],
   ])) ?>">
プレビュー
</a>

</div>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>質問・グループ</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="add_group">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-primary"
        type="submit">
グループを追加
</button>
</form>

</div>

<div class="card-body">

<?php if (!$survey['groups']): ?>
<div class="empty">
グループがありません。
上の「グループを追加」から追加してください。
</div>
<?php endif; ?>

<div id="groupList">

<?php foreach ($survey['groups'] as $group): ?>

<div class="group-card"
     data-group="<?= h($group['id']) ?>">

<div class="group-head">
<div class="group-title-line">
<span class="drag-handle"
      title="ドラッグして並び替え">
☷
</span>
<strong><?= h($group['title']) ?></strong>
</div>

<div class="actions">
<form method="post">
<input type="hidden"
       name="action"
       value="delete_group">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="group_id"
       value="<?= h($group['id']) ?>">
<button class="btn btn-small btn-danger"
        type="submit"
        data-confirm="このグループを削除しますか？">
削除
</button>
</form>
</div>
</div>

<div data-question-list="<?= h($group['id']) ?>">

<?php foreach ($group['questions'] as $question): ?>

<div class="question-card"
     data-question="<?= h($question['id']) ?>">

<div class="question-head">
<div>
<span class="drag-handle">☷</span>
<span class="question-number">
<?= h($question['number']) ?>
</span>
</div>

<div class="actions">
<a class="btn btn-small btn-secondary"
   href="<?= h(app_url([
       'screen' => 'edit',
       'id' => $survey['id'],
       'question' => $question['id'],
   ])) ?>">
編集
</a>

<form method="post">
<input type="hidden"
       name="action"
       value="delete_question">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="question_id"
       value="<?= h($question['id']) ?>">
<button class="btn btn-small btn-danger"
        type="submit"
        data-confirm="この質問を削除しますか？">
削除
</button>
</form>
</div>
</div>

<div class="question-body">
<strong><?= h($question['text']) ?></strong>
<p class="help">
<?= h(type_label(
    (string)$question['type']
)) ?>
 /
<?= !empty($question['required'])
    ? '必須'
    : '任意' ?>
</p>
</div>

</div>

<?php endforeach; ?>

</div>

<div style="padding:0 14px 14px">

<form method="post">
<input type="hidden"
       name="action"
       value="add_question">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="group_id"
       value="<?= h($group['id']) ?>">
<button class="btn btn-primary"
        type="submit">
質問を追加
</button>
</form>

</div>

</div>

<?php endforeach; ?>

</div>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * 質問編集
 * ========================================================= */

function render_question_edit(
    array $survey,
    array $question
): void {
    render_head('質問編集');

    render_flash();
?>
<div class="container">

<div class="page-title">
<div>
<h1>質問編集</h1>
<p><?= h($survey['title']) ?></p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'edit',
       'id' => $survey['id'],
   ])) ?>">
編集画面へ戻る
</a>
</div>

<div class="card">
<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_question">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<input type="hidden"
       name="question_id"
       value="<?= h($question['id']) ?>">

<div class="form-group">
<label>
<span>質問文</span>
<textarea name="question_text"
          required><?= h(
              $question['text']
          ) ?></textarea>
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>回答形式</span>
<select name="question_type"
        id="questionType">
<option value="single"
<?= $question['type'] === 'single'
    ? 'selected'
    : '' ?>>
単一選択
</option>
<option value="multiple"
<?= $question['type'] === 'multiple'
    ? 'selected'
    : '' ?>>
複数選択
</option>
<option value="text"
<?= $question['type'] === 'text'
    ? 'selected'
    : '' ?>>
自由記述
</option>
</select>
</label>
</div>

<div class="form-group">
<label class="check">
<input type="checkbox"
       name="question_required"
       value="1"
<?= !empty($question['required'])
    ? 'checked'
    : '' ?>>
必須回答
</label>
</div>

</div>

<div class="form-group"
     id="optionsArea">

<label>
<span>選択肢</span>
</label>

<?php
$options = $question['options'] ?? [];
if (!is_array($options)) {
    $options = [];
}
?>

<?php foreach ($options as $key => $option): ?>

<div class="option-row">

<input type="hidden"
       name="option_id[<?= h($key) ?>]"
       value="<?= h($option['id'] ?? '') ?>">

<input type="text"
       name="option_label[<?= h($key) ?>]"
       value="<?= h($option['label'] ?? '') ?>">

<select name="option_next[<?= h($key) ?>]">
<option value="">
次の質問を指定しない
</option>

<?php foreach (
    answer_questions($survey)
    as $candidate
): ?>

<?php if (
    ($candidate['id'] ?? '')
    === ($question['id'] ?? '')
) {
    continue;
} ?>

<option value="<?= h($candidate['id']) ?>"
<?= ($option['nextQuestionId'] ?? '')
    === ($candidate['id'] ?? '')
    ? 'selected'
    : '' ?>>
<?= h(
    ($candidate['number'] ?? '') .
    ' ' .
    ($candidate['text'] ?? '')
) ?>
</option>

<?php endforeach; ?>

</select>

</div>

<?php endforeach; ?>

</div>

<div class="button-row">
<button class="btn btn-primary"
        type="submit">
質問を保存
</button>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'edit',
       'id' => $survey['id'],
   ])) ?>">
キャンセル
</a>
</div>

</form>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * Preview
 * ========================================================= */

function render_preview(
    array $survey
): void {
    render_head('プレビュー');

    render_flash();
?>
<div class="container">

<div class="page-title">
<div>
<h1>プレビュー</h1>
<p><?= h($survey['title']) ?></p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'edit',
       'id' => $survey['id'],
   ])) ?>">
編集へ戻る
</a>
</div>

<div class="card">
<div class="card-body">

<h2><?= h($survey['title']) ?></h2>

<p><?= nl2br(
    h($survey['description'])
) ?></p>

<?php foreach (
    answer_questions($survey)
    as $question
): ?>

<div class="preview-question">

<h3>
<?= h($question['number']) ?>
<?= h($question['text']) ?>

<?php if (!empty($question['required'])): ?>
<span class="badge badge-warning">
必須
</span>
<?php endif; ?>

</h3>

<p class="help">
<?= h(type_label(
    (string)$question['type']
)) ?>
</p>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<div class="answer-option">
<input type="<?= $question['type'] === 'multiple'
    ? 'checkbox'
    : 'radio' ?>">
<label>
<?= h($option['label'] ?? '') ?>
</label>
</div>

<?php endforeach; ?>

<?php if ($question['type'] === 'text'): ?>
<textarea
    placeholder="回答を入力してください"></textarea>
<?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * kintone設定画面
 * ========================================================= */

function render_kintone(
    array $settings
): void {
    render_head('kintone連携設定');

    render_flash();

    $config = $settings['kintone'];
    $fields = $config['fields'] ?? [];

    if (!is_array($fields)) {
        $fields = [];
    }

    $mapping =
        $config['mapping'] ?? [];

    if (!is_array($mapping)) {
        $mapping = [];
    }

    $address =
        $mapping['address'] ?? [];

    if (!is_array($address)) {
        $address = [];
    }
?>
<div class="container">

<div class="page-title">
<div>
<h1>kintone連携設定</h1>
<p>顧客管理アプリとの接続・項目取得・同期を設定します。</p>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>kintone設定</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>サブドメイン</span>
<input type="text"
       name="subdomain"
       value="<?= h(
           $config['subdomain']
       ) ?>"
       placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>顧客管理アプリID</span>
<input type="number"
       name="app_id"
       min="1"
       value="<?= h(
           $config['app_id']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>ログイン名</span>
<input type="text"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>"
       autocomplete="username"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更時のみ入力">
</label>
</div>

<div class="form-group">
<label>
<span>Proxy</span>
<input type="text"
       name="proxy"
       value="<?= h(
           $config['proxy']
       ) ?>"
       placeholder="host:port">
</label>
<p class="help">
未入力の場合はProxyを使用しません。
</p>
</div>

<div class="form-group">
<label class="check">
<input type="checkbox"
       name="verify_ssl"
       value="1"
<?= !empty($config['verify_ssl'])
    ? 'checked'
    : '' ?>>
SSL証明書を検証する
</label>
<p class="help">
POCでは無効を既定とします。
</p>
</div>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

<hr style="
border:0;
border-top:1px solid var(--border);
margin:24px 0;
">

<div class="button-row">

<form method="post">
<input type="hidden"
       name="action"
       value="test_kintone">
<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>
</form>

<form method="post">
<input type="hidden"
       name="action"
       value="fetch_kintone_fields">
<button class="btn btn-secondary"
        type="submit">
項目一覧を再取得
</button>
</form>

<form method="post">
<input type="hidden"
       name="action"
       value="sync_kintone">
<button class="btn btn-success"
        type="submit"
        data-confirm="kintoneから顧客情報を同期しますか？">
顧客情報を同期
</button>
</form>

</div>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>項目マッピング</h2>
</div>

<div class="card-body">

<?php if (!$fields): ?>

<div class="notice">
先に「項目一覧を再取得」を実行してください。
</div>

<?php else: ?>

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone">

<input type="hidden"
       name="subdomain"
       value="<?= h($config['subdomain']) ?>">

<input type="hidden"
       name="app_id"
       value="<?= h($config['app_id']) ?>">

<input type="hidden"
       name="username"
       value="<?= h($config['username']) ?>">

<input type="hidden"
       name="proxy"
       value="<?= h($config['proxy']) ?>">

<?php
$mappingFields = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<div class="mapping-grid">

<?php foreach (
    $mappingFields
    as $mapKey => $label
): ?>

<div class="form-group">
<label>
<span><?= h($label) ?></span>
<select name="mapping_<?= h($mapKey) ?>">
<option value="">
未選択
</option>

<?php foreach ($fields as $field): ?>

<option value="<?= h($field['code']) ?>"
<?= ($mapping[$mapKey] ?? '')
    === ($field['code'] ?? '')
    ? 'selected'
    : '' ?>>
<?= h(
    ($field['label'] ?? '') .
    ' [' .
    ($field['code'] ?? '') .
    ']'
) ?>
</option>

<?php endforeach; ?>

</select>
</label>
</div>

<?php endforeach; ?>

<div class="form-group"
     style="grid-column:1/-1">

<label>
<span>住所</span>
</label>

<div class="mapping-address">

<?php foreach ($fields as $field): ?>

<label>
<input type="checkbox"
       name="mapping_address[]"
       value="<?= h($field['code']) ?>"
<?= in_array(
    $field['code'],
    $address,
    true
)
    ? 'checked'
    : '' ?>>
<span>
<?= h($field['label']) ?>
[<?= h($field['code']) ?>]
</span>
</label>

<?php endforeach; ?>

</div>

<p class="help">
住所は複数のkintone項目を選択できます。
同期時に選択順で連結します。
</p>

</div>

</div>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
マッピングを保存
</button>

</div>

</form>

<?php endif; ?>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * メール設定
 * ========================================================= */

function render_mail(
    array $settings
): void {
    render_head('メールサーバ設定');

    render_flash();

    $config = $settings['mail'];
?>
<div class="container">

<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
<p>SMTPサーバへの接続・認証・テスト送信を設定します。</p>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>SMTP設定</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>SMTPサーバ</span>
<input type="text"
       name="host"
       value="<?= h(
           $config['host']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>SMTPポート</span>
<input type="number"
       name="port"
       min="1"
       max="65535"
       value="<?= h(
           $config['port']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>暗号化方式</span>
<select name="encryption">
<option value="ssl"
<?= $config['encryption'] === 'ssl'
    ? 'selected'
    : '' ?>>
SSL
</option>
<option value="tls"
<?= $config['encryption'] === 'tls'
    ? 'selected'
    : '' ?>>
TLS
</option>
<option value="none"
<?= $config['encryption'] === 'none'
    ? 'selected'
    : '' ?>>
なし
</option>
</select>
</label>
</div>

<div class="form-group">
<label class="check">
<input type="checkbox"
       name="auth"
       value="1"
<?= !empty($config['auth'])
    ? 'checked'
    : '' ?>>
SMTP認証を使用する
</label>
</div>

<div class="form-group">
<label>
<span>SMTPユーザー名</span>
<input type="text"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>SMTPパスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更時のみ入力">
</label>
</div>

<div class="form-group">
<label>
<span>送信元メールアドレス</span>
<input type="email"
       name="from_email"
       value="<?= h(
           $config['from_email']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>送信元名</span>
<input type="text"
       name="from_name"
       value="<?= h(
           $config['from_name']
       ) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>返信先メールアドレス</span>
<input type="email"
       name="reply_to"
       value="<?= h(
           $config['reply_to']
       ) ?>">
</label>
</div>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

<hr style="
border:0;
border-top:1px solid var(--border);
margin:24px 0;
">

<h3>接続状態</h3>

<p>
<?php if (!empty($config['last_test'])): ?>
<span class="badge badge-success">
接続確認済み
</span>
<?php else: ?>
<span class="badge badge-gray">
未設定
</span>
<?php endif; ?>
</p>

<form method="post">

<input type="hidden"
       name="action"
       value="test_mail">

<div class="form-group">
<label>
<span>接続テスト用パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みの場合は空欄でも可">
</label>
</div>

<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>

</form>

<hr style="
border:0;
border-top:1px solid var(--border);
margin:24px 0;
">

<form method="post">

<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="form-group">
<label>
<span>テスト送信先メールアドレス</span>
<input type="email"
       name="test_email"
       required>
</label>
</div>

<button class="btn btn-primary"
        type="submit"
        data-confirm="テストメールを送信しますか？">
テストメール送信
</button>

</form>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * Send
 * ========================================================= */

function render_send(
    array $survey,
    array $customers,
    array $history
): void {
    render_head('顧客選択・メール送信');

    render_flash();
?>
<div class="container">

<div class="page-title">
<div>
<h1>顧客選択・メール送信</h1>
<p>
対象アンケート：
<strong><?= h(
    $survey['title']
) ?></strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'list'
   ])) ?>">
一覧へ戻る
</a>
</div>

<div class="card">
<div class="card-header">
<h2>顧客選択・メール作成</h2>
</div>

<div class="card-body">

<div class="notice">
メール変数：
{顧客名} / {アンケートURL}
</div>

<div class="form-group">
<span class="field-label">
アンケートURL
</span>

<div class="url-box">
<?= h(
    public_answer_url(
        (string)$survey['id']
    )
) ?>
</div>

<p class="help">
送信メールにはこの完全なURLが
{アンケートURL}として差し込まれます。
</p>
</div>

<form method="post">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="form-group">
<label>
<span>顧客検索</span>
<input type="search"
       id="customerSearch"
       placeholder="氏名・組織名・メールアドレス等で検索">
</label>
</div>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>
<label class="check">
<input type="checkbox"
       id="selectAllCustomers">
全選択
</label>
</th>
<th>氏名</th>
<th>組織名</th>
<th>部署</th>
<th>メール</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $customers as $customer
): ?>

<tr data-customer-row>

<td>
<input type="checkbox"
       class="customer-check"
       name="customer_ids[]"
       value="<?= h(
           $customer['id']
       ) ?>">
</td>

<td><?= h(
    $customer['name']
) ?></td>

<td><?= h(
    $customer['organization']
) ?></td>

<td><?= h(
    $customer['department']
) ?></td>

<td><?= h(
    $customer['email']
) ?></td>

</tr>

<?php endforeach; ?>

<?php if (!$customers): ?>

<tr>
<td colspan="5">
<div class="empty">
顧客情報がありません。
先にkintoneから顧客情報を同期してください。
</div>
</td>
</tr>

<?php endif; ?>

</tbody>
</table>
</div>

<div class="form-group"
     style="margin-top:20px">

<label>
<span>メール件名</span>
<input type="text"
       name="subject"
       value="<?= h(
           $survey['title'] .
           'のご案内'
       ) ?>"
       required>
</label>

</div>

<div class="form-group">

<label>
<span>メール本文</span>
<textarea name="body"
          required>「{顧客名}」様

以下のアンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
</label>

</div>

<button class="btn btn-primary"
        type="submit"
        data-confirm="選択した顧客へメールを送信しますか？">
一括送信
</button>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>送信履歴</h2>
</div>

<div class="card-body">

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>メール</th>
<th>結果</th>
<th>エラー</th>
</tr>
</thead>

<tbody>

<?php
$surveyHistory = [];

foreach ($history as $item) {
    if (
        ($item['survey_id'] ?? '')
        === ($survey['id'] ?? '')
    ) {
        $surveyHistory[] = $item;
    }
}
?>

<?php if (!$surveyHistory): ?>

<tr>
<td colspan="4">
<div class="empty">
送信履歴はありません。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach (
    array_reverse($surveyHistory)
    as $item
): ?>

<tr>
<td><?= h(
    $item['sentAt'] ?? ''
) ?></td>

<td><?= h(
    $item['email'] ?? ''
) ?></td>

<td>
<?php if (
    ($item['status'] ?? '')
    === 'success'
): ?>
<span class="badge badge-success">
成功
</span>
<?php else: ?>
<span class="badge badge-warning">
失敗
</span>
<?php endif; ?>
</td>

<td><?= h(
    $item['error'] ?? ''
) ?></td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * Analytics
 * ========================================================= */

function render_analytics(
    array $survey,
    array $answers,
    array $customers
): void {
    render_head('回答集計・分析');

    render_flash();

    $surveyAnswers = [];

    foreach ($answers as $answer) {
        if (
            ($answer['survey_id'] ?? '')
            === ($survey['id'] ?? '')
        ) {
            $surveyAnswers[] = $answer;
        }
    }

    $answerCount = count($surveyAnswers);
?>
<div class="container">

<div class="page-title">
<div>
<h1>回答集計・分析</h1>
<p>
対象アンケート：
<strong><?= h(
    $survey['title']
) ?></strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'list'
   ])) ?>">
一覧へ戻る
</a>
</div>

<div class="stat-grid">

<div class="stat">
<div class="stat-label">送信対象者数</div>
<div class="stat-value">
<?= h(count($customers)) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">回答数</div>
<div class="stat-value">
<?= h($answerCount) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">未登録回答数</div>
<div class="stat-value">0</div>
</div>

<div class="stat">
<div class="stat-label">未回答数</div>
<div class="stat-value">
<?= h(
    max(0,count($customers) - $answerCount)
) ?>
</div>
</div>

</div>

<div class="card"
     style="margin-top:20px">

<div class="card-header">
<h2>回答データ</h2>
</div>

<div class="card-body">

<?php if ($answerCount === 0): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>回答日時</th>
<th>回答内容</th>
</tr>
</thead>
<tbody>

<?php foreach (
    $surveyAnswers
    as $answer
): ?>

<tr>
<td><?= h(
    $answer['createdAt'] ?? ''
) ?></td>

<td>
<?php
$answerValues =
    $answer['answers'] ?? [];

if (!is_array($answerValues)) {
    $answerValues = [];
}

foreach (
    answer_questions($survey)
    as $question
):
    $value =
        $answerValues[$question['id']]
        ?? '';

    if (is_array($value)) {
        $value = implode(
            '、',
            array_map(
                'strval',
                $value
            )
        );
    }
?>
<div style="margin-bottom:8px">
<strong><?= h(
    $question['number']
) ?></strong>
<?= h((string)$value) ?>
</div>
<?php endforeach; ?>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * 回答画面
 * ========================================================= */

function render_answer(
    array $survey
): void {
    render_head(
        'アンケート回答',
        false
    );

    render_flash();

    $draft =
        $_SESSION['answer_draft'][
            $survey['id']
        ] ?? [];

    if (!is_array($draft)) {
        $draft = [];
    }
?>
<div class="answer-shell">

<div class="card">
<div class="card-body">

<h1><?= h(
    $survey['title']
) ?></h1>

<p><?= nl2br(
    h($survey['description'])
) ?></p>

</div>
</div>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_next">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="card">
<div class="card-body">

<?php foreach (
    answer_questions($survey)
    as $question
): ?>

<div class="preview-question">

<h3>
<?= h($question['number']) ?>
<?= h($question['text']) ?>

<?php if (!empty($question['required'])): ?>
<span class="badge badge-warning">
必須
</span>
<?php endif; ?>

</h3>

<?php
$qid = (string)$question['id'];
$current = $draft[$qid] ?? '';
?>

<?php if (
    $question['type'] === 'single'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<label class="answer-option">
<input type="radio"
       name="answers[<?= h($qid) ?>]"
       value="<?= h(
           $option['id']
       ) ?>"
<?= (string)$current ===
    (string)($option['id'] ?? '')
    ? 'checked'
    : '' ?>>
<span>
<?= h($option['label'] ?? '') ?>
</span>
</label>

<?php endforeach; ?>

<?php elseif (
    $question['type'] === 'multiple'
): ?>

<?php
$currentValues = $current;

if (!is_array($currentValues)) {
    $currentValues = [];
}
?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<label class="answer-option">
<input type="checkbox"
       name="answers[<?= h($qid) ?>][]"
       value="<?= h(
           $option['id']
       ) ?>"
<?= in_array(
    $option['id'],
    $currentValues,
    true
)
    ? 'checked'
    : '' ?>>
<span>
<?= h($option['label'] ?? '') ?>
</span>
</label>

<?php endforeach; ?>

<?php else: ?>

<textarea name="answers[<?= h($qid) ?>]"
          placeholder="回答を入力してください"><?= h(
              (string)$current
          ) ?></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<div class="button-row"
     style="justify-content:flex-end">

<button class="btn btn-primary"
        type="submit">
回答確認へ
</button>

</div>

</form>

</div>
<?php
    render_footer();
}


/* =========================================================
 * 確認
 * ========================================================= */

function render_confirm(
    array $survey
): void {
    render_head(
        '回答確認',
        false
    );

    render_flash();

    $answers =
        $_SESSION['answer_draft'][
            $survey['id']
        ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }
?>
<div class="answer-shell">

<div class="card">
<div class="card-header">
<h1>回答確認</h1>
</div>

<div class="card-body">

<p>
以下の内容で送信します。
</p>

<?php foreach (
    answer_questions($survey)
    as $question
): ?>

<div class="preview-question">

<h3>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</h3>

<?php
$value =
    $answers[$question['id']] ?? '';

if (is_array($value)) {
    $labels = [];

    foreach (
        $question['options'] ?? []
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
                (string)$option['label'];
        }
    }

    $value = implode(
        '、',
        $labels
    );
} else {
    foreach (
        $question['options'] ?? []
        as $option
    ) {
        if (
            (string)$option['id']
            === (string)$value
        ) {
            $value =
                (string)$option['label'];
            break;
        }
    }
}
?>

<p>
<?= nl2br(
    h((string)$value)
) ?>
</p>

</div>

<?php endforeach; ?>

</div>
</div>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'answer',
       'id' => $survey['id'],
   ])) ?>">
修正する
</a>

<form method="post">
<input type="hidden"
       name="action"
       value="answer_confirm">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-primary"
        type="submit"
        data-confirm="回答を送信しますか？">
回答を送信
</button>
</form>

</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * 完了
 * ========================================================= */

function render_complete(
    array $survey
): void {
    render_head(
        '回答完了',
        false
    );
?>
<div class="answer-shell">

<div class="card">
<div class="card-body"
     style="text-align:center;padding:50px 25px">

<h1>回答ありがとうございました</h1>

<p>
「<?= h($survey['title']) ?>」
の回答を受け付けました。
</p>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * ルーティング
 * ========================================================= */

try {
    app_init();

    $data = load_data();
    $settings = load_settings();

    if (refresh_statuses($data)) {
        save_data($data);
    }

    handle_post(
        $data,
        $settings
    );

    /*
     * POST後にデータが変更されている可能性があるため
     * 最新状態を再読込する。
     */
    $data = load_data();
    $settings = load_settings();

    if (refresh_statuses($data)) {
        save_data($data);
    }

    $screen = get_string('screen');

    if ($screen === '') {
        $screen = 'list';
    }

    switch ($screen) {

        case 'list':
            render_list($data);
            break;

        case 'edit':
            $id = get_string('id');

            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($id === '') {
                $survey = null;
            }

            if ($survey === null) {
                /*
                 * 新規作成
                 */
                $survey = [
                    'id' => '',
                    'title' => '',
                    'description' => '',
                    'startAt' => '',
                    'endAt' => '',
                    'status' => 'draft',
                    'numbering' => 'global',
                    'createdAt' => '',
                    'updatedAt' => '',
                    'groups' => [
                        [
                            'id' => uuid('group'),
                            'title' => '基本アンケート',
                            'questions' => [],
                        ],
                    ],
                ];
            }

            $questionId =
                get_string('question');

            if ($questionId !== '') {
                $question =
                    find_question(
                        $survey,
                        $questionId
                    );

                if ($question === null) {
                    render_edit($survey);
                } else {
                    render_question_edit(
                        $survey,
                        $question
                    );
                }
            } else {
                render_edit($survey);
            }

            break;

        case 'preview':
            $id = get_string('id');

            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                redirect_screen('list');
            }

            render_preview($survey);
            break;

        case 'send':
            $id = get_string('id');

            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                redirect_screen('list');
            }

            render_send(
                $survey,
                $data['customers'],
                $data['send_history']
            );
            break;

        case 'analytics':
            $id = get_string('id');

            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                redirect_screen('list');
            }

            render_analytics(
                $survey,
                $data['answers'],
                $data['customers']
            );
            break;

        case 'kintone':
            render_kintone($settings);
            break;

        case 'mail':
            render_mail($settings);
            break;

        case 'answer':
            $id = get_string('id');

            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                http_response_code(404);
                render_head(
                    'アンケートが見つかりません',
                    false
                );
                ?>
                <div class="answer-shell">
                <div class="card">
                <div class="card-body">
                <h1>アンケートが見つかりません</h1>
                <p>
                指定されたアンケートは存在しません。
                </p>
                </div>
                </div>
                </div>
                <?php
                render_footer();
                break;
            }

            if (
                ($survey['status'] ?? '')
                !== 'published'
            ) {
                render_head(
                    '回答できません',
                    false
                );
                ?>
                <div class="answer-shell">
                <div class="card">
                <div class="card-body">
                <h1>現在回答できません</h1>
                <p>
                このアンケートは現在公開されていません。
                </p>
                </div>
                </div>
                </div>
                <?php
                render_footer();
                break;
            }

            render_answer($survey);
            break;

        case 'confirm':
            $id = get_string('id');

            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                redirect_screen('list');
            }

            render_confirm($survey);
            break;

        case 'complete':
            $id = get_string('id');

            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                redirect_screen('list');
            }

            render_complete($survey);
            break;

        default:
            redirect_screen('list');
    }

} catch (Throwable $e) {

    http_response_code(500);

    /*
     * 構文エラーではここへ到達しない。
     * したがって、PHP 8.5の構文として解釈できるコードを
     * ファイル全体で維持することが重要。
     */
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
    <meta charset="utf-8">
    <meta name="viewport"
          content="width=device-width,initial-scale=1">
    <title>システムエラー</title>
    <style>
    body{
        margin:0;
        background:#f8fafc;
        color:#1e293b;
        font-family:
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            "Noto Sans JP",
            Meiryo,
            sans-serif;
    }
    .error{
        width:min(760px,calc(100% - 30px));
        margin:60px auto;
        background:#fff;
        border:1px solid #fecaca;
        border-radius:12px;
        padding:25px;
        box-shadow:0 4px 18px rgba(15,23,42,.08);
    }
    h1{
        color:#991b1b;
    }
    </style>
    </head>
    <body>
    <div class="error">
    <h1>処理に失敗しました</h1>
    <p>
    <?= h(safe_error_message($e)) ?>
    </p>
    <p>
    入力内容、設定、外部サービスの接続状態を確認してください。
    </p>
    </div>
    </body>
    </html>
    <?php
}
?>
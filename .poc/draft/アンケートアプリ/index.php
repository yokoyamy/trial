<?php
declare(strict_types=1);

/**
 * アンケート管理アプリ
 *
 * PHP 8.5 / Apache 2.4
 * DBなし
 * PHP cURLなし
 *
 * 外部通信:
 *   kintone : PHP標準stream
 *   SMTP    : PHP標準stream_socket_client
 *
 * 設計方針:
 *   - index.phpを単一エントリーポイントとする
 *   - DBを使用しない
 *   - サーバー側JSONへ永続化
 *   - kintone設定保存と接続テストを完全分離
 *   - kintone認証はX-Cybozu-Authorization
 *   - APIトークンは使用しない
 *   - 認証情報をHTML/URL/JavaScriptへ出力しない
 *   - POST成功時のみ303 PRG
 *   - POST失敗時もユーザーへ明示的に通知
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const SESSION_NAME = 'survey_app_session';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT = 30;


/* ============================================================
 * 共通
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function post_string(
    string $key,
    string $default = ''
): string {
    $value = $_POST[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function get_string(
    string $key,
    string $default = ''
): string {
    $value = $_GET[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function post_bool(string $key): bool
{
    return isset($_POST[$key])
        && (
            $_POST[$key] === '1'
            || $_POST[$key] === 1
            || $_POST[$key] === true
            || $_POST[$key] === 'on'
        );
}


/* ============================================================
 * セッション
 * ============================================================ */

function application_cookie_path(): string
{
    $script = str_replace(
        '\\',
        '/',
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
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

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
    );

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => application_cookie_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException(
            'PHPセッションを開始できません。'
        );
    }
}


/* ============================================================
 * JSON永続化
 * ============================================================ */

function ensure_data_directory(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (
        !@mkdir(DATA_DIR, 0775, true)
        && !is_dir(DATA_DIR)
    ) {
        throw new RuntimeException(
            'データ保存フォルダを作成できません。'
        );
    }
}

function save_json_file(
    string $file,
    array $data
): void {
    ensure_data_directory();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
    );

    $temporary =
        $file
        . '.tmp.'
        . bin2hex(random_bytes(8));

    $fp = @fopen($temporary, 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                '保存ファイルをロックできません。'
            );
        }

        $length = strlen($json);
        $written = 0;

        while ($written < $length) {
            $result = fwrite(
                $fp,
                substr($json, $written)
            );

            if ($result === false) {
                throw new RuntimeException(
                    'データを書き込めません。'
                );
            }

            $written += $result;
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException(
                '保存ファイルを更新できません。'
            );
        }
    } catch (Throwable $e) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
        @unlink($temporary);

        throw $e;
    }
}

function load_json_file(
    string $file,
    array $default
): array {
    if (!is_file($file)) {
        return $default;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        return $default;
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return $default;
        }

        $contents = stream_get_contents($fp);

        flock($fp, LOCK_UN);
        fclose($fp);

        if (
            $contents === false
            || trim($contents) === ''
        ) {
            return $default;
        }

        $decoded = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return is_array($decoded)
            ? $decoded
            : $default;
    } catch (Throwable) {
        @flock($fp, LOCK_UN);
        @fclose($fp);

        return $default;
    }
}


/* ============================================================
 * 初期データ
 * ============================================================ */

function default_data(): array
{
    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'title' => '顧客満足度アンケート',
                'description' =>
                    'サービスについてのご意見をお聞かせください。',
                'startAt' => date('Y-m-d\TH:i'),
                'endAt' => date(
                    'Y-m-d\TH:i',
                    strtotime('+30 days')
                ),
                'status' => 'published',
                'numbering' => 'global',
                'createdAt' => date('Y-m-d H:i:s'),
                'updatedAt' => date('Y-m-d H:i:s'),
                'groups' => [
                    [
                        'id' => 'group-001',
                        'title' => '基本アンケート',
                        'questions' => [
                            [
                                'id' => 'question-001',
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
                            ],
                            [
                                'id' => 'question-002',
                                'text' =>
                                    'ご意見・ご要望があれば入力してください。',
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
            'port' => '587',
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
    $data = load_json_file(
        DATA_FILE,
        default_data()
    );

    foreach (
        ['surveys', 'answers', 'customers', 'send_history']
        as $key
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

function load_settings(): array
{
    $settings = load_json_file(
        SETTINGS_FILE,
        default_settings()
    );

    $default = default_settings();

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


/* ============================================================
 * アプリ初期化
 * ============================================================ */

function app_init(): void
{
    ensure_data_directory();

    if (!is_file(DATA_FILE)) {
        save_json_file(
            DATA_FILE,
            default_data()
        );
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json_file(
            SETTINGS_FILE,
            default_settings()
        );
    }

    start_session();
}

app_init();


/* ============================================================
 * Flash
 * ============================================================ */

function flash(
    string $type,
    string $message
): void {
    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function take_flash(): array
{
    $items = $_SESSION['flash'] ?? [];

    unset($_SESSION['flash']);

    return is_array($items)
        ? $items
        : [];
}


/* ============================================================
 * URL / PRG
 * ============================================================ */

function app_url(array $params = []): string
{
    $script = str_replace(
        '\\',
        '/',
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
    );

    $base = basename($script);

    if ($params === []) {
        return $base;
    }

    return $base
        . '?'
        . http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}

/**
 * POST成功時のPRG専用。
 *
 * 302ではなく303を明示する。
 * POSTの再送を防ぎ、GETへ確実に切り替える。
 */
function redirect_screen(
    string $screen,
    array $params = []
): never {
    $allowed = [
        'list',
        'edit',
        'preview',
        'analytics',
        'send',
        'kintone',
        'mail',
        'answer',
        'confirm',
        'complete',
    ];

    if (!in_array($screen, $allowed, true)) {
        $screen = 'list';
    }

    $params = array_merge(
        ['screen' => $screen],
        $params
    );

    $location = app_url($params);

    header(
        'Location: ' . $location,
        true,
        303
    );

    exit;
}


/* ============================================================
 * ID
 * ============================================================ */

function new_id(string $prefix): string
{
    return $prefix
        . '-'
        . date('YmdHis')
        . '-'
        . bin2hex(random_bytes(4));
}


/* ============================================================
 * アンケート
 * ============================================================ */

function survey_index(
    array $data,
    string $id
): int {
    foreach (
        $data['surveys'] as $index => $survey
    ) {
        if (
            ($survey['id'] ?? '')
            === $id
        ) {
            return $index;
        }
    }

    return -1;
}

function find_survey(
    array $data,
    string $id
): ?array {
    $index = survey_index(
        $data,
        $id
    );

    if ($index < 0) {
        return null;
    }

    return $data['surveys'][$index];
}

function status_label(string $status): string
{
    return match ($status) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '不明',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'danger',
        default => 'gray',
    };
}

function refresh_survey_status(
    array &$survey
): bool {
    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        return false;
    }

    $endAt =
        (string)($survey['endAt'] ?? '');

    if ($endAt === '') {
        return false;
    }

    $timestamp = strtotime($endAt);

    if (
        $timestamp !== false
        && $timestamp < time()
    ) {
        $survey['status'] = 'ended';

        return true;
    }

    return false;
}

function refresh_all_statuses(
    array &$data
): void {
    $changed = false;

    foreach (
        $data['surveys'] as &$survey
    ) {
        if (
            refresh_survey_status(
                $survey
            )
        ) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        save_json_file(
            DATA_FILE,
            $data
        );
    }
}

function recalculate_question_numbers(
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
                    'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);

        $groupNo++;
    }

    unset($group);
}


/* ============================================================
 * kintone URL
 * ============================================================ */

function normalize_kintone_base_url(
    string $value
): string {
    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (
        !str_starts_with($value, 'http://')
        && !str_starts_with($value, 'https://')
    ) {
        $value = 'https://' . $value;
    }

    $parts = parse_url($value);

    if (
        !is_array($parts)
        || empty($parts['host'])
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    $host = strtolower(
        (string)$parts['host']
    );

    if (
        !str_ends_with(
            $host,
            '.cybozu.com'
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインは xxx.cybozu.com の形式で入力してください。'
        );
    }

    $scheme = strtolower(
        (string)(
            $parts['scheme']
            ?? 'https'
        )
    );

    if (
        !in_array(
            $scheme,
            ['http', 'https'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'kintone URLの形式が不正です。'
        );
    }

    return $scheme . '://' . $host;
}


/* ============================================================
 * Proxy
 * ============================================================ */

function parse_proxy(
    string $proxy
): ?array {
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (
        !preg_match(
            '/^([a-zA-Z0-9._-]+):([0-9]{1,5})$/',
            $proxy,
            $matches
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyは「ホスト:ポート」の形式で入力してください。'
        );
    }

    $port = (int)$matches[2];

    if (
        $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'Proxyのポート番号は1～65535で指定してください。'
        );
    }

    return [
        'host' => $matches[1],
        'port' => $port,
    ];
}


/* ============================================================
 * kintone HTTP
 *
 * PHP cURLは使用しない。
 * ============================================================ */

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $payload = null
): array {
    $base =
        normalize_kintone_base_url(
            (string)(
                $config['subdomain'] ?? ''
            )
        );

    $appId = trim(
        (string)(
            $config['app_id'] ?? ''
        )
    );

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDは1以上の整数で指定してください。'
        );
    }

    $username =
        (string)(
            $config['username'] ?? ''
        );

    $password =
        (string)(
            $config['password'] ?? ''
        );

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    $auth = base64_encode(
        $username . ':' . $password
    );

    $url = $base . $path;

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Cybozu-Authorization: ' . $auth,
        'Connection: close',
    ];

    $body = null;

    if ($payload !== null) {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        $headers[] =
            'Content-Length: '
            . strlen($body);
    }

    $verifySsl =
        !empty($config['verify_ssl']);

    $httpOptions = [
        'method' =>
            strtoupper($method),
        'header' =>
            implode(
                "\r\n",
                $headers
            ),
        'ignore_errors' => true,
        'timeout' =>
            KINTONE_READ_TIMEOUT,
        'protocol_version' => 1.1,
    ];

    if ($body !== null) {
        $httpOptions['content'] =
            $body;
    }

    $proxy = parse_proxy(
        (string)(
            $config['proxy'] ?? ''
        )
    );

    if ($proxy !== null) {
        $httpOptions['proxy'] =
            'tcp://'
            . $proxy['host']
            . ':'
            . $proxy['port'];

        $httpOptions['request_fulluri'] =
            true;
    }

    $context = stream_context_create([
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' =>
                $verifySsl,
            'verify_peer_name' =>
                $verifySsl,
            'allow_self_signed' =>
                !$verifySsl,
            'capture_peer_cert' =>
                false,
        ],
    ]);

    $errorMessage = '';

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$errorMessage): bool {
            $errorMessage = $message;

            return true;
        }
    );

    try {
        $responseBody =
            file_get_contents(
                $url,
                false,
                $context
            );
    } finally {
        restore_error_handler();
    }

    if ($responseBody === false) {
        throw new RuntimeException(
            'kintoneへの通信に失敗しました。'
            . (
                $errorMessage !== ''
                    ? ' 詳細: ' . $errorMessage
                    : ''
            )
        );
    }

    $status = 0;

    foreach (
        $http_response_header ?? []
        as $header
    ) {
        if (
            preg_match(
                '#^HTTP/\S+\s+(\d+)#i',
                $header,
                $matches
            )
        ) {
            $status =
                (int)$matches[1];
        }
    }

    $decoded = null;

    if (
        trim($responseBody) !== ''
    ) {
        $tmp = json_decode(
            $responseBody,
            true
        );

        if (is_array($tmp)) {
            $decoded = $tmp;
        }
    }

    return [
        'url' => $url,
        'status' => $status,
        'body' => $responseBody,
        'json' => $decoded,
    ];
}

function kintone_error_message(
    array $response
): string {
    $json =
        $response['json']
        ?? null;

    if (
        is_array($json)
        && !empty($json['message'])
    ) {
        return (string)$json['message'];
    }

    return match (
        (int)($response['status'] ?? 0)
    ) {
        400 =>
            'kintoneがリクエストを不正と判断しました。',
        401 =>
            'kintoneの認証に失敗しました。',
        403 =>
            'kintoneへのアクセス権限がありません。',
        404 =>
            'kintoneの対象が見つかりません。',
        default =>
            'kintoneから正常な応答を取得できませんでした。',
    };
}


/* ============================================================
 * kintone 接続テスト
 * ============================================================ */

function kintone_test(
    array $config
): array {
    $response = kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
    );

    if (
        $response['status'] < 200
        || $response['status'] >= 300
    ) {
        throw new RuntimeException(
            kintone_error_message(
                $response
            )
        );
    }

    return [
        'success' => true,
        'status' =>
            (int)$response['status'],
        'at' =>
            date('Y-m-d H:i:s'),
        'message' =>
            'kintoneへの接続に成功しました。',
    ];
}


/* ============================================================
 * kintone フィールド取得
 * ============================================================ */

function kintone_get_fields(
    array $config
): array {
    $response = kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
    );

    if (
        $response['status'] < 200
        || $response['status'] >= 300
    ) {
        throw new RuntimeException(
            '項目一覧取得失敗: '
            . kintone_error_message(
                $response
            )
        );
    }

    $properties =
        $response['json']['properties']
        ?? [];

    return is_array($properties)
        ? $properties
        : [];
}


/* ============================================================
 * kintone 顧客同期
 * ============================================================ */

function kintone_record_value(
    array $record,
    string $code
): string {
    if ($code === '') {
        return '';
    }

    if (str_contains($code, ',')) {
        foreach (
            explode(',', $code)
            as $part
        ) {
            $value =
                kintone_record_value(
                    $record,
                    trim($part)
                );

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    $field =
        $record[$code] ?? null;

    if (!is_array($field)) {
        return '';
    }

    $value =
        $field['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] =
                    (string)(
                        $item['value']
                        ?? $item['name']
                        ?? ''
                    );
            } else {
                $parts[] =
                    (string)$item;
            }
        }

        return implode(
            ', ',
            $parts
        );
    }

    return trim(
        (string)$value
    );
}

function kintone_sync_customers(
    array $config,
    array &$data,
    array &$settings
): int {
    $response = kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
        . '&query='
        . rawurlencode('limit 500')
    );

    if (
        $response['status'] < 200
        || $response['status'] >= 300
    ) {
        throw new RuntimeException(
            '顧客同期失敗: '
            . kintone_error_message(
                $response
            )
        );
    }

    $records =
        $response['json']['records']
        ?? [];

    if (!is_array($records)) {
        throw new RuntimeException(
            'kintoneから顧客レコードを取得できませんでした。'
        );
    }

    $mapping =
        $config['mapping']
        ?? [];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' =>
                new_id('customer'),
            'organization' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['organization']
                        ?? ''
                    )
                ),
            'name' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['name']
                        ?? ''
                    )
                ),
            'email' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['email']
                        ?? ''
                    )
                ),
            'department' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['department']
                        ?? ''
                    )
                ),
            'phone' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['phone']
                        ?? ''
                    )
                ),
            'address' =>
                kintone_record_value(
                    $record,
                    implode(
                        ',',
                        (array)(
                            $mapping['address']
                            ?? []
                        )
                    )
                ),
            'source' => 'kintone',
            'syncedAt' =>
                date('Y-m-d H:i:s'),
        ];
    }

    $data['customers'] =
        $customers;

    $settings['kintone']['last_sync'] = [
        'at' =>
            date('Y-m-d H:i:s'),
        'count' =>
            count($customers),
    ];

    save_json_file(
        DATA_FILE,
        $data
    );

    save_json_file(
        SETTINGS_FILE,
        $settings
    );

    return count($customers);
}


/* ============================================================
 * kintone 設定保存
 *
 * ここではkintoneへ接続しない。
 * 「設定保存」と「接続テスト」を完全分離する。
 * ============================================================ */

function save_kintone_settings(
    array &$settings
): void {
    $old =
        $settings['kintone']
        ?? default_settings()['kintone'];

    $subdomain =
        post_string('subdomain');

    $appId =
        post_string('app_id');

    $username =
        post_string('username');

    $password =
        post_string('password');

    $proxy =
        post_string('proxy');

    $verifySsl =
        post_bool('verify_ssl');

    $normalizedUrl =
        normalize_kintone_base_url(
            $subdomain
        );

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDは1以上の整数で入力してください。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    if ($password === '') {
        $password =
            (string)(
                $old['password']
                ?? ''
            );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    parse_proxy($proxy);

    /*
     * 保存する値は正規化する。
     * URL入力値をそのまま保存しない。
     */
    $settings['kintone'] = [
        'subdomain' =>
            $normalizedUrl,
        'app_id' =>
            (string)(int)$appId,
        'username' =>
            $username,
        'password' =>
            $password,
        'proxy' =>
            $proxy,
        'verify_ssl' =>
            $verifySsl,
        'mapping' =>
            is_array(
                $old['mapping'] ?? null
            )
                ? $old['mapping']
                : default_settings()[
                    'kintone'
                ]['mapping'],
        'fields' =>
            is_array(
                $old['fields'] ?? null
            )
                ? $old['fields']
                : [],
        'last_test' =>
            $old['last_test']
            ?? null,
        'last_sync' =>
            $old['last_sync']
            ?? null,
    ];

    /*
     * ここで例外が発生した場合、
     * redirect_screen()はまだ実行されない。
     *
     * つまり「保存失敗したのに成功画面へ
     * リダイレクトする」という状態を作らない。
     */
    save_json_file(
        SETTINGS_FILE,
        $settings
    );
}


/* ============================================================
 * kintone マッピング保存
 * ============================================================ */

function save_kintone_mapping(
    array &$settings
): void {
    $address =
        $_POST['mapping_address']
        ?? [];

    if (!is_array($address)) {
        $address = [];
    }

    $address = array_values(
        array_filter(
            $address,
            static function ($value): bool {
                return is_string($value)
                    && trim($value) !== '';
            }
        )
    );

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
            $address,
    ];

    save_json_file(
        SETTINGS_FILE,
        $settings
    );
}


/* ============================================================
 * アンケート保存
 * ============================================================ */

function save_survey_action(
    array &$data
): void {
    $id =
        post_string('id');

    $title =
        post_string('title');

    $description =
        post_string('description');

    $startAt =
        post_string('startAt');

    $endAt =
        post_string('endAt');

    $numbering =
        post_string(
            'numbering',
            'global'
        );

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (
        $startAt !== ''
        && strtotime($startAt) === false
    ) {
        throw new InvalidArgumentException(
            '開始日時の形式が不正です。'
        );
    }

    if (
        $endAt !== ''
        && strtotime($endAt) === false
    ) {
        throw new InvalidArgumentException(
            '終了日時の形式が不正です。'
        );
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt)
            > strtotime($endAt)
    ) {
        throw new InvalidArgumentException(
            '終了日時は開始日時より後にしてください。'
        );
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

    if ($id === '') {
        $survey = [
            'id' =>
                new_id('survey'),
            'title' =>
                $title,
            'description' =>
                $description,
            'startAt' =>
                $startAt,
            'endAt' =>
                $endAt,
            'status' =>
                'draft',
            'numbering' =>
                $numbering,
            'createdAt' =>
                date('Y-m-d H:i:s'),
            'updatedAt' =>
                date('Y-m-d H:i:s'),
            'groups' => [
                [
                    'id' =>
                        new_id('group'),
                    'title' =>
                        'グループ1',
                    'questions' => [],
                ],
            ],
        ];

        recalculate_question_numbers(
            $survey
        );

        $data['surveys'][] =
            $survey;
    } else {
        $index =
            survey_index(
                $data,
                $id
            );

        if ($index < 0) {
            throw new InvalidArgumentException(
                '対象アンケートが見つかりません。'
            );
        }

        $data['surveys'][$index]['title'] =
            $title;

        $data['surveys'][$index]['description'] =
            $description;

        $data['surveys'][$index]['startAt'] =
            $startAt;

        $data['surveys'][$index]['endAt'] =
            $endAt;

        $data['surveys'][$index]['numbering'] =
            $numbering;

        $data['surveys'][$index]['updatedAt'] =
            date('Y-m-d H:i:s');

        recalculate_question_numbers(
            $data['surveys'][$index]
        );
    }

    save_json_file(
        DATA_FILE,
        $data
    );
}


/* ============================================================
 * ステータス変更
 * ============================================================ */

function change_status_action(
    array &$data
): void {
    $id =
        post_string('id');

    $newStatus =
        post_string('new_status');

    $index =
        survey_index(
            $data,
            $id
        );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    $current =
        $data['surveys'][$index]['status']
        ?? 'draft';

    if ($current === 'ended') {
        throw new InvalidArgumentException(
            '終了したアンケートの状態は変更できません。'
        );
    }

    $allowed = [
        'draft' => ['published'],
        'published' => ['stopped'],
        'stopped' => ['published'],
    ];

    if (
        !isset($allowed[$current])
        || !in_array(
            $newStatus,
            $allowed[$current],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '指定された状態変更は許可されていません。'
        );
    }

    $data['surveys'][$index]['status'] =
        $newStatus;

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    save_json_file(
        DATA_FILE,
        $data
    );
}


/* ============================================================
 * アンケート削除
 * ============================================================ */

function delete_survey_action(
    array &$data
): void {
    $id =
        post_string('id');

    $index =
        survey_index(
            $data,
            $id
        );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    array_splice(
        $data['surveys'],
        $index,
        1
    );

    save_json_file(
        DATA_FILE,
        $data
    );
}


/* ============================================================
 * アンケート複製
 * ============================================================ */

function duplicate_survey_action(
    array &$data
): void {
    $id =
        post_string('id');

    $survey =
        find_survey(
            $data,
            $id
        );

    if ($survey === null) {
        throw new InvalidArgumentException(
            '複製対象のアンケートが見つかりません。'
        );
    }

    $survey['id'] =
        new_id('survey');

    $survey['title'] =
        (string)(
            $survey['title'] ?? ''
        )
        . '（複製）';

    $survey['status'] =
        'draft';

    $survey['createdAt'] =
        date('Y-m-d H:i:s');

    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    foreach (
        $survey['groups']
        as &$group
    ) {
        $group['id'] =
            new_id('group');

        foreach (
            $group['questions']
            as &$question
        ) {
            $question['id'] =
                new_id('question');

            foreach (
                $question['options']
                ?? []
                as &$option
            ) {
                $option['id'] =
                    new_id('option');
            }

            unset($option);
        }

        unset($question);
    }

    unset($group);

    $data['surveys'][] =
        $survey;

    save_json_file(
        DATA_FILE,
        $data
    );
}


/* ============================================================
 * 回答保存
 * ============================================================ */

function save_answer_action(
    array &$data
): void {
    $surveyId =
        post_string('survey_id');

    $survey =
        find_survey(
            $data,
            $surveyId
        );

    if ($survey === null) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        throw new InvalidArgumentException(
            'このアンケートは現在回答を受け付けていません。'
        );
    }

    $answers =
        $_POST['answer'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $normalized = [];

    foreach (
        $survey['groups']
        as $group
    ) {
        foreach (
            $group['questions']
            as $question
        ) {
            $qid =
                (string)$question['id'];

            $value =
                $answers[$qid] ?? '';

            if (
                ($question['required'] ?? false)
                && (
                    $value === ''
                    || $value === []
                )
            ) {
                throw new InvalidArgumentException(
                    '必須項目が未回答です。'
                );
            }

            if (is_array($value)) {
                $value =
                    array_values(
                        array_map(
                            'strval',
                            $value
                        )
                    );
            } else {
                $value =
                    trim(
                        (string)$value
                    );
            }

            $normalized[$qid] =
                $value;
        }
    }

    $_SESSION['answer_draft'] = [
        'surveyId' =>
            $surveyId,
        'answers' =>
            $normalized,
    ];
}

function finalize_answer_action(
    array &$data
): void {
    $draft =
        $_SESSION['answer_draft']
        ?? null;

    if (!is_array($draft)) {
        throw new RuntimeException(
            '回答情報が見つかりません。最初から回答してください。'
        );
    }

    $surveyId =
        (string)(
            $draft['surveyId']
            ?? ''
        );

    if (
        find_survey(
            $data,
            $surveyId
        ) === null
    ) {
        throw new RuntimeException(
            '回答対象アンケートが見つかりません。'
        );
    }

    $data['answers'][] = [
        'id' =>
            new_id('answer'),
        'surveyId' =>
            $surveyId,
        'answers' =>
            is_array(
                $draft['answers']
                ?? null
            )
                ? $draft['answers']
                : [],
        'createdAt' =>
            date('Y-m-d H:i:s'),
    ];

    save_json_file(
        DATA_FILE,
        $data
    );

    unset(
        $_SESSION['answer_draft']
    );
}


/* ============================================================
 * POST処理
 *
 * 重要:
 * すべてのPOST処理はここで完結させる。
 * 成功した場合だけ303。
 * 例外の場合も必ずユーザーへ通知する。
 * ============================================================ */

function handle_post(
    array &$data,
    array &$settings
): void {
    $action =
        post_string('action');

    if ($action === '') {
        flash(
            'error',
            '操作が指定されていません。'
        );

        redirect_screen(
            'list'
        );
    }

    try {
        switch ($action) {
            case 'save_kintone':
                save_kintone_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                redirect_screen(
                    'kintone'
                );

            case 'test_kintone':
                $temporary =
                    $settings['kintone'];

                $temporary['subdomain'] =
                    post_string(
                        'subdomain'
                    );

                $temporary['app_id'] =
                    post_string(
                        'app_id'
                    );

                $temporary['username'] =
                    post_string(
                        'username'
                    );

                $enteredPassword =
                    post_string(
                        'password'
                    );

                if (
                    $enteredPassword !== ''
                ) {
                    $temporary['password'] =
                        $enteredPassword;
                }

                $temporary['proxy'] =
                    post_string('proxy');

                $temporary['verify_ssl'] =
                    post_bool(
                        'verify_ssl'
                    );

                $test =
                    kintone_test(
                        $temporary
                    );

                $_SESSION['kintone_test'] =
                    $test;

                flash(
                    'success',
                    'kintone接続テストに成功しました。'
                );

                redirect_screen(
                    'kintone'
                );

            case 'refresh_kintone_fields':
                $fields =
                    kintone_get_fields(
                        $settings['kintone']
                    );

                $settings['kintone']['fields'] =
                    $fields;

                save_json_file(
                    SETTINGS_FILE,
                    $settings
                );

                flash(
                    'success',
                    'kintone項目一覧を取得しました。'
                );

                redirect_screen(
                    'kintone'
                );

            case 'sync_kintone':
                $count =
                    kintone_sync_customers(
                        $settings['kintone'],
                        $data,
                        $settings
                    );

                flash(
                    'success',
                    'kintoneから顧客情報を'
                    . $count
                    . '件同期しました。'
                );

                redirect_screen(
                    'kintone'
                );

            case 'save_kintone_mapping':
                save_kintone_mapping(
                    $settings
                );

                flash(
                    'success',
                    'kintone項目マッピングを保存しました。'
                );

                redirect_screen(
                    'kintone'
                );

            case 'save_survey':
                save_survey_action(
                    $data
                );

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                redirect_screen(
                    'list'
                );

            case 'change_status':
                change_status_action(
                    $data
                );

                flash(
                    'success',
                    'アンケートの状態を変更しました。'
                );

                redirect_screen(
                    'list'
                );

            case 'duplicate_survey':
                duplicate_survey_action(
                    $data
                );

                flash(
                    'success',
                    'アンケートを複製しました。'
                );

                redirect_screen(
                    'list'
                );

            case 'delete_survey':
                delete_survey_action(
                    $data
                );

                flash(
                    'success',
                    'アンケートを削除しました。'
                );

                redirect_screen(
                    'list'
                );

            case 'answer':
                save_answer_action(
                    $data
                );

                redirect_screen(
                    'confirm',
                    [
                        'id' =>
                            post_string(
                                'survey_id'
                            ),
                    ]
                );

            case 'finalize_answer':
                finalize_answer_action(
                    $data
                );

                redirect_screen(
                    'complete'
                );

            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }
    } catch (Throwable $e) {
        /*
         * ここが今回の再発防止上の重要ポイント。
         *
         * 例外を握り潰して先に302/303を返さない。
         * エラー内容をFlashへ保存してから、
         * 固定された画面へ303する。
         *
         * パスワード、Authorizationヘッダー等は
         * エラーメッセージに含めない。
         */
        $message =
            $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : '処理に失敗しました。保存先、入力値、または外部サービスの設定を確認してください。';

        flash(
            'error',
            $message
        );

        switch ($action) {
            case 'save_kintone':
            case 'test_kintone':
            case 'refresh_kintone_fields':
            case 'sync_kintone':
            case 'save_kintone_mapping':
                redirect_screen(
                    'kintone'
                );

            case 'save_survey':
                redirect_screen(
                    'edit',
                    [
                        'id' =>
                            post_string('id'),
                    ]
                );

            case 'answer':
                redirect_screen(
                    'answer',
                    [
                        'id' =>
                            post_string(
                                'survey_id'
                            ),
                    ]
                );

            default:
                redirect_screen(
                    'list'
                );
        }
    }
}


/* ============================================================
 * CSS
 * ============================================================ */

function render_header(
    string $title
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
:root {
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

* {
    box-sizing:border-box;
}

body {
    margin:0;
    background:#f8fafc;
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Yu Gothic",
        Meiryo,
        sans-serif;
    line-height:1.6;
}

a {
    color:var(--primary);
    text-decoration:none;
}

a:hover {
    text-decoration:underline;
}

header {
    background:#fff;
    border-bottom:1px solid var(--border);
}

.header-inner {
    max-width:1400px;
    margin:0 auto;
    padding:16px 24px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand {
    font-size:20px;
    font-weight:800;
    color:var(--text);
}

.nav {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.nav a {
    padding:8px 12px;
    border-radius:8px;
    color:#475569;
}

.nav a:hover {
    background:var(--gray-light);
}

.container {
    max-width:1400px;
    margin:0 auto;
    padding:32px 24px;
}

.page-title {
    margin:0 0 8px;
    font-size:28px;
}

.page-description {
    color:var(--gray);
    margin-bottom:24px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.card-header {
    padding:18px 20px;
    border-bottom:1px solid var(--border);
}

.card-body {
    padding:20px;
}

.grid {
    display:grid;
    gap:18px;
}

.grid-2 {
    grid-template-columns:
        repeat(2,minmax(0,1fr));
}

label {
    display:block;
}

label > span {
    display:block;
    margin-bottom:6px;
    font-weight:700;
}

input,
textarea,
select {
    width:100%;
    padding:10px 12px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    background:#fff;
    color:var(--text);
    font:inherit;
}

textarea {
    min-height:120px;
    resize:vertical;
}

input:focus,
textarea:focus,
select:focus {
    outline:3px solid rgba(37,99,235,.12);
    border-color:var(--primary);
}

.button-row {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
}

.btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:42px;
    padding:9px 15px;
    border:0;
    border-radius:8px;
    cursor:pointer;
    font:inherit;
    font-weight:700;
    text-decoration:none;
}

.btn:hover {
    text-decoration:none;
}

.btn-primary {
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover {
    background:var(--primary-dark);
}

.btn-success {
    background:var(--success);
    color:#fff;
}

.btn-warning {
    background:var(--warning);
    color:#fff;
}

.btn-danger {
    background:var(--danger);
    color:#fff;
}

.btn-secondary {
    background:#475569;
    color:#fff;
}

.btn-light {
    background:#f1f5f9;
    color:#334155;
}

.alert {
    padding:13px 16px;
    border-radius:8px;
    margin-bottom:16px;
}

.alert-success {
    background:#dcfce7;
    color:#166534;
}

.alert-error {
    background:#fee2e2;
    color:#991b1b;
}

.alert-info {
    background:#dbeafe;
    color:#1e40af;
}

.alert-warning {
    background:#fef3c7;
    color:#92400e;
}

.badge {
    display:inline-block;
    padding:3px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge-success {
    background:#dcfce7;
    color:#166534;
}

.badge-warning {
    background:#fef3c7;
    color:#92400e;
}

.badge-danger {
    background:#fee2e2;
    color:#991b1b;
}

.badge-gray {
    background:#e2e8f0;
    color:#475569;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
}

th,
td {
    padding:12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}

th {
    background:#f8fafc;
    white-space:nowrap;
}

.small {
    color:var(--gray);
    font-size:13px;
}

.actions {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}

@media(max-width:800px) {
    .grid-2 {
        grid-template-columns:1fr;
    }

    .header-inner {
        align-items:flex-start;
        flex-direction:column;
    }

    .container {
        padding:20px 14px;
    }
}
</style>
</head>

<body>

<header>
<div class="header-inner">

<a class="brand"
   href="<?= h(app_url(['screen' => 'list'])) ?>">
    <?= h(APP_TITLE) ?>
</a>

<nav class="nav">
<a href="<?= h(app_url(['screen' => 'list'])) ?>">
    アンケート一覧
</a>

<a href="<?= h(app_url(['screen' => 'kintone'])) ?>">
    kintone
</a>

<a href="<?= h(app_url(['screen' => 'mail'])) ?>">
    メール
</a>
</nav>

</div>
</header>

<main class="container">

<?php
}

function render_footer(): void
{
    ?>
</main>

<script>
document.querySelectorAll(
    'form[data-loading]'
).forEach(function(form) {
    form.addEventListener(
        'submit',
        function() {
            var buttons =
                form.querySelectorAll(
                    'button[type="submit"]'
                );

            buttons.forEach(function(button) {
                button.disabled = true;
                button.dataset.originalText =
                    button.textContent;

                button.textContent =
                    '処理中です…';
            });
        }
    );
});

document.querySelectorAll(
    '[data-confirm]'
).forEach(function(element) {
    element.addEventListener(
        'click',
        function(event) {
            var message =
                element.getAttribute(
                    'data-confirm'
                );

            if (
                message
                && !window.confirm(message)
            ) {
                event.preventDefault();
            }
        }
    );
});
</script>

</body>
</html>
<?php
}


/* ============================================================
 * Flash表示
 * ============================================================ */

function render_flash(): void
{
    foreach (
        take_flash()
        as $item
    ) {
        $type =
            ($item['type'] ?? '')
            === 'success'
                ? 'alert-success'
                : 'alert-error';

        echo '<div class="alert '
            . $type
            . '">'
            . h(
                (string)(
                    $item['message']
                    ?? ''
                )
            )
            . '</div>';
    }
}


/* ============================================================
 * 一覧
 * ============================================================ */

function render_list(
    array $data
): void {
    render_header(
        'アンケート一覧'
    );

    render_flash();

    ?>
<h1 class="page-title">
アンケート一覧
</h1>

<p class="page-description">
アンケートの作成、公開、集計、送信を管理します。
</p>

<div class="button-row"
     style="margin-bottom:20px">

<a class="btn btn-primary"
   href="<?= h(
       app_url([
           'screen' => 'edit'
       ])
   ) ?>">
    新規アンケート作成
</a>

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

<?php foreach (
    $data['surveys']
    as $survey
): ?>

<?php
$id =
    (string)(
        $survey['id']
        ?? ''
    );

$status =
    (string)(
        $survey['status']
        ?? 'draft'
    );

$answerCount = 0;

foreach (
    $data['answers']
    as $answer
) {
    if (
        ($answer['surveyId'] ?? '')
        === $id
    ) {
        $answerCount++;
    }
}
?>

<tr>

<td>
<strong>
<?= h(
    (string)(
        $survey['title']
        ?? ''
    )
) ?>
</strong>
</td>

<td>
<?= h(
    (string)(
        $survey['createdAt']
        ?? ''
    )
) ?>
</td>

<td>
<?= h(
    (string)(
        $survey['updatedAt']
        ?? ''
    )
) ?>
</td>

<td>
<?= h(
    (string)(
        $survey['startAt']
        ?? ''
    )
) ?>
<br>
～
<br>
<?= h(
    (string)(
        $survey['endAt']
        ?? ''
    )
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

<td>
<?= $answerCount ?>件
</td>

<td>

<div class="actions">

<a class="btn btn-light"
   href="<?= h(
       app_url([
           'screen' => 'edit',
           'id' => $id,
       ])
   ) ?>">
    編集
</a>

<a class="btn btn-light"
   href="<?= h(
       app_url([
           'screen' => 'analytics',
           'id' => $id,
       ])
   ) ?>">
    集計
</a>

<a class="btn btn-light"
   href="<?= h(
       app_url([
           'screen' => 'preview',
           'id' => $id,
       ])
   ) ?>">
    プレビュー
</a>

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="id"
       value="<?= h($id) ?>">

<button class="btn btn-light"
        type="submit"
        data-confirm="このアンケートを複製しますか？">
    複製
</button>
</form>

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="id"
       value="<?= h($id) ?>">

<button class="btn btn-danger"
        type="submit"
        data-confirm="このアンケートを削除しますか？">
    削除
</button>
</form>

<?php if (
    $status === 'draft'
): ?>

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="id"
       value="<?= h($id) ?>">
<input type="hidden"
       name="new_status"
       value="published">

<button class="btn btn-success"
        type="submit"
        data-confirm="このアンケートを公開しますか？">
    公開
</button>
</form>

<?php elseif (
    $status === 'published'
): ?>

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="id"
       value="<?= h($id) ?>">
<input type="hidden"
       name="new_status"
       value="stopped">

<button class="btn btn-warning"
        type="submit"
        data-confirm="このアンケートを停止しますか？">
    停止
</button>
</form>

<?php elseif (
    $status === 'stopped'
): ?>

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="id"
       value="<?= h($id) ?>">
<input type="hidden"
       name="new_status"
       value="published">

<button class="btn btn-success"
        type="submit"
        data-confirm="このアンケートを再開しますか？">
    再開
</button>
</form>

<?php endif; ?>

</div>

</td>

</tr>

<?php endforeach; ?>

<?php if (
    count($data['surveys']) === 0
): ?>

<tr>
<td colspan="7">
アンケートがありません。
</td>
</tr>

<?php endif; ?>

</tbody>
</table>
</div>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * 編集
 * ============================================================ */

function render_edit(
    array $data,
    ?array $survey
): void {
    $isNew =
        $survey === null;

    if ($isNew) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => '',
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
        ];
    }

    render_header(
        $isNew
            ? 'アンケート作成'
            : 'アンケート編集'
    );

    render_flash();

    ?>

<h1 class="page-title">
<?= $isNew
    ? 'アンケート作成'
    : 'アンケート編集' ?>
</h1>

<div class="card">
<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?= h(
           (string)$survey['id']
       ) ?>">

<div class="grid grid-2">

<label>
<span>アンケートタイトル</span>
<input type="text"
       name="title"
       maxlength="200"
       required
       value="<?= h(
           (string)$survey['title']
       ) ?>">
</label>

<label>
<span>質問番号</span>
<select name="numbering">
<option value="global"
    <?= ($survey['numbering'] ?? 'global')
        === 'global'
        ? 'selected'
        : '' ?>>
    アンケート全体で通番
</option>

<option value="group"
    <?= ($survey['numbering'] ?? '')
        === 'group'
        ? 'selected'
        : '' ?>>
    グループ毎
</option>
</select>
</label>

<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h(
           (string)$survey['startAt']
       ) ?>">
</label>

<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h(
           (string)$survey['endAt']
       ) ?>">
</label>

</div>

<label style="margin-top:18px">
<span>説明</span>
<textarea name="description"><?= h(
    (string)$survey['description']
) ?></textarea>
</label>

<div class="button-row"
     style="margin-top:20px">

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen' => 'list'
       ])
   ) ?>">
    キャンセル
</a>

<button class="btn btn-primary"
        type="submit">
    保存して一覧へ
</button>

</div>

</form>

</div>
</div>

<?php if (!$isNew): ?>

<div class="card">
<div class="card-header">
<h2>質問・グループ</h2>
</div>

<div class="card-body">

<?php foreach (
    $survey['groups']
    as $gi => $group
): ?>

<div class="card"
     style="box-shadow:none">

<div class="card-header">

<strong>
グループ<?= $gi + 1 ?>：
<?= h(
    (string)(
        $group['title']
        ?? ''
    )
) ?>
</strong>

</div>

<div class="card-body">

<?php foreach (
    $group['questions']
    as $qi => $question
): ?>

<div style="
border:1px solid var(--border);
border-radius:8px;
padding:14px;
margin-bottom:12px;
">

<strong>
<?= h(
    (string)(
        $question['number']
        ?? ('Q' . ($qi + 1))
    )
) ?>
</strong>

<div style="margin-top:8px">
<?= h(
    (string)(
        $question['text']
        ?? ''
    )
) ?>
</div>

</div>

<?php endforeach; ?>

<?php if (
    count(
        $group['questions']
    ) === 0
): ?>

<div class="small">
質問はまだありません。
</div>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

<div class="alert alert-info">
質問編集機能は同一POST処理系統で保存され、
保存成功時のみ303で編集画面へ戻る構成にできます。
</div>

</div>
</div>

<?php endif; ?>

<?php
    render_footer();
}


/* ============================================================
 * kintone画面
 * ============================================================ */

function render_kintone(
    array $settings
): void {
    $k =
        $settings['kintone'];

    render_header(
        'kintone連携設定'
    );

    render_flash();

    ?>

<h1 class="page-title">
kintone連携設定
</h1>

<p class="page-description">
kintoneの顧客管理アプリと連携します。
</p>

<?php
$test =
    $_SESSION['kintone_test']
    ?? null;

unset(
    $_SESSION['kintone_test']
);

if (is_array($test)): ?>

<div class="alert alert-success">
<?= h(
    (string)(
        $test['message']
        ?? '接続テストに成功しました。'
    )
) ?>
</div>

<?php endif; ?>

<div class="card">

<div class="card-header">
<h2>接続設定</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid grid-2">

<label>
<span>サブドメイン</span>

<input type="text"
       name="subdomain"
       required
       placeholder="xxxx.cybozu.com"
       value="<?= h(
           (string)(
               $k['subdomain']
               ?? ''
           )
       ) ?>">

<div class="small">
xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com の形式に対応します。
</div>

</label>

<label>
<span>顧客管理アプリID</span>

<input type="number"
       name="app_id"
       min="1"
       required
       value="<?= h(
           (string)(
               $k['app_id']
               ?? ''
           )
       ) ?>">
</label>

<label>
<span>ログイン名</span>

<input type="text"
       name="username"
       autocomplete="off"
       required
       value="<?= h(
           (string)(
               $k['username']
               ?? ''
           )
       ) ?>">
</label>

<label>
<span>パスワード</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</label>

<label>
<span>Proxy</span>

<input type="text"
       name="proxy"
       placeholder="proxy.example.local:8080"
       value="<?= h(
           (string)(
               $k['proxy']
               ?? ''
           )
       ) ?>">

<div class="small">
未入力の場合は直接接続します。
</div>

</label>

<label>
<span>SSL証明書検証</span>

<label>
<input type="checkbox"
       name="verify_ssl"
       value="1"
       <?= !empty(
           $k['verify_ssl']
       )
           ? 'checked'
           : '' ?>>
SSL証明書を検証する
</label>

<div class="small">
POCでは無効にできます。
</div>

</label>

</div>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
    設定保存
</button>

</div>

</form>

<hr style="
margin:24px 0;
border:0;
border-top:1px solid var(--border);
">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="test_kintone">

<div class="grid grid-2">

<label>
<span>接続テスト サブドメイン</span>
<input type="text"
       name="subdomain"
       required
       value="<?= h(
           (string)(
               $k['subdomain']
               ?? ''
           )
       ) ?>">
</label>

<label>
<span>接続テスト アプリID</span>
<input type="number"
       name="app_id"
       min="1"
       required
       value="<?= h(
           (string)(
               $k['app_id']
               ?? ''
           )
       ) ?>">
</label>

<label>
<span>接続テスト ログイン名</span>
<input type="text"
       name="username"
       required
       value="<?= h(
           (string)(
               $k['username']
               ?? ''
           )
       ) ?>">
</label>

<label>
<span>接続テスト パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みパスワードを使用する場合は空欄">
</label>

<label>
<span>Proxy</span>
<input type="text"
       name="proxy"
       value="<?= h(
           (string)(
               $k['proxy']
               ?? ''
           )
       ) ?>">
</label>

<label>
<span>SSL証明書検証</span>
<label>
<input type="checkbox"
       name="verify_ssl"
       value="1"
       <?= !empty(
           $k['verify_ssl']
       )
           ? 'checked'
           : '' ?>>
検証する
</label>
</label>

</div>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-success"
        type="submit">
    接続テスト
</button>

</div>

</form>

</div>
</div>


<div class="card">

<div class="card-header">
<h2>項目一覧</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="refresh_kintone_fields">

<button class="btn btn-secondary"
        type="submit">
    項目一覧を再取得
</button>

</form>

<?php
$fields =
    $k['fields']
    ?? [];

if (
    is_array($fields)
    && count($fields) > 0
): ?>

<div class="table-wrap"
     style="margin-top:20px">

<table>

<thead>
<tr>
<th>フィールドコード</th>
<th>表示名</th>
<th>種類</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $fields as $code => $field
): ?>

<?php if (
    !is_array($field)
): ?>
<?php continue; ?>
<?php endif; ?>

<tr>

<td>
<?= h(
    (string)$code
) ?>
</td>

<td>
<?= h(
    (string)(
        $field['label']
        ?? ''
    )
) ?>
</td>

<td>
<?= h(
    (string)(
        $field['type']
        ?? ''
    )
) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

<?php else: ?>

<p class="small">
項目一覧はまだ取得されていません。
</p>

<?php endif; ?>

</div>
</div>


<div class="card">

<div class="card-header">
<h2>顧客情報マッピング</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="grid grid-2">

<label>
<span>組織名</span>
<select name="mapping_organization">
<option value="">未設定</option>
<?php
foreach (
    $fields as $code => $field
):
if (!is_array($field)) {
    continue;
}
?>
<option value="<?= h(
    (string)$code
) ?>"
<?= (
    (string)(
        $k['mapping']['organization']
        ?? ''
    )
    === (string)$code
)
    ? 'selected'
    : '' ?>>
<?= h(
    (string)$code
) ?>
：<?= h(
    (string)(
        $field['label']
        ?? ''
    )
) ?>
</option>
<?php endforeach; ?>
</select>
</label>

<label>
<span>氏名</span>
<select name="mapping_name">
<option value="">未設定</option>
<?php
foreach (
    $fields as $code => $field
):
if (!is_array($field)) {
    continue;
}
?>
<option value="<?= h(
    (string)$code
) ?>"
<?= (
    (string)(
        $k['mapping']['name']
        ?? ''
    )
    === (string)$code
)
    ? 'selected'
    : '' ?>>
<?= h(
    (string)$code
) ?>
：<?= h(
    (string)(
        $field['label']
        ?? ''
    )
) ?>
</option>
<?php endforeach; ?>
</select>
</label>

<label>
<span>メールアドレス</span>
<select name="mapping_email">
<option value="">未設定</option>
<?php
foreach (
    $fields as $code => $field
):
if (!is_array($field)) {
    continue;
}
?>
<option value="<?= h(
    (string)$code
) ?>"
<?= (
    (string)(
        $k['mapping']['email']
        ?? ''
    )
    === (string)$code
)
    ? 'selected'
    : '' ?>>
<?= h(
    (string)$code
) ?>
：<?= h(
    (string)(
        $field['label']
        ?? ''
    )
) ?>
</option>
<?php endforeach; ?>
</select>
</label>

<label>
<span>部署名</span>
<select name="mapping_department">
<option value="">未設定</option>
<?php
foreach (
    $fields as $code => $field
):
if (!is_array($field)) {
    continue;
}
?>
<option value="<?= h(
    (string)$code
) ?>"
<?= (
    (string)(
        $k['mapping']['department']
        ?? ''
    )
    === (string)$code
)
    ? 'selected'
    : '' ?>>
<?= h(
    (string)$code
) ?>
：<?= h(
    (string)(
        $field['label']
        ?? ''
    )
) ?>
</option>
<?php endforeach; ?>
</select>
</label>

<label>
<span>電話番号</span>
<select name="mapping_phone">
<option value="">未設定</option>
<?php
foreach (
    $fields as $code => $field
):
if (!is_array($field)) {
    continue;
}
?>
<option value="<?= h(
    (string)$code
) ?>"
<?= (
    (string)(
        $k['mapping']['phone']
        ?? ''
    )
    === (string)$code
)
    ? 'selected'
    : '' ?>>
<?= h(
    (string)$code
) ?>
：<?= h(
    (string)(
        $field['label']
        ?? ''
    )
) ?>
</option>
<?php endforeach; ?>
</select>
</label>

</div>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
    マッピング保存
</button>

</div>

</form>

<hr style="
margin:24px 0;
border:0;
border-top:1px solid var(--border);
">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-primary"
        type="submit"
        data-confirm="kintoneから顧客情報を同期しますか？">
    顧客情報を同期
</button>

</form>

<?php if (
    !empty($k['last_sync'])
): ?>

<div class="alert alert-success"
     style="margin-top:16px">

最終同期：
<?= h(
    (string)(
        $k['last_sync']['at']
        ?? ''
    )
) ?>

／

<?= (int)(
    $k['last_sync']['count']
    ?? 0
) ?>件

</div>

<?php endif; ?>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * メール設定
 * ============================================================ */

function render_mail(
    array $settings
): void {
    render_header(
        'メールサーバ設定'
    );

    render_flash();

    $mail =
        $settings['mail'];

    ?>

<h1 class="page-title">
メールサーバ設定
</h1>

<p class="page-description">
SMTP設定を管理します。
</p>

<div class="card">
<div class="card-body">

<form method="post">

<div class="grid grid-2">

<label>
<span>SMTPサーバ</span>
<input type="text"
       name="smtp_host"
       value="<?= h(
           (string)(
               $mail['host']
               ?? ''
           )
       ) ?>">
</label>

<label>
<span>SMTPポート</span>
<input type="number"
       name="smtp_port"
       min="1"
       max="65535"
       value="<?= h(
           (string)(
               $mail['port']
               ?? '587'
           )
       ) ?>">
</label>

<label>
<span>暗号化方式</span>
<select name="smtp_encryption">
<option value="tls">TLS</option>
<option value="ssl">SSL</option>
<option value="none">なし</option>
</select>
</label>

<label>
<span>SMTP認証</span>
<select name="smtp_auth">
<option value="1">使用する</option>
<option value="0">使用しない</option>
</select>
</label>

<label>
<span>SMTPユーザー名</span>
<input type="text"
       name="smtp_username"
       value="<?= h(
           (string)(
               $mail['username']
               ?? ''
           )
       ) ?>">
</label>

<label>
<span>SMTPパスワード</span>
<input type="password"
       name="smtp_password"
       autocomplete="new-password">
</label>

<label>
<span>送信元メールアドレス</span>
<input type="email"
       name="from_email"
       value="<?= h(
           (string)(
               $mail['from_email']
               ?? ''
           )
       ) ?>">
</label>

<label>
<span>送信元名</span>
<input type="text"
       name="from_name"
       value="<?= h(
           (string)(
               $mail['from_name']
               ?? ''
           )
       ) ?>">
</label>

<label>
<span>返信先</span>
<input type="email"
       name="reply_to"
       value="<?= h(
           (string)(
               $mail['reply_to']
               ?? ''
           )
       ) ?>">
</label>

</div>

<div class="alert alert-info"
     style="margin-top:20px">
SMTP認証情報はHTML、URL、JavaScriptへ出力しません。
</div>

<button class="btn btn-primary"
        type="button"
        onclick="alert('メール設定の保存処理はこの単一POST処理系統へ接続してください。')">
    設定保存
</button>

</form>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(
    ?array $survey
): void {
    render_header(
        'アンケートプレビュー'
    );

    render_flash();

    if ($survey === null) {
        ?>
<div class="alert alert-error">
アンケートが見つかりません。
</div>
<?php
        render_footer();
        return;
    }

    ?>

<div class="card">
<div class="card-body">

<h1 class="page-title">
<?= h(
    (string)$survey['title']
) ?>
</h1>

<p>
<?= nl2br(
    h(
        (string)(
            $survey['description']
            ?? ''
        )
    )
) ?>
</p>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">

<div class="card-header">
<h2>
<?= h(
    (string)(
        $group['title']
        ?? ''
    )
) ?>
</h2>
</div>

<div class="card-body">

<?php foreach (
    $group['questions']
    as $question
): ?>

<div style="
margin-bottom:24px;
">

<strong>
<?= h(
    (string)(
        $question['number']
        ?? ''
    )
) ?>

<?= h(
    (string)(
        $question['text']
        ?? ''
    )
) ?>
</strong>

<?php
$type =
    $question['type']
    ?? 'text';

if ($type === 'single'):
?>

<div style="margin-top:8px">
<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>
<label style="
margin:6px 0;
">
<input type="radio"
       disabled>
<?= h(
    (string)(
        $option['label']
        ?? ''
    )
) ?>
</label>
<?php endforeach; ?>
</div>

<?php elseif (
    $type === 'multiple'
): ?>

<div style="margin-top:8px">
<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>
<label style="
margin:6px 0;
">
<input type="checkbox"
       disabled>
<?= h(
    (string)(
        $option['label']
        ?? ''
    )
) ?>
</label>
<?php endforeach; ?>
</div>

<?php else: ?>

<textarea disabled
          style="margin-top:8px"></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * 回答
 * ============================================================ */

function render_answer(
    array $survey
): void {
    render_header(
        'アンケート回答'
    );

    render_flash();

    ?>

<div class="card">
<div class="card-body">

<h1 class="page-title">
<?= h(
    (string)$survey['title']
) ?>
</h1>

<p>
<?= nl2br(
    h(
        (string)(
            $survey['description']
            ?? ''
        )
    )
) ?>
</p>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="answer">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           (string)$survey['id']
       ) ?>">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">

<div class="card-header">
<h2>
<?= h(
    (string)(
        $group['title']
        ?? ''
    )
) ?>
</h2>
</div>

<div class="card-body">

<?php foreach (
    $group['questions']
    as $question
): ?>

<div style="
margin-bottom:24px;
">

<label>
<span>
<?= h(
    (string)(
        $question['number']
        ?? ''
    )
) ?>
<?= h(
    (string)(
        $question['text']
        ?? ''
    )
) ?>

<?php if (
    !empty($question['required'])
): ?>
<span style="
color:var(--danger);
display:inline;
">
*
</span>
<?php endif; ?>

</span>

<?php
$qid =
    (string)$question['id'];

$type =
    $question['type']
    ?? 'text';

if ($type === 'single'):
?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label style="
margin:8px 0;
font-weight:normal;
">

<input type="radio"
       name="answer[<?= h($qid) ?>]"
       value="<?= h(
           (string)(
               $option['id']
               ?? ''
           )
       ) ?>"
<?= !empty(
    $question['required']
)
    ? 'required'
    : '' ?>>

<?= h(
    (string)(
        $option['label']
        ?? ''
    )
) ?>

</label>

<?php endforeach; ?>

<?php elseif (
    $type === 'multiple'
): ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label style="
margin:8px 0;
font-weight:normal;
">

<input type="checkbox"
       name="answer[<?= h($qid) ?>][]"
       value="<?= h(
           (string)(
               $option['id']
               ?? ''
           )
       ) ?>">

<?= h(
    (string)(
        $option['label']
        ?? ''
    )
) ?>

</label>

<?php endforeach; ?>

<?php else: ?>

<textarea
    name="answer[<?= h($qid) ?>]"
    <?= !empty(
        $question['required']
    )
        ? 'required'
        : '' ?>></textarea>

<?php endif; ?>

</label>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<button class="btn btn-primary"
        type="submit">
    回答内容を確認
</button>

</form>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * 回答確認
 * ============================================================ */

function render_confirm(
    array $survey
): void {
    render_header(
        '回答確認'
    );

    render_flash();

    $draft =
        $_SESSION['answer_draft']
        ?? [];

    ?>

<div class="card">
<div class="card-body">

<h1 class="page-title">
回答確認
</h1>

<p>
<?= h(
    (string)$survey['title']
) ?>
</p>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">

<div class="card-header">
<h2>
<?= h(
    (string)(
        $group['title']
        ?? ''
    )
) ?>
</h2>
</div>

<div class="card-body">

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$qid =
    (string)$question['id'];

$value =
    $draft['answers'][$qid]
    ?? '';

if (is_array($value)) {
    $value =
        implode(
            ', ',
            array_map(
                'strval',
                $value
            )
        );
}
?>

<div style="
margin-bottom:18px;
">

<strong>
<?= h(
    (string)(
        $question['number']
        ?? ''
    )
) ?>

<?= h(
    (string)(
        $question['text']
        ?? ''
    )
) ?>
</strong>

<div style="
margin-top:6px;
white-space:pre-wrap;
">
<?= h(
    (string)$value
) ?>
</div>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen' => 'answer',
           'id' =>
               $survey['id'],
       ])
   ) ?>">
    戻って修正
</a>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="finalize_answer">

<button class="btn btn-primary"
        type="submit"
        data-confirm="この回答を送信しますか？">
    回答を送信
</button>

</form>

</div>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * 完了
 * ============================================================ */

function render_complete(): void
{
    render_header(
        '回答完了'
    );

    ?>

<div class="card">
<div class="card-body">

<h1 class="page-title">
回答ありがとうございました
</h1>

<p>
回答を受け付けました。
</p>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * 集計
 * ============================================================ */

function render_analytics(
    ?array $survey,
    array $data
): void {
    render_header(
        '回答集計・分析'
    );

    render_flash();

    if ($survey === null) {
        ?>
<div class="alert alert-error">
対象アンケートが見つかりません。
</div>
<?php
        render_footer();
        return;
    }

    $answers = array_filter(
        $data['answers'],
        static function (
            array $answer
        ) use ($survey): bool {
            return (
                ($answer['surveyId'] ?? '')
                === ($survey['id'] ?? '')
            );
        }
    );

    ?>

<h1 class="page-title">
回答集計・分析
</h1>

<p class="page-description">
対象：
<?= h(
    (string)$survey['title']
) ?>
</p>

<div class="card">
<div class="card-body">

<strong>
回答数：
<?= count($answers) ?>件
</strong>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * メイン
 * ============================================================ */

$data =
    load_data();

$settings =
    load_settings();

refresh_all_statuses(
    $data
);

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {
    handle_post(
        $data,
        $settings
    );

    exit;
}

$screen =
    get_string(
        'screen',
        'list'
    );

if (
    $screen === 'answer'
    || $screen === 'confirm'
) {
    $id =
        get_string('id');

    $survey =
        find_survey(
            $data,
            $id
        );

    if ($survey === null) {
        render_header(
            'アンケート'
        );

        ?>
<div class="alert alert-error">
アンケートが見つかりません。
</div>
<?php

        render_footer();
        exit;
    }

    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        render_header(
            'アンケート'
        );

        ?>
<div class="alert alert-warning">
このアンケートは現在回答を受け付けていません。
</div>
<?php

        render_footer();
        exit;
    }

    if ($screen === 'answer') {
        render_answer(
            $survey
        );
    } else {
        render_confirm(
            $survey
        );
    }

    exit;
}

if (
    $screen === 'complete'
) {
    render_complete();
    exit;
}

switch ($screen) {
    case 'edit':
        $id =
            get_string('id');

        render_edit(
            $data,
            $id === ''
                ? null
                : find_survey(
                    $data,
                    $id
                )
        );
        break;

    case 'preview':
        render_preview(
            find_survey(
                $data,
                get_string('id')
            )
        );
        break;

    case 'analytics':
        render_analytics(
            find_survey(
                $data,
                get_string('id')
            ),
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

    case 'list':
    default:
        render_list(
            $data
        );
        break;
}
<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * 単一ファイル版 index.php
 *
 * PHP 8.5 / Apache 2.4
 * DBなし
 * PHP cURLなし
 *
 * 外部通信:
 *   kintone : PHP標準stream
 *   SMTP    : PHP標準stream_socket_client
 *
 * 重要:
 * - kintone操作ではPOST後に303だけ返して終了しない
 * - kintone操作は同一リクエスト内で結果画面を生成する
 * - kintone設定保存と接続テストを完全分離
 * - kintone API認証はX-Cybozu-Authorization
 * - APIトークンは使用しない
 * - 機密情報をHTML / URL / JavaScript / ログへ出さない
 * - DBを使用しない
 * - サーバー側JSONを使用する
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';

const DATA_DIR =
    __DIR__ . DIRECTORY_SEPARATOR . '_data';

const DATA_FILE =
    DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';

const SETTINGS_FILE =
    DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const SESSION_NAME =
    'survey_app_session';

const KINTONE_TIMEOUT = 30;

const SMTP_TIMEOUT = 30;


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
    $value = $_POST[$key] ?? null;

    return $value === '1'
        || $value === 1
        || $value === true
        || $value === 'on'
        || $value === 'true';
}


/* ============================================================
 * セッション
 * ============================================================ */

function app_cookie_path(): string
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

    $secure =
        (
            !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off'
        )
        || (int)(
            $_SERVER['SERVER_PORT'] ?? 80
        ) === 443;

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_cookie_path(),
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


/* ============================================================
 * JSON
 * ============================================================ */

function ensure_data_dir(): void
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

function load_json_file(
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

        $contents = stream_get_contents($fp);

        flock($fp, LOCK_UN);
        fclose($fp);

        if (
            $contents === false
            || trim($contents) === ''
        ) {
            return $fallback;
        }

        $decoded = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return is_array($decoded)
            ? $decoded
            : $fallback;

    } catch (Throwable) {
        @flock($fp, LOCK_UN);
        @fclose($fp);

        return $fallback;
    }
}

function save_json_file(
    string $file,
    array $data
): void {
    ensure_data_dir();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
    );

    $tmp =
        $file
        . '.tmp.'
        . bin2hex(random_bytes(8));

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            '一時保存ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                '保存ファイルをロックできません。'
            );
        }

        $length = strlen($json);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite(
                $fp,
                substr($json, $offset)
            );

            if ($written === false) {
                throw new RuntimeException(
                    'JSONを書き込めません。'
                );
            }

            $offset += $written;
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            throw new RuntimeException(
                'JSONファイルを更新できません。'
            );
        }

    } catch (Throwable $e) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
        @unlink($tmp);

        throw $e;
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
                'startAt' =>
                    date('Y-m-d\TH:i'),
                'endAt' =>
                    date(
                        'Y-m-d\TH:i',
                        strtotime('+30 days')
                    ),
                'status' => 'published',
                'numbering' => 'global',
                'createdAt' =>
                    date('Y-m-d H:i:s'),
                'updatedAt' =>
                    date('Y-m-d H:i:s'),
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
            is_array(
                $settings['kintone'] ?? null
            )
                ? $settings['kintone']
                : []
        );

    $settings['mail'] =
        array_replace_recursive(
            $default['mail'],
            is_array(
                $settings['mail'] ?? null
            )
                ? $settings['mail']
                : []
        );

    return $settings;
}

function app_init(): void
{
    ensure_data_dir();

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
 * URL
 * ============================================================ */

function app_url(
    array $params = []
): string {
    $base =
        $_SERVER['SCRIPT_NAME']
        ?? 'index.php';

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
 * 通常の画面遷移用PRG。
 *
 * kintone操作では使用しない。
 * kintone操作は同一リクエストで結果を表示する。
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
        'answer',
        'confirm',
        'complete',
        'mail',
    ];

    if (!in_array(
        $screen,
        $allowed,
        true
    )) {
        $screen = 'list';
    }

    $params =
        array_merge(
            ['screen' => $screen],
            $params
        );

    header(
        'Location: '
        . app_url($params),
        true,
        303
    );

    exit;
}


/* ============================================================
 * ID
 * ============================================================ */

function new_id(
    string $prefix
): string {
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
        $data['surveys']
        as $index => $survey
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
    $index =
        survey_index(
            $data,
            $id
        );

    if ($index < 0) {
        return null;
    }

    return $data['surveys'][$index];
}

function recalculate_question_numbers(
    array &$survey
): void {
    $global = 1;
    $groupNo = 1;

    foreach (
        $survey['groups']
        as &$group
    ) {
        $questionNo = 1;

        foreach (
            $group['questions']
            as &$question
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

function refresh_all_statuses(
    array &$data
): void {
    $changed = false;

    foreach (
        $data['surveys']
        as &$survey
    ) {
        if (
            ($survey['status'] ?? '')
            !== 'published'
        ) {
            continue;
        }

        $endAt =
            (string)(
                $survey['endAt']
                ?? ''
            );

        if ($endAt === '') {
            continue;
        }

        $timestamp =
            strtotime($endAt);

        if (
            $timestamp !== false
            && $timestamp < time()
        ) {
            $survey['status'] =
                'ended';

            $survey['updatedAt'] =
                date('Y-m-d H:i:s');

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
        'ended' => 'danger',
        default => 'gray',
    };
}

function count_answers(
    array $data,
    string $surveyId
): int {
    $count = 0;

    foreach (
        $data['answers']
        as $answer
    ) {
        if (
            ($answer['surveyId'] ?? '')
            === $surveyId
        ) {
            $count++;
        }
    }

    return $count;
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
        !str_starts_with(
            $value,
            'http://'
        )
        && !str_starts_with(
            $value,
            'https://'
        )
    ) {
        $value =
            'https://' . $value;
    }

    $parts =
        parse_url($value);

    if (
        !is_array($parts)
        || empty($parts['host'])
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    $host =
        strtolower(
            (string)$parts['host']
        );

    if (
        !str_ends_with(
            $host,
            '.cybozu.com'
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインは'
            . ' xxx.cybozu.com '
            . 'の形式で入力してください。'
        );
    }

    $scheme =
        strtolower(
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

    return
        $scheme
        . '://'
        . $host;
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

    $port =
        (int)$matches[2];

    if (
        $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'Proxyのポート番号は1～65535です。'
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
 * PHP cURLは絶対に使用しない。
 *
 * 重要:
 * allow_redirects相当の処理を意図的に行わない。
 * kintone REST APIから303等が返った場合、
 * そのままAPI応答として扱う。
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
                $config['subdomain']
                ?? ''
            )
        );

    $appId =
        trim(
            (string)(
                $config['app_id']
                ?? ''
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
            $config['username']
            ?? ''
        );

    $password =
        (string)(
            $config['password']
            ?? ''
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

    $url =
        $base . $path;

    /*
     * Basic認証情報をX-Cybozu-Authorizationへ変換。
     *
     * この値は変数内だけに存在し、
     * HTML / URL / JavaScript / ログには出力しない。
     */
    $authorization =
        base64_encode(
            $username
            . ':'
            . $password
        );

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Cybozu-Authorization: '
            . $authorization,
        'Connection: close',
    ];

    $body = null;

    if ($payload !== null) {
        $body =
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );

        $headers[] =
            'Content-Length: '
            . strlen($body);
    }

    $proxy =
        parse_proxy(
            (string)(
                $config['proxy']
                ?? ''
            )
        );

    $http = [
        'method' =>
            strtoupper($method),
        'header' =>
            implode(
                "\r\n",
                $headers
            ),
        'ignore_errors' => true,
        'timeout' =>
            KINTONE_TIMEOUT,
        'protocol_version' => 1.1,
    ];

    if ($body !== null) {
        $http['content'] =
            $body;
    }

    if ($proxy !== null) {
        $http['proxy'] =
            'tcp://'
            . $proxy['host']
            . ':'
            . $proxy['port'];

        $http['request_fulluri'] =
            true;
    }

    $verify =
        !empty(
            $config['verify_ssl']
        );

    $context =
        stream_context_create([
            'http' => $http,
            'ssl' => [
                'verify_peer' =>
                    $verify,
                'verify_peer_name' =>
                    $verify,
                'allow_self_signed' =>
                    !$verify,
            ],
        ]);

    $warning = '';

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$warning): bool {
            $warning = $message;
            return true;
        }
    );

    try {
        $stream =
            fopen(
                $url,
                'rb',
                false,
                $context
            );
    } finally {
        restore_error_handler();
    }

    if ($stream === false) {
        throw new RuntimeException(
            'kintoneへのHTTPS接続に失敗しました。'
            . (
                $warning !== ''
                    ? ' 詳細: ' . $warning
                    : ''
            )
        );
    }

    $responseBody =
        stream_get_contents(
            $stream
        );

    $meta =
        stream_get_meta_data(
            $stream
        );

    fclose($stream);

    if ($responseBody === false) {
        $responseBody = '';
    }

    $status = 0;

    foreach (
        ($meta['wrapper_data'] ?? [])
        as $header
    ) {
        if (
            preg_match(
                '#^HTTP/\S+\s+(\d{3})#i',
                (string)$header,
                $matches
            )
        ) {
            $status =
                (int)$matches[1];
        }
    }

    $json = null;

    if (
        trim($responseBody) !== ''
    ) {
        $decoded =
            json_decode(
                $responseBody,
                true
            );

        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'status' => $status,
        'body' => $responseBody,
        'json' => $json,
    ];
}


/* ============================================================
 * kintone エラー
 * ============================================================ */

function kintone_response_message(
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
        (int)(
            $response['status']
            ?? 0
        )
    ) {
        400 =>
            'kintoneがリクエストを不正と判断しました。',
        401 =>
            'kintoneの認証に失敗しました。',
        403 =>
            'kintoneへのアクセス権限がありません。',
        404 =>
            'kintoneの対象が見つかりません。',
        429 =>
            'kintoneのリクエスト制限に達しました。',
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
    $started =
        microtime(true);

    $result = [
        'success' => false,
        'status' => 0,
        'elapsed' => 0,
        'message' => '',
        'detail' => '',
        'steps' => [],
    ];

    $result['steps'][] =
        '1. 入力値を検証しました。';

    $base =
        normalize_kintone_base_url(
            (string)$config['subdomain']
        );

    $result['steps'][] =
        '2. 接続先: '
        . $base;

    $proxy =
        parse_proxy(
            (string)(
                $config['proxy']
                ?? ''
            )
        );

    if ($proxy === null) {
        $result['steps'][] =
            '3. Proxy: 使用しません。';
    } else {
        $result['steps'][] =
            '3. Proxyを使用します。';
    }

    $result['steps'][] =
        '4. kintone REST APIへ接続します。';

    $path =
        '/k/v1/records.json?'
        . 'app='
        . rawurlencode(
            (string)$config['app_id']
        )
        . '&totalCount=true'
        . '&query='
        . rawurlencode(
            'limit 1'
        );

    $response =
        kintone_request(
            $config,
            'GET',
            $path
        );

    $status =
        (int)$response['status'];

    $result['status'] =
        $status;

    $result['elapsed'] =
        round(
            microtime(true)
            - $started,
            3
        );

    $result['steps'][] =
        '5. HTTPステータス: '
        . $status;

    if (
        $status >= 200
        && $status < 300
    ) {
        $result['success'] =
            true;

        $result['message'] =
            'kintone接続に成功しました。';

        $result['detail'] =
            '認証および指定アプリへのアクセスを確認しました。';

        $result['steps'][] =
            '6. 認証・アプリ確認に成功しました。';

        return $result;
    }

    $result['message'] =
        kintone_response_message(
            $response
        );

    $result['detail'] =
        'HTTP '
        . $status;

    if (
        is_array(
            $response['json']
            ?? null
        )
        && !empty(
            $response['json']['id']
        )
    ) {
        $result['detail'] .=
            ' / エラーID: '
            . (string)(
                $response['json']['id']
            );
    }

    $result['steps'][] =
        '6. kintone APIからエラー応答を受信しました。';

    return $result;
}


/* ============================================================
 * kintone フィールド取得
 * ============================================================ */

function kintone_get_fields(
    array $config
): array {
    $response =
        kintone_request(
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
            'kintone項目取得に失敗しました。'
            . ' HTTP '
            . $response['status']
            . ' / '
            . kintone_response_message(
                $response
            )
        );
    }

    $properties =
        $response['json']['properties']
        ?? [];

    if (!is_array($properties)) {
        throw new RuntimeException(
            'kintone項目一覧の形式が不正です。'
        );
    }

    return [
        'properties' =>
            $properties,
    ];
}


/* ============================================================
 * kintone 顧客同期
 * ============================================================ */

function kintone_record_value(
    array $record,
    string $code
): string {
    $code =
        trim($code);

    if ($code === '') {
        return '';
    }

    if (
        str_contains(
            $code,
            ','
        )
    ) {
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
        $record[$code]
        ?? null;

    if (!is_array($field)) {
        return '';
    }

    $value =
        $field['value']
        ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach (
            $value as $item
        ) {
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
    $response =
        kintone_request(
            $config,
            'GET',
            '/k/v1/records.json?'
            . 'app='
            . rawurlencode(
                (string)$config['app_id']
            )
            . '&totalCount=true'
            . '&query='
            . rawurlencode(
                'limit 500'
            )
        );

    if (
        $response['status'] < 200
        || $response['status'] >= 300
    ) {
        throw new RuntimeException(
            'kintone顧客同期に失敗しました。'
            . ' HTTP '
            . $response['status']
            . ' / '
            . kintone_response_message(
                $response
            )
        );
    }

    $records =
        $response['json']['records']
        ?? [];

    if (!is_array($records)) {
        throw new RuntimeException(
            'kintoneレコードの形式が不正です。'
        );
    }

    $mapping =
        $config['mapping']
        ?? [];

    $customers = [];

    foreach (
        $records as $record
    ) {
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
 * kintone設定保存
 *
 * ここではkintoneへ接続しない。
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

    $verify =
        post_bool('verify_ssl');

    $normalized =
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

    /*
     * パスワード空欄の場合は既存値を維持。
     */
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

    $settings['kintone'] = [
        'subdomain' =>
            $normalized,

        'app_id' =>
            (string)(int)$appId,

        'username' =>
            $username,

        'password' =>
            $password,

        'proxy' =>
            $proxy,

        'verify_ssl' =>
            $verify,

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
     * ここでは外部通信しない。
     *
     * 保存だけが成功したかを確認する。
     */
    save_json_file(
        SETTINGS_FILE,
        $settings
    );
}


/* ============================================================
 * kintoneマッピング保存
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

    $address =
        array_values(
            array_filter(
                $address,
                static function (
                    mixed $value
                ): bool {
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

function save_survey(
    array &$data
): string {
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

        save_json_file(
            DATA_FILE,
            $data
        );

        return (string)$survey['id'];
    }

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

    $current =
        $data['surveys'][$index];

    $data['surveys'][$index]['title'] =
        $title;

    $data['surveys'][$index]['description'] =
        $description;

    $data['surveys'][$index]['startAt'] =
        $startAt;

    $data['surveys'][$index]['endAt'] =
        $endAt;

    $data['surveys'][$index]['status'] =
        $current['status']
        ?? 'draft';

    $data['surveys'][$index]['numbering'] =
        $numbering;

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    recalculate_question_numbers(
        $data['surveys'][$index]
    );

    save_json_file(
        DATA_FILE,
        $data
    );

    return $id;
}


/* ============================================================
 * 質問保存
 * ============================================================ */

function add_group(
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

    $data['surveys'][$index]['groups'][] = [
        'id' =>
            new_id('group'),

        'title' =>
            '新しいグループ',

        'questions' => [],
    ];

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    recalculate_question_numbers(
        $data['surveys'][$index]
    );

    save_json_file(
        DATA_FILE,
        $data
    );
}

function add_question(
    array &$data
): void {
    $id =
        post_string('id');

    $groupId =
        post_string('group_id');

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

    foreach (
        $data['surveys'][$index]['groups']
        as &$group
    ) {
        if (
            ($group['id'] ?? '')
            !== $groupId
        ) {
            continue;
        }

        $group['questions'][] = [
            'id' =>
                new_id('question'),

            'text' =>
                '新しい質問',

            'type' =>
                'single',

            'required' =>
                false,

            'options' => [
                [
                    'id' =>
                        new_id('option'),

                    'label' =>
                        '選択肢1',

                    'nextQuestionId' =>
                        '',
                ],
                [
                    'id' =>
                        new_id('option'),

                    'label' =>
                        '選択肢2',

                    'nextQuestionId' =>
                        '',
                ],
            ],
        ];

        break;
    }

    unset($group);

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    recalculate_question_numbers(
        $data['surveys'][$index]
    );

    save_json_file(
        DATA_FILE,
        $data
    );
}

function delete_group(
    array &$data
): void {
    $id =
        post_string('id');

    $groupId =
        post_string('group_id');

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

    $groups =
        &$data['surveys'][$index]['groups'];

    if (count($groups) <= 1) {
        throw new InvalidArgumentException(
            '最後のグループは削除できません。'
        );
    }

    $groups =
        array_values(
            array_filter(
                $groups,
                static fn($group) =>
                    ($group['id'] ?? '')
                    !== $groupId
            )
        );

    unset($groups);

    recalculate_question_numbers(
        $data['surveys'][$index]
    );

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    save_json_file(
        DATA_FILE,
        $data
    );
}

function delete_question(
    array &$data
): void {
    $id =
        post_string('id');

    $questionId =
        post_string('question_id');

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

    foreach (
        $data['surveys'][$index]['groups']
        as &$group
    ) {
        $group['questions'] =
            array_values(
                array_filter(
                    $group['questions'] ?? [],
                    static fn($question) =>
                        ($question['id'] ?? '')
                        !== $questionId
                )
            );
    }

    unset($group);

    recalculate_question_numbers(
        $data['surveys'][$index]
    );

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    save_json_file(
        DATA_FILE,
        $data
    );
}

function save_questions(
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

    $survey =
        &$data['surveys'][$index];

    foreach (
        $survey['groups']
        as $gi => &$group
    ) {
        $groupTitle =
            post_string(
                'group_title_' . $gi
            );

        if ($groupTitle !== '') {
            $group['title'] =
                $groupTitle;
        }

        foreach (
            $group['questions']
            as $qi => &$question
        ) {
            $question['text'] =
                post_string(
                    'question_text_'
                    . $gi
                    . '_'
                    . $qi
                );

            $type =
                post_string(
                    'question_type_'
                    . $gi
                    . '_'
                    . $qi
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
                $type = 'single';
            }

            $question['type'] =
                $type;

            $question['required'] =
                post_bool(
                    'question_required_'
                    . $gi
                    . '_'
                    . $qi
                );

            if ($type === 'text') {
                $question['options'] =
                    [];
                continue;
            }

            $labels =
                $_POST[
                    'options_'
                    . $gi
                    . '_'
                    . $qi
                ] ?? [];

            $nexts =
                $_POST[
                    'nexts_'
                    . $gi
                    . '_'
                    . $qi
                ] ?? [];

            $options = [];

            if (is_array($labels)) {
                foreach (
                    $labels
                    as $oi => $label
                ) {
                    $label =
                        trim(
                            (string)$label
                        );

                    if ($label === '') {
                        continue;
                    }

                    $oldId =
                        $question[
                            'options'
                        ][$oi]['id']
                        ?? new_id('option');

                    $next =
                        is_array($nexts)
                            ? trim(
                                (string)(
                                    $nexts[$oi]
                                    ?? ''
                                )
                            )
                            : '';

                    $options[] = [
                        'id' =>
                            $oldId,

                        'label' =>
                            $label,

                        'nextQuestionId' =>
                            $next,
                    ];
                }
            }

            $question['options'] =
                $options;
        }

        unset($question);
    }

    unset($group);

    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    recalculate_question_numbers(
        $survey
    );

    save_json_file(
        DATA_FILE,
        $data
    );
}


/* ============================================================
 * ステータス
 * ============================================================ */

function change_status(
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

    $allowed = [
        'draft' =>
            ['published'],

        'published' =>
            ['stopped'],

        'stopped' =>
            ['published'],
    ];

    if (
        !isset(
            $allowed[$current]
        )
        || !in_array(
            $newStatus,
            $allowed[$current],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '指定された状態変更はできません。'
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
 * 複製
 * ============================================================ */

function duplicate_survey(
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
            $survey['title']
            ?? ''
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
 * 削除
 * ============================================================ */

function delete_survey(
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
 * 回答
 * ============================================================ */

function save_answer(
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
            'このアンケートは回答を受け付けていません。'
        );
    }

    $answers =
        $_POST['answer']
        ?? [];

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
                $answers[$qid]
                ?? '';

            if (
                !empty(
                    $question['required']
                )
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

function finalize_answer(
    array &$data
): void {
    $draft =
        $_SESSION['answer_draft']
        ?? null;

    if (!is_array($draft)) {
        throw new RuntimeException(
            '回答情報がありません。'
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
            '回答対象アンケートがありません。'
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
 * HTML
 * ============================================================ */

function render_header(
    string $title,
    string $screen = ''
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>
<?= h($title) ?>
 -
 <?= h(APP_TITLE) ?>
</title>

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
    --shadow:
        0 4px 18px
        rgba(15,23,42,.08);
}

* {
    box-sizing:border-box;
}

html,
body {
    margin:0;
    padding:0;
}

body {
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

.app-header {
    background:#fff;
    border-bottom:
        1px solid var(--border);
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
    flex-wrap:wrap;
    gap:8px;
}

.nav a {
    padding:8px 12px;
    border-radius:8px;
    color:#475569;
}

.nav a:hover,
.nav a.active {
    background:#eff6ff;
    color:var(--primary);
    text-decoration:none;
}

.container {
    max-width:1400px;
    margin:0 auto;
    padding:28px 24px 60px;
}

.page-title {
    margin:0 0 6px;
    font-size:28px;
    font-weight:800;
}

.page-description {
    margin:0 0 24px;
    color:var(--gray);
}

.card {
    background:#fff;
    border:
        1px solid var(--border);
    border-radius:14px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.card-header {
    padding:18px 20px;
    border-bottom:
        1px solid var(--border);

    display:flex;
    justify-content:space-between;
    align-items:center;

    gap:12px;
}

.card-header h2,
.card-header h3 {
    margin:0;
    font-size:18px;
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
        repeat(
            2,
            minmax(0,1fr)
        );
}

.grid-3 {
    grid-template-columns:
        repeat(
            3,
            minmax(0,1fr)
        );
}

label {
    display:block;
    font-weight:700;
    font-size:14px;
}

label > span {
    display:block;
    margin-bottom:6px;
}

input[type="text"],
input[type="number"],
input[type="email"],
input[type="password"],
input[type="datetime-local"],
input[type="url"],
select,
textarea {
    width:100%;
    border:
        1px solid #cbd5e1;

    border-radius:8px;

    padding:10px 12px;

    background:#fff;
    color:var(--text);

    font:inherit;
}

textarea {
    min-height:120px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus {
    outline:
        3px solid
        rgba(37,99,235,.12);

    border-color:
        var(--primary);
}

.button-row {
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:10px;
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

.alert-warning {
    background:#fef3c7;
    color:#92400e;
}

.alert-info {
    background:#dbeafe;
    color:#1e40af;
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

.table-scroll {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
}

th,
td {
    padding:12px;
    border-bottom:
        1px solid var(--border);

    text-align:left;
    vertical-align:top;
}

th {
    background:#f8fafc;
    white-space:nowrap;
}

.actions {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}

.help {
    color:var(--gray);
    font-size:13px;
    margin-top:5px;
}

.test-result {
    padding:18px;
    border-radius:10px;
    border:1px solid var(--border);
}

.test-result.success {
    background:#f0fdf4;
    border-color:#86efac;
}

.test-result.failure {
    background:#fff7ed;
    border-color:#fdba74;
}

.test-result-title {
    font-size:18px;
    font-weight:800;
    margin-bottom:8px;
}

.test-step {
    display:flex;
    gap:8px;
    margin-top:6px;
}

.empty {
    padding:40px 20px;
    text-align:center;
    color:var(--gray);
}

.question-card {
    border:
        1px solid var(--border);
    border-radius:10px;
    margin-bottom:14px;
    overflow:hidden;
}

.question-head {
    background:#f8fafc;
    padding:14px;
    border-bottom:
        1px solid var(--border);

    display:flex;
    gap:10px;
    align-items:center;
}

.question-body {
    padding:14px;
}

.answer-option {
    padding:10px 0;
    font-weight:400;
}

.stat-grid {
    display:grid;
    grid-template-columns:
        repeat(4,minmax(0,1fr));
    gap:16px;
}

.stat {
    background:#fff;
    border:
        1px solid var(--border);
    border-radius:12px;
    padding:20px;
}

.stat-label {
    color:var(--gray);
    font-size:13px;
}

.stat-value {
    font-size:30px;
    font-weight:800;
}

@media(max-width:900px) {
    .grid-2,
    .grid-3,
    .stat-grid {
        grid-template-columns:1fr;
    }

    .header-inner {
        align-items:flex-start;
        flex-direction:column;
    }

    .container {
        padding:
            20px 14px 40px;
    }
}

</style>
</head>

<body>

<header class="app-header">

<div class="header-inner">

<a class="brand"
   href="<?= h(
       app_url([
           'screen' => 'list'
       ])
   ) ?>">
    <?= h(APP_TITLE) ?>
</a>

<nav class="nav">

<a class="<?= $screen === 'list'
    ? 'active'
    : '' ?>"
   href="<?= h(
       app_url([
           'screen' => 'list'
       ])
   ) ?>">
    アンケート一覧
</a>

<a class="<?= $screen === 'kintone'
    ? 'active'
    : '' ?>"
   href="<?= h(
       app_url([
           'screen' => 'kintone'
       ])
   ) ?>">
    kintone
</a>

<a class="<?= $screen === 'mail'
    ? 'active'
    : '' ?>"
   href="<?= h(
       app_url([
           'screen' => 'mail'
       ])
   ) ?>">
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

document
.querySelectorAll(
    'form[data-loading]'
)
.forEach(function(form) {

    form.addEventListener(
        'submit',
        function() {

            form
            .querySelectorAll(
                'button[type="submit"]'
            )
            .forEach(function(button) {

                button.disabled = true;

                button.dataset.originalText =
                    button.textContent;

                button.textContent =
                    '処理中です…';
            });
        }
    );
});

document
.querySelectorAll(
    '[data-confirm]'
)
.forEach(function(element) {

    element.addEventListener(
        'click',
        function(event) {

            var message =
                element.getAttribute(
                    'data-confirm'
                );

            if (
                message
                && !window.confirm(
                    message
                )
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
                : (
                    ($item['type'] ?? '')
                    === 'warning'
                        ? 'alert-warning'
                        : 'alert-error'
                );

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
        'アンケート一覧',
        'list'
    );

    render_flash();

    ?>

<h1 class="page-title">
アンケート一覧
</h1>

<p class="page-description">
アンケートの作成・公開・回答・集計を管理します。
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

<div class="table-scroll">

<table>

<thead>
<tr>
<th>タイトル</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>更新日</th>
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
<?= count_answers(
    $data,
    $id
) ?>件
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
           'screen' => 'preview',
           'id' => $id,
       ])
   ) ?>">
    確認
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
           'screen' => 'send',
           'id' => $id,
       ])
   ) ?>">
    送信
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

</div>

</td>

</tr>

<?php endforeach; ?>

<?php if (
    count($data['surveys']) === 0
): ?>

<tr>
<td colspan="6">
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
            : 'アンケート編集',
        'edit'
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
<?= (
    ($survey['numbering'] ?? 'global')
    === 'global'
)
    ? 'selected'
    : '' ?>>
アンケート全体で通番
</option>

<option value="group"
<?= (
    ($survey['numbering'] ?? '')
    === 'group'
)
    ? 'selected'
    : '' ?>>
グループ毎に採番
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

<span>アンケート説明</span>

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

<h2>
質問・グループ
</h2>

</div>

<div class="card-body">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">

<div class="card-header">

<h3>
<?= h(
    (string)(
        $group['title']
        ?? ''
    )
) ?>
</h3>

</div>

<div class="card-body">

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<div class="question-head">

<strong>
<?= h(
    (string)(
        $question['number']
        ?? ''
    )
) ?>
</strong>

<span>
<?= h(
    (string)(
        $question['text']
        ?? ''
    )
) ?>
</span>

</div>

</div>

<?php endforeach; ?>

<form method="post">

<input type="hidden"
       name="action"
       value="add_question">

<input type="hidden"
       name="id"
       value="<?= h(
           (string)$survey['id']
       ) ?>">

<input type="hidden"
       name="group_id"
       value="<?= h(
           (string)(
               $group['id']
               ?? ''
           )
       ) ?>">

<button class="btn btn-light"
        type="submit">
    質問を追加
</button>

</form>

</div>

</div>

<?php endforeach; ?>

<form method="post">

<input type="hidden"
       name="action"
       value="add_group">

<input type="hidden"
       name="id"
       value="<?= h(
           (string)$survey['id']
       ) ?>">

<button class="btn btn-secondary"
        type="submit">
    グループを追加
</button>

</form>

</div>

</div>

<?php endif; ?>

<?php
    render_footer();
}


/* ============================================================
 * kintone
 *
 * ここが今回の問題の再発防止の中心。
 *
 * kintone POST:
 *
 *   POST
 *     ↓
 *   処理
 *     ↓
 *   そのまま render_kintone()
 *     ↓
 *   HTTP 200
 *
 * 303を返さない。
 * ============================================================ */

function render_kintone(
    array $settings,
    array $data,
    ?array $operation = null
): void {
    $k =
        $settings['kintone']
        ?? default_settings()['kintone'];

    $fields =
        $k['fields']
        ?? [];

    $customers =
        $data['customers']
        ?? [];

    render_header(
        'kintone連携設定',
        'kintone'
    );

    render_flash();

    ?>

<h1 class="page-title">
kintone連携設定
</h1>

<p class="page-description">
kintoneの顧客管理アプリと接続します。
</p>

<?php if (
    is_array($operation)
): ?>

<div class="card">

<div class="card-header">

<h2>
処理結果
</h2>

</div>

<div class="card-body">

<?php if (
    !empty($operation['success'])
): ?>

<div class="test-result success">

<div class="test-result-title">
✓ <?= h(
    (string)(
        $operation['title']
        ?? '処理成功'
    )
) ?>
</div>

<p>
<?= h(
    (string)(
        $operation['message']
        ?? ''
    )
) ?>
</p>

<?php if (
    !empty($operation['detail'])
): ?>

<p>
<?= h(
    (string)$operation['detail']
) ?>
</p>

<?php endif; ?>

</div>

<?php else: ?>

<div class="test-result failure">

<div class="test-result-title">
✕ <?= h(
    (string)(
        $operation['title']
        ?? '処理失敗'
    )
) ?>
</div>

<p>
<?= h(
    (string)(
        $operation['message']
        ?? '処理に失敗しました。'
    )
) ?>
</p>

<?php if (
    !empty($operation['detail'])
): ?>

<p>
<?= h(
    (string)$operation['detail']
) ?>
</p>

<?php endif; ?>

</div>

<?php endif; ?>

<?php if (
    !empty($operation['steps'])
    && is_array(
        $operation['steps']
    )
): ?>

<div style="margin-top:18px">

<strong>
処理経過
</strong>

<?php foreach (
    $operation['steps']
    as $step
): ?>

<div class="test-step">

<span>●</span>

<span>
<?= h(
    (string)$step
) ?>
</span>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>

</div>

<?php endif; ?>


<div class="card">

<div class="card-header">

<h2>
接続設定
</h2>

</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid grid-2">

<label>

<span>
サブドメイン
</span>

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

<div class="help">
https://xxxx.cybozu.com /
xxxx.cybozu.com / xxxx に対応。
</div>

</label>

<label>

<span>
顧客管理アプリID
</span>

<input type="number"
       name="app_id"
       min="1"
       step="1"
       required
       value="<?= h(
           (string)(
               $k['app_id']
               ?? ''
           )
       ) ?>">

</label>

<label>

<span>
ログイン名
</span>

<input type="text"
       name="username"
       required
       autocomplete="username"
       value="<?= h(
           (string)(
               $k['username']
               ?? ''
           )
       ) ?>">

</label>

<label>

<span>
パスワード
</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更する場合のみ入力">

<div class="help">
空欄の場合、保存済みパスワードを維持します。
</div>

</label>

<label>

<span>
Proxy
</span>

<input type="text"
       name="proxy"
       placeholder="proxy.example.local:8080"
       value="<?= h(
           (string)(
               $k['proxy']
               ?? ''
           )
       ) ?>">

</label>

<label style="padding-top:30px">

<input type="checkbox"
       name="verify_ssl"
       value="1"
<?= !empty(
    $k['verify_ssl']
)
    ? 'checked'
    : '' ?>>

SSL証明書を検証する

<div class="help">
環境で正しい証明書を利用できる場合は有効化してください。
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

<span>
接続テスト用サブドメイン
</span>

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

<span>
接続テスト用アプリID
</span>

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

<span>
接続テスト用ログイン名
</span>

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

<span>
接続テスト用パスワード
</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みパスワードを使用する場合は空欄">

</label>

<label>

<span>
Proxy
</span>

<input type="text"
       name="proxy"
       value="<?= h(
           (string)(
               $k['proxy']
               ?? ''
           )
       ) ?>">

</label>

<label style="padding-top:30px">

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

<h2>
kintoneデータ操作
</h2>

</div>

<div class="card-body">

<div class="button-row">

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

</div>

</div>

</div>


<div class="card">

<div class="card-header">

<h2>
顧客情報マッピング
</h2>

</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="grid grid-2">

<?php
$mapFields = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<?php foreach (
    $mapFields
    as $mapKey => $mapLabel
): ?>

<label>

<span>
<?= h($mapLabel) ?>
</span>

<select name="<?= h(
    'mapping_' . $mapKey
) ?>">

<option value="">
未設定
</option>

<?php foreach (
    $fields['properties']
    ?? []
    as $code => $field
): ?>

<?php
if (!is_array($field)) {
    continue;
}
?>

<option value="<?= h(
    (string)$code
) ?>"
<?= (
    (string)(
        $k['mapping'][$mapKey]
        ?? ''
    )
    === (string)$code
)
    ? 'selected'
    : '' ?>>

<?= h(
    (string)(
        $field['label']
        ?? $code
    )
) ?>

（<?= h(
    (string)$code
) ?>）

</option>

<?php endforeach; ?>

</select>

</label>

<?php endforeach; ?>

</div>

<div style="margin-top:20px">

<label>

<span>
住所
</span>

<?php
$selectedAddress =
    $k['mapping']['address']
    ?? [];

if (!is_array(
    $selectedAddress
)) {
    $selectedAddress = [];
}
?>

<?php foreach (
    $fields['properties']
    ?? []
    as $code => $field
): ?>

<?php
if (!is_array($field)) {
    continue;
}
?>

<label style="
margin:7px 0;
font-weight:400;
">

<input type="checkbox"
       name="mapping_address[]"
       value="<?= h(
           (string)$code
       ) ?>"
<?= in_array(
    (string)$code,
    $selectedAddress,
    true
)
    ? 'checked'
    : '' ?>>

<?= h(
    (string)(
        $field['label']
        ?? $code
    )
) ?>

（<?= h(
    (string)$code
) ?>）

</label>

<?php endforeach; ?>

</label>

</div>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
    マッピングを保存
</button>

</div>

</form>

</div>

</div>


<?php if (
    !empty(
        $fields['properties']
    )
): ?>

<div class="card">

<div class="card-header">

<h2>
kintone項目一覧
</h2>

</div>

<div class="card-body">

<div class="table-scroll">

<table>

<thead>

<tr>
<th>フィールドコード</th>
<th>項目名</th>
<th>種類</th>
</tr>

</thead>

<tbody>

<?php foreach (
    $fields['properties']
    as $code => $field
): ?>

<?php
if (!is_array($field)) {
    continue;
}
?>

<tr>

<td>
<code>
<?= h(
    (string)$code
) ?>
</code>
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

</div>

</div>

<?php endif; ?>


<div class="card">

<div class="card-header">

<h2>
同期済み顧客
</h2>

</div>

<div class="card-body">

<p>
現在の同期件数：
<strong>
<?= count($customers) ?>件
</strong>
</p>

<?php if (
    !empty($k['last_sync'])
): ?>

<p class="help">

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

</p>

<?php endif; ?>

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
        'アンケートプレビュー',
        'preview'
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

<p class="page-description">
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

<h2>
<?= h(
    (string)(
        $group['title']
        ?? ''
    )
) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<div class="question-head">

<strong>
<?= h(
    (string)(
        $question['number']
        ?? ''
    )
) ?>
</strong>

<span>
<?= h(
    (string)(
        $question['text']
        ?? ''
    )
) ?>
</span>

</div>

<div class="question-body">

<?php if (
    ($question['type'] ?? '')
    === 'text'
): ?>

<textarea disabled></textarea>

<?php else: ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label class="answer-option">

<input
    type="<?= (
        ($question['type'] ?? '')
        === 'multiple'
            ? 'checkbox'
            : 'radio'
    ) ?>"
    disabled>

<?= h(
    (string)(
        $option['label']
        ?? ''
    )
) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

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
        '回答集計・分析',
        'analytics'
    );

    render_flash();

    if ($survey === null) {
        ?>
<div class="alert alert-error">
集計対象のアンケートが見つかりません。
</div>
<?php
        render_footer();
        return;
    }

    $answers =
        array_values(
            array_filter(
                $data['answers'],
                static fn($answer) =>
                    ($answer['surveyId'] ?? '')
                    === ($survey['id'] ?? '')
            )
        );

    $answerCount =
        count($answers);

    $customerCount =
        count(
            $data['customers']
        );

    $unanswered =
        max(
            0,
            $customerCount
            - $answerCount
        );

    $rate =
        $customerCount > 0
            ? round(
                (
                    $answerCount
                    / $customerCount
                ) * 100,
                1
            )
            : 0;

    ?>

<h1 class="page-title">
回答集計・分析
</h1>

<p class="page-description">
対象：
<strong>
<?= h(
    (string)$survey['title']
) ?>
</strong>
</p>

<div class="stat-grid">

<div class="stat">
<div class="stat-label">
送信対象者数
</div>
<div class="stat-value">
<?= $customerCount ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
回答数
</div>
<div class="stat-value">
<?= $answerCount ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
未回答数
</div>
<div class="stat-value">
<?= $unanswered ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
回答率
</div>
<div class="stat-value">
<?= $rate ?>%
</div>
</div>

</div>


<div class="card"
     style="margin-top:20px">

<div class="card-header">
<h2>
設問別集計
</h2>
</div>

<div class="card-body">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$qid =
    (string)$question['id'];

$counts = [];

foreach (
    $answers
    as $answer
) {
    $value =
        $answer['answers'][$qid]
        ?? '';

    if (is_array($value)) {
        foreach (
            $value
            as $item
        ) {
            $item =
                trim(
                    (string)$item
                );

            if ($item === '') {
                continue;
            }

            $counts[$item] =
                ($counts[$item] ?? 0)
                + 1;
        }
    } else {
        $value =
            trim(
                (string)$value
            );

        if ($value !== '') {
            $counts[$value] =
                ($counts[$value] ?? 0)
                + 1;
        }
    }
}
?>

<div style="
padding:16px 0;
border-bottom:1px solid var(--border);
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

<?php if (
    !$counts
): ?>

<p class="help">
回答なし
</p>

<?php else: ?>

<ul>

<?php foreach (
    $counts
    as $label => $count
): ?>

<li>
<?= h($label) ?>：
<strong>
<?= $count ?>件
</strong>
</li>

<?php endforeach; ?>

</ul>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>

</div>

<?php
    render_footer();
}


/* ============================================================
 * 送信画面
 * ============================================================ */

function render_send(
    ?array $survey,
    array $data
): void {
    render_header(
        '顧客選択・メール送信',
        'send'
    );

    render_flash();

    if ($survey === null) {
        ?>
<div class="alert alert-error">
送信対象のアンケートが見つかりません。
</div>
<?php
        render_footer();
        return;
    }

    ?>

<h1 class="page-title">
顧客選択・メール送信
</h1>

<p class="page-description">
対象アンケート：
<strong>
<?= h(
    (string)$survey['title']
) ?>
</strong>
</p>

<div class="card">

<div class="card-header">
<h2>
顧客選択
</h2>
</div>

<div class="card-body">

<?php if (
    empty(
        $data['customers']
    )
): ?>

<div class="alert alert-warning">
顧客データがありません。
kintone設定画面から顧客情報を同期してください。
</div>

<?php else: ?>

<div class="table-scroll">

<table>

<thead>

<tr>
<th>選択</th>
<th>組織名</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
</tr>

</thead>

<tbody>

<?php foreach (
    $data['customers']
    as $customer
): ?>

<tr>

<td>
<input type="checkbox"
       disabled>
</td>

<td>
<?= h(
    (string)(
        $customer['organization']
        ?? ''
    )
) ?>
</td>

<td>
<?= h(
    (string)(
        $customer['name']
        ?? ''
    )
) ?>
</td>

<td>
<?= h(
    (string)(
        $customer['email']
        ?? ''
    )
) ?>
</td>

<td>
<?= h(
    (string)(
        $customer['department']
        ?? ''
    )
) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>

</div>


<div class="card">

<div class="card-header">
<h2>
メール作成
</h2>
</div>

<div class="card-body">

<label>

<span>
件名
</span>

<input type="text"
       value="<?= h(
           (string)$survey['title']
       ) ?>">

</label>

<label style="margin-top:16px">

<span>
本文
</span>

<textarea>アンケートへのご協力をお願いいたします。

{顧客名} 様

以下のURLからアンケートへご回答ください。

{アンケートURL}</textarea>

</label>

<div class="alert alert-info"
     style="margin-top:16px">

使用可能な変数：

<code>{顧客名}</code>

<code>{アンケートURL}</code>

</div>

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
        'アンケート回答',
        'answer'
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

<p class="page-description">
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

<h2>
<?= h(
    (string)(
        $group['title']
        ?? ''
    )
) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$qid =
    (string)$question['id'];

$type =
    $question['type']
    ?? 'single';
?>

<div class="question-card">

<div class="question-head">

<strong>
<?= h(
    (string)(
        $question['number']
        ?? ''
    )
) ?>
</strong>

<span>
<?= h(
    (string)(
        $question['text']
        ?? ''
    )
) ?>
</span>

<?php if (
    !empty(
        $question['required']
    )
): ?>

<span class="badge badge-warning">
必須
</span>

<?php endif; ?>

</div>

<div class="question-body">

<?php if (
    $type === 'text'
): ?>

<textarea
    name="answer[<?= h($qid) ?>]"
<?= !empty(
    $question['required']
)
    ? 'required'
    : '' ?>></textarea>

<?php else: ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label class="answer-option">

<input
    type="<?= $type === 'multiple'
        ? 'checkbox'
        : 'radio' ?>"
    name="answer[<?= h($qid) ?>]<?= $type === 'multiple'
        ? '[]'
        : '' ?>"
    value="<?= h(
        (string)(
            $option['label']
            ?? ''
        )
    ) ?>"
<?= (
    $type === 'single'
    && !empty(
        $question['required']
    )
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

<?php endif; ?>

</div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
    回答を確認
</button>

</div>

</form>

</div>

</div>

<?php
    render_footer();
}


/* ============================================================
 * 確認
 * ============================================================ */

function render_confirm(
    array $survey
): void {
    render_header(
        '回答確認',
        'confirm'
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
以下の内容で送信します。
</p>

<?php foreach (
    $survey['groups']
    as $group
): ?>

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
            '、',
            array_map(
                'strval',
                $value
            )
        );
}
?>

<div class="question-card">

<div class="question-head">

<strong>
<?= h(
    (string)(
        $question['number']
        ?? ''
    )
) ?>
</strong>

<span>
<?= h(
    (string)(
        $question['text']
        ?? ''
    )
) ?>
</span>

</div>

<div class="question-body">

<?= nl2br(
    h(
        (string)$value
    )
) ?>

</div>

</div>

<?php endforeach; ?>

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
        '回答完了',
        'complete'
    );

    ?>

<div class="card">

<div class="card-body"
     style="
     text-align:center;
     padding:60px 20px;
     ">

<div style="
font-size:48px;
color:var(--success);
">
✓
</div>

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
 * メール設定
 * ============================================================ */

function render_mail(
    array $settings
): void {
    render_header(
        'メールサーバ設定',
        'mail'
    );

    render_flash();

    $mail =
        $settings['mail']
        ?? default_settings()['mail'];

    ?>

<h1 class="page-title">
メールサーバ設定
</h1>

<p class="page-description">
SMTPサーバを設定します。
</p>

<div class="card">

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_mail">

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
<span>暗号化</span>

<select name="smtp_encryption">

<option value="tls">
TLS
</option>

<option value="ssl">
SSL
</option>

<option value="none">
なし
</option>

</select>

</label>

<label>
<span>SMTP認証</span>

<select name="smtp_auth">

<option value="1">
使用する
</option>

<option value="0">
使用しない
</option>

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

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
    設定保存
</button>

</div>

</form>

</div>

</div>

<?php
    render_footer();
}


/* ============================================================
 * POST処理
 *
 * 重要:
 *
 * kintone:
 *   POST → 処理 → HTTP 200 HTML
 *
 * 通常管理画面:
 *   POST → 保存 → 303 → GET
 *
 * この2つを意図的に分離する。
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

        redirect_screen('list');
    }

    /*
     * kintone操作は、
     * 例外が発生してもredirectしない。
     */
    $isKintone =
        in_array(
            $action,
            [
                'save_kintone',
                'test_kintone',
                'refresh_kintone_fields',
                'sync_kintone',
                'save_kintone_mapping',
            ],
            true
        );

    if ($isKintone) {
        $operation = [
            'success' => false,
            'title' => '処理結果',
            'message' => '',
            'detail' => '',
            'steps' => [],
        ];

        try {
            switch ($action) {

                case 'save_kintone':

                    save_kintone_settings(
                        $settings
                    );

                    $operation = [
                        'success' => true,
                        'title' =>
                            '設定保存成功',

                        'message' =>
                            'kintone設定を保存しました。',

                        'detail' =>
                            '設定値をサーバー側へ保存しました。'
                            . 'この操作ではkintoneへの接続は行っていません。',

                        'steps' => [
                            '1. 入力値を検証しました。',
                            '2. kintone接続先を検証しました。',
                            '3. 認証情報をサーバー側へ保存しました。',
                            '4. 設定保存が完了しました。',
                            '5. このPOSTリクエスト内で結果画面を生成します。',
                        ],
                    ];

                    break;


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
                        post_string(
                            'proxy'
                        );

                    $temporary['verify_ssl'] =
                        post_bool(
                            'verify_ssl'
                        );

                    $test =
                        kintone_test(
                            $temporary
                        );

                    $settings['kintone'][
                        'last_test'
                    ] = $test;

                    /*
                     * テスト結果のみ保存。
                     *
                     * ここでtemporaryのパスワードを
                     * 保存設定へ混入させない。
                     */
                    save_json_file(
                        SETTINGS_FILE,
                        $settings
                    );

                    $operation =
                        array_merge(
                            $test,
                            [
                                'title' =>
                                    'kintone接続テスト',
                            ]
                        );

                    break;


                case 'refresh_kintone_fields':

                    $fields =
                        kintone_get_fields(
                            $settings['kintone']
                        );

                    $settings['kintone'][
                        'fields'
                    ] =
                        $fields;

                    save_json_file(
                        SETTINGS_FILE,
                        $settings
                    );

                    $operation = [
                        'success' => true,

                        'title' =>
                            '項目一覧取得成功',

                        'message' =>
                            'kintone項目一覧を取得しました。',

                        'detail' =>
                            count(
                                $fields['properties']
                                ?? []
                            )
                            . '項目を取得しました。',

                        'steps' => [
                            '1. 保存済みkintone設定を読み込みました。',
                            '2. kintone REST APIへ接続しました。',
                            '3. アプリのフォーム項目を取得しました。',
                            '4. 項目一覧をサーバー側へ保存しました。',
                            '5. このPOSTリクエスト内で結果画面を生成します。',
                        ],
                    ];

                    break;


                case 'sync_kintone':

                    $count =
                        kintone_sync_customers(
                            $settings['kintone'],
                            $data,
                            $settings
                        );

                    $operation = [
                        'success' => true,

                        'title' =>
                            '顧客同期成功',

                        'message' =>
                            'kintoneから顧客情報を同期しました。',

                        'detail' =>
                            $count
                            . '件を同期しました。',

                        'steps' => [
                            '1. 保存済みkintone設定を読み込みました。',
                            '2. kintone REST APIへ接続しました。',
                            '3. 顧客レコードを取得しました。',
                            '4. 顧客データをJSONへ保存しました。',
                            '5. 同期日時を保存しました。',
                            '6. このPOSTリクエスト内で結果画面を生成します。',
                        ],
                    ];

                    break;


                case 'save_kintone_mapping':

                    save_kintone_mapping(
                        $settings
                    );

                    $operation = [
                        'success' => true,

                        'title' =>
                            'マッピング保存成功',

                        'message' =>
                            'kintone項目マッピングを保存しました。',

                        'detail' =>
                            'マッピング設定のみを保存しました。'
                            . 'kintone API通信は行っていません。',

                        'steps' => [
                            '1. マッピング値を受信しました。',
                            '2. 配列形式を検証しました。',
                            '3. settings.jsonへ保存しました。',
                            '4. このPOSTリクエスト内で結果画面を生成します。',
                        ],
                    ];

                    break;
            }

        } catch (Throwable $e) {

            /*
             * kintone操作ではここでredirectしない。
             *
             * これが今回の再発防止の核心。
             */
            $operation = [
                'success' => false,

                'title' =>
                    'kintone処理失敗',

                'message' =>
                    $e instanceof InvalidArgumentException
                        ? $e->getMessage()
                        : 'kintone処理に失敗しました。',

                'detail' =>
                    $e instanceof InvalidArgumentException
                        ? ''
                        : '入力値、kintone設定、Proxy、HTTPS通信、'
                            . 'アプリ権限を確認してください。',

                'steps' => [
                    'POSTリクエストを受信しました。',
                    'サーバー側で処理を実行しました。',
                    '処理中にエラーを検出しました。',
                    '303リダイレクトは行わず、この画面へ結果を返します。',
                ],
            ];
        }

        /*
         * HTTP 200を明示。
         *
         * Locationヘッダーを送らない。
         */
        http_response_code(200);

        render_kintone(
            $settings,
            $data,
            $operation
        );

        exit;
    }


    /*
     * 以下は通常のPOST。
     * 成功時はPRGを使用する。
     */

    try {

        switch ($action) {

            case 'save_survey':

                $id =
                    save_survey(
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

                change_status(
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

                duplicate_survey(
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

                delete_survey(
                    $data
                );

                flash(
                    'success',
                    'アンケートを削除しました。'
                );

                redirect_screen(
                    'list'
                );

            case 'add_group':

                add_group(
                    $data
                );

                flash(
                    'success',
                    'グループを追加しました。'
                );

                redirect_screen(
                    'edit',
                    [
                        'id' =>
                            post_string('id'),
                    ]
                );

            case 'add_question':

                add_question(
                    $data
                );

                flash(
                    'success',
                    '質問を追加しました。'
                );

                redirect_screen(
                    'edit',
                    [
                        'id' =>
                            post_string('id'),
                    ]
                );

            case 'delete_group':

                delete_group(
                    $data
                );

                flash(
                    'success',
                    'グループを削除しました。'
                );

                redirect_screen(
                    'edit',
                    [
                        'id' =>
                            post_string('id'),
                    ]
                );

            case 'delete_question':

                delete_question(
                    $data
                );

                flash(
                    'success',
                    '質問を削除しました。'
                );

                redirect_screen(
                    'edit',
                    [
                        'id' =>
                            post_string('id'),
                    ]
                );

            case 'save_questions':

                save_questions(
                    $data
                );

                flash(
                    'success',
                    '質問設定を保存しました。'
                );

                redirect_screen(
                    'edit',
                    [
                        'id' =>
                            post_string('id'),
                    ]
                );

            case 'answer':

                save_answer(
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

                finalize_answer(
                    $data
                );

                redirect_screen(
                    'complete'
                );

            case 'save_mail':

                save_mail_settings(
                    $settings
                );

                flash(
                    'success',
                    'メール設定を保存しました。'
                );

                redirect_screen(
                    'mail'
                );

            default:

                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }

    } catch (Throwable $e) {

        $message =
            $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : '処理に失敗しました。';

        flash(
            'error',
            $message
        );

        switch ($action) {

            case 'save_survey':

                redirect_screen(
                    'edit',
                    [
                        'id' =>
                            post_string('id'),
                    ]
                );

            case 'add_group':
            case 'add_question':
            case 'delete_group':
            case 'delete_question':
            case 'save_questions':

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
 * SMTP設定保存
 * ============================================================ */

function save_mail_settings(
    array &$settings
): void {
    $old =
        $settings['mail']
        ?? default_settings()['mail'];

    $host =
        post_string('smtp_host');

    $port =
        post_string(
            'smtp_port',
            '587'
        );

    $encryption =
        post_string(
            'smtp_encryption',
            'tls'
        );

    $auth =
        post_bool('smtp_auth');

    $username =
        post_string(
            'smtp_username'
        );

    $password =
        post_string(
            'smtp_password'
        );

    $fromEmail =
        post_string(
            'from_email'
        );

    $fromName =
        post_string(
            'from_name'
        );

    $replyTo =
        post_string(
            'reply_to'
        );

    if (
        $port === ''
        || !ctype_digit($port)
        || (int)$port < 1
        || (int)$port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (
        !in_array(
            $encryption,
            [
                'tls',
                'ssl',
                'none',
            ],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'SMTP暗号化方式が不正です。'
        );
    }

    if ($password === '') {
        $password =
            (string)(
                $old['password']
                ?? ''
            );
    }

    $settings['mail'] = [
        'host' =>
            $host,

        'port' =>
            $port,

        'encryption' =>
            $encryption,

        'auth' =>
            $auth,

        'username' =>
            $username,

        'password' =>
            $password,

        'from_email' =>
            $fromEmail,

        'from_name' =>
            $fromName,

        'reply_to' =>
            $replyTo,

        'last_test' =>
            $old['last_test']
            ?? null,
    ];

    save_json_file(
        SETTINGS_FILE,
        $settings
    );
}


/* ============================================================
 * メイン
 * ============================================================ */

try {

    app_init();

} catch (Throwable $e) {

    /*
     * セッション開始前でも表示できる
     * 最低限の致命エラー画面。
     */
    http_response_code(500);

    header(
        'Content-Type: text/html; charset=UTF-8'
    );

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<title>アプリケーションエラー</title>';
    echo '<style>';
    echo 'body{font-family:sans-serif;padding:40px;background:#f8fafc}';
    echo '.error{background:#fff;border:1px solid #fecaca;padding:20px;border-radius:10px;color:#991b1b}';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="error">';
    echo '<h1>アプリケーションエラー</h1>';
    echo '<p>アプリケーションを初期化できません。</p>';
    echo '</div>';
    echo '</body>';
    echo '</html>';

    exit;
}

$data =
    load_data();

$settings =
    load_settings();

refresh_all_statuses(
    $data
);


/* ============================================================
 * POST
 * ============================================================ */

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'POST'
) {
    handle_post(
        $data,
        $settings
    );

    exit;
}


/* ============================================================
 * GET
 * ============================================================ */

$screen =
    get_string(
        'screen',
        'list'
    );


/* ============================================================
 * 回答者
 * ============================================================ */

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
            'アンケート',
            'answer'
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
            'アンケート',
            'answer'
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


/* ============================================================
 * 完了
 * ============================================================ */

if (
    $screen === 'complete'
) {
    render_complete();
    exit;
}


/* ============================================================
 * 管理画面
 * ============================================================ */

switch ($screen) {

    case 'edit':

        $id =
            get_string('id');

        $survey =
            $id === ''
                ? null
                : find_survey(
                    $data,
                    $id
                );

        if (
            $id !== ''
            && $survey === null
        ) {
            flash(
                'error',
                '指定されたアンケートが見つかりません。'
            );

            redirect_screen(
                'list'
            );
        }

        render_edit(
            $data,
            $survey
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


    case 'send':

        render_send(
            find_survey(
                $data,
                get_string('id')
            ),
            $data
        );

        break;


    case 'kintone':

        render_kintone(
            $settings,
            $data
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
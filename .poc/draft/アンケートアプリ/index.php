<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 *
 * 設計方針
 * - index.php単一エントリーポイント
 * - DB不使用、サーバー側JSON永続化
 * - POST処理と画面描画を分離
 * - 外部通信関数からheader("Location:")を実行しない
 * - kintone / SMTPの302・303を成功扱いしない
 * - 外部通信のレスポンスなしを成功扱いしない
 * - 回答完了後は管理者一覧へ戻さない
 * - 未定義のエラー描画関数に依存しない
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const HTTP_CONNECT_TIMEOUT = 10;
const HTTP_READ_TIMEOUT = 30;

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
    $script = (string)(
        $_SERVER['SCRIPT_NAME'] ?? 'index.php'
    );

    if (!$params) {
        return $script;
    }

    return $script . '?' .
        http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}

function public_answer_url(string $surveyId): string
{
    $https =
        (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
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
        save_json(
            DATA_FILE,
            default_data()
        );
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json(
            SETTINGS_FILE,
            default_settings()
        );
    }

    if (
        session_status()
        !== PHP_SESSION_ACTIVE
    ) {
        $https =
            (!empty($_SERVER['HTTPS'])
                && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80)
                === 443;

        session_name('survey_app_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => cookie_path(),
            'secure' => $https,
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
        $file .
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

        if (
            fwrite($fp, $json)
            === false
        ) {
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

function save_settings(
    array $settings
): void {
    save_json(
        SETTINGS_FILE,
        $settings
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
            $group['questions']
                as &$question
        ) {
            if (
                ($survey['numbering']
                    ?? 'global')
                === 'group'
            ) {
                $question['number'] =
                    'Q' .
                    $groupNo .
                    '-' .
                    $questionNo;
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
        default => 'gray',
    };
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
        return substr(
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

/*
 * kintone通信専用関数。
 *
 * 絶対にheader(Location)を実行しない。
 * 302/303はfollowせず、呼び出し元へ返す。
 */
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
            'method' => strtoupper($method),
            'header' => implode(
                "\r\n",
                $headers
            ),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => HTTP_READ_TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
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
            'tcp://' .
            $proxyHost .
            ':' .
            (int)$proxyPort;

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
                ? 'kintoneからレスポンスを取得できませんでした。'
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

    /*
     * 302 / 303は成功ではない。
     */
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

    return [
        'status' => $status,
        'body' =>
            is_array($json)
                ? $json
                : [],
        'raw' => $response,
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
        . '&totalCount=true'
    );
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

    return $errors;
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
            implode("\n", $errors)
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
            : $host;

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
        && stripos(
            $ehlo,
            'STARTTLS'
        ) !== false
    ) {
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
                'SMTP STARTTLSを確立できません。'
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

        if (
            $username === ''
            || $password === ''
        ) {
            throw new RuntimeException(
                'SMTP認証情報が設定されていません。'
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

function smtp_test(
    array $config
): void {
    $socket =
        smtp_open($config);

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
        'errors' => $errors,
        'title' => $title,
        'description' => $description,
        'startAt' => $startAt,
        'endAt' => $endAt,
        'numbering' => $numbering,
    ];
}

/* =========================================================
 * POST処理
 *
 * 重要:
 * ここからheader("Location")を絶対に実行しない。
 *
 * 戻り値だけで次に表示するscreen/idを指定する。
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

            /* -----------------------------
             * アンケート保存
             * ----------------------------- */

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

                if ($index < 0) {
                    $surveyId =
                        uuid('survey');

                    $survey = [
                        'id' => $surveyId,
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
                            date('Y-m-d H:i:s'),
                        'updatedAt' =>
                            date('Y-m-d H:i:s'),
                        'groups' => [
                            [
                                'id' =>
                                    uuid('group'),
                                'title' =>
                                    '基本アンケート',
                                'questions' => [],
                            ],
                        ],
                    ];

                    $data['surveys'][] =
                        $survey;
                } else {
                    /*
                     * 編集時はstatusを絶対に
                     * POST内容から勝手に変更しない。
                     */
                    $survey =
                        $data['surveys'][$index];

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

                    $survey['updatedAt'] =
                        date('Y-m-d H:i:s');

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


            /* -----------------------------
             * 状態変更
             * ----------------------------- */

            case 'change_status':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $newStatus =
                    post_string(
                        'status'
                    );

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                if ($index < 0) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $current =
                    (string)(
                        $data['surveys'][$index]
                            ['status']
                        ?? 'draft'
                    );

                $allowed = [
                    'draft' => ['published'],
                    'published' => ['stopped'],
                    'stopped' => ['published'],
                    'ended' => [],
                ];

                if (
                    !in_array(
                        $newStatus,
                        $allowed[$current] ?? [],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        '許可されていない状態変更です。'
                    );
                }

                $data['surveys'][$index]
                    ['status'] =
                    $newStatus;

                $data['surveys'][$index]
                    ['updatedAt'] =
                    date('Y-m-d H:i:s');

                save_data($data);

                flash(
                    'success',
                    '状態を変更しました。'
                );

                return [
                    'screen' => 'list',
                ];


            /* -----------------------------
             * グループ追加
             * ----------------------------- */

            case 'add_group':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                if ($index < 0) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $data['surveys'][$index]
                    ['groups'][] = [
                        'id' =>
                            uuid('group'),
                        'title' =>
                            '新しいグループ',
                        'questions' => [],
                    ];

                recalc_numbers(
                    $data['surveys'][$index]
                );

                $data['surveys'][$index]
                    ['updatedAt'] =
                    date('Y-m-d H:i:s');

                save_data($data);

                return [
                    'screen' => 'edit',
                    'id' => $surveyId,
                ];


            /* -----------------------------
             * 質問追加
             * ----------------------------- */

            case 'add_question':

                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $groupId =
                    post_string(
                        'group_id'
                    );

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                if ($index < 0) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                foreach (
                    $data['surveys'][$index]
                        ['groups']
                    as &$group
                ) {
                    if (
                        (string)(
                            $group['id'] ?? ''
                        )
                        !== $groupId
                    ) {
                        continue;
                    }

                    $group['questions'][] = [
                        'id' =>
                            uuid('question'),
                        'number' => '',
                        'text' =>
                            '新しい質問',
                        'type' => 'single',
                        'required' => false,
                        'options' => [
                            [
                                'id' =>
                                    uuid('option'),
                                'label' =>
                                    '選択肢1',
                                'nextQuestionId' =>
                                    '',
                            ],
                            [
                                'id' =>
                                    uuid('option'),
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

                recalc_numbers(
                    $data['surveys'][$index]
                );

                $data['surveys'][$index]
                    ['updatedAt'] =
                    date('Y-m-d H:i:s');

                save_data($data);

                return [
                    'screen' => 'edit',
                    'id' => $surveyId,
                ];


            /* -----------------------------
             * アンケート複製
             * ----------------------------- */

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

                if ($survey === null) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $survey['id'] =
                    uuid('survey');

                $survey['title'] .=
                    '（複製）';

                $survey['status'] =
                    'draft';

                $survey['createdAt'] =
                    date('Y-m-d H:i:s');

                $survey['updatedAt'] =
                    date('Y-m-d H:i:s');

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


            /* -----------------------------
             * アンケート削除
             * ----------------------------- */

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

                if ($index < 0) {
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


            /* -----------------------------
             * kintone接続テスト
             * ----------------------------- */

            case 'test_kintone':

                $current =
                    $settings['kintone'];

                $password =
                    post_string(
                        'password'
                    );

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
                            $config
                        );

                    $settings['kintone']
                        ['last_test'] =
                        date('Y-m-d H:i:s');

                    save_settings(
                        $settings
                    );

                    flash(
                        'success',
                        'kintone接続成功。HTTP '
                        . $result['status']
                    );
                } catch (Throwable $e) {
                    /*
                     * 302/303を含め、
                     * 必ず画面へ結果を返す。
                     */
                    flash(
                        'error',
                        'kintone接続失敗：'
                        . $e->getMessage()
                    );
                }

                return [
                    'screen' => 'kintone',
                ];


            /* -----------------------------
             * kintone設定保存
             * ----------------------------- */

            case 'save_kintone':

                $current =
                    $settings['kintone'];

                $password =
                    post_string(
                        'password'
                    );

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
                        $config
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


            /* -----------------------------
             * SMTP設定保存
             * ----------------------------- */

            case 'save_mail':

                $current =
                    $settings['mail'];

                $password =
                    post_string(
                        'password'
                    );

                if ($password === '') {
                    $password =
                        (string)(
                            $current['password']
                                ?? ''
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


            /* -----------------------------
             * SMTP接続テスト
             * ----------------------------- */

            case 'test_mail':

                try {
                    smtp_test(
                        $settings['mail']
                    );

                    $settings['mail']
                        ['last_test'] =
                        date('Y-m-d H:i:s');

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


            /* -----------------------------
             * 回答途中
             * ----------------------------- */

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

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $answers =
                    $_POST['answer'] ?? [];

                if (!is_array($answers)) {
                    $answers = [];
                }

                foreach (
                    $survey['groups']
                    as $group
                ) {
                    foreach (
                        $group['questions']
                        as $question
                    ) {
                        if (
                            !empty(
                                $question['required']
                            )
                            && (
                                !isset(
                                    $answers[
                                        $question['id']
                                    ]
                                )
                                || (
                                    is_string(
                                        $answers[
                                            $question['id']
                                        ]
                                    )
                                    && trim(
                                        $answers[
                                            $question['id']
                                        ]
                                    ) === ''
                                )
                                || (
                                    is_array(
                                        $answers[
                                            $question['id']
                                        ]
                                    )
                                    && count(
                                        $answers[
                                            $question['id']
                                        ]
                                    ) === 0
                                )
                            )
                        ) {
                            throw new RuntimeException(
                                '必須項目に未回答があります。'
                            );
                        }
                    }
                }

                $_SESSION[
                    'answer_draft'
                ] = $answers;

                return [
                    'screen' => 'confirm',
                    'id' => $surveyId,
                ];


            /* -----------------------------
             * 回答修正
             * ----------------------------- */

            case 'answer_back':

                return [
                    'screen' => 'answer',
                    'id' =>
                        post_string(
                            'survey_id'
                        ),
                ];


            /* -----------------------------
             * 回答送信
             *
             * 重要:
             * 管理者一覧には戻さない。
             * ----------------------------- */

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

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $draft =
                    $_SESSION[
                        'answer_draft'
                    ] ?? [];

                if (!is_array($draft)) {
                    $draft = [];
                }

                $data['answers'][] = [
                    'id' =>
                        uuid('answer'),
                    'survey_id' =>
                        $surveyId,
                    'answers' =>
                        $draft,
                    'createdAt' =>
                        date('Y-m-d H:i:s'),
                ];

                save_data($data);

                unset(
                    $_SESSION[
                        'answer_draft'
                    ]
                );

                /*
                 * 回答者フローの終了先は
                 * complete固定。
                 *
                 * list/edit/send等にはしない。
                 */
                return [
                    'screen' => 'complete',
                    'id' => $surveyId,
                ];
        }

        return null;

    } catch (Throwable $e) {

        /*
         * POST処理で例外が発生しても
         * header(Location)を実行しない。
         *
         * 現在のPOST処理に対応する画面へ戻す。
         */
        $screen =
            post_string('return_screen');

        $id =
            post_string('survey_id');

        if ($screen === '') {
            $screen = 'list';
        }

        /*
         * 回答者処理でエラーになった場合は
         * 管理者画面へ絶対に落とさない。
         */
        if (
            in_array(
                $action,
                [
                    'answer_confirm',
                    'answer_back',
                    'submit_answer',
                ],
                true
            )
        ) {
            $screen =
                $action === 'submit_answer'
                    ? 'confirm'
                    : 'answer';
        }

        flash(
            'error',
            '処理に失敗しました：'
            . $e->getMessage()
        );

        return [
            'screen' => $screen,
            'id' => $id,
        ];
    }
}

/* =========================================================
 * HTML共通
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
 background:#f8fafc;
 color:var(--text);
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
 color:inherit;
}

.container{
 width:min(1400px,calc(100% - 32px));
 margin:auto;
}

.page{
 padding:28px 0 60px;
}

.admin-header{
 background:#0f172a;
 color:#fff;
}

.admin-header-inner{
 width:min(1400px,calc(100% - 32px));
 min-height:64px;
 margin:auto;
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:20px;
}

.brand{
 font-weight:700;
 font-size:18px;
}

.nav{
 display:flex;
 flex-wrap:wrap;
 gap:6px;
}

.nav a{
 color:#cbd5e1;
 text-decoration:none;
 padding:9px 12px;
 border-radius:7px;
}

.nav a:hover{
 background:#1e293b;
 color:#fff;
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
 font-size:26px;
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
 overflow:hidden;
}

.card-header{
 padding:16px 20px;
 border-bottom:1px solid var(--border);
}

.card-header h2{
 margin:0;
 font-size:17px;
}

.card-body{
 padding:20px;
}

.grid{
 display:grid;
 gap:18px;
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
 font-weight:600;
 margin-bottom:7px;
}

input,
textarea,
select{
 width:100%;
 border:1px solid #cbd5e1;
 border-radius:8px;
 padding:10px 12px;
 background:#fff;
 color:var(--text);
 font:inherit;
}

input:focus,
textarea:focus,
select:focus{
 outline:2px solid rgba(37,99,235,.18);
 border-color:var(--primary);
}

textarea{
 min-height:130px;
 resize:vertical;
}

input[type=checkbox],
input[type=radio]{
 width:auto;
}

.button-row{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 align-items:center;
}

.btn{
 display:inline-flex;
 align-items:center;
 justify-content:center;
 gap:6px;
 border:1px solid transparent;
 border-radius:8px;
 padding:9px 14px;
 font:inherit;
 cursor:pointer;
 text-decoration:none;
}

.btn:disabled{
 opacity:.5;
 cursor:not-allowed;
}

.btn-primary{
 background:var(--primary);
 color:#fff;
}

.btn-primary:hover{
 background:var(--primary-dark);
}

.btn-secondary{
 background:#fff;
 border-color:#cbd5e1;
 color:var(--text);
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

.alert{
 border-radius:9px;
 padding:12px 14px;
 margin-bottom:18px;
 white-space:pre-line;
}

.alert-success{
 background:#dcfce7;
 color:#166534;
 border:1px solid #bbf7d0;
}

.alert-warning{
 background:#fef3c7;
 color:#92400e;
 border:1px solid #fde68a;
}

.alert-error{
 background:#fee2e2;
 color:#991b1b;
 border:1px solid #fecaca;
}

.alert-info{
 background:#dbeafe;
 color:#1e40af;
 border:1px solid #bfdbfe;
}

.badge{
 display:inline-block;
 padding:4px 8px;
 border-radius:999px;
 font-size:12px;
 font-weight:600;
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

.table-wrap{
 overflow-x:auto;
}

table{
 width:100%;
 border-collapse:collapse;
 min-width:900px;
}

th,td{
 padding:12px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:middle;
}

th{
 background:#f8fafc;
 white-space:nowrap;
}

.sortable{
 cursor:pointer;
}

.group-card,
.question-card{
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
 align-items:center;
 justify-content:space-between;
 gap:10px;
}

.group-body,
.question-body{
 padding:14px;
}

.drag-handle{
 cursor:grab;
 user-select:none;
 color:var(--gray);
 font-size:18px;
}

.dragging{
 opacity:.45;
}

.drop-target{
 outline:2px dashed var(--primary);
 outline-offset:2px;
}

.option-row{
 display:grid;
 grid-template-columns:1fr auto;
 gap:8px;
 margin-bottom:8px;
}

.help{
 color:var(--gray);
 font-size:13px;
}

.answer-shell{
 width:min(900px,calc(100% - 28px));
 margin:28px auto 60px;
}

.answer-shell .page-title{
 margin-bottom:18px;
}

.preview-question{
 border-bottom:1px solid var(--border);
 padding:14px 0;
}

.preview-question:last-child{
 border-bottom:0;
}

.empty{
 padding:35px;
 text-align:center;
 color:var(--gray);
}

.loading{
 display:none;
 position:fixed;
 inset:0;
 background:rgba(255,255,255,.72);
 z-index:9999;
 align-items:center;
 justify-content:center;
}

.loading.show{
 display:flex;
}

.spinner{
 width:38px;
 height:38px;
 border:4px solid #cbd5e1;
 border-top-color:var(--primary);
 border-radius:50%;
 animation:spin .8s linear infinite;
}

@keyframes spin{
 to{
  transform:rotate(360deg);
 }
}

@media(max-width:800px){
 .grid-2,
 .grid-3{
  grid-template-columns:1fr;
 }

 .admin-header-inner{
  align-items:flex-start;
  flex-direction:column;
  padding:14px 0;
 }

 .page-title{
  flex-direction:column;
 }

 .button-row .btn{
  width:100%;
 }

 .answer-shell{
  width:min(100% - 20px,900px);
 }
}
</style>
</head>

<body>

<?php if ($admin): ?>

<header class="admin-header">
<div class="admin-header-inner">

<div class="brand">
<?= h(APP_TITLE) ?>
</div>

<nav class="nav">
<a href="<?= h(app_url([
 'screen'=>'list'
])) ?>">アンケート一覧</a>

<a href="<?= h(app_url([
 'screen'=>'kintone'
])) ?>">kintone設定</a>

<a href="<?= h(app_url([
 'screen'=>'mail'
])) ?>">メール設定</a>
</nav>

</div>
</header>

<?php endif; ?>

<div class="loading"
     id="loading">
 <div class="spinner"></div>
</div>
<?php
}

function render_footer(): void
{
?>
<script>
(function(){
 'use strict';

 document.addEventListener(
  'submit',
  function(e){
   var form=e.target;

   if(!form.matches('form')){
    return;
   }

   var message=form.getAttribute(
    'data-confirm'
   );

   if(message){
    if(!window.confirm(message)){
     e.preventDefault();
     return;
    }
   }

   var loading=document.getElementById(
    'loading'
   );

   if(loading){
    loading.classList.add('show');
   }
  }
 );

 document.querySelectorAll(
  '[data-autosubmit]'
 ).forEach(function(el){
  el.addEventListener(
   'change',
   function(){
    if(el.form){
     el.form.submit();
    }
   }
  );
 });
})();
</script>
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
    $flash =
        consume_flash();

    render_head(
        'アンケート一覧'
    );
?>
<div class="page">
<div class="container">

<?php if ($flash): ?>
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
<?php endif; ?>

<div class="page-title">
<div>
<h1>アンケート一覧</h1>
<p>アンケートの作成・公開・送信・集計を管理します。</p>
</div>

<a class="btn btn-primary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>'new'
   ])) ?>">
新規作成
</a>
</div>

<div class="card">
<div class="card-body">

<form method="get">
<input type="hidden"
       name="screen"
       value="list">

<div class="grid grid-2">

<label>
<span>検索</span>
<input type="search"
       name="q"
       value="<?= h(
           get_string('q')
       ) ?>"
       placeholder="タイトルを検索">
</label>

<label>
<span>ステータス</span>
<select name="status">
<option value="">すべて</option>
<option value="published">公開中</option>
<option value="draft">下書き</option>
<option value="stopped">停止</option>
<option value="ended">終了</option>
</select>
</label>

</div>

<div class="button-row"
     style="margin-top:14px">
<button class="btn btn-primary"
        type="submit">
検索
</button>
</div>

</form>

</div>
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
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>

<tbody>

<?php
$q = get_string('q');
$statusFilter =
    get_string('status');

$surveys =
    $data['surveys'];

foreach (
    $surveys as $survey
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
<strong>
<?= h(
    $survey['title'] ?? ''
) ?>
</strong>
</td>

<td>
<?= h(
    $survey['createdAt'] ?? ''
) ?>
</td>

<td>
<?= h(
    $survey['updatedAt'] ?? ''
) ?>
</td>

<td>
<?= h(
    $survey['startAt'] ?? ''
) ?>
<br>
～
<?= h(
    $survey['endAt'] ?? ''
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
<?= h($answerCount) ?>
</td>

<td>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id']
   ])) ?>">
確認・編集
</a>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id']
   ])) ?>">
プレビュー
</a>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'send',
       'id'=>$survey['id']
   ])) ?>">
送信
</a>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'analytics',
       'id'=>$survey['id']
   ])) ?>">
集計
</a>

<form method="post"
      style="display:inline"
      data-confirm="このアンケートを複製しますか？">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<button class="btn btn-secondary">
複製
</button>
</form>

<form method="post"
      style="display:inline"
      data-confirm="このアンケートを削除しますか？">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<button class="btn btn-danger">
削除
</button>
</form>

<?php if ($status === 'draft'): ?>

<form method="post"
      style="display:inline"
      data-confirm="公開しますか？">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<input type="hidden"
       name="status"
       value="published">
<button class="btn btn-success">
公開
</button>
</form>

<?php elseif ($status === 'published'): ?>

<form method="post"
      style="display:inline"
      data-confirm="停止しますか？">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<input type="hidden"
       name="status"
       value="stopped">
<button class="btn btn-warning">
停止
</button>
</form>

<?php elseif ($status === 'stopped'): ?>

<form method="post"
      style="display:inline"
      data-confirm="再公開しますか？">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<input type="hidden"
       name="status"
       value="published">
<button class="btn btn-success">
再開
</button>
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
    $flash =
        consume_flash();

    render_head(
        'アンケート作成・編集'
    );
?>
<div class="page">
<div class="container">

<?php if ($flash): ?>
<div class="alert alert-<?= h(
    $flash['type'] === 'success'
        ? 'success'
        : 'error'
) ?>">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>

<div class="page-title">

<div>
<h1>アンケート作成・編集</h1>
<p>
<?= h(
    $survey['title'] ?? ''
) ?>
</p>
</div>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>">
キャンセル
</a>

</div>

</div>

<form method="post">

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
<span>アンケートタイトル</span>
<input name="title"
       required
       maxlength="<?= MAX_TITLE ?>"
       value="<?= h(
           $survey['title'] ?? ''
       ) ?>">
</label>

<label>
<span>質問番号の採番方式</span>
<select name="numbering">
<option value="global"
 <?= ($survey['numbering'] ?? '')
     === 'global'
     ? 'selected'
     : '' ?>>
アンケート全体で通番：Q1、Q2...
</option>

<option value="group"
 <?= ($survey['numbering'] ?? '')
     === 'group'
     ? 'selected'
     : '' ?>>
グループ毎：Q1-1、Q1-2...
</option>
</select>
</label>

</div>

<div class="form-group">
<label>
<span>アンケート説明</span>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h(
              $survey['description'] ?? ''
          ) ?></textarea>
</label>
</div>

<div class="grid grid-2">

<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h(
           $survey['startAt'] ?? ''
       ) ?>">
</label>

<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h(
           $survey['endAt'] ?? ''
       ) ?>">
</label>

</div>

<div class="button-row"
     style="margin-top:18px">

<button class="btn btn-primary"
        type="submit">
保存して一覧へ
</button>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id']
   ])) ?>">
プレビュー
</a>

</div>

</div>
</div>

</form>

<div class="card">
<div class="card-header">
<h2>グループ・質問</h2>
</div>

<div class="card-body"
     id="groups">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="group-card"
     draggable="true"
     data-group-id="<?= h(
         $group['id']
     ) ?>">

<div class="group-header">

<div>
<span class="drag-handle">☷</span>
<strong>
<?= h(
    $group['title']
) ?>
</strong>
</div>

</div>

<div class="group-body">

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card"
     draggable="true"
     data-question-id="<?= h(
         $question['id']
     ) ?>">

<div class="question-header">

<div>
<span class="drag-handle">☷</span>
<strong>
<?= h(
    $question['number']
) ?>
</strong>
</div>

<span class="badge badge-gray">
<?= h(
    match (
        $question['type']
        ?? 'single'
    ) {
        'multiple' => '複数選択',
        'text' => '自由記述',
        default => '単一選択',
    }
) ?>
</span>

</div>

<div class="question-body">

<div class="form-group">
<label>
<span>質問文</span>
<input value="<?= h(
    $question['text']
) ?>"
       readonly>
</label>
</div>

<div class="form-group">
<label>
<span>回答形式</span>
<select disabled>
<option
 <?= ($question['type'] ?? '')
     === 'single'
     ? 'selected'
     : '' ?>>
単一選択
</option>

<option
 <?= ($question['type'] ?? '')
     === 'multiple'
     ? 'selected'
     : '' ?>>
複数選択
</option>

<option
 <?= ($question['type'] ?? '')
     === 'text'
     ? 'selected'
     : '' ?>>
自由記述
</option>
</select>
</label>
</div>

<div>
<label>
<input type="checkbox"
       disabled
 <?= !empty(
     $question['required']
 )
     ? 'checked'
     : '' ?>>
 必須
</label>
</div>

</div>
</div>

<?php endforeach; ?>

<form method="post"
      style="margin-top:10px">

<input type="hidden"
       name="action"
       value="add_question">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<input type="hidden"
       name="group_id"
       value="<?= h(
           $group['id']
       ) ?>">

<button class="btn btn-primary">
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
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<button class="btn btn-secondary">
グループを追加
</button>

</form>

</div>
</div>

</div>
</div>

<script>
(function(){

 var groups=
 document.getElementById('groups');

 if(!groups){
  return;
 }

 var dragging=null;

 groups.addEventListener(
  'dragstart',
  function(e){
   var el=e.target.closest(
    '[draggable="true"]'
   );

   if(!el){
    return;
   }

   dragging=el;
   el.classList.add('dragging');

   e.dataTransfer.effectAllowed=
    'move';
  }
 );

 groups.addEventListener(
  'dragend',
  function(){
   if(dragging){
    dragging.classList.remove(
     'dragging'
    );
   }

   dragging=null;
  }
 );

 groups.addEventListener(
  'dragover',
  function(e){
   e.preventDefault();

   var target=e.target.closest(
    '[draggable="true"]'
   );

   if(
    !target
    || target===dragging
   ){
    return;
   }

   if(
    dragging
    && target.parentNode
       ===dragging.parentNode
   ){
    target.classList.add(
     'drop-target'
    );

    var rect=
     target.getBoundingClientRect();

    if(
     e.clientY
     < rect.top + rect.height / 2
    ){
     target.parentNode.insertBefore(
      dragging,
      target
     );
    }else{
     target.parentNode.insertBefore(
      dragging,
      target.nextSibling
     );
    }

    setTimeout(
     function(){
      target.classList.remove(
       'drop-target'
      );
     },
     100
    );
   }
  }
 );

})();
</script>

<?php
render_footer();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(
    array $survey
): void {
    render_head(
        'プレビュー'
    );
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>プレビュー</h1>
<p><?= h(
    $survey['title']
) ?></p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id']
   ])) ?>">
編集へ戻る
</a>
</div>

<div class="card">
<div class="card-body">

<h1><?= h(
    $survey['title']
) ?></h1>

<p>
<?= nl2br(h(
    $survey['description']
)) ?>
</p>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h2>
<?= h(
    $group['title']
) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="preview-question">

<strong>
<?= h(
    $question['number']
) ?>
 <?= h(
     $question['text']
 ) ?>
</strong>

<?php if (
    ($question['type'] ?? '')
    === 'text'
): ?>

<textarea readonly></textarea>

<?php else: ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label style="
display:block;
padding:10px;
margin:7px 0;
border:1px solid var(--border);
border-radius:8px">

<input type="<?= h(
    ($question['type'] ?? '')
        === 'multiple'
        ? 'checkbox'
        : 'radio'
) ?>">

<?= h(
    $option['label']
) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

</div>
</div>

</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * 回答
 * ========================================================= */

function render_answer(
    array $survey
): void {
    render_head(
        'アンケート回答',
        false
    );
?>
<div class="answer-shell">

<div class="page-title">
<div>
<h1><?= h(
    $survey['title']
) ?></h1>
<p><?= nl2br(h(
    $survey['description']
)) ?></p>
</div>
</div>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_confirm">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">

<div class="card-header">
<h2><?= h(
    $group['title']
) ?></h2>
</div>

<div class="card-body">

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="preview-question">

<strong>
<?= h(
    $question['number']
) ?>
 <?= h(
     $question['text']
 ) ?>

<?php if (
    !empty(
        $question['required']
    )
): ?>

<span style="
color:var(--danger)">
*
</span>

<?php endif; ?>

</strong>

<?php
$type =
    $question['type']
    ?? 'single';
?>

<?php if (
    $type === 'text'
): ?>

<textarea
 name="answer[<?= h(
     $question['id']
 ) ?>]"></textarea>

<?php else: ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label style="
display:block;
padding:12px;
margin:8px 0;
border:1px solid var(--border);
border-radius:8px">

<input
 type="<?= $type === 'multiple'
     ? 'checkbox'
     : 'radio' ?>"
 name="answer[<?= h(
     $question['id']
 ) ?>]<?= $type === 'multiple'
     ? '[]'
     : '' ?>"
 value="<?= h(
     $option['label']
 ) ?>">

<?= h(
    $option['label']
) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
回答を確認する
</button>

</div>

</form>

</div>
<?php
render_footer();
}

/* =========================================================
 * 回答確認
 * ========================================================= */

function render_confirm(
    array $survey
): void {
    $draft =
        $_SESSION[
            'answer_draft'
        ] ?? [];

    if (!is_array($draft)) {
        $draft = [];
    }

    render_head(
        '回答確認',
        false
    );
?>
<div class="answer-shell">

<div class="page-title">
<div>
<h1>回答確認</h1>
<p><?= h(
    $survey['title']
) ?></p>
</div>
</div>

<div class="card">
<div class="card-body">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h2><?= h(
    $group['title']
) ?></h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$value =
    $draft[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $display =
        implode(
            ', ',
            array_map(
                'strval',
                $value
            )
        );
} else {
    $display =
        (string)$value;
}
?>

<div class="preview-question">

<strong>
<?= h(
    $question['number']
) ?>
 <?= h(
     $question['text']
 ) ?>
</strong>

<p>
<?= nl2br(h($display)) ?>
</p>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

<div class="button-row">

<form method="post">

<input type="hidden"
       name="action"
       value="answer_back">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<button class="btn btn-secondary">
修正する
</button>

</form>

<form method="post"
      data-confirm="
回答を送信します。よろしいですか？
">

<input type="hidden"
       name="action"
       value="submit_answer">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<button class="btn btn-primary">
回答を送信する
</button>

</form>

</div>

</div>
</div>

</div>
<?php
render_footer();
}

/* =========================================================
 * 回答完了
 *
 * 管理者画面へ戻す導線を持たない。
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
     style="
     text-align:center;
     padding:55px 25px">

<h1>回答ありがとうございました</h1>

<p>
「<?= h(
    $survey['title']
) ?>」
への回答を受け付けました。
</p>

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
    array $config
): void {
    $flash =
        consume_flash();

    render_head(
        'kintone設定'
    );
?>
<div class="page">
<div class="container">

<?php if ($flash): ?>
<div class="alert alert-<?= h(
    $flash['type'] === 'success'
        ? 'success'
        : 'error'
) ?>">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>

<div class="page-title">
<div>
<h1>kintone連携設定</h1>
<p>kintoneから顧客情報を取得します。</p>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid grid-2">

<label>
<span>サブドメイン</span>
<input name="subdomain"
       value="<?= h(
           $config['subdomain']
               ?? ''
       ) ?>"
       placeholder="xxxx.cybozu.com">
</label>

<label>
<span>顧客管理アプリID</span>
<input name="app_id"
       inputmode="numeric"
       value="<?= h(
           $config['app_id']
               ?? ''
       ) ?>">
</label>

<label>
<span>ログイン名</span>
<input name="username"
       value="<?= h(
           $config['username']
               ?? ''
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
<input name="proxy"
       value="<?= h(
           $config['proxy']
               ?? ''
       ) ?>"
       placeholder="host:port">
</label>

<label>
<span>SSL証明書検証</span>
<select name="verify_ssl">
<option value="0"
 <?= empty(
     $config['verify_ssl']
 )
 ? 'selected'
 : '' ?>>
無効
</option>

<option value="1"
 <?= !empty(
     $config['verify_ssl']
 )
 ? 'selected'
 : '' ?>>
有効
</option>
</select>
</label>

</div>

<div class="button-row"
     style="margin-top:18px">

<button class="btn btn-primary">
設定保存
</button>

</div>

</form>

<hr style="
margin:25px 0;
border:0;
border-top:1px solid var(--border)">

<form method="post">

<input type="hidden"
       name="action"
       value="test_kintone">

<input type="hidden"
       name="subdomain"
       value="<?= h(
           $config['subdomain']
               ?? ''
       ) ?>">

<input type="hidden"
       name="app_id"
       value="<?= h(
           $config['app_id']
               ?? ''
       ) ?>">

<input type="hidden"
       name="username"
       value="<?= h(
           $config['username']
               ?? ''
       ) ?>">

<input type="hidden"
       name="password"
       value="">

<input type="hidden"
       name="proxy"
       value="<?= h(
           $config['proxy']
               ?? ''
       ) ?>">

<input type="hidden"
       name="verify_ssl"
       value="<?= !empty(
           $config['verify_ssl']
       ) ? '1' : '0' ?>">

<button class="btn btn-secondary">
接続テスト
</button>

</form>

</div>
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
    array $config
): void {
    $flash =
        consume_flash();

    render_head(
        'メールサーバ設定'
    );
?>
<div class="page">
<div class="container">

<?php if ($flash): ?>
<div class="alert alert-<?= h(
    $flash['type'] === 'success'
        ? 'success'
        : 'error'
) ?>">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>

<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
<p>SMTPサーバとの接続設定です。</p>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid grid-2">

<label>
<span>SMTPサーバ</span>
<input name="server"
       value="<?= h(
           $config['host']
               ?? ''
       ) ?>">
</label>

<label>
<span>SMTPポート</span>
<input name="port"
       type="number"
       min="1"
       max="65535"
       value="<?= h(
           $config['port']
               ?? 587
       ) ?>">
</label>

<label>
<span>暗号化方式</span>
<select name="encryption">
<option value="tls"
 <?= ($config['encryption']
       ?? '') === 'tls'
       ? 'selected'
       : '' ?>>
TLS
</option>

<option value="ssl"
 <?= ($config['encryption']
       ?? '') === 'ssl'
       ? 'selected'
       : '' ?>>
SSL
</option>

<option value="none"
 <?= ($config['encryption']
       ?? '') === 'none'
       ? 'selected'
       : '' ?>>
なし
</option>
</select>
</label>

<label>
<span>SMTP認証</span>
<select name="auth">
<option value="1"
 <?= !empty(
     $config['auth']
 )
 ? 'selected'
 : '' ?>>
使用する
</option>

<option value="0"
 <?= empty(
     $config['auth']
 )
 ? 'selected'
 : '' ?>>
使用しない
</option>
</select>
</label>

<label>
<span>SMTPユーザー名</span>
<input name="username"
       value="<?= h(
           $config['username']
               ?? ''
       ) ?>">
</label>

<label>
<span>SMTPパスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</label>

<label>
<span>送信元メールアドレス</span>
<input type="email"
       name="from_email"
       value="<?= h(
           $config['from_email']
               ?? ''
       ) ?>">
</label>

<label>
<span>送信元名</span>
<input name="from_name"
       value="<?= h(
           $config['from_name']
               ?? ''
       ) ?>">
</label>

<label>
<span>返信先メールアドレス</span>
<input type="email"
       name="reply_to"
       value="<?= h(
           $config['reply_to']
               ?? ''
       ) ?>">
</label>

</div>

<div class="button-row"
     style="margin-top:18px">

<button class="btn btn-primary">
設定保存
</button>

</div>

</form>

<hr style="
margin:25px 0;
border:0;
border-top:1px solid var(--border)">

<form method="post">

<input type="hidden"
       name="action"
       value="test_mail">

<button class="btn btn-secondary">
接続テスト
</button>

</form>

</div>
</div>

</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * メイン
 *
 * 重要:
 *
 * POST
 * ↓
 * 業務処理
 * ↓
 * 結果確定
 * ↓
 * 同一リクエストでrender
 *
 * header(Location)なし。
 * ========================================================= */

try {

    start_app();

    $data =
        load_data();

    $settings =
        load_settings();

    if (
        refresh_statuses($data)
    ) {
        save_data($data);
    }

    $postResult =
        handle_post(
            $data,
            $settings
        );

    /*
     * POST後は最新データを再読込。
     */
    $data =
        load_data();

    $settings =
        load_settings();

    if (
        refresh_statuses($data)
    ) {
        save_data($data);
    }

    if (
        $postResult !== null
    ) {
        $screen =
            (string)(
                $postResult['screen']
                    ?? 'list'
            );

        $id =
            (string)(
                $postResult['id']
                    ?? ''
            );
    } else {
        $screen =
            get_string('screen');

        if ($screen === '') {
            $screen = 'list';
        }

        $id =
            get_string('id');
    }

    /*
     * 回答者画面
     *
     * 管理者画面と完全分離。
     */
    if (
        in_array(
            $screen,
            [
                'answer',
                'confirm',
                'complete',
            ],
            true
        )
    ) {
        $survey =
            survey_by_id(
                $data['surveys'],
                $id
            );

        if ($survey === null) {
            render_head(
                'アンケート',
                false
            );
?>
<div class="answer-shell">
<div class="alert alert-error">
アンケートが見つかりません。
</div>
</div>
<?php
            render_footer();
            exit;
        }

        if (
            $screen === 'answer'
        ) {
            render_answer(
                $survey
            );
            exit;
        }

        if (
            $screen === 'confirm'
        ) {
            render_confirm(
                $survey
            );
            exit;
        }

        /*
         * completeから管理者一覧へ
         * 遷移させない。
         */
        render_complete(
            $survey
        );

        exit;
    }

    /*
     * 管理者画面
     */
    switch ($screen) {

        case 'edit':

            if ($id === 'new') {
                $survey = [
                    'id' =>
                        uuid('survey'),
                    'title' => '',
                    'description' => '',
                    'startAt' =>
                        date(
                            'Y-m-d\TH:i'
                        ),
                    'endAt' =>
                        date(
                            'Y-m-d\TH:i',
                            strtotime('+30 days')
                        ),
                    'status' => 'draft',
                    'numbering' => 'global',
                    'createdAt' =>
                        date(
                            'Y-m-d H:i:s'
                        ),
                    'updatedAt' =>
                        date(
                            'Y-m-d H:i:s'
                        ),
                    'groups' => [
                        [
                            'id' =>
                                uuid('group'),
                            'title' =>
                                '基本アンケート',
                            'questions' => [],
                        ],
                    ],
                ];

                render_edit(
                    $survey
                );

                break;
            }

            $survey =
                survey_by_id(
                    $data['surveys'],
                    $id
                );

            if ($survey === null) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                render_list(
                    $data
                );

                break;
            }

            render_edit(
                $survey
            );

            break;


        case 'preview':

            $survey =
                survey_by_id(
                    $data['surveys'],
                    $id
                );

            if ($survey === null) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                render_list(
                    $data
                );

                break;
            }

            render_preview(
                $survey
            );

            break;


        case 'kintone':

            render_kintone(
                $settings['kintone']
            );

            break;


        case 'mail':

            render_mail(
                $settings['mail']
            );

            break;


        case 'list':
        default:

            render_list(
                $data
            );

            break;
    }

} catch (Throwable $e) {

    /*
     * =====================================================
     * 最終防衛線
     *
     * ここでは未定義関数を呼ばない。
     * render_system_error() のような関数依存を作らない。
     *
     * Fatal Errorの二次発生を防ぐ。
     * =====================================================
     */

    http_response_code(500);

    $message =
        'アプリケーションの処理を完了できませんでした。';

    /*
     * スタックトレース・認証情報等は
     * ブラウザへ出力しない。
     */

    $isHtml =
        !empty($_SERVER['HTTP_ACCEPT'])
        && str_contains(
            (string)$_SERVER['HTTP_ACCEPT'],
            'text/html'
        );

    if (!$isHtml) {
        header(
            'Content-Type: text/plain; charset=UTF-8'
        );

        echo $message;
        exit;
    }

    /*
     * ここも関数を使わず自己完結させる。
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

.container{
 width:min(900px,calc(100% - 32px));
 margin:60px auto;
}

.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:12px;
 box-shadow:
 0 4px 18px rgba(15,23,42,.08);
 padding:28px;
}

.error{
 background:#fee2e2;
 color:#991b1b;
 border:1px solid #fecaca;
 border-radius:8px;
 padding:14px;
}

a{
 display:inline-block;
 margin-top:18px;
 background:#2563eb;
 color:#fff;
 text-decoration:none;
 padding:10px 15px;
 border-radius:8px;
}
</style>
</head>
<body>

<div class="container">

<div class="card">

<h1>システムエラー</h1>

<div class="error">
<?= h($message) ?>
</div>

<p>
データ保存先の権限、PHP設定、
外部サービス設定、ネットワーク設定等を確認してください。
</p>

<a href="<?= h(
    (string)(
        $_SERVER['SCRIPT_NAME']
        ?? 'index.php'
    )
) ?>">
アンケート一覧へ
</a>

</div>

</div>

</body>
</html>
<?php
}
?>
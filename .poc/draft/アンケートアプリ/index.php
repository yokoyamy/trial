<?php
declare(strict_types=1);

/*
 * ============================================================
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 *
 * 単一エントリーポイント
 * DBなし
 * PHP cURLなし
 * PHP mail()なし
 * Canvasなし
 * 管理者認証なし
 * CSRFトークンなし
 *
 * kintone:
 *   ログイン名 + パスワード
 *   X-Cybozu-Authorization
 *
 * 重要:
 *   kintoneの各操作は完全に分離する。
 *
 *   save_kintone
 *   test_kintone
 *   fetch_kintone_fields
 *   sync_kintone
 *
 *   接続テストではリダイレクトしない。
 * ============================================================
 */

date_default_timezone_set('Asia/Tokyo');

$APP_DIR  = __DIR__;
$DATA_DIR = $APP_DIR . DIRECTORY_SEPARATOR . 'data';

if (!is_dir($DATA_DIR)) {
    if (!@mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

/*
 * ============================================================
 * セッション
 * ============================================================
 *
 * CSRFでは使用しない。
 * 回答途中データ等の短期状態保持に使用する。
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    $cookiePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    if ($cookiePath === '') {
        $cookiePath = '/';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        http_response_code(500);
        exit('セッションを利用できません。');
    }
}

/*
 * ============================================================
 * 共通
 * ============================================================
 */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now_iso(): string
{
    return date('Y-m-d H:i:s');
}

function base_url(): string
{
    return 'index.php';
}

function redirect_screen(string $screen, array $params = []): never
{
    $query = ['screen' => $screen];

    foreach ($params as $key => $value) {
        $query[$key] = $value;
    }

    header(
        'Location: ' . base_url() . '?' . http_build_query($query),
        true,
        303
    );
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    $v = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($v) ? $v : null;
}

function safe_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id);
}

function json_read(string $file, mixed $default): mixed
{
    if (!is_file($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || $raw === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    return $data === null && json_last_error() !== JSON_ERROR_NONE
        ? $default
        : $data;
}

function json_write(string $file, mixed $data): bool
{
    $dir = dirname($file);

    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        return false;
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function data_file(string $name): string
{
    global $DATA_DIR;
    return $DATA_DIR . DIRECTORY_SEPARATOR . $name;
}

/*
 * ============================================================
 * データ初期化
 * ============================================================
 */

function load_surveys(): array
{
    $data = json_read(data_file('surveys.json'), []);
    return is_array($data) ? $data : [];
}

function save_surveys(array $surveys): bool
{
    return json_write(data_file('surveys.json'), array_values($surveys));
}

function load_customers(): array
{
    $data = json_read(data_file('customers.json'), []);
    return is_array($data) ? $data : [];
}

function save_customers(array $customers): bool
{
    return json_write(data_file('customers.json'), array_values($customers));
}

function load_answers(): array
{
    $data = json_read(data_file('answers.json'), []);
    return is_array($data) ? $data : [];
}

function save_answers(array $answers): bool
{
    return json_write(data_file('answers.json'), array_values($answers));
}

function load_send_history(): array
{
    $data = json_read(data_file('send_history.json'), []);
    return is_array($data) ? $data : [];
}

function save_send_history(array $rows): bool
{
    return json_write(data_file('send_history.json'), array_values($rows));
}

function load_kintone(): array
{
    $data = json_read(data_file('kintone.json'), []);

    if (!is_array($data)) {
        $data = [];
    }

    return array_merge([
        'subdomain' => '',
        'app_id' => '',
        'username' => '',
        'password' => '',
        'proxy' => '',
        'verify_ssl' => false,
        'fields' => [],
        'address_mapping' => [],
        'status' => '未設定',
        'last_test' => '',
        'last_sync' => '',
    ], $data);
}

function save_kintone_config(array $config): bool
{
    return json_write(data_file('kintone.json'), $config);
}

function load_mail_config(): array
{
    $data = json_read(data_file('mail.json'), []);

    if (!is_array($data)) {
        $data = [];
    }

    return array_merge([
        'server' => '',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
        'status' => '未設定',
        'last_test' => '',
    ], $data);
}

function save_mail_config(array $config): bool
{
    return json_write(data_file('mail.json'), $config);
}

/*
 * ============================================================
 * アンケート状態
 * ============================================================
 */

function refresh_survey_status(array &$survey): bool
{
    if (
        ($survey['status'] ?? 'draft') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now_iso();
            return true;
        }
    }

    return false;
}

function refresh_all_statuses(array &$surveys): void
{
    $changed = false;

    foreach ($surveys as &$survey) {
        if (refresh_survey_status($survey)) {
            $changed = true;
        }
    }
    unset($survey);

    if ($changed) {
        save_surveys($surveys);
    }
}

function find_survey(array $surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped'   => '停止',
        'ended'     => '終了',
        default     => '下書き',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'status-published',
        'stopped'   => 'status-stopped',
        'ended'     => 'status-ended',
        default     => 'status-draft',
    };
}

/*
 * ============================================================
 * 質問番号
 * ============================================================
 */

function recalc_question_numbers(array &$survey): void
{
    $mode = $survey['numbering'] ?? 'global';
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if ($mode === 'group') {
                $question['number'] =
                    'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $questionNo++;
            $global++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
}

function new_question(): array
{
    return [
        'id' => 'q-' . bin2hex(random_bytes(8)),
        'number' => '',
        'text' => '',
        'type' => 'single',
        'required' => true,
        'options' => ['選択肢1', '選択肢2'],
        'branching' => [],
    ];
}

function new_group(): array
{
    return [
        'id' => 'g-' . bin2hex(random_bytes(8)),
        'title' => '新しいグループ',
        'questions' => [
            new_question(),
        ],
    ];
}

/*
 * ============================================================
 * kintone
 * ============================================================
 *
 * PHP cURLは使用しない。
 * stream_context_create() / fopen() を使用する。
 */

function normalize_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim($value, '/');

    if (str_contains($value, '.cybozu.com')) {
        $value = preg_replace(
            '/\.cybozu\.com.*$/i',
            '',
            $value
        );
    }

    return trim((string)$value);
}

function kintone_host(array $config): string
{
    $subdomain = normalize_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    return $subdomain . '.cybozu.com';
}

function kintone_auth_header(array $config): string
{
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');

    return base64_encode(
        $username . ':' . $password
    );
}

function parse_proxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (!preg_match(
        '/^([^:\/\s]+):([0-9]{1,5})$/',
        $proxy,
        $m
    )) {
        return null;
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        return null;
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $host = kintone_host($config);

    if (
        $host === '.cybozu.com'
        || !preg_match(
            '/^[A-Za-z0-9.-]+\.cybozu\.com$/',
            $host
        )
    ) {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'message' => 'kintoneサブドメインが正しくありません。',
            'data' => null,
        ];
    }

    $url = 'https://' . $host . $path;

    $headers = [
        'X-Cybozu-Authorization: ' .
            kintone_auth_header($config),
        'Accept: application/json',
    ];

    $content = null;

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            return [
                'ok' => false,
                'category' => 'データエラー',
                'status' => 0,
                'message' => 'リクエストデータを作成できません。',
                'data' => null,
            ];
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $verifySsl = !empty($config['verify_ssl']);

    $ssl = [
        'verify_peer' => $verifySsl,
        'verify_peer_name' => $verifySsl,
        'allow_self_signed' => !$verifySsl,
        'SNI_enabled' => true,
    ];

    $http = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'timeout' => 20,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
    ];

    if ($content !== null) {
        $http['content'] = $content;
    }

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy !== null) {
        $http['proxy'] =
            'tcp://' . $proxy['host'] . ':' . $proxy['port'];

        /*
         * HTTPSをHTTP CONNECTでプロキシするための
         * request_fulluri は通常のHTTPプロキシ用途で使用。
         */
        $http['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $http,
        'ssl' => $ssl,
    ]);

    $error = null;

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$error): bool {
            $error = $message;
            return true;
        }
    );

    $response = file_get_contents(
        $url,
        false,
        $context
    );

    restore_error_handler();

    if ($response === false) {
        return [
            'ok' => false,
            'category' => '通信エラー',
            'status' => 0,
            'message' =>
                $error
                ?: 'kintoneへ接続できませんでした。',
            'data' => null,
        ];
    }

    $statusCode = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match(
            '/^HTTP\/[0-9.]+\s+([0-9]{3})/',
            $header,
            $m
        )) {
            $statusCode = (int)$m[1];
        }
    }

    $decoded = json_decode($response, true);

    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'ok' => true,
            'category' => '成功',
            'status' => $statusCode,
            'message' => '接続成功',
            'data' => is_array($decoded)
                ? $decoded
                : $response,
        ];
    }

    $message = 'kintoneからエラーが返されました。';

    if (is_array($decoded)) {
        if (!empty($decoded['message'])) {
            $message .= ' ' . (string)$decoded['message'];
        }

        if (!empty($decoded['id'])) {
            $message .= '（エラーID: ' .
                (string)$decoded['id'] . '）';
        }
    }

    $category = match (true) {
        $statusCode === 401 || $statusCode === 403
            => '認証エラー',
        $statusCode === 404
            => '設定エラー',
        $statusCode >= 500
            => '外部サービスエラー',
        default
            => '通信エラー',
    };

    return [
        'ok' => false,
        'category' => $category,
        'status' => $statusCode,
        'message' => $message,
        'data' => is_array($decoded)
            ? $decoded
            : null,
    ];
}

function validate_kintone_config(array $input): array
{
    $subdomain = normalize_subdomain(
        (string)($input['subdomain'] ?? '')
    );

    $appId = trim(
        (string)($input['app_id'] ?? '')
    );

    $username = trim(
        (string)($input['username'] ?? '')
    );

    $proxy = trim(
        (string)($input['proxy'] ?? '')
    );

    $errors = [];

    if (
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/',
            $subdomain
        )
    ) {
        $errors[] = 'サブドメインを正しく入力してください。';
    }

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] = '顧客管理アプリIDを正しく入力してください。';
    }

    if ($username === '') {
        $errors[] = 'ログイン名を入力してください。';
    }

    if ($proxy !== '' && parse_proxy($proxy) === null) {
        $errors[] =
            'Proxyは「host:port」形式で入力してください。';
    }

    return [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'proxy' => $proxy,
        'errors' => $errors,
    ];
}

function kintone_connection_test(array $config): array
{
    $validation = validate_kintone_config($config);

    if ($validation['errors']) {
        return [
            'ok' => false,
            'category' => '入力エラー',
            'message' => implode(
                ' ',
                $validation['errors']
            ),
        ];
    }

    if ((string)($config['password'] ?? '') === '') {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'message' =>
                'パスワードが未設定です。'
                . '設定保存後に接続テストしてください。',
        ];
    }

    $appId = (int)$validation['app_id'];

    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?app=' . rawurlencode((string)$appId)
    );
}

function kintone_fetch_fields(array $config): array
{
    $validation = validate_kintone_config($config);

    if ($validation['errors']) {
        return [
            'ok' => false,
            'category' => '入力エラー',
            'message' => implode(
                ' ',
                $validation['errors']
            ),
        ];
    }

    if ((string)($config['password'] ?? '') === '') {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'message' => 'パスワードが未設定です。',
        ];
    }

    $appId = (int)$validation['app_id'];

    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
            rawurlencode((string)$appId)
    );
}

function kintone_sync_customers(array $config): array
{
    $validation = validate_kintone_config($config);

    if ($validation['errors']) {
        return [
            'ok' => false,
            'category' => '入力エラー',
            'message' => implode(
                ' ',
                $validation['errors']
            ),
        ];
    }

    if ((string)($config['password'] ?? '') === '') {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'message' => 'パスワードが未設定です。',
        ];
    }

    $appId = (int)$validation['app_id'];

    $query = http_build_query([
        'app' => $appId,
        'totalCount' => 'true',
    ]);

    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?' . $query
    );

    if (!$result['ok']) {
        return $result;
    }

    $records = $result['data']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $values = [];

        foreach ($record as $fieldCode => $field) {
            if (
                is_array($field)
                && array_key_exists('value', $field)
            ) {
                $values[$fieldCode] = $field['value'];
            }
        }

        $customers[] = [
            'id' =>
                (string)($values['$id'] ?? '')
                ?: 'k-' . bin2hex(random_bytes(6)),
            'organization' =>
                (string)(
                    $values['organization']
                    ?? $values['組織名']
                    ?? ''
                ),
            'name' =>
                (string)(
                    $values['name']
                    ?? $values['氏名']
                    ?? ''
                ),
            'email' =>
                (string)(
                    $values['email']
                    ?? $values['メールアドレス']
                    ?? ''
                ),
            'department' =>
                (string)(
                    $values['department']
                    ?? $values['部署名']
                    ?? ''
                ),
            'phone' =>
                (string)(
                    $values['phone']
                    ?? $values['電話番号']
                    ?? ''
                ),
            'address' =>
                (string)(
                    $values['address']
                    ?? $values['住所']
                    ?? ''
                ),
            'source' => 'kintone',
            'updatedAt' => now_iso(),
        ];
    }

    return [
        'ok' => true,
        'category' => '成功',
        'message' =>
            count($customers)
            . '件の顧客情報を取得しました。',
        'customers' => $customers,
    ];
}

/*
 * ============================================================
 * SMTP
 * ============================================================
 */

function smtp_read($socket): array
{
    $lines = [];

    while (!feof($socket)) {
        $line = fgets($socket, 8192);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (
            isset($line[3])
            && $line[3] === ' '
        ) {
            break;
        }
    }

    $last = end($lines);

    $code = 0;

    if (
        is_string($last)
        && preg_match('/^([0-9]{3})/', $last, $m)
    ) {
        $code = (int)$m[1];
    }

    return [
        'code' => $code,
        'lines' => $lines,
    ];
}

function smtp_write($socket, string $line): void
{
    fwrite($socket, $line . "\r\n");
}

function smtp_expect(
    $socket,
    array $codes
): array {
    $response = smtp_read($socket);

    if (!in_array($response['code'], $codes, true)) {
        throw new RuntimeException(
            'SMTP応答コード: ' .
            $response['code']
        );
    }

    return $response;
}

function smtp_test(array $config): array
{
    $server = trim(
        (string)($config['server'] ?? '')
    );

    $port = (int)($config['port'] ?? 0);

    $encryption = strtolower(
        (string)($config['encryption'] ?? 'none')
    );

    if ($server === '') {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'message' => 'SMTPサーバを入力してください。',
        ];
    }

    if ($port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'message' => 'SMTPポートが不正です。',
        ];
    }

    $transport =
        $encryption === 'ssl'
            ? 'ssl://'
            : 'tcp://';

    $errorNo = 0;
    $errorStr = '';

    $socket = @stream_socket_client(
        $transport . $server . ':' . $port,
        $errorNo,
        $errorStr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return [
            'ok' => false,
            'category' => '通信エラー',
            'message' =>
                'SMTPサーバへ接続できませんでした。',
        ];
    }

    stream_set_timeout($socket, 15);

    try {
        smtp_expect($socket, [220]);

        smtp_write($socket, 'EHLO localhost');
        smtp_expect($socket, [250]);

        if ($encryption === 'tls') {
            smtp_write($socket, 'STARTTLS');
            smtp_expect($socket, [220]);

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'TLS接続を確立できませんでした。'
                );
            }

            smtp_write($socket, 'EHLO localhost');
            smtp_expect($socket, [250]);
        }

        if (!empty($config['auth'])) {
            $username = (string)(
                $config['username'] ?? ''
            );

            $password = (string)(
                $config['password'] ?? ''
            );

            if ($username === '') {
                throw new RuntimeException(
                    'SMTPユーザー名が未設定です。'
                );
            }

            smtp_write($socket, 'AUTH LOGIN');
            smtp_expect($socket, [334]);

            smtp_write(
                $socket,
                base64_encode($username)
            );
            smtp_expect($socket, [334]);

            smtp_write(
                $socket,
                base64_encode($password)
            );
            smtp_expect($socket, [235]);
        }

        smtp_write($socket, 'QUIT');

        @fclose($socket);

        return [
            'ok' => true,
            'category' => '成功',
            'message' => 'SMTP接続確認済み',
        ];
    } catch (Throwable $e) {
        @fclose($socket);

        return [
            'ok' => false,
            'category' => '通信エラー',
            'message' =>
                'SMTP接続に失敗しました。',
        ];
    }
}

/*
 * ============================================================
 * POST処理
 * ============================================================
 */

$screen = (string)(
    $_GET['screen'] ?? 'list'
);

if ($screen === '') {
    $screen = 'list';
}

$action = (string)(
    $_POST['action'] ?? ''
);

/*
 * ------------------------------------------------------------
 * kintone設定保存
 *
 * ここだけ設定保存。
 * 接続テストとは完全に別処理。
 * ------------------------------------------------------------
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'kintone'
    && $action === 'save_kintone'
) {
    $old = load_kintone();

    $password = (string)(
        $_POST['password'] ?? ''
    );

    if ($password === '') {
        $password = (string)(
            $old['password'] ?? ''
        );
    }

    $config = [
        'subdomain' =>
            normalize_subdomain(
                (string)($_POST['subdomain'] ?? '')
            ),
        'app_id' =>
            trim((string)(
                $_POST['app_id'] ?? ''
            )),
        'username' =>
            trim((string)(
                $_POST['username'] ?? ''
            )),
        'password' => $password,
        'proxy' =>
            trim((string)(
                $_POST['proxy'] ?? ''
            )),
        'verify_ssl' =>
            isset($_POST['verify_ssl']),
        'fields' =>
            $old['fields'] ?? [],
        'address_mapping' =>
            is_array($_POST['address_mapping'] ?? null)
                ? $_POST['address_mapping']
                : ($old['address_mapping'] ?? []),
        'status' =>
            $old['status'] ?? '未設定',
        'last_test' =>
            $old['last_test'] ?? '',
        'last_sync' =>
            $old['last_sync'] ?? '',
    ];

    $validation = validate_kintone_config(
        $config
    );

    if ($validation['errors']) {
        flash(
            'error',
            implode(' ', $validation['errors'])
        );

        redirect_screen('kintone');
    }

    if (!save_kintone_config($config)) {
        flash(
            'error',
            'kintone設定を保存できませんでした。'
        );

        redirect_screen('kintone');
    }

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirect_screen('kintone');
}

/*
 * ------------------------------------------------------------
 * kintone接続テスト
 *
 * ★重要:
 * ここでは redirect_screen() を呼ばない。
 * 303を発生させない。
 * ------------------------------------------------------------
 */

$kintoneTestResult = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'kintone'
    && $action === 'test_kintone'
) {
    $saved = load_kintone();

    $password = (string)(
        $_POST['password'] ?? ''
    );

    if ($password === '') {
        $password = (string)(
            $saved['password'] ?? ''
        );
    }

    $testConfig = [
        'subdomain' =>
            normalize_subdomain(
                (string)(
                    $_POST['subdomain']
                    ?? $saved['subdomain']
                    ?? ''
                )
            ),
        'app_id' =>
            trim((string)(
                $_POST['app_id']
                ?? $saved['app_id']
                ?? ''
            )),
        'username' =>
            trim((string)(
                $_POST['username']
                ?? $saved['username']
                ?? ''
            )),
        'password' => $password,
        'proxy' =>
            trim((string)(
                $_POST['proxy']
                ?? $saved['proxy']
                ?? ''
            )),
        'verify_ssl' =>
            isset($_POST['verify_ssl'])
            ? true
            : (bool)($saved['verify_ssl'] ?? false),
    ];

    /*
     * 接続テストは実際のkintoneへ接続。
     */
    $result = kintone_connection_test(
        $testConfig
    );

    $kintoneTestResult = $result;

    /*
     * 接続結果はサーバー側にも保存。
     * パスワードはログ・画面へ出さない。
     */
    $saved['subdomain'] =
        $testConfig['subdomain'];

    $saved['app_id'] =
        $testConfig['app_id'];

    $saved['username'] =
        $testConfig['username'];

    $saved['proxy'] =
        $testConfig['proxy'];

    $saved['verify_ssl'] =
        $testConfig['verify_ssl'];

    /*
     * ブラウザから空欄だった場合も、
     * 保存済みパスワードを保持する。
     */
    if ($testConfig['password'] !== '') {
        $saved['password'] =
            $testConfig['password'];
    }

    $saved['status'] =
        $result['ok']
            ? '接続確認済み'
            : '接続できません';

    $saved['last_test'] = now_iso();

    save_kintone_config($saved);
}

/*
 * ------------------------------------------------------------
 * kintone項目一覧再取得
 * ------------------------------------------------------------
 */

$kintoneFieldResult = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'kintone'
    && $action === 'fetch_kintone_fields'
) {
    $config = load_kintone();

    $result = kintone_fetch_fields(
        $config
    );

    $kintoneFieldResult = $result;

    if ($result['ok']) {
        $fields = [];

        foreach (
            ($result['data']['properties'] ?? [])
            as $code => $field
        ) {
            if (!is_array($field)) {
                continue;
            }

            $fields[] = [
                'code' => $code,
                'label' =>
                    (string)(
                        $field['label'] ?? $code
                    ),
                'type' =>
                    (string)(
                        $field['type'] ?? ''
                    ),
            ];
        }

        $config['fields'] = $fields;

        save_kintone_config($config);
    }
}

/*
 * ------------------------------------------------------------
 * kintone顧客同期
 * ------------------------------------------------------------
 */

$kintoneSyncResult = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'kintone'
    && $action === 'sync_kintone'
) {
    $config = load_kintone();

    $result = kintone_sync_customers(
        $config
    );

    $kintoneSyncResult = $result;

    if ($result['ok']) {
        $customers =
            $result['customers'] ?? [];

        if (save_customers($customers)) {
            $config['last_sync'] = now_iso();
            save_kintone_config($config);

            $kintoneSyncResult['message'] =
                count($customers)
                . '件の顧客情報を同期しました。';
        } else {
            $kintoneSyncResult = [
                'ok' => false,
                'category' => 'データエラー',
                'message' =>
                    '顧客情報を保存できませんでした。',
            ];
        }
    }
}

/*
 * ------------------------------------------------------------
 * アンケート保存
 * ------------------------------------------------------------
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'edit'
    && $action === 'save_survey'
) {
    $surveys = load_surveys();

    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $isNew = $id === '';

    if ($isNew) {
        $id = 'survey-' .
            bin2hex(random_bytes(8));

        $survey = [
            'id' => $id,
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [new_group()],
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ];
    } else {
        $survey = find_survey(
            $surveys,
            $id
        );

        if ($survey === null) {
            flash(
                'error',
                'アンケートが存在しません。'
            );

            redirect_screen('list');
        }
    }

    $survey['title'] =
        trim((string)(
            $_POST['title'] ?? ''
        ));

    $survey['description'] =
        trim((string)(
            $_POST['description'] ?? ''
        ));

    $survey['startAt'] =
        trim((string)(
            $_POST['startAt'] ?? ''
        ));

    $survey['endAt'] =
        trim((string)(
            $_POST['endAt'] ?? ''
        ));

    $numbering =
        (string)(
            $_POST['numbering'] ?? 'global'
        );

    $survey['numbering'] =
        in_array(
            $numbering,
            ['global', 'group'],
            true
        )
            ? $numbering
            : 'global';

    if ($survey['title'] === '') {
        flash(
            'error',
            'アンケートタイトルを入力してください。'
        );

        redirect_screen(
            'edit',
            ['id' => $id]
        );
    }

    recalc_question_numbers($survey);

    $survey['updatedAt'] = now_iso();

    $found = false;

    foreach ($surveys as $index => $row) {
        if (($row['id'] ?? '') === $id) {
            $surveys[$index] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $surveys[] = $survey;
    }

    if (!save_surveys($surveys)) {
        flash(
            'error',
            'アンケートを保存できませんでした。'
        );

        redirect_screen(
            'edit',
            ['id' => $id]
        );
    }

    flash(
        'success',
        'アンケートを保存しました。'
    );

    redirect_screen('list');
}

/*
 * ------------------------------------------------------------
 * 状態変更
 * ------------------------------------------------------------
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'edit'
    && $action === 'change_status'
) {
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $newStatus = (string)(
        $_POST['new_status'] ?? ''
    );

    $allowed = [
        'draft' => ['published'],
        'published' => ['stopped'],
        'stopped' => ['published'],
        'ended' => [],
    ];

    $surveys = load_surveys();

    foreach ($surveys as &$survey) {
        if (($survey['id'] ?? '') !== $id) {
            continue;
        }

        $current =
            (string)($survey['status'] ?? 'draft');

        if (
            !isset($allowed[$current])
            || !in_array(
                $newStatus,
                $allowed[$current],
                true
            )
        ) {
            flash(
                'error',
                'その状態変更は許可されていません。'
            );

            break;
        }

        $survey['status'] = $newStatus;
        $survey['updatedAt'] = now_iso();

        flash(
            'success',
            '状態を変更しました。'
        );

        break;
    }

    unset($survey);

    save_surveys($surveys);

    redirect_screen(
        'edit',
        ['id' => $id]
    );
}

/*
 * ------------------------------------------------------------
 * 複製
 * ------------------------------------------------------------
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'list'
    && $action === 'duplicate'
) {
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $surveys = load_surveys();
    $source = find_survey($surveys, $id);

    if ($source !== null) {
        $source['id'] =
            'survey-' . bin2hex(random_bytes(8));

        $source['title'] =
            (string)($source['title'] ?? '')
            . '（コピー）';

        $source['status'] = 'draft';
        $source['createdAt'] = now_iso();
        $source['updatedAt'] = now_iso();

        $surveys[] = $source;

        save_surveys($surveys);

        flash(
            'success',
            'アンケートを複製しました。'
        );
    }

    redirect_screen('list');
}

/*
 * ------------------------------------------------------------
 * 削除
 * ------------------------------------------------------------
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'list'
    && $action === 'delete'
) {
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $surveys = load_surveys();

    $surveys = array_values(
        array_filter(
            $surveys,
            static fn(array $s): bool =>
                ($s['id'] ?? '') !== $id
        )
    );

    save_surveys($surveys);

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirect_screen('list');
}

/*
 * ============================================================
 * 表示用データ
 * ============================================================
 */

$surveys = load_surveys();
refresh_all_statuses($surveys);
$surveys = load_surveys();

$flash = get_flash();

$currentId = trim(
    (string)($_GET['id'] ?? '')
);

$currentSurvey = null;

if ($currentId !== '' && safe_id($currentId)) {
    $currentSurvey =
        find_survey(
            $surveys,
            $currentId
        );
}

/*
 * ============================================================
 * HTML
 * ============================================================
 */

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート管理</title>

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
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    color:var(--text);
    background:#f8fafc;
}

body{
    min-height:100vh;
}

a{
    color:inherit;
    text-decoration:none;
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

.admin-header{
    background:#0f172a;
    color:#fff;
    height:64px;
    display:flex;
    align-items:center;
    padding:0 24px;
}

.admin-header .brand{
    font-size:19px;
    font-weight:700;
}

.admin-nav{
    margin-left:auto;
    display:flex;
    gap:8px;
}

.admin-nav a{
    color:#cbd5e1;
    padding:9px 12px;
    border-radius:7px;
}

.admin-nav a:hover{
    color:#fff;
    background:#1e293b;
}

.container{
    width:min(1440px,calc(100% - 32px));
    margin:28px auto;
}

.page-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:20px;
}

.page-title{
    margin:0;
    font-size:26px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:18px;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:1px solid transparent;
    border-radius:7px;
    padding:9px 14px;
    background:#fff;
    color:var(--text);
    min-height:40px;
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-secondary{
    border-color:var(--border);
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

.btn:disabled{
    opacity:.5;
    cursor:not-allowed;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.form-grid{
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-group{
    display:flex;
    flex-direction:column;
    gap:7px;
}

.form-group.full{
    grid-column:1 / -1;
}

label{
    font-weight:600;
    font-size:14px;
}

input,
select,
textarea{
    width:100%;
    border:1px solid var(--border);
    border-radius:7px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

textarea{
    min-height:120px;
    resize:vertical;
}

.help{
    font-size:13px;
    color:var(--gray);
}

.alert{
    padding:13px 15px;
    border-radius:8px;
    margin-bottom:18px;
}

.alert-success{
    color:#166534;
    background:#dcfce7;
    border:1px solid #bbf7d0;
}

.alert-error{
    color:#991b1b;
    background:#fee2e2;
    border:1px solid #fecaca;
}

.alert-warning{
    color:#92400e;
    background:#fef3c7;
    border:1px solid #fde68a;
}

.status{
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.status-draft{
    background:#e2e8f0;
    color:#475569;
}

.status-published{
    background:#dcfce7;
    color:#166534;
}

.status-stopped{
    background:#fef3c7;
    color:#92400e;
}

.status-ended{
    background:#fee2e2;
    color:#991b1b;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    min-width:1050px;
    border-collapse:collapse;
}

th,
td{
    padding:12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    white-space:nowrap;
    font-size:13px;
}

td{
    font-size:14px;
}

.toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
}

.search{
    flex:1 1 300px;
}

.filter{
    min-width:160px;
}

.section-title{
    margin:0 0 15px;
    font-size:18px;
}

.setting-actions{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:18px;
    padding-top:18px;
    border-top:1px solid var(--border);
}

.kintone-result{
    margin-top:18px;
}

.spinner{
    width:18px;
    height:18px;
    border:3px solid rgba(255,255,255,.4);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
}

@keyframes spin{
    to{transform:rotate(360deg)}
}

.group{
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:16px;
    background:#fff;
}

.group-head{
    padding:13px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    gap:10px;
}

.group-body{
    padding:14px;
}

.question{
    border:1px solid var(--border);
    border-radius:8px;
    padding:14px;
    margin-bottom:10px;
    background:#fff;
}

.question:last-child{
    margin-bottom:0;
}

.question-grid{
    display:grid;
    grid-template-columns:
        90px 1fr 170px;
    gap:10px;
    align-items:start;
}

.q-number{
    font-weight:700;
    color:var(--primary);
}

.checkbox{
    width:auto;
}

.option-row{
    display:flex;
    gap:8px;
    margin-top:8px;
}

.sticky-actions{
    position:sticky;
    top:0;
    z-index:5;
    background:#f8fafc;
    padding:10px 0;
}

.empty{
    text-align:center;
    color:var(--gray);
    padding:45px 20px;
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
    padding:18px;
}

.stat-label{
    color:var(--gray);
    font-size:13px;
}

.stat-value{
    font-size:27px;
    font-weight:700;
    margin-top:5px;
}

.answer-page{
    max-width:760px;
    margin:0 auto;
}

.answer-card{
    padding:20px;
    margin-bottom:15px;
}

.answer-option{
    display:flex;
    align-items:center;
    gap:10px;
    padding:13px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-top:8px;
}

.answer-option input{
    width:auto;
}

@media(max-width:900px){
    .form-grid,
    .question-grid,
    .stat-grid{
        grid-template-columns:1fr;
    }

    .admin-header{
        padding:0 12px;
    }

    .admin-nav{
        display:none;
    }

    .container{
        width:min(100% - 20px,1440px);
        margin:15px auto;
    }

    .page-head{
        align-items:flex-start;
        flex-direction:column;
    }
}

@media(max-width:600px){
    .card{
        padding:15px;
    }

    .btn{
        width:100%;
    }

    .actions{
        width:100%;
    }

    .actions .btn{
        flex:1 1 100%;
    }
}
</style>
</head>

<body>

<?php
$isAnswerer =
    in_array(
        $screen,
        ['answer', 'confirm', 'complete'],
        true
    );
?>

<?php if (!$isAnswerer): ?>

<header class="admin-header">
    <div class="brand">アンケート管理</div>

    <nav class="admin-nav">
        <a href="index.php?screen=list">
            アンケート一覧
        </a>
        <a href="index.php?screen=kintone">
            kintone連携設定
        </a>
        <a href="index.php?screen=mail">
            メールサーバ設定
        </a>
    </nav>
</header>

<?php endif; ?>

<main class="container">

<?php if ($flash): ?>
<div class="alert
    <?= $flash['type'] === 'success'
        ? 'alert-success'
        : 'alert-error' ?>">
    <?= h($flash['message']) ?>
</div>
<?php endif; ?>


<?php
/*
 * ============================================================
 * 一覧
 * ============================================================
 */

if ($screen === 'list'):
?>

<div class="page-head">
    <h1 class="page-title">アンケート一覧</h1>

    <a
        class="btn btn-primary"
        href="index.php?screen=edit"
    >
        ＋ 新規作成
    </a>
</div>

<div class="card">
    <form
        method="get"
        class="toolbar"
        id="listSearch"
    >
        <input
            type="hidden"
            name="screen"
            value="list"
        >

        <input
            class="search"
            type="search"
            name="q"
            placeholder="タイトルを検索"
            value="<?= h($_GET['q'] ?? '') ?>"
        >

        <select
            class="filter"
            name="status"
        >
            <?php
            $selectedStatus =
                (string)($_GET['status'] ?? '');
            ?>
            <option value="">すべて</option>
            <option
                value="published"
                <?= $selectedStatus === 'published'
                    ? 'selected' : '' ?>
            >公開中</option>
            <option
                value="draft"
                <?= $selectedStatus === 'draft'
                    ? 'selected' : '' ?>
            >下書き</option>
            <option
                value="stopped"
                <?= $selectedStatus === 'stopped'
                    ? 'selected' : '' ?>
            >停止</option>
            <option
                value="ended"
                <?= $selectedStatus === 'ended'
                    ? 'selected' : '' ?>
            >終了</option>
        </select>

        <select
            class="filter"
            name="sort"
        >
            <?php
            $sort =
                (string)($_GET['sort'] ?? 'updated_desc');
            ?>
            <option
                value="updated_desc"
                <?= $sort === 'updated_desc'
                    ? 'selected' : '' ?>
            >更新日：新しい順</option>

            <option
                value="updated_asc"
                <?= $sort === 'updated_asc'
                    ? 'selected' : '' ?>
            >更新日：古い順</option>

            <option
                value="answers_desc"
                <?= $sort === 'answers_desc'
                    ? 'selected' : '' ?>
            >回答数：多い順</option>

            <option
                value="answers_asc"
                <?= $sort === 'answers_asc'
                    ? 'selected' : '' ?>
            >回答数：少ない順</option>

            <option
                value="start_desc"
                <?= $sort === 'start_desc'
                    ? 'selected' : '' ?>
            >開始日：新しい順</option>

            <option
                value="start_asc"
                <?= $sort === 'start_asc'
                    ? 'selected' : '' ?>
            >開始日：古い順</option>
        </select>

        <button
            class="btn btn-secondary"
            type="submit"
        >
            検索
        </button>
    </form>
</div>

<?php
$q =
    trim((string)($_GET['q'] ?? ''));

$statusFilter =
    (string)($_GET['status'] ?? '');

$filtered = array_filter(
    $surveys,
    static function(array $survey)
        use ($q, $statusFilter): bool
    {
        if (
            $q !== ''
            && !str_contains(
                mb_strtolower(
                    (string)($survey['title'] ?? '')
                ),
                mb_strtolower($q)
            )
        ) {
            return false;
        }

        if (
            $statusFilter !== ''
            && ($survey['status'] ?? 'draft')
                !== $statusFilter
        ) {
            return false;
        }

        return true;
    }
);

usort(
    $filtered,
    static function(array $a, array $b)
        use ($sort): int
    {
        if (str_starts_with($sort, 'answers')) {
            $av = (int)($a['answerCount'] ?? 0);
            $bv = (int)($b['answerCount'] ?? 0);
        } elseif (
            str_starts_with($sort, 'start')
        ) {
            $av = strtotime(
                (string)($a['startAt'] ?? '')
            ) ?: 0;
            $bv = strtotime(
                (string)($b['startAt'] ?? '')
            ) ?: 0;
        } else {
            $av = strtotime(
                (string)($a['updatedAt'] ?? '')
            ) ?: 0;
            $bv = strtotime(
                (string)($b['updatedAt'] ?? '')
            ) ?: 0;
        }

        $descending =
            str_ends_with($sort, '_desc');

        return $descending
            ? $bv <=> $av
            : $av <=> $bv;
    }
);
?>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>作成日</th>
<th>更新日</th>
<th>アンケート期間</th>
<th>ステータス</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>

<tbody>

<?php if (!$filtered): ?>

<tr>
<td colspan="7">
<div class="empty">
アンケートがありません。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($filtered as $survey): ?>

<tr>
<td>
<strong>
<?= h($survey['title'] ?? '無題') ?>
</strong>
</td>

<td>
<?= h($survey['createdAt'] ?? '') ?>
</td>

<td>
<?= h($survey['updatedAt'] ?? '') ?>
</td>

<td>
<?= h($survey['startAt'] ?? '') ?>
～
<?= h($survey['endAt'] ?? '') ?>
</td>

<td>
<span class="status
    <?= h(status_class(
        (string)($survey['status'] ?? 'draft')
    )) ?>">
    <?= h(status_label(
        (string)($survey['status'] ?? 'draft')
    )) ?>
</span>
</td>

<td>
<?= (int)($survey['answerCount'] ?? 0) ?>
</td>

<td>
<div class="actions">

<a
    class="btn btn-secondary"
    href="index.php?screen=edit&id=<?= rawurlencode(
        (string)$survey['id']
    ) ?>"
>
確認・編集
</a>

<a
    class="btn btn-secondary"
    href="index.php?screen=analytics&id=<?= rawurlencode(
        (string)$survey['id']
    ) ?>"
>
集計
</a>

<a
    class="btn btn-primary"
    href="index.php?screen=send&id=<?= rawurlencode(
        (string)$survey['id']
    ) ?>"
>
送信
</a>

<form
    method="post"
    style="display:inline"
    onsubmit="return confirm('このアンケートを複製しますか？')"
>
<input
    type="hidden"
    name="action"
    value="duplicate"
>
<input
    type="hidden"
    name="id"
    value="<?= h($survey['id']) ?>"
>
<button
    class="btn btn-secondary"
    type="submit"
>
複製
</button>
</form>

<form
    method="post"
    style="display:inline"
    onsubmit="return confirm('このアンケートを削除しますか？')"
>
<input
    type="hidden"
    name="action"
    value="delete"
>
<input
    type="hidden"
    name="id"
    value="<?= h($survey['id']) ?>"
>
<button
    class="btn btn-danger"
    type="submit"
>
削除
</button>
</form>

</div>
</td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>
</div>

<?php
/*
 * ============================================================
 * kintone設定
 * ============================================================
 */

elseif ($screen === 'kintone'):

$config = load_kintone();

?>

<div class="page-head">
    <h1 class="page-title">
        kintone連携設定
    </h1>

    <a
        class="btn btn-secondary"
        href="index.php?screen=list"
    >
        一覧へ戻る
    </a>
</div>

<?php if ($kintoneTestResult !== null): ?>

<div class="alert
    <?= $kintoneTestResult['ok']
        ? 'alert-success'
        : 'alert-error' ?>"
>
<strong>
<?= $kintoneTestResult['ok']
    ? '接続成功'
    : '接続失敗' ?>
</strong>

<br>

<?= h(
    (string)(
        $kintoneTestResult['message']
        ?? ''
    )
) ?>

<?php if (
    !$kintoneTestResult['ok']
    && !empty($kintoneTestResult['category'])
): ?>

<br>
原因区分：
<?= h(
    (string)$kintoneTestResult['category']
) ?>

<?php endif; ?>
</div>

<?php endif; ?>

<?php if ($kintoneFieldResult !== null): ?>

<div class="alert
    <?= $kintoneFieldResult['ok']
        ? 'alert-success'
        : 'alert-error' ?>"
>
<?= h(
    (string)(
        $kintoneFieldResult['message']
        ?? (
            $kintoneFieldResult['ok']
                ? '項目一覧を取得しました。'
                : '項目一覧の取得に失敗しました。'
        )
    )
) ?>
</div>

<?php endif; ?>

<?php if ($kintoneSyncResult !== null): ?>

<div class="alert
    <?= $kintoneSyncResult['ok']
        ? 'alert-success'
        : 'alert-error' ?>"
>
<?= h(
    (string)(
        $kintoneSyncResult['message']
        ?? ''
    )
) ?>
</div>

<?php endif; ?>

<div class="card">

<h2 class="section-title">
接続設定
</h2>

<form
    method="post"
    action="index.php?screen=kintone"
    id="kintoneForm"
>

<input
    type="hidden"
    name="action"
    value="save_kintone"
>

<div class="form-grid">

<div class="form-group">
<label for="subdomain">
サブドメイン
</label>

<input
    id="subdomain"
    name="subdomain"
    type="text"
    value="<?= h(
        $config['subdomain'] ?? ''
    ) ?>"
    placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com"
    required
>

<div class="help">
https://xxxx.cybozu.com、
xxxx.cybozu.com、
xxxx のいずれも入力可能です。
</div>
</div>

<div class="form-group">
<label for="app_id">
顧客管理アプリID
</label>

<input
    id="app_id"
    name="app_id"
    type="number"
    min="1"
    value="<?= h(
        $config['app_id'] ?? ''
    ) ?>"
    required
>
</div>

<div class="form-group">
<label for="username">
ログイン名
</label>

<input
    id="username"
    name="username"
    type="text"
    value="<?= h(
        $config['username'] ?? ''
    ) ?>"
    autocomplete="username"
    required
>
</div>

<div class="form-group">
<label for="password">
パスワード
</label>

<input
    id="password"
    name="password"
    type="password"
    value=""
    autocomplete="new-password"
>

<div class="help">
変更しない場合は空欄のままにしてください。
</div>
</div>

<div class="form-group">
<label for="proxy">
Proxy
</label>

<input
    id="proxy"
    name="proxy"
    type="text"
    value="<?= h(
        $config['proxy'] ?? ''
    ) ?>"
    placeholder="192.168.81.81:8080"
>

<div class="help">
未入力の場合はProxyを使用せず直接接続します。
</div>
</div>

<div class="form-group">
<label>
SSL証明書検証
</label>

<label style="font-weight:400">
<input
    class="checkbox"
    type="checkbox"
    name="verify_ssl"
    value="1"
    <?= !empty($config['verify_ssl'])
        ? 'checked'
        : '' ?>
>
有効
</label>

<div class="help">
POCでは無効を初期値とします。
</div>
</div>

</div>

<div class="setting-actions">

<!--
    ★設定保存専用
    test_kintoneとは別action
-->
<button
    class="btn btn-primary"
    type="submit"
    name="action"
    value="save_kintone"
>
設定保存
</button>

<!--
    ★接続テスト専用
    redirectしない
-->
<button
    class="btn btn-success"
    type="submit"
    name="action"
    value="test_kintone"
    id="testKintoneButton"
>
接続テスト
</button>

</div>

</form>

</div>

<div class="card">

<h2 class="section-title">
顧客情報
</h2>

<div class="help">
接続テスト、項目一覧取得、顧客同期は
それぞれ独立した操作です。
</div>

<div class="setting-actions">

<form
    method="post"
    action="index.php?screen=kintone"
    style="display:inline"
>
<input
    type="hidden"
    name="action"
    value="fetch_kintone_fields"
>
<button
    class="btn btn-secondary"
    type="submit"
>
項目一覧を再取得
</button>
</form>

<form
    method="post"
    action="index.php?screen=kintone"
    style="display:inline"
    onsubmit="return confirm('kintoneから顧客情報を同期しますか？')"
>
<input
    type="hidden"
    name="action"
    value="sync_kintone"
>
<button
    class="btn btn-primary"
    type="submit"
>
顧客情報を同期
</button>
</form>

</div>

<?php if (!empty($config['fields'])): ?>

<div style="margin-top:20px">
<strong>取得済み項目</strong>

<div class="table-wrap">
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
    $config['fields'] as $field
):
?>

<tr>
<td><?= h($field['code'] ?? '') ?></td>
<td><?= h($field['label'] ?? '') ?></td>
<td><?= h($field['type'] ?? '') ?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>
</div>

<?php endif; ?>

</div>

<?php
/*
 * ============================================================
 * メール設定
 * ============================================================
 */

elseif ($screen === 'mail'):

$mail = load_mail_config();

?>

<div class="page-head">
<h1 class="page-title">
メールサーバ設定
</h1>

<a
    class="btn btn-secondary"
    href="index.php?screen=list"
>
一覧へ戻る
</a>
</div>

<div class="card">

<form
    method="post"
    action="index.php?screen=mail"
>
<input
    type="hidden"
    name="action"
    value="save_mail"
>

<div class="form-grid">

<div class="form-group">
<label>SMTPサーバ</label>
<input
    name="server"
    type="text"
    value="<?= h($mail['server']) ?>"
    required
>
</div>

<div class="form-group">
<label>SMTPポート</label>
<input
    name="port"
    type="number"
    min="1"
    max="65535"
    value="<?= h($mail['port']) ?>"
    required
>
</div>

<div class="form-group">
<label>暗号化方式</label>
<select name="encryption">
<option
    value="ssl"
    <?= $mail['encryption'] === 'ssl'
        ? 'selected' : '' ?>
>SSL</option>
<option
    value="tls"
    <?= $mail['encryption'] === 'tls'
        ? 'selected' : '' ?>
>TLS</option>
<option
    value="none"
    <?= $mail['encryption'] === 'none'
        ? 'selected' : '' ?>
>なし</option>
</select>
</div>

<div class="form-group">
<label>SMTP認証</label>
<label style="font-weight:400">
<input
    class="checkbox"
    type="checkbox"
    name="auth"
    value="1"
    <?= !empty($mail['auth'])
        ? 'checked' : '' ?>
>
使用する
</label>
</div>

<div class="form-group">
<label>SMTPユーザー名</label>
<input
    name="username"
    type="text"
    value="<?= h($mail['username']) ?>"
>
</div>

<div class="form-group">
<label>SMTPパスワード</label>
<input
    name="password"
    type="password"
    value=""
    autocomplete="new-password"
>
</div>

<div class="form-group">
<label>送信元メールアドレス</label>
<input
    name="from_email"
    type="email"
    value="<?= h($mail['from_email']) ?>"
    required
>
</div>

<div class="form-group">
<label>送信元名</label>
<input
    name="from_name"
    type="text"
    value="<?= h($mail['from_name']) ?>"
>
</div>

<div class="form-group">
<label>返信先メールアドレス</label>
<input
    name="reply_to"
    type="email"
    value="<?= h($mail['reply_to']) ?>"
>
</div>

</div>

<div class="setting-actions">

<button
    class="btn btn-primary"
    type="submit"
    name="action"
    value="save_mail"
>
設定保存
</button>

<button
    class="btn btn-success"
    type="submit"
    name="action"
    value="test_mail"
>
接続テスト
</button>

</div>

</form>

</div>

<?php
/*
 * ============================================================
 * 編集
 * ============================================================
 */

elseif ($screen === 'edit'):

if ($currentSurvey === null) {
    $currentSurvey = [
        'id' => '',
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'groups' => [new_group()],
        'createdAt' => '',
        'updatedAt' => '',
    ];
}

?>

<div class="sticky-actions">
<div class="page-head">

<h1 class="page-title">
アンケート作成・編集
</h1>

<div class="actions">

<a
    class="btn btn-secondary"
    href="index.php?screen=list"
    onclick="return confirm('編集内容を破棄して戻りますか？')"
>
キャンセル
</a>

<a
    class="btn btn-secondary"
    href="index.php?screen=preview&id=<?= rawurlencode(
        (string)$currentSurvey['id']
    ) ?>"
>
プレビュー
</a>

</div>

</div>
</div>

<div class="card">

<form
    method="post"
    action="index.php?screen=edit"
    id="surveyForm"
>

<input
    type="hidden"
    name="action"
    value="save_survey"
>

<input
    type="hidden"
    name="id"
    value="<?= h($currentSurvey['id']) ?>"
>

<div class="form-grid">

<div class="form-group full">
<label>アンケートタイトル</label>
<input
    name="title"
    type="text"
    maxlength="200"
    value="<?= h(
        $currentSurvey['title']
    ) ?>"
    required
>
</div>

<div class="form-group full">
<label>アンケート説明</label>
<textarea
    name="description"
    maxlength="5000"
><?= h($currentSurvey['description']) ?></textarea>
</div>

<div class="form-group">
<label>開始日時</label>
<input
    name="startAt"
    type="datetime-local"
    value="<?= h(
        $currentSurvey['startAt']
    ) ?>"
>
</div>

<div class="form-group">
<label>終了日時</label>
<input
    name="endAt"
    type="datetime-local"
    value="<?= h(
        $currentSurvey['endAt']
    ) ?>"
>
</div>

<div class="form-group">
<label>質問番号の採番方式</label>
<select name="numbering">
<option
    value="global"
    <?= $currentSurvey['numbering']
        === 'global'
        ? 'selected' : '' ?>
>
アンケート全体で通番：Q1、Q2、Q3...
</option>

<option
    value="group"
    <?= $currentSurvey['numbering']
        === 'group'
        ? 'selected' : '' ?>
>
グループ毎：Q1-1、Q1-2、Q2-1...
</option>
</select>
</div>

<div class="form-group">
<label>状態</label>

<div>
<span class="status
<?= h(status_class(
    (string)$currentSurvey['status']
)) ?>">
<?= h(status_label(
    (string)$currentSurvey['status']
)) ?>
</span>
</div>

<?php
$currentStatus =
    (string)$currentSurvey['status'];
?>

<div class="actions" style="margin-top:8px">

<?php if ($currentStatus === 'draft'): ?>

<button
    class="btn btn-success"
    type="submit"
    formaction="index.php?screen=edit"
    name="action"
    value="change_status"
    onclick="return confirm('公開しますか？')"
>
公開
</button>

<?php elseif ($currentStatus === 'published'): ?>

<button
    class="btn btn-warning"
    type="submit"
    formaction="index.php?screen=edit"
    name="action"
    value="change_status"
    onclick="return confirm('停止しますか？')"
>
停止
</button>

<?php elseif ($currentStatus === 'stopped'): ?>

<button
    class="btn btn-success"
    type="submit"
    formaction="index.php?screen=edit"
    name="action"
    value="change_status"
    onclick="return confirm('再開しますか？')"
>
再開
</button>

<?php endif; ?>

</div>
</div>

</div>

<?php
/*
 * グループ
 */
?>

<div style="margin-top:28px">

<h2 class="section-title">
質問・グループ
</h2>

<div id="groups">

<?php foreach (
    $currentSurvey['groups'] as $groupIndex => $group
):
?>

<div
    class="group"
    draggable="true"
    data-group-id="<?= h($group['id']) ?>"
>

<div class="group-head">

<span
    class="drag-handle"
    title="ドラッグして並び替え"
>
☰
</span>

<input
    type="text"
    name="groups[<?= $groupIndex ?>][title]"
    value="<?= h($group['title']) ?>"
    maxlength="200"
>

<input
    type="hidden"
    name="groups[<?= $groupIndex ?>][id]"
    value="<?= h($group['id']) ?>"
>

</div>

<div class="group-body">

<?php foreach (
    $group['questions']
    as $questionIndex => $question
):
?>

<div
    class="question"
    draggable="true"
>

<div class="question-grid">

<div class="q-number">
<?= h($question['number']) ?>
</div>

<div>

<input
    type="hidden"
    name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][id]"
    value="<?= h($question['id']) ?>"
>

<input
    type="text"
    name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][text]"
    value="<?= h($question['text']) ?>"
    placeholder="質問文"
    maxlength="1000"
>

<div class="option-row">

<select
    name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][type]"
>
<option
    value="single"
    <?= $question['type'] === 'single'
        ? 'selected' : '' ?>
>
単一選択
</option>

<option
    value="multiple"
    <?= $question['type'] === 'multiple'
        ? 'selected' : '' ?>
>
複数選択
</option>

<option
    value="text"
    <?= $question['type'] === 'text'
        ? 'selected' : '' ?>
>
自由記述
</option>
</select>

<label
    style="display:flex;
           align-items:center;
           gap:5px;
           font-weight:400"
>
<input
    class="checkbox"
    type="checkbox"
    name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][required]"
    value="1"
    <?= !empty($question['required'])
        ? 'checked' : '' ?>
>
必須
</label>

</div>

<?php if (
    in_array(
        $question['type'],
        ['single', 'multiple'],
        true
    )
): ?>

<div style="margin-top:10px">

<?php foreach (
    $question['options'] ?? []
    as $optionIndex => $option
):
?>

<input
    type="text"
    name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][options][<?= $optionIndex ?>]"
    value="<?= h($option) ?>"
    placeholder="選択肢"
    style="margin-top:6px"
>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>

<div>
<button
    class="btn btn-danger"
    type="button"
    onclick="removeQuestion(this)"
>
削除
</button>
</div>

</div>

</div>

<?php endforeach; ?>

<!--
    質問追加はグループ末尾のみ
-->
<button
    class="btn btn-secondary"
    type="button"
    onclick="addQuestion(this)"
>
＋ 質問を追加
</button>

</div>
</div>

<?php endforeach; ?>

</div>

<!--
    グループ追加は一覧末尾のみ
-->
<div style="margin-top:15px">
<button
    class="btn btn-secondary"
    type="button"
    onclick="addGroup()"
>
＋ グループを追加
</button>
</div>

</div>

<div class="setting-actions">

<!--
    保存ボタンはこれ1つだけ。
-->
<button
    class="btn btn-primary"
    type="submit"
    name="action"
    value="save_survey"
>
保存して一覧へ
</button>

</div>

</form>

</div>

<?php
/*
 * ============================================================
 * プレビュー
 * ============================================================
 */

elseif ($screen === 'preview'):

if ($currentSurvey === null) {
    redirect_screen('list');
}

?>

<div class="page-head">
<h1 class="page-title">
プレビュー
</h1>

<a
    class="btn btn-secondary"
    href="index.php?screen=edit&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
>
編集へ戻る
</a>
</div>

<div class="card">

<h2>
<?= h($currentSurvey['title']) ?>
</h2>

<p>
<?= nl2br(h(
    $currentSurvey['description']
)) ?>
</p>

</div>

<?php foreach (
    $currentSurvey['groups']
    as $group
):
?>

<div class="card">

<h3>
<?= h($group['title']) ?>
</h3>

<?php foreach (
    $group['questions']
    as $question
):
?>

<div class="question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<?php if (!empty($question['required'])): ?>
<span class="status status-published">
必須
</span>
<?php endif; ?>

<?php if (
    $question['type'] === 'text'
):
?>

<textarea
    disabled
    placeholder="自由記述"
></textarea>

<?php elseif (
    $question['type'] === 'single'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
):
?>

<label class="answer-option">
<input
    type="radio"
    disabled
>
<?= h($option) ?>
</label>

<?php endforeach; ?>

<?php else: ?>

<?php foreach (
    $question['options'] ?? []
    as $option
):
?>

<label class="answer-option">
<input
    type="checkbox"
    disabled
>
<?= h($option) ?>
</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>


<?php
/*
 * ============================================================
 * 集計
 * ============================================================
 */

elseif ($screen === 'analytics'):

if ($currentSurvey === null) {
    redirect_screen('list');
}

$answers = array_values(
    array_filter(
        load_answers(),
        static fn(array $a): bool =>
            ($a['surveyId'] ?? '')
            === $currentSurvey['id']
    )
);

$answerCount = count($answers);

?>

<div class="page-head">
<h1 class="page-title">
回答集計・分析
</h1>

<a
    class="btn btn-secondary"
    href="index.php?screen=list"
>
一覧へ戻る
</a>
</div>

<div class="card">

<h2>
対象アンケート：
<?= h($currentSurvey['title']) ?>
</h2>

</div>

<div class="stat-grid">

<div class="stat">
<div class="stat-label">
送信対象者数
</div>
<div class="stat-value">
<?= count(load_customers()) ?>
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
未登録回答数
</div>
<div class="stat-value">
0
</div>
</div>

<div class="stat">
<div class="stat-label">
回答率
</div>
<div class="stat-value">
<?php
$totalCustomers =
    count(load_customers());

echo $totalCustomers > 0
    ? h(
        number_format(
            $answerCount /
            $totalCustomers *
            100,
            1
        )
    ) . '%'
    : '0%';
?>
</div>
</div>

</div>

<?php if ($answerCount === 0): ?>

<div class="card empty">
現在、回答データはありません
</div>

<?php else: ?>

<div class="card">

<h2 class="section-title">
個別回答
</h2>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>回答日時</th>
<th>回答者</th>
<th>回答</th>
</tr>
</thead>

<tbody>

<?php foreach ($answers as $answer): ?>

<tr>
<td>
<?= h($answer['createdAt'] ?? '') ?>
</td>

<td>
<?= h($answer['customerName'] ?? '') ?>
</td>

<td>
<?= h(
    json_encode(
        $answer['answers'] ?? [],
        JSON_UNESCAPED_UNICODE
    )
) ?>
</td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<?php endif; ?>


<?php
/*
 * ============================================================
 * 送信
 * ============================================================
 */

elseif ($screen === 'send'):

if ($currentSurvey === null) {
    redirect_screen('list');
}

$customers = load_customers();

?>

<div class="page-head">
<h1 class="page-title">
顧客選択・メール送信
</h1>

<a
    class="btn btn-secondary"
    href="index.php?screen=list"
>
一覧へ戻る
</a>
</div>

<div class="card">

<h2>
対象アンケート
</h2>

<p>
<strong>
<?= h($currentSurvey['title']) ?>
</strong>
</p>

<div class="help">
この画面では対象アンケートを変更できません。
</div>

</div>

<div class="card">

<h2 class="section-title">
顧客選択
</h2>

<div class="table-wrap">

<table>

<thead>
<tr>
<th></th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署名</th>
<th>電話番号</th>
</tr>
</thead>

<tbody>

<?php if (!$customers): ?>

<tr>
<td colspan="6">
<div class="empty">
顧客情報がありません。
kintone設定画面から同期してください。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($customers as $customer): ?>

<tr>

<td>
<input
    class="checkbox"
    type="checkbox"
    name="customer_ids[]"
    value="<?= h($customer['id']) ?>"
>
</td>

<td>
<?= h($customer['organization']) ?>
</td>

<td>
<?= h($customer['name']) ?>
</td>

<td>
<?= h($customer['email']) ?>
</td>

<td>
<?= h($customer['department']) ?>
</td>

<td>
<?= h($customer['phone']) ?>
</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

<div class="card">

<h2 class="section-title">
メール作成
</h2>

<div class="form-grid">

<div class="form-group full">
<label>件名</label>
<input
    type="text"
    value="<?= h(
        $currentSurvey['title']
    ) ?>"
>
</div>

<div class="form-group full">
<label>本文</label>
<textarea><?= h(
    "{顧客名} 様\n\n"
    . "アンケートへのご回答をお願いいたします。\n\n"
    . "{アンケートURL}"
) ?></textarea>
</div>

</div>

<div class="setting-actions">

<button
    class="btn btn-primary"
    type="button"
    onclick="confirm('選択した顧客へ一括送信しますか？')"
>
一括送信
</button>

<button
    class="btn btn-secondary"
    type="button"
    onclick="confirm('選択した顧客へ再送しますか？')"
>
再送
</button>

<button
    class="btn btn-secondary"
    type="button"
    onclick="confirm('選択した顧客へリマインドしますか？')"
>
リマインド
</button>

</div>

</div>

<div class="card">

<h2 class="section-title">
送信履歴
</h2>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>結果</th>
</tr>
</thead>

<tbody>

<?php
$history = array_values(
    array_filter(
        load_send_history(),
        static fn(array $row): bool =>
            ($row['surveyId'] ?? '')
            === $currentSurvey['id']
    )
);
?>

<?php if (!$history): ?>

<tr>
<td colspan="3">
<div class="empty">
送信履歴はありません。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($history as $row): ?>

<tr>
<td>
<?= h($row['createdAt'] ?? '') ?>
</td>

<td>
<?= h($row['customerName'] ?? '') ?>
</td>

<td>
<?= h($row['status'] ?? '') ?>
</td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

</div>


<?php
/*
 * ============================================================
 * 回答
 * ============================================================
 */

elseif ($screen === 'answer'):

if ($currentSurvey === null) {
    http_response_code(404);
    exit('アンケートが存在しません。');
}

if (
    $currentSurvey['status'] !== 'published'
) {
    http_response_code(404);
    exit('現在回答できるアンケートではありません。');
}

?>

<div class="answer-page">

<div class="card">

<h1 class="page-title">
<?= h($currentSurvey['title']) ?>
</h1>

<p>
<?= nl2br(h(
    $currentSurvey['description']
)) ?>
</p>

</div>

<form
    method="post"
    action="index.php?screen=confirm&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
>

<?php foreach (
    $currentSurvey['groups']
    as $group
):
?>

<div class="card answer-card">

<h2>
<?= h($group['title']) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
):
?>

<div style="margin-top:22px">

<label>
<?= h($question['number']) ?>
<?= h($question['text']) ?>

<?php if (!empty($question['required'])): ?>

<span style="color:#dc2626">
*
</span>

<?php endif; ?>

</label>

<?php if (
    $question['type'] === 'text'
):
?>

<textarea
    name="answers[<?= h($question['id']) ?>]"
    <?= !empty($question['required'])
        ? 'required'
        : '' ?>
></textarea>

<?php elseif (
    $question['type'] === 'single'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
):
?>

<label class="answer-option">
<input
    type="radio"
    name="answers[<?= h($question['id']) ?>]"
    value="<?= h($option) ?>"
    <?= !empty($question['required'])
        ? 'required'
        : '' ?>
>
<?= h($option) ?>
</label>

<?php endforeach; ?>

<?php else: ?>

<?php foreach (
    $question['options'] ?? []
    as $option
):
?>

<label class="answer-option">
<input
    type="checkbox"
    name="answers[<?= h($question['id']) ?>][]"
    value="<?= h($option) ?>"
>
<?= h($option) ?>
</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="actions">
<button
    class="btn btn-primary"
    type="submit"
>
回答確認へ
</button>
</div>

</form>

</div>


<?php
/*
 * ============================================================
 * 回答確認
 * ============================================================
 */

elseif ($screen === 'confirm'):

if ($currentSurvey === null) {
    http_response_code(404);
    exit('アンケートが存在しません。');
}

$answerInput =
    is_array($_POST['answers'] ?? null)
        ? $_POST['answers']
        : [];

$_SESSION['answer_draft'] = [
    'surveyId' => $currentSurvey['id'],
    'answers' => $answerInput,
];

?>

<div class="answer-page">

<div class="card">

<h1 class="page-title">
回答確認
</h1>

<p>
以下の内容で送信します。
</p>

<?php foreach (
    $currentSurvey['groups']
    as $group
):
?>

<h2>
<?= h($group['title']) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
):
?>

<div class="question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<div style="margin-top:8px">

<?php
$value =
    $answerInput[$question['id']]
    ?? '';

if (is_array($value)) {
    echo h(implode(', ', $value));
} else {
    echo nl2br(h((string)$value));
}
?>

</div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="actions">

<a
    class="btn btn-secondary"
    href="index.php?screen=answer&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
>
修正する
</a>

<form
    method="post"
    action="index.php?screen=complete&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
    style="display:inline"
>
<input
    type="hidden"
    name="submit_answer"
    value="1"
>
<button
    class="btn btn-primary"
    type="submit"
    onclick="return confirm('回答を送信しますか？')"
>
回答を送信
</button>
</form>

</div>

</div>

</div>


<?php
/*
 * ============================================================
 * 回答完了
 * ============================================================
 */

elseif ($screen === 'complete'):

if ($currentSurvey === null) {
    http_response_code(404);
    exit('アンケートが存在しません。');
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['submit_answer'])
) {
    $draft =
        $_SESSION['answer_draft'] ?? null;

    if (
        !is_array($draft)
        || ($draft['surveyId'] ?? '')
            !== $currentSurvey['id']
    ) {
        http_response_code(400);
        exit('回答セッションを利用できません。');
    }

    $answers = load_answers();

    $answers[] = [
        'id' =>
            'answer-' .
            bin2hex(random_bytes(8)),
        'surveyId' =>
            $currentSurvey['id'],
        'answers' =>
            $draft['answers'] ?? [],
        'customerName' => '',
        'createdAt' => now_iso(),
    ];

    if (!save_answers($answers)) {
        http_response_code(500);
        exit('回答を保存できませんでした。');
    }

    unset($_SESSION['answer_draft']);

    $surveys = load_surveys();

    foreach ($surveys as &$survey) {
        if (
            ($survey['id'] ?? '')
            === $currentSurvey['id']
        ) {
            $survey['answerCount'] =
                (int)(
                    $survey['answerCount'] ?? 0
                ) + 1;

            $survey['updatedAt'] =
                now_iso();

            break;
        }
    }

    unset($survey);

    save_surveys($surveys);
}

?>

<div class="answer-page">

<div class="card">

<h1 class="page-title">
回答完了
</h1>

<div class="empty">
アンケートへの回答ありがとうございました。
</div>

</div>

</div>


<?php
/*
 * ============================================================
 * 未知のscreen
 * ============================================================
 */

else:

redirect_screen('list');

endif;
?>

</main>


<script>
/*
 * ============================================================
 * kintone接続テスト
 *
 * submit前にボタンをロックするだけ。
 * CSRF処理は行わない。
 * ============================================================
 */

(function(){

    const button =
        document.getElementById(
            'testKintoneButton'
        );

    if (!button) {
        return;
    }

    button.addEventListener(
        'click',
        function(){

            const form =
                document.getElementById(
                    'kintoneForm'
                );

            if (!form) {
                return;
            }

            /*
             * 接続テスト中は他操作を受け付けない。
             */
            const controls =
                form.querySelectorAll(
                    'button,input,select,textarea'
                );

            controls.forEach(function(el){
                el.disabled = true;
            });

            button.innerHTML =
                '<span class="spinner"></span> 接続テスト中...';

        }
    );

})();


/*
 * ============================================================
 * 質問操作
 * ============================================================
 */

function removeQuestion(button)
{
    if (
        !confirm(
            'この質問を削除しますか？'
        )
    ) {
        return;
    }

    const question =
        button.closest('.question');

    if (question) {
        question.remove();
        recalcClientNumbers();
    }
}

function addQuestion(button)
{
    const group =
        button.closest('.group');

    if (!group) {
        return;
    }

    const body =
        group.querySelector('.group-body');

    const questions =
        body.querySelectorAll('.question');

    const index =
        questions.length;

    const html = `
        <div class="question" draggable="true">
            <div class="question-grid">

                <div class="q-number">
                    Q?
                </div>

                <div>

                    <input
                        type="hidden"
                        name="groups[0][questions][${index}][id]"
                        value="q-${Date.now()}"
                    >

                    <input
                        type="text"
                        name="groups[0][questions][${index}][text]"
                        placeholder="質問文"
                        maxlength="1000"
                    >

                    <div
                        class="option-row"
                    >

                        <select
                            name="groups[0][questions][${index}][type]"
                        >
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

                        <label
                            style="
                                display:flex;
                                align-items:center;
                                gap:5px;
                                font-weight:400
                            "
                        >
                            <input
                                class="checkbox"
                                type="checkbox"
                                name="groups[0][questions][${index}][required]"
                                value="1"
                                checked
                            >
                            必須
                        </label>

                    </div>

                </div>

                <div>
                    <button
                        class="btn btn-danger"
                        type="button"
                        onclick="removeQuestion(this)"
                    >
                        削除
                    </button>
                </div>

            </div>
        </div>
    `;

    button.insertAdjacentHTML(
        'beforebegin',
        html
    );

    recalcClientNumbers();
}

function addGroup()
{
    const groups =
        document.getElementById(
            'groups'
        );

    if (!groups) {
        return;
    }

    const index =
        groups.querySelectorAll(
            '.group'
        ).length;

    const html = `
        <div
            class="group"
            draggable="true"
        >

            <div class="group-head">

                <span
                    class="drag-handle"
                >
                    ☰
                </span>

                <input
                    type="text"
                    name="groups[${index}][title]"
                    value="新しいグループ"
                    maxlength="200"
                >

                <input
                    type="hidden"
                    name="groups[${index}][id]"
                    value="g-${Date.now()}"
                >

            </div>

            <div class="group-body">

                <div
                    class="question"
                    draggable="true"
                >

                    <div class="question-grid">

                        <div class="q-number">
                            Q?
                        </div>

                        <div>

                            <input
                                type="hidden"
                                name="groups[${index}][questions][0][id]"
                                value="q-${Date.now()}"
                            >

                            <input
                                type="text"
                                name="groups[${index}][questions][0][text]"
                                placeholder="質問文"
                            >

                            <div
                                class="option-row"
                            >

                                <select
                                    name="groups[${index}][questions][0][type]"
                                >
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

                                <label
                                    style="
                                        display:flex;
                                        align-items:center;
                                        gap:5px;
                                        font-weight:400
                                    "
                                >
                                    <input
                                        class="checkbox"
                                        type="checkbox"
                                        name="groups[${index}][questions][0][required]"
                                        value="1"
                                        checked
                                    >
                                    必須
                                </label>

                            </div>

                        </div>

                        <div>
                            <button
                                class="btn btn-danger"
                                type="button"
                                onclick="removeQuestion(this)"
                            >
                                削除
                            </button>
                        </div>

                    </div>

                </div>

                <button
                    class="btn btn-secondary"
                    type="button"
                    onclick="addQuestion(this)"
                >
                    ＋ 質問を追加
                </button>

            </div>

        </div>
    `;

    groups.insertAdjacentHTML(
        'beforeend',
        html
    );

    recalcClientNumbers();
}


/*
 * ============================================================
 * クライアント側質問番号再計算
 *
 * 最終的な保存値はPHP側でも必ず再計算する。
 * ============================================================
 */

function recalcClientNumbers()
{
    const groups =
        document.querySelectorAll(
            '#groups .group'
        );

    let global = 1;

    groups.forEach(
        function(group, groupIndex){

            const questions =
                group.querySelectorAll(
                    '.question'
                );

            questions.forEach(
                function(question, questionIndex){

                    const number =
                        question.querySelector(
                            '.q-number'
                        );

                    if (!number) {
                        return;
                    }

                    const numbering =
                        document.querySelector(
                            '[name="numbering"]'
                        );

                    if (
                        numbering
                        && numbering.value
                            === 'group'
                    ) {
                        number.textContent =
                            'Q'
                            + (groupIndex + 1)
                            + '-'
                            + (questionIndex + 1);
                    } else {
                        number.textContent =
                            'Q'
                            + global;
                    }

                    global++;
                }
            );
        }
    );
}

document.addEventListener(
    'change',
    function(event){

        if (
            event.target
            && event.target.name
                === 'numbering'
        ) {
            recalcClientNumbers();
        }

    }
);


/*
 * ============================================================
 * HTML5 Drag and Drop
 * ============================================================
 */

(function(){

    let dragging = null;

    document.addEventListener(
        'dragstart',
        function(event){

            const group =
                event.target.closest(
                    '.group'
                );

            const question =
                event.target.closest(
                    '.question'
                );

            dragging =
                group || question || null;

            if (dragging) {
                dragging.style.opacity = '.5';
            }
        }
    );

    document.addEventListener(
        'dragend',
        function(){

            if (dragging) {
                dragging.style.opacity = '';
            }

            dragging = null;

            recalcClientNumbers();
        }
    );

    document.addEventListener(
        'dragover',
        function(event){

            if (!dragging) {
                return;
            }

            const target =
                event.target.closest(
                    '.group,.question'
                );

            if (!target || target === dragging) {
                return;
            }

            event.preventDefault();

            if (
                dragging.classList.contains(
                    'group'
                )
                && target.classList.contains(
                    'group'
                )
            ) {
                const groups =
                    document.getElementById(
                        'groups'
                    );

                if (
                    groups
                    && dragging.parentNode
                        === groups
                ) {
                    const rect =
                        target.getBoundingClientRect();

                    if (
                        event.clientY
                        < rect.top
                        + rect.height / 2
                    ) {
                        groups.insertBefore(
                            dragging,
                            target
                        );
                    } else {
                        groups.insertBefore(
                            dragging,
                            target.nextSibling
                        );
                    }

                    recalcClientNumbers();
                }
            }

        }
    );

})();


/*
 * ============================================================
 * Enter検索
 * ============================================================
 */

(function(){

    const search =
        document.querySelector(
            '#listSearch input[name="q"]'
        );

    if (!search) {
        return;
    }

    search.addEventListener(
        'keydown',
        function(event){

            if (event.key === 'Enter') {
                event.preventDefault();
                search.form.submit();
            }

        }
    );

})();

</script>

</body>
</html>
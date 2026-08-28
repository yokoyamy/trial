<?php
declare(strict_types=1);

/*
 * ============================================================
 * アンケートアプリ
 * 単一ファイル版 index.php
 *
 * PHP 8.5 / Apache 2.4 / DBなし / PHP cURLなし
 *
 * 外部通信:
 *   kintone : PHP stream wrapper
 *   SMTP    : PHP stream_socket_client()
 *
 * データ:
 *   このPHPファイルと同じ公開ディレクトリ配下の
 *   .data ディレクトリへJSONとして保存
 *
 * 重要:
 *   kintoneの認証情報・X-Cybozu-Authorizationは
 *   ブラウザへ出力しない。
 * ============================================================
 */

/* ------------------------------------------------------------
 * 基本設定
 * ------------------------------------------------------------ */

const APP_NAME = 'アンケート管理';
const DATA_DIR_NAME = '.data';
const DATA_FILE_NAME = 'data.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT    = 20;
const SMTP_CONNECT_TIMEOUT    = 10;

/* ------------------------------------------------------------
 * セッション
 * ------------------------------------------------------------ */

$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
);

$cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($cookiePath === '') {
    $cookiePath = '/';
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $cookiePath,
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ------------------------------------------------------------
 * 共通関数
 * ------------------------------------------------------------ */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_encode_safe(mixed $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '{}';
}

function now_string(): string
{
    return date('Y-m-d H:i:s');
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function current_script(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
}

function screen_url(string $screen, array $params = []): string
{
    $params = array_merge(['screen' => $screen], $params);
    return current_script() . '?' . http_build_query($params);
}

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    $flash = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return is_array($flash) ? $flash : null;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function verify_csrf(): void
{
    $given = (string)($_POST['_csrf'] ?? '');
    $actual = (string)($_SESSION['_csrf'] ?? '');

    if ($actual === '' || !hash_equals($actual, $given)) {
        http_response_code(400);
        exit('不正なリクエストです。ページを再読み込みしてください。');
    }
}

function data_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . DATA_DIR_NAME;
}

function data_file(): string
{
    return data_dir() . DIRECTORY_SEPARATOR . DATA_FILE_NAME;
}

function ensure_data_dir(): void
{
    $dir = data_dir();

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('データ保存ディレクトリを作成できません。');
        }
    }

    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';

    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Deny from all\n",
            LOCK_EX
        );
    }
}

function default_data(): array
{
    return [
        'surveys' => [],
        'customers' => [],
        'answers' => [],
        'send_history' => [],
        'settings' => [
            'kintone' => [
                'subdomain' => '',
                'app_id' => '',
                'login_name' => '',
                'password' => '',
                'proxy' => '',
                'verify_ssl' => false,
            ],
            'mail' => [
                'smtp_host' => '',
                'smtp_port' => '587',
                'encryption' => 'tls',
                'auth' => true,
                'username' => '',
                'password' => '',
                'from_email' => '',
                'from_name' => '',
                'reply_to' => '',
            ],
        ],
    ];
}

function load_data(): array
{
    ensure_data_dir();

    $file = data_file();

    if (!file_exists($file)) {
        $data = default_data();
        save_data($data);
        return $data;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return default_data();
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return default_data();
    }

    $defaults = default_data();

    $data['surveys'] = is_array($data['surveys'] ?? null)
        ? $data['surveys']
        : [];

    $data['customers'] = is_array($data['customers'] ?? null)
        ? $data['customers']
        : [];

    $data['answers'] = is_array($data['answers'] ?? null)
        ? $data['answers']
        : [];

    $data['send_history'] = is_array($data['send_history'] ?? null)
        ? $data['send_history']
        : [];

    $data['settings'] = array_replace_recursive(
        $defaults['settings'],
        is_array($data['settings'] ?? null) ? $data['settings'] : []
    );

    return $data;
}

function save_data(array $data): void
{
    ensure_data_dir();

    $file = data_file();
    $tmp  = $file . '.tmp.' . bin2hex(random_bytes(5));

    $json = json_encode_safe($data);

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('データを一時ファイルへ保存できません。');
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データファイルを更新できません。');
    }
}

function find_survey(array $data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_index(array $data, string $id): int
{
    foreach ($data['surveys'] as $index => $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function survey_status_label(string $status): string
{
    return match ($status) {
        'draft'     => '下書き',
        'published' => '公開中',
        'stopped'   => '停止',
        'ended'     => '終了',
        default     => '不明',
    };
}

function survey_status_class(string $status): string
{
    return match ($status) {
        'draft'     => 'badge-draft',
        'published' => 'badge-published',
        'stopped'   => 'badge-stopped',
        'ended'     => 'badge-ended',
        default     => 'badge-gray',
    };
}

function normalize_subdomain(string $value): string
{
    $value = trim($value);
    $value = preg_replace('#^https?://#i', '', $value) ?? $value;
    $value = trim($value, "/ \t\n\r\0\x0B");

    if (str_contains($value, '.cybozu.com')) {
        $value = preg_replace('/\.cybozu\.com.*$/i', '', $value) ?? $value;
    }

    return trim($value);
}

function kintone_base_url(string $subdomain): string
{
    $subdomain = normalize_subdomain($subdomain);

    return 'https://' . $subdomain . '.cybozu.com';
}

function validate_kintone_config(array $config): array
{
    $errors = [];

    $subdomain = normalize_subdomain((string)($config['subdomain'] ?? ''));
    $appId     = trim((string)($config['app_id'] ?? ''));
    $login     = trim((string)($config['login_name'] ?? ''));
    $password  = (string)($config['password'] ?? '');
    $proxy     = trim((string)($config['proxy'] ?? ''));

    if ($subdomain === '') {
        $errors[] = 'サブドメインを入力してください。例：xxxx または xxxx.cybozu.com';
    } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $subdomain)) {
        $errors[] = 'サブドメインの形式が不正です。';
    }

    if ($appId === '') {
        $errors[] = '顧客管理アプリIDを入力してください。';
    } elseif (!ctype_digit($appId) || (int)$appId <= 0) {
        $errors[] = '顧客管理アプリIDは1以上の数字で入力してください。';
    }

    if ($login === '') {
        $errors[] = 'kintoneログイン名を入力してください。';
    }

    if ($password === '') {
        $errors[] = 'kintoneパスワードを入力してください。';
    }

    if ($proxy !== '') {
        /*
         * Proxyは host:port の1項目。
         * ここでURL全体を要求しない。
         */
        if (!preg_match(
            '/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\]):([0-9]{1,5})$/',
            $proxy,
            $matches
        )) {
            $errors[] = 'Proxyは「host:port」形式で入力してください。例：proxy.example.local:8080';
        } else {
            $port = (int)$matches[1];

            if ($port < 1 || $port > 65535) {
                $errors[] = 'Proxyのポート番号は1～65535で指定してください。';
            }
        }
    }

    return $errors;
}

/* ------------------------------------------------------------
 * HTTP / kintone通信
 * ------------------------------------------------------------ */

/**
 * PHP cURLを使用せずHTTP通信する。
 *
 * 戻り値:
 * [
 *   ok,
 *   http_code,
 *   headers,
 *   body,
 *   elapsed_ms,
 *   error,
 *   error_category
 * ]
 */
function http_request_stream(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    array $options = []
): array {
    $started = microtime(true);

    $connectTimeout = (int)($options['connect_timeout'] ?? 10);
    $readTimeout    = (int)($options['read_timeout'] ?? 20);
    $verifySsl      = (bool)($options['verify_ssl'] ?? true);
    $proxy          = trim((string)($options['proxy'] ?? ''));

    $headerLines = [];

    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $httpOptions = [
        'method'          => strtoupper($method),
        'header'          => implode("\r\n", $headerLines),
        'ignore_errors'   => true,
        'timeout'         => max($connectTimeout, $readTimeout),
        'protocol_version'=> 1.1,
        'follow_location' => 0,
    ];

    if ($body !== null) {
        $httpOptions['content'] = $body;
    }

    if ($proxy !== '') {
        $httpOptions['proxy'] = 'tcp://' . $proxy;
        $httpOptions['request_fulluri'] = true;
    }

    $contextOptions = [
        'http' => $httpOptions,
    ];

    /*
     * SSL検証設定。
     * prompt.txtではPOC段階で無効を基本とするが、
     * UIから有効化できるようにする。
     */
    if (!$verifySsl) {
        $contextOptions['ssl'] = [
            'verify_peer'      => false,
            'verify_peer_name' => false,
            'allow_self_signed'=> true,
        ];
    } else {
        $contextOptions['ssl'] = [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ];
    }

    $context = stream_context_create($contextOptions);

    $error = null;
    $headersBefore = $http_response_header ?? [];

    set_error_handler(
        function (int $severity, string $message) use (&$error): bool {
            $error = $message;
            return true;
        }
    );

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    restore_error_handler();

    $elapsed = (int)round((microtime(true) - $started) * 1000);

    /*
     * file_get_contents()失敗時でもHTTPエラーなら
     * ignore_errors=trueにより応答本文を取得できる。
     */
    $responseHeaders = $http_response_header ?? $headersBefore;

    $httpCode = 0;

    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
            $httpCode = (int)$m[1];
            break;
        }
    }

    $ok = (
        $response !== false
        && $httpCode >= 200
        && $httpCode < 300
    );

    $category = '';

    if ($error !== null) {
        $lower = strtolower($error);

        if (
            str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
        ) {
            $category = 'タイムアウト';
        } elseif (
            str_contains($lower, 'failed to open stream')
            || str_contains($lower, 'unable to connect')
            || str_contains($lower, 'connection')
        ) {
            $category = '通信エラー';
        } else {
            $category = 'システムエラー';
        }
    } elseif ($httpCode === 401 || $httpCode === 403) {
        $category = '認証エラー';
    } elseif ($httpCode === 400) {
        $category = 'リクエストエラー';
    } elseif ($httpCode >= 500) {
        $category = 'kintoneサーバーエラー';
    } elseif ($httpCode >= 300 && $httpCode < 400) {
        $category = 'リダイレクト';
    }

    return [
        'ok'             => $ok,
        'http_code'      => $httpCode,
        'headers'        => $responseHeaders,
        'body'           => $response === false ? '' : (string)$response,
        'elapsed_ms'     => $elapsed,
        'error'          => $error,
        'error_category' => $category,
    ];
}

function kintone_authorization(string $login, string $password): string
{
    return base64_encode($login . ':' . $password);
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $payload = null
): array {
    $base = kintone_base_url((string)$config['subdomain']);
    $url  = $base . '/k/v1/' . ltrim($path, '/');

    $headers = [
        'X-Cybozu-Authorization' =>
            kintone_authorization(
                (string)$config['login_name'],
                (string)$config['password']
            ),
        'Accept' => 'application/json',
    ];

    $body = null;

    if ($payload !== null) {
        $body = json_encode_safe($payload);

        $headers['Content-Type'] = 'application/json';
    }

    return http_request_stream(
        $method,
        $url,
        $headers,
        $body,
        [
            'connect_timeout' => KINTONE_CONNECT_TIMEOUT,
            'read_timeout'    => KINTONE_READ_TIMEOUT,
            'verify_ssl'      => (bool)($config['verify_ssl'] ?? false),
            'proxy'           => trim((string)($config['proxy'] ?? '')),
        ]
    );
}

function kintone_error_message(array $result): string
{
    $httpCode = (int)($result['http_code'] ?? 0);
    $body     = (string)($result['body'] ?? '');

    $decoded = json_decode($body, true);

    if (is_array($decoded)) {
        $message = trim((string)($decoded['message'] ?? ''));

        if ($message !== '') {
            return $message;
        }

        $code = trim((string)($decoded['code'] ?? ''));

        if ($code !== '') {
            return 'kintone APIエラーコード: ' . $code;
        }
    }

    if ($httpCode === 400) {
        return 'kintoneがリクエストを不正と判断しました。アプリID、API URL、認証情報を確認してください。';
    }

    if ($httpCode === 401) {
        return '認証に失敗しました。ログイン名またはパスワードを確認してください。';
    }

    if ($httpCode === 403) {
        return 'kintoneへのアクセスが拒否されました。権限を確認してください。';
    }

    if ($httpCode === 404) {
        return 'kintoneのURLまたはアプリIDが見つかりません。';
    }

    if ($httpCode >= 500) {
        return 'kintone側でサーバーエラーが発生しました。';
    }

    if (!empty($result['error'])) {
        return (string)$result['error'];
    }

    return '原因を特定できない通信エラーが発生しました。';
}

function kintone_sanitize_api_body(string $body): string
{
    $decoded = json_decode($body, true);

    if (is_array($decoded)) {
        /*
         * 顧客データそのものを接続テスト結果として表示しない。
         * エラー情報だけ表示対象とする。
         */
        $safe = [];

        foreach (['code', 'id', 'message'] as $key) {
            if (array_key_exists($key, $decoded)) {
                $safe[$key] = $decoded[$key];
            }
        }

        return json_encode_safe($safe);
    }

    return mb_substr(trim($body), 0, 500);
}

function perform_kintone_connection_test(array $config): array
{
    $steps = [];

    $started = microtime(true);

    $steps[] = [
        'status' => 'success',
        'title'  => '入力値を確認',
        'detail' => 'サブドメイン、アプリID、ログイン名、パスワード、Proxy設定を確認しました。',
    ];

    $base = kintone_base_url((string)$config['subdomain']);

    $steps[] = [
        'status' => 'success',
        'title'  => '接続先を決定',
        'detail' => $base . '/k/v1/app.json?app=' .
            rawurlencode((string)$config['app_id']),
    ];

    if (trim((string)($config['proxy'] ?? '')) !== '') {
        $steps[] = [
            'status' => 'info',
            'title'  => 'Proxyを使用',
            'detail' => 'Proxy設定を使用して外部通信を行います。',
        ];
    } else {
        $steps[] = [
            'status' => 'info',
            'title'  => 'Proxyを使用しない',
            'detail' => '直接kintoneへ接続します。',
        ];
    }

    $steps[] = [
        'status' => 'info',
        'title'  => 'kintoneへ接続中',
        'detail' => 'PHP標準のHTTP Stream機能で実際のkintoneへ接続しています。',
    ];

    $result = kintone_request(
        $config,
        'GET',
        'app.json?app=' . rawurlencode((string)$config['app_id'])
    );

    if ($result['ok']) {
        $decoded = json_decode((string)$result['body'], true);

        $appName = '';

        if (is_array($decoded)) {
            $appName = trim((string)($decoded['name'] ?? ''));
        }

        $steps[] = [
            'status' => 'success',
            'title'  => 'HTTP通信成功',
            'detail' => sprintf(
                'HTTP %d / 応答時間 %dms',
                (int)$result['http_code'],
                (int)$result['elapsed_ms']
            ),
        ];

        $steps[] = [
            'status' => 'success',
            'title'  => 'kintone API応答を確認',
            'detail' => $appName !== ''
                ? 'アプリ名「' . $appName . '」を確認しました。'
                : 'kintoneから正常なJSON応答を取得しました。',
        ];

        $steps[] = [
            'status' => 'success',
            'title'  => '接続テスト完了',
            'detail' => 'kintoneとの接続および指定アプリへのアクセスを確認できました。',
        ];

        return [
            'ok' => true,
            'steps' => $steps,
            'http_code' => $result['http_code'],
            'elapsed_ms' => $result['elapsed_ms'],
            'message' => 'kintone接続成功',
            'detail' => $appName !== ''
                ? '指定したアプリ「' . $appName . '」へアクセスできました。'
                : '指定したkintoneアプリへアクセスできました。',
            'api_body' => '',
        ];
    }

    $errorMessage = kintone_error_message($result);

    $steps[] = [
        'status' => 'error',
        'title'  => 'HTTP通信失敗',
        'detail' => $result['http_code'] > 0
            ? 'HTTP ' . $result['http_code'] .
              ' / 応答時間 ' . $result['elapsed_ms'] . 'ms'
            : (
                $result['error_category'] !== ''
                ? $result['error_category']
                : 'HTTPステータスを取得できませんでした。'
            ),
    ];

    $steps[] = [
        'status' => 'error',
        'title'  => 'kintoneからの結果',
        'detail' => $errorMessage,
    ];

    $recommendation = match ((int)$result['http_code']) {
        400 => 'アプリIDが正しいか、サブドメインが正しいか、ログイン名・パスワードが正しいか確認してください。',
        401 => 'ログイン名・パスワードを確認してください。',
        403 => 'kintoneユーザーのアプリ閲覧権限を確認してください。',
        404 => 'サブドメインとアプリIDを確認してください。',
        407 => 'Proxy認証が必要な環境の場合は、Proxy設定を確認してください。',
        default => (
            $result['error_category'] === 'タイムアウト'
            ? 'Proxy、ファイアウォール、DNS、インターネット接続を確認してください。'
            : 'サーバー、Proxy、SSL、ネットワーク設定を確認してください。'
        ),
    };

    $steps[] = [
        'status' => 'warning',
        'title'  => '次に確認すること',
        'detail' => $recommendation,
    ];

    return [
        'ok' => false,
        'steps' => $steps,
        'http_code' => $result['http_code'],
        'elapsed_ms' => $result['elapsed_ms'],
        'message' => 'kintone接続テスト失敗',
        'detail' => $errorMessage,
        'recommendation' => $recommendation,
        'api_body' => kintone_sanitize_api_body((string)$result['body']),
        'error_category' => $result['error_category'],
    ];
}

/* ------------------------------------------------------------
 * kintone顧客同期
 * ------------------------------------------------------------ */

function kintone_fetch_customers(array $config): array
{
    /*
     * REST APIから顧客アプリのレコードを取得。
     * フィールドコードは一般的な名称を優先し、
     * 存在しない場合でも空欄として扱う。
     */
    $result = kintone_request(
        $config,
        'GET',
        'records.json?app=' . rawurlencode((string)$config['app_id']) .
        '&totalCount=true'
    );

    if (!$result['ok']) {
        return [
            'ok' => false,
            'count' => 0,
            'customers' => [],
            'message' => kintone_error_message($result),
            'http_code' => $result['http_code'],
        ];
    }

    $json = json_decode((string)$result['body'], true);

    if (!is_array($json)) {
        return [
            'ok' => false,
            'count' => 0,
            'customers' => [],
            'message' => 'kintoneから正しいJSON応答を取得できませんでした。',
            'http_code' => $result['http_code'],
        ];
    }

    $records = is_array($json['records'] ?? null)
        ? $json['records']
        : [];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $get = static function (array $names) use ($record): string {
            foreach ($names as $name) {
                if (
                    isset($record[$name])
                    && is_array($record[$name])
                    && array_key_exists('value', $record[$name])
                ) {
                    return trim((string)$record[$name]['value']);
                }
            }

            return '';
        };

        $customers[] = [
            'id' => bin2hex(random_bytes(8)),
            'kintone_record_id' => $get(['$id', 'レコード番号']),
            'organization' => $get(['組織名', 'organization', 'company', '会社名']),
            'name' => $get(['氏名', 'name', '名前']),
            'email' => $get(['メールアドレス', 'email', 'Email']),
            'department' => $get(['部署名', 'department', '部署']),
            'phone' => $get(['電話番号', 'phone', 'TEL']),
            'address' => $get(['住所', 'address']),
            'synced_at' => now_string(),
        ];
    }

    return [
        'ok' => true,
        'count' => count($customers),
        'customers' => $customers,
        'message' => count($customers) . '件の顧客情報を取得しました。',
        'http_code' => $result['http_code'],
    ];
}

/* ------------------------------------------------------------
 * アンケート番号
 * ------------------------------------------------------------ */

function renumber_questions(array &$survey): void
{
    $mode = (string)($survey['numbering'] ?? 'global');

    $global = 1;

    foreach ($survey['groups'] as $groupIndex => &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if ($mode === 'group') {
                $question['number'] =
                    'Q' . ($groupIndex + 1) . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);
    }

    unset($group);
}

function new_question(): array
{
    return [
        'id' => 'question-' . bin2hex(random_bytes(6)),
        'number' => '',
        'text' => '新しい質問',
        'type' => 'single',
        'required' => true,
        'options' => ['はい', 'いいえ'],
        'branches' => [],
    ];
}

function new_group(): array
{
    return [
        'id' => 'group-' . bin2hex(random_bytes(6)),
        'title' => '新しいグループ',
        'questions' => [new_question()],
    ];
}

function new_survey(): array
{
    $survey = [
        'id' => 'survey-' . bin2hex(random_bytes(6)),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'createdAt' => now_string(),
        'updatedAt' => now_string(),
        'groups' => [new_group()],
    ];

    renumber_questions($survey);

    return $survey;
}

function auto_update_survey_status(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
            return true;
        }
    }

    return false;
}

/* ------------------------------------------------------------
 * POST処理
 * ------------------------------------------------------------ */

$data = load_data();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {

            /* -------------------------
             * kintone設定保存
             * ------------------------- */

            case 'save_kintone':
                $old = $data['settings']['kintone'];

                $new = [
                    'subdomain' => normalize_subdomain(
                        (string)($_POST['subdomain'] ?? '')
                    ),
                    'app_id' => trim((string)($_POST['app_id'] ?? '')),
                    'login_name' => trim((string)($_POST['login_name'] ?? '')),
                    'password' => (string)($_POST['password'] ?? ''),
                    'proxy' => trim((string)($_POST['proxy'] ?? '')),
                    'verify_ssl' => isset($_POST['verify_ssl']),
                ];

                /*
                 * パスワード欄を空にした場合は既存値を維持。
                 */
                if ($new['password'] === '') {
                    $new['password'] = (string)($old['password'] ?? '');
                }

                $errors = validate_kintone_config($new);

                if ($errors) {
                    flash_set('error', implode("\n", $errors));
                    redirect_to(screen_url('kintone'));
                }

                $data['settings']['kintone'] = $new;
                save_data($data);

                flash_set(
                    'success',
                    'kintone設定を保存しました。'
                );

                redirect_to(screen_url('kintone'));

            /* -------------------------
             * kintone接続テスト
             * ------------------------- */

            case 'test_kintone':
                $config = [
                    'subdomain' => normalize_subdomain(
                        (string)($_POST['subdomain'] ?? '')
                    ),
                    'app_id' => trim((string)($_POST['app_id'] ?? '')),
                    'login_name' => trim((string)($_POST['login_name'] ?? '')),
                    'password' => (string)($_POST['password'] ?? ''),
                    'proxy' => trim((string)($_POST['proxy'] ?? '')),
                    'verify_ssl' => isset($_POST['verify_ssl']),
                ];

                if ($config['password'] === '') {
                    $config['password'] =
                        (string)($data['settings']['kintone']['password'] ?? '');
                }

                $errors = validate_kintone_config($config);

                if ($errors) {
                    $_SESSION['_kintone_test'] = [
                        'ok' => false,
                        'message' => '入力エラー',
                        'detail' => implode("\n", $errors),
                        'steps' => array_map(
                            static fn(string $error): array => [
                                'status' => 'error',
                                'title' => '入力値を確認',
                                'detail' => $error,
                            ],
                            $errors
                        ),
                    ];

                    redirect_to(screen_url('kintone'));
                }

                /*
                 * 接続テストは保存操作とは独立。
                 */
                $_SESSION['_kintone_test'] =
                    perform_kintone_connection_test($config);

                redirect_to(screen_url('kintone'));

            /* -------------------------
             * kintone項目再取得
             * ------------------------- */

            case 'fetch_kintone_fields':
                $config = $data['settings']['kintone'];

                $errors = validate_kintone_config($config);

                if ($errors) {
                    flash_set('error', implode("\n", $errors));
                    redirect_to(screen_url('kintone'));
                }

                $result = kintone_request(
                    $config,
                    'GET',
                    'app/form/fields.json?app=' .
                    rawurlencode((string)$config['app_id'])
                );

                if (!$result['ok']) {
                    flash_set(
                        'error',
                        '項目一覧の取得に失敗しました。' .
                        "\n" . kintone_error_message($result)
                    );
                } else {
                    $json = json_decode((string)$result['body'], true);
                    $count = is_array($json['properties'] ?? null)
                        ? count($json['properties'])
                        : 0;

                    flash_set(
                        'success',
                        '項目一覧を再取得しました。' .
                        '取得項目数: ' . $count . '件'
                    );
                }

                redirect_to(screen_url('kintone'));

            /* -------------------------
             * kintone同期
             * ------------------------- */

            case 'sync_kintone':
                $config = $data['settings']['kintone'];

                $errors = validate_kintone_config($config);

                if ($errors) {
                    flash_set('error', implode("\n", $errors));
                    redirect_to(screen_url('kintone'));
                }

                $result = kintone_fetch_customers($config);

                if (!$result['ok']) {
                    flash_set(
                        'error',
                        '顧客情報の同期に失敗しました。' .
                        "\n" . $result['message']
                    );
                    redirect_to(screen_url('kintone'));
                }

                $data['customers'] = $result['customers'];
                save_data($data);

                flash_set(
                    'success',
                    '顧客情報の同期が完了しました。' .
                    "\n同期件数: " . $result['count'] . '件'
                );

                redirect_to(screen_url('kintone'));

            /* -------------------------
             * アンケート保存
             * ------------------------- */

            case 'save_survey':
                $id = trim((string)($_POST['id'] ?? ''));

                if ($id !== '') {
                    $index = survey_index($data, $id);

                    if ($index < 0) {
                        throw new RuntimeException(
                            '指定されたアンケートが見つかりません。'
                        );
                    }

                    $survey = $data['surveys'][$index];
                } else {
                    $survey = new_survey();
                }

                $survey['title'] =
                    trim((string)($_POST['title'] ?? ''));

                $survey['description'] =
                    trim((string)($_POST['description'] ?? ''));

                $survey['startAt'] =
                    trim((string)($_POST['startAt'] ?? ''));

                $survey['endAt'] =
                    trim((string)($_POST['endAt'] ?? ''));

                $numbering =
                    (string)($_POST['numbering'] ?? 'global');

                $survey['numbering'] =
                    in_array($numbering, ['global', 'group'], true)
                    ? $numbering
                    : 'global';

                $postedStatus =
                    (string)($_POST['status'] ?? 'draft');

                $currentStatus =
                    (string)($survey['status'] ?? 'draft');

                if ($currentStatus === 'ended') {
                    $survey['status'] = 'ended';
                } elseif (
                    in_array(
                        $postedStatus,
                        ['draft', 'published', 'stopped'],
                        true
                    )
                ) {
                    $survey['status'] = $postedStatus;
                } else {
                    $survey['status'] = $currentStatus;
                }

                $survey['groups'] =
                    json_decode(
                        (string)($_POST['groups_json'] ?? '[]'),
                        true
                    );

                if (!is_array($survey['groups']) || !$survey['groups']) {
                    $survey['groups'] = [new_group()];
                }

                foreach ($survey['groups'] as &$group) {
                    if (!is_array($group)) {
                        $group = new_group();
                        continue;
                    }

                    $group['id'] =
                        (string)($group['id'] ?? 'group-' . bin2hex(random_bytes(4)));

                    $group['title'] =
                        trim((string)($group['title'] ?? 'グループ'));

                    $group['questions'] =
                        is_array($group['questions'] ?? null)
                        ? $group['questions']
                        : [];

                    foreach ($group['questions'] as &$question) {
                        $question['id'] =
                            (string)($question['id'] ?? 'question-' . bin2hex(random_bytes(4)));

                        $question['text'] =
                            trim((string)($question['text'] ?? ''));

                        $type =
                            (string)($question['type'] ?? 'single');

                        $question['type'] =
                            in_array(
                                $type,
                                ['single', 'multiple', 'text'],
                                true
                            )
                            ? $type
                            : 'single';

                        $question['required'] =
                            !empty($question['required']);

                        $question['options'] =
                            is_array($question['options'] ?? null)
                            ? array_values($question['options'])
                            : [];

                        $question['branches'] =
                            is_array($question['branches'] ?? null)
                            ? $question['branches']
                            : [];
                    }

                    unset($question);
                }

                unset($group);

                renumber_questions($survey);

                $survey['updatedAt'] = now_string();

                if ($id === '') {
                    $data['surveys'][] = $survey;
                } else {
                    $data['surveys'][$index] = $survey;
                }

                save_data($data);

                flash_set(
                    'success',
                    'アンケートを保存しました。'
                );

                redirect_to(screen_url('list'));

            /* -------------------------
             * 複製
             * ------------------------- */

            case 'duplicate_survey':
                $id = trim((string)($_POST['id'] ?? ''));
                $survey = find_survey($data, $id);

                if (!$survey) {
                    throw new RuntimeException(
                        '複製対象のアンケートが見つかりません。'
                    );
                }

                $survey['id'] =
                    'survey-' . bin2hex(random_bytes(6));

                $survey['title'] =
                    (string)$survey['title'] . '（コピー）';

                $survey['status'] = 'draft';
                $survey['createdAt'] = now_string();
                $survey['updatedAt'] = now_string();

                $data['surveys'][] = $survey;

                save_data($data);

                flash_set(
                    'success',
                    'アンケートを複製し、下書きとして追加しました。'
                );

                redirect_to(screen_url('list'));

            /* -------------------------
             * 削除
             * ------------------------- */

            case 'delete_survey':
                $id = trim((string)($_POST['id'] ?? ''));
                $index = survey_index($data, $id);

                if ($index < 0) {
                    throw new RuntimeException(
                        '削除対象のアンケートが見つかりません。'
                    );
                }

                array_splice($data['surveys'], $index, 1);

                save_data($data);

                flash_set(
                    'success',
                    'アンケートを削除しました。'
                );

                redirect_to(screen_url('list'));

            /* -------------------------
             * 状態変更
             * ------------------------- */

            case 'change_status':
                $id = trim((string)($_POST['id'] ?? ''));
                $newStatus = (string)($_POST['new_status'] ?? '');

                $index = survey_index($data, $id);

                if ($index < 0) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $current = (string)(
                    $data['surveys'][$index]['status'] ?? 'draft'
                );

                if ($current === 'ended') {
                    throw new RuntimeException(
                        '終了したアンケートの状態は変更できません。'
                    );
                }

                if (
                    !in_array(
                        $newStatus,
                        ['draft', 'published', 'stopped'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        '指定された状態は変更できません。'
                    );
                }

                $data['surveys'][$index]['status'] = $newStatus;
                $data['surveys'][$index]['updatedAt'] = now_string();

                save_data($data);

                flash_set(
                    'success',
                    '状態を「' . survey_status_label($newStatus) . '」へ変更しました。'
                );

                redirect_to(screen_url('list'));

            /* -------------------------
             * 回答保存
             * ------------------------- */

            case 'submit_answer':
                $id = trim((string)($_POST['survey_id'] ?? ''));
                $survey = find_survey($data, $id);

                if (!$survey) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $answers =
                    json_decode(
                        (string)($_POST['answers_json'] ?? '{}'),
                        true
                    );

                if (!is_array($answers)) {
                    throw new RuntimeException(
                        '回答データが不正です。'
                    );
                }

                $answerId =
                    'answer-' . bin2hex(random_bytes(8));

                $data['answers'][] = [
                    'id' => $answerId,
                    'survey_id' => $id,
                    'answers' => $answers,
                    'created_at' => now_string(),
                ];

                save_data($data);

                $_SESSION['_answer_complete'] = true;

                redirect_to(
                    screen_url('complete', ['id' => $id])
                );

            /* -------------------------
             * メール設定保存
             * ------------------------- */

            case 'save_mail':
                $old = $data['settings']['mail'];

                $password = (string)($_POST['password'] ?? '');

                if ($password === '') {
                    $password = (string)($old['password'] ?? '');
                }

                $data['settings']['mail'] = [
                    'smtp_host' => trim(
                        (string)($_POST['smtp_host'] ?? '')
                    ),
                    'smtp_port' => trim(
                        (string)($_POST['smtp_port'] ?? '587')
                    ),
                    'encryption' => (string)(
                        $_POST['encryption'] ?? 'tls'
                    ),
                    'auth' => isset($_POST['auth']),
                    'username' => trim(
                        (string)($_POST['username'] ?? '')
                    ),
                    'password' => $password,
                    'from_email' => trim(
                        (string)($_POST['from_email'] ?? '')
                    ),
                    'from_name' => trim(
                        (string)($_POST['from_name'] ?? '')
                    ),
                    'reply_to' => trim(
                        (string)($_POST['reply_to'] ?? '')
                    ),
                ];

                save_data($data);

                flash_set(
                    'success',
                    'メール設定を保存しました。'
                );

                redirect_to(screen_url('mail'));

            default:
                flash_set(
                    'error',
                    '不明な操作です。'
                );

                redirect_to(screen_url('list'));
        }
    } catch (Throwable $e) {
        /*
         * 内部のスタックトレースや認証情報は表示しない。
         */
        flash_set(
            'error',
            '処理に失敗しました。' . "\n" .
            $e->getMessage()
        );

        redirect_to(
            screen_url(
                (string)($_GET['screen'] ?? 'list')
            )
        );
    }
}

/* ------------------------------------------------------------
 * 自動状態更新
 * ------------------------------------------------------------ */

$statusChanged = false;

foreach ($data['surveys'] as &$survey) {
    if (auto_update_survey_status($survey)) {
        $statusChanged = true;
    }
}

unset($survey);

if ($statusChanged) {
    save_data($data);
}

/* ------------------------------------------------------------
 * GET画面
 * ------------------------------------------------------------ */

$screen = (string)($_GET['screen'] ?? 'list');

$allowedScreens = [
    'list',
    'edit',
    'preview',
    'send',
    'analytics',
    'kintone',
    'mail',
    'answer',
    'confirm',
    'complete',
];

if (!in_array($screen, $allowedScreens, true)) {
    $screen = 'list';
}

$surveyId = trim((string)($_GET['id'] ?? ''));

$survey = $surveyId !== ''
    ? find_survey($data, $surveyId)
    : null;

if (
    in_array($screen, ['analytics', 'send'], true)
    && !$survey
) {
    redirect_to(screen_url('list'));
}

$flash = flash_get();

$kintoneTest =
    $_SESSION['_kintone_test'] ?? null;

unset($_SESSION['_kintone_test']);

$csrf = csrf_token();

/* ------------------------------------------------------------
 * 一覧検索・絞り込み
 * ------------------------------------------------------------ */

$search = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? 'all');
$sort   = (string)($_GET['sort'] ?? 'updated_desc');

$surveys = $data['surveys'];

foreach ($surveys as &$s) {
    auto_update_survey_status($s);
}

unset($s);

if ($search !== '') {
    $surveys = array_values(
        array_filter(
            $surveys,
            static function (array $s) use ($search): bool {
                return mb_stripos(
                    (string)($s['title'] ?? ''),
                    $search
                ) !== false;
            }
        )
    );
}

if ($filter !== 'all') {
    $surveys = array_values(
        array_filter(
            $surveys,
            static fn(array $s): bool =>
                (string)($s['status'] ?? '') === $filter
        )
    );
}

usort(
    $surveys,
    static function (array $a, array $b) use ($sort): int {
        return match ($sort) {
            'updated_asc' =>
                strcmp(
                    (string)($a['updatedAt'] ?? ''),
                    (string)($b['updatedAt'] ?? '')
                ),

            'answers_desc' =>
                count($GLOBALS['data']['answers'] ?? [])
                <=> count($GLOBALS['data']['answers'] ?? []),

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

/* ------------------------------------------------------------
 * 回答数
 * ------------------------------------------------------------ */

function survey_answer_count(array $data, string $surveyId): int
{
    $count = 0;

    foreach ($data['answers'] as $answer) {
        if ((string)($answer['survey_id'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

/* ------------------------------------------------------------
 * HTML開始
 * ------------------------------------------------------------ */

$adminScreens = [
    'list',
    'edit',
    'preview',
    'send',
    'analytics',
    'kintone',
    'mail',
];

$isAnswerer =
    in_array(
        $screen,
        ['answer', 'confirm', 'complete'],
        true
    );
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?></title>

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
    --bg:#f8fafc;
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
    background:var(--bg);
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    line-height:1.6;
}

a {
    color:inherit;
    text-decoration:none;
}

button,
input,
select,
textarea {
    font:inherit;
}

button {
    cursor:pointer;
}

.app-header {
    background:#0f172a;
    color:#fff;
    min-height:64px;
}

.header-inner {
    max-width:1400px;
    margin:0 auto;
    padding:0 24px;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.logo {
    font-weight:800;
    font-size:20px;
    letter-spacing:.02em;
}

.admin-nav {
    display:flex;
    gap:4px;
    overflow:auto;
}

.admin-nav a {
    padding:10px 13px;
    border-radius:8px;
    color:#cbd5e1;
    white-space:nowrap;
    font-size:14px;
}

.admin-nav a:hover,
.admin-nav a.active {
    background:#1e293b;
    color:#fff;
}

.container {
    max-width:1400px;
    margin:0 auto;
    padding:30px 24px 60px;
}

.page-head {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:20px;
    margin-bottom:24px;
}

.page-title {
    margin:0;
    font-size:28px;
    line-height:1.3;
}

.page-description {
    color:var(--gray);
    margin:6px 0 0;
}

.actions {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.btn {
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    padding:9px 14px;
    border-radius:8px;
    font-weight:600;
    transition:.15s;
}

.btn:hover {
    transform:translateY(-1px);
    box-shadow:0 3px 10px rgba(15,23,42,.08);
}

.btn-primary {
    background:var(--primary);
    border-color:var(--primary);
    color:#fff;
}

.btn-primary:hover {
    background:var(--primary-dark);
}

.btn-success {
    background:var(--success);
    border-color:var(--success);
    color:#fff;
}

.btn-danger {
    background:var(--danger);
    border-color:var(--danger);
    color:#fff;
}

.btn-warning {
    background:var(--warning);
    border-color:var(--warning);
    color:#fff;
}

.btn-small {
    padding:6px 9px;
    font-size:13px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}

.card-title {
    margin:0 0 16px;
    font-size:18px;
}

.grid {
    display:grid;
    gap:18px;
}

.grid-2 {
    grid-template-columns:repeat(2,minmax(0,1fr));
}

.grid-3 {
    grid-template-columns:repeat(3,minmax(0,1fr));
}

.field {
    margin-bottom:16px;
}

.field:last-child {
    margin-bottom:0;
}

.field label {
    display:block;
    font-weight:700;
    margin-bottom:6px;
}

.field input,
.field select,
.field textarea {
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

.field textarea {
    min-height:110px;
    resize:vertical;
}

.field input:focus,
.field select:focus,
.field textarea:focus {
    outline:3px solid rgba(37,99,235,.12);
    border-color:var(--primary);
}

.help {
    margin-top:5px;
    color:var(--gray);
    font-size:13px;
}

.notice {
    border-radius:10px;
    padding:14px 16px;
    margin-bottom:18px;
    white-space:pre-line;
}

.notice-success {
    background:#ecfdf5;
    color:#166534;
    border:1px solid #bbf7d0;
}

.notice-error {
    background:#fef2f2;
    color:#991b1b;
    border:1px solid #fecaca;
}

.notice-warning {
    background:#fffbeb;
    color:#92400e;
    border:1px solid #fde68a;
}

.notice-info {
    background:#eff6ff;
    color:#1e40af;
    border:1px solid #bfdbfe;
}

.table-wrap {
    overflow-x:auto;
}

.table {
    width:100%;
    border-collapse:collapse;
    min-width:980px;
}

.table th,
.table td {
    padding:12px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

.table th {
    background:#f8fafc;
    font-size:13px;
    color:#475569;
}

.table tr:hover td {
    background:#f8fafc;
}

.badge {
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge-draft {
    background:#e2e8f0;
    color:#475569;
}

.badge-published {
    background:#dcfce7;
    color:#166534;
}

.badge-stopped {
    background:#fef3c7;
    color:#92400e;
}

.badge-ended {
    background:#fee2e2;
    color:#991b1b;
}

.badge-gray {
    background:#e2e8f0;
    color:#475569;
}

.toolbar {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:end;
}

.toolbar .field {
    margin:0;
    min-width:180px;
}

.toolbar .field.search {
    flex:1;
    min-width:260px;
}

.empty {
    padding:50px 20px;
    text-align:center;
    color:var(--gray);
}

.stat-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
}

.stat-label {
    color:var(--gray);
    font-size:13px;
}

.stat-value {
    margin-top:5px;
    font-size:28px;
    font-weight:800;
}

.form-actions {
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:22px;
}

.question-card,
.group-card {
    border:1px solid var(--border);
    border-radius:12px;
    background:#fff;
}

.group-card {
    margin-bottom:18px;
    overflow:hidden;
}

.group-head {
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    padding:12px 14px;
    display:flex;
    align-items:center;
    gap:10px;
}

.group-title {
    flex:1;
}

.question-list {
    padding:12px;
    min-height:40px;
}

.question-card {
    padding:14px;
    margin-bottom:10px;
}

.question-card:last-child {
    margin-bottom:0;
}

.drag-handle {
    cursor:grab;
    color:var(--gray);
    user-select:none;
}

.question-meta {
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:8px;
}

.question-number {
    font-weight:800;
    color:var(--primary);
}

.option-row {
    display:flex;
    gap:8px;
    margin-bottom:8px;
}

.option-row input {
    flex:1;
}

.add-row {
    margin-top:10px;
}

.branch-box {
    background:#f8fafc;
    border-radius:8px;
    padding:10px;
    margin-top:10px;
}

.preview-question {
    padding:18px 0;
    border-bottom:1px solid var(--border);
}

.preview-question:last-child {
    border-bottom:0;
}

.choice {
    display:flex;
    gap:9px;
    align-items:center;
    padding:10px;
    border:1px solid var(--border);
    border-radius:8px;
    margin:7px 0;
}

.choice input {
    width:18px;
    height:18px;
}

.result-panel {
    border:1px solid var(--border);
    border-radius:12px;
    overflow:hidden;
    margin-top:18px;
}

.result-head {
    padding:16px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.result-head.success {
    background:#ecfdf5;
    color:#166534;
}

.result-head.error {
    background:#fef2f2;
    color:#991b1b;
}

.result-title {
    font-size:19px;
    font-weight:800;
}

.result-body {
    padding:18px;
}

.test-steps {
    display:flex;
    flex-direction:column;
    gap:10px;
}

.test-step {
    display:grid;
    grid-template-columns:32px 190px 1fr;
    gap:10px;
    align-items:start;
    padding:10px;
    border-radius:8px;
    background:#f8fafc;
}

.step-icon {
    width:26px;
    height:26px;
    display:grid;
    place-items:center;
    border-radius:50%;
    font-weight:800;
}

.step-success .step-icon {
    background:#dcfce7;
    color:#166534;
}

.step-error .step-icon {
    background:#fee2e2;
    color:#991b1b;
}

.step-warning .step-icon {
    background:#fef3c7;
    color:#92400e;
}

.step-info .step-icon {
    background:#dbeafe;
    color:#1d4ed8;
}

.step-title {
    font-weight:700;
}

.step-detail {
    color:#475569;
    overflow-wrap:anywhere;
}

.detail-box {
    margin-top:14px;
    background:#0f172a;
    color:#e2e8f0;
    border-radius:8px;
    padding:13px;
    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Consolas,
        monospace;
    font-size:12px;
    white-space:pre-wrap;
    overflow:auto;
}

.spinner {
    width:17px;
    height:17px;
    border:2px solid rgba(255,255,255,.4);
    border-top-color:#fff;
    border-radius:50%;
    display:inline-block;
    animation:spin .7s linear infinite;
    vertical-align:-3px;
}

@keyframes spin {
    to { transform:rotate(360deg); }
}

.mobile-only {
    display:none;
}

.answer-container {
    max-width:760px;
    margin:0 auto;
}

.answer-header {
    margin-bottom:20px;
}

.answer-title {
    font-size:28px;
    font-weight:800;
}

.answer-question {
    margin-bottom:22px;
}

.answer-question h3 {
    margin:0 0 10px;
}

.required {
    color:var(--danger);
    font-size:12px;
    margin-left:5px;
}

.answer-actions {
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:25px;
}

.progress {
    height:8px;
    background:#e2e8f0;
    border-radius:999px;
    overflow:hidden;
    margin:12px 0 25px;
}

.progress > div {
    height:100%;
    background:var(--primary);
}

@media (max-width:900px) {
    .header-inner {
        align-items:flex-start;
        flex-direction:column;
        padding:14px 18px;
    }

    .admin-nav {
        width:100%;
    }

    .container {
        padding:22px 16px 45px;
    }

    .page-head {
        flex-direction:column;
    }

    .grid-2,
    .grid-3,
    .stat-grid {
        grid-template-columns:1fr;
    }

    .test-step {
        grid-template-columns:32px 1fr;
    }

    .test-step .step-detail {
        grid-column:2;
    }

    .mobile-only {
        display:block;
    }
}

@media (max-width:600px) {
    .page-title {
        font-size:23px;
    }

    .card {
        padding:16px;
    }

    .btn {
        width:auto;
    }

    .form-actions {
        flex-direction:column;
    }

    .form-actions .btn {
        width:100%;
    }

    .answer-title {
        font-size:23px;
    }

    .answer-actions {
        position:sticky;
        bottom:0;
        background:#fff;
        padding:12px 0;
    }
}
</style>
</head>

<body>

<?php if (!$isAnswerer): ?>

<header class="app-header">
    <div class="header-inner">
        <div class="logo"><?= h(APP_NAME) ?></div>

        <nav class="admin-nav">
            <a
                href="<?= h(screen_url('list')) ?>"
                class="<?= $screen === 'list' ? 'active' : '' ?>"
            >アンケート一覧</a>

            <a
                href="<?= h(screen_url('kintone')) ?>"
                class="<?= $screen === 'kintone' ? 'active' : '' ?>"
            >kintone</a>

            <a
                href="<?= h(screen_url('mail')) ?>"
                class="<?= $screen === 'mail' ? 'active' : '' ?>"
            >メール</a>
        </nav>
    </div>
</header>

<?php endif; ?>

<main class="container">

<?php if ($flash): ?>
    <div class="notice notice-<?= h($flash['type']) ?>">
        <?= h($flash['message']) ?>
    </div>
<?php endif; ?>

<?php
/* ============================================================
 * 一覧
 * ============================================================ */
if ($screen === 'list'):
?>

<div class="page-head">
    <div>
        <h1 class="page-title">アンケート一覧</h1>
        <p class="page-description">
            アンケートの作成・公開・集計・送信を管理します。
        </p>
    </div>

    <div class="actions">
        <a
            class="btn btn-primary"
            href="<?= h(screen_url('edit')) ?>"
        >＋ 新規作成</a>
    </div>
</div>

<div class="card">
    <form method="get">
        <input type="hidden" name="screen" value="list">

        <div class="toolbar">
            <div class="field search">
                <label for="q">検索</label>
                <input
                    id="q"
                    type="search"
                    name="q"
                    value="<?= h($search) ?>"
                    placeholder="タイトルで検索"
                >
            </div>

            <div class="field">
                <label for="filter">状態</label>
                <select id="filter" name="filter">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>すべて</option>
                    <option value="published" <?= $filter === 'published' ? 'selected' : '' ?>>公開中</option>
                    <option value="draft" <?= $filter === 'draft' ? 'selected' : '' ?>>下書き</option>
                    <option value="stopped" <?= $filter === 'stopped' ? 'selected' : '' ?>>停止</option>
                    <option value="ended" <?= $filter === 'ended' ? 'selected' : '' ?>>終了</option>
                </select>
            </div>

            <div class="field">
                <label for="sort">ソート</label>
                <select id="sort" name="sort">
                    <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>更新日：新しい順</option>
                    <option value="updated_asc" <?= $sort === 'updated_asc' ? 'selected' : '' ?>>更新日：古い順</option>
                    <option value="answers_desc" <?= $sort === 'answers_desc' ? 'selected' : '' ?>>回答数：多い順</option>
                    <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>開始日：新しい順</option>
                    <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>開始日：古い順</option>
                </select>
            </div>

            <button class="btn btn-primary" type="submit">
                検索・絞り込み
            </button>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
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
            <?php if (!$surveys): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            アンケートがありません。
                        </div>
                    </td>
                </tr>
            <?php else: ?>

            <?php foreach ($surveys as $item): ?>
                <?php
                    $itemId = (string)$item['id'];
                    $itemStatus = (string)($item['status'] ?? 'draft');
                    $answerCount = survey_answer_count(
                        $data,
                        $itemId
                    );
                ?>
                <tr>
                    <td>
                        <strong><?= h($item['title'] ?: '無題のアンケート') ?></strong>
                    </td>

                    <td><?= h($item['createdAt'] ?? '') ?></td>

                    <td><?= h($item['updatedAt'] ?? '') ?></td>

                    <td>
                        <?= h($item['startAt'] ?? '-') ?>
                        ～
                        <?= h($item['endAt'] ?? '-') ?>
                    </td>

                    <td>
                        <span class="badge <?= h(survey_status_class($itemStatus)) ?>">
                            <?= h(survey_status_label($itemStatus)) ?>
                        </span>
                    </td>

                    <td><?= h($answerCount) ?>件</td>

                    <td>
                        <div class="actions">

                            <a
                                class="btn btn-small"
                                href="<?= h(screen_url('edit', ['id' => $itemId])) ?>"
                            >確認・編集</a>

                            <a
                                class="btn btn-small"
                                href="<?= h(screen_url('analytics', ['id' => $itemId])) ?>"
                            >集計</a>

                            <a
                                class="btn btn-small"
                                href="<?= h(screen_url('send', ['id' => $itemId])) ?>"
                            >送信</a>

                            <form
                                method="post"
                                style="display:inline"
                                onsubmit="return confirm('このアンケートを複製しますか？');"
                            >
                                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="duplicate_survey">
                                <input type="hidden" name="id" value="<?= h($itemId) ?>">
                                <button class="btn btn-small" type="submit">複製</button>
                            </form>

                            <form
                                method="post"
                                style="display:inline"
                                onsubmit="return confirm('このアンケートを削除しますか？');"
                            >
                                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="delete_survey">
                                <input type="hidden" name="id" value="<?= h($itemId) ?>">
                                <button class="btn btn-danger btn-small" type="submit">削除</button>
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
/* ============================================================
 * kintone設定
 * ============================================================ */
elseif ($screen === 'kintone'):

$kc = $data['settings']['kintone'];

$test = $kintoneTest;

$syncCount = count($data['customers']);

?>

<div class="page-head">
    <div>
        <h1 class="page-title">kintone連携設定</h1>
        <p class="page-description">
            kintoneへの接続確認と顧客情報同期を行います。
        </p>
    </div>
</div>

<div class="card">
    <h2 class="card-title">kintone接続設定</h2>

    <form method="post" id="kintoneForm">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

        <div class="grid grid-2">

            <div class="field">
                <label for="subdomain">
                    サブドメイン <span style="color:#dc2626">*</span>
                </label>

                <input
                    id="subdomain"
                    name="subdomain"
                    value="<?= h($kc['subdomain'] ?? '') ?>"
                    placeholder="xxxx.cybozu.com"
                    required
                >

                <div class="help">
                    https://xxxx.cybozu.com、xxxx.cybozu.com、xxxx のいずれでも入力できます。
                </div>
            </div>

            <div class="field">
                <label for="app_id">
                    顧客管理アプリID <span style="color:#dc2626">*</span>
                </label>

                <input
                    id="app_id"
                    name="app_id"
                    inputmode="numeric"
                    value="<?= h($kc['app_id'] ?? '') ?>"
                    placeholder="123"
                    required
                >
            </div>

            <div class="field">
                <label for="login_name">
                    ログイン名 <span style="color:#dc2626">*</span>
                </label>

                <input
                    id="login_name"
                    name="login_name"
                    value="<?= h($kc['login_name'] ?? '') ?>"
                    required
                >

                <div class="help">
                    メールアドレス欄ではありません。kintoneのログイン名を指定します。
                </div>
            </div>

            <div class="field">
                <label for="password">
                    パスワード <span style="color:#dc2626">*</span>
                </label>

                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    placeholder="変更しない場合は空欄"
                >
            </div>

            <div class="field">
                <label for="proxy">
                    Proxy
                </label>

                <input
                    id="proxy"
                    name="proxy"
                    value="<?= h($kc['proxy'] ?? '') ?>"
                    placeholder="proxy.example.local:8080"
                >

                <div class="help">
                    host:port形式。未入力ならProxyを使用せず直接接続します。
                </div>
            </div>

            <div class="field">
                <label>
                    SSL証明書検証
                </label>

                <label style="display:flex;align-items:center;gap:8px;font-weight:400">
                    <input
                        type="checkbox"
                        name="verify_ssl"
                        value="1"
                        <?= !empty($kc['verify_ssl']) ? 'checked' : '' ?>
                    >
                    SSL証明書を検証する
                </label>

                <div class="help">
                    POCでは無効を基本とします。
                </div>
            </div>

        </div>

        <div class="form-actions">

            <button
                class="btn"
                name="action"
                value="save_kintone"
                type="submit"
            >
                設定保存
            </button>

            <button
                class="btn btn-primary"
                name="action"
                value="test_kintone"
                type="submit"
                id="testKintoneButton"
            >
                <span id="testKintoneSpinner" class="spinner" style="display:none"></span>
                <span id="testKintoneText">接続テスト</span>
            </button>

            <button
                class="btn"
                name="action"
                value="fetch_kintone_fields"
                type="submit"
            >
                項目一覧を再取得
            </button>

            <button
                class="btn btn-success"
                name="action"
                value="sync_kintone"
                type="submit"
                onclick="return confirm('kintoneから顧客情報を取得して同期しますか？');"
            >
                顧客情報を同期
            </button>

        </div>
    </form>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-label">現在の同期件数</div>
        <div class="stat-value"><?= h($syncCount) ?></div>
    </div>

    <div class="stat">
        <div class="stat-label">接続方式</div>
        <div class="stat-value" style="font-size:18px">
            PHP Stream
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">PHP cURL</div>
        <div class="stat-value" style="font-size:18px">
            使用しない
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">認証方式</div>
        <div class="stat-value" style="font-size:16px">
            X-Cybozu-Authorization
        </div>
    </div>
</div>

<?php if ($test): ?>

<div class="result-panel">

    <div class="result-head <?= !empty($test['ok']) ? 'success' : 'error' ?>">

        <div>
            <div class="result-title">
                <?= !empty($test['ok']) ? '✓ 接続テスト成功' : '✕ 接続テスト失敗' ?>
            </div>

            <div>
                <?= h($test['message'] ?? '') ?>
            </div>
        </div>

        <?php if (isset($test['http_code'])): ?>
            <strong>
                HTTP <?= h($test['http_code']) ?>
            </strong>
        <?php endif; ?>

    </div>

    <div class="result-body">

        <?php if (!empty($test['detail'])): ?>
            <div class="notice <?= !empty($test['ok']) ? 'notice-success' : 'notice-error' ?>">
                <?= h($test['detail']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($test['recommendation'])): ?>
            <div class="notice notice-warning">
                <strong>次に確認すること</strong><br>
                <?= h($test['recommendation']) ?>
            </div>
        <?php endif; ?>

        <h3>接続テストの経過</h3>

        <div class="test-steps">

            <?php foreach (($test['steps'] ?? []) as $step): ?>

                <?php
                    $stepStatus = (string)($step['status'] ?? 'info');

                    $icon = match ($stepStatus) {
                        'success' => '✓',
                        'error'   => '✕',
                        'warning' => '!',
                        default   => 'i',
                    };
                ?>

                <div class="test-step step-<?= h($stepStatus) ?>">

                    <div class="step-icon">
                        <?= h($icon) ?>
                    </div>

                    <div class="step-title">
                        <?= h($step['title'] ?? '') ?>
                    </div>

                    <div class="step-detail">
                        <?= h($step['detail'] ?? '') ?>
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

        <?php if (
            empty($test['ok'])
            && !empty($test['api_body'])
        ): ?>

            <h3>kintoneから返されたエラー情報</h3>

            <div class="detail-box"><?= h($test['api_body']) ?></div>

        <?php endif; ?>

        <?php if (!empty($test['elapsed_ms'])): ?>

            <p class="help">
                通信時間: <?= h($test['elapsed_ms']) ?> ms
            </p>

        <?php endif; ?>

    </div>
</div>

<?php endif; ?>

<?php
/* ============================================================
 * メール設定
 * ============================================================ */
elseif ($screen === 'mail'):

$mc = $data['settings']['mail'];

?>

<div class="page-head">
    <div>
        <h1 class="page-title">メールサーバ設定</h1>
        <p class="page-description">
            SMTPサーバとの接続設定を管理します。
        </p>
    </div>
</div>

<div class="card">

<form method="post">
<input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

<div class="grid grid-2">

    <div class="field">
        <label>SMTPサーバ</label>
        <input
            name="smtp_host"
            value="<?= h($mc['smtp_host'] ?? '') ?>"
            placeholder="smtp.example.com"
        >
    </div>

    <div class="field">
        <label>SMTPポート</label>
        <input
            name="smtp_port"
            value="<?= h($mc['smtp_port'] ?? '587') ?>"
            inputmode="numeric"
        >
    </div>

    <div class="field">
        <label>暗号化方式</label>
        <select name="encryption">
            <option value="ssl" <?= ($mc['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
            <option value="tls" <?= ($mc['encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
            <option value="none" <?= ($mc['encryption'] ?? '') === 'none' ? 'selected' : '' ?>>なし</option>
        </select>
    </div>

    <div class="field">
        <label>SMTP認証</label>
        <label style="font-weight:400">
            <input
                type="checkbox"
                name="auth"
                value="1"
                <?= !empty($mc['auth']) ? 'checked' : '' ?>
            >
            使用する
        </label>
    </div>

    <div class="field">
        <label>SMTPユーザー名</label>
        <input
            name="username"
            value="<?= h($mc['username'] ?? '') ?>"
        >
    </div>

    <div class="field">
        <label>SMTPパスワード</label>
        <input
            type="password"
            name="password"
            autocomplete="new-password"
            placeholder="変更しない場合は空欄"
        >
    </div>

    <div class="field">
        <label>送信元メールアドレス</label>
        <input
            type="email"
            name="from_email"
            value="<?= h($mc['from_email'] ?? '') ?>"
        >
    </div>

    <div class="field">
        <label>送信元名</label>
        <input
            name="from_name"
            value="<?= h($mc['from_name'] ?? '') ?>"
        >
    </div>

    <div class="field">
        <label>返信先メールアドレス</label>
        <input
            type="email"
            name="reply_to"
            value="<?= h($mc['reply_to'] ?? '') ?>"
        >
    </div>

</div>

<div class="form-actions">

    <button
        class="btn btn-primary"
        type="submit"
        name="action"
        value="save_mail"
    >
        設定保存
    </button>

</div>

</form>
</div>

<?php
/* ============================================================
 * アンケート編集
 * ============================================================ */
elseif ($screen === 'edit'):

$editing = $survey ?? new_survey();

renumber_questions($editing);

?>

<div class="page-head">
    <div>
        <h1 class="page-title">アンケート作成・編集</h1>
    </div>

    <div class="actions">
        <a
            class="btn"
            href="<?= h(screen_url('list')) ?>"
            onclick="return confirm('編集内容を破棄して一覧へ戻りますか？');"
        >キャンセル</a>

        <?php if (!empty($editing['id'])): ?>
        <a
            class="btn"
            href="<?= h(screen_url('preview', ['id' => $editing['id']])) ?>"
        >プレビュー</a>
        <?php endif; ?>
    </div>
</div>

<form
    method="post"
    id="surveyForm"
    onsubmit="return prepareSurveySubmit();"
>

<input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
<input type="hidden" name="action" value="save_survey">
<input
    type="hidden"
    name="id"
    value="<?= h($editing['id'] ?? '') ?>"
>
<input
    type="hidden"
    name="groups_json"
    id="groupsJson"
>

<div class="card">

    <div class="grid grid-2">

        <div class="field">
            <label>アンケートタイトル</label>
            <input
                id="surveyTitle"
                name="title"
                value="<?= h($editing['title'] ?? '') ?>"
                required
            >
        </div>

        <div class="field">
            <label>状態</label>
            <select
                name="status"
                <?= ($editing['status'] ?? '') === 'ended' ? 'disabled' : '' ?>
            >
                <option value="draft" <?= ($editing['status'] ?? '') === 'draft' ? 'selected' : '' ?>>下書き</option>
                <option value="published" <?= ($editing['status'] ?? '') === 'published' ? 'selected' : '' ?>>公開中</option>
                <option value="stopped" <?= ($editing['status'] ?? '') === 'stopped' ? 'selected' : '' ?>>停止</option>
            </select>
        </div>

        <div class="field">
            <label>開始日時</label>
            <input
                type="datetime-local"
                name="startAt"
                value="<?= h($editing['startAt'] ?? '') ?>"
            >
        </div>

        <div class="field">
            <label>終了日時</label>
            <input
                type="datetime-local"
                name="endAt"
                value="<?= h($editing['endAt'] ?? '') ?>"
            >
        </div>

        <div class="field">
            <label>質問番号の採番方式</label>
            <select name="numbering" id="numbering">
                <option
                    value="global"
                    <?= ($editing['numbering'] ?? 'global') === 'global' ? 'selected' : '' ?>
                >
                    アンケート全体で通番（Q1、Q2、Q3）
                </option>

                <option
                    value="group"
                    <?= ($editing['numbering'] ?? '') === 'group' ? 'selected' : '' ?>
                >
                    グループ毎（Q1-1、Q1-2、Q2-1）
                </option>
            </select>
        </div>

    </div>

    <div class="field">
        <label>アンケート説明</label>
        <textarea name="description"><?= h($editing['description'] ?? '') ?></textarea>
    </div>

</div>

<div class="card">
    <h2 class="card-title">質問・グループ</h2>

    <div id="groupsContainer"></div>

    <button
        type="button"
        class="btn"
        onclick="addGroup()"
    >
        ＋ グループを追加
    </button>
</div>

<div class="form-actions">
    <a
        class="btn"
        href="<?= h(screen_url('list')) ?>"
        onclick="return confirm('編集内容を破棄しますか？');"
    >キャンセル</a>

    <button
        class="btn btn-primary"
        type="submit"
    >
        保存して一覧へ
    </button>
</div>

</form>

<script>
const initialGroups = <?= json_encode_safe($editing['groups'] ?? [new_group()]) ?>;

let groups = initialGroups;

function makeId(prefix) {
    return prefix + '-' +
        Math.random().toString(36).slice(2) +
        Date.now().toString(36);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function renumberClient() {
    const mode = document.getElementById('numbering').value;
    let global = 1;

    groups.forEach((group, gi) => {
        group.questions.forEach((question, qi) => {
            question.number =
                mode === 'group'
                ? `Q${gi + 1}-${qi + 1}`
                : `Q${global}`;

            global++;
        });
    });
}

function renderGroups() {
    renumberClient();

    const container =
        document.getElementById('groupsContainer');

    container.innerHTML = '';

    groups.forEach((group, gi) => {

        const wrapper = document.createElement('div');
        wrapper.className = 'group-card';
        wrapper.draggable = true;
        wrapper.dataset.groupIndex = gi;

        wrapper.innerHTML = `
            <div class="group-head">
                <span class="drag-handle">☰</span>

                <input
                    class="group-title"
                    value="${escapeHtml(group.title)}"
                    aria-label="グループタイトル"
                    onchange="updateGroupTitle(${gi}, this.value)"
                >

                <button
                    type="button"
                    class="btn btn-danger btn-small"
                    onclick="deleteGroup(${gi})"
                >
                    グループ削除
                </button>
            </div>

            <div
                class="question-list"
                data-question-list="${gi}"
            ></div>

            <div style="padding:12px">
                <button
                    type="button"
                    class="btn btn-small"
                    onclick="addQuestion(${gi})"
                >
                    ＋ 質問を追加
                </button>
            </div>
        `;

        wrapper.addEventListener('dragstart', () => {
            wrapper.classList.add('dragging');
        });

        wrapper.addEventListener('dragend', () => {
            wrapper.classList.remove('dragging');
        });

        container.appendChild(wrapper);

        const questionList =
            wrapper.querySelector(
                `[data-question-list="${gi}"]`
            );

        group.questions.forEach((question, qi) => {

            const q = document.createElement('div');

            q.className = 'question-card';
            q.draggable = true;
            q.dataset.questionIndex = qi;
            q.dataset.groupIndex = gi;

            q.innerHTML = `
                <div class="question-meta">
                    <span class="drag-handle">☰</span>
                    <span class="question-number">
                        ${escapeHtml(question.number)}
                    </span>
                    <span style="color:#64748b">
                        質問
                    </span>
                </div>

                <div class="field">
                    <label>質問文</label>
                    <input
                        value="${escapeHtml(question.text)}"
                        onchange="updateQuestionText(${gi}, ${qi}, this.value)"
                    >
                </div>

                <div class="grid grid-2">

                    <div class="field">
                        <label>回答形式</label>

                        <select
                            onchange="updateQuestionType(${gi}, ${qi}, this.value)"
                        >
                            <option
                                value="single"
                                ${question.type === 'single' ? 'selected' : ''}
                            >
                                単一選択
                            </option>

                            <option
                                value="multiple"
                                ${question.type === 'multiple' ? 'selected' : ''}
                            >
                                複数選択
                            </option>

                            <option
                                value="text"
                                ${question.type === 'text' ? 'selected' : ''}
                            >
                                自由記述
                            </option>
                        </select>
                    </div>

                    <div class="field">
                        <label>必須設定</label>

                        <label style="font-weight:400">
                            <input
                                type="checkbox"
                                ${question.required ? 'checked' : ''}
                                onchange="updateQuestionRequired(${gi}, ${qi}, this.checked)"
                            >
                            必須
                        </label>
                    </div>

                </div>

                <div class="options-area">
                    ${
                        question.type !== 'text'
                        ? renderOptions(gi, qi, question)
                        : ''
                    }
                </div>

                ${
                    question.type === 'single'
                    ? renderBranches(gi, qi, question)
                    : ''
                }

                <div class="form-actions">
                    <button
                        type="button"
                        class="btn btn-danger btn-small"
                        onclick="deleteQuestion(${gi}, ${qi})"
                    >
                        質問削除
                    </button>
                </div>
            `;

            questionList.appendChild(q);
        });
    });
}

function renderOptions(gi, qi, question) {
    const options =
        Array.isArray(question.options)
        ? question.options
        : [];

    let html = '<div class="field"><label>選択肢</label>';

    options.forEach((option, oi) => {
        html += `
            <div class="option-row">
                <input
                    value="${escapeHtml(option)}"
                    onchange="updateOption(${gi}, ${qi}, ${oi}, this.value)"
                >

                <button
                    type="button"
                    class="btn btn-small btn-danger"
                    onclick="deleteOption(${gi}, ${qi}, ${oi})"
                >
                    削除
                </button>
            </div>
        `;
    });

    html += `
        <button
            type="button"
            class="btn btn-small"
            onclick="addOption(${gi}, ${qi})"
        >
            ＋ 選択肢
        </button>
    `;

    html += '</div>';

    return html;
}

function renderBranches(gi, qi, question) {
    const branches =
        question.branches || {};

    let html = `
        <div class="branch-box">
            <strong>条件分岐</strong>
            <div class="help">
                選択肢ごとに次に表示する質問を指定できます。
            </div>
    `;

    (question.options || []).forEach((option, oi) => {
        const value = branches[option] || '';

        html += `
            <div class="field" style="margin-top:10px">
                <label>${escapeHtml(option)}</label>

                <select
                    onchange="updateBranch(${gi}, ${qi}, '${escapeHtml(option).replaceAll("'", "\\'")}', this.value)"
                >
                    <option value="">次の質問を指定しない</option>
        `;

        groups.forEach(group => {
            group.questions.forEach(target => {
                if (
                    target.id !== question.id
                ) {
                    html += `
                        <option
                            value="${escapeHtml(target.id)}"
                            ${value === target.id ? 'selected' : ''}
                        >
                            ${escapeHtml(target.number)} ${escapeHtml(target.text)}
                        </option>
                    `;
                }
            });
        });

        html += `
                </select>
            </div>
        `;
    });

    html += '</div>';

    return html;
}

function updateGroupTitle(gi, value) {
    groups[gi].title = value;
}

function addGroup() {
    groups.push({
        id: makeId('group'),
        title: '新しいグループ',
        questions: [{
            id: makeId('question'),
            number: '',
            text: '新しい質問',
            type: 'single',
            required: true,
            options: ['はい', 'いいえ'],
            branches: {}
        }]
    });

    renderGroups();
}

function deleteGroup(gi) {
    if (groups.length <= 1) {
        alert('グループは最低1つ必要です。');
        return;
    }

    if (!confirm('このグループを削除しますか？')) {
        return;
    }

    groups.splice(gi, 1);
    renderGroups();
}

function addQuestion(gi) {
    groups[gi].questions.push({
        id: makeId('question'),
        number: '',
        text: '新しい質問',
        type: 'single',
        required: true,
        options: ['はい', 'いいえ'],
        branches: {}
    });

    renderGroups();
}

function deleteQuestion(gi, qi) {
    if (
        groups[gi].questions.length <= 1
        && groups.length <= 1
    ) {
        alert('質問は最低1つ必要です。');
        return;
    }

    if (!confirm('この質問を削除しますか？')) {
        return;
    }

    groups[gi].questions.splice(qi, 1);

    renderGroups();
}

function updateQuestionText(gi, qi, value) {
    groups[gi].questions[qi].text = value;
}

function updateQuestionType(gi, qi, value) {
    groups[gi].questions[qi].type = value;

    if (
        value !== 'text'
        && !Array.isArray(groups[gi].questions[qi].options)
    ) {
        groups[gi].questions[qi].options = ['はい', 'いいえ'];
    }

    renderGroups();
}

function updateQuestionRequired(gi, qi, checked) {
    groups[gi].questions[qi].required = checked;
}

function addOption(gi, qi) {
    groups[gi].questions[qi].options.push('新しい選択肢');
    renderGroups();
}

function deleteOption(gi, qi, oi) {
    const options =
        groups[gi].questions[qi].options;

    if (options.length <= 2) {
        alert('選択肢は最低2つ必要です。');
        return;
    }

    options.splice(oi, 1);

    renderGroups();
}

function updateOption(gi, qi, oi, value) {
    const question =
        groups[gi].questions[qi];

    const old =
        question.options[oi];

    question.options[oi] = value;

    if (
        question.branches
        && question.branches[old]
    ) {
        question.branches[value] =
            question.branches[old];

        delete question.branches[old];
    }
}

function updateBranch(gi, qi, option, target) {
    if (!groups[gi].questions[qi].branches) {
        groups[gi].questions[qi].branches = {};
    }

    if (target) {
        groups[gi].questions[qi].branches[option] =
            target;
    } else {
        delete groups[gi].questions[qi].branches[option];
    }
}

function prepareSurveySubmit() {
    renumberClient();

    document.getElementById('groupsJson').value =
        JSON.stringify(groups);

    return true;
}

document
    .getElementById('numbering')
    .addEventListener('change', renderGroups);

renderGroups();
</script>

<?php
/* ============================================================
 * プレビュー
 * ============================================================ */
elseif ($screen === 'preview'):

if (!$survey):
    redirect_to(screen_url('list'));
endif;

?>

<div class="page-head">
    <div>
        <h1 class="page-title">プレビュー</h1>
        <p class="page-description">
            <?= h($survey['title'] ?? '') ?>
        </p>
    </div>

    <div class="actions">
        <a
            class="btn"
            href="<?= h(screen_url('edit', ['id' => $surveyId])) ?>"
        >編集へ戻る</a>
    </div>
</div>

<div class="card">
    <h2><?= h($survey['title'] ?? '') ?></h2>

    <?php if (!empty($survey['description'])): ?>
        <p><?= nl2br(h($survey['description'])) ?></p>
    <?php endif; ?>

    <?php foreach ($survey['groups'] as $group): ?>

        <section style="margin-top:28px">

            <h3><?= h($group['title'] ?? '') ?></h3>

            <?php foreach ($group['questions'] as $question): ?>

                <div class="preview-question">

                    <div>
                        <strong>
                            <?= h($question['number'] ?? '') ?>
                            <?= h($question['text'] ?? '') ?>
                        </strong>

                        <?php if (!empty($question['required'])): ?>
                            <span class="required">必須</span>
                        <?php endif; ?>
                    </div>

                    <?php if (($question['type'] ?? '') === 'text'): ?>

                        <textarea
                            style="width:100%;margin-top:12px"
                            rows="5"
                            disabled
                        ></textarea>

                    <?php else: ?>

                        <?php foreach (($question['options'] ?? []) as $option): ?>

                            <label class="choice">

                                <input
                                    type="<?= ($question['type'] ?? '') === 'multiple' ? 'checkbox' : 'radio' ?>"
                                    disabled
                                >

                                <?= h($option) ?>

                            </label>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </section>

    <?php endforeach; ?>

</div>

<?php
/* ============================================================
 * 集計
 * ============================================================ */
elseif ($screen === 'analytics'):

$answerRows = array_values(
    array_filter(
        $data['answers'],
        static fn(array $a): bool =>
            (string)($a['survey_id'] ?? '') === $surveyId
    )
);

$sendTargetCount = count($data['customers']);
$answerCount = count($answerRows);

$responseRate =
    $sendTargetCount > 0
    ? round(($answerCount / $sendTargetCount) * 100, 1)
    : 0;

?>

<div class="page-head">
    <div>
        <h1 class="page-title">回答集計・分析</h1>
        <p class="page-description">
            対象アンケート：
            <strong><?= h($survey['title'] ?? '') ?></strong>
        </p>
    </div>

    <div class="actions">
        <a
            class="btn"
            href="<?= h(screen_url('list')) ?>"
        >一覧へ戻る</a>
    </div>
</div>

<div class="stat-grid">

    <div class="stat">
        <div class="stat-label">送信対象者数</div>
        <div class="stat-value">
            <?= h($sendTargetCount) ?>
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">回答数</div>
        <div class="stat-value">
            <?= h($answerCount) ?>
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">未回答数</div>
        <div class="stat-value">
            <?= h(max(0, $sendTargetCount - $answerCount)) ?>
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">回答率</div>
        <div class="stat-value">
            <?= h($responseRate) ?>%
        </div>
    </div>

</div>

<div class="card">

    <h2 class="card-title">設問別集計</h2>

    <?php if ($answerCount === 0): ?>

        <div class="empty">
            現在、回答データはありません
        </div>

    <?php else: ?>

        <?php foreach ($survey['groups'] as $group): ?>

            <h3><?= h($group['title'] ?? '') ?></h3>

            <?php foreach ($group['questions'] as $question): ?>

                <?php
                    $counts = [];

                    foreach (($question['options'] ?? []) as $option) {
                        $counts[$option] = 0;
                    }

                    foreach ($answerRows as $answer) {
                        $value =
                            $answer['answers'][$question['id']]
                            ?? null;

                        if (is_array($value)) {
                            foreach ($value as $v) {
                                if (isset($counts[$v])) {
                                    $counts[$v]++;
                                }
                            }
                        } elseif ($value !== null && isset($counts[$value])) {
                            $counts[$value]++;
                        }
                    }
                ?>

                <div class="card" style="box-shadow:none">

                    <strong>
                        <?= h($question['number'] ?? '') ?>
                        <?= h($question['text'] ?? '') ?>
                    </strong>

                    <?php if (($question['type'] ?? '') === 'text'): ?>

                        <p class="help">
                            自由記述回答は個別回答欄で確認してください。
                        </p>

                    <?php else: ?>

                        <?php foreach ($counts as $option => $count): ?>

                            <div style="margin-top:10px">
                                <?= h($option) ?>：
                                <strong><?= h($count) ?>件</strong>
                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<div class="card">
    <h2 class="card-title">個別回答</h2>

    <?php if (!$answerRows): ?>

        <div class="empty">
            現在、回答データはありません
        </div>

    <?php else: ?>

        <div class="table-wrap">

            <table class="table">

                <thead>
                <tr>
                    <th>回答日時</th>
                    <th>回答内容</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($answerRows as $answer): ?>

                    <tr>
                        <td>
                            <?= h($answer['created_at'] ?? '') ?>
                        </td>

                        <td>
                            <?php foreach (($answer['answers'] ?? []) as $qid => $value): ?>

                                <?php
                                    $label = $qid;

                                    foreach ($survey['groups'] as $g) {
                                        foreach ($g['questions'] as $q) {
                                            if ((string)$q['id'] === (string)$qid) {
                                                $label =
                                                    ($q['number'] ?? '') .
                                                    ' ' .
                                                    ($q['text'] ?? '');
                                            }
                                        }
                                    }
                                ?>

                                <div>
                                    <strong><?= h($label) ?></strong>：

                                    <?php
                                    if (is_array($value)) {
                                        echo h(implode('、', $value));
                                    } else {
                                        echo h((string)$value);
                                    }
                                    ?>
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

<?php
/* ============================================================
 * 送信
 * ============================================================ */
elseif ($screen === 'send'):

?>

<div class="page-head">
    <div>
        <h1 class="page-title">顧客選択・メール送信</h1>
        <p class="page-description">
            対象アンケート：
            <strong><?= h($survey['title'] ?? '') ?></strong>
        </p>
    </div>

    <div class="actions">
        <a
            class="btn"
            href="<?= h(screen_url('list')) ?>"
        >一覧へ戻る</a>
    </div>
</div>

<div class="card">

    <div class="notice notice-info">
        対象アンケートは
        「<?= h($survey['title'] ?? '') ?>」
        に固定されています。
    </div>

    <h2 class="card-title">顧客選択</h2>

    <?php if (!$data['customers']): ?>

        <div class="empty">
            顧客データがありません。<br>
            kintone設定画面から「顧客情報を同期」を実行してください。
        </div>

    <?php else: ?>

        <div class="table-wrap">

            <table class="table">

                <thead>
                <tr>
                    <th>
                        <input
                            type="checkbox"
                            id="selectAll"
                            onclick="toggleCustomers(this.checked)"
                        >
                    </th>
                    <th>組織名</th>
                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th>部署</th>
                    <th>電話</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($data['customers'] as $customer): ?>

                    <tr>
                        <td>
                            <input
                                type="checkbox"
                                class="customer-check"
                                value="<?= h($customer['id'] ?? '') ?>"
                            >
                        </td>

                        <td><?= h($customer['organization'] ?? '') ?></td>
                        <td><?= h($customer['name'] ?? '') ?></td>
                        <td><?= h($customer['email'] ?? '') ?></td>
                        <td><?= h($customer['department'] ?? '') ?></td>
                        <td><?= h($customer['phone'] ?? '') ?></td>
                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>

<div class="card">

    <h2 class="card-title">メール作成</h2>

    <div class="field">
        <label>件名</label>
        <input
            id="mailSubject"
            value="<?= h(($survey['title'] ?? '') . ' ご回答のお願い') ?>"
        >
    </div>

    <div class="field">
        <label>本文</label>
        <textarea id="mailBody">{顧客名} 様

「<?= h($survey['title'] ?? '') ?>」へのご回答をお願いいたします。

{アンケートURL}
</textarea>

        <div class="help">
            使用可能な変数：{顧客名}、{アンケートURL}
        </div>
    </div>

    <div class="form-actions">

        <button
            type="button"
            class="btn btn-primary"
            onclick="sendMailMock()"
        >
            一括送信
        </button>

        <button
            type="button"
            class="btn"
            onclick="alert('再送対象を選択してから実装してください。')"
        >
            再送
        </button>

        <button
            type="button"
            class="btn"
            onclick="alert('リマインド対象を選択してから実装してください。')"
        >
            リマインド
        </button>

    </div>

    <div id="sendResult" style="margin-top:20px"></div>

</div>

<div class="card">

    <h2 class="card-title">送信履歴</h2>

    <?php
        $history = array_reverse(
            array_values(
                array_filter(
                    $data['send_history'],
                    static fn(array $row): bool =>
                        (string)($row['survey_id'] ?? '') === $surveyId
                )
            )
        );
    ?>

    <?php if (!$history): ?>

        <div class="empty">
            送信履歴はありません。
        </div>

    <?php else: ?>

        <div class="table-wrap">

            <table class="table">

                <thead>
                <tr>
                    <th>日時</th>
                    <th>件数</th>
                    <th>結果</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($history as $row): ?>

                    <tr>
                        <td><?= h($row['created_at'] ?? '') ?></td>
                        <td><?= h($row['count'] ?? 0) ?>件</td>
                        <td><?= h($row['result'] ?? '') ?></td>
                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>

<script>
function toggleCustomers(checked) {
    document
        .querySelectorAll('.customer-check')
        .forEach(el => {
            el.checked = checked;
        });
}

function sendMailMock() {
    const selected =
        [...document.querySelectorAll('.customer-check:checked')];

    if (!selected.length) {
        alert('送信対象を選択してください。');
        return;
    }

    if (!confirm(
        selected.length +
        '件にメールを送信します。よろしいですか？'
    )) {
        return;
    }

    /*
     * SMTP実送信処理はSMTP設定・認証方式が
     * 確定した環境で実装する。
     */
    document.getElementById('sendResult').innerHTML =
        '<div class="notice notice-info">' +
        '送信処理を開始しました。選択件数：' +
        selected.length +
        '件' +
        '</div>';
}
</script>

<?php
/* ============================================================
 * 回答
 * ============================================================
 */
elseif ($screen === 'answer'):

if (!$survey):
    redirect_to(screen_url('list'));
endif;

?>

<div class="answer-container">

    <div class="answer-header">
        <div class="answer-title">
            <?= h($survey['title'] ?? '') ?>
        </div>

        <?php if (!empty($survey['description'])): ?>
            <p><?= nl2br(h($survey['description'])) ?></p>
        <?php endif; ?>
    </div>

    <form
        method="post"
        action="<?= h(screen_url('confirm', ['id' => $surveyId])) ?>"
        id="answerForm"
    >

        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

        <?php
            $questionCount = 0;

            foreach ($survey['groups'] as $group) {
                $questionCount += count($group['questions']);
            }
        ?>

        <div class="progress">
            <div style="width:100%"></div>
        </div>

        <?php foreach ($survey['groups'] as $group): ?>

            <div class="card">

                <h2><?= h($group['title'] ?? '') ?></h2>

                <?php foreach ($group['questions'] as $question): ?>

                    <div
                        class="answer-question"
                        data-question-id="<?= h($question['id']) ?>"
                    >

                        <h3>
                            <?= h($question['number'] ?? '') ?>
                            <?= h($question['text'] ?? '') ?>

                            <?php if (!empty($question['required'])): ?>
                                <span class="required">必須</span>
                            <?php endif; ?>
                        </h3>

                        <?php if (($question['type'] ?? '') === 'text'): ?>

                            <textarea
                                name="answer[<?= h($question['id']) ?>]"
                                rows="6"
                                <?= !empty($question['required']) ? 'required' : '' ?>
                            ></textarea>

                        <?php elseif (($question['type'] ?? '') === 'multiple'): ?>

                            <?php foreach (($question['options'] ?? []) as $option): ?>

                                <label class="choice">
                                    <input
                                        type="checkbox"
                                        name="answer[<?= h($question['id']) ?>][]"
                                        value="<?= h($option) ?>"
                                    >
                                    <?= h($option) ?>
                                </label>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <?php foreach (($question['options'] ?? []) as $option): ?>

                                <label class="choice">
                                    <input
                                        type="radio"
                                        name="answer[<?= h($question['id']) ?>]"
                                        value="<?= h($option) ?>"
                                        <?= !empty($question['required']) ? 'required' : '' ?>
                                    >
                                    <?= h($option) ?>
                                </label>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>

        <div class="answer-actions">

            <a
                class="btn"
                href="<?= h(screen_url('list')) ?>"
            >
                戻る
            </a>

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
/* ============================================================
 * 回答確認
 * ============================================================
 */
elseif ($screen === 'confirm'):

$postedAnswers = $_POST['answer'] ?? [];

if (!is_array($postedAnswers)) {
    $postedAnswers = [];
}

$_SESSION['_answer_draft'] = [
    'survey_id' => $surveyId,
    'answers' => $postedAnswers,
];

?>

<div class="answer-container">

    <div class="page-head">
        <div>
            <h1 class="page-title">回答確認</h1>
            <p class="page-description">
                送信前に回答内容を確認してください。
            </p>
        </div>
    </div>

    <div class="card">

        <?php foreach ($survey['groups'] as $group): ?>

            <h2><?= h($group['title'] ?? '') ?></h2>

            <?php foreach ($group['questions'] as $question): ?>

                <?php
                    $value =
                        $postedAnswers[$question['id']]
                        ?? '';

                    if (is_array($value)) {
                        $displayValue =
                            implode('、', $value);
                    } else {
                        $displayValue =
                            (string)$value;
                    }
                ?>

                <div class="preview-question">

                    <strong>
                        <?= h($question['number'] ?? '') ?>
                        <?= h($question['text'] ?? '') ?>
                    </strong>

                    <div style="margin-top:8px">
                        <?= nl2br(h($displayValue)) ?>
                    </div>

                </div>

            <?php endforeach; ?>

        <?php endforeach; ?>

    </div>

    <form method="post">

        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="submit_answer">
        <input type="hidden" name="survey_id" value="<?= h($surveyId) ?>">
        <input
            type="hidden"
            name="answers_json"
            value="<?= h(json_encode_safe($postedAnswers)) ?>"
        >

        <div class="answer-actions">

            <a
                class="btn"
                href="<?= h(screen_url('answer', ['id' => $surveyId])) ?>"
            >
                回答を修正
            </a>

            <button
                class="btn btn-primary"
                type="submit"
                onclick="return confirm('回答を送信しますか？');"
            >
                回答を送信
            </button>

        </div>

    </form>

</div>

<?php
/* ============================================================
 * 回答完了
 * ============================================================
 */
elseif ($screen === 'complete'):

?>

<div class="answer-container">

    <div class="card" style="text-align:center;padding:50px 25px">

        <div style="
            width:64px;
            height:64px;
            margin:0 auto 18px;
            border-radius:50%;
            background:#dcfce7;
            color:#166534;
            display:grid;
            place-items:center;
            font-size:32px;
            font-weight:800;
        ">
            ✓
        </div>

        <h1>回答完了</h1>

        <p>
            ご回答ありがとうございました。<br>
            回答は正常に受け付けられました。
        </p>

    </div>

</div>

<?php endif; ?>

</main>

<script>
/*
 * ============================================================
 * 共通JavaScript
 * ============================================================
 */

const kintoneForm =
    document.getElementById('kintoneForm');

if (kintoneForm) {

    kintoneForm.addEventListener('submit', function(event) {

        const submitter =
            event.submitter;

        if (
            submitter
            && submitter.value === 'test_kintone'
        ) {

            const button =
                document.getElementById(
                    'testKintoneButton'
                );

            const spinner =
                document.getElementById(
                    'testKintoneSpinner'
                );

            const text =
                document.getElementById(
                    'testKintoneText'
                );

            if (button) {
                button.disabled = true;
            }

            if (spinner) {
                spinner.style.display =
                    'inline-block';
            }

            if (text) {
                text.textContent =
                    '接続テスト中...';
            }

            /*
             * 実際のPOST送信を継続。
             * サーバー側で通信して結果画面を生成する。
             */
        }
    });
}

/*
 * Enterキーによる一覧検索
 */
const listSearch =
    document.getElementById('q');

if (listSearch) {
    listSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
}
</script>

</body>
</html>
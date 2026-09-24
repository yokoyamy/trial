<?php
namespace yokoyamy\trial\NewApp;

use RuntimeException;

/**
 * アンケート業務運営アプリ
 * 単一 index.php
 * PHP 8.4 / 8.5
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax'
    ]);
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

const APP_SESSION_KEY = 'yokoyamy_trial_newapp';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';

if (!isset($_SESSION[APP_SESSION_KEY]) || !is_array($_SESSION[APP_SESSION_KEY])) {
    $_SESSION[APP_SESSION_KEY] = [];
}

if (
    !isset($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
    !is_string($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
    $_SESSION[APP_SESSION_KEY]['csrf_token'] === ''
) {
    $_SESSION[APP_SESSION_KEY]['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * 安全な文字列エスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * JSONファイルを安全に読み込む
 */
function read_json_file(string $file, array $default): array
{
    if (!is_file($file)) {
        return $default;
    }

    $contents = @file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $data = json_decode($contents, true);

    return is_array($data) ? $data : $default;
}

/**
 * JSONファイルへ保存
 */
function write_json_file(string $file, array $data): void
{
    if (!is_dir(DATA_DIR)) {
        if (!@mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存用フォルダを作成できません。');
        }
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('データの保存形式を作成できません。');
    }

    $tmp = $file . '.tmp';

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('データを保存できません。');
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データを確定保存できません。');
    }

    @chmod($file, 0600);
}

/**
 * JSONレスポンス
 */
function json_response(array $response): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * CSRF確認
 */
function verify_csrf(): void
{
    $token = '';

    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (isset($_POST['csrf_token'])) {
        $token = (string)$_POST['csrf_token'];
    }

    $sessionToken = (string)($_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '');

    if (
        $token === '' ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $token)
    ) {
        json_response([
            'success' => false,
            'message' => 'セッションの確認に失敗しました。画面を再読み込みしてから、もう一度お試しください。'
        ]);
    }
}

/**
 * POST JSON取得
 */
function get_request_json(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

/**
 * 安全なエラー文言
 */
function safe_error_message(string $message): string
{
    $message = trim($message);

    $message = preg_replace(
        '/(?:password|passwd|pwd|token|authorization|secret)\s*[:=]\s*[^\s,;]+/i',
        '$1: [非表示]',
        $message
    );

    return $message !== ''
        ? $message
        : '処理中にエラーが発生しました。';
}

/**
 * HTTPレスポンスヘッダー取得
 */
function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();

        if (is_array($headers)) {
            return $headers;
        }
    }

    return [];
}

/**
 * HTTPステータス取得
 */
function get_response_status(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $header, $matches)) {
            return (int)$matches[1];
        }
    }

    return 0;
}

/**
 * kintone URL整形
 */
function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);

    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = trim((string)$domain, "/ \t\n\r\0\x0B");

    if ($domain === '') {
        throw new RuntimeException('kintoneの利用先を入力してください。');
    }

    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/', $domain)) {
        throw new RuntimeException('kintoneの利用先が正しくありません。');
    }

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * プロキシ値を host:port として検証
 */
function normalize_proxy(string $proxy): string
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return '';
    }

    if (!preg_match('/^([a-zA-Z0-9.-]+):([0-9]{1,5})$/', $proxy, $matches)) {
        throw new RuntimeException(
            'プロキシは「ホスト名:ポート番号」の形式で入力してください。'
        );
    }

    $port = (int)$matches[2];

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('プロキシのポート番号が正しくありません。');
    }

    return $matches[1] . ':' . $port;
}

/**
 * kintone認証ヘッダー
 */
function make_cybozu_auth_header(string $loginName, string $password): string
{
    $loginName = trim($loginName);
    $password = trim($password);

    $auth = base64_encode($loginName . ':' . $password);

    return 'X-Cybozu-Authorization: ' . $auth;
}

/**
 * kintone API通信
 * cURLは使用しない。
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    $payload,
    string $proxy
): array {
    $method = strtoupper($method);

    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 30,
        'protocol_version' => 1.1
    ];

    if ($method !== 'GET' && $payload !== null) {
        $body = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : (string)$payload;

        if ($body === false) {
            throw new RuntimeException('送信データを作成できません。');
        }

        $http['content'] = $body;
    }

    $contextOptions = [
        'http' => $http,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $proxy = normalize_proxy($proxy);

    /*
     * プロキシが設定されている場合は必ず
     * proxy + request_fulluri を適用する。
     */
    if ($proxy !== '') {
        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $responseBody = @file_get_contents($url, false, $context);
    $headersReceived = get_safe_response_headers();
    $status = get_response_status($headersReceived);

    $decoded = [];

    if ($responseBody !== false && trim($responseBody) !== '') {
        $json = json_decode($responseBody, true);

        if (is_array($json)) {
            $decoded = $json;
        }
    }

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => $decoded
        ];
    }

    $message = 'kintone APIへの通信に失敗しました。';

    if (isset($decoded['message']) && is_string($decoded['message'])) {
        $message = $decoded['message'];
    }

    $details = [];

    if (isset($decoded['code']) && is_string($decoded['code'])) {
        $details[] = 'コード: ' . $decoded['code'];
    }

    if (
        isset($decoded['errors']) &&
        is_array($decoded['errors'])
    ) {
        foreach ($decoded['errors'] as $field => $error) {
            if (!is_array($error)) {
                continue;
            }

            if (
                isset($error['messages']) &&
                is_array($error['messages'])
            ) {
                foreach ($error['messages'] as $errorMessage) {
                    if (is_string($errorMessage)) {
                        $details[] = (string)$field . ': ' . $errorMessage;
                    }
                }
            }
        }
    }

    if (!empty($details)) {
        $message .= ' ' . implode(' / ', $details);
    }

    if ($status > 0) {
        $message .= '（HTTP ' . $status . '）';
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => safe_error_message($message),
        'data' => $decoded
    ];
}

/**
 * SMTPレスポンス読み取り
 */
function smtp_read($socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

/**
 * SMTPコマンド
 */
function smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");

    $response = smtp_read($socket);

    $code = (int)substr(trim($response), 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException(
            'メールサーバーとの通信に失敗しました。'
        );
    }

    return $response;
}

/**
 * メールアドレス検証
 */
function validate_email_address(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * SMTPでテストメールを送信
 *
 * STARTTLS:
 *   通常接続 → EHLO → STARTTLS → TLS化 → EHLO
 *
 * SSL/TLS:
 *   ssl:// で接続
 */
function smtp_send_test_mail(array $settings, string $to): void
{
    $server = trim((string)($settings['smtp'] ?? ''));
    $port = (int)($settings['port'] ?? 0);
    $security = (string)($settings['security'] ?? 'STARTTLS');
    $username = trim((string)($settings['username'] ?? ''));
    $password = (string)($settings['password'] ?? '');
    $from = trim((string)($settings['from'] ?? ''));
    $fromName = trim((string)($settings['fromName'] ?? ''));

    if ($server === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('SMTPサーバーとポート番号を確認してください。');
    }

    if (!validate_email_address($from)) {
        throw new RuntimeException('送信元メールアドレスが正しくありません。');
    }

    if (!validate_email_address($to)) {
        throw new RuntimeException('テスト送信先メールアドレスが正しくありません。');
    }

    if (!in_array($security, ['なし', 'STARTTLS', 'SSL/TLS'], true)) {
        throw new RuntimeException('メール接続方式が正しくありません。');
    }

    $remote = $security === 'SSL/TLS'
        ? 'ssl://' . $server . ':' . $port
        : 'tcp://' . $server . ':' . $port;

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバーへ接続できませんでした。サーバー名、ポート番号、ネットワーク環境を確認してください。'
        );
    }

    stream_set_timeout($socket, 20);

    try {
        $greeting = smtp_read($socket);

        if ((int)substr(trim($greeting), 0, 3) !== 220) {
            throw new RuntimeException('SMTPサーバーから正常な応答がありません。');
        }

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );

        if ($security === 'STARTTLS') {
            smtp_command(
                $socket,
                'STARTTLS',
                [220]
            );

            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'STARTTLSによる暗号化を開始できませんでした。'
                );
            }

            smtp_command(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if ($username !== '') {
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

        $subject = 'アンケート業務運営：テストメール';

        $encodedSubject = '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $displayFrom = $from;

        if ($fromName !== '') {
            $displayFrom =
                '=?UTF-8?B?' .
                base64_encode($fromName) .
                '?= <' .
                $from .
                '>';
        }

        $body =
            "アンケート業務運営アプリからのテストメールです。\r\n\r\n" .
            "このメールを受信できれば、メール送信設定は利用可能です。\r\n";

        $mailData =
            'From: ' . $displayFrom . "\r\n" .
            'To: ' . $to . "\r\n" .
            'Subject: ' . $encodedSubject . "\r\n" .
            'MIME-Version: 1.0' . "\r\n" .
            'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
            'Content-Transfer-Encoding: 8bit' . "\r\n" .
            "\r\n" .
            $body .
            "\r\n.";

        fwrite($socket, $mailData . "\r\n");

        $response = smtp_read($socket);

        if ((int)substr(trim($response), 0, 3) !== 250) {
            throw new RuntimeException(
                'メール送信を完了できませんでした。'
            );
        }

        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

/**
 * 初期設定
 */
function default_settings(): array
{
    return [
        'mail' => [
            'smtp' => '',
            'port' => '587',
            'security' => 'STARTTLS',
            'username' => '',
            'password' => '',
            'from' => '',
            'fromName' => 'アンケート事務局',
            'testTo' => ''
        ],
        'kintone' => [
            'domain' => '',
            'appId' => '',
            'loginName' => '',
            'password' => '',
            'proxy' => '',
            'nameField' => '',
            'emailField' => '',
            'companyField' => '',
            'codeField' => ''
        ]
    ];
}

/**
 * 初期アンケート
 */
function default_surveys(): array
{
    return [
        [
            'id' => 1,
            'name' => '新商品アンケート',
            'description' => '新商品の利用状況とご意見をお聞きするアンケートです。',
            'status' => 'open',
            'created' => '2026-09-01',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'answers' => 0,
            'sent' => 0,
            'updated' => '2026-09-24',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => 101,
                    'name' => 'ご利用状況',
                    'questions' => [
                        [
                            'id' => 1001,
                            'text' => '当社の商品を利用したことがありますか？',
                            'type' => 'single',
                            'required' => true,
                            'options' => [
                                ['text' => 'はい', 'branch' => ''],
                                ['text' => 'いいえ', 'branch' => '']
                            ]
                        ],
                        [
                            'id' => 1002,
                            'text' => '商品についての満足度を教えてください。',
                            'type' => 'single',
                            'required' => true,
                            'options' => [
                                ['text' => '満足', 'branch' => ''],
                                ['text' => '普通', 'branch' => ''],
                                ['text' => '不満', 'branch' => '']
                            ]
                        ]
                    ]
                ],
                [
                    'id' => 102,
                    'name' => 'ご意見',
                    'questions' => [
                        [
                            'id' => 1003,
                            'text' => '今後の商品についてご意見をお聞かせください。',
                            'type' => 'free',
                            'required' => false,
                            'options' => []
                        ]
                    ]
                ]
            ]
        ]
    ];
}

$settings = read_json_file(SETTINGS_FILE, default_settings());
$surveys = read_json_file(SURVEYS_FILE, default_surveys());

/**
 * アクション処理
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = isset($_POST['action'])
        ? (string)$_POST['action']
        : '';

    $request = get_request_json();

    if (!empty($request['action'])) {
        $action = (string)$request['action'];
    }

    try {
        switch ($action) {
            /**
             * メール設定保存
             */
            case 'save_mail_settings':
                $mail = isset($request['mail']) && is_array($request['mail'])
                    ? $request['mail']
                    : $_POST;

                $smtp = trim((string)($mail['smtp'] ?? ''));
                $port = trim((string)($mail['port'] ?? ''));
                $security = (string)($mail['security'] ?? 'STARTTLS');
                $username = trim((string)($mail['username'] ?? ''));
                $password = (string)($mail['password'] ?? '');
                $from = trim((string)($mail['from'] ?? ''));
                $fromName = trim((string)($mail['fromName'] ?? ''));
                $testTo = trim((string)($mail['testTo'] ?? ''));

                if ($smtp === '' || $port === '' || $from === '') {
                    throw new RuntimeException(
                        'SMTPサーバー、ポート番号、送信元メールアドレスを入力してください。'
                    );
                }

                if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
                    throw new RuntimeException(
                        'SMTPポート番号が正しくありません。'
                    );
                }

                if (!in_array($security, ['なし', 'STARTTLS', 'SSL/TLS'], true)) {
                    throw new RuntimeException(
                        '接続方式が正しくありません。'
                    );
                }

                if (!validate_email_address($from)) {
                    throw new RuntimeException(
                        '送信元メールアドレスが正しくありません。'
                    );
                }

                if (!isset($settings['mail']) || !is_array($settings['mail'])) {
                    $settings['mail'] = [];
                }

                $settings['mail']['smtp'] = $smtp;
                $settings['mail']['port'] = $port;
                $settings['mail']['security'] = $security;
                $settings['mail']['username'] = $username;

                if ($password !== '') {
                    $settings['mail']['password'] = $password;
                }

                $settings['mail']['from'] = $from;
                $settings['mail']['fromName'] = $fromName;
                $settings['mail']['testTo'] = $testTo;

                write_json_file(SETTINGS_FILE, $settings);

                json_response([
                    'success' => true,
                    'message' => 'メール設定を保存しました。'
                ]);

            /**
             * テストメール送信
             */
            case 'test_mail':
                $mail = $settings['mail'] ?? [];

                $to = trim((string)($request['to'] ?? ($mail['testTo'] ?? '')));

                if (!validate_email_address($to)) {
                    throw new RuntimeException(
                        'テスト送信先メールアドレスを入力してください。'
                    );
                }

                smtp_send_test_mail($mail, $to);

                $settings['mail']['testTo'] = $to;
                write_json_file(SETTINGS_FILE, $settings);

                json_response([
                    'success' => true,
                    'message' => 'テストメールを送信しました。受信先をご確認ください。'
                ]);

            /**
             * kintone設定保存
             */
            case 'save_kintone_settings':
                $kt = isset($request['kintone']) && is_array($request['kintone'])
                    ? $request['kintone']
                    : [];

                $domain = trim((string)($kt['domain'] ?? ''));
                $appId = trim((string)($kt['appId'] ?? ''));
                $loginName = trim((string)($kt['loginName'] ?? ''));
                $password = (string)($kt['password'] ?? '');
                $proxy = trim((string)($kt['proxy'] ?? ''));

                if ($domain === '' || $appId === '' || $loginName === '') {
                    throw new RuntimeException(
                        'kintoneの利用先、アプリID、ログイン名を入力してください。'
                    );
                }

                if (!ctype_digit($appId)) {
                    throw new RuntimeException(
                        'アプリIDは数字で入力してください。'
                    );
                }

                kintone_build_url($domain, '/k/v1/apps.json');

                $proxy = normalize_proxy($proxy);

                if (!isset($settings['kintone']) || !is_array($settings['kintone'])) {
                    $settings['kintone'] = [];
                }

                $settings['kintone']['domain'] = $domain;
                $settings['kintone']['appId'] = $appId;
                $settings['kintone']['loginName'] = $loginName;
                $settings['kintone']['proxy'] = $proxy;

                if ($password !== '') {
                    $settings['kintone']['password'] = $password;
                }

                foreach ([
                    'nameField',
                    'emailField',
                    'companyField',
                    'codeField'
                ] as $fieldName) {
                    if (isset($kt[$fieldName])) {
                        $settings['kintone'][$fieldName] =
                            trim((string)$kt[$fieldName]);
                    }
                }

                write_json_file(SETTINGS_FILE, $settings);

                json_response([
                    'success' => true,
                    'message' => 'kintone設定を保存しました。'
                ]);

            /**
             * kintone接続＋アプリ項目一覧取得
             */
            case 'test_kintone':
                $kt = $settings['kintone'] ?? [];

                $domain = trim((string)($kt['domain'] ?? ''));
                $appId = trim((string)($kt['appId'] ?? ''));
                $loginName = trim((string)($kt['loginName'] ?? ''));
                $password = (string)($kt['password'] ?? '');
                $proxy = trim((string)($kt['proxy'] ?? ''));

                if ($domain === '' || $appId === '' || $loginName === '' || $password === '') {
                    throw new RuntimeException(
                        'kintoneの利用先、アプリID、ログイン名、パスワードを確認してください。'
                    );
                }

                $proxy = normalize_proxy($proxy);

                $headers = [
                    make_cybozu_auth_header($loginName, $password),
                    'Accept: application/json'
                ];

                $url = kintone_build_url(
                    $domain,
                    '/k/v1/app/form/fields.json'
                );

                $query = http_build_query(
                    [
                        'app' => $appId,
                        'lang' => 'ja'
                    ],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );

                $result = kintone_api_request(
                    'GET',
                    $url . '?' . $query,
                    $headers,
                    null,
                    $proxy
                );

                if (!$result['success']) {
                    throw new RuntimeException(
                        safe_error_message((string)$result['message'])
                    );
                }

                $properties = [];

                if (
                    isset($result['data']['properties']) &&
                    is_array($result['data']['properties'])
                ) {
                    $properties = $result['data']['properties'];
                }

                $fields = [];

                foreach ($properties as $code => $property) {
                    if (!is_array($property)) {
                        continue;
                    }

                    $fields[] = [
                        'code' => (string)$code,
                        'label' => (string)($property['label'] ?? $code),
                        'type' => (string)($property['type'] ?? '')
                    ];
                }

                usort(
                    $fields,
                    static function (array $a, array $b): int {
                        return strcmp($a['code'], $b['code']);
                    }
                );

                json_response([
                    'success' => true,
                    'message' => 'kintoneへの接続に成功しました。',
                    'fields' => $fields,
                    'fieldCount' => count($fields)
                ]);

            /**
             * kintone顧客一覧取得
             */
            case 'get_customers':
                $kt = $settings['kintone'] ?? [];

                $domain = trim((string)($kt['domain'] ?? ''));
                $appId = trim((string)($kt['appId'] ?? ''));
                $loginName = trim((string)($kt['loginName'] ?? ''));
                $password = (string)($kt['password'] ?? '');
                $proxy = trim((string)($kt['proxy'] ?? ''));

                $nameField = trim((string)($kt['nameField'] ?? ''));
                $emailField = trim((string)($kt['emailField'] ?? ''));
                $companyField = trim((string)($kt['companyField'] ?? ''));
                $codeField = trim((string)($kt['codeField'] ?? ''));

                if (
                    $domain === '' ||
                    $appId === '' ||
                    $loginName === '' ||
                    $password === ''
                ) {
                    throw new RuntimeException(
                        'kintone設定を確認してください。'
                    );
                }

                if ($nameField === '' || $emailField === '') {
                    throw new RuntimeException(
                        '顧客名項目とメールアドレス項目を設定してください。'
                    );
                }

                $proxy = normalize_proxy($proxy);

                $headers = [
                    make_cybozu_auth_header($loginName, $password),
                    'Accept: application/json'
                ];

                $queryString = 'order by $id asc limit 500';

                $params = [
                    'app' => $appId,
                    'query' => $queryString
                ];

                $url = kintone_build_url(
                    $domain,
                    '/k/v1/records.json'
                );

                $query = http_build_query(
                    $params,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );

                $result = kintone_api_request(
                    'GET',
                    $url . '?' . $query,
                    $headers,
                    null,
                    $proxy
                );

                if (!$result['success']) {
                    throw new RuntimeException(
                        safe_error_message((string)$result['message'])
                    );
                }

                $records = [];

                if (
                    isset($result['data']['records']) &&
                    is_array($result['data']['records'])
                ) {
                    $records = $result['data']['records'];
                }

                $customers = [];

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $getValue = static function (
                        array $record,
                        string $field
                    ): string {
                        if (
                            !isset($record[$field]) ||
                            !is_array($record[$field])
                        ) {
                            return '';
                        }

                        $value = $record[$field]['value'] ?? '';

                        if (is_array($value)) {
                            $values = [];

                            foreach ($value as $item) {
                                if (is_array($item) && isset($item['name'])) {
                                    $values[] = (string)$item['name'];
                                } elseif (is_scalar($item)) {
                                    $values[] = (string)$item;
                                }
                            }

                            return implode(', ', $values);
                        }

                        return is_scalar($value)
                            ? (string)$value
                            : '';
                    };

                    $customers[] = [
                        'name' => $getValue($record, $nameField),
                        'email' => $getValue($record, $emailField),
                        'company' => $companyField !== ''
                            ? $getValue($record, $companyField)
                            : '',
                        'code' => $codeField !== ''
                            ? $getValue($record, $codeField)
                            : ''
                    ];
                }

                json_response([
                    'success' => true,
                    'message' => 'kintoneから顧客一覧を取得しました。',
                    'customers' => $customers,
                    'count' => count($customers)
                ]);

            /**
             * アンケート保存
             */
            case 'save_surveys':
                $incoming = $request['surveys'] ?? null;

                if (!is_array($incoming)) {
                    throw new RuntimeException(
                        'アンケートデータが正しくありません。'
                    );
                }

                write_json_file(SURVEYS_FILE, $incoming);
                $surveys = $incoming;

                json_response([
                    'success' => true,
                    'message' => 'アンケートを保存しました。'
                ]);

            default:
                json_response([
                    'success' => false,
                    'message' => '指定された処理はありません。'
                ]);
        }
    } catch (\Throwable $e) {
        json_response([
            'success' => false,
            'message' => safe_error_message($e->getMessage())
        ]);
    }
}

$csrfToken = (string)$_SESSION[APP_SESSION_KEY]['csrf_token'];

$mail = $settings['mail'] ?? [];
$kintone = $settings['kintone'] ?? [];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h($csrfToken) ?>">
<title>アンケート業務運営</title>

<style>
*{box-sizing:border-box}
body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif;
    color:#263238;
    background:#f4f6f8;
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.55}
.topbar{
    min-height:60px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px;
}
.logo{font-size:18px;font-weight:bold;white-space:nowrap}
.main-nav{
    display:flex;
    min-height:60px;
    align-items:center;
    gap:2px;
    flex-wrap:wrap;
}
.main-nav button{
    min-height:60px;
    padding:0 17px;
    color:#dce7f3;
    background:transparent;
    border:0;
}
.main-nav button:hover,
.main-nav button.active{
    background:#31557f;
    color:#fff
}
.app{
    max-width:1440px;
    margin:0 auto;
    padding:24px
}
.hidden{display:none!important}
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:20px;
}
.page-header h1{margin:0;font-size:25px}
.subtext{color:#718096;font-size:13px;margin-top:5px}
.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px;
}
.btn:hover{background:#f7fafc}
.btn-primary{background:#2878c8;border-color:#2878c8;color:#fff}
.btn-primary:hover{background:#2068ad}
.btn-danger{border-color:#e05a5a;color:#c53f3f;background:#fff}
.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:18px;
}
.card-title{font-size:17px;font-weight:bold;margin-bottom:15px}
.table{
    width:100%;
    border-collapse:collapse
}
.table th,.table td{
    padding:12px 10px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    vertical-align:middle;
    font-size:13px;
}
.table th{background:#f8fafc;color:#52606d;font-weight:bold}
.table tr:hover td{background:#fbfdff}
.empty{text-align:center!important;color:#8a98a5;padding:40px!important}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
.field{margin-bottom:15px}
.field label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:6px;
    color:#455563;
}
.field input,
.field textarea,
.field select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:9px 10px;
    background:#fff;
}
.field textarea{min-height:90px;resize:vertical}
.notice{
    padding:11px 13px;
    border-radius:5px;
    background:#edf6ff;
    border:1px solid #c9e2fa;
    color:#2b5f8a;
    font-size:13px;
    margin-bottom:15px;
}
.notice.success{background:#edf9f1;border-color:#c9ead5;color:#267348}
.notice.warning{background:#fff8e6;border-color:#f0dfae;color:#8a6408}
.notice.error{background:#fff0f0;border-color:#f0c5c5;color:#b43b3b}
.status-line{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    background:#f7f9fb;
    border-radius:5px;
    margin-bottom:15px;
}
.status-dot{
    width:9px;
    height:9px;
    border-radius:50%;
    background:#9aa7b3;
}
.status-dot.ok{background:#2f9e61}
.status-dot.warn{background:#d39b25}
.settings-tabs{
    display:flex;
    gap:5px;
    margin-bottom:18px;
}
.settings-tabs button{
    border:1px solid #d6dee6;
    background:#fff;
    padding:9px 16px;
    border-radius:5px;
}
.settings-tabs button.active{
    background:#2878c8;
    color:#fff;
    border-color:#2878c8
}
.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:15px;
}
.loading{
    position:relative;
    color:transparent!important;
    pointer-events:none;
}
.loading:after{
    content:"";
    position:absolute;
    width:14px;
    height:14px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    left:50%;
    top:50%;
    margin:-7px 0 0 -7px;
    animation:spin .7s linear infinite;
}
.btn:not(.btn-primary).loading:after{
    border-color:rgba(40,120,200,.25);
    border-top-color:#2878c8;
}
@keyframes spin{
    to{transform:rotate(360deg)}
}
.field-list{
    max-height:420px;
    overflow:auto;
    border:1px solid #dfe5eb;
    border-radius:5px;
}
.field-row{
    display:grid;
    grid-template-columns:1.2fr 1fr 140px;
    gap:10px;
    padding:9px 12px;
    border-bottom:1px solid #e6ebef;
    font-size:13px;
}
.field-row:last-child{border-bottom:0}
.field-row.header{
    background:#f7f9fb;
    font-weight:bold;
}
.customer-toolbar{
    display:flex;
    gap:8px;
    margin-bottom:12px;
}
.customer-toolbar input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px
}
.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:12px;
    font-size:11px;
    font-weight:bold;
}
.badge-open{background:#e6f6ed;color:#237a49}
.badge-draft{background:#edf2f7;color:#66788a}
.badge-end{background:#fdecec;color:#b43b3b}
.survey-card{
    border:1px solid #dfe5eb;
    background:#fff;
    border-radius:7px;
    padding:18px;
    margin-bottom:15px;
}
.survey-card h3{margin:0 0 8px}
.group{
    border:1px solid #e1e7ec;
    border-radius:6px;
    margin:12px 0;
    overflow:hidden;
}
.group-header{
    padding:10px 12px;
    background:#f7f9fb;
    font-weight:bold;
}
.question{
    padding:12px;
    border-top:1px solid #e6ebef;
}
.question-title{
    display:flex;
    gap:10px;
    align-items:center;
}
.question-number{
    color:#2878c8;
    font-weight:bold;
    min-width:45px;
}
.question-options{
    margin:8px 0 0 55px;
}
.option{
    padding:4px 0;
    color:#52606d;
}
.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(20,35,50,.45);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:1000;
}
.modal{
    width:min(760px,calc(100% - 30px));
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:8px;
    box-shadow:0 15px 50px rgba(0,0,0,.25);
}
.modal-header{
    padding:16px 20px;
    border-bottom:1px solid #e3e8ed;
    display:flex;
    justify-content:space-between;
}
.modal-body{padding:20px}
.modal-footer{
    padding:13px 20px;
    border-top:1px solid #e3e8ed;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}
.toast{
    position:fixed;
    right:25px;
    bottom:25px;
    background:#263238;
    color:#fff;
    padding:12px 18px;
    border-radius:5px;
    box-shadow:0 5px 20px rgba(0,0,0,.2);
    opacity:0;
    transform:translateY(10px);
    transition:.2s;
    pointer-events:none;
    z-index:2000;
}
.toast.show{opacity:1;transform:translateY(0)}
@media(max-width:800px){
    .topbar{
        padding:0 10px;
        gap:8px;
        flex-direction:column;
        align-items:flex-start;
    }
    .main-nav{width:100%;overflow:auto}
    .main-nav button{padding:0 10px}
    .app{padding:14px}
    .form-grid{grid-template-columns:1fr}
    .field-row{grid-template-columns:1fr}
    .customer-toolbar{flex-direction:column}
    .page-header{align-items:flex-start;flex-direction:column}
}
</style>
</head>

<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>

    <nav class="main-nav">
        <button id="nav-list" data-page="page-list">アンケート一覧</button>
        <button id="nav-create" data-page="page-editor">アンケート作成</button>
        <button id="nav-customers" data-page="page-customers">顧客一覧</button>
        <button id="nav-settings" data-page="page-settings">設定</button>
    </nav>
</header>

<main class="app">

<section id="page-list">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>
        <button id="create-survey" class="btn btn-primary">
            ＋ アンケート作成
        </button>
    </div>

    <div id="survey-list"></div>
</section>

<section id="page-editor" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="editor-title">アンケート作成</h1>
            <div class="subtext">
                アンケート全体を確認しながら編集します
            </div>
        </div>
    </div>

    <div class="card">
        <div class="form-grid">
            <div class="field">
                <label>アンケート名 *</label>
                <input id="survey-name">
            </div>

            <div class="field">
                <label>公開状態</label>
                <select id="survey-status">
                    <option value="draft">下書き</option>
                    <option value="open">公開中</option>
                    <option value="end">終了</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label>説明</label>
            <textarea id="survey-description"></textarea>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>公開開始日</label>
                <input id="survey-start" type="date">
            </div>

            <div class="field">
                <label>公開終了日</label>
                <input id="survey-end" type="date">
            </div>
        </div>

        <div class="field">
            <label>質問番号</label>

            <select id="survey-numbering">
                <option value="global">全体で通番（Q1、Q2、Q3…）</option>
                <option value="group">グループごと（Q1-1、Q1-2…）</option>
            </select>
        </div>
    </div>

    <div id="editor-groups"></div>

    <div class="actions">
        <button id="add-group" class="btn btn-primary">
            ＋ グループ追加
        </button>
    </div>

    <div class="actions">
        <button id="editor-back" class="btn">一覧へ戻る</button>
        <button id="save-survey" class="btn btn-primary">
            保存
        </button>
    </div>
</section>

<section id="page-detail" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="detail-title"></h1>
            <div class="subtext" id="detail-subtitle"></div>
        </div>

        <div class="actions">
            <button id="detail-edit" class="btn">編集</button>
            <button id="detail-send" class="btn btn-primary">送信</button>
            <button id="detail-back" class="btn">一覧へ戻る</button>
        </div>
    </div>

    <div id="detail-body"></div>
</section>

<section id="page-customers" class="hidden">
    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">
                kintoneの顧客管理アプリから取得します
            </div>
        </div>

        <button id="customers-settings" class="btn">
            kintone設定
        </button>
    </div>

    <div id="customer-status"></div>

    <div class="card">
        <div class="customer-toolbar">
            <input
                id="customer-search"
                placeholder="顧客名・メールアドレス・会社名などで検索"
            >
            <button id="refresh-customers" class="btn">
                顧客一覧を更新
            </button>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>顧客名</th>
                    <th>メールアドレス</th>
                    <th>会社名</th>
                    <th>顧客番号</th>
                </tr>
            </thead>
            <tbody id="customer-body"></tbody>
        </table>
    </div>
</section>

<section id="page-settings" class="hidden">
    <div class="page-header">
        <div>
            <h1>設定</h1>
            <div class="subtext">
                メール送信とkintone接続を設定します
            </div>
        </div>
    </div>

    <div class="settings-tabs">
        <button id="tab-mail">メール送信設定</button>
        <button id="tab-kintone">kintone設定</button>
    </div>

    <div id="settings-content"></div>
</section>

</main>

<div id="modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <strong id="modal-title"></strong>
            <button id="modal-close" class="btn">閉じる</button>
        </div>

        <div id="modal-body" class="modal-body"></div>

        <div id="modal-footer" class="modal-footer"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var surveys = <?= json_encode(
        $surveys,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?>;

    var settings = <?= json_encode(
        [
            'mail' => [
                'smtp' => (string)($mail['smtp'] ?? ''),
                'port' => (string)($mail['port'] ?? '587'),
                'security' => (string)($mail['security'] ?? 'STARTTLS'),
                'username' => (string)($mail['username'] ?? ''),
                'from' => (string)($mail['from'] ?? ''),
                'fromName' => (string)($mail['fromName'] ?? ''),
                'testTo' => (string)($mail['testTo'] ?? '')
            ],
            'kintone' => [
                'domain' => (string)($kintone['domain'] ?? ''),
                'appId' => (string)($kintone['appId'] ?? ''),
                'loginName' => (string)($kintone['loginName'] ?? ''),
                'proxy' => (string)($kintone['proxy'] ?? ''),
                'nameField' => (string)($kintone['nameField'] ?? ''),
                'emailField' => (string)($kintone['emailField'] ?? ''),
                'companyField' => (string)($kintone['companyField'] ?? ''),
                'codeField' => (string)($kintone['codeField'] ?? '')
            ]
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?>;

    var customers = [];
    var currentSurveyId = null;
    var currentSettingsTab = 'mail';
    var nextId = 10000;

    function get(id) {
        return document.getElementById(id);
    }

    function escapeHtml(value) {
        var text = value === null || value === undefined
            ? ''
            : String(value);

        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showToast(message) {
        var toast = get('toast');

        if (!toast) {
            return;
        }

        toast.textContent = message;
        toast.classList.add('show');

        window.setTimeout(function () {
            toast.classList.remove('show');
        }, 2500);
    }

    function showModal(title, message) {
        var modal = get('modal');
        var titleEl = get('modal-title');
        var bodyEl = get('modal-body');

        if (!modal || !titleEl || !bodyEl) {
            return;
        }

        titleEl.textContent = title;
        bodyEl.textContent = message;
        modal.classList.remove('hidden');
    }

    function closeModal() {
        var modal = get('modal');

        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
    }

    function setLoading(button, loading) {
        if (!button) {
            return;
        }

        if (loading) {
            button.disabled = true;
            button.classList.add('loading');
        } else {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    function postJson(action, data, button) {
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        var payload = data || {};
        payload.action = action;

        return fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload),
            credentials: 'same-origin'
        })
        .then(function (response) {
            return response.json();
        })
        .then(function (result) {
            if (!result || result.success !== true) {
                throw new Error(
                    result && result.message
                        ? result.message
                        : '処理に失敗しました。'
                );
            }

            return result;
        })
        .finally(function () {
            if (button) {
                button.disabled = false;
                button.classList.remove('loading');
            }
        });
    }

    function showPage(pageId) {
        var pages = [
            'page-list',
            'page-editor',
            'page-detail',
            'page-customers',
            'page-settings'
        ];

        pages.forEach(function (id) {
            var page = get(id);

            if (page) {
                page.classList.add('hidden');
            }
        });

        var target = get(pageId);

        if (target) {
            target.classList.remove('hidden');
        }

        var navs = [
            'nav-list',
            'nav-create',
            'nav-customers',
            'nav-settings'
        ];

        navs.forEach(function (id) {
            var nav = get(id);

            if (nav) {
                nav.classList.remove('active');
            }
        });

        if (pageId === 'page-list') {
            var listNav = get('nav-list');
            if (listNav) {
                listNav.classList.add('active');
            }
        }

        if (pageId === 'page-editor') {
            var createNav = get('nav-create');
            if (createNav) {
                createNav.classList.add('active');
            }
        }

        if (pageId === 'page-customers') {
            var customerNav = get('nav-customers');
            if (customerNav) {
                customerNav.classList.add('active');
            }
        }

        if (pageId === 'page-settings') {
            var settingsNav = get('nav-settings');
            if (settingsNav) {
                settingsNav.classList.add('active');
            }
        }
    }

    function renderList() {
        var container = get('survey-list');

        if (!container) {
            return;
        }

        container.textContent = '';

        if (!Array.isArray(surveys) || surveys.length === 0) {
            var emptyCard = document.createElement('div');
            emptyCard.className = 'card';
            emptyCard.textContent = 'アンケートはありません。';
            container.appendChild(emptyCard);
            return;
        }

        surveys.forEach(function (survey) {
            var card = document.createElement('div');
            card.className = 'survey-card';

            var title = document.createElement('h3');
            title.textContent = survey.name || '名称未設定';

            var info = document.createElement('div');
            info.className = 'subtext';
            info.textContent =
                '状態：' +
                getStatusText(survey.status) +
                '　回答数：' +
                String(survey.answers || 0) +
                '件　最終更新：' +
                String(survey.updated || '');

            var actions = document.createElement('div');
            actions.className = 'actions';

            var openButton = document.createElement('button');
            openButton.className = 'btn';
            openButton.textContent = '開く';
            openButton.setAttribute(
                'data-survey-open',
                String(survey.id)
            );

            var editButton = document.createElement('button');
            editButton.className = 'btn';
            editButton.textContent = '編集';
            editButton.setAttribute(
                'data-survey-edit',
                String(survey.id)
            );

            actions.appendChild(openButton);
            actions.appendChild(editButton);

            if (survey.status === 'draft') {
                var publishButton = document.createElement('button');
                publishButton.className = 'btn btn-primary';
                publishButton.textContent = '公開';
                publishButton.setAttribute(
                    'data-survey-publish',
                    String(survey.id)
                );
                actions.appendChild(publishButton);

                var deleteButton = document.createElement('button');
                deleteButton.className = 'btn btn-danger';
                deleteButton.textContent = '削除';
                deleteButton.setAttribute(
                    'data-survey-delete',
                    String(survey.id)
                );
                actions.appendChild(deleteButton);
            }

            if (survey.status === 'open') {
                var endButton = document.createElement('button');
                endButton.className = 'btn';
                endButton.textContent = '終了';
                endButton.setAttribute(
                    'data-survey-end',
                    String(survey.id)
                );
                actions.appendChild(endButton);
            }

            card.appendChild(title);
            card.appendChild(info);
            card.appendChild(actions);

            container.appendChild(card);
        });
    }

    function getStatusText(status) {
        if (status === 'open') {
            return '公開中';
        }

        if (status === 'end') {
            return '終了';
        }

        return '下書き';
    }

    function findSurvey(id) {
        var found = null;

        surveys.forEach(function (survey) {
            if (String(survey.id) === String(id)) {
                found = survey;
            }
        });

        return found;
    }

    function openCreate() {
        currentSurveyId = null;

        var title = get('editor-title');
        if (title) {
            title.textContent = 'アンケート作成';
        }

        setEditorValues({
            name: '',
            description: '',
            status: 'draft',
            start: '',
            end: '',
            numbering: 'global',
            groups: [
                {
                    id: nextId++,
                    name: '基本情報',
                    questions: [
                        {
                            id: nextId++,
                            text: '',
                            type: 'free',
                            required: false,
                            options: []
                        }
                    ]
                }
            ]
        });

        showPage('page-editor');
    }

    function openEdit(id) {
        var survey = findSurvey(id);

        if (!survey) {
            return;
        }

        currentSurveyId = survey.id;

        var title = get('editor-title');
        if (title) {
            title.textContent = 'アンケート編集';
        }

        setEditorValues(survey);
        showPage('page-editor');
    }

    function setEditorValues(survey) {
        var name = get('survey-name');
        var description = get('survey-description');
        var status = get('survey-status');
        var start = get('survey-start');
        var end = get('survey-end');
        var numbering = get('survey-numbering');

        if (name) {
            name.value = survey.name || '';
        }

        if (description) {
            description.value = survey.description || '';
        }

        if (status) {
            status.value = survey.status || 'draft';
        }

        if (start) {
            start.value = survey.start || '';
        }

        if (end) {
            end.value = survey.end || '';
        }

        if (numbering) {
            numbering.value = survey.numbering || 'global';
        }

        renderEditorGroups(
            Array.isArray(survey.groups) ? survey.groups : []
        );
    }

    function renderEditorGroups(groups) {
        var container = get('editor-groups');

        if (!container) {
            return;
        }

        container.textContent = '';

        groups.forEach(function (group, groupIndex) {
            var card = document.createElement('div');
            card.className = 'group';

            var header = document.createElement('div');
            header.className = 'group-header';

            var groupInput = document.createElement('input');
            groupInput.type = 'text';
            groupInput.value = group.name || '';
            groupInput.className = 'group-name';
            groupInput.setAttribute('data-group-id', String(group.id));
            groupInput.style.width = 'calc(100% - 100px)';
            groupInput.style.padding = '7px';

            var removeGroup = document.createElement('button');
            removeGroup.className = 'btn btn-danger';
            removeGroup.textContent = 'グループ削除';
            removeGroup.setAttribute(
                'data-remove-group',
                String(group.id)
            );

            header.appendChild(groupInput);
            header.appendChild(removeGroup);

            card.appendChild(header);

            var questions = Array.isArray(group.questions)
                ? group.questions
                : [];

            questions.forEach(function (question, questionIndex) {
                var questionBox = document.createElement('div');
                questionBox.className = 'question';

                var title = document.createElement('div');
                title.className = 'question-title';

                var number = document.createElement('span');
                number.className = 'question-number';

                var numbering = get('survey-numbering');
                var mode = numbering ? numbering.value : 'global';

                if (mode === 'group') {
                    number.textContent =
                        'Q' +
                        String(groupIndex + 1) +
                        '-' +
                        String(questionIndex + 1);
                } else {
                    var globalNumber = getGlobalQuestionNumber(
                        groups,
                        groupIndex,
                        questionIndex
                    );

                    number.textContent =
                        'Q' + String(globalNumber);
                }

                var textInput = document.createElement('input');
                textInput.type = 'text';
                textInput.value = question.text || '';
                textInput.className = 'question-text';
                textInput.setAttribute(
                    'data-question-id',
                    String(question.id)
                );
                textInput.style.flex = '1';

                var typeSelect = document.createElement('select');
                typeSelect.className = 'question-type';
                typeSelect.setAttribute(
                    'data-question-id',
                    String(question.id)
                );

                [
                    ['free', '自由記述'],
                    ['single', '単一選択'],
                    ['multiple', '複数選択']
                ].forEach(function (item) {
                    var option = document.createElement('option');
                    option.value = item[0];
                    option.textContent = item[1];

                    if (question.type === item[0]) {
                        option.selected = true;
                    }

                    typeSelect.appendChild(option);
                });

                var requiredLabel = document.createElement('label');
                requiredLabel.style.display = 'inline-flex';
                requiredLabel.style.alignItems = 'center';
                requiredLabel.style.gap = '4px';

                var required = document.createElement('input');
                required.type = 'checkbox';
                required.checked = question.required === true;
                required.className = 'question-required';
                required.setAttribute(
                    'data-question-id',
                    String(question.id)
                );

                requiredLabel.appendChild(required);
                requiredLabel.appendChild(
                    document.createTextNode('必須')
                );

                var removeQuestion = document.createElement('button');
                removeQuestion.className = 'btn btn-danger';
                removeQuestion.textContent = '削除';
                removeQuestion.setAttribute(
                    'data-remove-question',
                    String(question.id)
                );

                title.appendChild(number);
                title.appendChild(textInput);
                title.appendChild(typeSelect);
                title.appendChild(requiredLabel);
                title.appendChild(removeQuestion);

                questionBox.appendChild(title);

                if (
                    question.type === 'single' ||
                    question.type === 'multiple'
                ) {
                    var optionArea = document.createElement('div');
                    optionArea.className = 'question-options';

                    var options = Array.isArray(question.options)
                        ? question.options
                        : [];

                    options.forEach(function (item, optionIndex) {
                        var row = document.createElement('div');
                        row.className = 'option';

                        var optionInput = document.createElement('input');
                        optionInput.type = 'text';
                        optionInput.value = item.text || '';
                        optionInput.className = 'question-option';
                        optionInput.setAttribute(
                            'data-question-id',
                            String(question.id)
                        );
                        optionInput.setAttribute(
                            'data-option-index',
                            String(optionIndex)
                        );

                        row.appendChild(optionInput);

                        optionArea.appendChild(row);
                    });

                    var addOption = document.createElement('button');
                    addOption.className = 'btn';
                    addOption.textContent = '＋ 選択肢追加';
                    addOption.setAttribute(
                        'data-add-option',
                        String(question.id)
                    );

                    optionArea.appendChild(addOption);
                    questionBox.appendChild(optionArea);
                }

                card.appendChild(questionBox);
            });

            var addQuestionArea = document.createElement('div');
            addQuestionArea.style.padding = '10px';

            var addQuestion = document.createElement('button');
            addQuestion.className = 'btn';
            addQuestion.textContent = '＋ 質問追加';
            addQuestion.setAttribute(
                'data-add-question',
                String(group.id)
            );

            addQuestionArea.appendChild(addQuestion);
            card.appendChild(addQuestionArea);

            container.appendChild(card);
        });
    }

    function getGlobalQuestionNumber(groups, groupIndex, questionIndex) {
        var number = 0;

        for (var i = 0; i <= groupIndex; i++) {
            var questions = Array.isArray(groups[i].questions)
                ? groups[i].questions
                : [];

            if (i === groupIndex) {
                number += questionIndex + 1;
            } else {
                number += questions.length;
            }
        }

        return number;
    }

    function collectEditorData() {
        var result = {
            id: currentSurveyId || nextId++,
            name: get('survey-name') ? get('survey-name').value.trim() : '',
            description: get('survey-description')
                ? get('survey-description').value.trim()
                : '',
            status: get('survey-status')
                ? get('survey-status').value
                : 'draft',
            start: get('survey-start')
                ? get('survey-start').value
                : '',
            end: get('survey-end')
                ? get('survey-end').value
                : '',
            numbering: get('survey-numbering')
                ? get('survey-numbering').value
                : 'global',
            created: '',
            updated: new Date().toISOString().slice(0, 10),
            answers: 0,
            sent: 0,
            groups: []
        };

        var existing = currentSurveyId
            ? findSurvey(currentSurveyId)
            : null;

        if (existing) {
            result.created = existing.created || '';
            result.answers = existing.answers || 0;
            result.sent = existing.sent || 0;
        } else {
            result.created = result.updated;
        }

        var groupElements = document.querySelectorAll(
            '#editor-groups .group'
        );

        Array.prototype.forEach.call(
            groupElements,
            function (groupElement) {
                var groupInput =
                    groupElement.querySelector('.group-name');

                var groupId = groupInput
                    ? Number(groupInput.getAttribute('data-group-id'))
                    : nextId++;

                var group = {
                    id: groupId,
                    name: groupInput
                        ? groupInput.value.trim()
                        : '',
                    questions: []
                };

                var questionElements =
                    groupElement.querySelectorAll('.question');

                Array.prototype.forEach.call(
                    questionElements,
                    function (questionElement) {
                        var textInput =
                            questionElement.querySelector('.question-text');

                        var typeSelect =
                            questionElement.querySelector('.question-type');

                        var required =
                            questionElement.querySelector('.question-required');

                        var questionId = textInput
                            ? Number(
                                textInput.getAttribute(
                                    'data-question-id'
                                )
                            )
                            : nextId++;

                        var question = {
                            id: questionId,
                            text: textInput
                                ? textInput.value.trim()
                                : '',
                            type: typeSelect
                                ? typeSelect.value
                                : 'free',
                            required: required
                                ? required.checked
                                : false,
                            options: []
                        };

                        var optionInputs =
                            questionElement.querySelectorAll(
                                '.question-option'
                            );

                        Array.prototype.forEach.call(
                            optionInputs,
                            function (optionInput) {
                                question.options.push({
                                    text: optionInput.value.trim(),
                                    branch: ''
                                });
                            }
                        );

                        result.groups.push;
                        group.questions.push(question);
                    }
                );

                result.groups.push(group);
            }
        );

        return result;
    }

    function saveSurvey() {
        var button = get('save-survey');

        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        var survey = collectEditorData();

        if (survey.name === '') {
            if (button) {
                button.disabled = false;
                button.classList.remove('loading');
            }

            showModal(
                '入力確認',
                'アンケート名を入力してください。'
            );

            return;
        }

        var index = -1;

        surveys.forEach(function (item, i) {
            if (String(item.id) === String(survey.id)) {
                index = i;
            }
        });

        if (index >= 0) {
            surveys[index] = survey;
        } else {
            surveys.push(survey);
        }

        postJson(
            'save_surveys',
            { surveys: surveys },
            button
        )
        .then(function () {
            showToast('アンケートを保存しました。');
            renderList();
            showPage('page-list');
        })
        .catch(function (error) {
            showModal(
                '保存できませんでした',
                error.message
            );
        });
    }

    function renderDetail(survey) {
        var title = get('detail-title');
        var subtitle = get('detail-subtitle');
        var body = get('detail-body');

        if (!title || !subtitle || !body) {
            return;
        }

        title.textContent = survey.name || '';
        subtitle.textContent =
            '状態：' + getStatusText(survey.status);

        body.textContent = '';

        var info = document.createElement('div');
        info.className = 'card';

        var infoText = document.createElement('div');
        infoText.textContent =
            '回答数：' +
            String(survey.answers || 0) +
            '件　　送信済み：' +
            String(survey.sent || 0) +
            '件';

        info.appendChild(infoText);
        body.appendChild(info);

        var groups = Array.isArray(survey.groups)
            ? survey.groups
            : [];

        groups.forEach(function (group, groupIndex) {
            var groupBox = document.createElement('div');
            groupBox.className = 'group';

            var groupHeader = document.createElement('div');
            groupHeader.className = 'group-header';
            groupHeader.textContent =
                group.name || ('グループ' + String(groupIndex + 1));

            groupBox.appendChild(groupHeader);

            var questions = Array.isArray(group.questions)
                ? group.questions
                : [];

            questions.forEach(function (question, questionIndex) {
                var q = document.createElement('div');
                q.className = 'question';

                var qTitle = document.createElement('div');
                qTitle.className = 'question-title';

                var qNo = document.createElement('span');
                qNo.className = 'question-number';

                if (survey.numbering === 'group') {
                    qNo.textContent =
                        'Q' +
                        String(groupIndex + 1) +
                        '-' +
                        String(questionIndex + 1);
                } else {
                    qNo.textContent =
                        'Q' +
                        String(
                            getGlobalQuestionNumber(
                                groups,
                                groupIndex,
                                questionIndex
                            )
                        );
                }

                var qText = document.createElement('span');
                qText.textContent = question.text || '';

                qTitle.appendChild(qNo);
                qTitle.appendChild(qText);
                q.appendChild(qTitle);

                var options = Array.isArray(question.options)
                    ? question.options
                    : [];

                options.forEach(function (option) {
                    var op = document.createElement('div');
                    op.className = 'option';
                    op.textContent = '・' + (option.text || '');
                    q.appendChild(op);
                });

                groupBox.appendChild(q);
            });

            body.appendChild(groupBox);
        });

        if (survey.status === 'open') {
            var end = document.createElement('div');
            end.className = 'notice';
            end.textContent =
                'このアンケートは公開中です。';

            body.appendChild(end);
        }
    }

    function openDetail(id) {
        var survey = findSurvey(id);

        if (!survey) {
            return;
        }

        currentSurveyId = survey.id;
        renderDetail(survey);
        showPage('page-detail');
    }

    function addGroup() {
        var survey = collectEditorData();

        survey.groups.push({
            id: nextId++,
            name: '新しいグループ',
            questions: []
        });

        renderEditorGroups(survey.groups);
    }

    function addQuestion(groupId) {
        var survey = collectEditorData();

        survey.groups.forEach(function (group) {
            if (String(group.id) === String(groupId)) {
                group.questions.push({
                    id: nextId++,
                    text: '',
                    type: 'free',
                    required: false,
                    options: []
                });
            }
        });

        renderEditorGroups(survey.groups);
    }

    function addOption(questionId) {
        var survey = collectEditorData();

        survey.groups.forEach(function (group) {
            group.questions.forEach(function (question) {
                if (String(question.id) === String(questionId)) {
                    question.options.push({
                        text: '',
                        branch: ''
                    });
                }
            });
        });

        renderEditorGroups(survey.groups);
    }

    function removeQuestion(questionId) {
        var survey = collectEditorData();

        survey.groups.forEach(function (group) {
            group.questions = group.questions.filter(function (question) {
                return String(question.id) !== String(questionId);
            });
        });

        renderEditorGroups(survey.groups);
    }

    function removeGroup(groupId) {
        var survey = collectEditorData();

        survey.groups = survey.groups.filter(function (group) {
            return String(group.id) !== String(groupId);
        });

        renderEditorGroups(survey.groups);
    }

    function publishSurvey(id) {
        var survey = findSurvey(id);

        if (!survey) {
            return;
        }

        survey.status = 'open';
        survey.updated = new Date().toISOString().slice(0, 10);

        postJson(
            'save_surveys',
            { surveys: surveys },
            null
        )
        .then(function () {
            renderList();
            showToast('アンケートを公開しました。');
        })
        .catch(function (error) {
            showModal('公開できませんでした', error.message);
        });
    }

    function endSurvey(id) {
        var survey = findSurvey(id);

        if (!survey) {
            return;
        }

        survey.status = 'end';
        survey.updated = new Date().toISOString().slice(0, 10);

        postJson(
            'save_surveys',
            { surveys: surveys },
            null
        )
        .then(function () {
            renderList();
            showToast('アンケートを終了しました。');
        })
        .catch(function (error) {
            showModal('終了できませんでした', error.message);
        });
    }

    function deleteSurvey(id) {
        var survey = findSurvey(id);

        if (!survey) {
            return;
        }

        if (!window.confirm(
            'この下書きアンケートを削除しますか？'
        )) {
            return;
        }

        surveys = surveys.filter(function (item) {
            return String(item.id) !== String(id);
        });

        postJson(
            'save_surveys',
            { surveys: surveys },
            null
        )
        .then(function () {
            renderList();
            showToast('アンケートを削除しました。');
        })
        .catch(function (error) {
            showModal('削除できませんでした', error.message);
        });
    }

    function renderMailSettings() {
        var container = get('settings-content');

        if (!container) {
            return;
        }

        container.textContent = '';

        var card = document.createElement('div');
        card.className = 'card';

        var title = document.createElement('div');
        title.className = 'card-title';
        title.textContent = 'メール送信設定';

        card.appendChild(title);

        var status = document.createElement('div');
        status.className = 'status-line';

        var dot = document.createElement('span');
        dot.className =
            'status-dot ' +
            (settings.mail.smtp &&
             settings.mail.from
                ? 'ok'
                : 'warn');

        var statusText = document.createElement('span');
        statusText.textContent =
            settings.mail.smtp &&
            settings.mail.from
                ? 'メール設定が保存されています。'
                : 'メール設定が未完了です。';

        status.appendChild(dot);
        status.appendChild(statusText);
        card.appendChild(status);

        card.appendChild(createField(
            'SMTPサーバ *',
            'smtp-server',
            settings.mail.smtp,
            'text'
        ));

        var grid = document.createElement('div');
        grid.className = 'form-grid';

        grid.appendChild(createField(
            'ポート番号 *',
            'smtp-port',
            settings.mail.port || '587',
            'text'
        ));

        var securityWrap = document.createElement('div');
        securityWrap.className = 'field';

        var securityLabel = document.createElement('label');
        securityLabel.textContent = '接続方式';

        var security = document.createElement('select');
        security.id = 'smtp-security';

        [
            ['なし', 'なし'],
            ['STARTTLS', 'STARTTLS'],
            ['SSL/TLS', 'SSL/TLS']
        ].forEach(function (item) {
            var op = document.createElement('option');
            op.value = item[0];
            op.textContent = item[1];

            if (settings.mail.security === item[0]) {
                op.selected = true;
            }

            security.appendChild(op);
        });

        securityWrap.appendChild(securityLabel);
        securityWrap.appendChild(security);
        grid.appendChild(securityWrap);

        card.appendChild(grid);

        var authGrid = document.createElement('div');
        authGrid.className = 'form-grid';

        authGrid.appendChild(createField(
            '認証ユーザー名',
            'smtp-user',
            settings.mail.username,
            'text'
        ));

        authGrid.appendChild(createField(
            '認証パスワード',
            'smtp-password',
            '',
            'password'
        ));

        card.appendChild(authGrid);

        var fromGrid = document.createElement('div');
        fromGrid.className = 'form-grid';

        fromGrid.appendChild(createField(
            '送信元メールアドレス *',
            'smtp-from',
            settings.mail.from,
            'email'
        ));

        fromGrid.appendChild(createField(
            '送信元名',
            'smtp-from-name',
            settings.mail.fromName,
            'text'
        ));

        card.appendChild(fromGrid);

        card.appendChild(createField(
            'テスト送信先メールアドレス',
            'smtp-test-to',
            settings.mail.testTo,
            'email'
        ));

        var notice = document.createElement('div');
        notice.className = 'notice';
        notice.textContent =
            '「テストメール送信」を押すと、入力したテスト送信先へ実際にメールを送信します。';

        card.appendChild(notice);

        var actions = document.createElement('div');
        actions.className = 'actions';

        var save = document.createElement('button');
        save.id = 'save-mail-settings';
        save.className = 'btn btn-primary';
        save.textContent = '設定を保存';

        var test = document.createElement('button');
        test.id = 'test-mail-settings';
        test.className = 'btn';
        test.textContent = 'テストメール送信';

        actions.appendChild(save);
        actions.appendChild(test);

        card.appendChild(actions);
        container.appendChild(card);
    }

    function createField(labelText, id, value, type) {
        var wrap = document.createElement('div');
        wrap.className = 'field';

        var label = document.createElement('label');
        label.setAttribute('for', id);
        label.textContent = labelText;

        var input = document.createElement('input');
        input.id = id;
        input.type = type || 'text';
        input.value = value || '';

        wrap.appendChild(label);
        wrap.appendChild(input);

        return wrap;
    }

    function saveMailSettings() {
        var button = get('save-mail-settings');

        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        var passwordInput = get('smtp-password');

        var mail = {
            smtp: get('smtp-server')
                ? get('smtp-server').value.trim()
                : '',
            port: get('smtp-port')
                ? get('smtp-port').value.trim()
                : '',
            security: get('smtp-security')
                ? get('smtp-security').value
                : 'STARTTLS',
            username: get('smtp-user')
                ? get('smtp-user').value.trim()
                : '',
            password: passwordInput
                ? passwordInput.value
                : '',
            from: get('smtp-from')
                ? get('smtp-from').value.trim()
                : '',
            fromName: get('smtp-from-name')
                ? get('smtp-from-name').value.trim()
                : '',
            testTo: get('smtp-test-to')
                ? get('smtp-test-to').value.trim()
                : ''
        };

        postJson(
            'save_mail_settings',
            { mail: mail },
            button
        )
        .then(function () {
            settings.mail.smtp = mail.smtp;
            settings.mail.port = mail.port;
            settings.mail.security = mail.security;
            settings.mail.username = mail.username;
            settings.mail.from = mail.from;
            settings.mail.fromName = mail.fromName;
            settings.mail.testTo = mail.testTo;

            renderMailSettings();
            showToast('メール設定を保存しました。');
        })
        .catch(function (error) {
            showModal('保存できませんでした', error.message);
        });
    }

    function testMailSettings() {
        var button = get('test-mail-settings');

        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        var to = get('smtp-test-to')
            ? get('smtp-test-to').value.trim()
            : '';

        postJson(
            'test_mail',
            { to: to },
            button
        )
        .then(function (result) {
            showModal(
                'テストメール送信',
                result.message
            );
        })
        .catch(function (error) {
            showModal(
                'テストメールを送信できませんでした',
                error.message
            );
        });
    }

    function renderKintoneSettings() {
        var container = get('settings-content');

        if (!container) {
            return;
        }

        container.textContent = '';

        var card = document.createElement('div');
        card.className = 'card';

        var title = document.createElement('div');
        title.className = 'card-title';
        title.textContent = 'kintone設定';

        card.appendChild(title);

        var notice = document.createElement('div');
        notice.className = 'notice';
        notice.textContent =
            'kintoneへの接続にはログイン名・パスワードを使用します。APIトークンは使用しません。SSL証明書の検証は行いません。';

        card.appendChild(notice);

        card.appendChild(createField(
            'kintoneの利用先 *',
            'kt-domain',
            settings.kintone.domain,
            'text'
        ));

        card.appendChild(createField(
            '顧客管理アプリID *',
            'kt-appid',
            settings.kintone.appId,
            'text'
        ));

        var loginGrid = document.createElement('div');
        loginGrid.className = 'form-grid';

        loginGrid.appendChild(createField(
            'ログイン名 *',
            'kt-user',
            settings.kintone.loginName,
            'text'
        ));

        loginGrid.appendChild(createField(
            'パスワード *',
            'kt-password',
            '',
            'password'
        ));

        card.appendChild(loginGrid);

        card.appendChild(createField(
            'プロキシ（ホスト名:ポート番号）',
            'kt-proxy',
            settings.kintone.proxy,
            'text'
        ));

        var proxyNotice = document.createElement('div');
        proxyNotice.className = 'notice';
        proxyNotice.textContent =
            '例：proxy.example.local:8080　　プロキシを使用しない場合は空欄にしてください。';

        card.appendChild(proxyNotice);

        var actions = document.createElement('div');
        actions.className = 'actions';

        var save = document.createElement('button');
        save.id = 'save-kt-settings';
        save.className = 'btn btn-primary';
        save.textContent = '設定を保存';

        var test = document.createElement('button');
        test.id = 'test-kt-settings';
        test.className = 'btn';
        test.textContent = '接続確認・項目一覧取得';

        actions.appendChild(save);
        actions.appendChild(test);
        card.appendChild(actions);

        var fieldTitle = document.createElement('div');
        fieldTitle.className = 'card-title';
        fieldTitle.style.marginTop = '25px';
        fieldTitle.textContent = '顧客項目の設定';

        card.appendChild(fieldTitle);

        var fieldNotice = document.createElement('div');
        fieldNotice.className = 'notice';
        fieldNotice.textContent =
            '「接続確認・項目一覧取得」でkintoneアプリの項目を取得すると、ここから顧客名・メールアドレスなどの項目を選択できます。';

        card.appendChild(fieldNotice);

        var mappingGrid = document.createElement('div');
        mappingGrid.className = 'form-grid';

        mappingGrid.appendChild(createFieldSelect(
            '顧客名項目 *',
            'kt-name-field',
            settings.kintone.nameField,
            window.kintoneFields || []
        ));

        mappingGrid.appendChild(createFieldSelect(
            'メールアドレス項目 *',
            'kt-email-field',
            settings.kintone.emailField,
            window.kintoneFields || []
        ));

        mappingGrid.appendChild(createFieldSelect(
            '会社名項目',
            'kt-company-field',
            settings.kintone.companyField,
            window.kintoneFields || []
        ));

        mappingGrid.appendChild(createFieldSelect(
            '顧客番号項目',
            'kt-code-field',
            settings.kintone.codeField,
            window.kintoneFields || []
        ));

        card.appendChild(mappingGrid);

        var mappingSave = document.createElement('button');
        mappingSave.id = 'save-kt-fields';
        mappingSave.className = 'btn';
        mappingSave.textContent = '顧客項目設定を保存';

        card.appendChild(mappingSave);

        var listTitle = document.createElement('div');
        listTitle.className = 'card-title';
        listTitle.style.marginTop = '25px';
        listTitle.textContent = '取得したアプリ項目一覧';

        card.appendChild(listTitle);

        var fieldList = document.createElement('div');
        fieldList.id = 'kintone-field-list';
        fieldList.className = 'field-list';

        renderFieldList(fieldList, window.kintoneFields || []);

        card.appendChild(fieldList);

        container.appendChild(card);
    }

    function createFieldSelect(
        labelText,
        id,
        selectedValue,
        fields
    ) {
        var wrap = document.createElement('div');
        wrap.className = 'field';

        var label = document.createElement('label');
        label.textContent = labelText;

        var select = document.createElement('select');
        select.id = id;

        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = '選択してください';
        select.appendChild(empty);

        fields.forEach(function (field) {
            var option = document.createElement('option');
            option.value = field.code;
            option.textContent =
                field.label +
                ' [' +
                field.code +
                ']';

            if (selectedValue === field.code) {
                option.selected = true;
            }

            select.appendChild(option);
        });

        wrap.appendChild(label);
        wrap.appendChild(select);

        return wrap;
    }

    function renderFieldList(container, fields) {
        if (!container) {
            return;
        }

        container.textContent = '';

        var header = document.createElement('div');
        header.className = 'field-row header';

        [
            '項目名',
            'フィールドコード',
            '種類'
        ].forEach(function (text) {
            var cell = document.createElement('div');
            cell.textContent = text;
            header.appendChild(cell);
        });

        container.appendChild(header);

        if (!fields.length) {
            var empty = document.createElement('div');
            empty.className = 'field-row';

            var message = document.createElement('div');
            message.textContent =
                'まだ項目一覧を取得していません。';

            empty.appendChild(message);
            container.appendChild(empty);
            return;
        }

        fields.forEach(function (field) {
            var row = document.createElement('div');
            row.className = 'field-row';

            var label = document.createElement('div');
            label.textContent = field.label;

            var code = document.createElement('div');
            code.textContent = field.code;

            var type = document.createElement('div');
            type.textContent = field.type;

            row.appendChild(label);
            row.appendChild(code);
            row.appendChild(type);

            container.appendChild(row);
        });
    }

    function saveKintoneSettings() {
        var button = get('save-kt-settings');

        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        var passwordInput = get('kt-password');

        var kintone = {
            domain: get('kt-domain')
                ? get('kt-domain').value.trim()
                : '',
            appId: get('kt-appid')
                ? get('kt-appid').value.trim()
                : '',
            loginName: get('kt-user')
                ? get('kt-user').value.trim()
                : '',
            password: passwordInput
                ? passwordInput.value
                : '',
            proxy: get('kt-proxy')
                ? get('kt-proxy').value.trim()
                : '',
            nameField: settings.kintone.nameField,
            emailField: settings.kintone.emailField,
            companyField: settings.kintone.companyField,
            codeField: settings.kintone.codeField
        };

        postJson(
            'save_kintone_settings',
            { kintone: kintone },
            button
        )
        .then(function () {
            settings.kintone.domain = kintone.domain;
            settings.kintone.appId = kintone.appId;
            settings.kintone.loginName = kintone.loginName;
            settings.kintone.proxy = kintone.proxy;

            showToast('kintone設定を保存しました。');
        })
        .catch(function (error) {
            showModal(
                '保存できませんでした',
                error.message
            );
        });
    }

    function testKintoneSettings() {
        var button = get('test-kt-settings');

        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        postJson(
            'test_kintone',
            {},
            button
        )
        .then(function (result) {
            window.kintoneFields =
                Array.isArray(result.fields)
                    ? result.fields
                    : [];

            renderKintoneSettings();

            showToast(
                'kintone接続に成功し、' +
                String(result.fieldCount || 0) +
                '項目を取得しました。'
            );
        })
        .catch(function (error) {
            showModal(
                'kintone接続に失敗しました',
                error.message
            );
        });
    }

    function saveKintoneFields() {
        var button = get('save-kt-fields');

        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        settings.kintone.nameField =
            get('kt-name-field')
                ? get('kt-name-field').value
                : '';

        settings.kintone.emailField =
            get('kt-email-field')
                ? get('kt-email-field').value
                : '';

        settings.kintone.companyField =
            get('kt-company-field')
                ? get('kt-company-field').value
                : '';

        settings.kintone.codeField =
            get('kt-code-field')
                ? get('kt-code-field').value
                : '';

        postJson(
            'save_kintone_settings',
            {
                kintone: {
                    domain: settings.kintone.domain,
                    appId: settings.kintone.appId,
                    loginName: settings.kintone.loginName,
                    password: '',
                    proxy: settings.kintone.proxy,
                    nameField: settings.kintone.nameField,
                    emailField: settings.kintone.emailField,
                    companyField: settings.kintone.companyField,
                    codeField: settings.kintone.codeField
                }
            },
            button
        )
        .then(function () {
            showToast('顧客項目設定を保存しました。');
        })
        .catch(function (error) {
            showModal(
                '保存できませんでした',
                error.message
            );
        });
    }

    function showSettings(tab) {
        currentSettingsTab = tab || 'mail';

        var mailTab = get('tab-mail');
        var ktTab = get('tab-kintone');

        if (mailTab) {
            mailTab.classList.toggle(
                'active',
                currentSettingsTab === 'mail'
            );
        }

        if (ktTab) {
            ktTab.classList.toggle(
                'active',
                currentSettingsTab === 'kintone'
            );
        }

        showPage('page-settings');

        if (currentSettingsTab === 'kintone') {
            renderKintoneSettings();
        } else {
            renderMailSettings();
        }
    }

    function showCustomers() {
        showPage('page-customers');

        var status = get('customer-status');

        if (status) {
            status.textContent = '';
        }

        renderCustomers();
    }

    function renderCustomers() {
        var body = get('customer-body');
        var searchInput = get('customer-search');

        if (!body) {
            return;
        }

        var search = searchInput
            ? searchInput.value.trim().toLowerCase()
            : '';

        body.textContent = '';

        var filtered = customers.filter(function (customer) {
            var text =
                String(customer.name || '') +
                ' ' +
                String(customer.email || '') +
                ' ' +
                String(customer.company || '') +
                ' ' +
                String(customer.code || '');

            return text.toLowerCase().indexOf(search) >= 0;
        });

        if (!filtered.length) {
            var row = document.createElement('tr');
            var cell = document.createElement('td');

            cell.colSpan = 4;
            cell.className = 'empty';
            cell.textContent =
                customers.length
                    ? '検索条件に一致する顧客がありません。'
                    : '顧客一覧を取得してください。';

            row.appendChild(cell);
            body.appendChild(row);
            return;
        }

        filtered.forEach(function (customer) {
            var row = document.createElement('tr');

            [
                customer.name,
                customer.email,
                customer.company,
                customer.code
            ].forEach(function (value) {
                var cell = document.createElement('td');
                cell.textContent = value || '';
                row.appendChild(cell);
            });

            body.appendChild(row);
        });
    }

    function refreshCustomers() {
        var button = get('refresh-customers');

        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        postJson(
            'get_customers',
            {},
            button
        )
        .then(function (result) {
            customers =
                Array.isArray(result.customers)
                    ? result.customers
                    : [];

            var status = get('customer-status');

            if (status) {
                status.textContent =
                    'kintoneから' +
                    String(result.count || 0) +
                    '件の顧客を取得しました。';
                status.className = 'notice success';
            }

            renderCustomers();
            showToast('顧客一覧を更新しました。');
        })
        .catch(function (error) {
            var status = get('customer-status');

            if (status) {
                status.textContent =
                    '顧客一覧を取得できませんでした：' +
                    error.message;
                status.className = 'notice error';
            }

            renderCustomers();
        });
    }

    function bindEvents() {
        var navButtons =
            document.querySelectorAll('[data-page]');

        Array.prototype.forEach.call(
            navButtons,
            function (button) {
                if (!button) {
                    return;
                }

                button.addEventListener('click', function () {
                    var page =
                        button.getAttribute('data-page');

                    if (page === 'page-list') {
                        renderList();
                    }

                    if (page === 'page-editor') {
                        openCreate();
                        return;
                    }

                    if (page === 'page-customers') {
                        showCustomers();
                        return;
                    }

                    if (page === 'page-settings') {
                        showSettings(currentSettingsTab);
                        return;
                    }

                    showPage(page);
                });
            }
        );

        var createButton = get('create-survey');

        if (createButton) {
            createButton.addEventListener(
                'click',
                openCreate
            );
        }

        var list = get('survey-list');

        if (list) {
            list.addEventListener('click', function (event) {
                var target = event.target;

                if (!target) {
                    return;
                }

                var openId =
                    target.getAttribute('data-survey-open');

                if (openId) {
                    openDetail(openId);
                    return;
                }

                var editId =
                    target.getAttribute('data-survey-edit');

                if (editId) {
                    openEdit(editId);
                    return;
                }

                var publishId =
                    target.getAttribute('data-survey-publish');

                if (publishId) {
                    publishSurvey(publishId);
                    return;
                }

                var endId =
                    target.getAttribute('data-survey-end');

                if (endId) {
                    endSurvey(endId);
                    return;
                }

                var deleteId =
                    target.getAttribute('data-survey-delete');

                if (deleteId) {
                    deleteSurvey(deleteId);
                }
            });
        }

        var addGroupButton = get('add-group');

        if (addGroupButton) {
            addGroupButton.addEventListener(
                'click',
                addGroup
            );
        }

        var saveSurveyButton = get('save-survey');

        if (saveSurveyButton) {
            saveSurveyButton.addEventListener(
                'click',
                saveSurvey
            );
        }

        var editorBack = get('editor-back');

        if (editorBack) {
            editorBack.addEventListener(
                'click',
                function () {
                    renderList();
                    showPage('page-list');
                }
            );
        }

        var detailEdit = get('detail-edit');

        if (detailEdit) {
            detailEdit.addEventListener(
                'click',
                function () {
                    if (currentSurveyId !== null) {
                        openEdit(currentSurveyId);
                    }
                }
            );
        }

        var detailSend = get('detail-send');

        if (detailSend) {
            detailSend.addEventListener(
                'click',
                function () {
                    showModal(
                        '送信',
                        '顧客一覧から送信対象者を選択してメール送信を行います。'
                    );
                }
            );
        }

        var detailBack = get('detail-back');

        if (detailBack) {
            detailBack.addEventListener(
                'click',
                function () {
                    renderList();
                    showPage('page-list');
                }
            );
        }

        var customersSettings = get('customers-settings');

        if (customersSettings) {
            customersSettings.addEventListener(
                'click',
                function () {
                    showSettings('kintone');
                }
            );
        }

        var refresh = get('refresh-customers');

        if (refresh) {
            refresh.addEventListener(
                'click',
                refreshCustomers
            );
        }

        var search = get('customer-search');

        if (search) {
            search.addEventListener(
                'input',
                renderCustomers
            );
        }

        var mailTab = get('tab-mail');

        if (mailTab) {
            mailTab.addEventListener(
                'click',
                function () {
                    showSettings('mail');
                }
            );
        }

        var ktTab = get('tab-kintone');

        if (ktTab) {
            ktTab.addEventListener(
                'click',
                function () {
                    showSettings('kintone');
                }
            );
        }

        var modalClose = get('modal-close');

        if (modalClose) {
            modalClose.addEventListener(
                'click',
                closeModal
            );
        }

        var numbering = get('survey-numbering');

        if (numbering) {
            numbering.addEventListener(
                'change',
                function () {
                    var data = collectEditorData();
                    renderEditorGroups(data.groups);
                }
            );
        }

        var editorGroups = get('editor-groups');

        if (editorGroups) {
            editorGroups.addEventListener(
                'click',
                function (event) {
                    var target = event.target;

                    if (!target) {
                        return;
                    }

                    var addQuestionId =
                        target.getAttribute('data-add-question');

                    if (addQuestionId) {
                        addQuestion(addQuestionId);
                        return;
                    }

                    var addOptionId =
                        target.getAttribute('data-add-option');

                    if (addOptionId) {
                        addOption(addOptionId);
                        return;
                    }

                    var removeQuestionId =
                        target.getAttribute('data-remove-question');

                    if (removeQuestionId) {
                        removeQuestion(removeQuestionId);
                        return;
                    }

                    var removeGroupId =
                        target.getAttribute('data-remove-group');

                    if (removeGroupId) {
                        removeGroup(removeGroupId);
                    }
                }
            );

            editorGroups.addEventListener(
                'change',
                function (event) {
                    var target = event.target;

                    if (!target) {
                        return;
                    }

                    if (
                        target.classList.contains(
                            'question-type'
                        )
                    ) {
                        var data = collectEditorData();

                        renderEditorGroups(data.groups);
                    }
                }
            );
        }

        document.addEventListener(
            'click',
            function (event) {
                var target = event.target;

                if (!target) {
                    return;
                }

                if (
                    target.id === 'save-mail-settings'
                ) {
                    saveMailSettings();
                    return;
                }

                if (
                    target.id === 'test-mail-settings'
                ) {
                    testMailSettings();
                    return;
                }

                if (
                    target.id === 'save-kt-settings'
                ) {
                    saveKintoneSettings();
                    return;
                }

                if (
                    target.id === 'test-kt-settings'
                ) {
                    testKintoneSettings();
                    return;
                }

                if (
                    target.id === 'save-kt-fields'
                ) {
                    saveKintoneFields();
                }
            }
        );
    }

    /*
     * 設定画面は動的に生成されるため、
     * 保存・確認ボタンは上記documentクリックで処理する。
     */

    bindEvents();
    renderList();
    showPage('page-list');
});
</script>

</body>
</html>

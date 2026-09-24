<?php
declare(strict_types=1);

namespace yokoyamy\trial\NewApp;

use RuntimeException;

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
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const SURVEYS_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'surveys.json';

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
 * NULL安全エスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * JSON読み込み
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
 * JSON保存
 */
function write_json_file(string $file, array $data): void
{
    $directory = dirname($file);

    if (!is_dir($directory)) {
        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('データ保存用フォルダを作成できません。');
        }
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('データを保存できません。');
    }

    $temporary = $file . '.tmp';

    if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException('データを保存できません。');
    }

    if (!@rename($temporary, $file)) {
        @unlink($temporary);
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
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
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

    $sessionToken = (string)(
        $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? ''
    );

    if (
        $token === '' ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $token)
    ) {
        json_response([
            'success' => false,
            'message' => 'セッションの確認に失敗しました。画面を再読み込みしてください。'
        ]);
    }
}

/**
 * JSONリクエスト
 */
function request_json(): array
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
        if (
            is_string($header) &&
            preg_match(
                '/HTTP\/\d(?:\.\d)?\s+(\d{3})/i',
                $header,
                $matches
            )
        ) {
            return (int)$matches[1];
        }
    }

    return 0;
}

/**
 * kintone URL生成
 */
function kintone_build_url(
    string $domain,
    string $endpoint
): string {
    $domain = trim($domain);

    $domain = preg_replace(
        '/^https?:\/\//i',
        '',
        $domain
    );

    $domain = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $domain
    );

    $domain = trim(
        (string)$domain,
        "/ \t\n\r\0\x0B"
    );

    if ($domain === '') {
        throw new RuntimeException(
            'kintoneの利用先を入力してください。'
        );
    }

    if (
        !preg_match(
            '/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/',
            $domain
        )
    ) {
        throw new RuntimeException(
            'kintoneの利用先が正しくありません。'
        );
    }

    return 'https://' .
        $domain .
        '.cybozu.com/' .
        ltrim($endpoint, '/');
}

/**
 * プロキシ host:port 正規化
 */
function normalize_proxy(string $proxy): string
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return '';
    }

    if (
        !preg_match(
            '/^([a-zA-Z0-9.-]+):([0-9]{1,5})$/',
            $proxy,
            $matches
        )
    ) {
        throw new RuntimeException(
            'プロキシは「ホスト名:ポート番号」の形式で入力してください。'
        );
    }

    $port = (int)$matches[2];

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException(
            'プロキシのポート番号が正しくありません。'
        );
    }

    return $matches[1] . ':' . $port;
}

/**
 * kintone認証ヘッダー
 */
function make_cybozu_auth_header(
    string $loginName,
    string $password
): string {
    return 'X-Cybozu-Authorization: ' .
        base64_encode(
            trim($loginName) . ':' . trim($password)
        );
}

/**
 * kintone API通信
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload,
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

    if ($method === 'GET' && $payload !== null) {
        throw new RuntimeException(
            'GET通信に送信本文は指定できません。'
        );
    }

    if ($method !== 'GET' && $payload !== null) {
        $body = is_array($payload)
            ? json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_INVALID_UTF8_SUBSTITUTE
            )
            : (string)$payload;

        if ($body === false) {
            throw new RuntimeException(
                '送信データを作成できません。'
            );
        }

        $http['content'] = $body;
    }

    $proxy = normalize_proxy($proxy);

    if ($proxy !== '') {
        $http['proxy'] = 'tcp://' . $proxy;
        $http['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $http,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $responseBody = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders = get_safe_response_headers();
    $status = get_response_status($responseHeaders);

    $decoded = [];

    if (
        $responseBody !== false &&
        trim($responseBody) !== ''
    ) {
        $json = json_decode(
            $responseBody,
            true
        );

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

    if (
        isset($decoded['message']) &&
        is_string($decoded['message'])
    ) {
        $message = $decoded['message'];
    }

    $details = [];

    if (
        isset($decoded['code']) &&
        is_string($decoded['code'])
    ) {
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
                        $details[] =
                            (string)$field .
                            ': ' .
                            $errorMessage;
                    }
                }
            }
        }
    }

    if ($details !== []) {
        $message .= ' ' . implode(
            ' / ',
            $details
        );
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
 * メールアドレス
 */
function valid_email(string $email): bool
{
    return filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    ) !== false;
}

/**
 * SMTP読み込み
 */
function smtp_read(mixed $socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    return $response;
}

/**
 * SMTPコマンド
 */
function smtp_command(
    mixed $socket,
    string $command,
    array $expected
): void {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    $response = smtp_read($socket);

    $code = (int)substr(
        trim($response),
        0,
        3
    );

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'メールサーバーとの通信に失敗しました。'
        );
    }
}

/**
 * テストメール送信
 */
function smtp_send(
    array $settings,
    string $to,
    string $subject,
    string $body
): void {
    $server = trim(
        (string)($settings['smtp'] ?? '')
    );

    $port = (int)(
        $settings['port'] ?? 0
    );

    $security = (string)(
        $settings['security'] ?? 'STARTTLS'
    );

    $username = trim(
        (string)($settings['username'] ?? '')
    );

    $password = (string)(
        $settings['password'] ?? ''
    );

    $from = trim(
        (string)($settings['from'] ?? '')
    );

    $fromName = trim(
        (string)($settings['fromName'] ?? '')
    );

    if (
        $server === '' ||
        $port < 1 ||
        $port > 65535
    ) {
        throw new RuntimeException(
            'SMTPサーバーとポート番号を確認してください。'
        );
    }

    if (!valid_email($from)) {
        throw new RuntimeException(
            '送信元メールアドレスが正しくありません。'
        );
    }

    if (!valid_email($to)) {
        throw new RuntimeException(
            '送信先メールアドレスが正しくありません。'
        );
    }

    if (
        !in_array(
            $security,
            ['なし', 'STARTTLS', 'SSL/TLS'],
            true
        )
    ) {
        throw new RuntimeException(
            'メール接続方式が正しくありません。'
        );
    }

    $remote =
        $security === 'SSL/TLS'
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
            'SMTPサーバーへ接続できませんでした。'
        );
    }

    stream_set_timeout($socket, 20);

    try {
        $greeting = smtp_read($socket);

        if (
            (int)substr(
                trim($greeting),
                0,
                3
            ) !== 220
        ) {
            throw new RuntimeException(
                'SMTPサーバーから正常な応答がありません。'
            );
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

        $encodedSubject =
            '=?UTF-8?B?' .
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

        $mail =
            'From: ' . $displayFrom . "\r\n" .
            'To: ' . $to . "\r\n" .
            'Subject: ' . $encodedSubject . "\r\n" .
            'MIME-Version: 1.0' . "\r\n" .
            'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
            'Content-Transfer-Encoding: 8bit' . "\r\n" .
            "\r\n" .
            $body .
            "\r\n.";

        fwrite(
            $socket,
            $mail . "\r\n"
        );

        $response = smtp_read($socket);

        if (
            (int)substr(
                trim($response),
                0,
                3
            ) !== 250
        ) {
            throw new RuntimeException(
                'メール送信を完了できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'QUIT',
            [221]
        );
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
            'fromName' => 'アンケート事務局'
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
            'status' => 'draft',
            'created' => date('Y-m-d'),
            'start' => '',
            'end' => '',
            'updated' => date('Y-m-d'),
            'answers' => 0,
            'target' => 0,
            'sent' => 0,
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
                                [
                                    'text' => 'はい',
                                    'branch' => ''
                                ],
                                [
                                    'text' => 'いいえ',
                                    'branch' => ''
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];
}

$settings = read_json_file(
    SETTINGS_FILE,
    default_settings()
);

$customers = read_json_file(
    CUSTOMERS_FILE,
    []
);

$surveys = read_json_file(
    SURVEYS_FILE,
    default_surveys()
);

/**
 * API処理
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $request = request_json();

    $action = isset($request['action'])
        ? (string)$request['action']
        : '';

    try {
        switch ($action) {
            case 'save_settings':
                $mail = isset($request['mail']) &&
                    is_array($request['mail'])
                    ? $request['mail']
                    : [];

                $kintone = isset($request['kintone']) &&
                    is_array($request['kintone'])
                    ? $request['kintone']
                    : [];

                $smtp = trim(
                    (string)($mail['smtp'] ?? '')
                );

                $port = trim(
                    (string)($mail['port'] ?? '')
                );

                $from = trim(
                    (string)($mail['from'] ?? '')
                );

                if (
                    $smtp === '' ||
                    !ctype_digit($port) ||
                    (int)$port < 1 ||
                    (int)$port > 65535 ||
                    !valid_email($from)
                ) {
                    throw new RuntimeException(
                        'メール設定を確認してください。'
                    );
                }

                $security =
                    (string)($mail['security'] ?? 'STARTTLS');

                if (
                    !in_array(
                        $security,
                        ['なし', 'STARTTLS', 'SSL/TLS'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'メール接続方式が正しくありません。'
                    );
                }

                $password = (string)(
                    $mail['password'] ?? ''
                );

                if ($password !== '') {
                    $settings['mail']['password'] =
                        $password;
                }

                $settings['mail']['smtp'] = $smtp;
                $settings['mail']['port'] = $port;
                $settings['mail']['security'] = $security;
                $settings['mail']['username'] =
                    trim((string)(
                        $mail['username'] ?? ''
                    ));
                $settings['mail']['from'] = $from;
                $settings['mail']['fromName'] =
                    trim((string)(
                        $mail['fromName'] ?? ''
                    ));

                $domain = trim(
                    (string)($kintone['domain'] ?? '')
                );

                $appId = trim(
                    (string)($kintone['appId'] ?? '')
                );

                $loginName = trim(
                    (string)($kintone['loginName'] ?? '')
                );

                $kintonePassword = (string)(
                    $kintone['password'] ?? ''
                );

                $proxy = trim(
                    (string)($kintone['proxy'] ?? '')
                );

                normalize_proxy($proxy);

                $settings['kintone']['domain'] = $domain;
                $settings['kintone']['appId'] = $appId;
                $settings['kintone']['loginName'] =
                    $loginName;

                if ($kintonePassword !== '') {
                    $settings['kintone']['password'] =
                        $kintonePassword;
                }

                $settings['kintone']['proxy'] = $proxy;
                $settings['kintone']['nameField'] =
                    trim((string)(
                        $kintone['nameField'] ?? ''
                    ));
                $settings['kintone']['emailField'] =
                    trim((string)(
                        $kintone['emailField'] ?? ''
                    ));
                $settings['kintone']['companyField'] =
                    trim((string)(
                        $kintone['companyField'] ?? ''
                    ));
                $settings['kintone']['codeField'] =
                    trim((string)(
                        $kintone['codeField'] ?? ''
                    ));

                write_json_file(
                    SETTINGS_FILE,
                    $settings
                );

                json_response([
                    'success' => true,
                    'message' => '設定を保存しました。'
                ]);

            case 'test_mail':
                $to = trim(
                    (string)($request['to'] ?? '')
                );

                if (!valid_email($to)) {
                    throw new RuntimeException(
                        'テスト送信先メールアドレスが正しくありません。'
                    );
                }

                smtp_send(
                    $settings['mail'] ?? [],
                    $to,
                    'アンケート業務運営：テストメール',
                    "アンケート業務運営アプリからのテストメールです。\r\n\r\n" .
                    "このメールを受信できれば、メール送信設定は利用可能です。"
                );

                json_response([
                    'success' => true,
                    'message' => 'テストメールを送信しました。'
                ]);

            case 'test_kintone':
                $k = $settings['kintone'] ?? [];

                $url = kintone_build_url(
                    (string)($k['domain'] ?? ''),
                    '/k/v1/apps.json?' .
                    http_build_query(
                        ['limit' => 1],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    )
                );

                $result = kintone_api_request(
                    'GET',
                    $url,
                    [
                        make_cybozu_auth_header(
                            (string)($k['loginName'] ?? ''),
                            (string)($k['password'] ?? '')
                        ),
                        'Content-Type: application/json'
                    ],
                    null,
                    (string)($k['proxy'] ?? '')
                );

                if (!$result['success']) {
                    throw new RuntimeException(
                        (string)$result['message']
                    );
                }

                json_response([
                    'success' => true,
                    'message' => 'kintoneへの接続を確認しました。'
                ]);

            case 'load_customers':
                $k = $settings['kintone'] ?? [];

                $appId = trim(
                    (string)($k['appId'] ?? '')
                );

                if ($appId === '') {
                    throw new RuntimeException(
                        '顧客管理アプリIDを設定してください。'
                    );
                }

                $fields = array_values(
                    array_filter([
                        $k['nameField'] ?? '',
                        $k['emailField'] ?? '',
                        $k['companyField'] ?? '',
                        $k['codeField'] ?? ''
                    ], static function ($value): bool {
                        return trim((string)$value) !== '';
                    })
                );

                $params = [
                    'app' => $appId,
                    'totalCount' => 'true',
                    'limit' => 500
                ];

                if ($fields !== []) {
                    $params['fields'] = $fields;
                }

                $url = kintone_build_url(
                    (string)($k['domain'] ?? ''),
                    '/k/v1/records.json?' .
                    http_build_query(
                        $params,
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    )
                );

                $result = kintone_api_request(
                    'GET',
                    $url,
                    [
                        make_cybozu_auth_header(
                            (string)($k['loginName'] ?? ''),
                            (string)($k['password'] ?? '')
                        )
                    ],
                    null,
                    (string)($k['proxy'] ?? '')
                );

                if (!$result['success']) {
                    throw new RuntimeException(
                        (string)$result['message']
                    );
                }

                $records = $result['data']['records'] ?? [];

                $customers = [];

                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (!is_array($record)) {
                            continue;
                        }

                        $readField = static function (
                            array $record,
                            string $field
                        ): string {
                            if (
                                $field === '' ||
                                !isset($record[$field]) ||
                                !is_array($record[$field])
                            ) {
                                return '';
                            }

                            $value = $record[$field]['value'] ?? '';

                            return is_scalar($value)
                                ? (string)$value
                                : '';
                        };

                        $customers[] = [
                            'id' => count($customers) + 1,
                            'name' => $readField(
                                $record,
                                (string)($k['nameField'] ?? '')
                            ),
                            'email' => $readField(
                                $record,
                                (string)($k['emailField'] ?? '')
                            ),
                            'company' => $readField(
                                $record,
                                (string)($k['companyField'] ?? '')
                            ),
                            'code' => $readField(
                                $record,
                                (string)($k['codeField'] ?? '')
                            )
                        ];
                    }
                }

                write_json_file(
                    CUSTOMERS_FILE,
                    $customers
                );

                json_response([
                    'success' => true,
                    'message' => '顧客一覧を取得しました。',
                    'customers' => $customers
                ]);

            case 'save_survey':
                $survey = $request['survey'] ?? null;

                if (!is_array($survey)) {
                    throw new RuntimeException(
                        'アンケートデータが正しくありません。'
                    );
                }

                $survey['name'] = trim(
                    (string)($survey['name'] ?? '')
                );

                if ($survey['name'] === '') {
                    throw new RuntimeException(
                        'アンケート名を入力してください。'
                    );
                }

                if (
                    isset($survey['start']) &&
                    isset($survey['end']) &&
                    $survey['start'] !== '' &&
                    $survey['end'] !== '' &&
                    $survey['start'] > $survey['end']
                ) {
                    throw new RuntimeException(
                        '公開開始日は公開終了日以前にしてください。'
                    );
                }

                if (
                    !isset($survey['groups']) ||
                    !is_array($survey['groups'])
                ) {
                    throw new RuntimeException(
                        '質問グループを設定してください。'
                    );
                }

                foreach ($survey['groups'] as $groupIndex => &$group) {
                    if (!is_array($group)) {
                        throw new RuntimeException(
                            'グループの内容が正しくありません。'
                        );
                    }

                    $group['name'] = trim(
                        (string)($group['name'] ?? '')
                    );

                    if ($group['name'] === '') {
                        throw new RuntimeException(
                            'グループ' .
                            ($groupIndex + 1) .
                            'の名前を入力してください。'
                        );
                    }

                    $group['questions'] =
                        isset($group['questions']) &&
                        is_array($group['questions'])
                        ? $group['questions']
                        : [];

                    foreach (
                        $group['questions']
                        as $questionIndex => &$question
                    ) {
                        if (!is_array($question)) {
                            throw new RuntimeException(
                                '質問の内容が正しくありません。'
                            );
                        }

                        $question['text'] = trim(
                            (string)($question['text'] ?? '')
                        );

                        if ($question['text'] === '') {
                            throw new RuntimeException(
                                '質問文を入力してください。'
                            );
                        }

                        $type = (string)(
                            $question['type'] ?? 'free'
                        );

                        if (
                            !in_array(
                                $type,
                                [
                                    'free',
                                    'single',
                                    'multiple'
                                ],
                                true
                            )
                        ) {
                            throw new RuntimeException(
                                '回答形式が正しくありません。'
                            );
                        }

                        $question['type'] = $type;
                        $question['required'] =
                            !empty($question['required']);

                        if ($type === 'free') {
                            $question['options'] = [];
                        } else {
                            $options =
                                isset($question['options']) &&
                                is_array($question['options'])
                                ? $question['options']
                                : [];

                            $normalized = [];

                            foreach ($options as $option) {
                                if (!is_array($option)) {
                                    continue;
                                }

                                $text = trim(
                                    (string)($option['text'] ?? '')
                                );

                                if ($text === '') {
                                    continue;
                                }

                                $normalized[] = [
                                    'text' => $text,
                                    'branch' =>
                                        (string)(
                                            $option['branch'] ?? ''
                                        )
                                ];
                            }

                            if ($normalized === []) {
                                throw new RuntimeException(
                                    '選択式の質問には選択肢を設定してください。'
                                );
                            }

                            $question['options'] =
                                $normalized;
                        }
                    }

                    unset($question);
                }

                unset($group);

                $now = date('Y-m-d');

                $id = isset($survey['id'])
                    ? (int)$survey['id']
                    : 0;

                if ($id <= 0) {
                    $max = 0;

                    foreach ($surveys as $existing) {
                        $max = max(
                            $max,
                            (int)($existing['id'] ?? 0)
                        );
                    }

                    $survey['id'] = $max + 1;
                    $survey['created'] = $now;
                    $survey['answers'] = 0;
                    $survey['target'] = 0;
                    $survey['sent'] = 0;
                    $survey['status'] = 'draft';
                }

                $survey['updated'] = $now;

                $found = false;

                foreach ($surveys as $index => $existing) {
                    if (
                        (int)($existing['id'] ?? 0) ===
                        (int)$survey['id']
                    ) {
                        $surveys[$index] = $survey;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $surveys[] = $survey;
                }

                write_json_file(
                    SURVEYS_FILE,
                    $surveys
                );

                json_response([
                    'success' => true,
                    'message' => 'アンケートを保存しました。',
                    'survey' => $survey,
                    'surveys' => $surveys
                ]);

            case 'change_status':
                $id = (int)($request['id'] ?? 0);
                $status = (string)($request['status'] ?? '');

                if (
                    $id <= 0 ||
                    !in_array(
                        $status,
                        ['draft', 'open', 'end'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'アンケート状態が正しくありません。'
                    );
                }

                $found = false;

                foreach ($surveys as &$survey) {
                    if (
                        (int)($survey['id'] ?? 0) === $id
                    ) {
                        $survey['status'] = $status;
                        $survey['updated'] = date('Y-m-d');
                        $found = true;
                        break;
                    }
                }

                unset($survey);

                if (!$found) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                write_json_file(
                    SURVEYS_FILE,
                    $surveys
                );

                json_response([
                    'success' => true,
                    'message' => 'アンケートの状態を変更しました。',
                    'surveys' => $surveys
                ]);

            case 'delete_survey':
                $id = (int)($request['id'] ?? 0);

                $newSurveys = [];
                $deleted = false;

                foreach ($surveys as $survey) {
                    if (
                        (int)($survey['id'] ?? 0) === $id
                    ) {
                        if (
                            (string)($survey['status'] ?? 'draft')
                            !== 'draft'
                        ) {
                            throw new RuntimeException(
                                '下書き以外のアンケートは削除できません。'
                            );
                        }

                        $deleted = true;
                        continue;
                    }

                    $newSurveys[] = $survey;
                }

                if (!$deleted) {
                    throw new RuntimeException(
                        '削除対象のアンケートが見つかりません。'
                    );
                }

                $surveys = $newSurveys;

                write_json_file(
                    SURVEYS_FILE,
                    $surveys
                );

                json_response([
                    'success' => true,
                    'message' => 'アンケートを削除しました。',
                    'surveys' => $surveys
                ]);

            case 'send_mail':
                $surveyId = (int)(
                    $request['surveyId'] ?? 0
                );

                $customerIds =
                    isset($request['customerIds']) &&
                    is_array($request['customerIds'])
                    ? $request['customerIds']
                    : [];

                $subject = trim(
                    (string)($request['subject'] ?? '')
                );

                $body = (string)(
                    $request['body'] ?? ''
                );

                if (
                    $surveyId <= 0 ||
                    $customerIds === []
                ) {
                    throw new RuntimeException(
                        '送信対象者を選択してください。'
                    );
                }

                if ($subject === '') {
                    throw new RuntimeException(
                        'メール件名を入力してください。'
                    );
                }

                if (
                    empty(
                        $settings['mail']['smtp']
                    ) ||
                    empty(
                        $settings['mail']['from']
                    )
                ) {
                    throw new RuntimeException(
                        'メール送信設定を完了してください。'
                    );
                }

                $success = 0;
                $failed = [];

                foreach ($customerIds as $customerId) {
                    $customer = null;

                    foreach ($customers as $item) {
                        if (
                            (string)($item['id'] ?? '') ===
                            (string)$customerId
                        ) {
                            $customer = $item;
                            break;
                        }
                    }

                    if (
                        !is_array($customer) ||
                        !valid_email(
                            (string)($customer['email'] ?? '')
                        )
                    ) {
                        $failed[] = [
                            'name' =>
                                (string)(
                                    $customer['name'] ?? ''
                                ),
                            'email' =>
                                (string)(
                                    $customer['email'] ?? ''
                                )
                        ];
                        continue;
                    }

                    try {
                        smtp_send(
                            $settings['mail'],
                            (string)$customer['email'],
                            $subject,
                            $body
                        );
                        $success++;
                    } catch (\Throwable $mailError) {
                        $failed[] = [
                            'name' =>
                                (string)(
                                    $customer['name'] ?? ''
                                ),
                            'email' =>
                                (string)(
                                    $customer['email'] ?? ''
                                )
                        ];
                    }
                }

                foreach ($surveys as &$survey) {
                    if (
                        (int)($survey['id'] ?? 0) ===
                        $surveyId
                    ) {
                        $survey['target'] =
                            count($customerIds);

                        $survey['sent'] =
                            (int)(
                                $survey['sent'] ?? 0
                            ) + $success;

                        $survey['updated'] =
                            date('Y-m-d');

                        break;
                    }
                }

                unset($survey);

                write_json_file(
                    SURVEYS_FILE,
                    $surveys
                );

                json_response([
                    'success' => true,
                    'message' => 'メール送信処理が完了しました。',
                    'target' => count($customerIds),
                    'successCount' => $success,
                    'failedCount' => count($failed),
                    'failed' => $failed
                ]);

            default:
                throw new RuntimeException(
                    '処理内容が指定されていません。'
                );
        }
    } catch (\Throwable $e) {
        json_response([
            'success' => false,
            'message' => safe_error_message(
                $e->getMessage()
            )
        ]);
    }
}

$csrfToken = (string)(
    $_SESSION[APP_SESSION_KEY]['csrf_token']
    ?? ''
);

$publicSurveyId = isset($_GET['answer'])
    ? (int)$_GET['answer']
    : 0;

if ($publicSurveyId > 0) {
    $answerSurvey = null;

    foreach ($surveys as $survey) {
        if (
            (int)($survey['id'] ?? 0) ===
            $publicSurveyId
        ) {
            $answerSurvey = $survey;
            break;
        }
    }

    if (
        !is_array($answerSurvey) ||
        (string)($answerSurvey['status'] ?? '') !== 'open'
    ) {
        http_response_code(404);
        ?>
        <!DOCTYPE html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <title>アンケート</title>
            <style>
                body{
                    margin:0;
                    background:#f4f6f8;
                    color:#20252b;
                    font-family:-apple-system,BlinkMacSystemFont,
                        "Segoe UI","Yu Gothic",sans-serif;
                }
                .answer-wrap{
                    max-width:760px;
                    margin:60px auto;
                    padding:24px;
                }
                .card{
                    background:#fff;
                    border-radius:12px;
                    padding:32px;
                    box-shadow:0 4px 20px rgba(0,0,0,.08);
                }
            </style>
        </head>
        <body>
        <div class="answer-wrap">
            <div class="card">
                <h1>アンケートを表示できません</h1>
                <p>このアンケートは現在回答を受け付けていません。</p>
            </div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport"
              content="width=device-width,initial-scale=1">
        <title><?= h((string)$answerSurvey['name']) ?></title>
        <style>
            body{
                margin:0;
                background:#f4f6f8;
                color:#20252b;
                font-family:-apple-system,BlinkMacSystemFont,
                    "Segoe UI","Yu Gothic",sans-serif;
            }
            .answer-wrap{
                max-width:760px;
                margin:40px auto;
                padding:20px;
            }
            .card{
                background:#fff;
                border-radius:12px;
                padding:28px;
                margin-bottom:18px;
                box-shadow:0 3px 18px rgba(0,0,0,.07);
            }
            .question{
                margin-bottom:28px;
            }
            .question-title{
                font-weight:700;
                margin-bottom:12px;
            }
            .option{
                display:block;
                padding:8px 0;
            }
            textarea{
                width:100%;
                min-height:120px;
                box-sizing:border-box;
                padding:10px;
            }
            .btn{
                border:0;
                border-radius:7px;
                padding:12px 20px;
                background:#1769aa;
                color:#fff;
                cursor:pointer;
            }
            .btn:disabled{
                opacity:.6;
                cursor:not-allowed;
            }
            .loading{
                position:relative;
                padding-left:38px;
            }
            .loading:before{
                content:"";
                position:absolute;
                left:14px;
                top:50%;
                width:13px;
                height:13px;
                margin-top:-7px;
                border:2px solid rgba(255,255,255,.4);
                border-top-color:#fff;
                border-radius:50%;
                animation:spin .7s linear infinite;
            }
            @keyframes spin{
                to{transform:rotate(360deg)}
            }
        </style>
    </head>
    <body>
    <main class="answer-wrap">
        <div class="card">
            <h1><?= h((string)$answerSurvey['name']) ?></h1>
            <p><?= nl2br(h((string)($answerSurvey['description'] ?? ''))) ?></p>
        </div>

        <form id="answer-form">
            <?php
            $answerQuestionIndex = 0;
            foreach (
                $answerSurvey['groups'] as $group
            ):
            ?>
            <div class="card">
                <h2><?= h((string)$group['name']) ?></h2>

                <?php
                foreach (
                    $group['questions'] as $question
                ):
                    $answerQuestionIndex++;
                    $qid = (string)$question['id'];
                    $required = !empty($question['required']);
                ?>
                <div class="question"
                     data-question-id="<?= h($qid) ?>">
                    <div class="question-title">
                        Q<?= $answerQuestionIndex ?>.
                        <?= h((string)$question['text']) ?>
                        <?php if ($required): ?>
                            <span>＊</span>
                        <?php endif; ?>
                    </div>

                    <?php
                    $type =
                        (string)($question['type'] ?? 'free');

                    if ($type === 'free'):
                    ?>
                        <textarea
                            name="q[<?= h($qid) ?>]"
                            <?= $required ? 'required' : '' ?>
                        ></textarea>
                    <?php elseif ($type === 'single'): ?>
                        <?php foreach (
                            $question['options'] ?? []
                            as $option
                        ): ?>
                            <label class="option">
                                <input
                                    type="radio"
                                    name="q[<?= h($qid) ?>]"
                                    value="<?= h((string)$option['text']) ?>"
                                    <?= $required ? 'required' : '' ?>
                                >
                                <?= h((string)$option['text']) ?>
                            </label>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach (
                            $question['options'] ?? []
                            as $option
                        ): ?>
                            <label class="option">
                                <input
                                    type="checkbox"
                                    name="q[<?= h($qid) ?>][]"
                                    value="<?= h((string)$option['text']) ?>"
                                >
                                <?= h((string)$option['text']) ?>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <div class="card">
                <button
                    id="answer-submit"
                    class="btn"
                    type="button"
                >
                    回答を送信する
                </button>
            </div>
        </form>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const button =
            document.getElementById('answer-submit');

        const form =
            document.getElementById('answer-form');

        if (!button || !form) {
            return;
        }

        button.addEventListener('click', function () {
            if (button.disabled) {
                return;
            }

            if (!form.reportValidity()) {
                return;
            }

            button.disabled = true;
            button.classList.add('loading');

            /*
             * 回答保存APIは運営画面と同じCSRF方式を使用する。
             * 公開回答の永続保存を追加する場合はここで保存処理を行う。
             */
            setTimeout(function () {
                button.classList.remove('loading');
                button.disabled = false;

                form.innerHTML =
                    '<div class="card">' +
                    '<h1>回答ありがとうございました</h1>' +
                    '<p>回答を受け付けました。</p>' +
                    '</div>';
            }, 300);
        });
    });
    </script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<meta name="csrf-token"
      content="<?= h($csrfToken) ?>">
<title>アンケート業務運営</title>
<style>
*{
    box-sizing:border-box;
}
body{
    margin:0;
    background:#f4f6f8;
    color:#20252b;
    font-family:-apple-system,BlinkMacSystemFont,
        "Segoe UI","Yu Gothic",sans-serif;
}
.header{
    height:58px;
    background:#17324d;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
}
.header-title{
    font-weight:700;
    margin-right:30px;
}
.nav{
    display:flex;
    gap:4px;
}
.nav button{
    border:0;
    background:transparent;
    color:#dce7f0;
    padding:10px 14px;
    cursor:pointer;
    border-radius:5px;
}
.nav button.active,
.nav button:hover{
    background:#285272;
    color:#fff;
}
.container{
    max-width:1280px;
    margin:0 auto;
    padding:28px;
}
.page{
    display:none;
}
.page.active{
    display:block;
}
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:22px;
}
.page-header h1{
    margin:0 0 5px;
    font-size:25px;
}
.subtext{
    color:#66717c;
    font-size:13px;
}
.card{
    background:#fff;
    border-radius:10px;
    padding:22px;
    margin-bottom:18px;
    box-shadow:0 2px 12px rgba(0,0,0,.05);
}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
.field{
    margin-bottom:16px;
}
.field label{
    display:block;
    font-weight:600;
    margin-bottom:6px;
}
input,
textarea,
select{
    width:100%;
    border:1px solid #ccd3da;
    border-radius:6px;
    padding:9px 10px;
    background:#fff;
    color:#20252b;
}
textarea{
    min-height:100px;
    resize:vertical;
}
.btn{
    border:1px solid #ccd3da;
    background:#fff;
    color:#27313a;
    border-radius:6px;
    padding:8px 13px;
    cursor:pointer;
}
.btn:hover{
    background:#f1f4f6;
}
.btn-primary{
    background:#1769aa;
    color:#fff;
    border-color:#1769aa;
}
.btn-success{
    background:#218838;
    color:#fff;
    border-color:#218838;
}
.btn-danger{
    background:#c62828;
    color:#fff;
    border-color:#c62828;
}
.btn-small{
    font-size:12px;
    padding:5px 9px;
}
.btn:disabled{
    opacity:.6;
    cursor:not-allowed;
}
.loading{
    position:relative;
    padding-left:36px;
}
.loading:before{
    content:"";
    position:absolute;
    left:12px;
    top:50%;
    width:13px;
    height:13px;
    margin-top:-7px;
    border:2px solid rgba(255,255,255,.35);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
}
@keyframes spin{
    to{transform:rotate(360deg)}
}
.table-wrap{
    overflow:auto;
}
table{
    width:100%;
    border-collapse:collapse;
}
th,
td{
    border-bottom:1px solid #e4e8eb;
    padding:11px 10px;
    text-align:left;
    vertical-align:middle;
}
th{
    background:#f8fafb;
    font-size:13px;
}
.status{
    display:inline-block;
    padding:4px 9px;
    border-radius:20px;
    font-size:12px;
}
.status-draft{
    background:#edf0f3;
    color:#5f6972;
}
.status-open{
    background:#e4f6e9;
    color:#23733a;
}
.status-end{
    background:#fbe6e6;
    color:#a42828;
}
.toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-bottom:14px;
}
.group{
    border:1px solid #dbe1e6;
    border-radius:9px;
    margin-bottom:18px;
    background:#fafbfc;
}
.group-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px;
    border-bottom:1px solid #dbe1e6;
}
.group-title{
    flex:1;
}
.group-body{
    padding:14px;
}
.question{
    background:#fff;
    border:1px solid #e0e5e9;
    border-radius:8px;
    padding:15px;
    margin-bottom:12px;
}
.question-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
}
.question-number{
    font-weight:700;
    color:#1769aa;
}
.option-row{
    display:flex;
    gap:8px;
    margin-bottom:7px;
}
.option-row input{
    flex:1;
}
.notice{
    padding:12px 14px;
    background:#f1f6fa;
    border-radius:7px;
    margin-bottom:15px;
}
.notice.error{
    background:#fff0f0;
    color:#9b2020;
}
.notice.success{
    background:#ebf8ee;
    color:#236d35;
}
.toast{
    position:fixed;
    right:20px;
    bottom:20px;
    background:#25313c;
    color:#fff;
    padding:12px 17px;
    border-radius:7px;
    opacity:0;
    pointer-events:none;
    transition:opacity .2s;
    z-index:100;
}
.toast.show{
    opacity:1;
}
.tabs{
    display:flex;
    gap:4px;
    border-bottom:1px solid #dce2e6;
    margin-bottom:18px;
}
.tabs button{
    border:0;
    background:transparent;
    padding:11px 15px;
    cursor:pointer;
}
.tabs button.active{
    color:#1769aa;
    border-bottom:2px solid #1769aa;
}
.modal{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.45);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:90;
}
.modal.active{
    display:flex;
}
.modal-box{
    background:#fff;
    width:min(760px,92vw);
    max-height:90vh;
    overflow:auto;
    border-radius:10px;
    padding:22px;
}
.modal-head{
    display:flex;
    justify-content:space-between;
    margin-bottom:18px;
}
.empty{
    text-align:center;
    color:#75808a;
    padding:40px 10px;
}
@media(max-width:800px){
    .form-grid{
        grid-template-columns:1fr;
    }
    .header{
        height:auto;
        padding:12px;
        align-items:flex-start;
        flex-direction:column;
        gap:8px;
    }
    .container{
        padding:15px;
    }
    .page-header{
        align-items:flex-start;
        flex-direction:column;
        gap:12px;
    }
}
</style>
</head>

<body>

<header class="header">
    <div class="header-title">アンケート業務運営</div>

    <nav class="nav">
        <button
            type="button"
            data-page="list"
            class="active"
        >アンケート一覧</button>

        <button
            type="button"
            data-page="editor"
        >アンケート作成</button>

        <button
            type="button"
            data-page="customers"
        >顧客一覧</button>

        <button
            type="button"
            data-page="settings"
        >設定</button>
    </nav>
</header>

<main class="container">

<section id="page-list" class="page active">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">
                作成済みアンケートを管理します
            </div>
        </div>

        <button
            type="button"
            class="btn btn-primary"
            data-action="new-survey"
        >＋ アンケート作成</button>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>アンケート名</th>
                    <th>状態</th>
                    <th>作成日</th>
                    <th>公開期間</th>
                    <th>回答数</th>
                    <th>最終更新</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody id="survey-list"></tbody>
            </table>
        </div>
    </div>
</section>

<section id="page-editor" class="page">
    <div class="page-header">
        <div>
            <h1 id="editor-title">アンケート作成</h1>
            <div class="subtext">
                アンケート全体を確認しながら編集します
            </div>
        </div>

        <div>
            <button
                type="button"
                class="btn"
                data-action="editor-back"
            >一覧へ戻る</button>

            <button
                type="button"
                class="btn btn-primary"
                data-action="save-survey"
            >保存</button>
        </div>
    </div>

    <div class="card">
        <div class="form-grid">
            <div class="field">
                <label for="survey-name">
                    アンケート名 *
                </label>
                <input id="survey-name">
            </div>

            <div class="field">
                <label for="survey-status">
                    公開状態
                </label>
                <select id="survey-status">
                    <option value="draft">下書き</option>
                    <option value="open">公開中</option>
                    <option value="end">終了</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label for="survey-description">
                説明
            </label>
            <textarea id="survey-description"></textarea>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="survey-start">
                    公開開始日
                </label>
                <input
                    id="survey-start"
                    type="date"
                >
            </div>

            <div class="field">
                <label for="survey-end">
                    公開終了日
                </label>
                <input
                    id="survey-end"
                    type="date"
                >
            </div>
        </div>

        <div class="field">
            <label>質問番号形式</label>

            <label>
                <input
                    type="radio"
                    name="numbering"
                    value="global"
                    checked
                >
                全体で通番
            </label>

            <label>
                <input
                    type="radio"
                    name="numbering"
                    value="group"
                >
                グループごとの番号
            </label>
        </div>
    </div>

    <div id="groups"></div>

    <div class="card">
        <button
            type="button"
            class="btn btn-primary"
            data-action="add-group"
        >＋ グループ追加</button>
    </div>
</section>

<section id="page-detail" class="page">
    <div class="page-header">
        <div>
            <h1 id="detail-title"></h1>
            <div id="detail-status"></div>
        </div>

        <div>
            <button
                type="button"
                class="btn"
                data-action="detail-edit"
            >編集</button>

            <button
                type="button"
                class="btn"
                data-action="detail-back"
            >一覧へ戻る</button>
        </div>
    </div>

    <div class="tabs">
        <button
            type="button"
            data-detail-tab="content"
            class="active"
        >アンケート内容</button>

        <button
            type="button"
            data-detail-tab="send"
        >送信</button>

        <button
            type="button"
            data-detail-tab="status"
        >回答状況</button>

        <button
            type="button"
            data-detail-tab="result"
        >回答結果</button>
    </div>

    <div id="detail-content"></div>
</section>

<section id="page-customers" class="page">
    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">
                kintoneから取得した顧客情報
            </div>
        </div>

        <button
            type="button"
            class="btn btn-primary"
            data-action="load-customers"
        >顧客一覧を更新</button>
    </div>

    <div class="card">
        <div class="field">
            <label for="customer-search">
                顧客検索
            </label>
            <input
                id="customer-search"
                placeholder="顧客名・メールアドレス・会社名"
            >
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>顧客名</th>
                    <th>メールアドレス</th>
                    <th>会社名</th>
                    <th>顧客番号</th>
                </tr>
                </thead>
                <tbody id="customer-list"></tbody>
            </table>
        </div>
    </div>
</section>

<section id="page-settings" class="page">
    <div class="page-header">
        <div>
            <h1>設定</h1>
            <div class="subtext">
                メール送信とkintone接続の設定
            </div>
        </div>
    </div>

    <div class="tabs">
        <button
            type="button"
            data-settings-tab="mail"
            class="active"
        >メール送信設定</button>

        <button
            type="button"
            data-settings-tab="kintone"
        >kintone設定</button>
    </div>

    <div id="settings-content"></div>
</section>

</main>

<div
    id="modal"
    class="modal"
    aria-hidden="true"
>
    <div class="modal-box">
        <div class="modal-head">
            <strong id="modal-title"></strong>

            <button
                type="button"
                class="btn"
                data-action="close-modal"
            >閉じる</button>
        </div>

        <div id="modal-content"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const csrf =
        document.querySelector(
            'meta[name="csrf-token"]'
        )?.getAttribute('content') || '';

    let surveys =
        <?= json_encode(
            $surveys,
            JSON_UNESCAPED_UNICODE |
            JSON_INVALID_UTF8_SUBSTITUTE
        ) ?>;

    let customers =
        <?= json_encode(
            $customers,
            JSON_UNESCAPED_UNICODE |
            JSON_INVALID_UTF8_SUBSTITUTE
        ) ?>;

    let settings =
        <?= json_encode(
            [
                'mail' => [
                    'smtp' =>
                        (string)($settings['mail']['smtp'] ?? ''),
                    'port' =>
                        (string)($settings['mail']['port'] ?? '587'),
                    'security' =>
                        (string)($settings['mail']['security'] ?? 'STARTTLS'),
                    'username' =>
                        (string)($settings['mail']['username'] ?? ''),
                    'from' =>
                        (string)($settings['mail']['from'] ?? ''),
                    'fromName' =>
                        (string)($settings['mail']['fromName'] ?? '')
                ],
                'kintone' => [
                    'domain' =>
                        (string)($settings['kintone']['domain'] ?? ''),
                    'appId' =>
                        (string)($settings['kintone']['appId'] ?? ''),
                    'loginName' =>
                        (string)($settings['kintone']['loginName'] ?? ''),
                    'proxy' =>
                        (string)($settings['kintone']['proxy'] ?? ''),
                    'nameField' =>
                        (string)($settings['kintone']['nameField'] ?? ''),
                    'emailField' =>
                        (string)($settings['kintone']['emailField'] ?? ''),
                    'companyField' =>
                        (string)($settings['kintone']['companyField'] ?? ''),
                    'codeField' =>
                        (string)($settings['kintone']['codeField'] ?? '')
                ]
            ],
            JSON_UNESCAPED_UNICODE |
            JSON_INVALID_UTF8_SUBSTITUTE
        ) ?>;

    let editingSurvey = null;
    let currentSurveyId = 0;
    let nextGroupId = 10000;
    let nextQuestionId = 100000;
    let currentSettingsTab = 'mail';
    let selectedCustomers = [];

    const $ = function (selector) {
        return document.querySelector(selector);
    };

    const $$ = function (selector) {
        return Array.from(
            document.querySelectorAll(selector)
        );
    };

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent =
            value === null ||
            value === undefined
                ? ''
                : String(value);
        return div.innerHTML;
    }

    function showToast(message) {
        const toast = $('#toast');

        if (!toast) {
            return;
        }

        toast.textContent = message;
        toast.classList.add('show');

        window.setTimeout(function () {
            toast.classList.remove('show');
        }, 2500);
    }

    function showModal(title, content) {
        const modal = $('#modal');
        const titleElement = $('#modal-title');
        const contentElement = $('#modal-content');

        if (
            !modal ||
            !titleElement ||
            !contentElement
        ) {
            return;
        }

        titleElement.textContent = title;
        contentElement.replaceChildren();

        const wrapper =
            document.createElement('div');

        wrapper.innerHTML = content;

        contentElement.appendChild(wrapper);
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        const modal = $('#modal');

        if (!modal) {
            return;
        }

        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
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

    async function api(action, data, button) {
        if (button) {
            setLoading(button, true);
        }

        try {
            const response = await fetch(
                window.location.href,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify(
                        Object.assign(
                            { action: action },
                            data || {}
                        )
                    )
                }
            );

            const result =
                await response.json();

            if (!result.success) {
                throw new Error(
                    result.message ||
                    '処理に失敗しました。'
                );
            }

            return result;
        } finally {
            if (button) {
                setLoading(button, false);
            }
        }
    }

    function switchPage(name) {
        $$('.page').forEach(function (page) {
            page.classList.remove('active');
        });

        const target =
            $('#page-' + name);

        if (target) {
            target.classList.add('active');
        }

        $$('.nav button').forEach(
            function (button) {
                button.classList.toggle(
                    'active',
                    button.dataset.page === name
                );
            }
        );
    }

    function statusHtml(status) {
        if (status === 'open') {
            return '<span class="status status-open">公開中</span>';
        }

        if (status === 'end') {
            return '<span class="status status-end">終了</span>';
        }

        return '<span class="status status-draft">下書き</span>';
    }

    function renderSurveyList() {
        const tbody =
            $('#survey-list');

        if (!tbody) {
            return;
        }

        tbody.replaceChildren();

        if (!surveys.length) {
            const row =
                document.createElement('tr');

            const cell =
                document.createElement('td');

            cell.colSpan = 7;
            cell.className = 'empty';
            cell.textContent =
                'アンケートがありません。';

            row.appendChild(cell);
            tbody.appendChild(row);
            return;
        }

        surveys.forEach(function (survey) {
            const row =
                document.createElement('tr');

            const nameCell =
                document.createElement('td');

            const nameButton =
                document.createElement('button');

            nameButton.type = 'button';
            nameButton.className = 'btn';
            nameButton.textContent =
                survey.name || '名称未設定';

            nameButton.dataset.action =
                'open-detail';

            nameButton.dataset.id =
                String(survey.id);

            nameCell.appendChild(nameButton);

            const statusCell =
                document.createElement('td');

            statusCell.innerHTML =
                statusHtml(
                    survey.status
                );

            const createdCell =
                document.createElement('td');

            createdCell.textContent =
                survey.created || '';

            const periodCell =
                document.createElement('td');

            periodCell.textContent =
                survey.start || survey.end
                    ? (survey.start || '未設定') +
                      ' ～ ' +
                      (survey.end || '未設定')
                    : '未設定';

            const answersCell =
                document.createElement('td');

            answersCell.textContent =
                String(
                    survey.answers || 0
                ) + '件';

            const updatedCell =
                document.createElement('td');

            updatedCell.textContent =
                survey.updated || '';

            const actionCell =
                document.createElement('td');

            const edit =
                document.createElement('button');

            edit.type = 'button';
            edit.className =
                'btn btn-small';

            edit.textContent = '編集';
            edit.dataset.action =
                'edit-survey';
            edit.dataset.id =
                String(survey.id);

            actionCell.appendChild(edit);

            const detail =
                document.createElement('button');

            detail.type = 'button';
            detail.className =
                'btn btn-small';

            detail.textContent = '確認';
            detail.dataset.action =
                'open-detail';
            detail.dataset.id =
                String(survey.id);

            actionCell.appendChild(detail);

            if (survey.status === 'draft') {
                const publish =
                    document.createElement('button');

                publish.type = 'button';
                publish.className =
                    'btn btn-small btn-success';

                publish.textContent = '公開';
                publish.dataset.action =
                    'publish';
                publish.dataset.id =
                    String(survey.id);

                actionCell.appendChild(publish);

                const remove =
                    document.createElement('button');

                remove.type = 'button';
                remove.className =
                    'btn btn-small btn-danger';

                remove.textContent = '削除';
                remove.dataset.action =
                    'delete-survey';
                remove.dataset.id =
                    String(survey.id);

                actionCell.appendChild(remove);
            }

            if (survey.status === 'open') {
                const end =
                    document.createElement('button');

                end.type = 'button';
                end.className =
                    'btn btn-small btn-danger';

                end.textContent = '終了';
                end.dataset.action =
                    'end-survey';
                end.dataset.id =
                    String(survey.id);

                actionCell.appendChild(end);
            }

            row.appendChild(nameCell);
            row.appendChild(statusCell);
            row.appendChild(createdCell);
            row.appendChild(periodCell);
            row.appendChild(answersCell);
            row.appendChild(updatedCell);
            row.appendChild(actionCell);

            tbody.appendChild(row);
        });
    }

    function newSurvey() {
        editingSurvey = {
            id: null,
            name: '',
            description: '',
            status: 'draft',
            created: '',
            start: '',
            end: '',
            updated: '',
            answers: 0,
            target: 0,
            sent: 0,
            numbering: 'global',
            groups: [
                {
                    id: nextGroupId++,
                    name: 'グループ1',
                    questions: [
                        {
                            id: nextQuestionId++,
                            text: '',
                            type: 'free',
                            required: false,
                            options: []
                        }
                    ]
                }
            ]
        };

        openEditor();
    }

    function clone(value) {
        return JSON.parse(
            JSON.stringify(value)
        );
    }

    function editSurvey(id) {
        const survey =
            surveys.find(function (item) {
                return Number(item.id) === Number(id);
            });

        if (!survey) {
            return;
        }

        editingSurvey = clone(survey);
        openEditor();
    }

    function openEditor() {
        const title =
            $('#editor-title');

        if (title) {
            title.textContent =
                editingSurvey.id
                    ? 'アンケート編集'
                    : 'アンケート作成';
        }

        $('#survey-name').value =
            editingSurvey.name || '';

        $('#survey-description').value =
            editingSurvey.description || '';

        $('#survey-status').value =
            editingSurvey.status || 'draft';

        $('#survey-start').value =
            editingSurvey.start || '';

        $('#survey-end').value =
            editingSurvey.end || '';

        $$(
            'input[name="numbering"]'
        ).forEach(function (radio) {
            radio.checked =
                radio.value ===
                editingSurvey.numbering;
        });

        renderEditor();
        switchPage('editor');
    }

    function questionNumber(
        groupIndex,
        questionIndex
    ) {
        if (
            editingSurvey.numbering ===
            'group'
        ) {
            return 'Q' +
                (groupIndex + 1) +
                '-' +
                (questionIndex + 1);
        }

        let count = 0;

        for (
            let i = 0;
            i < groupIndex;
            i++
        ) {
            count +=
                editingSurvey.groups[i]
                    .questions.length;
        }

        return 'Q' +
            (count + questionIndex + 1);
    }

    function renderEditor() {
        const container =
            $('#groups');

        if (!container) {
            return;
        }

        container.replaceChildren();

        editingSurvey.groups.forEach(
            function (group, gi) {
                const groupElement =
                    document.createElement('div');

                groupElement.className =
                    'group';

                const header =
                    document.createElement('div');

                header.className =
                    'group-header';

                const name =
                    document.createElement('input');

                name.value =
                    group.name || '';

                name.dataset.role =
                    'group-name';

                name.dataset.groupId =
                    String(group.id);

                const remove =
                    document.createElement('button');

                remove.type = 'button';
                remove.className =
                    'btn btn-small btn-danger';

                remove.textContent =
                    'グループ削除';

                remove.dataset.action =
                    'delete-group';

                remove.dataset.groupId =
                    String(group.id);

                header.appendChild(name);
                header.appendChild(remove);

                const body =
                    document.createElement('div');

                body.className =
                    'group-body';

                group.questions.forEach(
                    function (question, qi) {
                        body.appendChild(
                            createQuestionElement(
                                group,
                                question,
                                gi,
                                qi
                            )
                        );
                    }
                );

                const add =
                    document.createElement('button');

                add.type = 'button';
                add.className =
                    'btn btn-small btn-primary';

                add.textContent =
                    '＋ 質問追加';

                add.dataset.action =
                    'add-question';

                add.dataset.groupId =
                    String(group.id);

                body.appendChild(add);

                groupElement.appendChild(header);
                groupElement.appendChild(body);

                container.appendChild(
                    groupElement
                );
            }
        );
    }

    function createQuestionElement(
        group,
        question,
        groupIndex,
        questionIndex
    ) {
        const wrapper =
            document.createElement('div');

        wrapper.className =
            'question';

        const head =
            document.createElement('div');

        head.className =
            'question-head';

        const number =
            document.createElement('span');

        number.className =
            'question-number';

        number.textContent =
            questionNumber(
                groupIndex,
                questionIndex
            );

        const remove =
            document.createElement('button');

        remove.type = 'button';
        remove.className =
            'btn btn-small btn-danger';

        remove.textContent =
            '質問削除';

        remove.dataset.action =
            'delete-question';

        remove.dataset.groupId =
            String(group.id);

        remove.dataset.questionId =
            String(question.id);

        head.appendChild(number);
        head.appendChild(remove);

        const text =
            document.createElement('textarea');

        text.placeholder =
            '質問文を入力してください';

        text.value =
            question.text || '';

        text.dataset.role =
            'question-text';

        text.dataset.groupId =
            String(group.id);

        text.dataset.questionId =
            String(question.id);

        const type =
            document.createElement('select');

        [
            ['free', '自由記述'],
            ['single', '単一選択'],
            ['multiple', '複数選択']
        ].forEach(function (item) {
            const option =
                document.createElement('option');

            option.value = item[0];
            option.textContent = item[1];
            option.selected =
                question.type === item[0];

            type.appendChild(option);
        });

        type.dataset.role =
            'question-type';

        type.dataset.groupId =
            String(group.id);

        type.dataset.questionId =
            String(question.id);

        const requiredLabel =
            document.createElement('label');

        const required =
            document.createElement('input');

        required.type = 'checkbox';
        required.checked =
            !!question.required;

        required.dataset.role =
            'question-required';

        required.dataset.groupId =
            String(group.id);

        required.dataset.questionId =
            String(question.id);

        requiredLabel.appendChild(required);
        requiredLabel.appendChild(
            document.createTextNode(
                ' 必須'
            )
        );

        wrapper.appendChild(head);
        wrapper.appendChild(text);
        wrapper.appendChild(type);
        wrapper.appendChild(requiredLabel);

        if (
            question.type === 'single' ||
            question.type === 'multiple'
        ) {
            const optionArea =
                document.createElement('div');

            optionArea.style.marginTop =
                '12px';

            (question.options || [])
                .forEach(function (
                    option,
                    oi
                ) {
                    const row =
                        document.createElement('div');

                    row.className =
                        'option-row';

                    const input =
                        document.createElement('input');

                    input.value =
                        option.text || '';

                    input.dataset.role =
                        'option-text';

                    input.dataset.groupId =
                        String(group.id);

                    input.dataset.questionId =
                        String(question.id);

                    input.dataset.optionIndex =
                        String(oi);

                    row.appendChild(input);

                    const removeOption =
                        document.createElement('button');

                    removeOption.type = 'button';
                    removeOption.className =
                        'btn btn-small';

                    removeOption.textContent =
                        '削除';

                    removeOption.dataset.action =
                        'delete-option';

                    removeOption.dataset.groupId =
                        String(group.id);

                    removeOption.dataset.questionId =
                        String(question.id);

                    removeOption.dataset.optionIndex =
                        String(oi);

                    row.appendChild(
                        removeOption
                    );

                    optionArea.appendChild(row);
                });

            const addOption =
                document.createElement('button');

            addOption.type = 'button';
            addOption.className =
                'btn btn-small';

            addOption.textContent =
                '＋ 選択肢追加';

            addOption.dataset.action =
                'add-option';

            addOption.dataset.groupId =
                String(group.id);

            addOption.dataset.questionId =
                String(question.id);

            optionArea.appendChild(
                addOption
            );

            wrapper.appendChild(
                optionArea
            );
        }

        return wrapper;
    }

    function findGroup(id) {
        if (!editingSurvey) {
            return null;
        }

        return editingSurvey.groups.find(
            function (group) {
                return Number(group.id) ===
                    Number(id);
            }
        ) || null;
    }

    function findQuestion(
        groupId,
        questionId
    ) {
        const group =
            findGroup(groupId);

        if (!group) {
            return null;
        }

        return group.questions.find(
            function (question) {
                return Number(question.id) ===
                    Number(questionId);
            }
        ) || null;
    }

    function addGroup() {
        editingSurvey.groups.push({
            id: nextGroupId++,
            name:
                'グループ' +
                (editingSurvey.groups.length + 1),
            questions: []
        });

        renderEditor();
    }

    function addQuestion(groupId) {
        const group =
            findGroup(groupId);

        if (!group) {
            return;
        }

        group.questions.push({
            id: nextQuestionId++,
            text: '',
            type: 'free',
            required: false,
            options: []
        });

        renderEditor();
    }

    function deleteGroup(groupId) {
        const group =
            findGroup(groupId);

        if (!group) {
            return;
        }

        const message =
            group.questions.length
                ? 'このグループには質問があります。グループと質問を削除しますか？'
                : 'このグループを削除しますか？';

        if (!window.confirm(message)) {
            return;
        }

        editingSurvey.groups =
            editingSurvey.groups.filter(
                function (item) {
                    return Number(item.id) !==
                        Number(groupId);
                }
            );

        renderEditor();
    }

    function deleteQuestion(
        groupId,
        questionId
    ) {
        const group =
            findGroup(groupId);

        if (!group) {
            return;
        }

        if (
            !window.confirm(
                'この質問を削除しますか？'
            )
        ) {
            return;
        }

        group.questions =
            group.questions.filter(
                function (item) {
                    return Number(item.id) !==
                        Number(questionId);
                }
            );

        renderEditor();
    }

    function addOption(
        groupId,
        questionId
    ) {
        const question =
            findQuestion(
                groupId,
                questionId
            );

        if (!question) {
            return;
        }

        if (!Array.isArray(question.options)) {
            question.options = [];
        }

        question.options.push({
            text: '',
            branch: ''
        });

        renderEditor();
    }

    function deleteOption(
        groupId,
        questionId,
        optionIndex
    ) {
        const question =
            findQuestion(
                groupId,
                questionId
            );

        if (!question) {
            return;
        }

        question.options.splice(
            Number(optionIndex),
            1
        );

        renderEditor();
    }

    function collectEditorValues() {
        editingSurvey.name =
            ($('#survey-name')?.value || '')
            .trim();

        editingSurvey.description =
            $('#survey-description')?.value ||
            '';

        editingSurvey.status =
            $('#survey-status')?.value ||
            'draft';

        editingSurvey.start =
            $('#survey-start')?.value ||
            '';

        editingSurvey.end =
            $('#survey-end')?.value ||
            '';

        const selected =
            $('input[name="numbering"]:checked');

        editingSurvey.numbering =
            selected?.value ||
            'global';

        $$(
            '[data-role="group-name"]'
        ).forEach(function (input) {
            const group =
                findGroup(
                    input.dataset.groupId
                );

            if (group) {
                group.name =
                    input.value.trim();
            }
        });

        $$(
            '[data-role="question-text"]'
        ).forEach(function (input) {
            const question =
                findQuestion(
                    input.dataset.groupId,
                    input.dataset.questionId
                );

            if (question) {
                question.text =
                    input.value.trim();
            }
        });

        $$(
            '[data-role="question-type"]'
        ).forEach(function (select) {
            const question =
                findQuestion(
                    select.dataset.groupId,
                    select.dataset.questionId
                );

            if (question) {
                question.type =
                    select.value;

                if (
                    question.type === 'free'
                ) {
                    question.options = [];
                }
            }
        });

        $$(
            '[data-role="question-required"]'
        ).forEach(function (input) {
            const question =
                findQuestion(
                    input.dataset.groupId,
                    input.dataset.questionId
                );

            if (question) {
                question.required =
                    input.checked;
            }
        });

        $$(
            '[data-role="option-text"]'
        ).forEach(function (input) {
            const question =
                findQuestion(
                    input.dataset.groupId,
                    input.dataset.questionId
                );

            if (
                question &&
                question.options[
                    Number(input.dataset.optionIndex)
                ]
            ) {
                question.options[
                    Number(input.dataset.optionIndex)
                ].text =
                    input.value.trim();
            }
        });
    }

    async function saveSurvey(button) {
        collectEditorValues();

        if (!editingSurvey.name) {
            window.alert(
                'アンケート名を入力してください。'
            );
            return;
        }

        try {
            const result =
                await api(
                    'save_survey',
                    {
                        survey:
                            editingSurvey
                    },
                    button
                );

            surveys =
                result.surveys || surveys;

            editingSurvey =
                result.survey || editingSurvey;

            renderSurveyList();
            showToast(
                'アンケートを保存しました。'
            );

            switchPage('list');
        } catch (error) {
            window.alert(
                error.message ||
                '保存に失敗しました。'
            );
        }
    }

    function getSurvey(id) {
        return surveys.find(
            function (survey) {
                return Number(survey.id) ===
                    Number(id);
            }
        ) || null;
    }

    function openDetail(id) {
        const survey =
            getSurvey(id);

        if (!survey) {
            return;
        }

        currentSurveyId =
            Number(survey.id);

        $('#detail-title').textContent =
            survey.name || '';

        $('#detail-status').innerHTML =
            statusHtml(survey.status);

        renderDetailContent('content');

        switchPage('detail');
    }

    function renderDetailContent(tab) {
        const survey =
            getSurvey(currentSurveyId);

        const target =
            $('#detail-content');

        if (!survey || !target) {
            return;
        }

        target.replaceChildren();

        if (tab === 'content') {
            const card =
                document.createElement('div');

            card.className = 'card';

            const title =
                document.createElement('h2');

            title.textContent =
                'アンケート内容';

            card.appendChild(title);

            const description =
                document.createElement('p');

            description.textContent =
                survey.description || '';

            card.appendChild(
                description
            );

            (survey.groups || [])
                .forEach(function (
                    group,
                    gi
                ) {
                    const heading =
                        document.createElement('h3');

                    heading.textContent =
                        group.name || '';

                    card.appendChild(
                        heading
                    );

                    (group.questions || [])
                        .forEach(
                            function (
                                question,
                                qi
                            ) {
                                const p =
                                    document.createElement('p');

                                p.textContent =
                                    questionNumberFor(
                                        survey,
                                        gi,
                                        qi
                                    ) +
                                    ' ' +
                                    (
                                        question.text ||
                                        ''
                                    );

                                card.appendChild(
                                    p
                                );
                            }
                        );
                });

            target.appendChild(card);
            return;
        }

        if (tab === 'status') {
            const card =
                document.createElement('div');

            card.className = 'card';

            const title =
                document.createElement('h2');

            title.textContent =
                '回答状況';

            card.appendChild(title);

            const values = [
                ['回答数', survey.answers || 0],
                ['メール送信対象者数',
                    survey.target || 0],
                ['メール送信済み数',
                    survey.sent || 0],
                [
                    '未回答数',
                    Math.max(
                        0,
                        Number(survey.target || 0) -
                        Number(survey.answers || 0)
                    )
                ]
            ];

            values.forEach(function (item) {
                const p =
                    document.createElement('p');

                p.textContent =
                    item[0] +
                    '：' +
                    item[1];

                card.appendChild(p);
            });

            target.appendChild(card);
            return;
        }

        if (tab === 'result') {
            const card =
                document.createElement('div');

            card.className = 'card';

            const title =
                document.createElement('h2');

            title.textContent =
                '回答結果';

            card.appendChild(title);

            const p =
                document.createElement('p');

            p.textContent =
                Number(survey.answers || 0) > 0
                    ? '回答結果を確認できます。'
                    : '回答結果はまだありません。';

            card.appendChild(p);
            target.appendChild(card);
            return;
        }

        renderSendScreen(survey);
    }

    function questionNumberFor(
        survey,
        groupIndex,
        questionIndex
    ) {
        if (
            survey.numbering === 'group'
        ) {
            return 'Q' +
                (groupIndex + 1) +
                '-' +
                (questionIndex + 1);
        }

        let count = 0;

        for (
            let i = 0;
            i < groupIndex;
            i++
        ) {
            count +=
                survey.groups[i]
                    .questions.length;
        }

        return 'Q' +
            (count + questionIndex + 1);
    }

    function renderSendScreen(survey) {
        const target =
            $('#detail-content');

        const card =
            document.createElement('div');

        card.className = 'card';

        const title =
            document.createElement('h2');

        title.textContent =
            '送信';

        card.appendChild(title);

        const search =
            document.createElement('input');

        search.id =
            'send-customer-search';

        search.placeholder =
            '顧客を検索';

        search.value = '';

        card.appendChild(search);

        const customerBox =
            document.createElement('div');

        customerBox.id =
            'send-customer-list';

        customerBox.style.marginTop =
            '15px';

        card.appendChild(
            customerBox
        );

        const subject =
            document.createElement('input');

        subject.id =
            'send-subject';

        subject.placeholder =
            '件名';

        subject.value =
            survey.name +
            ' のご案内';

        card.appendChild(subject);

        const body =
            document.createElement('textarea');

        body.id =
            'send-body';

        body.style.marginTop =
            '10px';

        body.placeholder =
            'メール本文';

        body.value =
            'アンケートへのご協力をお願いいたします。';

        card.appendChild(body);

        const button =
            document.createElement('button');

        button.type = 'button';
        button.className =
            'btn btn-primary';

        button.style.marginTop =
            '12px';

        button.textContent =
            'メール送信';

        button.dataset.action =
            'send-mail';

        card.appendChild(button);

        target.appendChild(card);

        selectedCustomers = [];

        renderSendCustomers();

        if (search) {
            search.addEventListener(
                'input',
                function () {
                    renderSendCustomers();
                }
            );
        }
    }

    function renderSendCustomers() {
        const target =
            $('#send-customer-list');

        if (!target) {
            return;
        }

        const search =
            ($('#send-customer-search')
                ?.value || '')
            .toLowerCase();

        target.replaceChildren();

        customers
            .filter(function (customer) {
                const value =
                    [
                        customer.name,
                        customer.email,
                        customer.company
                    ]
                    .join(' ')
                    .toLowerCase();

                return !search ||
                    value.includes(search);
            })
            .forEach(function (customer) {
                const label =
                    document.createElement('label');

                label.style.display =
                    'block';

                label.style.padding =
                    '7px 0';

                const checkbox =
                    document.createElement('input');

                checkbox.type =
                    'checkbox';

                checkbox.checked =
                    selectedCustomers
                        .includes(
                            Number(customer.id)
                        );

                checkbox.dataset.customerId =
                    String(customer.id);

                label.appendChild(
                    checkbox
                );

                label.appendChild(
                    document.createTextNode(
                        ' ' +
                        (customer.name || '') +
                        ' / ' +
                        (customer.email || '')
                    )
                );

                target.appendChild(
                    label
                );
            });
    }

    async function sendMail(button) {
        const subject =
            $('#send-subject')?.value || '';

        const body =
            $('#send-body')?.value || '';

        if (
            !selectedCustomers.length
        ) {
            window.alert(
                '送信対象者を選択してください。'
            );
            return;
        }

        try {
            const result =
                await api(
                    'send_mail',
                    {
                        surveyId:
                            currentSurveyId,
                        customerIds:
                            selectedCustomers,
                        subject: subject,
                        body: body
                    },
                    button
                );

            const survey =
                getSurvey(currentSurveyId);

            if (survey) {
                survey.target =
                    result.target || 0;

                survey.sent =
                    (survey.sent || 0) +
                    (result.successCount || 0);
            }

            showModal(
                '送信結果',
                '<div class="notice success">' +
                escapeHtml(
                    result.message || ''
                ) +
                '</div>' +
                '<p>送信対象者数：' +
                escapeHtml(
                    result.target || 0
                ) +
                '</p>' +
                '<p>送信成功数：' +
                escapeHtml(
                    result.successCount || 0
                ) +
                '</p>' +
                '<p>送信失敗数：' +
                escapeHtml(
                    result.failedCount || 0
                ) +
                '</p>'
            );
        } catch (error) {
            window.alert(
                error.message ||
                'メール送信に失敗しました。'
            );
        }
    }

    function renderCustomers() {
        const target =
            $('#customer-list');

        if (!target) {
            return;
        }

        const search =
            ($('#customer-search')
                ?.value || '')
            .toLowerCase();

        target.replaceChildren();

        customers
            .filter(function (customer) {
                const value =
                    [
                        customer.name,
                        customer.email,
                        customer.company,
                        customer.code
                    ]
                    .join(' ')
                    .toLowerCase();

                return !search ||
                    value.includes(search);
            })
            .forEach(function (customer) {
                const row =
                    document.createElement('tr');

                [
                    customer.name,
                    customer.email,
                    customer.company,
                    customer.code
                ].forEach(function (value) {
                    const cell =
                        document.createElement('td');

                    cell.textContent =
                        value || '';

                    row.appendChild(cell);
                });

                target.appendChild(row);
            });
    }

    async function loadCustomers(button) {
        try {
            const result =
                await api(
                    'load_customers',
                    {},
                    button
                );

            customers =
                result.customers || [];

            renderCustomers();

            showToast(
                '顧客一覧を更新しました。'
            );
        } catch (error) {
            window.alert(
                error.message ||
                '顧客一覧を取得できませんでした。'
            );
        }
    }

    function renderSettings() {
        const target =
            $('#settings-content');

        if (!target) {
            return;
        }

        target.replaceChildren();

        if (
            currentSettingsTab ===
            'mail'
        ) {
            renderMailSettings(target);
        } else {
            renderKintoneSettings(target);
        }
    }

    function renderMailSettings(target) {
        const card =
            document.createElement('div');

        card.className = 'card';

        card.innerHTML =
            '<h2>メール送信設定</h2>' +
            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>SMTPサーバ</label>' +
            '<input id="mail-smtp">' +
            '</div>' +
            '<div class="field">' +
            '<label>ポート番号</label>' +
            '<input id="mail-port">' +
            '</div>' +
            '</div>' +
            '<div class="field">' +
            '<label>接続方式</label>' +
            '<select id="mail-security">' +
            '<option value="なし">なし</option>' +
            '<option value="STARTTLS">STARTTLS</option>' +
            '<option value="SSL/TLS">SSL/TLS</option>' +
            '</select>' +
            '</div>' +
            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>認証ユーザー名</label>' +
            '<input id="mail-username">' +
            '</div>' +
            '<div class="field">' +
            '<label>認証パスワード</label>' +
            '<input id="mail-password" type="password">' +
            '</div>' +
            '</div>' +
            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>送信元メールアドレス</label>' +
            '<input id="mail-from">' +
            '</div>' +
            '<div class="field">' +
            '<label>送信元名</label>' +
            '<input id="mail-from-name">' +
            '</div>' +
            '</div>' +
            '<div class="field">' +
            '<label>テスト送信先</label>' +
            '<input id="mail-test-to">' +
            '</div>' +
            '<button type="button" ' +
            'class="btn btn-primary" ' +
            'data-action="save-settings">' +
            '設定を保存' +
            '</button> ' +
            '<button type="button" ' +
            'class="btn" ' +
            'data-action="test-mail">' +
            '接続確認・テスト送信' +
            '</button>';

        target.appendChild(card);

        $('#mail-smtp').value =
            settings.mail.smtp || '';

        $('#mail-port').value =
            settings.mail.port || '587';

        $('#mail-security').value =
            settings.mail.security ||
            'STARTTLS';

        $('#mail-username').value =
            settings.mail.username || '';

        $('#mail-from').value =
            settings.mail.from || '';

        $('#mail-from-name').value =
            settings.mail.fromName || '';
    }

    function renderKintoneSettings(target) {
        const card =
            document.createElement('div');

        card.className = 'card';

        card.innerHTML =
            '<h2>kintone設定</h2>' +
            '<div class="notice">' +
            'ログイン名・パスワードによる認証を使用します。' +
            'APIトークンは使用しません。' +
            '</div>' +
            '<div class="field">' +
            '<label>kintoneの利用先</label>' +
            '<input id="kt-domain">' +
            '</div>' +
            '<div class="field">' +
            '<label>顧客管理アプリID</label>' +
            '<input id="kt-appid">' +
            '</div>' +
            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>ログイン名</label>' +
            '<input id="kt-login">' +
            '</div>' +
            '<div class="field">' +
            '<label>パスワード</label>' +
            '<input id="kt-password" type="password">' +
            '</div>' +
            '</div>' +
            '<div class="field">' +
            '<label>プロキシ host:port</label>' +
            '<input id="kt-proxy" placeholder="proxy.example.local:8080">' +
            '</div>' +
            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>顧客名フィールド</label>' +
            '<input id="kt-name-field">' +
            '</div>' +
            '<div class="field">' +
            '<label>メールアドレスフィールド</label>' +
            '<input id="kt-email-field">' +
            '</div>' +
            '</div>' +
            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>会社名フィールド</label>' +
            '<input id="kt-company-field">' +
            '</div>' +
            '<div class="field">' +
            '<label>顧客番号フィールド</label>' +
            '<input id="kt-code-field">' +
            '</div>' +
            '</div>' +
            '<button type="button" ' +
            'class="btn btn-primary" ' +
            'data-action="save-settings">' +
            '設定を保存' +
            '</button> ' +
            '<button type="button" ' +
            'class="btn" ' +
            'data-action="test-kintone">' +
            '接続確認' +
            '</button>';

        target.appendChild(card);

        $('#kt-domain').value =
            settings.kintone.domain || '';

        $('#kt-appid').value =
            settings.kintone.appId || '';

        $('#kt-login').value =
            settings.kintone.loginName || '';

        $('#kt-proxy').value =
            settings.kintone.proxy || '';

        $('#kt-name-field').value =
            settings.kintone.nameField || '';

        $('#kt-email-field').value =
            settings.kintone.emailField || '';

        $('#kt-company-field').value =
            settings.kintone.companyField || '';

        $('#kt-code-field').value =
            settings.kintone.codeField || '';
    }

    function collectSettings() {
        return {
            mail: {
                smtp:
                    $('#mail-smtp')?.value.trim() || '',
                port:
                    $('#mail-port')?.value.trim() || '',
                security:
                    $('#mail-security')?.value || 'STARTTLS',
                username:
                    $('#mail-username')?.value.trim() || '',
                password:
                    $('#mail-password')?.value || '',
                from:
                    $('#mail-from')?.value.trim() || '',
                fromName:
                    $('#mail-from-name')?.value.trim() || ''
            },
            kintone: {
                domain:
                    $('#kt-domain')?.value.trim() || '',
                appId:
                    $('#kt-appid')?.value.trim() || '',
                loginName:
                    $('#kt-login')?.value.trim() || '',
                password:
                    $('#kt-password')?.value || '',
                proxy:
                    $('#kt-proxy')?.value.trim() || '',
                nameField:
                    $('#kt-name-field')?.value.trim() || '',
                emailField:
                    $('#kt-email-field')?.value.trim() || '',
                companyField:
                    $('#kt-company-field')?.value.trim() || '',
                codeField:
                    $('#kt-code-field')?.value.trim() || ''
            }
        };
    }

    async function saveSettings(button) {
        const data =
            collectSettings();

        try {
            await api(
                'save_settings',
                data,
                button
            );

            if (
                data.mail.password === ''
            ) {
                data.mail.password = '';
            }

            if (
                data.kintone.password === ''
            ) {
                data.kintone.password = '';
            }

            settings.mail.smtp =
                data.mail.smtp;

            settings.mail.port =
                data.mail.port;

            settings.mail.security =
                data.mail.security;

            settings.mail.username =
                data.mail.username;

            settings.mail.from =
                data.mail.from;

            settings.mail.fromName =
                data.mail.fromName;

            settings.kintone =
                Object.assign(
                    {},
                    settings.kintone,
                    data.kintone
                );

            showToast(
                '設定を保存しました。'
            );

            renderSettings();
        } catch (error) {
            window.alert(
                error.message ||
                '設定の保存に失敗しました。'
            );
        }
    }

    async function testMail(button) {
        const to =
            window.prompt(
                'テスト送信先メールアドレスを入力してください。',
                ''
            );

        if (!to) {
            return;
        }

        try {
            const result =
                await api(
                    'test_mail',
                    { to: to },
                    button
                );

            showToast(
                result.message ||
                'テストメールを送信しました。'
            );
        } catch (error) {
            window.alert(
                error.message ||
                'テストメールの送信に失敗しました。'
            );
        }
    }

    async function testKintone(button) {
        try {
            const data =
                collectSettings();

            await api(
                'save_settings',
                data,
                button
            );

            const result =
                await api(
                    'test_kintone',
                    {},
                    button
                );

            showToast(
                result.message ||
                'kintoneへの接続を確認しました。'
            );
        } catch (error) {
            window.alert(
                error.message ||
                'kintoneへの接続に失敗しました。'
            );
        }
    }

    document.addEventListener(
        'click',
        async function (event) {
            const button =
                event.target.closest(
                    'button'
                );

            if (!button) {
                return;
            }

            if (
                button.dataset.page
            ) {
                switchPage(
                    button.dataset.page
                );
                return;
            }

            const detailTab =
                button.dataset.detailTab;

            if (detailTab) {
                $$('.tabs button').forEach(
                    function (item) {
                        if (
                            item.dataset.detailTab
                        ) {
                            item.classList.toggle(
                                'active',
                                item === button
                            );
                        }
                    }
                );

                renderDetailContent(
                    detailTab
                );

                return;
            }

            const settingsTab =
                button.dataset.settingsTab;

            if (settingsTab) {
                currentSettingsTab =
                    settingsTab;

                $$('.tabs button').forEach(
                    function (item) {
                        if (
                            item.dataset.settingsTab
                        ) {
                            item.classList.toggle(
                                'active',
                                item === button
                            );
                        }
                    }
                );

                renderSettings();
                return;
            }

            const action =
                button.dataset.action;

            if (!action) {
                return;
            }

            if (
                button.disabled &&
                action !== 'close-modal'
            ) {
                return;
            }

            switch (action) {
                case 'new-survey':
                    newSurvey();
                    break;

                case 'editor-back':
                    switchPage('list');
                    break;

                case 'save-survey':
                    await saveSurvey(button);
                    break;

                case 'open-detail':
                    openDetail(
                        button.dataset.id
                    );
                    break;

                case 'edit-survey':
                    editSurvey(
                        button.dataset.id
                    );
                    break;

                case 'detail-edit':
                    editSurvey(
                        currentSurveyId
                    );
                    break;

                case 'detail-back':
                    switchPage('list');
                    break;

                case 'add-group':
                    addGroup();
                    break;

                case 'add-question':
                    addQuestion(
                        button.dataset.groupId
                    );
                    break;

                case 'delete-group':
                    deleteGroup(
                        button.dataset.groupId
                    );
                    break;

                case 'delete-question':
                    deleteQuestion(
                        button.dataset.groupId,
                        button.dataset.questionId
                    );
                    break;

                case 'add-option':
                    addOption(
                        button.dataset.groupId,
                        button.dataset.questionId
                    );
                    break;

                case 'delete-option':
                    deleteOption(
                        button.dataset.groupId,
                        button.dataset.questionId,
                        button.dataset.optionIndex
                    );
                    break;

                case 'publish':
                    if (
                        window.confirm(
                            'このアンケートを公開しますか？'
                        )
                    ) {
                        try {
                            const result =
                                await api(
                                    'change_status',
                                    {
                                        id:
                                            button.dataset.id,
                                        status:
                                            'open'
                                    },
                                    button
                                );

                            surveys =
                                result.surveys ||
                                surveys;

                            renderSurveyList();
                            showToast(
                                'アンケートを公開しました。'
                            );
                        } catch (error) {
                            window.alert(
                                error.message
                            );
                        }
                    }
                    break;

                case 'end-survey':
                    if (
                        window.confirm(
                            'このアンケートを終了しますか？'
                        )
                    ) {
                        try {
                            const result =
                                await api(
                                    'change_status',
                                    {
                                        id:
                                            button.dataset.id,
                                        status:
                                            'end'
                                    },
                                    button
                                );

                            surveys =
                                result.surveys ||
                                surveys;

                            renderSurveyList();
                            showToast(
                                'アンケートを終了しました。'
                            );
                        } catch (error) {
                            window.alert(
                                error.message
                            );
                        }
                    }
                    break;

                case 'delete-survey':
                    if (
                        window.confirm(
                            'このアンケートを削除しますか？'
                        )
                    ) {
                        try {
                            const result =
                                await api(
                                    'delete_survey',
                                    {
                                        id:
                                            button.dataset.id
                                    },
                                    button
                                );

                            surveys =
                                result.surveys ||
                                surveys;

                            renderSurveyList();
                            showToast(
                                'アンケートを削除しました。'
                            );
                        } catch (error) {
                            window.alert(
                                error.message
                            );
                        }
                    }
                    break;

                case 'load-customers':
                    await loadCustomers(
                        button
                    );
                    break;

                case 'send-mail':
                    await sendMail(
                        button
                    );
                    break;

                case 'save-settings':
                    await saveSettings(
                        button
                    );
                    break;

                case 'test-mail':
                    await testMail(
                        button
                    );
                    break;

                case 'test-kintone':
                    await testKintone(
                        button
                    );
                    break;

                case 'close-modal':
                    closeModal();
                    break;
            }
        }
    );

    document.addEventListener(
        'input',
        function (event) {
            const target =
                event.target;

            if (
                target &&
                target.id ===
                    'customer-search'
            ) {
                renderCustomers();
            }

            if (
                target &&
                target.dataset &&
                target.dataset.role
            ) {
                if (
                    target.dataset.role ===
                    'group-name'
                ) {
                    const group =
                        findGroup(
                            target.dataset.groupId
                        );

                    if (group) {
                        group.name =
                            target.value;
                    }
                }

                if (
                    target.dataset.role ===
                    'question-text'
                ) {
                    const question =
                        findQuestion(
                            target.dataset.groupId,
                            target.dataset.questionId
                        );

                    if (question) {
                        question.text =
                            target.value;
                    }
                }

                if (
                    target.dataset.role ===
                    'option-text'
                ) {
                    const question =
                        findQuestion(
                            target.dataset.groupId,
                            target.dataset.questionId
                        );

                    if (
                        question &&
                        question.options[
                            Number(
                                target.dataset.optionIndex
                            )
                        ]
                    ) {
                        question.options[
                            Number(
                                target.dataset.optionIndex
                            )
                        ].text =
                            target.value;
                    }
                }
            }
        }
    );

    document.addEventListener(
        'change',
        function (event) {
            const target =
                event.target;

            if (
                !target ||
                !target.dataset ||
                !target.dataset.role
            ) {
                return;
            }

            if (
                target.dataset.role ===
                'question-type'
            ) {
                const question =
                    findQuestion(
                        target.dataset.groupId,
                        target.dataset.questionId
                    );

                if (question) {
                    question.type =
                        target.value;

                    if (
                        question.type ===
                        'free'
                    ) {
                        question.options = [];
                    }

                    renderEditor();
                }
            }

            if (
                target.dataset.role ===
                'question-required'
            ) {
                const question =
                    findQuestion(
                        target.dataset.groupId,
                        target.dataset.questionId
                    );

                if (question) {
                    question.required =
                        target.checked;
                }
            }
        }
    );

    renderSurveyList();
    renderCustomers();
    renderSettings();
});
</script>

</body>
</html>

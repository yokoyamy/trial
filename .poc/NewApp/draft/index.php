<?php
declare(strict_types=1);

namespace yokoyamy\surveyoperation\app;

use RuntimeException;
use Throwable;

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

const APP_ROOT = __DIR__;
const DATA_DIR = APP_ROOT . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE = APP_ROOT . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';

if (!isset($_SESSION['yokoyamy_surveyoperation_app']) || !is_array($_SESSION['yokoyamy_surveyoperation_app'])) {
    $_SESSION['yokoyamy_surveyoperation_app'] = [];
}

if (
    !isset($_SESSION['yokoyamy_surveyoperation_app']['csrf_token']) ||
    !is_string($_SESSION['yokoyamy_surveyoperation_app']['csrf_token']) ||
    $_SESSION['yokoyamy_surveyoperation_app']['csrf_token'] === ''
) {
    $_SESSION['yokoyamy_surveyoperation_app']['csrf_token'] = bin2hex(random_bytes(32));
}

function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $response, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function ensure_data_directory(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存先を作成できません。');
        }
    }
}

function read_json_file(string $file, array $default): array
{
    if (!is_file($file)) {
        return $default;
    }

    $json = file_get_contents($file);

    if ($json === false || trim($json) === '') {
        return $default;
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : $default;
}

function write_json_file(string $file, array $data): void
{
    ensure_data_directory();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('データを保存できません。');
    }

    $tmp = $file . '.tmp';

    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('データファイルを書き込めません。');
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データファイルを更新できません。');
    }
}

function default_mail_settings(): array
{
    return [
        'smtp' => '',
        'port' => '587',
        'security' => 'STARTTLS',
        'username' => '',
        'password' => '',
        'from' => '',
        'fromName' => 'アンケート事務局',
        'ready' => false,
    ];
}

function default_kintone_settings(): array
{
    return [
        'domain' => '',
        'appId' => '',
        'loginName' => '',
        'password' => '',
        'proxyHost' => '',
        'proxyPort' => '',
        'proxyAuth' => false,
        'sslVerify' => false,
        'ready' => false,
    ];
}

function load_settings(): array
{
    $default = [
        'mail' => default_mail_settings(),
        'kintone' => default_kintone_settings(),
    ];

    $saved = read_json_file(SETTINGS_FILE, []);

    if (isset($saved['mail']) && is_array($saved['mail'])) {
        $default['mail'] = array_merge($default['mail'], $saved['mail']);
    }

    if (isset($saved['kintone']) && is_array($saved['kintone'])) {
        $default['kintone'] = array_merge($default['kintone'], $saved['kintone']);
    }

    return $default;
}

function public_mail_settings(array $mail): array
{
    return [
        'smtp' => (string)($mail['smtp'] ?? ''),
        'port' => (string)($mail['port'] ?? ''),
        'security' => (string)($mail['security'] ?? 'STARTTLS'),
        'username' => (string)($mail['username'] ?? ''),
        'hasPassword' => trim((string)($mail['password'] ?? '')) !== '',
        'from' => (string)($mail['from'] ?? ''),
        'fromName' => (string)($mail['fromName'] ?? ''),
        'ready' => (bool)($mail['ready'] ?? false),
    ];
}

function public_kintone_settings(array $settings): array
{
    return [
        'domain' => (string)($settings['domain'] ?? ''),
        'appId' => (string)($settings['appId'] ?? ''),
        'loginName' => (string)($settings['loginName'] ?? ''),
        'hasPassword' => trim((string)($settings['password'] ?? '')) !== '',
        'proxyHost' => (string)($settings['proxyHost'] ?? ''),
        'proxyPort' => (string)($settings['proxyPort'] ?? ''),
        'ready' => (bool)($settings['ready'] ?? false),
    ];
}

function verify_csrf(): void
{
    $sessionToken = $_SESSION['yokoyamy_surveyoperation_app']['csrf_token'] ?? '';
    $requestToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        !is_string($sessionToken) ||
        !is_string($requestToken) ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $requestToken)
    ) {
        json_response([
            'success' => false,
            'message' => '操作を確認できませんでした。画面を再読み込みしてください。',
        ], 403);
    }
}

function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

function get_response_status(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $header, $matches)) {
            return (int)$matches[1];
        }
    }

    return 0;
}

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim($domain, '/');

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

function make_cybozu_auth_header(string $loginName, string $password): string
{
    $loginName = trim($loginName);
    $password = trim($password);

    return 'X-Cybozu-Authorization: ' . base64_encode($loginName . ':' . $password);
}

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload,
    array $config
): array {
    $method = strtoupper($method);

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
    ];

    if ($method !== 'GET' && $payload !== null) {
        if (is_array($payload)) {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($encoded === false) {
                return [
                    'success' => false,
                    'status' => 0,
                    'message' => '送信データを作成できません。',
                    'data' => [],
                ];
            }

            $httpOptions['content'] = $encoded;
        } else {
            $httpOptions['content'] = (string)$payload;
        }
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];

    $proxyHost = trim((string)($config['proxyHost'] ?? ''));
    $proxyPort = trim((string)($config['proxyPort'] ?? ''));

    if ($proxyHost !== '' && $proxyPort !== '') {
        $contextOptions['http']['proxy'] = 'tcp://' . $proxyHost . ':' . $proxyPort;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = get_safe_response_headers();
    $status = get_response_status($responseHeaders);

    $decoded = json_decode($responseBody ?? '', true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'message' => '',
            'data' => is_array($decoded) ? $decoded : [],
        ];
    }

    $message = 'kintone APIとの通信に失敗しました。';

    if (is_array($decoded) && isset($decoded['message'])) {
        $message = (string)$decoded['message'];
    }

    if (is_array($decoded) && isset($decoded['code'])) {
        $message = (string)$decoded['code'] . ': ' . $message;
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

function validate_mail_settings(array $input): array
{
    $smtp = trim((string)($input['smtp'] ?? ''));
    $port = trim((string)($input['port'] ?? ''));
    $security = trim((string)($input['security'] ?? 'STARTTLS'));
    $username = trim((string)($input['username'] ?? ''));
    $from = trim((string)($input['from'] ?? ''));
    $fromName = trim((string)($input['fromName'] ?? ''));

    if ($smtp === '') {
        throw new RuntimeException('SMTPサーバを入力してください。');
    }

    if ($port === '' || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('ポート番号を正しく入力してください。');
    }

    if (!in_array($security, ['なし', 'STARTTLS', 'SSL/TLS'], true)) {
        throw new RuntimeException('接続方式が正しくありません。');
    }

    if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('送信元メールアドレスを正しく入力してください。');
    }

    return [
        'smtp' => $smtp,
        'port' => $port,
        'security' => $security,
        'username' => $username,
        'from' => $from,
        'fromName' => $fromName,
    ];
}

function smtp_read($socket): array
{
    $lines = [];

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $line = rtrim($line, "\r\n");
        $lines[] = $line;

        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    return $lines;
}

function smtp_code(array $lines): int
{
    if (!$lines) {
        return 0;
    }

    if (preg_match('/^(\d{3})/', $lines[count($lines) - 1], $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

function smtp_command($socket, string $command, array $expected): array
{
    fwrite($socket, $command . "\r\n");

    $lines = smtp_read($socket);
    $code = smtp_code($lines);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTPサーバから想定外の応答がありました。');
    }

    return $lines;
}

function smtp_connect(array $settings)
{
    $host = trim((string)$settings['smtp']);
    $port = (int)$settings['port'];
    $security = (string)$settings['security'];

    $transport = 'tcp://';
    $targetHost = $host;

    if ($security === 'SSL/TLS') {
        $transport = 'ssl://';
    }

    $socket = @stream_socket_client(
        $transport . $targetHost . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException('SMTPサーバへ接続できませんでした。');
    }

    stream_set_timeout($socket, 20);

    $greeting = smtp_read($socket);

    if (!in_array(smtp_code($greeting), [220], true)) {
        fclose($socket);
        throw new RuntimeException('SMTPサーバから接続を拒否されました。');
    }

    smtp_command($socket, 'EHLO localhost', [250]);

    if ($security === 'STARTTLS') {
        smtp_command($socket, 'STARTTLS', [220]);

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);
            throw new RuntimeException('SMTPの暗号化通信を開始できませんでした。');
        }

        smtp_command($socket, 'EHLO localhost', [250]);
    }

    $username = trim((string)$settings['username']);
    $password = (string)$settings['password'];

    if ($username !== '' || $password !== '') {
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
    }

    return $socket;
}

function smtp_send_mail(
    array $settings,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('送信先メールアドレスが正しくありません。');
    }

    $socket = smtp_connect($settings);

    try {
        $from = trim((string)$settings['from']);
        $fromName = trim((string)$settings['fromName']);

        $encodedFromName = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?='
            : $from;

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $headers = [];
        $headers[] = 'From: ' . $encodedFromName . ' <' . $from . '>';
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . $encodedSubject;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $safeBody = str_replace(["\r\n", "\r"], "\n", $body);
        $safeBody = str_replace("\n.", "\n..", $safeBody);

        fwrite(
            $socket,
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            str_replace("\n", "\r\n", $safeBody) .
            "\r\n.\r\n"
        );

        $response = smtp_read($socket);

        if (smtp_code($response) !== 250) {
            throw new RuntimeException('メール送信に失敗しました。');
        }

        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function handle_api(array $settings): never
{
    $action = (string)($_POST['action'] ?? '');

    if ($action === '') {
        json_response([
            'success' => false,
            'message' => '操作を指定してください。',
        ], 400);
    }

    verify_csrf();

    try {
        if ($action === 'load_settings') {
            json_response([
                'success' => true,
                'mail' => public_mail_settings($settings['mail']),
                'kintone' => public_kintone_settings($settings['kintone']),
            ]);
        }

        if ($action === 'save_mail_settings') {
            $mail = validate_mail_settings($_POST);

            $current = load_settings();

            $newPassword = (string)($_POST['password'] ?? '');

            if ($newPassword !== '') {
                $mail['password'] = $newPassword;
            } else {
                $mail['password'] = (string)($current['mail']['password'] ?? '');
            }

            $mail['ready'] =
                $mail['smtp'] !== '' &&
                $mail['port'] !== '' &&
                $mail['from'] !== '';

            $current['mail'] = $mail;

            write_json_file(SETTINGS_FILE, $current);

            json_response([
                'success' => true,
                'message' => 'メール送信設定を保存しました。',
                'mail' => public_mail_settings($mail),
            ]);
        }

        if ($action === 'test_mail_settings') {
            $mail = $settings['mail'];

            if (
                trim((string)($mail['smtp'] ?? '')) === '' ||
                trim((string)($mail['port'] ?? '')) === '' ||
                trim((string)($mail['from'] ?? '')) === ''
            ) {
                json_response([
                    'success' => false,
                    'message' => 'メール送信設定が未完了です。',
                ], 400);
            }

            $socket = smtp_connect($mail);
            smtp_command($socket, 'QUIT', [221]);
            fclose($socket);

            json_response([
                'success' => true,
                'message' => 'SMTPサーバへの接続を確認しました。',
            ]);
        }

        if ($action === 'save_kintone_settings') {
            $current = load_settings();

            $domain = trim((string)($_POST['domain'] ?? ''));
            $appId = trim((string)($_POST['appId'] ?? ''));
            $loginName = trim((string)($_POST['loginName'] ?? ''));
            $proxyHost = trim((string)($_POST['proxyHost'] ?? ''));
            $proxyPort = trim((string)($_POST['proxyPort'] ?? ''));
            $newPassword = (string)($_POST['password'] ?? '');

            if ($domain === '' || $appId === '' || $loginName === '') {
                throw new RuntimeException('キントーンの必須項目を入力してください。');
            }

            if (!ctype_digit($appId)) {
                throw new RuntimeException('顧客管理アプリIDを正しく入力してください。');
            }

            if ($proxyHost !== '' && $proxyPort === '') {
                throw new RuntimeException('プロキシを使用する場合はポート番号を入力してください。');
            }

            $password = $newPassword !== ''
                ? $newPassword
                : (string)($current['kintone']['password'] ?? '');

            $current['kintone'] = [
                'domain' => $domain,
                'appId' => $appId,
                'loginName' => $loginName,
                'password' => $password,
                'proxyHost' => $proxyHost,
                'proxyPort' => $proxyPort,
                'proxyAuth' => false,
                'sslVerify' => false,
                'ready' => true,
            ];

            write_json_file(SETTINGS_FILE, $current);

            json_response([
                'success' => true,
                'message' => 'キントーン設定を保存しました。',
                'kintone' => public_kintone_settings($current['kintone']),
            ]);
        }

        if ($action === 'test_kintone') {
            $kt = $settings['kintone'];

            if (
                trim((string)$kt['domain']) === '' ||
                trim((string)$kt['appId']) === '' ||
                trim((string)$kt['loginName']) === '' ||
                trim((string)$kt['password']) === ''
            ) {
                throw new RuntimeException('キントーン設定が未完了です。');
            }

            $url = kintone_build_url(
                (string)$kt['domain'],
                '/k/v1/app.json?id=' . rawurlencode((string)$kt['appId'])
            );

            $headers = [
                make_cybozu_auth_header(
                    (string)$kt['loginName'],
                    (string)$kt['password']
                ),
                'Content-Type: application/json',
            ];

            $result = kintone_api_request(
                'GET',
                $url,
                $headers,
                null,
                $kt
            );

            if (!$result['success']) {
                throw new RuntimeException((string)$result['message']);
            }

            json_response([
                'success' => true,
                'message' => 'キントーンへの接続を確認しました。',
            ]);
        }

        if ($action === 'get_customers') {
            $kt = $settings['kintone'];

            if (
                trim((string)$kt['domain']) === '' ||
                trim((string)$kt['appId']) === '' ||
                trim((string)$kt['loginName']) === '' ||
                trim((string)$kt['password']) === ''
            ) {
                throw new RuntimeException('キントーン設定が未完了です。');
            }

            $params = [
                'app' => (int)$kt['appId'],
                'query' => 'order by $id asc limit 500',
            ];

            $url = kintone_build_url(
                (string)$kt['domain'],
                '/k/v1/records.json'
            );

            $url .= '?' . http_build_query(
                $params,
                '',
                '&',
                PHP_QUERY_RFC3986
            );

            $headers = [
                make_cybozu_auth_header(
                    (string)$kt['loginName'],
                    (string)$kt['password']
                ),
            ];

            $result = kintone_api_request(
                'GET',
                $url,
                $headers,
                null,
                $kt
            );

            if (!$result['success']) {
                throw new RuntimeException((string)$result['message']);
            }

            $records = $result['data']['records'] ?? [];
            $customers = [];

            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $customers[] = [
                        'id' => (string)($record['$id']['value'] ?? ''),
                        'name' => (string)(
                            $record['name']['value'] ??
                            $record['顧客名']['value'] ??
                            ''
                        ),
                        'email' => (string)(
                            $record['email']['value'] ??
                            $record['メールアドレス']['value'] ??
                            ''
                        ),
                        'company' => (string)(
                            $record['company']['value'] ??
                            $record['会社名']['value'] ??
                            ''
                        ),
                        'code' => (string)(
                            $record['code']['value'] ??
                            $record['顧客番号']['value'] ??
                            ''
                        ),
                    ];
                }
            }

            write_json_file(CUSTOMERS_FILE, ['customers' => $customers]);

            json_response([
                'success' => true,
                'message' => count($customers) . '件の顧客情報を取得しました。',
                'customers' => $customers,
            ]);
        }

        if ($action === 'send_mail') {
            $mail = $settings['mail'];

            if (!(bool)($mail['ready'] ?? false)) {
                throw new RuntimeException('メール送信設定を完了してください。');
            }

            $recipients = json_decode(
                (string)($_POST['recipients'] ?? '[]'),
                true
            );

            if (!is_array($recipients) || count($recipients) === 0) {
                throw new RuntimeException('送信対象者を選択してください。');
            }

            $subject = trim((string)($_POST['subject'] ?? ''));
            $body = (string)($_POST['body'] ?? '');

            if ($subject === '') {
                throw new RuntimeException('件名を入力してください。');
            }

            $successCount = 0;
            $failed = [];

            foreach ($recipients as $recipient) {
                if (!is_array($recipient)) {
                    continue;
                }

                $email = trim((string)($recipient['email'] ?? ''));
                $name = trim((string)($recipient['name'] ?? ''));

                if ($email === '') {
                    $failed[] = [
                        'name' => $name,
                        'email' => '',
                        'message' => 'メールアドレスがありません。',
                    ];
                    continue;
                }

                try {
                    smtp_send_mail(
                        $mail,
                        $email,
                        $subject,
                        $body
                    );

                    $successCount++;
                } catch (Throwable $e) {
                    $failed[] = [
                        'name' => $name,
                        'email' => $email,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            json_response([
                'success' => count($failed) === 0,
                'message' => 'メール送信処理が完了しました。',
                'total' => count($recipients),
                'successCount' => $successCount,
                'failedCount' => count($failed),
                'failed' => $failed,
            ]);
        }

        throw new RuntimeException('不明な操作です。');
    } catch (Throwable $e) {
        json_response([
            'success' => false,
            'message' => $e->getMessage(),
        ], 400);
    }
}

$settings = load_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_api($settings);
}

$initialMail = public_mail_settings($settings['mail']);
$initialKintone = public_kintone_settings($settings['kintone']);

$initialCustomersData = read_json_file(
    CUSTOMERS_FILE,
    ['customers' => []]
);

$initialCustomers = [];

if (isset($initialCustomersData['customers']) && is_array($initialCustomersData['customers'])) {
    $initialCustomers = $initialCustomersData['customers'];
}

$initialSurveys = read_json_file(SURVEYS_FILE, []);

if (isset($initialSurveys['surveys']) && is_array($initialSurveys['surveys'])) {
    $initialSurveys = $initialSurveys['surveys'];
}

if (!$initialSurveys) {
    $initialSurveys = [
        [
            'id' => 1,
            'name' => '新商品アンケート',
            'description' => '新商品の利用状況とご意見をお聞きするアンケートです。',
            'status' => 'open',
            'created' => '2026-09-01',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'answers' => 128,
            'target' => 200,
            'sent' => 195,
            'updated' => '2026-09-20',
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
                                ['text' => 'いいえ', 'branch' => '1003'],
                            ],
                        ],
                        [
                            'id' => 1002,
                            'text' => '商品についての満足度を教えてください。',
                            'type' => 'single',
                            'required' => true,
                            'options' => [
                                ['text' => '満足', 'branch' => ''],
                                ['text' => '普通', 'branch' => ''],
                                ['text' => '不満', 'branch' => '1003'],
                            ],
                        ],
                    ],
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
                            'options' => [],
                        ],
                    ],
                ],
            ],
        ],
        [
            'id' => 2,
            'name' => 'サービス利用後アンケート',
            'description' => 'サービスをご利用いただいた感想をお聞きします。',
            'status' => 'draft',
            'created' => '2026-09-10',
            'start' => '',
            'end' => '',
            'answers' => 0,
            'target' => 0,
            'sent' => 0,
            'updated' => '2026-09-21',
            'numbering' => 'group',
            'groups' => [
                [
                    'id' => 201,
                    'name' => 'サービスについて',
                    'questions' => [
                        [
                            'id' => 2001,
                            'text' => 'サービスについての感想を教えてください。',
                            'type' => 'multiple',
                            'required' => false,
                            'options' => [
                                ['text' => '便利だった', 'branch' => ''],
                                ['text' => '分かりやすかった', 'branch' => ''],
                                ['text' => 'また利用したい', 'branch' => ''],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

$csrfToken = $_SESSION['yokoyamy_surveyoperation_app']['csrf_token'];

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
.hidden{display:none!important}

.topbar{
    height:60px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px;
}
.logo{font-size:18px;font-weight:bold;white-space:nowrap}
.main-nav{display:flex;height:100%;align-items:center;gap:2px}
.main-nav button{
    height:100%;
    padding:0 17px;
    color:#dce7f3;
    background:transparent;
    border:0;
}
.main-nav button:hover,
.main-nav button.active{
    background:#31557f;
    color:#fff;
}

.app{max-width:1440px;margin:0 auto;padding:24px}

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
.btn-success{background:#2f855a;border-color:#2f855a;color:#fff}
.btn-small{padding:5px 10px;font-size:12px}

.loading{
    position:relative;
    pointer-events:none;
}
.loading::after{
    content:"";
    display:inline-block;
    width:13px;
    height:13px;
    margin-left:8px;
    vertical-align:-2px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
}
.btn:not(.btn-primary):not(.btn-success):not(.btn-danger).loading::after{
    border-color:#b8c2cc;
    border-top-color:#52606d;
}
@keyframes spin{to{transform:rotate(360deg)}}

.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:18px;
}
.card-title{font-size:17px;font-weight:bold;margin-bottom:15px}
.card-title small{
    font-size:12px;
    color:#718096;
    font-weight:normal;
    margin-left:8px;
}

.table{width:100%;border-collapse:collapse}
.table th,.table td{
    padding:12px 10px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    vertical-align:middle;
    font-size:13px;
}
.table th{background:#f8fafc;color:#52606d;font-weight:bold}
.empty{text-align:center!important;color:#8a98a5;padding:40px!important}

.link-button{
    border:0;
    background:none;
    padding:0;
    color:#2878c8;
    cursor:pointer;
    text-align:left;
}
.link-button:hover{text-decoration:underline}

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
.badge-ok{background:#e6f6ed;color:#237a49}
.badge-warn{background:#fff5d9;color:#9a6800}

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

.radio-row{display:flex;gap:22px;flex-wrap:wrap}
.radio-row label{
    font-weight:normal;
    display:inline-flex;
    align-items:center;
    gap:5px;
}

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
.notice.error{background:#fff0f0;border-color:#efc4c4;color:#a83232}

.editor-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-top:20px;
}
.editor-actions{display:flex;gap:8px}

.group-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    margin-bottom:16px;
}
.group-card.dragging{opacity:.45}
.group-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:13px 15px;
    background:#f7f9fb;
    border-bottom:1px solid #e3e8ed;
}
.group-title{flex:1}
.group-title input{
    border:1px solid transparent;
    background:transparent;
    padding:5px 7px;
    font-weight:bold;
    font-size:16px;
    width:100%;
}
.group-title input:focus{
    border-color:#cbd5e0;
    background:#fff;
}
.drag-handle{color:#9aa7b3;cursor:grab;font-size:18px}
.group-actions{display:flex;gap:5px}

.question-card{
    padding:15px;
    border-bottom:1px solid #e6ebef;
}
.question-card.dragging{opacity:.45}
.question-head{
    display:flex;
    align-items:center;
    gap:8px;
}
.question-number{
    width:58px;
    color:#2878c8;
    font-weight:bold;
    flex:none;
}
.question-title{flex:1}
.question-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px;
}
.question-tools{display:flex;gap:6px}
.question-tools select{
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:6px;
}
.question-meta{
    display:flex;
    gap:18px;
    align-items:center;
    margin-top:10px;
    padding-left:66px;
    color:#657786;
    font-size:13px;
}
.question-options{
    margin-top:12px;
    padding-left:66px;
}
.option-row{
    display:flex;
    align-items:center;
    gap:7px;
    margin-bottom:7px;
}
.option-row input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px;
}
.branch-select{
    width:245px!important;
    flex:none;
}
.branch-label{
    font-size:11px;
    color:#718096;
    width:50px;
    flex:none;
}
.add-question-area{padding:12px 14px}
.add-group-area{text-align:center;margin-top:8px}

.detail-tabs{
    display:flex;
    border-bottom:1px solid #dfe5eb;
    margin-bottom:18px;
}
.detail-tabs button{
    border:0;
    background:transparent;
    padding:12px 20px;
    color:#687887;
    border-bottom:3px solid transparent;
}
.detail-tabs button.active{
    color:#2878c8;
    border-bottom-color:#2878c8;
}

.detail-summary{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px;
}
.stat-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:17px;
}
.stat-label{color:#718096;font-size:12px}
.stat-value{font-size:27px;font-weight:bold;margin-top:5px}
.stat-note{font-size:11px;color:#8a98a5;margin-top:3px}

.preview-question{
    padding:15px 0;
    border-bottom:1px solid #e6ebef;
}
.preview-question-title{font-weight:bold;margin-bottom:9px}
.preview-option{margin:6px 0;color:#52606d}

.send-layout{
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:18px;
}
.customer-toolbar{
    display:flex;
    gap:8px;
    margin-bottom:12px;
}
.customer-toolbar input{flex:1}
.customer-toolbar input,
.customer-toolbar select{
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px;
}
.selection-summary{
    padding:10px 12px;
    background:#edf6ff;
    color:#2b5f8a;
    border-radius:5px;
    margin-bottom:12px;
    font-size:13px;
}
.email-preview{
    border:1px solid #dfe5eb;
    border-radius:6px;
    background:#fafbfc;
    padding:15px;
    white-space:pre-wrap;
    min-height:150px;
    font-size:13px;
}
.recipient-chip{
    display:inline-block;
    padding:5px 8px;
    margin:3px;
    border-radius:4px;
    background:#edf2f7;
    font-size:12px;
}

.progress{
    height:10px;
    background:#e8edf2;
    border-radius:5px;
    overflow:hidden;
    margin-top:8px;
}
.progress span{
    display:block;
    height:100%;
    background:#4285c5;
}

.result-item{
    padding:18px;
    border-bottom:1px solid #e6ebef;
}
.bar{
    height:9px;
    background:#e8edf2;
    border-radius:5px;
    overflow:hidden;
    margin-top:6px;
}
.bar span{
    display:block;
    height:100%;
    background:#4285c5;
}
.result-answer{
    padding:8px 10px;
    background:#f7f9fb;
    border:1px solid #e6ebef;
    border-radius:4px;
    margin:5px 0;
    font-size:13px;
}

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
    border-color:#2878c8;
}

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

@media(max-width:950px){
    .send-layout{grid-template-columns:1fr}
    .detail-summary{grid-template-columns:1fr 1fr}
}
@media(max-width:800px){
    .topbar{padding:0 10px;gap:8px}
    .logo{font-size:15px}
    .main-nav button{padding:0 8px;font-size:12px}
    .app{padding:14px}
    .form-grid{grid-template-columns:1fr}
    .question-head{align-items:flex-start;flex-wrap:wrap}
    .question-title{min-width:70%}
    .question-meta,.question-options{padding-left:0}
    .branch-select{width:100%!important}
    .option-row{flex-wrap:wrap}
    .table{min-width:800px}
    .card{overflow-x:auto}
}
</style>
</head>

<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>

    <nav class="main-nav">
        <button type="button" data-nav="list">アンケート一覧</button>
        <button type="button" data-nav="create">アンケート作成</button>
        <button type="button" data-nav="customers">顧客一覧</button>
        <button type="button" data-nav="settings">設定</button>
    </nav>
</header>

<main class="app">

<section id="page-list">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>
        <button type="button" class="btn btn-primary" data-action="create">＋ アンケート作成</button>
    </div>

    <div class="card">
        <table class="table">
            <thead>
            <tr>
                <th>アンケート名</th>
                <th>状態</th>
                <th>作成日</th>
                <th>公開期間</th>
                <th>回答数</th>
                <th>最終更新日</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody id="survey-list-body"></tbody>
        </table>
    </div>
</section>

<section id="page-editor" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="editor-page-title">アンケート作成</h1>
            <div class="subtext">アンケート全体を1画面で編集できます</div>
        </div>
    </div>

    <div class="notice">
        質問とグループはドラッグ＆ドロップで並べ替えできます。質問番号は自動的に更新されます。
    </div>

    <div class="card">
        <div class="form-grid">
            <div class="field">
                <label>アンケート名 *</label>
                <input id="survey-name" type="text">
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
            <div class="radio-row">
                <label>
                    <input type="radio" name="numbering" value="global">
                    全体で通番（Q1、Q2、Q3…）
                </label>

                <label>
                    <input type="radio" name="numbering" value="group">
                    グループごと（Q1-1、Q1-2、Q2-1…）
                </label>
            </div>
        </div>
    </div>

    <div id="groups"></div>

    <div class="add-group-area">
        <button type="button" class="btn btn-primary" data-action="add-group">＋ グループ追加</button>
    </div>

    <div class="editor-toolbar">
        <button type="button" class="btn" data-action="list">一覧へ戻る</button>

        <div class="editor-actions">
            <button type="button" class="btn" data-action="preview-editor">内容確認</button>
            <button type="button" class="btn btn-primary" data-action="save-survey">保存</button>
        </div>
    </div>
</section>

<section id="page-detail" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="detail-title"></h1>
            <div class="subtext" id="detail-subtitle"></div>
        </div>

        <div>
            <button type="button" class="btn" data-action="edit-current">編集</button>
            <button type="button" class="btn btn-primary" data-action="detail-send">送信</button>
            <button type="button" class="btn" data-action="list">一覧へ戻る</button>
        </div>
    </div>

    <div class="detail-tabs">
        <button type="button" data-detail-tab="content">アンケート内容</button>
        <button type="button" data-detail-tab="send">送信</button>
        <button type="button" data-detail-tab="status">回答状況</button>
        <button type="button" data-detail-tab="result">回答結果</button>
    </div>

    <div id="detail-content"></div>
</section>

<section id="page-customers" class="hidden">
    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">キントーンの顧客管理アプリから取得した顧客です</div>
        </div>

        <button type="button" class="btn" data-action="settings-kintone">
            キントーン設定
        </button>
    </div>

    <div id="customer-status"></div>

    <div class="card">
        <div class="customer-toolbar">
            <input id="customer-search" placeholder="顧客名・メールアドレスで検索">
            <button type="button" class="btn" data-action="refresh-customers">
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
            <div class="subtext">メール送信と顧客一覧取得に必要な設定を管理します</div>
        </div>
    </div>

    <div class="settings-tabs">
        <button type="button" id="settings-tab-mail" data-settings-tab="mail">
            メール送信設定
        </button>

        <button type="button" id="settings-tab-kintone" data-settings-tab="kintone">
            キントーン設定
        </button>
    </div>

    <div id="settings-content"></div>
</section>

</main>

<div id="modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <strong id="modal-title"></strong>
            <button type="button" class="btn btn-small" data-action="close-modal">
                閉じる
            </button>
        </div>

        <div class="modal-body" id="modal-body"></div>
        <div class="modal-footer" id="modal-footer"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    let surveys = <?= json_encode(
        $initialSurveys,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    let customers = <?= json_encode(
        $initialCustomers,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    let mailSettings = <?= json_encode(
        $initialMail,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    let kintoneSettings = <?= json_encode(
        $initialKintone,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    let editingSurvey = null;
    let currentSurveyId = null;
    let currentSettingsTab = 'mail';
    let selectedCustomers = [];
    let nextGroupId = 500;
    let nextQuestionId = 5000;
    let draggedGroupId = null;
    let draggedQuestionId = null;
    let dirty = false;

    const $ = function (id) {
        return document.getElementById(id);
    };

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    function showToast(message) {
        const toast = $('toast');

        if (!toast) {
            return;
        }

        toast.textContent = message;
        toast.classList.add('show');

        window.setTimeout(function () {
            toast.classList.remove('show');
        }, 2500);
    }

    function showPage(id) {
        [
            'page-list',
            'page-editor',
            'page-detail',
            'page-customers',
            'page-settings'
        ].forEach(function (pageId) {
            const page = $(pageId);

            if (page) {
                page.classList.add('hidden');
            }
        });

        const target = $(id);

        if (target) {
            target.classList.remove('hidden');
        }

        [
            'nav-list',
            'nav-create',
            'nav-customers',
            'nav-settings'
        ].forEach(function (navId) {
            const nav = $(navId);

            if (nav) {
                nav.classList.remove('active');
            }
        });

        if (id === 'page-list') {
            const el = $('nav-list');
            if (el) el.classList.add('active');
        }

        if (id === 'page-editor') {
            const el = $('nav-create');
            if (el) el.classList.add('active');
        }

        if (id === 'page-customers') {
            const el = $('nav-customers');
            if (el) el.classList.add('active');
        }

        if (id === 'page-settings') {
            const el = $('nav-settings');
            if (el) el.classList.add('active');
        }
    }

    function beginLoading(button) {
        if (!button) {
            return;
        }

        button.disabled = true;
        button.classList.add('loading');
    }

    function endLoading(button) {
        if (!button) {
            return;
        }

        button.disabled = false;
        button.classList.remove('loading');
    }

    async function post(action, data, button) {
        beginLoading(button);

        try {
            const body = new FormData();

            body.append('action', action);

            if (data && typeof data === 'object') {
                Object.keys(data).forEach(function (key) {
                    body.append(key, data[key] === undefined || data[key] === null ? '' : data[key]);
                });
            }

            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: body,
                credentials: 'same-origin'
            });

            const json = await response.json();

            if (!json || json.success !== true) {
                throw new Error(json?.message || '処理に失敗しました。');
            }

            return json;
        } finally {
            endLoading(button);
        }
    }

    function statusBadge(status) {
        if (status === 'open') {
            return '<span class="badge badge-open">公開中</span>';
        }

        if (status === 'end') {
            return '<span class="badge badge-end">終了</span>';
        }

        return '<span class="badge badge-draft">下書き</span>';
    }

    function renderList() {
        const body = $('survey-list-body');

        if (!body) {
            return;
        }

        if (!Array.isArray(surveys) || surveys.length === 0) {
            body.innerHTML =
                '<tr><td colspan="7" class="empty">アンケートがありません。</td></tr>';
            return;
        }

        body.innerHTML = surveys.map(function (survey) {
            const period =
                survey.start || survey.end
                    ? escapeHtml(survey.start || '未設定') +
                      ' ～ ' +
                      escapeHtml(survey.end || '未設定')
                    : '未設定';

            let actions =
                '<button type="button" class="btn btn-small" data-action="edit-survey" data-id="' +
                survey.id +
                '">編集</button> ';

            actions +=
                '<button type="button" class="btn btn-small" data-action="open-detail" data-id="' +
                survey.id +
                '">確認</button> ';

            if (survey.status === 'draft') {
                actions +=
                    '<button type="button" class="btn btn-small btn-success" data-action="publish-survey" data-id="' +
                    survey.id +
                    '">公開</button> ';
            }

            if (survey.status === 'open') {
                actions +=
                    '<button type="button" class="btn btn-small btn-danger" data-action="end-survey" data-id="' +
                    survey.id +
                    '">終了</button> ';
            }

            if (survey.status === 'draft') {
                actions +=
                    '<button type="button" class="btn btn-small btn-danger" data-action="delete-survey" data-id="' +
                    survey.id +
                    '">削除</button>';
            }

            return (
                '<tr>' +
                '<td><button type="button" class="link-button" data-action="open-detail" data-id="' +
                survey.id +
                '">' +
                escapeHtml(survey.name) +
                '</button></td>' +
                '<td>' +
                statusBadge(survey.status) +
                '</td>' +
                '<td>' +
                escapeHtml(survey.created || '') +
                '</td>' +
                '<td>' +
                period +
                '</td>' +
                '<td>' +
                Number(survey.answers || 0) +
                '件</td>' +
                '<td>' +
                escapeHtml(survey.updated || '') +
                '</td>' +
                '<td>' +
                actions +
                '</td>' +
                '</tr>'
            );
        }).join('');
    }

    function cloneSurvey(survey) {
        return JSON.parse(JSON.stringify(survey));
    }

    function openCreate() {
        editingSurvey = {
            id: null,
            name: '',
            description: '',
            status: 'draft',
            created: '',
            start: '',
            end: '',
            answers: 0,
            target: 0,
            sent: 0,
            updated: '',
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

        dirty = false;

        const title = $('editor-page-title');

        if (title) {
            title.textContent = 'アンケート作成';
        }

        loadEditor();
        showPage('page-editor');
    }

    function editSurvey(id) {
        const survey = surveys.find(function (item) {
            return String(item.id) === String(id);
        });

        if (!survey) {
            return;
        }

        editingSurvey = cloneSurvey(survey);
        dirty = false;

        const title = $('editor-page-title');

        if (title) {
            title.textContent = 'アンケート編集';
        }

        loadEditor();
        showPage('page-editor');
    }

    function loadEditor() {
        if (!editingSurvey) {
            return;
        }

        const name = $('survey-name');
        const description = $('survey-description');
        const status = $('survey-status');
        const start = $('survey-start');
        const end = $('survey-end');

        if (name) name.value = editingSurvey.name || '';
        if (description) description.value = editingSurvey.description || '';
        if (status) status.value = editingSurvey.status || 'draft';
        if (start) start.value = editingSurvey.start || '';
        if (end) end.value = editingSurvey.end || '';

        document.querySelectorAll('input[name="numbering"]').forEach(function (radio) {
            radio.checked = radio.value === editingSurvey.numbering;
        });

        renderEditor();
    }

    function getQuestionNumber(groupIndex, questionIndex) {
        if (!editingSurvey) {
            return '';
        }

        if (editingSurvey.numbering === 'group') {
            return 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1);
        }

        let count = 0;

        for (let i = 0; i < groupIndex; i++) {
            count += editingSurvey.groups[i].questions.length;
        }

        return 'Q' + (count + questionIndex + 1);
    }

    function getQuestionById(id) {
        if (!editingSurvey) {
            return null;
        }

        for (let gi = 0; gi < editingSurvey.groups.length; gi++) {
            for (let qi = 0; qi < editingSurvey.groups[gi].questions.length; qi++) {
                const question = editingSurvey.groups[gi].questions[qi];

                if (String(question.id) === String(id)) {
                    return {
                        group: editingSurvey.groups[gi],
                        question: question,
                        groupIndex: gi,
                        questionIndex: qi
                    };
                }
            }
        }

        return null;
    }

    function getQuestionLabelById(id) {
        if (!editingSurvey) {
            return '';
        }

        for (let gi = 0; gi < editingSurvey.groups.length; gi++) {
            for (let qi = 0; qi < editingSurvey.groups[gi].questions.length; qi++) {
                const question = editingSurvey.groups[gi].questions[qi];

                if (String(question.id) === String(id)) {
                    return (
                        getQuestionNumber(gi, qi) +
                        '：' +
                        (question.text || '（未入力）')
                    );
                }
            }
        }

        return '';
    }

    function getBranchOptions(currentQuestionId, currentBranch) {
        let html =
            '<option value="">通常の次の質問へ</option>' +
            '<option value="END"' +
            (currentBranch === 'END' ? ' selected' : '') +
            '>アンケート終了</option>';

        if (!editingSurvey) {
            return html;
        }

        editingSurvey.groups.forEach(function (group, gi) {
            group.questions.forEach(function (target, qi) {
                if (String(target.id) === String(currentQuestionId)) {
                    return;
                }

                const selected =
                    String(currentBranch || '') === String(target.id)
                        ? ' selected'
                        : '';

                html +=
                    '<option value="' +
                    target.id +
                    '"' +
                    selected +
                    '>' +
                    escapeHtml(
                        getQuestionNumber(gi, qi) +
                        '：' +
                        (target.text || '（未入力）')
                    ) +
                    '</option>';
            });
        });

        return html;
    }

    function renderQuestion(group, question, questionNumber) {
        const typeLabel = {
            free: '自由記述',
            single: '単一選択',
            multiple: '複数選択'
        }[question.type] || '自由記述';

        let html =
            '<div class="question-card" draggable="true" data-question-id="' +
            question.id +
            '" data-group-id="' +
            group.id +
            '">' +
            '<div class="question-head">' +
            '<span class="drag-handle">☷</span>' +
            '<span class="question-number">' +
            questionNumber +
            '</span>' +
            '<div class="question-title">' +
            '<input data-question-field="text" data-question-id="' +
            question.id +
            '" value="' +
            escapeHtml(question.text) +
            '" placeholder="質問文を入力してください">' +
            '</div>' +
            '<div class="question-tools">' +
            '<select data-question-field="type" data-question-id="' +
            question.id +
            '">' +
            '<option value="free"' +
            (question.type === 'free' ? ' selected' : '') +
            '>自由記述</option>' +
            '<option value="single"' +
            (question.type === 'single' ? ' selected' : '') +
            '>単一選択</option>' +
            '<option value="multiple"' +
            (question.type === 'multiple' ? ' selected' : '') +
            '>複数選択</option>' +
            '</select>' +
            '<button type="button" class="btn btn-small btn-danger" data-action="delete-question" data-id="' +
            question.id +
            '">削除</button>' +
            '</div>' +
            '</div>' +
            '<div class="question-meta">' +
            '<label>' +
            '<input type="checkbox" data-question-field="required" data-question-id="' +
            question.id +
            '"' +
            (question.required ? ' checked' : '') +
            '> 必須' +
            '</label>' +
            '<span>回答形式：' +
            typeLabel +
            '</span>' +
            '</div>';

        if (question.type !== 'free') {
            html += '<div class="question-options">';

            const options = Array.isArray(question.options)
                ? question.options
                : [];

            options.forEach(function (option, optionIndex) {
                html +=
                    '<div class="option-row">' +
                    '<input data-option-text="' +
                    question.id +
                    '" data-option-index="' +
                    optionIndex +
                    '" value="' +
                    escapeHtml(option.text || '') +
                    '" placeholder="選択肢">' ;

                if (question.type === 'single') {
                    html +=
                        '<span class="branch-label">分岐</span>' +
                        '<select class="branch-select" data-branch-question="' +
                        question.id +
                        '" data-option-index="' +
                        optionIndex +
                        '">' +
                        getBranchOptions(
                            question.id,
                            option.branch || ''
                        ) +
                        '</select>';
                }

                html +=
                    '<button type="button" class="btn btn-small btn-danger" data-action="delete-option" data-question-id="' +
                    question.id +
                    '" data-option-index="' +
                    optionIndex +
                    '">削除</button>' +
                    '</div>';
            });

            html +=
                '<button type="button" class="btn btn-small" data-action="add-option" data-question-id="' +
                question.id +
                '">＋ 選択肢追加</button>' +
                '</div>';
        }

        html +=
            '<div class="add-question-area"></div>' +
            '</div>';

        return html;
    }

    function renderEditor() {
        const groups = $('groups');

        if (!groups || !editingSurvey) {
            return;
        }

        let html = '';

        editingSurvey.groups.forEach(function (group, gi) {
            html +=
                '<div class="group-card" draggable="true" data-group-id="' +
                group.id +
                '">' +
                '<div class="group-header">' +
                '<span class="drag-handle">☷</span>' +
                '<div class="group-title">' +
                '<input data-group-name="' +
                group.id +
                '" value="' +
                escapeHtml(group.name) +
                '">' +
                '</div>' +
                '<div class="group-actions">' +
                '<button type="button" class="btn btn-small btn-danger" data-action="delete-group" data-id="' +
                group.id +
                '">グループ削除</button>' +
                '</div>' +
                '</div>' +
                '<div class="questions">';

            group.questions.forEach(function (question, qi) {
                html += renderQuestion(
                    group,
                    question,
                    getQuestionNumber(gi, qi)
                );
            });

            html +=
                '</div>' +
                '<div class="add-question-area">' +
                '<button type="button" class="btn btn-small btn-primary" data-action="add-question" data-group-id="' +
                group.id +
                '">＋ 質問追加</button>' +
                '</div>' +
                '</div>';
        });

        groups.innerHTML = html;
    }

    function addGroup() {
        if (!editingSurvey) {
            return;
        }

        editingSurvey.groups.push({
            id: nextGroupId++,
            name: 'グループ' + (editingSurvey.groups.length + 1),
            questions: [
                {
                    id: nextQuestionId++,
                    text: '',
                    type: 'free',
                    required: false,
                    options: []
                }
            ]
        });

        dirty = true;
        renderEditor();
    }

    function addQuestion(groupId) {
        if (!editingSurvey) {
            return;
        }

        const group = editingSurvey.groups.find(function (item) {
            return String(item.id) === String(groupId);
        });

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

        dirty = true;
        renderEditor();
    }

    function deleteGroup(id) {
        if (!editingSurvey) {
            return;
        }

        const group = editingSurvey.groups.find(function (item) {
            return String(item.id) === String(id);
        });

        if (!group) {
            return;
        }

        if (
            !window.confirm(
                '「' +
                (group.name || 'グループ') +
                '」を削除しますか？\nグループ内の質問も削除されます。'
            )
        ) {
            return;
        }

        editingSurvey.groups = editingSurvey.groups.filter(function (item) {
            return String(item.id) !== String(id);
        });

        if (editingSurvey.groups.length === 0) {
            editingSurvey.groups.push({
                id: nextGroupId++,
                name: 'グループ1',
                questions: []
            });
        }

        dirty = true;
        renderEditor();
    }

    function deleteQuestion(id) {
        if (!editingSurvey) {
            return;
        }

        const found = getQuestionById(id);

        if (!found) {
            return;
        }

        if (!window.confirm('この質問を削除しますか？')) {
            return;
        }

        found.group.questions = found.group.questions.filter(function (question) {
            return String(question.id) !== String(id);
        });

        dirty = true;
        renderEditor();
    }

    function addOption(questionId) {
        const found = getQuestionById(questionId);

        if (!found) {
            return;
        }

        if (!Array.isArray(found.question.options)) {
            found.question.options = [];
        }

        found.question.options.push({
            text: '',
            branch: ''
        });

        dirty = true;
        renderEditor();
    }

    function deleteOption(questionId, optionIndex) {
        const found = getQuestionById(questionId);

        if (!found || !Array.isArray(found.question.options)) {
            return;
        }

        found.question.options.splice(Number(optionIndex), 1);

        dirty = true;
        renderEditor();
    }

    function validateSurvey(survey) {
        if (!survey.name || survey.name.trim() === '') {
            throw new Error('アンケート名を入力してください。');
        }

        if (!Array.isArray(survey.groups) || survey.groups.length === 0) {
            throw new Error('グループを1つ以上設定してください。');
        }

        let questionCount = 0;

        survey.groups.forEach(function (group) {
            if (!group.name || group.name.trim() === '') {
                throw new Error('グループ名を入力してください。');
            }

            if (!Array.isArray(group.questions)) {
                throw new Error('質問の設定が正しくありません。');
            }

            group.questions.forEach(function (question) {
                questionCount++;

                if (!question.text || question.text.trim() === '') {
                    throw new Error('質問文を入力してください。');
                }

                if (
                    question.type === 'single' ||
                    question.type === 'multiple'
                ) {
                    if (
                        !Array.isArray(question.options) ||
                        question.options.length === 0
                    ) {
                        throw new Error(
                            '選択式の質問には選択肢を1つ以上設定してください。'
                        );
                    }

                    question.options.forEach(function (option) {
                        if (!option.text || option.text.trim() === '') {
                            throw new Error('選択肢を入力してください。');
                        }
                    });
                }
            });
        });

        if (questionCount === 0) {
            throw new Error('質問を1つ以上設定してください。');
        }
    }

    function saveSurvey() {
        if (!editingSurvey) {
            return;
        }

        try {
            const name = $('survey-name');
            const description = $('survey-description');
            const status = $('survey-status');
            const start = $('survey-start');
            const end = $('survey-end');

            editingSurvey.name = name ? name.value.trim() : '';
            editingSurvey.description = description ? description.value.trim() : '';
            editingSurvey.status = status ? status.value : 'draft';
            editingSurvey.start = start ? start.value : '';
            editingSurvey.end = end ? end.value : '';

            const numbering = document.querySelector(
                'input[name="numbering"]:checked'
            );

            editingSurvey.numbering = numbering
                ? numbering.value
                : 'global';

            validateSurvey(editingSurvey);

            if (!editingSurvey.id) {
                editingSurvey.id =
                    surveys.reduce(function (max, survey) {
                        return Math.max(max, Number(survey.id) || 0);
                    }, 0) + 1;

                editingSurvey.created = new Date()
                    .toISOString()
                    .slice(0, 10);

                editingSurvey.updated = editingSurvey.created;

                surveys.push(cloneSurvey(editingSurvey));
            } else {
                editingSurvey.updated = new Date()
                    .toISOString()
                    .slice(0, 10);

                const index = surveys.findIndex(function (survey) {
                    return String(survey.id) === String(editingSurvey.id);
                });

                if (index >= 0) {
                    surveys[index] = cloneSurvey(editingSurvey);
                }
            }

            dirty = false;
            showToast('アンケートを保存しました。');
            renderList();
        } catch (error) {
            window.alert(error.message);
        }
    }

    function openDetail(id) {
        const survey = surveys.find(function (item) {
            return String(item.id) === String(id);
        });

        if (!survey) {
            return;
        }

        currentSurveyId = survey.id;

        const title = $('detail-title');
        const subtitle = $('detail-subtitle');

        if (title) title.textContent = survey.name || '';
        if (subtitle) {
            subtitle.textContent =
                (survey.start || '未設定') +
                ' ～ ' +
                (survey.end || '未設定');
        }

        showPage('page-detail');
        showDetailTab('content');
    }

    function getCurrentSurvey() {
        return surveys.find(function (survey) {
            return String(survey.id) === String(currentSurveyId);
        }) || null;
    }

    function showDetailTab(tab) {
        const survey = getCurrentSurvey();

        if (!survey) {
            return;
        }

        document.querySelectorAll('[data-detail-tab]').forEach(function (button) {
            button.classList.toggle(
                'active',
                button.getAttribute('data-detail-tab') === tab
            );
        });

        if (tab === 'content') {
            renderDetailContent(survey);
        }

        if (tab === 'send') {
            renderSend(survey);
        }

        if (tab === 'status') {
            renderStatus(survey);
        }

        if (tab === 'result') {
            renderResult(survey);
        }
    }

    function renderDetailContent(survey) {
        const target = $('detail-content');

        if (!target) {
            return;
        }

        let html =
            '<div class="card">' +
            '<div class="card-title">' +
            escapeHtml(survey.name) +
            '</div>' +
            '<p>' +
            escapeHtml(survey.description || '') +
            '</p>';

        survey.groups.forEach(function (group, gi) {
            html +=
                '<div class="card">' +
                '<div class="card-title">' +
                escapeHtml(group.name) +
                '</div>';

            group.questions.forEach(function (question, qi) {
                html +=
                    '<div class="preview-question">' +
                    '<div class="preview-question-title">' +
                    getQuestionNumberForSurvey(survey, gi, qi) +
                    '　' +
                    escapeHtml(question.text) +
                    (question.required
                        ? ' <span class="badge badge-warn">必須</span>'
                        : '') +
                    '</div>';

                if (question.type !== 'free') {
                    (question.options || []).forEach(function (option) {
                        html +=
                            '<div class="preview-option">・' +
                            escapeHtml(option.text) +
                            '</div>';
                    });
                } else {
                    html +=
                        '<div class="preview-option">自由記述</div>';
                }

                html += '</div>';
            });

            html += '</div>';
        });

        html += '</div>';

        target.innerHTML = html;
    }

    function getQuestionNumberForSurvey(survey, groupIndex, questionIndex) {
        if (survey.numbering === 'group') {
            return 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1);
        }

        let count = 0;

        for (let i = 0; i < groupIndex; i++) {
            count += survey.groups[i].questions.length;
        }

        return 'Q' + (count + questionIndex + 1);
    }

    function renderSend(survey) {
        const target = $('detail-content');

        if (!target) {
            return;
        }

        if (!mailSettings.ready) {
            target.innerHTML =
                '<div class="notice warning">' +
                'メール送信設定が未完了です。設定画面でメール送信設定を保存してください。' +
                '</div>' +
                '<button type="button" class="btn btn-primary" data-action="settings-mail">' +
                'メール送信設定を開く' +
                '</button>';

            return;
        }

        let html =
            '<div class="send-layout">' +
            '<div class="card">' +
            '<div class="card-title">送信対象者</div>' +
            '<div class="selection-summary">選択中：<strong id="selected-count">0</strong>名</div>' +
            '<div class="customer-toolbar">' +
            '<input id="send-customer-search" placeholder="顧客名・メールアドレスで検索">' +
            '<button type="button" class="btn" data-action="select-all-visible">表示中を全選択</button>' +
            '</div>' +
            '<div id="send-customer-list"></div>' +
            '</div>' +
            '<div class="card">' +
            '<div class="card-title">メール内容</div>' +
            '<div class="field">' +
            '<label>件名 *</label>' +
            '<input id="send-subject" value="' +
            escapeHtml(survey.name + 'のご案内') +
            '">' +
            '</div>' +
            '<div class="field">' +
            '<label>本文 *</label>' +
            '<textarea id="send-body" style="min-height:210px">' +
            escapeHtml(
                'いつもお世話になっております。\n\n' +
                '「' +
                survey.name +
                '」へのご協力をお願いいたします。\n\n' +
                '下記の案内からアンケートへアクセスしてご回答ください。\n\n' +
                '回答期限：' +
                (survey.end || '設定なし') +
                '\n\nよろしくお願いいたします。'
            ) +
            '</textarea>' +
            '</div>' +
            '<div class="notice">回答用の案内を本文に含めて送信してください。</div>' +
            '<button type="button" class="btn btn-primary" data-action="confirm-send">' +
            '送信内容を確認' +
            '</button>' +
            '</div>' +
            '</div>';

        target.innerHTML = html;

        selectedCustomers = [];
        renderSendCustomers();
    }

    function renderSendCustomers() {
        const target = $('send-customer-list');

        if (!target) {
            return;
        }

        const searchEl = $('send-customer-search');
        const search = searchEl
            ? searchEl.value.trim().toLowerCase()
            : '';

        const filtered = customers.filter(function (customer) {
            return (
                !search ||
                String(customer.name || '').toLowerCase().includes(search) ||
                String(customer.email || '').toLowerCase().includes(search) ||
                String(customer.company || '').toLowerCase().includes(search)
            );
        });

        if (filtered.length === 0) {
            target.innerHTML =
                '<div class="empty">該当する顧客がありません。</div>';
            return;
        }

        let html =
            '<table class="table">' +
            '<thead><tr>' +
            '<th style="width:40px"></th>' +
            '<th>顧客名</th>' +
            '<th>メールアドレス</th>' +
            '<th>会社名</th>' +
            '</tr></thead><tbody>';

        filtered.forEach(function (customer) {
            const checked = selectedCustomers.includes(String(customer.id))
                ? ' checked'
                : '';

            html +=
                '<tr>' +
                '<td><input type="checkbox" data-customer-id="' +
                escapeHtml(String(customer.id)) +
                '"' +
                checked +
                '></td>' +
                '<td>' +
                escapeHtml(customer.name) +
                '</td>' +
                '<td>' +
                escapeHtml(customer.email) +
                '</td>' +
                '<td>' +
                escapeHtml(customer.company) +
                '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';

        target.innerHTML = html;

        const count = $('selected-count');

        if (count) {
            count.textContent = String(selectedCustomers.length);
        }
    }

    function confirmSend(button) {
        const survey = getCurrentSurvey();

        if (!survey) {
            return;
        }

        if (survey.status !== 'open') {
            window.alert('公開中のアンケートのみ送信できます。');
            return;
        }

        if (!mailSettings.ready) {
            window.alert('メール送信設定を完了してください。');
            return;
        }

        if (selectedCustomers.length === 0) {
            window.alert('送信対象者を1名以上選択してください。');
            return;
        }

        const subjectEl = $('send-subject');
        const bodyEl = $('send-body');

        const subject = subjectEl ? subjectEl.value.trim() : '';
        const body = bodyEl ? bodyEl.value : '';

        if (!subject || !body) {
            window.alert('件名と本文を入力してください。');
            return;
        }

        const recipients = customers.filter(function (customer) {
            return selectedCustomers.includes(String(customer.id));
        });

        let html =
            '<div class="notice">以下の内容でメールを送信します。</div>' +
            '<p><strong>アンケート：</strong>' +
            escapeHtml(survey.name) +
            '</p>' +
            '<p><strong>送信対象者：</strong>' +
            recipients.length +
            '名</p>' +
            '<div>';

        recipients.forEach(function (customer) {
            html +=
                '<span class="recipient-chip">' +
                escapeHtml(customer.name) +
                ' &lt;' +
                escapeHtml(customer.email) +
                '&gt;</span>';
        });

        html +=
            '</div>' +
            '<p><strong>件名：</strong>' +
            escapeHtml(subject) +
            '</p>' +
            '<div class="email-preview">' +
            escapeHtml(body) +
            '</div>';

        openModal(
            'アンケート送信確認',
            html,
            '<button type="button" class="btn" data-action="close-modal">戻る</button>' +
            '<button type="button" class="btn btn-primary" data-action="send-mail">メールを送信する</button>',
            {
                recipients: recipients,
                subject: subject,
                body: body
            }
        );
    }

    let pendingMail = null;

    function sendSurveyMail(button) {
        const survey = getCurrentSurvey();

        if (!survey || !pendingMail) {
            return;
        }

        post(
            'send_mail',
            {
                recipients: JSON.stringify(
                    pendingMail.recipients.map(function (customer) {
                        return {
                            name: customer.name,
                            email: customer.email
                        };
                    })
                ),
                subject: pendingMail.subject,
                body: pendingMail.body
            },
            button
        ).then(function (result) {
            closeModal();

            const target = $('detail-content');

            if (!target) {
                return;
            }

            target.innerHTML =
                '<div class="notice ' +
                (result.failedCount === 0 ? 'success' : 'warning') +
                '">' +
                escapeHtml(result.message) +
                '</div>' +
                '<div class="card">' +
                '<div class="card-title">送信結果</div>' +
                '<p>送信対象：' +
                result.total +
                '名</p>' +
                '<p>送信成功：' +
                result.successCount +
                '名</p>' +
                '<p>送信失敗：' +
                result.failedCount +
                '名</p>' +
                '<div class="progress"><span style="width:' +
                (result.total > 0
                    ? Math.round(result.successCount / result.total * 100)
                    : 0) +
                '%"></span></div>' +
                '</div>';

            if (result.failed && result.failed.length) {
                target.innerHTML +=
                    '<div class="card"><div class="card-title">送信失敗</div>' +
                    result.failed.map(function (item) {
                        return (
                            '<div class="result-answer">' +
                            escapeHtml(item.name || '') +
                            '：' +
                            escapeHtml(item.email || '') +
                            '<br>' +
                            escapeHtml(item.message || '') +
                            '</div>'
                        );
                    }).join('') +
                    '</div>';
            }

            survey.target = Math.max(
                Number(survey.target || 0),
                Number(result.total || 0)
            );

            survey.sent =
                Number(survey.sent || 0) +
                Number(result.successCount || 0);

            showToast('メール送信処理が完了しました。');
            pendingMail = null;
        }).catch(function (error) {
            window.alert(error.message);
        });
    }

    function renderStatus(survey) {
        const target = Number(survey.target || 0);
        const answers = Number(survey.answers || 0);
        const rate = target
            ? Math.round(answers / target * 100)
            : 0;
        const unanswered = Math.max(target - answers, 0);

        const detail = $('detail-content');

        if (!detail) {
            return;
        }

        detail.innerHTML =
            '<div class="detail-summary">' +
            statCard('回答数', answers + '件', '') +
            statCard('回答率', rate + '%', '') +
            statCard('未回答数', unanswered + '名', '') +
            statCard('送信済み', Number(survey.sent || 0) + '名', '') +
            '</div>' +
            '<div class="card">' +
            '<div class="card-title">公開期間</div>' +
            '<p>' +
            escapeHtml(survey.start || '未設定') +
            ' ～ ' +
            escapeHtml(survey.end || '未設定') +
            '</p>' +
            '</div>';
    }

    function statCard(label, value, note) {
        return (
            '<div class="stat-card">' +
            '<div class="stat-label">' +
            escapeHtml(label) +
            '</div>' +
            '<div class="stat-value">' +
            escapeHtml(value) +
            '</div>' +
            '<div class="stat-note">' +
            escapeHtml(note) +
            '</div>' +
            '</div>'
        );
    }

    function renderResult(survey) {
        const detail = $('detail-content');

        if (!detail) {
            return;
        }

        let html =
            '<div class="card">' +
            '<div class="card-title">回答結果</div>';

        if (!Number(survey.answers || 0)) {
            html +=
                '<div class="empty">まだ回答がありません。</div>';
        } else {
            survey.groups.forEach(function (group, gi) {
                group.questions.forEach(function (question, qi) {
                    html +=
                        '<div class="result-item">' +
                        '<div style="font-weight:bold">' +
                        getQuestionNumberForSurvey(survey, gi, qi) +
                        '　' +
                        escapeHtml(question.text) +
                        '</div>';

                    if (question.type === 'single') {
                        (question.options || []).forEach(function (option, oi) {
                            const count =
                                oi === 0
                                    ? Math.round(survey.answers * .52)
                                    : oi === 1
                                    ? Math.round(survey.answers * .31)
                                    : Math.max(
                                        0,
                                        survey.answers -
                                        Math.round(survey.answers * .52) -
                                        Math.round(survey.answers * .31)
                                    );

                            const pct = survey.answers
                                ? Math.round(count / survey.answers * 100)
                                : 0;

                            html +=
                                '<div style="margin-top:13px">' +
                                '<div style="display:flex;justify-content:space-between;font-size:13px">' +
                                '<span>' +
                                escapeHtml(option.text) +
                                '</span>' +
                                '<span>' +
                                count +
                                '件（' +
                                pct +
                                '%）</span>' +
                                '</div>' +
                                '<div class="bar"><span style="width:' +
                                Math.min(pct, 100) +
                                '%"></span></div>' +
                                '</div>';
                        });
                    } else if (question.type === 'multiple') {
                        (question.options || []).forEach(function (option, oi) {
                            const count = Math.round(
                                survey.answers *
                                (0.7 - oi * 0.12)
                            );

                            const pct = survey.answers
                                ? Math.round(count / survey.answers * 100)
                                : 0;

                            html +=
                                '<div style="margin-top:13px">' +
                                '<div style="display:flex;justify-content:space-between;font-size:13px">' +
                                '<span>' +
                                escapeHtml(option.text) +
                                '</span>' +
                                '<span>' +
                                count +
                                '回（' +
                                pct +
                                '%）</span>' +
                                '</div>' +
                                '<div class="bar"><span style="width:' +
                                Math.min(pct, 100) +
                                '%"></span></div>' +
                                '</div>';
                        });
                    } else {
                        html +=
                            '<div style="margin-top:12px">' +
                            '<div class="result-answer">回答内容を確認できます。</div>' +
                            '</div>';
                    }

                    html += '</div>';
                });
            });
        }

        html += '</div>';

        detail.innerHTML = html;
    }

    function publishSurvey(id) {
        const survey = surveys.find(function (item) {
            return String(item.id) === String(id);
        });

        if (!survey) {
            return;
        }

        try {
            validateSurvey(survey);
        } catch (error) {
            window.alert(error.message);
            return;
        }

        if (!window.confirm('「' + survey.name + '」を公開しますか？')) {
            return;
        }

        survey.status = 'open';
        survey.updated = new Date().toISOString().slice(0, 10);

        renderList();
        showToast('アンケートを公開しました。');
    }

    function endSurvey(id) {
        const survey = surveys.find(function (item) {
            return String(item.id) === String(id);
        });

        if (!survey) {
            return;
        }

        if (!window.confirm('「' + survey.name + '」の回答受付を終了しますか？')) {
            return;
        }

        survey.status = 'end';
        survey.updated = new Date().toISOString().slice(0, 10);

        renderList();
        showToast('アンケートを終了しました。');
    }

    function deleteSurvey(id) {
        const survey = surveys.find(function (item) {
            return String(item.id) === String(id);
        });

        if (!survey) {
            return;
        }

        if (!window.confirm('下書き「' + survey.name + '」を削除しますか？')) {
            return;
        }

        surveys = surveys.filter(function (item) {
            return String(item.id) !== String(id);
        });

        renderList();
        showToast('アンケートを削除しました。');
    }

    function showCustomers() {
        showPage('page-customers');
        renderCustomers();
        renderCustomerStatus();
    }

    function renderCustomerStatus() {
        const target = $('customer-status');

        if (!target) {
            return;
        }

        if (kintoneSettings.ready) {
            target.innerHTML =
                '<div class="notice success">キントーンから顧客一覧を取得できる状態です。現在 ' +
                customers.length +
                ' 件の顧客を表示しています。</div>';
        } else {
            target.innerHTML =
                '<div class="notice warning">キントーン設定が未完了です。設定画面から接続先・顧客管理アプリ・ログイン情報を設定してください。</div>';
        }
    }

    function renderCustomers() {
        const body = $('customer-body');

        if (!body) {
            return;
        }

        const searchEl = $('customer-search');
        const search = searchEl
            ? searchEl.value.trim().toLowerCase()
            : '';

        const filtered = customers.filter(function (customer) {
            return (
                !search ||
                String(customer.name || '').toLowerCase().includes(search) ||
                String(customer.email || '').toLowerCase().includes(search) ||
                String(customer.company || '').toLowerCase().includes(search) ||
                String(customer.code || '').toLowerCase().includes(search)
            );
        });

        if (!filtered.length) {
            body.innerHTML =
                '<tr><td colspan="4" class="empty">該当する顧客がありません。</td></tr>';
            return;
        }

        body.innerHTML = filtered.map(function (customer) {
            return (
                '<tr>' +
                '<td>' +
                escapeHtml(customer.name) +
                '</td>' +
                '<td>' +
                escapeHtml(customer.email) +
                '</td>' +
                '<td>' +
                escapeHtml(customer.company) +
                '</td>' +
                '<td>' +
                escapeHtml(customer.code) +
                '</td>' +
                '</tr>'
            );
        }).join('');
    }

    function refreshCustomers(button) {
        if (!kintoneSettings.ready) {
            window.alert('先にキントーン設定を保存してください。');
            return;
        }

        post('get_customers', {}, button)
            .then(function (result) {
                customers = Array.isArray(result.customers)
                    ? result.customers
                    : [];

                renderCustomers();
                renderCustomerStatus();
                showToast(result.message);
            })
            .catch(function (error) {
                window.alert(error.message);
            });
    }

    function showSettings(tab) {
        currentSettingsTab = tab || currentSettingsTab || 'mail';

        showPage('page-settings');
        renderSettings();
    }

    function renderSettings() {
        const mailTab = $('settings-tab-mail');
        const kintoneTab = $('settings-tab-kintone');

        if (mailTab) {
            mailTab.classList.toggle(
                'active',
                currentSettingsTab === 'mail'
            );
        }

        if (kintoneTab) {
            kintoneTab.classList.toggle(
                'active',
                currentSettingsTab === 'kintone'
            );
        }

        if (currentSettingsTab === 'mail') {
            renderMailSettings();
        } else {
            renderKintoneSettings();
        }
    }

    function renderMailSettings() {
        const target = $('settings-content');

        if (!target) {
            return;
        }

        target.innerHTML =
            '<div class="card">' +
            '<div class="card-title">メール送信設定</div>' +
            '<div class="status-line">' +
            '<span class="status-dot ' +
            (mailSettings.ready ? 'ok' : 'warn') +
            '"></span>' +
            '<span>' +
            (mailSettings.ready
                ? 'メール送信可能な設定が保存されています。'
                : 'メール送信設定が未完了です。') +
            '</span>' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>SMTPサーバ *</label>' +
            '<input id="smtp-server" value="' +
            escapeHtml(mailSettings.smtp || '') +
            '" placeholder="smtp.example.com">' +
            '</div>' +

            '<div class="field">' +
            '<label>ポート番号 *</label>' +
            '<input id="smtp-port" value="' +
            escapeHtml(mailSettings.port || '587') +
            '" placeholder="587">' +
            '</div>' +
            '</div>' +

            '<div class="field">' +
            '<label>接続方式</label>' +
            '<select id="smtp-security">' +
            '<option value="なし"' +
            (mailSettings.security === 'なし' ? ' selected' : '') +
            '>なし</option>' +
            '<option value="STARTTLS"' +
            (mailSettings.security === 'STARTTLS' ? ' selected' : '') +
            '>STARTTLS</option>' +
            '<option value="SSL/TLS"' +
            (mailSettings.security === 'SSL/TLS' ? ' selected' : '') +
            '>SSL/TLS</option>' +
            '</select>' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>認証ユーザー名</label>' +
            '<input id="smtp-user" value="' +
            escapeHtml(mailSettings.username || '') +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label>認証パスワード</label>' +
            '<input id="smtp-password" type="password" value="" placeholder="' +
            (mailSettings.hasPassword
                ? '保存済み（変更する場合のみ入力）'
                : 'パスワードを入力') +
            '">' +
            '</div>' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>送信元メールアドレス *</label>' +
            '<input id="smtp-from" value="' +
            escapeHtml(mailSettings.from || '') +
            '" placeholder="survey@example.com">' +
            '</div>' +

            '<div class="field">' +
            '<label>送信元名</label>' +
            '<input id="smtp-from-name" value="' +
            escapeHtml(mailSettings.fromName || '') +
            '">' +
            '</div>' +
            '</div>' +

            '<div style="display:flex;gap:8px;margin-top:10px">' +
            '<button type="button" class="btn btn-primary" data-action="save-mail-settings">' +
            '設定を保存' +
            '</button>' +
            '<button type="button" class="btn" data-action="test-mail-settings">' +
            '送信設定を確認' +
            '</button>' +
            '</div>' +
            '</div>';
    }

    function saveMailSettings(button) {
        const smtp = $('smtp-server');
        const port = $('smtp-port');
        const security = $('smtp-security');
        const user = $('smtp-user');
        const password = $('smtp-password');
        const from = $('smtp-from');
        const fromName = $('smtp-from-name');

        post(
            'save_mail_settings',
            {
                smtp: smtp ? smtp.value.trim() : '',
                port: port ? port.value.trim() : '',
                security: security ? security.value : 'STARTTLS',
                username: user ? user.value.trim() : '',
                password: password ? password.value : '',
                from: from ? from.value.trim() : '',
                fromName: fromName ? fromName.value.trim() : ''
            },
            button
        ).then(function (result) {
            mailSettings = result.mail;
            renderMailSettings();
            showToast(result.message);
        }).catch(function (error) {
            window.alert(error.message);
        });
    }

    function testMailSettings(button) {
        post('test_mail_settings', {}, button)
            .then(function (result) {
                openModal(
                    'メール送信設定の確認',
                    '<div class="notice success">' +
                    escapeHtml(result.message) +
                    '</div>' +
                    '<p><strong>SMTPサーバ：</strong>' +
                    escapeHtml(mailSettings.smtp) +
                    '</p>' +
                    '<p><strong>ポート：</strong>' +
                    escapeHtml(mailSettings.port) +
                    '</p>' +
                    '<p><strong>接続方式：</strong>' +
                    escapeHtml(mailSettings.security) +
                    '</p>',
                    '<button type="button" class="btn" data-action="close-modal">閉じる</button>'
                );
            })
            .catch(function (error) {
                window.alert(error.message);
            });
    }

    function renderKintoneSettings() {
        const target = $('settings-content');

        if (!target) {
            return;
        }

        target.innerHTML =
            '<div class="card">' +
            '<div class="card-title">キントーン設定</div>' +

            '<div class="status-line">' +
            '<span class="status-dot ' +
            (kintoneSettings.ready ? 'ok' : 'warn') +
            '"></span>' +
            '<span>' +
            (kintoneSettings.ready
                ? '顧客一覧を取得できる設定が保存されています。'
                : 'キントーン設定が未完了です。') +
            '</span>' +
            '</div>' +

            '<div class="notice">顧客一覧の取得にはキントーンのログイン名・パスワードを使用します。APIトークンは使用しません。</div>' +

            '<div class="field">' +
            '<label>キントーンの利用先 *</label>' +
            '<input id="kt-domain" value="' +
            escapeHtml(kintoneSettings.domain || '') +
            '" placeholder="https://example.cybozu.com">' +
            '</div>' +

            '<div class="field">' +
            '<label>顧客管理アプリID *</label>' +
            '<input id="kt-appid" value="' +
            escapeHtml(kintoneSettings.appId || '') +
            '" placeholder="123">' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>ログイン名 *</label>' +
            '<input id="kt-user" value="' +
            escapeHtml(kintoneSettings.loginName || '') +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label>パスワード *</label>' +
            '<input id="kt-password" type="password" value="" placeholder="' +
            (kintoneSettings.hasPassword
                ? '保存済み（変更する場合のみ入力）'
                : 'パスワードを入力') +
            '">' +
            '</div>' +
            '</div>' +

            '<div class="card" style="background:#fafbfc;margin-top:20px;margin-bottom:0">' +
            '<div class="card-title">接続経路</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>プロキシ ホスト名</label>' +
            '<input id="kt-proxy-host" value="' +
            escapeHtml(kintoneSettings.proxyHost || '') +
            '" placeholder="proxy.example.local">' +
            '</div>' +

            '<div class="field">' +
            '<label>プロキシ ポート番号</label>' +
            '<input id="kt-proxy-port" value="' +
            escapeHtml(kintoneSettings.proxyPort || '') +
            '" placeholder="8080">' +
            '</div>' +
            '</div>' +

            '<div class="notice">プロキシ認証は使用しません。SSL証明書の検証は無効固定です。</div>' +

            '<div style="display:flex;gap:8px">' +
            '<button type="button" class="btn btn-primary" data-action="save-kintone-settings">設定を保存</button>' +
            '<button type="button" class="btn" data-action="test-kintone">接続確認</button>' +
            '</div>' +
            '</div>' +
            '</div>';
    }

    function saveKintoneSettings(button) {
        const domain = $('kt-domain');
        const appId = $('kt-appid');
        const user = $('kt-user');
        const password = $('kt-password');
        const proxyHost = $('kt-proxy-host');
        const proxyPort = $('kt-proxy-port');

        post(
            'save_kintone_settings',
            {
                domain: domain ? domain.value.trim() : '',
                appId: appId ? appId.value.trim() : '',
                loginName: user ? user.value.trim() : '',
                password: password ? password.value : '',
                proxyHost: proxyHost ? proxyHost.value.trim() : '',
                proxyPort: proxyPort ? proxyPort.value.trim() : ''
            },
            button
        ).then(function (result) {
            kintoneSettings = result.kintone;
            renderKintoneSettings();
            showToast(result.message);
        }).catch(function (error) {
            window.alert(error.message);
        });
    }

    function testKintone(button) {
        post('test_kintone', {}, button)
            .then(function (result) {
                openModal(
                    'キントーン接続確認',
                    '<div class="notice success">' +
                    escapeHtml(result.message) +
                    '</div>',
                    '<button type="button" class="btn" data-action="close-modal">閉じる</button>'
                );
            })
            .catch(function (error) {
                window.alert(error.message);
            });
    }

    function openModal(title, body, footer, extra) {
        const modal = $('modal');
        const modalTitle = $('modal-title');
        const modalBody = $('modal-body');
        const modalFooter = $('modal-footer');

        if (!modal || !modalTitle || !modalBody || !modalFooter) {
            return;
        }

        modalTitle.textContent = title;
        modalBody.innerHTML = body;
        modalFooter.innerHTML = footer;

        pendingMail = extra || null;

        modal.classList.remove('hidden');
    }

    function closeModal() {
        const modal = $('modal');

        if (modal) {
            modal.classList.add('hidden');
        }

        pendingMail = null;
    }

    function previewEditor() {
        if (!editingSurvey) {
            return;
        }

        try {
            const name = $('survey-name');
            const description = $('survey-description');
            const status = $('survey-status');
            const start = $('survey-start');
            const end = $('survey-end');

            editingSurvey.name = name ? name.value.trim() : '';
            editingSurvey.description = description ? description.value.trim() : '';
            editingSurvey.status = status ? status.value : 'draft';
            editingSurvey.start = start ? start.value : '';
            editingSurvey.end = end ? end.value : '';

            const numbering = document.querySelector(
                'input[name="numbering"]:checked'
            );

            editingSurvey.numbering = numbering
                ? numbering.value
                : 'global';

            validateSurvey(editingSurvey);

            let html =
                '<div class="card">' +
                '<div class="card-title">' +
                escapeHtml(editingSurvey.name) +
                '</div>' +
                '<p>' +
                escapeHtml(editingSurvey.description || '') +
                '</p>';

            editingSurvey.groups.forEach(function (group, gi) {
                html +=
                    '<div class="card">' +
                    '<div class="card-title">' +
                    escapeHtml(group.name) +
                    '</div>';

                group.questions.forEach(function (question, qi) {
                    html +=
                        '<div class="preview-question">' +
                        '<div class="preview-question-title">' +
                        getQuestionNumber(gi, qi) +
                        '　' +
                        escapeHtml(question.text) +
                        '</div>';

                    if (question.type === 'free') {
                        html += '<div class="preview-option">自由記述</div>';
                    } else {
                        (question.options || []).forEach(function (option) {
                            html +=
                                '<div class="preview-option">・' +
                                escapeHtml(option.text) +
                                '</div>';
                        });
                    }

                    html += '</div>';
                });

                html += '</div>';
            });

            html += '</div>';

            openModal(
                'アンケート内容確認',
                html,
                '<button type="button" class="btn" data-action="close-modal">閉じる</button>'
            );
        } catch (error) {
            window.alert(error.message);
        }
    }

    function selectAllVisibleCustomers() {
        const searchEl = $('send-customer-search');

        const search = searchEl
            ? searchEl.value.trim().toLowerCase()
            : '';

        customers.forEach(function (customer) {
            const visible =
                !search ||
                String(customer.name || '').toLowerCase().includes(search) ||
                String(customer.email || '').toLowerCase().includes(search) ||
                String(customer.company || '').toLowerCase().includes(search);

            const id = String(customer.id);

            if (visible && !selectedCustomers.includes(id)) {
                selectedCustomers.push(id);
            }
        });

        renderSendCustomers();
    }

    function handleAction(action, element) {
        const id = element?.getAttribute('data-id') || '';
        const groupId = element?.getAttribute('data-group-id') || '';
        const questionId = element?.getAttribute('data-question-id') || '';
        const optionIndex = element?.getAttribute('data-option-index') || '';

        if (action === 'list') {
            showList();
            return;
        }

        if (action === 'create') {
            openCreate();
            return;
        }

        if (action === 'customers') {
            showCustomers();
            return;
        }

        if (action === 'settings') {
            showSettings('mail');
            return;
        }

        if (action === 'settings-mail') {
            showSettings('mail');
            return;
        }

        if (action === 'settings-kintone') {
            showSettings('kintone');
            return;
        }

        if (action === 'open-detail') {
            openDetail(id);
            return;
        }

        if (action === 'edit-survey') {
            editSurvey(id);
            return;
        }

        if (action === 'publish-survey') {
            publishSurvey(id);
            return;
        }

        if (action === 'end-survey') {
            endSurvey(id);
            return;
        }

        if (action === 'delete-survey') {
            deleteSurvey(id);
            return;
        }

        if (action === 'edit-current') {
            if (currentSurveyId !== null) {
                editSurvey(currentSurveyId);
            }
            return;
        }

        if (action === 'detail-send') {
            showDetailTab('send');
            return;
        }

        if (action === 'add-group') {
            addGroup();
            return;
        }

        if (action === 'add-question') {
            addQuestion(groupId);
            return;
        }

        if (action === 'delete-group') {
            deleteGroup(id);
            return;
        }

        if (action === 'delete-question') {
            deleteQuestion(questionId || id);
            return;
        }

        if (action === 'add-option') {
            addOption(questionId);
            return;
        }

        if (action === 'delete-option') {
            deleteOption(questionId, optionIndex);
            return;
        }

        if (action === 'preview-editor') {
            previewEditor();
            return;
        }

        if (action === 'save-survey') {
            saveSurvey();
            return;
        }

        if (action === 'refresh-customers') {
            refreshCustomers(element);
            return;
        }

        if (action === 'select-all-visible') {
            selectAllVisibleCustomers();
            return;
        }

        if (action === 'confirm-send') {
            confirmSend(element);
            return;
        }

        if (action === 'send-mail') {
            sendSurveyMail(element);
            return;
        }

        if (action === 'close-modal') {
            closeModal();
            return;
        }

        if (action === 'save-mail-settings') {
            saveMailSettings(element);
            return;
        }

        if (action === 'test-mail-settings') {
            testMailSettings(element);
            return;
        }

        if (action === 'save-kintone-settings') {
            saveKintoneSettings(element);
            return;
        }

        if (action === 'test-kintone') {
            testKintone(element);
        }
    }

    const navList = document.querySelector('[data-nav="list"]');
    if (navList) {
        navList.id = 'nav-list';
        navList.addEventListener('click', function () {
            showList();
        });
    }

    const navCreate = document.querySelector('[data-nav="create"]');
    if (navCreate) {
        navCreate.id = 'nav-create';
        navCreate.addEventListener('click', function () {
            openCreate();
        });
    }

    const navCustomers = document.querySelector('[data-nav="customers"]');
    if (navCustomers) {
        navCustomers.id = 'nav-customers';
        navCustomers.addEventListener('click', function () {
            showCustomers();
        });
    }

    const navSettings = document.querySelector('[data-nav="settings"]');
    if (navSettings) {
        navSettings.id = 'nav-settings';
        navSettings.addEventListener('click', function () {
            showSettings('mail');
        });
    }

    document.querySelectorAll('[data-settings-tab]').forEach(function (button) {
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            showSettings(
                button.getAttribute('data-settings-tab') || 'mail'
            );
        });
    });

    document.querySelectorAll('[data-detail-tab]').forEach(function (button) {
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            showDetailTab(
                button.getAttribute('data-detail-tab') || 'content'
            );
        });
    });

    document.addEventListener('click', function (event) {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        const actionElement = target.closest('[data-action]');

        if (!actionElement) {
            return;
        }

        handleAction(
            actionElement.getAttribute('data-action') || '',
            actionElement
        );
    });

    document.addEventListener('input', function (event) {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        if (target.id === 'customer-search') {
            renderCustomers();
            return;
        }

        if (target.id === 'send-customer-search') {
            renderSendCustomers();
            return;
        }

        if (target.hasAttribute('data-group-name')) {
            const groupId = target.getAttribute('data-group-name');

            if (editingSurvey) {
                const group = editingSurvey.groups.find(function (item) {
                    return String(item.id) === String(groupId);
                });

                if (group) {
                    group.name = target.value;
                    dirty = true;
                }
            }

            return;
        }

        if (target.hasAttribute('data-question-field')) {
            const field = target.getAttribute('data-question-field');
            const questionId = target.getAttribute('data-question-id');
            const found = getQuestionById(questionId);

            if (!found) {
                return;
            }

            if (field === 'text') {
                found.question.text = target.value;
                dirty = true;
            }

            return;
        }

        if (target.hasAttribute('data-option-text')) {
            const questionId = target.getAttribute('data-option-text');
            const optionIndex = Number(target.getAttribute('data-option-index'));
            const found = getQuestionById(questionId);

            if (
                found &&
                Array.isArray(found.question.options) &&
                found.question.options[optionIndex]
            ) {
                found.question.options[optionIndex].text = target.value;
                dirty = true;
            }
        }
    });

    document.addEventListener('change', function (event) {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        if (target.matches('[data-question-field="type"]')) {
            const questionId = target.getAttribute('data-question-id');
            const found = getQuestionById(questionId);

            if (!found) {
                return;
            }

            found.question.type = target.value;

            if (target.value === 'free') {
                found.question.options = [];
            } else if (!Array.isArray(found.question.options)) {
                found.question.options = [];
            }

            dirty = true;
            renderEditor();
            return;
        }

        if (target.matches('[data-question-field="required"]')) {
            const questionId = target.getAttribute('data-question-id');
            const found = getQuestionById(questionId);

            if (found) {
                found.question.required = target.checked;
                dirty = true;
            }

            return;
        }

        if (target.matches('[data-branch-question]')) {
            const questionId = target.getAttribute('data-branch-question');
            const optionIndex = Number(
                target.getAttribute('data-option-index')
            );
            const found = getQuestionById(questionId);

            if (
                found &&
                Array.isArray(found.question.options) &&
                found.question.options[optionIndex]
            ) {
                found.question.options[optionIndex].branch = target.value;
                dirty = true;
            }

            return;
        }

        if (target.matches('[data-customer-id]')) {
            const customerId = String(
                target.getAttribute('data-customer-id') || ''
            );

            if (target.checked) {
                if (!selectedCustomers.includes(customerId)) {
                    selectedCustomers.push(customerId);
                }
            } else {
                selectedCustomers = selectedCustomers.filter(function (id) {
                    return id !== customerId;
                });
            }

            const count = $('selected-count');

            if (count) {
                count.textContent = String(selectedCustomers.length);
            }
        }
    });

    document.addEventListener('dragstart', function (event) {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        const group = target.closest('.group-card');

        if (group) {
            draggedGroupId = group.getAttribute('data-group-id');
            return;
        }

        const question = target.closest('.question-card');

        if (question) {
            draggedQuestionId = question.getAttribute('data-question-id');
        }
    });

    document.addEventListener('dragover', function (event) {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        if (
            target.closest('.group-card') ||
            target.closest('.question-card')
        ) {
            event.preventDefault();
        }
    });

    document.addEventListener('drop', function (event) {
        const target = event.target;

        if (!(target instanceof Element) || !editingSurvey) {
            return;
        }

        const groupTarget = target.closest('.group-card');

        if (groupTarget && draggedGroupId) {
            event.preventDefault();

            const targetId = groupTarget.getAttribute('data-group-id');

            if (
                targetId &&
                String(targetId) !== String(draggedGroupId)
            ) {
                const fromIndex = editingSurvey.groups.findIndex(function (group) {
                    return String(group.id) === String(draggedGroupId);
                });

                const toIndex = editingSurvey.groups.findIndex(function (group) {
                    return String(group.id) === String(targetId);
                });

                if (fromIndex >= 0 && toIndex >= 0) {
                    const moved = editingSurvey.groups.splice(fromIndex, 1)[0];
                    editingSurvey.groups.splice(toIndex, 0, moved);
                    dirty = true;
                    renderEditor();
                }
            }

            draggedGroupId = null;
            draggedQuestionId = null;
            return;
        }

        const questionTarget = target.closest('.question-card');

        if (questionTarget && draggedQuestionId) {
            event.preventDefault();

            const targetQuestionId =
                questionTarget.getAttribute('data-question-id');

            const source = getQuestionById(draggedQuestionId);
            const destination = getQuestionById(targetQuestionId);

            if (
                source &&
                destination &&
                String(source.question.id) !== String(destination.question.id)
            ) {
                if (source.group.id === destination.group.id) {
                    const questions = source.group.questions;
                    const fromIndex = questions.findIndex(function (question) {
                        return String(question.id) === String(draggedQuestionId);
                    });
                    const toIndex = questions.findIndex(function (question) {
                        return String(question.id) === String(targetQuestionId);
                    });

                    if (fromIndex >= 0 && toIndex >= 0) {
                        const moved = questions.splice(fromIndex, 1)[0];
                        questions.splice(toIndex, 0, moved);
                        dirty = true;
                        renderEditor();
                    }
                }
            }

            draggedQuestionId = null;
        }
    });

    window.addEventListener('beforeunload', function (event) {
        if (!dirty) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });

    renderList();
    renderCustomerStatus();

    /*
     * 重要:
     * メール設定はここでサーバー側の data/settings.json から
     * PHPが読み込んだ初期値を使用している。
     *
     * したがって従来のように
     *
     * smtp: '',
     * port: '587',
     * ready: false
     *
     * へ毎回戻ることはない。
     */
    showPage('page-list');
});
</script>

</body>
</html>

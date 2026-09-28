<?php
declare(strict_types=1);
namespace Yokoyamy\QuestionnaireOperationApp;

const APP_DIR = __DIR__;
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR;
const SESSION_KEY = 'yokoyamy_questionnaire_operation_app';

\session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

\header('X-Frame-Options: SAMEORIGIN');
\header('X-Content-Type-Options: nosniff');
\header('Referrer-Policy: same-origin');
\header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION[SESSION_KEY]) || !is_array($_SESSION[SESSION_KEY])) {
    $_SESSION[SESSION_KEY] = [];
}
if (!isset($_SESSION[SESSION_KEY]['csrf_token']) || !is_string($_SESSION[SESSION_KEY]['csrf_token'])) {
    $_SESSION[SESSION_KEY]['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION[SESSION_KEY]['csrf_token'];

function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_file_path(string $name): string {
    return APP_DIR . DIRECTORY_SEPARATOR . $name;
}

function read_json_file(string $path, array $default): array {
    if (!is_file($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function write_json_file(string $path, array $data): bool {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
    $written = file_put_contents($tmp, $json . PHP_EOL, LOCK_EX);

    if ($written === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function now_string(): string {
    return date('Y-m-d H:i:s');
}

function make_id(string $prefix): string {
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
}

function api_response(array $response, int $status = 200): never {
    http_response_code($status);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_csrf(): void {
    $token = '';

    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (isset($_POST['csrf_token'])) {
        $token = (string)$_POST['csrf_token'];
    }

    $saved = $_SESSION[SESSION_KEY]['csrf_token'] ?? '';

    if (!is_string($saved) || $saved === '' || !hash_equals($saved, $token)) {
        api_response([
            'success' => false,
            'message' => 'セキュリティ確認に失敗しました。ページを再読み込みしてください。'
        ], 403);
    }
}

function get_request_json(): array {
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sanitize_error_message(string $message): string {
    $message = preg_replace(
        '/(password|passwd|token|authorization|x-cybozu-authorization)\s*[:=]\s*[^\s,;]+/i',
        '$1: [REDACTED]',
        $message
    ) ?? $message;

    return mb_substr($message, 0, 1000, 'UTF-8');
}

function get_safe_response_headers(): array {
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

function kintone_build_url(string $domain, string $endpoint): string {
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain) ?? $domain;
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain) ?? $domain;
    $domain = rtrim($domain, '/');

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

function make_cybozu_auth_header(string $loginName, string $password): string {
    $auth = base64_encode(trim($loginName) . ':' . trim($password));
    return 'X-Cybozu-Authorization: ' . $auth;
}

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20
    ];

    if ($method !== 'GET' && $payload !== null) {
        $encoded = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE)
            : (string)$payload;

        $http['content'] = $encoded === false ? '' : $encoded;
    }

    $contextOptions = [
        'http' => $http,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    $proxy = trim((string)($config['proxy_host_port'] ?? ''));

    if ($proxy !== '') {
        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $body = @file_get_contents($url, false, $context);
    $headersOut = get_safe_response_headers();

    $status = 0;

    foreach ($headersOut as $headerLine) {
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/i', $headerLine, $m)) {
            $status = (int)$m[1];
        }
    }

    $data = json_decode($body === false ? '' : $body, true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($data) ? $data : []
        ];
    }

    $message = is_array($data) && isset($data['message'])
        ? (string)$data['message']
        : 'kintone API 通信エラーが発生しました。';

    if (is_array($data) && isset($data['code'])) {
        $message = (string)$data['code'] . ': ' . $message;
    }

    if (is_array($data) && isset($data['errors']) && is_array($data['errors'])) {
        $details = [];

        foreach ($data['errors'] as $field => $detail) {
            $messages = (
                is_array($detail)
                && isset($detail['messages'])
                && is_array($detail['messages'])
            ) ? $detail['messages'] : [];

            $details[] = (string)$field . ': ' . implode(' / ', array_map('strval', $messages));
        }

        if ($details) {
            $message .= ' (' . implode(', ', $details) . ')';
        }
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => sanitize_error_message($message),
        'data' => is_array($data) ? $data : []
    ];
}

function load_settings(): array {
    return read_json_file(
        json_file_path('app_settings.json'),
        [
            'smtp' => [
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'username' => '',
                'password' => '',
                'from_email' => '',
                'from_name' => 'アンケート事務局'
            ],
            'kintone' => [
                'domain' => '',
                'app_id' => '',
                'login' => '',
                'password' => '',
                'proxy_host_port' => ''
            ],
            'mapping' => [
                'name' => '',
                'organization' => '',
                'department' => '',
                'email' => '',
                'phone' => '',
                'address' => []
            ],
            'kintone_fields' => []
        ]
    );
}

function load_surveys(): array {
    $default = [
        [
            'id' => 's1',
            'name' => '顧客満足度調査 2026上期',
            'description' => 'サービスについてのご意見をお聞かせください。',
            'status' => 'published',
            'startAt' => '2026-09-10',
            'endAt' => '2026-10-10',
            'numberingFormat' => 'Q1, Q2, Q3',
            'groups' => [
                [
                    'id' => 'g1',
                    'name' => '基本情報',
                    'questions' => [
                        [
                            'id' => 'q1',
                            'type' => 'single',
                            'text' => 'お住まいの地域を教えてください。',
                            'required' => true,
                            'choices' => ['北海道・東北', '関東', 'その他'],
                            'branch' => null
                        ],
                        [
                            'id' => 'q2',
                            'type' => 'multiple',
                            'text' => 'ご利用中のサービスを教えてください。',
                            'required' => false,
                            'choices' => ['サービスA', 'サービスB', 'サービスC'],
                            'branch' => null
                        ]
                    ]
                ],
                [
                    'id' => 'g2',
                    'name' => 'ご意見',
                    'questions' => [
                        [
                            'id' => 'q3',
                            'type' => 'text',
                            'text' => 'ご自由にご意見をお書きください。',
                            'required' => false,
                            'choices' => [],
                            'branch' => null
                        ]
                    ]
                ]
            ],
            'createdAt' => '2026-09-01 10:00:00',
            'updatedAt' => '2026-09-20 15:30:00'
        ],
        [
            'id' => 's2',
            'name' => '新商品コンセプトアンケート',
            'description' => '新商品のコンセプトについてお聞かせください。',
            'status' => 'draft',
            'startAt' => '',
            'endAt' => '',
            'numberingFormat' => 'Q1, Q2, Q3',
            'groups' => [
                [
                    'id' => 'g1',
                    'name' => '質問グループ',
                    'questions' => [
                        [
                            'id' => 'q1',
                            'type' => 'single',
                            'text' => 'コンセプトはいかがですか？',
                            'required' => true,
                            'choices' => ['良い', '普通', '改善してほしい'],
                            'branch' => null
                        ]
                    ]
                ]
            ],
            'createdAt' => '2026-09-20 09:00:00',
            'updatedAt' => '2026-09-24 18:00:00'
        ]
    ];

    return read_json_file(json_file_path('surveys.json'), $default);
}

function load_customers(): array {
    $default = [
        [
            'id' => 'c1',
            'name' => '山田 太郎',
            'organization' => '株式会社サンプル',
            'department' => '営業部',
            'email' => 'taro.yamada@example.com',
            'phone' => '03-0000-0001',
            'address' => '東京都港区',
            'active' => true,
            'createdAt' => '2025-04-01'
        ],
        [
            'id' => 'c2',
            'name' => '佐藤 花子',
            'organization' => '株式会社サンプル',
            'department' => '企画部',
            'email' => 'hanako.sato@example.com',
            'phone' => '03-0000-0002',
            'address' => '東京都千代田区',
            'active' => true,
            'createdAt' => '2025-05-12'
        ],
        [
            'id' => 'c3',
            'name' => '鈴木 次郎',
            'organization' => '株式会社テスト',
            'department' => '管理部',
            'email' => 'jiro.suzuki@example.com',
            'phone' => '03-0000-0003',
            'address' => '東京都新宿区',
            'active' => true,
            'createdAt' => '2025-06-20'
        ]
    ];

    return read_json_file(json_file_path('customers.json'), $default);
}

function load_responses(): array {
    return read_json_file(json_file_path('responses.json'), []);
}

function validate_survey(array $survey, array $allSurveys): array {
    $errors = [];

    if (trim((string)($survey['name'] ?? '')) === '') {
        $errors[] = 'アンケート名を入力してください。';
    }

    $questions = [];

    foreach (($survey['groups'] ?? []) as $group) {
        $groupId = (string)($group['id'] ?? '');

        if ($groupId === '') {
            $errors[] = 'グループIDがありません。';
        }

        foreach (($group['questions'] ?? []) as $q) {
            $qid = (string)($q['id'] ?? '');

            if ($qid === '' || isset($questions[$qid])) {
                $errors[] = '質問IDが重複しています。';
            }

            $questions[$qid] = $q;

            if (trim((string)($q['text'] ?? '')) === '') {
                $errors[] = '質問文が未入力です。';
            }

            if (($q['type'] ?? '') !== 'text') {
                $choices = $q['choices'] ?? [];
                $seen = [];

                foreach ($choices as $choice) {
                    $c = trim((string)$choice);

                    if ($c === '') {
                        $errors[] = '選択肢に空欄があります。';
                    }

                    if (isset($seen[$c])) {
                        $errors[] = '選択肢が重複しています。';
                    }

                    $seen[$c] = true;
                }
            }

            if (($q['branch'] ?? null) !== null && ($q['type'] ?? '') !== 'single') {
                $errors[] = '分岐は単一選択質問にだけ設定できます。';
            }
        }
    }

    foreach ($questions as $q) {
        $branch = $q['branch'] ?? null;

        if (!is_array($branch)) {
            continue;
        }

        foreach ($branch as $target) {
            $target = (string)$target;

            if ($target === '' || $target === 'next' || $target === 'end') {
                continue;
            }

            if (str_starts_with($target, 'question:')) {
                $targetId = substr($target, 9);

                if (!isset($questions[$targetId])) {
                    $errors[] = '分岐先の質問が存在しません。';
                }
            }
        }
    }

    return array_values(array_unique($errors));
}

function find_survey(array $surveys, string $id): ?array {
    foreach ($surveys as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function upsert_by_id(array $items, array $item): array {
    $id = (string)($item['id'] ?? '');

    foreach ($items as $i => $old) {
        if ((string)($old['id'] ?? '') === $id) {
            $items[$i] = $item;
            return array_values($items);
        }
    }

    $items[] = $item;
    return array_values($items);
}

function smtp_read($socket): array {
    $lines = [];

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $lines[] = trim($line);

        if (preg_match('/^(\d{3})\s/', $line, $m)) {
            return [
                'code' => (int)$m[1],
                'text' => implode(' ', $lines)
            ];
        }
    }

    return [
        'code' => 0,
        'text' => implode(' ', $lines)
    ];
}

function smtp_command($socket, string $command, array $expected): bool {
    fwrite($socket, $command . "\r\n");
    $reply = smtp_read($socket);

    return in_array($reply['code'], $expected, true);
}

function smtp_open(array $cfg): array {
    $host = trim((string)($cfg['host'] ?? ''));
    $port = (int)($cfg['port'] ?? 0);
    $encryption = strtolower((string)($cfg['encryption'] ?? 'none'));

    if ($host === '' || $port < 1 || $port > 65535) {
        return [
            'success' => false,
            'message' => 'SMTPホストとポートを確認してください。'
        ];
    }

    $transport = $encryption === 'ssl'
        ? 'ssl://' . $host
        : $host;

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transport . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        return [
            'success' => false,
            'message' => 'SMTPサーバへ接続できませんでした。' .
                ($errstr !== '' ? ' ' . sanitize_error_message($errstr) : '')
        ];
    }

    stream_set_timeout($socket, 15);

    $greeting = smtp_read($socket);

    if ($greeting['code'] < 200 || $greeting['code'] >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'SMTPサーバの応答を確認できませんでした。'
        ];
    }

    if (!smtp_command($socket, 'EHLO localhost', [250])) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'SMTP EHLOに失敗しました。'
        ];
    }

    if ($encryption === 'tls') {
        $tls = smtp_command($socket, 'STARTTLS', [220]);

        if (
            !$tls ||
            !stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'STARTTLSに失敗しました。'
            ];
        }

        if (!smtp_command($socket, 'EHLO localhost', [250])) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'TLS後のEHLOに失敗しました。'
            ];
        }
    }

    $username = trim((string)($cfg['username'] ?? ''));
    $password = (string)($cfg['password'] ?? '');

    if ($username !== '') {
        if (
            !smtp_command($socket, 'AUTH LOGIN', [334]) ||
            !smtp_command($socket, base64_encode($username), [334]) ||
            !smtp_command($socket, base64_encode($password), [235])
        ) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'SMTP認証に失敗しました。'
            ];
        }
    }

    return [
        'success' => true,
        'socket' => $socket
    ];
}

function smtp_send_mail(
    array $cfg,
    string $to,
    string $subject,
    string $body
): array {
    $opened = smtp_open($cfg);

    if (
        !$opened['success'] ||
        !isset($opened['socket']) ||
        !is_resource($opened['socket'])
    ) {
        return [
            'success' => false,
            'message' => (string)$opened['message']
        ];
    }

    $socket = $opened['socket'];

    $from = trim((string)($cfg['from_email'] ?? ''));
    $fromName = trim((string)($cfg['from_name'] ?? ''));

    if (
        $from === '' ||
        !filter_var($from, FILTER_VALIDATE_EMAIL) ||
        !filter_var($to, FILTER_VALIDATE_EMAIL)
    ) {
        fclose($socket);

        return [
            'success' => false,
            'message' => '送信元または宛先メールアドレスを確認してください。'
        ];
    }

    if (
        !smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]) ||
        !smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]) ||
        !smtp_command($socket, 'DATA', [354])
    ) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'SMTP送信開始に失敗しました。'
        ];
    }

    $encodedName = $fromName !== ''
        ? '=?UTF-8?B?' . base64_encode($fromName) . '?='
        : $from;

    $headers = 'From: ' . $encodedName . ' <' . $from . "\r\n";
    $headers .= 'To: <' . $to . ">\r\n";
    $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n\r\n";

    $body = preg_replace('/\r?\n/', "\r\n", $body) ?? $body;
    $body = preg_replace('/^\./m', '..', $body) ?? $body;

    fwrite($socket, $headers . $body . "\r\n.\r\n");

    $reply = smtp_read($socket);

    smtp_command($socket, 'QUIT', [221, 250]);
    fclose($socket);

    if ($reply['code'] >= 200 && $reply['code'] < 300) {
        return [
            'success' => true,
            'message' => '送信しました。'
        ];
    }

    return [
        'success' => false,
        'message' => 'SMTPサーバから送信拒否の応答がありました。'
    ];
}

function build_kintone_headers(array $cfg): array {
    return [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                trim((string)$cfg['login']) . ':' .
                trim((string)$cfg['password'])
            ),
        'Accept: application/json'
    ];
}

$surveys = load_surveys();
$customers = load_customers();
$responses = load_responses();
$settings = load_settings();

$respondToken = isset($_GET['respond'])
    ? trim((string)$_GET['respond'])
    : '';

if ($respondToken !== '') {
    $targetResponse = null;

    foreach ($responses as $response) {
        if (($response['token'] ?? '') === $respondToken) {
            $targetResponse = $response;
            break;
        }
    }

    if ($targetResponse === null) {
        http_response_code(404);

        echo '<!doctype html>
<meta charset="UTF-8">
<title>回答URL</title>
<p style="font-family:sans-serif;padding:40px">
回答URLが見つかりません。
</p>';

        exit;
    }

    $survey = find_survey(
        $surveys,
        (string)$targetResponse['surveyId']
    );

    if ($survey === null) {
        http_response_code(404);

        echo '<!doctype html>
<meta charset="UTF-8">
<title>回答URL</title>
<p style="font-family:sans-serif;padding:40px">
アンケートが見つかりません。
</p>';

        exit;
    }

    $responseJson = json_encode(
        $targetResponse,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $surveyJson = json_encode(
        $survey,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $csrfJson = json_encode($csrfToken);

    echo '<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . h((string)$survey['name']) . '</title>
<style>
body{
    margin:0;
    background:#f5f7fa;
    color:#263238;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Meiryo",sans-serif
}
.box{
    max-width:720px;
    margin:40px auto;
    background:#fff;
    border:1px solid #e1e6eb;
    border-radius:10px;
    padding:28px
}
.steps{
    display:flex;
    gap:6px;
    margin-bottom:24px
}
.step{
    flex:1;
    text-align:center;
    padding:8px;
    background:#edf1f4;
    border-radius:5px;
    font-size:12px;
    color:#65717b
}
.step.active{
    background:#dceefa;
    color:#21658e;
    font-weight:700
}
.field{
    margin:0 0 16px
}
.field label{
    display:block;
    font-size:13px;
    margin-bottom:6px
}
.field input,
.field textarea,
.field select{
    width:100%;
    padding:10px;
    border:1px solid #ccd5dd;
    border-radius:5px;
    box-sizing:border-box
}
.choice{
    display:block;
    margin:9px 0
}
.btn{
    border:0;
    border-radius:5px;
    background:#2f80c0;
    color:#fff;
    padding:10px 16px;
    cursor:pointer
}
.btn:disabled{
    opacity:.55
}
.loading{
    position:relative
}
.loading:after{
    content:"";
    display:inline-block;
    width:12px;
    height:12px;
    margin-left:8px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
    vertical-align:-2px
}
@keyframes spin{
    to{transform:rotate(360deg)}
}
.error{
    color:#a62f2f;
    background:#fff2f2;
    border:1px solid #e0a0a0;
    padding:10px;
    border-radius:5px;
    margin-bottom:14px
}
.success{
    background:#eefaf2;
    border:1px solid #9bcba9;
    padding:14px;
    border-radius:5px
}
h1{font-size:22px}
h2{font-size:17px}
small{color:#74808c}
</style>
</head>
<body>
<main class="box">
<small>個別回答</small>
<h1 id="r-title"></h1>

<div class="steps">
<div class="step active" id="step1">1 回答者情報</div>
<div class="step" id="step2">2 アンケート</div>
<div class="step" id="step3">3 完了</div>
</div>

<div id="r-error" class="error" style="display:none"></div>

<section id="r-info">
<div class="field">
<label>組織名（必須）</label>
<input id="r-org">
</div>

<div class="field">
<label>部署名</label>
<input id="r-dept">
</div>

<div class="field">
<label>メールアドレス（必須）</label>
<input id="r-email" type="email">
</div>

<button class="btn" id="r-start">
回答を開始する
</button>
</section>

<section id="r-questions" style="display:none"></section>

<section id="r-done" style="display:none">
<div class="success">
<b>回答ありがとうございました。</b>
<p>
回答を受け付けました。このURLから再度回答することはできません。
</p>
</div>
</section>

</main>

<script>
document.addEventListener("DOMContentLoaded",function(){

const token=<?= $csrfJson ?>;
const response=<?= $responseJson ?>;
const survey=<?= $surveyJson ?>;

const title=document.getElementById("r-title");

if(title){
    title.textContent=survey.name;
}

const error=document.getElementById("r-error");

function err(t){
    if(error){
        error.textContent=t;
        error.style.display="block";
    }
}

function clearErr(){
    if(error){
        error.textContent="";
        error.style.display="none";
    }
}

function step(n){

    [1,2,3].forEach(function(i){
        const s=document.getElementById("step"+i);

        if(s){
            s.classList.toggle("active",i===n);
        }
    });

    ["r-info","r-questions","r-done"].forEach(function(id){
        const e=document.getElementById(id);

        if(e){
            e.style.display="none";
        }
    });

    const target=
        n===1
            ?"r-info"
            :n===2
                ?"r-questions"
                :"r-done";

    const e=document.getElementById(target);

    if(e){
        e.style.display="block";
    }
}

function renderQuestions(){

    const root=document.getElementById("r-questions");

    if(!root){
        return;
    }

    root.textContent="";

    (survey.groups||[]).forEach(function(g){

        const h=document.createElement("h2");
        h.textContent=g.name||"";
        root.appendChild(h);

        (g.questions||[]).forEach(function(q){

            const wrap=document.createElement("div");
            wrap.className="field";

            const label=document.createElement("label");
            label.textContent=
                q.text+(q.required?"（必須）":"");

            wrap.appendChild(label);

            if(q.type==="text"){

                const ta=document.createElement("textarea");
                ta.rows=5;
                ta.dataset.qid=q.id;
                wrap.appendChild(ta);

            }else{

                (q.choices||[]).forEach(function(c){

                    const lab=document.createElement("label");
                    lab.className="choice";

                    const input=document.createElement("input");

                    input.type=
                        q.type==="multiple"
                            ?"checkbox"
                            :"radio";

                    input.name="q_"+q.id;
                    input.value=c;
                    input.dataset.qid=q.id;

                    lab.appendChild(input);
                    lab.appendChild(
                        document.createTextNode(" "+c)
                    );

                    wrap.appendChild(lab);
                });
            }

            root.appendChild(wrap);
        });
    });

    const btn=document.createElement("button");

    btn.className="btn";
    btn.id="r-submit";
    btn.textContent="回答を送信する";

    root.appendChild(btn);

    btn.addEventListener("click",async function(){

        btn.disabled=true;
        btn.classList.add("loading");
        clearErr();

        try{

            const answers={};

            (survey.groups||[]).forEach(function(g){

                (g.questions||[]).forEach(function(q){

                    const inputs=
                        root.querySelectorAll(
                            "[data-qid=\""+
                            CSS.escape(q.id)+
                            "\"]"
                        );

                    if(q.type==="text"){

                        answers[q.id]=
                            inputs[0]
                                ?inputs[0].value
                                :"";

                    }else if(q.type==="multiple"){

                        answers[q.id]=
                            Array.from(inputs)
                            .filter(function(x){
                                return x.checked;
                            })
                            .map(function(x){
                                return x.value;
                            });

                    }else{

                        const x=
                            Array.from(inputs)
                            .find(function(y){
                                return y.checked;
                            });

                        answers[q.id]=
                            x
                                ?x.value
                                :"";
                    }
                });
            });

            const res=await fetch(
                location.pathname,
                {
                    method:"POST",
                    headers:{
                        "Content-Type":"application/json",
                        "X-CSRF-Token":token
                    },
                    body:JSON.stringify({
                        action:"submit_response",
                        token:response.token,
                        answers:answers
                    })
                }
            );

            const data=await res.json();

            if(!data.success){
                err(
                    data.message||
                    "回答を送信できませんでした。"
                );
                return;
            }

            step(3);

        }catch(e){

            err(
                "通信に失敗しました。時間をおいて再度お試しください。"
            );

        }finally{

            btn.disabled=false;
            btn.classList.remove("loading");
        }
    });
}

const start=document.getElementById("r-start");

if(start){

    start.addEventListener(
        "click",
        async function(){

            start.disabled=true;
            start.classList.add("loading");
            clearErr();

            try{

                const org=document.getElementById("r-org");
                const dept=document.getElementById("r-dept");
                const email=document.getElementById("r-email");

                if(
                    !org||
                    !email||
                    !org.value.trim()||
                    !email.value.trim()
                ){
                    err(
                        "組織名とメールアドレスを入力してください。"
                    );
                    return;
                }

                const res=await fetch(
                    location.pathname,
                    {
                        method:"POST",
                        headers:{
                            "Content-Type":"application/json",
                            "X-CSRF-Token":token
                        },
                        body:JSON.stringify({
                            action:"save_respondent",
                            token:response.token,
                            organization:org.value.trim(),
                            department:
                                dept
                                    ?dept.value.trim()
                                    :"",
                            email:email.value.trim()
                        })
                    }
                );

                const data=await res.json();

                if(!data.success){
                    err(
                        data.message||
                        "保存できませんでした。"
                    );
                    return;
                }

                renderQuestions();
                step(2);

            }catch(e){

                err(
                    "通信に失敗しました。時間をおいて再度お試しください"
                );

            }finally{

                start.disabled=false;
                start.classList.remove("loading");
            }
        }
    );
}

if(response.status==="answered"){
    step(3);
}else if(response.status==="info_entered"){
    renderQuestions();
    step(2);
}

});
</script>

</body>
</html>';

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf();

    $request = get_request_json();

    $action =
        isset($request['action'])
            ? (string)$request['action']
            : (
                isset($_POST['action'])
                    ? (string)$_POST['action']
                    : ''
            );

    if ($action === 'save_survey') {

        $survey =
            is_array($request['survey'] ?? null)
                ? $request['survey']
                : [];

        $survey['id'] =
            trim((string)($survey['id'] ?? ''))
                ?: make_id('survey');

        $survey['name'] =
            trim((string)($survey['name'] ?? ''));

        $survey['description'] =
            trim((string)($survey['description'] ?? ''));

        $survey['status'] =
            in_array(
                ($survey['status'] ?? 'draft'),
                ['draft','published','closed'],
                true
            )
                ? (string)$survey['status']
                : 'draft';

        $survey['updatedAt']=now_string();

        $survey['createdAt'] =
            (string)(
                $survey['createdAt']
                ?? now_string()
            );

        $survey['groups'] =
            is_array($survey['groups'] ?? null)
                ? $survey['groups']
                : [];

        $errors=validate_survey(
            $survey,
            $surveys
        );

        if($errors){
            api_response(
                [
                    'success'=>false,
                    'message'=>implode(' ',$errors)
                ],
                422
            );
        }

        $surveys=upsert_by_id(
            $surveys,
            $survey
        );

        if(
            !write_json_file(
                json_file_path('surveys.json'),
                $surveys
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'アンケートを保存できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'survey'=>$survey,
                'message'=>'アンケートを保存しました。'
            ]
        );
    }

    if ($action === 'publish_survey') {

        $id=trim(
            (string)($request['id']??'')
        );

        $survey=find_survey(
            $surveys,
            $id
        );

        if($survey===null){
            api_response(
                [
                    'success'=>false,
                    'message'=>'アンケートが見つかりません。'
                ],
                404
            );
        }

        $errors=validate_survey(
            $survey,
            $surveys
        );

        if($errors){
            api_response(
                [
                    'success'=>false,
                    'message'=>implode(' ',$errors)
                ],
                422
            );
        }

        $survey['status']='published';
        $survey['updatedAt']=now_string();

        $surveys=upsert_by_id(
            $surveys,
            $survey
        );

        if(
            !write_json_file(
                json_file_path('surveys.json'),
                $surveys
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'公開状態を保存できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'survey'=>$survey,
                'message'=>'アンケートを公開しました。'
            ]
        );
    }

    if ($action === 'close_survey') {

        $id=trim(
            (string)($request['id']??'')
        );

        $survey=find_survey(
            $surveys,
            $id
        );

        if($survey===null){
            api_response(
                [
                    'success'=>false,
                    'message'=>'アンケートが見つかりません。'
                ],
                404
            );
        }

        $survey['status']='closed';
        $survey['updatedAt']=now_string();

        $surveys=upsert_by_id(
            $surveys,
            $survey
        );

        if(
            !write_json_file(
                json_file_path('surveys.json'),
                $surveys
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'終了状態を保存できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'survey'=>$survey,
                'message'=>'アンケートを終了しました。'
            ]
        );
    }

    if ($action === 'delete_survey') {

        $id=trim(
            (string)($request['id']??'')
        );

        $found=false;
        $next=[];

        foreach($surveys as $s){

            if((string)($s['id']??'')===$id){
                $found=true;
                continue;
            }

            $next[]=$s;
        }

        if(!$found){
            api_response(
                [
                    'success'=>false,
                    'message'=>'アンケートが見つかりません。'
                ],
                404
            );
        }

        if(
            !write_json_file(
                json_file_path('surveys.json'),
                array_values($next)
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'削除できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'message'=>'アンケートを削除しました。'
            ]
        );
    }

    if ($action === 'create_token') {

        $surveyId=trim(
            (string)($request['surveyId']??'')
        );

        $survey=find_survey(
            $surveys,
            $surveyId
        );

        if($survey===null){
            api_response(
                [
                    'success'=>false,
                    'message'=>'アンケートが見つかりません。'
                ],
                404
            );
        }

        if(($survey['status']??'')!=='published'){
            api_response(
                [
                    'success'=>false,
                    'message'=>'公開中のアンケートだけ回答URLを発行できます。'
                ],
                422
            );
        }

        $token=bin2hex(
            random_bytes(24)
        );

        $response=[
            'id'=>make_id('response'),
            'token'=>$token,
            'surveyId'=>$surveyId,
            'status'=>'unused',
            'organization'=>'',
            'department'=>'',
            'email'=>'',
            'answers'=>[],
            'sendStatus'=>'未送信',
            'sendAt'=>'',
            'answerAt'=>'',
            'error'=>'',
            'createdAt'=>now_string()
        ];

        $responses[]=$response;

        if(
            !write_json_file(
                json_file_path('responses.json'),
                $responses
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'回答URLを保存できませんでした。'
                ],
                500
            );
        }

        $url=
            (
                isset($_SERVER['HTTPS']) &&
                $_SERVER['HTTPS']!=='off'
                    ?'https'
                    :'http'
            )
            .'://'
            .($_SERVER['HTTP_HOST']??'localhost')
            .rtrim(
                dirname(
                    $_SERVER['SCRIPT_NAME']??'/'
                ),
                '/\\'
            )
            .'/index.php?respond='
            .rawurlencode($token);

        api_response(
            [
                'success'=>true,
                'response'=>$response,
                'url'=>$url,
                'message'=>'個別回答URLを発行しました。'
            ]
        );
    }

    if ($action === 'send_invitations') {

        $surveyId=trim(
            (string)($request['surveyId']??'')
        );

        $ids=
            is_array($request['customerIds']??null)
                ?$request['customerIds']
                :[];

        $individuals=
            is_array($request['individuals']??null)
                ?$request['individuals']
                :[];

        $survey=find_survey(
            $surveys,
            $surveyId
        );

        if($survey===null){
            api_response(
                [
                    'success'=>false,
                    'message'=>'アンケートが見つかりません。'
                ],
                404
            );
        }

        if(($survey['status']??'')!=='published'){
            api_response(
                [
                    'success'=>false,
                    'message'=>'公開中のアンケートだけ回答依頼を送信できます。'
                ],
                422
            );
        }

        $smtp=$settings['smtp']??[];

        if(
            trim((string)($smtp['host']??''))==='' ||
            trim((string)($smtp['from_email']??''))===''
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'SMTP設定を保存してから送信してください。'
                ],
                422
            );
        }

        $sent=0;
        $failed=0;
        $created=[];

        foreach($ids as $customerId){

            foreach($customers as $customer){

                if(
                    (string)$customer['id'] !==
                    (string)$customerId
                ){
                    continue;
                }

                if(
                    !filter_var(
                        (string)$customer['email'],
                        FILTER_VALIDATE_EMAIL
                    )
                ){
                    continue;
                }

                $token=bin2hex(
                    random_bytes(24)
                );

                $response=[
                    'id'=>make_id('response'),
                    'token'=>$token,
                    'surveyId'=>$surveyId,
                    'status'=>'unused',
                    'organization'=>$customer['organization'],
                    'department'=>$customer['department'],
                    'email'=>$customer['email'],
                    'answers'=>[],
                    'sendStatus'=>'未送信',
                    'sendAt'=>'',
                    'answerAt'=>'',
                    'error'=>'',
                    'createdAt'=>now_string()
                ];

                $responses[]=$response;

                $url=
                    (
                        isset($_SERVER['HTTPS']) &&
                        $_SERVER['HTTPS']!=='off'
                            ?'https'
                            :'http'
                    )
                    .'://'
                    .($_SERVER['HTTP_HOST']??'localhost')
                    .rtrim(
                        dirname(
                            $_SERVER['SCRIPT_NAME']??'/'
                        ),
                        '/\\'
                    )
                    .'/index.php?respond='
                    .rawurlencode($token);

                $body=
                    "アンケートへのご協力をお願いいたします。\n\n"
                    .$survey['name']
                    ."\n\n回答用URL："
                    .$url
                    ."\n\nこのURLは受信者専用です。";

                $result=smtp_send_mail(
                    $smtp,
                    (string)$customer['email'],
                    'アンケート回答のお願い：'.$survey['name'],
                    $body
                );

                $idx=count($responses)-1;

                if($result['success']){

                    $responses[$idx]['sendStatus']='成功';
                    $responses[$idx]['sendAt']=now_string();
                    $sent++;

                }else{

                    $responses[$idx]['sendStatus']='失敗';
                    $responses[$idx]['error']=$result['message'];
                    $failed++;
                }

                $created[]=$responses[$idx];
            }
        }

        foreach($individuals as $person){

            $email=trim(
                (string)($person['email']??'')
            );

            if(
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ){
                continue;
            }

            $token=bin2hex(
                random_bytes(24)
            );

            $response=[
                'id'=>make_id('response'),
                'token'=>$token,
                'surveyId'=>$surveyId,
                'status'=>'unused',
                'organization'=>trim(
                    (string)($person['organization']??'')
                ),
                'department'=>trim(
                    (string)($person['department']??'')
                ),
                'email'=>$email,
                'answers'=>[],
                'sendStatus'=>'未送信',
                'sendAt'=>'',
                'answerAt'=>'',
                'error'=>'',
                'createdAt'=>now_string()
            ];

            $responses[]=$response;

            $url=
                (
                    isset($_SERVER['HTTPS']) &&
                    $_SERVER['HTTPS']!=='off'
                        ?'https'
                        :'http'
                )
                .'://'
                .($_SERVER['HTTP_HOST']??'localhost')
                .rtrim(
                    dirname(
                        $_SERVER['SCRIPT_NAME']??'/'
                    ),
                    '/\\'
                )
                .'/index.php?respond='
                .rawurlencode($token);

            $result=smtp_send_mail(
                $smtp,
                $email,
                'アンケート回答のお願い：'.$survey['name'],
                "アンケートへのご協力をお願いいたします。\n\n"
                ."回答用URL：".$url
            );

            $idx=count($responses)-1;

            if($result['success']){

                $responses[$idx]['sendStatus']='成功';
                $responses[$idx]['sendAt']=now_string();
                $sent++;

            }else{

                $responses[$idx]['sendStatus']='失敗';
                $responses[$idx]['error']=$result['message'];
                $failed++;
            }

            $created[]=$responses[$idx];
        }

        if(
            !write_json_file(
                json_file_path('responses.json'),
                $responses
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'回答依頼の結果を保存できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'sent'=>$sent,
                'failed'=>$failed,
                'responses'=>$created,
                'message'=>'回答依頼を処理しました。成功 '.$sent.'件、失敗 '.$failed.'件です。'
            ]
        );
    }

    if ($action === 'save_smtp') {

        $smtp=
            is_array($request['smtp']??null)
                ?$request['smtp']
                :[];

        $smtp['host']=
            trim((string)($smtp['host']??''));

        $smtp['port']=
            (int)($smtp['port']??0);

        $smtp['encryption']=
            in_array(
                ($smtp['encryption']??'none'),
                ['none','ssl','tls'],
                true
            )
                ?(string)$smtp['encryption']
                :'none';

        $smtp['username']=
            trim((string)($smtp['username']??''));

        $smtp['password']=
            (string)($smtp['password']??'');

        $smtp['from_email']=
            trim((string)($smtp['from_email']??''));

        $smtp['from_name']=
            trim((string)($smtp['from_name']??''));

        if(
            $smtp['host']==='' ||
            $smtp['port']<1 ||
            $smtp['port']>65535 ||
            !filter_var(
                $smtp['from_email'],
                FILTER_VALIDATE_EMAIL
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'SMTPホスト・ポート・送信元メールアドレスを確認してください。'
                ],
                422
            );
        }

        $settings['smtp']=$smtp;

        if(
            !write_json_file(
                json_file_path('app_settings.json'),
                $settings
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'SMTP設定を保存できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'message'=>'SMTP設定を保存しました。'
            ]
        );
    }

    if ($action === 'test_smtp') {

        $smtp=
            is_array($request['smtp']??null)
                ?$request['smtp']
                :($settings['smtp']??[]);

        $result=smtp_open($smtp);

        if(!$result['success']){
            api_response(
                [
                    'success'=>false,
                    'message'=>$result['message']
                ],
                422
            );
        }

        if(
            isset($result['socket']) &&
            is_resource($result['socket'])
        ){
            smtp_command(
                $result['socket'],
                'QUIT',
                [221,250]
            );

            fclose($result['socket']);
        }

        api_response(
            [
                'success'=>true,
                'message'=>'SMTPサーバへの接続と認証を確認しました。'
            ]
        );
    }

    if ($action === 'save_kintone') {

        $k=
            is_array($request['kintone']??null)
                ?$request['kintone']
                :[];

        $settings['kintone']=[
            'domain'=>trim(
                (string)($k['domain']??'')
            ),
            'app_id'=>trim(
                (string)($k['app_id']??'')
            ),
            'login'=>trim(
                (string)($k['login']??'')
            ),
            'password'=>
                (string)($k['password']??''),
            'proxy_host_port'=>trim(
                (string)($k['proxy_host_port']??'')
            )
        ];

        if(
            $settings['kintone']['domain']==='' ||
            $settings['kintone']['app_id']==='' ||
            $settings['kintone']['login']===''
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'kintoneのサブドメイン・アプリID・ログイン名を入力してください。'
                ],
                422
            );
        }

        if(
            !write_json_file(
                json_file_path('app_settings.json'),
                $settings
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'kintone設定を保存できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'message'=>'kintone設定を保存しました。'
            ]
        );
    }

    if ($action === 'test_kintone') {

        $k=
            is_array($request['kintone']??null)
                ?$request['kintone']
                :($settings['kintone']??[]);

        $url=kintone_build_url(
            (string)($k['domain']??''),
            '/k/v1/apps.json?limit=1'
        );

        $result=kintone_api_request(
            'GET',
            $url,
            build_kintone_headers($k),
            null,
            $k
        );

        if(!$result['success']){
            api_response(
                [
                    'success'=>false,
                    'message'=>$result['message']
                ],
                422
            );
        }

        api_response(
            [
                'success'=>true,
                'message'=>'kintoneへの接続を確認しました。'
            ]
        );
    }

    if ($action === 'fetch_kintone_fields') {

        $k=$settings['kintone']??[];

        $appId=trim(
            (string)($k['app_id']??'')
        );

        if($appId===''){
            api_response(
                [
                    'success'=>false,
                    'message'=>'kintone設定を先に保存してください。'
                ],
                422
            );
        }

        $query=http_build_query(
            ['app'=>$appId],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $url=
            kintone_build_url(
                (string)$k['domain'],
                '/k/v1/app/form/fields.json'
            )
            .'?'.$query;

        $result=kintone_api_request(
            'GET',
            $url,
            build_kintone_headers($k),
            null,
            $k
        );

        if(!$result['success']){
            api_response(
                [
                    'success'=>false,
                    'message'=>$result['message']
                ],
                422
            );
        }

        $properties=
            $result['data']['properties']??[];

        $fields=[];

        foreach($properties as $code=>$field){

            $fields[]=[
                'code'=>(string)$code,
                'label'=>(string)(
                    $field['label']??$code
                ),
                'type'=>(string)(
                    $field['type']??''
                )
            ];
        }

        $settings['kintone_fields']=$fields;

        write_json_file(
            json_file_path('app_settings.json'),
            $settings
        );

        api_response(
            [
                'success'=>true,
                'fields'=>$fields,
                'message'=>'kintoneのフィールド定義を取得しました。'
            ]
        );
    }

    if ($action === 'save_mapping') {

        $mapping=
            is_array($request['mapping']??null)
                ?$request['mapping']
                :[];

        $address=
            is_array($mapping['address']??null)
                ?array_values(
                    array_filter(
                        array_map(
                            'strval',
                            $mapping['address']
                        )
                    )
                )
                :[];

        $settings['mapping']=[
            'name'=>trim(
                (string)($mapping['name']??'')
            ),
            'organization'=>trim(
                (string)($mapping['organization']??'')
            ),
            'department'=>trim(
                (string)($mapping['department']??'')
            ),
            'email'=>trim(
                (string)($mapping['email']??'')
            ),
            'phone'=>trim(
                (string)($mapping['phone']??'')
            ),
            'address'=>$address
        ];

        if(
            !write_json_file(
                json_file_path('app_settings.json'),
                $settings
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'マッピングを保存できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'message'=>'マッピングを保存しました。'
            ]
        );
    }

    if ($action === 'sync_kintone') {

        $k=$settings['kintone']??[];
        $mapping=$settings['mapping']??[];

        $appId=trim(
            (string)($k['app_id']??'')
        );

        if($appId===''){
            api_response(
                [
                    'success'=>false,
                    'message'=>'kintone設定を保存してください。'
                ],
                422
            );
        }

        $params=[
            'app'=>$appId,
            'totalCount'=>'true'
        ];

        $params['query']='order by $id asc';

        $url=
            kintone_build_url(
                (string)$k['domain'],
                '/k/v1/records.json'
            )
            .'?'
            .http_build_query(
                $params,
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        $result=kintone_api_request(
            'GET',
            $url,
            build_kintone_headers($k),
            null,
            $k
        );

        if(!$result['success']){
            api_response(
                [
                    'success'=>false,
                    'message'=>$result['message']
                ],
                422
            );
        }

        $records=
            $result['data']['records']??[];

        $byEmail=[];

        foreach($customers as $c){
            $byEmail[
                strtolower(
                    (string)$c['email']
                )
            ]=$c;
        }

        foreach($records as $record){

            $get=function(string $code)use($record):string{

                $v=$record[$code]['value']??'';

                if(is_array($v)){
                    return implode(
                        ' ',
                        array_map(
                            'strval',
                            $v
                        )
                    );
                }

                return (string)$v;
            };

            $email=$get(
                (string)($mapping['email']??'')
            );

            if(
                $email==='' ||
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ){
                continue;
            }

            $customer=
                $byEmail[strtolower($email)]
                ??
                [
                    'id'=>make_id('customer'),
                    'createdAt'=>date('Y-m-d')
                ];

            $customer['email']=$email;

            $customer['name']=$get(
                (string)($mapping['name']??'')
            );

            $customer['organization']=$get(
                (string)($mapping['organization']??'')
            );

            $customer['department']=$get(
                (string)($mapping['department']??'')
            );

            $customer['phone']=$get(
                (string)($mapping['phone']??'')
            );

            $parts=[];

            foreach(
                ($mapping['address']??[])
                as $code
            ){
                $v=$get((string)$code);

                if($v!==''){
                    $parts[]=$v;
                }
            }

            $customer['address']=
                implode(' ',$parts);

            $customer['active']=true;

            $customers=upsert_by_id(
                $customers,
                $customer
            );

            $byEmail[strtolower($email)]=$customer;
        }

        if(
            !write_json_file(
                json_file_path('customers.json'),
                $customers
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'顧客データを保存できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'count'=>count($records),
                'customers'=>$customers,
                'message'=>'kintoneから顧客情報を同期しました。'
            ]
        );
    }

    if ($action === 'save_respondent') {

        $token=trim(
            (string)($request['token']??'')
        );

        $found=false;

        foreach($responses as $i=>$r){

            if(($r['token']??'')===$token){

                if(($r['status']??'')==='answered'){
                    api_response(
                        [
                            'success'=>false,
                            'message'=>'このURLはすでに回答済みです。'
                        ],
                        422
                    );
                }

                $responses[$i]['organization']=
                    trim(
                        (string)($request['organization']??'')
                    );

                $responses[$i]['department']=
                    trim(
                        (string)($request['department']??'')
                    );

                $email=trim(
                    (string)($request['email']??'')
                );

                if(
                    !filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ){
                    api_response(
                        [
                            'success'=>false,
                            'message'=>'メールアドレスを確認してください。'
                        ],
                        422
                    );
                }

                $responses[$i]['email']=$email;
                $responses[$i]['status']='info_entered';
                $found=true;

                break;
            }
        }

        if(!$found){
            api_response(
                [
                    'success'=>false,
                    'message'=>'回答URLが見つかりません。'
                ],
                404
            );
        }

        if(
            !write_json_file(
                json_file_path('responses.json'),
                $responses
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'回答者情報を保存できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'message'=>'回答者情報を保存しました。'
            ]
        );
    }

    if ($action === 'submit_response') {

        $token=trim(
            (string)($request['token']??'')
        );

        $answers=
            is_array($request['answers']??null)
                ?$request['answers']
                :[];

        $found=false;

        foreach($responses as $i=>$r){

            if(($r['token']??'')===$token){

                if(($r['status']??'')==='answered'){
                    api_response(
                        [
                            'success'=>false,
                            'message'=>'このURLはすでに回答済みです。'
                        ],
                        422
                    );
                }

                $survey=find_survey(
                    $surveys,
                    (string)$r['surveyId']
                );

                if($survey===null){
                    api_response(
                        [
                            'success'=>false,
                            'message'=>'アンケートが見つかりません。'
                        ],
                        404
                    );
                }

                $errors=[];

                foreach(
                    $survey['groups']??[]
                    as $g
                ){
                    foreach(
                        $g['questions']??[]
                        as $q
                    ){

                        if(
                            ($q['required']??false) &&
                            (
                                ($answers[$q['id']]??'')==='' ||
                                $answers[$q['id']]===[]
                            )
                        ){
                            $errors[]=
                                $q['text']
                                .'を入力してください。';
                        }
                    }
                }

                if($errors){
                    api_response(
                        [
                            'success'=>false,
                            'message'=>implode(
                                ' ',
                                $errors
                            )
                        ],
                        422
                    );
                }

                $responses[$i]['answers']=$answers;
                $responses[$i]['status']='answered';
                $responses[$i]['answerAt']=now_string();

                $found=true;

                break;
            }
        }

        if(!$found){
            api_response(
                [
                    'success'=>false,
                    'message'=>'回答URLが見つかりません。'
                ],
                404
            );
        }

        if(
            !write_json_file(
                json_file_path('responses.json'),
                $responses
            )
        ){
            api_response(
                [
                    'success'=>false,
                    'message'=>'回答を保存できませんでした。'
                ],
                500
            );
        }

        api_response(
            [
                'success'=>true,
                'message'=>'回答を保存しました。'
            ]
        );
    }

    api_response(
        [
            'success'=>false,
            'message'=>'指定された操作はありません。'
        ],
        400
    );
}

$settingsJson=json_encode(
    $settings,
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
);

$surveysJson=json_encode(
    $surveys,
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
);

$customersJson=json_encode(
    $customers,
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
);

$responsesJson=json_encode(
    $responses,
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
);

$csrfJson=json_encode($csrfToken);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート運営</title>
<style>
*{
    box-sizing:border-box
}

body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Meiryo",sans-serif;
    background:#f5f7fa;
    color:#263238;
    font-size:14px
}

button,
input,
select,
textarea{
    font:inherit
}

button{
    cursor:pointer
}

.topbar{
    height:58px;
    background:#243447;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 22px;
    position:sticky;
    top:0;
    z-index:50
}

.brand{
    font-weight:700;
    font-size:16px;
    margin-right:28px;
    white-space:nowrap
}

.nav{
    display:flex;
    height:100%;
    gap:2px
}

.nav button{
    height:100%;
    padding:0 18px;
    border:0;
    background:transparent;
    color:#cbd5df;
    border-bottom:3px solid transparent
}

.nav button:hover,
.nav button.active{
    color:#fff;
    border-bottom-color:#4aa3df
}

.page{
    display:none;
    max-width:1280px;
    margin:0 auto;
    padding:24px
}

.page.active{
    display:block
}

.page-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:18px
}

.page-head h1{
    font-size:21px;
    margin:0
}

.subtle{
    color:#74808c;
    font-size:12px
}

.btn{
    border:0;
    border-radius:5px;
    background:#2f80c0;
    color:#fff;
    padding:9px 15px
}

.btn.secondary{
    background:#e9edf1;
    color:#263238
}

.btn.danger{
    background:#c94b4b
}

.btn.small{
    padding:6px 10px;
    font-size:12px
}

.btn:disabled{
    opacity:.55;
    cursor:not-allowed
}

.loading:after{
    content:"";
    display:inline-block;
    width:12px;
    height:12px;
    margin-left:8px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
    vertical-align:-2px
}

@keyframes spin{
    to{transform:rotate(360deg)}
}

.card{
    background:#fff;
    border:1px solid #e2e7ec;
    border-radius:7px;
    padding:18px;
    margin-bottom:16px
}

.table-wrap{
    background:#fff;
    border:1px solid #e2e7ec;
    border-radius:7px;
    overflow:auto
}

.table{
    width:100%;
    border-collapse:collapse
}

.table th,
.table td{
    padding:11px 13px;
    border-bottom:1px solid #edf0f2;
    text-align:left;
    font-size:13px;
    vertical-align:middle
}

.table th{
    background:#f7f9fb;
    color:#56616d;
    font-weight:600;
    white-space:nowrap
}

.table tr:last-child td{
    border-bottom:0
}

.link{
    border:0;
    background:none;
    color:#2676ad;
    padding:0;
    text-decoration:underline;
    cursor:pointer
}

.badge{
    display:inline-block;
    padding:3px 9px;
    border-radius:12px;
    font-size:11px
}

.badge.draft{
    background:#eef1f3;
    color:#59636d
}

.badge.published{
    background:#e4f5eb;
    color:#227344
}

.badge.closed{
    background:#eceff1;
    color:#59636d
}

.badge.answer{
    background:#e8f1fb;
    color:#2d638f
}

.detail-head{
    background:#fff;
    border:1px solid #e2e7ec;
    border-radius:7px;
    padding:18px;
    margin-bottom:12px
}

.detail-title{
    display:flex;
    justify-content:space-between;
    gap:20px
}

.detail-title h1{
    font-size:21px;
    margin:0 0 7px
}

.detail-actions{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
    justify-content:flex-end
}

.tabs{
    display:flex;
    background:#fff;
    border:1px solid #e2e7ec;
    border-radius:7px 7px 0 0;
    overflow:auto
}

.tabs button{
    border:0;
    background:#fff;
    padding:12px 17px;
    color:#64717d;
    border-bottom:3px solid transparent;
    white-space:nowrap
}

.tabs button.active{
    color:#2676ad;
    border-bottom-color:#2676ad;
    font-weight:700
}

.tab-panel{
    display:none;
    background:#fff;
    border:1px solid #e2e7ec;
    border-top:0;
    border-radius:0 0 7px 7px;
    padding:18px
}

.tab-panel.active{
    display:block
}

.grid2{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px
}

.grid3{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px
}

.field{
    margin-bottom:13px
}

.field label{
    display:block;
    font-size:12px;
    color:#5e6974;
    margin-bottom:5px
}

.field input,
.field select,
.field textarea{
    width:100%;
    border:1px solid #cfd7df;
    border-radius:5px;
    padding:8px;
    background:#fff
}

.field textarea{
    resize:vertical
}

.section-title{
    font-size:15px;
    margin:0 0 12px
}

.form-actions,
.row-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    flex-wrap:wrap;
    margin-top:15px
}

.question{
    border:1px solid #dde4ea;
    border-radius:6px;
    padding:14px;
    margin-bottom:10px;
    background:#fbfcfd
}

.question-head{
    display:flex;
    justify-content:space-between;
    gap:10px
}

.qtext{
    font-weight:600
}

.choices{
    margin-top:9px;
    color:#687580;
    font-size:12px
}

.choice{
    padding:3px 0
}

.stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
    margin-bottom:16px
}

.stat{
    background:#fff;
    border:1px solid #e2e7ec;
    border-radius:7px;
    padding:15px
}

.stat .n{
    font-size:24px;
    font-weight:700
}

.stat .l{
    font-size:12px;
    color:#74808c;
    margin-top:4px
}

.recipient-box{
    border:1px solid #dce4ea;
    border-radius:6px;
    max-height:250px;
    overflow:auto
}

.recipient{
    display:flex;
    align-items:center;
    gap:9px;
    padding:9px 11px;
    border-bottom:1px solid #edf0f2
}

.recipient:last-child{
    border-bottom:0
}

.progress{
    height:10px;
    background:#e8edf1;
    border-radius:5px;
    overflow:hidden
}

.progress>span{
    display:block;
    height:100%;
    background:#4aa3df
}

.bar{
    display:grid;
    grid-template-columns:160px 1fr 90px;
    align-items:center;
    gap:10px;
    margin:9px 0;
    font-size:12px
}

.notice{
    position:fixed;
    right:18px;
    bottom:18px;
    width:390px;
    max-width:calc(100vw - 36px);
    z-index:200;
    display:none
}

.notice.show{
    display:block
}

.notice-box{
    background:#fff;
    border:1px solid #cfd7df;
    border-radius:7px;
    padding:13px 15px;
    box-shadow:0 5px 20px rgba(0,0,0,.12);
    position:relative
}

.notice.error .notice-box{
    border:2px solid #d64c4c;
    color:#3b2b2b
}

.notice.success .notice-box{
    border:2px solid #3b9b62
}

.notice-title{
    font-weight:700;
    margin-bottom:5px
}

.notice-close{
    position:absolute;
    right:9px;
    top:7px;
    border:0;
    background:none;
    font-size:18px;
    color:#6f7880
}

.modal{
    position:fixed;
    inset:0;
    background:rgba(20,28,36,.45);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:100
}

.modal.show{
    display:flex
}

.modal-box{
    width:460px;
    max-width:92vw;
    background:#fff;
    border-radius:8px;
    padding:20px
}

.modal-box h2{
    font-size:17px;
    margin:0 0 10px
}

.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:18px
}

.error-list{
    background:#fff2f2;
    border:2px solid #d64c4c;
    color:#3b2b2b;
    border-radius:6px;
    padding:11px 14px;
    margin-bottom:14px;
    display:none
}

.error-list.show{
    display:block
}

.url-box{
    background:#f7f9fb;
    border:1px solid #dce3e8;
    border-radius:6px;
    padding:12px;
    margin-top:12px;
    word-break:break-all
}

.settings-nav{
    display:flex;
    gap:5px;
    margin-bottom:12px
}

.settings-nav button{
    border:1px solid #d9e0e6;
    background:#fff;
    border-radius:5px;
    padding:8px 13px;
    color:#5c6872
}

.settings-nav button.active{
    background:#edf6fc;
    color:#23698f;
    border-color:#b8d8eb
}

.settings-panel{
    display:none
}

.settings-panel.active{
    display:block
}

.kstep{
    display:flex;
    gap:7px;
    margin-bottom:16px;
    flex-wrap:wrap
}

.kstep span{
    padding:6px 10px;
    border-radius:12px;
    background:#eef1f3;
    color:#66727b;
    font-size:11px
}

.kstep span.current{
    background:#dceefa;
    color:#24698f;
    font-weight:700
}

.empty{
    padding:30px;
    text-align:center;
    color:#7a858e
}

@media(max-width:800px){

    .topbar{
        padding:0 10px
    }

    .brand{
        margin-right:10px
    }

    .nav button{
        padding:0 10px
    }

    .page{
        padding:15px
    }

    .grid2,
    .grid3,
    .stats{
        grid-template-columns:1fr
    }

    .detail-title{
        flex-direction:column
    }

    .detail-actions{
        justify-content:flex-start
    }

    .bar{
        grid-template-columns:90px 1fr 60px
    }
}
</style>
</head>

<body>

<header class="topbar">

<div class="brand">
📋 アンケート運営
</div>

<nav class="nav">

<button
type="button"
class="active"
data-page="surveys">
アンケート
</button>

<button
type="button"
data-page="customers">
顧客
</button>

<button
type="button"
data-page="settings">
設定
</button>

</nav>

</header>

<main>

<section
class="page active"
id="page-surveys">

<div class="page-head">

<div>
<h1>アンケート</h1>
<div class="subtle">
作成から配信・回答状況・結果まで、アンケート単位で管理します。
</div>
</div>

<button
type="button"
class="btn"
id="new-survey">
＋ 新規アンケート
</button>

</div>

<div
id="survey-error"
class="error-list">
</div>

<div class="table-wrap">

<table class="table">

<thead>
<tr>
<th>アンケート名</th>
<th>状態</th>
<th>公開期間</th>
<th>回答</th>
<th>更新日時</th>
<th>操作</th>
</tr>
</thead>

<tbody id="survey-list">
</tbody>

</table>

</div>

</section>

<section
class="page"
id="page-editor">

<div class="page-head">

<div>

<button
type="button"
class="link"
id="editor-back">
← アンケート一覧
</button>

<h1
id="editor-title"
style="margin-top:8px">
アンケート作成
</h1>

<div class="subtle">
基本情報と質問をここで編集します。
</div>

</div>

</div>

<div
id="editor-error"
class="error-list">
</div>

<div class="card">

<div class="grid2">

<div class="field">
<label>アンケート名（必須）</label>
<input id="survey-name">
</div>

<div class="field">
<label>質問番号</label>

<select id="numbering">
<option value="Q1, Q2, Q3">Q1, Q2, Q3</option>
<option value="Q1-1, Q1-2">Q1-1, Q1-2</option>
</select>

</div>

<div class="field">
<label>開始日</label>
<input id="start-at" type="date">
</div>

<div class="field">
<label>終了日</label>
<input id="end-at" type="date">
</div>

</div>

<div class="field">

<label>説明文</label>

<textarea
id="survey-description"
rows="3">
</textarea>

</div>

</div>

<div id="editor-groups">
</div>

<div class="row-actions">

<button
type="button"
class="btn secondary"
id="add-group">
＋ グループを追加
</button>

<button
type="button"
class="btn"
id="save-survey">
保存する
</button>

</div>

</section>

<section
class="page"
id="page-detail">

<div class="detail-head">

<div class="detail-title">

<div>

<button
type="button"
class="link"
id="detail-back">
← アンケート一覧
</button>

<h1
id="detail-name"
style="margin-top:8px">
</h1>

<div
class="subtle"
id="detail-meta">
</div>

</div>

<div class="detail-actions">

<button
type="button"
class="btn secondary"
id="edit-survey">
編集
</button>

<button
type="button"
class="btn"
id="publish-survey">
公開する
</button>

<button
type="button"
class="btn danger"
id="close-survey">
終了する
</button>

<button
type="button"
class="btn danger"
id="delete-survey">
削除する
</button>

</div>

</div>

</div>

<div
class="tabs"
id="detail-tabs">

<button
type="button"
data-tab="overview"
class="active">
概要
</button>

<button
type="button"
data-tab="questions">
質問
</button>

<button
type="button"
data-tab="send">
回答依頼
</button>

<button
type="button"
data-tab="status">
回答状況
</button>

<button
type="button"
data-tab="results">
回答結果・集計
</button>

</div>

<div
class="tab-panel active"
id="panel-overview">

<div
class="stats"
id="overview-stats">
</div>

<div class="card">

<h2 class="section-title">
基本情報
</h2>

<div id="overview-info">
</div>

</div>

</div>

<div
class="tab-panel"
id="panel-questions">

<div class="row-actions">

<button
type="button"
class="btn"
id="edit-questions">
質問を編集する
</button>

</div>

<div id="question-preview">
</div>

</div>

<div
class="tab-panel"
id="panel-send">

<div class="card">

<h2 class="section-title">
通常回答者への回答依頼
</h2>

<div class="field">

<label>検索</label>

<input
id="recipient-search"
placeholder="氏名・会社名・メールアドレス">

</div>

<div
class="recipient-box"
id="recipient-list">
</div>

<div
class="subtle"
id="selected-count"
style="margin-top:8px">
0名を選択中
</div>

<div class="row-actions">

<button
type="button"
class="btn"
id="send-selected">
選択した回答者へ送信する
</button>

</div>

</div>

<div class="card">

<h2 class="section-title">
個別回答URL発行
</h2>

<div class="grid3">

<div class="field">
<label>組織名</label>
<input id="individual-org">
</div>

<div class="field">
<label>部署名</label>
<input id="individual-dept">
</div>

<div class="field">
<label>メールアドレス</label>
<input
id="individual-email"
type="email">
</div>

</div>

<button
type="button"
class="btn"
id="issue-url">
個別回答URLを発行する
</button>

<div
id="issued-url"
class="url-box"
style="display:none">
</div>

</div>

</div>

<div
class="tab-panel"
id="panel-status">

<div
id="status-stats"
class="stats">
</div>

<div class="table-wrap">

<table class="table">

<thead>
<tr>
<th>回答者</th>
<th>所属</th>
<th>メール</th>
<th>送信状態</th>
<th>回答状態</th>
<th>送信日時</th>
<th>回答日時</th>
<th>エラー</th>
</tr>
</thead>

<tbody id="status-list">
</tbody>

</table>

</div>

</div>

<div
class="tab-panel"
id="panel-results">

<div
id="result-stats"
class="stats">
</div>

<div id="result-content">
</div>

</div>

</section>

<section
class="page"
id="page-customers">

<div class="page-head">

<div>

<h1>顧客</h1>

<div class="subtle">
顧客情報を管理します。回答依頼はアンケート側から開始します。
</div>

</div>

<button
type="button"
class="btn secondary"
id="sync-customers">
kintoneから最新情報を取得
</button>

</div>

<div class="card">

<div class="field">

<label>検索</label>

<input
id="customer-search"
placeholder="氏名・会社名・メールアドレス">

</div>

</div>

<div class="table-wrap">

<table class="table">

<thead>

<tr>
<th>顧客名</th>
<th>会社名</th>
<th>部署</th>
<th>メール</th>
<th>電話</th>
<th>住所</th>
<th>登録日</th>
</tr>

</thead>

<tbody id="customer-list">
</tbody>

</table>

</div>

</section>

<section
class="page"
id="page-settings">

<div class="page-head">

<div>

<h1>設定</h1>

<div class="subtle">
外部連携の設定をここに集約します。
</div>

</div>

</div>

<div class="settings-nav">

<button
type="button"
class="active"
data-setting="smtp">
SMTP
</button>

<button
type="button"
data-setting="kintone">
kintone
</button>

</div>

<div
class="settings-panel active"
id="setting-smtp">

<div class="card">

<h2 class="section-title">
SMTP設定
</h2>

<div class="grid2">

<div class="field">
<label>ホスト</label>
<input id="smtp-host">
</div>

<div class="field">
<label>ポート</label>
<input
id="smtp-port"
type="number"
min="1"
max="65535">
</div>

<div class="field">
<label>暗号化</label>

<select id="smtp-encryption">
<option value="none">なし</option>
<option value="ssl">SSL</option>
<option value="tls">TLS</option>
</select>

</div>

<div class="field">
<label>ユーザー名</label>
<input id="smtp-username">
</div>

<div class="field">
<label>パスワード</label>

<input
id="smtp-password"
type="password"
autocomplete="new-password">

</div>

<div class="field">
<label>送信元メール</label>

<input
id="smtp-from-email"
type="email">

</div>

</div>

<div class="field">

<label>送信元表示名</label>
<input id="smtp-from-name">

</div>

<div class="row-actions">

<button
type="button"
class="btn secondary"
id="smtp-test">
接続確認
</button>

<button
type="button"
class="btn"
id="smtp-save">
保存する
</button>

</div>

</div>

</div>

<div
class="settings-panel"
id="setting-kintone">

<div class="card">

<h2 class="section-title">
kintone設定
</h2>

<div class="grid2">

<div class="field">
<label>サブドメイン</label>
<input
id="kt-domain"
placeholder="example または https://example.cybozu.com">
</div>

<div class="field">
<label>対象アプリID</label>
<input id="kt-app-id">
</div>

<div class="field">
<label>ログイン名</label>
<input id="kt-login">
</div>

<div class="field">
<label>パスワード</label>

<input
id="kt-password"
type="password"
autocomplete="new-password">

</div>

<div class="field">
<label>プロキシ host:port（任意）</label>

<input
id="kt-proxy"
placeholder="proxy.example.com:8080">

</div>

</div>

<div class="kstep">

<span
class="current"
id="ks1">
1 接続設定
</span>

<span id="ks2">
2 フィールド取得
</span>

<span id="ks3">
3 マッピング
</span>

<span id="ks4">
4 プレビュー
</span>

<span id="ks5">
5 同期
</span>

</div>

<div
id="kt-error"
class="error-list">
</div>

<div class="row-actions">

<button
type="button"
class="btn secondary"
id="kt-test">
接続確認
</button>

<button
type="button"
class="btn"
id="kt-save">
設定を保存
</button>

<button
type="button"
class="btn secondary"
id="kt-fields">
フィールド取得
</button>

</div>

<div
id="kt-mapping-area"
style="display:none;margin-top:16px">

<h3 class="section-title">
フィールドマッピング
</h3>

<div class="grid2">

<div class="field">
<label>顧客名</label>
<select id="map-name"></select>
</div>

<div class="field">
<label>会社名</label>
<select id="map-org"></select>
</div>

<div class="field">
<label>部署名</label>
<select id="map-dept"></select>
</div>

<div class="field">
<label>メールアドレス</label>
<select id="map-email"></select>
</div>

<div class="field">
<label>電話番号</label>
<select id="map-phone"></select>
</div>

<div class="field">

<label>
住所（複数項目を指定可能）
</label>

<select
id="map-address"
multiple
size="5">
</select>

</div>

</div>

<div class="row-actions">

<button
type="button"
class="btn secondary"
id="kt-map-save">
マッピングを保存
</button>

<button
type="button"
class="btn secondary"
id="kt-preview">
プレビュー
</button>

<button
type="button"
class="btn"
id="kt-sync">
同期する
</button>

</div>

<div
id="kt-preview-box"
class="card"
style="display:none;margin-top:15px">
</div>

</div>

</div>

</div>

</section>

</main>

<div
class="notice"
id="notice">

<div class="notice-box">

<button
type="button"
class="notice-close"
id="notice-close">
×
</button>

<div
class="notice-title"
id="notice-title">
</div>

<div id="notice-text">
</div>

</div>

</div>

<div
class="modal"
id="modal">

<div class="modal-box">

<h2 id="modal-title">
確認
</h2>

<p
id="modal-text"
class="subtle">
</p>

<div class="modal-actions">

<button
type="button"
class="btn secondary"
id="modal-cancel">
キャンセル
</button>

<button
type="button"
class="btn"
id="modal-ok">
実行する
</button>

</div>

</div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function(){

const csrf=<?= $csrfJson ?>;

let surveys=<?= $surveysJson ?>||[];
let customers=<?= $customersJson ?>||[];
let responses=<?= $responsesJson ?>||[];
let settings=<?= $settingsJson ?>||{};

let currentSurveyId='';
let editorSurvey=null;
let noticeTimer=null;
let modalAction=null;

const $=function(id){
    return document.getElementById(id);
};

function showPage(name){

    document
        .querySelectorAll('.page')
        .forEach(function(p){
            p.classList.remove('active');
        });

    const page=$('page-'+name);

    if(page){
        page.classList.add('active');
    }

    document
        .querySelectorAll('.nav button')
        .forEach(function(b){

            b.classList.toggle(
                'active',
                b.dataset.page===name ||
                (
                    (name==='detail'||name==='editor') &&
                    b.dataset.page==='surveys'
                )
            );
        });
}

function notice(type,title,text){

    const n=$('notice');
    const t=$('notice-title');
    const x=$('notice-text');

    if(!n||!t||!x){
        return;
    }

    n.className='notice show '+type;
    t.textContent=title;
    x.textContent=text;

    if(noticeTimer){
        clearTimeout(noticeTimer);
    }

    noticeTimer=setTimeout(function(){
        n.classList.remove('show');
    },7000);
}

function showError(id,text){

    const e=$(id);

    if(!e){
        return;
    }

    e.textContent=text;
    e.classList.toggle('show',!!text);
}

async function post(action,payload,button){

    if(button){
        button.disabled=true;
        button.classList.add('loading');
    }

    try{

        const res=await fetch(
            location.pathname,
            {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'X-CSRF-Token':csrf
                },
                body:JSON.stringify(
                    Object.assign(
                        {action:action},
                        payload||{}
                    )
                )
            }
        );

        const data=await res.json();

        if(!res.ok||!data.success){
            throw new Error(
                data.message||
                '処理に失敗しました。'
            );
        }

        return data;

    }catch(e){

        notice(
            'error',
            '処理できませんでした',
            e.message
        );

        throw e;

    }finally{

        if(button){
            button.disabled=false;
            button.classList.remove('loading');
        }
    }
}

function confirmAction(title,text,fn){

    const m=$('modal');
    const mt=$('modal-title');
    const mx=$('modal-text');
    const ok=$('modal-ok');

    if(!m||!mt||!mx||!ok){
        return;
    }

    modalAction=fn;

    mt.textContent=title;
    mx.textContent=text;

    m.classList.add('show');
}

function statusLabel(s){

    return s==='published'
        ?'公開中'
        :s==='closed'
            ?'終了'
            :'下書き';
}

function statusClass(s){

    return s==='published'
        ?'published'
        :s==='closed'
            ?'closed'
            :'draft';
}

function surveyById(id){

    return surveys.find(function(s){
        return String(s.id)===String(id);
    })||null;
}

function responseForSurvey(id){

    return responses.filter(function(r){
        return String(r.surveyId)===String(id);
    });
}

function renderSurveyList(){

    const root=$('survey-list');

    if(!root){
        return;
    }

    root.textContent='';

    if(!surveys.length){

        const tr=document.createElement('tr');
        const td=document.createElement('td');

        td.colSpan=6;
        td.className='empty';
        td.textContent='アンケートはありません。';

        tr.appendChild(td);
        root.appendChild(tr);

        return;
    }

    surveys.forEach(function(s){

        const tr=document.createElement('tr');

        const tdName=document.createElement('td');

        const open=document.createElement('button');

        open.type='button';
        open.className='link';
        open.dataset.openSurvey=s.id;
        open.textContent=s.name;

        tdName.appendChild(open);

        const tdStatus=document.createElement('td');

        const badge=document.createElement('span');

        badge.className=
            'badge '+
            statusClass(s.status);

        badge.textContent=statusLabel(s.status);

        tdStatus.appendChild(badge);

        const rs=responseForSurvey(s.id);

        const answered=
            rs.filter(function(r){
                return r.status==='answered';
            }).length;

        const tdPeriod=document.createElement('td');

        tdPeriod.textContent=
            (s.startAt||'未設定')+
            ' ～ '+
            (s.endAt||'未設定');

        const tdCount=document.createElement('td');

        tdCount.textContent=
            answered+
            ' / '+
            rs.length;

        const tdUpdated=document.createElement('td');

        tdUpdated.textContent=
            s.updatedAt||'';

        const tdAct=document.createElement('td');

        const b=document.createElement('button');

        b.type='button';
        b.className='link';
        b.dataset.openSurvey=s.id;
        b.textContent='開く';

        tdAct.appendChild(b);

        [
            tdName,
            tdStatus,
            tdPeriod,
            tdCount,
            tdUpdated,
            tdAct
        ].forEach(function(td){
            tr.appendChild(td);
        });

        root.appendChild(tr);
    });
}

function setDetailTab(tab){

    document
        .querySelectorAll('#detail-tabs button')
        .forEach(function(b){

            b.classList.toggle(
                'active',
                b.dataset.tab===tab
            );
        });

    document
        .querySelectorAll('#page-detail .tab-panel')
        .forEach(function(p){

            p.classList.toggle(
                'active',
                p.id==='panel-'+tab
            );
        });

    if(tab==='status'){
        renderStatus();
    }

    if(tab==='results'){
        renderResults();
    }
}

function openDetail(id,tab){

    const s=surveyById(id);

    if(!s){
        return;
    }

    currentSurveyId=s.id;

    const name=$('detail-name');
    const meta=$('detail-meta');

    if(name){
        name.textContent=s.name;
    }

    if(meta){

        meta.textContent=
            statusLabel(s.status)+
            ' ・ '+
            (s.startAt||'期間未設定')+
            ' ～ '+
            (s.endAt||'期間未設定');
    }

    const publish=$('publish-survey');
    const close=$('close-survey');
    const del=$('delete-survey');

    if(publish){
        publish.style.display=
            s.status==='draft'
                ?'inline-block'
                :'none';
    }

    if(close){
        close.style.display=
            s.status==='published'
                ?'inline-block'
                :'none';
    }

    if(del){

        del.style.display=
            s.status==='closed'||
            s.status==='draft'
                ?'inline-block'
                :'none';
    }

    renderOverview();
    renderQuestions();
    renderRecipients();

    showPage('detail');

    setDetailTab(tab||'overview');
}

function renderOverview(){

    const s=surveyById(currentSurveyId);

    if(!s){
        return;
    }

    const rs=responseForSurvey(s.id);

    const answered=
        rs.filter(function(r){
            return r.status==='answered';
        }).length;

    const sent=
        rs.filter(function(r){
            return r.sendStatus==='成功';
        }).length;

    const failed=
        rs.filter(function(r){
            return r.sendStatus==='失敗';
        }).length;

    const stats=$('overview-stats');

    if(stats){

        stats.textContent='';

        [
            ['送信対象',rs.length],
            ['送信成功',sent],
            ['回答済み',answered],
            ['送信エラー',failed]
        ].forEach(function(x){

            const d=document.createElement('div');
            d.className='stat';

            const n=document.createElement('div');
            n.className='n';
            n.textContent=x[1];

            const l=document.createElement('div');
            l.className='l';
            l.textContent=x[0];

            d.appendChild(n);
            d.appendChild(l);

            stats.appendChild(d);
        });
    }

    const info=$('overview-info');

    if(info){

        info.textContent='';

        [
            ['状態',statusLabel(s.status)],
            ['説明',s.description||''],
            [
                '公開期間',
                (s.startAt||'未設定')+
                ' ～ '+
                (s.endAt||'未設定')
            ],
            ['更新日時',s.updatedAt||'']
        ].forEach(function(x){

            const p=document.createElement('p');
            const b=document.createElement('b');

            b.textContent=x[0]+'：';

            p.appendChild(b);
            p.appendChild(
                document.createTextNode(x[1])
            );

            info.appendChild(p);
        });
    }
}

function renderQuestions(){

    const s=surveyById(currentSurveyId);
    const root=$('question-preview');

    if(!s||!root){
        return;
    }

    root.textContent='';

    (s.groups||[]).forEach(function(g,gi){

        const card=document.createElement('div');
        card.className='card';

        const h=document.createElement('h2');
        h.className='section-title';

        h.textContent=
            'グループ '+
            (gi+1)+
            '：'+
            g.name;

        card.appendChild(h);

        (g.questions||[]).forEach(function(q,qi){

            const qbox=document.createElement('div');
            qbox.className='question';

            const head=document.createElement('div');
            head.className='question-head';

            const qt=document.createElement('div');
            qt.className='qtext';

            qt.textContent=
                'Q'+
                (qi+1)+
                '：'+
                q.text;

            const type=document.createElement('span');
            type.className='subtle';

            type.textContent=
                (
                    q.type==='single'
                        ?'単一選択'
                        :q.type==='multiple'
                            ?'複数選択'
                            :'自由記述'
                )+
                (
                    q.required
                        ?'・必須'
                        :''
                );

            head.appendChild(qt);
            head.appendChild(type);

            qbox.appendChild(head);

            if(q.choices&&q.choices.length){

                const choices=
                    document.createElement('div');

                choices.className='choices';

                q.choices.forEach(function(c){

                    const p=
                        document.createElement('div');

                    p.className='choice';
                    p.textContent='・'+c;

                    choices.appendChild(p);
                });

                qbox.appendChild(choices);
            }

            card.appendChild(qbox);
        });

        root.appendChild(card);
    });
}

function renderRecipients(){

    const root=$('recipient-list');

    if(!root){
        return;
    }

    root.textContent='';

    customers
        .filter(function(c){
            return c.active!==false;
        })
        .forEach(function(c){

            const label=
                document.createElement('label');

            label.className='recipient';

            const cb=
                document.createElement('input');

            cb.type='checkbox';
            cb.dataset.customerId=c.id;

            const text=
                document.createElement('span');

            text.textContent=
                c.name+
                ' / '+
                c.organization+
                ' / '+
                c.email;

            label.appendChild(cb);
            label.appendChild(text);

            root.appendChild(label);
        });
}

function renderStatus(){

    const list=$('status-list');
    const stats=$('status-stats');

    if(!list||!stats){
        return;
    }

    const rs=responseForSurvey(
        currentSurveyId
    );

    list.textContent='';
    stats.textContent='';

    const sent=
        rs.filter(function(r){
            return r.sendStatus==='成功';
        }).length;

    const failed=
        rs.filter(function(r){
            return r.sendStatus==='失敗';
        }).length;

    const answered=
        rs.filter(function(r){
            return r.status==='answered';
        }).length;

    [
        ['送信対象',rs.length],
        ['送信成功',sent],
        ['送信エラー',failed],
        ['回答済み',answered]
    ].forEach(function(x){

        const d=document.createElement('div');
        d.className='stat';

        const n=document.createElement('div');
        n.className='n';
        n.textContent=x[1];

        const l=document.createElement('div');
        l.className='l';
        l.textContent=x[0];

        d.appendChild(n);
        d.appendChild(l);

        stats.appendChild(d);
    });

    if(!rs.length){

        const tr=document.createElement('tr');
        const td=document.createElement('td');

        td.colSpan=8;
        td.className='empty';
        td.textContent='まだ回答依頼はありません。';

        tr.appendChild(td);
        list.appendChild(tr);

        return;
    }

    rs.forEach(function(r){

        const tr=document.createElement('tr');

        const vals=[
            r.organization||'—',
            r.department||'—',
            r.email||'—',
            r.sendStatus||'未送信',
            r.status==='answered'
                ?'回答済み'
                :r.status==='info_entered'
                    ?'回答者情報入力済み'
                    :'未使用',
            r.sendAt||'—',
            r.answerAt||'—',
            r.error||'—'
        ];

        vals.forEach(function(v){

            const td=document.createElement('td');

            td.textContent=v;

            tr.appendChild(td);
        });

        list.appendChild(tr);
    });
}

function renderResults(){

    const s=surveyById(currentSurveyId);
    const root=$('result-content');
    const stats=$('result-stats');

    if(!s||!root||!stats){
        return;
    }

    const rs=responseForSurvey(s.id);

    const answered=
        rs.filter(function(r){
            return r.status==='answered';
        });

    stats.textContent='';

    [
        ['総回答',answered.length],
        [
            '回答率',
            rs.length
                ?Math.round(
                    answered.length/
                    rs.length*
                    100
                )+'%'
                :'0%'
        ],
        [
            '未回答',
            rs.filter(function(r){
                return r.status!=='answered';
            }).length
        ],
        ['回答依頼',rs.length]
    ].forEach(function(x){

        const d=document.createElement('div');
        d.className='stat';

        const n=document.createElement('div');
        n.className='n';
        n.textContent=x[1];

        const l=document.createElement('div');
        l.className='l';
        l.textContent=x[0];

        d.appendChild(n);
        d.appendChild(l);

        stats.appendChild(d);
    });

    root.textContent='';

    (s.groups||[]).forEach(function(g){

        (g.questions||[]).forEach(function(q){

            const card=document.createElement('div');
            card.className='card';

            const h=document.createElement('h2');
            h.className='section-title';
            h.textContent=q.text;

            card.appendChild(h);

            if(q.type==='text'){

                const table=
                    document.createElement('table');

                table.className='table';

                const thead=
                    document.createElement('thead');

                const trh=
                    document.createElement('tr');

                [
                    '回答者',
                    '回答内容'
                ].forEach(function(v){

                    const th=
                        document.createElement('th');

                    th.textContent=v;
                    trh.appendChild(th);
                });

                thead.appendChild(trh);
                table.appendChild(thead);

                const tb=
                    document.createElement('tbody');

                answered.forEach(function(r){

                    const tr=
                        document.createElement('tr');

                    const td1=
                        document.createElement('td');

                    const td2=
                        document.createElement('td');

                    td1.textContent=
                        r.organization+
                        ' '+
                        r.email;

                    td2.textContent=
                        String(
                            r.answers&&
                            r.answers[q.id]||
                            ''
                        );

                    tr.appendChild(td1);
                    tr.appendChild(td2);

                    tb.appendChild(tr);
                });

                table.appendChild(tb);
                card.appendChild(table);

            }else{

                const counts={};

                (q.choices||[]).forEach(function(c){
                    counts[c]=0;
                });

                answered.forEach(function(r){

                    const a=
                        r.answers&&
                        r.answers[q.id];

                    const arr=
                        q.type==='multiple'
                            ?(
                                Array.isArray(a)
                                    ?a
                                    :[]
                            )
                            :[a];

                    arr.forEach(function(v){

                        if(
                            Object.prototype.hasOwnProperty.call(
                                counts,
                                v
                            )
                        ){
                            counts[v]++;
                        }
                    });
                });

                Object.keys(counts).forEach(function(c){

                    const row=
                        document.createElement('div');

                    row.className='bar';

                    const label=
                        document.createElement('span');

                    label.textContent=c;

                    const prog=
                        document.createElement('div');

                    prog.className='progress';

                    const fill=
                        document.createElement('span');

                    fill.style.width=
                        (
                            answered.length
                                ?Math.round(
                                    counts[c]/
                                    answered.length*
                                    100
                                )
                                :0
                        )+
                        '%';

                    prog.appendChild(fill);

                    const val=
                        document.createElement('span');

                    val.textContent=
                        counts[c]+'件';

                    row.appendChild(label);
                    row.appendChild(prog);
                    row.appendChild(val);

                    card.appendChild(row);
                });
            }

            root.appendChild(card);
        });
    });
}

function populateEditor(s){

    editorSurvey=
        JSON.parse(
            JSON.stringify(
                s||
                {
                    id:'',
                    name:'',
                    description:'',
                    status:'draft',
                    startAt:'',
                    endAt:'',
                    numberingFormat:'Q1, Q2, Q3',
                    groups:[
                        {
                            id:'g_'+Date.now(),
                            name:'質問グループ',
                            questions:[]
                        }
                    ],
                    createdAt:''
                }
            )
        );

    $('survey-name').value=
        editorSurvey.name||'';

    $('survey-description').value=
        editorSurvey.description||'';

    $('start-at').value=
        editorSurvey.startAt||'';

    $('end-at').value=
        editorSurvey.endAt||'';

    $('numbering').value=
        editorSurvey.numberingFormat||
        'Q1, Q2, Q3';

    renderEditorGroups();
}

function renderEditorGroups(){

    const root=$('editor-groups');

    if(!root||!editorSurvey){
        return;
    }

    root.textContent='';

    (editorSurvey.groups||[]).forEach(
        function(g,gi){

            const card=
                document.createElement('div');

            card.className='card';

            const top=
                document.createElement('div');

            top.className='page-head';

            const h=
                document.createElement('h2');

            h.className='section-title';
            h.textContent='グループ '+(gi+1);

            top.appendChild(h);

            const del=
                document.createElement('button');

            del.type='button';
            del.className=
                'btn secondary small';

            del.textContent='グループ削除';
            del.dataset.deleteGroup=gi;

            top.appendChild(del);
            card.appendChild(top);

            const gf=
                document.createElement('div');

            gf.className='field';

            const gl=
                document.createElement('label');

            gl.textContent='グループ名';

            const giInput=
                document.createElement('input');

            giInput.value=g.name||'';
            giInput.dataset.groupName=gi;

            gf.appendChild(gl);
            gf.appendChild(giInput);

            card.appendChild(gf);

            (g.questions||[]).forEach(
                function(q,qi){

                    const box=
                        document.createElement('div');

                    box.className='question';

                    const head=
                        document.createElement('div');

                    head.className='question-head';

                    const title=
                        document.createElement('b');

                    title.textContent='Q'+(qi+1);

                    head.appendChild(title);

                    const actions=
                        document.createElement('div');

                    const delq=
                        document.createElement('button');

                    delq.type='button';
                    delq.className=
                        'btn secondary small';

                    delq.textContent='削除';
                    delq.dataset.deleteQuestion=
                        gi+':'+qi;

                    actions.appendChild(delq);
                    head.appendChild(actions);

                    box.appendChild(head);

                    const textField=
                        document.createElement('div');

                    textField.className='field';

                    const textLabel=
                        document.createElement('label');

                    textLabel.textContent='質問文';

                    const textInput=
                        document.createElement('input');

                    textInput.value=q.text||'';
                    textInput.dataset.qText=
                        gi+':'+qi;

                    textField.appendChild(textLabel);
                    textField.appendChild(textInput);

                    box.appendChild(textField);

                    const typeField=
                        document.createElement('div');

                    typeField.className='field';

                    const typeLabel=
                        document.createElement('label');

                    typeLabel.textContent='回答形式';

                    const typeSelect=
                        document.createElement('select');

                    [
                        ['text','自由記述'],
                        ['single','単一選択'],
                        ['multiple','複数選択']
                    ].forEach(function(o){

                        const op=
                            document.createElement('option');

                        op.value=o[0];
                        op.textContent=o[1];

                        if(q.type===o[0]){
                            op.selected=true;
                        }

                        typeSelect.appendChild(op);
                    });

                    typeSelect.dataset.qType=
                        gi+':'+qi;

                    typeField.appendChild(typeLabel);
                    typeField.appendChild(typeSelect);

                    box.appendChild(typeField);

                    const req=
                        document.createElement('label');

                    const reqcb=
                        document.createElement('input');

                    reqcb.type='checkbox';
                    reqcb.checked=!!q.required;
                    reqcb.dataset.qRequired=
                        gi+':'+qi;

                    req.appendChild(reqcb);
                    req.appendChild(
                        document.createTextNode(
                            ' 必須回答'
                        )
                    );

                    box.appendChild(req);

                    if(q.type!=='text'){

                        const choices=
                            document.createElement('div');

                        choices.className='field';

                        const lab=
                            document.createElement('label');

                        lab.textContent=
                            '選択肢（1行1項目）';

                        const ta=
                            document.createElement('textarea');

                        ta.rows=4;
                        ta.value=
                            (q.choices||[]).join('\n');

                        ta.dataset.qChoices=
                            gi+':'+qi;

                        choices.appendChild(lab);
                        choices.appendChild(ta);

                        box.appendChild(choices);
                    }

                    card.appendChild(box);
                }
            );

            const add=
                document.createElement('button');

            add.type='button';
            add.className='btn secondary';
            add.textContent='＋ 質問を追加';
            add.dataset.addQuestion=gi;

            card.appendChild(add);

            root.appendChild(card);
        }
    );
}

function syncEditorFromDom(){

    if(!editorSurvey){
        return;
    }

    editorSurvey.name=
        $('survey-name').value.trim();

    editorSurvey.description=
        $('survey-description').value.trim();

    editorSurvey.startAt=
        $('start-at').value;

    editorSurvey.endAt=
        $('end-at').value;

    editorSurvey.numberingFormat=
        $('numbering').value;

    document
        .querySelectorAll('[data-group-name]')
        .forEach(function(e){

            const i=Number(
                e.dataset.groupName
            );

            if(editorSurvey.groups[i]){
                editorSurvey.groups[i].name=
                    e.value.trim();
            }
        });

    document
        .querySelectorAll('[data-qText]')
        .forEach(function(e){

            const p=
                e.dataset.qText
                .split(':')
                .map(Number);

            if(
                editorSurvey.groups[p[0]] &&
                editorSurvey.groups[p[0]].questions[p[1]]
            ){
                editorSurvey.groups[p[0]]
                    .questions[p[1]]
                    .text=e.value.trim();
            }
        });

    document
        .querySelectorAll('[data-qType]')
        .forEach(function(e){

            const p=
                e.dataset.qType
                .split(':')
                .map(Number);

            if(
                editorSurvey.groups[p[0]] &&
                editorSurvey.groups[p[0]].questions[p[1]]
            ){

                editorSurvey.groups[p[0]]
                    .questions[p[1]]
                    .type=e.value;

                if(e.value==='text'){
                    editorSurvey.groups[p[0]]
                        .questions[p[1]]
                        .choices=[];
                }
            }
        });

    document
        .querySelectorAll('[data-qRequired]')
        .forEach(function(e){

            const p=
                e.dataset.qRequired
                .split(':')
                .map(Number);

            if(
                editorSurvey.groups[p[0]] &&
                editorSurvey.groups[p[0]].questions[p[1]]
            ){
                editorSurvey.groups[p[0]]
                    .questions[p[1]]
                    .required=e.checked;
            }
        });

    document
        .querySelectorAll('[data-qChoices]')
        .forEach(function(e){

            const p=
                e.dataset.qChoices
                .split(':')
                .map(Number);

            if(
                editorSurvey.groups[p[0]] &&
                editorSurvey.groups[p[0]].questions[p[1]]
            ){

                editorSurvey.groups[p[0]]
                    .questions[p[1]]
                    .choices=
                    e.value
                        .split(/\r?\n/)
                        .map(function(v){
                            return v.trim();
                        })
                        .filter(Boolean);
            }
        });
}

function refreshSettingsForm(){

    const s=settings||{};
    const smtp=s.smtp||{};

    $('smtp-host').value=
        smtp.host||'';

    $('smtp-port').value=
        smtp.port||587;

    $('smtp-encryption').value=
        smtp.encryption||'tls';

    $('smtp-username').value=
        smtp.username||'';

    $('smtp-password').value=
        smtp.password||'';

    $('smtp-from-email').value=
        smtp.from_email||'';

    $('smtp-from-name').value=
        smtp.from_name||
        'アンケート事務局';

    const k=s.kintone||{};

    $('kt-domain').value=
        k.domain||'';

    $('kt-app-id').value=
        k.app_id||'';

    $('kt-login').value=
        k.login||'';

    $('kt-password').value=
        k.password||'';

    $('kt-proxy').value=
        k.proxy_host_port||'';
}

function getSmtp(){

    return {
        host:$('smtp-host').value.trim(),
        port:Number(
            $('smtp-port').value
        ),
        encryption:$('smtp-encryption').value,
        username:$('smtp-username').value.trim(),
        password:$('smtp-password').value,
        from_email:$('smtp-from-email').value.trim(),
        from_name:$('smtp-from-name').value.trim()
    };
}

function getKintone(){

    return {
        domain:$('kt-domain').value.trim(),
        app_id:$('kt-app-id').value.trim(),
        login:$('kt-login').value.trim(),
        password:$('kt-password').value,
        proxy_host_port:$('kt-proxy').value.trim()
    };
}

function renderCustomers(){

    const root=$('customer-list');

    if(!root){
        return;
    }

    const q=
        (
            $('customer-search').value||
            ''
        ).toLowerCase();

    root.textContent='';

    customers
        .filter(function(c){

            return (
                c.name+
                ' '+
                c.organization+
                ' '+
                c.email
            )
            .toLowerCase()
            .includes(q);

        })
        .forEach(function(c){

            const tr=
                document.createElement('tr');

            [
                c.name,
                c.organization,
                c.department,
                c.email,
                c.phone,
                c.address,
                c.createdAt
            ].forEach(function(v){

                const td=
                    document.createElement('td');

                td.textContent=v||'—';

                tr.appendChild(td);
            });

            root.appendChild(tr);
        });
}

function populateMapping(){

    const fields=
        settings.kintone_fields||[];

    [
        'map-name',
        'map-org',
        'map-dept',
        'map-email',
        'map-phone'
    ].forEach(function(id){

        const select=$(id);

        if(!select){
            return;
        }

        select.textContent='';

        const blank=
            document.createElement('option');

        blank.value='';
        blank.textContent='未設定';

        select.appendChild(blank);

        fields.forEach(function(f){

            const op=
                document.createElement('option');

            op.value=f.code;

            op.textContent=
                f.label+
                ' ['+
                f.code+
                ']';

            select.appendChild(op);
        });
    });

    const addr=$('map-address');

    if(addr){

        addr.textContent='';

        fields.forEach(function(f){

            const op=
                document.createElement('option');

            op.value=f.code;

            op.textContent=
                f.label+
                ' ['+
                f.code+
                ']';

            addr.appendChild(op);
        });
    }

    const m=settings.mapping||{};

    if($('map-name')){
        $('map-name').value=
            m.name||'';
    }

    if($('map-org')){
        $('map-org').value=
            m.organization||'';
    }

    if($('map-dept')){
        $('map-dept').value=
            m.department||'';
    }

    if($('map-email')){
        $('map-email').value=
            m.email||'';
    }

    if($('map-phone')){
        $('map-phone').value=
            m.phone||'';
    }

    if(addr){

        (m.address||[]).forEach(function(v){

            const op=
                Array.from(
                    addr.options
                ).find(function(o){
                    return o.value===v;
                });

            if(op){
                op.selected=true;
            }
        });
    }
}

function updateKSteps(n){

    for(let i=1;i<=5;i++){

        const e=$('ks'+i);

        if(e){
            e.classList.toggle(
                'current',
                i===n
            );
        }
    }
}

document
    .querySelectorAll('.nav button')
    .forEach(function(btn){

        if(!btn){
            return;
        }

        btn.addEventListener(
            'click',
            function(){

                showPage(btn.dataset.page);

                if(btn.dataset.page==='customers'){
                    renderCustomers();
                }

                if(btn.dataset.page==='settings'){
                    refreshSettingsForm();
                }
            }
        );
    });

const newSurvey=$('new-survey');

if(newSurvey){

    newSurvey.addEventListener(
        'click',
        function(){

            populateEditor(null);

            $('editor-title').textContent=
                'アンケート作成';

            showPage('editor');
        }
    );
}

const surveyList=$('survey-list');

if(surveyList){

    surveyList.addEventListener(
        'click',
        function(e){

            const b=
                e.target.closest(
                    '[data-open-survey]'
                );

            if(b){
                openDetail(
                    b.dataset.openSurvey,
                    'overview'
                );
            }
        }
    );
}

const editorBack=$('editor-back');

if(editorBack){

    editorBack.addEventListener(
        'click',
        function(){
            showPage('surveys');
        }
    );
}

const detailBack=$('detail-back');

if(detailBack){

    detailBack.addEventListener(
        'click',
        function(){
            showPage('surveys');
        }
    );
}

const editSurvey=$('edit-survey');

if(editSurvey){

    editSurvey.addEventListener(
        'click',
        function(){

            const s=
                surveyById(currentSurveyId);

            if(!s){
                return;
            }

            populateEditor(s);

            $('editor-title').textContent=
                'アンケート編集';

            showPage('editor');
        }
    );
}

const editQuestions=$('edit-questions');

if(editQuestions){

    editQuestions.addEventListener(
        'click',
        function(){

            const s=
                surveyById(currentSurveyId);

            if(!s){
                return;
            }

            populateEditor(s);

            $('editor-title').textContent=
                '質問を編集';

            showPage('editor');
        }
    );
}

const detailTabs=$('detail-tabs');

if(detailTabs){

    detailTabs.addEventListener(
        'click',
        function(e){

            const b=
                e.target.closest(
                    '[data-tab]'
                );

            if(b){
                setDetailTab(
                    b.dataset.tab
                );
            }
        }
    );
}

const addGroup=$('add-group');

if(addGroup){

    addGroup.addEventListener(
        'click',
        function(){

            syncEditorFromDom();

            editorSurvey.groups.push(
                {
                    id:
                        'g_'+
                        Date.now()+
                        '_'+
                        Math.random()
                            .toString(16)
                            .slice(2),
                    name:'新しいグループ',
                    questions:[]
                }
            );

            renderEditorGroups();
        }
    );
}

const editorGroups=$('editor-groups');

if(editorGroups){

    editorGroups.addEventListener(
        'click',
        function(e){

            const dg=
                e.target.closest(
                    '[data-delete-group]'
                );

            if(dg){

                syncEditorFromDom();

                editorSurvey.groups.splice(
                    Number(dg.dataset.deleteGroup),
                    1
                );

                if(!editorSurvey.groups.length){

                    editorSurvey.groups.push(
                        {
                            id:'g_'+Date.now(),
                            name:'質問グループ',
                            questions:[]
                        }
                    );
                }

                renderEditorGroups();

                return;
            }

            const dq=
                e.target.closest(
                    '[data-delete-question]'
                );

            if(dq){

                syncEditorFromDom();

                const p=
                    dq.dataset.deleteQuestion
                        .split(':')
                        .map(Number);

                if(editorSurvey.groups[p[0]]){

                    editorSurvey.groups[p[0]]
                        .questions.splice(
                            p[1],
                            1
                        );
                }

                renderEditorGroups();

                return;
            }

            const aq=
                e.target.closest(
                    '[data-add-question]'
                );

            if(aq){

                syncEditorFromDom();

                const i=
                    Number(
                        aq.dataset.addQuestion
                    );

                editorSurvey.groups[i]
                    .questions.push(
                        {
                            id:
                                'q_'+
                                Date.now()+
                                '_'+
                                Math.random()
                                    .toString(16)
                                    .slice(2),
                            type:'text',
                            text:'新しい質問',
                            required:false,
                            choices:[],
                            branch:null
                        }
                    );

                renderEditorGroups();
            }
        }
    );
}

const saveSurvey=$('save-survey');

if(saveSurvey){

    saveSurvey.addEventListener(
        'click',
        async function(){

            syncEditorFromDom();

            showError(
                'editor-error',
                ''
            );

            try{

                const data=await post(
                    'save_survey',
                    {
                        survey:editorSurvey
                    },
                    saveSurvey
                );

                surveys=surveys.map(
                    function(s){
                        return String(s.id)===
                            String(data.survey.id)
                            ?data.survey
                            :s;
                    }
                );

                if(
                    !surveys.some(
                        function(s){
                            return String(s.id)===
                                String(data.survey.id);
                        }
                    )
                ){
                    surveys.push(data.survey);
                }

                renderSurveyList();

                showPage('surveys');

                notice(
                    'success',
                    '保存しました',
                    'アンケートを保存しました。'
                );

            }catch(e){

                showError(
                    'editor-error',
                    e.message
                );
            }
        }
    );
}

const publish=$('publish-survey');

if(publish){

    publish.addEventListener(
        'click',
        async function(){

            try{

                const data=await post(
                    'publish_survey',
                    {
                        id:currentSurveyId
                    },
                    publish
                );

                surveys=surveys.map(
                    function(s){
                        return String(s.id)===
                            String(data.survey.id)
                            ?data.survey
                            :s;
                    }
                );

                openDetail(
                    currentSurveyId,
                    'overview'
                );

                renderSurveyList();

                notice(
                    'success',
                    '公開しました',
                    'アンケートを公開しました。'
                );

            }catch(e){}
        }
    );
}

const close=$('close-survey');

if(close){

    close.addEventListener(
        'click',
        function(){

            confirmAction(
                'アンケートを終了しますか？',
                '終了すると新しい回答は受け付けません。',
                async function(){

                    try{

                        const data=await post(
                            'close_survey',
                            {
                                id:currentSurveyId
                            },
                            close
                        );

                        surveys=surveys.map(
                            function(s){
                                return String(s.id)===
                                    String(data.survey.id)
                                    ?data.survey
                                    :s;
                            }
                        );

                        renderSurveyList();

                        openDetail(
                            currentSurveyId,
                            'overview'
                        );

                        notice(
                            'success',
                            '終了しました',
                            'アンケートを終了しました。'
                        );

                    }catch(e){}
                }
            );
        }
    );
}

const del=$('delete-survey');

if(del){

    del.addEventListener(
        'click',
        function(){

            confirmAction(
                'アンケートを削除しますか？',
                '削除すると一覧から利用できなくなります。',
                async function(){

                    try{

                        await post(
                            'delete_survey',
                            {
                                id:currentSurveyId
                            },
                            del
                        );

                        surveys=surveys.filter(
                            function(s){
                                return String(s.id)!==
                                    String(currentSurveyId);
                            }
                        );

                        renderSurveyList();

                        showPage('surveys');

                        notice(
                            'success',
                            '削除しました',
                            'アンケートを削除しました。'
                        );

                    }catch(e){}
                }
            );
        }
    );
}

const recipientList=$('recipient-list');

if(recipientList){

    recipientList.addEventListener(
        'change',
        function(){

            const n=
                recipientList
                    .querySelectorAll(
                        'input:checked'
                    )
                    .length;

            const c=$('selected-count');

            if(c){
                c.textContent=
                    n+
                    '名を選択中';
            }
        }
    );
}

const recipientSearch=$('recipient-search');

if(recipientSearch){

    recipientSearch.addEventListener(
        'input',
        function(){

            const q=
                recipientSearch.value
                    .toLowerCase();

            document
                .querySelectorAll(
                    '#recipient-list .recipient'
                )
                .forEach(function(r){

                    r.style.display=
                        r.textContent
                            .toLowerCase()
                            .includes(q)
                            ?'flex'
                            :'none';
                });
        }
    );
}

const sendSelected=$('send-selected');

if(sendSelected){

    sendSelected.addEventListener(
        'click',
        async function(){

            const ids=
                Array.from(
                    document.querySelectorAll(
                        '#recipient-list input:checked'
                    )
                ).map(function(x){
                    return x.dataset.customerId;
                });

            if(!ids.length){

                notice(
                    'error',
                    '送信できません',
                    '回答者を選択してください。'
                );

                return;
            }

            try{

                const data=await post(
                    'send_invitations',
                    {
                        surveyId:currentSurveyId,
                        customerIds:ids,
                        individuals:[]
                    },
                    sendSelected
                );

                responses=
                    Array.isArray(data.responses)
                        ?responses.concat(data.responses)
                        :responses;

                notice(
                    data.failed
                        ?'error'
                        :'success',
                    '回答依頼を処理しました',
                    data.message
                );

                renderOverview();
                renderStatus();

            }catch(e){}
        }
    );
}

const issueUrl=$('issue-url');

if(issueUrl){

    issueUrl.addEventListener(
        'click',
        async function(){

            const email=
                $('individual-email')
                    .value
                    .trim();

            if(!email){

                notice(
                    'error',
                    '発行できません',
                    'メールアドレスを入力してください。'
                );

                return;
            }

            try{

                const data=await post(
                    'create_token',
                    {
                        surveyId:currentSurveyId
                    },
                    issueUrl
                );

                const existing=
                    responses.find(
                        function(r){
                            return r.token===
                                data.response.token;
                        }
                    );

                if(!existing){
                    responses.push(
                        data.response
                    );
                }

                const box=$('issued-url');

                if(box){

                    box.style.display='block';
                    box.textContent=data.url;
                }

                notice(
                    'success',
                    '個別回答URLを発行しました',
                    '回答者情報入力前のURLを発行しました。URLを本人へ共有してください。'
                );

            }catch(e){}
        }
    );
}

const smtpSave=$('smtp-save');

if(smtpSave){

    smtpSave.addEventListener(
        'click',
        async function(){

            try{

                await post(
                    'save_smtp',
                    {
                        smtp:getSmtp()
                    },
                    smtpSave
                );

                settings.smtp=getSmtp();

                notice(
                    'success',
                    '保存しました',
                    'SMTP設定を保存しました。'
                );

            }catch(e){}
        }
    );
}

const smtpTest=$('smtp-test');

if(smtpTest){

    smtpTest.addEventListener(
        'click',
        async function(){

            try{

                await post(
                    'test_smtp',
                    {
                        smtp:getSmtp()
                    },
                    smtpTest
                );

                notice(
                    'success',
                    '接続確認成功',
                    'SMTPサーバへの接続と認証を確認しました。'
                );

            }catch(e){}
        }
    );
}

const customerSearch=$('customer-search');

if(customerSearch){

    customerSearch.addEventListener(
        'input',
        renderCustomers
    );
}

const syncCustomers=$('sync-customers');

if(syncCustomers){

    syncCustomers.addEventListener(
        'click',
        async function(){

            try{

                const data=await post(
                    'sync_kintone',
                    {},
                    syncCustomers
                );

                customers=
                    Array.isArray(data.customers)
                        ?data.customers
                        :customers;

                renderCustomers();

                notice(
                    'success',
                    '同期しました',
                    data.message
                );

            }catch(e){}
        }
    );
}

document
    .querySelectorAll('.settings-nav button')
    .forEach(function(btn){

        if(!btn){
            return;
        }

        btn.addEventListener(
            'click',
            function(){

                document
                    .querySelectorAll(
                        '.settings-nav button'
                    )
                    .forEach(function(b){
                        b.classList.remove('active');
                    });

                document
                    .querySelectorAll(
                        '.settings-panel'
                    )
                    .forEach(function(p){
                        p.classList.remove('active');
                    });

                btn.classList.add('active');

                const panel=
                    $('setting-'+
                        btn.dataset.setting);

                if(panel){
                    panel.classList.add('active');
                }

                if(
                    btn.dataset.setting===
                    'kintone'
                ){
                    populateMapping();
                }
            }
        );
    });

const ktSave=$('kt-save');

if(ktSave){

    ktSave.addEventListener(
        'click',
        async function(){

            try{

                const k=getKintone();

                await post(
                    'save_kintone',
                    {
                        kintone:k
                    },
                    ktSave
                );

                settings.kintone=k;

                notice(
                    'success',
                    '保存しました',
                    'kintone設定を保存しました。'
                );

            }catch(e){}
        }
    );
}

const ktTest=$('kt-test');

if(ktTest){

    ktTest.addEventListener(
        'click',
        async function(){

            try{

                const k=getKintone();

                await post(
                    'test_kintone',
                    {
                        kintone:k
                    },
                    ktTest
                );

                notice(
                    'success',
                    '接続確認成功',
                    'kintoneへの接続を確認しました。'
                );

            }catch(e){}
        }
    );
}

const ktFields=$('kt-fields');

if(ktFields){

    ktFields.addEventListener(
        'click',
        async function(){

            try{

                const data=await post(
                    'fetch_kintone_fields',
                    {},
                    ktFields
                );

                settings.kintone_fields=
                    data.fields||[];

                populateMapping();

                const area=
                    $('kt-mapping-area');

                if(area){
                    area.style.display='block';
                }

                updateKSteps(3);

                notice(
                    'success',
                    '取得しました',
                    'kintoneのフィールド定義を取得しました。'
                );

            }catch(e){}
        }
    );
}

const ktMapSave=$('kt-map-save');

if(ktMapSave){

    ktMapSave.addEventListener(
        'click',
        async function(){

            const mapping={
                name:$('map-name').value,
                organization:$('map-org').value,
                department:$('map-dept').value,
                email:$('map-email').value,
                phone:$('map-phone').value,
                address:
                    Array.from(
                        $('map-address')
                            .selectedOptions
                    ).map(function(o){
                        return o.value;
                    })
            };

            try{

                await post(
                    'save_mapping',
                    {
                        mapping:mapping
                    },
                    ktMapSave
                );

                settings.mapping=mapping;

                updateKSteps(4);

                notice(
                    'success',
                    '保存しました',
                    'フィールドマッピングを保存しました。'
                );

            }catch(e){}
        }
    );
}

const ktPreview=$('kt-preview');

if(ktPreview){

    ktPreview.addEventListener(
        'click',
        function(){

            const box=$('kt-preview-box');

            if(!box){
                return;
            }

            box.style.display='block';

            box.textContent=
                '保存済みのマッピングで同期対象を確認します。';

            updateKSteps(4);
        }
    );
}

const ktSync=$('kt-sync');

if(ktSync){

    ktSync.addEventListener(
        'click',
        async function(){

            try{

                const data=await post(
                    'sync_kintone',
                    {},
                    ktSync
                );

                updateKSteps(5);

                notice(
                    'success',
                    '同期しました',
                    data.message
                );

                renderCustomers();

            }catch(e){}
        }
    );
}

const noticeClose=$('notice-close');

if(noticeClose){

    noticeClose.addEventListener(
        'click',
        function(){

            const n=$('notice');

            if(n){
                n.classList.remove('show');
            }
        }
    );
}

const modalCancel=$('modal-cancel');

if(modalCancel){

    modalCancel.addEventListener(
        'click',
        function(){

            const m=$('modal');

            if(m){
                m.classList.remove('show');
            }

            modalAction=null;
        }
    );
}

const modalOk=$('modal-ok');

if(modalOk){

    modalOk.addEventListener(
        'click',
        async function(){

            const fn=modalAction;

            const m=$('modal');

            if(m){
                m.classList.remove('show');
            }

            modalAction=null;

            if(typeof fn==='function'){
                await fn();
            }
        }
    );
}

refreshSettingsForm();
renderSurveyList();
renderCustomers();

});
</script>

</body>
</html>
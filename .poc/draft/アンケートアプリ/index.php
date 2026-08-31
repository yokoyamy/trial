<?php
/**
 * アンケートアプリ POC - 単一ファイル index.php
 */

// セッションの基本設定（一時データ保持用）
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// -----------------------------------------------------------------------------
// 定数・パス定義
// -----------------------------------------------------------------------------
define('DATA_DIR', __DIR__ . '/.data');
define('SECRET_KEY_PATH', __DIR__ . '/.secrets/アンケートアプリ/secret.key');

// ディレクトリ初期作成
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
    file_put_contents(DATA_DIR . '/.htaccess', "Deny from all\n");
}
$secretDir = dirname(SECRET_KEY_PATH);
if (!is_dir($secretDir)) {
    @mkdir($secretDir, 0755, true);
    file_put_contents($secretDir . '/.htaccess', "Deny from all\n");
}

// -----------------------------------------------------------------------------
// 暗号化・秘密情報管理 (Sodium)
// -----------------------------------------------------------------------------
function getSecretKey(): ?string {
    if (!file_exists(SECRET_KEY_PATH)) {
        return null;
    }
    $key = file_get_contents(SECRET_KEY_PATH);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        return null;
    }
    return $key;
}

function encryptSecret(string $plainText): ?string {
    if ($plainText === '') return '';
    $key = getSecretKey();
    if (!$key) return null;
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($plainText, $nonce, $key);
    return 'ENC:v1:' . bin2hex($nonce) . ':' . bin2hex($ciphertext);
}

function decryptSecret(string $encrypted): ?string {
    if ($encrypted === '') return '';
    if (!str_starts_with($encrypted, 'ENC:v1:')) {
        return null;
    }
    $parts = explode(':', $encrypted);
    if (count($parts) !== 4) return null;
    $nonce = hex2bin($parts[2]);
    $ciphertext = hex2bin($parts[3]);
    $key = getSecretKey();
    if (!$key) return null;
    $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
    return $plain === false ? null : $plain;
}

// -----------------------------------------------------------------------------
// データ永続化 (ファイルベース JSON・排他制御)
// -----------------------------------------------------------------------------
function loadData(string $filename, array $default = []): array {
    $path = DATA_DIR . '/' . $filename;
    if (!file_exists($path)) {
        return $default;
    }
    $fp = fopen($path, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $json = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($json, true);
    return is_array($data) ? $data : $default;
}

function saveData(string $filename, array $data): bool {
    $path = DATA_DIR . '/' . $filename;
    $fp = fopen($path, 'c+');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
}

// -----------------------------------------------------------------------------
// ソケット通信ベース HTTPクライアント (cURL非依存)
// -----------------------------------------------------------------------------
function socketHttpRequest(string $url, string $method = 'GET', array $headers = [], string $body = '', int $timeout = 10, bool $sslVerify = true, ?string $proxy = null): array {
    $parts = parse_url($url);
    if (!$parts || !isset($parts['host'])) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => '無効なURLです'];
    }

    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'];
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    $targetHost = $host;
    $targetPort = $port;
    $useTls = ($scheme === 'https');

    $contextOptions = [
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify,
        ]
    ];

    $isProxy = false;
    if (!empty($proxy)) {
        $pParts = explode(':', $proxy);
        $targetHost = $pParts[0];
        $targetPort = isset($pParts[1]) ? (int)$pParts[1] : 80;
        $isProxy = true;
    }

    $transport = ($useTls && !$isProxy) ? 'tls://' : 'tcp://';
    $context = stream_context_create($contextOptions);
    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client("{$transport}{$targetHost}:{$targetPort}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => "接続失敗: {$errstr} ({$errno})"];
    }

    stream_set_timeout($fp, $timeout);

    // Proxy CONNECT (HTTPS Proxy)
    if ($isProxy && $useTls) {
        $connectReq = "CONNECT {$host}:{$port} HTTP/1.1\r\nHost: {$host}:{$port}\r\n\r\n";
        fwrite($fp, $connectReq);
        $proxyRes = fgets($fp, 4096);
        if (!str_contains($proxyRes, '200')) {
            fclose($fp);
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => "プロキシトンネル接続失敗: {$proxyRes}"];
        }
        while (($line = fgets($fp, 4096)) !== false && trim($line) !== '') {}
        // TLS ハンドシェイク
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => "プロキシTLSハンドシェイク失敗"];
        }
    }

    $reqPath = ($isProxy && !$useTls) ? $url : $path;
    $req = "{$method} {$reqPath} HTTP/1.1\r\n";
    $req .= "Host: {$host}\r\n";
    $req .= "Connection: close\r\n";
    $hasContentLength = false;
    foreach ($headers as $h) {
        $req .= $h . "\r\n";
        if (stripos($h, 'Content-Length:') === 0) $hasContentLength = true;
    }
    if ($body !== '' && !$hasContentLength) {
        $req .= "Content-Length: " . strlen($body) . "\r\n";
    }
    $req .= "\r\n";
    $req .= $body;

    fwrite($fp, $req);

    $response = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) break;
        $response .= $chunk;
    }
    $info = stream_get_meta_data($fp);
    fclose($fp);

    if ($info['timed_out']) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => '通信がタイムアウトしました'];
    }

    $parts = explode("\r\n\r\n", $response, 2);
    $headerStr = $parts[0] ?? '';
    $resBody = $parts[1] ?? '';

    $status = 0;
    $resHeaders = [];
    $headerLines = explode("\r\n", $headerStr);
    if (!empty($headerLines[0]) && preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headerLines[0], $m)) {
        $status = (int)$m[1];
    }
    foreach ($headerLines as $i => $line) {
        if ($i === 0) continue;
        $hParts = explode(':', $line, 2);
        if (count($hParts) === 2) {
            $resHeaders[strtolower(trim($hParts[0]))] = trim($hParts[1]);
        }
    }

    // Chunked transfer-encoding のデコード
    if (isset($resHeaders['transfer-encoding']) && strtolower($resHeaders['transfer-encoding']) === 'chunked') {
        $decoded = '';
        $bodyPos = 0;
        $bodyLen = strlen($resBody);
        while ($bodyPos < $bodyLen) {
            $nextClrf = strpos($resBody, "\r\n", $bodyPos);
            if ($nextClrf === false) break;
            $chunkSizeHex = trim(substr($resBody, $bodyPos, $nextClrf - $bodyPos));
            $chunkSize = hexdec($chunkSizeHex);
            if ($chunkSize === 0) break;
            $decoded .= substr($resBody, $nextClrf + 2, $chunkSize);
            $bodyPos = $nextClrf + 2 + $chunkSize + 2;
        }
        $resBody = $decoded;
    }

    return [
        'status' => $status,
        'headers' => $resHeaders,
        'body' => $resBody,
        'error' => null
    ];
}

// -----------------------------------------------------------------------------
// ソケットベース SMTP送信 (mail()非依存)
// -----------------------------------------------------------------------------
function sendSmtpMail(array $smtpConfig, string $to, string $subject, string $body, ?string $inputPassword = null): array {
    $host = $smtpConfig['host'] ?? '';
    $port = (int)($smtpConfig['port'] ?? 25);
    $encryption = $smtpConfig['encryption'] ?? 'none'; // 'ssl', 'tls', 'none'
    $useAuth = !empty($smtpConfig['auth']);
    $user = $smtpConfig['username'] ?? '';
    $pass = $inputPassword;
    if ($pass === null && !empty($smtpConfig['password_enc'])) {
        $pass = decryptSecret($smtpConfig['password_enc']);
    }

    $fromMail = $smtpConfig['from_email'] ?? 'noreply@example.com';
    $fromName = $smtpConfig['from_name'] ?? '';
    $replyTo = $smtpConfig['reply_to'] ?? '';

    if (empty($host) || $port <= 0) {
        return ['success' => false, 'error' => 'SMTPホストまたはポートが不正です'];
    }

    $transport = ($encryption === 'ssl') ? 'ssl://' : 'tcp://';
    $errno = 0;
    $errstr = '';
    $timeout = 10;
    $fp = @stream_socket_client("{$transport}{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return ['success' => false, 'error' => "SMTPサーバへ接続できません: {$errstr} ({$errno})"];
    }
    stream_set_timeout($fp, $timeout);

    $readResponse = function() use ($fp) {
        $res = '';
        while (!feof($fp)) {
            $line = fgets($fp, 512);
            if ($line === false) break;
            $res .= $line;
            if (preg_match('/^\d{3}\s/', $line)) break;
        }
        return $res;
    };

    $sendCommand = function($cmd, $expectedCode) use ($fp, $readResponse) {
        fwrite($fp, $cmd . "\r\n");
        $res = $readResponse();
        $code = (int)substr($res, 0, 3);
        if ($code !== $expectedCode && !in_array($code, (array)$expectedCode, true)) {
            return ['ok' => false, 'response' => $res, 'code' => $code];
        }
        return ['ok' => true, 'response' => $res, 'code' => $code];
    };

    $banner = $readResponse();
    if (substr($banner, 0, 3) !== '220') {
        fclose($fp);
        return ['success' => false, 'error' => 'SMTPバナー応答不正: ' . $banner];
    }

    $res = $sendCommand("EHLO " . gethostname(), [250]);
    if (!$res['ok']) {
        $res = $sendCommand("HELO " . gethostname(), 250);
        if (!$res['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'EHLO/HELO失敗: ' . $res['response']];
        }
    }

    // STARTTLS
    if ($encryption === 'tls') {
        $res = $sendCommand("STARTTLS", 220);
        if (!$res['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'STARTTLS失敗: ' . $res['response']];
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return ['success' => false, 'error' => 'STARTTLS暗号化ハンドシェイク失敗'];
        }
        $sendCommand("EHLO " . gethostname(), 250);
    }

    // 認証
    if ($useAuth) {
        $res = $sendCommand("AUTH LOGIN", 334);
        if (!$res['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'AUTH LOGIN開始失敗: ' . $res['response']];
        }
        $res = $sendCommand(base64_encode($user), 334);
        if (!$res['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'ユーザー名認証失敗: ' . $res['response']];
        }
        $res = $sendCommand(base64_encode((string)$pass), 235);
        if (!$res['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'パスワード認証失敗: ' . $res['response']];
        }
    }

    // 接続テスト（宛先が空）の場合はここまで
    if (empty($to)) {
        $sendCommand("QUIT", 221);
        fclose($fp);
        return ['success' => true, 'message' => 'SMTP接続および認証に成功しました'];
    }

    // メール送信
    $res = $sendCommand("MAIL FROM:<{$fromMail}>", 250);
    if (!$res['ok']) { fclose($fp); return ['success' => false, 'error' => 'MAIL FROM失敗: ' . $res['response']]; }

    $res = $sendCommand("RCPT TO:<{$to}>", [250, 251]);
    if (!$res['ok']) { fclose($fp); return ['success' => false, 'error' => 'RCPT TO失敗: ' . $res['response']]; }

    $res = $sendCommand("DATA", 354);
    if (!$res['ok']) { fclose($fp); return ['success' => false, 'error' => 'DATA開始失敗: ' . $res['response']]; }

    $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $fromHeader = $fromName ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromMail}>" : $fromMail;

    $headers = [
        "From: {$fromHeader}",
        "To: {$to}",
        "Subject: {$encodedSubject}",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
        "Date: " . date('r')
    ];
    if (!empty($replyTo)) {
        $headers[] = "Reply-To: {$replyTo}";
    }

    $msgData = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    $res = $sendCommand($msgData, 250);
    $sendCommand("QUIT", 221);
    fclose($fp);

    if (!$res['ok']) {
        return ['success' => false, 'error' => 'メッセージ送信失敗: ' . $res['response']];
    }
    return ['success' => true, 'message' => 'メールが送信されました'];
}

// -----------------------------------------------------------------------------
// kintone API 連携関数
// -----------------------------------------------------------------------------
function callKintoneApi(array $config, string $endpoint, string $method = 'GET', array $params = [], ?string $inputPassword = null): array {
    $subdomain = $config['subdomain'] ?? '';
    // サブドメインのノーマライズ
    if (preg_match('/^https?:\/\/([^.]+)\.cybozu\.com/', $subdomain, $m)) {
        $subdomain = $m[1];
    } elseif (preg_match('/^([^.]+)\.cybozu\.com/', $subdomain, $m)) {
        $subdomain = $m[1];
    }
    $subdomain = trim($subdomain, "/ \t\n\r\0\x0B");

    if (empty($subdomain)) {
        return ['status' => 0, 'data' => null, 'error' => 'サブドメインが設定されていません'];
    }

    $login = $config['login_name'] ?? '';
    $password = $inputPassword;
    if ($password === null && !empty($config['password_enc'])) {
        $password = decryptSecret($config['password_enc']);
    }

    if (empty($login) || $password === null) {
        return ['status' => 0, 'data' => null, 'error' => 'kintone認証情報（ログイン名・パスワード）が不足しています'];
    }

    $url = "https://{$subdomain}.cybozu.com{$endpoint}";
    $authHeader = 'X-Cybozu-Authorization: ' . base64_encode("{$login}:{$password}");
    $headers = [$authHeader];
    $body = '';

    if ($method === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
    } elseif ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($params);
    }

    $sslVerify = !isset($config['ssl_verify']) || (bool)$config['ssl_verify'];
    $proxy = !empty($config['proxy']) ? $config['proxy'] : null;

    $res = socketHttpRequest($url, $method, $headers, $body, 15, $sslVerify, $proxy);

    if ($res['error']) {
        return ['status' => 0, 'data' => null, 'error' => $res['error']];
    }

    // 302/303 リダイレクトは成功扱いしない
    if ($res['status'] === 302 || $res['status'] === 303) {
        return ['status' => $res['status'], 'data' => null, 'error' => '不正なリダイレクト応答を受信しました'];
    }

    $json = json_decode($res['body'], true);
    if ($res['status'] !== 200) {
        $errMsg = $json['message'] ?? ($json['code'] ?? "HTTPエラー ({$res['status']})");
        return ['status' => $res['status'], 'data' => null, 'error' => $errMsg];
    }

    return ['status' => 200, 'data' => $json, 'error' => null];
}

// -----------------------------------------------------------------------------
// 質問番号 再計算ロジック
// -----------------------------------------------------------------------------
function recalculateQuestionNumbers(array &$survey): void {
    $style = $survey['numbering_style'] ?? 'global';
    $gIdx = 1;
    $globalIdx = 1;
    foreach ($survey['groups'] as &$group) {
        $qIdxInGroup = 1;
        foreach ($group['questions'] as &$q) {
            if ($style === 'group') {
                $q['number_label'] = "Q{$gIdx}-{$qIdxInGroup}";
            } else {
                $q['number_label'] = "Q{$globalIdx}";
            }
            $qIdxInGroup++;
            $globalIdx++;
        }
        $gIdx++;
    }
}

// -----------------------------------------------------------------------------
// アンケート状態自動更新（公開中 -> 終了）
// -----------------------------------------------------------------------------
function updateSurveyStatuses(array &$surveys): bool {
    $changed = false;
    $now = date('Y-m-d\TH:i');
    foreach ($surveys as &$s) {
        if ($s['status'] === 'published' && !empty($s['end_date'])) {
            if ($now > $s['end_date']) {
                $s['status'] = 'finished';
                $changed = true;
            }
        }
    }
    return $changed;
}

// -----------------------------------------------------------------------------
// リクエストパラメータ取得 & 共通初期化
// -----------------------------------------------------------------------------
$screen = $_GET['screen'] ?? 'list';
$action = $_POST['action'] ?? '';
$surveyId = $_GET['id'] ?? ($_POST['survey_id'] ?? '');

$surveys = loadData('surveys.json', []);
if (updateSurveyStatuses($surveys)) {
    saveData('surveys.json', $surveys);
}
$customers = loadData('customers.json', []);
$smtpConfig = loadData('smtp.json', [
    'host' => '', 'port' => 587, 'encryption' => 'tls', 'auth' => 1,
    'username' => '', 'password_enc' => '', 'from_email' => '', 'from_name' => '', 'reply_to' => '', 'status' => '未設定'
]);
$kintoneConfig = loadData('kintone.json', [
    'subdomain' => '', 'app_id' => '', 'login_name' => '', 'password_enc' => '',
    'proxy' => '', 'ssl_verify' => 1, 'mappings' => []
]);
$sendLogs = loadData('send_logs.json', []);
$answers = loadData('answers.json', []);

$flashMessage = '';
$flashError = '';

// -----------------------------------------------------------------------------
// 管理者 POST アクション処理
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. kintone 設定関連
    if ($action === 'kintone_save') {
        $kintoneConfig['subdomain'] = trim($_POST['subdomain'] ?? '');
        $kintoneConfig['app_id'] = trim($_POST['app_id'] ?? '');
        $kintoneConfig['login_name'] = trim($_POST['login_name'] ?? '');
        $kintoneConfig['proxy'] = trim($_POST['proxy'] ?? '');
        $kintoneConfig['ssl_verify'] = isset($_POST['ssl_verify']) ? 1 : 0;
        if (!empty($_POST['password'])) {
            $kintoneConfig['password_enc'] = encryptSecret($_POST['password']);
        }
        if (isset($_POST['mappings']) && is_array($_POST['mappings'])) {
            $kintoneConfig['mappings'] = $_POST['mappings'];
        }
        saveData('kintone.json', $kintoneConfig);
        $flashMessage = 'kintone設定を保存しました。';
    } elseif ($action === 'kintone_test') {
        $pw = !empty($_POST['password']) ? $_POST['password'] : null;
        $appId = trim($_POST['app_id'] ?? $kintoneConfig['app_id']);
        $res = callKintoneApi($kintoneConfig, '/k/v1/app.json', 'GET', ['id' => $appId], $pw);
        if ($res['error']) {
            $flashError = '接続テスト失敗: ' . $res['error'];
        } else {
            $flashMessage = '接続テスト成功: アプリ「' . htmlspecialchars($res['data']['name'] ?? '') . '」を確認しました。';
        }
    } elseif ($action === 'kintone_get_fields') {
        $pw = !empty($_POST['password']) ? $_POST['password'] : null;
        $appId = trim($_POST['app_id'] ?? $kintoneConfig['app_id']);
        $res = callKintoneApi($kintoneConfig, '/k/v1/app/form/fields.json', 'GET', ['app' => $appId], $pw);
        if ($res['error']) {
            $flashError = '項目一覧取得失敗: ' . $res['error'];
        } else {
            $fields = array_keys($res['data']['properties'] ?? []);
            saveData('kintone_fields.json', $fields);
            $flashMessage = '項目一覧を再取得しました（' . count($fields) . '項目）。';
        }
    } elseif ($action === 'kintone_sync') {
        $pw = !empty($_POST['password']) ? $_POST['password'] : null;
        $appId = trim($_POST['app_id'] ?? $kintoneConfig['app_id']);
        $res = callKintoneApi($kintoneConfig, '/k/v1/records.json', 'GET', ['app' => $appId], $pw);
        if ($res['error']) {
            $flashError = '顧客同期失敗: ' . $res['error'];
        } else {
            $records = $res['data']['records'] ?? [];
            $newCustomers = [];
            $m = $kintoneConfig['mappings'] ?? [];
            foreach ($records as $r) {
                $cId = $r['$id']['value'] ?? uniqid('c_');
                $org = isset($m['org']) && isset($r[$m['org']]) ? $r[$m['org']]['value'] : '';
                $name = isset($m['name']) && isset($r[$m['name']]) ? $r[$m['name']]['value'] : '';
                $mail = isset($m['email']) && isset($r[$m['email']]) ? $r[$m['email']]['value'] : '';
                $dept = isset($m['dept']) && isset($r[$m['dept']]) ? $r[$m['dept']]['value'] : '';
                $tel = isset($m['tel']) && isset($r[$m['tel']]) ? $r[$m['tel']]['value'] : '';
                
                // 複数住所項目の結合
                $addrParts = [];
                if (!empty($m['address']) && is_array($m['address'])) {
                    foreach ($m['address'] as $af) {
                        if (isset($r[$af]['value'])) $addrParts[] = $r[$af]['value'];
                    }
                }
                $address = implode(' ', $addrParts);

                if (!empty($mail)) {
                    $newCustomers[] = [
                        'id' => $cId,
                        'org' => $org,
                        'name' => $name,
                        'email' => $mail,
                        'dept' => $dept,
                        'tel' => $tel,
                        'address' => $address
                    ];
                }
            }
            saveData('customers.json', $newCustomers);
            $customers = $newCustomers;
            $flashMessage = '顧客情報を同期しました（' . count($newCustomers) . '件）。';
        }
    }

    // 2. SMTP 設定関連
    elseif ($action === 'smtp_save') {
        $smtpConfig['host'] = trim($_POST['host'] ?? '');
        $smtpConfig['port'] = (int)($_POST['port'] ?? 587);
        $smtpConfig['encryption'] = $_POST['encryption'] ?? 'none';
        $smtpConfig['auth'] = isset($_POST['auth']) ? 1 : 0;
        $smtpConfig['username'] = trim($_POST['username'] ?? '');
        $smtpConfig['from_email'] = trim($_POST['from_email'] ?? '');
        $smtpConfig['from_name'] = trim($_POST['from_name'] ?? '');
        $smtpConfig['reply_to'] = trim($_POST['reply_to'] ?? '');
        if (!empty($_POST['password'])) {
            $smtpConfig['password_enc'] = encryptSecret($_POST['password']);
        }
        saveData('smtp.json', $smtpConfig);
        $flashMessage = 'SMTP設定を保存しました。';
    } elseif ($action === 'smtp_test') {
        $pw = !empty($_POST['password']) ? $_POST['password'] : null;
        $res = sendSmtpMail($smtpConfig, '', '', '', $pw);
        if ($res['success']) {
            $smtpConfig['status'] = '接続確認済み';
            $flashMessage = $res['message'];
        } else {
            $smtpConfig['status'] = '接続できません';
            $flashError = 'SMTP接続テスト失敗: ' . $res['error'];
        }
        saveData('smtp.json', $smtpConfig);
    } elseif ($action === 'smtp_test_mail') {
        $to = trim($_POST['test_email'] ?? '');
        $pw = !empty($_POST['password']) ? $_POST['password'] : null;
        if (empty($to)) {
            $flashError = 'テストメール送信先のメールアドレスを入力してください。';
        } else {
            $res = sendSmtpMail($smtpConfig, $to, '【テスト送信】アンケートアプリ', "これはSMTP接続のテストメールです。\n正常に届いています。", $pw);
            if ($res['success']) {
                $flashMessage = "テストメールを {$to} へ送信しました。";
            } else {
                $flashError = 'テストメール送信失敗: ' . $res['error'];
            }
        }
    }

    // 3. アンケート保存・編集
    elseif ($action === 'survey_save') {
        $sId = $_POST['survey_id'] ?? '';
        $isNew = empty($sId);
        if ($isNew) {
            $sId = 'survey-' . bin2hex(random_bytes(4));
        }

        $title = trim($_POST['title'] ?? '無題のアンケート');
        $desc = trim($_POST['description'] ?? '');
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $numbering = $_POST['numbering_style'] ?? 'global';
        $statusTarget = $_POST['status_target'] ?? '';

        // 構造化JSONデータの受取
        $groups = json_decode($_POST['groups_json'] ?? '[]', true) ?: [];

        $currentStatus = 'draft';
        if (!$isNew) {
            foreach ($surveys as $s) {
                if ($s['id'] === $sId) {
                    $currentStatus = $s['status'];
                    break;
                }
            }
        }

        // 状態遷移の適用
        if ($statusTarget && in_array($statusTarget, ['draft', 'published', 'paused'])) {
            if ($currentStatus === 'draft' && $statusTarget === 'published') $currentStatus = 'published';
            elseif ($currentStatus === 'published' && $statusTarget === 'paused') $currentStatus = 'paused';
            elseif ($currentStatus === 'paused' && $statusTarget === 'published') $currentStatus = 'published';
        }

        $surveyRecord = [
            'id' => $sId,
            'title' => $title,
            'description' => $desc,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'numbering_style' => $numbering,
            'status' => $currentStatus,
            'updated_at' => date('Y-m-d H:i:s'),
            'groups' => $groups
        ];
        if ($isNew) {
            $surveyRecord['created_at'] = date('Y-m-d H:i:s');
        } else {
            foreach ($surveys as $s) {
                if ($s['id'] === $sId) {
                    $surveyRecord['created_at'] = $s['created_at'] ?? date('Y-m-d H:i:s');
                    break;
                }
            }
        }

        recalculateQuestionNumbers($surveyRecord);

        $found = false;
        foreach ($surveys as &$s) {
            if ($s['id'] === $sId) {
                $s = $surveyRecord;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $surveys[] = $surveyRecord;
        }

        saveData('surveys.json', $surveys);
        $screen = 'list';
        $flashMessage = 'アンケートを保存しました。';
    }

    // 4. アンケート複製・削除
    elseif ($action === 'survey_duplicate') {
        $targetId = $_POST['target_id'] ?? '';
        foreach ($surveys as $s) {
            if ($s['id'] === $targetId) {
                $dup = $s;
                $dup['id'] = 'survey-' . bin2hex(random_bytes(4));
                $dup['title'] = $dup['title'] . ' (複製)';
                $dup['status'] = 'draft';
                $dup['created_at'] = date('Y-m-d H:i:s');
                $dup['updated_at'] = date('Y-m-d H:i:s');
                $surveys[] = $dup;
                saveData('surveys.json', $surveys);
                $flashMessage = 'アンケートを複製しました。';
                break;
            }
        }
    } elseif ($action === 'survey_delete') {
        $targetId = $_POST['target_id'] ?? '';
        $surveys = array_values(array_filter($surveys, fn($s) => $s['id'] !== $targetId));
        saveData('surveys.json', $surveys);
        $flashMessage = 'アンケートを削除しました。';
    }

    // 5. メール一括送信・再送
    elseif ($action === 'mail_send') {
        $targetCustomerIds = $_POST['customer_ids'] ?? [];
        $subjectTemplate = $_POST['mail_subject'] ?? '';
        $bodyTemplate = $_POST['mail_body'] ?? '';
        $pw = !empty($_POST['password']) ? $_POST['password'] : null;

        $survey = null;
        foreach ($surveys as $s) {
            if ($s['id'] === $surveyId) { $survey = $s; break; }
        }

        if (!$survey) {
            $flashError = '対象アンケートが存在しません。';
        } elseif (empty($targetCustomerIds)) {
            $flashError = '送信対象の顧客が選択されていません。';
        } else {
            $successCount = 0;
            $failCount = 0;
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');

            foreach ($customers as $c) {
                if (in_array($c['id'], $targetCustomerIds)) {
                    $ansUrl = "{$baseUrl}?screen=answer&id={$surveyId}&customer_id={$c['id']}";
                    $sub = str_replace(['{顧客名}', '{アンケートURL}'], [$c['name'], $ansUrl], $subjectTemplate);
                    $body = str_replace(['{顧客名}', '{アンケートURL}'], [$c['name'], $ansUrl], $bodyTemplate);

                    $res = sendSmtpMail($smtpConfig, $c['email'], $sub, $body, $pw);
                    $sendLogs[] = [
                        'survey_id' => $surveyId,
                        'customer_id' => $c['id'],
                        'customer_name' => $c['name'],
                        'email' => $c['email'],
                        'sent_at' => date('Y-m-d H:i:s'),
                        'status' => $res['success'] ? '成功' : '失敗',
                        'error' => $res['error'] ?? ''
                    ];
                    if ($res['success']) $successCount++;
                    else $failCount++;
                }
            }
            saveData('send_logs.json', $sendLogs);
            $flashMessage = "送信完了: 成功 {$successCount} 件 / 失敗 {$failCount} 件";
        }
    }

    // 6. 回答者フロー（一時回答保存 & 確定送信）
    elseif ($action === 'answer_submit_step') {
        $_SESSION['answer_temp'] = $_POST['answers'] ?? [];
        header("Location: index.php?screen=confirm&id=" . urlencode($surveyId) . (isset($_GET['customer_id']) ? '&customer_id=' . urlencode($_GET['customer_id']) : ''));
        exit;
    } elseif ($action === 'answer_final_submit') {
        $ansData = $_SESSION['answer_temp'] ?? [];
        $cid = $_POST['customer_id'] ?? ($_GET['customer_id'] ?? null);
        $answers[] = [
            'id' => 'ans-' . bin2hex(random_bytes(4)),
            'survey_id' => $surveyId,
            'customer_id' => $cid,
            'submitted_at' => date('Y-m-d H:i:s'),
            'responses' => $ansData
        ];
        saveData('answers.json', $answers);
        unset($_SESSION['answer_temp']);
        header("Location: index.php?screen=complete&id=" . urlencode($surveyId));
        exit;
    }
}

// -----------------------------------------------------------------------------
// 表示用データ算出 & ヘルパー
// -----------------------------------------------------------------------------
function getSurveyAnswerCount(string $sId, array $answers): int {
    $c = 0;
    foreach ($answers as $a) {
        if ($a['survey_id'] === $sId) $c++;
    }
    return $c;
}

$statusLabels = [
    'draft' => '下書き',
    'published' => '公開中',
    'paused' => '停止',
    'finished' => '終了'
];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アンケートシステム</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg-main: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-main);
            color: var(--text-main);
            line-height: 1.5;
        }
        .header-nav {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .header-title { font-size: 1.25rem; font-weight: bold; color: var(--text-main); }
        .header-links a {
            color: var(--text-muted);
            text-decoration: none;
            margin-left: 1.25rem;
            font-size: 0.9rem;
        }
        .header-links a:hover, .header-links a.active { color: var(--primary); font-weight: 600; }
        .container { max-width: 1100px; margin: 2rem auto; padding: 0 1rem; }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: #fff; border-color: var(--border); color: var(--text-main); }
        .btn-secondary:hover { background: #f1f5f9; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 0.35rem; }
        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.9rem;
            background: #fff;
        }
        .form-control:focus { outline: none; border-color: var(--primary); ring: 2px var(--primary); }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.875rem; }
        th, td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); }
        th { background: #f8fafc; font-weight: 600; color: var(--text-muted); }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-draft { background: #e2e8f0; color: #475569; }
        .badge-published { background: #dcfce7; color: #166534; }
        .badge-paused { background: #fef3c7; color: #92400e; }
        .badge-finished { background: #fee2e2; color: #991b1b; }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .flex { display: flex; gap: 0.5rem; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .text-muted { color: var(--text-muted); }
        
        /* 回答画面専用スタイル (モバイル優先) */
        .respondent-container { max-width: 600px; margin: 1.5rem auto; padding: 0 1rem; }
        .q-card { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; }
        .q-title { font-size: 1rem; font-weight: 600; margin-bottom: 0.75rem; }
        .q-required { color: var(--danger); font-size: 0.75rem; margin-left: 0.25rem; }
        .option-label { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; cursor: pointer; }
    </style>
</head>
<body>

<?php
// 回答者画面（answer, confirm, complete）以外は管理者ヘッダーを表示
$isRespondent = in_array($screen, ['answer', 'confirm', 'complete']);
?>

<?php if (!$isRespondent): ?>
<header class="header-nav">
    <div class="header-title">アンケート管理システム</div>
    <nav class="header-links">
        <a href="index.php?screen=list" class="<?= $screen === 'list' ? 'active' : '' ?>">アンケート一覧</a>
        <a href="index.php?screen=kintone" class="<?= $screen === 'kintone' ? 'active' : '' ?>">kintone設定</a>
        <a href="index.php?screen=mail" class="<?= $screen === 'mail' ? 'active' : '' ?>">メール設定</a>
    </nav>
</header>
<?php endif; ?>

<main class="<?= $isRespondent ? 'respondent-container' : 'container' ?>">

<?php if (!empty($flashMessage)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flashMessage) ?></div>
<?php endif; ?>
<?php if (!empty($flashError)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($flashError) ?></div>
<?php endif; ?>

<?php
// -----------------------------------------------------------------------------
// 画面別ルーティング
// -----------------------------------------------------------------------------

// =============================================================================
// SCREEN: LIST (アンケート一覧)
// =============================================================================
if ($screen === 'list'):
    $search = trim($_GET['search'] ?? '');
    $filterStatus = $_GET['status'] ?? 'all';
    $sort = $_GET['sort'] ?? 'updated_desc';

    $filtered = array_filter($surveys, function($s) use ($search, $filterStatus) {
        if ($search !== '' && stripos($s['title'], $search) === false) return false;
        if ($filterStatus !== 'all' && $s['status'] !== $filterStatus) return false;
        return true;
    });

    usort($filtered, function($a, $b) use ($sort, $answers) {
        if ($sort === 'updated_desc') return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        if ($sort === 'updated_asc') return strcmp($a['updated_at'] ?? '', $b['updated_at'] ?? '');
        if ($sort === 'start_desc') return strcmp($b['start_date'] ?? '', $a['start_date'] ?? '');
        if ($sort === 'start_asc') return strcmp($a['start_date'] ?? '', $b['start_date'] ?? '');
        if ($sort === 'answers_desc') return getSurveyAnswerCount($b['id'], $answers) <=> getSurveyAnswerCount($a['id'], $answers);
        if ($sort === 'answers_asc') return getSurveyAnswerCount($a['id'], $answers) <=> getSurveyAnswerCount($b['id'], $answers);
        return 0;
    });
?>
    <div class="flex-between" style="margin-bottom: 1.5rem;">
        <h2>アンケート一覧</h2>
        <a href="index.php?screen=edit" class="btn btn-primary">+ 新規作成</a>
    </div>

    <div class="card">
        <form method="GET" action="index.php" class="flex" style="flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
            <input type="hidden" name="screen" value="list">
            <div style="flex: 1; min-width: 200px;">
                <label style="font-size: 0.75rem; font-weight: 600;">タイトル検索 (Enter)</label>
                <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="タイトルで検索...">
            </div>
            <div>
                <label style="font-size: 0.75rem; font-weight: 600;">ステータス</label>
                <select name="status" class="form-control" onchange="this.form.submit()">
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>すべて</option>
                    <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>公開中</option>
                    <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>下書き</option>
                    <option value="paused" <?= $filterStatus === 'paused' ? 'selected' : '' ?>>停止</option>
                    <option value="finished" <?= $filterStatus === 'finished' ? 'selected' : '' ?>>終了</option>
                </select>
            </div>
            <div>
                <label style="font-size: 0.75rem; font-weight: 600;">並び順</label>
                <select name="sort" class="form-control" onchange="this.form.submit()">
                    <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>更新日: 新しい順</option>
                    <option value="updated_asc" <?= $sort === 'updated_asc' ? 'selected' : '' ?>>更新日: 古い順</option>
                    <option value="answers_desc" <?= $sort === 'answers_desc' ? 'selected' : '' ?>>回答数: 多い順</option>
                    <option value="answers_asc" <?= $sort === 'answers_asc' ? 'selected' : '' ?>>回答数: 少ない順</option>
                    <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>開始日: 新しい順</option>
                    <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>開始日: 古い順</option>
                </select>
            </div>
        </form>
    </div>

    <div class="card table-responsive">
        <table>
            <thead>
                <tr>
                    <th>タイトル</th>
                    <th>期間</th>
                    <th>ステータス</th>
                    <th>回答数</th>
                    <th>更新日時</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($filtered)): ?>
                <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">アンケートが見つかりません</td></tr>
            <?php else: foreach ($filtered as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['title']) ?></strong></td>
                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                        <?= htmlspecialchars($s['start_date'] ?: '指定なし') ?> ～<br><?= htmlspecialchars($s['end_date'] ?: '指定なし') ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($s['status']) ?>">
                            <?= $statusLabels[$s['status']] ?? $s['status'] ?>
                        </span>
                    </td>
                    <td><?= getSurveyAnswerCount($s['id'], $answers) ?></td>
                    <td style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($s['updated_at']) ?></td>
                    <td>
                        <div class="flex" style="gap: 0.25rem;">
                            <a href="index.php?screen=edit&id=<?= urlencode($s['id']) ?>" class="btn btn-secondary btn-sm">編集</a>
                            <a href="index.php?screen=preview&id=<?= urlencode($s['id']) ?>" class="btn btn-secondary btn-sm">プレビュー</a>
                            <a href="index.php?screen=analytics&id=<?= urlencode($s['id']) ?>" class="btn btn-secondary btn-sm">集計</a>
                            <a href="index.php?screen=send&id=<?= urlencode($s['id']) ?>" class="btn btn-secondary btn-sm">送信</a>
                            <form method="POST" action="index.php" style="display:inline;">
                                <input type="hidden" name="action" value="survey_duplicate">
                                <input type="hidden" name="target_id" value="<?= htmlspecialchars($s['id']) ?>">
                                <button type="submit" class="btn btn-secondary btn-sm">複製</button>
                            </form>
                            <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('本当に削除しますか？');">
                                <input type="hidden" name="action" value="survey_delete">
                                <input type="hidden" name="target_id" value="<?= htmlspecialchars($s['id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm">削除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

<?php
// =============================================================================
// SCREEN: EDIT (アンケート作成・編集)
// =============================================================================
elseif ($screen === 'edit'):
    $currSurvey = null;
    if ($surveyId) {
        foreach ($surveys as $s) {
            if ($s['id'] === $surveyId) { $currSurvey = $s; break; }
        }
    }
    $isNew = ($currSurvey === null);
    $status = $currSurvey['status'] ?? 'draft';
?>
    <div class="flex-between" style="margin-bottom: 1.5rem;">
        <h2><?= $isNew ? '新規アンケート作成' : 'アンケート編集' ?></h2>
        <div>
            <a href="index.php?screen=list" class="btn btn-secondary" onclick="return confirm('編集内容を破棄しますか？');">キャンセル</a>
            <button type="button" class="btn btn-primary" onclick="submitSurveyForm()">保存して一覧へ</button>
        </div>
    </div>

    <form id="surveyForm" method="POST" action="index.php">
        <input type="hidden" name="action" value="survey_save">
        <input type="hidden" name="survey_id" value="<?= htmlspecialchars($currSurvey['id'] ?? '') ?>">
        <input type="hidden" id="groups_json" name="groups_json" value="">

        <div class="card">
            <h3 style="margin-bottom: 1rem;">基本設定</h3>
            <div class="form-group">
                <label>アンケートタイトル <span class="text-muted">*</span></label>
                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($currSurvey['title'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>説明文</label>
                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($currSurvey['description'] ?? '') ?></textarea>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>開始日時</label>
                    <input type="datetime-local" name="start_date" class="form-control" value="<?= htmlspecialchars($currSurvey['start_date'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>終了日時</label>
                    <input type="datetime-local" name="end_date" class="form-control" value="<?= htmlspecialchars($currSurvey['end_date'] ?? '') ?>">
                </div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>質問番号の採番方式</label>
                    <select name="numbering_style" id="numbering_style" class="form-control" onchange="renderGroups()">
                        <option value="global" <?= ($currSurvey['numbering_style'] ?? '') === 'global' ? 'selected' : '' ?>>全体通番 (Q1, Q2...)</option>
                        <option value="group" <?= ($currSurvey['numbering_style'] ?? '') === 'group' ? 'selected' : '' ?>>グループ単位 (Q1-1, Q2-1...)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>ステータス変更</label>
                    <select name="status_target" class="form-control">
                        <?php if ($isNew): ?>
                            <option value="draft">下書き (新規作成時)</option>
                        <?php elseif ($status === 'draft'): ?>
                            <option value="draft" selected>下書き (現状維持)</option>
                            <option value="published">公開中に変更</option>
                        <?php elseif ($status === 'published'): ?>
                            <option value="published" selected>公開中 (現状維持)</option>
                            <option value="paused">停止に変更</option>
                        <?php elseif ($status === 'paused'): ?>
                            <option value="paused" selected>停止 (現状維持)</option>
                            <option value="published">公開中に再開</option>
                        <?php elseif ($status === 'finished'): ?>
                            <option value="finished" selected disabled>終了 (変更不可)</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
        </div>

        <div id="groupContainer"></div>

        <div style="margin-top: 1rem; margin-bottom: 3rem;">
            <button type="button" class="btn btn-secondary" onclick="addGroup()">+ グループを追加</button>
        </div>
    </form>

    <script>
    let surveyGroups = <?= json_encode($currSurvey['groups'] ?? [
        [
            'id' => 'g_' . uniqid(),
            'title' => '基本グループ',
            'questions' => [
                [
                    'id' => 'q_' . uniqid(),
                    'title' => 'ご意見をお聞かせください',
                    'type' => 'text',
                    'required' => true,
                    'options' => [],
                    'branches' => []
                ]
            ]
        ]
    ]) ?>;

    function renderGroups() {
        const container = document.getElementById('groupContainer');
        const style = document.getElementById('numbering_style').value;
        container.innerHTML = '';

        let globalIdx = 1;
        surveyGroups.forEach((g, gIdx) => {
            const gDiv = document.createElement('div');
            gDiv.className = 'card';
            gDiv.innerHTML = `
                <div class="flex-between" style="margin-bottom: 1rem;">
                    <div style="flex: 1; margin-right: 1rem;">
                        <input type="text" class="form-control" style="font-weight: bold;" value="${escapeHtml(g.title)}" onchange="updateGroupTitle(${gIdx}, this.value)" placeholder="グループ名">
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="moveGroup(${gIdx}, -1)" ${gIdx === 0 ? 'disabled' : ''}>↑</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="moveGroup(${gIdx}, 1)" ${gIdx === surveyGroups.length - 1 ? 'disabled' : ''}>↓</button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeGroup(${gIdx})">グループ削除</button>
                    </div>
                </div>
                <div id="questions_in_g_${gIdx}"></div>
                <div style="margin-top: 1rem;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addQuestion(${gIdx})">+ 質問を追加</button>
                </div>
            `;
            container.appendChild(gDiv);

            const qContainer = gDiv.querySelector(`#questions_in_g_${gIdx}`);
            g.questions.forEach((q, qIdx) => {
                const label = style === 'group' ? `Q${gIdx+1}-${qIdx+1}` : `Q${globalIdx}`;
                globalIdx++;

                const qDiv = document.createElement('div');
                qDiv.className = 'card';
                qDiv.style.background = '#f8fafc';
                qDiv.innerHTML = `
                    <div class="flex-between" style="margin-bottom: 0.5rem;">
                        <strong>${label}</strong>
                        <div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="moveQuestion(${gIdx}, ${qIdx}, -1)" ${qIdx === 0 ? 'disabled' : ''}>↑</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="moveQuestion(${gIdx}, ${qIdx}, 1)" ${qIdx === g.questions.length - 1 ? 'disabled' : ''}>↓</button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeQuestion(${gIdx}, ${qIdx})">削除</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <input type="text" class="form-control" value="${escapeHtml(q.title)}" onchange="updateQField(${gIdx}, ${qIdx}, 'title', this.value)" placeholder="質問文を入力...">
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>形式</label>
                            <select class="form-control" onchange="updateQType(${gIdx}, ${qIdx}, this.value)">
                                <option value="radio" ${q.type === 'radio' ? 'selected' : ''}>単一選択 (ラジオボタン)</option>
                                <option value="checkbox" ${q.type === 'checkbox' ? 'selected' : ''}>複数選択 (チェックボックス)</option>
                                <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述 (テキスト)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>必須設定</label>
                            <select class="form-control" onchange="updateQField(${gIdx}, ${qIdx}, 'required', this.value === '1')">
                                <option value="1" ${q.required ? 'selected' : ''}>必須</option>
                                <option value="0" ${!q.required ? 'selected' : ''}>任意</option>
                            </select>
                        </div>
                    </div>
                    ${q.type === 'radio' || q.type === 'checkbox' ? `
                        <div class="form-group">
                            <label>選択肢 (1行に1つ)</label>
                            <textarea class="form-control" rows="3" onchange="updateQOptions(${gIdx}, ${qIdx}, this.value)">${escapeHtml((q.options || []).join('\n'))}</textarea>
                        </div>
                    ` : ''}
                `;
                qContainer.appendChild(qDiv);
            });
        });
    }

    function escapeHtml(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function updateGroupTitle(gIdx, val) { surveyGroups[gIdx].title = val; }
    function addGroup() {
        surveyGroups.push({ id: 'g_' + Date.now(), title: '新規グループ', questions: [] });
        renderGroups();
    }
    function removeGroup(gIdx) {
        if (confirm('このグループを削除しますか？')) {
            surveyGroups.splice(gIdx, 1);
            renderGroups();
        }
    }
    function moveGroup(gIdx, dir) {
        const target = gIdx + dir;
        if (target < 0 || target >= surveyGroups.length) return;
        const temp = surveyGroups[gIdx];
        surveyGroups[gIdx] = surveyGroups[target];
        surveyGroups[target] = temp;
        renderGroups();
    }
    function addQuestion(gIdx) {
        surveyGroups[gIdx].questions.push({
            id: 'q_' + Date.now(),
            title: '新規の質問',
            type: 'text',
            required: true,
            options: ['選択肢1', '選択肢2'],
            branches: []
        });
        renderGroups();
    }
    function removeQuestion(gIdx, qIdx) {
        surveyGroups[gIdx].questions.splice(qIdx, 1);
        renderGroups();
    }
    function moveQuestion(gIdx, qIdx, dir) {
        const target = qIdx + dir;
        const list = surveyGroups[gIdx].questions;
        if (target < 0 || target >= list.length) return;
        const temp = list[qIdx];
        list[qIdx] = list[target];
        list[target] = temp;
        renderGroups();
    }
    function updateQField(gIdx, qIdx, field, val) { surveyGroups[gIdx].questions[qIdx][field] = val; }
    function updateQType(gIdx, qIdx, type) {
        surveyGroups[gIdx].questions[qIdx].type = type;
        if ((type === 'radio' || type === 'checkbox') && (!surveyGroups[gIdx].questions[qIdx].options || surveyGroups[gIdx].questions[qIdx].options.length === 0)) {
            surveyGroups[gIdx].questions[qIdx].options = ['選択肢1', '選択肢2'];
        }
        renderGroups();
    }
    function updateQOptions(gIdx, qIdx, val) {
        surveyGroups[gIdx].questions[qIdx].options = val.split('\n').map(s => s.trim()).filter(s => s.length > 0);
    }
    function submitSurveyForm() {
        document.getElementById('groups_json').value = JSON.stringify(surveyGroups);
        document.getElementById('surveyForm').submit();
    }

    renderGroups();
    </script>

<?php
// =============================================================================
// SCREEN: PREVIEW (プレビュー)
// =============================================================================
elseif ($screen === 'preview'):
    $survey = null;
    foreach ($surveys as $s) {
        if ($s['id'] === $surveyId) { $survey = $s; break; }
    }
    if (!$survey): ?>
        <div class="alert alert-error">アンケートが見つかりません。</div>
    <?php else: ?>
        <div class="flex-between" style="margin-bottom: 1.5rem;">
            <h2>プレビュー: <?= htmlspecialchars($survey['title']) ?></h2>
            <div class="flex">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('previewWrapper').style.maxWidth='400px'">SP表示</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('previewWrapper').style.maxWidth='800px'">PC表示</button>
                <a href="index.php?screen=list" class="btn btn-primary">一覧へ戻る</a>
            </div>
        </div>

        <div id="previewWrapper" style="margin: 0 auto; transition: max-width 0.3s; max-width: 800px;">
            <div class="card">
                <h2><?= htmlspecialchars($survey['title']) ?></h2>
                <p class="text-muted" style="margin-top: 0.5rem;"><?= nl2br(htmlspecialchars($survey['description'])) ?></p>
            </div>
            <?php foreach ($survey['groups'] as $g): ?>
                <div style="margin: 1.5rem 0 0.5rem 0; font-weight: bold; color: var(--text-muted);"><?= htmlspecialchars($g['title']) ?></div>
                <?php foreach ($g['questions'] as $q): ?>
                    <div class="q-card">
                        <div class="q-title">
                            <?= htmlspecialchars($q['number_label'] ?? '') ?>. <?= htmlspecialchars($q['title']) ?>
                            <?php if (!empty($q['required'])): ?><span class="q-required">*必須</span><?php endif; ?>
                        </div>
                        <?php if ($q['type'] === 'radio'): ?>
                            <?php foreach ($q['options'] as $opt): ?>
                                <label class="option-label"><input type="radio" disabled> <?= htmlspecialchars($opt) ?></label>
                            <?php endforeach; ?>
                        <?php elseif ($q['type'] === 'checkbox'): ?>
                            <?php foreach ($q['options'] as $opt): ?>
                                <label class="option-label"><input type="checkbox" disabled> <?= htmlspecialchars($opt) ?></label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <input type="text" class="form-control" placeholder="回答を入力..." disabled>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php
// =============================================================================
// SCREEN: SEND (顧客選択・メール送信)
// =============================================================================
elseif ($screen === 'send'):
    $survey = null;
    foreach ($surveys as $s) {
        if ($s['id'] === $surveyId) { $survey = $s; break; }
    }
    if (!$survey): ?>
        <div class="alert alert-error">アンケートが特定されていません。<a href="index.php?screen=list">一覧</a>から選択してください。</div>
    <?php else: ?>
        <div class="flex-between" style="margin-bottom: 1.5rem;">
            <h2>メール送信: <?= htmlspecialchars($survey['title']) ?></h2>
            <a href="index.php?screen=list" class="btn btn-secondary">一覧へ戻る</a>
        </div>

        <form method="POST" action="index.php?screen=send&id=<?= urlencode($surveyId) ?>">
            <input type="hidden" name="action" value="mail_send">
            <input type="hidden" name="survey_id" value="<?= htmlspecialchars($surveyId) ?>">

            <div class="card">
                <h3 style="margin-bottom: 1rem;">メール内容設定</h3>
                <div class="form-group">
                    <label>件名</label>
                    <input type="text" name="mail_subject" class="form-control" value="【アンケートのお願い】<?= htmlspecialchars($survey['title']) ?>" required>
                </div>
                <div class="form-group">
                    <label>本文テンプレート (利用可能な変数: {顧客名}, {アンケートURL})</label>
                    <textarea name="mail_body" class="form-control" rows="5" required>{顧客名} 様

アンケートへのご協力をお願い申し上げます。
以下のURLよりご
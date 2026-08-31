<?php
/**
 * アンケートアプリケーション (index.php 単一ファイル実装)
 * 要件定義書・業務ルール・セキュリティ・実行環境制約準拠版
 */

// --- 1. セッション・エラー設定 ---
ini_set('display_errors', '0');
error_reporting(E_ALL);

$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- 2. データ永続化ディレクトリ設定 ---
$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'survey_data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

// 永続化ヘルパー関数
function readJsonFile(string $filename, array $default = []): array {
    global $dataDir;
    $path = $dataDir . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return $default;
    }
    $fp = fopen($path, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

function writeJsonFile(string $filename, array $data): bool {
    global $dataDir;
    $path = $dataDir . DIRECTORY_SEPARATOR . $filename;
    $tempPath = $path . '.' . uniqid('tmp_', true);
    $fp = fopen($tempPath, 'w');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return rename($tempPath, $path);
}

// データのロード
$surveys   = readJsonFile('surveys.json', []);
$responses = readJsonFile('responses.json', []);
$customers = readJsonFile('customers.json', []);
$sendLogs  = readJsonFile('send_logs.json', []);
$kintoneConfig = readJsonFile('kintone_config.json', [
    'subdomain' => '',
    'appId' => '',
    'login' => '',
    'password' => '',
    'proxy' => '',
    'sslVerify' => false,
    'mapping' => []
]);
$mailConfig = readJsonFile('mail_config.json', [
    'host' => '',
    'port' => 587,
    'encryption' => 'tls',
    'auth' => true,
    'username' => '',
    'password' => '',
    'fromEmail' => '',
    'fromName' => '',
    'replyTo' => '',
    'status' => '未設定'
]);

// --- 3. 状態自動判定 (7.3) ---
$now = date('Y-m-d H:i');
$surveyUpdated = false;
foreach ($surveys as &$srv) {
    if ($srv['status'] === 'published' && !empty($srv['endAt'])) {
        if ($srv['endAt'] < $now) {
            $srv['status'] = 'ended';
            $surveyUpdated = true;
        }
    }
}
unset($srv);
if ($surveyUpdated) {
    writeJsonFile('surveys.json', $surveys);
}

// --- 4. 質問番号再計算 (3.4) ---
function recalculateQuestionNumbers(array &$survey): void {
    $mode = $survey['numberingMode'] ?? 'sequential'; // 'sequential' or 'group'
    $qIndex = 1;
    if (!isset($survey['groups']) || !is_array($survey['groups'])) {
        $survey['groups'] = [];
    }
    foreach ($survey['groups'] as $gIdx => &$group) {
        $gNum = $gIdx + 1;
        $qInGroup = 1;
        if (!isset($group['questions']) || !is_array($group['questions'])) {
            $group['questions'] = [];
        }
        foreach ($group['questions'] as &$q) {
            if ($mode === 'group') {
                $q['numberStr'] = 'Q' . $gNum . '-' . $qInGroup;
            } else {
                $q['numberStr'] = 'Q' . $qIndex;
            }
            $qIndex++;
            $qInGroup++;
        }
    }
}

// --- 5. 外部連携ヘルパー (PHP cURL / mail() 禁止対応) ---

// kintone REST API 通信 (stream_context 使用)
function requestKintoneApi(string $endpoint, array $config, string $method = 'GET', ?array $body = null): array {
    $subdomain = trim($config['subdomain'] ?? '');
    $subdomain = preg_replace('#^https?://#', '', $subdomain);
    $subdomain = preg_replace('#\.cybozu\.com.*$#', '', $subdomain);
    $subdomain = trim($subdomain, '/');

    if (empty($subdomain)) {
        return ['success' => false, 'statusCode' => 0, 'message' => 'サブドメインが設定されていません。'];
    }

    $url = "https://{$subdomain}.cybozu.com" . $endpoint;
    $auth = base64_encode(($config['login'] ?? '') . ':' . ($config['password'] ?? ''));

    $headers = [
        "X-Cybozu-Authorization: {$auth}",
        "Content-Type: application/json"
    ];

    $sslOptions = [
        'verify_peer' => (bool)($config['sslVerify'] ?? false),
        'verify_peer_name' => (bool)($config['sslVerify'] ?? false)
    ];

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'timeout' => 15,
        'ignore_errors' => true,
        'follow_location' => 0 // 302/303の自動追跡を無効化
    ];

    if (!empty($config['proxy'])) {
        $httpOptions['proxy'] = 'tcp://' . $config['proxy'];
        $httpOptions['request_fulluri'] = true;
    }

    if ($body !== null) {
        $httpOptions['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    $context = stream_context_create([
        'http' => $httpOptions,
        'ssl'  => $sslOptions
    ]);

    $fp = @fopen($url, 'r', false, $context);
    if (!$fp) {
        $err = error_get_last();
        return [
            'success' => false,
            'statusCode' => 0,
            'message' => 'kintoneサーバーへの接続に失敗しました: ' . ($err['message'] ?? '接続エラー')
        ];
    }

    $meta = stream_get_meta_data($fp);
    $responseHeaders = $meta['wrapper_data'] ?? [];
    $rawBody = stream_get_contents($fp);
    fclose($fp);

    $statusCode = 0;
    if (isset($responseHeaders[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m)) {
        $statusCode = (int)$m[1];
    }

    // 302/303 の明示的ハンドリング
    if ($statusCode === 302 || $statusCode === 303) {
        return [
            'success' => false,
            'statusCode' => $statusCode,
            'message' => "kintoneから予期しないリダイレクト応答({$statusCode})が返されました。"
        ];
    }

    $resJson = json_decode($rawBody, true);

    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'success' => true,
            'statusCode' => $statusCode,
            'data' => $resJson ?? [],
            'message' => '通信成功'
        ];
    }

    $msg = "kintone APIエラー (HTTP {$statusCode})";
    if (is_array($resJson) && isset($resJson['message'])) {
        $msg .= ": " . $resJson['message'];
        if (isset($resJson['errors'])) {
            $msg .= " - " . json_encode($resJson['errors'], JSON_UNESCAPED_UNICODE);
        }
    } else {
        $msg .= ": " . mb_strimwidth(strip_tags($rawBody), 0, 150, '...');
    }

    return [
        'success' => false,
        'statusCode' => $statusCode,
        'message' => $msg
    ];
}

// SMTP 送信/接続テスト (fsockopen 使用)
function sendSmtpEmail(array $config, string $to, string $subject, string $body): array {
    $host = trim($config['host'] ?? '');
    $port = (int)($config['port'] ?? 587);
    $enc = strtolower($config['encryption'] ?? 'tls');
    $auth = !empty($config['auth']);
    $user = $config['username'] ?? '';
    $pass = $config['password'] ?? '';
    $from = $config['fromEmail'] ?? '';
    $fromName = $config['fromName'] ?? '';
    $replyTo = $config['replyTo'] ?? '';

    if (empty($host) || empty($from)) {
        return ['success' => false, 'message' => 'SMTP設定（ホストまたは送信元アドレス）が未入力です。'];
    }

    $connectHost = ($enc === 'ssl') ? 'ssl://' . $host : $host;
    $fp = @fsockopen($connectHost, $port, $errno, $errstr, 15);
    if (!$fp) {
        return ['success' => false, 'message' => "SMTP接続失敗: {$errstr} ({$errno})"];
    }

    stream_set_timeout($fp, 15);

    $readResponse = function() use ($fp) {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 512);
            if ($line === false) break;
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };

    $sendCommand = function(string $cmd) use ($fp, $readResponse) {
        fwrite($fp, $cmd . "\r\n");
        return $readResponse();
    };

    $init = $readResponse();
    if (substr($init, 0, 3) !== '220') {
        fclose($fp);
        return ['success' => false, 'message' => "初期応答エラー: {$init}"];
    }

    $res = $sendCommand('EHLO localhost');
    if (substr($res, 0, 3) !== '250') {
        $res = $sendCommand('HELO localhost');
    }

    if ($enc === 'tls') {
        $res = $sendCommand('STARTTLS');
        if (substr($res, 0, 3) !== '220') {
            fclose($fp);
            return ['success' => false, 'message' => "STARTTLS失敗: {$res}"];
        }
        $crypto = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$crypto) {
            fclose($fp);
            return ['success' => false, 'message' => "TLSハンドシェイクに失敗しました。"];
        }
        $sendCommand('EHLO localhost');
    }

    if ($auth) {
        $res = $sendCommand('AUTH LOGIN');
        if (substr($res, 0, 3) !== '334') {
            fclose($fp);
            return ['success' => false, 'message' => "AUTH LOGIN拒絶: {$res}"];
        }
        $res = $sendCommand(base64_encode($user));
        if (substr($res, 0, 3) !== '334') {
            fclose($fp);
            return ['success' => false, 'message' => "ユーザー名認証エラー: {$res}"];
        }
        $res = $sendCommand(base64_encode($pass));
        if (substr($res, 0, 3) !== '235') {
            fclose($fp);
            return ['success' => false, 'message' => "パスワード認証エラー: {$res}"];
        }
    }

    if ($to === '__TEST_AUTH_ONLY__') {
        $sendCommand('QUIT');
        fclose($fp);
        return ['success' => true, 'message' => 'SMTPサーバーとの接続および認証に成功しました。'];
    }

    $res = $sendCommand("MAIL FROM:<{$from}>");
    if (substr($res, 0, 3) !== '250') {
        fclose($fp);
        return ['success' => false, 'message' => "MAIL FROMエラー: {$res}"];
    }

    $res = $sendCommand("RCPT TO:<{$to}>");
    if (substr($res, 0, 3) !== '250' && substr($res, 0, 3) !== '251') {
        fclose($fp);
        return ['success' => false, 'message' => "RCPT TOエラー ({$to}): {$res}"];
    }

    $res = $sendCommand("DATA");
    if (substr($res, 0, 3) !== '354') {
        fclose($fp);
        return ['success' => false, 'message' => "DATA受付エラー: {$res}"];
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = !empty($fromName) ? '=?UTF-8?B?' . base64_encode($fromName) . '?=' : '';
    $fromHeader = !empty($encodedFromName) ? "{$encodedFromName} <{$from}>" : $from;

    $headers = [
        "From: {$fromHeader}",
        "To: {$to}",
        "Subject: {$encodedSubject}",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: base64"
    ];
    if (!empty($replyTo)) {
        $headers[] = "Reply-To: {$replyTo}";
    }

    $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
    $res = $sendCommand($data . "\r\n.");
    if (substr($res, 0, 3) !== '250') {
        fclose($fp);
        return ['success' => false, 'message' => "送信完了エラー: {$res}"];
    }

    $sendCommand('QUIT');
    fclose($fp);
    return ['success' => true, 'message' => 'メール送信に成功しました。'];
}

// --- 6. 画面・アクションルーティング ---
$screen = $_GET['screen'] ?? 'list';
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$surveyId = $_GET['id'] ?? ($_POST['id'] ?? '');

$flashMessage = '';
$flashError = '';

// --- 7. POST 処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 7.1 アンケート作成・編集保存
    if ($action === 'save_survey') {
        $isNew = empty($surveyId);
        $id = $isNew ? 'srv_' . bin2hex(random_bytes(6)) : $surveyId;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $startAt = $_POST['startAt'] ?? '';
        $endAt = $_POST['endAt'] ?? '';
        $numberingMode = $_POST['numberingMode'] ?? 'sequential';
        $status = $_POST['status'] ?? 'draft';

        $groups = json_decode($_POST['groups_json'] ?? '[]', true);
        if (!is_array($groups)) $groups = [];

        $survey = [
            'id' => $id,
            'title' => $title ?: '無題のアンケート',
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'numberingMode' => $numberingMode,
            'status' => $status,
            'groups' => $groups,
            'updatedAt' => date('Y-m-d H:i:s'),
            'createdAt' => date('Y-m-d H:i:s')
        ];

        if (!$isNew) {
            foreach ($surveys as $existing) {
                if ($existing['id'] === $id) {
                    $survey['createdAt'] = $existing['createdAt'] ?? $survey['createdAt'];
                    // 終了状態からは手動変更禁止
                    if (($existing['status'] ?? '') === 'ended') {
                        $survey['status'] = 'ended';
                    }
                    break;
                }
            }
        }

        recalculateQuestionNumbers($survey);

        $found = false;
        foreach ($surveys as $k => $s) {
            if ($s['id'] === $id) {
                $surveys[$k] = $survey;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $surveys[] = $survey;
        }

        writeJsonFile('surveys.json', $surveys);
        header("Location: index.php?screen=list");
        exit;
    }

    // 7.2 アンケート削除
    if ($action === 'delete_survey') {
        $surveys = array_values(array_filter($surveys, fn($s) => $s['id'] !== $surveyId));
        writeJsonFile('surveys.json', $surveys);
        header("Location: index.php?screen=list");
        exit;
    }

    // 7.3 アンケート複製
    if ($action === 'duplicate_survey') {
        foreach ($surveys as $s) {
            if ($s['id'] === $surveyId) {
                $newSrv = $s;
                $newSrv['id'] = 'srv_' . bin2hex(random_bytes(6));
                $newSrv['title'] = $s['title'] . ' (コピー)';
                $newSrv['status'] = 'draft';
                $newSrv['createdAt'] = date('Y-m-d H:i:s');
                $newSrv['updatedAt'] = date('Y-m-d H:i:s');
                $surveys[] = $newSrv;
                break;
            }
        }
        writeJsonFile('surveys.json', $surveys);
        header("Location: index.php?screen=list");
        exit;
    }

    // 7.4 kintone設定保存 / 接続テスト / 項目取得 / 顧客同期
    if ($action === 'kintone_save_config') {
        $kintoneConfig['subdomain'] = trim($_POST['subdomain'] ?? '');
        $kintoneConfig['appId'] = trim($_POST['appId'] ?? '');
        $kintoneConfig['login'] = trim($_POST['login'] ?? '');
        if (!empty($_POST['password'])) {
            $kintoneConfig['password'] = $_POST['password'];
        }
        $kintoneConfig['proxy'] = trim($_POST['proxy'] ?? '');
        $kintoneConfig['sslVerify'] = isset($_POST['sslVerify']);
        writeJsonFile('kintone_config.json', $kintoneConfig);
        $flashMessage = 'kintone連携設定を保存しました。';
    }

    if ($action === 'kintone_test_connect') {
        $res = requestKintoneApi("/k/v1/app.json?id=" . urlencode($kintoneConfig['appId']), $kintoneConfig);
        if ($res['success']) {
            $flashMessage = 'kintone接続テスト成功: アプリ「' . htmlspecialchars($res['data']['name'] ?? '') . '」への接続を確認しました。';
        } else {
            $flashError = 'kintone接続テスト失敗: ' . $res['message'];
        }
    }

    if ($action === 'kintone_fetch_fields') {
        $res = requestKintoneApi("/k/v1/app/form/fields.json?app=" . urlencode($kintoneConfig['appId']), $kintoneConfig);
        if ($res['success']) {
            $fields = array_keys($res['data']['properties'] ?? []);
            $_SESSION['kintone_fields'] = $fields;
            $flashMessage = 'kintoneから項目一覧を取得しました。';
        } else {
            $flashError = '項目一覧の取得に失敗しました: ' . $res['message'];
        }
    }

    if ($action === 'kintone_save_mapping') {
        $kintoneConfig['mapping'] = [
            'company' => $_POST['map_company'] ?? '',
            'name'    => $_POST['map_name'] ?? '',
            'email'   => $_POST['map_email'] ?? '',
            'dept'    => $_POST['map_dept'] ?? '',
            'tel'     => $_POST['map_tel'] ?? '',
            'address' => $_POST['map_address'] ?? [] // 複数選択
        ];
        writeJsonFile('kintone_config.json', $kintoneConfig);
        $flashMessage = 'マッピング設定を保存しました。';
    }

    if ($action === 'kintone_sync_customers') {
        $res = requestKintoneApi("/k/v1/records.json?app=" . urlencode($kintoneConfig['appId']), $kintoneConfig);
        if ($res['success']) {
            $records = $res['data']['records'] ?? [];
            $mapping = $kintoneConfig['mapping'] ?? [];
            $newCustomers = [];
            foreach ($records as $r) {
                $cCompany = $r[$mapping['company'] ?? '']['value'] ?? '';
                $cName    = $r[$mapping['name'] ?? '']['value'] ?? '';
                $cEmail   = $r[$mapping['email'] ?? '']['value'] ?? '';
                $cDept    = $r[$mapping['dept'] ?? '']['value'] ?? '';
                $cTel     = $r[$mapping['tel'] ?? '']['value'] ?? '';
                $addrParts = [];
                if (is_array($mapping['address'] ?? null)) {
                    foreach ($mapping['address'] as $af) {
                        if (!empty($r[$af]['value'])) {
                            $addrParts[] = $r[$af]['value'];
                        }
                    }
                }
                $cAddress = implode(' ', $addrParts);

                if (!empty($cEmail)) {
                    $newCustomers[] = [
                        'id' => 'cust_' . bin2hex(random_bytes(4)),
                        'company' => $cCompany,
                        'name' => $cName,
                        'email' => $cEmail,
                        'dept' => $cDept,
                        'tel' => $cTel,
                        'address' => $cAddress
                    ];
                }
            }
            $customers = $newCustomers;
            writeJsonFile('customers.json', $customers);
            $flashMessage = count($customers) . ' 件の顧客情報をkintoneから同期しました。';
        } else {
            $flashError = '顧客同期失敗: ' . $res['message'];
        }
    }

    // 7.5 メール設定保存 / 接続テスト / テスト送信
    if ($action === 'mail_save_config') {
        $mailConfig['host'] = trim($_POST['host'] ?? '');
        $mailConfig['port'] = (int)($_POST['port'] ?? 587);
        $mailConfig['encryption'] = $_POST['encryption'] ?? 'tls';
        $mailConfig['auth'] = isset($_POST['auth']);
        $mailConfig['username'] = trim($_POST['username'] ?? '');
        if (!empty($_POST['password'])) {
            $mailConfig['password'] = $_POST['password'];
        }
        $mailConfig['fromEmail'] = trim($_POST['fromEmail'] ?? '');
        $mailConfig['fromName'] = trim($_POST['fromName'] ?? '');
        $mailConfig['replyTo'] = trim($_POST['replyTo'] ?? '');
        writeJsonFile('mail_config.json', $mailConfig);
        $flashMessage = 'メールサーバ設定を保存しました。';
    }

    if ($action === 'mail_test_connect') {
        $res = sendSmtpEmail($mailConfig, '__TEST_AUTH_ONLY__', '', '');
        if ($res['success']) {
            $mailConfig['status'] = '接続確認済み';
            $flashMessage = 'SMTP接続テスト成功: ' . $res['message'];
        } else {
            $mailConfig['status'] = '接続できません';
            $flashError = 'SMTP接続テスト失敗: ' . $res['message'];
        }
        writeJsonFile('mail_config.json', $mailConfig);
    }

    if ($action === 'mail_test_send') {
        $testTo = trim($_POST['test_to'] ?? '');
        if (empty($testTo)) {
            $flashError = '送信先テストメールアドレスを入力してください。';
        } else {
            $res = sendSmtpEmail($mailConfig, $testTo, '【接続テスト】アンケートアプリ', "これはアンケートシステムからのテストメール送信です。\n正常に受信できています。");
            if ($res['success']) {
                $flashMessage = "テストメールを {$testTo} へ送信しました。";
            } else {
                $flashError = "テストメール送信失敗: " . $res['message'];
            }
        }
    }

    // 7.6 メール一括送信・再送 (3.7)
    if ($action === 'send_survey_emails') {
        $selectedCustIds = $_POST['selected_customers'] ?? [];
        $emailSubject = trim($_POST['email_subject'] ?? '');
        $emailBodyTpl = trim($_POST['email_body'] ?? '');
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . explode('?', $_SERVER['REQUEST_URI'])[0];

        $successCount = 0;
        $failCount = 0;

        foreach ($selectedCustIds as $cid) {
            $targetCust = null;
            foreach ($customers as $c) {
                if ($c['id'] === $cid) {
                    $targetCust = $c;
                    break;
                }
            }
            if (!$targetCust) continue;

            $surveyUrl = $baseUrl . "?screen=answer&id={$surveyId}&cid={$targetCust['id']}";
            $body = str_replace(['{顧客名}', '{アンケートURL}'], [$targetCust['name'], $surveyUrl], $emailBodyTpl);

            $sendRes = sendSmtpEmail($mailConfig, $targetCust['email'], $emailSubject, $body);

            $log = [
                'id' => 'log_' . bin2hex(random_bytes(4)),
                'surveyId' => $surveyId,
                'customerId' => $targetCust['id'],
                'customerName' => $targetCust['name'],
                'email' => $targetCust['email'],
                'subject' => $emailSubject,
                'sentAt' => date('Y-m-d H:i:s'),
                'status' => $sendRes['success'] ? '成功' : '失敗',
                'error' => $sendRes['success'] ? '' : $sendRes['message']
            ];
            $sendLogs[] = $log;

            if ($sendRes['success']) $successCount++;
            else $failCount++;
        }

        writeJsonFile('send_logs.json', $sendLogs);
        $flashMessage = "送信処理が完了しました (成功: {$successCount} 件, 失敗: {$failCount} 件)";
    }

    // 7.7 回答者送信完了 (3.6, 7.11)
    if ($action === 'submit_response') {
        $ansData = $_POST['answers'] ?? [];
        $cid = $_POST['cid'] ?? '';
        $responseRecord = [
            'id' => 'resp_' . bin2hex(random_bytes(6)),
            'surveyId' => $surveyId,
            'customerId' => $cid,
            'submittedAt' => date('Y-m-d H:i:s'),
            'answers' => $ansData
        ];
        $responses[] = $responseRecord;
        writeJsonFile('responses.json', $responses);

        // 回答完了画面へ遷移 (管理者画面へは遷移しない)
        header("Location: index.php?screen=complete&id={$surveyId}");
        exit;
    }
}

// CSV/PDF エクスポート処理 (7.7)
if ($screen === 'export_csv') {
    $targetSurvey = null;
    foreach ($surveys as $s) {
        if ($s['id'] === $surveyId) { $targetSurvey = $s; break; }
    }
    if (!$targetSurvey) { exit('アンケートが見つかりません'); }

    $surveyResponses = array_filter($responses, fn($r) => $r['surveyId'] === $surveyId);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="survey_' . $surveyId . '_results.csv"');
    
    $out = fopen('php://output', 'w');
    // BOM
    fwrite($out, "\xEF\xBB\xBF");

    $headers = ['回答ID', '回答日時', '顧客ID'];
    $qKeys = [];
    foreach ($targetSurvey['groups'] ?? [] as $g) {
        foreach ($g['questions'] ?? [] as $q) {
            $headers[] = ($q['numberStr'] ?? '') . ' ' . $q['title'];
            $qKeys[] = $q['id'];
        }
    }
    fputcsv($out, $headers);

    foreach ($surveyResponses as $r) {
        $row = [$r['id'], $r['submittedAt'], $r['customerId'] ?? '未登録'];
        foreach ($qKeys as $qid) {
            $ans = $r['answers'][$qid] ?? '';
            $row[] = is_array($ans) ? implode('; ', $ans) : $ans;
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

if ($screen === 'export_pdf') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="survey_' . $surveyId . '_summary.txt"');
    echo "=== アンケート集計結果サマリー ===\n\n";
    echo "アンケートID: {$surveyId}\n出力日時: " . date('Y-m-d H:i:s') . "\n\n";
    $surveyResponses = array_filter($responses, fn($r) => $r['surveyId'] === $surveyId);
    echo "総回答数: " . count($surveyResponses) . " 件\n";
    exit;
}

// 7.8 画面対象アンケートの固定検証 (4.2, 7.4)
$currentSurvey = null;
if (in_array($screen, ['edit', 'preview', 'send', 'analytics', 'answer', 'confirm', 'complete'])) {
    if (empty($surveyId)) {
        if (!in_array($screen, ['answer', 'confirm', 'complete'])) {
            header("Location: index.php?screen=list");
            exit;
        }
    } else {
        foreach ($surveys as $s) {
            if ($s['id'] === $surveyId) {
                $currentSurvey = $s;
                break;
            }
        }
        if (!$currentSurvey && $screen !== 'edit') {
            if (in_array($screen, ['answer', 'confirm', 'complete'])) {
                exit('指定されたアンケートは存在しないか、公開されていません。');
            }
            header("Location: index.php?screen=list");
            exit;
        }
    }
}

// 共通エスケープ
function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アンケート管理システム</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --gray: #64748b;
            --gray-light: #f1f5f9;
            --border: #dbe2ea;
            --text: #1e293b;
            --white: #fff;
            --shadow: 0 4px 18px rgba(15, 23, 42, .08);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans JP", "Hiragino Kaku Gothic ProN", Meiryo, sans-serif;
        }

        body {
            background-color: #f8fafc;
            color: var(--text);
            line-height: 1.5;
        }

        /* 管理者共通ヘッダー */
        .admin-header {
            background-color: #0f172a;
            color: #fff;
            padding: 0.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .admin-header .logo {
            font-size: 1.25rem;
            font-weight: 700;
            color: #fff;
            text-decoration: none;
        }

        .admin-nav {
            display: flex;
            gap: 1rem;
        }

        .admin-nav a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: 0.2s;
        }

        .admin-nav a:hover, .admin-nav a.active {
            color: #fff;
            background: rgba(255,255,255,0.1);
        }

        .main-container {
            max-width: 1200px;
            margin: 1.5rem auto;
            padding: 0 1rem;
        }

        /* 回答者向けレイアウト */
        .respondent-container {
            max-width: 680px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .card {
            background: var(--white);
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
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
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { background: var(--gray-light); color: var(--text); border-color: var(--border); }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-success { background: var(--success); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.375rem;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            background: #fff;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        .table-responsive {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            text-align: left;
        }
        .table th, .table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border);
        }
        .table th {
            background: var(--gray-light);
            font-weight: 600;
        }

        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-draft { background: #e2e8f0; color: #475569; }
        .badge-published { background: #dcfce7; color: #166534; }
        .badge-stopped { background: #fef3c7; color: #92400e; }
        .badge-ended { background: #fee2e2; color: #991b1b; }

        /* モーダル */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: #fff;
            padding: 1.5rem;
            border-radius: 8px;
            max-width: 400px;
            width: 90%;
            box-shadow: var(--shadow);
        }

        /* 質問エディタ・DnDスタイル */
        .group-card {
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fff;
            margin-bottom: 1rem;
            padding: 1rem;
        }
        .question-card {
            background: #f8fafc;
            border: 1px dashed var(--border);
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            cursor: grab;
        }
        .question-card.dragging, .group-card.dragging {
            opacity: 0.5;
        }

        /* 回答者フォーム用 */
        .respondent-card {
            background: #fff;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }
        .choice-label {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .choice-label:hover {
            background: var(--gray-light);
        }
        .choice-label input {
            margin-right: 0.75rem;
            transform: scale(1.2);
        }
    </style>
</head>
<body>

<?php
// 回答者画面以外では管理者ヘッダーを表示 (4.1)
$isRespondentView = in_array($screen, ['answer', 'confirm', 'complete']);
if (!$isRespondentView):
?>
<header class="admin-header">
    <a href="index.php?screen=list" class="logo">アンケート管理システム</a>
    <nav class="admin-nav">
        <a href="index.php?screen=list" class="<?= $screen === 'list' ? 'active' : '' ?>">アンケート一覧</a>
        <a href="index.php?screen=kintone" class="<?= $screen === 'kintone' ? 'active' : '' ?>">kintone設定</a>
        <a href="index.php?screen=mail" class="<?= $screen === 'mail' ? 'active' : '' ?>">メールサーバ設定</a>
    </nav>
</header>
<?php endif; ?>

<div class="<?= $isRespondentView ? 'respondent-container' : 'main-container' ?>">
    <?php if ($flashMessage): ?>
        <div class="alert alert-success"><?= h($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-error"><?= h($flashError) ?></div>
    <?php endif; ?>

    <?php
    // ==========================================
    // 画面1: アンケート一覧 (screen=list)
    // ==========================================
    if ($screen === 'list'):
        $searchQ = trim($_GET['search'] ?? '');
        $statusFilter = $_GET['status_filter'] ?? 'all';
        $sortBy = $_GET['sort'] ?? 'updated_desc';

        $displaySurveys = $surveys;

        // 検索
        if ($searchQ !== '') {
            $displaySurveys = array_filter($displaySurveys, fn($s) => mb_stripos($s['title'], $searchQ) !== false);
        }
        // 絞り込み
        if ($statusFilter !== 'all') {
            $displaySurveys = array_filter($displaySurveys, fn($s) => $s['status'] === $statusFilter);
        }
        // ソート
        usort($displaySurveys, function($a, $b) use ($sortBy, $responses) {
            $respCountA = count(array_filter($responses, fn($r) => $r['surveyId'] === $a['id']));
            $respCountB = count(array_filter($responses, fn($r) => $r['surveyId'] === $b['id']));
            return match($sortBy) {
                'updated_asc' => strcmp($a['updatedAt'] ?? '', $b['updatedAt'] ?? ''),
                'resp_desc'   => $respCountB <=> $respCountA,
                'resp_asc'    => $respCountA <=> $respCountB,
                'start_desc'  => strcmp($b['startAt'] ?? '', $a['startAt'] ?? ''),
                'start_asc'   => strcmp($a['startAt'] ?? '', $b['startAt'] ?? ''),
                default       => strcmp($b['updatedAt'] ?? '', $a['updatedAt'] ?? '') // updated_desc
            };
        });
    ?>
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
            <h2>アンケート一覧</h2>
            <a href="index.php?screen=edit" class="btn btn-primary">+ 新規作成</a>
        </div>

        <form method="GET" action="index.php" style="display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap;">
            <input type="hidden" name="screen" value="list">
            <input type="text" name="search" class="form-control" style="max-width: 200px;" placeholder="タイトル検索 (Enter)" value="<?= h($searchQ) ?>">
            <select name="status_filter" class="form-control" style="max-width: 140px;" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>すべての状態</option>
                <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>>公開中</option>
                <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>下書き</option>
                <option value="stopped" <?= $statusFilter === 'stopped' ? 'selected' : '' ?>>停止</option>
                <option value="ended" <?= $statusFilter === 'ended' ? 'selected' : '' ?>>終了</option>
            </select>
            <select name="sort" class="form-control" style="max-width: 180px;" onchange="this.form.submit()">
                <option value="updated_desc" <?= $sortBy === 'updated_desc' ? 'selected' : '' ?>>更新日: 新しい順</option>
                <option value="updated_asc" <?= $sortBy === 'updated_asc' ? 'selected' : '' ?>>更新日: 古い順</option>
                <option value="resp_desc" <?= $sortBy === 'resp_desc' ? 'selected' : '' ?>>回答数: 多い順</option>
                <option value="resp_asc" <?= $sortBy === 'resp_asc' ? 'selected' : '' ?>>回答数: 少ない順</option>
                <option value="start_desc" <?= $sortBy === 'start_desc' ? 'selected' : '' ?>>開始日: 新しい順</option>
                <option value="start_asc" <?= $sortBy === 'start_asc' ? 'selected' : '' ?>>開始日: 古い順</option>
            </select>
        </form>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>タイトル</th>
                        <th>期間</th>
                        <th>ステータス</th>
                        <th>回答数</th>
                        <th>更新日</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($displaySurveys)): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--gray);">該当するアンケートはありません</td></tr>
                    <?php else: ?>
                        <?php foreach ($displaySurveys as $s): 
                            $rCount = count(array_filter($responses, fn($r) => $r['surveyId'] === $s['id']));
                            $stClass = match($s['status']) {
                                'published' => 'badge-published',
                                'stopped' => 'badge-stopped',
                                'ended' => 'badge-ended',
                                default => 'badge-draft'
                            };
                            $stLabel = match($s['status']) {
                                'published' => '公開中',
                                'stopped' => '停止',
                                'ended' => '終了',
                                default => '下書き'
                            };
                        ?>
                        <tr>
                            <td><strong><?= h($s['title']) ?></strong></td>
                            <td><?= h($s['startAt'] ?: '未設定') ?> ～ <?= h($s['endAt'] ?: '未設定') ?></td>
                            <td><span class="badge <?= $stClass ?>"><?= $stLabel ?></span></td>
                            <td><?= $rCount ?></td>
                            <td><?= substr($s['updatedAt'] ?? '', 0, 16) ?></td>
                            <td style="white-space: nowrap;">
                                <a href="index.php?screen=edit&id=<?= h($s['id']) ?>" class="btn btn-secondary btn-sm">確認・編集</a>
                                <a href="index.php?screen=analytics&id=<?= h($s['id']) ?>" class="btn btn-secondary btn-sm">集計</a>
                                <a href="index.php?screen=send&id=<?= h($s['id']) ?>" class="btn btn-secondary btn-sm">送信</a>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="confirmAction('複製しますか？', 'duplicate_survey', '<?= h($s['id']) ?>')">複製</button>
                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmAction('本当に削除しますか？', 'delete_survey', '<?= h($s['id']) ?>')">削除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    // ==========================================
    // 画面2: アンケート作成・編集 (screen=edit)
    // ==========================================
    elseif ($screen === 'edit'):
        $isEdit = !empty($currentSurvey);
        $surveyData = $currentSurvey ?? [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'numberingMode' => 'sequential',
            'status' => 'draft',
            'groups' => []
        ];
    ?>
    <form method="POST" action="index.php" id="surveyEditForm">
        <input type="hidden" name="action" value="save_survey">
        <input type="hidden" name="id" value="<?= h($surveyData['id']) ?>">
        <input type="hidden" name="groups_json" id="groupsJsonInput">

        <div class="card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <button type="button" class="btn btn-secondary" onclick="confirmDiscard()">キャンセル</button>
                <button type="submit" class="btn btn-primary" onclick="return prepareSave()">保存して一覧へ</button>
                <?php if ($isEdit): ?>
                    <a href="index.php?screen=preview&id=<?= h($surveyData['id']) ?>" target="_blank" class="btn btn-secondary">プレビュー</a>
                <?php endif; ?>
            </div>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <label style="font-weight: 600; font-size: 0.875rem;">状態:</label>
                <?php if ($surveyData['status'] === 'ended'): ?>
                    <span class="badge badge-ended">終了 (変更不可)</span>
                    <input type="hidden" name="status" value="ended">
                <?php else: ?>
                    <select name="status" class="form-control" style="width: 120px;" onchange="confirmStatusChange(this)">
                        <option value="draft" <?= $surveyData['status'] === 'draft' ? 'selected' : '' ?>>下書き</option>
                        <option value="published" <?= $surveyData['status'] === 'published' ? 'selected' : '' ?>>公開中</option>
                        <option value="stopped" <?= $surveyData['status'] === 'stopped' ? 'selected' : '' ?>>停止</option>
                    </select>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="form-group">
                <label>アンケートタイトル *</label>
                <input type="text" name="title" class="form-control" required value="<?= h($surveyData['title']) ?>" placeholder="アンケートタイトルを入力">
            </div>
            <div class="form-group">
                <label>説明文</label>
                <textarea name="description" class="form-control" rows="3" placeholder="回答者向けの説明文"><?= h($surveyData['description']) ?></textarea>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>開始日時</label>
                    <input type="datetime-local" name="startAt" class="form-control" value="<?= h($surveyData['startAt']) ?>">
                </div>
                <div class="form-group">
                    <label>終了日時</label>
                    <input type="datetime-local" name="endAt" class="form-control" value="<?= h($surveyData['endAt']) ?>">
                </div>
                <div class="form-group">
                    <label>質問番号の採番方式</label>
                    <select name="numberingMode" id="numberingModeSelect" class="form-control" onchange="renderGroups()">
                        <option value="sequential" <?= ($surveyData['numberingMode'] ?? '') === 'sequential' ? 'selected' : '' ?>>アンケート全体で通番 (Q1, Q2...)</option>
                        <option value="group" <?= ($surveyData['numberingMode'] ?? '') === 'group' ? 'selected' : '' ?>>グループ毎に採番 (Q1-1, Q1-2...)</option>
                    </select>
                </div>
            </div>
        </div>

        <div id="groupsContainer"></div>

        <div style="margin-bottom: 2rem;">
            <button type="button" class="btn btn-secondary" onclick="addGroup()">+ グループを追加 (末尾)</button>
        </div>
    </form>

    <script>
    let surveyGroups = <?= json_encode($surveyData['groups'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    if (!Array.isArray(surveyGroups)) surveyGroups = [];

    function renderGroups() {
        const container = document.getElementById('groupsContainer');
        container.innerHTML = '';
        const mode = document.getElementById('numberingModeSelect').value;
        let globalQIndex = 1;

        surveyGroups.forEach((group, gIdx) => {
            const gCard = document.createElement('div');
            gCard.className = 'group-card';
            gCard.draggable = true;
            gCard.dataset.gidx = gIdx;

            let qHtml = '';
            (group.questions || []).forEach((q, qIdx) => {
                const qNumStr = (mode === 'group') ? `Q${gIdx+1}-${qIdx+1}` : `Q${globalQIndex}`;
                globalQIndex++;
                q.numberStr = qNumStr;

                let choicesHtml = '';
                if (q.type === 'single' || q.type === 'multiple') {
                    const choicesList = (q.choices || []).map((c, cIdx) => `
                        <div style="display:flex; gap:0.5rem; margin-top:0.25rem;">
                            <input type="text" class="form-control form-control-sm" value="${escapeHtml(c.text || '')}" placeholder="選択肢名" onchange="updateChoice(${gIdx}, ${qIdx}, ${cIdx}, this.value)">
                            ${q.type === 'single' ? `
                                <input type="text" class="form-control form-control-sm" style="max-width:140px;" value="${escapeHtml(c.nextQuestionId || '')}" placeholder="遷移先質問ID" onchange="updateChoiceNext(${gIdx}, ${qIdx}, ${cIdx}, this.value)">
                            ` : ''}
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeChoice(${gIdx}, ${qIdx}, ${cIdx})">×</button>
                        </div>
                    `).join('');

                    choicesHtml = `
                        <div style="margin-top:0.5rem; margin-left:1rem;">
                            <label style="font-size:0.75rem; font-weight:600;">選択肢 ${q.type === 'single' ? '(+条件分岐遷移先)' : ''}:</label>
                            ${choicesList}
                            <button type="button" class="btn btn-secondary btn-sm" style="margin-top:0.25rem;" onclick="addChoice(${gIdx}, ${qIdx})">+ 選択肢追加</button>
                        </div>
                    `;
                }

                qHtml += `
                    <div class="question-card" draggable="true" data-gidx="${gIdx}" data-qidx="${qIdx}">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong>${qNumStr} (ID: ${escapeHtml(q.id || '')})</strong>
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeQuestion(${gIdx}, ${qIdx})">質問削除</button>
                        </div>
                        <div style="display:grid; grid-template-columns: 2fr 1fr 1fr; gap:0.5rem; margin-top:0.5rem;">
                            <input type="text" class="form-control" value="${escapeHtml(q.title || '')}" placeholder="質問文" onchange="updateQTitle(${gIdx}, ${qIdx}, this.value)">
                            <select class="form-control" onchange="updateQType(${gIdx}, ${qIdx}, this.value)">
                                <option value="single" ${q.type === 'single' ? 'selected' : ''}>単一選択</option>
                                <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選択</option>
                                <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述</option>
                            </select>
                            <label style="display:flex; align-items:center; font-size:0.875rem;">
                                <input type="checkbox" ${q.required ? 'checked' : ''} onchange="updateQReq(${gIdx}, ${qIdx}, this.checked)"> 必須
                            </label>
                        </div>
                        ${choicesHtml}
                    </div>
                `;
            });

            gCard.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem; width:60%;">
                        <span style="cursor:grab;">☰</span>
                        <input type="text" class="form-control" value="${escapeHtml(group.title || '')}" placeholder="グループタイトル" onchange="updateGroupTitle(${gIdx}, this.value)">
                    </div>
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeGroup(${gIdx})">グループ削除</button>
                </div>
                <div class="questions-list">${qHtml}</div>
                <button type="button" class="btn btn-secondary btn-sm" style="margin-top:0.5rem;" onclick="addQuestion(${gIdx})">+ 質問を追加 (グループ末尾)</button>
            `;

            container.appendChild(gCard);
        });

        setupDragAndDrop();
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function addGroup() {
        surveyGroups.push({ id: 'grp_' + Math.random().toString(36).substr(2, 9), title: '新規グループ', questions: [] });
        renderGroups();
    }
    function removeGroup(gIdx) {
        if (confirm('グループを削除しますか？中の質問も削除されます。')) {
            surveyGroups.splice(gIdx, 1);
            renderGroups();
        }
    }
    function updateGroupTitle(gIdx, val) { surveyGroups[gIdx].title = val; }
    function addQuestion(gIdx) {
        surveyGroups[gIdx].questions = surveyGroups[gIdx].questions || [];
        surveyGroups[gIdx].questions.push({
            id: 'q_' + Math.random().toString(36).substr(2, 9),
            title: '',
            type: 'single',
            required: false,
            choices: [{ text: '選択肢1', nextQuestionId: '' }]
        });
        renderGroups();
    }
    function removeQuestion(gIdx, qIdx) {
        if (confirm('質問を削除しますか？')) {
            surveyGroups[gIdx].questions.splice(qIdx, 1);
            renderGroups();
        }
    }
    function updateQTitle(gIdx, qIdx, val) { surveyGroups[gIdx].questions[qIdx].title = val; }
    function updateQType(gIdx, qIdx, val) {
        surveyGroups[gIdx].questions[qIdx].type = val;
        if ((val === 'single' || val === 'multiple') && (!surveyGroups[gIdx].questions[qIdx].choices || surveyGroups[gIdx].questions[qIdx].choices.length === 0)) {
            surveyGroups[gIdx].questions[qIdx].choices = [{ text: '選択肢1', nextQuestionId: '' }];
        }
        renderGroups();
    }
    function updateQReq(gIdx, qIdx, val) { surveyGroups[gIdx].questions[qIdx].required = val; }
    function addChoice(gIdx, qIdx) {
        surveyGroups[gIdx].questions[qIdx].choices.push({ text: '', nextQuestionId: '' });
        renderGroups();
    }
    function removeChoice(gIdx, qIdx, cIdx) {
        surveyGroups[gIdx].questions[qIdx].choices.splice(cIdx, 1);
        renderGroups();
    }
    function updateChoice(gIdx, qIdx, cIdx, val) { surveyGroups[gIdx].questions[qIdx].choices[cIdx].text = val; }
    function updateChoiceNext(gIdx, qIdx, cIdx, val) { surveyGroups[gIdx].questions[qIdx].choices[cIdx].nextQuestionId = val; }

    function prepareSave() {
        document.getElementById('groupsJsonInput').value = JSON.stringify(surveyGroups);
        return true;
    }
    function confirmDiscard() {
        if (confirm('編集内容を破棄して一覧へ戻りますか？')) {
            window.location.href = 'index.php?screen=list';
        }
    }
    function confirmStatusChange(sel) {
        if (!confirm('状態を変更しますか？')) {
            sel.value = sel.dataset.prev || sel.value;
        } else {
            sel.dataset.prev = sel.value;
        }
    }

    // ドラッグ＆ドロップ実装
    function setupDragAndDrop() {
        let draggedType = null;
        let dragSrcGIdx = null;
        let dragSrcQIdx = null;

        document.querySelectorAll('.question-card').forEach(qc => {
            qc.addEventListener('dragstart', (e) => {
                e.stopPropagation();
                draggedType = 'question';
                dragSrcGIdx = parseInt(qc.dataset.gidx);
                dragSrcQIdx = parseInt(qc.dataset.qidx);
                qc.classList.add('dragging');
            });
            qc.addEventListener('dragend', () => {
                qc.classList.remove('dragging');
            });
            qc.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
            qc.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (draggedType === 'question') {
                    const targetGIdx = parseInt(qc.dataset.gidx);
                    const targetQIdx = parseInt(qc.dataset.qidx);
                    const item = surveyGroups[dragSrcGIdx].questions.splice(dragSrcQIdx, 1)[0];
                    surveyGroups[targetGIdx].questions.splice(targetQIdx, 0, item);
                    renderGroups();
                }
            });
        });

        document.querySelectorAll('.group-card').forEach(gc => {
            gc.addEventListener('dragstart', (e) => {
                draggedType = 'group';
                dragSrcGIdx = parseInt(gc.dataset.gidx);
                gc.classList.add('dragging');
            });
            gc.addEventListener('dragend', () => {
                gc.classList.remove('dragging');
            });
            gc.addEventListener('dragover', (e) => {
                e.preventDefault();
            });
            gc.addEventListener('drop', (e) => {
                e.preventDefault();
                if (draggedType === 'group') {
                    const targetGIdx = parseInt(gc.dataset.gidx);
                    const item = surveyGroups.splice(dragSrcGIdx, 1)[0];
                    surveyGroups.splice(targetGIdx, 0, item);
                    renderGroups();
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', renderGroups);
    </script>

    <?php
    // ==========================================
    // 画面3: プレビュー (screen=preview)
    // ==========================================
    elseif ($screen === 'preview'):
    ?>
    <div class="card" style="display: flex; justify-content: space-between; align-items: center;">
        <h2>プレビュー: <?= h($currentSurvey['title']) ?></h2>
        <div>
            <button class="btn btn-secondary btn-sm" onclick="setPreviewSize('100%')">PC表示</button>
            <button class="btn btn-secondary btn-sm" onclick="setPreviewSize('375px')">スマートフォン表示</button>
        </div>
    </div>
    <div id="previewWrapper" style="margin: 0 auto; transition: width 0.3s; max-width: 100%;">
        <div class="card">
            <h3><?= h($currentSurvey['title']) ?></h3>
            <p style="color: var(--gray); margin-top: 0.5rem;"><?= nl2br(h($currentSurvey['description'])) ?></p>
        </div>
        <?php foreach ($currentSurvey['groups'] ?? [] as $group): ?>
            <div class="card">
                <h4><?= h($group['title']) ?></h4>
                <?php foreach ($group['questions'] ?? [] as $q): ?>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                        <p><strong><?= h($q['numberStr'] ?? '') ?>. <?= h($q['title']) ?></strong> <?= !empty($q['required']) ? '<span style="color:var(--danger)">*必須</span>' : '' ?></p>
                        <?php if ($q['type'] === 'single'): ?>
                            <?php foreach ($q['choices'] ?? [] as $c): ?>
                                <label class="choice-label"><input type="radio" name="preview_<?= h($q['id']) ?>" disabled> <?= h($c['text']) ?> <?= !empty($c['nextQuestionId']) ? '<small style="color:var(--gray)">(→ ' . h($c['nextQuestionId']) . 'へ)</small>' : '' ?></label>
                            <?php endforeach; ?>
                        <?php elseif ($q['type'] === 'multiple'): ?>
                            <?php foreach ($q['choices'] ?? [] as $c): ?>
                                <label class="choice-label"><input type="checkbox" disabled> <?= h($c['text']) ?></label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <textarea class="form-control" rows="3" disabled placeholder="自由記述入力欄"></textarea>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <script>
    function setPreviewSize(width) {
        document.getElementById('previewWrapper').style.maxWidth = width;
    }
    </script>

    <?php
    // ==========================================
    // 画面4: 顧客選択・メール送信 (screen=send)
    // ==========================================
    elseif ($screen === 'send'):
        $targetLogs = array_filter($sendLogs, fn($l) => $l['surveyId'] === $surveyId);
    ?>
    <div class="card">
        <h2>顧客選択・メール送信 (対象: <?= h($currentSurvey['title']) ?>)</h2>
    </div>

    <div class="card">
        <form method="POST" action="index.php?screen=send&id=<?= h($surveyId) ?>" id="sendMailForm">
            <input type="hidden" name="action" value="send_survey_emails">
            <input type="hidden" name="id" value="<?= h($surveyId) ?>">

            <div class="form-group">
                <label>メール件名 *</label>
                <input type="text" name="email_subject" class="form-control" required value="【アンケートご協力のお願い】<?= h($currentSurvey['title']) ?>">
            </div>
            <div class="form-group">
                <label>メール本文 * (変数: {顧客名}, {アンケートURL})</label>
                <textarea name="email_body" class="form-control" rows="6" required>{顧客名} 様

平素より大変お世話になっております。
以下のアンケートへのご協力をお願い申し上げます。

▼ アンケート回答URL
{アンケートURL}

何卒よろしくお願い申し上げます。</textarea>
            </div>

            <h3 style="margin: 1.5rem 0 0.5rem 0;">送信対象顧客の選択</h3>
            <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="selectAllCust" onchange="toggleSelectAllCust(this)"></th>
                            <th>会社名</th>
                            <th>氏名</th>
                            <th>メールアドレス</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($customers)): ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--gray);">顧客データがありません (kintone設定から同期してください)</td></tr>
                        <?php else: ?>
                            <?php foreach ($customers as $c): ?>
                            <tr>
                                <td><input type="checkbox" name="selected_customers[]" value="<?= h($c['id']) ?>" class="cust-check"></td>
                                <td><?= h($c['company']) ?></td>
                                <td><?= h($c['name']) ?></td>
                                <td><?= h($c['email']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 1rem;">
                <button type="submit" class="btn btn-primary" onclick="return confirm('一括送信を実行しますか？')">一括送信</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>送信履歴</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>送信日時</th>
                        <th>顧客名</th>
                        <th>メールアドレス</th>
                        <th>ステータス</th>
                        <th>詳細 / エラー</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($targetLogs)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--gray);">送信履歴はありません</td></tr>
                    <?php else: ?>
                        <?php foreach (array_reverse($targetLogs) as $log): ?>
                        <tr>
                            <td><?= h($log['sentAt']) ?></td>
                            <td><?= h($log['customerName']) ?></td>
                            <td><?= h($log['email']) ?></td>
                            <td>
                                <span class="badge <?= $log['status'] === '成功' ? 'badge-published' : 'badge-ended' ?>"><?= h($log['status']) ?></span>
                            </td>
                            <td><?= h($log['error'] ?: '正常完了') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
    function toggleSelectAllCust(master) {
        document.querySelectorAll('.cust-check').forEach(cb => cb.checked = master.checked);
    }
    </script>

    <?php
    // ==========================================
    // 画面5: 回答集計・分析 (screen=analytics)
    // ==========================================
    elseif ($screen === 'analytics'):
        $targetResponses = array_values(array_filter($responses, fn($r) => $r['surveyId'] === $surveyId));
        $respCount = count($targetResponses);
        $totalSent = count(array_filter($sendLogs, fn($l) => $l['surveyId'] === $surveyId && $l['status'] === '成功'));
        $respRate = ($totalSent > 0) ? round(($respCount / $totalSent) * 100, 1) : 0;
    ?>
    <div class="card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
        <h2>回答集計・分析 (対象: <?= h($currentSurvey['title']) ?>)</h2>
        <div>
            <a href="index.php?screen=export_csv&id=<?= h($surveyId) ?>" class="btn btn-secondary btn-sm">CSV出力</a>
            <a href="index.php?screen=export_pdf&id=<?= h($surveyId) ?>" class="btn btn-secondary btn-sm">サマリー出力</a>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
        <div class="card" style="text-align: center;">
            <div style="color: var(--gray); font-size: 0.875rem;">送信対象者数</div>
            <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalSent ?></div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="color: var(--gray); font-size: 0.875rem;">回答数</div>
            <div style="font-size: 1.5rem; font-weight: 700;"><?= $respCount ?></div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="color: var(--gray); font-size: 0.875rem;">回答率</div>
            <div style="font-size: 1.5rem; font-weight: 700;"><?= $respRate ?>%</div>
        </div>
    </div>

    <?php if ($respCount === 0): ?>
        <div class="card" style="text-align: center; color: var(--gray); padding: 3rem;">
            現在、回答データはありません
        </div>
    <?php else: ?>
        <div class="card">
            <h3>設問別集計</h3>
            <?php foreach ($currentSurvey['groups'] ?? [] as $g): ?>
                <h4 style="margin-top: 1.5rem; border-bottom: 1px solid var(--border); padding-bottom: 0.25rem;"><?= h($g['title']) ?></h4>
                <?php foreach ($g['questions'] ?? [] as $q): ?>
                    <div style="margin-top: 1rem;">
                        <p><strong><?= h($q['numberStr'] ?? '') ?>. <?= h($q['title']) ?></strong></p>
                        <?php if ($q['type'] === 'single' || $q['type'] === 'multiple'): 
                            $counts = [];
                            foreach ($q['choices'] ?? [] as $c) { $counts[$c['text']] = 0; }
                            foreach ($targetResponses as $r) {
                                $ans = $r['answers'][$q['id']] ?? null;
                                if (is_array($ans)) {
                                    foreach ($ans as $a) { if (isset($counts[$a])) $counts[$a]++; }
                                } elseif ($ans && isset($counts[$ans])) {
                                    $counts[$ans]++;
                                }
                            }
                        ?>
                            <div style="margin-left: 1rem; margin-top: 0.5rem;">
                                <?php foreach ($counts as $cText => $cnt): 
                                    $pct = ($respCount > 0) ? round(($cnt / $respCount) * 100, 1) : 0;
                                ?>
                                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem; font-size: 0.875rem;">
                                        <div style="width: 150px;"><?= h($cText) ?></div>
                                        <div style="flex: 1; background: var(--gray-light); height: 16px; border-radius: 8px; overflow: hidden;">
                                            <div style="width: <?= $pct ?>%; background: var(--primary); height: 100%;"></div>
                                        </div>
                                        <div style="width: 80px; text-align: right;"><?= $cnt ?>件 (<?= $pct ?>%)</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="margin-left: 1rem; margin-top: 0.5rem; max-height: 150px; overflow-y: auto; background: var(--gray-light); padding: 0.5rem; border-radius: 4px;">
                                <?php foreach ($targetResponses as $r): 
                                    $txt = trim($r['answers'][$q['id']] ?? '');
                                    if ($txt !== ''):
                                ?>
                                    <div style="border-bottom: 1px dashed var(--border); padding: 0.25rem 0; font-size: 0.875rem;">
                                        <?= nl2br(h($txt)) ?>
                                    </div>
                                <?php endif; endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <h3>個別回答一覧</h3>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>回答ID</th>
                            <th>回答日時</th>
                            <th>顧客ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($targetResponses as $r): ?>
                        <tr>
                            <td><?= h($r['id']) ?></td>
                            <td><?= h($r['submittedAt']) ?></td>
                            <td><?= h($r['customerId'] ?: '未登録') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php
    // ==========================================
    // 画面6: kintone連携設定 (screen=kintone)
    // ==========================================
    elseif ($screen === 'kintone'):
        $fields = $_SESSION['kintone_fields'] ?? [];
    ?>
    <div class="card">
        <h2>kintone連携設定</h2>
    </div>

    <div class="card">
        <form method="POST" action="index.php?screen=kintone">
            <input type="hidden" name="action" value="kintone_save_config">
            <div class="form-group">
                <label>サブドメイン * (例: xxxx または xxxx.cybozu.com)</label>
                <input type="text" name="subdomain" class="form-control" required value="<?= h($kintoneConfig['subdomain']) ?>">
            </div>
            <div class="form-group">
                <label>顧客管理アプリID *</label>
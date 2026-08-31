<?php
/**
 * アンケートアプリ (POC)
 * 単一ファイル構成: index.php
 */

// ============================================================
// 初期化・セッション・定数設定
// ============================================================
ini_set('display_errors', '0');
error_reporting(E_ALL);

// セッション設定 (CSRF・認証目的ではなく回答一時保持用)
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

define('DATA_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'data');
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
}

// ============================================================
// データ永続化ヘルパー (JSON + 排他ロック)
// ============================================================
function loadData(string $filename, array $default = []): array {
    $filePath = DATA_DIR . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($filePath)) {
        return $default;
    }
    $fp = fopen($filePath, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $json = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($json, true);
    return is_array($data) ? $data : $default;
}

function saveData(string $filename, array $data): bool {
    $filePath = DATA_DIR . DIRECTORY_SEPARATOR . $filename;
    $fp = fopen($filePath, 'c+');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
}

function getSurveys(): array {
    $surveys = loadData('surveys.json', []);
    // 終了日時の自動判定
    $changed = false;
    $now = date('Y-m-d H:i');
    foreach ($surveys as &$s) {
        if ($s['status'] === 'active' && !empty($s['end_at']) && $s['end_at'] < $now) {
            $s['status'] = 'closed';
            $changed = true;
        }
    }
    if ($changed) {
        saveData('surveys.json', $surveys);
    }
    return $surveys;
}

function saveSurveys(array $surveys): void {
    saveData('surveys.json', $surveys);
}

function getAnswers(string $surveyId): array {
    $allAnswers = loadData('answers.json', []);
    return $allAnswers[$surveyId] ?? [];
}

function addAnswer(string $surveyId, array $record): void {
    $allAnswers = loadData('answers.json', []);
    if (!isset($allAnswers[$surveyId])) {
        $allAnswers[$surveyId] = [];
    }
    $allAnswers[$surveyId][] = $record;
    saveData('answers.json', $allAnswers);
}

function getKintoneSettings(): array {
    return loadData('kintone_settings.json', [
        'subdomain' => '',
        'app_id' => '',
        'login_name' => '',
        'proxy' => '',
        'ssl_verify' => '1',
        'mappings' => [
            'org_name' => '',
            'name' => '',
            'email' => '',
            'dept_name' => '',
            'tel' => '',
            'address' => []
        ]
    ]);
}

function getSmtpSettings(): array {
    return loadData('smtp_settings.json', [
        'host' => '',
        'port' => '587',
        'encryption' => 'tls',
        'auth' => '1',
        'user' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
        'status' => '未設定'
    ]);
}

function getCustomers(): array {
    return loadData('customers.json', []);
}

function getSendLogs(string $surveyId): array {
    $logs = loadData('send_logs.json', []);
    return $logs[$surveyId] ?? [];
}

function addSendLog(string $surveyId, array $log): void {
    $logs = loadData('send_logs.json', []);
    if (!isset($logs[$surveyId])) {
        $logs[$surveyId] = [];
    }
    $logs[$surveyId][] = $log;
    saveData('send_logs.json', $logs);
}

// ============================================================
// 質問採番・再計算ロジック
// ============================================================
function recalculateQuestionNumbers(array &$groups, string $numberingType): void {
    $globalIndex = 1;
    foreach ($groups as $gIndex => &$group) {
        $groupNum = $gIndex + 1;
        $qIndex = 1;
        if (!isset($group['questions']) || !is_array($group['questions'])) {
            $group['questions'] = [];
        }
        foreach ($group['questions'] as &$question) {
            if ($numberingType === 'group') {
                $question['number'] = "Q{$groupNum}-{$qIndex}";
            } else {
                $question['number'] = "Q{$globalIndex}";
            }
            $globalIndex++;
            $qIndex++;
        }
    }
}

// ============================================================
// 外部通信: kintone REST API (cURLなし: stream_context使用)
// ============================================================
function callKintoneApi(string $endpoint, array $settings, string $password, string $method = 'GET', ?array $data = null): array {
    $subdomain = trim($settings['subdomain'] ?? '');
    $subdomain = preg_replace('#^https?://#', '', $subdomain);
    $subdomain = explode('.', $subdomain)[0];
    if (empty($subdomain)) {
        return ['success' => false, 'error' => 'サブドメインが正しく指定されていません。'];
    }

    $url = "https://{$subdomain}.cybozu.com/k/v1/{$endpoint}";
    $auth = base64_encode(($settings['login_name'] ?? '') . ':' . $password);

    $headers = [
        "X-Cybozu-Authorization: {$auth}",
        "Content-Type: application/json"
    ];

    $sslVerify = !empty($settings['ssl_verify']) && $settings['ssl_verify'] === '1';
    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 15,
            'follow_location' => 0
        ],
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify
        ]
    ];

    if (!empty($settings['proxy'])) {
        $contextOptions['http']['proxy'] = 'tcp://' . $settings['proxy'];
        $contextOptions['http']['request_fulluri'] = true;
    }

    if ($data !== null && $method !== 'GET') {
        $contextOptions['http']['content'] = json_encode($data);
    }

    $context = stream_context_create($contextOptions);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return ['success' => false, 'error' => '接続エラーまたはタイムアウトが発生しました。'];
    }

    $statusLine = $http_response_header[0] ?? '';
    preg_match('{HTTP\/\S*\s(\d{3})}', $statusLine, $match);
    $statusCode = (int)($match[1] ?? 0);

    if ($statusCode === 302 || $statusCode === 303) {
        return ['success' => false, 'error' => "予期しないリダイレクト ({$statusCode}) が発生しました。"];
    }

    $body = json_decode($response, true);
    if ($statusCode === 200) {
        return ['success' => true, 'data' => $body];
    }

    if ($statusCode === 401 || $statusCode === 403) {
        return ['success' => false, 'error' => '認証エラー: ログイン名またはパスワードが無効です。'];
    }

    $errMsg = $body['message'] ?? "HTTPエラー ({$statusCode})";
    if (!empty($body['errors'])) {
        $errMsg .= ' : ' . json_encode($body['errors'], JSON_UNESCAPED_UNICODE);
    }
    return ['success' => false, 'error' => $errMsg];
}

// ============================================================
// 外部通信: SMTP 送信 (PHP mail()なし・fsockopenソケット通信)
// ============================================================
function sendSmtpEmail(array $settings, string $password, string $toEmail, string $subject, string $body): array {
    $host = trim($settings['host'] ?? '');
    $port = (int)($settings['port'] ?? 587);
    $encryption = $settings['encryption'] ?? 'tls';
    $timeout = 15;

    $socketHost = ($encryption === 'ssl') ? "ssl://{$host}" : $host;
    $socket = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);

    if (!$socket) {
        return ['success' => false, 'error' => "SMTP接続失敗: {$errstr} ({$errno})"];
    }

    stream_set_timeout($socket, $timeout);

    $getResponse = function() use ($socket) {
        $data = '';
        while ($line = fgets($socket, 512)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };

    $sendCommand = function(string $cmd) use ($socket, $getResponse) {
        fputs($socket, $cmd . "\r\n");
        return $getResponse();
    };

    $initRes = $getResponse();
    if (substr($initRes, 0, 3) !== '220') {
        fclose($socket);
        return ['success' => false, 'error' => "接続応答エラー: {$initRes}"];
    }

    $heloRes = $sendCommand("EHLO localhost");

    if ($encryption === 'tls') {
        $startTls = $sendCommand("STARTTLS");
        if (substr($startTls, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'error' => "STARTTLS失敗: {$startTls}"];
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['success' => false, 'error' => "TLS暗号化ハンドシェイク失敗"];
        }
        $heloRes = $sendCommand("EHLO localhost");
    }

    if (!empty($settings['auth'])) {
        $authRes = $sendCommand("AUTH LOGIN");
        if (substr($authRes, 0, 3) !== '334') {
            fclose($socket);
            return ['success' => false, 'error' => "AUTH LOGIN要求失敗: {$authRes}"];
        }
        $userRes = $sendCommand(base64_encode($settings['user'] ?? ''));
        if (substr($userRes, 0, 3) !== '334') {
            fclose($socket);
            return ['success' => false, 'error' => "SMTPユーザー名認証失敗: {$userRes}"];
        }
        $passRes = $sendCommand(base64_encode($password));
        if (substr($passRes, 0, 3) !== '235') {
            fclose($socket);
            return ['success' => false, 'error' => "SMTPパスワード認証失敗: {$passRes}"];
        }
    }

    $from = $settings['from_email'] ?? '';
    $fromCmd = $sendCommand("MAIL FROM:<{$from}>");
    if (substr($fromCmd, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'error' => "MAIL FROM失敗: {$fromCmd}"];
    }

    $rcptCmd = $sendCommand("RCPT TO:<{$toEmail}>");
    if (substr($rcptCmd, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'error' => "RCPT TO失敗 ({$toEmail}): {$rcptCmd}"];
    }

    $dataCmd = $sendCommand("DATA");
    if (substr($dataCmd, 0, 3) !== '354') {
        fclose($socket);
        return ['success' => false, 'error' => "DATAコマンド失敗: {$dataCmd}"];
    }

    $fromNameHeader = !empty($settings['from_name']) ? "=?UTF-8?B?" . base64_encode($settings['from_name']) . "?=" : "";
    $fromFull = $fromNameHeader ? "{$fromNameHeader} <{$from}>" : $from;
    $subjectEncoded = "=?UTF-8?B?" . base64_encode($subject) . "?=";

    $headers = [
        "From: {$fromFull}",
        "To: {$toEmail}",
        "Subject: {$subjectEncoded}",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit"
    ];
    if (!empty($settings['reply_to'])) {
        $headers[] = "Reply-To: {$settings['reply_to']}";
    }

    $messageContent = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    $sendRes = $sendCommand($messageContent);
    $sendCommand("QUIT");
    fclose($socket);

    if (substr($sendRes, 0, 3) !== '250') {
        return ['success' => false, 'error' => "メッセージ送信失敗: {$sendRes}"];
    }

    return ['success' => true];
}

// ============================================================
// リクエストルーティング・状態ハンドリング
// ============================================================
$screen = $_GET['screen'] ?? 'list';
$id = $_GET['id'] ?? '';
$action = $_POST['action'] ?? '';
$message = '';
$errorMessage = '';

// ------------------------------------------------------------
// POST アクション処理
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. アンケート保存
    if ($action === 'save_survey') {
        $surveys = getSurveys();
        $surveyId = $_POST['survey_id'] ?? ('survey-' . uniqid());
        $isNew = true;
        $existingIndex = -1;

        foreach ($surveys as $idx => $s) {
            if ($s['id'] === $surveyId) {
                $isNew = false;
                $existingIndex = $idx;
                break;
            }
        }

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $start_at = $_POST['start_at'] ?? '';
        $end_at = $_POST['end_at'] ?? '';
        $numbering = $_POST['numbering'] ?? 'global';
        $status = $_POST['status'] ?? 'draft';

        $groupsJson = $_POST['groups_json'] ?? '[]';
        $groups = json_decode($groupsJson, true) ?: [];

        recalculateQuestionNumbers($groups, $numbering);

        $surveyData = [
            'id' => $surveyId,
            'title' => $title ?: '無題のアンケート',
            'description' => $description,
            'start_at' => $start_at,
            'end_at' => $end_at,
            'numbering' => $numbering,
            'status' => $isNew ? 'draft' : $status,
            'groups' => $groups,
            'updated_at' => date('Y-m-d H:i:s'),
            'created_at' => $isNew ? date('Y-m-d H:i:s') : ($surveys[$existingIndex]['created_at'] ?? date('Y-m-d H:i:s'))
        ];

        if ($isNew) {
            $surveys[] = $surveyData;
        } else {
            // 状態変更制限チェック
            $oldStatus = $surveys[$existingIndex]['status'];
            if ($oldStatus === 'closed') {
                $surveyData['status'] = 'closed'; // 終了からは変更不可
            } else {
                $surveyData['status'] = $status;
            }
            $surveys[$existingIndex] = $surveyData;
        }

        saveSurveys($surveys);
        header("Location: index.php?screen=list");
        exit;
    }

    // 2. 複製
    if ($action === 'duplicate_survey') {
        $surveys = getSurveys();
        $targetId = $_POST['target_id'] ?? '';
        foreach ($surveys as $s) {
            if ($s['id'] === $targetId) {
                $newS = $s;
                $newS['id'] = 'survey-' . uniqid();
                $newS['title'] = $s['title'] . ' (コピー)';
                $newS['status'] = 'draft';
                $newS['created_at'] = date('Y-m-d H:i:s');
                $newS['updated_at'] = date('Y-m-d H:i:s');
                $surveys[] = $newS;
                saveSurveys($surveys);
                break;
            }
        }
        header("Location: index.php?screen=list");
        exit;
    }

    // 3. 削除
    if ($action === 'delete_survey') {
        $surveys = getSurveys();
        $targetId = $_POST['target_id'] ?? '';
        $surveys = array_values(array_filter($surveys, fn($s) => $s['id'] !== $targetId));
        saveSurveys($surveys);
        header("Location: index.php?screen=list");
        exit;
    }

    // 4. kintone 設定保存・接続テスト・項目取得・同期
    if ($action === 'save_kintone_settings') {
        $settings = [
            'subdomain' => trim($_POST['subdomain'] ?? ''),
            'app_id' => trim($_POST['app_id'] ?? ''),
            'login_name' => trim($_POST['login_name'] ?? ''),
            'proxy' => trim($_POST['proxy'] ?? ''),
            'ssl_verify' => isset($_POST['ssl_verify']) ? '1' : '0',
            'mappings' => [
                'org_name' => $_POST['map_org'] ?? '',
                'name' => $_POST['map_name'] ?? '',
                'email' => $_POST['map_email'] ?? '',
                'dept_name' => $_POST['map_dept'] ?? '',
                'tel' => $_POST['map_tel'] ?? '',
                'address' => isset($_POST['map_address']) ? (array)$_POST['map_address'] : []
            ]
        ];
        saveData('kintone_settings.json', $settings);
        $message = "kintone設定を保存しました。";
    }

    if ($action === 'test_kintone') {
        $settings = getKintoneSettings();
        $pass = $_POST['kintone_password'] ?? '';
        $res = callKintoneApi("app.json?id=" . urlencode($settings['app_id']), $settings, $pass);
        if ($res['success']) {
            $message = "kintone接続テストに成功しました。(アプリ名: " . htmlspecialchars($res['data']['name'] ?? '') . ")";
        } else {
            $errorMessage = "kintone接続テスト失敗: " . htmlspecialchars($res['error']);
        }
    }

    if ($action === 'fetch_kintone_fields') {
        $settings = getKintoneSettings();
        $pass = $_POST['kintone_password'] ?? '';
        $res = callKintoneApi("app/form/fields.json?app=" . urlencode($settings['app_id']), $settings, $pass);
        if ($res['success']) {
            saveData('kintone_fields.json', $res['data']['properties'] ?? []);
            $message = "項目一覧を取得・更新しました。";
        } else {
            $errorMessage = "項目取得失敗: " . htmlspecialchars($res['error']);
        }
    }

    if ($action === 'sync_kintone_customers') {
        $settings = getKintoneSettings();
        $pass = $_POST['kintone_password'] ?? '';
        $res = callKintoneApi("records.json?app=" . urlencode($settings['app_id']), $settings, $pass);
        if ($res['success']) {
            $records = $res['data']['records'] ?? [];
            $customers = [];
            $map = $settings['mappings'];
            foreach ($records as $r) {
                $addrParts = [];
                if (!empty($map['address'])) {
                    foreach ((array)$map['address'] as $fKey) {
                        if (!empty($r[$fKey]['value'])) {
                            $addrParts[] = $r[$fKey]['value'];
                        }
                    }
                }
                $customers[] = [
                    'id' => $r['$id']['value'] ?? uniqid(),
                    'org_name' => $r[$map['org_name']]['value'] ?? '',
                    'name' => $r[$map['name']]['value'] ?? '',
                    'email' => $r[$map['email']]['value'] ?? '',
                    'dept_name' => $r[$map['dept_name']]['value'] ?? '',
                    'tel' => $r[$map['tel']]['value'] ?? '',
                    'address' => implode(' ', $addrParts)
                ];
            }
            saveData('customers.json', $customers);
            $message = count($customers) . "件の顧客情報を同期しました。";
        } else {
            $errorMessage = "顧客同期失敗: " . htmlspecialchars($res['error']);
        }
    }

    // 5. SMTP 設定保存・テスト・メール送信
    if ($action === 'save_smtp_settings') {
        $current = getSmtpSettings();
        $settings = [
            'host' => trim($_POST['host'] ?? ''),
            'port' => trim($_POST['port'] ?? '587'),
            'encryption' => $_POST['encryption'] ?? 'tls',
            'auth' => isset($_POST['auth']) ? '1' : '0',
            'user' => trim($_POST['user'] ?? ''),
            'from_email' => trim($_POST['from_email'] ?? ''),
            'from_name' => trim($_POST['from_name'] ?? ''),
            'reply_to' => trim($_POST['reply_to'] ?? ''),
            'status' => $current['status'] ?? '未設定'
        ];
        saveData('smtp_settings.json', $settings);
        $message = "SMTP設定を保存しました。";
    }

    if ($action === 'test_smtp') {
        $settings = getSmtpSettings();
        $pass = $_POST['smtp_password'] ?? '';
        $testEmail = $_POST['test_email'] ?? $settings['from_email'];
        $res = sendSmtpEmail($settings, $pass, $testEmail, "【テスト】SMTP接続確認", "これは接続テストメールです。");
        if ($res['success']) {
            $settings['status'] = '接続確認済み';
            $message = "接続テストおよびテストメール送信に成功しました。";
        } else {
            $settings['status'] = '接続できません';
            $errorMessage = "SMTP接続失敗: " . htmlspecialchars($res['error']);
        }
        saveData('smtp_settings.json', $settings);
    }

    // 6. 送信画面からのメール一括送信
    if ($action === 'send_survey_emails') {
        $surveyId = $_POST['survey_id'] ?? '';
        $surveys = getSurveys();
        $survey = null;
        foreach ($surveys as $s) { if ($s['id'] === $surveyId) { $survey = $s; break; } }

        $selectedCustomerIds = $_POST['selected_customers'] ?? [];
        $subjectTemplate = $_POST['subject'] ?? '';
        $bodyTemplate = $_POST['body'] ?? '';
        $smtpPass = $_POST['smtp_password'] ?? '';

        $settings = getSmtpSettings();
        $customers = getCustomers();
        $targetCustomers = array_filter($customers, fn($c) => in_array($c['id'], $selectedCustomerIds));

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptPath = $_SERVER['PHP_SELF'];

        $successCount = 0;
        $failCount = 0;

        foreach ($targetCustomers as $cust) {
            $surveyUrl = "{$proto}{$host}{$scriptPath}?screen=answer&id={$surveyId}&cid=" . urlencode($cust['id']);
            $mailSubject = str_replace(['{顧客名}', '{アンケートURL}'], [$cust['name'], $surveyUrl], $subjectTemplate);
            $mailBody = str_replace(['{顧客名}', '{アンケートURL}'], [$cust['name'], $surveyUrl], $bodyTemplate);

            $res = sendSmtpEmail($settings, $smtpPass, $cust['email'], $mailSubject, $mailBody);

            $log = [
                'date' => date('Y-m-d H:i:s'),
                'customer_id' => $cust['id'],
                'customer_name' => $cust['name'],
                'email' => $cust['email'],
                'status' => $res['success'] ? '送信成功' : '失敗',
                'error' => $res['error'] ?? ''
            ];
            addSendLog($surveyId, $log);

            if ($res['success']) {
                $successCount++;
            } else {
                $failCount++;
            }
        }
        $message = "送信処理が完了しました。(成功: {$successCount}件, 失敗: {$failCount}件)";
    }

    // 7. 回答送信 (回答者フロー)
    if ($action === 'submit_answer') {
        $surveyId = $_POST['survey_id'] ?? '';
        $customerId = $_POST['customer_id'] ?? '';
        $rawAnswers = $_POST['ans'] ?? [];

        $record = [
            'id' => 'ans-' . uniqid(),
            'customer_id' => $customerId,
            'submitted_at' => date('Y-m-d H:i:s'),
            'data' => $rawAnswers
        ];
        addAnswer($surveyId, $record);
        unset($_SESSION['temp_answers'][$surveyId]);
        header("Location: index.php?screen=complete&id=" . urlencode($surveyId));
        exit;
    }

    // 8. 回答一時確認 (回答確認画面へ)
    if ($action === 'confirm_answer') {
        $surveyId = $_POST['survey_id'] ?? '';
        $_SESSION['temp_answers'][$surveyId] = $_POST['ans'] ?? [];
        $_SESSION['temp_customer_id'][$surveyId] = $_POST['customer_id'] ?? '';
        header("Location: index.php?screen=confirm&id=" . urlencode($surveyId));
        exit;
    }
}

// ============================================================
// 画面別レンダリング
// ============================================================
function renderHeader(string $title, bool $isAdmin = true) {
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg: #f8fafc;
            --surface: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; }
        .admin-nav { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.75rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        .admin-nav .brand { font-weight: bold; font-size: 1.1rem; color: var(--primary); text-decoration: none; }
        .admin-nav .links { display: flex; gap: 1rem; }
        .admin-nav .links a { color: var(--text-muted); text-decoration: none; font-size: 0.9rem; padding: 0.25rem 0.5rem; border-radius: 4px; }
        .admin-nav .links a:hover, .admin-nav .links a.active { color: var(--primary); background: #eff6ff; }
        .container { max-width: 1100px; margin: 2rem auto; padding: 0 1rem; }
        .respondent-container { max-width: 600px; margin: 1.5rem auto; padding: 0 1rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-header { font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 500; border-radius: 6px; border: 1px solid transparent; cursor: pointer; text-decoration: none; transition: background 0.15s; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: #f1f5f9; color: var(--text); border-color: var(--border); }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.8rem; }
        .badge { display: inline-block; padding: 0.2rem 0.5rem; font-size: 0.75rem; font-weight: 600; border-radius: 9999px; }
        .badge-draft { background: #f1f5f9; color: #475569; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-paused { background: #fef3c7; color: #92400e; }
        .badge-closed { background: #fee2e2; color: #991b1b; }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.35rem; }
        .form-control { width: 100%; padding: 0.5rem 0.75rem; font-size: 0.9rem; border: 1px solid var(--border); border-radius: 6px; background: #fff; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(37,99,235,0.2); }
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th, td { padding: 0.75rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        th { background: #f8fafc; font-weight: 600; color: var(--text-muted); }
        .alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-size: 0.9rem; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .flex { display: flex; gap: 0.5rem; }
        .justify-between { justify-content: space-between; }
        .items-center { align-items: center; }
    </style>
</head>
<body>
<?php if ($isAdmin): ?>
<header class="admin-nav">
    <a href="index.php?screen=list" class="brand">アンケート管理システム</a>
    <nav class="links">
        <a href="index.php?screen=list" class="<?= in_array($GLOBALS['screen'], ['list','edit','analytics','send']) ? 'active' : '' ?>">アンケート一覧</a>
        <a href="index.php?screen=kintone" class="<?= $GLOBALS['screen'] === 'kintone' ? 'active' : '' ?>">kintone設定</a>
        <a href="index.php?screen=mail" class="<?= $GLOBALS['screen'] === 'mail' ? 'active' : '' ?>">メール設定</a>
    </nav>
</header>
<?php endif; ?>
<main class="<?= $isAdmin ? 'container' : 'respondent-container' ?>">
<?php if (!empty($GLOBALS['message'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($GLOBALS['message']) ?></div>
<?php endif; ?>
<?php if (!empty($GLOBALS['errorMessage'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($GLOBALS['errorMessage']) ?></div>
<?php endif; ?>
<?php
}

function renderFooter() {
?>
</main>
</body>
</html>
<?php
}

// ============================================================
// 各画面実装
// ============================================================

// 1. アンケート一覧画面
if ($screen === 'list') {
    renderHeader('アンケート一覧');
    $surveys = getSurveys();
    $statusFilter = $_GET['status'] ?? 'all';
    $searchQuery = trim($_GET['q'] ?? '');
    $sort = $_GET['sort'] ?? 'updated_desc';

    if ($statusFilter !== 'all') {
        $surveys = array_filter($surveys, fn($s) => ($s['status'] ?? 'draft') === $statusFilter);
    }
    if ($searchQuery !== '') {
        $surveys = array_filter($surveys, fn($s) => stripos($s['title'], $searchQuery) !== false);
    }

    // ソート
    usort($surveys, function($a, $b) use ($sort) {
        $ansCountA = count(getAnswers($a['id']));
        $ansCountB = count(getAnswers($b['id']));
        switch ($sort) {
            case 'updated_asc': return strcmp($a['updated_at'] ?? '', $b['updated_at'] ?? '');
            case 'answers_desc': return $ansCountB <=> $ansCountA;
            case 'answers_asc': return $ansCountA <=> $ansCountB;
            case 'start_desc': return strcmp($b['start_at'] ?? '', $a['start_at'] ?? '');
            case 'start_asc': return strcmp($a['start_at'] ?? '', $b['start_at'] ?? '');
            case 'updated_desc':
            default: return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        }
    });

    $statusBadges = [
        'draft' => '<span class="badge badge-draft">下書き</span>',
        'active' => '<span class="badge badge-active">公開中</span>',
        'paused' => '<span class="badge badge-paused">停止</span>',
        'closed' => '<span class="badge badge-closed">終了</span>'
    ];
    ?>
    <div class="card">
        <div class="card-header">
            <h2>アンケート一覧</h2>
            <a href="index.php?screen=edit" class="btn btn-primary">+ 新規作成</a>
        </div>
        <form method="GET" action="index.php" class="flex" style="margin-bottom: 1rem; flex-wrap: wrap;">
            <input type="hidden" name="screen" value="list">
            <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="タイトルで検索 (Enter)" class="form-control" style="max-width: 250px;">
            <select name="status" class="form-control" style="max-width: 140px;" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>すべての状態</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>公開中</option>
                <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>下書き</option>
                <option value="paused" <?= $statusFilter === 'paused' ? 'selected' : '' ?>>停止</option>
                <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>終了</option>
            </select>
            <select name="sort" class="form-control" style="max-width: 180px;" onchange="this.form.submit()">
                <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>更新日: 新しい順</option>
                <option value="updated_asc" <?= $sort === 'updated_asc' ? 'selected' : '' ?>>更新日: 古い順</option>
                <option value="answers_desc" <?= $sort === 'answers_desc' ? 'selected' : '' ?>>回答数: 多い順</option>
                <option value="answers_asc" <?= $sort === 'answers_asc' ? 'selected' : '' ?>>回答数: 少ない順</option>
                <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>開始日: 新しい順</option>
                <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>開始日: 古い順</option>
            </select>
        </form>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>タイトル</th>
                        <th>状態</th>
                        <th>期間</th>
                        <th>回答数</th>
                        <th>更新日時</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($surveys)): ?>
                    <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">アンケートが見つかりません。</td></tr>
                <?php else: ?>
                    <?php foreach ($surveys as $s): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($s['title']) ?></strong></td>
                        <td><?= $statusBadges[$s['status']] ?? $s['status'] ?></td>
                        <td><small><?= htmlspecialchars($s['start_at'] ?: '未設定') ?> ~ <?= htmlspecialchars($s['end_at'] ?: '未設定') ?></small></td>
                        <td><?= count(getAnswers($s['id'])) ?></td>
                        <td><small><?= htmlspecialchars($s['updated_at'] ?? '') ?></small></td>
                        <td>
                            <div class="flex">
                                <a href="index.php?screen=edit&id=<?= urlencode($s['id']) ?>" class="btn btn-secondary btn-sm">編集</a>
                                <a href="index.php?screen=preview&id=<?= urlencode($s['id']) ?>" class="btn btn-secondary btn-sm" target="_blank">プレビュー</a>
                                <a href="index.php?screen=analytics&id=<?= urlencode($s['id']) ?>" class="btn btn-secondary btn-sm">集計</a>
                                <a href="index.php?screen=send&id=<?= urlencode($s['id']) ?>" class="btn btn-secondary btn-sm">送信</a>
                                <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('複製しますか？');">
                                    <input type="hidden" name="action" value="duplicate_survey">
                                    <input type="hidden" name="target_id" value="<?= htmlspecialchars($s['id']) ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">複製</button>
                                </form>
                                <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('本当に削除しますか？');">
                                    <input type="hidden" name="action" value="delete_survey">
                                    <input type="hidden" name="target_id" value="<?= htmlspecialchars($s['id']) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">削除</button>
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
    renderFooter();
    exit;
}

// 2. 作成・編集画面
if ($screen === 'edit') {
    renderHeader('アンケート作成・編集');
    $surveys = getSurveys();
    $survey = null;
    $isNew = empty($id);
    if (!$isNew) {
        foreach ($surveys as $s) {
            if ($s['id'] === $id) { $survey = $s; break; }
        }
    }
    if (!$survey) {
        $survey = [
            'id' => 'survey-' . uniqid(),
            'title' => '',
            'description' => '',
            'start_at' => '',
            'end_at' => '',
            'numbering' => 'global',
            'status' => 'draft',
            'groups' => [
                [
                    'id' => 'grp-1',
                    'title' => '基本質問',
                    'questions' => []
                ]
            ]
        ];
    }
    ?>
    <div class="card">
        <form method="POST" action="index.php" id="editSurveyForm">
            <input type="hidden" name="action" value="save_survey">
            <input type="hidden" name="survey_id" value="<?= htmlspecialchars($survey['id']) ?>">
            <input type="hidden" name="groups_json" id="groups_json" value="">

            <div class="card-header">
                <h2><?= $isNew ? 'アンケート新規作成' : 'アンケート編集' ?></h2>
                <div class="flex">
                    <button type="button" class="btn btn-secondary" onclick="if(confirm('編集内容を破棄して一覧に戻りますか？')) location.href='index.php?screen=list'">キャンセル</button>
                    <button type="button" class="btn btn-primary" onclick="submitSurveyForm()">保存して一覧へ</button>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">タイトル</label>
                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($survey['title']) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">説明文</label>
                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($survey['description']) ?></textarea>
            </div>

            <div class="flex" style="gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">開始日時</label>
                    <input type="datetime-local" name="start_at" class="form-control" value="<?= htmlspecialchars($survey['start_at']) ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">終了日時</label>
                    <input type="datetime-local" name="end_at" class="form-control" value="<?= htmlspecialchars($survey['end_at']) ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">採番方式</label>
                    <select name="numbering" id="numberingSelect" class="form-control" onchange="renderGroups()">
                        <option value="global" <?= ($survey['numbering'] ?? '') === 'global' ? 'selected' : '' ?>>全体通番 (Q1, Q2...)</option>
                        <option value="group" <?= ($survey['numbering'] ?? '') === 'group' ? 'selected' : '' ?>>グループ単位 (Q1-1, Q1-2...)</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">状態</label>
                    <select name="status" class="form-control" <?= $survey['status'] === 'closed' ? 'disabled' : '' ?>>
                        <?php if ($survey['status'] === 'closed'): ?>
                            <option value="closed" selected>終了</option>
                        <?php else: ?>
                            <option value="draft" <?= $survey['status'] === 'draft' ? 'selected' : '' ?>>下書き</option>
                            <option value="active" <?= $survey['status'] === 'active' ? 'selected' : '' ?>>公開中</option>
                            <option value="paused" <?= $survey['status'] === 'paused' ? 'selected' : '' ?>>停止</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border);">

            <h3>グループ・設問構成</h3>
            <div id="groupsContainer" style="margin-top: 1rem;"></div>
            <button type="button" class="btn btn-secondary" onclick="addGroup()" style="margin-top: 1rem;">+ グループを追加</button>
        </form>
    </div>

    <script>
    let surveyGroups = <?= json_encode($survey['groups'], JSON_UNESCAPED_UNICODE) ?>;

    function renderGroups() {
        const container = document.getElementById('groupsContainer');
        const numberingType = document.getElementById('numberingSelect').value;
        container.innerHTML = '';

        let globalQIndex = 1;

        surveyGroups.forEach((group, gIdx) => {
            const gDiv = document.createElement('div');
            gDiv.className = 'card';
            gDiv.style.background = '#fcfcfc';
            gDiv.style.border = '1px solid #cbd5e1';

            let questionsHtml = '';
            group.questions = group.questions || [];
            group.questions.forEach((q, qIdx) => {
                const qNum = (numberingType === 'group') ? `Q${gIdx + 1}-${qIdx + 1}` : `Q${globalQIndex}`;
                globalQIndex++;
                q.number = qNum;

                questionsHtml += `
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:1rem; margin-bottom:0.75rem;">
                        <div class="flex justify-between items-center" style="margin-bottom:0.5rem;">
                            <strong>${qNum}</strong>
                            <div class="flex">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="moveQuestion(${gIdx}, ${qIdx}, -1)">↑</button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="moveQuestion(${gIdx}, ${qIdx}, 1)">↓</button>
                                <button type="button" class="btn btn-danger btn-sm" onclick="deleteQuestion(${gIdx}, ${qIdx})">削除</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <input type="text" class="form-control" placeholder="質問文を入力" value="${escapeHtml(q.title || '')}" oninput="surveyGroups[${gIdx}].questions[${qIdx}].title = this.value">
                        </div>
                        <div class="flex" style="gap:1rem; margin-bottom:0.5rem;">
                            <select class="form-control" style="flex:1;" onchange="changeQuestionType(${gIdx}, ${qIdx}, this.value)">
                                <option value="single" ${q.type === 'single' ? 'selected' : ''}>単一選択 (ラジオボタン)</option>
                                <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選択 (チェックボックス)</option>
                                <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述 (テキスト)</option>
                            </select>
                            <label style="display:flex; align-items:center; gap:0.25rem; font-size:0.875rem;">
                                <input type="checkbox" ${q.required ? 'checked' : ''} onchange="surveyGroups[${gIdx}].questions[${qIdx}].required = this.checked"> 必須
                            </label>
                        </div>
                        ${renderOptionsHtml(gIdx, qIdx, q)}
                    </div>
                `;
            });

            gDiv.innerHTML = `
                <div class="flex justify-between items-center" style="margin-bottom:1rem;">
                    <input type="text" class="form-control" style="font-weight:bold; max-width:300px;" value="${escapeHtml(group.title || '')}" oninput="surveyGroups[${gIdx}].title = this.value">
                    <div class="flex">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="moveGroup(${gIdx}, -1)">グループ↑</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="moveGroup(${gIdx}, 1)">グループ↓</button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="deleteGroup(${gIdx})">グループ削除</button>
                    </div>
                </div>
                <div>${questionsHtml}</div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="addQuestion(${gIdx})">+ 質問を追加</button>
            `;
            container.appendChild(gDiv);
        });
    }

    function renderOptionsHtml(gIdx, qIdx, q) {
        if (q.type === 'text') return '';
        let opts = q.options || [];
        let html = '<div style="margin-top:0.5rem; padding-left:0.5rem; border-left:2px solid var(--border);">';
        html += '<small style="color:var(--text-muted);">選択肢 & 分岐設定 (単一選択時)</small>';
        opts.forEach((opt, oIdx) => {
            html += `
                <div class="flex items-center" style="gap:0.5rem; margin-top:0.35rem;">
                    <input type="text" class="form-control" style="flex:2;" placeholder="選択肢" value="${escapeHtml(opt.label || '')}" oninput="surveyGroups[${gIdx}].questions[${qIdx}].options[${oIdx}].label = this.value">
                    ${q.type === 'single' ? `
                        <input type="text" class="form-control" style="flex:1;" placeholder="次に進むQ番号(任意)" value="${escapeHtml(opt.next_q || '')}" oninput="surveyGroups[${gIdx}].questions[${qIdx}].options[${oIdx}].next_q = this.value">
                    ` : ''}
                    <button type="button" class="btn btn-secondary btn-sm" onclick="deleteOption(${gIdx}, ${qIdx}, ${oIdx})">×</button>
                </div>
            `;
        });
        html += `<button type="button" class="btn btn-secondary btn-sm" style="margin-top:0.35rem;" onclick="addOption(${gIdx}, ${qIdx})">+ 選択肢追加</button></div>`;
        return html;
    }

    function addGroup() {
        surveyGroups.push({ id: 'grp-' + Date.now(), title: '新規グループ', questions: [] });
        renderGroups();
    }
    function deleteGroup(gIdx) {
        if (confirm('グループを削除しますか？')) {
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
            id: 'q-' + Date.now(),
            title: '',
            type: 'single',
            required: false,
            options: [{ label: '選択肢1', next_q: '' }]
        });
        renderGroups();
    }
    function deleteQuestion(gIdx, qIdx) {
        surveyGroups[gIdx].questions.splice(qIdx, 1);
        renderGroups();
    }
    function moveQuestion(gIdx, qIdx, dir) {
        const questions = surveyGroups[gIdx].questions;
        const target = qIdx + dir;
        if (target < 0 || target >= questions.length) return;
        const temp = questions[qIdx];
        questions[qIdx] = questions[target];
        questions[target] = temp;
        renderGroups();
    }
    function changeQuestionType(gIdx, qIdx, type) {
        const q = surveyGroups[gIdx].questions[qIdx];
        q.type = type;
        if (type !== 'text' && (!q.options || q.options.length === 0)) {
            q.options = [{ label: '選択肢1', next_q: '' }];
        }
        renderGroups();
    }
    function addOption(gIdx, qIdx) {
        surveyGroups[gIdx].questions[qIdx].options.push({ label: '', next_q: '' });
        renderGroups();
    }
    function deleteOption(gIdx, qIdx, oIdx) {
        surveyGroups[gIdx].questions[qIdx].options.splice(oIdx, 1);
        renderGroups();
    }
    function escapeHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function submitSurveyForm() {
        document.getElementById('groups_json').value = JSON.stringify(surveyGroups);
        document.getElementById('editSurveyForm').submit();
    }

    renderGroups();
    </script>
    <?php
    renderFooter();
    exit;
}

// 3. プレビュー画面 (PC/SP表示切り替え)
if ($screen === 'preview') {
    renderHeader('プレビュー', true);
    $surveys = getSurveys();
    $survey = null;
    foreach ($surveys as $s) { if ($s['id'] === $id) { $survey = $s; break; } }
    if (!$survey) { echo "<p>アンケートが見つかりません。</p>"; renderFooter(); exit; }
    ?>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
        <h2>アンケート プレビュー</h2>
        <div class="flex">
            <button class="btn btn-secondary" onclick="document.getElementById('previewFrame').style.maxWidth='100%'">PC表示</button>
            <button class="btn btn-secondary" onclick="document.getElementById('previewFrame').style.maxWidth='400px'">スマートフォン表示</button>
        </div>
    </div>
    <div id="previewFrame" style="margin: 0 auto; transition: max-width 0.3s; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem;">
        <h1 style="font-size:1.5rem; margin-bottom:0.5rem;"><?= htmlspecialchars($survey['title']) ?></h1>
        <p style="color:var(--text-muted); margin-bottom:1.5rem;"><?= nl2br(htmlspecialchars($survey['description'])) ?></p>

        <?php foreach ($survey['groups'] as $g): ?>
            <div style="margin-bottom: 1.5rem;">
                <h3 style="margin-bottom: 0.75rem; border-bottom: 1px solid var(--border); padding-bottom: 0.25rem;"><?= htmlspecialchars($g['title']) ?></h3>
                <?php foreach ($g['questions'] as $q): ?>
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display:block; font-weight:600; margin-bottom:0.5rem;">
                            <?= htmlspecialchars($q['number'] ?? '') ?>. <?= htmlspecialchars($q['title']) ?>
                            <?php if (!empty($q['required'])): ?><span style="color:var(--danger); font-size:0.8rem;">(必須)</span><?php endif; ?>
                        </label>
                        <?php if ($q['type'] === 'single'): ?>
                            <?php foreach ($q['options'] as $opt): ?>
                                <label style="display:block; margin-bottom:0.25rem;">
                                    <input type="radio" disabled> <?= htmlspecialchars($opt['label']) ?>
                                    <?php if (!empty($opt['next_q'])): ?><small style="color:var(--primary);">(→ <?= htmlspecialchars($opt['next_q']) ?>へ)</small><?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        <?php elseif ($q['type'] === 'multiple'): ?>
                            <?php foreach ($q['options'] as $opt): ?>
                                <label style="display:block; margin-bottom:0.25rem;">
                                    <input type="checkbox" disabled> <?= htmlspecialchars($opt['label']) ?>
                                </label>
                            <?php endforeach; ?>
                        <?php elseif ($q['type'] === 'text'): ?>
                            <textarea class="form-control" rows="3" disabled placeholder="自由記述欄"></textarea>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    renderFooter();
    exit;
}

// 4. 送信画面 (顧客選択・メール送信・履歴)
if ($screen === 'send') {
    renderHeader('顧客選択・メール送信');
    $surveys = getSurveys();
    $survey = null;
    foreach ($surveys as $s) { if ($s['id'] === $id) { $survey = $s; break; } }
    if (!$survey) { echo "<p>対象アンケートが指定されていません。</p>"; renderFooter(); exit; }

    $customers = getCustomers();
    $sendLogs = getSendLogs($id);
    ?>
    <div class="card">
        <div class="card-header">
            <h2>アンケート送信: <?= htmlspecialchars($survey['title']) ?></h2>
        </div>
        <form method="POST" action="index.php?screen=send&id=<?= urlencode($id) ?>">
            <input type="hidden" name="action" value="send_survey_emails">
            <input type="hidden" name="survey_id" value="<?= htmlspecialchars($survey['id']) ?>">

            <h3>1. 送信先顧客の選択</h3>
            <div class="table-responsive" style="max-height: 250px; overflow-y: auto; margin: 1rem 0; border: 1px solid var(--border);">
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" onclick="document.querySelectorAll('.cust-cb').forEach(c=>c.checked=this.checked)"></th>
                            <th>組織名</th>
                            <th>氏名</th>
                            <th>メールアドレス</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($customers)): ?>
                        <tr><td colspan="4" style="text-align:center;">顧客データがありません。kintone同期を行ってください。</td></tr>
                    <?php else: ?>
                        <?php foreach ($customers as $c): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_customers[]" value="<?= htmlspecialchars($c['id']) ?>" class="cust-cb"></td>
                            <td><?= htmlspecialchars($c['org_name']) ?></td>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td><?= htmlspecialchars($c['email']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h3>2. メールテンプレート編集</h3>
            <div class="form-group" style="margin-top:0.5rem;">
                <label class="form-label">件名</label>
                <input type="text" name="subject" class="form-control" value="【アンケートご協力のお願い】<?= htmlspecialchars($survey['title']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">本文 (利用可能変数: {顧客名}, {アンケートURL})</label>
                <textarea name="body" class="form-control" rows="6" required>{顧客名} 様

平素より大変お世話になっております。
以下のアンケートへのご協力をお願い申し上げます。

▼ 回答URL
{アンケートURL}

よろしくお願いいたします。</textarea>
            </div>

            <div class="form-group">
                <label class="form-label">SMTP送信パスワード (送信時に入力)</label>
                <input type="password" name="smtp_password" class="form-control" placeholder="SMTPパスワード" required>
            </div>

            <button type="submit" class="btn btn-primary" onclick="return confirm('選択した顧客に一括送信しますか？');">一括送信を実行</button>
        </form>

        <hr style="margin: 2rem 0; border:none; border-top:1px solid var(--border);">

        <h3>送信履歴</h3>
        <div class="table-responsive" style="margin-top:1rem;">
            <table>
                <thead>
                    <tr>
                        <th>日時</th>
                        <th>顧客名</th>
                        <th>メールアドレス</th>
                        <th>ステータス</th>
                        <th>詳細</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sendLogs)): ?>
                    <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">送信履歴はありません。</td></tr>
                <?php else: ?>
                    <?php foreach (array_reverse($sendLogs) as $log): ?>
                    <tr>
                        <td><small><?= htmlspecialchars($log['date']) ?></small></td>
                        <td><?= htmlspecialchars($log['customer_name']) ?></td>
                        <td><?= htmlspecialchars($log['email']) ?></td>
                        <td>
                            <span class="badge <?= $log['status'] === '送信成功' ? 'badge-active' : 'badge-closed' ?>">
                                <?= htmlspecialchars($log['status']) ?>
                            </span>
                        </td>
                        <td><small style="color:var(--danger);"><?= htmlspecialchars($log['error'] ?? '') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    renderFooter();
    exit;
}

// 5. 集計画面 (CSV/PDF出力対応)
if ($screen === 'analytics') {
    $surveys = getSurveys();
    $survey = null;
    foreach ($surveys as $s) { if ($s['id'] === $id) { $survey = $s; break; } }
    if (!$survey) { echo "対象アンケートが指定されていません。"; exit; }

    $answers = getAnswers($id);
    $sendLogs = getSendLogs($id);
    $totalSent = count($sendLogs);
    $totalAnswers = count($answers);

    // CSV エクスポート処理
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="survey_result_' . $id . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM

        $headerRow = ['回答ID', '回答日時', '顧客ID'];
        foreach ($survey['groups'] as $g) {
            foreach ($g['questions'] as $q) {
                $headerRow[] = $q['number'] . ':' . $q['title'];
            }
        }
        fputcsv($out, $headerRow);

        foreach ($answers as $ans) {
            $row = [$ans['id'], $ans['submitted_at'], $ans['customer_id'] ?? ''];
            foreach ($survey['groups'] as $g) {
                foreach ($g['questions'] as $q) {
                    $val = $ans['data'][$q['id']] ?? '';
                    if (is_array($val)) $val = implode(', ', $val);
                    $row[] = $val;
                }
            }
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    renderHeader('回答集計・分析');
    ?>
    <div class="card">
        <div class="card-header">
            <h2>集計・分析: <?= htmlspecialchars($survey['title']) ?></h2>
            <div class="flex">
                <a href="index.php?screen=analytics&id=<?= urlencode($id) ?>&export=csv" class="btn btn-secondary btn-sm">CSV出力</a>
                <button class="btn btn-secondary btn-sm" onclick="window.print()">印刷 / PDF保存</button>
            </div>
        </div>

        <div class="flex" style="gap:1rem; margin-bottom:1.5rem;">
            <div class="card" style="flex:1; text-align:center; margin-bottom:0;">
                <div style="font-size:0.875rem; color:var(--text-muted);">送信対象者数</div>
                <div style="font-size:1.5rem; font-weight:bold;"><?= $totalSent ?></div>
            </div>
            <div class="card" style="flex:1; text-align:center; margin-bottom:0;">
                <div style="font-size:0.875rem; color:var(--text-muted);">回答数</div>
                <div style="font-size:1.5rem; font-weight:bold;"><?= $totalAnswers ?></div>
            </div>
            <div class="card" style="flex:1; text-align:center; margin-bottom:0;">
                <div style="font-size:0.875rem; color:var(--text-muted);">回答率</div>
                <div style="font-size:1.5rem; font-weight:bold;">
                    <?= $totalSent > 0 ? round(($totalAnswers / $totalSent) * 100, 1) : 0 ?>%
                </div>
            </div>
        </div>

        <?php if ($totalAnswers === 0): ?>
            <div style="text-align:center; padding:3rem 0; color:var(--text-muted);">現在、回答データはありません</div>
        <?php else: ?>
            <h3>設問別集計</h3>
            <?php foreach ($survey['groups'] as $g): ?>
                <div style="margin-top:1.5rem;">
                    <h4><?= htmlspecialchars($g['title']) ?></h4>
                    <?php foreach ($g['questions'] as $q): ?>
                        <div style="background:#f8fafc; border:1px solid var(--border); border-radius:6px; padding:1rem; margin-top:0.5rem;">
                            <strong><?= htmlspecialchars($q['number'] ?? '') ?>. <?= htmlspecialchars($q['title']) ?></strong>
                            <?php if ($q['type'] === 'single' || $q['type'] === 'multiple'): ?>
                                <div style="margin-top:0.5rem;">
                                <?php
                                $counts = [];
                                foreach ($q['options'] as $opt) { $counts[$opt['label']] = 0; }
                                foreach ($answers as $ans) {
                                    $val = $ans['data'][$q['id']] ?? null;
                                    if (is_array($val)) {
                                        foreach ($val as $v) { if (isset($counts[$v])) $counts[$v]++; }
                                    } elseif ($val !== null && isset($counts[$val])) {
                                        $counts[$val]++;
                                    }
                                }
                                foreach ($counts as $lbl => $cnt):
                                    $pct = $totalAnswers > 0 ? round(($cnt / $totalAnswers) * 100, 1) : 0;
                                ?>
                                    <div style="margin-bottom:0.25rem; font-size:0.875rem;">
                                        <div class="flex justify-between"><span><?= htmlspecialchars($lbl) ?></span><span><?= $cnt ?>件 (<?= $pct ?>%)</span></div>
                                        <div style="background:#e2e8f0; height:8px; border-radius:4px; overflow:hidden;">
                                            <div style="background:var(--primary); height:100%; width:<?= $pct ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="margin-top:0.5rem; max-height:150px; overflow-y:auto; background:#fff; padding:0.5rem; border:1px solid var(--border);">
                                    <?php foreach ($answers as $ans): ?>
                                        <?php if (!empty($ans['data'][$q['id']])): ?>
                                            <div style="border-bottom:1px solid #f1f5f9; padding:0.25rem 0; font-size:0.875rem;"><?= htmlspecialchars($ans['data'][$q['id']]) ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    renderFooter();
    exit;
}

// 6. kintone 設定画面
if ($screen === 'kintone') {
    renderHeader('kintone設定');
    $settings = getKintoneSettings();
    $fields = loadData('kintone_fields.json', []);
    ?>
    <div class="card">
        <div class="card-header">
            <h2>kintone API 連携設定</h2>
        </div>
        <form method="POST" action="index.php?screen=kintone">
            <input type="hidden" name="action" value="save_kintone_settings">
            <div class="form-group">
                <label class="form-label">サブドメイン (URLまたはサブドメイン名)</label>
                <input type="text" name="subdomain" class="form-control" value="<?= htmlspecialchars($settings['subdomain']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">顧客管理アプリID</label>
                <input type="text" name="app_id" class="form-control" value="<?= htmlspecialchars($settings['app_id']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">ログイン名</label>
                <input type="text" name="login_name" class="form-control" value="<?= htmlspecialchars($settings['login_name']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Proxy (host:port 形式・任意)</label>
                <input type="text" name="proxy" class="form-control" value="<?= htmlspecialchars($settings['proxy']) ?>">
            </div>
            <div class="form-group">
                <label style="display:flex; align-items:center; gap:0.5rem;">
                    <input type="checkbox" name="ssl_verify" value="1" <?= $settings['ssl_verify'] === '1' ? 'checked' : '' ?>> SSL証明書検証を有効にする
                </label>
            </div>

            <hr style="margin:1.5rem 0; border:none; border-top:1px solid var(--border);">

            <h3>項目マッピング設定</h3>
            <p style="font-size:0.875rem; color:var(--text-muted); margin-bottom:1rem;">kintoneのフィールドコードを指定してください。</p>

            <div class="flex" style="gap:1rem; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label class="form-label">組織名</label>
                    <input type="text" name="map_org" class="form-control" value="<?= htmlspecialchars($settings['mappings']['org_name'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label class="form-label">氏名</label>
                    <input type="text" name="map_name" class="form-control" value="<?= htmlspecialchars($settings['mappings']['name'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label class="form-label">メールアドレス</label>
                    <input type="text" name="map_email" class="form-control" value="<?= htmlspecialchars($settings['mappings']['email'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label class="form-label">部署名</label>
                    <input type="text" name="map_dept" class="form-control" value="<?= htmlspecialchars($settings['mappings']['dept_name'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label class="form-label">電話番号</label>
                    <input type="text" name="map_tel" class="form-control" value="<?= htmlspecialchars($settings['mappings']['tel'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label class="form-label">住所 (カンマ区切りで複数可)</label>
                    <input type="text" name="map_address[]" class="form-control" value="<?= htmlspecialchars(implode(',', (array)($settings['mappings']['address'] ?? []))) ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">設定を保存</button>
        </form>

        <hr style="margin:2rem 0; border:none; border-top:1px solid var(--border);">

        <h3>独立操作 (パスワードを入力して実行)</h3>
        <div class="flex" style="gap:1rem; margin-top:1rem; flex-wrap:wrap;">
            <form method="POST" action="index.php?screen=kintone" style="border:1px solid var(--border); padding:1rem; border-radius:6px; flex:1;">
                <input type="hidden" name="action" value="test_kintone">
                <strong>接続テスト</strong>
                <input type="password" name="kintone_password" class="form-control" placeholder="kintoneパスワード" style="margin:0.5rem 0;" required>
                <button type="submit" class="btn btn-secondary btn-sm">接続テスト実行</button>
            </form>

            <form method="POST" action="index.php?screen=kintone" style="border:1px solid var(--border); padding:1rem; border-radius:6px; flex:1;">
                <input type="hidden" name="action" value="fetch_kintone_fields">
                <strong>項目一覧再取得</strong>
                <input type="password" name="kintone_password" class="form-control" placeholder="kintoneパスワード" style="margin:0.5rem 0;" required>
                <button type="submit" class="btn btn-secondary btn-sm">項目再取得実行</button>
            </form>

            <form method="POST" action="index.php?screen=kintone" style="border:1px solid var(--border); padding:1rem; border-radius:6px; flex:1;">
                <input type="hidden" name="action" value="sync_kintone_customers">
                <strong>顧客情報同期</strong>
                <input type="password" name="kintone_password" class="form-control" placeholder="kintoneパスワード" style="margin:0.5rem 0;" required>
                <button type="submit" class="btn btn-secondary btn-sm">顧客同期実行</button>
            </form>
        </div>
    </div>
    <?php
    renderFooter();
    exit;
}

// 7. メールサーバ設定画面
if ($screen === 'mail') {
    renderHeader('メールサーバ設定');
    $settings = getSmtpSettings();
    ?>
    <div class="card">
        <div class="card-header">
            <h2>SMTP サーバ設定</h2>
            <span class="badge <?= $settings['status'] === '接続確認済み' ? 'badge-active' : 'badge-closed' ?>"><?= htmlspecialchars($settings['status']) ?></span>
        </div>
        <form method="POST" action="index.php?screen=mail">
            <input type="hidden" name="action" value="save_smtp_settings">
            <div class="flex" style="gap:1rem;">
                <div class="form-group" style="flex:2;">
                    <label class="form-label">SMTPサーバ</label>
                    <input type="text" name="host" class="form-control" value="<?= htmlspecialchars($settings['host']) ?>" required>
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">ポート番号</label>
                    <input type="text" name="port" class="form-control" value="<?= htmlspecialchars($settings['port']) ?>" required>
                </div>
            </div>
            <div class="flex" style="gap:1rem;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">暗号化方式</label>
                    <select name="encryption" class="form-control">
                        <option value="tls" <?= $settings['encryption'] === 'tls' ? 'selected' : '' ?>>TLS (推奨)</option>
                        <option value="ssl" <?= $settings['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= $settings['encryption'] === 'none' ? 'selected' :
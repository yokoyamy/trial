<?php
declare(strict_types=1);

namespace App\SurveySystem;

// セッション・セキュリティヘッダー設定
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

// CSRFトークン初期化
if (empty($_SESSION['survey_csrf_token'])) {
    $_SESSION['survey_csrf_token'] = bin2hex(random_bytes(32));
}

// NULL安全なHTMLエスケープ関数
function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// データディレクトリの初期化
const DATA_DIR = __DIR__ . '/data';
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
}

// JSONデータ操作ヘルパー (排他ロック付き)
function loadJson(string $filename, array $default = []): array {
    $path = DATA_DIR . '/' . $filename;
    if (!file_exists($path)) {
        return $default;
    }
    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return $default;
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

function saveJson(string $filename, array $data): bool {
    $path = DATA_DIR . '/' . $filename;
    $fp = @fopen($path, 'c+');
    if (!$fp) return false;
    
    $success = false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (fwrite($fp, $json) !== false) {
            fflush($fp);
            $success = true;
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $success;
}

// JSONレスポンス送信
function sendJsonResponse(array $data, int $statusCode = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// kintone API リクエスト (cURL不使用、stream_context使用)
function kintoneRequest(string $method, string $url, array $params, array $settings): array {
    $host = preg_replace('#^https?://#', '', trim($settings['host'] ?? ''));
    $host = preg_replace('#\.cybozu\.com.*$#', '', $host);
    $fullUrl = "https://{$host}.cybozu.com" . $url;
    
    $auth = base64_encode(($settings['login'] ?? '') . ':' . ($settings['pass'] ?? ''));
    $headers = [
        "X-Cybozu-Authorization: {$auth}",
        "Host: {$host}.cybozu.com"
    ];

    $opts = [
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ];

    if (!empty($settings['proxyHost']) && !empty($settings['proxyPort'])) {
        $opts['http']['proxy'] = 'tcp://' . $settings['proxyHost'] . ':' . $settings['proxyPort'];
        $opts['http']['request_fulluri'] = true;
    }

    if ($method === 'GET') {
        if (!empty($params)) {
            $fullUrl .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
    } else {
        $headers[] = 'Content-Type: application/json';
        $opts['http']['content'] = json_encode($params);
    }

    $opts['http']['header'] = implode("\r\n", $headers);
    $context = stream_context_create($opts);
    
    $response = @file_get_contents($fullUrl, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);

    if ($response === false) {
        return ['success' => false, 'error' => 'kintoneとの通信に失敗しました。プロキシやホスト名を確認してください。'];
    }

    $resData = json_decode($response, true);
    if (isset($resData['code']) || isset($resData['message'])) {
        $msg = $resData['message'] ?? 'エラーが発生しました';
        if (isset($resData['errors'])) {
            $msg .= ' ' . json_encode($resData['errors'], JSON_UNESCAPED_UNICODE);
        }
        return ['success' => false, 'error' => $msg];
    }

    return ['success' => true, 'data' => $resData];
}

// SMTPメール送信 (ソケット通信)
function sendSmtpMail(array $smtp, string $to, string $subject, string $body): array {
    $host = $smtp['host'] ?? '';
    $port = (int)($smtp['port'] ?? 587);
    $timeout = 10;
    
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return ['success' => false, 'error' => "接続失敗: {$errstr} ({$errno})"];
    }

    $read = function() use ($socket) {
        $res = '';
        while ($line = fgets($socket, 515)) {
            $res .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $res;
    };
    $write = function(string $cmd) use ($socket) {
        fputs($socket, $cmd . "\r\n");
    };

    $res = $read();
    $write("EHLO " . gethostname());
    $res = $read();

    if (($smtp['secure'] ?? '') === 'STARTTLS' || $port === 587) {
        $write("STARTTLS");
        $res = $read();
        if (strpos($res, '220') !== 0) {
            fclose($socket);
            return ['success' => false, 'error' => "STARTTLS失敗: {$res}"];
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write("EHLO " . gethostname());
        $res = $read();
    }

    if (!empty($smtp['user']) && !empty($smtp['pass'])) {
        $write("AUTH LOGIN");
        $read();
        $write(base64_encode($smtp['user']));
        $read();
        $write(base64_encode($smtp['pass']));
        $authRes = $read();
        if (strpos($authRes, '235') !== 0) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP認証失敗: {$authRes}"];
        }
    }

    $from = $smtp['fromEmail'] ?? $smtp['user'];
    $write("MAIL FROM:<{$from}>");
    $read();
    $write("RCPT TO:<{$to}>");
    $rcptRes = $read();
    if (strpos($rcptRes, '250') !== 0) {
        fclose($socket);
        return ['success' => false, 'error' => "宛先エラー: {$rcptRes}"];
    }

    $write("DATA");
    $read();

    $headers = [];
    $fromName = !empty($smtp['fromName']) ? "=?UTF-8?B?" . base64_encode($smtp['fromName']) . "?=" : '';
    $headers[] = "From: {$fromName} <{$from}>";
    $headers[] = "To: <{$to}>";
    $headers[] = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";

    $mailData = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    $write($mailData);
    $sendRes = $read();
    $write("QUIT");
    fclose($socket);

    if (strpos($sendRes, '250') !== 0) {
        return ['success' => false, 'error' => "送信エラー: {$sendRes}"];
    }
    return ['success' => true];
}

// -------------------------------------------------------------------
// API ルーティング処理
// -------------------------------------------------------------------
$action = $_GET['action'] ?? '';

if ($action) {
    // CSRFチェック (GETリクエスト以外の状態変更処理)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
        if (!$token || !hash_equals($_SESSION['survey_csrf_token'], $token)) {
            sendJsonResponse(['error' => 'CSRFトークンが無効または一致しません。'], 403);
        }
    }

    // 1. 全体データ取得
    if ($action === 'get_bootstrap_data') {
        $settings = loadJson('settings.json', [
            'kintone' => ['host' => '', 'appId' => '', 'login' => '', 'pass' => '', 'proxyHost' => '', 'proxyPort' => '', 'nameField' => '顧客名', 'emailField' => 'メールアドレス'],
            'smtp' => ['host' => '', 'port' => '587', 'secure' => 'STARTTLS', 'user' => '', 'pass' => '', 'fromEmail' => '', 'fromName' => '']
        ]);
        // パスワード等はマスクして返却
        $safeSettings = $settings;
        if (!empty($safeSettings['kintone']['pass'])) $safeSettings['kintone']['pass'] = '********';
        if (!empty($safeSettings['smtp']['pass'])) $safeSettings['smtp']['pass'] = '********';

        sendJsonResponse([
            'csrf_token' => $_SESSION['survey_csrf_token'],
            'settings' => $safeSettings,
            'surveys' => loadJson('surveys.json', []),
            'groups' => loadJson('groups.json', []),
            'questions' => loadJson('questions.json', []),
            'options' => loadJson('options.json', []),
            'customers' => loadJson('customers.json', []),
            'recipients' => loadJson('recipients.json', []),
            'mail_logs' => loadJson('mail_logs.json', []),
            'responses' => loadJson('responses.json', []),
            'response_answers' => loadJson('response_answers.json', [])
        ]);
    }

    // 2. 設定保存・接続確認
    if ($action === 'save_settings') {
        $input = json_decode(file_get_contents('php://input'), true);
        $current = loadJson('settings.json', []);
        
        $kPass = $input['kintone']['pass'] ?? '';
        if ($kPass === '********' || empty($kPass)) {
            $input['kintone']['pass'] = $current['kintone']['pass'] ?? '';
        }
        $sPass = $input['smtp']['pass'] ?? '';
        if ($sPass === '********' || empty($sPass)) {
            $input['smtp']['pass'] = $current['smtp']['pass'] ?? '';
        }

        saveJson('settings.json', $input);
        sendJsonResponse(['success' => true, 'message' => '設定を保存しました。']);
    }

    if ($action === 'test_kintone') {
        $settings = loadJson('settings.json', []);
        $k = $settings['kintone'] ?? [];
        if (empty($k['host']) || empty($k['appId']) || empty($k['login'])) {
            sendJsonResponse(['success' => false, 'error' => 'kintoneの接続設定が不足しています。']);
        }
        $res = kintoneRequest('GET', '/k/v1/records.json', ['app' => $k['appId'], 'totalCount' => 'true'], $k);
        if ($res['success']) {
            $records = $res['data']['records'] ?? [];
            $customers = [];
            $nameField = $k['nameField'] ?: '顧客名';
            $emailField = $k['emailField'] ?: 'メールアドレス';
            
            foreach ($records as $r) {
                $customers[] = [
                    'id' => (string)($r['$id']['value'] ?? uniqid('c_')),
                    'name' => $r[$nameField]['value'] ?? '名称未設定',
                    'email' => $r[$emailField]['value'] ?? ''
                ];
            }
            saveJson('customers.json', $customers);
            sendJsonResponse(['success' => true, 'count' => count($customers), 'customers' => $customers]);
        } else {
            sendJsonResponse($res);
        }
    }

    if ($action === 'test_smtp') {
        $settings = loadJson('settings.json', []);
        $s = $settings['smtp'] ?? [];
        if (empty($s['host']) || empty($s['fromEmail'])) {
            sendJsonResponse(['success' => false, 'error' => 'SMTP設定が不足しています。']);
        }
        $res = sendSmtpMail($s, $s['fromEmail'], '【接続テスト】アンケートシステム', "接続確認テストです。\n正常に設定されています。");
        sendJsonResponse($res);
    }

    // 3. アンケート保存
    if ($action === 'save_survey') {
        $input = json_decode(file_get_contents('php://input'), true);
        $survey = $input['survey'] ?? null;
        $groups = $input['groups'] ?? [];
        $questions = $input['questions'] ?? [];
        $options = $input['options'] ?? [];

        if (!$survey || empty($survey['name'])) {
            sendJsonResponse(['error' => 'アンケート名は必須です。'], 400);
        }

        $allSurveys = loadJson('surveys.json', []);
        $allGroups = loadJson('groups.json', []);
        $allQuestions = loadJson('questions.json', []);
        $allOptions = loadJson('options.json', []);

        $now = date('Y-m-d H:i:s');
        if (empty($survey['id'])) {
            $survey['id'] = 's_' . uniqid();
            $survey['created_at'] = $now;
            $survey['status'] = '下書き';
        }
        $survey['updated_at'] = $now;

        // 更新・マージ
        $allSurveys = array_values(array_filter($allSurveys, fn($s) => $s['id'] !== $survey['id']));
        $allSurveys[] = $survey;

        $allGroups = array_values(array_filter($allGroups, fn($g) => $g['survey_id'] !== $survey['id']));
        foreach ($groups as $g) {
            $g['survey_id'] = $survey['id'];
            $allGroups[] = $g;
        }

        $allQuestions = array_values(array_filter($allQuestions, fn($q) => $q['survey_id'] !== $survey['id']));
        foreach ($questions as $q) {
            $q['survey_id'] = $survey['id'];
            $allQuestions[] = $q;
        }

        $qIds = array_column($questions, 'id');
        $allOptions = array_values(array_filter($allOptions, fn($o) => !in_array($o['question_id'], $qIds, true)));
        foreach ($options as $o) {
            $allOptions[] = $o;
        }

        saveJson('surveys.json', $allSurveys);
        saveJson('groups.json', $allGroups);
        saveJson('questions.json', $allQuestions);
        saveJson('options.json', $allOptions);

        sendJsonResponse(['success' => true, 'survey_id' => $survey['id']]);
    }

    // 4. アンケート状態更新 / 削除
    if ($action === 'update_survey_status') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $status = $input['status'] ?? '';
        $surveys = loadJson('surveys.json', []);
        foreach ($surveys as &$s) {
            if ($s['id'] === $id) {
                $s['status'] = $status;
                $s['updated_at'] = date('Y-m-d H:i:s');
            }
        }
        saveJson('surveys.json', $surveys);
        sendJsonResponse(['success' => true]);
    }

    if ($action === 'delete_survey') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $surveys = array_values(array_filter(loadJson('surveys.json', []), fn($s) => $s['id'] !== $id));
        saveJson('surveys.json', $surveys);
        sendJsonResponse(['success' => true]);
    }

    // 5. メール送信実行
    if ($action === 'send_survey_mail') {
        $input = json_decode(file_get_contents('php://input'), true);
        $surveyId = $input['survey_id'] ?? '';
        $customerIds = $input['customer_ids'] ?? [];
        $subject = $input['subject'] ?? '';
        $bodyTemplate = $input['body'] ?? '';

        $settings = loadJson('settings.json', []);
        $smtp = $settings['smtp'] ?? [];
        if (empty($smtp['host']) || empty($smtp['fromEmail'])) {
            sendJsonResponse(['error' => 'SMTP送信設定が完了していません。設定画面を確認してください。'], 400);
        }

        $customers = loadJson('customers.json', []);
        $recipients = loadJson('recipients.json', []);
        $mailLogs = loadJson('mail_logs.json', []);

        $targetCustomers = array_filter($customers, fn($c) => in_array($c['id'], $customerIds, true));
        $successCount = 0;
        $failCount = 0;
        $now = date('Y-m-d H:i:s');

        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}";

        foreach ($targetCustomers as $cust) {
            $recId = 'r_' . uniqid();
            $recipients[] = [
                'id' => $recId,
                'survey_id' => $surveyId,
                'customer_id' => $cust['id'],
                'send_status' => '送信中',
                'send_at' => $now,
                'response_status' => '未回答'
            ];

            $surveyUrl = "{$baseUrl}?respondent=1&sid={$surveyId}&rid={$recId}";
            $mailBody = str_replace(['{name}', '{url}'], [$cust['name'], $surveyUrl], $bodyTemplate);

            $sendRes = sendSmtpMail($smtp, $cust['email'], $subject, $mailBody);
            
            $logId = 'log_' . uniqid();
            $mailLogs[] = [
                'id' => $logId,
                'recipient_id' => $recId,
                'subject' => $subject,
                'body' => $mailBody,
                'send_at' => $now,
                'is_success' => $sendRes['success'],
                'error' => $sendRes['error'] ?? ''
            ];

            // 受信者状態更新
            foreach ($recipients as &$r) {
                if ($r['id'] === $recId) {
                    $r['send_status'] = $sendRes['success'] ? '送信成功' : '送信失敗';
                }
            }

            if ($sendRes['success']) $successCount++; else $failCount++;
        }

        saveJson('recipients.json', $recipients);
        saveJson('mail_logs.json', $mailLogs);

        sendJsonResponse([
            'success' => true,
            'total' => count($targetCustomers),
            'success_count' => $successCount,
            'fail_count' => $failCount
        ]);
    }

    // 6. 回答送信 (回答者用)
    if ($action === 'submit_response') {
        $input = json_decode(file_get_contents('php://input'), true);
        $surveyId = $input['survey_id'] ?? '';
        $recipientId = $input['recipient_id'] ?? null;
        $answers = $input['answers'] ?? []; // [qid => val]

        $surveys = loadJson('surveys.json', []);
        $survey = null;
        foreach ($surveys as $s) {
            if ($s['id'] === $surveyId) { $survey = $s; break; }
        }

        if (!$survey || $survey['status'] !== '公開中') {
            sendJsonResponse(['error' => 'このアンケートは現在回答を受け付けていません。'], 400);
        }

        $now = date('Y-m-d H:i:s');
        if (!empty($survey['start_date']) && $now < $survey['start_date']) {
            sendJsonResponse(['error' => 'アンケート公開期間外です。'], 400);
        }
        if (!empty($survey['end_date']) && $now > $survey['end_date'] . ' 23:59:59') {
            sendJsonResponse(['error' => 'アンケートの受付は終了しました。'], 400);
        }

        $responses = loadJson('responses.json', []);
        $responseAnswers = loadJson('response_answers.json', []);

        // 二重回答チェック
        if ($recipientId) {
            foreach ($responses as $resp) {
                if ($resp['recipient_id'] === $recipientId) {
                    sendJsonResponse(['error' => 'すでに回答が送信されています。'], 400);
                }
            }
        }

        $responseId = 'res_' . uniqid();
        $responses[] = [
            'id' => $responseId,
            'survey_id' => $surveyId,
            'recipient_id' => $recipientId,
            'created_at' => $now,
            'is_completed' => true
        ];

        foreach ($answers as $qid => $val) {
            $responseAnswers[] = [
                'id' => 'ans_' . uniqid(),
                'response_id' => $responseId,
                'question_id' => $qid,
                'answer_value' => is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string)$val
            ];
        }

        if ($recipientId) {
            $recipients = loadJson('recipients.json', []);
            foreach ($recipients as &$r) {
                if ($r['id'] === $recipientId) {
                    $r['response_status'] = '回答済み';
                }
            }
            saveJson('recipients.json', $recipients);
        }

        saveJson('responses.json', $responses);
        saveJson('response_answers.json', $responseAnswers);

        sendJsonResponse(['success' => true]);
    }

    sendJsonResponse(['error' => '不正なリクエストです。'], 404);
}

// -------------------------------------------------------------------
// HTML レンダリング (回答者画面 または 運営者画面)
// -------------------------------------------------------------------
$isRespondent = isset($_GET['respondent']);
$respondentSid = $_GET['sid'] ?? '';
$respondentRid = $_GET['rid'] ?? '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isRespondent ? 'アンケート回答' : 'アンケート業務運営アプリ' ?></title>
  <style>
    :root {
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --bg: #f8fafc;
      --surface: #ffffff;
      --border: #e2e8f0;
      --text: #1e293b;
      --text-muted: #64748b;
      --success: #16a34a;
      --danger: #dc2626;
      --warning: #d97706;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
    body { background-color: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }
    
    /* ヘッダー */
    header { background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; }
    .header-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 0 1rem; }
    .logo { font-weight: bold; font-size: 1.15rem; color: var(--primary); padding: 1rem 0; }
    nav ul { display: flex; list-style: none; gap: 0.5rem; }
    nav button { background: none; border: none; padding: 1rem 0.75rem; font-size: 0.95rem; cursor: pointer; color: var(--text-muted); font-weight: 500; border-bottom: 2px solid transparent; }
    nav button.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }
    nav button:hover:not(.active) { color: var(--text); }

    /* サブナビ */
    .sub-nav { background: #f1f5f9; border-bottom: 1px solid var(--border); padding: 0.5rem 1rem; }
    .sub-nav-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .sub-nav-title { font-weight: 600; font-size: 0.9rem; color: var(--text-muted); }
    .sub-nav-title span { color: var(--text); font-size: 1rem; font-weight: bold; }
    .sub-nav ul { display: flex; list-style: none; gap: 0.5rem; }
    .sub-nav button { background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 0.35rem 0.75rem; font-size: 0.85rem; cursor: pointer; }
    .sub-nav button.active { background: var(--primary); color: #fff; border-color: var(--primary); }

    /* メイン領域 */
    main { max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem; flex: 1; width: 100%; }
    .card { background: var(--surface); border-radius: 8px; border: 1px solid var(--border); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; }
    .card-title { font-size: 1.15rem; font-weight: 600; }

    /* UI コンポーネント */
    .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.9rem; font-weight: 500; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; }
    .btn-primary { background: var(--primary); color: #fff; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-outline { background: var(--surface); border-color: var(--border); color: var(--text); }
    .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }
    .btn-danger { background: var(--danger); color: #fff; }
    .btn-danger-outline { border-color: var(--danger); color: var(--danger); background: transparent; }
    .btn-danger-outline:hover { background: #fee2e2; }
    .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.8rem; }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; }

    .badge { padding: 0.25rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
    .badge-draft { background: #e2e8f0; color: #475569; }
    .badge-active { background: #dcfce7; color: #15803d; }
    .badge-closed { background: #fee2e2; color: #b91c1c; }

    .form-group { margin-bottom: 1rem; }
    .form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; }
    .form-control { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; }
    .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(37,99,235,0.1); }
    .form-row { display: flex; gap: 1rem; }
    .form-row .form-group { flex: 1; }

    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
    th, td { padding: 0.75rem; border-bottom: 1px solid var(--border); }
    th { background: #f8fafc; font-weight: 600; color: var(--text-muted); }

    /* エディタ用スタイル */
    .group-card { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem; }
    .group-card.dragging { opacity: 0.4; border: 2px dashed var(--primary); }
    .question-card { background: #ffffff; border: 1px solid var(--border); border-radius: 6px; padding: 1rem; margin-bottom: 0.75rem; }
    .question-card.dragging { opacity: 0.4; border: 2px dashed var(--primary); }
    .drag-handle { cursor: grab; color: var(--text-muted); padding: 0.25rem; font-size: 1.1rem; user-select: none; }
    .choice-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }

    /* ダッシュボードグリッド */
    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; text-align: center; }
    .stat-num { font-size: 2rem; font-weight: bold; color: var(--primary); margin-top: 0.25rem; }

    /* 通知トースト & モーダル */
    .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #334155; color: #fff; padding: 0.75rem 1.25rem; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000; display: none; }
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 500; }
    .modal { background: #fff; width: 90%; max-width: 600px; border-radius: 8px; padding: 1.5rem; box-shadow: 0 10px 15px rgba(0,0,0,0.1); }
  </style>
</head>
<body>

<?php if ($isRespondent): ?>
  <!-- ======================= 回答者画面 ======================= -->
  <main style="max-width: 800px; margin: 2rem auto;">
    <div id="respondent-app">
      <div class="card" style="text-align: center; padding: 3rem;">
        <p>アンケートを読み込んでいます...</p>
      </div>
    </div>
  </main>

  <script>
    document.addEventListener('DOMContentLoaded', async () => {
      const appEl = document.getElementById('respondent-app');
      const surveyId = "<?= h($respondentSid) ?>";
      const recipientId = "<?= h($respondentRid) ?>";

      try {
        const res = await fetch('?action=get_bootstrap_data');
        const data = await res.json();
        const survey = (data.surveys || []).find(s => s.id === surveyId);
        
        if (!survey || survey.status !== '公開中') {
          appEl.innerHTML = `<div class="card"><h2>ご案内</h2><p style="margin-top:1rem;">このアンケートは現在公開されていないか、終了いたしました。</p></div>`;
          return;
        }

        const groups = (data.groups || []).filter(g => g.survey_id === surveyId).sort((a,b) => a.order_no - b.order_no);
        const questions = (data.questions || []).filter(q => q.survey_id === surveyId).sort((a,b) => a.order_no - b.order_no);
        const options = (data.options || []);

        const qMap = {};
        questions.forEach(q => {
          q.choices = options.filter(o => o.question_id === q.id).sort((a,b) => a.order_no - b.order_no);
          qMap[q.id] = q;
        });

        // 質問番号マップ生成
        const qNumMap = {};
        let gCount = 1, tCount = 1;
        groups.forEach(g => {
          let qCount = 1;
          questions.filter(q => q.group_id === g.id).forEach(q => {
            qNumMap[q.id] = survey.numbering_format === 'global' ? `Q${tCount}` : `Q${gCount}-${qCount}`;
            qCount++; tCount++;
          });
          gCount++;
        });

        // レンダリング
        appEl.innerHTML = `
          <div class="card">
            <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem;">${survey.name}</h1>
            <p style="color: var(--text-muted); white-space: pre-wrap;">${survey.description || ''}</p>
          </div>
          <form id="resp-form">
            <div id="questions-area"></div>
            <div class="card" style="text-align: center;">
              <button type="button" id="submit-btn" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1rem;">回答を送信する</button>
            </div>
          </form>
        `;

        const area = document.getElementById('questions-area');
        
        function renderQuestions() {
          let html = '';
          let stopRendering = false;

          groups.forEach(g => {
            if (stopRendering) return;
            const gQuestions = questions.filter(q => q.group_id === g.id);
            if (gQuestions.length === 0) return;

            let groupHtml = `<div class="card"><h2 style="font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; margin-bottom: 1rem;">${g.name}</h2>`;
            
            for (const q of gQuestions) {
              if (stopRendering) break;
              
              groupHtml += `
                <div class="form-group" style="margin-bottom: 1.5rem;" data-qid="${q.id}">
                  <label style="font-size: 1rem; margin-bottom: 0.5rem;">
                    <span style="font-weight: bold; color: var(--primary);">${qNumMap[q.id]}</span> ${q.title}
                    ${q.is_required ? '<span style="color: var(--danger); font-size: 0.8rem;">*必須</span>' : ''}
                  </label>
              `;

              if (q.type === 'text') {
                groupHtml += `<textarea class="form-control" name="q_${q.id}" rows="3" placeholder="回答をご記入ください"></textarea>`;
              } else if (q.type === 'single') {
                groupHtml += `<div>`;
                q.choices.forEach(opt => {
                  groupHtml += `
                    <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.4rem; font-weight: normal; cursor: pointer;">
                      <input type="radio" name="q_${q.id}" value="${opt.title}" data-next="${opt.next_question_id || ''}">
                      <span>${opt.title}</span>
                    </label>
                  `;
                });
                groupHtml += `</div>`;
              } else if (q.type === 'multiple') {
                groupHtml += `<div>`;
                q.choices.forEach(opt => {
                  groupHtml += `
                    <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.4rem; font-weight: normal; cursor: pointer;">
                      <input type="checkbox" name="q_${q.id}" value="${opt.title}">
                      <span>${opt.title}</span>
                    </label>
                  `;
                });
                groupHtml += `</div>`;
              }
              groupHtml += `</div>`;
            }
            groupHtml += `</div>`;
            html += groupHtml;
          });

          area.innerHTML = html;
        }

        renderQuestions();

        // 送信イベント
        document.getElementById('submit-btn').addEventListener('click', async (e) => {
          const btn = e.target;
          const form = document.getElementById('resp-form');
          const formData = new FormData(form);
          const answers = {};

          // バリデーション
          for (const q of questions) {
            const el = form.querySelector(`[data-qid="${q.id}"]`);
            if (!el) continue; // 分岐等で非表示

            if (q.type === 'text') {
              const val = formData.get(`q_${q.id}`)?.toString().trim() || '';
              if (q.is_required && !val) {
                alert(`「${qNumMap[q.id]}」は必須項目です。`);
                return;
              }
              answers[q.id] = val;
            } else if (q.type === 'single') {
              const val = formData.get(`q_${q.id}`);
              if (q.is_required && !val) {
                alert(`「${qNumMap[q.id]}」は必須項目です。`);
                return;
              }
              answers[q.id] = val || '';
            } else if (q.type === 'multiple') {
              const vals = formData.getAll(`q_${q.id}`);
              if (q.is_required && vals.length === 0) {
                alert(`「${qNumMap[q.id]}」は必須項目です。1つ以上選択してください。`);
                return;
              }
              answers[q.id] = vals;
            }
          }

          btn.disabled = true;
          btn.innerText = '送信中...';

          try {
            const postRes = await fetch('?action=submit_response', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': data.csrf_token },
              body: JSON.stringify({
                survey_id: surveyId,
                recipient_id: recipientId,
                answers: answers
              })
            });
            const result = await postRes.json();
            if (result.success) {
              appEl.innerHTML = `
                <div class="card" style="text-align: center; padding: 3rem;">
                  <h2 style="color: var(--success); margin-bottom: 1rem;">回答を送信しました</h2>
                  <p>アンケートへのご協力、誠にありがとうございました。</p>
                </div>
              `;
            } else {
              alert(result.error || 'エラーが発生しました');
              btn.disabled = false;
              btn.innerText = '回答を送信する';
            }
          } catch (err) {
            alert('通信エラーが発生しました。');
            btn.disabled = false;
            btn.innerText = '回答を送信する';
          }
        });

      } catch (err) {
        appEl.innerHTML = `<div class="card"><p style="color:var(--danger);">データの取得に失敗しました。</p></div>`;
      }
    });
  </script>

<?php else: ?>
  <!-- ======================= 運営者画面 ======================= -->
  <header id="app-header">
    <div class="header-container">
      <div class="logo">アンケート業務運営アプリ</div>
      <nav>
        <ul>
          <li><button class="active" onclick="App.navigate('survey-list')">アンケート一覧</button></li>
          <li><button onclick="App.openEditor(null)">アンケート作成</button></li>
          <li><button onclick="App.navigate('customer-list')">顧客一覧</button></li>
          <li><button onclick="App.navigate('settings')">設定</button></li>
        </ul>
      </nav>
    </div>
    <!-- 個別アンケート用サブナビ -->
    <div id="sub-nav-bar" class="sub-nav" style="display: none;">
      <div class="sub-nav-container">
        <div class="sub-nav-title">選択中: <span id="current-survey-name"></span></div>
        <ul>
          <li><button id="snav-detail" onclick="App.navigateSub('detail')">アンケート内容</button></li>
          <li><button id="snav-send" onclick="App.navigateSub('send')">送信</button></li>
          <li><button id="snav-status" onclick="App.navigateSub('status')">回答状況</button></li>
          <li><button id="snav-result" onclick="App.navigateSub('result')">回答結果</button></li>
        </ul>
      </div>
    </div>
  </header>

  <main id="main-content"></main>

  <div id="toast" class="toast"></div>

  <div id="modal" class="modal-overlay">
    <div class="modal">
      <h3 id="modal-title" style="margin-bottom: 0.75rem;">確認</h3>
      <div id="modal-body" style="font-size: 0.9rem; margin-bottom: 1.25rem;"></div>
      <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
        <button class="btn btn-outline" onclick="App.closeModal()">キャンセル</button>
        <button id="modal-confirm-btn" class="btn btn-primary">実行</button>
      </div>
    </div>
  </div>

  <script>
    const App = {
      state: {
        csrf_token: '',
        currentView: 'survey-list',
        selectedSurveyId: null,
        settings: {},
        surveys: [],
        groups: [],
        questions: [],
        options: [],
        customers: [],
        recipients: [],
        mail_logs: [],
        responses: [],
        response_answers: []
      },

      async init() {
        await this.loadData();
        this.render();
      },

      async loadData() {
        try {
          const res = await fetch('?action=get_bootstrap_data');
          const data = await res.json();
          Object.assign(this.state, data);
        } catch (e) {
          this.showToast('データの読み込みに失敗しました');
        }
      },

      showToast(msg) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.style.display = 'block';
        setTimeout(() => { t.style.display = 'none'; }, 3000);
      },

      openModal(title, bodyHtml, onConfirm) {
        document.getElementById('modal-title').textContent = title;
        document.getElementById('modal-body').innerHTML = bodyHtml;
        const btn = document.getElementById('modal-confirm-btn');
        btn.onclick = () => { onConfirm(); App.closeModal(); };
        document.getElementById('modal').style.display = 'flex';
      },

      closeModal() {
        document.getElementById('modal').style.display = 'none';
      },

      getSurvey(id) {
        return this.state.surveys.find(s => s.id === (id || this.state.selectedSurveyId));
      },

      calcQuestionNumbers(surveyId, numberingFormat) {
        const groups = this.state.groups.filter(g => g.survey_id === surveyId).sort((a,b) => a.order_no - b.order_no);
        const questions = this.state.questions.filter(q => q.survey_id === surveyId).sort((a,b) => a.order_no - b.order_no);
        const map = {};
        let gCount = 1, tCount = 1;
        groups.forEach(g => {
          let qCount = 1;
          questions.filter(q => q.group_id === g.id).forEach(q => {
            map[q.id] = (numberingFormat === 'global') ? `Q${tCount}` : `Q${gCount}-${qCount}`;
            qCount++; tCount++;
          });
          gCount++;
        });
        return map;
      },

      navigate(view, surveyId = null) {
        this.state.currentView = view;
        if (surveyId) this.state.selectedSurveyId = surveyId;

        const subNav = document.getElementById('sub-nav-bar');
        const isSubSection = ['detail', 'send', 'status', 'result'].includes(view);

        document.querySelectorAll('#app-header nav button').forEach(btn => btn.classList.remove('active'));
        if (isSubSection) {
          subNav.style.display = 'block';
          const s = this.getSurvey();
          document.getElementById('current-survey-name').textContent = s ? s.name : '';
          document.querySelectorAll('#sub-nav-bar button').forEach(b => {
            b.classList.toggle('active', b.id === `snav-${view}`);
          });
        } else {
          subNav.style.display = 'none';
        }
        this.render();
      },

      navigateSub(subView) {
        this.navigate(subView, this.state.selectedSurveyId);
      },

      render() {
        const container = document.getElementById('main-content');
        container.innerHTML = '';
        switch (this.state.currentView) {
          case 'survey-list': this.renderSurveyList(container); break;
          case 'survey-editor': this.renderSurveyEditor(container); break;
          case 'customer-list': this.renderCustomerList(container); break;
          case 'settings': this.renderSettings(container); break;
          case 'detail': this.renderSurveyDetail(container); break;
          case 'send': this.renderSurveySend(container); break;
          case 'status': this.renderSurveyStatus(container); break;
          case 'result': this.renderSurveyResult(container); break;
        }
      },

      // --- 1. アンケート一覧 ---
      renderSurveyList(container) {
        const list = this.state.surveys;
        container.innerHTML = `
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">アンケート一覧</h2>
              <button class="btn btn-primary" onclick="App.openEditor(null)">＋ 新規アンケート作成</button>
            </div>
            <table>
              <thead>
                <tr>
                  <th>アンケート名</th>
                  <th>状態</th>
                  <th>作成日</th>
                  <th>公開期間</th>
                  <th>回答数</th>
                  <th>最終更新日</th>
                  <th style="text-align: right;">操作</th>
                </tr>
              </thead>
              <tbody>
                ${list.length === 0 ? '<tr><td colspan="7" style="text-align:center;">アンケートが登録されていません。</td></tr>' : ''}
                ${list.map(s => {
                  const respCount = this.state.responses.filter(r => r.survey_id === s.id).length;
                  const badgeClass = s.status === '公開中' ? 'badge-active' : (s.status === '下書き' ? 'badge-draft' : 'badge-closed');
                  return `
                    <tr>
                      <td><strong><a href="javascript:void(0)" onclick="App.navigate('detail', '${s.id}')" style="color: var(--primary); text-decoration: none;">${s.name}</a></strong></td>
                      <td><span class="badge ${badgeClass}">${s.status}</span></td>
                      <td>${(s.created_at || '').substring(0, 10)}</td>
                      <td>${s.start_date || '未設定'} 〜 ${s.end_date || '未設定'}</td>
                      <td>${respCount} 件</td>
                      <td>${(s.updated_at || '').substring(0, 10)}</td>
                      <td style="text-align: right;">
                        <button class="btn btn-outline btn-sm" onclick="App.navigate('detail', '${s.id}')">管理</button>
                        <button class="btn btn-outline btn-sm" onclick="App.openEditor('${s.id}')">編集</button>
                        ${s.status === '下書き' ? `<button class="btn btn-primary btn-sm" onclick="App.updateSurveyStatus('${s.id}', '公開中')">公開</button>` : ''}
                        ${s.status === '公開中' ? `<button class="btn btn-outline btn-sm" onclick="App.updateSurveyStatus('${s.id}', '終了')">終了</button>` : ''}
                        ${s.status === '下書き' ? `<button class="btn btn-danger-outline btn-sm" onclick="App.deleteSurvey('${s.id}')">削除</button>` : ''}
                      </td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
          </div>
        `;
      },

      async updateSurveyStatus(id, status) {
        await fetch('?action=update_survey_status', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.state.csrf_token },
          body: JSON.stringify({ id, status })
        });
        this.showToast(`状態を「${status}」に更新しました`);
        await this.loadData();
        this.render();
      },

      deleteSurvey(id) {
        this.openModal('削除確認', 'この下書きアンケートを完全に削除しますか？', async () => {
          await fetch('?action=delete_survey', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.state.csrf_token },
            body: JSON.stringify({ id })
          });
          this.showToast('アンケートを削除しました');
          await this.loadData();
          this.render();
        });
      },

      // --- 2. アンケート作成・編集エディタ ---
      editorDraft: null,
      openEditor(surveyId) {
        if (!surveyId) {
          this.editorDraft = {
            survey: { id: '', name: '', description: '', numbering_format: 'group', start_date: '', end_date: '' },
            groups: [{ id: 'g_' + Date.now(), name: '基本グループ', order_no: 1 }],
            questions: [{ id: 'q_' + Date.now(), group_id: '', title: '', type: 'single', is_required: true, order_no: 1 }],
            options: [
              { id: 'o_' + Date.now() + '_1', question_id: '', title: '選択肢1', order_no: 1, next_question_id: '' },
              { id: 'o_' + Date.now() + '_2', question_id: '', title: '選択肢2', order_no: 2, next_question_id: '' }
            ]
          };
          this.editorDraft.questions[0].group_id = this.editorDraft.groups[0].id;
          this.editorDraft.options.forEach(o => o.question_id = this.editorDraft.questions[0].id);
        } else {
          const s = this.getSurvey(surveyId);
          this.editorDraft = {
            survey: JSON.parse(JSON.stringify(s)),
            groups: JSON.parse(JSON.stringify(this.state.groups.filter(g => g.survey_id === surveyId))),
            questions: JSON.parse(JSON.stringify(this.state.questions.filter(q => q.survey_id === surveyId))),
            options: JSON.parse(JSON.stringify(this.state.options.filter(o => this.state.questions.some(q => q.survey_id === surveyId && q.id === o.question_id))))
          };
        }
        this.navigate('survey-editor');
      },

      renderSurveyEditor(container) {
        const d = this.editorDraft;
        const allQuestions = d.questions.map((q, idx) => ({ id: q.id, title: q.title || `質問 ${idx + 1}` }));

        container.innerHTML = `
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">${d.survey.id ? 'アンケート編集' : '新規アンケート作成'}</h2>
              <div style="display: flex; gap: 0.5rem;">
                <button class="btn btn-outline" onclick="App.navigate('survey-list')">キャンセル</button>
                <button class="btn btn-primary" id="save-survey-btn" onclick="App.saveSurvey()">保存</button>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group" style="flex: 2;">
                <label>アンケート名 <span style="color: var(--danger)">*必須</span></label>
                <input type="text" class="form-control" id="ed-name" value="${d.survey.name}" placeholder="例: サービス満足度調査">
              </div>
              <div class="form-group">
                <label>質問番号形式</label>
                <select class="form-control" id="ed-num-format">
                  <option value="group" ${d.survey.numbering_format==='group'?'selected':''}>グループごと (Q1-1, Q2-1...)</option>
                  <option value="global" ${d.survey.numbering_format==='global'?'selected':''}>全体で通番 (Q1, Q2, Q3...)</option>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>説明文</label>
              <textarea class="form-control" id="ed-desc" rows="2">${d.survey.description || ''}</textarea>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>公開開始日</label>
                <input type="date" class="form-control" id="ed-start" value="${d.survey.start_date || ''}">
              </div>
              <div class="form-group">
                <label>公開終了日</label>
                <input type="date" class="form-control" id="ed-end" value="${d.survey.end_date || ''}">
              </div>
            </div>

            <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border);">

            <h3 style="font-size: 1.05rem; margin-bottom: 1rem;">質問グループ構成</h3>
            <div id="editor-groups">
              ${d.groups.map((g, gIdx) => {
                const gQuestions = d.questions.filter(q => q.group_id === g.id);
                return `
                  <div class="group-card" data-gid="${g.id}">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                      <div style="display: flex; align-items: center; gap: 0.5rem; flex: 1;">
                        <span class="drag-handle">☰</span>
                        <input type="text" class="form-control" style="font-weight: bold; width: 60%;" value="${g.name}" placeholder="グループ名" onchange="App.updateGroupName('${g.id}', this.value)">
                      </div>
                      <button class="btn btn-danger-outline btn-sm" onclick="App.removeGroup('${g.id}')">グループ削除</button>
                    </div>

                    <div class="group-questions" data-gid="${g.id}">
                      ${gQuestions.map((q, qIdx) => {
                        const qOpts = d.options.filter(o => o.question_id === q.id);
                        return `
                          <div class="question-card" data-qid="${q.id}">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                              <div style="display: flex; align-items: center; gap: 0.5rem; flex: 1;">
                                <span class="drag-handle">::</span>
                                <input type="text" class="form-control" style="font-weight: 500;" value="${q.title}" placeholder="質問文を入力" onchange="App.updateQuestionTitle('${q.id}', this.value)">
                              </div>
                              <button class="btn btn-danger-outline btn-sm" style="margin-left: 0.5rem;" onclick="App.removeQuestion('${q.id}')">削除</button>
                            </div>

                            <div class="form-row" style="margin-bottom: 0.5rem;">
                              <div class="form-group" style="margin-bottom: 0;">
                                <select class="form-control" onchange="App.updateQuestionType('${q.id}', this.value)">
                                  <option value="single" ${q.type==='single'?'selected':''}>単一選択 (ラジオボタン)</option>
                                  <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択 (チェックボックス)</option>
                                  <option value="text" ${q.type==='text'?'selected':''}>自由記述 (テキスト)</option>
                                </select>
                              </div>
                              <div class="form-group" style="margin-bottom: 0; display: flex; align-items: center;">
                                <label style="display: flex; align-items: center; gap: 0.35rem; font-weight: normal; cursor: pointer; margin-bottom: 0;">
                                  <input type="checkbox" ${q.is_required?'checked':''} onchange="App.updateQuestionRequired('${q.id}', this.checked)"> 必須回答
                                </label>
                              </div>
                            </div>

                            <!-- 選択肢エリア -->
                            ${q.type !== 'text' ? `
                              <div style="background: #f8fafc; padding: 0.75rem; border-radius: 4px; margin-top: 0.5rem;">
                                <div style="font-size: 0.8rem; font-weight: bold; color: var(--text-muted); margin-bottom: 0.35rem;">選択肢・分岐先設定</div>
                                ${qOpts.map((opt, oIdx) => `
                                  <div class="choice-row">
                                    <input type="text" class="form-control" style="flex: 2;" value="${opt.title}" placeholder="選択肢名" onchange="App.updateOptionTitle('${opt.id}', this.value)">
                                    ${q.type === 'single' ? `
                                      <select class="form-control" style="flex: 1;" onchange="App.updateOptionBranch('${opt.id}', this.value)">
                                        <option value="">(次の質問へ)</option>
                                        <option value="END" ${opt.next_question_id==='END'?'selected':''}>【アンケート終了】</option>
                                        ${allQuestions.filter(aq => aq.id !== q.id).map(aq => `
                                          <option value="${aq.id}" ${opt.next_question_id===aq.id?'selected':''}>→ ${aq.title}</option>
                                        `).join('')}
                                      </select>
                                    ` : ''}
                                    <button class="btn btn-danger-outline btn-sm" onclick="App.removeOption('${opt.id}')">×</button>
                                  </div>
                                `).join('')}
                                <button class="btn btn-outline btn-sm" style="margin-top: 0.25rem;" onclick="App.addOption('${q.id}')">＋ 選択肢追加</button>
                              </div>
                            ` : ''}
                          </div>
                        `;
                      }).join('')}
                    </div>
                    <button class="btn btn-outline btn-sm" style="margin-top: 0.5rem;" onclick="App.addQuestion('${g.id}')">＋ 質問追加</button>
                  </div>
                `;
              }).join('')}
            </div>

            <button class="btn btn-outline" style="width: 100%; border-style: dashed; margin-top: 0.5rem;" onclick="App.addGroup()">＋ 質問グループ追加</button>
          </div>
        `;
      },

      updateGroupName(gid, val) { const g = this.editorDraft.groups.find(x => x.id === gid); if (g) g.name = val; },
      updateQuestionTitle(qid, val) { const q = this.editorDraft.questions.find(x => x.id === qid); if (q) q.title = val; },
      updateQuestionType(qid, val) {
        const q = this.editorDraft.questions.find(x => x.id === qid);
        if (q) {
          q.type = val;
          if (val !== 'text' && !this.editorDraft.options.some(o => o.question_id === qid)) {
            this.addOption(qid);
          }
          this.render();
        }
      },
      updateQuestionRequired(qid, val) { const q = this.editorDraft.questions.find(x => x.id === qid); if (q) q.is_required = val; },
      updateOptionTitle(oid, val) { const o = this.editorDraft.options.find(x => x.id === oid); if (o) o.title = val; },
      updateOptionBranch(oid, val) { const o = this.editorDraft.options.find(x => x.id === oid); if (o) o.next_question_id = val; },

      addGroup() {
        this.editorDraft.groups.push({ id: 'g_' + Date.now(), name: '新しいグループ', order_no: this.editorDraft.groups.length + 1 });
        this.render();
      },
      removeGroup(gid) {
        this.openModal('グループ削除', 'グループ内の質問も削除されます。よろしいですか？', () => {
          this.editorDraft.groups = this.editorDraft.groups.filter(g => g.id !== gid);
          const qIds = this.editorDraft.questions.filter(q => q.group_id === gid).map(q => q.id);
          this.editorDraft.questions = this.editorDraft.questions.filter(q => q.group_id !== gid);
          this.editorDraft.options = this.editorDraft.options.filter(o => !qIds.includes(o.question_id));
          this.render();
        });
      },
      addQuestion(gid) {
        const qid = 'q_' + Date.now();
        this.editorDraft.questions.push({ id: qid, group_id: gid, title: '', type: 'single', is_required: true, order_no: this.editorDraft.questions.length + 1 });
        this.editorDraft.options.push({ id: 'o_' + Date.now() + '_1', question_id: qid, title: '選択肢1', order_no: 1, next_question_id: '' });
        this.render();
      },
      removeQuestion(qid) {
        this.editorDraft.questions = this.editorDraft.questions.filter(q => q.id !== qid);
        this.editorDraft.options = this.editorDraft.options.filter(o => o.question_id !== qid);
        this.render();
      },
      addOption(qid) {
        this.editorDraft.options.push({ id: 'o_' + Date.now(), question_id: qid, title: '新しい選択肢', order_no: this.editorDraft.options.length + 1, next_question_id: '' });
        this.render();
      },
      removeOption(oid) {
        this.editorDraft.options = this.editorDraft.options.filter(o => o.id !== oid);
        this.render();
      },

      async saveSurvey() {
        const d = this.editorDraft;
        d.survey.name = document.getElementById('ed-name').value.trim();
        d.survey.numbering_format = document.getElementById('ed-num-format').value;
        d.survey.description = document.getElementById('ed-desc').value;
        d.survey.start_date = document.getElementById('ed-start').value;
        d.survey.end_date = document.getElementById('ed-end').value;

        if (!d.survey.name) {
          alert('アンケート名は必須です。');
          return;
        }

        const btn = document.getElementById('save-survey-btn');
        btn.disabled = true;
        btn.textContent = '保存中...';

        try {
          const res = await fetch('?action=save_survey', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.state.csrf_token },
            body: JSON.stringify(d)
          });
          const result = await res.json();
          if (result.success) {
            this.showToast('アンケートを保存しました');
            await this.loadData();
            this.navigate('detail', result.survey_id);
          } else {
            alert(result.error || 'エラーが発生しました');
          }
        } catch (e) {
          alert('通信エラーが発生しました');
        } finally {
          btn.disabled = false;
          btn.textContent = '保存';
        }
      },

      // --- 3. アンケート内容確認 ---
      renderSurveyDetail(container) {
        const s = this.getSurvey();
        if (!s) return;
        const groups = this.state.groups.filter(g => g.survey_id === s.id).sort((a,b) => a.order_no - b.order_no);
        const questions = this.state.questions.filter(q => q.survey_id === s.id).sort((a,b) => a.order_no - b.order_no);
        const qNumMap = this.calcQuestionNumbers(s.id, s.numbering_format);

        container.innerHTML = `
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">${s.name}</h2>
              <div style="display: flex; gap: 0.5rem;">
                <button class="btn btn-outline" onclick="App.openEditor('${s.id}')">編集</button>
                <a class="btn btn-primary" href="?respondent=1&sid=${s.id}" target="_blank">回答画面プレビュー</a>
              </div>
            </div>
            <p style="color: var(--text-muted); margin-bottom: 1rem;">${s.description || '説明なし'}</p>
            <div style="display: flex; gap: 1.5rem; font-size: 0.9rem; margin-bottom: 1.5rem;">
              <div><strong>状態:</strong> ${s.status}</div>
              <div><strong>公開期間:</strong> ${s.start_date || '未設定'} 〜 ${s.end_date || '未設定'}</div>
              <div><strong>質問番号形式:</strong> ${s.numbering_format === 'global' ? '全体通番' : 'グループごと'}</div>
            </div>

            <hr style="margin-bottom: 1.5rem; border: none; border-top: 1px solid var(--border);">

            ${groups.map(g => `
              <div style="margin-bottom: 1.5rem;">
                <h3 style="font-size: 1.05rem; margin-bottom: 0.75rem; color: var(--text);">${g.name}</h3>
                ${questions.filter(q => q.group_id === g.id).map(q => {
                  const opts = this.state.options.filter(o => o.question_id === q.id);
                  return `
                    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 0.5rem;">
                      <div style="font-weight: 600; margin-bottom: 0.25rem;">
                        <span style="color: var(--primary);">${qNumMap[q.id]}</span> ${q.title}
                        ${q.is_required ? '<span style="color: var(--danger); font-size: 0.75rem;">(必須)</span>' : ''}
                        <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal; margin-left: 0.5rem;">[形式: ${q.type}]</span>
                      </div>
                      ${opts.length > 0 ? `
                        <ul style="margin-left: 1.25rem; font-size: 0.85rem; color: var(--text-muted);">
                          ${opts.map(o => `<li>${o.title} ${o.next_question_id ? `(分岐: ${o.next_question_id === 'END' ? 'アンケート終了' : o.next_question_id})` : ''}</li>`).join('')}
                        </ul>
                      ` : ''}
                    </div>
                  `;
                }).join('')}
              </div>
            `).join('')}
          </div>
        `;
      },

      // --- 4. 送信画面 ---
      renderSurveySend(container) {
        const s = this.getSurvey();
        const customers = this.state.customers;
        const smtpReady = !empty(this.state.settings.smtp?.host) && !empty(this.state.settings.smtp?.fromEmail);

        container.innerHTML = `
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">メール送信</h2>
              ${s.status !== '公開中' ? '<span class="badge badge-draft">アンケートが公開中ではないため送信できません</span>' : ''}
            </div>

            ${!smtpReady ? `
              <div style="background: #fee2e2; color: #b91c1c; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem;">
                SMTP送信設定が完了していません。「設定」画面でSMTP情報を保存してください。
              </div>
            ` : ''}

            <div class="form-row">
              <!-- 送信先選択 -->
              <div style="flex: 1;">
                <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">送信対象者の選択</h3>
                <input type="text" class="form-control" placeholder="顧客名・メールアドレスで絞り込み" oninput="App.filterCustomers(this.value)" style="margin-bottom: 0.5rem;">
                <div style="max-height: 250px; overflow-y: auto; border: 1px solid var(--border); border-radius: 6px; padding: 0.5rem;" id="cust-list-area">
                  ${customers.map(c => `
                    <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.25rem 0; font-size: 0.85rem; cursor: pointer;">
                      <input type="checkbox" class="send-cust-cb" value="${c.id}">
                      <span>${c.name} (${c.email})</span>
                    </label>
                  `).join('')}
                </div>
              </div>

              <!-- 送信内容 -->
              <div style="flex: 1;">
                <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">メール内容設定</h3>
                <div class="form-group">
                  <label>件名</label>
                  <input type="text" class="form-control" id="mail-subject" value="【アンケートのお願い】${s.name}">
                </div>
                <div class="form-group">
                  <label>本文 ( {name}: 顧客名, {url}: 回答URL )</label>
                  <textarea class="form-control" id="mail-body" rows="6">{name} 様\n\n平素は格別のご高配を賜り御礼申し上げます。\n以
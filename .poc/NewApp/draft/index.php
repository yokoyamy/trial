<?php
declare(strict_types=1);

namespace App\SurveyManager;

// --------------------------------------------------
// 1. セキュリティヘッダー & セッション初期化
// --------------------------------------------------
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

if (empty($_SESSION['survey_app_csrf_token'])) {
    $_SESSION['survey_app_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['survey_app_csrf_token'];

// --------------------------------------------------
// 2. ヘルパー関数
// --------------------------------------------------
function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sendJsonResponse(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --------------------------------------------------
// 3. データストレージ (JSON排他制御付き)
// --------------------------------------------------
const DATA_DIR = __DIR__ . '/data';

function getDataFilePath(string $filename): string {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0777, true);
    }
    return DATA_DIR . '/' . $filename;
}

function loadJson(string $filename, mixed $default = []): mixed {
    $path = getDataFilePath($filename);
    if (!file_exists($path)) {
        return $default;
    }
    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return $default;
    }
    $decoded = json_decode($content, true);
    return ($decoded !== null) ? $decoded : $default;
}

function saveJson(string $filename, mixed $data): bool {
    $path = getDataFilePath($filename);
    $fp = @fopen($path, 'c+');
    if (!$fp) return false;

    $success = false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        $success = ($written !== false);
    }
    fclose($fp);
    return $success;
}

// --------------------------------------------------
// 4. kintone API 連携ロジック (要件9準拠)
// --------------------------------------------------
function callKintoneApi(array $config, string $endpoint, string $method = 'GET', array $params = []): array {
    $subdomain = trim($config['subdomain'] ?? '');
    if ($subdomain === '') {
        return ['ok' => false, 'error' => 'kintoneのドメインまたはサブドメインが未設定です。'];
    }
    
    // URLの二重付与防止
    $host = $subdomain;
    if (!str_contains($host, '.')) {
        $host .= '.cybozu.com';
    }
    $host = preg_replace('#^https?://#', '', $host);
    $host = rtrim($host, '/');
    $url = "https://{$host}/k/v1/{$endpoint}.json";

    $login = $config['login'] ?? '';
    $password = $config['password'] ?? '';
    $authHeader = 'X-Cybozu-Authorization: ' . base64_encode("{$login}:{$password}");

    $headers = [
        $authHeader,
        'User-Agent: PHP-Survey-App/1.0'
    ];

    $httpOptions = [
        'method' => strtoupper($method),
        'ignore_errors' => true,
        'timeout' => 15
    ];

    if ($httpOptions['method'] === 'GET') {
        if (!empty($params)) {
            $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $url .= '?' . $queryString;
        }
    } else {
        $headers[] = 'Content-Type: application/json';
        $httpOptions['content'] = json_encode($params, JSON_UNESCAPED_UNICODE);
    }

    $httpOptions['header'] = implode("\r\n", $headers);

    // プロキシ設定
    if (!empty($config['proxy_host']) && !empty($config['proxy_port'])) {
        $proxy = 'tcp://' . preg_replace('#^https?://#', '', $config['proxy_host']) . ':' . (int)$config['proxy_port'];
        $httpOptions['proxy'] = $proxy;
        $httpOptions['request_fulluri'] = true;
    }

    // SSL証明書検証を無効化
    $sslOptions = [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    ];

    $context = stream_context_create([
        'http' => $httpOptions,
        'ssl' => $sslOptions
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : [];

    if ($responseBody === false) {
        $lastErr = error_get_last();
        return ['ok' => false, 'error' => 'kintone通信エラー: ' . ($lastErr['message'] ?? '接続できませんでした。')];
    }

    $json = json_decode($responseBody, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'kintoneからの応答を解析できませんでした: ' . substr($responseBody, 0, 150)];
    }

    if (isset($json['code']) || isset($json['message'])) {
        $errMsg = "kintone APIエラー [{$json['code']}]: {$json['message']}";
        if (!empty($json['errors'])) {
            $errMsg .= ' (' . json_encode($json['errors'], JSON_UNESCAPED_UNICODE) . ')';
        }
        return ['ok' => false, 'error' => $errMsg];
    }

    return ['ok' => true, 'data' => $json];
}

// --------------------------------------------------
// 5. SMTPメール送信ロジック (cURLなし・fsockopen実装)
// --------------------------------------------------
function sendSmtpMail(array $smtp, string $to, string $toName, string $subject, string $body): array {
    $host = trim($smtp['host'] ?? '');
    $port = (int)($smtp['port'] ?? 587);
    $encryption = strtoupper($smtp['encryption'] ?? 'STARTTLS'); // NONE, SSL, STARTTLS
    $user = $smtp['user'] ?? '';
    $pass = $smtp['pass'] ?? '';
    $fromEmail = $smtp['from_email'] ?? $user;
    $fromName = $smtp['from_name'] ?? 'アンケート事務局';

    if ($host === '' || $fromEmail === '') {
        return ['ok' => false, 'error' => 'SMTP設定（ホストまたは送信元メールアドレス）が不完全です。'];
    }

    $socketHost = ($encryption === 'SSL') ? 'ssl://' . $host : $host;
    $sslContext = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client("{$socketHost}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $sslContext);
    if (!$socket) {
        return ['ok' => false, 'error' => "SMTPサーバー接続失敗 ({$errno}): {$errstr}"];
    }

    stream_set_timeout($socket, 10);

    $readResponse = function() use ($socket): string {
        $res = '';
        while ($line = fgets($socket, 512)) {
            $res .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $res;
    };

    $sendCommand = function(string $cmd) use ($socket, $readResponse): array {
        fwrite($socket, $cmd . "\r\n");
        $res = $readResponse();
        $code = (int)substr($res, 0, 3);
        return [$code, $res];
    };

    $initRes = $readResponse();
    if ((int)substr($initRes, 0, 3) !== 220) {
        fclose($socket);
        return ['ok' => false, 'error' => '初期応答エラー: ' . $initRes];
    }

    [$code, $res] = $sendCommand("EHLO " . gethostname());
    if ($code !== 250) {
        [$code, $res] = $sendCommand("HELO " . gethostname());
        if ($code !== 250) {
            fclose($socket);
            return ['ok' => false, 'error' => 'HELO/EHLOエラー: ' . $res];
        }
    }

    if ($encryption === 'STARTTLS') {
        [$code, $res] = $sendCommand("STARTTLS");
        if ($code !== 220) {
            fclose($socket);
            return ['ok' => false, 'error' => 'STARTTLS開始失敗: ' . $res];
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['ok' => false, 'error' => 'TLSハンドシェイクに失敗しました。'];
        }
        $sendCommand("EHLO " . gethostname());
    }

    if ($user !== '' && $pass !== '') {
        [$code, $res] = $sendCommand("AUTH LOGIN");
        if ($code !== 334) {
            fclose($socket);
            return ['ok' => false, 'error' => 'AUTH LOGIN要求失敗: ' . $res];
        }
        [$code, $res] = $sendCommand(base64_encode($user));
        if ($code !== 334) {
            fclose($socket);
            return ['ok' => false, 'error' => 'AUTH ユーザー認証失敗: ' . $res];
        }
        [$code, $res] = $sendCommand(base64_encode($pass));
        if ($code !== 235) {
            fclose($socket);
            return ['ok' => false, 'error' => 'AUTH パスワード認証失敗: ' . $res];
        }
    }

    [$code, $res] = $sendCommand("MAIL FROM:<{$fromEmail}>");
    if ($code !== 250) {
        fclose($socket);
        return ['ok' => false, 'error' => 'MAIL FROMエラー: ' . $res];
    }

    [$code, $res] = $sendCommand("RCPT TO:<{$to}>");
    if ($code !== 250 && $code !== 251) {
        fclose($socket);
        return ['ok' => false, 'error' => "RCPT TOエラー ({$to}): " . $res];
    }

    [$code, $res] = $sendCommand("DATA");
    if ($code !== 354) {
        fclose($socket);
        return ['ok' => false, 'error' => 'DATA開始エラー: ' . $res];
    }

    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedToName   = !empty($toName) ? '=?UTF-8?B?' . base64_encode($toName) . '?= ' : '';

    $headers = [
        "From: {$encodedFromName} <{$fromEmail}>",
        "To: {$encodedToName}<{$to}>",
        "Subject: {$encodedSubject}",
        "Date: " . date('r'),
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: base64"
    ];

    $dataPayload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body)) . "\r\n.";
    [$code, $res] = $sendCommand($dataPayload);
    $sendCommand("QUIT");
    fclose($socket);

    if ($code !== 250) {
        return ['ok' => false, 'error' => '本文送信エラー: ' . $res];
    }

    return ['ok' => true];
}

// --------------------------------------------------
// 6. API ルーティング (バックエンド処理)
// --------------------------------------------------
if (isset($_GET['api'])) {
    $action = $_GET['api'];
    $method = $_SERVER['REQUEST_METHOD'];

    // CSRF検証 (回答送信等の外部POST以外)
    if ($method === 'POST' && !in_array($action, ['submit_respondent_answer'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrfToken, $token)) {
            sendJsonResponse(['ok' => false, 'error' => 'CSRFトークンが無効または一致しません。画面を再読み込みしてください。'], 403);
        }
    }

    $postData = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // A. 設定の取得・保存
    if ($action === 'get_settings' && $method === 'GET') {
        $settings = loadJson('settings.json', [
            'kintone' => ['subdomain' => '', 'appId' => '', 'login' => '', 'password' => '', 'proxy_host' => '', 'proxy_port' => ''],
            'smtp' => ['host' => '', 'port' => 587, 'encryption' => 'STARTTLS', 'user' => '', 'pass' => '', 'from_email' => '', 'from_name' => '']
        ]);
        // パスワードをマスク
        if (!empty($settings['kintone']['password'])) $settings['kintone']['password_masked'] = true;
        if (!empty($settings['smtp']['pass'])) $settings['smtp']['pass_masked'] = true;
        unset($settings['kintone']['password'], $settings['smtp']['pass']);
        sendJsonResponse(['ok' => true, 'settings' => $settings]);
    }

    if ($action === 'save_settings' && $method === 'POST') {
        $curSettings = loadJson('settings.json', []);
        $newKintone = $postData['kintone'] ?? [];
        $newSmtp = $postData['smtp'] ?? [];

        // パスワードが空文字列で送信された場合は既存のものを維持
        if (empty($newKintone['password']) && !empty($curSettings['kintone']['password'])) {
            $newKintone['password'] = $curSettings['kintone']['password'];
        }
        if (empty($newSmtp['pass']) && !empty($curSettings['smtp']['pass'])) {
            $newSmtp['pass'] = $curSettings['smtp']['pass'];
        }

        $curSettings['kintone'] = $newKintone;
        $curSettings['smtp'] = $newSmtp;
        saveJson('settings.json', $curSettings);
        sendJsonResponse(['ok' => true, 'message' => '設定を保存しました。']);
    }

    // B. kintone 接続テスト & 顧客同期
    if ($action === 'test_kintone' && $method === 'POST') {
        $settings = loadJson('settings.json', []);
        $kConfig = $postData['kintone'] ?? $settings['kintone'] ?? [];
        if (empty($kConfig['password']) && !empty($settings['kintone']['password'])) {
            $kConfig['password'] = $settings['kintone']['password'];
        }

        $appId = (int)($kConfig['appId'] ?? 0);
        if ($appId <= 0) {
            sendJsonResponse(['ok' => false, 'error' => 'アプリIDを正しく入力してください。']);
        }

        $res = callKintoneApi($kConfig, 'records', 'GET', ['app' => $appId, 'query' => 'limit 1']);
        if (!$res['ok']) {
            sendJsonResponse(['ok' => false, 'error' => $res['error']]);
        }
        sendJsonResponse(['ok' => true, 'message' => 'kintoneとの通信・認証に成功しました！']);
    }

    if ($action === 'sync_customers' && $method === 'POST') {
        $settings = loadJson('settings.json', []);
        $kConfig = $settings['kintone'] ?? [];
        $appId = (int)($kConfig['appId'] ?? 0);
        if ($appId <= 0) {
            sendJsonResponse(['ok' => false, 'error' => 'kintone設定でアプリIDが設定されていません。']);
        }

        $res = callKintoneApi($kConfig, 'records', 'GET', ['app' => $appId, 'query' => 'limit 500']);
        if (!$res['ok']) {
            sendJsonResponse(['ok' => false, 'error' => $res['error']]);
        }

        $records = $res['data']['records'] ?? [];
        $customers = [];
        foreach ($records as $rec) {
            $recId = $rec['$id']['value'] ?? $rec['レコード番号']['value'] ?? uniqid('cust_');
            $name = $rec['顧客名']['value'] ?? $rec['氏名']['value'] ?? $rec['name']['value'] ?? '名称未設定';
            $email = $rec['メールアドレス']['value'] ?? $rec['mail']['value'] ?? $rec['email']['value'] ?? '';
            $company = $rec['会社名']['value'] ?? $rec['企業名']['value'] ?? $rec['company']['value'] ?? '';

            $customers[] = [
                'id' => (string)$recId,
                'name' => $name,
                'email' => $email,
                'company' => $company
            ];
        }

        saveJson('customers.json', $customers);
        sendJsonResponse(['ok' => true, 'count' => count($customers), 'customers' => $customers]);
    }

    // C. SMTP 接続テスト
    if ($action === 'test_smtp' && $method === 'POST') {
        $settings = loadJson('settings.json', []);
        $sConfig = $postData['smtp'] ?? $settings['smtp'] ?? [];
        if (empty($sConfig['pass']) && !empty($settings['smtp']['pass'])) {
            $sConfig['pass'] = $settings['smtp']['pass'];
        }
        $to = $sConfig['from_email'] ?? '';
        if ($to === '') {
            sendJsonResponse(['ok' => false, 'error' => 'テスト送信用に送信元メールアドレスを入力してください。']);
        }

        $res = sendSmtpMail($sConfig, $to, 'テスト受信者', '【テスト】SMTP接続確認', "本メールはアンケート管理システムからのSMTP接続テストです。\n正常に送信されました。");
        if (!$res['ok']) {
            sendJsonResponse(['ok' => false, 'error' => $res['error']]);
        }
        sendJsonResponse(['ok' => true, 'message' => "接続テストに成功しました！ {$to} 宛てにテストメールを送信しました。"]);
    }

    // D. アンケート送信実行 (メール送信 & 履歴・送信対象記録)
    if ($action === 'send_survey_mail' && $method === 'POST') {
        $surveyId = (string)($postData['survey_id'] ?? '');
        $customerIds = (array)($postData['customer_ids'] ?? []);
        $subject = trim($postData['subject'] ?? '');
        $bodyTemplate = trim($postData['body'] ?? '');

        if ($surveyId === '' || empty($customerIds) || $subject === '' || $bodyTemplate === '') {
            sendJsonResponse(['ok' => false, 'error' => '必須項目が不足しています。']);
        }

        $surveys = loadJson('surveys.json', []);
        $survey = null;
        foreach ($surveys as $s) {
            if ((string)$s['id'] === $surveyId) { $survey = $s; break; }
        }
        if (!$survey || $survey['status'] !== '公開中') {
            sendJsonResponse(['ok' => false, 'error' => 'アンケートが存在しないか、公開中ではありません。']);
        }

        $settings = loadJson('settings.json', []);
        $smtp = $settings['smtp'] ?? [];
        $customers = loadJson('customers.json', []);
        $recipients = loadJson('recipients.json', []);
        $mailLogs = loadJson('mail_logs.json', []);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . explode('?', $_SERVER['REQUEST_URI'])[0];

        $successCount = 0;
        $failCount = 0;
        $failedList = [];

        foreach ($customerIds as $cId) {
            $c = null;
            foreach ($customers as $cust) {
                if ((string)$cust['id'] === (string)$cId) { $c = $cust; break; }
            }
            if (!$c || empty($c['email'])) {
                $failCount++;
                $failedList[] = ['id' => $cId, 'name' => $c['name'] ?? '不明', 'error' => 'メールアドレスが登録されていません'];
                continue;
            }

            // 送信対象レコード登録または取得
            $recipientId = null;
            foreach ($recipients as &$r) {
                if ((string)$r['survey_id'] === $surveyId && (string)$r['customer_id'] === (string)$cId) {
                    $recipientId = $r['id'];
                    $r['send_status'] = '送信中';
                    break;
                }
            }
            unset($r);

            if (!$recipientId) {
                $recipientId = uniqid('rec_');
                $recipients[] = [
                    'id' => $recipientId,
                    'survey_id' => $surveyId,
                    'customer_id' => (string)$cId,
                    'send_status' => '送信中',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'response_status' => '未回答'
                ];
            }

            // URL差し替え
            $surveyUrl = "{$baseUrl}?respondent=1&sid={$surveyId}&rid={$recipientId}";
            $mailBody = str_replace(
                ['{name}', '{company}', '{survey_name}', '{url}'],
                [$c['name'], $c['company'] ?? '', $survey['name'], $surveyUrl],
                $bodyTemplate
            );

            // 送信実行
            $res = sendSmtpMail($smtp, $c['email'], $c['name'], $subject, $mailBody);
            $logId = uniqid('log_');
            $mailLogs[] = [
                'id' => $logId,
                'recipient_id' => $recipientId,
                'subject' => $subject,
                'body' => $mailBody,
                'sent_at' => date('Y-m-d H:i:s'),
                'is_success' => $res['ok'],
                'error_message' => $res['ok'] ? '' : $res['error'],
                'retry_from_id' => null
            ];

            // 受信者状態更新
            foreach ($recipients as &$r) {
                if ($r['id'] === $recipientId) {
                    $r['send_status'] = $res['ok'] ? '送信成功' : '送信失敗';
                    $r['sent_at'] = date('Y-m-d H:i:s');
                    break;
                }
            }
            unset($r);

            if ($res['ok']) {
                $successCount++;
            } else {
                $failCount++;
                $failedList[] = ['id' => $cId, 'name' => $c['name'], 'error' => $res['error']];
            }
        }

        saveJson('recipients.json', $recipients);
        saveJson('mail_logs.json', $mailLogs);

        sendJsonResponse([
            'ok' => true,
            'summary' => [
                'total' => count($customerIds),
                'success' => $successCount,
                'failed' => $failCount,
                'failed_list' => $failedList,
                'sent_at' => date('Y-m-d H:i:s')
            ]
        ]);
    }

    // E. データ保存（アンケート一式）
    if ($action === 'save_survey' && $method === 'POST') {
        $survey = $postData['survey'] ?? null;
        if (!$survey || empty($survey['name'])) {
            sendJsonResponse(['ok' => false, 'error' => 'アンケート名を入力してください。']);
        }

        $surveys = loadJson('surveys.json', []);
        $groups = loadJson('groups.json', []);
        $questions = loadJson('questions.json', []);
        $options = loadJson('options.json', []);

        $surveyId = !empty($survey['id']) ? (string)$survey['id'] : uniqid('srv_');
        $isNew = true;

        foreach ($surveys as &$s) {
            if ((string)$s['id'] === $surveyId) {
                $s['name'] = $survey['name'];
                $s['description'] = $survey['description'] ?? '';
                $s['status'] = $survey['status'] ?? '下書き';
                $s['start_date'] = $survey['start_date'] ?? date('Y-m-d');
                $s['end_date'] = $survey['end_date'] ?? date('Y-m-d', strtotime('+1 month'));
                $s['numbering_format'] = $survey['numbering_format'] ?? 'group';
                $s['updated_at'] = date('Y-m-d H:i:s');
                $isNew = false;
                break;
            }
        }
        unset($s);

        if ($isNew) {
            $surveys[] = [
                'id' => $surveyId,
                'name' => $survey['name'],
                'description' => $survey['description'] ?? '',
                'status' => $survey['status'] ?? '下書き',
                'start_date' => $survey['start_date'] ?? date('Y-m-d'),
                'end_date' => $survey['end_date'] ?? date('Y-m-d', strtotime('+1 month')),
                'numbering_format' => $survey['numbering_format'] ?? 'group',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }

        // 既存の関連グループ・質問・選択肢を整理して更新
        $groups = array_values(array_filter($groups, fn($g) => (string)$g['survey_id'] !== $surveyId));
        $questions = array_values(array_filter($questions, fn($q) => (string)($q['survey_id'] ?? '') !== $surveyId));
        $options = array_values(array_filter($options, fn($o) => (string)($o['survey_id'] ?? '') !== $surveyId));

        $gOrder = 1;
        foreach (($survey['groups'] ?? []) as $g) {
            $gId = !empty($g['id']) ? (string)$g['id'] : uniqid('grp_');
            $groups[] = [
                'id' => $gId,
                'survey_id' => $surveyId,
                'name' => $g['name'] ?? 'グループ',
                'sort_order' => $gOrder++
            ];

            $qOrder = 1;
            foreach (($g['questions'] ?? []) as $q) {
                $qId = !empty($q['id']) ? (string)$q['id'] : uniqid('qst_');
                $questions[] = [
                    'id' => $qId,
                    'survey_id' => $surveyId,
                    'group_id' => $gId,
                    'question_text' => $q['text'] ?? '',
                    'answer_type' => $q['type'] ?? 'single', // text, single, multiple
                    'is_required' => !empty($q['required']),
                    'sort_order' => $qOrder++
                ];

                $optOrder = 1;
                foreach (($q['choices'] ?? []) as $choiceText) {
                    $optId = uniqid('opt_');
                    $branch = $q['branches'][$choiceText] ?? null;
                    $options[] = [
                        'id' => $optId,
                        'survey_id' => $surveyId,
                        'question_id' => $qId,
                        'option_text' => $choiceText,
                        'sort_order' => $optOrder++,
                        'next_question_id' => ($branch === '__END__' ? '__END__' : $branch)
                    ];
                }
            }
        }

        saveJson('surveys.json', $surveys);
        saveJson('groups.json', $groups);
        saveJson('questions.json', $questions);
        saveJson('options.json', $options);

        sendJsonResponse(['ok' => true, 'survey_id' => $surveyId, 'message' => 'アンケートを保存しました。']);
    }

    // F. 回答者画面用 API (回答送信)
    if ($action === 'submit_respondent_answer' && $method === 'POST') {
        $surveyId = (string)($postData['survey_id'] ?? '');
        $recipientId = !empty($postData['recipient_id']) ? (string)$postData['recipient_id'] : null;
        $answers = (array)($postData['answers'] ?? []);

        $surveys = loadJson('surveys.json', []);
        $survey = null;
        foreach ($surveys as $s) {
            if ((string)$s['id'] === $surveyId) { $survey = $s; break; }
        }
        if (!$survey || $survey['status'] !== '公開中') {
            sendJsonResponse(['ok' => false, 'error' => '現在このアンケートは回答を受け付けておりません。']);
        }

        $responses = loadJson('responses.json', []);
        $responseAnswers = loadJson('response_answers.json', []);

        // 二重回答チェック（受信者IDが存在する場合）
        if ($recipientId) {
            foreach ($responses as $r) {
                if ((string)$r['survey_id'] === $surveyId && (string)$r['recipient_id'] === $recipientId) {
                    sendJsonResponse(['ok' => false, 'error' => '既に回答済みです。ご回答ありがとうございました。']);
                }
            }
        }

        $respId = uniqid('rsp_');
        $responses[] = [
            'id' => $respId,
            'survey_id' => $surveyId,
            'recipient_id' => $recipientId,
            'answered_at' => date('Y-m-d H:i:s'),
            'status' => '完了'
        ];

        foreach ($answers as $qId => $val) {
            $responseAnswers[] = [
                'id' => uniqid('ans_'),
                'response_id' => $respId,
                'question_id' => (string)$qId,
                'answer_value' => is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string)$val
            ];
        }

        // 受信者ステータス更新
        if ($recipientId) {
            $recipients = loadJson('recipients.json', []);
            foreach ($recipients as &$rec) {
                if ($rec['id'] === $recipientId) {
                    $rec['response_status'] = '回答済み';
                    break;
                }
            }
            unset($rec);
            saveJson('recipients.json', $recipients);
        }

        saveJson('responses.json', $responses);
        saveJson('response_answers.json', $responseAnswers);

        sendJsonResponse(['ok' => true, 'message' => 'ご回答ありがとうございました。送信が完了しました。']);
    }

    sendJsonResponse(['ok' => false, 'error' => '未定義のAPIリクエストです。'], 404);
}

// --------------------------------------------------
// 7. 回答者画面 レンダリング (運営メニューなし)
// --------------------------------------------------
if (isset($_GET['respondent'])) {
    $surveyId = (string)($_GET['sid'] ?? '');
    $recipientId = (string)($_GET['rid'] ?? '');

    $surveys = loadJson('surveys.json', []);
    $groups = loadJson('groups.json', []);
    $questions = loadJson('questions.json', []);
    $options = loadJson('options.json', []);

    $survey = null;
    foreach ($surveys as $s) {
        if ((string)$s['id'] === $surveyId) { $survey = $s; break; }
    }

    $isClosed = (!$survey || $survey['status'] !== '公開中');
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title><?= h($survey['name'] ?? 'アンケート') ?></title>
      <style>
        :root { --primary: #2563eb; --bg: #f8fafc; --surface: #ffffff; --border: #e2e8f0; --text: #1e293b; --danger: #dc2626; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: var(--bg); color: var(--text); padding: 2rem 1rem; }
        .container { max-width: 680px; margin: 0 auto; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        h1 { font-size: 1.4rem; margin-bottom: 0.5rem; color: #0f172a; }
        p.desc { font-size: 0.95rem; color: #64748b; margin-bottom: 2rem; white-space: pre-wrap; }
        .q-block { margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border); }
        .q-title { font-size: 1rem; font-weight: 600; margin-bottom: 0.75rem; }
        .req { color: var(--danger); font-size: 0.8rem; margin-left: 0.25rem; }
        .choice-item { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.6rem; font-size: 0.95rem; cursor: pointer; }
        textarea, input[type="text"] { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; }
        .btn-submit { width: 100%; background: var(--primary); color: #fff; border: none; padding: 0.85rem; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
        .msg-box { padding: 1.5rem; background: #dcfce7; color: #15803d; border-radius: 6px; text-align: center; font-weight: 600; }
        .err-box { padding: 1.5rem; background: #fee2e2; color: #991b1b; border-radius: 6px; text-align: center; }
      </style>
    </head>
    <body>
      <div class="container" id="app">
        <?php if ($isClosed): ?>
          <div class="err-box">このアンケートは現在公開されていないか、終了しております。</div>
        <?php else: ?>
          <h1><?= h($survey['name']) ?></h1>
          <p class="desc"><?= h($survey['description']) ?></p>

          <form id="resp-form">
            <?php
            $sGroups = array_filter($groups, fn($g) => (string)$g['survey_id'] === $surveyId);
            usort($sGroups, fn($a, $b) => ($a['sort_order'] <=> $b['sort_order']));

            $gIdx = 1; $totalQ = 1;
            foreach ($sGroups as $grp):
                $gQuestions = array_filter($questions, fn($q) => (string)$q['group_id'] === (string)$grp['id']);
                usort($gQuestions, fn($a, $b) => ($a['sort_order'] <=> $b['sort_order']));
                $qIdx = 1;
                foreach ($gQuestions as $q):
                    $numLabel = ($survey['numbering_format'] === 'global') ? "Q{$totalQ}" : "Q{$gIdx}-{$qIdx}";
                    $qOptions = array_filter($options, fn($o) => (string)$o['question_id'] === (string)$q['id']);
                    usort($qOptions, fn($a, $b) => ($a['sort_order'] <=> $b['sort_order']));
            ?>
              <div class="q-block" id="block-<?= h($q['id']) ?>" data-qid="<?= h($q['id']) ?>">
                <div class="q-title">
                  <span><?= $numLabel ?>. <?= h($q['question_text']) ?></span>
                  <?php if ($q['is_required']): ?><span class="req">*必須</span><?php endif; ?>
                </div>

                <?php if ($q['answer_type'] === 'text'): ?>
                  <textarea name="ans[<?= h($q['id']) ?>]" rows="3" <?= $q['is_required'] ? 'data-required="true"' : '' ?> placeholder="ご自由に入力してください"></textarea>
                <?php elseif ($q['answer_type'] === 'single'): ?>
                  <?php foreach ($qOptions as $opt): ?>
                    <label class="choice-item">
                      <input type="radio" name="ans[<?= h($q['id']) ?>]" value="<?= h($opt['option_text']) ?>" data-next="<?= h($opt['next_question_id']) ?>" <?= $q['is_required'] ? 'data-required="true"' : '' ?> onchange="handleBranch(this)">
                      <span><?= h($opt['option_text']) ?></span>
                    </label>
                  <?php endforeach; ?>
                <?php elseif ($q['answer_type'] === 'multiple'): ?>
                  <?php foreach ($qOptions as $opt): ?>
                    <label class="choice-item">
                      <input type="checkbox" name="ans[<?= h($q['id']) ?>][]" value="<?= h($opt['option_text']) ?>">
                      <span><?= h($opt['option_text']) ?></span>
                    </label>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php
                    $qIdx++; $totalQ++;
                endforeach;
                $gIdx++;
            endforeach;
            ?>
            <button type="button" id="btn-submit" class="btn-submit" onclick="submitAnswers()">回答を送信する</button>
          </form>
        <?php endif; ?>
      </div>

      <script>
        function handleBranch(radio) {
          // 単一選択の分岐処理
          const nextQId = radio.getAttribute('data-next');
          // 将来の動的スキップ制御
        }

        async function submitAnswers() {
          const btn = document.getElementById('btn-submit');
          btn.disabled = true;
          btn.textContent = '送信中...';

          const form = document.getElementById('resp-form');
          const formData = new FormData(form);
          const answers = {};

          // 必須チェック
          let hasError = false;
          document.querySelectorAll('.q-block').forEach(block => {
            const qid = block.getAttribute('data-qid');
            const reqInput = block.querySelector('[data-required="true"]');
            if (reqInput) {
              if (reqInput.type === 'radio') {
                const checked = block.querySelector('input[type="radio"]:checked');
                if (!checked) { hasError = true; }
                else { answers[qid] = checked.value; }
              } else if (reqInput.tagName === 'TEXTAREA') {
                if (!reqInput.value.trim()) { hasError = true; }
                else { answers[qid] = reqInput.value.trim(); }
              }
            } else {
              const textVal = block.querySelector('textarea')?.value;
              const radioVal = block.querySelector('input[type="radio"]:checked')?.value;
              const checks = Array.from(block.querySelectorAll('input[type="checkbox"]:checked')).map(c => c.value);
              if (textVal !== undefined) answers[qid] = textVal;
              if (radioVal !== undefined) answers[qid] = radioVal;
              if (checks.length > 0) answers[qid] = checks;
            }
          });

          if (hasError) {
            alert('必須項目にすべてご回答ください。');
            btn.disabled = false;
            btn.textContent = '回答を送信する';
            return;
          }

          try {
            const res = await fetch('index.php?api=submit_respondent_answer', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                survey_id: '<?= h($surveyId) ?>',
                recipient_id: '<?= h($recipientId) ?>',
                answers: answers
              })
            });
            const json = await res.json();
            if (json.ok) {
              document.getElementById('app').innerHTML = `<div class="msg-box">${json.message}</div>`;
            } else {
              alert(json.error || 'エラーが発生しました。');
              btn.disabled = false;
              btn.textContent = '回答を送信する';
            }
          } catch(e) {
            alert('通信エラーが発生しました。');
            btn.disabled = false;
            btn.textContent = '回答を送信する';
          }
        }
      </script>
    </body>
    </html>
    <?php
    exit;
}

// --------------------------------------------------
// 8. 運営者画面（フル機能UI）
// --------------------------------------------------
$allSurveys = loadJson('surveys.json', []);
$allGroups = loadJson('groups.json', []);
$allQuestions = loadJson('questions.json', []);
$allOptions = loadJson('options.json', []);
$allCustomers = loadJson('customers.json', []);
$allRecipients = loadJson('recipients.json', []);
$allResponses = loadJson('responses.json', []);
$allAnswers = loadJson('response_answers.json', []);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>アンケート業務運営アプリ</title>
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
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    body { background-color: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }
    header { background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; }
    .header-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 0 1rem; }
    .logo { font-weight: bold; font-size: 1.1rem; color: var(--primary); padding: 1rem 0; }
    nav ul { display: flex; list-style: none; gap: 0.5rem; }
    nav button { background: none; border: none; padding: 1rem 0.75rem; font-size: 0.95rem; cursor: pointer; color: var(--text-muted); font-weight: 500; border-bottom: 2px solid transparent; }
    nav button.active { color: var(--primary); border-bottom-color: var(--primary); }
    .sub-nav { background: #f1f5f9; border-bottom: 1px solid var(--border); padding: 0.5rem 1rem; }
    .sub-nav-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; }
    .sub-nav-title { font-weight: 600; font-size: 0.9rem; color: var(--text-muted); }
    .sub-nav-title span { color: var(--text); }
    .sub-nav ul { display: flex; list-style: none; gap: 0.5rem; }
    .sub-nav button { background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 0.35rem 0.75rem; font-size: 0.85rem; cursor: pointer; }
    .sub-nav button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
    main { max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem; flex: 1; width: 100%; }
    .card { background: var(--surface); border-radius: 8px; border: 1px solid var(--border); padding: 1.5rem; margin-bottom: 1.5rem; }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; }
    .card-title { font-size: 1.15rem; font-weight: 600; }
    .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.9rem; font-weight: 500; cursor: pointer; border: 1px solid transparent; }
    .btn-primary { background: var(--primary); color: #fff; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-outline { background: var(--surface); border-color: var(--border); color: var(--text); }
    .btn-danger-outline { border-color: var(--danger); color: var(--danger); background: transparent; }
    .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.8rem; }
    .badge { padding: 0.25rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
    .badge-draft { background: #e2e8f0; color: #475569; }
    .badge-active { background: #dcfce7; color: #15803d; }
    .badge-closed { background: #fee2e2; color: #b91c1c; }
    .form-group { margin-bottom: 1rem; }
    .form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; }
    .form-control { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; }
    .form-row { display: flex; gap: 1rem; }
    .form-row .form-group { flex: 1; }
    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
    th, td { padding: 0.75rem; border-bottom: 1px solid var(--border); }
    th { background: #f8fafc; font-weight: 600; color: var(--text-muted); }
    .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #334155; color: #fff; padding: 0.75rem 1.25rem; border-radius: 6px; z-index: 1000; font-size: 0.9rem; display: none; }
  </style>
</head>
<body>

  <header id="app-header">
    <div class="header-container">
      <div class="logo">アンケート業務運営アプリ</div>
      <nav>
        <ul>
          <li><button id="nav-list" class="active" onclick="App.navigate('survey-list')">アンケート一覧</button></li>
          <li><button id="nav-create" onclick="App.navigate('survey-editor')">アンケート作成</button></li>
          <li><button id="nav-cust" onclick="App.navigate('customer-list')">顧客一覧</button></li>
          <li><button id="nav-sett" onclick="App.navigate('settings')">設定</button></li>
        </ul>
      </nav>
    </div>
    <div id="sub-nav-bar" class="sub-nav" style="display: none;">
      <div class="sub-nav-container">
        <div class="sub-nav-title">選択中: <span id="current-survey-name"></span></div>
        <ul>
          <li><button id="subnav-detail" class="active" onclick="App.navigateSub('detail')">アンケート内容</button></li>
          <li><button id="subnav-send" onclick="App.navigateSub('send')">送信</button></li>
          <li><button id="subnav-status" onclick="App.navigateSub('status')">回答状況</button></li>
          <li><button id="subnav-result" onclick="App.navigateSub('result')">回答結果</button></li>
        </ul>
      </div>
    </div>
  </header>

  <main id="main-content"></main>
  <div id="toast" class="toast"></div>

  <script>
    // グローバルAppオブジェクト定義 (Uncaught ReferenceError: App is not defined の完全防止)
    const App = {
      csrfToken: '<?= $csrfToken ?>',
      currentView: 'survey-list',
      currentSubView: 'detail',
      selectedSurveyId: null,

      showToast(msg) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.style.display = 'block';
        setTimeout(() => { t.style.display = 'none'; }, 3000);
      },

      async postApi(action, data = {}) {
        const res = await fetch(`index.php?api=${action}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': this.csrfToken
          },
          body: JSON.stringify(data)
        });
        return await res.json();
      },

      navigate(view, sid = null) {
        this.currentView = view;
        if (sid) this.selectedSurveyId = sid;

        document.querySelectorAll('#app-header nav button').forEach(b => b.classList.remove('active'));
        if (view === 'survey-list') document.getElementById('nav-list')?.classList.add('active');
        if (view === 'survey-editor') document.getElementById('nav-create')?.classList.add('active');
        if (view === 'customer-list') document.getElementById('nav-cust')?.classList.add('active');
        if (view === 'settings') document.getElementById('nav-sett')?.classList.add('active');

        const isSub = ['detail', 'send', 'status', 'result'].includes(view);
        document.getElementById('sub-nav-bar').style.display = isSub ? 'block' : 'none';

        this.render();
      },

      navigateSub(subView) {
        this.currentSubView = subView;
        this.navigate(subView, this.selectedSurveyId);
      },

      render() {
        const c = document.getElementById('main-content');
        if (this.currentView === 'survey-list') this.renderSurveyList(c);
        if (this.currentView === 'settings') this.renderSettings(c);
        if (this.currentView === 'customer-list') this.renderCustomers(c);
        if (this.currentView === 'send') this.renderSend(c);
        if (this.currentView === 'survey-editor') this.renderEditor(c);
      },

      renderSurveyList(el) {
        el.innerHTML = `
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">アンケート一覧</h2>
              <button class="btn btn-primary" onclick="App.navigate('survey-editor')">＋ 新規作成</button>
            </div>
            <table>
              <thead>
                <tr>
                  <th>アンケート名</th><th>状態</th><th>作成日</th><th>操作</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($allSurveys as $s): ?>
                  <tr>
                    <td><strong><?= h($s['name']) ?></strong></td>
                    <td><span class="badge badge-<?= $s['status'] === '公開中' ? 'active' : 'draft' ?>"><?= h($s['status']) ?></span></td>
                    <td><?= h($s['created_at'] ?? '-') ?></td>
                    <td>
                      <button class="btn btn-outline btn-sm" onclick="App.navigate('send', '<?= h($s['id']) ?>')">送信管理</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($allSurveys)): ?>
                  <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">アンケートがまだありません。</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        `;
      },

      async renderSettings(el) {
        el.innerHTML = `<div class="card"><div class="card-title">設定を読み込み中...</div></div>`;
        const res = await fetch('index.php?api=get_settings');
        const data = await res.json();
        const k = data.settings?.kintone || {};
        const s = data.settings?.smtp || {};

        el.innerHTML = `
          <div class="card">
            <div class="card-header"><h2 class="card-title">kintone 連携設定</h2></div>
            <div class="form-row">
              <div class="form-group"><label>利用先 (サブドメインまたはFQDN)</label><input id="k_host" class="form-control" value="${k.subdomain || ''}" placeholder="example.cybozu.com"></div>
              <div class="form-group"><label>顧客管理アプリID</label><input id="k_appid" class="form-control" value="${k.appId || ''}" placeholder="101"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>ログイン名</label><input id="k_login" class="form-control" value="${k.login || ''}"></div>
              <div class="form-group"><label>パスワード ${k.password_masked ? '(設定済み)' : ''}</label><input id="k_pass" type="password" class="form-control" placeholder="${k.password_masked ? '変更する場合のみ入力' : ''}"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>プロキシホスト (任意)</label><input id="k_proxy_host" class="form-control" value="${k.proxy_host || ''}"></div>
              <div class="form-group"><label>プロキシポート (任意)</label><input id="k_proxy_port" class="form-control" value="${k.proxy_port || ''}"></div>
            </div>
            <button class="btn btn-outline" onclick="App.testKintone()">kintone 接続テスト</button>
          </div>

          <div class="card">
            <div class="card-header"><h2 class="card-title">メール送信 (SMTP) 設定</h2></div>
            <div class="form-row">
              <div class="form-group"><label>SMTPホスト</label><input id="s_host" class="form-control" value="${s.host || ''}" placeholder="smtp.example.com"></div>
              <div class="form-group"><label>ポート</label><input id="s_port" class="form-control" value="${s.port || 587}"></div>
              <div class="form-group"><label>暗号化</label>
                <select id="s_enc" class="form-control">
                  <option value="STARTTLS" ${s.encryption === 'STARTTLS' ? 'selected' : ''}>STARTTLS</option>
                  <option value="SSL" ${s.encryption === 'SSL' ? 'selected' : ''}>SSL/TLS</option>
                  <option value="NONE" ${s.encryption === 'NONE' ? 'selected' : ''}>なし</option>
                </select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>認証ユーザー名</label><input id="s_user" class="form-control" value="${s.user || ''}"></div>
              <div class="form-group"><label>パスワード ${s.pass_masked ? '(設定済み)' : ''}</label><input id="s_pass" type="password" class="form-control" placeholder="${s.pass_masked ? '変更する場合のみ入力' : ''}"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>送信元メールアドレス</label><input id="s_from_email" class="form-control" value="${s.from_email || ''}"></div>
              <div class="form-group"><label>送信元名</label><input id="s_from_name" class="form-control" value="${s.from_name || ''}"></div>
            </div>
            <button class="btn btn-outline" onclick="App.testSmtp()">SMTP 接続テスト</button>
          </div>

          <div style="text-align: right; margin-bottom: 2rem;">
            <button class="btn btn-primary" onclick="App.saveSettings()">すべての設定を保存</button>
          </div>
        `;
      },

      async saveSettings() {
        const payload = {
          kintone: {
            subdomain: document.getElementById('k_host').value.trim(),
            appId: document.getElementById('k_appid').value.trim(),
            login: document.getElementById('k_login').value.trim(),
            password: document.getElementById('k_pass').value,
            proxy_host: document.getElementById('k_proxy_host').value.trim(),
            proxy_port: document.getElementById('k_proxy_port').value.trim()
          },
          smtp: {
            host: document.getElementById('s_host').value.trim(),
            port: document.getElementById('s_port').value.trim(),
            encryption: document.getElementById('s_enc').value,
            user: document.getElementById('s_user').value.trim(),
            pass: document.getElementById('s_pass').value,
            from_email: document.getElementById('s_from_email').value.trim(),
            from_name: document.getElementById('s_from_name').value.trim()
          }
        };
        const res = await this.postApi('save_settings', payload);
        if (res.ok) { this.showToast(res.message); }
        else { alert(res.error); }
      },

      async testKintone() {
        const res = await this.postApi('test_kintone', {
          kintone: {
            subdomain: document.getElementById('k_host').value.trim(),
            appId: document.getElementById('k_appid').value.trim(),
            login: document.getElementById('k_login').value.trim(),
            password: document.getElementById('k_pass').value,
            proxy_host: document.getElementById('k_proxy_host').value.trim(),
            proxy_port: document.getElementById('k_proxy_port').value.trim()
          }
        });
        alert(res.ok ? res.message : res.error);
      },

      async testSmtp() {
        const res = await this.postApi('test_smtp', {
          smtp: {
            host: document.getElementById('s_host').value.trim(),
            port: document.getElementById('s_port').value.trim(),
            encryption: document.getElementById('s_enc').value,
            user: document.getElementById('s_user').value.trim(),
            pass: document.getElementById('s_pass').value,
            from_email: document.getElementById('s_from_email').value.trim(),
            from_name: document.getElementById('s_from_name').value.trim()
          }
        });
        alert(res.ok ? res.message : res.error);
      },

      renderCustomers(el) {
        el.innerHTML = `
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">顧客一覧</h2>
              <button class="btn btn-outline" id="btn-sync-cust" onclick="App.syncCustomers()">kintoneから顧客データを同期</button>
            </div>
            <table>
              <thead><tr><th>ID</th><th>顧客名</th><th>会社名</th><th>メールアドレス</th></tr></thead>
              <tbody>
                <?php foreach ($allCustomers as $c): ?>
                  <tr>
                    <td><?= h($c['id']) ?></td>
                    <td><strong><?= h($c['name']) ?></strong></td>
                    <td><?= h($c['company'] ?? '-') ?></td>
                    <td><?= h($c['email']) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($allCustomers)): ?>
                  <tr><td colspan="4" style="text-align:center; color: var(--text-muted);">顧客データがありません。「kintoneから顧客データを同期」を実行してください。</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        `;
      },

      async syncCustomers() {
        const btn = document.getElementById('btn-sync-cust');
        btn.disabled = true;
        btn.textContent = '同期中...';
        try {
          const res = await this.postApi('sync_customers');
          if (res.ok) {
            alert(`${res.count} 件の顧客データを取得・保存しました。`);
            location.reload();
          } else {
            alert(res.error);
          }
        } finally {
          btn.disabled = false;
          btn.textContent = 'kintoneから顧客データを同期';
        }
      },

      renderSend(el) {
        el.innerHTML = `
          <div class="card">
            <div class="card-header"><h2 class="card-title">アンケート一括メール送信</h2></div>
            <div class="form-group">
              <label>メール件名</label>
              <input id="mail_subj" class="form-control" value="【ご協力のお願い】アンケートへのご回答">
            </div>
            <div class="form-group">
              <label>メール本文 ({name}, {company}, {survey_name}, {url} が置換されます)</label>
              <textarea id="mail_body" class="form-control" rows="6">{name} 様\n\n平素より大変お世話になっております。\n以下のアンケートへのご協力をお願い申し上げます。\n\n▼ アンケート回答URL\n{url}\n\n何卒よろしくお願いいたします。</textarea>
            </div>
            <div class="form-group">
              <label>送信対象者を選択</label>
              <table>
                <thead><tr><th><input type="checkbox" onchange="document.querySelectorAll('.cust-cb').forEach(c=>c.checked=this.checked)"></th><th>氏名</th><th>メール</th></tr></thead>
                <tbody>
                  <?php foreach ($allCustomers as $c): ?>
                    <tr>
                      <td><input type="checkbox" class="cust-cb" value="<?= h($c['id']) ?>"></td>
                      <td><?= h($c['name']) ?></td>
                      <td><?= h($c['email']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <button class="btn btn-primary" id="btn-send-mail" onclick="App.executeSend()">メール送信を実行</button>
          </div>
        `;
      },

      async executeSend() {
        const cbs = Array.from(document.querySelectorAll('.cust-cb:checked')).map(c => c.value);
        if (cbs.length === 0) { alert('送信対象者を選択してください。'); return; }

        const btn = document.getElementById('btn-send-mail');
        btn.disabled = true;
        btn.textContent = '送信中...';

        try {
          const res = await this.postApi('send_survey_mail', {
            survey_id: this.selectedSurveyId || '<?= $allSurveys[0]['id'] ?? '' ?>',
            customer_ids: cbs,
            subject: document.getElementById('mail_subj').value,
            body: document.getElementById('mail_body').value
          });

          if (res.ok) {
            alert(`送信完了\n成功: ${res.summary.success} 件\n失敗: ${res.summary.failed} 件`);
          } else {
            alert(res.error);
          }
        } finally {
          btn.disabled = false;
          btn.textContent = 'メール送信を実行';
        }
      },

      renderEditor(el) {
        el.innerHTML = `
          <div class="card">
            <div class="card-header"><h2 class="card-title">アンケート作成</h2></div>
            <div class="form-group"><label>アンケート名</label><input id="ed_name" class="form-control" placeholder="例: 満足度アンケート"></div>
            <div class="form-group"><label>説明</label><textarea id="ed_desc" class="form-control" rows="3"></textarea></div>
            <div class="form-group"><label>状態</label>
              <select id="ed_status" class="form-control">
                <option value="公開中">公開中</option>
                <option value="下書き">下書き</option>
              </select>
            </div>
            <button class="btn btn-primary" onclick="App.saveSurveySimple()">保存して公開</button>
          </div>
        `;
      },

      async saveSurveySimple() {
        const name = document.getElementById('ed_name').value.trim();
        if (!name) { alert('アンケート名を入力してください'); return; }

        const res = await this.postApi('save_survey', {
          survey: {
            name: name,
            description: document.getElementById('ed_desc').value,
            status: document.getElementById('ed_status').value,
            groups: [{
              name: '基本質問',
              questions: [{
                text: '使い心地はいかがでしたか？',
                type: 'single',
                required: true,
                choices: ['大変満足', '満足', '不満']
              }]
            }]
          }
        });

        if (res.ok) {
          alert('アンケートを作成・保存しました。');
          location.reload();
        } else {
          alert(res.error);
        }
      }
    };

    document.addEventListener('DOMContentLoaded', () => {
      App.render();
    });
  </script>
</body>
</html>
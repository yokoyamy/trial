<?php
declare(strict_types=1);

namespace SurveyApp\Core;

// セッション・セキュリティヘッダー
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

// CSRFトークン管理
if (empty($_SESSION['survey_app_csrf_token'])) {
    $_SESSION['survey_app_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['survey_app_csrf_token'];

// NULL安全なHTMLエスケープ関数
function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// データ保存用ディレクトリ
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

// JSONデータ操作ヘルパー
class DataStore {
    public static function getFilePath(string $name): string {
        return __DIR__ . '/data/' . $name . '.json';
    }

    public static function load(string $name, array $default = []): array {
        $path = self::getFilePath($name);
        if (!file_exists($path)) {
            return $default;
        }
        $fp = @fopen($path, 'r');
        if (!$fp) return $default;
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if (!$content) return $default;
        $data = json_decode($content, true);
        return is_array($data) ? $data : $default;
    }

    public static function save(string $name, array $data): bool {
        $path = self::getFilePath($name);
        $fp = @fopen($path, 'c+');
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

    public static function nextId(string $name): int {
        $items = self::load($name, []);
        $max = 0;
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > $max) $max = $id;
        }
        return $max + 1;
    }
}

// メール送信ヘルパー (fsockopen SMTP)
class Mailer {
    public static function send(array $smtp, string $to, string $subject, string $body): array {
        $host = trim($smtp['host'] ?? '');
        $port = (int)($smtp['port'] ?? 587);
        $secure = strtolower($smtp['secure'] ?? 'tls');
        $user = trim($smtp['user'] ?? '');
        $pass = (string)($smtp['pass'] ?? '');
        $fromEmail = trim($smtp['fromEmail'] ?? '');
        $fromName = trim($smtp['fromName'] ?? '');

        if (empty($host) || empty($fromEmail)) {
            return ['ok' => false, 'error' => 'SMTP設定（ホスト名・送信元アドレス）が未完了です。'];
        }

        $connectHost = ($secure === 'ssl') ? 'ssl://' . $host : $host;
        $socket = @fsockopen($connectHost, $port, $errno, $errstr, 10);
        if (!$socket) {
            return ['ok' => false, 'error' => "接続失敗: {$errstr} ({$errno})"];
        }

        $getResponse = function($sock) {
            $res = '';
            while ($line = fgets($sock, 515)) {
                $res .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $res;
        };

        $sendCommand = function($sock, $cmd) use ($getResponse) {
            fputs($sock, $cmd . "\r\n");
            return $getResponse($sock);
        };

        $res = $getResponse($socket);
        if (substr($res, 0, 3) !== '220') {
            fclose($socket);
            return ['ok' => false, 'error' => 'SMTP Greeting Error: ' . $res];
        }

        $sendCommand($socket, 'EHLO ' . gethostname());

        if ($secure === 'tls') {
            $res = $sendCommand($socket, 'STARTTLS');
            if (substr($res, 0, 3) !== '220') {
                fclose($socket);
                return ['ok' => false, 'error' => 'STARTTLS Failed: ' . $res];
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return ['ok' => false, 'error' => 'TLS暗号化の開始に失敗しました。'];
            }
            $sendCommand($socket, 'EHLO ' . gethostname());
        }

        if (!empty($user) && !empty($pass)) {
            $res = $sendCommand($socket, 'AUTH LOGIN');
            if (substr($res, 0, 3) === '334') {
                $sendCommand($socket, base64_encode($user));
                $res = $sendCommand($socket, base64_encode($pass));
                if (substr($res, 0, 3) !== '235') {
                    fclose($socket);
                    return ['ok' => false, 'error' => 'SMTP認証に失敗しました。ユーザー名・パスワードをご確認ください。'];
                }
            }
        }

        $sendCommand($socket, "MAIL FROM:<{$fromEmail}>");
        $res = $sendCommand($socket, "RCPT TO:<{$to}>");
        if (substr($res, 0, 3) !== '250') {
            fclose($socket);
            return ['ok' => false, 'error' => 'RCPT TO Error: ' . $res];
        }

        $sendCommand($socket, 'DATA');

        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = [
            "From: {$encodedFromName} <{$fromEmail}>",
            "To: <{$to}>",
            "Subject: {$encodedSubject}",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: base64"
        ];

        $emailData = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body)) . "\r\n.";
        $res = $sendCommand($socket, $emailData);
        $sendCommand($socket, 'QUIT');
        fclose($socket);

        if (substr($res, 0, 3) === '250') {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'メール送信失敗: ' . $res];
    }
}

// kintone APIヘルパー (stream_context & file_get_contents)
class KintoneClient {
    public static function request(array $settings, string $method, string $path, array $params = []): array {
        $host = trim($settings['host'] ?? '');
        $host = preg_replace('#^https?://#', '', $host);
        $host = rtrim($host, '/');
        if (!str_contains($host, '.')) {
            $host .= '.cybozu.com';
        }

        $login = (string)($settings['login'] ?? '');
        $pass = (string)($settings['pass'] ?? '');
        $auth = base64_encode("{$login}:{$pass}");

        $url = "https://{$host}/k/v1/{$path}";
        $headers = [
            "X-Cybozu-Authorization: {$auth}",
            "User-Agent: SurveyApp/1.0"
        ];

        $httpOptions = [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 15
        ];

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        } elseif ($method === 'POST' || $method === 'PUT') {
            $headers[] = 'Content-Type: application/json';
            $httpOptions['content'] = json_encode($params);
        }

        $httpOptions['header'] = implode("\r\n", $headers);

        $sslOptions = [
            'verify_peer' => false,
            'verify_peer_name' => false
        ];

        $contextOptions = [
            'http' => $httpOptions,
            'ssl' => $sslOptions
        ];

        $proxyHost = trim($settings['proxyHost'] ?? '');
        $proxyPort = trim($settings['proxyPort'] ?? '');
        if (!empty($proxyHost) && !empty($proxyPort)) {
            $contextOptions['http']['proxy'] = "tcp://{$proxyHost}:{$proxyPort}";
            $contextOptions['http']['request_fulluri'] = true;
        }

        $context = stream_context_create($contextOptions);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return ['ok' => false, 'error' => 'kintoneサーバーへの通信に失敗しました。接続先・プロキシ設定をご確認ください。'];
        }

        $json = json_decode($result, true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'kintoneから不正なレスポンスが返却却却却されました。'];
        }

        if (isset($json['code']) || isset($json['message'])) {
            $msg = $json['message'] ?? 'kintone API Error';
            if (isset($json['errors'])) {
                $msg .= ' (' . json_encode($json['errors'], JSON_UNESCAPED_UNICODE) . ')';
            }
            return ['ok' => false, 'error' => $msg];
        }

        return ['ok' => true, 'data' => $json];
    }
}

// --- API ルーティング & 処理 ---
if (isset($_GET['api'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=UTF-8');

    $api = $_GET['api'];
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $verifyCsrf = function() use ($input) {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $bodyToken = $input['csrf_token'] ?? '';
        $validToken = $_SESSION['survey_app_csrf_token'] ?? '';
        if (empty($validToken) || ($headerToken !== $validToken && $bodyToken !== $validToken)) {
            echo json_encode(['ok' => false, 'error' => '不正なリクエスト（CSRF検証失敗）です。']);
            exit;
        }
    };

    // 1. アンケート一覧取得
    if ($api === 'get_surveys' && $method === 'GET') {
        $surveys = DataStore::load('surveys', []);
        $responses = DataStore::load('responses', []);
        
        $resCounts = [];
        foreach ($responses as $r) {
            $sId = (int)$r['surveyId'];
            $resCounts[$sId] = ($resCounts[$sId] ?? 0) + 1;
        }

        foreach ($surveys as &$s) {
            $s['responseCount'] = $resCounts[(int)$s['id']] ?? 0;
        }
        echo json_encode(['ok' => true, 'surveys' => $surveys]);
        exit;
    }

    // 2. 単一アンケート詳細取得 (グループ・質問・選択肢含む)
    if ($api === 'get_survey' && $method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $surveys = DataStore::load('surveys', []);
        $survey = null;
        foreach ($surveys as $s) {
            if ((int)$s['id'] === $id) { $survey = $s; break; }
        }

        if (!$survey) {
            echo json_encode(['ok' => false, 'error' => 'アンケートが見つかりません。']);
            exit;
        }

        $allGroups = DataStore::load('groups', []);
        $allQuestions = DataStore::load('questions', []);
        $allOptions = DataStore::load('options', []);

        $groups = [];
        foreach ($allGroups as $g) {
            if ((int)$g['surveyId'] === $id) $groups[] = $g;
        }
        usort($groups, fn($a, $b) => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0));

        foreach ($groups as &$g) {
            $gId = (int)$g['id'];
            $questions = [];
            foreach ($allQuestions as $q) {
                if ((int)$q['groupId'] === $gId) $questions[] = $q;
            }
            usort($questions, fn($a, $b) => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0));

            foreach ($questions as &$q) {
                $qId = (int)$q['id'];
                $options = [];
                foreach ($allOptions as $o) {
                    if ((int)$o['questionId'] === $qId) $options[] = $o;
                }
                usort($options, fn($a, $b) => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0));
                $q['options'] = $options;
            }
            $g['questions'] = $questions;
        }
        $survey['groups'] = $groups;

        echo json_encode(['ok' => true, 'survey' => $survey]);
        exit;
    }

    // 3. アンケート保存 (新規・編集一括)
    if ($api === 'save_survey' && $method === 'POST') {
        $verifyCsrf();
        $sData = $input['survey'] ?? null;
        if (!$sData || empty(trim($sData['name'] ?? ''))) {
            echo json_encode(['ok' => false, 'error' => 'アンケート名を入力してください。']);
            exit;
        }

        $surveys = DataStore::load('surveys', []);
        $allGroups = DataStore::load('groups', []);
        $allQuestions = DataStore::load('questions', []);
        $allOptions = DataStore::load('options', []);

        $surveyId = (int)($sData['id'] ?? 0);
        $isNew = ($surveyId === 0);

        if ($isNew) {
            $surveyId = DataStore::nextId('surveys');
            $newSurvey = [
                'id' => $surveyId,
                'name' => trim($sData['name']),
                'description' => trim($sData['description'] ?? ''),
                'status' => $sData['status'] ?? '下書き',
                'numberingFormat' => $sData['numberingFormat'] ?? 'group',
                'startDate' => $sData['startDate'] ?? '',
                'endDate' => $sData['endDate'] ?? '',
                'createdAt' => date('Y-m-d H:i:s'),
                'updatedAt' => date('Y-m-d H:i:s')
            ];
            $surveys[] = $newSurvey;
        } else {
            foreach ($surveys as &$s) {
                if ((int)$s['id'] === $surveyId) {
                    $s['name'] = trim($sData['name']);
                    $s['description'] = trim($sData['description'] ?? '');
                    $s['numberingFormat'] = $sData['numberingFormat'] ?? 'group';
                    $s['startDate'] = $sData['startDate'] ?? '';
                    $s['endDate'] = $sData['endDate'] ?? '';
                    $s['updatedAt'] = date('Y-m-d H:i:s');
                    break;
                }
            }
            // 既存の関連グループ・質問・選択肢を差し替え削除
            $allGroups = array_values(array_filter($allGroups, fn($g) => (int)$g['surveyId'] !== $surveyId));
        }

        // 階層データの登録
        $nextGroupId = DataStore::nextId('groups');
        $nextQId = DataStore::nextId('questions');
        $nextOptId = DataStore::nextId('options');

        $groupsInput = $sData['groups'] ?? [];
        foreach ($groupsInput as $gIdx => $gIn) {
            $groupId = $nextGroupId++;
            $allGroups[] = [
                'id' => $groupId,
                'surveyId' => $surveyId,
                'name' => trim($gIn['name'] ?? 'グループ名'),
                'sortOrder' => $gIdx + 1
            ];

            foreach ($gIn['questions'] ?? [] as $qIdx => $qIn) {
                $questionId = $nextQId++;
                $allQuestions[] = [
                    'id' => $questionId,
                    'groupId' => $groupId,
                    'surveyId' => $surveyId,
                    'text' => trim($qIn['text'] ?? '質問文'),
                    'type' => $qIn['type'] ?? 'single',
                    'required' => !empty($qIn['required']),
                    'sortOrder' => $qIdx + 1
                ];

                foreach ($qIn['options'] ?? [] as $oIdx => $oIn) {
                    $optId = $nextOptId++;
                    $allOptions[] = [
                        'id' => $optId,
                        'questionId' => $questionId,
                        'text' => trim($oIn['text'] ?? ''),
                        'sortOrder' => $oIdx + 1,
                        'nextQuestionId' => $oIn['nextQuestionId'] ?? ''
                    ];
                }
            }
        }

        DataStore::save('surveys', $surveys);
        DataStore::save('groups', $allGroups);
        DataStore::save('questions', $allQuestions);
        DataStore::save('options', $allOptions);

        echo json_encode(['ok' => true, 'surveyId' => $surveyId]);
        exit;
    }

    // 4. アンケート状態変更 (公開 / 終了 / 削除)
    if ($api === 'update_survey_status' && $method === 'POST') {
        $verifyCsrf();
        $id = (int)($input['id'] ?? 0);
        $action = (string)($input['action'] ?? '');

        $surveys = DataStore::load('surveys', []);
        $found = false;

        if ($action === 'delete') {
            $surveys = array_values(array_filter($surveys, function($s) use ($id) {
                return !((int)$s['id'] === $id && $s['status'] === '下書き');
            }));
            DataStore::save('surveys', $surveys);
            echo json_encode(['ok' => true]);
            exit;
        }

        foreach ($surveys as &$s) {
            if ((int)$s['id'] === $id) {
                if ($action === 'publish') $s['status'] = '公開中';
                if ($action === 'close') $s['status'] = '終了';
                $s['updatedAt'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }

        if ($found) {
            DataStore::save('surveys', $surveys);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => '対象アンケートが存在しません。']);
        }
        exit;
    }

    // 5. 設定保存 & テスト
    if ($api === 'save_settings' && $method === 'POST') {
        $verifyCsrf();
        $settings = $input['settings'] ?? [];
        DataStore::save('settings', $settings);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($api === 'test_smtp' && $method === 'POST') {
        $verifyCsrf();
        $smtp = $input['smtp'] ?? [];
        $to = trim($smtp['fromEmail'] ?? '');
        $res = Mailer::send($smtp, $to, '【テスト送信】アンケート管理システム', "これはSMTP接続確認用のテストメールです。\n正常に設定されています。");
        echo json_encode($res);
        exit;
    }

    if ($api === 'test_kintone' && $method === 'POST') {
        $verifyCsrf();
        $kintone = $input['kintone'] ?? [];
        $appId = (int)($kintone['appId'] ?? 0);
        if ($appId <= 0) {
            echo json_encode(['ok' => false, 'error' => '顧客管理アプリIDを入力してください。']);
            exit;
        }
        $res = KintoneClient::request($kintone, 'GET', 'app.json', ['id' => $appId]);
        if ($res['ok']) {
            echo json_encode(['ok' => true, 'appName' => $res['data']['name'] ?? '']);
        } else {
            echo json_encode(['ok' => false, 'error' => $res['error']]);
        }
        exit;
    }

    // 6. 顧客同期 (kintone)
    if ($api === 'sync_customers' && $method === 'POST') {
        $verifyCsrf();
        $settings = DataStore::load('settings', []);
        $kintone = $settings['kintone'] ?? [];
        $appId = (int)($kintone['appId'] ?? 0);
        if ($appId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'キントーン設定が未完了です。設定画面でアプリID等を設定してください。']);
            exit;
        }

        $res = KintoneClient::request($kintone, 'GET', 'records.json', [
            'app' => $appId,
            'query' => 'limit 500'
        ]);

        if (!$res['ok']) {
            echo json_encode(['ok' => false, 'error' => $res['error']]);
            exit;
        }

        $nameField = trim($kintone['nameField'] ?? '顧客名');
        $emailField = trim($kintone['emailField'] ?? 'メールアドレス');

        $customers = [];
        $records = $res['data']['records'] ?? [];
        foreach ($records as $rec) {
            $cId = (int)($rec['$id']['value'] ?? 0);
            $name = $rec[$nameField]['value'] ?? ($rec['顧客名']['value'] ?? '');
            $email = $rec[$emailField]['value'] ?? ($rec['メールアドレス']['value'] ?? '');

            if ($cId > 0 && !empty($email)) {
                $customers[] = [
                    'id' => $cId,
                    'name' => $name,
                    'email' => $email
                ];
            }
        }

        DataStore::save('customers', $customers);
        echo json_encode(['ok' => true, 'count' => count($customers), 'customers' => $customers]);
        exit;
    }

    // 顧客一覧取得
    if ($api === 'get_customers' && $method === 'GET') {
        $customers = DataStore::load('customers', []);
        echo json_encode(['ok' => true, 'customers' => $customers]);
        exit;
    }

    // 7. メール送信実行 / 再送
    if ($api === 'send_survey_mail' && $method === 'POST') {
        $verifyCsrf();
        $surveyId = (int)($input['surveyId'] ?? 0);
        $customerIds = $input['customerIds'] ?? [];
        $subject = trim($input['subject'] ?? '');
        $bodyTemplate = trim($input['body'] ?? '');

        if ($surveyId <= 0 || empty($customerIds) || empty($subject) || empty($bodyTemplate)) {
            echo json_encode(['ok' => false, 'error' => '送信に必要な項目が不足しています。']);
            exit;
        }

        $settings = DataStore::load('settings', []);
        $smtp = $settings['smtp'] ?? [];
        $customers = DataStore::load('customers', []);
        $recipients = DataStore::load('recipients', []);
        $mailLogs = DataStore::load('mail_logs', []);

        $custMap = [];
        foreach ($customers as $c) $custMap[(int)$c['id']] = $c;

        $nextRecId = DataStore::nextId('recipients');
        $nextLogId = DataStore::nextId('mail_logs');

        $successCount = 0;
        $failCount = 0;
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['SCRIPT_NAME']}";

        foreach ($customerIds as $cId) {
            $cId = (int)$cId;
            if (!isset($custMap[$cId])) continue;
            $cust = $custMap[$cId];

            // 既存の送信対象レコード探索または新規作成
            $recIdx = -1;
            foreach ($recipients as $idx => $r) {
                if ((int)$r['surveyId'] === $surveyId && (int)$r['customerId'] === $cId) {
                    $recIdx = $idx;
                    break;
                }
            }

            if ($recIdx === -1) {
                $recipientId = $nextRecId++;
                $recipient = [
                    'id' => $recipientId,
                    'surveyId' => $surveyId,
                    'customerId' => $cId,
                    'status' => '送信中',
                    'sentAt' => date('Y-m-d H:i:s'),
                    'responseStatus' => '未回答'
                ];
                $recipients[] = $recipient;
            } else {
                $recipientId = (int)$recipients[$recIdx]['id'];
                $recipients[$recIdx]['sentAt'] = date('Y-m-d H:i:s');
            }

            $surveyUrl = "{$baseUrl}?view=respondent&id={$surveyId}&rid={$recipientId}";
            $mailBody = str_replace(['{name}', '{url}'], [$cust['name'], $surveyUrl], $bodyTemplate);

            $sendRes = Mailer::send($smtp, $cust['email'], $subject, $mailBody);

            $log = [
                'id' => $nextLogId++,
                'recipientId' => $recipientId,
                'surveyId' => $surveyId,
                'customerId' => $cId,
                'email' => $cust['email'],
                'subject' => $subject,
                'sentAt' => date('Y-m-d H:i:s'),
                'success' => $sendRes['ok'],
                'error' => $sendRes['error'] ?? ''
            ];
            $mailLogs[] = $log;

            foreach ($recipients as &$r) {
                if ((int)$r['id'] === $recipientId) {
                    $r['status'] = $sendRes['ok'] ? '送信成功' : '送信失敗';
                    $r['error'] = $sendRes['error'] ?? '';
                    break;
                }
            }

            if ($sendRes['ok']) $successCount++;
            else $failCount++;
        }

        DataStore::save('recipients', $recipients);
        DataStore::save('mail_logs', $mailLogs);

        echo json_encode([
            'ok' => true,
            'successCount' => $successCount,
            'failCount' => $failCount
        ]);
        exit;
    }

    // 8. 送信状況・回答状況・集計データ取得
    if ($api === 'get_survey_stats' && $method === 'GET') {
        $surveyId = (int)($_GET['id'] ?? 0);
        $recipients = DataStore::load('recipients', []);
        $customers = DataStore::load('customers', []);
        $responses = DataStore::load('responses', []);
        $answers = DataStore::load('response_answers', []);
        $questions = DataStore::load('questions', []);
        $options = DataStore::load('options', []);

        $custMap = [];
        foreach ($customers as $c) $custMap[(int)$c['id']] = $c;

        $surveyRecipients = [];
        $sentCount = 0;
        foreach ($recipients as $r) {
            if ((int)$r['surveyId'] === $surveyId) {
                $cId = (int)$r['customerId'];
                $r['customerName'] = $custMap[$cId]['name'] ?? '不明';
                $r['customerEmail'] = $custMap[$cId]['email'] ?? '';
                $surveyRecipients[] = $r;
                if ($r['status'] === '送信成功') $sentCount++;
            }
        }

        $surveyResponses = [];
        foreach ($responses as $res) {
            if ((int)$res['surveyId'] === $surveyId) {
                $resId = (int)$res['id'];
                $resAnswers = [];
                foreach ($answers as $a) {
                    if ((int)$a['responseId'] === $resId) {
                        $resAnswers[(int)$a['questionId']] = $a['value'];
                    }
                }
                $res['answers'] = $resAnswers;
                $surveyResponses[] = $res;
            }
        }

        $totalResponses = count($surveyResponses);
        $responseRate = ($sentCount > 0) ? round(($totalResponses / $sentCount) * 100, 1) : 0;

        echo json_encode([
            'ok' => true,
            'recipients' => $surveyRecipients,
            'sentCount' => $sentCount,
            'totalResponses' => $totalResponses,
            'responseRate' => $responseRate,
            'responses' => $surveyResponses
        ]);
        exit;
    }

    // 9. 回答者からの送信受付
    if ($api === 'submit_response' && $method === 'POST') {
        $surveyId = (int)($input['surveyId'] ?? 0);
        $recipientId = (int)($input['recipientId'] ?? 0);
        $answersData = $input['answers'] ?? [];

        $surveys = DataStore::load('surveys', []);
        $targetSurvey = null;
        foreach ($surveys as $s) {
            if ((int)$s['id'] === $surveyId) { $targetSurvey = $s; break; }
        }

        if (!$targetSurvey || $targetSurvey['status'] !== '公開中') {
            echo json_encode(['ok' => false, 'error' => '現在このアンケートは回答を受け付けておりません。']);
            exit;
        }

        $responses = DataStore::load('responses', []);
        $allAnswers = DataStore::load('response_answers', []);
        $recipients = DataStore::load('recipients', []);

        $resId = DataStore::nextId('responses');
        $newResponse = [
            'id' => $resId,
            'surveyId' => $surveyId,
            'recipientId' => $recipientId,
            'submittedAt' => date('Y-m-d H:i:s')
        ];
        $responses[] = $newResponse;

        $nextAnsId = DataStore::nextId('response_answers');
        foreach ($answersData as $qId => $val) {
            $allAnswers[] = [
                'id' => $nextAnsId++,
                'responseId' => $resId,
                'questionId' => (int)$qId,
                'value' => $val
            ];
        }

        if ($recipientId > 0) {
            foreach ($recipients as &$r) {
                if ((int)$r['id'] === $recipientId) {
                    $r['responseStatus'] = '回答済';
                    break;
                }
            }
            DataStore::save('recipients', $recipients);
        }

        DataStore::save('responses', $responses);
        DataStore::save('response_answers', $allAnswers);

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Invalid API']);
    exit;
}

// 初期設定のロード
$settings = DataStore::load('settings', [
    'kintone' => ['host' => '', 'appId' => '', 'login' => '', 'pass' => '', 'nameField' => '顧客名', 'emailField' => 'メールアドレス', 'proxyHost' => '', 'proxyPort' => ''],
    'smtp' => ['host' => '', 'port' => 587, 'secure' => 'TLS', 'user' => '', 'pass' => '', 'fromEmail' => '', 'fromName' => '']
]);

$viewMode = $_GET['view'] ?? 'admin';
$respondentSurveyId = (int)($_GET['id'] ?? 0);
$respondentRecId = (int)($_GET['rid'] ?? 0);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>アンケート業務運営システム</title>
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
    header { background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; }
    .header-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 0 1rem; }
    .logo { font-weight: bold; font-size: 1.1rem; color: var(--primary); padding: 1rem 0; cursor: pointer; }
    nav ul { display: flex; list-style: none; gap: 0.5rem; }
    nav button { background: none; border: none; padding: 1rem 0.75rem; font-size: 0.95rem; cursor: pointer; color: var(--text-muted); font-weight: 500; border-bottom: 2px solid transparent; }
    nav button.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }
    nav button:hover:not(.active) { color: var(--text); }
    .sub-nav { background: #f1f5f9; border-bottom: 1px solid var(--border); padding: 0.5rem 1rem; }
    .sub-nav-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .sub-nav-title { font-weight: 600; font-size: 0.9rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem; }
    .sub-nav-title span { color: var(--text); font-size: 1rem; }
    .sub-nav ul { display: flex; list-style: none; gap: 0.5rem; }
    .sub-nav button { background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 0.35rem 0.75rem; font-size: 0.85rem; cursor: pointer; font-weight: 500; }
    .sub-nav button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
    main { max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem; flex: 1; width: 100%; }
    .card { background: var(--surface); border-radius: 8px; border: 1px solid var(--border); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; }
    .card-title { font-size: 1.15rem; font-weight: 600; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.9rem; font-weight: 500; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; }
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
    th, td { padding: 0.75rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    th { background: #f8fafc; font-weight: 600; color: var(--text-muted); }
    .group-card { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem; }
    .question-card { background: #ffffff; border: 1px solid var(--border); border-radius: 6px; padding: 1rem; margin-bottom: 0.75rem; }
    .choice-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .stat-card { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; text-align: center; }
    .stat-val { font-size: 1.75rem; font-weight: bold; color: var(--primary); margin-top: 0.25rem; }
    .stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 500; }
    .chart-bar-bg { background: #f1f5f9; border-radius: 4px; height: 1.25rem; width: 100%; overflow: hidden; margin-top: 0.25rem; }
    .chart-bar-fill { background: var(--primary); height: 100%; border-radius: 4px; transition: width 0.3s; }
    .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #334155; color: #fff; padding: 0.75rem 1.25rem; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000; display: none; }
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 500; }
    .modal { background: #fff; width: 90%; max-width: 600px; border-radius: 8px; padding: 1.5rem; max-height: 90vh; overflow-y: auto; }
    .respondent-view { max-width: 720px; margin: 2rem auto; background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    .loading-spinner { display: inline-block; width: 1rem; height: 1rem; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 1s ease-in-out infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>

<?php if ($viewMode === 'respondent'): ?>
  <!-- 回答者専用画面 -->
  <main>
    <div class="respondent-view" id="respondent-container">
      <div id="respondent-loading" style="text-align: center; padding: 2rem;">読み込み中...</div>
      <div id="respondent-form-wrap" style="display: none;">
        <h2 id="resp-title" style="margin-bottom: 0.5rem;"></h2>
        <p id="resp-desc" style="color: var(--text-muted); margin-bottom: 1.5rem; white-space: pre-wrap;"></p>
        <form id="respondent-form" onsubmit="submitRespondentAnswers(event)">
          <div id="resp-questions-list"></div>
          <div style="margin-top: 2rem; text-align: center;">
            <button type="submit" id="resp-submit-btn" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1rem;">回答を送信する</button>
          </div>
        </form>
      </div>
      <div id="respondent-complete" style="display: none; text-align: center; padding: 2rem;">
        <h3 style="color: var(--success); margin-bottom: 1rem;">回答を受け付けました</h3>
        <p>アンケートへのご協力、誠にありがとうございました。</p>
      </div>
    </div>
  </main>
<?php else: ?>
  <!-- 運営者用メイン画面 -->
  <header id="app-header">
    <div class="header-container">
      <div class="logo" onclick="SurveyApp.navigate('survey-list')">アンケート業務運営システム</div>
      <nav>
        <ul>
          <li><button id="nav-surveys" class="active" onclick="SurveyApp.navigate('survey-list')">アンケート一覧</button></li>
          <li><button id="nav-new" onclick="SurveyApp.startNewSurvey()">新規アンケート作成</button></li>
          <li><button id="nav-customers" onclick="SurveyApp.navigate('customer-list')">顧客一覧</button></li>
          <li><button id="nav-settings" onclick="SurveyApp.navigate('settings')">設定</button></li>
        </ul>
      </nav>
    </div>
    <div id="sub-nav-bar" class="sub-nav" style="display: none;">
      <div class="sub-nav-container">
        <div class="sub-nav-title">選択中: <span id="current-survey-name"></span></div>
        <ul>
          <li><button id="sub-detail" class="active" onclick="SurveyApp.navigateSub('detail')">アンケート内容</button></li>
          <li><button id="sub-send" onclick="SurveyApp.navigateSub('send')">送信</button></li>
          <li><button id="sub-status" onclick="SurveyApp.navigateSub('status')">回答状況</button></li>
          <li><button id="sub-result" onclick="SurveyApp.navigateSub('result')">回答結果</button></li>
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
        <button class="btn btn-outline" onclick="SurveyApp.closeModal()">キャンセル</button>
        <button id="modal-confirm-btn" class="btn btn-primary">実行</button>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
const CSRF_TOKEN = '<?= h($csrfToken) ?>';
const RESPONDENT_SURVEY_ID = <?= (int)$respondentSurveyId ?>;
const RESPONDENT_REC_ID = <?= (int)$respondentRecId ?>;

// --- 回答者画面用ロジック ---
<?php if ($viewMode === 'respondent'): ?>
document.addEventListener('DOMContentLoaded', async () => {
  if (!RESPONDENT_SURVEY_ID) {
    document.getElementById('respondent-loading').textContent = 'アンケートが指定されていません。';
    return;
  }

  try {
    const res = await fetch(`index.php?api=get_survey&id=${RESPONDENT_SURVEY_ID}`);
    const data = await res.json();
    if (!data.ok || !data.survey) {
      document.getElementById('respondent-loading').textContent = data.error || 'アンケートが見つかりませんでした。';
      return;
    }

    const survey = data.survey;
    if (survey.status !== '公開中') {
      document.getElementById('respondent-loading').textContent = 'このアンケートは現在受け付けておりません（状態: ' + survey.status + '）';
      return;
    }

    document.getElementById('resp-title').textContent = survey.name;
    document.getElementById('resp-desc').textContent = survey.description || '';

    const qList = document.getElementById('resp-questions-list');
    qList.innerHTML = '';

    let globalQIdx = 1;
    survey.groups.forEach((g, gIdx) => {
      g.questions.forEach((q, qIdx) => {
        const qNum = survey.numberingFormat === 'group' ? `Q${gIdx + 1}-${qIdx + 1}` : `Q${globalQIdx++}`;
        const wrap = document.createElement('div');
        wrap.className = 'card question-block';
        wrap.id = `qblock-${q.id}`;
        wrap.dataset.qid = q.id;

        let inputHtml = '';
        if (q.type === 'single') {
          (q.options || []).forEach(opt => {
            inputHtml += `
              <div style="margin-bottom: 0.4rem;">
                <label style="font-weight: normal; cursor: pointer; display: flex; align-items: center; gap: 0.4rem;">
                  <input type="radio" name="ans_${q.id}" value="${escapeHtml(opt.text)}" ${q.required ? 'required' : ''} onchange="handleRespondentBranch(${q.id}, '${escapeHtml(opt.nextQuestionId || '')}')">
                  ${escapeHtml(opt.text)}
                </label>
              </div>`;
          });
        } else if (q.type === 'multiple') {
          (q.options || []).forEach(opt => {
            inputHtml += `
              <div style="margin-bottom: 0.4rem;">
                <label style="font-weight: normal; cursor: pointer; display: flex; align-items: center; gap: 0.4rem;">
                  <input type="checkbox" name="ans_${q.id}[]" value="${escapeHtml(opt.text)}">
                  ${escapeHtml(opt.text)}
                </label>
              </div>`;
          });
        } else {
          inputHtml = `<textarea class="form-control" name="ans_${q.id}" rows="3" ${q.required ? 'required' : ''}></textarea>`;
        }

        wrap.innerHTML = `
          <div style="font-weight: 600; margin-bottom: 0.5rem;">
            <span style="color: var(--primary);">${qNum}.</span> ${escapeHtml(q.text)}
            ${q.required ? '<span style="color: var(--danger); font-size: 0.8rem; margin-left: 0.3rem;">*必須</span>' : ''}
          </div>
          <div>${inputHtml}</div>
        `;
        qList.appendChild(wrap);
      });
    });

    document.getElementById('respondent-loading').style.display = 'none';
    document.getElementById('respondent-form-wrap').style.display = 'block';

  } catch (e) {
    document.getElementById('respondent-loading').textContent = 'データの読み込みに失敗しました。';
  }
});

function handleRespondentBranch(currentQId, nextQId) {
  // 分岐による後続スキップ等の動的制御
}

async function submitRespondentAnswers(e) {
  e.preventDefault();
  const btn = document.getElementById('resp-submit-btn');
  btn.disabled = true;
  btn.textContent = '送信中...';

  const form = document.getElementById('respondent-form');
  const formData = new FormData(form);
  const answers = {};

  document.querySelectorAll('.question-block').forEach(qb => {
    const qid = qb.dataset.qid;
    const radios = form.querySelectorAll(`input[name="ans_${qid}"]:checked`);
    const checkboxes = form.querySelectorAll(`input[name="ans_${qid}[]"]:checked`);
    const textarea = form.querySelector(`textarea[name="ans_${qid}"]`);

    if (radios.length > 0) {
      answers[qid] = radios[0].value;
    } else if (checkboxes.length > 0) {
      answers[qid] = Array.from(checkboxes).map(c => c.value);
    } else if (textarea) {
      answers[qid] = textarea.value.trim();
    }
  });

  try {
    const res = await fetch('index.php?api=submit_response', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        surveyId: RESPONDENT_SURVEY_ID,
        recipientId: RESPONDENT_REC_ID,
        answers: answers
      })
    });
    const result = await res.json();
    if (result.ok) {
      document.getElementById('respondent-form-wrap').style.display = 'none';
      document.getElementById('respondent-complete').style.display = 'block';
    } else {
      alert(result.error || '送信に失敗しました。');
      btn.disabled = false;
      btn.textContent = '回答を送信する';
    }
  } catch (err) {
    alert('通信エラーが発生しました。');
    btn.disabled = false;
    btn.textContent = '回答を送信する';
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/[&<>"']/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' })[m]);
}
<?php else: ?>

// --- 運営者用メインアプリケーション ---
const SurveyApp = {
  state: {
    currentView: 'survey-list',
    currentSubView: 'detail',
    selectedSurveyId: null,
    surveys: [],
    selectedSurvey: null,
    customers: [],
    selectedCustomerIds: [],
    settings: <?= json_encode($settings, JSON_UNESCAPED_UNICODE) ?>
  },

  init() {
    this.navigate('survey-list');
  },

  async apiCall(api, method = 'GET', data = null) {
    const options = {
      method: method,
      headers: {
        'X-CSRF-TOKEN': CSRF_TOKEN,
        'Content-Type': 'application/json'
      }
    };
    if (data && method !== 'GET') {
      data.csrf_token = CSRF_TOKEN;
      options.body = JSON.stringify(data);
    }
    const res = await fetch(`index.php?api=${api}`, options);
    return await res.json();
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
    btn.onclick = async () => {
      await onConfirm();
      SurveyApp.closeModal();
    };
    document.getElementById('modal').style.display = 'flex';
  },

  closeModal() {
    document.getElementById('modal').style.display = 'none';
  },

  async navigate(view) {
    this.state.currentView = view;
    document.getElementById('sub-nav-bar').style.display = 'none';
    ['surveys', 'new', 'customers', 'settings'].forEach(k => {
      const el = document.getElementById(`nav-${k}`);
      if (el) el.classList.remove('active');
    });

    if (view === 'survey-list') {
      document.getElementById('nav-surveys')?.classList.add('active');
      await this.renderSurveyList();
    } else if (view === 'customer-list') {
      document.getElementById('nav-customers')?.classList.add('active');
      await this.renderCustomerList();
    } else if (view === 'settings') {
      document.getElementById('nav-settings')?.classList.add('active');
      this.renderSettings();
    }
  },

  async selectSurvey(id, subView = 'detail') {
    this.state.selectedSurveyId = id;
    this.state.currentView = 'survey-detail';
    this.state.currentSubView = subView;
    document.getElementById('sub-nav-bar').style.display = 'block';

    const res = await this.apiCall(`get_survey&id=${id}`);
    if (!res.ok) {
      this.showToast(res.error || 'アンケートの取得に失敗しました');
      this.navigate('survey-list');
      return;
    }
    this.state.selectedSurvey = res.survey;
    document.getElementById('current-survey-name').textContent = this.state.selectedSurvey.name;

    this.navigateSub(subView);
  },

  navigateSub(subView) {
    this.state.currentSubView = subView;
    ['detail', 'send', 'status', 'result'].forEach(k => {
      const el = document.getElementById(`sub-${k}`);
      if (el) el.classList.toggle('active', k === subView);
    });

    if (subView === 'detail') this.renderSurveyDetail();
    if (subView === 'send') this.renderSurveySend();
    if (subView === 'status') this.renderSurveyStatus();
    if (subView === 'result') this.renderSurveyResult();
  },

  // 1. アンケート一覧画面
  async renderSurveyList() {
    const main = document.getElementById('main-content');
    main.innerHTML = '<div class="card">アンケート一覧を読み込み中...</div>';

    const res = await this.apiCall('get_surveys');
    this.state.surveys = res.ok ? res.surveys : [];

    let rowsHtml = '';
    this.state.surveys.forEach(s => {
      const badgeCls = s.status === '公開中' ? 'badge-active' : (s.status === '終了' ? 'badge-closed' : 'badge-draft');
      rowsHtml += `
        <tr>
          <td><strong style="cursor:pointer; color:var(--primary);" onclick="SurveyApp.selectSurvey(${s.id})">${this.escape(s.name)}</strong></td>
          <td><span class="badge ${badgeCls}">${this.escape(s.status)}</span></td>
          <td>${this.escape(s.startDate || '-')} 〜 ${this.escape(s.endDate || '-')}</td>
          <td>${s.responseCount || 0} 件</td>
          <td>${this.escape(s.updatedAt || s.createdAt || '-')}</td>
          <td>
            <button class="btn btn-outline btn-sm" onclick="SurveyApp.selectSurvey(${s.id})">管理</button>
            <button class="btn btn-outline btn-sm" onclick="SurveyApp.editSurvey(${s.id})">編集</button>
            ${s.status === '下書き' ? `<button class="btn btn-danger-outline btn-sm" onclick="SurveyApp.deleteSurvey(${s.id})">削除</button>` : ''}
          </td>
        </tr>`;
    });

    main.innerHTML = `
      <div class="card">
        <div class="card-header">
          <div class="card-title">アンケート一覧</div>
          <button class="btn btn-primary btn-sm" onclick="SurveyApp.startNewSurvey()">+ 新規アンケート作成</button>
        </div>
        <table>
          <thead>
            <tr>
              <th>アンケート名</th>
              <th>状態</th>
              <th>公開期間</th>
              <th>回答数</th>
              <th>最終更新</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>${rowsHtml || '<tr><td colspan="6" style="text-align:center;">アンケートがありません。</td></tr>'}</tbody>
        </table>
      </div>`;
  },

  // 2. 新規作成 / 編集画面
  async startNewSurvey() {
    this.state.selectedSurveyId = null;
    this.state.selectedSurvey = {
      id: 0,
      name: '',
      description: '',
      status: '下書き',
      numberingFormat: 'group',
      startDate: '',
      endDate: '',
      groups: [{ id: 'new_g1', name: '基本質問', questions: [] }]
    };
    this.renderSurveyEditor();
  },

  async editSurvey(id) {
    const res = await this.apiCall(`get_survey&id=${id}`);
    if (!res.ok) {
      this.showToast('アンケート情報の読み込みに失敗しました。');
      return;
    }
    this.state.selectedSurvey = res.survey;
    this.renderSurveyEditor();
  },

  renderSurveyEditor() {
    document.getElementById('sub-nav-bar').style.display = 'none';
    const s = this.state.selectedSurvey;
    const main = document.getElementById('main-content');

    let groupsHtml = '';
    (s.groups || []).forEach((g, gIdx) => {
      let qHtml = '';
      (g.questions || []).forEach((q, qIdx) => {
        let optHtml = '';
        if (q.type === 'single' || q.type === 'multiple') {
          (q.options || []).forEach((opt, oIdx) => {
            optHtml += `
              <div class="choice-row">
                <input type="text" class="form-control" style="max-width: 280px;" value="${this.escape(opt.text)}" placeholder="選択肢名" onchange="SurveyApp.updateOptionText(${gIdx}, ${qIdx}, ${oIdx}, this.value)">
                ${q.type === 'single' ? `
                  <span style="font-size:0.8rem; color:var(--text-muted);">分岐:</span>
                  <select class="form-control" style="max-width:180px;" onchange="SurveyApp.updateOptionBranch(${gIdx}, ${qIdx}, ${oIdx}, this.value)">
                    <option value="">通常（次の質問へ）</option>
                    <option value="__END__" ${opt.nextQuestionId === '__END__' ? 'selected' : ''}>アンケート終了</option>
                  </select>
                ` : ''}
                <button class="btn btn-outline btn-sm" onclick="SurveyApp.removeOption(${gIdx}, ${qIdx}, ${oIdx})">✕</button>
              </div>`;
          });
          optHtml += `<button class="btn btn-outline btn-sm" style="margin-top:0.3rem;" onclick="SurveyApp.addOption(${gIdx}, ${qIdx})">+ 選択肢追加</button>`;
        }

        qHtml += `
          <div class="question-card">
            <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem;">
              <span style="font-weight:600; color:var(--primary);">質問 ${qIdx + 1}</span>
              <button class="btn btn-danger-outline btn-sm" onclick="SurveyApp.removeQuestion(${gIdx}, ${qIdx})">質問削除</button>
            </div>
            <div class="form-group">
              <label>質問文</label>
              <input type="text" class="form-control" value="${this.escape(q.text)}" onchange="SurveyApp.updateQuestionText(${gIdx}, ${qIdx}, this.value)">
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>回答形式</label>
                <select class="form-control" onchange="SurveyApp.updateQuestionType(${gIdx}, ${qIdx}, this.value)">
                  <option value="single" ${q.type === 'single' ? 'selected' : ''}>単一選択（ラジオボタン）</option>
                  <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選択（チェックボックス）</option>
                  <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述（テキスト入力）</option>
                </select>
              </div>
              <div class="form-group" style="display:flex; align-items:center; margin-top:1.2rem;">
                <label style="cursor:pointer; display:flex; align-items:center; gap:0.4rem;">
                  <input type="checkbox" ${q.required ? 'checked' : ''} onchange="SurveyApp.updateQuestionRequired(${gIdx}, ${qIdx}, this.checked)">
                  回答を必須にする
                </label>
              </div>
            </div>
            ${optHtml ? `<div style="margin-top:0.5rem;"><label style="font-size:0.85rem; font-weight:600;">選択肢設定</label>${optHtml}</div>` : ''}
          </div>`;
      });

      groupsHtml += `
        <div class="group-card">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
            <input type="text" class="form-control" style="font-weight:bold; max-width:300px;" value="${this.escape(g.name)}" onchange="SurveyApp.updateGroupName(${gIdx}, this.value)">
            <button class="btn btn-danger-outline btn-sm" onclick="SurveyApp.removeGroup(${gIdx})">グループ削除</button>
          </div>
          <div>${qHtml}</div>
          <button class="btn btn-outline btn-sm" style="margin-top:0.5rem;" onclick="SurveyApp.addQuestion(${gIdx})">+ 質問を追加</button>
        </div>`;
    });

    main.innerHTML = `
      <div class="card">
        <div class="card-header">
          <div class="card-title">${s.id ? 'アンケート編集' : '新規アンケート作成'}</div>
          <div>
            <button class="btn btn-outline" onclick="SurveyApp.navigate('survey-list')">キャンセル</button>
            <button class="btn btn-primary" id="save-survey-btn" onclick="SurveyApp.saveSurveyData()">保存する</button>
          </div>
        </div>
        <div class="form-group">
          <label>アンケート名 <span style="color:var(--danger)">*</span></label>
          <input type="text" id="edit-survey-name" class="form-control" value="${this.escape(s.name)}" placeholder="例: お客様満足度調査">
        </div>
        <div class="form-group">
          <label>説明文 / 案内文</label>
          <textarea id="edit-survey-desc" class="form-control" rows="3" placeholder="アンケートの概要や案内文を入力">${this.escape(s.description)}</textarea>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>公開開始日時</label>
            <input type="date" id="edit-survey-sdate" class="form-control" value="${this.escape(s.startDate)}">
          </div>
          <div class="form-group">
            <label>公開終了日時</label>
            <input type="date" id="edit-survey-edate" class="form-control" value="${this.escape(s.endDate)}">
          </div>
          <div class="form-group">
            <label>質問番号形式</label>
            <select id="edit-survey-numfmt" class="form-control">
              <option value="group" ${s.numberingFormat === 'group' ? 'selected' : ''}>グループ別 (Q1-1, Q2-1...)</option>
              <option value="global" ${s.numberingFormat === 'global' ? 'selected' : ''}>全体通番 (Q1, Q2, Q3...)</option>
            </select>
          </div>
        </div>
        <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border);">
        <div style="margin-bottom: 1rem; font-weight:600;">質問グループ・質問設定</div>
        <div>${groupsHtml}</div>
        <button class="btn btn-outline" onclick="SurveyApp.addGroup()">+ グループを追加</button>
      </div>`;
  },

  // エディタ内のインライン更新
  updateGroupName(gIdx, val) { this.state.selectedSurvey.groups[gIdx].name = val; },
  removeGroup(gIdx) {
    this.openModal('グループ削除確認', 'このグループと含まれる質問をすべて削除しますか？', () => {
      this.state.selectedSurvey.groups.splice(gIdx, 1);
      this.renderSurveyEditor();
    });
  },
  addGroup() {
    this.state.selectedSurvey.groups.push({ id: 'new_g_' + Date.now(), name: '新しいグループ', questions: [] });
    this.renderSurveyEditor();
  },
  addQuestion(gIdx) {
    this.state.selectedSurvey.groups[gIdx].questions.push({
      id: 'new_q_' + Date.now(),
      text: '新しい質問',
      type: 'single',
      required: true,
      options: [{ text: 'はい', nextQuestionId: '' }, { text: 'いいえ', nextQuestionId: '' }]
    });
    this.renderSurveyEditor();
  },
  removeQuestion(gIdx, qIdx) {
    this.state.selectedSurvey.groups[gIdx].questions.splice(qIdx, 1);
    this.renderSurveyEditor();
  },
  updateQuestionText(gIdx, qIdx, val) { this.state.selectedSurvey.groups[gIdx].questions[qIdx].text = val; },
  updateQuestionType(gIdx, qIdx, type) {
    const q = this.state.selectedSurvey.groups[gIdx].questions[qIdx];
    q.type = type;
    if (type !== 'text' && (!q.options || q.options.length === 0)) {
      q.options = [{ text: '選択肢1', nextQuestionId: '' }, { text: '選択肢2', nextQuestionId: '' }];
    }
    this.renderSurveyEditor();
  },
  updateQuestionRequired(gIdx, qIdx, checked) { this.state.selectedSurvey.groups[gIdx].questions[qIdx].required = checked; },
  addOption(gIdx, qIdx) {
    this.state.selectedSurvey.groups[gIdx].questions[qIdx].options.push({ text: '新しい選択肢', nextQuestionId: '' });
    this.renderSurveyEditor();
  },
  removeOption(gIdx, qIdx, oIdx) {
    this.state.selectedSurvey.groups[gIdx].questions[qIdx].options.splice(oIdx, 1);
    this.renderSurveyEditor();
  },
  updateOptionText(gIdx, qIdx, oIdx, val) { this.state.selectedSurvey.groups[gIdx].questions[qIdx].options[oIdx].text = val; },
  updateOptionBranch(gIdx, qIdx, oIdx, val) { this.state.selectedSurvey.groups[gIdx].questions[qIdx].options[oIdx].nextQuestionId = val; },

  async saveSurveyData() {
    const s = this.state.selectedSurvey;
    s.name = document.getElementById('edit-survey-name').value.trim();
    s.description = document.getElementById('edit-survey-desc').value.trim();
    s.startDate = document.getElementById('edit-survey-sdate').value;
    s.endDate = document.getElementById('edit-survey-edate').value;
    s.numberingFormat = document.getElementById('edit-survey-numfmt').value;

    if (!s.name) {
      alert('アンケート名を入力してください。');
      return;
    }

    const btn = document.getElementById('save-survey-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="loading-spinner"></span> 保存中...';

    const res = await this.apiCall('save_survey', 'POST', { survey: s });
    btn.disabled = false;
    btn.textContent = '保存する';

    if (res.ok) {
      this.showToast('アンケートを保存しました。');
      this.selectSurvey(res.surveyId, 'detail');
    } else {
      alert(res.error || '保存に失敗しました。');
    }
  },

  // 3. アンケート内容詳細確認
  renderSurveyDetail() {
    const s = this.state.selectedSurvey;
    const main = document.getElementById('main-content');
    const badgeCls = s.status === '公開中' ? 'badge-active' : (s.status === '終了' ? 'badge-closed' : 'badge-draft');

    let previewHtml = '';
    let globalIdx = 1;
    (s.groups || []).forEach((g, gIdx) => {
      previewHtml += `<div style="font-weight:bold; margin: 1rem 0 0.5rem; color:var(--text-muted);">${this.escape(g.name)}</div>`;
      (g.questions || []).forEach((q, qIdx) => {
        const qNum = s.numberingFormat === 'group' ? `Q${gIdx + 1}-${qIdx + 1}` : `Q${globalIdx++}`;
        let optStr = '';
        if (q.options && q.options.length > 0) {
          optStr = '<ul style="margin-left:1.5rem; margin-top:0.3rem;">' + q.options.map(o => `<li>${this.escape(o.text)} ${o.nextQuestionId === '__END__' ? '<span style="color:var(--danger); font-size:0.8rem;">[終了]</span>' : ''}</li>`).join('') + '</ul>';
        }
        previewHtml += `
          <div style="background:#f8fafc; border:1px solid var(--border); border-radius:6px; padding:0.75rem; margin-bottom:0.5rem;">
            <div><strong>${qNum}.</strong> ${this.escape(q.text)} ${q.required ? '<span style="color:var(--danger); font-size:0.8rem;">*必須</span>' : ''} <span style="color:var(--text-muted); font-size:0.8rem;">(${q.type === 'single' ? '単一選択' : (q.type === 'multiple' ? '複数選択' : '自由記述')})</span></div>
            ${optStr}
          </div>`;
      });
    });

    main.innerHTML = `
      <div class="card">
        <div class="card-header">
          <div>
            <span class="badge ${badgeCls}" style="margin-right:0.5rem;">${this.escape(s.status)}</span>
            <span class="card-title">${this.escape(s.name)}</span>
          </div>
          <div style="display:flex; gap:0.5rem;">
            <button class="btn btn-outline btn-sm" onclick="SurveyApp.editSurvey(${s.id})">編集する</button>
            ${s.status === '下書き' ? `<button class="btn btn-primary btn-sm" onclick="SurveyApp.updateStatus(${s.id}, 'publish')">公開する</button>` : ''}
            ${s.status === '公開中' ? `<button class="btn btn-danger btn-sm" onclick="SurveyApp.updateStatus(${s.id}, 'close')">受付終了にする</button>` : ''}
          </div>
        </div>
        <p style="color:var(--text-muted); margin-bottom:1rem; white-space:pre-wrap;">${this.escape(s.description || '（説明文なし）')}</p>
        <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1.5rem;">公開期間: ${this.escape(s.startDate || '-')} 〜 ${this.escape(s.endDate || '-')}</div>
        <hr style="margin-bottom:1.5rem; border:none; border-top:1px solid var(--border);">
        <div style="font-weight:600; margin-bottom:0.5rem;">アンケート構成プレビュー</div>
        ${previewHtml || '<p style="color:var(--text-muted);">質問が登録されていません。</p>'}
      </div>`;
  },

  async updateStatus(id, action) {
    const actionLabel = action === 'publish' ? '公開' : '受付終了';
    this.openModal(`${actionLabel}の確認`, `アンケートを${actionLabel}状態に変更しますか？`, async () => {
      const res = await this.apiCall('update_survey_status', 'POST', { id, action });
      if (res.ok) {
        this.showToast(`アンケートを${actionLabel}にしました。`);
        this.selectSurvey(id, 'detail');
      } else {
        alert(res.error || '状態の更新に失敗しました。');
      }
    });
  },

  async deleteSurvey(id) {
    this.openModal('削除確認', 'この下書きアンケートを完全に削除しますか？', async () => {
      const res = await this.apiCall('update_survey_status', 'POST', { id, action: 'delete' });
      if (res.ok) {
        this.showToast('アンケートを削除しました。');
        this.navigate('survey-list');
      } else {
        alert(res.error || '削除に失敗しました。');
      }
    });
  },

  // 4. アンケート送信画面
  async renderSurveySend() {
    const s = this.state.selectedSurvey;
    const main = document.getElementById('main-content');
    main.innerHTML = '<div class="card">送信設定・顧客データを読み込み中...</div>';

    const [cRes, statsRes] = await Promise.all([
      this.apiCall('get_customers'),
      this.apiCall(`get_survey_stats&id=${s.id}`)
    ]);

    this.state.customers = cRes.ok ? cRes.customers : [];
    const recipients = statsRes.ok ? statsRes.recipients : [];

    let custRows = '';
    this.state.customers.forEach(c => {
      const isChecked = this.state.selectedCustomerIds.includes(c.id);
      custRows += `
        <tr>
          <td style="width:40px;"><input type="checkbox" value="${c.id}" ${isChecked ? 'checked' : ''} onchange="SurveyApp.toggleCustomerSelect(${c.id}, this.checked)"></td>
          <td>${this.escape(c.name)}</td>
          <td>${this.escape(c.email)}</td>
        </tr>`;
    });

    let historyRows = '';
    recipients.forEach(r => {
      historyRows += `
        <tr>
          <td>${this.escape(r.customerName)}</td>
          <td>${this.escape(r.customerEmail)}</td>
          <td>${r.status === '送信成功' ? '<span style="color:var(--success);">送信成功</span>' : '<span style="color:var(--danger);">失敗</span>'}</td>
          <td>${this.escape(r.responseStatus)}</td>
          <td>${this.escape(r.sentAt)}</td>
        </tr>`;
    });

    main.innerHTML = `
      <div class="card">
        <div class="card-title" style="margin-bottom:1rem;">アンケート案内メール送信</div>
        ${s.status !== '公開中' ? '<div style="padding:0.75rem; background:#fee2e2; color:#b91c1c; border-radius
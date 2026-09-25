<?php
declare(strict_types=1);

namespace App\SurveySystem;

// セッションおよびセキュリティ設定
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// レスポンスヘッダー
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

// --------------------------------------------------
// ヘルパー関数 & CSRF
// --------------------------------------------------
function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getCsrfToken(): string {
    if (empty($_SESSION['_app_survey_csrf_token'])) {
        $_SESSION['_app_survey_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_app_survey_csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (empty($token) || empty($_SESSION['_app_survey_csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['_app_survey_csrf_token'], $token);
}

function jsonResponse(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --------------------------------------------------
// JSON ファイル管理 (排他ロック付きデータアクセス)
// --------------------------------------------------
class JsonStorage {
    private static function getFilePath(string $filename): string {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $filename;
    }

    public static function load(string $filename, array $default = []): array {
        $path = self::getFilePath($filename);
        if (!file_exists($path)) {
            return $default;
        }
        $fp = fopen($path, 'r');
        if (!$fp) return $default;
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if ($content === false || trim($content) === '') {
            return $default;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : $default;
    }

    public static function save(string $filename, array $data): bool {
        $path = self::getFilePath($filename);
        $fp = fopen($path, 'c+');
        if (!$fp) return false;
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }
        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $result !== false;
    }
}

// --------------------------------------------------
// kintone 連携サービス (パスワード認証 / プロキシ対応 / SSL検証無効固定)
// --------------------------------------------------
class KintoneService {
    public static function buildUrl(string $host, string $path): string {
        $cleanHost = preg_replace('#^https?://#', '', trim($host));
        $cleanHost = rtrim($cleanHost, '/');
        if (!str_contains($cleanHost, '.')) {
            $cleanHost .= '.cybozu.com';
        }
        return 'https://' . $cleanHost . $path;
    }

    public static function request(string $method, string $url, array $params, array $settings): array {
        $auth = base64_encode(($settings['login'] ?? '') . ':' . ($settings['pass'] ?? ''));
        $headers = [
            'X-Cybozu-Authorization: ' . $auth,
            'User-Agent: SurveyApp-KintoneClient/1.0'
        ];

        $httpOptions = [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 15,
        ];

        if (!empty($settings['proxyHost']) && !empty($settings['proxyPort'])) {
            $httpOptions['proxy'] = 'tcp://' . $settings['proxyHost'] . ':' . $settings['proxyPort'];
            $httpOptions['request_fulluri'] = true;
        }

        if ($method === 'GET') {
            if (!empty($params)) {
                $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            }
        } else {
            $headers[] = 'Content-Type: application/json';
            $httpOptions['content'] = json_encode($params);
        }

        $httpOptions['header'] = implode("\r\n", $headers);

        $context = stream_context_create([
            'http' => $httpOptions,
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $res = @file_get_contents($url, false, $context);
        $resHeaders = function_exists('http_get_last_response_headers') 
            ? http_get_last_response_headers() 
            : ($http_response_header ?? []);

        if ($res === false) {
            return ['ok' => false, 'error' => 'kintoneサーバーへの通信に失敗しました。接続先やプロキシ設定を確認してください。'];
        }

        $statusCode = 200;
        if (!empty($resHeaders) && preg_match('#HTTP/\S+\s+(\d+)#', $resHeaders[0], $m)) {
            $statusCode = (int)$m[1];
        }

        $json = json_decode($res, true);
        if ($statusCode >= 400) {
            $msg = $json['message'] ?? 'kintone APIエラー (' . $statusCode . ')';
            if (!empty($json['errors'])) {
                $msg .= ' : ' . json_encode($json['errors'], JSON_UNESCAPED_UNICODE);
            }
            return ['ok' => false, 'error' => $msg, 'code' => $json['code'] ?? ''];
        }

        return ['ok' => true, 'data' => $json];
    }
}

// --------------------------------------------------
// SMTP メール送信サービス (ソケット通信)
// --------------------------------------------------
class SmtpService {
    public static function send(array $smtp, string $to, string $subject, string $body): array {
        $host = $smtp['host'] ?? '';
        $port = (int)($smtp['port'] ?? 587);
        $user = $smtp['user'] ?? '';
        $pass = $smtp['pass'] ?? '';
        $secure = $smtp['secure'] ?? 'STARTTLS';
        $fromEmail = !empty($smtp['fromEmail']) ? $smtp['fromEmail'] : $user;
        $fromName = !empty($smtp['fromName']) ? $smtp['fromName'] : 'アンケート運営事務局';

        if (empty($host) || empty($port) || empty($fromEmail)) {
            return ['ok' => false, 'error' => 'SMTP設定（サーバー名、ポート、送信元メールアドレス）が不足しています。'];
        }

        $remote = ($secure === 'SSL/TLS') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            return ['ok' => false, 'error' => "SMTPサーバーへの接続に失敗しました: {$errstr} ({$errno})"];
        }

        $read = function() use ($socket) {
            $res = '';
            while ($line = fgets($socket, 512)) {
                $res .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $res;
        };

        $write = function($cmd) use ($socket) {
            fwrite($socket, $cmd . "\r\n");
        };

        $welcome = $read();
        if (!str_starts_with($welcome, '220')) {
            fclose($socket);
            return ['ok' => false, 'error' => 'SMTP応答エラー: ' . $welcome];
        }

        $write("EHLO " . (gethostname() ?: 'localhost'));
        $read();

        if ($secure === 'STARTTLS') {
            $write("STARTTLS");
            $tlsRes = $read();
            if (!str_starts_with($tlsRes, '220')) {
                fclose($socket);
                return ['ok' => false, 'error' => 'STARTTLSコマンド失敗: ' . $tlsRes];
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                fclose($socket);
                return ['ok' => false, 'error' => 'TLS暗号化接続の確立に失敗しました。'];
            }
            $write("EHLO " . (gethostname() ?: 'localhost'));
            $read();
        }

        if (!empty($user) && !empty($pass)) {
            $write("AUTH LOGIN");
            $read();
            $write(base64_encode($user));
            $read();
            $write(base64_encode($pass));
            $authRes = $read();
            if (!str_starts_with($authRes, '235')) {
                fclose($socket);
                return ['ok' => false, 'error' => 'SMTP認証（AUTH LOGIN）に失敗しました。ユーザー名/パスワードを確認してください。'];
            }
        }

        $write("MAIL FROM:<{$fromEmail}>");
        if (!str_starts_with($read(), '250')) { fclose($socket); return ['ok' => false, 'error' => 'MAIL FROM 送信元アドレスが拒否されました。']; }

        $write("RCPT TO:<{$to}>");
        $rcptRes = $read();
        if (!str_starts_with($rcptRes, '250')) { fclose($socket); return ['ok' => false, 'error' => "RCPT TO 送信先アドレス拒否 ({$to}): {$rcptRes}"]; }

        $write("DATA");
        if (!str_starts_with($read(), '354')) { fclose($socket); return ['ok' => false, 'error' => 'DATA 送信準備拒否']; }

        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = [
            "From: {$encodedFromName} <{$fromEmail}>",
            "To: <{$to}>",
            "Subject: {$encodedSubject}",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
            "Date: " . date('r')
        ];

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body)) . "\r\n.";
        $write($payload);

        $dataRes = $read();
        $write("QUIT");
        fclose($socket);

        if (!str_starts_with($dataRes, '250')) {
            return ['ok' => false, 'error' => 'メール本文送信失敗: ' . $dataRes];
        }

        return ['ok' => true];
    }
}

// --------------------------------------------------
// バックエンド API ルーティング
// --------------------------------------------------
$action = $_GET['action'] ?? null;

if ($action) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];
    
    // CSRF検証 (公開アンケート取得・回答送信以外)
    $csrfExempt = ['get_public_survey', 'submit_answer'];
    if (!in_array($action, $csrfExempt, true)) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
        if (!verifyCsrfToken($token)) {
            jsonResponse(['ok' => false, 'error' => '不正なリクエスト（CSRFトークンが無効または失効）です。画面を更新してください。'], 403);
        }
    }

    switch ($action) {
        // 設定取得
        case 'get_settings':
            $settings = JsonStorage::load('settings.json', [
                'kintone' => ['host' => '', 'appId' => '', 'login' => '', 'pass' => '', 'proxyHost' => '', 'proxyPort' => '', 'customerNameField' => '顧客名', 'customerEmailField' => 'メールアドレス'],
                'smtp' => ['host' => '', 'port' => '587', 'secure' => 'STARTTLS', 'user' => '', 'pass' => '', 'fromEmail' => '', 'fromName' => 'アンケート運営事務局']
            ]);
            $safe = $settings;
            $safe['kintone']['pass'] = !empty($settings['kintone']['pass']) ? '********' : '';
            $safe['smtp']['pass'] = !empty($settings['smtp']['pass']) ? '********' : '';
            $safe['kintone_configured'] = !empty($settings['kintone']['host']) && !empty($settings['kintone']['appId']) && !empty($settings['kintone']['login']);
            $safe['smtp_configured'] = !empty($settings['smtp']['host']) && !empty($settings['smtp']['port']) && !empty($settings['smtp']['fromEmail']);
            jsonResponse(['ok' => true, 'settings' => $safe]);
            break;

        // 設定保存
        case 'save_settings':
            $oldSettings = JsonStorage::load('settings.json', []);
            $newSettings = $input['settings'] ?? [];
            if (($newSettings['kintone']['pass'] ?? '') === '********') {
                $newSettings['kintone']['pass'] = $oldSettings['kintone']['pass'] ?? '';
            }
            if (($newSettings['smtp']['pass'] ?? '') === '********') {
                $newSettings['smtp']['pass'] = $oldSettings['smtp']['pass'] ?? '';
            }
            JsonStorage::save('settings.json', $newSettings);
            jsonResponse(['ok' => true, 'message' => '設定を保存しました。']);
            break;

        // kintone接続確認・顧客データ同期
        case 'sync_kintone':
            $settings = JsonStorage::load('settings.json', []);
            $k = $settings['kintone'] ?? [];
            if (empty($k['host']) || empty($k['appId']) || empty($k['login'])) {
                jsonResponse(['ok' => false, 'error' => 'kintoneの接続先設定（利用先、アプリID、ログイン名）を入力・保存してください。']);
            }
            $url = KintoneService::buildUrl($k['host'], '/k/v1/records.json');
            $res = KintoneService::request('GET', $url, ['app' => (int)$k['appId'], 'totalCount' => 'true'], $k);
            if (!$res['ok']) {
                jsonResponse(['ok' => false, 'error' => 'kintone接続エラー: ' . $res['error']]);
            }
            $records = $res['data']['records'] ?? [];
            $nameField = !empty($k['customerNameField']) ? $k['customerNameField'] : '顧客名';
            $emailField = !empty($k['customerEmailField']) ? $k['customerEmailField'] : 'メールアドレス';

            $customers = [];
            foreach ($records as $r) {
                $customers[] = [
                    'id' => (string)($r['$id']['value'] ?? $r['レコード番号']['value'] ?? uniqid()),
                    'name' => (string)($r[$nameField]['value'] ?? $r['顧客名']['value'] ?? $r['氏名']['value'] ?? $r['name']['value'] ?? '名称未設定'),
                    'email' => (string)($r[$emailField]['value'] ?? $r['メールアドレス']['value'] ?? $r['mail']['value'] ?? $r['email']['value'] ?? ''),
                    'company' => (string)($r['会社名']['value'] ?? $r['組織名']['value'] ?? $r['company']['value'] ?? '')
                ];
            }
            JsonStorage::save('customers.json', $customers);
            jsonResponse(['ok' => true, 'customers' => $customers, 'count' => count($customers), 'message' => "kintoneから顧客データを " . count($customers) . " 件取得・同期しました。"]);
            break;

        // 顧客一覧取得
        case 'get_customers':
            $customers = JsonStorage::load('customers.json', []);
            jsonResponse(['ok' => true, 'customers' => $customers]);
            break;

        // アンケート一覧取得（階層構造復元）
        case 'get_surveys':
            $surveys = JsonStorage::load('surveys.json', []);
            $groups = JsonStorage::load('groups.json', []);
            $questions = JsonStorage::load('questions.json', []);
            $options = JsonStorage::load('options.json', []);
            $responses = JsonStorage::load('responses.json', []);
            $recipients = JsonStorage::load('recipients.json', []);

            foreach ($surveys as &$s) {
                $sId = $s['id'];
                $s['groups'] = array_values(array_filter($groups, fn($g) => (string)$g['surveyId'] === (string)$sId));
                usort($s['groups'], fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

                foreach ($s['groups'] as &$g) {
                    $gId = $g['id'];
                    $g['questions'] = array_values(array_filter($questions, fn($q) => (string)$q['groupId'] === (string)$gId));
                    usort($g['questions'], fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

                    foreach ($g['questions'] as &$q) {
                        $qId = $q['id'];
                        $q['options'] = array_values(array_filter($options, fn($o) => (string)$o['questionId'] === (string)$qId));
                        usort($q['options'], fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                    }
                }

                $sRecipients = array_filter($recipients, fn($r) => (string)$r['surveyId'] === (string)$sId);
                $sResponses = array_filter($responses, fn($r) => (string)$r['surveyId'] === (string)$sId);
                $s['stats'] = [
                    'recipient_count' => count($sRecipients),
                    'sent_count' => count(array_filter($sRecipients, fn($r) => ($r['status'] ?? '') === 'sent')),
                    'answered_count' => count($sResponses)
                ];
            }
            jsonResponse(['ok' => true, 'surveys' => $surveys]);
            break;

        // アンケート保存（正規化テーブル分解保存）
        case 'save_survey':
            $surveyData = $input['survey'] ?? null;
            if (!$surveyData || empty(trim($surveyData['name'] ?? ''))) {
                jsonResponse(['ok' => false, 'error' => 'アンケート名を入力してください。']);
            }

            $surveys = JsonStorage::load('surveys.json', []);
            $groups = JsonStorage::load('groups.json', []);
            $questions = JsonStorage::load('questions.json', []);
            $options = JsonStorage::load('options.json', []);

            $sId = $surveyData['id'] ?? null;
            $isNew = false;
            if (!$sId) {
                $isNew = true;
                $sId = 'srv_' . bin2hex(random_bytes(6));
                $surveyData['id'] = $sId;
                $surveyData['createdAt'] = date('Y-m-d');
                $surveyData['status'] = $surveyData['status'] ?? '下書き';
            }
            $surveyData['updatedAt'] = date('Y-m-d H:i');

            // 既存グループ・質問・選択肢を削除して再登録
            $groups = array_filter($groups, fn($g) => (string)$g['surveyId'] !== (string)$sId);
            $existingQIds = array_map(fn($q) => $q['id'], array_filter($questions, fn($q) => (string)($q['surveyId'] ?? '') === (string)$sId));
            $questions = array_filter($questions, fn($q) => (string)($q['surveyId'] ?? '') !== (string)$sId);
            $options = array_filter($options, fn($o) => !in_array($o['questionId'], $existingQIds, true));

            $gOrder = 1;
            foreach ($surveyData['groups'] ?? [] as $g) {
                $gId = $g['id'] ?? ('grp_' . bin2hex(random_bytes(6)));
                $groups[] = [
                    'id' => $gId,
                    'surveyId' => $sId,
                    'name' => $g['name'] ?? '質問グループ',
                    'order' => $gOrder++
                ];

                $qOrder = 1;
                foreach ($g['questions'] ?? [] as $q) {
                    $qId = $q['id'] ?? ('qst_' . bin2hex(random_bytes(6)));
                    $questions[] = [
                        'id' => $qId,
                        'surveyId' => $sId,
                        'groupId' => $gId,
                        'text' => $q['text'] ?? '',
                        'type' => $q['type'] ?? 'single',
                        'required' => !empty($q['required']),
                        'order' => $qOrder++
                    ];

                    $oOrder = 1;
                    foreach ($q['options'] ?? [] as $opt) {
                        $options[] = [
                            'id' => $opt['id'] ?? ('opt_' . bin2hex(random_bytes(6))),
                            'questionId' => $qId,
                            'text' => $opt['text'] ?? '',
                            'next' => $opt['next'] ?? 'next', // 'next' | 'end' | 指定質問ID
                            'order' => $oOrder++
                        ];
                    }
                }
            }

            // アンケート本体の保存
            unset($surveyData['groups']);
            $found = false;
            foreach ($surveys as &$existing) {
                if ((string)$existing['id'] === (string)$sId) {
                    $existing = array_merge($existing, $surveyData);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $surveys[] = $surveyData;
            }

            JsonStorage::save('surveys.json', $surveys);
            JsonStorage::save('groups.json', array_values($groups));
            JsonStorage::save('questions.json', array_values($questions));
            JsonStorage::save('options.json', array_values($options));

            jsonResponse(['ok' => true, 'surveyId' => $sId, 'message' => 'アンケートを保存しました。']);
            break;

        // アンケートステータス更新 (公開 / 終了)
        case 'update_survey_status':
            $sId = $input['id'] ?? null;
            $newStatus = $input['status'] ?? null;
            if (!$sId || !in_array($newStatus, ['下書き', '公開中', '終了'], true)) {
                jsonResponse(['ok' => false, 'error' => '無効なステータス変更です。']);
            }
            $surveys = JsonStorage::load('surveys.json', []);
            $target = null;
            foreach ($surveys as &$s) {
                if ((string)$s['id'] === (string)$sId) {
                    $s['status'] = $newStatus;
                    $s['updatedAt'] = date('Y-m-d H:i');
                    $target = $s;
                    break;
                }
            }
            if ($target) {
                JsonStorage::save('surveys.json', $surveys);
                jsonResponse(['ok' => true, 'message' => "アンケートの状態を「{$newStatus}」に更新しました。"]);
            }
            jsonResponse(['ok' => false, 'error' => '指定されたアンケートが見つかりません。']);
            break;

        // アンケート削除（下書きのみ）
        case 'delete_survey':
            $sId = $input['id'] ?? null;
            $surveys = JsonStorage::load('surveys.json', []);
            $target = null;
            foreach ($surveys as $s) {
                if ((string)$s['id'] === (string)$sId) {
                    $target = $s;
                    break;
                }
            }
            if (!$target) {
                jsonResponse(['ok' => false, 'error' => 'アンケートが見つかりません。']);
            }
            if ($target['status'] !== '下書き') {
                jsonResponse(['ok' => false, 'error' => '公開中または終了状態のアンケートは削除できません。']);
            }

            $surveys = array_values(array_filter($surveys, fn($s) => (string)$s['id'] !== (string)$sId));
            $groups = array_values(array_filter(JsonStorage::load('groups.json', []), fn($g) => (string)$g['surveyId'] !== (string)$sId));
            $questions = JsonStorage::load('questions.json', []);
            $deletedQIds = array_map(fn($q) => $q['id'], array_filter($questions, fn($q) => (string)($q['surveyId'] ?? '') === (string)$sId));
            $questions = array_values(array_filter($questions, fn($q) => (string)($q['surveyId'] ?? '') !== (string)$sId));
            $options = array_values(array_filter(JsonStorage::load('options.json', []), fn($o) => !in_array($o['questionId'], $deletedQIds, true)));

            JsonStorage::save('surveys.json', $surveys);
            JsonStorage::save('groups.json', $groups);
            JsonStorage::save('questions.json', $questions);
            JsonStorage::save('options.json', $options);

            jsonResponse(['ok' => true, 'message' => 'アンケートを削除しました。']);
            break;

        // メール送信実行
        case 'send_survey_emails':
            $sId = $input['surveyId'] ?? null;
            $customerIds = $input['customerIds'] ?? [];
            $subject = $input['subject'] ?? '';
            $bodyTemplate = $input['body'] ?? '';

            if (!$sId || empty($customerIds)) {
                jsonResponse(['ok' => false, 'error' => '送信対象者が選択されていません。']);
            }

            $settings = JsonStorage::load('settings.json', []);
            $smtp = $settings['smtp'] ?? [];
            if (empty($smtp['host']) || empty($smtp['fromEmail'])) {
                jsonResponse(['ok' => false, 'error' => 'SMTP設定が未完了のため、メールを送信できません。設定画面でSMTP情報を登録してください。']);
            }

            $customers = JsonStorage::load('customers.json', []);
            $targetCustomers = array_filter($customers, fn($c) => in_array($c['id'], $customerIds));
            
            $recipients = JsonStorage::load('recipients.json', []);
            $results = [
                'total' => count($targetCustomers),
                'success' => 0,
                'failed' => 0,
                'failed_details' => []
            ];

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $script = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
            $baseUrl = $protocol . $host . $script;

            foreach ($targetCustomers as $c) {
                if (empty($c['email'])) {
                    $results['failed']++;
                    $results['failed_details'][] = ['name' => $c['name'], 'email' => '(なし)', 'error' => 'メールアドレス未登録'];
                    continue;
                }

                $surveyUrl = $baseUrl . '?mode=answer&id=' . urlencode((string)$sId) . '&cid=' . urlencode((string)$c['id']);
                $body = str_replace(
                    ['{{name}}', '{{company}}', '{{survey_url}}'],
                    [$c['name'], $c['company'] ?? '', $surveyUrl],
                    $bodyTemplate
                );

                $sendRes = SmtpService::send($smtp, $c['email'], $subject, $body);
                $status = $sendRes['ok'] ? 'sent' : 'failed';
                $errorMsg = $sendRes['ok'] ? '' : $sendRes['error'];

                if ($sendRes['ok']) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['failed_details'][] = ['name' => $c['name'], 'email' => $c['email'], 'error' => $errorMsg];
                }

                // 送信記録の保存
                $recipients[] = [
                    'id' => 'rec_' . bin2hex(random_bytes(6)),
                    'surveyId' => $sId,
                    'customerId' => $c['id'],
                    'customerName' => $c['name'],
                    'customerEmail' => $c['email'],
                    'status' => $status,
                    'error' => $errorMsg,
                    'sentAt' => date('Y-m-d H:i:s')
                ];
            }

            JsonStorage::save('recipients.json', $recipients);
            jsonResponse(['ok' => true, 'result' => $results, 'message' => "送信完了: 成功 {$results['success']} 件 / 失敗 {$results['failed']} 件"]);
            break;

        // 回答状況・推移データ取得
        case 'get_survey_status':
            $sId = $_GET['id'] ?? null;
            if (!$sId) jsonResponse(['ok' => false, 'error' => 'アンケートIDが指定されていません。']);

            $recipients = array_values(array_filter(JsonStorage::load('recipients.json', []), fn($r) => (string)$r['surveyId'] === (string)$sId));
            $responses = array_values(array_filter(JsonStorage::load('responses.json', []), fn($res) => (string)$res['surveyId'] === (string)$sId));

            $sentTotal = count($recipients);
            $answeredTotal = count($responses);
            $unansweredCount = max(0, $sentTotal - $answeredTotal);
            $rate = $sentTotal > 0 ? round(($answeredTotal / $sentTotal) * 100, 1) : 0;

            // 回答日ごとの集計推移
            $trend = [];
            foreach ($responses as $res) {
                $day = substr($res['submittedAt'] ?? date('Y-m-d'), 0, 10);
                $trend[$day] = ($trend[$day] ?? 0) + 1;
            }
            ksort($trend);

            jsonResponse([
                'ok' => true,
                'stats' => [
                    'sent_total' => $sentTotal,
                    'sent_success' => count(array_filter($recipients, fn($r) => $r['status'] === 'sent')),
                    'answered_count' => $answeredTotal,
                    'unanswered_count' => $unansweredCount,
                    'response_rate' => $rate,
                    'trend' => $trend,
                    'recipients' => $recipients
                ]
            ]);
            break;

        // 回答結果・集計データ取得
        case 'get_survey_results':
            $sId = $_GET['id'] ?? null;
            if (!$sId) jsonResponse(['ok' => false, 'error' => 'アンケートIDが指定されていません。']);

            $questions = array_values(array_filter(JsonStorage::load('questions.json', []), fn($q) => (string)($q['surveyId'] ?? '') === (string)$sId));
            usort($questions, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
            $options = JsonStorage::load('options.json', []);
            $responses = array_values(array_filter(JsonStorage::load('responses.json', []), fn($res) => (string)$res['surveyId'] === (string)$sId));

            $aggregated = [];
            foreach ($questions as $q) {
                $qId = $q['id'];
                $qOptions = array_values(array_filter($options, fn($o) => (string)$o['questionId'] === (string)$qId));
                
                $summary = [
                    'question' => $q,
                    'options' => $qOptions,
                    'total_answers' => 0,
                    'counts' => [],
                    'texts' => []
                ];

                foreach ($qOptions as $opt) {
                    $summary['counts'][$opt['text']] = 0;
                }

                foreach ($responses as $res) {
                    $ans = $res['answers'][$qId] ?? null;
                    if ($ans === null || $ans === '') continue;

                    $summary['total_answers']++;
                    if ($q['type'] === 'single') {
                        $txt = (string)$ans;
                        $summary['counts'][$txt] = ($summary['counts'][$txt] ?? 0) + 1;
                    } elseif ($q['type'] === 'multiple') {
                        if (is_array($ans)) {
                            foreach ($ans as $selected) {
                                $summary['counts'][$selected] = ($summary['counts'][$selected] ?? 0) + 1;
                            }
                        }
                    } elseif ($q['type'] === 'text') {
                        $summary['texts'][] = [
                            'text' => (string)$ans,
                            'submittedAt' => $res['submittedAt'] ?? '',
                            'respondent' => $res['respondentName'] ?? '匿名'
                        ];
                    }
                }
                $aggregated[] = $summary;
            }

            jsonResponse([
                'ok' => true,
                'total_responses' => count($responses),
                'aggregated' => $aggregated
            ]);
            break;

        // 公開用アンケート取得（回答者画面用）
        case 'get_public_survey':
            $sId = $_GET['id'] ?? null;
            $surveys = JsonStorage::load('surveys.json', []);
            $target = null;
            foreach ($surveys as $s) {
                if ((string)$s['id'] === (string)$sId) {
                    $target = $s;
                    break;
                }
            }
            if (!$target) {
                jsonResponse(['ok' => false, 'error' => 'アンケートが見つかりません。'], 404);
            }
            if ($target['status'] !== '公開中') {
                jsonResponse(['ok' => false, 'error' => 'このアンケートは現在回答を受け付けておりません。（状態: ' . $target['status'] . '）'], 403);
            }

            $groups = array_values(array_filter(JsonStorage::load('groups.json', []), fn($g) => (string)$g['surveyId'] === (string)$sId));
            usort($groups, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
            $questions = JsonStorage::load('questions.json', []);
            $options = JsonStorage::load('options.json', []);

            foreach ($groups as &$g) {
                $gId = $g['id'];
                $g['questions'] = array_values(array_filter($questions, fn($q) => (string)$q['groupId'] === (string)$gId));
                usort($g['questions'], fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                foreach ($g['questions'] as &$q) {
                    $qId = $q['id'];
                    $q['options'] = array_values(array_filter($options, fn($o) => (string)$o['questionId'] === (string)$qId));
                    usort($q['options'], fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                }
            }
            $target['groups'] = $groups;
            jsonResponse(['ok' => true, 'survey' => $target]);
            break;

        // 回答送信（一般回答者 & 顧客両対応）
        case 'submit_answer':
            $sId = $input['surveyId'] ?? null;
            $answers = $input['answers'] ?? [];
            $customerId = $input['customerId'] ?? null;

            $surveys = JsonStorage::load('surveys.json', []);
            $target = null;
            foreach ($surveys as $s) {
                if ((string)$s['id'] === (string)$sId) {
                    $target = $s;
                    break;
                }
            }
            if (!$target || $target['status'] !== '公開中') {
                jsonResponse(['ok' => false, 'error' => 'このアンケートは回答を受け付けておりません。'], 400);
            }

            // 顧客情報の紐付け（任意）
            $respondentName = '一般回答者';
            if ($customerId) {
                $customers = JsonStorage::load('customers.json', []);
                foreach ($customers as $c) {
                    if ((string)$c['id'] === (string)$customerId) {
                        $respondentName = $c['name'];
                        break;
                    }
                }
            }

            $responses = JsonStorage::load('responses.json', []);
            $responses[] = [
                'id' => 'ans_' . bin2hex(random_bytes(8)),
                'surveyId' => $sId,
                'customerId' => $customerId,
                'respondentName' => $respondentName,
                'answers' => $answers,
                'submittedAt' => date('Y-m-d H:i:s'),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ];
            JsonStorage::save('responses.json', $responses);

            jsonResponse(['ok' => true, 'message' => 'ご回答ありがとうございました。回答を送信しました。']);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => '不明なAPIアクションです。'], 404);
    }
}

// --------------------------------------------------
// 回答者専用画面モード (運営者ヘッダーや戻るボタンを完全排除)
// --------------------------------------------------
$isAnswerMode = (($_GET['mode'] ?? '') === 'answer');
$publicSurveyId = $_GET['id'] ?? '';
$publicCustomerId = $_GET['cid'] ?? '';
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isAnswerMode ? 'アンケート回答' : 'アンケート業務運営アプリ' ?></title>
  <style>
    :root {
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --bg: #f8fafc;
      --surface: #ffffff;
      --border: #cbd5e1;
      --text: #0f172a;
      --text-muted: #64748b;
      --success: #16a34a;
      --danger: #dc2626;
      --warning: #d97706;
      --card-radius: 8px;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif; }
    body { background-color: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }

    /* 運営者ヘッダー */
    header { background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; }
    .header-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 0 1rem; }
    .logo { font-weight: 700; font-size: 1.15rem; color: var(--primary); padding: 1rem 0; display: flex; align-items: center; gap: 0.5rem; }
    nav ul { display: flex; list-style: none; gap: 0.25rem; }
    nav button { background: none; border: none; padding: 1rem 0.85rem; font-size: 0.95rem; cursor: pointer; color: var(--text-muted); font-weight: 600; border-bottom: 3px solid transparent; transition: all 0.2s; }
    nav button.active { color: var(--primary); border-bottom-color: var(--primary); }
    nav button:hover:not(.active) { color: var(--text); }

    /* 個別アンケート サブメニュー */
    .sub-nav { background: #f1f5f9; border-bottom: 1px solid var(--border); padding: 0.5rem 1rem; }
    .sub-nav-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
    .sub-nav-title { font-weight: 600; font-size: 0.9rem; color: var(--text-muted); }
    .sub-nav-title span { color: var(--text); font-size: 1rem; font-weight: 700; margin-left: 0.25rem; }
    .sub-nav ul { display: flex; list-style: none; gap: 0.5rem; flex-wrap: wrap; }
    .sub-nav button { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 0.35rem 0.85rem; font-size: 0.85rem; cursor: pointer; font-weight: 500; }
    .sub-nav button.active { background: var(--primary); color: #fff; border-color: var(--primary); }

    /* メインコンテナ */
    main { max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem; flex: 1; width: 100%; }
    .card { background: var(--surface); border-radius: var(--card-radius); border: 1px solid var(--border); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; }
    .card-title { font-size: 1.15rem; font-weight: 700; }

    /* ボタンスタイル */
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.9rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; text-decoration: none; }
    .btn-primary { background: var(--primary); color: #fff; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-outline { background: var(--surface); border-color: var(--border); color: var(--text); }
    .btn-outline:hover { background: #f8fafc; border-color: #94a3b8; }
    .btn-danger { background: var(--danger); color: #fff; }
    .btn-danger:hover { background: #b91c1c; }
    .btn-danger-outline { border-color: var(--danger); color: var(--danger); background: transparent; }
    .btn-danger-outline:hover { background: #fee2e2; }
    .btn-sm { padding: 0.25rem 0.55rem; font-size: 0.8rem; border-radius: 4px; }
    .btn-lg { padding: 0.75rem 1.5rem; font-size: 1rem; }

    /* バッジ */
    .badge { display: inline-block; padding: 0.25rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; }
    .badge-draft { background: #e2e8f0; color: #475569; }
    .badge-active { background: #dcfce7; color: #15803d; }
    .badge-closed { background: #fee2e2; color: #b91c1c; }

    /* フォーム要素 */
    .form-group { margin-bottom: 1.25rem; }
    .form-group label { display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.35rem; color: #334155; }
    .form-group .help-text { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; }
    .form-control { width: 100%; padding: 0.6rem 0.8rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; background: #fff; }
    .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
    .form-row { display: flex; gap: 1rem; flex-wrap: wrap; }
    .form-row .form-group { flex: 1; min-width: 200px; }

    /* テーブル */
    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
    th, td { padding: 0.85rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    th { background: #f8fafc; font-weight: 700; color: var(--text-muted); white-space: nowrap; }

    /* エディタ専用スタイル (D&D / グループ / 質問) */
    .group-block { background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem; position: relative; }
    .group-block.dragging { opacity: 0.4; border: 2px dashed var(--primary); }
    .group-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e2e8f0; }
    .group-title-input { font-size: 1.1rem; font-weight: 700; border: none; background: transparent; border-bottom: 2px dashed #cbd5e1; padding: 0.2rem 0.4rem; width: 60%; }
    .group-title-input:focus { border-bottom-color: var(--primary); outline: none; background: #fff; }
    
    .question-block { background: #ffffff; border: 1px solid var(--border); border-radius: 6px; padding: 1rem; margin-bottom: 0.85rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
    .question-block.dragging { opacity: 0.4; border: 2px dashed var(--primary); }
    .question-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; }
    .drag-handle { cursor: grab; color: #94a3b8; padding: 0.25rem 0.5rem; user-select: none; font-size: 1.1rem; }
    .drag-handle:active { cursor: grabbing; }
    
    .choice-item { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; background: #f8fafc; padding: 0.4rem 0.6rem; border-radius: 4px; border: 1px solid #e2e8f0; }
    .branch-select { font-size: 0.8rem; padding: 0.3rem; border-radius: 4px; border: 1px solid #cbd5e1; }

    /* ダッシュボードカード */
    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .stat-card { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; text-align: center; }
    .stat-num { font-size: 1.8rem; font-weight: 800; color: var(--primary); margin-top: 0.25rem; }
    .stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; }

    /* プログレスバー（集計用） */
    .progress-bar-wrap { background: #e2e8f0; border-radius: 9999px; height: 12px; width: 100%; overflow: hidden; margin-top: 0.35rem; }
    .progress-bar { background: var(--primary); height: 100%; border-radius: 9999px; transition: width 0.3s; }

    /* トースト */
    .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #1e293b; color: #fff; padding: 0.85rem 1.4rem; border-radius: 6px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2); z-index: 1000; font-size: 0.9rem; display: none; }

    /* モーダル */
    .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); display: none; align-items: center; justify-content: center; z-index: 500; backdrop-filter: blur(2px); }
    .modal { background: #fff; width: 92%; max-width: 650px; border-radius: 10px; padding: 1.5rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); }

    /* 回答者専用画面スタイル */
    .respondent-body { background: #f1f5f9; }
    .respondent-container { max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
    .respondent-card { background: #fff; border-radius: 10px; border: 1px solid var(--border); padding: 2rem; margin-bottom: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .choice-label { display: flex; align-items: center; gap: 0.6rem; padding: 0.65rem 0.85rem; border: 1.5px solid #e2e8f0; border-radius: 6px; margin-bottom: 0.5rem; cursor: pointer; transition: all 0.2s; font-size: 0.95rem; }
    .choice-label:hover { background: #f8fafc; border-color: #cbd5e1; }
    .choice-label input:checked + span { font-weight: 700; color: var(--primary); }
  </style>
</head>
<body class="<?= $isAnswerMode ? 'respondent-body' : '' ?>">

<?php if (!$isAnswerMode): ?>
  <!-- 運営者用メインヘッダー -->
  <header id="app-header">
    <div class="header-container">
      <div class="logo">📋 アンケート業務運営アプリ</div>
      <nav>
        <ul>
          <li><button class="active" onclick="App.navigate('survey-list')">アンケート一覧</button></li>
          <li><button onclick="App.navigate('survey-editor', null)">アンケート作成</button></li>
          <li><button onclick="App.navigate('customer-list')">顧客一覧</button></li>
          <li><button onclick="App.navigate('settings')">設定</button></li>
        </ul>
      </nav>
    </div>
    <!-- 個別アンケート用サブナビゲーション -->
    <div id="sub-nav-bar" class="sub-nav" style="display: none;">
      <div class="sub-nav-container">
        <div class="sub-nav-title">選択中のアンケート: <span id="current-survey-name"></span></div>
        <ul>
          <li><button class="active" onclick="App.navigateSub('detail')">アンケート内容</button></li>
          <li><button onclick="App.navigateSub('send')">送信</button></li>
          <li><button onclick="App.navigateSub('status')">回答状況</button></li>
          <li><button onclick="App.navigateSub('result')">回答結果</button></li>
          <li><button onclick="App.openRespondentPreview()">回答画面を開く ↗</button></li>
        </ul>
      </div>
    </div>
  </header>

  <!-- メインコンテンツ -->
  <main id="main-content"></main>
<?php else: ?>
  <!-- 回答者専用画面 (ヘッダー・運営用ボタン排除) -->
  <main class="respondent-container" id="respondent-content">
    <div class="respondent-card" style="text-align: center;">読み込み中...</div>
  </main>
<?php endif; ?>

<!-- トースト通知 -->
<div id="toast" class="toast"></div>

<!-- 汎用モーダル -->
<div id="modal" class="modal-overlay">
  <div class="modal">
    <h3 id="modal-title" style="margin-bottom: 0.85rem; font-size: 1.15rem;">確認</h3>
    <div id="modal-body" style="font-size: 0.9rem; margin-bottom: 1.5rem; line-height: 1.6;"></div>
    <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
      <button class="btn btn-outline" onclick="App.closeModal()">キャンセル</button>
      <button id="modal-confirm-btn" class="btn btn-primary">実行</button>
    </div>
  </div>
</div>

<script>
// ==================================================
// アプリケーション コアロジック (SPA / フロントエンド)
// ==================================================
const App = {
  csrfToken: '<?= $csrfToken ?>',
  currentView: 'survey-list',
  currentSubView: 'detail',
  selectedSurveyId: null,
  isDirty: false, // 編集中フラグ

  state: {
    settings: null,
    customers: [],
    surveys: [],
    editingSurvey: null,
    currentStatus: null,
    currentResults: null
  },

  // 初期化
  async init() {
    <?php if ($isAnswerMode): ?>
      RespondentApp.init('<?= h($publicSurveyId) ?>', '<?= h($publicCustomerId) ?>');
      return;
    <?php endif; ?>

    window.addEventListener('beforeunload', (e) => {
      if (this.isDirty) {
        e.preventDefault();
        e.returnValue = '保存されていない変更があります。移動しますか？';
      }
    });

    await this.fetchSurveys();
    this.navigate('survey-list');
  },

  // API 通信共通関数
  async api(action, method = 'GET', data = null) {
    const url = `?action=${action}`;
    const options = {
      method: method,
      headers: {
        'X-CSRF-TOKEN': this.csrfToken,
        'Content-Type': 'application/json'
      }
    };
    if (data && method !== 'GET') {
      options.body = JSON.stringify(data);
    }
    try {
      const res = await fetch(url, options);
      const json = await res.json();
      if (!json.ok) {
        throw new Error(json.error || 'エラーが発生しました。');
      }
      return json;
    } catch (err) {
      this.toast(err.message, 'danger');
      throw err;
    }
  },

  // トースト表示
  toast(msg, type = 'info') {
    const t = document.getElementById('toast');
    t.innerText = msg;
    t.style.backgroundColor = type === 'danger' ? '#dc2626' : (type === 'success' ? '#16a34a' : '#1e293b');
    t.style.display = 'block';
    clearTimeout(this._toastTimer);
    this._toastTimer = setTimeout(() => { t.style.display = 'none'; }, 3500);
  },

  // モーダル表示
  openModal(title, bodyHtml, onConfirm, confirmText = '実行', confirmClass = 'btn-primary') {
    document.getElementById('modal-title').innerText = title;
    document.getElementById('modal-body').innerHTML = bodyHtml;
    const btn = document.getElementById('modal-confirm-btn');
    btn.className = `btn ${confirmClass}`;
    btn.innerText = confirmText;
    btn.onclick = async () => {
      await onConfirm();
      this.closeModal();
    };
    document.getElementById('modal').style.display = 'flex';
  },

  closeModal() {
    document.getElementById('modal').style.display = 'none';
  },

  // 画面遷移
  async navigate(view, surveyId = null) {
    if (this.isDirty && this.currentView === 'survey-editor' && view !== 'survey-editor') {
      if (!confirm('保存されていない編集内容があります。破棄して移動しますか？')) {
        return;
      }
      this.isDirty = false;
    }

    this.currentView = view;
    if (surveyId !== null) {
      this.selectedSurveyId = surveyId;
    }

    const subNav = document.getElementById('sub-nav-bar');
    const isSubSection = ['detail', 'send', 'status', 'result'].includes(view);

    // ヘッダーナビ更新
    document.querySelectorAll('#app-header nav button').forEach(btn => btn.classList.remove('active'));
    const navMap = { 'survey-list': 'アンケート一覧', 'survey-editor': 'アンケート作成', 'customer-list': '顧客一覧', 'settings': '設定' };
    Array.from(document.querySelectorAll('#app-header nav button')).forEach(btn => {
      if (btn.innerText === navMap[view]) btn.classList.add('active');
    });

    if (isSubSection) {
      subNav.style.display = 'block';
      const s = this.getSurvey(this.selectedSurveyId);
      document.getElementById('current-survey-name').innerText = s ? s.name : '（未選択）';
      this.currentSubView = view;
      document.querySelectorAll('#sub-nav-bar button').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('onclick')?.includes(view));
      });
    } else {
      subNav.style.display = 'none';
    }

    const container = document.getElementById('main-content');
    switch (view) {
      case 'survey-list': await this.renderSurveyList(container); break;
      case 'survey-editor': await this.renderSurveyEditor(container, this.selectedSurveyId); break;
      case 'customer-list': await this.renderCustomerList(container); break;
      case 'settings': await this.renderSettings(container); break;
      case 'detail': await this.renderSurveyDetail(container); break;
      case 'send': await this.renderSurveySend(container); break;
      case 'status': await this.renderSurveyStatus(container); break;
      case 'result': await this.renderSurveyResult(container); break;
    }
  },

  navigateSub(subView) {
    this.navigate(subView, this.selectedSurveyId);
  },

  getSurvey(id) {
    return this.state.surveys.find(s => String(s.id) === String(id));
  },

  async fetchSurveys() {
    const res = await this.api('get_surveys');
    this.state.surveys = res.surveys || [];
  },

  // 質問番号自動計算（全体通番 or グループ別）
  calculateQuestionNumbers(survey) {
    const map = {};
    let gIdx = 1;
    let totalIdx = 1;
    (survey.groups || []).forEach(g => {
      let qIdx = 1;
      (g.questions || []).forEach(q => {
        map[q.id] = (survey.numberingFormat === 'group') ? `Q${gIdx}-${qIdx}` : `Q${totalIdx}`;
        qIdx++;
        totalIdx++;
      });
      gIdx++;
    });
    return map;
  },

  // --------------------------------------------------
  // 1. アンケート一覧画面 (ダッシュボードは表示しない要件)
  // --------------------------------------------------
  async renderSurveyList(el) {
    await this.fetchSurveys();
    el.innerHTML = `
      <div class="card">
        <div class="card-header">
          <h2 class="card-title">アンケート一覧</h2>
          <button class="btn btn-primary" onclick="App.navigate('survey-editor', null)">＋ 新規アンケート作成</button>
        </div>
        <table>
          <thead>
            <tr>
              <th>アンケート名</th>
              <th>状態</th>
              <th>作成日</th>
              <th>公開期間</th>
              <th>回答数</th>
              <th>最終更新</th>
              <th style="text-align: right;">操作</th>
            </tr>
          </thead>
          <tbody>
            ${this.state.surveys.length === 0 ? '<tr><td colspan="7" style="text-align:center; padding: 2rem; color: #94a3b8;">登録されているアンケートはありません。</td></tr>' : ''}
            ${this.state.surveys.map(s => {
              const badgeClass = s.status === '公開中' ? 'badge-active' : (s.status === '下書き' ? 'badge-draft' : 'badge-closed');
              const answered = s.stats ? s.stats.answered_count : 0;
              return `
                <tr>
                  <td><strong><a href="javascript:void(0)" onclick="App.navigate('detail', '${s.id}')" style="color: var(--primary); text-decoration: none;">${this.escape(s.name)}</a></strong></td>
                  <td><span class="badge ${badgeClass}">${s.status}</span></td>
                  <td>${s.createdAt || '-'}</td>
                  <td>${s.startDate || '未設定'} 〜 ${s.endDate || '未設定'}</td>
                  <td><strong>${answered}</strong> 件</td>
                  <td>${s.updatedAt || '-'}</td>
                  <td style="text-align: right;">
                    <button class="btn btn-outline btn-sm" onclick="App.navigate('detail', '${s.id}')">管理</button>
                    <button class="btn btn-outline btn-sm" onclick="App.navigate('survey-editor', '${s.id}')">編集</button>
                    ${s.status === '下書き' ? `<button class="btn btn-primary btn-sm" onclick="App.changeStatus('${s.id}', '公開中')">公開</button>` : ''}
                    ${s.status === '公開中' ? `<button class="btn btn-outline btn-sm" onclick="App.changeStatus('${s.id}', '終了')">終了</button>` : ''}
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

  // --------------------------------------------------
  // 2. アンケート作成・編集画面 (1画面完結 / D&D並べ替え / 分岐設定)
  // --------------------------------------------------
  async renderSurveyEditor(el, surveyId) {
    if (surveyId) {
      await this.fetchSurveys();
      const existing = this.getSurvey(surveyId);
      this.state.editingSurvey = JSON.parse(JSON.stringify(existing));
    } else {
      this.state.editingSurvey = {
        id: null,
        name: '',
        description: '',
        startDate: new Date().toISOString().substring(0, 10),
        endDate: '',
        status: '下書き',
        numberingFormat: 'group',
        groups: [
          {
            id: 'grp_1',
            name: '基本情報',
            questions: [
              { id: 'qst_1', text: 'ご意見をお聞かせください', type: 'text', required: true, options: [] }
            ]
          }
        ]
      };
    }
    this.isDirty = false;
    this.renderEditorForm(el);
  },

  renderEditorForm(el) {
    const s = this.state.editingSurvey;
    const qMap = this.calculateQuestionNumbers(s);

    // すべての質問リスト（分岐先選択用）
    const allQuestions = [];
    (s.groups || []).forEach(g => {
      (g.questions || []).forEach(q => {
        allQuestions.push({ id: q.id, label: `${qMap[q.id] || 'Q'}: ${q.text ? q.text.substring(0, 20) : '未入力'}` });
      });
    });

    el.innerHTML = `
      <div class="card">
        <div class="card-header">
          <h2 class="card-title">${s.id ? 'アンケート編集' : '新規アンケート作成'}</h2>
          <div style="display:flex; gap:0.5rem;">
            <button class="btn btn-outline" onclick="App.navigate('survey-list')">一覧へ戻る</button>
            <button class="btn btn-primary btn-lg" onclick="App.saveSurvey()">💾 アンケートを保存</button>
          </div>
        </div>

        <!-- 基本情報エリア -->
        <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 6px; padding: 1.25rem; margin-bottom: 1.5rem;">
          <h3 style="font-size: 1rem; margin-bottom: 1rem;">基本情報</h3>
          <div class="form-row">
            <div class="form-group" style="flex: 2;">
              <label>アンケート名 <span style="color: var(--danger);">*必須</span></label>
              <input type="text" class="form-control" id="survey-name" value="${this.escape(s.name)}" placeholder="例: 2026年度 サービス利用満足度調査" oninput="App.state.editingSurvey.name = this.value; App.isDirty = true;">
            </div>
            <div class="form-group">
              <label>質問番号形式</label>
              <select class="form-control" id="survey-numbering" onchange="App.state.editingSurvey.numberingFormat = this.value; App.isDirty = true; App.renderEditorForm(document.getElementById('main-content'));">
                <option value="group" ${s.numberingFormat === 'group' ? 'selected' : ''}>グループごとの番号 (Q1-1, Q1-2, Q2-1...)</option>
                <option value="global" ${s.numberingFormat === 'global' ? 'selected' : ''}>全体で通番 (Q1, Q2, Q3...)</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>アンケートの説明・案内文</label>
            <textarea class="form-control" rows="2" placeholder="回答者へ向けた概要や案内文を入力してください" oninput="App.state.editingSurvey.description = this.value; App.isDirty = true;">${this.escape(s.description || '')}</textarea>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>公開開始日</label>
              <input type="date" class="form-control" value="${s.startDate || ''}" onchange="App.state.editingSurvey.startDate = this.value; App.isDirty = true;">
            </div>
            <div class="form-group">
              <label>公開終了日</label>
              <input type="date" class="form-control" value="${s.endDate || ''}" onchange="App.state.editingSurvey.endDate = this.value; App.isDirty = true;">
            </div>
            <div class="form-group">
              <label>公開状態</label>
              <input type="text" class="form-control" value="${s.status}" disabled>
            </div>
          </div>
        </div>

        <!-- 質問グループ・質問エディタ領域 (D&D対応) -->
        <div id="groups-container">
          ${(s.groups || []).map((g, gIdx) => `
            <div class="group-block" data-group-index="${gIdx}" draggable="true" ondragstart="App.onGroupDragStart(event, ${gIdx})" ondragover="App.onGroupDragOver(event)" ondrop="App.onGroupDrop(event, ${gIdx})">
              <div class="group-header">
                <div style="display:flex; align-items:center; gap:0.5rem; width: 80%;">
                  <span class="drag-handle" title="ドラッグしてグループを並べ替え">☰</span>
                  <input type="text" class="group-title-input" value="${this.escape(g.name)}" placeholder="グループ名を入力" oninput="App.state.editingSurvey.groups[${gIdx}].name = this.value; App.isDirty = true;">
                </div>
                <button class="btn btn-danger-outline btn-sm" onclick="App.deleteGroup(${gIdx})">グループ削除</button>
              </div>

              <!-- 質問リスト -->
              <div class="questions-container" id="questions-container-${gIdx}">
                ${(g.questions || []).map((q, qIdx) => {
                  const qNum = qMap[q.id] || `Q${qIdx + 1}`;
                  return `
                    <div class="question-block" data-question-index="${qIdx}" draggable="true" ondragstart="App.onQuestionDragStart(event, ${gIdx}, ${qIdx})" ondragover="App.onQuestionDragOver(event)" ondrop="App.onQuestionDrop(event, ${gIdx}, ${qIdx})">
                      <div class="question-header">
                        <div style="display:flex; align-items:center; gap:0.5rem;">
                          <span class="drag-handle" title="ドラッグして質問を並べ替え">☰</span>
                          <strong style="color: var(--primary);">${qNum}</strong>
                        </div>
                        <div style="display:flex; align-items:center; gap:0.5rem;">
                          <label style="font-size:0.8rem; display:flex; align-items:center; gap:0.3rem; margin:0; cursor:pointer;">
                            <input type="checkbox" ${q.required ? 'checked' : ''} onchange="App.state.editingSurvey.groups[${gIdx}].questions[${qIdx}].required = this.checked; App.isDirty = true;"> 必須回答
                          </label>
                          <button class="btn btn-danger-outline btn-sm" onclick="App.deleteQuestion(${gIdx}, ${qIdx})">削除</button>
                        </div>
                      </div>

                      <div class="form-row" style="margin-bottom:0.75rem;">
                        <div class="form-group" style="flex: 2; margin-bottom:0;">
                          <input type="text" class="form-control" value="${this.escape(q.text)}" placeholder="質問文を入力してください" oninput="App.state.editingSurvey.groups[${gIdx}].questions[${qIdx}].text = this.value; App.isDirty = true;">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                          <select class="form-control" onchange="App.changeQuestionType(${gIdx}, ${qIdx}, this.value)">
                            <option value="single" ${q.type === 'single' ? 'selected' : ''}>単一選択 (ラジオボタン)</option>
                            <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選択 (チェックボックス)</option>
                            <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述 (テキスト)</option>
                          </select>
                        </div>
                      </div>

                      <!-- 選択肢・分岐設定 (単一・複数選択のみ) -->
                      ${q.type !== 'text' ? `
                        <div style="background:#f1f5f9; padding:0.75rem; border-radius:6px; margin-top:0.5rem;">
                          <div style="font-size:0.8rem; font-weight:700; color:var(--text-muted); margin-bottom:0.4rem;">
                            選択肢設定 ${q.type === 'single' ? '<span style="color:var(--primary); font-weight:normal;">(回答による分岐先を設定可能)</span>' : ''}
                          </div>
                          ${(q.options || []).map((opt, optIdx) => `
                            <div class="choice-item">
                              <span style="color:#94a3b8;">●</span>
                              <input type="text" class="form-control" style="padding:0.35rem 0.6rem; font-size:0.85rem;" value="${this.escape(opt.text)}" placeholder="選択肢を入力" oninput="App.state.editingSurvey.groups[${gIdx}].questions[${qIdx}].options[${optIdx}].text = this.value; App.isDirty = true;">
                              
                              ${q.type === 'single' ? `
                                <div style="display:flex; align-items:center; gap:0.3rem; white-space:nowrap; font-size:0.8rem;">
                                  <span>分岐先:</span>
                                  <select class="branch-select" onchange="App.state.editingSurvey.groups[${gIdx}].questions[${qIdx}].options[${optIdx}].next = this.value; App.isDirty = true;">
                                    <option value="next" ${opt.next === 'next' ? 'selected' : ''}>次の質問へ進む</option>
                                    <option value="end" ${opt.next === 'end' ? 'selected' : ''}>アンケート終了へ</option>
                                    <optgroup label="指定した質問へジャンプ">
                                      ${allQuestions.filter(aq => aq.id !== q.id).map(aq => `
                                        <option value="${aq.id}" ${opt.next === aq.id ? 'selected' : ''}>${this.escape(aq.label)}</option>
                                      `).join('')}
                                    </optgroup>
                                  </select>
                                </div>
                              ` : ''}

                              <button class="btn btn-outline btn-sm" style="color:var(--danger);" onclick="App.deleteOption(${gIdx}, ${qIdx}, ${optIdx})">×</button>
                            </div>
                          `).join('')}
                          <button class="btn btn-outline btn-sm" style="margin-top:0.4rem;" onclick="App.addOption(${gIdx}, ${qIdx})">＋ 選択肢を追加</button>
                        </div>
                      ` : ''}
                    </div>
                  `;
                }).join('')}
              </div>

              <!-- 質問追加ボタン（各グループの末尾に配置 要件準拠） -->
              <div style="margin-top: 0.75rem;">
                <button class="btn btn-outline btn-sm" onclick="App.addQuestion(${gIdx})">＋ このグループに質問を追加</button>
              </div>
            </div>
          `).join('')}
        </div>

        <!-- グループ追加ボタン（グループ一覧の末尾に配置 要件準拠） -->
        <div style="text-align: center; margin: 1.5rem 0; padding: 1rem; border: 2px dashed #cbd5e1; border-radius: 8px;">
          <button class="btn btn-outline" onclick="App.addGroup()">＋ 新しい質問グループを追加</button>
        </div>

        <div style="display:flex; justify-content: flex-end; gap:0.5rem; border-top: 1px solid var(--border); padding-top: 1rem;">
          <button class="btn btn-primary btn-lg" onclick="App.saveSurvey()">💾 アンケートを保存</button>
        </div>
      </div>
    `;
  },

  // 編集操作ハンドラ
  addGroup() {
    this.state.editingSurvey.groups.push({
      id: 'grp_' + Math.random().toString(36).substr(2, 9),
      name: '新規グループ',
      questions: [
        { id: 'qst_' + Math.random().toString(36).substr(2, 9), text: '', type: 'single', required: true, options: [{ id: 'opt_' + Math.random().toString(36).substr(2, 9), text: '選択肢 1', next: 'next' }] }
      ]
    });
    this.isDirty = true;
    this.renderEditorForm(document.getElementById('main-content'));
  },

  deleteGroup(gIdx) {
    const g = this.state.editingSurvey.groups[gIdx];
    if (g.questions && g.questions.length > 0) {
      if (!confirm(`グループ「${g.name}」に含まれる全ての質問も削除されます。よろしいですか？`)) {
        return;
      }
    }
    this.state.editingSurvey.groups.splice(gIdx, 1);
    this.isDirty = true;
    this.renderEditorForm(document.getElementById('main-content'));
  },

  addQuestion(gIdx) {
    this.state.editingSurvey.groups[gIdx].
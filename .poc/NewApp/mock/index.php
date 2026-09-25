<?php
declare(strict_types=1);

namespace App\SurveySystem;

// セッション・セキュリティ設定
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// レスポンスヘッダー
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

// --------------------------------------------------
// ヘルパー関数
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
// JSON ファイル管理 (排他ロック付き)
// --------------------------------------------------
class JsonStorage {
    private static function getFilePath(string $filename): string {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
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
// kintone 連携サービス
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
            'User-Agent: PHP-Survey-App/1.0'
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
            return ['ok' => false, 'error' => 'kintoneサーバーへの通信に失敗しました。'];
        }

        $statusCode = 200;
        if (!empty($resHeaders) && preg_match('#HTTP/\S+\s+(\d+)#', $resHeaders[0], $m)) {
            $statusCode = (int)$m[1];
        }

        $json = json_decode($res, true);
        if ($statusCode >= 400) {
            $msg = $json['message'] ?? 'kintone APIエラー';
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
        $fromEmail = $smtp['fromEmail'] ?? $user;
        $fromName = $smtp['fromName'] ?? 'アンケート事務局';

        if (empty($host) || empty($port)) {
            return ['ok' => false, 'error' => 'SMTP設定が不完全です。'];
        }

        $remote = ($secure === 'SSL/TLS') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            return ['ok' => false, 'error' => "SMTP接続エラー: {$errstr} ({$errno})"];
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
            return ['ok' => false, 'error' => 'SMTPサーバー応答エラー: ' . $welcome];
        }

        $write("EHLO " . gethostname());
        $read();

        if ($secure === 'STARTTLS') {
            $write("STARTTLS");
            $tlsRes = $read();
            if (!str_starts_with($tlsRes, '220')) {
                fclose($socket);
                return ['ok' => false, 'error' => 'STARTTLS失敗: ' . $tlsRes];
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                fclose($socket);
                return ['ok' => false, 'error' => 'TLS暗号化の確立に失敗しました。'];
            }
            $write("EHLO " . gethostname());
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
                return ['ok' => false, 'error' => 'SMTP認証失敗'];
            }
        }

        $write("MAIL FROM:<{$fromEmail}>");
        if (!str_starts_with($read(), '250')) { fclose($socket); return ['ok' => false, 'error' => 'MAIL FROM 拒否']; }

        $write("RCPT TO:<{$to}>");
        if (!str_starts_with($read(), '250')) { fclose($socket); return ['ok' => false, 'error' => 'RCPT TO 拒否']; }

        $write("DATA");
        if (!str_starts_with($read(), '354')) { fclose($socket); return ['ok' => false, 'error' => 'DATA 拒否']; }

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
            return ['ok' => false, 'error' => 'メール送信失敗: ' . $dataRes];
        }

        return ['ok' => true];
    }
}

// --------------------------------------------------
// バックエンド API ルーティング
// --------------------------------------------------
$action = $_GET['action'] ?? null;

if ($action) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // CSRF検証 (回答送信等のパブリック処理以外)
    $csrfExempt = ['get_public_survey', 'submit_answer'];
    if (!in_array($action, $csrfExempt, true)) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
        if (!verifyCsrfToken($token)) {
            jsonResponse(['ok' => false, 'error' => '不正なリクエスト（CSRFトークン無効）です。'], 403);
        }
    }

    switch ($action) {
        // 設定取得
        case 'get_settings':
            $settings = JsonStorage::load('settings.json', [
                'kintone' => ['host' => '', 'appId' => '', 'login' => '', 'pass' => '', 'proxyHost' => '', 'proxyPort' => ''],
                'smtp' => ['host' => '', 'port' => '587', 'secure' => 'STARTTLS', 'user' => '', 'pass' => '', 'fromEmail' => '', 'fromName' => 'アンケート事務局']
            ]);
            // パスワード等はマスクして返却
            $safeSettings = $settings;
            $safeSettings['kintone']['pass'] = !empty($settings['kintone']['pass']) ? '********' : '';
            $safeSettings['smtp']['pass'] = !empty($settings['smtp']['pass']) ? '********' : '';
            jsonResponse(['ok' => true, 'settings' => $safeSettings]);
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

        // kintone接続テスト・顧客同期
        case 'sync_kintone':
            $settings = JsonStorage::load('settings.json', []);
            $k = $settings['kintone'] ?? [];
            if (empty($k['host']) || empty($k['appId']) || empty($k['login'])) {
                jsonResponse(['ok' => false, 'error' => 'kintone設定が未完了です。']);
            }
            $url = KintoneService::buildUrl($k['host'], '/k/v1/records.json');
            $res = KintoneService::request('GET', $url, ['app' => (int)$k['appId']], $k);
            if (!$res['ok']) {
                jsonResponse(['ok' => false, 'error' => $res['error']]);
            }
            $records = $res['data']['records'] ?? [];
            $customers = [];
            foreach ($records as $r) {
                $customers[] = [
                    'id' => $r['$id']['value'] ?? $r['レコード番号']['value'] ?? uniqid(),
                    'name' => $r['顧客名']['value'] ?? $r['name']['value'] ?? $r['氏名']['value'] ?? '名称未設定',
                    'email' => $r['メールアドレス']['value'] ?? $r['email']['value'] ?? $r['mail']['value'] ?? '',
                    'company' => $r['会社名']['value'] ?? $r['company']['value'] ?? $r['組織名']['value'] ?? ''
                ];
            }
            JsonStorage::save('customers.json', $customers);
            jsonResponse(['ok' => true, 'customers' => $customers, 'count' => count($customers)]);
            break;

        // アンケート一覧・詳細取得
        case 'get_surveys':
            $surveys = JsonStorage::load('surveys.json', []);
            $groups = JsonStorage::load('groups.json', []);
            $questions = JsonStorage::load('questions.json', []);
            $options = JsonStorage::load('options.json', []);
            $responses = JsonStorage::load('responses.json', []);
            $recipients = JsonStorage::load('recipients.json', []);

            foreach ($surveys as &$s) {
                $sId = $s['id'];
                $s['groups'] = array_values(array_filter($groups, fn($g) => $g['surveyId'] == $sId));
                usort($s['groups'], fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

                foreach ($s['groups'] as &$g) {
                    $gId = $g['id'];
                    $g['questions'] = array_values(array_filter($questions, fn($q) => $q['groupId'] == $gId));
                    usort($g['questions'], fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

                    foreach ($g['questions'] as &$q) {
                        $qId = $q['id'];
                        $q['options'] = array_values(array_filter($options, fn($o) => $o['questionId'] == $qId));
                        usort($q['options'], fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                    }
                }

                $sRecipients = array_filter($recipients, fn($r) => $r['surveyId'] == $sId);
                $sResponses = array_filter($responses, fn($r) => $r['surveyId'] == $sId);
                $s['stats'] = [
                    'sent' => count($sRecipients),
                    'answered' => count($sResponses),
                ];
            }
            jsonResponse(['ok' => true, 'surveys' => $surveys]);
            break;

        // アンケート保存（正規化テーブル分解保存）
        case 'save_survey':
            $surveyData = $input['survey'] ?? null;
            if (!$surveyData || empty($surveyData['name'])) {
                jsonResponse(['ok' => false, 'error' => 'アンケート名を入力してください。']);
            }

            $surveys = JsonStorage::load('surveys.json', []);
            $groups = JsonStorage::load('groups.json', []);
            $questions = JsonStorage::load('questions.json', []);
            $options = JsonStorage::load('options.json', []);

            $sId = $surveyData['id'] ?? ('s_' . bin2hex(random_bytes(6)));
            $isNew = true;
            foreach ($surveys as &$existing) {
                if ($existing['id'] == $sId) {
                    $existing['name'] = $surveyData['name'];
                    $existing['description'] = $surveyData['description'] ?? '';
                    $existing['startDate'] = $surveyData['startDate'] ?? date('Y-m-d');
                    $existing['endDate'] = $surveyData['endDate'] ?? date('Y-m-d', strtotime('+1 month'));
                    $existing['numberingFormat'] = $surveyData['numberingFormat'] ?? 'group';
                    $existing['updatedAt'] = date('Y-m-d H:i:s');
                    $isNew = false;
                    break;
                }
            }
            if ($isNew) {
                $surveys[] = [
                    'id' => $sId,
                    'name' => $surveyData['name'],
                    'description' => $surveyData['description'] ?? '',
                    'status' => '下書き',
                    'startDate' => $surveyData['startDate'] ?? date('Y-m-d'),
                    'endDate' => $surveyData['endDate'] ?? date('Y-m-d', strtotime('+1 month')),
                    'numberingFormat' => $surveyData['numberingFormat'] ?? 'group',
                    'createdAt' => date('Y-m-d H:i:s'),
                    'updatedAt' => date('Y-m-d H:i:s')
                ];
            }

            // 既存構造を一旦削除し再登録
            $groups = array_values(array_filter($groups, fn($g) => $g['surveyId'] != $sId));
            $questions = array_values(array_filter($questions, fn($q) => $q['surveyId'] != $sId));
            $options = array_values(array_filter($options, fn($o) => $o['surveyId'] != $sId));

            if (!empty($surveyData['groups']) && is_array($surveyData['groups'])) {
                foreach ($surveyData['groups'] as $gIdx => $g) {
                    $gId = $g['id'] ?? ('g_' . bin2hex(random_bytes(6)));
                    $groups[] = [
                        'id' => $gId,
                        'surveyId' => $sId,
                        'name' => $g['name'] ?? '無題グループ',
                        'order' => $gIdx + 1
                    ];
                    if (!empty($g['questions']) && is_array($g['questions'])) {
                        foreach ($g['questions'] as $qIdx => $q) {
                            $qId = $q['id'] ?? ('q_' . bin2hex(random_bytes(6)));
                            $questions[] = [
                                'id' => $qId,
                                'groupId' => $gId,
                                'surveyId' => $sId,
                                'text' => $q['text'] ?? '',
                                'type' => $q['type'] ?? 'single',
                                'required' => (bool)($q['required'] ?? false),
                                'order' => $qIdx + 1
                            ];
                            if (!empty($q['options']) && is_array($q['options'])) {
                                foreach ($q['options'] as $oIdx => $o) {
                                    $oId = $o['id'] ?? ('o_' . bin2hex(random_bytes(6)));
                                    $options[] = [
                                        'id' => $oId,
                                        'questionId' => $qId,
                                        'surveyId' => $sId,
                                        'text' => $o['text'] ?? '',
                                        'branchTo' => $o['branchTo'] ?? '',
                                        'order' => $oIdx + 1
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            JsonStorage::save('surveys.json', $surveys);
            JsonStorage::save('groups.json', $groups);
            JsonStorage::save('questions.json', $questions);
            JsonStorage::save('options.json', $options);

            jsonResponse(['ok' => true, 'surveyId' => $sId]);
            break;

        // アンケートステータス更新 (公開・終了・下書き削除)
        case 'update_survey_status':
            $sId = $input['id'] ?? null;
            $newStatus = $input['status'] ?? null;
            $surveys = JsonStorage::load('surveys.json', []);
            foreach ($surveys as &$s) {
                if ($s['id'] == $sId) {
                    $s['status'] = $newStatus;
                    $s['updatedAt'] = date('Y-m-d H:i:s');
                    break;
                }
            }
            JsonStorage::save('surveys.json', $surveys);
            jsonResponse(['ok' => true]);
            break;

        case 'delete_survey':
            $sId = $input['id'] ?? null;
            $surveys = JsonStorage::load('surveys.json', []);
            $surveys = array_values(array_filter($surveys, fn($s) => $s['id'] != $sId));
            JsonStorage::save('surveys.json', $surveys);
            jsonResponse(['ok' => true]);
            break;

        // メール送信実行
        case 'send_survey_mail':
            $sId = $input['surveyId'] ?? null;
            $customerIds = $input['customerIds'] ?? [];
            $subject = $input['subject'] ?? '';
            $bodyTemplate = $input['body'] ?? '';

            $settings = JsonStorage::load('settings.json', []);
            $smtp = $settings['smtp'] ?? [];
            $customers = JsonStorage::load('customers.json', []);
            $recipients = JsonStorage::load('recipients.json', []);
            $mailLogs = JsonStorage::load('mail_logs.json', []);

            $successCount = 0;
            $failedCount = 0;

            foreach ($customerIds as $cId) {
                $c = null;
                foreach ($customers as $item) {
                    if ($item['id'] == $cId) { $c = $item; break; }
                }
                if (!$c || empty($c['email'])) continue;

                $rId = 'r_' . bin2hex(random_bytes(6));
                $targetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?respond=' . $sId . '&rid=' . $rId;

                $mailBody = str_replace(['{name}', '{url}'], [$c['name'], $targetUrl], $bodyTemplate);
                $res = SmtpService::send($smtp, $c['email'], $subject, $mailBody);

                $recipients[] = [
                    'id' => $rId,
                    'surveyId' => $sId,
                    'customerId' => $c['id'],
                    'status' => $res['ok'] ? 'sent' : 'failed',
                    'sentAt' => date('Y-m-d H:i:s'),
                    'answered' => false
                ];

                $mailLogs[] = [
                    'id' => 'm_' . bin2hex(random_bytes(6)),
                    'recipientId' => $rId,
                    'subject' => $subject,
                    'body' => $mailBody,
                    'sentAt' => date('Y-m-d H:i:s'),
                    'success' => $res['ok'],
                    'error' => $res['error'] ?? ''
                ];

                if ($res['ok']) $successCount++;
                else $failedCount++;
            }

            JsonStorage::save('recipients.json', $recipients);
            JsonStorage::save('mail_logs.json', $mailLogs);

            jsonResponse(['ok' => true, 'total' => count($customerIds), 'success' => $successCount, 'failed' => $failedCount]);
            break;

        // 集計結果取得
        case 'get_survey_results':
            $sId = $_GET['surveyId'] ?? null;
            $responses = JsonStorage::load('responses.json', []);
            $responseAnswers = JsonStorage::load('response_answers.json', []);
            
            $sResponses = array_values(array_filter($responses, fn($r) => $r['surveyId'] == $sId));
            $sRespIds = array_column($sResponses, 'id');
            $sAnswers = array_values(array_filter($responseAnswers, fn($ra) => in_array($ra['responseId'], $sRespIds, true)));

            jsonResponse(['ok' => true, 'responses' => $sResponses, 'answers' => $sAnswers]);
            break;

        // 一般回答者用: アンケートデータ取得
        case 'get_public_survey':
            $sId = $_GET['id'] ?? null;
            $surveys = JsonStorage::load('surveys.json', []);
            $survey = null;
            foreach ($surveys as $s) {
                if ($s['id'] == $sId) { $survey = $s; break; }
            }
            if (!$survey) {
                jsonResponse(['ok' => false, 'error' => 'アンケートが存在しません。'], 404);
            }
            if ($survey['status'] !== '公開中' && empty($_GET['preview'])) {
                jsonResponse(['ok' => false, 'error' => 'このアンケートは現在受け付けておりません。'], 403);
            }

            $groups = array_values(array_filter(JsonStorage::load('groups.json', []), fn($g) => $g['surveyId'] == $sId));
            $questions = array_values(array_filter(JsonStorage::load('questions.json', []), fn($q) => $q['surveyId'] == $sId));
            $options = array_values(array_filter(JsonStorage::load('options.json', []), fn($o) => $o['surveyId'] == $sId));

            usort($groups, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
            foreach ($groups as &$g) {
                $gId = $g['id'];
                $g['questions'] = array_values(array_filter($questions, fn($q) => $q['groupId'] == $gId));
                usort($g['questions'], fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                foreach ($g['questions'] as &$q) {
                    $qId = $q['id'];
                    $q['options'] = array_values(array_filter($options, fn($o) => $o['questionId'] == $qId));
                    usort($q['options'], fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                }
            }
            $survey['groups'] = $groups;
            jsonResponse(['ok' => true, 'survey' => $survey]);
            break;

        // 一般回答者用: 回答送信
        case 'submit_answer':
            $sId = $input['surveyId'] ?? null;
            $rId = $input['recipientId'] ?? null;
            $answers = $input['answers'] ?? [];

            $surveys = JsonStorage::load('surveys.json', []);
            $survey = null;
            foreach ($surveys as $s) {
                if ($s['id'] == $sId) { $survey = $s; break; }
            }
            if (!$survey || $survey['status'] !== '公開中') {
                jsonResponse(['ok' => false, 'error' => '回答受付期間外です。']);
            }

            $responses = JsonStorage::load('responses.json', []);
            $responseAnswers = JsonStorage::load('response_answers.json', []);

            // 二重回答チェック
            if ($rId) {
                foreach ($responses as $resp) {
                    if ($resp['recipientId'] == $rId) {
                        jsonResponse(['ok' => false, 'error' => '既に回答済みです。']);
                    }
                }
            }

            $respId = 'resp_' . bin2hex(random_bytes(6));
            $responses[] = [
                'id' => $respId,
                'surveyId' => $sId,
                'recipientId' => $rId,
                'answeredAt' => date('Y-m-d H:i:s'),
                'completed' => true
            ];

            foreach ($answers as $qId => $val) {
                $responseAnswers[] = [
                    'id' => 'ra_' . bin2hex(random_bytes(6)),
                    'responseId' => $respId,
                    'questionId' => $qId,
                    'value' => is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string)$val
                ];
            }

            if ($rId) {
                $recipients = JsonStorage::load('recipients.json', []);
                foreach ($recipients as &$rec) {
                    if ($rec['id'] == $rId) {
                        $rec['answered'] = true;
                        break;
                    }
                }
                JsonStorage::save('recipients.json', $recipients);
            }

            JsonStorage::save('responses.json', $responses);
            JsonStorage::save('response_answers.json', $responseAnswers);

            jsonResponse(['ok' => true, 'message' => '回答を受け付けました。ご協力ありがとうございました。']);
            break;
    }
    jsonResponse(['ok' => false, 'error' => '無効なリクエストです。'], 400);
}

// --------------------------------------------------
// HTML フロントエンド出力
// --------------------------------------------------
$respondSurveyId = $_GET['respond'] ?? null;
$recipientId = $_GET['rid'] ?? null;
$isPreview = isset($_GET['preview']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= h(getCsrfToken()) ?>">
  <title>アンケート業務運営アプリ</title>
  <style>
    :root {
      --primary: #2563eb; --primary-hover: #1d4ed8;
      --bg: #f8fafc; --surface: #ffffff; --border: #e2e8f0;
      --text: #1e293b; --text-muted: #64748b;
      --success: #16a34a; --danger: #dc2626; --warning: #d97706;
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
    .sub-nav-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .sub-nav-title { font-weight: 600; font-size: 0.9rem; color: var(--text-muted); }
    .sub-nav-title span { color: var(--text); font-size: 1rem; }
    .sub-nav ul { display: flex; list-style: none; gap: 0.5rem; }
    .sub-nav button { background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 0.35rem 0.75rem; font-size: 0.85rem; cursor: pointer; }
    .sub-nav button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
    main { max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem; flex: 1; width: 100%; }
    .card { background: var(--surface); border-radius: 8px; border: 1px solid var(--border); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; }
    .card-title { font-size: 1.15rem; font-weight: 600; }
    .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.9rem; font-weight: 500; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .btn-primary { background: var(--primary); color: #fff; }
    .btn-primary:hover:not(:disabled) { background: var(--primary-hover); }
    .btn-outline { background: var(--surface); border-color: var(--border); color: var(--text); }
    .btn-outline:hover:not(:disabled) { background: #f8fafc; border-color: #cbd5e1; }
    .btn-danger-outline { border-color: var(--danger); color: var(--danger); background: transparent; }
    .btn-danger-outline:hover:not(:disabled) { background: #fee2e2; }
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
    .group-card { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem; }
    .question-card { background: #ffffff; border: 1px solid var(--border); border-radius: 6px; padding: 1rem; margin-bottom: 0.75rem; }
    .choice-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
    .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #334155; color: #fff; padding: 0.75rem 1.25rem; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000; display: none; }
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 500; }
    .modal { background: #fff; width: 90%; max-width: 600px; border-radius: 8px; padding: 1.5rem; }
  </style>
</head>
<body>

<?php if (!$respondSurveyId): ?>
  <!-- 管理画面ヘッダー -->
  <header id="app-header">
    <div class="header-container">
      <div class="logo">アンケート業務運営アプリ</div>
      <nav>
        <ul>
          <li><button class="active" onclick="navigate('survey-list')">アンケート一覧</button></li>
          <li><button onclick="navigate('survey-editor', null)">アンケート作成</button></li>
          <li><button onclick="navigate('customer-list')">顧客一覧</button></li>
          <li><button onclick="navigate('settings')">設定</button></li>
        </ul>
      </nav>
    </div>
    <div id="sub-nav-bar" class="sub-nav" style="display: none;">
      <div class="sub-nav-container">
        <div class="sub-nav-title">選択中: <span id="current-survey-name"></span></div>
        <ul>
          <li><button class="active" onclick="navigateSub('detail')">アンケート内容</button></li>
          <li><button onclick="navigateSub('send')">送信</button></li>
          <li><button onclick="navigateSub('status')">回答状況</button></li>
          <li><button onclick="navigateSub('result')">回答結果</button></li>
          <li><button onclick="openPreview()">回答プレビュー</button></li>
        </ul>
      </div>
    </div>
  </header>
<?php endif; ?>

  <main id="main-content"></main>
  <div id="toast" class="toast"></div>
  <div id="modal" class="modal-overlay">
    <div class="modal">
      <h3 id="modal-title" style="margin-bottom: 0.75rem;">確認</h3>
      <div id="modal-body" style="font-size: 0.9rem; margin-bottom: 1.25rem;"></div>
      <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
        <button class="btn btn-outline" onclick="closeModal()">キャンセル</button>
        <button id="modal-confirm-btn" class="btn btn-primary">実行</button>
      </div>
    </div>
  </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const RESPOND_SURVEY_ID = <?= json_encode($respondSurveyId) ?>;
  const RECIPIENT_ID = <?= json_encode($recipientId) ?>;
  const IS_PREVIEW = <?= json_encode($isPreview) ?>;

  const state = {
    currentView: 'survey-list',
    selectedSurveyId: null,
    surveys: [],
    customers: [],
    settings: { kintone: {}, smtp: {} }
  };

  async function api(action, params = {}, method = 'POST') {
    const url = `?action=${action}` + (method === 'GET' ? '&' + new URLSearchParams(params).toString() : '');
    const opts = {
      method,
      headers: {
        'X-CSRF-TOKEN': CSRF_TOKEN,
        'Content-Type': 'application/json'
      }
    };
    if (method !== 'GET') {
      opts.body = JSON.stringify(params);
    }
    const res = await fetch(url, opts);
    return await res.json();
  }

  function showToast(msg) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 3000);
  }

  function openModal(title, html, onConfirm) {
    const modal = document.getElementById('modal');
    document.getElementById('modal-title').textContent = title;
    document.getElementById('modal-body').innerHTML = html;
    const btn = document.getElementById('modal-confirm-btn');
    btn.onclick = () => { onConfirm(); closeModal(); };
    modal.style.display = 'flex';
  }

  window.closeModal = function() {
    const modal = document.getElementById('modal');
    if (modal) modal.style.display = 'none';
  };

  // 質問番号計算
  function calculateQuestionNumbers(survey) {
    const map = {};
    let gIdx = 1, totalIdx = 1;
    (survey.groups || []).forEach(g => {
      let qIdx = 1;
      (g.questions || []).forEach(q => {
        map[q.id] = (survey.numberingFormat === 'global') ? `Q${totalIdx}` : `Q${gIdx}-${qIdx}`;
        qIdx++; totalIdx++;
      });
      gIdx++;
    });
    return map;
  }

  window.navigate = function(view, surveyId = null) {
    state.currentView = view;
    if (surveyId !== null) state.selectedSurveyId = surveyId;
    const isSub = ['detail', 'send', 'status', 'result'].includes(view);
    const subNav = document.getElementById('sub-nav-bar');
    if (subNav) {
      subNav.style.display = isSub ? 'block' : 'none';
      if (isSub) {
        const s = state.surveys.find(item => item.id == state.selectedSurveyId);
        document.getElementById('current-survey-name').textContent = s ? s.name : '';
        document.querySelectorAll('#sub-nav-bar button').forEach(b => {
          b.classList.toggle('active', b.getAttribute('onclick')?.includes(view));
        });
      }
    }
    document.querySelectorAll('#app-header nav button').forEach(btn => {
      btn.classList.toggle('active', btn.getAttribute('onclick')?.includes(view));
    });
    render();
  };

  window.navigateSub = function(subView) {
    window.navigate(subView, state.selectedSurveyId);
  };

  window.openPreview = function() {
    window.open(`?respond=${state.selectedSurveyId}&preview=1`, '_blank');
  };

  async function loadData() {
    const res = await api('get_surveys', {}, 'GET');
    if (res.ok) state.surveys = res.surveys;
    const sRes = await api('get_settings', {}, 'GET');
    if (sRes.ok) state.settings = sRes.settings;
  }

  function render() {
    const c = document.getElementById('main-content');
    if (!c) return;
    switch (state.currentView) {
      case 'survey-list': renderSurveyList(c); break;
      case 'survey-editor': renderSurveyEditor(c); break;
      case 'customer-list': renderCustomerList(c); break;
      case 'settings': renderSettings(c); break;
      case 'detail': renderSurveyDetail(c); break;
      case 'send': renderSurveySend(c); break;
      case 'status': renderSurveyStatus(c); break;
      case 'result': renderSurveyResult(c); break;
    }
  }

  // --- 1. アンケート一覧 ---
  function renderSurveyList(el) {
    el.innerHTML = `
      <div class="card">
        <div class="card-header">
          <h2 class="card-title">アンケート一覧</h2>
          <button class="btn btn-primary" onclick="editorDraft=null; navigate('survey-editor', null)">＋ 新規アンケート作成</button>
        </div>
        <table>
          <thead>
            <tr>
              <th>アンケート名</th><th>状態</th><th>公開期間</th><th>回答数</th><th>最終更新日</th><th style="text-align:right;">操作</th>
            </tr>
          </thead>
          <tbody>
            ${state.surveys.length === 0 ? '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);">アンケートがありません</td></tr>' : ''}
            ${state.surveys.map(s => `
              <tr>
                <td><strong><a href="javascript:void(0)" onclick="navigate('detail', '${s.id}')" style="color:var(--primary);text-decoration:none;">${escapeHtml(s.name)}</a></strong></td>
                <td><span class="badge ${s.status === '公開中' ? 'badge-active' : (s.status === '下書き' ? 'badge-draft' : 'badge-closed')}">${escapeHtml(s.status)}</span></td>
                <td>${escapeHtml(s.startDate)} 〜 ${escapeHtml(s.endDate)}</td>
                <td>${s.stats?.answered || 0} 件</td>
                <td>${escapeHtml(s.updatedAt || s.createdAt)}</td>
                <td style="text-align:right;">
                  <button class="btn btn-outline btn-sm" onclick="navigate('detail', '${s.id}')">管理</button>
                  <button class="btn btn-outline btn-sm" onclick="editorDraft=null; navigate('survey-editor', '${s.id}')">編集</button>
                  ${s.status === '下書き' ? `<button class="btn btn-danger-outline btn-sm" onclick="deleteSurvey('${s.id}')">削除</button>` : ''}
                  ${s.status === '下書き' ? `<button class="btn btn-primary btn-sm" onclick="updateStatus('${s.id}', '公開中')">公開</button>` : ''}
                  ${s.status === '公開中' ? `<button class="btn btn-outline btn-sm" onclick="updateStatus('${s.id}', '終了')">終了</button>` : ''}
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  }

  window.updateStatus = async function(id, status) {
    const res = await api('update_survey_status', { id, status });
    if (res.ok) {
      showToast(`ステータスを「${status}」に更新しました`);
      await loadData();
      render();
    }
  };

  window.deleteSurvey = function(id) {
    openModal('削除確認', 'このアンケートを完全に削除しますか？', async () => {
      const res = await api('delete_survey', { id });
      if (res.ok) {
        showToast('アンケートを削除しました');
        await loadData();
        render();
      }
    });
  };

  // --- 2. アンケート編集 ---
  window.editorDraft = null;
  function renderSurveyEditor(el) {
    if (!window.editorDraft) {
      const s = state.surveys.find(item => item.id == state.selectedSurveyId);
      if (s) {
        window.editorDraft = JSON.parse(JSON.stringify(s));
      } else {
        window.editorDraft = {
          name: '', description: '', startDate: new Date().toISOString().split('T')[0],
          endDate: new Date(Date.now() + 30*86400000).toISOString().split('T')[0],
          numberingFormat: 'group',
          groups: [{ id: 'g_1', name: '基本情報', questions: [{ id: 'q_1', text: '', type: 'single', required: true, options: [{ id: 'o_1', text: 'はい', branchTo: '' }, { id: 'o_2', text: 'いいえ', branchTo: '' }] }] }]
        };
      }
    }
    const draft = window.editorDraft;
    const qNumMap = calculateQuestionNumbers(draft);
    const allQ = [];
    (draft.groups || []).forEach(g => (g.questions || []).forEach(q => allQ.push({ id: q.id, label: `${qNumMap[q.id] || ''} ${q.text || '(無題)'}` })));

    el.innerHTML = `
      <div class="card">
        <div class="card-header">
          <h2 class="card-title">${draft.id ? 'アンケート編集' : '新規アンケート作成'}</h2>
          <div style="display:flex;gap:0.5rem;">
            <button class="btn btn-outline" onclick="editorDraft=null;navigate('survey-list')">キャンセル</button>
            <button id="save-btn" class="btn btn-primary" onclick="saveEditor()">保存</button>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:2;">
            <label>アンケート名 <span style="color:var(--danger)">*必須</span></label>
            <input type="text" class="form-control" id="ed-name" value="${escapeHtml(draft.name)}">
          </div>
          <div class="form-group">
            <label>質問番号形式</label>
            <select class="form-control" onchange="editorDraft.numberingFormat=this.value;renderSurveyEditor(document.getElementById('main-content'))">
              <option value="group" ${draft.numberingFormat==='group'?'selected':''}>グループごと (Q1-1, Q2-1...)</option>
              <option value="global" ${draft.numberingFormat==='global'?'selected':''}>全体通番 (Q1, Q2...)</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>説明文</label>
          <textarea class="form-control" id="ed-desc" rows="2">${escapeHtml(draft.description)}</textarea>
        </div>
        <div class="form-row">
          <div class="form-group"><label>公開開始日</label><input type="date" class="form-control" id="ed-start" value="${escapeHtml(draft.startDate)}"></div>
          <div class="form-group"><label>公開終了日</label><input type="date" class="form-control" id="ed-end" value="${escapeHtml(draft.endDate)}"></div>
        </div>
        <hr style="margin:1.5rem 0;border:none;border-top:1px solid var(--border);">
        <h3 style="font-size:1rem;margin-bottom:1rem;">質問グループ構成</h3>
        ${(draft.groups || []).map((g, gIdx) => `
          <div class="group-card">
            <div style="display:flex;justify-content:space-between;margin-bottom:0.75rem;">
              <input type="text" class="form-control" style="font-weight:600;width:300px;" value="${escapeHtml(g.name)}" onchange="editorDraft.groups[${gIdx}].name=this.value">
              <button class="btn btn-danger-outline btn-sm" onclick="editorDraft.groups.splice(${gIdx},1);renderSurveyEditor(document.getElementById('main-content'))">グループ削除</button>
            </div>
            ${(g.questions || []).map((q, qIdx) => `
              <div class="question-card">
                <div style="display:flex;gap:0.5rem;margin-bottom:0.5rem;">
                  <span style="font-weight:bold;padding:0.4rem 0;">${qNumMap[q.id] || ''}</span>
                  <input type="text" class="form-control" placeholder="質問文を入力" value="${escapeHtml(q.text)}" onchange="editorDraft.groups[${gIdx}].questions[${qIdx}].text=this.value">
                  <button class="btn btn-danger-outline btn-sm" onclick="editorDraft.groups[${gIdx}].questions.splice(${qIdx},1);renderSurveyEditor(document.getElementById('main-content'))">削除</button>
                </div>
                <div class="form-row" style="margin-bottom:0.5rem;">
                  <div class="form-group" style="margin:0;"><label>回答形式</label>
                    <select class="form-control" onchange="editorDraft.groups[${gIdx}].questions[${qIdx}].type=this.value;renderSurveyEditor(document.getElementById('main-content'))">
                      <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
                      <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
                      <option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
                    </select>
                  </div>
                  <div class="form-group" style="margin:0;"><label>必須設定</label>
                    <select class="form-control" onchange="editorDraft.groups[${gIdx}].questions[${qIdx}].required=(this.value==='1')">
                      <option value="1" ${q.required?'selected':''}>必須</option>
                      <option value="0" ${!q.required?'selected':''}>任意</option>
                    </select>
                  </div>
                </div>
                ${q.type !== 'text' ? `
                  <div style="background:#f8fafc;padding:0.75rem;border-radius:4px;border:1px solid var(--border);margin-top:0.5rem;">
                    <label style="font-size:0.8rem;font-weight:600;">選択肢および分岐設定</label>
                    ${(q.options || []).map((o, oIdx) => `
                      <div class="choice-row">
                        <input type="text" class="form-control" value="${escapeHtml(o.text)}" onchange="editorDraft.groups[${gIdx}].questions[${qIdx}].options[${oIdx}].text=this.value">
                        ${q.type === 'single' ? `
                          <select class="form-control" style="width:180px;" onchange="editorDraft.groups[${gIdx}].questions[${qIdx}].options[${oIdx}].branchTo=this.value">
                            <option value="">(次へ進む)</option>
                            <option value="__END__" ${o.branchTo==='__END__'?'selected':''}>アンケート終了</option>
                            ${allQ.filter(item=>item.id!==q.id).map(item=>`
                              <option value="${item.id}" ${o.branchTo===item.id?'selected':''}>${escapeHtml(item.label)}へ</option>
                            `).join('')}
                          </select>
                        ` : ''}
                        <button class="btn btn-outline btn-sm" onclick="editorDraft.groups[${gIdx}].questions[${qIdx}].options.splice(${oIdx},1);renderSurveyEditor(document.getElementById('main-content'))">✕</button>
                      </div>
                    `).join('')}
                    <button class="btn btn-outline btn-sm" onclick="(editorDraft.groups[${gIdx}].questions[${qIdx}].options = editorDraft.groups[${gIdx}].questions[${qIdx}].options || []).push({id:'o_'+Date.now(),text:'選択肢',branchTo:''});renderSurveyEditor(document.getElementById('main-content'))">＋ 選択肢追加</button>
                  </div>
                ` : ''}
              </div>
            `).join('')}
            <button class="btn btn-outline btn-sm" onclick="editorDraft.groups[${gIdx}].questions.push({id:'q_'+Date.now(),text:'',type:'single',required:true,options:[{id:'o_1',text:'はい',branchTo:''},{id:'o_2',text:'いいえ',branchTo:''}]});renderSurveyEditor(document.getElementById('main-content'))">＋ 質問を追加</button>
          </div>
        `).join('')}
        <button class="btn btn-outline" style="width:100%;border-style:dashed;padding:0.75rem;" onclick="(editorDraft.groups=editorDraft.groups||[]).push({id:'g_'+Date.now(),name:'新規グループ',questions:[]});renderSurveyEditor(document.getElementById('main-content'))">＋ 新しいグループを追加</button>
      </div>
    `;
  }

  window.saveEditor = async function() {
    const btn = document.getElementById('save-btn');
    if (btn) btn.disabled = true;
    try {
      window.editorDraft.name = document.getElementById('ed-name').value.trim();
      window.editorDraft.description = document.getElementById('ed-desc').value;
      window.editorDraft.startDate = document.getElementById('ed-start').value;
      window.editorDraft.endDate = document.getElementById('ed-end').value;

      if (!window.editorDraft.name) {
        alert('アンケート名は必須です。');
        return;
      }
      const res = await api('save_survey', { survey: window.editorDraft });
      if (res.ok) {
        showToast('アンケートを保存しました');
        window.editorDraft = null;
        await loadData();
        window.navigate('survey-list');
      } else {
        alert(res.error || '保存に失敗しました');
      }
    } finally {
      if (btn) btn.disabled = false;
    }
  };

  // --- 3. アンケート詳細 ---
  function renderSurveyDetail(el) {
    const s = state.surveys.find(item => item.id == state.selectedSurveyId);
    if (!s) return;
    const qNumMap = calculateQuestionNumbers(s);
    el.innerHTML = `
      <div class="card">
        <div class="card-header">
          <div>
            <h2 class="card-title">${escapeHtml(s.name)}</h2>
            <div style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;">公開期間: ${escapeHtml(s.startDate)} 〜 ${escapeHtml(s.endDate)} | 状態: <span class="badge ${s.status==='公開中'?'badge-active':'badge-draft'}">${escapeHtml(s.status)}</span></div>
          </div>
          <button class="btn btn-primary" onclick="editorDraft=null;navigate('survey-editor', '${s.id}')">編集する</button>
        </div>
        <p style="margin-bottom:1.5rem;font-size:0.95rem;">${escapeHtml(s.description || '(説明文なし)')}</p>
        <h3 style="font-size:1rem;margin-bottom:1rem;">質問構成一覧</h3>
        ${(s.groups || []).map(g => `
          <div style="margin-bottom:1.5rem;">
            <h4 style="background:#f1f5f9;padding:0.5rem 0.75rem;border-radius:4px;font-size:0.95rem;margin-bottom:0.75rem;">${escapeHtml(g.name)}</h4>
            <div style="padding-left:1rem;">
              ${(g.questions || []).map(q => `
                <div style="margin-bottom:0.75rem;border-left:2px solid var(--border);padding-left:0.75rem;">
                  <div><strong>${qNumMap[q.id] || ''}</strong> ${escapeHtml(q.text)} ${q.required ? '<span style="color:var(--danger);font-size:0.8rem;">[必須]</span>' : '<span style="color:var(--text-muted);font-size:0.8rem;">[任意]</span>'}</div>
                  <div style="font-size:0.85rem;color:var(--text-muted);margin-top:0.2rem;">形式: ${q.type==='text'?'自由記述':(q.type==='single'?'単一選択':'複数選択')} ${(q.options||[]).length>0?' | 選択肢: '+q.options.map(o=>escapeHtml(o.text)).join(', '):''}</div>
                </div>
              `).join('')}
            </div>
          </div>
        `).join('')}
      </div>
    `;
  }

  // --- 4. メール送信 ---
  let selectedCusts = [];
  function renderSurveySend(el) {
    const s = state.surveys.find(item => item.id == state.selectedSurveyId);
    el.innerHTML = `
      <div class="card">
        <div class="card-header"><h2 class="card-title">メール送信</h2></div>
        <div class="form-group"><label>メール件名</label><input type="text" class="form-control" id="mail-sub" value="【ご協力のお願い】${escapeHtml(s.name)}"></div>
        <div class="form-group"><label>メール本文 ({name}=氏名, {url}=回答URL)</label><textarea class="form-control" id="mail-body" rows="4">{name} 様\n\nアンケートへのご協力をお願いいたします。\n以下のURLよりご回答ください。\n{url}</textarea></div>
        <h3 style="font-size:1rem;margin:1.5rem 0 0.75rem;">送信対象顧客選択</h3>
        <table>
          <thead><tr><th style="width:40px;"><input type="checkbox" onchange="toggleSelectAllCust(this.checked)"></th><th>氏名</th><th>会社名</th><th>メールアドレス</th></tr></thead>
          <tbody>
            ${state.customers.length === 0 ? '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);">顧客データがありません。顧客一覧からkintone同期を行ってください。</td></tr>' : ''}
            ${state.customers.map(c => `
              <tr>
                <td><input type="checkbox" class="cust-chk" value="${c.id}" ${selectedCusts.includes(c.id)?'checked':''} onchange="toggleCust('${c.id}', this.checked)"></td>
                <td>${escapeHtml(c.name)}</td><td>${escapeHtml(c.company)}</td><td>${escapeHtml(c.email)}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
        <div style="margin-top:1.5rem;display:flex;justify-content:flex-end;">
          <button id="send-mail-btn" class="btn btn-primary" onclick="submitSendMail()">送信を実行する</button>
        </div>
      </div>
    `;
  }

  window.toggleSelectAllCust = function(chk) {
    selectedCusts = chk ? state.customers.map(c => c.id) : [];
    document.querySelectorAll('.cust-chk').forEach(el => el.checked = chk);
  };
  window.toggleCust = function(id, chk) {
    if (chk) selectedCusts.push(id);
    else selectedCusts = selectedCusts.filter(x => x !== id);
  };

  window.submitSendMail = async function() {
    if (selectedCusts.length === 0) { alert('対象者を1名以上選択してください。'); return; }
    const btn = document.getElementById('send-mail-btn');
    if (btn) btn.disabled = true;
    try {
      const res = await api('send_survey_mail', {
        surveyId: state.selectedSurveyId,
        customerIds: selectedCusts,
        subject: document.getElementById('mail-sub').value,
        body: document.getElementById('mail-body').value
      });
      if (res.ok) {
        showToast(`送信完了: 成功 ${res.success}件 / 失敗 ${res.failed}件`);
        await loadData();
      } else {
        alert(res.error || '送信失敗');
      }
    } finally {
      if (btn) btn.disabled = false;
    }
  };

  // --- 5. 回答状況 & 6. 回答結果 ---
  async function renderSurveyStatus(el) {
    const s = state.surveys.find(item => item.id == state.selectedSurveyId);
    const sent = s.stats?.sent || 0;
    const answered = s.stats?.answered || 0;
    const rate = sent > 0 ? Math.round((answered / sent) * 100) : 0;
    el.innerHTML = `
      <div class="card">
        <div class="card-header"><h2 class="card-title">回答状況ダッシュボード</h2><span class="badge badge-active">${escapeHtml(s.status)}</span></div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;">
          <div style="background:#f8fafc;padding:1rem;border-radius:6px;text-align:center;"><div style="color:var(--text-muted);font-size:0.85rem;">送信数</div><div style="font-size:1.5rem;font-weight:bold;">${sent}</div></div>
          <div style="background:#f8fafc;padding:1rem;border-radius:6px;text-align:center;"><div style="color:var(--text-muted);font-size:0.85rem;">回答数</div><div style="font-size:1.5rem;font-weight:bold;color:var(--primary);">${answered}</div></div>
          <div style="background:#f8fafc;padding:1rem;border-radius:6px;text-align:center;"><div style="color:var(--text-muted);font-size:0.85rem;">未回答数</div><div style="font-size:1.5rem;font-weight:bold;color:var(--danger);">${Math.max(0, sent - answered)}</div></div>
          <div style="background:#f8fafc;padding:1rem;border-radius:6px;text-align:center;"><div style="color:var(--text-muted);font-size:0.85rem;">回答率</div><div style="font-size:1.5rem;font-weight:bold;color:var(--success);">${rate}%</div></div>
        </div>
      </div>
    `;
  }

  async function renderSurveyResult(el) {
    const s = state.surveys.find(item => item.id == state.selectedSurveyId);
    const qNumMap = calculateQuestionNumbers(s);
    const res = await api('get_survey_results', { surveyId: s.id }, 'GET');
    const answers = res.answers || [];
    el.innerHTML = `
      <div class="card">
        <div class="card-header"><h2 class="card-title">回答結果・集計 (総回答: ${res.responses?.length || 0}件)</h2></div>
        ${(s.groups || []).map(g => `
          <div style="margin-bottom:1.5rem;">
            <h3 style="background:#f1f5f9;padding:0.5rem 0.75rem;border-radius:4px;font-size:1rem;margin-bottom:1rem;">${escapeHtml(g.name)}</h3>
            ${(g.questions || []).map(q => {
              const qAns = answers.filter(a => a.questionId == q.id);
              return `
                <div style="background:#fff;border:1px solid var(--border);padding:1rem;border-radius:6px;margin-bottom:1rem;">
                  <div style="font-weight:600;margin-bottom:0.75rem;">${qNumMap[q.id] || ''} ${escapeHtml(q.text)}</div>
                  ${q.type === 'text' ? `
                    <div style="background:#f8fafc;padding:0.5rem;border-radius:4px;">
                      ${qAns.length === 0 ? '<div style="color:var(--text-muted);">回答なし</div>' : qAns.map(a => `<div style="padding:0.3rem 0;border-bottom:1px solid var(--border);font-size:0.9rem;">・${escapeHtml(a.value)}</div>`).join('')}
                    </div>
                  ` : `
                    <div>
                      ${(q.options || []).map(o => {
                        let count = 0;
                        qAns.forEach(a => {
                          try {
                            const parsed = JSON.parse(a.value);
                            if (Array.isArray(parsed) && parsed.includes(o.text)) count++;
                            else if (parsed === o.text) count++;
                          } catch {
                            if (a.value === o.text) count++;
                          }
                        });
                        const pct = qAns.length > 0 ? Math.round((count / qAns.length) * 100) : 0;
                        return `
                          <div style="margin-bottom:0.5rem;font-size:0.85rem;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:0.2rem;"><span>${escapeHtml(o.text)}</span><span><strong>${count}件</strong> (${pct}%)</span></div>
                            <div style="background:#e2e8f0;height:8px;border-radius:4px;overflow:hidden;"><div style="background:var(--primary);width:${pct}%;height:100%;"></div></div>
                          </div>
                        `;
                      }).join('')}
                    </div>
                  `}
                </div>
              `;
            }).join('')}
          </div>
        `).join('')}
      </div>
    `;
  }

  // --- 7. 顧客一覧 ---
  async function renderCustomerList(el) {
    el.innerHTML = `
      <div class="card">
        <div class="card-header">
          <div><h2 class="card-title">顧客一覧</h2><div style="font-size:0.85rem;color:var(--text-muted);">kintoneから自動同期</div></div>
          <button id="sync-cust-btn" class="btn btn-outline" onclick="syncCustomers()">kintoneと再同期</button>
        </div>
        <table>
          <thead><tr><th>顧客ID</th><th>氏名</th><th>会社名</th><th>メールアドレス</th></tr></thead>
          <tbody>
            ${state.customers.length === 0 ? '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);">顧客が未同期です。</td></tr>' : ''}
            ${state.customers.map(c => `<tr><td>${escapeHtml(c.id)}</td><td><strong>${escapeHtml(c.name)}</strong></td><td>${escapeHtml(c.company)}</td><td>${escapeHtml(c.email)}</td></tr>`).join('')}
          </tbody>
        </table>
      </div>
    `;
  }

  window.syncCustomers = async function() {
    const btn = document.getElementById('sync-cust-btn');
    if (btn) btn.disabled = true;
    try {
      const res = await api('sync_kintone');
      if (res.ok) {
        state.customers = res.customers;
        showToast(`${res.count}件の顧客データを同期しました`);
        renderCustomerList(document.getElementById('main-content'));
      } else {
        alert(res.error || '同期エラー');
      }
    } finally {
      if (btn) btn.disabled = false;
    }
  };

  // --- 8. 設定 ---
  function renderSettings(el) {
    const k = state.settings.kintone || {};
    const sm = state.settings.smtp || {};
    el.innerHTML = `
      <div class="card">
        <div class="card-header"><h2 class="card-title">kintone 連携設定</h2></div>
        <div class="form-row">
          <div class="form-group" style="flex:2;"><label>利用先ホスト (例: example.cybozu.com)</label><input type="text" class="form-control" id="st-k-host" value="${escapeHtml(k.host||'')}"></div>
          <div class="form-group"><label>アプリID</label><input type="text" class="form-control" id="st-k-appid" value="${escapeHtml(k.appId||'')}"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>ログイン名</label><input type="text" class="form-control" id="st-k-login" value="${escapeHtml(k.login||'')}"></div>
          <div class="form-group"><label>パスワード</label><input type="password" class="form-control" id="st-k-pass" value="${escapeHtml(k.pass||'')}"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>プロキシホスト</label><input type="text" class="form-control" id="st-k-pxh" value="${escapeHtml(k.proxyHost||'')}"></div>
          <div class="form-group"><label>プロキシポート</label><input type="text" class="form-control" id="st-k-pxp" value="${escapeHtml(k.proxyPort||'')}"></div>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><h2 class="card-title">SMTP メール送信設定</h2></div>
        <div class="form-row">
          <div class="form-group" style="flex:2;"><label>SMTPサーバー</label><input type="text" class="form-control" id="st-s-host" value="${escapeHtml(sm.host||'')}"></div>
          <div class="form-group"><label>ポート</label><input type="text" class="form-control" id="st-s-port" value="${escapeHtml(sm.port||'587')}"></div>
          <div class="form-group"><label>暗号化</label>
            <select class="form-control" id="st-s-sec">
              <option value="STARTTLS" ${sm.secure==='STARTTLS'?'selected':''}>STARTTLS</option>
              <option value="SSL/TLS" ${sm.secure==='SSL/TLS'?'selected':''}>SSL/TLS</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>認証ユーザー</label><input type="text" class="form-control" id="st-s-user" value="${escapeHtml(sm.user||'')}"></div>
          <div class="form-group"><label>認証パスワード</label><input type="password" class="form-control" id="st-s-pass" value="${escapeHtml(sm.pass||'')}"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>差出人名</label><input type="text" class="form-control" id="st-s-fname" value="${escapeHtml(sm.fromName||'')}"></div>
          <div class="form-group"><label>差出人アドレス</label><input type="text" class="form-control" id="st-s-fmail" value="${escapeHtml(sm.fromEmail||'')}"></div>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:1rem;">
          <button id="save-settings-btn" class="btn btn-primary" onclick="saveSettings()">設定を保存</button>
        </div>
      </div>
    `;
  }

  window.saveSettings = async function() {
    const btn = document.getElementById('save-settings-btn');
    if (btn) btn.disabled = true;
    try {
      const settings = {
        kintone: {
          host: document.getElementById('st-k-host').value,
          appId: document.getElementById('st-k-appid').value,
          login: document.getElementById('st-k-login').value,
          pass: document.getElementById('st-k-pass').value,
          proxyHost: document.getElementById('st-k-pxh').value,
          proxyPort: document.getElementById('st-k-pxp').value,
        },
        smtp: {
          host: document.getElementById('st-s-host').value,
          port: document.getElementById('st-s-port').value,
          secure: document.getElementById('st-s-sec').value,
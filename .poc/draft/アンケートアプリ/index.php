<?php
declare(strict_types=1);

/**
 * アンケートアプリ (POC)
 * 単一エントリーポイント: index.php
 */

// --------------------------------------------------
// 0. 基本設定・エラーハンドリング・セッション
// --------------------------------------------------
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (\Throwable $e) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head><meta charset="UTF-8"><title>システムエラー</title>
    <style>body{font-family:sans-serif;padding:30px;color:#333;background:#f9f9f9;}.box{background:#fff;padding:24px;border:1px solid #ddd;border-radius:6px;max-width:600px;margin:0 auto;}</style>
    </head>
    <body>
      <div class="box">
        <h2 style="color:#d9534f;margin-top:0;">システムエラーが発生しました</h2>
        <p>処理の実行中に予期しないエラーが発生しました。設定や入力内容をご確認ください。</p>
        <p><a href="index.php?screen=list">アンケート一覧へ戻る</a></p>
      </div>
    </body>
    </html>
    <?php
    exit;
});

// セッションCookie設定（POC用: CSRF検証等は行わず、一時データ保持のみに利用）
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// --------------------------------------------------
// 1. 定数・パス定義
// --------------------------------------------------
define('DATA_DIR', __DIR__ . '/data');
define('SECRETS_DIR', __DIR__ . '/.secrets/アンケートアプリ');
define('SECRET_KEY_PATH', SECRETS_DIR . '/secret.key');

define('FILE_SURVEYS', DATA_DIR . '/surveys.json');
define('FILE_ANSWERS', DATA_DIR . '/answers.json');
define('FILE_CUSTOMERS', DATA_DIR . '/customers.json');
define('FILE_SEND_LOGS', DATA_DIR . '/send_logs.json');
define('FILE_KINTONE_CONFIG', DATA_DIR . '/kintone_config.json');
define('FILE_SMTP_CONFIG', DATA_DIR . '/smtp_config.json');

// --------------------------------------------------
// 2. 暗号化・秘密情報管理 (Sodium)
// --------------------------------------------------
class CryptoHelper {
    public static function getKey(): string {
        if (!file_exists(SECRET_KEY_PATH)) {
            if (!is_dir(SECRETS_DIR)) {
                @mkdir(SECRETS_DIR, 0700, true);
            }
            // 鍵が存在しない場合は要件に基づき自動生成・保存（32バイト）
            $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            file_put_contents(SECRET_KEY_PATH, $key);
            @chmod(SECRET_KEY_PATH, 0600);
            return $key;
        }
        $key = file_get_contents(SECRET_KEY_PATH);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('暗号鍵の設定が不正です。');
        }
        return $key;
    }

    public static function encrypt(string $plain): string {
        if ($plain === '') return '';
        $key = self::getKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        return 'ENC:v1:' . base64_encode($nonce) . ':' . base64_encode($cipher);
    }

    public static function decrypt(string $enc): string {
        if ($enc === '') return '';
        if (!str_starts_with($enc, 'ENC:v1:')) {
            return '';
        }
        $parts = explode(':', $enc);
        if (count($parts) !== 4) return '';
        $nonce = base64_decode($parts[2], true);
        $cipher = base64_decode($parts[3], true);
        if ($nonce === false || $cipher === false) return '';
        $key = self::getKey();
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        return $plain === false ? '' : $plain;
    }
}

// --------------------------------------------------
// 3. データ永続化ヘルパー (ファイルロック付きJSON)
// --------------------------------------------------
class Storage {
    public static function init(): void {
        if (!is_dir(DATA_DIR)) {
            @mkdir(DATA_DIR, 0700, true);
        }
        // .htaccessで直接ダウンロード防止
        $htaccess = DATA_DIR . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
        // 初期ファイル作成
        $files = [
            FILE_SURVEYS => [],
            FILE_ANSWERS => [],
            FILE_CUSTOMERS => [],
            FILE_SEND_LOGS => [],
            FILE_KINTONE_CONFIG => [
                'subdomain' => '',
                'app_id' => '',
                'login_name' => '',
                'proxy' => '',
                'verify_ssl' => false,
                'password_enc' => '',
                'mapping' => [
                    'org' => '',
                    'name' => '',
                    'email' => '',
                    'dept' => '',
                    'phone' => '',
                    'address' => []
                ],
                'status' => '未設定'
            ],
            FILE_SMTP_CONFIG => [
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'auth' => true,
                'username' => '',
                'from_email' => '',
                'from_name' => '',
                'reply_to' => '',
                'password_enc' => '',
                'status' => '未設定'
            ]
        ];
        foreach ($files as $file => $default) {
            if (!file_exists($file)) {
                self::save($file, $default);
            }
        }
    }

    public static function load(string $path, mixed $default = []) {
        if (!file_exists($path)) return $default;
        $fp = fopen($path, 'r');
        if (!$fp) return $default;
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode($content, true);
        return $data !== null ? $data : $default;
    }

    public static function save(string $path, mixed $data): bool {
        if (!is_dir(DATA_DIR)) {
            @mkdir(DATA_DIR, 0700, true);
        }
        $fp = fopen($path, 'c+');
        if (!$fp) return false;
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
}
Storage::init();

// --------------------------------------------------
// 4. アンケート状態・採番更新ロジック
// --------------------------------------------------
function updateSurveyStatusAndNumbers(array &$survey): void {
    $now = date('Y-m-d H:i:s');
    // 状態自動更新: 公開中のアンケートが終了日時を経過した場合のみ自動で終了
    if (($survey['status'] ?? '') === '公開中' && !empty($survey['end_datetime'])) {
        if ($now > $survey['end_datetime']) {
            $survey['status'] = '終了';
        }
    }
    // 質問番号の自動採番再計算
    $numbering = $survey['numbering_type'] ?? 'overall'; // 'overall' or 'group'
    $overallIndex = 1;
    $groups = $survey['groups'] ?? [];
    foreach ($groups as $gIdx => &$group) {
        $gNum = $gIdx + 1;
        $qInGroupIndex = 1;
        foreach ($group['questions'] as &$question) {
            if ($numbering === 'overall') {
                $question['number'] = 'Q' . $overallIndex;
            } else {
                $question['number'] = 'Q' . $gNum . '-' . $qInGroupIndex;
            }
            $overallIndex++;
            $qInGroupIndex++;
        }
    }
    $survey['groups'] = $groups;
}

function refreshAllSurveys(): array {
    $surveys = Storage::load(FILE_SURVEYS, []);
    $changed = false;
    foreach ($surveys as &$s) {
        $oldStatus = $s['status'] ?? '';
        updateSurveyStatusAndNumbers($s);
        if ($oldStatus !== ($s['status'] ?? '')) {
            $changed = true;
        }
    }
    if ($changed) {
        Storage::save(FILE_SURVEYS, $surveys);
    }
    return $surveys;
}

// --------------------------------------------------
// 5. 外部通信: kintone REST API (cURL不使用: stream_context)
// --------------------------------------------------
class KintoneClient {
    public static function request(string $subdomain, string $endpoint, string $login, string $password, array $params = [], string $proxy = '', bool $verifySsl = false): array {
        // サブドメインの整形
        $sub = trim($subdomain);
        $sub = preg_replace('#^https?://#', '', $sub);
        $sub = explode('.', $sub)[0];
        $sub = trim($sub, '/');
        if ($sub === '') {
            return ['ok' => false, 'error' => 'サブドメインが不正です。'];
        }

        $host = "{$sub}.cybozu.com";
        $url = "https://{$host}{$endpoint}";
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $authHeader = 'X-Cybozu-Authorization: ' . base64_encode("{$login}:{$password}");
        $headers = [
            $authHeader,
            'User-Agent: SurveyApp-POC/1.0',
            'Accept: application/json'
        ];

        $contextOptions = [
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 15,
                'ignore_errors' => true,
                'follow_location' => 0 // 302/303を成功扱い・自動追随しない
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl
            ]
        ];

        if (!empty($proxy)) {
            $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
            $contextOptions['http']['request_fulluri'] = true;
        }

        $context = stream_context_create($contextOptions);
        $fp = @fopen($url, 'r', false, $context);
        if (!$fp) {
            $lastErr = error_get_last();
            return ['ok' => false, 'error' => 'kintone接続に失敗しました。(通信エラーまたはタイムアウト: ' . ($lastErr['message'] ?? '') . ')'];
        }

        $meta = stream_get_meta_data($fp);
        $responseBody = stream_get_contents($fp);
        fclose($fp);

        // レスポンスヘッダからHTTPコード取得
        $httpCode = 0;
        if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
            foreach ($meta['wrapper_data'] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#i', $line, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }

        if ($httpCode === 301 || $httpCode === 302 || $httpCode === 303 || $httpCode === 307) {
            return ['ok' => false, 'error' => "kintoneからリダイレクト応答({$httpCode})が返されました。URLや設定を確認してください。"];
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return ['ok' => false, 'error' => "kintone認証エラー({$httpCode}): ログイン名またはパスワードが正しくありません。"];
        }

        $json = json_decode($responseBody, true);
        if ($httpCode !== 200) {
            $msg = $json['message'] ?? 'kintone APIエラー';
            $code = $json['code'] ?? (string)$httpCode;
            return ['ok' => false, 'error' => "[{$code}] {$msg}"];
        }

        return ['ok' => true, 'data' => $json];
    }
}

// --------------------------------------------------
// 6. 外部通信: SMTP 送信 (PHP mail()・cURL不使用: fsockopen/TLS)
// --------------------------------------------------
class SmtpClient {
    public static function sendMail(array $config, string $password, string $toEmail, string $subject, string $body): array {
        $host = $config['host'] ?? '';
        $port = (int)($config['port'] ?? 587);
        $enc  = $config['encryption'] ?? 'tls'; // ssl, tls, none
        $user = $config['username'] ?? '';
        $from = $config['from_email'] ?? '';
        $fromName = $config['from_name'] ?? '';
        $replyTo = $config['reply_to'] ?? '';

        if (empty($host) || empty($from) || empty($toEmail)) {
            return ['ok' => false, 'error' => 'SMTP設定または宛先が不足しています。'];
        }

        $socketHost = ($enc === 'ssl') ? 'ssl://' . $host : $host;
        $timeout = 15;
        $fp = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            return ['ok' => false, 'error' => "SMTPサーバへの接続に失敗しました: {$errstr} ({$errno})"];
        }
        stream_set_timeout($fp, $timeout);

        $read = function() use ($fp) {
            $data = '';
            while ($str = fgets($fp, 515)) {
                $data .= $str;
                if (substr($str, 3, 1) === ' ') break;
            }
            return $data;
        };

        $write = function(string $cmd) use ($fp) {
            fputs($fp, $cmd . "\r\n");
        };

        $res = $read();
        if (!str_starts_with($res, '220')) {
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTPサーバ応答エラー: ' . trim($res)];
        }

        $clientHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $write("EHLO {$clientHost}");
        $res = $read();

        // STARTTLS
        if ($enc === 'tls') {
            $write("STARTTLS");
            $res = $read();
            if (!str_starts_with($res, '220')) {
                fclose($fp);
                return ['ok' => false, 'error' => 'STARTTLS失敗: ' . trim($res)];
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return ['ok' => false, 'error' => 'TLS暗号化接続の確立に失敗しました。'];
            }
            $write("EHLO {$clientHost}");
            $res = $read();
        }

        // AUTH
        if (!empty($config['auth'])) {
            $write("AUTH LOGIN");
            $res = $read();
            if (!str_starts_with($res, '334')) {
                fclose($fp);
                return ['ok' => false, 'error' => 'AUTH LOGIN 要求失敗: ' . trim($res)];
            }
            $write(base64_encode($user));
            $res = $read();
            if (!str_starts_with($res, '334')) {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP ユーザー名送信失敗: ' . trim($res)];
            }
            $write(base64_encode($password));
            $res = $read();
            if (!str_starts_with($res, '235')) {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP 認証失敗(パスワード不一致等): ' . trim($res)];
            }
        }

        // MAIL FROM / RCPT TO
        $write("MAIL FROM:<{$from}>");
        $res = $read();
        if (!str_starts_with($res, '250')) {
            fclose($fp);
            return ['ok' => false, 'error' => 'MAIL FROM エラー: ' . trim($res)];
        }

        $write("RCPT TO:<{$toEmail}>");
        $res = $read();
        if (!str_starts_with($res, '250') && !str_starts_with($res, '251')) {
            fclose($fp);
            return ['ok' => false, 'error' => 'RCPT TO エラー: ' . trim($res)];
        }

        // DATA
        $write("DATA");
        $res = $read();
        if (!str_starts_with($res, '354')) {
            fclose($fp);
            return ['ok' => false, 'error' => 'DATA コマンドエラー: ' . trim($res)];
        }

        $headers = [];
        $headers[] = 'From: ' . ($fromName ? '=?UTF-8?B?' . base64_encode($fromName) . "?= <{$from}>" : $from);
        $headers[] = "To: <{$toEmail}>";
        if (!empty($replyTo)) {
            $headers[] = "Reply-To: <{$replyTo}>";
        }
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $headers[] = 'Date: ' . date('r');

        $mailData = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
        $write($mailData . "\r\n.");
        $res = $read();
        if (!str_starts_with($res, '250')) {
            fclose($fp);
            return ['ok' => false, 'error' => 'メッセージ送信失敗: ' . trim($res)];
        }

        $write("QUIT");
        $read();
        fclose($fp);
        return ['ok' => true];
    }
}

// --------------------------------------------------
// 7. リクエストルーティング・POST処理
// --------------------------------------------------
$screen = $_GET['screen'] ?? 'list';
$action = $_POST['action'] ?? '';
$id = $_GET['id'] ?? ($_POST['id'] ?? '');

$flashMessage = '';
$flashError = '';

// 全アンケートの読み込みと自動更新
$surveys = refreshAllSurveys();

// --- POSTハンドリング ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. アンケート保存 (新規・編集)
    if ($action === 'save_survey') {
        $surveyId = trim($_POST['survey_id'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $startDt = trim($_POST['start_datetime'] ?? '');
        $endDt = trim($_POST['end_datetime'] ?? '');
        $numbering = trim($_POST['numbering_type'] ?? 'overall');
        $status = trim($_POST['status'] ?? '下書き');
        $groupsJson = $_POST['groups_json'] ?? '[]';
        $groups = json_decode($groupsJson, true) ?: [];

        if ($title === '') {
            $flashError = 'タイトルを入力してください。';
        } else {
            $isNew = ($surveyId === '' || !isset($surveys[$surveyId]));
            if ($isNew) {
                $surveyId = 'survey_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
                $targetSurvey = [
                    'id' => $surveyId,
                    'created_at' => date('Y-m-d H:i:s'),
                    'status' => '下書き'
                ];
            } else {
                $targetSurvey = $surveys[$surveyId];
                // 手動変更許可遷移チェック
                $allowed = ['下書き' => ['公開中', '下書き'], '公開中' => ['停止', '公開中'], '停止' => ['公開中', '停止']];
                $cur = $targetSurvey['status'];
                if ($cur !== '終了' && isset($allowed[$cur]) && in_array($status, $allowed[$cur], true)) {
                    $targetSurvey['status'] = $status;
                }
            }

            $targetSurvey['title'] = $title;
            $targetSurvey['description'] = $desc;
            $targetSurvey['start_datetime'] = $startDt;
            $targetSurvey['end_datetime'] = $endDt;
            $targetSurvey['numbering_type'] = $numbering;
            $targetSurvey['groups'] = $groups;
            $targetSurvey['updated_at'] = date('Y-m-d H:i:s');

            updateSurveyStatusAndNumbers($targetSurvey);
            $surveys[$surveyId] = $targetSurvey;
            Storage::save(FILE_SURVEYS, $surveys);

            // 保存後は一覧画面へ
            header('Location: index.php?screen=list&saved=1');
            exit;
        }
    }

    // 2. アンケート複製
    if ($action === 'duplicate_survey') {
        $targetId = $_POST['target_id'] ?? '';
        if (isset($surveys[$targetId])) {
            $dup = $surveys[$targetId];
            $newId = 'survey_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
            $dup['id'] = $newId;
            $dup['title'] = $dup['title'] . ' (コピー)';
            $dup['status'] = '下書き';
            $dup['created_at'] = date('Y-m-d H:i:s');
            $dup['updated_at'] = date('Y-m-d H:i:s');
            updateSurveyStatusAndNumbers($dup);
            $surveys[$newId] = $dup;
            Storage::save(FILE_SURVEYS, $surveys);
            header('Location: index.php?screen=list&duplicated=1');
            exit;
        }
    }

    // 3. アンケート削除
    if ($action === 'delete_survey') {
        $targetId = $_POST['target_id'] ?? '';
        if (isset($surveys[$targetId])) {
            unset($surveys[$targetId]);
            Storage::save(FILE_SURVEYS, $surveys);
            header('Location: index.php?screen=list&deleted=1');
            exit;
        }
    }

    // 4. kintone設定保存・接続テスト・項目取得・顧客同期
    if (in_array($action, ['save_kintone', 'test_kintone', 'get_kintone_fields', 'sync_kintone'], true)) {
        $kConfig = Storage::load(FILE_KINTONE_CONFIG, []);
        $subdomain = trim($_POST['subdomain'] ?? '');
        $appId = trim($_POST['app_id'] ?? '');
        $loginName = trim($_POST['login_name'] ?? '');
        $proxy = trim($_POST['proxy'] ?? '');
        $verifySsl = !empty($_POST['verify_ssl']);
        $inputPassword = $_POST['password'] ?? '';

        $kConfig['subdomain'] = $subdomain;
        $kConfig['app_id'] = $appId;
        $kConfig['login_name'] = $loginName;
        $kConfig['proxy'] = $proxy;
        $kConfig['verify_ssl'] = $verifySsl;

        // パスワードの更新がある場合のみ暗号化して保存
        if ($inputPassword !== '') {
            $kConfig['password_enc'] = CryptoHelper::encrypt($inputPassword);
            $activePassword = $inputPassword;
        } else {
            $activePassword = CryptoHelper::decrypt($kConfig['password_enc'] ?? '');
        }

        if (isset($_POST['mapping']) && is_array($_POST['mapping'])) {
            $kConfig['mapping'] = [
                'org' => trim($_POST['mapping']['org'] ?? ''),
                'name' => trim($_POST['mapping']['name'] ?? ''),
                'email' => trim($_POST['mapping']['email'] ?? ''),
                'dept' => trim($_POST['mapping']['dept'] ?? ''),
                'phone' => trim($_POST['mapping']['phone'] ?? ''),
                'address' => array_filter(array_map('trim', (array)($_POST['mapping']['address'] ?? [])))
            ];
        }

        if ($action === 'save_kintone') {
            Storage::save(FILE_KINTONE_CONFIG, $kConfig);
            $flashMessage = 'kintone設定を保存しました。';
        } elseif ($action === 'test_kintone') {
            if ($activePassword === '') {
                $flashError = '接続テストにはパスワードの入力が必要です。';
            } else {
                $res = KintoneClient::request($subdomain, '/k/v1/app.json', $loginName, $activePassword, ['id' => $appId], $proxy, $verifySsl);
                if ($res['ok']) {
                    $kConfig['status'] = '接続確認済み';
                    Storage::save(FILE_KINTONE_CONFIG, $kConfig);
                    $flashMessage = 'kintoneへの接続テストに成功しました。(アプリ名: ' . htmlspecialchars($res['data']['name'] ?? '') . ')';
                } else {
                    $kConfig['status'] = '接続できません';
                    Storage::save(FILE_KINTONE_CONFIG, $kConfig);
                    $flashError = 'kintone接続テスト失敗: ' . $res['error'];
                }
            }
        } elseif ($action === 'get_kintone_fields') {
            if ($activePassword === '') {
                $flashError = '項目一覧取得にはパスワードの入力が必要です。';
            } else {
                $res = KintoneClient::request($subdomain, '/k/v1/app/form/fields.json', $loginName, $activePassword, ['app' => $appId], $proxy, $verifySsl);
                if ($res['ok']) {
                    $_SESSION['kintone_fields_cache'] = array_keys($res['data']['properties'] ?? []);
                    Storage::save(FILE_KINTONE_CONFIG, $kConfig);
                    $flashMessage = 'kintoneの項目一覧を取得しました。';
                } else {
                    $flashError = '項目一覧取得失敗: ' . $res['error'];
                }
            }
        } elseif ($action === 'sync_kintone') {
            if ($activePassword === '') {
                $flashError = '顧客同期にはパスワードの入力が必要です。';
            } else {
                $res = KintoneClient::request($subdomain, '/k/v1/records.json', $loginName, $activePassword, ['app' => $appId], $proxy, $verifySsl);
                if ($res['ok']) {
                    $records = $res['data']['records'] ?? [];
                    $customers = [];
                    $map = $kConfig['mapping'];
                    foreach ($records as $r) {
                        $cId = (string)($r['$id']['value'] ?? bin2hex(random_bytes(4)));
                        $org = $map['org'] ? ($r[$map['org']]['value'] ?? '') : '';
                        $name = $map['name'] ? ($r[$map['name']]['value'] ?? '') : '';
                        $email = $map['email'] ? ($r[$map['email']]['value'] ?? '') : '';
                        $dept = $map['dept'] ? ($r[$map['dept']]['value'] ?? '') : '';
                        $phone = $map['phone'] ? ($r[$map['phone']]['value'] ?? '') : '';
                        
                        $addrParts = [];
                        foreach ($map['address'] as $af) {
                            if (!empty($af) && !empty($r[$af]['value'])) {
                                $addrParts[] = $r[$af]['value'];
                            }
                        }
                        $address = implode(' ', $addrParts);

                        if ($email !== '' || $name !== '') {
                            $customers[$cId] = [
                                'id' => $cId,
                                'org' => $org,
                                'name' => $name,
                                'email' => $email,
                                'dept' => $dept,
                                'phone' => $phone,
                                'address' => $address,
                                'synced_at' => date('Y-m-d H:i:s')
                            ];
                        }
                    }
                    Storage::save(FILE_CUSTOMERS, $customers);
                    Storage::save(FILE_KINTONE_CONFIG, $kConfig);
                    $flashMessage = 'kintoneから ' . count($customers) . ' 件の顧客情報を同期しました。';
                } else {
                    $flashError = '顧客同期失敗: ' . $res['error'];
                }
            }
        }
    }

    // 5. SMTP設定保存・接続テスト・テストメール
    if (in_array($action, ['save_smtp', 'test_smtp', 'send_test_mail'], true)) {
        $sConfig = Storage::load(FILE_SMTP_CONFIG, []);
        $host = trim($_POST['host'] ?? '');
        $port = (int)($_POST['port'] ?? 587);
        $enc = trim($_POST['encryption'] ?? 'tls');
        $auth = !empty($_POST['auth']);
        $username = trim($_POST['username'] ?? '');
        $fromEmail = trim($_POST['from_email'] ?? '');
        $fromName = trim($_POST['from_name'] ?? '');
        $replyTo = trim($_POST['reply_to'] ?? '');
        $inputPassword = $_POST['password'] ?? '';
        $testTo = trim($_POST['test_to_email'] ?? '');

        $sConfig['host'] = $host;
        $sConfig['port'] = $port;
        $sConfig['encryption'] = $enc;
        $sConfig['auth'] = $auth;
        $sConfig['username'] = $username;
        $sConfig['from_email'] = $fromEmail;
        $sConfig['from_name'] = $fromName;
        $sConfig['reply_to'] = $replyTo;

        if ($inputPassword !== '') {
            $sConfig['password_enc'] = CryptoHelper::encrypt($inputPassword);
            $activePassword = $inputPassword;
        } else {
            $activePassword = CryptoHelper::decrypt($sConfig['password_enc'] ?? '');
        }

        if ($action === 'save_smtp') {
            Storage::save(FILE_SMTP_CONFIG, $sConfig);
            $flashMessage = 'SMTP設定を保存しました。';
        } elseif ($action === 'test_smtp') {
            if ($auth && $activePassword === '') {
                $flashError = '接続テストにはSMTPパスワードの入力が必要です。';
            } else {
                $res = SmtpClient::sendMail($sConfig, $activePassword, $fromEmail, 'SMTP Connection Test', 'This is a test connection.');
                if ($res['ok']) {
                    $sConfig['status'] = '接続確認済み';
                    Storage::save(FILE_SMTP_CONFIG, $sConfig);
                    $flashMessage = 'SMTPサーバへの接続および認証に成功しました。';
                } else {
                    $sConfig['status'] = '接続できません';
                    Storage::save(FILE_SMTP_CONFIG, $sConfig);
                    $flashError = 'SMTP接続テスト失敗: ' . $res['error'];
                }
            }
        } elseif ($action === 'send_test_mail') {
            if (empty($testTo)) {
                $flashError = 'テストメール送信先アドレスを入力してください。';
            } elseif ($auth && $activePassword === '') {
                $flashError = 'テストメール送信にはSMTPパスワードの入力が必要です。';
            } else {
                $res = SmtpClient::sendMail($sConfig, $activePassword, $testTo, '【テスト送信】アンケートアプリ SMTP疎通確認', "本メールはアンケートアプリのSMTP接続テストです。\r\n正常に送信されました。");
                if ($res['ok']) {
                    $sConfig['status'] = '接続確認済み';
                    Storage::save(FILE_SMTP_CONFIG, $sConfig);
                    $flashMessage = "テストメールを {$testTo} へ送信しました。";
                } else {
                    $sConfig['status'] = '接続できません';
                    Storage::save(FILE_SMTP_CONFIG, $sConfig);
                    $flashError = 'テストメール送信失敗: ' . $res['error'];
                }
            }
        }
    }

    // 6. メール送信実行 (一括・再送)
    if ($action === 'send_survey_mail') {
        $surveyId = trim($_POST['survey_id'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $bodyTemplate = trim($_POST['body'] ?? '');
        $selectedCustomerIds = (array)($_POST['selected_customers'] ?? []);

        if (!isset($surveys[$surveyId])) {
            $flashError = 'アンケートが見つかりません。';
        } elseif (empty($selectedCustomerIds)) {
            $flashError = '送信先顧客が選択されていません。';
        } else {
            $sConfig = Storage::load(FILE_SMTP_CONFIG, []);
            $smtpPass = CryptoHelper::decrypt($sConfig['password_enc'] ?? '');
            $customers = Storage::load(FILE_CUSTOMERS, []);
            $sendLogs = Storage::load(FILE_SEND_LOGS, []);

            $baseAppUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['PHP_SELF'];

            $successCount = 0;
            $failCount = 0;

            foreach ($selectedCustomerIds as $cId) {
                if (!isset($customers[$cId])) continue;
                $c = $customers[$cId];
                $toEmail = $c['email'];
                if (empty($toEmail)) continue;

                $surveyUrl = "{$baseAppUrl}?screen=answer&id={$surveyId}&customer_id=" . urlencode($cId);
                $body = str_replace(['{顧客名}', '{アンケートURL}'], [$c['name'], $surveyUrl], $bodyTemplate);

                $res = SmtpClient::sendMail($sConfig, $smtpPass, $toEmail, $subject, $body);

                $logEntry = [
                    'survey_id' => $surveyId,
                    'customer_id' => $cId,
                    'customer_name' => $c['name'],
                    'email' => $toEmail,
                    'sent_at' => date('Y-m-d H:i:s'),
                    'status' => $res['ok'] ? '成功' : '失敗',
                    'error' => $res['ok'] ? '' : $res['error']
                ];
                $sendLogs[] = $logEntry;

                if ($res['ok']) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }
            Storage::save(FILE_SEND_LOGS, $sendLogs);
            $flashMessage = "メール送信処理が完了しました。(成功: {$successCount} 件, 失敗: {$failCount} 件)";
        }
    }

    // 7. 回答者フロー: 回答確認画面へ進む
    if ($action === 'submit_answer_confirm') {
        $surveyId = trim($_POST['survey_id'] ?? '');
        $customerId = trim($_POST['customer_id'] ?? '');
        $answers = (array)($_POST['answers'] ?? []);

        if (!isset($surveys[$surveyId])) {
            $flashError = '対象アンケートが存在しません。';
        } else {
            $survey = $surveys[$surveyId];
            // 必須チェック等
            $missingRequired = false;
            foreach ($survey['groups'] as $grp) {
                foreach ($grp['questions'] as $q) {
                    $qid = $q['id'];
                    $val = $answers[$qid] ?? '';
                    if (!empty($q['required'])) {
                        if (is_array($val) && empty($val)) {
                            $missingRequired = true;
                        } elseif (!is_array($val) && trim((string)$val) === '') {
                            $missingRequired = true;
                        }
                    }
                }
            }
            if ($missingRequired) {
                $flashError = '必須項目にすべてご回答ください。';
            } else {
                $_SESSION['answer_temp'] = [
                    'survey_id' => $surveyId,
                    'customer_id' => $customerId,
                    'answers' => $answers
                ];
                header("Location: index.php?screen=confirm&id={$surveyId}&customer_id=" . urlencode($customerId));
                exit;
            }
        }
    }

    // 8. 回答者フロー: 最終送信
    if ($action === 'submit_answer_final') {
        $temp = $_SESSION['answer_temp'] ?? null;
        if (!$temp || empty($temp['survey_id'])) {
            $flashError = '回答データが見つかりません。最初からやり直してください。';
        } else {
            $answersData = Storage::load(FILE_ANSWERS, []);
            $answerId = 'ans_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
            $answersData[] = [
                'id' => $answerId,
                'survey_id' => $temp['survey_id'],
                'customer_id' => $temp['customer_id'],
                'answers' => $temp['answers'],
                'created_at' => date('Y-m-d H:i:s')
            ];
            Storage::save(FILE_ANSWERS, $answersData);
            unset($_SESSION['answer_temp']);
            header("Location: index.php?screen=complete&id=" . urlencode($temp['survey_id']));
            exit;
        }
    }
}

// --------------------------------------------------
// 8. CSVエクスポート処理 (回答集計画面)
// --------------------------------------------------
if ($screen === 'export_csv' && !empty($id) && isset($surveys[$id])) {
    $survey = $surveys[$id];
    $answersData = Storage::load(FILE_ANSWERS, []);
    $surveyAnswers = array_filter($answersData, fn($a) => ($a['survey_id'] ?? '') === $id);

    $questions = [];
    foreach ($survey['groups'] as $grp) {
        foreach ($grp['questions'] as $q) {
            $questions[] = $q;
        }
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="survey_results_' . $id . '.csv"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");

    $headers = ['回答日時', '顧客ID'];
    foreach ($questions as $q) {
        $headers[] = ($q['number'] ?? '') . ' ' . ($q['text'] ?? '');
    }
    fputcsv($out, $headers);

    foreach ($surveyAnswers as $ans) {
        $row = [
            $ans['created_at'] ?? '',
            $ans['customer_id'] ?? '未登録回答'
        ];
        foreach ($questions as $q) {
            $val = $ans['answers'][$q['id']] ?? '';
            if (is_array($val)) {
                $row[] = implode(', ', $val);
            } else {
                $row[] = (string)$val;
            }
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// --------------------------------------------------
// 9. HTML共通ヘッダー・CSS
// --------------------------------------------------
$isAnswerScreen = in_array($screen, ['answer', 'confirm', 'complete'], true);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム</title>
<style>
:root {
  --primary: #1976d2;
  --primary-hover: #1565c0;
  --bg-color: #f4f6f8;
  --card-bg: #ffffff;
  --text: #333333;
  --border: #dcdfe6;
  --success: #28a745;
  --danger: #dc3545;
  --warning: #ffc107;
  --info: #17a2b8;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: var(--bg-color); color: var(--text); line-height: 1.6; }
a { color: var(--primary); text-decoration: none; }
a:hover { text-decoration: underline; }
header { background: #fff; border-bottom: 1px solid var(--border); padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; }
.header-title { font-size: 1.25rem; font-weight: bold; color: var(--primary); }
nav a { margin-left: 16px; font-size: 0.95rem; color: #555; text-decoration: none; }
nav a.active { font-weight: bold; color: var(--primary); border-bottom: 2px solid var(--primary); padding-bottom: 4px; }
.container { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
.card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 6px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.03); }
.card-header { font-size: 1.15rem; font-weight: bold; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.btn { display: inline-block; padding: 8px 16px; border-radius: 4px; border: 1px solid transparent; font-size: 0.9rem; cursor: pointer; text-align: center; text-decoration: none !important; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-hover); }
.btn-secondary { background: #e0e0e0; color: #333; }
.btn-secondary:hover { background: #d5d5d5; }
.btn-danger { background: var(--danger); color: #fff; }
.btn-danger:hover { background: #bd2130; }
.btn-sm { padding: 4px 8px; font-size: 0.8rem; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9rem; }
.form-control { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 4px; font-size: 0.95rem; }
.form-control:focus { outline: none; border-color: var(--primary); }
.badge { display: inline-block; padding: 3px 8px; font-size: 0.75rem; border-radius: 12px; font-weight: bold; }
.badge-draft { background: #6c757d; color: #fff; }
.badge-public { background: var(--success); color: #fff; }
.badge-stopped { background: var(--warning); color: #212529; }
.badge-closed { background: #343a40; color: #fff; }
.alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; font-size: 0.95rem; }
.alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.table-responsive { width: 100%; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
th { background: #fafafa; font-weight: 600; }
.answer-box { max-width: 680px; margin: 30px auto; }
.q-block { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.q-title { font-weight: bold; font-size: 1.05rem; margin-bottom: 12px; }
.q-req { color: var(--danger); font-size: 0.8rem; margin-left: 6px; }
.opt-label { display: block; margin-bottom: 8px; font-size: 0.95rem; cursor: pointer; }
.opt-label input { margin-right: 8px; }
</style>
</head>
<body>

<?php if (!$isAnswerScreen): ?>
<header>
  <div class="header-title">アンケート管理システム (POC)</div>
  <nav>
    <a href="index.php?screen=list" class="<?= $screen === 'list' ? 'active' : '' ?>">アンケート一覧</a>
    <a href="index.php?screen=kintone" class="<?= $screen === 'kintone' ? 'active' : '' ?>">kintone設定</a>
    <a href="index.php?screen=mail" class="<?= $screen === 'mail' ? 'active' : '' ?>">メール設定</a>
  </nav>
</header>
<?php endif; ?>

<div class="container <?= $isAnswerScreen ? 'answer-box' : '' ?>">

<?php if ($flashMessage || isset($_GET['saved']) || isset($_GET['duplicated']) || isset($_GET['deleted'])): ?>
  <div class="alert alert-success">
    <?= htmlspecialchars($flashMessage ?: '処理が正常に完了しました。') ?>
  </div>
<?php endif; ?>

<?php if ($flashError): ?>
  <div class="alert alert-danger">
    <?= htmlspecialchars($flashError) ?>
  </div>
<?php endif; ?>

<?php
// --------------------------------------------------
// 10. 画面別レンダリング
// --------------------------------------------------

// ==========================================
// A. アンケート一覧 (screen=list)
// ==========================================
if ($screen === 'list'):
    $answersData = Storage::load(FILE_ANSWERS, []);
    $searchQ = trim($_GET['q'] ?? '');
    $filterStatus = $_GET['status'] ?? 'all';
    $sort = $_GET['sort'] ?? 'updated_desc';

    $listSurveys = array_values($surveys);

    // 検索
    if ($searchQ !== '') {
        $listSurveys = array_filter($listSurveys, fn($s) => mb_stripos($s['title'], $searchQ) !== false);
    }
    // 絞り込み
    if ($filterStatus !== 'all') {
        $listSurveys = array_filter($listSurveys, fn($s) => ($s['status'] ?? '') === $filterStatus);
    }
    // ソート
    usort($listSurveys, function($a, $b) use ($sort, $answersData) {
        $aAns = count(array_filter($answersData, fn($x) => ($x['survey_id'] ?? '') === $a['id']));
        $bAns = count(array_filter($answersData, fn($x) => ($x['survey_id'] ?? '') === $b['id']));
        return match($sort) {
            'updated_asc' => strcmp($a['updated_at'] ?? '', $b['updated_at'] ?? ''),
            'ans_desc' => $bAns <=> $aAns,
            'ans_asc' => $aAns <=> $bAns,
            'start_desc' => strcmp($b['start_datetime'] ?? '', $a['start_datetime'] ?? ''),
            'start_asc' => strcmp($a['start_datetime'] ?? '', $b['start_datetime'] ?? ''),
            default => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '') // updated_desc
        };
    });
?>
  <div class="card">
    <div class="card-header">
      <span>アンケート一覧</span>
      <a href="index.php?screen=edit" class="btn btn-primary">+ 新規作成</a>
    </div>

    <!-- 検索・フィルタフォーム (Enterキーで送信) -->
    <form method="GET" action="index.php" style="margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
      <input type="hidden" name="screen" value="list">
      <input type="text" name="q" class="form-control" style="max-width: 280px;" placeholder="タイトルで検索 (Enterで実行)" value="<?= htmlspecialchars($searchQ) ?>">
      <select name="status" class="form-control" style="max-width: 160px;" onchange="this.form.submit()">
        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>すべての状態</option>
        <option value="公開中" <?= $filterStatus === '公開中' ? 'selected' : '' ?>>公開中</option>
        <option value="下書き" <?= $filterStatus === '下書き' ? 'selected' : '' ?>>下書き</option>
        <option value="停止" <?= $filterStatus === '停止' ? 'selected' : '' ?>>停止</option>
        <option value="終了" <?= $filterStatus === '終了' ? 'selected' : '' ?>>終了</option>
      </select>
      <select name="sort" class="form-control" style="max-width: 200px;" onchange="this.form.submit()">
        <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>更新日: 新しい順</option>
        <option value="updated_asc" <?= $sort === 'updated_asc' ? 'selected' : '' ?>>更新日: 古い順</option>
        <option value="ans_desc" <?= $sort === 'ans_desc' ? 'selected' : '' ?>>回答数: 多い順</option>
        <option value="ans_asc" <?= $sort === 'ans_asc' ? 'selected' : '' ?>>回答数: 少ない順</option>
        <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>開始日: 新しい順</option>
        <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>開始日: 古い順</option>
      </select>
    </form>

    <div class="table-responsive">
      <table>
        <thead>
          <tr>
            <th>ステータス</th>
            <th>タイトル</th>
            <th>期間</th>
            <th>回答数</th>
            <th>更新日</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($listSurveys)): ?>
            <tr><td colspan="6" style="text-align: center; color: #888; padding: 24px;">アンケートがありません。</td></tr>
          <?php else: foreach ($listSurveys as $s):
              $sId = $s['id'];
              $ansCount = count(array_filter($answersData, fn($x) => ($x['survey_id'] ?? '') === $sId));
              $st = $s['status'] ?? '下書き';
              $badgeClass = match($st) {
                  '公開中' => 'badge-public',
                  '停止' => 'badge-stopped',
                  '終了' => 'badge-closed',
                  default => 'badge-draft'
              };
          ?>
            <tr>
              <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($st) ?></span></td>
              <td><strong><?= htmlspecialchars($s['title']) ?></strong></td>
              <td style="font-size: 0.85rem; color: #666;">
                <?= htmlspecialchars($s['start_datetime'] ?: '未設定') ?><br>〜 <?= htmlspecialchars($s['end_datetime'] ?: '未設定') ?>
              </td>
              <td><?= $ansCount ?> 件</td>
              <td style="font-size: 0.85rem; color: #666;"><?= htmlspecialchars(substr($s['updated_at'] ?? '', 0, 16)) ?></td>
              <td style="white-space: nowrap;">
                <a href="index.php?screen=edit&id=<?= urlencode($sId) ?>" class="btn btn-secondary btn-sm">編集</a>
                <a href="index.php?screen=preview&id=<?= urlencode($sId) ?>" class="btn btn-secondary btn-sm" target="_blank">プレビュー</a>
                <a href="index.php?screen=analytics&id=<?= urlencode($sId) ?>" class="btn btn-secondary btn-sm">集計</a>
                <a href="index.php?screen=send&id=<?= urlencode($sId) ?>" class="btn btn-secondary btn-sm">送信</a>
                <form method="POST" action="index.php" style="display: inline;" onsubmit="return confirm('複製しますか？');">
                  <input type="hidden" name="action" value="duplicate_survey">
                  <input type="hidden" name="target_id" value="<?= htmlspecialchars($sId) ?>">
                  <button type="submit" class="btn btn-secondary btn-sm">複製</button>
                </form>
                <form method="POST" action="index.php" style="display: inline;" onsubmit="return confirm('本当に削除しますか？');">
                  <input type="hidden" name="action" value="delete_survey">
                  <input type="hidden" name="target_id" value="<?= htmlspecialchars($sId) ?>">
                  <button type="submit" class="btn btn-danger btn-sm">削除</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php
// ==========================================
// B. アンケート作成・編集 (screen=edit)
// ==========================================
elseif ($screen === 'edit'):
    $survey = $id && isset($surveys[$id]) ? $surveys[$id] : [
        'id' => '',
        'title' => '',
        'description' => '',
        'start_datetime' => '',
        'end_datetime' => '',
        'numbering_type' => 'overall',
        'status' => '下書き',
        'groups' => [
            [
                'id' => 'grp_1',
                'title' => '基本質問',
                'questions' => [
                    [
                        'id' => 'q_1',
                        'text' => '当サービスを知ったきっかけを教えてください。',
                        'type' => 'single',
                        'required' => true,
                        'options' => ['Web検索', 'SNS', '知人の紹介', 'その他'],
                        'branches' => []
                    ]
                ]
            ]
        ]
    ];
    $status = $survey['status'];
?>
  <div class="card">
    <div class="card-header">
      <span><?= $survey['id'] ? 'アンケート編集' : 'アンケート新規作成' ?></span>
      <span class="badge badge-<?= $status === '公開中' ? 'public' : ($status === '停止' ? 'stopped' : ($status === '終了' ? 'closed' : 'draft')) ?>">現在の状態: <?= htmlspecialchars($status) ?></span>
    </div>

    <form method="POST" action="index.php" id="surveyForm" onsubmit="return serializeGroups();">
      <input type="hidden" name="action" value="save_survey">
      <input type="hidden" name="survey_id" value="<?= htmlspecialchars($survey['id']) ?>">
      <input type="hidden" name="groups_json" id="groups_json" value="">

      <div class="form-group">
        <label>タイトル <span class="q-req">*</span></label>
        <input type="text" name="title" class="form-control" required value="<?= htmlspecialchars($survey['title']) ?>">
      </div>

      <div class="form-group">
        <label>説明文</label>
        <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($survey['description']) ?></textarea>
      </div>

      <div style="display: flex; gap: 16px; flex-wrap: wrap;">
        <div class="form-group" style="flex: 1;">
          <label>開始日時</label>
          <input type="datetime-local" name="start_datetime" class="form-control" value="<?= htmlspecialchars(str_replace(' ', 'T', $survey['start_datetime'])) ?>">
        </div>
        <div class="form-group" style="flex: 1;">
          <label>終了日時 (経過時に自動で「終了」)</label>
          <input type="datetime-local" name="end_datetime" class="form-control" value="<?= htmlspecialchars(str_replace(' ', 'T', $survey['end_datetime'])) ?>">
        </div>
        <div class="form-group" style="flex: 1;">
          <label>質問番号の採番方式</label>
          <select name="numbering_type" class="form-control">
            <option value="overall" <?= ($survey['numbering_type'] ?? '') === 'overall' ? 'selected' : '' ?>>全体通番 (Q1, Q2...)</option>
            <option value="group" <?= ($survey['numbering_type'] ?? '') === 'group' ? 'selected' : '' ?>>グループ単位 (Q1-1, Q1-2...)</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label>ステータス変更</label>
        <?php if ($status === '終了'): ?>
          <p style="color: #888; font-size: 0.9rem;">※ 終了状態のアンケートは状態変更できません。</p>
          <input type="hidden" name="status" value="終了">
        <?php else: ?>
          <select name="status" class="form-control" style="max-width: 240px;">
            <?php if ($status === '下書き'): ?>
              <option value="下書き" selected>下書きのまま保存</option>
              <option value="公開中">公開中にする</option>
            <?php elseif ($status === '公開中'): ?>
              <option value="公開中" selected>公開中のまま保存</option>
              <option value="停止">停止にする</option>
            <?php elseif ($status === '停止'): ?>
              <option value="停止" selected>停止のまま保存</option>
              <option value="公開中">公開中に再開する</option>
            <?php endif; ?>
          </select>
        <?php endif; ?>
      </div>

      <hr style="margin: 24px 0; border: 0; border-top: 1px solid var(--border);">

      <h3 style="margin-bottom: 16px;">グループ・質問設計</h3>
      <div id="groupsContainer"></div>

      <button type="button" class="btn btn-secondary" onclick="addGroup()" style="margin-bottom: 24px;">+ グループを追加</button>

      <div style="border-top: 1px solid var(--border); padding-top: 16px; display: flex; justify-content: space-between;">
        <button type="button" class="btn btn-secondary" onclick="if(confirm('変更を破棄して一覧へ戻りますか？')) location.href='index.php?screen=list';">キャンセル</button>
        <button type="submit" class="btn btn-primary">保存して一覧へ</button>
      </div>
    </form>
  </div>

  <script>
  let groups = <?= json_encode($survey['groups']) ?>;

  function renderGroups() {
    const container = document.getElementById('groupsContainer');
    container.innerHTML = '';
    groups.forEach((g, gIdx) => {
      const gBox = document.createElement('div');
      gBox.style = 'border: 1px solid #ccd; border-radius: 6px; padding: 16px; margin-bottom: 20px; background: #fafbfc;';
      gBox.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <input type="text" class="form-control" style="font-weight:bold; max-width:300px;" value="${escapeHtml(g.title)}" onchange="groups[${gIdx}].title = this.value">
          <div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="moveGroup(${gIdx}, -1)" ${gIdx === 0 ? 'disabled' : ''}>↑</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="moveGroup(${gIdx}, 1)" ${gIdx === groups.length - 1 ? 'disabled' : ''}>↓</button>
            <button type="button" class="btn btn-danger btn-sm" onclick="removeGroup(${gIdx})">グループ削除</button>
          </div>
        </div>
        <div id="qContainer_${gIdx}"></div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="addQuestion(${gIdx})" style="margin-top:8px;">+ 質問を追加</button>
      `;
      container.appendChild(gBox);

      const qContainer = gBox.querySelector(`#qContainer_${gIdx}`);
      g.questions.forEach((q, qIdx) => {
        const qBox = document.createElement('div');
        qBox.style = 'background:#fff; border:1px solid #e0e0e0; border-radius:4px; padding:12px; margin-bottom:10px;';
        
        let optHtml = '';
        if (q.type === 'single' || q.type === 'multiple') {
          optHtml = `
            <div style="margin-top:8px;">
              <label style="font-size:0.85rem; font-weight:bold;">選択肢 (カンマ区切り)</label>
              <input type="text" class="form-control" value="${escapeHtml((q.options || []).join(', '))}" onchange="updateOptions(${gIdx}, ${qIdx}, this.value)">
            </div>
          `;
        }

        qBox.innerHTML = `
          <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
            <span style="font-weight:bold; font-size:0.9rem;">設問</span>
            <input type="text" class="form-control" style="flex:1;" value="${escapeHtml(q.text)}" onchange="groups[${gIdx}].questions[${qIdx}].text = this.value" placeholder="質問文を入力">
            <select class="form-control" style="width:130px;" onchange="changeQType(${gIdx}, ${qIdx}, this.value)">
              <option value="single" ${q.type === 'single' ? 'selected' : ''}>単一選択</option>
              <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選択</option>
              <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述</option>
            </select>
            <label style="font-size:0.85rem; white-space:nowrap;"><input type="checkbox" ${q.required ? 'checked' : ''} onchange="groups[${gIdx}].questions[${qIdx}].required = this.checked"> 必須</label>
            <button type="button" class="btn btn-secondary btn-sm" onclick="moveQ(${gIdx}, ${qIdx}, -1)">↑</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="moveQ(${gIdx}, ${qIdx}, 1)">↓</button>
            <button type="button" class="btn btn-danger btn-sm" onclick="removeQ(${gIdx}, ${qIdx})">削除</button>
          </div>
          ${optHtml}
        `;
        qContainer.appendChild(qBox);
      });
    });
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function addGroup() {
    groups.push({ id: 'grp_' + Date.now(), title: '新規グループ', questions: [] });
    renderGroups();
  }
  function removeGroup(idx) {
    if (confirm('グループを削除しますか？')) {
      groups.splice(idx, 1);
      renderGroups();
    }
  }
  function moveGroup(idx, dir) {
    const target = idx + dir;
    if (target < 0 || target >= groups.length) return;
    const temp = groups[idx];
    groups[idx] = groups[target];
    groups[target] = temp;
    renderGroups();
  }
  function addQuestion(gIdx) {
    groups[gIdx].questions.push({
      id: 'q_' + Date.now() + '_' + Math.floor(Math.random()*1000),
      text: '',
      type: 'single',
      required: false,
      options: ['選択肢1', '選択肢2'],
      branches: {}
    });
    renderGroups();
  }
  function removeQ(gIdx, qIdx) {
    groups[gIdx].questions.splice(qIdx, 1);
    renderGroups();
  }
  function moveQ(gIdx, qIdx, dir) {
    const target = qIdx + dir;
    if (target < 0 || target >= groups[gIdx].questions.length) return;
    const temp = groups[gIdx].questions[qIdx];
    groups[gIdx].questions[qIdx] = groups[gIdx].questions[target];
    groups[gIdx].questions[target] = temp;
    renderGroups();
  }
  function changeQType(gIdx, qIdx, val) {
    groups[gIdx].questions[qIdx].type = val;
    if (val === 'single' || val === 'multiple') {
      if (!groups[gIdx].questions[qIdx].options || groups[gIdx].questions[qIdx].options.length === 0) {
        groups[gIdx].questions[qIdx].options = ['選択肢1', '選択肢2'];
      }
    }
    renderGroups();
  }
  function updateOptions(gIdx, qIdx, val) {
    groups[gIdx].questions[qIdx].options = val.split(',').map(s => s.trim()).filter(s => s !== '');
  }
  function serializeGroups() {
    document.getElementById('groups_json').value = JSON.stringify(groups);
    return true;
  }

  renderGroups();
  </script>

<?php
// ==========================================
// C. プレビュー画面 (screen=preview)
// ==========================================
elseif ($screen === 'preview'):
    if (!$id || !isset($surveys[$id])) {
        echo "<div class='card'><p>アンケートが存在しません。</p></div>";
    } else {
        $survey = $surveys[$id];
?>
  <div class="card" style="border-top: 4px solid var(--info);">
    <div style="background: #e1f5fe; padding: 10px 16px; border-radius: 4px; margin-bottom: 20px; font-size: 0.9rem; color: #0277bd;">
      <strong>【プレビュー表示】</strong> これはアンケートの確認用表示です。メール送信や実回答データの保存は行われません。
    </div>
    <h2><?= htmlspecialchars($survey['title']) ?></h2>
    <?php if (!empty($survey['description'])): ?>
      <p style="color: #666; margin: 12px 0 24px;"><?= nl2br(htmlspecialchars($survey['description'])) ?></p>
    <?php endif; ?>

    <?php foreach ($survey['groups'] as $grp): ?>
      <div style="margin-top: 24px; margin-bottom: 12px; font-size: 1.1rem; font-weight: bold; color: var(--primary);">
        <?= htmlspecialchars($grp['title']) ?>
      </div>
      <?php foreach ($grp['questions'] as $q): ?>
        <div class="q-block">
          <div class="q-title">
            <?= htmlspecialchars($q['number'] ?? '') ?>. <?= htmlspecialchars($q['text']) ?>
            <?php if (!empty($q['required'])): ?><span class="q-req">* 必須</span><?php endif; ?>
          </div>
          <?php if ($q['type'] === 'single'): ?>
            <?php foreach (($q['options'] ?? []) as $opt): ?>
              <label class="opt-label"><input type="radio" name="preview_<?= $q['id'] ?>" disabled> <?= htmlspecialchars($opt) ?></label>
            <?php endforeach; ?>
          <?php elseif ($q['type'] === 'multiple'): ?>
            <?php foreach (($q['options'] ?? []) as $opt): ?>
              <label class="opt-label"><input type="checkbox" disabled> <?= htmlspecialchars($opt) ?></label>
            <?php endforeach; ?>
          <?php else: ?>
            <textarea class="form-control" rows="3" disabled placeholder="自由記述欄"></textarea>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
<?php }

// ==========================================
// D. 顧客選択・メール送信 (screen=send)
// ==========================================
elseif ($screen === 'send'):
    if (!$id || !isset($surveys[$id])) {
        echo "<div class='card'><p>アンケートを特定できません。一覧から選択してください。</p></div>";
    } else {
        $survey = $surveys[$id];
        $customers = Storage::load(FILE_CUSTOMERS, []);
        $sendLogs = Storage::load(FILE_SEND_LOGS, []);
        $surveyLogs = array_filter($sendLogs, fn($l) => ($l['survey_id'] ?? '') === $id);
?>
  <div class="card">
    <div class="card-header">
      <span>メール送信: <?= htmlspecialchars($survey['title']) ?></span>
    </div>

    <form method="POST" action="index.php">
      <input type="hidden" name="action" value="send_survey_mail">
      <input type="hidden" name="survey_id" value="<?= htmlspecialchars($id) ?>">
      <input type="hidden" name="screen" value="send">

      <div class="form-group">
        <label>メール件名</label>
        <input type="text" name="subject" class="form-control" required value="【アンケートご協力のお願い】<?= htmlspecialchars($survey['title']) ?>">
      </div>

      <div class="form-group">
        <label>メール本文テンプレート</label>
        <div style="font-size: 0.8rem; color: #666; margin-bottom: 4px;">利用可能変数: <code>{顧客名}</code>, <code>{アンケートURL}</code></div>
        <textarea name="body" class="form-control" rows="6" required>{顧客名} 様

いつも大変お世話になっております。
アンケートへのご協力をお願い申し上げます。

▼ 回答URL
{アンケートURL}

何卒よろしくお願いいたします。</textarea>
      </div>

      <div class="form-group">
        <label>送信対象顧客の選択 (kintoneから同期された顧客一覧)</label>
        <div class="table-responsive" style="max-height: 250px; overflow-y: auto; border: 1px solid var(--border);">
          <table>
            <thead>
              <tr>
                <th style="width: 40px;"><input type="checkbox" onclick="document.querySelectorAll('.cust-cb').forEach(c=>c.checked=this.checked)"></th>
                <th>組織名</th>
                <th>氏名</th>
                <th>メールアドレス</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($customers)): ?>
                <tr><td colspan="4" style="text-align: center; color: #888;">顧客情報がありません。kintone設定から同期を行ってください。</td></tr>
              <?php else: foreach ($customers as $c): ?>
                <tr>
                  <td><input type="checkbox" name="selected_customers[]" class="cust-cb" value="<?= htmlspecialchars($c['id']) ?>"></td>
                  <td><?= htmlspecialchars($c['org']) ?></td>
                  <td><?= htmlspecialchars($c['name']) ?></td>
                  <td><?= htmlspecialchars($c['email']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" onclick="return confirm('選択した顧客へメールを一括送信しますか？');">一括送信実行</button>
    </form>

    <hr style="margin: 30px 0; border: 0; border-top: 1px solid var(--border);">

    <h3>送信履歴 (当アンケート)</h3>
    <div class="table-responsive" style="margin-top: 12px;">
      <table>
        <thead>
          <tr>
            <th>送信日時</th>
            <th>宛先氏名</th>
            <th>メールアドレス</th>
            <th>結果</th>
            <th>備考</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($surveyLogs)): ?>
            <tr><td colspan="5" style="text-align: center; color: #888;">送信履歴はありません。</td></tr>
          <?php else: foreach (array_reverse($surveyLogs) as $log): ?>
            <tr>
              <td><?= htmlspecialchars($log['sent_at']) ?></td>
              <td><?= htmlspecialchars($log['customer_name']) ?></td>
              <td><?= htmlspecialchars($log['email']) ?></td>
              <td>
                <span class="badge badge-<?= ($log['status'] === '成功') ? 'public' : 'closed' ?>"><?= htmlspecialchars($log['status']) ?></span>
              </td>
              <td style="font-size: 0.8rem; color: #d9534f;"><?= htmlspecialchars($log['error'] ?? '') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php }

// ==========================================
// E. 回答集計・分析 (screen=analytics)
// ==========================================
elseif ($screen === 'analytics'):
    if (!$id || !isset($surveys[$id])) {
        echo "<div class='card'><p>アンケートを特定できません。一覧から選択してください。</p></div>";
    } else {
        $survey = $surveys[$id];
        $answersData = Storage::load(FILE_ANSWERS, []);
        $surveyAnswers = array_values(array_filter($answersData, fn($a) => ($a['survey_id'] ?? '') === $id));
        $sendLogs = Storage::load(FILE_SEND_LOGS, []);
        $targetSent = array_filter($sendLogs, fn($l) => ($l['survey_id'] ?? '') === $id && $l['status'] === '成功');
        $sentCount = count($targetSent);
        $totalAnswers = count($surveyAnswers);
        
        $customerAnswersCount = 0;
        foreach ($surveyAnswers as $ans) {
            if (!empty($ans['customer_id'])) $customerAnswersCount++;
        }
        $unregisteredCount = $totalAnswers - $customerAnswersCount;
        $unansweredCount = max(0, $sentCount - $customerAnswersCount);
        $responseRate = $sentCount > 0 ? round(($customerAnswersCount / $sentCount) * 100, 1) : 0;
?>
  <div class="card">
    <div class="card-header">
      <span>集計・分析: <?= htmlspecialchars($survey['title']) ?></span>
      <a href="index.php?screen=export_csv&id=<?= urlencode($id) ?>" class="btn btn-secondary btn-sm">CSVエクスポート</a>
    </div>

    <div style="display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap;">
      <div style="flex: 1; min-width: 120px; background: #fafafa; border: 1px solid var(--border); padding: 12px; border-radius: 4px; text-align: center;">
        <div style="font-size: 0.8rem; color: #666;">送信対象者数</div>
        <div style="font-size: 1.4rem; font-weight: bold;"><?= $sentCount ?></div>
      </div>
      <div style="flex: 1; min-width: 120px; background: #fafafa; border: 1px solid var(--border); padding: 12px; border-radius: 4px; text-align: center;">
        <div style="font-size: 0.8rem; color: #666;">回答総数</div>
        <div style="font-size: 1.4rem; font-weight: bold; color: var(--primary);"><?= $totalAnswers ?></div>
      </div>
      <div style="flex: 1; min-width: 120px; background: #fafafa; border: 1px solid var(--border); padding: 12px; border-radius: 4px; text-align: center;">
        <div style="font-size: 0.8rem; color: #666;">未登録回答</div>
        <div style="font-size: 1.4rem; font-weight: bold;"><?= $unregisteredCount ?></div>
      </div>
      <div style="flex: 1; min-width: 120px; background: #fafafa; border: 1px solid var(--border); padding: 12px; border-radius: 4px; text-align: center;">
        <div style="font-size: 0.8rem; color: #666;">未回答数</div>
        <div style="font-size: 1.
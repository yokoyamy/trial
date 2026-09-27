<?php
declare(strict_types=1);

/*
 * アンケート運営システム
 * Apache 2.4 / PHP 8.4 / 8.5
 * DBなし / JSONファイル保存 / 入口は本ファイルのみ
 */

const APP_NAME = 'アンケート運営';
const JSON_FILES = [
    'surveys' => 'surveys.json',
    'customers' => 'customers.json',
    'responses' => 'responses.json',
    'answer_tokens' => 'answer_tokens.json',
    'send_logs' => 'send_logs.json',
    'settings' => 'settings.json',
    'kintone_mapping' => 'kintone_mapping.json',
    'kintone_sync_logs' => 'kintone_sync_logs.json'
];

ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['survey_csrf'])) {
    $_SESSION['survey_csrf'] = bin2hex(random_bytes(32));
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; base-uri 'self'; frame-ancestors 'self'");

function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_iso(): string {
    return date('c');
}

function json_path(string $key): string {
    if (!isset(JSON_FILES[$key])) {
        throw new RuntimeException('データ定義が不正です。');
    }
    return __DIR__ . '/' . JSON_FILES[$key];
}

function ensure_data_files(): void {
    foreach (JSON_FILES as $name => $file) {
        $path = __DIR__ . '/' . $file;
        if (is_file($path)) {
            continue;
        }
        $initial = [];
        if ($name === 'settings') {
            $initial = [
                'smtp' => [
                    'host' => '', 'port' => '587', 'encryption' => 'tls',
                    'username' => '', 'password' => '', 'fromEmail' => '', 'fromName' => APP_NAME
                ],
                'kintone' => [
                    'subdomain' => '', 'appId' => '', 'login' => '', 'password' => '',
                    'proxyHostPort' => '', 'verifySsl' => false
                ]
            ];
        }
        atomic_write_json($path, $initial);
    }
}

function read_json_file(string $key): array {
    $path = json_path($key);
    if (!is_file($path)) {
        throw new RuntimeException('データファイルが見つかりません。');
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('データファイルを読み込めません。');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('データファイルが壊れています。内容を上書きせず処理を停止しました。');
    }
    return $data;
}

function atomic_write_json_path(string $path, array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('JSON化に失敗しました。');
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
    $fp = fopen($tmp, 'xb');
    if ($fp === false) {
        throw new RuntimeException('データファイルを書き込めません。');
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データファイルのロックに失敗しました。');
        }
        $written = fwrite($fp, $json);
        if ($written === false || $written < strlen($json)) {
            throw new RuntimeException('データファイルの書き込みに失敗しました。');
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('データファイルの反映に失敗しました。');
        }
        @chmod($path, 0600);
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            fclose($fp);
        }
        @unlink($tmp);
        throw $e;
    }
}

function atomic_write_json(string $keyOrPath, array $data): void {
    $path = isset(JSON_FILES[$keyOrPath]) ? json_path($keyOrPath) : $keyOrPath;
    atomic_write_json_path($path, $data);
}

function with_file_lock(string $key, callable $callback): mixed {
    $lockPath = json_path($key) . '.lock';
    $fp = fopen($lockPath, 'c');
    if ($fp === false) {
        throw new RuntimeException('データ処理のロックを取得できません。');
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データ処理のロックを取得できません。');
        }
        $result = $callback();
        flock($fp, LOCK_UN);
        fclose($fp);
        return $result;
    } catch (Throwable $e) {
        flock($fp, LOCK_UN);
        fclose($fp);
        throw $e;
    }
}

function api_response(bool $ok, array $data = [], string $code = '', string $message = '', array $fields = []): never {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=UTF-8');
    if ($ok) {
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'fields' => $fields]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function require_csrf(): void {
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals((string)$_SESSION['survey_csrf'], $token)) {
        api_response(false, [], 'CSRF_ERROR', 'セキュリティ確認に失敗しました。画面を再読み込みしてください。');
    }
}

function request_json(): array {
    $raw = (string)($_POST['payload'] ?? '');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_response(false, [], 'VALIDATION_ERROR', '入力データを読み込めませんでした。');
    }
    return $data;
}

function validate_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function new_id(string $prefix): string {
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
}

function survey_question_flat(array $survey): array {
    $out = [];
    foreach ($survey['groups'] ?? [] as $gi => $group) {
        foreach ($group['questions'] ?? [] as $qi => $question) {
            $question['_groupIndex'] = $gi;
            $question['_questionIndex'] = $qi;
            $out[] = $question;
        }
    }
    return $out;
}

function validate_survey(array $survey): array {
    $errors = [];
    $name = trim((string)($survey['name'] ?? ''));
    if ($name === '') $errors[] = 'アンケート名を入力してください。';
    $start = (string)($survey['startAt'] ?? '');
    $end = (string)($survey['endAt'] ?? '');
    if ($start === '' || strtotime($start) === false) $errors[] = '開始日時を正しく設定してください。';
    if ($end === '' || strtotime($end) === false) $errors[] = '終了日時を正しく設定してください。';
    if ($start !== '' && $end !== '' && strtotime($start) !== false && strtotime($end) !== false && strtotime($start) >= strtotime($end)) {
        $errors[] = '開始日時は終了日時より前にしてください。';
    }
    $groups = $survey['groups'] ?? null;
    if (!is_array($groups) || count($groups) === 0) $errors[] = 'グループを1つ以上作成してください。';
    $questionIds = [];
    $groupIds = [];
    foreach ($groups ?? [] as $gi => $group) {
        $gid = trim((string)($group['id'] ?? ''));
        if ($gid === '' || isset($groupIds[$gid])) $errors[] = 'グループIDが不正または重複しています。';
        $groupIds[$gid] = true;
        $questions = $group['questions'] ?? [];
        if (!is_array($questions)) { $errors[] = 'グループ内の質問データが不正です。'; continue; }
        foreach ($questions as $qi => $q) {
            $qid = trim((string)($q['id'] ?? ''));
            $type = (string)($q['type'] ?? '');
            $title = trim((string)($q['title'] ?? ''));
            if ($qid === '' || isset($questionIds[$qid])) $errors[] = '質問IDが不正または重複しています。';
            $questionIds[$qid] = true;
            if ($title === '') $errors[] = '質問文を入力してください。';
            if (!in_array($type, ['text','single','multiple'], true)) $errors[] = '質問形式が不正です。';
            if (in_array($type, ['single','multiple'], true)) {
                $choices = $q['choices'] ?? [];
                if (!is_array($choices) || count($choices) === 0) $errors[] = '選択式質問には選択肢が必要です。';
                $choiceIds = [];
                foreach ($choices ?? [] as $choice) {
                    $cid = trim((string)($choice['id'] ?? ''));
                    $label = trim((string)($choice['label'] ?? ''));
                    if ($cid === '' || isset($choiceIds[$cid])) $errors[] = '選択肢IDが不正または重複しています。';
                    $choiceIds[$cid] = true;
                    if ($label === '') $errors[] = '選択肢名を入力してください。';
                }
            }
            if ($type !== 'single' && !empty($q['branch'])) $errors[] = '分岐を設定できるのは単一選択質問だけです。';
            if ($type === 'single') {
                foreach (($q['branch'] ?? []) as $choiceId => $target) {
                    $target = (string)$target;
                    $choiceIds = array_column($q['choices'] ?? [], 'id');
                    if (!in_array((string)$choiceId, $choiceIds, true)) $errors[] = '分岐元の選択肢が存在しません。';
                    if (!($target === 'next' || $target === 'end' || str_starts_with($target, 'question:'))) $errors[] = '分岐先の形式が不正です。';
                    if (str_starts_with($target, 'question:')) {
                        $targetId = substr($target, 9);
                        if ($targetId === '' || $targetId === $qid || !in_array($targetId, array_keys($questionIds), true)) {
                            /* 全質問走査後にも再検証するため後段で確認 */
                        }
                    }
                }
            }
        }
    }
    $allIds = array_keys($questionIds);
    foreach ($groups ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $q) {
            if (($q['type'] ?? '') !== 'single') continue;
            foreach (($q['branch'] ?? []) as $target) {
                if (str_starts_with((string)$target, 'question:')) {
                    $tid = substr((string)$target, 9);
                    if (!in_array($tid, $allIds, true)) $errors[] = '分岐先の質問が存在しません。';
                }
            }
        }
    }
    /* 分岐グラフの循環を検出 */
    $edges = [];
    foreach ($groups ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $q) {
            $qid = (string)$q['id'];
            foreach (($q['branch'] ?? []) as $target) {
                if (str_starts_with((string)$target, 'question:')) $edges[$qid][] = substr((string)$target, 9);
            }
        }
    }
    $visiting = [];
    $visited = [];
    $dfs = function(string $id) use (&$dfs, &$visiting, &$visited, &$edges): bool {
        if (isset($visiting[$id])) return true;
        if (isset($visited[$id])) return false;
        $visiting[$id] = true;
        foreach ($edges[$id] ?? [] as $next) if ($dfs($next)) return true;
        unset($visiting[$id]); $visited[$id] = true; return false;
    };
    foreach (array_keys($questionIds) as $qid) if ($dfs($qid)) { $errors[] = '分岐に循環があります。'; break; }
    return array_values(array_unique($errors));
}

function find_survey(string $id, ?array $surveys = null): ?array {
    $surveys ??= read_json_file('surveys');
    foreach ($surveys as $survey) if ((string)($survey['id'] ?? '') === $id) return $survey;
    return null;
}

function get_public_survey(string $surveyId): array {
    $survey = find_survey($surveyId);
    if (!$survey) api_response(false, [], 'NOT_FOUND', 'アンケートが見つかりません。');
    if (($survey['status'] ?? '') !== 'published') api_response(false, [], 'SURVEY_ERROR', 'このアンケートは現在回答できません。');
    $now = time();
    $start = strtotime((string)($survey['startAt'] ?? ''));
    $end = strtotime((string)($survey['endAt'] ?? ''));
    if ($start === false || $end === false || $now < $start || $now > $end) api_response(false, [], 'SURVEY_ERROR', '回答期間外です。');
    return $survey;
}

function find_token(string $token, ?array $tokens = null): ?array {
    $tokens ??= read_json_file('answer_tokens');
    foreach ($tokens as $row) if (hash_equals((string)($row['token'] ?? ''), $token)) return $row;
    return null;
}

function smtp_cfg_from_settings(array $settings): array {
    $smtp = $settings['smtp'] ?? [];
    return [
        'host' => trim((string)($smtp['host'] ?? '')),
        'port' => (int)($smtp['port'] ?? 0),
        'encryption' => strtolower((string)($smtp['encryption'] ?? 'none')),
        'username' => trim((string)($smtp['username'] ?? '')),
        'password' => (string)($smtp['password'] ?? ''),
        'fromEmail' => trim((string)($smtp['fromEmail'] ?? '')),
        'fromName' => trim((string)($smtp['fromName'] ?? APP_NAME))
    ];
}

function smtp_read_response($socket): string {
    $response = '';
    while (($line = fgets($socket, 512)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') break;
        if (strlen($response) > 65536) break;
    }
    return trim($response);
}

function smtp_expect($socket, array $codes, string $step): string {
    $response = smtp_read_response($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $codes, true)) throw new RuntimeException($step . 'に失敗しました。SMTPサーバーからエラーが返されました。');
    return $response;
}

function smtp_command($socket, string $command, array $codes, string $step): void {
    fwrite($socket, $command . "\r\n");
    smtp_expect($socket, $codes, $step);
}

function smtp_open(array $cfg) {
    if ($cfg['host'] === '' || $cfg['port'] < 1 || $cfg['port'] > 65535) throw new RuntimeException('SMTPホストとポートを設定してください。');
    if (!in_array($cfg['encryption'], ['none','ssl','tls'], true)) throw new RuntimeException('SMTP暗号化方式が不正です。');
    $target = ($cfg['encryption'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'];
    $context = stream_context_create(['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]]);
    $errno = 0; $errstr = '';
    $socket = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) throw new RuntimeException('SMTPサーバーへ接続できませんでした。ホスト・ポート・ファイアウォール設定を確認してください。');
    stream_set_timeout($socket, 20);
    smtp_expect($socket, [220], 'SMTP接続');
    $ehlo = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
    smtp_command($socket, 'EHLO ' . $ehlo, [250], 'EHLO');
    if ($cfg['encryption'] === 'tls') {
        smtp_command($socket, 'STARTTLS', [220], 'TLS開始');
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS通信を開始できませんでした。');
        smtp_command($socket, 'EHLO ' . $ehlo, [250], 'TLS後のEHLO');
    }
    return $socket;
}

function smtp_auth($socket, string $username, string $password): void {
    if ($username === '') return;
    smtp_command($socket, 'AUTH LOGIN', [334], 'SMTP認証開始');
    smtp_command($socket, base64_encode($username), [334], 'SMTPユーザー認証');
    smtp_command($socket, base64_encode($password), [235], 'SMTPパスワード認証');
}

function mime_header(string $value): string {
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_send(array $cfg, string $to, string $subject, string $body): void {
    if (!validate_email($to)) throw new RuntimeException('宛先メールアドレスが不正です。');
    if (!validate_email($cfg['fromEmail'])) throw new RuntimeException('送信元メールアドレスが不正です。');
    $socket = smtp_open($cfg);
    try {
        smtp_auth($socket, $cfg['username'], $cfg['password']);
        smtp_command($socket, 'MAIL FROM:<' . $cfg['fromEmail'] . '>', [250], '送信元設定');
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250,251], '宛先設定');
        smtp_command($socket, 'DATA', [354], 'メール本文開始');
        $from = $cfg['fromName'] !== '' ? mime_header($cfg['fromName']) . ' <' . $cfg['fromEmail'] . '>' : $cfg['fromEmail'];
        $headers = 'From: ' . $from . "\r\n" . 'To: <' . $to . ">\r\n" . 'Subject: ' . mime_header($subject) . "\r\n" . 'MIME-Version: 1.0' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8' . "\r\n" . 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n";
        $safeBody = preg_replace('/\r?\n/', "\r\n", $body);
        $safeBody = preg_replace('/^\./m', '..', (string)$safeBody);
        fwrite($socket, $headers . $safeBody . "\r\n.\r\n");
        smtp_expect($socket, [250], 'メール送信');
        smtp_command($socket, 'QUIT', [221,250], 'SMTP終了');
    } finally { fclose($socket); }
}

function kintone_url(array $cfg, string $path): string {
    $sub = strtolower(trim((string)($cfg['subdomain'] ?? '')));
    $sub = preg_replace('#^https?://#', '', $sub);
    $sub = preg_replace('#\.cybozu\.com.*$#', '', $sub);
    $sub = trim($sub, '/');
    if ($sub === '') throw new RuntimeException('kintoneサブドメインを設定してください。');
    return 'https://' . $sub . '.cybozu.com' . (str_starts_with($path, '/') ? $path : '/' . $path);
}

function kintone_request(array $cfg, string $method, string $path, ?array $body = null): array {
    $url = kintone_url($cfg, $path);
    $login = trim((string)($cfg['login'] ?? ''));
    $password = trim((string)($cfg['password'] ?? ''));
    if ($login === '' || $password === '') throw new RuntimeException('kintoneログイン情報を設定してください。');
    $auth = base64_encode($login . ':' . $password);
    $headers = ['X-Cybozu-Authorization: ' . $auth, 'Accept: application/json'];
    $content = null;
    if ($body !== null && in_array($method, ['POST','PUT'], true)) {
        $content = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }
    $proxy = trim((string)($cfg['proxyHostPort'] ?? ''));
    $verify = (bool)($cfg['verifySsl'] ?? false);
    $ssl = ['verify_peer'=>$verify,'verify_peer_name'=>$verify,'allow_self_signed'=>!$verify];
    $http = ['method'=>$method,'header'=>implode("\r\n", $headers),'ignore_errors'=>true,'timeout'=>30,'ssl'=>$ssl];
    if ($content !== null) $http['content'] = $content;
    if ($proxy !== '') { $http['proxy'] = 'tcp://' . $proxy; $http['request_fulluri'] = true; }
    $ctx = stream_context_create(['http'=>$http,'ssl'=>$ssl]);
    $result = @file_get_contents($url, false, $ctx);
    $headersRaw = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : [];
    $status = 0;
    foreach ($headersRaw as $line) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) $status = (int)$m[1]; }
    if ($result === false && $status === 0) throw new RuntimeException('kintoneへ接続できませんでした。');
    $decoded = json_decode((string)$result, true);
    if ($status >= 400 || !is_array($decoded)) {
        if ($status === 401 || $status === 403) throw new RuntimeException('kintoneの認証またはアプリ権限を確認してください。');
        throw new RuntimeException('kintone APIでエラーが発生しました。');
    }
    return $decoded;
}

function kintone_fields(array $cfg, string $appId): array {
    if (!ctype_digit($appId) || (int)$appId < 1) throw new RuntimeException('kintoneアプリIDが不正です。');
    return kintone_request($cfg, 'GET', '/k/v1/app/form/fields.json?app=' . rawurlencode($appId));
}

function kintone_schema(array $fields): string {
    $items = [];
    foreach ($fields as $code => $f) {
        $items[] = [
            'code'=>(string)$code,
            'type'=>(string)($f['type'] ?? ''),
            'label'=>(string)($f['label'] ?? ''),
            'required'=>(bool)($f['required'] ?? false),
            'options'=>$f['options'] ?? []
        ];
    }
    usort($items, fn($a,$b)=>strcmp($a['code'],$b['code']));
    return hash('sha256', json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function issue_token(string $surveyId, ?string $customerId, string $email): array {
    if (!validate_email($email)) throw new RuntimeException('メールアドレスが不正です。');
    $tokens = read_json_file('answer_tokens');
    $token = bin2hex(random_bytes(32));
    $row = ['tokenId'=>new_id('tok'),'token'=>$token,'surveyId'=>$surveyId,'customerId'=>$customerId,'email'=>$email,'issuedAt'=>now_iso(),'usedAt'=>null,'respondent'=>null];
    $tokens[] = $row;
    atomic_write_json('answer_tokens', $tokens);
    return $row;
}

function answer_url(string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return $scheme . '://' . $host . ($base === '/' ? '' : $base) . '/index.php?token=' . rawurlencode($token);
}

function respondent_visible_questions(array $survey, array $answers): array {
    $flat = survey_question_flat($survey);
    $map = [];
    foreach ($flat as $q) $map[$q['id']] = $q;
    $visible = [];
    $index = 0;
    while ($index < count($flat)) {
        $q = $flat[$index];
        $visible[] = $q;
        $next = $index + 1;
        if (($q['type'] ?? '') === 'single') {
            $value = $answers[$q['id']] ?? null;
            $target = null;
            if (is_string($value)) $target = $q['branch'][$value] ?? null;
            if ($target === 'end') break;
            if (is_string($target) && str_starts_with($target, 'question:')) {
                $targetId = substr($target, 9);
                foreach ($flat as $i => $candidate) if ((string)$candidate['id'] === $targetId) { $next = $i; break; }
            }
        }
        $index = $next;
    }
    return $visible;
}

function validate_answers(array $survey, array $answers): array {
    $visible = respondent_visible_questions($survey, $answers);
    $errors = [];
    $validIds = [];
    foreach ($visible as $q) {
        $qid = (string)$q['id']; $validIds[$qid] = true;
        $value = $answers[$qid] ?? null;
        $required = !empty($q['required']);
        if ($required && ($value === null || $value === '' || $value === [])) { $errors[$qid] = '必須回答です。'; continue; }
        if ($value === null || $value === '' || $value === []) continue;
        $type = $q['type'] ?? '';
        $choices = array_column($q['choices'] ?? [], 'id');
        if ($type === 'text' && mb_strlen((string)$value) > 5000) $errors[$qid] = '回答は5000文字以内で入力してください。';
        if ($type === 'single' && !in_array((string)$value, $choices, true)) $errors[$qid] = '選択肢が不正です。';
        if ($type === 'multiple') {
            if (!is_array($value)) { $errors[$qid] = '複数選択の回答形式が不正です。'; continue; }
            if (count($value) !== count(array_unique($value))) { $errors[$qid] = '同じ選択肢が重複しています。'; continue; }
            foreach ($value as $v) if (!in_array((string)$v, $choices, true)) { $errors[$qid] = '選択肢が不正です。'; break; }
        }
    }
    foreach ($answers as $qid => $_) if (!isset($validIds[$qid])) unset($answers[$qid]);
    return [$errors, $answers, $visible];
}

function require_admin_api(): void {
    /* この環境では管理画面セッションを認証済みとして扱う。ログイン機能追加時もAPI入口はここに集約する。 */
}

ensure_data_files();

$tokenParam = trim((string)($_GET['token'] ?? ''));
$isApi = isset($_GET['api']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api']));

if ($isApi) {
    require_admin_api();
    $api = (string)($_GET['api'] ?? $_POST['api'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

    try {
        switch ($api) {
            case 'state':
                $surveys=read_json_file('surveys'); $customers=read_json_file('customers'); $responses=read_json_file('responses'); $logs=read_json_file('send_logs'); $settings=read_json_file('settings'); $mapping=read_json_file('kintone_mapping');
                $publicSettings=$settings;
                if(isset($publicSettings['smtp']['password'])) $publicSettings['smtp']['password']='';
                if(isset($publicSettings['kintone']['password'])) $publicSettings['kintone']['password']='';
                api_response(true,['surveys'=>$surveys,'customers'=>$customers,'responses'=>$responses,'sendLogs'=>$logs,'settings'=>$publicSettings,'mapping'=>$mapping,'csrf'=>$_SESSION['survey_csrf']]);
            case 'survey_get':
                $s=find_survey((string)($_GET['id']??'')); if(!$s) api_response(false,[],'NOT_FOUND','アンケートが見つかりません。'); api_response(true,['survey'=>$s]);
            case 'customer_list':
                api_response(true,['customers'=>read_json_file('customers')]);
            case 'stats':
                $sid=(string)($_GET['surveyId']??''); $responses=read_json_file('responses'); $tokens=read_json_file('answer_tokens'); $rows=array_values(array_filter($responses,fn($r)=>(string)($r['surveyId']??'')===$sid)); $ts=array_values(array_filter($tokens,fn($r)=>(string)($r['surveyId']??'')===$sid)); api_response(true,['answerCount'=>count($rows),'issuedCount'=>count($ts),'unanswered'=>max(0,count($ts)-count($rows)),'responses'=>$rows]);
            case 'survey_save':
                $p=request_json();
                $survey=$p['survey']??[]; if(!is_array($survey)) api_response(false,[],'VALIDATION_ERROR','アンケートデータが不正です。');
                $surveys=read_json_file('surveys'); $id=trim((string)($survey['id']??'')); $isNew=$id==='';
                if($isNew){$survey['id']=new_id('survey');$survey['status']='draft';$survey['createdAt']=now_iso();}
                else { $old=find_survey($id,$surveys); if(!$old) api_response(false,[],'NOT_FOUND','アンケートが見つかりません。'); $survey['status']=$old['status']??'draft'; $survey['createdAt']=$old['createdAt']??now_iso(); }
                $survey['name']=trim((string)($survey['name']??'')); $survey['description']=(string)($survey['description']??''); $survey['startAt']=(string)($survey['startAt']??''); $survey['endAt']=(string)($survey['endAt']??''); $survey['numberingFormat']=in_array(($survey['numberingFormat']??'group'),['group','global'],true)?$survey['numberingFormat']:'group'; $survey['groups']=is_array($survey['groups']??null)?$survey['groups']:[]; $survey['updatedAt']=now_iso();
                foreach($survey['groups'] as &$g){ if(empty($g['id']))$g['id']=new_id('grp'); $g['name']=trim((string)($g['name']??'グループ')); $g['questions']=is_array($g['questions']??null)?$g['questions']:[]; foreach($g['questions'] as &$q){if(empty($q['id']))$q['id']=new_id('q'); $q['title']=(string)($q['title']??''); $q['type']=in_array(($q['type']??'text'),['text','single','multiple'],true)?$q['type']:'text'; $q['required']=(bool)($q['required']??false); $q['choices']=is_array($q['choices']??null)?$q['choices']:[]; $q['branch']=is_array($q['branch']??null)?$q['branch']:[]; }} unset($g,$q);
                $errors=validate_survey($survey); if($errors) api_response(false,[],'SURVEY_ERROR',implode("\n",$errors));
                $found=false; foreach($surveys as $i=>$old){if((string)$old['id']===$id){$surveys[$i]=$survey;$found=true;break;}} if(!$found)$surveys[]=$survey; atomic_write_json('surveys',$surveys); api_response(true,['survey'=>$survey]);
            case 'survey_delete':
                $p=request_json(); $id=(string)($p['surveyId']??''); $surveys=read_json_file('surveys'); $new=array_values(array_filter($surveys,fn($s)=>(string)$s['id']!==$id)); if(count($new)===count($surveys))api_response(false,[],'NOT_FOUND','アンケートが見つかりません。'); atomic_write_json('surveys',$new); api_response(true,[],'','アンケートを削除しました。');
            case 'survey_publish':
                $p=request_json(); $id=(string)($p['surveyId']??''); $surveys=read_json_file('surveys'); foreach($surveys as &$s)if((string)$s['id']===$id){$errors=validate_survey($s);if($errors)api_response(false,[],'SURVEY_PUBLISH_ERROR',implode("\n",$errors));$s['status']='published';$s['updatedAt']=now_iso();$found=true;break;} unset($s); if(empty($found))api_response(false,[],'NOT_FOUND','アンケートが見つかりません。'); atomic_write_json('surveys',$surveys); api_response(true,['survey'=>find_survey($id,$surveys)]);
            case 'survey_close':
                $p=request_json(); $id=(string)($p['surveyId']??''); $surveys=read_json_file('surveys'); $found=false; foreach($surveys as &$s)if((string)$s['id']===$id){$s['status']='closed';$s['updatedAt']=now_iso();$found=true;break;} unset($s);if(!$found)api_response(false,[],'NOT_FOUND','アンケートが見つかりません。');atomic_write_json('surveys',$surveys);api_response(true,['survey'=>find_survey($id,$surveys)]);
            case 'customer_save':
                $p=request_json(); $c=$p['customer']??[]; if(!is_array($c))api_response(false,[],'VALIDATION_ERROR','顧客データが不正です。'); $customers=read_json_file('customers'); $id=trim((string)($c['customerId']??'')); if($id===''){$id=new_id('cust');$c['customerId']=$id;$c['createdAt']=now_iso();} $c['name']=trim((string)($c['name']??''));$c['email']=trim((string)($c['email']??''));$c['status']=in_array(($c['status']??'active'),['active','inactive'],true)?$c['status']:'active';$c['updatedAt']=now_iso();if($c['name']===''||!validate_email($c['email']))api_response(false,[],'VALIDATION_ERROR','顧客名とメールアドレスを正しく入力してください。');$found=false;foreach($customers as $i=>$old)if((string)$old['customerId']===$id){$customers[$i]=array_merge($old,$c);$found=true;break;}if(!$found)$customers[]=$c;atomic_write_json('customers',$customers);api_response(true,['customer'=>end($customers)]);
            case 'customer_delete':
                $p=request_json();$id=(string)($p['customerId']??'');$customers=read_json_file('customers');$found=false;foreach($customers as &$c)if((string)$c['customerId']===$id){$c['status']='inactive';$c['updatedAt']=now_iso();$found=true;break;}unset($c);if(!$found)api_response(false,[],'NOT_FOUND','顧客が見つかりません。');atomic_write_json('customers',$customers);api_response(true);
            case 'issue_token':
                $p=request_json();$sid=(string)($p['surveyId']??'');$survey=get_public_survey($sid);$customerId=$p['customerId']??null;$email=trim((string)($p['email']??''));if($customerId){$customers=read_json_file('customers');foreach($customers as $c)if((string)$c['customerId']===(string)$customerId){$email=(string)$c['email'];break;}}$row=issue_token($sid,$customerId,$email);api_response(true,['token'=>$row,'url'=>answer_url($row['token'])]);
            case 'send_mail':
                $p=request_json();$sid=(string)($p['surveyId']??'');$survey=get_public_survey($sid);$settings=read_json_file('settings');$cfg=smtp_cfg_from_settings($settings);$recipients=$p['recipients']??[];if(!is_array($recipients)||!$recipients)api_response(false,[],'VALIDATION_ERROR','送信対象を選択してください。');$customers=read_json_file('customers');$tokens=read_json_file('answer_tokens');$logs=read_json_file('send_logs');$sent=0;$failed=0;$errors=[];foreach($recipients as $cid){$customer=null;foreach($customers as $c)if((string)$c['customerId']===(string)$cid){$customer=$c;break;}if(!$customer||($customer['status']??'')!=='active'){continue;}$already=false;foreach($logs as $l)if((string)($l['surveyId']??'')===$sid&&(string)($l['customerId']??'')===(string)$cid&&($l['status']??'')==='sent'){$already=true;break;}if($already){$errors[]=$customer['email'].'：送信済みのためスキップ';continue;}try{$token=issue_token($sid,$cid,(string)$customer['email']);$url=answer_url($token['token']);$body=$survey['name']."\n\n以下のURLから回答してください。\n".$url."\n\n回答期限：".date('Y/m/d H:i',strtotime((string)$survey['endAt']));smtp_send($cfg,(string)$customer['email'],'【回答依頼】'.$survey['name'],$body);$logs[]=['logId'=>new_id('send'),'surveyId'=>$sid,'customerId'=>$cid,'email'=>$customer['email'],'sentAt'=>now_iso(),'status'=>'sent','error'=>'','tokenId'=>$token['tokenId']];$sent++;}catch(Throwable $e){$logs[]=['logId'=>new_id('send'),'surveyId'=>$sid,'customerId'=>$cid,'email'=>$customer['email'],'sentAt'=>now_iso(),'status'=>'error','error'=>$e->getMessage(),'tokenId'=>null];$failed++;}}atomic_write_json('send_logs',$logs);api_response(true,['sent'=>$sent,'failed'=>$failed,'errors'=>$errors]);
            case 'resend_failed':
                $p=request_json();$logId=(string)($p['logId']??'');$logs=read_json_file('send_logs');$target=null;foreach($logs as $l)if((string)$l['logId']===$logId){$target=$l;break;}if(!$target||($target['status']??'')!=='error')api_response(false,[],'NOT_FOUND','再送対象が見つかりません。');$survey=find_survey((string)$target['surveyId']);$customers=read_json_file('customers');$customer=null;foreach($customers as $c)if((string)$c['customerId']===(string)$target['customerId']){$customer=$c;break;}if(!$survey||!$customer)api_response(false,[],'NOT_FOUND','再送対象の情報が見つかりません。');$token=issue_token((string)$survey['id'],(string)$customer['customerId'],(string)$customer['email']);$settings=read_json_file('settings');$url=answer_url($token['token']);smtp_send(smtp_cfg_from_settings($settings),(string)$customer['email'],'【回答依頼】'.$survey['name'],$survey['name']."\n\n以下のURLから回答してください。\n".$url);foreach($logs as &$l)if((string)$l['logId']===$logId){$l['status']='sent';$l['sentAt']=now_iso();$l['error']='';$l['tokenId']=$token['tokenId'];}unset($l);atomic_write_json('send_logs',$logs);api_response(true);
            case 'smtp_save':
                $p=request_json();$cfg=$p['smtp']??[];$cfg['host']=trim((string)($cfg['host']??''));$cfg['port']=(string)($cfg['port']??'');$cfg['encryption']=strtolower((string)($cfg['encryption']??'none'));$cfg['username']=trim((string)($cfg['username']??''));$incomingPassword=(string)($cfg['password']??'');$cfg['password']=$incomingPassword;$cfg['fromEmail']=trim((string)($cfg['fromEmail']??''));$cfg['fromName']=trim((string)($cfg['fromName']??''));if($cfg['host']===''||!ctype_digit($cfg['port'])||(int)$cfg['port']<1||(int)$cfg['port']>65535||!in_array($cfg['encryption'],['none','ssl','tls'],true)||!validate_email($cfg['fromEmail']))api_response(false,[],'SMTP_ERROR','SMTP設定を正しく入力してください。');$settings=read_json_file('settings');
                if($cfg['password']==='' && !empty($settings['smtp']['password'])) $cfg['password']=$settings['smtp']['password'];
                $settings['smtp']=$cfg;atomic_write_json('settings',$settings);api_response(true);
            case 'smtp_test':
                $p=request_json();$cfg=$p['smtp']??[];$to=trim((string)($p['to']??''));$socket=smtp_open($cfg);smtp_command($socket,'QUIT',[221,250],'SMTP終了');fclose($socket);if($to!=='')smtp_send($cfg,$to,'SMTP接続テスト',"SMTP接続・認証テストが成功しました。\n\n".now_iso());api_response(true,['mailSent'=>$to!=='']);
            case 'kintone_save':
                $p=request_json();$cfg=$p['kintone']??[];$cfg['subdomain']=trim((string)($cfg['subdomain']??''));$cfg['appId']=trim((string)($cfg['appId']??''));$cfg['login']=trim((string)($cfg['login']??''));$cfg['password']=(string)($cfg['password']??'');$cfg['proxyHostPort']=trim((string)($cfg['proxyHostPort']??''));$cfg['verifySsl']=(bool)($cfg['verifySsl']??false);if($cfg['subdomain']===''||!ctype_digit($cfg['appId'])||$cfg['login']===''||$cfg['password']==='')api_response(false,[],'KINTONE_ERROR','kintone設定を正しく入力してください。');$settings=read_json_file('settings');
                if($cfg['password']==='' && !empty($settings['kintone']['password'])) $cfg['password']=$settings['kintone']['password'];
                $settings['kintone']=$cfg;atomic_write_json('settings',$settings);api_response(true);
            case 'kintone_test':
                $settings=read_json_file('settings');$cfg=$settings['kintone']??[];kintone_fields($cfg,(string)$cfg['appId']);api_response(true,['message'=>'kintoneへの接続と対象アプリの利用確認に成功しました。']);
            case 'kintone_fields':
                $p=request_json();$settings=read_json_file('settings');$cfg=$settings['kintone']??[];$appId=trim((string)($p['appId']??$cfg['appId']??''));$result=kintone_fields($cfg,$appId);$fields=$result['properties']??[];$schema=kintone_schema($fields);$mapping=read_json_file('kintone_mapping');$mapping=['appId'=>$appId,'fetchedAt'=>now_iso(),'schemaHash'=>$schema,'fields'=>$fields,'mappings'=>$mapping['mappings']??[],'syncKey'=>'kintoneRecordId','mappingVersion'=>(int)($mapping['mappingVersion']??0),'updatedAt'=>now_iso()];atomic_write_json('kintone_mapping',$mapping);api_response(true,['mapping'=>$mapping]);
            case 'kintone_mapping_get': api_response(true,['mapping'=>read_json_file('kintone_mapping')]);
            case 'kintone_mapping_save':
                $p=request_json();$mapping=$p['mapping']??[];$saved=read_json_file('kintone_mapping');if((string)($mapping['appId']??'')!==(string)($saved['appId']??''))api_response(false,[],'KINTONE_MAPPING_INVALID','最新のkintone項目を取得してから保存してください。');$fields=$saved['fields']??[];$m=$mapping['mappings']??[];if(!isset($m['name'])||!isset($m['email'])||!isset($fields[$m['name']])||!isset($fields[$m['email']]))api_response(false,[],'KINTONE_MAPPING_INVALID','顧客名とメールアドレスのマッピングは必須です。');$saved['mappings']=$m;$saved['mappingVersion']=(int)($saved['mappingVersion']??0)+1;$saved['updatedAt']=now_iso();atomic_write_json('kintone_mapping',$saved);api_response(true,['mapping'=>$saved]);
            case 'kintone_sync_preview':
            case 'kintone_sync_execute':
                $settings=read_json_file('settings');$cfg=$settings['kintone']??[];$mapping=read_json_file('kintone_mapping');$latest=kintone_fields($cfg,(string)($cfg['appId']??''));$fields=$latest['properties']??[];$hash=kintone_schema($fields);if((string)($mapping['appId']??'')!==(string)($cfg['appId']??'')||(string)($mapping['schemaHash']??'')!==$hash)api_response(false,[],'KINTONE_SCHEMA_CHANGED','kintoneの項目構成が変更されています。最新の項目を取得してマッピングを再確認してください。');$customers=read_json_file('customers');$resp=kintone_request($cfg,'GET','/k/v1/records.json?app='.rawurlencode((string)$cfg['appId']).'&totalCount=true&query='.rawurlencode('order by $id asc limit 500'));$records=$resp['records']??[];$created=0;$updated=0;$unchanged=0;$incoming=[];$nameCode=(string)$mapping['mappings']['name'];$emailCode=(string)$mapping['mappings']['email'];foreach($records as $record){$rid=(string)($record['$id']['value']??'');$name=(string)($record[$nameCode]['value']??'');$email=(string)($record[$emailCode]['value']??'');if($rid==='')continue;$incoming[$rid]=['customerId'=>new_id('cust'),'name'=>$name,'email'=>$email,'status'=>'active','kintoneRecordId'=>$rid,'kintoneUpdatedAt'=>now_iso(),'syncStatus'=>'synced','syncError'=>''];$existing=null;foreach($customers as $c)if((string)($c['kintoneRecordId']??'')===$rid){$existing=$c;break;}if(!$existing)$created++;elseif((string)$existing['name']===$name&&(string)$existing['email']===$email)$unchanged++;else$updated++;}if($api==='kintone_sync_preview')api_response(true,['totalCount'=>count($records),'createdCount'=>$created,'updatedCount'=>$updated,'unchangedCount'=>$unchanged,'errorCount'=>0]);$lockKey='kintone_sync_logs';$result=with_file_lock($lockKey,function()use($customers,$incoming,$records,$mapping,$cfg){$existingByRid=[];foreach($customers as $c){$rid=(string)($c['kintoneRecordId']??'');if($rid!=='')$existingByRid[$rid]=$c;}$out=$customers;$created=0;$updated=0;$unchanged=0;foreach($incoming as $rid=>$row){if(isset($existingByRid[$rid])){$old=$existingByRid[$rid];if($old['name']===$row['name']&&$old['email']===$row['email']){$unchanged++;continue;}$row=array_merge($old,$row,['updatedAt'=>now_iso()]);foreach($out as $i=>$c)if((string)($c['kintoneRecordId']??'')===$rid)$out[$i]=$row;$updated++;}else{$row['updatedAt']=now_iso();$out[]=$row;$created++;}}atomic_write_json('customers',$out);return[$created,$updated,$unchanged];});$logs=read_json_file('kintone_sync_logs');[$created,$updated,$unchanged]=$result;$logs[]=['syncId'=>new_id('sync'),'startedAt'=>now_iso(),'finishedAt'=>now_iso(),'appId'=>(string)$cfg['appId'],'schemaHash'=>(string)$mapping['schemaHash'],'mappingVersion'=>(int)$mapping['mappingVersion'],'totalCount'=>count($records),'createdCount'=>$created,'updatedCount'=>$updated,'unchangedCount'=>$unchanged,'inactiveCount'=>0,'skippedCount'=>0,'errorCount'=>0,'status'=>'success','errorSummary'=>''];atomic_write_json('kintone_sync_logs',$logs);api_response(true,['createdCount'=>$created,'updatedCount'=>$updated,'unchangedCount'=>$unchanged]);
            case 'answer_start':
                $token=trim((string)($_GET['token']??''));$tokens=read_json_file('answer_tokens');$row=find_token($token,$tokens);if(!$row)api_response(false,[],'TOKEN_ERROR','回答URLが正しくありません。');if(!empty($row['usedAt']))api_response(false,[],'TOKEN_ALREADY_USED','この回答URLはすでに回答済みです。');$survey=get_public_survey((string)$row['surveyId']);api_response(true,['survey'=>$survey,'token'=>$row]);
            case 'answer_submit':
                $p=request_json();$token=trim((string)($p['token']??''));$answers=$p['answers']??[];$respondent=$p['respondent']??[];if(!is_array($answers)||!is_array($respondent))api_response(false,[],'RESPONSE_ERROR','回答データが不正です。');$tokens=read_json_file('answer_tokens');$row=null;$idx=-1;foreach($tokens as $i=>$t)if(hash_equals((string)$t['token'],$token)){$row=$t;$idx=$i;break;}if(!$row)api_response(false,[],'TOKEN_ERROR','回答URLが正しくありません。');if(!empty($row['usedAt']))api_response(false,[],'TOKEN_ALREADY_USED','この回答URLはすでに回答済みです。');$survey=get_public_survey((string)$row['surveyId']);[$errors,$answers,$visible]=validate_answers($survey,$answers);if($errors)api_response(false,['fields'=>$errors],'VALIDATION_ERROR','未回答または入力内容に誤りがあります。',$errors);$name=trim((string)($respondent['name']??''));$department=trim((string)($respondent['department']??''));$email=trim((string)($respondent['email']??$row['email']??''));$organization=trim((string)($respondent['organization']??''));if($row['customerId']===null&&($organization===''||$email===''||!validate_email($email)))api_response(false,[],'VALIDATION_ERROR','組織名とメールアドレスを入力してください。');$responses=read_json_file('responses');$response=['responseId'=>new_id('resp'),'surveyId'=>$survey['id'],'tokenId'=>$row['tokenId'],'customerId'=>$row['customerId'],'respondent'=>['organization'=>$organization,'name'=>$name,'department'=>$department,'email'=>$email],'answers'=>$answers,'visibleQuestionIds'=>array_values(array_map(fn($q)=>(string)$q['id'],$visible)),'answeredAt'=>now_iso()];$responses[]=$response;$tokens[$idx]['usedAt']=now_iso();$tokens[$idx]['respondent']=$response['respondent'];atomic_write_json('responses',$responses);atomic_write_json('answer_tokens',$tokens);api_response(true,['responseId'=>$response['responseId']]);
            default: api_response(false,[],'NOT_FOUND','指定された処理は存在しません。');
        }
    } catch (Throwable $e) {
        api_response(false,[], 'SYSTEM_ERROR', $e->getMessage());
    }
}

if ($tokenParam !== '') {
    $csrf = h($_SESSION['survey_csrf']);
    ?><!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h(APP_NAME)?> - 回答</title><style>
    *{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#222;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif}.respondent{max-width:780px;margin:35px auto;padding:28px;background:#fff;border:1px solid #ddd;border-radius:8px}.q{padding:18px 0;border-bottom:1px solid #eee}.q:last-child{border-bottom:0}.q-title{font-weight:700;margin-bottom:10px}.required{color:#d33;font-size:12px;margin-left:5px}.field{width:100%;padding:10px;border:1px solid #bbb;border-radius:5px;font:inherit}.choice{display:block;margin:9px 0}.btn{border:1px solid #2788d9;background:#2788d9;color:#fff;border-radius:5px;padding:10px 16px;font:inherit;cursor:pointer}.btn:disabled{opacity:.6;cursor:not-allowed}.notice{padding:12px;border:1px solid #ddd;border-radius:5px;margin:15px 0}.error{border-color:#d9534f;color:#9e2420;background:#fff}.success{border-color:#39a866;color:#196d3b}.actions{text-align:right;margin-top:20px}</style></head><body><main class="respondent"><div id="app"><p>回答画面を読み込んでいます。</p></div></main><script>document.addEventListener('DOMContentLoaded',function(){const csrf='<?=$csrf?>',token=<?=json_encode($tokenParam,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,app=document.getElementById('app');let survey=null;let respondentRequired=false;function esc(v){const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML}async function post(api,payload){const f=new FormData();f.set('api',api);f.set('csrf',csrf);f.set('payload',JSON.stringify(payload||{}));const r=await fetch(location.href,{method:'POST',body:f});const j=await r.json();if(!j.ok)throw j;return j.data}function render(){let html='<h1>'+esc(survey.name)+'</h1><p>'+esc(survey.description||'')+'</p>';html+='<div id="info"><h2>回答者情報</h2><p class="notice">顧客登録済みの場合はメールアドレスが設定済みです。個別回答URLの場合は回答者情報を入力してください。</p><label>組織名<input id="org" class="field"></label><br><label>部署名<input id="dept" class="field"></label><br><label>氏名<input id="person" class="field"></label><br><label>メールアドレス<input id="mail" type="email" class="field"></label></div>';let n=0;for(const g of survey.groups||[]){html+='<h2>'+esc(g.name)+'</h2>';for(const q of g.questions||[]){n++;html+='<div class="q" data-q="'+esc(q.id)+'"><div class="q-title">'+esc(q.title)+(q.required?'<span class="required">必須</span>':'')+'</div>';if(q.type==='text')html+='<textarea class="field" data-answer="'+esc(q.id)+'"></textarea>';else for(const c of q.choices||[])html+='<label class="choice"><input type="'+(q.type==='single'?'radio':'checkbox')+'" name="q_'+esc(q.id)+'" value="'+esc(c.id)+'" data-answer="'+esc(q.id)+'"> '+esc(c.label)+'</label>';html+='</div>'}}html+='<div id="msg"></div><div class="actions"><button id="submit" class="btn">回答を送信する</button></div>';app.innerHTML=html;const btn=document.getElementById('submit');if(btn)btn.addEventListener('click',submit)}function collect(){const answers={};for(const q of survey.groups.flatMap(g=>g.questions||[])){if(q.type==='text'){const el=document.querySelector('[data-answer="'+CSS.escape(q.id)+'"]');answers[q.id]=el?el.value:''}else if(q.type==='single'){const el=document.querySelector('input[name="q_'+CSS.escape(q.id)+'"]:checked');answers[q.id]=el?el.value:''}else answers[q.id]=Array.from(document.querySelectorAll('input[name="q_'+CSS.escape(q.id)+'"]:checked')).map(x=>x.value)}return answers}async function submit(){const btn=document.getElementById('submit');if(!btn)return;btn.disabled=true;btn.textContent='送信中…';try{const data={token,answers:collect(),respondent:{organization:document.getElementById('org')?.value||'',department:document.getElementById('dept')?.value||'',name:document.getElementById('person')?.value||'',email:document.getElementById('mail')?.value||''}};await post('answer_submit',data);app.innerHTML='<div class="notice success"><h1>回答を受け付けました</h1><p>ご回答ありがとうございました。</p></div>'}catch(e){const m=document.getElementById('msg');if(m)m.innerHTML='<div class="notice error">'+esc(e?.error?.message||'回答の送信に失敗しました。')+'</div>';btn.disabled=false;btn.textContent='回答を送信する'}}post('answer_start',{}).then(d=>{survey=d.survey;respondentRequired=d.token.customerId===null;render()}).catch(e=>{app.innerHTML='<div class="notice error">'+esc(e?.error?.message||'回答画面を表示できません。')+'</div>'})});</script></body></html><?php exit;
}

$csrf = h($_SESSION['survey_csrf']);
$settings = read_json_file('settings');
?><!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h(APP_NAME)?></title><style>
*{box-sizing:border-box}html,body{margin:0;background:#f5f6f8;color:#222;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif;font-size:14px}button,input,textarea,select{font:inherit}button{cursor:pointer}.header{position:sticky;top:0;z-index:20;background:#243447;color:#fff;min-height:62px;display:flex;align-items:center;gap:26px;padding:0 22px;box-shadow:0 2px 8px #0002}.logo{font-weight:700;white-space:nowrap}.nav{display:flex;gap:3px;align-self:stretch}.nav button{border:0;background:transparent;color:#dce5ed;padding:0 16px;border-bottom:3px solid transparent}.nav button.active,.nav button:hover{background:#30485d;color:#fff}.page{display:none;max-width:1280px;margin:auto;padding:26px 26px 80px}.page.active{display:block}.title{font-size:25px;margin:0 0 18px}.toolbar{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}.card{background:#fff;border:1px solid #ddd;border-radius:7px;padding:18px;margin-bottom:16px}.grid{display:grid;grid-template-columns:180px 1fr;gap:11px 18px;align-items:center}.grid>label{font-weight:700}.grid input,.grid textarea,.grid select,.field{width:100%;padding:9px;border:1px solid #ccc;border-radius:5px;background:#fff}.grid textarea{min-height:90px}.btn{border:1px solid #2788d9;background:#2788d9;color:#fff;border-radius:5px;padding:8px 14px;min-height:36px}.btn:hover{background:#176faf}.btn.secondary{background:#fff;color:#333;border-color:#bbb}.btn.success{background:#198754;border-color:#198754}.btn.warning{background:#e08a00;border-color:#e08a00}.btn.danger{background:#d9534f;border-color:#d9534f}.btn:disabled{opacity:.55;cursor:not-allowed}.btn.loading:before{content:' ';display:inline-block;width:12px;height:12px;border:2px solid #fff;border-right-color:transparent;border-radius:50%;animation:spin .7s linear infinite;margin-right:6px;vertical-align:-2px}@keyframes spin{to{transform:rotate(360deg)}}table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #ddd}th,td{padding:10px;border-bottom:1px solid #e6e6e6;text-align:left;vertical-align:middle}th{background:#f7f8fa;white-space:nowrap}.badge{display:inline-block;padding:4px 8px;border-radius:12px;font-size:12px}.draft{background:#eee;color:#555}.open{background:#d9f4e3;color:#18733c}.closed{background:#e5e5e5;color:#666}.sent{background:#dbeeff;color:#176da8}.error{background:#ffe0de;color:#a52d28}.pending{background:#fff0c9;color:#8a6200}.actions{display:flex;gap:6px;flex-wrap:wrap}.message{padding:12px;border:1px solid #d9534f;color:#8e211d;background:#fff;margin-bottom:15px;white-space:pre-line}.message.success{border-color:#39a866;color:#196d3b}.group{border:1px solid #ccc;border-radius:7px;margin-bottom:14px;overflow:hidden}.group-head{display:flex;justify-content:space-between;align-items:center;padding:12px;background:#f2f5f8}.q{margin:12px;border:1px solid #ddd;border-radius:6px;padding:12px}.qhead{display:flex;gap:8px;align-items:center}.qbody{padding-top:10px}.choice-row{display:flex;gap:7px;margin:6px 0}.choice-row input{flex:1}.two{display:grid;grid-template-columns:1fr 1fr;gap:16px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.stat{background:#fff;border:1px solid #ddd;border-radius:7px;padding:15px}.stat .n{font-size:27px;font-weight:700;margin-top:4px}.tabs{display:flex;gap:3px;border-bottom:1px solid #ccc;margin-bottom:16px}.tab{padding:10px 16px;border:1px solid #ddd;border-bottom:0;background:#eee;border-radius:5px 5px 0 0;cursor:pointer}.tab.active{background:#fff;color:#1673b8;font-weight:700}.hidden{display:none!important}.modal{display:none;position:fixed;inset:0;background:#0007;z-index:50;align-items:center;justify-content:center;padding:20px}.modal.show{display:flex}.modal-box{background:#fff;border-radius:8px;padding:22px;width:min(650px,100%);max-height:90vh;overflow:auto}.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}.url{display:flex;gap:8px}.url input{flex:1;padding:9px}.toast{position:fixed;left:20px;right:20px;bottom:20px;z-index:100;max-width:850px;margin:auto;background:#fff;border:2px solid #d9534f;padding:12px 15px;border-radius:6px;box-shadow:0 4px 15px #0002}.toast.ok{border-color:#39a866}.small{font-size:12px;color:#666}.empty{text-align:center;color:#777;padding:35px}.log-error{background:#fff7f6}@media(max-width:900px){.header{flex-wrap:wrap;padding:9px 12px}.nav{width:100%;overflow:auto;height:43px}.nav button{padding:0 12px}.page{padding:18px 12px 60px}.grid,.two{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}table{font-size:12px}}
</style></head><body>
<header class="header"><div class="logo">📋 <?=h(APP_NAME)?></div><nav class="nav"><button data-page="surveys" class="active">アンケート一覧</button><button data-page="customers">顧客一覧</button><button data-page="settings">設定</button></nav></header>
<main>
<section class="page active" id="page-surveys"><div class="toolbar"><h1 class="title">アンケート一覧</h1><button class="btn" id="newSurvey">＋ 新規アンケート作成</button></div><div id="surveyTable"></div></section>
<section class="page" id="page-editor"><div class="toolbar"><h1 class="title" id="editorTitle">アンケート作成</h1><div class="actions"><button class="btn secondary" id="backList">一覧へ戻る</button><button class="btn" id="saveSurvey">下書き保存</button><button class="btn success" id="publishEditor">公開する</button></div></div><div id="editorMsg"></div><div class="card"><h2>基本情報</h2><div class="grid"><label>アンケート名</label><input id="sName"><label>説明</label><textarea id="sDesc"></textarea><label>開始日時</label><input id="sStart" type="datetime-local"><label>終了日時</label><input id="sEnd" type="datetime-local"><label>質問番号形式</label><select id="sNumber"><option value="group">グループごとに Q1-1、Q1-2</option><option value="global">全体で Q1、Q2</option></select></div></div><div class="card"><div class="toolbar"><h2>質問・グループ</h2><button class="btn secondary" id="addGroup">＋ グループを追加</button></div><div id="groups"></div></div></section>
<section class="page" id="page-detail"><div class="toolbar"><div><h1 class="title" id="detailTitle"></h1><div id="detailStatus"></div></div><div class="actions"><button class="btn secondary" id="detailEdit">編集</button><button class="btn warning" id="detailClose">終了する</button></div></div><div class="tabs"><button class="tab active" data-tab="content">アンケート内容</button><button class="tab" data-tab="send">回答依頼</button><button class="tab" data-tab="status">回答状況</button><button class="tab" data-tab="result">回答結果</button><button class="tab" data-tab="summary">集計</button></div><div id="detailContent"></div></section>
<section class="page" id="page-customers"><div class="toolbar"><h1 class="title">顧客一覧</h1><button class="btn" id="addCustomer">＋ 顧客を追加</button></div><div class="card"><input id="customerSearch" class="field" placeholder="顧客名・メールアドレスで検索"></div><div id="customerTable"></div></section>
<section class="page" id="page-settings"><h1 class="title">設定</h1><div class="tabs"><button class="tab active" data-setting="smtp">SMTP設定</button><button class="tab" data-setting="kintone">kintone設定</button><button class="tab" data-setting="mapping">kintone項目マッピング</button></div><div id="settingsContent"></div></section>
</main><div id="modal" class="modal"><div class="modal-box" id="modalBox"></div></div><div id="toast" class="toast hidden"></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
const CSRF='<?=$csrf?>';let state={surveys:[],customers:[],responses:[],sendLogs:[],settings:{},mapping:{}};let currentSurveyId=null;let editorSurvey=null;let detailTab='content';let settingTab='smtp';
const $=id=>document.getElementById(id);function esc(v){const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML}function toast(msg,ok=false){const t=$('toast');if(!t)return;t.textContent=msg;t.className='toast'+(ok?' ok':'');clearTimeout(window.__toast);window.__toast=setTimeout(()=>t.className='toast hidden',4500)}
async function api(apiName,payload={},method='POST'){const f=new FormData();f.set('api',apiName);f.set('csrf',CSRF);if(method==='POST')f.set('payload',JSON.stringify(payload));const url=method==='GET'?location.pathname+'?api='+encodeURIComponent(apiName)+(payload.query||''):'?api='+encodeURIComponent(apiName);const r=await fetch(url,{method,body:method==='POST'?f:undefined});let j;try{j=await r.json()}catch(_){throw {error:{message:'サーバーから正しい応答を受信できませんでした。'}}}if(!j.ok)throw j;return j.data}
function setLoading(btn,on){if(!btn)return;btn.disabled=on;btn.classList.toggle('loading',on)}function showPage(name){document.querySelectorAll('.page').forEach(x=>x.classList.remove('active'));const p=$('page-'+name);if(p)p.classList.add('active');document.querySelectorAll('.nav button').forEach(x=>x.classList.toggle('active',x.dataset.page===name))}
async function loadState(){try{const d=await api('state',{},'GET');state=d;renderSurveys();renderCustomers();if(settingTab)renderSettings()}catch(e){toast(e?.error?.message||'データを読み込めませんでした。')}}
function statusBadge(s){return '<span class="badge '+(s==='published'?'open':s==='closed'?'closed':'draft')+'">'+(s==='published'?'公開中':s==='closed'?'終了':'下書き')+'</span>'}
function renderSurveys(){const box=$('surveyTable');if(!box)return;if(!state.surveys.length){box.innerHTML='<div class="card empty">アンケートはまだありません。</div>';return}let h='<table><thead><tr><th>アンケート名</th><th>状態</th><th>開始</th><th>終了</th><th>回答数</th><th>操作</th></tr></thead><tbody>';for(const s of state.surveys){const count=state.responses.filter(r=>r.surveyId===s.id).length;h+='<tr><td>'+esc(s.name)+'</td><td>'+statusBadge(s.status)+'</td><td>'+esc(fmt(s.startAt))+'</td><td>'+esc(fmt(s.endAt))+'</td><td>'+count+'</td><td class="actions"><button class="btn small secondary" data-act="detail" data-id="'+esc(s.id)+'">詳細</button><button class="btn small secondary" data-act="edit" data-id="'+esc(s.id)+'">編集</button>'+(s.status==='draft'?'<button class="btn small success" data-act="publish" data-id="'+esc(s.id)+'">公開</button>':'')+(s.status==='published'?'<button class="btn small warning" data-act="close" data-id="'+esc(s.id)+'">終了</button>':'')+'<button class="btn small danger" data-act="delete" data-id="'+esc(s.id)+'">削除</button></td></tr>'}h+='</tbody></table>';box.innerHTML=h}
function fmt(v){if(!v)return '';const d=new Date(v);return isNaN(d)?v:d.toLocaleString('ja-JP',{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'})}
function blankSurvey(){return {id:'',name:'',description:'',status:'draft',startAt:'',endAt:'',numberingFormat:'group',groups:[{id:'',name:'グループ1',questions:[]}],createdAt:'',updatedAt:''}}
function openEditor(id=''){editorSurvey=id?JSON.parse(JSON.stringify(state.surveys.find(s=>s.id===id))):blankSurvey();currentSurveyId=id||null;$('editorTitle').textContent=id?'アンケート編集':'アンケート作成';$('sName').value=editorSurvey.name||'';$('sDesc').value=editorSurvey.description||'';$('sStart').value=(editorSurvey.startAt||'').slice(0,16);$('sEnd').value=(editorSurvey.endAt||'').slice(0,16);$('sNumber').value=editorSurvey.numberingFormat||'group';renderGroups();$('editorMsg').innerHTML='';showPage('editor')}
function renderGroups(){const box=$('groups');if(!box)return;let h='';editorSurvey.groups.forEach((g,gi)=>{h+='<div class="group" data-gi="'+gi+'"><div class="group-head"><input class="field gname" value="'+esc(g.name)+'" data-gi="'+gi+'"><div class="actions"><button class="btn small secondary" data-act="addq" data-gi="'+gi+'">＋ 質問</button><button class="btn small danger" data-act="delg" data-gi="'+gi+'">削除</button></div></div>';if(!g.questions.length)h+='<div class="empty">質問はありません。</div>';g.questions.forEach((q,qi)=>{h+='<div class="q" data-gi="'+gi+'" data-qi="'+qi+'"><div class="qhead"><strong>Q'+(qi+1)+'</strong><span class="small">'+esc(q.type)+'</span><div style="margin-left:auto" class="actions"><button class="btn small danger" data-act="delq">削除</button></div></div><div class="qbody"><input class="field qtitle" value="'+esc(q.title)+'" placeholder="質問文"><label><input type="checkbox" class="qreq" '+(q.required?'checked':'')+'> 必須回答</label>';if(q.type!=='text'){h+='<div class="choices">';(q.choices||[]).forEach((c,ci)=>{h+='<div class="choice-row"><input class="field cid" value="'+esc(c.id)+'" placeholder="choiceId"><input class="field clabel" value="'+esc(c.label)+'" placeholder="選択肢"><button class="btn small danger" data-act="delc">削除</button></div>'});h+='<button class="btn small secondary" data-act="addc">＋ 選択肢</button></div>'}h+='<div style="margin-top:9px"><select class="qtype"><option value="text" '+(q.type==='text'?'selected':'')+'>自由記述</option><option value="single" '+(q.type==='single'?'selected':'')+'>単一選択</option><option value="multiple" '+(q.type==='multiple'?'selected':'')+'>複数選択</option></select></div>';if(q.type==='single'){h+='<div style="margin-top:9px"><strong>分岐</strong><div class="small">選択肢ごとに next / end / question:質問ID を指定</div>';for(const c of q.choices||[]){h+='<div class="choice-row"><span style="width:120px">'+esc(c.label)+'</span><input class="field branch" data-choice="'+esc(c.id)+'" value="'+esc(q.branch?.[c.id]||'next')+'"></div>'}h+='</div>'}h+='</div></div>'});h+='</div>'});box.innerHTML=h}
function collectEditor(){const groups=[];document.querySelectorAll('#groups .group').forEach((ge,gi)=>{const g=editorSurvey.groups[gi];const ng={...g,name:ge.querySelector('.gname')?.value.trim()||'グループ',questions:[]};ge.querySelectorAll('.q').forEach((qe,qi)=>{const old=g.questions[qi]||{id:'',type:'text',choices:[],branch:{}};const type=qe.querySelector('.qtype')?.value||'text';const q={...old,title:qe.querySelector('.qtitle')?.value.trim()||'',required:!!qe.querySelector('.qreq')?.checked,type,choices:[],branch:{}};qe.querySelectorAll('.choice-row').forEach(row=>{const cid=row.querySelector('.cid');const cl=row.querySelector('.clabel');if(cid&&cl)q.choices.push({id:cid.value.trim(),label:cl.value.trim()})});qe.querySelectorAll('.branch').forEach(b=>q.branch[b.dataset.choice]=b.value.trim());ng.questions.push(q)});groups.push(ng)});return {...editorSurvey,name:$('sName').value.trim(),description:$('sDesc').value,startAt:$('sStart').value,endAt:$('sEnd').value,numberingFormat:$('sNumber').value,groups}}
async function saveSurvey(publish=false){const btn=publish?$('publishEditor'):$('saveSurvey');setLoading(btn,true);try{const survey=collectEditor();const d=await api('survey_save',{survey});editorSurvey=d.survey;currentSurveyId=editorSurvey.id;if(publish){await api('survey_publish',{surveyId:editorSurvey.id})}await loadState();toast(publish?'アンケートを公開しました。':'下書きを保存しました。',true);if(publish)openDetail(editorSurvey.id,'content');else openEditor(editorSurvey.id)}catch(e){$('editorMsg').innerHTML='<div class="message">'+esc(e?.error?.message||'保存に失敗しました。')+'</div>'}finally{setLoading(btn,false)}}
function openDetail(id,tab='content'){currentSurveyId=id;detailTab=tab;const s=state.surveys.find(x=>x.id===id);if(!s)return;$('detailTitle').textContent=s.name;$('detailStatus').innerHTML=statusBadge(s.status);$('detailClose').classList.toggle('hidden',s.status!=='published');renderDetail();showPage('detail')}
function renderDetail(){document.querySelectorAll('#page-detail .tab').forEach(t=>t.classList.toggle('active',t.dataset.tab===detailTab));const s=state.surveys.find(x=>x.id===currentSurveyId);const box=$('detailContent');if(!s||!box)return;let h='';if(detailTab==='content'){h='<div class="card"><p>'+esc(s.description||'')+'</p>';for(const g of s.groups||[]){h+='<h2>'+esc(g.name)+'</h2>';for(const q of g.questions||[]){h+='<div class="q"><strong>'+esc(q.title)+'</strong> <span class="small">'+esc(q.type)+(q.required?'・必須':'')+'</span>';if(q.choices?.length)h+='<ul>'+q.choices.map(c=>'<li>'+esc(c.label)+'</li>').join('')+'</ul>';h+='</div>'}}h+='</div>'}else if(detailTab==='send'){h=sendView(s)}else if(detailTab==='status'){h=statusView(s)}else if(detailTab==='result'){h=resultView(s)}else{h=summaryView(s)}box.innerHTML=h}
function sendView(s){const active=state.customers.filter(c=>c.status==='active');let h='<div class="card"><h2>通常回答者への回答依頼</h2><div class="small">送信済みの対象には二重送信しません。</div><div style="max-height:300px;overflow:auto;border:1px solid #ddd;margin-top:12px">';for(const c of active)h+='<label style="display:block;padding:9px;border-bottom:1px solid #eee"><input type="checkbox" class="sendCustomer" value="'+esc(c.customerId)+'"> '+esc(c.name)+' / '+esc(c.email)+'</label>';h+='</div><div style="margin-top:12px"><button class="btn" id="sendSelected">回答依頼を送信する</button> <button class="btn secondary" id="issueIndividual">個別回答URLを発行</button></div></div><div class="card"><h2>送信ログ</h2><div id="sendLogs">'+sendLogsTable(s.id)+'</div></div>';return h}
function sendLogsTable(sid){const logs=state.sendLogs.filter(l=>l.surveyId===sid);if(!logs.length)return '<div class="empty">送信ログはありません。</div>';let h='<table><thead><tr><th>メール</th><th>送信日時</th><th>結果</th><th>エラー</th><th>操作</th></tr></thead><tbody>';for(const l of logs){h+='<tr class="'+(l.status==='error'?'log-error':'')+'"><td>'+esc(l.email)+'</td><td>'+esc(fmt(l.sentAt))+'</td><td><span class="badge '+(l.status==='sent'?'sent':'error')+'">'+(l.status==='sent'?'送信済み':'送信失敗')+'</span></td><td>'+esc(l.error||'—')+'</td><td>'+(l.status==='error'?'<button class="btn small" data-resend="'+esc(l.logId)+'">再送</button>':'—')+'</td></tr>'}return h+'</tbody></table>'}
function statusView(s){const logs=state.sendLogs.filter(l=>l.surveyId===s.id);const responses=state.responses.filter(r=>r.surveyId===s.id);const answered=new Set(responses.map(r=>r.customerId||r.tokenId));let h='<div class="stats"><div class="stat"><div>送信対象</div><div class="n">'+logs.length+'</div></div><div class="stat"><div>送信済み</div><div class="n">'+logs.filter(l=>l.status==='sent').length+'</div></div><div class="stat"><div>回答済み</div><div class="n">'+responses.length+'</div></div><div class="stat"><div>未回答</div><div class="n">'+Math.max(0,logs.filter(l=>l.status==='sent').length-responses.length)+'</div></div></div><div class="card"><table><thead><tr><th>対象者</th><th>メール</th><th>送信</th><th>回答</th></tr></thead><tbody>';for(const l of logs){const r=responses.find(x=>x.customerId===l.customerId||x.tokenId===l.tokenId);h+='<tr><td>'+esc((state.customers.find(c=>c.customerId===l.customerId)?.name)||l.email)+'</td><td>'+esc(l.email)+'</td><td>'+esc(fmt(l.sentAt))+'</td><td>'+(r?'<span class="badge open">回答済み</span> '+esc(fmt(r.answeredAt)):'<span class="badge pending">未回答</span>')+'</td></tr>'}h+='</tbody></table></div>';return h}
function resultView(s){const rows=state.responses.filter(r=>r.surveyId===s.id);if(!rows.length)return '<div class="card empty">回答結果はまだありません。</div>';let h='<div class="card"><table><thead><tr><th>回答者</th><th>組織</th><th>メール</th><th>回答日時</th><th>回答内容</th></tr></thead><tbody>';for(const r of rows){h+='<tr><td>'+esc(r.respondent?.name||'—')+'</td><td>'+esc(r.respondent?.organization||'—')+'</td><td>'+esc(r.respondent?.email||'—')+'</td><td>'+esc(fmt(r.answeredAt))+'</td><td><button class="btn small secondary" data-response="'+esc(r.responseId)+'">表示</button></td></tr>'}return h+'</tbody></table></div>'}
function summaryView(s){const rows=state.responses.filter(r=>r.surveyId===s.id);const flat=s.groups.flatMap(g=>g.questions||[]);let h='<div class="stats"><div class="stat"><div>回答数</div><div class="n">'+rows.length+'</div></div><div class="stat"><div>送信済み</div><div class="n">'+state.sendLogs.filter(l=>l.surveyId===s.id&&l.status==='sent').length+'</div></div><div class="stat"><div>未回答</div><div class="n">'+Math.max(0,state.sendLogs.filter(l=>l.surveyId===s.id&&l.status==='sent').length-rows.length)+'</div></div><div class="stat"><div>回答率</div><div class="n">'+(state.sendLogs.filter(l=>l.surveyId===s.id&&l.status==='sent').length?Math.round(rows.length/state.sendLogs.filter(l=>l.surveyId===s.id&&l.status==='sent').length*100):0)+'%</div></div></div>';for(const q of flat){if(q.type==='text')continue;const counts={};for(const c of q.choices||[])counts[c.id]=0;for(const r of rows){const v=r.answers?.[q.id];const vals=Array.isArray(v)?v:[v];for(const x of vals)if(Object.prototype.hasOwnProperty.call(counts,x))counts[x]++}h+='<div class="card"><h3>'+esc(q.title)+'</h3>';for(const c of q.choices||[])h+='<div style="margin:7px 0">'+esc(c.label)+'：'+counts[c.id]+'件</div>';h+='</div>'}return h}
function renderCustomers(){const box=$('customerTable');const kw=($('customerSearch')?.value||'').toLowerCase();const rows=state.customers.filter(c=>(c.name+' '+c.email).toLowerCase().includes(kw));if(!rows.length){box.innerHTML='<div class="card empty">顧客はありません。</div>';return}let h='<table><thead><tr><th>顧客名</th><th>メール</th><th>状態</th><th>kintoneレコード</th><th>操作</th></tr></thead><tbody>';for(const c of rows)h+='<tr><td>'+esc(c.name)+'</td><td>'+esc(c.email)+'</td><td>'+esc(c.status==='active'?'有効':'無効')+'</td><td>'+esc(c.kintoneRecordId||'—')+'</td><td class="actions"><button class="btn small secondary" data-customer-edit="'+esc(c.customerId)+'">編集</button><button class="btn small danger" data-customer-del="'+esc(c.customerId)+'">無効化</button></td></tr>';box.innerHTML=h+'</tbody></table>'}
function renderSettings(){const box=$('settingsContent');if(settingTab==='smtp'){const s=state.settings.smtp||{};box.innerHTML='<div class="card"><h2>SMTP設定</h2><div class="grid"><label>ホスト</label><input id="smtpHost" value="'+esc(s.host||'')+'"><label>ポート</label><input id="smtpPort" value="'+esc(s.port||'587')+'"><label>暗号化</label><select id="smtpEnc"><option value="none" '+(s.encryption==='none'?'selected':'')+'>なし</option><option value="tls" '+(s.encryption==='tls'?'selected':'')+'>TLS</option><option value="ssl" '+(s.encryption==='ssl'?'selected':'')+'>SSL</option></select><label>ユーザー名</label><input id="smtpUser" value="'+esc(s.username||'')+'"><label>パスワード</label><input id="smtpPass" type="password" value="" placeholder="保存済み（変更時のみ入力）"><label>送信元メール</label><input id="smtpFrom" value="'+esc(s.fromEmail||'')+'"><label>送信元名</label><input id="smtpFromName" value="'+esc(s.fromName||APP_NAME)+'"></div><div class="actions" style="margin-top:16px"><button class="btn" id="saveSmtp">保存する</button><button class="btn secondary" id="testSmtp">接続確認</button></div><div class="small" style="margin-top:10px">接続確認の宛先メールを指定すると、実際のテストメールも送信します。</div><input id="smtpTestTo" class="field" style="margin-top:7px" placeholder="テストメール宛先（任意）"></div>'}else if(settingTab==='kintone'){const k=state.settings.kintone||{};box.innerHTML='<div class="card"><h2>kintone設定</h2><div class="grid"><label>サブドメイン</label><input id="kSub" value="'+esc(k.subdomain||'')+'"><label>アプリID</label><input id="kApp" value="'+esc(k.appId||'')+'"><label>ログイン</label><input id="kLogin" value="'+esc(k.login||'')+'"><label>パスワード</label><input id="kPass" type="password" value="" placeholder="保存済み（変更時のみ入力）"><label>プロキシ host:port</label><input id="kProxy" value="'+esc(k.proxyHostPort||'')+'"><label>SSL証明書検証</label><label><input id="kVerify" type="checkbox" '+(k.verifySsl?'checked':'')+'> 検証する</label></div><div class="actions" style="margin-top:16px"><button class="btn" id="saveK">保存する</button><button class="btn secondary" id="testK">接続確認</button><button class="btn secondary" id="fieldsK">項目を取得</button></div></div>'}else{const m=state.mapping||{};const fields=m.fields||{};let opts='<option value="">選択してください</option>';for(const [code,f] of Object.entries(fields))opts+='<option value="'+esc(code)+'">'+esc(f.label||code)+' ['+esc(f.type||'')+']</option>';box.innerHTML='<div class="card"><h2>kintone項目マッピング</h2><p class="small">顧客名・メールアドレスは必須です。項目取得後、field codeを保存します。</p><div class="grid"><label>顧客名 *</label><select id="mapName">'+opts+'</select><label>メールアドレス *</label><select id="mapEmail">'+opts+'</select><label>住所</label><select id="mapAddress">'+opts+'</select><label>電話番号</label><select id="mapPhone">'+opts+'</select></div><div class="actions" style="margin-top:16px"><button class="btn" id="saveMap">マッピングを保存</button></div><p class="small">取得日時：'+esc(m.fetchedAt||'未取得')+' / schemaHash：'+esc(m.schemaHash||'未取得')+'</p></div>';setTimeout(()=>{if(m.mappings){for(const [id,key] of [['mapName','name'],['mapEmail','email'],['mapAddress','address'],['mapPhone','phone']])if($(id))$(id).value=m.mappings[key]||''}},0)}}
function openModal(html){$('modalBox').innerHTML=html;$('modal').classList.add('show')}function closeModal(){$('modal').classList.remove('show')}
function customerModal(c){openModal('<h2>顧客'+(c?'編集':'追加')+'</h2><div class="grid"><label>顧客名</label><input id="mcName" value="'+esc(c?.name||'')+'"><label>メール</label><input id="mcEmail" value="'+esc(c?.email||'')+'"></div><div class="modal-actions"><button class="btn secondary" id="mcCancel">キャンセル</button><button class="btn" id="mcSave">保存</button></div>');$('mcCancel').addEventListener('click',closeModal);$('mcSave').addEventListener('click',async()=>{const b=$('mcSave');setLoading(b,true);try{await api('customer_save',{customer:{customerId:c?.customerId||'',name:$('mcName').value,email:$('mcEmail').value,status:c?.status||'active'}});closeModal();await loadState();toast('顧客を保存しました。',true)}catch(e){toast(e?.error?.message||'顧客保存に失敗しました。')}finally{setLoading(b,false)}})}
$('newSurvey').addEventListener('click',()=>openEditor());$('backList').addEventListener('click',()=>{showPage('surveys');loadState()});$('saveSurvey').addEventListener('click',()=>saveSurvey(false));$('publishEditor').addEventListener('click',()=>saveSurvey(true));$('addGroup').addEventListener('click',()=>{editorSurvey.groups.push({id:'',name:'新しいグループ',questions:[]});renderGroups()});
$('groups').addEventListener('input',e=>{if(e.target.classList.contains('gname')){}});$('groups').addEventListener('change',e=>{if(e.target.classList.contains('qtype')){const q=e.target.closest('.q');const gi=+q.dataset.gi,qi=+q.dataset.qi;editorSurvey.groups[gi].questions[qi].type=e.target.value;renderGroups()}});$('groups').addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;const act=b.dataset.act;if(act==='addq'){editorSurvey.groups[+b.dataset.gi].questions.push({id:'q_'+Date.now()+Math.random().toString(16).slice(2),title:'',type:'text',required:false,choices:[],branch:{}});renderGroups()}else if(act==='delg'){const gi=+b.dataset.gi;if(editorSurvey.groups.length>1){editorSurvey.groups.splice(gi,1);renderGroups()}}else if(act==='delq'){const q=b.closest('.q');editorSurvey.groups[+q.dataset.gi].questions.splice(+q.dataset.qi,1);renderGroups()}else if(act==='addc'){const q=b.closest('.q');const qq=editorSurvey.groups[+q.dataset.gi].questions[+q.dataset.qi];qq.choices.push({id:'choice_'+Date.now(),label:''});renderGroups()}else if(act==='delc'){const row=b.closest('.choice-row');const q=b.closest('.q');const qq=editorSurvey.groups[+q.dataset.gi].questions[+q.dataset.qi];const ci=Array.from(q.querySelectorAll('.choice-row')).indexOf(row);qq.choices.splice(ci,1);renderGroups()}});
$('surveyTable').addEventListener('click',async e=>{const b=e.target.closest('button');if(!b)return;const id=b.dataset.id;if(b.dataset.act==='detail')openDetail(id);if(b.dataset.act==='edit')openEditor(id);if(b.dataset.act==='publish'){try{await api('survey_publish',{surveyId:id});await loadState();toast('アンケートを公開しました。',true)}catch(x){toast(x?.error?.message||'公開に失敗しました。')}}if(b.dataset.act==='close'){try{await api('survey_close',{surveyId:id});await loadState();toast('アンケートを終了しました。',true)}catch(x){toast(x?.error?.message||'終了に失敗しました。')}}if(b.dataset.act==='delete'&&confirm('このアンケートを削除しますか？')){try{await api('survey_delete',{surveyId:id});await loadState();toast('アンケートを削除しました。',true)}catch(x){toast(x?.error?.message||'削除に失敗しました。')}}});
$('page-detail').addEventListener('click',async e=>{const t=e.target.closest('.tab');if(t){detailTab=t.dataset.tab;renderDetail();return}const b=e.target.closest('button');if(!b)return;if(b.id==='sendSelected'){const ids=Array.from(document.querySelectorAll('.sendCustomer:checked')).map(x=>x.value);if(!ids.length){toast('送信対象を選択してください。');return}setLoading(b,true);try{const d=await api('send_mail',{surveyId:currentSurveyId,recipients:ids});await loadState();renderDetail();toast('送信済み '+d.sent+'件、失敗 '+d.failed+'件',d.failed===0)}catch(x){toast(x?.error?.message||'メール送信に失敗しました。')}finally{setLoading(b,false)}}if(b.id==='issueIndividual'){openModal('<h2>個別回答URLを発行</h2><div class="grid"><label>メールアドレス</label><input id="indMail" type="email"><label>URL</label><input id="indUrl" readonly></div><div class="modal-actions"><button class="btn secondary" id="indClose">閉じる</button><button class="btn" id="indIssue">発行</button></div>');$('indClose').addEventListener('click',closeModal);$('indIssue').addEventListener('click',async()=>{try{const d=await api('issue_token',{surveyId:currentSurveyId,email:$('indMail').value});$('indUrl').value=d.url;toast('個別回答URLを発行しました。',true)}catch(x){toast(x?.error?.message||'URL発行に失敗しました。')}})}if(b.dataset.resend){try{await api('resend_failed',{logId:b.dataset.resend});await loadState();renderDetail();toast('再送しました。',true)}catch(x){toast(x?.error?.message||'再送に失敗しました。')}}if(b.dataset.response){const r=state.responses.find(x=>x.responseId===b.dataset.response);if(r)openModal('<h2>回答内容</h2><pre style="white-space:pre-wrap">'+esc(JSON.stringify(r,null,2))+'</pre><div class="modal-actions"><button class="btn" id="respClose">閉じる</button></div>');$('respClose')?.addEventListener('click',closeModal)}});
$('detailEdit').addEventListener('click',()=>openEditor(currentSurveyId));$('detailClose').addEventListener('click',async()=>{try{await api('survey_close',{surveyId:currentSurveyId});await loadState();openDetail(currentSurveyId)}catch(e){toast(e?.error?.message||'終了に失敗しました。')}});
$('customerSearch').addEventListener('input',renderCustomers);$('addCustomer').addEventListener('click',()=>customerModal(null));$('customerTable').addEventListener('click',async e=>{const b=e.target.closest('button');if(!b)return;if(b.dataset.customerEdit){const c=state.customers.find(x=>x.customerId===b.dataset.customerEdit);if(c)customerModal(c)}if(b.dataset.customerDel){if(!confirm('この顧客を無効化しますか？'))return;try{await api('customer_delete',{customerId:b.dataset.customerDel});await loadState();toast('顧客を無効化しました。',true)}catch(x){toast(x?.error?.message||'顧客更新に失敗しました。')}}});
document.querySelectorAll('.nav button').forEach(b=>b.addEventListener('click',()=>{showPage(b.dataset.page);if(b.dataset.page==='surveys')renderSurveys();if(b.dataset.page==='customers')renderCustomers();if(b.dataset.page==='settings')renderSettings()}));document.querySelectorAll('#page-settings .tab').forEach(b=>b.addEventListener('click',()=>{settingTab=b.dataset.setting;document.querySelectorAll('#page-settings .tab').forEach(x=>x.classList.toggle('active',x===b));renderSettings()}));
$('settingsContent').addEventListener('click',async e=>{const b=e.target.closest('button');if(!b)return;if(b.id==='saveSmtp'){setLoading(b,true);try{await api('smtp_save',{smtp:{host:$('smtpHost').value,port:$('smtpPort').value,encryption:$('smtpEnc').value,username:$('smtpUser').value,password:$('smtpPass').value,fromEmail:$('smtpFrom').value,fromName:$('smtpFromName').value}});await loadState();toast('SMTP設定を保存しました。',true)}catch(x){toast(x?.error?.message||'SMTP設定の保存に失敗しました。')}finally{setLoading(b,false)}}if(b.id==='testSmtp'){const smtp={host:$('smtpHost').value,port:$('smtpPort').value,encryption:$('smtpEnc').value,username:$('smtpUser').value,password:$('smtpPass').value,fromEmail:$('smtpFrom').value,fromName:$('smtpFromName').value};setLoading(b,true);try{const d=await api('smtp_test',{smtp,to:$('smtpTestTo').value.trim()});toast(d.mailSent?'SMTP接続・認証とテストメール送信に成功しました。':'SMTP接続・認証に成功しました。',true)}catch(x){toast(x?.error?.message||'SMTP接続確認に失敗しました。')}finally{setLoading(b,false)}}if(b.id==='saveK'){setLoading(b,true);try{await api('kintone_save',{kintone:{subdomain:$('kSub').value,appId:$('kApp').value,login:$('kLogin').value,password:$('kPass').value,proxyHostPort:$('kProxy').value,verifySsl:$('kVerify').checked}});await loadState();toast('kintone設定を保存しました。',true)}catch(x){toast(x?.error?.message||'kintone設定の保存に失敗しました。')}finally{setLoading(b,false)}}if(b.id==='testK'){setLoading(b,true);try{const d=await api('kintone_test');toast(d.message,true)}catch(x){toast(x?.error?.message||'kintone接続確認に失敗しました。')}finally{setLoading(b,false)}}if(b.id==='fieldsK'){setLoading(b,true);try{const d=await api('kintone_fields',{appId:$('kApp').value});state.mapping=d.mapping;renderSettings();toast('kintone項目を取得しました。',true)}catch(x){toast(x?.error?.message||'kintone項目取得に失敗しました。')}finally{setLoading(b,false)}}if(b.id==='saveMap'){setLoading(b,true);try{await api('kintone_mapping_save',{mapping:{appId:state.mapping.appId,mappings:{name:$('mapName').value,email:$('mapEmail').value,address:$('mapAddress').value,phone:$('mapPhone').value}}});await loadState();toast('kintone項目マッピングを保存しました。',true)}catch(x){toast(x?.error?.message||'マッピング保存に失敗しました。')}finally{setLoading(b,false)}}});
loadState();
});
</script></body></html>

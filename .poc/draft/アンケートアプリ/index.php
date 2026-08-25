<?php
declare(strict_types=1);

/*
 * Survey Management System
 * Apache 2.4 / PHP 8.5
 *
 * DBなし:
 *   JSONファイルをデータストアとして使用
 *
 * 実装:
 *   - 管理者／回答者ルート分離
 *   - 管理者認証
 *   - CSRF
 *   - アンケートCRUD
 *   - 状態遷移
 *   - 自動終了
 *   - グループ／質問CRUD
 *   - 質問番号自動採番
 *   - 条件分岐
 *   - 回答
 *   - 個別回答URL
 *   - 回答済み制御
 *   - 顧客管理
 *   - SMTP送信
 *   - 送信履歴
 *   - kintone REST API
 *   - CSV出力
 *   - 簡易PDF出力
 *
 * 本番環境では以下を必ず実施:
 *   - HTTPS
 *   - dataディレクトリへのWebアクセス禁止
 *   - ADMIN_PASSWORD_HASHの設定
 *   - SMTP/kintone認証情報の安全な管理
 *   - サーバーバックアップ
 */

session_start();
date_default_timezone_set('Asia/Tokyo');

const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/survey.json';

const STATUS_DRAFT     = 'draft';
const STATUS_PUBLISHED = 'published';
const STATUS_STOPPED   = 'stopped';
const STATUS_ENDED     = 'ended';

const QUESTION_SINGLE   = 'single';
const QUESTION_MULTIPLE = 'multiple';
const QUESTION_TEXT     = 'text';

const DEFAULT_ADMIN_USER = 'admin';

/*
 * 初期パスワード:
 * 環境変数 ADMIN_PASSWORD があればそれを使用。
 * なければ初回導入用パスワード "change-me"。
 *
 * 本番では必ず環境変数を設定してください。
 */
const DEFAULT_ADMIN_PASSWORD = 'change-me';

/* =========================================================
 * 基本関数
 * ========================================================= */

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uid(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function baseUrl(): string
{
    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/');

    if ($path === '/' || $path === '\\') {
        $path = '';
    }

    return $scheme . '://' . $host . rtrim(str_replace('\\', '/', $path), '/');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['_flash'][] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeFlash(): array
{
    $x = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($x) ? $x : [];
}

/* =========================================================
 * CSRF
 * ========================================================= */

function csrfToken(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    $a = (string)($_POST['_csrf'] ?? '');
    $b = (string)($_SESSION['_csrf'] ?? '');

    if ($a === '' || $b === '' || !hash_equals($b, $a)) {
        http_response_code(403);
        exit('CSRF validation failed.');
    }
}

/* =========================================================
 * JSON datastore
 * ========================================================= */

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    }

    $htaccess = DATA_DIR . '/.htaccess';

    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Require all denied\n"
        );
    }
}

function defaultData(): array
{
    return [
        'surveys' => [],
        'groups' => [],
        'questions' => [],
        'choices' => [],
        'customers' => [],
        'answers' => [],
        'sendHistories' => [],
        'settings' => [
            'kintone' => [
                'subdomain' => '',
                'appId' => '',
                'loginName' => '',
                'password' => '',
                'sslVerify' => true,
                'fieldMapping' => [],
                'addressFields' => [],
            ],
            'smtp' => [
                'server' => '',
                'port' => 587,
                'encryption' => 'TLS',
                'auth' => true,
                'username' => '',
                'password' => '',
                'from' => '',
                'senderName' => '',
                'replyTo' => '',
                'status' => '未設定',
            ],
        ],
    ];
}

function loadData(): array
{
    ensureDataDir();

    if (!file_exists(DATA_FILE)) {
        $data = defaultData();
        saveData($data);
        return $data;
    }

    $raw = file_get_contents(DATA_FILE);
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        $data = defaultData();
    }

    foreach (defaultData() as $key => $value) {
        if (!isset($data[$key])) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function saveData(array $data): void
{
    ensureDataDir();

    $tmp = DATA_FILE . '.tmp';

    file_put_contents(
        $tmp,
        json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT |
            JSON_INVALID_UTF8_SUBSTITUTE
        ),
        LOCK_EX
    );

    rename($tmp, DATA_FILE);
}

/* =========================================================
 * 認証
 * ========================================================= */

function adminPassword(): string
{
    $env = getenv('ADMIN_PASSWORD');

    if ($env !== false && $env !== '') {
        return $env;
    }

    return DEFAULT_ADMIN_PASSWORD;
}

function isAdmin(): bool
{
    return !empty($_SESSION['admin']);
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        redirect('?page=login');
    }
}

function adminLogin(string $user, string $password): bool
{
    if ($user !== DEFAULT_ADMIN_USER) {
        return false;
    }

    return hash_equals(adminPassword(), $password);
}

function logout(): void
{
    unset($_SESSION['admin']);
    session_regenerate_id(true);
}

/* =========================================================
 * データ検索
 * ========================================================= */

function surveyIndex(array $data, string $id): int
{
    foreach ($data['surveys'] as $i => $s) {
        if (($s['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function survey(array $data, string $id): ?array
{
    $i = surveyIndex($data, $id);

    return $i >= 0 ? $data['surveys'][$i] : null;
}

function group(array $data, string $id): ?array
{
    foreach ($data['groups'] as $g) {
        if (($g['id'] ?? '') === $id) {
            return $g;
        }
    }

    return null;
}

function question(array $data, string $id): ?array
{
    foreach ($data['questions'] as $q) {
        if (($q['id'] ?? '') === $id) {
            return $q;
        }
    }

    return null;
}

function customer(array $data, string $id): ?array
{
    foreach ($data['customers'] as $c) {
        if (($c['id'] ?? '') === $id) {
            return $c;
        }
    }

    return null;
}

/* =========================================================
 * アンケート関連
 * ========================================================= */

function surveyAnswerCount(array $data, string $surveyId): int
{
    return count(array_filter(
        $data['answers'],
        fn($a) =>
            ($a['surveyId'] ?? '') === $surveyId
            && ($a['status'] ?? '') === 'submitted'
    ));
}

function surveyQuestions(array $data, string $surveyId): array
{
    $groups = array_values(array_filter(
        $data['groups'],
        fn($g) => ($g['surveyId'] ?? '') === $surveyId
    ));

    usort(
        $groups,
        fn($a, $b) => (int)$a['order'] <=> (int)$b['order']
    );

    $result = [];

    foreach ($groups as $g) {
        $questions = array_values(array_filter(
            $data['questions'],
            fn($q) => ($q['groupId'] ?? '') === $g['id']
        ));

        usort(
            $questions,
            fn($a, $b) => (int)$a['order'] <=> (int)$b['order']
        );

        foreach ($questions as $q) {
            $q['_groupTitle'] = $g['title'];
            $q['_groupId'] = $g['id'];
            $result[] = $q;
        }
    }

    return $result;
}

function surveyGroups(array $data, string $surveyId): array
{
    $groups = array_values(array_filter(
        $data['groups'],
        fn($g) => ($g['surveyId'] ?? '') === $surveyId
    ));

    usort(
        $groups,
        fn($a, $b) => (int)$a['order'] <=> (int)$b['order']
    );

    return $groups;
}

function questionChoices(array $data, string $questionId): array
{
    $x = array_values(array_filter(
        $data['choices'],
        fn($c) => ($c['questionId'] ?? '') === $questionId
    ));

    usort(
        $x,
        fn($a, $b) => (int)$a['order'] <=> (int)$b['order']
    );

    return $x;
}

/* =========================================================
 * 質問番号
 * ========================================================= */

function renumberSurvey(array &$data, string $surveyId): void
{
    $idx = surveyIndex($data, $surveyId);

    if ($idx < 0) {
        return;
    }

    $mode = $data['surveys'][$idx]['numberingMode'] ?? 'survey';

    $groups = surveyGroups($data, $surveyId);

    $surveyNo = 1;

    foreach ($groups as $g) {
        $groupNo = 1;

        $questions = array_values(array_filter(
            $data['questions'],
            fn($q) => ($q['groupId'] ?? '') === $g['id']
        ));

        usort(
            $questions,
            fn($a, $b) => (int)$a['order'] <=> (int)$b['order']
        );

        foreach ($questions as $q) {
            foreach ($data['questions'] as $i => $stored) {
                if ($stored['id'] === $q['id']) {
                    $data['questions'][$i]['number'] =
                        $mode === 'survey' ? $surveyNo : $groupNo;

                    $surveyNo++;
                    $groupNo++;
                    break;
                }
            }
        }
    }

    /*
     * branchRulesに保存されているtargetQuestionIdはIDを維持する。
     * 表示時に最新のnumberへ変換するため、
     * 並び替えによって条件分岐先が古い番号になることを防止する。
     */
}

/* =========================================================
 * 自動終了
 * ========================================================= */

function autoEndSurveys(array &$data): void
{
    $changed = false;
    $current = new DateTimeImmutable();

    foreach ($data['surveys'] as $i => $s) {
        if (
            ($s['status'] ?? '') !== STATUS_PUBLISHED
            || empty($s['endAt'])
        ) {
            continue;
        }

        try {
            $end = new DateTimeImmutable((string)$s['endAt']);

            if ($current > $end) {
                $data['surveys'][$i]['status'] = STATUS_ENDED;
                $data['surveys'][$i]['updatedAt'] = now();
                $changed = true;
            }
        } catch (Throwable) {
        }
    }

    if ($changed) {
        saveData($data);
    }
}

/* =========================================================
 * ラベル
 * ========================================================= */

function statusLabel(string $s): string
{
    return [
        STATUS_DRAFT => '下書き',
        STATUS_PUBLISHED => '公開中',
        STATUS_STOPPED => '停止',
        STATUS_ENDED => '終了',
    ][$s] ?? $s;
}

function typeLabel(string $t): string
{
    return [
        QUESTION_SINGLE => '単一選択',
        QUESTION_MULTIPLE => '複数選択',
        QUESTION_TEXT => '自由記述',
    ][$t] ?? $t;
}

function statusClass(string $s): string
{
    return [
        STATUS_DRAFT => 'draft',
        STATUS_PUBLISHED => 'published',
        STATUS_STOPPED => 'stopped',
        STATUS_ENDED => 'ended',
    ][$s] ?? '';
}

/* =========================================================
 * 回答状態
 * ========================================================= */

function customerSurveyStatus(array $data, string $surveyId, string $customerId): array
{
    $answers = array_values(array_filter(
        $data['answers'],
        fn($a) =>
            ($a['surveyId'] ?? '') === $surveyId
            && ($a['customerId'] ?? '') === $customerId
            && ($a['status'] ?? '') === 'submitted'
    ));

    $histories = [];

    foreach ($data['sendHistories'] as $h) {
        if (($h['surveyId'] ?? '') !== $surveyId) {
            continue;
        }

        foreach (($h['recipients'] ?? []) as $r) {
            if (($r['customerId'] ?? '') === $customerId) {
                $histories[] = $r;
            }
        }
    }

    usort(
        $histories,
        fn($a, $b) =>
            strcmp((string)($b['sentAt'] ?? ''), (string)($a['sentAt'] ?? ''))
    );

    $last = $histories[0]['sentAt'] ?? null;

    if ($answers) {
        return [
            'status' => '回答済み',
            'lastSentAt' => $last,
            'sendCount' => count($histories),
        ];
    }

    if ($histories) {
        return [
            'status' => '送信済み／未回答',
            'lastSentAt' => $last,
            'sendCount' => count($histories),
        ];
    }

    return [
        'status' => '未送信',
        'lastSentAt' => null,
        'sendCount' => 0,
    ];
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtpRead($fp): string
{
    $result = '';

    while (!feof($fp)) {
        $line = fgets($fp, 515);

        if ($line === false) {
            break;
        }

        $result .= $line;

        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return $result;
}

function smtpExpect($fp, array $codes): string
{
    $response = smtpRead($fp);
    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }

    return $response;
}

function smtpCommand($fp, string $command, array $codes): string
{
    fwrite($fp, $command . "\r\n");
    return smtpExpect($fp, $codes);
}

function smtpSendMail(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    $server = trim((string)($config['server'] ?? ''));
    $port = (int)($config['port'] ?? 587);
    $encryption = strtoupper((string)($config['encryption'] ?? 'TLS'));

    if ($server === '') {
        throw new RuntimeException('SMTPサーバが未設定です。');
    }

    $transport = 'tcp://';

    if ($encryption === 'SSL') {
        $transport = 'ssl://';
    }

    $timeout = 15;

    $fp = @stream_socket_client(
        $transport . $server . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        throw new RuntimeException(
            'SMTP接続失敗: ' . $errstr
        );
    }

    stream_set_timeout($fp, $timeout);

    try {
        smtpExpect($fp, [220]);

        smtpCommand(
            $fp,
            'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
            [250]
        );

        if ($encryption === 'TLS') {
            smtpCommand($fp, 'STARTTLS', [220]);

            $ok = stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($ok !== true) {
                throw new RuntimeException('STARTTLSに失敗しました。');
            }

            smtpCommand(
                $fp,
                'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
                [250]
            );
        }

        if (!empty($config['auth'])) {
            $username = (string)($config['username'] ?? '');
            $password = (string)($config['password'] ?? '');

            smtpCommand($fp, 'AUTH LOGIN', [334]);
            smtpCommand($fp, base64_encode($username), [334]);
            smtpCommand($fp, base64_encode($password), [235]);
        }

        $from = (string)($config['from'] ?? '');

        if ($from === '') {
            throw new RuntimeException('送信元メールアドレスが未設定です。');
        }

        smtpCommand($fp, 'MAIL FROM:<' . $from . '>', [250]);
        smtpCommand($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtpCommand($fp, 'DATA', [354]);

        $senderName = (string)($config['senderName'] ?? '');
        $fromHeader = $senderName !== ''
            ? '=?UTF-8?B?' . base64_encode($senderName) . '?= <' . $from . '>'
            : $from;

        $headers = [];
        $headers[] = 'From: ' . $fromHeader;
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        if (!empty($config['replyTo'])) {
            $headers[] = 'Reply-To: ' . $config['replyTo'];
        }

        $mail = implode("\r\n", $headers)
            . "\r\n\r\n"
            . str_replace(
                ["\r\n", "\r"],
                "\n",
                $body
            );

        $mail = str_replace(
            "\n",
            "\r\n",
            $mail
        );

        $mail = preg_replace(
            '/^\./m',
            '..',
            $mail
        );

        fwrite($fp, $mail . "\r\n.\r\n");

        smtpExpect($fp, [250]);

        smtpCommand($fp, 'QUIT', [221]);
    } finally {
        fclose($fp);
    }
}

/* =========================================================
 * kintone
 * ========================================================= */

function kintoneRequest(
    array $config,
    string $method,
    string $path,
    ?array $payload = null
): array {
    $subdomain = trim((string)($config['subdomain'] ?? ''));

    if ($subdomain === '') {
        throw new RuntimeException('kintoneサブドメインが未設定です。');
    }

    $url = 'https://' . $subdomain . '.cybozu.com' . $path;

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('cURL初期化に失敗しました。');
    }

    $headers = [
        'Content-Type: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode(
                (string)($config['loginName'] ?? '')
                . ':'
                . (string)($config['password'] ?? '')
            ),
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => !empty($config['sslVerify']),
        CURLOPT_SSL_VERIFYHOST => !empty($config['sslVerify']) ? 2 : 0,
    ]);

    if ($payload !== null) {
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($errno) {
        throw new RuntimeException(
            'kintone接続エラー: ' . $error
        );
    }

    $json = json_decode((string)$body, true);

    if ($status < 200 || $status >= 300) {
        $message = is_array($json)
            ? ($json['message'] ?? 'kintone APIエラー')
            : 'kintone APIエラー';

        throw new RuntimeException(
            'HTTP ' . $status . ': ' . $message
        );
    }

    return is_array($json) ? $json : [];
}

function kintoneTest(array $config): array
{
    $appId = (int)($config['appId'] ?? 0);

    if ($appId <= 0) {
        throw new RuntimeException('アプリIDが不正です。');
    }

    return kintoneRequest(
        $config,
        'GET',
        '/k/v1/app.json?id=' . $appId
    );
}

function kintoneFields(array $config): array
{
    $appId = (int)($config['appId'] ?? 0);

    if ($appId <= 0) {
        throw new RuntimeException('アプリIDが不正です。');
    }

    return kintoneRequest(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' . $appId
    );
}

function kintoneRecords(
    array $config,
    int $appId
): array {
    return kintoneRequest(
        $config,
        'GET',
        '/k/v1/records.json?app=' . $appId
        . '&query=' . rawurlencode('limit 500')
    );
}

/* =========================================================
 * PDF
 *
 * 外部ライブラリなしで簡易PDFを生成。
 * 日本語フォントを埋め込まないため、ASCII中心の帳票用。
 * 日本語PDFを正式運用する場合はTCPDF等の導入を推奨。
 * ========================================================= */

function pdfEscape(string $text): string
{
    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );
}

function simplePdf(array $lines): string
{
    $content = "BT\n/F1 10 Tf\n";

    $y = 800;

    foreach ($lines as $line) {
        $line = preg_replace('/[^\x20-\x7E]/', '?', (string)$line);
        $content .= "50 {$y} Td (" .
            pdfEscape($line) .
            ") Tj\n";
        $y -= 16;

        if ($y < 50) {
            break;
        }
    }

    $content .= "ET\n";

    $objects = [];

    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] =
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 4 0 R >> >> ' .
        '/Contents 5 0 R >>';
    $objects[] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[] =
        '<< /Length ' . strlen($content) . ' >>' .
        "\nstream\n" . $content . "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $obj) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n";
        $pdf .= $obj;
        $pdf .= "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .= "xref\n";
    $pdf .= "0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .= "trailer\n";
    $pdf .= "<< /Size " . (count($objects) + 1)
        . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= $xref . "\n";
    $pdf .= "%%EOF";

    return $pdf;
}

/* =========================================================
 * 初期処理
 * ========================================================= */

$data = loadData();

autoEndSurveys($data);

$flash = consumeFlash();

$page = (string)($_GET['page'] ?? 'list');

$isRespondent =
    $page === 'respond'
    || $page === 'answer'
    || $page === 'confirm'
    || $page === 'complete';

/*
 * 回答者ルートは管理者認証を要求しない。
 * 管理者ページは全て requireAdmin()。
 */

/* =========================================================
 * POST処理
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = (string)($_POST['action'] ?? '');

    /* ---------- ログイン ---------- */

    if ($action === 'login') {
        $user = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if (adminLogin($user, $password)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            redirect('?page=list');
        }

        flash('ログインに失敗しました。', 'error');
        redirect('?page=login');
    }

    /* ---------- ログアウト ---------- */

    if ($action === 'logout') {
        logout();
        redirect('?page=login');
    }

    /* ---------- 回答送信 ---------- */

    if ($action === 'submit_answer') {
        $surveyId = (string)($_POST['surveyId'] ?? '');
        $token = trim((string)($_POST['token'] ?? ''));

        $sidx = surveyIndex($data, $surveyId);

        if ($sidx < 0) {
            http_response_code(404);
            exit('アンケートが存在しません。');
        }

        $survey = $data['surveys'][$sidx];

        if (($survey['status'] ?? '') !== STATUS_PUBLISHED) {
            http_response_code(403);
            exit('このアンケートは回答できません。');
        }

        $customerId = null;

        if ($token !== '') {
            foreach ($data['customers'] as $c) {
                $expected = hash_hmac(
                    'sha256',
                    $surveyId . '|' . $c['id'],
                    'survey-secret'
                );

                if (hash_equals($expected, $token)) {
                    $customerId = $c['id'];
                    break;
                }
            }
        }

        /*
         * 公開URLの場合は顧客未登録として扱う。
         * 個別URLの場合のみcustomerIdを確定。
         */

        if ($customerId !== null) {
            $existing = array_filter(
                $data['answers'],
                fn($a) =>
                    ($a['surveyId'] ?? '') === $surveyId
                    && ($a['customerId'] ?? '') === $customerId
                    && ($a['status'] ?? '') === 'submitted'
            );

            if ($existing && empty($survey['allowResubmission'])) {
                redirect(
                    '?page=complete&surveyId='
                    . urlencode($surveyId)
                    . '&already=1'
                );
            }
        }

        $answers = $_POST['answers'] ?? [];

        if (!is_array($answers)) {
            $answers = [];
        }

        $errors = [];

        foreach (surveyQuestions($data, $surveyId) as $q) {
            $qid = $q['id'];

            if (!isQuestionVisible($data, $surveyId, $q, $answers)) {
                continue;
            }

            $value = $answers[$qid] ?? null;

            if (!empty($q['required'])) {
                $empty = false;

                if (is_array($value)) {
                    $empty = count($value) === 0;
                } else {
                    $empty = trim((string)$value) === '';
                }

                if ($empty) {
                    $errors[$qid] = '必須項目です。';
                }
            }
        }

        if ($errors) {
            $_SESSION['answer_errors'] = $errors;
            $_SESSION['answer_values'] = $answers;

            redirect(
                '?page=respond&surveyId='
                . urlencode($surveyId)
                . ($token !== ''
                    ? '&token=' . urlencode($token)
                    : '')
            );
        }

        $respondentInfo = [
            'name' => trim((string)($_POST['respondentName'] ?? '')),
            'email' => trim((string)($_POST['respondentEmail'] ?? '')),
        ];

        $data['answers'][] = [
            'id' => uid('answer'),
            'surveyId' => $surveyId,
            'customerId' => $customerId,
            'respondentInfo' => $respondentInfo,
            'answers' => $answers,
            'submittedAt' => now(),
            'status' => 'submitted',
            'token' => $token,
        ];

        saveData($data);

        redirect(
            '?page=complete&surveyId='
            . urlencode($surveyId)
        );
    }

    /* ---------- アンケート保存 ---------- */

    if ($action === 'save_survey') {
        requireAdmin();

        $id = trim((string)($_POST['id'] ?? ''));

        $title = trim((string)($_POST['title'] ?? ''));

        if ($title === '') {
            flash('タイトルは必須です。', 'error');

            redirect(
                '?page=edit'
                . ($id !== '' ? '&id=' . urlencode($id) : '')
            );
        }

        if ($id === '') {
            $id = uid('survey');

            $data['surveys'][] = [
                'id' => $id,
                'title' => $title,
                'description' =>
                    trim((string)($_POST['description'] ?? '')),
                'startAt' =>
                    trim((string)($_POST['startAt'] ?? '')),
                'endAt' =>
                    trim((string)($_POST['endAt'] ?? '')),
                'status' => STATUS_DRAFT,
                'numberingMode' =>
                    ($_POST['numberingMode'] ?? 'survey') === 'group'
                        ? 'group'
                        : 'survey',
                'createdAt' => now(),
                'updatedAt' => now(),
                'allowResubmission' =>
                    isset($_POST['allowResubmission']),
            ];

            $data['groups'][] = [
                'id' => uid('group'),
                'surveyId' => $id,
                'title' => 'グループ1',
                'order' => 1,
            ];
        } else {
            $idx = surveyIndex($data, $id);

            if ($idx < 0) {
                flash('アンケートが存在しません。', 'error');
                redirect('?page=list');
            }

            /*
             * 保存ではstatusを変更しない。
             */
            $data['surveys'][$idx]['title'] = $title;
            $data['surveys'][$idx]['description'] =
                trim((string)($_POST['description'] ?? ''));
            $data['surveys'][$idx]['startAt'] =
                trim((string)($_POST['startAt'] ?? ''));
            $data['surveys'][$idx]['endAt'] =
                trim((string)($_POST['endAt'] ?? ''));
            $data['surveys'][$idx]['numberingMode'] =
                ($_POST['numberingMode'] ?? 'survey') === 'group'
                    ? 'group'
                    : 'survey';
            $data['surveys'][$idx]['allowResubmission'] =
                isset($_POST['allowResubmission']);
            $data['surveys'][$idx]['updatedAt'] = now();
        }

        renumberSurvey($data, $id);
        saveData($data);

        flash('アンケートを保存しました。');
        redirect('?page=list');
    }

    /* ---------- 状態変更 ---------- */

    if ($action === 'change_status') {
        requireAdmin();

        $id = (string)($_POST['id'] ?? '');
        $newStatus = (string)($_POST['newStatus'] ?? '');

        $idx = surveyIndex($data, $id);

        if ($idx >= 0) {
            $old = $data['surveys'][$idx]['status'];

            $allowed = [
                STATUS_DRAFT => [STATUS_PUBLISHED],
                STATUS_PUBLISHED => [STATUS_STOPPED],
                STATUS_STOPPED => [STATUS_PUBLISHED],
                STATUS_ENDED => [],
            ];

            if (
                $newStatus !== STATUS_ENDED
                && in_array(
                    $newStatus,
                    $allowed[$old] ?? [],
                    true
                )
            ) {
                $data['surveys'][$idx]['status'] = $newStatus;
                $data['surveys'][$idx]['updatedAt'] = now();
                saveData($data);

                flash(
                    'ステータスを「'
                    . statusLabel($newStatus)
                    . '」に変更しました。'
                );
            }
        }

        redirect('?page=edit&id=' . urlencode($id));
    }

    /* ---------- グループ追加 ---------- */

    if ($action === 'add_group') {
        requireAdmin();

        $surveyId = (string)($_POST['surveyId'] ?? '');

        $orders = array_map(
            fn($g) => (int)$g['order'],
            surveyGroups($data, $surveyId)
        );

        $data['groups'][] = [
            'id' => uid('group'),
            'surveyId' => $surveyId,
            'title' => '新しいグループ',
            'order' => $orders
                ? max($orders) + 1
                : 1,
        ];

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect(
            '?page=edit&id=' . urlencode($surveyId)
        );
    }

    /* ---------- グループ更新 ---------- */

    if ($action === 'update_group') {
        requireAdmin();

        $surveyId = (string)($_POST['surveyId'] ?? '');
        $groupId = (string)($_POST['groupId'] ?? '');

        foreach ($data['groups'] as $i => $g) {
            if ($g['id'] === $groupId) {
                $data['groups'][$i]['title'] =
                    trim((string)($_POST['title'] ?? ''))
                    ?: '無題のグループ';
            }
        }

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect(
            '?page=edit&id=' . urlencode($surveyId)
        );
    }

    /* ---------- グループ削除 ---------- */

    if ($action === 'delete_group') {
        requireAdmin();

        $surveyId = (string)($_POST['surveyId'] ?? '');
        $groupId = (string)($_POST['groupId'] ?? '');

        $groupIds = array_map(
            fn($g) => $g['id'],
            surveyGroups($data, $surveyId)
        );

        /*
         * 最後のグループは削除しない。
         */
        if (count($groupIds) <= 1) {
            flash(
                'グループは最低1つ必要です。',
                'error'
            );

            redirect(
                '?page=edit&id=' . urlencode($surveyId)
            );
        }

        $questionIds = [];

        foreach ($data['questions'] as $q) {
            if (($q['groupId'] ?? '') === $groupId) {
                $questionIds[] = $q['id'];
            }
        }

        $data['groups'] = array_values(array_filter(
            $data['groups'],
            fn($g) => $g['id'] !== $groupId
        ));

        $data['questions'] = array_values(array_filter(
            $data['questions'],
            fn($q) => ($q['groupId'] ?? '') !== $groupId
        ));

        $data['choices'] = array_values(array_filter(
            $data['choices'],
            fn($c) =>
                !in_array(
                    $c['questionId'],
                    $questionIds,
                    true
                )
        ));

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect(
            '?page=edit&id=' . urlencode($surveyId)
        );
    }

    /* ---------- 質問追加 ---------- */

    if ($action === 'add_question') {
        requireAdmin();

        $surveyId = (string)($_POST['surveyId'] ?? '');
        $groupId = (string)($_POST['groupId'] ?? '');

        $orders = [];

        foreach ($data['questions'] as $q) {
            if (($q['groupId'] ?? '') === $groupId) {
                $orders[] = (int)$q['order'];
            }
        }

        $questionId = uid('question');

        $data['questions'][] = [
            'id' => $questionId,
            'groupId' => $groupId,
            'text' => '新しい質問',
            'type' => QUESTION_SINGLE,
            'required' => false,
            'order' => $orders
                ? max($orders) + 1
                : 1,
            'branchRules' => [],
        ];

        $data['choices'][] = [
            'id' => uid('choice'),
            'questionId' => $questionId,
            'label' => '選択肢1',
            'order' => 1,
            'hasOther' => false,
        ];

        $data['choices'][] = [
            'id' => uid('choice'),
            'questionId' => $questionId,
            'label' => '選択肢2',
            'order' => 2,
            'hasOther' => false,
        ];

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect(
            '?page=edit&id=' . urlencode($surveyId)
        );
    }

    /* ---------- 質問更新 ---------- */

    if ($action === 'update_question') {
        requireAdmin();

        $surveyId = (string)($_POST['surveyId'] ?? '');
        $questionId = (string)($_POST['questionId'] ?? '');

        foreach ($data['questions'] as $i => $q) {
            if ($q['id'] !== $questionId) {
                continue;
            }

            $type = (string)($_POST['type'] ?? QUESTION_SINGLE);

            if (!in_array(
                $type,
                [
                    QUESTION_SINGLE,
                    QUESTION_MULTIPLE,
                    QUESTION_TEXT
                ],
                true
            )) {
                $type = QUESTION_SINGLE;
            }

            $data['questions'][$i]['text'] =
                trim((string)($_POST['text'] ?? ''))
                ?: '無題の質問';

            $data['questions'][$i]['type'] = $type;
            $data['questions'][$i]['required'] =
                isset($_POST['required']);

            $branchTarget =
                trim((string)($_POST['branchTarget'] ?? ''));

            $branchValue =
                trim((string)($_POST['branchValue'] ?? ''));

            if (
                $type === QUESTION_SINGLE
                && $branchTarget !== ''
                && $branchValue !== ''
            ) {
                $data['questions'][$i]['branchRules'] = [
                    [
                        'value' => $branchValue,
                        'targetQuestionId' => $branchTarget,
                    ]
                ];
            } else {
                $data['questions'][$i]['branchRules'] = [];
            }

            break;
        }

        /*
         * 選択肢を更新。
         */
        if (
            isset($_POST['choiceLabel'])
            && is_array($_POST['choiceLabel'])
        ) {
            $choiceLabels = $_POST['choiceLabel'];

            foreach ($data['choices'] as $i => $c) {
                if (($c['questionId'] ?? '') !== $questionId) {
                    continue;
                }

                $cid = $c['id'];

                if (array_key_exists($cid, $choiceLabels)) {
                    $label = trim(
                        (string)$choiceLabels[$cid]
                    );

                    $data['choices'][$i]['label'] =
                        $label !== '' ? $label : '選択肢';
                }
            }
        }

        /*
         * 新規選択肢。
         */
        $newChoiceLabels =
            $_POST['newChoiceLabel'] ?? [];

        if (is_array($newChoiceLabels)) {
            $maxOrder = 0;

            foreach ($data['choices'] as $c) {
                if (($c['questionId'] ?? '') === $questionId) {
                    $maxOrder = max(
                        $maxOrder,
                        (int)$c['order']
                    );
                }
            }

            foreach ($newChoiceLabels as $label) {
                $label = trim((string)$label);

                if ($label === '') {
                    continue;
                }

                $data['choices'][] = [
                    'id' => uid('choice'),
                    'questionId' => $questionId,
                    'label' => $label,
                    'order' => ++$maxOrder,
                    'hasOther' => false,
                ];
            }
        }

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect(
            '?page=edit&id=' . urlencode($surveyId)
        );
    }

    /* ---------- 選択肢削除 ---------- */

    if ($action === 'delete_choice') {
        requireAdmin();

        $surveyId = (string)($_POST['surveyId'] ?? '');
        $questionId = (string)($_POST['questionId'] ?? '');
        $choiceId = (string)($_POST['choiceId'] ?? '');

        $data['choices'] = array_values(array_filter(
            $data['choices'],
            fn($c) => $c['id'] !== $choiceId
        ));

        $order = 1;

        foreach ($data['choices'] as $i => $c) {
            if (($c['questionId'] ?? '') === $questionId) {
                $data['choices'][$i]['order'] = $order++;
            }
        }

        saveData($data);

        redirect(
            '?page=edit&id=' . urlencode($surveyId)
        );
    }

    /* ---------- 質問削除 ---------- */

    if ($action === 'delete_question') {
        requireAdmin();

        $surveyId = (string)($_POST['surveyId'] ?? '');
        $questionId = (string)($_POST['questionId'] ?? '');

        $data['questions'] = array_values(array_filter(
            $data['questions'],
            fn($q) => $q['id'] !== $questionId
        ));

        $data['choices'] = array_values(array_filter(
            $data['choices'],
            fn($c) =>
                ($c['questionId'] ?? '') !== $questionId
        ));

        /*
         * 他質問のbranchRulesから削除された質問を除去。
         */
        foreach ($data['questions'] as $i => $q) {
            $rules = $q['branchRules'] ?? [];

            $rules = array_values(array_filter(
                $rules,
                fn($r) =>
                    ($r['targetQuestionId'] ?? '') !== $questionId
            ));

            $data['questions'][$i]['branchRules'] = $rules;
        }

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect(
            '?page=edit&id=' . urlencode($surveyId)
        );
    }

    /* ---------- グループ並び替え ---------- */

    if ($action === 'move_group') {
        requireAdmin();

        $surveyId = (string)($_POST['surveyId'] ?? '');
        $groupId = (string)($_POST['groupId'] ?? '');
        $direction = (string)($_POST['direction'] ?? '');

        $groups = surveyGroups($data, $surveyId);

        $index = -1;

        foreach ($groups as $i => $g) {
            if ($g['id'] === $groupId) {
                $index = $i;
                break;
            }
        }

        $target = $direction === 'up'
            ? $index - 1
            : $index + 1;

        if (
            $index >= 0
            && isset($groups[$target])
        ) {
            $tmp = $groups[$index];
            $groups[$index] = $groups[$target];
            $groups[$target] = $tmp;

            foreach ($groups as $order => $g) {
                foreach ($data['groups'] as $i => $stored) {
                    if ($stored['id'] === $g['id']) {
                        $data['groups'][$i]['order'] =
                            $order + 1;
                    }
                }
            }

            renumberSurvey($data, $surveyId);
            saveData($data);
        }

        redirect(
            '?page=edit&id=' . urlencode($surveyId)
        );
    }

    /* ---------- 質問移動 ---------- */

    if ($action === 'move_question') {
        requireAdmin();

        $surveyId = (string)($_POST['surveyId'] ?? '');
        $questionId = (string)($_POST['questionId'] ?? '');
        $direction = (string)($_POST['direction'] ?? '');
        $targetGroupId =
            (string)($_POST['targetGroupId'] ?? '');

        $questionIndex = -1;

        foreach ($data['questions'] as $i => $q) {
            if ($q['id'] === $questionId) {
                $questionIndex = $i;
                break;
            }
        }

        if ($questionIndex >= 0) {
            if ($targetGroupId !== '') {
                $data['questions'][$questionIndex]['groupId'] =
                    $targetGroupId;

                $max = 0;

                foreach ($data['questions'] as $q) {
                    if (
                        ($q['groupId'] ?? '') ===
                        $targetGroupId
                    ) {
                        $max = max(
                            $max,
                            (int)$q['order']
                        );
                    }
                }

                $data['questions'][$questionIndex]['order'] =
                    $max + 1;
            } else {
                $groupId =
                    $data['questions'][$questionIndex]['groupId'];

                $questions = [];

                foreach ($data['questions'] as $q) {
                    if (($q['groupId'] ?? '') === $groupId) {
                        $questions[] = $q;
                    }
                }

                usort(
                    $questions,
                    fn($a, $b) =>
                        (int)$a['order']
                        <=> (int)$b['order']
                );

                $idx = -1;

                foreach ($questions as $i => $q) {
                    if ($q['id'] === $questionId) {
                        $idx = $i;
                        break;
                    }
                }

                $target = $direction === 'up'
                    ? $idx - 1
                    : $idx + 1;

                if (
                    $idx >= 0
                    && isset($questions[$target])
                ) {
                    $tmp = $questions[$idx];
                    $questions[$idx] =
                        $questions[$target];
                    $questions[$target] = $tmp;

                    foreach ($questions as $order => $q) {
                        foreach ($data['questions'] as $i => $stored) {
                            if ($stored['id'] === $q['id']) {
                                $data['questions'][$i]['order'] =
                                    $order + 1;
                            }
                        }
                    }
                }
            }

            renumberSurvey($data, $surveyId);
            saveData($data);
        }

        redirect(
            '?page=edit&id=' . urlencode($surveyId)
        );
    }

    /* ---------- 複製 ---------- */

    if ($action === 'duplicate_survey') {
        requireAdmin();

        $surveyId = (string)($_POST['id'] ?? '');
        $original = survey($data, $surveyId);

        if (!$original) {
            flash('複製元が存在しません。', 'error');
            redirect('?page=list');
        }

        $newSurveyId = uid('survey');

        $copy = $original;
        $copy['id'] = $newSurveyId;
        $copy['title'] .= '（コピー）';
        $copy['status'] = STATUS_DRAFT;
        $copy['createdAt'] = now();
        $copy['updatedAt'] = now();

        $data['surveys'][] = $copy;

        $groupMap = [];
        $questionMap = [];

        foreach (surveyGroups($data, $surveyId) as $g) {
            $newGroupId = uid('group');

            $groupMap[$g['id']] = $newGroupId;

            $data['groups'][] = [
                'id' => $newGroupId,
                'surveyId' => $newSurveyId,
                'title' => $g['title'],
                'order' => $g['order'],
            ];
        }

        foreach (surveyQuestions($data, $surveyId) as $q) {
            $newQuestionId = uid('question');

            $questionMap[$q['id']] = $newQuestionId;

            $data['questions'][] = [
                'id' => $newQuestionId,
                'groupId' => $groupMap[$q['groupId']],
                'text' => $q['text'],
                'type' => $q['type'],
                'required' => $q['required'],
                'order' => $q['order'],
                'branchRules' => [],
            ];
        }

        /*
         * 選択肢をコピー。
         */
        foreach ($data['choices'] as $c) {
            $oldQid = $c['questionId'];

            if (!isset($questionMap[$oldQid])) {
                continue;
            }

            $data['choices'][] = [
                'id' => uid('choice'),
                'questionId' =>
                    $questionMap[$oldQid],
                'label' => $c['label'],
                'order' => $c['order'],
                'hasOther' => $c['hasOther'] ?? false,
            ];
        }

        renumberSurvey($data, $newSurveyId);
        saveData($data);

        flash('アンケートを複製しました。');

        redirect(
            '?page=edit&id=' . urlencode($newSurveyId)
        );
    }

    /* ---------- 削除 ---------- */

    if ($action === 'delete_survey') {
        requireAdmin();

        $surveyId = (string)($_POST['id'] ?? '');

        $groupIds = [];

        foreach ($data['groups'] as $g) {
            if (($g['surveyId'] ?? '') === $surveyId) {
                $groupIds[] = $g['id'];
            }
        }

        $questionIds = [];

        foreach ($data['questions'] as $q) {
            if (in_array(
                $q['groupId'],
                $groupIds,
                true
            )) {
                $questionIds[] = $q['id'];
            }
        }

        $data['surveys'] = array_values(array_filter(
            $data['surveys'],
            fn($s) => $s['id'] !== $surveyId
        ));

        $data['groups'] = array_values(array_filter(
            $data['groups'],
            fn($g) =>
                !in_array($g['id'], $groupIds, true)
        ));

        $data['questions'] = array_values(array_filter(
            $data['questions'],
            fn($q) =>
                !in_array($q['id'], $questionIds, true)
        ));

        $data['choices'] = array_values(array_filter(
            $data['choices'],
            fn($c) =>
                !in_array(
                    $c['questionId'],
                    $questionIds,
                    true
                )
        ));

        /*
         * 業務要件上、削除したアンケートに属する
         * 回答・送信履歴も削除。
         */
        $data['answers'] = array_values(array_filter(
            $data['answers'],
            fn($a) =>
                ($a['surveyId'] ?? '') !== $surveyId
        ));

        $data['sendHistories'] = array_values(array_filter(
            $data['sendHistories'],
            fn($h) =>
                ($h['surveyId'] ?? '') !== $surveyId
        ));

        saveData($data);

        flash('アンケートを削除しました。');

        redirect('?page=list');
    }

    /* ---------- 顧客選択・メール送信 ---------- */

    if ($action === 'send_mail') {
        requireAdmin();

        $surveyId = (string)($_POST['surveyId'] ?? '');
        $survey = survey($data, $surveyId);

        if (!$survey) {
            http_response_code(404);
            exit('対象アンケートが存在しません。');
        }

        $customerIds = $_POST['customerIds'] ?? [];

        if (!is_array($customerIds)) {
            $customerIds = [];
        }

        $customerIds = array_values(
            array_unique(
                array_map(
                    'strval',
                    $customerIds
                )
            )
        );

        if (!$customerIds) {
            flash(
                '送信対象を選択してください。',
                'error'
            );

            redirect(
                '?page=send&surveyId='
                . urlencode($surveyId)
            );
        }

        $subject = trim(
            (string)($_POST['subject'] ?? '')
        );

        $bodyTemplate = (string)(
            $_POST['body'] ?? ''
        );

        if ($subject === '') {
            flash(
                '件名を入力してください。',
                'error'
            );

            redirect(
                '?page=send&surveyId='
                . urlencode($surveyId)
            );
        }

        $smtp = $data['settings']['smtp'] ?? [];

        $recipients = [];
        $messages = [];
        $success = 0;
        $failure = 0;

        foreach ($customerIds as $customerId) {
            $c = customer($data, $customerId);

            if (!$c || empty($c['email'])) {
                $failure++;

                $messages[] = [
                    'customerId' => $customerId,
                    'email' => $c['email'] ?? '',
                    'success' => false,
                    'error' => 'メールアドレス未設定',
                ];

                continue;
            }

            $token = hash_hmac(
                'sha256',
                $surveyId . '|' . $customerId,
                'survey-secret'
            );

            $url =
                baseUrl()
                . '/?page=respond&surveyId='
                . rawurlencode($surveyId)
                . '&token='
                . rawurlencode($token);

            $body = str_replace(
                [
                    '{{顧客名}}',
                    '{{会社名}}',
                    '{{アンケートURL}}',
                ],
                [
                    $c['name'],
                    $c['organizationName'],
                    $url,
                ],
                $bodyTemplate
            );

            try {
                smtpSendMail(
                    $smtp,
                    $c['email'],
                    $subject,
                    $body
                );

                $success++;

                $messages[] = [
                    'customerId' => $customerId,
                    'email' => $c['email'],
                    'success' => true,
                    'subject' => $subject,
                    'body' => $body,
                    'url' => $url,
                ];

                $recipients[] = [
                    'customerId' => $customerId,
                    'email' => $c['email'],
                    'sentAt' => now(),
                    'url' => $url,
                    'success' => true,
                ];
            } catch (Throwable $e) {
                $failure++;

                $messages[] = [
                    'customerId' => $customerId,
                    'email' => $c['email'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];

                $recipients[] = [
                    'customerId' => $customerId,
                    'email' => $c['email'],
                    'sentAt' => now(),
                    'url' => $url,
                    'success' => false,
                ];
            }
        }

        $data['sendHistories'][] = [
            'id' => uid('history'),
            'surveyId' => $surveyId,
            'sentAt' => now(),
            'sendType' => (string)(
                $_POST['sendType'] ?? 'bulk'
            ),
            'count' => count($customerIds),
            'subject' => $subject,
            'bodyTemplate' => $bodyTemplate,
            'operator' => DEFAULT_ADMIN_USER,
            'recipients' => $recipients,
            'messages' => $messages,
            'successCount' => $success,
            'failureCount' => $failure,
        ];

        saveData($data);

        $_SESSION['send_result'] = [
            'target' => count($customerIds),
            'success' => $success,
            'failure' => $failure,
            'sentAt' => now(),
        ];

        redirect(
            '?page=send&surveyId='
            . urlencode($surveyId)
            . '&tab=result'
        );
    }

    /* ---------- kintone設定保存 ---------- */

    if ($action === 'save_kintone') {
        requireAdmin();

        $data['settings']['kintone'] = [
            'subdomain' =>
                trim((string)($_POST['subdomain'] ?? '')),
            'appId' =>
                trim((string)($_POST['appId'] ?? '')),
            'loginName' =>
                trim((string)($_POST['loginName'] ?? '')),
            'password' =>
                (string)($_POST['password'] ?? ''),
            'sslVerify' =>
                isset($_POST['sslVerify']),
            'fieldMapping' =>
                is_array($_POST['fieldMapping'] ?? null)
                    ? $_POST['fieldMapping']
                    : [],
            'addressFields' =>
                is_array($_POST['addressFields'] ?? null)
                    ? $_POST['addressFields']
                    : [],
        ];

        saveData($data);

        flash('kintone設定を保存しました。');

        redirect('?page=kintone');
    }

    /* ---------- kintone接続テスト ---------- */

    if ($action === 'test_kintone') {
        requireAdmin();

        try {
            /*
             * 保存データではなく画面入力を使用。
             * 接続テストによって設定保存・同期は行わない。
             */
            $config = [
                'subdomain' =>
                    trim((string)($_POST['subdomain'] ?? '')),
                'appId' =>
                    trim((string)($_POST['appId'] ?? '')),
                'loginName' =>
                    trim((string)($_POST['loginName'] ?? '')),
                'password' =>
                    (string)($_POST['password'] ?? ''),
                'sslVerify' =>
                    isset($_POST['sslVerify']),
            ];

            $result = kintoneTest($config);

            $_SESSION['kintone_result'] = [
                'success' => true,
                'message' =>
                    '接続成功: '
                    . ($result['name'] ?? 'kintoneアプリ'),
            ];
        } catch (Throwable $e) {
            $_SESSION['kintone_result'] = [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        redirect('?page=kintone');
    }

    /* ---------- kintone項目取得 ---------- */

    if ($action === 'fetch_kintone_fields') {
        requireAdmin();

        try {
            $fields = kintoneFields(
                $data['settings']['kintone']
            );

            $_SESSION['kintone_fields'] =
                $fields['properties'] ?? [];

            $_SESSION['kintone_result'] = [
                'success' => true,
                'message' => '項目一覧を取得しました。',
            ];
        } catch (Throwable $e) {
            $_SESSION['kintone_result'] = [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        redirect('?page=kintone');
    }

    /* ---------- kintone同期 ---------- */

    if ($action === 'sync_kintone') {
        requireAdmin();

        try {
            $appId = (int)(
                $data['settings']['kintone']['appId']
                ?? 0
            );

            $response = kintoneRecords(
                $data['settings']['kintone'],
                $appId
            );

            $mapping =
                $data['settings']['kintone']['fieldMapping']
                ?? [];

            $count = 0;

            foreach (($response['records'] ?? []) as $record) {
                $get = function(string $logical) use (
                    $record,
                    $mapping
                ): string {
                    $fieldCode =
                        (string)($mapping[$logical] ?? '');

                    if (
                        $fieldCode === ''
                        || !isset($record[$fieldCode]['value'])
                    ) {
                        return '';
                    }

                    $v = $record[$fieldCode]['value'];

                    if (is_array($v)) {
                        return implode(
                            ' ',
                            array_map(
                                'strval',
                                $v
                            )
                        );
                    }

                    return (string)$v;
                };

                $email = $get('email');

                if ($email === '') {
                    continue;
                }

                $found = false;

                foreach ($data['customers'] as $i => $c) {
                    if (
                        strtolower(
                            (string)$c['email']
                        )
                        === strtolower($email)
                    ) {
                        $data['customers'][$i] = [
                            ...$c,
                            'organizationName' =>
                                $get('organizationName')
                                ?: $c['organizationName'],
                            'name' =>
                                $get('name')
                                ?: $c['name'],
                            'email' => $email,
                            'department' =>
                                $get('department'),
                            'phone' =>
                                $get('phone'),
                            'address' =>
                                $get('address'),
                            'kintoneStatus' =>
                                'registered',
                        ];

                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $data['customers'][] = [
                        'id' => uid('customer'),
                        'organizationName' =>
                            $get('organizationName'),
                        'name' => $get('name'),
                        'email' => $email,
                        'department' =>
                            $get('department'),
                        'phone' => $get('phone'),
                        'address' => $get('address'),
                        'kintoneStatus' =>
                            'registered',
                    ];
                }

                $count++;
            }

            saveData($data);

            $_SESSION['kintone_result'] = [
                'success' => true,
                'message' =>
                    $count . '件の顧客情報を同期しました。',
            ];
        } catch (Throwable $e) {
            $_SESSION['kintone_result'] = [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        redirect('?page=kintone');
    }

    /* ---------- SMTP保存 ---------- */

    if ($action === 'save_smtp') {
        requireAdmin();

        $data['settings']['smtp'] = [
            'server' =>
                trim((string)($_POST['server'] ?? '')),
            'port' =>
                (int)($_POST['port'] ?? 587),
            'encryption' =>
                in_array(
                    $_POST['encryption'] ?? '',
                    ['SSL', 'TLS', 'NONE'],
                    true
                )
                    ? $_POST['encryption']
                    : 'TLS',
            'auth' =>
                isset($_POST['auth']),
            'username' =>
                trim((string)($_POST['username'] ?? '')),
            'password' =>
                (string)($_POST['password'] ?? ''),
            'from' =>
                trim((string)($_POST['from'] ?? '')),
            'senderName' =>
                trim((string)($_POST['senderName'] ?? '')),
            'replyTo' =>
                trim((string)($_POST['replyTo'] ?? '')),
            'status' =>
                '未設定',
        ];

        saveData($data);

        flash('メールサーバ設定を保存しました。');

        redirect('?page=smtp');
    }

    /* ---------- SMTPテスト ---------- */

    if ($action === 'test_smtp') {
        requireAdmin();

        try {
            $config = [
                'server' =>
                    trim((string)($_POST['server'] ?? '')),
                'port' =>
                    (int)($_POST['port'] ?? 587),
                'encryption' =>
                    (string)($_POST['encryption'] ?? 'TLS'),
                'auth' =>
                    isset($_POST['auth']),
                'username' =>
                    trim((string)($_POST['username'] ?? '')),
                'password' =>
                    (string)($_POST['password'] ?? ''),
                'from' =>
                    trim((string)($_POST['from'] ?? '')),
                'senderName' =>
                    trim((string)($_POST['senderName'] ?? '')),
                'replyTo' =>
                    trim((string)($_POST['replyTo'] ?? '')),
            ];

            $testTo = trim(
                (string)($_POST['testTo'] ?? '')
            );

            if (!filter_var(
                $testTo,
                FILTER_VALIDATE_EMAIL
            )) {
                throw new RuntimeException(
                    'テスト送信先メールアドレスが不正です。'
                );
            }

            smtpSendMail(
                $config,
                $testTo,
                'SMTP接続テスト',
                'SMTP接続テストメールです。'
            );

            $_SESSION['smtp_result'] = [
                'success' => true,
                'message' =>
                    'テストメールを送信しました。',
            ];
        } catch (Throwable $e) {
            $_SESSION['smtp_result'] = [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        redirect('?page=smtp');
    }
}

/* =========================================================
 * 回答分岐
 * ========================================================= */

function isQuestionVisible(
    array $data,
    string $surveyId,
    array $question,
    array $answers
): bool {
    $rules = $question['branchRules'] ?? [];

    if (!$rules) {
        return true;
    }

    foreach ($data['questions'] as $source) {
        foreach (($source['branchRules'] ?? []) as $rule) {
            if (
                ($rule['targetQuestionId'] ?? '') !==
                $question['id']
            ) {
                continue;
            }

            $sourceValue =
                $answers[$source['id']] ?? null;

            $expected =
                (string)($rule['value'] ?? '');

            if (is_array($sourceValue)) {
                if (
                    in_array(
                        $expected,
                        array_map(
                            'strval',
                            $sourceValue
                        ),
                        true
                    )
                ) {
                    return true;
                }
            } elseif (
                (string)$sourceValue === $expected
            ) {
                return true;
            }
        }
    }

    /*
     * 明示的な分岐対象でない質問は表示。
     */
    $hasIncoming = false;

    foreach ($data['questions'] as $source) {
        foreach (($source['branchRules'] ?? []) as $rule) {
            if (
                ($rule['targetQuestionId'] ?? '') ===
                $question['id']
            ) {
                $hasIncoming = true;
            }
        }
    }

    return !$hasIncoming;
}

/* =========================================================
 * 出力
 * ========================================================= */

if ($page === 'csv') {
    requireAdmin();

    $surveyId = (string)($_GET['surveyId'] ?? '');
    $s = survey($data, $surveyId);

    if (!$s) {
        http_response_code(404);
        exit('Not Found');
    }

    $questions = surveyQuestions($data, $surveyId);

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey_'
        . rawurlencode($surveyId)
        . '.csv"'
    );

    $fp = fopen('php://output', 'w');

    fwrite($fp, "\xEF\xBB\xBF");

    $header = [
        '回答ID',
        '回答日時',
        '顧客ID',
        '回答者名',
        'メール',
    ];

    foreach ($questions as $q) {
        $header[] =
            'Q' . ($q['number'] ?? '')
            . ' '
            . $q['text'];
    }

    fputcsv($fp, $header);

    foreach ($data['answers'] as $a) {
        if (
            ($a['surveyId'] ?? '') !== $surveyId
            || ($a['status'] ?? '') !== 'submitted'
        ) {
            continue;
        }

        $row = [
            $a['id'],
            $a['submittedAt'],
            $a['customerId'] ?? '',
            $a['respondentInfo']['name'] ?? '',
            $a['respondentInfo']['email'] ?? '',
        ];

        foreach ($questions as $q) {
            $v =
                $a['answers'][$q['id']]
                ?? '';

            if (is_array($v)) {
                $v = implode('、', $v);
            }

            $row[] = $v;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

if ($page === 'pdf') {
    requireAdmin();

    $surveyId = (string)($_GET['surveyId'] ?? '');
    $s = survey($data, $surveyId);

    if (!$s) {
        http_response_code(404);
        exit('Not Found');
    }

    $lines = [
        'Survey Report',
        'Survey ID: ' . $surveyId,
        'Title: ' . $s['title'],
        'Answers: ' .
            surveyAnswerCount($data, $surveyId),
        'Generated: ' . now(),
        '',
    ];

    foreach (
        surveyQuestions($data, $surveyId)
        as $q
    ) {
        $lines[] =
            'Q'
            . ($q['number'] ?? '')
            . ': '
            . $q['text'];
    }

    $pdf = simplePdf($lines);

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey-report.pdf"'
    );

    echo $pdf;
    exit;
}

/* =========================================================
 * HTML
 * ========================================================= */

function renderHead(string $title, bool $admin = true): void
{
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --danger:#dc2626;
    --success:#15803d;
    --warning:#b45309;
    --bg:#f5f7fb;
    --card:#fff;
    --border:#dbe1ea;
    --text:#1f2937;
    --muted:#64748b;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
    color:var(--text);
    background:var(--bg);
    line-height:1.6;
}
a{color:var(--primary)}
.container{
    max-width:1400px;
    margin:auto;
    padding:24px;
}
header.admin-header{
    background:#111827;
    color:white;
}
.header-inner{
    max-width:1400px;
    margin:auto;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:0 24px;
}
.logo{
    color:#fff;
    font-weight:700;
    text-decoration:none;
    white-space:nowrap;
}
nav{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}
nav a{
    color:#e5e7eb;
    text-decoration:none;
    padding:8px 12px;
    border-radius:7px;
}
nav a:hover{background:#374151}
h1{font-size:28px;margin:0 0 8px}
h2{font-size:21px;margin:0 0 14px}
h3{font-size:17px;margin:0 0 12px}
.page-title{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    margin-bottom:24px;
}
.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:12px;
    padding:20px;
    margin-bottom:18px;
    box-shadow:0 2px 7px rgba(15,23,42,.04);
}
.grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}
.grid-2{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}
label{
    display:block;
    font-weight:600;
    margin-bottom:5px;
}
input,textarea,select{
    width:100%;
    min-height:42px;
    border:1px solid #cbd5e1;
    border-radius:7px;
    padding:9px 11px;
    background:#fff;
    font:inherit;
}
textarea{min-height:110px;resize:vertical}
input[type=checkbox],
input[type=radio]{
    width:auto;
    min-height:auto;
}
.form-row{margin-bottom:16px}
.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 14px;
    border:0;
    border-radius:7px;
    background:#e2e8f0;
    color:#0f172a;
    text-decoration:none;
    cursor:pointer;
    font:inherit;
    font-weight:600;
}
.btn:hover{filter:brightness(.96)}
.btn-primary{
    background:var(--primary);
    color:white;
}
.btn-danger{
    background:var(--danger);
    color:white;
}
.btn-success{
    background:var(--success);
    color:white;
}
.btn-warning{
    background:#d97706;
    color:white;
}
.btn-small{
    min-height:34px;
    padding:5px 9px;
    font-size:13px;
}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    border-bottom:1px solid var(--border);
    padding:11px 10px;
    text-align:left;
    vertical-align:top;
}
th{
    background:#f8fafc;
    white-space:nowrap;
}
.table-wrap{
    overflow-x:auto;
}
.badge{
    display:inline-block;
    padding:3px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.badge.draft{background:#e2e8f0}
.badge.published{background:#dcfce7;color:#166534}
.badge.stopped{background:#fef3c7;color:#92400e}
.badge.ended{background:#fee2e2;color:#991b1b}
.notice{
    padding:12px 15px;
    border-radius:8px;
    margin-bottom:15px;
}
.notice.success{
    background:#dcfce7;
    color:#166534;
}
.notice.error{
    background:#fee2e2;
    color:#991b1b;
}
.notice.info{
    background:#dbeafe;
    color:#1e40af;
}
.muted{color:var(--muted)}
.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:17px;
}
.stat .label{
    color:var(--muted);
    font-size:13px;
}
.stat .value{
    font-size:27px;
    font-weight:700;
}
.question-card{
    border:1px solid var(--border);
    border-radius:10px;
    padding:17px;
    margin:14px 0;
}
.question-number{
    color:var(--primary);
    font-weight:700;
}
.choice{
    display:flex;
    gap:9px;
    align-items:flex-start;
    padding:9px 0;
}
.answer-actions{
    display:flex;
    justify-content:space-between;
    gap:12px;
    margin-top:24px;
}
.progress{
    height:8px;
    background:#e2e8f0;
    border-radius:99px;
    overflow:hidden;
}
.progress>span{
    display:block;
    height:100%;
    background:var(--primary);
}
.bar-row{
    display:grid;
    grid-template-columns:180px 1fr 70px;
    align-items:center;
    gap:10px;
    margin:8px 0;
}
.bar{
    height:18px;
    background:#dbeafe;
    border-radius:5px;
    overflow:hidden;
}
.bar>span{
    display:block;
    height:100%;
    background:var(--primary);
}
.login{
    min-height:100vh;
    display:grid;
    place-items:center;
}
.login-card{
    width:min(420px,calc(100% - 30px));
}
.respondent{
    min-height:100vh;
    background:#f8fafc;
}
.respondent .container{
    max-width:820px;
}
.respondent-header{
    background:white;
    border-bottom:1px solid var(--border);
}
.respondent-title{
    max-width:820px;
    margin:auto;
    padding:22px 24px;
}
.mobile-only{display:none}
.dnd{
    cursor:grab;
}
pre{
    white-space:pre-wrap;
    word-break:break-word;
}
@media(max-width:900px){
    .grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .grid-2{grid-template-columns:1fr}
}
@media(max-width:640px){
    .container{padding:15px}
    .header-inner{
        align-items:flex-start;
        flex-direction:column;
        padding:12px 15px;
    }
    nav{width:100%}
    nav a{padding:8px}
    .grid{grid-template-columns:1fr}
    .page-title{
        flex-direction:column;
    }
    .actions .btn{
        width:100%;
    }
    .bar-row{
        grid-template-columns:1fr;
        gap:3px;
    }
    .answer-actions{
        position:sticky;
        bottom:0;
        background:#fff;
        padding:10px;
        border-top:1px solid var(--border);
        margin-left:-15px;
        margin-right:-15px;
    }
    .answer-actions .btn{
        flex:1;
    }
}
</style>
</head>
<body>
<?php
}

function renderAdminHeader(string $title): void
{
?>
<header class="admin-header">
<div class="header-inner">
<a class="logo" href="?page=list">
アンケート管理
</a>
<nav>
<a href="?page=list">アンケート一覧</a>
<a href="?page=kintone">kintone連携設定</a>
<a href="?page=smtp">メールサーバ設定</a>
<form method="post" style="display:inline">
<?= csrfField() ?>
<input type="hidden" name="action" value="logout">
<button class="btn btn-small" type="submit">ログアウト</button>
</form>
</nav>
</div>
</header>
<?php
}

function renderFlash(array $flash): void
{
    foreach ($flash as $f) {
        echo '<div class="notice '
            . h($f['type'])
            . '">'
            . h($f['message'])
            . '</div>';
    }
}

/* =========================================================
 * ログイン
 * ========================================================= */

if ($page === 'login') {
    renderHead('ログイン', false);
?>
<div class="login">
<div class="card login-card">
<h1>アンケート管理</h1>
<p class="muted">管理者ログイン</p>

<?php renderFlash($flash); ?>

<form method="post">
<?= csrfField() ?>
<input type="hidden" name="action" value="login">

<div class="form-row">
<label>ログイン名</label>
<input name="username"
       value="<?= h($_POST['username'] ?? '') ?>"
       autocomplete="username"
       required>
</div>

<div class="form-row">
<label>パスワード</label>
<input type="password"
       name="password"
       autocomplete="current-password"
       required>
</div>

<button class="btn btn-primary"
        style="width:100%"
        type="submit">
ログイン
</button>
</form>

<p class="muted" style="margin-top:15px;font-size:12px">
本番環境では ADMIN_PASSWORD 環境変数を設定してください。
</p>
</div>
</div>
</body>
</html>
<?php
exit;
}

/* =========================================================
 * 回答者画面
 * ========================================================= */

if ($isRespondent) {
    $surveyId = (string)(
        $_GET['surveyId']
        ?? $_POST['surveyId']
        ?? ''
    );

    $s = survey($data, $surveyId);

    if (!$s) {
        http_response_code(404);
        renderHead('アンケート');
?>
<div class="container">
<div class="card">
<h1>アンケートが見つかりません</h1>
<p>指定されたアンケートは存在しません。</p>
</div>
</div>
</body>
</html>
<?php
        exit;
    }

    /*
     * 終了・停止・下書きは回答不可。
     */
    if (
        ($s['status'] ?? '') !== STATUS_PUBLISHED
        && $page !== 'complete'
    ) {
        renderHead('回答できません', false);
?>
<div class="respondent">
<div class="container">
<div class="card">
<h1>現在回答できません</h1>
<p>
このアンケートは現在回答受付を行っていません。
</p>
</div>
</div>
</div>
</body>
</html>
<?php
        exit;
    }

    $token = trim(
        (string)(
            $_GET['token']
            ?? $_POST['token']
            ?? ''
        )
    );

    $answerValues =
        $_SESSION['answer_values'] ?? [];

    $answerErrors =
        $_SESSION['answer_errors'] ?? [];

    unset(
        $_SESSION['answer_values'],
        $_SESSION['answer_errors']
    );

    /* ---------- 完了 ---------- */

    if ($page === 'complete') {
        $already = isset($_GET['already']);

        renderHead(
            '回答完了',
            false
        );
?>
<div class="respondent">
<div class="respondent-header">
<div class="respondent-title">
<h1><?= h($s['title']) ?></h1>
</div>
</div>

<div class="container">
<div class="card">
<h2>
<?= $already
    ? '回答済みです'
    : '回答ありがとうございました'
?>
</h2>

<p>
<?= $already
    ? 'このアンケートはすでに回答済みです。'
    : 'アンケートの送信が完了しました。'
?>
</p>

<?php if (!$s['allowResubmission']): ?>
<p class="muted">
このアンケートは再回答できません。
</p>
<?php endif; ?>
</div>
</div>
</div>
</body>
</html>
<?php
        exit;
    }

    /* ---------- 確認 ---------- */

    if ($page === 'confirm') {
        $answers = $_POST['answers'] ?? [];

        if (!is_array($answers)) {
            $answers = [];
        }

        $_SESSION['confirm_answers'] = $answers;

        renderHead(
            '回答確認',
            false
        );
?>
<div class="respondent">
<div class="respondent-header">
<div class="respondent-title">
<h1><?= h($s['title']) ?></h1>
</div>
</div>

<div class="container">
<div class="card">
<h2>回答内容の確認</h2>

<form method="post"
      action="?page=complete">
<?= csrfField() ?>
</form>

<?php foreach (
    surveyQuestions($data, $surveyId)
    as $q
): ?>

<?php if (
    !isQuestionVisible(
        $data,
        $surveyId,
        $q,
        $answers
    )
) continue; ?>

<div class="question-card">
<div class="question-number">
Q<?= h($q['number'] ?? '') ?>
</div>

<strong><?= h($q['text']) ?></strong>

<div style="margin-top:8px">
<?php
$v = $answers[$q['id']] ?? '';

if (is_array($v)) {
    echo h(implode('、', $v));
} else {
    echo nl2br(h((string)$v));
}
?>
</div>
</div>

<?php endforeach; ?>

<div class="answer-actions">
<a class="btn"
   href="?page=respond&surveyId=<?= urlencode($surveyId) ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>">
修正する
</a>

<form method="post"
      action="?page=confirm_submit"
      onsubmit="return confirm('回答を送信します。よろしいですか？')">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="final_answer">
<input type="hidden"
       name="surveyId"
       value="<?= h($surveyId) ?>">
<input type="hidden"
       name="token"
       value="<?= h($token) ?>">

<?php foreach ($answers as $qid => $v): ?>
<?php if (is_array($v)): ?>
<?php foreach ($v as $item): ?>
<input type="hidden"
       name="answers[<?= h($qid) ?>][]"
       value="<?= h($item) ?>">
<?php endforeach; ?>
<?php else: ?>
<input type="hidden"
       name="answers[<?= h($qid) ?>]"
       value="<?= h($v) ?>">
<?php endif; ?>
<?php endforeach; ?>

<button class="btn btn-primary"
        type="submit">
送信する
</button>
</form>
</div>
</div>
</div>
</div>
</body>
</html>
<?php
        exit;
    }

    /* ---------- 最終送信 ---------- */

    if ($page === 'confirm_submit') {
        /*
         * POST actionを通さずGET pageに入った場合も拒否。
         */
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(
                '?page=respond&surveyId='
                . urlencode($surveyId)
            );
        }

        $answers = $_POST['answers'] ?? [];

        if (!is_array($answers)) {
            $answers = [];
        }

        $customerId = null;

        if ($token !== '') {
            foreach ($data['customers'] as $c) {
                $expected = hash_hmac(
                    'sha256',
                    $surveyId . '|' . $c['id'],
                    'survey-secret'
                );

                if (
                    hash_equals(
                        $expected,
                        $token
                    )
                ) {
                    $customerId = $c['id'];
                    break;
                }
            }
        }

        if (
            $customerId !== null
            && !$s['allowResubmission']
        ) {
            foreach ($data['answers'] as $a) {
                if (
                    ($a['surveyId'] ?? '') === $surveyId
                    && ($a['customerId'] ?? '') === $customerId
                    && ($a['status'] ?? '') === 'submitted'
                ) {
                    redirect(
                        '?page=complete&surveyId='
                        . urlencode($surveyId)
                        . '&already=1'
                    );
                }
            }
        }

        $data['answers'][] = [
            'id' => uid('answer'),
            'surveyId' => $surveyId,
            'customerId' => $customerId,
            'respondentInfo' => [
                'name' =>
                    $customerId
                    ? ($customer = customer(
                        $data,
                        $customerId
                    ))['name'] ?? ''
                    : '',
                'email' =>
                    $customerId
                    ? $customer['email'] ?? ''
                    : '',
            ],
            'answers' => $answers,
            'submittedAt' => now(),
            'status' => 'submitted',
            'token' => $token,
        ];

        saveData($data);

        redirect(
            '?page=complete&surveyId='
            . urlencode($surveyId)
        );
    }

    /* ---------- 回答 ---------- */

    renderHead(
        'アンケート回答',
        false
    );
?>
<div class="respondent">
<div class="respondent-header">
<div class="respondent-title">
<h1><?= h($s['title']) ?></h1>

<?php if (!empty($s['description'])): ?>
<p><?= nl2br(h($s['description'])) ?></p>
<?php endif; ?>

<?php if (!empty($s['endAt'])): ?>
<p class="muted">
回答期限:
<?= h($s['endAt']) ?>
</p>
<?php endif; ?>
</div>
</div>

<div class="container">

<?php foreach ($answerErrors as $qid => $error): ?>
<div class="notice error">
<?= h($error) ?>
</div>
<?php endforeach; ?>

<form method="post"
      action="?page=confirm"
      onsubmit="return validateAnswerForm()">
<?= csrfField() ?>

<input type="hidden"
       name="surveyId"
       value="<?= h($surveyId) ?>">

<input type="hidden"
       name="token"
       value="<?= h($token) ?>">

<?php foreach (
    surveyQuestions($data, $surveyId)
    as $q
): ?>

<?php
$visible = isQuestionVisible(
    $data,
    $surveyId,
    $q,
    $answerValues
);

if (!$visible) {
    continue;
}
?>

<div class="question-card">
<div class="question-number">
Q<?= h($q['number'] ?? '') ?>
<?php if (!empty($q['required'])): ?>
<span style="color:#dc2626">*</span>
<?php endif; ?>
</div>

<h3><?= h($q['text']) ?></h3>

<?php
$choices = questionChoices(
    $data,
    $q['id']
);

$value =
    $answerValues[$q['id']]
    ?? ($q['type'] === QUESTION_MULTIPLE
        ? []
        : '');
?>

<?php if ($q['type'] === QUESTION_SINGLE): ?>

<?php foreach ($choices as $choice): ?>
<label class="choice">
<input type="radio"
       name="answers[<?= h($q['id']) ?>]"
       value="<?= h($choice['label']) ?>"
       <?= (string)$value ===
           (string)$choice['label']
           ? 'checked'
           : '' ?>>
<span><?= h($choice['label']) ?></span>
</label>
<?php endforeach; ?>

<?php elseif (
    $q['type'] === QUESTION_MULTIPLE
): ?>

<?php
if (!is_array($value)) {
    $value = [];
}
?>

<?php foreach ($choices as $choice): ?>
<label class="choice">
<input type="checkbox"
       name="answers[<?= h($q['id']) ?>][]"
       value="<?= h($choice['label']) ?>"
       <?= in_array(
           $choice['label'],
           $value,
           true
       ) ? 'checked' : '' ?>>
<span><?= h($choice['label']) ?></span>
</label>
<?php endforeach; ?>

<?php else: ?>

<textarea
 name="answers[<?= h($q['id']) ?>]"
 placeholder="回答を入力してください"><?= h((string)$value) ?></textarea>

<?php endif; ?>

</div>
<?php endforeach; ?>

<div class="answer-actions">
<div></div>
<button class="btn btn-primary"
        type="submit">
回答を確認する
</button>
</div>

</form>
</div>
</div>

<script>
function validateAnswerForm(){
    const required =
        document.querySelectorAll(
            '.question-card input, .question-card textarea'
        );

    /*
     * HTML5 required属性だけでは
     * checkbox/radioのグループ判定が複雑になるため、
     * PHP側で最終検証する。
     */
    return true;
}
</script>

</body>
</html>
<?php
exit;
}

/* =========================================================
 * 管理者ページ
 * ========================================================= */

requireAdmin();

renderHead($page, true);
renderAdminHeader($page);

?>
<div class="container">
<?php renderFlash($flash); ?>

<?php
/* =========================================================
 * 一覧
 * ========================================================= */

if ($page === 'list'):

    $keyword =
        trim((string)($_GET['q'] ?? ''));

    $filter =
        (string)($_GET['status'] ?? '');

    $sort =
        (string)($_GET['sort'] ?? 'updated');

    $surveys = $data['surveys'];

    $surveys = array_values(array_filter(
        $surveys,
        function ($s) use (
            $keyword,
            $filter
        ) {
            if (
                $keyword !== ''
                && mb_stripos(
                    (string)$s['title'],
                    $keyword
                ) === false
            ) {
                return false;
            }

            if (
                $filter !== ''
                && ($s['status'] ?? '') !== $filter
            ) {
                return false;
            }

            return true;
        }
    ));

    usort(
        $surveys,
        function ($a, $b) use ($sort) {
            if ($sort === 'answers') {
                return 0;
            }

            if ($sort === 'start') {
                return strcmp(
                    (string)$b['startAt'],
                    (string)$a['startAt']
                );
            }

            return strcmp(
                (string)$b['updatedAt'],
                (string)$a['updatedAt']
            );
        }
    );
?>

<div class="page-title">
<div>
<h1>アンケート一覧</h1>
<p class="muted">
管理業務の起点となるアンケート一覧です。
</p>
</div>

<a class="btn btn-primary"
   href="?page=edit">
新規作成
</a>
</div>

<div class="card">
<form method="get">
<input type="hidden" name="page" value="list">

<div class="grid">
<div>
<label>タイトル検索</label>
<input name="q"
       value="<?= h($keyword) ?>"
       placeholder="タイトルを入力">
</div>

<div>
<label>ステータス</label>
<select name="status">
<option value="">すべて</option>
<?php foreach ([
    STATUS_DRAFT,
    STATUS_PUBLISHED,
    STATUS_STOPPED,
    STATUS_ENDED
] as $st): ?>
<option value="<?= h($st) ?>"
 <?= $filter === $st ? 'selected' : '' ?>>
<?= h(statusLabel($st)) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div>
<label>ソート</label>
<select name="sort">
<option value="updated"
 <?= $sort === 'updated' ? 'selected' : '' ?>>
更新日
</option>
<option value="answers"
 <?= $sort === 'answers' ? 'selected' : '' ?>>
回答数
</option>
<option value="start"
 <?= $sort === 'start' ? 'selected' : '' ?>>
開始日時
</option>
</select>
</div>

<div style="display:flex;align-items:end">
<button class="btn btn-primary"
        type="submit">
検索
</button>
</div>
</div>
</form>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>作成日／更新日</th>
<th>タイトル</th>
<th>アンケート期間</th>
<th>ステータス</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>

<?php if (!$surveys): ?>
<tr>
<td colspan="6">
該当するアンケートはありません。
</td>
</tr>
<?php endif; ?>

<?php foreach ($surveys as $s): ?>
<tr>
<td>
<?= h($s['createdAt']) ?><br>
<span class="muted">
<?= h($s['updatedAt']) ?>
</span>
</td>

<td>
<strong><?= h($s['title']) ?></strong>
</td>

<td>
<?= h($s['startAt']) ?><br>
〜 <?= h($s['endAt']) ?>
</td>

<td>
<span class="badge <?= h(
    statusClass($s['status'])
) ?>">
<?= h(statusLabel($s['status'])) ?>
</span>
</td>

<td>
<?= surveyAnswerCount(
    $data,
    $s['id']
) ?>
</td>

<td>
<div class="actions">
<a class="btn btn-small"
   href="?page=edit&id=<?= urlencode($s['id']) ?>">
確認・編集
</a>

<a class="btn btn-small"
   href="?page=analysis&surveyId=<?= urlencode($s['id']) ?>">
集計
</a>

<a class="btn btn-small"
   href="?page=send&surveyId=<?= urlencode($s['id']) ?>">
送信
</a>

<form method="post"
      onsubmit="return confirm('アンケート「<?= h($s['title']) ?>」を複製しますか？')">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="id"
       value="<?= h($s['id']) ?>">
<button class="btn btn-small"
        type="submit">
複製
</button>
</form>

<form method="post"
      onsubmit="return confirm('アンケート「<?= h($s['title']) ?>」を削除しますか？回答・送信履歴も削除されます。')">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="id"
       value="<?= h($s['id']) ?>">
<button class="btn btn-small btn-danger"
        type="submit">
削除
</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>
</div>

<?php
/* =========================================================
 * 編集
 * =========================================================
 */

elseif ($page === 'edit'):

    $id = (string)($_GET['id'] ?? '');

    $s = $id !== ''
        ? survey($data, $id)
        : null;

    $isNew = $s === null;

    if ($isNew) {
        $s = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => STATUS_DRAFT,
            'numberingMode' => 'survey',
            'allowResubmission' => false,
        ];
    }

    $groups = surveyGroups(
        $data,
        $s['id']
    );

?>

<div class="page-title">
<div>
<h1>
<?= $isNew ? 'アンケート作成' : 'アンケート編集' ?>
</h1>
</div>

<div class="actions">

<?php if (!$isNew): ?>
<form method="post"
      onsubmit="return confirm('変更を破棄して一覧へ戻りますか？')">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="noop">
<button class="btn"
        type="button"
        onclick="confirmCancel()">
キャンセル
</button>
</form>
<?php else: ?>
<a class="btn"
   href="?page=list">
キャンセル
</a>
<?php endif; ?>

</div>
</div>

<div class="card">

<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="save_survey">
<input type="hidden"
       name="id"
       value="<?= h($s['id']) ?>">

<div class="grid-2">

<div>
<div class="form-row">
<label>タイトル *</label>
<input name="title"
       value="<?= h($s['title']) ?>"
       required>
</div>

<div class="form-row">
<label>説明</label>
<textarea name="description"><?= h(
    $s['description']
) ?></textarea>
</div>

<div class="form-row">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?= h($s['startAt']) ?>">
</div>

<div class="form-row">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?= h($s['endAt']) ?>">
</div>
</div>

<div>

<div class="form-row">
<label>質問番号</label>
<select name="numberingMode">
<option value="survey"
 <?= $s['numberingMode'] === 'survey'
     ? 'selected' : '' ?>>
アンケート全体で通番
</option>
<option value="group"
 <?= $s['numberingMode'] === 'group'
     ? 'selected' : '' ?>>
グループ毎に採番
</option>
</select>
</div>

<div class="form-row">
<label>再回答</label>
<label class="choice">
<input type="checkbox"
       name="allowResubmission"
       <?= !empty(
           $s['allowResubmission']
       ) ? 'checked' : '' ?>>
再回答を許可する
</label>
</div>

<?php if (!$isNew): ?>
<div class="form-row">
<label>現在の状態</label>

<select disabled>
<option>
<?= h(statusLabel($s['status'])) ?>
</option>
</select>
</div>
<?php endif; ?>

</div>

</div>

<div class="actions">
<button class="btn btn-primary"
        type="submit">
保存して一覧へ
</button>
</div>

</form>
</div>

<?php if (!$isNew): ?>

<div class="card">
<h2>状態変更</h2>

<?php
$allowedStatuses = [
    STATUS_DRAFT => [STATUS_PUBLISHED],
    STATUS_PUBLISHED => [STATUS_STOPPED],
    STATUS_STOPPED => [STATUS_PUBLISHED],
    STATUS_ENDED => [],
][$s['status']] ?? [];
?>

<?php if ($s['status'] === STATUS_ENDED): ?>

<p class="muted">
終了状態から変更することはできません。
</p>

<?php elseif (!$allowedStatuses): ?>

<p class="muted">
現在の状態から変更できる状態はありません。
</p>

<?php else: ?>

<form method="post"
      id="statusForm">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="id"
       value="<?= h($s['id']) ?>">

<select name="newStatus"
        onchange="changeStatus(this)">
<option value="">状態を選択</option>

<?php foreach ($allowedStatuses as $st): ?>
<option value="<?= h($st) ?>">
<?= h(statusLabel($st)) ?>
</option>
<?php endforeach; ?>

</select>

<button class="btn"
        type="submit"
        style="margin-top:10px">
状態変更
</button>
</form>

<script>
function changeStatus(select){
    if(!select.value)return;

    const label =
        select.options[select.selectedIndex].text;

    if(!confirm(
        '状態を「'+label+'」へ変更します。よろしいですか？'
    )){
        select.value='';
    }
}
</script>

<?php endif; ?>
</div>

<div class="card">
<div class="page-title">
<div>
<h2>グループ・質問</h2>
<p class="muted">
質問番号は自動採番されます。
</p>
</div>

<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="add_group">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<button class="btn btn-primary"
        type="submit">
グループ追加
</button>
</form>
</div>

<?php foreach ($groups as $gi => $g): ?>

<div class="card"
     style="background:#f8fafc">

<form method="post"
      class="actions"
      style="margin-bottom:12px">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="update_group">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<input type="hidden"
       name="groupId"
       value="<?= h($g['id']) ?>">

<input name="title"
       value="<?= h($g['title']) ?>"
       style="flex:1">

<button class="btn btn-small"
        type="submit">
グループ保存
</button>
</form>

<div class="actions"
     style="margin-bottom:10px">

<?php if ($gi > 0): ?>
<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="move_group">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<input type="hidden"
       name="groupId"
       value="<?= h($g['id']) ?>">
<input type="hidden"
       name="direction"
       value="up">
<button class="btn btn-small"
        type="submit">
↑
</button>
</form>
<?php endif; ?>

<?php if ($gi < count($groups) - 1): ?>
<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="move_group">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<input type="hidden"
       name="groupId"
       value="<?= h($g['id']) ?>">
<input type="hidden"
       name="direction"
       value="down">
<button class="btn btn-small"
        type="submit">
↓
</button>
</form>
<?php endif; ?>

<form method="post"
      onsubmit="return confirm('このグループと所属質問を削除しますか？')">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="delete_group">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<input type="hidden"
       name="groupId"
       value="<?= h($g['id']) ?>">
<button class="btn btn-small btn-danger"
        type="submit">
グループ削除
</button>
</form>

<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="add_question">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<input type="hidden"
       name="groupId"
       value="<?= h($g['id']) ?>">
<button class="btn btn-small btn-primary"
        type="submit">
質問追加
</button>
</form>
</div>

<?php
$questions = array_values(array_filter(
    $data['questions'],
    fn($q) =>
        ($q['groupId'] ?? '') === $g['id']
));

usort(
    $questions,
    fn($a, $b) =>
        (int)$a['order']
        <=> (int)$b['order']
);
?>

<?php foreach (
    $questions as $qi => $q
): ?>

<div class="question-card">

<div class="actions"
     style="justify-content:space-between">
<strong>
Q<?= h($q['number'] ?? '') ?>
</strong>

<div class="actions">

<?php if ($qi > 0): ?>
<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="move_question">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<input type="hidden"
       name="questionId"
       value="<?= h($q['id']) ?>">
<input type="hidden"
       name="direction"
       value="up">
<button class="btn btn-small"
        type="submit">↑</button>
</form>
<?php endif; ?>

<?php if ($qi < count($questions)-1): ?>
<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="move_question">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<input type="hidden"
       name="questionId"
       value="<?= h($q['id']) ?>">
<input type="hidden"
       name="direction"
       value="down">
<button class="btn btn-small"
        type="submit">↓</button>
</form>
<?php endif; ?>

<form method="post"
      onsubmit="return confirm('この質問を削除しますか？')">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="delete_question">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<input type="hidden"
       name="questionId"
       value="<?= h($q['id']) ?>">
<button class="btn btn-small btn-danger"
        type="submit">
削除
</button>
</form>

</div>
</div>

<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="update_question">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<input type="hidden"
       name="questionId"
       value="<?= h($q['id']) ?>">

<div class="form-row">
<label>質問文</label>
<input name="text"
       value="<?= h($q['text']) ?>"
       required>
</div>

<div class="grid-2">

<div>
<label>回答形式</label>
<select name="type">
<?php foreach ([
    QUESTION_SINGLE,
    QUESTION_MULTIPLE,
    QUESTION_TEXT
] as $type): ?>
<option value="<?= h($type) ?>"
 <?= $q['type'] === $type
     ? 'selected' : '' ?>>
<?= h(typeLabel($type)) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div>
<label>回答ルール</label>
<label class="choice">
<input type="checkbox"
       name="required"
       <?= !empty($q['required'])
           ? 'checked' : '' ?>>
必須回答
</label>
</div>

</div>

<?php if (
    $q['type'] !== QUESTION_TEXT
): ?>

<div class="form-row">
<label>選択肢</label>

<?php foreach (
    questionChoices($data, $q['id'])
    as $choice
): ?>

<div class="actions"
     style="margin-bottom:6px">

<input name="choiceLabel[<?= h($choice['id']) ?>]"
       value="<?= h($choice['label']) ?>">

<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="delete_choice">
<input type="hidden"
       name="surveyId"
       value="<?= h($s['id']) ?>">
<input type="hidden"
       name="questionId"
       value="<?= h($q['id']) ?>">
<input type="hidden"
       name="choiceId"
       value="<?= h($choice['id']) ?>">
<button class="btn btn-small btn-danger"
        type="submit">
削除
</button>
</form>

</div>
<?php endforeach; ?>

<input name="newChoiceLabel[]"
       placeholder="新しい選択肢">

</div>

<?php endif; ?>

<?php
$branch =
    $q['branchRules'][0]
    ?? null;
?>

<?php if (
    $q['type'] === QUESTION_SINGLE
): ?>

<div class="form-row">
<label>条件分岐</label>

<div class="grid-2">
<div>
<select name="branchValue">
<option value="">分岐なし</option>

<?php foreach (
    questionChoices($data, $q['id'])
    as $choice
): ?>
<option value="<?= h($choice['label']) ?>"
 <?= (
     $branch['value'] ?? ''
 ) === $choice['label']
     ? 'selected' : '' ?>>
<?= h($choice['label']) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div>
<select name="branchTarget">
<option value="">表示先なし</option>

<?php foreach (
    surveyQuestions($data, $s['id'])
    as $target
): ?>

<?php if (
    $target['id'] === $q['id']
) continue; ?>

<option value="<?= h($target['id']) ?>"
 <?= (
     $branch['targetQuestionId'] ?? ''
 ) === $target['id']
     ? 'selected' : '' ?>>
Q<?= h($target['number'] ?? '') ?>
:
<?= h($target['text']) ?>
</option>

<?php endforeach; ?>
</select>
</div>
</div>
</div>

<?php endif; ?>

<button class="btn btn-primary"
        type="submit">
質問を保存
</button>

</form>
</div>

<?php endforeach; ?>

</div>
<?php endforeach; ?>

</div>

<?php
/* =========================================================
 * 集計
 * =========================================================
 */

elseif ($page === 'analysis'):

    $surveyId = (string)(
        $_GET['surveyId'] ?? ''
    );

    $s = survey($data, $surveyId);

    if (!$s) {
        echo '<div class="card">';
        echo '<h1>対象アンケートがありません</h1>';
        echo '</div>';
    } else {

        $answers = array_values(array_filter(
            $data['answers'],
            fn($a) =>
                ($a['surveyId'] ?? '') === $surveyId
                && ($a['status'] ?? '') === 'submitted'
        ));

        $customersSent = [];

        foreach ($data['sendHistories'] as $h) {
            if (($h['surveyId'] ?? '') !== $surveyId) {
                continue;
            }

            foreach (
                ($h['recipients'] ?? [])
                as $r
            ) {
                $customersSent[
                    $r['customerId']
                ] = true;
            }
        }

        $sentCount = count($customersSent);
        $answerCount = count($answers);

        $rate = $sentCount > 0
            ? round(
                $answerCount /
                $sentCount *
                100,
                1
            )
            : 0;
?>

<div class="page-title">
<div>
<h1>回答集計・分析</h1>
<p class="muted">
対象アンケート:
<strong><?= h($s['title']) ?></strong>
</p>
</div>

<div class="actions">
<a class="btn"
   href="?page=csv&surveyId=<?= urlencode($surveyId) ?>">
CSV出力
</a>

<a class="btn"
   href="?page=pdf&surveyId=<?= urlencode($surveyId) ?>">
PDF出力
</a>
</div>
</div>

<div class="grid">
<div class="stat">
<div class="label">回答数</div>
<div class="value"><?= $answerCount ?></div>
</div>

<div class="stat">
<div class="label">送信済み</div>
<div class="value"><?= $sentCount ?></div>
</div>

<div class="stat">
<div class="label">未回答</div>
<div class="value">
<?= max(
    0,
    $sentCount - $answerCount
) ?>
</div>
</div>

<div class="stat">
<div class="label">回答率</div>
<div class="value"><?= h($rate) ?>%</div>
</div>
</div>

<?php if ($answerCount === 0): ?>

<div class="card">
<h2>回答データがありません</h2>
<p>
現在、回答は登録されていません。
</p>
</div>

<?php else: ?>

<div class="card">
<h2>設問別集計</h2>

<?php foreach (
    surveyQuestions($data, $surveyId)
    as $q
): ?>

<div class="question-card">
<h3>
Q<?= h($q['number'] ?? '') ?>
<?= h($q['text']) ?>
</h3>

<?php if (
    $q['type'] === QUESTION_TEXT
): ?>

<?php foreach ($answers as $a): ?>
<?php
$v =
    $a['answers'][$q['id']]
    ?? '';
?>
<?php if ((string)$v !== ''): ?>
<div style="border-top:1px solid #e2e8f0;padding:10px 0">
<strong>
<?= h(
    $a['respondentInfo']['name']
    ?? $a['customerId']
    ?? '未登録'
) ?>
</strong>
<p><?= nl2br(h((string)$v)) ?></p>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php else: ?>

<?php
$choices = questionChoices(
    $data,
    $q['id']
);

$total = 0;

foreach ($answers as $a) {
    $v = $a['answers'][$q['id']] ?? null;

    if (is_array($v)) {
        $total += count($v);
    } elseif (
        $v !== null
        && $v !== ''
    ) {
        $total++;
    }
}
?>

<?php foreach ($choices as $choice): ?>

<?php
$count = 0;

foreach ($answers as $a) {
    $v =
        $a['answers'][$q['id']]
        ?? null;

    if (is_array($v)) {
        if (
            in_array(
                $choice['label'],
                $v,
                true
            )
        ) {
            $count++;
        }
    } elseif (
        (string)$v ===
        (string)$choice['label']
    ) {
        $count++;
    }
}

$pct = $total > 0
    ? round($count / $total * 100, 1)
    : 0;
?>

<div class="bar-row">
<div><?= h($choice['label']) ?></div>
<div class="bar">
<span style="width:<?= h($pct) ?>%"></span>
</div>
<div>
<?= $count ?>件
(<?= $pct ?>%)
</div>
</div>

<?php endforeach; ?>

<?php endif; ?>
</div>

<?php endforeach; ?>
</div>

<div class="card">
<h2>個別回答</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>回答日時</th>
<th>回答者</th>
<th>メール</th>
<th>顧客</th>
<th>詳細</th>
</tr>
</thead>
<tbody>

<?php foreach ($answers as $a): ?>

<tr>
<td><?= h($a['submittedAt']) ?></td>
<td>
<?= h(
    $a['respondentInfo']['name']
    ?? '未登録'
) ?>
</td>
<td>
<?= h(
    $a['respondentInfo']['email']
    ?? ''
) ?>
</td>
<td>
<?= h(
    $a['customerId']
    ?? '未登録'
) ?>
</td>
<td>
<details>
<summary>回答を見る</summary>

<?php foreach (
    surveyQuestions($data, $surveyId)
    as $q
): ?>
<div style="margin:8px 0">
<strong>
Q<?= h($q['number'] ?? '') ?>
<?= h($q['text']) ?>
</strong><br>

<?php
$v =
    $a['answers'][$q['id']]
    ?? '';

if (is_array($v)) {
    echo h(implode('、', $v));
} else {
    echo nl2br(h((string)$v));
}
?>
</div>
<?php endforeach; ?>

</details>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>
</div>

<?php endif; ?>

<?php
    }

/* =========================================================
 * 顧客・メール送信
 * =========================================================
 */

elseif ($page === 'send'):

    $surveyId = (string)(
        $_GET['surveyId'] ?? ''
    );

    $s = survey($data, $surveyId);

    if (!$s) {
        echo '<div class="card">';
        echo '<h1>対象アンケートがありません</h1>';
        echo '</div>';
    } else {

        $tab =
            (string)($_GET['tab'] ?? 'customers');

        $customerKeyword =
            trim((string)(
                $_GET['q'] ?? ''
            ));

        $customerStatus =
            (string)(
                $_GET['customerStatus'] ?? ''
            );

        $customers = $data['customers'];

        $customers = array_values(array_filter(
            $customers,
            function ($c) use (
                $customerKeyword,
                $customerStatus,
                $data,
                $surveyId
            ) {
                if ($customerKeyword !== '') {
                    $haystack =
                        ($c['organizationName'] ?? '')
                        . ' '
                        . ($c['name'] ?? '')
                        . ' '
                        . ($c['email'] ?? '');

                    if (
                        mb_stripos(
                            $haystack,
                            $customerKeyword
                        ) === false
                    ) {
                        return false;
                    }
                }

                if ($customerStatus !== '') {
                    $st = customerSurveyStatus(
                        $data,
                        $surveyId,
                        $c['id']
                    )['status'];

                    if ($st !== $customerStatus) {
                        return false;
                    }
                }

                return true;
            }
        ));

        $sendResult =
            $_SESSION['send_result'] ?? null;

        unset($_SESSION['send_result']);
?>

<div class="page-title">
<div>
<h1>顧客選択・メール送信</h1>
<p>
対象アンケート:
<strong><?= h($s['title']) ?></strong>
</p>
</div>
</div>

<div class="card">
<div class="actions">
<a class="btn <?= $tab === 'customers' ? 'btn-primary' : '' ?>"
   href="?page=send&surveyId=<?= urlencode($surveyId) ?>&tab=customers">
顧客選択
</a>

<a class="btn <?= $tab === 'content' ? 'btn-primary' : '' ?>"
   href="?page=send&surveyId=<?= urlencode($surveyId) ?>&tab=content">
送信内容
</a>

<a class="btn <?= $tab === 'result' ? 'btn-primary' : '' ?>"
   href="?page=send&surveyId=<?= urlencode($surveyId) ?>&tab=result">
送信結果
</a>

<a class="btn <?= $tab === 'history' ? 'btn-primary' : '' ?>"
   href="?page=send&surveyId=<?= urlencode($surveyId) ?>&tab=history">
送信履歴
</a>
</div>
</div>

<?php if ($tab === 'customers'): ?>

<div class="card">
<form method="get">
<input type="hidden"
       name="page"
       value="send">
<input type="hidden"
       name="surveyId"
       value="<?= h($surveyId) ?>">
<input type="hidden"
       name="tab"
       value="customers">

<div class="grid">
<div>
<label>顧客検索</label>
<input name="q"
       value="<?= h($customerKeyword) ?>"
       placeholder="会社名・氏名・メール">
</div>

<div>
<label>回答状況</label>
<select name="customerStatus">
<option value="">すべて</option>
<option value="未送信"
 <?= $customerStatus === '未送信'
     ? 'selected' : '' ?>>
未送信
</option>
<option value="送信済み／未回答"
 <?= $customerStatus === '送信済み／未回答'
     ? 'selected' : '' ?>>
送信済み／未回答
</option>
<option value="回答済み"
 <?= $customerStatus === '回答済み'
     ? 'selected' : '' ?>>
回答済み
</option>
</select>
</div>

<div style="display:flex;align-items:end">
<button class="btn btn-primary"
        type="submit">
検索
</button>
</div>
</div>
</form>
</div>

<div class="card">
<form method="post"
      onsubmit="return confirmSend(this)">
<?= csrfField() ?>

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="surveyId"
       value="<?= h($surveyId) ?>">

<input type="hidden"
       name="subject"
       id="subjectHidden"
       value="">

<input type="hidden"
       name="body"
       id="bodyHidden"
       value="">

<input type="hidden"
       name="sendType"
       value="bulk">

<div class="table-wrap">
<table>
<thead>
<tr>
<th></th>
<th>会社名</th>
<th>氏名</th>
<th>メール</th>
<th>回答状況</th>
<th>最終送信日時</th>
<th>送信回数</th>
<th>kintone</th>
</tr>
</thead>

<tbody>
<?php foreach ($customers as $c): ?>

<?php
$st = customerSurveyStatus(
    $data,
    $surveyId,
    $c['id']
);
?>

<tr>
<td>
<input type="checkbox"
       name="customerIds[]"
       value="<?= h($c['id']) ?>"
       data-status="<?= h($st['status']) ?>">
</td>

<td><?= h($c['organizationName']) ?></td>
<td><?= h($c['name']) ?></td>
<td><?= h($c['email']) ?></td>
<td><?= h($st['status']) ?></td>
<td><?= h($st['lastSentAt'] ?? '') ?></td>
<td><?= h($st['sendCount']) ?></td>
<td><?= h($c['kintoneStatus']) ?></td>
</tr>

<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="card"
     style="margin-top:18px">
<h3>送信内容</h3>

<div class="form-row">
<label>件名</label>
<input id="subject"
       value="<?= h(
           $_SESSION['mail_subject']
           ?? 'アンケートのご案内'
       ) ?>"
       required>
</div>

<div class="form-row">
<label>本文</label>
<textarea id="body"
          required><?= h(
              $_SESSION['mail_body']
              ?? "{{顧客名}} 様\n\n"
              . "アンケートへのご協力をお願いいたします。\n\n"
              . "{{アンケートURL}}\n"
          ) ?></textarea>
</div>

<p class="muted">
差し込み変数:
<code>{{顧客名}}</code>
<code>{{会社名}}</code>
<code>{{アンケートURL}}</code>
</p>
</div>

<button class="btn btn-primary"
        type="submit">
一括送信
</button>

</form>
</div>

<script>
function confirmSend(form){
    const checked =
        form.querySelectorAll(
            'input[name="customerIds[]"]:checked'
        );

    if(!checked.length){
        alert('送信対象を選択してください。');
        return false;
    }

    document.getElementById('subjectHidden').value =
        document.getElementById('subject').value;

    document.getElementById('bodyHidden').value =
        document.getElementById('body').value;

    let hasSent = false;

    checked.forEach(function(el){
        if(
            el.dataset.status ===
            '送信済み／未回答'
            ||
            el.dataset.status === '回答済み'
        ){
            hasSent = true;
        }
    });

    if(hasSent){
        return confirm(
            '送信済みの顧客が含まれています。\n'
            + '再送として送信しますか？'
        );
    }

    return confirm(
        checked.length
        + '件にメールを送信します。よろしいですか？'
    );
}
</script>

<?php elseif ($tab === 'content'): ?>

<div class="card">
<h2>送信内容</h2>

<p>
顧客名:
<code>{{顧客名}}</code>
</p>

<p>
会社名:
<code>{{会社名}}</code>
</p>

<p>
個別アンケートURL:
<code>{{アンケートURL}}</code>
</p>

<p class="muted">
実際の送信はSMTP設定に従って行われます。
</p>
</div>

<?php elseif ($tab === 'result'): ?>

<div class="card">
<h2>送信結果</h2>

<?php if ($sendResult): ?>

<div class="grid">
<div class="stat">
<div class="label">対象件数</div>
<div class="value">
<?= h($sendResult['target']) ?>
</div>
</div>

<div class="stat">
<div class="label">成功件数</div>
<div class="value">
<?= h($sendResult['success']) ?>
</div>
</div>

<div class="stat">
<div class="label">失敗件数</div>
<div class="value">
<?= h($sendResult['failure']) ?>
</div>
</div>

<div class="stat">
<div class="label">送信日時</div>
<div class="value"
     style="font-size:15px">
<?= h($sendResult['sentAt']) ?>
</div>
</div>
</div>

<?php else: ?>

<p class="muted">
送信結果はありません。
</p>

<?php endif; ?>
</div>

<?php elseif ($tab === 'history'): ?>

<div class="card">
<h2>送信履歴</h2>

<?php
$histories = array_values(array_filter(
    $data['sendHistories'],
    fn($h) =>
        ($h['surveyId'] ?? '') === $surveyId
));

usort(
    $histories,
    fn($a, $b) =>
        strcmp(
            (string)$b['sentAt'],
            (string)$a['sentAt']
        )
);
?>

<?php if (!$histories): ?>

<p class="muted">
送信履歴はありません。
</p>

<?php else: ?>

<?php foreach ($histories as $h): ?>

<details class="question-card">
<summary>
<?= h($h['sentAt']) ?>
｜
<?= h($h['subject']) ?>
｜
<?= h($h['count']) ?>件
</summary>

<p>
送信種別:
<?= h($h['sendType']) ?>
</p>

<p>
成功:
<?= h($h['successCount'] ?? 0) ?>件
<br>
失敗:
<?= h($h['failureCount'] ?? 0) ?>件
</p>

<p>
本文テンプレート:
</p>

<pre><?= h(
    $h['bodyTemplate']
    ?? ''
) ?></pre>

<?php foreach (
    ($h['messages'] ?? [])
    as $message
): ?>

<div class="question-card">
<strong>
<?= h($message['email'] ?? '') ?>
</strong>

<?php if (
    !empty($message['success'])
): ?>

<p class="notice success">
送信成功
</p>

<p>
差し込み後本文:
</p>

<pre><?= h(
    $message['body'] ?? ''
) ?></pre>

<p>
個別URL:
<br>
<?= h($message['url'] ?? '') ?>
</p>

<?php else: ?>

<p class="notice error">
送信失敗:
<?= h($message['error'] ?? '') ?>
</p>

<?php endif; ?>
</div>

<?php endforeach; ?>

</details>

<?php endforeach; ?>

<?php endif; ?>
</div>

<?php endif; ?>

<?php
    }

/* =========================================================
 * kintone
 * =========================================================
 */

elseif ($page === 'kintone'):

    $k =
        $data['settings']['kintone'];

    $result =
        $_SESSION['kintone_result']
        ?? null;

    unset($_SESSION['kintone_result']);

    $fields =
        $_SESSION['kintone_fields']
        ?? [];

?>

<div class="page-title">
<div>
<h1>kintone連携設定</h1>
<p class="muted">
接続テスト、設定保存、項目取得、顧客同期は独立操作です。
</p>
</div>
</div>

<?php if ($result): ?>
<div class="notice <?= $result['success']
    ? 'success'
    : 'error' ?>">
<?= h($result['message']) ?>
</div>
<?php endif; ?>

<div class="card">
<h2>接続情報</h2>

<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid-2">

<div class="form-row">
<label>サブドメイン</label>
<input name="subdomain"
       value="<?= h($k['subdomain']) ?>"
       placeholder="example">
</div>

<div class="form-row">
<label>顧客管理アプリID</label>
<input name="appId"
       value="<?= h($k['appId']) ?>">
</div>

<div class="form-row">
<label>ログイン名</label>
<input name="loginName"
       value="<?= h($k['loginName']) ?>"
       autocomplete="username">
</div>

<div class="form-row">
<label>パスワード</label>
<input type="password"
       name="password"
       value="<?= h($k['password']) ?>"
       autocomplete="current-password">
</div>

</div>

<label class="choice">
<input type="checkbox"
       name="sslVerify"
       <?= !empty($k['sslVerify'])
           ? 'checked' : '' ?>>
SSL証明書を検証する
</label>

<div class="actions"
     style="margin-top:15px">
<button class="btn btn-primary"
        type="submit">
設定保存
</button>
</div>
</form>

<hr>

<h3>接続テスト</h3>

<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="test_kintone">

<input type="hidden"
       name="subdomain"
       value="<?= h($k['subdomain']) ?>">
<input type="hidden"
       name="appId"
       value="<?= h($k['appId']) ?>">
<input type="hidden"
       name="loginName"
       value="<?= h($k['loginName']) ?>">
<input type="hidden"
       name="password"
       value="<?= h($k['password']) ?>">

<input type="hidden"
       name="sslVerify"
       value="<?= !empty($k['sslVerify'])
           ? '1' : '' ?>">

<button class="btn"
        type="submit">
接続テスト
</button>
</form>
</div>

<div class="card">
<h2>項目一覧・マッピング</h2>

<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="fetch_kintone_fields">

<button class="btn"
        type="submit">
項目一覧を再取得
</button>
</form>

<?php if ($fields): ?>

<form method="post"
      style="margin-top:15px">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="save_kintone">

<input type="hidden"
       name="subdomain"
       value="<?= h($k['subdomain']) ?>">
<input type="hidden"
       name="appId"
       value="<?= h($k['appId']) ?>">
<input type="hidden"
       name="loginName"
       value="<?= h($k['loginName']) ?>">
<input type="hidden"
       name="password"
       value="<?= h($k['password']) ?>">

<input type="hidden"
       name="sslVerify"
       value="<?= !empty($k['sslVerify'])
           ? '1' : '' ?>">

<div class="grid-2">

<?php foreach ([
    'organizationName' => '会社名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署',
    'phone' => '電話番号',
    'address' => '住所'
] as $logical => $label): ?>

<div class="form-row">
<label><?= h($label) ?></label>
<select name="fieldMapping[<?= h($logical) ?>]">
<option value="">未設定</option>

<?php foreach ($fields as $code => $field): ?>
<?php
$type =
    $field['type']
    ?? '';

if (
    $logical === 'email'
    && $type !== 'SINGLE_LINE_TEXT'
) {
    /*
     * メール項目以外でも運用できるよう
     * UIでは選択可能とする。
     */
}
?>
<option value="<?= h($code) ?>"
 <?= (
     $k['fieldMapping'][$logical]
     ?? ''
 ) === $code
     ? 'selected'
     : '' ?>>
<?= h(
    ($field['label'] ?? $code)
    . ' [' . $code . ']'
) ?>
</option>
<?php endforeach; ?>

</select>
</div>

<?php endforeach; ?>

</div>

<h3>住所マッピング</h3>

<?php foreach ($fields as $code => $field): ?>
<label class="choice">
<input type="checkbox"
       name="addressFields[]"
       value="<?= h($code) ?>"
       <?= in_array(
           $code,
           $k['addressFields'] ?? [],
           true
       ) ? 'checked' : '' ?>>
<?= h(
    ($field['label'] ?? $code)
    . ' [' . $code . ']'
) ?>
</label>
<?php endforeach; ?>

<button class="btn btn-primary"
        type="submit">
マッピング保存
</button>

</form>

<?php endif; ?>
</div>

<div class="card">
<h2>顧客情報同期</h2>

<p class="muted">
保存済みのkintone設定を使用して顧客情報を同期します。
</p>

<form method="post"
      onsubmit="return confirm('kintoneから顧客情報を同期しますか？')">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-primary"
        type="submit">
顧客情報を同期
</button>
</form>
</div>

<?php
/* =========================================================
 * SMTP
 * =========================================================
 */

elseif ($page === 'smtp'):

    $smtp =
        $data['settings']['smtp'];

    $smtpResult =
        $_SESSION['smtp_result']
        ?? null;

    unset($_SESSION['smtp_result']);

?>

<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
<p class="muted">
SMTP接続情報とテスト送信を管理します。
</p>
</div>
</div>

<?php if ($smtpResult): ?>
<div class="notice <?= $smtpResult['success']
    ? 'success'
    : 'error' ?>">
<?= h($smtpResult['message']) ?>
</div>
<?php endif; ?>

<div class="card">
<h2>SMTP設定</h2>

<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="save_smtp">

<div class="grid-2">

<div class="form-row">
<label>SMTPサーバ</label>
<input name="server"
       value="<?= h($smtp['server']) ?>">
</div>

<div class="form-row">
<label>SMTPポート</label>
<input type="number"
       name="port"
       value="<?= h($smtp['port']) ?>">
</div>

<div class="form-row">
<label>暗号化方式</label>
<select name="encryption">
<?php foreach (
    ['SSL','TLS','NONE']
    as $enc
): ?>
<option value="<?= h($enc) ?>"
 <?= $smtp['encryption'] === $enc
     ? 'selected' : '' ?>>
<?= h(
    $enc === 'NONE'
        ? 'なし'
        : $enc
) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="form-row">
<label>SMTP認証</label>
<label class="choice">
<input type="checkbox"
       name="auth"
       <?= !empty($smtp['auth'])
           ? 'checked' : '' ?>>
SMTP認証を使用する
</label>
</div>

<div class="form-row">
<label>SMTPユーザー名</label>
<input name="username"
       value="<?= h($smtp['username']) ?>">
</div>

<div class="form-row">
<label>SMTPパスワード</label>
<input type="password"
       name="password"
       value="<?= h($smtp['password']) ?>">
</div>

<div class="form-row">
<label>送信元メールアドレス</label>
<input type="email"
       name="from"
       value="<?= h($smtp['from']) ?>">
</div>

<div class="form-row">
<label>送信者名</label>
<input name="senderName"
       value="<?= h($smtp['senderName']) ?>">
</div>

<div class="form-row">
<label>返信先メールアドレス</label>
<input type="email"
       name="replyTo"
       value="<?= h($smtp['replyTo']) ?>">
</div>

</div>

<div class="actions">
<button class="btn btn-primary"
        type="submit">
設定保存
</button>
</div>
</form>
</div>

<div class="card">
<h2>テストメール</h2>

<form method="post">
<?= csrfField() ?>
<input type="hidden"
       name="action"
       value="test_smtp">

<input type="hidden"
       name="server"
       value="<?= h($smtp['server']) ?>">
<input type="hidden"
       name="port"
       value="<?= h($smtp['port']) ?>">
<input type="hidden"
       name="encryption"
       value="<?= h($smtp['encryption']) ?>">
<input type="hidden"
       name="auth"
       value="<?= !empty($smtp['auth'])
           ? '1' : '' ?>">
<input type="hidden"
       name="username"
       value="<?= h($smtp['username']) ?>">
<input type="hidden"
       name="password"
       value="<?= h($smtp['password']) ?>">
<input type="hidden"
       name="from"
       value="<?= h($smtp['from']) ?>">
<input type="hidden"
       name="senderName"
       value="<?= h($smtp['senderName']) ?>">
<input type="hidden"
       name="replyTo"
       value="<?= h($smtp['replyTo']) ?>">

<div class="form-row">
<label>テスト送信先</label>
<input type="email"
       name="testTo"
       required>
</div>

<button class="btn btn-primary"
        type="submit">
テストメール送信
</button>
</form>
</div>

<?php
/* =========================================================
 * 不明ページ
 * =========================================================
 */

else:
?>

<div class="card">
<h1>ページが見つかりません</h1>
<a class="btn"
   href="?page=list">
アンケート一覧へ
</a>
</div>

<?php endif; ?>

</div>

<script>
function confirmCancel(){
    if(confirm(
        '変更を破棄して一覧へ戻りますか？'
    )){
        location.href='?page=list';
    }
}
</script>

</body>
</html>
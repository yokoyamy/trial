<?php
declare(strict_types=1);

/*
 * ============================================================
 * アンケート管理システム
 * index.php 単体実装
 *
 * GUARD:
 * - 本ファイル以外のPHPプログラムは使用しない
 * - 永続データは data/*.json
 * - DBは使用しない
 * - 管理者認証は実装しない
 * - 回答者認証は実装しない
 * - 回答者UIから管理者UIへの導線を作らない
 * - kintone接続情報はHTML/JavaScriptへパスワードを出力しない
 * - kintone proxy は host:port の1項目、認証なし
 * - sslVerify の初期値は false
 * - 終了判定は published のみ
 * - 集計・送信は必ず surveyId を指定する
 * - fetch失敗とAPIエラーを区別する
 * ============================================================
 */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const FILES = [
    'surveys'     => DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json',
    'customers'   => DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json',
    'responses'   => DATA_DIR . DIRECTORY_SEPARATOR . 'responses.json',
    'history'     => DATA_DIR . DIRECTORY_SEPARATOR . 'send_history.json',
    'kintone'     => DATA_DIR . DIRECTORY_SEPARATOR . 'kintone.json',
    'mail'        => DATA_DIR . DIRECTORY_SEPARATOR . 'mail.json',
];

date_default_timezone_set('Asia/Tokyo');

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400, array $extra = []): never
{
    jsonResponse(array_merge([
        'success' => false,
        'error'   => $message,
    ], $extra), $status);
}

function readJson(string $name, mixed $default = []): mixed
{
    $file = FILES[$name] ?? null;
    if (!$file) {
        return $default;
    }
    if (!is_file($file)) {
        return $default;
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $value = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $value : $default;
}

function writeJson(string $name, mixed $data): void
{
    $file = FILES[$name] ?? null;
    if (!$file) {
        throw new RuntimeException('不正なデータファイルです。');
    }

    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('dataディレクトリを作成できません。');
    }

    $fp = fopen($file, 'c+');
    if (!$fp) {
        throw new RuntimeException('データファイルを開けません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データファイルをロックできません。');
        }

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

        if ($json === false) {
            throw new RuntimeException('JSON生成に失敗しました。');
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

function id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function nowIso(): string
{
    return date('c');
}

function normalizeSubdomain(string $value): string
{
    $value = trim($value);
    $value = preg_replace('#^https?://#i', '', $value) ?? $value;
    $value = trim($value, "/ \t\r\n");
    $value = preg_replace('#\.cybozu\.com$#i', '', $value) ?? $value;
    $value = preg_replace('#[^a-zA-Z0-9._-]#', '', $value) ?? $value;
    return strtolower($value);
}

function kintoneHost(array $settings): string
{
    $sub = normalizeSubdomain((string)($settings['subdomain'] ?? ''));
    if ($sub === '') {
        throw new RuntimeException('kintoneサブドメインが未設定です。');
    }
    return 'https://' . $sub . '.cybozu.com';
}

function kintoneSettings(): array
{
    $x = readJson('kintone', []);
    return array_merge([
        'subdomain' => '',
        'appId'     => '',
        'loginName' => '',
        'password'  => '',
        'sslVerify' => false,
        'proxy'     => '',
        'fields'    => [],
        'mapping'   => [
            'organization' => '',
            'name'         => '',
            'email'        => '',
            'department'   => '',
            'phone'        => '',
            'address'      => [],
        ],
    ], is_array($x) ? $x : []);
}

function curlJson(
    string $url,
    string $method,
    array $headers,
    ?array $body,
    bool $sslVerify,
    string $proxy = ''
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL拡張が有効ではありません。');
    }

    $ch = curl_init($url);
    if (!$ch) {
        throw new RuntimeException('cURLを初期化できません。');
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => $sslVerify,
        CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
    ];

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    if ($proxy !== '') {
        $proxy = trim($proxy);
        if (!preg_match('/^[^:\s]+:\d+$/', $proxy)) {
            curl_close($ch);
            throw new RuntimeException('プロキシは host:port 形式で入力してください。');
        }
        $opts[CURLOPT_PROXY] = $proxy;
        $opts[CURLOPT_PROXYAUTH] = CURLAUTH_NONE;
    }

    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException(
            'kintone API通信に失敗しました。' .
            ($errno ? " cURL {$errno}: {$error}" : '')
        );
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException(
            "kintone APIがJSON以外を返しました。HTTP {$http}"
        );
    }

    if ($http < 200 || $http >= 300) {
        $message = (string)($data['message'] ?? 'APIエラー');
        throw new RuntimeException("kintone APIエラー HTTP {$http}: {$message}");
    }

    return $data;
}

function kintoneRequest(string $path, string $method = 'GET', ?array $body = null): array
{
    $s = kintoneSettings();

    if ((string)$s['appId'] === '') {
        throw new RuntimeException('顧客管理アプリIDが未設定です。');
    }
    if ((string)$s['loginName'] === '') {
        throw new RuntimeException('kintoneログイン名が未設定です。');
    }
    if ((string)$s['password'] === '') {
        throw new RuntimeException('kintoneパスワードが未設定です。');
    }

    $url = kintoneHost($s) . $path;

    $headers = [
        'Content-Type: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode($s['loginName'] . ':' . $s['password']),
    ];

    return curlJson(
        $url,
        $method,
        $headers,
        $body,
        (bool)$s['sslVerify'],
        (string)$s['proxy']
    );
}

function ensureData(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }

    foreach (FILES as $name => $file) {
        if (is_file($file)) {
            continue;
        }

        if ($name === 'surveys') {
            $past = date('c', strtotime('-2 days'));
            $future = date('c', strtotime('+30 days'));

            $g1 = id('grp');
            $q1 = id('q');
            $q2 = id('q');
            $c1 = id('choice');
            $c2 = id('choice');

            $surveys = [
                [
                    'id' => 'survey_draft',
                    'title' => 'サンプル・下書き',
                    'description' => '下書き状態のサンプルアンケートです。',
                    'startDate' => date('c'),
                    'endDate' => $future,
                    'status' => 'draft',
                    'numbering' => 'all',
                    'allowResubmit' => false,
                    'createdAt' => nowIso(),
                    'updatedAt' => nowIso(),
                    'groups' => [[
                        'id' => $g1,
                        'title' => '基本情報',
                        'sortOrder' => 1,
                        'questions' => [
                            [
                                'id' => $q1,
                                'groupId' => $g1,
                                'sortOrder' => 1,
                                'text' => '今回のサービスについて教えてください。',
                                'type' => 'single',
                                'required' => true,
                                'choices' => [
                                    ['id' => $c1, 'label' => '満足'],
                                    ['id' => $c2, 'label' => '不満'],
                                ],
                                'branching' => [],
                            ],
                            [
                                'id' => $q2,
                                'groupId' => $g1,
                                'sortOrder' => 2,
                                'text' => 'ご意見を入力してください。',
                                'type' => 'text',
                                'required' => false,
                                'choices' => [],
                                'branching' => [],
                            ],
                        ],
                    ]],
                ],
                [
                    'id' => 'survey_published',
                    'title' => 'サンプル・公開中',
                    'description' => '公開中のサンプルです。',
                    'startDate' => date('c', strtotime('-1 day')),
                    'endDate' => $future,
                    'status' => 'published',
                    'numbering' => 'all',
                    'allowResubmit' => false,
                    'createdAt' => nowIso(),
                    'updatedAt' => nowIso(),
                    'groups' => [],
                ],
                [
                    'id' => 'survey_stopped',
                    'title' => 'サンプル・停止',
                    'description' => '停止状態のサンプルです。',
                    'startDate' => date('c', strtotime('-5 days')),
                    'endDate' => $past,
                    'status' => 'stopped',
                    'numbering' => 'all',
                    'allowResubmit' => false,
                    'createdAt' => nowIso(),
                    'updatedAt' => nowIso(),
                    'groups' => [],
                ],
                [
                    'id' => 'survey_finished',
                    'title' => 'サンプル・終了',
                    'description' => '終了状態のサンプルです。',
                    'startDate' => date('c', strtotime('-10 days')),
                    'endDate' => $past,
                    'status' => 'finished',
                    'numbering' => 'all',
                    'allowResubmit' => false,
                    'createdAt' => nowIso(),
                    'updatedAt' => nowIso(),
                    'groups' => [],
                ],
                [
                    'id' => 'survey_past_publish',
                    'title' => '期限経過・公開中から終了確認',
                    'description' => '公開中かつ終了日時経過のサンプルです。',
                    'startDate' => date('c', strtotime('-5 days')),
                    'endDate' => $past,
                    'status' => 'published',
                    'numbering' => 'all',
                    'allowResubmit' => false,
                    'createdAt' => nowIso(),
                    'updatedAt' => nowIso(),
                    'groups' => [],
                ],
                [
                    'id' => 'survey_past_draft',
                    'title' => '期限経過・下書き維持確認',
                    'description' => '下書きなので期限経過後も下書きです。',
                    'startDate' => date('c', strtotime('-5 days')),
                    'endDate' => $past,
                    'status' => 'draft',
                    'numbering' => 'all',
                    'allowResubmit' => false,
                    'createdAt' => nowIso(),
                    'updatedAt' => nowIso(),
                    'groups' => [],
                ],
            ];

            writeJson('surveys', $surveys);
        } elseif ($name === 'customers') {
            writeJson('customers', [
                [
                    'id' => 'customer_001',
                    'organization' => 'サンプル株式会社',
                    'name' => '山田 太郎',
                    'email' => 'example@example.com',
                    'department' => '営業部',
                    'phone' => '03-0000-0000',
                    'address' => '東京都港区',
                    'lastSentAt' => null,
                    'sendCount' => 0,
                    'status' => '未送信',
                    'kintoneStatus' => '登録済み',
                ],
                [
                    'id' => 'customer_002',
                    'organization' => 'テスト商事',
                    'name' => '佐藤 花子',
                    'email' => 'test@example.com',
                    'department' => '総務部',
                    'phone' => '03-1111-1111',
                    'address' => '東京都千代田区',
                    'lastSentAt' => null,
                    'sendCount' => 0,
                    'status' => '未送信',
                    'kintoneStatus' => '登録済み',
                ],
            ]);
        } elseif ($name === 'responses') {
            writeJson('responses', []);
        } elseif ($name === 'history') {
            writeJson('history', []);
        } elseif ($name === 'kintone') {
            writeJson('kintone', [
                'subdomain' => '',
                'appId' => '',
                'loginName' => '',
                'password' => '',
                'sslVerify' => false,
                'proxy' => '',
                'fields' => [],
                'mapping' => [
                    'organization' => '',
                    'name' => '',
                    'email' => '',
                    'department' => '',
                    'phone' => '',
                    'address' => [],
                ],
            ]);
        } elseif ($name === 'mail') {
            writeJson('mail', [
                'server' => '',
                'port' => 587,
                'encryption' => 'tls',
                'auth' => true,
                'username' => '',
                'password' => '',
                'fromEmail' => '',
                'fromName' => '',
                'replyTo' => '',
                'status' => '未設定',
            ]);
        }
    }
}

function finishExpired(array &$surveys): bool
{
    $changed = false;
    $now = time();

    foreach ($surveys as &$s) {
        if (($s['status'] ?? '') !== 'published') {
            continue;
        }

        $end = trim((string)($s['endDate'] ?? ''));
        if ($end === '') {
            continue;
        }

        $time = strtotime($end);
        if ($time !== false && $now > $time) {
            $s['status'] = 'finished';
            $s['updatedAt'] = nowIso();
            $changed = true;
        }
    }
    unset($s);

    if ($changed) {
        writeJson('surveys', $surveys);
    }
    return $changed;
}

function surveyById(array $surveys, string $id): ?array
{
    foreach ($surveys as $s) {
        if (($s['id'] ?? '') === $id) {
            return $s;
        }
    }
    return null;
}

function renumber(array &$survey): void
{
    $n = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        $group['sortOrder'] = $gi + 1;

        foreach ($group['questions'] as $qi => &$question) {
            $question['groupId'] = $group['id'];
            $question['sortOrder'] = $qi + 1;
            $question['questionNumber'] =
                ($survey['numbering'] ?? 'all') === 'group'
                ? 'Q' . ($gi + 1) . '-' . ($qi + 1)
                : 'Q' . $n;
            $n++;

            foreach ($question['choices'] ?? [] as &$choice) {
                if (!isset($choice['id'])) {
                    $choice['id'] = id('choice');
                }
            }
            unset($choice);
        }
        unset($question);
    }
    unset($group);
}

function saveSurvey(array $survey): array
{
    $surveys = readJson('surveys', []);
    if (!is_array($surveys)) {
        $surveys = [];
    }

    renumber($survey);
    $survey['updatedAt'] = nowIso();

    $found = false;
    foreach ($surveys as $i => $s) {
        if (($s['id'] ?? '') === $survey['id']) {
            $surveys[$i] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $survey['createdAt'] = $survey['createdAt'] ?? nowIso();
        $surveys[] = $survey;
    }

    writeJson('surveys', $surveys);
    return $survey;
}

function mailConfig(): array
{
    return array_merge([
        'server' => '',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password' => '',
        'fromEmail' => '',
        'fromName' => '',
        'replyTo' => '',
        'status' => '未設定',
    ], readJson('mail', []));
}

/*
 * PHP SMTP:
 * 外部ライブラリなしで最低限のSMTP通信を行う。
 */
function smtpSend(array $cfg, string $to, string $subject, string $body): array
{
    $server = trim((string)$cfg['server']);
    $port = (int)$cfg['port'];

    if ($server === '' || $port <= 0) {
        throw new RuntimeException('SMTPサーバ設定が未設定です。');
    }
    if (trim((string)$cfg['fromEmail']) === '') {
        throw new RuntimeException('送信元メールアドレスが未設定です。');
    }

    $enc = (string)$cfg['encryption'];
    $host = $enc === 'ssl' ? 'ssl://' . $server : $server;

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        $host . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        throw new RuntimeException("SMTP接続失敗: {$errstr} ({$errno})");
    }

    stream_set_timeout($fp, 15);

    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };

    $expect = static function (string $response, array $codes): void {
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP応答エラー: ' . trim($response));
        }
    };

    $write = static function (string $line) use ($fp): void {
        fwrite($fp, $line . "\r\n");
    };

    try {
        $expect($read(), [220]);
        $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $expect($read(), [250]);

        if ($enc === 'tls') {
            $write('STARTTLS');
            $expect($read(), [220]);

            if (!stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )) {
                throw new RuntimeException('SMTP STARTTLSに失敗しました。');
            }

            $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            $expect($read(), [250]);
        }

        if ((bool)$cfg['auth']) {
            if ((string)$cfg['username'] === '' ||
                (string)$cfg['password'] === '') {
                throw new RuntimeException('SMTP認証情報が未設定です。');
            }

            $write('AUTH LOGIN');
            $expect($read(), [334]);
            $write(base64_encode((string)$cfg['username']));
            $expect($read(), [334]);
            $write(base64_encode((string)$cfg['password']));
            $expect($read(), [235]);
        }

        $from = (string)$cfg['fromEmail'];

        $write('MAIL FROM:<' . $from . '>');
        $expect($read(), [250]);

        $write('RCPT TO:<' . $to . '>');
        $expect($read(), [250, 251]);

        $write('DATA');
        $expect($read(), [354]);

        $fromName = (string)$cfg['fromName'];
        $fromHeader = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>'
            : $from;

        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if ((string)$cfg['replyTo'] !== '') {
            $headers[] = 'Reply-To: ' . $cfg['replyTo'];
        }

        $data = implode("\r\n", $headers) .
            "\r\n\r\n" .
            str_replace("\n", "\r\n", $body);

        $data = preg_replace('/^\./m', '..', $data) ?? $data;

        fwrite($fp, $data . "\r\n.\r\n");
        $expect($read(), [250]);

        $write('QUIT');
        $read();

        return ['success' => true];
    } finally {
        fclose($fp);
    }
}

function answerStatus(string $customerId, string $surveyId): string
{
    $responses = readJson('responses', []);
    foreach ($responses as $r) {
        if (($r['customerId'] ?? '') === $customerId &&
            ($r['surveyId'] ?? '') === $surveyId) {
            return '回答済み';
        }
    }

    $customers = readJson('customers', []);
    foreach ($customers as $c) {
        if (($c['id'] ?? '') === $customerId) {
            return (string)($c['status'] ?? '未送信');
        }
    }
    return '未送信';
}

function publicSurveyUrl(string $surveyId, ?string $customerId = null): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    $url = $scheme . '://' . $host . $base . '/index.php?answer=' .
        rawurlencode($surveyId);

    if ($customerId !== null && $customerId !== '') {
        $url .= '&customer=' . rawurlencode($customerId);
    }
    return $url;
}

function apiRequest(): void
{
    $action = $_GET['api'] ?? '';

    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    if (!is_array($input)) {
        $input = [];
    }

    try {
        $surveys = readJson('surveys', []);
        if (!is_array($surveys)) {
            $surveys = [];
        }

        finishExpired($surveys);
        $surveys = readJson('surveys', $surveys);

        switch ($action) {
            case 'get_data':
                jsonResponse([
                    'success' => true,
                    'surveys' => $surveys,
                    'customers' => readJson('customers', []),
                    'responses' => readJson('responses', []),
                    'history' => readJson('history', []),
                    'kintone' => kintonePublic(),
                    'mail' => mailPublic(),
                ]);

            case 'save_survey':
                $survey = $input['survey'] ?? null;
                if (!is_array($survey)) {
                    fail('アンケートデータが不正です。');
                }
                if (trim((string)($survey['title'] ?? '')) === '') {
                    fail('タイトルを入力してください。');
                }
                if (!isset($survey['id']) || $survey['id'] === '') {
                    $survey['id'] = id('survey');
                    $survey['status'] = 'draft';
                    $survey['createdAt'] = nowIso();
                    $survey['groups'] = $survey['groups'] ?? [];
                }
                $survey['numbering'] =
                    ($survey['numbering'] ?? 'all') === 'group'
                    ? 'group' : 'all';

                $saved = saveSurvey($survey);
                jsonResponse(['success' => true, 'survey' => $saved]);

            case 'change_status':
                $sid = (string)($input['surveyId'] ?? '');
                $status = (string)($input['status'] ?? '');
                $allowed = [
                    'draft' => ['published'],
                    'published' => ['stopped'],
                    'stopped' => ['published'],
                ];

                $surveys = readJson('surveys', []);
                foreach ($surveys as &$s) {
                    if (($s['id'] ?? '') !== $sid) {
                        continue;
                    }

                    $current = $s['status'] ?? 'draft';

                    if ($current === 'finished') {
                        fail('終了状態から変更できません。');
                    }

                    if (!in_array($status, $allowed[$current] ?? [], true)) {
                        fail('許可されていない状態変更です。');
                    }

                    $s['status'] = $status;
                    $s['updatedAt'] = nowIso();
                    writeJson('surveys', $surveys);
                    jsonResponse(['success' => true, 'survey' => $s]);
                }
                unset($s);
                fail('対象アンケートがありません。', 404);

            case 'delete_survey':
                $sid = (string)($input['surveyId'] ?? '');
                $surveys = array_values(array_filter(
                    $surveys,
                    static fn($s) => ($s['id'] ?? '') !== $sid
                ));
                writeJson('surveys', $surveys);

                $responses = array_values(array_filter(
                    readJson('responses', []),
                    static fn($r) => ($r['surveyId'] ?? '') !== $sid
                ));
                writeJson('responses', $responses);

                jsonResponse(['success' => true]);

            case 'duplicate_survey':
                $sid = (string)($input['surveyId'] ?? '');
                $source = surveyById($surveys, $sid);
                if (!$source) {
                    fail('複製対象がありません。', 404);
                }

                $source['id'] = id('survey');
                $source['title'] = (string)$source['title'] . '（複製）';
                $source['status'] = 'draft';
                $source['createdAt'] = nowIso();
                $source['updatedAt'] = nowIso();

                foreach ($source['groups'] as &$g) {
                    $g['id'] = id('grp');
                    foreach ($g['questions'] as &$q) {
                        $old = $q['id'];
                        $q['id'] = id('q');
                        $q['groupId'] = $g['id'];
                        foreach ($q['choices'] ?? [] as &$c) {
                            $c['id'] = id('choice');
                        }
                        foreach ($q['branching'] ?? [] as &$b) {
                            $b['questionId'] = $q['id'];
                        }
                    }
                    unset($q);
                }
                unset($g);

                renumber($source);
                $surveys[] = $source;
                writeJson('surveys', $surveys);

                jsonResponse(['success' => true, 'survey' => $source]);

            case 'save_kintone':
                $s = kintoneSettings();
                $new = $input['settings'] ?? [];
                if (!is_array($new)) {
                    fail('kintone設定が不正です。');
                }

                $s['subdomain'] = normalizeSubdomain(
                    (string)($new['subdomain'] ?? '')
                );
                $s['appId'] = preg_replace(
                    '/\D/',
                    '',
                    (string)($new['appId'] ?? '')
                ) ?? '';
                $s['loginName'] = trim((string)($new['loginName'] ?? ''));
                if (array_key_exists('password', $new) &&
                    (string)$new['password'] !== '') {
                    $s['password'] = (string)$new['password'];
                }
                $s['sslVerify'] = (bool)($new['sslVerify'] ?? false);
                $s['proxy'] = trim((string)($new['proxy'] ?? ''));

                if ($s['proxy'] !== '' &&
                    !preg_match('/^[^:\s]+:\d+$/', $s['proxy'])) {
                    fail('プロキシは host:port 形式で入力してください。');
                }

                if (isset($new['mapping']) && is_array($new['mapping'])) {
                    $s['mapping'] = $new['mapping'];
                }

                writeJson('kintone', $s);
                jsonResponse([
                    'success' => true,
                    'settings' => kintonePublic(),
                ]);

            case 'kintone_test':
                kintoneRequest(
                    '/k/v1/app.json?id=' .
                    rawurlencode((string)kintoneSettings()['appId'])
                );
                jsonResponse([
                    'success' => true,
                    'message' => 'kintone接続成功',
                ]);

            case 'kintone_fields':
                $s = kintoneSettings();
                $result = kintoneRequest(
                    '/k/v1/app/form/fields.json?app=' .
                    rawurlencode((string)$s['appId'])
                );

                $fields = [];
                foreach (($result['properties'] ?? []) as $code => $f) {
                    $fields[] = [
                        'code' => $code,
                        'label' => $f['label'] ?? $code,
                        'type' => $f['type'] ?? '',
                    ];
                }

                $s['fields'] = $fields;
                writeJson('kintone', $s);

                jsonResponse([
                    'success' => true,
                    'fields' => $fields,
                ]);

            case 'kintone_sync':
                $s = kintoneSettings();
                $query = 'order by $id asc limit 500';

                $result = kintoneRequest(
                    '/k/v1/records.json?app=' .
                    rawurlencode((string)$s['appId']) .
                    '&query=' . rawurlencode($query)
                );

                $customers = [];
                foreach (($result['records'] ?? []) as $record) {
                    $get = static function (string $code) use ($record): string {
                        return (string)($record[$code]['value'] ?? '');
                    };

                    $m = $s['mapping'] ?? [];
                    $addressCodes = $m['address'] ?? [];
                    $address = [];

                    foreach ($addressCodes as $code) {
                        $v = $get((string)$code);
                        if ($v !== '') {
                            $address[] = $v;
                        }
                    }

                    $email = $get((string)($m['email'] ?? ''));
                    if ($email === '') {
                        continue;
                    }

                    $customers[] = [
                        'id' => 'kintone_' . $get('$id'),
                        'organization' => $get((string)($m['organization'] ?? '')),
                        'name' => $get((string)($m['name'] ?? '')),
                        'email' => $email,
                        'department' => $get((string)($m['department'] ?? '')),
                        'phone' => $get((string)($m['phone'] ?? '')),
                        'address' => implode(' ', $address),
                        'lastSentAt' => null,
                        'sendCount' => 0,
                        'status' => '未送信',
                        'kintoneStatus' => '登録済み',
                    ];
                }

                writeJson('customers', $customers);

                jsonResponse([
                    'success' => true,
                    'message' => '顧客同期完了',
                    'count' => count($customers),
                    'customers' => $customers,
                ]);

            case 'save_mail':
                $m = mailConfig();
                $new = $input['settings'] ?? [];

                foreach ([
                    'server', 'encryption', 'username',
                    'fromEmail', 'fromName', 'replyTo'
                ] as $key) {
                    if (array_key_exists($key, $new)) {
                        $m[$key] = trim((string)$new[$key]);
                    }
                }

                if (array_key_exists('port', $new)) {
                    $m['port'] = max(1, min(65535, (int)$new['port']));
                }
                if (array_key_exists('auth', $new)) {
                    $m['auth'] = (bool)$new['auth'];
                }
                if (array_key_exists('password', $new) &&
                    (string)$new['password'] !== '') {
                    $m['password'] = (string)$new['password'];
                }

                writeJson('mail', $m);
                jsonResponse([
                    'success' => true,
                    'mail' => mailPublic(),
                ]);

            case 'mail_test':
                $m = mailConfig();
                $to = trim((string)($input['to'] ?? $m['replyTo']));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    fail('テスト送信先メールアドレスが不正です。');
                }

                smtpSend(
                    $m,
                    $to,
                    'アンケート管理システム テストメール',
                    "SMTP通信のテストメールです。\n送信日時: " . nowIso()
                );

                $m['status'] = '接続確認済み';
                writeJson('mail', $m);

                jsonResponse([
                    'success' => true,
                    'message' => 'テストメール送信成功',
                ]);

            case 'save_response':
                $surveyId = (string)($input['surveyId'] ?? '');
                $customerId = (string)($input['customerId'] ?? '');
                $answers = $input['answers'] ?? [];

                $survey = surveyById($surveys, $surveyId);
                if (!$survey) {
                    fail('アンケートがありません。', 404);
                }

                if (($survey['status'] ?? '') !== 'published') {
                    fail('このアンケートは回答できません。');
                }

                $responses = readJson('responses', []);
                if (!is_array($responses)) {
                    $responses = [];
                }

                if (!$survey['allowResubmit'] && $customerId !== '') {
                    foreach ($responses as $r) {
                        if (($r['surveyId'] ?? '') === $surveyId &&
                            ($r['customerId'] ?? '') === $customerId) {
                            fail('このアンケートは回答済みです。');
                        }
                    }
                }

                $response = [
                    'id' => id('response'),
                    'surveyId' => $surveyId,
                    'customerId' => $customerId,
                    'answers' => is_array($answers) ? $answers : [],
                    'respondent' => is_array($input['respondent'] ?? null)
                        ? $input['respondent'] : [],
                    'createdAt' => nowIso(),
                ];

                $responses[] = $response;
                writeJson('responses', $responses);

                if ($customerId !== '') {
                    $customers = readJson('customers', []);
                    foreach ($customers as &$c) {
                        if (($c['id'] ?? '') === $customerId) {
                            $c['status'] = '回答済み';
                            break;
                        }
                    }
                    unset($c);
                    writeJson('customers', $customers);
                }

                jsonResponse([
                    'success' => true,
                    'response' => $response,
                ]);

            case 'send_mail':
                $surveyId = (string)($input['surveyId'] ?? '');
                $ids = $input['customerIds'] ?? [];
                $subject = (string)($input['subject'] ?? '');
                $body = (string)($input['body'] ?? '');
                $type = (string)($input['type'] ?? '一括送信');

                if ($surveyId === '') {
                    fail('送信対象アンケートが指定されていません。');
                }

                $survey = surveyById($surveys, $surveyId);
                if (!$survey) {
                    fail('対象アンケートがありません。', 404);
                }

                if (!is_array($ids) || count($ids) === 0) {
                    fail('顧客を選択してください。');
                }

                if ($subject === '') {
                    fail('メール件名を入力してください。');
                }

                $customers = readJson('customers', []);
                $results = [];
                $m = mailConfig();

                foreach ($customers as &$customer) {
                    if (!in_array($customer['id'] ?? '', $ids, true)) {
                        continue;
                    }

                    $name = (string)($customer['name'] ?? '');
                    $email = (string)($customer['email'] ?? '');
                    $url = publicSurveyUrl(
                        $surveyId,
                        (string)($customer['id'] ?? '')
                    );

                    $s = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [$name, $url],
                        $subject
                    );

                    $b = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [$name, $url],
                        $body
                    );

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $results[] = [
                            'customerId' => $customer['id'],
                            'customerName' => $name,
                            'success' => false,
                            'message' => 'メールアドレスが不正です。',
                        ];
                        continue;
                    }

                    try {
                        smtpSend($m, $email, $s, $b);

                        $customer['lastSentAt'] = nowIso();
                        $customer['sendCount'] =
                            (int)($customer['sendCount'] ?? 0) + 1;
                        $customer['status'] = '送信済み / 未回答';

                        $results[] = [
                            'customerId' => $customer['id'],
                            'customerName' => $name,
                            'success' => true,
                            'message' => '送信成功',
                            'subject' => $s,
                            'body' => $b,
                            'url' => $url,
                        ];
                    } catch (Throwable $e) {
                        $results[] = [
                            'customerId' => $customer['id'],
                            'customerName' => $name,
                            'success' => false,
                            'message' => $e->getMessage(),
                        ];
                    }
                }
                unset($customer);

                writeJson('customers', $customers);

                $successCount = count(array_filter(
                    $results,
                    static fn($r) => !empty($r['success'])
                ));

                $history = readJson('history', []);
                $history[] = [
                    'id' => id('history'),
                    'surveyId' => $surveyId,
                    'sentAt' => nowIso(),
                    'type' => $type,
                    'count' => count($results),
                    'successCount' => $successCount,
                    'subject' => $subject,
                    'body' => $body,
                    'results' => $results,
                    'executor' => 'システム利用者',
                ];
                writeJson('history', $history);

                jsonResponse([
                    'success' => true,
                    'results' => $results,
                    'summary' => [
                        'total' => count($results),
                        'success' => $successCount,
                        'failed' => count($results) - $successCount,
                        'sentAt' => nowIso(),
                    ],
                ]);

            default:
                fail('未知のAPIです。', 404);
        }
    } catch (Throwable $e) {
        fail($e->getMessage(), 500, [
            'exception' => get_class($e),
        ]);
    }
}

function kintonePublic(): array
{
    $s = kintoneSettings();

    return [
        'subdomain' => $s['subdomain'],
        'appId' => $s['appId'],
        'loginName' => $s['loginName'],
        'passwordSet' => $s['password'] !== '',
        'sslVerify' => (bool)$s['sslVerify'],
        'proxy' => $s['proxy'],
        'fields' => $s['fields'],
        'mapping' => $s['mapping'],
    ];
}

function mailPublic(): array
{
    $m = mailConfig();

    return [
        'server' => $m['server'],
        'port' => $m['port'],
        'encryption' => $m['encryption'],
        'auth' => (bool)$m['auth'],
        'username' => $m['username'],
        'passwordSet' => $m['password'] !== '',
        'fromEmail' => $m['fromEmail'],
        'fromName' => $m['fromName'],
        'replyTo' => $m['replyTo'],
        'status' => $m['status'],
    ];
}

ensureData();

if (isset($_GET['api'])) {
    apiRequest();
}

?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>
<style>
:root{--p:#2563eb;--bg:#f5f7fb;--card:#fff;--text:#172033;--muted:#667085;--line:#dfe3eb;--danger:#dc2626;--ok:#15803d;--warn:#b45309}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
.admin-head{height:64px;background:#172033;color:#fff;display:flex;align-items:center;padding:0 22px;gap:18px}
.admin-head strong{font-size:18px}
.admin-head nav{display:flex;gap:6px}
.admin-head button{background:transparent;color:#fff;border:0;padding:10px 12px;border-radius:7px}
.admin-head button:hover{background:#26324a}
main{max-width:1500px;margin:auto;padding:24px}
.screen{display:none}
.screen.active{display:block}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 2px 8px #17203308}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
.toolbar input{min-width:260px}
input,select,textarea{border:1px solid #cbd2df;border-radius:7px;padding:9px 11px;background:#fff;color:var(--text)}
textarea{width:100%;min-height:100px;resize:vertical}
button.primary{background:var(--p);border:1px solid var(--p);color:#fff;padding:9px 14px;border-radius:7px}
button.secondary{background:#fff;border:1px solid #cbd2df;color:var(--text);padding:9px 14px;border-radius:7px}
button.danger{background:#fff;border:1px solid #f1b4b4;color:var(--danger);padding:9px 14px;border-radius:7px}
button:disabled{opacity:.45;cursor:not-allowed}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid var(--line);padding:12px 10px;text-align:left;vertical-align:top}
th{background:#f8fafc;white-space:nowrap}
.table-wrap{overflow:auto}
.badge{display:inline-block;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:700}
.badge.draft{background:#eef2f7;color:#475467}
.badge.published{background:#dcfce7;color:#166534}
.badge.stopped{background:#fef3c7;color:#92400e}
.badge.finished{background:#e5e7eb;color:#374151}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.field label{font-weight:700;font-size:13px}
.actions{display:flex;gap:7px;flex-wrap:wrap}
.question,.group{border:1px solid var(--line);border-radius:10px;padding:16px;background:#fff;margin-bottom:12px}
.group{background:#f8fafc}
.group-head,.question-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.drag{cursor:grab;color:#98a2b3}
.choice{display:flex;gap:8px;margin:7px 0}
.choice input{flex:1}
.status-line{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.kpi{background:#fff;border:1px solid var(--line);border-radius:10px;padding:18px}
.kpi small{display:block;color:var(--muted)}
.kpi strong{display:block;font-size:28px;margin-top:6px}
.bar{height:16px;background:#e8edf5;border-radius:20px;overflow:hidden}
.bar i{display:block;height:100%;background:var(--p)}
.modal-bg{display:none;position:fixed;inset:0;background:#0008;z-index:1000;align-items:center;justify-content:center;padding:20px}
.modal-bg.show{display:flex}
.modal{width:min(560px,100%);background:#fff;border-radius:12px;padding:22px;box-shadow:0 20px 70px #0005}
.modal .actions{justify-content:flex-end;margin-top:18px}
.notice{padding:12px 14px;border-radius:8px;background:#eff6ff;color:#1d4ed8;margin-bottom:15px}
.notice.error{background:#fef2f2;color:#b91c1c}
.notice.ok{background:#f0fdf4;color:#166534}
.preview-phone{max-width:390px;margin:auto;border:10px solid #222;border-radius:28px;padding:15px;background:#fff}
.answer-wrap{max-width:800px;margin:30px auto;padding:0 16px}
.answer-header{background:#fff;padding:25px;border-radius:14px;margin-bottom:16px}
.answer-question{background:#fff;padding:20px;border-radius:12px;margin-bottom:14px}
.answer-choice{display:block;border:1px solid var(--line);padding:13px;border-radius:8px;margin:8px 0}
.answer-choice input{margin-right:8px}
.error-list{color:#b91c1c;margin:10px 0}
.history-detail{display:none;background:#f8fafc;padding:12px;margin-top:8px;border-radius:8px}
@media(max-width:900px){.grid2,.grid3{grid-template-columns:1fr}.admin-head{height:auto;padding:12px;align-items:flex-start;flex-direction:column}.admin-head nav{width:100%;overflow:auto}.admin-head button{white-space:nowrap}main{padding:14px}}
@media(max-width:600px){.toolbar input{min-width:0;width:100%}th,td{font-size:13px}.actions button{min-height:42px}.card{padding:14px}}
</style>
</head>
<body>

<div id="adminApp">
<header class="admin-head">
<strong>アンケート管理システム</strong>
<nav>
<button onclick="showScreen('list')">アンケート一覧</button>
<button onclick="showScreen('kintone')">kintone連携設定</button>
<button onclick="showScreen('mail')">メールサーバ設定</button>
<button onclick="adminReset()">ログアウト</button>
</nav>
</header>

<main>

<section id="screen-list" class="screen active">
<div class="card">
<div class="toolbar">
<input id="surveySearch" placeholder="タイトルを検索" oninput="renderSurveyList()" onkeydown="if(event.key==='Enter')renderSurveyList()">
<select id="surveyFilter" onchange="renderSurveyList()">
<option value="">すべて</option>
<option value="published">公開中</option>
<option value="draft">下書き</option>
<option value="stopped">停止</option>
<option value="finished">終了</option>
</select>
<select id="surveySort" onchange="renderSurveyList()">
<option value="updated_desc">更新日 新しい順</option>
<option value="updated_asc">更新日 古い順</option>
<option value="answers_desc">回答数 多い順</option>
<option value="answers_asc">回答数 少ない順</option>
<option value="start_desc">開始日 新しい順</option>
<option value="start_asc">開始日 古い順</option>
</select>
<button class="primary" onclick="newSurvey()">＋ アンケート作成</button>
</div>
<div class="table-wrap">
<table><thead><tr><th>作成日 / 更新日</th><th>タイトル</th><th>期間</th><th>ステータス</th><th>回答数</th><th>操作</th></tr></thead>
<tbody id="surveyRows"></tbody></table>
</div>
</div>
</section>

<section id="screen-editor" class="screen">
<div class="card">
<div class="status-line">
<button class="secondary" onclick="cancelEditor()">キャンセル</button>
<button class="primary" onclick="saveEditor()">保存して一覧へ</button>
<span>状態：</span>
<select id="editStatus" onchange="statusChanged()"></select>
</div>
</div>
<div class="card">
<div class="grid2">
<div class="field"><label>タイトル</label><input id="editTitle"></div>
<div class="field"><label>質問番号の採番方式</label>
<select id="editNumbering" onchange="renumberEditor()"><option value="all">アンケート全体で通番</option><option value="group">グループ毎に採番</option></select>
</div>
<div class="field"><label>開始日時</label><input id="editStart" type="datetime-local"></div>
<div class="field"><label>終了日時</label><input id="editEnd" type="datetime-local"></div>
</div>
<div class="field"><label>説明</label><textarea id="editDescription"></textarea></div>
<label><input type="checkbox" id="editResubmit"> 回答済み回答者の再回答を許可する</label>
</div>
<div class="card">
<div id="groups"></div>
<button class="secondary" onclick="addGroup()">＋ グループを追加</button>
</div>
<div class="card">
<button class="primary" onclick="showPreview()">プレビュー</button>
</div>
</section>

<section id="screen-preview" class="screen">
<div class="card">
<div class="toolbar"><button class="secondary" onclick="showScreen('editor')">編集へ戻る</button><button class="secondary" onclick="previewMode('pc')">PC</button><button class="secondary" onclick="previewMode('sp')">スマートフォン</button></div>
<div id="previewBody"></div>
</div>
</section>

<section id="screen-send" class="screen">
<div class="card">
<div class="status-line"><button class="secondary" onclick="showScreen('list')">一覧へ</button><h2 id="sendTitle"></h2></div>
</div>
<div class="card">
<div class="toolbar">
<input id="customerSearch" placeholder="顧客名・組織名・メール・ステータス" oninput="renderCustomers()" onkeydown="if(event.key==='Enter')renderCustomers()">
<button class="secondary" onclick="selectReminder()">未回答を選択</button>
<button class="secondary" onclick="clearCustomerSelection()">すべて解除</button>
</div>
<div class="table-wrap"><table><thead><tr><th></th><th>組織名</th><th>氏名</th><th>メール</th><th>電話</th><th>住所</th><th>最終送信</th><th>回数</th><th>回答ステータス</th><th>kintone</th></tr></thead><tbody id="customerRows"></tbody></table></div>
</div>
<div class="card">
<h3>メール作成</h3>
<div class="field"><label>件名</label><input id="mailSubject" value="アンケートのお願い"></div>
<div class="field"><label>本文</label><textarea id="mailBody">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea></div>
<p>利用可能な変数：<code>{顧客名}</code> <code>{アンケートURL}</code></p>
<div class="actions">
<button class="primary" onclick="sendConfirm('一括送信')">一括送信</button>
<button class="secondary" onclick="sendConfirm('再送')">再送</button>
<button class="secondary" onclick="selectReminder();sendConfirm('リマインド')">リマインド</button>
</div>
<div id="sendResult"></div>
</div>
<div class="card">
<h3>送信履歴</h3>
<div id="history"></div>
</div>
</section>

<section id="screen-aggregate" class="screen">
<div class="card">
<button class="secondary" onclick="showScreen('list')">一覧へ</button>
<h2 id="aggregateTitle"></h2>
<div class="grid3" id="summary"></div>
</div>
<div class="card">
<div class="toolbar">
<button class="secondary" onclick="selectAllQuestions(true)">すべて選択</button>
<button class="secondary" onclick="selectAllQuestions(false)">すべて解除</button>
</div>
<div id="questionStats"></div>
</div>
<div class="card">
<h3>個別回答</h3>
<div class="table-wrap"><table><thead><tr><th>日時</th><th>回答者</th><th>回答</th></tr></thead><tbody id="responseRows"></tbody></table></div>
<div class="actions">
<button class="primary" onclick="exportCSV()">CSV出力</button>
<button class="secondary" onclick="exportPDF()">PDF出力</button>
</div>
<div id="exportNotice"></div>
</div>
</section>

<section id="screen-kintone" class="screen">
<div class="card">
<h2>kintone連携設定</h2>
<div class="grid2">
<div class="field"><label>サブドメイン</label><input id="kinSubdomain" placeholder="https:xxxx.cybozu.com / xxxx.cybozu.com / xxxx"></div>
<div class="field"><label>顧客管理アプリID</label><input id="kinAppId"></div>
<div class="field"><label>ログイン名</label><input id="kinLogin"></div>
<div class="field"><label>パスワード</label><input id="kinPassword" type="password" placeholder="変更時のみ入力"></div>
<div class="field"><label>SSL証明書検証</label><select id="kinSSL"><option value="false">検証しない</option><option value="true">検証する</option></select></div>
<div class="field"><label>プロキシ（host:port）</label><input id="kinProxy" placeholder="proxy.example.local:8080"></div>
</div>
<div class="actions">
<button class="primary" onclick="saveKintone()">設定を保存</button>
<button class="secondary" onclick="kinTest()">接続テスト</button>
<button class="secondary" onclick="kinFields()">項目一覧を再取得</button>
<button class="secondary" onclick="kinSync()">顧客情報を同期</button>
</div>
<div id="kinNotice"></div>
</div>
<div class="card">
<h3>フィールドマッピング</h3>
<div id="mapping"></div>
</div>
<div class="card">
<h3>取得済みフィールド</h3>
<div class="table-wrap"><table><thead><tr><th>コード</th><th>日本語ラベル</th><th>形式</th></tr></thead><tbody id="kinFields"></tbody></table></div>
</div>
</section>

<section id="screen-mail" class="screen">
<div class="card">
<h2>メールサーバ設定</h2>
<div class="grid2">
<div class="field"><label>SMTPサーバ</label><input id="smtpServer"></div>
<div class="field"><label>SMTPポート</label><input id="smtpPort" type="number"></div>
<div class="field"><label>暗号化方式</label><select id="smtpEncryption"><option value="none">なし</option><option value="tls">STARTTLS</option><option value="ssl">SSL</option></select></div>
<div class="field"><label>SMTP認証</label><select id="smtpAuth"><option value="true">認証する</option><option value="false">認証しない</option></select></div>
<div class="field"><label>SMTPユーザー名</label><input id="smtpUser"></div>
<div class="field"><label>SMTPパスワード</label><input id="smtpPass" type="password" placeholder="変更時のみ入力"></div>
<div class="field"><label>送信元メールアドレス</label><input id="smtpFrom"></div>
<div class="field"><label>送信元名</label><input id="smtpFromName"></div>
<div class="field"><label>返信先メールアドレス</label><input id="smtpReply"></div>
</div>
<div class="actions">
<button class="primary" onclick="saveMail()">設定を保存</button>
<button class="secondary" onclick="mailTest()">テストメール</button>
</div>
<div id="mailNotice"></div>
</div>
</section>

</main>
</div>

<!-- 回答者UI: 管理者ヘッダーを共有しない -->
<div id="respondentApp" style="display:none">
<div class="answer-wrap">
<div id="answerContent"></div>
</div>
</div>

<div id="modalBg" class="modal-bg">
<div class="modal">
<h3 id="modalTitle"></h3>
<div id="modalMessage"></div>
<div class="actions">
<button class="secondary" id="modalCancel">キャンセル</button>
<button class="primary" id="modalOK">実行</button>
</div>
</div>
</div>

<script>
"use strict";

/*
 * ============================================================
 * GUARD: クライアントAPI
 * ============================================================
 * "?api=..." の相対パスを直接組み立てず、現在のindex.phpを
 * URLとして明示する。
 *
 * また、
 * 1. fetch通信失敗
 * 2. HTTPエラー
 * 3. JSON以外
 * 4. API業務エラー
 *
 * を分離する。
 * ============================================================
 */

const state={
  surveys:[],customers:[],responses:[],history:[],
  kintone:null,mail:null,
  screen:"list",edit:null,editOriginal:null,
  aggregateId:null,sendId:null,
  selectedCustomers:new Set,
  questionSelection:new Set,
  answerSurvey:null,answerCustomer:null,answers:{},
  answerScreen:"answer",respondent:{}
};

function apiURL(action){
  const u=new URL(window.location.href);
  u.search="";
  u.hash="";
  u.searchParams.set("api",action);
  return u.toString();
}

async function api(action,payload={}){
  let response;
  try{
    response=await fetch(apiURL(action),{
      method:"POST",
      credentials:"same-origin",
      cache:"no-store",
      headers:{
        "Accept":"application/json",
        "Content-Type":"application/json"
      },
      body:JSON.stringify(payload)
    });
  }catch(e){
    throw new Error(
      "サーバーとの通信に失敗しました。"+
      " index.phpがPHPとして実行されているか、"+
      "Apache/PHPの状態、URL、ネットワークを確認してください。"+
      " ("+(e&&e.message?e.message:"Failed to fetch")+")"
    );
  }

  const text=await response.text();
  const ct=(response.headers.get("content-type")||"").toLowerCase();

  if(!ct.includes("application/json")){
    throw new Error(
      "サーバーがJSONを返しませんでした。HTTP "+
      response.status+" / "+text.slice(0,500)
    );
  }

  let data;
  try{data=JSON.parse(text)}
  catch(e){throw new Error("APIレスポンスのJSON解析に失敗しました。HTTP "+response.status)}

  if(!response.ok||data.success===false){
    throw new Error(data.error||data.message||"API処理に失敗しました。");
  }
  return data;
}

function esc(v){
  return String(v??"").replace(/[&<>"']/g,m=>({
    "&":"&amp;","<":"&lt;",">":"&gt;",
    '"':"&quot;","'":"&#39;"
  }[m]));
}

function fmtDate(v){
  if(!v)return "-";
  const d=new Date(v);
  return Number.isNaN(d.getTime())?esc(v):d.toLocaleString("ja-JP");
}

function localValue(v){
  if(!v)return "";
  const d=new Date(v);
  if(Number.isNaN(d.getTime()))return "";
  const z=n=>String(n).padStart(2,"0");
  return d.getFullYear()+"-"+z(d.getMonth()+1)+"-"+z(d.getDate())+
    "T"+z(d.getHours())+":"+z(d.getMinutes());
}

function notice(id,msg,type=""){
  const e=document.getElementById(id);
  if(e)e.innerHTML=msg?`<div class="notice ${type}">${esc(msg)}</div>`:"";
}

async function boot(){
  try{
    const d=await api("get_data");
    state.surveys=d.surveys||[];
    state.customers=d.customers||[];
    state.responses=d.responses||[];
    state.history=d.history||[];
    state.kintone=d.kintone||{};
    state.mail=d.mail||{};
    renderSurveyList();
    loadSettings();
  }catch(e){
    document.body.innerHTML=`
      <div style="max-width:760px;margin:60px auto;padding:24px">
        <div class="card">
          <h2>システムを起動できませんでした。</h2>
          <div class="notice error">${esc(e.message)}</div>
          <p>PHPが実行可能なApache上でindex.phpを開いているか確認してください。</p>
          <button class="primary" onclick="location.reload()">再読み込み</button>
        </div>
      </div>`;
  }
}

function showScreen(name){
  if((name==="aggregate"&&!state.aggregateId)||
     (name==="send"&&!state.sendId))return;
  document.querySelectorAll(".screen").forEach(e=>e.classList.remove("active"));
  const e=document.getElementById("screen-"+name);
  if(e)e.classList.add("active");
  state.screen=name;
  if(name==="list")renderSurveyList();
  if(name==="send"){renderSend();renderHistory()}
  if(name==="aggregate")renderAggregate();
  if(name==="kintone")renderKintone();
  if(name==="mail")renderMail();
}

function statusLabel(s){
  return {draft:"下書き",published:"公開中",stopped:"停止",finished:"終了"}[s]||s;
}

function statusBadge(s){
  return `<span class="badge ${esc(s)}">${esc(statusLabel(s))}</span>`;
}

function answerCount(id){
  return state.responses.filter(r=>r.surveyId===id).length;
}

function renderSurveyList(){
  const q=(document.getElementById("surveySearch")?.value||"").toLowerCase();
  const filter=document.getElementById("surveyFilter")?.value||"";
  const sort=document.getElementById("surveySort")?.value||"updated_desc";
  let a=state.surveys.filter(s=>{
    return (!q||String(s.title||"").toLowerCase().includes(q))&&
      (!filter||s.status===filter);
  });

  a.sort((x,y)=>{
    if(sort.includes("answers")){
      const d=answerCount(x.id)-answerCount(y.id);
      return sort.endsWith("desc")?-d:d;
    }
    const key=sort.includes("start")?"startDate":"updatedAt";
    const d=new Date(x[key]||0)-new Date(y[key]||0);
    return sort.endsWith("desc")?-d:d;
  });

  document.getElementById("surveyRows").innerHTML=a.map(s=>`
    <tr>
      <td>${fmtDate(s.createdAt)}<br>${fmtDate(s.updatedAt)}</td>
      <td><strong>${esc(s.title)}</strong></td>
      <td>${fmtDate(s.startDate)}<br>～ ${fmtDate(s.endDate)}</td>
      <td>${statusBadge(s.status)}</td>
      <td>${answerCount(s.id)}</td>
      <td>
        <div class="actions">
          <button class="secondary" onclick="editSurvey('${esc(s.id)}')">確認・編集</button>
          <button class="secondary" onclick="openAggregate('${esc(s.id)}')">集計</button>
          <button class="secondary" onclick="openSend('${esc(s.id)}')">送信</button>
          <button class="secondary" onclick="duplicateSurvey('${esc(s.id)}')">複製</button>
          <button class="danger" onclick="deleteSurvey('${esc(s.id)}')">削除</button>
        </div>
      </td>
    </tr>`).join("")||`<tr><td colspan="6">該当するアンケートがありません。</td></tr>`;
}

function newSurvey(){
  state.edit={
    id:"",
    title:"",
    description:"",
    startDate:new Date().toISOString(),
    endDate:"",
    status:"draft",
    numbering:"all",
    allowResubmit:false,
    groups:[]
  };
  state.editOriginal=null;
  renderEditor();
  showScreen("editor");
}

function clone(x){return JSON.parse(JSON.stringify(x))}

function editSurvey(id){
  const s=state.surveys.find(x=>x.id===id);
  if(!s)return;
  state.edit=clone(s);
  state.editOriginal=clone(s);
  renderEditor();
  showScreen("editor");
}

function renderEditor(){
  const s=state.edit;
  document.getElementById("editTitle").value=s.title||"";
  document.getElementById("editDescription").value=s.description||"";
  document.getElementById("editStart").value=localValue(s.startDate);
  document.getElementById("editEnd").value=localValue(s.endDate);
  document.getElementById("editNumbering").value=s.numbering||"all";
  document.getElementById("editResubmit").checked=!!s.allowResubmit;

  const sel=document.getElementById("editStatus");
  sel.innerHTML=["draft","published","stopped","finished"].map(x=>
    `<option value="${x}">${statusLabel(x)}</option>`).join("");
  sel.value=s.status||"draft";
  sel.disabled=s.status==="finished";

  renderGroups();
}

function syncEditor(){
  const s=state.edit;
  s.title=document.getElementById("editTitle").value;
  s.description=document.getElementById("editDescription").value;
  s.startDate=document.getElementById("editStart").value
    ? new Date(document.getElementById("editStart").value).toISOString():"";
  s.endDate=document.getElementById("editEnd").value
    ? new Date(document.getElementById("editEnd").value).toISOString():"";
  s.numbering=document.getElementById("editNumbering").value;
  s.allowResubmit=document.getElementById("editResubmit").checked;
  renumberEditor();
}

function renumberEditor(){
  if(!state.edit)return;
  state.edit.numbering=document.getElementById("editNumbering").value;
  let n=1;
  state.edit.groups.forEach((g,gi)=>{
    g.sortOrder=gi+1;
    g.questions.forEach((q,qi)=>{
      q.groupId=g.id;
      q.sortOrder=qi+1;
      q.questionNumber=state.edit.numbering==="group"
        ?`Q${gi+1}-${qi+1}`:`Q${n++}`;
    });
  });
  renderGroups();
}

function renderGroups(){
  const root=document.getElementById("groups");
  root.innerHTML=state.edit.groups.map((g,gi)=>`
    <div class="group" draggable="true"
      ondragstart="dragGroup(${gi})"
      ondragover="event.preventDefault()"
      ondrop="dropGroup(${gi})">
      <div class="group-head">
        <strong class="drag">☷ グループ ${gi+1}</strong>
        <div class="actions">
          <button class="danger" onclick="deleteGroup(${gi})">削除</button>
        </div>
      </div>
      <input style="width:100%;margin-bottom:12px" value="${esc(g.title||"")}"
        onchange="state.edit.groups[${gi}].title=this.value">
      <div>
      ${g.questions.map((q,qi)=>questionHtml(g,gi,q,qi)).join("")}
      </div>
      <button class="secondary" onclick="addQuestion(${gi})">＋ 質問を追加</button>
    </div>`).join("");
}

function questionHtml(g,gi,q,qi){
  const choices=q.choices||[];
  return `<div class="question" draggable="true"
    ondragstart="dragQuestion(${gi},${qi})"
    ondragover="event.preventDefault()"
    ondrop="dropQuestion(${gi},${qi})">
    <div class="question-head">
      <strong class="drag">☷ ${esc(q.questionNumber||"")}</strong>
      <button class="danger" onclick="deleteQuestion(${gi},${qi})">削除</button>
    </div>
    <div class="field"><label>質問文</label><textarea
      onchange="state.edit.groups[${gi}].questions[${qi}].text=this.value">${esc(q.text||"")}</textarea></div>
    <div class="grid2">
      <div class="field"><label>回答形式</label>
        <select onchange="changeQuestionType(${gi},${qi},this.value)">
          <option value="single" ${q.type==="single"?"selected":""}>単一選択</option>
          <option value="multi" ${q.type==="multi"?"selected":""}>複数選択</option>
          <option value="text" ${q.type==="text"?"selected":""}>自由記述</option>
        </select>
      </div>
      <div class="field"><label>必須</label>
        <select onchange="state.edit.groups[${gi}].questions[${qi}].required=this.value==='true'">
          <option value="true" ${q.required?"selected":""}>必須</option>
          <option value="false" ${!q.required?"selected":""}>任意</option>
        </select>
      </div>
    </div>
    ${(q.type==="single"||q.type==="multi")?`
    <div>
      <strong>選択肢</strong>
      ${choices.map((c,ci)=>`
        <div class="choice">
          <input value="${esc(c.label||"")}" onchange="state.edit.groups[${gi}].questions[${qi}].choices[${ci}].label=this.value">
          <button class="danger" onclick="removeChoice(${gi},${qi},${ci})">削除</button>
        </div>`).join("")}
      <button class="secondary" onclick="addChoice(${gi},${qi})">＋ 選択肢</button>
    </div>`:""}
    ${q.type==="single"?`
    <div style="margin-top:14px">
      <strong>条件分岐</strong>
      ${(q.branching||[]).map((b,bi)=>`
        <div class="choice">
          <select onchange="state.edit.groups[${gi}].questions[${qi}].branching[${bi}].choiceId=this.value">
            ${(q.choices||[]).map(c=>`<option value="${esc(c.id)}" ${c.id===b.choiceId?"selected":""}>${esc(c.label)}</option>`).join("")}
          </select>
          <select onchange="state.edit.groups[${gi}].questions[${qi}].branching[${bi}].nextQuestionId=this.value">
            <option value="">次の質問なし</option>
            ${allQuestions().filter(x=>x.id!==q.id).map(x=>`<option value="${esc(x.id)}" ${x.id===b.nextQuestionId?"selected":""}>${esc(x.questionNumber)} ${esc(x.text)}</option>`).join("")}
          </select>
          <button class="danger" onclick="state.edit.groups[${gi}].questions[${qi}].branching.splice(${bi},1);renderGroups()">削除</button>
        </div>`).join("")}
      <button class="secondary" onclick="addBranch(${gi},${qi})">＋ 条件分岐</button>
    </div>`:""}
  </div>`;
}

function allQuestions(){
  return state.edit.groups.flatMap(g=>g.questions);
}

function addGroup(){
  syncEditor();
  state.edit.groups.push({
    id:idClient("grp"),title:"新しいグループ",
    sortOrder:state.edit.groups.length+1,questions:[]
  });
  renumberEditor();
}

function idClient(p){return p+"_"+Date.now().toString(36)+"_"+Math.random().toString(36).slice(2,8)}

function deleteGroup(i){
  const has=state.edit.groups[i].questions.length>0;
  confirmModal("グループ削除",
    has?"質問が存在します。このグループを削除しますか？":"このグループを削除しますか？",
    ()=>{state.edit.groups.splice(i,1);renumberEditor()});
}

function addQuestion(gi){
  syncEditor();
  const g=state.edit.groups[gi];
  g.questions.push({
    id:idClient("q"),groupId:g.id,sortOrder:g.questions.length+1,
    questionNumber:"",
    text:"新しい質問",type:"single",required:false,
    choices:[
      {id:idClient("choice"),label:"選択肢1"},
      {id:idClient("choice"),label:"選択肢2"}
    ],
    branching:[]
  });
  renumberEditor();
}

function deleteQuestion(gi,qi){
  confirmModal("質問削除","この質問を削除しますか？",()=>{
    state.edit.groups[gi].questions.splice(qi,1);
    state.edit.groups.forEach(g=>g.questions.forEach(q=>{
      q.branching=(q.branching||[]).filter(b=>b.nextQuestionId!==state.edit.groups[gi]?.questions[qi]?.id);
    }));
    renumberEditor();
  });
}

function changeQuestionType(gi,qi,type){
  const q=state.edit.groups[gi].questions[qi];
  q.type=type;
  if(type==="text")q.choices=[];
  else if(!q.choices?.length)q.choices=[
    {id:idClient("choice"),label:"選択肢1"},
    {id:idClient("choice"),label:"選択肢2"}
  ];
  if(type!=="single")q.branching=[];
  renderGroups();
}

function addChoice(gi,qi){
  state.edit.groups[gi].questions[qi].choices.push({
    id:idClient("choice"),label:"新しい選択肢"
  });
  renderGroups();
}

function removeChoice(gi,qi,ci){
  state.edit.groups[gi].questions[qi].choices.splice(ci,1);
  renderGroups();
}

function addBranch(gi,qi){
  const q=state.edit.groups[gi].questions[qi];
  q.branching=q.branching||[];
  q.branching.push({
    questionId:q.id,
    choiceId:q.choices[0]?.id||"",
    nextQuestionId:""
  });
  renderGroups();
}

let dragG=null,dragQ=null;
function dragGroup(i){dragG=i}
function dropGroup(i){
  if(dragG===null||dragG===i)return;
  const x=state.edit.groups.splice(dragG,1)[0];
  state.edit.groups.splice(i,0,x);
  dragG=null;renumberEditor();
}
function dragQuestion(gi,qi){dragQ={gi,qi}}
function dropQuestion(tgi,tqi){
  if(!dragQ)return;
  const source=state.edit.groups[dragQ.gi];
  const q=source.questions.splice(dragQ.qi,1)[0];
  const target=state.edit.groups[tgi];
  let at=tqi;
  if(dragQ.gi===tgi&&dragQ.qi<tqi)at--;
  target.questions.splice(Math.max(0,at),0,q);
  dragQ=null;renumberEditor();
}

async function saveEditor(){
  try{
    syncEditor();
    if(!state.edit.title.trim())throw new Error("タイトルを入力してください。");
    const d=await api("save_survey",{survey:state.edit});
    const i=state.surveys.findIndex(x=>x.id===d.survey.id);
    if(i>=0)state.surveys[i]=d.survey;else state.surveys.push(d.survey);
    showScreen("list");
  }catch(e){alert(e.message)}
}

function statusChanged(){
  const value=document.getElementById("editStatus").value;
  const old=state.edit.status;
  if(value===old)return;
  const messages={
    published:"このアンケートを公開しますか？",
    stopped:"このアンケートを停止しますか？",
    draft:"下書きへ変更しますか？"
  };
  if(!messages[value]){
    document.getElementById("editStatus").value=old;
    return;
  }
  confirmModal("状態変更",messages[value],async()=>{
    try{
      const d=await api("change_status",{surveyId:state.edit.id,status:value});
      state.edit=d.survey;
      state.editOriginal=clone(d.survey);
      renderEditor();
    }catch(e){
      alert(e.message);
      renderEditor();
    }
  },()=>renderEditor());
}

function cancelEditor(){
  confirmModal("編集内容破棄","編集内容を破棄して前画面へ戻りますか？",
    ()=>showScreen("list"));
}

function confirmModal(title,message,ok,cancel=()=>{}){
  const bg=document.getElementById("modalBg");
  document.getElementById("modalTitle").textContent=title;
  document.getElementById("modalMessage").textContent=message;
  bg.classList.add("show");
  const c=document.getElementById("modalCancel");
  const o=document.getElementById("modalOK");
  c.onclick=()=>{bg.classList.remove("show");cancel()};
  o.onclick=()=>{bg.classList.remove("show");Promise.resolve(ok()).catch(e=>alert(e.message))};
}

function duplicateSurvey(id){
  confirmModal("アンケート複製","このアンケートを複製しますか？",async()=>{
    const d=await api("duplicate_survey",{surveyId:id});
    state.surveys.push(d.survey);
    renderSurveyList();
  });
}

function deleteSurvey(id){
  confirmModal("アンケート削除","このアンケートを削除しますか？",async()=>{
    await api("delete_survey",{surveyId:id});
    state.surveys=state.surveys.filter(x=>x.id!==id);
    state.responses=state.responses.filter(x=>x.surveyId!==id);
    renderSurveyList();
  });
}

function showPreview(){
  syncEditor();
  previewMode("pc");
  showScreen("preview");
}

function previewMode(mode){
  const s=state.edit;
  const inner=`
    <div class="${mode==="sp"?"preview-phone":""}">
      <h2>${esc(s.title)}</h2>
      <p>${esc(s.description).replace(/\n/g,"<br>")}</p>
      ${s.groups.map(g=>`
        <h3>${esc(g.title)}</h3>
        ${g.questions.map(q=>`
          <div class="question">
            <strong>${esc(q.questionNumber)} ${esc(q.text)}</strong>
            ${q.required?` <span class="badge published">必須</span>`:""}
            ${q.type==="text"
              ?`<textarea disabled></textarea>`
              :q.choices.map(c=>`<label class="answer-choice"><input disabled type="${q.type==="multi"?"checkbox":"radio"}">${esc(c.label)}</label>`).join("")}
          </div>`).join("")}
      `).join("")}
    </div>`;
  document.getElementById("previewBody").innerHTML=inner;
}

function openAggregate(id){
  state.aggregateId=id;
  state.questionSelection=new Set(
    allQuestionsFromSurvey(state.surveys.find(s=>s.id===id)||{})
      .map(q=>q.id)
  );
  showScreen("aggregate");
}

function allQuestionsFromSurvey(s){
  return (s.groups||[]).flatMap(g=>g.questions||[]);
}

function renderAggregate(){
  const s=state.surveys.find(x=>x.id===state.aggregateId);
  if(!s){showScreen("list");return}
  const rs=state.responses.filter(r=>r.surveyId===s.id);
  const sent=state.customers.filter(c=>c.lastSentAt).length;
  const registered=rs.filter(r=>r.customerId).length;
  const unregistered=rs.filter(r=>!r.customerId).length;
  const rate=sent?Math.round(rs.length/sent*100):0;

  document.getElementById("aggregateTitle").textContent=s.title;
  document.getElementById("summary").innerHTML=[
    ["送信対象者数",sent],
    ["回答数",rs.length],
    ["未登録回答",unregistered],
    ["未回答",Math.max(0,sent-registered)],
    ["回答率",rate+"%"]
  ].map(x=>`<div class="kpi"><small>${esc(x[0])}</small><strong>${esc(x[1])}</strong></div>`).join("");

  const qs=allQuestionsFromSurvey(s);
  document.getElementById("questionStats").innerHTML=qs.map(q=>{
    const vals=rs.map(r=>r.answers?.[q.id]).filter(v=>v!==undefined&&v!==null&&v!=="");
    if(q.type==="text"){
      return `<div class="card"><h3>${esc(q.questionNumber)} ${esc(q.text)}</h3>
        ${vals.map((v,i)=>`<p><strong>回答${i+1}</strong> ${esc(Array.isArray(v)?v.join(", "):v)}</p>`).join("")||"回答なし"}</div>`;
    }
    const counts={};
    (q.choices||[]).forEach(c=>counts[c.id]=0);
    vals.forEach(v=>{
      (Array.isArray(v)?v:[v]).forEach(x=>{if(x in counts)counts[x]++});
    });
    return `<div class="card">
      <label><input type="checkbox" ${state.questionSelection.has(q.id)?"checked":""}
        onchange="toggleQuestion('${esc(q.id)}',this.checked)"> 設問を選択</label>
      <h3>${esc(q.questionNumber)} ${esc(q.text)}</h3>
      ${(q.choices||[]).map(c=>{
        const n=counts[c.id]||0,p=vals.length?Math.round(n/vals.length*100):0;
        return `<div style="margin:12px 0"><div>${esc(c.label)}：${n}件 (${p}%)</div><div class="bar"><i style="width:${p}%"></i></div></div>`;
      }).join("")}
    </div>`;
  }).join("");

  document.getElementById("responseRows").innerHTML=rs.map(r=>{
    const customer=state.customers.find(c=>c.id===r.customerId);
    return `<tr><td>${fmtDate(r.createdAt)}</td><td>${esc(customer?.name||r.respondent?.name||"未登録")}</td><td><pre style="white-space:pre-wrap">${esc(JSON.stringify(r.answers,null,2))}</pre></td></tr>`;
  }).join("")||`<tr><td colspan="3">回答はありません。</td></tr>`;
}

function toggleQuestion(id,on){
  if(on)state.questionSelection.add(id);else state.questionSelection.delete(id);
}
function selectAllQuestions(on){
  const s=state.surveys.find(x=>x.id===state.aggregateId);
  allQuestionsFromSurvey(s||{}).forEach(q=>on?state.questionSelection.add(q.id):state.questionSelection.delete(q.id));
  renderAggregate();
}

function exportCSV(){
  const s=state.surveys.find(x=>x.id===state.aggregateId);
  if(!s)return;
  const qs=allQuestionsFromSurvey(s);
  const rows=[["回答日時","回答者","メール",...qs.filter(q=>state.questionSelection.has(q.id)).map(q=>q.questionNumber+" "+q.text)]];
  state.responses.filter(r=>r.surveyId===s.id).forEach(r=>{
    const c=state.customers.find(x=>x.id===r.customerId);
    rows.push([
      r.createdAt,c?.name||r.respondent?.name||"未登録",c?.email||r.respondent?.email||"",
      ...qs.filter(q=>state.questionSelection.has(q.id)).map(q=>{
        const v=r.answers?.[q.id];
        return Array.isArray(v)?v.join(" / "):v??"";
      })
    ]);
  });
  const csv="\ufeff"+rows.map(row=>row.map(v=>{
    const x=String(v??"").replace(/"/g,'""');
    return `"${x}"`;
  }).join(",")).join("\r\n");
  const a=document.createElement("a");
  a.href=URL.createObjectURL(new Blob([csv],{type:"text/csv;charset=utf-8"}));
  a.download=(s.title||"survey")+".csv";
  a.click();
  notice("exportNotice","CSV出力を実行しました。","ok");
}

function exportPDF(){
  notice("exportNotice","PDF出力操作を実行しました。印刷ダイアログからPDFとして保存できます。","ok");
  window.print();
}

function openSend(id){
  state.sendId=id;
  state.selectedCustomers=new Set;
  showScreen("send");
}

function renderSend(){
  const s=state.surveys.find(x=>x.id===state.sendId);
  if(!s){showScreen("list");return}
  document.getElementById("sendTitle").textContent="送信： "+s.title;
  renderCustomers();
  renderHistory();
}

function renderCustomers(){
  const q=(document.getElementById("customerSearch")?.value||"").toLowerCase();
  const rows=state.customers.filter(c=>{
    return !q||[
      c.name,c.organization,c.email,c.status
    ].some(v=>String(v||"").toLowerCase().includes(q));
  });
  document.getElementById("customerRows").innerHTML=rows.map(c=>`
    <tr>
      <td><input type="checkbox" ${state.selectedCustomers.has(c.id)?"checked":""}
        onchange="toggleCustomer('${esc(c.id)}',this.checked)"></td>
      <td>${esc(c.organization)}</td>
      <td>${esc(c.name)}</td>
      <td>${esc(c.email)}</td>
      <td>${esc(c.phone)}</td>
      <td>${esc(c.address)}</td>
      <td>${fmtDate(c.lastSentAt)}</td>
      <td>${esc(c.sendCount||0)}</td>
      <td>${esc(answerStatus(c))}</td>
      <td>${esc(c.kintoneStatus||"未登録")}</td>
    </tr>`).join("")||`<tr><td colspan="10">顧客がありません。</td></tr>`;
}

function answerStatus(c){
  if(!state.sendId)return c.status||"未送信";
  const r=state.responses.some(x=>x.surveyId===state.sendId&&x.customerId===c.id);
  return r?"回答済み":(c.lastSentAt?"送信済み / 未回答":"未送信");
}

function toggleCustomer(id,on){
  if(on)state.selectedCustomers.add(id);else state.selectedCustomers.delete(id);
}

function clearCustomerSelection(){
  state.selectedCustomers.clear();renderCustomers();
}

function selectReminder(){
  state.customers.forEach(c=>{
    if(answerStatus(c)==="送信済み / 未回答")state.selectedCustomers.add(c.id);
  });
  renderCustomers();
}

function sendConfirm(type){
  const ids=[...state.selectedCustomers];
  if(!ids.length){alert("顧客を選択してください。");return}
  confirmModal(type,"選択した "+ids.length+" 件へメールを送信します。よろしいですか？",
    ()=>doSend(type,ids));
}

async function doSend(type,ids){
  try{
    const d=await api("send_mail",{
      surveyId:state.sendId,
      customerIds:ids,
      subject:document.getElementById("mailSubject").value,
      body:document.getElementById("mailBody").value,
      type
    });
    d.results.forEach(r=>{
      const c=state.customers.find(x=>x.id===r.customerId);
      if(c&&r.success){
        c.lastSentAt=new Date().toISOString();
        c.sendCount=(c.sendCount||0)+1;
        c.status="送信済み / 未回答";
      }
    });
    state.history.push({
      surveyId:state.sendId,
      sentAt:d.summary.sentAt,
      type,
      count:d.summary.total,
      successCount:d.summary.success,
      subject:document.getElementById("mailSubject").value,
      body:document.getElementById("mailBody").value,
      results:d.results
    });
    document.getElementById("sendResult").innerHTML=`
      <div class="notice ${d.summary.failed?"":"ok"}">
        対象 ${d.summary.total}件 / 成功 ${d.summary.success}件 / 失敗 ${d.summary.failed}件 /
        ${fmtDate(d.summary.sentAt)}
      </div>
      ${d.results.map(r=>`<div>${esc(r.customerName)}：${r.success?"成功":"失敗 - "+r.message}</div>`).join("")}`;
    renderCustomers();renderHistory();
  }catch(e){notice("sendResult",e.message,"error")}
}

function renderHistory(){
  const h=state.history.filter(x=>x.surveyId===state.sendId);
  document.getElementById("history").innerHTML=h.slice().reverse().map((x,i)=>`
    <div class="card">
      <strong>${esc(x.type||"送信")} / ${fmtDate(x.sentAt)}</strong>
      <div>対象 ${esc(x.count)}件 / 成功 ${esc(x.successCount??"-")}件</div>
      <div>件名：${esc(x.subject)}</div>
      <button class="secondary" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='block'?'none':'block'">詳細</button>
      <div class="history-detail">
        <pre style="white-space:pre-wrap">${esc(x.body||"")}</pre>
        ${(x.results||[]).map(r=>`<hr><strong>${esc(r.customerName)}</strong><br>${esc(r.subject||"")}<br>${esc(r.body||"")}<br>${esc(r.url||"")}`).join("")}
      </div>
    </div>`).join("")||"送信履歴はありません。";
}

function loadSettings(){
  if(!state.kintone)return;
  document.getElementById("kinSubdomain").value=state.kintone.subdomain||"";
  document.getElementById("kinAppId").value=state.kintone.appId||"";
  document.getElementById("kinLogin").value=state.kintone.loginName||"";
  document.getElementById("kinSSL").value=String(!!state.kintone.sslVerify);
  document.getElementById("kinProxy").value=state.kintone.proxy||"";
}

function renderKintone(){
  loadSettings();
  const f=state.kintone?.fields||[];
  document.getElementById("kinFields").innerHTML=f.map(x=>
    `<tr><td>${esc(x.code)}</td><td>${esc(x.label)}</td><td>${esc(x.type)}</td></tr>`
  ).join("")||`<tr><td colspan="3">フィールド未取得</td></tr>`;
  renderMapping();
}

function renderMapping(){
  const fields=state.kintone?.fields||[];
  const opts=`<option value="">未設定</option>`+
    fields.map(f=>`<option value="${esc(f.code)}">${esc(f.label)} (${esc(f.code)})</option>`).join("");
  const m=state.kintone?.mapping||{};
  document.getElementById("mapping").innerHTML=[
    ["organization","組織名"],["name","氏名"],["email","メールアドレス"],
    ["department","部署名"],["phone","電話番号"]
  ].map(([k,l])=>`<div class="field"><label>${l}</label><select data-map="${k}">${opts}</select></div>`).join("")+
  `<div class="field"><label>住所（複数選択）</label>${fields.map(f=>
    `<label><input type="checkbox" data-address="${esc(f.code)}" ${(m.address||[]).includes(f.code)?"checked":""}> ${esc(f.label)} (${esc(f.code)})</label>`
  ).join("")}</div>`;
  document.querySelectorAll("[data-map]").forEach(e=>e.value=m[e.dataset.map]||"");
}

async function saveKintone(){
  try{
    const mapping={};
    document.querySelectorAll("[data-map]").forEach(e=>mapping[e.dataset.map]=e.value);
    mapping.address=[...document.querySelectorAll("[data-address]:checked")].map(e=>e.dataset.address);
    const settings={
      subdomain:document.getElementById("kinSubdomain").value,
      appId:document.getElementById("kinAppId").value,
      loginName:document.getElementById("kinLogin").value,
      password:document.getElementById("kinPassword").value,
      sslVerify:document.getElementById("kinSSL").value==="true",
      proxy:document.getElementById("kinProxy").value,
      mapping
    };
    const d=await api("save_kintone",{settings});
    state.kintone=d.settings;
    document.getElementById("kinPassword").value="";
    notice("kinNotice","kintone設定を保存しました。","ok");
    renderMapping();
  }catch(e){notice("kinNotice",e.message,"error")}
}

async function kinTest(){
  try{
    const d=await api("kintone_test");
    notice("kinNotice",d.message,"ok");
  }catch(e){notice("kinNotice",e.message,"error")}
}

async function kinFields(){
  try{
    const d=await api("kintone_fields");
    state.kintone.fields=d.fields||[];
    notice("kinNotice","項目一覧を取得しました。","ok");
    renderKintone();
  }catch(e){notice("kinNotice",e.message,"error")}
}

async function kinSync(){
  try{
    const d=await api("kintone_sync");
    state.customers=d.customers||[];
    notice("kinNotice",d.message+"（"+d.count+"件）","ok");
  }catch(e){notice("kinNotice",e.message,"error")}
}

function renderMail(){
  const m=state.mail||{};
  document.getElementById("smtpServer").value=m.server||"";
  document.getElementById("smtpPort").value=m.port||587;
  document.getElementById("smtpEncryption").value=m.encryption||"tls";
  document.getElementById("smtpAuth").value=String(!!m.auth);
  document.getElementById("smtpUser").value=m.username||"";
  document.getElementById("smtpFrom").value=m.fromEmail||"";
  document.getElementById("smtpFromName").value=m.fromName||"";
  document.getElementById("smtpReply").value=m.replyTo||"";
}

async function saveMail(){
  try{
    const d=await api("save_mail",{settings:{
      server:document.getElementById("smtpServer").value,
      port:document.getElementById("smtpPort").value,
      encryption:document.getElementById("smtpEncryption").value,
      auth:document.getElementById("smtpAuth").value==="true",
      username:document.getElementById("smtpUser").value,
      password:document.getElementById("smtpPass").value,
      fromEmail:document.getElementById("smtpFrom").value,
      fromName:document.getElementById("smtpFromName").value,
      replyTo:document.getElementById("smtpReply").value
    }});
    state.mail=d.mail;
    document.getElementById("smtpPass").value="";
    notice("mailNotice","メール設定を保存しました。","ok");
  }catch(e){notice("mailNotice",e.message,"error")}
}

async function mailTest(){
  const to=prompt("テスト送信先メールアドレス",state.mail?.replyTo||"");
  if(!to)return;
  try{
    const d=await api("mail_test",{to});
    notice("mailNotice",d.message,"ok");
    state.mail.status="接続確認済み";
  }catch(e){notice("mailNotice",e.message,"error")}
}

function adminReset(){
  state.edit=null;
  state.aggregateId=null;
  state.sendId=null;
  state.selectedCustomers.clear();
  showScreen("list");
}

function answerEntry(){
  const params=new URLSearchParams(location.search);
  const sid=params.get("answer");
  if(!sid)return false;

  document.getElementById("adminApp").style.display="none";
  document.getElementById("respondentApp").style.display="block";

  const s=state.surveys.find(x=>x.id===sid);
  if(!s){
    document.getElementById("answerContent").innerHTML=
      `<div class="answer-header"><h2>アンケートがありません</h2></div>`;
    return true;
  }

  const cid=params.get("customer")||"";
  state.answerSurvey=s;
  state.answerCustomer=cid;
  const answered=state.responses.some(r=>r.surveyId===sid&&r.customerId===cid);

  if(s.status!=="published"){
    document.getElementById("answerContent").innerHTML=
      `<div class="answer-header"><h2>回答できません</h2><p>このアンケートは現在回答を受け付けていません。</p></div>`;
    return true;
  }

  if(answered&&!s.allowResubmit){
    document.getElementById("answerContent").innerHTML=
      `<div class="answer-header"><h2>回答済みです</h2><p>このアンケートはすでに回答済みです。</p></div>`;
    return true;
  }

  state.answerScreen="answer";
  state.answers={};
  renderAnswer();
  return true;
}

function visibleQuestions(){
  const s=state.answerSurvey;
  if(!s)return[];
  const qs=allQuestionsFromSurvey(s);
  const visible=new Set(qs.map(q=>q.id));

  qs.forEach(q=>{
    if(q.type!=="single")return;
    const val=state.answers[q.id];
    if(!val)return;
    const branch=(q.branching||[]).find(b=>b.choiceId===val);
    if(branch&&branch.nextQuestionId){
      let seen=false;
      qs.forEach(x=>{
        if(x.id===branch.nextQuestionId)seen=true;
        if(seen)visible.add(x.id);
      });
    }
  });

  return qs.filter(q=>visible.has(q.id));
}

function validateAnswers(){
  const errors=[];
  visibleQuestions().forEach(q=>{
    if(!q.required)return;
    const v=state.answers[q.id];
    const empty=Array.isArray(v)?v.length===0:v===undefined||v===null||String(v).trim()==="";
    if(empty)errors.push(q.questionNumber+" "+q.text);
  });
  return errors;
}

function renderAnswer(){
  const s=state.answerSurvey;
  const qs=visibleQuestions();
  if(state.answerScreen==="confirm"){
    renderConfirm();
    return;
  }
  if(state.answerScreen==="complete"){
    document.getElementById("answerContent").innerHTML=
      `<div class="answer-header"><h2>回答完了</h2><p>ご回答ありがとうございました。</p></div>`;
    return;
  }

  document.getElementById("answerContent").innerHTML=`
    <div class="answer-header">
      <h1>${esc(s.title)}</h1>
      <p>${esc(s.description).replace(/\n/g,"<br>")}</p>
    </div>
    ${qs.map(q=>`
      <div class="answer-question" id="aq-${esc(q.id)}">
        <h3>${esc(q.questionNumber)} ${esc(q.text)} ${q.required?'<span class="badge published">必須</span>':""}</h3>
        ${q.type==="text"
          ?`<textarea oninput="state.answers['${esc(q.id)}']=this.value">${esc(state.answers[q.id]||"")}</textarea>`
          :(q.choices||[]).map(c=>`
            <label class="answer-choice">
              <input type="${q.type==="multi"?"checkbox":"radio"}"
                name="q_${esc(q.id)}"
                value="${esc(c.id)}"
                ${q.type==="multi"
                  ?(Array.isArray(state.answers[q.id])&&state.answers[q.id].includes(c.id)?"checked":"")
                  :(state.answers[q.id]===c.id?"checked":"")}
                onchange="setAnswer('${esc(q.id)}','${esc(c.id)}',${q.type==="multi"})">
              ${esc(c.label)}
            </label>`).join(""))}
      </div>`).join("")}
    <div class="actions">
      <button class="primary" onclick="nextAnswer()">回答内容を確認する</button>
    </div>`;
}

function setAnswer(qid,cid,multi){
  if(multi){
    let a=Array.isArray(state.answers[qid])?[...state.answers[qid]]:[];
    if(a.includes(cid))a=a.filter(x=>x!==cid);else a.push(cid);
    state.answers[qid]=a;
  }else state.answers[qid]=cid;
}

function nextAnswer(){
  const errors=validateAnswers();
  if(errors.length){
    alert("必須回答を確認してください。\n\n"+errors.join("\n"));
    const el=document.getElementById("aq-"+allQuestionsFromSurvey(state.answerSurvey).find(q=>errors[0].startsWith(q.questionNumber))?.id);
    el?.scrollIntoView({behavior:"smooth"});
    return;
  }
  state.answerScreen="confirm";
  renderAnswer();
}

function answerText(q,v){
  if(q.type==="text")return String(v||"");
  const vals=Array.isArray(v)?v:[v];
  return vals.map(x=>q.choices?.find(c=>c.id===x)?.label||x).join("、");
}

function renderConfirm(){
  const s=state.answerSurvey;
  document.getElementById("answerContent").innerHTML=`
    <div class="answer-header"><h1>回答内容の確認</h1></div>
    ${visibleQuestions().map(q=>`
      <div class="answer-question">
        <h3>${esc(q.questionNumber)} ${esc(q.text)}</h3>
        <p>${esc(answerText(q,state.answers[q.id])).replace(/\n/g,"<br>")||"未回答"}</p>
        <button class="secondary" onclick="state.answerScreen='answer';renderAnswer()">修正</button>
      </div>`).join("")}
    <div class="actions">
      <button class="secondary" onclick="state.answerScreen='answer';renderAnswer()">戻る</button>
      <button class="primary" onclick="confirmAnswerSend()">回答を送信</button>
    </div>`;
}

function confirmAnswerSend(){
  confirmModal("回答送信","この内容で回答を送信しますか？",
    submitAnswer);
}

async function submitAnswer(){
  try{
    const d=await api("save_response",{
      surveyId:state.answerSurvey.id,
      customerId:state.answerCustomer,
      answers:state.answers,
      respondent:state.respondent
    });
    if(!d.success)throw new Error(d.error);
    state.answerScreen="complete";
    renderAnswer();
  }catch(e){alert(e.message)}
}

function answerInit(){
  if(answerEntry())return;
  boot();
}

answerInit();
</script>
</body>
</html>
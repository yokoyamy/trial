<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * 環境:
 * - Apache 2.4
 * - PHP 8.5
 * - DBなし
 * - PHP cURLなし
 * - index.php が入口
 *
 * データ:
 *   ./data/surveys.json
 *   ./data/answers.json
 *   ./data/customers.json
 *   ./data/mail_logs.json
 *   ./data/settings.json
 *
 * 注意:
 * - POCのため管理者認証・CSRF対策は実装しない。
 * - 本番利用時には認証、CSRF、秘密情報管理、DB等を別途実装する。
 */

session_start();

const DATA_DIR = __DIR__ . '/data';

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0775, true);
}

/* ============================================================
 * Utility
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function id(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(8));
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function dataFile(string $name): string
{
    return DATA_DIR . '/' . $name . '.json';
}

function readData(string $name, mixed $default = []): mixed
{
    $file = dataFile($name);

    if (!is_file($file)) {
        return $default;
    }

    $json = file_get_contents($file);

    if ($json === false || $json === '') {
        return $default;
    }

    $data = json_decode($json, true);

    return json_last_error() === JSON_ERROR_NONE ? $data : $default;
}

function writeData(string $name, mixed $data): void
{
    $file = dataFile($name);

    $fp = fopen($file, 'c+');

    if ($fp === false) {
        throw new RuntimeException('データファイルを開けません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データファイルをロックできません。');
        }

        ftruncate($fp, 0);
        rewind($fp);

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

        if ($json === false || fwrite($fp, $json) === false) {
            throw new RuntimeException('データを保存できません。');
        }

        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

function findSurvey(string $surveyId): ?array
{
    $surveys = readData('surveys', []);

    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $surveyId) {
            return $survey;
        }
    }

    return null;
}

function saveSurvey(array $survey): void
{
    $surveys = readData('surveys', []);
    $found = false;

    foreach ($surveys as $i => $item) {
        if (($item['id'] ?? '') === $survey['id']) {
            $surveys[$i] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $surveys[] = $survey;
    }

    writeData('surveys', $surveys);
}

function deleteSurvey(string $surveyId): void
{
    $surveys = readData('surveys', []);

    $surveys = array_values(
        array_filter(
            $surveys,
            fn(array $s): bool => ($s['id'] ?? '') !== $surveyId
        )
    );

    writeData('surveys', $surveys);
}

function surveyStatus(array $survey): string
{
    $status = $survey['status'] ?? 'draft';

    if (
        $status === 'published' &&
        !empty($survey['end_at'])
    ) {
        $end = strtotime($survey['end_at']);

        if ($end !== false && $end < time()) {
            return 'finished';
        }
    }

    return $status;
}

function statusLabel(string $status): string
{
    return [
        'draft'     => '下書き',
        'published' => '公開中',
        'stopped'   => '停止',
        'finished'  => '終了',
    ][$status] ?? $status;
}

function statusClass(string $status): string
{
    return [
        'draft'     => 'badge-gray',
        'published' => 'badge-success',
        'stopped'   => 'badge-warning',
        'finished'  => 'badge-danger',
    ][$status] ?? 'badge-gray';
}

function answerCount(string $surveyId): int
{
    $answers = readData('answers', []);

    return count(
        array_filter(
            $answers,
            fn(array $a): bool => ($a['survey_id'] ?? '') === $surveyId
        )
    );
}

function normalizeQuestions(array &$survey): void
{
    $mode = $survey['numbering'] ?? 'global';

    foreach ($survey['groups'] as $gi => &$group) {
        foreach ($group['questions'] as $qi => &$question) {
            if ($mode === 'group') {
                $question['number'] = 'Q' . ($gi + 1) . '-' . ($qi + 1);
            } else {
                static $n;
                if ($qi === 0 && $gi === 0) {
                    $n = 0;
                }
                $n++;
                $question['number'] = 'Q' . $n;
            }
        }
    }

    unset($group, $question);
}

function newQuestion(): array
{
    return [
        'id' => id('q_'),
        'text' => '新しい質問',
        'type' => 'single',
        'required' => false,
        'choices' => [
            ['id' => id('c_'), 'text' => '選択肢1'],
            ['id' => id('c_'), 'text' => '選択肢2'],
        ],
        'conditions' => [],
        'number' => '',
    ];
}

function newGroup(): array
{
    return [
        'id' => id('g_'),
        'title' => '新しいグループ',
        'questions' => [
            newQuestion()
        ],
    ];
}

function newSurvey(): array
{
    $survey = [
        'id' => id('survey_'),
        'title' => '新しいアンケート',
        'description' => '',
        'start_at' => date('Y-m-d\TH:i'),
        'end_at' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'created_at' => now(),
        'updated_at' => now(),
        'groups' => [
            [
                'id' => id('g_'),
                'title' => '基本情報',
                'questions' => [
                    newQuestion()
                ],
            ]
        ],
    ];

    normalizeQuestions($survey);

    return $survey;
}

/* ============================================================
 * kintone
 * ============================================================ */

function kintoneRequest(
    array $config,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    $host = trim((string)($config['subdomain'] ?? ''));

    if ($host === '') {
        throw new RuntimeException('kintoneサブドメインが設定されていません。');
    }

    $host = preg_replace('#^https?://#', '', $host);
    $host = rtrim($host, '/');

    $url = 'https://' . $host . $path;

    $login = (string)($config['login'] ?? '');
    $password = (string)($config['password'] ?? '');

    if ($login === '' || $password === '') {
        throw new RuntimeException('kintoneログイン情報が設定されていません。');
    }

    $authorization = base64_encode($login . ':' . $password);

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body === null
                ? null
                : json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $result = file_get_contents($url, false, $context);

    if ($result === false) {
        throw new RuntimeException('kintoneへの接続に失敗しました。');
    }

    $data = json_decode($result, true);

    if (!is_array($data)) {
        throw new RuntimeException('kintoneから不正なレスポンスが返されました。');
    }

    return $data;
}

function testKintone(array $config): array
{
    $appId = (int)($config['app_id'] ?? 0);

    if ($appId <= 0) {
        throw new RuntimeException('顧客管理アプリIDが不正です。');
    }

    return kintoneRequest(
        $config,
        '/k/v1/app.json?id=' . $appId
    );
}

function getKintoneFields(array $config): array
{
    $appId = (int)($config['app_id'] ?? 0);

    $data = kintoneRequest(
        $config,
        '/k/v1/app/form/fields.json?app=' . $appId
    );

    return $data['properties'] ?? [];
}

function getKintoneCustomers(array $config): array
{
    $appId = (int)($config['app_id'] ?? 0);

    $data = kintoneRequest(
        $config,
        '/k/v1/records.json?app=' . $appId . '&query=' .
        rawurlencode('limit 500')
    );

    return $data['records'] ?? [];
}

/* ============================================================
 * SMTP
 * PHP cURLを使用しない簡易SMTP実装
 * ============================================================ */

function smtpRead($socket): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    return $response;
}

function smtpExpect($socket, array $codes): void
{
    $response = smtpRead($socket);

    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . $code
        );
    }
}

function smtpCommand($socket, string $command, array $codes): void
{
    fwrite($socket, $command . "\r\n");
    smtpExpect($socket, $codes);
}

function smtpSend(array $config, string $to, string $subject, string $body): void
{
    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port'] ?? 587);
    $user = (string)($config['username'] ?? '');
    $pass = (string)($config['password'] ?? '');
    $from = (string)($config['from_email'] ?? '');
    $fromName = (string)($config['from_name'] ?? '');
    $replyTo = (string)($config['reply_to'] ?? '');

    if ($host === '' || $from === '') {
        throw new RuntimeException('SMTP設定が不足しています。');
    }

    $encryption = $config['encryption'] ?? 'tls';

    if ($encryption === 'ssl') {
        $host = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket = stream_socket_client(
        $host . ':' . $port,
        $errno,
        $errstr,
        15
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました: ' . $errstr
        );
    }

    stream_set_timeout($socket, 15);

    try {
        smtpExpect($socket, [220]);

        smtpCommand($socket, 'EHLO localhost', [250]);

        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException('TLS接続に失敗しました。');
            }

            smtpCommand($socket, 'EHLO localhost', [250]);
        }

        if ($user !== '') {
            smtpCommand($socket, 'AUTH LOGIN', [334]);
            smtpCommand($socket, base64_encode($user), [334]);
            smtpCommand($socket, base64_encode($pass), [235]);
        }

        smtpCommand(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtpCommand(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        fwrite($socket, "DATA\r\n");
        smtpExpect($socket, [354]);

        $encodedSubject = '=?UTF-8?B?' .
            base64_encode($subject) . '?=';

        $encodedFromName = '=?UTF-8?B?' .
            base64_encode($fromName) . '?=';

        $headers = [
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $message = implode("\r\n", $headers)
            . "\r\n\r\n"
            . str_replace("\n", "\r\n", $body)
            . "\r\n.";

        fwrite($socket, $message . "\r\n");
        smtpExpect($socket, [250]);

        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

/* ============================================================
 * CSV
 * ============================================================ */

function outputCsv(array $survey): never
{
    $answers = readData('answers', []);

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        $survey['id'] . '.csv"'
    );

    $fp = fopen('php://output', 'w');

    // Excel向けUTF-8 BOM
    fwrite($fp, "\xEF\xBB\xBF");

    $header = [
        '回答ID',
        '回答日時',
        '回答者メール',
    ];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $header[] = $question['number'] . ' ' . $question['text'];
        }
    }

    fputcsv($fp, $header);

    foreach ($answers as $answer) {
        if (($answer['survey_id'] ?? '') !== $survey['id']) {
            continue;
        }

        $row = [
            $answer['id'] ?? '',
            $answer['created_at'] ?? '',
            $answer['email'] ?? '',
        ];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $value = $answer['answers'][$question['id']] ?? '';

                if (is_array($value)) {
                    $value = implode(', ', $value);
                }

                $row[] = $value;
            }
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* ============================================================
 * PDF
 *
 * 外部ライブラリなしのため、POCでは印刷用PDF画面を生成。
 * ブラウザの「PDFとして保存」でPDF化できる。
 * ============================================================ */

function outputPdfView(array $survey): never
{
    $answers = readData('answers', []);

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <title><?= h($survey['title']) ?> 集計</title>
        <style>
            body {
                font-family: sans-serif;
                color: #111827;
                padding: 30px;
            }
            h1 { font-size: 24px; }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th, td {
                border: 1px solid #ccc;
                padding: 8px;
                text-align: left;
            }
            @media print {
                .no-print { display:none; }
            }
        </style>
    </head>
    <body>
        <div class="no-print">
            <button onclick="window.print()">PDFとして印刷</button>
        </div>

        <h1><?= h($survey['title']) ?></h1>
        <p>回答数: <?= answerCount($survey['id']) ?></p>

        <table>
            <thead>
            <tr>
                <th>回答日時</th>
                <?php foreach ($survey['groups'] as $group): ?>
                    <?php foreach ($group['questions'] as $q): ?>
                        <th><?= h($q['number']) ?> <?= h($q['text']) ?></th>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($answers as $answer): ?>
                <?php if (($answer['survey_id'] ?? '') !== $survey['id']) continue; ?>
                <tr>
                    <td><?= h($answer['created_at'] ?? '') ?></td>

                    <?php foreach ($survey['groups'] as $group): ?>
                        <?php foreach ($group['questions'] as $q): ?>
                            <?php
                            $value = $answer['answers'][$q['id']] ?? '';
                            if (is_array($value)) {
                                $value = implode(', ', $value);
                            }
                            ?>
                            <td><?= h($value) ?></td>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

/* ============================================================
 * POST Actions
 * ============================================================ */

$action = $_POST['action'] ?? '';

try {
    if ($action === 'save_survey') {
        $surveyId = trim((string)($_POST['id'] ?? ''));

        $survey = $surveyId !== ''
            ? findSurvey($surveyId)
            : newSurvey();

        if ($survey === null) {
            throw new RuntimeException('アンケートが見つかりません。');
        }

        $title = trim((string)($_POST['title'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('タイトルは必須です。');
        }

        $survey['title'] = $title;
        $survey['description'] = trim((string)($_POST['description'] ?? ''));
        $survey['start_at'] = trim((string)($_POST['start_at'] ?? ''));
        $survey['end_at'] = trim((string)($_POST['end_at'] ?? ''));
        $survey['numbering'] = $_POST['numbering'] ?? 'global';

        $groupsJson = $_POST['groups_json'] ?? '[]';
        $groups = json_decode($groupsJson, true);

        if (!is_array($groups)) {
            throw new RuntimeException('質問データが不正です。');
        }

        $survey['groups'] = $groups;
        $survey['updated_at'] = now();

        normalizeQuestions($survey);
        saveSurvey($survey);

        redirect('index.php?screen=list');
    }

    if ($action === 'delete_survey') {
        $surveyId = trim((string)($_POST['id'] ?? ''));

        if ($surveyId !== '') {
            deleteSurvey($surveyId);
        }

        redirect('index.php?screen=list');
    }

    if ($action === 'change_status') {
        $survey = findSurvey((string)($_POST['id'] ?? ''));

        if (!$survey) {
            throw new RuntimeException('アンケートが見つかりません。');
        }

        $next = (string)($_POST['status'] ?? '');

        $allowed = [
            'draft' => ['published'],
            'published' => ['stopped'],
            'stopped' => ['published'],
        ];

        $current = surveyStatus($survey);

        if (
            isset($allowed[$current]) &&
            in_array($next, $allowed[$current], true)
        ) {
            $survey['status'] = $next;
            $survey['updated_at'] = now();
            saveSurvey($survey);
        }

        redirect(
            'index.php?screen=edit&id=' .
            rawurlencode($survey['id'])
        );
    }

    if ($action === 'duplicate_survey') {
        $survey = findSurvey((string)($_POST['id'] ?? ''));

        if (!$survey) {
            throw new RuntimeException('アンケートが見つかりません。');
        }

        $survey['id'] = id('survey_');
        $survey['title'] .= '（複製）';
        $survey['status'] = 'draft';
        $survey['created_at'] = now();
        $survey['updated_at'] = now();

        saveSurvey($survey);

        redirect('index.php?screen=list');
    }

    if ($action === 'save_kintone') {
        $config = [
            'subdomain' => trim((string)($_POST['subdomain'] ?? '')),
            'app_id' => (int)($_POST['app_id'] ?? 0),
            'login' => (string)($_POST['login'] ?? ''),
            'password' => (string)($_POST['password'] ?? ''),
            'proxy' => trim((string)($_POST['proxy'] ?? '')),
            'verify_ssl' => isset($_POST['verify_ssl']),
            'mapping' => $_POST['mapping'] ?? [],
        ];

        writeData('kintone', $config);

        redirect('index.php?screen=kintone&saved=1');
    }

    if ($action === 'test_kintone') {
        $config = readData('kintone', []);

        $result = testKintone($config);

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'kintone接続成功: ' .
                ($result['name'] ?? 'OK'),
        ];

        redirect('index.php?screen=kintone');
    }

    if ($action === 'sync_kintone') {
        $config = readData('kintone', []);

        $records = getKintoneCustomers($config);

        $customers = [];

        foreach ($records as $record) {
            $mapping = $config['mapping'] ?? [];

            $get = static function (
                array $record,
                string $key
            ): string {
                $value = $record[$key]['value'] ?? '';

                if (is_array($value)) {
                    return implode(' ', $value);
                }

                return (string)$value;
            };

            $customers[] = [
                'id' => id('customer_'),
                'kintone_id' => $get(
                    $record,
                    $mapping['id'] ?? 'レコード番号'
                ),
                'organization' => $get(
                    $record,
                    $mapping['organization'] ?? ''
                ),
                'name' => $get(
                    $record,
                    $mapping['name'] ?? ''
                ),
                'email' => $get(
                    $record,
                    $mapping['email'] ?? ''
                ),
                'department' => $get(
                    $record,
                    $mapping['department'] ?? ''
                ),
                'phone' => $get(
                    $record,
                    $mapping['phone'] ?? ''
                ),
                'address' => $get(
                    $record,
                    $mapping['address'] ?? ''
                ),
            ];
        }

        writeData('customers', $customers);

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => count($customers) .
                '件の顧客情報を同期しました。',
        ];

        redirect('index.php?screen=kintone');
    }

    if ($action === 'save_mail') {
        $config = [
            'host' => trim((string)($_POST['host'] ?? '')),
            'port' => (int)($_POST['port'] ?? 587),
            'encryption' => $_POST['encryption'] ?? 'tls',
            'username' => (string)($_POST['username'] ?? ''),
            'password' => (string)($_POST['password'] ?? ''),
            'from_email' => trim((string)($_POST['from_email'] ?? '')),
            'from_name' => trim((string)($_POST['from_name'] ?? '')),
            'reply_to' => trim((string)($_POST['reply_to'] ?? '')),
        ];

        writeData('mail', $config);

        redirect('index.php?screen=mail&saved=1');
    }

    if ($action === 'test_mail') {
        $config = readData('mail', []);

        $to = trim((string)($_POST['test_to'] ?? ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'テスト送信先メールアドレスが不正です。'
            );
        }

        smtpSend(
            $config,
            $to,
            'アンケートアプリ SMTPテスト',
            "SMTP接続テストです。\n\n送信日時: " . now()
        );

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'テストメールを送信しました。',
        ];

        redirect('index.php?screen=mail');
    }

    if ($action === 'send_mail') {
        $survey = findSurvey((string)($_POST['survey_id'] ?? ''));

        if (!$survey) {
            throw new RuntimeException('アンケートが見つかりません。');
        }

        $config = readData('mail', []);
        $customers = readData('customers', []);

        $selected = $_POST['customers'] ?? [];

        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = (string)($_POST['body'] ?? '');

        if ($subject === '') {
            throw new RuntimeException('件名を入力してください。');
        }

        $logs = readData('mail_logs', []);

        foreach ($customers as $customer) {
            if (!in_array($customer['id'], $selected, true)) {
                continue;
            }

            $email = $customer['email'] ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $customerBody = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [
                    $customer['name'] ?? '',
                    rtrim(
                        (
                            !empty($_SERVER['HTTPS'])
                            ? 'https'
                            : 'http'
                        ) . '://' .
                        $_SERVER['HTTP_HOST'] .
                        dirname($_SERVER['SCRIPT_NAME']) .
                        '/index.php?screen=answer&id=' .
                        rawurlencode($survey['id']) .
                        '&customer=' .
                        rawurlencode($customer['id'])
                    ),
                ],
                $body
            );

            try {
                smtpSend(
                    $config,
                    $email,
                    $subject,
                    $customerBody
                );

                $logs[] = [
                    'id' => id('mail_'),
                    'survey_id' => $survey['id'],
                    'customer_id' => $customer['id'],
                    'customer_name' => $customer['name'] ?? '',
                    'email' => $email,
                    'subject' => $subject,
                    'type' => $_POST['send_type'] ?? 'send',
                    'status' => 'sent',
                    'created_at' => now(),
                ];
            } catch (Throwable $e) {
                $logs[] = [
                    'id' => id('mail_'),
                    'survey_id' => $survey['id'],
                    'customer_id' => $customer['id'],
                    'customer_name' => $customer['name'] ?? '',
                    'email' => $email,
                    'subject' => $subject,
                    'type' => $_POST['send_type'] ?? 'send',
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'created_at' => now(),
                ];
            }
        }

        writeData('mail_logs', $logs);

        redirect(
            'index.php?screen=send&id=' .
            rawurlencode($survey['id'])
        );
    }

    if ($action === 'confirm_answer') {
        $survey = findSurvey((string)($_POST['survey_id'] ?? ''));

        if (!$survey) {
            throw new RuntimeException('アンケートが見つかりません。');
        }

        if (surveyStatus($survey) !== 'published') {
            throw new RuntimeException(
                'このアンケートは現在回答できません。'
            );
        }

        $values = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $qid = $question['id'];

                if ($question['type'] === 'multiple') {
                    $values[$qid] = $_POST[$qid] ?? [];
                } else {
                    $values[$qid] = $_POST[$qid] ?? '';
                }
            }
        }

        $errors = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                if (!$question['required']) {
                    continue;
                }

                $value = $values[$question['id']] ?? '';

                if (
                    $value === '' ||
                    $value === [] ||
                    $value === null
                ) {
                    $errors[] =
                        $question['number'] .
                        '「' .
                        $question['text'] .
                        '」は必須です。';
                }
            }
        }

        if ($errors) {
            throw new RuntimeException(
                implode("\n", $errors)
            );
        }

        $_SESSION['answer_draft'] = [
            'survey_id' => $survey['id'],
            'customer_id' => $_POST['customer_id'] ?? '',
            'email' => $_POST['email'] ?? '',
            'answers' => $values,
        ];

        redirect(
            'index.php?screen=confirm&id=' .
            rawurlencode($survey['id'])
        );
    }

    if ($action === 'complete_answer') {
        $draft = $_SESSION['answer_draft'] ?? null;

        if (!$draft) {
            throw new RuntimeException(
                '回答データがありません。'
            );
        }

        $answers = readData('answers', []);

        $draft['id'] = id('answer_');
        $draft['created_at'] = now();

        $answers[] = $draft;

        writeData('answers', $answers);

        unset($_SESSION['answer_draft']);

        redirect(
            'index.php?screen=complete&id=' .
            rawurlencode($draft['survey_id'])
        );
    }
} catch (Throwable $e) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => $e->getMessage(),
    ];
}

/* ============================================================
 * Screen
 * ============================================================ */

$screen = $_GET['screen'] ?? 'list';
$surveyId = (string)($_GET['id'] ?? '');

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$survey = $surveyId !== ''
    ? findSurvey($surveyId)
    : null;

/* ============================================================
 * CSV / PDF
 * ============================================================ */

if (
    $screen === 'csv' &&
    $survey
) {
    outputCsv($survey);
}

if (
    $screen === 'pdf' &&
    $survey
) {
    outputPdfView($survey);
}

/* ============================================================
 * HTML
 * ============================================================ */

$isAnswerScreen = in_array(
    $screen,
    ['answer', 'confirm', 'complete'],
    true
);

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>アンケートアプリ POC</title>

<style>
:root {
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --gray:#64748b;
    --gray-light:#f1f5f9;
    --border:#dbe2ea;
    --text:#1e293b;
    --white:#fff;
    --background:#f8fafc;
    --header:#0f172a;
    --shadow:0 4px 18px rgba(15,23,42,.08);
    --radius:10px;
}

* {
    box-sizing:border-box;
}

body {
    margin:0;
    background:var(--background);
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    line-height:1.6;
}

a {
    color:var(--primary);
    text-decoration:none;
}

a:hover {
    text-decoration:underline;
}

button,
input,
select,
textarea {
    font:inherit;
}

button {
    cursor:pointer;
}

.app-header {
    background:var(--header);
    color:#fff;
    min-height:64px;
    display:flex;
    align-items:center;
    padding:0 24px;
}

.brand {
    color:#fff;
    font-weight:700;
    font-size:1.1rem;
}

.nav {
    margin-left:auto;
    display:flex;
    gap:8px;
    align-items:center;
}

.nav a {
    color:#cbd5e1;
    padding:8px 12px;
    border-radius:6px;
}

.nav a:hover,
.nav a.active {
    color:#fff;
    background:rgba(255,255,255,.08);
    text-decoration:none;
}

.container {
    width:min(1200px, calc(100% - 32px));
    margin:auto;
    padding:28px 0 48px;
}

.answer-container {
    width:min(760px, calc(100% - 32px));
    margin:auto;
    padding:28px 0 48px;
}

.page-title {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    margin-bottom:22px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}

.grid-2 {
    display:grid;
    grid-template-columns:
        repeat(2, minmax(0, 1fr));
    gap:20px;
}

.grid-3 {
    display:grid;
    grid-template-columns:
        repeat(3, minmax(0, 1fr));
    gap:20px;
}

.form-group {
    margin-bottom:16px;
}

.form-label {
    display:block;
    font-weight:600;
    margin-bottom:6px;
}

input[type=text],
input[type=search],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
select,
textarea {
    width:100%;
    min-height:42px;
    padding:9px 12px;
    border:1px solid var(--border);
    border-radius:7px;
    background:#fff;
    color:var(--text);
}

textarea {
    min-height:140px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus {
    border-color:var(--primary);
    outline:none;
    box-shadow:
        0 0 0 3px rgba(37,99,235,.12);
}

.btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    min-height:40px;
    padding:8px 14px;
    border:1px solid transparent;
    border-radius:7px;
    font-weight:600;
    line-height:1.3;
    white-space:nowrap;
}

.btn-primary {
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover {
    background:var(--primary-dark);
    text-decoration:none;
}

.btn-secondary {
    background:#fff;
    color:var(--text);
    border-color:var(--border);
}

.btn-success {
    background:var(--success);
    color:#fff;
}

.btn-warning {
    background:var(--warning);
    color:#fff;
}

.btn-danger {
    background:var(--danger);
    color:#fff;
}

.btn-small {
    min-height:34px;
    padding:5px 10px;
    font-size:.875rem;
}

.actions {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

table {
    width:100%;
    border-collapse:collapse;
}

th,
td {
    text-align:left;
    padding:12px;
    border-bottom:1px solid var(--border);
    vertical-align:top;
}

th {
    background:#f8fafc;
    font-weight:700;
}

.badge {
    display:inline-block;
    padding:3px 9px;
    border-radius:999px;
    font-size:.8rem;
    font-weight:700;
}

.badge-gray {
    background:#e2e8f0;
    color:#475569;
}

.badge-success {
    background:#dcfce7;
    color:#166534;
}

.badge-warning {
    background:#fef3c7;
    color:#92400e;
}

.badge-danger {
    background:#fee2e2;
    color:#991b1b;
}

.alert {
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:18px;
    white-space:pre-line;
}

.alert-success {
    background:#dcfce7;
    color:#166534;
}

.alert-danger {
    background:#fee2e2;
    color:#991b1b;
}

.group-card {
    border:1px solid var(--border);
    border-radius:9px;
    background:#fff;
    margin-bottom:18px;
    overflow:hidden;
}

.group-header {
    display:flex;
    align-items:center;
    gap:10px;
    padding:14px 16px;
    background:var(--gray-light);
    border-bottom:1px solid var(--border);
}

.group-body {
    padding:16px;
}

.question-card {
    border:1px solid var(--border);
    border-radius:8px;
    padding:16px;
    margin-bottom:12px;
    background:#fff;
}

.question-card:last-child {
    margin-bottom:0;
}

.question-card.dragging,
.group-card.dragging {
    opacity:.6;
}

.question-card.drag-over,
.group-card.drag-over {
    border-color:var(--primary);
    box-shadow:
        0 0 0 3px rgba(37,99,235,.12);
}

.drag-handle {
    color:var(--gray);
    cursor:grab;
    user-select:none;
    font-size:20px;
}

.option-row {
    display:flex;
    gap:8px;
    margin-bottom:8px;
}

.option-row input {
    flex:1;
}

.empty {
    padding:34px 20px;
    text-align:center;
    color:var(--gray);
    background:#f8fafc;
    border:1px dashed var(--border);
    border-radius:8px;
}

.stats {
    display:grid;
    grid-template-columns:
        repeat(4, minmax(0,1fr));
    gap:16px;
}

.stat {
    background:#fff;
    border:1px solid var(--border);
    border-radius:8px;
    padding:18px;
}

.stat-label {
    color:var(--gray);
    font-size:.875rem;
}

.stat-value {
    font-size:1.8rem;
    font-weight:700;
}

.answer-card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:18px;
}

.question-title {
    font-weight:700;
    margin-bottom:12px;
}

.required {
    color:var(--danger);
    font-size:.8rem;
    margin-left:5px;
}

.tabs {
    display:flex;
    gap:4px;
    border-bottom:1px solid var(--border);
    margin-bottom:20px;
}

.tabs a {
    padding:10px 14px;
}

.tabs a.active {
    border-bottom:2px solid var(--primary);
    color:var(--primary);
    font-weight:700;
}

.muted {
    color:var(--gray);
}

@media(max-width:900px) {
    .grid-3 {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .stats {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media(max-width:720px) {
    .app-header {
        min-height:auto;
        padding:12px 16px;
        flex-wrap:wrap;
        gap:8px;
    }

    .nav {
        width:100%;
        margin-left:0;
        overflow-x:auto;
    }

    .container {
        width:min(100% - 20px,1200px);
        padding-top:18px;
    }

    .answer-container {
        width:min(100% - 16px,760px);
        padding-top:12px;
    }

    .page-title {
        flex-direction:column;
    }

    .grid-2,
    .grid-3 {
        grid-template-columns:1fr;
    }

    .stats {
        grid-template-columns:1fr 1fr;
    }

    table {
        display:block;
        overflow-x:auto;
    }
}

@media(max-width:480px) {
    .stats {
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<?php if (!$isAnswerScreen): ?>

<header class="app-header">
    <a class="brand" href="index.php?screen=list">
        アンケートアプリ POC
    </a>

    <nav class="nav">
        <a href="index.php?screen=list">
            アンケート
        </a>

        <a href="index.php?screen=kintone">
            kintone
        </a>

        <a href="index.php?screen=mail">
            メール設定
        </a>
    </nav>
</header>

<?php endif; ?>

<?php if ($flash): ?>
<div class="container">
    <div class="alert alert-<?= h($flash['type']) ?>">
        <?= h($flash['message']) ?>
    </div>
</div>
<?php endif; ?>

<?php

/* ============================================================
 * LIST
 * ============================================================ */

if ($screen === 'list'):

    $surveys = readData('surveys', []);

    $keyword = trim((string)($_GET['q'] ?? ''));
    $filter = $_GET['filter'] ?? 'all';
    $sort = $_GET['sort'] ?? 'updated_desc';

    $surveys = array_map(
        function (array $s): array {
            $s['status'] = surveyStatus($s);
            return $s;
        },
        $surveys
    );

    if ($keyword !== '') {
        $surveys = array_values(
            array_filter(
                $surveys,
                fn(array $s): bool =>
                    mb_stripos(
                        $s['title'] ?? '',
                        $keyword
                    ) !== false
            )
        );
    }

    if ($filter !== 'all') {
        $surveys = array_values(
            array_filter(
                $surveys,
                fn(array $s): bool =>
                    ($s['status'] ?? '') === $filter
            )
        );
    }

    usort(
        $surveys,
        function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        $a['updated_at'] ?? '',
                        $b['updated_at'] ?? ''
                    ),

                'answers_desc' =>
                    answerCount($b['id']) <=>
                    answerCount($a['id']),

                'answers_asc' =>
                    answerCount($a['id']) <=>
                    answerCount($b['id']),

                'start_desc' =>
                    strcmp(
                        $b['start_at'] ?? '',
                        $a['start_at'] ?? ''
                    ),

                'start_asc' =>
                    strcmp(
                        $a['start_at'] ?? '',
                        $b['start_at'] ?? ''
                    ),

                default =>
                    strcmp(
                        $b['updated_at'] ?? '',
                        $a['updated_at'] ?? ''
                    ),
            };
        }
    );
?>

<main class="container">

<div class="page-title">
    <div>
        <h1>アンケート一覧</h1>
        <p class="muted">
            アンケートの作成・公開・集計・送信を管理します。
        </p>
    </div>

    <a class="btn btn-primary"
       href="index.php?screen=edit">
        ＋ 新規作成
    </a>
</div>

<div class="card">

<form class="searchbar"
      method="get"
      action="index.php">

    <input type="hidden"
           name="screen"
           value="list">

    <input type="search"
           name="q"
           value="<?= h($keyword) ?>"
           placeholder="タイトルで検索">

    <select name="filter">
        <option value="all"
            <?= $filter === 'all' ? 'selected' : '' ?>>
            すべて
        </option>

        <option value="published"
            <?= $filter === 'published' ? 'selected' : '' ?>>
            公開中
        </option>

        <option value="draft"
            <?= $filter === 'draft' ? 'selected' : '' ?>>
            下書き
        </option>

        <option value="stopped"
            <?= $filter === 'stopped' ? 'selected' : '' ?>>
            停止
        </option>

        <option value="finished"
            <?= $filter === 'finished' ? 'selected' : '' ?>>
            終了
        </option>
    </select>

    <select name="sort">
        <option value="updated_desc"
            <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
            更新日：新しい順
        </option>

        <option value="updated_asc"
            <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
            更新日：古い順
        </option>

        <option value="answers_desc"
            <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
            回答数：多い順
        </option>

        <option value="answers_asc"
            <?= $sort === 'answers_asc' ? 'selected' : '' ?>>
            回答数：少ない順
        </option>

        <option value="start_desc"
            <?= $sort === 'start_desc' ? 'selected' : '' ?>>
            開始日：新しい順
        </option>

        <option value="start_asc"
            <?= $sort === 'start_asc' ? 'selected' : '' ?>>
            開始日：古い順
        </option>
    </select>

    <button class="btn btn-secondary"
            type="submit">
        検索
    </button>

</form>

</div>

<div class="card">

<?php if (!$surveys): ?>

<div class="empty">
    アンケートがありません。
</div>

<?php else: ?>

<table>
<thead>
<tr>
    <th>タイトル</th>
    <th>作成日</th>
    <th>更新日</th>
    <th>開始</th>
    <th>終了</th>
    <th>状態</th>
    <th>回答数</th>
    <th>操作</th>
</tr>
</thead>

<tbody>

<?php foreach ($surveys as $s): ?>

<tr>

<td>
    <strong><?= h($s['title']) ?></strong>
</td>

<td><?= h($s['created_at']) ?></td>

<td><?= h($s['updated_at']) ?></td>

<td><?= h($s['start_at']) ?></td>

<td><?= h($s['end_at']) ?></td>

<td>
    <span class="badge <?= h(statusClass($s['status'])) ?>">
        <?= h(statusLabel($s['status'])) ?>
    </span>
</td>

<td><?= answerCount($s['id']) ?></td>

<td>

<div class="actions">

<a class="btn btn-small btn-secondary"
   href="index.php?screen=edit&id=<?= h($s['id']) ?>">
    編集
</a>

<a class="btn btn-small btn-secondary"
   href="index.php?screen=preview&id=<?= h($s['id']) ?>">
    プレビュー
</a>

<a class="btn btn-small btn-secondary"
   href="index.php?screen=analytics&id=<?= h($s['id']) ?>">
    集計
</a>

<a class="btn btn-small btn-secondary"
   href="index.php?screen=send&id=<?= h($s['id']) ?>">
    送信
</a>

<form method="post">
    <input type="hidden"
           name="action"
           value="duplicate_survey">

    <input type="hidden"
           name="id"
           value="<?= h($s['id']) ?>">

    <button class="btn btn-small btn-secondary">
        複製
    </button>
</form>

<form method="post"
      onsubmit="return confirm('削除しますか？')">

    <input type="hidden"
           name="action"
           value="delete_survey">

    <input type="hidden"
           name="id"
           value="<?= h($s['id']) ?>">

    <button class="btn btn-small btn-danger">
        削除
    </button>
</form>

</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

<?php endif; ?>

</div>

</main>

<?php

/* ============================================================
 * EDIT
 * ============================================================ */

elseif ($screen === 'edit'):

    if (!$survey) {
        $survey = newSurvey();
    }

    normalizeQuestions($survey);

?>

<main class="container">

<div class="page-title">

<div>
    <h1>アンケート作成・編集</h1>
    <p class="muted">
        質問・グループを編集できます。
    </p>
</div>

<div class="actions">

<a class="btn btn-secondary"
   href="index.php?screen=list">
    キャンセル
</a>

<button form="survey-form"
        class="btn btn-primary">
    保存して一覧へ
</button>

</div>

</div>

<div class="card">

<div class="actions">

<?php if ($survey['id']): ?>

<span>
    状態:
    <strong>
        <?= h(statusLabel(surveyStatus($survey))) ?>
    </strong>
</span>

<?php
$currentStatus = surveyStatus($survey);
?>

<?php if ($currentStatus === 'draft'): ?>

<form method="post">
    <input type="hidden"
           name="action"
           value="change_status">

    <input type="hidden"
           name="id"
           value="<?= h($survey['id']) ?>">

    <input type="hidden"
           name="status"
           value="published">

    <button class="btn btn-success"
            onclick="return confirm('公開しますか？')">
        公開
    </button>
</form>

<?php elseif ($currentStatus === 'published'): ?>

<form method="post">
    <input type="hidden"
           name="action"
           value="change_status">

    <input type="hidden"
           name="id"
           value="<?= h($survey['id']) ?>">

    <input type="hidden"
           name="status"
           value="stopped">

    <button class="btn btn-warning"
            onclick="return confirm('停止しますか？')">
        停止
    </button>
</form>

<?php elseif ($currentStatus === 'stopped'): ?>

<form method="post">
    <input type="hidden"
           name="action"
           value="change_status">

    <input type="hidden"
           name="id"
           value="<?= h($survey['id']) ?>">

    <input type="hidden"
           name="status"
           value="published">

    <button class="btn btn-success"
            onclick="return confirm('再公開しますか？')">
        再公開
    </button>
</form>

<?php endif; ?>

<?php endif; ?>

</div>

</div>

<form id="survey-form"
      method="post"
      onsubmit="return prepareSurvey()">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<input type="hidden"
       name="groups_json"
       id="groups-json">

<div class="card">

<div class="grid-2">

<div class="form-group">

<label class="form-label">
    タイトル
</label>

<input type="text"
       name="title"
       required
       maxlength="200"
       value="<?= h($survey['title']) ?>">

</div>

<div class="form-group">

<label class="form-label">
    質問番号
</label>

<select name="numbering"
        id="numbering">

<option value="global"
    <?= $survey['numbering'] === 'global'
        ? 'selected'
        : '' ?>>
    アンケート全体で通番
</option>

<option value="group"
    <?= $survey['numbering'] === 'group'
        ? 'selected'
        : '' ?>>
    グループ単位
</option>

</select>

</div>

</div>

<div class="form-group">

<label class="form-label">
    説明
</label>

<textarea name="description"><?= h(
    $survey['description']
) ?></textarea>

</div>

<div class="grid-2">

<div class="form-group">

<label class="form-label">
    開始日時
</label>

<input type="datetime-local"
       name="start_at"
       value="<?= h($survey['start_at']) ?>">

</div>

<div class="form-group">

<label class="form-label">
    終了日時
</label>

<input type="datetime-local"
       name="end_at"
       value="<?= h($survey['end_at']) ?>">

</div>

</div>

</div>

<div id="groups"></div>

<div class="actions">

<button type="button"
        class="btn btn-secondary"
        onclick="addGroup()">
    ＋ グループを追加
</button>

</div>

</form>

</main>

<script>
let groups = <?= json_encode(
    $survey['groups'],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

function uid(prefix) {
    return prefix + Math.random()
        .toString(36)
        .substring(2) +
        Date.now().toString(36);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function render() {

    const root = document.getElementById('groups');

    root.innerHTML = '';

    groups.forEach((group, gi) => {

        const groupEl = document.createElement('div');

        groupEl.className = 'group-card';
        groupEl.draggable = true;
        groupEl.dataset.index = gi;

        groupEl.innerHTML = `
            <div class="group-header">

                <span class="drag-handle">☷</span>

                <input
                    class="group-title"
                    value="${escapeHtml(group.title)}"
                    oninput="groups[${gi}].title=this.value"
                >

                <button
                    type="button"
                    class="btn btn-small btn-danger"
                    onclick="removeGroup(${gi})">
                    削除
                </button>

            </div>

            <div class="group-body">

                <div class="questions"
                     data-group="${gi}">
                </div>

                <button
                    type="button"
                    class="btn btn-small btn-secondary"
                    onclick="addQuestion(${gi})">
                    ＋ 質問を追加
                </button>

            </div>
        `;

        groupEl.addEventListener('dragstart', e => {
            e.dataTransfer.setData(
                'text/group',
                String(gi)
            );

            groupEl.classList.add('dragging');
        });

        groupEl.addEventListener('dragend', () => {
            groupEl.classList.remove('dragging');
        });

        groupEl.addEventListener('dragover', e => {
            e.preventDefault();
            groupEl.classList.add('drag-over');
        });

        groupEl.addEventListener('dragleave', () => {
            groupEl.classList.remove('drag-over');
        });

        groupEl.addEventListener('drop', e => {

            e.preventDefault();

            groupEl.classList.remove('drag-over');

            const from = Number(
                e.dataTransfer.getData('text/group')
            );

            if (!Number.isInteger(from) || from === gi) {
                return;
            }

            const item = groups.splice(from, 1)[0];

            groups.splice(gi, 0, item);

            render();
        });

        root.appendChild(groupEl);

        const questions =
            groupEl.querySelector('.questions');

        group.questions.forEach((question, qi) => {

            const q = document.createElement('div');

            q.className = 'question-card';
            q.draggable = true;
            q.dataset.group = gi;
            q.dataset.question = qi;

            const choices =
                question.choices ?? [];

            let choicesHtml = '';

            if (
                question.type === 'single' ||
                question.type === 'multiple'
            ) {

                choicesHtml = `
                    <div class="form-group">
                        <label class="form-label">
                            選択肢
                        </label>

                        <div id="choices-${gi}-${qi}">
                            ${choices.map((c, ci) => `
                                <div class="option-row">
                                    <input
                                        value="${escapeHtml(c.text)}"
                                        oninput="
                                            groups[${gi}]
                                            .questions[${qi}]
                                            .choices[${ci}]
                                            .text=this.value
                                        "
                                    >

                                    <button
                                        type="button"
                                        class="btn btn-small btn-danger"
                                        onclick="
                                            removeChoice(
                                                ${gi},
                                                ${qi},
                                                ${ci}
                                            )
                                        ">
                                        ×
                                    </button>
                                </div>
                            `).join('')}
                        </div>

                        <button
                            type="button"
                            class="btn btn-small btn-secondary"
                            onclick="
                                addChoice(${gi},${qi})
                            ">
                            ＋ 選択肢
                        </button>
                    </div>
                `;
            }

            q.innerHTML = `
                <div class="actions"
                     style="margin-bottom:12px">

                    <span class="drag-handle">☷</span>

                    <strong>
                        ${escapeHtml(question.number)}
                    </strong>

                    <button
                        type="button"
                        class="btn btn-small btn-danger"
                        onclick="
                            removeQuestion(${gi},${qi})
                        ">
                        削除
                    </button>

                </div>

                <div class="form-group">

                    <label class="form-label">
                        質問文
                    </label>

                    <input
                        type="text"
                        value="${escapeHtml(question.text)}"
                        oninput="
                            groups[${gi}]
                            .questions[${qi}]
                            .text=this.value
                        "
                    >

                </div>

                <div class="grid-2">

                    <div class="form-group">

                        <label class="form-label">
                            回答形式
                        </label>

                        <select
                            onchange="
                                changeType(
                                    ${gi},
                                    ${qi},
                                    this.value
                                )
                            ">

                            <option value="single"
                                ${question.type === 'single'
                                    ? 'selected'
                                    : ''}>
                                単一選択
                            </option>

                            <option value="multiple"
                                ${question.type === 'multiple'
                                    ? 'selected'
                                    : ''}>
                                複数選択
                            </option>

                            <option value="text"
                                ${question.type === 'text'
                                    ? 'selected'
                                    : ''}>
                                自由記述
                            </option>

                        </select>

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            必須
                        </label>

                        <label>
                            <input
                                type="checkbox"
                                ${question.required
                                    ? 'checked'
                                    : ''}
                                onchange="
                                    groups[${gi}]
                                    .questions[${qi}]
                                    .required=this.checked
                                ">
                            必須回答
                        </label>

                    </div>

                </div>

                ${choicesHtml}
            `;

            q.addEventListener('dragstart', e => {

                e.stopPropagation();

                e.dataTransfer.effectAllowed = 'move';

                e.dataTransfer.setData(
                    'text/question',
                    JSON.stringify({
                        group: gi,
                        question: qi
                    })
                );

                q.classList.add('dragging');
            });

            q.addEventListener('dragend', () => {
                q.classList.remove('dragging');
            });

            q.addEventListener('dragover', e => {
                e.preventDefault();
                e.stopPropagation();
                q.classList.add('drag-over');
            });

            q.addEventListener('dragleave', e => {
                q.classList.remove('drag-over');
            });

            q.addEventListener('drop', e => {

                e.preventDefault();
                e.stopPropagation();

                q.classList.remove('drag-over');

                const raw =
                    e.dataTransfer.getData(
                        'text/question'
                    );

                if (!raw) {
                    return;
                }

                const source = JSON.parse(raw);

                moveQuestion(
                    source.group,
                    source.question,
                    gi,
                    qi
                );
            });

            questions.appendChild(q);
        });
    });

    renumber();
}

function renumber() {

    const mode =
        document.getElementById('numbering').value;

    let n = 0;

    groups.forEach((group, gi) => {

        group.questions.forEach(
            (question, qi) => {

                if (mode === 'group') {
                    question.number =
                        `Q${gi + 1}-${qi + 1}`;
                } else {
                    n++;
                    question.number = `Q${n}`;
                }
            }
        );
    });

    document
        .querySelectorAll('.group-card')
        .forEach((groupEl, gi) => {

            groupEl
                .querySelectorAll('.question-card')
                .forEach((q, qi) => {

                    const number =
                        groups[gi]
                            .questions[qi]
                            .number;

                    const strong =
                        q.querySelector('strong');

                    if (strong) {
                        strong.textContent = number;
                    }
                });
        });
}

document
    .getElementById('numbering')
    .addEventListener('change', () => {
        renumber();
    });

function addGroup() {

    groups.push({
        id: uid('g_'),
        title: '新しいグループ',
        questions: [
            {
                id: uid('q_'),
                text: '新しい質問',
                type: 'single',
                required: false,
                choices: [
                    {
                        id: uid('c_'),
                        text: '選択肢1'
                    },
                    {
                        id: uid('c_'),
                        text: '選択肢2'
                    }
                ],
                conditions: [],
                number: ''
            }
        ]
    });

    render();
}

function removeGroup(index) {

    if (groups.length <= 1) {
        alert('最低1グループ必要です。');
        return;
    }

    if (!confirm('グループを削除しますか？')) {
        return;
    }

    groups.splice(index, 1);

    render();
}

function addQuestion(groupIndex) {

    groups[groupIndex].questions.push({
        id: uid('q_'),
        text: '新しい質問',
        type: 'single',
        required: false,
        choices: [
            {
                id: uid('c_'),
                text: '選択肢1'
            },
            {
                id: uid('c_'),
                text: '選択肢2'
            }
        ],
        conditions: [],
        number: ''
    });

    render();
}

function removeQuestion(groupIndex, questionIndex) {

    groups[groupIndex]
        .questions
        .splice(questionIndex, 1);

    render();
}

function changeType(groupIndex, questionIndex, type) {

    groups[groupIndex]
        .questions[questionIndex]
        .type = type;

    if (
        type === 'single' ||
        type === 'multiple'
    ) {

        if (
            !Array.isArray(
                groups[groupIndex]
                    .questions[questionIndex]
                    .choices
            )
        ) {
            groups[groupIndex]
                .questions[questionIndex]
                .choices = [
                    {
                        id: uid('c_'),
                        text: '選択肢1'
                    },
                    {
                        id: uid('c_'),
                        text: '選択肢2'
                    }
                ];
        }
    }

    render();
}

function addChoice(groupIndex, questionIndex) {

    groups[groupIndex]
        .questions[questionIndex]
        .choices.push({
            id: uid('c_'),
            text: '新しい選択肢'
        });

    render();
}

function removeChoice(
    groupIndex,
    questionIndex,
    choiceIndex
) {

    groups[groupIndex]
        .questions[questionIndex]
        .choices
        .splice(choiceIndex, 1);

    render();
}

function moveQuestion(
    sourceGroup,
    sourceQuestion,
    targetGroup,
    targetQuestion
) {

    const source =
        groups[sourceGroup];

    const target =
        groups[targetGroup];

    const question =
        source.questions.splice(
            sourceQuestion,
            1
        )[0];

    if (
        sourceGroup === targetGroup &&
        sourceQuestion < targetQuestion
    ) {
        targetQuestion--;
    }

    target.questions.splice(
        targetQuestion,
        0,
        question
    );

    render();
}

function prepareSurvey() {

    renumber();

    document.getElementById(
        'groups-json'
    ).value = JSON.stringify(groups);

    return true;
}

render();
</script>

<?php

/* ============================================================
 * PREVIEW
 * ============================================================ */

elseif ($screen === 'preview' && $survey):

?>

<main class="answer-container">

<div class="page-title">
    <div>
        <h1>プレビュー</h1>
        <p class="muted">
            実際の回答画面を確認します。
        </p>
    </div>

    <a class="btn btn-secondary"
       href="index.php?screen=edit&id=<?= h($survey['id']) ?>">
        編集へ戻る
    </a>
</div>

<div class="answer-card">

<h2><?= h($survey['title']) ?></h2>

<p><?= nl2br(h($survey['description'])) ?></p>

</div>

<?php foreach ($survey['groups'] as $group): ?>

<div class="answer-card">

<h3><?= h($group['title']) ?></h3>

<?php foreach ($group['questions'] as $q): ?>

<div style="margin-bottom:25px">

<div class="question-title">
    <?= h($q['number']) ?>.
    <?= h($q['text']) ?>

    <?php if ($q['required']): ?>
        <span class="required">必須</span>
    <?php endif; ?>
</div>

<?php if ($q['type'] === 'text'): ?>

<textarea placeholder="回答を入力"></textarea>

<?php else: ?>

<?php foreach ($q['choices'] as $choice): ?>

<label style="display:block;margin:8px 0">

<input
    type="<?= $q['type'] === 'multiple'
        ? 'checkbox'
        : 'radio' ?>">

<?= h($choice['text']) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</main>

<?php

/* ============================================================
 * ANSWER
 * ============================================================ */

elseif ($screen === 'answer' && $survey):

$status = surveyStatus($survey);

if ($status !== 'published'):

?>

<main class="answer-container">

<div class="answer-card">

<h1>回答できません</h1>

<p>
このアンケートは現在回答受付中ではありません。
</p>

</div>

</main>

<?php else: ?>

<main class="answer-container">

<div class="page-title">

<div>
    <h1><?= h($survey['title']) ?></h1>
</div>

</div>

<?php if ($survey['description'] !== ''): ?>

<div class="answer-card">
    <?= nl2br(h($survey['description'])) ?>
</div>

<?php endif; ?>

<form method="post">

<input type="hidden"
       name="action"
       value="confirm_answer">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<input type="hidden"
       name="customer_id"
       value="<?= h($_GET['customer'] ?? '') ?>">

<?php foreach ($survey['groups'] as $group): ?>

<div class="answer-card">

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $q): ?>

<div class="form-group">

<label class="form-label">

<?= h($q['number']) ?>.
<?= h($q['text']) ?>

<?php if ($q['required']): ?>
<span class="required">必須</span>
<?php endif; ?>

</label>

<?php if ($q['type'] === 'text'): ?>

<textarea
    name="<?= h($q['id']) ?>"
    <?= $q['required'] ? 'required' : '' ?>
></textarea>

<?php else: ?>

<?php foreach ($q['choices'] as $choice): ?>

<label style="display:block;margin:8px 0">

<input
    type="<?= $q['type'] === 'multiple'
        ? 'checkbox'
        : 'radio' ?>"
    name="<?= h($q['id']) ?><?= $q['type'] === 'multiple'
        ? '[]'
        : '' ?>"
    value="<?= h($choice['id']) ?>"
    <?= $q['required'] ? 'required' : '' ?>>

<?= h($choice['text']) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="actions">

<button class="btn btn-primary">
    回答を確認
</button>

</div>

</form>

</main>

<?php endif; ?>

<?php

/* ============================================================
 * CONFIRM
 * ============================================================ */

elseif ($screen === 'confirm' && $survey):

$draft = $_SESSION['answer_draft'] ?? null;

?>

<main class="answer-container">

<div class="page-title">

<div>
    <h1>回答確認</h1>
    <p class="muted">
        回答内容を確認してください。
    </p>
</div>

</div>

<?php if (!$draft): ?>

<div class="answer-card">

<div class="empty">
    回答データがありません。
</div>

</div>

<?php else: ?>

<?php foreach ($survey['groups'] as $group): ?>

<div class="answer-card">

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $q): ?>

<?php
$value =
    $draft['answers'][$q['id']] ?? '';

if (is_array($value)) {
    $texts = [];

    foreach ($q['choices'] as $choice) {
        if (in_array(
            $choice['id'],
            $value,
            true
        )) {
            $texts[] = $choice['text'];
        }
    }

    $value = implode(', ', $texts);
} elseif (
    $q['type'] !== 'text'
) {
    foreach ($q['choices'] as $choice) {
        if ($choice['id'] === $value) {
            $value = $choice['text'];
            break;
        }
    }
}
?>

<div class="form-group">

<div class="form-label">
    <?= h($q['number']) ?>.
    <?= h($q['text']) ?>
</div>

<div>
    <?= nl2br(h($value)) ?>
</div>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="actions">

<a class="btn btn-secondary"
   href="index.php?screen=answer&id=<?= h($survey['id']) ?>">
    修正する
</a>

<form method="post">

<input type="hidden"
       name="action"
       value="complete_answer">

<button class="btn btn-primary">
    回答を送信
</button>

</form>

</div>

<?php endif; ?>

</main>

<?php

/* ============================================================
 * COMPLETE
 * ============================================================ */

elseif ($screen === 'complete'):

?>

<main class="answer-container">

<div class="answer-card">

<h1>回答完了</h1>

<p>
回答を受け付けました。
ご回答ありがとうございました。
</p>

</div>

</main>

<?php

/* ============================================================
 * ANALYTICS
 * ============================================================ */

elseif ($screen === 'analytics' && $survey):

$answers = array_values(
    array_filter(
        readData('answers', []),
        fn(array $a): bool =>
            ($a['survey_id'] ?? '') === $survey['id']
    )
);

$total = count($answers);

$customers = readData('customers', []);

$mailLogs = array_values(
    array_filter(
        readData('mail_logs', []),
        fn(array $l): bool =>
            ($l['survey_id'] ?? '') === $survey['id']
    )
);

$sentCustomerIds = [];

foreach ($mailLogs as $log) {
    if (($log['status'] ?? '') === 'sent') {
        $sentCustomerIds[] =
            $log['customer_id'] ?? '';
    }
}

$sentCount = count(
    array_unique($sentCustomerIds)
);

$unregistered = 0;

foreach ($answers as $answer) {

    $customerId =
        $answer['customer_id'] ?? '';

    if (
        $customerId === '' ||
        !array_filter(
            $customers,
            fn(array $c): bool =>
                ($c['id'] ?? '') === $customerId
        )
    ) {
        $unregistered++;
    }
}

$unanswered =
    max(0, $sentCount - $total);

$rate =
    $sentCount > 0
        ? round($total / $sentCount * 100, 1)
        : 0;

?>

<main class="container">

<div class="page-title">

<div>
    <h1>回答集計・分析</h1>
    <p>
        対象:
        <strong><?= h($survey['title']) ?></strong>
    </p>
</div>

<div class="actions">

<a class="btn btn-secondary"
   href="index.php?screen=csv&id=<?= h($survey['id']) ?>">
    CSV
</a>

<a class="btn btn-secondary"
   href="index.php?screen=pdf&id=<?= h($survey['id']) ?>">
    PDF
</a>

</div>

</div>

<div class="stats">

<div class="stat">
    <div class="stat-label">送信対象者数</div>
    <div class="stat-value"><?= $sentCount ?></div>
</div>

<div class="stat">
    <div class="stat-label">回答数</div>
    <div class="stat-value"><?= $total ?></div>
</div>

<div class="stat">
    <div class="stat-label">未登録回答</div>
    <div class="stat-value"><?= $unregistered ?></div>
</div>

<div class="stat">
    <div class="stat-label">回答率</div>
    <div class="stat-value"><?= h($rate) ?>%</div>
</div>

</div>

<?php if ($total === 0): ?>

<div class="card">
    <div class="empty">
        現在、回答データはありません
    </div>
</div>

<?php else: ?>

<?php foreach ($survey['groups'] as $group): ?>

<div class="card">

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $q): ?>

<?php
$counts = [];

foreach ($q['choices'] ?? [] as $choice) {
    $counts[$choice['id']] = 0;
}

foreach ($answers as $answer) {

    $value =
        $answer['answers'][$q['id']] ?? '';

    if (is_array($value)) {
        foreach ($value as $v) {
            if (isset($counts[$v])) {
                $counts[$v]++;
            }
        }
    } elseif (isset($counts[$value])) {
        $counts[$value]++;
    }
}
?>

<div class="form-group">

<strong>
    <?= h($q['number']) ?>.
    <?= h($q['text']) ?>
</strong>

<?php if ($q['type'] === 'text'): ?>

<table>
<tbody>

<?php foreach ($answers as $answer): ?>

<tr>
<td>
<?= nl2br(
    h(
        $answer['answers'][$q['id']]
        ?? ''
    )
) ?>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

<?php else: ?>

<table>

<thead>
<tr>
    <th>選択肢</th>
    <th>回答数</th>
</tr>
</thead>

<tbody>

<?php foreach ($q['choices'] as $choice): ?>

<tr>
<td><?= h($choice['text']) ?></td>
<td><?= $counts[$choice['id']] ?? 0 ?></td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>

</main>

<?php

/* ============================================================
 * SEND
 * ============================================================ */

elseif ($screen === 'send' && $survey):

$customers = readData('customers', []);

$logs = array_values(
    array_filter(
        readData('mail_logs', []),
        fn(array $l): bool =>
            ($l['survey_id'] ?? '') === $survey['id']
    )
);

?>

<main class="container">

<div class="page-title">

<div>
    <h1>顧客選択・メール送信</h1>

    <p>
        対象アンケート:
        <strong><?= h($survey['title']) ?></strong>
    </p>
</div>

</div>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="form-group">

<label class="form-label">
    件名
</label>

<input type="text"
       name="subject"
       required
       value="<?= h(
           'アンケートのお願い：' .
           $survey['title']
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
    本文
</label>

<textarea name="body"
          required><?= h(
' {顧客名} 様

アンケートへのご協力をお願いいたします。

以下のURLからご回答ください。

{アンケートURL}

よろしくお願いいたします。'
) ?></textarea>

<p class="muted">
    使用可能な変数:
    {顧客名} / {アンケートURL}
</p>

</div>

<div class="form-group">

<label class="form-label">
    送信種別
</label>

<select name="send_type">

<option value="send">
    新規送信
</option>

<option value="resend">
    再送
</option>

<option value="reminder">
    リマインド
</option>

</select>

</div>

<hr>

<h2>顧客選択</h2>

<?php if (!$customers): ?>

<div class="empty">
    顧客データがありません。
    kintone設定画面から同期してください。
</div>

<?php else: ?>

<table>

<thead>
<tr>
<th></th>
<th>組織名</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
</tr>
</thead>

<tbody>

<?php foreach ($customers as $customer): ?>

<tr>

<td>
<input
    type="checkbox"
    name="customers[]"
    value="<?= h($customer['id']) ?>">
</td>

<td><?= h($customer['organization']) ?></td>
<td><?= h($customer['name']) ?></td>
<td><?= h($customer['email']) ?></td>
<td><?= h($customer['department']) ?></td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<br>

<button class="btn btn-primary"
        onclick="
            return confirm(
                '選択した顧客へメールを送信しますか？'
            )
        ">
    メール送信
</button>

<?php endif; ?>

</form>

</div>

<div class="card">

<h2>送信履歴</h2>

<?php if (!$logs): ?>

<div class="empty">
    送信履歴はありません。
</div>

<?php else: ?>

<table>

<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>メール</th>
<th>種別</th>
<th>結果</th>
</tr>
</thead>

<tbody>

<?php foreach ($logs as $log): ?>

<tr>

<td><?= h($log['created_at']) ?></td>

<td><?= h($log['customer_name']) ?></td>

<td><?= h($log['email']) ?></td>

<td><?= h($log['type']) ?></td>

<td>
<?php if (($log['status'] ?? '') === 'sent'): ?>

<span class="badge badge-success">
    送信成功
</span>

<?php else: ?>

<span class="badge badge-danger">
    送信失敗
</span>

<?php endif; ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php endif; ?>

</div>

</main>

<?php

/* ============================================================
 * KINTONE
 * ============================================================ */

elseif ($screen === 'kintone'):

$config = readData('kintone', []);

?>

<main class="container">

<div class="page-title">

<div>
    <h1>kintone連携設定</h1>
    <p class="muted">
        顧客情報の取得元を設定します。
    </p>
</div>

</div>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid-2">

<div class="form-group">

<label class="form-label">
    サブドメイン
</label>

<input type="text"
       name="subdomain"
       value="<?= h(
           $config['subdomain'] ?? ''
       ) ?>"
       placeholder="example.cybozu.com">

</div>

<div class="form-group">

<label class="form-label">
    顧客管理アプリID
</label>

<input type="number"
       name="app_id"
       value="<?= h(
           $config['app_id'] ?? ''
       ) ?>">

</div>

</div>

<div class="grid-2">

<div class="form-group">

<label class="form-label">
    ログイン名
</label>

<input type="text"
       name="login"
       value="<?= h(
           $config['login'] ?? ''
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
    パスワード
</label>

<input type="password"
       name="password"
       value="<?= h(
           $config['password'] ?? ''
       ) ?>">

</div>

</div>

<div class="form-group">

<label class="form-label">
    Proxy
</label>

<input type="text"
       name="proxy"
       value="<?= h(
           $config['proxy'] ?? ''
       ) ?>">

</div>

<div class="form-group">

<label>
<input type="checkbox"
       name="verify_ssl"
       <?= ($config['verify_ssl'] ?? true)
           ? 'checked'
           : '' ?>>
SSL証明書を検証する
</label>

</div>

<h2>顧客項目マッピング</h2>

<?php
$mapping = $config['mapping'] ?? [];
?>

<div class="grid-2">

<?php
$mappingFields = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
    'address' => '住所',
];
?>

<?php foreach ($mappingFields as $key => $label): ?>

<div class="form-group">

<label class="form-label">
    <?= h($label) ?>
</label>

<input type="text"
       name="mapping[<?= h($key) ?>]"
       value="<?= h(
           $mapping[$key] ?? ''
       ) ?>">

</div>

<?php endforeach; ?>

</div>

<div class="actions">

<button class="btn btn-primary">
    設定保存
</button>

</div>

</form>

<hr>

<div class="actions">

<form method="post">
    <input type="hidden"
           name="action"
           value="test_kintone">

    <button class="btn btn-secondary">
        接続テスト
    </button>
</form>

<form method="post">
    <input type="hidden"
           name="action"
           value="sync_kintone">

    <button class="btn btn-primary">
        顧客情報同期
    </button>
</form>

</div>

</div>

</main>

<?php

/* ============================================================
 * MAIL
 * ============================================================ */

elseif ($screen === 'mail'):

$config = readData('mail', []);

?>

<main class="container">

<div class="page-title">

<div>
    <h1>メールサーバ設定</h1>

    <p class="muted">
        SMTPサーバとの接続設定です。
    </p>
</div>

</div>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid-2">

<div class="form-group">

<label class="form-label">
    SMTPサーバ
</label>

<input type="text"
       name="host"
       value="<?= h(
           $config['host'] ?? ''
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
    SMTPポート
</label>

<input type="number"
       name="port"
       value="<?= h(
           $config['port'] ?? 587
       ) ?>">

</div>

</div>

<div class="grid-2">

<div class="form-group">

<label class="form-label">
    暗号化方式
</label>

<select name="encryption">

<option value="tls"
    <?= ($config['encryption'] ?? 'tls') === 'tls'
        ? 'selected'
        : '' ?>>
    STARTTLS
</option>

<option value="ssl"
    <?= ($config['encryption'] ?? '') === 'ssl'
        ? 'selected'
        : '' ?>>
    SSL/TLS
</option>

<option value="none"
    <?= ($config['encryption'] ?? '') === 'none'
        ? 'selected'
        : '' ?>>
    なし
</option>

</select>

</div>

<div class="form-group">

<label class="form-label">
    SMTPユーザー名
</label>

<input type="text"
       name="username"
       value="<?= h(
           $config['username'] ?? ''
       ) ?>">

</div>

</div>

<div class="form-group">

<label class="form-label">
    SMTPパスワード
</label>

<input type="password"
       name="password"
       value="<?= h(
           $config['password'] ?? ''
       ) ?>">

</div>

<div class="grid-2">

<div class="form-group">

<label class="form-label">
    送信元メールアドレス
</label>

<input type="email"
       name="from_email"
       value="<?= h(
           $config['from_email'] ?? ''
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
    送信元名
</label>

<input type="text"
       name="from_name"
       value="<?= h(
           $config['from_name'] ?? ''
       ) ?>">

</div>

</div>

<div class="form-group">

<label class="form-label">
    返信先メールアドレス
</label>

<input type="email"
       name="reply_to"
       value="<?= h(
           $config['reply_to'] ?? ''
       ) ?>">

</div>

<button class="btn btn-primary">
    設定保存
</button>

</form>

<hr>

<h2>SMTP接続確認・テスト送信</h2>

<form method="post">

<input type="hidden"
       name="action"
       value="test_mail">

<div class="grid-2">

<div class="form-group">

<label class="form-label">
    テスト送信先
</label>

<input type="email"
       name="test_to"
       required>

</div>

<div style="display:flex;align-items:end">

<button class="btn btn-secondary">
    テストメール送信
</button>

</div>

</div>

</form>

</div>

</main>

<?php

/* ============================================================
 * UNKNOWN
 * ============================================================ */

else:

?>

<main class="container">

<div class="card">

<h1>ページが見つかりません</h1>

<a class="btn btn-primary"
   href="index.php?screen=list">
    アンケート一覧へ
</a>

</div>

</main>

<?php endif; ?>

</body>
</html>
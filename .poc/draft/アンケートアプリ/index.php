<?php
declare(strict_types=1);

/*
 * アンケートアプリ POC
 * PHP 8.5 / Apache 2.4 / DBなし / cURLなし
 * 単一ファイル版 index.php
 */

session_start();
date_default_timezone_set('Asia/Tokyo');

const APP_NAME = 'アンケート管理システム';
const DATA_DIR = __DIR__ . '/data';

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}

foreach ([
    'surveys.json',
    'responses.json',
    'customers.json',
    'mail_logs.json',
    'kintone.json',
    'smtp.json'
] as $file) {
    $path = DATA_DIR . '/' . $file;
    if (!file_exists($path)) {
        @file_put_contents($path, "[]", LOCK_EX);
    }
}

/* =========================================================
 * Utility
 * ========================================================= */

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uid(string $prefix): string
{
    return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
}

function jsonRead(string $name, mixed $default = []): mixed
{
    $path = DATA_DIR . '/' . $name . '.json';

    if (!file_exists($path)) {
        return $default;
    }

    $fp = @fopen($path, 'rb');
    if (!$fp) {
        return $default;
    }

    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);
    return $data === null && json_last_error() !== JSON_ERROR_NONE
        ? $default
        : $data;
}

function jsonWrite(string $name, mixed $data): bool
{
    $path = DATA_DIR . '/' . $name . '.json';

    $fp = @fopen($path, 'c+');
    if (!$fp) {
        return false;
    }

    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    $ok = fwrite($fp, $json) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $ok;
}

function findById(array $items, string $id): ?array
{
    foreach ($items as $item) {
        if (($item['id'] ?? '') === $id) {
            return $item;
        }
    }
    return null;
}

function saveById(string $name, array $item): void
{
    $items = jsonRead($name, []);
    $found = false;

    foreach ($items as $i => $old) {
        if (($old['id'] ?? '') === ($item['id'] ?? '')) {
            $items[$i] = $item;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $items[] = $item;
    }

    jsonWrite($name, $items);
}

function deleteById(string $name, string $id): void
{
    $items = jsonRead($name, []);
    $items = array_values(array_filter(
        $items,
        fn($item) => ($item['id'] ?? '') !== $id
    ));
    jsonWrite($name, $items);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrfFree(): void
{
    /* POC要件によりCSRF対策は実装しない */
}

/* =========================================================
 * Survey
 * ========================================================= */

function emptySurvey(): array
{
    return [
        'id' => uid('survey'),
        'title' => '',
        'description' => '',
        'start_at' => '',
        'end_at' => '',
        'numbering' => 'global',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
        'groups' => [
            [
                'id' => uid('group'),
                'title' => 'グループ1',
                'questions' => []
            ]
        ]
    ];
}

function normalizeSurvey(array $s): array
{
    $s['groups'] ??= [];

    foreach ($s['groups'] as $gi => &$g) {
        $g['id'] ??= uid('group');
        $g['title'] = trim((string)($g['title'] ?? ''));
        $g['questions'] ??= [];

        foreach ($g['questions'] as $qi => &$q) {
            $q['id'] ??= uid('question');
            $q['text'] = trim((string)($q['text'] ?? ''));
            $q['type'] ??= 'single';
            $q['required'] = !empty($q['required']);
            $q['options'] ??= [];
            $q['conditions'] ??= [];

            foreach ($q['options'] as &$o) {
                if (is_string($o)) {
                    $o = [
                        'id' => uid('option'),
                        'label' => $o
                    ];
                } else {
                    $o['id'] ??= uid('option');
                    $o['label'] = (string)($o['label'] ?? '');
                }
            }
            unset($o);
        }
        unset($q);
    }
    unset($g);

    return recalcNumbers($s);
}

function recalcNumbers(array $s): array
{
    $global = 1;

    foreach ($s['groups'] as $gi => &$g) {
        $local = 1;

        foreach ($g['questions'] as &$q) {
            if (($s['numbering'] ?? 'global') === 'group') {
                $q['number'] = 'Q' . ($gi + 1) . '-' . $local;
            } else {
                $q['number'] = 'Q' . $global;
            }

            $global++;
            $local++;
        }
        unset($q);
    }
    unset($g);

    return $s;
}

function surveyStatus(array $s): string
{
    $status = $s['status'] ?? 'draft';

    if (
        $status === 'published' &&
        !empty($s['end_at'])
    ) {
        $end = strtotime((string)$s['end_at']);
        if ($end !== false && $end < time()) {
            return 'ended';
        }
    }

    return $status;
}

function statusLabel(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'draft' => '下書き',
        'stopped' => '停止',
        'ended' => '終了',
        default => $status
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        'published' => 'badge-success',
        'draft' => 'badge-draft',
        'stopped' => 'badge-warning',
        'ended' => 'badge-danger',
        default => 'badge-gray'
    };
}

function surveyResponses(string $surveyId): array
{
    return array_values(array_filter(
        jsonRead('responses', []),
        fn($r) => ($r['survey_id'] ?? '') === $surveyId
    ));
}

function visibleQuestions(array $survey, array $answers): array
{
    $questions = [];

    foreach ($survey['groups'] as $g) {
        foreach ($g['questions'] as $q) {
            $visible = true;

            foreach (($q['conditions'] ?? []) as $condition) {
                $source = $condition['question_id'] ?? '';
                $expected = $condition['option_id'] ?? '';

                if ($source === '') {
                    continue;
                }

                $answer = $answers[$source] ?? null;

                if (is_array($answer)) {
                    if (!in_array($expected, $answer, true)) {
                        $visible = false;
                    }
                } elseif ((string)$answer !== (string)$expected) {
                    $visible = false;
                }
            }

            if ($visible) {
                $questions[] = $q;
            }
        }
    }

    return $questions;
}

/* =========================================================
 * Validation
 * ========================================================= */

function validateSurvey(array $s): array
{
    $errors = [];

    $title = trim((string)($s['title'] ?? ''));

    if ($title === '') {
        $errors[] = 'タイトルは必須です。';
    }

    if (mb_strlen($title) > 200) {
        $errors[] = 'タイトルは200文字以内で入力してください。';
    }

    if (!empty($s['start_at']) && strtotime((string)$s['start_at']) === false) {
        $errors[] = '開始日時が不正です。';
    }

    if (!empty($s['end_at']) && strtotime((string)$s['end_at']) === false) {
        $errors[] = '終了日時が不正です。';
    }

    if (
        !empty($s['start_at']) &&
        !empty($s['end_at']) &&
        strtotime((string)$s['start_at']) > strtotime((string)$s['end_at'])
    ) {
        $errors[] = '終了日時は開始日時以降にしてください。';
    }

    foreach ($s['groups'] as $g) {
        if (trim((string)($g['title'] ?? '')) === '') {
            $errors[] = 'グループ名を入力してください。';
        }

        foreach ($g['questions'] as $q) {
            if (trim((string)($q['text'] ?? '')) === '') {
                $errors[] = '質問文を入力してください。';
            }

            if (!in_array($q['type'] ?? '', ['single', 'multiple', 'text'], true)) {
                $errors[] = '回答形式が不正です。';
            }

            if (in_array($q['type'] ?? '', ['single', 'multiple'], true)) {
                if (count($q['options'] ?? []) === 0) {
                    $errors[] = '選択式質問には選択肢を1つ以上設定してください。';
                }

                foreach (($q['options'] ?? []) as $o) {
                    if (trim((string)($o['label'] ?? '')) === '') {
                        $errors[] = '選択肢を空欄にできません。';
                    }
                }
            }
        }
    }

    return $errors;
}

/* =========================================================
 * kintone
 * ========================================================= */

function kintoneConfig(): array
{
    return jsonRead('kintone', [
        'subdomain' => '',
        'app_id' => '',
        'login' => '',
        'password' => '',
        'proxy' => '',
        'verify_ssl' => true,
        'mapping' => [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => []
        ]
    ]);
}

function kintoneRequest(
    string $method,
    string $url,
    array $config,
    ?array $body = null
): array {
    $auth = base64_encode(
        (string)$config['login'] . ':' . (string)$config['password']
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Content-Type: application/json'
    ];

    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
        'ssl' => [
            'verify_peer' => !empty($config['verify_ssl']),
            'verify_peer_name' => !empty($config['verify_ssl']),
            'allow_self_signed' => empty($config['verify_ssl'])
        ]
    ];

    if ($body !== null) {
        $opts['http']['content'] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
        );
    }

    if (!empty($config['proxy'])) {
        $proxy = trim((string)$config['proxy']);
        $opts['http']['proxy'] = $proxy;
        $opts['http']['request_fulluri'] = true;
    }

    $ctx = stream_context_create($opts);

    $result = @file_get_contents($url, false, $ctx);

    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $m)) {
            $status = (int)$m[1];
            break;
        }
    }

    if ($result === false) {
        return [
            'ok' => false,
            'status' => $status,
            'body' => null,
            'error' => 'kintoneへの通信に失敗しました。'
        ];
    }

    $decoded = json_decode($result, true);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $decoded,
        'raw' => $result,
        'error' => $status >= 300
            ? 'kintone APIエラーが発生しました。'
            : ''
    ];
}

function kintoneBaseUrl(array $config): string
{
    $sub = preg_replace(
        '/[^a-zA-Z0-9\-]/',
        '',
        (string)($config['subdomain'] ?? '')
    );

    return 'https://' . $sub . '.cybozu.com';
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtpConfig(): array
{
    return jsonRead('smtp', [
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => APP_NAME,
        'reply_to' => ''
    ]);
}

function smtpRead($fp, int $timeout = 15): array
{
    stream_set_timeout($fp, $timeout);

    $data = '';

    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    $code = (int)substr($data, 0, 3);

    return [$code, $data];
}

function smtpCommand($fp, string $command, array $expected = [250]): array
{
    fwrite($fp, $command . "\r\n");

    [$code, $response] = smtpRead($fp);

    return [
        'ok' => in_array($code, $expected, true),
        'code' => $code,
        'response' => $response
    ];
}

function smtpConnect(array $cfg): array
{
    $host = trim((string)($cfg['host'] ?? ''));
    $port = (int)($cfg['port'] ?? 587);
    $encryption = strtolower((string)($cfg['encryption'] ?? 'tls'));

    if ($host === '') {
        return [
            'ok' => false,
            'error' => 'SMTPサーバーを設定してください。'
        ];
    }

    if ($port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'error' => 'SMTPポートが不正です。'
        ];
    }

    $transport = $encryption === 'ssl'
        ? 'ssl://'
        : 'tcp://';

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        return [
            'ok' => false,
            'error' => 'SMTP接続失敗: ' . $errstr
        ];
    }

    [$code, $response] = smtpRead($fp);

    if ($code < 200 || $code >= 400) {
        fclose($fp);
        return [
            'ok' => false,
            'error' => 'SMTPサーバーから接続を拒否されました。'
        ];
    }

    $result = smtpCommand($fp, 'EHLO localhost', [250]);

    if (!$result['ok']) {
        fclose($fp);
        return [
            'ok' => false,
            'error' => 'EHLOに失敗しました。'
        ];
    }

    if ($encryption === 'tls') {
        $tls = smtpCommand($fp, 'STARTTLS', [220]);

        if (!$tls['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'error' => 'STARTTLSに失敗しました。'
            ];
        }

        $crypto = @stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($fp);
            return [
                'ok' => false,
                'error' => 'TLS暗号化を開始できませんでした。'
            ];
        }

        $result = smtpCommand($fp, 'EHLO localhost', [250]);

        if (!$result['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'error' => 'TLS後のEHLOに失敗しました。'
            ];
        }
    }

    if (!empty($cfg['auth'])) {
        $username = (string)($cfg['username'] ?? '');
        $password = (string)($cfg['password'] ?? '');

        $auth = smtpCommand($fp, 'AUTH LOGIN', [334]);

        if (!$auth['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'error' => 'SMTP認証開始に失敗しました。'
            ];
        }

        $auth = smtpCommand($fp, base64_encode($username), [334]);

        if (!$auth['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'error' => 'SMTPユーザー認証に失敗しました。'
            ];
        }

        $auth = smtpCommand($fp, base64_encode($password), [235]);

        if (!$auth['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'error' => 'SMTPパスワード認証に失敗しました。'
            ];
        }
    }

    return [
        'ok' => true,
        'fp' => $fp
    ];
}

function smtpSend(array $cfg, string $to, string $subject, string $body): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok' => false,
            'error' => '宛先メールアドレスが不正です。'
        ];
    }

    $connection = smtpConnect($cfg);

    if (!$connection['ok']) {
        return $connection;
    }

    $fp = $connection['fp'];

    $from = (string)$cfg['from_email'];
    $fromName = (string)($cfg['from_name'] ?? APP_NAME);

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        fclose($fp);
        return [
            'ok' => false,
            'error' => '送信元メールアドレスが不正です。'
        ];
    }

    $r = smtpCommand($fp, 'MAIL FROM:<' . $from . '>', [250]);

    if (!$r['ok']) {
        fclose($fp);
        return ['ok' => false, 'error' => 'MAIL FROMに失敗しました。'];
    }

    $r = smtpCommand($fp, 'RCPT TO:<' . $to . '>', [250, 251]);

    if (!$r['ok']) {
        fclose($fp);
        return ['ok' => false, 'error' => 'RCPT TOに失敗しました。'];
    }

    $r = smtpCommand($fp, 'DATA', [354]);

    if (!$r['ok']) {
        fclose($fp);
        return ['ok' => false, 'error' => 'DATAに失敗しました。'];
    }

    $encodedSubject = '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $encodedName = '=?UTF-8?B?' .
        base64_encode($fromName) .
        '?=';

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . $encodedName . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit'
    ];

    if (!empty($cfg['reply_to']) &&
        filter_var($cfg['reply_to'], FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $cfg['reply_to'];
    }

    $message = implode("\r\n", $headers) .
        "\r\n\r\n" .
        preg_replace('/^\./m', '..', $body) .
        "\r\n.";

    fwrite($fp, $message . "\r\n");

    [$code, $response] = smtpRead($fp);

    smtpCommand($fp, 'QUIT', [221, 250]);

    fclose($fp);

    return [
        'ok' => $code >= 200 && $code < 300,
        'error' => $code >= 200 && $code < 300
            ? ''
            : 'SMTP送信に失敗しました。'
    ];
}

/* =========================================================
 * PDF
 * 簡易PDF生成。外部ライブラリなし。
 * ========================================================= */

function pdfEscape(string $text): string
{
    $text = str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );

    return $text;
}

function outputSimplePdf(string $title, array $lines): never
{
    /*
     * PDF標準フォントでは日本語を直接描画できないため、
     * POCではASCII化した帳票を生成する。
     */
    $safe = [];

    $safe[] = $title;

    foreach ($lines as $line) {
        $safe[] = preg_replace(
            '/[^\x20-\x7E]/',
            '?',
            (string)$line
        );
    }

    $content = "BT\n/F1 12 Tf\n50 800 Td\n";

    foreach ($safe as $i => $line) {
        if ($i > 0) {
            $content .= "0 -20 Td\n";
        }

        $content .= '(' . pdfEscape($line) . ") Tj\n";
    }

    $content .= "ET";

    $objects = [];

    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[] =
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 5 0 R >> >> ' .
        '/Contents 4 0 R >>';

    $objects[] =
        '<< /Length ' . strlen($content) . " >>\nstream\n" .
        $content .
        "\nendstream";

    $objects[] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $obj) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n";
        $pdf .= $obj . "\n";
        $pdf .= "endobj\n";
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
    $pdf .= "<< /Size " . (count($objects) + 1) .
        " /Root 1 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= $xref . "\n";
    $pdf .= "%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="survey.pdf"');
    header('Content-Length: ' . strlen($pdf));

    echo $pdf;
    exit;
}

/* =========================================================
 * POST Actions
 * ========================================================= */

$action = $_POST['action'] ?? '';

if ($action !== '') {
    csrfFree();

    switch ($action) {

        /* ---------------------------------------------
         * Survey save
         * --------------------------------------------- */

        case 'save_survey':
            $id = trim((string)($_POST['id'] ?? ''));

            $surveys = jsonRead('surveys', []);
            $existing = $id !== '' ? findById($surveys, $id) : null;

            if ($existing) {
                $survey = $existing;
            } else {
                $survey = emptySurvey();
            }

            $survey['title'] = trim((string)($_POST['title'] ?? ''));
            $survey['description'] = trim(
                (string)($_POST['description'] ?? '')
            );
            $survey['start_at'] = trim(
                (string)($_POST['start_at'] ?? '')
            );
            $survey['end_at'] = trim(
                (string)($_POST['end_at'] ?? '')
            );
            $survey['numbering'] =
                ($_POST['numbering'] ?? 'global') === 'group'
                    ? 'group'
                    : 'global';

            $groups = json_decode(
                (string)($_POST['groups_json'] ?? '[]'),
                true
            );

            if (!is_array($groups)) {
                $groups = [];
            }

            $survey['groups'] = $groups;
            $survey = normalizeSurvey($survey);

            $errors = validateSurvey($survey);

            if ($errors) {
                flash('danger', implode('<br>', array_map('h', $errors)));
                redirect(
                    'index.php?screen=edit&id=' .
                    rawurlencode($survey['id'])
                );
            }

            if ($survey['status'] === '') {
                $survey['status'] = 'draft';
            }

            $survey['updated_at'] = now();

            saveById('surveys', $survey);

            flash('success', 'アンケートを保存しました。');
            redirect('index.php?screen=list');

        /* ---------------------------------------------
         * Survey status
         * --------------------------------------------- */

        case 'change_status':
            $id = (string)($_POST['id'] ?? '');
            $to = (string)($_POST['to'] ?? '');

            $surveys = jsonRead('surveys', []);
            $survey = findById($surveys, $id);

            if (!$survey) {
                flash('danger', 'アンケートが見つかりません。');
                redirect('index.php?screen=list');
            }

            $current = surveyStatus($survey);

            $allowed = [
                'draft' => ['published'],
                'published' => ['stopped'],
                'stopped' => ['published'],
                'ended' => []
            ];

            if (!in_array($to, $allowed[$current] ?? [], true)) {
                flash('danger', 'この状態変更はできません。');
                redirect('index.php?screen=list');
            }

            $survey['status'] = $to;
            $survey['updated_at'] = now();

            saveById('surveys', $survey);

            flash('success', '状態を変更しました。');
            redirect('index.php?screen=list');

        /* ---------------------------------------------
         * Duplicate
         * --------------------------------------------- */

        case 'duplicate_survey':
            $id = (string)($_POST['id'] ?? '');

            $surveys = jsonRead('surveys', []);
            $survey = findById($surveys, $id);

            if ($survey) {
                $survey['id'] = uid('survey');
                $survey['title'] .= '（コピー）';
                $survey['status'] = 'draft';
                $survey['created_at'] = now();
                $survey['updated_at'] = now();

                foreach ($survey['groups'] as &$g) {
                    $g['id'] = uid('group');

                    foreach ($g['questions'] as &$q) {
                        $q['id'] = uid('question');

                        foreach ($q['options'] as &$o) {
                            $o['id'] = uid('option');
                        }
                        unset($o);
                    }
                    unset($q);
                }
                unset($g);

                $survey = recalcNumbers($survey);
                saveById('surveys', $survey);

                flash('success', 'アンケートを複製しました。');
            }

            redirect('index.php?screen=list');

        /* ---------------------------------------------
         * Delete
         * --------------------------------------------- */

        case 'delete_survey':
            $id = (string)($_POST['id'] ?? '');

            deleteById('surveys', $id);

            flash('success', 'アンケートを削除しました。');
            redirect('index.php?screen=list');

        /* ---------------------------------------------
         * Answer confirmation
         * --------------------------------------------- */

        case 'answer_confirm':
            $id = (string)($_POST['survey_id'] ?? '');

            $surveys = jsonRead('surveys', []);
            $survey = findById($surveys, $id);

            if (!$survey || surveyStatus($survey) !== 'published') {
                flash('danger', 'このアンケートは回答できません。');
                redirect('index.php?screen=list');
            }

            $answers = $_POST['answer'] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $visible = visibleQuestions($survey, $answers);
            $errors = [];

            foreach ($visible as $q) {
                if (!$q['required']) {
                    continue;
                }

                $value = $answers[$q['id']] ?? null;

                $empty = $value === null ||
                    $value === '' ||
                    (is_array($value) && count($value) === 0);

                if ($empty) {
                    $errors[] =
                        ($q['number'] ?? '') .
                        '「' .
                        h($q['text']) .
                        '」は必須です。';
                }
            }

            if ($errors) {
                $_SESSION['answer_errors'] = $errors;
                $_SESSION['answer_data'] = $answers;

                redirect(
                    'index.php?screen=answer&id=' .
                    rawurlencode($id)
                );
            }

            $_SESSION['answer_data'] = $answers;

            redirect(
                'index.php?screen=confirm&id=' .
                rawurlencode($id)
            );

        /* ---------------------------------------------
         * Answer save
         * --------------------------------------------- */

        case 'save_answer':
            $id = (string)($_POST['survey_id'] ?? '');

            $surveys = jsonRead('surveys', []);
            $survey = findById($surveys, $id);

            if (!$survey || surveyStatus($survey) !== 'published') {
                flash('danger', 'このアンケートは回答できません。');
                redirect('index.php?screen=list');
            }

            $answers = $_SESSION['answer_data'] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $responses = jsonRead('responses', []);

            $responses[] = [
                'id' => uid('response'),
                'survey_id' => $id,
                'customer_id' => trim(
                    (string)($_POST['customer_id'] ?? '')
                ),
                'respondent_name' => trim(
                    (string)($_POST['respondent_name'] ?? '')
                ),
                'respondent_email' => trim(
                    (string)($_POST['respondent_email'] ?? '')
                ),
                'answers' => $answers,
                'created_at' => now()
            ];

            jsonWrite('responses', $responses);

            unset(
                $_SESSION['answer_data'],
                $_SESSION['answer_errors']
            );

            redirect(
                'index.php?screen=complete&id=' .
                rawurlencode($id)
            );

        /* ---------------------------------------------
         * Mail send
         * --------------------------------------------- */

        case 'send_mail':
            $surveyId = (string)($_POST['survey_id'] ?? '');

            $customers = jsonRead('customers', []);
            $selected = $_POST['customers'] ?? [];

            if (!is_array($selected)) {
                $selected = [];
            }

            $subject = trim((string)($_POST['subject'] ?? ''));
            $body = (string)($_POST['body'] ?? '');

            $surveys = jsonRead('surveys', []);
            $survey = findById($surveys, $surveyId);

            if (!$survey) {
                flash('danger', '対象アンケートが見つかりません。');
                redirect(
                    'index.php?screen=send&id=' .
                    rawurlencode($surveyId)
                );
            }

            $cfg = smtpConfig();

            $sent = 0;
            $failed = 0;
            $logs = jsonRead('mail_logs', []);

            $baseUrl =
                (isset($_SERVER['HTTPS']) &&
                $_SERVER['HTTPS'] !== 'off'
                    ? 'https'
                    : 'http') .
                '://' .
                ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') .
                '/index.php?screen=answer&id=' .
                rawurlencode($surveyId);

            foreach ($customers as $customer) {
                $cid = (string)($customer['id'] ?? '');

                if (!in_array($cid, $selected, true)) {
                    continue;
                }

                $name = (string)($customer['name'] ?? '');
                $email = (string)($customer['email'] ?? '');

                $mailBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [$name, $baseUrl],
                    $body
                );

                $result = smtpSend(
                    $cfg,
                    $email,
                    $subject,
                    $mailBody
                );

                $logs[] = [
                    'id' => uid('mail'),
                    'survey_id' => $surveyId,
                    'customer_id' => $cid,
                    'customer_name' => $name,
                    'email' => $email,
                    'subject' => $subject,
                    'body' => $mailBody,
                    'type' => (string)(
                        $_POST['mail_type'] ?? 'send'
                    ),
                    'status' => $result['ok'] ? 'success' : 'failed',
                    'error' => $result['ok']
                        ? ''
                        : ($result['error'] ?? '送信失敗'),
                    'created_at' => now()
                ];

                if ($result['ok']) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            jsonWrite('mail_logs', $logs);

            flash(
                $failed > 0 ? 'warning' : 'success',
                '送信完了：成功 ' .
                $sent .
                '件 / 失敗 ' .
                $failed .
                '件'
            );

            redirect(
                'index.php?screen=send&id=' .
                rawurlencode($surveyId)
            );

        /* ---------------------------------------------
         * SMTP save
         * --------------------------------------------- */

        case 'save_smtp':
            $cfg = smtpConfig();

            $cfg['host'] = trim(
                (string)($_POST['host'] ?? '')
            );
            $cfg['port'] = (int)($_POST['port'] ?? 587);
            $cfg['encryption'] = in_array(
                $_POST['encryption'] ?? 'tls',
                ['none', 'tls', 'ssl'],
                true
            )
                ? $_POST['encryption']
                : 'tls';
            $cfg['auth'] = !empty($_POST['auth']);
            $cfg['username'] = trim(
                (string)($_POST['username'] ?? '')
            );

            if (
                isset($_POST['password']) &&
                $_POST['password'] !== ''
            ) {
                $cfg['password'] = (string)$_POST['password'];
            }

            $cfg['from_email'] = trim(
                (string)($_POST['from_email'] ?? '')
            );
            $cfg['from_name'] = trim(
                (string)($_POST['from_name'] ?? '')
            );
            $cfg['reply_to'] = trim(
                (string)($_POST['reply_to'] ?? '')
            );

            jsonWrite('smtp', $cfg);

            flash('success', 'SMTP設定を保存しました。');
            redirect('index.php?screen=mail');

        /* ---------------------------------------------
         * SMTP test
         * --------------------------------------------- */

        case 'smtp_test':
            $result = smtpConnect(smtpConfig());

            if (!empty($result['fp'])) {
                smtpCommand(
                    $result['fp'],
                    'QUIT',
                    [221, 250]
                );
                fclose($result['fp']);
            }

            flash(
                $result['ok'] ? 'success' : 'danger',
                $result['ok']
                    ? 'SMTP接続に成功しました。'
                    : ($result['error'] ?? 'SMTP接続に失敗しました。')
            );

            redirect('index.php?screen=mail');

        /* ---------------------------------------------
         * Test mail
         * --------------------------------------------- */

        case 'smtp_test_mail':
            $to = trim((string)($_POST['test_email'] ?? ''));

            $result = smtpSend(
                smtpConfig(),
                $to,
                'SMTPテストメール',
                "SMTP接続・メール送信テストです。\n" .
                "送信日時: " . now()
            );

            flash(
                $result['ok'] ? 'success' : 'danger',
                $result['ok']
                    ? 'テストメールを送信しました。'
                    : ($result['error'] ?? '送信に失敗しました。')
            );

            redirect('index.php?screen=mail');

        /* ---------------------------------------------
         * kintone save
         * --------------------------------------------- */

        case 'save_kintone':
            $cfg = kintoneConfig();

            $cfg['subdomain'] = trim(
                (string)($_POST['subdomain'] ?? '')
            );
            $cfg['app_id'] = trim(
                (string)($_POST['app_id'] ?? '')
            );
            $cfg['login'] = trim(
                (string)($_POST['login'] ?? '')
            );

            if (
                isset($_POST['password']) &&
                $_POST['password'] !== ''
            ) {
                $cfg['password'] = (string)$_POST['password'];
            }

            $cfg['proxy'] = trim(
                (string)($_POST['proxy'] ?? '')
            );
            $cfg['verify_ssl'] = !empty($_POST['verify_ssl']);

            $cfg['mapping'] = [
                'organization' => trim(
                    (string)($_POST['map_organization'] ?? '')
                ),
                'name' => trim(
                    (string)($_POST['map_name'] ?? '')
                ),
                'email' => trim(
                    (string)($_POST['map_email'] ?? '')
                ),
                'department' => trim(
                    (string)($_POST['map_department'] ?? '')
                ),
                'phone' => trim(
                    (string)($_POST['map_phone'] ?? '')
                ),
                'address' => array_values(
                    array_filter(
                        $_POST['map_address'] ?? [],
                        fn($x) => trim((string)$x) !== ''
                    )
                )
            ];

            jsonWrite('kintone', $cfg);

            flash(
                'success',
                'kintone設定を保存しました。'
            );

            redirect('index.php?screen=kintone');

        /* ---------------------------------------------
         * kintone connection test
         * --------------------------------------------- */

        case 'kintone_test':
            $cfg = kintoneConfig();

            if (
                empty($cfg['subdomain']) ||
                empty($cfg['app_id']) ||
                empty($cfg['login']) ||
                empty($cfg['password'])
            ) {
                flash(
                    'danger',
                    'kintone設定を入力してください。'
                );
                redirect('index.php?screen=kintone');
            }

            $url =
                kintoneBaseUrl($cfg) .
                '/k/v1/app.json?id=' .
                rawurlencode((string)$cfg['app_id']);

            $result = kintoneRequest(
                'GET',
                $url,
                $cfg
            );

            flash(
                $result['ok'] ? 'success' : 'danger',
                $result['ok']
                    ? 'kintone接続に成功しました。'
                    : ($result['error'] ?? '接続に失敗しました。')
            );

            redirect('index.php?screen=kintone');

        /* ---------------------------------------------
         * kintone fields
         * --------------------------------------------- */

        case 'kintone_fields':
            $cfg = kintoneConfig();

            $url =
                kintoneBaseUrl($cfg) .
                '/k/v1/app/form/fields.json?app=' .
                rawurlencode((string)$cfg['app_id']);

            $result = kintoneRequest(
                'GET',
                $url,
                $cfg
            );

            if ($result['ok']) {
                $_SESSION['kintone_fields'] =
                    $result['body']['properties'] ?? [];

                flash(
                    'success',
                    'kintone項目一覧を取得しました。'
                );
            } else {
                flash(
                    'danger',
                    $result['error'] ?? '取得に失敗しました。'
                );
            }

            redirect('index.php?screen=kintone');

        /* ---------------------------------------------
         * kintone sync
         * --------------------------------------------- */

        case 'kintone_sync':
            $cfg = kintoneConfig();

            $query = '';

            $url =
                kintoneBaseUrl($cfg) .
                '/k/v1/records.json?app=' .
                rawurlencode((string)$cfg['app_id']) .
                '&query=' .
                rawurlencode($query) .
                '&totalCount=true';

            $result = kintoneRequest(
                'GET',
                $url,
                $cfg
            );

            if (!$result['ok']) {
                flash(
                    'danger',
                    $result['error'] ?? '顧客情報取得に失敗しました。'
                );
                redirect('index.php?screen=kintone');
            }

            $mapping = $cfg['mapping'] ?? [];
            $records = $result['body']['records'] ?? [];
            $customers = [];

            foreach ($records as $record) {
                $value = function(string $field) use ($record): string {
                    return (string)(
                        $record[$field]['value'] ?? ''
                    );
                };

                $address = [];

                foreach (($mapping['address'] ?? []) as $field) {
                    $v = $value((string)$field);
                    if ($v !== '') {
                        $address[] = $v;
                    }
                }

                $customers[] = [
                    'id' => 'kintone-' .
                        ($record['$id']['value'] ?? uid('customer')),
                    'organization' =>
                        $value((string)($mapping['organization'] ?? '')),
                    'name' =>
                        $value((string)($mapping['name'] ?? '')),
                    'email' =>
                        $value((string)($mapping['email'] ?? '')),
                    'department' =>
                        $value((string)($mapping['department'] ?? '')),
                    'phone' =>
                        $value((string)($mapping['phone'] ?? '')),
                    'address' => implode(' ', $address),
                    'updated_at' => now()
                ];
            }

            jsonWrite('customers', $customers);

            flash(
                'success',
                count($customers) .
                '件の顧客情報を同期しました。'
            );

            redirect('index.php?screen=kintone');
    }
}

/* =========================================================
 * Export
 * ========================================================= */

$screen = $_GET['screen'] ?? 'list';
$id = (string)($_GET['id'] ?? '');

if ($screen === 'csv') {
    $surveyId = $id;

    $surveys = jsonRead('surveys', []);
    $survey = findById($surveys, $surveyId);

    if (!$survey) {
        http_response_code(404);
        exit('Not Found');
    }

    $responses = surveyResponses($surveyId);

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        rawurlencode($surveyId) .
        '.csv"'
    );

    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'w');

    $questions = [];

    foreach ($survey['groups'] as $g) {
        foreach ($g['questions'] as $q) {
            $questions[] = $q;
        }
    }

    $header = [
        '回答ID',
        '回答日時',
        '回答者名',
        'メールアドレス'
    ];

    foreach ($questions as $q) {
        $header[] =
            ($q['number'] ?? '') .
            ' ' .
            ($q['text'] ?? '');
    }

    fputcsv($fp, $header);

    foreach ($responses as $response) {
        $row = [
            $response['id'] ?? '',
            $response['created_at'] ?? '',
            $response['respondent_name'] ?? '',
            $response['respondent_email'] ?? ''
        ];

        foreach ($questions as $q) {
            $v = $response['answers'][$q['id']] ?? '';

            if (is_array($v)) {
                $labels = [];

                foreach ($q['options'] as $o) {
                    if (in_array($o['id'], $v, true)) {
                        $labels[] = $o['label'];
                    }
                }

                $v = implode(', ', $labels);
            } else {
                foreach ($q['options'] as $o) {
                    if ((string)$o['id'] === (string)$v) {
                        $v = $o['label'];
                        break;
                    }
                }
            }

            $row[] = $v;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

if ($screen === 'pdf') {
    $surveys = jsonRead('surveys', []);
    $survey = findById($surveys, $id);

    if (!$survey) {
        http_response_code(404);
        exit('Not Found');
    }

    $responses = surveyResponses($id);

    $lines = [
        'Survey: ' . $survey['title'],
        'Responses: ' . count($responses),
        ''
    ];

    foreach ($survey['groups'] as $g) {
        $lines[] = 'Group: ' . $g['title'];

        foreach ($g['questions'] as $q) {
            $count = 0;

            foreach ($responses as $r) {
                $v = $r['answers'][$q['id']] ?? null;

                if ($v !== null &&
                    $v !== '' &&
                    !(is_array($v) && !$v)) {
                    $count++;
                }
            }

            $lines[] =
                ($q['number'] ?? '') .
                ' ' .
                $q['text'] .
                ' / answered: ' .
                $count;
        }
    }

    outputSimplePdf(
        $survey['title'],
        $lines
    );
}

/* =========================================================
 * HTML
 * ========================================================= */

$flash = getFlash();

$surveys = jsonRead('surveys', []);
$customers = jsonRead('customers', []);
$mailLogs = jsonRead('mail_logs', []);

foreach ($surveys as &$s) {
    $s['display_status'] = surveyStatus($s);
}
unset($s);

$navAdmin = !in_array(
    $screen,
    ['answer', 'confirm', 'complete']
);

function pageStart(string $title, bool $admin = true): void
{
    global $flash, $screen;

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport"
              content="width=device-width,initial-scale=1">
        <title><?= h($title) ?> - <?= h(APP_NAME) ?></title>
        <style>
        :root{
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

        *{box-sizing:border-box}

        body{
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

        a{
            color:var(--primary);
            text-decoration:none;
        }

        a:hover{text-decoration:underline}

        button,input,select,textarea{font:inherit}

        button{cursor:pointer}

        h1,h2,h3{margin-top:0}

        h1{
            font-size:1.65rem;
            margin-bottom:6px;
        }

        h2{
            font-size:1.2rem;
            margin-bottom:18px;
        }

        h3{
            font-size:1rem;
            margin-bottom:12px;
        }

        .app-header{
            background:var(--header);
            color:var(--white);
            min-height:64px;
            display:flex;
            align-items:center;
            padding:0 24px;
        }

        .brand{
            color:#fff;
            font-weight:700;
            font-size:1.1rem;
        }

        .nav{
            margin-left:auto;
            display:flex;
            gap:8px;
            align-items:center;
        }

        .nav a{
            color:#cbd5e1;
            padding:8px 12px;
            border-radius:6px;
        }

        .nav a:hover,.nav a.active{
            color:#fff;
            background:rgba(255,255,255,.08);
            text-decoration:none;
        }

        .container{
            width:min(1200px,calc(100% - 32px));
            margin:0 auto;
            padding:28px 0 48px;
        }

        .answer-container{
            width:min(760px,calc(100% - 32px));
            margin:0 auto;
            padding:28px 0 48px;
        }

        .page-title{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:16px;
            margin-bottom:22px;
        }

        .card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:22px;
            margin-bottom:20px;
        }

        .grid-2{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:20px;
        }

        .grid-3{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:20px;
        }

        .form-group{margin-bottom:16px}

        .form-label{
            display:block;
            font-weight:600;
            margin-bottom:6px;
        }

        .form-help{
            margin-top:5px;
            color:var(--gray);
            font-size:.875rem;
        }

        input[type=text],
        input[type=search],
        input[type=email],
        input[type=password],
        input[type=number],
        input[type=datetime-local],
        select,
        textarea{
            width:100%;
            min-height:42px;
            padding:9px 12px;
            border:1px solid var(--border);
            border-radius:7px;
            background:#fff;
            color:var(--text);
            outline:none;
        }

        textarea{
            min-height:140px;
            resize:vertical;
        }

        input:focus,select:focus,textarea:focus{
            border-color:var(--primary);
            box-shadow:0 0 0 3px rgba(37,99,235,.12);
        }

        .checkbox{
            display:inline-flex;
            align-items:center;
            gap:8px;
            cursor:pointer;
        }

        .checkbox input{
            width:17px;
            height:17px;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:40px;
            padding:8px 14px;
            border:1px solid transparent;
            border-radius:7px;
            font-weight:600;
            text-decoration:none;
            white-space:nowrap;
        }

        .btn:hover{text-decoration:none}

        .btn-primary{
            background:var(--primary);
            color:#fff;
        }

        .btn-primary:hover{background:var(--primary-dark)}

        .btn-success{
            background:var(--success);
            color:#fff;
        }

        .btn-warning{
            background:var(--warning);
            color:#fff;
        }

        .btn-danger{
            background:var(--danger);
            color:#fff;
        }

        .btn-secondary{
            background:#fff;
            color:var(--text);
            border-color:var(--border);
        }

        .btn-small{
            min-height:34px;
            padding:5px 9px;
            font-size:.875rem;
        }

        .actions{
            display:flex;
            flex-wrap:wrap;
            gap:8px;
        }

        .table-wrap{overflow-x:auto}

        table{
            width:100%;
            border-collapse:collapse;
            background:#fff;
        }

        th,td{
            padding:12px 14px;
            border-bottom:1px solid var(--border);
            text-align:left;
            vertical-align:middle;
        }

        th{
            background:var(--gray-light);
            font-weight:700;
            white-space:nowrap;
        }

        .badge{
            display:inline-flex;
            align-items:center;
            padding:4px 9px;
            border-radius:999px;
            font-size:.8rem;
            font-weight:700;
            white-space:nowrap;
        }

        .badge-success{
            background:#dcfce7;
            color:#166534;
        }

        .badge-warning{
            background:#fef3c7;
            color:#92400e;
        }

        .badge-danger{
            background:#fee2e2;
            color:#991b1b;
        }

        .badge-gray{
            background:#e2e8f0;
            color:#475569;
        }

        .badge-draft{
            background:#e0e7ff;
            color:#3730a3;
        }

        .tabs{
            display:flex;
            gap:4px;
            border-bottom:1px solid var(--border);
            margin-bottom:20px;
        }

        .tabs a{
            padding:10px 14px;
            color:var(--gray);
            border-bottom:2px solid transparent;
        }

        .tabs a.active{
            color:var(--primary);
            border-bottom-color:var(--primary);
            font-weight:700;
        }

        .muted{color:var(--gray)}

        .empty{
            padding:34px 20px;
            text-align:center;
            color:var(--gray);
            background:#f8fafc;
            border:1px dashed var(--border);
            border-radius:8px;
        }

        .alert{
            border-radius:8px;
            padding:13px 16px;
            margin-bottom:20px;
        }

        .alert-success{
            color:#166534;
            background:#dcfce7;
            border:1px solid #bbf7d0;
        }

        .alert-warning{
            color:#92400e;
            background:#fef3c7;
            border:1px solid #fde68a;
        }

        .alert-danger{
            color:#991b1b;
            background:#fee2e2;
            border:1px solid #fecaca;
        }

        .group-card{
            border:1px solid var(--border);
            border-radius:9px;
            background:#fff;
            margin-bottom:18px;
            overflow:hidden;
        }

        .group-header{
            display:flex;
            align-items:center;
            gap:10px;
            padding:14px 16px;
            background:var(--gray-light);
            border-bottom:1px solid var(--border);
        }

        .group-title{
            flex:1;
            min-width:0;
        }

        .group-body{padding:16px}

        .question-card{
            border:1px solid var(--border);
            border-radius:8px;
            padding:16px;
            margin-bottom:12px;
            background:#fff;
        }

        .question-card:last-child{margin-bottom:0}

        .option-row{
            display:flex;
            gap:8px;
            margin-bottom:8px;
        }

        .option-row input{flex:1}

        .condition-row{
            display:grid;
            grid-template-columns:1fr 1fr auto;
            gap:8px;
            margin-bottom:8px;
        }

        .stat{
            padding:18px;
            border:1px solid var(--border);
            border-radius:9px;
            background:#fff;
        }

        .stat .label{
            color:var(--gray);
            font-size:.9rem;
        }

        .stat .value{
            font-size:1.7rem;
            font-weight:700;
        }

        .answer-card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:22px;
            margin-bottom:18px;
        }

        .required{
            color:var(--danger);
            margin-left:4px;
        }

        .searchbar{
            display:flex;
            gap:8px;
            align-items:center;
        }

        .searchbar input{flex:1}

        .drag{
            cursor:grab;
            color:var(--gray);
            user-select:none;
        }

        .sticky{
            position:sticky;
            bottom:0;
            background:rgba(255,255,255,.96);
            border-top:1px solid var(--border);
            padding:12px 0;
            z-index:5;
        }

        @media(max-width:900px){
            .grid-3{
                grid-template-columns:repeat(2,minmax(0,1fr));
            }

            .grid-2{
                grid-template-columns:1fr;
            }

            .condition-row{
                grid-template-columns:1fr;
            }
        }

        @media(max-width:700px){
            .app-header{
                padding:10px 16px;
                align-items:flex-start;
                flex-direction:column;
            }

            .nav{
                margin-left:0;
                width:100%;
                overflow-x:auto;
            }

            .container{
                width:calc(100% - 16px);
                padding-top:16px;
            }

            .answer-container{
                width:calc(100% - 16px);
                padding-top:12px;
            }

            .page-title{
                flex-direction:column;
            }

            .grid-3{
                grid-template-columns:1fr;
            }

            .actions .btn{
                max-width:100%;
            }

            th,td{
                padding:10px 11px;
            }
        }

        @media print{
            .app-header,.actions,.btn,.tabs{
                display:none!important;
            }

            body{background:#fff}

            .container,.answer-container{
                width:100%;
                max-width:none;
                padding:0;
            }

            .card,.answer-card{
                box-shadow:none;
                border:0;
            }
        }
        </style>
    </head>
    <body>

    <?php if ($admin): ?>
        <header class="app-header">
            <a class="brand" href="index.php?screen=list">
                <?= h(APP_NAME) ?>
            </a>

            <nav class="nav">
                <a href="index.php?screen=list"
                   class="<?= $screen === 'list' ? 'active' : '' ?>">
                    アンケート
                </a>
                <a href="index.php?screen=kintone"
                   class="<?= $screen === 'kintone' ? 'active' : '' ?>">
                    kintone
                </a>
                <a href="index.php?screen=mail"
                   class="<?= $screen === 'mail' ? 'active' : '' ?>">
                    メール
                </a>
            </nav>
        </header>
    <?php endif; ?>

    <main class="<?= $admin ? 'container' : 'answer-container' ?>">

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>">
            <?= $flash['message'] ?>
        </div>
    <?php endif; ?>

    <?php
}

function pageEnd(): void
{
    ?>
    </main>

    <script>
    function confirmAction(message) {
        return window.confirm(message);
    }

    function addOption(button) {
        const box = button.closest('.options-box');
        const row = document.createElement('div');
        row.className = 'option-row';
        row.innerHTML =
            '<input type="text" placeholder="選択肢">' +
            '<button type="button" class="btn btn-danger btn-small" ' +
            'onclick="this.parentElement.remove()">削除</button>';
        box.insertBefore(row, button);
    }

    function addCondition(button) {
        const box = button.closest('.conditions-box');
        const template = box.querySelector('.condition-template');
        const row = template.cloneNode(true);
        row.classList.remove('condition-template');
        row.style.display = 'grid';
        row.querySelectorAll('select').forEach(function(s){
            s.value = '';
        });
        box.insertBefore(row, button);
    }

    function addGroup() {
        const groups = document.getElementById('groups');
        const index = groups.children.length;

        const group = document.createElement('div');
        group.className = 'group-card';
        group.innerHTML = `
            <div class="group-header">
                <span class="drag">☷</span>
                <input class="group-title"
                       type="text"
                       value="グループ${index + 1}">
                <button type="button"
                        class="btn btn-danger btn-small"
                        onclick="this.closest('.group-card').remove();renumberGroups()">
                    グループ削除
                </button>
            </div>
            <div class="group-body">
                <div class="questions"></div>
                <button type="button"
                        class="btn btn-secondary btn-small"
                        onclick="addQuestion(this)">
                    ＋ 質問を追加
                </button>
            </div>
        `;

        groups.appendChild(group);
    }

    function addQuestion(button) {
        const group = button.closest('.group-card');
        const questions = group.querySelector('.questions');

        const q = document.createElement('div');
        q.className = 'question-card';
        q.innerHTML = questionTemplate();

        questions.appendChild(q);
        rebuildConditions();
    }

    function questionTemplate() {
        return `
            <div class="actions"
                 style="justify-content:space-between;margin-bottom:12px">
                <strong class="question-number">Q?</strong>
                <button type="button"
                        class="btn btn-danger btn-small"
                        onclick="this.closest('.question-card').remove();rebuildConditions()">
                    質問削除
                </button>
            </div>

            <div class="form-group">
                <label class="form-label">質問文</label>
                <textarea class="q-text"
                          placeholder="質問文を入力"></textarea>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">回答形式</label>
                    <select class="q-type"
                            onchange="toggleQuestionOptions(this)">
                        <option value="single">単一選択</option>
                        <option value="multiple">複数選択</option>
                        <option value="text">自由記述</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="checkbox"
                           style="margin-top:30px">
                        <input class="q-required"
                               type="checkbox">
                        必須回答
                    </label>
                </div>
            </div>

            <div class="options-area">
                <label class="form-label">選択肢</label>
                <div class="options-box">
                    <div class="option-row">
                        <input type="text"
                               placeholder="選択肢">
                        <button type="button"
                                class="btn btn-danger btn-small"
                                onclick="this.parentElement.remove()">
                            削除
                        </button>
                    </div>

                    <button type="button"
                            class="btn btn-secondary btn-small"
                            onclick="addOption(this)">
                        ＋ 選択肢
                    </button>
                </div>
            </div>

            <div class="conditions-area"
                 style="margin-top:18px">
                <label class="form-label">
                    条件分岐
                </label>

                <div class="conditions-box">
                    <div class="condition-row condition-template"
                         style="display:none">
                        <select class="condition-question"></select>
                        <select class="condition-option"></select>
                        <button type="button"
                                class="btn btn-danger btn-small"
                                onclick="this.parentElement.remove()">
                            削除
                        </button>
                    </div>

                    <button type="button"
                            class="btn btn-secondary btn-small"
                            onclick="addCondition(this)">
                        ＋ 条件を追加
                    </button>
                </div>
            </div>
        `;
    }

    function toggleQuestionOptions(select) {
        const card = select.closest('.question-card');
        const area = card.querySelector('.options-area');
        const conditions = card.querySelector('.conditions-area');

        if (select.value === 'text') {
            area.style.display = 'none';
            conditions.style.display = 'none';
        } else {
            area.style.display = '';
            conditions.style.display =
                select.value === 'single' ? '' : 'none';
        }
    }

    function renumberGroups() {
        document.querySelectorAll('.group-card')
            .forEach(function(group, i) {
                const title = group.querySelector('.group-title');
                if (!title.value.trim()) {
                    title.value = 'グループ' + (i + 1);
                }
            });
    }

    function collectGroups() {
        const groups = [];

        document.querySelectorAll('.group-card')
        .forEach(function(group) {
            const g = {
                id: group.dataset.id || '',
                title: group.querySelector('.group-title').value,
                questions: []
            };

            group.querySelectorAll('.question-card')
            .forEach(function(card) {
                const q = {
                    id: card.dataset.id || '',
                    text: card.querySelector('.q-text').value,
                    type: card.querySelector('.q-type').value,
                    required: card.querySelector('.q-required').checked,
                    options: [],
                    conditions: []
                };

                card.querySelectorAll(
                    '.options-box .option-row input'
                ).forEach(function(input) {
                    if (input.value.trim() !== '') {
                        q.options.push({
                            id: input.dataset.id || '',
                            label: input.value
                        });
                    }
                });

                card.querySelectorAll(
                    '.conditions-box .condition-row:not(.condition-template)'
                ).forEach(function(row) {
                    const qs = row.querySelector('.condition-question');
                    const os = row.querySelector('.condition-option');

                    if (qs && os && qs.value && os.value) {
                        q.conditions.push({
                            question_id: qs.value,
                            option_id: os.value
                        });
                    }
                });

                g.questions.push(q);
            });

            groups.push(g);
        });

        return groups;
    }

    function rebuildConditions() {
        const all = [];

        document.querySelectorAll('.question-card')
        .forEach(function(card) {
            const text =
                card.querySelector('.q-text').value ||
                '未入力の質問';

            const id = card.dataset.id || '';

            all.push({
                id:id,
                text:text,
                options:Array.from(
                    card.querySelectorAll(
                        '.options-box .option-row'
                    )
                ).map(function(row){
                    const input = row.querySelector('input');
                    return {
                        id: input.dataset.id || '',
                        label: input.value || '未入力'
                    };
                })
            });
        });

        document.querySelectorAll('.condition-question')
        .forEach(function(select) {
            const current = select.value;

            select.innerHTML =
                '<option value="">質問を選択</option>';

            all.forEach(function(q) {
                if (!q.id) return;

                const o = document.createElement('option');
                o.value = q.id;
                o.textContent = q.text;
                select.appendChild(o);
            });

            if (current) {
                select.value = current;
            }
        });

        document.querySelectorAll('.condition-question')
        .forEach(function(select) {
            updateConditionOptions(select);
        });

        renumberEditor();
    }

    function updateConditionOptions(select) {
        const row = select.closest('.condition-row');
        const optionSelect =
            row.querySelector('.condition-option');

        if (!optionSelect) return;

        const card = document.querySelector(
            '.question-card[data-id="' +
            CSS.escape(select.value) +
            '"]'
        );

        const current = optionSelect.value;

        optionSelect.innerHTML =
            '<option value="">選択肢を選択</option>';

        if (card) {
            card.querySelectorAll(
                '.options-box .option-row'
            ).forEach(function(row) {
                const input = row.querySelector('input');
                const id = input.dataset.id || input.value;

                if (!id) return;

                const o = document.createElement('option');
                o.value = id;
                o.textContent = input.value || '未入力';
                optionSelect.appendChild(o);
            });
        }

        if (current) {
            optionSelect.value = current;
        }
    }

    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('condition-question')) {
            updateConditionOptions(e.target);
        }
    });

    function renumberEditor() {
        const numbering =
            document.getElementById('numbering')?.value || 'global';

        let global = 1;

        document.querySelectorAll('.group-card')
        .forEach(function(group, gi) {
            let local = 1;

            group.querySelectorAll('.question-card')
            .forEach(function(q) {
                const n = q.querySelector('.question-number');

                if (numbering === 'group') {
                    n.textContent =
                        'Q' + (gi + 1) + '-' + local;
                } else {
                    n.textContent = 'Q' + global;
                }

                global++;
                local++;
            });
        });
    }

    function prepareEditor() {
        const input = document.getElementById('groups_json');

        if (!input) return;

        input.value = JSON.stringify(collectGroups());
    }

    document.addEventListener('input', function(e) {
        if (
            e.target.closest('.question-card') ||
            e.target.classList.contains('group-title')
        ) {
            rebuildConditions();
        }
    });
    </script>
    </body>
    </html>
    <?php
}

/* =========================================================
 * LIST
 * ========================================================= */

if ($screen === 'list') {

    $search = trim((string)($_GET['q'] ?? ''));
    $filter = (string)($_GET['status'] ?? 'all');
    $sort = (string)($_GET['sort'] ?? 'updated_desc');

    $filtered = array_values(array_filter(
        $surveys,
        function($s) use ($search, $filter) {
            if (
                $search !== '' &&
                mb_stripos(
                    (string)$s['title'],
                    $search
                ) === false
            ) {
                return false;
            }

            if (
                $filter !== 'all' &&
                ($s['display_status'] ?? '') !== $filter
            ) {
                return false;
            }

            return true;
        }
    ));

    usort(
        $filtered,
        function($a, $b) use ($sort) {
            $ra = surveyResponses($a['id']);
            $rb = surveyResponses($b['id']);

            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        $a['updated_at'] ?? '',
                        $b['updated_at'] ?? ''
                    ),

                'answers_desc' =>
                    count($rb) <=> count($ra),

                'answers_asc' =>
                    count($ra) <=> count($rb),

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
                    )
            };
        }
    );

    pageStart('アンケート一覧');

    ?>

    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <div class="muted">
                アンケートの作成・公開・集計・送信を管理します。
            </div>
        </div>

        <a class="btn btn-primary"
           href="index.php?screen=edit">
            ＋ 新規作成
        </a>
    </div>

    <div class="card">
        <form method="get" class="searchbar">
            <input type="hidden"
                   name="screen"
                   value="list">

            <input type="search"
                   name="q"
                   value="<?= h($search) ?>"
                   placeholder="タイトルを検索">

            <select name="status">
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
                <option value="ended"
                    <?= $filter === 'ended' ? 'selected' : '' ?>>
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

            <button class="btn btn-primary">
                検索
            </button>
        </form>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>タイトル</th>
                    <th>作成日</th>
                    <th>更新日</th>
                    <th>開始日時</th>
                    <th>終了日時</th>
                    <th>ステータス</th>
                    <th>回答数</th>
                    <th>操作</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$filtered): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty">
                                アンケートがありません。
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($filtered as $s): ?>
                    <?php
                    $count = count(
                        surveyResponses($s['id'])
                    );
                    $st = $s['display_status'];
                    ?>

                    <tr>
                        <td>
                            <strong><?= h($s['title']) ?></strong>
                        </td>

                        <td><?= h($s['created_at']) ?></td>
                        <td><?= h($s['updated_at']) ?></td>
                        <td><?= h($s['start_at']) ?></td>
                        <td><?= h($s['end_at']) ?></td>

                        <td>
                            <span class="badge <?= h(statusClass($st)) ?>">
                                <?= h(statusLabel($st)) ?>
                            </span>
                        </td>

                        <td><?= $count ?></td>

                        <td>
                            <div class="actions">
                                <a class="btn btn-secondary btn-small"
                                   href="index.php?screen=edit&id=<?= h($s['id']) ?>">
                                    編集
                                </a>

                                <a class="btn btn-secondary btn-small"
                                   href="index.php?screen=preview&id=<?= h($s['id']) ?>">
                                    プレビュー
                                </a>

                                <a class="btn btn-secondary btn-small"
                                   href="index.php?screen=analytics&id=<?= h($s['id']) ?>">
                                    集計
                                </a>

                                <a class="btn btn-secondary btn-small"
                                   href="index.php?screen=send&id=<?= h($s['id']) ?>">
                                    送信
                                </a>

                                <?php if ($st === 'draft'): ?>
                                    <form method="post">
                                        <input type="hidden"
                                               name="action"
                                               value="change_status">
                                        <input type="hidden"
                                               name="id"
                                               value="<?= h($s['id']) ?>">
                                        <input type="hidden"
                                               name="to"
                                               value="published">

                                        <button class="btn btn-success btn-small"
                                                onclick="return confirmAction('公開しますか？')">
                                            公開
                                        </button>
                                    </form>
                                <?php elseif ($st === 'published'): ?>
                                    <form method="post">
                                        <input type="hidden"
                                               name="action"
                                               value="change_status">
                                        <input type="hidden"
                                               name="id"
                                               value="<?= h($s['id']) ?>">
                                        <input type="hidden"
                                               name="to"
                                               value="stopped">

                                        <button class="btn btn-warning btn-small"
                                                onclick="return confirmAction('停止しますか？')">
                                            停止
                                        </button>
                                    </form>
                                <?php elseif ($st === 'stopped'): ?>
                                    <form method="post">
                                        <input type="hidden"
                                               name="action"
                                               value="change_status">
                                        <input type="hidden"
                                               name="id"
                                               value="<?= h($s['id']) ?>">
                                        <input type="hidden"
                                               name="to"
                                               value="published">

                                        <button class="btn btn-success btn-small"
                                                onclick="return confirmAction('再開しますか？')">
                                            再開
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="post">
                                    <input type="hidden"
                                           name="action"
                                           value="duplicate_survey">
                                    <input type="hidden"
                                           name="id"
                                           value="<?= h($s['id']) ?>">

                                    <button class="btn btn-secondary btn-small">
                                        複製
                                    </button>
                                </form>

                                <form method="post">
                                    <input type="hidden"
                                           name="action"
                                           value="delete_survey">
                                    <input type="hidden"
                                           name="id"
                                           value="<?= h($s['id']) ?>">

                                    <button class="btn btn-danger btn-small"
                                            onclick="return confirmAction('このアンケートを削除しますか？')">
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
    pageEnd();
    exit;
}

/* =========================================================
 * EDIT
 * ========================================================= */

if ($screen === 'edit') {

    $survey = null;

    if ($id !== '') {
        $survey = findById($surveys, $id);
    }

    if (!$survey) {
        $survey = emptySurvey();
    }

    $survey = normalizeSurvey($survey);

    pageStart(
        $id === '' ? 'アンケート作成' : 'アンケート編集'
    );

    ?>

    <div class="page-title">
        <div>
            <h1>
                <?= $id === '' ? 'アンケート作成' : 'アンケート編集' ?>
            </h1>
            <span class="badge <?= h(
                statusClass(surveyStatus($survey))
            ) ?>">
                <?= h(statusLabel(surveyStatus($survey))) ?>
            </span>
        </div>

        <div class="actions">
            <a class="btn btn-secondary"
               href="index.php?screen=list"
               onclick="return confirmAction('編集内容を破棄しますか？')">
                キャンセル
            </a>

            <?php if ($survey['id']): ?>
                <a class="btn btn-secondary"
                   href="index.php?screen=preview&id=<?= h($survey['id']) ?>">
                    プレビュー
                </a>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" onsubmit="prepareEditor()">
        <input type="hidden"
               name="action"
               value="save_survey">

        <input type="hidden"
               name="id"
               value="<?= h($survey['id']) ?>">

        <input type="hidden"
               name="groups_json"
               id="groups_json">

        <div class="card">
            <h2>基本情報</h2>

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
                           value="<?= h(
                               $survey['start_at']
                           ) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">
                        終了日時
                    </label>

                    <input type="datetime-local"
                           name="end_at"
                           value="<?= h(
                               $survey['end_at']
                           ) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">
                    質問番号の採番方式
                </label>

                <select name="numbering"
                        id="numbering"
                        onchange="renumberEditor()">
                    <option value="global"
                        <?= $survey['numbering'] === 'global'
                            ? 'selected'
                            : '' ?>>
                        アンケート全体で通番（Q1、Q2、Q3...）
                    </option>

                    <option value="group"
                        <?= $survey['numbering'] === 'group'
                            ? 'selected'
                            : '' ?>>
                        グループ単位（Q1-1、Q1-2、Q2-1...）
                    </option>
                </select>
            </div>
        </div>

        <div class="card">
            <div class="page-title">
                <h2>グループ・質問</h2>

                <button type="button"
                        class="btn btn-primary"
                        onclick="addGroup()">
                    ＋ グループを追加
                </button>
            </div>

            <div id="groups">
                <?php foreach ($survey['groups'] as $g): ?>
                    <div class="group-card"
                         data-id="<?= h($g['id']) ?>">

                        <div class="group-header">
                            <span class="drag">☷</span>

                            <input class="group-title"
                                   type="text"
                                   value="<?= h($g['title']) ?>">

                            <button type="button"
                                    class="btn btn-danger btn-small"
                                    onclick="this.closest('.group-card').remove();renumberGroups()">
                                グループ削除
                            </button>
                        </div>

                        <div class="group-body">
                            <div class="questions">

                                <?php foreach ($g['questions'] as $q): ?>
                                    <div class="question-card"
                                         data-id="<?= h($q['id']) ?>">

                                        <div class="actions"
                                             style="justify-content:space-between;margin-bottom:12px">
                                            <strong class="question-number">
                                                <?= h($q['number']) ?>
                                            </strong>

                                            <button type="button"
                                                    class="btn btn-danger btn-small"
                                                    onclick="this.closest('.question-card').remove();rebuildConditions()">
                                                質問削除
                                            </button>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">
                                                質問文
                                            </label>

                                            <textarea class="q-text"><?= h(
                                                $q['text']
                                            ) ?></textarea>
                                        </div>

                                        <div class="grid-2">
                                            <div class="form-group">
                                                <label class="form-label">
                                                    回答形式
                                                </label>

                                                <select class="q-type"
                                                        onchange="toggleQuestionOptions(this)">
                                                    <option value="single"
                                                        <?= $q['type'] === 'single'
                                                            ? 'selected'
                                                            : '' ?>>
                                                        単一選択
                                                    </option>

                                                    <option value="multiple"
                                                        <?= $q['type'] === 'multiple'
                                                            ? 'selected'
                                                            : '' ?>>
                                                        複数選択
                                                    </option>

                                                    <option value="text"
                                                        <?= $q['type'] === 'text'
                                                            ? 'selected'
                                                            : '' ?>>
                                                        自由記述
                                                    </option>
                                                </select>
                                            </div>

                                            <div class="form-group">
                                                <label class="checkbox"
                                                       style="margin-top:30px">
                                                    <input class="q-required"
                                                           type="checkbox"
                                                        <?= $q['required']
                                                            ? 'checked'
                                                            : '' ?>>
                                                    必須回答
                                                </label>
                                            </div>
                                        </div>

                                        <div class="options-area"
                                             style="<?= $q['type'] === 'text'
                                                ? 'display:none'
                                                : '' ?>">
                                            <label class="form-label">
                                                選択肢
                                            </label>

                                            <div class="options-box">
                                                <?php foreach ($q['options'] as $o): ?>
                                                    <div class="option-row">
                                                        <input type="text"
                                                               data-id="<?= h($o['id']) ?>"
                                                               value="<?= h($o['label']) ?>">

                                                        <button type="button"
                                                                class="btn btn-danger btn-small"
                                                                onclick="this.parentElement.remove()">
                                                            削除
                                                        </button>
                                                    </div>
                                                <?php endforeach; ?>

                                                <button type="button"
                                                        class="btn btn-secondary btn-small"
                                                        onclick="addOption(this)">
                                                    ＋ 選択肢
                                                </button>
                                            </div>
                                        </div>

                                        <div class="conditions-area"
                                             style="<?= $q['type'] !== 'single'
                                                ? 'display:none'
                                                : '' ?>;margin-top:18px">

                                            <label class="form-label">
                                                条件分岐
                                            </label>

                                            <div class="conditions-box">

                                                <?php foreach ($q['conditions'] as $c): ?>
                                                    <div class="condition-row">
                                                        <select class="condition-question">
                                                            <option value="">
                                                                質問を選択
                                                            </option>
                                                        </select>

                                                        <select class="condition-option">
                                                            <option value="">
                                                                選択肢を選択
                                                            </option>
                                                        </select>

                                                        <button type="button"
                                                                class="btn btn-danger btn-small"
                                                                onclick="this.parentElement.remove()">
                                                            削除
                                                        </button>
                                                    </div>
                                                <?php endforeach; ?>

                                                <button type="button"
                                                        class="btn btn-secondary btn-small"
                                                        onclick="addCondition(this)">
                                                    ＋ 条件を追加
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                            </div>

                            <button type="button"
                                    class="btn btn-secondary btn-small"
                                    onclick="addQuestion(this)">
                                ＋ 質問を追加
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sticky">
            <div class="actions">
                <a class="btn btn-secondary"
                   href="index.php?screen=list">
                    キャンセル
                </a>

                <button class="btn btn-primary">
                    保存して一覧へ
                </button>
            </div>
        </div>
    </form>

    <script>
    document.addEventListener('DOMContentLoaded',function(){
        document.querySelectorAll('.question-card').forEach(function(card){
            if(!card.dataset.id){
                card.dataset.id =
                    'question-' +
                    Date.now() +
                    '-' +
                    Math.random().toString(16).slice(2);
            }
        });

        document.querySelectorAll('.option-row input').forEach(function(input){
            if(!input.dataset.id){
                input.dataset.id =
                    'option-' +
                    Date.now() +
                    '-' +
                    Math.random().toString(16).slice(2);
            }
        });

        document.querySelectorAll('.group-card').forEach(function(group){
            if(!group.dataset.id){
                group.dataset.id =
                    'group-' +
                    Date.now() +
                    '-' +
                    Math.random().toString(16).slice(2);
            }
        });

        rebuildConditions();
    });
    </script>

    <?php
    pageEnd();
    exit;
}

/* =========================================================
 * PREVIEW
 * ========================================================= */

if ($screen === 'preview') {

    $survey = findById($surveys, $id);

    if (!$survey) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    pageStart('プレビュー');

    ?>

    <div class="page-title">
        <div>
            <h1><?= h($survey['title']) ?></h1>
            <div class="muted">
                プレビュー
            </div>
        </div>

        <div class="actions">
            <a class="btn btn-secondary"
               href="index.php?screen=edit&id=<?= h($id) ?>">
                編集へ戻る
            </a>
        </div>
    </div>

    <?php if ($survey['description'] !== ''): ?>
        <div class="card">
            <?= nl2br(h($survey['description'])) ?>
        </div>
    <?php endif; ?>

    <?php foreach ($survey['groups'] as $g): ?>

        <div class="answer-card">
            <h2><?= h($g['title']) ?></h2>

            <?php foreach ($g['questions'] as $q): ?>

                <div class="form-group">
                    <label class="form-label">
                        <?= h($q['number']) ?>.
                        <?= h($q['text']) ?>

                        <?php if ($q['required']): ?>
                            <span class="required">*</span>
                        <?php endif; ?>
                    </label>

                    <?php if ($q['type'] === 'text'): ?>

                        <textarea disabled
                                  placeholder="自由記述"></textarea>

                    <?php elseif ($q['type'] === 'single'): ?>

                        <?php foreach ($q['options'] as $o): ?>
                            <label class="checkbox"
                                   style="display:flex;margin:8px 0">
                                <input type="radio" disabled>
                                <?= h($o['label']) ?>
                            </label>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <?php foreach ($q['options'] as $o): ?>
                            <label class="checkbox"
                                   style="display:flex;margin:8px 0">
                                <input type="checkbox" disabled>
                                <?= h($o['label']) ?>
                            </label>
                        <?php endforeach; ?>

                    <?php endif; ?>
                </div>

            <?php endforeach; ?>
        </div>

    <?php endforeach; ?>

    <?php
    pageEnd();
    exit;
}

/* =========================================================
 * ANSWER
 * ========================================================= */

if ($screen === 'answer') {

    $survey = findById($surveys, $id);

    if (!$survey || surveyStatus($survey) !== 'published') {

        pageStart('回答できません', false);

        ?>
        <div class="answer-card">
            <h1>回答できません</h1>
            <p>
                このアンケートは現在公開されていません。
            </p>
        </div>
        <?php

        pageEnd();
        exit;
    }

    $answers = $_SESSION['answer_data'] ?? [];
    $errors = $_SESSION['answer_errors'] ?? [];

    unset($_SESSION['answer_errors']);

    $visible = visibleQuestions(
        $survey,
        is_array($answers) ? $answers : []
    );

    pageStart($survey['title'], false);

    ?>

    <div class="answer-card">
        <h1><?= h($survey['title']) ?></h1>

        <?php if ($survey['description'] !== ''): ?>
            <p><?= nl2br(h($survey['description'])) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <strong>入力内容を確認してください。</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h(strip_tags($error)) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden"
               name="action"
               value="answer_confirm">

        <input type="hidden"
               name="survey_id"
               value="<?= h($id) ?>">

        <?php foreach ($survey['groups'] as $g): ?>

            <?php
            $groupQuestions = array_values(
                array_filter(
                    $visible,
                    fn($q) =>
                        in_array(
                            $q['id'],
                            array_column(
                                $g['questions'],
                                'id'
                            ),
                            true
                        )
                )
            );

            if (!$groupQuestions) {
                continue;
            }
            ?>

            <div class="answer-card">
                <h2><?= h($g['title']) ?></h2>

                <?php foreach ($groupQuestions as $q): ?>

                    <?php
                    $value = $answers[$q['id']] ?? '';
                    ?>

                    <div class="form-group">
                        <label class="form-label">
                            <?= h($q['number']) ?>.
                            <?= h($q['text']) ?>

                            <?php if ($q['required']): ?>
                                <span class="required">*</span>
                            <?php endif; ?>
                        </label>

                        <?php if ($q['type'] === 'text'): ?>

                            <textarea
                                name="answer[<?= h($q['id']) ?>]"
                                <?= $q['required']
                                    ? 'required'
                                    : '' ?>><?= h(
                                        is_string($value)
                                            ? $value
                                            : ''
                                    ) ?></textarea>

                        <?php elseif ($q['type'] === 'single'): ?>

                            <?php foreach ($q['options'] as $o): ?>
                                <label class="checkbox"
                                       style="display:flex;margin:9px 0">
                                    <input type="radio"
                                           name="answer[<?= h($q['id']) ?>]"
                                           value="<?= h($o['id']) ?>"
                                        <?= (string)$value ===
                                            (string)$o['id']
                                            ? 'checked'
                                            : '' ?>
                                        <?= $q['required']
                                            ? 'required'
                                            : '' ?>>
                                    <?= h($o['label']) ?>
                                </label>
                            <?php endforeach; ?>

                        <?php else: ?>

                            <?php
                            $values = is_array($value)
                                ? $value
                                : [];
                            ?>

                            <?php foreach ($q['options'] as $o): ?>
                                <label class="checkbox"
                                       style="display:flex;margin:9px 0">
                                    <input type="checkbox"
                                           name="answer[<?= h($q['id']) ?>][]"
                                           value="<?= h($o['id']) ?>"
                                        <?= in_array(
                                            $o['id'],
                                            $values,
                                            true
                                        )
                                            ? 'checked'
                                            : '' ?>>
                                    <?= h($o['label']) ?>
                                </label>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </div>

                <?php endforeach; ?>
            </div>

        <?php endforeach; ?>

        <div class="sticky">
            <button class="btn btn-primary"
                    style="width:100%">
                回答内容を確認する
            </button>
        </div>
    </form>

    <?php
    pageEnd();
    exit;
}

/* =========================================================
 * CONFIRM
 * ========================================================= */

if ($screen === 'confirm') {

    $survey = findById($surveys, $id);
    $answers = $_SESSION['answer_data'] ?? [];

    if (!$survey || !is_array($answers)) {
        redirect(
            'index.php?screen=answer&id=' .
            rawurlencode($id)
        );
    }

    $visible = visibleQuestions($survey, $answers);

    pageStart('回答確認', false);

    ?>

    <div class="answer-card">
        <h1>回答確認</h1>
        <p>
            以下の内容で送信します。
        </p>
    </div>

    <?php foreach ($survey['groups'] as $g): ?>

        <?php
        $questions = array_values(
            array_filter(
                $visible,
                fn($q) =>
                    in_array(
                        $q['id'],
                        array_column(
                            $g['questions'],
                            'id'
                        ),
                        true
                    )
            )
        );

        if (!$questions) {
            continue;
        }
        ?>

        <div class="answer-card">
            <h2><?= h($g['title']) ?></h2>

            <?php foreach ($questions as $q): ?>

                <?php
                $value = $answers[$q['id']] ?? '';

                if (is_array($value)) {
                    $labels = [];

                    foreach ($q['options'] as $o) {
                        if (in_array($o['id'], $value, true)) {
                            $labels[] = $o['label'];
                        }
                    }

                    $display = implode('、', $labels);
                } else {
                    $display = $value;

                    foreach ($q['options'] as $o) {
                        if ((string)$o['id'] === (string)$value) {
                            $display = $o['label'];
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

                    <div style="
                        padding:12px;
                        background:#f8fafc;
                        border-radius:7px;
                        white-space:pre-wrap
                    ">
                        <?= h($display) ?>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>

    <?php endforeach; ?>

    <div class="answer-card">
        <form method="post"
              style="display:inline">
            <input type="hidden"
                   name="action"
                   value="save_answer">

            <input type="hidden"
                   name="survey_id"
                   value="<?= h($id) ?>">

            <div class="form-group">
                <label class="form-label">
                    お名前（任意）
                </label>
                <input type="text"
                       name="respondent_name">
            </div>

            <div class="form-group">
                <label class="form-label">
                    メールアドレス（任意）
                </label>
                <input type="email"
                       name="respondent_email">
            </div>

            <div class="actions">
                <a class="btn btn-secondary"
                   href="index.php?screen=answer&id=<?= h($id) ?>">
                    修正する
                </a>

                <button class="btn btn-primary">
                    回答を送信する
                </button>
            </div>
        </form>
    </div>

    <?php
    pageEnd();
    exit;
}

/* =========================================================
 * COMPLETE
 * ========================================================= */

if ($screen === 'complete') {

    $survey = findById($surveys, $id);

    pageStart('回答完了', false);

    ?>

    <div class="answer-card"
         style="text-align:center">
        <h1>回答ありがとうございました</h1>

        <p>
            回答を正常に受け付けました。
        </p>

        <?php if ($survey): ?>
            <p class="muted">
                <?= h($survey['title']) ?>
            </p>
        <?php endif; ?>
    </div>

    <?php
    pageEnd();
    exit;
}

/* =========================================================
 * ANALYTICS
 * ========================================================= */

if ($screen === 'analytics') {

    $survey = findById($surveys, $id);

    if (!$survey) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    $responses = surveyResponses($id);
    $customerIds = [];

    foreach ($mailLogs as $log) {
        if (
            ($log['survey_id'] ?? '') === $id &&
            ($log['status'] ?? '') === 'success'
        ) {
            $customerIds[] = $log['customer_id'] ?? '';
        }
    }

    $customerIds = array_values(
        array_unique(
            array_filter($customerIds)
        )
    );

    $registered = 0;
    $unregistered = 0;

    foreach ($responses as $r) {
        if (!empty($r['customer_id'])) {
            $registered++;
        } else {
            $unregistered++;
        }
    }

    $sent = count($customerIds);
    $answered = count($responses);

    $rate = $sent > 0
        ? round($answered / $sent * 100, 1)
        : 0;

    pageStart('回答集計・分析');

    ?>

    <div class="page-title">
        <div>
            <h1>回答集計・分析</h1>
            <div class="muted">
                対象アンケート：
                <strong><?= h($survey['title']) ?></strong>
            </div>
        </div>

        <div class="actions">
            <a class="btn btn-secondary"
               href="index.php?screen=csv&id=<?= h($id) ?>">
                CSV
            </a>

            <a class="btn btn-secondary"
               href="index.php?screen=pdf&id=<?= h($id) ?>">
                PDF
            </a>
        </div>
    </div>

    <div class="grid-3">
        <div class="stat">
            <div class="label">送信対象者数</div>
            <div class="value"><?= $sent ?></div>
        </div>

        <div class="stat">
            <div class="label">回答数</div>
            <div class="value"><?= $answered ?></div>
        </div>

        <div class="stat">
            <div class="label">未登録回答</div>
            <div class="value"><?= $unregistered ?></div>
        </div>

        <div class="stat">
            <div class="label">未回答数</div>
            <div class="value">
                <?= max(0, $sent - $answered) ?>
            </div>
        </div>

        <div class="stat">
            <div class="label">回答率</div>
            <div class="value"><?= $rate ?>%</div>
        </div>
    </div>

    <?php if ($answered === 0): ?>

        <div class="card" style="margin-top:20px">
            <div class="empty">
                現在、回答データはありません
            </div>
        </div>

    <?php else: ?>

        <?php foreach ($survey['groups'] as $g): ?>

            <div class="card">
                <h2><?= h($g['title']) ?></h2>

                <?php foreach ($g['questions'] as $q): ?>

                    <div style="
                        padding:16px 0;
                        border-bottom:1px solid var(--border)
                    ">
                        <h3>
                            <?= h($q['number']) ?>.
                            <?= h($q['text']) ?>
                        </h3>

                        <?php if ($q['type'] === 'text'): ?>

                            <div class="muted">
                                自由記述回答
                            </div>

                            <div style="margin-top:10px">
                                <?php foreach ($responses as $r): ?>
                                    <?php
                                    $v =
                                        $r['answers'][$q['id']]
                                        ?? '';

                                    if ($v === '') {
                                        continue;
                                    }
                                    ?>

                                    <div style="
                                        padding:10px;
                                        margin-bottom:6px;
                                        background:#f8fafc;
                                        border-radius:6px
                                    ">
                                        <?= nl2br(h((string)$v)) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                        <?php else: ?>

                            <?php
                            $total = count($responses);
                            ?>

                            <?php foreach ($q['options'] as $o): ?>

                                <?php
                                $count = 0;

                                foreach ($responses as $r) {
                                    $v =
                                        $r['answers'][$q['id']]
                                        ?? null;

                                    if (is_array($v)) {
                                        if (in_array(
                                            $o['id'],
                                            $v,
                                            true
                                        )) {
                                            $count++;
                                        }
                                    } elseif (
                                        (string)$v ===
                                        (string)$o['id']
                                    ) {
                                        $count++;
                                    }
                                }

                                $percent = $total > 0
                                    ? round(
                                        $count /
                                        $total *
                                        100,
                                        1
                                    )
                                    : 0;
                                ?>

                                <div style="margin:12px 0">
                                    <div style="
                                        display:flex;
                                        justify-content:space-between
                                    ">
                                        <span>
                                            <?= h($o['label']) ?>
                                        </span>

                                        <strong>
                                            <?= $count ?>
                                            （<?= $percent ?>%）
                                        </strong>
                                    </div>

                                    <div style="
                                        height:9px;
                                        background:#e2e8f0;
                                        border-radius:999px;
                                        overflow:hidden;
                                        margin-top:5px
                                    ">
                                        <div style="
                                            width:<?= $percent ?>%;
                                            height:100%;
                                            background:#2563eb
                                        "></div>
                                    </div>
                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>
                    </div>

                <?php endforeach; ?>
            </div>

        <?php endforeach; ?>

    <?php endif; ?>

    <?php
    pageEnd();
    exit;
}

/* =========================================================
 * SEND
 * ========================================================= */

if ($screen === 'send') {

    $survey = findById($surveys, $id);

    if (!$survey) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    $search = trim((string)($_GET['q'] ?? ''));

    $targetCustomers = array_values(
        array_filter(
            $customers,
            function($c) use ($search) {
                if ($search === '') {
                    return true;
                }

                $haystack = implode(' ', [
                    $c['organization'] ?? '',
                    $c['name'] ?? '',
                    $c['email'] ?? '',
                    $c['department'] ?? '',
                    $c['phone'] ?? '',
                    $c['address'] ?? ''
                ]);

                return mb_stripos(
                    $haystack,
                    $search
                ) !== false;
            }
        )
    );

    $history = array_values(
        array_filter(
            $mailLogs,
            fn($l) =>
                ($l['survey_id'] ?? '') === $id
        )
    );

    usort(
        $history,
        fn($a,$b) =>
            strcmp(
                $b['created_at'] ?? '',
                $a['created_at'] ?? ''
            )
    );

    $defaultSubject =
        $survey['title'] . ' ご回答のお願い';

    $defaultBody =
        "{顧客名} 様\n\n" .
        "アンケートへのご回答をお願いいたします。\n\n" .
        "{アンケートURL}\n\n" .
        "よろしくお願いいたします。";

    pageStart('顧客選択・メール送信');

    ?>

    <div class="page-title">
        <div>
            <h1>顧客選択・メール送信</h1>
            <div class="muted">
                対象アンケート：
                <strong><?= h($survey['title']) ?></strong>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>顧客検索</h2>

        <form method="get" class="searchbar">
            <input type="hidden"
                   name="screen"
                   value="send">

            <input type="hidden"
                   name="id"
                   value="<?= h($id) ?>">

            <input type="search"
                   name="q"
                   value="<?= h($search) ?>"
                   placeholder="組織名・氏名・メールアドレス等">

            <button class="btn btn-primary">
                検索
            </button>
        </form>
    </div>

    <form method="post">

        <input type="hidden"
               name="action"
               value="send_mail">

        <input type="hidden"
               name="survey_id"
               value="<?= h($id) ?>">

        <div class="card">
            <h2>顧客選択</h2>

            <?php if (!$targetCustomers): ?>

                <div class="empty">
                    顧客データがありません。
                    kintone設定から顧客情報を同期してください。
                </div>

            <?php else: ?>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>
                                <input type="checkbox"
                                       onclick="
                                       document.querySelectorAll('.customer-check')
                                       .forEach(x=>x.checked=this.checked)
                                       ">
                            </th>
                            <th>組織名</th>
                            <th>氏名</th>
                            <th>メール</th>
                            <th>部署</th>
                            <th>電話</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($targetCustomers as $c): ?>
                            <tr>
                                <td>
                                    <input class="customer-check"
                                           type="checkbox"
                                           name="customers[]"
                                           value="<?= h($c['id']) ?>">
                                </td>

                                <td><?= h($c['organization'] ?? '') ?></td>
                                <td><?= h($c['name'] ?? '') ?></td>
                                <td><?= h($c['email'] ?? '') ?></td>
                                <td><?= h($c['department'] ?? '') ?></td>
                                <td><?= h($c['phone'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </div>

        <div class="card">
            <h2>メール作成</h2>

            <div class="form-group">
                <label class="form-label">送信種別</label>

                <select name="mail_type">
                    <option value="send">送信</option>
                    <option value="resend">再送</option>
                    <option value="remind">リマインド</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">件名</label>

                <input type="text"
                       name="subject"
                       value="<?= h($defaultSubject) ?>"
                       required>
            </div>

            <div class="form-group">
                <label class="form-label">本文</label>

                <textarea name="body"
                          required><?= h($defaultBody) ?></textarea>

                <div class="form-help">
                    使用可能な変数：
                    <code>{顧客名}</code>
                    <code>{アンケートURL}</code>
                </div>
            </div>

            <button class="btn btn-primary"
                    onclick="return confirmAction('選択した顧客へメールを送信しますか？')">
                メール送信
            </button>
        </div>
    </form>

    <div class="card">
        <h2>送信履歴</h2>

        <?php if (!$history): ?>

            <div class="empty">
                送信履歴はありません。
            </div>

        <?php else: ?>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>日時</th>
                        <th>種別</th>
                        <th>顧客</th>
                        <th>メール</th>
                        <th>状態</th>
                        <th>エラー</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($history as $log): ?>
                        <tr>
                            <td><?= h($log['created_at']) ?></td>
                            <td><?= h($log['type']) ?></td>
                            <td><?= h($log['customer_name']) ?></td>
                            <td><?= h($log['email']) ?></td>
                            <td>
                                <span class="badge <?= $log['status'] === 'success'
                                    ? 'badge-success'
                                    : 'badge-danger' ?>">
                                    <?= $log['status'] === 'success'
                                        ? '成功'
                                        : '失敗' ?>
                                </span>
                            </td>
                            <td><?= h($log['error'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>

    <?php
    pageEnd();
    exit;
}

/* =========================================================
 * KINTONE
 * ========================================================= */

if ($screen === 'kintone') {

    $cfg = kintoneConfig();
    $fields = $_SESSION['kintone_fields'] ?? [];

    pageStart('kintone連携設定');

    ?>

    <div class="page-title">
        <div>
            <h1>kintone連携設定</h1>
            <div class="muted">
                顧客情報の取得元
            </div>
        </div>
    </div>

    <form method="post">
        <input type="hidden"
               name="action"
               value="save_kintone">

        <div class="card">
            <h2>接続設定</h2>

            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">
                        サブドメイン
                    </label>
                    <input type="text"
                           name="subdomain"
                           value="<?= h($cfg['subdomain']) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        顧客管理アプリID
                    </label>
                    <input type="number"
                           name="app_id"
                           value="<?= h($cfg['app_id']) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        ログイン名
                    </label>
                    <input type="text"
                           name="login"
                           value="<?= h($cfg['login']) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        パスワード
                    </label>
                    <input type="password"
                           name="password"
                           placeholder="変更しない場合は空欄">
                </div>

                <div class="form-group">
                    <label class="form-label">
                        Proxy
                    </label>
                    <input type="text"
                           name="proxy"
                           value="<?= h($cfg['proxy']) ?>"
                           placeholder="http://proxy:8080">
                </div>

                <div class="form-group">
                    <label class="checkbox"
                           style="margin-top:30px">
                        <input type="checkbox"
                               name="verify_ssl"
                            <?= !empty($cfg['verify_ssl'])
                                ? 'checked'
                                : '' ?>>
                        SSL証明書を検証する
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>顧客項目マッピング</h2>

            <div class="grid-2">
                <?php
                $mapping = $cfg['mapping'] ?? [];
                ?>

                <div class="form-group">
                    <label class="form-label">組織名</label>
                    <input type="text"
                           name="map_organization"
                           value="<?= h(
                               $mapping['organization'] ?? ''
                           ) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">氏名</label>
                    <input type="text"
                           name="map_name"
                           value="<?= h(
                               $mapping['name'] ?? ''
                           ) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">メールアドレス</label>
                    <input type="text"
                           name="map_email"
                           value="<?= h(
                               $mapping['email'] ?? ''
                           ) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">部署名</label>
                    <input type="text"
                           name="map_department"
                           value="<?= h(
                               $mapping['department'] ?? ''
                           ) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">電話番号</label>
                    <input type="text"
                           name="map_phone"
                           value="<?= h(
                               $mapping['phone'] ?? ''
                           ) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">
                    住所項目
                </label>

                <?php
                $addressFields =
                    $mapping['address'] ?? [];

                for ($i = 0; $i < 5; $i++):
                ?>
                    <input type="text"
                           name="map_address[]"
                           value="<?= h(
                               $addressFields[$i] ?? ''
                           ) ?>"
                           style="margin-bottom:8px"
                           placeholder="例：郵便番号 / 都道府県 / 市区町村 / 番地">
                <?php endfor; ?>
            </div>
        </div>

        <div class="card">
            <div class="actions">
                <button class="btn btn-primary">
                    設定保存
                </button>
            </div>
        </div>
    </form>

    <div class="card">
        <h2>接続・取得操作</h2>

        <div class="actions">

            <form method="post">
                <input type="hidden"
                       name="action"
                       value="kintone_test">

                <button class="btn btn-secondary">
                    接続テスト
                </button>
            </form>

            <form method="post">
                <input type="hidden"
                       name="action"
                       value="kintone_fields">

                <button class="btn btn-secondary">
                    項目一覧再取得
                </button>
            </form>

            <form method="post">
                <input type="hidden"
                       name="action"
                       value="kintone_sync">

                <button class="btn btn-primary"
                        onclick="return confirmAction('kintoneから顧客情報を同期しますか？')">
                    顧客情報同期
                </button>
            </form>

        </div>
    </div>

    <?php if ($fields): ?>

        <div class="card">
            <h2>kintone項目一覧</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>フィールドコード</th>
                        <th>ラベル</th>
                        <th>タイプ</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($fields as $code => $field): ?>
                        <tr>
                            <td>
                                <code><?= h($code) ?></code>
                            </td>
                            <td>
                                <?= h($field['label'] ?? '') ?>
                            </td>
                            <td>
                                <?= h($field['type'] ?? '') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

    <div class="card">
        <h2>同期済み顧客</h2>

        <div class="stat">
            <div class="label">顧客件数</div>
            <div class="value">
                <?= count($customers) ?>
            </div>
        </div>
    </div>

    <?php
    pageEnd();
    exit;
}

/* =========================================================
 * MAIL SETTINGS
 * ========================================================= */

if ($screen === 'mail') {

    $cfg = smtpConfig();

    pageStart('メールサーバ設定');

    ?>

    <div class="page-title">
        <div>
            <h1>メールサーバ設定</h1>
            <div class="muted">
                SMTPサーバー設定
            </div>
        </div>
    </div>

    <form method="post">

        <input type="hidden"
               name="action"
               value="save_smtp">

        <div class="card">
            <h2>SMTP設定</h2>

            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">
                        SMTPサーバー
                    </label>

                    <input type="text"
                           name="host"
                           value="<?= h($cfg['host']) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        SMTPポート
                    </label>

                    <input type="number"
                           name="port"
                           min="1"
                           max="65535"
                           value="<?= h($cfg['port']) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        暗号化方式
                    </label>

                    <select name="encryption">
                        <option value="none"
                            <?= $cfg['encryption'] === 'none'
                                ? 'selected'
                                : '' ?>>
                            なし
                        </option>

                        <option value="tls"
                            <?= $cfg['encryption'] === 'tls'
                                ? 'selected'
                                : '' ?>>
                            STARTTLS
                        </option>

                        <option value="ssl"
                            <?= $cfg['encryption'] === 'ssl'
                                ? 'selected'
                                : '' ?>>
                            SSL/TLS
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="checkbox"
                           style="margin-top:30px">
                        <input type="checkbox"
                               name="auth"
                            <?= !empty($cfg['auth'])
                                ? 'checked'
                                : '' ?>>
                        SMTP認証を使用
                    </label>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        SMTPユーザー名
                    </label>

                    <input type="text"
                           name="username"
                           value="<?= h($cfg['username']) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">
                        SMTPパスワード
                    </label>

                    <input type="password"
                           name="password"
                           placeholder="変更しない場合は空欄">
                </div>

                <div class="form-group">
                    <label class="form-label">
                        送信元メールアドレス
                    </label>

                    <input type="email"
                           name="from_email"
                           value="<?= h($cfg['from_email']) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        送信元名
                    </label>

                    <input type="text"
                           name="from_name"
                           value="<?= h($cfg['from_name']) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">
                        返信先メールアドレス
                    </label>

                    <input type="email"
                           name="reply_to"
                           value="<?= h($cfg['reply_to']) ?>">
                </div>
            </div>

            <button class="btn btn-primary">
                設定保存
            </button>
        </div>
    </form>

    <div class="card">
        <h2>SMTP操作</h2>

        <div class="actions">

            <form method="post">
                <input type="hidden"
                       name="action"
                       value="smtp_test">

                <button class="btn btn-secondary">
                    SMTP接続確認
                </button>
            </form>

            <form method="post"
                  style="display:flex;gap:8px;flex:1">
                <input type="hidden"
                       name="action"
                       value="smtp_test_mail">

                <input type="email"
                       name="test_email"
                       placeholder="テスト送信先メールアドレス"
                       required>

                <button class="btn btn-primary">
                    テストメール送信
                </button>
            </form>

        </div>
    </div>

    <?php
    pageEnd();
    exit;
}

/* =========================================================
 * Fallback
 * ========================================================= */

redirect('index.php?screen=list');
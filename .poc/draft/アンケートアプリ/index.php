<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * PHP 8.5 / Apache 2.4
 * DBなし / cURLなし / index.php 1ファイル
 */

date_default_timezone_set('Asia/Tokyo');

const APP_NAME = 'アンケート管理';
const DATA_DIR = __DIR__ . '/../survey-data';

const STATUS_DRAFT     = 'draft';
const STATUS_PUBLISHED = 'published';
const STATUS_STOPPED   = 'stopped';
const STATUS_ENDED     = 'ended';

const TYPE_SINGLE = 'single';
const TYPE_MULTI  = 'multi';
const TYPE_TEXT   = 'text';

const MAX_TITLE = 200;
const MAX_DESC  = 5000;
const MAX_TEXT  = 10000;

/* =========================================================
 * セッション / CSRF
 * ======================================================= */

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
);

session_set_cookie_params([
    'httponly' => true,
    'secure'   => $isHttps,
    'samesite' => 'Lax',
    'path'     => '/',
]);

session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string
{
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';

    if (
        !is_string($token)
        || !isset($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('CSRF検証に失敗しました。');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' .
        e(csrf_token()) .
        '">';
}

/* =========================================================
 * 共通
 * ======================================================= */

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $screen = 'list', array $params = []): never
{
    $query = array_merge(['screen' => $screen], $params);

    $safe = [];

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $safe[$key] = (string)$value;
    }

    header('Location: index.php?' . http_build_query($safe));
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uuid(): string
{
    return bin2hex(random_bytes(8));
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type'    => $type,
    ];
}

function consume_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function post_array(string $key): array
{
    $value = $_POST[$key] ?? [];
    return is_array($value) ? $value : [];
}

function valid_id(?string $id): bool
{
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) === 1;
}

function validate_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function format_dt(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $time = strtotime($value);

    return $time === false ? e($value) : date('Y/m/d H:i', $time);
}

function status_label(string $status): string
{
    return match ($status) {
        STATUS_DRAFT     => '下書き',
        STATUS_PUBLISHED => '公開中',
        STATUS_STOPPED   => '停止',
        STATUS_ENDED     => '終了',
        default          => '不明',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        STATUS_PUBLISHED => 'success',
        STATUS_STOPPED   => 'warning',
        STATUS_ENDED     => 'muted',
        default          => 'draft',
    };
}

function answer_type_label(string $type): string
{
    return match ($type) {
        TYPE_SINGLE => '単一選択',
        TYPE_MULTI  => '複数選択',
        TYPE_TEXT   => '自由記述',
        default     => '不明',
    };
}

/* =========================================================
 * サーバー側ファイル保存
 * ======================================================= */

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0700, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存ディレクトリを作成できません。');
        }
    }

    @chmod(DATA_DIR, 0700);
}

function data_file(string $name): string
{
    ensure_data_dir();

    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
        throw new InvalidArgumentException('不正なデータファイル名です。');
    }

    return DATA_DIR . DIRECTORY_SEPARATOR . $name . '.json';
}

function read_json(string $name, mixed $default = null): mixed
{
    $file = data_file($name);

    if (!is_file($file)) {
        return $default;
    }

    $fp = fopen($file, 'rb');

    if ($fp === false) {
        throw new RuntimeException('データを読み込めません。');
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException('データをロックできません。');
        }

        $json = stream_get_contents($fp);

        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($json === false || $json === '') {
        return $default;
    }

    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('保存データが壊れています。');
    }

    return $data;
}

function write_json(string $name, mixed $data): void
{
    $file = data_file($name);
    $tmp  = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    $fp = fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データをロックできません。');
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException('データを書き込めません。');
        }

        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    @chmod($tmp, 0600);

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データを保存できません。');
    }
}

function surveys(): array
{
    return read_json('surveys', []);
}

function save_surveys(array $items): void
{
    write_json('surveys', array_values($items));
}

function answers(): array
{
    return read_json('answers', []);
}

function save_answers(array $items): void
{
    write_json('answers', array_values($items));
}

function customers(): array
{
    return read_json('customers', []);
}

function save_customers(array $items): void
{
    write_json('customers', array_values($items));
}

function send_logs(): array
{
    return read_json('send_logs', []);
}

function save_send_logs(array $items): void
{
    write_json('send_logs', array_values($items));
}

function app_settings(): array
{
    return read_json('settings', [
        'kintone' => [
            'subdomain'     => '',
            'app_id'        => '',
            'login'         => '',
            'password'      => '',
            'proxy'         => '',
            'verify_ssl'    => true,
            'address_fields'=> [],
        ],
        'smtp' => [
            'server'        => '',
            'port'          => 587,
            'encryption'    => 'tls',
            'auth'          => true,
            'username'      => '',
            'password'      => '',
            'from_email'    => '',
            'from_name'     => '',
            'reply_to'      => '',
            'status'        => '未設定',
        ],
    ]);
}

function save_settings(array $settings): void
{
    write_json('settings', $settings);
}

/* =========================================================
 * アンケート
 * ======================================================= */

function find_survey(string $id): ?array
{
    foreach (surveys() as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_answer_count(string $surveyId): int
{
    $count = 0;

    foreach (answers() as $answer) {
        if (($answer['survey_id'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

function update_automatic_status(array &$survey): void
{
    if (
        ($survey['status'] ?? '') === STATUS_PUBLISHED
        && !empty($survey['end_at'])
        && strtotime((string)$survey['end_at']) !== false
        && strtotime((string)$survey['end_at']) < time()
    ) {
        $survey['status'] = STATUS_ENDED;
        $survey['updated_at'] = now();
    }
}

function update_all_automatic_statuses(): void
{
    $items = surveys();
    $changed = false;

    foreach ($items as &$survey) {
        $old = $survey['status'] ?? '';
        update_automatic_status($survey);

        if ($old !== ($survey['status'] ?? '')) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        save_surveys($items);
    }
}

function normalize_survey(array $survey): array
{
    $survey['groups'] ??= [];

    foreach ($survey['groups'] as &$group) {
        $group['id'] ??= uuid();
        $group['title'] = (string)($group['title'] ?? 'グループ');
        $group['questions'] ??= [];

        foreach ($group['questions'] as &$question) {
            $question['id'] ??= uuid();
            $question['text'] = (string)($question['text'] ?? '');
            $question['type'] = in_array(
                $question['type'] ?? TYPE_SINGLE,
                [TYPE_SINGLE, TYPE_MULTI, TYPE_TEXT],
                true
            ) ? $question['type'] : TYPE_SINGLE;

            $question['required'] = !empty($question['required']);
            $question['options'] ??= [];
            $question['branches'] ??= [];
        }

        unset($question);
    }

    unset($group);

    recalculate_question_numbers($survey);

    return $survey;
}

function recalculate_question_numbers(array &$survey): void
{
    $mode = $survey['numbering'] ?? 'global';

    $global = 1;
    $groupIndex = 1;

    foreach ($survey['groups'] as &$group) {
        $local = 1;

        foreach ($group['questions'] as &$question) {
            if ($mode === 'group') {
                $question['number'] = 'Q' . $groupIndex . '-' . $local;
                $local++;
            } else {
                $question['number'] = 'Q' . $global;
                $global++;
            }
        }

        unset($question);

        $groupIndex++;
    }

    unset($group);
}

/* =========================================================
 * 外部HTTP
 * cURLを使用しない
 * ======================================================= */

function http_request(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
    bool $verifySsl = true,
    ?string $proxy = null
): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('外部URLが不正です。');
    }

    $parts = parse_url($url);

    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('外部URLを解析できません。');
    }

    $contextHeaders = array_merge([
        'User-Agent: SurveyApp/1.0',
        'Accept: application/json',
    ], $headers);

    $options = [
        'http' => [
            'method'          => strtoupper($method),
            'header'          => implode("\r\n", $contextHeaders),
            'content'         => $body ?? '',
            'timeout'         => 15,
            'ignore_errors'   => true,
            'protocol_version'=> 1.1,
        ],
        'ssl' => [
            'verify_peer'      => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed'=> !$verifySsl,
            'capture_peer_cert'=> false,
        ],
    ];

    if ($proxy !== null && $proxy !== '') {
        if (!preg_match('/^[A-Za-z0-9.-]+:\d{1,5}$/', $proxy)) {
            throw new RuntimeException('Proxyはhost:port形式で指定してください。');
        }

        $options['http']['proxy'] = 'tcp://' . $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        throw new RuntimeException('外部サービスへの接続に失敗しました。');
    }

    $status = 0;

    if (isset($http_response_header[0])
        && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)
    ) {
        $status = (int)$m[1];
    }

    return [
        'status'  => $status,
        'body'    => $response,
        'headers' => $http_response_header ?? [],
    ];
}

/* =========================================================
 * kintone
 * ======================================================= */

function kintone_config(): array
{
    $settings = app_settings();
    return $settings['kintone'];
}

function kintone_base_url(): string
{
    $cfg = kintone_config();

    $subdomain = trim((string)($cfg['subdomain'] ?? ''));

    $subdomain = preg_replace(
        '/[^A-Za-z0-9-]/',
        '',
        $subdomain
    );

    if ($subdomain === '') {
        throw new RuntimeException('kintoneサブドメインが設定されていません。');
    }

    return 'https://' . $subdomain . '.cybozu.com';
}

function kintone_headers(): array
{
    $cfg = kintone_config();

    $login = (string)($cfg['login'] ?? '');
    $password = (string)($cfg['password'] ?? '');

    if ($login === '' || $password === '') {
        throw new RuntimeException('kintoneログイン情報が設定されていません。');
    }

    $authorization = base64_encode($login . ':' . $password);

    return [
        'X-Cybozu-Authorization: ' . $authorization,
        'Content-Type: application/json',
    ];
}

function kintone_request(
    string $path,
    string $method = 'GET',
    ?array $payload = null
): array {
    $cfg = kintone_config();

    $url = kintone_base_url() . $path;

    $body = $payload === null
        ? null
        : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $response = http_request(
        $url,
        $method,
        kintone_headers(),
        $body,
        !empty($cfg['verify_ssl']),
        trim((string)($cfg['proxy'] ?? '')) ?: null
    );

    $data = json_decode($response['body'], true);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        $message = is_array($data)
            ? ($data['message'] ?? 'kintone APIエラー')
            : 'kintone APIエラー';

        throw new RuntimeException(
            'kintone接続に失敗しました。HTTP ' .
            $response['status'] . ' / ' . $message
        );
    }

    return is_array($data) ? $data : [];
}

function kintone_test(): string
{
    $cfg = kintone_config();

    if (empty($cfg['app_id'])) {
        throw new RuntimeException('顧客管理アプリIDを入力してください。');
    }

    kintone_request(
        '/k/v1/app.json?id=' . rawurlencode((string)$cfg['app_id']),
        'GET'
    );

    return 'kintoneへの接続に成功しました。';
}

function kintone_fields(): array
{
    $cfg = kintone_config();

    if (empty($cfg['app_id'])) {
        throw new RuntimeException('顧客管理アプリIDを入力してください。');
    }

    $data = kintone_request(
        '/k/v1/app/form/fields.json?app=' . rawurlencode((string)$cfg['app_id']),
        'GET'
    );

    return $data['properties'] ?? [];
}

function kintone_sync(): int
{
    $cfg = kintone_config();

    if (empty($cfg['app_id'])) {
        throw new RuntimeException('顧客管理アプリIDを入力してください。');
    }

    /*
     * 一般的な顧客フィールドコードを想定。
     * 実際の環境では設定画面の項目マッピングを利用する。
     */
    $mapping = $cfg['mapping'] ?? [
        'organization' => '組織名',
        'name'         => '氏名',
        'email'        => 'メールアドレス',
        'department'   => '部署名',
        'phone'        => '電話番号',
        'address'      => [],
    ];

    $records = [];

    $offset = 0;

    do {
        $query = 'limit 500 offset ' . $offset;

        $data = kintone_request(
            '/k/v1/records.json?' . http_build_query([
                'app'   => $cfg['app_id'],
                'query' => $query,
            ]),
            'GET'
        );

        $batch = $data['records'] ?? [];

        foreach ($batch as $record) {
            $get = static function (string $field) use ($record): string {
                return isset($record[$field]['value'])
                    ? (string)$record[$field]['value']
                    : '';
            };

            $addressValues = [];

            foreach (($mapping['address'] ?? []) as $field) {
                if (isset($record[$field]['value'])) {
                    $addressValues[] = (string)$record[$field]['value'];
                }
            }

            $records[] = [
                'id'           => 'kintone-' . sha1(json_encode($record)),
                'organization' => $get((string)($mapping['organization'] ?? '')),
                'name'         => $get((string)($mapping['name'] ?? '')),
                'email'        => $get((string)($mapping['email'] ?? '')),
                'department'   => $get((string)($mapping['department'] ?? '')),
                'phone'        => $get((string)($mapping['phone'] ?? '')),
                'address'      => implode(' ', $addressValues),
                'updated_at'   => now(),
            ];
        }

        $offset += count($batch);

        if (count($batch) < 500) {
            break;
        }
    } while (true);

    save_customers($records);

    return count($records);
}

/* =========================================================
 * SMTP
 * ======================================================= */

function smtp_read($socket): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

function smtp_expect($socket, array $codes): void
{
    $response = smtp_read($socket);

    if (!preg_match('/^(\d{3})/', $response, $m)) {
        throw new RuntimeException('SMTP応答を解析できません。');
    }

    $code = (int)$m[1];

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTPサーバーからエラーが返されました。');
    }
}

function smtp_command($socket, string $command, array $codes): void
{
    fwrite($socket, $command . "\r\n");
    smtp_expect($socket, $codes);
}

function smtp_send_mail(
    string $to,
    string $subject,
    string $body
): void {
    $settings = app_settings()['smtp'];

    $server = trim((string)($settings['server'] ?? ''));
    $port = (int)($settings['port'] ?? 587);
    $encryption = (string)($settings['encryption'] ?? 'tls');

    if ($server === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('SMTP設定が不正です。');
    }

    if (!validate_email($to)) {
        throw new RuntimeException('宛先メールアドレスが不正です。');
    }

    $host = $server;

    if ($encryption === 'ssl') {
        $host = 'ssl://' . $server;
    }

    $socket = @fsockopen(
        $host,
        $port,
        $errno,
        $errstr,
        15
    );

    if (!$socket) {
        throw new RuntimeException('SMTPサーバーへ接続できません。');
    }

    stream_set_timeout($socket, 15);

    try {
        smtp_expect($socket, [220]);

        smtp_command($socket, 'EHLO localhost', [250]);

        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException('SMTP TLS接続に失敗しました。');
            }

            smtp_command($socket, 'EHLO localhost', [250]);
        }

        if (!empty($settings['auth'])) {
            $username = (string)($settings['username'] ?? '');
            $password = (string)($settings['password'] ?? '');

            if ($username === '' || $password === '') {
                throw new RuntimeException('SMTP認証情報が未設定です。');
            }

            smtp_command($socket, 'AUTH LOGIN', [334]);

            fwrite($socket, base64_encode($username) . "\r\n");
            smtp_expect($socket, [334]);

            fwrite($socket, base64_encode($password) . "\r\n");
            smtp_expect($socket, [235]);
        }

        $from = (string)($settings['from_email'] ?? '');

        if (!validate_email($from)) {
            throw new RuntimeException('送信元メールアドレスが不正です。');
        }

        smtp_command(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_command($socket, 'DATA', [354]);

        $fromName = (string)($settings['from_name'] ?? '');

        $encodedSubject = '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $headers = [
            'From: ' .
            ($fromName !== ''
                ? '=?UTF-8?B?' . base64_encode($fromName) . '?= '
                : '') .
            '<' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        $payload = implode("\r\n", $headers) .
            "\r\n\r\n" .
            rtrim(chunk_split(base64_encode($body), 76, "\r\n")) .
            "\r\n.";

        fwrite($socket, $payload . "\r\n");
        smtp_expect($socket, [250]);

        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

/* =========================================================
 * CSV
 * ======================================================= */

function output_csv(array $rows, string $filename): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($filename) . '"'
    );

    $out = fopen('php://output', 'wb');

    if ($out === false) {
        exit;
    }

    // Excel向けUTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");

    foreach ($rows as $row) {
        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}

/* =========================================================
 * PDF
 *
 * 外部ライブラリなしで最低限のPDFを生成。
 * 日本語フォント埋め込みは行わないため、実運用では
 * TCPDF等の導入を推奨。
 * ======================================================= */

function output_simple_pdf(string $title, array $lines): never
{
    $content = "BT\n/F1 12 Tf\n50 790 Td\n";

    $content .= '(' .
        preg_replace('/[()\\\\]/', '\\\\$0', $title) .
        ") Tj\n0 -20 Td\n";

    foreach ($lines as $line) {
        $line = preg_replace('/[()\\\\]/', '\\\\$0', (string)$line);
        $content .= '(' . $line . ") Tj\n0 -18 Td\n";
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
        '<< /Length ' . strlen($content) . " >>\nstream\n" .
        $content .
        "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $num = $i + 1;
        $offsets[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" .
            $object .
            "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .=
        "trailer\n<< /Size " .
        (count($objects) + 1) .
        " /Root 1 0 R >>\n" .
        "startxref\n" .
        $xref .
        "\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="analytics.pdf"');

    echo $pdf;
    exit;
}

/* =========================================================
 * 回答分岐
 * ======================================================= */

function visible_questions(array $survey, array $answers): array
{
    $result = [];

    $answerMap = [];

    foreach ($answers as $answer) {
        $answerMap[$answer['question_id']] = $answer['value'];
    }

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $visible = true;

            /*
             * 自分より前の単一選択質問の分岐を評価。
             * branch[target_question_id] が null / 空の場合は通常表示。
             */
            foreach ($survey['groups'] as $g2) {
                foreach ($g2['questions'] as $source) {
                    if (
                        ($source['type'] ?? '') !== TYPE_SINGLE
                        || !isset($source['branches'])
                        || !array_key_exists($source['id'], $answerMap)
                    ) {
                        continue;
                    }

                    $selected = $answerMap[$source['id']];

                    foreach ($source['branches'] as $branch) {
                        if (
                            ($branch['target'] ?? '') === ($question['id'] ?? '')
                            && ($branch['option'] ?? '') !== ''
                            && $selected === ($branch['option'] ?? '')
                        ) {
                            $visible = true;
                        }
                    }
                }
            }

            /*
             * 明示的に分岐されていない質問は表示。
             * target指定がある場合、該当条件がないものを隠す。
             */
            $hasIncomingBranch = false;
            $matchedIncoming = false;

            foreach ($survey['groups'] as $g2) {
                foreach ($g2['questions'] as $source) {
                    foreach (($source['branches'] ?? []) as $branch) {
                        if (($branch['target'] ?? '') === $question['id']) {
                            $hasIncomingBranch = true;

                            if (
                                isset($answerMap[$source['id']])
                                && $answerMap[$source['id']] === ($branch['option'] ?? '')
                            ) {
                                $matchedIncoming = true;
                            }
                        }
                    }
                }
            }

            if ($hasIncomingBranch) {
                $visible = $matchedIncoming;
            }

            if ($visible) {
                $result[] = $question;
            }
        }
    }

    return $result;
}

/* =========================================================
 * POST処理
 * ======================================================= */

update_all_automatic_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = post_string('action');

    try {
        switch ($action) {

            /* ---------------------------------------------
             * アンケート保存
             * ------------------------------------------- */

            case 'save_survey':
                $id = post_string('id');

                $title = post_string('title');
                $description = post_string('description');
                $startAt = post_string('start_at');
                $endAt = post_string('end_at');
                $numbering = post_string('numbering', 'global');

                if ($title === '') {
                    throw new RuntimeException('アンケートタイトルは必須です。');
                }

                if (mb_strlen($title) > MAX_TITLE) {
                    throw new RuntimeException('タイトルが長すぎます。');
                }

                if (mb_strlen($description) > MAX_DESC) {
                    throw new RuntimeException('説明が長すぎます。');
                }

                if (!in_array($numbering, ['global', 'group'], true)) {
                    throw new RuntimeException('採番方式が不正です。');
                }

                if ($startAt !== '' && strtotime($startAt) === false) {
                    throw new RuntimeException('開始日時が不正です。');
                }

                if ($endAt !== '' && strtotime($endAt) === false) {
                    throw new RuntimeException('終了日時が不正です。');
                }

                if (
                    $startAt !== ''
                    && $endAt !== ''
                    && strtotime($startAt) > strtotime($endAt)
                ) {
                    throw new RuntimeException(
                        '開始日時は終了日時より前にしてください。'
                    );
                }

                $items = surveys();

                $existingIndex = null;

                foreach ($items as $index => $item) {
                    if (($item['id'] ?? '') === $id && $id !== '') {
                        $existingIndex = $index;
                        break;
                    }
                }

                if ($existingIndex === null) {
                    $survey = [
                        'id'         => 'survey-' . uuid(),
                        'title'      => $title,
                        'description'=> $description,
                        'start_at'   => $startAt,
                        'end_at'     => $endAt,
                        'status'     => STATUS_DRAFT,
                        'numbering'  => $numbering,
                        'groups'     => [],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } else {
                    $survey = $items[$existingIndex];

                    $survey['title'] = $title;
                    $survey['description'] = $description;
                    $survey['start_at'] = $startAt;
                    $survey['end_at'] = $endAt;
                    $survey['numbering'] = $numbering;
                    $survey['updated_at'] = now();
                }

                /*
                 * JSONで質問構造を送信。
                 * UIからの改変をサーバー側で再検証。
                 */
                $groupsJson = post_string('groups_json', '[]');
                $groups = json_decode($groupsJson, true);

                if (!is_array($groups)) {
                    throw new RuntimeException('質問データが不正です。');
                }

                $survey['groups'] = [];

                foreach ($groups as $group) {
                    $newGroup = [
                        'id'        => valid_id($group['id'] ?? null)
                            ? $group['id']
                            : uuid(),
                        'title'     => mb_substr(
                            (string)($group['title'] ?? 'グループ'),
                            0,
                            200
                        ),
                        'questions' => [],
                    ];

                    foreach (($group['questions'] ?? []) as $question) {
                        $type = $question['type'] ?? TYPE_SINGLE;

                        if (!in_array(
                            $type,
                            [TYPE_SINGLE, TYPE_MULTI, TYPE_TEXT],
                            true
                        )) {
                            $type = TYPE_SINGLE;
                        }

                        $newQuestion = [
                            'id'       => valid_id($question['id'] ?? null)
                                ? $question['id']
                                : uuid(),
                            'text'     => mb_substr(
                                (string)($question['text'] ?? ''),
                                0,
                                MAX_TEXT
                            ),
                            'type'     => $type,
                            'required' => !empty($question['required']),
                            'options'  => [],
                            'branches' => [],
                        ];

                        foreach (($question['options'] ?? []) as $option) {
                            $option = trim((string)$option);

                            if ($option !== '') {
                                $newQuestion['options'][] =
                                    mb_substr($option, 0, 500);
                            }
                        }

                        foreach (($question['branches'] ?? []) as $branch) {
                            $option = trim((string)($branch['option'] ?? ''));
                            $target = trim((string)($branch['target'] ?? ''));

                            if ($option !== '' && valid_id($target)) {
                                $newQuestion['branches'][] = [
                                    'option' => $option,
                                    'target' => $target,
                                ];
                            }
                        }

                        $newGroup['questions'][] = $newQuestion;
                    }

                    $survey['groups'][] = $newGroup;
                }

                $survey = normalize_survey($survey);

                if ($existingIndex === null) {
                    $items[] = $survey;
                } else {
                    $items[$existingIndex] = $survey;
                }

                save_surveys($items);

                flash('アンケートを保存しました。');
                redirect('list');
                break;

            /* ---------------------------------------------
             * 削除
             * ------------------------------------------- */

            case 'delete_survey':
                $id = post_string('id');

                if (!valid_id($id)) {
                    throw new RuntimeException('アンケートIDが不正です。');
                }

                $items = surveys();
                $found = false;

                foreach ($items as $index => $item) {
                    if (($item['id'] ?? '') === $id) {
                        unset($items[$index]);
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    throw new RuntimeException('アンケートが存在しません。');
                }

                save_surveys($items);

                flash('アンケートを削除しました。');
                redirect('list');
                break;

            /* ---------------------------------------------
             * 複製
             * ------------------------------------------- */

            case 'duplicate_survey':
                $id = post_string('id');
                $survey = find_survey($id);

                if (!$survey) {
                    throw new RuntimeException('アンケートが存在しません。');
                }

                $copy = $survey;

                $copy['id'] = 'survey-' . uuid();
                $copy['title'] = $survey['title'] . '（コピー）';
                $copy['status'] = STATUS_DRAFT;
                $copy['created_at'] = now();
                $copy['updated_at'] = now();

                foreach ($copy['groups'] as &$group) {
                    $group['id'] = uuid();

                    foreach ($group['questions'] as &$question) {
                        $question['id'] = uuid();
                    }

                    unset($question);
                }

                unset($group);

                /*
                 * IDが変わったため分岐先も再構築。
                 * 単純化のため分岐はコピー時にクリア。
                 */
                foreach ($copy['groups'] as &$group) {
                    foreach ($group['questions'] as &$question) {
                        $question['branches'] = [];
                    }
                }

                unset($group, $question);

                $copy = normalize_survey($copy);

                $items = surveys();
                $items[] = $copy;
                save_surveys($items);

                flash('アンケートを複製しました。');
                redirect('list');
                break;

            /* ---------------------------------------------
             * 状態変更
             * ------------------------------------------- */

            case 'change_status':
                $id = post_string('id');
                $newStatus = post_string('new_status');

                if (!in_array(
                    $newStatus,
                    [STATUS_PUBLISHED, STATUS_STOPPED],
                    true
                )) {
                    throw new RuntimeException('状態変更が不正です。');
                }

                $items = surveys();
                $changed = false;

                foreach ($items as &$survey) {
                    if (($survey['id'] ?? '') !== $id) {
                        continue;
                    }

                    update_automatic_status($survey);

                    if (($survey['status'] ?? '') === STATUS_ENDED) {
                        throw new RuntimeException(
                            '終了したアンケートは変更できません。'
                        );
                    }

                    if (
                        $newStatus === STATUS_PUBLISHED
                        && empty($survey['end_at'])
                    ) {
                        throw new RuntimeException(
                            '公開するには終了日時を設定してください。'
                        );
                    }

                    $survey['status'] = $newStatus;
                    $survey['updated_at'] = now();

                    $changed = true;
                    break;
                }

                unset($survey);

                if (!$changed) {
                    throw new RuntimeException('アンケートが存在しません。');
                }

                save_surveys($items);

                flash('状態を変更しました。');
                redirect('list');
                break;

            /* ---------------------------------------------
             * 回答送信
             * ------------------------------------------- */

            case 'submit_answer':
                $surveyId = post_string('survey_id');
                $survey = find_survey($surveyId);

                if (!$survey) {
                    throw new RuntimeException('アンケートが存在しません。');
                }

                update_automatic_status($survey);

                if (($survey['status'] ?? '') !== STATUS_PUBLISHED) {
                    throw new RuntimeException(
                        '現在、このアンケートには回答できません。'
                    );
                }

                $inputAnswers = post_array('answer');

                $normalizedAnswers = [];

                foreach ($survey['groups'] as $group) {
                    foreach ($group['questions'] as $question) {
                        $qid = $question['id'];

                        if (!array_key_exists($qid, $inputAnswers)) {
                            $value = '';
                        } else {
                            $value = $inputAnswers[$qid];
                        }

                        if (is_array($value)) {
                            $value = array_values(array_map(
                                static fn($v) => mb_substr(
                                    trim((string)$v),
                                    0,
                                    MAX_TEXT
                                ),
                                $value
                            ));
                        } else {
                            $value = mb_substr(
                                trim((string)$value),
                                0,
                                MAX_TEXT
                            );
                        }

                        $requiredEmpty =
                            ($value === '')
                            || ($value === []);

                        if (
                            !empty($question['required'])
                            && $requiredEmpty
                        ) {
                            throw new RuntimeException(
                                '必須項目「' .
                                ($question['number'] ?? '') .
                                '」に回答してください。'
                            );
                        }

                        if (
                            $question['type'] === TYPE_SINGLE
                            && is_array($value)
                        ) {
                            throw new RuntimeException(
                                '回答形式が不正です。'
                            );
                        }

                        if (
                            $question['type'] === TYPE_MULTI
                            && !is_array($value)
                            && $value !== ''
                        ) {
                            throw new RuntimeException(
                                '回答形式が不正です。'
                            );
                        }

                        $normalizedAnswers[] = [
                            'question_id' => $qid,
                            'value'       => $value,
                        ];
                    }
                }

                /*
                 * 確認画面用セッション。
                 */
                $_SESSION['pending_answer'] = [
                    'survey_id' => $surveyId,
                    'answers'   => $normalizedAnswers,
                ];

                redirect('confirm', ['id' => $surveyId]);
                break;

            /* ---------------------------------------------
             * 回答確定
             * ------------------------------------------- */

            case 'confirm_answer':
                $pending = $_SESSION['pending_answer'] ?? null;

                if (!is_array($pending)) {
                    throw new RuntimeException(
                        '確認対象の回答がありません。'
                    );
                }

                $surveyId = (string)($pending['survey_id'] ?? '');
                $survey = find_survey($surveyId);

                if (!$survey) {
                    throw new RuntimeException('アンケートが存在しません。');
                }

                update_automatic_status($survey);

                if (($survey['status'] ?? '') !== STATUS_PUBLISHED) {
                    throw new RuntimeException(
                        '現在、このアンケートには回答できません。'
                    );
                }

                $answerRows = answers();

                $answerRows[] = [
                    'id'          => 'answer-' . uuid(),
                    'survey_id'   => $surveyId,
                    'answers'     => $pending['answers'],
                    'customer_id' => null,
                    'created_at'  => now(),
                ];

                save_answers($answerRows);

                unset($_SESSION['pending_answer']);

                redirect('complete', ['id' => $surveyId]);
                break;

            /* ---------------------------------------------
             * kintone設定保存
             * ------------------------------------------- */

            case 'save_kintone':
                $settings = app_settings();

                $proxy = post_string('proxy');

                if (
                    $proxy !== ''
                    && !preg_match('/^[A-Za-z0-9.-]+:\d{1,5}$/', $proxy)
                ) {
                    throw new RuntimeException(
                        'Proxyはhost:port形式で入力してください。'
                    );
                }

                $settings['kintone']['subdomain'] =
                    post_string('subdomain');

                $settings['kintone']['app_id'] =
                    post_string('app_id');

                $settings['kintone']['login'] =
                    post_string('login');

                /*
                 * パスワードは空欄なら既存値を維持。
                 */
                $password = post_string('password');

                if ($password !== '') {
                    $settings['kintone']['password'] = $password;
                }

                $settings['kintone']['proxy'] = $proxy;

                $settings['kintone']['verify_ssl'] =
                    isset($_POST['verify_ssl']);

                $settings['kintone']['mapping'] = [
                    'organization' => post_string('map_organization'),
                    'name'         => post_string('map_name'),
                    'email'        => post_string('map_email'),
                    'department'   => post_string('map_department'),
                    'phone'        => post_string('map_phone'),
                    'address'      => array_values(
                        array_filter(
                            post_array('map_address'),
                            'is_string'
                        )
                    ),
                ];

                save_settings($settings);

                flash('kintone設定を保存しました。');
                redirect('kintone');
                break;

            /* ---------------------------------------------
             * kintone接続確認
             * ------------------------------------------- */

            case 'test_kintone':
                $message = kintone_test();

                flash($message);
                redirect('kintone');
                break;

            /* ---------------------------------------------
             * kintone項目取得
             * ------------------------------------------- */

            case 'fetch_kintone_fields':
                $fields = kintone_fields();

                $_SESSION['kintone_fields'] = $fields;

                flash(
                    'kintoneの項目一覧を取得しました。'
                );

                redirect('kintone');
                break;

            /* ---------------------------------------------
             * kintone同期
             * ------------------------------------------- */

            case 'sync_kintone':
                $count = kintone_sync();

                flash(
                    '顧客情報を ' . $count . ' 件同期しました。'
                );

                redirect('kintone');
                break;

            /* ---------------------------------------------
             * SMTP保存
             * ------------------------------------------- */

            case 'save_smtp':
                $settings = app_settings();

                $server = post_string('server');
                $port = (int)post_string('port', '587');

                if ($server === '') {
                    throw new RuntimeException(
                        'SMTPサーバを入力してください。'
                    );
                }

                if ($port < 1 || $port > 65535) {
                    throw new RuntimeException(
                        'SMTPポートが不正です。'
                    );
                }

                $encryption = post_string('encryption');

                if (!in_array(
                    $encryption,
                    ['ssl', 'tls', 'none'],
                    true
                )) {
                    throw new RuntimeException(
                        '暗号化方式が不正です。'
                    );
                }

                $settings['smtp']['server'] = $server;
                $settings['smtp']['port'] = $port;
                $settings['smtp']['encryption'] = $encryption;
                $settings['smtp']['auth'] = isset($_POST['auth']);
                $settings['smtp']['username'] =
                    post_string('username');

                $password = post_string('password');

                if ($password !== '') {
                    $settings['smtp']['password'] = $password;
                }

                $settings['smtp']['from_email'] =
                    post_string('from_email');

                $settings['smtp']['from_name'] =
                    post_string('from_name');

                $settings['smtp']['reply_to'] =
                    post_string('reply_to');

                $settings['smtp']['status'] = '未設定';

                save_settings($settings);

                flash('メールサーバ設定を保存しました。');
                redirect('mail');
                break;

            /* ---------------------------------------------
             * SMTP接続確認
             * ------------------------------------------- */

            case 'test_smtp':
                $settings = app_settings()['smtp'];

                $server = (string)$settings['server'];
                $port = (int)$settings['port'];

                $host = $settings['encryption'] === 'ssl'
                    ? 'ssl://' . $server
                    : $server;

                $socket = @fsockopen(
                    $host,
                    $port,
                    $errno,
                    $errstr,
                    15
                );

                if (!$socket) {
                    $settings = app_settings();
                    $settings['smtp']['status'] = '接続できません';
                    save_settings($settings);

                    throw new RuntimeException(
                        'SMTPサーバーへ接続できません。'
                    );
                }

                stream_set_timeout($socket, 15);

                try {
                    smtp_expect($socket, [220]);
                    fwrite($socket, "EHLO localhost\r\n");
                    smtp_expect($socket, [250]);
                    fwrite($socket, "QUIT\r\n");
                } finally {
                    fclose($socket);
                }

                $settings = app_settings();
                $settings['smtp']['status'] = '接続確認済み';
                save_settings($settings);

                flash('SMTPサーバーへの接続に成功しました。');
                redirect('mail');
                break;

            /* ---------------------------------------------
             * テストメール
             * ------------------------------------------- */

            case 'test_email':
                $to = post_string('to');

                if (!validate_email($to)) {
                    throw new RuntimeException(
                        'テスト送信先メールアドレスが不正です。'
                    );
                }

                smtp_send_mail(
                    $to,
                    'アンケートアプリ テストメール',
                    "SMTP接続テストメールです。\n\n送信日時: " . now()
                );

                flash('テストメールを送信しました。');
                redirect('mail');
                break;

            /* ---------------------------------------------
             * 一括送信
             * ------------------------------------------- */

            case 'send_bulk':
                $surveyId = post_string('survey_id');
                $survey = find_survey($surveyId);

                if (!$survey) {
                    throw new RuntimeException(
                        'アンケートが存在しません。'
                    );
                }

                $selected = array_values(
                    array_filter(
                        post_array('customer_ids'),
                        static fn($v) => valid_id((string)$v)
                    )
                );

                if (!$selected) {
                    throw new RuntimeException(
                        '送信対象を選択してください。'
                    );
                }

                $subject = post_string('subject');
                $body = post_string('body');

                if ($subject === '' || $body === '') {
                    throw new RuntimeException(
                        '件名と本文は必須です。'
                    );
                }

                $baseUrl =
                    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
                        ? 'https'
                        : 'http')
                    . '://' .
                    ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                    dirname($_SERVER['SCRIPT_NAME']);

                $url = rtrim($baseUrl, '/') .
                    '/index.php?screen=answer&id=' .
                    rawurlencode($surveyId);

                $allCustomers = customers();
                $logs = send_logs();

                $success = 0;
                $failed = 0;

                foreach ($allCustomers as $customer) {
                    if (!in_array(
                        (string)($customer['id'] ?? ''),
                        $selected,
                        true
                    )) {
                        continue;
                    }

                    $email = (string)($customer['email'] ?? '');

                    if (!validate_email($email)) {
                        $failed++;

                        $logs[] = [
                            'id'          => 'send-' . uuid(),
                            'survey_id'   => $surveyId,
                            'customer_id' => $customer['id'] ?? null,
                            'email'       => $email,
                            'status'      => 'failed',
                            'message'     => 'メールアドレス不正',
                            'created_at'  => now(),
                        ];

                        continue;
                    }

                    $name = (string)($customer['name'] ?? '');

                    $mailBody = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [$name, $url],
                        $body
                    );

                    try {
                        smtp_send_mail(
                            $email,
                            $subject,
                            $mailBody
                        );

                        $success++;

                        $logs[] = [
                            'id'          => 'send-' . uuid(),
                            'survey_id'   => $surveyId,
                            'customer_id' => $customer['id'],
                            'email'       => $email,
                            'status'      => 'success',
                            'message'     => '',
                            'created_at'  => now(),
                        ];
                    } catch (Throwable $e) {
                        $failed++;

                        $logs[] = [
                            'id'          => 'send-' . uuid(),
                            'survey_id'   => $surveyId,
                            'customer_id' => $customer['id'],
                            'email'       => $email,
                            'status'      => 'failed',
                            'message'     => '送信失敗',
                            'created_at'  => now(),
                        ];
                    }
                }

                save_send_logs($logs);

                flash(
                    '送信完了：成功 ' .
                    $success .
                    ' 件 / 失敗 ' .
                    $failed .
                    ' 件'
                );

                redirect('send', ['id' => $surveyId]);
                break;

            /* ---------------------------------------------
             * 再送 / リマインド
             * ------------------------------------------- */

            case 'resend':
            case 'remind':
                $logId = post_string('log_id');

                $logs = send_logs();
                $targetLog = null;

                foreach ($logs as $log) {
                    if (($log['id'] ?? '') === $logId) {
                        $targetLog = $log;
                        break;
                    }
                }

                if (!$targetLog) {
                    throw new RuntimeException(
                        '送信履歴が存在しません。'
                    );
                }

                $survey = find_survey(
                    (string)$targetLog['survey_id']
                );

                if (!$survey) {
                    throw new RuntimeException(
                        'アンケートが存在しません。'
                    );
                }

                $customer = null;

                foreach (customers() as $item) {
                    if (
                        ($item['id'] ?? '') ===
                        ($targetLog['customer_id'] ?? '')
                    ) {
                        $customer = $item;
                        break;
                    }
                }

                if (!$customer) {
                    throw new RuntimeException(
                        '顧客情報が存在しません。'
                    );
                }

                $email = (string)$customer['email'];

                if (!validate_email($email)) {
                    throw new RuntimeException(
                        '顧客メールアドレスが不正です。'
                    );
                }

                $name = (string)($customer['name'] ?? '');

                $baseUrl =
                    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
                        ? 'https'
                        : 'http')
                    . '://' .
                    ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                    dirname($_SERVER['SCRIPT_NAME']);

                $url = rtrim($baseUrl, '/') .
                    '/index.php?screen=answer&id=' .
                    rawurlencode($survey['id']);

                $subject = $survey['title'];

                $body =
                    $name .
                    " 様\n\n" .
                    "アンケートへのご回答をお願いいたします。\n\n" .
                    $url;

                smtp_send_mail(
                    $email,
                    $subject,
                    $body
                );

                $logs[] = [
                    'id'          => 'send-' . uuid(),
                    'survey_id'   => $survey['id'],
                    'customer_id' => $customer['id'],
                    'email'       => $email,
                    'status'      => 'success',
                    'message'     => $action === 'remind'
                        ? 'リマインド'
                        : '再送',
                    'created_at'  => now(),
                ];

                save_send_logs($logs);

                flash(
                    $action === 'remind'
                        ? 'リマインドを送信しました。'
                        : '再送しました。'
                );

                redirect('send', ['id' => $survey['id']]);
                break;

            default:
                throw new RuntimeException(
                    '不明な処理です。'
                );
        }
    } catch (Throwable $e) {
        /*
         * 内部の認証情報・パスワード・Authorizationヘッダー等を
         * 画面へ出さない。
         */
        flash($e->getMessage(), 'error');

        $screen = post_string('return_screen', 'list');
        $id = post_string('return_id');

        if (
            $screen === 'answer'
            || $screen === 'confirm'
            || $screen === 'complete'
        ) {
            redirect($screen, $id !== '' ? ['id' => $id] : []);
        }

        redirect($screen, $id !== '' ? ['id' => $id] : []);
    }
}

/* =========================================================
 * GET / 画面
 * ======================================================= */

$screen = $_GET['screen'] ?? 'list';

if (!is_string($screen)) {
    $screen = 'list';
}

$allowedScreens = [
    'list',
    'edit',
    'preview',
    'send',
    'analytics',
    'kintone',
    'mail',
    'answer',
    'confirm',
    'complete',
];

if (!in_array($screen, $allowedScreens, true)) {
    $screen = 'list';
}

$id = $_GET['id'] ?? '';

if (!is_string($id)) {
    $id = '';
}

$flash = consume_flash();

/* =========================================================
 * HTML
 * ======================================================= */

function render_head(string $title, bool $admin = true): void
{
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> - <?= e(APP_NAME) ?></title>

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
    --shadow:0 4px 18px rgba(15,23,42,.08);
}

* {
    box-sizing:border-box;
}

body {
    margin:0;
    background:#f8fafc;
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
}

a {
    color:var(--primary);
    text-decoration:none;
}

a:hover {
    text-decoration:underline;
}

.header {
    background:#0f172a;
    color:#fff;
    padding:0 24px;
}

.header-inner {
    max-width:1400px;
    margin:auto;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand {
    font-weight:700;
    color:#fff;
    font-size:19px;
}

.nav {
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.nav a {
    color:#cbd5e1;
    padding:8px 12px;
    border-radius:7px;
    font-size:14px;
}

.nav a:hover {
    background:#1e293b;
    color:#fff;
    text-decoration:none;
}

.container {
    max-width:1400px;
    margin:0 auto;
    padding:28px 24px 60px;
}

.page-title {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    margin-bottom:22px;
}

.page-title h1 {
    margin:0;
    font-size:25px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}

.card h2 {
    margin:0 0 18px;
    font-size:19px;
}

.grid {
    display:grid;
    gap:16px;
}

.grid-2 {
    grid-template-columns:repeat(2,minmax(0,1fr));
}

.grid-3 {
    grid-template-columns:repeat(3,minmax(0,1fr));
}

@media(max-width:800px) {
    .grid-2,
    .grid-3 {
        grid-template-columns:1fr;
    }

    .header-inner {
        align-items:flex-start;
        flex-direction:column;
        padding:15px 0;
    }

    .container {
        padding:20px 14px 40px;
    }
}

label {
    display:block;
    font-size:14px;
    font-weight:600;
    margin-bottom:7px;
}

input,
select,
textarea {
    width:100%;
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px 12px;
    font:inherit;
    color:var(--text);
    background:#fff;
}

textarea {
    min-height:120px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus {
    outline:2px solid rgba(37,99,235,.18);
    border-color:var(--primary);
}

.form-row {
    margin-bottom:16px;
}

.checkbox {
    display:flex;
    align-items:center;
    gap:8px;
}

.checkbox input {
    width:auto;
}

.btn {
    border:0;
    border-radius:8px;
    padding:9px 14px;
    font:inherit;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    text-decoration:none;
}

.btn:hover {
    text-decoration:none;
}

.btn-primary {
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover {
    background:var(--primary-dark);
}

.btn-secondary {
    background:#e2e8f0;
    color:#334155;
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

.btn-sm {
    padding:6px 9px;
    font-size:13px;
}

.actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.alert {
    border-radius:9px;
    padding:13px 16px;
    margin-bottom:18px;
}

.alert-success {
    background:#dcfce7;
    color:#166534;
}

.alert-error {
    background:#fee2e2;
    color:#991b1b;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

th,
td {
    padding:12px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
    font-size:14px;
}

th {
    background:#f8fafc;
    font-weight:700;
    white-space:nowrap;
}

.badge {
    display:inline-flex;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge-success {
    color:#166534;
    background:#dcfce7;
}

.badge-warning {
    color:#92400e;
    background:#fef3c7;
}

.badge-draft {
    color:#475569;
    background:#e2e8f0;
}

.badge-muted {
    color:#64748b;
    background:#e2e8f0;
}

.searchbar {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.searchbar input {
    max-width:360px;
}

.tabs {
    display:flex;
    gap:5px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.tabs a {
    padding:8px 12px;
    border-radius:7px;
    background:#e2e8f0;
    color:#334155;
    font-size:14px;
}

.tabs a.active {
    background:var(--primary);
    color:#fff;
}

.question-card {
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    background:#fff;
    margin-bottom:12px;
}

.question-head {
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
    margin-bottom:12px;
}

.group-card {
    border:2px solid #e2e8f0;
    border-radius:12px;
    padding:16px;
    margin-bottom:18px;
    background:#f8fafc;
}

.group-card.dragging,
.question-card.dragging {
    opacity:.45;
}

.drag-handle {
    cursor:grab;
    color:#64748b;
    user-select:none;
}

.option-row {
    display:grid;
    grid-template-columns:1fr auto;
    gap:8px;
    margin-bottom:8px;
}

.preview {
    max-width:760px;
    margin:auto;
}

.metric {
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
    background:#fff;
}

.metric-label {
    color:var(--gray);
    font-size:13px;
}

.metric-value {
    font-size:28px;
    font-weight:800;
    margin-top:4px;
}

.progress {
    height:10px;
    background:#e2e8f0;
    border-radius:999px;
    overflow:hidden;
}

.progress > span {
    display:block;
    height:100%;
    background:var(--primary);
}

.muted {
    color:var(--gray);
}

.required {
    color:var(--danger);
}

.sticky-actions {
    position:sticky;
    bottom:0;
    z-index:10;
    background:rgba(248,250,252,.95);
    padding:12px 0;
    border-top:1px solid var(--border);
}

.empty {
    text-align:center;
    padding:50px 20px;
    color:var(--gray);
}
</style>
</head>
<body>

<?php if ($admin): ?>
<header class="header">
    <div class="header-inner">
        <a class="brand" href="index.php?screen=list">
            <?= e(APP_NAME) ?>
        </a>

        <nav class="nav">
            <a href="index.php?screen=list">アンケート一覧</a>
            <a href="index.php?screen=kintone">kintone設定</a>
            <a href="index.php?screen=mail">メール設定</a>
        </nav>
    </div>
</header>
<?php endif; ?>

<main class="container">
<?php
}

function render_foot(): void
{
    ?>
</main>

<script>
function confirmAction(message) {
    return window.confirm(message);
}

function submitConfirmed(form, message) {
    if (window.confirm(message)) {
        form.submit();
    }
}

document.addEventListener('keydown', function(e) {
    if (
        e.key === 'Enter' &&
        e.target &&
        e.target.matches('.search-input')
    ) {
        const form = e.target.closest('form');
        if (form) {
            form.submit();
        }
    }
});
</script>

</body>
</html>
<?php
}

/* =========================================================
 * Flash
 * ======================================================= */

function render_flash(?array $flash): void
{
    if (!$flash) {
        return;
    }

    $class = ($flash['type'] ?? '') === 'error'
        ? 'alert-error'
        : 'alert-success';

    ?>
    <div class="alert <?= e($class) ?>">
        <?= e($flash['message'] ?? '') ?>
    </div>
    <?php
}

/* =========================================================
 * LIST
 * ======================================================= */

if ($screen === 'list') {
    render_head('アンケート一覧');
    render_flash($flash);

    $items = surveys();

    $search = $_GET['q'] ?? '';
    $filter = $_GET['status'] ?? 'all';
    $sort = $_GET['sort'] ?? 'updated_desc';

    if (!is_string($search)) {
        $search = '';
    }

    if (!is_string($filter)) {
        $filter = 'all';
    }

    if (!is_string($sort)) {
        $sort = 'updated_desc';
    }

    $items = array_filter(
        $items,
        static function ($survey) use ($search, $filter): bool {
            if (
                $search !== ''
                && mb_stripos(
                    (string)($survey['title'] ?? ''),
                    $search
                ) === false
            ) {
                return false;
            }

            if ($filter !== 'all') {
                return ($survey['status'] ?? '') === $filter;
            }

            return true;
        }
    );

    usort(
        $items,
        static function ($a, $b) use ($sort): int {
            return match ($sort) {
                'updated_asc' => strcmp(
                    (string)$a['updated_at'],
                    (string)$b['updated_at']
                ),
                'answers_desc' => survey_answer_count($b['id'])
                    <=> survey_answer_count($a['id']),
                'answers_asc' => survey_answer_count($a['id'])
                    <=> survey_answer_count($b['id']),
                'start_desc' => strcmp(
                    (string)$b['start_at'],
                    (string)$a['start_at']
                ),
                'start_asc' => strcmp(
                    (string)$a['start_at'],
                    (string)$b['start_at']
                ),
                default => strcmp(
                    (string)$b['updated_at'],
                    (string)$a['updated_at']
                ),
            };
        }
    );
    ?>

<div class="page-title">
    <div>
        <h1>アンケート一覧</h1>
        <p class="muted">アンケートの作成・公開・集計・送信を管理します。</p>
    </div>

    <a class="btn btn-primary"
       href="index.php?screen=edit">
        ＋ 新規作成
    </a>
</div>

<div class="card">
    <form method="get">
        <input type="hidden" name="screen" value="list">

        <div class="searchbar">
            <input
                class="search-input"
                type="search"
                name="q"
                value="<?= e($search) ?>"
                placeholder="タイトルで検索"
            >

            <select name="status">
                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>
                    すべて
                </option>
                <option value="published" <?= $filter === 'published' ? 'selected' : '' ?>>
                    公開中
                </option>
                <option value="draft" <?= $filter === 'draft' ? 'selected' : '' ?>>
                    下書き
                </option>
                <option value="stopped" <?= $filter === 'stopped' ? 'selected' : '' ?>>
                    停止
                </option>
                <option value="ended" <?= $filter === 'ended' ? 'selected' : '' ?>>
                    終了
                </option>
            </select>

            <select name="sort">
                <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
                    更新日：新しい順
                </option>
                <option value="updated_asc" <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
                    更新日：古い順
                </option>
                <option value="answers_desc" <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
                    回答数：多い順
                </option>
                <option value="answers_asc" <?= $sort === 'answers_asc' ? 'selected' : '' ?>>
                    回答数：少ない順
                </option>
                <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>
                    開始日：新しい順
                </option>
                <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>
                    開始日：古い順
                </option>
            </select>

            <button class="btn btn-primary" type="submit">
                検索
            </button>
        </div>
    </form>
</div>

<div class="card">
<div class="table-wrap">

<?php if (!$items): ?>

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
    <th>アンケート期間</th>
    <th>ステータス</th>
    <th>回答数</th>
    <th>操作</th>
</tr>
</thead>

<tbody>
<?php foreach ($items as $survey): ?>

<?php
$status = $survey['status'];
$count = survey_answer_count($survey['id']);
?>

<tr>
    <td>
        <strong><?= e($survey['title']) ?></strong>
    </td>

    <td><?= e(format_dt($survey['created_at'])) ?></td>

    <td><?= e(format_dt($survey['updated_at'])) ?></td>

    <td>
        <?= e(format_dt($survey['start_at'] ?? '')) ?>
        〜
        <?= e(format_dt($survey['end_at'] ?? '')) ?>
    </td>

    <td>
        <span class="badge badge-<?= e(status_class($status)) ?>">
            <?= e(status_label($status)) ?>
        </span>
    </td>

    <td><?= e($count) ?></td>

    <td>
        <div class="actions">

            <a class="btn btn-secondary btn-sm"
               href="index.php?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
                確認・編集
            </a>

            <a class="btn btn-secondary btn-sm"
               href="index.php?screen=analytics&id=<?= rawurlencode($survey['id']) ?>">
                集計
            </a>

            <a class="btn btn-secondary btn-sm"
               href="index.php?screen=send&id=<?= rawurlencode($survey['id']) ?>">
                送信
            </a>

            <form method="post" style="display:inline"
                  onsubmit="return confirmAction('このアンケートを複製しますか？')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="duplicate_survey">
                <input type="hidden" name="id" value="<?= e($survey['id']) ?>">
                <input type="hidden" name="return_screen" value="list">

                <button class="btn btn-secondary btn-sm">
                    複製
                </button>
            </form>

            <form method="post" style="display:inline"
                  onsubmit="return confirmAction('このアンケートを削除しますか？')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_survey">
                <input type="hidden" name="id" value="<?= e($survey['id']) ?>">
                <input type="hidden" name="return_screen" value="list">

                <button class="btn btn-danger btn-sm">
                    削除
                </button>
            </form>

        </div>

        <?php if ($status !== STATUS_ENDED): ?>
        <div class="actions" style="margin-top:7px">

            <?php if ($status === STATUS_DRAFT || $status === STATUS_STOPPED): ?>

            <form method="post"
                  onsubmit="return confirmAction('アンケートを公開しますか？')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="id" value="<?= e($survey['id']) ?>">
                <input type="hidden" name="new_status" value="published">
                <input type="hidden" name="return_screen" value="list">

                <button class="btn btn-success btn-sm">
                    公開
                </button>
            </form>

            <?php elseif ($status === STATUS_PUBLISHED): ?>

            <form method="post"
                  onsubmit="return confirmAction('アンケートを停止しますか？')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="id" value="<?= e($survey['id']) ?>">
                <input type="hidden" name="new_status" value="stopped">
                <input type="hidden" name="return_screen" value="list">

                <button class="btn btn-warning btn-sm">
                    停止
                </button>
            </form>

            <?php endif; ?>

        </div>
        <?php endif; ?>
    </td>
</tr>

<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>

</div>
</div>

<?php
    render_foot();
    exit;
}

/* =========================================================
 * EDIT
 * ======================================================= */

if ($screen === 'edit') {
    $survey = $id !== '' ? find_survey($id) : null;

    if ($id !== '' && !$survey) {
        flash('指定されたアンケートが存在しません。', 'error');
        redirect('list');
    }

    if (!$survey) {
        $survey = [
            'id'         => '',
            'title'      => '',
            'description'=> '',
            'start_at'   => '',
            'end_at'     => '',
            'status'     => STATUS_DRAFT,
            'numbering'  => 'global',
            'groups'     => [],
            'created_at' => '',
            'updated_at' => '',
        ];
    }

    $survey = normalize_survey($survey);

    render_head(
        $survey['id'] === ''
            ? 'アンケート作成'
            : 'アンケート編集'
    );

    render_flash($flash);
    ?>

<div class="page-title">
    <div>
        <h1>
            <?= $survey['id'] === ''
                ? 'アンケート作成'
                : 'アンケート編集' ?>
        </h1>
    </div>

    <div class="actions">
        <a class="btn btn-secondary"
           href="index.php?screen=preview&id=<?= rawurlencode($survey['id']) ?>">
            プレビュー
        </a>

        <a class="btn btn-secondary"
           href="index.php?screen=list">
            キャンセル
        </a>
    </div>
</div>

<form method="post" id="survey-form">
    <?= csrf_field() ?>

    <input type="hidden" name="action" value="save_survey">
    <input type="hidden" name="id" value="<?= e($survey['id']) ?>">
    <input type="hidden" name="return_screen" value="list">
    <input type="hidden" name="groups_json" id="groups_json">

    <div class="card">
        <h2>基本情報</h2>

        <div class="form-row">
            <label>
                アンケートタイトル
                <span class="required">*</span>
            </label>

            <input
                id="title"
                name="title"
                maxlength="<?= MAX_TITLE ?>"
                required
                value="<?= e($survey['title']) ?>"
            >
        </div>

        <div class="form-row">
            <label>アンケート説明</label>

            <textarea
                name="description"
                maxlength="<?= MAX_DESC ?>"
            ><?= e($survey['description']) ?></textarea>
        </div>

        <div class="grid grid-2">

            <div class="form-row">
                <label>開始日時</label>

                <input
                    type="datetime-local"
                    name="start_at"
                    value="<?= e(
                        $survey['start_at']
                            ? date('Y-m-d\TH:i', strtotime($survey['start_at']))
                            : ''
                    ) ?>"
                >
            </div>

            <div class="form-row">
                <label>終了日時</label>

                <input
                    type="datetime-local"
                    name="end_at"
                    value="<?= e(
                        $survey['end_at']
                            ? date('Y-m-d\TH:i', strtotime($survey['end_at']))
                            : ''
                    ) ?>"
                >
            </div>

        </div>

        <div class="form-row">
            <label>質問番号の採番方式</label>

            <select name="numbering">
                <option
                    value="global"
                    <?= $survey['numbering'] === 'global'
                        ? 'selected'
                        : '' ?>
                >
                    アンケート全体で通番（Q1、Q2、Q3...）
                </option>

                <option
                    value="group"
                    <?= $survey['numbering'] === 'group'
                        ? 'selected'
                        : '' ?>
                >
                    グループ毎に採番（Q1-1、Q1-2、Q2-1...）
                </option>
            </select>
        </div>

        <div>
            <label>状態</label>

            <span class="badge badge-<?= e(status_class($survey['status'])) ?>">
                <?= e(status_label($survey['status'])) ?>
            </span>
        </div>
    </div>

    <div class="card">
        <div class="page-title" style="margin-bottom:15px">
            <h2 style="margin:0">グループ・質問</h2>

            <button
                class="btn btn-secondary"
                type="button"
                onclick="addGroup()"
            >
                ＋ グループを追加
            </button>
        </div>

        <div id="groups"></div>
    </div>

    <div class="sticky-actions">
        <div class="actions">
            <a class="btn btn-secondary"
               href="index.php?screen=list">
                キャンセル
            </a>

            <button
                class="btn btn-primary"
                type="submit"
                onclick="prepareSave()"
            >
                保存して一覧へ
            </button>
        </div>
    </div>
</form>

<script>
let groups = <?= json_encode(
    $survey['groups'],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

function id() {
    return Math.random().toString(36).slice(2) +
        Date.now().toString(36);
}

function esc(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function ensureData() {
    groups = groups.map(g => ({
        id: g.id || id(),
        title: g.title || 'グループ',
        questions: (g.questions || []).map(q => ({
            id: q.id || id(),
            text: q.text || '',
            type: ['single','multi','text'].includes(q.type)
                ? q.type
                : 'single',
            required: !!q.required,
            options: q.options || [],
            branches: q.branches || []
        }))
    }));
}

function render() {
    ensureData();

    const root = document.getElementById('groups');

    if (!groups.length) {
        root.innerHTML = `
            <div class="empty">
                グループがありません。<br>
                「グループを追加」から作成してください。
            </div>
        `;
        return;
    }

    root.innerHTML = groups.map((group, gi) => `
        <div
            class="group-card"
            draggable="true"
            data-group-index="${gi}"
            ondragstart="groupDragStart(event, ${gi})"
            ondragover="event.preventDefault()"
            ondrop="groupDrop(event, ${gi})"
        >
            <div class="question-head">
                <div style="display:flex;gap:10px;align-items:center">
                    <span class="drag-handle">☷</span>

                    <strong>グループ ${gi + 1}</strong>
                </div>

                <button
                    type="button"
                    class="btn btn-danger btn-sm"
                    onclick="removeGroup(${gi})"
                >
                    グループ削除
                </button>
            </div>

            <div class="form-row">
                <label>グループタイトル</label>

                <input
                    value="${esc(group.title)}"
                    onchange="groups[${gi}].title = this.value"
                >
            </div>

            <div>
                ${(group.questions || []).map((q, qi) =>
                    questionHtml(q, gi, qi)
                ).join('')}
            </div>

            <button
                type="button"
                class="btn btn-secondary"
                onclick="addQuestion(${gi})"
            >
                ＋ 質問を追加
            </button>
        </div>
    `).join('');
}

function questionHtml(q, gi, qi) {
    const number = questionNumber(gi, qi);

    return `
        <div
            class="question-card"
            draggable="true"
            data-question-id="${esc(q.id)}"
            ondragstart="questionDragStart(event, ${gi}, ${qi})"
            ondragover="event.preventDefault()"
            ondrop="questionDrop(event, ${gi}, ${qi})"
        >
            <div class="question-head">
                <div>
                    <span class="drag-handle">☷</span>
                    <strong>${esc(number)}</strong>
                </div>

                <button
                    type="button"
                    class="btn btn-danger btn-sm"
                    onclick="removeQuestion(${gi}, ${qi})"
                >
                    質問削除
                </button>
            </div>

            <div class="form-row">
                <label>質問文</label>

                <textarea
                    onchange="groups[${gi}].questions[${qi}].text=this.value"
                >${esc(q.text)}</textarea>
            </div>

            <div class="grid grid-2">

                <div class="form-row">
                    <label>回答形式</label>

                    <select
                        onchange="changeType(${gi}, ${qi}, this.value)"
                    >
                        <option
                            value="single"
                            ${q.type === 'single' ? 'selected' : ''}
                        >
                            単一選択
                        </option>

                        <option
                            value="multi"
                            ${q.type === 'multi' ? 'selected' : ''}
                        >
                            複数選択
                        </option>

                        <option
                            value="text"
                            ${q.type === 'text' ? 'selected' : ''}
                        >
                            自由記述
                        </option>
                    </select>
                </div>

                <div class="form-row">
                    <label>必須設定</label>

                    <label class="checkbox">
                        <input
                            type="checkbox"
                            ${q.required ? 'checked' : ''}
                            onchange="
                                groups[${gi}].questions[${qi}].required=this.checked
                            "
                        >
                        必須
                    </label>
                </div>

            </div>

            ${optionsHtml(q, gi, qi)}
            ${branchesHtml(q, gi, qi)}
        </div>
    `;
}

function optionsHtml(q, gi, qi) {
    if (q.type === 'text') {
        return '';
    }

    return `
        <div class="form-row">
            <label>選択肢</label>

            ${(q.options || []).map((option, oi) => `
                <div class="option-row">
                    <input
                        value="${esc(option)}"
                        onchange="
                            groups[${gi}].questions[${qi}]
                                .options[${oi}]=this.value
                        "
                    >

                    <button
                        type="button"
                        class="btn btn-danger btn-sm"
                        onclick="removeOption(${gi},${qi},${oi})"
                    >
                        削除
                    </button>
                </div>
            `).join('')}

            <button
                type="button"
                class="btn btn-secondary btn-sm"
                onclick="addOption(${gi},${qi})"
            >
                ＋ 選択肢
            </button>
        </div>
    `;
}

function branchesHtml(q, gi, qi) {
    if (q.type !== 'single' || !q.options?.length) {
        return '';
    }

    const targets = [];

    groups.forEach((g, gIndex) => {
        g.questions.forEach((question, qIndex) => {
            if (
                gIndex === gi &&
                qIndex === qi
            ) {
                return;
            }

            targets.push({
                id: question.id,
                label: questionNumber(gIndex, qIndex) +
                    ' ' +
                    (question.text || '未入力')
            });
        });
    });

    return `
        <div class="form-row">
            <label>条件分岐</label>

            <p class="muted">
                選択肢ごとに次に表示する質問を指定できます。
            </p>

            ${(q.options || []).map((option, oi) => {
                const branch = (q.branches || [])
                    .find(b => b.option === option);

                return `
                    <div class="grid grid-2" style="margin-bottom:8px">
                        <div>
                            <input value="${esc(option)}" readonly>
                        </div>

                        <div>
                            <select
                                onchange="
                                    setBranch(
                                        ${gi},
                                        ${qi},
                                        '${esc(option)}',
                                        this.value
                                    )
                                "
                            >
                                <option value="">分岐なし</option>

                                ${targets.map(t => `
                                    <option
                                        value="${esc(t.id)}"
                                        ${branch &&
                                          branch.target === t.id
                                            ? 'selected'
                                            : ''}
                                    >
                                        ${esc(t.label)}
                                    </option>
                                `).join('')}
                            </select>
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
}

function questionNumber(gi, qi) {
    const mode =
        document.querySelector('[name="numbering"]')?.value ||
        'global';

    if (mode === 'group') {
        return 'Q' + (gi + 1) + '-' + (qi + 1);
    }

    let n = 0;

    for (let i = 0; i < gi; i++) {
        n += groups[i].questions.length;
    }

    return 'Q' + (n + qi + 1);
}

function addGroup() {
    groups.push({
        id: id(),
        title: '新しいグループ',
        questions: []
    });

    render();
}

function removeGroup(index) {
    if (!confirm('このグループを削除しますか？')) {
        return;
    }

    groups.splice(index, 1);
    render();
}

function addQuestion(gi) {
    groups[gi].questions.push({
        id: id(),
        text: '',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        branches: []
    });

    render();
}

function removeQuestion(gi, qi) {
    if (!confirm('この質問を削除しますか？')) {
        return;
    }

    groups[gi].questions.splice(qi, 1);
    render();
}

function changeType(gi, qi, type) {
    groups[gi].questions[qi].type = type;

    if (type === 'text') {
        groups[gi].questions[qi].options = [];
        groups[gi].questions[qi].branches = [];
    }

    render();
}

function addOption(gi, qi) {
    groups[gi].questions[qi].options.push(
        '選択肢' +
        (groups[gi].questions[qi].options.length + 1)
    );

    render();
}

function removeOption(gi, qi, oi) {
    groups[gi].questions[qi].options.splice(oi, 1);

    groups[gi].questions[qi].branches =
        groups[gi].questions[qi].branches.filter(
            b => b.option !== oi
        );

    render();
}

function setBranch(gi, qi, option, target) {
    const q = groups[gi].questions[qi];

    q.branches = (q.branches || [])
        .filter(b => b.option !== option);

    if (target) {
        q.branches.push({
            option: option,
            target: target
        });
    }
}

let draggedGroup = null;

function groupDragStart(event, index) {
    draggedGroup = index;
    event.dataTransfer.effectAllowed = 'move';
}

function groupDrop(event, targetIndex) {
    if (draggedGroup === null ||
        draggedGroup === targetIndex) {
        return;
    }

    const item = groups.splice(draggedGroup, 1)[0];

    groups.splice(targetIndex, 0, item);

    draggedGroup = null;

    render();
}

let draggedQuestion = null;

function questionDragStart(event, gi, qi) {
    draggedQuestion = {
        gi,
        qi
    };

    event.dataTransfer.effectAllowed = 'move';
}

function questionDrop(event, targetGi, targetQi) {
    if (!draggedQuestion) {
        return;
    }

    const sourceGi = draggedQuestion.gi;
    const sourceQi = draggedQuestion.qi;

    const question =
        groups[sourceGi].questions.splice(sourceQi, 1)[0];

    /*
     * 同一グループ内で下方向へ移動する場合、
     * splice後のindex補正。
     */
    let insertIndex = targetQi;

    if (
        sourceGi === targetGi &&
        sourceQi < targetQi
    ) {
        insertIndex--;
    }

    groups[targetGi].questions.splice(
        Math.max(0, insertIndex),
        0,
        question
    );

    draggedQuestion = null;

    render();
}

function prepareSave() {
    document.getElementById('groups_json').value =
        JSON.stringify(groups);
}

document.querySelector('[name="numbering"]')
    ?.addEventListener('change', render);

render();
</script>

<?php
    render_foot();
    exit;
}

/* =========================================================
 * PREVIEW
 * ======================================================= */

if ($screen === 'preview') {
    $survey = find_survey($id);

    if (!$survey) {
        flash('アンケートが存在しません。', 'error');
        redirect('list');
    }

    $survey = normalize_survey($survey);

    render_head('プレビュー');
    render_flash($flash);
    ?>

<div class="page-title">
    <h1>プレビュー</h1>

    <div class="actions">
        <a class="btn btn-secondary"
           href="index.php?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
            編集へ戻る
        </a>
    </div>
</div>

<div class="preview">
<div class="card">

    <h1 style="margin-top:0">
        <?= e($survey['title']) ?>
    </h1>

    <?php if ($survey['description'] !== ''): ?>
    <p class="muted">
        <?= nl2br(e($survey['description'])) ?>
    </p>
    <?php endif; ?>

    <?php foreach ($survey['groups'] as $group): ?>

        <div style="margin-top:28px">
            <h2><?= e($group['title']) ?></h2>

            <?php foreach ($group['questions'] as $question): ?>

            <div class="question-card">
                <div>
                    <strong>
                        <?= e($question['number']) ?>
                        <?= e($question['text']) ?>
                    </strong>

                    <?php if ($question['required']): ?>
                        <span class="required"> *</span>
                    <?php endif; ?>
                </div>

                <div style="margin-top:12px">

                <?php if ($question['type'] === TYPE_TEXT): ?>

                    <textarea placeholder="回答を入力"></textarea>

                <?php else: ?>

                    <?php foreach ($question['options'] as $option): ?>

                    <label class="checkbox" style="margin-bottom:8px">
                        <input
                            type="<?= $question['type'] === TYPE_SINGLE
                                ? 'radio'
                                : 'checkbox' ?>"
                            disabled
                        >
                        <?= e($option) ?>
                    </label>

                    <?php endforeach; ?>

                <?php endif; ?>

                </div>
            </div>

            <?php endforeach; ?>
        </div>

    <?php endforeach; ?>

</div>
</div>

<?php
    render_foot();
    exit;
}

/* =========================================================
 * ANSWER
 * ======================================================= */

if ($screen === 'answer') {
    $survey = find_survey($id);

    if (!$survey) {
        render_head('アンケート', false);
        ?>
        <div class="card">
            <h1>アンケートが見つかりません</h1>
        </div>
        <?php
        render_foot();
        exit;
    }

    update_automatic_status($survey);

    if (($survey['status'] ?? '') !== STATUS_PUBLISHED) {
        render_head('アンケート', false);
        ?>
        <div class="preview">
        <div class="card">
            <h1><?= e($survey['title']) ?></h1>
            <p>
                現在、このアンケートには回答できません。
            </p>
        </div>
        </div>
        <?php
        render_foot();
        exit;
    }

    $survey = normalize_survey($survey);

    render_head('アンケート回答', false);
    render_flash($flash);
    ?>

<div class="preview">

<form method="post">
    <?= csrf_field() ?>

    <input type="hidden"
           name="action"
           value="submit_answer">

    <input type="hidden"
           name="survey_id"
           value="<?= e($survey['id']) ?>">

    <input type="hidden"
           name="return_screen"
           value="answer">

    <input type="hidden"
           name="return_id"
           value="<?= e($survey['id']) ?>">

    <div class="card">

        <h1><?= e($survey['title']) ?></h1>

        <?php if ($survey['description'] !== ''): ?>
        <p class="muted">
            <?= nl2br(e($survey['description'])) ?>
        </p>
        <?php endif; ?>

        <?php foreach ($survey['groups'] as $group): ?>

        <section style="margin-top:28px">
            <h2><?= e($group['title']) ?></h2>

            <?php foreach ($group['questions'] as $question): ?>

            <div class="question-card">

                <label>
                    <?= e($question['number']) ?>
                    <?= e($question['text']) ?>

                    <?php if ($question['required']): ?>
                    <span class="required">*</span>
                    <?php endif; ?>
                </label>

                <?php if ($question['type'] === TYPE_TEXT): ?>

                <textarea
                    name="answer[<?= e($question['id']) ?>]"
                    <?= $question['required'] ? 'required' : '' ?>
                ></textarea>

                <?php elseif ($question['type'] === TYPE_SINGLE): ?>

                    <?php foreach ($question['options'] as $option): ?>

                    <label class="checkbox" style="margin-bottom:9px">
                        <input
                            type="radio"
                            name="answer[<?= e($question['id']) ?>]"
                            value="<?= e($option) ?>"
                            <?= $question['required'] ? 'required' : '' ?>
                        >

                        <?= e($option) ?>
                    </label>

                    <?php endforeach; ?>

                <?php else: ?>

                    <?php foreach ($question['options'] as $option): ?>

                    <label class="checkbox" style="margin-bottom:9px">
                        <input
                            type="checkbox"
                            name="answer[<?= e($question['id']) ?>][]"
                            value="<?= e($option) ?>"
                        >

                        <?= e($option) ?>
                    </label>

                    <?php endforeach; ?>

                    <?php if ($question['required']): ?>
                    <small class="muted">
                        必須項目です。
                    </small>
                    <?php endif; ?>

                <?php endif; ?>

            </div>

            <?php endforeach; ?>
        </section>

        <?php endforeach; ?>

        <button
            class="btn btn-primary"
            type="submit"
        >
            回答を確認する
        </button>

    </div>
</form>

</div>

<?php
    render_foot();
    exit;
}

/* =========================================================
 * CONFIRM
 * ======================================================= */

if ($screen === 'confirm') {
    $survey = find_survey($id);
    $pending = $_SESSION['pending_answer'] ?? null;

    if (!$survey || !is_array($pending)) {
        flash('確認対象の回答がありません。', 'error');
        redirect('list');
    }

    $answerMap = [];

    foreach (($pending['answers'] ?? []) as $row) {
        $answerMap[$row['question_id']] = $row['value'];
    }

    render_head('回答確認', false);
    render_flash($flash);
    ?>

<div class="preview">
<div class="card">

<h1>回答確認</h1>

<p class="muted">
    以下の内容で送信します。修正する場合は「戻る」を押してください。
</p>

<?php foreach ($survey['groups'] as $group): ?>

<h2 style="margin-top:26px">
    <?= e($group['title']) ?>
</h2>

<?php foreach ($group['questions'] as $question): ?>

<?php
$value = $answerMap[$question['id']] ?? '';

if (is_array($value)) {
    $displayValue = implode('、', $value);
} else {
    $displayValue = $value;
}
?>

<div class="question-card">
    <strong>
        <?= e($question['number']) ?>
        <?= e($question['text']) ?>
    </strong>

    <div style="margin-top:9px">
        <?= nl2br(e($displayValue !== ''
            ? $displayValue
            : '未回答')) ?>
    </div>
</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="actions">

    <a
        class="btn btn-secondary"
        href="index.php?screen=answer&id=<?= rawurlencode($survey['id']) ?>"
    >
        戻る
    </a>

    <form method="post"
          onsubmit="return confirmAction('回答を送信しますか？')">

        <?= csrf_field() ?>

        <input type="hidden"
               name="action"
               value="confirm_answer">

        <input type="hidden"
               name="return_screen"
               value="confirm">

        <input type="hidden"
               name="return_id"
               value="<?= e($survey['id']) ?>">

        <button class="btn btn-primary">
            回答を送信する
        </button>

    </form>

</div>

</div>
</div>

<?php
    render_foot();
    exit;
}

/* =========================================================
 * COMPLETE
 * ======================================================= */

if ($screen === 'complete') {
    $survey = find_survey($id);

    render_head('回答完了', false);
    ?>

<div class="preview">
<div class="card" style="text-align:center">

    <h1>回答ありがとうございました</h1>

    <p>
        アンケートの回答を受け付けました。
    </p>

    <?php if ($survey): ?>
    <p class="muted">
        <?= e($survey['title']) ?>
    </p>
    <?php endif; ?>

</div>
</div>

<?php
    render_foot();
    exit;
}

/* =========================================================
 * ANALYTICS
 * ======================================================= */

if ($screen === 'analytics') {
    $survey = find_survey($id);

    if (!$survey) {
        flash('アンケートが存在しません。', 'error');
        redirect('list');
    }

    $allAnswers = array_values(
        array_filter(
            answers(),
            static fn($a) => ($a['survey_id'] ?? '') === $survey['id']
        )
    );

    $allCustomers = customers();
    $logs = send_logs();

    $sentCustomerIds = [];

    foreach ($logs as $log) {
        if (
            ($log['survey_id'] ?? '') === $survey['id']
            && ($log['status'] ?? '') === 'success'
        ) {
            $sentCustomerIds[] = $log['customer_id'] ?? null;
        }
    }

    $sentCustomerIds = array_values(
        array_unique(array_filter($sentCustomerIds))
    );

    $sentCount = count($sentCustomerIds);
    $answerCount = count($allAnswers);

    $registeredCount = 0;

    foreach ($allAnswers as $answer) {
        if (!empty($answer['customer_id'])) {
            $registeredCount++;
        }
    }

    $unregisteredCount = $answerCount - $registeredCount;

    $unansweredCount = max(
        0,
        $sentCount - $answerCount
    );

    $rate = $sentCount > 0
        ? round(($answerCount / $sentCount) * 100, 1)
        : 0;

    render_head('回答集計');
    render_flash($flash);
    ?>

<div class="page-title">
    <div>
        <h1>回答集計・分析</h1>
        <p class="muted">
            対象アンケート：
            <strong><?= e($survey['title']) ?></strong>
        </p>
    </div>

    <div class="actions">
        <a
            class="btn btn-secondary"
            href="index.php?screen=list"
        >
            一覧へ
        </a>

        <a
            class="btn btn-primary"
            href="index.php?screen=analytics&id=<?= rawurlencode($survey['id']) ?>&export=csv"
        >
            CSV
        </a>

        <a
            class="btn btn-secondary"
            href="index.php?screen=analytics&id=<?= rawurlencode($survey['id']) ?>&export=pdf"
        >
            PDF
        </a>
    </div>
</div>

<div class="grid grid-3">

    <div class="metric">
        <div class="metric-label">送信対象者数</div>
        <div class="metric-value"><?= e($sentCount) ?></div>
    </div>

    <div class="metric">
        <div class="metric-label">回答数</div>
        <div class="metric-value"><?= e($answerCount) ?></div>
    </div>

    <div class="metric">
        <div class="metric-label">未回答数</div>
        <div class="metric-value"><?= e($unansweredCount) ?></div>
    </div>

    <div class="metric">
        <div class="metric-label">未登録回答数</div>
        <div class="metric-value"><?= e($unregisteredCount) ?></div>
    </div>

    <div class="metric">
        <div class="metric-label">回答率</div>
        <div class="metric-value"><?= e($rate) ?>%</div>

        <div class="progress">
            <span style="width:<?= e(min(100, $rate)) ?>%"></span>
        </div>
    </div>

</div>

<?php if ($answerCount === 0): ?>

<div class="card empty">
    現在、回答データはありません
</div>

<?php else: ?>

<div class="card">
    <h2>設問別集計</h2>

    <?php foreach ($survey['groups'] as $group): ?>

        <h3><?= e($group['title']) ?></h3>

        <?php foreach ($group['questions'] as $question): ?>

        <?php
        $counts = [];

        foreach ($question['options'] ?? [] as $option) {
            $counts[$option] = 0;
        }

        foreach ($allAnswers as $answer) {
            foreach (($answer['answers'] ?? []) as $row) {
                if (($row['question_id'] ?? '') !== $question['id']) {
                    continue;
                }

                $value = $row['value'] ?? '';

                if (is_array($value)) {
                    foreach ($value as $v) {
                        $counts[$v] = ($counts[$v] ?? 0) + 1;
                    }
                } elseif ($value !== '') {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
        }
        ?>

        <div class="question-card">

            <strong>
                <?= e($question['number']) ?>
                <?= e($question['text']) ?>
            </strong>

            <p class="muted">
                <?= e(answer_type_label($question['type'])) ?>
            </p>

            <?php if ($question['type'] !== TYPE_TEXT): ?>

                <?php foreach ($counts as $option => $count): ?>

                <div style="margin-bottom:10px">
                    <div style="display:flex;justify-content:space-between">
                        <span><?= e($option) ?></span>
                        <strong><?= e($count) ?></strong>
                    </div>

                    <div class="progress">
                        <span
                            style="width:<?= e(
                                $answerCount > 0
                                    ? min(
                                        100,
                                        ($count / $answerCount) * 100
                                    )
                                    : 0
                            ) ?>%"
                        ></span>
                    </div>
                </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p class="muted">
                    自由記述回答
                </p>

            <?php endif; ?>

        </div>

        <?php endforeach; ?>

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
            <th>回答</th>
        </tr>
        </thead>

        <tbody>

        <?php foreach ($allAnswers as $answer): ?>

        <?php
        $customerName = '未登録';

        if (!empty($answer['customer_id'])) {
            foreach ($allCustomers as $customer) {
                if (
                    ($customer['id'] ?? '') ===
                    $answer['customer_id']
                ) {
                    $customerName =
                        (string)($customer['name'] ?? '未登録');
                    break;
                }
            }
        }
        ?>

        <tr>
            <td><?= e(format_dt($answer['created_at'])) ?></td>

            <td><?= e($customerName) ?></td>

            <td>
                <?php foreach ($answer['answers'] as $row): ?>
                    <?php
                    $v = $row['value'] ?? '';

                    if (is_array($v)) {
                        $v = implode('、', $v);
                    }
                    ?>

                    <div>
                        <strong>
                            <?= e($row['question_id']) ?>
                        </strong>：
                        <?= e($v) ?>
                    </div>
                <?php endforeach; ?>
            </td>
        </tr>

        <?php endforeach; ?>

        </tbody>
    </table>
    </div>
</div>

<?php endif; ?>

<?php
    /*
     * CSV / PDF export.
     * GETなのでCSRF対象ではない。
     */
    if (($_GET['export'] ?? '') === 'csv') {
        $rows = [
            ['回答日時', '回答者', '質問ID', '回答'],
        ];

        foreach ($allAnswers as $answer) {
            $name = '未登録';

            foreach ($allCustomers as $customer) {
                if (
                    ($customer['id'] ?? '') ===
                    ($answer['customer_id'] ?? '')
                ) {
                    $name = $customer['name'] ?? '未登録';
                    break;
                }
            }

            foreach ($answer['answers'] as $row) {
                $value = $row['value'] ?? '';

                if (is_array($value)) {
                    $value = implode('、', $value);
                }

                $rows[] = [
                    $answer['created_at'],
                    $name,
                    $row['question_id'],
                    $value,
                ];
            }
        }

        output_csv($rows, 'survey-' . $survey['id'] . '.csv');
    }

    if (($_GET['export'] ?? '') === 'pdf') {
        $lines = [];

        $lines[] = '対象アンケート: ' . $survey['title'];
        $lines[] = '回答数: ' . $answerCount;
        $lines[] = '回答率: ' . $rate . '%';

        foreach ($allAnswers as $answer) {
            foreach ($answer['answers'] as $row) {
                $value = $row['value'] ?? '';

                if (is_array($value)) {
                    $value = implode('、', $value);
                }

                $lines[] =
                    $row['question_id'] . ': ' .
                    (string)$value;
            }
        }

        output_simple_pdf(
            $survey['title'],
            $lines
        );
    }

    render_foot();
    exit;
}

/* =========================================================
 * SEND
 * ======================================================= */

if ($screen === 'send') {
    $survey = find_survey($id);

    if (!$survey) {
        flash('アンケートが存在しません。', 'error');
        redirect('list');
    }

    $allCustomers = customers();

    $search = $_GET['q'] ?? '';

    if (!is_string($search)) {
        $search = '';
    }

    if ($search !== '') {
        $allCustomers = array_filter(
            $allCustomers,
            static function ($customer) use ($search): bool {
                $haystack = implode(' ', [
                    $customer['organization'] ?? '',
                    $customer['name'] ?? '',
                    $customer['email'] ?? '',
                    $customer['department'] ?? '',
                ]);

                return mb_stripos($haystack, $search) !== false;
            }
        );
    }

    $logs = array_values(
        array_filter(
            send_logs(),
            static fn($log) =>
                ($log['survey_id'] ?? '') === $survey['id']
        )
    );

    render_head('顧客選択・メール送信');
    render_flash($flash);
    ?>

<div class="page-title">
    <div>
        <h1>顧客選択・メール送信</h1>

        <p class="muted">
            対象アンケート：
            <strong><?= e($survey['title']) ?></strong>
        </p>
    </div>

    <a class="btn btn-secondary"
       href="index.php?screen=list">
        一覧へ
    </a>
</div>

<div class="tabs">
    <a class="active" href="#send-area">顧客選択・送信</a>
    <a href="#history">送信履歴</a>
</div>

<div id="send-area" class="card">
    <h2>顧客選択</h2>

    <form method="get" class="searchbar">
        <input type="hidden" name="screen" value="send">
        <input type="hidden" name="id" value="<?= e($survey['id']) ?>">

        <input
            class="search-input"
            type="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="顧客名・メール・部署など"
        >

        <button class="btn btn-primary">
            検索
        </button>
    </form>

    <form method="post"
          style="margin-top:20px"
          onsubmit="return confirmAction('選択した顧客へ一括送信しますか？')">

        <?= csrf_field() ?>

        <input type="hidden"
               name="action"
               value="send_bulk">

        <input type="hidden"
               name="survey_id"
               value="<?= e($survey['id']) ?>">

        <input type="hidden"
               name="return_screen"
               value="send">

        <input type="hidden"
               name="return_id"
               value="<?= e($survey['id']) ?>">

        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>
                    <input
                        type="checkbox"
                        onclick="
                            document
                              .querySelectorAll('.customer-check')
                              .forEach(x => x.checked=this.checked)
                        "
                    >
                </th>
                <th>組織名</th>
                <th>氏名</th>
                <th>メールアドレス</th>
                <th>部署</th>
                <th>電話番号</th>
            </tr>
            </thead>

            <tbody>

            <?php if (!$allCustomers): ?>

            <tr>
                <td colspan="6">
                    <div class="empty">
                        顧客データがありません。
                        kintone設定から同期してください。
                    </div>
                </td>
            </tr>

            <?php else: ?>

            <?php foreach ($allCustomers as $customer): ?>

            <tr>
                <td>
                    <input
                        class="customer-check"
                        type="checkbox"
                        name="customer_ids[]"
                        value="<?= e($customer['id']) ?>"
                    >
                </td>

                <td><?= e($customer['organization'] ?? '') ?></td>
                <td><?= e($customer['name'] ?? '') ?></td>
                <td><?= e($customer['email'] ?? '') ?></td>
                <td><?= e($customer['department'] ?? '') ?></td>
                <td><?= e($customer['phone'] ?? '') ?></td>
            </tr>

            <?php endforeach; ?>

            <?php endif; ?>

            </tbody>
        </table>
        </div>

        <div class="card" style="margin-top:20px">
            <h2>メール本文</h2>

            <div class="form-row">
                <label>件名</label>

                <input
                    name="subject"
                    value="<?= e($survey['title']) ?>"
                    required
                >
            </div>

            <div class="form-row">
                <label>本文</label>

                <textarea
                    name="body"
                    required
                >{顧客名} 様

アンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力よろしくお願いいたします。</textarea>
            </div>

            <p class="muted">
                使用可能な変数：
                <code>{顧客名}</code>
                <code>{アンケートURL}</code>
            </p>

            <button class="btn btn-primary">
                一括送信
            </button>
        </div>
    </form>
</div>

<div id="history" class="card">
    <h2>送信履歴</h2>

    <?php if (!$logs): ?>

    <div class="empty">
        送信履歴はありません。
    </div>

    <?php else: ?>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>日時</th>
            <th>メール</th>
            <th>結果</th>
            <th>メッセージ</th>
            <th>操作</th>
        </tr>
        </thead>

        <tbody>

        <?php foreach (array_reverse($logs) as $log): ?>

        <tr>
            <td><?= e(format_dt($log['created_at'])) ?></td>

            <td><?= e($log['email'] ?? '') ?></td>

            <td>
                <?php if (($log['status'] ?? '') === 'success'): ?>
                    <span class="badge badge-success">
                        成功
                    </span>
                <?php else: ?>
                    <span class="badge badge-warning">
                        失敗
                    </span>
                <?php endif; ?>
            </td>

            <td><?= e($log['message'] ?? '') ?></td>

            <td>
                <div class="actions">

                    <form method="post"
                          onsubmit="return confirmAction('この顧客へ再送しますか？')">

                        <?= csrf_field() ?>

                        <input type="hidden"
                               name="action"
                               value="resend">

                        <input type="hidden"
                               name="log_id"
                               value="<?= e($log['id']) ?>">

                        <input type="hidden"
                               name="return_screen"
                               value="send">

                        <input type="hidden"
                               name="return_id"
                               value="<?= e($survey['id']) ?>">

                        <button class="btn btn-secondary btn-sm">
                            再送
                        </button>
                    </form>

                    <form method="post"
                          onsubmit="return confirmAction('リマインドを送信しますか？')">

                        <?= csrf_field() ?>

                        <input type="hidden"
                               name="action"
                               value="remind">

                        <input type="hidden"
                               name="log_id"
                               value="<?= e($log['id']) ?>">

                        <input type="hidden"
                               name="return_screen"
                               value="send">

                        <input type="hidden"
                               name="return_id"
                               value="<?= e($survey['id']) ?>">

                        <button class="btn btn-warning btn-sm">
                            リマインド
                        </button>
                    </form>

                </div>
            </td>
        </tr>

        <?php endforeach; ?>

        </tbody>
    </table>
    </div>

    <?php endif; ?>
</div>

<?php
    render_foot();
    exit;
}

/* =========================================================
 * KINTONE
 * ======================================================= */

if ($screen === 'kintone') {
    $settings = app_settings()['kintone'];
    $fields = $_SESSION['kintone_fields'] ?? [];

    render_head('kintone設定');
    render_flash($flash);
    ?>

<div class="page-title">
    <div>
        <h1>kintone設定</h1>
        <p class="muted">顧客情報の取得元を設定します。</p>
    </div>

    <a class="btn btn-secondary"
       href="index.php?screen=list">
        一覧へ
    </a>
</div>

<div class="card">

<form method="post">

<?= csrf_field() ?>

<input type="hidden"
       name="action"
       value="save_kintone">

<input type="hidden"
       name="return_screen"
       value="kintone">

<div class="grid grid-2">

<div class="form-row">
    <label>サブドメイン</label>
    <input
        name="subdomain"
        value="<?= e($settings['subdomain'] ?? '') ?>"
        placeholder="example"
    >
</div>

<div class="form-row">
    <label>顧客管理アプリID</label>
    <input
        name="app_id"
        value="<?= e($settings['app_id'] ?? '') ?>"
    >
</div>

<div class="form-row">
    <label>ログイン名</label>
    <input
        name="login"
        value="<?= e($settings['login'] ?? '') ?>"
        autocomplete="off"
    >
</div>

<div class="form-row">
    <label>パスワード</label>
    <input
        type="password"
        name="password"
        value=""
        autocomplete="new-password"
        placeholder="変更しない場合は空欄"
    >
</div>

<div class="form-row">
    <label>Proxy</label>
    <input
        name="proxy"
        value="<?= e($settings['proxy'] ?? '') ?>"
        placeholder="proxy.example.local:8080"
    >
</div>

<div class="form-row">
    <label>SSL証明書検証</label>

    <label class="checkbox">
        <input
            type="checkbox"
            name="verify_ssl"
            <?= !empty($settings['verify_ssl'])
                ? 'checked'
                : '' ?>
        >
        有効
    </label>
</div>

</div>

<h2>項目マッピング</h2>

<div class="grid grid-2">

<?php
$mapping = $settings['mapping'] ?? [];
?>

<div class="form-row">
    <label>組織名</label>
    <input
        name="map_organization"
        value="<?= e($mapping['organization'] ?? '') ?>"
    >
</div>

<div class="form-row">
    <label>氏名</label>
    <input
        name="map_name"
        value="<?= e($mapping['name'] ?? '') ?>"
    >
</div>

<div class="form-row">
    <label>メールアドレス</label>
    <input
        name="map_email"
        value="<?= e($mapping['email'] ?? '') ?>"
    >
</div>

<div class="form-row">
    <label>部署名</label>
    <input
        name="map_department"
        value="<?= e($mapping['department'] ?? '') ?>"
    >
</div>

<div class="form-row">
    <label>電話番号</label>
    <input
        name="map_phone"
        value="<?= e($mapping['phone'] ?? '') ?>"
    >
</div>

</div>

<div class="form-row">
    <label>
        住所マッピング
        <span class="muted">複数選択可</span>
    </label>

    <?php if (!$fields): ?>

    <p class="muted">
        「項目一覧を再取得」を実行すると項目を選択できます。
    </p>

    <?php else: ?>

        <?php foreach ($fields as $fieldCode => $field): ?>

        <label class="checkbox" style="margin-bottom:8px">
            <input
                type="checkbox"
                name="map_address[]"
                value="<?= e($fieldCode) ?>"
                <?= in_array(
                    $fieldCode,
                    $mapping['address'] ?? [],
                    true
                ) ? 'checked' : '' ?>
            >

            <?= e(
                ($field['label'] ?? '') .
                ' (' .
                $fieldCode .
                ')'
            ) ?>
        </label>

        <?php endforeach; ?>

    <?php endif; ?>
</div>

<button class="btn btn-primary">
    設定を保存
</button>

</form>
</div>

<div class="card">
<h2>接続・同期</h2>

<div class="actions">

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_kintone">
    <input type="hidden" name="return_screen" value="kintone">

    <button class="btn btn-secondary">
        接続テスト
    </button>
</form>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="fetch_kintone_fields">
    <input type="hidden" name="return_screen" value="kintone">

    <button class="btn btn-secondary">
        項目一覧を再取得
    </button>
</form>

<form method="post"
      onsubmit="return confirmAction('kintoneから顧客情報を同期しますか？')">

    <?= csrf_field() ?>
    <input type="hidden" name="action" value="sync_kintone">
    <input type="hidden" name="return_screen" value="kintone">

    <button class="btn btn-primary">
        顧客情報を同期
    </button>
</form>

</div>
</div>

<?php
    render_foot();
    exit;
}

/* =========================================================
 * MAIL
 * ======================================================= */

if ($screen === 'mail') {
    $settings = app_settings()['smtp'];

    render_head('メールサーバ設定');
    render_flash($flash);
    ?>

<div class="page-title">
    <div>
        <h1>メールサーバ設定</h1>
        <p class="muted">SMTPによるメール送信を設定します。</p>
    </div>

    <a class="btn btn-secondary"
       href="index.php?screen=list">
        一覧へ
    </a>
</div>

<div class="card">

<form method="post">

<?= csrf_field() ?>

<input type="hidden"
       name="action"
       value="save_smtp">

<input type="hidden"
       name="return_screen"
       value="mail">

<div class="grid grid-2">

<div class="form-row">
    <label>SMTPサーバ</label>
    <input
        name="server"
        value="<?= e($settings['server'] ?? '') ?>"
    >
</div>

<div class="form-row">
    <label>SMTPポート</label>
    <input
        type="number"
        name="port"
        min="1"
        max="65535"
        value="<?= e($settings['port'] ?? 587) ?>"
    >
</div>

<div class="form-row">
    <label>暗号化方式</label>

    <select name="encryption">
        <option
            value="ssl"
            <?= ($settings['encryption'] ?? '') === 'ssl'
                ? 'selected'
                : '' ?>
        >
            SSL
        </option>

        <option
            value="tls"
            <?= ($settings['encryption'] ?? 'tls') === 'tls'
                ? 'selected'
                : '' ?>
        >
            TLS
        </option>

        <option
            value="none"
            <?= ($settings['encryption'] ?? '') === 'none'
                ? 'selected'
                : '' ?>
        >
            なし
        </option>
    </select>
</div>

<div class="form-row">
    <label>SMTP認証</label>

    <label class="checkbox">
        <input
            type="checkbox"
            name="auth"
            <?= !empty($settings['auth'])
                ? 'checked'
                : '' ?>
        >
        使用する
    </label>
</div>

<div class="form-row">
    <label>SMTPユーザー名</label>
    <input
        name="username"
        value="<?= e($settings['username'] ?? '') ?>"
        autocomplete="off"
    >
</div>

<div class="form-row">
    <label>SMTPパスワード</label>
    <input
        type="password"
        name="password"
        value=""
        autocomplete="new-password"
        placeholder="変更しない場合は空欄"
    >
</div>

<div class="form-row">
    <label>送信元メールアドレス</label>
    <input
        type="email"
        name="from_email"
        value="<?= e($settings['from_email'] ?? '') ?>"
    >
</div>

<div class="form-row">
    <label>送信元名</label>
    <input
        name="from_name"
        value="<?= e($settings['from_name'] ?? '') ?>"
    >
</div>

<div class="form-row">
    <label>返信先メールアドレス</label>
    <input
        type="email"
        name="reply_to"
        value="<?= e($settings['reply_to'] ?? '') ?>"
    >
</div>

</div>

<button class="btn btn-primary">
    設定を保存
</button>

</form>
</div>

<div class="card">
<h2>接続状態</h2>

<p>
    現在の状態：
    <strong>
        <?= e($settings['status'] ?? '未設定') ?>
    </strong>
</p>

<div class="actions">

<form method="post">
    <?= csrf_field() ?>

    <input type="hidden"
           name="action"
           value="test_smtp">

    <input type="hidden"
           name="return_screen"
           value="mail">

    <button class="btn btn-secondary">
        SMTP接続確認
    </button>
</form>

</div>
</div>

<div class="card">
<h2>テストメール</h2>

<form method="post">

<?= csrf_field() ?>

<input type="hidden"
       name="action"
       value="test_email">

<input type="hidden"
       name="return_screen"
       value="mail">

<div class="form-row">
    <label>送信先</label>

    <input
        type="email"
        name="to"
        required
        placeholder="test@example.com"
    >
</div>

<button class="btn btn-primary">
    テストメール送信
</button>

</form>
</div>

<?php
    render_foot();
    exit;
}
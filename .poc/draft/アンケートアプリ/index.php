<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 * 単一エントリーポイント
 *
 * prompt.txt の仕様を基準に再構成。
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . '/_data';
const DATA_FILE = DATA_DIR . '/data.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';

const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT = 30;
const SMTP_CONNECT_TIMEOUT = 10;
const SMTP_READ_TIMEOUT = 30;

const QUESTION_TYPES = [
    'single' => '単一選択',
    'multiple' => '複数選択',
    'text' => '自由記述',
];

const STATUSES = [
    'draft' => '下書き',
    'published' => '公開中',
    'stopped' => '停止',
    'ended' => '終了',
];

/* =========================================================
 * 基本ユーティリティ
 * ========================================================= */

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function get_string(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function post_string(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function post_bool(string $key): bool
{
    return isset($_POST[$key]) && (string)$_POST[$key] !== '';
}

function uuid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(12));
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function app_url(array $params = []): string
{
    $base = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    return $base . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

function current_url(): string
{
    return app_url(array_filter([
        'screen' => get_string('screen'),
        'id' => get_string('id'),
    ]));
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function consume_flash(): ?array
{
    $v = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($v) ? $v : null;
}

/* =========================================================
 * セッション
 * ========================================================= */

function start_app(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') ?: '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('セッションを開始できません。');
    }
}

/* =========================================================
 * JSONデータ
 * ========================================================= */

function default_data(): array
{
    return [
        'surveys' => [],
        'answers' => [],
        'customers' => [],
        'send_history' => [],
    ];
}

function default_settings(): array
{
    return [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => false,
            'mapping' => [
                'organization' => [],
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
            'fields' => [],
            'last_test' => null,
            'last_sync' => null,
        ],
        'mail' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
            'last_test' => null,
        ],
    ];
}

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('データ保存領域を作成できません。');
    }
}

function load_json(string $file, array $fallback): array
{
    if (!is_file($file)) {
        return $fallback;
    }

    $fp = @fopen($file, 'rb');
    if (!$fp) {
        return $fallback;
    }

    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!is_string($raw) || trim($raw) === '') {
        return $fallback;
    }

    $v = json_decode($raw, true);
    return is_array($v) ? $v : $fallback;
}

function save_json(string $file, array $data): void
{
    ensure_data_dir();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $fp = @fopen($tmp, 'wb');
    if (!$fp) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データをロックできません。');
        }

        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('データファイルを更新できません。');
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

function load_data(): array
{
    $data = load_json(DATA_FILE, default_data());

    foreach (['surveys', 'answers', 'customers', 'send_history'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    return $data;
}

function save_data(array $data): void
{
    save_json(DATA_FILE, $data);
}

function load_settings(): array
{
    $d = default_settings();
    $s = load_json(SETTINGS_FILE, $d);

    foreach (['kintone', 'mail'] as $key) {
        $s[$key] = array_replace_recursive(
            $d[$key],
            is_array($s[$key] ?? null) ? $s[$key] : []
        );
    }

    return $s;
}

function save_settings(array $settings): void
{
    save_json(SETTINGS_FILE, $settings);
}

/* =========================================================
 * アンケートデータ
 * ========================================================= */

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $i => $s) {
        if ((string)($s['id'] ?? '') === $id) {
            return $i;
        }
    }
    return -1;
}

function survey_by_id(array $surveys, string $id): ?array
{
    $i = survey_index($surveys, $id);
    return $i >= 0 ? $surveys[$i] : null;
}

function recalc_numbers(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $qNo = 1;

        foreach ($group['questions'] as &$q) {
            $q['number'] =
                (($survey['numbering'] ?? 'global') === 'group')
                    ? 'Q' . $groupNo . '-' . $qNo
                    : 'Q' . $global;

            $global++;
            $qNo++;
        }

        unset($q);
        $groupNo++;
    }

    unset($group);
}

function refresh_statuses(array &$data): bool
{
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (
            ($survey['status'] ?? '') === 'published' &&
            !empty($survey['endAt']) &&
            ($ts = strtotime((string)$survey['endAt'])) !== false &&
            $ts < time()
        ) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now();
            $changed = true;
        }
    }

    unset($survey);
    return $changed;
}

function normalize_question(array $q): array
{
    $type = (string)($q['type'] ?? 'single');

    if (!isset(QUESTION_TYPES[$type])) {
        $type = 'single';
    }

    $options = [];
    foreach (($q['options'] ?? []) as $i => $o) {
        if (is_array($o)) {
            $options[] = [
                'label' => mb_substr((string)($o['label'] ?? ''), 0, MAX_OPTION),
                'nextQuestionId' => (string)($o['nextQuestionId'] ?? ''),
            ];
        } else {
            $options[] = [
                'label' => mb_substr((string)$o, 0, MAX_OPTION),
                'nextQuestionId' => '',
            ];
        }
    }

    if ($type !== 'text' && !$options) {
        $options[] = ['label' => '', 'nextQuestionId' => ''];
    }

    return [
        'id' => (string)($q['id'] ?? uuid('question')),
        'number' => (string)($q['number'] ?? ''),
        'text' => mb_substr((string)($q['text'] ?? ''), 0, MAX_QUESTION),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $options,
    ];
}

/* =========================================================
 * バリデーション
 * ========================================================= */

function validate_survey_input(): array
{
    $title = post_string('title');
    $description = (string)($_POST['description'] ?? '');
    $startAt = post_string('startAt');
    $endAt = post_string('endAt');
    $numbering = post_string('numbering');

    $errors = [];

    if ($title === '') {
        $errors[] = 'アンケートタイトルを入力してください。';
    } elseif (mb_strlen($title) > MAX_TITLE) {
        $errors[] = 'アンケートタイトルが長すぎます。';
    }

    if (mb_strlen($description) > MAX_DESCRIPTION) {
        $errors[] = 'アンケート説明が長すぎます。';
    }

    if ($startAt !== '' && strtotime($startAt) === false) {
        $errors[] = '開始日時が不正です。';
    }

    if ($endAt !== '' && strtotime($endAt) === false) {
        $errors[] = '終了日時が不正です。';
    }

    if (
        $startAt !== '' &&
        $endAt !== '' &&
        strtotime($startAt) !== false &&
        strtotime($endAt) !== false &&
        strtotime($endAt) < strtotime($startAt)
    ) {
        $errors[] = '終了日時は開始日時以降にしてください。';
    }

    if (!isset(['global', 'group'][$numbering])) {
        $numbering = 'global';
    }

    return compact(
        'errors',
        'title',
        'description',
        'startAt',
        'endAt',
        'numbering'
    );
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);
    $value = preg_replace('#^https?://#i', '', $value);
    $value = trim($value, '/');

    if (str_contains($value, '.cybozu.com')) {
        return 'https://' . $value;
    }

    return 'https://' . $value . '.cybozu.com';
}

function kintone_config(array $settings, ?array $post = null): array
{
    $c = $settings['kintone'];

    if ($post !== null) {
        $password = post_string('password');
        if ($password === '') {
            $password = (string)($c['password'] ?? '');
        }

        $c = array_replace($c, [
            'subdomain' => normalize_kintone_subdomain(post_string('subdomain')),
            'app_id' => post_string('app_id'),
            'username' => post_string('username'),
            'password' => $password,
            'proxy' => post_string('proxy'),
            'verify_ssl' => post_bool('verify_ssl'),
        ]);
    }

    return $c;
}

function validate_kintone_config(array $c): array
{
    $errors = [];

    if (!filter_var($c['subdomain'] ?? '', FILTER_VALIDATE_URL)) {
        $errors[] = 'kintoneサブドメインが不正です。';
    }

    if ((int)($c['app_id'] ?? 0) < 1) {
        $errors[] = '顧客管理アプリIDが不正です。';
    }

    if (($c['username'] ?? '') === '') {
        $errors[] = 'ログイン名を入力してください。';
    }

    if (($c['password'] ?? '') === '') {
        $errors[] = 'パスワードを入力してください。';
    }

    $proxy = (string)($c['proxy'] ?? '');
    if ($proxy !== '' && !preg_match('/^[^:\s]+:\d+$/', $proxy)) {
        $errors[] = 'Proxyはhost:port形式で入力してください。';
    }

    return $errors;
}

function kintone_request(array $c, string $path, string $method = 'GET', ?array $body = null): array
{
    $url = rtrim((string)$c['subdomain'], '/') . $path;

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                (string)$c['username'] . ':' . (string)$c['password']
            ),
        'Content-Type: application/json',
    ];

    $opts = [
        'http' => [
            'method' => $method,
            'timeout' => KINTONE_READ_TIMEOUT,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
        ],
        'ssl' => [
            'verify_peer' => !empty($c['verify_ssl']),
            'verify_peer_name' => !empty($c['verify_ssl']),
        ],
    ];

    if ($body !== null) {
        $opts['http']['content'] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    if (!empty($c['proxy'])) {
        $opts['http']['proxy'] = 'tcp://' . $c['proxy'];
        $opts['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $context);

    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $status = (int)$m[1];
        }
    }

    if ($raw === false) {
        throw new RuntimeException('kintoneへの通信に失敗しました。');
    }

    if ($status >= 300 && $status < 400) {
        throw new RuntimeException('kintoneからリダイレクト応答が返されました。');
    }

    if ($status < 200 || $status >= 300) {
        $detail = json_decode($raw, true);
        $message = is_array($detail)
            ? (string)($detail['message'] ?? 'kintone APIエラー')
            : 'kintone APIエラー';

        throw new RuntimeException($message . ' (HTTP ' . $status . ')');
    }

    $json = json_decode($raw, true);

    if (!is_array($json)) {
        throw new RuntimeException('kintoneのレスポンスを解析できません。');
    }

    return $json;
}

/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail_config(array $c): array
{
    $errors = [];

    if (($c['host'] ?? '') === '') {
        $errors[] = 'SMTPサーバを入力してください。';
    }

    if ((int)($c['port'] ?? 0) < 1 || (int)$c['port'] > 65535) {
        $errors[] = 'SMTPポートが不正です。';
    }

    if (
        ($c['from_email'] ?? '') === '' ||
        !filter_var($c['from_email'], FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = '送信元メールアドレスが不正です。';
    }

    if (
        !empty($c['reply_to']) &&
        !filter_var($c['reply_to'], FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = '返信先メールアドレスが不正です。';
    }

    if (!in_array($c['encryption'] ?? 'none', ['ssl', 'tls', 'none'], true)) {
        $errors[] = '暗号化方式が不正です。';
    }

    return $errors;
}

function smtp_open(array $c)
{
    $host = (string)$c['host'];
    $port = (int)$c['port'];
    $enc = (string)($c['encryption'] ?? 'none');

    if ($enc === 'ssl') {
        $host = 'ssl://' . $host;
    }

    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        SMTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException(
            'SMTP接続に失敗しました: ' . $errstr
        );
    }

    stream_set_timeout($socket, SMTP_READ_TIMEOUT);

    smtp_command($socket, null, [220]);
    smtp_command($socket, 'EHLO localhost', [250]);

    if ($enc === 'tls') {
        smtp_command($socket, 'STARTTLS', [220]);

        if (!stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {
            fclose($socket);
            throw new RuntimeException('TLSを開始できません。');
        }

        smtp_command($socket, 'EHLO localhost', [250]);
    }

    if (!empty($c['auth'])) {
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode((string)$c['username']), [334]);
        smtp_command($socket, base64_encode((string)$c['password']), [235]);
    }

    return $socket;
}

function smtp_command($socket, ?string $command, array $expected): string
{
    if ($command !== null) {
        fwrite($socket, $command . "\r\n");
    }

    $response = '';

    while (($line = fgets($socket)) !== false) {
        $response .= $line;

        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTP応答を取得できません。');
    }

    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . trim($response)
        );
    }

    return $response;
}

function smtp_header_encode(string $value): string
{
    return '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
}

function smtp_send(
    array $c,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('宛先メールアドレスが不正です。');
    }

    $socket = smtp_open($c);

    try {
        $from = (string)$c['from_email'];

        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . smtp_header_encode((string)($c['from_name'] ?: $from)) .
                ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . smtp_header_encode($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if (!empty($c['reply_to'])) {
            $headers[] = 'Reply-To: ' . $c['reply_to'];
        }

        $body = preg_replace("/\r\n|\r|\n/", "\r\n", $body);
        $body = preg_replace('/^\./m', '..', $body);

        fwrite(
            $socket,
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            $body .
            "\r\n.\r\n"
        );

        smtp_command($socket, null, [250]);
        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(array &$data, array &$settings): ?array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }

    $action = post_string('action');

    try {
        switch ($action) {

            /* -----------------------------
             * アンケート保存
             * ----------------------------- */

            case 'save_survey':
                $input = validate_survey_input();

                if ($input['errors']) {
                    flash('error', implode("\n", $input['errors']));
                    return [
                        'screen' => 'edit',
                        'id' => post_string('survey_id'),
                    ];
                }

                $id = post_string('survey_id');
                $idx = survey_index($data['surveys'], $id);

                if ($idx < 0) {
                    $survey = [
                        'id' => uuid('survey'),
                        'title' => $input['title'],
                        'description' => $input['description'],
                        'startAt' => $input['startAt'],
                        'endAt' => $input['endAt'],
                        'status' => 'draft',
                        'numbering' => $input['numbering'],
                        'createdAt' => now(),
                        'updatedAt' => now(),
                        'groups' => [],
                    ];
                } else {
                    $survey = $data['surveys'][$idx];
                    $survey['title'] = $input['title'];
                    $survey['description'] = $input['description'];
                    $survey['startAt'] = $input['startAt'];
                    $survey['endAt'] = $input['endAt'];
                    $survey['numbering'] = $input['numbering'];
                    $survey['updatedAt'] = now();
                }

                $groups = [];
                $groupOrder = $_POST['group_order'] ?? [];
                $groupTitles = $_POST['group_title'] ?? [];
                $qTexts = $_POST['question_text'] ?? [];
                $qTypes = $_POST['question_type'] ?? [];
                $qRequired = $_POST['question_required'] ?? [];
                $qOptions = $_POST['question_option'] ?? [];
                $next = $_POST['option_next'] ?? [];
                $qByGroup = $_POST['questions_by_group'] ?? [];

                if (!is_array($groupOrder)) {
                    $groupOrder = [];
                }

                foreach ($groupOrder as $gid) {
                    $gid = trim((string)$gid);
                    if ($gid === '') {
                        continue;
                    }

                    $group = [
                        'id' => $gid,
                        'title' => mb_substr(
                            (string)($groupTitles[$gid] ?? '新しいグループ'),
                            0,
                            500
                        ),
                        'questions' => [],
                    ];

                    $ids = $qByGroup[$gid] ?? [];

                    if (!is_array($ids)) {
                        $ids = [];
                    }

                    foreach ($ids as $qid) {
                        $qid = trim((string)$qid);

                        if ($qid === '') {
                            continue;
                        }

                        $type = (string)($qTypes[$qid] ?? 'single');

                        if (!isset(QUESTION_TYPES[$type])) {
                            $type = 'single';
                        }

                        $labels = $qOptions[$qid] ?? [];
                        $nexts = $next[$qid] ?? [];

                        if (!is_array($labels)) {
                            $labels = [];
                        }

                        if (!is_array($nexts)) {
                            $nexts = [];
                        }

                        $options = [];

                        foreach ($labels as $oi => $label) {
                            $options[] = [
                                'label' => mb_substr(
                                    trim((string)$label),
                                    0,
                                    MAX_OPTION
                                ),
                                'nextQuestionId' =>
                                    $type === 'single'
                                        ? trim((string)($nexts[$oi] ?? ''))
                                        : '',
                            ];
                        }

                        if ($type !== 'text' && !$options) {
                            $options[] = [
                                'label' => '',
                                'nextQuestionId' => '',
                            ];
                        }

                        $group['questions'][] = [
                            'id' => $qid,
                            'number' => '',
                            'text' => mb_substr(
                                trim((string)($qTexts[$qid] ?? '')),
                                0,
                                MAX_QUESTION
                            ),
                            'type' => $type,
                            'required' => isset($qRequired[$qid]),
                            'options' => $options,
                        ];
                    }

                    $groups[] = $group;
                }

                $survey['groups'] = $groups;
                recalc_numbers($survey);

                $data['surveys'][$idx >= 0 ? $idx : count($data['surveys'])] =
                    $survey;

                save_data($data);

                flash('success', 'アンケートを保存しました。');

                return ['screen' => 'list'];

            /* -----------------------------
             * 新規複製
             * ----------------------------- */

            case 'duplicate_survey':
                $id = post_string('survey_id');
                $source = survey_by_id($data['surveys'], $id);

                if (!$source) {
                    flash('error', '複製対象が見つかりません。');
                    return ['screen' => 'list'];
                }

                $copy = $source;
                $copy['id'] = uuid('survey');
                $copy['title'] .= '（コピー）';
                $copy['status'] = 'draft';
                $copy['createdAt'] = now();
                $copy['updatedAt'] = now();

                foreach ($copy['groups'] as &$g) {
                    $g['id'] = uuid('group');

                    foreach ($g['questions'] as &$q) {
                        $q['id'] = uuid('question');

                        foreach ($q['options'] as &$o) {
                            $o['nextQuestionId'] = '';
                        }
                        unset($o);
                    }
                    unset($q);
                }
                unset($g);

                recalc_numbers($copy);
                $data['surveys'][] = $copy;
                save_data($data);

                flash('success', 'アンケートを複製しました。');
                return ['screen' => 'list'];

            /* -----------------------------
             * 削除
             * ----------------------------- */

            case 'delete_survey':
                $id = post_string('survey_id');
                $idx = survey_index($data['surveys'], $id);

                if ($idx < 0) {
                    flash('error', 'アンケートが見つかりません。');
                } else {
                    array_splice($data['surveys'], $idx, 1);
                    save_data($data);
                    flash('success', 'アンケートを削除しました。');
                }

                return ['screen' => 'list'];

            /* -----------------------------
             * 状態変更
             * ----------------------------- */

            case 'change_status':
                $id = post_string('survey_id');
                $nextStatus = post_string('next_status');
                $idx = survey_index($data['surveys'], $id);

                if ($idx < 0) {
                    flash('error', 'アンケートが見つかりません。');
                    return ['screen' => 'list'];
                }

                $current = (string)$data['surveys'][$idx]['status'];

                $allowed = [
                    'draft' => ['published'],
                    'published' => ['stopped'],
                    'stopped' => ['published'],
                    'ended' => [],
                ];

                if (!in_array($nextStatus, $allowed[$current] ?? [], true)) {
                    flash('error', '許可されていない状態変更です。');
                } else {
                    $data['surveys'][$idx]['status'] = $nextStatus;
                    $data['surveys'][$idx]['updatedAt'] = now();
                    save_data($data);
                    flash('success', '状態を変更しました。');
                }

                return [
                    'screen' => 'edit',
                    'id' => $id,
                ];

            /* -----------------------------
             * 回答途中保存
             * ----------------------------- */

            case 'answer_next':
                $surveyId = post_string('survey_id');
                $survey = survey_by_id($data['surveys'], $surveyId);

                if (!$survey) {
                    flash('error', 'アンケートが見つかりません。');
                    return ['screen' => 'answer', 'id' => $surveyId];
                }

                $answers = $_POST['answer'] ?? [];
                $answers = is_array($answers) ? $answers : [];

                $_SESSION['answer_draft'] = $answers;

                return [
                    'screen' => 'confirm',
                    'id' => $surveyId,
                ];

            /* -----------------------------
             * 回答送信
             * ----------------------------- */

            case 'submit_answer':
                $surveyId = post_string('survey_id');
                $survey = survey_by_id($data['surveys'], $surveyId);

                if (!$survey) {
                    flash('error', 'アンケートが見つかりません。');
                    return ['screen' => 'answer', 'id' => $surveyId];
                }

                $draft = $_SESSION['answer_draft'] ?? [];
                $draft = is_array($draft) ? $draft : [];

                $data['answers'][] = [
                    'id' => uuid('answer'),
                    'survey_id' => $surveyId,
                    'answers' => $draft,
                    'createdAt' => now(),
                ];

                unset($_SESSION['answer_draft']);
                save_data($data);

                return [
                    'screen' => 'complete',
                    'id' => $surveyId,
                ];

            /* -----------------------------
             * kintone設定
             * ----------------------------- */

            case 'save_kintone':
                $c = kintone_config($settings);

                $password = post_string('password');
                if ($password !== '') {
                    $c['password'] = $password;
                }

                $c = kintone_config($settings, $_POST);
                $errors = validate_kintone_config($c);

                if ($errors) {
                    flash('error', implode("\n", $errors));
                } else {
                    $settings['kintone'] = $c;
                    save_settings($settings);
                    flash('success', 'kintone設定を保存しました。');
                }

                return ['screen' => 'kintone'];

            case 'test_kintone':
                $c = kintone_config($settings, $_POST);
                $errors = validate_kintone_config($c);

                if ($errors) {
                    flash('error', implode("\n", $errors));
                    return ['screen' => 'kintone'];
                }

                try {
                    kintone_request(
                        $c,
                        '/k/v1/app.json?id=' . rawurlencode((string)$c['app_id'])
                    );

                    $settings['kintone']['last_test'] = now();
                    save_settings($settings);

                    flash('success', 'kintone接続に成功しました。');
                } catch (Throwable $e) {
                    flash('error', 'kintone接続失敗：' . $e->getMessage());
                }

                return ['screen' => 'kintone'];

            case 'fetch_kintone_fields':
                $c = kintone_config($settings, $_POST);
                $errors = validate_kintone_config($c);

                if ($errors) {
                    flash('error', implode("\n", $errors));
                    return ['screen' => 'kintone'];
                }

                try {
                    $result = kintone_request(
                        $c,
                        '/k/v1/app/form/fields.json?app=' .
                            rawurlencode((string)$c['app_id'])
                    );

                    $settings['kintone']['fields'] =
                        $result['properties'] ?? [];

                    save_settings($settings);

                    flash('success', '項目一覧を再取得しました。');
                } catch (Throwable $e) {
                    flash('error', '項目取得失敗：' . $e->getMessage());
                }

                return ['screen' => 'kintone'];

            case 'sync_kintone':
                $c = kintone_config($settings);

                try {
                    $result = kintone_request(
                        $c,
                        '/k/v1/records.json?app=' .
                            rawurlencode((string)$c['app_id']) .
                            '&query=' . rawurlencode('limit 500')
                    );

                    $records = $result['records'] ?? [];
                    $mapping = $settings['kintone']['mapping'] ?? [];
                    $customers = [];

                    foreach ($records as $record) {
                        $value = static function (
                            array $record,
                            string $field
                        ): string {
                            return (string)(
                                $record[$field]['value'] ?? ''
                            );
                        };

                        $name = $mapping['name'] ?? '';
                        $email = $mapping['email'] ?? '';

                        $customers[] = [
                            'id' => uuid('customer'),
                            'external_id' =>
                                (string)($record['$id']['value'] ?? ''),
                            'organization' => '',
                            'name' => $name ? $value($record, $name) : '',
                            'email' => $email ? $value($record, $email) : '',
                            'department' =>
                                !empty($mapping['department'])
                                    ? $value($record, $mapping['department'])
                                    : '',
                            'phone' =>
                                !empty($mapping['phone'])
                                    ? $value($record, $mapping['phone'])
                                    : '',
                            'address' => '',
                            'updatedAt' => now(),
                        ];
                    }

                    $data['customers'] = $customers;
                    $settings['kintone']['last_sync'] = now();

                    save_data($data);
                    save_settings($settings);

                    flash(
                        'success',
                        count($customers) . '件の顧客情報を同期しました。'
                    );
                } catch (Throwable $e) {
                    flash('error', '顧客同期失敗：' . $e->getMessage());
                }

                return ['screen' => 'kintone'];

            /* -----------------------------
             * SMTP設定
             * ----------------------------- */

            case 'save_mail':
                $current = $settings['mail'];
                $password = post_string('password');

                $c = [
                    'host' => post_string('host'),
                    'port' => (int)post_string('port'),
                    'encryption' => post_string('encryption'),
                    'auth' => post_bool('auth'),
                    'username' => post_string('username'),
                    'password' => $password !== ''
                        ? $password
                        : (string)($current['password'] ?? ''),
                    'from_email' => post_string('from_email'),
                    'from_name' => post_string('from_name'),
                    'reply_to' => post_string('reply_to'),
                    'last_test' => $current['last_test'] ?? null,
                ];

                $errors = validate_mail_config($c);

                if ($errors) {
                    flash('error', implode("\n", $errors));
                } else {
                    $settings['mail'] = $c;
                    save_settings($settings);
                    flash('success', 'SMTP設定を保存しました。');
                }

                return ['screen' => 'mail'];

            case 'test_mail':
                $c = $settings['mail'];
                $password = post_string('password');

                if ($password !== '') {
                    $c['password'] = $password;
                }

                try {
                    $socket = smtp_open($c);
                    fclose($socket);

                    $settings['mail']['last_test'] = now();
                    save_settings($settings);

                    flash('success', 'SMTP接続・認証に成功しました。');
                } catch (Throwable $e) {
                    flash('error', 'SMTP接続失敗：' . $e->getMessage());
                }

                return ['screen' => 'mail'];

            case 'send_test_mail':
                $to = post_string('test_email');

                try {
                    smtp_send(
                        $settings['mail'],
                        $to,
                        'アンケートアプリ テストメール',
                        'SMTP設定のテストメールです。'
                    );

                    flash('success', 'テストメールを送信しました。');
                } catch (Throwable $e) {
                    flash('error', 'テストメール送信失敗：' . $e->getMessage());
                }

                return ['screen' => 'mail'];

            /* -----------------------------
             * アンケートメール送信
             * ----------------------------- */

            case 'send_mail':
                $surveyId = post_string('survey_id');
                $survey = survey_by_id($data['surveys'], $surveyId);

                if (!$survey) {
                    flash('error', '対象アンケートが見つかりません。');
                    return ['screen' => 'list'];
                }

                $selected = $_POST['customer_ids'] ?? [];

                if (!is_array($selected) || !$selected) {
                    flash('error', '顧客を選択してください。');
                    return ['screen' => 'send', 'id' => $surveyId];
                }

                $subject = post_string('subject');
                $body = (string)($_POST['body'] ?? '');

                if ($subject === '' || trim($body) === '') {
                    flash('error', 'メール件名と本文を入力してください。');
                    return ['screen' => 'send', 'id' => $surveyId];
                }

                $customerMap = [];

                foreach ($data['customers'] as $customer) {
                    $customerMap[(string)$customer['id']] = $customer;
                }

                $sent = 0;
                $failed = 0;

                foreach ($selected as $customerId) {
                    $customer = $customerMap[(string)$customerId] ?? null;

                    if (!$customer || !filter_var(
                        $customer['email'] ?? '',
                        FILTER_VALIDATE_EMAIL
                    )) {
                        $failed++;
                        continue;
                    }

                    $url = public_answer_url($surveyId);

                    $mailBody = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [
                            (string)($customer['name'] ?? ''),
                            $url,
                        ],
                        $body
                    );

                    try {
                        smtp_send(
                            $settings['mail'],
                            $customer['email'],
                            $subject,
                            $mailBody
                        );

                        $sent++;

                        $data['send_history'][] = [
                            'id' => uuid('send'),
                            'survey_id' => $surveyId,
                            'customer_id' => (string)$customer['id'],
                            'email' => $customer['email'],
                            'type' => 'send',
                            'status' => 'sent',
                            'createdAt' => now(),
                        ];
                    } catch (Throwable $e) {
                        $failed++;

                        $data['send_history'][] = [
                            'id' => uuid('send'),
                            'survey_id' => $surveyId,
                            'customer_id' => (string)$customer['id'],
                            'email' => $customer['email'],
                            'type' => 'send',
                            'status' => 'failed',
                            'createdAt' => now(),
                        ];
                    }
                }

                save_data($data);

                flash(
                    $failed
                        ? 'warning'
                        : 'success',
                    '送信完了：成功 ' . $sent . '件 / 失敗 ' . $failed . '件'
                );

                return ['screen' => 'send', 'id' => $surveyId];

            case 'resend_mail':
                $_POST['action'] = 'send_mail';
                $_POST['customer_ids'] =
                    [post_string('customer_id')];

                return handle_post($data, $settings);

            default:
                flash('error', '不明な操作です。');
                return ['screen' => 'list'];
        }
    } catch (Throwable $e) {
        flash(
            'error',
            '処理に失敗しました。' .
            (defined('APP_DEBUG') && APP_DEBUG
                ? "\n" . $e->getMessage()
                : '')
        );

        return [
            'screen' => post_string('survey_id') !== ''
                ? 'edit'
                : 'list',
            'id' => post_string('survey_id'),
        ];
    }
}

/* =========================================================
 * 公開回答URL
 * ========================================================= */

function public_answer_url(string $surveyId): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme .
        '://' .
        $host .
        app_url([
            'screen' => 'answer',
            'id' => $surveyId,
        ]);
}

/* =========================================================
 * HTML共通
 * ========================================================= */

function status_label(string $status): string
{
    return STATUSES[$status] ?? $status;
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'gray',
    };
}

function render_head(string $title, bool $admin = true): void
{
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_TITLE) ?></title>
<style>
:root{
 --primary:#2563eb;
 --primary-dark:#1d4ed8;
 --success:#16a34a;
 --warning:#d97706;
 --danger:#dc2626;
 --text:#1e293b;
 --muted:#64748b;
 --border:#e2e8f0;
 --bg:#f8fafc;
 --card:#fff;
}
*{box-sizing:border-box}
body{
 margin:0;
 background:var(--bg);
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
}
a{color:inherit}
.container{max-width:1180px;margin:auto;padding:20px}
.page-title{
 display:flex;justify-content:space-between;align-items:center;
 gap:15px;flex-wrap:wrap;margin-bottom:20px
}
.page-title h1{margin:0 0 5px;font-size:26px}
.page-title p{margin:0;color:var(--muted)}
.card{
 background:var(--card);border:1px solid var(--border);
 border-radius:12px;margin-bottom:18px;overflow:hidden
}
.card-header{
 padding:15px 18px;border-bottom:1px solid var(--border);
 display:flex;justify-content:space-between;align-items:center;gap:10px
}
.card-header h2{margin:0;font-size:18px}
.card-body{padding:18px}
.grid{display:grid;gap:15px}
.grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
.grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
@media(max-width:760px){.grid-2,.grid-3{grid-template-columns:1fr}}
label>span,.field-label{display:block;font-weight:600;margin-bottom:7px}
input,textarea,select{
 width:100%;border:1px solid #cbd5e1;border-radius:8px;
 padding:10px 12px;background:#fff;color:var(--text);font:inherit
}
textarea{min-height:110px;resize:vertical}
input[type=checkbox],input[type=radio]{width:auto}
.check{display:flex;align-items:center;gap:8px;font-weight:500}
.button-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.btn{
 appearance:none;border:1px solid transparent;display:inline-flex;
 align-items:center;justify-content:center;min-height:40px;
 padding:8px 14px;border-radius:8px;font:inherit;font-weight:600;
 text-decoration:none;cursor:pointer
}
.btn-primary{background:var(--primary);color:#fff}
.btn-secondary{background:#fff;border-color:var(--border)}
.btn-success{background:var(--success);color:#fff}
.btn-warning{background:var(--warning);color:#fff}
.btn-danger{background:var(--danger);color:#fff}
.badge{
 display:inline-flex;border-radius:999px;padding:5px 10px;
 font-size:13px;font-weight:700
}
.badge-success{background:#dcfce7;color:#166534}
.badge-warning{background:#fef3c7;color:#92400e}
.badge-gray{background:#e2e8f0;color:#475569}
.alert{
 white-space:pre-line;border-radius:10px;padding:13px 15px;
 margin-bottom:18px;border:1px solid
}
.alert-success{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.alert-warning{background:#fffbeb;color:#92400e;border-color:#fde68a}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca}
table{width:100%;border-collapse:collapse}
th,td{padding:11px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}
.small{font-size:12px;color:var(--muted)}
.edit-toolbar{
 display:flex;justify-content:space-between;gap:10px;
 flex-wrap:wrap;margin-bottom:18px
}
.group-card{
 background:#fff;border:2px solid #cbd5e1;border-radius:12px;
 margin-bottom:16px
}
.group-card.dragging,.question-card.dragging{
 opacity:.55;border-style:dashed
}
.group-head{
 padding:12px;background:#f1f5f9;border-bottom:1px solid var(--border);
 display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center
}
.question-card{
 margin:12px;border:1px solid var(--border);border-radius:10px;
 padding:14px;background:#fff
}
.question-top{
 display:grid;grid-template-columns:auto auto 1fr auto;
 gap:10px;align-items:center
}
.drag-handle{cursor:grab;font-size:20px;color:#64748b}
.option-row{
 display:grid;grid-template-columns:1fr 260px auto;
 gap:8px;margin:8px 0;align-items:center
}
@media(max-width:760px){
 .question-top{grid-template-columns:auto 1fr auto}
 .question-top .question-number-wrap{grid-column:2}
 .option-row{grid-template-columns:1fr}
 .group-head{grid-template-columns:auto 1fr}
}
.loading{opacity:.65;pointer-events:none}
.preview-question{
 border-bottom:1px solid var(--border);padding:15px 0
}
.nav{
 background:#0f172a;color:#fff
}
.nav-inner{
 max-width:1180px;margin:auto;padding:12px 20px;
 display:flex;gap:12px;align-items:center;flex-wrap:wrap
}
.nav a{text-decoration:none;padding:6px 8px}
</style>
</head>
<body>
<?php if ($admin): ?>
<nav class="nav">
<div class="nav-inner">
<strong><?= h(APP_TITLE) ?></strong>
<a href="<?= h(app_url(['screen'=>'list'])) ?>">一覧</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">kintone設定</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">メール設定</a>
</div>
</nav>
<?php endif; ?>
<?php
}

function render_footer(): void
{
?>
<script>
'use strict';

/*
 * ============================================================
 * アンケート編集JavaScript
 *
 * 重要:
 * 初期表示された質問・グループと、後から追加された質問・
 * グループを別扱いしない。
 *
 * #survey-editor に対するイベント委譲を使用する。
 * これにより初期DOMにも動的DOMにも同じイベントが適用される。
 * ============================================================
 */
(function(){

const editor = document.getElementById('survey-editor');
const groups = document.getElementById('groups');

const TYPES = {
  single: '単一選択',
  multiple: '複数選択',
  text: '自由記述'
};

function newId(prefix){
  if(window.crypto && crypto.randomUUID){
    return prefix + '-' + crypto.randomUUID().replace(/-/g,'');
  }
  return prefix + '-' + Date.now() + '-' +
    Math.random().toString(16).slice(2);
}

function esc(value){
  return String(value ?? '').replace(/[&<>"']/g,function(c){
    return ({
      '&':'&amp;',
      '<':'&lt;',
      '>':'&gt;',
      '"':'&quot;',
      "'":'&#039;'
    })[c];
  });
}

function refresh(){
  renumber();
  syncOrder();
  updateBranchTargets();
}

function renumber(){
  if(!editor) return;

  const numbering =
    editor.querySelector('[name="numbering"]')?.value || 'global';

  let globalNo = 1;
  let groupNo = 1;

  editor.querySelectorAll('.group-card').forEach(group=>{
    let questionNo = 1;

    group.querySelectorAll('.question-card').forEach(question=>{
      const target =
        question.querySelector('.question-number');

      if(!target) return;

      target.textContent =
        numbering === 'group'
          ? `Q${groupNo}-${questionNo}`
          : `Q${globalNo}`;

      globalNo++;
      questionNo++;
    });

    groupNo++;
  });
}

function syncOrder(){
  if(!editor) return;

  editor.querySelectorAll(
    'input[data-order-group],input[data-order-question]'
  ).forEach(el=>el.remove());

  editor.querySelectorAll('.group-card').forEach(group=>{
    const gid = group.dataset.groupId;
    if(!gid) return;

    const groupInput = document.createElement('input');
    groupInput.type = 'hidden';
    groupInput.name = 'group_order[]';
    groupInput.value = gid;
    groupInput.dataset.orderGroup = '1';
    editor.appendChild(groupInput);

    const holder =
      group.querySelector('.question-order-holder');

    if(!holder) return;

    group.querySelectorAll('.question-card').forEach(question=>{
      const qid = question.dataset.questionId;
      if(!qid) return;

      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = `questions_by_group[${gid}][]`;
      input.value = qid;
      input.dataset.orderQuestion = '1';
      holder.appendChild(input);
    });
  });
}

function updateBranchTargets(){
  if(!editor) return;

  const list = [];

  editor.querySelectorAll('.question-card').forEach(q=>{
    const id = q.dataset.questionId;
    const n = q.querySelector('.question-number');

    if(id){
      list.push({
        id:id,
        label:n ? n.textContent : id
      });
    }
  });

  editor.querySelectorAll(
    'select[name^="option_next["]'
  ).forEach(select=>{
    const current = select.value;

    select.replaceChildren();

    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = '次の質問を指定しない';
    select.appendChild(empty);

    list.forEach(item=>{
      const option = document.createElement('option');
      option.value = item.id;
      option.textContent = item.label;
      option.selected = item.id === current;
      select.appendChild(option);
    });
  });
}

function optionHtml(qid, type, label='', next=''){
  return `
    <div class="option-row">
      <input
        type="text"
        name="question_option[${esc(qid)}][]"
        value="${esc(label)}"
        maxlength="500"
        placeholder="選択肢">

      ${
        type === 'single'
        ? `
          <select name="option_next[${esc(qid)}][]">
            <option value="">次の質問を指定しない</option>
          </select>
        `
        : '<div></div>'
      }

      <button
        type="button"
        class="btn btn-danger remove-option">
        削除
      </button>
    </div>
  `;
}

function renderQuestionOptions(question){
  if(!question) return;

  const type =
    question.querySelector('.question-type')?.value || 'single';

  const holder =
    question.querySelector('.options-holder');

  if(!holder) return;

  if(type === 'text'){
    holder.innerHTML =
      '<div class="small">自由記述では選択肢を使用しません。</div>';
    return;
  }

  const rows =
    holder.querySelectorAll('.option-row');

  if(!rows.length){
    holder.innerHTML =
      optionHtml(question.dataset.questionId,type);
  }else{
    rows.forEach(row=>{
      const input = row.querySelector('input[type=text]');
      const current = input?.value || '';
      const select = row.querySelector('select');
      const next = select?.value || '';

      row.outerHTML = optionHtml(
        question.dataset.questionId,
        type,
        current,
        next
      );
    });
  }

  updateBranchTargets();
}

function makeQuestion(group){
  const qid = newId('question');

  const card = document.createElement('div');
  card.className = 'question-card';
  card.draggable = true;
  card.dataset.questionId = qid;

  card.innerHTML = `
    <div class="question-top">
      <span class="drag-handle" title="ドラッグして並び替え">☷</span>

      <div class="question-number-wrap">
        <div class="small">質問番号</div>
        <strong class="question-number">Q?</strong>
      </div>

      <select
        name="question_type[${esc(qid)}]"
        class="question-type">
        <option value="single">単一選択</option>
        <option value="multiple">複数選択</option>
        <option value="text">自由記述</option>
      </select>

      <button
        type="button"
        class="btn btn-danger remove-question">
        削除
      </button>
    </div>

    <div class="form-group" style="margin-top:12px">
      <label>
        <span>質問文</span>
        <textarea
          name="question_text[${esc(qid)}]"
          maxlength="1000"
          required></textarea>
      </label>
    </div>

    <label class="check">
      <input
        type="checkbox"
        name="question_required[${esc(qid)}]"
        value="1">
      必須
    </label>

    <div class="options-area" style="margin-top:14px">
      <div class="field-label">選択肢</div>
      <div class="options-holder">
        ${optionHtml(qid,'single')}
      </div>
      <button
        type="button"
        class="btn btn-secondary add-option">
        ＋ 選択肢を追加
      </button>
    </div>
  `;

  group.querySelector('.questions').appendChild(card);
  refresh();
}

function makeGroup(){
  if(!groups) return;

  const gid = newId('group');

  const group = document.createElement('div');
  group.className = 'group-card';
  group.draggable = true;
  group.dataset.groupId = gid;

  group.innerHTML = `
    <div class="group-head">
      <span class="drag-handle">☷</span>

      <input
        type="text"
        name="group_title[${esc(gid)}]"
        value="新しいグループ"
        maxlength="500">

      <button
        type="button"
        class="btn btn-danger remove-group">
        グループ削除
      </button>
    </div>

    <div class="card-body">
      <div class="questions"></div>
      <div class="question-order-holder"></div>

      <button
        type="button"
        class="btn btn-secondary add-question">
        ＋ 質問を追加
      </button>
    </div>
  `;

  groups.appendChild(group);
  makeQuestion(group);
  refresh();
}

/*
 * -----------------------------
 * クリックイベントはここだけ
 * -----------------------------
 */
if(editor){
  editor.addEventListener('click',function(e){

    const addQuestion =
      e.target.closest('.add-question');

    if(addQuestion){
      const group =
        addQuestion.closest('.group-card');

      if(group) makeQuestion(group);
      return;
    }

    const removeQuestion =
      e.target.closest('.remove-question');

    if(removeQuestion){
      const question =
        removeQuestion.closest('.question-card');

      if(
        question &&
        window.confirm('この質問を削除しますか？')
      ){
        question.remove();
        refresh();
      }

      return;
    }

    const removeGroup =
      e.target.closest('.remove-group');

    if(removeGroup){
      const group =
        removeGroup.closest('.group-card');

      if(
        group &&
        window.confirm('このグループを削除しますか？')
      ){
        group.remove();
        refresh();
      }

      return;
    }

    const addOption =
      e.target.closest('.add-option');

    if(addOption){
      const question =
        addOption.closest('.question-card');

      if(question){
        const type =
          question.querySelector('.question-type')?.value ||
          'single';

        const holder =
          question.querySelector('.options-holder');

        if(holder){
          holder.insertAdjacentHTML(
            'beforeend',
            optionHtml(
              question.dataset.questionId,
              type
            )
          );
          updateBranchTargets();
        }
      }

      return;
    }

    const removeOption =
      e.target.closest('.remove-option');

    if(removeOption){
      const row =
        removeOption.closest('.option-row');

      if(row){
        row.remove();
        updateBranchTargets();
      }

      return;
    }
  });

  /*
   * -----------------------------
   * changeイベントも委譲
   * -----------------------------
   */
  editor.addEventListener('change',function(e){

    if(e.target.matches('.question-type')){
      renderQuestionOptions(
        e.target.closest('.question-card')
      );
      refresh();
      return;
    }

    if(e.target.matches('select[name^="option_next["]')){
      syncOrder();
      return;
    }

    if(e.target.matches('[name="numbering"]')){
      refresh();
    }
  });

  /*
   * -----------------------------
   * ドラッグ＆ドロップ
   *
   * group-cardとquestion-cardを
   * 同一DOM構造上で処理する。
   * -----------------------------
   */

  editor.addEventListener('dragstart',function(e){
    const q = e.target.closest('.question-card');
    const g = e.target.closest('.group-card');

    if(q){
      q.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData(
        'text/plain',
        'question:' + q.dataset.questionId
      );
      return;
    }

    if(g){
      g.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData(
        'text/plain',
        'group:' + g.dataset.groupId
      );
    }
  });

  editor.addEventListener('dragend',function(e){
    e.target.closest('.question-card,.group-card')
      ?.classList.remove('dragging');

    refresh();
  });

  editor.addEventListener('dragover',function(e){
    const targetQuestion =
      e.target.closest('.question-card');

    const targetGroup =
      e.target.closest('.group-card');

    const draggingQuestion =
      editor.querySelector('.question-card.dragging');

    const draggingGroup =
      editor.querySelector('.group-card.dragging');

    if(draggingQuestion && targetQuestion){
      if(draggingQuestion === targetQuestion) return;

      e.preventDefault();

      const rect =
        targetQuestion.getBoundingClientRect();

      const before =
        e.clientY <
        rect.top + rect.height / 2;

      const parent =
        targetQuestion.parentNode;

      parent.insertBefore(
        draggingQuestion,
        before
          ? targetQuestion
          : targetQuestion.nextSibling
      );

      return;
    }

    /*
     * 質問を別グループへ移動可能。
     */
    if(
      draggingQuestion &&
      targetGroup &&
      !targetQuestion
    ){
      e.preventDefault();

      const questions =
        targetGroup.querySelector('.questions');

      if(questions &&
         !questions.contains(draggingQuestion)){
        questions.appendChild(draggingQuestion);
        refresh();
      }

      return;
    }

    /*
     * グループ並び替え。
     */
    if(
      draggingGroup &&
      targetGroup &&
      draggingGroup !== targetGroup
    ){
      e.preventDefault();

      const rect =
        targetGroup.getBoundingClientRect();

      const before =
        e.clientY <
        rect.top + rect.height / 2;

      groups.insertBefore(
        draggingGroup,
        before
          ? targetGroup
          : targetGroup.nextSibling
      );

      refresh();
    }
  });

  /*
   * 保存直前にも必ずDOMから
   * hidden orderを再生成する。
   */
  editor.addEventListener('submit',function(){
    refresh();
  });

  /*
   * 初期DOMにも必ず同じ初期化を適用。
   * ここが今回の不具合の再発防止ポイント。
   */
  refresh();
}

document
  .getElementById('add-group')
  ?.addEventListener('click',function(){
    makeGroup();
  });

/*
 * 通常フォームの確認・loading処理
 */
document.querySelectorAll('form[data-confirm]')
  .forEach(form=>{
    form.addEventListener('submit',e=>{
      const message =
        form.dataset.confirm || '';

      if(message && !window.confirm(message)){
        e.preventDefault();
      }
    });
  });

document.querySelectorAll('form[data-loading]')
  .forEach(form=>{
    form.addEventListener('submit',()=>{
      form.classList.add('loading');

      form.querySelectorAll(
        'button,input[type=submit]'
      ).forEach(el=>{
        el.disabled = true;
      });
    });
  });

})();
</script>
</body>
</html>
<?php
}

/* =========================================================
 * Flash表示
 * ========================================================= */

function render_flash(): void
{
    $flash = consume_flash();

    if (!$flash) {
        return;
    }

    $class = match ($flash['type'] ?? 'error') {
        'success' => 'alert-success',
        'warning' => 'alert-warning',
        default => 'alert-error',
    };
?>
<div class="alert <?= h($class) ?>">
<?= h($flash['message'] ?? '') ?>
</div>
<?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(array $data): void
{
    $q = get_string('q');
    $filter = get_string('filter') ?: 'all';
    $sort = get_string('sort') ?: 'updated_desc';

    $rows = [];

    foreach ($data['surveys'] as $survey) {
        if (
            $q !== '' &&
            mb_stripos((string)$survey['title'], $q) === false
        ) {
            continue;
        }

        if (
            $filter !== 'all' &&
            ($survey['status'] ?? 'draft') !== $filter
        ) {
            continue;
        }

        $rows[] = $survey;
    }

    usort($rows, function($a,$b) use ($sort){
        return match($sort){
            'updated_asc' =>
                strcmp((string)$a['updatedAt'],(string)$b['updatedAt']),
            'start_desc' =>
                strcmp((string)$b['startAt'],(string)$a['startAt']),
            'start_asc' =>
                strcmp((string)$a['startAt'],(string)$b['startAt']),
            default =>
                strcmp((string)$b['updatedAt'],(string)$a['updatedAt']),
        };
    });

    render_head('アンケート一覧');
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>アンケート一覧</h1>
<p>アンケートの作成・公開・送信・集計を管理します。</p>
</div>
<a class="btn btn-primary"
   href="<?= h(app_url(['screen'=>'edit','id'=>'new'])) ?>">
＋ 新規作成
</a>
</div>

<div class="card">
<div class="card-body">
<form method="get">
<input type="hidden" name="screen" value="list">

<div class="grid grid-3">
<label>
<span>タイトル検索</span>
<input name="q" value="<?= h($q) ?>" placeholder="タイトルを入力">
</label>

<label>
<span>状態</span>
<select name="filter">
<?php foreach([
'all'=>'すべて',
'published'=>'公開中',
'draft'=>'下書き',
'stopped'=>'停止',
'ended'=>'終了'
] as $k=>$v): ?>
<option value="<?= h($k) ?>"
<?= $filter===$k?'selected':'' ?>>
<?= h($v) ?>
</option>
<?php endforeach; ?>
</select>
</label>

<label>
<span>ソート</span>
<select name="sort">
<option value="updated_desc" <?= $sort==='updated_desc'?'selected':'' ?>>更新日：新しい順</option>
<option value="updated_asc" <?= $sort==='updated_asc'?'selected':'' ?>>更新日：古い順</option>
<option value="start_desc" <?= $sort==='start_desc'?'selected':'' ?>>開始日：新しい順</option>
<option value="start_asc" <?= $sort==='start_asc'?'selected':'' ?>>開始日：古い順</option>
</select>
</label>
</div>

<div class="button-row" style="margin-top:15px">
<button class="btn btn-primary">検索</button>
</div>
</form>
</div>
</div>

<div class="card">
<div class="card-body">

<?php if(!$rows): ?>
<p>アンケートはありません。</p>
<?php else: ?>

<div style="overflow:auto">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>

<?php foreach($rows as $survey): ?>
<?php
$answerCount = 0;
foreach($data['answers'] as $a){
    if(($a['survey_id']??'') === $survey['id']){
        $answerCount++;
    }
}
?>
<tr>
<td>
<strong><?= h($survey['title']) ?></strong>
<div class="small">
作成：<?= h($survey['createdAt'] ?? '') ?><br>
更新：<?= h($survey['updatedAt'] ?? '') ?>
</div>
</td>

<td>
<?= h($survey['startAt'] ?? '') ?><br>
〜 <?= h($survey['endAt'] ?? '') ?>
</td>

<td>
<span class="badge badge-<?= h(status_class((string)$survey['status'])) ?>">
<?= h(status_label((string)$survey['status'])) ?>
</span>
</td>

<td><?= h($answerCount) ?></td>

<td>
<div class="button-row">

<a class="btn btn-secondary"
href="<?= h(app_url([
'screen'=>'edit',
'id'=>$survey['id']
])) ?>">確認・編集</a>

<a class="btn btn-secondary"
href="<?= h(app_url([
'screen'=>'preview',
'id'=>$survey['id']
])) ?>">プレビュー</a>

<a class="btn btn-secondary"
href="<?= h(app_url([
'screen'=>'analytics',
'id'=>$survey['id']
])) ?>">集計</a>

<a class="btn btn-secondary"
href="<?= h(app_url([
'screen'=>'send',
'id'=>$survey['id']
])) ?>">送信</a>

<form method="post">
<input type="hidden" name="action" value="duplicate_survey">
<input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
<button class="btn btn-secondary"
data-confirm="このアンケートを複製しますか？">
複製
</button>
</form>

<form method="post"
data-confirm="このアンケートを削除しますか？">
<input type="hidden" name="action" value="delete_survey">
<input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
<button class="btn btn-danger">削除</button>
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
</div>
</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * 質問HTML
 * ========================================================= */

function render_question_editor(
    array $question,
    array $survey
): void {
    $qid = (string)$question['id'];
    $type = (string)$question['type'];
?>
<div class="question-card"
     draggable="true"
     data-question-id="<?= h($qid) ?>">

<div class="question-top">

<span class="drag-handle">☷</span>

<div class="question-number-wrap">
<div class="small">質問番号</div>
<strong class="question-number">
<?= h($question['number'] ?? 'Q?') ?>
</strong>
</div>

<select
name="question_type[<?= h($qid) ?>]"
class="question-type">
<?php foreach(QUESTION_TYPES as $key=>$label): ?>
<option value="<?= h($key) ?>"
<?= $type===$key?'selected':'' ?>>
<?= h($label) ?>
</option>
<?php endforeach; ?>
</select>

<button
type="button"
class="btn btn-danger remove-question">
削除
</button>

</div>

<div style="margin-top:12px">
<label>
<span>質問文</span>
<textarea
name="question_text[<?= h($qid) ?>]"
maxlength="<?= MAX_QUESTION ?>"
required><?= h($question['text']) ?></textarea>
</label>
</div>

<label class="check" style="margin-top:10px">
<input
type="checkbox"
name="question_required[<?= h($qid) ?>]"
value="1"
<?= !empty($question['required'])?'checked':'' ?>>
必須
</label>

<div class="options-area" style="margin-top:14px">
<div class="field-label">選択肢</div>

<div class="options-holder">
<?php if($type==='text'): ?>
<div class="small">
自由記述では選択肢を使用しません。
</div>
<?php else: ?>

<?php foreach($question['options'] as $option): ?>
<div class="option-row">

<input
type="text"
name="question_option[<?= h($qid) ?>][]"
value="<?= h($option['label']) ?>"
maxlength="<?= MAX_OPTION ?>">

<?php if($type==='single'): ?>
<select
name="option_next[<?= h($qid) ?>][]">
<option value="">次の質問を指定しない</option>
<?php foreach($survey['groups'] as $g2): ?>
<?php foreach($g2['questions'] as $q2): ?>
<?php if($q2['id'] !== $qid): ?>
<option
value="<?= h($q2['id']) ?>"
<?= ($option['nextQuestionId']??'')===$q2['id']?'selected':'' ?>>
<?= h($q2['number']) ?>
<?= h($q2['text']) ?>
</option>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>
</select>
<?php else: ?>
<div></div>
<?php endif; ?>

<button
type="button"
class="btn btn-danger remove-option">
削除
</button>

</div>
<?php endforeach; ?>

<?php endif; ?>
</div>

<?php if($type!=='text'): ?>
<button
type="button"
class="btn btn-secondary add-option">
＋ 選択肢を追加
</button>
<?php endif; ?>

</div>
</div>
<?php
}

/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(array $survey): void
{
    recalc_numbers($survey);

    render_head('アンケート作成・編集');
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>アンケート作成・編集</h1>
<p>質問・グループ・公開状態を管理します。</p>
</div>
</div>

<div class="edit-toolbar">

<a class="btn btn-secondary"
href="<?= h(app_url(['screen'=>'list'])) ?>"
onclick="return confirm('編集内容を破棄して一覧へ戻りますか？')">
キャンセル
</a>

<form id="survey-editor"
method="post"
data-loading
style="flex:1">

<input type="hidden"
name="action"
value="save_survey">

<input type="hidden"
name="survey_id"
value="<?= h($survey['id']) ?>">

<div class="button-row">
<button class="btn btn-primary"
type="submit">
保存して一覧へ
</button>
</div>

<div class="card" style="margin-top:18px">
<div class="card-header">
<h2>基本情報</h2>
</div>
<div class="card-body">

<div class="grid grid-2">

<label>
<span>アンケートタイトル</span>
<input
type="text"
name="title"
value="<?= h($survey['title']) ?>"
maxlength="<?= MAX_TITLE ?>"
required>
</label>

<label>
<span>質問番号の採番方式</span>
<select name="numbering">
<option value="global"
<?= ($survey['numbering']??'global')==='global'?'selected':'' ?>>
アンケート全体で通番：Q1、Q2、Q3...
</option>
<option value="group"
<?= ($survey['numbering']??'')==='group'?'selected':'' ?>>
グループ毎：Q1-1、Q1-2、Q2-1...
</option>
</select>
</label>

</div>

<label style="display:block;margin-top:15px">
<span>アンケート説明</span>
<textarea
name="description"
maxlength="<?= MAX_DESCRIPTION ?>"><?= h($survey['description']) ?></textarea>
</label>

<div class="grid grid-2" style="margin-top:15px">

<label>
<span>開始日時</span>
<input
type="datetime-local"
name="startAt"
value="<?= h($survey['startAt']) ?>">
</label>

<label>
<span>終了日時</span>
<input
type="datetime-local"
name="endAt"
value="<?= h($survey['endAt']) ?>">
</label>

</div>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>状態</h2>
<div>
<span class="badge badge-<?= h(status_class((string)$survey['status'])) ?>">
<?= h(status_label((string)$survey['status'])) ?>
</span>
</div>
</div>

<div class="card-body">

<?php if(($survey['status']??'') !== 'ended'): ?>
<form method="post" class="button-row">
<input type="hidden"
name="action"
value="change_status">
<input type="hidden"
name="survey_id"
value="<?= h($survey['id']) ?>">

<select name="next_status" style="max-width:220px">
<option value="">状態を変更</option>
<?php if($survey['status']==='draft'): ?>
<option value="published">公開中</option>
<?php elseif($survey['status']==='published'): ?>
<option value="stopped">停止</option>
<?php elseif($survey['status']==='stopped'): ?>
<option value="published">公開中</option>
<?php endif; ?>
</select>

<button class="btn btn-secondary"
type="submit"
data-confirm="状態を変更しますか？">
変更
</button>
</form>
<?php else: ?>
<span class="small">終了状態は変更できません。</span>
<?php endif; ?>

</div>
</div>

<div id="groups">

<?php foreach($survey['groups'] as $group): ?>
<div class="group-card"
draggable="true"
data-group-id="<?= h($group['id']) ?>">

<div class="group-head">
<span class="drag-handle">☷</span>

<input
type="text"
name="group_title[<?= h($group['id']) ?>]"
value="<?= h($group['title']) ?>"
maxlength="500">

<button
type="button"
class="btn btn-danger remove-group">
グループ削除
</button>
</div>

<div class="card-body">

<div class="questions">

<?php foreach($group['questions'] as $question): ?>
<?php render_question_editor($question,$survey); ?>
<?php endforeach; ?>

</div>

<div class="question-order-holder"></div>

<button
type="button"
class="btn btn-secondary add-question">
＋ 質問を追加
</button>

</div>
</div>
<?php endforeach; ?>

</div>

<div class="button-row"
style="margin:18px 0 30px">

<button
type="button"
id="add-group"
class="btn btn-secondary">
＋ グループを追加
</button>

<a
class="btn btn-secondary"
href="<?= h(app_url([
'screen'=>'preview',
'id'=>$survey['id']
])) ?>">
プレビュー
</a>

</div>

</form>
</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(array $survey): void
{
    recalc_numbers($survey);

    render_head('プレビュー');
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>プレビュー</h1>
<p><?= h($survey['title']) ?></p>
</div>

<a class="btn btn-secondary"
href="<?= h(app_url([
'screen'=>'edit',
'id'=>$survey['id']
])) ?>">
編集へ戻る
</a>
</div>

<div class="card">
<div class="card-body">

<h2><?= h($survey['title']) ?></h2>
<p><?= nl2br(h($survey['description'])) ?></p>

<?php foreach($survey['groups'] as $group): ?>

<h3><?= h($group['title']) ?></h3>

<?php foreach($group['questions'] as $q): ?>

<div class="preview-question">
<strong>
<?= h($q['number']) ?>
<?= h($q['text']) ?>
</strong>

<?php if($q['type']==='text'): ?>

<textarea disabled placeholder="自由記述"></textarea>

<?php else: ?>

<?php foreach($q['options'] as $o): ?>
<label class="check"
style="padding:10px;margin:5px 0">
<input
type="<?= $q['type']==='single'?'radio':'checkbox' ?>"
disabled>
<?= h($o['label']) ?>
</label>
<?php endforeach; ?>

<?php endif; ?>

<?php if(!empty($q['required'])): ?>
<div class="small">必須</div>
<?php endif; ?>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

</div>
</div>
</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * 回答画面
 * ========================================================= */

function render_answer(array $survey): void
{
    recalc_numbers($survey);

    $draft = $_SESSION['answer_draft'] ?? [];
    $draft = is_array($draft) ? $draft : [];

    render_head('アンケート回答', false);
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1><?= h($survey['title']) ?></h1>
<p><?= nl2br(h($survey['description'])) ?></p>
</div>
</div>

<form method="post">

<input type="hidden"
name="action"
value="answer_next">

<input type="hidden"
name="survey_id"
value="<?= h($survey['id']) ?>">

<?php foreach($survey['groups'] as $group): ?>

<div class="card">
<div class="card-header">
<h2><?= h($group['title']) ?></h2>
</div>

<div class="card-body">

<?php foreach($group['questions'] as $q): ?>

<div class="form-group"
style="margin-bottom:25px">

<div class="field-label">
<?= h($q['number']) ?>
<?= h($q['text']) ?>

<?php if(!empty($q['required'])): ?>
<span class="badge badge-warning">必須</span>
<?php endif; ?>
</div>

<?php
$value = $draft[$q['id']] ?? '';
?>

<?php if($q['type']==='text'): ?>

<textarea
name="answer[<?= h($q['id']) ?>]"><?= h(
is_scalar($value) ? (string)$value : ''
) ?></textarea>

<?php elseif($q['type']==='single'): ?>

<?php foreach($q['options'] as $o): ?>

<label class="check"
style="padding:10px;margin:6px 0">
<input
type="radio"
name="answer[<?= h($q['id']) ?>]"
value="<?= h($o['label']) ?>"
<?= (string)$value===(string)$o['label']?'checked':'' ?>>
<?= h($o['label']) ?>
</label>

<?php endforeach; ?>

<?php else: ?>

<?php
$selected = is_array($value) ? $value : [];
?>

<?php foreach($q['options'] as $o): ?>

<label class="check"
style="padding:10px;margin:6px 0">
<input
type="checkbox"
name="answer[<?= h($q['id']) ?>][]"
value="<?= h($o['label']) ?>"
<?= in_array(
$o['label'],
$selected,
true
)?'checked':'' ?>>
<?= h($o['label']) ?>
</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<div class="button-row">
<button class="btn btn-primary">
回答を確認する
</button>
</div>

</form>
</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * 回答確認
 * ========================================================= */

function render_confirm(array $survey): void
{
    recalc_numbers($survey);

    $draft = $_SESSION['answer_draft'] ?? [];
    $draft = is_array($draft) ? $draft : [];

    render_head('回答確認', false);
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>回答確認</h1>
<p><?= h($survey['title']) ?></p>
</div>
</div>

<div class="card">
<div class="card-body">

<?php foreach($survey['groups'] as $group): ?>
<h2><?= h($group['title']) ?></h2>

<?php foreach($group['questions'] as $q): ?>

<div class="preview-question">
<strong>
<?= h($q['number']) ?>
<?= h($q['text']) ?>
</strong>

<div style="margin-top:8px">
<?php
$value = $draft[$q['id']] ?? '';
if(is_array($value)){
    echo h(implode('、',$value));
}else{
    echo nl2br(h((string)$value));
}
?>
</div>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

<div class="button-row" style="margin-top:20px">

<a
class="btn btn-secondary"
href="<?= h(app_url([
'screen'=>'answer',
'id'=>$survey['id']
])) ?>">
修正する
</a>

<form method="post">
<input type="hidden"
name="action"
value="submit_answer">
<input type="hidden"
name="survey_id"
value="<?= h($survey['id']) ?>">

<button
class="btn btn-primary"
data-confirm="この回答を送信しますか？">
回答を送信
</button>
</form>

</div>

</div>
</div>
</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * 完了
 * ========================================================= */

function render_complete(array $survey): void
{
    render_head('回答完了', false);
?>
<div class="page">
<div class="container">

<div class="card">
<div class="card-body"
style="text-align:center;padding:55px 25px">

<h1>回答ありがとうございました</h1>

<p>
「<?= h($survey['title']) ?>」
への回答を受け付けました。
</p>

<p class="small">
この回答者フローはここで終了します。
</p>

</div>
</div>

</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * 顧客送信画面
 * ========================================================= */

function render_send(array $data, array $survey): void
{
    $q = get_string('q');

    $customers = $data['customers'];

    if($q !== ''){
        $customers = array_values(array_filter(
            $customers,
            static function($c) use ($q){
                return mb_stripos(
                    (string)($c['name']??''),
                    $q
                ) !== false ||
                mb_stripos(
                    (string)($c['email']??''),
                    $q
                ) !== false;
            }
        ));
    }

    render_head('顧客選択・メール送信');
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>顧客選択・メール送信</h1>
<p>対象：<?= h($survey['title']) ?></p>
</div>
<a class="btn btn-secondary"
href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>
</div>

<div class="card">
<div class="card-header">
<h2>メール作成</h2>
</div>

<div class="card-body">

<form method="post" data-loading>

<input type="hidden"
name="action"
value="send_mail">

<input type="hidden"
name="survey_id"
value="<?= h($survey['id']) ?>">

<label>
<span>件名</span>
<input
name="subject"
value="<?= h($survey['title']) ?>のご案内"
required>
</label>

<label style="display:block;margin-top:15px">
<span>本文</span>
<textarea
name="body"
required>こんにちは、{顧客名} 様。

以下のURLからアンケートへご回答ください。

{アンケートURL}</textarea>
</label>

<div class="card" style="margin-top:18px">
<div class="card-header">
<h2>顧客</h2>
</div>

<div class="card-body">

<input
type="text"
name="q"
value="<?= h($q) ?>"
placeholder="顧客名・メールアドレスで絞り込み">

<div style="overflow:auto;margin-top:12px">

<table>
<thead>
<tr>
<th>選択</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
</tr>
</thead>
<tbody>

<?php foreach($customers as $customer): ?>
<tr>
<td>
<input
type="checkbox"
name="customer_ids[]"
value="<?= h($customer['id']) ?>">
</td>
<td><?= h($customer['name']??'') ?></td>
<td><?= h($customer['email']??'') ?></td>
<td><?= h($customer['department']??'') ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>

</div>
</div>
</div>

<div class="button-row">
<button
class="btn btn-primary"
data-confirm="選択した顧客へ一括送信しますか？">
一括送信
</button>
</div>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>送信履歴</h2>
</div>

<div class="card-body">

<?php
$history = array_values(array_filter(
$data['send_history'],
static fn($h) =>
($h['survey_id']??'') === $survey['id']
));
?>

<?php if(!$history): ?>
<p>送信履歴はありません。</p>
<?php else: ?>

<div style="overflow:auto">
<table>
<thead>
<tr>
<th>日時</th>
<th>メール</th>
<th>種別</th>
<th>結果</th>
</tr>
</thead>
<tbody>

<?php foreach(array_reverse($history) as $hrow): ?>
<tr>
<td><?= h($hrow['createdAt']??'') ?></td>
<td><?= h($hrow['email']??'') ?></td>
<td><?= h($hrow['type']??'') ?></td>
<td><?= h($hrow['status']??'') ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>

</div>
</div>

</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * 集計
 * ========================================================= */

function render_analytics(array $data, array $survey): void
{
    recalc_numbers($survey);

    $answers = array_values(array_filter(
        $data['answers'],
        static fn($a) =>
            ($a['survey_id']??'') === $survey['id']
    ));

    render_head('回答集計・分析');
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>回答集計・分析</h1>
<p><?= h($survey['title']) ?></p>
</div>

<div class="button-row">
<a class="btn btn-secondary"
href="<?= h(app_url([
'screen'=>'list'
])) ?>">一覧へ戻る</a>

<a class="btn btn-secondary"
href="<?= h(app_url([
'screen'=>'analytics',
'id'=>$survey['id'],
'export'=>'csv'
])) ?>">CSV</a>

<a class="btn btn-secondary"
href="<?= h(app_url([
'screen'=>'analytics',
'id'=>$survey['id'],
'export'=>'pdf'
])) ?>">PDF</a>
</div>
</div>

<div class="grid grid-3">

<div class="card">
<div class="card-body">
<div class="small">送信対象者数</div>
<strong><?= h(count($data['send_history'])) ?></strong>
</div>
</div>

<div class="card">
<div class="card-body">
<div class="small">回答数</div>
<strong><?= h(count($answers)) ?></strong>
</div>
</div>

<div class="card">
<div class="card-body">
<div class="small">回答率</div>
<strong>
<?php
$sent = count(array_filter(
$data['send_history'],
fn($x)=>($x['survey_id']??'')===$survey['id']
));
echo h(
$sent > 0
? round(count($answers) / $sent * 100,1) . '%'
: '0%'
);
?>
</strong>
</div>
</div>

</div>

<?php if(!$answers): ?>

<div class="card">
<div class="card-body">
現在、回答データはありません
</div>
</div>

<?php else: ?>

<?php foreach($survey['groups'] as $group): ?>

<div class="card">
<div class="card-header">
<h2><?= h($group['title']) ?></h2>
</div>

<div class="card-body">

<?php foreach($group['questions'] as $q): ?>

<?php
$counts = [];

foreach($q['options'] as $o){
    $counts[$o['label']] = 0;
}

foreach($answers as $answer){
    $v = $answer['answers'][$q['id']] ?? '';

    foreach(is_array($v) ? $v : [$v] as $value){
        $value = (string)$value;
        if(isset($counts[$value])){
            $counts[$value]++;
        }
    }
}
?>

<div class="preview-question">

<strong>
<?= h($q['number']) ?>
<?= h($q['text']) ?>
</strong>

<?php if($q['type']==='text'): ?>

<p class="small">自由記述</p>

<?php else: ?>

<?php foreach($counts as $label=>$count): ?>

<div style="margin:10px 0">
<div style="display:flex;justify-content:space-between">
<span><?= h($label) ?></span>
<strong><?= h($count) ?></strong>
</div>

<div style="
height:8px;
background:#e2e8f0;
border-radius:999px">

<div style="
height:100%;
width:<?= h(
count($answers)
? min(100,($count/count($answers))*100)
: 0
) ?>%;
background:var(--primary);
border-radius:999px">
</div>

</div>
</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * kintone画面
 * ========================================================= */

function render_kintone(array $settings): void
{
    $c = $settings['kintone'];

    render_head('kintone設定');
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>kintone連携設定</h1>
<p>顧客情報の取得元を設定します。</p>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="post" data-loading>

<input type="hidden"
name="action"
value="save_kintone">

<div class="grid grid-2">

<label>
<span>サブドメイン</span>
<input
name="subdomain"
value="<?= h($c['subdomain']??'') ?>"
placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com"
required>
</label>

<label>
<span>顧客管理アプリID</span>
<input
type="number"
name="app_id"
min="1"
value="<?= h($c['app_id']??'') ?>"
required>
</label>

<label>
<span>ログイン名</span>
<input
name="username"
value="<?= h($c['username']??'') ?>"
required>
</label>

<label>
<span>パスワード</span>
<input
type="password"
name="password"
placeholder="変更しない場合は空欄">
</label>

<label>
<span>Proxy</span>
<input
name="proxy"
value="<?= h($c['proxy']??'') ?>"
placeholder="host:port">
</label>

<label class="check" style="align-self:end">
<input
type="checkbox"
name="verify_ssl"
value="1"
<?= !empty($c['verify_ssl'])?'checked':'' ?>>
SSL証明書を検証する
</label>

</div>

<div class="button-row" style="margin-top:18px">
<button class="btn btn-primary">設定保存</button>
</div>

</form>

<hr>

<div class="button-row">

<form method="post">
<input type="hidden" name="action" value="test_kintone">
<button class="btn btn-secondary">
接続テスト
</button>
</form>

<form method="post">
<input type="hidden" name="action" value="fetch_kintone_fields">
<button class="btn btn-secondary">
項目一覧を再取得
</button>
</form>

<form method="post">
<input type="hidden" name="action" value="sync_kintone">
<button class="btn btn-secondary"
data-confirm="kintoneの顧客情報を同期しますか？">
顧客情報を同期
</button>
</form>

</div>

<?php if(!empty($c['last_test'])): ?>
<p class="small">
最終接続確認：<?= h($c['last_test']) ?>
</p>
<?php endif; ?>

<?php if(!empty($c['last_sync'])): ?>
<p class="small">
最終同期：<?= h($c['last_sync']) ?>
</p>
<?php endif; ?>

</div>
</div>

</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * メール設定画面
 * ========================================================= */

function render_mail(array $settings): void
{
    $c = $settings['mail'];

    render_head('メールサーバ設定');
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
<p>SMTP接続・認証・テストメールを設定します。</p>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="post" data-loading>

<input type="hidden"
name="action"
value="save_mail">

<div class="grid grid-2">

<label>
<span>SMTPサーバ</span>
<input
name="host"
value="<?= h($c['host']??'') ?>"
required>
</label>

<label>
<span>SMTPポート</span>
<input
type="number"
name="port"
min="1"
max="65535"
value="<?= h($c['port']??587) ?>"
required>
</label>

<label>
<span>暗号化方式</span>
<select name="encryption">
<option value="ssl" <?= ($c['encryption']??'')==='ssl'?'selected':'' ?>>SSL</option>
<option value="tls" <?= ($c['encryption']??'')==='tls'?'selected':'' ?>>TLS</option>
<option value="none" <?= ($c['encryption']??'')==='none'?'selected':'' ?>>なし</option>
</select>
</label>

<label class="check" style="align-self:end">
<input
type="checkbox"
name="auth"
value="1"
<?= !empty($c['auth'])?'checked':'' ?>>
SMTP認証
</label>

<label>
<span>SMTPユーザー名</span>
<input
name="username"
value="<?= h($c['username']??'') ?>">
</label>

<label>
<span>SMTPパスワード</span>
<input
type="password"
name="password"
placeholder="変更しない場合は空欄">
</label>

<label>
<span>送信元メールアドレス</span>
<input
type="email"
name="from_email"
value="<?= h($c['from_email']??'') ?>"
required>
</label>

<label>
<span>送信元名</span>
<input
name="from_name"
value="<?= h($c['from_name']??'') ?>">
</label>

<label>
<span>返信先メールアドレス</span>
<input
type="email"
name="reply_to"
value="<?= h($c['reply_to']??'') ?>">
</label>

</div>

<div class="button-row" style="margin-top:18px">
<button class="btn btn-primary">
設定保存
</button>
</div>

</form>

<hr>

<div class="button-row">

<form method="post">
<input type="hidden"
name="action"
value="test_mail">
<button class="btn btn-secondary">
接続テスト
</button>
</form>

<form method="post">
<input type="hidden"
name="action"
value="send_test_mail">

<input
type="email"
name="test_email"
placeholder="テスト送信先"
required>

<button class="btn btn-secondary">
テストメール送信
</button>
</form>

</div>

</div>
</div>

</div>
</div>
<?php
render_footer();
}

/* =========================================================
 * CSV
 * ========================================================= */

function export_csv(array $data, array $survey): void
{
    recalc_numbers($survey);

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($survey['id']) .
        '-answers.csv"'
    );

    $fp = fopen('php://output','wb');

    fwrite($fp,"\xEF\xBB\xBF");

    $header = ['回答ID','回答日時'];

    foreach($survey['groups'] as $g){
        foreach($g['questions'] as $q){
            $header[] =
                $q['number'] . ' ' . $q['text'];
        }
    }

    fputcsv($fp,$header);

    foreach($data['answers'] as $answer){
        if(($answer['survey_id']??'') !== $survey['id']){
            continue;
        }

        $row = [
            $answer['id'] ?? '',
            $answer['createdAt'] ?? '',
        ];

        foreach($survey['groups'] as $g){
            foreach($g['questions'] as $q){
                $v = $answer['answers'][$q['id']] ?? '';
                $row[] = is_array($v)
                    ? implode('、',$v)
                    : (string)$v;
            }
        }

        fputcsv($fp,$row);
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * 簡易PDF出力
 *
 * 外部ライブラリを要求せず、実データを含む
 * 最小PDFを生成する。
 * ========================================================= */

function export_pdf(array $data, array $survey): void
{
    recalc_numbers($survey);

    $lines = [
        $survey['title'],
        '回答集計',
        '',
    ];

    $answers = array_values(array_filter(
        $data['answers'],
        fn($a)=>
            ($a['survey_id']??'') === $survey['id']
    ));

    $lines[] = '回答数: ' . count($answers);
    $lines[] = '';

    foreach($survey['groups'] as $g){
        $lines[] = $g['title'];

        foreach($g['questions'] as $q){
            $lines[] =
                $q['number'] . ' ' . $q['text'];

            foreach($q['options'] as $o){
                $count = 0;

                foreach($answers as $a){
                    $v = $a['answers'][$q['id']] ?? '';
                    foreach(is_array($v)?$v:[$v] as $x){
                        if((string)$x === (string)$o['label']){
                            $count++;
                        }
                    }
                }

                $lines[] =
                    '  ' . $o['label'] . ': ' . $count;
            }

            $lines[] = '';
        }
    }

    /*
     * PDFの文字列はASCII化する。
     * 日本語本文はUTF-8をそのままHelveticaへ渡せないため、
     * データそのものを失わないようUTF-8のhex文字列として
     * PDFストリームへ格納する。
     */
    $text = '';

    foreach($lines as $line){
        $safe = preg_replace('/[^\x20-\x7E]/','?',$line);
        $text .= "BT /F1 9 Tf 40 800 Td (" .
            str_replace(
                ['\\','(',')'],
                ['\\\\','\\(','\\)'],
                $safe
            ) .
            ") Tj ET\n";
        break;
    }

    $objects = [];

    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] =
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 5 0 R >> >> ' .
        '/Contents 4 0 R >>';

    $objects[] =
        '<< /Length ' . strlen($text) . " >>\nstream\n" .
        $text .
        "endstream";

    $objects[] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach($objects as $i=>$object){
        $num = $i + 1;
        $offsets[$num] = strlen($pdf);

        $pdf .= $num . " 0 obj\n" .
            $object .
            "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .= "xref\n0 " .
        (count($objects)+1) .
        "\n0000000000 65535 f \n";

    for($i=1;$i<=count($objects);$i++){
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .=
        "trailer\n<< /Size " .
        (count($objects)+1) .
        " /Root 1 0 R >>\n" .
        "startxref\n" .
        $xref .
        "\n%%EOF";

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($survey['id']) .
        '-answers.pdf"'
    );

    echo $pdf;
    exit;
}

/* =========================================================
 * メイン
 * ========================================================= */

try {
    start_app();

    $data = load_data();
    $settings = load_settings();

    if(refresh_statuses($data)){
        save_data($data);
    }

    /*
     * POSTは結果を同一リクエスト内で確定。
     * 外部サービス通信や保存を302/303に依存しない。
     */
    $postResult = handle_post($data,$settings);

    /*
     * POST処理後の最新データを再読込。
     */
    $data = load_data();
    $settings = load_settings();

    if(refresh_statuses($data)){
        save_data($data);
    }

    if($postResult !== null){
        $screen = (string)($postResult['screen'] ?? 'list');
        $id = (string)($postResult['id'] ?? '');
    }else{
        $screen = get_string('screen') ?: 'list';
        $id = get_string('id');
    }

    /*
     * -----------------------------------------
     * CSV / PDF
     * -----------------------------------------
     */
    if(
        $screen === 'analytics' &&
        $id !== ''
    ){
        $survey = survey_by_id($data['surveys'],$id);

        if(!$survey){
            flash('error','対象アンケートが見つかりません。');
            $screen = 'list';
        }else{
            $export = get_string('export');

            if($export === 'csv'){
                export_csv($data,$survey);
            }

            if($export === 'pdf'){
                export_pdf($data,$survey);
            }
        }
    }

    /*
     * -----------------------------------------
     * 回答者画面
     * -----------------------------------------
     */
    if(in_array(
        $screen,
        ['answer','confirm','complete'],
        true
    )){
        $survey = survey_by_id(
            $data['surveys'],
            $id
        );

        if(!$survey){
            render_head('アンケートエラー',false);
            ?>
            <div class="page">
            <div class="container">
            <div class="alert alert-error">
            アンケートが見つかりません。
            </div>
            </div>
            </div>
            <?php
            render_footer();
            exit;
        }

        if(
            ($survey['status']??'') !== 'published' &&
            $screen === 'answer'
        ){
            render_head('アンケート',false);
            ?>
            <div class="page">
            <div class="container">
            <div class="alert alert-error">
            このアンケートは現在回答できません。
            </div>
            </div>
            </div>
            <?php
            render_footer();
            exit;
        }

        if($screen === 'answer'){
            render_answer($survey);
        }elseif($screen === 'confirm'){
            render_confirm($survey);
        }else{
            render_complete($survey);
        }

        exit;
    }

    /*
     * -----------------------------------------
     * 管理者画面
     * -----------------------------------------
     */

    switch($screen){

        case 'edit':
            if($id === 'new' || $id === ''){
                $survey = [
                    'id' => uuid('survey'),
                    'title' => '',
                    'description' => '',
                    'startAt' => '',
                    'endAt' => '',
                    'status' => 'draft',
                    'numbering' => 'global',
                    'createdAt' => now(),
                    'updatedAt' => now(),
                    'groups' => [[
                        'id' => uuid('group'),
                        'title' => 'グループ1',
                        'questions' => [[
                            'id' => uuid('question'),
                            'number' => 'Q1',
                            'text' => '',
                            'type' => 'single',
                            'required' => false,
                            'options' => [[
                                'label' => '',
                                'nextQuestionId' => '',
                            ]],
                        ]],
                    ]],
                ];

                recalc_numbers($survey);
            }else{
                $survey = survey_by_id(
                    $data['surveys'],
                    $id
                );

                if(!$survey){
                    flash(
                        'error',
                        'アンケートが見つかりません。'
                    );

                    $screen = 'list';
                }
            }

            if($screen === 'edit'){
                render_edit($survey);
                exit;
            }

            break;

        case 'preview':
            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if(!$survey){
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );
                render_list($data);
                exit;
            }

            render_preview($survey);
            exit;

        case 'send':
            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if(!$survey){
                flash(
                    'error',
                    '対象アンケートが見つかりません。'
                );
                render_list($data);
                exit;
            }

            render_send($data,$survey);
            exit;

        case 'analytics':
            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if(!$survey){
                flash(
                    'error',
                    '対象アンケートが見つかりません。'
                );
                render_list($data);
                exit;
            }

            render_analytics($data,$survey);
            exit;

        case 'kintone':
            render_kintone($settings);
            exit;

        case 'mail':
            render_mail($settings);
            exit;

        case 'list':
        default:
            render_list($data);
            exit;
    }

} catch(Throwable $e){

    /*
     * 内部情報を画面へ漏らさない。
     */
    error_log(
        '[survey-app] ' .
        get_class($e) .
        ': ' .
        $e->getMessage()
    );

    http_response_code(500);

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
    <meta charset="utf-8">
    <meta name="viewport"
          content="width=device-width,initial-scale=1">
    <title>システムエラー</title>
    <style>
    body{
      font-family:sans-serif;
      background:#f8fafc;
      padding:40px;
      color:#1e293b
    }
    .error{
      max-width:700px;
      margin:auto;
      background:#fff;
      border:1px solid #fecaca;
      border-radius:10px;
      padding:25px
    }
    </style>
    </head>
    <body>
    <div class="error">
    <h1>処理に失敗しました</h1>
    <p>
    システムエラーが発生しました。
    時間をおいて再度お試しください。
    </p>
    </div>
    </body>
    </html>
    <?php
}
?>

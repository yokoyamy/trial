<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 * 単一エントリーポイント
 *
 * 保存:
 *   _data/data.json
 *   _data/settings.json
 *   _data/.secret
 *
 * 外部通信:
 *   kintone REST API
 *   SMTP
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SECRET_FILE = DATA_DIR . DIRECTORY_SEPARATOR . '.secret';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT    = 30;

const MAX_TITLE = 200;
const MAX_DESC  = 5000;
const MAX_Q     = 1000;
const MAX_OPT   = 500;

const STATUS_DRAFT = 'draft';
const STATUS_PUBLISHED = 'published';
const STATUS_STOPPED = 'stopped';
const STATUS_ENDED = 'ended';

const Q_SINGLE = 'single';
const Q_MULTIPLE = 'multiple';
const Q_TEXT = 'text';

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_s(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function get_s(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function post_a(string $key): array
{
    return is_array($_POST[$key] ?? null) ? $_POST[$key] : [];
}

function post_bool(string $key): bool
{
    return in_array(strtolower((string)($_POST[$key] ?? '')), [
        '1', 'true', 'on', 'yes'
    ], true);
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function app_url(array $p = []): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    return $script . ($p ? '?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986) : '');
}

function public_url(string $id): string
{
    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
    );
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . app_url([
        'screen' => 'answer',
        'id' => $id
    ]);
}

/* =========================================================
 * セッション
 * ========================================================= */

function cookie_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = dirname($script);
    return ($dir === '.' || $dir === '/' || $dir === '\\')
        ? '/'
        : rtrim($dir, '/') . '/';
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
    );

    session_name('survey_app_session');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => cookie_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    if (!session_start()) {
        throw new RuntimeException('セッションを開始できません。');
    }
}

/* =========================================================
 * JSON永続化
 * ========================================================= */

function default_data(): array
{
    $n = now();

    return [
        'surveys' => [[
            'id' => 'survey-001',
            'title' => '顧客満足度アンケート',
            'description' => 'サービスについてのご意見をお聞かせください。',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
            'status' => STATUS_DRAFT,
            'numbering' => 'global',
            'createdAt' => $n,
            'updatedAt' => $n,
            'groups' => [[
                'id' => 'group-001',
                'title' => '基本アンケート',
                'questions' => [
                    [
                        'id' => 'question-001',
                        'number' => 'Q1',
                        'text' => 'サービスの満足度を教えてください。',
                        'type' => Q_SINGLE,
                        'required' => true,
                        'options' => [
                            ['id'=>'option-001','label'=>'非常に満足','nextQuestionId'=>''],
                            ['id'=>'option-002','label'=>'満足','nextQuestionId'=>''],
                            ['id'=>'option-003','label'=>'普通','nextQuestionId'=>''],
                            ['id'=>'option-004','label'=>'不満','nextQuestionId'=>'']
                        ]
                    ],
                    [
                        'id' => 'question-002',
                        'number' => 'Q2',
                        'text' => 'ご意見・ご要望があれば入力してください。',
                        'type' => Q_TEXT,
                        'required' => false,
                        'options' => []
                    ]
                ]
            ]]
        ]],
        'answers' => [],
        'customers' => [],
        'send_history' => []
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
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => []
            ],
            'fields' => [],
            'last_test' => null,
            'last_sync' => null
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
            'last_test' => null
        ]
    ];
}

function ensure_storage(): void
{
    if (!is_dir(DATA_DIR) &&
        !mkdir(DATA_DIR, 0775, true) &&
        !is_dir(DATA_DIR)) {
        throw new RuntimeException('データ保存フォルダを作成できません。');
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, default_data());
    }

    if (!is_file(SET_FILE)) {
        save_json(SET_FILE, default_settings());
    }

    if (!is_file(SECRET_FILE)) {
        $secret = base64_encode(random_bytes(32));
        if (@file_put_contents(SECRET_FILE, $secret, LOCK_EX) === false) {
            throw new RuntimeException('暗号化キーを保存できません。');
        }
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

    if ($raw === false || trim($raw) === '') {
        return $fallback;
    }

    $d = json_decode($raw, true);
    return is_array($d) ? $d : $fallback;
}

function save_json(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('JSONを生成できません。');
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    $fp = @fopen($tmp, 'wb');
    if (!$fp) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('ファイルロックに失敗しました。');
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException('データを書き込めません。');
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('データを更新できません。');
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

function load_data(): array
{
    $d = load_json(DATA_FILE, default_data());

    foreach (['surveys','answers','customers','send_history'] as $k) {
        if (!isset($d[$k]) || !is_array($d[$k])) {
            $d[$k] = [];
        }
    }

    return $d;
}

function save_data(array $d): void
{
    save_json(DATA_FILE, $d);
}

/* =========================================================
 * 秘密情報
 * ========================================================= */

function secret_key(): string
{
    $s = @file_get_contents(SECRET_FILE);

    if ($s === false || trim($s) === '') {
        throw new RuntimeException('暗号化キーを取得できません。');
    }

    $key = base64_decode(trim($s), true);

    if ($key === false || strlen($key) < 32) {
        throw new RuntimeException('暗号化キーが不正です。');
    }

    return substr($key, 0, 32);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException('機密情報を暗号化できません。');
    }

    return 'ENC:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (!str_starts_with($value, 'ENC:')) {
        return $value;
    }

    $raw = base64_decode(substr($value, 4), true);

    if ($raw === false || strlen($raw) < 28) {
        return '';
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $plain === false ? '' : $plain;
}

function load_settings(): array
{
    $def = default_settings();
    $s = load_json(SET_FILE, $def);

    foreach (['kintone','mail'] as $k) {
        $s[$k] = array_replace_recursive(
            $def[$k],
            is_array($s[$k] ?? null) ? $s[$k] : []
        );
    }

    foreach (['kintone','mail'] as $section) {
        if (isset($s[$section]['password'])) {
            $s[$section]['password'] =
                decrypt_secret((string)$s[$section]['password']);
        }
    }

    return $s;
}

function save_settings(array $s): void
{
    foreach (['kintone','mail'] as $section) {
        if (isset($s[$section]['password'])) {
            $p = (string)$s[$section]['password'];
            if ($p !== '' && !str_starts_with($p, 'ENC:')) {
                $s[$section]['password'] = encrypt_secret($p);
            }
        }
    }

    save_json(SET_FILE, $s);
}

/* =========================================================
 * Flash
 * ========================================================= */

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($f) ? $f : null;
}

/* =========================================================
 * Survey
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

function survey_get(array $surveys, string $id): ?array
{
    $i = survey_index($surveys, $id);
    return $i >= 0 && is_array($surveys[$i]) ? $surveys[$i] : null;
}

function refresh_status(array &$data): void
{
    $changed = false;

    foreach ($data['surveys'] as &$s) {
        if (
            ($s['status'] ?? '') === STATUS_PUBLISHED &&
            !empty($s['endAt'])
        ) {
            $t = strtotime((string)$s['endAt']);

            if ($t !== false && $t < time()) {
                $s['status'] = STATUS_ENDED;
                $s['updatedAt'] = now();
                $changed = true;
            }
        }
    }

    unset($s);

    if ($changed) {
        save_data($data);
    }
}

function normalize_survey(array $s): array
{
    $s['id'] = (string)($s['id'] ?? uid('survey'));
    $s['title'] = trim((string)($s['title'] ?? ''));
    $s['description'] = (string)($s['description'] ?? '');
    $s['startAt'] = (string)($s['startAt'] ?? '');
    $s['endAt'] = (string)($s['endAt'] ?? '');
    $s['status'] = in_array(
        $s['status'] ?? STATUS_DRAFT,
        [STATUS_DRAFT,STATUS_PUBLISHED,STATUS_STOPPED,STATUS_ENDED],
        true
    ) ? $s['status'] : STATUS_DRAFT;

    $s['numbering'] =
        ($s['numbering'] ?? 'global') === 'group'
        ? 'group'
        : 'global';

    $groups = [];

    foreach (($s['groups'] ?? []) as $g) {
        if (!is_array($g)) {
            continue;
        }

        $g['id'] = (string)($g['id'] ?? uid('group'));
        $g['title'] = trim((string)($g['title'] ?? 'グループ'));
        $g['questions'] = is_array($g['questions'] ?? null)
            ? $g['questions']
            : [];

        $qs = [];

        foreach ($g['questions'] as $q) {
            if (!is_array($q)) {
                continue;
            }

            $q['id'] = (string)($q['id'] ?? uid('question'));
            $q['text'] = trim((string)($q['text'] ?? ''));
            $q['type'] = in_array(
                $q['type'] ?? Q_TEXT,
                [Q_SINGLE,Q_MULTIPLE,Q_TEXT],
                true
            ) ? $q['type'] : Q_TEXT;

            $q['required'] = !empty($q['required']);
            $q['options'] = is_array($q['options'] ?? null)
                ? $q['options']
                : [];

            $opts = [];

            foreach ($q['options'] as $o) {
                if (!is_array($o)) {
                    continue;
                }

                $opts[] = [
                    'id' => (string)($o['id'] ?? uid('option')),
                    'label' => trim((string)($o['label'] ?? '')),
                    'nextQuestionId' =>
                        (string)($o['nextQuestionId'] ?? '')
                ];
            }

            $q['options'] = $opts;
            $qs[] = $q;
        }

        $g['questions'] = $qs;
        $groups[] = $g;
    }

    $s['groups'] = $groups;

    recalc_numbers($s);

    return $s;
}

function recalc_numbers(array &$survey): void
{
    $global = 1;
    $gn = 1;

    foreach ($survey['groups'] as &$g) {
        $qn = 1;

        foreach ($g['questions'] as &$q) {
            $q['number'] =
                ($survey['numbering'] ?? 'global') === 'group'
                ? 'Q' . $gn . '-' . $qn
                : 'Q' . $global;

            $global++;
            $qn++;
        }

        unset($q);
        $gn++;
    }

    unset($g);
}

function all_questions(array $survey): array
{
    $out = [];

    foreach ($survey['groups'] ?? [] as $g) {
        foreach ($g['questions'] ?? [] as $q) {
            $out[] = $q;
        }
    }

    return $out;
}

function question_ids(array $survey): array
{
    return array_map(
        static fn(array $q): string => (string)$q['id'],
        all_questions($survey)
    );
}

function status_label(string $s): string
{
    return match ($s) {
        STATUS_PUBLISHED => '公開中',
        STATUS_STOPPED => '停止',
        STATUS_ENDED => '終了',
        default => '下書き'
    };
}

function status_class(string $s): string
{
    return match ($s) {
        STATUS_PUBLISHED => 'success',
        STATUS_STOPPED => 'warning',
        STATUS_ENDED => 'danger',
        default => 'gray'
    };
}

function validate_survey(array $s): array
{
    $e = [];

    if ($s['title'] === '') {
        $e[] = 'アンケートタイトルを入力してください。';
    } elseif (mb_strlen($s['title']) > MAX_TITLE) {
        $e[] = 'アンケートタイトルが長すぎます。';
    }

    if (mb_strlen($s['description']) > MAX_DESC) {
        $e[] = 'アンケート説明が長すぎます。';
    }

    if ($s['startAt'] !== '' && strtotime($s['startAt']) === false) {
        $e[] = '開始日時が不正です。';
    }

    if ($s['endAt'] !== '' && strtotime($s['endAt']) === false) {
        $e[] = '終了日時が不正です。';
    }

    if (
        $s['startAt'] !== '' &&
        $s['endAt'] !== '' &&
        strtotime($s['startAt']) !== false &&
        strtotime($s['endAt']) !== false &&
        strtotime($s['endAt']) <= strtotime($s['startAt'])
    ) {
        $e[] = '終了日時は開始日時より後にしてください。';
    }

    foreach ($s['groups'] as $g) {
        if (trim((string)($g['title'] ?? '')) === '') {
            $e[] = 'グループ名を入力してください。';
        }

        foreach ($g['questions'] as $q) {
            if (trim((string)($q['text'] ?? '')) === '') {
                $e[] = '質問文を入力してください。';
            }

            if (mb_strlen((string)$q['text']) > MAX_Q) {
                $e[] = '質問文が長すぎます。';
            }

            if (in_array($q['type'], [Q_SINGLE,Q_MULTIPLE], true)) {
                if (!$q['options']) {
                    $e[] = '選択式質問には選択肢を1つ以上設定してください。';
                }

                foreach ($q['options'] as $o) {
                    if (
                        trim((string)($o['label'] ?? '')) === '' ||
                        mb_strlen((string)$o['label']) > MAX_OPT
                    ) {
                        $e[] = '選択肢を正しく入力してください。';
                    }
                }
            }
        }
    }

    return array_values(array_unique($e));
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalize_kintone_subdomain(string $v): string
{
    $v = trim($v);
    $v = preg_replace('#^https?://#i', '', $v) ?? $v;
    $v = preg_replace('#/.*$#', '', $v) ?? $v;

    $suffix = '.cybozu.com';

    if (str_ends_with(strtolower($v), $suffix)) {
        $v = substr($v, 0, -strlen($suffix));
    }

    return trim($v);
}

function validate_kintone(array $c, bool $password = true): array
{
    $e = [];

    $sub = normalize_kintone_subdomain((string)($c['subdomain'] ?? ''));

    if (
        $sub === '' ||
        !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $sub)
    ) {
        $e[] = 'kintoneサブドメインが不正です。';
    }

    $app = trim((string)($c['app_id'] ?? ''));

    if (!ctype_digit($app) || (int)$app < 1) {
        $e[] = '顧客管理アプリIDが不正です。';
    }

    if (trim((string)($c['username'] ?? '')) === '') {
        $e[] = 'ログイン名を入力してください。';
    }

    if (
        $password &&
        trim((string)($c['password'] ?? '')) === ''
    ) {
        $e[] = 'パスワードを入力してください。';
    }

    $proxy = trim((string)($c['proxy'] ?? ''));

    if (
        $proxy !== '' &&
        !preg_match(
            '/^[A-Za-z0-9._-]+:\d{1,5}$/',
            $proxy
        )
    ) {
        $e[] = 'Proxyはhost:port形式で入力してください。';
    }

    return $e;
}

function external_error(Throwable $e): string
{
    $m = trim($e->getMessage());

    foreach ([
        'X-Cybozu-Authorization',
        'Authorization:',
        'password',
        'SMTP_PASSWORD'
    ] as $secret) {
        $m = str_ireplace($secret, '[機密情報]', $m);
    }

    return $m !== '' ? $m : '外部サービスとの通信に失敗しました。';
}

function http_status(array $headers): int
{
    $status = 0;

    foreach ($headers as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', $line, $m)) {
            $status = (int)$m[1];
        }
    }

    return $status;
}

function kintone_request(
    array $c,
    string $method,
    string $path,
    ?array $body = null
): array {
    $errors = validate_kintone($c, true);

    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }

    $sub = normalize_kintone_subdomain((string)$c['subdomain']);

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $sub)) {
        throw new RuntimeException('kintoneサブドメインが不正です。');
    }

    $app = trim((string)$c['app_id']);

    if (!ctype_digit($app) || (int)$app < 1) {
        throw new RuntimeException('顧客管理アプリIDが不正です。');
    }

    $url = 'https://' . $sub . '.cybozu.com' . $path;

    $auth = base64_encode(
        (string)$c['username'] . ':' . (string)$c['password']
    );

    $headers = [
        'Host: ' . $sub . '.cybozu.com',
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
        'Connection: close'
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($content === false) {
            throw new RuntimeException('kintoneリクエストを生成できません。');
        }

        $headers[] = 'Content-Type: application/json';
    }

    $verify = !empty($c['verify_ssl']);

    $opts = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => READ_TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0,
            'protocol_version' => 1.1
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $sub . '.cybozu.com'
        ]
    ];

    $proxy = trim((string)($c['proxy'] ?? ''));

    if ($proxy !== '') {
        [$ph, $pp] = explode(':', $proxy, 2);

        $opts['http']['proxy'] =
            'tcp://' . $ph . ':' . (int)$pp;
        $opts['http']['request_fulluri'] = true;
    }

    $ctx = stream_context_create($opts);

    $http_response_header = [];

    $response = @file_get_contents(
        $url,
        false,
        $ctx
    );

    $headers = $http_response_header ?? [];
    $status = http_status($headers);

    if ($response === false) {
        $last = error_get_last();
        throw new RuntimeException(
            'kintone通信エラー: ' .
            ($last['message'] ?? 'レスポンスを取得できませんでした。')
        );
    }

    if ($status === 0) {
        throw new RuntimeException(
            'kintone通信結果不明: HTTPレスポンスを取得できませんでした。'
        );
    }

    if ($status === 302 || $status === 303) {
        throw new RuntimeException(
            'kintoneからリダイレクト応答 HTTP ' . $status .
            ' が返されました。APIの接続先・設定を確認してください。'
        );
    }

    $json = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        $code = is_array($json) ? (string)($json['code'] ?? '') : '';
        $msg  = is_array($json) ? (string)($json['message'] ?? '') : '';

        $detail = 'kintone APIエラー HTTP ' . $status;

        if ($code !== '') {
            $detail .= ' [' . $code . ']';
        }

        if ($msg !== '') {
            $detail .= ' ' . $msg;
        }

        throw new RuntimeException($detail);
    }

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintoneから正常なJSONレスポンスを取得できませんでした。'
        );
    }

    return [
        'status' => $status,
        'body' => $json
    ];
}

function kintone_test(array $c): array
{
    return kintone_request(
        $c,
        'GET',
        '/k/v1/app.json?id=' . rawurlencode((string)$c['app_id'])
    );
}

function kintone_fields(array $c): array
{
    return kintone_request(
        $c,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode((string)$c['app_id'])
    );
}

function kintone_records(array $c): array
{
    return kintone_request(
        $c,
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode((string)$c['app_id']) .
        '&totalCount=true'
    );
}

function kintone_field_list(array $r): array
{
    $p = $r['body']['properties'] ?? [];

    if (!is_array($p)) {
        return [];
    }

    $out = [];

    foreach ($p as $code => $f) {
        if (!is_array($f)) {
            continue;
        }

        $out[] = [
            'code' => (string)$code,
            'label' => (string)($f['label'] ?? $code),
            'type' => (string)($f['type'] ?? '')
        ];
    }

    usort(
        $out,
        static fn(array $a, array $b): int =>
            strnatcasecmp($a['code'], $b['code'])
    );

    return $out;
}

function krecord(array $record, string $code): string
{
    if (
        $code === '' ||
        !isset($record[$code]) ||
        !is_array($record[$code])
    ) {
        return '';
    }

    $v = $record[$code]['value'] ?? '';

    if (!is_array($v)) {
        return trim((string)$v);
    }

    $out = [];

    foreach ($v as $x) {
        if (!is_array($x)) {
            $out[] = (string)$x;
        } elseif (isset($x['name'])) {
            $out[] = (string)$x['name'];
        } elseif (isset($x['value'])) {
            $out[] = (string)$x['value'];
        }
    }

    return implode(' ', array_filter(
        $out,
        static fn(string $v): bool => trim($v) !== ''
    ));
}

function sync_customers(array &$data, array $settings): int
{
    $k = $settings['kintone'];

    $r = kintone_records($k);
    $records = $r['body']['records'] ?? [];

    if (!is_array($records)) {
        throw new RuntimeException('kintone顧客レコードが不正です。');
    }

    $m = $k['mapping'];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $name = krecord($record, (string)($m['name'] ?? ''));
        $email = krecord($record, (string)($m['email'] ?? ''));

        if ($name === '' && $email === '') {
            continue;
        }

        $addresses = [];

        foreach ((array)($m['address'] ?? []) as $code) {
            $v = krecord($record, (string)$code);
            if ($v !== '') {
                $addresses[] = $v;
            }
        }

        $customers[] = [
            'id' => krecord($record, '$id') ?: uid('customer'),
            'organization' => krecord(
                $record,
                (string)($m['organization'] ?? '')
            ),
            'name' => $name,
            'email' => $email,
            'department' => krecord(
                $record,
                (string)($m['department'] ?? '')
            ),
            'phone' => krecord(
                $record,
                (string)($m['phone'] ?? '')
            ),
            'address' => implode(' ', $addresses)
        ];
    }

    $data['customers'] = $customers;

    return count($customers);
}

/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail(array $c): array
{
    $e = [];

    $host = trim((string)($c['host'] ?? ''));

    if ($host === '') {
        $e[] = 'SMTPサーバを入力してください。';
    }

    if (
        preg_match('#^[a-z]+://#i', $host)
    ) {
        $e[] = 'SMTPサーバにはホスト名だけを入力してください。';
    }

    $port = (int)($c['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        $e[] = 'SMTPポートが不正です。';
    }

    if (!in_array(
        (string)($c['encryption'] ?? ''),
        ['ssl','tls','none'],
        true
    )) {
        $e[] = '暗号化方式が不正です。';
    }

    if (!filter_var(
        (string)($c['from_email'] ?? ''),
        FILTER_VALIDATE_EMAIL
    )) {
        $e[] = '送信元メールアドレスが不正です。';
    }

    $reply = trim((string)($c['reply_to'] ?? ''));

    if (
        $reply !== '' &&
        !filter_var($reply, FILTER_VALIDATE_EMAIL)
    ) {
        $e[] = '返信先メールアドレスが不正です。';
    }

    if (!empty($c['auth'])) {
        if (trim((string)($c['username'] ?? '')) === '') {
            $e[] = 'SMTPユーザー名を入力してください。';
        }

        if (trim((string)($c['password'] ?? '')) === '') {
            $e[] = 'SMTPパスワードを入力してください。';
        }
    }

    return $e;
}

function smtp_read($socket, array $codes): string
{
    $response = '';

    while (($line = fgets($socket)) !== false) {
        $response .= $line;

        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            if ($m[2] === ' ') {
                $code = (int)$m[1];

                if (!in_array($code, $codes, true)) {
                    throw new RuntimeException(
                        'SMTPエラー ' . $code . ': ' .
                        trim($response)
                    );
                }

                return $response;
            }
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTPから応答がありません。');
    }

    throw new RuntimeException(
        'SMTP応答を最後まで取得できませんでした。'
    );
}

function smtp_cmd($socket, string $cmd, array $codes): string
{
    if (@fwrite($socket, $cmd . "\r\n") === false) {
        throw new RuntimeException('SMTPへコマンドを送信できません。');
    }

    return smtp_read($socket, $codes);
}

function smtp_open(array $c)
{
    $errors = validate_mail($c);

    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }

    /*
     * 重要:
     * encryption と host を絶対に混ぜない。
     *
     * ssl:
     *   ssl://host:port
     *
     * tls:
     *   tcp://host:port -> STARTTLS
     *
     * none:
     *   tcp://host:port
     */
    $host = trim((string)$c['host']);

    /*
     * 念のため入力値から scheme を除去する。
     * ssl://smtp.example.com のような入力を
     * ssl://ssl://smtp... にしない。
     */
    $host = preg_replace(
        '#^[a-z]+://#i',
        '',
        $host
    ) ?? $host;

    $host = trim($host, " \t\n\r\0\x0B/");

    if ($host === '' || str_contains($host, '/')) {
        throw new RuntimeException('SMTPサーバ名が不正です。');
    }

    $port = (int)$c['port'];
    $enc = (string)$c['encryption'];

    if ($enc === 'ssl') {
        $target = 'ssl://' . $host . ':' . $port;
    } else {
        $target = 'tcp://' . $host . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        throw new RuntimeException(
            'SMTP接続に失敗しました: ' .
            ($errstr !== '' ? $errstr : '接続できませんでした。')
        );
    }

    stream_set_timeout($socket, READ_TIMEOUT);

    try {
        smtp_read($socket, [220]);

        $ehlo = smtp_cmd(
            $socket,
            'EHLO localhost',
            [250]
        );

        if ($enc === 'tls') {
            if (
                stripos($ehlo, 'STARTTLS') === false
            ) {
                throw new RuntimeException(
                    'SMTPサーバがSTARTTLSに対応していません。'
                );
            }

            smtp_cmd($socket, 'STARTTLS', [220]);

            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTP STARTTLS暗号化に失敗しました。'
                );
            }

            smtp_cmd($socket, 'EHLO localhost', [250]);
        }

        if (!empty($c['auth'])) {
            smtp_cmd($socket, 'AUTH LOGIN', [334]);

            smtp_cmd(
                $socket,
                base64_encode((string)$c['username']),
                [334]
            );

            smtp_cmd(
                $socket,
                base64_encode((string)$c['password']),
                [235]
            );
        }

        return $socket;
    } catch (Throwable $e) {
        @fclose($socket);
        throw $e;
    }
}

function smtp_send(
    array $c,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('送信先メールアドレスが不正です。');
    }

    $socket = smtp_open($c);

    try {
        smtp_cmd($socket, 'MAIL FROM:<' . $c['from_email'] . '>', [250]);
        smtp_cmd($socket, 'RCPT TO:<' . $to . '>', [250,251]);
        smtp_cmd($socket, 'DATA', [354]);

        $fromName = trim((string)$c['from_name']);
        $from = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' .
              $c['from_email'] . '>'
            : '<' . $c['from_email'] . '>';

        $headers = [
            'From: ' . $from,
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ];

        if (
            !empty($c['reply_to']) &&
            filter_var($c['reply_to'], FILTER_VALIDATE_EMAIL)
        ) {
            $headers[] = 'Reply-To: ' . $c['reply_to'];
        }

        $payload = implode("\r\n", $headers) .
            "\r\n\r\n" .
            str_replace(
                ["\r\n.", "\n."],
                ["\r\n..", "\n.."],
                str_replace(["\r\n","\r","\n"], "\r\n", $body)
            ) .
            "\r\n.";

        smtp_cmd($socket, $payload, [250]);
        smtp_cmd($socket, 'QUIT', [221]);
    } finally {
        @fclose($socket);
    }
}

/* =========================================================
 * 回答分岐
 * ========================================================= */

function answer_value(array $answers, string $id): array|string
{
    $v = $answers[$id] ?? '';

    if (is_array($v)) {
        return array_map('strval', $v);
    }

    return is_scalar($v) ? (string)$v : '';
}

function visible_questions(array $survey, array $answers): array
{
    $qs = all_questions($survey);

    $visible = [];
    $skipUntil = null;

    foreach ($qs as $q) {
        if ($skipUntil !== null) {
            if ($q['id'] === $skipUntil) {
                $skipUntil = null;
            } else {
                continue;
            }
        }

        $visible[] = $q;

        if ($q['type'] !== Q_SINGLE) {
            continue;
        }

        $v = $answers[$q['id']] ?? '';

        if (!is_scalar($v)) {
            continue;
        }

        foreach ($q['options'] as $o) {
            if ((string)$o['id'] === (string)$v) {
                $next = (string)($o['nextQuestionId'] ?? '');

                if ($next !== '') {
                    $found = false;

                    foreach ($qs as $x) {
                        if ($x['id'] === $next) {
                            $found = true;
                            break;
                        }
                    }

                    if ($found) {
                        $skipUntil = $next;
                    }
                }

                break;
            }
        }
    }

    return $visible;
}

function validate_answers(array $survey, array $answers): array
{
    $errors = [];

    foreach (visible_questions($survey, $answers) as $q) {
        $v = $answers[$q['id']] ?? '';

        if ($q['required']) {
            $empty = is_array($v)
                ? count($v) === 0
                : trim((string)$v) === '';

            if ($empty) {
                $errors[] =
                    $q['number'] . '「' . $q['text'] . '」は必須です。';
                continue;
            }
        }

        if ($q['type'] === Q_SINGLE && $v !== '') {
            $ids = array_column($q['options'], 'id');

            if (!in_array((string)$v, $ids, true)) {
                $errors[] = $q['number'] . 'の選択値が不正です。';
            }
        }

        if ($q['type'] === Q_MULTIPLE) {
            $vals = is_array($v) ? $v : [];

            $ids = array_column($q['options'], 'id');

            foreach ($vals as $x) {
                if (!in_array((string)$x, $ids, true)) {
                    $errors[] = $q['number'] . 'の選択値が不正です。';
                }
            }
        }
    }

    return $errors;
}

/* =========================================================
 * POST処理
 * ========================================================= */

function save_survey_action(array &$data): string
{
    $raw = [
        'id' => post_s('survey_id'),
        'title' => post_s('title'),
        'description' => (string)($_POST['description'] ?? ''),
        'startAt' => post_s('startAt'),
        'endAt' => post_s('endAt'),
        'numbering' => post_s('numbering', 'global'),
        'status' => post_s('status', STATUS_DRAFT),
        'groups' => post_a('groups')
    ];

    $existingId = $raw['id'];

    $s = normalize_survey($raw);

    $errors = validate_survey($s);

    if ($errors) {
        flash('error', implode("\n", $errors));
        return $existingId !== ''
            ? app_url(['screen'=>'edit','id'=>$existingId])
            : app_url(['screen'=>'edit']);
    }

    $i = survey_index($data['surveys'], $existingId);

    if ($i >= 0) {
        $oldStatus = $data['surveys'][$i]['status'];

        if ($oldStatus === STATUS_ENDED) {
            $s['status'] = STATUS_ENDED;
        } else {
            $s['status'] = $oldStatus;
        }

        $s['createdAt'] =
            $data['surveys'][$i]['createdAt'] ?? now();
        $s['updatedAt'] = now();

        $data['surveys'][$i] = $s;
    } else {
        $s['id'] = uid('survey');
        $s['status'] = STATUS_DRAFT;
        $s['createdAt'] = now();
        $s['updatedAt'] = now();

        $data['surveys'][] = $s;
    }

    save_data($data);

    flash('success', 'アンケートを保存しました。');

    return app_url(['screen'=>'list']);
}

function change_status_action(array &$data): string
{
    $id = post_s('survey_id');
    $to = post_s('new_status');

    $i = survey_index($data['surveys'], $id);

    if ($i < 0) {
        flash('error', 'アンケートが見つかりません。');
        return app_url(['screen'=>'list']);
    }

    $current = $data['surveys'][$i]['status'];

    $allowed = [
        STATUS_DRAFT => [STATUS_PUBLISHED],
        STATUS_PUBLISHED => [STATUS_STOPPED],
        STATUS_STOPPED => [STATUS_PUBLISHED]
    ];

    if (
        $current === STATUS_ENDED ||
        !in_array($to, $allowed[$current] ?? [], true)
    ) {
        flash('error', '指定された状態変更はできません。');
        return app_url(['screen'=>'list']);
    }

    $data['surveys'][$i]['status'] = $to;
    $data['surveys'][$i]['updatedAt'] = now();

    save_data($data);

    flash('success', '状態を変更しました。');

    return app_url(['screen'=>'list']);
}

function duplicate_survey_action(array &$data): string
{
    $id = post_s('survey_id');
    $s = survey_get($data['surveys'], $id);

    if (!$s) {
        flash('error', 'アンケートが見つかりません。');
        return app_url(['screen'=>'list']);
    }

    $s['id'] = uid('survey');
    $s['title'] = $s['title'] . '（コピー）';
    $s['status'] = STATUS_DRAFT;
    $s['createdAt'] = now();
    $s['updatedAt'] = now();

    foreach ($s['groups'] as &$g) {
        $g['id'] = uid('group');

        foreach ($g['questions'] as &$q) {
            $q['id'] = uid('question');

            foreach ($q['options'] as &$o) {
                $o['id'] = uid('option');
            }
        }

        unset($q);
    }

    unset($g);

    recalc_numbers($s);
    $data['surveys'][] = $s;

    save_data($data);

    flash('success', 'アンケートを複製しました。');

    return app_url(['screen'=>'list']);
}

function delete_survey_action(array &$data): string
{
    $id = post_s('survey_id');
    $i = survey_index($data['surveys'], $id);

    if ($i < 0) {
        flash('error', 'アンケートが見つかりません。');
        return app_url(['screen'=>'list']);
    }

    array_splice($data['surveys'], $i, 1);

    $data['answers'] = array_values(array_filter(
        $data['answers'],
        static fn(array $a): bool =>
            (string)($a['survey_id'] ?? '') !== $id
    ));

    save_data($data);

    flash('success', 'アンケートを削除しました。');

    return app_url(['screen'=>'list']);
}

function save_kintone_action(array &$settings): string
{
    $old = $settings['kintone'];

    $c = [
        'subdomain' => post_s('subdomain'),
        'app_id' => post_s('app_id'),
        'username' => post_s('username'),
        'password' => post_s('password'),
        'proxy' => post_s('proxy'),
        'verify_ssl' => post_bool('verify_ssl'),
        'mapping' => $old['mapping'] ?? [],
        'fields' => $old['fields'] ?? [],
        'last_test' => $old['last_test'] ?? null,
        'last_sync' => $old['last_sync'] ?? null
    ];

    if ($c['password'] === '') {
        $c['password'] = $old['password'] ?? '';
    }

    $errors = validate_kintone($c, true);

    if ($errors) {
        flash('error', implode("\n", $errors));
        return app_url(['screen'=>'kintone']);
    }

    $settings['kintone'] = $c;
    save_settings($settings);

    flash('success', 'kintone設定を保存しました。');

    return app_url(['screen'=>'kintone']);
}

function save_mail_action(array &$settings): string
{
    $old = $settings['mail'];

    $c = [
        'host' => post_s('host'),
        'port' => (int)post_s('port'),
        'encryption' => post_s('encryption'),
        'auth' => post_bool('auth'),
        'username' => post_s('username'),
        'password' => post_s('password'),
        'from_email' => post_s('from_email'),
        'from_name' => post_s('from_name'),
        'reply_to' => post_s('reply_to'),
        'last_test' => $old['last_test'] ?? null
    ];

    if ($c['password'] === '') {
        $c['password'] = $old['password'] ?? '';
    }

    $errors = validate_mail($c);

    if ($errors) {
        flash('error', implode("\n", $errors));
        return app_url(['screen'=>'mail']);
    }

    $settings['mail'] = $c;
    save_settings($settings);

    flash('success', 'メール設定を保存しました。');

    return app_url(['screen'=>'mail']);
}

function handle_post(array &$data, array &$settings): ?string
{
    $action = post_s('action');

    if ($action === '') {
        return null;
    }

    try {
        switch ($action) {
            case 'save_survey':
                return save_survey_action($data);

            case 'change_status':
                return change_status_action($data);

            case 'duplicate_survey':
                return duplicate_survey_action($data);

            case 'delete_survey':
                return delete_survey_action($data);

            case 'save_kintone':
                return save_kintone_action($settings);

            case 'test_kintone':
                $r = kintone_test($settings['kintone']);
                $settings['kintone']['last_test'] = now();
                save_settings($settings);

                flash(
                    'success',
                    'kintone接続テスト成功。HTTP ' . $r['status']
                );

                return app_url(['screen'=>'kintone']);

            case 'load_kintone_fields':
                $r = kintone_fields($settings['kintone']);
                $fields = kintone_field_list($r);

                if (!$fields) {
                    throw new RuntimeException(
                        'kintoneから項目を取得できませんでした。'
                    );
                }

                $settings['kintone']['fields'] = $fields;
                save_settings($settings);

                flash(
                    'success',
                    count($fields) . '件の項目を取得しました。'
                );

                return app_url(['screen'=>'kintone']);

            case 'save_kintone_mapping':
                $fields = $settings['kintone']['fields'] ?? [];
                $valid = [];

                foreach ($fields as $f) {
                    if (is_array($f)) {
                        $valid[] = (string)$f['code'];
                    }
                }

                $address = [];

                foreach ((array)($_POST['mapping_address'] ?? []) as $x) {
                    $x = (string)$x;
                    if (in_array($x, $valid, true)) {
                        $address[] = $x;
                    }
                }

                $settings['kintone']['mapping'] = [
                    'organization' => in_array(
                        post_s('mapping_organization'),
                        $valid,
                        true
                    ) ? post_s('mapping_organization') : '',
                    'name' => in_array(
                        post_s('mapping_name'),
                        $valid,
                        true
                    ) ? post_s('mapping_name') : '',
                    'email' => in_array(
                        post_s('mapping_email'),
                        $valid,
                        true
                    ) ? post_s('mapping_email') : '',
                    'department' => in_array(
                        post_s('mapping_department'),
                        $valid,
                        true
                    ) ? post_s('mapping_department') : '',
                    'phone' => in_array(
                        post_s('mapping_phone'),
                        $valid,
                        true
                    ) ? post_s('mapping_phone') : '',
                    'address' => array_values(array_unique($address))
                ];

                save_settings($settings);

                flash('success', '項目マッピングを保存しました。');

                return app_url(['screen'=>'kintone']);

            case 'sync_kintone':
                $n = sync_customers($data, $settings);

                $settings['kintone']['last_sync'] = now();

                save_data($data);
                save_settings($settings);

                flash(
                    'success',
                    '顧客情報を同期しました。' . $n . '件'
                );

                return app_url(['screen'=>'kintone']);

            case 'save_mail':
                return save_mail_action($settings);

            case 'test_mail':
                $socket = smtp_open($settings['mail']);

                try {
                    smtp_cmd($socket, 'QUIT', [221]);
                } finally {
                    @fclose($socket);
                }

                $settings['mail']['last_test'] = now();
                save_settings($settings);

                flash('success', 'SMTP接続テスト成功。');

                return app_url(['screen'=>'mail']);

            case 'send_test_mail':
                $to = post_s('test_email');

                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException(
                        'テスト送信先メールアドレスが不正です。'
                    );
                }

                smtp_send(
                    $settings['mail'],
                    $to,
                    'アンケートアプリ テストメール',
                    'SMTP設定のテストメールです。'
                );

                flash('success', 'テストメールを送信しました。');

                return app_url(['screen'=>'mail']);

            case 'send_mail':
                return send_mail_action($data, $settings);

            case 'answer_confirm':
                return answer_confirm_action($data);

            case 'answer_back':
                $id = post_s('survey_id');
                return app_url([
                    'screen'=>'answer',
                    'id'=>$id
                ]);

            case 'submit_answer':
                return submit_answer_action($data);

            default:
                flash('error', '不明な操作です。');
                return app_url(['screen'=>'list']);
        }
    } catch (Throwable $e) {
        flash('error', external_error($e));

        $screen = post_s('return_screen', 'list');
        $id = post_s('survey_id');

        $allowed = [
            'list','edit','preview','send',
            'analytics','kintone','mail',
            'answer','confirm','complete'
        ];

        if (!in_array($screen, $allowed, true)) {
            $screen = 'list';
        }

        $p = ['screen'=>$screen];

        if (
            $id !== '' &&
            in_array($screen, [
                'edit','preview','send','analytics',
                'answer','confirm','complete'
            ], true)
        ) {
            $p['id'] = $id;
        }

        return app_url($p);
    }
}

function answer_confirm_action(array &$data): string
{
    $id = post_s('survey_id');
    $survey = survey_get($data['surveys'], $id);

    if (!$survey) {
        flash('error', 'アンケートが見つかりません。');
        return app_url([
            'screen'=>'answer',
            'id'=>$id
        ]);
    }

    if ($survey['status'] !== STATUS_PUBLISHED) {
        flash('error', '現在回答できる状態ではありません。');
        return app_url([
            'screen'=>'answer',
            'id'=>$id
        ]);
    }

    $answers = post_a('answers');

    $errors = validate_answers($survey, $answers);

    $_SESSION['answer_draft'] = $answers;

    if ($errors) {
        flash('error', implode("\n", $errors));

        return app_url([
            'screen'=>'answer',
            'id'=>$id
        ]);
    }

    return app_url([
        'screen'=>'confirm',
        'id'=>$id
    ]);
}

function submit_answer_action(array &$data): string
{
    $id = post_s('survey_id');
    $survey = survey_get($data['surveys'], $id);

    if (!$survey) {
        flash('error', 'アンケートが見つかりません。');
        return app_url([
            'screen'=>'answer',
            'id'=>$id
        ]);
    }

    if ($survey['status'] !== STATUS_PUBLISHED) {
        flash('error', '現在回答できる状態ではありません。');
        return app_url([
            'screen'=>'answer',
            'id'=>$id
        ]);
    }

    $answers = $_SESSION['answer_draft'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $errors = validate_answers($survey, $answers);

    if ($errors) {
        flash('error', implode("\n", $errors));

        return app_url([
            'screen'=>'answer',
            'id'=>$id
        ]);
    }

    $data['answers'][] = [
        'id' => uid('answer'),
        'survey_id' => $id,
        'answers' => $answers,
        'createdAt' => now()
    ];

    save_data($data);

    unset($_SESSION['answer_draft']);

    /*
     * 回答者フロー終了点。
     * 管理者一覧には戻さない。
     */
    return app_url([
        'screen'=>'complete',
        'id'=>$id
    ]);
}

function send_mail_action(array &$data, array $settings): string
{
    $id = post_s('survey_id');
    $survey = survey_get($data['surveys'], $id);

    if (!$survey) {
        flash('error', '対象アンケートが見つかりません。');
        return app_url(['screen'=>'list']);
    }

    $selected = [];

    foreach ((array)($_POST['customers'] ?? []) as $cid) {
        $cid = (string)$cid;

        if ($cid !== '') {
            $selected[] = $cid;
        }
    }

    $selected = array_values(array_unique($selected));

    if (!$selected) {
        flash('error', '顧客を選択してください。');
        return app_url([
            'screen'=>'send',
            'id'=>$id
        ]);
    }

    $subject = post_s('subject');
    $body = (string)($_POST['body'] ?? '');

    if ($subject === '' || trim($body) === '') {
        flash('error', 'メール件名と本文を入力してください。');
        return app_url([
            'screen'=>'send',
            'id'=>$id
        ]);
    }

    $map = [];

    foreach ($data['customers'] as $c) {
        if (is_array($c)) {
            $map[(string)($c['id'] ?? '')] = $c;
        }
    }

    $sent = 0;
    $failed = 0;
    $results = [];

    foreach ($selected as $cid) {
        $c = $map[$cid] ?? null;

        if (!is_array($c)) {
            $failed++;
            $results[] = [
                'customer_id'=>$cid,
                'email'=>'',
                'status'=>'failed',
                'message'=>'顧客が見つかりません。'
            ];
            continue;
        }

        $email = trim((string)($c['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $failed++;
            $results[] = [
                'customer_id'=>$cid,
                'email'=>$email,
                'status'=>'failed',
                'message'=>'メールアドレスが不正です。'
            ];
            continue;
        }

        $personalBody = str_replace(
            ['{顧客名}','{アンケートURL}'],
            [
                (string)($c['name'] ?? ''),
                public_url($id)
            ],
            $body
        );

        $personalSubject = str_replace(
            ['{顧客名}','{アンケートURL}'],
            [
                (string)($c['name'] ?? ''),
                public_url($id)
            ],
            $subject
        );

        try {
            smtp_send(
                $settings['mail'],
                $email,
                $personalSubject,
                $personalBody
            );

            $sent++;

            $results[] = [
                'customer_id'=>$cid,
                'email'=>$email,
                'status'=>'sent',
                'message'=>'送信成功'
            ];

            $data['send_history'][] = [
                'id'=>uid('send'),
                'survey_id'=>$id,
                'customer_id'=>$cid,
                'email'=>$email,
                'subject'=>$personalSubject,
                'status'=>'sent',
                'sentAt'=>now()
            ];
        } catch (Throwable $e) {
            $failed++;

            $results[] = [
                'customer_id'=>$cid,
                'email'=>$email,
                'status'=>'failed',
                'message'=>external_error($e)
            ];

            $data['send_history'][] = [
                'id'=>uid('send'),
                'survey_id'=>$id,
                'customer_id'=>$cid,
                'email'=>$email,
                'subject'=>$personalSubject,
                'status'=>'failed',
                'sentAt'=>now(),
                'message'=>external_error($e)
            ];
        }
    }

    save_data($data);

    $_SESSION['send_results'] = $results;

    flash(
        $failed ? 'warning' : 'success',
        '送信完了：成功 ' . $sent . '件 / 失敗 ' . $failed . '件'
    );

    return app_url([
        'screen'=>'send',
        'id'=>$id
    ]);
}

/* =========================================================
 * 共通HTML
 * ========================================================= */

function css(): string
{
    return <<<'CSS'
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
 --shadow:0 4px 18px rgba(15,23,42,.08);
}
*{box-sizing:border-box}
body{
 margin:0;
 background:#f8fafc;
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
a{color:var(--primary);text-decoration:none}
button,input,select,textarea{font:inherit}
button{
 border:1px solid #cbd5e1;
 background:#fff;
 color:#1e293b;
 padding:9px 14px;
 border-radius:8px;
 cursor:pointer;
}
button:hover{background:#f8fafc}
button.primary{
 background:var(--primary);
 border-color:var(--primary);
 color:#fff;
}
button.primary:hover{background:var(--primary-dark)}
button.danger{
 background:#fff;
 border-color:#fecaca;
 color:#b91c1c;
}
button:disabled{opacity:.5;cursor:not-allowed}
.header{
 background:#0f172a;
 color:#fff;
 padding:14px 24px;
}
.header-inner{
 max-width:1400px;
 margin:auto;
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:20px;
}
.logo{font-size:19px;font-weight:700}
.nav{display:flex;gap:8px;flex-wrap:wrap}
.nav a{
 color:#cbd5e1;
 padding:7px 10px;
 border-radius:7px;
}
.nav a:hover{background:#1e293b;color:#fff}
.container{
 max-width:1400px;
 margin:0 auto;
 padding:24px;
}
.page-title{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:12px;
 margin-bottom:20px;
}
.page-title h1{margin:0;font-size:25px}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 box-shadow:var(--shadow);
 padding:20px;
 margin-bottom:18px;
}
.notice{
 white-space:pre-line;
 padding:12px 14px;
 border-radius:8px;
 margin-bottom:18px;
}
.notice.success{background:#dcfce7;color:#166534}
.notice.error{background:#fee2e2;color:#991b1b}
.notice.warning{background:#fef3c7;color:#92400e}
.grid{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:16px;
}
.grid3{
 display:grid;
 grid-template-columns:repeat(3,minmax(0,1fr));
 gap:16px;
}
.field{margin-bottom:15px}
.field label{
 display:block;
 font-weight:600;
 margin-bottom:6px;
}
input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
select,
textarea{
 width:100%;
 border:1px solid #cbd5e1;
 border-radius:8px;
 padding:10px 11px;
 background:#fff;
 color:#1e293b;
}
textarea{min-height:100px;resize:vertical}
.table-wrap{overflow-x:auto}
table{
 width:100%;
 min-width:1000px;
 border-collapse:collapse;
}
th,td{
 padding:10px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:top;
}
th{background:#f8fafc;white-space:nowrap}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 align-items:center;
}
.badge{
 display:inline-block;
 padding:4px 9px;
 border-radius:999px;
 font-size:12px;
 font-weight:700;
 background:#e2e8f0;
 color:#475569;
}
.badge.success{background:#dcfce7;color:#166534}
.badge.warning{background:#fef3c7;color:#92400e}
.badge.danger{background:#fee2e2;color:#991b1b}
.group{
 border:1px solid var(--border);
 border-radius:10px;
 padding:16px;
 margin-bottom:16px;
 background:#fff;
}
.group.dragging,.question.dragging{opacity:.45}
.question{
 border:1px solid #e2e8f0;
 border-radius:8px;
 padding:14px;
 margin:12px 0;
 background:#f8fafc;
 cursor:grab;
}
.question-head{
 display:flex;
 gap:10px;
 align-items:center;
 margin-bottom:10px;
}
.question-number{font-weight:700;min-width:70px}
.drag-handle{color:#64748b}
.option{
 display:grid;
 grid-template-columns:minmax(0,1fr) 240px auto;
 gap:8px;
 margin:8px 0;
}
.answer-shell{
 max-width:760px;
 margin:30px auto;
 padding:0 16px;
}
.answer-card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:14px;
 padding:24px;
 box-shadow:var(--shadow);
}
.answer-question{
 margin:20px 0;
 padding:18px;
 border:1px solid var(--border);
 border-radius:10px;
}
.choice{
 display:flex;
 gap:10px;
 align-items:flex-start;
 padding:12px;
 border:1px solid var(--border);
 border-radius:8px;
 margin:8px 0;
 cursor:pointer;
 background:#fff;
}
.choice:hover{background:#f8fafc}
.small{font-size:12px;color:#64748b}
.muted{color:#64748b}
@media(max-width:800px){
 .container{padding:14px}
 .grid,.grid3{grid-template-columns:1fr}
 .header-inner{align-items:flex-start;flex-direction:column}
 .option{grid-template-columns:1fr}
 .page-title{align-items:flex-start;flex-direction:column}
 .answer-card{padding:16px}
 button{min-height:42px}
 input[type=text],
 input[type=email],
 input[type=password],
 input[type=number],
 input[type=datetime-local],
 select,
 textarea{font-size:16px}
}
CSS;
}

function admin_header(string $title): void
{
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?> - <?= h(APP_TITLE) ?></title>
    <style><?= css() ?></style>
    </head>
    <body>
    <header class="header">
      <div class="header-inner">
        <div class="logo"><?= h(APP_TITLE) ?></div>
        <nav class="nav">
          <a href="<?= h(app_url(['screen'=>'list'])) ?>">アンケート一覧</a>
          <a href="<?= h(app_url(['screen'=>'kintone'])) ?>">kintone連携</a>
          <a href="<?= h(app_url(['screen'=>'mail'])) ?>">メール設定</a>
        </nav>
      </div>
    </header>
    <main class="container">
    <?php
}

function admin_footer(): void
{
    ?>
    </main>
    </body>
    </html>
    <?php
}

function render_flash(): void
{
    $f = flash_get();

    if (!$f) {
        return;
    }

    ?>
    <div class="notice <?= h($f['type']) ?>">
      <?= h($f['message']) ?>
    </div>
    <?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(array $data): void
{
    $surveys = $data['surveys'];

    $q = get_s('q');
    $filter = get_s('filter','all');
    $sort = get_s('sort','updated_desc');

    $answersBySurvey = [];

    foreach ($data['answers'] as $a) {
        $sid = (string)($a['survey_id'] ?? '');
        $answersBySurvey[$sid] =
            ($answersBySurvey[$sid] ?? 0) + 1;
    }

    $surveys = array_values(array_filter(
        $surveys,
        static function(array $s) use ($q,$filter): bool {
            if (
                $q !== '' &&
                mb_stripos(
                    (string)($s['title'] ?? ''),
                    $q
                ) === false
            ) {
                return false;
            }

            if ($filter !== 'all') {
                $map = [
                    'published'=>STATUS_PUBLISHED,
                    'draft'=>STATUS_DRAFT,
                    'stopped'=>STATUS_STOPPED,
                    'ended'=>STATUS_ENDED
                ];

                if (($s['status'] ?? '') !== ($map[$filter] ?? '')) {
                    return false;
                }
            }

            return true;
        }
    ));

    usort(
        $surveys,
        static function(array $a,array $b) use($sort,$answersBySurvey): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp((string)$a['updatedAt'],(string)$b['updatedAt']),
                'answers_desc' =>
                    ($answersBySurvey[$b['id']] ?? 0) <=>
                    ($answersBySurvey[$a['id']] ?? 0),
                'answers_asc' =>
                    ($answersBySurvey[$a['id']] ?? 0) <=>
                    ($answersBySurvey[$b['id']] ?? 0),
                'start_desc' =>
                    strcmp((string)$b['startAt'],(string)$a['startAt']),
                'start_asc' =>
                    strcmp((string)$a['startAt'],(string)$b['startAt']),
                default =>
                    strcmp((string)$b['updatedAt'],(string)$a['updatedAt'])
            };
        }
    );

    admin_header('アンケート一覧');
    render_flash();
    ?>

    <div class="page-title">
      <h1>アンケート一覧</h1>
      <a href="<?= h(app_url(['screen'=>'edit'])) ?>">
        <button type="button" class="primary">＋ 新規作成</button>
      </a>
    </div>

    <div class="card">
      <form method="get">
        <input type="hidden" name="screen" value="list">
        <div class="grid3">
          <div class="field">
            <label>タイトル検索</label>
            <input
              type="text"
              name="q"
              value="<?= h($q) ?>"
              placeholder="タイトル部分一致">
          </div>

          <div class="field">
            <label>ステータス</label>
            <select name="filter">
              <?php
              $filters = [
                'all'=>'すべて',
                'published'=>'公開中',
                'draft'=>'下書き',
                'stopped'=>'停止',
                'ended'=>'終了'
              ];
              foreach($filters as $k=>$v):
              ?>
              <option
                value="<?= h($k) ?>"
                <?= $filter===$k?'selected':'' ?>>
                <?= h($v) ?>
              </option>
              <?php endforeach ?>
            </select>
          </div>

          <div class="field">
            <label>ソート</label>
            <select name="sort">
              <?php
              $sorts=[
                'updated_desc'=>'更新日：新しい順',
                'updated_asc'=>'更新日：古い順',
                'answers_desc'=>'回答数：多い順',
                'answers_asc'=>'回答数：少ない順',
                'start_desc'=>'開始日：新しい順',
                'start_asc'=>'開始日：古い順'
              ];
              foreach($sorts as $k=>$v):
              ?>
              <option
                value="<?= h($k) ?>"
                <?= $sort===$k?'selected':'' ?>>
                <?= h($v) ?>
              </option>
              <?php endforeach ?>
            </select>
          </div>
        </div>

        <button type="submit" class="primary">検索</button>
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
            <th>期間</th>
            <th>状態</th>
            <th>回答数</th>
            <th>操作</th>
          </tr>
          </thead>
          <tbody>
          <?php if(!$surveys): ?>
          <tr>
            <td colspan="7">該当するアンケートはありません。</td>
          </tr>
          <?php endif ?>

          <?php foreach($surveys as $s): ?>
          <?php $sid=(string)$s['id']; ?>
          <tr>
            <td>
              <strong><?= h($s['title']) ?></strong>
            </td>
            <td><?= h($s['createdAt']) ?></td>
            <td><?= h($s['updatedAt']) ?></td>
            <td>
              <?= h($s['startAt']) ?><br>
              ～ <?= h($s['endAt']) ?>
            </td>
            <td>
              <span class="badge <?= h(status_class($s['status'])) ?>">
                <?= h(status_label($s['status'])) ?>
              </span>
            </td>
            <td><?= (int)($answersBySurvey[$sid] ?? 0) ?></td>
            <td>
              <div class="actions">
                <a href="<?= h(app_url([
                    'screen'=>'edit','id'=>$sid
                ])) ?>">
                  <button type="button">確認・編集</button>
                </a>

                <a href="<?= h(app_url([
                    'screen'=>'analytics','id'=>$sid
                ])) ?>">
                  <button type="button">集計</button>
                </a>

                <a href="<?= h(app_url([
                    'screen'=>'send','id'=>$sid
                ])) ?>">
                  <button type="button">送信</button>
                </a>

                <form method="post"
                      onsubmit="return confirm('このアンケートを複製しますか？')">
                  <input type="hidden" name="action" value="duplicate_survey">
                  <input type="hidden" name="survey_id" value="<?= h($sid) ?>">
                  <input type="hidden" name="return_screen" value="list">
                  <button type="submit">複製</button>
                </form>

                <form method="post"
                      onsubmit="return confirm('このアンケートを削除しますか？')">
                  <input type="hidden" name="action" value="delete_survey">
                  <input type="hidden" name="survey_id" value="<?= h($sid) ?>">
                  <input type="hidden" name="return_screen" value="list">
                  <button type="submit" class="danger">削除</button>
                </form>
              </div>

              <?php if($s['status'] !== STATUS_ENDED): ?>
              <div class="actions" style="margin-top:8px">
                <?php if($s['status']===STATUS_DRAFT): ?>
                <?php $to=STATUS_PUBLISHED; ?>
                <?php elseif($s['status']===STATUS_PUBLISHED): ?>
                <?php $to=STATUS_STOPPED; ?>
                <?php else: ?>
                <?php $to=STATUS_PUBLISHED; ?>
                <?php endif; ?>

                <form method="post"
                      onsubmit="return confirm('状態を変更しますか？')">
                  <input type="hidden" name="action" value="change_status">
                  <input type="hidden" name="survey_id" value="<?= h($sid) ?>">
                  <input type="hidden" name="new_status" value="<?= h($to) ?>">
                  <input type="hidden" name="return_screen" value="list">

                  <button type="submit">
                    <?php
                    echo $to===STATUS_PUBLISHED
                      ? '公開・再開'
                      : '停止';
                    ?>
                  </button>
                </form>
              </div>
              <?php endif ?>
            </td>
          </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php
    admin_footer();
}

/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(array $data, ?string $id): void
{
    $survey = $id
        ? survey_get($data['surveys'],$id)
        : null;

    if ($id !== null && !$survey) {
        admin_header('アンケート編集');
        render_flash();
        echo '<div class="card">対象アンケートが見つかりません。</div>';
        admin_footer();
        return;
    }

    if (!$survey) {
        $survey = [
            'id'=>'',
            'title'=>'',
            'description'=>'',
            'startAt'=>date('Y-m-d\TH:i'),
            'endAt'=>date('Y-m-d\TH:i',strtotime('+30 days')),
            'status'=>STATUS_DRAFT,
            'numbering'=>'global',
            'groups'=>[[
                'id'=>uid('group'),
                'title'=>'新しいグループ',
                'questions'=>[]
            ]]
        ];
    }

    $survey = normalize_survey($survey);

    admin_header('アンケート作成・編集');
    render_flash();
    ?>

    <div class="page-title">
      <h1>アンケート作成・編集</h1>
      <div class="actions">
        <a href="<?= h(app_url(['screen'=>'list'])) ?>">
          <button type="button">キャンセル</button>
        </a>

        <button
          type="button"
          onclick="previewEdit()">
          プレビュー
        </button>

        <button
          type="submit"
          form="surveyForm"
          class="primary">
          保存して一覧へ
        </button>
      </div>
    </div>

    <form
      id="surveyForm"
      method="post"
      onsubmit="return normalizeBeforeSubmit()">

      <input type="hidden" name="action" value="save_survey">
      <input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
      <input type="hidden" name="return_screen" value="edit">

      <div class="card">
        <div class="grid">
          <div class="field">
            <label>アンケートタイトル</label>
            <input
              type="text"
              name="title"
              maxlength="<?= MAX_TITLE ?>"
              required
              value="<?= h($survey['title']) ?>">
          </div>

          <div class="field">
            <label>状態</label>
            <select disabled>
              <option><?= h(status_label($survey['status'])) ?></option>
            </select>
            <input
              type="hidden"
              name="status"
              value="<?= h($survey['status']) ?>">
          </div>
        </div>

        <div class="field">
          <label>アンケート説明</label>
          <textarea
            name="description"
            maxlength="<?= MAX_DESC ?>"><?= h($survey['description']) ?></textarea>
        </div>

        <div class="grid">
          <div class="field">
            <label>開始日時</label>
            <input
              type="datetime-local"
              name="startAt"
              value="<?= h($survey['startAt']) ?>">
          </div>

          <div class="field">
            <label>終了日時</label>
            <input
              type="datetime-local"
              name="endAt"
              value="<?= h($survey['endAt']) ?>">
          </div>
        </div>

        <div class="field">
          <label>質問番号の採番方式</label>
          <select
            name="numbering"
            onchange="recalcClientNumbers()">
            <option
              value="global"
              <?= $survey['numbering']==='global'?'selected':'' ?>>
              アンケート全体で通番（Q1、Q2、Q3…）
            </option>
            <option
              value="group"
              <?= $survey['numbering']==='group'?'selected':'' ?>>
              グループ毎に採番（Q1-1、Q1-2、Q2-1…）
            </option>
          </select>
        </div>
      </div>

      <div id="groups">
      <?php foreach($survey['groups'] as $g): ?>
        <?= group_html($g) ?>
      <?php endforeach ?>
      </div>

      <div class="card">
        <button
          type="button"
          onclick="addGroup()">
          ＋ グループを追加
        </button>
      </div>
    </form>

    <script>
    function uid(prefix){
      return prefix+'-'+Math.random().toString(36).slice(2,11);
    }

    function esc(v){
      return String(v ?? '').replace(/[&<>"']/g,m=>({
        '&':'&amp;','<':'&lt;','>':'&gt;',
        '"':'&quot;',"'":'&#039;'
      }[m]));
    }

    function recalcClientNumbers(){
      let globalNo=1;
      let groupNo=1;
      const mode=document.querySelector('[name=numbering]').value;

      document.querySelectorAll('.group').forEach(g=>{
        let qn=1;

        g.querySelectorAll('.question').forEach(q=>{
          const n=mode==='group'
            ? `Q${groupNo}-${qn}`
            : `Q${globalNo}`;

          q.querySelector('.question-number').textContent=n;

          globalNo++;
          qn++;
        });

        groupNo++;
      });
    }

    function addGroup(){
      const id=uid('group');
      const el=document.createElement('div');
      el.className='group';
      el.dataset.groupId=id;
      el.draggable=true;

      el.innerHTML=`
        <input type="hidden"
          name="groups[${id}][id]" value="${id}">

        <div class="actions">
          <span class="drag-handle">☷</span>
          <strong>グループ</strong>
          <button type="button"
            onclick="removeGroup(this)">
            グループを削除
          </button>
        </div>

        <div class="field">
          <label>グループタイトル</label>
          <input
            type="text"
            name="groups[${id}][title]"
            value="新しいグループ"
            required>
        </div>

        <div class="questions"></div>

        <button type="button"
          onclick="addQuestion(this)">
          ＋ 質問を追加
        </button>
      `;

      document.getElementById('groups').appendChild(el);
      installDnD();
      recalcClientNumbers();
    }

    function addQuestion(button){
      const group=button.closest('.group');
      const gid=group.dataset.groupId;
      const qid=uid('question');

      const q=document.createElement('div');
      q.className='question';
      q.dataset.questionId=qid;
      q.draggable=true;

      q.innerHTML=`
        <input type="hidden"
          name="groups[${gid}][questions][${qid}][id]"
          value="${qid}">

        <div class="question-head">
          <span class="drag-handle">☷</span>
          <span class="question-number">Q?</span>
          <button type="button"
            onclick="removeQuestion(this)">
            質問を削除
          </button>
        </div>

        <div class="field">
          <label>質問文</label>
          <textarea
            name="groups[${gid}][questions][${qid}][text]"
            maxlength="<?= MAX_Q ?>"
            required></textarea>
        </div>

        <div class="grid">
          <div class="field">
            <label>回答形式</label>
            <select
              name="groups[${gid}][questions][${qid}][type]"
              onchange="toggleQuestionType(this)">
              <option value="single">単一選択</option>
              <option value="multiple">複数選択</option>
              <option value="text">自由記述</option>
            </select>
          </div>

          <div class="field">
            <label>必須</label>
            <label>
              <input
                type="checkbox"
                name="groups[${gid}][questions][${qid}][required]"
                value="1">
              必須回答
            </label>
          </div>
        </div>

        <div class="options">
          <strong>選択肢</strong>
          <div class="option-list"></div>
          <button type="button"
            onclick="addOption(this)">
            選択肢を追加
          </button>
        </div>
      `;

      group.querySelector('.questions').appendChild(q);

      addOption(q.querySelector('.options button'));
      addOption(q.querySelector('.options button'));

      installDnD();
      recalcClientNumbers();
      toggleQuestionType(q.querySelector('select'));
    }

    function addOption(button){
      const q=button.closest('.question');
      const gid=q.closest('.group').dataset.groupId;
      const qid=q.dataset.questionId;
      const oid=uid('option');

      const row=document.createElement('div');
      row.className='option';

      row.innerHTML=`
        <input type="hidden"
          name="groups[${gid}][questions][${qid}][options][${oid}][id]"
          value="${oid}">

        <input type="text"
          name="groups[${gid}][questions][${qid}][options][${oid}][label]"
          maxlength="<?= MAX_OPT ?>"
          placeholder="選択肢">

        <select
          name="groups[${gid}][questions][${qid}][options][${oid}][nextQuestionId]"
          class="next-question">
          <option value="">次の質問へ</option>
        </select>

        <button type="button"
          onclick="removeOption(this)">
          削除
        </button>
      `;

      q.querySelector('.option-list').appendChild(row);
      rebuildNextQuestionSelects();
    }

    function removeOption(button){
      const row=button.closest('.option');
      row.remove();
      rebuildNextQuestionSelects();
    }

    function removeQuestion(button){
      if(!confirm('この質問を削除しますか？')) return;
      button.closest('.question').remove();
      rebuildNextQuestionSelects();
      recalcClientNumbers();
    }

    function removeGroup(button){
      if(!confirm('このグループを削除しますか？')) return;
      button.closest('.group').remove();
      recalcClientNumbers();
      rebuildNextQuestionSelects();
    }

    function toggleQuestionType(select){
      const q=select.closest('.question');
      const box=q.querySelector('.options');

      if(select.value==='text'){
        box.style.display='none';
      }else{
        box.style.display='';
      }
    }

    function rebuildNextQuestionSelects(){
      const qs=[...document.querySelectorAll('.question')];

      document.querySelectorAll('.next-question').forEach(select=>{
        const current=select.value;

        select.innerHTML='<option value="">次の質問へ</option>';

        qs.forEach(q=>{
          const id=q.dataset.questionId;
          const number=q.querySelector('.question-number').textContent;
          const text=q.querySelector('textarea')?.value || '';

          const o=document.createElement('option');
          o.value=id;
          o.textContent=number+' '+text.slice(0,40);

          if(id===current) o.selected=true;

          select.appendChild(o);
        });
      });
    }

    function normalizeBeforeSubmit(){
      recalcClientNumbers();
      rebuildNextQuestionSelects();
      return true;
    }

    function previewEdit(){
      const form=document.getElementById('surveyForm');

      if(!form.reportValidity()) return;

      const old=form.action;
      const target='<?= h(app_url(['screen'=>'preview'])) ?>';

      const data=new FormData(form);

      const f=document.createElement('form');
      f.method='post';
      f.action=target;

      for(const [k,v] of data.entries()){
        const i=document.createElement('input');
        i.type='hidden';
        i.name=k;
        i.value=v;
        f.appendChild(i);
      }

      document.body.appendChild(f);
      f.submit();
    }

    function installDnD(){
      const groups=document.getElementById('groups');

      groups.querySelectorAll('.group').forEach(group=>{
        if(group.dataset.dnd==='1') return;
        group.dataset.dnd='1';

        group.addEventListener('dragstart',()=>{
          group.classList.add('dragging');
        });

        group.addEventListener('dragend',()=>{
          group.classList.remove('dragging');
          recalcClientNumbers();
          rebuildNextQuestionSelects();
        });

        group.addEventListener('dragover',e=>{
          e.preventDefault();
          const dragging=groups.querySelector('.group.dragging');

          if(dragging && dragging!==group){
            const rect=group.getBoundingClientRect();
            const before=e.clientY<rect.top+rect.height/2;
            groups.insertBefore(
              dragging,
              before?group:group.nextSibling
            );
          }
        });

        const list=group.querySelector('.questions');

        list.querySelectorAll('.question').forEach(q=>{
          if(q.dataset.dnd==='1') return;
          q.dataset.dnd='1';

          q.addEventListener('dragstart',e=>{
            e.stopPropagation();
            q.classList.add('dragging');
            e.dataTransfer.setData('text/plain',q.dataset.questionId);
          });

          q.addEventListener('dragend',()=>{
            q.classList.remove('dragging');
            recalcClientNumbers();
            rebuildNextQuestionSelects();
          });

          q.addEventListener('dragover',e=>{
            e.preventDefault();
            e.stopPropagation();

            const dragging=document.querySelector('.question.dragging');

            if(!dragging || dragging===q) return;

            const rect=q.getBoundingClientRect();
            const before=e.clientY<rect.top+rect.height/2;

            q.parentNode.insertBefore(
              dragging,
              before?q:q.nextSibling
            );
          });

          q.addEventListener('drop',e=>{
            e.preventDefault();
            e.stopPropagation();
            recalcClientNumbers();
            rebuildNextQuestionSelects();
          });
        });

        list.addEventListener('dragover',e=>{
          e.preventDefault();
        });

        list.addEventListener('drop',e=>{
          const dragging=document.querySelector('.question.dragging');

          if(!dragging) return;

          e.preventDefault();

          list.appendChild(dragging);
          recalcClientNumbers();
          rebuildNextQuestionSelects();
        });
      });
    }

    document.addEventListener('DOMContentLoaded',()=>{
      document.querySelectorAll('.question select').forEach(toggleQuestionType);
      installDnD();
      recalcClientNumbers();
      rebuildNextQuestionSelects();
    });
    </script>

    <?php
    admin_footer();
}

function group_html(array $g): string
{
    $gid = (string)$g['id'];

    ob_start();
    ?>
    <div
      class="group"
      data-group-id="<?= h($gid) ?>"
      draggable="true">

      <input type="hidden"
        name="groups[<?= h($gid) ?>][id]"
        value="<?= h($gid) ?>">

      <div class="actions">
        <span class="drag-handle">☷</span>

        <strong>グループ</strong>

        <button type="button"
          onclick="removeGroup(this)">
          グループを削除
        </button>
      </div>

      <div class="field">
        <label>グループタイトル</label>
        <input
          type="text"
          name="groups[<?= h($gid) ?>][title]"
          maxlength="<?= MAX_TITLE ?>"
          required
          value="<?= h($g['title']) ?>">
      </div>

      <div class="questions">
      <?php foreach($g['questions'] as $q): ?>
        <?= question_html($gid,$q) ?>
      <?php endforeach ?>
      </div>

      <button type="button"
        onclick="addQuestion(this)">
        ＋ 質問を追加
      </button>
    </div>
    <?php
    return (string)ob_get_clean();
}

function question_html(string $gid,array $q): string
{
    $qid=(string)$q['id'];

    ob_start();
    ?>
    <div
      class="question"
      data-question-id="<?= h($qid) ?>"
      draggable="true">

      <input type="hidden"
        name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][id]"
        value="<?= h($qid) ?>">

      <div class="question-head">
        <span class="drag-handle">☷</span>
        <span class="question-number">
          <?= h($q['number']) ?>
        </span>

        <button type="button"
          onclick="removeQuestion(this)">
          質問を削除
        </button>
      </div>

      <div class="field">
        <label>質問文</label>
        <textarea
          name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][text]"
          maxlength="<?= MAX_Q ?>"
          required><?= h($q['text']) ?></textarea>
      </div>

      <div class="grid">
        <div class="field">
          <label>回答形式</label>
          <select
            name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][type]"
            onchange="toggleQuestionType(this)">
            <option
              value="single"
              <?= $q['type']==='single'?'selected':'' ?>>
              単一選択
            </option>
            <option
              value="multiple"
              <?= $q['type']==='multiple'?'selected':'' ?>>
              複数選択
            </option>
            <option
              value="text"
              <?= $q['type']==='text'?'selected':'' ?>>
              自由記述
            </option>
          </select>
        </div>

        <div class="field">
          <label>必須</label>
          <label>
            <input
              type="checkbox"
              name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][required]"
              value="1"
              <?= !empty($q['required'])?'checked':'' ?>>
            必須回答
          </label>
        </div>
      </div>

      <div
        class="options"
        style="<?= $q['type']==='text'?'display:none':'' ?>">

        <strong>選択肢</strong>

        <div class="option-list">
        <?php foreach($q['options'] as $o): ?>
          <?= option_html($gid,$qid,$o) ?>
        <?php endforeach ?>
        </div>

        <button type="button"
          onclick="addOption(this)">
          ＋ 選択肢を追加
        </button>
      </div>
    </div>
    <?php

    return (string)ob_get_clean();
}

function option_html(
    string $gid,
    string $qid,
    array $o
): string {
    $oid=(string)$o['id'];

    ob_start();
    ?>
    <div class="option">
      <input type="hidden"
        name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][options][<?= h($oid) ?>][id]"
        value="<?= h($oid) ?>">

      <input type="text"
        name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][options][<?= h($oid) ?>][label]"
        maxlength="<?= MAX_OPT ?>"
        value="<?= h($o['label']) ?>">

      <select
        name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][options][<?= h($oid) ?>][nextQuestionId]"
        class="next-question">
        <option value="">次の質問へ</option>
      </select>

      <button type="button"
        onclick="removeOption(this)">
        削除
      </button>
    </div>
    <?php
    return (string)ob_get_clean();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(array $data, string $id): void
{
    $survey=survey_get($data['surveys'],$id);

    if(!$survey){
        admin_header('プレビュー');
        render_flash();
        echo '<div class="card">対象アンケートが見つかりません。</div>';
        admin_footer();
        return;
    }

    admin_header('プレビュー');
    render_flash();
    ?>

    <div class="page-title">
      <h1>プレビュー</h1>
      <a href="<?= h(app_url(['screen'=>'edit','id'=>$id])) ?>">
        <button type="button">編集へ戻る</button>
      </a>
    </div>

    <div class="card">
      <h2><?= h($survey['title']) ?></h2>
      <p><?= nl2br(h($survey['description'])) ?></p>
    </div>

    <?php foreach($survey['groups'] as $g): ?>
      <div class="card">
        <h3><?= h($g['title']) ?></h3>

        <?php foreach($g['questions'] as $q): ?>
          <div class="answer-question">
            <strong><?= h($q['number']) ?></strong>
            <div><?= nl2br(h($q['text'])) ?></div>

            <?php if($q['required']): ?>
              <span class="badge warning">必須</span>
            <?php endif ?>

            <?php if($q['type']==='text'): ?>
              <textarea disabled></textarea>
            <?php else: ?>
              <?php foreach($q['options'] as $o): ?>
                <label class="choice">
                  <input
                    type="<?= $q['type']==='single'?'radio':'checkbox' ?>"
                    disabled>
                  <span><?= h($o['label']) ?></span>

                  <?php if($o['nextQuestionId']!==''): ?>
                    <span class="small">
                      → 条件分岐あり
                    </span>
                  <?php endif ?>
                </label>
              <?php endforeach ?>
            <?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
    <?php endforeach ?>

    <?php
    admin_footer();
}

/* =========================================================
 * 送信
 * ========================================================= */

function render_send(array $data,array $settings,string $id): void
{
    $survey=survey_get($data['surveys'],$id);

    if(!$survey){
        admin_header('顧客選択・メール送信');
        render_flash();
        echo '<div class="card">対象アンケートが見つかりません。</div>';
        admin_footer();
        return;
    }

    $results=$_SESSION['send_results'] ?? [];
    unset($_SESSION['send_results']);

    $history=array_values(array_filter(
        $data['send_history'],
        static fn(array $x): bool =>
            (string)($x['survey_id'] ?? '')===$id
    ));

    $customers=$data['customers'];

    admin_header('顧客選択・メール送信');
    render_flash();
    ?>

    <div class="page-title">
      <h1>顧客選択・メール送信</h1>
      <span class="badge"><?= h($survey['title']) ?></span>
    </div>

    <div class="card">
      <h3>顧客選択</h3>

      <div class="field">
        <input
          id="customerSearch"
          type="text"
          placeholder="顧客名・組織名・メールアドレスで検索"
          oninput="filterCustomers()">
      </div>

      <form method="post"
        onsubmit="return confirm('選択した顧客へ一括送信しますか？')">

        <input type="hidden" name="action" value="send_mail">
        <input type="hidden" name="survey_id" value="<?= h($id) ?>">
        <input type="hidden" name="return_screen" value="send">

        <div class="table-wrap">
          <table id="customerTable">
            <thead>
            <tr>
              <th>
                <input type="checkbox"
                  onclick="toggleCustomers(this)">
              </th>
              <th>組織名</th>
              <th>氏名</th>
              <th>部署</th>
              <th>メール</th>
              <th>電話</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach($customers as $c): ?>
            <?php
              $cid=(string)($c['id']??'');
              $search=implode(' ',[
                $c['organization']??'',
                $c['name']??'',
                $c['department']??'',
                $c['email']??''
              ]);
            ?>
            <tr data-search="<?= h($search) ?>">
              <td>
                <input
                  type="checkbox"
                  name="customers[]"
                  value="<?= h($cid) ?>">
              </td>
              <td><?= h($c['organization']??'') ?></td>
              <td><?= h($c['name']??'') ?></td>
              <td><?= h($c['department']??'') ?></td>
              <td><?= h($c['email']??'') ?></td>
              <td><?= h($c['phone']??'') ?></td>
            </tr>
            <?php endforeach ?>

            <?php if(!$customers): ?>
            <tr>
              <td colspan="6">
                顧客情報がありません。
                kintone連携設定から同期してください。
              </td>
            </tr>
            <?php endif ?>
            </tbody>
          </table>
        </div>

        <hr>

        <div class="grid">
          <div class="field">
            <label>メール件名</label>
            <input
              type="text"
              name="subject"
              value="<?= h($survey['title']) ?>"
              required>
          </div>

          <div class="field">
            <label>利用可能な変数</label>
            <div class="small">
              {顧客名} / {アンケートURL}
            </div>
          </div>
        </div>

        <div class="field">
          <label>メール本文</label>
          <textarea
            name="body"
            rows="12"
            required>アンケートのご案内です。

{顧客名} 様

以下のURLからアンケートへご回答ください。

{アンケートURL}</textarea>
        </div>

        <button type="submit" class="primary">
          一括送信
        </button>
      </form>
    </div>

    <?php if($results): ?>
    <div class="card">
      <h3>今回の送信結果</h3>

      <div class="table-wrap">
        <table>
          <thead>
          <tr>
            <th>メール</th>
            <th>結果</th>
            <th>内容</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach($results as $r): ?>
          <tr>
            <td><?= h($r['email']??'') ?></td>
            <td>
              <span class="badge <?= ($r['status']??'')==='sent'
                ? 'success'
                : 'danger' ?>">
                <?= ($r['status']??'')==='sent'
                  ? '送信成功'
                  : '送信失敗' ?>
              </span>
            </td>
            <td><?= h($r['message']??'') ?></td>
          </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif ?>

    <div class="card">
      <h3>送信履歴</h3>

      <div class="table-wrap">
        <table>
          <thead>
          <tr>
            <th>日時</th>
            <th>メール</th>
            <th>件名</th>
            <th>結果</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach(array_reverse($history) as $r): ?>
          <tr>
            <td><?= h($r['sentAt']??'') ?></td>
            <td><?= h($r['email']??'') ?></td>
            <td><?= h($r['subject']??'') ?></td>
            <td><?= h($r['status']??'') ?></td>
          </tr>
          <?php endforeach ?>

          <?php if(!$history): ?>
          <tr>
            <td colspan="4">送信履歴はありません。</td>
          </tr>
          <?php endif ?>
          </tbody>
        </table>
      </div>
    </div>

    <script>
    function filterCustomers(){
      const q=document.getElementById('customerSearch')
        .value.toLowerCase();

      document.querySelectorAll('#customerTable tbody tr')
        .forEach(row=>{
          const s=(row.dataset.search||'').toLowerCase();
          row.style.display=s.includes(q)?'':'none';
        });
    }

    function toggleCustomers(master){
      document.querySelectorAll(
        '#customerTable tbody input[type=checkbox]'
      ).forEach(x=>{
        if(x.closest('tr').style.display!=='none'){
          x.checked=master.checked;
        }
      });
    }
    </script>

    <?php
    admin_footer();
}

/* =========================================================
 * kintone設定
 * ========================================================= */

function render_kintone(array $settings): void
{
    $c=$settings['kintone'];

    admin_header('kintone連携設定');
    render_flash();
    ?>

    <div class="page-title">
      <h1>kintone連携設定</h1>
    </div>

    <div class="card">
      <form method="post">
        <input type="hidden" name="action" value="save_kintone">
        <input type="hidden" name="return_screen" value="kintone">

        <div class="grid">
          <div class="field">
            <label>サブドメイン</label>
            <input
              type="text"
              name="subdomain"
              value="<?= h($c['subdomain']) ?>"
              placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
              required>
          </div>

          <div class="field">
            <label>顧客管理アプリID</label>
            <input
              type="number"
              name="app_id"
              min="1"
              value="<?= h($c['app_id']) ?>"
              required>
          </div>

          <div class="field">
            <label>ログイン名</label>
            <input
              type="text"
              name="username"
              value="<?= h($c['username']) ?>"
              required>
          </div>

          <div class="field">
            <label>パスワード</label>
            <input
              type="password"
              name="password"
              placeholder="変更する場合のみ入力">
          </div>

          <div class="field">
            <label>Proxy</label>
            <input
              type="text"
              name="proxy"
              value="<?= h($c['proxy']) ?>"
              placeholder="host:port">
          </div>

          <div class="field">
            <label>SSL証明書検証</label>
            <label>
              <input
                type="checkbox"
                name="verify_ssl"
                value="1"
                <?= !empty($c['verify_ssl'])?'checked':'' ?>>
              有効
            </label>
          </div>
        </div>

        <button type="submit" class="primary">
          設定保存
        </button>
      </form>
    </div>

    <div class="card">
      <h3>接続確認</h3>

      <div class="actions">
        <form method="post"
          onsubmit="return busySubmit(this,'接続テスト中です...')">
          <input type="hidden" name="action" value="test_kintone">
          <input type="hidden" name="return_screen" value="kintone">
          <button type="submit">接続テスト</button>
        </form>

        <form method="post"
          onsubmit="return busySubmit(this,'項目取得中です...')">
          <input type="hidden" name="action" value="load_kintone_fields">
          <input type="hidden" name="return_screen" value="kintone">
          <button type="submit">項目一覧を再取得</button>
        </form>

        <form method="post"
          onsubmit="return confirm('kintoneから顧客情報を同期しますか？')">
          <input type="hidden" name="action" value="sync_kintone">
          <input type="hidden" name="return_screen" value="kintone">
          <button type="submit" class="primary">
            顧客情報を同期
          </button>
        </form>
      </div>

      <?php if(!empty($c['last_test'])): ?>
      <p class="small">
        最終接続確認：<?= h($c['last_test']) ?>
      </p>
      <?php endif ?>

      <?php if(!empty($c['last_sync'])): ?>
      <p class="small">
        最終同期：<?= h($c['last_sync']) ?>
      </p>
      <?php endif ?>
    </div>

    <div class="card">
      <h3>顧客情報マッピング</h3>

      <?php if(empty($c['fields'])): ?>
      <p class="muted">
        先に「項目一覧を再取得」を実行してください。
      </p>
      <?php else: ?>

      <form method="post">
        <input
          type="hidden"
          name="action"
          value="save_kintone_mapping">
        <input
          type="hidden"
          name="return_screen"
          value="kintone">

        <div class="grid">
          <?php
          $maps=[
            'organization'=>'組織名',
            'name'=>'氏名',
            'email'=>'メールアドレス',
            'department'=>'部署名',
            'phone'=>'電話番号'
          ];
          ?>

          <?php foreach($maps as $key=>$label): ?>
          <div class="field">
            <label><?= h($label) ?></label>
            <select name="mapping_<?= h($key) ?>">
              <option value="">未設定</option>
              <?php foreach($c['fields'] as $f): ?>
              <option
                value="<?= h($f['code']) ?>"
                <?= ($c['mapping'][$key]??'')===$f['code']
                  ? 'selected'
                  : '' ?>>
                <?= h($f['label']) ?>
                (<?= h($f['code']) ?>)
              </option>
              <?php endforeach ?>
            </select>
          </div>
          <?php endforeach ?>
        </div>

        <div class="field">
          <label>住所（複数選択可）</label>

          <?php foreach($c['fields'] as $f): ?>
          <label>
            <input
              type="checkbox"
              name="mapping_address[]"
              value="<?= h($f['code']) ?>"
              <?= in_array(
                $f['code'],
                $c['mapping']['address']??[],
                true
              ) ? 'checked' : '' ?>>
            <?= h($f['label']) ?>
            (<?= h($f['code']) ?>)
          </label>
          <?php endforeach ?>
        </div>

        <button type="submit" class="primary">
          マッピング保存
        </button>
      </form>
      <?php endif ?>
    </div>

    <script>
    function busySubmit(form,message){
      const buttons=form.querySelectorAll('button');
      buttons.forEach(b=>{
        b.disabled=true;
        b.textContent=message;
      });
      return true;
    }
    </script>

    <?php
    admin_footer();
}

/* =========================================================
 * メール設定
 * ========================================================= */

function render_mail(array $settings): void
{
    $c=$settings['mail'];

    admin_header('メールサーバ設定');
    render_flash();
    ?>

    <div class="page-title">
      <h1>メールサーバ設定</h1>
    </div>

    <div class="card">
      <form method="post">
        <input type="hidden" name="action" value="save_mail">
        <input type="hidden" name="return_screen" value="mail">

        <div class="grid">
          <div class="field">
            <label>SMTPサーバ</label>
            <input
              type="text"
              name="host"
              value="<?= h($c['host']) ?>"
              placeholder="smtp.example.com"
              required>
          </div>

          <div class="field">
            <label>SMTPポート</label>
            <input
              type="number"
              name="port"
              min="1"
              max="65535"
              value="<?= h($c['port']) ?>"
              required>
          </div>

          <div class="field">
            <label>暗号化方式</label>
            <select name="encryption">
              <option
                value="ssl"
                <?= $c['encryption']==='ssl'?'selected':'' ?>>
                SSL
              </option>
              <option
                value="tls"
                <?= $c['encryption']==='tls'?'selected':'' ?>>
                TLS / STARTTLS
              </option>
              <option
                value="none"
                <?= $c['encryption']==='none'?'selected':'' ?>>
                なし
              </option>
            </select>
          </div>

          <div class="field">
            <label>SMTP認証</label>
            <label>
              <input
                type="checkbox"
                name="auth"
                value="1"
                <?= !empty($c['auth'])?'checked':'' ?>>
              認証する
            </label>
          </div>

          <div class="field">
            <label>SMTPユーザー名</label>
            <input
              type="text"
              name="username"
              value="<?= h($c['username']) ?>">
          </div>

          <div class="field">
            <label>SMTPパスワード</label>
            <input
              type="password"
              name="password"
              placeholder="変更する場合のみ入力">
          </div>

          <div class="field">
            <label>送信元メールアドレス</label>
            <input
              type="email"
              name="from_email"
              value="<?= h($c['from_email']) ?>"
              required>
          </div>

          <div class="field">
            <label>送信元名</label>
            <input
              type="text"
              name="from_name"
              value="<?= h($c['from_name']) ?>">
          </div>

          <div class="field">
            <label>返信先メールアドレス</label>
            <input
              type="email"
              name="reply_to"
              value="<?= h($c['reply_to']) ?>">
          </div>
        </div>

        <button type="submit" class="primary">
          設定保存
        </button>
      </form>
    </div>

    <div class="card">
      <h3>接続テスト</h3>

      <div class="actions">
        <form method="post"
          onsubmit="return busyMail(this)">
          <input type="hidden" name="action" value="test_mail">
          <input type="hidden" name="return_screen" value="mail">
          <button type="submit">
            接続テスト
          </button>
        </form>

        <form method="post">
          <input type="hidden"
            name="action"
            value="send_test_mail">
          <input type="hidden"
            name="return_screen"
            value="mail">

          <input
            type="email"
            name="test_email"
            placeholder="テスト送信先"
            required>

          <button type="submit">
            テストメール送信
          </button>
        </form>
      </div>

      <?php if(!empty($c['last_test'])): ?>
      <p class="small">
        最終接続確認：<?= h($c['last_test']) ?>
      </p>
      <?php endif ?>
    </div>

    <script>
    function busyMail(form){
      form.querySelectorAll('button').forEach(b=>{
        b.disabled=true;
        b.textContent='接続確認中...';
      });
      return true;
    }
    </script>

    <?php
    admin_footer();
}

/* =========================================================
 * 集計
 * ========================================================= */

function render_analytics(array $data,string $id): void
{
    $survey=survey_get($data['surveys'],$id);

    if(!$survey){
        admin_header('回答集計・分析');
        render_flash();
        echo '<div class="card">対象アンケートが見つかりません。</div>';
        admin_footer();
        return;
    }

    $answers=array_values(array_filter(
        $data['answers'],
        static fn(array $a): bool =>
            (string)($a['survey_id']??'')===$id
    ));

    $sent=array_values(array_filter(
        $data['send_history'],
        static fn(array $a): bool =>
            (string)($a['survey_id']??'')===$id
    ));

    $sentCustomers=[];
    foreach($sent as $x){
        $sentCustomers[(string)($x['customer_id']??'')]=true;
    }

    $answerCount=count($answers);
    $sentCount=count($sentCustomers);
    $registered=0;

    foreach($answers as $a){
        $registered++;
    }

    $unregistered=max(0,$answerCount-$registered);
    $unanswered=max(0,$sentCount-$answerCount);
    $rate=$sentCount>0
        ? round($answerCount/$sentCount*100,1)
        : 0;

    admin_header('回答集計・分析');
    render_flash();
    ?>

    <div class="page-title">
      <h1>回答集計・分析</h1>
      <span class="badge"><?= h($survey['title']) ?></span>
    </div>

    <div class="grid3">
      <div class="card">
        <div class="small">送信対象者数</div>
        <h2><?= $sentCount ?></h2>
      </div>

      <div class="card">
        <div class="small">回答数</div>
        <h2><?= $answerCount ?></h2>
      </div>

      <div class="card">
        <div class="small">回答率</div>
        <h2><?= h((string)$rate) ?>%</h2>
      </div>

      <div class="card">
        <div class="small">未登録回答数</div>
        <h2><?= $unregistered ?></h2>
      </div>

      <div class="card">
        <div class="small">未回答数</div>
        <h2><?= $unanswered ?></h2>
      </div>
    </div>

    <div class="card">
      <div class="actions">
        <a href="<?= h(app_url([
          'screen'=>'analytics',
          'id'=>$id,
          'export'=>'csv'
        ])) ?>">
          <button type="button">CSV出力</button>
        </a>

        <a href="<?= h(app_url([
          'screen'=>'analytics',
          'id'=>$id,
          'export'=>'pdf'
        ])) ?>">
          <button type="button">PDF出力</button>
        </a>
      </div>
    </div>

    <?php if(!$answers): ?>

    <div class="card">
      現在、回答データはありません
    </div>

    <?php else: ?>

    <?php foreach($survey['groups'] as $g): ?>
      <div class="card">
        <h3><?= h($g['title']) ?></h3>

        <?php foreach($g['questions'] as $q): ?>

        <?php
        $counts=[];
        foreach($q['options'] as $o){
          $counts[(string)$o['id']]=0;
        }

        $textAnswers=[];

        foreach($answers as $a){
          $v=$a['answers'][$q['id']]??'';

          if($q['type']==='text'){
            if(is_scalar($v) && trim((string)$v)!==''){
              $textAnswers[]=(string)$v;
            }
          }elseif($q['type']==='single'){
            if(isset($counts[(string)$v])){
              $counts[(string)$v]++;
            }
          }else{
            foreach(is_array($v)?$v:[] as $x){
              if(isset($counts[(string)$x])){
                $counts[(string)$x]++;
              }
            }
          }
        }
        ?>

        <div class="answer-question">
          <strong><?= h($q['number']) ?></strong>
          <div><?= nl2br(h($q['text'])) ?></div>

          <?php if($q['type']!=='text'): ?>
            <div class="table-wrap">
              <table>
                <thead>
                <tr>
                  <th>選択肢</th>
                  <th>回答数</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach($q['options'] as $o): ?>
                <tr>
                  <td><?= h($o['label']) ?></td>
                  <td><?= (int)($counts[$o['id']]??0) ?></td>
                </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <?php if(!$textAnswers): ?>
            <p class="muted">回答なし</p>
            <?php else: ?>
              <?php foreach($textAnswers as $v): ?>
                <div class="card">
                  <?= nl2br(h($v)) ?>
                </div>
              <?php endforeach ?>
            <?php endif ?>
          <?php endif ?>
        </div>

        <?php endforeach ?>
      </div>
    <?php endforeach ?>

    <div class="card">
      <h3>個別回答</h3>

      <?php foreach($answers as $i=>$a): ?>
      <details>
        <summary>
          回答 <?= $i+1 ?> /
          <?= h($a['createdAt']??'') ?>
        </summary>

        <?php foreach(visible_questions(
          $survey,
          $a['answers']??[]
        ) as $q): ?>
          <div style="padding:10px 0;border-bottom:1px solid #e2e8f0">
            <strong><?= h($q['number']) ?></strong>
            <div><?= nl2br(h($q['text'])) ?></div>
            <div>
            <?php
            $v=$a['answers'][$q['id']]??'';

            if(is_array($v)){
              $labels=[];
              foreach($q['options'] as $o){
                if(in_array($o['id'],$v,true)){
                  $labels[]=$o['label'];
                }
              }
              echo h(implode('、',$labels));
            }elseif($q['type']==='single'){
              foreach($q['options'] as $o){
                if((string)$o['id']===(string)$v){
                  echo h($o['label']);
                }
              }
            }else{
              echo nl2br(h((string)$v));
            }
            ?>
            </div>
          </div>
        <?php endforeach ?>
      </details>
      <?php endforeach ?>
    </div>

    <?php endif ?>

    <?php
    admin_footer();
}

/* =========================================================
 * 回答者
 * ========================================================= */

function answer_header(string $title): void
{
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport"
      content="width=device-width,initial-scale=1">
    <title><?= h($title) ?></title>
    <style><?= css() ?></style>
    </head>
    <body>
    <?php
}

function render_answer(array $data,string $id): void
{
    $survey=survey_get($data['surveys'],$id);

    answer_header('アンケート回答');

    if(!$survey){
        echo '<div class="answer-shell"><div class="answer-card">';
        echo '<h1>アンケートが見つかりません。</h1>';
        echo '</div></div></body></html>';
        return;
    }

    $answers=$_SESSION['answer_draft']??[];

    if(!is_array($answers)){
        $answers=[];
    }

    $available=$survey['status']===STATUS_PUBLISHED;

    render_flash();
    ?>

    <div class="answer-shell">
      <div class="answer-card">
        <h1><?= h($survey['title']) ?></h1>

        <?php if($survey['description']!==''): ?>
        <p><?= nl2br(h($survey['description'])) ?></p>
        <?php endif ?>

        <?php if(!$available): ?>

        <div class="notice warning">
          現在、このアンケートには回答できません。
        </div>

        <?php else: ?>

        <form method="post">
          <input
            type="hidden"
            name="action"
            value="answer_confirm">

          <input
            type="hidden"
            name="survey_id"
            value="<?= h($id) ?>">

          <input
            type="hidden"
            name="return_screen"
            value="answer">

          <?php foreach(visible_questions(
            $survey,
            $answers
          ) as $q): ?>

          <div class="answer-question">
            <strong><?= h($q['number']) ?></strong>

            <?php if($q['required']): ?>
            <span class="badge warning">必須</span>
            <?php endif ?>

            <h3><?= nl2br(h($q['text'])) ?></h3>

            <?php if($q['type']==='single'): ?>

              <?php foreach($q['options'] as $o): ?>
              <label class="choice">
                <input
                  type="radio"
                  name="answers[<?= h($q['id']) ?>]"
                  value="<?= h($o['id']) ?>"
                  <?= (string)($answers[$q['id']]??'')===
                    (string)$o['id']
                    ? 'checked'
                    : '' ?>
                  <?= $q['required']?'required':'' ?>>
                <span><?= h($o['label']) ?></span>
              </label>
              <?php endforeach ?>

            <?php elseif($q['type']==='multiple'): ?>

              <?php
              $selected=is_array($answers[$q['id']]??null)
                ? $answers[$q['id']]
                : [];
              ?>

              <?php foreach($q['options'] as $o): ?>
              <label class="choice">
                <input
                  type="checkbox"
                  name="answers[<?= h($q['id']) ?>][]"
                  value="<?= h($o['id']) ?>"
                  <?= in_array(
                    $o['id'],
                    $selected,
                    true
                  )?'checked':'' ?>>
                <span><?= h($o['label']) ?></span>
              </label>
              <?php endforeach ?>

            <?php else: ?>

              <textarea
                name="answers[<?= h($q['id']) ?>]"
                <?= $q['required']?'required':'' ?>><?= h(
                  is_scalar($answers[$q['id']]??'')
                    ? $answers[$q['id']]
                    : ''
              ) ?></textarea>

            <?php endif ?>
          </div>

          <?php endforeach ?>

          <button
            type="submit"
            class="primary">
            回答を確認する
          </button>
        </form>

        <?php endif ?>
      </div>
    </div>

    </body>
    </html>
    <?php
}

function render_confirm(array $data,string $id): void
{
    $survey=survey_get($data['surveys'],$id);
    $answers=$_SESSION['answer_draft']??[];

    answer_header('回答確認');

    if(!$survey){
        echo '<div class="answer-shell"><div class="answer-card">';
        echo '<h1>アンケートが見つかりません。</h1>';
        echo '</div></div></body></html>';
        return;
    }

    if(!is_array($answers)){
        $answers=[];
    }

    ?>
    <div class="answer-shell">
      <div class="answer-card">
        <h1>回答確認</h1>
        <p><?= h($survey['title']) ?></p>

        <?php foreach(visible_questions(
          $survey,
          $answers
        ) as $q): ?>

        <div class="answer-question">
          <strong><?= h($q['number']) ?></strong>
          <div><?= nl2br(h($q['text'])) ?></div>

          <div>
          <?php
          $v=$answers[$q['id']]??'';

          if(is_array($v)){
            $labels=[];

            foreach($q['options'] as $o){
              if(in_array($o['id'],$v,true)){
                $labels[]=$o['label'];
              }
            }

            echo nl2br(h(implode("\n",$labels)));
          }elseif($q['type']==='single'){
            foreach($q['options'] as $o){
              if((string)$o['id']===(string)$v){
                echo h($o['label']);
              }
            }
          }else{
            echo nl2br(h((string)$v));
          }
          ?>
          </div>
        </div>

        <?php endforeach ?>

        <div class="actions">
          <form method="post">
            <input type="hidden"
              name="action"
              value="answer_back">
            <input type="hidden"
              name="survey_id"
              value="<?= h($id) ?>">
            <input type="hidden"
              name="return_screen"
              value="confirm">

            <button type="submit">
              戻って修正
            </button>
          </form>

          <form method="post"
            onsubmit="return confirm('回答を送信しますか？')">
            <input type="hidden"
              name="action"
              value="submit_answer">
            <input type="hidden"
              name="survey_id"
              value="<?= h($id) ?>">
            <input type="hidden"
              name="return_screen"
              value="confirm">

            <button
              type="submit"
              class="primary">
              回答を送信
            </button>
          </form>
        </div>
      </div>
    </div>

    </body>
    </html>
    <?php
}

function render_complete(array $data,string $id): void
{
    $survey=survey_get($data['surveys'],$id);

    answer_header('回答完了');
    ?>

    <div class="answer-shell">
      <div class="answer-card">
        <h1>回答完了</h1>

        <?php if($survey): ?>
        <p>
          「<?= h($survey['title']) ?>」への回答を
          受け付けました。
        </p>
        <?php endif ?>

        <div class="notice success">
          ご回答ありがとうございました。
        </div>

        <p class="muted">
          この画面で回答者フローは終了します。
        </p>
      </div>
    </div>

    </body>
    </html>
    <?php
}

/* =========================================================
 * CSV / PDF
 * ========================================================= */

function output_csv(array $survey,array $answers): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($survey['id'] . '-answers.csv') .
        '"'
    );

    $fp=fopen('php://output','wb');

    if(!$fp){
        throw new RuntimeException('CSV出力を開始できません。');
    }

    fwrite($fp,"\xEF\xBB\xBF");

    fputcsv(
        $fp,
        ['回答ID','回答日時','質問番号','質問','回答'],
        ',',
        '"',
        '\\'
    );

    foreach($answers as $a){
        foreach(visible_questions(
            $survey,
            $a['answers']??[]
        ) as $q){
            $v=$a['answers'][$q['id']]??'';

            if(is_array($v)){
                $labels=[];

                foreach($q['options'] as $o){
                    if(in_array($o['id'],$v,true)){
                        $labels[]=$o['label'];
                    }
                }

                $v=implode('、',$labels);
            }elseif($q['type']==='single'){
                foreach($q['options'] as $o){
                    if((string)$o['id']===(string)$v){
                        $v=$o['label'];
                        break;
                    }
                }
            }

            fputcsv(
                $fp,
                [
                    $a['id']??'',
                    $a['createdAt']??'',
                    $q['number'],
                    $q['text'],
                    $v
                ],
                ',',
                '"',
                '\\'
            );
        }
    }

    exit;
}

function pdf_escape(string $s): string
{
    return str_replace(
        ['\\','(',')',"\r","\n"],
        ['\\\\','\\(','\\)',' ',' '],
        $s
    );
}

function output_pdf(array $survey,array $answers): never
{
    /*
     * 外部PDFライブラリなしで生成する最小PDF。
     * 日本語フォントは環境依存になるため、
     * 実データをASCII安全表現へ変換して記録する。
     */
    $lines=[
        'Survey: '.$survey['title'],
        'Answers: '.count($answers)
    ];

    foreach($answers as $i=>$a){
        $lines[]='Answer '.($i+1).' '.$a['createdAt'];

        foreach(visible_questions(
            $survey,
            $a['answers']??[]
        ) as $q){
            $v=$a['answers'][$q['id']]??'';

            if(is_array($v)){
                $v=implode(',',$v);
            }

            $lines[]=$q['number'].' '.$q['text'].' : '.$v;
        }
    }

    $lines=array_map(
        static function(string $v):string{
            $v=preg_replace('/[^\x20-\x7E]/','?',$v)??'?';
            return substr($v,0,110);
        },
        $lines
    );

    $stream="BT\n/F1 9 Tf\n50 760 Td\n";

    foreach($lines as $line){
        $stream.='(' . pdf_escape($line) . ") Tj\n0 -14 Td\n";
    }

    $stream.="ET\n";

    $objects=[];

    $objects[]='<< /Type /Catalog /Pages 2 0 R >>';
    $objects[]='<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[]=
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 4 0 R >> >> ' .
        '/Contents 5 0 R >>';
    $objects[]=
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[]=
        '<< /Length '.strlen($stream).' >>' .
        "\nstream\n".$stream."endstream";

    $pdf="%PDF-1.4\n";
    $offsets=[0];

    foreach($objects as $i=>$obj){
        $n=$i+1;
        $offsets[$n]=strlen($pdf);
        $pdf.=$n." 0 obj\n".$obj."\nendobj\n";
    }

    $xref=strlen($pdf);

    $pdf.="xref\n0 ".(count($objects)+1)."\n";
    $pdf.="0000000000 65535 f \n";

    for($i=1;$i<=count($objects);$i++){
        $pdf.=sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf.="trailer\n<< /Size ".
        (count($objects)+1).
        " /Root 1 0 R >>\n";
    $pdf.="startxref\n".$xref."\n%%EOF";

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($survey['id'].'-answers.pdf') .
        '"'
    );

    echo $pdf;
    exit;
}

/* =========================================================
 * エラー画面
 * ========================================================= */

function render_error_page(string $message): never
{
    http_response_code(500);

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport"
      content="width=device-width,initial-scale=1">
    <title>エラー</title>
    <style><?= css() ?></style>
    </head>
    <body>
    <div class="answer-shell">
      <div class="answer-card">
        <h1>処理エラー</h1>
        <div class="notice error">
          <?= h($message) ?>
        </div>
        <a href="<?= h(app_url(['screen'=>'list'])) ?>">
          管理画面へ戻る
        </a>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/* =========================================================
 * 起動
 * ========================================================= */

try {
    ensure_storage();
    start_session();

    $data=load_data();
    $settings=load_settings();

    refresh_status($data);

    /*
     * GETではセッションIDを再生成しない。
     */
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $next=handle_post($data,$settings);

        if($next!==null){
            /*
             * ここでは302/303を使用しない。
             * POST処理の結果を確定後、同一リクエスト内で
             * 次画面を直接描画する。
             */
            $parts=parse_url($next);
            $screen=(string)($parts['query']??'');

            parse_str($screen,$params);

            $_GET=array_replace($_GET,$params);
        }
    }

    $screen=get_s('screen','list');
    $id=get_s('id');

    /*
     * CSV/PDFは画面描画より先に処理する。
     */
    if(
        $screen==='analytics' &&
        $id!=='' &&
        get_s('export')==='csv'
    ){
        $survey=survey_get($data['surveys'],$id);

        if(!$survey){
            render_error_page('対象アンケートが見つかりません。');
        }

        $answers=array_values(array_filter(
            $data['answers'],
            static fn(array $a):bool =>
                (string)($a['survey_id']??'')===$id
        ));

        if(!$answers){
            /*
             * CSV自体は実データ0件でも出力可能。
             */
        }

        output_csv($survey,$answers);
    }

    if(
        $screen==='analytics' &&
        $id!=='' &&
        get_s('export')==='pdf'
    ){
        $survey=survey_get($data['surveys'],$id);

        if(!$survey){
            render_error_page('対象アンケートが見つかりません。');
        }

        $answers=array_values(array_filter(
            $data['answers'],
            static fn(array $a):bool =>
                (string)($a['survey_id']??'')===$id
        ));

        output_pdf($survey,$answers);
    }

    /*
     * 回答者画面。
     * 管理者ヘッダーを絶対に出さない。
     */
    if($screen==='answer'){
        render_answer($data,$id);
        exit;
    }

    if($screen==='confirm'){
        render_confirm($data,$id);
        exit;
    }

    if($screen==='complete'){
        render_complete($data,$id);
        exit;
    }

    /*
     * 集計・送信はID必須。
     */
    if(in_array($screen,['analytics','send'],true)){
        if($id===''){
            flash(
                'error',
                '対象アンケートが指定されていません。'
            );

            $screen='list';
        }elseif(!survey_get($data['surveys'],$id)){
            flash(
                'error',
                '対象アンケートが見つかりません。'
            );

            $screen='list';
        }
    }

    switch($screen){
        case 'edit':
            render_edit(
                $data,
                $id!==''?$id:null
            );
            break;

        case 'preview':
            render_preview($data,$id);
            break;

        case 'send':
            render_send($data,$settings,$id);
            break;

        case 'analytics':
            render_analytics($data,$id);
            break;

        case 'kintone':
            render_kintone($settings);
            break;

        case 'mail':
            render_mail($settings);
            break;

        case 'list':
        default:
            render_list($data);
            break;
    }

} catch(Throwable $e) {
    render_error_page(
        external_error($e)
    );
}

<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 * 単一エントリーポイント
 *
 * 重要:
 *  - 管理者認証はPOCのため実装しない
 *  - 回答者画面と管理者画面を分離
 *  - kintone認証情報はブラウザへ公開しない
 *  - SMTP認証情報はブラウザへ公開しない
 *  - 利用者に「暗号化キー」を入力させない
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SECRET_FILE = DATA_DIR . DIRECTORY_SEPARATOR . '.survey_secret.php';

const K_TIMEOUT = 10;
const K_READ_TIMEOUT = 30;
const S_TIMEOUT = 10;
const S_READ_TIMEOUT = 30;

const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;

const ALLOWED_TYPES = ['single','multiple','text'];
const ALLOWED_STATUS = ['draft','published','stopped','ended'];

/* =========================================================
 * 基本
 * ========================================================= */

function h(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function post_string(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function get_string(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function post_bool(string $key): bool
{
    $v = strtolower((string)($_POST[$key] ?? ''));
    return in_array($v, ['1','true','on','yes'], true);
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function app_url(array $params = []): string
{
    $script = str_replace(
        '\\',
        '/',
        (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
    );

    if (!$params) {
        return $script;
    }

    return $script . '?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function public_url(string $id): string
{
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);

    return ($https ? 'https' : 'http')
        . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost')
        . app_url([
            'screen' => 'answer',
            'id' => $id
        ]);
}

function safe_error(Throwable $e): string
{
    $m = trim($e->getMessage());

    $sensitive = [
        'X-Cybozu-Authorization',
        'Authorization:',
        'password',
        'passwd',
        'secret',
        'SURVEY_APP_KEY'
    ];

    foreach ($sensitive as $x) {
        if (stripos($m, $x) !== false) {
            return '外部サービスとの通信に失敗しました。設定値を確認してください。';
        }
    }

    return $m !== ''
        ? $m
        : '処理に失敗しました。';
}

/* =========================================================
 * セッション
 * ========================================================= */

function cookie_path(): string
{
    $script = str_replace(
        '\\',
        '/',
        (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
    );

    $dir = dirname($script);

    if ($dir === '.' || $dir === '/' || $dir === '\\') {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);

    session_name('survey_app_session');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => cookie_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('セッションを開始できません。');
    }
}

/* =========================================================
 * JSON永続化
 * ========================================================= */

function ensure_data_dir(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException(
            'データ保存フォルダを作成できません。'
        );
    }
}

function read_json(string $file, array $fallback): array
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

    $v = json_decode($raw, true);

    return is_array($v) ? $v : $fallback;
}

function write_json(string $file, array $data): void
{
    ensure_data_dir();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException(
            '保存データを生成できません。'
        );
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));

    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
        throw new RuntimeException(
            '一時保存ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                '保存ファイルをロックできません。'
            );
        }

        $len = strlen($json);
        $written = 0;

        while ($written < $len) {
            $n = fwrite(
                $fp,
                substr($json, $written)
            );

            if ($n === false) {
                throw new RuntimeException(
                    'データを書き込めません。'
                );
            }

            $written += $n;
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException(
                '保存ファイルを更新できません。'
            );
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

/* =========================================================
 * 暗号化
 *
 * 「暗号化キーが不正です」という利用者入力依存の設計を廃止。
 * 鍵はアプリ初回起動時にサーバー側で生成する。
 *
 * .phpファイルなので通常のApache PHP設定では直接表示されない。
 * ========================================================= */

function secret_key(): string
{
    ensure_data_dir();

    if (is_file(SECRET_FILE)) {
        $key = include SECRET_FILE;

        if (is_string($key) && strlen($key) === 32) {
            return $key;
        }
    }

    $key = random_bytes(32);

    $php =
        "<?php\n"
        . "return "
        . var_export($key, true)
        . ";\n";

    $tmp = SECRET_FILE . '.tmp.' . bin2hex(random_bytes(8));

    if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
        throw new RuntimeException(
            'サーバー側暗号化キーを保存できません。'
        );
    }

    if (!@rename($tmp, SECRET_FILE)) {
        @unlink($tmp);
        throw new RuntimeException(
            'サーバー側暗号化キーを確定できません。'
        );
    }

    @chmod(SECRET_FILE, 0600);

    return $key;
}

function secret_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException(
            'PHP OpenSSL拡張が利用できないため、認証情報を安全に保存できません。'
        );
    }

    $key = secret_key();
    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException(
            '認証情報を暗号化できません。'
        );
    }

    return 'enc:v1:'
        . base64_encode($iv)
        . ':'
        . base64_encode($tag)
        . ':'
        . base64_encode($cipher);
}

function secret_decrypt(string $value): string
{
    if ($value === '') {
        return '';
    }

    /*
     * 旧版との互換。
     * enc:v1: でない既存値は旧版の平文として扱う。
     * ただし保存時には暗号化形式へ変換する。
     */
    if (!str_starts_with($value, 'enc:v1:')) {
        return $value;
    }

    $p = explode(':', $value, 5);

    if (count($p) !== 5) {
        throw new RuntimeException(
            '保存済み認証情報の形式が不正です。設定保存をやり直してください。'
        );
    }

    $iv = base64_decode($p[2], true);
    $tag = base64_decode($p[3], true);
    $cipher = base64_decode($p[4], true);

    if (
        $iv === false ||
        $tag === false ||
        $cipher === false ||
        strlen($iv) !== 12 ||
        strlen($tag) !== 16
    ) {
        throw new RuntimeException(
            '保存済み認証情報を復号できません。設定保存をやり直してください。'
        );
    }

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plain === false) {
        throw new RuntimeException(
            '保存済み認証情報を復号できません。設定保存をやり直してください。'
        );
    }

    return $plain;
}

function secure_password_value(string $value): string
{
    if ($value === '') {
        return '';
    }

    return str_starts_with($value, 'enc:v1:')
        ? $value
        : secret_encrypt($value);
}

/* =========================================================
 * デフォルトデータ
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
            'status' => 'draft',
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
                        'type' => 'single',
                        'required' => true,
                        'options' => [
                            [
                                'id' => 'option-001',
                                'label' => '非常に満足',
                                'nextQuestionId' => ''
                            ],
                            [
                                'id' => 'option-002',
                                'label' => '満足',
                                'nextQuestionId' => ''
                            ],
                            [
                                'id' => 'option-003',
                                'label' => '普通',
                                'nextQuestionId' => ''
                            ],
                            [
                                'id' => 'option-004',
                                'label' => '不満',
                                'nextQuestionId' => ''
                            ]
                        ]
                    ],
                    [
                        'id' => 'question-002',
                        'number' => 'Q2',
                        'text' => 'ご意見・ご要望があれば入力してください。',
                        'type' => 'text',
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

function load_data(): array
{
    $d = read_json(DATA_FILE, default_data());

    foreach (
        ['surveys','answers','customers','send_history']
        as $k
    ) {
        if (!isset($d[$k]) || !is_array($d[$k])) {
            $d[$k] = [];
        }
    }

    return $d;
}

function save_data(array $d): void
{
    write_json(DATA_FILE, $d);
}

function load_settings(): array
{
    $def = default_settings();
    $s = read_json(SET_FILE, $def);

    foreach (['kintone','mail'] as $k) {
        $s[$k] = array_replace_recursive(
            $def[$k],
            is_array($s[$k] ?? null)
                ? $s[$k]
                : []
        );
    }

    return $s;
}

function save_settings(array $s): void
{
    write_json(SET_FILE, $s);
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
 * アンケート
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

    return $i >= 0 && is_array($surveys[$i])
        ? $surveys[$i]
        : null;
}

function refresh_status(array &$data): void
{
    $changed = false;

    foreach ($data['surveys'] as &$s) {
        if (
            ($s['status'] ?? '') === 'published'
            && !empty($s['endAt'])
        ) {
            $t = strtotime((string)$s['endAt']);

            if ($t !== false && $t < time()) {
                $s['status'] = 'ended';
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

function all_questions(array $survey): array
{
    $out = [];

    foreach ($survey['groups'] ?? [] as $g) {
        foreach ($g['questions'] ?? [] as $q) {
            if (is_array($q)) {
                $out[] = $q;
            }
        }
    }

    return $out;
}

function question_ids(array $survey): array
{
    return array_map(
        static fn(array $q): string =>
            (string)($q['id'] ?? ''),
        all_questions($survey)
    );
}

function status_label(string $s): string
{
    return match ($s) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き'
    };
}

function status_class(string $s): string
{
    return match ($s) {
        'published' => 'success',
        'stopped' => 'warning',
        default => 'gray'
    };
}

function answer_count(array $data, string $surveyId): int
{
    $n = 0;

    foreach ($data['answers'] as $a) {
        if (
            is_array($a)
            && (string)($a['survey_id'] ?? '') === $surveyId
        ) {
            $n++;
        }
    }

    return $n;
}

/* =========================================================
 * 入力検証
 * ========================================================= */

function validate_survey(array $s): array
{
    $e = [];

    $title = trim((string)($s['title'] ?? ''));

    if ($title === '') {
        $e[] = 'アンケートタイトルを入力してください。';
    } elseif (mb_strlen($title) > MAX_TITLE) {
        $e[] = 'アンケートタイトルが長すぎます。';
    }

    if (
        mb_strlen((string)($s['description'] ?? ''))
        > MAX_DESCRIPTION
    ) {
        $e[] = 'アンケート説明が長すぎます。';
    }

    $start = (string)($s['startAt'] ?? '');
    $end = (string)($s['endAt'] ?? '');

    if ($start !== '' && strtotime($start) === false) {
        $e[] = '開始日時が不正です。';
    }

    if ($end !== '' && strtotime($end) === false) {
        $e[] = '終了日時が不正です。';
    }

    if (
        $start !== ''
        && $end !== ''
        && strtotime($start) !== false
        && strtotime($end) !== false
        && strtotime($start) > strtotime($end)
    ) {
        $e[] = '終了日時は開始日時以降にしてください。';
    }

    if (
        !in_array(
            (string)($s['numbering'] ?? ''),
            ['global','group'],
            true
        )
    ) {
        $e[] = '質問番号方式が不正です。';
    }

    if (!is_array($s['groups'] ?? null) || !$s['groups']) {
        $e[] = 'グループを1つ以上設定してください。';
        return $e;
    }

    foreach ($s['groups'] as $g) {
        if (!is_array($g)) {
            $e[] = 'グループデータが不正です。';
            continue;
        }

        if (
            trim((string)($g['title'] ?? '')) === ''
        ) {
            $e[] = 'グループタイトルを入力してください。';
        }

        foreach ($g['questions'] ?? [] as $q) {
            if (!is_array($q)) {
                $e[] = '質問データが不正です。';
                continue;
            }

            $text = trim((string)($q['text'] ?? ''));

            if ($text === '') {
                $e[] = '質問文を入力してください。';
            } elseif (mb_strlen($text) > MAX_QUESTION) {
                $e[] = '質問文が長すぎます。';
            }

            if (
                !in_array(
                    (string)($q['type'] ?? ''),
                    ALLOWED_TYPES,
                    true
                )
            ) {
                $e[] = '回答形式が不正です。';
            }

            if (
                in_array(
                    (string)($q['type'] ?? ''),
                    ['single','multiple'],
                    true
                )
            ) {
                foreach ($q['options'] ?? [] as $o) {
                    if (
                        mb_strlen(
                            trim((string)($o['label'] ?? ''))
                        ) > MAX_OPTION
                    ) {
                        $e[] = '選択肢が長すぎます。';
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

function normalize_subdomain(string $v): string
{
    $v = trim($v);

    $v = preg_replace(
        '#^https?://#i',
        '',
        $v
    ) ?? $v;

    $v = preg_replace(
        '#/.*$#',
        '',
        $v
    ) ?? $v;

    if (
        str_ends_with(
            strtolower($v),
            '.cybozu.com'
        )
    ) {
        $v = substr(
            $v,
            0,
            -strlen('.cybozu.com')
        );
    }

    return trim($v);
}

function validate_kintone(
    array $c,
    bool $password = true
): array {
    $e = [];

    $sub = normalize_subdomain(
        (string)($c['subdomain'] ?? '')
    );

    if (
        $sub === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $sub
        )
    ) {
        $e[] = 'kintoneサブドメインが不正です。';
    }

    $app = trim(
        (string)($c['app_id'] ?? '')
    );

    if (
        !ctype_digit($app)
        || (int)$app < 1
    ) {
        $e[] = '顧客管理アプリIDが不正です。';
    }

    if (
        trim((string)($c['username'] ?? '')) === ''
    ) {
        $e[] = 'ログイン名を入力してください。';
    }

    if (
        $password
        && trim((string)($c['password'] ?? '')) === ''
    ) {
        $e[] = 'パスワードを入力してください。';
    }

    $proxy = trim(
        (string)($c['proxy'] ?? '')
    );

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        $e[] = 'Proxyはhost:port形式で入力してください。';
    }

    return $e;
}

function kintone_request(
    array $c,
    string $method,
    string $path,
    ?array $body = null
): array {
    $c['password'] = secret_decrypt(
        (string)($c['password'] ?? '')
    );

    $errors = validate_kintone($c, true);

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $sub = normalize_subdomain(
        (string)$c['subdomain']
    );

    $url =
        'https://' . $sub . '.cybozu.com' . $path;

    $auth = base64_encode(
        (string)$c['username']
        . ':'
        . (string)$c['password']
    );

    $headers = [
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
            throw new RuntimeException(
                'kintoneリクエストを生成できません。'
            );
        }

        $headers[] =
            'Content-Type: application/json';
    }

    $verify = !empty($c['verify_ssl']);

    $opts = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode(
                "\r\n",
                $headers
            ),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => K_READ_TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $sub . '.cybozu.com'
        ]
    ];

    $proxy = trim(
        (string)($c['proxy'] ?? '')
    );

    if ($proxy !== '') {
        [$ph,$pp] = explode(
            ':',
            $proxy,
            2
        );

        $opts['http']['proxy'] =
            'tcp://' . $ph . ':' . (int)$pp;

        $opts['http']['request_fulluri'] = true;
    }

    $ctx = stream_context_create($opts);

    $headersBefore = $http_response_header ?? [];

    $response = @file_get_contents(
        $url,
        false,
        $ctx
    );

    $responseHeaders =
        $http_response_header ?? $headersBefore;

    $status = 0;

    foreach ($responseHeaders as $line) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $line,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへの通信に失敗しました。'
        );
    }

    if (
        $status === 302
        || $status === 303
    ) {
        throw new RuntimeException(
            'kintoneからHTTP '
            . $status
            . ' のリダイレクト応答が返されました。'
        );
    }

    $json = json_decode(
        $response,
        true
    );

    if (
        $status < 200
        || $status >= 300
    ) {
        $code = is_array($json)
            ? (string)($json['code'] ?? '')
            : '';

        $message = is_array($json)
            ? (string)($json['message'] ?? '')
            : '';

        $m =
            'kintone APIエラー HTTP '
            . $status;

        if ($code !== '') {
            $m .= ' [' . $code . ']';
        }

        if ($message !== '') {
            $m .= ' ' . $message;
        }

        throw new RuntimeException($m);
    }

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintoneから正常なJSON応答を取得できませんでした。'
        );
    }

    return [
        'status' => $status,
        'body' => $json,
        'headers' => $responseHeaders
    ];
}

function kintone_test(array $c): array
{
    return kintone_request(
        $c,
        'GET',
        '/k/v1/app.json?id='
        . rawurlencode(
            (string)$c['app_id']
        )
    );
}

function kintone_fields(array $c): array
{
    return kintone_request(
        $c,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode(
            (string)$c['app_id']
        )
    );
}

function kintone_records(
    array $c
): array {
    $all = [];
    $offset = 0;

    /*
     * kintone REST APIの取得上限を考慮して
     * offsetで繰り返す。
     */
    while (true) {
        $r = kintone_request(
            $c,
            'GET',
            '/k/v1/records.json?app='
            . rawurlencode(
                (string)$c['app_id']
            )
            . '&totalCount=true'
            . '&limit=500'
            . '&offset=' . $offset
        );

        $records =
            $r['body']['records'] ?? [];

        if (!is_array($records)) {
            throw new RuntimeException(
                'kintoneレコードの形式が不正です。'
            );
        }

        $all = array_merge(
            $all,
            $records
        );

        if (count($records) < 500) {
            return [
                'status' => $r['status'],
                'body' => [
                    'records' => $all,
                    'totalCount' =>
                        count($all)
                ]
            ];
        }

        $offset += 500;

        if ($offset > 100000) {
            throw new RuntimeException(
                'kintoneレコード取得件数が上限を超えました。'
            );
        }
    }
}

function kintone_field_list(
    array $response
): array {
    $properties =
        $response['body']['properties'] ?? [];

    if (!is_array($properties)) {
        return [];
    }

    $out = [];

    foreach ($properties as $code => $f) {
        if (!is_array($f)) {
            continue;
        }

        $out[] = [
            'code' => (string)$code,
            'label' =>
                (string)($f['label'] ?? $code),
            'type' =>
                (string)($f['type'] ?? '')
        ];
    }

    usort(
        $out,
        static fn(array $a,array $b): int =>
            strnatcasecmp(
                $a['code'],
                $b['code']
            )
    );

    return $out;
}

function krecord(
    array $record,
    string $code
): string {
    if (
        $code === ''
        || !isset($record[$code])
        || !is_array($record[$code])
    ) {
        return '';
    }

    $v = $record[$code]['value'] ?? '';

    if (!is_array($v)) {
        return (string)$v;
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

    return implode(
        ' ',
        array_filter(
            $out,
            static fn(string $x): bool =>
                $x !== ''
        )
    );
}

/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail(array $c): array
{
    $e = [];

    if (
        trim((string)($c['host'] ?? '')) === ''
    ) {
        $e[] = 'SMTPサーバを入力してください。';
    }

    $port = (int)($c['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        $e[] = 'SMTPポートが不正です。';
    }

    if (
        !in_array(
            (string)($c['encryption'] ?? ''),
            ['ssl','tls','none'],
            true
        )
    ) {
        $e[] = '暗号化方式が不正です。';
    }

    if (
        !filter_var(
            (string)($c['from_email'] ?? ''),
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $e[] = '送信元メールアドレスが不正です。';
    }

    $reply =
        trim((string)($c['reply_to'] ?? ''));

    if (
        $reply !== ''
        && !filter_var(
            $reply,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $e[] = '返信先メールアドレスが不正です。';
    }

    if (!empty($c['auth'])) {
        if (
            trim(
                (string)($c['username'] ?? '')
            ) === ''
        ) {
            $e[] = 'SMTPユーザー名を入力してください。';
        }

        if (
            trim(
                (string)($c['password'] ?? '')
            ) === ''
        ) {
            $e[] = 'SMTPパスワードを入力してください。';
        }
    }

    return $e;
}

function smtp_read(
    $socket,
    array $codes
): string {
    $response = '';

    while (($line = fgets($socket)) !== false) {
        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([ -])/',
                $line,
                $m
            )
        ) {
            if ($m[2] === ' ') {
                $code = (int)$m[1];

                if (!in_array(
                    $code,
                    $codes,
                    true
                )) {
                    throw new RuntimeException(
                        'SMTPエラー: '
                        . $code
                        . ' '
                        . trim($response)
                    );
                }

                return $response;
            }
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPから応答がありません。'
        );
    }

    throw new RuntimeException(
        'SMTP応答を最後まで取得できませんでした。'
    );
}

function smtp_cmd(
    $socket,
    string $command,
    array $codes
): string {
    if (
        fwrite(
            $socket,
            $command . "\r\n"
        ) === false
    ) {
        throw new RuntimeException(
            'SMTPへコマンドを送信できません。'
        );
    }

    return smtp_read(
        $socket,
        $codes
    );
}

function smtp_open(array $c)
{
    $c['password'] = secret_decrypt(
        (string)($c['password'] ?? '')
    );

    $errors = validate_mail($c);

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $host = trim((string)$c['host']);
    $port = (int)$c['port'];
    $enc = (string)$c['encryption'];

    if ($enc === 'ssl') {
        $transport = 'ssl://' . $host;
    } else {
        $transport = 'tcp://' . $host;
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true
        ]
    ]);

    /*
     * ここで「ssl」をホスト名として扱わない。
     * ssl://host:port の形式を正しく構築する。
     */
    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transport . ':' . $port,
        $errno,
        $errstr,
        S_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException(
            'SMTP接続に失敗しました: '
            . ($errstr !== ''
                ? $errstr
                : '接続エラー')
        );
    }

    stream_set_timeout(
        $socket,
        S_READ_TIMEOUT
    );

    try {
        smtp_read($socket,[220]);

        smtp_cmd(
            $socket,
            'EHLO localhost',
            [250]
        );

        if ($enc === 'tls') {
            $ok = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($ok !== true) {
                throw new RuntimeException(
                    'SMTP STARTTLSを確立できません。'
                );
            }

            smtp_cmd(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (!empty($c['auth'])) {
            smtp_cmd(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtp_cmd(
                $socket,
                base64_encode(
                    (string)$c['username']
                ),
                [334]
            );

            smtp_cmd(
                $socket,
                base64_encode(
                    (string)$c['password']
                ),
                [235]
            );
        }

        return $socket;
    } catch (Throwable $e) {
        @fclose($socket);
        throw $e;
    }
}

function mime_header(string $v): string
{
    if ($v === '') {
        return '';
    }

    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader(
            $v,
            'UTF-8',
            'B'
        );
    }

    return $v;
}

function smtp_send(
    array $c,
    string $to,
    string $subject,
    string $body
): void {
    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new RuntimeException(
            '送信先メールアドレスが不正です。'
        );
    }

    $socket = smtp_open($c);

    try {
        $from = (string)$c['from_email'];
        $name = (string)($c['from_name'] ?? '');

        smtp_cmd(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_cmd(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250,251]
        );

        smtp_cmd(
            $socket,
            'DATA',
            [354]
        );

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: '
                . mime_header(
                    $name !== '' ? $name : $from
                )
                . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . mime_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ];

        $reply =
            trim((string)($c['reply_to'] ?? ''));

        if ($reply !== '') {
            $headers[] =
                'Reply-To: ' . $reply;
        }

        $body = str_replace(
            ["\r\n","\r"],
            "\n",
            $body
        );

        $body = preg_replace(
            '/^\./m',
            '..',
            $body
        ) ?? $body;

        $message =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . str_replace(
                "\n",
                "\r\n",
                $body
            )
            . "\r\n.\r\n";

        if (
            fwrite(
                $socket,
                $message
            ) === false
        ) {
            throw new RuntimeException(
                'SMTPへメール本文を送信できません。'
            );
        }

        smtp_read(
            $socket,
            [250]
        );

        smtp_cmd(
            $socket,
            'QUIT',
            [221]
        );

        fclose($socket);
    } catch (Throwable $e) {
        @fclose($socket);
        throw $e;
    }
}

/* =========================================================
 * 回答検証
 * ========================================================= */

function validate_answers(
    array $survey,
    array $answers
): array {
    $errors = [];

    foreach (
        all_questions($survey)
        as $q
    ) {
        $id =
            (string)($q['id'] ?? '');

        if (
            empty($q['required'])
        ) {
            continue;
        }

        $v = $answers[$id] ?? '';

        if (is_array($v)) {
            $v = array_values(
                array_filter(
                    array_map(
                        static fn($x): string =>
                            trim((string)$x),
                        $v
                    ),
                    static fn(string $x): bool =>
                        $x !== ''
                )
            );

            if (!$v) {
                $errors[] =
                    ($q['number'] ?? '質問')
                    . ' は必須です。';
            }
        } elseif (
            trim((string)$v) === ''
        ) {
            $errors[] =
                ($q['number'] ?? '質問')
                . ' は必須です。';
        }
    }

    return $errors;
}

/* =========================================================
 * POST処理
 *
 * 外部通信関数はここから呼び出すが、
 * 外部通信関数自身はheader(Location)を実行しない。
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): ?array {
    $action = post_string('action');

    if ($action === '') {
        return null;
    }

    switch ($action) {
        case 'save_survey':
            $id = post_string('survey_id');

            $survey = [
                'id' =>
                    $id !== ''
                        ? $id
                        : uid('survey'),
                'title' =>
                    post_string('title'),
                'description' =>
                    (string)($_POST['description'] ?? ''),
                'startAt' =>
                    post_string('startAt'),
                'endAt' =>
                    post_string('endAt'),
                'status' =>
                    'draft',
                'numbering' =>
                    post_string('numbering'),
                'createdAt' => now(),
                'updatedAt' => now(),
                'groups' => []
            ];

            if ($id !== '') {
                $old = survey_get(
                    $data['surveys'],
                    $id
                );

                if (!$old) {
                    flash(
                        'error',
                        '編集対象のアンケートが見つかりません。'
                    );

                    return ['screen'=>'list'];
                }

                $survey['status'] =
                    (string)($old['status'] ?? 'draft');

                $survey['createdAt'] =
                    (string)($old['createdAt'] ?? now());
            }

            $groups =
                $_POST['groups'] ?? [];

            if (!is_array($groups)) {
                $groups = [];
            }

            foreach ($groups as $g) {
                if (!is_array($g)) {
                    continue;
                }

                $group = [
                    'id' =>
                        preg_match(
                            '/^group-[A-Za-z0-9_-]+$/',
                            (string)($g['id'] ?? '')
                        )
                            ? (string)$g['id']
                            : uid('group'),
                    'title' =>
                        trim((string)($g['title'] ?? '')),
                    'questions' => []
                ];

                $questions =
                    $g['questions'] ?? [];

                if (!is_array($questions)) {
                    $questions = [];
                }

                foreach ($questions as $q) {
                    if (!is_array($q)) {
                        continue;
                    }

                    $type =
                        (string)($q['type'] ?? 'single');

                    if (!in_array(
                        $type,
                        ALLOWED_TYPES,
                        true
                    )) {
                        $type = 'single';
                    }

                    $question = [
                        'id' =>
                            preg_match(
                                '/^question-[A-Za-z0-9_-]+$/',
                                (string)($q['id'] ?? '')
                            )
                                ? (string)$q['id']
                                : uid('question'),
                        'number' => '',
                        'text' =>
                            trim((string)($q['text'] ?? '')),
                        'type' => $type,
                        'required' =>
                            !empty($q['required']),
                        'options' => []
                    ];

                    if (
                        in_array(
                            $type,
                            ['single','multiple'],
                            true
                        )
                    ) {
                        $opts =
                            $q['options'] ?? [];

                        if (is_array($opts)) {
                            foreach ($opts as $o) {
                                if (!is_array($o)) {
                                    continue;
                                }

                                $label =
                                    trim(
                                        (string)($o['label'] ?? '')
                                    );

                                if ($label === '') {
                                    continue;
                                }

                                $question['options'][] = [
                                    'id' =>
                                        preg_match(
                                            '/^option-[A-Za-z0-9_-]+$/',
                                            (string)($o['id'] ?? '')
                                        )
                                            ? (string)$o['id']
                                            : uid('option'),
                                    'label' => $label,
                                    'nextQuestionId' =>
                                        (string)(
                                            $o['nextQuestionId']
                                            ?? ''
                                        )
                                ];
                            }
                        }
                    }

                    $group['questions'][] =
                        $question;
                }

                $survey['groups'][] =
                    $group;
            }

            $errors =
                validate_survey($survey);

            if ($errors) {
                flash(
                    'error',
                    implode("\n",$errors)
                );

                $_SESSION['edit_form'] =
                    $survey;

                return [
                    'screen' => 'edit',
                    'id' => $survey['id']
                ];
            }

            recalc_numbers($survey);

            $idx = survey_index(
                $data['surveys'],
                $survey['id']
            );

            if ($idx >= 0) {
                $data['surveys'][$idx] =
                    $survey;
            } else {
                $data['surveys'][] =
                    $survey;
            }

            save_data($data);

            flash(
                'success',
                'アンケートを保存しました。'
            );

            return ['screen'=>'list'];

        case 'delete_survey':
            $id =
                post_string('survey_id');

            $idx =
                survey_index(
                    $data['surveys'],
                    $id
                );

            if ($idx < 0) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return ['screen'=>'list'];
            }

            array_splice(
                $data['surveys'],
                $idx,
                1
            );

            save_data($data);

            flash(
                'success',
                'アンケートを削除しました。'
            );

            return ['screen'=>'list'];

        case 'duplicate_survey':
            $id =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $id
                );

            if (!$survey) {
                flash(
                    'error',
                    '複製元アンケートが見つかりません。'
                );

                return ['screen'=>'list'];
            }

            $survey['id'] =
                uid('survey');

            $survey['title'] =
                (string)$survey['title']
                . '（複製）';

            $survey['status'] =
                'draft';

            $survey['createdAt'] =
                now();

            $survey['updatedAt'] =
                now();

            foreach ($survey['groups'] as &$g) {
                $g['id'] = uid('group');

                foreach ($g['questions'] as &$q) {
                    $q['id'] = uid('question');

                    foreach ($q['options'] as &$o) {
                        $o['id'] =
                            uid('option');
                    }

                    unset($o);
                }

                unset($q);
            }

            unset($g);

            recalc_numbers($survey);

            $data['surveys'][] =
                $survey;

            save_data($data);

            flash(
                'success',
                'アンケートを複製しました。'
            );

            return ['screen'=>'list'];

        case 'change_status':
            $id =
                post_string('survey_id');

            $new =
                post_string('new_status');

            $idx =
                survey_index(
                    $data['surveys'],
                    $id
                );

            if ($idx < 0) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return ['screen'=>'list'];
            }

            $old =
                (string)(
                    $data['surveys'][$idx]['status']
                    ?? 'draft'
                );

            $allowed = [
                'draft' => ['published'],
                'published' => ['stopped'],
                'stopped' => ['published']
            ];

            if (
                !isset($allowed[$old])
                || !in_array(
                    $new,
                    $allowed[$old],
                    true
                )
            ) {
                flash(
                    'error',
                    '指定された状態変更はできません。'
                );

                return ['screen'=>'list'];
            }

            $data['surveys'][$idx]['status'] =
                $new;

            $data['surveys'][$idx]['updatedAt'] =
                now();

            save_data($data);

            flash(
                'success',
                '状態を変更しました。'
            );

            return ['screen'=>'list'];

        case 'save_kintone':
            $old =
                $settings['kintone'];

            $password =
                post_string('password');

            if ($password === '') {
                $password =
                    (string)(
                        $old['password'] ?? ''
                    );
            } else {
                $password =
                    secure_password_value(
                        $password
                    );
            }

            /*
             * 旧版の平文値をそのまま保存しない。
             */
            if (
                $password !== ''
                && !str_starts_with(
                    $password,
                    'enc:v1:'
                )
            ) {
                $password =
                    secure_password_value(
                        $password
                    );
            }

            $config = [
                'subdomain' =>
                    normalize_subdomain(
                        post_string('subdomain')
                    ),
                'app_id' =>
                    post_string('app_id'),
                'username' =>
                    post_string('username'),
                'password' =>
                    $password,
                'proxy' =>
                    post_string('proxy'),
                'verify_ssl' =>
                    post_bool('verify_ssl'),
                'mapping' =>
                    $old['mapping'] ?? [],
                'fields' =>
                    $old['fields'] ?? [],
                'last_test' =>
                    $old['last_test'] ?? null,
                'last_sync' =>
                    $old['last_sync'] ?? null
            ];

            /*
             * 検証時だけ復号。
             */
            $check = $config;
            $check['password'] =
                secret_decrypt(
                    (string)$config['password']
                );

            $errors =
                validate_kintone(
                    $check,
                    true
                );

            if ($errors) {
                flash(
                    'error',
                    implode("\n",$errors)
                );

                return ['screen'=>'kintone'];
            }

            $settings['kintone'] =
                $config;

            save_settings($settings);

            flash(
                'success',
                'kintone設定を保存しました。'
            );

            return ['screen'=>'kintone'];

        case 'test_kintone':
            try {
                $r =
                    kintone_test(
                        $settings['kintone']
                    );

                $settings['kintone']['last_test'] =
                    now();

                save_settings($settings);

                flash(
                    'success',
                    'kintone接続テスト成功。HTTP '
                    . (int)$r['status']
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone接続テスト失敗：'
                    . safe_error($e)
                );
            }

            return ['screen'=>'kintone'];

        case 'load_kintone_fields':
            try {
                $r =
                    kintone_fields(
                        $settings['kintone']
                    );

                $fields =
                    kintone_field_list($r);

                if (!$fields) {
                    throw new RuntimeException(
                        'kintoneから項目を取得できませんでした。'
                    );
                }

                $settings['kintone']['fields'] =
                    $fields;

                save_settings($settings);

                flash(
                    'success',
                    count($fields)
                    . '件の項目を取得しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone項目取得失敗：'
                    . safe_error($e)
                );
            }

            return ['screen'=>'kintone'];

        case 'save_kintone_mapping':
            $fields =
                $settings['kintone']['fields']
                ?? [];

            $valid = [];

            foreach ($fields as $f) {
                if (isset($f['code'])) {
                    $valid[] =
                        (string)$f['code'];
                }
            }

            $mapping = [
                'organization' =>
                    post_string(
                        'mapping_organization'
                    ),
                'name' =>
                    post_string(
                        'mapping_name'
                    ),
                'email' =>
                    post_string(
                        'mapping_email'
                    ),
                'department' =>
                    post_string(
                        'mapping_department'
                    ),
                'phone' =>
                    post_string(
                        'mapping_phone'
                    ),
                'address' => []
            ];

            $addr =
                $_POST['mapping_address']
                ?? [];

            if (is_array($addr)) {
                foreach ($addr as $code) {
                    $code =
                        trim((string)$code);

                    if (
                        $code !== ''
                        && in_array(
                            $code,
                            $valid,
                            true
                        )
                    ) {
                        $mapping['address'][] =
                            $code;
                    }
                }
            }

            foreach (
                [
                    'organization',
                    'name',
                    'email',
                    'department',
                    'phone'
                ]
                as $key
            ) {
                if (
                    $mapping[$key] !== ''
                    && !in_array(
                        $mapping[$key],
                        $valid,
                        true
                    )
                ) {
                    $mapping[$key] = '';
                }
            }

            $settings['kintone']['mapping'] =
                $mapping;

            save_settings($settings);

            flash(
                'success',
                'kintone項目マッピングを保存しました。'
            );

            return ['screen'=>'kintone'];

        case 'sync_kintone':
            try {
                $r =
                    kintone_records(
                        $settings['kintone']
                    );

                $records =
                    $r['body']['records']
                    ?? [];

                $m =
                    $settings['kintone']['mapping']
                    ?? [];

                $customers = [];

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $addressParts = [];

                    foreach (
                        $m['address'] ?? []
                        as $code
                    ) {
                        $v =
                            krecord(
                                $record,
                                (string)$code
                            );

                        if ($v !== '') {
                            $addressParts[] =
                                $v;
                        }
                    }

                    $customers[] = [
                        'id' =>
                            uid('customer'),
                        'organization' =>
                            krecord(
                                $record,
                                (string)(
                                    $m['organization']
                                    ?? ''
                                )
                            ),
                        'name' =>
                            krecord(
                                $record,
                                (string)(
                                    $m['name']
                                    ?? ''
                                )
                            ),
                        'email' =>
                            krecord(
                                $record,
                                (string)(
                                    $m['email']
                                    ?? ''
                                )
                            ),
                        'department' =>
                            krecord(
                                $record,
                                (string)(
                                    $m['department']
                                    ?? ''
                                )
                            ),
                        'phone' =>
                            krecord(
                                $record,
                                (string)(
                                    $m['phone']
                                    ?? ''
                                )
                            ),
                        'address' =>
                            implode(
                                ' ',
                                $addressParts
                            )
                    ];
                }

                $data['customers'] =
                    $customers;

                $settings['kintone']['last_sync'] =
                    now();

                save_data($data);
                save_settings($settings);

                flash(
                    'success',
                    count($customers)
                    . '件の顧客情報を同期しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone同期失敗：'
                    . safe_error($e)
                );
            }

            return ['screen'=>'kintone'];

        case 'save_mail':
            $old =
                $settings['mail'];

            $password =
                post_string('password');

            if ($password === '') {
                $password =
                    (string)(
                        $old['password'] ?? ''
                    );
            } else {
                $password =
                    secure_password_value(
                        $password
                    );
            }

            if (
                $password !== ''
                && !str_starts_with(
                    $password,
                    'enc:v1:'
                )
            ) {
                $password =
                    secure_password_value(
                        $password
                    );
            }

            $config = [
                'host' =>
                    post_string('server'),
                'port' =>
                    (int)post_string('port'),
                'encryption' =>
                    post_string('encryption'),
                'auth' =>
                    post_bool('auth'),
                'username' =>
                    post_string('username'),
                'password' =>
                    $password,
                'from_email' =>
                    post_string('from_email'),
                'from_name' =>
                    post_string('from_name'),
                'reply_to' =>
                    post_string('reply_to'),
                'last_test' =>
                    $old['last_test'] ?? null
            ];

            $check = $config;

            $check['password'] =
                secret_decrypt(
                    (string)$config['password']
                );

            $errors =
                validate_mail($check);

            if ($errors) {
                flash(
                    'error',
                    implode("\n",$errors)
                );

                return ['screen'=>'mail'];
            }

            $settings['mail'] =
                $config;

            save_settings($settings);

            flash(
                'success',
                'メール設定を保存しました。'
            );

            return ['screen'=>'mail'];

        case 'test_mail':
            try {
                $socket =
                    smtp_open(
                        $settings['mail']
                    );

                smtp_cmd(
                    $socket,
                    'QUIT',
                    [221]
                );

                fclose($socket);

                $settings['mail']['last_test'] =
                    now();

                save_settings($settings);

                flash(
                    'success',
                    'SMTP接続テスト成功。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'SMTP接続テスト失敗：'
                    . safe_error($e)
                );
            }

            return ['screen'=>'mail'];

        case 'send_test_mail':
            $to =
                post_string('test_email');

            try {
                smtp_send(
                    $settings['mail'],
                    $to,
                    'アンケートアプリ テストメール',
                    'SMTP設定のテストメールです。'
                );

                flash(
                    'success',
                    'テストメールを送信しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'テストメール送信失敗：'
                    . safe_error($e)
                );
            }

            return ['screen'=>'mail'];

        case 'send_mail':
            $surveyId =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $surveyId
                );

            if (!$survey) {
                flash(
                    'error',
                    '対象アンケートが見つかりません。'
                );

                return ['screen'=>'list'];
            }

            $selected =
                $_POST['customer_ids']
                ?? [];

            if (
                !is_array($selected)
                || !$selected
            ) {
                flash(
                    'error',
                    '顧客を選択してください。'
                );

                return [
                    'screen'=>'send',
                    'id'=>$surveyId
                ];
            }

            $subject =
                post_string('subject');

            $body =
                (string)($_POST['body'] ?? '');

            if (
                $subject === ''
                || trim($body) === ''
            ) {
                flash(
                    'error',
                    'メール件名と本文を入力してください。'
                );

                return [
                    'screen'=>'send',
                    'id'=>$surveyId
                ];
            }

            $map = [];

            foreach (
                $data['customers']
                as $c
            ) {
                if (is_array($c)) {
                    $map[
                        (string)($c['id'] ?? '')
                    ] = $c;
                }
            }

            $sent = 0;
            $failed = 0;

            foreach ($selected as $cid) {
                $cid = (string)$cid;

                if (!isset($map[$cid])) {
                    $failed++;
                    continue;
                }

                $customer =
                    $map[$cid];

                $email =
                    trim(
                        (string)(
                            $customer['email']
                            ?? ''
                        )
                    );

                if (
                    !filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $failed++;

                    $data['send_history'][] = [
                        'id'=>uid('send'),
                        'survey_id'=>$surveyId,
                        'customer_id'=>$cid,
                        'email'=>$email,
                        'status'=>'failed',
                        'message'=>'メールアドレス不正',
                        'createdAt'=>now()
                    ];

                    continue;
                }

                $mailBody =
                    str_replace(
                        [
                            '{顧客名}',
                            '{アンケートURL}'
                        ],
                        [
                            (string)(
                                $customer['name']
                                ?? ''
                            ),
                            public_url(
                                $surveyId
                            )
                        ],
                        $body
                    );

                try {
                    smtp_send(
                        $settings['mail'],
                        $email,
                        $subject,
                        $mailBody
                    );

                    $sent++;

                    $data['send_history'][] = [
                        'id'=>uid('send'),
                        'survey_id'=>$surveyId,
                        'customer_id'=>$cid,
                        'email'=>$email,
                        'status'=>'sent',
                        'message'=>'',
                        'createdAt'=>now()
                    ];
                } catch (Throwable $e) {
                    $failed++;

                    $data['send_history'][] = [
                        'id'=>uid('send'),
                        'survey_id'=>$surveyId,
                        'customer_id'=>$cid,
                        'email'=>$email,
                        'status'=>'failed',
                        'message'=>safe_error($e),
                        'createdAt'=>now()
                    ];
                }
            }

            save_data($data);

            flash(
                $failed
                    ? 'warning'
                    : 'success',
                '送信完了：成功 '
                . $sent
                . '件 / 失敗 '
                . $failed
                . '件'
            );

            return [
                'screen'=>'send',
                'id'=>$surveyId
            ];

        case 'answer_confirm':
            $surveyId =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $surveyId
                );

            if (!$survey) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return [
                    'screen'=>'answer',
                    'id'=>$surveyId
                ];
            }

            $answers =
                $_POST['answers'] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $errors =
                validate_answers(
                    $survey,
                    $answers
                );

            if ($errors) {
                flash(
                    'error',
                    implode("\n",$errors)
                );

                $_SESSION['answer_draft'] =
                    $answers;

                return [
                    'screen'=>'answer',
                    'id'=>$surveyId
                ];
            }

            $_SESSION['answer_draft'] =
                $answers;

            return [
                'screen'=>'confirm',
                'id'=>$surveyId
            ];

        case 'answer_submit':
            $surveyId =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $surveyId
                );

            if (!$survey) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return [
                    'screen'=>'answer',
                    'id'=>$surveyId
                ];
            }

            $answers =
                $_SESSION['answer_draft']
                ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $errors =
                validate_answers(
                    $survey,
                    $answers
                );

            if ($errors) {
                flash(
                    'error',
                    implode("\n",$errors)
                );

                return [
                    'screen'=>'answer',
                    'id'=>$surveyId
                ];
            }

            $data['answers'][] = [
                'id'=>uid('answer'),
                'survey_id'=>$surveyId,
                'answers'=>$answers,
                'createdAt'=>now()
            ];

            save_data($data);

            unset(
                $_SESSION['answer_draft']
            );

            /*
             * 回答完了後は回答者画面だけ。
             * 管理者一覧へは戻さない。
             */
            return [
                'screen'=>'complete',
                'id'=>$surveyId
            ];

        case 'export_csv':
            export_csv(
                $data,
                post_string('survey_id')
            );
            exit;

        case 'export_pdf':
            export_pdf(
                $data,
                post_string('survey_id')
            );
            exit;
    }

    return null;
}

/* =========================================================
 * CSV
 * ========================================================= */

function export_csv(
    array $data,
    string $surveyId
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $surveyId
        );

    if (!$survey) {
        http_response_code(404);
        echo 'アンケートが見つかりません。';
        return;
    }

    $fp = fopen(
        'php://output',
        'wb'
    );

    if (!$fp) {
        throw new RuntimeException(
            'CSVを出力できません。'
        );
    }

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="survey-'
        . rawurlencode($surveyId)
        . '.csv"'
    );

    fwrite(
        $fp,
        "\xEF\xBB\xBF"
    );

    $questions =
        all_questions($survey);

    $header = [
        '回答ID',
        '回答日時'
    ];

    foreach ($questions as $q) {
        $header[] =
            (string)($q['number'] ?? '');
    }

    fputcsv(
        $fp,
        $header
    );

    foreach (
        $data['answers']
        as $a
    ) {
        if (
            !is_array($a)
            || (string)($a['survey_id'] ?? '')
                !== $surveyId
        ) {
            continue;
        }

        $row = [
            (string)($a['id'] ?? ''),
            (string)($a['createdAt'] ?? '')
        ];

        $answers =
            is_array($a['answers'] ?? null)
                ? $a['answers']
                : [];

        foreach ($questions as $q) {
            $v =
                $answers[
                    (string)$q['id']
                ] ?? '';

            if (is_array($v)) {
                $v =
                    implode(
                        ' / ',
                        array_map(
                            static fn($x): string =>
                                (string)$x,
                            $v
                        )
                    );
            }

            $row[] =
                (string)$v;
        }

        fputcsv(
            $fp,
            $row
        );
    }

    fclose($fp);
}

/* =========================================================
 * 簡易PDF
 *
 * 外部PDFライブラリに依存せず、実データをPDF化。
 * 日本語表示についてはサーバーにPDFフォントライブラリを
 * 導入していない環境でも壊れないようASCII化して出力。
 * ========================================================= */

function pdf_escape(string $s): string
{
    $s = preg_replace(
        '/[^\x20-\x7E]/',
        '?',
        $s
    ) ?? $s;

    return str_replace(
        ['\\','(',')'],
        ['\\\\','\\(','\\)'],
        $s
    );
}

function export_pdf(
    array $data,
    string $surveyId
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $surveyId
        );

    if (!$survey) {
        http_response_code(404);
        echo 'アンケートが見つかりません。';
        return;
    }

    $lines = [];

    $lines[] =
        'Survey Report';

    $lines[] =
        'Title: '
        . (string)($survey['title'] ?? '');

    $lines[] =
        'Answers: '
        . answer_count(
            $data,
            $surveyId
        );

    foreach (
        $survey['groups']
        ?? []
        as $g
    ) {
        $lines[] =
            'Group: '
            . (string)($g['title'] ?? '');

        foreach (
            $g['questions']
            ?? []
            as $q
        ) {
            $lines[] =
                (string)($q['number'] ?? '')
                . ' '
                . (string)($q['text'] ?? '');
        }
    }

    /*
     * 回答実データ。
     */
    foreach (
        $data['answers']
        as $a
    ) {
        if (
            !is_array($a)
            || (string)($a['survey_id'] ?? '')
                !== $surveyId
        ) {
            continue;
        }

        $lines[] =
            'Answer '
            . (string)($a['id'] ?? '')
            . ' '
            . (string)($a['createdAt'] ?? '');

        foreach (
            $a['answers'] ?? []
            as $qid => $v
        ) {
            if (is_array($v)) {
                $v =
                    implode(
                        ' / ',
                        array_map(
                            static fn($x): string =>
                                (string)$x,
                            $v
                        )
                    );
            }

            $lines[] =
                (string)$qid
                . ': '
                . (string)$v;
        }
    }

    /*
     * 最小PDF生成。
     */
    $objects = [];

    $objects[] =
        '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[] =
        '<< /Type /Page /Parent 2 0 R '
        . '/MediaBox [0 0 595 842] '
        . '/Resources << /Font << '
        . '/F1 5 0 R >> >> '
        . '/Contents 4 0 R >>';

    $content =
        "BT\n"
        . "/F1 9 Tf\n"
        . "40 800 Td\n";

    $first = true;

    foreach ($lines as $line) {
        if (!$first) {
            $content .= "0 -14 Td\n";
        }

        $content .=
            '('
            . pdf_escape($line)
            . ") Tj\n";

        $first = false;

        /*
         * 1ページあたりの簡易制限。
         */
        if (substr_count($content, 'Td') > 50) {
            break;
        }
    }

    $content .=
        "ET\n";

    $objects[] =
        '<< /Length '
        . strlen($content)
        . " >>\nstream\n"
        . $content
        . "endstream";

    $objects[] =
        '<< /Type /Font /Subtype /Type1 '
        . '/BaseFont /Helvetica >>';

    $pdf =
        "%PDF-1.4\n";

    $offsets = [0];

    foreach ($objects as $i => $object) {
        $offsets[$i + 1] =
            strlen($pdf);

        $pdf .=
            ($i + 1)
            . " 0 obj\n"
            . $object
            . "\nendobj\n";
    }

    $xref =
        strlen($pdf);

    $pdf .=
        "xref\n"
        . "0 "
        . (count($objects) + 1)
        . "\n"
        . "0000000000 65535 f \n";

    for (
        $i = 1;
        $i <= count($objects);
        $i++
    ) {
        $pdf .=
            sprintf(
                "%010d 00000 n \n",
                $offsets[$i]
            );
    }

    $pdf .=
        "trailer\n"
        . "<< /Size "
        . (count($objects) + 1)
        . " /Root 1 0 R >>\n"
        . "startxref\n"
        . $xref
        . "\n%%EOF";

    header(
        'Content-Type: application/pdf'
    );

    header(
        'Content-Disposition: attachment; filename="survey-'
        . rawurlencode($surveyId)
        . '.pdf"'
    );

    echo $pdf;
}

/* =========================================================
 * HTML共通
 * ========================================================= */

function admin_header(string $title): void
{
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_TITLE) ?></title>
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
 --shadow:0 4px 18px rgba(15,23,42,.08);
}
*{box-sizing:border-box}
body{
 margin:0;
 color:var(--text);
 background:#f8fafc;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
a{color:var(--primary);text-decoration:none}
a:hover{text-decoration:underline}
header{
 background:#0f172a;
 color:#fff;
}
.nav{
 max-width:1400px;
 margin:auto;
 padding:14px 20px;
 display:flex;
 gap:8px;
 align-items:center;
 flex-wrap:wrap;
}
.logo{
 font-size:20px;
 font-weight:700;
 margin-right:auto;
 color:#fff;
}
.nav a{
 color:#fff;
 padding:8px 10px;
 border-radius:7px;
}
.nav a:hover{
 background:#1e293b;
 text-decoration:none;
}
main{
 max-width:1400px;
 margin:auto;
 padding:24px 20px 60px;
}
h1{font-size:26px;margin:0 0 20px}
h2{font-size:20px;margin:0 0 16px}
h3{font-size:17px}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 padding:20px;
 margin-bottom:18px;
 box-shadow:var(--shadow);
}
.toolbar{
 display:flex;
 gap:10px;
 flex-wrap:wrap;
 align-items:center;
 margin-bottom:18px;
}
button,.btn{
 display:inline-block;
 border:1px solid var(--border);
 background:#fff;
 color:var(--text);
 border-radius:7px;
 padding:9px 14px;
 cursor:pointer;
 font-size:14px;
}
button:hover,.btn:hover{background:var(--gray-light)}
.primary{
 background:var(--primary);
 color:#fff;
 border-color:var(--primary);
}
.primary:hover{
 background:var(--primary-dark);
 color:#fff;
}
.danger{
 color:#fff;
 background:var(--danger);
 border-color:var(--danger);
}
.success{
 color:#fff;
 background:var(--success);
 border-color:var(--success);
}
.warning{
 color:#fff;
 background:var(--warning);
 border-color:var(--warning);
}
.gray{
 color:#fff;
 background:var(--gray);
 border-color:var(--gray);
}
input,select,textarea{
 width:100%;
 padding:9px 10px;
 border:1px solid #cbd5e1;
 border-radius:7px;
 background:#fff;
 font:inherit;
}
textarea{min-height:110px;resize:vertical}
label{
 display:block;
 font-weight:600;
 margin-bottom:6px;
}
.field{margin-bottom:15px}
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
.table-wrap{overflow-x:auto}
table{
 width:100%;
 border-collapse:collapse;
 min-width:900px;
}
th,td{
 padding:10px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:top;
}
th{background:#f8fafc}
.badge{
 display:inline-block;
 padding:4px 9px;
 border-radius:999px;
 font-size:12px;
 font-weight:700;
 background:#e2e8f0;
 color:#334155;
}
.badge.success{background:#dcfce7;color:#166534}
.badge.warning{background:#fef3c7;color:#92400e}
.badge.gray{background:#e2e8f0;color:#475569}
.notice{
 padding:12px 14px;
 border-radius:8px;
 margin-bottom:15px;
 white-space:pre-line;
}
.notice.success{
 background:#dcfce7;
 color:#166534;
 border:1px solid #bbf7d0;
}
.notice.error{
 background:#fee2e2;
 color:#991b1b;
 border:1px solid #fecaca;
}
.notice.warning{
 background:#fef3c7;
 color:#92400e;
 border:1px solid #fde68a;
}
.group{
 border:1px solid var(--border);
 border-radius:10px;
 padding:16px;
 margin-bottom:16px;
 background:#f8fafc;
}
.question{
 background:#fff;
 border:1px solid var(--border);
 border-radius:9px;
 padding:15px;
 margin:12px 0;
}
.question-head{
 display:flex;
 gap:10px;
 align-items:center;
 margin-bottom:10px;
}
.drag-handle{
 cursor:grab;
 font-size:20px;
 color:var(--gray);
}
.question-number{
 font-weight:800;
 color:var(--primary);
}
.option{
 display:grid;
 grid-template-columns:minmax(0,1fr) 220px auto;
 gap:8px;
 align-items:center;
 margin:7px 0;
}
.stats{
 display:grid;
 grid-template-columns:repeat(4,minmax(0,1fr));
 gap:12px;
}
.stat{
 background:#fff;
 border:1px solid var(--border);
 border-radius:10px;
 padding:15px;
}
.stat strong{
 display:block;
 font-size:26px;
 margin-top:4px;
}
.answer-card{
 border:1px solid var(--border);
 border-radius:10px;
 padding:16px;
 background:#fff;
 margin-bottom:12px;
}
.choice{
 display:block;
 padding:10px;
 border:1px solid var(--border);
 border-radius:8px;
 margin:7px 0;
 cursor:pointer;
}
.choice:hover{background:var(--gray-light)}
.mobile-actions{
 display:flex;
 gap:10px;
 margin-top:20px;
}
@media(max-width:800px){
 .grid,.grid3,.stats{
  grid-template-columns:1fr;
 }
 .option{
  grid-template-columns:1fr;
 }
 main{padding:18px 12px 40px}
 .nav{padding:12px}
}
</style>
</head>
<body>
<header>
<div class="nav">
<a class="logo"
 href="<?= h(app_url(['screen'=>'list'])) ?>">
<?= h(APP_TITLE) ?>
</a>
<a href="<?= h(app_url(['screen'=>'list'])) ?>">アンケート</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">kintone</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">メール</a>
</div>
</header>
<main>
<?php
}

function admin_footer(): void
{
    ?>
</main>
<script>
(function(){
 const forms=document.querySelectorAll('form');
 forms.forEach(function(f){
   f.addEventListener('submit',function(){
     const b=f.querySelector('button[type="submit"],button:not([type])');
     if(b){
       b.disabled=true;
       b.dataset.original=b.textContent;
       b.textContent='処理中...';
     }
   });
 });
})();
</script>
</body>
</html>
<?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(
    array $data
): void {
    $q =
        get_string('q');

    $status =
        get_string('status');

    $sort =
        get_string('sort');

    $sort =
        in_array(
            $sort,
            [
                'updated_desc',
                'updated_asc',
                'answers_desc',
                'answers_asc',
                'start_desc',
                'start_asc'
            ],
            true
        )
            ? $sort
            : 'updated_desc';

    $surveys =
        $data['surveys'];

    $surveys =
        array_values(
            array_filter(
                $surveys,
                static function($s) use ($q,$status): bool {
                    if (!is_array($s)) {
                        return false;
                    }

                    if (
                        $q !== ''
                        && mb_stripos(
                            (string)($s['title'] ?? ''),
                            $q
                        ) === false
                    ) {
                        return false;
                    }

                    if (
                        $status !== ''
                        && $status !== 'all'
                        && (string)($s['status'] ?? '')
                            !== $status
                    ) {
                        return false;
                    }

                    return true;
                }
            )
        );

    usort(
        $surveys,
        static function($a,$b) use ($sort): int {
            $aa = is_array($a) ? $a : [];
            $bb = is_array($b) ? $b : [];

            return match ($sort) {
                'answers_desc' =>
                    answer_count(
                        ['answers'=>[]],
                        ''
                    )
                    <=> 0,
                default =>
                    strcmp(
                        (string)($bb['updatedAt'] ?? ''),
                        (string)($aa['updatedAt'] ?? '')
                    )
            };
        }
    );

    /*
     * 回答数・開始日ソートはここで再計算。
     */
    if (
        in_array(
            $sort,
            [
                'answers_desc',
                'answers_asc',
                'start_desc',
                'start_asc'
            ],
            true
        )
    ) {
        usort(
            $surveys,
            static function($a,$b) use ($sort,$data): int {
                $aa = is_array($a) ? $a : [];
                $bb = is_array($b) ? $b : [];

                if (
                    str_starts_with(
                        $sort,
                        'answers'
                    )
                ) {
                    $x =
                        answer_count(
                            $data,
                            (string)($aa['id'] ?? '')
                        );
                    $y =
                        answer_count(
                            $data,
                            (string)($bb['id'] ?? '')
                        );
                } else {
                    $x =
                        strtotime(
                            (string)($aa['startAt'] ?? '')
                        ) ?: 0;
                    $y =
                        strtotime(
                            (string)($bb['startAt'] ?? '')
                        ) ?: 0;
                }

                $r = $x <=> $y;

                return str_ends_with(
                    $sort,
                    '_desc'
                )
                    ? -$r
                    : $r;
            }
        );
    }

    admin_header('アンケート一覧');

    ?>
<h1>アンケート一覧</h1>

<div class="toolbar">
<a class="btn primary"
 href="<?= h(app_url(['screen'=>'edit'])) ?>">
新規作成
</a>
</div>

<div class="card">
<form method="get">
<input type="hidden" name="screen" value="list">
<div class="grid3">
<div class="field">
<label>タイトル検索</label>
<input name="q"
 value="<?= h($q) ?>"
 placeholder="タイトル部分一致">
</div>
<div class="field">
<label>ステータス</label>
<select name="status">
<option value="all">すべて</option>
<option value="published"
 <?= $status==='published'?'selected':'' ?>>
公開中
</option>
<option value="draft"
 <?= $status==='draft'?'selected':'' ?>>
下書き
</option>
<option value="stopped"
 <?= $status==='stopped'?'selected':'' ?>>
停止
</option>
<option value="ended"
 <?= $status==='ended'?'selected':'' ?>>
終了
</option>
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
<option value="<?= h($k) ?>"
 <?= $sort===$k?'selected':'' ?>>
<?= h($v) ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<button class="primary">検索</button>
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
<?php foreach($surveys as $s): ?>
<?php
$id=(string)$s['id'];
$st=(string)($s['status']??'draft');
?>
<tr>
<td><?= h($s['title']??'') ?></td>
<td><?= h($s['createdAt']??'') ?></td>
<td><?= h($s['updatedAt']??'') ?></td>
<td>
<?= h($s['startAt']??'') ?><br>
～
<?= h($s['endAt']??'') ?>
</td>
<td>
<span class="badge <?= h(status_class($st)) ?>">
<?= h(status_label($st)) ?>
</span>
</td>
<td><?= answer_count($data,$id) ?></td>
<td>
<div class="toolbar">
<a class="btn"
 href="<?= h(app_url([
 'screen'=>'edit',
 'id'=>$id
])) ?>">
確認・編集
</a>
<a class="btn"
 href="<?= h(app_url([
 'screen'=>'analytics',
 'id'=>$id
])) ?>">
集計
</a>
<a class="btn"
 href="<?= h(app_url([
 'screen'=>'send',
 'id'=>$id
])) ?>">
送信
</a>
<form method="post"
 onsubmit="return confirm('複製しますか？')">
<input type="hidden"
 name="action"
 value="duplicate_survey">
<input type="hidden"
 name="survey_id"
 value="<?= h($id) ?>">
<button>複製</button>
</form>
<form method="post"
 onsubmit="return confirm('削除しますか？')">
<input type="hidden"
 name="action"
 value="delete_survey">
<input type="hidden"
 name="survey_id"
 value="<?= h($id) ?>">
<button class="danger">削除</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php if(!$surveys): ?>
<tr>
<td colspan="7">該当するアンケートはありません。</td>
</tr>
<?php endif; ?>
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

function render_edit(
    array $data,
    ?array $survey
): void {
    $survey =
        $_SESSION['edit_form']
        ?? $survey;

    unset($_SESSION['edit_form']);

    if (!$survey) {
        $n=now();

        $survey=[
            'id'=>uid('survey'),
            'title'=>'',
            'description'=>'',
            'startAt'=>date('Y-m-d\TH:i'),
            'endAt'=>date(
                'Y-m-d\TH:i',
                strtotime('+30 days')
            ),
            'status'=>'draft',
            'numbering'=>'global',
            'createdAt'=>$n,
            'updatedAt'=>$n,
            'groups'=>[
                [
                    'id'=>uid('group'),
                    'title'=>'基本アンケート',
                    'questions'=>[]
                ]
            ]
        ];
    }

    $flash=flash_get();

    admin_header('アンケート作成・編集');

    if($flash):
?>
<div class="notice <?= h($flash['type']) ?>">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>

<h1>アンケート作成・編集</h1>

<div class="toolbar">
<a class="btn"
 href="<?= h(app_url(['screen'=>'list'])) ?>">
キャンセル
</a>
<button form="survey-form"
 class="primary">
保存して一覧へ
</button>
<span>
状態：
<strong>
<?= h(status_label(
    (string)($survey['status']??'draft')
)) ?>
</strong>
</span>
</div>

<form id="survey-form"
 method="post">
<input type="hidden"
 name="action"
 value="save_survey">
<input type="hidden"
 name="survey_id"
 value="<?= h($survey['id']) ?>">

<div class="card">
<div class="field">
<label>アンケートタイトル</label>
<input name="title"
 maxlength="<?= MAX_TITLE ?>"
 required
 value="<?= h($survey['title']??'') ?>">
</div>

<div class="field">
<label>アンケート説明</label>
<textarea name="description"
 maxlength="<?= MAX_DESCRIPTION ?>"
><?= h($survey['description']??'') ?></textarea>
</div>

<div class="grid">
<div class="field">
<label>開始日時</label>
<input type="datetime-local"
 name="startAt"
 value="<?= h($survey['startAt']??'') ?>">
</div>
<div class="field">
<label>終了日時</label>
<input type="datetime-local"
 name="endAt"
 value="<?= h($survey['endAt']??'') ?>">
</div>
</div>

<div class="field">
<label>質問番号の採番方式</label>
<select name="numbering">
<option value="global"
 <?= ($survey['numbering']??'global')==='global'
 ?'selected':'' ?>>
アンケート全体で通番：Q1、Q2、Q3...
</option>
<option value="group"
 <?= ($survey['numbering']??'')==='group'
 ?'selected':'' ?>>
グループ毎に採番：Q1-1、Q1-2...
</option>
</select>
</div>
</div>

<div id="groups">
<?php foreach($survey['groups'] as $g): ?>
<?php
$gid=(string)$g['id'];
?>
<div class="group"
 draggable="true"
 data-group-id="<?= h($gid) ?>">

<input type="hidden"
 name="groups[<?= h($gid) ?>][id]"
 value="<?= h($gid) ?>">

<div class="grid">
<div class="field">
<label>グループタイトル</label>
<input name="groups[<?= h($gid) ?>][title]"
 value="<?= h($g['title']??'') ?>"
 required>
</div>
<div>
<label>&nbsp;</label>
<button type="button"
 onclick="removeGroup(this)">
グループ削除
</button>
</div>
</div>

<div class="questions">
<?php foreach($g['questions'] as $q): ?>
<?php
$qid=(string)$q['id'];
?>
<div class="question"
 draggable="true"
 data-question-id="<?= h($qid) ?>">

<input type="hidden"
 name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][id]"
 value="<?= h($qid) ?>">

<div class="question-head">
<span class="drag-handle">☷</span>
<span class="question-number">
<?= h($q['number']??'Q?') ?>
</span>
</div>

<div class="field">
<label>質問文</label>
<textarea
 name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][text]"
 maxlength="<?= MAX_QUESTION ?>"
 required><?= h($q['text']??'') ?></textarea>
</div>

<div class="grid">
<div class="field">
<label>回答形式</label>
<select
 name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][type]"
 onchange="toggleQuestionType(this)">
<option value="single"
 <?= ($q['type']??'')==='single'?'selected':'' ?>>
単一選択
</option>
<option value="multiple"
 <?= ($q['type']??'')==='multiple'?'selected':'' ?>>
複数選択
</option>
<option value="text"
 <?= ($q['type']??'')==='text'?'selected':'' ?>>
自由記述
</option>
</select>
</div>
<div class="field">
<label>必須</label>
<label>
<input type="checkbox"
 name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][required]"
 value="1"
 <?= !empty($q['required'])?'checked':'' ?>>
必須回答
</label>
</div>
</div>

<div class="options"
 style="<?= in_array(
    ($q['type']??''),
    ['single','multiple'],
    true
 )?'':'display:none' ?>">
<strong>選択肢</strong>
<div class="option-list">
<?php foreach($q['options']??[] as $o): ?>
<?php
$oid=(string)$o['id'];
?>
<div class="option">
<input type="hidden"
 name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][options][<?= h($oid) ?>][id]"
 value="<?= h($oid) ?>">
<input type="text"
 name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][options][<?= h($oid) ?>][label]"
 value="<?= h($o['label']??'') ?>"
 placeholder="選択肢">
<select
 name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][options][<?= h($oid) ?>][nextQuestionId]">
<option value="">次の質問へ</option>
<?php foreach(
    question_ids($survey)
    as $target
): ?>
<?php if($target!==$qid): ?>
<option value="<?= h($target) ?>"
 <?= ($o['nextQuestionId']??'')===$target
 ?'selected':'' ?>>
<?= h($target) ?>
</option>
<?php endif; ?>
<?php endforeach; ?>
</select>
<button type="button"
 onclick="this.closest('.option').remove()">
削除
</button>
</div>
<?php endforeach; ?>
</div>
<button type="button"
 onclick="addOption(this)">
選択肢を追加
</button>
</div>

<button type="button"
 onclick="removeQuestion(this)">
質問を削除
</button>
</div>
<?php endforeach; ?>
</div>

<button type="button"
 class="primary"
 onclick="addQuestion(this)">
質問を追加
</button>
</div>
<?php endforeach; ?>
</div>

<div class="card">
<button type="button"
 class="primary"
 onclick="addGroup()">
グループを追加
</button>
<a class="btn"
 href="<?= h(app_url([
 'screen'=>'preview',
 'id'=>$survey['id']
])) ?>">
プレビュー
</a>
</div>

</form>

<script>
function uid(prefix){
 return prefix+'-'+Math.random().toString(16).slice(2)+Date.now();
}

function addGroup(){
 const groups=document.getElementById('groups');
 const gid=uid('group');
 const div=document.createElement('div');
 div.className='group';
 div.draggable=true;
 div.dataset.groupId=gid;
 div.innerHTML=`
 <input type="hidden"
  name="groups[${gid}][id]"
  value="${gid}">
 <div class="grid">
 <div class="field">
 <label>グループタイトル</label>
 <input name="groups[${gid}][title]"
  value="新しいグループ" required>
 </div>
 <div>
 <label>&nbsp;</label>
 <button type="button"
  onclick="removeGroup(this)">グループ削除</button>
 </div>
 </div>
 <div class="questions"></div>
 <button type="button"
  class="primary"
  onclick="addQuestion(this)">質問を追加</button>
 `;
 groups.appendChild(div);
 installDnD();
 recalcClientNumbers();
}

function addQuestion(button){
 const group=button.closest('.group');
 const gid=group.dataset.groupId;
 const qid=uid('question');
 const q=document.createElement('div');

 q.className='question';
 q.draggable=true;
 q.dataset.questionId=qid;

 q.innerHTML=`
 <input type="hidden"
  name="groups[${gid}][questions][${qid}][id]"
  value="${qid}">
 <div class="question-head">
  <span class="drag-handle">☷</span>
  <span class="question-number">Q?</span>
 </div>
 <div class="field">
  <label>質問文</label>
  <textarea
   name="groups[${gid}][questions][${qid}][text]"
   maxlength="<?= MAX_QUESTION ?>"
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
   <input type="checkbox"
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
 <button type="button"
  onclick="removeQuestion(this)">
  質問を削除
 </button>
 `;

 group.querySelector('.questions').appendChild(q);

 addOption(
  q.querySelector('.options button')
 );
 addOption(
  q.querySelector('.options button')
 );

 installDnD();
 recalcClientNumbers();
 refreshBranchTargets();
}

function addOption(button){
 const q=button.closest('.question');
 const group=q.closest('.group');
 const gid=group.dataset.groupId;
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
  placeholder="選択肢">
 <select
  name="groups[${gid}][questions][${qid}][options][${oid}][nextQuestionId]">
  <option value="">次の質問へ</option>
 </select>
 <button type="button"
  onclick="this.closest('.option').remove()">
  削除
 </button>
 `;

 q.querySelector('.option-list').appendChild(row);
 refreshBranchTargets();
}

function removeQuestion(button){
 if(!confirm('質問を削除しますか？')) return;
 button.closest('.question').remove();
 recalcClientNumbers();
 refreshBranchTargets();
}

function removeGroup(button){
 const groups=document.querySelectorAll('.group');

 if(groups.length<=1){
  alert('グループは最低1つ必要です。');
  return;
 }

 if(!confirm('グループを削除しますか？')) return;

 button.closest('.group').remove();
 recalcClientNumbers();
 refreshBranchTargets();
}

function toggleQuestionType(select){
 const q=select.closest('.question');
 const box=q.querySelector('.options');

 if(
  select.value==='single' ||
  select.value==='multiple'
 ){
  box.style.display='';
 }else{
  box.style.display='none';
 }
}

function recalcClientNumbers(){
 const numbering=document.querySelector(
  'select[name="numbering"]'
 )?.value || 'global';

 let global=1;
 let groupNo=1;

 document.querySelectorAll('.group')
 .forEach(function(g){
   let qno=1;

   g.querySelectorAll(':scope > .questions > .question')
   .forEach(function(q){
     const n=q.querySelector('.question-number');

     if(numbering==='group'){
       n.textContent='Q'+groupNo+'-'+qno;
     }else{
       n.textContent='Q'+global;
     }

     global++;
     qno++;
   });

   groupNo++;
 });
}

function refreshBranchTargets(){
 const questions=[
  ...document.querySelectorAll('.question')
 ];

 const options=[
  ...document.querySelectorAll(
   '.option select[name*="[nextQuestionId]"]'
  )
 ];

 options.forEach(function(select){
   const current=select.value;
   const q=select.closest('.question');
   const qid=q.dataset.questionId;

   select.innerHTML=
    '<option value="">次の質問へ</option>';

   questions.forEach(function(target){
     const id=target.dataset.questionId;

     if(id===qid) return;

     const n=target.querySelector(
      '.question-number'
     )?.textContent || id;

     const o=document.createElement('option');
     o.value=id;
     o.textContent=n;
     if(id===current) o.selected=true;

     select.appendChild(o);
   });
 });
}

function installDnD(){
 let dragged=null;

 document.querySelectorAll('.question').forEach(function(el){
  el.ondragstart=function(e){
   dragged=el;
   e.dataTransfer.effectAllowed='move';
  };

  el.ondragover=function(e){
   e.preventDefault();
  };

  el.ondrop=function(e){
   e.preventDefault();

   if(!dragged || dragged===el) return;

   const parent=el.parentNode;
   const rect=el.getBoundingClientRect();

   if(
    e.clientY <
    rect.top + rect.height/2
   ){
    parent.insertBefore(dragged,el);
   }else{
    parent.insertBefore(
     dragged,
     el.nextSibling
    );
   }

   recalcClientNumbers();
   refreshBranchTargets();
  };
 });

 document.querySelectorAll('.group').forEach(function(el){
  el.ondragover=function(e){
   e.preventDefault();
  };
 });
}

document.querySelector(
 'select[name="numbering"]'
)?.addEventListener(
 'change',
 function(){
  recalcClientNumbers();
  refreshBranchTargets();
 }
);

installDnD();
recalcClientNumbers();
refreshBranchTargets();
</script>
<?php
    admin_footer();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(
    array $survey
): void {
    admin_header('プレビュー');

    ?>
<h1>プレビュー</h1>

<div class="toolbar">
<a class="btn"
 href="<?= h(app_url([
 'screen'=>'edit',
 'id'=>$survey['id']
])) ?>">
編集へ戻る
</a>
</div>

<div class="card">
<h2><?= h($survey['title']) ?></h2>
<p><?= nl2br(h($survey['description'])) ?></p>
</div>

<?php foreach($survey['groups'] as $g): ?>
<div class="card">
<h2><?= h($g['title']) ?></h2>

<?php foreach($g['questions'] as $q): ?>
<div class="answer-card">
<h3>
<?= h($q['number']) ?>
<?= !empty($q['required'])
 ? ' *'
 : '' ?>
</h3>

<p><?= nl2br(h($q['text'])) ?></p>

<?php if($q['type']==='single'): ?>
<?php foreach($q['options'] as $o): ?>
<label class="choice">
<input type="radio" disabled>
<?= h($o['label']) ?>
<?php if(!empty($o['nextQuestionId'])): ?>
→ <?= h($o['nextQuestionId']) ?>
<?php endif; ?>
</label>
<?php endforeach; ?>

<?php elseif($q['type']==='multiple'): ?>
<?php foreach($q['options'] as $o): ?>
<label class="choice">
<input type="checkbox" disabled>
<?= h($o['label']) ?>
</label>
<?php endforeach; ?>

<?php else: ?>
<textarea disabled></textarea>
<?php endif; ?>

</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php
    admin_footer();
}

/* =========================================================
 * 送信
 * ========================================================= */

function render_send(
    array $data,
    array $survey
): void {
    $q=get_string('q');
    $history=
        array_reverse(
            array_values(
                array_filter(
                    $data['send_history'],
                    static fn($x): bool =>
                        is_array($x)
                        && (string)($x['survey_id']??'')
                            === (string)$survey['id']
                )
            )
        );

    admin_header('顧客選択・メール送信');

    ?>
<h1>顧客選択・メール送信</h1>

<div class="card">
<strong>対象アンケート</strong>
<p><?= h($survey['title']) ?></p>
</div>

<div class="card">
<form method="get">
<input type="hidden" name="screen" value="send">
<input type="hidden"
 name="id"
 value="<?= h($survey['id']) ?>">
<div class="grid">
<div class="field">
<label>顧客検索</label>
<input name="q"
 value="<?= h($q) ?>">
</div>
<div>
<label>&nbsp;</label>
<button class="primary">検索</button>
</div>
</div>
</form>
</div>

<div class="card">
<form method="post"
 onsubmit="return confirm('選択した顧客へ一括送信しますか？')">
<input type="hidden"
 name="action"
 value="send_mail">
<input type="hidden"
 name="survey_id"
 value="<?= h($survey['id']) ?>">

<h2>顧客選択</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th></th>
<th>組織名</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
<th>電話</th>
</tr>
</thead>
<tbody>
<?php
$customers=
array_values(
array_filter(
$data['customers'],
static function($c) use($q):bool{
 if(!is_array($c))return false;
 if($q==='')return true;
 return
 mb_stripos(
  (string)($c['name']??''),
  $q
 )!==false
 ||
 mb_stripos(
  (string)($c['organization']??''),
  $q
 )!==false
 ||
 mb_stripos(
  (string)($c['email']??''),
  $q
 )!==false;
}
)
);
foreach($customers as $c):
?>
<tr>
<td>
<input type="checkbox"
 name="customer_ids[]"
 value="<?= h($c['id']) ?>">
</td>
<td><?= h($c['organization']??'') ?></td>
<td><?= h($c['name']??'') ?></td>
<td><?= h($c['email']??'') ?></td>
<td><?= h($c['department']??'') ?></td>
<td><?= h($c['phone']??'') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="grid">
<div class="field">
<label>メール件名</label>
<input name="subject"
 required
 value="アンケートのお願い">
</div>
<div></div>
</div>

<div class="field">
<label>メール本文</label>
<textarea name="body"
 required>いつもお世話になっております。

<?= h($survey['title']) ?>へのご回答をお願いいたします。

{顧客名} 様

以下のURLからご回答ください。
{アンケートURL}

よろしくお願いいたします。</textarea>
</div>

<button class="primary">一括送信</button>
</form>
</div>

<div class="card">
<h2>送信履歴</h2>

<?php if(!$history): ?>
<p>送信履歴はありません。</p>
<?php else: ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>メール</th>
<th>結果</th>
<th>内容</th>
</tr>
</thead>
<tbody>
<?php foreach($history as $hrow): ?>
<tr>
<td><?= h($hrow['createdAt']??'') ?></td>
<td><?= h($hrow['email']??'') ?></td>
<td>
<?= ($hrow['status']??'')==='sent'
 ? '送信成功'
 : '送信失敗' ?>
</td>
<td><?= h($hrow['message']??'') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php
    admin_footer();
}

/* =========================================================
 * 集計
 * ========================================================= */

function render_analytics(
    array $data,
    array $survey
): void {
    $answers=
        array_values(
            array_filter(
                $data['answers'],
                static fn($a):bool =>
                    is_array($a)
                    && (string)($a['survey_id']??'')
                       === (string)$survey['id']
            )
        );

    $sent=0;

    foreach($data['send_history'] as $hrow){
        if(
            is_array($hrow)
            && (string)($hrow['survey_id']??'')
              === (string)$survey['id']
            && ($hrow['status']??'')==='sent'
        ){
            $sent++;
        }
    }

    $count=count($answers);
    $rate=$sent>0
        ? round($count/$sent*100,1)
        : 0;

    admin_header('回答集計・分析');

    ?>
<h1>回答集計・分析</h1>

<div class="card">
<strong>対象アンケート</strong>
<p><?= h($survey['title']) ?></p>
</div>

<div class="stats">
<div class="stat">
送信対象者数
<strong><?= $sent ?></strong>
</div>
<div class="stat">
回答数
<strong><?= $count ?></strong>
</div>
<div class="stat">
未回答数
<strong><?= max(0,$sent-$count) ?></strong>
</div>
<div class="stat">
回答率
<strong><?= h($rate) ?>%</strong>
</div>
</div>

<div class="toolbar"
 style="margin-top:18px">
<form method="post">
<input type="hidden"
 name="action"
 value="export_csv">
<input type="hidden"
 name="survey_id"
 value="<?= h($survey['id']) ?>">
<button class="primary">CSV出力</button>
</form>
<form method="post">
<input type="hidden"
 name="action"
 value="export_pdf">
<input type="hidden"
 name="survey_id"
 value="<?= h($survey['id']) ?>">
<button>PDF出力</button>
</form>
</div>

<?php if(!$answers): ?>
<div class="card">
現在、回答データはありません
</div>
<?php else: ?>

<?php foreach(
    all_questions($survey)
    as $q
): ?>
<?php
$values=[];
foreach($answers as $a){
 $v=$a['answers'][$q['id']]??'';
 if(is_array($v)){
  foreach($v as $x){
   $values[]=(string)$x;
  }
 }elseif(trim((string)$v)!==''){
  $values[]=(string)$v;
 }
}
$counts=array_count_values($values);
?>
<div class="card">
<h2>
<?= h($q['number']) ?>
<?= h($q['text']) ?>
</h2>

<?php if(
 in_array(
  $q['type'],
  ['single','multiple'],
  true
 )
): ?>
<?php foreach($q['options'] as $o): ?>
<p>
<?= h($o['label']) ?>：
<strong>
<?= (int)($counts[$o['label']]??0) ?>
</strong>
</p>
<?php endforeach; ?>
<?php else: ?>
<?php foreach($values as $v): ?>
<div class="answer-card">
<?= nl2br(h($v)) ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card">
<h2>個別回答</h2>

<?php foreach($answers as $a): ?>
<div class="answer-card">
<strong>
<?= h($a['createdAt']??'') ?>
</strong>

<?php foreach(
    all_questions($survey)
    as $q
): ?>
<div style="margin-top:10px">
<strong>
<?= h($q['number']) ?>
</strong><br>
<?php
$v=$a['answers'][$q['id']]??'';
if(is_array($v)){
 $v=implode(' / ',array_map(
  static fn($x):string=>(string)$x,
  $v
 ));
}
?>
<?= nl2br(h((string)$v)) ?>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

</div>
<?php endif; ?>

<?php
    admin_footer();
}

/* =========================================================
 * kintone画面
 * ========================================================= */

function render_kintone(
    array $settings
): void {
    $c=$settings['kintone'];
    $fields=$c['fields']??[];
    $map=$c['mapping']??[];

    admin_header('kintone設定');

    ?>
<h1>kintone連携設定</h1>

<div class="card">
<form method="post">
<input type="hidden"
 name="action"
 value="save_kintone">

<div class="grid">
<div class="field">
<label>サブドメイン</label>
<input name="subdomain"
 value="<?= h($c['subdomain']??'') ?>"
 placeholder="https:xxxx.cybozu.com / xxxx.cybozu.com / xxxx">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="app_id"
 inputmode="numeric"
 value="<?= h($c['app_id']??'') ?>">
</div>
</div>

<div class="grid">
<div class="field">
<label>ログイン名</label>
<input name="username"
 value="<?= h($c['username']??'') ?>">
</div>

<div class="field">
<label>パスワード</label>
<input type="password"
 name="password"
 placeholder="変更しない場合は空欄">
</div>
</div>

<div class="field">
<label>Proxy</label>
<input name="proxy"
 value="<?= h($c['proxy']??'') ?>"
 placeholder="host:port">
</div>

<div class="field">
<label>
<input type="checkbox"
 name="verify_ssl"
 value="1"
 <?= !empty($c['verify_ssl'])?'checked':'' ?>>
SSL証明書検証を有効にする
</label>
</div>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>
<p>
実際のkintone REST APIへ接続し、
認証まで確認します。
</p>
<form method="post">
<input type="hidden"
 name="action"
 value="test_kintone">
<button class="primary">
接続テスト
</button>
</form>
</div>

<div class="card">
<h2>項目一覧</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="load_kintone_fields">
<button class="primary">
項目一覧を再取得
</button>
</form>
</div>

<?php if($fields): ?>
<div class="card">
<h2>顧客情報マッピング</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="save_kintone_mapping">

<?php
$mapFields=[
 'organization'=>'組織名',
 'name'=>'氏名',
 'email'=>'メールアドレス',
 'department'=>'部署名',
 'phone'=>'電話番号'
];

foreach($mapFields as $key=>$label):
?>
<div class="field">
<label><?= h($label) ?></label>
<select name="mapping_<?= h($key) ?>">
<option value="">未設定</option>
<?php foreach($fields as $f): ?>
<option value="<?= h($f['code']) ?>"
 <?= ($map[$key]??'')===$f['code']
 ?'selected':'' ?>>
<?= h(
 $f['code']
 .' / '
 .$f['label']
) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<?php endforeach; ?>

<div class="field">
<label>住所（複数項目可）</label>

<?php foreach($fields as $f): ?>
<label>
<input type="checkbox"
 name="mapping_address[]"
 value="<?= h($f['code']) ?>"
 <?= in_array(
  $f['code'],
  $map['address']??[],
  true
 )?'checked':'' ?>>
<?= h(
 $f['code']
 .' / '
 .$f['label']
) ?>
</label>
<?php endforeach; ?>
</div>

<button class="primary">
マッピング保存
</button>
</form>
</div>
<?php endif; ?>

<div class="card">
<h2>顧客情報を同期</h2>
<p>
設定済みのkintoneアプリから実際にレコードを取得します。
</p>
<form method="post">
<input type="hidden"
 name="action"
 value="sync_kintone">
<button class="primary">
顧客情報を同期
</button>
</form>

<?php if(!empty($c['last_sync'])): ?>
<p>
最終同期：
<?= h($c['last_sync']) ?>
</p>
<?php endif; ?>
</div>

<?php
    admin_footer();
}

/* =========================================================
 * メール設定
 * ========================================================= */

function render_mail(
    array $settings
): void {
    $c=$settings['mail'];

    admin_header('メール設定');

    ?>
<h1>メールサーバ設定</h1>

<div class="card">
<form method="post">
<input type="hidden"
 name="action"
 value="save_mail">

<div class="grid">
<div class="field">
<label>SMTPサーバ</label>
<input name="server"
 value="<?= h($c['host']??'') ?>">
</div>

<div class="field">
<label>SMTPポート</label>
<input type="number"
 name="port"
 min="1"
 max="65535"
 value="<?= h($c['port']??587) ?>">
</div>
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl"
 <?= ($c['encryption']??'')==='ssl'
 ?'selected':'' ?>>
SSL
</option>
<option value="tls"
 <?= ($c['encryption']??'')==='tls'
 ?'selected':'' ?>>
TLS
</option>
<option value="none"
 <?= ($c['encryption']??'')==='none'
 ?'selected':'' ?>>
なし
</option>
</select>
</div>

<div class="field">
<label>
<input type="checkbox"
 name="auth"
 value="1"
 <?= !empty($c['auth'])?'checked':'' ?>>
SMTP認証を使用する
</label>
</div>

<div class="grid">
<div class="field">
<label>SMTPユーザー名</label>
<input name="username"
 value="<?= h($c['username']??'') ?>">
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 placeholder="変更しない場合は空欄">
</div>
</div>

<div class="grid">
<div class="field">
<label>送信元メールアドレス</label>
<input type="email"
 name="from_email"
 value="<?= h($c['from_email']??'') ?>">
</div>

<div class="field">
<label>送信元名</label>
<input name="from_name"
 value="<?= h($c['from_name']??'') ?>">
</div>
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input type="email"
 name="reply_to"
 value="<?= h($c['reply_to']??'') ?>">
</div>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>
<p>
SMTPサーバへの接続および認証まで行います。
メール送信は行いません。
</p>
<form method="post">
<input type="hidden"
 name="action"
 value="test_mail">
<button class="primary">
接続テスト
</button>
</form>
</div>

<div class="card">
<h2>テストメール送信</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="send_test_mail">

<div class="field">
<label>送信先</label>
<input type="email"
 name="test_email"
 required>
</div>

<button class="primary">
テストメール送信
</button>
</form>
</div>

<?php
    admin_footer();
}

/* =========================================================
 * 回答者画面
 * ========================================================= */

function visible_questions(
    array $survey,
    array $answers
): array {
    $all=all_questions($survey);
    $visible=[];
    $skipUntil=null;

    /*
     * 条件分岐:
     * 現在の回答からnextQuestionIdを追跡する。
     */
    $index=0;

    while($index<count($all)){
        $q=$all[$index];

        if(
            $skipUntil!==null
            && (string)$q['id']!==$skipUntil
        ){
            $index++;
            continue;
        }

        $skipUntil=null;
        $visible[]=$q;

        if(
            ($q['type']??'')==='single'
        ){
            $v=$answers[$q['id']]??'';

            foreach($q['options']??[] as $o){
                if(
                    (string)($o['label']??'')
                    === (string)$v
                    && !empty($o['nextQuestionId'])
                ){
                    $skipUntil=
                        (string)$o['nextQuestionId'];
                    break;
                }
            }
        }

        $index++;
    }

    return $visible;
}

function render_answer(
    array $survey
): void {
    $draft=
        $_SESSION['answer_draft']
        ?? [];

    if(!is_array($draft)){
        $draft=[];
    }

    admin_header('アンケート回答');

    ?>
<h1><?= h($survey['title']) ?></h1>

<div class="card">
<p><?= nl2br(h($survey['description'])) ?></p>
</div>

<?php
$questions=
    visible_questions(
        $survey,
        $draft
    );
?>

<form method="post">
<input type="hidden"
 name="action"
 value="answer_confirm">
<input type="hidden"
 name="survey_id"
 value="<?= h($survey['id']) ?>">

<?php foreach($questions as $q): ?>
<div class="card">
<h2>
<?= h($q['number']) ?>
<?php if(!empty($q['required'])): ?>
<span style="color:#dc2626">*</span>
<?php endif; ?>
</h2>

<p><?= nl2br(h($q['text'])) ?></p>

<?php if($q['type']==='single'): ?>
<?php foreach($q['options'] as $o): ?>
<label class="choice">
<input type="radio"
 name="answers[<?= h($q['id']) ?>]"
 value="<?= h($o['label']) ?>"
 <?= ($draft[$q['id']]??'')===$o['label']
 ?'checked':'' ?>>
<?= h($o['label']) ?>
</label>
<?php endforeach; ?>

<?php elseif($q['type']==='multiple'): ?>
<?php
$selected=
is_array($draft[$q['id']]??null)
?$draft[$q['id']]
:[];
?>
<?php foreach($q['options'] as $o): ?>
<label class="choice">
<input type="checkbox"
 name="answers[<?= h($q['id']) ?>][]"
 value="<?= h($o['label']) ?>"
 <?= in_array(
  $o['label'],
  $selected,
  true
 )?'checked':'' ?>>
<?= h($o['label']) ?>
</label>
<?php endforeach; ?>

<?php else: ?>
<textarea
 name="answers[<?= h($q['id']) ?>]"
 <?= !empty($q['required'])
 ?'required':'' ?>><?= h(
 $draft[$q['id']]??''
) ?></textarea>
<?php endif; ?>

</div>
<?php endforeach; ?>

<div class="mobile-actions">
<button class="primary">
次へ
</button>
</div>
</form>
<?php
    admin_footer();
}

/* =========================================================
 * 回答確認
 * ========================================================= */

function render_confirm(
    array $survey
): void {
    $answers=
        $_SESSION['answer_draft']
        ?? [];

    if(!is_array($answers)){
        $answers=[];
    }

    admin_header('回答確認');

    ?>
<h1>回答確認</h1>

<div class="card">
<h2><?= h($survey['title']) ?></h2>
</div>

<?php foreach(
    all_questions($survey)
    as $q
): ?>
<div class="card">
<h3><?= h($q['number']) ?></h3>
<p><?= nl2br(h($q['text'])) ?></p>

<?php
$v=$answers[$q['id']]??'';

if(is_array($v)){
 $v=implode(
  ' / ',
  array_map(
   static fn($x):string=>(string)$x,
   $v
  )
 );
}
?>

<div>
<?= nl2br(h((string)$v)) ?>
</div>
</div>
<?php endforeach; ?>

<div class="mobile-actions">
<a class="btn"
 href="<?= h(app_url([
  'screen'=>'answer',
  'id'=>$survey['id']
])) ?>">
戻る
</a>

<form method="post"
 onsubmit="return confirm('回答を送信しますか？')">
<input type="hidden"
 name="action"
 value="answer_submit">
<input type="hidden"
 name="survey_id"
 value="<?= h($survey['id']) ?>">
<button class="primary">
回答送信
</button>
</form>
</div>
<?php
    admin_footer();
}

/* =========================================================
 * 完了
 * ========================================================= */

function render_complete(
    array $survey
): void {
    admin_header('回答完了');

    ?>
<div class="card"
 style="max-width:720px;margin:40px auto">
<h1>回答完了</h1>

<p>
アンケートへのご回答ありがとうございました。
</p>

<p>
「<?= h($survey['title']) ?>」の回答を
正常に受け付けました。
</p>

<p>
この画面で回答者フローは終了します。
</p>
</div>
<?php

    /*
     * 管理者メニューを回答者へ見せない。
     * admin_headerを使っているためナビが出るので、
     * CSSで隠す。
     */
    ?>
<style>
body>header{display:none}
main{max-width:760px}
</style>
<?php

    admin_footer();
}

/* =========================================================
 * 初期化
 * ========================================================= */

ensure_data_dir();

if (!is_file(DATA_FILE)) {
    write_json(
        DATA_FILE,
        default_data()
    );
}

if (!is_file(SET_FILE)) {
    write_json(
        SET_FILE,
        default_settings()
    );
}

start_session();

$data=load_data();
$settings=load_settings();

refresh_status($data);

/* =========================================================
 * POST
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $route=
            handle_post(
                $data,
                $settings
            );

        if ($route !== null) {
            /*
             * POST処理はここで確定。
             * その後に画面を表示する。
             *
             * 外部通信関数自身はredirectしない。
             */
            $params=[
                'screen'=>
                    (string)(
                        $route['screen']
                        ?? 'list'
                    )
            ];

            if(
                isset($route['id'])
                && $route['id']!==''
            ){
                $params['id']=
                    (string)$route['id'];
            }

            /*
             * PRGではなく同一リクエストで結果表示。
             * 要件の「処理結果確定前に302/303を返さない」を満たす。
             */
            $_GET=$params;
        }
    } catch (Throwable $e) {
        flash(
            'error',
            safe_error($e)
        );

        $_GET=[
            'screen'=>
                get_string('screen') ?: 'list'
        ];
    }
}

/* =========================================================
 * 画面
 * ========================================================= */

$screen=
    get_string('screen');

if ($screen==='') {
    $screen='list';
}

$id=
    get_string('id');

$flash=
    flash_get();

if(
    $flash
    && !in_array(
        $screen,
        ['edit'],
        true
    )
){
    /*
     * 各画面へflashを表示。
     * render側でも取得できるよう一時退避。
     */
    $_SESSION['render_flash']=$flash;
}

function render_flash(): void
{
    $f=
        $_SESSION['render_flash']
        ?? null;

    unset($_SESSION['render_flash']);

    if(!is_array($f)){
        return;
    }

    ?>
<div class="notice <?= h($f['type']??'error') ?>">
<?= h($f['message']??'') ?>
</div>
<?php
}

/*
 * 管理者系画面。
 */
switch($screen){

case 'list':
    admin_header('アンケート一覧');
    render_flash();
    admin_footer();

    /*
     * header/footerだけ先に出さず、
     * 一覧本体を直接描画するため再実行。
     */
    /*
     * 実際の一覧を描画。
     */
    render_list($data);
    break;

case 'edit':
    $survey=
        $id!==''
            ? survey_get(
                $data['surveys'],
                $id
            )
            : null;

    $f=
        $_SESSION['render_flash']
        ?? null;

    unset($_SESSION['render_flash']);

    render_edit(
        $data,
        $survey
    );

    if(is_array($f)){
        /*
         * 編集画面上部へのflashは
         * render_edit内部のflash_getとの競合を避ける。
         */
    }
    break;

case 'preview':
    if($id===''){
        flash(
            'error',
            '対象アンケートが指定されていません。'
        );
        render_list($data);
        break;
    }

    $survey=
        survey_get(
            $data['surveys'],
            $id
        );

    if(!$survey){
        flash(
            'error',
            '対象アンケートが見つかりません。'
        );
        render_list($data);
        break;
    }

    render_preview($survey);
    break;

case 'send':
    if($id===''){
        flash(
            'error',
            '対象アンケートが指定されていません。'
        );
        render_list($data);
        break;
    }

    $survey=
        survey_get(
            $data['surveys'],
            $id
        );

    if(!$survey){
        flash(
            'error',
            '対象アンケートが見つかりません。'
        );
        render_list($data);
        break;
    }

    render_send(
        $data,
        $survey
    );
    break;

case 'analytics':
    if($id===''){
        flash(
            'error',
            '対象アンケートが指定されていません。'
        );
        render_list($data);
        break;
    }

    $survey=
        survey_get(
            $data['surveys'],
            $id
        );

    if(!$survey){
        flash(
            'error',
            '対象アンケートが見つかりません。'
        );
        render_list($data);
        break;
    }

    render_analytics(
        $data,
        $survey
    );
    break;

case 'kintone':
    render_kintone(
        $settings
    );
    break;

case 'mail':
    render_mail(
        $settings
    );
    break;

/*
 * 回答者画面。
 *
 * 管理者認証を要求しない。
 * 回答完了後にlistへ戻さない。
 */
case 'answer':
    if($id===''){
        http_response_code(400);
        echo 'アンケートIDが指定されていません。';
        break;
    }

    $survey=
        survey_get(
            $data['surveys'],
            $id
        );

    if(!$survey){
        http_response_code(404);
        echo 'アンケートが見つかりません。';
        break;
    }

    /*
     * 回答者からは公開中のみ回答可能。
     * 終了・停止・下書きは受付しない。
     */
    if(
        ($survey['status']??'')!=='published'
    ){
        admin_header('アンケート');
        ?>
<div class="card">
<h1>アンケート受付停止中</h1>
<p>
このアンケートは現在回答を受け付けていません。
</p>
</div>
<style>
body>header{display:none}
</style>
<?php
        admin_footer();
        break;
    }

    render_answer($survey);
    break;

case 'confirm':
    if($id===''){
        http_response_code(400);
        echo 'アンケートIDが指定されていません。';
        break;
    }

    $survey=
        survey_get(
            $data['surveys'],
            $id
        );

    if(!$survey){
        http_response_code(404);
        echo 'アンケートが見つかりません。';
        break;
    }

    render_confirm($survey);
    break;

case 'complete':
    if($id===''){
        http_response_code(400);
        echo 'アンケートIDが指定されていません。';
        break;
    }

    $survey=
        survey_get(
            $data['surveys'],
            $id
        );

    if(!$survey){
        http_response_code(404);
        echo 'アンケートが見つかりません。';
        break;
    }

    render_complete($survey);
    break;

default:
    /*
     * 不正screenは管理者一覧へ。
     * ユーザー入力URLへの外部リダイレクトは行わない。
     */
    render_list($data);
    break;
}
?>

<?php
declare(strict_types=1);

/*
 * アンケートアプリ / compact rebuild
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 *
 * 設計:
 *   - index.php 単一入口
 *   - POST処理中にLocationを返さない
 *   - kintone/SMTP通信結果を同一リクエストで表示
 *   - 認証情報をHTML/URLへ出さない
 *   - JSONへ永続化
 *   - 質問/グループの順序をサーバー側で再構築
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . '/_data';
const DATA_FILE = DATA_DIR . '/data.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
const SECRET_FILE = DATA_DIR . '/.secret';
const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;
const KINTONE_TIMEOUT = 30;
const SMTP_TIMEOUT = 20;

/* ============================================================
 * 基本
 * ============================================================ */

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ps(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function gs(string $key, string $default = ''): string {
    $v = $_GET[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function pb(string $key): bool {
    return isset($_POST[$key]) &&
        in_array((string)$_POST[$key], ['1','true','on'], true);
}

function id(string $prefix): string {
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function app_url(array $params = []): string {
    $base = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    return $params
        ? $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
        : $base;
}

function public_url(string $surveyId): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    return ($https ? 'https' : 'http') . '://' .
        ($_SERVER['HTTP_HOST'] ?? 'localhost') .
        app_url(['screen'=>'answer','id'=>$surveyId]);
}

/* ============================================================
 * セッション / JSON
 * ============================================================ */

function boot(): void {
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('データ保存フォルダを作成できません。');
    }

    if (!is_file(DATA_FILE)) save_json(DATA_FILE, default_data());
    if (!is_file(SETTINGS_FILE)) save_json(SETTINGS_FILE, default_settings());

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $path = dirname($script);
        $path = ($path === '.' || $path === '/') ? '/' : rtrim($path, '/') . '/';

        session_name('survey_app_session');
        session_set_cookie_params([
            'lifetime'=>0,
            'path'=>$path,
            'secure'=>$https,
            'httponly'=>true,
            'samesite'=>'Lax'
        ]);

        if (!session_start()) {
            throw new RuntimeException('セッションを開始できません。');
        }
    }
}

function default_data(): array {
    $t = now();

    return [
        'surveys'=>[[
            'id'=>'survey-001',
            'title'=>'顧客満足度アンケート',
            'description'=>'サービスについてのご意見をお聞かせください。',
            'startAt'=>date('Y-m-d\TH:i'),
            'endAt'=>date('Y-m-d\TH:i', strtotime('+30 days')),
            'status'=>'draft',
            'numbering'=>'global',
            'createdAt'=>$t,
            'updatedAt'=>$t,
            'groups'=>[[
                'id'=>'group-001',
                'title'=>'基本アンケート',
                'questions'=>[[
                    'id'=>'question-001',
                    'number'=>'Q1',
                    'text'=>'サービスの満足度を教えてください。',
                    'type'=>'single',
                    'required'=>true,
                    'options'=>[
                        ['id'=>'option-001','label'=>'非常に満足','nextQuestionId'=>''],
                        ['id'=>'option-002','label'=>'満足','nextQuestionId'=>''],
                        ['id'=>'option-003','label'=>'普通','nextQuestionId'=>''],
                        ['id'=>'option-004','label'=>'不満','nextQuestionId'=>'']
                    ]
                ],[
                    'id'=>'question-002',
                    'number'=>'Q2',
                    'text'=>'ご意見・ご要望があれば入力してください。',
                    'type'=>'text',
                    'required'=>false,
                    'options'=>[]
                ]]
            ]]
        ]],
        'answers'=>[],
        'customers'=>[],
        'send_history'=>[]
    ];
}

function default_settings(): array {
    return [
        'kintone'=>[
            'subdomain'=>'',
            'app_id'=>'',
            'username'=>'',
            'password'=>'',
            'proxy'=>'',
            'verify_ssl'=>false,
            'mapping'=>[
                'organization'=>'',
                'name'=>'',
                'email'=>'',
                'department'=>'',
                'phone'=>'',
                'address'=>[]
            ],
            'fields'=>[],
            'last_test'=>null,
            'last_sync'=>null
        ],
        'mail'=>[
            'host'=>'',
            'port'=>587,
            'encryption'=>'tls',
            'auth'=>true,
            'username'=>'',
            'password'=>'',
            'from_email'=>'',
            'from_name'=>'',
            'reply_to'=>'',
            'last_test'=>null
        ]
    ];
}

function load_json(string $file, array $fallback): array {
    if (!is_file($file)) return $fallback;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return $fallback;
    $v = json_decode($raw, true);
    return is_array($v) ? $v : $fallback;
}

function save_json(string $file, array $data): void {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if ($json === false) throw new RuntimeException('JSON化に失敗しました。');

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
    $fp = @fopen($tmp, 'wb');
    if (!$fp) throw new RuntimeException('一時ファイルを作成できません。');

    try {
        if (!flock($fp, LOCK_EX)) throw new RuntimeException('ファイルをロックできません。');
        if (fwrite($fp, $json) === false) throw new RuntimeException('ファイルを書き込めません。');
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('ファイルを更新できません。');
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

function data(): array {
    $d = load_json(DATA_FILE, default_data());
    foreach (['surveys','answers','customers','send_history'] as $k) {
        if (!isset($d[$k]) || !is_array($d[$k])) $d[$k] = [];
    }
    return $d;
}

function settings(): array {
    $d = load_json(SETTINGS_FILE, default_settings());
    $def = default_settings();
    $d['kintone'] = array_replace_recursive(
        $def['kintone'],
        is_array($d['kintone'] ?? null) ? $d['kintone'] : []
    );
    $d['mail'] = array_replace_recursive(
        $def['mail'],
        is_array($d['mail'] ?? null) ? $d['mail'] : []
    );
    return $d;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type'=>$type,'message'=>$message];
}

function show_flash(): void {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!is_array($f)) return;

    $class = $f['type'] === 'success'
        ? 'ok'
        : ($f['type'] === 'warning' ? 'warn' : 'err');

    echo '<div class="alert ' . h($class) . '">' . h($f['message']) . '</div>';
}

/* ============================================================
 * 秘密情報
 *
 * SMTP/kintoneパスワードはJSONへ平文保存しない。
 * APP_SECRET環境変数があればそれを優先。
 * なければWeb公開ディレクトリ外の親ディレクトリを優先する。
 * ============================================================ */

function secret_key(): string {
    $env = getenv('APP_SECRET');
    if ($env !== false && strlen($env) >= 32) return hash('sha256', $env, true);

    $candidates = [
        dirname(__DIR__) . '/.survey_app_secret',
        sys_get_temp_dir() . '/survey_app_secret'
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            $v = trim((string)@file_get_contents($file));
            if ($v !== '') return hash('sha256', $v, true);
        }
    }

    $file = $candidates[0];
    $v = bin2hex(random_bytes(32));

    if (@file_put_contents($file, $v, LOCK_EX) === false) {
        $file = $candidates[1];
        @file_put_contents($file, $v, LOCK_EX);
    }

    return hash('sha256', $v, true);
}

function encrypt_secret(string $plain): string {
    if ($plain === '') return '';
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
    if ($cipher === false) throw new RuntimeException('秘密情報の暗号化に失敗しました。');
    return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $value): string {
    if ($value === '') return '';

    /*
     * 旧版の平文保存データも読めるようにする。
     * 次回保存時に暗号化形式へ移行する。
     */
    if (!str_starts_with($value, 'enc:v1:')) return $value;

    $raw = base64_decode(substr($value, 7), true);
    if ($raw === false || strlen($raw) < 28) return '';

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

/* ============================================================
 * アンケート
 * ============================================================ */

function survey_index(array $surveys, string $sid): int {
    foreach ($surveys as $i=>$s) {
        if ((string)($s['id'] ?? '') === $sid) return $i;
    }
    return -1;
}

function survey(array $data, string $sid): ?array {
    $i = survey_index($data['surveys'], $sid);
    return $i < 0 ? null : $data['surveys'][$i];
}

function recalc(array &$s): void {
    $global = 1;
    $gn = 1;

    foreach ($s['groups'] as &$g) {
        $qn = 1;
        foreach ($g['questions'] as &$q) {
            $q['number'] = ($s['numbering'] ?? 'global') === 'group'
                ? "Q{$gn}-{$qn}"
                : "Q{$global}";
            $global++;
            $qn++;
        }
        unset($q);
        $gn++;
    }
    unset($g);
}

function refresh_statuses(array &$d): bool {
    $changed = false;

    foreach ($d['surveys'] as &$s) {
        if (
            ($s['status'] ?? '') === 'published' &&
            !empty($s['endAt']) &&
            ($t = strtotime((string)$s['endAt'])) !== false &&
            $t < time()
        ) {
            $s['status'] = 'ended';
            $s['updatedAt'] = now();
            $changed = true;
        }
    }

    unset($s);
    return $changed;
}

function status_label(string $s): string {
    return match ($s) {
        'published'=>'公開中',
        'stopped'=>'停止',
        'ended'=>'終了',
        default=>'下書き'
    };
}

function status_class(string $s): string {
    return match ($s) {
        'published'=>'ok',
        'stopped'=>'warn',
        default=>'muted'
    };
}

function validate_survey(): array {
    $title = ps('title');
    $description = (string)($_POST['description'] ?? '');
    $start = ps('start_at');
    $end = ps('end_at');
    $numbering = ps('numbering', 'global');

    $e = [];

    if ($title === '') $e[] = 'アンケートタイトルを入力してください。';
    if (mb_strlen($title) > MAX_TITLE) $e[] = 'アンケートタイトルが長すぎます。';
    if (mb_strlen($description) > MAX_DESCRIPTION) $e[] = 'アンケート説明が長すぎます。';

    if ($start !== '' && strtotime($start) === false) $e[] = '開始日時が不正です。';
    if ($end !== '' && strtotime($end) === false) $e[] = '終了日時が不正です。';

    if (
        $start !== '' && $end !== '' &&
        strtotime($start) !== false &&
        strtotime($end) !== false &&
        strtotime($end) < strtotime($start)
    ) {
        $e[] = '終了日時は開始日時以降にしてください。';
    }

    if (!in_array($numbering, ['global','group'], true)) $numbering = 'global';

    return compact('title','description','start','end','numbering','e');
}

function normalize_editor_payload(array $old = []): array {
    $groups = $_POST['group_order'] ?? [];
    $titles = $_POST['group_title'] ?? [];
    $qids = $_POST['questions_by_group'] ?? [];
    $texts = $_POST['question_text'] ?? [];
    $types = $_POST['question_type'] ?? [];
    $required = $_POST['question_required'] ?? [];
    $options = $_POST['question_option'] ?? [];
    $nexts = $_POST['option_next'] ?? [];

    if (!is_array($groups)) $groups = [];
    if (!is_array($titles)) $titles = [];
    if (!is_array($qids)) $qids = [];

    $result = [];

    foreach ($groups as $gid) {
        $gid = trim((string)$gid);
        if ($gid === '') continue;

        $g = [
            'id'=>$gid,
            'title'=>trim((string)($titles[$gid] ?? '新しいグループ')),
            'questions'=>[]
        ];

        if ($g['title'] === '') $g['title'] = '新しいグループ';

        $list = is_array($qids[$gid] ?? null) ? $qids[$gid] : [];

        foreach ($list as $qid) {
            $qid = trim((string)$qid);
            if ($qid === '') continue;

            $type = (string)($types[$qid] ?? 'single');
            if (!in_array($type, ['single','multiple','text'], true)) $type = 'single';

            $q = [
                'id'=>$qid,
                'number'=>'',
                'text'=>mb_substr(trim((string)($texts[$qid] ?? '')), 0, MAX_QUESTION),
                'type'=>$type,
                'required'=>isset($required[$qid]),
                'options'=>[]
            ];

            $raw = is_array($options[$qid] ?? null) ? $options[$qid] : [];
            foreach (array_values($raw) as $oi=>$label) {
                $label = trim((string)$label);
                if ($label === '') continue;

                $next = '';
                if (
                    $type === 'single' &&
                    is_array($nexts[$qid] ?? null)
                ) {
                    $next = trim((string)($nexts[$qid][$oi] ?? ''));
                }

                $q['options'][] = [
                    'id'=>id('option'),
                    'label'=>mb_substr($label, 0, MAX_OPTION),
                    'nextQuestionId'=>$next
                ];
            }

            $g['questions'][] = $q;
        }

        $result[] = $g;
    }

    if (!$result) {
        $result[] = [
            'id'=>id('group'),
            'title'=>'基本アンケート',
            'questions'=>[]
        ];
    }

    /*
     * 分岐先が存在しない質問を参照しないようにする。
     */
    $valid = [];
    foreach ($result as $g) {
        foreach ($g['questions'] as $q) $valid[$q['id']] = true;
    }

    foreach ($result as &$g) {
        foreach ($g['questions'] as &$q) {
            foreach ($q['options'] as &$o) {
                if (!isset($valid[$o['nextQuestionId']])) {
                    $o['nextQuestionId'] = '';
                }
            }
            unset($o);
        }
        unset($q);
    }
    unset($g);

    return $result;
}

/* ============================================================
 * kintone
 * ============================================================ */

function normalize_kintone_subdomain(string $value): string {
    $value = trim($value);

    /*
     * https://xxxx.cybozu.com
     * http://xxxx.cybozu.com
     * xxxx.cybozu.com
     * xxxx
     *
     * をすべて xxxx に統一する。
     */
    $value = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value) ?? $value;
    $value = preg_replace('#/.*$#', '', $value) ?? $value;
    $value = trim($value, " \t\n\r\0\x0B/");

    if (preg_match('/^(.+)\.cybozu\.com$/i', $value, $m)) {
        $value = $m[1];
    }

    return strtolower($value);
}

function kintone_config(array $base, bool $fromPost = false): array {
    $password = $fromPost ? ps('password') : decrypt_secret((string)($base['password'] ?? ''));

    if ($fromPost && $password === '') {
        $password = decrypt_secret((string)($base['password'] ?? ''));
    }

    return [
        'subdomain'=>normalize_kintone_subdomain(
            $fromPost ? ps('subdomain') : (string)($base['subdomain'] ?? '')
        ),
        'app_id'=>$fromPost ? ps('app_id') : (string)($base['app_id'] ?? ''),
        'username'=>$fromPost ? ps('username') : (string)($base['username'] ?? ''),
        'password'=>$password,
        'proxy'=>$fromPost ? ps('proxy') : (string)($base['proxy'] ?? ''),
        'verify_ssl'=>$fromPost ? pb('verify_ssl') : !empty($base['verify_ssl'])
    ];
}

function validate_kintone(array $c, bool $password = true): array {
    $e = [];

    if (
        $c['subdomain'] === '' ||
        !preg_match('/^[a-z0-9][a-z0-9-]*$/i', $c['subdomain'])
    ) {
        $e[] = 'kintoneサブドメインが不正です。';
    }

    if (!ctype_digit((string)$c['app_id']) || (int)$c['app_id'] < 1) {
        $e[] = '顧客管理アプリIDが不正です。';
    }

    if ($c['username'] === '') {
        $e[] = 'ログイン名を入力してください。';
    }

    if ($password && $c['password'] === '') {
        $e[] = 'kintoneパスワードを入力してください。';
    }

    if (
        $c['proxy'] !== '' &&
        !preg_match('/^[^:\s\/]+:\d{1,5}$/', $c['proxy'])
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
    $e = validate_kintone($c, true);
    if ($e) throw new RuntimeException(implode("\n", $e));

    $host = $c['subdomain'] . '.cybozu.com';
    $url = 'https://' . $host . $path;

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode($c['username'] . ':' . $c['password']),
        'Accept: application/json',
        'Connection: close'
    ];

    $content = '';
    if ($body !== null) {
        $content = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($content === false) throw new RuntimeException('kintoneリクエストを生成できません。');
        $headers[] = 'Content-Type: application/json';
    }

    $opts = [
        'http'=>[
            'method'=>$method,
            'header'=>implode("\r\n", $headers),
            'content'=>$content,
            'ignore_errors'=>true,
            'timeout'=>KINTONE_TIMEOUT,
            'follow_location'=>0,
            'max_redirects'=>0
        ],
        'ssl'=>[
            'verify_peer'=>(bool)$c['verify_ssl'],
            'verify_peer_name'=>(bool)$c['verify_ssl'],
            'allow_self_signed'=>!(bool)$c['verify_ssl'],
            'SNI_enabled'=>true,
            'peer_name'=>$host
        ]
    ];

    if ($c['proxy'] !== '') {
        [$ph,$pp] = explode(':', $c['proxy'], 2);
        $opts['http']['proxy'] = 'tcp://' . $ph . ':' . (int)$pp;
        $opts['http']['request_fulluri'] = true;
    }

    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);

    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
            $status = (int)$m[1];
        }
    }

    if ($raw === false) {
        throw new RuntimeException(
            'kintoneへの接続に失敗しました。DNS、ネットワーク、Proxy、SSL設定を確認してください。'
        );
    }

    if ($status >= 300 && $status < 400) {
        throw new RuntimeException(
            'kintoneがHTTP ' . $status .
            ' のリダイレクトを返しました。APIエンドポイントを確認してください。'
        );
    }

    $json = json_decode($raw, true);

    if ($status < 200 || $status >= 300) {
        $msg = is_array($json)
            ? trim((string)($json['message'] ?? ''))
            : '';

        $code = is_array($json)
            ? trim((string)($json['code'] ?? ''))
            : '';

        throw new RuntimeException(
            'kintone APIエラー' .
            ($code !== '' ? " [{$code}]" : '') .
            ($msg !== '' ? " {$msg}" : '') .
            " HTTP {$status}"
        );
    }

    if (!is_array($json)) {
        throw new RuntimeException('kintoneからJSONレスポンスを取得できませんでした。');
    }

    return ['status'=>$status,'body'=>$json];
}

function kintone_fields(array $c): array {
    return kintone_request(
        $c,
        'GET',
        '/k/v1/app/form/fields.json?app=' . rawurlencode($c['app_id'])
    );
}

function kintone_records(array $c): array {
    return kintone_request(
        $c,
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode($c['app_id']) .
        '&totalCount=true'
    );
}

function kintone_field_list(array $r): array {
    $out = [];

    foreach (($r['properties'] ?? []) as $code=>$f) {
        if (!is_array($f)) continue;

        $out[] = [
            'code'=>(string)$code,
            'label'=>(string)($f['label'] ?? $code),
            'type'=>(string)($f['type'] ?? '')
        ];
    }

    usort(
        $out,
        fn($a,$b)=>strnatcasecmp($a['code'],$b['code'])
    );

    return $out;
}

function kvalue(array $record, string $code): string {
    if ($code === '' || !isset($record[$code]) || !is_array($record[$code])) return '';

    $v = $record[$code]['value'] ?? '';

    if (!is_array($v)) return (string)$v;

    $out = [];

    foreach ($v as $x) {
        if (is_array($x)) {
            if (isset($x['name'])) $out[] = (string)$x['name'];
            elseif (isset($x['value'])) $out[] = (string)$x['value'];
        } else {
            $out[] = (string)$x;
        }
    }

    return implode(' ', array_filter($out, fn($x)=>$x !== ''));
}

/* ============================================================
 * SMTP
 * ============================================================ */

function normalize_smtp_host(string $host): string {
    $host = trim($host);

    /*
     * ここが旧版との重要な違い。
     *
     * ssl://smtp.example
     * tls://smtp.example
     * smtp.example
     *
     * のいずれが入力されても、保存値はホスト名だけにする。
     *
     * 「ssl://」をホスト名としてDNS解決しない。
     */
    $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host) ?? $host;
    $host = preg_replace('#/.*$#', '', $host) ?? $host;
    $host = trim($host);

    if (preg_match('/^\[(.+)](?::\d+)?$/', $host, $m)) {
        return '[' . $m[1] . ']';
    }

    if (substr_count($host, ':') === 1) {
        [$h] = explode(':', $host, 2);
        if ($h !== '') $host = $h;
    }

    return $host;
}

function validate_mail(array $c): array {
    $e = [];

    if ($c['host'] === '') $e[] = 'SMTPサーバを入力してください。';

    if ($c['port'] < 1 || $c['port'] > 65535) {
        $e[] = 'SMTPポートが不正です。';
    }

    if (!in_array($c['encryption'], ['ssl','tls','none'], true)) {
        $e[] = '暗号化方式が不正です。';
    }

    if (!filter_var($c['from_email'], FILTER_VALIDATE_EMAIL)) {
        $e[] = '送信元メールアドレスが不正です。';
    }

    if (
        $c['reply_to'] !== '' &&
        !filter_var($c['reply_to'], FILTER_VALIDATE_EMAIL)
    ) {
        $e[] = '返信先メールアドレスが不正です。';
    }

    if (
        $c['auth'] &&
        ($c['username'] === '' || $c['password'] === '')
    ) {
        $e[] = 'SMTP認証を使用する場合はユーザー名とパスワードが必要です。';
    }

    return $e;
}

function smtp_config(array $base, bool $post = false): array {
    $oldPass = decrypt_secret((string)($base['password'] ?? ''));
    $pass = $post ? ps('password') : $oldPass;

    if ($post && $pass === '') $pass = $oldPass;

    return [
        'host'=>normalize_smtp_host(
            $post ? ps('server') : (string)($base['host'] ?? '')
        ),
        'port'=>$post ? (int)ps('port') : (int)($base['port'] ?? 587),
        'encryption'=>$post ? ps('encryption') : (string)($base['encryption'] ?? 'tls'),
        'auth'=>$post ? pb('auth') : !empty($base['auth']),
        'username'=>$post ? ps('username') : (string)($base['username'] ?? ''),
        'password'=>$pass,
        'from_email'=>$post ? ps('from_email') : (string)($base['from_email'] ?? ''),
        'from_name'=>$post ? ps('from_name') : (string)($base['from_name'] ?? ''),
        'reply_to'=>$post ? ps('reply_to') : (string)($base['reply_to'] ?? '')
    ];
}

function smtp_write($fp, string $line): void {
    if (@fwrite($fp, $line . "\r\n") === false) {
        throw new RuntimeException('SMTPへの送信に失敗しました。');
    }
}

function smtp_read($fp, array $codes): string {
    $last = '';

    while (!feof($fp)) {
        $line = fgets($fp, 4096);
        if ($line === false) {
            throw new RuntimeException('SMTPレスポンスを取得できませんでした。');
        }

        $last = trim($line);

        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            if ($m[2] === ' ') {
                $code = (int)$m[1];
                if (!in_array($code, $codes, true)) {
                    throw new RuntimeException(
                        'SMTPエラー [' . $code . '] ' . $last
                    );
                }
                return $last;
            }
        }
    }

    throw new RuntimeException('SMTPレスポンスが途中で終了しました。');
}

function smtp_cmd($fp, string $cmd, array $codes): string {
    smtp_write($fp, $cmd);
    return smtp_read($fp, $codes);
}

function smtp_open(array $c) {
    $e = validate_mail($c);
    if ($e) throw new RuntimeException(implode("\n", $e));

    $host = normalize_smtp_host($c['host']);
    $port = (int)$c['port'];

    /*
     * SSL:
     *   ssl://host:port
     *
     * TLS:
     *   tcp://host:port
     *   EHLO
     *   STARTTLS
     *   TLS
     *   EHLO
     *
     * none:
     *   tcp://host:port
     */
    if ($c['encryption'] === 'ssl') {
        $target = 'ssl://' . $host . ':' . $port;
    } else {
        $target = 'tcp://' . $host . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        throw new RuntimeException(
            "SMTP接続に失敗しました: {$errstr} ({$errno})"
        );
    }

    stream_set_timeout($fp, SMTP_TIMEOUT);

    try {
        smtp_read($fp, [220]);

        $hello = $_SERVER['SERVER_NAME'] ?? 'localhost';
        smtp_cmd($fp, 'EHLO ' . $hello, [250]);

        if ($c['encryption'] === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);

            $ok = @stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($ok !== true) {
                throw new RuntimeException('SMTP STARTTLSの確立に失敗しました。');
            }

            smtp_cmd($fp, 'EHLO ' . $hello, [250]);
        }

        if ($c['auth']) {
            /*
             * AUTH PLAINを最初に試す。
             * サーバーが拒否した場合はAUTH LOGINへ。
             */
            $plain = base64_encode(
                "\0" . $c['username'] . "\0" . $c['password']
            );

            try {
                smtp_cmd($fp, 'AUTH PLAIN ' . $plain, [235]);
            } catch (Throwable $plainError) {
                smtp_cmd($fp, 'AUTH LOGIN', [334]);
                smtp_cmd($fp, base64_encode($c['username']), [334]);
                smtp_cmd($fp, base64_encode($c['password']), [235]);
            }
        }

        return $fp;
    } catch (Throwable $e) {
        @fclose($fp);
        throw $e;
    }
}

function smtp_header(string $v): string {
    return '=?UTF-8?B?' . base64_encode($v) . '?=';
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

    $fp = smtp_open($c);

    try {
        $from = $c['from_email'];
        $name = $c['from_name'] !== '' ? smtp_header($c['from_name']) : $from;

        smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250,251]);
        smtp_cmd($fp, 'DATA', [354]);

        $body = str_replace(["\r\n","\r"], "\n", $body);
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $name . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . smtp_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ];

        if ($c['reply_to'] !== '') {
            $headers[] = 'Reply-To: ' . $c['reply_to'];
        }

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            str_replace("\n", "\r\n", $body) .
            "\r\n.";

        smtp_cmd($fp, $message, [250]);
        smtp_cmd($fp, 'QUIT', [221]);
    } finally {
        @fclose($fp);
    }
}

/* ============================================================
 * 回答 / 分岐
 * ============================================================ */

function question_map(array $s): array {
    $m = [];

    foreach ($s['groups'] as $g) {
        foreach ($g['questions'] as $q) {
            $m[$q['id']] = $q;
        }
    }

    return $m;
}

function visible_questions(array $s, array $answers): array {
    recalc($s);

    $all = question_map($s);
    $ordered = [];

    foreach ($s['groups'] as $g) {
        foreach ($g['questions'] as $q) $ordered[] = $q;
    }

    if (!$ordered) return [];

    $visible = [];
    $next = $ordered[0]['id'];
    $visited = [];

    while ($next !== '' && isset($all[$next]) && !isset($visited[$next])) {
        $visited[$next] = true;
        $q = $all[$next];
        $visible[] = $q;

        $nextId = '';

        /*
         * 回答された単一選択の分岐先。
         */
        if ($q['type'] === 'single') {
            $answer = (string)($answers[$q['id']] ?? '');

            foreach ($q['options'] as $o) {
                if ((string)$o['label'] === $answer) {
                    $nextId = (string)($o['nextQuestionId'] ?? '');
                    break;
                }
            }
        }

        /*
         * 分岐指定がなければ通常の次質問。
         */
        if ($nextId === '') {
            $pos = array_search($q['id'], array_column($ordered, 'id'), true);
            $nextId = $pos !== false && isset($ordered[$pos + 1])
                ? $ordered[$pos + 1]['id']
                : '';
        }

        $next = $nextId;
    }

    return $visible;
}

function collect_answers(array $s): array {
    $answers = [];

    foreach (visible_questions($s, [] ) as $q) {
        $qid = $q['id'];

        if ($q['type'] === 'multiple') {
            $v = $_POST['answer'][$qid] ?? [];
            $v = is_array($v) ? array_values(array_map('strval', $v)) : [];
        } else {
            $v = (string)($_POST['answer'][$qid] ?? '');
        }

        if (
            !empty($q['required']) &&
            ($v === '' || (is_array($v) && !$v))
        ) {
            throw new RuntimeException($q['number'] . ' は必須です。');
        }

        $answers[$qid] = $v;
    }

    /*
     * 実際には分岐によって後続質問が変わるため、
     * POST全体を安全に再検証する。
     */
    $visible = visible_questions($s, $answers);

    foreach ($visible as $q) {
        $v = $answers[$q['id']] ?? ($q['type'] === 'multiple' ? [] : '');

        if (
            !empty($q['required']) &&
            ($v === '' || (is_array($v) && !$v))
        ) {
            throw new RuntimeException($q['number'] . ' は必須です。');
        }
    }

    return $answers;
}

/* ============================================================
 * POST
 * ============================================================ */

function handle_post(array &$d, array &$set): ?array {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return null;

    $action = ps('action');

    try {
        switch ($action) {

            case 'save_survey':
                $v = validate_survey();

                if ($v['e']) {
                    flash('error', implode("\n", $v['e']));
                    return ['screen'=>'edit','id'=>ps('survey_id')];
                }

                $sid = ps('survey_id');
                $i = survey_index($d['surveys'], $sid);

                if ($i < 0) {
                    $s = [
                        'id'=>id('survey'),
                        'title'=>$v['title'],
                        'description'=>$v['description'],
                        'startAt'=>$v['start'],
                        'endAt'=>$v['end'],
                        'status'=>'draft',
                        'numbering'=>$v['numbering'],
                        'createdAt'=>now(),
                        'updatedAt'=>now(),
                        'groups'=>[]
                    ];
                } else {
                    $s = $d['surveys'][$i];
                    $s['title']=$v['title'];
                    $s['description']=$v['description'];
                    $s['startAt']=$v['start'];
                    $s['endAt']=$v['end'];
                    $s['numbering']=$v['numbering'];
                    $s['updatedAt']=now();
                }

                $s['groups'] = normalize_editor_payload($s);
                recalc($s);

                if ($i < 0) $d['surveys'][] = $s;
                else $d['surveys'][$i] = $s;

                save_json(DATA_FILE, $d);
                flash('success','アンケートを保存しました。');
                return ['screen'=>'list'];

            case 'change_status':
                $sid = ps('survey_id');
                $next = ps('next_status');
                $i = survey_index($d['surveys'], $sid);

                if ($i < 0) throw new RuntimeException('アンケートが見つかりません。');

                $cur = (string)($d['surveys'][$i]['status'] ?? 'draft');

                $allowed = [
                    'draft'=>['published'],
                    'published'=>['stopped'],
                    'stopped'=>['published'],
                    'ended'=>[]
                ];

                if (!in_array($next, $allowed[$cur] ?? [], true)) {
                    throw new RuntimeException('許可されていない状態変更です。');
                }

                $d['surveys'][$i]['status']=$next;
                $d['surveys'][$i]['updatedAt']=now();
                save_json(DATA_FILE,$d);

                flash('success','状態を変更しました。');
                return ['screen'=>'edit','id'=>$sid];

            case 'duplicate_survey':
                $sid = ps('survey_id');
                $s = survey($d,$sid);

                if ($s === null) throw new RuntimeException('アンケートが見つかりません。');

                $map = [];

                $s['id']=id('survey');
                $s['title'] .= '（複製）';
                $s['status']='draft';
                $s['createdAt']=now();
                $s['updatedAt']=now();

                foreach ($s['groups'] as &$g) {
                    $g['id']=id('group');

                    foreach ($g['questions'] as &$q) {
                        $oldQ = $q['id'];
                        $q['id']=id('question');
                        $map[$oldQ]=$q['id'];

                        foreach ($q['options'] as &$o) {
                            $o['id']=id('option');
                        }
                        unset($o);
                    }
                    unset($q);
                }
                unset($g);

                foreach ($s['groups'] as &$g) {
                    foreach ($g['questions'] as &$q) {
                        foreach ($q['options'] as &$o) {
                            if (isset($map[$o['nextQuestionId']])) {
                                $o['nextQuestionId']=$map[$o['nextQuestionId']];
                            } else {
                                $o['nextQuestionId']='';
                            }
                        }
                        unset($o);
                    }
                    unset($q);
                }
                unset($g);

                recalc($s);
                $d['surveys'][]=$s;
                save_json(DATA_FILE,$d);

                flash('success','アンケートを複製しました。');
                return ['screen'=>'list'];

            case 'delete_survey':
                $sid=ps('survey_id');
                $i=survey_index($d['surveys'],$sid);

                if ($i<0) throw new RuntimeException('アンケートが見つかりません。');

                array_splice($d['surveys'],$i,1);
                save_json(DATA_FILE,$d);

                flash('success','アンケートを削除しました。');
                return ['screen'=>'list'];

            case 'answer_next':
                $sid=ps('survey_id');
                $s=survey($d,$sid);

                if ($s===null) throw new RuntimeException('アンケートが見つかりません。');

                $answers=collect_answers($s);
                $_SESSION['answer_draft']=$answers;

                return ['screen'=>'confirm','id'=>$sid];

            case 'answer_back':
                return ['screen'=>'answer','id'=>ps('survey_id')];

            case 'submit_answer':
                $sid=ps('survey_id');
                $s=survey($d,$sid);

                if ($s===null) throw new RuntimeException('アンケートが見つかりません。');

                $draft=$_SESSION['answer_draft'] ?? [];

                $d['answers'][]=[
                    'id'=>id('answer'),
                    'survey_id'=>$sid,
                    'answers'=>is_array($draft)?$draft:[],
                    'createdAt'=>now()
                ];

                unset($_SESSION['answer_draft']);
                save_json(DATA_FILE,$d);

                return ['screen'=>'complete','id'=>$sid];

            case 'save_kintone':
                $old=$set['kintone'];
                $c=kintone_config($old,true);
                $e=validate_kintone($c,true);

                if ($e) {
                    flash('error',implode("\n",$e));
                    return ['screen'=>'kintone'];
                }

                $set['kintone']=array_replace(
                    $old,
                    $c,
                    ['password'=>encrypt_secret($c['password'])]
                );

                save_json(SETTINGS_FILE,$set);
                flash('success','kintone設定を保存しました。');
                return ['screen'=>'kintone'];

            case 'test_kintone':
                $c=kintone_config($set['kintone'],true);
                $r=kintone_request(
                    $c,
                    'GET',
                    '/k/v1/app.json?id=' . rawurlencode($c['app_id'])
                );

                $set['kintone']['subdomain']=$c['subdomain'];
                $set['kintone']['app_id']=$c['app_id'];
                $set['kintone']['username']=$c['username'];
                $set['kintone']['proxy']=$c['proxy'];
                $set['kintone']['verify_ssl']=$c['verify_ssl'];
                $set['kintone']['password']=encrypt_secret($c['password']);
                $set['kintone']['last_test']=now();

                save_json(SETTINGS_FILE,$set);
                flash('success','kintone接続成功。HTTP '.$r['status']);
                return ['screen'=>'kintone'];

            case 'fetch_kintone_fields':
                $c=kintone_config($set['kintone'],true);
                $r=kintone_fields($c);
                $fields=kintone_field_list($r);

                if (!$fields) throw new RuntimeException('kintoneから項目を取得できませんでした。');

                $set['kintone']['fields']=$fields;
                $set['kintone']['password']=encrypt_secret($c['password']);
                $set['kintone']['subdomain']=$c['subdomain'];
                $set['kintone']['app_id']=$c['app_id'];
                $set['kintone']['username']=$c['username'];

                save_json(SETTINGS_FILE,$set);
                flash('success',count($fields).'件の項目を取得しました。');
                return ['screen'=>'kintone'];

            case 'save_kintone_mapping':
                $fields=$set['kintone']['fields'] ?? [];
                $valid=[];

                foreach ($fields as $f) {
                    if (isset($f['code'])) $valid[]=(string)$f['code'];
                }

                $mapping=[
                    'organization'=>ps('mapping_organization'),
                    'name'=>ps('mapping_name'),
                    'email'=>ps('mapping_email'),
                    'department'=>ps('mapping_department'),
                    'phone'=>ps('mapping_phone'),
                    'address'=>[]
                ];

                foreach ((array)($_POST['mapping_address'] ?? []) as $code) {
                    $code=(string)$code;
                    if (in_array($code,$valid,true)) $mapping['address'][]=$code;
                }

                $set['kintone']['mapping']=$mapping;
                save_json(SETTINGS_FILE,$set);

                flash('success','kintone項目マッピングを保存しました。');
                return ['screen'=>'kintone'];

            case 'sync_kintone':
                $c=kintone_config($set['kintone']);
                $r=kintone_records($c);
                $m=$set['kintone']['mapping'] ?? [];

                $customers=[];

                foreach (($r['body']['records'] ?? []) as $record) {
                    $address=[];

                    foreach (($m['address'] ?? []) as $code) {
                        $v=kvalue($record,(string)$code);
                        if ($v!=='') $address[]=$v;
                    }

                    $customers[]=[
                        'id'=>id('customer'),
                        'source_id'=>kvalue($record,'$id'),
                        'organization'=>kvalue($record,(string)($m['organization'] ?? '')),
                        'name'=>kvalue($record,(string)($m['name'] ?? '')),
                        'email'=>kvalue($record,(string)($m['email'] ?? '')),
                        'department'=>kvalue($record,(string)($m['department'] ?? '')),
                        'phone'=>kvalue($record,(string)($m['phone'] ?? '')),
                        'address'=>implode(' ',$address),
                        'updatedAt'=>now()
                    ];
                }

                $d['customers']=$customers;
                $set['kintone']['last_sync']=now();

                save_json(DATA_FILE,$d);
                save_json(SETTINGS_FILE,$set);

                flash('success',count($customers).'件の顧客情報を同期しました。');
                return ['screen'=>'kintone'];

            case 'save_mail':
                $old=$set['mail'];
                $c=smtp_config($old,true);
                $e=validate_mail($c);

                if ($e) {
                    flash('error',implode("\n",$e));
                    return ['screen'=>'mail'];
                }

                $set['mail']=array_replace(
                    $old,
                    $c,
                    ['password'=>encrypt_secret($c['password'])]
                );

                save_json(SETTINGS_FILE,$set);
                flash('success','SMTP設定を保存しました。');
                return ['screen'=>'mail'];

            case 'test_mail':
                $c=smtp_config($set['mail'],true);
                smtp_open($c);

                /*
                 * 接続/認証のみ。
                 */
                $set['mail']['host']=$c['host'];
                $set['mail']['port']=$c['port'];
                $set['mail']['encryption']=$c['encryption'];
                $set['mail']['auth']=$c['auth'];
                $set['mail']['username']=$c['username'];
                $set['mail']['password']=encrypt_secret($c['password']);
                $set['mail']['from_email']=$c['from_email'];
                $set['mail']['from_name']=$c['from_name'];
                $set['mail']['reply_to']=$c['reply_to'];
                $set['mail']['last_test']=now();

                save_json(SETTINGS_FILE,$set);

                flash('success','SMTP接続・認証に成功しました。');
                return ['screen'=>'mail'];

            case 'send_test_mail':
                $to=ps('test_email');

                smtp_send(
                    smtp_config($set['mail']),
                    $to,
                    'アンケートアプリ テストメール',
                    'SMTP設定のテストメールです。'
                );

                flash('success','テストメールを送信しました。');
                return ['screen'=>'mail'];

            case 'send_mail':
                $sid=ps('survey_id');
                $s=survey($d,$sid);

                if ($s===null) throw new RuntimeException('対象アンケートが見つかりません。');

                $selected=$_POST['customer_ids'] ?? [];
                if (!is_array($selected) || !$selected) {
                    throw new RuntimeException('顧客を選択してください。');
                }

                $subject=ps('subject');
                $body=(string)($_POST['body'] ?? '');

                if ($subject==='' || trim($body)==='') {
                    throw new RuntimeException('メール件名と本文を入力してください。');
                }

                $map=[];
                foreach ($d['customers'] as $c) {
                    $map[(string)$c['id']]=$c;
                }

                $sent=0;
                $failed=0;

                foreach ($selected as $cid) {
                    $cid=(string)$cid;
                    if (!isset($map[$cid])) continue;

                    $c=$map[$cid];
                    $email=trim((string)($c['email'] ?? ''));

                    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) {
                        $failed++;
                        $result='失敗：メールアドレス不正';
                    } else {
                        $sub=str_replace(
                            ['{顧客名}','{アンケートURL}'],
                            [
                                (string)($c['name'] ?? ''),
                                public_url($sid)
                            ],
                            $subject
                        );

                        $text=str_replace(
                            ['{顧客名}','{アンケートURL}'],
                            [
                                (string)($c['name'] ?? ''),
                                public_url($sid)
                            ],
                            $body
                        );

                        try {
                            smtp_send(
                                smtp_config($set['mail']),
                                $email,
                                $sub,
                                $text
                            );
                            $sent++;
                            $result='成功';
                        } catch (Throwable $e) {
                            $failed++;
                            $result='失敗：'.$e->getMessage();
                        }
                    }

                    $d['send_history'][]=[
                        'id'=>id('send'),
                        'survey_id'=>$sid,
                        'customer_id'=>$cid,
                        'customer_name'=>(string)($c['name'] ?? ''),
                        'type'=>'一括送信',
                        'result'=>$result,
                        'createdAt'=>now()
                    ];
                }

                save_json(DATA_FILE,$d);

                flash(
                    $failed ? 'warning':'success',
                    "送信結果：成功 {$sent}件 / 失敗 {$failed}件"
                );

                return ['screen'=>'send','id'=>$sid];
        }

        return null;

    } catch (Throwable $e) {
        flash('error','処理に失敗しました：'.$e->getMessage());

        $screen=gs('screen','list');
        $id=ps('survey_id');

        return [
            'screen'=>$screen ?: 'list',
            'id'=>$id
        ];
    }
}

/* ============================================================
 * HTML
 * ============================================================ */

function head(string $title, bool $admin=true): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> - <?=h(APP_TITLE)?></title>
<style>
:root{
 --p:#2563eb;--pd:#1d4ed8;--ok:#16a34a;--warn:#d97706;
 --err:#dc2626;--text:#1e293b;--muted:#64748b;
 --bg:#f8fafc;--card:#fff;--border:#dbe2ea;
}
*{box-sizing:border-box}
html{scrollbar-gutter:stable}
body{
 margin:0;background:var(--bg);color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
a{color:inherit}
.container{width:min(1400px,calc(100% - 32px));margin:auto}
.page{padding:28px 0 60px}
header.admin{
 background:#0f172a;color:#fff;padding:13px 0;
}
.nav{
 width:min(1400px,calc(100% - 32px));margin:auto;
 display:flex;gap:8px;align-items:center;flex-wrap:wrap
}
.nav strong{margin-right:auto}
.nav a{padding:8px 10px;text-decoration:none;border-radius:7px}
.nav a:hover{background:#1e293b}
.title{
 display:flex;justify-content:space-between;gap:15px;
 align-items:center;margin-bottom:20px
}
.card{
 background:var(--card);border:1px solid var(--border);
 border-radius:12px;box-shadow:0 4px 18px rgba(15,23,42,.06);
 margin-bottom:18px;overflow:hidden
}
.card h2,.card h3{margin-top:0}
.card-head{padding:15px 18px;border-bottom:1px solid var(--border)}
.card-body{padding:18px}
.grid{display:grid;gap:14px}
.g2{grid-template-columns:repeat(2,minmax(0,1fr))}
.g3{grid-template-columns:repeat(3,minmax(0,1fr))}
label>span,.label{display:block;font-weight:700;margin-bottom:6px}
input,textarea,select{
 width:100%;padding:10px 11px;border:1px solid #cbd5e1;
 border-radius:8px;background:#fff;color:var(--text);font:inherit
}
textarea{min-height:120px;resize:vertical}
input[type=checkbox],input[type=radio]{width:auto}
.btn{
 display:inline-flex;align-items:center;justify-content:center;
 min-height:40px;padding:8px 14px;border-radius:8px;
 border:1px solid transparent;font:inherit;font-weight:700;
 cursor:pointer;text-decoration:none
}
.btn-primary{background:var(--p);color:#fff}
.btn-primary:hover{background:var(--pd)}
.btn-secondary{background:#fff;border-color:var(--border)}
.btn-success{background:var(--ok);color:#fff}
.btn-warning{background:var(--warn);color:#fff}
.btn-danger{background:var(--err);color:#fff}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.badge{
 display:inline-flex;padding:4px 9px;border-radius:999px;
 font-size:13px;font-weight:700
}
.badge.ok{background:#dcfce7;color:#166534}
.badge.warn{background:#fef3c7;color:#92400e}
.badge.muted{background:#e2e8f0;color:#475569}
.alert{
 padding:13px 15px;border-radius:10px;margin-bottom:18px;
 white-space:pre-line;border:1px solid
}
.alert.ok{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.alert.warn{background:#fffbeb;color:#92400e;border-color:#fde68a}
.alert.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:720px}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);vertical-align:top}
th{background:#f8fafc}
.empty{text-align:center;color:var(--muted);padding:30px}
.group{
 border:1px solid var(--border);border-radius:10px;
 margin:14px 0;background:#f8fafc
}
.group-head{
 padding:10px;display:flex;gap:8px;align-items:center;
 background:#f1f5f9;border-bottom:1px solid var(--border)
}
.group-head input{flex:1}
.question{
 background:#fff;border:1px solid var(--border);
 border-radius:9px;margin:10px;padding:13px
}
.question.dragging,.group.dragging{opacity:.45}
.qhead{display:flex;gap:8px;align-items:center}
.qhead .qno{font-weight:800;min-width:55px}
.option{
 display:grid;grid-template-columns:1fr 260px auto;
 gap:7px;margin:7px 0
}
.drag{cursor:grab;color:var(--muted)}
.editor-drop{min-height:15px}
.check{display:flex;gap:7px;align-items:center;margin:8px 0}
.answer-shell{width:min(760px,calc(100% - 24px));margin:30px auto}
.answer-shell .card{margin-bottom:15px}
.preview-q{
 padding:12px 0;border-bottom:1px solid var(--border)
}
.small{font-size:13px;color:var(--muted)}
.spinner{display:none}
.loading .spinner{display:inline-block}
.loading button{pointer-events:none;opacity:.6}
.stat{font-size:30px;font-weight:800}
@media(max-width:800px){
 .g2,.g3{grid-template-columns:1fr}
 .container{width:min(100% - 20px,1400px)}
 .title{align-items:flex-start;flex-direction:column}
 .option{grid-template-columns:1fr}
 .answer-shell{width:min(100% - 16px,760px)}
}
</style>
</head>
<body>
<?php if($admin): ?>
<header class="admin">
<div class="nav">
<strong><?=h(APP_TITLE)?></strong>
<a href="<?=h(app_url(['screen'=>'list']))?>">アンケート</a>
<a href="<?=h(app_url(['screen'=>'kintone']))?>">kintone</a>
<a href="<?=h(app_url(['screen'=>'mail']))?>">メール</a>
</div>
</header>
<?php endif; ?>
<?php
}

function foot(): void {
?>
<script>
(function(){
'use strict';

function renumber(){
 const mode=document.querySelector('[name="numbering"]')?.value||'global';
 let n=1,g=1;

 document.querySelectorAll('.group').forEach(group=>{
   let q=1;

   group.querySelectorAll('.question').forEach(card=>{
     const no=card.querySelector('.qno');
     if(no) no.textContent=mode==='group'?'Q'+g+'-'+q:'Q'+n;
     n++;q++;
   });

   g++;
 });

 updateBranches();
 sync();
}

function sync(){
 document.querySelectorAll('.group').forEach(group=>{
   const gid=group.dataset.gid;
   const input=document.querySelector(
     'input[name="group_order[]"][value="'+CSS.escape(gid)+'"]'
   );
   if(input && group.parentNode){
     group.parentNode.appendChild(input);
   }

   const order=group.querySelector('.question-order');
   if(!order)return;

   order.innerHTML='';

   group.querySelectorAll('.question').forEach(card=>{
     const qid=card.dataset.qid;
     const i=document.createElement('input');
     i.type='hidden';
     i.name='questions_by_group['+gid+'][]';
     i.value=qid;
     order.appendChild(i);
   });
 }

 updateBranches();
}

function updateBranches(){
 const qs=[...document.querySelectorAll('.question')];
 const list=qs.map(q=>({
   id:q.dataset.qid,
   label:q.querySelector('.qno')?.textContent||q.dataset.qid
 }));

 document.querySelectorAll('.branch').forEach(sel=>{
   const current=sel.value;
   sel.innerHTML='<option value="">次の質問を指定しない</option>';

   list.forEach(x=>{
     const o=document.createElement('option');
     o.value=x.id;
     o.textContent=x.label;
     o.selected=x.id===current;
     sel.appendChild(o);
   });
 });
}

function setupQuestion(card){
 card.draggable=true;

 card.addEventListener('dragstart',e=>{
   if(e.target.closest('input,textarea,select,button'))return;
   card.classList.add('dragging');
   e.dataTransfer.effectAllowed='move';
 });

 card.addEventListener('dragend',()=>{
   card.classList.remove('dragging');
   renumber();
 });

 card.addEventListener('dragover',e=>{
   e.preventDefault();
   const moving=document.querySelector('.question.dragging');
   if(!moving||moving===card)return;

   const r=card.getBoundingClientRect();
   const before=e.clientY<r.top+r.height/2;

   if(before)card.parentNode.insertBefore(moving,card);
   else card.parentNode.insertBefore(moving,card.nextSibling);
 });
}

function setupGroup(group){
 group.draggable=true;

 group.addEventListener('dragstart',e=>{
   if(e.target.closest('.question'))return;
   group.classList.add('dragging');
   e.dataTransfer.effectAllowed='move';
 });

 group.addEventListener('dragend',()=>{
   group.classList.remove('dragging');
   renumber();
 });

 group.addEventListener('dragover',e=>{
   e.preventDefault();

   const moving=document.querySelector('.group.dragging');
   if(!moving||moving===group)return;

   const r=group.getBoundingClientRect();
   const before=e.clientY<r.top+r.height/2;

   if(before)group.parentNode.insertBefore(moving,group);
   else group.parentNode.insertBefore(moving,group.nextSibling);
 });

 group.querySelector('.add-question')?.addEventListener(
   'click',()=>addQuestion(group)
 );

 group.querySelector('.remove-group')?.addEventListener(
   'click',()=>{
     if(confirm('このグループを削除しますか？')){
       group.remove();
       renumber();
     }
   }
 );

 group.querySelectorAll('.question').forEach(setupQuestion);
}

function addQuestion(group){
 const qid='question-'+crypto.randomUUID().replaceAll('-','');
 const wrap=document.createElement('div');
 wrap.className='question';
 wrap.dataset.qid=qid;

 wrap.innerHTML=`
   <div class="qhead">
     <span class="drag">☷</span>
     <span class="qno"></span>
     <input name="question_text[${qid}]" placeholder="質問文">
     <button type="button" class="btn btn-danger remove-q">削除</button>
   </div>
   <div class="grid g2" style="margin-top:10px">
     <label>
       <span>回答形式</span>
       <select name="question_type[${qid}]" class="qtype">
         <option value="single">単一選択</option>
         <option value="multiple">複数選択</option>
         <option value="text">自由記述</option>
       </select>
     </label>
     <label class="check">
       <input type="checkbox" name="question_required[${qid}]" value="1">
       必須
     </label>
   </div>
   <div class="options"></div>
   <div class="question-order"></div>
 `;

 group.querySelector('.questions').appendChild(wrap);
 setupQuestion(wrap);
 setupQuestionUI(wrap);
 renumber();
}

function setupQuestionUI(card){
 const type=card.querySelector('.qtype');
 const options=card.querySelector('.options');

 function render(){
   options.innerHTML='';

   if(type.value==='text')return;

   const qid=card.dataset.qid;

   for(let i=0;i<2;i++) addOption(i);

   const add=document.createElement('button');
   add.type='button';
   add.className='btn btn-secondary';
   add.textContent='＋選択肢';
   add.onclick=()=>{
     addOption(options.querySelectorAll('.option').length);
   };
   options.appendChild(add);
 }

 function addOption(i){
   const div=document.createElement('div');
   div.className='option';

   div.innerHTML=`
     <input name="question_option[${card.dataset.qid}][]" placeholder="選択肢">
     <select name="option_next[${card.dataset.qid}][]" class="branch">
       <option value="">次の質問を指定しない</option>
     </select>
     <button type="button" class="btn btn-danger">削除</button>
   `;

   div.querySelector('button').onclick=()=>{
     div.remove();
     updateBranches();
   };

   options.appendChild(div);
   updateBranches();
 }

 type.addEventListener('change',render);
 render();

 card.querySelector('.remove-q').onclick=()=>{
   if(confirm('この質問を削除しますか？')){
     card.remove();
     renumber();
   }
 };
}

document.querySelectorAll('.group').forEach(group=>{
 setupGroup(group);
 group.querySelectorAll('.question').forEach(setupQuestionUI);
});

document.querySelector('#add-group')?.addEventListener('click',()=>{
 const gid='group-'+crypto.randomUUID().replaceAll('-','');

 const group=document.createElement('div');
 group.className='group';
 group.dataset.gid=gid;

 group.innerHTML=`
  <div class="group-head">
   <span class="drag">☷</span>
   <input name="group_title[${gid}]" value="新しいグループ">
   <input type="hidden" name="group_order[]" value="${gid}">
   <button type="button" class="btn btn-danger remove-group">削除</button>
  </div>
  <div class="questions"></div>
  <div class="question-order"></div>
  <div style="padding:10px">
   <button type="button" class="btn btn-secondary add-question">＋質問を追加</button>
  </div>
 `;

 document.querySelector('#groups').appendChild(group);
 setupGroup(group);
 addQuestion(group);
 renumber();
});

document.querySelector('[name="numbering"]')?.addEventListener('change',renumber);
document.querySelector('#survey-editor')?.addEventListener('submit',sync);

document.querySelectorAll('form[data-confirm]').forEach(form=>{
 form.addEventListener('submit',e=>{
   const msg=form.dataset.confirm;
   if(msg&&!confirm(msg))e.preventDefault();
 });
});

document.querySelectorAll('form[data-loading]').forEach(form=>{
 form.addEventListener('submit',()=>{
   form.classList.add('loading');
 });
});

renumber();
})();
</script>
</body>
</html>
<?php
}

/* ============================================================
 * 一覧
 * ============================================================ */

function render_list(array $d): void {
    $q=gs('q');
    $filter=gs('filter','all');
    $sort=gs('sort','updated_desc');

    $rows=array_values(array_filter(
        $d['surveys'],
        function($s)use($q,$filter){
            if(
                $q!=='' &&
                mb_stripos((string)($s['title']??''),$q)===false
            )return false;

            return $filter==='all' ||
                $filter===(string)($s['status']??'draft');
        }
    ));

    usort($rows,function($a,$b)use($sort){
        return match($sort){
            'updated_asc'=>strcmp($a['updatedAt']??'',$b['updatedAt']??''),
            'start_asc'=>strcmp($a['startAt']??'',$b['startAt']??''),
            'start_desc'=>strcmp($b['startAt']??'',$a['startAt']??''),
            'title_asc'=>strnatcasecmp($a['title']??'',$b['title']??''),
            default=>strcmp($b['updatedAt']??'',$a['updatedAt']??'')
        };
    });

    head('アンケート一覧');
    ?>
<div class="page"><div class="container">
<?php show_flash(); ?>

<div class="title">
 <div>
  <h1>アンケート一覧</h1>
  <p class="small">アンケートの作成・公開・送信・集計を管理します。</p>
 </div>
 <a class="btn btn-primary" href="<?=h(app_url(['screen'=>'edit','id'=>'new']))?>">
  ＋新規作成
 </a>
</div>

<div class="card">
<div class="card-body">
<form class="row" method="get">
 <input type="hidden" name="screen" value="list">
 <input name="q" value="<?=h($q)?>" placeholder="アンケート名を検索">
 <select name="filter">
  <option value="all">すべて</option>
  <?php foreach(['draft'=>'下書き','published'=>'公開中','stopped'=>'停止','ended'=>'終了'] as $k=>$v): ?>
  <option value="<?=$k?>" <?=$filter===$k?'selected':''?>><?=h($v)?></option>
  <?php endforeach; ?>
 </select>
 <select name="sort">
  <option value="updated_desc">更新日時 新しい順</option>
  <option value="updated_asc">更新日時 古い順</option>
  <option value="start_desc">開始日時 新しい順</option>
  <option value="start_asc">開始日時 古い順</option>
  <option value="title_asc">タイトル順</option>
 </select>
 <button class="btn btn-secondary">検索</button>
</form>
</div></div>

<div class="card"><div class="card-body table-wrap">
<table>
<thead><tr>
<th>タイトル</th><th>状態</th><th>期間</th><th>更新</th><th>操作</th>
</tr></thead>
<tbody>
<?php if(!$rows): ?>
<tr><td colspan="5" class="empty">アンケートはありません。</td></tr>
<?php endif; ?>

<?php foreach($rows as $s): ?>
<tr>
<td><strong><?=h($s['title'])?></strong></td>
<td>
 <span class="badge <?=h(status_class((string)$s['status']))?>">
  <?=h(status_label((string)$s['status']))?>
 </span>
</td>
<td><?=h($s['startAt']??'')?> ～ <?=h($s['endAt']??'')?></td>
<td><?=h($s['updatedAt']??'')?></td>
<td>
<div class="actions">
<a class="btn btn-secondary" href="<?=h(app_url(['screen'=>'edit','id'=>$s['id']]))?>">編集</a>
<a class="btn btn-secondary" href="<?=h(app_url(['screen'=>'preview','id'=>$s['id']]))?>">プレビュー</a>
<a class="btn btn-secondary" href="<?=h(app_url(['screen'=>'send','id'=>$s['id']]))?>">送信</a>
<a class="btn btn-secondary" href="<?=h(app_url(['screen'=>'analytics','id'=>$s['id']]))?>">集計</a>

<form method="post" data-confirm="このアンケートを複製しますか？">
<input type="hidden" name="action" value="duplicate_survey">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">
<button class="btn btn-secondary">複製</button>
</form>

<form method="post" data-confirm="このアンケートを削除しますか？">
<input type="hidden" name="action" value="delete_survey">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">
<button class="btn btn-danger">削除</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div>
</div></div>
<?php
    foot();
}

/* ============================================================
 * 編集
 * ============================================================ */

function render_edit(array $s): void {
    recalc($s);
    head('アンケート作成・編集');
    show_flash();
?>
<div class="page"><div class="container">

<div class="title">
<div>
<h1>アンケート作成・編集</h1>
<p class="small">質問・グループ・公開状態を管理します。</p>
</div>
<div class="actions">
<a class="btn btn-secondary"
 href="<?=h(app_url(['screen'=>'list']))?>"
 onclick="return confirm('編集内容を破棄して一覧へ戻りますか？')">キャンセル</a>
</div>
</div>

<form method="post" id="survey-editor" data-loading>
<input type="hidden" name="action" value="save_survey">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">

<?php foreach($s['groups'] as $g): ?>
<input type="hidden" name="group_order[]" value="<?=h($g['id'])?>">
<?php endforeach; ?>

<div class="card">
<div class="card-head"><h2>基本情報</h2></div>
<div class="card-body">
<div class="grid g2">
<label><span>アンケートタイトル</span>
<input name="title" value="<?=h($s['title'])?>" required></label>

<label><span>質問番号</span>
<select name="numbering">
<option value="global" <?=$s['numbering']==='global'?'selected':''?>>アンケート全体 Q1、Q2...</option>
<option value="group" <?=$s['numbering']==='group'?'selected':''?>>グループ毎 Q1-1、Q1-2...</option>
</select></label>

<label><span>開始日時</span>
<input type="datetime-local" name="start_at" value="<?=h($s['startAt'])?>"></label>

<label><span>終了日時</span>
<input type="datetime-local" name="end_at" value="<?=h($s['endAt'])?>"></label>
</div>

<label style="margin-top:14px"><span>説明</span>
<textarea name="description"><?=h($s['description'])?></textarea></label>
</div></div>

<div class="card">
<div class="card-head">
<h2>状態</h2>
</div>
<div class="card-body">
<div class="row">
<span class="badge <?=h(status_class((string)$s['status']))?>">
<?=h(status_label((string)$s['status']))?>
</span>

<?php if($s['status']!=='ended'): ?>
<form method="post" class="row">
<input type="hidden" name="action" value="change_status">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">
<select name="next_status">
<?php if($s['status']==='draft'): ?>
<option value="published">公開</option>
<?php elseif($s['status']==='published'): ?>
<option value="stopped">停止</option>
<?php elseif($s['status']==='stopped'): ?>
<option value="published">再開</option>
<?php endif; ?>
</select>
<button class="btn btn-secondary">状態変更</button>
</form>
<?php endif; ?>
</div>
</div></div>

<div class="card">
<div class="card-head">
<h2>質問・グループ</h2>
</div>
<div class="card-body" id="groups">

<?php foreach($s['groups'] as $g): ?>
<div class="group" data-gid="<?=h($g['id'])}">
<div class="group-head">
<span class="drag">☷</span>
<input name="group_title[<?=h($g['id'])?>]" value="<?=h($g['title'])?>">
<button type="button" class="btn btn-danger remove-group">削除</button>
</div>

<div class="questions">
<?php foreach($g['questions'] as $q): ?>
<div class="question" data-qid="<?=h($q['id'])?>">
<div class="qhead">
<span class="drag">☷</span>
<span class="qno"><?=h($q['number'])?></span>
<input name="question_text[<?=h($q['id'])?>]" value="<?=h($q['text'])?>" placeholder="質問文">
<button type="button" class="btn btn-danger remove-q">削除</button>
</div>

<div class="grid g2" style="margin-top:10px">
<label>
<span>回答形式</span>
<select name="question_type[<?=h($q['id'])?>]" class="qtype">
<option value="single" <?=$q['type']==='single'?'selected':''?>>単一選択</option>
<option value="multiple" <?=$q['type']==='multiple'?'selected':''?>>複数選択</option>
<option value="text" <?=$q['type']==='text'?'selected':''?>>自由記述</option>
</select>
</label>

<label class="check">
<input type="checkbox"
 name="question_required[<?=h($q['id'])?>]"
 value="1" <?=$q['required']?'checked':''?>>
必須
</label>
</div>

<?php if($q['type']!=='text'): ?>
<div class="options">
<?php foreach($q['options'] as $o): ?>
<div class="option">
<input name="question_option[<?=h($q['id'])?>][]"
 value="<?=h($o['label'])?>">

<select name="option_next[<?=h($q['id'])?>][]"
 class="branch"
 data-current="<?=h($o['nextQuestionId'])?>">
<option value="">次の質問を指定しない</option>
</select>

<button type="button" class="btn btn-danger"
 onclick="this.parentElement.remove();updateBranches()">削除</button>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="question-order"></div>
</div>
<?php endforeach; ?>
</div>

<div class="question-order"></div>

<div class="row" style="padding:10px">
<button type="button" class="btn btn-secondary add-question">＋質問を追加</button>
</div>
</div>
<?php endforeach; ?>

</div>

<div class="row" style="margin-top:15px">
<button type="button" id="add-group" class="btn btn-secondary">＋グループを追加</button>
<a class="btn btn-secondary"
 href="<?=h(app_url(['screen'=>'preview','id'=>$s['id']]))?>">プレビュー</a>
<button class="btn btn-primary">保存して一覧へ</button>
</div>
</div>
</form>
</div></div>
<?php
    foot();
}

/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(array $s): void {
    recalc($s);
    head('プレビュー');
    show_flash();
?>
<div class="page"><div class="container">
<div class="title">
<div>
<h1>プレビュー</h1>
<p class="small"><?=h($s['title'])?></p>
</div>
<a class="btn btn-secondary"
 href="<?=h(app_url(['screen'=>'edit','id'=>$s['id']]))?>">編集へ戻る</a>
</div>

<div class="card"><div class="card-body">
<h2><?=h($s['title'])?></h2>
<p><?=nl2br(h($s['description']))?></p>

<?php foreach($s['groups'] as $g): ?>
<h3><?=h($g['title'])?></h3>

<?php foreach($g['questions'] as $q): ?>
<div class="preview-q">
<strong><?=h($q['number'])?> <?=h($q['text'])?></strong>

<?php if($q['required']): ?>
<span class="badge warn">必須</span>
<?php endif; ?>

<?php if($q['type']==='text'): ?>
<textarea disabled placeholder="自由記述"></textarea>
<?php else: ?>
<?php foreach($q['options'] as $o): ?>
<label class="check">
<input type="<?=$q['type']==='single'?'radio':'checkbox'?>" disabled>
<?=h($o['label'])?>
<?php if(!empty($o['nextQuestionId'])): ?>
<span class="small">→ 条件分岐あり</span>
<?php endif; ?>
</label>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

</div></div>
</div></div>
<?php
    foot();
}

/* ============================================================
 * kintone画面
 * ============================================================ */

function render_kintone(array $c): void {
    head('kintone連携設定');
    show_flash();

    $fields=is_array($c['fields']??null)?$c['fields']:[];
    $m=is_array($c['mapping']??null)?$c['mapping']:[];
    $addr=is_array($m['address']??null)?$m['address']:[];
?>
<div class="page"><div class="container">
<div class="title">
<div><h1>kintone連携設定</h1>
<p class="small">顧客管理アプリとの接続・項目取得・同期。</p></div>
</div>

<div class="card"><div class="card-head"><h2>接続設定</h2></div>
<div class="card-body">

<form method="post" data-loading>
<input type="hidden" name="action" value="save_kintone">

<div class="grid g2">
<label><span>サブドメイン</span>
<input name="subdomain"
 value="<?=h($c['subdomain'])?>"
 placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com"
 required></label>

<label><span>顧客管理アプリID</span>
<input type="number" name="app_id"
 value="<?=h($c['app_id'])?>" min="1" required></label>

<label><span>ログイン名</span>
<input name="username" value="<?=h($c['username'])?>" required></label>

<label><span>パスワード</span>
<input type="password" name="password"
 placeholder="変更しない場合は空欄"></label>

<label><span>Proxy</span>
<input name="proxy" value="<?=h($c['proxy'])?>" placeholder="host:port"></label>

<label class="check">
<input type="checkbox" name="verify_ssl" value="1"
 <?=$c['verify_ssl']?'checked':''?>>
SSL証明書を検証する
</label>
</div>

<div class="row" style="margin-top:15px">
<button class="btn btn-primary">設定保存</button>
</div>
</form>

<hr>

<form method="post" data-loading>
<input type="hidden" name="action" value="test_kintone">
<button class="btn btn-success">接続テスト</button>
</form>

</div></div>

<div class="card"><div class="card-head"><h2>項目一覧</h2></div>
<div class="card-body">

<form method="post" data-loading>
<input type="hidden" name="action" value="fetch_kintone_fields">
<button class="btn btn-secondary">項目一覧を再取得</button>
</form>

<?php if($fields): ?>
<form method="post" style="margin-top:20px">
<input type="hidden" name="action" value="save_kintone_mapping">

<div class="grid g2">
<?php foreach([
 'organization'=>'組織名',
 'name'=>'氏名',
 'email'=>'メールアドレス',
 'department'=>'部署名',
 'phone'=>'電話番号'
] as $key=>$label): ?>
<label>
<span><?=h($label)?></span>
<select name="mapping_<?=$key?>">
<option value="">未設定</option>
<?php foreach($fields as $f): ?>
<option value="<?=h($f['code'])?>"
 <?=$m[$key]??''===$f['code']?'selected':''?>>
<?=h($f['label'])?>（<?=h($f['code'])?>）
</option>
<?php endforeach; ?>
</select>
</label>
<?php endforeach; ?>
</div>

<label style="margin-top:15px">
<span>住所（複数選択可）</span>
<?php foreach($fields as $f): ?>
<label class="check">
<input type="checkbox"
 name="mapping_address[]"
 value="<?=h($f['code'])?>"
 <?=in_array($f['code'],$addr,true)?'checked':''?>>
<?=h($f['label'])?>（<?=h($f['code'])?>）
</label>
<?php endforeach; ?>
</label>

<button class="btn btn-primary">マッピングを保存</button>
</form>
<?php endif; ?>

</div></div>

<div class="card"><div class="card-head"><h2>顧客情報同期</h2></div>
<div class="card-body">
<form method="post" data-loading>
<input type="hidden" name="action" value="sync_kintone">
<button class="btn btn-primary">顧客情報を同期</button>
</form>

<?php if(!empty($c['last_sync'])): ?>
<p class="small">最終同期：<?=h($c['last_sync'])?></p>
<?php endif; ?>
</div></div>

</div></div>
<?php
    foot();
}

/* ============================================================
 * メール設定
 * ============================================================ */

function render_mail(array $c): void {
    head('メールサーバ設定');
    show_flash();
?>
<div class="page"><div class="container">
<div class="title">
<div><h1>メールサーバ設定</h1>
<p class="small">SMTP設定・接続テスト・テストメール。</p></div>
</div>

<div class="card"><div class="card-head">
<h2>接続状態：
<?=!empty($c['last_test'])
 ? '接続確認済み'
 : '未設定'?></h2>
</div>
<div class="card-body">

<form method="post" data-loading>
<input type="hidden" name="action" value="save_mail">

<div class="grid g2">
<label><span>SMTPサーバ</span>
<input name="server" value="<?=h($c['host'])?>" required></label>

<label><span>SMTPポート</span>
<input type="number" name="port"
 value="<?=h($c['port'])?>" min="1" max="65535" required></label>

<label><span>暗号化方式</span>
<select name="encryption">
<option value="ssl" <?=$c['encryption']==='ssl'?'selected':''?>>SSL</option>
<option value="tls" <?=$c['encryption']==='tls'?'selected':''?>>TLS</option>
<option value="none" <?=$c['encryption']==='none'?'selected':''?>>なし</option>
</select>
</label>

<label class="check">
<input type="checkbox" name="auth" value="1"
 <?=$c['auth']?'checked':''?>> SMTP認証を使用
</label>

<label><span>SMTPユーザー名</span>
<input name="username" value="<?=h($c['username'])?>"></label>

<label><span>SMTPパスワード</span>
<input type="password" name="password"
 placeholder="変更しない場合は空欄"></label>

<label><span>送信元メールアドレス</span>
<input type="email" name="from_email"
 value="<?=h($c['from_email'])?>" required></label>

<label><span>送信元名</span>
<input name="from_name" value="<?=h($c['from_name'])?>"></label>

<label><span>返信先</span>
<input type="email" name="reply_to"
 value="<?=h($c['reply_to'])?>"></label>
</div>

<div class="row" style="margin-top:15px">
<button class="btn btn-primary">設定保存</button>
</div>
</form>

<hr>

<form method="post" data-loading>
<input type="hidden" name="action" value="test_mail">
<button class="btn btn-success">接続テスト</button>
</form>

<?php if(!empty($c['last_test'])): ?>
<p class="small">最終接続確認：<?=h($c['last_test'])?></p>
<?php endif; ?>

</div></div>

<div class="card"><div class="card-head"><h2>テストメール</h2></div>
<div class="card-body">
<form method="post" data-loading>
<input type="hidden" name="action" value="send_test_mail">

<label><span>テスト送信先</span>
<input type="email" name="test_email" required></label>

<button class="btn btn-primary" style="margin-top:12px">
テストメール送信
</button>
</form>
</div></div>

</div></div>
<?php
    foot();
}

/* ============================================================
 * 送信
 * ============================================================ */

function render_send(array $s,array $customers,array $history): void {
    head('顧客選択・メール送信');
    show_flash();
?>
<div class="page"><div class="container">
<div class="title">
<div><h1>顧客選択・メール送信</h1>
<p>対象アンケート：<strong><?=h($s['title'])?></strong></p></div>
<a class="btn btn-secondary" href="<?=h(app_url(['screen'=>'list']))?>">一覧へ戻る</a>
</div>

<div class="card"><div class="card-head"><h2>顧客選択・メール作成</h2></div>
<div class="card-body">
<form method="post"
 data-loading
 data-confirm="選択した顧客へメールを送信します。よろしいですか？">

<input type="hidden" name="action" value="send_mail">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">

<div style="max-height:320px;overflow:auto;border:1px solid var(--border);padding:10px">
<?php if(!$customers): ?>
<div class="empty">顧客データがありません。</div>
<?php else: ?>
<?php foreach($customers as $c): ?>
<label class="check">
<input type="checkbox" name="customer_ids[]" value="<?=h($c['id'])?>">
<strong><?=h($c['name']??'')?></strong>
<?=h($c['email']??'')?>
</label>
<?php endforeach; ?>
<?php endif; ?>
</div>

<div class="grid g2" style="margin-top:15px">
<label><span>件名</span>
<input name="subject" value="<?=h($s['title'])?>のご案内" required></label>

<label><span>本文</span>
<textarea name="body" required>いつもお世話になっております。

{顧客名} 様

以下のアンケートへご回答ください。

{アンケートURL}

よろしくお願いいたします。</textarea></label>
</div>

<button class="btn btn-primary" style="margin-top:12px">一括送信</button>
</form>
</div></div>

<div class="card"><div class="card-head"><h2>送信履歴</h2></div>
<div class="card-body table-wrap">
<?php if(!$history): ?>
<div class="empty">送信履歴はありません。</div>
<?php else: ?>
<table>
<thead><tr><th>日時</th><th>顧客</th><th>種別</th><th>結果</th></tr></thead>
<tbody>
<?php foreach(array_reverse($history) as $x): ?>
<tr>
<td><?=h($x['createdAt']??'')?></td>
<td><?=h($x['customer_name']??'')?></td>
<td><?=h($x['type']??'')?></td>
<td><?=h($x['result']??'')?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div></div>

</div></div>
<?php
    foot();
}

/* ============================================================
 * 集計
 * ============================================================ */

function render_analytics(array $s,array $answers,array $customers): void {
    $rows=array_values(array_filter(
        $answers,
        fn($a)=>
            ($a['survey_id']??'')===$s['id']
    ));

    $count=count($rows);
    $customerCount=count($customers);
    $rate=$customerCount
        ? round($count/$customerCount*100,1)
        : 0;

    head('回答集計・分析');
    show_flash();
?>
<div class="page"><div class="container">
<div class="title">
<div>
<h1>回答集計・分析</h1>
<p>対象アンケート：<strong><?=h($s['title'])?></strong></p>
</div>
<a class="btn btn-secondary" href="<?=h(app_url(['screen'=>'list']))?>">一覧</a>
</div>

<div class="grid g3">
<div class="card"><div class="card-body">
<div class="small">送信対象者数</div>
<div class="stat"><?=h($customerCount)?></div>
</div></div>
<div class="card"><div class="card-body">
<div class="small">回答数</div>
<div class="stat"><?=h($count)?></div>
</div></div>
<div class="card"><div class="card-body">
<div class="small">回答率</div>
<div class="stat"><?=h($rate)?>%</div>
</div></div>
</div>

<div class="card"><div class="card-head"><h2>設問別集計</h2></div>
<div class="card-body">

<?php if(!$count): ?>
<div class="empty">現在、回答データはありません</div>
<?php else: ?>

<?php foreach($s['groups'] as $g): ?>
<h3><?=h($g['title'])?></h3>

<?php foreach($g['questions'] as $q): ?>
<?php
$freq=[];
foreach($rows as $r){
 $v=$r['answers'][$q['id']]??'';
 if(is_array($v)){
   foreach($v as $x)$freq[(string)$x]=($freq[(string)$x]??0)+1;
 }else{
   $x=(string)$v;
   if($x!=='')$freq[$x]=($freq[$x]??0)+1;
 }
}
?>
<div class="preview-q">
<strong><?=h($q['number'])?> <?=h($q['text'])?></strong>
<?php if($q['type']!=='text'): ?>
<ul>
<?php foreach($freq as $label=>$n): ?>
<li><?=h($label)?>：<?=h($n)?>件</li>
<?php endforeach; ?>
</ul>
<?php else: ?>
<?php foreach($rows as $r): ?>
<?php $v=$r['answers'][$q['id']]??''; ?>
<?php if((string)$v!==''): ?>
<p><?=nl2br(h((string)$v))?></p>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<?php endif; ?>
</div></div>

<div class="card"><div class="card-head"><h2>個別回答</h2></div>
<div class="card-body table-wrap">
<?php if($rows): ?>
<table>
<thead><tr><th>日時</th><th>回答</th></tr></thead>
<tbody>
<?php foreach($rows as $r): ?>
<tr>
<td><?=h($r['createdAt']??'')?></td>
<td>
<?php foreach($r['answers']??[] as $qid=>$v): ?>
<div>
<strong><?=h($qid)?></strong>：
<?=h(is_array($v)?implode(', ',$v):(string)$v)?>
</div>
<?php endforeach; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div></div>

</div></div>
<?php
    foot();
}

/* ============================================================
 * 回答
 * ============================================================ */

function render_answer(array $s): void {
    recalc($s);
    $draft=is_array($_SESSION['answer_draft']??null)
        ? $_SESSION['answer_draft']
        : [];

    head('アンケート回答',false);
?>
<div class="answer-shell">
<div class="title">
<div>
<h1><?=h($s['title'])?></h1>
<p><?=nl2br(h($s['description']))?></p>
</div>
</div>

<form method="post">
<input type="hidden" name="action" value="answer_next">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">

<?php foreach($s['groups'] as $g): ?>
<div class="card"><div class="card-body">
<h2><?=h($g['title'])?></h2>

<?php foreach($g['questions'] as $q): ?>
<div class="preview-q"
 data-qid="<?=h($q['id'])?>"
 data-type="<?=h($q['type'])?>">

<strong><?=h($q['number'])?> <?=h($q['text'])?></strong>
<?php if($q['required']): ?><span class="badge warn">必須</span><?php endif; ?>

<?php if($q['type']==='text'): ?>
<textarea name="answer[<?=h($q['id'])?>]"
 placeholder="入力してください"><?=h($draft[$q['id']]??'')?></textarea>
<?php elseif($q['type']==='single'): ?>
<?php foreach($q['options'] as $o): ?>
<label class="check">
<input type="radio"
 name="answer[<?=h($q['id'])?>]"
 value="<?=h($o['label'])?>"
 <?=($draft[$q['id']]??'')===$o['label']?'checked':''?>>
<?=h($o['label'])?>
</label>
<?php endforeach; ?>
<?php else: ?>
<?php foreach($q['options'] as $o): ?>
<label class="check">
<input type="checkbox"
 name="answer[<?=h($q['id'])?>][]"
 value="<?=h($o['label'])?>"
 <?=in_array($o['label'],(array)($draft[$q['id']]??[]),true)?'checked':''?>>
<?=h($o['label'])?>
</label>
<?php endforeach; ?>
<?php endif; ?>

</div>
<?php endforeach; ?>
</div></div>
<?php endforeach; ?>

<div class="row">
<button class="btn btn-primary">回答を確認する</button>
</div>
</form>
</div>
<?php
    foot();
}

/* ============================================================
 * 確認
 * ============================================================ */

function render_confirm(array $s): void {
    recalc($s);
    $draft=is_array($_SESSION['answer_draft']??null)
        ? $_SESSION['answer_draft']
        : [];

    head('回答確認',false);
?>
<div class="answer-shell">
<div class="title">
<div><h1>回答確認</h1><p><?=h($s['title'])?></p></div>
</div>

<div class="card"><div class="card-body">
<?php foreach($s['groups'] as $g): ?>
<h2><?=h($g['title'])?></h2>

<?php foreach($g['questions'] as $q): ?>
<?php $v=$draft[$q['id']]??''; ?>
<div class="preview-q">
<strong><?=h($q['number'])?> <?=h($q['text'])?></strong>
<p><?=nl2br(h(is_array($v)?implode(', ',$v):(string)$v))?></p>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<div class="row" style="margin-top:20px">
<form method="post">
<input type="hidden" name="action" value="answer_back">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">
<button class="btn btn-secondary">修正する</button>
</form>

<form method="post" data-confirm="回答を送信します。よろしいですか？">
<input type="hidden" name="action" value="submit_answer">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">
<button class="btn btn-primary">回答を送信する</button>
</form>
</div>
</div></div>
</div>
<?php
    foot();
}

/* ============================================================
 * 完了
 * ============================================================ */

function render_complete(array $s): void {
    head('回答完了',false);
?>
<div class="answer-shell">
<div class="card"><div class="card-body" style="text-align:center;padding:55px 20px">
<h1>回答ありがとうございました</h1>
<p>「<?=h($s['title'])?>」への回答を受け付けました。</p>
</div></div>
</div>
<?php
    foot();
}

/* ============================================================
 * CSV
 * ============================================================ */

function export_csv(array $s,array $answers): never {
    $rows=array_values(array_filter(
        $answers,
        fn($a)=>
            ($a['survey_id']??'')===$s['id']
    ));

    $questions=[];
    foreach($s['groups'] as $g){
        foreach($g['questions'] as $q){
            $questions[]=$q;
        }
    }

    $fp=fopen('php://output','wb');

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        rawurlencode($s['id']) . '.csv"'
    );

    fwrite($fp,"\xEF\xBB\xBF");

    fputcsv(
        $fp,
        array_merge(['回答ID','日時'],array_column($questions,'number')),
        ',',
        '"',
        '\\'
    );

    foreach($rows as $r){
        $line=[
            $r['id']??'',
            $r['createdAt']??''
        ];

        foreach($questions as $q){
            $v=$r['answers'][$q['id']]??'';
            $line[]=is_array($v)?implode(', ',$v):(string)$v;
        }

        fputcsv($fp,$line,',','"','\\');
    }

    fclose($fp);
    exit;
}

/* ============================================================
 * メイン
 * ============================================================ */

try {
    boot();

    $d=data();
    $set=settings();

    if(refresh_statuses($d)){
        save_json(DATA_FILE,$d);
    }

    $post=handle_post($d,$set);

    /*
     * POST後は必ず最新状態を再読込する。
     */
    $d=data();
    $set=settings();

    if(refresh_statuses($d)){
        save_json(DATA_FILE,$d);
    }

    $screen=(string)($post['screen']??gs('screen','list'));
    $sid=(string)($post['id']??gs('id'));

    /*
     * 回答者系画面は管理者ヘッダーを絶対に表示しない。
     */
    if(in_array($screen,['answer','confirm','complete'],true)){
        $s=survey($d,$sid);

        if($s===null){
            head('アンケート',false);
            ?>
            <div class="answer-shell">
            <div class="alert err">アンケートが見つかりません。</div>
            </div>
            <?php
            foot();
            exit;
        }

        if($screen==='answer')render_answer($s);
        elseif($screen==='confirm')render_confirm($s);
        else render_complete($s);
        exit;
    }

    /*
     * CSVは対象アンケートを固定する。
     */
    if($screen==='csv'){
        $s=survey($d,$sid);

        if($s===null)throw new RuntimeException('対象アンケートが見つかりません。');

        export_csv($s,$d['answers']);
    }

    switch($screen){

        case 'edit':
            if($sid==='new'){
                $s=[
                    'id'=>id('survey'),
                    'title'=>'',
                    'description'=>'',
                    'startAt'=>date('Y-m-d\TH:i'),
                    'endAt'=>date('Y-m-d\TH:i',strtotime('+30 days')),
                    'status'=>'draft',
                    'numbering'=>'global',
                    'createdAt'=>now(),
                    'updatedAt'=>now(),
                    'groups'=>[[
                        'id'=>id('group'),
                        'title'=>'基本アンケート',
                        'questions'=>[]
                    ]]
                ];
            }else{
                $s=survey($d,$sid);
                if($s===null){
                    flash('error','アンケートが見つかりません。');
                    render_list($d);
                    exit;
                }
            }

            render_edit($s);
            break;

        case 'preview':
            $s=survey($d,$sid);

            if($s===null){
                flash('error','アンケートが見つかりません。');
                render_list($d);
                exit;
            }

            render_preview($s);
            break;

        case 'send':
            $s=survey($d,$sid);

            if($s===null){
                flash('error','対象アンケートが見つかりません。');
                render_list($d);
                exit;
            }

            $history=array_values(array_filter(
                $d['send_history'],
                fn($x)=>($x['survey_id']??'')===$sid
            ));

            render_send($s,$d['customers'],$history);
            break;

        case 'analytics':
            $s=survey($d,$sid);

            if($s===null){
                flash('error','対象アンケートが見つかりません。');
                render_list($d);
                exit;
            }

            render_analytics($s,$d['answers'],$d['customers']);
            break;

        case 'kintone':
            render_kintone($set['kintone']);
            break;

        case 'mail':
            render_mail($set['mail']);
            break;

        case 'list':
        default:
            render_list($d);
            break;
    }

} catch(Throwable $e) {
    /*
     * 白画面にしない。
     * 認証情報・パスワードは例外文字列へ混入させない。
     */
    http_response_code(500);

    head('エラー');
    ?>
    <div class="page"><div class="container">
    <div class="alert err">
    処理中にエラーが発生しました。
    <br>
    <?=h($e->getMessage())?>
    </div>
    </div></div>
    <?php
    foot();
}

<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * PHP 8.5 / Apache 2.4
 * DBなし / PHP cURLなし
 *
 * index.php 単一エントリーポイント
 * データ: _data/data.json
 * 設定: _data/settings.json
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . '/_data';
const DATA_FILE = DATA_DIR . '/data.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
const KEY_FILE = DATA_DIR . '/.secret';

const TYPES = ['single','multiple','text'];
const STATUSES = ['draft','published','stopped','ended'];

const MAX_TITLE = 200;
const MAX_DESC = 5000;
const MAX_Q = 1000;
const MAX_OPTION = 500;

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

function ps(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function gs(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function pb(string $key): bool
{
    return isset($_POST[$key]) &&
        in_array((string)$_POST[$key], ['1','on','true'], true);
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function url(array $params = []): string
{
    $base = $_SERVER['SCRIPT_NAME'] ?? 'index.php';
    return $params
        ? $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
        : $base;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = compact('type','message');
}

function flash_get(): ?array
{
    $v = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($v) ? $v : null;
}

/* =========================================================
 * 初期データ
 * ========================================================= */

function defaults(): array
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
                'questions' => [[
                    'id' => 'question-001',
                    'number' => 'Q1',
                    'text' => 'サービスの満足度を教えてください。',
                    'type' => 'single',
                    'required' => true,
                    'options' => [
                        ['id'=>uid('option'),'label'=>'非常に満足','nextQuestionId'=>''],
                        ['id'=>uid('option'),'label'=>'満足','nextQuestionId'=>''],
                        ['id'=>uid('option'),'label'=>'普通','nextQuestionId'=>''],
                        ['id'=>uid('option'),'label'=>'不満','nextQuestionId'=>''],
                    ],
                ],[
                    'id' => 'question-002',
                    'number' => 'Q2',
                    'text' => 'ご意見・ご要望があれば入力してください。',
                    'type' => 'text',
                    'required' => false,
                    'options' => [],
                ]],
            ]],
        ]],
        'answers' => [],
        'customers' => [],
        'send_history' => [],
    ];
}

function default_settings(): array
{
    return [
        'kintone' => [
            'subdomain'=>'',
            'app_id'=>'',
            'username'=>'',
            'password'=>'',
            'proxy'=>'',
            'verify_ssl'=>false,
            'fields'=>[],
            'mapping'=>[
                'organization'=>'',
                'name'=>'',
                'email'=>'',
                'department'=>'',
                'phone'=>'',
                'address'=>[],
            ],
            'last_test'=>null,
            'last_sync'=>null,
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
            'last_test'=>null,
        ],
    ];
}

/* =========================================================
 * JSON
 * ========================================================= */

function ensure_storage(): void
{
    if (!is_dir(DATA_DIR) &&
        !mkdir(DATA_DIR, 0775, true) &&
        !is_dir(DATA_DIR)) {
        throw new RuntimeException('データフォルダを作成できません。');
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, defaults());
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json(SETTINGS_FILE, default_settings());
    }
}

function load_json(string $file, array $fallback): array
{
    if (!is_file($file)) return $fallback;

    $fp = @fopen($file, 'rb');
    if (!$fp) return $fallback;

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

function save_json(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('JSON化に失敗しました。');
    }

    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('ファイルをロックできません。');
        }

        if (fwrite($fp, $json) === false || !fflush($fp)) {
            throw new RuntimeException('データを書き込めません。');
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            throw new RuntimeException('データを更新できません。');
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

function data(): array
{
    $d = load_json(DATA_FILE, defaults());

    foreach (['surveys','answers','customers','send_history'] as $k) {
        if (!isset($d[$k]) || !is_array($d[$k])) {
            $d[$k] = [];
        }
    }

    return $d;
}

function settings(): array
{
    $d = default_settings();
    $s = load_json(SETTINGS_FILE, $d);

    $s['kintone'] = array_replace_recursive(
        $d['kintone'],
        is_array($s['kintone'] ?? null) ? $s['kintone'] : []
    );

    $s['mail'] = array_replace_recursive(
        $d['mail'],
        is_array($s['mail'] ?? null) ? $s['mail'] : []
    );

    return $s;
}

/* =========================================================
 * セッション
 * ========================================================= */

function start_app(): void
{
    ensure_storage();

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    $script = str_replace('\\','/',$_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $path = dirname($script);
    $path = ($path === '.' || $path === '/') ? '/' : rtrim($path,'/') . '/';

    session_name('survey_app_session');

    session_set_cookie_params([
        'lifetime'=>0,
        'path'=>$path,
        'secure'=>$https,
        'httponly'=>true,
        'samesite'=>'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('セッションを開始できません。');
    }
}

/* =========================================================
 * アンケート
 * ========================================================= */

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $i => $s) {
        if (($s['id'] ?? '') === $id) return $i;
    }
    return -1;
}

function survey(array $surveys, string $id): ?array
{
    $i = survey_index($surveys,$id);
    return $i >= 0 ? $surveys[$i] : null;
}

function refresh_status(array &$d): bool
{
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

function renumber(array &$s): void
{
    $g = 1;
    $q = 1;

    foreach ($s['groups'] as &$group) {
        $local = 1;

        foreach ($group['questions'] as &$question) {
            $question['number'] =
                ($s['numbering'] ?? 'global') === 'group'
                    ? "Q{$g}-{$local}"
                    : "Q{$q}";

            $local++;
            $q++;
        }

        unset($question);
        $g++;
    }

    unset($group);
}

function status_label(string $s): string
{
    return [
        'draft'=>'下書き',
        'published'=>'公開中',
        'stopped'=>'停止',
        'ended'=>'終了',
    ][$s] ?? '下書き';
}

function status_class(string $s): string
{
    return [
        'published'=>'ok',
        'stopped'=>'warn',
        'ended'=>'danger',
    ][$s] ?? 'gray';
}

function public_url(string $id): string
{
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    return ($https ? 'https' : 'http') . '://' .
        ($_SERVER['HTTP_HOST'] ?? 'localhost') .
        url(['screen'=>'answer','id'=>$id]);
}

/* =========================================================
 * アンケート入力
 * ========================================================= */

function build_survey_from_post(?array $old = null): array
{
    $title = ps('title');
    $desc = (string)($_POST['description'] ?? '');
    $start = ps('startAt');
    $end = ps('endAt');
    $numbering = ps('numbering','global');

    $errors = [];

    if ($title === '') $errors[] = 'タイトルを入力してください。';
    if (mb_strlen($title) > MAX_TITLE) $errors[] = 'タイトルが長すぎます。';
    if (mb_strlen($desc) > MAX_DESC) $errors[] = '説明が長すぎます。';

    if ($start !== '' && strtotime($start) === false) {
        $errors[] = '開始日時が不正です。';
    }

    if ($end !== '' && strtotime($end) === false) {
        $errors[] = '終了日時が不正です。';
    }

    if (!in_array($numbering,['global','group'],true)) {
        $numbering = 'global';
    }

    if ($errors) {
        throw new InvalidArgumentException(implode("\n",$errors));
    }

    $s = $old ?? [
        'id'=>uid('survey'),
        'status'=>'draft',
        'createdAt'=>now(),
        'groups'=>[],
    ];

    $s['title'] = $title;
    $s['description'] = $desc;
    $s['startAt'] = $start;
    $s['endAt'] = $end;
    $s['numbering'] = $numbering;
    $s['updatedAt'] = now();

    $groupOrder = $_POST['group_order'] ?? [];
    $groupTitles = $_POST['group_title'] ?? [];
    $qByGroup = $_POST['questions_by_group'] ?? [];
    $qText = $_POST['question_text'] ?? [];
    $qType = $_POST['question_type'] ?? [];
    $qReq = $_POST['question_required'] ?? [];
    $qOpt = $_POST['question_option'] ?? [];
    $qNext = $_POST['option_next'] ?? [];

    if (!is_array($groupOrder)) $groupOrder = [];

    $groups = [];
    $usedQ = [];

    foreach ($groupOrder as $gid) {
        $gid = trim((string)$gid);
        if ($gid === '') continue;

        $gt = trim((string)($groupTitles[$gid] ?? ''));
        if ($gt === '') $gt = '新しいグループ';

        $group = [
            'id'=>$gid,
            'title'=>mb_substr($gt,0,MAX_TITLE),
            'questions'=>[],
        ];

        $ids = $qByGroup[$gid] ?? [];
        if (!is_array($ids)) $ids = [];

        foreach ($ids as $qid) {
            $qid = trim((string)$qid);
            if ($qid === '' || isset($usedQ[$qid])) continue;
            $usedQ[$qid] = true;

            $type = (string)($qType[$qid] ?? 'single');
            if (!in_array($type,TYPES,true)) $type = 'single';

            $text = trim((string)($qText[$qid] ?? ''));

            if ($text === '') {
                $text = '質問文未入力';
            }

            $options = [];

            if ($type !== 'text') {
                $labels = $qOpt[$qid] ?? [];
                $next = $qNext[$qid] ?? [];

                if (!is_array($labels)) $labels = [];
                if (!is_array($next)) $next = [];

                foreach ($labels as $i=>$label) {
                    $label = trim((string)$label);
                    if ($label === '') continue;

                    $options[] = [
                        'id'=>uid('option'),
                        'label'=>mb_substr($label,0,MAX_OPTION),
                        'nextQuestionId'=>
                            $type === 'single'
                                ? trim((string)($next[$i] ?? ''))
                                : '',
                    ];
                }

                if (count($options) < 2) {
                    $options[] = [
                        'id'=>uid('option'),
                        'label'=>'',
                        'nextQuestionId'=>'',
                    ];
                }
            }

            $group['questions'][] = [
                'id'=>$qid,
                'number'=>'',
                'text'=>mb_substr($text,0,MAX_Q),
                'type'=>$type,
                'required'=>isset($qReq[$qid]),
                'options'=>$options,
            ];
        }

        $groups[] = $group;
    }

    if (!$groups) {
        $groups[] = [
            'id'=>uid('group'),
            'title'=>'基本アンケート',
            'questions'=>[],
        ];
    }

    $s['groups'] = $groups;
    renumber($s);

    return $s;
}

/* =========================================================
 * kintone
 * ========================================================= */

function ksub(string $v): string
{
    $v = trim($v);
    $v = preg_replace('#^https?://#i','',$v) ?? $v;
    $v = rtrim($v,'/');

    if (str_ends_with(strtolower($v),'.cybozu.com')) {
        $v = substr($v,0,-strlen('.cybozu.com'));
    }

    return $v;
}

function validate_k(array $c,bool $password = true): array
{
    $e = [];
    $sub = ksub((string)($c['subdomain'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/',$sub)) {
        $e[] = 'kintoneサブドメインが不正です。';
    }

    if (!ctype_digit((string)($c['app_id'] ?? '')) ||
        (int)$c['app_id'] < 1) {
        $e[] = 'アプリIDが不正です。';
    }

    if (trim((string)($c['username'] ?? '')) === '') {
        $e[] = 'ログイン名を入力してください。';
    }

    if ($password && trim((string)($c['password'] ?? '')) === '') {
        $e[] = 'パスワードを入力してください。';
    }

    if (
        ($c['proxy'] ?? '') !== '' &&
        !preg_match('/^[^:\s]+:\d{1,5}$/',(string)$c['proxy'])
    ) {
        $e[] = 'Proxyはhost:port形式で入力してください。';
    }

    return $e;
}

function krequest(
    array $c,
    string $method,
    string $path,
    ?array $body = null
): array {
    $e = validate_k($c,true);
    if ($e) throw new RuntimeException(implode("\n",$e));

    $sub = ksub((string)$c['subdomain']);

    $url =
        'https://' . $sub . '.cybozu.com' . $path;

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                (string)$c['username'] . ':' . (string)$c['password']
            ),
        'Accept: application/json',
        'Connection: close',
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException('kintone JSON生成失敗。');
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $o = [
        'http'=>[
            'method'=>$method,
            'header'=>implode("\r\n",$headers),
            'content'=>$content,
            'ignore_errors'=>true,
            'timeout'=>30,
            'follow_location'=>0,
            'max_redirects'=>0,
        ],
        'ssl'=>[
            'verify_peer'=>(bool)$c['verify_ssl'],
            'verify_peer_name'=>(bool)$c['verify_ssl'],
            'allow_self_signed'=>!(bool)$c['verify_ssl'],
            'SNI_enabled'=>true,
            'peer_name'=>$sub.'.cybozu.com',
        ],
    ];

    if (!empty($c['proxy'])) {
        [$host,$port] = explode(':',(string)$c['proxy'],2);
        $o['http']['proxy'] = 'tcp://' . $host . ':' . (int)$port;
        $o['http']['request_fulluri'] = true;
    }

    $ctx = stream_context_create($o);
    $bodyRaw = @file_get_contents($url,false,$ctx);

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/',$header,$m)) {
            $status = (int)$m[1];
        }
    }

    if ($bodyRaw === false) {
        throw new RuntimeException('kintoneへの接続に失敗しました。');
    }

    $json = json_decode($bodyRaw,true);

    if ($status < 200 || $status >= 300) {
        $msg = is_array($json)
            ? (($json['code'] ?? '') . ' ' . ($json['message'] ?? ''))
            : '';

        throw new RuntimeException(
            'kintone HTTP ' . $status . ' ' . trim($msg)
        );
    }

    return [
        'status'=>$status,
        'body'=>is_array($json) ? $json : [],
    ];
}

function kfields(array $c): array
{
    $r = krequest(
        $c,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
            rawurlencode((string)$c['app_id'])
    );

    $result = [];

    foreach (($r['body']['properties'] ?? []) as $code=>$f) {
        if (!is_array($f)) continue;

        $result[] = [
            'code'=>(string)$code,
            'label'=>(string)($f['label'] ?? $code),
            'type'=>(string)($f['type'] ?? ''),
        ];
    }

    usort(
        $result,
        fn($a,$b)=>strnatcasecmp($a['code'],$b['code'])
    );

    return $result;
}

function krecords(array $c): array
{
    return krequest(
        $c,
        'GET',
        '/k/v1/records.json?app=' .
            rawurlencode((string)$c['app_id']) .
            '&totalCount=true'
    )['body']['records'] ?? [];
}

function record_value(array $r,string $code): string
{
    $v = $r[$code]['value'] ?? '';

    if (is_array($v)) {
        $a = [];

        foreach ($v as $x) {
            if (is_array($x)) {
                $a[] = $x['name'] ?? $x['value'] ?? '';
            } else {
                $a[] = $x;
            }
        }

        return implode(' ',array_filter($a,fn($x)=>(string)$x!==''));
    }

    return (string)$v;
}

/* =========================================================
 * SMTP
 * ========================================================= */

function mail_validate(array $c): array
{
    $e = [];

    if (trim((string)($c['host'] ?? '')) === '') {
        $e[] = 'SMTPサーバを入力してください。';
    }

    $port = (int)($c['port'] ?? 0);
    if ($port < 1 || $port > 65535) {
        $e[] = 'SMTPポートが不正です。';
    }

    if (!in_array($c['encryption'] ?? '',['ssl','tls','none'],true)) {
        $e[] = '暗号化方式が不正です。';
    }

    if (!filter_var($c['from_email'] ?? '',FILTER_VALIDATE_EMAIL)) {
        $e[] = '送信元メールアドレスが不正です。';
    }

    if (
        ($c['reply_to'] ?? '') !== '' &&
        !filter_var($c['reply_to'],FILTER_VALIDATE_EMAIL)
    ) {
        $e[] = '返信先メールアドレスが不正です。';
    }

    return $e;
}

function smtp_socket(array $c)
{
    $e = mail_validate($c);
    if ($e) throw new RuntimeException(implode("\n",$e));

    $host = (string)$c['host'];
    $port = (int)$c['port'];

    if ($c['encryption'] === 'ssl') {
        $host = 'ssl://' . $host;
    }

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
        throw new RuntimeException(
            'SMTP接続失敗: ' . $errstr
        );
    }

    stream_set_timeout($fp,15);

    smtp_cmd($fp,null,[220]);

    if ($c['encryption'] === 'tls') {
        smtp_cmd($fp,'EHLO localhost',[250]);
        smtp_cmd($fp,'STARTTLS',[220]);

        if (!stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {
            fclose($fp);
            throw new RuntimeException('TLS接続に失敗しました。');
        }

        smtp_cmd($fp,'EHLO localhost',[250]);
    } else {
        smtp_cmd($fp,'EHLO localhost',[250]);
    }

    if (!empty($c['auth'])) {
        smtp_cmd($fp,'AUTH LOGIN',[334]);
        smtp_cmd($fp,base64_encode((string)$c['username']),[334]);
        smtp_cmd($fp,base64_encode((string)$c['password']),[235]);
    }

    return $fp;
}

function smtp_cmd($fp,?string $command,array $codes): string
{
    if ($command !== null) {
        fwrite($fp,$command . "\r\n");
    }

    $response = '';

    while (!feof($fp)) {
        $line = fgets($fp,4096);
        if ($line === false) break;

        $response .= $line;

        if (preg_match('/^\d{3} /',$line)) break;
    }

    $code = (int)substr(trim($response),0,3);

    if (!in_array($code,$codes,true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . trim($response)
        );
    }

    return $response;
}

function mime_header(string $v): string
{
    return function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($v,'UTF-8','B')
        : $v;
}

function smtp_send(array $c,string $to,string $subject,string $body): void
{
    if (!filter_var($to,FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('送信先メールアドレスが不正です。');
    }

    $fp = smtp_socket($c);

    try {
        $from = (string)$c['from_email'];

        smtp_cmd($fp,'MAIL FROM:<'.$from.'>',[250]);
        smtp_cmd($fp,'RCPT TO:<'.$to.'>',[250,251]);
        smtp_cmd($fp,'DATA',[354]);

        $headers = [
            'Date: '.date(DATE_RFC2822),
            'From: '.mime_header((string)($c['from_name'] ?: $from)).
                ' <'.$from.'>',
            'To: <'.$to.'>',
            'Subject: '.mime_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (!empty($c['reply_to'])) {
            $headers[] = 'Reply-To: '.$c['reply_to'];
        }

        $body = str_replace(["\r\n","\r"],"\n",$body);
        $body = preg_replace('/^\./m','..',$body) ?? $body;

        smtp_cmd(
            $fp,
            implode("\r\n",$headers).
            "\r\n\r\n".
            str_replace("\n","\r\n",$body).
            "\r\n.",
            [250]
        );

        smtp_cmd($fp,'QUIT',[221]);
    } finally {
        fclose($fp);
    }
}

/* =========================================================
 * 暗号化設定
 * ========================================================= */

function secret_key(): string
{
    if (!is_file(KEY_FILE)) {
        $key = random_bytes(32);
        file_put_contents(KEY_FILE,base64_encode($key),LOCK_EX);
        @chmod(KEY_FILE,0600);
    } else {
        $key = base64_decode(
            (string)file_get_contents(KEY_FILE),
            true
        );
    }

    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('秘密鍵を利用できません。');
    }

    return $key;
}

function secret_encrypt(string $value): string
{
    if ($value === '') return '';

    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $value,
        'aes-256-gcm',
        secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException('秘密情報を暗号化できません。');
    }

    return base64_encode($iv.$tag.$cipher);
}

function secret_decrypt(string $value): string
{
    if ($value === '') return '';

    $raw = base64_decode($value,true);

    if ($raw === false || strlen($raw) < 28) {
        return '';
    }

    $iv = substr($raw,0,12);
    $tag = substr($raw,12,16);
    $cipher = substr($raw,28);

    $v = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return is_string($v) ? $v : '';
}

/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(array &$d,array &$s): ?array
{
    $action = ps('action');

    if ($action === '') return null;

    try {
        switch ($action) {

        /* -----------------------------------------
         * アンケート保存
         * ----------------------------------------- */
        case 'save_survey':
            $id = ps('survey_id');
            $idx = survey_index($d['surveys'],$id);

            $old = $idx >= 0 ? $d['surveys'][$idx] : null;
            $new = build_survey_from_post($old);

            if ($idx >= 0) {
                $d['surveys'][$idx] = $new;
            } else {
                $d['surveys'][] = $new;
            }

            save_json(DATA_FILE,$d);

            flash('success','アンケートを保存しました。');
            return ['screen'=>'list'];

        /* -----------------------------------------
         * 状態変更
         * ----------------------------------------- */
        case 'change_status':
            $id = ps('survey_id');
            $next = ps('next_status');
            $idx = survey_index($d['surveys'],$id);

            if ($idx < 0) throw new RuntimeException('アンケートが見つかりません。');

            $cur = $d['surveys'][$idx]['status'] ?? 'draft';

            $allowed = [
                'draft'=>['published'],
                'published'=>['stopped'],
                'stopped'=>['published'],
                'ended'=>[],
            ];

            if (!in_array($next,$allowed[$cur] ?? [],true)) {
                throw new RuntimeException('許可されていない状態変更です。');
            }

            $d['surveys'][$idx]['status'] = $next;
            $d['surveys'][$idx]['updatedAt'] = now();
            save_json(DATA_FILE,$d);

            flash('success','状態を変更しました。');
            return ['screen'=>'edit','id'=>$id];

        /* -----------------------------------------
         * 複製
         * ----------------------------------------- */
        case 'duplicate_survey':
            $id = ps('survey_id');
            $src = survey($d['surveys'],$id);

            if (!$src) throw new RuntimeException('アンケートが見つかりません。');

            $src['id'] = uid('survey');
            $src['title'] .= '（複製）';
            $src['status'] = 'draft';
            $src['createdAt'] = now();
            $src['updatedAt'] = now();

            foreach ($src['groups'] as &$g) {
                $g['id'] = uid('group');

                foreach ($g['questions'] as &$q) {
                    $q['id'] = uid('question');

                    foreach ($q['options'] as &$o) {
                        $o['id'] = uid('option');
                        $o['nextQuestionId'] = '';
                    }
                    unset($o);
                }
                unset($q);
            }
            unset($g);

            renumber($src);
            $d['surveys'][] = $src;
            save_json(DATA_FILE,$d);

            flash('success','アンケートを複製しました。');
            return ['screen'=>'list'];

        /* -----------------------------------------
         * 削除
         * ----------------------------------------- */
        case 'delete_survey':
            $id = ps('survey_id');
            $idx = survey_index($d['surveys'],$id);

            if ($idx < 0) throw new RuntimeException('アンケートが見つかりません。');

            array_splice($d['surveys'],$idx,1);
            save_json(DATA_FILE,$d);

            flash('success','アンケートを削除しました。');
            return ['screen'=>'list'];

        /* -----------------------------------------
         * 回答 → 確認
         * ----------------------------------------- */
        case 'answer_next':
            $id = ps('survey_id');
            $sv = survey($d['surveys'],$id);

            if (!$sv) throw new RuntimeException('アンケートが見つかりません。');

            $answers = [];
            $visible = visible_questions($sv);

            foreach ($visible as $q) {
                $qid = $q['id'];

                if ($q['type'] === 'multiple') {
                    $v = $_POST['answer'][$qid] ?? [];
                    $v = is_array($v) ? array_values(array_map('strval',$v)) : [];
                } else {
                    $v = (string)($_POST['answer'][$qid] ?? '');
                }

                if (
                    $q['required'] &&
                    (
                        $v === '' ||
                        (is_array($v) && !$v)
                    )
                ) {
                    throw new RuntimeException(
                        $q['number'].' は必須です。'
                    );
                }

                $answers[$qid] = $v;
            }

            $_SESSION['answer_draft'] = $answers;
            $_SESSION['answer_survey'] = $id;

            return ['screen'=>'confirm','id'=>$id];

        /* -----------------------------------------
         * 回答修正
         * ----------------------------------------- */
        case 'answer_back':
            return [
                'screen'=>'answer',
                'id'=>ps('survey_id'),
            ];

        /* -----------------------------------------
         * 回答送信
         * ----------------------------------------- */
        case 'submit_answer':
            $id = ps('survey_id');

            if (
                ($_SESSION['answer_survey'] ?? '') !== $id
            ) {
                throw new RuntimeException('回答セッションが無効です。');
            }

            $sv = survey($d['surveys'],$id);
            if (!$sv) throw new RuntimeException('アンケートが見つかりません。');

            $d['answers'][] = [
                'id'=>uid('answer'),
                'survey_id'=>$id,
                'answers'=>is_array($_SESSION['answer_draft'] ?? null)
                    ? $_SESSION['answer_draft']
                    : [],
                'createdAt'=>now(),
            ];

            unset(
                $_SESSION['answer_draft'],
                $_SESSION['answer_survey']
            );

            save_json(DATA_FILE,$d);

            return ['screen'=>'complete','id'=>$id];

        /* -----------------------------------------
         * kintone保存
         * ----------------------------------------- */
        case 'save_kintone':
            $old = $s['kintone'];

            $password = ps('password');
            if ($password === '') {
                $password = secret_decrypt(
                    (string)($old['password'] ?? '')
                );
            }

            $c = [
                'subdomain'=>ksub(ps('subdomain')),
                'app_id'=>ps('app_id'),
                'username'=>ps('username'),
                'password'=>$password,
                'proxy'=>ps('proxy'),
                'verify_ssl'=>pb('verify_ssl'),
                'fields'=>$old['fields'] ?? [],
                'mapping'=>$old['mapping'] ?? [],
                'last_test'=>$old['last_test'] ?? null,
                'last_sync'=>$old['last_sync'] ?? null,
            ];

            $e = validate_k($c,true);
            if ($e) throw new RuntimeException(implode("\n",$e));

            $c['password'] = secret_encrypt($password);
            $s['kintone'] = $c;

            save_json(SETTINGS_FILE,$s);

            flash('success','kintone設定を保存しました。');
            return ['screen'=>'kintone'];

        /* -----------------------------------------
         * kintone接続テスト
         * ----------------------------------------- */
        case 'test_kintone':
            $old = $s['kintone'];
            $password = ps('password');

            if ($password === '') {
                $password = secret_decrypt((string)$old['password']);
            }

            $c = [
                'subdomain'=>ksub(ps('subdomain')),
                'app_id'=>ps('app_id'),
                'username'=>ps('username'),
                'password'=>$password,
                'proxy'=>ps('proxy'),
                'verify_ssl'=>pb('verify_ssl'),
            ];

            krequest(
                $c,
                'GET',
                '/k/v1/app.json?id='.rawurlencode($c['app_id'])
            );

            $s['kintone']['last_test'] = now();
            save_json(SETTINGS_FILE,$s);

            flash('success','kintone接続テストに成功しました。');
            return ['screen'=>'kintone'];

        /* -----------------------------------------
         * kintone項目取得
         * ----------------------------------------- */
        case 'get_kintone_fields':
            $old = $s['kintone'];
            $password = ps('password');

            if ($password === '') {
                $password = secret_decrypt((string)$old['password']);
            }

            $c = [
                'subdomain'=>ksub(ps('subdomain')),
                'app_id'=>ps('app_id'),
                'username'=>ps('username'),
                'password'=>$password,
                'proxy'=>ps('proxy'),
                'verify_ssl'=>pb('verify_ssl'),
            ];

            $fields = kfields($c);

            $s['kintone']['password'] = secret_encrypt($password);
            $s['kintone']['subdomain'] = $c['subdomain'];
            $s['kintone']['app_id'] = $c['app_id'];
            $s['kintone']['username'] = $c['username'];
            $s['kintone']['proxy'] = $c['proxy'];
            $s['kintone']['verify_ssl'] = $c['verify_ssl'];
            $s['kintone']['fields'] = $fields;

            save_json(SETTINGS_FILE,$s);

            flash(
                'success',
                count($fields).'件のkintone項目を取得しました。'
            );

            return ['screen'=>'kintone'];

        /* -----------------------------------------
         * kintoneマッピング
         * ----------------------------------------- */
        case 'save_kintone_mapping':
            $valid = [];

            foreach ($s['kintone']['fields'] ?? [] as $f) {
                if (isset($f['code'])) $valid[] = (string)$f['code'];
            }

            $mapping = [
                'organization'=>ps('mapping_organization'),
                'name'=>ps('mapping_name'),
                'email'=>ps('mapping_email'),
                'department'=>ps('mapping_department'),
                'phone'=>ps('mapping_phone'),
                'address'=>[],
            ];

            foreach ($_POST['mapping_address'] ?? [] as $v) {
                if (in_array((string)$v,$valid,true)) {
                    $mapping['address'][] = (string)$v;
                }
            }

            $s['kintone']['mapping'] = $mapping;
            save_json(SETTINGS_FILE,$s);

            flash('success','kintone項目マッピングを保存しました。');
            return ['screen'=>'kintone'];

        /* -----------------------------------------
         * 顧客同期
         * ----------------------------------------- */
        case 'sync_kintone':
            $k = $s['kintone'];

            $password = secret_decrypt((string)$k['password']);

            $c = [
                'subdomain'=>$k['subdomain'],
                'app_id'=>$k['app_id'],
                'username'=>$k['username'],
                'password'=>$password,
                'proxy'=>$k['proxy'],
                'verify_ssl'=>$k['verify_ssl'],
            ];

            $records = krecords($c);
            $m = $k['mapping'];

            $customers = [];

            foreach ($records as $r) {
                $name = record_value($r,$m['name'] ?? '');
                $email = record_value($r,$m['email'] ?? '');

                if ($name === '' && $email === '') continue;

                $customers[] = [
                    'id'=>uid('customer'),
                    'organization'=>record_value($r,$m['organization'] ?? ''),
                    'name'=>$name,
                    'email'=>$email,
                    'department'=>record_value($r,$m['department'] ?? ''),
                    'phone'=>record_value($r,$m['phone'] ?? ''),
                    'address'=>implode(
                        ' ',
                        array_filter(
                            array_map(
                                fn($code)=>record_value($r,$code),
                                $m['address'] ?? []
                            )
                        )
                    ),
                ];
            }

            $d['customers'] = $customers;
            $s['kintone']['last_sync'] = now();

            save_json(DATA_FILE,$d);
            save_json(SETTINGS_FILE,$s);

            flash(
                'success',
                count($customers).'件の顧客情報を同期しました。'
            );

            return ['screen'=>'kintone'];

        /* -----------------------------------------
         * SMTP保存
         * ----------------------------------------- */
        case 'save_mail':
            $old = $s['mail'];
            $password = ps('password');

            if ($password === '') {
                $password = secret_decrypt(
                    (string)($old['password'] ?? '')
                );
            }

            $c = [
                'host'=>ps('server'),
                'port'=>(int)ps('port','587'),
                'encryption'=>ps('encryption','tls'),
                'auth'=>pb('auth'),
                'username'=>ps('username'),
                'password'=>$password,
                'from_email'=>ps('from_email'),
                'from_name'=>ps('from_name'),
                'reply_to'=>ps('reply_to'),
                'last_test'=>$old['last_test'] ?? null,
            ];

            $e = mail_validate($c);
            if ($e) throw new RuntimeException(implode("\n",$e));

            $c['password'] = secret_encrypt($password);

            $s['mail'] = $c;
            save_json(SETTINGS_FILE,$s);

            flash('success','SMTP設定を保存しました。');
            return ['screen'=>'mail'];

        /* -----------------------------------------
         * SMTPテスト
         * ----------------------------------------- */
        case 'test_mail':
            $old = $s['mail'];
            $password = ps('password');

            if ($password === '') {
                $password = secret_decrypt(
                    (string)($old['password'] ?? '')
                );
            }

            $c = [
                'host'=>ps('server'),
                'port'=>(int)ps('port','587'),
                'encryption'=>ps('encryption','tls'),
                'auth'=>pb('auth'),
                'username'=>ps('username'),
                'password'=>$password,
                'from_email'=>ps('from_email'),
                'from_name'=>ps('from_name'),
                'reply_to'=>ps('reply_to'),
            ];

            $fp = smtp_socket($c);
            smtp_cmd($fp,'QUIT',[221]);
            fclose($fp);

            $s['mail']['last_test'] = now();
            save_json(SETTINGS_FILE,$s);

            flash('success','SMTP接続テストに成功しました。');
            return ['screen'=>'mail'];

        /* -----------------------------------------
         * テストメール
         * ----------------------------------------- */
        case 'test_mail_send':
            $m = $s['mail'];
            $password = secret_decrypt((string)$m['password']);

            $c = [
                'host'=>$m['host'],
                'port'=>(int)$m['port'],
                'encryption'=>$m['encryption'],
                'auth'=>$m['auth'],
                'username'=>$m['username'],
                'password'=>$password,
                'from_email'=>$m['from_email'],
                'from_name'=>$m['from_name'],
                'reply_to'=>$m['reply_to'],
            ];

            smtp_send(
                $c,
                ps('test_email'),
                'アンケートアプリ テストメール',
                'SMTP接続テストメールです。'
            );

            flash('success','テストメールを送信しました。');
            return ['screen'=>'mail'];

        /* -----------------------------------------
         * 一括送信
         * ----------------------------------------- */
        case 'send_mail':
            $id = ps('survey_id');
            $sv = survey($d['surveys'],$id);

            if (!$sv) throw new RuntimeException('アンケートが見つかりません。');

            $selected = $_POST['customers'] ?? [];
            if (!is_array($selected) || !$selected) {
                throw new RuntimeException('送信先を選択してください。');
            }

            $m = $s['mail'];
            $password = secret_decrypt((string)$m['password']);

            $mailConfig = [
                'host'=>$m['host'],
                'port'=>(int)$m['port'],
                'encryption'=>$m['encryption'],
                'auth'=>$m['auth'],
                'username'=>$m['username'],
                'password'=>$password,
                'from_email'=>$m['from_email'],
                'from_name'=>$m['from_name'],
                'reply_to'=>$m['reply_to'],
            ];

            $subject = ps('subject');
            $body = (string)($_POST['body'] ?? '');

            $sent = 0;

            foreach ($d['customers'] as $customer) {
                if (!in_array($customer['id'],$selected,true)) continue;

                $to = $customer['email'] ?? '';
                if (!filter_var($to,FILTER_VALIDATE_EMAIL)) {
                    $d['send_history'][] = [
                        'createdAt'=>now(),
                        'survey_id'=>$id,
                        'customer_name'=>$customer['name'] ?? '',
                        'type'=>'一括送信',
                        'result'=>'失敗: メールアドレス不正',
                    ];
                    continue;
                }

                $mailBody = str_replace(
                    ['{顧客名}','{アンケートURL}'],
                    [$customer['name'] ?? '',public_url($id)],
                    $body
                );

                try {
                    smtp_send($mailConfig,$to,$subject,$mailBody);

                    $d['send_history'][] = [
                        'createdAt'=>now(),
                        'survey_id'=>$id,
                        'customer_name'=>$customer['name'] ?? '',
                        'type'=>'一括送信',
                        'result'=>'成功',
                    ];

                    $sent++;
                } catch (Throwable $e) {
                    $d['send_history'][] = [
                        'createdAt'=>now(),
                        'survey_id'=>$id,
                        'customer_name'=>$customer['name'] ?? '',
                        'type'=>'一括送信',
                        'result'=>'失敗: '.$e->getMessage(),
                    ];
                }
            }

            save_json(DATA_FILE,$d);

            flash(
                $sent ? 'success' : 'error',
                $sent.'件送信しました。'
            );

            return ['screen'=>'send','id'=>$id];

        default:
            throw new RuntimeException('不明な操作です。');
        }
    } catch (Throwable $e) {
        flash('error',$e->getMessage());

        $screen = gs('screen','list');

        if ($action === 'save_survey') {
            return ['screen'=>'edit','id'=>ps('survey_id')];
        }

        if (in_array(
            $action,
            [
                'send_mail',
                'test_mail',
            ],
            true
        )) {
            return [
                'screen'=>'send',
                'id'=>ps('survey_id'),
            ];
        }

        if (str_contains($action,'kintone')) {
            return ['screen'=>'kintone'];
        }

        if (str_contains($action,'mail')) {
            return ['screen'=>'mail'];
        }

        return [
            'screen'=>$screen,
            'id'=>ps('survey_id'),
        ];
    }
}

/* =========================================================
 * 条件分岐
 * ========================================================= */

function all_questions(array $s): array
{
    $r = [];

    foreach ($s['groups'] as $g) {
        foreach ($g['questions'] as $q) {
            $r[] = $q;
        }
    }

    return $r;
}

function visible_questions(array $s): array
{
    $answers = $_SESSION['answer_draft'] ?? [];
    $result = [];

    foreach (all_questions($s) as $q) {
        if (!isset($q['id'])) continue;

        $show = true;

        foreach (all_questions($s) as $parent) {
            if (($parent['type'] ?? '') !== 'single') continue;

            foreach ($parent['options'] ?? [] as $o) {
                if (
                    ($o['nextQuestionId'] ?? '') === $q['id'] &&
                    isset($answers[$parent['id']]) &&
                    $answers[$parent['id']] !== $o['label']
                ) {
                    $show = false;
                }
            }
        }

        if ($show) $result[] = $q;
    }

    return $result;
}

/* =========================================================
 * 共通HTML
 * ========================================================= */

function head(string $title,bool $answerer=false): void
{
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> - <?=h(APP_TITLE)?></title>
<style>
:root{
 --primary:#2563eb;--dark:#1e293b;--text:#334155;
 --border:#cbd5e1;--bg:#f1f5f9;--white:#fff;
 --success:#16a34a;--warn:#d97706;--danger:#dc2626;
}
*{box-sizing:border-box}
body{
 margin:0;background:var(--bg);color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Noto Sans JP",sans-serif;line-height:1.6
}
header{
 background:var(--dark);color:#fff;padding:14px 20px
}
header a{color:#fff;text-decoration:none}
main{max-width:1200px;margin:24px auto;padding:0 16px}
.card{
 background:#fff;border:1px solid var(--border);
 border-radius:10px;margin-bottom:16px;overflow:hidden
}
.card-header{
 padding:14px 18px;border-bottom:1px solid var(--border);
 font-weight:700
}
.card-body{padding:18px}
.grid{
 display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
 gap:12px
}
label{display:block;font-weight:600;margin-bottom:10px}
input,textarea,select{
 width:100%;padding:9px 10px;border:1px solid var(--border);
 border-radius:6px;background:#fff;font:inherit
}
textarea{min-height:100px;resize:vertical}
button,.btn{
 display:inline-flex;align-items:center;justify-content:center;
 min-height:40px;padding:8px 14px;border-radius:7px;
 border:1px solid transparent;font:inherit;font-weight:600;
 cursor:pointer;text-decoration:none
}
.btn-primary{background:var(--primary);color:#fff}
.btn-secondary{background:#fff;color:var(--text);border-color:var(--border)}
.btn-success{background:var(--success);color:#fff}
.btn-warning{background:var(--warn);color:#fff}
.btn-danger{background:var(--danger);color:#fff}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.small{font-size:12px;color:#64748b}
.flash{
 padding:12px 16px;margin-bottom:16px;border-radius:8px;
 white-space:pre-line
}
.flash.success{background:#dcfce7;color:#166534}
.flash.error{background:#fee2e2;color:#991b1b}
.flash.warning{background:#fef3c7;color:#92400e}
table{width:100%;border-collapse:collapse}
th,td{padding:9px;border-bottom:1px solid #e2e8f0;text-align:left}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px}
.badge.ok{background:#dcfce7;color:#166534}
.badge.warn{background:#fef3c7;color:#92400e}
.badge.danger{background:#fee2e2;color:#991b1b}
.badge.gray{background:#e2e8f0;color:#475569}
.group-card,.question-card{
 border:1px solid var(--border);border-radius:9px;
 margin-bottom:12px;background:#fff
}
.group-head,.question-top{
 display:flex;gap:8px;align-items:center;padding:10px;
 background:#f8fafc;border-bottom:1px solid var(--border)
}
.group-head input{flex:1}
.question-top select{width:180px}
.question-card .body{padding:12px}
.drag-handle{cursor:grab;color:#64748b;font-size:20px}
.dragging{opacity:.45}
.option-row{display:grid;grid-template-columns:1fr 240px auto;gap:7px;margin:7px 0}
@media(max-width:700px){
 .option-row{grid-template-columns:1fr}
 .question-top{flex-wrap:wrap}
 .question-top select{width:100%}
}
.answer-option{display:block;padding:7px 0}
.answer-option input{width:auto;margin-right:7px}
.stat{
 padding:16px;background:#f8fafc;border:1px solid var(--border);
 border-radius:8px
}
.stat strong{font-size:24px;display:block}
.preview-q{padding:16px;border-bottom:1px solid var(--border)}
footer{text-align:center;padding:30px;color:#64748b}
</style>
</head>
<body>
<header>
<div style="max-width:1200px;margin:auto">
<a href="<?=h(url(['screen'=>$answerer?'answer':'list']))?>">
<strong><?=h(APP_TITLE)?></strong>
</a>
</div>
</header>
<main>
<?php
}

function foot(): void
{
?>
</main>
<footer><?=h(APP_TITLE)?></footer>
</body>
</html>
<?php
}

function flash_html(): void
{
    if ($f = flash_get()) {
        echo '<div class="flash '.h($f['type']).'">'.h($f['message']).'</div>';
    }
}

function form_open(array $params=[]): void
{
?>
<form method="post" action="<?=h(url())?>">
<?php foreach ($params as $k=>$v): ?>
<input type="hidden" name="<?=h($k)?>" value="<?=h($v)?>">
<?php endforeach; ?>
<?php
}

function form_close(): void
{
?>
</form>
<?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

function screen_list(array $d): void
{
    $q = gs('q');
    $filter = gs('status','all');
    $sort = gs('sort','updated_desc');

    $surveys = array_filter(
        $d['surveys'],
        function($s)use($q,$filter){
            if ($q !== '' &&
                mb_stripos((string)$s['title'],$q) === false) {
                return false;
            }

            return $filter === 'all' ||
                ($s['status'] ?? 'draft') === $filter;
        }
    );

    usort(
        $surveys,
        function($a,$b)use($sort){
            if ($sort === 'answers_desc' ||
                $sort === 'answers_asc') {
                global $d;

                $ca = count(array_filter(
                    $d['answers'],
                    fn($x)=>($x['survey_id']??'')===($a['id']??'')
                ));

                $cb = count(array_filter(
                    $d['answers'],
                    fn($x)=>($x['survey_id']??'')===($b['id']??'')
                ));

                return $sort === 'answers_desc'
                    ? $cb <=> $ca
                    : $ca <=> $cb;
            }

            $field = str_starts_with($sort,'start')
                ? 'startAt'
                : 'updatedAt';

            $cmp = strcmp(
                (string)($b[$field]??''),
                (string)($a[$field]??'')
            );

            return str_ends_with($sort,'asc') ? -$cmp : $cmp;
        }
    );

    head('アンケート一覧');
    flash_html();
?>
<div class="row" style="justify-content:space-between;margin-bottom:16px">
<h1>アンケート一覧</h1>
<a class="btn btn-primary" href="<?=h(url(['screen'=>'edit']))?>">＋ 新規作成</a>
</div>

<div class="card">
<div class="card-body">
<form method="get">
<input type="hidden" name="screen" value="list">
<div class="grid">
<label>検索
<input name="q" value="<?=h($q)?>" placeholder="タイトル">
</label>
<label>状態
<select name="status">
<?php foreach([
'all'=>'すべて','published'=>'公開中','draft'=>'下書き',
'stopped'=>'停止','ended'=>'終了'
] as $v=>$t): ?>
<option value="<?=h($v)?>" <?=$filter===$v?'selected':''?>><?=h($t)?></option>
<?php endforeach;?>
</select>
</label>
<label>ソート
<select name="sort">
<option value="updated_desc">更新日：新しい順</option>
<option value="updated_asc">更新日：古い順</option>
<option value="answers_desc">回答数：多い順</option>
<option value="answers_asc">回答数：少ない順</option>
<option value="start_desc">開始日：新しい順</option>
<option value="start_asc">開始日：古い順</option>
</select>
</label>
</div>
<div class="row">
<button class="btn btn-secondary">検索</button>
<a class="btn btn-secondary" href="<?=h(url(['screen'=>'list']))?>">リセット</a>
</div>
</form>
</div>
</div>

<div class="card">
<div class="card-body">
<div style="overflow:auto">
<table>
<thead>
<tr>
<th>タイトル</th><th>期間</th><th>状態</th><th>回答数</th><th>操作</th>
</tr>
</thead>
<tbody>
<?php foreach($surveys as $s):
$n=count(array_filter(
$d['answers'],
fn($a)=>($a['survey_id']??'')===($s['id']??'')
));?>
<tr>
<td>
<strong><?=h($s['title'])?></strong><br>
<span class="small">
作成 <?=h($s['createdAt']??'')?> /
更新 <?=h($s['updatedAt']??'')?>
</span>
</td>
<td><?=h($s['startAt']??'')?> ～ <?=h($s['endAt']??'')?></td>
<td>
<span class="badge <?=h(status_class($s['status']??''))?>">
<?=h(status_label($s['status']??''))?>
</span>
</td>
<td><?=h($n)?></td>
<td>
<div class="row">
<a class="btn btn-secondary" href="<?=h(url(['screen'=>'edit','id'=>$s['id']]))?>">編集</a>
<a class="btn btn-secondary" href="<?=h(url(['screen'=>'preview','id'=>$s['id']]))?>">プレビュー</a>
<a class="btn btn-secondary" href="<?=h(url(['screen'=>'analytics','id'=>$s['id']]))?>">集計</a>
<a class="btn btn-secondary" href="<?=h(url(['screen'=>'send','id'=>$s['id']]))?>">送信</a>
<form method="post" style="display:inline" onsubmit="return confirm('複製しますか？')">
<input type="hidden" name="action" value="duplicate_survey">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">
<button class="btn btn-secondary">複製</button>
</form>
<form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
<input type="hidden" name="action" value="delete_survey">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">
<button class="btn btn-danger">削除</button>
</form>
</div>
</td>
</tr>
<?php endforeach;?>
<?php if(!$surveys):?>
<tr><td colspan="5">該当するアンケートはありません。</td></tr>
<?php endif;?>
</tbody>
</table>
</div>
</div>
</div>

<div class="row">
<a class="btn btn-secondary" href="<?=h(url(['screen'=>'kintone']))?>">kintone設定</a>
<a class="btn btn-secondary" href="<?=h(url(['screen'=>'mail']))?>">メールサーバ設定</a>
</div>
<?php
foot();
}

/* =========================================================
 * 編集
 * ========================================================= */

function question_html(array $q,array $all): void
{
?>
<div class="question-card" draggable="true"
 data-question-id="<?=h($q['id'])?>">
<div class="question-top">
<span class="drag-handle">☷</span>
<strong class="question-number"><?=h($q['number'])?></strong>

<select class="question-type"
 name="question_type[<?=h($q['id'])?>]">
<?php foreach([
'single'=>'単一選択',
'multiple'=>'複数選択',
'text'=>'自由記述'
] as $v=>$t):?>
<option value="<?=h($v)?>" <?=$q['type']===$v?'selected':''?>>
<?=h($t)?>
</option>
<?php endforeach;?>
</select>

<button type="button" class="btn btn-danger remove-question">削除</button>
</div>

<div class="body">
<input type="hidden"
 name="questions_by_group[__GROUP__][]"
 value="<?=h($q['id'])?>">

<label>質問文
<textarea name="question_text[<?=h($q['id'])?>]" required><?=h($q['text'])?></textarea>
</label>

<label class="answer-option">
<input type="checkbox"
 name="question_required[<?=h($q['id'])?>]"
 value="1" <?=$q['required']?'checked':''?>>
必須
</label>

<div class="options-area">
<?php if($q['type']!=='text'):?>
<div class="small">選択肢</div>
<div class="option-list">
<?php foreach($q['options'] as $i=>$o):?>
<div class="option-row">
<input type="text"
 name="question_option[<?=h($q['id'])?>][]"
 value="<?=h($o['label']??'')?>"
 placeholder="選択肢">
<?php if($q['type']==='single'):?>
<select name="option_next[<?=h($q['id'])?>][]">
<option value="">次の質問を指定しない</option>
<?php foreach($all as $target):?>
<option value="<?=h($target['id'])?>"
 <?=$target['id']===($o['nextQuestionId']??'')?'selected':''?>>
<?=h($target['number'])?>
</option>
<?php endforeach;?>
</select>
<?php endif;?>
<button type="button" class="btn btn-secondary remove-option">削除</button>
</div>
<?php endforeach;?>
</div>
<button type="button" class="btn btn-secondary add-option">＋ 選択肢を追加</button>
<?php endif;?>
</div>
</div>
</div>
<?php
}

function screen_edit(array $d,string $id=''): void
{
    $s = $id !== '' ? survey($d['surveys'],$id) : null;

    if (!$s) {
        $s = [
            'id'=>'',
            'title'=>'',
            'description'=>'',
            'startAt'=>'',
            'endAt'=>'',
            'status'=>'draft',
            'numbering'=>'global',
            'groups'=>[[
                'id'=>uid('group'),
                'title'=>'基本アンケート',
                'questions'=>[[
                    'id'=>uid('question'),
                    'number'=>'Q1',
                    'text'=>'',
                    'type'=>'single',
                    'required'=>false,
                    'options'=>[
                        ['id'=>uid('option'),'label'=>'','nextQuestionId'=>''],
                        ['id'=>uid('option'),'label'=>'','nextQuestionId'=>''],
                    ],
                ]],
            ]],
        ];
    }

    $all = all_questions($s);

    head($s['id'] ? 'アンケート編集' : 'アンケート作成');
    flash_html();
?>
<div class="row" style="justify-content:space-between">
<h1><?=h($s['id']?'アンケート編集':'アンケート作成')?></h1>
<div class="row">
<a class="btn btn-secondary"
 href="<?=h(url(['screen'=>'list']))?>"
 onclick="return confirm('編集内容を破棄しますか？')">キャンセル</a>
<?php if($s['id']):?>
<a class="btn btn-secondary"
 href="<?=h(url(['screen'=>'preview','id'=>$s['id']]))?>">プレビュー</a>
<?php endif;?>
</div>
</div>

<div class="card">
<div class="card-body">
<?php form_open(['action'=>'save_survey','survey_id'=>$s['id']]);?>

<div class="grid">
<label>アンケートタイトル
<input name="title" required maxlength="200" value="<?=h($s['title'])?>">
</label>
<label>開始日時
<input type="datetime-local" name="startAt" value="<?=h($s['startAt'])?>">
</label>
<label>終了日時
<input type="datetime-local" name="endAt" value="<?=h($s['endAt'])?>">
</label>
<label>質問番号
<select name="numbering">
<option value="global" <?=$s['numbering']==='global'?'selected':''?>>全体通番 Q1,Q2...</option>
<option value="group" <?=$s['numbering']==='group'?'selected':''?>>グループ毎 Q1-1,Q1-2...</option>
</select>
</label>
</div>

<label>アンケート説明
<textarea name="description"><?=h($s['description'])?></textarea>
</label>

<?php if($s['id']):?>
<div class="row" style="margin-bottom:15px">
<span>状態：</span>
<span class="badge <?=h(status_class($s['status']))?>">
<?=h(status_label($s['status']))?>
</span>

<?php if($s['status']!=='ended'):?>
<form method="post"
 onsubmit="return confirm('状態を変更しますか？')">
<input type="hidden" name="action" value="change_status">
<input type="hidden" name="survey_id" value="<?=h($s['id'])?>">
<select name="next_status">
<option value="">状態変更</option>
<?php if($s['status']==='draft'):?>
<option value="published">公開中</option>
<?php elseif($s['status']==='published'):?>
<option value="stopped">停止</option>
<?php elseif($s['status']==='stopped'):?>
<option value="published">公開中</option>
<?php endif;?>
</select>
<button class="btn btn-secondary">変更</button>
</form>
<?php endif;?>
</div>
<?php endif;?>

<div id="survey-editor">
<div id="groups">
<?php foreach($s['groups'] as $g):?>
<div class="group-card" draggable="true"
 data-group-id="<?=h($g['id'])?>">

<div class="group-head">
<span class="drag-handle">☷</span>
<input name="group_title[<?=h($g['id'])?>]"
 value="<?=h($g['title'])?>">
<button type="button" class="btn btn-danger remove-group">
グループ削除
</button>
</div>

<div class="card-body">
<div class="questions">
<?php foreach($g['questions'] as $q):
    ob_start();
    question_html($q,$all);
    $html=ob_get_clean();
    echo str_replace(
        'questions_by_group[__GROUP__][]',
        'questions_by_group['.h($g['id']).'][]',
        $html
    );
endforeach;?>
</div>

<div class="question-order-holder"></div>

<button type="button" class="btn btn-secondary add-question">
＋ 質問を追加
</button>
</div>
</div>
<?php endforeach;?>
</div>

<button type="button" id="add-group"
 class="btn btn-secondary">＋ グループを追加</button>
</div>

<div class="row" style="margin-top:18px">
<button class="btn btn-primary">
保存して一覧へ
</button>
</div>

<?php form_close();?>
</div>
</div>

<script>
(function(){
'use strict';

const editor=document.getElementById('survey-editor');
const groups=document.getElementById('groups');
if(!editor||!groups)return;

const TYPES={
 single:'単一選択',
 multiple:'複数選択',
 text:'自由記述'
};

function id(p){
 if(window.crypto?.randomUUID)return p+'-'+crypto.randomUUID().replace(/-/g,'');
 return p+'-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2);
}

function esc(v){
 return String(v).replace(/[&<>"']/g,m=>({
 '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
 }[m]));
}

function groupList(){
 return [...groups.children].filter(x=>x.classList.contains('group-card'));
}

function questionList(g){
 return [...g.querySelector('.questions').children]
   .filter(x=>x.classList.contains('question-card'));
}

/*
 * ========================================================
 * 重要:
 * PHP初期描画DOMにも、JS追加DOMにも個別イベントを付けない。
 * #survey-editorでイベント委譲する。
 * ========================================================
 */

function renumber(){
 let n=1,gno=1;

 groupList().forEach(g=>{
  let qno=1;

  questionList(g).forEach(q=>{
   const el=q.querySelector('.question-number');

   if(el){
    el.textContent=
      editor.querySelector('[name="numbering"]')?.value==='group'
        ? `Q${gno}-${qno}`
        : `Q${n}`;
   }

   n++;
   qno++;
  });

  gno++;
 });
}

function syncOrder(){
 editor.querySelectorAll(
  'input[name="group_order[]"],.dynamic-order'
 ).forEach(x=>x.remove());

 groupList().forEach(g=>{
  const gid=g.dataset.groupId;
  if(!gid)return;

  const gi=document.createElement('input');
  gi.type='hidden';
  gi.name='group_order[]';
  gi.value=gid;
  gi.className='dynamic-order';
  editor.querySelector('form').appendChild(gi);

  questionList(g).forEach(q=>{
   const qi=document.createElement('input');
   qi.type='hidden';
   qi.name=`questions_by_group[${gid}][]`;
   qi.value=q.dataset.questionId;
   qi.className='dynamic-order';
   editor.querySelector('form').appendChild(qi);
  });
}

function updateTargets(){
 const qs=[];

 groupList().forEach(g=>{
  questionList(g).forEach(q=>{
   qs.push({
    id:q.dataset.questionId,
    number:q.querySelector('.question-number')?.textContent||''
   });
  });
 });

 editor.querySelectorAll('select[name^="option_next["]')
 .forEach(select=>{
  const current=select.value;
  select.innerHTML='<option value="">次の質問を指定しない</option>';

  qs.forEach(q=>{
   if(q.id===select.closest('.question-card')?.dataset.questionId)return;

   const o=document.createElement('option');
   o.value=q.id;
   o.textContent=q.number;
   o.selected=q.id===current;
   select.appendChild(o);
  });
 });
}

function makeOption(q,value=''){
 const list=q.querySelector('.option-list');
 if(!list)return;

 const qid=q.dataset.questionId;
 const type=q.querySelector('.question-type')?.value||'single';

 const row=document.createElement('div');
 row.className='option-row';

 const input=document.createElement('input');
 input.type='text';
 input.name=`question_option[${qid}][]`;
 input.value=value;
 input.placeholder='選択肢';

 row.appendChild(input);

 if(type==='single'){
  const sel=document.createElement('select');
  sel.name=`option_next[${qid}][]`;
  sel.innerHTML='<option value="">次の質問を指定しない</option>';
  row.appendChild(sel);
 }

 const btn=document.createElement('button');
 btn.type='button';
 btn.className='btn btn-secondary remove-option';
 btn.textContent='削除';
 row.appendChild(btn);

 list.appendChild(row);
}

function renderOptions(q,preserve=true){
 const area=q.querySelector('.options-area');
 const type=q.querySelector('.question-type')?.value;

 if(!area)return;

 const values=preserve
  ? [...area.querySelectorAll(
      'input[name^="question_option["]'
    )].map(x=>x.value)
  : [];

 area.innerHTML='';

 if(type==='text')return;

 const label=document.createElement('div');
 label.className='small';
 label.textContent='選択肢';

 const list=document.createElement('div');
 list.className='option-list';

 area.append(label,list);

 const vals=values.length?values:['',''];

 vals.forEach(v=>makeOption(q,v));

 const btn=document.createElement('button');
 btn.type='button';
 btn.className='btn btn-secondary add-option';
 btn.textContent='＋ 選択肢を追加';

 area.appendChild(btn);
}

function makeQuestion(g){
 const qid=id('question');

 const q=document.createElement('div');
 q.className='question-card';
 q.draggable=true;
 q.dataset.questionId=qid;

 q.innerHTML=`
 <div class="question-top">
  <span class="drag-handle">☷</span>
  <strong class="question-number">Q?</strong>
  <select class="question-type"
   name="question_type[${esc(qid)}]">
   <option value="single">単一選択</option>
   <option value="multiple">複数選択</option>
   <option value="text">自由記述</option>
  </select>
  <button type="button"
   class="btn btn-danger remove-question">削除</button>
 </div>
 <div class="body">
  <input type="hidden"
   name="questions_by_group[${esc(g.dataset.groupId)}][]"
   value="${esc(qid)}">
  <label>質問文
   <textarea required
    name="question_text[${esc(qid)}]"></textarea>
  </label>
  <label class="answer-option">
   <input type="checkbox"
    name="question_required[${esc(qid)}]"
    value="1"> 必須
  </label>
  <div class="options-area"></div>
 </div>`;

 g.querySelector('.questions').appendChild(q);
 renderOptions(q,false);

 return q;
}

function makeGroup(){
 const gid=id('group');

 const g=document.createElement('div');
 g.className='group-card';
 g.draggable=true;
 g.dataset.groupId=gid;

 g.innerHTML=`
 <div class="group-head">
  <span class="drag-handle">☷</span>
  <input name="group_title[${esc(gid)}]"
   value="新しいグループ">
  <button type="button"
   class="btn btn-danger remove-group">グループ削除</button>
 </div>
 <div class="card-body">
  <div class="questions"></div>
  <button type="button"
   class="btn btn-secondary add-question">＋ 質問を追加</button>
 </div>`;

 groups.appendChild(g);
 makeQuestion(g);
 return g;
}

editor.addEventListener('click',function(e){

 const addGroup=e.target.closest('#add-group');
 if(addGroup){
  e.preventDefault();
  makeGroup();
  renumber();
  syncOrder();
  updateTargets();
  return;
 }

 const addQuestion=e.target.closest('.add-question');
 if(addQuestion){
  e.preventDefault();

  const g=addQuestion.closest('.group-card');
  if(!g)return;

  makeQuestion(g);
  renumber();
  syncOrder();
  updateTargets();
  return;
 }

 const removeQuestion=e.target.closest('.remove-question');
 if(removeQuestion){
  e.preventDefault();

  if(!confirm('この質問を削除しますか？'))return;

  removeQuestion.closest('.question-card')?.remove();

  renumber();
  syncOrder();
  updateTargets();
  return;
 }

 const removeGroup=e.target.closest('.remove-group');
 if(removeGroup){
  e.preventDefault();

  if(!confirm('このグループを削除しますか？'))return;

  removeGroup.closest('.group-card')?.remove();

  renumber();
  syncOrder();
  updateTargets();
  return;
 }

 const addOption=e.target.closest('.add-option');
 if(addOption){
  e.preventDefault();

  const q=addOption.closest('.question-card');
  if(q){
   makeOption(q);
   updateTargets();
  }
  return;
 }

 const removeOption=e.target.closest('.remove-option');
 if(removeOption){
  e.preventDefault();
  removeOption.closest('.option-row')?.remove();
  updateTargets();
 }
});

editor.addEventListener('change',function(e){

 const type=e.target.closest('.question-type');

 if(type){
  const q=type.closest('.question-card');
  if(q){
   renderOptions(q,true);
   renumber();
   syncOrder();
   updateTargets();
  }
  return;
 }

 if(e.target.name==='numbering'){
  renumber();
  syncOrder();
  updateTargets();
 }
});

let dragQ=null;
let dragG=null;

editor.addEventListener('dragstart',function(e){

 const q=e.target.closest('.question-card');

 if(q){
  dragQ=q;
  dragQ.classList.add('dragging');
  e.stopPropagation();
  return;
 }

 const g=e.target.closest('.group-card');

 if(g){
  dragG=g;
  dragG.classList.add('dragging');
 }
});

editor.addEventListener('dragover',function(e){

 if(dragQ){
  const target=e.target.closest('.question-card');

  if(
   !target ||
   target===dragQ ||
   !target.closest('.group-card')
  )return;

  e.preventDefault();

  const rect=target.getBoundingClientRect();
  const before=e.clientY<rect.top+rect.height/2;
  const container=target.closest('.questions');

  /*
   * グループ間移動にも対応。
   */
  if(before){
   container.insertBefore(dragQ,target);
  }else{
   container.insertBefore(dragQ,target.nextSibling);
  }

  /*
   * hidden inputはsubmit前にsyncOrder()で再構築する。
   */
  return;
 }

 if(dragG){
  const target=e.target.closest('.group-card');

  if(!target||target===dragG)return;

  e.preventDefault();

  const rect=target.getBoundingClientRect();

  if(e.clientY<rect.top+rect.height/2){
   groups.insertBefore(dragG,target);
  }else{
   groups.insertBefore(dragG,target.nextSibling);
  }
 }
});

editor.addEventListener('dragend',function(){

 if(dragQ){
  dragQ.classList.remove('dragging');
  dragQ=null;
 }

 if(dragG){
  dragG.classList.remove('dragging');
  dragG=null;
 }

 renumber();
 syncOrder();
 updateTargets();
});

editor.querySelector('form')?.addEventListener('submit',function(){
 renumber();
 syncOrder();
 updateTargets();
});

renumber();
syncOrder();
updateTargets();

})();
</script>
<?php
foot();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function screen_preview(array $d,string $id): void
{
    $s = survey($d['surveys'],$id);

    if (!$s) {
        head('エラー');
        flash_html();
        foot();
        return;
    }

    head('プレビュー');
?>
<div class="row" style="justify-content:space-between">
<h1><?=h($s['title'])?></h1>
<a class="btn btn-secondary"
 href="<?=h(url(['screen'=>'edit','id'=>$id]))?>">編集へ戻る</a>
</div>

<div class="card">
<div class="card-body">
<p><?=nl2br(h($s['description']))?></p>
<p class="small">
<?=h($s['startAt'])?> ～ <?=h($s['endAt'])?>
</p>
</div>
</div>

<?php foreach($s['groups'] as $g):?>
<div class="card">
<div class="card-header"><?=h($g['title'])?></div>
<div class="card-body">
<?php foreach($g['questions'] as $q):?>
<div class="preview-q">
<strong><?=h($q['number'])?> <?=h($q['text'])?></strong>
<?php if($q['required']):?>
<span class="badge danger">必須</span>
<?php endif;?>

<?php if($q['type']==='text'):?>
<textarea disabled></textarea>
<?php else:?>
<?php foreach($q['options'] as $o):?>
<label class="answer-option">
<input type="<?=$q['type']==='multiple'?'checkbox':'radio'?>" disabled>
<?=h($o['label'])?>
<?php if($q['type']==='single' && !empty($o['nextQuestionId'])):?>
<span class="small">
→ <?=h($o['nextQuestionId'])?>
</span>
<?php endif;?>
</label>
<?php endforeach;?>
<?php endif;?>
</div>
<?php endforeach;?>
</div>
</div>
<?php endforeach;?>
<?php
foot();
}

/* =========================================================
 * 回答
 * ========================================================= */

function screen_answer(array $d,string $id): void
{
    $s = survey($d['surveys'],$id);

    if (!$s || $s['status'] !== 'published') {
        head('アンケート');
        ?>
        <div class="card"><div class="card-body">
        <h1>回答できません</h1>
        <p>このアンケートは現在回答できる状態ではありません。</p>
        </div></div>
        <?php
        foot();
        return;
    }

    $_SESSION['answer_survey'] = $id;

    $qs = visible_questions($s);

    head('アンケート回答',true);
    flash_html();
?>
<div class="card">
<div class="card-header"><?=h($s['title'])?></div>
<div class="card-body">
<p><?=nl2br(h($s['description']))?></p>

<?php form_open([
'action'=>'answer_next',
'survey_id'=>$id
]);?>

<?php foreach($s['groups'] as $g):?>
<?php
$gq=array_filter(
 $qs,
 fn($q)=>in_array(
  $q['id'],
  array_column($g['questions'],'id'),
  true
 )
);
if(!$gq)continue;
?>
<h2><?=h($g['title'])?></h2>

<?php foreach($gq as $q):?>
<div class="preview-q">
<label>
<?=h($q['number'])?> <?=h($q['text'])?>
<?php if($q['required']):?>
<span class="badge danger">必須</span>
<?php endif;?>
</label>

<?php if($q['type']==='text'):?>
<textarea name="answer[<?=h($q['id'])?>]"
 <?=$q['required']?'required':''?>></textarea>

<?php elseif($q['type']==='single'):?>
<?php foreach($q['options'] as $o):?>
<label class="answer-option">
<input
 type="radio"
 name="answer[<?=h($q['id'])?>]"
 value="<?=h($o['label'])?>"
 <?=$q['required']?'required':''?>>
<?=h($o['label'])?>
</label>
<?php endforeach;?>

<?php else:?>
<?php foreach($q['options'] as $o):?>
<label class="answer-option">
<input
 type="checkbox"
 name="answer[<?=h($q['id'])?>][]"
 value="<?=h($o['label'])?>">
<?=h($o['label'])?>
</label>
<?php endforeach;?>
<?php endif;?>
</div>
<?php endforeach;?>
<?php endforeach;?>

<button class="btn btn-primary">回答を確認する</button>
<?php form_close();?>
</div>
</div>
<?php
foot();
}

/* =========================================================
 * 確認
 * ========================================================= */

function screen_confirm(array $d,string $id): void
{
    $s = survey($d['surveys'],$id);
    $answers = $_SESSION['answer_draft'] ?? [];

    if (!$s || !is_array($answers)) {
        head('回答確認');
        flash_html();
        foot();
        return;
    }

    head('回答確認',true);
?>
<div class="card">
<div class="card-header">回答内容の確認</div>
<div class="card-body">

<?php foreach(all_questions($s) as $q):?>
<div class="preview-q">
<strong><?=h($q['number'])?> <?=h($q['text'])?></strong>
<p>
<?php
$v=$answers[$q['id']]??'';
if(is_array($v)){
 echo nl2br(h(implode('、',$v)));
}else{
 echo nl2br(h($v));
}
?>
</p>
</div>
<?php endforeach;?>

<div class="row">
<?php form_open([
'action'=>'answer_back',
'survey_id'=>$id
]);?>
<button class="btn btn-secondary">回答を修正</button>
<?php form_close();?>

<?php form_open([
'action'=>'submit_answer',
'survey_id'=>$id
]);?>
<button class="btn btn-primary"
 onclick="return confirm('回答を送信しますか？')">
回答を送信
</button>
<?php form_close();?>
</div>

</div>
</div>
<?php
foot();
}

/* =========================================================
 * 完了
 * ========================================================= */

function screen_complete(array $d,string $id): void
{
    $s = survey($d['surveys'],$id);

    head('回答完了',true);
?>
<div class="card">
<div class="card-body" style="text-align:center">
<h1>回答ありがとうございました</h1>
<p>
<?=h($s['title']??'アンケート')?>への回答を受け付けました。
</p>
</div>
</div>
<?php
foot();
}

/* =========================================================
 * 送信
 * ========================================================= */

function screen_send(array $d,string $id): void
{
    $s=survey($d['surveys'],$id);

    if(!$s){
        head('送信');
        flash_html();
        foot();
        return;
    }

    $history=array_values(array_filter(
        $d['send_history'],
        fn($x)=>($x['survey_id']??'')===$id
    ));

    $customers=$d['customers'];

    head('メール送信');
    flash_html();
?>
<div class="row" style="justify-content:space-between">
<h1>メール送信</h1>
<a class="btn btn-secondary"
 href="<?=h(url(['screen'=>'list']))?>">一覧へ</a>
</div>

<div class="card">
<div class="card-header">対象アンケート</div>
<div class="card-body">
<strong><?=h($s['title'])?></strong>
<p class="small"><?=h(public_url($id))?></p>
</div>
</div>

<div class="card">
<div class="card-header">顧客選択・送信</div>
<div class="card-body">

<?php if(!$customers):?>
<p>顧客情報がありません。kintone設定から同期してください。</p>
<?php else:?>

<?php form_open([
'action'=>'send_mail',
'survey_id'=>$id
]);?>

<label>件名
<input name="subject"
 value="<?=h($s['title'])?>のご案内">
</label>

<label>本文
<textarea name="body" rows="10">{$顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
</label>

<div class="row">
<input id="customer-filter"
 placeholder="顧客検索"
 style="max-width:300px">
</div>

<div style="max-height:400px;overflow:auto;margin:12px 0">
<?php foreach($customers as $c):?>
<label class="customer-row">
<input type="checkbox"
 name="customers[]"
 value="<?=h($c['id'])?>">
<?=h($c['name'])?>
<?=h($c['email'])?>
</label>
<?php endforeach;?>
</div>

<button class="btn btn-primary"
 onclick="return confirm('選択した顧客へ一括送信しますか？')">
一括送信
</button>

<?php form_close();?>
<?php endif;?>
</div>
</div>

<div class="card">
<div class="card-header">送信履歴</div>
<div class="card-body">
<?php if(!$history):?>
<p>送信履歴はありません。</p>
<?php else:?>
<div style="overflow:auto">
<table>
<thead><tr>
<th>日時</th><th>顧客</th><th>種別</th><th>結果</th>
</tr></thead>
<tbody>
<?php foreach(array_reverse($history) as $x):?>
<tr>
<td><?=h($x['createdAt']??'')?></td>
<td><?=h($x['customer_name']??'')?></td>
<td><?=h($x['type']??'')?></td>
<td><?=h($x['result']??'')?></td>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
<?php endif;?>
</div>
</div>
<script>
document.getElementById('customer-filter')?.addEventListener('input',function(){
 const q=this.value.toLowerCase();
 document.querySelectorAll('.customer-row').forEach(x=>{
  x.style.display=x.textContent.toLowerCase().includes(q)?'block':'none';
 });
});
</script>
<?php
foot();
}

/* =========================================================
 * 集計
 * ========================================================= */

function screen_analytics(array $d,string $id): void
{
    $s=survey($d['surveys'],$id);

    if(!$s){
        head('集計');
        flash_html();
        foot();
        return;
    }

    $answers=array_values(array_filter(
        $d['answers'],
        fn($x)=>($x['survey_id']??'')===$id
    ));

    $sent=array_values(array_filter(
        $d['send_history'],
        fn($x)=>($x['survey_id']??'')===$id &&
            ($x['result']??'')==='成功'
    ));

    $answered=count($answers);
    $targets=count(array_unique(
        array_map(
            fn($x)=>$x['customer_name']??'',
            $sent
        )
    ));

    head('回答集計・分析');
    flash_html();
?>
<div class="row" style="justify-content:space-between">
<h1>回答集計・分析</h1>
<a class="btn btn-secondary"
 href="<?=h(url(['screen'=>'list']))?>">一覧へ</a>
</div>

<div class="card">
<div class="card-header">対象アンケート</div>
<div class="card-body">
<strong><?=h($s['title'])?></strong>
</div>
</div>

<div class="grid">
<div class="stat"><span>送信対象者数</span><strong><?=h($targets)?></strong></div>
<div class="stat"><span>回答数</span><strong><?=h($answered)?></strong></div>
<div class="stat"><span>未登録回答数</span><strong>0</strong></div>
<div class="stat">
<span>未回答数</span>
<strong><?=h(max(0,$targets-$answered))?></strong>
</div>
<div class="stat">
<span>回答率</span>
<strong><?=h($targets ? round($answered/$targets*100,1) : 0)?>%</strong>
</div>
</div>

<?php if(!$answers):?>
<div class="card">
<div class="card-body">
現在、回答データはありません
</div>
</div>
<?php else:?>

<?php foreach(all_questions($s) as $q):?>
<div class="card">
<div class="card-header">
<?=h($q['number'])?> <?=h($q['text'])?>
</div>
<div class="card-body">
<?php
$count=[];
foreach($answers as $a){
 $v=$a['answers'][$q['id']]??'';
 if(is_array($v)){
  foreach($v as $x)$count[$x]=($count[$x]??0)+1;
 }else{
  $count[$v]=($count[$v]??0)+1;
 }
}
arsort($count);
?>
<?php foreach($count as $label=>$n):?>
<div style="margin-bottom:8px">
<?=h($label===''?'未回答':$label)?>
<div style="background:#e2e8f0;border-radius:5px">
<div style="width:<?=h($answered?($n/$answered*100):0)?>%;background:#2563eb;color:#fff;padding:3px 6px">
<?=h($n)?>
</div>
</div>
</div>
<?php endforeach;?>
</div>
</div>
<?php endforeach;?>

<div class="card">
<div class="card-header">個別回答</div>
<div class="card-body">
<?php foreach($answers as $a):?>
<details>
<summary><?=h($a['createdAt']??'')?></summary>
<?php foreach(all_questions($s) as $q):?>
<p>
<strong><?=h($q['number'])?></strong>
<?=h(is_array($a['answers'][$q['id']]??'')
 ? implode('、',$a['answers'][$q['id']])
 : ($a['answers'][$q['id']]??''))?>
</p>
<?php endforeach;?>
</details>
<?php endforeach;?>
</div>
</div>

<?php endif;?>

<div class="row">
<a class="btn btn-secondary"
 href="<?=h(url(['screen'=>'export_csv','id'=>$id]))?>">CSV出力</a>
<a class="btn btn-secondary"
 href="<?=h(url(['screen'=>'export_pdf','id'=>$id]))?>">PDF出力</a>
</div>
<?php
foot();
}

/* =========================================================
 * kintone設定
 * ========================================================= */

function screen_kintone(array $s): void
{
    $k=$s['kintone'];

    head('kintone設定');
    flash_html();
?>
<h1>kintone設定</h1>

<div class="card">
<div class="card-body">

<?php form_open(['action'=>'save_kintone']);?>

<div class="grid">
<label>サブドメイン
<input name="subdomain"
 value="<?=h($k['subdomain'])?>"
 placeholder="xxxx / xxxx.cybozu.com">
</label>

<label>顧客管理アプリID
<input name="app_id" value="<?=h($k['app_id'])?>">
</label>

<label>ログイン名
<input name="username" value="<?=h($k['username'])?>">
</label>

<label>パスワード
<input type="password" name="password"
 placeholder="変更しない場合は空欄">
</label>

<label>Proxy
<input name="proxy" value="<?=h($k['proxy'])?>"
 placeholder="host:port">
</label>
</div>

<label>
<input type="checkbox" name="verify_ssl" value="1"
 style="width:auto" <?=$k['verify_ssl']?'checked':''?>>
SSL証明書検証を有効にする
</label>

<div class="row">
<button class="btn btn-primary">設定保存</button>
<?php form_close();?>

<?php form_open(['action'=>'test_kintone']);?>
<button class="btn btn-secondary">接続テスト</button>
<?php form_close();?>

<?php form_open(['action'=>'get_kintone_fields']);?>
<button class="btn btn-secondary">項目一覧を再取得</button>
<?php form_close();?>

<?php form_open(['action'=>'sync_kintone']);?>
<button class="btn btn-success">顧客情報を同期</button>
<?php form_close();?>
</div>

<p class="small">
最終接続確認: <?=h($k['last_test']??'未確認')?><br>
最終同期: <?=h($k['last_sync']??'未同期')?>
</p>
</div>
</div>

<?php if($k['fields']):?>
<div class="card">
<div class="card-header">顧客情報マッピング</div>
<div class="card-body">
<?php form_open(['action'=>'save_kintone_mapping']);?>

<?php
$fields=$k['fields'];
$map=$k['mapping'];
$select=function($name,$value)use($fields){
 echo '<select name="'.h($name).'">';
 echo '<option value="">未設定</option>';
 foreach($fields as $f){
  echo '<option value="'.h($f['code']).'" '.
   ($value===$f['code']?'selected':'').
   '>'.h($f['label'].' ['.$f['code'].']').
   '</option>';
 }
 echo '</select>';
};
?>

<div class="grid">
<label>組織名
<?php $select('mapping_organization',$map['organization']??'');?>
</label>
<label>氏名
<?php $select('mapping_name',$map['name']??'');?>
</label>
<label>メールアドレス
<?php $select('mapping_email',$map['email']??'');?>
</label>
<label>部署名
<?php $select('mapping_department',$map['department']??'');?>
</label>
<label>電話番号
<?php $select('mapping_phone',$map['phone']??'');?>
</label>
</div>

<label>住所（複数選択可）</label>
<?php foreach($fields as $f):?>
<label class="answer-option">
<input type="checkbox"
 name="mapping_address[]"
 value="<?=h($f['code'])?>"
 style="width:auto"
 <?=in_array($f['code'],$map['address']??[],true)?'checked':''?>>
<?=h($f['label'])?> [<?=h($f['code'])?>]
</label>
<?php endforeach;?>

<button class="btn btn-primary">マッピング保存</button>
<?php form_close();?>
</div>
</div>
<?php endif;?>

<div class="row">
<a class="btn btn-secondary" href="<?=h(url(['screen'=>'list']))?>">一覧へ</a>
</div>
<?php
foot();
}

/* =========================================================
 * メール設定
 * ========================================================= */

function screen_mail(array $s): void
{
    $m=$s['mail'];

    head('メールサーバ設定');
    flash_html();
?>
<h1>メールサーバ設定</h1>

<div class="card">
<div class="card-body">

<?php form_open(['action'=>'save_mail']);?>

<div class="grid">
<label>SMTPサーバ
<input name="server" value="<?=h($m['host'])?>">
</label>

<label>SMTPポート
<input name="port" type="number"
 value="<?=h($m['port'])?>">
</label>

<label>暗号化方式
<select name="encryption">
<?php foreach([
'ssl'=>'SSL','tls'=>'TLS','none'=>'なし'
] as $v=>$t):?>
<option value="<?=h($v)?>"
 <?=$m['encryption']===$v?'selected':''?>>
<?=h($t)?>
</option>
<?php endforeach;?>
</select>
</label>

<label>SMTPユーザー名
<input name="username" value="<?=h($m['username'])?>">
</label>

<label>SMTPパスワード
<input type="password" name="password"
 placeholder="変更しない場合は空欄">
</label>

<label>送信元メールアドレス
<input type="email" name="from_email"
 value="<?=h($m['from_email'])?>">
</label>

<label>送信元名
<input name="from_name" value="<?=h($m['from_name'])?>">
</label>

<label>返信先
<input type="email" name="reply_to"
 value="<?=h($m['reply_to'])?>">
</label>
</div>

<label class="answer-option">
<input type="checkbox" name="auth" value="1"
 style="width:auto" <?=$m['auth']?'checked':''?>>
SMTP認証
</label>

<div class="row">
<button class="btn btn-primary">設定保存</button>
<?php form_close();?>

<?php form_open(['action'=>'test_mail']);?>
<button class="btn btn-secondary">接続テスト</button>
<?php form_close();?>
</div>

<p class="small">
最終接続確認: <?=h($m['last_test']??'未確認')?>
</p>
</div>
</div>

<div class="card">
<div class="card-header">テストメール送信</div>
<div class="card-body">
<?php form_open(['action'=>'test_mail_send']);?>
<label>送信先
<input type="email" name="test_email" required>
</label>
<button class="btn btn-success">テストメール送信</button>
<?php form_close();?>
</div>
</div>

<a class="btn btn-secondary" href="<?=h(url(['screen'=>'list']))?>">一覧へ</a>
<?php
foot();
}

/* =========================================================
 * CSV
 * ========================================================= */

function export_csv(array $d,string $id): void
{
    $s=survey($d['surveys'],$id);

    if(!$s){
        http_response_code(404);
        echo 'survey not found';
        return;
    }

    header('Content-Type:text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey_' .
        preg_replace('/[^A-Za-z0-9_-]/','',$id) .
        '.csv"'
    );

    $fp=fopen('php://output','wb');

    fwrite($fp,"\xEF\xBB\xBF");

    $head=['回答日時'];

    foreach(all_questions($s) as $q){
        $head[]=$q['number'].' '.$q['text'];
    }

    fputcsv($fp,$head);

    foreach($d['answers'] as $a){
        if(($a['survey_id']??'')!==$id)continue;

        $row=[$a['createdAt']??''];

        foreach(all_questions($s) as $q){
            $v=$a['answers'][$q['id']]??'';
            $row[]=is_array($v)?implode('、',$v):$v;
        }

        fputcsv($fp,$row);
    }

    fclose($fp);
}

/* =========================================================
 * 簡易PDF
 * ========================================================= */

function pdf_escape(string $v): string
{
    return str_replace(
        ['\\','(',')'],
        ['\\\\','\\(','\\)'],
        $v
    );
}

function export_pdf(array $d,string $id): void
{
    $s=survey($d['surveys'],$id);

    if(!$s){
        http_response_code(404);
        echo 'survey not found';
        return;
    }

    /*
     * 外部ライブラリなしで実データを含む
     * 最小PDFを生成する。
     *
     * 日本語フォントを埋め込まないため、
     * ASCII化できる内容を中心に出力。
     */
    $lines=[
        'Survey: '.$s['title'],
        'Answers: '.count(array_filter(
            $d['answers'],
            fn($a)=>($a['survey_id']??'')===$id
        )),
    ];

    foreach(all_questions($s) as $q){
        $lines[]=$q['number'].' '.$q['text'];
    }

    $stream="BT\n/F1 10 Tf\n50 750 Td\n";

    foreach($lines as $line){
        $line=preg_replace('/[^\x20-\x7E]/','?',$line);
        $stream.='('.pdf_escape($line).") Tj\n0 -16 Td\n";
    }

    $stream.="ET";

    $objects=[];
    $objects[]="<< /Type /Catalog /Pages 2 0 R >>";
    $objects[]="<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[]=
        "<< /Type /Page /Parent 2 0 R ".
        "/MediaBox [0 0 595 842] ".
        "/Resources << /Font << /F1 5 0 R >> >> ".
        "/Contents 4 0 R >>";
    $objects[]=
        "<< /Length ".strlen($stream)." >>\nstream\n".
        $stream."\nendstream";
    $objects[]=
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    $pdf="%PDF-1.4\n";
    $offsets=[0];

    foreach($objects as $i=>$obj){
        $offsets[] = strlen($pdf);
        $pdf.=(string)($i+1)." 0 obj\n".$obj."\nendobj\n";
    }

    $xref=strlen($pdf);

    $pdf.="xref\n0 ".(count($objects)+1)."\n";
    $pdf.="0000000000 65535 f \n";

    for($i=1;$i<=count($objects);$i++){
        $pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);
    }

    $pdf.="trailer\n<< /Size ".(count($objects)+1).
        " /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

    header('Content-Type:application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey_'.$id.'.pdf"'
    );

    echo $pdf;
}

/* =========================================================
 * 起動
 * ========================================================= */

try {
    start_app();

    $d=data();
    $s=settings();

    if(refresh_status($d)){
        save_json(DATA_FILE,$d);
    }

    /*
     * GET export
     */
    $screen=gs('screen','list');

    if($screen==='export_csv'){
        export_csv($d,gs('id'));
        exit;
    }

    if($screen==='export_pdf'){
        export_pdf($d,gs('id'));
        exit;
    }

    /*
     * POST
     */
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $result=handle_post($d,$s);

        if($result){
            $screen=$result['screen']??'list';
            $id=$result['id']??'';
        }
    } else {
        $id=gs('id');
    }

    /*
     * 回答者画面と管理者画面を分離。
     */
    if(in_array(
        $screen,
        ['answer','confirm','complete'],
        true
    )){
        if($screen==='answer'){
            screen_answer($d,$id);
        }elseif($screen==='confirm'){
            screen_confirm($d,$id);
        }else{
            screen_complete($d,$id);
        }

        exit;
    }

    switch($screen){

        case 'edit':
            screen_edit($d,$id);
            break;

        case 'preview':
            screen_preview($d,$id);
            break;

        case 'send':
            if($id===''){
                flash('error','対象アンケートが指定されていません。');
                screen_list($d);
            }else{
                screen_send($d,$id);
            }
            break;

        case 'analytics':
            if($id===''){
                flash('error','対象アンケートが指定されていません。');
                screen_list($d);
            }else{
                screen_analytics($d,$id);
            }
            break;

        case 'kintone':
            screen_kintone($s);
            break;

        case 'mail':
            screen_mail($s);
            break;

        default:
            screen_list($d);
            break;
    }

} catch(Throwable $e) {

    http_response_code(500);

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=h(APP_TITLE)?> - エラー</title>
    <style>
    body{
      font-family:sans-serif;background:#f1f5f9;
      padding:30px;color:#334155
    }
    .error{
      max-width:800px;margin:auto;background:#fff;
      padding:25px;border:1px solid #cbd5e1;border-radius:10px
    }
    </style>
    </head>
    <body>
    <div class="error">
    <h1>アプリケーションエラー</h1>
    <p><?=nl2br(h($e->getMessage()))?></p>
    <p>
    <a href="<?=h($_SERVER['SCRIPT_NAME']??'index.php')?>">トップへ戻る</a>
    </p>
    </div>
    </body>
    </html>
    <?php
}

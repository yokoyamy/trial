<?php
/**
 * ============================================================================
 * GUARD COMMENT — 固定名称一覧
 * ============================================================================
 * ※以下の名称は、今後の修正・再生成時も変更・削除禁止。
 *
 * ストレージ:
 * - survey_storage_directory
 * - survey_storage_file
 * - survey_admin_session_v1
 *
 * データトップキー:
 * - surveys / responses / customers / settings / mail_logs
 *
 * アンケート項目:
 * - id / title / start_at / end_at / status / created_at / updated_at
 * - numbering_mode / groups / deleted
 *
 * グループ項目:
 * - id / name / questions
 *
 * 質問項目:
 * - id / text / type / required / options / other_enabled
 *
 * 質問形式:
 * - single / multiple / text
 *
 * 顧客項目:
 * - id / company / name / email / department / phone / address / source
 * - sent_at / send_count / answer_status / kintone_status
 *
 * 回答項目:
 * - id / survey_id / customer_id / company / name / email / answered_at / answers
 *
 * 設定項目:
 * - subdomain / login_name / password / app_id / ssl_verify / proxy
 * - field_company / field_name / field_email / field_department
 * - field_phone / field_address
 * - smtp_host / smtp_port / smtp_encryption / smtp_auth
 * - smtp_username / smtp_password / smtp_from / smtp_from_name
 * - smtp_timeout
 *
 * POST/GETパラメータ:
 * - action / survey_id / customer_id / response_id / keyword / status_filter
 * - sort / survey_json / settings_json / csrf_token / recipient_ids
 * - mail_subject / mail_body / template_type / app_id / test_email
 *
 * API/JSONキー:
 * - properties / records / label / code / type / message / ok / fields
 *
 * HTML DOM ID / JS参照名:
 * - app / csrf_token / survey_title / survey_start_at / survey_end_at
 * - survey_numbering_mode / question_editor / preview_modal / preview_content
 * - response_modal / response_detail / response_filter / response_table
 * - customer_filter / customer_table / select_all / mail_subject / mail_body
 * - template_type / settings_form / settings_json / setting_subdomain
 * - setting_app_id / setting_login_name / setting_password / setting_proxy
 * - setting_ssl_verify / field_message
 *
 * 取り得る値:
 * - status: draft / active / ended
 * - numbering_mode: global / group
 * - type: single / multiple / text
 * - source: kintone / web
 * - answer_status: unanswered / answered
 * - kintone_status: unregistered / registered
 * - template_type: initial / reminder
 * ============================================================================
 */

declare(strict_types=1);

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

function app_e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_json(mixed $v): string {
    return json_encode(
        $v,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

function app_now(): string {
    return date('Y-m-d H:i:s');
}

function app_id(string $prefix = 'id'): string {
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
}

function app_data(): array {
    if (!is_file(SURVEY_STORAGE_FILE)) {
        $data = [
            'surveys' => [],
            'responses' => [],
            'customers' => [],
            'settings' => [],
            'mail_logs' => []
        ];
        app_save($data);
        return $data;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        $data = [];
    }

    foreach (['surveys','responses','customers','settings','mail_logs'] as $k) {
        if (!isset($data[$k]) || !is_array($data[$k])) {
            $data[$k] = [];
        }
    }

    return $data;
}

function app_save(array $data): bool {
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';
    $json = app_json($data);

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function app_csrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function app_check_csrf(): void {
    $a = (string)($_POST['csrf_token'] ?? '');
    $b = (string)($_SESSION['csrf_token'] ?? '');

    if ($a === '' || $b === '' || !hash_equals($b, $a)) {
        app_json_response(['ok'=>false,'message'=>'CSRFトークンが不正です。'], 403);
    }
}

function app_json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo app_json($data);
    exit;
}

/**
 * PHP 8.4/8.5対応。
 * $http_response_header は使用しない。
 */
function get_safe_response_headers(): array {
    if (function_exists('http_get_last_response_headers')) {
        $h = http_get_last_response_headers();
        return is_array($h) ? $h : [];
    }
    return [];
}

/**
 * kintone URLの成形。
 * xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com をすべて許容。
 */
function kintone_build_url(string $domain, string $endpoint): string {
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim($domain, '/');
    $endpoint = '/' . ltrim($endpoint, '/');
    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * cURLを使わずstream_context_create/file_get_contentsで通信。
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $opts = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20
    ];

    if ($method !== 'GET' && $payload !== null) {
        $opts['content'] = is_array($payload)
            ? app_json($payload)
            : (string)$payload;
    }

    $ctx = [
        'http' => $opts,
        'ssl' => [
            'verify_peer' => !empty($config['ssl_verify']),
            'verify_peer_name' => !empty($config['ssl_verify']),
            'allow_self_signed' => empty($config['ssl_verify'])
        ]
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));
    if ($proxy !== '') {
        $ctx['http']['proxy'] = 'tcp://' . $proxy;
        $ctx['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($ctx);
    $body = @file_get_contents($url, false, $context);
    $headersOut = get_safe_response_headers();

    $status = 0;

    foreach ($headersOut as $h) {
        if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/i', $h, $m)) {
            $status = (int)$m[1];
        }
    }

    $decoded = json_decode($body ?: '', true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
            'headers' => $headersOut
        ];
    }

    $message = is_array($decoded)
        ? (string)($decoded['message'] ?? 'kintone API通信エラー')
        : 'kintone API通信エラー';

    return [
        'success' => false,
        'status' => $status ?: 500,
        'message' => $message,
        'raw' => is_array($decoded) ? $decoded : [],
        'body' => (string)$body,
        'headers' => $headersOut
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string {
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login_name) . ':' . trim($password));
}

function kintone_settings(array $data): array {
    return is_array($data['settings'] ?? null) ? $data['settings'] : [];
}

function kintone_headers(array $settings): array {
    return [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                (string)($settings['login_name'] ?? '') . ':' .
                (string)($settings['password'] ?? '')
            ),
        'Content-Type: application/json',
        'Accept: application/json'
    ];
}

function kintone_request_from_settings(
    array $settings,
    string $method,
    string $endpoint,
    mixed $payload = null
): array {
    $url = kintone_build_url(
        (string)($settings['subdomain'] ?? ''),
        $endpoint
    );

    return kintone_api_request(
        $method,
        $url,
        kintone_headers($settings),
        $payload,
        $settings
    );
}

/* --------------------------------------------------------------------------
 * SMTP
 * PHP mail()/MTAには依存せず、SMTPサーバーへ直接接続。
 * -------------------------------------------------------------------------- */

function smtp_socket(array $s): array {
    $host = trim((string)($s['smtp_host'] ?? ''));
    $port = (int)($s['smtp_port'] ?? 587);
    $enc  = strtolower((string)($s['smtp_encryption'] ?? 'tls'));
    $timeout = max(3, (int)($s['smtp_timeout'] ?? 15));

    if ($host === '') {
        return ['ok'=>false,'message'=>'SMTPサーバが未設定です。'];
    }

    $target = $host . ':' . $port;

    if ($enc === 'ssl') {
        $target = 'ssl://' . $target;
    }

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        return [
            'ok'=>false,
            'stage'=>'tcp',
            'host'=>$host,
            'port'=>$port,
            'encryption'=>$enc,
            'message'=>"TCP接続失敗: {$errstr} ({$errno})"
        ];
    }

    stream_set_timeout($fp, $timeout);

    $read = function() use ($fp): array {
        $lines = [];
        while (($line = fgets($fp, 2048)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $lines;
    };

    $write = function(string $line) use ($fp): void {
        fwrite($fp, $line . "\r\n");
    };

    $greeting = $read();
    $code = (int)substr((string)($greeting[0] ?? ''), 0, 3);

    if ($code < 200 || $code >= 400) {
        fclose($fp);
        return ['ok'=>false,'stage'=>'smtp','message'=>'SMTP greeting error','code'=>$code];
    }

    $write('EHLO localhost');
    $ehlo = $read();

    if ($enc === 'tls') {
        $write('STARTTLS');
        $tls = $read();
        $tlsCode = (int)substr((string)($tls[0] ?? ''), 0, 3);

        if ($tlsCode !== 220) {
            fclose($fp);
            return [
                'ok'=>false,
                'stage'=>'tls',
                'message'=>'STARTTLSに失敗しました。',
                'code'=>$tlsCode
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
                'ok'=>false,
                'stage'=>'tls',
                'message'=>'TLS接続に失敗しました。'
            ];
        }

        $write('EHLO localhost');
        $ehlo = $read();
    }

    $auth = !empty($s['smtp_auth']);

    if ($auth) {
        $user = (string)($s['smtp_username'] ?? '');
        $pass = (string)($s['smtp_password'] ?? '');

        $write('AUTH LOGIN');
        $r = $read();

        if ((int)substr((string)($r[0] ?? ''),0,3) !== 334) {
            fclose($fp);
            return ['ok'=>false,'stage'=>'auth','message'=>'SMTP AUTH LOGINを開始できません。'];
        }

        $write(base64_encode($user));
        $r = $read();

        if ((int)substr((string)($r[0] ?? ''),0,3) !== 334) {
            fclose($fp);
            return ['ok'=>false,'stage'=>'auth','message'=>'SMTPユーザー名認証に失敗しました。'];
        }

        $write(base64_encode($pass));
        $r = $read();

        if ((int)substr((string)($r[0] ?? ''),0,3) !== 235) {
            fclose($fp);
            return ['ok'=>false,'stage'=>'auth','message'=>'SMTPパスワード認証に失敗しました。'];
        }
    }

    return [
        'ok'=>true,
        'fp'=>$fp,
        'read'=>$read,
        'write'=>$write,
        'code'=>$code,
        'host'=>$host,
        'port'=>$port,
        'encryption'=>$enc
    ];
}

function smtp_send(array $settings, string $to, string $subject, string $body): array {
    $c = smtp_socket($settings);

    if (!$c['ok']) return $c;

    $fp = $c['fp'];
    $read = $c['read'];
    $write = $c['write'];

    $from = trim((string)($settings['smtp_from'] ?? ''));
    $fromName = trim((string)($settings['smtp_from_name'] ?? ''));

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        fclose($fp);
        return ['ok'=>false,'stage'=>'config','message'=>'送信元メールアドレスが不正です。'];
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        fclose($fp);
        return ['ok'=>false,'stage'=>'recipient','message'=>'宛先メールアドレスが不正です。'];
    }

    $write('MAIL FROM:<' . $from . '>');
    $r = $read();

    if ((int)substr((string)($r[0] ?? ''),0,3) >= 400) {
        fclose($fp);
        return ['ok'=>false,'stage'=>'mail_from','message'=>'MAIL FROMが拒否されました。'];
    }

    $write('RCPT TO:<' . $to . '>');
    $r = $read();

    if ((int)substr((string)($r[0] ?? ''),0,3) >= 400) {
        fclose($fp);
        return ['ok'=>false,'stage'=>'rcpt_to','message'=>'宛先が拒否されました。'];
    }

    $write('DATA');
    $r = $read();

    if ((int)substr((string)($r[0] ?? ''),0,3) !== 354) {
        fclose($fp);
        return ['ok'=>false,'stage'=>'data','message'=>'DATAコマンドが拒否されました。'];
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . ($fromName !== '' ? $encodedName . ' ' : '') . '<' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit'
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n" .
        preg_replace("/\r?\n/", "\r\n", $body) . "\r\n.";

    fwrite($fp, $message . "\r\n");

    $r = $read();
    $code = (int)substr((string)($r[0] ?? ''),0,3);

    $write('QUIT');
    $read();
    fclose($fp);

    return $code >= 200 && $code < 300
        ? ['ok'=>true,'code'=>$code]
        : ['ok'=>false,'stage'=>'send','code'=>$code,'message'=>'SMTPサーバが送信を拒否しました。'];
}

function smtp_config_complete(array $s): array {
    $required = [
        'smtp_host'=>'SMTPサーバ',
        'smtp_port'=>'SMTPポート',
        'smtp_from'=>'送信元メールアドレス'
    ];

    foreach ($required as $key=>$label) {
        if (trim((string)($s[$key] ?? '')) === '') {
            return ['ok'=>false,'message'=>$label . 'が未設定です。'];
        }
    }

    if (!filter_var($s['smtp_from'], FILTER_VALIDATE_EMAIL)) {
        return ['ok'=>false,'message'=>'送信元メールアドレスが不正です。'];
    }

    if (!empty($s['smtp_auth']) &&
        (trim((string)($s['smtp_username'] ?? '')) === '' ||
         trim((string)($s['smtp_password'] ?? '')) === '')) {
        return ['ok'=>false,'message'=>'SMTP認証情報が不足しています。'];
    }

    return ['ok'=>true];
}

/* --------------------------------------------------------------------------
 * API
 * -------------------------------------------------------------------------- */

$action = (string)($_REQUEST['action'] ?? '');

if ($action !== '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        app_check_csrf();
    }

    $data = app_data();

    if ($action === 'load') {
        app_json_response([
            'ok'=>true,
            'data'=>$data,
            'csrf_token'=>app_csrf()
        ]);
    }

    if ($action === 'save_survey') {
        $survey = json_decode((string)($_POST['survey_json'] ?? ''), true);

        if (!is_array($survey)) {
            app_json_response(['ok'=>false,'message'=>'アンケートデータが不正です。'],400);
        }

        $id = (string)($survey['id'] ?? '');
        if ($id === '') $id = app_id('survey');

        $old = null;

        foreach ($data['surveys'] as $i=>$s) {
            if (($s['id'] ?? '') === $id) {
                $old = $i;
                break;
            }
        }

        $survey['id'] = $id;
        $survey['updated_at'] = app_now();
        $survey['created_at'] = $survey['created_at'] ?? app_now();
        $survey['status'] = $survey['status'] ?? 'draft';
        $survey['numbering_mode'] = $survey['numbering_mode'] ?? 'global';
        $survey['groups'] = is_array($survey['groups'] ?? null) ? $survey['groups'] : [];
        $survey['deleted'] = !empty($survey['deleted']);

        if ($old === null) {
            $data['surveys'][] = $survey;
        } else {
            $data['surveys'][$old] = $survey;
        }

        app_save($data);
        app_json_response(['ok'=>true,'survey'=>$survey]);
    }

    if ($action === 'delete_survey') {
        $id = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$s) {
            if (($s['id'] ?? '') === $id) {
                $s['deleted'] = true;
                $s['updated_at'] = app_now();
            }
        }
        unset($s);

        app_save($data);
        app_json_response(['ok'=>true]);
    }

    if ($action === 'duplicate_survey') {
        $id = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $id) {
                $new = $s;
                $new['id'] = app_id('survey');
                $new['title'] = (string)($s['title'] ?? '') . '（複製）';
                $new['status'] = 'draft';
                $new['created_at'] = app_now();
                $new['updated_at'] = app_now();
                $new['deleted'] = false;
                $data['surveys'][] = $new;
                app_save($data);
                app_json_response(['ok'=>true,'survey'=>$new]);
            }
        }

        app_json_response(['ok'=>false,'message'=>'アンケートが見つかりません。'],404);
    }

    if ($action === 'save_settings') {
        $s = json_decode((string)($_POST['settings_json'] ?? ''), true);

        if (!is_array($s)) {
            app_json_response(['ok'=>false,'message'=>'設定データが不正です。'],400);
        }

        $oldPassword = (string)($data['settings']['password'] ?? '');
        if (($s['password'] ?? '') === '' && $oldPassword !== '') {
            $s['password'] = $oldPassword;
        }

        $oldSmtpPassword = (string)($data['settings']['smtp_password'] ?? '');
        if (($s['smtp_password'] ?? '') === '' && $oldSmtpPassword !== '') {
            $s['smtp_password'] = $oldSmtpPassword;
        }

        $data['settings'] = $s;
        app_save($data);

        $safe = $s;
        $safe['password'] = $safe['password'] !== '' ? '********' : '';
        $safe['smtp_password'] = $safe['smtp_password'] !== '' ? '********' : '';

        app_json_response(['ok'=>true,'settings'=>$safe]);
    }

    if ($action === 'kintone_test') {
        $s = kintone_settings($data);

        $r = kintone_request_from_settings(
            $s,
            'GET',
            '/k/v1/apps.json'
        );

        app_json_response([
            'ok'=>$r['success'],
            'status'=>$r['status'] ?? 0,
            'message'=>$r['success']
                ? 'kintone接続確認に成功しました。'
                : ($r['message'] ?? '接続に失敗しました。'),
            'diagnostic'=>[
                'http_status'=>$r['status'] ?? 0,
                'response'=>$r['raw'] ?? [],
                'subdomain'=>$s['subdomain'] ?? '',
                'ssl_verify'=>!empty($s['ssl_verify']),
                'proxy'=>$s['proxy'] ?? ''
            ]
        ]);
    }

    if ($action === 'kintone_fields') {
        $s = kintone_settings($data);

        $appId = trim((string)(
            $_POST['app_id'] ??
            $s['app_id'] ??
            ''
        ));

        if ($appId === '' || !ctype_digit($appId)) {
            app_json_response([
                'ok'=>false,
                'message'=>'顧客管理アプリIDを数字で指定してください。'
            ],400);
        }

        /*
         * 重要:
         * /k/v1/app/form/fields.json
         * に GET + app=アプリID を付ける。
         * 以前の「不正なリクエスト」の主原因になりやすい箇所。
         */
        $r = kintone_request_from_settings(
            $s,
            'GET',
            '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
        );

        if (!$r['success']) {
            app_json_response([
                'ok'=>false,
                'message'=>$r['message'] ?? '項目一覧取得に失敗しました。',
                'status'=>$r['status'] ?? 0,
                'response'=>$r['raw'] ?? []
            ]);
        }

        $fields = [];

        foreach (($r['data']['properties'] ?? []) as $code=>$field) {
            if (!is_array($field)) continue;

            $type = (string)($field['type'] ?? '');

            if (in_array($type, [
                'LABEL','SPACER','HR','GROUP'
            ], true)) {
                continue;
            }

            $fields[] = [
                'code'=>$code,
                'label'=>(string)($field['label'] ?? $code),
                'type'=>$type
            ];
        }

        app_json_response([
            'ok'=>true,
            'fields'=>$fields,
            'app_id'=>$appId
        ]);
    }

    if ($action === 'sync_customers') {
        $s = kintone_settings($data);
        $appId = trim((string)($s['app_id'] ?? ''));

        if ($appId === '' || !ctype_digit($appId)) {
            app_json_response(['ok'=>false,'message'=>'顧客管理アプリIDが未設定です。'],400);
        }

        $fields = [
            $s['field_company'] ?? '',
            $s['field_name'] ?? '',
            $s['field_email'] ?? '',
            $s['field_department'] ?? '',
            $s['field_phone'] ?? '',
            $s['field_address'] ?? ''
        ];

        $fields = array_values(array_filter($fields, fn($v)=>(string)$v !== ''));

        $query = [
            'app'=>(int)$appId,
            'totalCount'=>true,
            'size'=>500
        ];

        if ($fields) $query['fields'] = $fields;

        $r = kintone_request_from_settings(
            $s,
            'GET',
            '/k/v1/records.json?' . http_build_query($query)
        );

        if (!$r['success']) {
            app_json_response([
                'ok'=>false,
                'message'=>$r['message'] ?? '顧客データ取得に失敗しました。',
                'status'=>$r['status'] ?? 0
            ]);
        }

        $map = [
            'company'=>(string)($s['field_company'] ?? ''),
            'name'=>(string)($s['field_name'] ?? ''),
            'email'=>(string)($s['field_email'] ?? ''),
            'department'=>(string)($s['field_department'] ?? ''),
            'phone'=>(string)($s['field_phone'] ?? ''),
            'address'=>(string)($s['field_address'] ?? '')
        ];

        foreach (($r['data']['records'] ?? []) as $record) {
            $get = function(string $code) use ($record): string {
                if ($code === '' || !isset($record[$code])) return '';
                $v = $record[$code]['value'] ?? '';

                if (is_array($v)) {
                    $a = [];
                    foreach ($v as $x) {
                        if (is_array($x)) $a[] = (string)($x['value'] ?? '');
                        else $a[] = (string)$x;
                    }
                    return implode(' ', $a);
                }

                return (string)$v;
            };

            $email = trim($get($map['email']));
            if ($email === '') continue;

            $found = null;

            foreach ($data['customers'] as $i=>$c) {
                if (strcasecmp((string)($c['email'] ?? ''),$email) === 0) {
                    $found = $i;
                    break;
                }
            }

            $customer = [
                'id'=>$found !== null
                    ? ($data['customers'][$found]['id'] ?? app_id('customer'))
                    : app_id('customer'),
                'company'=>$get($map['company']),
                'name'=>$get($map['name']),
                'email'=>$email,
                'department'=>$get($map['department']),
                'phone'=>$get($map['phone']),
                'address'=>$get($map['address']),
                'source'=>'kintone',
                'sent_at'=>$found !== null ? ($data['customers'][$found]['sent_at'] ?? '') : '',
                'send_count'=>$found !== null ? (int)($data['customers'][$found]['send_count'] ?? 0) : 0,
                'answer_status'=>$found !== null ? ($data['customers'][$found]['answer_status'] ?? 'unanswered') : 'unanswered',
                'kintone_status'=>'registered'
            ];

            if ($found === null) $data['customers'][] = $customer;
            else $data['customers'][$found] = $customer;
        }

        app_save($data);

        app_json_response([
            'ok'=>true,
            'count'=>count($r['data']['records'] ?? [])
        ]);
    }

    if ($action === 'smtp_test_connection') {
        $s = $data['settings'];

        $r = smtp_socket($s);

        if (!empty($r['fp'])) {
            $r['read'] = null;
            $r['write'] = null;
            fclose($r['fp']);
            unset($r['fp']);
        }

        app_json_response($r['ok']
            ? [
                'ok'=>true,
                'message'=>'SMTP接続確認に成功しました。',
                'smtp_server'=>$s['smtp_host'] ?? '',
                'smtp_port'=>$s['smtp_port'] ?? '',
                'encryption'=>$s['smtp_encryption'] ?? '',
                'tcp'=>'OK',
                'tls'=>strtolower((string)($s['smtp_encryption'] ?? '')) === 'none' ? '未使用' : 'OK',
                'smtp_code'=>$r['code'] ?? 220,
                'authentication'=>!empty($s['smtp_auth']) ? 'OK' : 'なし'
            ]
            : [
                'ok'=>false,
                'message'=>$r['message'] ?? 'SMTP接続に失敗しました。',
                'smtp_server'=>$s['smtp_host'] ?? '',
                'smtp_port'=>$s['smtp_port'] ?? '',
                'encryption'=>$s['smtp_encryption'] ?? '',
                'stage'=>$r['stage'] ?? 'unknown',
                'smtp_code'=>$r['code'] ?? 0
            ]
        );
    }

    if ($action === 'smtp_test_mail') {
        $s = $data['settings'];
        $to = trim((string)($_POST['test_email'] ?? ''));

        $valid = smtp_config_complete($s);

        if (!$valid['ok']) {
            app_json_response($valid,400);
        }

        if (!filter_var($to,FILTER_VALIDATE_EMAIL)) {
            app_json_response(['ok'=>false,'message'=>'テスト送信先メールアドレスが不正です。'],400);
        }

        $r = smtp_send(
            $s,
            $to,
            'アンケート管理システム SMTP送信テスト',
            "アンケート管理システムのSMTP設定が正常に動作しています。\r\n\r\n" .
            "このメールはSMTP接続・送信確認のためのテストメールです。"
        );

        app_json_response($r['ok']
            ? ['ok'=>true,'message'=>'テストメールの送信に成功しました。']
            : [
                'ok'=>false,
                'message'=>$r['message'] ?? 'テストメール送信に失敗しました。',
                'stage'=>$r['stage'] ?? '',
                'code'=>$r['code'] ?? 0
            ]
        );
    }

    if ($action === 'send_mail') {
        $valid = smtp_config_complete($data['settings']);

        if (!$valid['ok']) {
            app_json_response([
                'ok'=>false,
                'configuration_error'=>true,
                'message'=>$valid['message']
            ],400);
        }

        $surveyId = (string)($_POST['survey_id'] ?? '');
        $ids = json_decode((string)($_POST['recipient_ids'] ?? '[]'),true);

        if (!is_array($ids)) $ids = [];

        $subject = (string)($_POST['mail_subject'] ?? '');
        $body = (string)($_POST['mail_body'] ?? '');
        $templateType = (string)($_POST['template_type'] ?? 'initial');

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if (!$survey) {
            app_json_response(['ok'=>false,'message'=>'アンケートが見つかりません。'],404);
        }

        $success = 0;
        $failed = 0;
        $unsent = 0;

        $log = [
            'id'=>app_id('mail_log'),
            'survey_id'=>$surveyId,
            'sent_at'=>app_now(),
            'type'=>$templateType === 'reminder' ? 'リマインド' : '初回',
            'target_count'=>count($ids),
            'success_count'=>0,
            'failed_count'=>0,
            'subject'=>$subject,
            'executor'=>'admin',
            'results'=>[]
        ];

        foreach ($ids as $cid) {
            $index = null;

            foreach ($data['customers'] as $i=>$c) {
                if (($c['id'] ?? '') === (string)$cid) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                $unsent++;
                continue;
            }

            $customer = $data['customers'][$index];
            $email = trim((string)($customer['email'] ?? ''));

            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) {
                $failed++;
                $log['results'][] = [
                    'customer_id'=>$cid,
                    'email'=>$email,
                    'result'=>'failed',
                    'error'=>'メールアドレス不正'
                ];
                continue;
            }

            $answerUrl =
                rtrim(
                    ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        ? 'https://' : 'http://') .
                    ($_SERVER['HTTP_HOST'] ?? ''),
                    '/'
                ) .
                dirname($_SERVER['SCRIPT_NAME']) .
                '/?survey=' . rawurlencode($surveyId) .
                '&customer=' . rawurlencode((string)$cid);

            $personalBody = str_replace(
                ['{顧客名}','{アンケートURL}'],
                [
                    (string)($customer['name'] ?? ''),
                    $answerUrl
                ],
                $body
            );

            $r = smtp_send(
                $data['settings'],
                $email,
                $subject,
                $personalBody
            );

            if ($r['ok']) {
                $success++;

                $data['customers'][$index]['sent_at'] = app_now();
                $data['customers'][$index]['send_count'] =
                    (int)($data['customers'][$index]['send_count'] ?? 0) + 1;
                $data['customers'][$index]['answer_status'] = 'unanswered';

                $log['results'][] = [
                    'customer_id'=>$cid,
                    'email'=>$email,
                    'result'=>'success',
                    'sent_at'=>app_now(),
                    'body'=>$personalBody
                ];
            } else {
                $failed++;

                $log['results'][] = [
                    'customer_id'=>$cid,
                    'email'=>$email,
                    'result'=>'failed',
                    'error'=>$r['message'] ?? 'SMTP送信失敗'
                ];
            }
        }

        $log['success_count'] = $success;
        $log['failed_count'] = $failed;
        $log['unsent_count'] = $unsent;

        $data['mail_logs'][] = $log;
        app_save($data);

        app_json_response([
            'ok'=>true,
            'success'=>$success,
            'failed'=>$failed,
            'unsent'=>$unsent,
            'log_id'=>$log['id']
        ]);
    }

    if ($action === 'register_customer') {
        $cid = (string)($_POST['customer_id'] ?? '');

        foreach ($data['customers'] as &$c) {
            if (($c['id'] ?? '') === $cid) {
                $c['kintone_status'] = 'registered';
            }
        }
        unset($c);

        app_save($data);
        app_json_response(['ok'=>true]);
    }

    if ($action === 'save_response') {
        $r = json_decode((string)($_POST['response_json'] ?? ''),true);

        if (!is_array($r)) {
            app_json_response(['ok'=>false,'message'=>'回答データが不正です。'],400);
        }

        $r['id'] = $r['id'] ?? app_id('response');
        $r['answered_at'] = app_now();

        $data['responses'][] = $r;

        foreach ($data['customers'] as &$c) {
            if (($c['id'] ?? '') === ($r['customer_id'] ?? '')) {
                $c['answer_status'] = 'answered';
            }
        }
        unset($c);

        app_save($data);

        app_json_response(['ok'=>true,'response'=>$r]);
    }

    if ($action === 'csv') {
        $surveyId = (string)($_GET['survey_id'] ?? '');

        $survey = null;
        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        $responses = array_values(array_filter(
            $data['responses'],
            fn($r)=>(string)($r['survey_id'] ?? '') === $surveyId
        ));

        $questions = [];

        foreach (($survey['groups'] ?? []) as $g) {
            foreach (($g['questions'] ?? []) as $q) {
                $questions[] = $q;
            }
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="survey_' .
            rawurlencode($surveyId) . '.csv"'
        );

        echo "\xEF\xBB\xBF";

        $head = ['回答ID','回答日時','顧客ID','会社名','氏名'];

        foreach ($questions as $q) {
            $head[] = (string)($q['text'] ?? '');
        }

        $f = fopen('php://output','w');
        fputcsv($f,$head);

        foreach ($responses as $r) {
            $row = [
                $r['id'] ?? '',
                $r['answered_at'] ?? '',
                $r['customer_id'] ?? '',
                $r['company'] ?? '',
                $r['name'] ?? ''
            ];

            $answers = is_array($r['answers'] ?? null)
                ? $r['answers'] : [];

            foreach ($questions as $q) {
                $v = $answers[$q['id'] ?? ''] ?? '';

                if (is_array($v)) $v = implode('、',$v);

                $row[] = $v;
            }

            fputcsv($f,$row);
        }

        fclose($f);
        exit;
    }

    app_json_response(['ok'=>false,'message'=>'Unknown action'],404);
}

$csrf = app_csrf();

?><!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App = {
    state: {
        data: {surveys:[],responses:[],customers:[],settings:[],mail_logs:[]},
        csrf: <?= json_encode($csrf) ?>,
        screen: 'list',
        editSurvey: null,
        selectedSurvey: null,
        selectedQuestion: null,
        selectedResponse: null,
        fields: [],
        filter: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        responseFilter: '',
        customerFilter: '',
        customerStatus: 'all',
        previewMode: 'pc'
    },

    api: {
        async post(action, params = {}) {
            const fd = new FormData();
            fd.append('action', action);
            fd.append('csrf_token', App.state.csrf);

            Object.keys(params).forEach(k => {
                let v = params[k];
                if (typeof v === 'object') v = JSON.stringify(v);
                fd.append(k, v);
            });

            const r = await fetch(location.href, {
                method: 'POST',
                body: fd
            });

            const text = await r.text();
            let j;

            try {
                j = JSON.parse(text);
            } catch(e) {
                throw new Error(text || 'サーバーから不正な応答が返されました。');
            }

            if (j.csrf_token) App.state.csrf = j.csrf_token;

            return j;
        },

        async load() {
            const r = await App.api.post('load');
            if (!r.ok) throw new Error(r.message || '初期化に失敗しました。');
            App.state.data = r.data;
            App.state.csrf = r.csrf_token || App.state.csrf;
        }
    },

    util: {
        esc(v) {
            return String(v ?? '')
                .replaceAll('&','&amp;')
                .replaceAll('<','&lt;')
                .replaceAll('>','&gt;')
                .replaceAll('"','&quot;')
                .replaceAll("'","&#039;");
        },

        uid(prefix='id') {
            return prefix + '_' + Date.now() + '_' +
                Math.random().toString(16).slice(2);
        },

        survey(id) {
            return App.state.data.surveys.find(
                x => x.id === id && !x.deleted
            );
        },

        statusLabel(s) {
            return {
                active:'公開中',
                draft:'下書き',
                ended:'終了'
            }[s] || s;
        },

        statusClass(s) {
            return {
                active:'bg-emerald-100 text-emerald-700',
                draft:'bg-amber-100 text-amber-700',
                ended:'bg-slate-200 text-slate-600'
            }[s] || 'bg-slate-100';
        },

        fmt(v) {
            if (!v) return '未設定';
            const d = new Date(String(v).replace(' ','T'));
            if (isNaN(d)) return v;
            return d.toLocaleString('ja-JP');
        },

        questions(survey) {
            const a = [];
            (survey?.groups || []).forEach(g => {
                (g.questions || []).forEach(q => a.push(q));
            });
            return a;
        },

        renumber(survey) {
            let n = 0;

            if (survey.numbering_mode === 'group') {
                survey.groups.forEach((g, gi) => {
                    (g.questions || []).forEach((q, qi) => {
                        q.number = `Q${gi+1}-${qi+1}`;
                    });
                });
            } else {
                survey.groups.forEach(g => {
                    (g.questions || []).forEach(q => {
                        n++;
                        q.number = `Q${n}`;
                    });
                });
            }
        }
    },

    render: {
        shell(content) {
            return `
            <div class="min-h-screen">
                <header class="bg-white border-b sticky top-0 z-30">
                    <div class="max-w-7xl mx-auto px-5 py-4 flex items-center justify-between">
                        <div>
                            <div class="text-xl font-bold text-slate-800">
                                アンケート管理システム
                            </div>
                            <div class="text-xs text-slate-400">
                                Survey Management System
                            </div>
                        </div>
                        <nav class="flex gap-2">
                            <button onclick="App.actions.go('list')"
                                class="px-3 py-2 rounded-lg hover:bg-slate-100">
                                アンケート一覧
                            </button>
                            <button onclick="App.actions.settings()"
                                class="px-3 py-2 rounded-lg hover:bg-slate-100">
                                キントーン・メール連携設定
                            </button>
                        </nav>
                    </div>
                </header>

                <main class="max-w-7xl mx-auto p-5">
                    ${content}
                </main>
            </div>`;
        },

        list() {
            let surveys = App.state.data.surveys.filter(s => !s.deleted);

            const kw = App.state.filter.toLowerCase();

            if (kw) {
                surveys = surveys.filter(
                    s => String(s.title || '').toLowerCase().includes(kw)
                );
            }

            if (App.state.statusFilter !== 'all') {
                surveys = surveys.filter(
                    s => s.status === App.state.statusFilter
                );
            }

            surveys.sort((a,b) => {
                if (App.state.sort === 'updated_desc')
                    return String(b.updated_at).localeCompare(String(a.updated_at));
                if (App.state.sort === 'updated_asc')
                    return String(a.updated_at).localeCompare(String(b.updated_at));

                const ac = App.state.data.responses.filter(
                    r=>r.survey_id===a.id
                ).length;
                const bc = App.state.data.responses.filter(
                    r=>r.survey_id===b.id
                ).length;

                if (App.state.sort === 'answers_desc') return bc-ac;
                if (App.state.sort === 'answers_asc') return ac-bc;

                return String(b.start_at || '').localeCompare(
                    String(a.start_at || '')
                );
            });

            return App.render.shell(`
                <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                    <div>
                        <h1 class="text-2xl font-bold">アンケート一覧</h1>
                        <p class="text-sm text-slate-500 mt-1">
                            アンケートの作成・送信・集計を管理します。
                        </p>
                    </div>
                    <button onclick="App.actions.newSurvey()"
                        class="bg-indigo-600 text-white px-5 py-3 rounded-xl font-semibold shadow-sm hover:bg-indigo-700">
                        ＋ 新規アンケート作成
                    </button>
                </div>

                <div class="bg-white rounded-2xl border p-4 mb-4 flex flex-wrap gap-3">
                    <input value="${App.util.esc(App.state.filter)}"
                        onkeydown="if(event.key==='Enter')App.actions.filter(this.value)"
                        placeholder="タイトルを検索"
                        class="border rounded-lg px-3 py-2 w-64">

                    <select onchange="App.actions.statusFilter(this.value)"
                        class="border rounded-lg px-3 py-2">
                        <option value="all">すべて</option>
                        <option value="active" ${App.state.statusFilter==='active'?'selected':''}>公開中</option>
                        <option value="draft" ${App.state.statusFilter==='draft'?'selected':''}>下書き</option>
                        <option value="ended" ${App.state.statusFilter==='ended'?'selected':''}>終了</option>
                    </select>

                    <select onchange="App.actions.sort(this.value)"
                        class="border rounded-lg px-3 py-2">
                        <option value="updated_desc">更新日：新しい順</option>
                        <option value="updated_asc">更新日：古い順</option>
                        <option value="answers_desc">回答数：多い順</option>
                        <option value="answers_asc">回答数：少ない順</option>
                    </select>
                </div>

                <div class="bg-white rounded-2xl border overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-b">
                            <tr>
                                <th class="text-left p-4">作成日 / 更新日</th>
                                <th class="text-left p-4">タイトル</th>
                                <th class="text-left p-4">アンケート期間</th>
                                <th class="text-left p-4">ステータス</th>
                                <th class="text-left p-4">回答数</th>
                                <th class="text-right p-4">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        ${surveys.length ? surveys.map(s => {
                            const count = App.state.data.responses.filter(
                                r=>r.survey_id===s.id
                            ).length;

                            let buttons = '';

                            if (s.status === 'active') {
                                buttons = `
                                    <button onclick="App.actions.edit('${s.id}')">確認・編集</button>
                                    <button onclick="App.actions.aggregate('${s.id}')">集計</button>
                                    <button onclick="App.actions.send('${s.id}')">送信</button>
                                    <button onclick="App.actions.stop('${s.id}')">停止</button>`;
                            } else if (s.status === 'draft') {
                                buttons = `
                                    <button onclick="App.actions.edit('${s.id}')">確認・編集</button>
                                    <button onclick="App.actions.deleteSurvey('${s.id}')">削除</button>`;
                            } else {
                                buttons = `
                                    <button onclick="App.actions.edit('${s.id}')">確認・編集</button>
                                    <button onclick="App.actions.aggregate('${s.id}')">集計</button>`;
                            }

                            return `
                            <tr class="border-b last:border-0 hover:bg-slate-50">
                                <td class="p-4 text-slate-500">
                                    ${App.util.fmt(s.created_at)}<br>
                                    <span class="text-xs">更新: ${App.util.fmt(s.updated_at)}</span>
                                </td>
                                <td class="p-4 font-bold">${App.util.esc(s.title)}</td>
                                <td class="p-4">
                                    ${App.util.fmt(s.start_at)} ～ ${App.util.fmt(s.end_at)}
                                </td>
                                <td class="p-4">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold ${App.util.statusClass(s.status)}">
                                        ${App.util.statusLabel(s.status)}
                                    </span>
                                </td>
                                <td class="p-4">${count} 件</td>
                                <td class="p-4 text-right">
                                    <div class="flex flex-wrap justify-end gap-2 text-indigo-600">
                                        ${buttons}
                                        <button onclick="App.actions.duplicate('${s.id}')">複製</button>
                                    </div>
                                </td>
                            </tr>`;
                        }).join('') : `
                            <tr>
                                <td colspan="6" class="p-12 text-center text-slate-400">
                                    アンケートがありません。
                                </td>
                            </tr>`}
                        </tbody>
                    </table>
                </div>
            `);
        },

        editor(survey) {
            App.util.renumber(survey);

            return App.render.shell(`
                <div class="flex justify-between items-center mb-5">
                    <div>
                        <div class="text-sm text-slate-400">
                            ホーム ＞ アンケート一覧 ＞ 編集
                        </div>
                        <h1 class="text-2xl font-bold mt-1">アンケート作成・編集</h1>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="App.actions.preview()"
                            class="px-4 py-2 border rounded-lg bg-white">
                            プレビュー
                        </button>
                        <button onclick="App.actions.saveSurvey()"
                            class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
                            保存して一覧へ戻る
                        </button>
                        <button onclick="App.actions.cancelEdit()"
                            class="px-4 py-2 border rounded-lg">
                            キャンセル
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border p-5 mb-5">
                    <div class="grid md:grid-cols-4 gap-4">
                        <label class="md:col-span-2">
                            <span class="block text-sm font-semibold mb-1">タイトル</span>
                            <input id="survey_title"
                                value="${App.util.esc(survey.title)}"
                                oninput="App.actions.editField('title',this.value)"
                                class="w-full border rounded-lg px-3 py-2">
                        </label>
                        <label>
                            <span class="block text-sm font-semibold mb-1">開始日時</span>
                            <input id="survey_start_at" type="datetime-local"
                                value="${App.util.esc(String(survey.start_at||'').replace(' ','T'))}"
                                onchange="App.actions.editField('start_at',this.value)"
                                class="w-full border rounded-lg px-3 py-2">
                        </label>
                        <label>
                            <span class="block text-sm font-semibold mb-1">終了日時</span>
                            <input id="survey_end_at" type="datetime-local"
                                value="${App.util.esc(String(survey.end_at||'').replace(' ','T'))}"
                                onchange="App.actions.editField('end_at',this.value)"
                                class="w-full border rounded-lg px-3 py-2">
                        </label>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-5 items-center">
                        <label>
                            ステータス
                            <select onchange="App.actions.editField('status',this.value)"
                                class="border rounded-lg px-3 py-2 ml-2">
                                <option value="draft" ${survey.status==='draft'?'selected':''}>下書き</option>
                                <option value="active" ${survey.status==='active'?'selected':''}>公開中</option>
                                <option value="ended" ${survey.status==='ended'?'selected':''}>終了</option>
                            </select>
                        </label>

                        <label>
                            質問番号
                            <select id="survey_numbering_mode"
                                onchange="App.actions.editField('numbering_mode',this.value);App.renderEditor()"
                                class="border rounded-lg px-3 py-2 ml-2">
                                <option value="global" ${survey.numbering_mode==='global'?'selected':''}>Q1 / Q2 / Q3</option>
                                <option value="group" ${survey.numbering_mode==='group'?'selected':''}>Q1-1 / Q1-2</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div id="question_editor" class="space-y-5">
                    ${survey.groups.map((g,gi)=>App.render.group(g,gi)).join('')}
                </div>

                <div class="mt-5">
                    <button onclick="App.actions.addGroup()"
                        class="px-5 py-3 bg-white border rounded-xl hover:bg-slate-50">
                        ＋ グループ追加
                    </button>
                </div>
            `);
        },

        group(g, gi) {
            return `
            <section class="group-card bg-white border rounded-2xl overflow-hidden"
                data-group-id="${App.util.esc(g.id)}">
                <div class="bg-slate-50 border-b p-4 flex items-center gap-3">
                    <span class="group-handle cursor-grab text-xl">⠿</span>
                    <input value="${App.util.esc(g.name)}"
                        oninput="App.actions.groupName('${g.id}',this.value)"
                        class="font-bold bg-transparent border-0 outline-none flex-1">
                    <button onclick="App.actions.deleteGroup('${g.id}')"
                        class="text-red-500">削除</button>
                </div>

                <div class="p-4 question-list space-y-3" data-group-id="${g.id}">
                    ${(g.questions||[]).map((q,qi)=>App.render.question(q,gi,qi)).join('')}
                </div>

                <div class="p-4 border-t">
                    <button onclick="App.actions.addQuestion('${g.id}')"
                        class="text-indigo-600 font-semibold">
                        ＋ 質問追加
                    </button>
                </div>
            </section>`;
        },

        question(q,gi,qi) {
            return `
            <div class="question-card border rounded-xl p-4 bg-white"
                data-question-id="${App.util.esc(q.id)}">
                <div class="flex items-start gap-3">
                    <span class="question-handle cursor-grab text-slate-400 text-xl">⠿</span>

                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <span class="font-bold text-indigo-600">${App.util.esc(q.number)}</span>
                            <select onchange="App.actions.questionField('${q.id}','type',this.value)"
                                class="border rounded px-2 py-1 text-sm">
                                <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
                                <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
                                <option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
                            </select>
                            <label class="text-sm">
                                <input type="checkbox" ${q.required?'checked':''}
                                    onchange="App.actions.questionField('${q.id}','required',this.checked)">
                                必須
                            </label>
                            ${q.type!=='text'?`
                            <label class="text-sm">
                                <input type="checkbox" ${q.other_enabled?'checked':''}
                                    onchange="App.actions.questionField('${q.id}','other_enabled',this.checked)">
                                その他
                            </label>`:''}
                        </div>

                        <input value="${App.util.esc(q.text)}"
                            oninput="App.actions.questionField('${q.id}','text',this.value)"
                            placeholder="質問文を入力"
                            class="w-full border rounded-lg px-3 py-2 font-semibold">

                        ${q.type!=='text'?`
                        <div class="mt-3 space-y-2">
                            ${(q.options||[]).map((o,oi)=>`
                                <div class="flex gap-2">
                                    <input value="${App.util.esc(o)}"
                                        oninput="App.actions.option('${q.id}',${oi},this.value)"
                                        class="flex-1 border rounded px-3 py-2">
                                    <button onclick="App.actions.removeOption('${q.id}',${oi})"
                                        class="text-red-500">削除</button>
                                </div>`).join('')}
                            <button onclick="App.actions.addOption('${q.id}')"
                                class="text-sm text-indigo-600">
                                ＋ 選択肢追加
                            </button>
                        </div>`:''}
                    </div>

                    <button onclick="App.actions.deleteQuestion('${q.id}')"
                        class="text-red-500">削除</button>
                </div>
            </div>`;
        },

        preview() {
            const s = App.state.editSurvey;
            return `
            <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-5"
                id="preview_modal">
                <div class="bg-white rounded-2xl max-w-3xl w-full max-h-[90vh] overflow-auto">
                    <div class="p-4 border-b flex justify-between">
                        <div class="font-bold">プレビュー</div>
                        <div class="flex gap-2">
                            <button onclick="App.actions.previewMode('pc')"
                                class="px-3 py-1 border rounded">PC</button>
                            <button onclick="App.actions.previewMode('sp')"
                                class="px-3 py-1 border rounded">スマートフォン</button>
                            <button onclick="App.actions.closePreview()">×</button>
                        </div>
                    </div>
                    <div id="preview_content"
                        class="${App.state.previewMode==='sp'?'max-w-sm mx-auto':''} p-6">
                        <h2 class="text-2xl font-bold mb-5">${App.util.esc(s.title)}</h2>
                        ${App.util.questions(s).map(q=>`
                            <div class="mb-6">
                                <div class="font-semibold mb-2">
                                    ${App.util.esc(q.number)} ${App.util.esc(q.text)}
                                    ${q.required?'<span class="text-red-500">*</span>':''}
                                </div>
                                ${q.type==='text'
                                    ? `<textarea class="w-full border rounded-lg p-3" rows="4"></textarea>`
                                    : (q.options||[]).map(o=>`
                                        <label class="block p-2">
                                            <input type="${q.type==='single'?'radio':'checkbox'}">
                                            ${App.util.esc(o)}
                                        </label>`).join('')}
                            </div>
                        `).join('')}
                        <button onclick="alert('プレビューでは送信されません。')"
                            class="bg-indigo-600 text-white px-5 py-3 rounded-lg">
                            回答を送信
                        </button>
                    </div>
                </div>
            </div>`;
        },

        settings() {
            const s = App.state.data.settings || {};

            return App.render.shell(`
                <div class="mb-5">
                    <div class="text-sm text-slate-400">
                        ホーム ＞ システム設定 ＞ kintone・メール連携設定
                    </div>
                    <h1 class="text-2xl font-bold mt-1">キントーン・メール連携設定</h1>
                </div>

                <div class="space-y-5">

                    <section class="bg-white rounded-2xl border p-5">
                        <h2 class="font-bold text-lg mb-4">kintone接続・認証設定</h2>

                        <div class="grid md:grid-cols-2 gap-4">
                            <label>
                                <span class="text-sm font-semibold">サブドメイン</span>
                                <input id="setting_subdomain"
                                    value="${App.util.esc(s.subdomain||'')}"
                                    placeholder="xxxx または xxxx.cybozu.com"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label>
                                <span class="text-sm font-semibold">顧客管理アプリID</span>
                                <input id="setting_app_id"
                                    value="${App.util.esc(s.app_id||'')}"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label>
                                <span class="text-sm font-semibold">ログイン名</span>
                                <input id="setting_login_name"
                                    value="${App.util.esc(s.login_name||'')}"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label>
                                <span class="text-sm font-semibold">パスワード</span>
                                <input id="setting_password" type="password"
                                    placeholder="変更しない場合は空欄"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label>
                                <span class="text-sm font-semibold">Proxy</span>
                                <input id="setting_proxy"
                                    value="${App.util.esc(s.proxy||'')}"
                                    placeholder="host名:port番号"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label class="flex items-center gap-2 pt-6">
                                <input id="setting_ssl_verify" type="checkbox"
                                    ${s.ssl_verify?'checked':''}>
                                SSL証明書を検証する
                            </label>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button onclick="App.actions.saveSettings()"
                                class="bg-indigo-600 text-white px-4 py-2 rounded-lg">
                                設定を保存
                            </button>
                            <button onclick="App.actions.kintoneTest()"
                                class="border px-4 py-2 rounded-lg">
                                kintone接続確認
                            </button>
                            <button onclick="App.actions.fetchKintoneFields()"
                                class="border px-4 py-2 rounded-lg">
                                項目一覧を取得
                            </button>
                            <button onclick="App.actions.syncCustomers()"
                                class="border px-4 py-2 rounded-lg">
                                顧客データを同期
                            </button>
                        </div>

                        <div id="field_message" class="mt-4"></div>
                    </section>

                    <section class="bg-white rounded-2xl border p-5">
                        <h2 class="font-bold text-lg mb-4">kintoneフィールドマッピング</h2>
                        <p class="text-sm text-slate-500 mb-4">
                            「項目一覧を取得」で取得した日本語フィールド名から選択します。
                        </p>

                        <div id="mapping_area">
                            ${App.render.mapping(s)}
                        </div>
                    </section>

                    <section class="bg-white rounded-2xl border p-5">
                        <h2 class="font-bold text-lg mb-4">SMTPサーバ設定</h2>

                        <div class="grid md:grid-cols-2 gap-4">
                            <label>
                                SMTPサーバ
                                <input id="smtp_host"
                                    value="${App.util.esc(s.smtp_host||'')}"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label>
                                SMTPポート
                                <input id="smtp_port" type="number"
                                    value="${App.util.esc(s.smtp_port||587)}"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label>
                                暗号化方式
                                <select id="smtp_encryption"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                                    <option value="none" ${s.smtp_encryption==='none'?'selected':''}>なし</option>
                                    <option value="ssl" ${s.smtp_encryption==='ssl'?'selected':''}>SSL</option>
                                    <option value="tls" ${s.smtp_encryption==='tls'||!s.smtp_encryption?'selected':''}>TLS</option>
                                </select>
                            </label>

                            <label>
                                SMTP認証
                                <select id="smtp_auth"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                                    <option value="0" ${!s.smtp_auth?'selected':''}>認証しない</option>
                                    <option value="1" ${s.smtp_auth?'selected':''}>認証する</option>
                                </select>
                            </label>

                            <label>
                                SMTPユーザー名
                                <input id="smtp_username"
                                    value="${App.util.esc(s.smtp_username||'')}"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label>
                                SMTPパスワード
                                <input id="smtp_password" type="password"
                                    placeholder="変更しない場合は空欄"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label>
                                送信元メールアドレス
                                <input id="smtp_from"
                                    value="${App.util.esc(s.smtp_from||'')}"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label>
                                送信元表示名
                                <input id="smtp_from_name"
                                    value="${App.util.esc(s.smtp_from_name||'')}"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>

                            <label>
                                接続タイムアウト
                                <input id="smtp_timeout" type="number"
                                    value="${App.util.esc(s.smtp_timeout||15)}"
                                    class="w-full border rounded-lg px-3 py-2 mt-1">
                            </label>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button onclick="App.actions.saveSettings()"
                                class="bg-indigo-600 text-white px-4 py-2 rounded-lg">
                                SMTP設定を保存
                            </button>

                            <button onclick="App.actions.smtpTest()"
                                class="border px-4 py-2 rounded-lg">
                                SMTP接続確認
                            </button>

                            <button onclick="App.actions.smtpMail()"
                                class="border px-4 py-2 rounded-lg">
                                テストメール送信
                            </button>
                        </div>

                        <div id="smtp_message" class="mt-4"></div>
                    </section>
                </div>
            `);
        },

        mapping(s) {
            const names = [
                ['field_company','会社名'],
                ['field_name','氏名'],
                ['field_email','メールアドレス'],
                ['field_department','部署名'],
                ['field_phone','電話番号']
            ];

            return names.map(x=>App.render.fieldSelect(x[0],x[1],s[x[0]]||'')).join('') +
                `<div class="mt-4">
                    <div class="font-semibold mb-2">住所</div>
                    <div class="text-sm text-slate-500 mb-2">
                        複数フィールドを結合する場合は複数選択してください。
                    </div>
                    ${App.render.fieldSelect('field_address','住所',s.field_address||'',true)}
                </div>`;
        },

        fieldSelect(key,label,value,multiple=false) {
            const vals = Array.isArray(value)
                ? value
                : String(value||'').split(',').filter(Boolean);

            return `
            <label class="block mb-3">
                <span class="text-sm font-semibold">${label}</span>
                <select data-map="${key}"
                    ${multiple?'multiple':''}
                    class="w-full border rounded-lg px-3 py-2 mt-1 min-h-11">
                    <option value="">-- 選択してください --</option>
                    ${App.state.fields.map(f=>`
                        <option value="${App.util.esc(f.code)}"
                            ${vals.includes(f.code)?'selected':''}>
                            ${App.util.esc(f.label)} (${App.util.esc(f.code)})
                        </option>
                    `).join('')}
                </select>
            </label>`;
        },

        send(survey) {
            const customers = App.state.data.customers.filter(c=>{
                if (App.state.customerFilter &&
                    !(String(c.name||'')+' '+String(c.email||''))
                        .toLowerCase()
                        .includes(App.state.customerFilter.toLowerCase())) {
                    return false;
                }

                if (App.state.customerStatus==='unanswered' &&
                    c.answer_status!=='unanswered') return false;

                if (App.state.customerStatus==='answered' &&
                    c.answer_status!=='answered') return false;

                return true;
            });

            return App.render.shell(`
                <div class="mb-5">
                    <div class="text-sm text-slate-400">
                        ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
                    </div>
                    <h1 class="text-2xl font-bold mt-1">
                        メール送信・回答フォロー
                    </h1>
                    <p class="mt-1 text-slate-500">${App.util.esc(survey.title)}</p>
                </div>

                <div class="bg-white rounded-2xl border p-5 mb-5">
                    <div class="grid md:grid-cols-2 gap-4">
                        <label>
                            件名
                            <input id="mail_subject"
                                value="アンケートご協力のお願い"
                                class="w-full border rounded-lg px-3 py-2 mt-1">
                        </label>

                        <label>
                            種別
                            <select id="template_type"
                                class="w-full border rounded-lg px-3 py-2 mt-1">
                                <option value="initial">初回送信</option>
                                <option value="reminder">リマインド</option>
                            </select>
                        </label>

                        <label class="md:col-span-2">
                            本文
                            <textarea id="mail_body" rows="7"
                                class="w-full border rounded-lg px-3 py-2 mt-1">{顧客名} 様

アンケートへのご協力をお願いいたします。

回答はこちら：
{アンケートURL}</textarea>
                        </label>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <input id="customer_filter"
                            oninput="App.actions.customerFilter(this.value)"
                            placeholder="顧客名・メールアドレス検索"
                            class="border rounded-lg px-3 py-2">

                        <select onchange="App.actions.customerStatus(this.value)"
                            class="border rounded-lg px-3 py-2">
                            <option value="all">すべて</option>
                            <option value="unanswered">未回答</option>
                            <option value="answered">回答済み</option>
                        </select>

                        <button onclick="App.actions.selectAll(true)"
                            class="border rounded-lg px-4 py-2">
                            全選択
                        </button>

                        <button onclick="App.actions.selectAll(false)"
                            class="border rounded-lg px-4 py-2">
                            全解除
                        </button>

                        <button onclick="App.actions.bulkSend('${survey.id}')"
                            class="bg-indigo-600 text-white rounded-lg px-5 py-2 font-semibold">
                            一括送信実行
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="p-3"><input id="select_all" type="checkbox"
                                    onchange="App.actions.selectAll(this.checked)"></th>
                                <th class="p-3 text-left">会社名 / 氏名</th>
                                <th class="p-3 text-left">メール</th>
                                <th class="p-3 text-left">電話番号</th>
                                <th class="p-3 text-left">住所</th>
                                <th class="p-3 text-left">送信履歴</th>
                                <th class="p-3 text-left">回答</th>
                                <th class="p-3 text-left">kintone</th>
                            </tr>
                        </thead>
                        <tbody id="customer_table">
                            ${customers.map(c=>`
                            <tr class="border-t">
                                <td class="p-3">
                                    <input class="recipient" type="checkbox"
                                        value="${App.util.esc(c.id)}"
                                        ${c.source==='web'?'disabled':''}>
                                </td>
                                <td class="p-3">
                                    <b>${App.util.esc(c.company)}</b><br>
                                    ${App.util.esc(c.name)}
                                </td>
                                <td class="p-3">${App.util.esc(c.email)}</td>
                                <td class="p-3">${App.util.esc(c.phone)}</td>
                                <td class="p-3">${App.util.esc(c.address)}</td>
                                <td class="p-3">
                                    ${App.util.fmt(c.sent_at)}<br>
                                    ${c.send_count||0} 回
                                </td>
                                <td class="p-3">
                                    <span class="px-2 py-1 rounded-full text-xs ${
                                        c.answer_status==='answered'
                                            ? 'bg-emerald-100 text-emerald-700'
                                            : 'bg-amber-100 text-amber-700'
                                    }">
                                        ${c.answer_status==='answered'?'回答済み':'送信済み（未回答）'}
                                    </span>
                                </td>
                                <td class="p-3">
                                    <button onclick="App.actions.register('${c.id}')"
                                        class="text-indigo-600">
                                        ${c.kintone_status==='registered'
                                            ? '✓ キントーン登録完了'
                                            : 'キントーン登録完了'}
                                    </button>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 bg-white rounded-2xl border p-5">
                    <h2 class="font-bold mb-4">一括送信ログ</h2>
                    ${App.state.data.mail_logs
                        .filter(x=>x.survey_id===survey.id)
                        .slice().reverse().map(l=>`
                        <div class="border-b py-3 last:border-0">
                            ${App.util.fmt(l.sent_at)}
                            ／ ${App.util.esc(l.type)}
                            ／ ${l.target_count}件
                            ／ 成功 ${l.success_count}
                            ／ 失敗 ${l.failed_count}
                        </div>
                    `).join('') || '<div class="text-slate-400">送信履歴はありません。</div>'}
                </div>
            `);
        },

        aggregate(survey) {
            const responses = App.state.data.responses.filter(
                r=>r.survey_id===survey.id
            );

            const sent = App.state.data.customers.filter(
                c=>c.sent_at
            ).length;

            const answeredFromTargets = responses.filter(r=>r.customer_id).length;
            const unregistered = responses.filter(
                r=>!r.customer_id
            ).length;

            const unanswered = Math.max(0,sent-answeredFromTargets);
            const rate = sent ? ((answeredFromTargets/sent)*100).toFixed(1) : '0.0';

            const questions = App.util.questions(survey);

            return App.render.shell(`
                <div class="mb-5">
                    <div class="text-sm text-slate-400">
                        ホーム ＞ アンケート一覧 ＞ 集計
                    </div>
                    <h1 class="text-2xl font-bold mt-1">
                        ${App.util.esc(survey.title)}
                    </h1>
                </div>

                <div class="grid md:grid-cols-5 gap-3 mb-5">
                    ${[
                        ['送信対象者数',sent+' 人'],
                        ['回答数',responses.length+' 件'],
                        ['未登録顧客からの回答数',unregistered+' 件'],
                        ['未回答数',unanswered+' 人'],
                        ['回答率',rate+' %']
                    ].map(x=>`
                    <div class="bg-white border rounded-2xl p-4">
                        <div class="text-sm text-slate-500">${x[0]}</div>
                        <div class="text-2xl font-bold mt-2">${x[1]}</div>
                    </div>`).join('')}
                </div>

                <div class="bg-white rounded-2xl border p-5 mb-5">
                    <div class="flex justify-between mb-4">
                        <h2 class="font-bold text-lg">設問別集計</h2>
                        <div class="flex gap-2">
                            <button onclick="App.actions.toggleAllQuestions(true)"
                                class="border rounded px-3 py-1">全選択</button>
                            <button onclick="App.actions.toggleAllQuestions(false)"
                                class="border rounded px-3 py-1">全解除</button>
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-2 mb-5">
                        ${questions.map(q=>`
                            <label class="flex items-center gap-2">
                                <input class="response_filter"
                                    type="checkbox"
                                    checked
                                    data-qid="${q.id}"
                                    onchange="App.actions.renderAggregation()">
                                ${App.util.esc(q.number)} ${App.util.esc(q.text)}
                            </label>
                        `).join('')}
                    </div>

                    <div id="response_filter">
                    ${questions.map(q=>App.render.questionStats(q,responses)).join('')}
                    </div>
                </div>

                <div class="bg-white rounded-2xl border overflow-x-auto">
                    <div class="p-5 border-b flex justify-between">
                        <h2 class="font-bold">個別回答一覧</h2>
                        <div class="flex gap-2">
                            <input placeholder="会社名・氏名"
                                oninput="App.actions.responseSearch(this.value)"
                                class="border rounded-lg px-3 py-2">
                            <a href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
                                class="px-4 py-2 border rounded-lg">
                                CSV出力
                            </a>
                        </div>
                    </div>

                    <table class="w-full text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="p-3 text-left">会社名</th>
                                <th class="p-3 text-left">氏名</th>
                                <th class="p-3 text-left">回答日時</th>
                                <th class="p-3 text-left">操作</th>
                            </tr>
                        </thead>
                        <tbody id="response_table">
                        ${responses.map(r=>`
                            <tr class="border-t response-row">
                                <td class="p-3">${App.util.esc(r.company)}</td>
                                <td class="p-3">${App.util.esc(r.name)}</td>
                                <td class="p-3">${App.util.fmt(r.answered_at)}</td>
                                <td class="p-3">
                                    <button onclick="App.actions.responseDetail('${r.id}')"
                                        class="text-indigo-600">
                                        全回答を表示
                                    </button>
                                </td>
                            </tr>`).join('') || `
                            <tr>
                                <td colspan="4" class="p-12 text-center text-slate-400">
                                    現在、回答データはありません
                                </td>
                            </tr>`}
                        </tbody>
                    </table>
                </div>
            `);
        },

        questionStats(q,responses) {
            const counts = {};
            (q.options||[]).forEach(o=>counts[o]=0);

            let other = 0;

            responses.forEach(r=>{
                let v = r.answers?.[q.id];

                if (Array.isArray(v)) {
                    v.forEach(x=>{
                        if (counts[x] !== undefined) counts[x]++;
                    });
                } else if (v !== undefined && v !== '') {
                    if (counts[v] !== undefined) counts[v]++;
                    else other++;
                }
            });

            if (q.type==='text') {
                const texts = responses.map(r=>({
                    name:r.name,
                    company:r.company,
                    value:r.answers?.[q.id] || ''
                })).filter(x=>x.value);

                return `
                <div class="border rounded-xl p-4 mb-3">
                    <div class="font-bold mb-3">
                        ${App.util.esc(q.number)} ${App.util.esc(q.text)}
                    </div>
                    ${texts.length
                        ? texts.map(x=>`
                            <div class="border-t py-3">
                                <b>${App.util.esc(x.company)} ${App.util.esc(x.name)}</b>
                                <div class="mt-1">${App.util.esc(x.value)}</div>
                            </div>`).join('')
                        : '<div class="text-slate-400">回答なし</div>'}
                </div>`;
            }

            return `
            <div class="border rounded-xl p-4 mb-3">
                <div class="font-bold mb-3">
                    ${App.util.esc(q.number)} ${App.util.esc(q.text)}
                </div>
                ${Object.entries(counts).map(([label,count])=>{
                    const pct = responses.length
                        ? Math.round(count/responses.length*100) : 0;

                    return `
                    <div class="mb-3">
                        <div class="flex justify-between text-sm">
                            <span>${App.util.esc(label)}</span>
                            <span>${count}件 (${pct}%)</span>
                        </div>
                        <div class="h-3 bg-slate-100 rounded-full overflow-hidden mt-1">
                            <div class="h-full bg-indigo-500" style="width:${pct}%"></div>
                        </div>
                    </div>`;
                }).join('')}
                ${other?`<div class="text-sm text-slate-500">その他自由記述 ${other}件</div>`:''}
            </div>`;
        },

        responseModal(r,survey) {
            const qs = App.util.questions(survey);

            return `
            <div id="response_modal"
                class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-5">
                <div class="bg-white rounded-2xl max-w-3xl w-full max-h-[90vh] overflow-auto">
                    <div class="p-4 border-b flex justify-between">
                        <b>回答詳細</b>
                        <button onclick="App.actions.closeResponse()">×</button>
                    </div>
                    <div id="response_detail" class="p-5">
                        <div class="mb-5">
                            <b>${App.util.esc(r.company)}</b>
                            ${App.util.esc(r.name)}
                            ／ ${App.util.esc(r.email)}
                        </div>
                        ${qs.map(q=>`
                            <div class="border-b py-4">
                                <div class="font-semibold">
                                    ${App.util.esc(q.number)} ${App.util.esc(q.text)}
                                </div>
                                <div class="mt-2">
                                    ${App.util.esc(
                                        Array.isArray(r.answers?.[q.id])
                                            ? r.answers[q.id].join('、')
                                            : (r.answers?.[q.id] ?? '未回答')
                                    )}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            </div>`;
        }
    },

    actions: {
        async init() {
            if (App.state.initialized) return;
            App.state.initialized = true;

            try {
                await App.api.load();
                App.renderScreen();
            } catch(e) {
                document.getElementById('app').innerHTML = `
                    <div class="min-h-screen flex items-center justify-center p-6">
                        <div class="bg-white border rounded-2xl p-8 max-w-xl">
                            <h1 class="text-xl font-bold text-red-600">
                                初期化に失敗しました
                            </h1>
                            <p class="mt-3">${App.util.esc(e.message)}</p>
                            <p class="mt-3 text-sm text-slate-500">
                                データファイルへのアクセス権限、survey_storageディレクトリの権限、
                                PHP設定などを確認してください。
                            </p>
                        </div>
                    </div>`;
            }
        },

        go(screen) {
            App.state.screen = screen;
            App.renderScreen();
        },

        renderScreen() {
            if (App.state.screen === 'list') {
                document.getElementById('app').innerHTML =
                    App.render.list();
            }

            if (App.state.screen === 'editor') {
                App.renderEditor();
            }

            if (App.state.screen === 'settings') {
                document.getElementById('app').innerHTML =
                    App.render.settings();
            }

            if (App.state.screen === 'send') {
                document.getElementById('app').innerHTML =
                    App.render.send(App.state.selectedSurvey);
            }

            if (App.state.screen === 'aggregate') {
                document.getElementById('app').innerHTML =
                    App.render.aggregate(App.state.selectedSurvey);
            }

            App.actions.mountSortables();
        },

        settings() {
            App.state.screen = 'settings';
            App.renderScreen();
        },

        filter(v) {
            App.state.filter = v;
            App.renderScreen();
        },

        statusFilter(v) {
            App.state.statusFilter = v;
            App.renderScreen();
        },

        sort(v) {
            App.state.sort = v;
            App.renderScreen();
        },

        newSurvey() {
            App.state.editSurvey = {
                id:App.util.uid('survey'),
                title:'新しいアンケート',
                start_at:'',
                end_at:'',
                status:'draft',
                created_at:'',
                updated_at:'',
                numbering_mode:'global',
                groups:[],
                deleted:false
            };

            App.actions.addGroup(false);
            App.state.screen = 'editor';
            App.renderScreen();
        },

        edit(id) {
            const s = App.util.survey(id);
            if (!s) return;

            App.state.editSurvey = JSON.parse(JSON.stringify(s));
            App.state.selectedSurvey = s;
            App.state.screen = 'editor';
            App.renderScreen();
        },

        editField(key,value) {
            if (!App.state.editSurvey) return;
            App.state.editSurvey[key] = value;
        },

        groupName(id,value) {
            const g = App.state.editSurvey.groups.find(x=>x.id===id);
            if (g) g.name = value;
        },

        addGroup(render=true) {
            if (!App.state.editSurvey) return;

            const g = {
                id:App.util.uid('group'),
                name:'新しいグループ',
                questions:[]
            };

            App.state.editSurvey.groups.push(g);

            if (render) App.renderEditor();
        },

        deleteGroup(id) {
            if (!confirm('このグループと内包する質問を削除しますか？')) return;

            App.state.editSurvey.groups =
                App.state.editSurvey.groups.filter(x=>x.id!==id);

            App.renderEditor();
        },

        addQuestion(groupId) {
            const g = App.state.editSurvey.groups.find(x=>x.id===groupId);
            if (!g) return;

            g.questions.push({
                id:App.util.uid('question'),
                text:'質問文を入力してください',
                type:'single',
                required:false,
                options:['選択肢1','選択肢2'],
                other_enabled:false
            });

            App.util.renumber(App.state.editSurvey);
            App.renderEditor();
        },

        deleteQuestion(id) {
            if (!confirm('この質問を削除しますか？')) return;

            App.state.editSurvey.groups.forEach(g=>{
                g.questions = g.questions.filter(q=>q.id!==id);
            });

            App.util.renumber(App.state.editSurvey);
            App.renderEditor();
        },

        questionField(id,key,value) {
            const q = App.util.questions(App.state.editSurvey)
                .find(x=>x.id===id);

            if (!q) return;

            q[key] = value;
            App.util.renumber(App.state.editSurvey);

            if (key === 'type') App.renderEditor();
        },

        option(id,index,value) {
            const q = App.util.questions(App.state.editSurvey)
                .find(x=>x.id===id);

            if (q) q.options[index] = value;
        },

        addOption(id) {
            const q = App.util.questions(App.state.editSurvey)
                .find(x=>x.id===id);

            if (q) {
                q.options.push('新しい選択肢');
                App.renderEditor();
            }
        },

        removeOption(id,index) {
            const q = App.util.questions(App.state.editSurvey)
                .find(x=>x.id===id);

            if (q) {
                q.options.splice(index,1);
                App.renderEditor();
            }
        },

        renderEditor() {
            document.getElementById('app').innerHTML =
                App.render.editor(App.state.editSurvey);

            App.actions.mountSortables();
        },

        mountSortables() {
            if (!window.Sortable) return;

            document.querySelectorAll('.question-list').forEach(el=>{
                if (el.dataset.sortMounted) return;

                new Sortable(el,{
                    group:'survey_questions',
                    handle:'.question-handle',
                    animation:180,
                    ghostClass:'opacity-40',
                    onEnd:()=>{
                        App.actions.readQuestionOrder();
                    }
                });

                el.dataset.sortMounted = '1';
            });

            const editor = document.getElementById('question_editor');

            if (editor && !editor.dataset.sortMounted) {
                new Sortable(editor,{
                    handle:'.group-handle',
                    animation:180,
                    ghostClass:'opacity-40',
                    onEnd:()=>{
                        const ids = [...editor.querySelectorAll('.group-card')]
                            .map(x=>x.dataset.groupId);

                        App.state.editSurvey.groups.sort(
                            (a,b)=>ids.indexOf(a.id)-ids.indexOf(b.id)
                        );

                        App.util.renumber(App.state.editSurvey);
                        App.renderEditor();
                    }
                });

                editor.dataset.sortMounted = '1';
            }
        },

        readQuestionOrder() {
            const s = App.state.editSurvey;

            document.querySelectorAll('.question-list').forEach(el=>{
                const gid = el.dataset.groupId;
                const g = s.groups.find(x=>x.id===gid);
                if (!g) return;

                const ids = [...el.querySelectorAll('.question-card')]
                    .map(x=>x.dataset.questionId);

                const all = App.util.questions(s);

                g.questions = ids.map(id=>all.find(q=>q.id===id))
                    .filter(Boolean);
            });

            App.util.renumber(s);
            App.renderEditor();
        },

        async saveSurvey() {
            const r = await App.api.post('save_survey',{
                survey_json:App.state.editSurvey
            });

            if (!r.ok) {
                alert(r.message || '保存に失敗しました。');
                return;
            }

            await App.api.load();
            alert('保存しました。');
            App.state.screen = 'list';
            App.renderScreen();
        },

        cancelEdit() {
            if (!confirm('未保存の変更を破棄して一覧へ戻りますか？')) return;

            App.state.screen = 'list';
            App.renderScreen();
        },

        preview() {
            const el = document.createElement('div');
            el.innerHTML = App.render.preview();
            document.body.appendChild(el.firstElementChild);
        },

        closePreview() {
            document.getElementById('preview_modal')?.remove();
        },

        previewMode(v) {
            App.state.previewMode = v;
            App.closePreview();
            App.preview();
        },

        async stop(id) {
            if (!confirm('アンケートを停止しますか？')) return;

            const s = App.util.survey(id);
            if (!s) return;

            s.status = 'ended';

            const r = await App.api.post('save_survey',{
                survey_json:s
            });

            if (!r.ok) alert(r.message);
            await App.api.load();
            App.renderScreen();
        },

        async deleteSurvey(id) {
            if (!confirm('削除しますか？')) return;

            const r = await App.api.post('delete_survey',{
                survey_id:id
            });

            if (!r.ok) alert(r.message);
            await App.api.load();
            App.renderScreen();
        },

        async duplicate(id) {
            const r = await App.api.post('duplicate_survey',{
                survey_id:id
            });

            if (!r.ok) {
                alert(r.message);
                return;
            }

            await App.api.load();
            App.renderScreen();
        },

        send(id) {
            App.state.selectedSurvey = App.util.survey(id);
            App.state.screen = 'send';
            App.renderScreen();
        },

        aggregate(id) {
            App.state.selectedSurvey = App.util.survey(id);
            App.state.screen = 'aggregate';
            App.renderScreen();
        },

        customerFilter(v) {
            App.state.customerFilter = v;
            App.renderScreen();
        },

        customerStatus(v) {
            App.state.customerStatus = v;
            App.renderScreen();
        },

        selectAll(value) {
            document.querySelectorAll('.recipient:not(:disabled)')
                .forEach(x=>x.checked=value);
        },

        async bulkSend(surveyId) {
            const ids = [...document.querySelectorAll('.recipient:checked')]
                .map(x=>x.value);

            if (!ids.length) {
                alert('送信対象を選択してください。');
                return;
            }

            const already = ids.filter(id=>{
                const c = App.state.data.customers.find(x=>x.id===id);
                return c && Number(c.send_count||0)>0;
            });

            if (already.length &&
                !confirm(
                    '既に送信済みの宛先が含まれています。再送しますか？'
                )) return;

            const r = await App.api.post('send_mail',{
                survey_id:surveyId,
                recipient_ids:ids,
                mail_subject:document.getElementById('mail_subject').value,
                mail_body:document.getElementById('mail_body').value,
                template_type:document.getElementById('template_type').value
            });

            if (!r.ok) {
                alert(r.message || '送信に失敗しました。');
                return;
            }

            await App.api.load();

            alert(
                '送信完了\n' +
                '成功: '+r.success+'件\n' +
                '失敗: '+r.failed+'件\n' +
                '未送信: '+r.unsent+'件'
            );

            App.renderScreen();
        },

        async register(id) {
            const r = await App.api.post('register_customer',{
                customer_id:id
            });

            if (!r.ok) alert(r.message);

            await App.api.load();
            App.renderScreen();
        },

        async saveSettings() {
            const old = App.state.data.settings || {};

            const selected = {};

            document.querySelectorAll('[data-map]').forEach(el=>{
                if (el.multiple) {
                    selected[el.dataset.map] =
                        [...el.selectedOptions].map(x=>x.value).filter(Boolean);
                } else {
                    selected[el.dataset.map] = el.value;
                }
            });

            const s = {
                ...old,
                subdomain:document.getElementById('setting_subdomain')?.value.trim() || '',
                app_id:document.getElementById('setting_app_id')?.value.trim() || '',
                login_name:document.getElementById('setting_login_name')?.value.trim() || '',
                password:document.getElementById('setting_password')?.value || '',
                proxy:document.getElementById('setting_proxy')?.value.trim() || '',
                ssl_verify:document.getElementById('setting_ssl_verify')?.checked || false,

                ...selected,

                smtp_host:document.getElementById('smtp_host')?.value.trim() || '',
                smtp_port:document.getElementById('smtp_port')?.value || '',
                smtp_encryption:document.getElementById('smtp_encryption')?.value || 'tls',
                smtp_auth:document.getElementById('smtp_auth')?.value === '1',
                smtp_username:document.getElementById('smtp_username')?.value.trim() || '',
                smtp_password:document.getElementById('smtp_password')?.value || '',
                smtp_from:document.getElementById('smtp_from')?.value.trim() || '',
                smtp_from_name:document.getElementById('smtp_from_name')?.value.trim() || '',
                smtp_timeout:document.getElementById('smtp_timeout')?.value || 15
            };

            const r = await App.api.post('save_settings',{
                settings_json:s
            });

            if (!r.ok) {
                alert(r.message || '設定保存に失敗しました。');
                return;
            }

            await App.api.load();

            document.getElementById('field_message').innerHTML =
                `<div class="bg-emerald-50 text-emerald-700 p-3 rounded-lg">
                    設定を保存しました。
                </div>`;
        },

        async kintoneTest() {
            const r = await App.api.post('kintone_test');

            const el = document.getElementById('field_message');

            el.innerHTML = `
                <div class="${r.ok?'bg-emerald-50 text-emerald-700':'bg-red-50 text-red-700'}
                    p-4 rounded-xl">
                    <div class="font-bold">${r.ok?'接続成功':'接続失敗'}</div>
                    <div class="mt-1">${App.util.esc(r.message)}</div>
                    <pre class="text-xs mt-3 whitespace-pre-wrap">${App.util.esc(
                        JSON.stringify(r.diagnostic || {},null,2)
                    )}</pre>
                </div>`;
        },

        /**
         * 必須関数:
         * kintone APIからpropertiesを取得してドロップダウン生成。
         */
        async fetchKintoneFields() {
            const appId =
                document.getElementById('setting_app_id')?.value.trim() || '';

            if (!appId) {
                alert('顧客管理アプリIDを入力してください。');
                return;
            }

            /*
             * 保存前でも現在入力中の認証情報を使用できるよう、
             * 一旦設定保存を行う。
             */
            await App.actions.saveSettings();

            const r = await App.api.post('kintone_fields',{
                app_id:appId
            });

            const msg = document.getElementById('field_message');

            if (!r.ok) {
                msg.innerHTML = `
                    <div class="bg-red-50 text-red-700 p-4 rounded-xl">
                        <b>項目一覧取得に失敗しました。</b>
                        <div class="mt-1">${App.util.esc(r.message)}</div>
                        <div class="text-sm mt-2">
                            HTTPステータス: ${App.util.esc(r.status)}
                        </div>
                        <pre class="text-xs mt-2 whitespace-pre-wrap">${App.util.esc(
                            JSON.stringify(r.response||{},null,2)
                        )}</pre>
                    </div>`;
                return;
            }

            App.state.fields = r.fields || [];

            document.getElementById('mapping_area').innerHTML =
                App.render.mapping(App.state.data.settings || {});

            msg.innerHTML = `
                <div class="bg-emerald-50 text-emerald-700 p-3 rounded-lg">
                    ${App.state.fields.length}件のフィールドを取得しました。
                </div>`;
        },

        async syncCustomers() {
            const r = await App.api.post('sync_customers');

            const el = document.getElementById('field_message');

            el.innerHTML = `
                <div class="${r.ok?'bg-emerald-50 text-emerald-700':'bg-red-50 text-red-700'}
                    p-4 rounded-xl">
                    ${App.util.esc(r.message || (
                        r.ok
                            ? r.count+'件の顧客データを同期しました。'
                            : '同期に失敗しました。'
                    ))}
                </div>`;

            if (r.ok) await App.api.load();
        },

        async smtpTest() {
            const r = await App.api.post('smtp_test_connection');

            document.getElementById('smtp_message').innerHTML = `
                <div class="${r.ok?'bg-emerald-50 text-emerald-700':'bg-red-50 text-red-700'}
                    p-4 rounded-xl">
                    <div class="font-bold">
                        ${r.ok?'SMTP接続成功':'SMTP接続失敗'}
                    </div>
                    <div class="mt-2">${App.util.esc(r.message)}</div>
                    <pre class="text-xs mt-3 whitespace-pre-wrap">${App.util.esc(
                        JSON.stringify(r,null,2)
                    )}</pre>
                </div>`;
        },

        async smtpMail() {
            const to = prompt('テストメール送信先アドレスを入力してください。');

            if (!to) return;

            await App.actions.saveSettings();

            const r = await App.api.post('smtp_test_mail',{
                test_email:to
            });

            document.getElementById('smtp_message').innerHTML = `
                <div class="${r.ok?'bg-emerald-50 text-emerald-700':'bg-red-50 text-red-700'}
                    p-4 rounded-xl">
                    <b>${r.ok?'送信成功':'送信失敗'}</b>
                    <div>${App.util.esc(r.message)}</div>
                    ${r.stage?`<div class="text-sm mt-2">診断段階: ${App.util.esc(r.stage)}</div>`:''}
                </div>`;
        },

        toggleAllQuestions(flag) {
            document.querySelectorAll('.response_filter')
                .forEach(x=>x.checked=flag);

            App.actions.renderAggregation();
        },

        renderAggregation() {
            const s = App.state.selectedSurvey;
            const responses = App.state.data.responses.filter(
                r=>r.survey_id===s.id
            );

            const checked = [...document.querySelectorAll('.response_filter:checked')]
                .map(x=>x.dataset.qid);

            document.getElementById('response_filter').innerHTML =
                App.util.questions(s)
                    .filter(q=>checked.includes(q.id))
                    .map(q=>App.render.questionStats(q,responses))
                    .join('');
        },

        responseSearch(v) {
            const rows = document.querySelectorAll('.response-row');

            rows.forEach(row=>{
                row.style.display =
                    row.innerText.toLowerCase().includes(v.toLowerCase())
                        ? ''
                        : 'none';
            });
        },

        responseDetail(id) {
            const r = App.state.data.responses.find(x=>x.id===id);
            const s = App.state.selectedSurvey;

            if (!r || !s) return;

            const el = document.createElement('div');
            el.innerHTML = App.render.responseModal(r,s);
            document.body.appendChild(el.firstElementChild);
        },

        closeResponse() {
            document.getElementById('response_modal')?.remove();
        }
    }
};

/* --------------------------------------------------------------------------
 * 安全な初期化トリガー
 * -------------------------------------------------------------------------- */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => App.actions.init(), {once:true});
} else {
    App.actions.init();
}
</script>

</body>
</html>
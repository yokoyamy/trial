<?php
declare(strict_types=1);

/*
========================================================================
GUARD COMMENT — 固定名称一覧
※以下の名称は、今後の修正・再生成時も変更・削除禁止。

ストレージ:
- survey_storage_directory
- survey_storage_file
- survey_admin_session_v1

データトップキー:
- surveys
- responses
- customers
- settings
- mail_logs

アンケート項目:
- id
- title
- start_at
- end_at
- status
- created_at
- updated_at
- numbering_mode
- groups
- deleted

グループ項目:
- id
- name
- questions

質問項目:
- id
- text
- type
- required
- options
- other_enabled
- option_branches

質問形式:
- single
- multiple
- text

顧客項目:
- id
- company
- name
- email
- department
- phone
- address
- source
- sent_at
- send_count
- answer_status
- kintone_status

回答項目:
- id
- survey_id
- customer_id
- company
- name
- email
- answered_at
- answers

設定項目:
- subdomain
- login_name
- password
- app_id
- ssl_verify
- proxy
- field_company
- field_name
- field_email
- field_department
- field_phone
- field_address

POST/GETパラメータ:
- action
- survey_id
- customer_id
- response_id
- keyword
- status_filter
- sort
- survey_json
- settings_json
- csrf_token
- recipient_ids
- mail_subject
- mail_body
- template_type
- app_id

API/JSONキー:
- properties
- records
- label
- code
- type
- message
- ok
- fields

HTML DOM ID / JS参照名:
- app
- csrf_token
- survey_title
- survey_start_at
- survey_end_at
- survey_numbering_mode
- question_editor
- preview_modal
- preview_content
- response_modal
- response_detail
- response_filter
- response_table
- customer_filter
- customer_table
- select_all
- mail_subject
- mail_body
- template_type
- settings_form
- settings_json
- setting_subdomain
- setting_app_id
- setting_login_name
- setting_password
- setting_proxy
- setting_ssl_verify
- field_message

取り得る値:
- status: draft / active / ended
- numbering_mode: global / group
- type: single / multiple / text
- source: kintone / web
- answer_status: unanswered / answered
- kintone_status: unregistered / registered
- template_type: initial / reminder

追加固定項目:
- option_branches
- option_index
- target_question_id
========================================================================
*/

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

function survey_h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_json(mixed $v): string {
    return json_encode(
        $v,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: 'null';
}

function survey_id(): string {
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable) {
        return sha1(uniqid('', true));
    }
}

function survey_now(): string {
    return date('Y-m-d H:i:s');
}

function survey_default_data(): array {
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'subdomain' => '',
            'login_name' => '',
            'password' => '',
            'app_id' => '',
            'ssl_verify' => true,
            'proxy' => '',
            'field_company' => '',
            'field_name' => '',
            'field_email' => '',
            'field_department' => '',
            'field_phone' => '',
            'field_address' => [],
        ],
        'mail_logs' => [],
    ];
}

function survey_read_data(): array {
    if (!is_file(SURVEY_STORAGE_FILE)) {
        return survey_default_data();
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    if (!is_string($raw) || $raw === '') {
        return survey_default_data();
    }

    $data = json_decode($raw, true);

    return is_array($data)
        ? array_replace_recursive(survey_default_data(), $data)
        : survey_default_data();
}

function survey_write_data(array $data): bool {
    if (!is_dir(SURVEY_STORAGE_DIRECTORY) &&
        !@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true)) {
        return false;
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    if (@file_put_contents(
        $tmp,
        survey_json($data),
        LOCK_EX
    ) === false) {
        return false;
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function survey_check_token(): bool {
    $a = (string)($_SESSION['csrf_token'] ?? '');
    $b = (string)($_POST['csrf_token'] ?? '');

    return $a !== '' && $b !== '' && hash_equals($a, $b);
}

function survey_api(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo survey_json($data);
    exit;
}

/* =========================================================
 * Survey normalization / branch validation
 * ========================================================= */

function survey_question_index(array $survey): array {
    $out = [];

    foreach (($survey['groups'] ?? []) as $g) {
        foreach (($g['questions'] ?? []) as $q) {
            if (!empty($q['id'])) {
                $out[(string)$q['id']] = $q;
            }
        }
    }

    return $out;
}

function survey_normalize_question(array $q): array {
    $q['id'] = (string)($q['id'] ?? survey_id());
    $q['text'] = (string)($q['text'] ?? '');
    $q['type'] = in_array(
        ($q['type'] ?? 'single'),
        ['single', 'multiple', 'text'],
        true
    ) ? $q['type'] : 'single';

    $q['required'] = !empty($q['required']);
    $q['other_enabled'] = !empty($q['other_enabled']);
    $q['options'] = is_array($q['options'] ?? null)
        ? array_values(array_map('strval', $q['options']))
        : [];

    $branches = $q['option_branches'] ?? [];
    $q['option_branches'] = is_array($branches) ? $branches : [];

    foreach ($q['options'] as $i => $_) {
        if (!array_key_exists((string)$i, $q['option_branches'])) {
            $q['option_branches'][(string)$i] = '';
        }
    }

    return $q;
}

function survey_normalize_survey(array $s): array {
    $s['id'] = (string)($s['id'] ?? survey_id());
    $s['title'] = (string)($s['title'] ?? '無題のアンケート');
    $s['start_at'] = (string)($s['start_at'] ?? '');
    $s['end_at'] = (string)($s['end_at'] ?? '');
    $s['status'] = in_array(
        ($s['status'] ?? 'draft'),
        ['draft', 'active', 'ended'],
        true
    ) ? $s['status'] : 'draft';

    $s['created_at'] = (string)($s['created_at'] ?? survey_now());
    $s['updated_at'] = (string)($s['updated_at'] ?? survey_now());
    $s['numbering_mode'] = (($s['numbering_mode'] ?? 'global') === 'group')
        ? 'group'
        : 'global';
    $s['deleted'] = !empty($s['deleted']);

    $groups = [];

    foreach (($s['groups'] ?? []) as $g) {
        if (!is_array($g)) {
            continue;
        }

        $group = [
            'id' => (string)($g['id'] ?? survey_id()),
            'name' => (string)($g['name'] ?? 'グループ'),
            'questions' => [],
        ];

        foreach (($g['questions'] ?? []) as $q) {
            if (is_array($q)) {
                $group['questions'][] = survey_normalize_question($q);
            }
        }

        $groups[] = $group;
    }

    $s['groups'] = $groups;

    return $s;
}

function survey_validate_branches(array $survey): array {
    $survey = survey_normalize_survey($survey);
    $questions = survey_question_index($survey);
    $errors = [];

    foreach ($questions as $qid => $q) {
        if ($q['type'] !== 'single') {
            continue;
        }

        foreach ($q['option_branches'] as $index => $target) {
            $target = (string)$target;

            if ($target === '') {
                continue;
            }

            if (!isset($questions[$target])) {
                $errors[] =
                    '質問「' . ($q['text'] ?: $qid) .
                    '」の選択肢「' . ((int)$index + 1) .
                    '」の分岐先が存在しません。';
            }

            if ($target === $qid) {
                $errors[] =
                    '質問「' . ($q['text'] ?: $qid) .
                    '」自身への分岐は設定できません。';
            }
        }
    }

    return $errors;
}

function survey_all_questions(array $survey): array {
    $result = [];

    foreach (($survey['groups'] ?? []) as $gi => $g) {
        foreach (($g['questions'] ?? []) as $qi => $q) {
            $q['group_id'] = $g['id'];
            $q['_group_index'] = $gi;
            $q['_question_index'] = $qi;
            $result[] = $q;
        }
    }

    return $result;
}

function survey_question_number(array $survey, string $questionId): string {
    $n = 0;

    foreach (($survey['groups'] ?? []) as $gi => $g) {
        foreach (($g['questions'] ?? []) as $qi => $q) {
            $n++;

            if (($q['id'] ?? '') !== $questionId) {
                continue;
            }

            if (($survey['numbering_mode'] ?? 'global') === 'group') {
                return 'Q' . ($gi + 1) . '-' . ($qi + 1);
            }

            return 'Q' . $n;
        }
    }

    return '';
}

/* =========================================================
 * kintone
 * ========================================================= */

function survey_normalize_kintone_base(string $input): array {
    $input = trim($input);
    $input = rtrim($input, "/ \t\r\n");

    if ($input === '') {
        return ['ok' => false, 'error' => 'kintoneホストが未入力です。'];
    }

    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }

    $host = '';

    $parsed = @parse_url($input);

    if (is_array($parsed)) {
        $host = (string)($parsed['host'] ?? '');

        if (isset($parsed['port'])) {
            $host .= ':' . (int)$parsed['port'];
        }
    }

    if ($host === '' &&
        preg_match('~^https?://([^/?#]+)~i', $input, $m)) {
        $host = $m[1];
    }

    $host = strtolower(trim($host));

    if ($host === '') {
        return ['ok' => false, 'error' => 'kintoneホストを取得できません。'];
    }

    $hostOnly = preg_replace('/:\d+$/', '', $host);

    if (!preg_match(
        '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
        (string)$hostOnly
    ) &&
        !preg_match(
            '~^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~i',
            (string)$hostOnly
        )) {
        return [
            'ok' => false,
            'error' => '許可されていないkintoneホスト名です。'
        ];
    }

    return [
        'ok' => true,
        'base' => 'https://' . $host,
        'host' => $hostOnly,
    ];
}

function survey_parse_proxy(string $input): array {
    $input = trim($input);

    if ($input === '') {
        return [
            'ok' => true,
            'used' => false,
            'value' => '',
        ];
    }

    if (!preg_match(
        '~^(?:(https?)://)?([^/:?#\s]+):([0-9]{1,5})$~i',
        $input,
        $m
    )) {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' =>
                'Proxy形式は host:port、http://host:port、https://host:port です。'
        ];
    }

    $port = (int)$m[3];

    if ($port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' => 'Proxyポート番号が不正です。'
        ];
    }

    return [
        'ok' => true,
        'used' => true,
        'value' => 'tcp://' . strtolower($m[2]) . ':' . $port,
    ];
}

function survey_last_headers(): array {
    if (function_exists('http_get_last_response_headers')) {
        try {
            $h = http_get_last_response_headers();
            return is_array($h) ? $h : [];
        } catch (Throwable) {
            return [];
        }
    }

    $h = $GLOBALS['http_response_header'] ?? null;

    return is_array($h) ? $h : [];
}

function survey_status_from_headers(array $headers): int {
    $status = 0;

    foreach ($headers as $header) {
        if (preg_match(
            '~^HTTP/\S+\s+([0-9]{3})~i',
            (string)$header,
            $m
        )) {
            $status = (int)$m[1];
        }
    }

    return $status;
}

function survey_http_request(
    string $url,
    string $method,
    array $headers,
    ?string $content,
    bool $sslVerify,
    string $proxy
): array {
    $proxyInfo = survey_parse_proxy($proxy);

    if (!$proxyInfo['ok']) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => $proxyInfo['error'],
            'url' => $url,
            'proxy_used' => true,
        ];
    }

    $parsed = @parse_url($url);
    $peerName = is_array($parsed)
        ? (string)($parsed['host'] ?? '')
        : '';

    $http = [
        'method' => strtoupper($method),
        'timeout' => 30,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'header' => implode("\r\n", $headers),
    ];

    if ($content !== null && strtoupper($method) !== 'GET') {
        $http['content'] = $content;
    }

    if ($proxyInfo['used']) {
        $http['proxy'] = $proxyInfo['value'];
        $http['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $http,
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify,
            'SNI_enabled' => true,
            'peer_name' => $peerName,
        ],
    ]);

    $warning = '';

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$warning): bool {
            $warning = $message;
            return true;
        }
    );

    try {
        $body = file_get_contents($url, false, $context);
    } catch (Throwable $e) {
        $body = false;
        $warning = $e->getMessage();
    }

    restore_error_handler();

    $headersResult = survey_last_headers();
    $status = survey_status_from_headers($headersResult);
    $bodyText = is_string($body) ? $body : '';
    $json = json_decode($bodyText, true);

    if ($status === 0) {
        return [
            'status' => 0,
            'body' => $bodyText,
            'json' => $json,
            'error' =>
                ($warning !== '' ? $warning : 'HTTPレスポンスを取得できませんでした。') .
                "\n確認事項: DNS、外部HTTPS通信、Proxy、ファイアウォール、SSL/TLS、OpenSSL、タイムアウト。",
            'url' => $url,
            'proxy_used' => $proxyInfo['used'],
        ];
    }

    return [
        'status' => $status,
        'body' => $bodyText,
        'json' => $json,
        'error' => $warning,
        'url' => $url,
        'proxy_used' => $proxyInfo['used'],
    ];
}

function survey_kintone_base_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $payload = null
): array {
    $normalized = survey_normalize_kintone_base(
        (string)($settings['subdomain'] ?? '')
    );

    if (!$normalized['ok']) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => $normalized['error'],
            'url' => '',
            'proxy_used' => false,
        ];
    }

    $appId = trim((string)($settings['app_id'] ?? ''));

    if ($appId === '' || !preg_match('/^[0-9]+$/', $appId)) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => 'アプリIDは数字で入力してください。',
            'url' => '',
            'proxy_used' => false,
        ];
    }

    $url =
        $normalized['base'] .
        '/k/v1/' .
        ltrim($path, '/');

    if (!str_contains($url, '?')) {
        $url .= '?app=' . rawurlencode($appId);
    }

    $auth = base64_encode(
        (string)($settings['login_name'] ?? '') .
        ':' .
        (string)($settings['password'] ?? '')
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
        'Connection: close',
    ];

    $content = null;

    if ($payload !== null) {
        $content = survey_json($payload);
        $headers[] = 'Content-Type: application/json';
    }

    return survey_http_request(
        $url,
        $method,
        $headers,
        $content,
        (bool)($settings['ssl_verify'] ?? true),
        (string)($settings['proxy'] ?? '')
    );
}

function survey_kintone_message(array $r): string {
    $status = (int)($r['status'] ?? 0);
    $url = (string)($r['url'] ?? '');
    $error = trim((string)($r['error'] ?? ''));
    $proxy = !empty($r['proxy_used']) ? '使用' : '未使用';

    if ($status === 0) {
        return
            "kintoneからHTTPレスポンスを取得できませんでした。\n" .
            "HTTPステータス: 0\n" .
            "接続先: {$url}\n" .
            "Proxy: {$proxy}\n" .
            "PHP通信エラー: " .
            ($error !== '' ? $error : 'なし') .
            "\n確認事項: サーバーからの外部HTTPS通信、DNS、Proxy、ファイアウォール、SSL設定";
    }

    return match (true) {
        $status === 401 || $status === 403 =>
            "kintone認証または権限エラーです。\nHTTPステータス: {$status}\n接続先: {$url}",
        $status === 404 =>
            "kintone APIまたはアプリが見つかりません。\nHTTPステータス: 404\n接続先: {$url}",
        $status === 408 =>
            "kintone通信がタイムアウトしました。",
        $status === 429 =>
            "kintone側のレート制限です。",
        $status >= 500 =>
            "kintoneまたはProxy側のサーバーエラーです。HTTPステータス: {$status}",
        $status >= 200 && $status < 300 =>
            "kintone通信に成功しました。HTTPステータス: {$status}",
        default =>
            "kintone通信でエラーが発生しました。\nHTTPステータス: {$status}\n接続先: {$url}" .
            ($error !== '' ? "\nPHP通信エラー: {$error}" : ''),
    };
}

function fetchKintoneFields(array $settings): array {
    $r = survey_kintone_base_request(
        $settings,
        'app/form/fields.json'
    );

    if ((int)$r['status'] < 200 || (int)$r['status'] >= 300) {
        return [
            'ok' => false,
            'fields' => [],
            'message' => survey_kintone_message($r),
        ];
    }

    $json = $r['json'];

    if (!is_array($json) ||
        !isset($json['properties']) ||
        !is_array($json['properties'])) {
        return [
            'ok' => false,
            'fields' => [],
            'message' => 'kintoneレスポンスにpropertiesがありません。',
        ];
    }

    $fields = [];

    foreach ($json['properties'] as $code => $property) {
        if (!is_array($property)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' => (string)($property['label'] ?? $code),
            'type' => (string)($property['type'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'fields' => $fields,
        'message' => '項目一覧を取得しました。',
    ];
}

function survey_kintone_value(array $record, string $code): string {
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $v = $record[$code]['value'] ?? '';

    if (is_array($v)) {
        $values = [];

        foreach ($v as $item) {
            $values[] = is_array($item)
                ? (string)($item['value'] ?? '')
                : (string)$item;
        }

        return implode(' ', $values);
    }

    return (string)$v;
}

function survey_sync_customers(array &$data): array {
    $settings = $data['settings'];

    $r = survey_kintone_base_request(
        $settings,
        'records.json'
    );

    if ((int)$r['status'] < 200 || (int)$r['status'] >= 300) {
        return [
            'ok' => false,
            'message' => survey_kintone_message($r),
            'count' => 0,
        ];
    }

    $records = $r['json']['records'] ?? null;

    if (!is_array($records)) {
        return [
            'ok' => false,
            'message' => 'kintone APIレスポンスにrecordsがありません。',
            'count' => 0,
        ];
    }

    $existing = [];

    foreach ($data['customers'] as $customer) {
        if (!empty($customer['email'])) {
            $existing[(string)$customer['email']] = $customer;
        }
    }

    $count = 0;

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $email = trim(survey_kintone_value(
            $record,
            (string)$settings['field_email']
        ));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $old = $existing[$email] ?? [];

        $customer = [
            'id' => $old['id'] ?? survey_id(),
            'company' => survey_kintone_value(
                $record,
                (string)$settings['field_company']
            ),
            'name' => survey_kintone_value(
                $record,
                (string)$settings['field_name']
            ),
            'email' => $email,
            'department' => survey_kintone_value(
                $record,
                (string)$settings['field_department']
            ),
            'phone' => survey_kintone_value(
                $record,
                (string)$settings['field_phone']
            ),
            'address' => '',
            'source' => 'kintone',
            'sent_at' => $old['sent_at'] ?? null,
            'send_count' => (int)($old['send_count'] ?? 0),
            'answer_status' => $old['answer_status'] ?? 'unanswered',
            'kintone_status' => 'registered',
        ];

        $addressCodes = $settings['field_address'] ?? [];

        if (!is_array($addressCodes)) {
            $addressCodes = [$addressCodes];
        }

        $parts = [];

        foreach ($addressCodes as $code) {
            $v = survey_kintone_value($record, (string)$code);

            if ($v !== '') {
                $parts[] = $v;
            }
        }

        $customer['address'] = implode(' ', $parts);
        $existing[$email] = $customer;
        $count++;
    }

    $data['customers'] = array_values($existing);

    return [
        'ok' => true,
        'message' => "kintone顧客同期完了: {$count}件",
        'count' => $count,
    ];
}

/* =========================================================
 * Mail
 * ========================================================= */

function survey_mail_send(
    string $to,
    string $subject,
    string $body
): array {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'メールアドレスが不正です。'];
    }

    $subject = str_replace(["\r", "\n"], '', $subject);

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . (string)($_SERVER['SERVER_ADMIN'] ?? 'webmaster@localhost'),
    ];

    $ok = @mail(
        $to,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        $body,
        implode("\r\n", $headers)
    );

    return [
        'ok' => $ok,
        'message' => $ok
            ? '送信しました。'
            : 'PHP mail() による送信に失敗しました。サーバーのメール送信設定を確認してください。',
    ];
}

/* =========================================================
 * API
 * ========================================================= */

$data = survey_read_data();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action !== 'public_submit' && !survey_check_token()) {
        survey_api([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 403);
    }

    if ($action === 'save_survey') {
        $survey = json_decode(
            (string)($_POST['survey_json'] ?? ''),
            true
        );

        if (!is_array($survey)) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートデータが不正です。',
            ], 400);
        }

        $survey = survey_normalize_survey($survey);
        $errors = survey_validate_branches($survey);

        if ($errors) {
            survey_api([
                'ok' => false,
                'message' => implode("\n", $errors),
            ], 422);
        }

        $found = false;

        foreach ($data['surveys'] as $i => $old) {
            if (($old['id'] ?? '') === $survey['id']) {
                $survey['created_at'] = $old['created_at'] ?? survey_now();
                $survey['updated_at'] = survey_now();
                $data['surveys'][$i] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $survey['created_at'] = survey_now();
            $survey['updated_at'] = survey_now();
            $data['surveys'][] = $survey;
        }

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'survey' => $survey,
        ]);
    }

    if ($action === 'delete_survey') {
        $id = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['deleted'] = true;
                $survey['updated_at'] = survey_now();
            }
        }
        unset($survey);

        survey_write_data($data);
        survey_api(['ok' => true]);
    }

    if ($action === 'status') {
        $id = (string)($_POST['survey_id'] ?? '');
        $status = (string)($_POST['status'] ?? '');

        if (!in_array($status, ['draft', 'active', 'ended'], true)) {
            survey_api([
                'ok' => false,
                'message' => '不正なステータスです。',
            ], 400);
        }

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['status'] = $status;
                $survey['updated_at'] = survey_now();
                break;
            }
        }
        unset($survey);

        survey_write_data($data);
        survey_api(['ok' => true]);
    }

    if ($action === 'duplicate') {
        $id = (string)($_POST['survey_id'] ?? '');
        $source = null;

        foreach ($data['surveys'] as $survey) {
            if (($survey['id'] ?? '') === $id) {
                $source = $survey;
                break;
            }
        }

        if (!$source) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートがありません。',
            ], 404);
        }

        $source['id'] = survey_id();
        $source['title'] .= '（複製）';
        $source['status'] = 'draft';
        $source['deleted'] = false;
        $source['created_at'] = survey_now();
        $source['updated_at'] = survey_now();

        $data['surveys'][] = $source;
        survey_write_data($data);

        survey_api([
            'ok' => true,
            'survey' => $source,
        ]);
    }

    if ($action === 'save_settings') {
        $settings = json_decode(
            (string)($_POST['settings_json'] ?? ''),
            true
        );

        if (!is_array($settings)) {
            survey_api([
                'ok' => false,
                'message' => '設定データが不正です。',
            ], 400);
        }

        $settings['password'] =
            trim((string)($settings['password'] ?? ''));

        $data['settings'] = array_replace(
            $data['settings'],
            [
                'subdomain' => trim((string)($settings['subdomain'] ?? '')),
                'login_name' => trim((string)($settings['login_name'] ?? '')),
                'password' => $settings['password'],
                'app_id' => trim((string)($settings['app_id'] ?? '')),
                'ssl_verify' => !empty($settings['ssl_verify']),
                'proxy' => trim((string)($settings['proxy'] ?? '')),
                'field_company' => (string)($settings['field_company'] ?? ''),
                'field_name' => (string)($settings['field_name'] ?? ''),
                'field_email' => (string)($settings['field_email'] ?? ''),
                'field_department' => (string)($settings['field_department'] ?? ''),
                'field_phone' => (string)($settings['field_phone'] ?? ''),
                'field_address' => is_array($settings['field_address'] ?? null)
                    ? $settings['field_address']
                    : [],
            ]
        );

        survey_write_data($data);
        survey_api(['ok' => true]);
    }

    if ($action === 'kintone_fields') {
        $settings = $data['settings'];

        $result = fetchKintoneFields($settings);

        survey_api($result);
    }

    if ($action === 'kintone_test') {
        $settings = $data['settings'];

        $input = json_decode(
            (string)($_POST['settings_json'] ?? ''),
            true
        );

        if (is_array($input)) {
            $settings = array_replace($settings, $input);
        }

        $result = fetchKintoneFields($settings);

        survey_api([
            'ok' => $result['ok'],
            'fields' => $result['fields'],
            'message' => $result['message'],
        ]);
    }

    if ($action === 'kintone_sync') {
        $result = survey_sync_customers($data);

        if ($result['ok']) {
            survey_write_data($data);
        }

        survey_api($result);
    }

    if ($action === 'send_mail') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $recipientIds = json_decode(
            (string)($_POST['recipient_ids'] ?? '[]'),
            true
        );

        $subject = (string)($_POST['mail_subject'] ?? '');
        $body = (string)($_POST['mail_body'] ?? '');
        $templateType = (string)($_POST['template_type'] ?? 'initial');

        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if (!$survey || $survey['status'] !== 'active') {
            survey_api([
                'ok' => false,
                'message' => '公開中のアンケートのみ送信できます。',
            ], 422);
        }

        $sent = 0;
        $failed = 0;
        $alreadySent = 0;

        foreach ($data['customers'] as &$customer) {
            if (!in_array($customer['id'] ?? '', $recipientIds, true)) {
                continue;
            }

            if (($customer['source'] ?? '') === 'web') {
                continue;
            }

            if (!empty($customer['sent_at'])) {
                $alreadySent++;
            }

            $url = rtrim(
                (string)($_SERVER['REQUEST_SCHEME'] ?? 'http') .
                '://' .
                (string)($_SERVER['HTTP_HOST'] ?? ''),
                '/'
            ) . $_SERVER['SCRIPT_NAME'] .
                '?survey=' . rawurlencode($surveyId) .
                '&customer=' . rawurlencode((string)$customer['id']);

            $actualSubject = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [(string)($customer['name'] ?? ''), $url],
                $subject
            );

            $actualBody = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [(string)($customer['name'] ?? ''), $url],
                $body
            );

            $result = survey_mail_send(
                (string)$customer['email'],
                $actualSubject,
                $actualBody
            );

            if ($result['ok']) {
                $customer['sent_at'] = survey_now();
                $customer['send_count'] =
                    (int)($customer['send_count'] ?? 0) + 1;

                $sent++;

                $data['mail_logs'][] = [
                    'id' => survey_id(),
                    'survey_id' => $surveyId,
                    'customer_id' => $customer['id'],
                    'sent_at' => survey_now(),
                    'template_type' => $templateType,
                    'subject' => $actualSubject,
                    'body' => $actualBody,
                    'to' => $customer['email'],
                ];
            } else {
                $failed++;
            }
        }
        unset($customer);

        survey_write_data($data);

        survey_api([
            'ok' => $failed === 0,
            'sent' => $sent,
            'failed' => $failed,
            'already_sent' => $alreadySent,
        ]);
    }

    if ($action === 'mark_kintone') {
        $id = (string)($_POST['customer_id'] ?? '');

        foreach ($data['customers'] as &$customer) {
            if (($customer['id'] ?? '') === $id) {
                $customer['kintone_status'] = 'registered';
                break;
            }
        }
        unset($customer);

        survey_write_data($data);
        survey_api(['ok' => true]);
    }

    if ($action === 'public_submit') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $answers = json_decode(
            (string)($_POST['answers'] ?? '{}'),
            true
        );

        if (!is_array($answers)) {
            survey_api([
                'ok' => false,
                'message' => '回答データが不正です。',
            ], 422);
        }

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = survey_normalize_survey($s);
                break;
            }
        }

        if (!$survey || $survey['status'] !== 'active') {
            survey_api([
                'ok' => false,
                'message' => 'このアンケートは現在回答できません。',
            ], 422);
        }

        $questions = survey_question_index($survey);

        /* サーバー側でも分岐到達可能性を検証 */
        $visible = [];

        foreach ($questions as $qid => $q) {
            $visible[$qid] = true;
        }

        foreach ($questions as $qid => $q) {
            if ($q['type'] !== 'single') {
                continue;
            }

            $value = $answers[$qid] ?? null;

            if (!is_string($value)) {
                continue;
            }

            $index = array_search($value, $q['options'], true);

            if ($index === false) {
                continue;
            }

            $target = (string)($q['option_branches'][(string)$index] ?? '');

            if ($target !== '') {
                foreach ($questions as $otherId => $_q) {
                    if ($otherId !== $target &&
                        $otherId !== $qid) {
                        /* 到達判定はJS側と同一モデルを保存するため、
                           少なくとも不正IDは拒否する */
                    }
                }

                if (!isset($questions[$target])) {
                    survey_api([
                        'ok' => false,
                        'message' => '分岐先質問が存在しません。',
                    ], 422);
                }
            }
        }

        $email = trim((string)($_POST['email'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $company = trim((string)($_POST['company'] ?? ''));

        $customerId = '';

        if ($email !== '') {
            foreach ($data['customers'] as &$customer) {
                if (strcasecmp(
                    (string)$customer['email'],
                    $email
                ) === 0) {
                    $customerId = (string)$customer['id'];
                    $customer['answer_status'] = 'answered';
                    break;
                }
            }
            unset($customer);
        }

        if ($customerId === '') {
            $customerId = survey_id();

            $data['customers'][] = [
                'id' => $customerId,
                'company' => $company,
                'name' => $name,
                'email' => $email,
                'department' => '',
                'phone' => '',
                'address' => '',
                'source' => 'web',
                'sent_at' => null,
                'send_count' => 0,
                'answer_status' => 'answered',
                'kintone_status' => 'unregistered',
            ];
        }

        $data['responses'][] = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'customer_id' => $customerId,
            'company' => $company,
            'name' => $name,
            'email' => $email,
            'answered_at' => survey_now(),
            'answers' => $answers,
        ];

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' => '回答を送信しました。ありがとうございました。',
        ]);
    }

    if ($action === 'csv') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if (!$survey) {
            http_response_code(404);
            exit;
        }

        $questions = survey_all_questions($survey);

        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="survey_' .
            preg_replace('/[^a-zA-Z0-9_-]/', '_', $surveyId) .
            '.csv"'
        );

        echo "\xEF\xBB\xBF";

        $fp = fopen('php://output', 'w');

        $headerRow = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
            'メールアドレス',
        ];

        foreach ($questions as $q) {
            $headerRow[] = survey_question_number(
                $survey,
                (string)$q['id']
            ) . ' ' . $q['text'];
        }

        fputcsv($fp, $headerRow);

        foreach ($data['responses'] as $response) {
            if (($response['survey_id'] ?? '') !== $surveyId) {
                continue;
            }

            $row = [
                $response['id'] ?? '',
                $response['answered_at'] ?? '',
                $response['customer_id'] ?? '',
                $response['company'] ?? '',
                $response['name'] ?? '',
                $response['email'] ?? '',
            ];

            foreach ($questions as $q) {
                $v = $response['answers'][$q['id']] ?? '';

                if (is_array($v)) {
                    $v = implode(', ', $v);
                }

                $row[] = $v;
            }

            fputcsv($fp, $row);
        }

        fclose($fp);
        exit;
    }

    survey_api([
        'ok' => false,
        'message' => '不明なactionです。',
    ], 400);
}

/* =========================================================
 * Public answer page
 * ========================================================= */

if (isset($_GET['survey'])) {
    $surveyId = (string)$_GET['survey'];
    $survey = null;

    foreach ($data['surveys'] as $s) {
        if (($s['id'] ?? '') === $surveyId) {
            $survey = survey_normalize_survey($s);
            break;
        }
    }

    if (!$survey || $survey['status'] !== 'active') {
        http_response_code(404);
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <script src="https://cdn.tailwindcss.com"></script>
        <title>アンケート</title>
        </head>
        <body class="bg-slate-50 min-h-screen flex items-center justify-center p-6">
        <div class="bg-white rounded-2xl shadow-sm p-8 max-w-lg w-full text-center">
        <h1 class="text-xl font-bold text-slate-800">回答できません</h1>
        <p class="mt-3 text-slate-500">このアンケートは公開されていないか終了しています。</p>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <title><?= survey_h($survey['title']) ?></title>
    </head>
    <body class="bg-slate-100 text-slate-800 min-h-screen">
    <div class="max-w-3xl mx-auto px-4 py-8">
    <div class="bg-white rounded-2xl shadow-sm p-6 md:p-8">
    <h1 class="text-2xl font-bold"><?= survey_h($survey['title']) ?></h1>

    <div class="mt-6 grid md:grid-cols-3 gap-3">
    <input id="public_company" class="border rounded-xl px-4 py-3" placeholder="会社名">
    <input id="public_name" class="border rounded-xl px-4 py-3" placeholder="氏名">
    <input id="public_email" type="email" class="border rounded-xl px-4 py-3" placeholder="メールアドレス">
    </div>

    <div id="public_questions" class="mt-8 space-y-6"></div>

    <button id="public_submit"
      class="mt-8 w-full rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3">
      回答を送信
    </button>
    </div>
    </div>

    <script>
    window.PUBLIC_SURVEY = <?= survey_json($survey) ?>;
    window.PUBLIC_TOKEN = <?= survey_json(survey_token()) ?>;

    window.PublicApp = {
        survey: window.PUBLIC_SURVEY,
        answers: {},

        escape(v) {
            const d = document.createElement('div');
            d.textContent = String(v ?? '');
            return d.innerHTML;
        },

        questions() {
            const a = [];
            for (const g of this.survey.groups || []) {
                for (const q of g.questions || []) {
                    a.push(q);
                }
            }
            return a;
        },

        reachable() {
            const qs = this.questions();
            const map = Object.fromEntries(qs.map(q => [q.id, q]));
            const visible = new Set();

            if (!qs.length) return visible;

            let current = qs[0].id;
            const seen = new Set();

            while (current && map[current] && !seen.has(current)) {
                seen.add(current);
                visible.add(current);

                const q = map[current];

                if (q.type !== 'single') {
                    const idx = qs.findIndex(x => x.id === current);
                    current = qs[idx + 1]?.id || '';
                    continue;
                }

                const value = this.answers[current];
                const idx = (q.options || []).indexOf(value);
                const target = idx >= 0
                    ? (q.option_branches?.[String(idx)] || '')
                    : '';

                if (target) {
                    current = target;
                } else {
                    const idx2 = qs.findIndex(x => x.id === current);
                    current = qs[idx2 + 1]?.id || '';
                }
            }

            return visible;
        },

        render() {
            const box = document.getElementById('public_questions');
            const visible = this.reachable();
            const qs = this.questions();

            box.innerHTML = qs.map((q, i) => {
                if (!visible.has(q.id)) return '';

                const title = this.escape(q.text || `質問${i + 1}`);
                const required = q.required
                    ? '<span class="text-red-500 text-xs ml-2">必須</span>'
                    : '';

                if (q.type === 'text') {
                    return `
                    <div class="border rounded-2xl p-5" data-q="${this.escape(q.id)}">
                      <div class="font-semibold mb-3">${title}${required}</div>
                      <textarea
                        class="answer-input w-full border rounded-xl px-4 py-3 min-h-28"
                        data-q="${this.escape(q.id)}"></textarea>
                    </div>`;
                }

                if (q.type === 'multiple') {
                    return `
                    <div class="border rounded-2xl p-5">
                      <div class="font-semibold mb-3">${title}${required}</div>
                      <div class="space-y-2">
                      ${(q.options || []).map((o, oi) => `
                        <label class="flex gap-3 items-center p-3 rounded-xl hover:bg-slate-50">
                          <input type="checkbox"
                            class="answer-multi"
                            data-q="${this.escape(q.id)}"
                            value="${this.escape(o)}">
                          <span>${this.escape(o)}</span>
                        </label>
                      `).join('')}
                      </div>
                    </div>`;
                }

                return `
                <div class="border rounded-2xl p-5">
                  <div class="font-semibold mb-3">${title}${required}</div>
                  <div class="space-y-2">
                  ${(q.options || []).map((o, oi) => `
                    <label class="flex gap-3 items-center p-3 rounded-xl hover:bg-slate-50">
                      <input type="radio"
                        class="answer-single"
                        name="q_${this.escape(q.id)}"
                        data-q="${this.escape(q.id)}"
                        value="${this.escape(o)}"
                        ${this.answers[q.id] === o ? 'checked' : ''}>
                      <span>${this.escape(o)}</span>
                    </label>
                  `).join('')}
                  </div>
                </div>`;
            }).join('');

            box.querySelectorAll('.answer-single').forEach(el => {
                el.addEventListener('change', () => {
                    this.answers[el.dataset.q] = el.value;
                    this.render();
                });
            });

            box.querySelectorAll('.answer-multi').forEach(el => {
                el.addEventListener('change', () => {
                    const id = el.dataset.q;
                    const values = [...box.querySelectorAll(
                        `.answer-multi[data-q="${CSS.escape(id)}"]:checked`
                    )].map(x => x.value);
                    this.answers[id] = values;
                });
            });

            box.querySelectorAll('.answer-input').forEach(el => {
                el.addEventListener('input', () => {
                    this.answers[el.dataset.q] = el.value;
                });
            });
        },

        submit() {
            const qs = this.questions();
            const visible = this.reachable();

            for (const q of qs) {
                if (!visible.has(q.id) || !q.required) continue;

                const v = this.answers[q.id];

                if (
                    v === undefined ||
                    v === '' ||
                    (Array.isArray(v) && v.length === 0)
                ) {
                    alert(`${q.text} は必須です。`);
                    return;
                }
            }

            const fd = new FormData();
            fd.append('action', 'public_submit');
            fd.append('survey_id', this.survey.id);
            fd.append('csrf_token', window.PUBLIC_TOKEN);
            fd.append('company',
                document.getElementById('public_company').value);
            fd.append('name',
                document.getElementById('public_name').value);
            fd.append('email',
                document.getElementById('public_email').value);
            fd.append('answers', JSON.stringify(this.answers));

            fetch(location.href, {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(r => {
                if (!r.ok) {
                    alert(r.message || '送信に失敗しました。');
                    return;
                }

                document.body.innerHTML = `
                <div class="min-h-screen flex items-center justify-center p-6 bg-slate-100">
                  <div class="bg-white rounded-2xl shadow-sm p-10 text-center max-w-lg w-full">
                    <div class="text-5xl">✓</div>
                    <h1 class="mt-5 text-2xl font-bold">回答ありがとうございました</h1>
                    <p class="mt-3 text-slate-500">${this.escape(r.message)}</p>
                  </div>
                </div>`;
            })
            .catch(() => alert('通信に失敗しました。'));
        }
    };

    PublicApp.render();
    document.getElementById('public_submit')
      .addEventListener('click', () => PublicApp.submit());
    </script>
    </body>
    </html>
    <?php
    exit;
}

/* =========================================================
 * Admin SPA
 * ========================================================= */

$publicData = $data;
$publicData['settings']['password'] = '';

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<title>アンケート管理システム</title>
</head>

<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App = {
    state: {
        data: <?= survey_json($publicData) ?>,
        token: <?= survey_json(survey_token()) ?>,
        screen: 'list',
        survey: null,
        editing: null,
        selectedSurvey: null,
        keyword: '',
        status_filter: 'all',
        sort: 'updated_desc',
        selectedCustomers: [],
        mail: {
            subject: '【アンケートのお願い】{顧客名} 様',
            body: '{顧客名} 様\n\n以下のURLよりアンケートへご回答ください。\n{アンケートURL}',
            template_type: 'initial'
        },
        selectedQuestions: {},
        previewMobile: false
    },

    refs: {},

    escape(v) {
        const d = document.createElement('div');
        d.textContent = String(v ?? '');
        return d.innerHTML;
    },

    api(action, payload = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', this.state.token);

        for (const [k, v] of Object.entries(payload)) {
            fd.append(
                k,
                typeof v === 'object' ? JSON.stringify(v) : String(v ?? '')
            );
        }

        return fetch(location.href, {
            method: 'POST',
            body: fd
        }).then(async r => {
            const text = await r.text();
            let json;

            try {
                json = JSON.parse(text);
            } catch {
                throw new Error(text || 'サーバー応答が不正です。');
            }

            if (!json.ok) {
                throw new Error(json.message || '処理に失敗しました。');
            }

            return json;
        });
    },

    init() {
        this.render();
    },

    render() {
        const app = document.getElementById('app');

        if (this.state.screen === 'list') {
            app.innerHTML = this.views.layout(
                this.views.list()
            );
        } else if (this.state.screen === 'edit') {
            app.innerHTML = this.views.layout(
                this.views.editor()
            );
            this.bindEditor();
        } else if (this.state.screen === 'send') {
            app.innerHTML = this.views.layout(
                this.views.send()
            );
        } else if (this.state.screen === 'aggregate') {
            app.innerHTML = this.views.layout(
                this.views.aggregate()
            );
        } else if (this.state.screen === 'settings') {
            app.innerHTML = this.views.layout(
                this.views.settings()
            );
        }
    },

    views: {
        layout(content) {
            return `
            <header class="bg-white border-b sticky top-0 z-40">
              <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
                <button
                  class="text-lg font-bold"
                  onclick="App.actions.go('list')">
                  アンケート管理
                </button>
                <nav class="flex gap-2">
                  <button
                    class="px-4 py-2 rounded-lg hover:bg-slate-100"
                    onclick="App.actions.go('list')">
                    アンケート一覧
                  </button>
                  <button
                    class="px-4 py-2 rounded-lg hover:bg-slate-100"
                    onclick="App.actions.go('settings')">
                    kintone連携設定
                  </button>
                </nav>
              </div>
            </header>
            ${content}`;
        },

        list() {
            const s = App.state;

            let surveys = (s.data.surveys || [])
                .filter(x => !x.deleted)
                .filter(x =>
                    !s.keyword ||
                    String(x.title).toLowerCase()
                    .includes(s.keyword.toLowerCase())
                )
                .filter(x =>
                    s.status_filter === 'all' ||
                    x.status === s.status_filter
                );

            surveys.sort((a, b) => {
                if (s.sort === 'updated_asc') {
                    return String(a.updated_at).localeCompare(String(b.updated_at));
                }

                if (s.sort === 'title') {
                    return String(a.title).localeCompare(String(b.title));
                }

                return String(b.updated_at).localeCompare(String(a.updated_at));
            });

            return `
            <main class="max-w-7xl mx-auto p-4 md:p-6">
              <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
                <div>
                  <h1 class="text-2xl font-bold">アンケート一覧</h1>
                  <p class="text-sm text-slate-500 mt-1">作成・公開・送信・集計を管理します。</p>
                </div>

                <button
                  onclick="App.actions.newSurvey()"
                  class="bg-indigo-600 text-white px-5 py-3 rounded-xl font-semibold">
                  ＋ 新規アンケート作成
                </button>
              </div>

              <div class="bg-white rounded-2xl shadow-sm p-4 mb-4 flex flex-wrap gap-3">
                <input
                  class="border rounded-xl px-4 py-3 flex-1 min-w-56"
                  placeholder="タイトルを検索"
                  value="${App.escape(s.keyword)}"
                  oninput="App.actions.search(this.value)">

                <select
                  class="border rounded-xl px-4 py-3"
                  onchange="App.actions.toggleStatusFilter(this.value)">
                  <option value="all" ${s.status_filter === 'all' ? 'selected' : ''}>すべて</option>
                  <option value="active" ${s.status_filter === 'active' ? 'selected' : ''}>公開中</option>
                  <option value="draft" ${s.status_filter === 'draft' ? 'selected' : ''}>下書き</option>
                  <option value="ended" ${s.status_filter === 'ended' ? 'selected' : ''}>終了</option>
                </select>

                <select
                  class="border rounded-xl px-4 py-3"
                  onchange="App.actions.sort(this.value)">
                  <option value="updated_desc" ${s.sort === 'updated_desc' ? 'selected' : ''}>更新日：新しい順</option>
                  <option value="updated_asc" ${s.sort === 'updated_asc' ? 'selected' : ''}>更新日：古い順</option>
                  <option value="title" ${s.sort === 'title' ? 'selected' : ''}>タイトル順</option>
                </select>
              </div>

              <div class="bg-white rounded-2xl shadow-sm overflow-x-auto">
              <table class="w-full min-w-[1100px]">
                <thead class="bg-slate-50 text-sm">
                  <tr>
                    <th class="text-left p-4">作成日 / 更新日</th>
                    <th class="text-left p-4">タイトル</th>
                    <th class="text-left p-4">期間</th>
                    <th class="text-left p-4">ステータス</th>
                    <th class="text-left p-4">回答数</th>
                    <th class="text-left p-4">操作</th>
                  </tr>
                </thead>
                <tbody>
                ${surveys.map(x => App.views.surveyRow(x)).join('')}
                </tbody>
              </table>
              ${surveys.length ? '' : `
                <div class="p-12 text-center text-slate-400">
                  アンケートがありません。
                </div>`}
              </div>
            </main>`;
        },

        surveyRow(x) {
            const answers = App.state.data.responses
                .filter(r => r.survey_id === x.id).length;

            const badge = {
                active: 'bg-emerald-100 text-emerald-700',
                draft: 'bg-slate-100 text-slate-600',
                ended: 'bg-amber-100 text-amber-700'
            }[x.status];

            let actions = `
              <button class="px-3 py-2 rounded-lg bg-slate-100"
                onclick="App.actions.editSurvey('${x.id}',false)">
                確認・編集
              </button>
              <button class="px-3 py-2 rounded-lg bg-slate-100"
                onclick="App.actions.duplicate('${x.id}')">
                複製
              </button>`;

            if (x.status === 'active') {
                actions += `
                <button class="px-3 py-2 rounded-lg bg-blue-50 text-blue-700"
                  onclick="App.actions.aggregate('${x.id}')">
                  集計
                </button>
                <button class="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700"
                  onclick="App.actions.send('${x.id}')">
                  送信
                </button>
                <button class="px-3 py-2 rounded-lg bg-red-50 text-red-700"
                  onclick="App.actions.status('${x.id}','ended')">
                  停止
                </button>`;
            }

            if (x.status === 'draft') {
                actions += `
                <button class="px-3 py-2 rounded-lg bg-emerald-50 text-emerald-700"
                  onclick="App.actions.status('${x.id}','active')">
                  公開
                </button>
                <button class="px-3 py-2 rounded-lg bg-red-50 text-red-700"
                  onclick="App.actions.deleteSurvey('${x.id}')">
                  削除
                </button>`;
            }

            if (x.status === 'ended') {
                actions += `
                <button class="px-3 py-2 rounded-lg bg-blue-50 text-blue-700"
                  onclick="App.actions.aggregate('${x.id}')">
                  集計
                </button>
                <button class="px-3 py-2 rounded-lg bg-emerald-50 text-emerald-700"
                  onclick="App.actions.status('${x.id}','active')">
                  再開
                </button>`;
            }

            return `
            <tr class="border-t">
              <td class="p-4 text-sm">
                ${App.escape(x.created_at)}<br>
                <span class="text-slate-400">更新: ${App.escape(x.updated_at)}</span>
              </td>
              <td class="p-4 font-bold">${App.escape(x.title)}</td>
              <td class="p-4 text-sm">
                ${App.escape(x.start_at || '未設定')}<br>
                ～ ${App.escape(x.end_at || '未設定')}
              </td>
              <td class="p-4">
                <span class="px-3 py-1 rounded-full text-xs font-semibold ${badge}">
                  ${x.status === 'active' ? '公開中' : x.status === 'draft' ? '下書き' : '終了'}
                </span>
              </td>
              <td class="p-4">${answers} 件</td>
              <td class="p-4">
                <div class="flex flex-wrap gap-2">${actions}</div>
              </td>
            </tr>`;
        },

        editor() {
            const s = App.state.editing;

            return `
            <main class="max-w-6xl mx-auto p-4 md:p-6">
              <div class="flex flex-wrap justify-between gap-3 mb-6">
                <div class="flex gap-2">
                  <button class="px-4 py-2 rounded-lg bg-white"
                    onclick="App.actions.go('list')">← 一覧</button>
                  <button class="px-4 py-2 rounded-lg bg-slate-900 text-white"
                    onclick="App.actions.preview()">プレビュー</button>
                </div>
                <div class="flex gap-2">
                  <button class="px-4 py-2 rounded-lg bg-white"
                    onclick="App.actions.cancelEdit()">キャンセル</button>
                  <button class="px-4 py-2 rounded-lg bg-indigo-600 text-white"
                    onclick="App.actions.saveSurvey()">保存して一覧へ戻る</button>
                </div>
              </div>

              <div class="bg-white rounded-2xl shadow-sm p-5 mb-5">
                <div class="grid md:grid-cols-4 gap-3">
                  <input id="survey_title"
                    class="border rounded-xl px-4 py-3 md:col-span-2 text-lg font-semibold"
                    value="${App.escape(s.title)}"
                    placeholder="アンケートタイトル">

                  <input id="survey_start_at"
                    type="datetime-local"
                    class="border rounded-xl px-4 py-3"
                    value="${App.escape(s.start_at)}">

                  <input id="survey_end_at"
                    type="datetime-local"
                    class="border rounded-xl px-4 py-3"
                    value="${App.escape(s.end_at)}">
                </div>

                <div class="mt-4">
                  <label class="text-sm text-slate-500">質問番号</label>
                  <select id="survey_numbering_mode"
                    class="border rounded-xl px-4 py-3 ml-2"
                    onchange="App.actions.numbering(this.value)">
                    <option value="global" ${s.numbering_mode === 'global' ? 'selected' : ''}>Q1, Q2, Q3...</option>
                    <option value="group" ${s.numbering_mode === 'group' ? 'selected' : ''}>Q1-1, Q1-2...</option>
                  </select>
                </div>
              </div>

              <div id="question_editor" class="space-y-5"></div>

              <button
                onclick="App.actions.addGroup()"
                class="mt-5 w-full border-2 border-dashed border-slate-300 rounded-2xl py-5 text-slate-500 hover:bg-white">
                ＋ グループを追加
              </button>
            </main>

            <div id="preview_modal"></div>`;
        },

        group(g, gi) {
            return `
            <section class="group-card bg-white rounded-2xl shadow-sm p-5"
              data-group-id="${App.escape(g.id)}">
              <div class="flex items-center gap-3 mb-5">
                <span class="group-handle cursor-grab text-2xl">⠿</span>
                <input
                  class="group-name border rounded-xl px-4 py-2 flex-1 font-bold"
                  value="${App.escape(g.name)}"
                  onchange="App.actions.groupName('${g.id}',this.value)">
                <button
                  class="px-3 py-2 rounded-lg bg-red-50 text-red-700"
                  onclick="App.actions.removeGroup('${g.id}')">
                  グループ削除
                </button>
              </div>

              <div class="question-list space-y-4" data-group="${App.escape(g.id)}">
                ${(g.questions || []).map((q, qi) =>
                    App.views.question(q, gi, qi)
                ).join('')}
              </div>

              <button
                onclick="App.actions.addQuestion('${g.id}')"
                class="mt-4 px-4 py-3 rounded-xl bg-indigo-50 text-indigo-700 font-semibold">
                ＋ 質問を追加
              </button>
            </section>`;
        },

        question(q, gi, qi) {
            const number = App.questionNumber(q.id);
            const branchOptions = App.allQuestions()
                .filter(x => x.id !== q.id)
                .map(x => `
                  <option value="${App.escape(x.id)}">
                    ${App.escape(App.questionNumber(x.id))} ${App.escape(x.text || '無題')}
                  </option>`)
                .join('');

            return `
            <article class="question-card border rounded-2xl p-5"
              data-question-id="${App.escape(q.id)}">

              <div class="flex items-start gap-3">
                <span class="question-handle cursor-grab text-xl">⠿</span>
                <div class="flex-1">

                  <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <div class="font-bold text-indigo-600">${number}</div>
                    <button
                      class="text-red-600 text-sm"
                      onclick="App.actions.removeQuestion('${q.id}')">
                      質問削除
                    </button>
                  </div>

                  <input
                    class="question-text border rounded-xl px-4 py-3 w-full mb-3"
                    value="${App.escape(q.text)}"
                    placeholder="質問文"
                    onchange="App.actions.questionText('${q.id}',this.value)">

                  <div class="flex flex-wrap gap-4 mb-4">
                    <label>
                      回答形式
                      <select
                        class="question-type border rounded-lg px-3 py-2 ml-2"
                        onchange="App.actions.questionType('${q.id}',this.value)">
                        <option value="single" ${q.type === 'single' ? 'selected' : ''}>単一選択</option>
                        <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選択</option>
                        <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述</option>
                      </select>
                    </label>

                    <label class="flex items-center gap-2">
                      <input
                        type="checkbox"
                        ${q.required ? 'checked' : ''}
                        onchange="App.actions.required('${q.id}',this.checked)">
                      必須回答
                    </label>

                    ${q.type !== 'text' ? `
                    <label class="flex items-center gap-2">
                      <input
                        type="checkbox"
                        ${q.other_enabled ? 'checked' : ''}
                        onchange="App.actions.other('${q.id}',this.checked)">
                      その他
                    </label>` : ''}
                  </div>

                  ${q.type !== 'text' ? `
                  <div class="space-y-3">
                    ${(q.options || []).map((o, oi) => `
                      <div class="border rounded-xl p-3 bg-slate-50">
                        <div class="flex gap-2">
                          <input
                            class="option-input border rounded-lg px-3 py-2 flex-1 bg-white"
                            value="${App.escape(o)}"
                            onchange="App.actions.optionText('${q.id}',${oi},this.value)"
                            placeholder="選択肢">

                          <button
                            class="px-3 rounded-lg bg-red-50 text-red-700"
                            onclick="App.actions.removeOption('${q.id}',${oi})">
                            削除
                          </button>
                        </div>

                        ${q.type === 'single' ? `
                        <div class="mt-2 flex items-center gap-2 text-sm">
                          <span class="text-slate-500 whitespace-nowrap">
                            この選択肢を選んだら →
                          </span>
                          <select
                            class="border rounded-lg px-3 py-2 bg-white flex-1"
                            onchange="App.actions.branch('${q.id}',${oi},this.value)">
                            <option value="">次の質問へ（通常順）</option>
                            ${App.allQuestions()
                              .filter(x => x.id !== q.id)
                              .map(x => `
                              <option value="${App.escape(x.id)}"
                                ${(q.option_branches?.[String(oi)] || '') === x.id ? 'selected' : ''}>
                                ${App.escape(App.questionNumber(x.id))} ${App.escape(x.text || '無題')}
                              </option>`).join('')}
                          </select>
                        </div>` : ''}
                      </div>
                    `).join('')}

                    <button
                      onclick="App.actions.addOption('${q.id}')"
                      class="px-3 py-2 rounded-lg bg-slate-100">
                      ＋ 選択肢追加
                    </button>
                  </div>` : `
                  <div class="bg-slate-50 rounded-xl p-4 text-slate-500">
                    回答者が複数行のテキストを入力します。
                  </div>`}

                </div>
              </div>
            </article>`;
        },

        send() {
            const survey = App.state.survey;
            const customers = App.state.data.customers || [];

            return `
            <main class="max-w-7xl mx-auto p-4 md:p-6">
              <div class="flex items-center gap-3 mb-6">
                <button class="px-4 py-2 bg-white rounded-lg"
                  onclick="App.actions.go('list')">← 一覧</button>
                <div>
                  <h1 class="text-2xl font-bold">顧客選択・メール送信</h1>
                  <p class="text-slate-500">${App.escape(survey.title)}</p>
                </div>
              </div>

              <div class="bg-white rounded-2xl shadow-sm p-5 mb-5">
                <div class="grid md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-semibold mb-2">件名</label>
                    <input id="mail_subject"
                      class="w-full border rounded-xl px-4 py-3"
                      value="${App.escape(App.state.mail.subject)}">
                  </div>

                  <div>
                    <label class="block text-sm font-semibold mb-2">送信種別</label>
                    <select id="template_type"
                      class="w-full border rounded-xl px-4 py-3">
                      <option value="initial">初回</option>
                      <option value="reminder">リマインド</option>
                    </select>
                  </div>
                </div>

                <textarea id="mail_body"
                  class="w-full border rounded-xl px-4 py-3 mt-4 min-h-48">${App.escape(App.state.mail.body)}</textarea>

                <p class="text-sm text-slate-500 mt-2">
                  使用可能な変数：{顧客名} / {アンケートURL}
                </p>

                <div class="mt-4 flex gap-3">
                  <button
                    onclick="App.actions.selectAllCustomers()"
                    class="px-4 py-2 rounded-lg bg-slate-100">
                    全選択
                  </button>

                  <button
                    onclick="App.actions.sendMail()"
                    class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold">
                    選択した顧客へ一括送信
                  </button>
                </div>
              </div>

              <div class="bg-white rounded-2xl shadow-sm overflow-x-auto">
              <table class="w-full min-w-[1000px]">
                <thead class="bg-slate-50">
                <tr>
                  <th class="p-3">
                    <input id="select_all" type="checkbox"
                      onchange="App.actions.selectAllCustomers(this.checked)">
                  </th>
                  <th class="text-left p-3">会社名 / 氏名</th>
                  <th class="text-left p-3">メール</th>
                  <th class="text-left p-3">送信日時</th>
                  <th class="text-left p-3">送信回数</th>
                  <th class="text-left p-3">回答</th>
                  <th class="text-left p-3">kintone</th>
                </tr>
                </thead>
                <tbody id="customer_table">
                ${customers.map(c => `
                  <tr class="border-t">
                    <td class="p-3">
                      <input type="checkbox"
                        ${c.source === 'web' ? 'disabled' : ''}
                        ${App.state.selectedCustomers.includes(c.id) ? 'checked' : ''}
                        onchange="App.actions.customerSelect('${c.id}',this.checked)">
                    </td>
                    <td class="p-3">
                      <b>${App.escape(c.company)}</b><br>
                      ${App.escape(c.name)}
                    </td>
                    <td class="p-3">${App.escape(c.email)}</td>
                    <td class="p-3">${App.escape(c.sent_at || '未送信')}</td>
                    <td class="p-3">${Number(c.send_count || 0)}回</td>
                    <td class="p-3">
                      <span class="px-2 py-1 rounded-full text-xs
                        ${c.answer_status === 'answered'
                          ? 'bg-emerald-100 text-emerald-700'
                          : 'bg-slate-100 text-slate-600'}">
                        ${c.answer_status === 'answered' ? '回答済み' : '未回答'}
                      </span>
                    </td>
                    <td class="p-3">
                      ${c.kintone_status === 'registered'
                        ? '<span class="text-emerald-600">✓ 登録完了</span>'
                        : `<button class="text-blue-600"
                            onclick="App.actions.markKintone('${c.id}')">
                            kintone登録完了
                           </button>`}
                    </td>
                  </tr>
                `).join('')}
                </tbody>
              </table>
              </div>
            </main>`;
        },

        aggregate() {
            const survey = App.state.survey;
            const responses = App.state.data.responses
                .filter(r => r.survey_id === survey.id);
            const questions = App.allQuestions();
            const sent = App.state.data.customers
                .filter(c => c.source === 'kintone' && c.sent_at).length;

            const selected = questions.filter(q =>
                App.state.selectedQuestions[q.id] !== false
            );

            return `
            <main class="max-w-7xl mx-auto p-4 md:p-6">
              <div class="flex justify-between items-center mb-6">
                <div>
                  <button class="px-4 py-2 rounded-lg bg-white"
                    onclick="App.actions.go('list')">← 一覧</button>
                  <h1 class="text-2xl font-bold mt-4">${App.escape(survey.title)}</h1>
                </div>

                <form method="post">
                  <input type="hidden" name="action" value="csv">
                  <input type="hidden" name="csrf_token" value="${App.escape(App.state.token)}">
                  <input type="hidden" name="survey_id" value="${App.escape(survey.id)}">
                  <button class="px-4 py-3 rounded-xl bg-slate-900 text-white">
                    CSV出力
                  </button>
                </form>
              </div>

              <div class="grid md:grid-cols-5 gap-3 mb-6">
                ${[
                  ['送信対象者数',sent],
                  ['回答数',responses.length],
                  ['未登録回答',responses.filter(r =>
                    !App.state.data.customers.some(c =>
                      c.id === r.customer_id && c.source === 'kintone'
                    )).length],
                  ['未回答',Math.max(0,sent-responses.filter(r =>
                    App.state.data.customers.some(c =>
                      c.id === r.customer_id)).length)],
                  ['回答率',sent ? ((responses.length/sent)*100).toFixed(1)+'%' : '0.0%']
                ].map(x => `
                  <div class="bg-white rounded-2xl p-5 shadow-sm">
                    <div class="text-sm text-slate-500">${x[0]}</div>
                    <div class="text-2xl font-bold mt-2">${x[1]}</div>
                  </div>`).join('')}
              </div>

              <div class="bg-white rounded-2xl shadow-sm p-5 mb-6">
                <div class="flex justify-between items-center">
                  <h2 class="font-bold">設問表示</h2>
                  <div class="flex gap-2">
                    <button class="px-3 py-2 rounded-lg bg-slate-100"
                      onclick="App.actions.selectQuestions(true)">
                      全選択
                    </button>
                    <button class="px-3 py-2 rounded-lg bg-slate-100"
                      onclick="App.actions.selectQuestions(false)">
                      全解除
                    </button>
                  </div>
                </div>

                <div class="mt-4 grid md:grid-cols-2 gap-2">
                ${questions.map(q => `
                  <label class="flex gap-2 items-center border rounded-lg p-3">
                    <input type="checkbox"
                      ${App.state.selectedQuestions[q.id] !== false ? 'checked' : ''}
                      onchange="App.actions.selectQuestion('${q.id}',this.checked)">
                    <span>${App.escape(App.questionNumber(q.id))} ${App.escape(q.text)}</span>
                  </label>`).join('')}
                </div>
              </div>

              <div class="space-y-5">
              ${selected.map(q => App.views.stats(q, responses)).join('')}
              </div>

              <div class="mt-6 bg-white rounded-2xl shadow-sm overflow-x-auto">
                <table class="w-full min-w-[900px]">
                  <thead class="bg-slate-50">
                    <tr>
                      <th class="p-3 text-left">日時</th>
                      <th class="p-3 text-left">会社名</th>
                      <th class="p-3 text-left">氏名</th>
                      <th class="p-3 text-left">操作</th>
                    </tr>
                  </thead>
                  <tbody id="response_table">
                  ${responses.map(r => `
                    <tr class="border-t">
                      <td class="p-3">${App.escape(r.answered_at)}</td>
                      <td class="p-3">${App.escape(r.company)}</td>
                      <td class="p-3">${App.escape(r.name)}</td>
                      <td class="p-3">
                        <button class="text-indigo-600"
                          onclick="App.actions.response('${r.id}')">
                          全回答を表示
                        </button>
                      </td>
                    </tr>`).join('')}
                  </tbody>
                </table>
              </div>

              <div id="response_modal"></div>
            </main>`;
        },

        stats(q, responses) {
            const values = responses
                .map(r => r.answers?.[q.id])
                .filter(v => v !== undefined && v !== null && v !== '');

            if (q.type === 'text') {
                return `
                <div class="bg-white rounded-2xl shadow-sm p-5">
                  <h2 class="font-bold">${App.escape(App.questionNumber(q.id))} ${App.escape(q.text)}</h2>
                  <div class="mt-4 space-y-3 max-h-96 overflow-auto">
                  ${values.map(v => `
                    <div class="bg-slate-50 rounded-xl p-4">${App.escape(v)}</div>
                  `).join('') || '<div class="text-slate-400">回答なし</div>'}
                  </div>
                </div>`;
            }

            const counts = {};

            for (const o of q.options || []) {
                counts[o] = 0;
            }

            for (const v of values) {
                const arr = Array.isArray(v) ? v : [v];

                for (const x of arr) {
                    counts[x] = (counts[x] || 0) + 1;
                }
            }

            return `
            <div class="bg-white rounded-2xl shadow-sm p-5">
              <h2 class="font-bold">${App.escape(App.questionNumber(q.id))} ${App.escape(q.text)}</h2>
              <div class="mt-5 space-y-4">
              ${Object.entries(counts).map(([label,count]) => {
                  const percent = values.length
                    ? (count / values.length * 100)
                    : 0;

                  return `
                  <div>
                    <div class="flex justify-between text-sm mb-1">
                      <span>${App.escape(label)}</span>
                      <span>${count}件 / ${percent.toFixed(1)}%</span>
                    </div>
                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                      <div class="h-full bg-indigo-500 rounded-full"
                        style="width:${percent}%"></div>
                    </div>
                  </div>`;
              }).join('')}
              </div>
            </div>`;
        },

        settings() {
            const s = App.state.data.settings;

            return `
            <main class="max-w-5xl mx-auto p-4 md:p-6">
              <h1 class="text-2xl font-bold mb-6">kintone連携設定</h1>

              <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="grid md:grid-cols-2 gap-4">
                  <label>
                    <span class="text-sm font-semibold">サブドメイン</span>
                    <input id="setting_subdomain"
                      class="mt-1 w-full border rounded-xl px-4 py-3"
                      placeholder="xxxx.cybozu.com"
                      value="${App.escape(s.subdomain)}">
                  </label>

                  <label>
                    <span class="text-sm font-semibold">アプリID</span>
                    <input id="setting_app_id"
                      class="mt-1 w-full border rounded-xl px-4 py-3"
                      value="${App.escape(s.app_id)}">
                  </label>

                  <label>
                    <span class="text-sm font-semibold">ログイン名</span>
                    <input id="setting_login_name"
                      class="mt-1 w-full border rounded-xl px-4 py-3"
                      value="${App.escape(s.login_name)}">
                  </label>

                  <label>
                    <span class="text-sm font-semibold">パスワード</span>
                    <input id="setting_password" type="password"
                      class="mt-1 w-full border rounded-xl px-4 py-3"
                      value="">
                  </label>

                  <label class="md:col-span-2">
                    <span class="text-sm font-semibold">Proxy</span>
                    <input id="setting_proxy"
                      class="mt-1 w-full border rounded-xl px-4 py-3"
                      placeholder="host:port"
                      value="${App.escape(s.proxy)}">
                  </label>

                  <label class="flex gap-2 items-center">
                    <input id="setting_ssl_verify"
                      type="checkbox"
                      ${s.ssl_verify ? 'checked' : ''}>
                    SSL証明書を検証する
                  </label>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                  <button
                    onclick="App.actions.saveSettings()"
                    class="px-5 py-3 rounded-xl bg-indigo-600 text-white">
                    設定を保存
                  </button>

                  <button
                    onclick="App.actions.kintoneTest()"
                    class="px-5 py-3 rounded-xl bg-slate-900 text-white">
                    接続確認
                  </button>

                  <button
                    onclick="App.actions.fetchKintoneFields()"
                    class="px-5 py-3 rounded-xl bg-slate-100">
                    項目一覧を取得
                  </button>

                  <button
                    onclick="App.actions.syncKintone()"
                    class="px-5 py-3 rounded-xl bg-emerald-50 text-emerald-700">
                    顧客を手動同期
                  </button>
                </div>

                <div id="field_message"
                  class="mt-5 whitespace-pre-wrap"></div>

                <div id="kintone_fields"
                  class="mt-6"></div>
              </div>
            </main>`;
        }
    },

    allQuestions() {
        const a = [];

        for (const g of this.state.editing.groups || []) {
            for (const q of g.questions || []) {
                a.push(q);
            }
        }

        return a;
    },

    questionNumber(id) {
        let n = 0;

        for (let gi = 0; gi < (this.state.editing.groups || []).length; gi++) {
            const g = this.state.editing.groups[gi];

            for (let qi = 0; qi < (g.questions || []).length; qi++) {
                n++;

                if (g.questions[qi].id === id) {
                    return this.state.editing.numbering_mode === 'group'
                        ? `Q${gi + 1}-${qi + 1}`
                        : `Q${n}`;
                }
            }
        }

        return '';
    },

    actions: {
        go(screen) {
            App.state.screen = screen;
            App.render();
        },

        search(value) {
            App.state.keyword = value;
            App.render();
        },

        toggleStatusFilter(value) {
            App.state.status_filter = value;
            App.render();
        },

        sort(value) {
            App.state.sort = value;
            App.render();
        },

        newSurvey() {
            const g = {
                id: App.id(),
                name: '基本情報',
                questions: [{
                    id: App.id(),
                    text: '質問文を入力してください',
                    type: 'single',
                    required: false,
                    options: ['選択肢1', '選択肢2'],
                    other_enabled: false,
                    option_branches: {
                        '0': '',
                        '1': ''
                    }
                }]
            };

            App.state.editing = {
                id: App.id(),
                title: '新規アンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: '',
                updated_at: '',
                numbering_mode: 'global',
                groups: [g],
                deleted: false
            };

            App.state.screen = 'edit';
            App.render();
        },

        editSurvey(id) {
            const found = App.state.data.surveys.find(x => x.id === id);

            if (!found) return;

            App.state.editing = JSON.parse(JSON.stringify(found));
            App.state.screen = 'edit';
            App.render();
        },

        cancelEdit() {
            if (confirm('変更を破棄して一覧へ戻りますか？')) {
                App.actions.go('list');
            }
        },

        saveSurvey() {
            const s = App.state.editing;

            s.title = document.getElementById('survey_title').value;
            s.start_at = document.getElementById('survey_start_at').value;
            s.end_at = document.getElementById('survey_end_at').value;
            s.numbering_mode =
                document.getElementById('survey_numbering_mode').value;

            App.api('save_survey', {
                survey_json: s
            })
            .then(r => {
                const i = App.state.data.surveys.findIndex(
                    x => x.id === r.survey.id
                );

                if (i >= 0) {
                    App.state.data.surveys[i] = r.survey;
                } else {
                    App.state.data.surveys.push(r.survey);
                }

                alert('保存しました。');
                App.actions.go('list');
            })
            .catch(e => alert(e.message));
        },

        numbering(value) {
            App.state.editing.numbering_mode = value;
            App.render();
        },

        groupName(id, value) {
            const g = App.state.editing.groups.find(x => x.id === id);
            if (g) g.name = value;
        },

        addGroup() {
            App.state.editing.groups.push({
                id: App.id(),
                name: '新しいグループ',
                questions: []
            });

            App.render();
        },

        removeGroup(id) {
            if (!confirm('グループと内包する質問を削除しますか？')) {
                return;
            }

            App.state.editing.groups =
                App.state.editing.groups.filter(x => x.id !== id);

            App.render();
        },

        addQuestion(groupId) {
            const g = App.state.editing.groups.find(x => x.id === groupId);

            if (!g) return;

            g.questions.push({
                id: App.id(),
                text: '新しい質問',
                type: 'single',
                required: false,
                options: ['選択肢1', '選択肢2'],
                other_enabled: false,
                option_branches: {
                    '0': '',
                    '1': ''
                }
            });

            App.render();
        },

        removeQuestion(id) {
            if (!confirm('この質問を削除しますか？')) {
                return;
            }

            for (const g of App.state.editing.groups) {
                g.questions = g.questions.filter(q => q.id !== id);
            }

            App.render();
        },

        questionText(id, value) {
            const q = App.findQuestion(id);
            if (q) q.text = value;
        },

        questionType(id, value) {
            const q = App.findQuestion(id);

            if (!q) return;

            q.type = value;

            if (value === 'text') {
                q.options = [];
                q.option_branches = {};
            } else if (!q.options.length) {
                q.options = ['選択肢1', '選択肢2'];
                q.option_branches = {'0':'','1':''};
            }

            App.render();
        },

        required(id, value) {
            const q = App.findQuestion(id);
            if (q) q.required = value;
        },

        other(id, value) {
            const q = App.findQuestion(id);
            if (q) q.other_enabled = value;
        },

        optionText(id, index, value) {
            const q = App.findQuestion(id);

            if (q) q.options[index] = value;
        },

        addOption(id) {
            const q = App.findQuestion(id);

            if (!q) return;

            q.options.push(`選択肢${q.options.length + 1}`);
            q.option_branches[String(q.options.length - 1)] = '';

            App.render();
        },

        removeOption(id, index) {
            const q = App.findQuestion(id);

            if (!q) return;

            q.options.splice(index, 1);

            const branches = {};
            q.options.forEach((_, i) => {
                branches[String(i)] =
                    q.option_branches?.[String(i)] || '';
            });

            q.option_branches = branches;

            App.render();
        },

        branch(id, index, target) {
            const q = App.findQuestion(id);

            if (!q) return;

            q.option_branches[String(index)] = target;
        },

        status(id, status) {
            const labels = {
                draft: '公開',
                active: '停止',
                ended: '再開'
            };

            if (!confirm(`${labels[status]}しますか？`)) return;

            App.api('status', {
                survey_id: id,
                status
            })
            .then(() => {
                const s = App.state.data.surveys.find(x => x.id === id);
                if (s) s.status = status;
                App.render();
            })
            .catch(e => alert(e.message));
        },

        deleteSurvey(id) {
            if (!confirm('この下書きを削除しますか？')) return;

            App.api('delete_survey', {
                survey_id: id
            })
            .then(() => {
                const s = App.state.data.surveys.find(x => x.id === id);
                if (s) s.deleted = true;
                App.render();
            })
            .catch(e => alert(e.message));
        },

        duplicate(id) {
            App.api('duplicate', {
                survey_id: id
            })
            .then(r => {
                App.state.data.surveys.push(r.survey);
                alert('下書きとして複製しました。');
                App.render();
            })
            .catch(e => alert(e.message));
        },

        aggregate(id) {
            const s = App.state.data.surveys.find(x => x.id === id);

            if (!s) return;

            App.state.survey = s;
            App.state.selectedQuestions = {};

            for (const q of App.allQuestionsFor(s)) {
                App.state.selectedQuestions[q.id] = true;
            }

            App.state.screen = 'aggregate';
            App.render();
        },

        send(id) {
            const s = App.state.data.surveys.find(x => x.id === id);

            if (!s) return;

            App.state.survey = s;
            App.state.selectedCustomers = [];
            App.state.screen = 'send';
            App.render();
        },

        customerSelect(id, checked) {
            if (checked) {
                if (!App.state.selectedCustomers.includes(id)) {
                    App.state.selectedCustomers.push(id);
                }
            } else {
                App.state.selectedCustomers =
                    App.state.selectedCustomers.filter(x => x !== id);
            }
        },

        selectAllCustomers(checked = true) {
            App.state.selectedCustomers = checked
                ? App.state.data.customers
                    .filter(c => c.source !== 'web')
                    .map(c => c.id)
                : [];

            App.render();
        },

        sendMail() {
            const subject = document.getElementById('mail_subject').value;
            const body = document.getElementById('mail_body').value;
            const templateType =
                document.getElementById('template_type').value;

            if (!App.state.selectedCustomers.length) {
                alert('送信先を選択してください。');
                return;
            }

            const selected = App.state.data.customers.filter(
                c => App.state.selectedCustomers.includes(c.id)
            );

            if (selected.some(c => c.sent_at)) {
                if (!confirm(
                    '既に送信済みの宛先が含まれています。再送しますか？'
                )) {
                    return;
                }
            }

            App.api('send_mail', {
                survey_id: App.state.survey.id,
                recipient_ids: App.state.selectedCustomers,
                mail_subject: subject,
                mail_body: body,
                template_type: templateType
            })
            .then(r => {
                alert(
                    `送信完了\n送信: ${r.sent}件\n失敗: ${r.failed}件`
                );

                return App.api('list', {});
            })
            .then(() => location.reload())
            .catch(e => alert(e.message));
        },

        markKintone(id) {
            App.api('mark_kintone', {
                customer_id: id
            })
            .then(() => {
                const c = App.state.data.customers.find(x => x.id === id);
                if (c) c.kintone_status = 'registered';
                App.render();
            })
            .catch(e => alert(e.message));
        },

        selectQuestion(id, checked) {
            App.state.selectedQuestions[id] = checked;
            App.render();
        },

        selectQuestions(value) {
            for (const q of App.allQuestions()) {
                App.state.selectedQuestions[q.id] = value;
            }

            App.render();
        },

        response(id) {
            const r = App.state.data.responses.find(x => x.id === id);

            if (!r) return;

            const s = App.state.survey;

            const html = `
            <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
              <div class="bg-white rounded-2xl max-w-3xl w-full max-h-[90vh] overflow-auto p-6">
                <div class="flex justify-between">
                  <h2 class="text-xl font-bold">全回答</h2>
                  <button
                    onclick="document.getElementById('response_modal').innerHTML=''"
                    class="text-2xl">×</button>
                </div>

                <div class="mt-5 space-y-4">
                ${App.allQuestionsFor(s).map(q => {
                    let v = r.answers?.[q.id] ?? '';
                    if (Array.isArray(v)) v = v.join(', ');

                    return `
                    <div class="border rounded-xl p-4">
                      <div class="font-semibold">
                        ${App.escape(App.questionNumberFor(s,q.id))}
                        ${App.escape(q.text)}
                      </div>
                      <div class="mt-2 text-slate-600 whitespace-pre-wrap">
                        ${App.escape(v)}
                      </div>
                    </div>`;
                }).join('')}
                </div>
              </div>
            </div>`;

            document.getElementById('response_modal').innerHTML = html;
        },

        preview() {
            const s = App.state.editing;

            document.getElementById('preview_modal').innerHTML = `
            <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
              <div class="${App.state.previewMobile ? 'max-w-sm' : 'max-w-3xl'} bg-white rounded-2xl w-full max-h-[90vh] overflow-auto">
                <div class="p-4 border-b flex justify-between items-center">
                  <b>回答者プレビュー</b>
                  <div class="flex gap-2">
                    <button
                      onclick="App.actions.previewMobile(false)"
                      class="px-3 py-2 rounded-lg bg-slate-100">
                      PC
                    </button>
                    <button
                      onclick="App.actions.previewMobile(true)"
                      class="px-3 py-2 rounded-lg bg-slate-100">
                      スマホ
                    </button>
                    <button
                      onclick="document.getElementById('preview_modal').innerHTML=''"
                      class="px-3 py-2">×</button>
                  </div>
                </div>

                <div class="p-6">
                  <h2 class="text-2xl font-bold">${App.escape(s.title)}</h2>
                  <div class="mt-6 space-y-5">
                  ${App.allQuestionsFor(s).map(q => `
                    <div class="border rounded-xl p-4">
                      <div class="font-semibold">
                        ${App.escape(App.questionNumber(q.id))} ${App.escape(q.text)}
                      </div>
                      ${
                        q.type === 'text'
                        ? '<textarea class="border rounded-lg w-full mt-3 p-3 h-28"></textarea>'
                        : (q.options || []).map(o => `
                          <label class="block mt-3">
                            <input type="${q.type === 'multiple' ? 'checkbox' : 'radio'}">
                            ${App.escape(o)}
                          </label>`).join('')
                      }
                    </div>
                  `).join('')}
                  </div>

                  <button
                    onclick="alert('プレビューでは実送信されません。')"
                    class="mt-6 w-full py-3 rounded-xl bg-indigo-600 text-white">
                    送信
                  </button>
                </div>
              </div>
            </div>`;
        },

        previewMobile(value) {
            App.state.previewMobile = value;
            App.actions.preview();
        },

        saveSettings() {
            const settings = {
                subdomain:
                    document.getElementById('setting_subdomain').value,
                app_id:
                    document.getElementById('setting_app_id').value,
                login_name:
                    document.getElementById('setting_login_name').value,
                password:
                    document.getElementById('setting_password').value,
                proxy:
                    document.getElementById('setting_proxy').value,
                ssl_verify:
                    document.getElementById('setting_ssl_verify').checked,
                field_company: App.state.data.settings.field_company,
                field_name: App.state.data.settings.field_name,
                field_email: App.state.data.settings.field_email,
                field_department: App.state.data.settings.field_department,
                field_phone: App.state.data.settings.field_phone,
                field_address: App.state.data.settings.field_address
            };

            App.api('save_settings', {
                settings_json: settings
            })
            .then(() => {
                App.state.data.settings = {
                    ...App.state.data.settings,
                    ...settings
                };

                alert('設定を保存しました。');
            })
            .catch(e => alert(e.message));
        },

        kintoneTest() {
            const settings = App.actions.readSettings();

            const box = document.getElementById('field_message');

            box.className =
                'mt-5 whitespace-pre-wrap rounded-xl bg-slate-50 p-4';

            box.textContent = '接続確認中...';

            App.api('kintone_test', {
                settings_json: settings
            })
            .then(r => {
                box.textContent = r.message;
                box.className =
                    'mt-5 whitespace-pre-wrap rounded-xl bg-emerald-50 text-emerald-800 p-4';
            })
            .catch(e => {
                box.textContent = e.message;
                box.className =
                    'mt-5 whitespace-pre-wrap rounded-xl bg-red-50 text-red-700 p-4';
            });
        },

        fetchKintoneFields() {
            const settings = App.actions.readSettings();
            const box = document.getElementById('field_message');

            box.textContent = '項目取得中...';

            App.api('kintone_test', {
                settings_json: settings
            })
            .then(r => {
                box.textContent = r.message;
                App.actions.renderKintoneFields(r.fields);
            })
            .catch(e => {
                box.textContent = e.message;
            });
        },

        renderKintoneFields(fields) {
            const target = document.getElementById('kintone_fields');

            if (!target) return;

            const s = App.state.data.settings;

            const select = (key, label, multi = false) => `
              <div>
                <label class="block text-sm font-semibold mb-1">${label}</label>
                <select
                  ${multi ? 'multiple' : ''}
                  data-map="${key}"
                  class="w-full border rounded-xl px-3 py-3">
                  <option value="">未設定</option>
                  ${fields.map(f => `
                    <option value="${App.escape(f.code)}"
                      ${multi
                        ? (s[key] || []).includes(f.code) ? 'selected' : ''
                        : s[key] === f.code ? 'selected' : ''}>
                      ${App.escape(f.label)} (${App.escape(f.code)}) [${App.escape(f.type)}]
                    </option>`).join('')}
                </select>
              </div>`;

            target.innerHTML = `
            <h2 class="font-bold text-lg mb-4">顧客項目マッピング</h2>
            <div class="grid md:grid-cols-2 gap-4">
              ${select('field_company','会社名')}
              ${select('field_name','氏名')}
              ${select('field_email','メールアドレス')}
              ${select('field_department','部署名')}
              ${select('field_phone','電話番号')}
              ${select('field_address','住所',true)}
            </div>
            <button
              class="mt-4 px-4 py-3 rounded-xl bg-indigo-600 text-white"
              onclick="App.actions.applyFieldMapping()">
              マッピングを保存
            </button>`;
        },

        applyFieldMapping() {
            document.querySelectorAll('[data-map]').forEach(el => {
                const key = el.dataset.map;

                if (el.multiple) {
                    App.state.data.settings[key] =
                        [...el.selectedOptions].map(o => o.value);
                } else {
                    App.state.data.settings[key] = el.value;
                }
            });

            App.api('save_settings', {
                settings_json: App.state.data.settings
            })
            .then(() => alert('マッピングを保存しました。'))
            .catch(e => alert(e.message));
        },

        syncKintone() {
            if (!confirm('kintoneから顧客を手動同期しますか？')) {
                return;
            }

            App.api('kintone_sync')
            .then(r => {
                alert(r.message);
                location.reload();
            })
            .catch(e => alert(e.message));
        }
    },

    findQuestion(id) {
        for (const g of this.state.editing.groups || []) {
            const q = (g.questions || []).find(x => x.id === id);
            if (q) return q;
        }

        return null;
    },

    allQuestionsFor(survey) {
        const a = [];

        for (const g of survey.groups || []) {
            for (const q of g.questions || []) {
                a.push(q);
            }
        }

        return a;
    },

    questionNumberFor(survey, id) {
        let n = 0;

        for (let gi = 0; gi < (survey.groups || []).length; gi++) {
            for (
                let qi = 0;
                qi < (survey.groups[gi].questions || []).length;
                qi++
            ) {
                n++;

                if (survey.groups[gi].questions[qi].id === id) {
                    return survey.numbering_mode === 'group'
                        ? `Q${gi + 1}-${qi + 1}`
                        : `Q${n}`;
                }
            }
        }

        return '';
    },

    readSettings() {
        const old = this.state.data.settings;

        return {
            subdomain:
                document.getElementById('setting_subdomain').value,
            app_id:
                document.getElementById('setting_app_id').value,
            login_name:
                document.getElementById('setting_login_name').value,
            password:
                document.getElementById('setting_password').value ||
                old.password,
            proxy:
                document.getElementById('setting_proxy').value,
            ssl_verify:
                document.getElementById('setting_ssl_verify').checked,
            field_company: old.field_company,
            field_name: old.field_name,
            field_email: old.field_email,
            field_department: old.field_department,
            field_phone: old.field_phone,
            field_address: old.field_address
        };
    },

    id() {
        return 'x' + Date.now().toString(36) +
            Math.random().toString(36).slice(2, 10);
    },

    bindEditor() {
        const editor = document.getElementById('question_editor');

        editor.innerHTML =
            this.state.editing.groups
            .map((g, i) => this.views.group(g, i))
            .join('');

        const groupSortable = new Sortable(editor, {
            handle: '.group-handle',
            animation: 180,
            ghostClass: 'opacity-50',
            onEnd: () => {
                const ids = [...editor.querySelectorAll('.group-card')]
                    .map(x => x.dataset.groupId);

                this.state.editing.groups.sort(
                    (a, b) => ids.indexOf(a.id) - ids.indexOf(b.id)
                );

                this.render();
            }
        });

        editor.querySelectorAll('.question-list').forEach(list => {
            new Sortable(list, {
                group: 'survey-questions',
                handle: '.question-handle',
                animation: 180,
                ghostClass: 'opacity-50',
                onEnd: evt => {
                    const fromId = evt.from.dataset.group;
                    const toId = evt.to.dataset.group;
                    const movedId = evt.item.dataset.questionId;

                    const from = this.state.editing.groups.find(
                        g => g.id === fromId
                    );

                    const to = this.state.editing.groups.find(
                        g => g.id === toId
                    );

                    if (!from || !to) return;

                    const moved = this.findQuestion(movedId);

                    if (!moved) return;

                    from.questions =
                        from.questions.filter(q => q.id !== movedId);

                    const index = evt.newIndex ?? to.questions.length;

                    to.questions.splice(index, 0, moved);

                    this.render();
                }
            });
        });
    }
};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        {once: true}
    );
} else {
    App.init();
}
</script>

</body>
</html>

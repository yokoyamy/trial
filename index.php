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
========================================================================
*/

const SURVEY_STORAGE_DIRECTORY =
    __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';

const SURVEY_STORAGE_FILE =
    SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';

const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* --------------------------------------------------------------------
 * 共通
 * ------------------------------------------------------------------ */

function survey_h(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function survey_json(mixed $v): string
{
    return json_encode(
        $v,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: 'null';
}

function survey_uuid(): string
{
    return bin2hex(random_bytes(16));
}

function survey_default_data(): array
{
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

function survey_read_data(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        return survey_default_data();
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($data)) {
        return survey_default_data();
    }

    return array_replace_recursive(
        survey_default_data(),
        $data
    );
}

function survey_write_data(array $data): bool
{
    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    if (
        @file_put_contents(
            $tmp,
            survey_json($data),
            LOCK_EX
        ) === false
    ) {
        return false;
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function survey_check_token(): bool
{
    return hash_equals(
        (string)($_SESSION['csrf_token'] ?? ''),
        (string)($_POST['csrf_token'] ?? '')
    );
}

function survey_api_response(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo survey_json($payload);
    exit;
}

function survey_public_data(array $data): array
{
    $data['settings']['password'] = '';
    return $data;
}

/* --------------------------------------------------------------------
 * kintone URL
 * ------------------------------------------------------------------ */

function survey_normalize_kintone_base(string $input): array
{
    $input = trim($input);

    if ($input === '') {
        return [
            'ok' => false,
            'error' => 'kintoneサブドメインが未入力です。'
        ];
    }

    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }

    $input = rtrim($input, '/');

    $parsed = @parse_url($input);
    $host = '';
    $port = null;

    if (is_array($parsed)) {
        $host = (string)($parsed['host'] ?? '');
        $port = $parsed['port'] ?? null;
    }

    if (
        $host === '' &&
        preg_match(
            '~^https?://([^/?#]+)~i',
            $input,
            $m
        )
    ) {
        $authority = strtolower($m[1]);

        if (str_contains($authority, ':')) {
            [$host, $portString] =
                explode(':', $authority, 2);

            if (ctype_digit($portString)) {
                $port = (int)$portString;
            }
        } else {
            $host = $authority;
        }
    }

    $host = strtolower(trim($host));
    $host = preg_replace('/:\d+$/', '', $host) ?: $host;

    if ($host === '') {
        return [
            'ok' => false,
            'error' => 'kintoneホスト名を取得できません。'
        ];
    }

    /*
     * 通常のcybozu.comを許可。
     * 検証環境用のポートも許可。
     */
    $valid =
        preg_match(
            '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
            $host
        )
        ||
        preg_match(
            '~^[a-z0-9][a-z0-9.-]*$~i',
            $host
        );

    if (!$valid) {
        return [
            'ok' => false,
            'error' => '許可されていないkintoneホスト名です。'
        ];
    }

    $authority = $host;

    if ($port !== null) {
        $port = (int)$port;

        if ($port < 1 || $port > 65535) {
            return [
                'ok' => false,
                'error' => 'ポート番号が不正です。'
            ];
        }

        $authority .= ':' . $port;
    }

    return [
        'ok' => true,
        'base' => 'https://' . $authority,
        'host' => $host,
        'port' => $port,
    ];
}

/* --------------------------------------------------------------------
 * Proxy
 * ------------------------------------------------------------------ */

function survey_parse_proxy(string $input): array
{
    $input = trim($input);

    if ($input === '') {
        return [
            'ok' => true,
            'used' => false,
            'value' => ''
        ];
    }

    if (
        !preg_match(
            '~^(?:(https?)://)?([^/:]+):(\d{1,5})$~i',
            $input,
            $m
        )
    ) {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' =>
                'Proxy形式は host:port、http://host:port、https://host:port で指定してください。'
        ];
    }

    $scheme = strtolower($m[1] ?: 'http');
    $host = $m[2];
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
        'value' => $scheme . '://' . $host . ':' . $port,
        'host' => $host,
        'port' => $port,
    ];
}

/* --------------------------------------------------------------------
 * HTTPレスポンスヘッダー
 *
 * PHP 8.4/8.5:
 * http_get_last_response_headers()を優先。
 * $http_response_headerは存在確認した場合だけ使用。
 * ------------------------------------------------------------------ */

function survey_last_headers(): array
{
    if (
        function_exists(
            'http_get_last_response_headers'
        )
    ) {
        $headers =
            http_get_last_response_headers();

        return is_array($headers)
            ? $headers
            : [];
    }

    /*
     * PHP 8.4/8.5でDeprecatedを発生させないため、
     * 直接の参照を避け、存在する場合だけ取得する。
     */
    if (
        isset($GLOBALS['http_response_header']) &&
        is_array($GLOBALS['http_response_header'])
    ) {
        return $GLOBALS['http_response_header'];
    }

    return [];
}

function survey_status_from_headers(
    array $headers
): int {
    foreach ($headers as $header) {
        if (
            preg_match(
                '~^HTTP/\S+\s+(\d{3})~i',
                (string)$header,
                $m
            )
        ) {
            return (int)$m[1];
        }
    }

    return 0;
}

/* --------------------------------------------------------------------
 * HTTP通信
 * ------------------------------------------------------------------ */

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

    $wrappers = stream_get_wrappers();

    $hasHttp =
        in_array('http', $wrappers, true);

    $hasHttps =
        in_array('https', $wrappers, true);

    if (!$hasHttps) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' =>
                'PHPにHTTPS stream transportが登録されていません。'
                . ' PHPのOpenSSL拡張、php.ini、Apacheから使用しているPHPの設定を確認してください。'
                . ' stream_get_wrappers()でhttpsが確認できません。',
            'url' => $url,
            'proxy_used' => $proxyInfo['used'],
        ];
    }

    if (!$hasHttp) {
        /*
         * HTTPS通信そのものはhttps wrapperを使用するが、
         * PHP環境にhttp wrapperがないことも診断情報として明示する。
         */
        $transportWarning =
            'PHPのhttp stream transportが登録されていません。';
    } else {
        $transportWarning = '';
    }

    $httpOptions = [
        'method' => strtoupper($method),
        'timeout' => 30,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'header' => implode("\r\n", $headers),
    ];

    if ($content !== null) {
        $httpOptions['content'] = $content;
    }

    if ($proxyInfo['used']) {
        $httpOptions['proxy'] =
            $proxyInfo['value'];

        $httpOptions['request_fulluri'] = true;
    }

    $parts = @parse_url($url);

    $peerName = is_array($parts)
        ? (string)($parts['host'] ?? '')
        : '';

    $context = stream_context_create([
        'http' => $httpOptions,
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

    $body =
        file_get_contents(
            $url,
            false,
            $context
        );

    restore_error_handler();

    $receivedHeaders =
        survey_last_headers();

    $status =
        survey_status_from_headers(
            $receivedHeaders
        );

    $bodyText =
        is_string($body)
            ? $body
            : '';

    if ($status === 0) {
        $parts = [];

        if ($warning !== '') {
            $parts[] =
                'PHP通信エラー: ' . $warning;
        }

        if ($transportWarning !== '') {
            $parts[] = $transportWarning;
        }

        $parts[] =
            '確認事項: DNS、PHPサーバーからの外部HTTPS通信、Proxy、ファイアウォール、OpenSSL、タイムアウト。';

        if ($proxyInfo['used']) {
            $parts[] =
                'Proxy: 使用';
            $parts[] =
                'Proxy接続失敗の可能性があります。';
        } else {
            $parts[] =
                'Proxy: 未使用';
        }

        return [
            'status' => 0,
            'body' => $bodyText,
            'json' => json_decode(
                $bodyText,
                true
            ),
            'error' => implode(
                ' ',
                $parts
            ),
            'url' => $url,
            'proxy_used' =>
                $proxyInfo['used'],
        ];
    }

    return [
        'status' => $status,
        'body' => $bodyText,
        'json' => json_decode(
            $bodyText,
            true
        ),
        'error' =>
            $warning !== ''
                ? $warning
                : '',
        'url' => $url,
        'proxy_used' =>
            $proxyInfo['used'],
    ];
}

/* --------------------------------------------------------------------
 * kintone
 * ------------------------------------------------------------------ */

function survey_kintone_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?string $content = null
): array {
    $normalized =
        survey_normalize_kintone_base(
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

    $appId =
        trim(
            (string)($settings['app_id'] ?? '')
        );

    if (
        $appId === '' ||
        !preg_match('/^\d+$/', $appId)
    ) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' =>
                'アプリIDは数字で入力してください。',
            'url' => '',
            'proxy_used' => false,
        ];
    }

    $url =
        $normalized['base']
        . '/k/v1/'
        . ltrim($path, '/');

    $url .=
        '?app=' .
        rawurlencode($appId);

    $login =
        (string)($settings['login_name'] ?? '');

    $password =
        (string)($settings['password'] ?? '');

    $auth =
        base64_encode(
            $login . ':' . $password
        );

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
        'Connection: close',
    ];

    if ($content !== null) {
        $headers[] =
            'Content-Type: application/json';
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

/* --------------------------------------------------------------------
 * API
 * ------------------------------------------------------------------ */

$action =
    (string)(
        $_POST['action']
        ?? $_GET['action']
        ?? ''
    );

if ($action !== '') {

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        !survey_check_token()
    ) {
        survey_api_response([
            'ok' => false,
            'message' =>
                'CSRFトークンが不正です。'
        ], 419);
    }

    $data = survey_read_data();

    if ($action === 'load') {
        survey_api_response([
            'ok' => true,
            'data' =>
                survey_public_data($data)
        ]);
    }

    if ($action === 'save_settings') {

        $settings =
            json_decode(
                (string)(
                    $_POST['settings_json']
                    ?? ''
                ),
                true
            );

        if (!is_array($settings)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    '設定データが不正です。'
            ], 400);
        }

        $data['settings'] =
            array_replace(
                $data['settings'],
                [
                    'subdomain' =>
                        trim(
                            (string)(
                                $settings['subdomain']
                                ?? ''
                            )
                        ),

                    'login_name' =>
                        trim(
                            (string)(
                                $settings['login_name']
                                ?? ''
                            )
                        ),

                    'password' =>
                        (string)(
                            $settings['password']
                            ?? ''
                        ),

                    'app_id' =>
                        trim(
                            (string)(
                                $settings['app_id']
                                ?? ''
                            )
                        ),

                    'ssl_verify' =>
                        (bool)(
                            $settings['ssl_verify']
                            ?? true
                        ),

                    'proxy' =>
                        trim(
                            (string)(
                                $settings['proxy']
                                ?? ''
                            )
                        ),

                    'field_company' =>
                        (string)(
                            $settings['field_company']
                            ?? ''
                        ),

                    'field_name' =>
                        (string)(
                            $settings['field_name']
                            ?? ''
                        ),

                    'field_email' =>
                        (string)(
                            $settings['field_email']
                            ?? ''
                        ),

                    'field_department' =>
                        (string)(
                            $settings['field_department']
                            ?? ''
                        ),

                    'field_phone' =>
                        (string)(
                            $settings['field_phone']
                            ?? ''
                        ),

                    'field_address' =>
                        is_array(
                            $settings['field_address']
                            ?? null
                        )
                            ? array_values(
                                $settings['field_address']
                            )
                            : [],
                ]
            );

        if (!survey_write_data($data)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    '設定保存に失敗しました。'
            ], 500);
        }

        survey_api_response([
            'ok' => true,
            'message' =>
                '設定を保存しました。',
            'data' =>
                survey_public_data($data)
        ]);
    }

    /* ---------------------------------------------------------------
     * kintone項目取得 / 接続確認
     * ------------------------------------------------------------- */

    if (
        $action === 'kintone_fields' ||
        $action === 'kintone_test'
    ) {

        $result =
            survey_kintone_request(
                $data['settings'],
                'app/form/fields.json'
            );

        if ($result['status'] === 0) {
            survey_api_response([
                'ok' => false,
                'status' => 0,
                'message' =>
                    'kintoneからHTTPレスポンスを取得できませんでした。',
                'diagnostic' => [
                    'status' => 0,
                    'url' =>
                        $result['url'],
                    'proxy_used' =>
                        $result['proxy_used'],
                    'error' =>
                        $result['error'],
                    'body' =>
                        $result['body'],
                ],
            ]);
        }

        if (
            $result['status'] === 401 ||
            $result['status'] === 403
        ) {
            survey_api_response([
                'ok' => false,
                'status' =>
                    $result['status'],
                'message' =>
                    '認証または権限エラーです。',
                'diagnostic' =>
                    $result,
            ]);
        }

        if (
            $result['status'] === 404
        ) {
            survey_api_response([
                'ok' => false,
                'status' => 404,
                'message' =>
                    'kintone API URLまたはアプリIDを確認してください。',
                'diagnostic' =>
                    $result,
            ]);
        }

        if (
            $result['status'] < 200 ||
            $result['status'] >= 300
        ) {
            survey_api_response([
                'ok' => false,
                'status' =>
                    $result['status'],
                'message' =>
                    'kintone APIエラーです。',
                'diagnostic' =>
                    $result,
            ]);
        }

        $properties =
            $result['json']['properties']
            ?? [];

        survey_api_response([
            'ok' => true,
            'status' =>
                $result['status'],
            'message' =>
                $action === 'kintone_test'
                    ? 'kintone接続に成功しました。'
                    : '項目一覧を取得しました。',
            'fields' =>
                $properties,
        ]);
    }

    /* ---------------------------------------------------------------
     * アンケート保存
     * ------------------------------------------------------------- */

    if ($action === 'save_survey') {

        $survey =
            json_decode(
                (string)(
                    $_POST['survey_json']
                    ?? ''
                ),
                true
            );

        if (!is_array($survey)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    'アンケートデータが不正です。'
            ], 400);
        }

        $survey['id'] =
            (string)(
                $survey['id']
                ?? survey_uuid()
            );

        $survey['title'] =
            trim(
                (string)(
                    $survey['title']
                    ?? '無題のアンケート'
                )
            );

        $survey['status'] =
            in_array(
                $survey['status'] ?? 'draft',
                ['draft', 'active', 'ended'],
                true
            )
                ? $survey['status']
                : 'draft';

        $survey['numbering_mode'] =
            ($survey['numbering_mode'] ?? 'global')
            === 'group'
                ? 'group'
                : 'global';

        $survey['updated_at'] =
            date('c');

        $survey['created_at'] =
            (string)(
                $survey['created_at']
                ?? date('c')
            );

        $survey['deleted'] = false;

        if (!isset($survey['groups']) ||
            !is_array($survey['groups'])) {
            $survey['groups'] = [];
        }

        foreach ($survey['groups'] as &$group) {

            if (!isset($group['id']) ||
                $group['id'] === '') {
                $group['id'] =
                    survey_uuid();
            }

            if (!isset($group['questions']) ||
                !is_array($group['questions'])) {
                $group['questions'] = [];
            }

            foreach ($group['questions'] as &$question) {

                if (!isset($question['id']) ||
                    $question['id'] === '') {
                    $question['id'] =
                        survey_uuid();
                }

                $question['text'] =
                    (string)(
                        $question['text']
                        ?? ''
                    );

                $question['type'] =
                    in_array(
                        $question['type'] ?? 'single',
                        ['single', 'multiple', 'text'],
                        true
                    )
                        ? $question['type']
                        : 'single';

                $question['required'] =
                    (bool)(
                        $question['required']
                        ?? false
                    );

                if (!is_array(
                    $question['options']
                    ?? null
                )) {
                    $question['options'] = [];
                }
            }

            unset($question);
        }

        unset($group);

        $found = false;

        foreach (
            $data['surveys']
            as $index => $existing
        ) {
            if (
                ($existing['id'] ?? '')
                === $survey['id']
            ) {
                $data['surveys'][$index] =
                    $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['surveys'][] =
                $survey;
        }

        if (!survey_write_data($data)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    'アンケート保存に失敗しました。'
            ], 500);
        }

        survey_api_response([
            'ok' => true,
            'message' =>
                'アンケートを保存しました。',
            'data' =>
                survey_public_data($data)
        ]);
    }

    if ($action === 'delete_survey') {

        $surveyId =
            (string)(
                $_POST['survey_id']
                ?? ''
            );

        foreach (
            $data['surveys']
            as &$survey
        ) {
            if (
                ($survey['id'] ?? '')
                === $surveyId
            ) {
                $survey['deleted'] = true;
                $survey['updated_at'] =
                    date('c');
            }
        }

        unset($survey);

        survey_write_data($data);

        survey_api_response([
            'ok' => true,
            'data' =>
                survey_public_data($data)
        ]);
    }

    if ($action === 'duplicate_survey') {

        $surveyId =
            (string)(
                $_POST['survey_id']
                ?? ''
            );

        $copy = null;

        foreach (
            $data['surveys']
            as $survey
        ) {
            if (
                ($survey['id'] ?? '')
                === $surveyId
            ) {
                $copy = $survey;
                break;
            }
        }

        if (!is_array($copy)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    '対象アンケートがありません。'
            ], 404);
        }

        $copy['id'] =
            survey_uuid();

        $copy['title'] =
            (string)$copy['title']
            . '（複製）';

        $copy['status'] = 'draft';
        $copy['created_at'] =
            date('c');
        $copy['updated_at'] =
            date('c');
        $copy['deleted'] = false;

        $data['surveys'][] =
            $copy;

        survey_write_data($data);

        survey_api_response([
            'ok' => true,
            'data' =>
                survey_public_data($data)
        ]);
    }

    /* ---------------------------------------------------------------
     * 回答保存
     * ------------------------------------------------------------- */

    if ($action === 'save_response') {

        $response =
            json_decode(
                (string)(
                    $_POST['response_json']
                    ?? ''
                ),
                true
            );

        if (!is_array($response)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    '回答データが不正です。'
            ], 400);
        }

        $response['id'] =
            (string)(
                $response['id']
                ?? survey_uuid()
            );

        $response['answered_at'] =
            date('c');

        $data['responses'][] =
            $response;

        survey_write_data($data);

        survey_api_response([
            'ok' => true,
            'message' =>
                '回答を保存しました。'
        ]);
    }

    /* ---------------------------------------------------------------
     * CSV
     * ------------------------------------------------------------- */

    if ($action === 'csv') {

        $surveyId =
            (string)(
                $_GET['survey_id']
                ?? ''
            );

        $survey = null;

        foreach (
            $data['surveys']
            as $item
        ) {
            if (
                ($item['id'] ?? '')
                === $surveyId
            ) {
                $survey = $item;
                break;
            }
        }

        if (!is_array($survey)) {
            http_response_code(404);
            exit('Not Found');
        }

        $questions = [];

        foreach (
            ($survey['groups'] ?? [])
            as $group
        ) {
            foreach (
                ($group['questions'] ?? [])
                as $question
            ) {
                $questions[] =
                    $question;
            }
        }

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="survey_'
            . rawurlencode($surveyId)
            . '.csv"'
        );

        $out =
            fopen(
                'php://output',
                'wb'
            );

        fwrite(
            $out,
            "\xEF\xBB\xBF"
        );

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
            'メールアドレス'
        ];

        foreach ($questions as $question) {
            $header[] =
                (string)(
                    $question['text']
                    ?? ''
                );
        }

        fputcsv($out, $header);

        foreach (
            $data['responses']
            as $response
        ) {

            if (
                ($response['survey_id'] ?? '')
                !== $surveyId
            ) {
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

            $answers =
                is_array(
                    $response['answers']
                    ?? null
                )
                    ? $response['answers']
                    : [];

            foreach ($questions as $question) {

                $id =
                    (string)(
                        $question['id']
                        ?? ''
                    );

                $value =
                    $answers[$id]
                    ?? '';

                $row[] =
                    is_array($value)
                        ? implode('、', $value)
                        : $value;
            }

            fputcsv(
                $out,
                $row
            );
        }

        fclose($out);
        exit;
    }

    survey_api_response([
        'ok' => false,
        'message' =>
            '不明な操作です。'
    ], 400);
}

/* --------------------------------------------------------------------
 * 初期データ
 * ------------------------------------------------------------------ */

$data = survey_read_data();
$csrf = survey_token();

/* --------------------------------------------------------------------
 * 公開アンケート
 * ------------------------------------------------------------------ */

if (
    isset(
        $_GET['public'],
        $_GET['survey_id']
    )
) {

    $surveyId =
        (string)$_GET['survey_id'];

    $publicSurvey = null;

    foreach (
        $data['surveys']
        as $survey
    ) {
        if (
            ($survey['id'] ?? '')
            === $surveyId &&
            empty($survey['deleted'])
        ) {
            $publicSurvey =
                $survey;
            break;
        }
    }

    if (!is_array($publicSurvey)) {
        http_response_code(404);
        exit(
            'アンケートが見つかりません。'
        );
    }

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= survey_h($publicSurvey['title']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 text-slate-800">

<main class="max-w-3xl mx-auto p-6">

<section class="bg-white rounded-2xl shadow p-6">

<h1 class="text-2xl font-bold mb-6">
<?= survey_h($publicSurvey['title']) ?>
</h1>

<form method="post"
      id="public_form"
      class="space-y-6">

<input type="hidden"
       name="action"
       value="save_response">

<input type="hidden"
       name="csrf_token"
       value="<?= survey_h($csrf) ?>">

<input type="hidden"
       name="response_json"
       id="public_response_json">

<div>
<label class="block font-semibold mb-1">
会社名
</label>
<input id="public_company"
       class="w-full border rounded-lg p-2">
</div>

<div>
<label class="block font-semibold mb-1">
氏名
</label>
<input id="public_name"
       class="w-full border rounded-lg p-2">
</div>

<div>
<label class="block font-semibold mb-1">
メールアドレス
</label>
<input id="public_email"
       type="email"
       class="w-full border rounded-lg p-2">
</div>

<?php foreach (
    ($publicSurvey['groups'] ?? [])
    as $group
): ?>

<fieldset class="border-t pt-4">

<legend class="font-bold mb-3">
<?= survey_h($group['name'] ?? '') ?>
</legend>

<?php foreach (
    ($group['questions'] ?? [])
    as $question
): ?>

<div class="mb-5">

<label class="block font-semibold mb-2">
<?= survey_h($question['text'] ?? '') ?>
</label>

<?php if (
    ($question['type'] ?? '')
    === 'text'
): ?>

<textarea
    data-question="<?= survey_h($question['id'] ?? '') ?>"
    class="w-full border rounded-lg p-2"></textarea>

<?php else: ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<label class="block mb-1">

<input
    data-question="<?= survey_h($question['id'] ?? '') ?>"
    value="<?= survey_h($option) ?>"
    type="<?=
        ($question['type'] ?? '')
        === 'multiple'
            ? 'checkbox'
            : 'radio'
    ?>">

<?= survey_h($option) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</fieldset>

<?php endforeach; ?>

<button
    type="button"
    onclick="AppPublic.submit()"
    class="bg-indigo-600 text-white px-5 py-3 rounded-lg">
回答を送信
</button>

</form>
</section>
</main>

<script>
const AppPublic = {

    submit() {

        const answers = {};

        document
            .querySelectorAll('[data-question]')
            .forEach(function(el) {

                const id =
                    el.dataset.question;

                if (el.type === 'checkbox') {

                    if (!answers[id]) {
                        answers[id] = [];
                    }

                    if (el.checked) {
                        answers[id].push(
                            el.value
                        );
                    }

                } else if (
                    el.type === 'radio'
                ) {

                    if (el.checked) {
                        answers[id] =
                            el.value;
                    }

                } else {

                    answers[id] =
                        el.value;
                }
            });

        const response = {
            id:
                crypto.randomUUID
                    ? crypto.randomUUID()
                    : String(Date.now()),

            survey_id:
                <?= survey_json($surveyId) ?>,

            customer_id: '',

            company:
                document
                    .getElementById(
                        'public_company'
                    ).value,

            name:
                document
                    .getElementById(
                        'public_name'
                    ).value,

            email:
                document
                    .getElementById(
                        'public_email'
                    ).value,

            answered_at: '',

            answers: answers
        };

        document
            .getElementById(
                'public_response_json'
            )
            .value =
            JSON.stringify(response);

        document
            .getElementById(
                'public_form'
            )
            .submit();
    }
};
</script>

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

<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

</head>

<body class="bg-slate-100 text-slate-800">

<div id="app"></div>

<script>

window.App = {

state: {

    data: null,

    page: 'list',

    editing: null,

    selectedSurvey: null,

    fields: [],

    keyword: '',

    status_filter: 'all',

    sort: 'updated_desc',

    csrf_token:
        <?= survey_json($csrf) ?>
},

api: {

    async request(
        action,
        values = {}
    ) {

        const body =
            new URLSearchParams();

        body.set(
            'action',
            action
        );

        body.set(
            'csrf_token',
            App.state.csrf_token
        );

        Object.keys(values)
            .forEach(function(key) {

                const value =
                    values[key];

                body.set(
                    key,
                    typeof value === 'string'
                        ? value
                        : JSON.stringify(value)
                );
            });

        const response =
            await fetch(
                location.pathname,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body:
                        body.toString()
                }
            );

        const json =
            await response.json();

        if (!json.ok) {

            const d =
                json.diagnostic || {};

            let message =
                json.message || 'エラー';

            if (d.error) {
                message +=
                    '\n' + d.error;
            }

            if (d.url) {
                message +=
                    '\n接続先: ' + d.url;
            }

            if (
                typeof d.status !==
                'undefined'
            ) {
                message +=
                    '\nHTTPステータス: '
                    + d.status;
            }

            throw new Error(message);
        }

        return json;
    },

    async load() {

        const response =
            await fetch(
                location.pathname
                + '?action=load'
            );

        const json =
            await response.json();

        if (!json.ok) {
            throw new Error(
                json.message
            );
        }

        App.state.data =
            json.data;
    }
},

actions: {

    async init() {

        try {

            await App.api.load();

            App.render.list();

        } catch (error) {

            App.render.error(
                error.message
            );
        }
    },

    newSurvey() {

        App.state.editing =
            App.helpers.emptySurvey();

        App.state.page =
            'edit';

        App.render.edit();
    },

    editSurvey(id) {

        const survey =
            App.state.data.surveys
                .find(
                    item =>
                        item.id === id
                );

        if (!survey) return;

        App.state.editing =
            structuredClone(survey);

        App.state.page =
            'edit';

        App.render.edit();
    },

    async saveSurvey() {

        const survey =
            App.helpers.readSurveyForm();

        App.helpers.renumber(
            survey
        );

        App.state.editing =
            survey;

        try {

            const result =
                await App.api.request(
                    'save_survey',
                    {
                        survey_json:
                            survey
                    }
                );

            App.state.data =
                result.data;

            App.state.page =
                'list';

            App.state.editing =
                null;

            App.render.list();

            alert(
                '保存しました。'
            );

        } catch (error) {

            alert(
                error.message
            );
        }
    },

    cancelEdit() {

        if (
            !confirm(
                '変更を破棄して一覧へ戻りますか？'
            )
        ) {
            return;
        }

        App.state.page =
            'list';

        App.state.editing =
            null;

        App.render.list();
    },

    async duplicateSurvey(id) {

        try {

            const result =
                await App.api.request(
                    'duplicate_survey',
                    {
                        survey_id: id
                    }
                );

            App.state.data =
                result.data;

            App.render.list();

        } catch (error) {

            alert(
                error.message
            );
        }
    },

    async deleteSurvey(id) {

        if (
            !confirm(
                'このアンケートを削除しますか？'
            )
        ) {
            return;
        }

        try {

            const result =
                await App.api.request(
                    'delete_survey',
                    {
                        survey_id: id
                    }
                );

            App.state.data =
                result.data;

            App.render.list();

        } catch (error) {

            alert(
                error.message
            );
        }
    },

    toggleStatus(id) {

        const survey =
            App.state.data.surveys
                .find(
                    item =>
                        item.id === id
                );

        if (!survey) return;

        survey.status =
            survey.status === 'active'
                ? 'ended'
                : 'active';

        App.api.request(
            'save_survey',
            {
                survey_json:
                    survey
            }
        )
        .then(result => {

            App.state.data =
                result.data;

            App.render.list();
        })
        .catch(error => {
            alert(
                error.message
            );
        });
    },

    filterList() {

        const keyword =
            document
                .getElementById(
                    'list_keyword'
                )?.value || '';

        const status =
            document
                .getElementById(
                    'list_status'
                )?.value || 'all';

        const sort =
            document
                .getElementById(
                    'list_sort'
                )?.value
                || 'updated_desc';

        App.state.keyword =
            keyword;

        App.state.status_filter =
            status;

        App.state.sort =
            sort;

        App.render.list();
    },

    addGroup() {

        const survey =
            App.helpers.readSurveyForm();

        survey.groups.push({
            id:
                App.helpers.uuid(),
            name:
                '新しいグループ',
            questions: []
        });

        App.helpers.renumber(
            survey
        );

        App.state.editing =
            survey;

        App.render.edit();
    },

    removeGroup(id) {

        const survey =
            App.helpers.readSurveyForm();

        if (
            survey.groups.length <= 1
        ) {
            alert(
                '最後のグループは削除できません。'
            );
            return;
        }

        survey.groups =
            survey.groups.filter(
                group =>
                    group.id !== id
            );

        App.helpers.renumber(
            survey
        );

        App.state.editing =
            survey;

        App.render.edit();
    },

    addQuestion(groupId) {

        const survey =
            App.helpers.readSurveyForm();

        const group =
            survey.groups.find(
                item =>
                    item.id === groupId
            );

        if (!group) return;

        group.questions.push({

            id:
                App.helpers.uuid(),

            text:
                '新しい質問',

            type:
                'single',

            required:
                false,

            options:
                ['選択肢1', '選択肢2'],

            other_enabled:
                false
        });

        App.helpers.renumber(
            survey
        );

        App.state.editing =
            survey;

        App.render.edit();
    },

    removeQuestion(
        groupId,
        questionId
    ) {

        const survey =
            App.helpers.readSurveyForm();

        const group =
            survey.groups.find(
                item =>
                    item.id === groupId
            );

        if (!group) return;

        group.questions =
            group.questions.filter(
                q =>
                    q.id !== questionId
            );

        App.helpers.renumber(
            survey
        );

        App.state.editing =
            survey;

        App.render.edit();
    },

    changeType(
        groupId,
        questionId,
        type
    ) {

        const survey =
            App.helpers.readSurveyForm();

        const q =
            App.helpers.findQuestion(
                survey,
                groupId,
                questionId
            );

        if (!q) return;

        q.type = type;

        if (
            type !== 'text' &&
            !Array.isArray(q.options)
        ) {
            q.options =
                ['選択肢1', '選択肢2'];
        }

        App.state.editing =
            survey;

        App.render.edit();
    },

    addOption(
        groupId,
        questionId
    ) {

        const survey =
            App.helpers.readSurveyForm();

        const q =
            App.helpers.findQuestion(
                survey,
                groupId,
                questionId
            );

        if (!q) return;

        if (!Array.isArray(q.options)) {
            q.options = [];
        }

        q.options.push(
            '選択肢'
            + (q.options.length + 1)
        );

        App.state.editing =
            survey;

        App.render.edit();
    },

    removeOption(
        groupId,
        questionId,
        index
    ) {

        const survey =
            App.helpers.readSurveyForm();

        const q =
            App.helpers.findQuestion(
                survey,
                groupId,
                questionId
            );

        if (!q) return;

        q.options.splice(
            index,
            1
        );

        App.state.editing =
            survey;

        App.render.edit();
    },

    openPreview() {

        const survey =
            App.helpers.readSurveyForm();

        App.helpers.renumber(
            survey
        );

        document
            .getElementById(
                'preview_content'
            )
            .innerHTML =
            App.helpers.previewHtml(
                survey
            );

        document
            .getElementById(
                'preview_modal'
            )
            .classList
            .remove('hidden');
    },

    closeModal(id) {

        document
            .getElementById(id)
            ?.classList
            .add('hidden');
    },

    async fetchKintoneFields() {

        try {

            const result =
                await App.api.request(
                    'kintone_fields'
                );

            App.state.fields =
                Object.values(
                    result.fields || {}
                );

            App.render.settings();

            document
                .getElementById(
                    'field_message'
                )
                .textContent =
                '項目一覧を取得しました。';

        } catch (error) {

            const node =
                document
                    .getElementById(
                        'field_message'
                    );

            if (node) {
                node.textContent =
                    error.message;
            }

            alert(
                error.message
            );
        }
    },

    async testKintone() {

        try {

            const result =
                await App.api.request(
                    'kintone_test'
                );

            alert(
                result.message
            );

        } catch (error) {

            alert(
                error.message
            );
        }
    },

    async saveSettings() {

        const settings =
            App.helpers.readSettingsForm();

        try {

            const result =
                await App.api.request(
                    'save_settings',
                    {
                        settings_json:
                            settings
                    }
                );

            App.state.data =
                result.data;

            alert(
                '設定を保存しました。'
            );

        } catch (error) {

            alert(
                error.message
            );
        }
    },

    openResponses(id) {

        App.state.selectedSurvey =
            id;

        App.state.page =
            'responses';

        App.render.responses();
    },

    openSettings() {

        App.state.page =
            'settings';

        App.render.settings();
    },

    home() {

        App.state.page =
            'list';

        App.render.list();
    },

    reorderQuestions() {

        const survey =
            App.helpers.readSurveyForm();

        App.helpers.renumber(
            survey
        );

        App.state.editing =
            survey;

        App.render.edit();
    },

    toggleQuestion(id) {

        document
            .getElementById(id)
            ?.classList
            .toggle('hidden');
    },

    openResponse(id) {

        const response =
            App.state.data.responses
                .find(
                    item =>
                        item.id === id
                );

        if (!response) return;

        document
            .getElementById(
                'response_detail'
            )
            .innerHTML =
            App.helpers.responseHtml(
                response
            );

        document
            .getElementById(
                'response_modal'
            )
            .classList
            .remove('hidden');
    }
},

helpers: {

    uuid() {

        return crypto.randomUUID
            ? crypto.randomUUID()
            : Date.now().toString(36)
              + Math.random()
                .toString(36)
                .slice(2);
    },

    emptySurvey() {

        return {

            id:
                App.helpers.uuid(),

            title:
                '新しいアンケート',

            start_at: '',
            end_at: '',

            status:
                'draft',

            created_at:
                new Date()
                    .toISOString(),

            updated_at:
                new Date()
                    .toISOString(),

            numbering_mode:
                'global',

            groups: [{
                id:
                    App.helpers.uuid(),

                name:
                    '基本情報',

                questions: []
            }],

            deleted:
                false
        };
    },

    esc(value) {

        return String(
            value ?? ''
        ).replace(
            /[&<>"']/g,
            function(c) {
                return {
                    '&':
                        '&amp;',
                    '<':
                        '&lt;',
                    '>':
                        '&gt;',
                    '"':
                        '&quot;',
                    "'":
                        '&#039;'
                }[c];
            }
        );
    },

    findQuestion(
        survey,
        groupId,
        questionId
    ) {

        const group =
            survey.groups.find(
                g =>
                    g.id === groupId
            );

        if (!group) return null;

        return group.questions.find(
            q =>
                q.id === questionId
        ) || null;
    },

    /*
     * ★重要
     *
     * 質問番号の附番をここで一元管理。
     *
     * global:
     *   Q1 Q2 Q3 Q4 ...
     *
     * group:
     *   Q1-1 Q1-2
     *   Q2-1 Q2-2
     *
     * 質問の追加・削除・移動後にも必ず呼ぶ。
     */
    renumber(survey) {

        let globalNo = 1;

        survey.groups.forEach(
            function(group, groupIndex) {

                group.questions.forEach(
                    function(question, questionIndex) {

                        if (
                            survey.numbering_mode
                            === 'group'
                        ) {
                            question.number =
                                'Q'
                                + (groupIndex + 1)
                                + '-'
                                + (questionIndex + 1);
                        } else {
                            question.number =
                                'Q'
                                + globalNo;

                            globalNo++;
                        }
                    }
                );
            }
        );

        return survey;
    },

    readSurveyForm() {

        const survey =
            structuredClone(
                App.state.editing
            );

        if (!survey) {
            return App.helpers.emptySurvey();
        }

        const title =
            document.getElementById(
                'survey_title'
            );

        if (title) {
            survey.title =
                title.value;
        }

        survey.start_at =
            document.getElementById(
                'survey_start_at'
            )?.value || '';

        survey.end_at =
            document.getElementById(
                'survey_end_at'
            )?.value || '';

        survey.numbering_mode =
            document.getElementById(
                'survey_numbering_mode'
            )?.value || 'global';

        document
            .querySelectorAll(
                '[data-group-name]'
            )
            .forEach(function(node) {

                const group =
                    survey.groups.find(
                        g =>
                            g.id
                            === node.dataset.groupName
                    );

                if (group) {
                    group.name =
                        node.value;
                }
            });

        document
            .querySelectorAll(
                '[data-question-text]'
            )
            .forEach(function(node) {

                const q =
                    App.helpers.findQuestion(
                        survey,
                        node.dataset.groupId,
                        node.dataset.questionId
                    );

                if (q) {
                    q.text =
                        node.value;
                }
            });

        document
            .querySelectorAll(
                '[data-required]'
            )
            .forEach(function(node) {

                const q =
                    App.helpers.findQuestion(
                        survey,
                        node.dataset.groupId,
                        node.dataset.questionId
                    );

                if (q) {
                    q.required =
                        node.checked;
                }
            });

        document
            .querySelectorAll(
                '[data-option]'
            )
            .forEach(function(node) {

                const q =
                    App.helpers.findQuestion(
                        survey,
                        node.dataset.groupId,
                        node.dataset.questionId
                    );

                if (q) {

                    const index =
                        Number(
                            node.dataset.index
                        );

                    q.options[index] =
                        node.value;
                }
            });

        return survey;
    },

    readSettingsForm() {

        const old =
            App.state.data.settings
            || {};

        const get =
            id =>
                document
                    .getElementById(id);

        return {

            ...old,

            subdomain:
                get(
                    'setting_subdomain'
                )?.value || '',

            app_id:
                get(
                    'setting_app_id'
                )?.value || '',

            login_name:
                get(
                    'setting_login_name'
                )?.value || '',

            password:
                get(
                    'setting_password'
                )?.value
                || old.password
                || '',

            proxy:
                get(
                    'setting_proxy'
                )?.value || '',

            ssl_verify:
                get(
                    'setting_ssl_verify'
                )?.checked ?? true,

            field_company:
                get(
                    'field_company'
                )?.value || '',

            field_name:
                get(
                    'field_name'
                )?.value || '',

            field_email:
                get(
                    'field_email'
                )?.value || '',

            field_department:
                get(
                    'field_department'
                )?.value || '',

            field_phone:
                get(
                    'field_phone'
                )?.value || '',

            field_address:
                Array.from(
                    document.querySelectorAll(
                        '[data-address-field]:checked'
                    )
                ).map(
                    node =>
                        node.value
                )
        };
    },

    fieldOptions(
        selected = ''
    ) {

        const fields =
            App.state.fields || [];

        let html =
            '<option value="">-- 選択 --</option>';

        fields.forEach(
            function(field) {

                const code =
                    field.code || '';

                const label =
                    field.label
                    || code;

                html +=
                    '<option value="'
                    + App.helpers.esc(code)
                    + '" '
                    + (
                        code === selected
                            ? 'selected'
                            : ''
                    )
                    + '>'
                    + App.helpers.esc(label)
                    + '（'
                    + App.helpers.esc(code)
                    + '）'
                    + '</option>';
            }
        );

        return html;
    },

    previewHtml(survey) {

        let html = '';

        survey.groups.forEach(
            function(group) {

                html +=
                    '<section class="mb-6">'
                    + '<h2 class="font-bold text-lg mb-3">'
                    + App.helpers.esc(
                        group.name
                    )
                    + '</h2>';

                group.questions.forEach(
                    function(q) {

                        html +=
                            '<div class="mb-5">'
                            + '<div class="font-semibold mb-2">'
                            + App.helpers.esc(
                                q.number
                            )
                            + ' '
                            + App.helpers.esc(
                                q.text
                            )
                            + '</div>';

                        if (
                            q.type === 'text'
                        ) {

                            html +=
                                '<textarea class="w-full border rounded p-2"></textarea>';

                        } else {

                            q.options
                                .forEach(
                                    function(o) {

                                        html +=
                                            '<label class="block mb-1">'
                                            + '<input type="'
                                            + (
                                                q.type
                                                === 'multiple'
                                                    ? 'checkbox'
                                                    : 'radio'
                                            )
                                            + '"> '
                                            + App.helpers.esc(o)
                                            + '</label>';
                                    }
                                );
                        }

                        html +=
                            '</div>';
                    }
                );

                html +=
                    '</section>';
            }
        );

        return html;
    },

    responseHtml(response) {

        return `
<div class="space-y-3">
<div>
<strong>会社名:</strong>
${App.helpers.esc(response.company)}
</div>
<div>
<strong>氏名:</strong>
${App.helpers.esc(response.name)}
</div>
<div>
<strong>メール:</strong>
${App.helpers.esc(response.email)}
</div>
<div>
<strong>回答日時:</strong>
${App.helpers.esc(response.answered_at)}
</div>
<pre class="bg-slate-50 p-4 rounded-xl overflow-auto">${App.helpers.esc(
    JSON.stringify(
        response.answers || {},
        null,
        2
    )
)}</pre>
</div>`;
    }
},

render: {

    shell(content) {

        document
            .getElementById('app')
            .innerHTML = `
<header class="bg-white border-b">
<div class="max-w-7xl mx-auto p-4 flex justify-between items-center">
<div class="font-bold text-lg">
アンケート管理システム
</div>
<nav class="flex gap-2">
<button onclick="App.actions.home()"
class="px-3 py-2 rounded-lg hover:bg-slate-100">
アンケート一覧
</button>
<button onclick="App.actions.openSettings()"
class="px-3 py-2 rounded-lg hover:bg-slate-100">
キントーン連携設定
</button>
</nav>
</div>
</header>

<main class="max-w-7xl mx-auto p-6">
${content}
</main>

<div id="preview_modal"
class="hidden fixed inset-0 bg-black/50 z-50 p-6">
<div class="bg-white rounded-2xl max-w-3xl mx-auto p-6 max-h-[90vh] overflow-auto">
<div class="flex justify-between mb-5">
<h2 class="font-bold text-xl">プレビュー</h2>
<button onclick="App.actions.closeModal('preview_modal')">閉じる</button>
</div>
<div id="preview_content"></div>
</div>
</div>

<div id="response_modal"
class="hidden fixed inset-0 bg-black/50 z-50 p-6">
<div class="bg-white rounded-2xl max-w-3xl mx-auto p-6 max-h-[90vh] overflow-auto">
<div class="flex justify-between mb-5">
<h2 class="font-bold text-xl">回答詳細</h2>
<button onclick="App.actions.closeModal('response_modal')">閉じる</button>
</div>
<div id="response_detail"></div>
</div>
</div>`;
    },

    error(message) {

        App.render.shell(`
<div class="bg-white rounded-2xl shadow p-8">
<h1 class="text-xl font-bold mb-4 text-rose-600">
エラー
</h1>
<pre class="whitespace-pre-wrap">${App.helpers.esc(message)}</pre>
</div>`);
    },

    list() {

        const state =
            App.state;

        let surveys =
            (
                state.data?.surveys
                || []
            ).filter(
                survey =>
                    !survey.deleted
            );

        surveys =
            surveys.filter(
                survey => {

                    const keyword =
                        state.keyword
                            .trim()
                            .toLowerCase();

                    const title =
                        String(
                            survey.title
                            || ''
                        ).toLowerCase();

                    const statusOk =
                        state.status_filter
                        === 'all'
                        ||
                        survey.status
                        === state.status_filter;

                    return (
                        (!keyword
                         || title.includes(
                             keyword
                         ))
                        && statusOk
                    );
                }
            );

        surveys.sort(
            function(a, b) {

                if (
                    state.sort
                    === 'responses_desc'
                    ||
                    state.sort
                    === 'responses_asc'
                ) {

                    const ac =
                        state.data.responses
                            .filter(
                                r =>
                                    r.survey_id
                                    === a.id
                            ).length;

                    const bc =
                        state.data.responses
                            .filter(
                                r =>
                                    r.survey_id
                                    === b.id
                            ).length;

                    return state.sort
                        === 'responses_desc'
                        ? bc - ac
                        : ac - bc;
                }

                const av =
                    new Date(
                        a.updated_at || 0
                    ).getTime();

                const bv =
                    new Date(
                        b.updated_at || 0
                    ).getTime();

                return state.sort
                    === 'updated_desc'
                    ? bv - av
                    : av - bv;
            }
        );

        const rows =
            surveys.map(
                function(survey) {

                    const count =
                        state.data.responses
                            .filter(
                                r =>
                                    r.survey_id
                                    === survey.id
                            ).length;

                    const statusLabel = {
                        active: '公開中',
                        draft: '下書き',
                        ended: '終了'
                    }[
                        survey.status
                    ] || survey.status;

                    return `
<tr class="border-t">
<td class="p-3">
${App.helpers.esc(
    survey.updated_at || ''
)}
</td>

<td class="p-3 font-bold">
${App.helpers.esc(
    survey.title
)}
</td>

<td class="p-3">
${App.helpers.esc(
    survey.start_at || '未設定'
)}
～
${App.helpers.esc(
    survey.end_at || '未設定'
)}
</td>

<td class="p-3">
<span class="px-2 py-1 rounded bg-slate-100">
${statusLabel}
</span>
</td>

<td class="p-3">
${count} 件
</td>

<td class="p-3">
<div class="flex flex-wrap gap-2">

<button
onclick="App.actions.editSurvey('${App.helpers.esc(survey.id)}')"
class="border px-2 py-1 rounded">
確認・編集
</button>

${
    survey.status !== 'draft'
    ? `
<button
onclick="App.actions.openResponses('${App.helpers.esc(survey.id)}')"
class="border px-2 py-1 rounded">
集計
</button>`
    : ''
}

<button
onclick="App.actions.duplicateSurvey('${App.helpers.esc(survey.id)}')"
class="border px-2 py-1 rounded">
複製
</button>

${
    survey.status === 'draft'
    ? `
<button
onclick="App.actions.deleteSurvey('${App.helpers.esc(survey.id)}')"
class="text-rose-600 border border-rose-200 px-2 py-1 rounded">
削除
</button>`
    : ''
}

${
    survey.status === 'active'
    ? `
<button
onclick="App.actions.toggleStatus('${App.helpers.esc(survey.id)}')"
class="border px-2 py-1 rounded">
停止
</button>`
    : ''
}

</div>
</td>
</tr>`;
                }
            ).join('');

        App.render.shell(`

<div class="flex justify-between items-center mb-6">

<h1 class="text-2xl font-bold">
アンケート一覧
</h1>

<button
onclick="App.actions.newSurvey()"
class="bg-indigo-600 text-white px-4 py-2 rounded-lg">
＋ 新規アンケート作成
</button>

</div>

<div class="bg-white rounded-2xl shadow p-4 mb-5 flex flex-wrap gap-3">

<input
id="list_keyword"
value="${App.helpers.esc(state.keyword)}"
onkeydown="if(event.key==='Enter')App.actions.filterList()"
placeholder="タイトル検索"
class="border rounded-lg p-2">

<select
id="list_status"
onchange="App.actions.filterList()"
class="border rounded-lg p-2">

<option value="all">すべて</option>
<option value="active" ${state.status_filter === 'active' ? 'selected' : ''}>公開中</option>
<option value="draft" ${state.status_filter === 'draft' ? 'selected' : ''}>下書き</option>
<option value="ended" ${state.status_filter === 'ended' ? 'selected' : ''}>終了</option>

</select>

<select
id="list_sort"
onchange="App.actions.filterList()"
class="border rounded-lg p-2">

<option value="updated_desc">更新日（新しい順）</option>
<option value="updated_asc">更新日（古い順）</option>
<option value="responses_desc">回答数（多い順）</option>
<option value="responses_asc">回答数（少ない順）</option>

</select>

</div>

<div class="bg-white rounded-2xl shadow overflow-auto">

<table class="w-full min-w-[1000px]">

<thead class="bg-slate-50">

<tr>
<th class="p-3 text-left">更新日</th>
<th class="p-3 text-left">タイトル</th>
<th class="p-3 text-left">期間</th>
<th class="p-3 text-left">ステータス</th>
<th class="p-3 text-left">回答数</th>
<th class="p-3 text-left">操作</th>
</tr>

</thead>

<tbody>

${
    rows ||
    `<tr>
    <td colspan="6"
    class="p-8 text-center text-slate-500">
    アンケートがありません。
    </td>
    </tr>`
}

</tbody>
</table>
</div>`);
    },

    edit() {

        const survey =
            App.state.editing;

        App.helpers.renumber(
            survey
        );

        const groups =
            survey.groups.map(
                function(group) {

                    const questions =
                        group.questions.map(
                            function(q) {

                                const options =
                                    q.type === 'text'
                                    ? ''
                                    : q.options.map(
                                        function(
                                            option,
                                            index
                                        ) {
                                            return `
<div class="flex gap-2 mb-2">
<input
data-option
data-group-id="${App.helpers.esc(group.id)}"
data-question-id="${App.helpers.esc(q.id)}"
data-index="${index}"
value="${App.helpers.esc(option)}"
class="border rounded p-2 flex-1">

<button
onclick="App.actions.removeOption('${App.helpers.esc(group.id)}','${App.helpers.esc(q.id)}',${index})"
class="text-rose-600">
削除
</button>
</div>`;
                                        }
                                    ).join('');

                                return `
<div
class="question-item border rounded-xl p-4 mb-3 bg-slate-50"
data-question-id="${App.helpers.esc(q.id)}">

<div class="flex gap-3 items-start">

<div class="drag-question text-slate-400 text-xl cursor-move">
⠿
</div>

<div class="flex-1">

<div class="flex gap-2 mb-2 items-center">

<span class="font-bold w-16">
${App.helpers.esc(q.number)}
</span>

<input
data-question-text
data-group-id="${App.helpers.esc(group.id)}"
data-question-id="${App.helpers.esc(q.id)}"
value="${App.helpers.esc(q.text)}"
class="border rounded p-2 flex-1">

</div>

<div class="flex gap-3 items-center mb-3">

<select
onchange="App.actions.changeType('${App.helpers.esc(group.id)}','${App.helpers.esc(q.id)}',this.value)"
class="border rounded p-2">

<option value="single" ${q.type === 'single' ? 'selected' : ''}>
単一選択
</option>

<option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>
複数選択
</option>

<option value="text" ${q.type === 'text' ? 'selected' : ''}>
自由記述
</option>

</select>

<label>
<input
data-required
data-group-id="${App.helpers.esc(group.id)}"
data-question-id="${App.helpers.esc(q.id)}"
type="checkbox"
${q.required ? 'checked' : ''}>
必須
</label>

<button
onclick="App.actions.removeQuestion('${App.helpers.esc(group.id)}','${App.helpers.esc(q.id)}')"
class="text-rose-600 ml-auto">
質問削除
</button>

</div>

${options}

${
    q.type !== 'text'
    ? `
<button
onclick="App.actions.addOption('${App.helpers.esc(group.id)}','${App.helpers.esc(q.id)}')"
class="text-indigo-600 text-sm">
＋ 選択肢追加
</button>`
    : ''
}

</div>
</div>
</div>`;
                            }
                        ).join('');

                    return `
<section
class="group-item border rounded-2xl p-4 mb-5 bg-white"
data-group-id="${App.helpers.esc(group.id)}">

<div class="flex gap-3 items-center mb-4">

<div class="drag-group text-slate-400 text-xl cursor-move">
⠿
</div>

<input
data-group-name="${App.helpers.esc(group.id)}"
value="${App.helpers.esc(group.name)}"
class="border rounded p-2 font-bold flex-1">

<button
onclick="App.actions.removeGroup('${App.helpers.esc(group.id)}')"
class="text-rose-600">
グループ削除
</button>

</div>

<div
class="question-list"
data-question-list="${App.helpers.esc(group.id)}">

${questions}

</div>

<button
onclick="App.actions.addQuestion('${App.helpers.esc(group.id)}')"
class="text-indigo-600">
＋ 質問追加
</button>

</section>`;
                }
            ).join('');

        App.render.shell(`

<div class="flex justify-between items-center mb-6">

<h1 class="text-2xl font-bold">
アンケート作成・編集
</h1>

<div class="flex gap-2">

<button
onclick="App.actions.openPreview()"
class="border px-4 py-2 rounded-lg">
プレビュー
</button>

<button
onclick="App.actions.saveSurvey()"
class="bg-indigo-600 text-white px-4 py-2 rounded-lg">
保存して一覧へ戻る
</button>

<button
onclick="App.actions.cancelEdit()"
class="border px-4 py-2 rounded-lg">
キャンセル
</button>

</div>
</div>

<div class="bg-white rounded-2xl shadow p-5 mb-5 grid md:grid-cols-4 gap-3">

<input
id="survey_title"
value="${App.helpers.esc(survey.title)}"
class="border rounded-lg p-2 md:col-span-2"
placeholder="タイトル">

<input
id="survey_start_at"
value="${App.helpers.esc(survey.start_at)}"
type="datetime-local"
class="border rounded-lg p-2">

<input
id="survey_end_at"
value="${App.helpers.esc(survey.end_at)}"
type="datetime-local"
class="border rounded-lg p-2">

<select
id="survey_numbering_mode"
class="border rounded-lg p-2">

<option
value="global"
${survey.numbering_mode === 'global' ? 'selected' : ''}>
質問番号：全体
</option>

<option
value="group"
${survey.numbering_mode === 'group' ? 'selected' : ''}>
質問番号：グループ別
</option>

</select>

</div>

<div
id="question_editor">

${groups}

</div>

<button
onclick="App.actions.addGroup()"
class="bg-slate-800 text-white px-4 py-2 rounded-lg">
＋ グループ追加
</button>
`);

        App.render.initSortables();
    },

    initSortables() {

        document
            .querySelectorAll(
                '[data-question-list]'
            )
            .forEach(function(node) {

                new Sortable(
                    node,
                    {
                        group:
                            'survey_questions',

                        animation:
                            150,

                        handle:
                            '.drag-question',

                        ghostClass:
                            'opacity-50',

                        onEnd:
                            function() {
                                App.actions
                                    .reorderQuestions();
                            }
                    }
                );
            });

        const editor =
            document
                .getElementById(
                    'question_editor'
                );

        if (editor) {

            new Sortable(
                editor,
                {
                    animation: 150,

                    handle:
                        '.drag-group',

                    ghostClass:
                        'opacity-50',

                    onEnd:
                        function() {
                            App.actions
                                .reorderQuestions();
                        }
                }
            );
        }
    },

    responses() {

        const survey =
            App.state.data.surveys
                .find(
                    s =>
                        s.id
                        === App.state.selectedSurvey
                );

        if (!survey) {
            App.render.list();
            return;
        }

        const responses =
            App.state.data.responses
                .filter(
                    r =>
                        r.survey_id
                        === survey.id
                );

        const questions = [];

        survey.groups.forEach(
            group =>
                group.questions.forEach(
                    q =>
                        questions.push(q)
                )
        );

        const questionChecks =
            questions.map(
                function(q) {

                    return `
<label class="block mb-2">
<input
type="checkbox"
checked
onchange="App.actions.toggleQuestion('${App.helpers.esc(q.id)}')">
${App.helpers.esc(q.number)}
${App.helpers.esc(q.text)}
</label>`;
                }
            ).join('');

        const rows =
            responses.map(
                function(r) {

                    return `
<tr class="border-t">

<td class="p-3">
${App.helpers.esc(r.company)}
</td>

<td class="p-3">
${App.helpers.esc(r.name)}
</td>

<td class="p-3">
${App.helpers.esc(r.answered_at)}
</td>

<td class="p-3">
<button
onclick="App.actions.openResponse('${App.helpers.esc(r.id)}')"
class="text-indigo-600">
全回答を表示
</button>
</td>

</tr>`;
                }
            ).join('');

        App.render.shell(`

<div class="flex justify-between items-center mb-5">

<h1 class="text-2xl font-bold">
集計：
${App.helpers.esc(survey.title)}
</h1>

<button
onclick="location.href='?action=csv&survey_id=${encodeURIComponent(survey.id)}'"
class="border px-4 py-2 rounded-lg">
CSV出力
</button>

</div>

<div class="grid md:grid-cols-4 gap-4 mb-5">

<div class="bg-white rounded-xl shadow p-4">
<div class="text-slate-500">回答数</div>
<div class="text-2xl font-bold">
${responses.length}
</div>
</div>

<div class="bg-white rounded-xl shadow p-4">
<div class="text-slate-500">未登録顧客からの回答</div>
<div class="text-2xl font-bold">0</div>
</div>

<div class="bg-white rounded-xl shadow p-4">
<div class="text-slate-500">送信対象者数</div>
<div class="text-2xl font-bold">0</div>
</div>

<div class="bg-white rounded-xl shadow p-4">
<div class="text-slate-500">回答率</div>
<div class="text-2xl font-bold">-</div>
</div>

</div>

<div class="bg-white rounded-2xl shadow p-5 mb-5">

<h2 class="font-bold mb-4">
設問絞り込み
</h2>

${questionChecks}

</div>

<div class="bg-white rounded-2xl shadow overflow-auto">

<table
id="response_table"
class="w-full min-w-[700px]">

<thead class="bg-slate-50">

<tr>
<th class="p-3 text-left">会社名</th>
<th class="p-3 text-left">氏名</th>
<th class="p-3 text-left">回答日時</th>
<th class="p-3 text-left">操作</th>
</tr>

</thead>

<tbody>
${rows || `
<tr>
<td colspan="4"
class="p-8 text-center text-slate-500">
現在、回答データはありません
</td>
</tr>`}
</tbody>

</table>

</div>
`);
    },

    settings() {

        const settings =
            App.state.data.settings
            || {};

        const field =
            id =>
                App.helpers.fieldOptions(
                    settings[id] || ''
                );

        App.render.shell(`

<h1 class="text-2xl font-bold mb-5">
キントーン連携設定
</h1>

<div
id="settings_form"
class="bg-white rounded-2xl shadow p-6">

<div class="grid md:grid-cols-2 gap-4">

<label>
<span class="block font-semibold mb-1">
サブドメイン
</span>

<input
id="setting_subdomain"
value="${App.helpers.esc(settings.subdomain || '')}"
placeholder="xxxx.cybozu.com"
class="w-full border rounded-lg p-2">
</label>

<label>
<span class="block font-semibold mb-1">
アプリID
</span>

<input
id="setting_app_id"
value="${App.helpers.esc(settings.app_id || '')}"
class="w-full border rounded-lg p-2">
</label>

<label>
<span class="block font-semibold mb-1">
ログイン名
</span>

<input
id="setting_login_name"
value="${App.helpers.esc(settings.login_name || '')}"
class="w-full border rounded-lg p-2">
</label>

<label>
<span class="block font-semibold mb-1">
パスワード
</span>

<input
id="setting_password"
type="password"
value=""
class="w-full border rounded-lg p-2">
</label>

<label class="md:col-span-2">

<span class="block font-semibold mb-1">
Proxy
</span>

<input
id="setting_proxy"
value="${App.helpers.esc(settings.proxy || '')}"
placeholder="host:port"
class="w-full border rounded-lg p-2">

</label>

<label class="flex gap-2 items-center">

<input
id="setting_ssl_verify"
type="checkbox"
${settings.ssl_verify !== false ? 'checked' : ''}>

SSL証明書を検証する

</label>

</div>

<hr class="my-6">

<h2 class="font-bold mb-4">
kintoneフィールドマッピング
</h2>

<div
id="field_message"
class="mb-3 text-sm text-slate-500">
</div>

<div class="grid md:grid-cols-2 gap-4">

<label>
会社名
<select
id="field_company"
class="w-full border rounded-lg p-2">
${field('field_company')}
</select>
</label>

<label>
氏名
<select
id="field_name"
class="w-full border rounded-lg p-2">
${field('field_name')}
</select>
</label>

<label>
メールアドレス
<select
id="field_email"
class="w-full border rounded-lg p-2">
${field('field_email')}
</select>
</label>

<label>
部署名
<select
id="field_department"
class="w-full border rounded-lg p-2">
${field('field_department')}
</select>
</label>

<label>
電話番号
<select
id="field_phone"
class="w-full border rounded-lg p-2">
${field('field_phone')}
</select>
</label>

<label>
住所
<select
id="field_address"
multiple
class="w-full border rounded-lg p-2">
${field('field_address')}
</select>
</label>

</div>

<div class="flex gap-3 mt-6">

<button
onclick="App.actions.saveSettings()"
class="bg-indigo-600 text-white px-4 py-2 rounded-lg">
設定保存
</button>

<button
onclick="App.actions.fetchKintoneFields()"
class="border px-4 py-2 rounded-lg">
項目一覧を再取得
</button>

<button
onclick="App.actions.testKintone()"
class="border px-4 py-2 rounded-lg">
接続確認
</button>

</div>

</div>
`);
    }
}

};

/* --------------------------------------------------------------------
 * 初期化
 * ------------------------------------------------------------------ */

if (
    document.readyState === 'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.actions.init();
        },
        {once: true}
    );

} else {

    App.actions.init();
}

</script>

</body>
</html>

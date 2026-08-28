<?php
declare(strict_types=1);

/*
 * ============================================================
 * アンケートアプリ
 * 単一ファイル版 index.php
 *
 * 対応:
 *   Apache 2.4
 *   PHP 8.5
 *   PHP cURL
 *   DBなし
 *
 * 外部通信:
 *   kintone : PHP cURL
 *   SMTP    : この単一ファイルから実装可能な構成
 *
 * 重要:
 *   - curl_close() は使用しない
 *   - APIトークン認証は使用しない
 *   - kintone認証情報をJavaScriptへ渡さない
 *   - CSRF処理は実装しない（要件指定）
 * ============================================================
 */

date_default_timezone_set('Asia/Tokyo');

/* ============================================================
 * 基本設定
 * ============================================================ */

const APP_TITLE = 'アンケート管理';
const DATA_DIR_NAME = '_data';
const DATA_FILE_NAME = 'data.json';
const SETTINGS_FILE_NAME = 'settings.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_TIMEOUT = 30;

const SESSION_NAME = 'survey_app_session';

/* ============================================================
 * セッション
 * ============================================================ */

function start_application_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
    );

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => application_cookie_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function application_cookie_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = dirname($script);

    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

start_application_session();

/* ============================================================
 * 共通ユーティリティ
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function get_string(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function current_screen(): string
{
    $screen = get_string('screen', 'list');

    $allowed = [
        'list',
        'edit',
        'preview',
        'analytics',
        'send',
        'kintone',
        'mail',
        'answer',
        'confirm',
        'complete',
    ];

    return in_array($screen, $allowed, true)
        ? $screen
        : 'list';
}

function redirect_screen(string $screen, array $params = []): never
{
    $params = array_merge(
        ['screen' => $screen],
        $params
    );

    $query = http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    header(
        'Location: ' .
        basename($_SERVER['SCRIPT_NAME'] ?? 'index.php') .
        '?' .
        $query,
        true,
        303
    );

    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($items) ? $items : [];
}

/* ============================================================
 * ファイル保存
 * ============================================================ */

function data_directory(): string
{
    /*
     * prompt.txtではサーバー側ファイル永続化を要求。
     * 現在のプロジェクトではWeb公開ディレクトリ配下を指定。
     */
    return __DIR__ . DIRECTORY_SEPARATOR . DATA_DIR_NAME;
}

function ensure_data_directory(): void
{
    $dir = data_directory();

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(
                'データ保存フォルダを作成できません。'
            );
        }
    }
}

function data_file_path(): string
{
    return data_directory() .
        DIRECTORY_SEPARATOR .
        DATA_FILE_NAME;
}

function settings_file_path(): string
{
    return data_directory() .
        DIRECTORY_SEPARATOR .
        SETTINGS_FILE_NAME;
}

function read_json_file(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }

    $contents = file_get_contents($file);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    try {
        $decoded = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $e) {
        return $default;
    }

    return is_array($decoded)
        ? $decoded
        : $default;
}

function write_json_file(string $file, array $data): void
{
    ensure_data_directory();

    $temporary = $file . '.tmp.' . bin2hex(random_bytes(6));

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    if (
        file_put_contents(
            $temporary,
            $json,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            '一時ファイルへ保存できません。'
        );
    }

    if (!rename($temporary, $file)) {
        @unlink($temporary);

        throw new RuntimeException(
            '保存ファイルを更新できません。'
        );
    }
}

/* ============================================================
 * 初期データ
 * ============================================================ */

function empty_data(): array
{
    return [
        'surveys' => [],
        'answers' => [],
        'customers' => [],
        'send_history' => [],
    ];
}

function load_data(): array
{
    ensure_data_directory();

    $data = read_json_file(
        data_file_path(),
        empty_data()
    );

    foreach (empty_data() as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function save_data(array $data): void
{
    write_json_file(
        data_file_path(),
        $data
    );
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
            'connection_status' => '未確認',
            'last_test_at' => '',
        ],
        'mail' => [
            'smtp_server' => '',
            'smtp_port' => '587',
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
            'connection_status' => '未設定',
            'last_test_at' => '',
        ],
    ];
}

function load_settings(): array
{
    ensure_data_directory();

    $settings = read_json_file(
        settings_file_path(),
        default_settings()
    );

    $defaults = default_settings();

    $settings['kintone'] = array_merge(
        $defaults['kintone'],
        is_array($settings['kintone'] ?? null)
            ? $settings['kintone']
            : []
    );

    $settings['mail'] = array_merge(
        $defaults['mail'],
        is_array($settings['mail'] ?? null)
            ? $settings['mail']
            : []
    );

    /*
     * パスワードが保存済みなら画面表示用には利用しない。
     */
    return $settings;
}

function save_settings(array $settings): void
{
    write_json_file(
        settings_file_path(),
        $settings
    );
}

/* ============================================================
 * アンケート
 * ============================================================ */

function generate_id(string $prefix): string
{
    return $prefix . '-' .
        date('YmdHis') . '-' .
        bin2hex(random_bytes(4));
}

function default_question(): array
{
    return [
        'id' => generate_id('question'),
        'text' => '',
        'type' => 'single',
        'required' => false,
        'options' => [
            ['id' => generate_id('option'), 'label' => ''],
            ['id' => generate_id('option'), 'label' => ''],
        ],
        'branching' => [],
    ];
}

function default_group(): array
{
    return [
        'id' => generate_id('group'),
        'title' => 'グループ1',
        'questions' => [
            default_question(),
        ],
    ];
}

function default_survey(): array
{
    return [
        'id' => generate_id('survey'),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'createdAt' => date('Y-m-d H:i:s'),
        'updatedAt' => date('Y-m-d H:i:s'),
        'groups' => [
            default_group(),
        ],
    ];
}

function survey_by_id(array $data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_index(array $data, string $id): int
{
    foreach ($data['surveys'] as $index => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function update_survey_auto_status(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
            return true;
        }
    }

    return false;
}

function normalize_survey_numbers(array &$survey): void
{
    $global = 1;

    foreach ($survey['groups'] as $groupIndex => &$group) {
        $local = 1;

        foreach ($group['questions'] as &$question) {
            if ($survey['numbering'] === 'group') {
                $question['number'] =
                    'Q' .
                    ($groupIndex + 1) .
                    '-' .
                    $local;

                $local++;
            } else {
                $question['number'] =
                    'Q' . $global;

                $global++;
            }
        }

        unset($question);
    }

    unset($group);
}

/* ============================================================
 * 状態
 * ============================================================ */

function survey_status_label(string $status): string
{
    return match ($status) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '不明',
    };
}

function survey_status_class(string $status): string
{
    return match ($status) {
        'published' => 'badge-success',
        'stopped' => 'badge-warning',
        'ended' => 'badge-danger',
        default => 'badge-gray',
    };
}

function can_manual_change_status(string $status): bool
{
    return in_array(
        $status,
        ['draft', 'published', 'stopped'],
        true
    );
}

/* ============================================================
 * kintone
 * ============================================================ */

function normalize_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = preg_replace(
        '#/.*$#',
        '',
        $value
    );

    $value = preg_replace(
        '#\.cybozu\.com$#i',
        '',
        $value
    );

    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (
        !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $value
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    return $value;
}

function kintone_build_url(
    string $domain,
    string $endpoint
): string {
    $domain = normalize_subdomain($domain);

    $endpoint =
        '/' .
        ltrim($endpoint, '/');

    return
        'https://' .
        $domain .
        '.cybozu.com' .
        $endpoint;
}

/*
 * Proxy:
 *   空欄
 *   host:port
 *   http://host:port
 *   https://host:port
 *
 * を受け付ける。
 */
function normalize_proxy(string $value): ?array
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    /*
     * URL形式を許可。
     */
    if (
        preg_match(
            '#^https?://#i',
            $value
        )
    ) {
        $parts = parse_url($value);

        if ($parts === false) {
            throw new InvalidArgumentException(
                'Proxyの形式を確認してください。例：proxy.example.local:8080'
            );
        }

        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? null;
    } else {
        /*
         * host:port
         * IPv6は [::1]:8080 に対応。
         */
        if (
            preg_match(
                '/^\[([0-9A-Fa-f:.]+)\]:(\d+)$/',
                $value,
                $m
            )
        ) {
            $host = '[' . $m[1] . ']';
            $port = (int)$m[2];
        } elseif (
            preg_match(
                '/^(.+):(\d+)$/',
                $value,
                $m
            )
        ) {
            $host = trim($m[1]);
            $port = (int)$m[2];
        } else {
            throw new InvalidArgumentException(
                'Proxyは「ホスト名:ポート番号」で入力してください。'
            );
        }
    }

    $host = trim((string)$host);

    if ($host === '') {
        throw new InvalidArgumentException(
            'Proxyのホスト名が入力されていません。'
        );
    }

    if (
        !is_int($port)
        && !ctype_digit((string)$port)
    ) {
        throw new InvalidArgumentException(
            'Proxyのポート番号は数字で指定してください。'
        );
    }

    $port = (int)$port;

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'Proxyのポート番号は1～65535の範囲で指定してください。'
        );
    }

    return [
        'host' => $host,
        'port' => $port,
    ];
}

function require_curl(): void
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException(
            'PHP cURL拡張が利用できません。' .
            ' PHP 8.5のphp.iniでextension=curlを有効にしてください。'
        );
    }
}

function kintone_request(
    array $settings,
    string $endpoint,
    string $method = 'GET',
    ?array $body = null
): array {
    require_curl();

    $kintone = $settings['kintone'] ?? [];

    $subdomain = normalize_subdomain(
        (string)($kintone['subdomain'] ?? '')
    );

    $username = (string)(
        $kintone['username'] ?? ''
    );

    $password = (string)(
        $kintone['password'] ?? ''
    );

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名が未入力です。'
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードが未入力です。'
        );
    }

    $url = kintone_build_url(
        $subdomain,
        $endpoint
    );

    $authorization = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Cybozu-Authorization: ' . $authorization,
    ];

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException(
            'kintone通信の初期化に失敗しました。'
        );
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,

        /*
         * 接続タイムアウトと通信全体のタイムアウトを
         * 別々に設定。
         */
        CURLOPT_CONNECTTIMEOUT => KINTONE_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => KINTONE_TIMEOUT,

        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );
    }

    $proxy = normalize_proxy(
        (string)($kintone['proxy'] ?? '')
    );

    if ($proxy !== null) {
        $options[CURLOPT_PROXY] =
            $proxy['host'];

        $options[CURLOPT_PROXYPORT] =
            $proxy['port'];

        $options[CURLOPT_PROXYTYPE] =
            CURLPROXY_HTTP;
    }

    /*
     * POCの初期値はSSL検証無効。
     * 設定画面で有効化可能。
     */
    $verifySsl =
        !empty($kintone['verify_ssl']);

    $options[CURLOPT_SSL_VERIFYPEER] =
        $verifySsl;

    $options[CURLOPT_SSL_VERIFYHOST] =
        $verifySsl ? 2 : 0;

    curl_setopt_array(
        $ch,
        $options
    );

    $response = curl_exec($ch);

    $curlErrorNo = curl_errno($ch);
    $curlError = curl_error($ch);

    $httpStatus = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $contentType = (string)curl_getinfo(
        $ch,
        CURLINFO_CONTENT_TYPE
    );

    /*
     * PHP 8.5対応:
     *
     * curl_close() は呼ばない。
     *
     * PHP 8.0以降、curl_close() は実質的に効果がなく、
     * PHP 8.5ではdeprecated。
     */
    unset($ch);

    if ($response === false) {
        $reason = match ($curlErrorNo) {
            CURLE_COULDNT_RESOLVE_HOST =>
                'kintoneのホスト名を名前解決できません。',
            CURLE_COULDNT_CONNECT =>
                'kintoneまたはProxyへ接続できません。',
            CURLE_OPERATION_TIMEDOUT =>
                'kintone通信がタイムアウトしました。',
            CURLE_SSL_CONNECT_ERROR =>
                'TLS/SSL接続に失敗しました。',
            CURLE_PROXY =>
                'Proxyとの通信に失敗しました。',
            CURLE_RECV_ERROR =>
                'kintoneからの応答を受信できませんでした。',
            default =>
                'kintoneへの通信に失敗しました。',
        };

        throw new RuntimeException(
            $reason .
            '（cURLエラー番号: ' .
            $curlErrorNo .
            '）'
        );
    }

    $decoded = null;

    if (trim($response) !== '') {
        $tmp = json_decode(
            $response,
            true
        );

        if (is_array($tmp)) {
            $decoded = $tmp;
        }
    }

    return [
        'url' => $url,
        'http_status' => $httpStatus,
        'content_type' => $contentType,
        'body' => $response,
        'json' => $decoded,
        'curl_error_no' => $curlErrorNo,
        'curl_error' => $curlError,
    ];
}

function kintone_error_message(
    array $result
): string {
    $json = $result['json'] ?? null;

    if (
        is_array($json)
        && !empty($json['message'])
    ) {
        return (string)$json['message'];
    }

    $status = (int)(
        $result['http_status'] ?? 0
    );

    return match (true) {
        $status === 400 =>
            'kintoneがリクエストを不正と判断しました。',
        $status === 401 =>
            'kintone認証に失敗しました。ログイン名とパスワードを確認してください。',
        $status === 403 =>
            'kintoneへのアクセス権限がありません。',
        $status === 404 =>
            'kintoneのアプリまたはAPI URLが見つかりません。',
        $status >= 500 =>
            'kintone側でサーバーエラーが発生しています。',
        default =>
            'kintoneからエラー応答が返りました。',
    };
}

function kintone_connection_test(
    array $settings
): array {
    $kintone = $settings['kintone'] ?? [];

    $appId = trim(
        (string)($kintone['app_id'] ?? '')
    );

    if ($appId === '') {
        throw new InvalidArgumentException(
            '顧客管理アプリIDを入力してください。'
        );
    }

    if (!ctype_digit($appId) || (int)$appId < 1) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDは1以上の整数で指定してください。'
        );
    }

    /*
     * prompt.txt指定:
     *
     * GET /k/v1/app.json?id=123
     */
    return kintone_request(
        $settings,
        '/k/v1/app.json?id=' .
        rawurlencode($appId),
        'GET'
    );
}

function kintone_get_fields(
    array $settings
): array {
    $kintone = $settings['kintone'] ?? [];

    $appId = trim(
        (string)($kintone['app_id'] ?? '')
    );

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDを確認してください。'
        );
    }

    $result = kintone_request(
        $settings,
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode($appId),
        'GET'
    );

    if (($result['http_status'] ?? 0) < 200
        || ($result['http_status'] ?? 0) >= 300
    ) {
        throw new RuntimeException(
            'kintone項目一覧取得に失敗しました。' .
            ' HTTP ' .
            (int)$result['http_status'] .
            ' / ' .
            kintone_error_message($result)
        );
    }

    return is_array($result['json'] ?? null)
        ? $result['json']
        : [];
}

function kintone_sync_customers(
    array $settings,
    array &$data
): int {
    $kintone = $settings['kintone'] ?? [];

    $appId = trim(
        (string)($kintone['app_id'] ?? '')
    );

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDを確認してください。'
        );
    }

    /*
     * まず100件取得。
     * POCの同期処理。
     */
    $query = 'limit 100';

    $result = kintone_request(
        $settings,
        '/k/v1/records.json?app=' .
        rawurlencode($appId) .
        '&query=' .
        rawurlencode($query),
        'GET'
    );

    $status = (int)(
        $result['http_status'] ?? 0
    );

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(
            '顧客情報の同期に失敗しました。' .
            ' HTTP ' .
            $status .
            ' / ' .
            kintone_error_message($result)
        );
    }

    $records =
        $result['json']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    /*
     * kintoneフィールドコードは設定画面の
     * マッピング値を使用。
     */
    $mapping =
        $kintone['mapping'] ?? [];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => generate_id('customer'),
            'kintoneId' =>
                (string)($record['$id']['value'] ?? ''),
            'organization' =>
                kintone_record_value(
                    $record,
                    first_mapping(
                        $mapping['organization'] ?? []
                    )
                ),
            'name' =>
                kintone_record_value(
                    $record,
                    (string)($mapping['name'] ?? '')
                ),
            'email' =>
                kintone_record_value(
                    $record,
                    (string)($mapping['email'] ?? '')
                ),
            'department' =>
                kintone_record_value(
                    $record,
                    (string)($mapping['department'] ?? '')
                ),
            'phone' =>
                kintone_record_value(
                    $record,
                    (string)($mapping['phone'] ?? '')
                ),
            'address' =>
                kintone_record_value(
                    $record,
                    first_mapping(
                        $mapping['address'] ?? []
                    )
                ),
            'raw' => $record,
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }

    $data['customers'] = $customers;

    save_data($data);

    return count($customers);
}

function first_mapping(mixed $value): string
{
    if (!is_array($value)) {
        return trim((string)$value);
    }

    foreach ($value as $item) {
        if (is_string($item) && trim($item) !== '') {
            return trim($item);
        }
    }

    return '';
}

function kintone_record_value(
    array $record,
    string $fieldCode
): string {
    $fieldCode = trim($fieldCode);

    if ($fieldCode === '') {
        return '';
    }

    $field = $record[$fieldCode] ?? null;

    if (!is_array($field)) {
        return '';
    }

    $value = $field['value'] ?? '';

    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $values[] = (string)(
                    $item['name']
                    ?? $item['value']
                    ?? ''
                );
            } else {
                $values[] = (string)$item;
            }
        }

        return implode(
            ', ',
            array_filter($values, static fn($v) =>
                $v !== ''
            )
        );
    }

    return (string)$value;
}

/* ============================================================
 * アンケートPOST処理
 * ============================================================ */

function process_post(
    string $screen,
    array &$data,
    array &$settings
): void {
    $action = post_string('action');

    if ($action === '') {
        return;
    }

    try {
        switch ($action) {
            case 'save_survey':
                save_survey_action($data);
                break;

            case 'delete_survey':
                delete_survey_action($data);
                break;

            case 'duplicate_survey':
                duplicate_survey_action($data);
                break;

            case 'change_status':
                change_status_action($data);
                break;

            case 'save_kintone':
                save_kintone_action($settings);
                break;

            case 'test_kintone':
                test_kintone_action($settings);
                break;

            case 'get_kintone_fields':
                get_kintone_fields_action($settings);
                break;

            case 'sync_kintone':
                sync_kintone_action(
                    $settings,
                    $data
                );
                break;

            case 'save_mail':
                save_mail_action($settings);
                break;

            case 'save_answer':
                save_answer_action($data);
                break;

            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }
    } catch (Throwable $e) {
        flash(
            'error',
            safe_exception_message($e)
        );
    }
}

function safe_exception_message(
    Throwable $e
): string {
    /*
     * ユーザーへは必要な情報のみ表示。
     * 認証情報や内部パスを出さない。
     */
    $message = $e->getMessage();

    $sensitivePatterns = [
        '/X-Cybozu-Authorization/i',
        '/password\s*[:=]/i',
        '/passwd\s*[:=]/i',
        '/Authorization\s*:/i',
        '/[A-Za-z0-9+\/]{30,}={0,2}/',
    ];

    foreach ($sensitivePatterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return '外部サービスとの通信に失敗しました。設定を確認してください。';
        }
    }

    return $message !== ''
        ? $message
        : '処理に失敗しました。';
}

function save_survey_action(array &$data): void
{
    $id = post_string('id');

    $title = post_string('title');

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException(
            'アンケートタイトルは200文字以内で入力してください。'
        );
    }

    $survey = $id !== ''
        ? survey_by_id($data, $id)
        : null;

    if ($survey === null) {
        $survey = default_survey();
    }

    $survey['title'] = $title;
    $survey['description'] =
        post_string('description');

    $survey['startAt'] =
        post_string('startAt');

    $survey['endAt'] =
        post_string('endAt');

    $numbering =
        post_string('numbering', 'global');

    $survey['numbering'] =
        in_array(
            $numbering,
            ['global', 'group'],
            true
        )
        ? $numbering
        : 'global';

    $status =
        post_string(
            'status',
            $survey['status'] ?? 'draft'
        );

    if (
        !in_array(
            $status,
            ['draft', 'published', 'stopped', 'ended'],
            true
        )
    ) {
        $status = 'draft';
    }

    /*
     * 終了は手動選択禁止。
     */
    if (
        ($survey['status'] ?? 'draft') === 'ended'
        || $status === 'ended'
    ) {
        $status =
            $survey['status'] ?? 'ended';
    }

    $survey['status'] = $status;
    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    normalize_survey_numbers($survey);

    $index = survey_index(
        $data,
        (string)$survey['id']
    );

    if ($index >= 0) {
        $data['surveys'][$index] = $survey;
    } else {
        $data['surveys'][] = $survey;
    }

    save_data($data);

    flash(
        'success',
        'アンケートを保存しました。'
    );

    redirect_screen('list');
}

function delete_survey_action(
    array &$data
): void {
    $id = post_string('id');

    if ($id === '') {
        throw new InvalidArgumentException(
            '削除対象が指定されていません。'
        );
    }

    $index = survey_index(
        $data,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    array_splice(
        $data['surveys'],
        $index,
        1
    );

    save_data($data);

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirect_screen('list');
}

function duplicate_survey_action(
    array &$data
): void {
    $id = post_string('id');

    $survey = survey_by_id(
        $data,
        $id
    );

    if ($survey === null) {
        throw new InvalidArgumentException(
            '複製対象が見つかりません。'
        );
    }

    $survey['id'] =
        generate_id('survey');

    $survey['title'] =
        ($survey['title'] ?? '') .
        '（コピー）';

    $survey['status'] = 'draft';
    $survey['createdAt'] =
        date('Y-m-d H:i:s');
    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    $data['surveys'][] = $survey;

    save_data($data);

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirect_screen('list');
}

function change_status_action(
    array &$data
): void {
    $id = post_string('id');
    $status = post_string('new_status');

    $index = survey_index(
        $data,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    $current =
        $data['surveys'][$index]['status']
        ?? 'draft';

    if (!can_manual_change_status($current)) {
        throw new InvalidArgumentException(
            '終了したアンケートの状態は変更できません。'
        );
    }

    $allowedTransitions = [
        'draft' => ['published'],
        'published' => ['stopped'],
        'stopped' => ['published'],
    ];

    if (
        !in_array(
            $status,
            $allowedTransitions[$current] ?? [],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '指定された状態変更はできません。'
        );
    }

    $data['surveys'][$index]['status'] =
        $status;

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    save_data($data);

    flash(
        'success',
        '状態を「' .
        survey_status_label($status) .
        '」に変更しました。'
    );

    redirect_screen('list');
}

/* ============================================================
 * kintone設定処理
 * ============================================================ */

function save_kintone_action(
    array &$settings
): void {
    $k =& $settings['kintone'];

    $subdomain =
        post_string('subdomain');

    $appId =
        post_string('app_id');

    $username =
        post_string('username');

    /*
     * パスワードは空欄なら既存値を維持。
     */
    $password =
        post_string('password');

    $proxy =
        post_string('proxy');

    normalize_subdomain($subdomain);

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDは1以上の整数で入力してください。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    /*
     * Proxyは空欄なら直接接続。
     * 入力されている場合のみ検証。
     */
    normalize_proxy($proxy);

    $k['subdomain'] =
        $subdomain;

    $k['app_id'] =
        $appId;

    $k['username'] =
        $username;

    if ($password !== '') {
        $k['password'] =
            $password;
    }

    $k['proxy'] =
        $proxy;

    $k['verify_ssl'] =
        isset($_POST['verify_ssl']);

    /*
     * mapping
     */
    $k['mapping']['name'] =
        post_string('mapping_name');

    $k['mapping']['email'] =
        post_string('mapping_email');

    $k['mapping']['department'] =
        post_string('mapping_department');

    $k['mapping']['phone'] =
        post_string('mapping_phone');

    $k['mapping']['organization'] =
        array_values(
            array_filter(
                array_map(
                    'trim',
                    $_POST['mapping_organization']
                    ?? []
                )
            )
        );

    $k['mapping']['address'] =
        array_values(
            array_filter(
                array_map(
                    'trim',
                    $_POST['mapping_address']
                    ?? []
                )
            )
        );

    save_settings($settings);

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirect_screen('kintone');
}

function test_kintone_action(
    array &$settings
): void {
    /*
     * 入力された値を先に保存する。
     * ただし接続テスト自体は別処理。
     */
    $temp = $settings;

    $temp['kintone']['subdomain'] =
        post_string('subdomain');

    $temp['kintone']['app_id'] =
        post_string('app_id');

    $temp['kintone']['username'] =
        post_string('username');

    $password =
        post_string('password');

    if ($password !== '') {
        $temp['kintone']['password'] =
            $password;
    }

    $temp['kintone']['proxy'] =
        post_string('proxy');

    $temp['kintone']['verify_ssl'] =
        isset($_POST['verify_ssl']);

    $result =
        kintone_connection_test(
            $temp
        );

    $status = (int)(
        $result['http_status'] ?? 0
    );

    if ($status < 200 || $status >= 300) {
        $message =
            kintone_error_message($result);

        $errorId =
            generate_error_id();

        $settings['kintone']['connection_status'] =
            '接続失敗';

        $settings['kintone']['last_test_at'] =
            date('Y-m-d H:i:s');

        save_settings($settings);

        flash(
            'error',
            'kintone接続テスト失敗' .
            "\nHTTP " .
            $status .
            "\n" .
            $message .
            "\nエラーID: " .
            $errorId .
            "\n\n確認ポイント：" .
            "\n・サブドメイン" .
            "\n・顧客管理アプリID" .
            "\n・ログイン名とパスワード" .
            "\n・Proxy設定" .
            "\n・kintone側のアクセス権限"
        );

        redirect_screen('kintone');
    }

    $settings['kintone']['connection_status'] =
        '接続成功';

    $settings['kintone']['last_test_at'] =
        date('Y-m-d H:i:s');

    /*
     * 成功した認証情報を保存。
     */
    $settings['kintone']['subdomain'] =
        $temp['kintone']['subdomain'];

    $settings['kintone']['app_id'] =
        $temp['kintone']['app_id'];

    $settings['kintone']['username'] =
        $temp['kintone']['username'];

    $settings['kintone']['password'] =
        $temp['kintone']['password'];

    $settings['kintone']['proxy'] =
        $temp['kintone']['proxy'];

    $settings['kintone']['verify_ssl'] =
        $temp['kintone']['verify_ssl'];

    save_settings($settings);

    $appName =
        (string)(
            $result['json']['name']
            ?? ''
        );

    $detail =
        'kintone接続成功' .
        "\nHTTP " .
        $status;

    if ($appName !== '') {
        $detail .=
            "\nアプリ名: " .
            $appName;
    }

    $detail .=
        "\n確認API: /k/v1/app.json?id=" .
        $settings['kintone']['app_id'];

    flash(
        'success',
        $detail
    );

    redirect_screen('kintone');
}

function generate_error_id(): string
{
    return strtoupper(
        bin2hex(random_bytes(5))
    );
}

function get_kintone_fields_action(
    array &$settings
): void {
    $temp = $settings;

    $temp['kintone']['subdomain'] =
        post_string('subdomain');

    $temp['kintone']['app_id'] =
        post_string('app_id');

    $temp['kintone']['username'] =
        post_string('username');

    $password =
        post_string('password');

    if ($password !== '') {
        $temp['kintone']['password'] =
            $password;
    }

    $temp['kintone']['proxy'] =
        post_string('proxy');

    $temp['kintone']['verify_ssl'] =
        isset($_POST['verify_ssl']);

    $fields =
        kintone_get_fields(
            $temp
        );

    $_SESSION['kintone_fields'] =
        $fields;

    flash(
        'success',
        'kintone項目一覧を取得しました。'
    );

    redirect_screen('kintone');
}

function sync_kintone_action(
    array &$settings,
    array &$data
): void {
    $count =
        kintone_sync_customers(
            $settings,
            $data
        );

    flash(
        'success',
        '顧客情報の同期が完了しました。同期件数: ' .
        $count .
        '件'
    );

    redirect_screen('kintone');
}

/* ============================================================
 * メール設定
 * ============================================================ */

function save_mail_action(
    array &$settings
): void {
    $m =& $settings['mail'];

    $server =
        post_string('smtp_server');

    $port =
        post_string('smtp_port', '587');

    if ($server === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    if (
        !ctype_digit($port)
        || (int)$port < 1
        || (int)$port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートは1～65535で指定してください。'
        );
    }

    $m['smtp_server'] = $server;
    $m['smtp_port'] = $port;
    $m['encryption'] =
        in_array(
            post_string('encryption'),
            ['ssl', 'tls', 'none'],
            true
        )
        ? post_string('encryption')
        : 'tls';

    $m['auth'] =
        isset($_POST['smtp_auth']);

    $m['username'] =
        post_string('smtp_username');

    $password =
        post_string('smtp_password');

    if ($password !== '') {
        $m['password'] =
            $password;
    }

    $m['from_email'] =
        post_string('from_email');

    $m['from_name'] =
        post_string('from_name');

    $m['reply_to'] =
        post_string('reply_to');

    if (
        $m['from_email'] !== ''
        && !filter_var(
            $m['from_email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスの形式が不正です。'
        );
    }

    save_settings($settings);

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirect_screen('mail');
}

/* ============================================================
 * 回答処理
 * ============================================================ */

function save_answer_action(
    array &$data
): void {
    $surveyId =
        post_string('survey_id');

    $survey =
        survey_by_id(
            $data,
            $surveyId
        );

    if ($survey === null) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    if (
        ($survey['status'] ?? '') !== 'published'
    ) {
        throw new InvalidArgumentException(
            '現在このアンケートには回答できません。'
        );
    }

    $answers =
        $_POST['answers'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $qid =
                (string)$question['id'];

            if (
                !empty($question['required'])
                && empty($answers[$qid])
            ) {
                throw new InvalidArgumentException(
                    '必須項目が未回答です。'
                );
            }
        }
    }

    $data['answers'][] = [
        'id' =>
            generate_id('answer'),
        'surveyId' =>
            $surveyId,
        'answers' =>
            $answers,
        'createdAt' =>
            date('Y-m-d H:i:s'),
    ];

    save_data($data);

    $_SESSION['last_answer_id'] =
        end($data['answers'])['id']
        ?? '';

    redirect_screen(
        'complete',
        ['id' => $surveyId]
    );
}

/* ============================================================
 * POST処理実行
 * ============================================================ */

$data = load_data();
$settings = load_settings();

foreach ($data['surveys'] as &$survey) {
    if (update_survey_auto_status($survey)) {
        $survey['updatedAt'] =
            date('Y-m-d H:i:s');
    }
}
unset($survey);

save_data($data);

$screen = current_screen();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    process_post(
        $screen,
        $data,
        $settings
    );

    /*
     * process_post内でredirectしなかった場合。
     */
    $data = load_data();
    $settings = load_settings();
}

/* ============================================================
 * 表示用
 * ============================================================ */

$flashes = consume_flash();

function render_flash(array $flashes): void
{
    foreach ($flashes as $item) {
        $type =
            $item['type'] ?? 'info';

        $message =
            (string)($item['message'] ?? '');

        $class = match ($type) {
            'success' => 'alert-success',
            'error' => 'alert-error',
            'warning' => 'alert-warning',
            default => 'alert-info',
        };

        echo '<div class="alert ' .
            h($class) .
            '">';

        echo nl2br(
            h($message)
        );

        echo '</div>';
    }
}

function page_title(string $screen): string
{
    return match ($screen) {
        'edit' => 'アンケート作成・編集',
        'preview' => 'プレビュー',
        'analytics' => '回答集計・分析',
        'send' => '顧客選択・メール送信',
        'kintone' => 'kintone連携設定',
        'mail' => 'メールサーバ設定',
        'answer' => 'アンケート回答',
        'confirm' => '回答確認',
        'complete' => '回答完了',
        default => APP_TITLE,
    };
}

function render_admin_header(
    string $screen
): void {
    ?>
    <header class="admin-header">
        <div class="header-inner">
            <div class="brand">
                <span class="brand-mark">Q</span>
                <span><?= h(APP_TITLE) ?></span>
            </div>

            <nav class="main-nav">
                <a class="<?= $screen === 'list' ? 'active' : '' ?>"
                   href="?screen=list">
                    アンケート一覧
                </a>

                <a class="<?= $screen === 'kintone' ? 'active' : '' ?>"
                   href="?screen=kintone">
                    kintone
                </a>

                <a class="<?= $screen === 'mail' ? 'active' : '' ?>"
                   href="?screen=mail">
                    メール
                </a>
            </nav>
        </div>
    </header>
    <?php
}

function render_admin_footer(): void
{
    ?>
    <footer class="footer">
        <div class="container">
            アンケート管理 POC
        </div>
    </footer>
    <?php
}

/* ============================================================
 * 一覧
 * ============================================================ */

function render_list(
    array $data
): void {
    $surveys =
        $data['surveys'] ?? [];

    $keyword =
        get_string('q');

    $status =
        get_string('status', 'all');

    $sort =
        get_string('sort', 'updated_desc');

    $filtered = [];

    foreach ($surveys as $survey) {
        if (
            $keyword !== ''
            && mb_stripos(
                (string)($survey['title'] ?? ''),
                $keyword
            ) === false
        ) {
            continue;
        }

        if (
            $status !== 'all'
            && ($survey['status'] ?? '') !== $status
        ) {
            continue;
        }

        $filtered[] = $survey;
    }

    usort(
        $filtered,
        static function (
            array $a,
            array $b
        ) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)($a['updatedAt'] ?? ''),
                        (string)($b['updatedAt'] ?? '')
                    ),
                'answers_desc' => 0,
                'answers_asc' => 0,
                'start_desc' =>
                    strcmp(
                        (string)($b['startAt'] ?? ''),
                        (string)($a['startAt'] ?? '')
                    ),
                'start_asc' =>
                    strcmp(
                        (string)($a['startAt'] ?? ''),
                        (string)($b['startAt'] ?? '')
                    ),
                default =>
                    strcmp(
                        (string)($b['updatedAt'] ?? ''),
                        (string)($a['updatedAt'] ?? '')
                    ),
            };
        }
    );
    ?>

    <div class="page-head">
        <div>
            <h1>アンケート一覧</h1>
            <p class="muted">
                アンケートの作成・公開・集計・送信を管理します。
            </p>
        </div>

        <a class="btn btn-primary"
           href="?screen=edit">
            ＋ 新規アンケート
        </a>
    </div>

    <form class="filter-bar"
          method="get">
        <input type="hidden"
               name="screen"
               value="list">

        <input
            class="search-input"
            type="search"
            name="q"
            value="<?= h($keyword) ?>"
            placeholder="タイトルを検索（Enterで検索）">

        <select name="status">
            <option value="all"
                <?= $status === 'all' ? 'selected' : '' ?>>
                すべて
            </option>
            <option value="published"
                <?= $status === 'published' ? 'selected' : '' ?>>
                公開中
            </option>
            <option value="draft"
                <?= $status === 'draft' ? 'selected' : '' ?>>
                下書き
            </option>
            <option value="stopped"
                <?= $status === 'stopped' ? 'selected' : '' ?>>
                停止
            </option>
            <option value="ended"
                <?= $status === 'ended' ? 'selected' : '' ?>>
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
        </select>

        <button class="btn btn-secondary"
                type="submit">
            検索
        </button>
    </form>

    <div class="card table-card">
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>タイトル</th>
                    <th>作成日</th>
                    <th>更新日</th>
                    <th>期間</th>
                    <th>ステータス</th>
                    <th>回答数</th>
                    <th>操作</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$filtered): ?>
                    <tr>
                        <td colspan="7"
                            class="empty-cell">
                            アンケートがありません。
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($filtered as $survey): ?>
                    <?php
                    $surveyId =
                        (string)$survey['id'];

                    $answerCount =
                        count_answers(
                            $data,
                            $surveyId
                        );
                    ?>
                    <tr>
                        <td>
                            <strong>
                                <?= h($survey['title']) ?>
                            </strong>
                        </td>

                        <td>
                            <?= h($survey['createdAt'] ?? '') ?>
                        </td>

                        <td>
                            <?= h($survey['updatedAt'] ?? '') ?>
                        </td>

                        <td>
                            <?= h($survey['startAt'] ?? '') ?>
                            <br>
                            ～
                            <br>
                            <?= h($survey['endAt'] ?? '') ?>
                        </td>

                        <td>
                            <span class="badge <?= h(
                                survey_status_class(
                                    (string)$survey['status']
                                )
                            ) ?>">
                                <?= h(
                                    survey_status_label(
                                        (string)$survey['status']
                                    )
                                ) ?>
                            </span>
                        </td>

                        <td>
                            <?= $answerCount ?>件
                        </td>

                        <td>
                            <div class="actions">
                                <a class="btn btn-small"
                                   href="?screen=edit&id=<?= rawurlencode($surveyId) ?>">
                                    確認・編集
                                </a>

                                <a class="btn btn-small"
                                   href="?screen=analytics&id=<?= rawurlencode($surveyId) ?>">
                                    集計
                                </a>

                                <a class="btn btn-small"
                                   href="?screen=send&id=<?= rawurlencode($surveyId) ?>">
                                    送信
                                </a>

                                <form method="post"
                                      class="inline-form"
                                      onsubmit="return confirm('このアンケートを複製しますか？');">
                                    <input type="hidden"
                                           name="action"
                                           value="duplicate_survey">

                                    <input type="hidden"
                                           name="id"
                                           value="<?= h($surveyId) ?>">

                                    <button class="btn btn-small"
                                            type="submit">
                                        複製
                                    </button>
                                </form>

                                <form method="post"
                                      class="inline-form"
                                      onsubmit="return confirm('このアンケートを削除しますか？');">
                                    <input type="hidden"
                                           name="action"
                                           value="delete_survey">

                                    <input type="hidden"
                                           name="id"
                                           value="<?= h($surveyId) ?>">

                                    <button class="btn btn-small btn-danger"
                                            type="submit">
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
}

function count_answers(
    array $data,
    string $surveyId
): int {
    $count = 0;

    foreach (
        ($data['answers'] ?? [])
        as $answer
    ) {
        if (
            ($answer['surveyId'] ?? '') === $surveyId
        ) {
            $count++;
        }
    }

    return $count;
}

/* ============================================================
 * 編集
 * ============================================================ */

function render_edit(
    array $data
): void {
    $id =
        get_string('id');

    $survey =
        $id !== ''
        ? survey_by_id($data, $id)
        : null;

    if ($survey === null) {
        $survey = default_survey();
    }

    normalize_survey_numbers($survey);
    ?>

    <div class="page-head">
        <div>
            <h1>アンケート作成・編集</h1>
        </div>

        <div class="button-row">
            <a class="btn btn-secondary"
               href="?screen=list">
                キャンセル
            </a>
        </div>
    </div>

    <form method="post"
          id="survey-form">

        <input type="hidden"
               name="action"
               value="save_survey">

        <input type="hidden"
               name="id"
               value="<?= h($survey['id']) ?>">

        <div class="card">
            <div class="card-header">
                <h2>基本情報</h2>

                <span>
                    状態：
                    <span class="badge <?= h(
                        survey_status_class(
                            (string)$survey['status']
                        )
                    ) ?>">
                        <?= h(
                            survey_status_label(
                                (string)$survey['status']
                            )
                        ) ?>
                    </span>
                </span>
            </div>

            <div class="form-grid">
                <label>
                    <span>アンケートタイトル *</span>
                    <input type="text"
                           name="title"
                           maxlength="200"
                           required
                           value="<?= h($survey['title']) ?>">
                </label>

                <label>
                    <span>アンケート説明</span>
                    <textarea name="description"
                              rows="4"><?= h(
                                  $survey['description']
                              ) ?></textarea>
                </label>

                <label>
                    <span>開始日時</span>
                    <input type="datetime-local"
                           name="startAt"
                           value="<?= h(
                               datetime_local_value(
                                   $survey['startAt'] ?? ''
                               )
                           ) ?>">
                </label>

                <label>
                    <span>終了日時</span>
                    <input type="datetime-local"
                           name="endAt"
                           value="<?= h(
                               datetime_local_value(
                                   $survey['endAt'] ?? ''
                               )
                           ) ?>">
                </label>

                <label>
                    <span>質問番号の採番方式</span>

                    <select name="numbering">
                        <option value="global"
                            <?= ($survey['numbering'] ?? 'global') === 'global'
                                ? 'selected'
                                : '' ?>>
                            アンケート全体で通番（Q1、Q2…）
                        </option>

                        <option value="group"
                            <?= ($survey['numbering'] ?? '') === 'group'
                                ? 'selected'
                                : '' ?>>
                            グループ毎（Q1-1、Q1-2…）
                        </option>
                    </select>
                </label>

                <label>
                    <span>状態</span>

                    <?php if (
                        ($survey['status'] ?? '') === 'ended'
                    ): ?>
                        <input type="text"
                               disabled
                               value="終了">
                    <?php else: ?>
                        <select name="status">
                            <option value="draft"
                                <?= ($survey['status'] ?? '') === 'draft'
                                    ? 'selected'
                                    : '' ?>>
                                下書き
                            </option>

                            <option value="published"
                                <?= ($survey['status'] ?? '') === 'published'
                                    ? 'selected'
                                    : '' ?>>
                                公開中
                            </option>

                            <option value="stopped"
                                <?= ($survey['status'] ?? '') === 'stopped'
                                    ? 'selected'
                                    : '' ?>>
                                停止
                            </option>
                        </select>
                    <?php endif; ?>
                </label>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>質問・グループ</h2>
            </div>

            <div id="groups">
                <?php foreach (
                    $survey['groups']
                    as $groupIndex => $group
                ): ?>

                    <div class="group-card"
                         draggable="true">

                        <div class="group-header">
                            <span class="drag-handle">☷</span>

                            <input type="text"
                                   name="group_titles[]"
                                   value="<?= h(
                                       $group['title']
                                   ) ?>"
                                   placeholder="グループタイトル">

                            <button type="button"
                                    class="btn btn-small btn-danger"
                                    onclick="removeGroup(this)">
                                グループ削除
                            </button>
                        </div>

                        <?php foreach (
                            $group['questions']
                            as $questionIndex => $question
                        ): ?>

                            <div class="question-card"
                                 draggable="true">

                                <div class="question-header">
                                    <span class="question-number">
                                        <?= h(
                                            $question['number']
                                            ?? 'Q'
                                        ) ?>
                                    </span>

                                    <span class="drag-handle">
                                        ☷
                                    </span>

                                    <button type="button"
                                            class="btn btn-small btn-danger"
                                            onclick="removeQuestion(this)">
                                        質問削除
                                    </button>
                                </div>

                                <label>
                                    質問文
                                    <input type="text"
                                           name="question_text[]"
                                           value="<?= h(
                                               $question['text']
                                           ) ?>">
                                </label>

                                <div class="question-row">
                                    <label>
                                        回答形式
                                        <select name="question_type[]">
                                            <option value="single"
                                                <?= ($question['type'] ?? '')
                                                    === 'single'
                                                    ? 'selected'
                                                    : '' ?>>
                                                単一選択
                                            </option>

                                            <option value="multiple"
                                                <?= ($question['type'] ?? '')
                                                    === 'multiple'
                                                    ? 'selected'
                                                    : '' ?>>
                                                複数選択
                                            </option>

                                            <option value="text"
                                                <?= ($question['type'] ?? '')
                                                    === 'text'
                                                    ? 'selected'
                                                    : '' ?>>
                                                自由記述
                                            </option>
                                        </select>
                                    </label>

                                    <label class="check-label">
                                        <input type="checkbox"
                                               name="question_required[]"
                                               value="1"
                                            <?= !empty(
                                                $question['required']
                                            )
                                                ? 'checked'
                                                : '' ?>>
                                        必須
                                    </label>
                                </div>

                                <div class="options-box">
                                    <strong>選択肢</strong>

                                    <?php foreach (
                                        ($question['options'] ?? [])
                                        as $option
                                    ): ?>
                                        <input type="text"
                                               name="options[]"
                                               value="<?= h(
                                                   $option['label']
                                               ) ?>"
                                               placeholder="選択肢">
                                    <?php endforeach; ?>

                                    <button type="button"
                                            class="btn btn-small"
                                            onclick="addOption(this)">
                                        ＋ 選択肢追加
                                    </button>
                                </div>

                            </div>
                        <?php endforeach; ?>

                        <button type="button"
                                class="btn btn-secondary add-question"
                                onclick="addQuestion(this)">
                            ＋ 質問を追加
                        </button>
                    </div>

                <?php endforeach; ?>

                <button type="button"
                        class="btn btn-secondary"
                        onclick="addGroup()">
                    ＋ グループを追加
                </button>
            </div>
        </div>

        <div class="sticky-actions">
            <a class="btn btn-secondary"
               href="?screen=list">
                キャンセル
            </a>

            <button class="btn btn-primary"
                    type="submit">
                保存して一覧へ
            </button>
        </div>
    </form>
    <?php
}

function datetime_local_value(
    string $value
): string {
    if ($value === '') {
        return '';
    }

    $time = strtotime($value);

    if ($time === false) {
        return '';
    }

    return date(
        'Y-m-d\TH:i',
        $time
    );
}

/* ============================================================
 * kintone画面
 * ============================================================ */

function render_kintone(
    array $settings,
    array $data
): void {
    $k =
        $settings['kintone'];

    $fields =
        $_SESSION['kintone_fields']
        ?? null;

    $status =
        (string)(
            $k['connection_status']
            ?? '未確認'
        );

    $statusClass =
        $status === '接続成功'
        ? 'badge-success'
        : (
            $status === '接続失敗'
            ? 'badge-danger'
            : 'badge-gray'
        );
    ?>

    <div class="page-head">
        <div>
            <h1>kintone連携設定</h1>

            <p class="muted">
                顧客情報の取得元としてkintoneを使用します。
            </p>
        </div>

        <span class="badge <?= h($statusClass) ?>">
            <?= h($status) ?>
        </span>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>接続設定</h2>
        </div>

        <form method="post"
              id="kintone-form">

            <input type="hidden"
                   name="action"
                   value="save_kintone">

            <div class="form-grid">

                <label>
                    <span>サブドメイン *</span>

                    <input type="text"
                           name="subdomain"
                           required
                           value="<?= h(
                               $k['subdomain']
                           ) ?>"
                           placeholder="xxxx または xxxx.cybozu.com">
                    <small>
                        xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com
                        のいずれでも入力できます。
                    </small>
                </label>

                <label>
                    <span>顧客管理アプリID *</span>

                    <input type="number"
                           min="1"
                           step="1"
                           name="app_id"
                           required
                           value="<?= h(
                               $k['app_id']
                           ) ?>">
                </label>

                <label>
                    <span>ログイン名 *</span>

                    <input type="text"
                           name="username"
                           required
                           value="<?= h(
                               $k['username']
                           ) ?>">
                </label>

                <label>
                    <span>パスワード</span>

                    <input type="password"
                           name="password"
                           autocomplete="new-password"
                           placeholder="変更しない場合は空欄">
                </label>

                <label>
                    <span>Proxy</span>

                    <input type="text"
                           name="proxy"
                           value="<?= h(
                               $k['proxy']
                           ) ?>"
                           placeholder="proxy.example.local:8080">
                    <small>
                        未入力ならProxyを使用せず直接接続します。
                    </small>
                </label>

                <label class="check-label">
                    <input type="checkbox"
                           name="verify_ssl"
                           value="1"
                        <?= !empty($k['verify_ssl'])
                            ? 'checked'
                            : '' ?>>
                    SSL証明書を検証する
                </label>

            </div>

            <div class="button-row">
                <button class="btn btn-primary"
                        type="submit">
                    設定保存
                </button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>kintone接続</h2>

                <p class="muted">
                    接続テストと顧客同期は別操作です。
                </p>
            </div>

            <?php if (
                !empty($k['last_test_at'])
            ): ?>
                <span class="muted">
                    最終確認：
                    <?= h($k['last_test_at']) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="connection-actions">

            <form method="post"
                  class="connection-form"
                  onsubmit="return startConnectionTest(this);">

                <input type="hidden"
                       name="action"
                       value="test_kintone">

                <input type="hidden"
                       name="subdomain"
                       value="<?= h($k['subdomain']) ?>">

                <input type="hidden"
                       name="app_id"
                       value="<?= h($k['app_id']) ?>">

                <input type="hidden"
                       name="username"
                       value="<?= h($k['username']) ?>">

                <input type="hidden"
                       name="proxy"
                       value="<?= h($k['proxy']) ?>">

                <input type="hidden"
                       name="verify_ssl"
                       value="<?= !empty($k['verify_ssl'])
                            ? '1'
                            : '0' ?>">

                <label class="sr-only">
                    パスワード
                    <input type="password"
                           name="password"
                           autocomplete="off">
                </label>

                <button class="btn btn-primary test-button"
                        type="submit">
                    <span class="spinner"></span>
                    接続テスト
                </button>
            </form>

            <form method="post"
                  onsubmit="return confirm('kintoneの項目一覧を再取得しますか？');">

                <input type="hidden"
                       name="action"
                       value="get_kintone_fields">

                <input type="hidden"
                       name="subdomain"
                       value="<?= h($k['subdomain']) ?>">

                <input type="hidden"
                       name="app_id"
                       value="<?= h($k['app_id']) ?>">

                <input type="hidden"
                       name="username"
                       value="<?= h($k['username']) ?>">

                <input type="hidden"
                       name="proxy"
                       value="<?= h($k['proxy']) ?>">

                <input type="hidden"
                       name="verify_ssl"
                       value="<?= !empty($k['verify_ssl'])
                            ? '1'
                            : '0' ?>">

                <label class="sr-only">
                    パスワード
                    <input type="password"
                           name="password"
                           autocomplete="off">
                </label>

                <button class="btn btn-secondary"
                        type="submit">
                    項目一覧を再取得
                </button>
            </form>

            <form method="post"
                  onsubmit="return confirm('kintoneから顧客情報を同期しますか？');">

                <input type="hidden"
                       name="action"
                       value="sync_kintone">

                <button class="btn btn-secondary"
                        type="submit">
                    顧客情報を同期
                </button>
            </form>
        </div>

        <div class="sync-summary">
            現在の同期件数：
            <strong>
                <?= count($data['customers'] ?? []) ?>
            </strong>
            件
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>項目マッピング</h2>
        </div>

        <form method="post">

            <input type="hidden"
                   name="action"
                   value="save_kintone">

            <input type="hidden"
                   name="subdomain"
                   value="<?= h($k['subdomain']) ?>">

            <input type="hidden"
                   name="app_id"
                   value="<?= h($k['app_id']) ?>">

            <input type="hidden"
                   name="username"
                   value="<?= h($k['username']) ?>">

            <input type="hidden"
                   name="proxy"
                   value="<?= h($k['proxy']) ?>">

            <input type="hidden"
                   name="verify_ssl"
                   value="<?= !empty($k['verify_ssl'])
                        ? '1'
                        : '0' ?>">

            <div class="form-grid">

                <label>
                    <span>氏名</span>
                    <input type="text"
                           name="mapping_name"
                           value="<?= h(
                               $k['mapping']['name'] ?? ''
                           ) ?>"
                           placeholder="name">
                </label>

                <label>
                    <span>メールアドレス</span>
                    <input type="text"
                           name="mapping_email"
                           value="<?= h(
                               $k['mapping']['email'] ?? ''
                           ) ?>"
                           placeholder="email">
                </label>

                <label>
                    <span>部署名</span>
                    <input type="text"
                           name="mapping_department"
                           value="<?= h(
                               $k['mapping']['department'] ?? ''
                           ) ?>"
                           placeholder="department">
                </label>

                <label>
                    <span>電話番号</span>
                    <input type="text"
                           name="mapping_phone"
                           value="<?= h(
                               $k['mapping']['phone'] ?? ''
                           ) ?>"
                           placeholder="phone">
                </label>

            </div>

            <div class="button-row">
                <button class="btn btn-primary"
                        type="submit">
                    マッピングを保存
                </button>
            </div>
        </form>
    </div>

    <?php if (
        is_array($fields)
        && !empty($fields['properties'])
    ): ?>

        <div class="card">
            <div class="card-header">
                <h2>kintone項目一覧</h2>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>フィールドコード</th>
                        <th>項目名</th>
                        <th>種類</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach (
                        $fields['properties']
                        as $code => $field
                    ): ?>

                        <tr>
                            <td>
                                <code><?= h($code) ?></code>
                            </td>

                            <td>
                                <?= h(
                                    $field['label'] ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $field['type'] ?? ''
                                ) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

    <div class="info-box">
        <strong>接続テストで確認している内容</strong>

        <ol>
            <li>PHP cURLが利用可能か</li>
            <li>Proxy設定を解析できるか</li>
            <li>kintoneサブドメインを正規化できるか</li>
            <li>kintoneへHTTPS接続できるか</li>
            <li>認証情報でAPIへアクセスできるか</li>
            <li>指定されたアプリIDへアクセスできるか</li>
            <li>HTTPステータスとkintone APIエラーを確認</li>
        </ol>
    </div>
    <?php
}

/* ============================================================
 * 集計
 * ============================================================ */

function render_analytics(
    array $data
): void {
    $id =
        get_string('id');

    $survey =
        survey_by_id(
            $data,
            $id
        );

    if ($survey === null) {
        echo '<div class="alert alert-error">';
        echo '対象アンケートが指定されていないか、存在しません。';
        echo '</div>';
        return;
    }

    $answers = [];

    foreach (
        ($data['answers'] ?? [])
        as $answer
    ) {
        if (
            ($answer['surveyId'] ?? '') === $id
        ) {
            $answers[] = $answer;
        }
    }

    $total =
        count($answers);
    ?>

    <div class="page-head">
        <div>
            <h1>回答集計・分析</h1>

            <p>
                対象：
                <strong>
                    <?= h($survey['title']) ?>
                </strong>
            </p>
        </div>

        <a class="btn btn-secondary"
           href="?screen=list">
            一覧へ戻る
        </a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span>送信対象者数</span>
            <strong>
                <?= count($data['customers'] ?? []) ?>
            </strong>
        </div>

        <div class="stat-card">
            <span>回答数</span>
            <strong>
                <?= $total ?>
            </strong>
        </div>

        <div class="stat-card">
            <span>未回答数</span>
            <strong>
                <?= max(
                    0,
                    count($data['customers'] ?? []) - $total
                ) ?>
            </strong>
        </div>

        <div class="stat-card">
            <span>回答率</span>
            <strong>
                <?php
                $customers =
                    count($data['customers'] ?? []);

                echo $customers > 0
                    ? h(
                        number_format(
                            ($total / $customers) * 100,
                            1
                        )
                    ) . '%'
                    : '0%';
                ?>
            </strong>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>設問別集計</h2>
        </div>

        <?php if ($total === 0): ?>

            <div class="empty-state">
                現在、回答データはありません
            </div>

        <?php else: ?>

            <?php foreach (
                $survey['groups']
                as $group
            ): ?>

                <section class="analytics-group">
                    <h3>
                        <?= h($group['title']) ?>
                    </h3>

                    <?php foreach (
                        $group['questions']
                        as $question
                    ): ?>

                        <div class="question-summary">
                            <h4>
                                <?= h(
                                    $question['number']
                                    ?? ''
                                ) ?>
                                <?= h(
                                    $question['text']
                                    ?? ''
                                ) ?>
                            </h4>

                            <?php
                            $counts = [];

                            foreach (
                                $answers
                                as $answer
                            ) {
                                $value =
                                    $answer['answers'][
                                        $question['id']
                                    ] ?? null;

                                if (is_array($value)) {
                                    foreach ($value as $v) {
                                        $key = (string)$v;
                                        $counts[$key] =
                                            ($counts[$key] ?? 0) + 1;
                                    }
                                } elseif ($value !== null) {
                                    $key = (string)$value;
                                    $counts[$key] =
                                        ($counts[$key] ?? 0) + 1;
                                }
                            }
                            ?>

                            <?php if (
                                empty($counts)
                            ): ?>

                                <p class="muted">
                                    回答なし
                                </p>

                            <?php else: ?>

                                <?php foreach (
                                    $counts
                                    as $label => $count
                                ): ?>

                                    <div class="bar-row">
                                        <span>
                                            <?= h($label) ?>
                                        </span>

                                        <div class="bar">
                                            <div style="width: <?= h(
                                                $total > 0
                                                    ? ($count / $total) * 100
                                                    : 0
                                            ) ?>%"></div>
                                        </div>

                                        <strong>
                                            <?= $count ?>
                                        </strong>
                                    </div>

                                <?php endforeach; ?>

                            <?php endif; ?>
                        </div>

                    <?php endforeach; ?>

                </section>

            <?php endforeach; ?>

        <?php endif; ?>
    </div>
    <?php
}

/* ============================================================
 * 送信
 * ============================================================ */

function render_send(
    array $data
): void {
    $id =
        get_string('id');

    $survey =
        survey_by_id(
            $data,
            $id
        );

    if ($survey === null) {
        echo '<div class="alert alert-error">';
        echo '対象アンケートが指定されていません。';
        echo '</div>';
        return;
    }

    $customers =
        $data['customers'] ?? [];
    ?>

    <div class="page-head">
        <div>
            <h1>顧客選択・メール送信</h1>
            <p>
                対象：
                <strong><?= h(
                    $survey['title']
                ) ?></strong>
            </p>
        </div>

        <a class="btn btn-secondary"
           href="?screen=list">
            一覧へ戻る
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>顧客選択</h2>

            <span>
                <?= count($customers) ?>件
            </span>
        </div>

        <form method="post"
              onsubmit="return confirm('選択した顧客へ一括送信しますか？');">

            <input type="hidden"
                   name="survey_id"
                   value="<?= h($id) ?>">

            <div class="customer-list">

                <?php foreach (
                    $customers
                    as $customer
                ): ?>

                    <label class="customer-row">

                        <input type="checkbox"
                               name="customer_ids[]"
                               value="<?= h(
                                   $customer['id']
                               ) ?>">

                        <span>
                            <strong>
                                <?= h(
                                    $customer['name']
                                ) ?>
                            </strong>

                            <small>
                                <?= h(
                                    $customer['email']
                                ) ?>
                            </small>
                        </span>

                    </label>

                <?php endforeach; ?>

            </div>

            <div class="form-grid">
                <label>
                    メール件名
                    <input type="text"
                           name="mail_subject"
                           value="<?= h(
                               $survey['title']
                           ) ?>">
                </label>

                <label>
                    メール本文
                    <textarea name="mail_body"
                              rows="10">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>
                </label>
            </div>

            <button class="btn btn-primary"
                    type="button"
                    onclick="alert('SMTP設定後に実送信処理を有効化します。')">
                一括送信
            </button>

        </form>
    </div>
    <?php
}

/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(
    array $data
): void {
    $id =
        get_string('id');

    $survey =
        survey_by_id(
            $data,
            $id
        );

    if ($survey === null) {
        echo '<div class="alert alert-error">';
        echo '対象アンケートがありません。';
        echo '</div>';
        return;
    }
    ?>

    <div class="page-head">
        <div>
            <h1>プレビュー</h1>
        </div>

        <a class="btn btn-secondary"
           href="?screen=edit&id=<?= rawurlencode($id) ?>">
            編集へ戻る
        </a>
    </div>

    <div class="answer-preview">
        <h1><?= h($survey['title']) ?></h1>

        <?php if (
            $survey['description'] !== ''
        ): ?>
            <p class="description">
                <?= nl2br(
                    h($survey['description'])
                ) ?>
            </p>
        <?php endif; ?>

        <?php foreach (
            $survey['groups']
            as $group
        ): ?>

            <section class="preview-group">
                <h2>
                    <?= h($group['title']) ?>
                </h2>

                <?php foreach (
                    $group['questions']
                    as $question
                ): ?>

                    <div class="preview-question">

                        <h3>
                            <?= h(
                                $question['number']
                            ) ?>
                            <?= h(
                                $question['text']
                            ) ?>

                            <?php if (
                                !empty(
                                    $question['required']
                                )
                            ): ?>
                                <span class="required">
                                    必須
                                </span>
                            <?php endif; ?>
                        </h3>

                        <?php
                        $type =
                            $question['type'];
                        ?>

                        <?php if (
                            $type === 'text'
                        ): ?>

                            <textarea
                                disabled
                                rows="4"></textarea>

                        <?php else: ?>

                            <?php foreach (
                                $question['options']
                                as $option
                            ): ?>

                                <label class="choice">
                                    <input
                                        type="<?= $type === 'single'
                                            ? 'radio'
                                            : 'checkbox' ?>"
                                        disabled>

                                    <?= h(
                                        $option['label']
                                    ) ?>
                                </label>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>
            </section>

        <?php endforeach; ?>
    </div>
    <?php
}

/* ============================================================
 * 回答者画面
 * ============================================================ */

function render_answer(
    array $data
): void {
    $id =
        get_string('id');

    $survey =
        survey_by_id(
            $data,
            $id
        );

    if ($survey === null) {
        render_answer_error(
            'アンケートが見つかりません。'
        );
        return;
    }

    if (
        ($survey['status'] ?? '') !== 'published'
    ) {
        render_answer_error(
            '現在このアンケートは回答を受け付けていません。'
        );
        return;
    }
    ?>

    <div class="respondent-page">
        <div class="respondent-card">

            <div class="respondent-brand">
                アンケート
            </div>

            <h1>
                <?= h($survey['title']) ?>
            </h1>

            <?php if (
                $survey['description'] !== ''
            ): ?>
                <p>
                    <?= nl2br(
                        h(
                            $survey['description']
                        )
                    ) ?>
                </p>
            <?php endif; ?>

            <form method="post">

                <input type="hidden"
                       name="action"
                       value="save_answer">

                <input type="hidden"
                       name="survey_id"
                       value="<?= h($id) ?>">

                <?php foreach (
                    $survey['groups']
                    as $group
                ): ?>

                    <section class="respondent-group">

                        <h2>
                            <?= h(
                                $group['title']
                            ) ?>
                        </h2>

                        <?php foreach (
                            $group['questions']
                            as $question
                        ): ?>

                            <div class="respondent-question">

                                <h3>
                                    <?= h(
                                        $question['number']
                                    ) ?>
                                    <?= h(
                                        $question['text']
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $question['required']
                                        )
                                    ): ?>
                                        <span class="required">
                                            必須
                                        </span>
                                    <?php endif; ?>
                                </h3>

                                <?php if (
                                    $question['type']
                                    === 'text'
                                ): ?>

                                    <textarea
                                        name="answers[<?= h(
                                            $question['id']
                                        ) ?>]"
                                        rows="5"
                                        <?= !empty(
                                            $question['required']
                                        )
                                            ? 'required'
                                            : '' ?>></textarea>

                                <?php else: ?>

                                    <?php foreach (
                                        $question['options']
                                        as $option
                                    ): ?>

                                        <label class="respondent-choice">

                                            <input
                                                type="<?= $question['type'] === 'single'
                                                    ? 'radio'
                                                    : 'checkbox' ?>"
                                                name="answers[<?= h(
                                                    $question['id']
                                                ) ?>]<?= $question['type'] === 'multiple'
                                                    ? '[]'
                                                    : '' ?>"
                                                value="<?= h(
                                                    $option['label']
                                                ) ?>"
                                                <?= $question['type'] === 'single'
                                                    && !empty(
                                                        $question['required']
                                                    )
                                                        ? 'required'
                                                        : '' ?>>

                                            <span>
                                                <?= h(
                                                    $option['label']
                                                ) ?>
                                            </span>

                                        </label>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                    </section>

                <?php endforeach; ?>

                <button class="btn btn-primary btn-large"
                        type="submit">
                    回答を確認する
                </button>

            </form>
        </div>
    </div>
    <?php
}

function render_answer_error(
    string $message
): void {
    ?>
    <div class="respondent-page">
        <div class="respondent-card">
            <div class="respondent-brand">
                アンケート
            </div>

            <div class="empty-state">
                <?= h($message) ?>
            </div>
        </div>
    </div>
    <?php
}

/* ============================================================
 * 完了
 * ============================================================ */

function render_complete(
    array $data
): void {
    ?>
    <div class="respondent-page">
        <div class="respondent-card complete-card">

            <div class="complete-icon">
                ✓
            </div>

            <h1>
                回答ありがとうございました
            </h1>

            <p>
                回答を正常に受け付けました。
            </p>

        </div>
    </div>
    <?php
}

/* ============================================================
 * メール設定画面
 * ============================================================ */

function render_mail(
    array $settings
): void {
    $m =
        $settings['mail'];
    ?>

    <div class="page-head">
        <div>
            <h1>メールサーバ設定</h1>
            <p class="muted">
                SMTPサーバを設定します。
            </p>
        </div>

        <span class="badge badge-gray">
            <?= h(
                $m['connection_status']
                ?? '未設定'
            ) ?>
        </span>
    </div>

    <div class="card">
        <form method="post">

            <input type="hidden"
                   name="action"
                   value="save_mail">

            <div class="form-grid">

                <label>
                    SMTPサーバ
                    <input type="text"
                           name="smtp_server"
                           value="<?= h(
                               $m['smtp_server']
                           ) ?>">
                </label>

                <label>
                    SMTPポート
                    <input type="number"
                           name="smtp_port"
                           min="1"
                           max="65535"
                           value="<?= h(
                               $m['smtp_port']
                           ) ?>">
                </label>

                <label>
                    暗号化方式
                    <select name="encryption">
                        <option value="tls"
                            <?= ($m['encryption'] ?? '')
                                === 'tls'
                                ? 'selected'
                                : '' ?>>
                            TLS
                        </option>

                        <option value="ssl"
                            <?= ($m['encryption'] ?? '')
                                === 'ssl'
                                ? 'selected'
                                : '' ?>>
                            SSL
                        </option>

                        <option value="none"
                            <?= ($m['encryption'] ?? '')
                                === 'none'
                                ? 'selected'
                                : '' ?>>
                            なし
                        </option>
                    </select>
                </label>

                <label class="check-label">
                    <input type="checkbox"
                           name="smtp_auth"
                           value="1"
                        <?= !empty($m['auth'])
                            ? 'checked'
                            : '' ?>>
                    SMTP認証
                </label>

                <label>
                    SMTPユーザー名
                    <input type="text"
                           name="smtp_username"
                           value="<?= h(
                               $m['username']
                           ) ?>">
                </label>

                <label>
                    SMTPパスワード
                    <input type="password"
                           name="smtp_password"
                           autocomplete="new-password"
                           placeholder="変更しない場合は空欄">
                </label>

                <label>
                    送信元メールアドレス
                    <input type="email"
                           name="from_email"
                           value="<?= h(
                               $m['from_email']
                           ) ?>">
                </label>

                <label>
                    送信元名
                    <input type="text"
                           name="from_name"
                           value="<?= h(
                               $m['from_name']
                           ) ?>">
                </label>

                <label>
                    返信先メールアドレス
                    <input type="email"
                           name="reply_to"
                           value="<?= h(
                               $m['reply_to']
                           ) ?>">
                </label>

            </div>

            <div class="button-row">
                <button class="btn btn-primary"
                        type="submit">
                    設定保存
                </button>

                <button class="btn btn-secondary"
                        type="button"
                        onclick="alert('SMTP接続テストはSMTP実装を有効化した環境で実行します。')">
                    接続テスト
                </button>

                <button class="btn btn-secondary"
                        type="button"
                        onclick="alert('テストメール送信はSMTP接続設定後に実行します。')">
                    テストメール送信
                </button>
            </div>

        </form>
    </div>
    <?php
}

/* ============================================================
 * CSS
 * ============================================================ */

function render_css(): void
{
    ?>
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
            --bg:#f8fafc;
        }

        * {
            box-sizing:border-box;
        }

        html,body {
            margin:0;
            padding:0;
            min-height:100%;
        }

        body {
            background:var(--bg);
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

        a {
            color:inherit;
            text-decoration:none;
        }

        button,
        input,
        textarea,
        select {
            font:inherit;
        }

        .admin-header {
            background:#0f172a;
            color:#fff;
            position:sticky;
            top:0;
            z-index:50;
        }

        .header-inner {
            max-width:1400px;
            margin:auto;
            min-height:64px;
            padding:0 24px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
        }

        .brand {
            display:flex;
            align-items:center;
            gap:10px;
            font-weight:700;
        }

        .brand-mark {
            width:32px;
            height:32px;
            border-radius:8px;
            background:var(--primary);
            display:grid;
            place-items:center;
        }

        .main-nav {
            display:flex;
            gap:4px;
        }

        .main-nav a {
            padding:10px 14px;
            border-radius:7px;
            color:#cbd5e1;
        }

        .main-nav a:hover,
        .main-nav a.active {
            color:#fff;
            background:rgba(255,255,255,.1);
        }

        .container {
            width:min(1400px,calc(100% - 40px));
            margin:0 auto;
        }

        main.container {
            padding:32px 0 70px;
        }

        .page-head {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:20px;
            margin-bottom:24px;
        }

        h1,h2,h3,h4 {
            margin-top:0;
        }

        h1 {
            font-size:28px;
            margin-bottom:5px;
        }

        h2 {
            font-size:19px;
        }

        h3 {
            font-size:17px;
        }

        .muted {
            color:var(--gray);
        }

        .card {
            background:#fff;
            border:1px solid var(--border);
            border-radius:12px;
            box-shadow:var(--shadow);
            padding:24px;
            margin-bottom:20px;
        }

        .card-header {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
            margin-bottom:20px;
        }

        .card-header h2 {
            margin:0;
        }

        .btn {
            display:inline-flex;
            justify-content:center;
            align-items:center;
            gap:7px;
            min-height:40px;
            padding:8px 15px;
            border-radius:8px;
            border:1px solid var(--border);
            background:#fff;
            color:var(--text);
            cursor:pointer;
            transition:.15s;
        }

        .btn:hover {
            transform:translateY(-1px);
            box-shadow:0 2px 8px rgba(15,23,42,.08);
        }

        .btn-primary {
            background:var(--primary);
            border-color:var(--primary);
            color:#fff;
        }

        .btn-primary:hover {
            background:var(--primary-dark);
        }

        .btn-secondary {
            background:#fff;
        }

        .btn-danger {
            color:#fff;
            background:var(--danger);
            border-color:var(--danger);
        }

        .btn-small {
            min-height:32px;
            padding:5px 10px;
            font-size:13px;
        }

        .btn-large {
            min-height:50px;
            width:100%;
            font-size:17px;
        }

        .button-row,
        .actions,
        .connection-actions {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
        }

        .inline-form {
            display:inline;
        }

        .filter-bar {
            display:flex;
            gap:10px;
            margin-bottom:20px;
            flex-wrap:wrap;
        }

        .filter-bar .search-input {
            flex:1 1 300px;
        }

        input,
        textarea,
        select {
            width:100%;
            border:1px solid #cbd5e1;
            border-radius:8px;
            background:#fff;
            padding:10px 12px;
            color:var(--text);
            outline:none;
        }

        input:focus,
        textarea:focus,
        select:focus {
            border-color:var(--primary);
            box-shadow:0 0 0 3px rgba(37,99,235,.12);
        }

        label {
            display:flex;
            flex-direction:column;
            gap:6px;
            font-weight:600;
        }

        label > small {
            font-weight:400;
            color:var(--gray);
        }

        .form-grid {
            display:grid;
            grid-template-columns:
                repeat(2,minmax(0,1fr));
            gap:18px;
        }

        .form-grid label:nth-child(2) {
            grid-column:span 2;
        }

        .check-label {
            flex-direction:row;
            align-items:center;
            width:max-content;
        }

        .check-label input {
            width:auto;
        }

        .table-card {
            padding:0;
            overflow:hidden;
        }

        .table-scroll {
            overflow-x:auto;
        }

        table {
            width:100%;
            min-width:1000px;
            border-collapse:collapse;
        }

        th,
        td {
            padding:14px 16px;
            border-bottom:1px solid var(--border);
            text-align:left;
            vertical-align:top;
        }

        th {
            background:#f8fafc;
            font-size:13px;
            color:#475569;
        }

        .empty-cell,
        .empty-state {
            text-align:center;
            color:var(--gray);
            padding:50px 20px;
        }

        .badge {
            display:inline-flex;
            align-items:center;
            padding:4px 9px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
        }

        .badge-success {
            background:#dcfce7;
            color:#166534;
        }

        .badge-warning {
            background:#fef3c7;
            color:#92400e;
        }

        .badge-danger {
            background:#fee2e2;
            color:#991b1b;
        }

        .badge-gray {
            background:#e2e8f0;
            color:#475569;
        }

        .alert {
            white-space:normal;
            padding:15px 18px;
            border-radius:10px;
            margin-bottom:20px;
            border:1px solid;
        }

        .alert-success {
            color:#166534;
            background:#f0fdf4;
            border-color:#bbf7d0;
        }

        .alert-error {
            color:#991b1b;
            background:#fef2f2;
            border-color:#fecaca;
        }

        .alert-warning {
            color:#92400e;
            background:#fffbeb;
            border-color:#fde68a;
        }

        .alert-info {
            color:#1e40af;
            background:#eff6ff;
            border-color:#bfdbfe;
        }

        .group-card {
            border:1px solid var(--border);
            border-radius:10px;
            padding:18px;
            margin-bottom:16px;
            background:#f8fafc;
        }

        .group-header,
        .question-header {
            display:flex;
            align-items:center;
            gap:10px;
            margin-bottom:14px;
        }

        .group-header input {
            flex:1;
        }

        .question-card {
            background:#fff;
            border:1px solid var(--border);
            border-radius:10px;
            padding:16px;
            margin-bottom:12px;
        }

        .question-header {
            justify-content:flex-start;
        }

        .question-header .btn {
            margin-left:auto;
        }

        .question-number {
            font-weight:700;
            color:var(--primary);
        }

        .drag-handle {
            cursor:grab;
            color:#94a3b8;
            font-size:20px;
        }

        .question-row {
            display:grid;
            grid-template-columns:1fr auto;
            align-items:end;
            gap:20px;
            margin-top:15px;
        }

        .options-box {
            margin-top:15px;
            padding:15px;
            background:#f8fafc;
            border-radius:8px;
        }

        .options-box input {
            margin-top:8px;
        }

        .options-box button {
            margin-top:10px;
        }

        .sticky-actions {
            position:sticky;
            bottom:0;
            z-index:20;
            background:rgba(255,255,255,.96);
            border-top:1px solid var(--border);
            padding:15px;
            display:flex;
            justify-content:flex-end;
            gap:10px;
        }

        .info-box {
            padding:18px;
            border-radius:10px;
            background:#eff6ff;
            border:1px solid #bfdbfe;
            color:#1e3a8a;
            margin-bottom:20px;
        }

        .info-box li {
            margin:4px 0;
        }

        .connection-actions {
            margin-bottom:20px;
        }

        .connection-form {
            display:flex;
        }

        .test-button .spinner {
            display:none;
        }

        .testing .spinner {
            display:inline-block;
        }

        .spinner {
            width:15px;
            height:15px;
            border:2px solid rgba(255,255,255,.4);
            border-top-color:#fff;
            border-radius:50%;
            animation:spin .7s linear infinite;
        }

        @keyframes spin {
            to {
                transform:rotate(360deg);
            }
        }

        .sync-summary {
            padding:15px;
            border-radius:8px;
            background:#f8fafc;
            color:#475569;
        }

        .stats-grid {
            display:grid;
            grid-template-columns:
                repeat(4,minmax(0,1fr));
            gap:16px;
            margin-bottom:20px;
        }

        .stat-card {
            background:#fff;
            border:1px solid var(--border);
            box-shadow:var(--shadow);
            border-radius:12px;
            padding:20px;
        }

        .stat-card span {
            display:block;
            color:var(--gray);
            font-size:13px;
        }

        .stat-card strong {
            display:block;
            font-size:30px;
            margin-top:5px;
        }

        .analytics-group {
            margin-bottom:30px;
        }

        .question-summary {
            padding:18px;
            border:1px solid var(--border);
            border-radius:10px;
            margin-bottom:12px;
        }

        .bar-row {
            display:grid;
            grid-template-columns:
                180px 1fr 50px;
            gap:10px;
            align-items:center;
            margin:8px 0;
        }

        .bar {
            height:12px;
            background:#e2e8f0;
            border-radius:999px;
            overflow:hidden;
        }

        .bar div {
            height:100%;
            background:var(--primary);
        }

        .customer-list {
            border:1px solid var(--border);
            border-radius:8px;
            margin-bottom:20px;
        }

        .customer-row {
            flex-direction:row;
            align-items:center;
            padding:12px;
            border-bottom:1px solid var(--border);
            font-weight:400;
        }

        .customer-row:last-child {
            border-bottom:0;
        }

        .customer-row input {
            width:auto;
        }

        .customer-row span {
            display:flex;
            flex-direction:column;
        }

        .customer-row small {
            color:var(--gray);
        }

        .answer-preview {
            max-width:900px;
            margin:0 auto;
            background:#fff;
            border:1px solid var(--border);
            border-radius:12px;
            padding:30px;
            box-shadow:var(--shadow);
        }

        .preview-group {
            margin-top:30px;
        }

        .preview-question {
            border:1px solid var(--border);
            border-radius:10px;
            padding:20px;
            margin-bottom:14px;
        }

        .choice {
            flex-direction:row;
            align-items:center;
            font-weight:400;
            margin:8px 0;
        }

        .choice input {
            width:auto;
        }

        .required {
            color:var(--danger);
            font-size:12px;
            margin-left:6px;
        }

        .respondent-page {
            min-height:100vh;
            padding:25px 15px;
            background:#f8fafc;
        }

        .respondent-card {
            max-width:760px;
            margin:0 auto;
            background:#fff;
            border-radius:14px;
            padding:25px;
            box-shadow:var(--shadow);
        }

        .respondent-brand {
            display:inline-block;
            background:var(--primary);
            color:#fff;
            padding:5px 10px;
            border-radius:7px;
            font-size:12px;
            font-weight:700;
            margin-bottom:15px;
        }

        .respondent-group {
            margin-top:30px;
        }

        .respondent-question {
            margin-top:20px;
            padding:18px;
            border:1px solid var(--border);
            border-radius:10px;
        }

        .respondent-choice {
            flex-direction:row;
            align-items:center;
            gap:10px;
            padding:12px;
            border:1px solid var(--border);
            border-radius:8px;
            margin-top:8px;
            cursor:pointer;
        }

        .respondent-choice input {
            width:auto;
            flex:none;
        }

        .complete-card {
            text-align:center;
            margin-top:10vh;
        }

        .complete-icon {
            width:70px;
            height:70px;
            margin:0 auto 20px;
            border-radius:50%;
            background:#dcfce7;
            color:#16a34a;
            display:grid;
            place-items:center;
            font-size:40px;
            font-weight:700;
        }

        .footer {
            padding:25px 0;
            color:#94a3b8;
            font-size:12px;
        }

        code {
            background:#f1f5f9;
            padding:3px 6px;
            border-radius:4px;
        }

        .sr-only {
            position:absolute;
            width:1px;
            height:1px;
            padding:0;
            margin:-1px;
            overflow:hidden;
            clip:rect(0,0,0,0);
            white-space:nowrap;
            border:0;
        }

        @media (max-width:900px) {
            .form-grid,
            .stats-grid {
                grid-template-columns:1fr;
            }

            .form-grid label:nth-child(2) {
                grid-column:auto;
            }

            .header-inner {
                align-items:flex-start;
                padding-top:10px;
                padding-bottom:10px;
                flex-direction:column;
            }

            .main-nav {
                width:100%;
                overflow-x:auto;
            }

            .page-head {
                align-items:flex-start;
                flex-direction:column;
            }

            .bar-row {
                grid-template-columns:
                    100px 1fr 40px;
            }
        }

        @media (max-width:600px) {
            .container {
                width:min(
                    100% - 24px,
                    1400px
                );
            }

            main.container {
                padding-top:20px;
            }

            .card {
                padding:16px;
            }

            h1 {
                font-size:24px;
            }

            .question-row {
                grid-template-columns:1fr;
            }

            .answer-preview,
            .respondent-card {
                padding:18px;
            }

            .sticky-actions {
                justify-content:stretch;
            }

            .sticky-actions .btn {
                flex:1;
            }
        }
    </style>
    <?php
}

/* ============================================================
 * JavaScript
 * ============================================================ */

function render_javascript(): void
{
    ?>
    <script>
        function startConnectionTest(form) {
            const button =
                form.querySelector('.test-button');

            if (button) {
                button.classList.add('testing');
                button.disabled = true;

                const original =
                    button.textContent;

                button.dataset.original =
                    original;

                button.innerHTML =
                    '<span class="spinner"></span> 接続確認中…';
            }

            /*
             * 接続テスト中は同じフォームを
             * 二重送信しない。
             */
            return true;
        }

        function addOption(button) {
            const box =
                button.closest('.options-box');

            if (!box) return;

            const input =
                document.createElement('input');

            input.type = 'text';
            input.name = 'options[]';
            input.placeholder = '選択肢';

            box.insertBefore(
                input,
                button
            );
        }

        function removeQuestion(button) {
            const question =
                button.closest('.question-card');

            if (!question) return;

            if (
                confirm('この質問を削除しますか？')
            ) {
                question.remove();
                renumberQuestions();
            }
        }

        function removeGroup(button) {
            const group =
                button.closest('.group-card');

            if (!group) return;

            if (
                confirm('このグループを削除しますか？')
            ) {
                group.remove();
                renumberQuestions();
            }
        }

        function addQuestion(button) {
            const group =
                button.closest('.group-card');

            if (!group) return;

            const question =
                document.createElement('div');

            question.className =
                'question-card';

            question.draggable = true;

            question.innerHTML = `
                <div class="question-header">
                    <span class="question-number">Q</span>
                    <span class="drag-handle">☷</span>
                    <button type="button"
                            class="btn btn-small btn-danger"
                            onclick="removeQuestion(this)">
                        質問削除
                    </button>
                </div>

                <label>
                    質問文
                    <input type="text"
                           name="question_text[]">
                </label>

                <div class="question-row">
                    <label>
                        回答形式
                        <select name="question_type[]">
                            <option value="single">
                                単一選択
                            </option>
                            <option value="multiple">
                                複数選択
                            </option>
                            <option value="text">
                                自由記述
                            </option>
                        </select>
                    </label>

                    <label class="check-label">
                        <input type="checkbox"
                               name="question_required[]"
                               value="1">
                        必須
                    </label>
                </div>

                <div class="options-box">
                    <strong>選択肢</strong>

                    <input type="text"
                           name="options[]"
                           placeholder="選択肢">

                    <input type="text"
                           name="options[]"
                           placeholder="選択肢">

                    <button type="button"
                            class="btn btn-small"
                            onclick="addOption(this)">
                        ＋ 選択肢追加
                    </button>
                </div>
            `;

            group.insertBefore(
                question,
                button
            );

            renumberQuestions();
        }

        function addGroup() {
            const container =
                document.getElementById('groups');

            if (!container) return;

            const addButton =
                container.querySelector(
                    ':scope > .btn'
                );

            const group =
                document.createElement('div');

            group.className =
                'group-card';

            group.draggable = true;

            group.innerHTML = `
                <div class="group-header">
                    <span class="drag-handle">☷</span>

                    <input type="text"
                           name="group_titles[]"
                           value="新しいグループ"
                           placeholder="グループタイトル">

                    <button type="button"
                            class="btn btn-small btn-danger"
                            onclick="removeGroup(this)">
                        グループ削除
                    </button>
                </div>

                <div class="question-card"
                     draggable="true">

                    <div class="question-header">
                        <span class="question-number">
                            Q
                        </span>

                        <span class="drag-handle">
                            ☷
                        </span>

                        <button type="button"
                                class="btn btn-small btn-danger"
                                onclick="removeQuestion(this)">
                            質問削除
                        </button>
                    </div>

                    <label>
                        質問文
                        <input type="text"
                               name="question_text[]">
                    </label>

                    <div class="question-row">
                        <label>
                            回答形式
                            <select name="question_type[]">
                                <option value="single">
                                    単一選択
                                </option>
                                <option value="multiple">
                                    複数選択
                                </option>
                                <option value="text">
                                    自由記述
                                </option>
                            </select>
                        </label>

                        <label class="check-label">
                            <input type="checkbox"
                                   name="question_required[]"
                                   value="1">
                            必須
                        </label>
                    </div>

                    <div class="options-box">
                        <strong>選択肢</strong>

                        <input type="text"
                               name="options[]"
                               placeholder="選択肢">

                        <input type="text"
                               name="options[]"
                               placeholder="選択肢">

                        <button type="button"
                                class="btn btn-small"
                                onclick="addOption(this)">
                            ＋ 選択肢追加
                        </button>
                    </div>
                </div>

                <button type="button"
                        class="btn btn-secondary add-question"
                        onclick="addQuestion(this)">
                    ＋ 質問を追加
                </button>
            `;

            if (addButton) {
                container.insertBefore(
                    group,
                    addButton
                );
            } else {
                container.appendChild(group);
            }

            renumberQuestions();
        }

        function renumberQuestions() {
            let number = 1;

            document
                .querySelectorAll(
                    '.group-card'
                )
                .forEach((group, groupIndex) => {

                    let local = 1;

                    group.querySelectorAll(
                        '.question-card'
                    ).forEach(question => {

                        const target =
                            question.querySelector(
                                '.question-number'
                            );

                        if (!target) return;

                        const numbering =
                            document.querySelector(
                                '[name="numbering"]'
                            );

                        if (
                            numbering
                            && numbering.value === 'group'
                        ) {
                            target.textContent =
                                'Q' +
                                (groupIndex + 1) +
                                '-' +
                                local;

                            local++;
                        } else {
                            target.textContent =
                                'Q' +
                                number;

                            number++;
                        }
                    });
                });
        }

        document.addEventListener(
            'DOMContentLoaded',
            () => {
                const numbering =
                    document.querySelector(
                        '[name="numbering"]'
                    );

                if (numbering) {
                    numbering.addEventListener(
                        'change',
                        renumberQuestions
                    );
                }

                renumberQuestions();
            }
        );
    </script>
    <?php
}

/* ============================================================
 * HTML出力
 * ============================================================ */

$isRespondentScreen =
    in_array(
        $screen,
        ['answer', 'confirm', 'complete'],
        true
    );

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width,initial-scale=1">

    <title>
        <?= h(
            page_title($screen)
        ) ?>
        -
        <?= h(APP_TITLE) ?>
    </title>

    <?php render_css(); ?>
</head>

<body>

<?php if ($isRespondentScreen): ?>

    <?php if ($screen === 'answer'): ?>

        <?php render_answer($data); ?>

    <?php elseif ($screen === 'complete'): ?>

        <?php render_complete($data); ?>

    <?php else: ?>

        <?php render_answer_error(
            'この画面は現在利用できません。'
        ); ?>

    <?php endif; ?>

<?php else: ?>

    <?php render_admin_header($screen); ?>

    <main class="container">

        <?php render_flash($flashes); ?>

        <?php
        switch ($screen) {

            case 'edit':
                render_edit($data);
                break;

            case 'preview':
                render_preview($data);
                break;

            case 'analytics':
                render_analytics($data);
                break;

            case 'send':
                render_send($data);
                break;

            case 'kintone':
                render_kintone(
                    $settings,
                    $data
                );
                break;

            case 'mail':
                render_mail($settings);
                break;

            case 'list':
            default:
                render_list($data);
                break;
        }
        ?>

    </main>

    <?php render_admin_footer(); ?>

<?php endif; ?>

<?php render_javascript(); ?>

</body>
</html>
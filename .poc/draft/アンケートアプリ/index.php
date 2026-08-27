<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * prompt.txt に基づく単一エントリーポイント。
 *
 * 重要:
 * - DBなし
 * - 管理者認証なし
 * - CSRFなし（要件）
 * - PHP cURLなし
 * - PHP mail()なし
 * - kintone: X-Cybozu-Authorization
 * - SMTP: ソケット通信
 * - サーバー側JSON永続化
 * - 通常GETでsession_regenerate_id()しない
 * - kintone/メール設定保存ではPOST→303→GET→flashを使用しない
 * - 外部URLへのリダイレクトを許可しない
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR       = __DIR__ . '/data';
const SETTINGS_FILE  = DATA_DIR . '/settings.json';
const SURVEYS_FILE   = DATA_DIR . '/surveys.json';
const CUSTOMERS_FILE = DATA_DIR . '/customers.json';
const ANSWERS_FILE   = DATA_DIR . '/answers.json';
const SEND_LOG_FILE  = DATA_DIR . '/send_logs.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT    = 20;


/* ============================================================
 * セッション
 * ============================================================ */

/*
 * 日本語を含む SCRIPT_NAME / REQUEST_URI を
 * Cookie Path に直接使用しない。
 *
 * 公開URLが
 * /gojacic/.poc/draft/アンケートアプリ/index.php
 * のような構成でも、Cookie Path は "/" に固定する。
 *
 * これにより、
 * - URLエンコード差異
 * - 日本語パス
 * - Apache側のURL表現差
 *
 * によるセッションCookie不整合を防止する。
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できません。');
    }
}

/*
 * 通常GETでは絶対にsession_regenerate_id()しない。
 *
 * POCでは管理者認証が存在しないため、
 * セッションIDを再生成する理由がない。
 */


/* ============================================================
 * 初期データ
 * ============================================================ */

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'データ保存領域を作成できません。'
            );
        }
    }
}

function ensure_json_file(
    string $file,
    array $default
): void {
    if (!file_exists($file)) {
        write_json_atomic($file, $default);
    }
}

ensure_data_dir();

ensure_json_file(SETTINGS_FILE, [
    'kintone' => [
        'subdomain' => '',
        'app_id' => '',
        'username' => '',
        'password' => '',
        'proxy' => '',
        'verify_ssl' => false,
        'field_mapping' => [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => [],
        ],
        'fields' => [],
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
        'status' => '未設定',
    ],
]);

ensure_json_file(SURVEYS_FILE, []);
ensure_json_file(CUSTOMERS_FILE, []);
ensure_json_file(ANSWERS_FILE, []);
ensure_json_file(SEND_LOG_FILE, []);


/* ============================================================
 * JSON永続化
 * ============================================================ */

function read_json(string $file, mixed $default): mixed
{
    if (!is_file($file)) {
        return $default;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        throw new RuntimeException(
            'データファイルを開けません。'
        );
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        throw new RuntimeException(
            'データファイルをロックできません。'
        );
    }

    $json = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($json === false || trim($json) === '') {
        return $default;
    }

    $data = json_decode(
        $json,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    return $data;
}

function write_json_atomic(
    string $file,
    mixed $data
): void {
    $dir = dirname($file);

    if (!is_dir($dir)) {
        throw new RuntimeException(
            '保存先ディレクトリがありません。'
        );
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
    );

    $fp = @fopen($tmp, 'xb');

    if ($fp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                '一時ファイルをロックできません。'
            );
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException(
                'データを書き込めません。'
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            throw new RuntimeException(
                'データファイルを置き換えられません。'
            );
        }
    } catch (Throwable $e) {
        fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}


/* ============================================================
 * 共通
 * ============================================================ */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function post_string(
    string $key,
    string $default = ''
): string {
    $value = $_POST[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function get_string(
    string $key,
    string $default = ''
): string {
    $value = $_GET[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function valid_id(string $id): bool
{
    return preg_match(
        '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/',
        $id
    ) === 1;
}

function new_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function now_iso(): string
{
    return date('c');
}

function parse_datetime(string $value): ?DateTimeImmutable
{
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable(
            $value,
            new DateTimeZone('Asia/Tokyo')
        );
    } catch (Throwable) {
        return null;
    }
}

function public_error(Throwable $e): string
{
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }

    return '処理に失敗しました。入力内容または設定を確認してください。';
}


/* ============================================================
 * URL
 * ============================================================ */

function screen_url(
    string $screen,
    ?string $id = null
): string {
    $allowed = [
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

    if (!in_array($screen, $allowed, true)) {
        $screen = 'list';
    }

    $url = 'index.php?screen=' . rawurlencode($screen);

    if ($id !== null && $id !== '') {
        $url .= '&id=' . rawurlencode($id);
    }

    return $url;
}

/*
 * 303を使用する場合も、ユーザー入力をLocationへ
 * 直接入れない。
 *
 * ただし kintone / mail の設定保存では
 * この関数自体を使用しない。
 */
function redirect303(string $url): never
{
    if (
        !str_starts_with($url, 'index.php')
        || str_contains($url, "\r")
        || str_contains($url, "\n")
    ) {
        $url = screen_url('list');
    }

    header(
        'Cache-Control: no-store, no-cache, must-revalidate'
    );
    header('Pragma: no-cache');
    header('Location: ' . $url, true, 303);

    exit;
}


/* ============================================================
 * アンケート
 * ============================================================ */

function load_surveys(): array
{
    $data = read_json(SURVEYS_FILE, []);

    if (!is_array($data)) {
        throw new RuntimeException(
            'アンケートデータが不正です。'
        );
    }

    return array_values($data);
}

function save_surveys(array $surveys): void
{
    write_json_atomic(
        SURVEYS_FILE,
        array_values($surveys)
    );
}

function find_survey(
    array $surveys,
    string $id
): ?array {
    foreach ($surveys as $survey) {
        if (
            is_array($survey)
            && ($survey['id'] ?? '') === $id
        ) {
            return $survey;
        }
    }

    return null;
}

function find_survey_index(
    array $surveys,
    string $id
): int {
    foreach ($surveys as $i => $survey) {
        if (
            is_array($survey)
            && ($survey['id'] ?? '') === $id
        ) {
            return $i;
        }
    }

    return -1;
}

function normalize_survey(
    array $survey
): array {
    $survey['id'] = (string)(
        $survey['id']
        ?? new_id('survey')
    );

    $survey['title'] = (string)(
        $survey['title'] ?? ''
    );

    $survey['description'] = (string)(
        $survey['description'] ?? ''
    );

    $survey['startAt'] = (string)(
        $survey['startAt'] ?? ''
    );

    $survey['endAt'] = (string)(
        $survey['endAt'] ?? ''
    );

    $survey['status'] = (string)(
        $survey['status'] ?? 'draft'
    );

    if (!in_array(
        $survey['status'],
        ['draft', 'published', 'stopped', 'ended'],
        true
    )) {
        $survey['status'] = 'draft';
    }

    $survey['numbering'] = (string)(
        $survey['numbering'] ?? 'global'
    );

    if (!in_array(
        $survey['numbering'],
        ['global', 'group'],
        true
    )) {
        $survey['numbering'] = 'global';
    }

    $survey['groups'] =
        is_array($survey['groups'] ?? null)
        ? $survey['groups']
        : [];

    $survey['createdAt'] =
        (string)($survey['createdAt'] ?? now_iso());

    $survey['updatedAt'] =
        (string)($survey['updatedAt'] ?? now_iso());

    return $survey;
}

function auto_update_status(
    array &$survey
): bool {
    $changed = false;

    if (
        ($survey['status'] ?? '') === 'published'
        && ($survey['endAt'] ?? '') !== ''
    ) {
        $end = parse_datetime(
            (string)$survey['endAt']
        );

        if (
            $end !== null
            && $end->getTimestamp() < time()
        ) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now_iso();
            $changed = true;
        }
    }

    return $changed;
}

function auto_update_all_statuses(
    array &$surveys
): void {
    $changed = false;

    foreach ($surveys as &$survey) {
        if (!is_array($survey)) {
            continue;
        }

        $survey = normalize_survey($survey);

        if (auto_update_status($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        save_surveys($surveys);
    }
}


/* ============================================================
 * 質問番号
 * ============================================================ */

function recalculate_question_numbers(
    array &$survey
): void {
    $global = 0;

    foreach (
        $survey['groups'] as $gi => &$group
    ) {
        $groupNumber = $gi + 1;

        if (!isset($group['questions'])
            || !is_array($group['questions'])
        ) {
            $group['questions'] = [];
        }

        foreach (
            $group['questions'] as $qi => &$question
        ) {
            $global++;

            if (
                ($survey['numbering'] ?? 'global')
                === 'group'
            ) {
                $question['number'] =
                    'Q'
                    . $groupNumber
                    . '-'
                    . ($qi + 1);
            } else {
                $question['number'] =
                    'Q' . $global;
            }
        }

        unset($question);
    }

    unset($group);
}


/* ============================================================
 * kintone
 * ============================================================ */

function normalize_subdomain(
    string $value
): string {
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim(
        $value,
        "/ \t\n\r\0\x0B"
    );

    if (
        str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {
        $value = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    if (
        !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/',
            $value
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    return $value;
}

function normalize_proxy(
    string $proxy
): ?array {
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (
        !preg_match(
            '/^([^:\s]+):([0-9]{1,5})$/',
            $proxy,
            $m
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyは host:port 形式で入力してください。'
        );
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'Proxyポート番号が不正です。'
        );
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

function kintone_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $payload = null
): array {
    $subdomain = normalize_subdomain(
        (string)($settings['subdomain'] ?? '')
    );

    $appId = (string)(
        $settings['app_id'] ?? ''
    );

    $username = (string)(
        $settings['username'] ?? ''
    );

    $password = (string)(
        $settings['password'] ?? ''
    );

    if (
        $subdomain === ''
        || !ctype_digit($appId)
        || $username === ''
        || $password === ''
    ) {
        throw new InvalidArgumentException(
            'kintone接続設定が不足しています。'
        );
    }

    $url =
        'https://'
        . $subdomain
        . '.cybozu.com'
        . $path;

    /*
     * 要件:
     * X-Cybozu-Authorizationはサーバー側だけで生成。
     */
    $authorization = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode(
                "\r\n",
                $headers
            ),
            'timeout' => READ_TIMEOUT,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' =>
                (bool)($settings['verify_ssl'] ?? false),
            'verify_peer_name' =>
                (bool)($settings['verify_ssl'] ?? false),
            'allow_self_signed' =>
                !((bool)($settings['verify_ssl'] ?? false)),
        ],
    ];

    $proxy = normalize_proxy(
        (string)($settings['proxy'] ?? '')
    );

    if ($proxy !== null) {
        $contextOptions['http']['proxy'] =
            'tcp://'
            . $proxy['host']
            . ':'
            . $proxy['port'];

        $contextOptions['http']['request_fulluri'] =
            true;
    }

    if ($payload !== null) {
        $contextOptions['http']['content'] =
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
    }

    $context = stream_context_create(
        $contextOptions
    );

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへ接続できませんでした。'
        );
    }

    $status = 0;

    foreach (
        $http_response_header ?? []
        as $header
    ) {
        if (
            preg_match(
                '#^HTTP/\S+\s+(\d{3})#',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    $decoded = json_decode(
        $response,
        true
    );

    if ($status < 200 || $status >= 300) {
        $message =
            is_array($decoded)
            ? (string)($decoded['message'] ?? '')
            : '';

        if ($message === '') {
            $message =
                'kintoneからHTTP '
                . $status
                . ' が返されました。';
        }

        throw new RuntimeException(
            $message
        );
    }

    return is_array($decoded)
        ? $decoded
        : [];
}


/* ============================================================
 * kintone接続テスト
 * ============================================================ */

function test_kintone(
    array $settings
): array {
    $result = kintone_request(
        $settings,
        '/k/v1/app.json?app='
        . rawurlencode(
            (string)$settings['app_id']
        )
    );

    return [
        'success' => true,
        'message' => 'kintoneへの接続に成功しました。',
        'data' => $result,
    ];
}


/* ============================================================
 * kintone項目取得
 * ============================================================ */

function get_kintone_fields(
    array $settings
): array {
    $result = kintone_request(
        $settings,
        '/k/v1/app/form/fields.json?app='
        . rawurlencode(
            (string)$settings['app_id']
        )
    );

    return is_array(
        $result['properties'] ?? null
    )
        ? $result['properties']
        : [];
}


/* ============================================================
 * kintone顧客同期
 * ============================================================ */

function sync_kintone_customers(
    array $settings,
    array $mapping
): int {
    $query = [];

    $offset = 0;
    $total = 0;
    $customers = [];

    do {
        $params = [
            'app' => (string)$settings['app_id'],
            'query' => 'order by $id asc limit 500 offset '
                . $offset,
        ];

        $result = kintone_request(
            $settings,
            '/k/v1/records.json?'
            . http_build_query($params)
        );

        $records =
            is_array($result['records'] ?? null)
            ? $result['records']
            : [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $get = static function (
                array $record,
                string $field
            ): string {
                if (
                    $field === ''
                    || !isset($record[$field])
                ) {
                    return '';
                }

                $value = $record[$field]['value']
                    ?? '';

                if (is_array($value)) {
                    return implode(
                        ', ',
                        array_map(
                            'strval',
                            $value
                        )
                    );
                }

                return (string)$value;
            };

            $customers[] = [
                'id' =>
                    'kintone-'
                    . ($record['$id']['value'] ?? uniqid()),
                'organization' =>
                    $get(
                        $record,
                        (string)(
                            $mapping['organization']
                            ?? ''
                        )
                    ),
                'name' =>
                    $get(
                        $record,
                        (string)(
                            $mapping['name'] ?? ''
                        )
                    ),
                'email' =>
                    $get(
                        $record,
                        (string)(
                            $mapping['email'] ?? ''
                        )
                    ),
                'department' =>
                    $get(
                        $record,
                        (string)(
                            $mapping['department']
                            ?? ''
                        )
                    ),
                'phone' =>
                    $get(
                        $record,
                        (string)(
                            $mapping['phone'] ?? ''
                        )
                    ),
                'address' =>
                    $get(
                        $record,
                        (string)(
                            $mapping['address'][0]
                            ?? ''
                        )
                    ),
                'updatedAt' => now_iso(),
            ];
        }

        $count = count($records);
        $total += $count;
        $offset += $count;
    } while ($count > 0);

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    return count($customers);
}


/* ============================================================
 * POST処理
 * ============================================================ */

$screen = get_string(
    'screen',
    'list'
);

$method = strtoupper(
    $_SERVER['REQUEST_METHOD'] ?? 'GET'
);

$surveys = load_surveys();
auto_update_all_statuses($surveys);


/* ============================================================
 * kintone設定保存
 *
 * ここが今回の303再発防止の重要箇所。
 *
 * 「POST → 303 → GET → flash」にはしない。
 *
 * 保存後、そのPOSTリクエスト内で同じ画面を再描画する。
 * ============================================================ */

if (
    $method === 'POST'
    && post_string('action') === 'save_kintone'
) {
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $current =
        is_array($settings['kintone'] ?? null)
        ? $settings['kintone']
        : [];

    try {
        $subdomain = normalize_subdomain(
            post_string('subdomain')
        );

        $appId = post_string('app_id');

        if (!ctype_digit($appId)) {
            throw new InvalidArgumentException(
                '顧客管理アプリIDは数値で入力してください。'
            );
        }

        $username = post_string('username');

        if ($username === '') {
            throw new InvalidArgumentException(
                'ログイン名を入力してください。'
            );
        }

        /*
         * パスワード未入力の場合は既存値を維持。
         * 画面へ既存パスワードは出力しない。
         */
        $password =
            post_string('password');

        if ($password === '') {
            $password =
                (string)($current['password'] ?? '');
        }

        if ($password === '') {
            throw new InvalidArgumentException(
                'パスワードを入力してください。'
            );
        }

        $proxy = post_string('proxy');

        normalize_proxy($proxy);

        $settings['kintone'] = [
            'subdomain' => $subdomain,
            'app_id' => $appId,
            'username' => $username,
            'password' => $password,
            'proxy' => $proxy,
            'verify_ssl' =>
                isset($_POST['verify_ssl']),
            'field_mapping' =>
                is_array(
                    $current['field_mapping'] ?? null
                )
                    ? $current['field_mapping']
                    : [],
            'fields' =>
                is_array(
                    $current['fields'] ?? null
                )
                    ? $current['fields']
                    : [],
        ];

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        /*
         * PRGにしない。
         * 同一POST内で結果を表示する。
         */
        $kintoneMessage =
            'kintone設定を保存しました。';

        $kintoneError = null;
    } catch (Throwable $e) {
        $kintoneMessage = null;
        $kintoneError = public_error($e);
    }
}


/* ============================================================
 * kintone接続テスト
 * ============================================================ */

if (
    $method === 'POST'
    && post_string('action') === 'test_kintone'
) {
    try {
        $settings = read_json(
            SETTINGS_FILE,
            []
        );

        $k =
            $settings['kintone']
            ?? [];

        /*
         * 保存済み設定を使う。
         * POSTフォームの認証情報をJavaScriptへ渡さない。
         */
        $test = test_kintone($k);

        $kintoneTestMessage =
            $test['message'];
        $kintoneTestError = null;
    } catch (Throwable $e) {
        $kintoneTestMessage = null;
        $kintoneTestError =
            public_error($e);
    }
}


/* ============================================================
 * kintone項目再取得
 * ============================================================ */

if (
    $method === 'POST'
    && post_string('action') === 'refresh_kintone_fields'
) {
    try {
        $settings = read_json(
            SETTINGS_FILE,
            []
        );

        $k =
            $settings['kintone']
            ?? [];

        $fields = get_kintone_fields($k);

        $settings['kintone']['fields'] =
            $fields;

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        $kintoneFieldsMessage =
            'kintoneの項目一覧を再取得しました。';
        $kintoneFieldsError = null;
    } catch (Throwable $e) {
        $kintoneFieldsMessage = null;
        $kintoneFieldsError =
            public_error($e);
    }
}


/* ============================================================
 * kintone顧客同期
 * ============================================================ */

if (
    $method === 'POST'
    && post_string('action') === 'sync_kintone'
) {
    try {
        $settings = read_json(
            SETTINGS_FILE,
            []
        );

        $k =
            $settings['kintone']
            ?? [];

        $mapping =
            $k['field_mapping']
            ?? [];

        $count =
            sync_kintone_customers(
                $k,
                $mapping
            );

        $kintoneSyncMessage =
            $count
            . '件の顧客情報を同期しました。';

        $kintoneSyncError = null;
    } catch (Throwable $e) {
        $kintoneSyncMessage = null;
        $kintoneSyncError =
            public_error($e);
    }
}


/* ============================================================
 * メール設定保存
 *
 * kintone同様にPOST→303→GET→flashを使用しない。
 * ============================================================ */

if (
    $method === 'POST'
    && post_string('action') === 'save_mail'
) {
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $current =
        $settings['mail']
        ?? [];

    try {
        $host = post_string('host');

        if ($host === '') {
            throw new InvalidArgumentException(
                'SMTPサーバを入力してください。'
            );
        }

        $port = filter_var(
            $_POST['port'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            $port === false
            || $port < 1
            || $port > 65535
        ) {
            throw new InvalidArgumentException(
                'SMTPポートが不正です。'
            );
        }

        $encryption =
            post_string('encryption');

        if (!in_array(
            $encryption,
            ['ssl', 'tls', 'none'],
            true
        )) {
            throw new InvalidArgumentException(
                '暗号化方式が不正です。'
            );
        }

        $username =
            post_string('username');

        $password =
            post_string('password');

        if ($password === '') {
            $password =
                (string)(
                    $current['password'] ?? ''
                );
        }

        $fromEmail =
            post_string('from_email');

        if (
            !filter_var(
                $fromEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new InvalidArgumentException(
                '送信元メールアドレスが不正です。'
            );
        }

        $replyTo =
            post_string('reply_to');

        if (
            $replyTo !== ''
            && !filter_var(
                $replyTo,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new InvalidArgumentException(
                '返信先メールアドレスが不正です。'
            );
        }

        $settings['mail'] = [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'auth' =>
                isset($_POST['auth']),
            'username' => $username,
            'password' => $password,
            'from_email' => $fromEmail,
            'from_name' =>
                post_string('from_name'),
            'reply_to' => $replyTo,
            'status' =>
                (string)(
                    $current['status']
                    ?? '未設定'
                ),
        ];

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        $mailMessage =
            'メール設定を保存しました。';
        $mailError = null;
    } catch (Throwable $e) {
        $mailMessage = null;
        $mailError = public_error($e);
    }
}


/* ============================================================
 * ここからHTML
 * ============================================================ */

function render_header(
    string $title,
    bool $answerer = false
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title><?=e($title)?> - アンケートアプリ</title>

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

*{
    box-sizing:border-box;
}

body{
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

header{
    background:#0f172a;
    color:#fff;
    padding:16px 24px;
}

header .inner{
    max-width:1400px;
    margin:auto;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
}

main{
    max-width:1400px;
    margin:0 auto;
    padding:24px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:24px;
    margin-bottom:20px;
}

h1{
    margin-top:0;
}

h2{
    margin-top:28px;
}

button,
.btn{
    border:0;
    border-radius:8px;
    padding:10px 16px;
    cursor:pointer;
    text-decoration:none;
    display:inline-block;
    font-size:14px;
}

.primary{
    background:var(--primary);
    color:#fff;
}

.primary:hover{
    background:var(--primary-dark);
}

.secondary{
    background:var(--gray-light);
    color:var(--text);
}

.success{
    background:var(--success);
    color:#fff;
}

.danger{
    background:var(--danger);
    color:#fff;
}

.warning{
    background:var(--warning);
    color:#fff;
}

input,
textarea,
select{
    width:100%;
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

textarea{
    min-height:120px;
    resize:vertical;
}

.form-row{
    display:grid;
    grid-template-columns:220px 1fr;
    gap:16px;
    align-items:center;
    margin-bottom:16px;
}

.form-row label{
    font-weight:600;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.notice{
    padding:12px 16px;
    border-radius:8px;
    margin-bottom:16px;
}

.notice.success{
    background:#dcfce7;
    color:#166534;
}

.notice.error{
    background:#fee2e2;
    color:#991b1b;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    min-width:1000px;
    border-collapse:collapse;
}

th,
td{
    padding:12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}

th{
    background:#f8fafc;
}

.badge{
    display:inline-block;
    border-radius:999px;
    padding:4px 10px;
    font-size:12px;
    font-weight:600;
}

.badge.draft{
    background:#e2e8f0;
}

.badge.published{
    background:#dcfce7;
    color:#166534;
}

.badge.stopped{
    background:#fef3c7;
    color:#92400e;
}

.badge.ended{
    background:#fee2e2;
    color:#991b1b;
}

.grid{
    display:grid;
    grid-template-columns:
        repeat(auto-fit,minmax(280px,1fr));
    gap:16px;
}

.question{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    margin-bottom:12px;
    background:#fff;
}

.group{
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    margin-bottom:18px;
    background:#f8fafc;
}

.drag-handle{
    cursor:grab;
    font-weight:700;
    color:var(--gray);
}

.answer-page{
    max-width:720px;
    margin:auto;
}

.answer-option{
    display:block;
    padding:14px;
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:10px;
    cursor:pointer;
    background:#fff;
}

.answer-option input{
    width:auto;
    margin-right:8px;
}

@media(max-width:700px){
    main{
        padding:12px;
    }

    header{
        padding:14px;
    }

    .form-row{
        grid-template-columns:1fr;
        gap:6px;
    }

    button,
    .btn{
        width:100%;
    }

    .actions{
        display:grid;
        grid-template-columns:1fr;
    }

    .answer-option{
        font-size:16px;
        padding:16px;
    }
}
</style>
</head>

<body>

<?php if (!$answerer): ?>
<header>
<div class="inner">
<strong>アンケートアプリ</strong>

<nav class="actions">
<a class="btn secondary"
   href="<?=e(screen_url('list'))?>">
   アンケート一覧
</a>

<a class="btn secondary"
   href="<?=e(screen_url('kintone'))?>">
   kintone
</a>

<a class="btn secondary"
   href="<?=e(screen_url('mail'))?>">
   メール
</a>
</nav>
</div>
</header>
<?php endif; ?>

<main>
<?php
}


/* ============================================================
 * kintone画面
 * ============================================================ */

function render_kintone(): void
{
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $k =
        $settings['kintone']
        ?? [];

    $fields =
        is_array($k['fields'] ?? null)
        ? $k['fields']
        : [];

    ?>
    <div class="card">

    <h1>kintone連携設定</h1>

    <?php if (!empty($GLOBALS['kintoneMessage'])): ?>
        <div class="notice success">
            <?=e($GLOBALS['kintoneMessage'])?>
        </div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['kintoneError'])): ?>
        <div class="notice error">
            <?=e($GLOBALS['kintoneError'])?>
        </div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['kintoneTestMessage'])): ?>
        <div class="notice success">
            <?=e($GLOBALS['kintoneTestMessage'])?>
        </div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['kintoneTestError'])): ?>
        <div class="notice error">
            <?=e($GLOBALS['kintoneTestError'])?>
        </div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['kintoneFieldsMessage'])): ?>
        <div class="notice success">
            <?=e($GLOBALS['kintoneFieldsMessage'])?>
        </div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['kintoneFieldsError'])): ?>
        <div class="notice error">
            <?=e($GLOBALS['kintoneFieldsError'])?>
        </div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['kintoneSyncMessage'])): ?>
        <div class="notice success">
            <?=e($GLOBALS['kintoneSyncMessage'])?>
        </div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['kintoneSyncError'])): ?>
        <div class="notice error">
            <?=e($GLOBALS['kintoneSyncError'])?>
        </div>
    <?php endif; ?>

    <form method="post"
          action="<?=e(screen_url('kintone'))?>">

        <input type="hidden"
               name="action"
               value="save_kintone">

        <div class="form-row">
            <label>サブドメイン</label>

            <input name="subdomain"
                   value="<?=e(
                       $k['subdomain'] ?? ''
                   )?>"
                   placeholder="xxxx / xxxx.cybozu.com">
        </div>

        <div class="form-row">
            <label>顧客管理アプリID</label>

            <input name="app_id"
                   inputmode="numeric"
                   value="<?=e(
                       $k['app_id'] ?? ''
                   )?>">
        </div>

        <div class="form-row">
            <label>ログイン名</label>

            <input name="username"
                   value="<?=e(
                       $k['username'] ?? ''
                   )?>">
        </div>

        <div class="form-row">
            <label>パスワード</label>

            <input type="password"
                   name="password"
                   autocomplete="new-password"
                   placeholder="変更しない場合は空欄">
        </div>

        <div class="form-row">
            <label>Proxy</label>

            <input name="proxy"
                   value="<?=e(
                       $k['proxy'] ?? ''
                   )?>"
                   placeholder="host:port">
        </div>

        <div class="form-row">
            <label>SSL証明書検証</label>

            <label>
                <input type="checkbox"
                       name="verify_ssl"
                       value="1"
                       style="width:auto"
                       <?=!empty(
                           $k['verify_ssl']
                       ) ? 'checked' : ''?>>
                有効
            </label>
        </div>

        <button class="primary"
                type="submit">
            設定保存
        </button>

    </form>

    <hr>

    <div class="actions">

        <form method="post"
              action="<?=e(screen_url('kintone'))?>">
            <input type="hidden"
                   name="action"
                   value="test_kintone">

            <button class="secondary"
                    type="submit"
                    onclick="return busy(this)">
                接続テスト
            </button>
        </form>

        <form method="post"
              action="<?=e(screen_url('kintone'))?>">
            <input type="hidden"
                   name="action"
                   value="refresh_kintone_fields">

            <button class="secondary"
                    type="submit"
                    onclick="return busy(this)">
                項目一覧を再取得
            </button>
        </form>

        <form method="post"
              action="<?=e(screen_url('kintone'))?>">
            <input type="hidden"
                   name="action"
                   value="sync_kintone">

            <button class="success"
                    type="submit"
                    onclick="return confirm(
                        '顧客情報を同期しますか？'
                    ) && busy(this)">
                顧客情報を同期
            </button>
        </form>

    </div>

    <h2>kintone項目</h2>

    <?php if (!$fields): ?>

        <p>
            項目一覧はまだ取得されていません。
        </p>

    <?php else: ?>

        <div class="table-wrap">
        <table>
        <thead>
        <tr>
            <th>フィールドコード</th>
            <th>表示名</th>
            <th>形式</th>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($fields as $code => $field): ?>
        <tr>
            <td><?=e($code)?></td>
            <td><?=e(
                $field['label'] ?? ''
            )?></td>
            <td><?=e(
                $field['type'] ?? ''
            )?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        </div>

    <?php endif; ?>

    </div>
    <?php
}


/* ============================================================
 * 一覧
 * ============================================================ */

function render_list(
    array $surveys
): void {
    $keyword =
        get_string('q');

    $status =
        get_string('status', 'all');

    $sort =
        get_string(
            'sort',
            'updated_desc'
        );

    $filtered = [];

    foreach ($surveys as $survey) {
        if (!is_array($survey)) {
            continue;
        }

        $title =
            (string)($survey['title'] ?? '');

        if (
            $keyword !== ''
            && mb_stripos(
                $title,
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
            if ($sort === 'answers_desc') {
                return
                    (int)($b['answerCount'] ?? 0)
                    <=>
                    (int)($a['answerCount'] ?? 0);
            }

            if ($sort === 'answers_asc') {
                return
                    (int)($a['answerCount'] ?? 0)
                    <=>
                    (int)($b['answerCount'] ?? 0);
            }

            $field =
                match ($sort) {
                    'updated_asc' =>
                        'updatedAt',
                    'start_desc',
                    'start_asc' =>
                        'startAt',
                    default =>
                        'updatedAt',
                };

            $av =
                strtotime(
                    (string)($a[$field] ?? '')
                ) ?: 0;

            $bv =
                strtotime(
                    (string)($b[$field] ?? '')
                ) ?: 0;

            return str_contains(
                $sort,
                '_asc'
            )
                ? $av <=> $bv
                : $bv <=> $av;
        }
    );
?>
<div class="card">

<h1>アンケート一覧</h1>

<div class="actions">
<a class="btn primary"
   href="<?=e(screen_url('edit'))?>">
    新規作成
</a>
</div>

<form method="get">
<input type="hidden"
       name="screen"
       value="list">

<div class="grid">

<div>
<label>検索</label>
<input name="q"
       value="<?=e($keyword)?>"
       placeholder="タイトル">
</div>

<div>
<label>状態</label>
<select name="status">
<?php
$statuses = [
    'all' => 'すべて',
    'published' => '公開中',
    'draft' => '下書き',
    'stopped' => '停止',
    'ended' => '終了',
];

foreach ($statuses as $key => $label):
?>
<option value="<?=e($key)?>"
    <?=$status === $key
        ? 'selected'
        : ''?>>
    <?=e($label)?>
</option>
<?php endforeach; ?>
</select>
</div>

<div>
<label>ソート</label>
<select name="sort">
<?php
$sorts = [
    'updated_desc' => '更新日：新しい順',
    'updated_asc' => '更新日：古い順',
    'answers_desc' => '回答数：多い順',
    'answers_asc' => '回答数：少ない順',
    'start_desc' => '開始日：新しい順',
    'start_asc' => '開始日：古い順',
];

foreach ($sorts as $key => $label):
?>
<option value="<?=e($key)?>"
    <?=$sort === $key
        ? 'selected'
        : ''?>>
    <?=e($label)?>
</option>
<?php endforeach; ?>
</select>
</div>

</div>

<button class="secondary"
        type="submit">
    検索
</button>
</form>

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

<?php if (!$filtered): ?>

<tr>
<td colspan="7">
アンケートがありません。
</td>
</tr>

<?php endif; ?>

<?php foreach ($filtered as $survey): ?>

<?php
$sid =
    (string)($survey['id'] ?? '');

$sstatus =
    (string)($survey['status'] ?? 'draft');

$badge =
    match ($sstatus) {
        'published' => 'published',
        'stopped' => 'stopped',
        'ended' => 'ended',
        default => 'draft',
    };
?>

<tr>

<td><?=e(
    $survey['title'] ?? ''
)?></td>

<td><?=e(
    $survey['createdAt'] ?? ''
)?></td>

<td><?=e(
    $survey['updatedAt'] ?? ''
)?></td>

<td>
<?=e(
    $survey['startAt'] ?? ''
)?>
<br>
～
<br>
<?=e(
    $survey['endAt'] ?? ''
)?>
</td>

<td>
<span class="badge <?=$badge?>">
<?=e(
    match ($sstatus) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    }
)?>
</span>
</td>

<td><?=e(
    $survey['answerCount'] ?? 0
)?></td>

<td>
<div class="actions">

<a class="btn secondary"
   href="<?=e(
       screen_url('edit', $sid)
   )?>">
確認・編集
</a>

<a class="btn secondary"
   href="<?=e(
       screen_url('preview', $sid)
   )?>">
プレビュー
</a>

<a class="btn secondary"
   href="<?=e(
       screen_url('analytics', $sid)
   )?>">
集計
</a>

<a class="btn secondary"
   href="<?=e(
       screen_url('send', $sid)
   )?>">
送信
</a>

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


/* ============================================================
 * JavaScript
 * ============================================================ */
?>
<script>
function busy(button){
    button.disabled = true;
    button.dataset.original =
        button.textContent;

    button.textContent = '処理中…';

    const form = button.form;

    if(form){
        form.querySelectorAll(
            'button,input,select,textarea'
        ).forEach(function(el){
            if(el !== button){
                el.disabled = true;
            }
        });
    }

    return true;
}

function confirmAction(message){
    return window.confirm(message);
}
</script>

<?php

/*
 * ============================================================
 * 画面ルーティング
 * ============================================================
 */

try {

    if ($screen === 'kintone') {

        render_header(
            'kintone連携設定'
        );

        render_kintone();

        echo '</main></body></html>';

        exit;
    }

    if ($screen === 'list') {

        render_header(
            'アンケート一覧'
        );

        render_list(
            $surveys
        );

        echo '</main></body></html>';

        exit;
    }

    /*
     * analytics / send は必ずIDを要求する。
     */

    if (
        $screen === 'analytics'
        || $screen === 'send'
    ) {
        $id = get_string('id');

        if (!valid_id($id)) {
            redirect303(
                screen_url('list')
            );
        }

        $survey =
            find_survey(
                $surveys,
                $id
            );

        if ($survey === null) {
            redirect303(
                screen_url('list')
            );
        }

        /*
         * ここで別アンケートを選択するUIは作らない。
         * URLのidで対象を固定する。
         */

        render_header(
            $screen === 'send'
                ? '顧客選択・メール送信'
                : '回答集計・分析'
        );

        ?>
        <div class="card">

        <h1>
        <?=e(
            $screen === 'send'
                ? '顧客選択・メール送信'
                : '回答集計・分析'
        )?>
        </h1>

        <p>
        対象アンケート：
        <strong>
        <?=e($survey['title'] ?? '')?>
        </strong>
        </p>

        <?php if ($screen === 'analytics'): ?>

            <p>
                回答データを対象アンケート単位で
                集計します。
            </p>

            <p>
                現在、回答データはありません
            </p>

        <?php else: ?>

            <p>
                対象アンケートは固定されています。
            </p>

        <?php endif; ?>

        </div>
        <?php

        echo '</main></body></html>';

        exit;
    }

    /*
     * その他の画面はここで実装する。
     *
     * 重要なのは、画面遷移を物理パスで行わず、
     * screen + idだけで制御すること。
     */

    render_header(
        'アンケートアプリ'
    );

    ?>
    <div class="card">
        <h1>アンケートアプリ</h1>

        <p>
            画面が指定されていないため、
            アンケート一覧を表示します。
        </p>

        <a class="btn primary"
           href="<?=e(screen_url('list'))?>">
            アンケート一覧へ
        </a>
    </div>
    <?php

    echo '</main></body></html>';

} catch (Throwable $e) {

    /*
     * 内部例外や認証情報をそのまま表示しない。
     */
    http_response_code(500);

    render_header(
        'エラー'
    );

    ?>
    <div class="card">

        <h1>処理エラー</h1>

        <div class="notice error">
            <?=e(public_error($e))?>
        </div>

        <a class="btn secondary"
           href="<?=e(screen_url('list'))?>">
            アンケート一覧へ
        </a>

    </div>
    <?php

    echo '</main></body></html>';
}
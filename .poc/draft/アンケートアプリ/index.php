<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * prompt.txt 再生成版
 *
 * 対応:
 * - Apache 2.4
 * - PHP 8.5
 * - DBなし
 * - PHP cURLあり
 * - index.php 単一エントリーポイント
 *
 * 外部サービス:
 * - kintone REST API
 * - SMTP
 *
 * 注意:
 * - CSRFは仕様により実装しない
 * - 管理者認証はPOCでは実装しない
 * - POST結果を303へリダイレクトしない
 * - kintone認証リトライを行わない
 * - APIトークン認証を使用しない
 * - curl_close()は使用しない
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR       = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const ANSWERS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'answers.json';
const SEND_LOG_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'send_logs.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT    = 30;

/* ------------------------------------------------------------
 * 初期化
 * ------------------------------------------------------------ */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

$https =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
$cookiePath = str_replace('\\', '/', $scriptDir);

if ($cookiePath === '.' || $cookiePath === '') {
    $cookiePath = '/';
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $cookiePath,
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できません。');
    }
}

/* ------------------------------------------------------------
 * 共通関数
 * ------------------------------------------------------------ */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now_iso(): string
{
    return date('c');
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function valid_id(string $id): bool
{
    return (bool)preg_match(
        '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/',
        $id
    );
}

function screen_url(string $screen, ?string $id = null): string
{
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

    if ($id !== null && $id !== '' && valid_id($id)) {
        $url .= '&id=' . rawurlencode($id);
    }

    return $url;
}

function set_result(string $type, string $message): void
{
    $_SESSION['_result'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function take_result(): ?array
{
    $result = $_SESSION['_result'] ?? null;
    unset($_SESSION['_result']);

    return is_array($result) ? $result : null;
}

function redirect_internal(string $screen, ?string $id = null): never
{
    /*
     * 外部URLへリダイレクトしない。
     * 303も使用しない。
     */
    header('Location: ' . screen_url($screen, $id));
    exit;
}

/* ------------------------------------------------------------
 * JSON永続化
 * ------------------------------------------------------------ */

function read_json(string $file, mixed $default = []): mixed
{
    if (!is_file($file)) {
        return $default;
    }

    $fp = fopen($file, 'rb');

    if ($fp === false) {
        throw new RuntimeException('データファイルを開けません。');
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException('データファイルをロックできません。');
        }

        $raw = stream_get_contents($fp);

        flock($fp, LOCK_UN);

        if ($raw === false || trim($raw) === '') {
            return $default;
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    } finally {
        fclose($fp);
    }
}

function write_json_atomic(string $file, mixed $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
    );

    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $fp = fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('一時ファイルをロックできません。');
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException('データを書き込めません。');
        }

        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データを保存できません。');
    }
}

/* ------------------------------------------------------------
 * アンケート
 * ------------------------------------------------------------ */

function surveys(): array
{
    $data = read_json(SURVEYS_FILE, []);
    return is_array($data) ? array_values($data) : [];
}

function save_surveys(array $items): void
{
    write_json_atomic(SURVEYS_FILE, array_values($items));
}

function default_question(): array
{
    return [
        'id' => 'question-' . uuid(),
        'text' => '',
        'type' => 'single',
        'required' => false,
        'options' => [
            [
                'id' => 'option-' . uuid(),
                'text' => '',
                'nextQuestionId' => '',
            ],
        ],
    ];
}

function default_group(): array
{
    return [
        'id' => 'group-' . uuid(),
        'title' => 'グループ1',
        'questions' => [
            default_question(),
        ],
    ];
}

function default_survey(): array
{
    $now = now_iso();

    return [
        'id' => 'survey-' . uuid(),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'groups' => [
            default_group(),
        ],
        'createdAt' => $now,
        'updatedAt' => $now,
    ];
}

function normalize_question(array $q): array
{
    $type = (string)($q['type'] ?? 'single');

    if (!in_array($type, ['single', 'multiple', 'text'], true)) {
        $type = 'single';
    }

    $options = [];

    foreach (($q['options'] ?? []) as $option) {
        if (!is_array($option)) {
            continue;
        }

        $options[] = [
            'id' => (string)($option['id'] ?? ('option-' . uuid())),
            'text' => (string)($option['text'] ?? ''),
            'nextQuestionId' =>
                (string)($option['nextQuestionId'] ?? ''),
        ];
    }

    if ($type === 'text') {
        $options = [];
    }

    if ($type !== 'text' && !$options) {
        $options[] = [
            'id' => 'option-' . uuid(),
            'text' => '',
            'nextQuestionId' => '',
        ];
    }

    return [
        'id' => (string)($q['id'] ?? ('question-' . uuid())),
        'text' => (string)($q['text'] ?? ''),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $options,
    ];
}

function normalize_group(array $g): array
{
    $questions = [];

    foreach (($g['questions'] ?? []) as $q) {
        if (is_array($q)) {
            $questions[] = normalize_question($q);
        }
    }

    if (!$questions) {
        $questions[] = default_question();
    }

    return [
        'id' => (string)($g['id'] ?? ('group-' . uuid())),
        'title' => (string)($g['title'] ?? ''),
        'questions' => $questions,
    ];
}

function normalize_survey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? ('survey-' . uuid()));
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['description'] = (string)($survey['description'] ?? '');
    $survey['startAt'] = (string)($survey['startAt'] ?? '');
    $survey['endAt'] = (string)($survey['endAt'] ?? '');

    $status = (string)($survey['status'] ?? 'draft');

    if (!in_array(
        $status,
        ['draft', 'published', 'stopped', 'ended'],
        true
    )) {
        $status = 'draft';
    }

    $survey['status'] = $status;

    $numbering = (string)($survey['numbering'] ?? 'global');

    if (!in_array($numbering, ['global', 'group'], true)) {
        $numbering = 'global';
    }

    $survey['numbering'] = $numbering;

    $groups = [];

    foreach (($survey['groups'] ?? []) as $group) {
        if (is_array($group)) {
            $groups[] = normalize_group($group);
        }
    }

    if (!$groups) {
        $groups[] = default_group();
    }

    $survey['groups'] = $groups;
    $survey['createdAt'] = (string)($survey['createdAt'] ?? now_iso());
    $survey['updatedAt'] = (string)($survey['updatedAt'] ?? now_iso());

    /*
     * 終了条件は published + endAt経過だけ。
     */
    if (
        $survey['status'] === 'published'
        && $survey['endAt'] !== ''
    ) {
        try {
            $end = new DateTimeImmutable($survey['endAt']);
            $now = new DateTimeImmutable();

            if ($end < $now) {
                $survey['status'] = 'ended';
            }
        } catch (Throwable) {
            /*
             * 不正な日時は別途入力・データエラーとして扱う。
             */
        }
    }

    return $survey;
}

function find_survey(string $id): ?array
{
    if (!valid_id($id)) {
        return null;
    }

    foreach (surveys() as $survey) {
        if (
            is_array($survey)
            && (string)($survey['id'] ?? '') === $id
        ) {
            return normalize_survey($survey);
        }
    }

    return null;
}

function save_survey(array $survey): void
{
    $survey = normalize_survey($survey);
    $items = surveys();
    $found = false;

    foreach ($items as $i => $item) {
        if (
            is_array($item)
            && (string)($item['id'] ?? '') === $survey['id']
        ) {
            $items[$i] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $items[] = $survey;
    }

    save_surveys($items);
}

function renumber_survey(array &$survey): void
{
    $global = 0;

    foreach ($survey['groups'] as $gi => &$group) {
        $local = 0;

        foreach ($group['questions'] as $qi => &$question) {
            $global++;
            $local++;

            if ($survey['numbering'] === 'group') {
                $question['_number'] = 'Q' . ($gi + 1) . '-' . $local;
            } else {
                $question['_number'] = 'Q' . $global;
            }
        }

        unset($question);
    }

    unset($group);
}

function survey_question_map(array $survey): array
{
    $map = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $map[$question['id']] = $question;
        }
    }

    return $map;
}

/* ------------------------------------------------------------
 * kintone
 * ------------------------------------------------------------ */

/**
 * kintone URLの成形
 *
 * 以下をすべて正規化:
 *
 * https://example.cybozu.com
 * http://example.cybozu.com
 * example.cybozu.com
 * example
 *
 * -> example.cybozu.com
 */
function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

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

    if (
        $value === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/',
            $value
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    return strtolower($value);
}

/**
 * kintone URLを生成。
 */
function kintone_build_url(
    string $domain,
    string $endpoint
): string {
    $domain = normalize_kintone_subdomain($domain);
    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * X-Cybozu-Authorization生成。
 *
 * ブラウザ側には返さない。
 */
function make_cybozu_auth_header(
    string $loginName,
    string $password
): string {
    return 'X-Cybozu-Authorization: '
        . base64_encode(
            trim($loginName) . ':' . $password
        );
}

function parse_proxy(string $proxy): ?string
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (
        !preg_match(
            '/^([A-Za-z0-9.-]+):([0-9]{1,5})$/',
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
            'Proxyのポート番号が不正です。'
        );
    }

    return $m[1] . ':' . $port;
}

function validate_kintone(array $k): void
{
    if (
        trim((string)($k['subdomain'] ?? '')) === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが未設定です。'
        );
    }

    normalize_kintone_subdomain(
        (string)$k['subdomain']
    );

    $appId = (string)($k['app_id'] ?? '');

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    if (
        trim((string)($k['username'] ?? '')) === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneログイン名が未設定です。'
        );
    }

    if ((string)($k['password'] ?? '') === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードが未設定です。'
        );
    }

    parse_proxy((string)($k['proxy'] ?? ''));
}

function kintone_request(
    array $k,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    validate_kintone($k);

    if (
        $path === ''
        || $path[0] !== '/'
    ) {
        throw new InvalidArgumentException(
            'kintone APIパスが不正です。'
        );
    }

    $method = strtoupper($method);

    if (
        !in_array(
            $method,
            ['GET', 'POST', 'PUT', 'DELETE'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'kintone HTTPメソッドが不正です。'
        );
    }

    $url = kintone_build_url(
        (string)$k['subdomain'],
        $path
    );

    $authorization = make_cybozu_auth_header(
        (string)$k['username'],
        (string)$k['password']
    );

    $headers = [
        'Accept: application/json',
        $authorization,
    ];

    $payload = null;

    if ($body !== null) {
        $payload = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init();

    if ($ch === false) {
        throw new RuntimeException(
            'cURLを初期化できません。'
        );
    }

    /*
     * PHP 8.5では curl_close() は不要。
     * ハンドルはスコープ終了時に解放される。
     */
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,

        /*
         * タイムアウトを接続と全体で分離。
         */
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => READ_TIMEOUT,

        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,

        CURLOPT_SSL_VERIFYPEER =>
            !empty($k['verify_ssl']),
        CURLOPT_SSL_VERIFYHOST =>
            !empty($k['verify_ssl']) ? 2 : 0,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = $payload;
    }

    $proxy = parse_proxy(
        (string)($k['proxy'] ?? '')
    );

    if ($proxy !== null) {
        $options[CURLOPT_PROXY] = $proxy;
        $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
    }

    if (!curl_setopt_array($ch, $options)) {
        throw new RuntimeException(
            'kintone通信設定を初期化できません。'
        );
    }

    $raw = curl_exec($ch);

    if ($raw === false) {
        $errno = curl_errno($ch);
        $error = curl_error($ch);

        throw new RuntimeException(
            'kintone通信に失敗しました。'
            . ' cURL errno=' . $errno
            . ' / ' . $error
        );
    }

    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $headerSize = (int)curl_getinfo(
        $ch,
        CURLINFO_HEADER_SIZE
    );

    $rawHeaders = substr(
        $raw,
        0,
        $headerSize
    );

    $responseBody = substr(
        $raw,
        $headerSize
    );

    $json = null;

    if ($responseBody !== '') {
        $decoded = json_decode(
            $responseBody,
            true
        );

        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'status' => $httpCode,
        'headers' => $rawHeaders,
        'body' => $responseBody,
        'json' => $json,
    ];
}

function kintone_error_message(
    array $result
): string {
    $status = (int)($result['status'] ?? 0);
    $json = $result['json'] ?? [];

    $message = '';

    if (is_array($json)) {
        $message = trim(
            (string)($json['message'] ?? '')
        );
    }

    $errorId = '';

    if (is_array($json)) {
        $errorId = trim(
            (string)($json['id'] ?? '')
        );
    }

    $detail = 'HTTP ' . $status;

    if ($message !== '') {
        $detail .= ' / kintone: ' . $message;
    }

    if ($errorId !== '') {
        $detail .= ' / エラーID: ' . $errorId;
    }

    /*
     * HTTP 400をユーザーが次に何を確認すればよいか分かるようにする。
     */
    if ($status === 400) {
        $detail .=
            ' / 対処: '
            . 'サブドメイン、アプリID、ログイン名・パスワード、'
            . 'kintone側のAPI利用権限を確認してください。'
            . '接続テストでは「アプリ情報取得API」を使用しています。';
    } elseif ($status === 401) {
        $detail .=
            ' / 対処: '
            . 'ログイン名・パスワードを確認し、'
            . 'kintone側で対象アプリへアクセスできる権限を確認してください。';
    } elseif ($status === 403) {
        $detail .=
            ' / 対処: '
            . 'kintoneユーザーの対象アプリへの権限を確認してください。';
    } elseif ($status === 404) {
        $detail .=
            ' / 対処: '
            . 'サブドメインと顧客管理アプリIDを確認してください。';
    }

    return $detail;
}

function test_kintone(array $settings): array
{
    $k = $settings['kintone'] ?? [];

    validate_kintone($k);

    $appId = (string)$k['app_id'];

    /*
     * kintone REST API:
     *
     * GET /k/v1/app.json?id={APP_ID}
     *
     * 「app=」ではなく「id=」を使用する。
     */
    $result = kintone_request(
        $k,
        '/k/v1/app.json?id='
        . rawurlencode($appId),
        'GET'
    );

    if (
        $result['status'] >= 200
        && $result['status'] < 300
    ) {
        $appName = '';

        if (is_array($result['json'])) {
            $appName = trim(
                (string)($result['json']['name'] ?? '')
            );
        }

        return [
            'success' => true,
            'message' =>
                'kintone接続成功。'
                . ($appName !== ''
                    ? ' アプリ名: ' . $appName
                    : ''),
        ];
    }

    return [
        'success' => false,
        'message' =>
            'kintone接続失敗。'
            . kintone_error_message($result),
    ];
}

function save_kintone_settings(): void
{
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    if (!is_array($settings)) {
        $settings = [];
    }

    $current = is_array(
        $settings['kintone'] ?? null
    )
        ? $settings['kintone']
        : [];

    $subdomain = normalize_kintone_subdomain(
        trim((string)(
            $_POST['subdomain'] ?? ''
        ))
    );

    $appId = trim((string)(
        $_POST['app_id'] ?? ''
    ));

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDは1以上の整数で入力してください。'
        );
    }

    $username = trim((string)(
        $_POST['username'] ?? ''
    ));

    if ($username === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    /*
     * パスワード未入力なら保存済みを維持。
     */
    $password = (string)(
        $_POST['password'] ?? ''
    );

    if ($password === '') {
        $password = (string)(
            $current['password'] ?? ''
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    $proxy = trim((string)(
        $_POST['proxy'] ?? ''
    ));

    parse_proxy($proxy);

    $settings['kintone'] = [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' => isset(
            $_POST['verify_ssl']
        ),
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

    set_result(
        'success',
        'kintone設定を保存しました。'
    );
}

function fetch_kintone_fields(): void
{
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $k = $settings['kintone'] ?? [];

    validate_kintone($k);

    $result = kintone_request(
        $k,
        '/k/v1/app/form/fields.json?app='
        . rawurlencode((string)$k['app_id']),
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        throw new RuntimeException(
            'kintone項目一覧取得失敗。'
            . kintone_error_message($result)
        );
    }

    $settings['kintone']['fields'] =
        is_array($result['json']['properties'] ?? null)
            ? $result['json']['properties']
            : [];

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    set_result(
        'success',
        'kintoneの項目一覧を再取得しました。'
    );
}

function sync_kintone_customers(): void
{
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $k = $settings['kintone'] ?? [];

    validate_kintone($k);

    $mapping =
        is_array($k['field_mapping'] ?? null)
            ? $k['field_mapping']
            : [];

    $customers = [];
    $offset = 0;

    while (true) {
        $query =
            'order by $id asc limit 500 offset '
            . $offset;

        $path =
            '/k/v1/records.json?'
            . http_build_query([
                'app' => (string)$k['app_id'],
                'query' => $query,
            ]);

        $result = kintone_request(
            $k,
            $path,
            'GET'
        );

        if (
            $result['status'] < 200
            || $result['status'] >= 300
        ) {
            throw new RuntimeException(
                'kintone顧客情報取得失敗。'
                . kintone_error_message($result)
            );
        }

        $records =
            is_array($result['json']['records'] ?? null)
                ? $result['json']['records']
                : [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $customers[] = [
                'id' => 'customer-' . uuid(),
                'kintoneId' =>
                    (string)(
                        $record['$id']['value'] ?? ''
                    ),
                'organization' =>
                    kintone_field_value(
                        $record,
                        $mapping['organization'] ?? ''
                    ),
                'name' =>
                    kintone_field_value(
                        $record,
                        $mapping['name'] ?? ''
                    ),
                'email' =>
                    kintone_field_value(
                        $record,
                        $mapping['email'] ?? ''
                    ),
                'department' =>
                    kintone_field_value(
                        $record,
                        $mapping['department'] ?? ''
                    ),
                'phone' =>
                    kintone_field_value(
                        $record,
                        $mapping['phone'] ?? ''
                    ),
                'address' =>
                    kintone_field_value(
                        $record,
                        $mapping['address'] ?? ''
                    ),
                'updatedAt' => now_iso(),
            ];
        }

        if (count($records) < 500) {
            break;
        }

        $offset += 500;
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    set_result(
        'success',
        'kintone顧客情報を同期しました。'
        . ' 件数: ' . count($customers)
    );
}

function kintone_field_value(
    array $record,
    string $fieldCode
): string {
    if ($fieldCode === '') {
        return '';
    }

    $field = $record[$fieldCode] ?? null;

    if (!is_array($field)) {
        return '';
    }

    $value = $field['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] = (string)(
                    $item['name']
                    ?? $item['code']
                    ?? $item['value']
                    ?? ''
                );
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(', ', $parts);
    }

    return (string)$value;
}

/* ------------------------------------------------------------
 * メール設定 / SMTP
 * ------------------------------------------------------------ */

function save_mail_settings(): void
{
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    if (!is_array($settings)) {
        $settings = [];
    }

    $current = is_array(
        $settings['mail'] ?? null
    )
        ? $settings['mail']
        : [];

    $host = trim((string)(
        $_POST['host'] ?? ''
    ));

    $port = (int)(
        $_POST['port'] ?? 0
    );

    $encryption = (string)(
        $_POST['encryption'] ?? 'tls'
    );

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (
        !in_array(
            $encryption,
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    $username = trim((string)(
        $_POST['username'] ?? ''
    ));

    $password = (string)(
        $_POST['password'] ?? ''
    );

    if ($password === '') {
        $password = (string)(
            $current['password'] ?? ''
        );
    }

    $fromEmail = trim((string)(
        $_POST['from_email'] ?? ''
    ));

    if (!filter_var(
        $fromEmail,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $replyTo = trim((string)(
        $_POST['reply_to'] ?? ''
    ));

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
        'auth' => isset($_POST['auth']),
        'username' => $username,
        'password' => $password,
        'from_email' => $fromEmail,
        'from_name' => trim((string)(
            $_POST['from_name'] ?? ''
        )),
        'reply_to' => $replyTo,
    ];

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    set_result(
        'success',
        'メール設定を保存しました。'
    );
}

/*
 * SMTPの低レベル通信。
 *
 * PHP mail()は使用しない。
 */
function smtp_open(array $mail)
{
    $host = trim((string)(
        $mail['host'] ?? ''
    ));

    $port = (int)(
        $mail['port'] ?? 0
    );

    $encryption = (string)(
        $mail['encryption'] ?? 'tls'
    );

    if (
        $host === ''
        || $port < 1
        || $port > 65535
    ) {
        throw new RuntimeException(
            'SMTP設定が不正です。'
        );
    }

    $target = $encryption === 'ssl'
        ? 'ssl://' . $host . ':' . $port
        : 'tcp://' . $host . ':' . $port;

    $errno = 0;
    $error = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $error,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。'
            . ' errno=' . $errno
            . ' / ' . $error
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    return $socket;
}

function smtp_read($socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 8192);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            strlen($line) >= 4
            && $line[3] === ' '
        ) {
            break;
        }
    }

    return $response;
}

function smtp_expect(
    $socket,
    array $codes
): string {
    $response = smtp_read($socket);

    $code = (int)substr(
        trim($response),
        0,
        3
    );

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTP応答が不正です。'
            . ' HTTPではなくSMTP応答: '
            . trim($response)
        );
    }

    return $response;
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): string {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    return smtp_expect(
        $socket,
        $codes
    );
}

function smtp_starttls(
    $socket
): void {
    smtp_command(
        $socket,
        'STARTTLS',
        [220]
    );

    $result = stream_socket_enable_crypto(
        $socket,
        true,
        STREAM_CRYPTO_METHOD_TLS_CLIENT
    );

    if ($result !== true) {
        throw new RuntimeException(
            'SMTP STARTTLSを確立できません。'
        );
    }
}

function smtp_connect_test(array $mail): void
{
    $socket = smtp_open($mail);

    try {
        smtp_expect($socket, [220]);

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );

        if (
            ($mail['encryption'] ?? 'none')
            === 'tls'
        ) {
            smtp_starttls($socket);

            smtp_command(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (!empty($mail['auth'])) {
            $username = (string)(
                $mail['username'] ?? ''
            );

            $password = (string)(
                $mail['password'] ?? ''
            );

            smtp_command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtp_command(
                $socket,
                base64_encode($username),
                [334]
            );

            smtp_command(
                $socket,
                base64_encode($password),
                [235]
            );
        }

        smtp_command(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}

/* ------------------------------------------------------------
 * 回答
 * ------------------------------------------------------------ */

function answers(): array
{
    $data = read_json(
        ANSWERS_FILE,
        []
    );

    return is_array($data)
        ? $data
        : [];
}

function save_answers(array $data): void
{
    write_json_atomic(
        ANSWERS_FILE,
        array_values($data)
    );
}

function customer_list(): array
{
    $data = read_json(
        CUSTOMERS_FILE,
        []
    );

    return is_array($data)
        ? $data
        : [];
}

function send_logs(): array
{
    $data = read_json(
        SEND_LOG_FILE,
        []
    );

    return is_array($data)
        ? $data
        : [];
}

function save_send_logs(array $logs): void
{
    write_json_atomic(
        SEND_LOG_FILE,
        array_values($logs)
    );
}

/* ------------------------------------------------------------
 * 入力検証
 * ------------------------------------------------------------ */

function post_string(
    string $name,
    int $max = 10000
): string {
    $value = (string)(
        $_POST[$name] ?? ''
    );

    if (mb_strlen($value) > $max) {
        throw new InvalidArgumentException(
            $name . 'が長すぎます。'
        );
    }

    return $value;
}

function validate_survey_for_save(
    array $survey
): void {
    $title = trim(
        (string)($survey['title'] ?? '')
    );

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (
        mb_strlen($title) > 200
    ) {
        throw new InvalidArgumentException(
            'アンケートタイトルは200文字以内です。'
        );
    }

    if (
        !empty($survey['startAt'])
        && !strtotime($survey['startAt'])
    ) {
        throw new InvalidArgumentException(
            '開始日時が不正です。'
        );
    }

    if (
        !empty($survey['endAt'])
        && !strtotime($survey['endAt'])
    ) {
        throw new InvalidArgumentException(
            '終了日時が不正です。'
        );
    }

    if (
        !empty($survey['startAt'])
        && !empty($survey['endAt'])
        && strtotime($survey['startAt'])
            > strtotime($survey['endAt'])
    ) {
        throw new InvalidArgumentException(
            '開始日時は終了日時より前にしてください。'
        );
    }

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            if (
                trim(
                    (string)$question['text']
                ) === ''
            ) {
                throw new InvalidArgumentException(
                    '質問文を入力してください。'
                );
            }

            if (
                in_array(
                    $question['type'],
                    ['single', 'multiple'],
                    true
                )
                && count($question['options']) < 1
            ) {
                throw new InvalidArgumentException(
                    '選択肢を1つ以上設定してください。'
                );
            }
        }
    }
}

/* ------------------------------------------------------------
 * POST処理
 * ------------------------------------------------------------ */

function handle_post(): ?string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }

    $action = (string)(
        $_POST['action'] ?? ''
    );

    try {
        switch ($action) {

            case 'save_kintone':
                save_kintone_settings();
                return 'kintone';

            case 'test_kintone':
                $settings = read_json(
                    SETTINGS_FILE,
                    []
                );

                $result = test_kintone(
                    is_array($settings)
                        ? $settings
                        : []
                );

                set_result(
                    $result['success']
                        ? 'success'
                        : 'error',
                    $result['message']
                );

                return 'kintone';

            case 'fetch_kintone_fields':
                fetch_kintone_fields();
                return 'kintone';

            case 'sync_kintone':
                sync_kintone_customers();
                return 'kintone';

            case 'save_mail':
                save_mail_settings();
                return 'mail';

            case 'test_mail':
                $settings = read_json(
                    SETTINGS_FILE,
                    []
                );

                $mail = $settings['mail'] ?? [];

                smtp_connect_test($mail);

                set_result(
                    'success',
                    'SMTP接続確認に成功しました。'
                );

                return 'mail';

            case 'save_survey':
                return handle_save_survey();

            case 'delete_survey':
                return handle_delete_survey();

            case 'duplicate_survey':
                return handle_duplicate_survey();

            case 'change_status':
                return handle_change_status();

            case 'save_answer':
                return handle_save_answer();

            case 'send_mail':
                return handle_send_mail();

            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }
    } catch (
        InvalidArgumentException $e
    ) {
        set_result(
            'error',
            '入力エラー: ' . $e->getMessage()
        );
    } catch (
        RuntimeException $e
    ) {
        set_result(
            'error',
            '処理エラー: ' . $e->getMessage()
        );
    } catch (
        Throwable $e
    ) {
        /*
         * 内部エラーや認証情報を画面へ出さない。
         */
        set_result(
            'error',
            'システムエラーが発生しました。'
            . ' 入力値・設定値・外部サービスの状態を確認してください。'
        );
    }

    return (string)(
        $_GET['screen'] ?? 'list'
    );
}

function handle_save_survey(): string
{
    $id = trim((string)(
        $_POST['id'] ?? ''
    ));

    $existing = $id !== ''
        ? find_survey($id)
        : null;

    if ($id !== '' && $existing === null) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    $survey = $existing ?? default_survey();

    $survey['title'] = trim(
        post_string('title', 200)
    );

    $survey['description'] =
        post_string('description', 5000);

    $survey['startAt'] =
        post_string('startAt', 50);

    $survey['endAt'] =
        post_string('endAt', 50);

    $numbering =
        (string)($_POST['numbering'] ?? 'global');

    if (
        !in_array(
            $numbering,
            ['global', 'group'],
            true
        )
    ) {
        $numbering = 'global';
    }

    $survey['numbering'] = $numbering;

    /*
     * 新規はdraft。
     * 既存は現在状態を維持。
     */
    if ($existing === null) {
        $survey['status'] = 'draft';
    }

    $groupsJson = (string)(
        $_POST['groups_json'] ?? ''
    );

    if ($groupsJson !== '') {
        $groups = json_decode(
            $groupsJson,
            true
        );

        if (!is_array($groups)) {
            throw new InvalidArgumentException(
                '質問データが不正です。'
            );
        }

        $survey['groups'] = [];

        foreach ($groups as $group) {
            if (is_array($group)) {
                $survey['groups'][] =
                    normalize_group($group);
            }
        }
    }

    if (!$survey['groups']) {
        $survey['groups'][] =
            default_group();
    }

    validate_survey_for_save($survey);

    renumber_survey($survey);

    $survey['updatedAt'] = now_iso();

    save_survey($survey);

    set_result(
        'success',
        'アンケートを保存しました。'
    );

    return 'list';
}

function handle_delete_survey(): string
{
    $id = trim((string)(
        $_POST['id'] ?? ''
    ));

    if (!valid_id($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $items = surveys();
    $newItems = [];
    $found = false;

    foreach ($items as $item) {
        if (
            is_array($item)
            && (string)($item['id'] ?? '') === $id
        ) {
            $found = true;
            continue;
        }

        $newItems[] = $item;
    }

    if (!$found) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    save_surveys($newItems);

    set_result(
        'success',
        'アンケートを削除しました。'
    );

    return 'list';
}

function handle_duplicate_survey(): string
{
    $id = trim((string)(
        $_POST['id'] ?? ''
    ));

    $survey = find_survey($id);

    if ($survey === null) {
        throw new InvalidArgumentException(
            '複製対象アンケートが存在しません。'
        );
    }

    $survey['id'] =
        'survey-' . uuid();

    $survey['title'] =
        $survey['title'] . '（複製）';

    $survey['status'] = 'draft';
    $survey['createdAt'] = now_iso();
    $survey['updatedAt'] = now_iso();

    save_survey($survey);

    set_result(
        'success',
        'アンケートを複製しました。'
    );

    return 'list';
}

function handle_change_status(): string
{
    $id = trim((string)(
        $_POST['id'] ?? ''
    ));

    $status = (string)(
        $_POST['status'] ?? ''
    );

    $survey = find_survey($id);

    if ($survey === null) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    if ($survey['status'] === 'ended') {
        throw new InvalidArgumentException(
            '終了したアンケートの状態は変更できません。'
        );
    }

    $allowed = [
        'draft' => ['published'],
        'published' => ['stopped'],
        'stopped' => ['published'],
    ];

    if (
        !isset($allowed[$survey['status']])
        || !in_array(
            $status,
            $allowed[$survey['status']],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '許可されていない状態変更です。'
        );
    }

    $survey['status'] = $status;
    $survey['updatedAt'] = now_iso();

    save_survey($survey);

    set_result(
        'success',
        'アンケート状態を変更しました。'
    );

    return 'list';
}

function handle_save_answer(): string
{
    $id = trim((string)(
        $_POST['survey_id'] ?? ''
    ));

    $survey = find_survey($id);

    if ($survey === null) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    if ($survey['status'] !== 'published') {
        throw new InvalidArgumentException(
            'このアンケートは現在回答できません。'
        );
    }

    $answers = answers();

    $submitted = $_POST['answers'] ?? [];

    if (!is_array($submitted)) {
        $submitted = [];
    }

    $map = survey_question_map($survey);

    foreach ($map as $questionId => $question) {
        if (
            !empty($question['required'])
            && empty($submitted[$questionId])
        ) {
            throw new InvalidArgumentException(
                ($question['_number'] ?? '質問')
                . 'は必須項目です。'
            );
        }
    }

    $answers[] = [
        'id' => 'answer-' . uuid(),
        'surveyId' => $id,
        'answers' => $submitted,
        'createdAt' => now_iso(),
    ];

    save_answers($answers);

    $_SESSION['last_answer'] = [
        'surveyId' => $id,
        'answers' => $submitted,
    ];

    return 'complete';
}

function handle_send_mail(): string
{
    $surveyId = trim((string)(
        $_POST['survey_id'] ?? ''
    ));

    $survey = find_survey($surveyId);

    if ($survey === null) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    $selected = $_POST['customers'] ?? [];

    if (!is_array($selected)) {
        $selected = [];
    }

    if (!$selected) {
        throw new InvalidArgumentException(
            '送信対象者を選択してください。'
        );
    }

    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $mail = $settings['mail'] ?? [];

    if (!is_array($mail)) {
        throw new InvalidArgumentException(
            'メール設定がありません。'
        );
    }

    $logs = send_logs();

    /*
     * 実送信。
     * 大量送信ではなくPOC向けに1件ずつ処理。
     */
    foreach ($selected as $customerId) {
        $customer = null;

        foreach (customer_list() as $item) {
            if (
                (string)($item['id'] ?? '')
                === (string)$customerId
            ) {
                $customer = $item;
                break;
            }
        }

        if (!is_array($customer)) {
            continue;
        }

        $email = trim(
            (string)($customer['email'] ?? '')
        );

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $logs[] = [
                'id' => 'send-' . uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'status' => 'failed',
                'message' => 'メールアドレスが不正です。',
                'createdAt' => now_iso(),
            ];
            continue;
        }

        $subject = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [
                (string)($customer['name'] ?? ''),
                absolute_answer_url($surveyId),
            ],
            (string)($_POST['subject'] ?? '')
        );

        $body = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [
                (string)($customer['name'] ?? ''),
                absolute_answer_url($surveyId),
            ],
            (string)($_POST['body'] ?? '')
        );

        try {
            smtp_send_mail(
                $mail,
                $email,
                $subject,
                $body
            );

            $logs[] = [
                'id' => 'send-' . uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'status' => 'sent',
                'message' => '送信成功',
                'createdAt' => now_iso(),
            ];
        } catch (Throwable $e) {
            $logs[] = [
                'id' => 'send-' . uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'status' => 'failed',
                'message' => 'SMTP送信に失敗しました。',
                'createdAt' => now_iso(),
            ];
        }
    }

    save_send_logs($logs);

    set_result(
        'success',
        '送信処理が完了しました。'
    );

    return 'send';
}

function save_answers(array $data): void
{
    write_json_atomic(
        ANSWERS_FILE,
        array_values($data)
    );
}

function absolute_answer_url(
    string $surveyId
): string {
    $scheme = (!empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    return $scheme
        . '://'
        . $host
        . '/'
        . ltrim(
            dirname(
                $_SERVER['SCRIPT_NAME'] ?? '/'
            ),
            '/'
        )
        . '/'
        . screen_url(
            'answer',
            $surveyId
        );
}

function smtp_send_mail(
    array $mail,
    string $to,
    string $subject,
    string $body
): void {
    $socket = smtp_open($mail);

    try {
        smtp_expect($socket, [220]);

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );

        if (
            ($mail['encryption'] ?? 'none')
            === 'tls'
        ) {
            smtp_starttls($socket);

            smtp_command(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (!empty($mail['auth'])) {
            smtp_command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtp_command(
                $socket,
                base64_encode(
                    (string)$mail['username']
                ),
                [334]
            );

            smtp_command(
                $socket,
                base64_encode(
                    (string)$mail['password']
                ),
                [235]
            );
        }

        $from = (string)$mail['from_email'];

        smtp_command(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $fromName = trim(
            (string)($mail['from_name'] ?? '')
        );

        $fromHeader = $from;

        if ($fromName !== '') {
            $fromHeader =
                '=?UTF-8?B?'
                . base64_encode($fromName)
                . '?= <'
                . $from
                . '>';
        }

        $headers = [
            'From: ' . $fromHeader,
            'To: ' . $to,
            'Subject: =?UTF-8?B?'
                . base64_encode($subject)
                . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $replyTo = trim(
            (string)($mail['reply_to'] ?? '')
        );

        if ($replyTo !== '') {
            $headers[] =
                'Reply-To: ' . $replyTo;
        }

        $message =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . str_replace(
                ["\r\n", "\r", "\n"],
                "\r\n",
                $body
            )
            . "\r\n.";

        fwrite(
            $socket,
            $message . "\r\n"
        );

        smtp_expect(
            $socket,
            [250]
        );

        smtp_command(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}

/* ------------------------------------------------------------
 * POST実行
 * ------------------------------------------------------------ */

$postScreen = handle_post();

if ($postScreen !== null) {
    $screen = $postScreen;
} else {
    $screen = (string)(
        $_GET['screen'] ?? 'list'
    );
}

$allowedScreens = [
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

if (!in_array(
    $screen,
    $allowedScreens,
    true
)) {
    $screen = 'list';
}

$id = trim((string)(
    $_GET['id'] ?? $_POST['id'] ?? ''
));

/*
 * analytics / send は必ず対象アンケートを固定。
 */
if (
    in_array(
        $screen,
        ['analytics', 'send'],
        true
    )
    && !valid_id($id)
) {
    $screen = 'list';

    set_result(
        'error',
        '対象アンケートが指定されていないため一覧へ戻りました。'
    );
}

$operationResult = take_result();

$currentSurvey =
    $id !== ''
        ? find_survey($id)
        : null;

if (
    in_array(
        $screen,
        [
            'edit',
            'preview',
            'send',
            'analytics',
            'answer',
            'confirm',
            'complete',
        ],
        true
    )
    && $currentSurvey === null
    && $id !== ''
) {
    $screen = 'list';

    set_result(
        'error',
        '指定されたアンケートが見つかりません。'
    );

    $operationResult = take_result();
}

if ($currentSurvey !== null) {
    renumber_survey($currentSurvey);
}

$settings = read_json(
    SETTINGS_FILE,
    []
);

if (!is_array($settings)) {
    $settings = [];
}

$kintoneSettings =
    is_array($settings['kintone'] ?? null)
        ? $settings['kintone']
        : [];

$mailSettings =
    is_array($settings['mail'] ?? null)
        ? $settings['mail']
        : [];

$customerData = customer_list();

$allAnswers = answers();

/* ------------------------------------------------------------
 * 表示用関数
 * ------------------------------------------------------------ */

function status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function answer_count(
    array $survey,
    array $answers
): int {
    $count = 0;

    foreach ($answers as $answer) {
        if (
            is_array($answer)
            && (string)($answer['surveyId'] ?? '')
                === (string)$survey['id']
        ) {
            $count++;
        }
    }

    return $count;
}

function format_datetime(string $value): string
{
    if ($value === '') {
        return '-';
    }

    $time = strtotime($value);

    if ($time === false) {
        return $value;
    }

    return date(
        'Y/m/d H:i',
        $time
    );
}

/* ------------------------------------------------------------
 * HTML
 * ------------------------------------------------------------ */

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>アンケート管理</title>

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
}

* {
    box-sizing:border-box;
}

html,
body {
    margin:0;
    padding:0;
}

body {
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

header {
    background:#0f172a;
    color:#fff;
    padding:18px 24px;
}

header .header-inner {
    max-width:1400px;
    margin:0 auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

nav {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

nav a {
    color:#fff;
    text-decoration:none;
    padding:7px 10px;
    border-radius:6px;
}

nav a:hover {
    background:rgba(255,255,255,.1);
}

.container {
    max-width:1400px;
    margin:0 auto;
    padding:24px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}

h1,
h2,
h3 {
    margin-top:0;
}

.form-row {
    margin-bottom:16px;
}

label {
    display:block;
    font-weight:600;
    margin-bottom:6px;
}

input,
textarea,
select {
    width:100%;
    padding:10px 12px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    font:inherit;
    background:#fff;
}

textarea {
    min-height:120px;
}

input[type="checkbox"],
input[type="radio"] {
    width:auto;
}

button,
.btn {
    display:inline-block;
    border:0;
    border-radius:8px;
    padding:10px 16px;
    font:inherit;
    font-weight:600;
    cursor:pointer;
    text-decoration:none;
}

.primary {
    background:var(--primary);
    color:#fff;
}

.primary:hover {
    background:var(--primary-dark);
}

.secondary {
    background:#e2e8f0;
    color:#172033;
}

.success {
    background:var(--success);
    color:#fff;
}

.warning {
    background:var(--warning);
    color:#fff;
}

.danger {
    background:var(--danger);
    color:#fff;
}

.actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
}

.notice {
    padding:14px 16px;
    border-radius:8px;
    margin-bottom:20px;
}

.notice.success {
    color:#166534;
    background:#dcfce7;
    border:1px solid #86efac;
}

.notice.error {
    color:#991b1b;
    background:#fee2e2;
    border:1px solid #fca5a5;
}

.notice.warning {
    color:#92400e;
    background:#fef3c7;
    border:1px solid #fcd34d;
}

.status {
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    background:#e2e8f0;
    white-space:nowrap;
}

.status.published {
    color:#166534;
    background:#dcfce7;
}

.status.stopped {
    color:#92400e;
    background:#fef3c7;
}

.status.ended {
    color:#991b1b;
    background:#fee2e2;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
}

th,
td {
    padding:10px;
    border-bottom:1px solid #e2e8f0;
    text-align:left;
    vertical-align:top;
}

th {
    background:#f8fafc;
    white-space:nowrap;
}

.grid {
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:16px;
}

.grid-3 {
    display:grid;
    grid-template-columns:
        repeat(3,minmax(0,1fr));
    gap:16px;
}

.question-card,
.group-card {
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    margin-bottom:12px;
    background:#fff;
}

.option-row {
    display:grid;
    grid-template-columns:1fr auto;
    gap:8px;
    margin-bottom:8px;
}

.muted {
    color:var(--gray);
}

.small {
    font-size:.9rem;
}

.answer-question {
    margin-bottom:24px;
}

.answer-option {
    display:block;
    padding:12px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:8px;
}

.answer-option:hover {
    background:#f8fafc;
}

.kintone-error {
    white-space:pre-wrap;
    word-break:break-word;
}

.processing {
    opacity:.7;
    pointer-events:none;
}

.spinner {
    display:none;
    margin-left:8px;
}

@media(max-width:800px) {
    .container {
        padding:12px;
    }

    .grid,
    .grid-3 {
        grid-template-columns:1fr;
    }

    header {
        padding:14px;
    }

    header .header-inner {
        align-items:flex-start;
        flex-direction:column;
    }

    table {
        min-width:900px;
    }
}
</style>
</head>

<body>

<header>
    <div class="header-inner">
        <strong>アンケート管理</strong>

        <?php if (
            !in_array(
                $screen,
                ['answer', 'confirm', 'complete'],
                true
            )
        ): ?>
        <nav>
            <a href="<?=e(screen_url('list'))?>">
                アンケート一覧
            </a>
            <a href="<?=e(screen_url('kintone'))?>">
                kintone
            </a>
            <a href="<?=e(screen_url('mail'))?>">
                メール
            </a>
        </nav>
        <?php endif; ?>
    </div>
</header>

<main class="container">

<?php if ($operationResult !== null): ?>
<div class="notice <?=e(
    $operationResult['type']
)?>">
    <?=e(
        $operationResult['message']
    )?>
</div>
<?php endif; ?>


<?php
/* ============================================================
 * 一覧
 * ============================================================ */
?>

<?php if ($screen === 'list'): ?>

<?php
$list = [];

foreach (surveys() as $item) {
    if (!is_array($item)) {
        continue;
    }

    $item = normalize_survey($item);

    /*
     * 読み込み時に状態を再判定。
     */
    if (
        $item['status'] === 'published'
        && $item['endAt'] !== ''
    ) {
        try {
            if (
                new DateTimeImmutable(
                    $item['endAt']
                ) < new DateTimeImmutable()
            ) {
                $item['status'] = 'ended';
            }
        } catch (Throwable) {
        }
    }

    $list[] = $item;
}

$keyword = trim((string)(
    $_GET['q'] ?? ''
));

$statusFilter = (string)(
    $_GET['status'] ?? ''
);

$sort = (string)(
    $_GET['sort'] ?? 'updated_desc'
);

$list = array_filter(
    $list,
    static function (array $survey)
        use ($keyword, $statusFilter): bool {

        if (
            $keyword !== ''
            && mb_stripos(
                $survey['title'],
                $keyword
            ) === false
        ) {
            return false;
        }

        if (
            $statusFilter !== ''
            && $statusFilter !== 'all'
            && $survey['status'] !== $statusFilter
        ) {
            return false;
        }

        return true;
    }
);

usort(
    $list,
    static function (
        array $a,
        array $b
    ) use ($sort): int {

        return match ($sort) {
            'updated_asc' =>
                strcmp(
                    $a['updatedAt'],
                    $b['updatedAt']
                ),

            'answers_desc' =>
                answer_count(
                    $b,
                    $GLOBALS['allAnswers']
                )
                <=>
                answer_count(
                    $a,
                    $GLOBALS['allAnswers']
                ),

            'answers_asc' =>
                answer_count(
                    $a,
                    $GLOBALS['allAnswers']
                )
                <=>
                answer_count(
                    $b,
                    $GLOBALS['allAnswers']
                ),

            'start_desc' =>
                strcmp(
                    $b['startAt'],
                    $a['startAt']
                ),

            'start_asc' =>
                strcmp(
                    $a['startAt'],
                    $b['startAt']
                ),

            default =>
                strcmp(
                    $b['updatedAt'],
                    $a['updatedAt']
                ),
        };
    }
);
?>

<div class="card">

    <div class="actions"
         style="justify-content:space-between">

        <div>
            <h1>アンケート一覧</h1>
            <p class="muted">
                アンケートの作成・編集・集計・送信を管理します。
            </p>
        </div>

        <a class="btn primary"
           href="<?=e(screen_url('edit'))?>">
            新規作成
        </a>

    </div>

    <form method="get">
        <input type="hidden"
               name="screen"
               value="list">

        <div class="grid-3">

            <div class="form-row">
                <label>検索</label>
                <input
                    type="text"
                    name="q"
                    value="<?=e($keyword)?>"
                    placeholder="タイトルを検索">
            </div>

            <div class="form-row">
                <label>状態</label>
                <select name="status">
                    <option value="all">すべて</option>
                    <option value="published"
                        <?=$statusFilter === 'published'
                            ? 'selected' : ''?>>
                        公開中
                    </option>
                    <option value="draft"
                        <?=$statusFilter === 'draft'
                            ? 'selected' : ''?>>
                        下書き
                    </option>
                    <option value="stopped"
                        <?=$statusFilter === 'stopped'
                            ? 'selected' : ''?>>
                        停止
                    </option>
                    <option value="ended"
                        <?=$statusFilter === 'ended'
                            ? 'selected' : ''?>>
                        終了
                    </option>
                </select>
            </div>

            <div class="form-row">
                <label>ソート</label>
                <select name="sort">
                    <option value="updated_desc"
                        <?=$sort === 'updated_desc'
                            ? 'selected' : ''?>>
                        更新日：新しい順
                    </option>
                    <option value="updated_asc"
                        <?=$sort === 'updated_asc'
                            ? 'selected' : ''?>>
                        更新日：古い順
                    </option>
                    <option value="answers_desc"
                        <?=$sort === 'answers_desc'
                            ? 'selected' : ''?>>
                        回答数：多い順
                    </option>
                    <option value="answers_asc"
                        <?=$sort === 'answers_asc'
                            ? 'selected' : ''?>>
                        回答数：少ない順
                    </option>
                    <option value="start_desc"
                        <?=$sort === 'start_desc'
                            ? 'selected' : ''?>>
                        開始日：新しい順
                    </option>
                    <option value="start_asc"
                        <?=$sort === 'start_asc'
                            ? 'selected' : ''?>>
                        開始日：古い順
                    </option>
                </select>
            </div>

        </div>

        <button class="secondary"
                type="submit">
            検索・絞り込み
        </button>

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

            <?php if (!$list): ?>

            <tr>
                <td colspan="7">
                    アンケートがありません。
                </td>
            </tr>

            <?php else: ?>

            <?php foreach ($list as $survey): ?>

            <tr>

                <td>
                    <strong>
                        <?=e($survey['title'])?>
                    </strong>
                </td>

                <td>
                    <?=e(
                        format_datetime(
                            $survey['createdAt']
                        )
                    )?>
                </td>

                <td>
                    <?=e(
                        format_datetime(
                            $survey['updatedAt']
                        )
                    )?>
                </td>

                <td>
                    <?=e(
                        format_datetime(
                            $survey['startAt']
                        )
                    )?>
                    ～
                    <?=e(
                        format_datetime(
                            $survey['endAt']
                        )
                    )?>
                </td>

                <td>
                    <span class="status
                        <?=e($survey['status'])?>">
                        <?=e(
                            status_label(
                                $survey['status']
                            )
                        )?>
                    </span>
                </td>

                <td>
                    <?=e(
                        answer_count(
                            $survey,
                            $allAnswers
                        )
                    )?>
                </td>

                <td>
                    <div class="actions">

                        <a class="btn secondary"
                           href="<?=e(
                               screen_url(
                                   'edit',
                                   $survey['id']
                               )
                           )?>">
                            確認・編集
                        </a>

                        <a class="btn secondary"
                           href="<?=e(
                               screen_url(
                                   'preview',
                                   $survey['id']
                               )
                           )?>">
                            プレビュー
                        </a>

                        <a class="btn secondary"
                           href="<?=e(
                               screen_url(
                                   'analytics',
                                   $survey['id']
                               )
                           )?>">
                            集計
                        </a>

                        <a class="btn secondary"
                           href="<?=e(
                               screen_url(
                                   'send',
                                   $survey['id']
                               )
                           )?>">
                            送信
                        </a>

                        <form method="post"
                              style="display:inline"
                              onsubmit="return confirm(
                                  'このアンケートを複製しますか？'
                              );">
                            <input type="hidden"
                                   name="action"
                                   value="duplicate_survey">
                            <input type="hidden"
                                   name="id"
                                   value="<?=e(
                                       $survey['id']
                                   )?>">
                            <button class="secondary">
                                複製
                            </button>
                        </form>

                        <form method="post"
                              style="display:inline"
                              onsubmit="return confirm(
                                  'このアンケートを削除しますか？'
                              );">
                            <input type="hidden"
                                   name="action"
                                   value="delete_survey">
                            <input type="hidden"
                                   name="id"
                                   value="<?=e(
                                       $survey['id']
                                   )?>">
                            <button class="danger">
                                削除
                            </button>
                        </form>

                    </div>
                </td>

            </tr>

            <?php endforeach; ?>

            <?php endif; ?>

            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>


<?php
/* ============================================================
 * 編集
 * ============================================================ */
?>

<?php if ($screen === 'edit'): ?>

<?php
$editSurvey =
    $currentSurvey
    ?? default_survey();

renumber_survey($editSurvey);
?>

<div class="card">

    <div class="actions"
         style="justify-content:space-between">

        <div>
            <h1>
                アンケート作成・編集
            </h1>
        </div>

        <div class="actions">

            <a class="btn secondary"
               href="<?=e(
                   screen_url('list')
               )?>">
                キャンセル
            </a>

            <button
                form="survey-form"
                class="primary"
                type="submit">
                保存して一覧へ
            </button>

        </div>

    </div>

    <form
        id="survey-form"
        method="post">

        <input type="hidden"
               name="action"
               value="save_survey">

        <input type="hidden"
               name="id"
               value="<?=e(
                   $editSurvey['id']
               )?>">

        <input type="hidden"
               id="groups_json"
               name="groups_json">

        <div class="grid">

            <div class="form-row">
                <label>アンケートタイトル</label>
                <input
                    name="title"
                    required
                    maxlength="200"
                    value="<?=e(
                        $editSurvey['title']
                    )?>">
            </div>

            <div class="form-row">
                <label>状態</label>
                <input
                    readonly
                    value="<?=e(
                        status_label(
                            $editSurvey['status']
                        )
                    )?>">
            </div>

        </div>

        <div class="form-row">
            <label>アンケート説明</label>
            <textarea
                name="description"
                maxlength="5000"><?=e(
                    $editSurvey['description']
                )?></textarea>
        </div>

        <div class="grid">

            <div class="form-row">
                <label>開始日時</label>
                <input
                    type="datetime-local"
                    name="startAt"
                    value="<?=e(
                        $editSurvey['startAt']
                    )?>">
            </div>

            <div class="form-row">
                <label>終了日時</label>
                <input
                    type="datetime-local"
                    name="endAt"
                    value="<?=e(
                        $editSurvey['endAt']
                    )?>">
            </div>

        </div>

        <div class="form-row">
            <label>質問番号の採番方式</label>

            <select name="numbering">

                <option
                    value="global"
                    <?=$editSurvey['numbering']
                        === 'global'
                        ? 'selected'
                        : ''?>>
                    アンケート全体で通番
                    （Q1、Q2、Q3…）
                </option>

                <option
                    value="group"
                    <?=$editSurvey['numbering']
                        === 'group'
                        ? 'selected'
                        : ''?>>
                    グループ毎に採番
                    （Q1-1、Q1-2、Q2-1…）
                </option>

            </select>
        </div>

        <div id="groups-editor"></div>

        <div class="actions">
            <button
                type="button"
                class="secondary"
                onclick="addGroup()">
                グループを追加
            </button>
        </div>

    </form>
</div>

<script>
const initialGroups =
<?=json_encode(
    $editSurvey['groups'],
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
)?>;

let groups =
    Array.isArray(initialGroups)
        ? initialGroups
        : [];

function uid(prefix) {
    return prefix + '-' +
        Date.now().toString(36) +
        '-' +
        Math.random()
            .toString(36)
            .slice(2);
}

function newQuestion() {
    return {
        id:uid('question'),
        text:'',
        type:'single',
        required:false,
        options:[
            {
                id:uid('option'),
                text:'',
                nextQuestionId:''
            }
        ]
    };
}

function newGroup() {
    return {
        id:uid('group'),
        title:'新しいグループ',
        questions:[newQuestion()]
    };
}

function renderGroups() {

    const root =
        document.getElementById(
            'groups-editor'
        );

    root.innerHTML = '';

    groups.forEach(
        (group, gi) => {

            const box =
                document.createElement('div');

            box.className =
                'group-card';

            let html = `
                <div class="actions"
                     style="justify-content:space-between">
                    <strong>
                        グループ ${gi + 1}
                    </strong>

                    <div class="actions">
                        ${
                            gi > 0
                            ? `<button type="button"
                                class="secondary"
                                onclick="moveGroup(
                                    ${gi},${gi - 1}
                                )">
                                ↑
                              </button>`
                            : ''
                        }

                        ${
                            gi < groups.length - 1
                            ? `<button type="button"
                                class="secondary"
                                onclick="moveGroup(
                                    ${gi},${gi + 1}
                                )">
                                ↓
                              </button>`
                            : ''
                        }

                        <button
                            type="button"
                            class="danger"
                            onclick="removeGroup(${gi})">
                            グループ削除
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <label>グループタイトル</label>
                    <input
                        value="${escapeHtml(
                            group.title || ''
                        )}"
                        oninput="groups[${gi}].title=this.value">
                </div>
            `;

            group.questions =
                Array.isArray(group.questions)
                    ? group.questions
                    : [];

            group.questions.forEach(
                (question, qi) => {

                    html += renderQuestion(
                        group,
                        question,
                        gi,
                        qi
                    );
                }
            );

            /*
             * 質問追加ボタンはグループ末尾のみ。
             */
            html += `
                <button
                    type="button"
                    class="secondary"
                    onclick="addQuestion(${gi})">
                    質問を追加
                </button>
            `;

            box.innerHTML = html;

            root.appendChild(box);
        }
    );

    syncJson();
}

function renderQuestion(
    group,
    question,
    gi,
    qi
) {
    const type =
        question.type || 'single';

    const number =
        'Q' + (gi + 1) + '-' + (qi + 1);

    let options = '';

    if (
        type === 'single'
        || type === 'multiple'
    ) {
        const list =
            Array.isArray(question.options)
                ? question.options
                : [];

        options = `
            <div class="form-row">
                <label>選択肢</label>

                ${list.map(
                    (option, oi) => `
                    <div class="option-row">

                        <input
                            value="${escapeHtml(
                                option.text || ''
                            )}"
                            oninput="
                                groups[${gi}]
                                .questions[${qi}]
                                .options[${oi}]
                                .text=this.value
                            ">

                        <button
                            type="button"
                            class="danger"
                            onclick="
                                removeOption(
                                    ${gi},
                                    ${qi},
                                    ${oi}
                                )
                            ">
                            削除
                        </button>

                    </div>
                `).join('')}

                <button
                    type="button"
                    class="secondary"
                    onclick="
                        addOption(
                            ${gi},
                            ${qi}
                        )
                    ">
                    選択肢を追加
                </button>
            </div>
        `;
    }

    return `
        <div class="question-card">

            <div class="actions"
                 style="justify-content:space-between">

                <strong>
                    ${number}
                </strong>

                <div class="actions">

                    ${
                        qi > 0
                        ? `<button
                            type="button"
                            class="secondary"
                            onclick="
                                moveQuestion(
                                    ${gi},
                                    ${qi},
                                    ${qi - 1}
                                )
                            ">
                            ↑
                          </button>`
                        : ''
                    }

                    ${
                        qi < group.questions.length - 1
                        ? `<button
                            type="button"
                            class="secondary"
                            onclick="
                                moveQuestion(
                                    ${gi},
                                    ${qi},
                                    ${qi + 1}
                                )
                            ">
                            ↓
                          </button>`
                        : ''
                    }

                    <button
                        type="button"
                        class="danger"
                        onclick="
                            removeQuestion(
                                ${gi},
                                ${qi}
                            )
                        ">
                        質問削除
                    </button>

                </div>

            </div>

            <div class="form-row">
                <label>質問文</label>
                <textarea
                    oninput="
                        groups[${gi}]
                        .questions[${qi}]
                        .text=this.value
                    ">${escapeHtml(
                        question.text || ''
                    )}</textarea>
            </div>

            <div class="grid">

                <div class="form-row">
                    <label>回答形式</label>

                    <select
                        onchange="
                            changeType(
                                ${gi},
                                ${qi},
                                this.value
                            )
                        ">

                        <option
                            value="single"
                            ${type === 'single'
                                ? 'selected'
                                : ''}>
                            単一選択
                        </option>

                        <option
                            value="multiple"
                            ${type === 'multiple'
                                ? 'selected'
                                : ''}>
                            複数選択
                        </option>

                        <option
                            value="text"
                            ${type === 'text'
                                ? 'selected'
                                : ''}>
                            自由記述
                        </option>

                    </select>
                </div>

                <div class="form-row">

                    <label>
                        <input
                            type="checkbox"
                            ${question.required
                                ? 'checked'
                                : ''}
                            onchange="
                                groups[${gi}]
                                .questions[${qi}]
                                .required=
                                this.checked
                            ">
                        必須
                    </label>

                </div>

            </div>

            ${options}

        </div>
    `;
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

function syncJson() {
    document.getElementById(
        'groups_json'
    ).value =
        JSON.stringify(groups);
}

function addGroup() {
    groups.push(newGroup());
    renderGroups();
}

function removeGroup(index) {
    if (groups.length <= 1) {
        alert(
            'グループは1つ以上必要です。'
        );
        return;
    }

    if (!confirm(
        'このグループを削除しますか？'
    )) {
        return;
    }

    groups.splice(index,1);
    renderGroups();
}

function moveGroup(from,to) {
    const item =
        groups.splice(from,1)[0];

    groups.splice(to,0,item);
    renderGroups();
}

function addQuestion(gi) {
    groups[gi].questions.push(
        newQuestion()
    );

    renderGroups();
}

function removeQuestion(gi,qi) {

    if (
        groups[gi].questions.length <= 1
    ) {
        alert(
            'グループには質問を1つ以上配置してください。'
        );
        return;
    }

    if (!confirm(
        'この質問を削除しますか？'
    )) {
        return;
    }

    groups[gi].questions.splice(qi,1);
    renderGroups();
}

function moveQuestion(gi,from,to) {

    const item =
        groups[gi].questions
            .splice(from,1)[0];

    groups[gi].questions
        .splice(to,0,item);

    renderGroups();
}

function addOption(gi,qi) {

    groups[gi]
        .questions[qi]
        .options
        .push({
            id:uid('option'),
            text:'',
            nextQuestionId:''
        });

    renderGroups();
}

function removeOption(gi,qi,oi) {

    const options =
        groups[gi]
            .questions[qi]
            .options;

    if (options.length <= 1) {
        alert(
            '選択肢は1つ以上必要です。'
        );
        return;
    }

    options.splice(oi,1);
    renderGroups();
}

function changeType(gi,qi,type) {

    groups[gi]
        .questions[qi]
        .type = type;

    if (type === 'text') {
        groups[gi]
            .questions[qi]
            .options = [];
    } else if (
        !groups[gi]
            .questions[qi]
            .options.length
    ) {
        groups[gi]
            .questions[qi]
            .options = [{
                id:uid('option'),
                text:'',
                nextQuestionId:''
            }];
    }

    renderGroups();
}

document
    .getElementById('survey-form')
    .addEventListener(
        'submit',
        function() {
            syncJson();
        }
    );

renderGroups();
</script>

<?php endif; ?>


<?php
/* ============================================================
 * プレビュー
 * ============================================================ */
?>

<?php if (
    $screen === 'preview'
    && $currentSurvey !== null
): ?>

<div class="card">

    <div class="actions"
         style="justify-content:space-between">

        <div>
            <h1>プレビュー</h1>
            <p class="muted">
                実際のメール送信等は行いません。
            </p>
        </div>

        <a class="btn secondary"
           href="<?=e(
               screen_url(
                   'edit',
                   $currentSurvey['id']
               )
           )?>">
            編集へ戻る
        </a>

    </div>

    <div class="card">
        <h2>
            <?=e(
                $currentSurvey['title']
            )?>
        </h2>

        <p>
            <?=nl2br(
                e(
                    $currentSurvey['description']
                )
            )?>
        </p>
    </div>

    <?php foreach (
        $currentSurvey['groups']
        as $group
    ): ?>

    <div class="card">

        <h3>
            <?=e(
                $group['title']
            )?>
        </h3>

        <?php foreach (
            $group['questions']
            as $question
        ): ?>

        <div class="answer-question">

            <strong>
                <?=e(
                    $question['_number']
                    ?? ''
                )?>
                .
                <?=e(
                    $question['text']
                )?>

                <?php if (
                    !empty(
                        $question['required']
                    )
                ): ?>
                    <span style="color:#dc2626">
                        *
                    </span>
                <?php endif; ?>
            </strong>

            <?php if (
                $question['type'] === 'text'
            ): ?>

                <textarea
                    disabled></textarea>

            <?php else: ?>

                <?php foreach (
                    $question['options']
                    as $option
                ): ?>

                <label class="answer-option">

                    <input
                        type="<?=
                            $question['type']
                                === 'single'
                                ? 'radio'
                                : 'checkbox'
                        ?>"
                        disabled>

                    <?=e(
                        $option['text']
                    )?>

                </label>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

        <?php endforeach; ?>

    </div>

    <?php endforeach; ?>

</div>

<?php endif; ?>


<?php
/* ============================================================
 * 回答者
 * ============================================================ */
?>

<?php if (
    $screen === 'answer'
    && $currentSurvey !== null
): ?>

<?php
if (
    $currentSurvey['status'] !== 'published'
) {
?>
<div class="card">
    <h1>回答できません</h1>
    <p>
        このアンケートは現在公開されていません。
    </p>
</div>
<?php
} else {
?>

<div class="card">

    <h1>
        <?=e(
            $currentSurvey['title']
        )?>
    </h1>

    <p>
        <?=nl2br(
            e(
                $currentSurvey['description']
            )
        )?>
    </p>

    <form method="post">

        <input type="hidden"
               name="action"
               value="save_answer">

        <input type="hidden"
               name="survey_id"
               value="<?=e(
                   $currentSurvey['id']
               )?>">

        <?php foreach (
            $currentSurvey['groups']
            as $group
        ): ?>

        <div class="card">

            <h2>
                <?=e(
                    $group['title']
                )?>
            </h2>

            <?php foreach (
                $group['questions']
                as $question
            ): ?>

            <div class="answer-question">

                <label>
                    <?=e(
                        $question['_number']
                    )?>
                    .
                    <?=e(
                        $question['text']
                    )?>

                    <?php if (
                        !empty(
                            $question['required']
                        )
                    ): ?>
                    <span style="color:#dc2626">
                        *
                    </span>
                    <?php endif; ?>
                </label>

                <?php if (
                    $question['type']
                    === 'text'
                ): ?>

                <textarea
                    name="answers[
                        <?=e(
                            $question['id']
                        )?>
                    ]"></textarea>

                <?php else: ?>

                <?php foreach (
                    $question['options']
                    as $option
                ): ?>

                <label class="answer-option">

                    <input
                        type="<?=
                            $question['type']
                                === 'single'
                                ? 'radio'
                                : 'checkbox'
                        ?>"
                        name="<?=
                            $question['type']
                                === 'single'
                                ? 'answers['
                                    . e(
                                        $question['id']
                                    )
                                    . ']'
                                : 'answers['
                                    . e(
                                        $question['id']
                                    )
                                    . '][]'
                        ?>"
                        value="<?=e(
                            $option['id']
                        )?>">

                    <?=e(
                        $option['text']
                    )?>

                </label>

                <?php endforeach; ?>

                <?php endif; ?>

            </div>

            <?php endforeach; ?>

        </div>

        <?php endforeach; ?>

        <button
            class="primary"
            type="submit">
            回答を確認する
        </button>

    </form>

</div>

<?php
}
?>

<?php endif; ?>


<?php
/* ============================================================
 * 完了
 * ============================================================ */
?>

<?php if ($screen === 'complete'): ?>

<div class="card">

    <h1>回答完了</h1>

    <p>
        回答を受け付けました。
    </p>

</div>

<?php endif; ?>


<?php
/* ============================================================
 * kintone設定
 * ============================================================ */
?>

<?php if ($screen === 'kintone'): ?>

<div class="card">

    <h1>kintone連携設定</h1>

    <p class="muted">
        顧客情報をkintoneから取得します。
        認証情報はサーバー側だけで使用します。
    </p>

    <form method="post">

        <input type="hidden"
               name="action"
               value="save_kintone">

        <div class="form-row">

            <label>
                サブドメイン
            </label>

            <input
                name="subdomain"
                required
                value="<?=e(
                    $kintoneSettings['subdomain']
                    ?? ''
                )?>"
                placeholder="example / example.cybozu.com">

            <div class="small muted">
                https://example.cybozu.com、
                example.cybozu.com、example のいずれでも入力できます。
            </div>

        </div>

        <div class="form-row">

            <label>
                顧客管理アプリID
            </label>

            <input
                name="app_id"
                required
                inputmode="numeric"
                value="<?=e(
                    $kintoneSettings['app_id']
                    ?? ''
                )?>">

        </div>

        <div class="form-row">

            <label>
                ログイン名
            </label>

            <input
                name="username"
                required
                value="<?=e(
                    $kintoneSettings['username']
                    ?? ''
                )?>">

        </div>

        <div class="form-row">

            <label>
                パスワード
            </label>

            <input
                type="password"
                name="password"
                placeholder="変更しない場合は空欄">

        </div>

        <div class="form-row">

            <label>
                Proxy
            </label>

            <input
                name="proxy"
                value="<?=e(
                    $kintoneSettings['proxy']
                    ?? ''
                )?>"
                placeholder="host:port">

            <div class="small muted">
                未入力の場合は直接接続します。
            </div>

        </div>

        <div class="form-row">

            <label>
                <input
                    type="checkbox"
                    name="verify_ssl"
                    value="1"
                    <?=
                        !empty(
                            $kintoneSettings[
                                'verify_ssl'
                            ]
                        )
                            ? 'checked'
                            : ''
                    ?>>
                SSL証明書を検証する
            </label>

            <div class="small muted">
                POCでは初期状態を無効とします。
            </div>

        </div>

        <button
            class="primary"
            type="submit">
            設定保存
        </button>

    </form>

</div>


<div class="card">

    <h2>kintone操作</h2>

    <div class="actions">

        <form method="post">
            <input type="hidden"
                   name="action"
                   value="test_kintone">

            <button
                class="primary"
                type="submit"
                onclick="
                    this.disabled=true;
                    this.innerText='接続確認中...';
                ">
                接続テスト
            </button>
        </form>

        <form method="post">
            <input type="hidden"
                   name="action"
                   value="fetch_kintone_fields">

            <button
                class="secondary"
                type="submit">
                項目一覧を再取得
            </button>
        </form>

        <form method="post">
            <input type="hidden"
                   name="action"
                   value="sync_kintone">

            <button
                class="secondary"
                type="submit">
                顧客情報を同期
            </button>
        </form>

    </div>

</div>


<?php
$fields =
    is_array(
        $kintoneSettings['fields']
        ?? null
    )
        ? $kintoneSettings['fields']
        : [];
?>

<?php if ($fields): ?>

<div class="card">

    <h2>取得済みkintone項目</h2>

    <div class="table-wrap">

        <table>

            <thead>
            <tr>
                <th>フィールドコード</th>
                <th>ラベル</th>
                <th>種類</th>
            </tr>
            </thead>

            <tbody>

            <?php foreach (
                $fields as $code => $field
            ): ?>

            <tr>

                <td>
                    <?=e($code)?>
                </td>

                <td>
                    <?=e(
                        $field['label']
                        ?? ''
                    )?>
                </td>

                <td>
                    <?=e(
                        $field['type']
                        ?? ''
                    )?>
                </td>

            </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

<?php endif; ?>

<?php endif; ?>


<?php
/* ============================================================
 * メール設定
 * ============================================================ */
?>

<?php if ($screen === 'mail'): ?>

<div class="card">

    <h1>メールサーバ設定</h1>

    <form method="post">

        <input type="hidden"
               name="action"
               value="save_mail">

        <div class="grid">

            <div class="form-row">

                <label>
                    SMTPサーバ
                </label>

                <input
                    name="host"
                    required
                    value="<?=e(
                        $mailSettings['host']
                        ?? ''
                    )?>">

            </div>

            <div class="form-row">

                <label>
                    SMTPポート
                </label>

                <input
                    type="number"
                    name="port"
                    min="1"
                    max="65535"
                    required
                    value="<?=e(
                        $mailSettings['port']
                        ?? ''
                    )?>">

            </div>

        </div>

        <div class="form-row">

            <label>
                暗号化方式
            </label>

            <select name="encryption">

                <option
                    value="ssl"
                    <?=
                        ($mailSettings[
                            'encryption'
                        ] ?? '')
                        === 'ssl'
                            ? 'selected'
                            : ''
                    ?>>
                    SSL
                </option>

                <option
                    value="tls"
                    <?=
                        ($mailSettings[
                            'encryption'
                        ] ?? 'tls')
                        === 'tls'
                            ? 'selected'
                            : ''
                    ?>>
                    TLS
                </option>

                <option
                    value="none"
                    <?=
                        ($mailSettings[
                            'encryption'
                        ] ?? '')
                        === 'none'
                            ? 'selected'
                            : ''
                    ?>>
                    なし
                </option>

            </select>

        </div>

        <div class="form-row">

            <label>
                <input
                    type="checkbox"
                    name="auth"
                    value="1"
                    <?=
                        !empty(
                            $mailSettings['auth']
                        )
                            ? 'checked'
                            : ''
                    ?>>
                SMTP認証を使用
            </label>

        </div>

        <div class="grid">

            <div class="form-row">

                <label>
                    SMTPユーザー名
                </label>

                <input
                    name="username"
                    value="<?=e(
                        $mailSettings['username']
                        ?? ''
                    )?>">

            </div>

            <div class="form-row">

                <label>
                    SMTPパスワード
                </label>

                <input
                    type="password"
                    name="password"
                    placeholder="変更しない場合は空欄">

            </div>

        </div>

        <div class="grid">

            <div class="form-row">

                <label>
                    送信元メールアドレス
                </label>

                <input
                    type="email"
                    name="from_email"
                    required
                    value="<?=e(
                        $mailSettings[
                            'from_email'
                        ] ?? ''
                    )?>">

            </div>

            <div class="form-row">

                <label>
                    送信元名
                </label>

                <input
                    name="from_name"
                    value="<?=e(
                        $mailSettings[
                            'from_name'
                        ] ?? ''
                    )?>">

            </div>

        </div>

        <div class="form-row">

            <label>
                返信先メールアドレス
            </label>

            <input
                type="email"
                name="reply_to"
                value="<?=e(
                    $mailSettings[
                        'reply_to'
                    ] ?? ''
                )?>">

        </div>

        <div class="actions">

            <button
                class="primary"
                type="submit">
                設定保存
            </button>

        </div>

    </form>

</div>


<div class="card">

    <h2>接続確認</h2>

    <form method="post">

        <input type="hidden"
               name="action"
               value="test_mail">

        <button
            class="primary"
            type="submit">
            接続テスト
        </button>

    </form>

</div>

<?php endif; ?>


<?php
/* ============================================================
 * 集計
 * ============================================================ */
?>

<?php if (
    $screen === 'analytics'
    && $currentSurvey !== null
): ?>

<?php
$surveyAnswers = array_values(
    array_filter(
        $allAnswers,
        static function ($answer)
            use ($currentSurvey): bool {

            return is_array($answer)
                && (string)(
                    $answer['surveyId']
                    ?? ''
                )
                === (string)(
                    $currentSurvey['id']
                );
        }
    )
);
?>

<div class="card">

    <div class="actions"
         style="justify-content:space-between">

        <div>

            <h1>
                回答集計・分析
            </h1>

            <p>
                対象アンケート:
                <strong>
                    <?=e(
                        $currentSurvey['title']
                    )?>
                </strong>
            </p>

        </div>

        <a class="btn secondary"
           href="<?=e(
               screen_url('list')
           )?>">
            一覧へ
        </a>

    </div>

    <div class="grid-3">

        <div class="card">
            <strong>送信対象者数</strong>
            <h2>
                <?=e(
                    count($customerData)
                )?>
            </h2>
        </div>

        <div class="card">
            <strong>回答数</strong>
            <h2>
                <?=e(
                    count($surveyAnswers)
                )?>
            </h2>
        </div>

        <div class="card">
            <strong>回答率</strong>
            <h2>
                <?php
                $totalCustomers =
                    count($customerData);

                $rate =
                    $totalCustomers > 0
                        ? (
                            count($surveyAnswers)
                            / $totalCustomers
                            * 100
                        )
                        : 0;
                ?>
                <?=e(
                    number_format(
                        $rate,
                        1
                    )
                )?>%
            </h2>
        </div>

    </div>

</div>


<?php if (!$surveyAnswers): ?>

<div class="card">

    <h2>
        現在、回答データはありません
    </h2>

</div>

<?php else: ?>

<div class="card">

    <h2>個別回答</h2>

    <div class="table-wrap">

        <table>

            <thead>
            <tr>
                <th>回答日時</th>
                <th>回答内容</th>
            </tr>
            </thead>

            <tbody>

            <?php foreach (
                $surveyAnswers as $answer
            ): ?>

            <tr>

                <td>
                    <?=e(
                        format_datetime(
                            (string)(
                                $answer[
                                    'createdAt'
                                ] ?? ''
                            )
                        )
                    )?>
                </td>

                <td>

                    <?php
                    $values =
                        is_array(
                            $answer['answers']
                            ?? null
                        )
                            ? $answer['answers']
                            : [];
                    ?>

                    <?php foreach (
                        $values as $qid => $value
                    ): ?>

                    <div>
                        <strong>
                            <?=e($qid)?>
                        </strong>:

                        <?=e(
                            is_array($value)
                                ? implode(
                                    ', ',
                                    $value
                                )
                                : $value
                        )?>
                    </div>

                    <?php endforeach; ?>

                </td>

            </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

<?php endif; ?>

<?php endif; ?>


<?php
/* ============================================================
 * 送信
 * ============================================================ */
?>

<?php if (
    $screen === 'send'
    && $currentSurvey !== null
): ?>

<?php
$sendLogs =
    array_values(
        array_filter(
            send_logs(),
            static function ($log)
                use ($currentSurvey): bool {
                return is_array($log)
                    && (string)(
                        $log['surveyId'] ?? ''
                    )
                    === (string)(
                        $currentSurvey['id']
                    );
            }
        )
    );
?>

<div class="card">

    <h1>
        顧客選択・メール送信
    </h1>

    <p>
        対象アンケート:
        <strong>
            <?=e(
                $currentSurvey['title']
            )?>
        </strong>
    </p>

    <form method="post">

        <input type="hidden"
               name="action"
               value="send_mail">

        <input type="hidden"
               name="survey_id"
               value="<?=e(
                   $currentSurvey['id']
               )?>">

        <div class="form-row">

            <label>
                顧客検索
            </label>

            <input
                id="customerSearch"
                type="search"
                placeholder="氏名・組織名・メールアドレス">

        </div>

        <div class="table-wrap">

            <table id="customerTable">

                <thead>
                <tr>
                    <th>選択</th>
                    <th>組織名</th>
                    <th>氏名</th>
                    <th>メール</th>
                    <th>部署</th>
                    <th>電話番号</th>
                    <th>住所</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach (
                    $customerData as $customer
                ): ?>

                <tr class="customer-row">

                    <td>
                        <input
                            type="checkbox"
                            name="customers[]"
                            value="<?=e(
                                $customer['id']
                            )?>">
                    </td>

                    <td>
                        <?=e(
                            $customer['organization']
                            ?? ''
                        )?>
                    </td>

                    <td>
                        <?=e(
                            $customer['name']
                            ?? ''
                        )?>
                    </td>

                    <td>
                        <?=e(
                            $customer['email']
                            ?? ''
                        )?>
                    </td>

                    <td>
                        <?=e(
                            $customer['department']
                            ?? ''
                        )?>
                    </td>

                    <td>
                        <?=e(
                            $customer['phone']
                            ?? ''
                        )?>
                    </td>

                    <td>
                        <?=e(
                            $customer['address']
                            ?? ''
                        )?>
                    </td>

                </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <div class="form-row">
            <label>メール件名</label>
            <input
                name="subject"
                required
                value="<?=e(
                    $currentSurvey['title']
                )?>">
        </div>

        <div class="form-row">
            <label>メール本文</label>
            <textarea
                name="body"
                required> {顧客名} 様

アンケートへのご協力をお願いいたします。

回答URL:
{アンケートURL}</textarea>
        </div>

        <button
            class="primary"
            type="submit"
            onclick="
                return confirm(
                    '選択した顧客へメールを送信しますか？'
                );
            ">
            一括送信
        </button>

    </form>

</div>


<div class="card">

    <h2>送信履歴</h2>

    <?php if (!$sendLogs): ?>

    <p class="muted">
        送信履歴はありません。
    </p>

    <?php else: ?>

    <div class="table-wrap">

        <table>

            <thead>
            <tr>
                <th>日時</th>
                <th>顧客ID</th>
                <th>結果</th>
                <th>内容</th>
            </tr>
            </thead>

            <tbody>

            <?php foreach (
                $sendLogs as $log
            ): ?>

            <tr>

                <td>
                    <?=e(
                        format_datetime(
                            (string)(
                                $log['createdAt']
                                ?? ''
                            )
                        )
                    )?>
                </td>

                <td>
                    <?=e(
                        $log['customerId']
                        ?? ''
                    )?>
                </td>

                <td>
                    <?=e(
                        $log['status']
                        ?? ''
                    )?>
                </td>

                <td>
                    <?=e(
                        $log['message']
                        ?? ''
                    )?>
                </td>

            </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <?php endif; ?>

</div>

<script>
const customerSearch =
    document.getElementById(
        'customerSearch'
    );

if (customerSearch) {

    customerSearch.addEventListener(
        'input',
        function() {

            const keyword =
                this.value
                    .toLowerCase()
                    .trim();

            document
                .querySelectorAll(
                    '.customer-row'
                )
                .forEach(
                    function(row) {

                        const text =
                            row.textContent
                                .toLowerCase();

                        row.style.display =
                            keyword === ''
                            || text.includes(keyword)
                                ? ''
                                : 'none';
                    }
                );
        }
    );
}
</script>

<?php endif; ?>


</main>

<script>
/*
 * 接続テスト等の二重送信防止。
 */
document.querySelectorAll(
    'form'
).forEach(
    function(form) {

        form.addEventListener(
            'submit',
            function() {

                const submitButtons =
                    form.querySelectorAll(
                        'button[type="submit"],'
                        + 'button:not([type])'
                    );

                submitButtons.forEach(
                    function(button) {

                        if (
                            button.dataset.confirmed
                            === '1'
                        ) {
                            return;
                        }

                        button.dataset.confirmed =
                            '1';

                        button.disabled = true;

                        if (
                            button.textContent
                        ) {
                            button.textContent =
                                '処理中...';
                        }
                    }
                );
            }
        );
    }
);
</script>

</body>
</html>
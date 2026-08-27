<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * 方針:
 * - index.php 単一エントリーポイント
 * - DB不使用
 * - PHP cURL不使用
 * - PHP mail()不使用
 * - 管理者認証なし
 * - CSRF対策なし（POC要件）
 * - kintone: ログイン名/パスワード + X-Cybozu-Authorization
 * - SMTP: 実SMTP接続
 * - データはサーバー側JSONへ保存
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR        = __DIR__ . '/data';
const SETTINGS_FILE   = DATA_DIR . '/settings.json';
const SURVEYS_FILE    = DATA_DIR . '/surveys.json';
const CUSTOMERS_FILE  = DATA_DIR . '/customers.json';
const ANSWERS_FILE    = DATA_DIR . '/answers.json';
const SEND_LOG_FILE   = DATA_DIR . '/send_logs.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT    = 20;

/* =========================================================
 * Session
 * ======================================================= */

$secure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

/* =========================================================
 * Initialization
 * ======================================================= */

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}

init_json_file(SETTINGS_FILE, [
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
        'connection_status' => '未設定',
        'last_test_at' => null,
    ],
    'mail' => [
        'host' => '',
        'port' => '',
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
        'connection_status' => '未設定',
        'last_test_at' => null,
    ],
]);

init_json_file(SURVEYS_FILE, []);
init_json_file(CUSTOMERS_FILE, []);
init_json_file(ANSWERS_FILE, []);
init_json_file(SEND_LOG_FILE, []);

/* =========================================================
 * Routing
 * ======================================================= */

$screen = (string)($_GET['screen'] ?? 'list');

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

if (!in_array($screen, $allowedScreens, true)) {
    $screen = 'list';
}

/* =========================================================
 * POST
 *
 * CSRF検証は行わない。
 *
 * 重要:
 * screen=kintone / screen=mail は
 * アンケートIDの検証対象にしない。
 * ======================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    switch ($action) {
        /* -------------------------------------------------
         * kintone
         * ------------------------------------------------- */

        case 'save_kintone':
            handle_save_kintone();
            break;

        case 'test_kintone':
            handle_test_kintone();
            break;

        case 'fetch_kintone_fields':
            handle_fetch_kintone_fields();
            break;

        case 'sync_kintone':
            handle_sync_kintone();
            break;

        /* -------------------------------------------------
         * Mail
         * ------------------------------------------------- */

        case 'save_mail':
            handle_save_mail();
            break;

        case 'test_mail':
            handle_test_mail();
            break;

        case 'send_test_mail':
            handle_send_test_mail();
            break;

        /* -------------------------------------------------
         * Survey
         * ------------------------------------------------- */

        case 'save_survey':
            handle_save_survey();
            break;

        case 'delete_survey':
            handle_delete_survey();
            break;

        case 'duplicate_survey':
            handle_duplicate_survey();
            break;

        case 'save_answer':
            handle_save_answer();
            break;

        case 'send_survey_mail':
            handle_send_survey_mail();
            break;

        default:
            flash('error', '不明な操作です。');
            redirect('index.php?screen=list');
    }
}

/* =========================================================
 * Screen-specific survey ID validation
 * ======================================================= */

$survey = null;

if (in_array($screen, ['send', 'analytics'], true)) {
    $id = (string)($_GET['id'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id)) {
        redirect('index.php?screen=list');
    }

    $survey = find_survey($id);

    if ($survey === null) {
        redirect('index.php?screen=list');
    }
}

if (in_array($screen, ['answer', 'confirm', 'complete'], true)) {
    $id = (string)($_GET['id'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id)) {
        render_error_page('アンケートIDが正しくありません。');
        exit;
    }

    $survey = find_survey($id);

    if ($survey === null) {
        render_error_page('アンケートが見つかりません。');
        exit;
    }
}

/* =========================================================
 * Render
 * ======================================================= */

render_header($screen);

switch ($screen) {
    case 'list':
        render_list();
        break;

    case 'edit':
        render_edit();
        break;

    case 'preview':
        render_preview();
        break;

    case 'send':
        render_send($survey);
        break;

    case 'analytics':
        render_analytics($survey);
        break;

    case 'kintone':
        render_kintone();
        break;

    case 'mail':
        render_mail();
        break;

    case 'answer':
        render_answer($survey);
        break;

    case 'confirm':
        render_confirm($survey);
        break;

    case 'complete':
        render_complete($survey);
        break;
}

render_footer();

/* =========================================================
 * Common
 * ======================================================= */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function redirect(string $url): never
{
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Location: ' . $url, true, 303);
    exit;
}

/*
 * CSRF関連の関数は意図的に存在しない。
 * csrf_token()
 * csrf_field()
 * verify_csrf()
 * は実装しない。
 */

function flash(string $type, string $message): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($items) ? $items : [];
}

function init_json_file(string $file, array $default): void
{
    if (!file_exists($file)) {
        atomic_write_json($file, $default);
    }
}

function read_json(string $file, mixed $default): mixed
{
    if (!file_exists($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $data
        : $default;
}

function atomic_write_json(string $file, mixed $data): void
{
    $dir = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('JSON保存データの生成に失敗しました。');
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('データ保存に失敗しました。');
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データ保存の反映に失敗しました。');
    }
}

function settings(): array
{
    $data = read_json(SETTINGS_FILE, []);

    return is_array($data) ? $data : [];
}

function save_settings(array $settings): void
{
    atomic_write_json(SETTINGS_FILE, $settings);
}

function surveys(): array
{
    $data = read_json(SURVEYS_FILE, []);

    return is_array($data) ? $data : [];
}

function save_surveys(array $items): void
{
    atomic_write_json(SURVEYS_FILE, array_values($items));
}

function customers(): array
{
    $data = read_json(CUSTOMERS_FILE, []);

    return is_array($data) ? $data : [];
}

function save_customers(array $items): void
{
    atomic_write_json(CUSTOMERS_FILE, array_values($items));
}

function answers(): array
{
    $data = read_json(ANSWERS_FILE, []);

    return is_array($data) ? $data : [];
}

function save_answers(array $items): void
{
    atomic_write_json(ANSWERS_FILE, array_values($items));
}

function send_logs(): array
{
    $data = read_json(SEND_LOG_FILE, []);

    return is_array($data) ? $data : [];
}

function save_send_logs(array $items): void
{
    atomic_write_json(SEND_LOG_FILE, array_values($items));
}

function find_survey(string $id): ?array
{
    foreach (surveys() as $item) {
        if ((string)($item['id'] ?? '') === $id) {
            return normalize_survey($item);
        }
    }

    return null;
}

function answer_count(string $surveyId): int
{
    $count = 0;

    foreach (answers() as $answer) {
        if ((string)($answer['survey_id'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

/* =========================================================
 * Survey normalization
 * ======================================================= */

function normalize_survey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? '');
    $survey['title'] = (string)($survey['title'] ?? '無題のアンケート');
    $survey['description'] = (string)($survey['description'] ?? '');
    $survey['status'] = (string)($survey['status'] ?? 'draft');
    $survey['createdAt'] = (string)($survey['createdAt'] ?? date('c'));
    $survey['updatedAt'] = (string)($survey['updatedAt'] ?? $survey['createdAt']);
    $survey['startAt'] = (string)($survey['startAt'] ?? '');
    $survey['endAt'] = (string)($survey['endAt'] ?? '');
    $survey['numbering'] = (string)($survey['numbering'] ?? 'global');

    if (!isset($survey['groups']) || !is_array($survey['groups'])) {
        $survey['groups'] = [];
    }

    foreach ($survey['groups'] as &$group) {
        if (!is_array($group)) {
            $group = [];
        }

        $group['id'] = (string)($group['id'] ?? ('group-' . bin2hex(random_bytes(4))));
        $group['title'] = (string)($group['title'] ?? 'グループ');
        $group['questions'] = is_array($group['questions'] ?? null)
            ? $group['questions']
            : [];
    }

    unset($group);

    $survey = recalculate_question_numbers($survey);

    if (
        $survey['status'] === 'published' &&
        $survey['endAt'] !== '' &&
        strtotime($survey['endAt']) !== false &&
        strtotime($survey['endAt']) < time()
    ) {
        $survey['status'] = 'ended';
    }

    return $survey;
}

function recalculate_question_numbers(array $survey): array
{
    $global = 0;

    foreach ($survey['groups'] as $gi => &$group) {
        $local = 0;

        foreach ($group['questions'] as &$question) {
            if (!is_array($question)) {
                $question = [];
            }

            $global++;
            $local++;

            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] = 'Q' . ($gi + 1) . '-' . $local;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $question['id'] = (string)(
                $question['id'] ?? ('question-' . bin2hex(random_bytes(4)))
            );

            $question['text'] = (string)($question['text'] ?? '');
            $question['type'] = in_array(
                ($question['type'] ?? ''),
                ['single', 'multiple', 'text'],
                true
            )
                ? $question['type']
                : 'single';

            $question['required'] = !empty($question['required']);

            $question['options'] = is_array($question['options'] ?? null)
                ? array_values($question['options'])
                : [];

            $question['branches'] = is_array($question['branches'] ?? null)
                ? $question['branches']
                : [];
        }

        unset($question);
    }

    unset($group);

    return $survey;
}

/* =========================================================
 * kintone validation
 * ======================================================= */

function validate_kintone_input(array $post): array
{
    $subdomain = trim((string)($post['subdomain'] ?? ''));
    $appId = trim((string)($post['app_id'] ?? ''));
    $username = trim((string)($post['username'] ?? ''));
    $password = (string)($post['password'] ?? '');
    $proxy = trim((string)($post['proxy'] ?? ''));

    $subdomain = preg_replace('#^https?://#i', '', $subdomain);
    $subdomain = preg_replace('#\.cybozu\.com/?$#i', '', $subdomain);
    $subdomain = trim((string)$subdomain, "/ \t\n\r");

    if ($subdomain === '') {
        throw new InvalidArgumentException('kintoneサブドメインを入力してください。');
    }

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,100}$/', $subdomain)) {
        throw new InvalidArgumentException('kintoneサブドメインが正しくありません。');
    }

    if ($appId === '' || !ctype_digit($appId)) {
        throw new InvalidArgumentException('顧客管理アプリIDが正しくありません。');
    }

    if ($username === '') {
        throw new InvalidArgumentException('ログイン名を入力してください。');
    }

    $old = settings()['kintone']['password'] ?? '';

    if ($password === '' && $old !== '') {
        $password = (string)$old;
    }

    if ($password === '') {
        throw new InvalidArgumentException('パスワードを入力してください。');
    }

    if ($proxy !== '' && !preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)) {
        throw new InvalidArgumentException(
            'Proxyは host:port 形式で入力してください。'
        );
    }

    $mapping = $post['field_mapping'] ?? [];

    if (!is_array($mapping)) {
        $mapping = [];
    }

    $address = $mapping['address'] ?? [];

    if (!is_array($address)) {
        $address = [];
    }

    return [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' => !empty($post['verify_ssl']),
        'field_mapping' => [
            'organization' => trim((string)($mapping['organization'] ?? '')),
            'name' => trim((string)($mapping['name'] ?? '')),
            'email' => trim((string)($mapping['email'] ?? '')),
            'department' => trim((string)($mapping['department'] ?? '')),
            'phone' => trim((string)($mapping['phone'] ?? '')),
            'address' => array_values(array_filter(
                array_map('strval', $address)
            )),
        ],
    ];
}

/* =========================================================
 * kintone HTTP
 * ======================================================= */

function kintone_request(
    string $method,
    string $path,
    ?array $body = null
): array {
    $config = settings()['kintone'] ?? [];

    $subdomain = (string)($config['subdomain'] ?? '');
    $appId = (string)($config['app_id'] ?? '');
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');

    if (
        $subdomain === '' ||
        $appId === '' ||
        $username === '' ||
        $password === ''
    ) {
        throw new RuntimeException(
            'kintone設定が未完了です。'
        );
    }

    $authorization = base64_encode(
        $username . ':' . $password
    );

    $url = 'https://' . $subdomain . '.cybozu.com' . $path;

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => READ_TIMEOUT,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => !empty($config['verify_ssl']),
            'verify_peer_name' => !empty($config['verify_ssl']),
        ],
    ];

    if ($body !== null) {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(
                'kintoneリクエスト生成に失敗しました。'
            );
        }

        $options['http']['content'] = $json;
    }

    if (!empty($config['proxy'])) {
        $options['http']['proxy'] =
            'tcp://' . $config['proxy'];
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへ接続できませんでした。'
            . 'サブドメイン、Proxy、SSL設定、ネットワークを確認してください。'
        );
    }

    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match(
            '#^HTTP/\S+\s+(\d+)#',
            $header,
            $m
        )) {
            $status = (int)$m[1];
            break;
        }
    }

    $data = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        $message = '';

        if (is_array($data)) {
            $message = (string)($data['message'] ?? '');
        }

        if ($message === '') {
            $message = 'HTTP ' . $status;
        }

        throw new RuntimeException(
            'kintone APIエラー: ' . $message
        );
    }

    return is_array($data) ? $data : [];
}

/* =========================================================
 * kintone actions
 * ======================================================= */

function handle_save_kintone(): void
{
    try {
        $input = validate_kintone_input($_POST);

        $all = settings();
        $old = $all['kintone'] ?? [];

        $all['kintone'] = array_merge(
            $old,
            $input,
            [
                'connection_status' => '未設定',
            ]
        );

        save_settings($all);

        flash(
            'success',
            'kintone設定を保存しました。'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'kintone設定の保存に失敗しました: ' .
            $e->getMessage()
        );
    }

    /*
     * 絶対に一覧へ飛ばさない。
     */
    redirect('index.php?screen=kintone');
}

function handle_test_kintone(): void
{
    try {
        $config = settings()['kintone'] ?? [];

        if (
            empty($config['subdomain']) ||
            empty($config['app_id']) ||
            empty($config['username']) ||
            empty($config['password'])
        ) {
            throw new RuntimeException(
                'kintone設定を先に保存してください。'
            );
        }

        kintone_request(
            'GET',
            '/k/v1/app.json?app=' .
            rawurlencode((string)$config['app_id'])
        );

        $all = settings();

        $all['kintone']['connection_status'] =
            '接続確認済み';

        $all['kintone']['last_test_at'] =
            date('c');

        save_settings($all);

        flash(
            'success',
            'kintoneへの接続に成功しました。'
        );
    } catch (Throwable $e) {
        $all = settings();

        $all['kintone']['connection_status'] =
            '接続できません';

        $all['kintone']['last_test_at'] =
            date('c');

        save_settings($all);

        flash(
            'error',
            'kintone接続テストに失敗しました: ' .
            $e->getMessage()
        );
    }

    redirect('index.php?screen=kintone');
}

function handle_fetch_kintone_fields(): void
{
    try {
        $config = settings()['kintone'] ?? [];

        $appId = (string)($config['app_id'] ?? '');

        if ($appId === '') {
            throw new RuntimeException(
                '顧客管理アプリIDを設定してください。'
            );
        }

        $result = kintone_request(
            'GET',
            '/k/v1/app/form/fields.json?app=' .
            rawurlencode($appId)
        );

        $properties =
            $result['properties'] ?? [];

        if (!is_array($properties)) {
            throw new RuntimeException(
                'kintoneの項目情報を取得できませんでした。'
            );
        }

        $all = settings();

        $all['kintone']['fields'] =
            $properties;

        $all['kintone']['last_fields_at'] =
            date('c');

        save_settings($all);

        flash(
            'success',
            count($properties) .
            '件のkintone項目を取得しました。'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'kintone項目取得に失敗しました: ' .
            $e->getMessage()
        );
    }

    redirect('index.php?screen=kintone');
}

function handle_sync_kintone(): void
{
    try {
        $config = settings()['kintone'] ?? [];
        $appId = (string)($config['app_id'] ?? '');

        if ($appId === '') {
            throw new RuntimeException(
                '顧客管理アプリIDを設定してください。'
            );
        }

        $mapping =
            $config['field_mapping'] ?? [];

        $fields = [];

        foreach ([
            'organization',
            'name',
            'email',
            'department',
            'phone',
        ] as $key) {
            if (!empty($mapping[$key])) {
                $fields[] =
                    (string)$mapping[$key];
            }
        }

        foreach (($mapping['address'] ?? []) as $field) {
            if ($field !== '') {
                $fields[] = (string)$field;
            }
        }

        $fields = array_values(
            array_unique($fields)
        );

        $query = '';

        $body = [
            'app' => (int)$appId,
            'query' => $query,
        ];

        if ($fields) {
            $body['fields'] = $fields;
        }

        $result = kintone_request(
            'POST',
            '/k/v1/records.json',
            $body
        );

        $records =
            $result['records'] ?? [];

        if (!is_array($records)) {
            $records = [];
        }

        $customers = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $customers[] = [
                'id' => bin2hex(random_bytes(8)),
                'organization' =>
                    kintone_value(
                        $record,
                        (string)($mapping['organization'] ?? '')
                    ),
                'name' =>
                    kintone_value(
                        $record,
                        (string)($mapping['name'] ?? '')
                    ),
                'email' =>
                    kintone_value(
                        $record,
                        (string)($mapping['email'] ?? '')
                    ),
                'department' =>
                    kintone_value(
                        $record,
                        (string)($mapping['department'] ?? '')
                    ),
                'phone' =>
                    kintone_value(
                        $record,
                        (string)($mapping['phone'] ?? '')
                    ),
                'address' =>
                    kintone_address_value(
                        $record,
                        $mapping['address'] ?? []
                    ),
                'raw' => $record,
                'updated_at' => date('c'),
            ];
        }

        save_customers($customers);

        flash(
            'success',
            count($customers) .
            '件の顧客情報を同期しました。'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            '顧客情報の同期に失敗しました: ' .
            $e->getMessage()
        );
    }

    redirect('index.php?screen=kintone');
}

function kintone_value(
    array $record,
    string $field
): string {
    if (
        $field === '' ||
        !isset($record[$field]['value'])
    ) {
        return '';
    }

    $value = $record[$field]['value'];

    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $values[] = (string)$item;
            } elseif (is_array($item)) {
                $values[] =
                    (string)($item['name'] ?? '');
            }
        }

        return implode(', ', $values);
    }

    return is_scalar($value)
        ? (string)$value
        : '';
}

function kintone_address_value(
    array $record,
    array $fields
): string {
    $values = [];

    foreach ($fields as $field) {
        $value = kintone_value(
            $record,
            (string)$field
        );

        if ($value !== '') {
            $values[] = $value;
        }
    }

    return implode(' ', $values);
}

/* =========================================================
 * Mail validation
 * ======================================================= */

function validate_mail_input(array $post): array
{
    $host = trim((string)($post['host'] ?? ''));
    $port = trim((string)($post['port'] ?? ''));
    $encryption =
        (string)($post['encryption'] ?? 'tls');

    $auth = !empty($post['auth']);

    $username =
        trim((string)($post['username'] ?? ''));

    $password =
        (string)($post['password'] ?? '');

    $fromEmail =
        trim((string)($post['from_email'] ?? ''));

    $fromName =
        trim((string)($post['from_name'] ?? ''));

    $replyTo =
        trim((string)($post['reply_to'] ?? ''));

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    if (
        $port === '' ||
        !ctype_digit($port) ||
        (int)$port < 1 ||
        (int)$port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートが正しくありません。'
        );
    }

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new InvalidArgumentException(
            '暗号化方式が正しくありません。'
        );
    }

    if (
        $fromEmail === '' ||
        !filter_var(
            $fromEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが正しくありません。'
        );
    }

    if (
        $replyTo !== '' &&
        !filter_var(
            $replyTo,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが正しくありません。'
        );
    }

    $old = settings()['mail']['password'] ?? '';

    if ($password === '' && $old !== '') {
        $password = (string)$old;
    }

    if ($auth && $username === '') {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はユーザー名を入力してください。'
        );
    }

    if ($auth && $password === '') {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はパスワードを入力してください。'
        );
    }

    return [
        'host' => $host,
        'port' => (int)$port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'reply_to' => $replyTo,
    ];
}

/* =========================================================
 * Mail actions
 * ======================================================= */

function handle_save_mail(): void
{
    try {
        $input =
            validate_mail_input($_POST);

        $all = settings();
        $old = $all['mail'] ?? [];

        $all['mail'] = array_merge(
            $old,
            $input,
            [
                'connection_status' => '未設定',
            ]
        );

        save_settings($all);

        flash(
            'success',
            'メール設定を保存しました。'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'メール設定の保存に失敗しました: ' .
            $e->getMessage()
        );
    }

    /*
     * 保存後もメール設定画面。
     */
    redirect('index.php?screen=mail');
}

function smtp_socket(): array
{
    $config =
        settings()['mail'] ?? [];

    $host =
        (string)($config['host'] ?? '');

    $port =
        (int)($config['port'] ?? 0);

    $encryption =
        (string)($config['encryption'] ?? 'tls');

    if ($host === '' || $port <= 0) {
        throw new RuntimeException(
            'SMTP設定が未完了です。'
        );
    }

    $targetHost = $host;

    if ($encryption === 'ssl') {
        $targetHost =
            'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $targetHost . ':' . $port,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません: ' .
            $errstr
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    $greeting =
        smtp_read($socket);

    if (
        !str_starts_with(
            $greeting,
            '220'
        )
    ) {
        fclose($socket);

        throw new RuntimeException(
            'SMTPサーバの応答が不正です。'
        );
    }

    smtp_command(
        $socket,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'tls') {
        smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'TLS接続を開始できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );
    }

    if (!empty($config['auth'])) {
        smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $socket,
            base64_encode(
                (string)$config['username']
            ),
            [334]
        );

        smtp_command(
            $socket,
            base64_encode(
                (string)$config['password']
            ),
            [235]
        );
    }

    return [$socket, $config];
}

function smtp_read($socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPサーバから応答がありません。'
        );
    }

    return $response;
}

function smtp_command(
    $socket,
    string $command,
    array $expected
): string {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    $response =
        smtp_read($socket);

    $code =
        (int)substr($response, 0, 3);

    if (!in_array(
        $code,
        $expected,
        true
    )) {
        throw new RuntimeException(
            'SMTPエラー: 応答コード ' .
            $code
        );
    }

    return $response;
}

function smtp_send_message(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    [$socket, $config] =
        smtp_socket();

    try {
        smtp_command(
            $socket,
            'MAIL FROM:<' .
            $config['from_email'] .
            '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<' .
            $to .
            '>',
            [250, 251]
        );

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $fromName =
            $config['from_name'] !== ''
            ? $config['from_name']
            : $config['from_email'];

        $headers = [
            'From: ' .
            mb_encode_mimeheader(
                $fromName
            ) .
            ' <' .
            $config['from_email'] .
            '>',
            'To: <' . $to . '>',
            'Subject: ' .
            mb_encode_mimeheader(
                $subject
            ),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (!empty($config['reply_to'])) {
            $headers[] =
                'Reply-To: ' .
                $config['reply_to'];
        }

        $message =
            implode(
                "\r\n",
                $headers
            ) .
            "\r\n\r\n" .
            $body;

        $message =
            str_replace(
                "\r\n",
                "\n",
                $message
            );

        $message =
            str_replace(
                "\n.",
                "\n..",
                $message
            );

        fwrite(
            $socket,
            str_replace(
                "\n",
                "\r\n",
                $message
            ) .
            "\r\n.\r\n"
        );

        $response =
            smtp_read($socket);

        if (
            !str_starts_with(
                $response,
                '250'
            )
        ) {
            throw new RuntimeException(
                'メール送信に失敗しました。'
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

function handle_test_mail(): void
{
    try {
        [$socket] =
            smtp_socket();

        smtp_command(
            $socket,
            'QUIT',
            [221]
        );

        fclose($socket);

        $all = settings();

        $all['mail']['connection_status'] =
            '接続確認済み';

        $all['mail']['last_test_at'] =
            date('c');

        save_settings($all);

        flash(
            'success',
            'SMTPサーバへの接続に成功しました。'
        );
    } catch (Throwable $e) {
        $all = settings();

        $all['mail']['connection_status'] =
            '接続できません';

        $all['mail']['last_test_at'] =
            date('c');

        save_settings($all);

        flash(
            'error',
            'SMTP接続テストに失敗しました: ' .
            $e->getMessage()
        );
    }

    redirect('index.php?screen=mail');
}

function handle_send_test_mail(): void
{
    try {
        $to =
            trim((string)(
                $_POST['test_to'] ?? ''
            ));

        if (
            !filter_var(
                $to,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new InvalidArgumentException(
                'テスト送信先メールアドレスが正しくありません。'
            );
        }

        $config =
            settings()['mail'] ?? [];

        smtp_send_message(
            $config,
            $to,
            'アンケートアプリ SMTPテストメール',
            "SMTPテストメールです。\r\n" .
            date('Y-m-d H:i:s')
        );

        flash(
            'success',
            'テストメールを送信しました。'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'テストメール送信に失敗しました: ' .
            $e->getMessage()
        );
    }

    redirect('index.php?screen=mail');
}

/* =========================================================
 * Survey actions
 * ======================================================= */

function handle_save_survey(): void
{
    try {
        $id =
            trim((string)(
                $_POST['id'] ?? ''
            ));

        if (
            $id !== '' &&
            !preg_match(
                '/^[A-Za-z0-9_-]{1,100}$/',
                $id
            )
        ) {
            throw new InvalidArgumentException(
                'アンケートIDが正しくありません。'
            );
        }

        $title =
            trim((string)(
                $_POST['title'] ?? ''
            ));

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

        $all = surveys();
        $now = date('c');

        $numbering =
            (string)(
                $_POST['numbering'] ?? 'global'
            );

        if (!in_array(
            $numbering,
            ['global', 'group'],
            true
        )) {
            $numbering = 'global';
        }

        $status =
            (string)(
                $_POST['status'] ?? 'draft'
            );

        if (!in_array(
            $status,
            ['draft', 'published', 'stopped'],
            true
        )) {
            $status = 'draft';
        }

        $existingIndex = null;
        $existing = null;

        if ($id !== '') {
            foreach ($all as $index => $item) {
                if (
                    (string)($item['id'] ?? '') ===
                    $id
                ) {
                    $existingIndex = $index;
                    $existing = $item;
                    break;
                }
            }
        }

        if ($existingIndex === null) {
            $id =
                'survey-' .
                date('YmdHis') .
                '-' .
                bin2hex(
                    random_bytes(3)
                );

            $createdAt = $now;
            $groups = [
                [
                    'id' =>
                        'group-' .
                        bin2hex(
                            random_bytes(3)
                        ),
                    'title' => '基本情報',
                    'questions' => [],
                ],
            ];

            $status = 'draft';
        } else {
            $createdAt =
                (string)(
                    $existing['createdAt'] ??
                    $now
                );

            $groups =
                is_array(
                    $existing['groups'] ?? null
                )
                ? $existing['groups']
                : [];

            /*
             * 終了状態は手動変更不可。
             */
            if (
                ($existing['status'] ?? '') ===
                'ended'
            ) {
                $status = 'ended';
            }
        }

        $survey = [
            'id' => $id,
            'title' => $title,
            'description' =>
                trim((string)(
                    $_POST['description'] ?? ''
                )),
            'startAt' =>
                trim((string)(
                    $_POST['startAt'] ?? ''
                )),
            'endAt' =>
                trim((string)(
                    $_POST['endAt'] ?? ''
                )),
            'numbering' => $numbering,
            'status' => $status,
            'createdAt' => $createdAt,
            'updatedAt' => $now,
            'groups' => $groups,
        ];

        $survey =
            normalize_survey($survey);

        if ($existingIndex === null) {
            $all[] = $survey;
        } else {
            $all[$existingIndex] = $survey;
        }

        save_surveys($all);

        flash(
            'success',
            'アンケートを保存しました。'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'アンケート保存に失敗しました: ' .
            $e->getMessage()
        );
    }

    redirect('index.php?screen=list');
}

function handle_delete_survey(): void
{
    $id =
        (string)($_POST['id'] ?? '');

    if (
        !preg_match(
            '/^[A-Za-z0-9_-]{1,100}$/',
            $id
        )
    ) {
        flash(
            'error',
            'アンケートIDが正しくありません。'
        );

        redirect(
            'index.php?screen=list'
        );
    }

    $items = surveys();

    $new = [];

    foreach ($items as $item) {
        if (
            (string)($item['id'] ?? '') !==
            $id
        ) {
            $new[] = $item;
        }
    }

    save_surveys($new);

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirect(
        'index.php?screen=list'
    );
}

function handle_duplicate_survey(): void
{
    $id =
        (string)($_POST['id'] ?? '');

    $source =
        find_survey($id);

    if ($source === null) {
        flash(
            'error',
            '複製対象のアンケートが見つかりません。'
        );

        redirect(
            'index.php?screen=list'
        );
    }

    $now = date('c');

    $copy = $source;

    $copy['id'] =
        'survey-' .
        date('YmdHis') .
        '-' .
        bin2hex(
            random_bytes(3)
        );

    $copy['title'] .= '（複製）';
    $copy['status'] = 'draft';
    $copy['createdAt'] = $now;
    $copy['updatedAt'] = $now;

    $items = surveys();
    $items[] = $copy;

    save_surveys($items);

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirect(
        'index.php?screen=list'
    );
}

function handle_save_answer(): void
{
    try {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $survey =
            find_survey($surveyId);

        if ($survey === null) {
            throw new RuntimeException(
                'アンケートが見つかりません。'
            );
        }

        $posted =
            $_POST['answers'] ?? [];

        if (!is_array($posted)) {
            $posted = [];
        }

        foreach ($survey['groups'] as $group) {
            foreach (
                ($group['questions'] ?? [])
                as $question
            ) {
                $qid =
                    (string)(
                        $question['id'] ?? ''
                    );

                if (
                    !empty($question['required']) &&
                    !array_key_exists(
                        $qid,
                        $posted
                    )
                ) {
                    throw new InvalidArgumentException(
                        '必須項目が未回答です。'
                    );
                }
            }
        }

        $item = [
            'id' =>
                'answer-' .
                date('YmdHis') .
                '-' .
                bin2hex(
                    random_bytes(4)
                ),
            'survey_id' => $surveyId,
            'submitted_at' => date('c'),
            'answers' => $posted,
        ];

        $items = answers();
        $items[] = $item;

        save_answers($items);

        $_SESSION['last_answer_id'] =
            $item['id'];

        redirect(
            'index.php?screen=complete&id=' .
            rawurlencode($surveyId)
        );
    } catch (Throwable $e) {
        flash(
            'error',
            '回答送信に失敗しました: ' .
            $e->getMessage()
        );

        redirect(
            'index.php?screen=answer&id=' .
            rawurlencode(
                (string)(
                    $_POST['survey_id'] ?? ''
                )
            )
        );
    }
}

function handle_send_survey_mail(): void
{
    try {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $survey =
            find_survey($surveyId);

        if ($survey === null) {
            throw new RuntimeException(
                'アンケートが見つかりません。'
            );
        }

        $selected =
            $_POST['customer_ids'] ?? [];

        if (!is_array($selected)) {
            $selected = [];
        }

        if (!$selected) {
            throw new InvalidArgumentException(
                '送信対象の顧客を選択してください。'
            );
        }

        $subject =
            trim((string)(
                $_POST['subject'] ?? ''
            ));

        $body =
            (string)(
                $_POST['body'] ?? ''
            );

        if ($subject === '') {
            throw new InvalidArgumentException(
                'メール件名を入力してください。'
            );
        }

        $config =
            settings()['mail'] ?? [];

        if (
            empty($config['host']) ||
            empty($config['from_email'])
        ) {
            throw new RuntimeException(
                'メール設定が未完了です。'
            );
        }

        $baseUrl =
            app_base_url();

        $url =
            $baseUrl .
            '?screen=answer&id=' .
            rawurlencode($surveyId);

        $logs = send_logs();
        $customerList = customers();

        $sent = 0;
        $failed = 0;

        foreach ($customerList as $customer) {
            $customerId =
                (string)(
                    $customer['id'] ?? ''
                );

            if (!in_array(
                $customerId,
                $selected,
                true
            )) {
                continue;
            }

            $email =
                (string)(
                    $customer['email'] ?? ''
                );

            if (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $failed++;

                $logs[] = [
                    'id' =>
                        bin2hex(
                            random_bytes(8)
                        ),
                    'survey_id' =>
                        $surveyId,
                    'customer_id' =>
                        $customerId,
                    'email' => $email,
                    'status' => 'failed',
                    'message' =>
                        'メールアドレス不正',
                    'sent_at' =>
                        date('c'),
                ];

                continue;
            }

            $name =
                (string)(
                    $customer['name'] ?? ''
                );

            $mailSubject =
                str_replace(
                    '{顧客名}',
                    $name,
                    $subject
                );

            $mailBody =
                str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}',
                    ],
                    [
                        $name,
                        $url,
                    ],
                    $body
                );

            try {
                smtp_send_message(
                    $config,
                    $email,
                    $mailSubject,
                    $mailBody
                );

                $sent++;

                $logs[] = [
                    'id' =>
                        bin2hex(
                            random_bytes(8)
                        ),
                    'survey_id' =>
                        $surveyId,
                    'customer_id' =>
                        $customerId,
                    'email' =>
                        $email,
                    'status' => 'sent',
                    'message' => '',
                    'sent_at' =>
                        date('c'),
                ];
            } catch (Throwable $e) {
                $failed++;

                $logs[] = [
                    'id' =>
                        bin2hex(
                            random_bytes(8)
                        ),
                    'survey_id' =>
                        $surveyId,
                    'customer_id' =>
                        $customerId,
                    'email' =>
                        $email,
                    'status' => 'failed',
                    'message' =>
                        $e->getMessage(),
                    'sent_at' =>
                        date('c'),
                ];
            }
        }

        save_send_logs($logs);

        flash(
            $failed > 0
                ? 'error'
                : 'success',
            '送信結果: 成功 ' .
            $sent .
            '件 / 失敗 ' .
            $failed .
            '件'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'メール送信に失敗しました: ' .
            $e->getMessage()
        );
    }

    redirect(
        'index.php?screen=send&id=' .
        rawurlencode(
            (string)(
                $_POST['survey_id'] ?? ''
            )
        )
    );
}

/* =========================================================
 * UI helpers
 * ======================================================= */

function status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function status_badge(string $status): string
{
    $class = match ($status) {
        'published' => 'badge success',
        'stopped' => 'badge warning',
        'ended' => 'badge danger',
        default => 'badge',
    };

    return '<span class="' .
        $class .
        '">' .
        h(status_label($status)) .
        '</span>';
}

function render_header(string $screen): void
{
    $adminScreens = [
        'list',
        'edit',
        'preview',
        'send',
        'analytics',
        'kintone',
        'mail',
    ];

    $isAdmin =
        in_array(
            $screen,
            $adminScreens,
            true
        );

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>アンケート管理</title>';

    echo '<style>';
    echo <<<CSS

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

body {
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

a {
    color:var(--primary);
    text-decoration:none;
}

a:hover {
    text-decoration:underline;
}

.admin-header {
    background:#0f172a;
    color:#fff;
    padding:0 24px;
}

.header-inner {
    max-width:1400px;
    min-height:64px;
    margin:auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.logo {
    color:#fff;
    font-size:20px;
    font-weight:700;
}

.nav {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.nav a {
    color:#cbd5e1;
    padding:9px 12px;
    border-radius:7px;
}

.nav a:hover,
.nav a.active {
    background:#1e293b;
    color:#fff;
    text-decoration:none;
}

.container {
    max-width:1400px;
    margin:auto;
    padding:28px 24px 60px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}

h1 {
    font-size:28px;
    margin:0 0 20px;
}

h2 {
    font-size:20px;
    margin:0 0 18px;
}

h3 {
    font-size:17px;
    margin:0 0 12px;
}

.form-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
}

.form-row {
    display:flex;
    flex-direction:column;
    gap:7px;
}

.form-row.full {
    grid-column:1 / -1;
}

label {
    font-weight:600;
    font-size:14px;
}

input,
textarea,
select {
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    font:inherit;
    background:#fff;
}

textarea {
    min-height:120px;
    resize:vertical;
}

input:focus,
textarea:focus,
select:focus {
    outline:none;
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.button,
button {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:0;
    border-radius:8px;
    padding:10px 14px;
    font:inherit;
    font-weight:600;
    cursor:pointer;
    text-decoration:none;
}

.button:hover {
    text-decoration:none;
}

.primary {
    background:var(--primary);
    color:#fff !important;
}

.primary:hover {
    background:var(--primary-dark);
}

.secondary {
    background:#e2e8f0;
    color:#334155 !important;
}

.success {
    background:var(--success);
    color:#fff !important;
}

.warning {
    background:var(--warning);
    color:#fff !important;
}

.danger {
    background:var(--danger);
    color:#fff !important;
}

.actions {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:18px;
}

.inline-form {
    display:inline;
}

.setting-actions {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
}

.setting-actions form {
    margin:0;
}

.setting-actions button {
    width:100%;
}

.flash {
    padding:13px 16px;
    border-radius:9px;
    margin-bottom:16px;
    border:1px solid;
}

.flash.success {
    background:#f0fdf4;
    color:#166534;
    border-color:#bbf7d0;
}

.flash.error {
    background:#fef2f2;
    color:#991b1b;
    border-color:#fecaca;
}

.flash.warning {
    background:#fffbeb;
    color:#92400e;
    border-color:#fde68a;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

th,
td {
    padding:12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th {
    background:#f8fafc;
    font-size:13px;
}

.badge {
    display:inline-block;
    padding:5px 9px;
    border-radius:999px;
    background:#e2e8f0;
    color:#334155;
    font-size:12px;
    font-weight:700;
}

.badge.success {
    background:#dcfce7;
    color:#166534;
}

.badge.warning {
    background:#fef3c7;
    color:#92400e;
}

.badge.danger {
    background:#fee2e2;
    color:#991b1b;
}

.small {
    color:var(--gray);
    font-size:13px;
}

.checkbox {
    display:flex;
    flex-direction:row;
    align-items:center;
    gap:8px;
}

.checkbox input {
    width:auto;
}

.question {
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
    margin-bottom:12px;
}

.question-number {
    color:var(--primary);
    font-weight:700;
    margin-bottom:8px;
}

.answer-option {
    padding:8px 0;
}

.stat-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat {
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
}

.stat-label {
    color:var(--gray);
    font-size:13px;
}

.stat-value {
    font-size:28px;
    font-weight:700;
    margin-top:5px;
}

.preview {
    max-width:800px;
    margin:auto;
}

@media(max-width:900px) {
    .form-grid,
    .stat-grid {
        grid-template-columns:1fr;
    }

    .setting-actions {
        grid-template-columns:1fr;
    }
}

@media(max-width:800px) {
    .header-inner {
        align-items:flex-start;
        flex-direction:column;
        padding:14px 0;
    }

    .container {
        padding:20px 14px 40px;
    }
}

CSS;
    echo '</style>';

    echo '</head>';
    echo '<body>';

    if ($isAdmin) {
        echo '<header class="admin-header">';
        echo '<div class="header-inner">';

        echo '<a class="logo" href="index.php?screen=list">';
        echo 'アンケート管理';
        echo '</a>';

        echo '<nav class="nav">';

        nav_link(
            'アンケート一覧',
            'index.php?screen=list',
            $screen === 'list'
        );

        nav_link(
            'kintone設定',
            'index.php?screen=kintone',
            $screen === 'kintone'
        );

        nav_link(
            'メール設定',
            'index.php?screen=mail',
            $screen === 'mail'
        );

        echo '</nav>';
        echo '</div>';
        echo '</header>';
    }

    echo '<main class="container">';

    foreach (get_flashes() as $flash) {
        echo '<div class="flash ' .
            h($flash['type'] ?? 'error') .
            '">';

        echo h(
            $flash['message'] ?? ''
        );

        echo '</div>';
    }
}

function render_footer(): void
{
    echo '</main>';

    echo '<script>';

    echo <<<JS

document.addEventListener('submit', function (event) {
    const form = event.target;

    if (!form.matches('[data-confirm]')) {
        return;
    }

    const message =
        form.getAttribute('data-confirm') ||
        '実行しますか？';

    if (!window.confirm(message)) {
        event.preventDefault();
    }
});

JS;

    echo '</script>';

    echo '</body>';
    echo '</html>';
}

function nav_link(
    string $label,
    string $url,
    bool $active
): void {
    echo '<a class="' .
        ($active ? 'active' : '') .
        '" href="' .
        h($url) .
        '">' .
        h($label) .
        '</a>';
}

function form_row(
    string $label,
    string $control,
    bool $full = false
): void {
    echo '<div class="form-row' .
        ($full ? ' full' : '') .
        '">';

    echo '<label>' .
        h($label) .
        '</label>';

    echo $control;

    echo '</div>';
}

function option(
    string $value,
    string $label,
    string $selected
): string {
    return '<option value="' .
        h($value) .
        '"' .
        ($value === $selected
            ? ' selected'
            : '') .
        '>' .
        h($label) .
        '</option>';
}

/* =========================================================
 * List
 * ======================================================= */

function render_list(): void
{
    $items = [];

    foreach (surveys() as $item) {
        $items[] =
            normalize_survey($item);
    }

    usort(
        $items,
        static function (
            array $a,
            array $b
        ): int {
            return strcmp(
                (string)($b['updatedAt'] ?? ''),
                (string)($a['updatedAt'] ?? '')
            );
        }
    );

    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px">';
    echo '<h1 style="margin:0">アンケート一覧</h1>';
    echo '<a class="button primary" href="index.php?screen=edit">新規作成</a>';
    echo '</div>';

    echo '<div class="card">';
    echo '<div class="table-wrap">';
    echo '<table>';

    echo '<thead><tr>';
    echo '<th>タイトル</th>';
    echo '<th>作成日</th>';
    echo '<th>更新日</th>';
    echo '<th>期間</th>';
    echo '<th>ステータス</th>';
    echo '<th>回答数</th>';
    echo '<th>操作</th>';
    echo '</tr></thead>';

    echo '<tbody>';

    if (!$items) {
        echo '<tr>';
        echo '<td colspan="7">アンケートはありません。</td>';
        echo '</tr>';
    }

    foreach ($items as $item) {
        $id =
            (string)($item['id'] ?? '');

        echo '<tr>';

        echo '<td>' .
            h($item['title'] ?? '') .
            '</td>';

        echo '<td>' .
            h($item['createdAt'] ?? '') .
            '</td>';

        echo '<td>' .
            h($item['updatedAt'] ?? '') .
            '</td>';

        echo '<td>' .
            h(
                ($item['startAt'] ?? '') .
                ' ～ ' .
                ($item['endAt'] ?? '')
            ) .
            '</td>';

        echo '<td>' .
            status_badge(
                (string)(
                    $item['status'] ??
                    'draft'
                )
            ) .
            '</td>';

        echo '<td>' .
            h(answer_count($id)) .
            '</td>';

        echo '<td>';
        echo '<div class="actions" style="margin:0">';

        echo '<a class="button secondary" href="index.php?screen=edit&id=' .
            rawurlencode($id) .
            '">確認・編集</a>';

        echo '<a class="button secondary" href="index.php?screen=analytics&id=' .
            rawurlencode($id) .
            '">集計</a>';

        echo '<a class="button primary" href="index.php?screen=send&id=' .
            rawurlencode($id) .
            '">送信</a>';

        echo '<form class="inline-form" method="post" data-confirm="このアンケートを複製しますか？">';
        echo '<input type="hidden" name="action" value="duplicate_survey">';
        echo '<input type="hidden" name="id" value="' .
            h($id) .
            '">';
        echo '<button class="secondary">複製</button>';
        echo '</form>';

        echo '<form class="inline-form" method="post" data-confirm="このアンケートを削除しますか？">';
        echo '<input type="hidden" name="action" value="delete_survey">';
        echo '<input type="hidden" name="id" value="' .
            h($id) .
            '">';
        echo '<button class="danger">削除</button>';
        echo '</form>';

        echo '</div>';
        echo '</td>';

        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

/* =========================================================
 * Edit
 * ======================================================= */

function render_edit(): void
{
    $id =
        (string)($_GET['id'] ?? '');

    $survey =
        $id !== ''
        ? find_survey($id)
        : null;

    $title =
        (string)($survey['title'] ?? '');

    $description =
        (string)($survey['description'] ?? '');

    $startAt =
        (string)($survey['startAt'] ?? '');

    $endAt =
        (string)($survey['endAt'] ?? '');

    $numbering =
        (string)(
            $survey['numbering'] ??
            'global'
        );

    $status =
        (string)(
            $survey['status'] ??
            'draft'
        );

    echo '<h1>アンケート作成・編集</h1>';

    echo '<div class="card">';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_survey">';
    echo '<input type="hidden" name="id" value="' .
        h($id) .
        '">';

    echo '<div class="form-grid">';

    form_row(
        'アンケートタイトル',
        '<input name="title" value="' .
        h($title) .
        '" required>',
        true
    );

    form_row(
        'アンケート説明',
        '<textarea name="description">' .
        h($description) .
        '</textarea>',
        true
    );

    form_row(
        '開始日時',
        '<input type="datetime-local" name="startAt" value="' .
        h(datetime_local_value($startAt)) .
        '">'
    );

    form_row(
        '終了日時',
        '<input type="datetime-local" name="endAt" value="' .
        h(datetime_local_value($endAt)) .
        '">'
    );

    form_row(
        '質問番号の採番方式',
        '<select name="numbering">' .
        option(
            'global',
            'アンケート全体で通番',
            $numbering
        ) .
        option(
            'group',
            'グループ毎に採番',
            $numbering
        ) .
        '</select>'
    );

    form_row(
        '状態',
        '<select name="status"' .
        ($status === 'ended'
            ? ' disabled'
            : '') .
        '>' .
        option(
            'draft',
            '下書き',
            $status
        ) .
        option(
            'published',
            '公開中',
            $status
        ) .
        option(
            'stopped',
            '停止',
            $status
        ) .
        '</select>'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<a class="button secondary" href="index.php?screen=list">キャンセル</a>';
    echo '<button class="primary">保存して一覧へ</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    /*
     * 既存アンケートの質問編集UI。
     * POCでは保存済みデータの確認・プレビューを可能にする。
     */
    if ($survey !== null) {
        echo '<div class="card">';
        echo '<h2>質問・グループ</h2>';

        if (empty($survey['groups'])) {
            echo '<p class="small">グループはまだありません。</p>';
        }

        foreach (
            $survey['groups']
            as $group
        ) {
            echo '<div class="question">';
            echo '<h3>' .
                h($group['title'] ?? '') .
                '</h3>';

            foreach (
                ($group['questions'] ?? [])
                as $question
            ) {
                echo '<div style="margin-bottom:14px">';

                echo '<div class="question-number">' .
                    h($question['number'] ?? '') .
                    '</div>';

                echo '<div>' .
                    h($question['text'] ?? '') .
                    '</div>';

                echo '<div class="small">';
                echo '形式: ' .
                    h(question_type_label(
                        (string)(
                            $question['type'] ??
                            ''
                        )
                    ));

                echo ' / ' .
                    (!empty(
                        $question['required']
                    )
                        ? '必須'
                        : '任意');

                echo '</div>';

                echo '</div>';
            }

            echo '</div>';
        }

        echo '</div>';
    }
}

function datetime_local_value(
    string $value
): string {
    if ($value === '') {
        return '';
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date(
        'Y-m-d\TH:i',
        $timestamp
    );
}

/* =========================================================
 * Preview
 * ======================================================= */

function render_preview(): void
{
    $id =
        (string)($_GET['id'] ?? '');

    $survey =
        find_survey($id);

    if ($survey === null) {
        render_error_page(
            'アンケートが見つかりません。'
        );
        return;
    }

    echo '<div class="preview">';

    echo '<div class="card">';

    echo '<h1>' .
        h($survey['title']) .
        '</h1>';

    if ($survey['description'] !== '') {
        echo '<p>' .
            nl2br(
                h($survey['description'])
            ) .
            '</p>';
    }

    foreach (
        $survey['groups']
        as $group
    ) {
        echo '<div style="margin-top:24px">';
        echo '<h2>' .
            h($group['title']) .
            '</h2>';

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {
            render_question_preview(
                $question
            );
        }

        echo '</div>';
    }

    echo '<div class="actions">';
    echo '<a class="button secondary" href="index.php?screen=edit&id=' .
        rawurlencode($id) .
        '">編集へ戻る</a>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
}

function render_question_preview(
    array $question
): void {
    echo '<div class="question">';

    echo '<div class="question-number">' .
        h($question['number'] ?? '') .
        '</div>';

    echo '<h3>' .
        h($question['text'] ?? '') .
        '</h3>';

    $type =
        (string)(
            $question['type'] ?? ''
        );

    if ($type === 'text') {
        echo '<textarea placeholder="回答"></textarea>';
    } elseif ($type === 'multiple') {
        foreach (
            ($question['options'] ?? [])
            as $option
        ) {
            echo '<div class="answer-option">';
            echo '<label class="checkbox">';
            echo '<input type="checkbox">';
            echo h((string)$option);
            echo '</label>';
            echo '</div>';
        }
    } else {
        foreach (
            ($question['options'] ?? [])
            as $option
        ) {
            echo '<div class="answer-option">';
            echo '<label class="checkbox">';
            echo '<input type="radio" name="preview_' .
                h($question['id'] ?? '') .
                '">';
            echo h((string)$option);
            echo '</label>';
            echo '</div>';
        }
    }

    echo '</div>';
}

/* =========================================================
 * Send
 * ======================================================= */

function render_send(?array $survey): void
{
    if ($survey === null) {
        render_error_page(
            'アンケートが指定されていません。'
        );
        return;
    }

    $customers =
        customers();

    $logs =
        send_logs();

    echo '<h1>顧客選択・メール送信</h1>';

    echo '<div class="card">';

    echo '<h2>対象アンケート</h2>';

    echo '<p><strong>' .
        h($survey['title']) .
        '</strong></p>';

    echo '<p class="small">対象アンケートは固定されています。</p>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>メール作成</h2>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="send_survey_mail">';
    echo '<input type="hidden" name="survey_id" value="' .
        h($survey['id']) .
        '">';

    echo '<div class="form-grid">';

    form_row(
        'メール件名',
        '<input name="subject" value="' .
        h(
            '【アンケート】' .
            $survey['title']
        ) .
        '" required>',
        true
    );

    form_row(
        'メール本文',
        '<textarea name="body" required>' .
        h(
            "{顧客名} 様\r\n\r\n" .
            "アンケートへのご回答をお願いいたします。\r\n\r\n" .
            "{アンケートURL}\r\n"
        ) .
        '</textarea>',
        true
    );

    echo '</div>';

    echo '<h3 style="margin-top:24px">顧客選択</h3>';

    if (!$customers) {
        echo '<p class="small">';
        echo '顧客データがありません。';
        echo 'kintone設定から顧客情報を同期してください。';
        echo '</p>';
    } else {
        echo '<div class="table-wrap">';
        echo '<table>';

        echo '<thead><tr>';
        echo '<th>選択</th>';
        echo '<th>組織名</th>';
        echo '<th>氏名</th>';
        echo '<th>メールアドレス</th>';
        echo '<th>部署</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach ($customers as $customer) {
            $cid =
                (string)(
                    $customer['id'] ?? ''
                );

            echo '<tr>';

            echo '<td>';
            echo '<input type="checkbox" name="customer_ids[]" value="' .
                h($cid) .
                '">';
            echo '</td>';

            echo '<td>' .
                h($customer['organization'] ?? '') .
                '</td>';

            echo '<td>' .
                h($customer['name'] ?? '') .
                '</td>';

            echo '<td>' .
                h($customer['email'] ?? '') .
                '</td>';

            echo '<td>' .
                h($customer['department'] ?? '') .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '<div class="actions">';
    echo '<button class="primary" data-confirm="選択した顧客へメールを送信します。よろしいですか？">';
    echo '一括送信';
    echo '</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>送信履歴</h2>';

    $surveyLogs = [];

    foreach ($logs as $log) {
        if (
            (string)(
                $log['survey_id'] ?? ''
            ) ===
            (string)$survey['id']
        ) {
            $surveyLogs[] = $log;
        }
    }

    if (!$surveyLogs) {
        echo '<p class="small">送信履歴はありません。</p>';
    } else {
        echo '<div class="table-wrap">';
        echo '<table>';

        echo '<thead><tr>';
        echo '<th>日時</th>';
        echo '<th>メールアドレス</th>';
        echo '<th>結果</th>';
        echo '<th>内容</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach ($surveyLogs as $log) {
            echo '<tr>';

            echo '<td>' .
                h($log['sent_at'] ?? '') .
                '</td>';

            echo '<td>' .
                h($log['email'] ?? '') .
                '</td>';

            echo '<td>' .
                h(
                    ($log['status'] ?? '') === 'sent'
                        ? '成功'
                        : '失敗'
                ) .
                '</td>';

            echo '<td>' .
                h($log['message'] ?? '') .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';
}

/* =========================================================
 * Analytics
 * ======================================================= */

function render_analytics(
    ?array $survey
): void {
    if ($survey === null) {
        render_error_page(
            'アンケートが指定されていません。'
        );
        return;
    }

    $answers =
        answers();

    $surveyAnswers = [];

    foreach ($answers as $answer) {
        if (
            (string)(
                $answer['survey_id'] ?? ''
            ) ===
            (string)$survey['id']
        ) {
            $surveyAnswers[] = $answer;
        }
    }

    $customers =
        customers();

    $customerCount =
        count($customers);

    $answerCount =
        count($surveyAnswers);

    $unanswered =
        max(
            0,
            $customerCount -
            $answerCount
        );

    $rate =
        $customerCount > 0
        ? round(
            $answerCount /
            $customerCount *
            100,
            1
        )
        : 0;

    echo '<h1>回答集計・分析</h1>';

    echo '<div class="card">';
    echo '<h2>対象アンケート</h2>';
    echo '<p><strong>' .
        h($survey['title']) .
        '</strong></p>';
    echo '</div>';

    echo '<div class="stat-grid">';

    render_stat(
        '送信対象者数',
        $customerCount
    );

    render_stat(
        '回答数',
        $answerCount
    );

    render_stat(
        '未回答数',
        $unanswered
    );

    render_stat(
        '回答率',
        $rate . '%'
    );

    echo '</div>';

    echo '<div class="card" style="margin-top:20px">';

    echo '<h2>設問別集計</h2>';

    if ($answerCount === 0) {
        echo '<p>';
        echo '現在、回答データはありません';
        echo '</p>';
    } else {
        foreach (
            $survey['groups']
            as $group
        ) {
            foreach (
                ($group['questions'] ?? [])
                as $question
            ) {
                render_question_statistics(
                    $question,
                    $surveyAnswers
                );
            }
        }
    }

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>個別回答</h2>';

    if (!$surveyAnswers) {
        echo '<p>現在、回答データはありません</p>';
    } else {
        echo '<div class="table-wrap">';
        echo '<table>';

        echo '<thead><tr>';
        echo '<th>回答日時</th>';
        echo '<th>回答ID</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach ($surveyAnswers as $answer) {
            echo '<tr>';
            echo '<td>' .
                h($answer['submitted_at'] ?? '') .
                '</td>';
            echo '<td>' .
                h($answer['id'] ?? '') .
                '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';
}

function render_stat(
    string $label,
    mixed $value
): void {
    echo '<div class="stat">';
    echo '<div class="stat-label">' .
        h($label) .
        '</div>';
    echo '<div class="stat-value">' .
        h($value) .
        '</div>';
    echo '</div>';
}

function render_question_statistics(
    array $question,
    array $answers
): void {
    echo '<div class="question">';

    echo '<h3>' .
        h($question['number'] ?? '') .
        ' ' .
        h($question['text'] ?? '') .
        '</h3>';

    $type =
        (string)(
            $question['type'] ?? ''
        );

    if ($type === 'text') {
        echo '<p class="small">';
        echo '自由記述回答: ' .
            count($answers) .
            '件';
        echo '</p>';
        echo '</div>';
        return;
    }

    $counts = [];

    foreach (
        ($question['options'] ?? [])
        as $option
    ) {
        $counts[(string)$option] = 0;
    }

    $qid =
        (string)(
            $question['id'] ?? ''
        );

    foreach ($answers as $answer) {
        $value =
            $answer['answers'][$qid] ??
            null;

        if (is_array($value)) {
            foreach ($value as $v) {
                $v = (string)$v;

                if (isset($counts[$v])) {
                    $counts[$v]++;
                }
            }
        } else {
            $value = (string)$value;

            if (isset($counts[$value])) {
                $counts[$value]++;
            }
        }
    }

    foreach ($counts as $label => $count) {
        echo '<div style="margin-bottom:10px">';
        echo '<strong>' .
            h($label) .
            '</strong>: ' .
            h($count) .
            '件';
        echo '</div>';
    }

    echo '</div>';
}

/* =========================================================
 * kintone screen
 * ======================================================= */

function render_kintone(): void
{
    $config =
        settings()['kintone'] ?? [];

    $fields =
        $config['fields'] ?? [];

    if (!is_array($fields)) {
        $fields = [];
    }

    $mapping =
        $config['field_mapping'] ?? [];

    echo '<h1>kintone連携設定</h1>';

    echo '<div class="card">';
    echo '<h2>接続設定</h2>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="form-grid">';

    form_row(
        'サブドメイン',
        '<input name="subdomain" value="' .
        h($config['subdomain'] ?? '') .
        '" placeholder="xxxx.cybozu.com">'
    );

    form_row(
        '顧客管理アプリID',
        '<input name="app_id" inputmode="numeric" value="' .
        h($config['app_id'] ?? '') .
        '">'
    );

    form_row(
        'ログイン名',
        '<input name="username" value="' .
        h($config['username'] ?? '') .
        '">'
    );

    form_row(
        'パスワード',
        '<input type="password" name="password" value="" autocomplete="new-password">' .
        '<span class="small">保存済みパスワードは表示しません。</span>'
    );

    form_row(
        'Proxy',
        '<input name="proxy" value="' .
        h($config['proxy'] ?? '') .
        '" placeholder="host:port">'
    );

    $verify =
        !empty(
            $config['verify_ssl']
        );

    form_row(
        'SSL証明書検証',
        '<label class="checkbox">' .
        '<input type="checkbox" name="verify_ssl" value="1"' .
        ($verify ? ' checked' : '') .
        '>' .
        '証明書を検証する' .
        '</label>' .
        '<span class="small">POCでは無効が初期値です。</span>'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">設定保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>kintone操作</h2>';

    echo '<p>接続状態: ';

    $status =
        (string)(
            $config['connection_status'] ??
            '未設定'
        );

    if ($status === '接続確認済み') {
        echo status_badge('published');
    } elseif (
        $status === '接続できません'
    ) {
        echo status_badge('stopped');
    } else {
        echo status_badge('draft');
    }

    echo '</p>';

    if (!empty($config['last_test_at'])) {
        echo '<p class="small">';
        echo '最終確認: ' .
            h($config['last_test_at']);
        echo '</p>';
    }

    echo '<div class="setting-actions">';

    render_action_form(
        'test_kintone',
        '接続テスト',
        'primary'
    );

    render_action_form(
        'fetch_kintone_fields',
        '項目一覧を再取得',
        'secondary'
    );

    render_action_form(
        'sync_kintone',
        '顧客情報を同期',
        'success'
    );

    echo '</div>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>項目一覧</h2>';

    if (!$fields) {
        echo '<p class="small">';
        echo 'まだ取得していません。';
        echo '「項目一覧を再取得」を実行してください。';
        echo '</p>';
    } else {
        echo '<div class="table-wrap">';
        echo '<table>';

        echo '<thead><tr>';
        echo '<th>フィールドコード</th>';
        echo '<th>ラベル</th>';
        echo '<th>タイプ</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach ($fields as $code => $field) {
            if (!is_array($field)) {
                continue;
            }

            echo '<tr>';

            echo '<td>' .
                h($code) .
                '</td>';

            echo '<td>' .
                h($field['label'] ?? '') .
                '</td>';

            echo '<td>' .
                h($field['type'] ?? '') .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>顧客項目マッピング</h2>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="form-grid">';

    form_row(
        '組織名',
        mapping_select(
            'organization',
            (string)(
                $mapping['organization'] ??
                ''
            ),
            $fields
        )
    );

    form_row(
        '氏名',
        mapping_select(
            'name',
            (string)(
                $mapping['name'] ??
                ''
            ),
            $fields
        )
    );

    form_row(
        'メールアドレス',
        mapping_select(
            'email',
            (string)(
                $mapping['email'] ??
                ''
            ),
            $fields
        )
    );

    form_row(
        '部署名',
        mapping_select(
            'department',
            (string)(
                $mapping['department'] ??
                ''
            ),
            $fields
        )
    );

    form_row(
        '電話番号',
        mapping_select(
            'phone',
            (string)(
                $mapping['phone'] ??
                ''
            ),
            $fields
        )
    );

    form_row(
        '住所',
        mapping_address_select(
            $mapping['address'] ?? [],
            $fields
        )
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">マッピングを保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}

function render_action_form(
    string $action,
    string $label,
    string $class
): void {
    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="' .
        h($action) .
        '">';

    echo '<button class="' .
        h($class) .
        '" type="submit">';

    echo h($label);

    echo '</button>';
    echo '</form>';
}

function mapping_select(
    string $name,
    string $selected,
    array $fields
): string {
    $html =
        '<select name="field_mapping[' .
        h($name) .
        ']">';

    $html .=
        '<option value="">未設定</option>';

    foreach ($fields as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $html .=
            '<option value="' .
            h($code) .
            '"' .
            (
                (string)$code ===
                $selected
                    ? ' selected'
                    : ''
            ) .
            '>' .
            h(
                (string)$code .
                ' / ' .
                (string)(
                    $field['label'] ??
                    ''
                )
            ) .
            '</option>';
    }

    $html .= '</select>';

    return $html;
}

function mapping_address_select(
    array $selected,
    array $fields
): string {
    $html = '<select multiple size="6" name="field_mapping[address][]">';

    foreach ($fields as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $isSelected =
            in_array(
                (string)$code,
                array_map(
                    'strval',
                    $selected
                ),
                true
            );

        $html .=
            '<option value="' .
            h($code) .
            '"' .
            ($isSelected
                ? ' selected'
                : '') .
            '>' .
            h(
                (string)$code .
                ' / ' .
                (string)(
                    $field['label'] ??
                    ''
                )
            ) .
            '</option>';
    }

    $html .= '</select>';

    return $html;
}

/* =========================================================
 * Mail screen
 * ======================================================= */

function render_mail(): void
{
    $config =
        settings()['mail'] ?? [];

    echo '<h1>メールサーバ設定</h1>';

    echo '<div class="card">';
    echo '<h2>SMTP設定</h2>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_mail">';

    echo '<div class="form-grid">';

    form_row(
        'SMTPサーバ',
        '<input name="host" value="' .
        h($config['host'] ?? '') .
        '" placeholder="smtp.example.com">'
    );

    form_row(
        'SMTPポート',
        '<input name="port" inputmode="numeric" value="' .
        h($config['port'] ?? '') .
        '">'
    );

    $encryption =
        (string)(
            $config['encryption'] ??
            'tls'
        );

    form_row(
        '暗号化方式',
        '<select name="encryption">' .
        option(
            'ssl',
            'SSL',
            $encryption
        ) .
        option(
            'tls',
            'TLS',
            $encryption
        ) .
        option(
            'none',
            'なし',
            $encryption
        ) .
        '</select>'
    );

    $auth =
        !empty($config['auth']);

    form_row(
        'SMTP認証',
        '<label class="checkbox">' .
        '<input type="checkbox" name="auth" value="1"' .
        ($auth ? ' checked' : '') .
        '>' .
        '認証を使用する' .
        '</label>'
    );

    form_row(
        'SMTPユーザー名',
        '<input name="username" value="' .
        h($config['username'] ?? '') .
        '">'
    );

    form_row(
        'SMTPパスワード',
        '<input type="password" name="password" value="" autocomplete="new-password">' .
        '<span class="small">保存済みパスワードは表示しません。</span>'
    );

    form_row(
        '送信元メールアドレス',
        '<input type="email" name="from_email" value="' .
        h($config['from_email'] ?? '') .
        '">'
    );

    form_row(
        '送信元名',
        '<input name="from_name" value="' .
        h($config['from_name'] ?? '') .
        '">'
    );

    form_row(
        '返信先メールアドレス',
        '<input type="email" name="reply_to" value="' .
        h($config['reply_to'] ?? '') .
        '">'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">設定保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';

    echo '<h2>接続テスト</h2>';

    $status =
        (string)(
            $config['connection_status'] ??
            '未設定'
        );

    echo '<p>接続状態: ';

    if (
        $status ===
        '接続確認済み'
    ) {
        echo status_badge(
            'published'
        );
    } elseif (
        $status ===
        '接続できません'
    ) {
        echo status_badge(
            'stopped'
        );
    } else {
        echo status_badge(
            'draft'
        );
    }

    echo '</p>';

    if (!empty($config['last_test_at'])) {
        echo '<p class="small">';
        echo '最終確認: ' .
            h($config['last_test_at']);
        echo '</p>';
    }

    echo '<div class="setting-actions">';

    render_action_form(
        'test_mail',
        '接続テスト',
        'primary'
    );

    echo '</div>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>テストメール送信</h2>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="send_test_mail">';

    form_row(
        'テスト送信先',
        '<input type="email" name="test_to" required>'
    );

    echo '<div class="actions">';
    echo '<button class="success">テストメール送信</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
}

/* =========================================================
 * Answer screens
 * ======================================================= */

function render_answer(
    ?array $survey
): void {
    if ($survey === null) {
        render_error_page(
            'アンケートが見つかりません。'
        );
        return;
    }

    if (
        $survey['status'] === 'ended' ||
        $survey['status'] === 'stopped' ||
        $survey['status'] === 'draft'
    ) {
        render_error_page(
            '現在、このアンケートには回答できません。'
        );
        return;
    }

    echo '<div class="preview">';

    echo '<div class="card">';

    echo '<h1>' .
        h($survey['title']) .
        '</h1>';

    if (
        $survey['description'] !== ''
    ) {
        echo '<p>' .
            nl2br(
                h(
                    $survey['description']
                )
            ) .
            '</p>';
    }

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_answer">';
    echo '<input type="hidden" name="survey_id" value="' .
        h($survey['id']) .
        '">';

    foreach (
        $survey['groups']
        as $group
    ) {
        echo '<div style="margin-top:24px">';

        echo '<h2>' .
            h($group['title'] ?? '') .
            '</h2>';

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {
            render_answer_question(
                $question
            );
        }

        echo '</div>';
    }

    echo '<div class="actions">';
    echo '<button class="primary">回答を送信</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
    echo '</div>';
}

function render_answer_question(
    array $question
): void {
    $qid =
        (string)(
            $question['id'] ?? ''
        );

    $type =
        (string)(
            $question['type'] ?? ''
        );

    echo '<div class="question">';

    echo '<div class="question-number">' .
        h($question['number'] ?? '') .
        '</div>';

    echo '<h3>' .
        h($question['text'] ?? '') .
        '</h3>';

    if ($type === 'text') {
        echo '<textarea name="answers[' .
            h($qid) .
            ']"' .
            (
                !empty($question['required'])
                    ? ' required'
                    : ''
            ) .
            '></textarea>';
    } elseif ($type === 'multiple') {
        foreach (
            ($question['options'] ?? [])
            as $option
        ) {
            echo '<div class="answer-option">';
            echo '<label class="checkbox">';

            echo '<input type="checkbox" name="answers[' .
                h($qid) .
                '][]" value="' .
                h($option) .
                '">';

            echo h($option);

            echo '</label>';
            echo '</div>';
        }
    } else {
        foreach (
            ($question['options'] ?? [])
            as $option
        ) {
            echo '<div class="answer-option">';
            echo '<label class="checkbox">';

            echo '<input type="radio" name="answers[' .
                h($qid) .
                ']" value="' .
                h($option) .
                '"' .
                (
                    !empty(
                        $question['required']
                    )
                        ? ' required'
                        : ''
                ) .
                '>';

            echo h($option);

            echo '</label>';
            echo '</div>';
        }
    }

    echo '</div>';
}

function render_confirm(
    ?array $survey
): void {
    if ($survey === null) {
        render_error_page(
            'アンケートが見つかりません。'
        );
        return;
    }

    echo '<div class="preview">';
    echo '<div class="card">';

    echo '<h1>回答確認</h1>';

    echo '<p>';
    echo '回答確認画面です。';
    echo '</p>';

    echo '<a class="button primary" href="index.php?screen=answer&id=' .
        rawurlencode(
            $survey['id']
        ) .
        '">回答画面へ戻る</a>';

    echo '</div>';
    echo '</div>';
}

function render_complete(
    ?array $survey
): void {
    echo '<div class="preview">';
    echo '<div class="card">';

    echo '<h1>回答完了</h1>';

    echo '<p>';
    echo 'ご回答ありがとうございました。';
    echo '</p>';

    echo '</div>';
    echo '</div>';
}

/* =========================================================
 * Error
 * ======================================================= */

function render_error_page(
    string $message
): void {
    echo '<div class="card">';
    echo '<h1>エラー</h1>';
    echo '<p>' .
        h($message) .
        '</p>';
    echo '<div class="actions">';
    echo '<a class="button secondary" href="index.php?screen=list">';
    echo 'アンケート一覧へ';
    echo '</a>';
    echo '</div>';
    echo '</div>';
}

/* =========================================================
 * Utility
 * ======================================================= */

function question_type_label(
    string $type
): string {
    return match ($type) {
        'single' => '単一選択',
        'multiple' => '複数選択',
        'text' => '自由記述',
        default => '未設定',
    };
}

function app_base_url(): string
{
    $scheme =
        (
            !empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        )
            ? 'https'
            : 'http';

    $host =
        (string)(
            $_SERVER['HTTP_HOST'] ??
            'localhost'
        );

    $script =
        (string)(
            $_SERVER['SCRIPT_NAME'] ??
            '/index.php'
        );

    $dir =
        str_replace(
            '\\',
            '/',
            dirname($script)
        );

    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }

    return
        $scheme .
        '://' .
        $host .
        $dir .
        '/index.php';
}
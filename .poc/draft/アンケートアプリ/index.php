<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * prompt.txt に基づく単一ファイル実装。
 *
 * POCのため管理者認証・CSRF対策は実装しない。
 *
 * 実装条件:
 * - index.php 単一エントリーポイント
 * - DBなし
 * - PHP cURLなし
 * - PHP mail()なし
 * - kintone: ログイン名 + パスワード
 *   X-Cybozu-Authorization
 * - SMTP: 実SMTP接続
 * - データはサーバー側JSONファイルへ保存
 * - screenクエリーで画面制御
 * - send / analytics のみ survey ID 必須
 * - kintone / mail は survey ID 検証を行わない
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR         = __DIR__ . '/data';
const SETTINGS_FILE    = DATA_DIR . '/settings.json';
const SURVEYS_FILE     = DATA_DIR . '/surveys.json';
const CUSTOMERS_FILE   = DATA_DIR . '/customers.json';
const ANSWERS_FILE     = DATA_DIR . '/answers.json';
const SEND_LOG_FILE    = DATA_DIR . '/send_logs.json';

const CONNECT_TIMEOUT  = 10;
const READ_TIMEOUT     = 20;


/* =========================================================
 * Session
 *
 * セッションは回答途中等の状態保持に使用可能。
 * CSRFトークンは生成しない。
 * 通常GETごとの session_regenerate_id() も行わない。
 * ======================================================= */

$secure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => app_cookie_path(),
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();


/* =========================================================
 * 初期ファイル
 * ======================================================= */

ensure_data_dir();

init_json_file(SETTINGS_FILE, default_settings());
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
 * survey ID 検証を絶対に行わない。
 * ======================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {

            /* -----------------------------
             * kintone
             * --------------------------- */

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


            /* -----------------------------
             * Mail
             * --------------------------- */

            case 'save_mail':
                handle_save_mail();
                break;

            case 'test_mail':
                handle_test_mail();
                break;

            case 'send_test_mail':
                handle_send_test_mail();
                break;


            /* -----------------------------
             * Survey
             * --------------------------- */

            case 'save_survey':
                handle_save_survey();
                break;

            case 'delete_survey':
                handle_delete_survey();
                break;

            case 'duplicate_survey':
                handle_duplicate_survey();
                break;

            case 'change_status':
                handle_change_status();
                break;


            /* -----------------------------
             * Answer
             * --------------------------- */

            case 'answer_confirm':
                handle_answer_confirm();
                break;

            case 'answer_complete':
                handle_answer_complete();
                break;


            /* -----------------------------
             * Send
             * --------------------------- */

            case 'send_mail':
                handle_send_mail();
                break;

            default:
                flash('error', '不明な操作です。');
                redirect_current_screen();
        }

    } catch (Throwable $e) {
        /*
         * 機密情報をエラー画面へそのまま出さない。
         * ただしPOCとして原因を可能な範囲で表示する。
         */
        flash(
            'error',
            '処理に失敗しました: ' . safe_error_message($e)
        );

        $postScreen = (string)($_POST['return_screen'] ?? $screen);

        if (!in_array($postScreen, $allowedScreens, true)) {
            $postScreen = 'list';
        }

        $postId = (string)($_POST['survey_id'] ?? '');

        if (
            in_array($postScreen, ['send', 'analytics'], true)
            && $postId !== ''
        ) {
            redirect(
                'index.php?screen=' .
                rawurlencode($postScreen) .
                '&id=' .
                rawurlencode($postId)
            );
        }

        redirect(
            'index.php?screen=' .
            rawurlencode($postScreen)
        );
    }
}


/* =========================================================
 *対象アンケート
 *
 * send / analytics のみID必須。
 * kintone / mail には適用しない。
 * ======================================================= */

$survey = null;

if (in_array($screen, ['send', 'analytics'], true)) {
    $id = (string)($_GET['id'] ?? '');

    if (!valid_survey_id($id)) {
        redirect('index.php?screen=list');
    }

    $survey = find_survey($id);

    if ($survey === null) {
        redirect('index.php?screen=list');
    }
}


/* =========================================================
 * HTML
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
        render_answer();
        break;

    case 'confirm':
        render_confirm();
        break;

    case 'complete':
        render_complete();
        break;

    default:
        render_list();
        break;
}

render_footer();


/* =========================================================
 * Common
 * ======================================================= */

function app_cookie_path(): string
{
    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '');

    if ($path === '/' || $path === '\\' || $path === '.') {
        return '/';
    }

    return rtrim(str_replace('\\', '/', $path), '/') . '/';
}

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存ディレクトリを作成できません。');
        }
    }
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
            'field_mapping' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
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
    ];
}

function init_json_file(string $file, mixed $default): void
{
    if (!file_exists($file)) {
        atomic_write_json($file, $default);
    }
}

function read_json(string $file, mixed $default = []): mixed
{
    if (!file_exists($file)) {
        return $default;
    }

    $raw = file_get_contents($file);

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
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('JSON生成に失敗しました。');
    }

    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('データ保存に失敗しました。');
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データ保存に失敗しました。');
    }
}

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
    header('Location: ' . $url, true, 303);
    exit;
}

function redirect_current_screen(): never
{
    $screen = (string)($_POST['return_screen'] ?? 'list');

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

    $id = (string)($_POST['survey_id'] ?? '');

    $url = 'index.php?screen=' . rawurlencode($screen);

    if (
        in_array($screen, ['edit', 'preview', 'send', 'analytics', 'answer', 'confirm', 'complete'], true)
        && valid_survey_id($id)
    ) {
        $url .= '&id=' . rawurlencode($id);
    }

    redirect($url);
}

function flash(string $type, string $message): void
{
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

function safe_error_message(Throwable $e): string
{
    $message = trim($e->getMessage());

    if ($message === '') {
        return '詳細不明のエラーです。';
    }

    /*
     * パスワード・Authorization等がメッセージに混入した場合に
     * そのまま表示しない。
     */
    $message = preg_replace(
        '/(password|passwd|authorization|x-cybozu-authorization)\s*[:=]\s*[^\s,]+/i',
        '$1: [hidden]',
        $message
    ) ?? $message;

    return mb_substr($message, 0, 500);
}


/* =========================================================
 * Settings
 * ======================================================= */

function settings(): array
{
    $data = read_json(SETTINGS_FILE, default_settings());

    if (!is_array($data)) {
        return default_settings();
    }

    return array_replace_recursive(default_settings(), $data);
}

function save_settings(array $data): void
{
    atomic_write_json(SETTINGS_FILE, $data);
}


/* =========================================================
 * Survey / customer / answer
 * ======================================================= */

function surveys(): array
{
    $data = read_json(SURVEYS_FILE, []);

    return is_array($data) ? $data : [];
}

function save_surveys(array $items): void
{
    atomic_write_json(
        SURVEYS_FILE,
        array_values($items)
    );
}

function customers(): array
{
    $data = read_json(CUSTOMERS_FILE, []);

    return is_array($data) ? $data : [];
}

function save_customers(array $items): void
{
    atomic_write_json(
        CUSTOMERS_FILE,
        array_values($items)
    );
}

function answers(): array
{
    $data = read_json(ANSWERS_FILE, []);

    return is_array($data) ? $data : [];
}

function save_answers(array $items): void
{
    atomic_write_json(
        ANSWERS_FILE,
        array_values($items)
    );
}

function send_logs(): array
{
    $data = read_json(SEND_LOG_FILE, []);

    return is_array($data) ? $data : [];
}

function save_send_logs(array $items): void
{
    atomic_write_json(
        SEND_LOG_FILE,
        array_values($items)
    );
}

function valid_survey_id(string $id): bool
{
    return preg_match(
        '/^[A-Za-z0-9_-]{1,100}$/',
        $id
    ) === 1;
}

function find_survey(string $id): ?array
{
    foreach (surveys() as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return normalize_survey($survey);
        }
    }

    return null;
}

function normalize_survey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? '');

    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['description'] = (string)($survey['description'] ?? '');

    $survey['status'] = (string)($survey['status'] ?? 'draft');

    $survey['startAt'] = (string)($survey['startAt'] ?? '');
    $survey['endAt'] = (string)($survey['endAt'] ?? '');

    $survey['numbering'] =
        (string)($survey['numbering'] ?? 'global');

    $survey['groups'] =
        is_array($survey['groups'] ?? null)
            ? $survey['groups']
            : [];

    if (
        $survey['status'] === 'published'
        && $survey['endAt'] !== ''
    ) {
        $end = strtotime($survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';

            $items = surveys();

            foreach ($items as &$item) {
                if (
                    (string)($item['id'] ?? '') === $survey['id']
                ) {
                    $item['status'] = 'ended';
                    $item['updatedAt'] = date('c');
                    break;
                }
            }

            unset($item);

            save_surveys($items);
        }
    }

    $survey['groups'] =
        normalize_groups(
            $survey['groups'],
            $survey['numbering']
        );

    return $survey;
}

function normalize_groups(
    array $groups,
    string $numbering = 'global'
): array {
    $global = 1;

    foreach ($groups as $gi => &$group) {

        if (!is_array($group)) {
            $group = [];
        }

        $group['id'] =
            (string)($group['id'] ?? ('group-' . uniqid()));

        $group['title'] =
            (string)($group['title'] ?? 'グループ ' . ($gi + 1));

        $questions =
            is_array($group['questions'] ?? null)
                ? $group['questions']
                : [];

        $local = 1;

        foreach ($questions as $qi => &$question) {

            if (!is_array($question)) {
                $question = [];
            }

            $question['id'] =
                (string)($question['id'] ?? ('question-' . uniqid()));

            $question['text'] =
                (string)($question['text'] ?? '');

            $type =
                (string)($question['type'] ?? 'single');

            if (!in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            )) {
                $type = 'single';
            }

            $question['type'] = $type;

            $question['required'] =
                !empty($question['required']);

            $question['options'] =
                is_array($question['options'] ?? null)
                    ? array_values($question['options'])
                    : [];

            $question['branching'] =
                is_array($question['branching'] ?? null)
                    ? $question['branching']
                    : [];

            if ($numbering === 'group') {
                $question['number'] =
                    'Q' . ($gi + 1) . '-' . $local;
            } else {
                $question['number'] =
                    'Q' . $global;
            }

            $global++;
            $local++;
        }

        unset($question);

        $group['questions'] = array_values($questions);
    }

    unset($group);

    return array_values($groups);
}

function new_survey(): array
{
    return [
        'id' => 'survey-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'groups' => [
            [
                'id' => 'group-' . bin2hex(random_bytes(4)),
                'title' => 'グループ 1',
                'questions' => [],
            ],
        ],
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
    ];
}


/* =========================================================
 * kintone validation
 * ======================================================= */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = trim($value, '/');

    $value = preg_replace(
        '/\.cybozu\.com$/i',
        '',
        $value
    ) ?? $value;

    return $value;
}

function validate_kintone_input(array $post): array
{
    $subdomain =
        normalize_kintone_subdomain(
            (string)($post['subdomain'] ?? '')
        );

    $appId =
        trim((string)($post['app_id'] ?? ''));

    $username =
        trim((string)($post['username'] ?? ''));

    $password =
        (string)($post['password'] ?? '');

    $proxy =
        trim((string)($post['proxy'] ?? ''));

    if (
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $subdomain
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが正しくありません。'
        );
    }

    if (
        !ctype_digit($appId)
        || (int)$appId <= 0
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが正しくありません。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyは host:port 形式で入力してください。'
        );
    }

    return [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' => !empty($post['verify_ssl']),
    ];
}


/* =========================================================
 * kintone HTTP
 *
 * PHP cURLは使用しない。
 * ======================================================= */

function kintone_request(
    string $method,
    string $path,
    ?array $body = null
): array {
    $settings = settings();
    $config = $settings['kintone'];

    $subdomain =
        normalize_kintone_subdomain(
            (string)($config['subdomain'] ?? '')
        );

    $appId =
        (string)($config['app_id'] ?? '');

    $username =
        (string)($config['username'] ?? '');

    $password =
        (string)($config['password'] ?? '');

    if (
        $subdomain === ''
        || $appId === ''
        || $username === ''
        || $password === ''
    ) {
        throw new RuntimeException(
            'kintone設定が未完了です。'
        );
    }

    $authorization =
        base64_encode(
            $username . ':' . $password
        );

    $url =
        'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'timeout' => READ_TIMEOUT,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' =>
                !empty($config['verify_ssl']),
            'verify_peer_name' =>
                !empty($config['verify_ssl']),
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

    $response =
        @file_get_contents(
            $url,
            false,
            $context
        );

    $status = 0;

    if (
        isset($http_response_header)
        && is_array($http_response_header)
    ) {
        foreach ($http_response_header as $header) {
            if (
                preg_match(
                    '#^HTTP/\S+\s+(\d+)#',
                    $header,
                    $m
                )
            ) {
                $status = (int)$m[1];
            }
        }
    }

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへの通信に失敗しました。'
        );
    }

    $data =
        json_decode(
            $response,
            true
        );

    if (!is_array($data)) {
        $data = [];
    }

    if ($status < 200 || $status >= 300) {
        $message =
            (string)($data['message'] ?? '');

        if ($message === '') {
            $message =
                'HTTP ' . $status;
        }

        throw new RuntimeException(
            'kintone通信エラー: ' . $message
        );
    }

    return $data;
}


/* =========================================================
 * kintone handlers
 * ======================================================= */

function handle_save_kintone(): void
{
    $config =
        validate_kintone_input($_POST);

    $settings = settings();

    $old = $settings['kintone'] ?? [];

    $config['field_mapping'] =
        $old['field_mapping'] ?? [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => [],
        ];

    $config['connection_status'] =
        $old['connection_status'] ?? '未設定';

    $config['last_test_at'] =
        $old['last_test_at'] ?? null;

    $settings['kintone'] = $config;

    save_settings($settings);

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirect('index.php?screen=kintone');
}

function handle_test_kintone(): void
{
    try {
        /*
         * 保存前入力をそのままテストする。
         * ボタン押下で「保存されている設定だけ」を
         * 参照する設計にしない。
         */
        $config =
            validate_kintone_input($_POST);

        $settings = settings();

        $settings['kintone'] =
            array_replace(
                $settings['kintone'],
                $config
            );

        save_settings($settings);

        kintone_request(
            'GET',
            '/k/v1/app.json?id=' .
            rawurlencode($config['app_id'])
        );

        $settings =
            settings();

        $settings['kintone']['connection_status'] =
            '接続確認済み';

        $settings['kintone']['last_test_at'] =
            date('c');

        save_settings($settings);

        flash(
            'success',
            'kintoneへの接続に成功しました。'
        );

    } catch (Throwable $e) {

        $settings = settings();

        $settings['kintone']['connection_status'] =
            '接続できません';

        $settings['kintone']['last_test_at'] =
            date('c');

        save_settings($settings);

        flash(
            'error',
            'kintone接続テストに失敗しました。' .
            safe_error_message($e)
        );
    }

    redirect('index.php?screen=kintone');
}

function handle_fetch_kintone_fields(): void
{
    try {
        $settings = settings();

        $appId =
            (string)$settings['kintone']['app_id'];

        if (!ctype_digit($appId)) {
            throw new RuntimeException(
                '顧客管理アプリIDが設定されていません。'
            );
        }

        $result =
            kintone_request(
                'GET',
                '/k/v1/app/form/fields.json?id=' .
                rawurlencode($appId)
            );

        $fields = [];

        foreach (
            ($result['properties'] ?? []) as $code => $field
        ) {
            if (!is_array($field)) {
                continue;
            }

            $fields[] = [
                'code' => $code,
                'label' => (string)($field['label'] ?? ''),
                'type' => (string)($field['type'] ?? ''),
            ];
        }

        $settings['kintone']['fields'] = $fields;

        save_settings($settings);

        flash(
            'success',
            count($fields) .
            '件の項目を取得しました。'
        );

    } catch (Throwable $e) {
        flash(
            'error',
            'kintone項目取得に失敗しました。' .
            safe_error_message($e)
        );
    }

    redirect('index.php?screen=kintone');
}

function handle_sync_kintone(): void
{
    try {
        $settings = settings();

        $appId =
            (string)$settings['kintone']['app_id'];

        if (
            $appId === ''
            || !ctype_digit($appId)
        ) {
            throw new RuntimeException(
                '顧客管理アプリIDが設定されていません。'
            );
        }

        $result =
            kintone_request(
                'GET',
                '/k/v1/records.json?app=' .
                rawurlencode($appId) .
                '&totalCount=true'
            );

        $records =
            is_array($result['records'] ?? null)
                ? $result['records']
                : [];

        $mapping =
            $settings['kintone']['field_mapping']
            ?? [];

        $items = [];

        foreach ($records as $record) {

            $items[] = [
                'id' =>
                    (string)(
                        $record['$id']['value']
                        ?? ''
                    ),
                'organization' =>
                    kintone_value_by_mapping(
                        $record,
                        (string)(
                            $mapping['organization']
                            ?? 'organization'
                        )
                    ),
                'name' =>
                    kintone_value_by_mapping(
                        $record,
                        (string)(
                            $mapping['name']
                            ?? 'name'
                        )
                    ),
                'email' =>
                    kintone_value_by_mapping(
                        $record,
                        (string)(
                            $mapping['email']
                            ?? 'email'
                        )
                    ),
                'department' =>
                    kintone_value_by_mapping(
                        $record,
                        (string)(
                            $mapping['department']
                            ?? 'department'
                        )
                    ),
                'phone' =>
                    kintone_value_by_mapping(
                        $record,
                        (string)(
                            $mapping['phone']
                            ?? 'phone'
                        )
                    ),
                'address' =>
                    kintone_address_value(
                        $record,
                        $mapping['address']
                            ?? []
                    ),
                'raw' => $record,
                'updated_at' => date('c'),
            ];
        }

        save_customers($items);

        flash(
            'success',
            count($items) .
            '件の顧客情報を同期しました。'
        );

    } catch (Throwable $e) {
        flash(
            'error',
            '顧客情報の同期に失敗しました。' .
            safe_error_message($e)
        );
    }

    redirect('index.php?screen=kintone');
}

function kintone_value_by_mapping(
    array $record,
    string $field
): string {
    if (
        $field === ''
        || !isset($record[$field]['value'])
    ) {
        return '';
    }

    return scalar_kintone_value(
        $record[$field]['value']
    );
}

function scalar_kintone_value(mixed $value): string
{
    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $values[] = (string)$item;
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
    mixed $fields
): string {
    if (!is_array($fields)) {
        $fields = [$fields];
    }

    $values = [];

    foreach ($fields as $field) {
        $field = (string)$field;

        if (
            $field !== ''
            && isset($record[$field]['value'])
        ) {
            $value =
                scalar_kintone_value(
                    $record[$field]['value']
                );

            if ($value !== '') {
                $values[] = $value;
            }
        }
    }

    return implode(' ', $values);
}


/* =========================================================
 * Mail validation
 * ======================================================= */

function validate_mail_input(array $post): array
{
    $host =
        trim((string)($post['host'] ?? ''));

    $port =
        trim((string)($post['port'] ?? ''));

    $encryption =
        (string)($post['encryption'] ?? 'none');

    $auth =
        !empty($post['auth']);

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
        !ctype_digit($port)
        || (int)$port < 1
        || (int)$port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートが正しくありません。'
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
            '暗号化方式が正しくありません。'
        );
    }

    if (
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
        $replyTo !== ''
        && !filter_var(
            $replyTo,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが正しくありません。'
        );
    }

    if (
        $auth
        && $username === ''
    ) {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はユーザー名が必要です。'
        );
    }

    if (
        $auth
        && $password === ''
    ) {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はパスワードが必要です。'
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
 * Mail handlers
 * ======================================================= */

function handle_save_mail(): void
{
    $config =
        validate_mail_input($_POST);

    $settings = settings();

    $old = $settings['mail'] ?? [];

    $config['connection_status'] =
        $old['connection_status'] ?? '未設定';

    $config['last_test_at'] =
        $old['last_test_at'] ?? null;

    $settings['mail'] = $config;

    save_settings($settings);

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirect('index.php?screen=mail');
}

function handle_test_mail(): void
{
    try {
        $config =
            validate_mail_input($_POST);

        smtp_open_and_quit($config);

        $settings = settings();

        $settings['mail'] =
            array_replace(
                $settings['mail'],
                $config
            );

        $settings['mail']['connection_status'] =
            '接続確認済み';

        $settings['mail']['last_test_at'] =
            date('c');

        save_settings($settings);

        flash(
            'success',
            'SMTPサーバへの接続に成功しました。'
        );

    } catch (Throwable $e) {

        $settings = settings();

        $settings['mail']['connection_status'] =
            '接続できません';

        $settings['mail']['last_test_at'] =
            date('c');

        save_settings($settings);

        flash(
            'error',
            'SMTP接続テストに失敗しました。' .
            safe_error_message($e)
        );
    }

    redirect('index.php?screen=mail');
}

function handle_send_test_mail(): void
{
    try {
        $config =
            validate_mail_input($_POST);

        $to =
            trim((string)($_POST['test_to'] ?? ''));

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

        smtp_send(
            $config,
            $to,
            'アンケートアプリ テストメール',
            "これはアンケートアプリからのテストメールです。\r\n"
        );

        flash(
            'success',
            $to . ' にテストメールを送信しました。'
        );

    } catch (Throwable $e) {
        flash(
            'error',
            'テストメール送信に失敗しました。' .
            safe_error_message($e)
        );
    }

    redirect('index.php?screen=mail');
}


/* =========================================================
 * SMTP
 *
 * 外部ライブラリなし。
 * PHP mail()なし。
 * fsockopenによるSMTP接続。
 * ======================================================= */

function smtp_socket(array $config)
{
    $host =
        (string)$config['host'];

    $port =
        (int)$config['port'];

    $encryption =
        (string)$config['encryption'];

    $target = $host;

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket =
        @fsockopen(
            $target,
            $port,
            $errno,
            $errstr,
            CONNECT_TIMEOUT
        );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。' .
            ($errstr !== '' ? ' ' . $errstr : '')
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
        $line = fgets($socket, 515);

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

    if ($response === '') {
        throw new RuntimeException(
            'SMTPサーバから応答がありません。'
        );
    }

    return $response;
}

function smtp_expect(
    $socket,
    string $command,
    array $codes
): string {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    $response =
        smtp_read($socket);

    $code =
        (int)substr(
            trim($response),
            0,
            3
        );

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTP応答エラー: HTTPではないSMTPコード ' .
            $code
        );
    }

    return $response;
}

function smtp_open_and_quit(
    array $config
): void {
    $socket =
        smtp_socket($config);

    try {
        $greeting =
            smtp_read($socket);

        $code =
            (int)substr(
                trim($greeting),
                0,
                3
            );

        if ($code !== 220) {
            throw new RuntimeException(
                'SMTP greeting error: ' . $code
            );
        }

        smtp_expect(
            $socket,
            'EHLO localhost',
            [250]
        );

        if (
            $config['encryption'] === 'tls'
        ) {
            smtp_expect(
                $socket,
                'STARTTLS',
                [220]
            );

            if (
                !stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                )
            ) {
                throw new RuntimeException(
                    'SMTP TLS開始に失敗しました。'
                );
            }

            smtp_expect(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (!empty($config['auth'])) {
            smtp_auth(
                $socket,
                $config
            );
        }

        smtp_expect(
            $socket,
            'QUIT',
            [221]
        );

    } finally {
        fclose($socket);
    }
}

function smtp_auth(
    $socket,
    array $config
): void {
    smtp_expect(
        $socket,
        'AUTH LOGIN',
        [334]
    );

    smtp_expect(
        $socket,
        base64_encode(
            (string)$config['username']
        ),
        [334]
    );

    smtp_expect(
        $socket,
        base64_encode(
            (string)$config['password']
        ),
        [235]
    );
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    $socket =
        smtp_socket($config);

    try {
        smtp_read($socket);

        smtp_expect(
            $socket,
            'EHLO localhost',
            [250]
        );

        if (
            $config['encryption'] === 'tls'
        ) {
            smtp_expect(
                $socket,
                'STARTTLS',
                [220]
            );

            if (
                !stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                )
            ) {
                throw new RuntimeException(
                    'SMTP TLS開始に失敗しました。'
                );
            }

            smtp_expect(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (!empty($config['auth'])) {
            smtp_auth(
                $socket,
                $config
            );
        }

        smtp_expect(
            $socket,
            'MAIL FROM:<' .
            $config['from_email'] .
            '>',
            [250]
        );

        smtp_expect(
            $socket,
            'RCPT TO:<' .
            $to .
            '>',
            [250, 251]
        );

        smtp_expect(
            $socket,
            'DATA',
            [354]
        );

        $headers = [];

        $headers[] =
            'From: ' .
            smtp_header(
                $config['from_name']
            ) .
            ' <' .
            $config['from_email'] .
            '>';

        $headers[] =
            'To: <' . $to . '>';

        $headers[] =
            'Subject: ' .
            smtp_header(
                $subject
            );

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        if (
            !empty($config['reply_to'])
        ) {
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
            normalize_mail_body($body);

        $message =
            preg_replace(
                '/^\./m',
                '..',
                $message
            ) ?? $message;

        fwrite(
            $socket,
            $message .
            "\r\n.\r\n"
        );

        $response =
            smtp_read($socket);

        $code =
            (int)substr(
                trim($response),
                0,
                3
            );

        if ($code !== 250) {
            throw new RuntimeException(
                'メール送信時のSMTP応答エラー: ' .
                $code
            );
        }

        smtp_expect(
            $socket,
            'QUIT',
            [221]
        );

    } finally {
        fclose($socket);
    }
}

function smtp_header(string $value): string
{
    if ($value === '') {
        return '';
    }

    return '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
}

function normalize_mail_body(string $body): string
{
    return str_replace(
        ["\r\n", "\r", "\n"],
        "\r\n",
        $body
    );
}


/* =========================================================
 * Survey handlers
 * ======================================================= */

function handle_save_survey(): void
{
    $id =
        trim((string)($_POST['survey_id'] ?? ''));

    $items = surveys();

    if ($id !== '') {
        $survey = find_survey($id);

        if ($survey === null) {
            throw new RuntimeException(
                '対象アンケートが存在しません。'
            );
        }
    } else {
        $survey = new_survey();
        $id = $survey['id'];
    }

    $title =
        trim((string)($_POST['title'] ?? ''));

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (mb_strlen($title) > 500) {
        throw new InvalidArgumentException(
            'アンケートタイトルが長すぎます。'
        );
    }

    $description =
        (string)($_POST['description'] ?? '');

    $startAt =
        trim((string)($_POST['startAt'] ?? ''));

    $endAt =
        trim((string)($_POST['endAt'] ?? ''));

    if (
        $startAt !== ''
        && strtotime($startAt) === false
    ) {
        throw new InvalidArgumentException(
            '開始日時が正しくありません。'
        );
    }

    if (
        $endAt !== ''
        && strtotime($endAt) === false
    ) {
        throw new InvalidArgumentException(
            '終了日時が正しくありません。'
        );
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) > strtotime($endAt)
    ) {
        throw new InvalidArgumentException(
            '終了日時は開始日時より後にしてください。'
        );
    }

    $status =
        $survey['status'] ?? 'draft';

    if ($status === 'ended') {
        $status = 'ended';
    } elseif ($id === ($survey['id'] ?? '')) {
        /*
         * 既存編集では現在状態を維持。
         */
        $status =
            (string)($survey['status'] ?? 'draft');
    } else {
        $status = 'draft';
    }

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

    $groups =
        parse_groups_from_post(
            $_POST['groups'] ?? []
        );

    $survey['title'] = $title;
    $survey['description'] = $description;
    $survey['startAt'] = $startAt;
    $survey['endAt'] = $endAt;
    $survey['numbering'] = $numbering;
    $survey['groups'] =
        normalize_groups(
            $groups,
            $numbering
        );
    $survey['status'] = $status;
    $survey['updatedAt'] = date('c');

    $found = false;

    foreach ($items as &$item) {
        if (
            (string)($item['id'] ?? '') === $id
        ) {
            $item = $survey;
            $found = true;
            break;
        }
    }

    unset($item);

    if (!$found) {
        $items[] = $survey;
    }

    save_surveys($items);

    flash(
        'success',
        'アンケートを保存しました。'
    );

    redirect('index.php?screen=list');
}

function parse_groups_from_post(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $groups = [];

    foreach ($value as $groupData) {

        if (!is_array($groupData)) {
            continue;
        }

        $group = [
            'id' =>
                trim(
                    (string)($groupData['id'] ?? '')
                ),
            'title' =>
                trim(
                    (string)($groupData['title'] ?? '')
                ),
            'questions' => [],
        ];

        if ($group['id'] === '') {
            $group['id'] =
                'group-' .
                bin2hex(random_bytes(4));
        }

        if ($group['title'] === '') {
            $group['title'] = 'グループ';
        }

        $questions =
            $groupData['questions'] ?? [];

        if (is_array($questions)) {

            foreach ($questions as $qData) {

                if (!is_array($qData)) {
                    continue;
                }

                $type =
                    (string)($qData['type'] ?? 'single');

                if (
                    !in_array(
                        $type,
                        ['single', 'multiple', 'text'],
                        true
                    )
                ) {
                    $type = 'single';
                }

                $options =
                    is_array(
                        $qData['options'] ?? null
                    )
                        ? array_values(
                            array_filter(
                                array_map(
                                    'strval',
                                    $qData['options']
                                ),
                                static fn($v) =>
                                    trim($v) !== ''
                            )
                        )
                        : [];

                $group['questions'][] = [
                    'id' =>
                        (string)(
                            $qData['id']
                            ??
                            ('question-' .
                            bin2hex(random_bytes(4)))
                        ),
                    'text' =>
                        trim(
                            (string)(
                                $qData['text'] ?? ''
                            )
                        ),
                    'type' => $type,
                    'required' =>
                        !empty($qData['required']),
                    'options' => $options,
                    'branching' =>
                        is_array(
                            $qData['branching'] ?? null
                        )
                            ? $qData['branching']
                            : [],
                ];
            }
        }

        $groups[] = $group;
    }

    return $groups;
}

function handle_delete_survey(): void
{
    $id =
        trim((string)($_POST['survey_id'] ?? ''));

    if (!valid_survey_id($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが正しくありません。'
        );
    }

    $items =
        array_values(
            array_filter(
                surveys(),
                static fn($item) =>
                    (string)($item['id'] ?? '') !== $id
            )
        );

    save_surveys($items);

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirect('index.php?screen=list');
}

function handle_duplicate_survey(): void
{
    $id =
        trim((string)($_POST['survey_id'] ?? ''));

    $source =
        find_survey($id);

    if ($source === null) {
        throw new RuntimeException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $copy = $source;

    $copy['id'] =
        'survey-' .
        date('YmdHis') .
        '-' .
        bin2hex(random_bytes(3));

    $copy['title'] =
        $source['title'] . '（複製）';

    $copy['status'] = 'draft';
    $copy['createdAt'] = date('c');
    $copy['updatedAt'] = date('c');

    foreach ($copy['groups'] as &$group) {

        $group['id'] =
            'group-' .
            bin2hex(random_bytes(4));

        foreach (
            ($group['questions'] ?? []) as
            &$question
        ) {
            $question['id'] =
                'question-' .
                bin2hex(random_bytes(4));
        }

        unset($question);
    }

    unset($group);

    $items = surveys();
    $items[] = $copy;

    save_surveys($items);

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirect('index.php?screen=list');
}

function handle_change_status(): void
{
    $id =
        trim((string)($_POST['survey_id'] ?? ''));

    $next =
        (string)($_POST['status'] ?? '');

    $survey =
        find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $current =
        (string)$survey['status'];

    if ($current === 'ended') {
        throw new InvalidArgumentException(
            '終了状態のアンケートは変更できません。'
        );
    }

    $allowed = [
        'draft' => ['published'],
        'published' => ['stopped'],
        'stopped' => ['published'],
    ];

    if (
        !isset($allowed[$current])
        || !in_array(
            $next,
            $allowed[$current],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '状態変更が許可されていません。'
        );
    }

    $items = surveys();

    foreach ($items as &$item) {
        if (
            (string)($item['id'] ?? '') === $id
        ) {
            $item['status'] = $next;
            $item['updatedAt'] = date('c');
            break;
        }
    }

    unset($item);

    save_surveys($items);

    flash(
        'success',
        'アンケート状態を変更しました。'
    );

    redirect('index.php?screen=list');
}


/* =========================================================
 * Answer
 * ======================================================= */

function handle_answer_confirm(): void
{
    $id =
        trim((string)($_POST['survey_id'] ?? ''));

    if (!valid_survey_id($id)) {
        throw new RuntimeException(
            'アンケートが指定されていません。'
        );
    }

    $survey =
        find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $posted =
        $_POST['answers'] ?? [];

    $answerData =
        is_array($posted)
            ? $posted
            : [];

    $errors = [];

    foreach (
        $survey['groups'] as $group
    ) {
        foreach (
            ($group['questions'] ?? []) as
            $question
        ) {
            $qid =
                (string)($question['id'] ?? '');

            if (
                !empty($question['required'])
                && empty_answer(
                    $answerData[$qid] ?? null
                )
            ) {
                $errors[] =
                    ($question['number'] ?? $qid) .
                    ' は必須項目です。';
            }
        }
    }

    if ($errors !== []) {
        flash(
            'error',
            implode(' ', $errors)
        );

        redirect(
            'index.php?screen=answer&id=' .
            rawurlencode($id)
        );
    }

    $_SESSION['answer_draft'] = [
        'survey_id' => $id,
        'answers' => $answerData,
    ];

    redirect(
        'index.php?screen=confirm&id=' .
        rawurlencode($id)
    );
}

function handle_answer_complete(): void
{
    $draft =
        $_SESSION['answer_draft'] ?? null;

    if (!is_array($draft)) {
        throw new RuntimeException(
            '回答データがありません。'
        );
    }

    $surveyId =
        (string)($draft['survey_id'] ?? '');

    if (!valid_survey_id($surveyId)) {
        throw new RuntimeException(
            '回答対象アンケートが正しくありません。'
        );
    }

    $survey =
        find_survey($surveyId);

    if ($survey === null) {
        throw new RuntimeException(
            '回答対象アンケートが存在しません。'
        );
    }

    $items = answers();

    $items[] = [
        'id' =>
            'answer-' .
            date('YmdHis') .
            '-' .
            bin2hex(random_bytes(4)),
        'survey_id' => $surveyId,
        'answers' =>
            is_array($draft['answers'] ?? null)
                ? $draft['answers']
                : [],
        'created_at' => date('c'),
    ];

    save_answers($items);

    unset($_SESSION['answer_draft']);

    redirect(
        'index.php?screen=complete&id=' .
        rawurlencode($surveyId)
    );
}

function empty_answer(mixed $value): bool
{
    if (is_array($value)) {
        return count($value) === 0;
    }

    return trim((string)$value) === '';
}


/* =========================================================
 * Send
 * ======================================================= */

function handle_send_mail(): void
{
    $surveyId =
        trim((string)($_POST['survey_id'] ?? ''));

    $survey =
        find_survey($surveyId);

    if ($survey === null) {
        throw new RuntimeException(
            '対象アンケートが存在しません。'
        );
    }

    $selected =
        $_POST['customers'] ?? [];

    if (!is_array($selected)) {
        $selected = [];
    }

    $selected =
        array_values(
            array_filter(
                array_map(
                    'strval',
                    $selected
                )
            )
        );

    if ($selected === []) {
        throw new InvalidArgumentException(
            '送信対象の顧客を選択してください。'
        );
    }

    $subject =
        trim((string)($_POST['subject'] ?? ''));

    $body =
        (string)($_POST['body'] ?? '');

    if ($subject === '') {
        throw new InvalidArgumentException(
            'メール件名を入力してください。'
        );
    }

    $settings = settings();

    $mailConfig =
        $settings['mail'];

    validate_mail_input(
        $mailConfig
    );

    $customerItems =
        customers();

    $map = [];

    foreach ($customerItems as $customer) {
        $cid =
            (string)($customer['id'] ?? '');

        if ($cid !== '') {
            $map[$cid] = $customer;
        }
    }

    $logs = send_logs();

    $success = 0;
    $failure = 0;

    foreach ($selected as $customerId) {

        if (!isset($map[$customerId])) {
            $failure++;
            continue;
        }

        $customer =
            $map[$customerId];

        $email =
            trim(
                (string)(
                    $customer['email'] ?? ''
                )
            );

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $failure++;
            continue;
        }

        $finalSubject =
            replace_mail_variables(
                $subject,
                $customer,
                $survey
            );

        $finalBody =
            replace_mail_variables(
                $body,
                $customer,
                $survey
            );

        try {

            smtp_send(
                $mailConfig,
                $email,
                $finalSubject,
                $finalBody
            );

            $success++;

            $logs[] = [
                'id' =>
                    'send-' .
                    date('YmdHis') .
                    '-' .
                    bin2hex(random_bytes(3)),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'email' => $email,
                'status' => 'success',
                'created_at' => date('c'),
            ];

        } catch (Throwable $e) {

            $failure++;

            $logs[] = [
                'id' =>
                    'send-' .
                    date('YmdHis') .
                    '-' .
                    bin2hex(random_bytes(3)),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'email' => $email,
                'status' => 'failure',
                'error' => safe_error_message($e),
                'created_at' => date('c'),
            ];
        }
    }

    save_send_logs($logs);

    flash(
        $failure === 0 ? 'success' : 'warning',
        '送信完了: 成功 ' .
        $success .
        '件 / 失敗 ' .
        $failure .
        '件'
    );

    redirect(
        'index.php?screen=send&id=' .
        rawurlencode($surveyId)
    );
}

function replace_mail_variables(
    string $text,
    array $customer,
    array $survey
): string {
    $surveyUrl =
        build_answer_url(
            (string)$survey['id']
        );

    return strtr(
        $text,
        [
            '{顧客名}' =>
                (string)(
                    $customer['name'] ?? ''
                ),
            '{アンケートURL}' =>
                $surveyUrl,
        ]
    );
}

function build_answer_url(string $id): string
{
    $scheme =
        (!empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

    $host =
        (string)(
            $_SERVER['HTTP_HOST']
            ?? 'localhost'
        );

    $path =
        rtrim(
            dirname(
                $_SERVER['SCRIPT_NAME']
                ?? '/index.php'
            ),
            '/\\'
        );

    return $scheme .
        '://' .
        $host .
        $path .
        '/index.php?screen=answer&id=' .
        rawurlencode($id);
}


/* =========================================================
 * HTML Header
 * ======================================================= */

function render_header(string $screen): void
{
    $titles = [
        'list' => 'アンケート一覧',
        'edit' => 'アンケート作成・編集',
        'preview' => 'プレビュー',
        'send' => '顧客選択・メール送信',
        'analytics' => '回答集計・分析',
        'kintone' => 'kintone連携設定',
        'mail' => 'メールサーバ設定',
        'answer' => 'アンケート回答',
        'confirm' => '回答確認',
        'complete' => '回答完了',
    ];

    $title =
        $titles[$screen]
        ?? 'アンケートアプリ';

    $admin =
        !in_array(
            $screen,
            ['answer', 'confirm', 'complete'],
            true
        );

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - アンケートアプリ</title>

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
    line-height:1.6;
}

a {
    color:var(--primary);
    text-decoration:none;
}

a:hover {
    text-decoration:underline;
}

button,
input,
textarea,
select {
    font:inherit;
}

button {
    cursor:pointer;
}

.admin-header {
    background:#0f172a;
    color:#fff;
    padding:16px 24px;
}

.header-inner {
    max-width:1400px;
    margin:0 auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.logo {
    color:#fff;
    font-weight:700;
    font-size:20px;
}

.admin-nav {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.admin-nav a {
    color:#cbd5e1;
    padding:7px 11px;
    border-radius:7px;
}

.admin-nav a:hover,
.admin-nav a.active {
    background:#1e293b;
    color:#fff;
    text-decoration:none;
}

.container {
    max-width:1400px;
    margin:0 auto;
    padding:28px 24px 60px;
}

.page-title {
    margin:0 0 22px;
    font-size:28px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}

.toolbar {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:20px;
    flex-wrap:wrap;
}

.buttons {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 15px;
    border-radius:8px;
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    text-decoration:none;
    font-weight:600;
}

.btn:hover {
    text-decoration:none;
    background:#f8fafc;
}

.btn-primary {
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}

.btn-primary:hover {
    background:var(--primary-dark);
}

.btn-success {
    background:var(--success);
    color:#fff;
    border-color:var(--success);
}

.btn-danger {
    background:var(--danger);
    color:#fff;
    border-color:var(--danger);
}

.btn-warning {
    background:var(--warning);
    color:#fff;
    border-color:var(--warning);
}

.btn-small {
    min-height:34px;
    padding:5px 10px;
    font-size:13px;
}

.form-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-group {
    margin-bottom:17px;
}

.form-group.full {
    grid-column:1/-1;
}

label {
    display:block;
    font-weight:700;
    margin-bottom:7px;
}

input[type="text"],
input[type="email"],
input[type="password"],
input[type="number"],
input[type="datetime-local"],
select,
textarea {
    width:100%;
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

textarea {
    min-height:120px;
    resize:vertical;
}

.help {
    margin-top:5px;
    color:var(--gray);
    font-size:13px;
}

.alert {
    border-radius:9px;
    padding:12px 15px;
    margin-bottom:15px;
}

.alert-success {
    background:#dcfce7;
    color:#166534;
}

.alert-error {
    background:#fee2e2;
    color:#991b1b;
}

.alert-warning {
    background:#fef3c7;
    color:#92400e;
}

.table-wrap {
    width:100%;
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
    min-width:1000px;
}

th,
td {
    border-bottom:1px solid var(--border);
    padding:11px 10px;
    text-align:left;
    vertical-align:middle;
}

th {
    background:#f8fafc;
    white-space:nowrap;
}

.badge {
    display:inline-block;
    border-radius:999px;
    padding:3px 9px;
    font-size:12px;
    font-weight:700;
}

.badge-draft {
    background:#e2e8f0;
    color:#475569;
}

.badge-published {
    background:#dcfce7;
    color:#166534;
}

.badge-stopped {
    background:#fef3c7;
    color:#92400e;
}

.badge-ended {
    background:#fee2e2;
    color:#991b1b;
}

.actions {
    display:flex;
    flex-wrap:wrap;
    gap:5px;
}

.question-card,
.group-card {
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
    padding:18px;
    margin-bottom:14px;
}

.group-header {
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:15px;
}

.question-number {
    color:var(--gray);
    font-size:13px;
    font-weight:700;
}

.option-list {
    display:grid;
    gap:7px;
    margin-top:8px;
}

.stats {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat {
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:17px;
}

.stat-label {
    color:var(--gray);
    font-size:13px;
}

.stat-value {
    font-size:26px;
    font-weight:800;
    margin-top:4px;
}

.empty {
    padding:40px;
    text-align:center;
    color:var(--gray);
}

.footer {
    color:var(--gray);
    text-align:center;
    padding:25px;
    font-size:13px;
}

@media(max-width:800px) {
    .container {
        padding:20px 14px 40px;
    }

    .header-inner {
        align-items:flex-start;
        flex-direction:column;
    }

    .form-grid {
        grid-template-columns:1fr;
    }

    .stats {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .page-title {
        font-size:23px;
    }
}

@media(max-width:480px) {
    .stats {
        grid-template-columns:1fr;
    }

    .btn {
        width:100%;
    }

    .buttons {
        width:100%;
    }
}
</style>
</head>

<body>

<?php if ($admin): ?>

<header class="admin-header">
<div class="header-inner">

<a class="logo"
   href="index.php?screen=list">
アンケートアプリ
</a>

<nav class="admin-nav">
<a href="index.php?screen=list"
   class="<?= $screen === 'list' ? 'active' : '' ?>">
アンケート一覧
</a>

<a href="index.php?screen=kintone"
   class="<?= $screen === 'kintone' ? 'active' : '' ?>">
kintone設定
</a>

<a href="index.php?screen=mail"
   class="<?= $screen === 'mail' ? 'active' : '' ?>">
メール設定
</a>
</nav>

</div>
</header>

<?php endif; ?>

<main class="container">

<?php foreach (get_flashes() as $flash): ?>

<div class="alert alert-<?= h($flash['type'] ?? 'error') ?>">
<?= nl2br(h($flash['message'] ?? '')) ?>
</div>

<?php endforeach; ?>

<h1 class="page-title"><?= h($title) ?></h1>

<?php
}


/* =========================================================
 * List
 * ======================================================= */

function render_list(): void
{
    $items = surveys();

    foreach ($items as &$item) {
        $item = normalize_survey($item);
    }

    unset($item);

    $keyword =
        trim((string)($_GET['q'] ?? ''));

    $statusFilter =
        (string)($_GET['status'] ?? 'all');

    $sort =
        (string)($_GET['sort'] ?? 'updated_desc');

    if ($keyword !== '') {
        $items =
            array_filter(
                $items,
                static fn($survey) =>
                    mb_stripos(
                        (string)($survey['title'] ?? ''),
                        $keyword
                    ) !== false
            );
    }

    $statusMap = [
        'published' => 'published',
        'draft' => 'draft',
        'stopped' => 'stopped',
        'ended' => 'ended',
    ];

    if (
        $statusFilter !== 'all'
        && isset($statusMap[$statusFilter])
    ) {
        $items =
            array_filter(
                $items,
                static fn($survey) =>
                    ($survey['status'] ?? '') ===
                    $statusMap[$statusFilter]
            );
    }

    usort(
        $items,
        static function($a, $b) use ($sort): int {

            if ($sort === 'answers_desc') {
                return
                    answer_count($b['id'])
                    <=>
                    answer_count($a['id']);
            }

            if ($sort === 'answers_asc') {
                return
                    answer_count($a['id'])
                    <=>
                    answer_count($b['id']);
            }

            $field = 'updatedAt';

            if (
                str_starts_with(
                    $sort,
                    'start'
                )
            ) {
                $field = 'startAt';
            }

            $av =
                strtotime(
                    (string)($a[$field] ?? '')
                ) ?: 0;

            $bv =
                strtotime(
                    (string)($b[$field] ?? '')
                ) ?: 0;

            return
                str_contains(
                    $sort,
                    '_asc'
                )
                    ? $av <=> $bv
                    : $bv <=> $av;
        }
    );

    ?>

<div class="toolbar">
<div class="buttons">
<a class="btn btn-primary"
   href="index.php?screen=edit">
＋ 新規作成
</a>
</div>
</div>

<div class="card">

<form method="get">

<input type="hidden"
       name="screen"
       value="list">

<div class="form-grid">

<div class="form-group">
<label>タイトル検索</label>
<input type="text"
       name="q"
       value="<?= h($keyword) ?>"
       placeholder="タイトルを入力してEnter">
</div>

<div class="form-group">
<label>ステータス</label>
<select name="status">
<option value="all">すべて</option>
<option value="published"
    <?= $statusFilter === 'published' ? 'selected' : '' ?>>
公開中
</option>
<option value="draft"
    <?= $statusFilter === 'draft' ? 'selected' : '' ?>>
下書き
</option>
<option value="stopped"
    <?= $statusFilter === 'stopped' ? 'selected' : '' ?>>
停止
</option>
<option value="ended"
    <?= $statusFilter === 'ended' ? 'selected' : '' ?>>
終了
</option>
</select>
</div>

<div class="form-group">
<label>ソート</label>
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
<option value="start_desc"
    <?= $sort === 'start_desc' ? 'selected' : '' ?>>
開始日：新しい順
</option>
<option value="start_asc"
    <?= $sort === 'start_asc' ? 'selected' : '' ?>>
開始日：古い順
</option>
</select>
</div>

<div class="form-group"
     style="display:flex;align-items:end;">
<button class="btn btn-primary"
        type="submit">
検索
</button>
</div>

</div>
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
<th>ステータス</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>

<tbody>

<?php if ($items === []): ?>

<tr>
<td colspan="7">
<div class="empty">
アンケートがありません。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($items as $survey): ?>

<tr>

<td>
<strong><?= h($survey['title']) ?></strong>
</td>

<td>
<?= h(format_datetime($survey['createdAt'] ?? '')) ?>
</td>

<td>
<?= h(format_datetime($survey['updatedAt'] ?? '')) ?>
</td>

<td>
<?= h(format_datetime($survey['startAt'] ?? '')) ?>
～
<?= h(format_datetime($survey['endAt'] ?? '')) ?>
</td>

<td>
<?= status_badge($survey['status']) ?>
</td>

<td>
<?= h(answer_count($survey['id'])) ?>
</td>

<td>

<div class="actions">

<a class="btn btn-small"
   href="index.php?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
編集
</a>

<a class="btn btn-small"
   href="index.php?screen=preview&id=<?= rawurlencode($survey['id']) ?>">
プレビュー
</a>

<a class="btn btn-small"
   href="index.php?screen=analytics&id=<?= rawurlencode($survey['id']) ?>">
集計
</a>

<a class="btn btn-small"
   href="index.php?screen=send&id=<?= rawurlencode($survey['id']) ?>">
送信
</a>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('このアンケートを複製しますか？');">

<input type="hidden"
       name="action"
       value="duplicate_survey">

<input type="hidden"
       name="return_screen"
       value="list">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-small"
        type="submit">
複製
</button>

</form>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('このアンケートを削除しますか？');">

<input type="hidden"
       name="action"
       value="delete_survey">

<input type="hidden"
       name="return_screen"
       value="list">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-danger btn-small"
        type="submit">
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

<?php
}


/* =========================================================
 * Edit
 * ======================================================= */

function render_edit(): void
{
    $id =
        trim((string)($_GET['id'] ?? ''));

    if ($id === '') {
        $survey = new_survey();
        $isNew = true;
    } else {
        $survey = find_survey($id);

        if ($survey === null) {
            redirect('index.php?screen=list');
        }

        $isNew = false;
    }

    ?>

<form method="post">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="return_screen"
       value="edit">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="toolbar">

<div class="buttons">
<a class="btn"
   href="index.php?screen=list">
キャンセル
</a>

<button class="btn btn-primary"
        type="submit">
保存して一覧へ
</button>
</div>

<div>
状態：
<strong>
<?= h(status_label($survey['status'])) ?>
</strong>
</div>

</div>

<div class="card">

<div class="form-grid">

<div class="form-group full">
<label>アンケートタイトル</label>
<input type="text"
       name="title"
       required
       maxlength="500"
       value="<?= h($survey['title']) ?>">
</div>

<div class="form-group full">
<label>アンケート説明</label>
<textarea name="description"><?= h($survey['description']) ?></textarea>
</div>

<div class="form-group">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?= h(to_datetime_local($survey['startAt'])) ?>">
</div>

<div class="form-group">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?= h(to_datetime_local($survey['endAt'])) ?>">
</div>

<div class="form-group">
<label>質問番号の採番方式</label>

<select name="numbering">
<option value="global"
    <?= ($survey['numbering'] ?? 'global') === 'global' ? 'selected' : '' ?>>
アンケート全体で通番：Q1、Q2、Q3...
</option>

<option value="group"
    <?= ($survey['numbering'] ?? '') === 'group' ? 'selected' : '' ?>>
グループ毎：Q1-1、Q1-2、Q2-1...
</option>
</select>

</div>

</div>

</div>

<div id="groups">

<?php foreach ($survey['groups'] as $gi => $group): ?>

<div class="group-card">

<div class="group-header">

<strong>
グループ <?= h($gi + 1) ?>
</strong>

<input type="hidden"
       name="groups[<?= $gi ?>][id]"
       value="<?= h($group['id']) ?>">

</div>

<div class="form-group">
<label>グループタイトル</label>

<input type="text"
       name="groups[<?= $gi ?>][title]"
       value="<?= h($group['title']) ?>">
</div>

<?php foreach (
    $group['questions']
    as $qi => $question
): ?>

<div class="question-card">

<div class="question-number">
<?= h($question['number'] ?? '') ?>
</div>

<input type="hidden"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][id]"
       value="<?= h($question['id']) ?>">

<div class="form-group">
<label>質問文</label>

<input type="text"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][text]"
       value="<?= h($question['text']) ?>">
</div>

<div class="form-grid">

<div class="form-group">

<label>回答形式</label>

<select
 name="groups[<?= $gi ?>][questions][<?= $qi ?>][type]">

<option value="single"
 <?= $question['type'] === 'single' ? 'selected' : '' ?>>
単一選択
</option>

<option value="multiple"
 <?= $question['type'] === 'multiple' ? 'selected' : '' ?>>
複数選択
</option>

<option value="text"
 <?= $question['type'] === 'text' ? 'selected' : '' ?>>
自由記述
</option>

</select>

</div>

<div class="form-group">

<label>
<input type="checkbox"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][required]"
       value="1"
       <?= !empty($question['required']) ? 'checked' : '' ?>>
必須
</label>

</div>

</div>

<?php if (
    in_array(
        $question['type'],
        ['single', 'multiple'],
        true
    )
): ?>

<div class="form-group">

<label>選択肢</label>

<?php foreach (
    $question['options']
    as $oi => $option
): ?>

<input type="text"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][]"
       value="<?= h($option) ?>"
       style="margin-bottom:6px">

<?php endforeach; ?>

<input type="text"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][]"
       value=""
       placeholder="選択肢を追加">

</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

<p class="help">
質問はグループ末尾に追加する構成です。
</p>

</div>

<?php endforeach; ?>

</div>

</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /*
     * POC用の簡易UI。
     * サーバー側では常に質問番号を再計算する。
     */
});
</script>

<?php
}


/* =========================================================
 * Preview
 * ======================================================= */

function render_preview(): void
{
    $id =
        trim((string)($_GET['id'] ?? ''));

    $survey =
        find_survey($id);

    if ($survey === null) {
        redirect('index.php?screen=list');
    }

    ?>

<div class="toolbar">
<div class="buttons">

<a class="btn"
   href="index.php?screen=edit&id=<?= rawurlencode($id) ?>">
編集へ戻る
</a>

</div>
</div>

<div class="card">

<h2><?= h($survey['title']) ?></h2>

<p>
<?= nl2br(h($survey['description'])) ?>
</p>

</div>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">

<h3><?= h($group['title']) ?></h3>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<div class="question-number">
<?= h($question['number']) ?>
</div>

<h4>
<?= h($question['text']) ?>

<?php if (!empty($question['required'])): ?>
<span class="badge badge-ended">必須</span>
<?php endif; ?>

</h4>

<?php if ($question['type'] === 'single'): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label>
<input type="radio"
       disabled>
<?= h($option) ?>
</label>

<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label>
<input type="checkbox"
       disabled>
<?= h($option) ?>
</label>

<?php endforeach; ?>

<?php else: ?>

<textarea disabled
          placeholder="自由記述"></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<?php
}


/* =========================================================
 * kintone screen
 *
 * ここには survey ID チェックを絶対に入れない。
 * ======================================================= */

function render_kintone(): void
{
    $settings = settings();
    $config = $settings['kintone'];

    ?>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone">

<input type="hidden"
       name="return_screen"
       value="kintone">

<div class="form-grid">

<div class="form-group">
<label>サブドメイン</label>

<input type="text"
       name="subdomain"
       value="<?= h($config['subdomain'] ?? '') ?>"
       placeholder="xxxx.cybozu.com">

<div class="help">
https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx
のいずれでも入力できます。
</div>
</div>

<div class="form-group">
<label>顧客管理アプリID</label>

<input type="number"
       name="app_id"
       min="1"
       value="<?= h($config['app_id'] ?? '') ?>">
</div>

<div class="form-group">
<label>ログイン名</label>

<input type="text"
       name="username"
       autocomplete="username"
       value="<?= h($config['username'] ?? '') ?>">
</div>

<div class="form-group">
<label>パスワード</label>

<input type="password"
       name="password"
       autocomplete="current-password"
       value="<?= h($config['password'] ?? '') ?>">
</div>

<div class="form-group">
<label>Proxy</label>

<input type="text"
       name="proxy"
       value="<?= h($config['proxy'] ?? '') ?>"
       placeholder="host:port">

<div class="help">
未入力の場合は直接接続します。
</div>
</div>

<div class="form-group">

<label>
<input type="checkbox"
       name="verify_ssl"
       value="1"
       <?= !empty($config['verify_ssl']) ? 'checked' : '' ?>>
SSL証明書を検証する
</label>

<div class="help">
POCでは無効を初期値とします。
</div>

</div>

</div>

<div class="buttons">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

</div>


<div class="card">

<h3>接続テスト</h3>

<p>
現在の入力値で実際のkintoneへ接続します。
</p>

<form method="post">

<input type="hidden"
       name="action"
       value="test_kintone">

<input type="hidden"
       name="return_screen"
       value="kintone">

<input type="hidden"
       name="subdomain"
       value="<?= h($config['subdomain'] ?? '') ?>">

<input type="hidden"
       name="app_id"
       value="<?= h($config['app_id'] ?? '') ?>">

<input type="hidden"
       name="username"
       value="<?= h($config['username'] ?? '') ?>">

<input type="hidden"
       name="password"
       value="<?= h($config['password'] ?? '') ?>">

<input type="hidden"
       name="proxy"
       value="<?= h($config['proxy'] ?? '') ?>">

<?php if (!empty($config['verify_ssl'])): ?>
<input type="hidden"
       name="verify_ssl"
       value="1">
<?php endif; ?>

<button class="btn"
        type="submit">
接続テスト
</button>

</form>

<p>
接続状態：
<strong>
<?= h($config['connection_status'] ?? '未設定') ?>
</strong>
</p>

</div>


<div class="card">

<h3>kintone項目・顧客情報</h3>

<div class="buttons">

<form method="post">

<input type="hidden"
       name="action"
       value="fetch_kintone_fields">

<input type="hidden"
       name="return_screen"
       value="kintone">

<button class="btn"
        type="submit">
項目一覧を再取得
</button>

</form>

<form method="post">

<input type="hidden"
       name="action"
       value="sync_kintone">

<input type="hidden"
       name="return_screen"
       value="kintone">

<button class="btn btn-primary"
        type="submit">
顧客情報を同期
</button>

</form>

</div>

</div>

<?php
}


/* =========================================================
 * mail screen
 *
 * survey ID チェックを行わない。
 * ======================================================= */

function render_mail(): void
{
    $settings = settings();
    $config = $settings['mail'];

    ?>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="save_mail">

<input type="hidden"
       name="return_screen"
       value="mail">

<div class="form-grid">

<div class="form-group">
<label>SMTPサーバ</label>
<input type="text"
       name="host"
       value="<?= h($config['host'] ?? '') ?>">
</div>

<div class="form-group">
<label>SMTPポート</label>
<input type="number"
       name="port"
       min="1"
       max="65535"
       value="<?= h($config['port'] ?? '') ?>">
</div>

<div class="form-group">
<label>暗号化方式</label>

<select name="encryption">
<option value="ssl"
 <?= ($config['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>
SSL
</option>

<option value="tls"
 <?= ($config['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>
TLS
</option>

<option value="none"
 <?= ($config['encryption'] ?? '') === 'none' ? 'selected' : '' ?>>
なし
</option>
</select>
</div>

<div class="form-group">

<label>
<input type="checkbox"
       name="auth"
       value="1"
       <?= !empty($config['auth']) ? 'checked' : '' ?>>
SMTP認証を使用
</label>

</div>

<div class="form-group">
<label>SMTPユーザー名</label>
<input type="text"
       name="username"
       autocomplete="username"
       value="<?= h($config['username'] ?? '') ?>">
</div>

<div class="form-group">
<label>SMTPパスワード</label>
<input type="password"
       name="password"
       autocomplete="current-password"
       value="<?= h($config['password'] ?? '') ?>">
</div>

<div class="form-group">
<label>送信元メールアドレス</label>
<input type="email"
       name="from_email"
       value="<?= h($config['from_email'] ?? '') ?>">
</div>

<div class="form-group">
<label>送信元名</label>
<input type="text"
       name="from_name"
       value="<?= h($config['from_name'] ?? '') ?>">
</div>

<div class="form-group">
<label>返信先メールアドレス</label>
<input type="email"
       name="reply_to"
       value="<?= h($config['reply_to'] ?? '') ?>">
</div>

</div>

<div class="buttons">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

</div>


<div class="card">

<h3>SMTP接続テスト</h3>

<form method="post">

<input type="hidden"
       name="action"
       value="test_mail">

<input type="hidden"
       name="return_screen"
       value="mail">

<input type="hidden"
       name="host"
       value="<?= h($config['host'] ?? '') ?>">

<input type="hidden"
       name="port"
       value="<?= h($config['port'] ?? '') ?>">

<input type="hidden"
       name="encryption"
       value="<?= h($config['encryption'] ?? 'tls') ?>">

<?php if (!empty($config['auth'])): ?>
<input type="hidden"
       name="auth"
       value="1">
<?php endif; ?>

<input type="hidden"
       name="username"
       value="<?= h($config['username'] ?? '') ?>">

<input type="hidden"
       name="password"
       value="<?= h($config['password'] ?? '') ?>">

<input type="hidden"
       name="from_email"
       value="<?= h($config['from_email'] ?? '') ?>">

<input type="hidden"
       name="from_name"
       value="<?= h($config['from_name'] ?? '') ?>">

<input type="hidden"
       name="reply_to"
       value="<?= h($config['reply_to'] ?? '') ?>">

<button class="btn"
        type="submit">
接続テスト
</button>

</form>

<p>
接続状態：
<strong>
<?= h($config['connection_status'] ?? '未設定') ?>
</strong>
</p>

</div>


<div class="card">

<h3>テストメール送信</h3>

<form method="post">

<input type="hidden"
       name="action"
       value="send_test_mail">

<input type="hidden"
       name="return_screen"
       value="mail">

<input type="hidden"
       name="host"
       value="<?= h($config['host'] ?? '') ?>">

<input type="hidden"
       name="port"
       value="<?= h($config['port'] ?? '') ?>">

<input type="hidden"
       name="encryption"
       value="<?= h($config['encryption'] ?? 'tls') ?>">

<?php if (!empty($config['auth'])): ?>
<input type="hidden"
       name="auth"
       value="1">
<?php endif; ?>

<input type="hidden"
       name="username"
       value="<?= h($config['username'] ?? '') ?>">

<input type="hidden"
       name="password"
       value="<?= h($config['password'] ?? '') ?>">

<input type="hidden"
       name="from_email"
       value="<?= h($config['from_email'] ?? '') ?>">

<input type="hidden"
       name="from_name"
       value="<?= h($config['from_name'] ?? '') ?>">

<input type="hidden"
       name="reply_to"
       value="<?= h($config['reply_to'] ?? '') ?>">

<div class="form-group">

<label>テスト送信先</label>

<input type="email"
       name="test_to"
       required
       placeholder="test@example.com">

</div>

<button class="btn btn-primary"
        type="submit">
テストメール送信
</button>

</form>

</div>

<?php
}


/* =========================================================
 * Send screen
 * ======================================================= */

function render_send(?array $survey): void
{
    if ($survey === null) {
        redirect('index.php?screen=list');
    }

    $items = customers();

    $logs =
        array_reverse(
            array_values(
                array_filter(
                    send_logs(),
                    static fn($log) =>
                        (string)($log['survey_id'] ?? '') ===
                        (string)$survey['id']
                )
            )
        );

    ?>

<div class="toolbar">

<div>
<strong>
対象アンケート：
<?= h($survey['title']) ?>
</strong>
</div>

<div class="buttons">
<a class="btn"
   href="index.php?screen=list">
一覧へ
</a>
</div>

</div>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="return_screen"
       value="send">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="form-group">
<label>メール件名</label>
<input type="text"
       name="subject"
       value="<?= h($survey['title']) ?>"
       required>
</div>

<div class="form-group">
<label>本文</label>

<textarea name="body"
          required><?= h(
    "{顧客名} 様\n\n" .
    "アンケートへのご回答をお願いいたします。\n\n" .
    "{アンケートURL}\n"
) ?></textarea>

<div class="help">
利用可能な変数：{顧客名} / {アンケートURL}
</div>

</div>

<h3>顧客選択</h3>

<?php if ($items === []): ?>

<div class="empty">
顧客データがありません。
kintone設定画面から同期してください。
</div>

<?php else: ?>

<div class="table-wrap">

<table>

<thead>
<tr>
<th></th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署</th>
<th>電話番号</th>
</tr>
</thead>

<tbody>

<?php foreach ($items as $customer): ?>

<tr>

<td>
<input type="checkbox"
       name="customers[]"
       value="<?= h($customer['id'] ?? '') ?>">
</td>

<td><?= h($customer['organization'] ?? '') ?></td>
<td><?= h($customer['name'] ?? '') ?></td>
<td><?= h($customer['email'] ?? '') ?></td>
<td><?= h($customer['department'] ?? '') ?></td>
<td><?= h($customer['phone'] ?? '') ?></td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

<div style="margin-top:20px">

<button class="btn btn-primary"
        type="submit"
        onclick="return confirm('選択した顧客へ送信しますか？');">
一括送信
</button>

</div>

</form>

</div>


<div class="card">

<h3>送信履歴</h3>

<?php if ($logs === []): ?>

<div class="empty">
送信履歴はありません。
</div>

<?php else: ?>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>日時</th>
<th>顧客ID</th>
<th>メール</th>
<th>結果</th>
</tr>
</thead>

<tbody>

<?php foreach ($logs as $log): ?>

<tr>

<td>
<?= h(format_datetime($log['created_at'] ?? '')) ?>
</td>

<td><?= h($log['customer_id'] ?? '') ?></td>

<td><?= h($log['email'] ?? '') ?></td>

<td>
<?= ($log['status'] ?? '') === 'success'
    ? '<span class="badge badge-published">成功</span>'
    : '<span class="badge badge-ended">失敗</span>' ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>

<?php
}


/* =========================================================
 * Analytics
 * ======================================================= */

function render_analytics(?array $survey): void
{
    if ($survey === null) {
        redirect('index.php?screen=list');
    }

    $surveyAnswers =
        array_values(
            array_filter(
                answers(),
                static fn($answer) =>
                    (string)($answer['survey_id'] ?? '') ===
                    (string)$survey['id']
            )
        );

    $sent =
        array_values(
            array_filter(
                send_logs(),
                static fn($log) =>
                    (string)($log['survey_id'] ?? '') ===
                    (string)$survey['id']
            )
        );

    $sentCustomers =
        count(
            array_unique(
                array_map(
                    static fn($log) =>
                        (string)($log['customer_id'] ?? ''),
                    $sent
                )
            )
        );

    $answerCount =
        count($surveyAnswers);

    $responseRate =
        $sentCustomers > 0
            ? round(
                $answerCount /
                $sentCustomers *
                100,
                1
            )
            : 0;

    ?>

<div class="toolbar">

<div>
<strong>
対象アンケート：
<?= h($survey['title']) ?>
</strong>
</div>

<div class="buttons">
<a class="btn"
   href="index.php?screen=list">
一覧へ
</a>
</div>

</div>

<div class="stats">

<div class="stat">
<div class="stat-label">送信対象者数</div>
<div class="stat-value">
<?= h($sentCustomers) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">回答数</div>
<div class="stat-value">
<?= h($answerCount) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">未回答数</div>
<div class="stat-value">
<?= h(max(0, $sentCustomers - $answerCount)) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">回答率</div>
<div class="stat-value">
<?= h($responseRate) ?>%
</div>
</div>

</div>

<?php if ($answerCount === 0): ?>

<div class="card">

<div class="empty">
現在、回答データはありません
</div>

</div>

<?php else: ?>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">

<h3><?= h($group['title']) ?></h3>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<div class="question-number">
<?= h($question['number']) ?>
</div>

<h4><?= h($question['text']) ?></h4>

<?php
$counts = [];

foreach (
    $surveyAnswers as $answer
) {
    $value =
        $answer['answers']
        [$question['id']]
        ?? null;

    if (is_array($value)) {
        foreach ($value as $v) {
            $v = (string)$v;
            $counts[$v] =
                ($counts[$v] ?? 0) + 1;
        }
    } elseif ($value !== null) {
        $v = (string)$value;
        $counts[$v] =
            ($counts[$v] ?? 0) + 1;
    }
}

if ($counts === []):
?>

<p class="help">
回答データなし
</p>

<?php else: ?>

<?php foreach ($counts as $label => $count): ?>

<div style="margin-bottom:8px">
<strong><?= h($label) ?></strong>
：
<?= h($count) ?>件
</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>

<?php
}


/* =========================================================
 * Answer
 * ======================================================= */

function render_answer(): void
{
    $id =
        trim((string)($_GET['id'] ?? ''));

    $survey =
        find_survey($id);

    if ($survey === null) {
        render_answer_error(
            'アンケートが見つかりません。'
        );
        return;
    }

    if (
        $survey['status'] !== 'published'
    ) {
        render_answer_error(
            'このアンケートは現在回答できません。'
        );
        return;
    }

    ?>

<div class="card">

<h2><?= h($survey['title']) ?></h2>

<p>
<?= nl2br(h($survey['description'])) ?>
</p>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_confirm">

<input type="hidden"
       name="return_screen"
       value="answer">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="question-card">

<h3><?= h($group['title']) ?></h3>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="form-group">

<label>
<?= h($question['number']) ?>.
<?= h($question['text']) ?>

<?php if (!empty($question['required'])): ?>
<span style="color:#dc2626">*</span>
<?php endif; ?>

</label>

<?php if ($question['type'] === 'single'): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label>
<input type="radio"
       name="answers[<?= h($question['id']) ?>]"
       value="<?= h($option) ?>"
       <?= !empty($question['required']) ? 'required' : '' ?>>
<?= h($option) ?>
</label>

<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label>
<input type="checkbox"
       name="answers[<?= h($question['id']) ?>][]"
       value="<?= h($option) ?>">
<?= h($option) ?>
</label>

<?php endforeach; ?>

<?php else: ?>

<textarea
 name="answers[<?= h($question['id']) ?>]"
 <?= !empty($question['required']) ? 'required' : '' ?>></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<button class="btn btn-primary"
        type="submit">
回答確認へ
</button>

</form>

</div>

<?php
}

function render_confirm(): void
{
    $id =
        trim((string)($_GET['id'] ?? ''));

    $survey =
        find_survey($id);

    if ($survey === null) {
        render_answer_error(
            'アンケートが見つかりません。'
        );
        return;
    }

    $draft =
        $_SESSION['answer_draft'] ?? [];

    $submitted =
        is_array($draft['answers'] ?? null)
            ? $draft['answers']
            : [];

    ?>

<div class="card">

<h2>回答確認</h2>

<p>
以下の内容で送信します。
</p>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h3><?= h($group['title']) ?></h3>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<strong>
<?= h($question['number']) ?>.
<?= h($question['text']) ?>
</strong>

<p>
<?php
$value =
    $submitted[$question['id']]
    ?? '';

if (is_array($value)) {
    echo nl2br(
        h(implode(', ', $value))
    );
} else {
    echo nl2br(h($value));
}
?>
</p>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="buttons">

<a class="btn"
   href="index.php?screen=answer&id=<?= rawurlencode($id) ?>">
修正する
</a>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_complete">

<input type="hidden"
       name="return_screen"
       value="confirm">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<button class="btn btn-primary"
        type="submit"
        onclick="return confirm('回答を送信しますか？');">
送信する
</button>

</form>

</div>

</div>

<?php
}

function render_complete(): void
{
    ?>

<div class="card">

<div class="empty">

<h2>回答ありがとうございました。</h2>

<p>
回答が完了しました。
</p>

</div>

</div>

<?php
}

function render_answer_error(string $message): void
{
    ?>

<div class="card">

<div class="empty">

<h2>回答できません</h2>

<p><?= h($message) ?></p>

</div>

</div>

<?php
}


/* =========================================================
 * Utility
 * ======================================================= */

function status_label(string $status): string
{
    return [
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
    ][$status] ?? $status;
}

function status_badge(string $status): string
{
    $class = [
        'draft' => 'badge-draft',
        'published' => 'badge-published',
        'stopped' => 'badge-stopped',
        'ended' => 'badge-ended',
    ][$status] ?? 'badge-draft';

    return
        '<span class="badge ' .
        h($class) .
        '">' .
        h(status_label($status)) .
        '</span>';
}

function format_datetime(string $value): string
{
    if ($value === '') {
        return '';
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

function to_datetime_local(string $value): string
{
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

function answer_count(string $surveyId): int
{
    $items = answers();

    $count = 0;

    foreach ($items as $item) {
        if (
            (string)($item['survey_id'] ?? '') ===
            $surveyId
        ) {
            $count++;
        }
    }

    return $count;
}


/* =========================================================
 * Footer
 * ======================================================= */

function render_footer(): void
{
    ?>

</main>

<footer class="footer">
アンケートアプリ POC
</footer>

</body>
</html>

<?php
}
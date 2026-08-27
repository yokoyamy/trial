<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * prompt.txt に基づく単一エントリーポイント。
 *
 * 方針:
 * - 管理者認証なし（POC）
 * - CSRFトークンなし（POC）
 * - DBなし
 * - JSONファイル永続化
 * - PHP cURLなし
 * - PHP mail()なし
 * - kintoneは実接続
 * - SMTPは実接続
 * - 外部認証情報をブラウザへ出さない
 * - kintone / mail のPOST失敗時に一覧へ飛ばさない
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR         = __DIR__ . '/data';
const SETTINGS_FILE    = DATA_DIR . '/settings.json';
const SURVEYS_FILE     = DATA_DIR . '/surveys.json';
const CUSTOMERS_FILE   = DATA_DIR . '/customers.json';
const ANSWERS_FILE     = DATA_DIR . '/answers.json';
const SEND_LOG_FILE    = DATA_DIR . '/send_logs.json';

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
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データディレクトリを作成できません。');
    }
}

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
 * POCのため管理者認証も行わない。
 * 設定画面のPOSTは、失敗しても一覧へ飛ばさず
 * 各設定画面へ戻す。
 * ======================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {

            /* -------------------------
             * kintone
             * ----------------------- */

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


            /* -------------------------
             * Mail
             * ----------------------- */

            case 'save_mail':
                handle_save_mail();
                break;

            case 'test_mail':
                handle_test_mail();
                break;

            case 'send_test_mail':
                handle_send_test_mail();
                break;


            /* -------------------------
             * Survey
             * ----------------------- */

            case 'save_survey':
                handle_save_survey();
                break;

            case 'delete_survey':
                handle_delete_survey();
                break;

            case 'duplicate_survey':
                handle_duplicate_survey();
                break;


            /* -------------------------
             * Answer
             * ----------------------- */

            case 'answer_next':
                handle_answer_next();
                break;

            case 'answer_complete':
                handle_answer_complete();
                break;


            default:
                flash('error', '不明な操作です。');
                redirect('index.php?screen=list');
        }
    } catch (Throwable $e) {
        /*
         * 予期しないエラーでも設定画面から一覧へ落とさない。
         */
        $target = match ($screen) {
            'kintone' => 'index.php?screen=kintone',
            'mail'    => 'index.php?screen=mail',
            'edit'    => 'index.php?screen=edit',
            'answer'  => 'index.php?screen=answer&id=' . rawurlencode(
                (string)($_POST['survey_id'] ?? '')
            ),
            default   => 'index.php?screen=list',
        };

        flash('error', safe_error_message($e));
        redirect($target);
    }
}


/* =========================================================
 * Screen prerequisites
 * ======================================================= */

$survey = null;

if (in_array($screen, ['edit', 'preview', 'send', 'analytics'], true)) {
    $id = trim((string)($_GET['id'] ?? ''));

    if ($id === '') {
        flash('error', '対象アンケートが指定されていません。');
        redirect('index.php?screen=list');
    }

    $survey = find_survey($id);

    if ($survey === null) {
        flash('error', 'アンケートが見つかりません。');
        redirect('index.php?screen=list');
    }

    auto_update_survey_status($survey);
}

if (in_array($screen, ['answer', 'confirm', 'complete'], true)) {
    $id = trim((string)($_GET['id'] ?? ''));

    if ($id === '') {
        http_response_code(404);
        render_simple_error('アンケートが指定されていません。');
        exit;
    }

    $survey = find_survey($id);

    if ($survey === null) {
        http_response_code(404);
        render_simple_error('アンケートが見つかりません。');
        exit;
    }

    auto_update_survey_status($survey);
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
        render_edit($survey);
        break;

    case 'preview':
        render_preview($survey);
        break;

    case 'send':
        render_send($survey);
        break;

    case 'analytics':
        render_analytics($survey);
        break;

    case 'kintone':
        /*
         * survey IDを要求しない。
         * 一覧へのフォールバックもしない。
         */
        render_kintone();
        break;

    case 'mail':
        /*
         * survey IDを要求しない。
         * 一覧へのフォールバックもしない。
         */
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

    default:
        render_list();
        break;
}

render_footer();


/* =========================================================
 * Defaults / JSON
 * ======================================================= */

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
    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('JSON生成に失敗しました。');
    }

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('データ保存に失敗しました。');
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データ保存に失敗しました。');
    }
}

function settings(): array
{
    $data = read_json(SETTINGS_FILE, default_settings());
    return is_array($data) ? $data : default_settings();
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
    /*
     * アプリ内部の固定URLのみを使用する。
     */
    header('Location: ' . $url, true, 303);
    exit;
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
    /*
     * POCでは原因を可能な範囲で表示する。
     * 認証情報などが含まれる可能性のある文字列は除去する。
     */
    $message = trim($e->getMessage());

    if ($message === '') {
        return '処理に失敗しました。';
    }

    $message = preg_replace(
        '/(password|passwd|authorization|x-cybozu-authorization)\s*[:=]\s*[^\s,]+/i',
        '$1: [hidden]',
        $message
    ) ?? $message;

    return $message;
}

function now_string(): string
{
    return date('Y-m-d H:i:s');
}


/* =========================================================
 * Survey
 * ======================================================= */

function normalize_survey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? '');
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['description'] = (string)($survey['description'] ?? '');
    $survey['startAt'] = (string)($survey['startAt'] ?? '');
    $survey['endAt'] = (string)($survey['endAt'] ?? '');
    $survey['status'] = (string)($survey['status'] ?? 'draft');
    $survey['numbering'] = (string)($survey['numbering'] ?? 'global');
    $survey['groups'] = is_array($survey['groups'] ?? null)
        ? $survey['groups']
        : [];
    $survey['createdAt'] = (string)($survey['createdAt'] ?? '');
    $survey['updatedAt'] = (string)($survey['updatedAt'] ?? '');

    return $survey;
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
        'published' => 'ok',
        'stopped' => 'ng',
        default => 'none',
    };
}

function auto_update_survey_status(array &$survey): void
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';

            $items = surveys();

            foreach ($items as &$item) {
                if (($item['id'] ?? '') === ($survey['id'] ?? '')) {
                    $item = $survey;
                    $item['updatedAt'] = now_string();
                    break;
                }
            }
            unset($item);

            save_surveys($items);
        }
    }
}

function answer_count(string $surveyId): int
{
    $count = 0;

    foreach (answers() as $answer) {
        if (($answer['survey_id'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}


/* =========================================================
 * Survey actions
 * ======================================================= */

function handle_save_survey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $startAt = trim((string)($_POST['startAt'] ?? ''));
    $endAt = trim((string)($_POST['endAt'] ?? ''));
    $numbering = (string)($_POST['numbering'] ?? 'global');

    if ($title === '') {
        throw new InvalidArgumentException('アンケートタイトルを入力してください。');
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException('アンケートタイトルは200文字以内で入力してください。');
    }

    if (!in_array($numbering, ['global', 'group'], true)) {
        throw new InvalidArgumentException('採番方式が正しくありません。');
    }

    if ($startAt !== '' && strtotime($startAt) === false) {
        throw new InvalidArgumentException('開始日時が正しくありません。');
    }

    if ($endAt !== '' && strtotime($endAt) === false) {
        throw new InvalidArgumentException('終了日時が正しくありません。');
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) > strtotime($endAt)
    ) {
        throw new InvalidArgumentException('終了日時は開始日時より後にしてください。');
    }

    $items = surveys();
    $found = false;

    if ($id === '') {
        $id = 'survey-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));

        $items[] = [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => 'draft',
            'numbering' => $numbering,
            'groups' => [],
            'createdAt' => now_string(),
            'updatedAt' => now_string(),
        ];
    } else {
        foreach ($items as &$item) {
            if (($item['id'] ?? '') !== $id) {
                continue;
            }

            $currentStatus = (string)($item['status'] ?? 'draft');

            $item['title'] = $title;
            $item['description'] = $description;
            $item['startAt'] = $startAt;
            $item['endAt'] = $endAt;
            $item['numbering'] = $numbering;
            $item['updatedAt'] = now_string();

            /*
             * 既存編集時は現在状態を維持。
             */
            $item['status'] = $currentStatus;

            $found = true;
            break;
        }
        unset($item);

        if (!$found) {
            throw new InvalidArgumentException('アンケートが見つかりません。');
        }
    }

    save_surveys($items);

    flash('success', 'アンケートを保存しました。');
    redirect('index.php?screen=list');
}

function handle_delete_survey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    if ($id === '') {
        throw new InvalidArgumentException('アンケートが指定されていません。');
    }

    $items = surveys();
    $newItems = [];
    $found = false;

    foreach ($items as $item) {
        if (($item['id'] ?? '') === $id) {
            $found = true;
            continue;
        }

        $newItems[] = $item;
    }

    if (!$found) {
        throw new InvalidArgumentException('アンケートが見つかりません。');
    }

    save_surveys($newItems);

    flash('success', 'アンケートを削除しました。');
    redirect('index.php?screen=list');
}

function handle_duplicate_survey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $source = find_survey($id);

    if ($source === null) {
        throw new InvalidArgumentException('複製元アンケートが見つかりません。');
    }

    $copy = $source;
    $copy['id'] = 'survey-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $copy['title'] = ($source['title'] ?? '') . '（コピー）';
    $copy['status'] = 'draft';
    $copy['createdAt'] = now_string();
    $copy['updatedAt'] = now_string();

    $items = surveys();
    $items[] = $copy;

    save_surveys($items);

    flash('success', 'アンケートを複製しました。');
    redirect('index.php?screen=list');
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

    $value = trim($value, "/ \t\n\r\0\x0B");

    $suffix = '.cybozu.com';

    if (str_ends_with(strtolower($value), $suffix)) {
        $value = substr($value, 0, -strlen($suffix));
    }

    return $value;
}

function validate_kintone_input(array $post): array
{
    $subdomain = normalize_kintone_subdomain(
        (string)($post['subdomain'] ?? '')
    );

    $appId = trim((string)($post['app_id'] ?? ''));
    $username = trim((string)($post['username'] ?? ''));
    $password = (string)($post['password'] ?? '');
    $proxy = trim((string)($post['proxy'] ?? ''));

    if (
        $subdomain === ''
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $subdomain)
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが正しくありません。'
        );
    }

    if (!ctype_digit($appId) || (int)$appId <= 0) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが正しくありません。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException('ログイン名を入力してください。');
    }

    /*
     * 空欄の場合は既存パスワードを維持する。
     * 設定画面のマッピング保存等でパスワードを消さない。
     */
    if ($password === '') {
        $old = settings()['kintone'] ?? [];
        $password = (string)($old['password'] ?? '');

        if ($password === '') {
            throw new InvalidArgumentException('パスワードを入力してください。');
        }
    }

    if (
        $proxy !== ''
        && !preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)
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
    $config = settings()['kintone'] ?? [];

    $subdomain = normalize_kintone_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    $appId = (string)($config['app_id'] ?? '');
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');

    if (
        $subdomain === ''
        || $appId === ''
        || $username === ''
        || $password === ''
    ) {
        throw new RuntimeException('kintone設定が未完了です。');
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
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'timeout' => READ_TIMEOUT,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => !empty($config['verify_ssl']),
            'verify_peer_name' => !empty($config['verify_ssl']),
        ],
    ];

    if (!empty($config['proxy'])) {
        $proxy = trim((string)$config['proxy']);

        if (preg_match('/^([^:]+):(\d+)$/', $proxy, $m)) {
            $options['http']['proxy'] =
                'tcp://' . $m[1] . ':' . $m[2];

            $options['http']['request_fulluri'] = true;
        }
    }

    if ($body !== null) {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException('kintone送信データの生成に失敗しました。');
        }

        $options['http']['content'] = $json;
    }

    $context = stream_context_create($options);

    $raw = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    if ($raw === false) {
        throw new RuntimeException(
            'kintoneへ接続できませんでした。ネットワーク、Proxy、SSL設定を確認してください。'
        );
    }

    $data = json_decode($raw, true);

    if ($status < 200 || $status >= 300) {
        $detail = '';

        if (is_array($data)) {
            $detail = (string)($data['message'] ?? '');
        }

        throw new RuntimeException(
            'kintone通信エラー HTTP ' .
            $status .
            ($detail !== '' ? ': ' . $detail : '')
        );
    }

    return is_array($data) ? $data : [];
}


/* =========================================================
 * kintone actions
 * ======================================================= */

function handle_save_kintone(): void
{
    $input = validate_kintone_input($_POST);

    $settings = settings();
    $old = $settings['kintone'] ?? [];

    $mapping = $old['field_mapping'] ?? [];

    if (isset($_POST['field_mapping']) && is_array($_POST['field_mapping'])) {
        $postedMapping = $_POST['field_mapping'];

        $mapping['organization'] =
            trim((string)($postedMapping['organization'] ?? ''));

        $mapping['name'] =
            trim((string)($postedMapping['name'] ?? ''));

        $mapping['email'] =
            trim((string)($postedMapping['email'] ?? ''));

        $mapping['department'] =
            trim((string)($postedMapping['department'] ?? ''));

        $mapping['phone'] =
            trim((string)($postedMapping['phone'] ?? ''));

        $mapping['address'] =
            is_array($postedMapping['address'] ?? null)
                ? array_values(array_map(
                    'strval',
                    $postedMapping['address']
                ))
                : [];
    }

    $settings['kintone'] = array_merge(
        $old,
        $input,
        [
            'field_mapping' => $mapping,
        ]
    );

    save_settings($settings);

    flash('success', 'kintone設定を保存しました。');

    redirect('index.php?screen=kintone');
}

function handle_test_kintone(): void
{
    /*
     * 設定保存済みの内容で実際に接続。
     */
    $data = kintone_request(
        'GET',
        '/k/v1/app.json?id=' .
        rawurlencode(
            (string)(settings()['kintone']['app_id'] ?? '')
        )
    );

    $settings = settings();

    $settings['kintone']['connection_status'] = '接続確認済み';
    $settings['kintone']['last_test_at'] = now_string();

    save_settings($settings);

    flash('success', 'kintone接続成功: ' .
        h((string)($data['name'] ?? 'アプリ'))
    );

    redirect('index.php?screen=kintone');
}

function handle_fetch_kintone_fields(): void
{
    $config = settings()['kintone'] ?? [];

    $appId = (string)($config['app_id'] ?? '');

    if ($appId === '') {
        throw new InvalidArgumentException(
            '先にkintone設定を保存してください。'
        );
    }

    $data = kintone_request(
        'GET',
        '/k/v1/app/form/fields.json?id=' .
        rawurlencode($appId)
    );

    $properties = $data['properties'] ?? [];

    if (!is_array($properties)) {
        throw new RuntimeException(
            'kintone項目一覧を取得できませんでした。'
        );
    }

    $_SESSION['kintone_fields'] = $properties;

    flash(
        'success',
        'kintone項目一覧を取得しました。'
    );

    redirect('index.php?screen=kintone');
}

function handle_sync_kintone(): void
{
    $config = settings()['kintone'] ?? [];
    $appId = (string)($config['app_id'] ?? '');

    if ($appId === '') {
        throw new InvalidArgumentException(
            'kintone設定を保存してください。'
        );
    }

    $mapping = $config['field_mapping'] ?? [];

    $fields = [
        'organization',
        'name',
        'email',
        'department',
        'phone',
    ];

    foreach ($fields as $key) {
        if (empty($mapping[$key])) {
            throw new InvalidArgumentException(
                '顧客項目マッピングが未設定です: ' . $key
            );
        }
    }

    /*
     * kintone REST APIで顧客情報を取得。
     */
    $data = kintone_request(
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode($appId) .
        '&query=' .
        rawurlencode('order by $id asc limit 500')
    );

    $records = $data['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $items = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $value = static function (
            array $record,
            string $code
        ): string {
            $field = $record[$code] ?? [];

            if (is_array($field)) {
                return (string)($field['value'] ?? '');
            }

            return '';
        };

        $addressParts = [];

        foreach (($mapping['address'] ?? []) as $code) {
            $part = $value($record, (string)$code);

            if ($part !== '') {
                $addressParts[] = $part;
            }
        }

        $items[] = [
            'id' => bin2hex(random_bytes(8)),
            'organization' => $value(
                $record,
                (string)$mapping['organization']
            ),
            'name' => $value(
                $record,
                (string)$mapping['name']
            ),
            'email' => $value(
                $record,
                (string)$mapping['email']
            ),
            'department' => $value(
                $record,
                (string)$mapping['department']
            ),
            'phone' => $value(
                $record,
                (string)$mapping['phone']
            ),
            'address' => implode(' ', $addressParts),
            'updatedAt' => now_string(),
        ];
    }

    save_customers($items);

    flash(
        'success',
        count($items) . '件の顧客情報を同期しました。'
    );

    redirect('index.php?screen=kintone');
}


/* =========================================================
 * Mail validation
 * ======================================================= */

function validate_mail_input(array $post): array
{
    $host = trim((string)($post['host'] ?? ''));
    $port = trim((string)($post['port'] ?? ''));
    $encryption = (string)($post['encryption'] ?? 'tls');
    $auth = !empty($post['auth']);
    $username = trim((string)($post['username'] ?? ''));
    $password = (string)($post['password'] ?? '');
    $fromEmail = trim((string)($post['from_email'] ?? ''));
    $fromName = trim((string)($post['from_name'] ?? ''));
    $replyTo = trim((string)($post['reply_to'] ?? ''));

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

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        throw new InvalidArgumentException(
            '暗号化方式が正しくありません。'
        );
    }

    /*
     * 保存済みパスワードは空欄表示。
     * 空欄保存の場合は既存値を維持。
     */
    if ($password === '') {
        $old = settings()['mail'] ?? [];
        $password = (string)($old['password'] ?? '');
    }

    if ($auth && $username === '') {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はユーザー名が必要です。'
        );
    }

    if ($auth && $password === '') {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はパスワードが必要です。'
        );
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが正しくありません。'
        );
    }

    if (
        $replyTo !== ''
        && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが正しくありません。'
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
 * SMTP
 * ======================================================= */

function smtp_socket(): array
{
    $config = settings()['mail'] ?? [];

    $host = (string)($config['host'] ?? '');
    $port = (int)($config['port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'none');

    if ($host === '' || $port <= 0) {
        throw new RuntimeException(
            'SMTP設定が未完了です。'
        );
    }

    $target = $host;

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket = @fsockopen(
        $target,
        $port,
        $errno,
        $errstr,
        CONNECT_TIMEOUT
    );

    if (!is_resource($socket)) {
        throw new RuntimeException(
            'SMTPサーバへ接続できませんでした。'
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    $response = smtp_read($socket);

    if (!str_starts_with($response, '220')) {
        fclose($socket);

        throw new RuntimeException(
            'SMTPサーバから正常な応答がありません。'
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

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'SMTP TLS接続を確立できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );
    }

    return [$socket, $config];
}

function smtp_read($socket): string
{
    $response = '';

    while (($line = fgets($socket, 4096)) !== false) {
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

    return trim($response);
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

    $response = smtp_read($socket);

    $code = (int)substr(
        trim($response),
        0,
        3
    );

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . $code
        );
    }

    return $response;
}

function smtp_authenticate(
    $socket,
    array $config
): void {
    if (empty($config['auth'])) {
        return;
    }

    smtp_command(
        $socket,
        'AUTH LOGIN',
        [334]
    );

    smtp_command(
        $socket,
        base64_encode((string)$config['username']),
        [334]
    );

    smtp_command(
        $socket,
        base64_encode((string)$config['password']),
        [235]
    );
}

function smtp_send_message(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            '宛先メールアドレスが正しくありません。'
        );
    }

    [$socket, $config] = smtp_socket();

    try {
        smtp_authenticate(
            $socket,
            $config
        );

        smtp_command(
            $socket,
            'MAIL FROM:<' . $config['from_email'] . '>',
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

        $headers = [
            'From: ' .
                format_mail_address(
                    (string)$config['from_email'],
                    (string)($config['from_name'] ?? '')
                ),
            'To: <' . $to . '>',
            'Subject: ' . mb_encode_mimeheader(
                $subject,
                'UTF-8'
            ),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (!empty($config['reply_to'])) {
            $headers[] =
                'Reply-To: <' .
                $config['reply_to'] .
                '>';
        }

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            normalize_mail_body($body) .
            "\r\n.";

        smtp_command(
            $socket,
            $message,
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

function format_mail_address(
    string $email,
    string $name
): string {
    if ($name === '') {
        return '<' . $email . '>';
    }

    return mb_encode_mimeheader(
        $name,
        'UTF-8'
    ) . ' <' . $email . '>';
}

function normalize_mail_body(string $body): string
{
    $body = str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );

    /*
     * SMTP DATA中のドット始まりをエスケープ。
     */
    $body = preg_replace(
        '/^\\./m',
        '..',
        $body
    ) ?? $body;

    return str_replace(
        "\n",
        "\r\n",
        $body
    );
}


/* =========================================================
 * Mail actions
 * ======================================================= */

function handle_save_mail(): void
{
    $input = validate_mail_input($_POST);

    $settings = settings();
    $old = $settings['mail'] ?? [];

    $settings['mail'] = array_merge(
        $old,
        $input
    );

    save_settings($settings);

    flash(
        'success',
        'メール設定を保存しました。'
    );

    /*
     * 絶対に一覧へ戻さない。
     */
    redirect('index.php?screen=mail');
}

function handle_test_mail(): void
{
    $config = settings()['mail'] ?? [];

    /*
     * SMTPへ実際に接続。
     */
    [$socket, $config] = smtp_socket();

    try {
        smtp_command(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }

    $settings = settings();

    $settings['mail']['connection_status'] =
        '接続確認済み';

    $settings['mail']['last_test_at'] =
        now_string();

    save_settings($settings);

    flash(
        'success',
        'SMTP接続確認に成功しました。'
    );

    redirect('index.php?screen=mail');
}

function handle_send_test_mail(): void
{
    $config = settings()['mail'] ?? [];

    $to = trim(
        (string)($_POST['test_to'] ?? '')
    );

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが正しくありません。'
        );
    }

    smtp_send_message(
        $config,
        $to,
        'アンケートアプリ テストメール',
        "アンケートアプリのテストメールです。\n\n"
        . now_string()
    );

    flash(
        'success',
        'テストメールを送信しました。'
    );

    redirect('index.php?screen=mail');
}


/* =========================================================
 * Answer
 * ======================================================= */

function handle_answer_next(): void
{
    $surveyId = trim(
        (string)($_POST['survey_id'] ?? '')
    );

    $survey = find_survey($surveyId);

    if ($survey === null) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    $responses = $_POST['responses'] ?? [];

    if (!is_array($responses)) {
        $responses = [];
    }

    $_SESSION['answer'][$surveyId] = $responses;

    redirect(
        'index.php?screen=confirm&id=' .
        rawurlencode($surveyId)
    );
}

function handle_answer_complete(): void
{
    $surveyId = trim(
        (string)($_POST['survey_id'] ?? '')
    );

    $survey = find_survey($surveyId);

    if ($survey === null) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    $responses =
        $_SESSION['answer'][$surveyId] ?? [];

    if (!is_array($responses)) {
        $responses = [];
    }

    $items = answers();

    $items[] = [
        'id' => bin2hex(random_bytes(8)),
        'survey_id' => $surveyId,
        'responses' => $responses,
        'createdAt' => now_string(),
    ];

    save_answers($items);

    unset($_SESSION['answer'][$surveyId]);

    redirect(
        'index.php?screen=complete&id=' .
        rawurlencode($surveyId)
    );
}


/* =========================================================
 * UI
 * ======================================================= */

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

    $isAdmin = in_array(
        $screen,
        $adminScreens,
        true
    );

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>アンケートアプリ</title>';

    echo <<<CSS
<style>
:root {
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --danger:#dc2626;
    --gray:#64748b;
    --light:#f1f5f9;
    --border:#dbe2ea;
    --text:#1e293b;
    --white:#fff;
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

.header {
    background:#0f172a;
    color:#fff;
}

.header-inner {
    max-width:1400px;
    min-height:64px;
    margin:auto;
    padding:0 24px;
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
    gap:6px;
}

.nav a {
    color:#cbd5e1;
    padding:8px 11px;
    border-radius:6px;
}

.nav a.active,
.nav a:hover {
    color:#fff;
    background:#1e293b;
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
    padding:24px;
    margin-bottom:20px;
}

h1 {
    margin:0 0 24px;
    font-size:28px;
}

h2 {
    margin:0 0 18px;
    font-size:20px;
}

h3 {
    margin:0 0 12px;
}

.form-grid {
    display:grid;
    grid-template-columns:180px minmax(0,1fr);
    gap:16px 20px;
    align-items:start;
}

label {
    font-weight:600;
}

input,
select,
textarea {
    width:100%;
    padding:10px 12px;
    border:1px solid #cbd5e1;
    border-radius:7px;
    background:#fff;
    color:var(--text);
    font:inherit;
}

textarea {
    min-height:130px;
    resize:vertical;
}

button {
    border:0;
    border-radius:7px;
    padding:10px 15px;
    cursor:pointer;
    font:inherit;
    font-weight:600;
}

button.primary {
    color:#fff;
    background:var(--primary);
}

button.primary:hover {
    background:var(--primary-dark);
}

button.success {
    color:#fff;
    background:var(--success);
}

button.danger {
    color:#fff;
    background:var(--danger);
}

button.secondary {
    background:#e2e8f0;
    color:#334155;
}

.actions,
.setting-actions {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:20px;
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
    padding:11px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}

th {
    background:#f8fafc;
}

.status {
    display:inline-block;
    padding:3px 8px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    background:#e2e8f0;
}

.status.ok {
    background:#dcfce7;
    color:#166534;
}

.status.ng {
    background:#fee2e2;
    color:#991b1b;
}

.flash {
    padding:13px 16px;
    border-radius:8px;
    margin-bottom:16px;
}

.flash.success {
    background:#dcfce7;
    color:#166534;
}

.flash.error {
    background:#fee2e2;
    color:#991b1b;
}

.small {
    color:var(--gray);
    font-size:13px;
}

.checkbox {
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:400;
}

.checkbox input {
    width:auto;
}

.grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
    gap:16px;
}

.stat {
    padding:18px;
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
}

.stat strong {
    display:block;
    font-size:26px;
    margin-top:5px;
}

@media(max-width:700px) {
    .form-grid {
        grid-template-columns:1fr;
    }

    .header-inner {
        align-items:flex-start;
        flex-direction:column;
        padding-top:14px;
        padding-bottom:14px;
    }

    .container {
        padding:18px 14px 40px;
    }
}
</style>
CSS;

    echo '</head>';
    echo '<body>';

    if ($isAdmin) {
        echo '<header class="header">';
        echo '<div class="header-inner">';
        echo '<a class="logo" href="index.php?screen=list">アンケート管理</a>';
        echo '<nav class="nav">';

        nav_link(
            'list',
            'アンケート一覧',
            $screen
        );

        nav_link(
            'kintone',
            'kintone設定',
            $screen
        );

        nav_link(
            'mail',
            'メール設定',
            $screen
        );

        echo '</nav>';
        echo '</div>';
        echo '</header>';
    }

    echo '<main class="container">';

    foreach (get_flashes() as $item) {
        $type = ($item['type'] ?? '') === 'success'
            ? 'success'
            : 'error';

        echo '<div class="flash ' .
            h($type) .
            '">' .
            h($item['message'] ?? '') .
            '</div>';
    }
}

function nav_link(
    string $screen,
    string $label,
    string $current
): void {
    $active = $screen === $current
        ? ' active'
        : '';

    echo '<a class="' . h($active) .
        '" href="index.php?screen=' .
        h($screen) .
        '">' .
        h($label) .
        '</a>';
}

function render_footer(): void
{
    echo '</main>';
    echo '</body>';
    echo '</html>';
}

function render_simple_error(string $message): void
{
    echo '<div class="card">';
    echo '<h1>エラー</h1>';
    echo '<p>' . h($message) . '</p>';
    echo '</div>';
}


/* =========================================================
 * List
 * ======================================================= */

function render_list(): void
{
    $items = surveys();

    usort(
        $items,
        static function (array $a, array $b): int {
            return strcmp(
                (string)($b['updatedAt'] ?? ''),
                (string)($a['updatedAt'] ?? '')
            );
        }
    );

    echo '<h1>アンケート一覧</h1>';

    echo '<div class="actions">';
    echo '<a href="index.php?screen=edit">';
    echo '<button class="primary" type="button">新規作成</button>';
    echo '</a>';
    echo '</div>';

    echo '<div class="card">';
    echo '<div class="table-wrap">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>タイトル</th>';
    echo '<th>作成日</th>';
    echo '<th>更新日</th>';
    echo '<th>期間</th>';
    echo '<th>状態</th>';
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
        $survey = normalize_survey($item);

        echo '<tr>';

        echo '<td>' .
            h($survey['title']) .
            '</td>';

        echo '<td>' .
            h($survey['createdAt']) .
            '</td>';

        echo '<td>' .
            h($survey['updatedAt']) .
            '</td>';

        echo '<td>' .
            h($survey['startAt']) .
            ' ～ ' .
            h($survey['endAt']) .
            '</td>';

        echo '<td>';
        echo '<span class="status ' .
            h(survey_status_class($survey['status'])) .
            '">' .
            h(survey_status_label($survey['status'])) .
            '</span>';
        echo '</td>';

        echo '<td>' .
            answer_count($survey['id']) .
            '</td>';

        echo '<td>';
        echo '<div class="actions">';

        echo '<a href="index.php?screen=edit&id=' .
            rawurlencode($survey['id']) .
            '">確認・編集</a>';

        echo '<a href="index.php?screen=preview&id=' .
            rawurlencode($survey['id']) .
            '">プレビュー</a>';

        echo '<a href="index.php?screen=analytics&id=' .
            rawurlencode($survey['id']) .
            '">集計</a>';

        echo '<a href="index.php?screen=send&id=' .
            rawurlencode($survey['id']) .
            '">送信</a>';

        echo '<form method="post" style="display:inline">';
        echo '<input type="hidden" name="action" value="duplicate_survey">';
        echo '<input type="hidden" name="id" value="' .
            h($survey['id']) .
            '">';
        echo '<button class="secondary">複製</button>';
        echo '</form>';

        echo '<form method="post" style="display:inline" '
            . 'onsubmit="return confirm(\'削除しますか？\')">';
        echo '<input type="hidden" name="action" value="delete_survey">';
        echo '<input type="hidden" name="id" value="' .
            h($survey['id']) .
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

function render_edit(?array $survey): void
{
    $survey = $survey ?? [
        'id' => '',
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'groups' => [],
    ];

    echo '<h1>アンケート作成・編集</h1>';

    echo '<div class="card">';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_survey">';
    echo '<input type="hidden" name="id" value="' .
        h($survey['id']) .
        '">';

    echo '<div class="form-grid">';

    echo '<label>アンケートタイトル</label>';
    echo '<input name="title" value="' .
        h($survey['title']) .
        '">';

    echo '<label>アンケート説明</label>';
    echo '<textarea name="description">' .
        h($survey['description']) .
        '</textarea>';

    echo '<label>開始日時</label>';
    echo '<input type="datetime-local" name="startAt" value="' .
        h(to_datetime_local($survey['startAt'])) .
        '">';

    echo '<label>終了日時</label>';
    echo '<input type="datetime-local" name="endAt" value="' .
        h(to_datetime_local($survey['endAt'])) .
        '">';

    echo '<label>質問番号の採番方式</label>';
    echo '<select name="numbering">';

    echo option(
        'global',
        'アンケート全体で通番：Q1、Q2、Q3...',
        (string)$survey['numbering']
    );

    echo option(
        'group',
        'グループ毎に採番：Q1-1、Q1-2...',
        (string)$survey['numbering']
    );

    echo '</select>';

    echo '<label>状態</label>';
    echo '<div>';
    echo '<span class="status ' .
        h(survey_status_class($survey['status'])) .
        '">' .
        h(survey_status_label($survey['status'])) .
        '</span>';
    echo '</div>';

    echo '</div>';

    echo '<div class="actions">';

    echo '<a href="index.php?screen=list">';
    echo '<button class="secondary" type="button">キャンセル</button>';
    echo '</a>';

    echo '<button class="primary" type="submit">';
    echo '保存して一覧へ';
    echo '</button>';

    echo '</div>';

    echo '</form>';
    echo '</div>';

    /*
     * POC用の質問・グループ編集領域。
     */
    echo '<div class="card">';
    echo '<h2>質問・グループ</h2>';

    if (empty($survey['groups'])) {
        echo '<p class="small">';
        echo 'まだグループ・質問はありません。';
        echo '</p>';
    } else {
        foreach ($survey['groups'] as $group) {
            echo '<div class="card">';
            echo '<h3>' .
                h($group['title'] ?? '') .
                '</h3>';

            foreach (($group['questions'] ?? []) as $question) {
                echo '<p>';
                echo h($question['number'] ?? '') .
                    ' ' .
                    h($question['text'] ?? '');
                echo '</p>';
            }

            echo '</div>';
        }
    }

    echo '</div>';
}


/* =========================================================
 * Preview
 * ======================================================= */

function render_preview(?array $survey): void
{
    if ($survey === null) {
        render_simple_error('アンケートがありません。');
        return;
    }

    echo '<h1>プレビュー</h1>';

    echo '<div class="card">';
    echo '<h2>' . h($survey['title']) . '</h2>';
    echo '<p>' . nl2br(h($survey['description'])) . '</p>';

    foreach ($survey['groups'] as $group) {
        echo '<h3>' .
            h($group['title'] ?? '') .
            '</h3>';

        foreach (($group['questions'] ?? []) as $question) {
            echo '<div class="card">';
            echo '<strong>' .
                h($question['number'] ?? '') .
                ' ' .
                h($question['text'] ?? '') .
                '</strong>';

            $type = $question['type'] ?? '';

            if ($type === 'single') {
                foreach (($question['options'] ?? []) as $option) {
                    echo '<label class="checkbox">';
                    echo '<input type="radio" disabled>';
                    echo h($option['label'] ?? '');
                    echo '</label>';
                }
            } elseif ($type === 'multiple') {
                foreach (($question['options'] ?? []) as $option) {
                    echo '<label class="checkbox">';
                    echo '<input type="checkbox" disabled>';
                    echo h($option['label'] ?? '');
                    echo '</label>';
                }
            } else {
                echo '<textarea disabled></textarea>';
            }

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
        render_simple_error('アンケートがありません。');
        return;
    }

    echo '<h1>顧客選択・メール送信</h1>';

    echo '<div class="card">';
    echo '<h2>対象アンケート</h2>';
    echo '<p><strong>' .
        h($survey['title']) .
        '</strong></p>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>顧客</h2>';

    $items = customers();

    if (!$items) {
        echo '<p class="small">';
        echo '顧客データがありません。kintone設定から同期してください。';
        echo '</p>';
    } else {
        echo '<div class="table-wrap">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>選択</th>';
        echo '<th>組織名</th>';
        echo '<th>氏名</th>';
        echo '<th>メール</th>';
        echo '<th>部署</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($items as $customer) {
            echo '<tr>';
            echo '<td><input type="checkbox"></td>';
            echo '<td>' . h($customer['organization'] ?? '') . '</td>';
            echo '<td>' . h($customer['name'] ?? '') . '</td>';
            echo '<td>' . h($customer['email'] ?? '') . '</td>';
            echo '<td>' . h($customer['department'] ?? '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>メール作成</h2>';

    echo '<div class="form-grid">';

    echo '<label>件名</label>';
    echo '<input name="subject" value="">';

    echo '<label>本文</label>';
    echo '<textarea name="body">';
    echo 'アンケートのご案内です。';
    echo "\n\n";
    echo '{顧客名} 様';
    echo "\n\n";
    echo '{アンケートURL}';
    echo '</textarea>';

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary" type="button">';
    echo '送信';
    echo '</button>';
    echo '</div>';

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>送信履歴</h2>';
    echo '<p class="small">';
    echo '送信履歴はこの画面内に表示します。';
    echo '</p>';
    echo '</div>';
}


/* =========================================================
 * Analytics
 * ======================================================= */

function render_analytics(?array $survey): void
{
    if ($survey === null) {
        render_simple_error('アンケートがありません。');
        return;
    }

    $count = answer_count($survey['id']);

    echo '<h1>回答集計・分析</h1>';

    echo '<div class="grid">';

    stat_card(
        '対象アンケート',
        $survey['title']
    );

    stat_card(
        '回答数',
        (string)$count
    );

    stat_card(
        '回答率',
        '—'
    );

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>設問別集計</h2>';

    if ($count === 0) {
        echo '<p>現在、回答データはありません</p>';
    } else {
        echo '<p>設問別集計を表示します。</p>';
    }

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>個別回答</h2>';

    foreach (answers() as $answer) {
        if (($answer['survey_id'] ?? '') !== $survey['id']) {
            continue;
        }

        echo '<pre>';
        echo h(
            json_encode(
                $answer,
                JSON_UNESCAPED_UNICODE |
                JSON_PRETTY_PRINT
            )
        );
        echo '</pre>';
    }

    echo '</div>';
}

function stat_card(string $label, string $value): void
{
    echo '<div class="stat">';
    echo '<span class="small">' . h($label) . '</span>';
    echo '<strong>' . h($value) . '</strong>';
    echo '</div>';
}


/* =========================================================
 * kintone screen
 * ======================================================= */

function render_kintone(): void
{
    $config = settings()['kintone'] ?? [];
    $fields = $_SESSION['kintone_fields'] ?? [];

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
        '" placeholder="xxxx.cybozu.com">' .
        '<div class="small">https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx</div>'
    );

    form_row(
        '顧客管理アプリID',
        '<input name="app_id" value="' .
        h($config['app_id'] ?? '') .
        '" inputmode="numeric">'
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
        '<div class="small">保存済みの場合は空欄のままで既存値を維持します。</div>'
    );

    form_row(
        'Proxy',
        '<input name="proxy" value="' .
        h($config['proxy'] ?? '') .
        '" placeholder="host:port">'
    );

    $checked = !empty($config['verify_ssl'])
        ? ' checked'
        : '';

    form_row(
        'SSL証明書検証',
        '<label class="checkbox">' .
        '<input type="checkbox" name="verify_ssl" value="1"' .
        $checked .
        '>有効にする' .
        '</label>' .
        '<div class="small">POC初期値は無効です。</div>'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary" type="submit">';
    echo '設定保存';
    echo '</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';


    /* Connection */

    echo '<div class="card">';
    echo '<h2>接続確認・データ操作</h2>';

    echo '<p>接続状態: ';

    $status = (string)(
        $config['connection_status'] ?? '未設定'
    );

    echo '<span class="status ' .
        h(
            $status === '接続確認済み'
                ? 'ok'
                : ($status === '接続できません'
                    ? 'ng'
                    : 'none')
        ) .
        '">' .
        h($status) .
        '</span>';

    echo '</p>';

    if (!empty($config['last_test_at'])) {
        echo '<p class="small">最終確認: ' .
            h($config['last_test_at']) .
            '</p>';
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


    /* Fields */

    echo '<div class="card">';
    echo '<h2>項目一覧</h2>';

    if (!$fields) {
        echo '<p class="small">';
        echo 'まだ取得していません。';
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
            echo '<tr>';
            echo '<td>' . h($code) . '</td>';
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


    /* Mapping */

    echo '<div class="card">';
    echo '<h2>顧客項目マッピング</h2>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="form-grid">';

    $mapping = $config['field_mapping'] ?? [];

    form_row(
        '組織名',
        mapping_select(
            'organization',
            $mapping['organization'] ?? '',
            $fields
        )
    );

    form_row(
        '氏名',
        mapping_select(
            'name',
            $mapping['name'] ?? '',
            $fields
        )
    );

    form_row(
        'メールアドレス',
        mapping_select(
            'email',
            $mapping['email'] ?? '',
            $fields
        )
    );

    form_row(
        '部署名',
        mapping_select(
            'department',
            $mapping['department'] ?? '',
            $fields
        )
    );

    form_row(
        '電話番号',
        mapping_select(
            'phone',
            $mapping['phone'] ?? '',
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
    echo '<button class="primary" type="submit">';
    echo 'マッピングを保存';
    echo '</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}


/* =========================================================
 * Mail screen
 * ======================================================= */

function render_mail(): void
{
    $config = settings()['mail'] ?? [];

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
        '<input name="port" value="' .
        h($config['port'] ?? '') .
        '" inputmode="numeric">'
    );

    $encryption =
        (string)($config['encryption'] ?? 'tls');

    form_row(
        '暗号化方式',
        '<select name="encryption">' .
        option('ssl', 'SSL', $encryption) .
        option('tls', 'TLS', $encryption) .
        option('none', 'なし', $encryption) .
        '</select>'
    );

    $auth = !empty($config['auth'])
        ? ' checked'
        : '';

    form_row(
        'SMTP認証',
        '<label class="checkbox">' .
        '<input type="checkbox" name="auth" value="1"' .
        $auth .
        '>認証を使用する' .
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
        '<div class="small">保存済みの場合は空欄のままで既存値を維持します。</div>'
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
    echo '<button class="primary" type="submit">';
    echo '設定保存';
    echo '</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';


    /* Connection */

    echo '<div class="card">';
    echo '<h2>接続状態</h2>';

    $status = (string)(
        $config['connection_status'] ?? '未設定'
    );

    echo '<p>';

    echo '<span class="status ' .
        h(
            $status === '接続確認済み'
                ? 'ok'
                : ($status === '接続できません'
                    ? 'ng'
                    : 'none')
        ) .
        '">' .
        h($status) .
        '</span>';

    echo '</p>';

    if (!empty($config['last_test_at'])) {
        echo '<p class="small">最終確認: ' .
            h($config['last_test_at']) .
            '</p>';
    }

    echo '<div class="setting-actions">';

    render_action_form(
        'test_mail',
        '接続テスト',
        'primary'
    );

    echo '</div>';

    echo '</div>';


    /* Test mail */

    echo '<div class="card">';
    echo '<h2>テストメール送信</h2>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="send_test_mail">';

    echo '<div class="form-grid">';

    form_row(
        '送信先',
        '<input type="email" name="test_to" value="" placeholder="test@example.com">'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="success" type="submit">';
    echo 'テストメール送信';
    echo '</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}


/* =========================================================
 * Answer screens
 * ======================================================= */

function render_answer(?array $survey): void
{
    if ($survey === null) {
        render_simple_error('アンケートがありません。');
        return;
    }

    echo '<h1>' . h($survey['title']) . '</h1>';

    echo '<div class="card">';
    echo '<p>' .
        nl2br(h($survey['description'])) .
        '</p>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="answer_next">';
    echo '<input type="hidden" name="survey_id" value="' .
        h($survey['id']) .
        '">';

    foreach ($survey['groups'] as $group) {
        echo '<h2>' .
            h($group['title'] ?? '') .
            '</h2>';

        foreach (($group['questions'] ?? []) as $question) {
            render_question($question);
        }
    }

    echo '<div class="actions">';
    echo '<button class="primary" type="submit">';
    echo '回答確認へ';
    echo '</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}

function render_confirm(?array $survey): void
{
    if ($survey === null) {
        render_simple_error('アンケートがありません。');
        return;
    }

    $responses =
        $_SESSION['answer'][$survey['id']] ?? [];

    echo '<h1>回答確認</h1>';

    echo '<div class="card">';
    echo '<h2>' .
        h($survey['title']) .
        '</h2>';

    echo '<pre>';
    echo h(
        json_encode(
            $responses,
            JSON_UNESCAPED_UNICODE |
            JSON_PRETTY_PRINT
        )
    );
    echo '</pre>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="answer_complete">';
    echo '<input type="hidden" name="survey_id" value="' .
        h($survey['id']) .
        '">';

    echo '<div class="actions">';
    echo '<a href="index.php?screen=answer&id=' .
        rawurlencode($survey['id']) .
        '">';
    echo '<button type="button" class="secondary">';
    echo '修正する';
    echo '</button>';
    echo '</a>';

    echo '<button class="primary" type="submit">';
    echo '送信する';
    echo '</button>';

    echo '</div>';

    echo '</form>';
    echo '</div>';
}

function render_complete(?array $survey): void
{
    echo '<div class="card">';
    echo '<h1>回答完了</h1>';
    echo '<p>回答を受け付けました。</p>';
    echo '</div>';
}

function render_question(array $question): void
{
    echo '<div class="card">';

    echo '<h3>' .
        h($question['number'] ?? '') .
        ' ' .
        h($question['text'] ?? '') .
        '</h3>';

    $type = (string)($question['type'] ?? '');

    if ($type === 'single') {
        foreach (($question['options'] ?? []) as $index => $option) {
            echo '<label class="checkbox">';
            echo '<input type="radio" name="responses[' .
                h($question['id'] ?? $index) .
                ']" value="' .
                h($option['value'] ?? $option['label'] ?? '') .
                '">';
            echo h($option['label'] ?? '');
            echo '</label>';
        }
    } elseif ($type === 'multiple') {
        foreach (($question['options'] ?? []) as $index => $option) {
            echo '<label class="checkbox">';
            echo '<input type="checkbox" name="responses[' .
                h($question['id'] ?? $index) .
                '][]" value="' .
                h($option['value'] ?? $option['label'] ?? '') .
                '">';
            echo h($option['label'] ?? '');
            echo '</label>';
        }
    } else {
        echo '<textarea name="responses[' .
            h($question['id'] ?? '') .
            ']"></textarea>';
    }

    echo '</div>';
}


/* =========================================================
 * UI helpers
 * ======================================================= */

function form_row(string $label, string $html): void
{
    echo '<label>' . h($label) . '</label>';
    echo '<div>' . $html . '</div>';
}

function option(
    string $value,
    string $label,
    string $selected
): string {
    return '<option value="' .
        h($value) .
        '"' .
        ($value === $selected ? ' selected' : '') .
        '>' .
        h($label) .
        '</option>';
}

function render_action_form(
    string $action,
    string $label,
    string $class
): void {
    echo '<form method="post" style="display:inline">';

    /*
     * CSRF hidden fieldは存在しない。
     */
    echo '<input type="hidden" name="action" value="' .
        h($action) .
        '">';

    echo '<button class="' .
        h($class) .
        '" type="submit">' .
        h($label) .
        '</button>';

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

    $html .= '<option value="">未設定</option>';

    foreach ($fields as $code => $field) {
        $code = (string)$code;

        $html .= '<option value="' .
            h($code) .
            '"' .
            ($code === $selected
                ? ' selected'
                : '') .
            '>' .
            h(
                ($field['label'] ?? $code) .
                ' [' . $code . ']'
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
    $html = '';

    foreach ($fields as $code => $field) {
        $code = (string)$code;

        $checked =
            in_array(
                $code,
                array_map('strval', $selected),
                true
            )
            ? ' checked'
            : '';

        $html .=
            '<label class="checkbox">';

        $html .=
            '<input type="checkbox" '
            . 'name="field_mapping[address][]" '
            . 'value="' . h($code) . '"' .
            $checked .
            '>';

        $html .=
            h(
                ($field['label'] ?? $code) .
                ' [' . $code . ']'
            );

        $html .= '</label>';
    }

    if ($html === '') {
        $html =
            '<span class="small">'
            . '項目一覧を取得してください。'
            . '</span>';
    }

    return $html;
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
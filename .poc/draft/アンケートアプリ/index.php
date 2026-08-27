<?php
declare(strict_types=1);

/*
 * アンケートアプリ POC
 * PHP 8.5 / Apache 2.4
 * DBなし / PHP cURLなし / Canvasなし
 *
 * 全画面をこのファイルで処理する。
 */

date_default_timezone_set('Asia/Tokyo');

const APP_NAME = 'アンケートアプリ';
const DATA_DIR = __DIR__ . '/data';
const SURVEY_FILE = DATA_DIR . '/surveys.json';
const ANSWER_FILE = DATA_DIR . '/answers.json';
const CUSTOMER_FILE = DATA_DIR . '/customers.json';
const SEND_LOG_FILE = DATA_DIR . '/send_logs.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0770, true);
}

/*
 * Webからdata配下へ直接アクセスできないようApache用設定を生成。
 * Nginx等の場合はサーバー側でdataを拒否してください。
 */
$htaccess = DATA_DIR . '/.htaccess';
if (!file_exists($htaccess)) {
    @file_put_contents(
        $htaccess,
        "Deny from all\n"
    );
}

/* ---------- 初期データ ---------- */

function ensure_file(string $file, mixed $default): void
{
    if (!file_exists($file)) {
        atomic_write($file, $default);
    }
}

function atomic_write(string $file, mixed $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('データのJSON化に失敗しました。');
    }

    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('一時ファイルへの保存に失敗しました。');
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データ保存に失敗しました。');
    }
}

function read_json(string $file, mixed $default = []): mixed
{
    if (!file_exists($file)) {
        return $default;
    }

    $fp = fopen($file, 'rb');
    if (!$fp) {
        throw new RuntimeException('データファイルを開けません。');
    }

    flock($fp, LOCK_SH);
    $contents = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $data = json_decode($contents, true);

    return json_last_error() === JSON_ERROR_NONE ? $data : $default;
}

ensure_file(SURVEY_FILE, []);
ensure_file(ANSWER_FILE, []);
ensure_file(CUSTOMER_FILE, []);
ensure_file(SEND_LOG_FILE, []);
ensure_file(SETTINGS_FILE, [
    'kintone' => [
        'subdomain' => '',
        'app_id' => '',
        'login' => '',
        'password' => '',
        'proxy' => '',
        'verify_ssl' => false,
        'fields' => [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => [],
        ],
        'status' => '未設定',
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

/* ---------- 共通 ---------- */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uuid(string $prefix): string
{
    return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';

    if (
        !is_string($token) ||
        empty($_SESSION['_csrf']) ||
        !hash_equals($_SESSION['_csrf'], $token)
    ) {
        throw new RuntimeException(
            'セッションまたはCSRFトークンが無効です。ページを再読み込みしてください。'
        );
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

function load_surveys(): array
{
    return read_json(SURVEY_FILE, []);
}

function save_surveys(array $surveys): void
{
    atomic_write(SURVEY_FILE, array_values($surveys));
}

function load_answers(): array
{
    return read_json(ANSWER_FILE, []);
}

function save_answers(array $answers): void
{
    atomic_write(ANSWER_FILE, array_values($answers));
}

function load_customers(): array
{
    return read_json(CUSTOMER_FILE, []);
}

function save_customers(array $customers): void
{
    atomic_write(CUSTOMER_FILE, array_values($customers));
}

function load_send_logs(): array
{
    return read_json(SEND_LOG_FILE, []);
}

function save_send_logs(array $logs): void
{
    atomic_write(SEND_LOG_FILE, array_values($logs));
}

function load_settings(): array
{
    return read_json(SETTINGS_FILE, []);
}

function save_settings(array $settings): void
{
    atomic_write(SETTINGS_FILE, $settings);
}

function survey_by_id(string $id): ?array
{
    foreach (load_surveys() as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_status(array &$survey): string
{
    if (
        ($survey['status'] ?? '') === 'published' &&
        !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now();
            return 'ended';
        }
    }

    return (string)($survey['status'] ?? 'draft');
}

function refresh_survey(array $survey): array
{
    $surveys = load_surveys();
    $changed = false;

    foreach ($surveys as &$item) {
        if (($item['id'] ?? '') === ($survey['id'] ?? '')) {
            $before = $item['status'] ?? '';
            $status = survey_status($item);

            if ($before !== $status) {
                $changed = true;
            }

            $survey = $item;
            break;
        }
    }
    unset($item);

    if ($changed) {
        save_surveys($surveys);
    }

    return $survey;
}

function status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'draft',
    };
}

function validate_id(string $id): bool
{
    return preg_match('/^[a-zA-Z0-9_-]+$/', $id) === 1;
}

/* ---------- セッション ---------- */

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'httponly' => true,
    'secure' => $secure,
    'samesite' => 'Lax',
    'path' => '/',
]);

session_start();

$screen = $_GET['screen'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$publicScreens = ['answer', 'confirm', 'complete'];

if (!in_array($screen, [
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
], true)) {
    $screen = 'list';
}

/* ---------- CSRFを必要とするPOST処理 ---------- */

if ($method === 'POST') {
    try {
        verify_csrf();

        $action = $_POST['action'] ?? '';

        switch ($action) {

            /* ===== アンケート保存 ===== */

            case 'save_survey':
                $id = trim((string)($_POST['id'] ?? ''));

                if ($id !== '' && !validate_id($id)) {
                    throw new InvalidArgumentException('アンケートIDが不正です。');
                }

                $title = trim((string)($_POST['title'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $startAt = trim((string)($_POST['startAt'] ?? ''));
                $endAt = trim((string)($_POST['endAt'] ?? ''));
                $numbering = $_POST['numbering'] ?? 'global';
                $status = $_POST['status'] ?? 'draft';

                if ($title === '') {
                    throw new InvalidArgumentException('アンケートタイトルは必須です。');
                }

                if (mb_strlen($title) > 200) {
                    throw new InvalidArgumentException(
                        'アンケートタイトルは200文字以内で入力してください。'
                    );
                }

                if (!in_array($numbering, ['global', 'group'], true)) {
                    $numbering = 'global';
                }

                if ($startAt !== '' && strtotime($startAt) === false) {
                    throw new InvalidArgumentException('開始日時が不正です。');
                }

                if ($endAt !== '' && strtotime($endAt) === false) {
                    throw new InvalidArgumentException('終了日時が不正です。');
                }

                if (
                    $startAt !== '' &&
                    $endAt !== '' &&
                    strtotime($startAt) > strtotime($endAt)
                ) {
                    throw new InvalidArgumentException(
                        '終了日時は開始日時以降にしてください。'
                    );
                }

                $surveys = load_surveys();
                $found = false;

                foreach ($surveys as &$survey) {
                    if (($survey['id'] ?? '') !== $id) {
                        continue;
                    }

                    $found = true;

                    if (($survey['status'] ?? '') === 'ended') {
                        $status = 'ended';
                    } elseif (!in_array(
                        $status,
                        ['draft', 'published', 'stopped'],
                        true
                    )) {
                        $status = $survey['status'] ?? 'draft';
                    }

                    $survey['title'] = $title;
                    $survey['description'] = $description;
                    $survey['startAt'] = $startAt;
                    $survey['endAt'] = $endAt;
                    $survey['numbering'] = $numbering;
                    $survey['status'] = $status;
                    $survey['updatedAt'] = now();

                    break;
                }
                unset($survey);

                if (!$found) {
                    $id = uuid('survey');

                    $surveys[] = [
                        'id' => $id,
                        'title' => $title,
                        'description' => $description,
                        'startAt' => $startAt,
                        'endAt' => $endAt,
                        'numbering' => $numbering,
                        'status' => 'draft',
                        'createdAt' => now(),
                        'updatedAt' => now(),
                        'groups' => [
                            [
                                'id' => uuid('group'),
                                'title' => '基本情報',
                                'questions' => [],
                            ],
                        ],
                    ];
                }

                save_surveys($surveys);

                flash('success', 'アンケートを保存しました。');
                redirect('index.php?screen=list');
                break;

            /* ===== 質問構造保存 ===== */

            case 'save_structure':
                $id = trim((string)($_POST['id'] ?? ''));
                $survey = survey_by_id($id);

                if (!$survey) {
                    throw new RuntimeException('アンケートが存在しません。');
                }

                $structure = json_decode(
                    (string)($_POST['structure'] ?? ''),
                    true
                );

                if (!is_array($structure)) {
                    throw new InvalidArgumentException(
                        '質問構造が不正です。'
                    );
                }

                $groups = [];

                foreach ($structure as $group) {
                    if (!is_array($group)) {
                        continue;
                    }

                    $groupId = (string)($group['id'] ?? uuid('group'));
                    $groupTitle = trim(
                        (string)($group['title'] ?? 'グループ')
                    );

                    $questions = [];

                    foreach (($group['questions'] ?? []) as $question) {
                        if (!is_array($question)) {
                            continue;
                        }

                        $questionId = (string)(
                            $question['id'] ?? uuid('question')
                        );

                        $type = $question['type'] ?? 'single';

                        if (!in_array(
                            $type,
                            ['single', 'multiple', 'text'],
                            true
                        )) {
                            $type = 'single';
                        }

                        $options = [];

                        foreach (($question['options'] ?? []) as $option) {
                            if (!is_array($option)) {
                                continue;
                            }

                            $options[] = [
                                'id' => (string)(
                                    $option['id'] ?? uuid('option')
                                ),
                                'label' => trim(
                                    (string)($option['label'] ?? '')
                                ),
                                'nextQuestionId' => (
                                    !empty($option['nextQuestionId'])
                                        ? (string)$option['nextQuestionId']
                                        : null
                                ),
                            ];
                        }

                        $questions[] = [
                            'id' => $questionId,
                            'number' => '',
                            'text' => trim(
                                (string)($question['text'] ?? '')
                            ),
                            'type' => $type,
                            'required' => !empty($question['required']),
                            'options' => $options,
                        ];
                    }

                    $groups[] = [
                        'id' => $groupId,
                        'title' => $groupTitle !== ''
                            ? $groupTitle
                            : 'グループ',
                        'questions' => $questions,
                    ];
                }

                $survey['groups'] = $groups;

                renumber_questions($survey);

                $surveys = load_surveys();

                foreach ($surveys as &$item) {
                    if (($item['id'] ?? '') === $id) {
                        $item = $survey;
                        $item['updatedAt'] = now();
                        break;
                    }
                }
                unset($item);

                save_surveys($surveys);

                flash('success', '質問構成を保存しました。');
                redirect(
                    'index.php?screen=edit&id=' . rawurlencode($id)
                );
                break;

            /* ===== 状態変更 ===== */

            case 'change_status':
                $id = trim((string)($_POST['id'] ?? ''));
                $newStatus = $_POST['new_status'] ?? '';

                $allowed = [
                    'draft' => ['published'],
                    'published' => ['stopped'],
                    'stopped' => ['published'],
                ];

                $surveys = load_surveys();

                foreach ($surveys as &$survey) {
                    if (($survey['id'] ?? '') !== $id) {
                        continue;
                    }

                    survey_status($survey);

                    $current = $survey['status'] ?? 'draft';

                    if (
                        !isset($allowed[$current]) ||
                        !in_array($newStatus, $allowed[$current], true)
                    ) {
                        throw new InvalidArgumentException(
                            '指定された状態変更は許可されていません。'
                        );
                    }

                    $survey['status'] = $newStatus;
                    $survey['updatedAt'] = now();

                    break;
                }
                unset($survey);

                save_surveys($surveys);

                flash('success', '状態を変更しました。');
                redirect('index.php?screen=list');
                break;

            /* ===== 削除 ===== */

            case 'delete_survey':
                $id = trim((string)($_POST['id'] ?? ''));

                $surveys = load_surveys();

                $before = count($surveys);

                $surveys = array_values(array_filter(
                    $surveys,
                    fn(array $survey): bool =>
                        ($survey['id'] ?? '') !== $id
                ));

                if ($before === count($surveys)) {
                    throw new RuntimeException(
                        '削除対象が存在しません。'
                    );
                }

                save_surveys($surveys);

                flash('success', 'アンケートを削除しました。');
                redirect('index.php?screen=list');
                break;

            /* ===== 複製 ===== */

            case 'duplicate_survey':
                $id = trim((string)($_POST['id'] ?? ''));
                $source = survey_by_id($id);

                if (!$source) {
                    throw new RuntimeException('複製元が存在しません。');
                }

                $source['id'] = uuid('survey');
                $source['title'] .= '（コピー）';
                $source['status'] = 'draft';
                $source['createdAt'] = now();
                $source['updatedAt'] = now();

                foreach ($source['groups'] as &$group) {
                    $group['id'] = uuid('group');

                    foreach ($group['questions'] as &$question) {
                        $question['id'] = uuid('question');

                        foreach ($question['options'] as &$option) {
                            $option['id'] = uuid('option');
                            $option['nextQuestionId'] = null;
                        }
                        unset($option);
                    }
                    unset($question);
                }
                unset($group);

                renumber_questions($source);

                $surveys = load_surveys();
                $surveys[] = $source;
                save_surveys($surveys);

                flash('success', 'アンケートを複製しました。');
                redirect('index.php?screen=list');
                break;

            /* ===== 回答保存 ===== */

            case 'answer_confirm':
                $id = trim((string)($_POST['id'] ?? ''));
                $survey = survey_by_id($id);

                if (!$survey) {
                    throw new RuntimeException(
                        'アンケートが存在しません。'
                    );
                }

                $survey = refresh_survey($survey);

                if (($survey['status'] ?? '') !== 'published') {
                    throw new RuntimeException(
                        '現在回答を受け付けていません。'
                    );
                }

                $answers = $_POST['answers'] ?? [];

                if (!is_array($answers)) {
                    $answers = [];
                }

                $errors = validate_answers($survey, $answers);

                if ($errors) {
                    $_SESSION['answer_errors'] = $errors;
                    $_SESSION['answer_draft'] = $answers;

                    redirect(
                        'index.php?screen=answer&id=' .
                        rawurlencode($id)
                    );
                }

                $_SESSION['answer_draft'] = $answers;

                redirect(
                    'index.php?screen=confirm&id=' .
                    rawurlencode($id)
                );
                break;

            /* ===== 回答送信 ===== */

            case 'submit_answer':
                $id = trim((string)($_POST['id'] ?? ''));
                $survey = survey_by_id($id);

                if (!$survey) {
                    throw new RuntimeException(
                        'アンケートが存在しません。'
                    );
                }

                $answers = $_SESSION['answer_draft'] ?? [];

                if (!is_array($answers)) {
                    throw new RuntimeException(
                        '回答セッションが失われました。'
                    );
                }

                $errors = validate_answers($survey, $answers);

                if ($errors) {
                    throw new RuntimeException(
                        '回答内容が変更されています。'
                    );
                }

                $allAnswers = load_answers();

                $allAnswers[] = [
                    'id' => uuid('answer'),
                    'surveyId' => $id,
                    'customerId' => null,
                    'answers' => $answers,
                    'createdAt' => now(),
                ];

                save_answers($allAnswers);

                unset(
                    $_SESSION['answer_draft'],
                    $_SESSION['answer_errors']
                );

                redirect(
                    'index.php?screen=complete&id=' .
                    rawurlencode($id)
                );
                break;

            /* ===== kintone設定 ===== */

            case 'save_kintone':
                $settings = load_settings();

                $settings['kintone']['subdomain'] =
                    normalize_kintone_subdomain(
                        (string)($_POST['subdomain'] ?? '')
                    );

                $settings['kintone']['app_id'] =
                    trim((string)($_POST['app_id'] ?? ''));

                $settings['kintone']['login'] =
                    trim((string)($_POST['login'] ?? ''));

                $password = (string)($_POST['password'] ?? '');

                if ($password !== '') {
                    $settings['kintone']['password'] = $password;
                }

                $settings['kintone']['proxy'] =
                    trim((string)($_POST['proxy'] ?? ''));

                $settings['kintone']['verify_ssl'] =
                    !empty($_POST['verify_ssl']);

                $settings['kintone']['fields'] = [
                    'organization' =>
                        trim((string)($_POST['field_organization'] ?? '')),
                    'name' =>
                        trim((string)($_POST['field_name'] ?? '')),
                    'email' =>
                        trim((string)($_POST['field_email'] ?? '')),
                    'department' =>
                        trim((string)($_POST['field_department'] ?? '')),
                    'phone' =>
                        trim((string)($_POST['field_phone'] ?? '')),
                    'address' =>
                        array_values(
                            array_filter(
                                $_POST['field_address'] ?? []
                            )
                        ),
                ];

                save_settings($settings);

                flash('success', 'kintone設定を保存しました。');
                redirect('index.php?screen=kintone');
                break;

            /* ===== kintone接続テスト ===== */

            case 'test_kintone':
                $settings = load_settings();

                $result = kintone_request(
                    $settings,
                    '/k/v1/app.json',
                    'GET',
                    ['app' => (int)$settings['kintone']['app_id']]
                );

                if (!$result['ok']) {
                    throw new RuntimeException(
                        'kintone接続失敗: ' . $result['message']
                    );
                }

                $settings['kintone']['status'] = '接続確認済み';
                save_settings($settings);

                flash('success', 'kintoneへの接続に成功しました。');
                redirect('index.php?screen=kintone');
                break;

            /* ===== kintone項目取得 ===== */

            case 'fetch_kintone_fields':
                $settings = load_settings();

                $result = kintone_request(
                    $settings,
                    '/k/v1/app/form/fields.json',
                    'GET',
                    ['app' => (int)$settings['kintone']['app_id']]
                );

                if (!$result['ok']) {
                    throw new RuntimeException(
                        '項目取得失敗: ' . $result['message']
                    );
                }

                $_SESSION['kintone_fields'] =
                    $result['data']['properties'] ?? [];

                flash('success', 'kintoneの項目一覧を取得しました。');
                redirect('index.php?screen=kintone');
                break;

            /* ===== kintone顧客同期 ===== */

            case 'sync_kintone':
                $settings = load_settings();

                $query = [
                    'app' => (int)$settings['kintone']['app_id'],
                    'query' => '',
                    'totalCount' => true,
                ];

                $result = kintone_request(
                    $settings,
                    '/k/v1/records.json',
                    'GET',
                    $query
                );

                if (!$result['ok']) {
                    throw new RuntimeException(
                        '顧客同期失敗: ' . $result['message']
                    );
                }

                $customers = [];
                $fields = $settings['kintone']['fields'];

                foreach (($result['data']['records'] ?? []) as $record) {
                    $customers[] = [
                        'id' => uuid('customer'),
                        'kintoneId' => $record['$id']['value'] ?? '',
                        'organization' =>
                            kintone_value(
                                $record,
                                $fields['organization']
                            ),
                        'name' =>
                            kintone_value(
                                $record,
                                $fields['name']
                            ),
                        'email' =>
                            kintone_value(
                                $record,
                                $fields['email']
                            ),
                        'department' =>
                            kintone_value(
                                $record,
                                $fields['department']
                            ),
                        'phone' =>
                            kintone_value(
                                $record,
                                $fields['phone']
                            ),
                        'address' =>
                            kintone_address(
                                $record,
                                $fields['address']
                            ),
                        'updatedAt' => now(),
                    ];
                }

                save_customers($customers);

                flash(
                    'success',
                    count($customers) . '件の顧客情報を同期しました。'
                );

                redirect('index.php?screen=kintone');
                break;

            /* ===== メール設定 ===== */

            case 'save_mail':
                $settings = load_settings();

                $host = trim((string)($_POST['host'] ?? ''));
                $port = (int)($_POST['port'] ?? 587);

                if ($host === '') {
                    throw new InvalidArgumentException(
                        'SMTPサーバは必須です。'
                    );
                }

                if ($port < 1 || $port > 65535) {
                    throw new InvalidArgumentException(
                        'SMTPポートが不正です。'
                    );
                }

                $settings['mail']['host'] = $host;
                $settings['mail']['port'] = $port;
                $settings['mail']['encryption'] =
                    $_POST['encryption'] ?? 'tls';
                $settings['mail']['auth'] =
                    !empty($_POST['auth']);
                $settings['mail']['username'] =
                    trim((string)($_POST['username'] ?? ''));

                $password = (string)($_POST['password'] ?? '');

                if ($password !== '') {
                    $settings['mail']['password'] = $password;
                }

                $settings['mail']['from_email'] =
                    trim((string)($_POST['from_email'] ?? ''));

                $settings['mail']['from_name'] =
                    trim((string)($_POST['from_name'] ?? ''));

                $settings['mail']['reply_to'] =
                    trim((string)($_POST['reply_to'] ?? ''));

                if (
                    !filter_var(
                        $settings['mail']['from_email'],
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new InvalidArgumentException(
                        '送信元メールアドレスが不正です。'
                    );
                }

                save_settings($settings);

                flash('success', 'メール設定を保存しました。');
                redirect('index.php?screen=mail');
                break;

            /* ===== SMTP接続テスト ===== */

            case 'test_mail':
                $settings = load_settings();

                smtp_connect_test($settings['mail']);

                $settings['mail']['status'] = '接続確認済み';
                save_settings($settings);

                flash('success', 'SMTP接続に成功しました。');
                redirect('index.php?screen=mail');
                break;

            /* ===== テストメール ===== */

            case 'send_test_mail':
                $settings = load_settings();

                $to = trim((string)($_POST['test_to'] ?? ''));

                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException(
                        'テスト送信先メールアドレスが不正です。'
                    );
                }

                smtp_send(
                    $settings['mail'],
                    $to,
                    'アンケートアプリ テストメール',
                    "アンケートアプリからのテストメールです。\r\n"
                );

                flash('success', 'テストメールを送信しました。');
                redirect('index.php?screen=mail');
                break;

            /* ===== アンケートメール送信 ===== */

            case 'send_bulk_mail':
                $id = trim((string)($_POST['id'] ?? ''));
                $survey = survey_by_id($id);

                if (!$survey) {
                    throw new RuntimeException(
                        'アンケートが存在しません。'
                    );
                }

                $settings = load_settings();
                $customers = load_customers();

                $selected = $_POST['customers'] ?? [];

                if (!is_array($selected) || !$selected) {
                    throw new InvalidArgumentException(
                        '送信対象を選択してください。'
                    );
                }

                $subject = trim((string)($_POST['subject'] ?? ''));
                $body = (string)($_POST['body'] ?? '');

                if ($subject === '' || $body === '') {
                    throw new InvalidArgumentException(
                        '件名と本文は必須です。'
                    );
                }

                $logs = load_send_logs();
                $success = 0;
                $failed = 0;

                foreach ($customers as $customer) {
                    if (
                        !in_array(
                            (string)$customer['id'],
                            array_map('strval', $selected),
                            true
                        )
                    ) {
                        continue;
                    }

                    $email = $customer['email'] ?? '';

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $failed++;

                        $logs[] = [
                            'id' => uuid('send'),
                            'surveyId' => $id,
                            'customerId' => $customer['id'],
                            'email' => $email,
                            'status' => 'failed',
                            'message' => 'メールアドレス不正',
                            'sentAt' => now(),
                        ];

                        continue;
                    }

                    $url =
                        absolute_app_url() .
                        '?screen=answer&id=' .
                        rawurlencode($id);

                    $mailSubject = replace_mail_vars(
                        $subject,
                        $customer,
                        $url
                    );

                    $mailBody = replace_mail_vars(
                        $body,
                        $customer,
                        $url
                    );

                    try {
                        smtp_send(
                            $settings['mail'],
                            $email,
                            $mailSubject,
                            $mailBody
                        );

                        $success++;

                        $logs[] = [
                            'id' => uuid('send'),
                            'surveyId' => $id,
                            'customerId' => $customer['id'],
                            'email' => $email,
                            'status' => 'sent',
                            'message' => '',
                            'sentAt' => now(),
                        ];
                    } catch (Throwable $e) {
                        $failed++;

                        $logs[] = [
                            'id' => uuid('send'),
                            'surveyId' => $id,
                            'customerId' => $customer['id'],
                            'email' => $email,
                            'status' => 'failed',
                            'message' => safe_error_message($e),
                            'sentAt' => now(),
                        ];
                    }
                }

                save_send_logs($logs);

                flash(
                    $failed > 0 ? 'warning' : 'success',
                    "送信完了: 成功 {$success}件 / 失敗 {$failed}件"
                );

                redirect(
                    'index.php?screen=send&id=' .
                    rawurlencode($id)
                );
                break;

            /* ===== CSV ===== */

            case 'export_csv':
                $id = trim((string)($_POST['id'] ?? ''));
                export_csv($id);
                break;

            /* ===== PDF ===== */

            case 'export_pdf':
                $id = trim((string)($_POST['id'] ?? ''));
                export_pdf($id);
                break;

            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }

    } catch (Throwable $e) {
        $_SESSION['_error'] = safe_error_message($e);

        $back = $_SERVER['HTTP_REFERER'] ?? 'index.php?screen=list';

        /*
         * 任意の外部URLへリダイレクトしない。
         * 同一ホストのindex.php系のみ許可。
         */
        $parsed = parse_url($back);

        if (
            isset($parsed['host']) &&
            $parsed['host'] !== ($_SERVER['HTTP_HOST'] ?? '')
        ) {
            $back = 'index.php?screen=list';
        }

        redirect($back);
    }
}

/* ---------- 自動終了判定 ---------- */

if ($screen === 'edit' || $screen === 'preview' ||
    $screen === 'send' || $screen === 'analytics') {

    $id = (string)($_GET['id'] ?? '');

    if ($id === '' || !validate_id($id)) {
        redirect('index.php?screen=list');
    }

    $survey = survey_by_id($id);

    if (!$survey) {
        redirect('index.php?screen=list');
    }

    $survey = refresh_survey($survey);

    if (
        in_array($screen, ['send', 'analytics'], true) &&
        !$survey
    ) {
        redirect('index.php?screen=list');
    }
}

/* ---------- 表示用 ---------- */

$flashMessages = consume_flash();

if (!empty($_SESSION['_error'])) {
    $flashMessages[] = [
        'type' => 'error',
        'message' => $_SESSION['_error'],
    ];

    unset($_SESSION['_error']);
}

function render_header(
    string $title,
    bool $admin = true
): void {
    global $flashMessages;

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_NAME) ?></title>

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

*{box-sizing:border-box}

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

a{
    color:var(--primary);
    text-decoration:none;
}

button,
input,
select,
textarea{
    font:inherit;
}

.admin-header{
    background:#0f172a;
    color:white;
    padding:14px 24px;
}

.admin-header-inner{
    max-width:1400px;
    margin:auto;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
}

.brand{
    color:white;
    font-weight:700;
    font-size:18px;
}

.admin-nav{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.admin-nav a{
    color:#cbd5e1;
    padding:8px 10px;
    border-radius:6px;
}

.admin-nav a:hover{
    background:#1e293b;
    color:white;
}

.container{
    max-width:1400px;
    margin:0 auto;
    padding:28px 20px;
}

.answer-container{
    max-width:760px;
    margin:auto;
    padding:24px 16px 80px;
}

h1{
    margin:0 0 22px;
    font-size:28px;
}

h2{
    font-size:20px;
    margin-top:0;
}

h3{
    font-size:16px;
}

.card{
    background:white;
    border:1px solid var(--border);
    border-radius:10px;
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:20px;
}

.toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid var(--border);
    background:white;
    color:var(--text);
    padding:9px 14px;
    border-radius:7px;
    cursor:pointer;
    min-height:40px;
}

.btn:hover{
    background:#f8fafc;
}

.btn-primary{
    background:var(--primary);
    border-color:var(--primary);
    color:white;
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-danger{
    background:var(--danger);
    border-color:var(--danger);
    color:white;
}

.btn-success{
    background:var(--success);
    border-color:var(--success);
    color:white;
}

.btn-warning{
    background:var(--warning);
    border-color:var(--warning);
    color:white;
}

.btn-sm{
    min-height:32px;
    padding:6px 9px;
    font-size:13px;
}

.grid{
    display:grid;
    gap:16px;
}

.grid-2{
    grid-template-columns:repeat(2,minmax(0,1fr));
}

.grid-3{
    grid-template-columns:repeat(3,minmax(0,1fr));
}

label{
    display:block;
    font-weight:600;
    margin-bottom:6px;
}

.field{
    margin-bottom:16px;
}

input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
select,
textarea{
    width:100%;
    border:1px solid var(--border);
    border-radius:7px;
    padding:10px 11px;
    background:white;
}

textarea{
    min-height:120px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus{
    outline:2px solid rgba(37,99,235,.15);
    border-color:var(--primary);
}

.help{
    color:var(--gray);
    font-size:13px;
    margin-top:5px;
}

.alert{
    padding:13px 15px;
    border-radius:8px;
    margin-bottom:16px;
    border:1px solid;
}

.alert-success{
    background:#f0fdf4;
    border-color:#bbf7d0;
    color:#166534;
}

.alert-warning{
    background:#fffbeb;
    border-color:#fde68a;
    color:#92400e;
}

.alert-error{
    background:#fef2f2;
    border-color:#fecaca;
    color:#991b1b;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:1000px;
}

th,
td{
    padding:11px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    font-size:13px;
    white-space:nowrap;
}

.badge{
    display:inline-flex;
    padding:4px 8px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge-success{
    background:#dcfce7;
    color:#166534;
}

.badge-warning{
    background:#fef3c7;
    color:#92400e;
}

.badge-gray{
    background:#e2e8f0;
    color:#475569;
}

.badge-draft{
    background:#dbeafe;
    color:#1e40af;
}

.stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
    margin-bottom:20px;
}

.stat{
    background:white;
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
}

.stat-label{
    color:var(--gray);
    font-size:13px;
}

.stat-value{
    font-size:28px;
    font-weight:700;
    margin-top:5px;
}

.group{
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
    margin-bottom:16px;
}

.group-header{
    display:flex;
    align-items:center;
    gap:8px;
    padding:13px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
}

.drag-handle{
    cursor:grab;
    color:var(--gray);
    user-select:none;
}

.group-title{
    flex:1;
    font-weight:700;
}

.question-list{
    padding:10px;
    min-height:30px;
}

.question{
    border:1px solid var(--border);
    border-radius:8px;
    padding:14px;
    margin-bottom:10px;
    background:white;
}

.question:last-child{
    margin-bottom:0;
}

.question-head{
    display:flex;
    align-items:center;
    gap:8px;
}

.question-body{
    padding-top:12px;
}

.option-row{
    display:grid;
    grid-template-columns:1fr 220px auto;
    gap:8px;
    margin-bottom:8px;
}

.drag-over{
    outline:2px dashed var(--primary);
    outline-offset:-2px;
}

.preview-question{
    padding:18px 0;
    border-bottom:1px solid var(--border);
}

.required{
    color:var(--danger);
}

.answer-choice{
    display:block;
    padding:12px;
    margin:8px 0;
    border:1px solid var(--border);
    border-radius:8px;
    cursor:pointer;
    background:white;
}

.answer-choice:hover{
    background:#f8fafc;
}

.answer-choice input{
    margin-right:8px;
}

.answer-actions{
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:24px;
}

.mobile-card{
    display:none;
}

@media(max-width:900px){
    .grid-2,
    .grid-3,
    .stats{
        grid-template-columns:1fr 1fr;
    }

    .admin-header-inner{
        align-items:flex-start;
        flex-direction:column;
    }
}

@media(max-width:640px){
    .container{
        padding:18px 12px;
    }

    .grid-2,
    .grid-3,
    .stats{
        grid-template-columns:1fr;
    }

    h1{
        font-size:23px;
    }

    .card{
        padding:15px;
    }

    .option-row{
        grid-template-columns:1fr;
    }

    .answer-actions{
        position:sticky;
        bottom:0;
        background:#f8fafc;
        padding:10px 0;
    }

    .answer-actions .btn{
        flex:1;
    }
}
</style>
</head>

<body>

<?php if ($admin): ?>

<header class="admin-header">
<div class="admin-header-inner">
    <a class="brand" href="index.php?screen=list">
        <?= h(APP_NAME) ?>
    </a>

    <nav class="admin-nav">
        <a href="index.php?screen=list">アンケート</a>
        <a href="index.php?screen=kintone">kintone</a>
        <a href="index.php?screen=mail">メール設定</a>
    </nav>
</div>
</header>

<?php endif; ?>

<main class="<?= $admin ? 'container' : 'answer-container' ?>">

<?php foreach ($flashMessages as $message): ?>
<div class="alert alert-<?= h($message['type']) ?>">
    <?= h($message['message']) ?>
</div>
<?php endforeach; ?>

<?php
}

function render_footer(): void
{
?>
</main>

<script>
function confirmAction(message) {
    return window.confirm(message);
}

function submitConfirm(form, message) {
    if (window.confirm(message)) {
        form.submit();
    }
}

document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (!window.confirm(el.dataset.confirm)) {
            e.preventDefault();
        }
    });
});
</script>

</body>
</html>
<?php
}

/* ---------- 質問番号 ---------- */

function renumber_questions(array &$survey): void
{
    $numbering = $survey['numbering'] ?? 'global';

    if ($numbering === 'group') {
        foreach ($survey['groups'] as $gi => &$group) {
            foreach ($group['questions'] as $qi => &$question) {
                $question['number'] =
                    'Q' . ($gi + 1) . '-' . ($qi + 1);
            }
            unset($question);
        }
        unset($group);
        return;
    }

    $n = 1;

    foreach ($survey['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            $question['number'] = 'Q' . $n++;
        }
        unset($question);
    }
    unset($group);
}

/* ---------- 回答関連 ---------- */

function all_questions(array $survey): array
{
    $result = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

function question_by_id(array $survey, string $id): ?array
{
    foreach (all_questions($survey) as $question) {
        if (($question['id'] ?? '') === $id) {
            return $question;
        }
    }

    return null;
}

function validate_answers(array $survey, array $answers): array
{
    $errors = [];

    foreach (all_questions($survey) as $question) {
        $qid = $question['id'];

        if (!is_question_visible($survey, $qid, $answers)) {
            continue;
        }

        if (empty($question['required'])) {
            continue;
        }

        $value = $answers[$qid] ?? null;

        $empty = false;

        if (is_array($value)) {
            $empty = count(array_filter(
                $value,
                fn($v) => trim((string)$v) !== ''
            )) === 0;
        } else {
            $empty = trim((string)$value) === '';
        }

        if ($empty) {
            $errors[$qid] =
                'この質問は必須です。';
        }
    }

    return $errors;
}

function is_question_visible(
    array $survey,
    string $questionId,
    array $answers
): bool {
    $previous = [];

    foreach (all_questions($survey) as $question) {
        if (($question['id'] ?? '') === $questionId) {
            break;
        }

        $previous[] = $question;
    }

    /*
     * 条件分岐は、直前までの単一選択回答を確認する。
     * 指定された次質問がある場合、その対象へ遷移する。
     */
    foreach ($previous as $question) {
        if (($question['type'] ?? '') !== 'single') {
            continue;
        }

        $value = $answers[$question['id']] ?? null;

        if (!is_string($value) || $value === '') {
            continue;
        }

        foreach ($question['options'] ?? [] as $option) {
            if (($option['id'] ?? '') !== $value) {
                continue;
            }

            $next = $option['nextQuestionId'] ?? null;

            if ($next && $next !== $questionId) {
                /*
                 * 次質問指定先と現在質問の位置関係を調べる。
                 * 現在質問が指定先より後ろなら非表示。
                 */
                $ids = array_map(
                    fn($q) => $q['id'],
                    all_questions($survey)
                );

                $nextPos = array_search($next, $ids, true);
                $currentPos = array_search(
                    $questionId,
                    $ids,
                    true
                );

                if (
                    $nextPos !== false &&
                    $currentPos !== false &&
                    $currentPos > $nextPos
                ) {
                    return false;
                }
            }
        }
    }

    return true;
}

/* ---------- kintone ---------- */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = rtrim($value, '/');

    if (str_ends_with($value, '.cybozu.com')) {
        return $value;
    }

    if ($value !== '') {
        return $value . '.cybozu.com';
    }

    return '';
}

function kintone_request(
    array $settings,
    string $path,
    string $method,
    array $params = []
): array {
    $k = $settings['kintone'] ?? [];

    $domain = normalize_kintone_subdomain(
        (string)($k['subdomain'] ?? '')
    );

    $app = (int)($k['app_id'] ?? 0);
    $login = (string)($k['login'] ?? '');
    $password = (string)($k['password'] ?? '');

    if (
        $domain === '' ||
        $app <= 0 ||
        $login === '' ||
        $password === ''
    ) {
        return [
            'ok' => false,
            'message' => 'kintone設定が不足しています。',
            'data' => null,
        ];
    }

    $url = 'https://' . $domain . $path;

    if ($method === 'GET' && $params) {
        $url .= '?' . http_build_query($params);
    }

    $authorization = base64_encode(
        $login . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Content-Type: application/json',
    ];

    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ];

    if ($method !== 'GET') {
        $contextOptions['http']['content'] =
            json_encode(
                $params,
                JSON_UNESCAPED_UNICODE
            );
    }

    if (!empty($k['proxy'])) {
        $parts = explode(':', (string)$k['proxy'], 2);

        if (count($parts) === 2) {
            $contextOptions['http']['proxy'] =
                'tcp://' . $parts[0] . ':' . (int)$parts[1];
            $contextOptions['http']['request_fulluri'] = true;
        }
    }

    $contextOptions['ssl'] = [
        'verify_peer' => !empty($k['verify_ssl']),
        'verify_peer_name' => !empty($k['verify_ssl']),
    ];

    $context = stream_context_create($contextOptions);

    $body = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = 0;

    if (
        isset($http_response_header) &&
        preg_match(
            '#HTTP/\S+\s+(\d+)#',
            $http_response_header[0],
            $m
        )
    ) {
        $status = (int)$m[1];
    }

    if ($body === false) {
        return [
            'ok' => false,
            'message' => 'kintoneへの通信に失敗しました。',
            'data' => null,
        ];
    }

    $data = json_decode($body, true);

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'message' => is_array($data) && !empty($data['message'])
                ? (string)$data['message']
                : 'HTTP ' . $status,
            'data' => $data,
        ];
    }

    return [
        'ok' => true,
        'message' => '',
        'data' => is_array($data) ? $data : [],
    ];
}

function kintone_value(
    array $record,
    string $field
): string {
    if ($field === '' || empty($record[$field])) {
        return '';
    }

    return (string)($record[$field]['value'] ?? '');
}

function kintone_address(
    array $record,
    array $fields
): string {
    $values = [];

    foreach ($fields as $field) {
        $value = kintone_value($record, (string)$field);

        if ($value !== '') {
            $values[] = $value;
        }
    }

    return implode(' ', $values);
}

/* ---------- SMTP ---------- */

function smtp_connect_test(array $config): void
{
    smtp_open($config);
}

function smtp_open(array $config)
{
    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port'] ?? 587);
    $encryption = $config['encryption'] ?? 'tls';

    if ($host === '') {
        throw new RuntimeException(
            'SMTPサーバが設定されていません。'
        );
    }

    $target = $host;

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host;
    }

    $socket = @stream_socket_client(
        'tcp://' . $target . ':' . $port,
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException(
            'SMTP接続失敗: ' . $errstr
        );
    }

    stream_set_timeout($socket, 10);

    smtp_expect($socket, [220]);

    smtp_command($socket, 'EHLO localhost', [250]);

    if ($encryption === 'tls') {
        smtp_command($socket, 'STARTTLS', [220]);

        if (
            !stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {
            fclose($socket);
            throw new RuntimeException(
                'TLS接続を確立できませんでした。'
            );
        }

        smtp_command($socket, 'EHLO localhost', [250]);
    }

    if (!empty($config['auth'])) {
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');

        if ($username === '' || $password === '') {
            fclose($socket);

            throw new RuntimeException(
                'SMTP認証情報が設定されていません。'
            );
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);

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

    smtp_command($socket, 'QUIT', [221]);

    fclose($socket);
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port'] ?? 587);
    $encryption = $config['encryption'] ?? 'tls';

    $from = trim((string)($config['from_email'] ?? ''));
    $fromName = trim((string)($config['from_name'] ?? ''));
    $replyTo = trim((string)($config['reply_to'] ?? ''));

    if (
        !filter_var($from, FILTER_VALIDATE_EMAIL) ||
        !filter_var($to, FILTER_VALIDATE_EMAIL)
    ) {
        throw new RuntimeException(
            '送信元または宛先メールアドレスが不正です。'
        );
    }

    $target = $host;

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host;
    }

    $socket = @stream_socket_client(
        'tcp://' . $target . ':' . $port,
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException(
            'SMTP接続失敗: ' . $errstr
        );
    }

    stream_set_timeout($socket, 10);

    try {
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO localhost', [250]);

        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);

            if (
                !stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                )
            ) {
                throw new RuntimeException(
                    'TLS接続を確立できませんでした。'
                );
            }

            smtp_command($socket, 'EHLO localhost', [250]);
        }

        if (!empty($config['auth'])) {
            smtp_command($socket, 'AUTH LOGIN', [334]);

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

        smtp_command($socket, 'DATA', [354]);

        $headers = [];

        $headers[] =
            'From: ' .
            ($fromName !== ''
                ? mb_encode_mimeheader(
                    $fromName,
                    'UTF-8'
                ) . ' <' . $from . '>'
                : $from);

        $headers[] = 'To: <' . $to . '>';

        if ($replyTo !== '') {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }

        $headers[] =
            'Subject: ' .
            mb_encode_mimeheader(
                $subject,
                'UTF-8'
            );

        $headers[] = 'MIME-Version: 1.0';
        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';
        $headers[] =
            'Content-Transfer-Encoding: 8bit';

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            normalize_crlf($body) .
            "\r\n.";

        smtp_command($socket, $message, [250]);
        smtp_command($socket, 'QUIT', [221]);

    } finally {
        fclose($socket);
    }
}

function smtp_command(
    $socket,
    string $command,
    array $expected
): string {
    fwrite($socket, $command . "\r\n");

    return smtp_expect($socket, $expected);
}

function smtp_expect($socket, array $expected): string
{
    $response = '';

    while (($line = fgets($socket)) !== false) {
        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    $code = (int)substr(trim($response), 0, 3);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . $code
        );
    }

    return $response;
}

function normalize_crlf(string $value): string
{
    return preg_replace(
        "/\r\n|\r|\n/",
        "\r\n",
        $value
    );
}

/* ---------- メール変数 ---------- */

function replace_mail_vars(
    string $text,
    array $customer,
    string $url
): string {
    return strtr($text, [
        '{顧客名}' =>
            (string)($customer['name'] ?? ''),
        '{アンケートURL}' => $url,
    ]);
}

function absolute_app_url(): string
{
    $scheme =
        (!empty($_SERVER['HTTPS']) &&
         $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $path = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

    return $scheme . '://' . $host . $path;
}

/* ---------- CSV ---------- */

function export_csv(string $surveyId): never
{
    $survey = survey_by_id($surveyId);

    if (!$survey) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers = array_values(
        array_filter(
            load_answers(),
            fn(array $a): bool =>
                ($a['surveyId'] ?? '') === $surveyId
        )
    );

    $questions = all_questions($survey);

    $filename =
        'survey-' .
        preg_replace('/[^a-zA-Z0-9_-]/', '', $surveyId) .
        '.csv';

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    $out = fopen('php://output', 'wb');

    /*
     * Excel向けUTF-8 BOM。
     */
    fwrite($out, "\xEF\xBB\xBF");

    $header = ['回答ID', '回答日時'];

    foreach ($questions as $question) {
        $header[] = $question['number'] . ' ' .
            $question['text'];
    }

    fputcsv($out, $header);

    foreach ($answers as $answer) {
        $row = [
            $answer['id'] ?? '',
            $answer['createdAt'] ?? '',
        ];

        foreach ($questions as $question) {
            $value =
                $answer['answers'][$question['id']] ??
                '';

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $label = answer_label(
                $question,
                (string)$value
            );

            $row[] = $label;
        }

        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}

function answer_label(
    array $question,
    string $value
): string {
    if ($value === '') {
        return '';
    }

    if (($question['type'] ?? '') === 'multiple') {
        $ids = array_filter(
            array_map(
                'trim',
                explode(',', $value)
            )
        );

        $labels = [];

        foreach ($ids as $id) {
            foreach ($question['options'] ?? [] as $option) {
                if (($option['id'] ?? '') === $id) {
                    $labels[] = $option['label'];
                    break;
                }
            }
        }

        return implode(', ', $labels);
    }

    foreach ($question['options'] ?? [] as $option) {
        if (($option['id'] ?? '') === $value) {
            return (string)$option['label'];
        }
    }

    return $value;
}

/* ---------- 簡易PDF ---------- */

function export_pdf(string $surveyId): never
{
    $survey = survey_by_id($surveyId);

    if (!$survey) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers = array_values(
        array_filter(
            load_answers(),
            fn(array $a): bool =>
                ($a['surveyId'] ?? '') === $surveyId
        )
    );

    /*
     * 外部ライブラリなしでPDFを生成。
     * 日本語CIDフォントの完全な埋め込みは行わず、
     * POC用のASCII PDFとして回答件数・設問集計を出力。
     */
    $lines = [];

    $lines[] = APP_NAME;
    $lines[] = 'Survey: ' . $survey['title'];
    $lines[] = 'Answers: ' . count($answers);
    $lines[] = '';

    foreach (all_questions($survey) as $question) {
        $lines[] =
            $question['number'] . ' ' .
            strip_tags((string)$question['text']);

        $count = 0;

        foreach ($answers as $answer) {
            if (
                !empty(
                    $answer['answers'][$question['id']]
                )
            ) {
                $count++;
            }
        }

        $lines[] = 'Answered: ' . $count;
        $lines[] = '';
    }

    $pdf = simple_pdf($lines);

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey.pdf"'
    );

    echo $pdf;
    exit;
}

function simple_pdf(array $lines): string
{
    $objects = [];

    $objects[1] =
        '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[2] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[3] =
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 4 0 R >> >> ' .
        '/Contents 5 0 R >>';

    $objects[4] =
        '<< /Type /Font /Subtype /Type1 ' .
        '/BaseFont /Helvetica >>';

    $stream = "BT\n";
    $stream .= "/F1 11 Tf\n";
    $stream .= "40 800 Td\n";

    foreach ($lines as $index => $line) {
        $line = preg_replace(
            '/[^\x20-\x7E]/',
            '?',
            $line
        );

        $line = str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $line
        );

        if ($index > 0) {
            $stream .= "0 -16 Td\n";
        }

        $stream .= '(' . $line . ") Tj\n";
    }

    $stream .= "ET";

    $objects[5] =
        '<< /Length ' . strlen($stream) .
        " >>\nstream\n" .
        $stream .
        "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    for ($i = 1; $i <= 5; $i++) {
        $offsets[$i] = strlen($pdf);

        $pdf .= $i . " 0 obj\n";
        $pdf .= $objects[$i];
        $pdf .= "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .= "xref\n";
    $pdf .= "0 6\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= 5; $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .= "trailer\n";
    $pdf .= "<< /Size 6 /Root 1 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= $xref . "\n";
    $pdf .= "%%EOF";

    return $pdf;
}

/* ---------- エラー ---------- */

function safe_error_message(Throwable $e): string
{
    $message = trim($e->getMessage());

    if ($message === '') {
        return '処理に失敗しました。';
    }

    /*
     * 機密情報がエラー画面に出ないよう除去。
     */
    $message = preg_replace(
        '/(password|authorization|secret|token)[^,;\s]*/i',
        '[REDACTED]',
        $message
    );

    return mb_substr($message, 0, 500);
}

/* ============================================================
 * 画面
 * ============================================================
 */

/* ---------- 回答者 ---------- */

if ($screen === 'answer') {

    $id = (string)($_GET['id'] ?? '');
    $survey = survey_by_id($id);

    if (!$survey) {
        http_response_code(404);
        render_header('アンケートが見つかりません', false);
        ?>
        <div class="card">
            <h1>アンケートが見つかりません</h1>
            <p>指定されたアンケートは存在しません。</p>
        </div>
        <?php
        render_footer();
        exit;
    }

    $survey = refresh_survey($survey);

    if (($survey['status'] ?? '') !== 'published') {
        render_header('回答受付終了', false);
        ?>
        <div class="card">
            <h1>回答を受け付けていません</h1>
            <p>
                このアンケートは現在回答を受け付けていません。
            </p>
        </div>
        <?php
        render_footer();
        exit;
    }

    $draft = $_SESSION['answer_draft'] ?? [];
    $errors = $_SESSION['answer_errors'] ?? [];

    unset($_SESSION['answer_errors']);

    render_header($survey['title'], false);
    ?>

    <div class="card">
        <h1><?= h($survey['title']) ?></h1>

        <?php if ($survey['description'] !== ''): ?>
            <p><?= nl2br(h($survey['description'])) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            未回答の必須項目があります。
        </div>
    <?php endif; ?>

    <form method="post"
          action="index.php?screen=answer&id=<?= h($id) ?>">

        <input type="hidden"
               name="_csrf"
               value="<?= h(csrf_token()) ?>">

        <input type="hidden"
               name="action"
               value="answer_confirm">

        <?php foreach ($survey['groups'] as $group): ?>

        <div class="card">
            <h2><?= h($group['title']) ?></h2>

            <?php foreach ($group['questions'] as $question): ?>

            <?php
            if (
                !is_question_visible(
                    $survey,
                    $question['id'],
                    $draft
                )
            ) {
                continue;
            }

            $qid = $question['id'];
            $value = $draft[$qid] ?? '';
            ?>

            <div class="preview-question">

                <label>
                    <?= h($question['number']) ?>
                    <?= h($question['text']) ?>

                    <?php if (!empty($question['required'])): ?>
                        <span class="required">*</span>
                    <?php endif; ?>
                </label>

                <?php if (($question['type'] ?? '') === 'single'): ?>

                    <?php foreach ($question['options'] as $option): ?>

                    <label class="answer-choice">
                        <input type="radio"
                               name="answers[<?= h($qid) ?>]"
                               value="<?= h($option['id']) ?>"
                            <?= $value === $option['id']
                                ? 'checked'
                                : '' ?>>
                        <?= h($option['label']) ?>
                    </label>

                    <?php endforeach; ?>

                <?php elseif (($question['type'] ?? '') === 'multiple'): ?>

                    <?php
                    $values = is_array($value)
                        ? $value
                        : [];
                    ?>

                    <?php foreach ($question['options'] as $option): ?>

                    <label class="answer-choice">
                        <input type="checkbox"
                               name="answers[<?= h($qid) ?>][]"
                               value="<?= h($option['id']) ?>"
                            <?= in_array(
                                $option['id'],
                                $values,
                                true
                            ) ? 'checked' : '' ?>>
                        <?= h($option['label']) ?>
                    </label>

                    <?php endforeach; ?>

                <?php else: ?>

                    <textarea
                        name="answers[<?= h($qid) ?>]"
                        rows="5"><?= h((string)$value) ?></textarea>

                <?php endif; ?>

                <?php if (isset($errors[$qid])): ?>
                    <div class="help"
                         style="color:var(--danger)">
                        <?= h($errors[$qid]) ?>
                    </div>
                <?php endif; ?>

            </div>

            <?php endforeach; ?>
        </div>

        <?php endforeach; ?>

        <div class="answer-actions">
            <span></span>
            <button class="btn btn-primary" type="submit">
                回答を確認する
            </button>
        </div>

    </form>

    <?php
    render_footer();
    exit;
}

/* ---------- 回答確認 ---------- */

if ($screen === 'confirm') {

    $id = (string)($_GET['id'] ?? '');
    $survey = survey_by_id($id);

    if (!$survey) {
        redirect('index.php?screen=list');
    }

    $draft = $_SESSION['answer_draft'] ?? [];

    render_header('回答確認', false);
    ?>

    <div class="card">
        <h1>回答確認</h1>
        <p><?= h($survey['title']) ?></p>
    </div>

    <div class="card">

    <?php foreach ($survey['groups'] as $group): ?>

        <h2><?= h($group['title']) ?></h2>

        <?php foreach ($group['questions'] as $question): ?>

        <?php
        if (
            !is_question_visible(
                $survey,
                $question['id'],
                $draft
            )
        ) {
            continue;
        }

        $value = $draft[$question['id']] ?? '';

        if (is_array($value)) {
            $display = [];

            foreach ($value as $v) {
                $display[] =
                    answer_label(
                        $question,
                        (string)$v
                    );
            }

            $display = implode(', ', $display);
        } else {
            $display =
                answer_label(
                    $question,
                    (string)$value
                );
        }
        ?>

        <div class="preview-question">
            <strong>
                <?= h($question['number']) ?>
                <?= h($question['text']) ?>
            </strong>

            <div>
                <?= nl2br(h($display)) ?>
            </div>
        </div>

        <?php endforeach; ?>

    <?php endforeach; ?>

    </div>

    <div class="answer-actions">

        <a class="btn"
           href="index.php?screen=answer&id=<?= h($id) ?>">
            修正する
        </a>

        <form method="post"
              action="index.php?screen=confirm&id=<?= h($id) ?>"
              onsubmit="return confirm('回答を送信します。よろしいですか？');">

            <input type="hidden"
                   name="_csrf"
                   value="<?= h(csrf_token()) ?>">

            <input type="hidden"
                   name="action"
                   value="submit_answer">

            <input type="hidden"
                   name="id"
                   value="<?= h($id) ?>">

            <button class="btn btn-primary" type="submit">
                回答を送信する
            </button>
        </form>

    </div>

    <?php
    render_footer();
    exit;
}

/* ---------- 回答完了 ---------- */

if ($screen === 'complete') {

    render_header('回答完了', false);
    ?>

    <div class="card">
        <h1>回答ありがとうございました</h1>
        <p>
            回答を受け付けました。
        </p>
    </div>

    <?php
    render_footer();
    exit;
}

/* ---------- 一覧 ---------- */

if ($screen === 'list') {

    $surveys = load_surveys();

    foreach ($surveys as &$item) {
        survey_status($item);
    }
    unset($item);

    save_surveys($surveys);

    $search = trim((string)($_GET['q'] ?? ''));
    $filter = $_GET['status'] ?? 'all';
    $sort = $_GET['sort'] ?? 'updated_desc';

    $answers = load_answers();

    $surveys = array_values(
        array_filter(
            $surveys,
            function (array $survey) use (
                $search,
                $filter
            ): bool {
                if (
                    $search !== '' &&
                    mb_stripos(
                        (string)$survey['title'],
                        $search
                    ) === false
                ) {
                    return false;
                }

                if (
                    $filter !== 'all' &&
                    ($survey['status'] ?? '') !== $filter
                ) {
                    return false;
                }

                return true;
            }
        )
    );

    usort(
        $surveys,
        function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        $a['updatedAt'] ?? '',
                        $b['updatedAt'] ?? ''
                    ),
                'answers_desc' =>
                    answer_count($b['id']) <=>
                    answer_count($a['id']),
                'answers_asc' =>
                    answer_count($a['id']) <=>
                    answer_count($b['id']),
                'start_desc' =>
                    strcmp(
                        $b['startAt'] ?? '',
                        $a['startAt'] ?? ''
                    ),
                'start_asc' =>
                    strcmp(
                        $a['startAt'] ?? '',
                        $b['startAt'] ?? ''
                    ),
                default =>
                    strcmp(
                        $b['updatedAt'] ?? '',
                        $a['updatedAt'] ?? ''
                    ),
            };
        }
    );

    render_header('アンケート一覧');
    ?>

    <div class="toolbar">
        <h1>アンケート一覧</h1>

        <a class="btn btn-primary"
           href="index.php?screen=edit">
            ＋ 新規アンケート
        </a>
    </div>

    <div class="card">

        <form method="get">
            <input type="hidden"
                   name="screen"
                   value="list">

            <div class="grid grid-3">

                <div class="field">
                    <label>検索</label>
                    <input type="text"
                           name="q"
                           value="<?= h($search) ?>"
                           placeholder="タイトルを検索">
                </div>

                <div class="field">
                    <label>ステータス</label>
                    <select name="status">
                        <?php
                        $filters = [
                            'all' => 'すべて',
                            'published' => '公開中',
                            'draft' => '下書き',
                            'stopped' => '停止',
                            'ended' => '終了',
                        ];
                        ?>

                        <?php foreach ($filters as $key => $label): ?>
                            <option
                                value="<?= h($key) ?>"
                                <?= $filter === $key
                                    ? 'selected'
                                    : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>ソート</label>
                    <select name="sort">
                        <?php
                        $sorts = [
                            'updated_desc' =>
                                '更新日：新しい順',
                            'updated_asc' =>
                                '更新日：古い順',
                            'answers_desc' =>
                                '回答数：多い順',
                            'answers_asc' =>
                                '回答数：少ない順',
                            'start_desc' =>
                                '開始日：新しい順',
                            'start_asc' =>
                                '開始日：古い順',
                        ];
                        ?>

                        <?php foreach ($sorts as $key => $label): ?>
                            <option
                                value="<?= h($key) ?>"
                                <?= $sort === $key
                                    ? 'selected'
                                    : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <button class="btn" type="submit">
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

        <?php if (!$surveys): ?>

        <tr>
            <td colspan="7">
                アンケートはありません。
            </td>
        </tr>

        <?php endif; ?>

        <?php foreach ($surveys as $survey): ?>

        <?php
        $status = $survey['status'] ?? 'draft';
        ?>

        <tr>

            <td>
                <strong><?= h($survey['title']) ?></strong>
            </td>

            <td><?= h($survey['createdAt'] ?? '') ?></td>
            <td><?= h($survey['updatedAt'] ?? '') ?></td>

            <td>
                <?= h($survey['startAt'] ?: '-') ?>
                〜
                <?= h($survey['endAt'] ?: '-') ?>
            </td>

            <td>
                <span class="badge badge-<?= h(
                    status_class($status)
                ) ?>">
                    <?= h(status_label($status)) ?>
                </span>
            </td>

            <td><?= answer_count($survey['id']) ?></td>

            <td>
                <div class="actions">

                    <a class="btn btn-sm"
                       href="index.php?screen=edit&id=<?= h($survey['id']) ?>">
                        編集
                    </a>

                    <a class="btn btn-sm"
                       href="index.php?screen=preview&id=<?= h($survey['id']) ?>">
                        プレビュー
                    </a>

                    <a class="btn btn-sm"
                       href="index.php?screen=analytics&id=<?= h($survey['id']) ?>">
                        集計
                    </a>

                    <a class="btn btn-sm"
                       href="index.php?screen=send&id=<?= h($survey['id']) ?>">
                        送信
                    </a>

                    <form method="post" style="display:inline"
                          onsubmit="return confirm('複製しますか？');">

                        <input type="hidden"
                               name="_csrf"
                               value="<?= h(csrf_token()) ?>">

                        <input type="hidden"
                               name="action"
                               value="duplicate_survey">

                        <input type="hidden"
                               name="id"
                               value="<?= h($survey['id']) ?>">

                        <button class="btn btn-sm"
                                type="submit">
                            複製
                        </button>
                    </form>

                    <form method="post" style="display:inline"
                          onsubmit="return confirm('削除しますか？');">

                        <input type="hidden"
                               name="_csrf"
                               value="<?= h(csrf_token()) ?>">

                        <input type="hidden"
                               name="action"
                               value="delete_survey">

                        <input type="hidden"
                               name="id"
                               value="<?= h($survey['id']) ?>">

                        <button class="btn btn-sm btn-danger"
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
    render_footer();
    exit;
}

/* ---------- 編集 ---------- */

if ($screen === 'edit') {

    $id = (string)($_GET['id'] ?? '');

    if ($id !== '') {
        $survey = survey_by_id($id);

        if (!$survey) {
            redirect('index.php?screen=list');
        }

        $survey = refresh_survey($survey);
    } else {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'numbering' => 'global',
            'status' => 'draft',
            'groups' => [
                [
                    'id' => uuid('group'),
                    'title' => '基本情報',
                    'questions' => [],
                ],
            ],
        ];
    }

    render_header('アンケート作成・編集');
    ?>

    <div class="toolbar">
        <h1>
            <?= $survey['id'] === ''
                ? 'アンケート作成'
                : 'アンケート編集' ?>
        </h1>

        <div class="actions">

            <a class="btn"
               href="index.php?screen=list">
                キャンセル
            </a>

            <?php if ($survey['id'] !== ''): ?>

            <a class="btn"
               href="index.php?screen=preview&id=<?= h($survey['id']) ?>">
                プレビュー
            </a>

            <?php endif; ?>

        </div>
    </div>

    <form method="post">

        <input type="hidden"
               name="_csrf"
               value="<?= h(csrf_token()) ?>">

        <input type="hidden"
               name="action"
               value="save_survey">

        <input type="hidden"
               name="id"
               value="<?= h($survey['id']) ?>">

        <div class="card">

            <div class="grid grid-2">

                <div class="field">
                    <label>アンケートタイトル</label>
                    <input type="text"
                           name="title"
                           required
                           maxlength="200"
                           value="<?= h($survey['title']) ?>">
                </div>

                <div class="field">
                    <label>状態</label>

                    <?php
                    $status = $survey['status'];
                    ?>

                    <select name="status"
                            <?= $status === 'ended'
                                ? 'disabled'
                                : '' ?>>

                        <option value="draft"
                            <?= $status === 'draft'
                                ? 'selected'
                                : '' ?>>
                            下書き
                        </option>

                        <option value="published"
                            <?= $status === 'published'
                                ? 'selected'
                                : '' ?>>
                            公開中
                        </option>

                        <option value="stopped"
                            <?= $status === 'stopped'
                                ? 'selected'
                                : '' ?>>
                            停止
                        </option>

                        <?php if ($status === 'ended'): ?>
                            <option value="ended" selected>
                                終了
                            </option>
                        <?php endif; ?>

                    </select>
                </div>

            </div>

            <div class="field">
                <label>アンケート説明</label>
                <textarea name="description"><?= h(
                    $survey['description']
                ) ?></textarea>
            </div>

            <div class="grid grid-2">

                <div class="field">
                    <label>開始日時</label>
                    <input type="datetime-local"
                           name="startAt"
                           value="<?= h(
                               datetime_local(
                                   $survey['startAt']
                               )
                           ) ?>">
                </div>

                <div class="field">
                    <label>終了日時</label>
                    <input type="datetime-local"
                           name="endAt"
                           value="<?= h(
                               datetime_local(
                                   $survey['endAt']
                               )
                           ) ?>">
                </div>

            </div>

            <div class="field">
                <label>質問番号の採番方式</label>

                <select name="numbering">
                    <option value="global"
                        <?= $survey['numbering'] === 'global'
                            ? 'selected'
                            : '' ?>>
                        アンケート全体で通番（Q1、Q2...）
                    </option>

                    <option value="group"
                        <?= $survey['numbering'] === 'group'
                            ? 'selected'
                            : '' ?>>
                        グループ毎（Q1-1、Q1-2...）
                    </option>
                </select>
            </div>

            <button class="btn btn-primary" type="submit">
                保存して一覧へ
            </button>

        </div>

    </form>

    <?php if ($survey['id'] !== ''): ?>

    <div class="card">

        <div class="toolbar">
            <h2>質問・グループ</h2>
        </div>

        <div id="groups">

        <?php foreach ($survey['groups'] as $group): ?>

        <div class="group"
             draggable="true"
             data-group-id="<?= h($group['id']) ?>">

            <div class="group-header">

                <span class="drag-handle">☰</span>

                <input class="group-title"
                       value="<?= h($group['title']) ?>">

                <button type="button"
                        class="btn btn-sm"
                        onclick="addQuestion(this)">
                    ＋ 質問
                </button>

                <button type="button"
                        class="btn btn-sm btn-danger"
                        onclick="deleteGroup(this)">
                    削除
                </button>

            </div>

            <div class="question-list">

            <?php foreach ($group['questions'] as $question): ?>

            <?= question_editor_html($question) ?>

            <?php endforeach; ?>

            </div>

        </div>

        <?php endforeach; ?>

        </div>

        <button type="button"
                class="btn"
                onclick="addGroup()">
            ＋ グループを追加
        </button>

        <form method="post"
              id="structure-form"
              style="margin-top:20px">

            <input type="hidden"
                   name="_csrf"
                   value="<?= h(csrf_token()) ?>">

            <input type="hidden"
                   name="action"
                   value="save_structure">

            <input type="hidden"
                   name="id"
                   value="<?= h($survey['id']) ?>">

            <input type="hidden"
                   name="structure"
                   id="structure-data">

            <button class="btn btn-primary"
                    type="submit"
                    onclick="return collectStructure()">
                質問構成を保存
            </button>

        </form>

    </div>

    <script>
    function uid(prefix) {
        return prefix + '-' +
            Date.now().toString(36) + '-' +
            Math.random().toString(36).slice(2, 8);
    }

    function addGroup() {
        const root = document.getElementById('groups');

        const div = document.createElement('div');

        div.className = 'group';
        div.draggable = true;
        div.dataset.groupId = uid('group');

        div.innerHTML = `
            <div class="group-header">
                <span class="drag-handle">☰</span>
                <input class="group-title"
                       value="新しいグループ">
                <button type="button"
                        class="btn btn-sm"
                        onclick="addQuestion(this)">
                    ＋ 質問
                </button>
                <button type="button"
                        class="btn btn-sm btn-danger"
                        onclick="deleteGroup(this)">
                    削除
                </button>
            </div>
            <div class="question-list"></div>
        `;

        root.appendChild(div);

        setupDnD();
    }

    function deleteGroup(button) {
        if (!confirm('グループを削除しますか？')) {
            return;
        }

        button.closest('.group').remove();
    }

    function addQuestion(button) {
        const list =
            button.closest('.group')
            .querySelector('.question-list');

        list.insertAdjacentHTML(
            'beforeend',
            questionTemplate()
        );

        setupDnD();
    }

    function questionTemplate() {
        const id = uid('question');

        return `
        <div class="question"
             draggable="true"
             data-question-id="${id}">

            <div class="question-head">
                <span class="drag-handle">☰</span>

                <strong class="question-number">
                    自動採番
                </strong>

                <button type="button"
                        class="btn btn-sm btn-danger"
                        onclick="deleteQuestion(this)">
                    削除
                </button>
            </div>

            <div class="question-body">

                <div class="field">
                    <label>質問文</label>
                    <input class="question-text"
                           value="">
                </div>

                <div class="grid grid-2">

                    <div class="field">
                        <label>回答形式</label>

                        <select class="question-type"
                                onchange="changeQuestionType(this)">
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
                    </div>

                    <div class="field">
                        <label>
                            <input type="checkbox"
                                   class="question-required">
                            必須
                        </label>
                    </div>

                </div>

                <div class="options">
                    ${optionTemplate()}
                    ${optionTemplate()}
                </div>

                <button type="button"
                        class="btn btn-sm"
                        onclick="addOption(this)">
                    ＋ 選択肢
                </button>

            </div>
        </div>`;
    }

    function optionTemplate() {
        const id = uid('option');

        return `
        <div class="option-row"
             data-option-id="${id}">
            <input class="option-label"
                   placeholder="選択肢">
            <select class="option-next">
                <option value="">次の質問へ</option>
            </select>
            <button type="button"
                    class="btn btn-sm"
                    onclick="this.parentElement.remove()">
                削除
            </button>
        </div>`;
    }

    function addOption(button) {
        const container =
            button.parentElement
            .querySelector('.options');

        container.insertAdjacentHTML(
            'beforeend',
            optionTemplate()
        );
    }

    function deleteQuestion(button) {
        if (!confirm('質問を削除しますか？')) {
            return;
        }

        button.closest('.question').remove();
    }

    function changeQuestionType(select) {
        const question =
            select.closest('.question');

        const options =
            question.querySelector('.options');

        const add =
            question.querySelector('.options')
            .nextElementSibling;

        const isText = select.value === 'text';

        options.style.display =
            isText ? 'none' : '';

        add.style.display =
            isText ? 'none' : '';
    }

    function collectStructure() {
        const groups = [];

        document.querySelectorAll(
            '#groups > .group'
        ).forEach(group => {

            const g = {
                id: group.dataset.groupId,
                title:
                    group.querySelector(
                        '.group-title'
                    ).value,
                questions: []
            };

            group.querySelectorAll(
                '.question-list > .question'
            ).forEach(question => {

                const q = {
                    id: question.dataset.questionId,
                    text:
                        question.querySelector(
                            '.question-text'
                        ).value,
                    type:
                        question.querySelector(
                            '.question-type'
                        ).value,
                    required:
                        question.querySelector(
                            '.question-required'
                        ).checked,
                    options: []
                };

                question.querySelectorAll(
                    '.option-row'
                ).forEach(row => {
                    q.options.push({
                        id: row.dataset.optionId,
                        label:
                            row.querySelector(
                                '.option-label'
                            ).value,
                        nextQuestionId:
                            row.querySelector(
                                '.option-next'
                            ).value || null
                    });
                });

                g.questions.push(q);
            });

            groups.push(g);
        });

        document.getElementById(
            'structure-data'
        ).value = JSON.stringify(groups);

        return true;
    }

    let dragItem = null;

    function setupDnD() {
        document.querySelectorAll(
            '.group,.question'
        ).forEach(el => {

            el.addEventListener(
                'dragstart',
                function() {
                    dragItem = this;
                }
            );

            el.addEventListener(
                'dragover',
                function(e) {
                    e.preventDefault();

                    if (
                        dragItem &&
                        dragItem !== this
                    ) {
                        this.classList.add(
                            'drag-over'
                        );
                    }
                }
            );

            el.addEventListener(
                'dragleave',
                function() {
                    this.classList.remove(
                        'drag-over'
                    );
                }
            );

            el.addEventListener(
                'drop',
                function(e) {
                    e.preventDefault();

                    this.classList.remove(
                        'drag-over'
                    );

                    if (!dragItem ||
                        dragItem === this) {
                        return;
                    }

                    if (
                        this.classList.contains('group') &&
                        dragItem.classList.contains('group')
                    ) {
                        this.parentNode.insertBefore(
                            dragItem,
                            this
                        );
                    }

                    if (
                        this.classList.contains('question') &&
                        dragItem.classList.contains('question')
                    ) {
                        this.closest('.question-list')
                            .insertBefore(
                                dragItem,
                                this
                            );
                    }

                    if (
                        this.classList.contains('question-list') &&
                        dragItem.classList.contains('question')
                    ) {
                        this.appendChild(dragItem);
                    }
                }
            );
        });

        document.querySelectorAll(
            '.question-list'
        ).forEach(list => {
            list.addEventListener(
                'dragover',
                function(e) {
                    e.preventDefault();
                }
            );

            list.addEventListener(
                'drop',
                function(e) {
                    e.preventDefault();

                    if (
                        dragItem &&
                        dragItem.classList.contains(
                            'question'
                        )
                    ) {
                        this.appendChild(dragItem);
                    }
                }
            );
        });
    }

    setupDnD();
    </script>

    <?php endif; ?>

    <?php
    render_footer();
    exit;
}

/* ---------- プレビュー ---------- */

if ($screen === 'preview') {

    render_header('プレビュー');

    ?>
    <div class="toolbar">
        <h1>プレビュー</h1>

        <a class="btn"
           href="index.php?screen=edit&id=<?= h($survey['id']) ?>">
            編集へ戻る
        </a>
    </div>

    <div class="card">

        <h1><?= h($survey['title']) ?></h1>

        <p>
            <?= nl2br(h($survey['description'])) ?>
        </p>

        <?php foreach ($survey['groups'] as $group): ?>

        <h2><?= h($group['title']) ?></h2>

        <?php foreach ($group['questions'] as $question): ?>

        <div class="preview-question">

            <label>
                <?= h($question['number']) ?>
                <?= h($question['text']) ?>

                <?php if ($question['required']): ?>
                    <span class="required">*</span>
                <?php endif; ?>
            </label>

            <?php if ($question['type'] === 'single'): ?>

                <?php foreach ($question['options'] as $option): ?>

                <label class="answer-choice">
                    <input type="radio" disabled>
                    <?= h($option['label']) ?>
                </label>

                <?php endforeach; ?>

            <?php elseif ($question['type'] === 'multiple'): ?>

                <?php foreach ($question['options'] as $option): ?>

                <label class="answer-choice">
                    <input type="checkbox" disabled>
                    <?= h($option['label']) ?>
                </label>

                <?php endforeach; ?>

            <?php else: ?>

                <textarea disabled></textarea>

            <?php endif; ?>

        </div>

        <?php endforeach; ?>

        <?php endforeach; ?>

    </div>

    <?php
    render_footer();
    exit;
}

/* ---------- 送信 ---------- */

if ($screen === 'send') {

    $customers = load_customers();
    $logs = load_send_logs();

    $logs = array_values(
        array_filter(
            $logs,
            fn(array $log): bool =>
                ($log['surveyId'] ?? '') === $survey['id']
        )
    );

    render_header('顧客選択・メール送信');
    ?>

    <div class="toolbar">
        <div>
            <h1>顧客選択・メール送信</h1>
            <p>
                対象：
                <strong><?= h($survey['title']) ?></strong>
            </p>
        </div>

        <a class="btn"
           href="index.php?screen=list">
            一覧へ
        </a>
    </div>

    <div class="card">

        <form method="post"
              onsubmit="return confirm('選択した顧客へ送信しますか？');">

            <input type="hidden"
                   name="_csrf"
                   value="<?= h(csrf_token()) ?>">

            <input type="hidden"
                   name="action"
                   value="send_bulk_mail">

            <input type="hidden"
                   name="id"
                   value="<?= h($survey['id']) ?>">

            <div class="field">
                <label>メール件名</label>
                <input type="text"
                       name="subject"
                       value="<?= h(
                           $survey['title'] . ' ご回答のお願い'
                       ) ?>">
            </div>

            <div class="field">
                <label>メール本文</label>

                <textarea name="body"><?=
                    h(
                        "{顧客名} 様\r\n\r\n" .
                        "以下のアンケートへのご回答をお願いします。\r\n\r\n" .
                        "{アンケートURL}\r\n"
                    )
                ?></textarea>

                <div class="help">
                    使用可能な変数：
                    {顧客名} / {アンケートURL}
                </div>
            </div>

            <h2>顧客選択</h2>

            <?php if (!$customers): ?>

                <div class="alert alert-warning">
                    顧客データがありません。
                    kintone設定画面から同期してください。
                </div>

            <?php else: ?>

            <div class="table-wrap">
            <table>

                <thead>
                <tr>
                    <th>
                        <input type="checkbox"
                               onclick="toggleCustomers(this)">
                    </th>
                    <th>組織名</th>
                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th>部署</th>
                    <th>電話番号</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($customers as $customer): ?>

                <tr>
                    <td>
                        <input type="checkbox"
                               name="customers[]"
                               value="<?= h($customer['id']) ?>"
                               class="customer-check">
                    </td>

                    <td><?= h($customer['organization']) ?></td>
                    <td><?= h($customer['name']) ?></td>
                    <td><?= h($customer['email']) ?></td>
                    <td><?= h($customer['department']) ?></td>
                    <td><?= h($customer['phone']) ?></td>
                </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
            </div>

            <div style="margin-top:18px">
                <button class="btn btn-primary"
                        type="submit">
                    一括送信
                </button>
            </div>

            <?php endif; ?>

        </form>

    </div>

    <div class="card">
        <h2>送信履歴</h2>

        <div class="table-wrap">

        <table>
        <thead>
        <tr>
            <th>日時</th>
            <th>メールアドレス</th>
            <th>結果</th>
            <th>内容</th>
        </tr>
        </thead>

        <tbody>

        <?php if (!$logs): ?>

        <tr>
            <td colspan="4">
                送信履歴はありません。
            </td>
        </tr>

        <?php endif; ?>

        <?php foreach (array_reverse($logs) as $log): ?>

        <tr>
            <td><?= h($log['sentAt']) ?></td>
            <td><?= h($log['email']) ?></td>
            <td>
                <span class="badge badge-<?= $log['status'] === 'sent'
                    ? 'success'
                    : 'warning' ?>">
                    <?= $log['status'] === 'sent'
                        ? '送信成功'
                        : '送信失敗' ?>
                </span>
            </td>
            <td><?= h($log['message']) ?></td>
        </tr>

        <?php endforeach; ?>

        </tbody>
        </table>

        </div>
    </div>

    <script>
    function toggleCustomers(master) {
        document.querySelectorAll(
            '.customer-check'
        ).forEach(function(el) {
            el.checked = master.checked;
        });
    }
    </script>

    <?php
    render_footer();
    exit;
}

/* ---------- 集計 ---------- */

if ($screen === 'analytics') {

    $allAnswers = load_answers();

    $answers = array_values(
        array_filter(
            $allAnswers,
            fn(array $a): bool =>
                ($a['surveyId'] ?? '') === $survey['id']
        )
    );

    $logs = array_values(
        array_filter(
            load_send_logs(),
            fn(array $log): bool =>
                ($log['surveyId'] ?? '') === $survey['id']
        )
    );

    $sentCustomers = [];

    foreach ($logs as $log) {
        if (($log['status'] ?? '') === 'sent') {
            $sentCustomers[$log['customerId'] ?? ''] = true;
        }
    }

    $sentCount = count($sentCustomers);
    $answerCount = count($answers);
    $unregistered = 0;

    foreach ($answers as $answer) {
        if (empty($answer['customerId'])) {
            $unregistered++;
        }
    }

    $unanswered = max(
        0,
        $sentCount - $answerCount
    );

    $rate =
        $sentCount > 0
            ? round(
                $answerCount / $sentCount * 100,
                1
            )
            : 0;

    render_header('回答集計・分析');
    ?>

    <div class="toolbar">

        <div>
            <h1>回答集計・分析</h1>
            <p>
                対象：
                <strong><?= h($survey['title']) ?></strong>
            </p>
        </div>

        <div class="actions">

            <form method="post">
                <input type="hidden"
                       name="_csrf"
                       value="<?= h(csrf_token()) ?>">

                <input type="hidden"
                       name="action"
                       value="export_csv">

                <input type="hidden"
                       name="id"
                       value="<?= h($survey['id']) ?>">

                <button class="btn"
                        type="submit">
                    CSV
                </button>
            </form>

            <form method="post">
                <input type="hidden"
                       name="_csrf"
                       value="<?= h(csrf_token()) ?>">

                <input type="hidden"
                       name="action"
                       value="export_pdf">

                <input type="hidden"
                       name="id"
                       value="<?= h($survey['id']) ?>">

                <button class="btn"
                        type="submit">
                    PDF
                </button>
            </form>

        </div>
    </div>

    <div class="stats">

        <div class="stat">
            <div class="stat-label">送信対象者数</div>
            <div class="stat-value">
                <?= $sentCount ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">回答数</div>
            <div class="stat-value">
                <?= $answerCount ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">未回答数</div>
            <div class="stat-value">
                <?= $unanswered ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">回答率</div>
            <div class="stat-value">
                <?= h((string)$rate) ?>%
            </div>
        </div>

    </div>

    <?php if ($answerCount === 0): ?>

    <div class="card">
        現在、回答データはありません
    </div>

    <?php else: ?>

    <?php foreach ($survey['groups'] as $group): ?>

    <div class="card">

        <h2><?= h($group['title']) ?></h2>

        <?php foreach ($group['questions'] as $question): ?>

        <?php
        $counts = [];

        foreach ($question['options'] ?? [] as $option) {
            $counts[$option['id']] = 0;
        }

        $textCount = 0;

        foreach ($answers as $answer) {
            $value =
                $answer['answers'][$question['id']]
                ?? null;

            if (is_array($value)) {
                foreach ($value as $v) {
                    if (isset($counts[$v])) {
                        $counts[$v]++;
                    }
                }
            } elseif (
                $value !== null &&
                $value !== ''
            ) {
                if (isset($counts[$value])) {
                    $counts[$value]++;
                }

                if ($question['type'] === 'text') {
                    $textCount++;
                }
            }
        }
        ?>

        <div class="preview-question">

            <h3>
                <?= h($question['number']) ?>
                <?= h($question['text']) ?>
            </h3>

            <?php if ($question['type'] === 'text'): ?>

                <p>
                    回答数：
                    <?= $textCount ?>
                </p>

            <?php else: ?>

                <?php foreach ($question['options'] as $option): ?>

                <div style="margin-bottom:8px">
                    <?= h($option['label']) ?>：
                    <strong>
                        <?= $counts[$option['id']] ?? 0 ?>
                    </strong>
                    件
                </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

        <?php endforeach; ?>

    </div>

    <?php endforeach; ?>

    <div class="card">

        <h2>個別回答</h2>

        <div class="table-wrap">

        <table>

        <thead>
        <tr>
            <th>回答ID</th>
            <th>日時</th>

            <?php foreach (all_questions($survey) as $question): ?>
                <th><?= h($question['number']) ?></th>
            <?php endforeach; ?>

        </tr>
        </thead>

        <tbody>

        <?php foreach ($answers as $answer): ?>

        <tr>
            <td><?= h($answer['id']) ?></td>
            <td><?= h($answer['createdAt']) ?></td>

            <?php foreach (all_questions($survey) as $question): ?>

            <?php
            $value =
                $answer['answers'][$question['id']]
                ?? '';

            if (is_array($value)) {
                $value = implode(
                    ', ',
                    array_map(
                        fn($v) =>
                            answer_label(
                                $question,
                                (string)$v
                            ),
                        $value
                    )
                );
            } else {
                $value = answer_label(
                    $question,
                    (string)$value
                );
            }
            ?>

            <td><?= nl2br(h((string)$value)) ?></td>

            <?php endforeach; ?>

        </tr>

        <?php endforeach; ?>

        </tbody>
        </table>

        </div>
    </div>

    <?php endif; ?>

    <?php
    render_footer();
    exit;
}

/* ---------- kintone設定 ---------- */

if ($screen === 'kintone') {

    $settings = load_settings();
    $k = $settings['kintone'];

    $fields = $_SESSION['kintone_fields'] ?? [];

    render_header('kintone連携設定');
    ?>

    <div class="toolbar">
        <h1>kintone連携設定</h1>
        <a class="btn"
           href="index.php?screen=list">
            一覧へ
        </a>
    </div>

    <div class="card">

    <form method="post">

        <input type="hidden"
               name="_csrf"
               value="<?= h(csrf_token()) ?>">

        <input type="hidden"
               name="action"
               value="save_kintone">

        <div class="field">
            <label>サブドメイン</label>
            <input type="text"
                   name="subdomain"
                   value="<?= h($k['subdomain']) ?>"
                   placeholder="example.cybozu.com">
        </div>

        <div class="field">
            <label>顧客管理アプリID</label>
            <input type="number"
                   name="app_id"
                   value="<?= h($k['app_id']) ?>">
        </div>

        <div class="grid grid-2">

            <div class="field">
                <label>ログイン名</label>
                <input type="text"
                       name="login"
                       value="<?= h($k['login']) ?>">
            </div>

            <div class="field">
                <label>パスワード</label>
                <input type="password"
                       name="password"
                       placeholder="変更しない場合は空欄">
            </div>

        </div>

        <div class="field">
            <label>Proxy</label>
            <input type="text"
                   name="proxy"
                   value="<?= h($k['proxy']) ?>"
                   placeholder="host:port">
        </div>

        <div class="field">
            <label>
                <input type="checkbox"
                       name="verify_ssl"
                       value="1"
                       <?= !empty($k['verify_ssl'])
                           ? 'checked'
                           : '' ?>>
                SSL証明書を検証する
            </label>
        </div>

        <h2>項目マッピング</h2>

        <div class="grid grid-2">

            <?php
            $mapping = [
                'organization' => '組織名',
                'name' => '氏名',
                'email' => 'メールアドレス',
                'department' => '部署名',
                'phone' => '電話番号',
            ];
            ?>

            <?php foreach ($mapping as $key => $label): ?>

            <div class="field">
                <label><?= h($label) ?></label>
                <input type="text"
                       name="field_<?= h($key) ?>"
                       value="<?= h(
                           $k['fields'][$key] ?? ''
                       ) ?>">
            </div>

            <?php endforeach; ?>

        </div>

        <div class="field">
            <label>住所マッピング</label>

            <?php foreach ($fields as $fieldName => $field): ?>

            <label>
                <input type="checkbox"
                       name="field_address[]"
                       value="<?= h($fieldName) ?>"
                    <?= in_array(
                        $fieldName,
                        $k['fields']['address'] ?? [],
                        true
                    ) ? 'checked' : '' ?>>
                <?= h(
                    $field['label'] ??
                    $fieldName
                ) ?>
            </label>

            <?php endforeach; ?>

            <?php if (!$fields): ?>
                <div class="help">
                    「項目一覧を再取得」でkintoneの項目を取得できます。
                </div>
            <?php endif; ?>

        </div>

        <button class="btn btn-primary" type="submit">
            設定保存
        </button>

    </form>

    </div>

    <div class="card">

        <div class="actions">

            <form method="post">
                <input type="hidden"
                       name="_csrf"
                       value="<?= h(csrf_token()) ?>">
                <input type="hidden"
                       name="action"
                       value="test_kintone">

                <button class="btn" type="submit">
                    接続テスト
                </button>
            </form>

            <form method="post">
                <input type="hidden"
                       name="_csrf"
                       value="<?= h(csrf_token()) ?>">
                <input type="hidden"
                       name="action"
                       value="fetch_kintone_fields">

                <button class="btn" type="submit">
                    項目一覧を再取得
                </button>
            </form>

            <form method="post">
                <input type="hidden"
                       name="_csrf"
                       value="<?= h(csrf_token()) ?>">
                <input type="hidden"
                       name="action"
                       value="sync_kintone">

                <button class="btn btn-primary"
                        type="submit">
                    顧客情報を同期
                </button>
            </form>

        </div>

        <p class="help">
            接続状態：
            <?= h($k['status'] ?? '未設定') ?>
        </p>

    </div>

    <?php
    render_footer();
    exit;
}

/* ---------- メール設定 ---------- */

if ($screen === 'mail') {

    $settings = load_settings();
    $mail = $settings['mail'];

    render_header('メールサーバ設定');
    ?>

    <div class="toolbar">
        <h1>メールサーバ設定</h1>
        <a class="btn"
           href="index.php?screen=list">
            一覧へ
        </a>
    </div>

    <div class="card">

    <form method="post">

        <input type="hidden"
               name="_csrf"
               value="<?= h(csrf_token()) ?>">

        <input type="hidden"
               name="action"
               value="save_mail">

        <div class="grid grid-2">

            <div class="field">
                <label>SMTPサーバ</label>
                <input type="text"
                       name="host"
                       value="<?= h($mail['host']) ?>">
            </div>

            <div class="field">
                <label>SMTPポート</label>
                <input type="number"
                       name="port"
                       min="1"
                       max="65535"
                       value="<?= h($mail['port']) ?>">
            </div>

        </div>

        <div class="field">
            <label>暗号化方式</label>

            <select name="encryption">
                <option value="ssl"
                    <?= $mail['encryption'] === 'ssl'
                        ? 'selected'
                        : '' ?>>
                    SSL
                </option>

                <option value="tls"
                    <?= $mail['encryption'] === 'tls'
                        ? 'selected'
                        : '' ?>>
                    TLS
                </option>

                <option value="none"
                    <?= $mail['encryption'] === 'none'
                        ? 'selected'
                        : '' ?>>
                    なし
                </option>
            </select>
        </div>

        <div class="field">
            <label>
                <input type="checkbox"
                       name="auth"
                       value="1"
                       <?= !empty($mail['auth'])
                           ? 'checked'
                           : '' ?>>
                SMTP認証を使用する
            </label>
        </div>

        <div class="grid grid-2">

            <div class="field">
                <label>SMTPユーザー名</label>
                <input type="text"
                       name="username"
                       value="<?= h($mail['username']) ?>">
            </div>

            <div class="field">
                <label>SMTPパスワード</label>
                <input type="password"
                       name="password"
                       placeholder="変更しない場合は空欄">
            </div>

        </div>

        <div class="grid grid-2">

            <div class="field">
                <label>送信元メールアドレス</label>
                <input type="email"
                       name="from_email"
                       value="<?= h($mail['from_email']) ?>">
            </div>

            <div class="field">
                <label>送信元名</label>
                <input type="text"
                       name="from_name"
                       value="<?= h($mail['from_name']) ?>">
            </div>

        </div>

        <div class="field">
            <label>返信先メールアドレス</label>
            <input type="email"
                   name="reply_to"
                   value="<?= h($mail['reply_to']) ?>">
        </div>

        <button class="btn btn-primary" type="submit">
            設定保存
        </button>

    </form>

    </div>

    <div class="card">

        <div class="actions">

            <form method="post">
                <input type="hidden"
                       name="_csrf"
                       value="<?= h(csrf_token()) ?>">

                <input type="hidden"
                       name="action"
                       value="test_mail">

                <button class="btn" type="submit">
                    接続テスト
                </button>
            </form>

        </div>

        <hr style="border:0;border-top:1px solid var(--border);margin:20px 0">

        <h2>テストメール送信</h2>

        <form method="post">

            <input type="hidden"
                   name="_csrf"
                   value="<?= h(csrf_token()) ?>">

            <input type="hidden"
                   name="action"
                   value="send_test_mail">

            <div class="field">
                <label>送信先</label>
                <input type="email"
                       name="test_to"
                       required>
            </div>

            <button class="btn btn-primary"
                    type="submit">
                テストメール送信
            </button>

        </form>

        <p class="help">
            接続状態：
            <?= h($mail['status'] ?? '未設定') ?>
        </p>

    </div>

    <?php
    render_footer();
    exit;
}

/* ---------- 補助関数 ---------- */

function answer_count(string $surveyId): int
{
    $count = 0;

    foreach (load_answers() as $answer) {
        if (($answer['surveyId'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

function datetime_local(string $value): string
{
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}

function question_editor_html(array $question): string
{
    $id = h($question['id']);

    ob_start();
    ?>

    <div class="question"
         draggable="true"
         data-question-id="<?= $id ?>">

        <div class="question-head">

            <span class="drag-handle">☰</span>

            <strong class="question-number">
                <?= h($question['number'] ?? '自動採番') ?>
            </strong>

            <button type="button"
                    class="btn btn-sm btn-danger"
                    onclick="deleteQuestion(this)">
                削除
            </button>

        </div>

        <div class="question-body">

            <div class="field">
                <label>質問文</label>

                <input class="question-text"
                       value="<?= h($question['text']) ?>">
            </div>

            <div class="grid grid-2">

                <div class="field">
                    <label>回答形式</label>

                    <select class="question-type"
                            onchange="changeQuestionType(this)">

                        <option value="single"
                            <?= $question['type'] === 'single'
                                ? 'selected'
                                : '' ?>>
                            単一選択
                        </option>

                        <option value="multiple"
                            <?= $question['type'] === 'multiple'
                                ? 'selected'
                                : '' ?>>
                            複数選択
                        </option>

                        <option value="text"
                            <?= $question['type'] === 'text'
                                ? 'selected'
                                : '' ?>>
                            自由記述
                        </option>

                    </select>
                </div>

                <div class="field">
                    <label>
                        <input type="checkbox"
                               class="question-required"
                            <?= !empty($question['required'])
                                ? 'checked'
                                : '' ?>>
                        必須
                    </label>
                </div>

            </div>

            <div class="options"
                 style="<?= $question['type'] === 'text'
                    ? 'display:none'
                    : '' ?>">

            <?php foreach ($question['options'] as $option): ?>

                <div class="option-row"
                     data-option-id="<?= h($option['id']) ?>">

                    <input class="option-label"
                           value="<?= h($option['label']) ?>">

                    <select class="option-next">

                        <option value="">
                            次の質問へ
                        </option>

                        <?php
                        foreach (
                            get_all_question_choices_for_editor()
                            as $choice
                        ):
                        ?>

                        <option
                            value="<?= h($choice['id']) ?>"
                            <?= ($option['nextQuestionId'] ?? '')
                                === $choice['id']
                                ? 'selected'
                                : '' ?>>
                            <?= h($choice['number'] . ' ' .
                                $choice['text']) ?>
                        </option>

                        <?php endforeach; ?>

                    </select>

                    <button type="button"
                            class="btn btn-sm"
                            onclick="this.parentElement.remove()">
                        削除
                    </button>

                </div>

            <?php endforeach; ?>

            </div>

            <button type="button"
                    class="btn btn-sm"
                    onclick="addOption(this)"
                    style="<?= $question['type'] === 'text'
                        ? 'display:none'
                        : '' ?>">
                ＋ 選択肢
            </button>

        </div>

    </div>

    <?php

    return (string)ob_get_clean();
}

function get_all_question_choices_for_editor(): array
{
    /*
     * PHP側では現在編集中アンケートを直接参照しないため、
     * JS側の再構築で実際の選択肢を補完する。
     */
    return [];
}
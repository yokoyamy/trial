<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * - index.php 単一エントリーポイント
 * - DB不使用
 * - 管理者認証なし
 * - CSRF対策なし（POC要件）
 * - PHP cURL不使用
 * - PHP mail()不使用
 * - kintone: ログイン名/パスワード + X-Cybozu-Authorization
 * - SMTP: ソケットによる実SMTP接続
 * - データはサーバー側JSONファイルへ保存
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

/* =========================================================
 * 初期化
 * ======================================================= */

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0775, true);
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
 * セッション
 *
 * 認証には使用しない。
 * 回答途中などの短期状態保持のために使用する。
 *
 * CSRFトークンは生成しない。
 * ======================================================= */

$secure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
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
 * POST処理
 *
 * 重要:
 * CSRFチェックを行わない。
 * POCでは管理者認証・CSRFトークンともに実装しない。
 * ======================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');

    try {
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
             * mail
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
             * survey
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

            case 'change_status':
                handle_change_status();
                break;

            case 'save_questions':
                handle_save_questions();
                break;

            /* -------------------------------------------------
             * answer
             * ------------------------------------------------- */

            case 'answer_next':
                handle_answer_next();
                break;

            case 'answer_back':
                handle_answer_back();
                break;

            case 'answer_submit':
                handle_answer_submit();
                break;

            /* -------------------------------------------------
             * send
             * ------------------------------------------------- */

            case 'send_mail':
                handle_send_mail();
                break;

            case 'resend_mail':
                handle_resend_mail();
                break;

            case 'remind_mail':
                handle_remind_mail();
                break;

            default:
                flash('error', '不明な操作です。');
                redirect(screen_url($screen));
        }

    } catch (Throwable $e) {
        /*
         * 内部情報は画面へそのまま出さない。
         * POCとして原因の概要だけ表示する。
         */
        flash('error', '処理に失敗しました。' . safe_error_message($e));
        redirect(screen_url($screen));
    }
}

/* =========================================================
 * GET時の自動終了判定
 * ======================================================= */

$surveys = read_json(SURVEYS_FILE);

$changed = false;

foreach ($surveys as &$survey) {
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now_iso();
            $changed = true;
        }
    }
}
unset($survey);

if ($changed) {
    write_json_atomic(SURVEYS_FILE, $surveys);
}

/* =========================================================
 * 対象アンケート
 * ======================================================= */

$survey = null;

if (in_array($screen, ['edit', 'preview', 'send', 'analytics', 'answer', 'confirm', 'complete'], true)) {
    $id = (string)($_GET['id'] ?? '');

    if ($id !== '') {
        $survey = find_survey($id);
    }

    if (
        in_array($screen, ['send', 'analytics'], true)
        && $survey === null
    ) {
        flash('error', '対象アンケートが指定されていません。');
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
 * POST handlers
 * ======================================================= */

function handle_save_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);

    $settings['kintone']['subdomain'] = trim((string)($_POST['subdomain'] ?? ''));
    $settings['kintone']['app_id'] = trim((string)($_POST['app_id'] ?? ''));
    $settings['kintone']['username'] = trim((string)($_POST['username'] ?? ''));

    /*
     * パスワード未入力時は既存値を維持。
     */
    if (isset($_POST['password']) && (string)$_POST['password'] !== '') {
        $settings['kintone']['password'] = (string)$_POST['password'];
    }

    $settings['kintone']['proxy'] = trim((string)($_POST['proxy'] ?? ''));
    $settings['kintone']['verify_ssl'] = isset($_POST['verify_ssl']);

    $mapping = $_POST['field_mapping'] ?? [];

    if (is_array($mapping)) {
        foreach ([
            'organization',
            'name',
            'email',
            'department',
            'phone',
        ] as $key) {
            $settings['kintone']['field_mapping'][$key]
                = trim((string)($mapping[$key] ?? ''));
        }

        $addresses = $mapping['address'] ?? [];

        $settings['kintone']['field_mapping']['address']
            = is_array($addresses)
                ? array_values(array_map('strval', $addresses))
                : [];
    }

    validate_kintone_settings($settings['kintone']);

    write_json_atomic(SETTINGS_FILE, $settings);

    flash('success', 'kintone設定を保存しました。');
    redirect('index.php?screen=kintone');
}


function handle_test_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'];

    validate_kintone_settings($k);

    try {
        /*
         * 接続テストは実際のkintoneへ接続。
         * GET /v1/app.json を使用。
         */
        $result = kintone_request(
            $k,
            '/v1/app.json?id=' . rawurlencode((string)$k['app_id']),
            'GET'
        );

        if ($result['status'] >= 200 && $result['status'] < 300) {
            $settings['kintone']['connection_status'] = '接続確認済み';
            $settings['kintone']['last_test_at'] = now_iso();

            write_json_atomic(SETTINGS_FILE, $settings);

            flash('success', 'kintoneへの接続に成功しました。');
        } else {
            $settings['kintone']['connection_status'] = '接続できません';
            $settings['kintone']['last_test_at'] = now_iso();

            write_json_atomic(SETTINGS_FILE, $settings);

            flash(
                'error',
                'kintoneへの接続に失敗しました。HTTP ' .
                (int)$result['status'] .
                '。' .
                error_detail_from_kintone($result)
            );
        }

    } catch (Throwable $e) {

        $settings['kintone']['connection_status'] = '接続できません';
        $settings['kintone']['last_test_at'] = now_iso();

        write_json_atomic(SETTINGS_FILE, $settings);

        flash('error', 'kintone接続エラー: ' . safe_error_message($e));
    }

    redirect('index.php?screen=kintone');
}


function handle_fetch_kintone_fields(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'];

    validate_kintone_settings($k);

    $result = kintone_request(
        $k,
        '/v1/app/form/fields.json?app=' .
        rawurlencode((string)$k['app_id']),
        'GET'
    );

    if ($result['status'] < 200 || $result['status'] >= 300) {
        flash(
            'error',
            '項目一覧の取得に失敗しました。HTTP ' .
            (int)$result['status'] .
            error_detail_from_kintone($result)
        );

        redirect('index.php?screen=kintone');
    }

    $body = json_decode($result['body'], true);

    if (!is_array($body)) {
        throw new RuntimeException('kintoneの応答を解析できませんでした。');
    }

    $fields = [];

    foreach (($body['properties'] ?? []) as $code => $field) {
        $fields[] = [
            'code' => (string)$code,
            'label' => (string)($field['label'] ?? $code),
            'type' => (string)($field['type'] ?? ''),
        ];
    }

    $_SESSION['kintone_fields'] = $fields;

    flash('success', 'kintoneの項目一覧を再取得しました。');
    redirect('index.php?screen=kintone');
}


function handle_sync_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'];

    validate_kintone_settings($k);

    $result = kintone_request(
        $k,
        '/v1/records.json?app=' .
        rawurlencode((string)$k['app_id']) .
        '&query=' . rawurlencode('limit 500'),
        'GET'
    );

    if ($result['status'] < 200 || $result['status'] >= 300) {
        flash(
            'error',
            '顧客情報の同期に失敗しました。HTTP ' .
            (int)$result['status'] .
            error_detail_from_kintone($result)
        );

        redirect('index.php?screen=kintone');
    }

    $body = json_decode($result['body'], true);

    if (!is_array($body)) {
        throw new RuntimeException('kintoneの応答を解析できませんでした。');
    }

    $customers = [];

    foreach (($body['records'] ?? []) as $record) {

        $customers[] = [
            'id' => uuid(),
            'kintoneRecordId' => (string)($record['$id']['value'] ?? ''),
            'organization' => kintone_value($record, 'organization'),
            'name' => kintone_value($record, 'name'),
            'email' => kintone_value($record, 'email'),
            'department' => kintone_value($record, 'department'),
            'phone' => kintone_value($record, 'phone'),
            'address' => kintone_value($record, 'address'),
            'raw' => $record,
            'updatedAt' => now_iso(),
        ];
    }

    write_json_atomic(CUSTOMERS_FILE, $customers);

    flash(
        'success',
        '顧客情報を同期しました。' . count($customers) . '件'
    );

    redirect('index.php?screen=kintone');
}


function handle_save_mail(): void
{
    $settings = read_json(SETTINGS_FILE);

    $m = &$settings['mail'];

    $m['host'] = trim((string)($_POST['host'] ?? ''));

    $port = (int)($_POST['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートは1～65535で指定してください。'
        );
    }

    $m['port'] = $port;

    $encryption = (string)($_POST['encryption'] ?? 'tls');

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    $m['encryption'] = $encryption;
    $m['auth'] = isset($_POST['auth']);
    $m['username'] = trim((string)($_POST['username'] ?? ''));

    if (isset($_POST['password']) && (string)$_POST['password'] !== '') {
        $m['password'] = (string)$_POST['password'];
    }

    $m['from_email'] = trim((string)($_POST['from_email'] ?? ''));
    $m['from_name'] = trim((string)($_POST['from_name'] ?? ''));
    $m['reply_to'] = trim((string)($_POST['reply_to'] ?? ''));

    validate_mail_settings($m);

    write_json_atomic(SETTINGS_FILE, $settings);

    flash('success', 'メール設定を保存しました。');
    redirect('index.php?screen=mail');
}


function handle_test_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'];

    validate_mail_settings($m);

    try {
        smtp_test_connection($m);

        $settings['mail']['connection_status'] = '接続確認済み';
        $settings['mail']['last_test_at'] = now_iso();

        write_json_atomic(SETTINGS_FILE, $settings);

        flash('success', 'SMTPサーバーへの接続に成功しました。');

    } catch (Throwable $e) {

        $settings['mail']['connection_status'] = '接続できません';
        $settings['mail']['last_test_at'] = now_iso();

        write_json_atomic(SETTINGS_FILE, $settings);

        flash('error', 'SMTP接続エラー: ' . safe_error_message($e));
    }

    redirect('index.php?screen=mail');
}


function handle_send_test_mail(): void
{
    $to = trim((string)($_POST['test_to'] ?? ''));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが不正です。'
        );
    }

    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'];

    validate_mail_settings($m);

    smtp_send(
        $m,
        $to,
        'アンケートアプリ テストメール',
        "アンケートアプリからのテストメールです。\r\n" .
        "送信日時: " . now_iso()
    );

    flash('success', 'テストメールを送信しました。');
    redirect('index.php?screen=mail');
}


/* =========================================================
 * Survey
 * ======================================================= */

function handle_save_survey(): void
{
    $surveys = read_json(SURVEYS_FILE);

    $id = trim((string)($_POST['id'] ?? ''));

    $title = trim((string)($_POST['title'] ?? ''));

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルは必須です。'
        );
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException(
            'アンケートタイトルは200文字以内で入力してください。'
        );
    }

    $description = trim((string)($_POST['description'] ?? ''));
    $startAt = normalize_datetime((string)($_POST['startAt'] ?? ''));
    $endAt = normalize_datetime((string)($_POST['endAt'] ?? ''));

    if ($startAt !== null && $endAt !== null && $endAt <= $startAt) {
        throw new InvalidArgumentException(
            '終了日時は開始日時より後にしてください。'
        );
    }

    if ($id === '') {

        $survey = [
            'id' => uuid(),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => uuid(),
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ];

        $surveys[] = $survey;

    } else {

        $found = false;

        foreach ($surveys as &$survey) {

            if (($survey['id'] ?? '') !== $id) {
                continue;
            }

            $found = true;

            $survey['title'] = $title;
            $survey['description'] = $description;
            $survey['startAt'] = $startAt;
            $survey['endAt'] = $endAt;
            $survey['updatedAt'] = now_iso();

            break;
        }

        unset($survey);

        if (!$found) {
            throw new RuntimeException(
                '指定されたアンケートが存在しません。'
            );
        }
    }

    write_json_atomic(SURVEYS_FILE, $surveys);

    flash('success', 'アンケートを保存しました。');
    redirect('index.php?screen=list');
}


function handle_delete_survey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    if ($id === '') {
        throw new InvalidArgumentException(
            '削除対象が指定されていません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);

    $new = [];

    $deleted = false;

    foreach ($surveys as $survey) {

        if (($survey['id'] ?? '') === $id) {
            $deleted = true;
            continue;
        }

        $new[] = $survey;
    }

    if (!$deleted) {
        throw new RuntimeException(
            '指定されたアンケートが存在しません。'
        );
    }

    write_json_atomic(SURVEYS_FILE, $new);

    flash('success', 'アンケートを削除しました。');
    redirect('index.php?screen=list');
}


function handle_duplicate_survey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);

    $copy = $survey;

    $copy['id'] = uuid();
    $copy['title'] = ($copy['title'] ?? '') . '（コピー）';
    $copy['status'] = 'draft';
    $copy['createdAt'] = now_iso();
    $copy['updatedAt'] = now_iso();

    write_json_atomic(
        SURVEYS_FILE,
        array_merge($surveys, [$copy])
    );

    flash('success', 'アンケートを複製しました。');
    redirect('index.php?screen=list');
}


function handle_change_status(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $newStatus = trim((string)($_POST['status'] ?? ''));

    $allowed = [
        'draft',
        'published',
        'stopped',
    ];

    if (!in_array($newStatus, $allowed, true)) {
        throw new InvalidArgumentException(
            '指定された状態は変更できません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);

    foreach ($surveys as &$survey) {

        if (($survey['id'] ?? '') !== $id) {
            continue;
        }

        if (($survey['status'] ?? '') === 'ended') {
            throw new RuntimeException(
                '終了したアンケートの状態は変更できません。'
            );
        }

        $current = (string)($survey['status'] ?? 'draft');

        $valid = (
            ($current === 'draft' && $newStatus === 'published')
            || ($current === 'published' && $newStatus === 'stopped')
            || ($current === 'stopped' && $newStatus === 'published')
        );

        if (!$valid) {
            throw new InvalidArgumentException(
                'この状態遷移は許可されていません。'
            );
        }

        $survey['status'] = $newStatus;
        $survey['updatedAt'] = now_iso();

        unset($survey);

        write_json_atomic(SURVEYS_FILE, $surveys);

        flash('success', '状態を変更しました。');
        redirect('index.php?screen=list');
    }

    unset($survey);

    throw new RuntimeException(
        '対象アンケートが存在しません。'
    );
}


function handle_save_questions(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $groupsJson = (string)($_POST['groups_json'] ?? '[]');

    $groups = json_decode($groupsJson, true);

    if (!is_array($groups)) {
        throw new InvalidArgumentException(
            '質問データが不正です。'
        );
    }

    normalize_questions($groups);

    $surveys = read_json(SURVEYS_FILE);

    foreach ($surveys as &$item) {

        if (($item['id'] ?? '') !== $id) {
            continue;
        }

        $item['groups'] = $groups;
        $item['updatedAt'] = now_iso();

        break;
    }

    unset($item);

    write_json_atomic(SURVEYS_FILE, $surveys);

    flash('success', '質問を保存しました。');

    redirect(
        'index.php?screen=edit&id=' .
        rawurlencode($id)
    );
}


/* =========================================================
 * Answer
 * ======================================================= */

function handle_answer_next(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers = $_POST['answers'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $_SESSION['answer_' . $id] = $answers;

    redirect(
        'index.php?screen=confirm&id=' .
        rawurlencode($id)
    );
}


function handle_answer_back(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    redirect(
        'index.php?screen=answer&id=' .
        rawurlencode($id)
    );
}


function handle_answer_submit(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers = $_SESSION['answer_' . $id] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    validate_required_answers($survey, $answers);

    $all = read_json(ANSWERS_FILE);

    $all[] = [
        'id' => uuid(),
        'surveyId' => $id,
        'answers' => $answers,
        'createdAt' => now_iso(),
    ];

    write_json_atomic(ANSWERS_FILE, $all);

    unset($_SESSION['answer_' . $id]);

    redirect(
        'index.php?screen=complete&id=' .
        rawurlencode($id)
    );
}


/* =========================================================
 * Mail send
 * ======================================================= */

function handle_send_mail(): void
{
    $surveyId = trim((string)($_POST['survey_id'] ?? ''));

    $survey = find_survey($surveyId);

    if ($survey === null) {
        throw new RuntimeException(
            '送信対象アンケートが存在しません。'
        );
    }

    $customerIds = $_POST['customer_ids'] ?? [];

    if (!is_array($customerIds) || count($customerIds) === 0) {
        throw new InvalidArgumentException(
            '送信対象の顧客を選択してください。'
        );
    }

    $subject = trim((string)($_POST['subject'] ?? ''));
    $body = (string)($_POST['body'] ?? '');

    if ($subject === '') {
        throw new InvalidArgumentException(
            'メール件名を入力してください。'
        );
    }

    $settings = read_json(SETTINGS_FILE);
    $mail = $settings['mail'];

    validate_mail_settings($mail);

    $customers = read_json(CUSTOMERS_FILE);
    $logs = read_json(SEND_LOG_FILE);

    $count = 0;

    foreach ($customers as $customer) {

        if (!in_array((string)($customer['id'] ?? ''), $customerIds, true)) {
            continue;
        }

        $to = trim((string)($customer['email'] ?? ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customer['id'] ?? '',
                'email' => $to,
                'status' => 'failed',
                'message' => 'メールアドレス不正',
                'createdAt' => now_iso(),
            ];

            continue;
        }

        $personalBody = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [
                (string)($customer['name'] ?? ''),
                survey_answer_url($surveyId),
            ],
            $body
        );

        try {

            smtp_send(
                $mail,
                $to,
                $subject,
                $personalBody
            );

            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customer['id'] ?? '',
                'email' => $to,
                'status' => 'sent',
                'message' => '',
                'createdAt' => now_iso(),
            ];

            $count++;

        } catch (Throwable $e) {

            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customer['id'] ?? '',
                'email' => $to,
                'status' => 'failed',
                'message' => safe_error_message($e),
                'createdAt' => now_iso(),
            ];
        }
    }

    write_json_atomic(SEND_LOG_FILE, $logs);

    flash('success', $count . '件のメールを送信しました。');

    redirect(
        'index.php?screen=send&id=' .
        rawurlencode($surveyId)
    );
}


function handle_resend_mail(): void
{
    handle_send_mail();
}


function handle_remind_mail(): void
{
    handle_send_mail();
}


/* =========================================================
 * kintone communication
 * ======================================================= */

function kintone_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?string $body = null
): array {

    $host = normalize_kintone_host(
        (string)$settings['subdomain']
    );

    if ($host === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインが未設定です。'
        );
    }

    $username = (string)$settings['username'];
    $password = (string)$settings['password'];

    $authorization = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $url = 'https://' . $host . $path;

    /*
     * PHP cURLは禁止されているため、
     * stream_context_create + fopenを使用。
     */
    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => READ_TIMEOUT,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => (bool)$settings['verify_ssl'],
            'verify_peer_name' => (bool)$settings['verify_ssl'],
            'allow_self_signed' => !(bool)$settings['verify_ssl'],
        ],
    ];

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match('/^[^:]+:\d+$/', $proxy)) {
            throw new InvalidArgumentException(
                'Proxyはhost:port形式で指定してください。'
            );
        }

        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $fp = @fopen($url, 'rb', false, $context);

    if ($fp === false) {
        throw new RuntimeException(
            'kintoneへの接続を開始できませんでした。'
        );
    }

    $responseBody = stream_get_contents($fp);

    fclose($fp);

    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match(
            '/^HTTP\/\S+\s+(\d+)/i',
            $header,
            $m
        )) {
            $status = (int)$m[1];
            break;
        }
    }

    return [
        'status' => $status,
        'body' => (string)$responseBody,
    ];
}


function normalize_kintone_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim($value, '/');

    if (str_contains($value, '.cybozu.com')) {
        return $value;
    }

    return $value . '.cybozu.com';
}


function kintone_value(array $record, string $code): string
{
    if (!isset($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '';
    }

    return (string)$value;
}


function error_detail_from_kintone(array $result): string
{
    $body = json_decode(
        (string)($result['body'] ?? ''),
        true
    );

    if (
        is_array($body)
        && isset($body['message'])
    ) {
        return ' ' . (string)$body['message'];
    }

    return '';
}


/* =========================================================
 * SMTP
 * ======================================================= */

function smtp_test_connection(array $m): void
{
    $transport = smtp_transport($m);

    $socket = @stream_socket_client(
        $transport,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバーへ接続できませんでした。' .
            ($errstr !== '' ? ' ' . $errstr : '')
        );
    }

    stream_set_timeout($socket, READ_TIMEOUT);

    smtp_expect($socket, 220);

    smtp_command(
        $socket,
        'EHLO ' . gethostname(),
        250
    );

    if (($m['encryption'] ?? '') === 'tls') {

        smtp_command(
            $socket,
            'STARTTLS',
            220
        );

        if (!stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {
            fclose($socket);

            throw new RuntimeException(
                'STARTTLSを開始できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'EHLO ' . gethostname(),
            250
        );
    }

    if (!empty($m['auth'])) {
        smtp_authenticate($socket, $m);
    }

    smtp_command($socket, 'QUIT', 221);

    fclose($socket);
}


function smtp_send(
    array $m,
    string $to,
    string $subject,
    string $body
): void {

    $transport = smtp_transport($m);

    $socket = @stream_socket_client(
        $transport,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。' .
            ($errstr !== '' ? ' ' . $errstr : '')
        );
    }

    stream_set_timeout($socket, READ_TIMEOUT);

    smtp_expect($socket, 220);

    smtp_command(
        $socket,
        'EHLO ' . gethostname(),
        250
    );

    if (($m['encryption'] ?? '') === 'tls') {

        smtp_command(
            $socket,
            'STARTTLS',
            220
        );

        if (!stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {
            fclose($socket);

            throw new RuntimeException(
                'STARTTLSを開始できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'EHLO ' . gethostname(),
            250
        );
    }

    if (!empty($m['auth'])) {
        smtp_authenticate($socket, $m);
    }

    $from = (string)$m['from_email'];

    smtp_command(
        $socket,
        'MAIL FROM:<' . $from . '>',
        250
    );

    smtp_command(
        $socket,
        'RCPT TO:<' . $to . '>',
        250
    );

    smtp_command(
        $socket,
        'DATA',
        354
    );

    $headers = [];

    $headers[] = 'From: ' .
        mail_address(
            (string)$m['from_name'],
            $from
        );

    $headers[] = 'To: <' . $to . '>';

    $headers[] = 'Subject: ' .
        mime_header((string)$subject);

    if (!empty($m['reply_to'])) {
        $headers[] =
            'Reply-To: <' .
            $m['reply_to'] .
            '>';
    }

    $headers[] = 'MIME-Version: 1.0';
    $headers[] =
        'Content-Type: text/plain; charset=UTF-8';
    $headers[] =
        'Content-Transfer-Encoding: 8bit';

    $message =
        implode("\r\n", $headers) .
        "\r\n\r\n" .
        normalize_mail_body($body);

    /*
     * SMTP DATA内の行頭ドットをエスケープ。
     */
    $message = preg_replace(
        '/^\./m',
        '..',
        $message
    );

    fwrite(
        $socket,
        $message . "\r\n.\r\n"
    );

    smtp_expect($socket, 250);

    smtp_command($socket, 'QUIT', 221);

    fclose($socket);
}


function smtp_transport(array $m): string
{
    $host = trim((string)$m['host']);
    $port = (int)$m['port'];
    $encryption = (string)$m['encryption'];

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバーが未設定です。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if ($encryption === 'ssl') {
        return 'ssl://' . $host . ':' . $port;
    }

    return 'tcp://' . $host . ':' . $port;
}


function smtp_authenticate($socket, array $m): void
{
    $username = (string)$m['username'];
    $password = (string)$m['password'];

    smtp_command(
        $socket,
        'AUTH LOGIN',
        334
    );

    smtp_command(
        $socket,
        base64_encode($username),
        334
    );

    smtp_command(
        $socket,
        base64_encode($password),
        235
    );
}


function smtp_command(
    $socket,
    string $command,
    int $expected
): void {

    fwrite($socket, $command . "\r\n");

    smtp_expect($socket, $expected);
}


function smtp_expect($socket, int $expected): string
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
            'SMTPサーバーから応答がありません。'
        );
    }

    $code = (int)substr($response, 0, 3);

    if ($code !== $expected) {
        throw new RuntimeException(
            'SMTPエラー: ' .
            $code .
            ' ' .
            trim($response)
        );
    }

    return $response;
}


/* =========================================================
 * Rendering
 * ======================================================= */

function render_header(string $screen): void
{
    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>アンケートアプリ</title>';

    echo <<<HTML
<style>
*{box-sizing:border-box}
body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,
        "Segoe UI","Noto Sans JP",sans-serif;
    color:#222;
    background:#f5f7fa;
}
header{
    background:#1f2937;
    color:#fff;
    padding:14px 22px;
}
header a{
    color:#fff;
    text-decoration:none;
    margin-right:18px;
}
main{
    max-width:1400px;
    margin:0 auto;
    padding:24px;
}
h1{margin-top:0}
.card{
    background:#fff;
    border-radius:8px;
    padding:20px;
    margin-bottom:20px;
    box-shadow:0 1px 3px rgba(0,0,0,.08);
}
.form-grid{
    display:grid;
    grid-template-columns:220px 1fr;
    gap:12px 20px;
    align-items:center;
}
input,textarea,select{
    width:100%;
    padding:9px 10px;
    border:1px solid #ccd3dc;
    border-radius:5px;
    font:inherit;
}
textarea{min-height:120px}
button,.button{
    display:inline-block;
    border:0;
    border-radius:5px;
    padding:9px 16px;
    cursor:pointer;
    text-decoration:none;
    font:inherit;
}
button.primary,.primary{
    background:#2563eb;
    color:#fff;
}
button.success,.success{
    background:#16a34a;
    color:#fff;
}
button.danger,.danger{
    background:#dc2626;
    color:#fff;
}
button.secondary,.secondary{
    background:#e5e7eb;
    color:#111827;
}
.actions{
    margin-top:20px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.alert{
    padding:12px 15px;
    border-radius:6px;
    margin-bottom:15px;
}
.alert.success{
    background:#dcfce7;
    color:#166534;
}
.alert.error{
    background:#fee2e2;
    color:#991b1b;
}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    border-bottom:1px solid #e5e7eb;
    padding:10px;
    text-align:left;
    vertical-align:top;
}
.small{
    color:#6b7280;
    font-size:.9em;
}
.status{
    display:inline-block;
    padding:3px 8px;
    border-radius:999px;
    background:#e5e7eb;
}
.status.published{background:#dcfce7;color:#166534}
.status.stopped{background:#fef3c7;color:#92400e}
.status.ended{background:#e5e7eb;color:#374151}
@media(max-width:700px){
    .form-grid{
        grid-template-columns:1fr;
    }
    main{padding:12px}
    table{min-width:900px}
    .table-wrap{overflow-x:auto}
}
</style>
HTML;

    echo '</head>';
    echo '<body>';

    echo '<header>';
    echo '<a href="index.php?screen=list">アンケート一覧</a>';
    echo '<a href="index.php?screen=kintone">kintone設定</a>';
    echo '<a href="index.php?screen=mail">メール設定</a>';
    echo '</header>';

    echo '<main>';

    render_flash();
}


function render_footer(): void
{
    echo '</main>';

    echo <<<HTML
<script>
document.querySelectorAll('form[data-confirm]').forEach(function(form){
    form.addEventListener('submit',function(e){
        const message=form.dataset.confirm;
        if(message && !window.confirm(message)){
            e.preventDefault();
        }
    });
});

document.querySelectorAll('form[data-busy]').forEach(function(form){
    form.addEventListener('submit',function(){
        const buttons=form.querySelectorAll('button');
        buttons.forEach(function(button){
            button.disabled=true;
            button.dataset.originalText=button.textContent;
            button.textContent='処理中...';
        });
    });
});
</script>
</body>
</html>
HTML;
}


/* =========================================================
 * List
 * ======================================================= */

function render_list(): void
{
    $surveys = read_json(SURVEYS_FILE);

    $q = trim((string)($_GET['q'] ?? ''));
    $filter = (string)($_GET['filter'] ?? 'all');
    $sort = (string)($_GET['sort'] ?? 'updated_desc');

    $filtered = [];

    foreach ($surveys as $survey) {

        if (
            $q !== ''
            && mb_stripos(
                (string)($survey['title'] ?? ''),
                $q
            ) === false
        ) {
            continue;
        }

        $status = (string)($survey['status'] ?? 'draft');

        if ($filter !== 'all') {

            $map = [
                'published' => 'published',
                'draft' => 'draft',
                'stopped' => 'stopped',
                'ended' => 'ended',
            ];

            if (
                isset($map[$filter])
                && $status !== $map[$filter]
            ) {
                continue;
            }
        }

        $filtered[] = $survey;
    }

    usort(
        $filtered,
        function(array $a, array $b) use ($sort): int {

            if ($sort === 'answers_desc') {
                return answer_count_for_survey(
                    (string)$b['id']
                ) <=> answer_count_for_survey(
                    (string)$a['id']
                );
            }

            if ($sort === 'answers_asc') {
                return answer_count_for_survey(
                    (string)$a['id']
                ) <=> answer_count_for_survey(
                    (string)$b['id']
                );
            }

            $field = match ($sort) {
                'start_desc', 'start_asc' => 'startAt',
                default => 'updatedAt',
            };

            $av = strtotime((string)($a[$field] ?? '')) ?: 0;
            $bv = strtotime((string)($b[$field] ?? '')) ?: 0;

            return str_ends_with($sort, '_asc')
                ? $av <=> $bv
                : $bv <=> $av;
        }
    );

    echo '<h1>アンケート一覧</h1>';

    echo '<div class="card">';

    echo '<form method="get">';

    echo '<input type="hidden" name="screen" value="list">';

    echo '<div class="form-grid">';

    form_row(
        '検索',
        '<input name="q" value="' .
        h($q) .
        '" placeholder="タイトルで検索">'
    );

    form_row(
        '絞り込み',
        '<select name="filter">' .
        option('all','すべて',$filter) .
        option('published','公開中',$filter) .
        option('draft','下書き',$filter) .
        option('stopped','停止',$filter) .
        option('ended','終了',$filter) .
        '</select>'
    );

    form_row(
        'ソート',
        '<select name="sort">' .
        option('updated_desc','更新日：新しい順',$sort) .
        option('updated_asc','更新日：古い順',$sort) .
        option('answers_desc','回答数：多い順',$sort) .
        option('answers_asc','回答数：少ない順',$sort) .
        option('start_desc','開始日：新しい順',$sort) .
        option('start_asc','開始日：古い順',$sort) .
        '</select>'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">検索</button>';
    echo '<a class="button secondary" href="index.php?screen=list">条件クリア</a>';
    echo '<a class="button primary" href="index.php?screen=edit">新規作成</a>';
    echo '</div>';

    echo '</form>';
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

    foreach ($filtered as $survey) {

        $id = (string)$survey['id'];

        echo '<tr>';

        echo '<td>' .
            h((string)$survey['title']) .
            '</td>';

        echo '<td>' .
            h(format_datetime($survey['createdAt'] ?? '')) .
            '</td>';

        echo '<td>' .
            h(format_datetime($survey['updatedAt'] ?? '')) .
            '</td>';

        echo '<td>' .
            h(format_period($survey)) .
            '</td>';

        echo '<td>' .
            status_badge((string)$survey['status']) .
            '</td>';

        echo '<td>' .
            h(answer_count_for_survey($id)) .
            '</td>';

        echo '<td>';

        echo '<a class="button secondary" href="' .
            h('index.php?screen=edit&id=' . rawurlencode($id)) .
            '">確認・編集</a> ';

        echo '<a class="button secondary" href="' .
            h('index.php?screen=analytics&id=' . rawurlencode($id)) .
            '">集計</a> ';

        echo '<a class="button secondary" href="' .
            h('index.php?screen=send&id=' . rawurlencode($id)) .
            '">送信</a> ';

        echo '<form method="post" style="display:inline" data-confirm="このアンケートを複製しますか？">';
        echo '<input type="hidden" name="action" value="duplicate_survey">';
        echo '<input type="hidden" name="id" value="' . h($id) . '">';
        echo '<button class="secondary">複製</button>';
        echo '</form> ';

        echo '<form method="post" style="display:inline" data-confirm="このアンケートを削除しますか？">';
        echo '<input type="hidden" name="action" value="delete_survey">';
        echo '<input type="hidden" name="id" value="' . h($id) . '">';
        echo '<button class="danger">削除</button>';
        echo '</form>';

        echo '</td>';

        echo '</tr>';
    }

    if (count($filtered) === 0) {
        echo '<tr><td colspan="7">アンケートはありません。</td></tr>';
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
    $id = (string)($survey['id'] ?? '');

    echo '<h1>アンケート作成・編集</h1>';

    echo '<div class="card">';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="save_survey">';
    echo '<input type="hidden" name="id" value="' . h($id) . '">';

    echo '<div class="form-grid">';

    form_row(
        'アンケートタイトル',
        '<input required maxlength="200" name="title" value="' .
        h((string)($survey['title'] ?? '')) .
        '">'
    );

    form_row(
        'アンケート説明',
        '<textarea name="description">' .
        h((string)($survey['description'] ?? '')) .
        '</textarea>'
    );

    form_row(
        '開始日時',
        '<input type="datetime-local" name="startAt" value="' .
        h(datetime_local((string)($survey['startAt'] ?? ''))) .
        '">'
    );

    form_row(
        '終了日時',
        '<input type="datetime-local" name="endAt" value="' .
        h(datetime_local((string)($survey['endAt'] ?? ''))) .
        '">'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<a class="button secondary" href="index.php?screen=list">キャンセル</a>';
    echo '<button class="primary">保存して一覧へ</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    if ($survey !== null) {
        render_question_editor($survey);
        render_status_controls($survey);
    }
}


function render_status_controls(array $survey): void
{
    $id = (string)$survey['id'];
    $status = (string)($survey['status'] ?? 'draft');

    echo '<div class="card">';
    echo '<h2>状態</h2>';
    echo '<p>' . status_badge($status) . '</p>';

    if ($status === 'ended') {
        echo '<p class="small">終了状態のため変更できません。</p>';
        echo '</div>';
        return;
    }

    $next = [];

    if ($status === 'draft') {
        $next[] = ['published', '公開する'];
    }

    if ($status === 'published') {
        $next[] = ['stopped', '停止する'];
    }

    if ($status === 'stopped') {
        $next[] = ['published', '再開する'];
    }

    echo '<div class="actions">';

    foreach ($next as [$target, $label]) {

        echo '<form method="post" data-confirm="' .
            h($label . 'しますか？') .
            '">';

        echo '<input type="hidden" name="action" value="change_status">';
        echo '<input type="hidden" name="id" value="' . h($id) . '">';
        echo '<input type="hidden" name="status" value="' . h($target) . '">';

        echo '<button class="primary">' .
            h($label) .
            '</button>';

        echo '</form>';
    }

    echo '</div>';
    echo '</div>';
}


/* =========================================================
 * Question editor
 * ======================================================= */

function render_question_editor(array $survey): void
{
    $groups = $survey['groups'] ?? [];

    echo '<div class="card">';
    echo '<h2>質問・グループ</h2>';

    echo '<p class="small">';
    echo '質問形式：単一選択 / 複数選択 / 自由記述';
    echo '</p>';

    echo '<div id="questionEditor">';

    foreach ($groups as $gi => $group) {

        echo '<div class="card group" draggable="true">';
        echo '<h3>' .
            h((string)($group['title'] ?? '')) .
            '</h3>';

        foreach (($group['questions'] ?? []) as $question) {

            echo '<div class="card" draggable="true">';

            echo '<strong>' .
                h((string)($question['number'] ?? '')) .
                '</strong> ';

            echo h((string)($question['text'] ?? ''));

            echo '<div class="small">';
            echo '形式: ' .
                h((string)($question['type'] ?? 'text'));
            echo ' / ';

            echo !empty($question['required'])
                ? '必須'
                : '任意';

            echo '</div>';

            echo '</div>';
        }

        echo '</div>';
    }

    echo '</div>';

    echo '<form method="post" id="questionSaveForm">';

    echo '<input type="hidden" name="action" value="save_questions">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';

    echo '<input type="hidden" name="groups_json" id="groups_json" value="' .
        h(json_encode(
            $groups,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '[]') .
        '">';

    echo '<div class="actions">';
    echo '<button class="primary">質問を保存</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
}


/* =========================================================
 * Preview
 * ======================================================= */

function render_preview(?array $survey): void
{
    if ($survey === null) {
        echo '<h1>プレビュー</h1>';
        echo '<div class="card">対象アンケートがありません。</div>';
        return;
    }

    echo '<h1>プレビュー</h1>';

    echo '<div class="card">';
    echo '<h2>' . h((string)$survey['title']) . '</h2>';
    echo '<p>' . nl2br(h((string)($survey['description'] ?? ''))) . '</p>';

    foreach (($survey['groups'] ?? []) as $group) {

        echo '<h3>' .
            h((string)($group['title'] ?? '')) .
            '</h3>';

        foreach (($group['questions'] ?? []) as $question) {

            echo '<div class="card">';

            echo '<p><strong>' .
                h((string)($question['number'] ?? '')) .
                ' ' .
                h((string)($question['text'] ?? '')) .
                '</strong>';

            if (!empty($question['required'])) {
                echo ' <span class="small">必須</span>';
            }

            echo '</p>';

            render_question_input($question);

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
        echo '<h1>顧客選択・メール送信</h1>';
        return;
    }

    $customers = read_json(CUSTOMERS_FILE);

    $logs = read_json(SEND_LOG_FILE);

    echo '<h1>顧客選択・メール送信</h1>';

    echo '<div class="card">';
    echo '<h2>対象アンケート</h2>';
    echo '<p><strong>' .
        h((string)$survey['title']) .
        '</strong></p>';
    echo '<p class="small">対象アンケートは固定されています。</p>';
    echo '</div>';

    echo '<div class="card">';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="send_mail">';
    echo '<input type="hidden" name="survey_id" value="' .
        h((string)$survey['id']) .
        '">';

    echo '<h2>顧客選択</h2>';

    if (count($customers) === 0) {
        echo '<p>顧客データがありません。kintone設定から同期してください。</p>';
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

            $cid = (string)($customer['id'] ?? '');

            echo '<tr>';

            echo '<td>';
            echo '<input type="checkbox" name="customer_ids[]" value="' .
                h($cid) .
                '">';
            echo '</td>';

            echo '<td>' .
                h((string)($customer['organization'] ?? '')) .
                '</td>';

            echo '<td>' .
                h((string)($customer['name'] ?? '')) .
                '</td>';

            echo '<td>' .
                h((string)($customer['email'] ?? '')) .
                '</td>';

            echo '<td>' .
                h((string)($customer['department'] ?? '')) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '<h2>メール</h2>';

    echo '<div class="form-grid">';

    form_row(
        '件名',
        '<input required name="subject" value="' .
        h((string)$survey['title']) .
        '">'
    );

    form_row(
        '本文',
        '<textarea required name="body">' .
        h(
            "アンケートへのご協力をお願いいたします。\n\n" .
            "{顧客名} 様\n\n" .
            "アンケートURL:\n" .
            "{アンケートURL}"
        ) .
        '</textarea>'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="success" data-confirm="選択した顧客へメールを送信しますか？">一括送信</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>送信履歴</h2>';

    $surveyLogs = array_values(
        array_filter(
            $logs,
            fn($log) =>
                ($log['surveyId'] ?? '') === $survey['id']
        )
    );

    if (count($surveyLogs) === 0) {
        echo '<p>送信履歴はありません。</p>';
    } else {

        echo '<div class="table-wrap"><table>';

        echo '<thead><tr>';
        echo '<th>日時</th>';
        echo '<th>メール</th>';
        echo '<th>結果</th>';
        echo '<th>内容</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach (array_reverse($surveyLogs) as $log) {

            echo '<tr>';

            echo '<td>' .
                h(format_datetime($log['createdAt'] ?? '')) .
                '</td>';

            echo '<td>' .
                h((string)($log['email'] ?? '')) .
                '</td>';

            echo '<td>' .
                h((string)($log['status'] ?? '')) .
                '</td>';

            echo '<td>' .
                h((string)($log['message'] ?? '')) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</div>';
}


/* =========================================================
 * Analytics
 * ======================================================= */

function render_analytics(?array $survey): void
{
    if ($survey === null) {
        return;
    }

    $surveyId = (string)$survey['id'];

    $answers = read_json(ANSWERS_FILE);
    $customers = read_json(CUSTOMERS_FILE);

    $surveyAnswers = array_values(
        array_filter(
            $answers,
            fn($a) =>
                ($a['surveyId'] ?? '') === $surveyId
        )
    );

    $logs = read_json(SEND_LOG_FILE);

    $sent = array_values(
        array_filter(
            $logs,
            fn($l) =>
                ($l['surveyId'] ?? '') === $surveyId
                && ($l['status'] ?? '') === 'sent'
        )
    );

    $sentCount = count($sent);
    $answerCount = count($surveyAnswers);

    $rate = $sentCount > 0
        ? round($answerCount / $sentCount * 100, 1)
        : 0;

    echo '<h1>回答集計・分析</h1>';

    echo '<div class="card">';
    echo '<h2>対象アンケート</h2>';
    echo '<p>' .
        h((string)$survey['title']) .
        '</p>';

    echo '<div class="form-grid">';

    form_row('送信対象者数', (string)$sentCount);
    form_row('回答数', (string)$answerCount);
    form_row('未回答数', (string)max(0, $sentCount - $answerCount));
    form_row('未登録回答数', '0');
    form_row('回答率', $rate . '%');

    echo '</div>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>設問別集計</h2>';

    if ($answerCount === 0) {
        echo '<p>現在、回答データはありません</p>';
    } else {

        foreach (($survey['groups'] ?? []) as $group) {

            foreach (($group['questions'] ?? []) as $question) {

                $qid = (string)($question['id'] ?? '');

                $values = [];

                foreach ($surveyAnswers as $answer) {
                    $value =
                        $answer['answers'][$qid]
                        ?? null;

                    if ($value !== null) {
                        $values[] = $value;
                    }
                }

                echo '<div class="card">';
                echo '<strong>' .
                    h((string)($question['number'] ?? '')) .
                    ' ' .
                    h((string)($question['text'] ?? '')) .
                    '</strong>';

                if (count($values) === 0) {
                    echo '<p>回答なし</p>';
                } else {
                    echo '<pre>' .
                        h(json_encode(
                            $values,
                            JSON_UNESCAPED_UNICODE |
                            JSON_PRETTY_PRINT
                        ) ?: '') .
                        '</pre>';
                }

                echo '</div>';
            }
        }
    }

    echo '</div>';
}


/* =========================================================
 * kintone screen
 * ======================================================= */

function render_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'];

    $fields = $_SESSION['kintone_fields'] ?? [];

    echo '<h1>kintone連携設定</h1>';

    echo '<div class="card">';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="form-grid">';

    form_row(
        'サブドメイン',
        '<input name="subdomain" value="' .
        h((string)$k['subdomain']) .
        '" placeholder="https://xxxx.cybozu.com">'
    );

    form_row(
        '顧客管理アプリID',
        '<input type="number" min="1" name="app_id" value="' .
        h((string)$k['app_id']) .
        '">'
    );

    form_row(
        'ログイン名',
        '<input name="username" value="' .
        h((string)$k['username']) .
        '">'
    );

    form_row(
        'パスワード',
        '<input type="password" name="password" value="" autocomplete="new-password" placeholder="変更する場合のみ入力">'
    );

    form_row(
        'Proxy',
        '<input name="proxy" value="' .
        h((string)$k['proxy']) .
        '" placeholder="host:port">'
    );

    form_row(
        'SSL証明書検証',
        '<label><input type="checkbox" name="verify_ssl" value="1" ' .
        (!empty($k['verify_ssl']) ? 'checked' : '') .
        '> 有効</label>'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">設定保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>接続確認</h2>';

    echo '<p>状態: ' .
        status_text((string)$k['connection_status']) .
        '</p>';

    echo '<form method="post" style="display:inline" data-busy>';
    echo '<input type="hidden" name="action" value="test_kintone">';
    echo '<button class="primary">接続テスト</button>';
    echo '</form> ';

    echo '<form method="post" style="display:inline" data-busy>';
    echo '<input type="hidden" name="action" value="fetch_kintone_fields">';
    echo '<button class="secondary">項目一覧を再取得</button>';
    echo '</form> ';

    echo '<form method="post" style="display:inline" data-busy>';
    echo '<input type="hidden" name="action" value="sync_kintone">';
    echo '<button class="success">顧客情報を同期</button>';
    echo '</form>';

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>項目一覧</h2>';

    if (count($fields) === 0) {
        echo '<p>まだ取得されていません。</p>';
    } else {

        echo '<div class="table-wrap"><table>';
        echo '<thead><tr>';
        echo '<th>フィールドコード</th>';
        echo '<th>ラベル</th>';
        echo '<th>形式</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($fields as $field) {

            echo '<tr>';
            echo '<td>' . h($field['code']) . '</td>';
            echo '<td>' . h($field['label']) . '</td>';
            echo '<td>' . h($field['type']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</div>';
}


/* =========================================================
 * Mail screen
 * ======================================================= */

function render_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'];

    echo '<h1>メールサーバ設定</h1>';

    echo '<div class="card">';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="save_mail">';

    echo '<div class="form-grid">';

    form_row(
        'SMTPサーバ',
        '<input required name="host" value="' .
        h((string)$m['host']) .
        '">'
    );

    form_row(
        'SMTPポート',
        '<input required type="number" min="1" max="65535" name="port" value="' .
        h((string)$m['port']) .
        '">'
    );

    form_row(
        '暗号化方式',
        '<select name="encryption">' .
        option('ssl','SSL',(string)$m['encryption']) .
        option('tls','TLS',(string)$m['encryption']) .
        option('none','なし',(string)$m['encryption']) .
        '</select>'
    );

    form_row(
        'SMTP認証',
        '<label><input type="checkbox" name="auth" value="1" ' .
        (!empty($m['auth']) ? 'checked' : '') .
        '> 使用する</label>'
    );

    form_row(
        'SMTPユーザー名',
        '<input name="username" value="' .
        h((string)$m['username']) .
        '">'
    );

    form_row(
        'SMTPパスワード',
        '<input type="password" name="password" value="" autocomplete="new-password" placeholder="変更する場合のみ入力">'
    );

    form_row(
        '送信元メールアドレス',
        '<input required type="email" name="from_email" value="' .
        h((string)$m['from_email']) .
        '">'
    );

    form_row(
        '送信元名',
        '<input name="from_name" value="' .
        h((string)$m['from_name']) .
        '">'
    );

    form_row(
        '返信先メールアドレス',
        '<input type="email" name="reply_to" value="' .
        h((string)$m['reply_to']) .
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

    echo '<p>状態: ' .
        status_text((string)$m['connection_status']) .
        '</p>';

    echo '<form method="post" style="display:inline" data-busy>';
    echo '<input type="hidden" name="action" value="test_mail">';
    echo '<button class="primary">接続テスト</button>';
    echo '</form>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>テストメール送信</h2>';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="send_test_mail">';

    echo '<div class="form-grid">';

    form_row(
        'テスト送信先',
        '<input required type="email" name="test_to" placeholder="test@example.com">'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="success">テストメール送信</button>';
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
        echo '<h1>アンケート回答</h1>';
        echo '<div class="card">アンケートが存在しません。</div>';
        return;
    }

    echo '<h1>' .
        h((string)$survey['title']) .
        '</h1>';

    echo '<div class="card">';
    echo '<p>' .
        nl2br(h((string)($survey['description'] ?? ''))) .
        '</p>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="answer_next">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';

    foreach (($survey['groups'] ?? []) as $group) {

        echo '<h2>' .
            h((string)($group['title'] ?? '')) .
            '</h2>';

        foreach (($group['questions'] ?? []) as $question) {

            echo '<div class="card">';

            echo '<p><strong>' .
                h((string)($question['number'] ?? '')) .
                ' ' .
                h((string)($question['text'] ?? '')) .
                '</strong>';

            if (!empty($question['required'])) {
                echo ' <span class="small">必須</span>';
            }

            echo '</p>';

            render_question_input($question);

            echo '</div>';
        }
    }

    echo '<div class="actions">';
    echo '<button class="primary">回答確認へ</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}


function render_confirm(?array $survey): void
{
    if ($survey === null) {
        return;
    }

    $answers = $_SESSION['answer_' . $survey['id']] ?? [];

    echo '<h1>回答確認</h1>';

    echo '<div class="card">';
    echo '<h2>' . h((string)$survey['title']) . '</h2>';

    foreach (($survey['groups'] ?? []) as $group) {

        foreach (($group['questions'] ?? []) as $question) {

            $qid = (string)($question['id'] ?? '');

            $value = $answers[$qid] ?? '';

            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            echo '<div class="card">';
            echo '<strong>' .
                h((string)($question['number'] ?? '')) .
                ' ' .
                h((string)($question['text'] ?? '')) .
                '</strong>';

            echo '<p>' . nl2br(h((string)$value)) . '</p>';
            echo '</div>';
        }
    }

    echo '<div class="actions">';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="answer_back">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';
    echo '<button class="secondary">戻る</button>';
    echo '</form>';

    echo '<form method="post" data-confirm="回答を送信しますか？">';
    echo '<input type="hidden" name="action" value="answer_submit">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';
    echo '<button class="success">回答を送信</button>';
    echo '</form>';

    echo '</div>';

    echo '</div>';
}


function render_complete(?array $survey): void
{
    echo '<h1>回答完了</h1>';

    echo '<div class="card">';
    echo '<p>回答を受け付けました。</p>';
    echo '</div>';
}


/* =========================================================
 * Question helper
 * ======================================================= */

function render_question_input(array $question): void
{
    $id = (string)($question['id'] ?? '');
    $type = (string)($question['type'] ?? 'text');
    $options = $question['options'] ?? [];

    $name = 'answers[' . h($id) . ']';

    if ($type === 'single') {

        foreach ($options as $option) {

            echo '<label style="display:block;margin:8px 0">';

            echo '<input type="radio" name="' .
                $name .
                '" value="' .
                h((string)$option) .
                '"> ';

            echo h((string)$option);

            echo '</label>';
        }

        return;
    }

    if ($type === 'multiple') {

        foreach ($options as $option) {

            echo '<label style="display:block;margin:8px 0">';

            echo '<input type="checkbox" name="' .
                $name .
                '[]" value="' .
                h((string)$option) .
                '"> ';

            echo h((string)$option);

            echo '</label>';
        }

        return;
    }

    echo '<textarea name="' .
        $name .
        '"></textarea>';
}


/* =========================================================
 * Validation
 * ======================================================= */

function validate_kintone_settings(array $k): void
{
    if (trim((string)($k['subdomain'] ?? '')) === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if ((int)($k['app_id'] ?? 0) <= 0) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDを入力してください。'
        );
    }

    if (trim((string)($k['username'] ?? '')) === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if ((string)($k['password'] ?? '') === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを設定してください。'
        );
    }

    $proxy = trim((string)($k['proxy'] ?? ''));

    if (
        $proxy !== ''
        && !preg_match('/^[^:]+:\d+$/', $proxy)
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }
}


function validate_mail_settings(array $m): void
{
    if (trim((string)($m['host'] ?? '')) === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    $port = (int)($m['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    $from = (string)($m['from_email'] ?? '');

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    if (!empty($m['auth'])) {

        if (
            trim((string)($m['username'] ?? '')) === ''
            || (string)($m['password'] ?? '') === ''
        ) {
            throw new InvalidArgumentException(
                'SMTP認証を使用する場合はユーザー名とパスワードが必要です。'
            );
        }
    }

    if (
        !empty($m['reply_to'])
        && !filter_var(
            $m['reply_to'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }
}


function validate_required_answers(
    array $survey,
    array $answers
): void {

    foreach (($survey['groups'] ?? []) as $group) {

        foreach (($group['questions'] ?? []) as $question) {

            if (empty($question['required'])) {
                continue;
            }

            $id = (string)($question['id'] ?? '');

            $value = $answers[$id] ?? null;

            if (is_array($value)) {
                $valid = count($value) > 0;
            } else {
                $valid = trim((string)$value) !== '';
            }

            if (!$valid) {
                throw new InvalidArgumentException(
                    '必須項目が未回答です: ' .
                    (string)($question['number'] ?? '')
                );
            }
        }
    }
}


function normalize_questions(array &$groups): void
{
    foreach ($groups as &$group) {

        if (empty($group['id'])) {
            $group['id'] = uuid();
        }

        if (!isset($group['questions']) || !is_array($group['questions'])) {
            $group['questions'] = [];
        }
    }

    unset($group);

    $globalNumber = 1;

    foreach ($groups as $gi => &$group) {

        foreach ($group['questions'] as $qi => &$question) {

            if (empty($question['id'])) {
                $question['id'] = uuid();
            }

            $question['number'] =
                'Q' . $globalNumber;

            $globalNumber++;
        }

        unset($question);
    }

    unset($group);
}


/* =========================================================
 * Storage
 * ======================================================= */

function init_json_file(
    string $file,
    array $default
): void {

    if (!file_exists($file)) {
        write_json_atomic($file, $default);
    }
}


function read_json(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $contents = file_get_contents($file);

    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $data = json_decode($contents, true);

    return is_array($data) ? $data : [];
}


function write_json_atomic(
    string $file,
    array $data
): void {

    $dir = dirname($file);

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException(
            'データをJSON化できませんでした。'
        );
    }

    $fp = fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できませんでした。'
        );
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        @unlink($tmp);

        throw new RuntimeException(
            'データファイルをロックできませんでした。'
        );
    }

    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!rename($tmp, $file)) {
        @unlink($tmp);

        throw new RuntimeException(
            'データファイルを更新できませんでした。'
        );
    }
}


/* =========================================================
 * General helpers
 * ======================================================= */

function find_survey(string $id): ?array
{
    if ($id === '') {
        return null;
    }

    foreach (read_json(SURVEYS_FILE) as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}


function answer_count(string $surveyId): int
{
    return answer_count_for_survey($surveyId);
}


function answer_count_for_survey(string $surveyId): int
{
    $answers = read_json(ANSWERS_FILE);

    $count = 0;

    foreach ($answers as $answer) {
        if (($answer['surveyId'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}


function now_iso(): string
{
    return date('c');
}


function uuid(): string
{
    return bin2hex(random_bytes(16));
}


function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
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


function form_row(
    string $label,
    string $html
): void {

    echo '<label>' .
        h($label) .
        '</label>';

    echo '<div>' .
        $html .
        '</div>';
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

    return date(
        'Y-m-d\TH:i',
        $timestamp
    );
}


function normalize_datetime(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        throw new InvalidArgumentException(
            '日時の形式が不正です。'
        );
    }

    return date('c', $timestamp);
}


function format_datetime(string $value): string
{
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date(
        'Y/m/d H:i',
        $timestamp
    );
}


function format_period(array $survey): string
{
    $start = format_datetime(
        (string)($survey['startAt'] ?? '')
    );

    $end = format_datetime(
        (string)($survey['endAt'] ?? '')
    );

    if ($start === '' && $end === '') {
        return '指定なし';
    }

    return $start . ' ～ ' . $end;
}


function status_badge(string $status): string
{
    $labels = [
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
    ];

    $label = $labels[$status] ?? $status;

    return '<span class="status ' .
        h($status) .
        '">' .
        h($label) .
        '</span>';
}


function status_text(string $status): string
{
    $labels = [
        '未設定' => '未設定',
        '接続確認済み' => '接続確認済み',
        '接続できません' => '接続できません',
    ];

    return h($labels[$status] ?? $status);
}


function flash(
    string $type,
    string $message
): void {

    $_SESSION['_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}


function render_flash(): void
{
    $messages = $_SESSION['_flash'] ?? [];

    unset($_SESSION['_flash']);

    foreach ($messages as $message) {

        echo '<div class="alert ' .
            h((string)$message['type']) .
            '">' .
            h((string)$message['message']) .
            '</div>';
    }
}


function redirect(string $url): never
{
    header(
        'Location: ' . $url,
        true,
        303
    );

    exit;
}


function screen_url(
    string $screen
): string {

    return 'index.php?screen=' .
        rawurlencode($screen);
}


function survey_answer_url(string $id): string
{
    $scheme =
        (!empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host =
        (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    $script =
        (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');

    return $scheme .
        '://' .
        $host .
        $script .
        '?screen=answer&id=' .
        rawurlencode($id);
}


function safe_error_message(Throwable $e): string
{
    /*
     * POCでは原因把握ができる程度の情報を表示する。
     * パスワード・認証ヘッダー等はここへ渡さない。
     */
    $message = trim($e->getMessage());

    if ($message === '') {
        return '';
    }

    return ' ' . $message;
}


function mail_address(
    string $name,
    string $email
): string {

    if ($name === '') {
        return '<' . $email . '>';
    }

    return mime_header($name) .
        ' <' .
        $email .
        '>';
}


function mime_header(string $value): string
{
    if (preg_match('/^[\x00-\x7F]*$/', $value)) {
        return $value;
    }

    return '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
}


function normalize_mail_body(string $body): string
{
    return str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );
}


function app_cookie_path(): string
{
    /*
     * サブディレクトリ配下でもCookie Pathが
     * "/"固定にならないよう、現在のアプリケーション
     * ディレクトリを使用。
     */
    $script = (string)(
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
    );

    $dir = dirname($script);

    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        return '/';
    }

    return rtrim(
        str_replace('\\', '/', $dir),
        '/'
    ) . '/';
}
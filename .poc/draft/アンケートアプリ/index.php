<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * prompt.txt 再生成版
 *
 * 実行環境:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLなし
 *
 * 重要:
 *   - CSRFなし（prompt.txt指定）
 *   - 管理者認証なし（POC）
 *   - PHP mail()なし
 *   - PHP cURLなし
 *   - kintone API Tokenなし
 *   - X-Cybozu-Authorizationをサーバー側のみで生成
 *   - 外部通信はsocket
 *   - サーバー側JSON永続化
 *   - GETごとのsession_regenerate_id()禁止
 *   - 認証を理由としたリダイレクトなし
 *   - 外部サービス接続結果をモックで返さない
 */

date_default_timezone_set('Asia/Tokyo');

const APP_NAME = 'アンケートアプリ';

const DATA_DIR       = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const ANSWERS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'answers.json';
const SEND_LOG_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'send_logs.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT    = 20;
const MAX_HTTP_BODY   = 10 * 1024 * 1024;

const STATUS_DRAFT     = 'draft';
const STATUS_PUBLISHED = 'published';
const STATUS_STOPPED   = 'stopped';
const STATUS_ENDED     = 'ended';

const SCREEN_LIST      = 'list';
const SCREEN_EDIT      = 'edit';
const SCREEN_PREVIEW   = 'preview';
const SCREEN_SEND      = 'send';
const SCREEN_ANALYTICS = 'analytics';
const SCREEN_KINTONE   = 'kintone';
const SCREEN_MAIL      = 'mail';
const SCREEN_ANSWER    = 'answer';
const SCREEN_CONFIRM   = 'confirm';
const SCREEN_COMPLETE  = 'complete';

const ACTION_SAVE_SURVEY      = 'save_survey';
const ACTION_DELETE_SURVEY    = 'delete_survey';
const ACTION_DUPLICATE_SURVEY = 'duplicate_survey';
const ACTION_CHANGE_STATUS    = 'change_status';

const ACTION_SAVE_KINTONE     = 'save_kintone';
const ACTION_TEST_KINTONE     = 'test_kintone';
const ACTION_FETCH_KINTONE    = 'fetch_kintone_fields';
const ACTION_SYNC_KINTONE     = 'sync_kintone';

const ACTION_SAVE_MAIL        = 'save_mail';
const ACTION_TEST_MAIL        = 'test_mail';
const ACTION_SEND_TEST_MAIL   = 'send_test_mail';

const ACTION_SEND_MAIL        = 'send_mail';
const ACTION_RESEND_MAIL      = 'resend_mail';
const ACTION_REMIND_MAIL      = 'remind_mail';

const ACTION_SAVE_QUESTIONS   = 'save_questions';

const ACTION_ANSWER_NEXT      = 'answer_next';
const ACTION_ANSWER_BACK      = 'answer_back';
const ACTION_ANSWER_SUBMIT    = 'answer_submit';

const ACTION_EXPORT_CSV       = 'export_csv';
const ACTION_EXPORT_PDF       = 'export_pdf';

const ENCRYPTION_NONE = 'none';
const ENCRYPTION_TLS  = 'tls';
const ENCRYPTION_SSL  = 'ssl';

const STATUS_LABELS = [
    STATUS_DRAFT     => '下書き',
    STATUS_PUBLISHED => '公開中',
    STATUS_STOPPED   => '停止',
    STATUS_ENDED     => '終了',
];

const RESPONSE_TYPE_LABELS = [
    'single' => '単一選択',
    'multi'  => '複数選択',
    'text'   => '自由記述',
];

/* ============================================================
 * 初期化
 * ============================================================ */

ensure_data_directory();

init_json_file(SETTINGS_FILE, default_settings());
init_json_file(SURVEYS_FILE, []);
init_json_file(CUSTOMERS_FILE, []);
init_json_file(ANSWERS_FILE, []);
init_json_file(SEND_LOG_FILE, []);

start_application_session();

$screen = normalize_screen((string)($_GET['screen'] ?? SCREEN_LIST));

/* ============================================================
 * POST
 * ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        switch ($action) {
            case ACTION_SAVE_SURVEY:
                save_survey();
                break;

            case ACTION_DELETE_SURVEY:
                delete_survey();
                break;

            case ACTION_DUPLICATE_SURVEY:
                duplicate_survey();
                break;

            case ACTION_CHANGE_STATUS:
                change_status();
                break;

            case ACTION_SAVE_KINTONE:
                save_kintone();
                break;

            case ACTION_TEST_KINTONE:
                test_kintone();
                break;

            case ACTION_FETCH_KINTONE:
                fetch_kintone_fields();
                break;

            case ACTION_SYNC_KINTONE:
                sync_kintone();
                break;

            case ACTION_SAVE_MAIL:
                save_mail();
                break;

            case ACTION_TEST_MAIL:
                test_mail();
                break;

            case ACTION_SEND_TEST_MAIL:
                send_test_mail();
                break;

            case ACTION_SEND_MAIL:
                send_survey_mail();
                break;

            case ACTION_RESEND_MAIL:
                resend_mail();
                break;

            case ACTION_REMIND_MAIL:
                remind_mail();
                break;

            case ACTION_SAVE_QUESTIONS:
                save_questions();
                break;

            case ACTION_ANSWER_NEXT:
                answer_next();
                break;

            case ACTION_ANSWER_BACK:
                answer_back();
                break;

            case ACTION_ANSWER_SUBMIT:
                answer_submit();
                break;

            case ACTION_EXPORT_CSV:
                export_csv();
                break;

            case ACTION_EXPORT_PDF:
                export_pdf();
                break;

            default:
                throw new InvalidArgumentException('不明な操作です。');
        }
    } catch (Throwable $e) {
        if (is_export_action($action)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo '出力に失敗しました。';
            exit;
        }

        flash('error', user_error_message($e));

        /*
         * 外部接続テスト等についても、
         * ユーザーが再送信しないGET画面へ戻す。
         *
         * ただし成功/失敗の結果そのものはflashに保存する。
         */
        redirect_for_action($action);
    }
}

/* ============================================================
 * 自動終了判定
 * ============================================================ */

auto_end_surveys();

/* ============================================================
 * 対象アンケート
 * ============================================================ */

$survey = null;

if (in_array(
    $screen,
    [
        SCREEN_EDIT,
        SCREEN_PREVIEW,
        SCREEN_SEND,
        SCREEN_ANALYTICS,
        SCREEN_ANSWER,
        SCREEN_CONFIRM,
        SCREEN_COMPLETE,
    ],
    true
)) {
    $id = trim((string)($_GET['id'] ?? ''));

    if ($id !== '') {
        $survey = find_survey($id);
    }

    if (
        in_array($screen, [SCREEN_SEND, SCREEN_ANALYTICS], true)
        && $survey === null
    ) {
        flash('error', '対象アンケートが指定されていません。');
        redirect('index.php?screen=list');
    }
}

/* ============================================================
 * 画面
 * ============================================================ */

if (in_array(
    $screen,
    [SCREEN_ANSWER, SCREEN_CONFIRM, SCREEN_COMPLETE],
    true
)) {
    render_answer_shell($screen, $survey);
    exit;
}

render_admin_header($screen);

switch ($screen) {
    case SCREEN_LIST:
        render_list();
        break;

    case SCREEN_EDIT:
        render_edit($survey);
        break;

    case SCREEN_PREVIEW:
        render_preview($survey);
        break;

    case SCREEN_SEND:
        render_send($survey);
        break;

    case SCREEN_ANALYTICS:
        render_analytics($survey);
        break;

    case SCREEN_KINTONE:
        render_kintone();
        break;

    case SCREEN_MAIL:
        render_mail();
        break;

    default:
        render_list();
        break;
}

render_admin_footer();
exit;


/* ============================================================
 * 共通
 * ============================================================ */

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
            'last_fetch_at' => null,
            'last_sync_at' => null,
            'fields' => [],
        ],
        'mail' => [
            'host' => '',
            'port' => 587,
            'encryption' => ENCRYPTION_TLS,
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

function ensure_data_directory(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

function init_json_file(string $file, array $default): void
{
    if (is_file($file)) {
        return;
    }

    write_json_atomic($file, $default);
}

function read_json(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException('保存データを読み込めません。');
    }

    return $data;
}

function write_json_atomic(string $file, array $data): void
{
    $dir = dirname($file);

    $tmp = tempnam($dir, 'survey_');

    if ($tmp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        );

        if (
            file_put_contents(
                $tmp,
                $json . PHP_EOL,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException('データを書き込めません。');
        }

        if (!rename($tmp, $file)) {
            throw new RuntimeException('データを保存できません。');
        }

        $tmp = '';
    } finally {
        if ($tmp !== '' && is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function start_application_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    );

    /*
     * アプリケーションの公開ディレクトリに合わせる。
     *
     * 日本語物理パスをREQUEST_URIからそのままCookie Pathに
     * 使用しない。
     */
    $cookiePath = '/';

    if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(
            0,
            $cookiePath,
            '',
            $secure,
            true
        );
    }

    session_name('questionnaire_poc_session');
    session_start();
}

function now_iso(): string
{
    return date('c');
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $value = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);

    return is_array($value) ? $value : null;
}

function user_error_message(Throwable $e): string
{
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }

    if ($e instanceof RuntimeException) {
        return $e->getMessage();
    }

    return '処理に失敗しました。サーバー側で処理を完了できませんでした。';
}

function is_export_action(string $action): bool
{
    return in_array(
        $action,
        [ACTION_EXPORT_CSV, ACTION_EXPORT_PDF],
        true
    );
}

/* ============================================================
 * URL / screen
 * ============================================================ */

function allowed_screens(): array
{
    return [
        SCREEN_LIST,
        SCREEN_EDIT,
        SCREEN_PREVIEW,
        SCREEN_SEND,
        SCREEN_ANALYTICS,
        SCREEN_KINTONE,
        SCREEN_MAIL,
        SCREEN_ANSWER,
        SCREEN_CONFIRM,
        SCREEN_COMPLETE,
    ];
}

function normalize_screen(string $screen): string
{
    return in_array($screen, allowed_screens(), true)
        ? $screen
        : SCREEN_LIST;
}

function screen_url(string $screen, ?string $id = null): string
{
    $screen = normalize_screen($screen);

    $url = 'index.php?screen=' . rawurlencode($screen);

    if ($id !== null && $id !== '') {
        $url .= '&id=' . rawurlencode($id);
    }

    return $url;
}

function redirect(string $path): never
{
    /*
     * ユーザー入力をLocationへ直接入れない。
     */
    if (
        !str_starts_with($path, 'index.php')
        || str_contains($path, "\r")
        || str_contains($path, "\n")
        || str_contains($path, '://')
    ) {
        $path = 'index.php?screen=list';
    }

    header(
        'Cache-Control: no-store, no-cache, must-revalidate',
        true
    );
    header('Pragma: no-cache');
    header('Location: ' . $path, true, 303);
    exit;
}

function redirect_for_action(string $action): never
{
    switch ($action) {
        case ACTION_SAVE_KINTONE:
        case ACTION_TEST_KINTONE:
        case ACTION_FETCH_KINTONE:
        case ACTION_SYNC_KINTONE:
            redirect('index.php?screen=kintone');

        case ACTION_SAVE_MAIL:
        case ACTION_TEST_MAIL:
        case ACTION_SEND_TEST_MAIL:
            redirect('index.php?screen=mail');

        case ACTION_SAVE_SURVEY:
        case ACTION_DELETE_SURVEY:
        case ACTION_DUPLICATE_SURVEY:
        case ACTION_CHANGE_STATUS:
            redirect('index.php?screen=list');

        case ACTION_SEND_MAIL:
        case ACTION_RESEND_MAIL:
        case ACTION_REMIND_MAIL:
            $id = trim((string)($_POST['survey_id'] ?? ''));
            redirect(screen_url(SCREEN_SEND, $id));

        case ACTION_SAVE_QUESTIONS:
            $id = trim((string)($_POST['survey_id'] ?? ''));
            redirect(screen_url(SCREEN_EDIT, $id));

        case ACTION_ANSWER_NEXT:
        case ACTION_ANSWER_BACK:
        case ACTION_ANSWER_SUBMIT:
            $id = trim((string)($_POST['survey_id'] ?? ''));
            redirect(screen_url(SCREEN_ANSWER, $id));

        default:
            redirect('index.php?screen=list');
    }
}

/* ============================================================
 * Survey
 * ============================================================ */

function find_survey(string $id): ?array
{
    foreach (read_json(SURVEYS_FILE) as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_status_label(string $status): string
{
    return STATUS_LABELS[$status] ?? $status;
}

function auto_end_surveys(): void
{
    $surveys = read_json(SURVEYS_FILE);
    $changed = false;

    foreach ($surveys as &$survey) {
        if (
            ($survey['status'] ?? '') === STATUS_PUBLISHED
            && !empty($survey['endAt'])
        ) {
            $timestamp = strtotime((string)$survey['endAt']);

            if ($timestamp !== false && $timestamp < time()) {
                $survey['status'] = STATUS_ENDED;
                $survey['updatedAt'] = now_iso();
                $changed = true;
            }
        }
    }

    unset($survey);

    if ($changed) {
        write_json_atomic(SURVEYS_FILE, $surveys);
    }
}

function normalize_question(array $q): array
{
    $type = (string)($q['type'] ?? 'text');

    if (!array_key_exists($type, RESPONSE_TYPE_LABELS)) {
        $type = 'text';
    }

    $options = [];

    foreach (($q['options'] ?? []) as $option) {
        if (is_string($option)) {
            $options[] = [
                'value' => $option,
                'label' => $option,
                'next' => '',
            ];
        } elseif (is_array($option)) {
            $options[] = [
                'value' => (string)($option['value'] ?? uuid()),
                'label' => (string)($option['label'] ?? ''),
                'next' => (string)($option['next'] ?? ''),
            ];
        }
    }

    return [
        'id' => (string)($q['id'] ?? uuid()),
        'number' => (string)($q['number'] ?? ''),
        'text' => (string)($q['text'] ?? ''),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $options,
    ];
}

function normalize_group(array $group): array
{
    $questions = [];

    foreach (($group['questions'] ?? []) as $question) {
        if (is_array($question)) {
            $questions[] = normalize_question($question);
        }
    }

    return [
        'id' => (string)($group['id'] ?? uuid()),
        'title' => (string)($group['title'] ?? ''),
        'questions' => $questions,
    ];
}

function normalize_survey(array $survey): array
{
    $groups = [];

    foreach (($survey['groups'] ?? []) as $group) {
        if (is_array($group)) {
            $groups[] = normalize_group($group);
        }
    }

    return [
        'id' => (string)($survey['id'] ?? uuid()),
        'title' => (string)($survey['title'] ?? ''),
        'description' => (string)($survey['description'] ?? ''),
        'startAt' => (string)($survey['startAt'] ?? ''),
        'endAt' => (string)($survey['endAt'] ?? ''),
        'status' => (string)($survey['status'] ?? STATUS_DRAFT),
        'numbering' => (
            (string)($survey['numbering'] ?? 'global') === 'group'
            ? 'group'
            : 'global'
        ),
        'createdAt' => (string)($survey['createdAt'] ?? now_iso()),
        'updatedAt' => (string)($survey['updatedAt'] ?? now_iso()),
        'groups' => $groups,
    ];
}

function renumber_survey(array &$survey): void
{
    $survey['groups'] = array_values($survey['groups'] ?? []);

    if (($survey['numbering'] ?? 'global') === 'group') {
        foreach ($survey['groups'] as $gi => &$group) {
            $group['questions'] = array_values(
                $group['questions'] ?? []
            );

            foreach ($group['questions'] as $qi => &$question) {
                $question['number'] =
                    'Q' . ($gi + 1) . '-' . ($qi + 1);
            }

            unset($question);
        }

        unset($group);
        return;
    }

    $number = 1;

    foreach ($survey['groups'] as &$group) {
        $group['questions'] = array_values(
            $group['questions'] ?? []
        );

        foreach ($group['questions'] as &$question) {
            $question['number'] = 'Q' . $number;
            $number++;
        }

        unset($question);
    }

    unset($group);
}

function survey_response_count(string $surveyId): int
{
    $answers = read_json(ANSWERS_FILE);
    $count = 0;

    foreach ($answers as $answer) {
        if (
            (string)($answer['survey_id'] ?? '') === $surveyId
            && !empty($answer['completed'])
        ) {
            $count++;
        }
    }

    return $count;
}

function save_survey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    $title = trim((string)($_POST['title'] ?? ''));

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

    $description = (string)($_POST['description'] ?? '');
    $startAt = trim((string)($_POST['startAt'] ?? ''));
    $endAt = trim((string)($_POST['endAt'] ?? ''));

    validate_datetime_input($startAt, '開始日時');
    validate_datetime_input($endAt, '終了日時');

    if ($startAt !== '' && $endAt !== '') {
        $start = strtotime($startAt);
        $end = strtotime($endAt);

        if ($start !== false && $end !== false && $end <= $start) {
            throw new InvalidArgumentException(
                '終了日時は開始日時より後にしてください。'
            );
        }
    }

    $numbering = (
        (string)($_POST['numbering'] ?? 'global') === 'group'
        ? 'group'
        : 'global'
    );

    $surveys = read_json(SURVEYS_FILE);
    $now = now_iso();

    if ($id === '') {
        $survey = normalize_survey([
            'id' => uuid(),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => STATUS_DRAFT,
            'numbering' => $numbering,
            'createdAt' => $now,
            'updatedAt' => $now,
            'groups' => [
                [
                    'id' => uuid(),
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
        ]);

        renumber_survey($survey);
        $surveys[] = $survey;
    } else {
        $found = false;

        foreach ($surveys as &$item) {
            if ((string)($item['id'] ?? '') !== $id) {
                continue;
            }

            $found = true;

            $item = normalize_survey($item);
            $item['title'] = $title;
            $item['description'] = $description;
            $item['startAt'] = $startAt;
            $item['endAt'] = $endAt;
            $item['numbering'] = $numbering;
            $item['updatedAt'] = $now;

            renumber_survey($item);
            break;
        }

        unset($item);

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

function validate_datetime_input(
    string $value,
    string $label
): void {
    if ($value === '') {
        return;
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        throw new InvalidArgumentException(
            $label . 'の形式が不正です。'
        );
    }
}

function delete_survey(): void
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
        if ((string)($survey['id'] ?? '') === $id) {
            $deleted = true;
            continue;
        }

        $new[] = $survey;
    }

    if (!$deleted) {
        throw new RuntimeException(
            '削除対象のアンケートが存在しません。'
        );
    }

    write_json_atomic(SURVEYS_FILE, $new);

    flash('success', 'アンケートを削除しました。');
    redirect('index.php?screen=list');
}

function duplicate_survey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);

    $copy = normalize_survey($survey);
    $copy['id'] = uuid();
    $copy['title'] .= '（コピー）';
    $copy['status'] = STATUS_DRAFT;
    $copy['createdAt'] = now_iso();
    $copy['updatedAt'] = now_iso();

    $surveys[] = $copy;

    write_json_atomic(SURVEYS_FILE, $surveys);

    flash('success', 'アンケートを複製しました。');
    redirect('index.php?screen=list');
}

function change_status(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $newStatus = trim((string)($_POST['status'] ?? ''));

    if (!in_array(
        $newStatus,
        [STATUS_DRAFT, STATUS_PUBLISHED, STATUS_STOPPED],
        true
    )) {
        throw new InvalidArgumentException(
            '指定された状態へ変更できません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);
    $found = false;

    foreach ($surveys as &$survey) {
        if ((string)($survey['id'] ?? '') !== $id) {
            continue;
        }

        $found = true;

        if (($survey['status'] ?? '') === STATUS_ENDED) {
            throw new InvalidArgumentException(
                '終了したアンケートの状態は変更できません。'
            );
        }

        $survey['status'] = $newStatus;
        $survey['updatedAt'] = now_iso();
        break;
    }

    unset($survey);

    if (!$found) {
        throw new RuntimeException(
            '指定されたアンケートが存在しません。'
        );
    }

    write_json_atomic(SURVEYS_FILE, $surveys);

    flash('success', 'アンケート状態を変更しました。');
    redirect('index.php?screen=list');
}

function save_questions(): void
{
    $surveyId = trim((string)($_POST['survey_id'] ?? ''));

    if ($surveyId === '') {
        throw new InvalidArgumentException(
            '対象アンケートが指定されていません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);
    $found = false;

    $groupsRaw = $_POST['groups'] ?? [];

    if (!is_array($groupsRaw)) {
        $groupsRaw = [];
    }

    $groups = [];

    foreach ($groupsRaw as $groupRaw) {
        if (!is_array($groupRaw)) {
            continue;
        }

        $group = [
            'id' => trim((string)($groupRaw['id'] ?? '')) ?: uuid(),
            'title' => trim((string)($groupRaw['title'] ?? '')),
            'questions' => [],
        ];

        $questionsRaw = $groupRaw['questions'] ?? [];

        if (!is_array($questionsRaw)) {
            $questionsRaw = [];
        }

        foreach ($questionsRaw as $questionRaw) {
            if (!is_array($questionRaw)) {
                continue;
            }

            $question = normalize_question([
                'id' => $questionRaw['id'] ?? uuid(),
                'text' => trim((string)($questionRaw['text'] ?? '')),
                'type' => $questionRaw['type'] ?? 'text',
                'required' => !empty($questionRaw['required']),
                'options' => $questionRaw['options'] ?? [],
            ]);

            if ($question['text'] === '') {
                throw new InvalidArgumentException(
                    '質問文を入力してください。'
                );
            }

            $group['questions'][] = $question;
        }

        $groups[] = $group;
    }

    foreach ($surveys as &$survey) {
        if ((string)($survey['id'] ?? '') !== $surveyId) {
            continue;
        }

        $found = true;
        $survey = normalize_survey($survey);
        $survey['groups'] = $groups;
        $survey['updatedAt'] = now_iso();

        renumber_survey($survey);
        break;
    }

    unset($survey);

    if (!$found) {
        throw new RuntimeException(
            '対象アンケートが存在しません。'
        );
    }

    write_json_atomic(SURVEYS_FILE, $surveys);

    flash('success', '質問構成を保存しました。');
    redirect(screen_url(SCREEN_EDIT, $surveyId));
}

/* ============================================================
 * kintone 設定
 * ============================================================ */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = trim($value, "/ \t\r\n");

    if (str_contains($value, '/')) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    if (str_ends_with(
        strtolower($value),
        '.cybozu.com'
    )) {
        $value = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    if (
        $value === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $value
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    return $value;
}

function validate_kintone(array $k): void
{
    normalize_kintone_subdomain(
        (string)($k['subdomain'] ?? '')
    );

    $appId = trim((string)($k['app_id'] ?? ''));

    if ($appId === '' || !ctype_digit($appId)) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    if (trim((string)($k['username'] ?? '')) === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if ((string)($k['password'] ?? '') === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    $proxy = trim((string)($k['proxy'] ?? ''));

    if ($proxy !== '') {
        validate_proxy($proxy);
    }
}

function validate_proxy(string $proxy): void
{
    if (
        !preg_match(
            '/^([A-Za-z0-9.-]+):([0-9]{1,5})$/',
            $proxy,
            $m
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'Proxyのポート番号が不正です。'
        );
    }
}

function save_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);

    if (!isset($settings['kintone'])) {
        $settings['kintone'] = default_settings()['kintone'];
    }

    $k = &$settings['kintone'];

    $k['subdomain'] = normalize_kintone_subdomain(
        (string)($_POST['subdomain'] ?? '')
    );

    $k['app_id'] = trim(
        (string)($_POST['app_id'] ?? '')
    );

    if (
        $k['app_id'] === ''
        || !ctype_digit($k['app_id'])
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $k['username'] = trim(
        (string)($_POST['username'] ?? '')
    );

    if (isset($_POST['password'])) {
        $password = (string)$_POST['password'];

        /*
         * 空欄の場合は既存パスワードを保持。
         */
        if ($password !== '') {
            $k['password'] = $password;
        }
    }

    $k['proxy'] = trim(
        (string)($_POST['proxy'] ?? '')
    );

    if ($k['proxy'] !== '') {
        validate_proxy($k['proxy']);
    }

    $k['verify_ssl'] = isset($_POST['verify_ssl']);

    /*
     * 保存内容をサーバー側で再検証してから保存。
     */
    validate_kintone($k);

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirect('index.php?screen=kintone');
}

function test_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    validate_kintone($k);

    $result = kintone_request(
        $k,
        '/k/v1/app.json?id='
        . rawurlencode((string)$k['app_id']),
        'GET'
    );

    $settings['kintone']['last_test_at'] = now_iso();

    if (
        $result['status'] >= 200
        && $result['status'] < 300
    ) {
        $settings['kintone']['connection_status']
            = '接続確認済み';

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'success',
            'kintoneへの接続に成功しました。'
            . ' アプリ: '
            . kintone_response_app_name($result)
        );

        redirect('index.php?screen=kintone');
    }

    $settings['kintone']['connection_status']
        = '接続できません';

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    throw new RuntimeException(
        'kintone接続に失敗しました。'
        . ' HTTP '
        . $result['status']
        . ': '
        . kintone_error_detail($result)
    );
}

function kintone_response_app_name(array $result): string
{
    $json = json_decode(
        (string)($result['body'] ?? ''),
        true
    );

    if (
        is_array($json)
        && isset($json['name'])
    ) {
        return (string)$json['name'];
    }

    return '取得成功';
}

function fetch_kintone_fields(): void
{
    $settings = read_json(SETTINGS_FILE);
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
            'kintone項目一覧の取得に失敗しました。'
            . ' HTTP '
            . $result['status']
            . ': '
            . kintone_error_detail($result)
        );
    }

    $json = json_decode(
        (string)$result['body'],
        true
    );

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintoneから不正なJSON応答を受信しました。'
        );
    }

    $properties = $json['properties'] ?? [];

    if (!is_array($properties)) {
        throw new RuntimeException(
            'kintone項目情報を解釈できません。'
        );
    }

    $fields = [];

    foreach ($properties as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' => (string)($field['label'] ?? ''),
            'type' => (string)($field['type'] ?? ''),
        ];
    }

    $settings['kintone']['fields'] = $fields;
    $settings['kintone']['last_fetch_at'] = now_iso();

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'kintone項目一覧を再取得しました。'
        . ' ' . count($fields) . '項目です。'
    );

    redirect('index.php?screen=kintone');
}

function sync_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    validate_kintone($k);

    $appId = rawurlencode((string)$k['app_id']);

    $mapping = $k['field_mapping'] ?? [];

    $fields = [
        'organization' => (string)($mapping['organization'] ?? ''),
        'name' => (string)($mapping['name'] ?? ''),
        'email' => (string)($mapping['email'] ?? ''),
        'department' => (string)($mapping['department'] ?? ''),
        'phone' => (string)($mapping['phone'] ?? ''),
    ];

    $addressFields = $mapping['address'] ?? [];

    if (!is_array($addressFields)) {
        $addressFields = [];
    }

    $query = '';

    $result = kintone_request(
        $k,
        '/k/v1/records.json?app='
        . $appId
        . '&totalCount=true'
        . ($query !== ''
            ? '&query=' . rawurlencode($query)
            : ''),
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        throw new RuntimeException(
            'kintone顧客情報の取得に失敗しました。'
            . ' HTTP '
            . $result['status']
            . ': '
            . kintone_error_detail($result)
        );
    }

    $json = json_decode(
        (string)$result['body'],
        true
    );

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintone顧客情報のJSON応答を解釈できません。'
        );
    }

    $records = $json['records'] ?? [];

    if (!is_array($records)) {
        throw new RuntimeException(
            'kintoneレコード情報が不正です。'
        );
    }

    /*
     * 100件を超える場合はoffsetで取得。
     */
    $allRecords = $records;
    $offset = count($records);

    while (count($records) >= 500) {
        $result = kintone_request(
            $k,
            '/k/v1/records.json?app='
            . $appId
            . '&totalCount=false'
            . '&query='
            . rawurlencode(
                'limit 500 offset ' . $offset
            ),
            'GET'
        );

        if (
            $result['status'] < 200
            || $result['status'] >= 300
        ) {
            throw new RuntimeException(
                'kintone顧客情報の追加取得に失敗しました。'
                . ' HTTP '
                . $result['status']
                . ': '
                . kintone_error_detail($result)
            );
        }

        $json = json_decode(
            (string)$result['body'],
            true
        );

        $records = (
            is_array($json)
            && is_array($json['records'] ?? null)
        )
            ? $json['records']
            : [];

        foreach ($records as $record) {
            $allRecords[] = $record;
        }

        $offset += count($records);

        if (count($records) === 0) {
            break;
        }
    }

    $customers = [];

    foreach ($allRecords as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => uuid(),
            'source' => 'kintone',
            'organization' => mapped_kintone_value(
                $record,
                $fields['organization']
            ),
            'name' => mapped_kintone_value(
                $record,
                $fields['name']
            ),
            'email' => mapped_kintone_value(
                $record,
                $fields['email']
            ),
            'department' => mapped_kintone_value(
                $record,
                $fields['department']
            ),
            'phone' => mapped_kintone_value(
                $record,
                $fields['phone']
            ),
            'address' => mapped_kintone_address(
                $record,
                $addressFields
            ),
            'syncedAt' => now_iso(),
        ];
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    $settings['kintone']['last_sync_at'] = now_iso();
    $settings['kintone']['connection_status']
        = '接続確認済み';

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        '顧客情報を同期しました。'
        . ' ' . count($customers) . '件。'
    );

    redirect('index.php?screen=kintone');
}

function mapped_kintone_value(
    array $record,
    string $code
): string {
    if ($code === '') {
        return '';
    }

    $field = $record[$code] ?? null;

    if (!is_array($field)) {
        return '';
    }

    $value = $field['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] = (string)($item['name'] ?? '');
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(', ', array_filter($parts));
    }

    return (string)$value;
}

function mapped_kintone_address(
    array $record,
    array $codes
): string {
    $parts = [];

    foreach ($codes as $code) {
        $code = trim((string)$code);

        if ($code === '') {
            continue;
        }

        $value = mapped_kintone_value(
            $record,
            $code
        );

        if ($value !== '') {
            $parts[] = $value;
        }
    }

    return implode(' ', $parts);
}

function kintone_error_detail(array $result): string
{
    $body = trim((string)($result['body'] ?? ''));

    $json = json_decode($body, true);

    if (is_array($json)) {
        $message = trim((string)($json['message'] ?? ''));
        $id = trim((string)($json['id'] ?? ''));

        if ($message !== '') {
            return $message
                . ($id !== '' ? ' [' . $id . ']' : '');
        }
    }

    if ($body !== '') {
        /*
         * パスワード等を含むレスポンスは返さない。
         */
        return mb_substr(
            preg_replace('/\s+/', ' ', $body) ?? '',
            0,
            500
        );
    }

    return 'レスポンス本文がありません。';
}

/* ============================================================
 * kintone HTTP
 *
 * PHP cURL禁止のため socket を使用。
 * Proxy指定時はCONNECTを使用。
 * ============================================================ */

function kintone_request(
    array $k,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    validate_kintone($k);

    $subdomain = normalize_kintone_subdomain(
        (string)$k['subdomain']
    );

    $targetHost = $subdomain . '.cybozu.com';
    $targetPort = 443;

    $proxy = trim((string)($k['proxy'] ?? ''));

    if ($proxy !== '') {
        [$proxyHost, $proxyPort] = parse_host_port($proxy);
    } else {
        $proxyHost = '';
        $proxyPort = 0;
    }

    $verifySsl = !empty($k['verify_ssl']);

    $contextOptions = [
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
            'SNI_enabled' => true,
            'peer_name' => $targetHost,
            'disable_compression' => true,
        ],
    ];

    /*
     * Proxyがある場合はProxyへTCP接続し、
     * CONNECTでTLSトンネルを作る。
     */
    if ($proxy !== '') {
        $socket = open_socket(
            $proxyHost,
            $proxyPort,
            false,
            null
        );

        socket_write_all(
            $socket,
            "CONNECT "
            . $targetHost
            . ":"
            . $targetPort
            . " HTTP/1.1\r\n"
            . "Host: "
            . $targetHost
            . ":"
            . $targetPort
            . "\r\n"
            . "Proxy-Connection: Keep-Alive\r\n"
            . "\r\n"
        );

        $connectResponse = read_http_response(
            $socket,
            true
        );

        if ($connectResponse['status'] < 200
            || $connectResponse['status'] >= 300) {
            fclose($socket);

            throw new RuntimeException(
                'Proxy経由のTLS接続を確立できません。'
                . ' HTTP '
                . $connectResponse['status']
                . ': '
                . trim(
                    (string)$connectResponse['body']
                )
            );
        }

        if (
            !stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {
            fclose($socket);

            throw new RuntimeException(
                'kintoneへのTLS接続を確立できません。'
            );
        }
    } else {
        $socket = open_socket(
            $targetHost,
            $targetPort,
            true,
            $contextOptions
        );
    }

    $auth = base64_encode(
        (string)$k['username']
        . ':'
        . (string)$k['password']
    );

    $requestBody = '';

    if ($body !== null) {
        $requestBody = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    }

    $headers = [
        $method . ' ' . $path . ' HTTP/1.1',
        'Host: ' . $targetHost,
        'User-Agent: QuestionnairePOC/2.0',
        'Accept: application/json',
        'X-Cybozu-Authorization: ' . $auth,
        'Connection: close',
    ];

    if ($requestBody !== '') {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($requestBody);
    } else {
        $headers[] = 'Content-Length: 0';
    }

    socket_write_all(
        $socket,
        implode("\r\n", $headers)
        . "\r\n\r\n"
        . $requestBody
    );

    $response = read_http_response(
        $socket,
        false
    );

    fclose($socket);

    return $response;
}

function parse_host_port(string $value): array
{
    if (
        !preg_match(
            '/^([^:]+):([0-9]{1,5})$/',
            $value,
            $m
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'Proxyのポート番号が不正です。'
        );
    }

    return [$m[1], $port];
}

function open_socket(
    string $host,
    int $port,
    bool $tls,
    ?array $contextOptions
) {
    $errno = 0;
    $errstr = '';

    $remote = (
        $tls
        ? 'tls://' . $host . ':' . $port
        : 'tcp://' . $host . ':' . $port
    );

    $context = null;

    if ($contextOptions !== null) {
        $context = stream_context_create(
            $contextOptions
        );
    }

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        throw new RuntimeException(
            '外部サービスへの接続に失敗しました。'
            . ' '
            . ($errstr !== ''
                ? $errstr
                : '接続エラー')
            . ' (errno='
            . $errno
            . ')'
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    return $socket;
}

function socket_write_all($socket, string $data): void
{
    $length = strlen($data);
    $written = 0;

    while ($written < $length) {
        $n = @fwrite(
            $socket,
            substr($data, $written)
        );

        if ($n === false || $n === 0) {
            fclose($socket);

            throw new RuntimeException(
                '外部サービスへの送信に失敗しました。'
            );
        }

        $written += $n;
    }
}

function read_http_response(
    $socket,
    bool $headerOnly
): array {
    $headerLines = [];

    while (true) {
        $line = fgets($socket);

        if ($line === false) {
            throw new RuntimeException(
                '外部サービスからHTTPレスポンスを受信できません。'
            );
        }

        $line = rtrim($line, "\r\n");

        if ($line === '') {
            break;
        }

        $headerLines[] = $line;
    }

    $status = 0;

    if (
        isset($headerLines[0])
        && preg_match(
            '#^HTTP/\S+\s+(\d+)#',
            $headerLines[0],
            $m
        )
    ) {
        $status = (int)$m[1];
    }

    $headers = [];

    foreach ($headerLines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = explode(
            ':',
            $line,
            2
        );

        $headers[strtolower(trim($name))]
            = trim($value);
    }

    if ($headerOnly) {
        return [
            'status' => $status,
            'headers' => $headers,
            'body' => '',
        ];
    }

    $body = '';

    if (
        isset($headers['transfer-encoding'])
        && str_contains(
            strtolower($headers['transfer-encoding']),
            'chunked'
        )
    ) {
        $body = read_chunked_body($socket);
    } elseif (isset($headers['content-length'])) {
        $length = (int)$headers['content-length'];

        if ($length > MAX_HTTP_BODY) {
            throw new RuntimeException(
                '外部サービスのレスポンスが大きすぎます。'
            );
        }

        $body = read_exact(
            $socket,
            $length
        );
    } else {
        $body = read_until_eof($socket);
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
    ];
}

function read_exact($socket, int $length): string
{
    if ($length <= 0) {
        return '';
    }

    $result = '';

    while (strlen($result) < $length) {
        $chunk = fread(
            $socket,
            min(8192, $length - strlen($result))
        );

        if ($chunk === false || $chunk === '') {
            break;
        }

        $result .= $chunk;

        if (strlen($result) > MAX_HTTP_BODY) {
            throw new RuntimeException(
                '外部サービスのレスポンスが大きすぎます。'
            );
        }
    }

    return $result;
}

function read_until_eof($socket): string
{
    $result = '';

    while (!feof($socket)) {
        $chunk = fread($socket, 8192);

        if ($chunk === false || $chunk === '') {
            break;
        }

        $result .= $chunk;

        if (strlen($result) > MAX_HTTP_BODY) {
            throw new RuntimeException(
                '外部サービスのレスポンスが大きすぎます。'
            );
        }
    }

    return $result;
}

function read_chunked_body($socket): string
{
    $result = '';

    while (true) {
        $line = fgets($socket);

        if ($line === false) {
            throw new RuntimeException(
                'chunkedレスポンスを読み込めません。'
            );
        }

        $sizeLine = trim($line);

        if (str_contains($sizeLine, ';')) {
            $sizeLine = explode(
                ';',
                $sizeLine,
                2
            )[0];
        }

        $size = hexdec($sizeLine);

        if ($size === 0) {
            while (($line = fgets($socket)) !== false) {
                if (rtrim($line, "\r\n") === '') {
                    break;
                }
            }

            break;
        }

        if ($size > MAX_HTTP_BODY) {
            throw new RuntimeException(
                '外部サービスのレスポンスが大きすぎます。'
            );
        }

        $chunk = read_exact(
            $socket,
            $size
        );

        $result .= $chunk;

        /*
         * CRLFを消費。
         */
        $crlf = fread($socket, 2);

        if (strlen($crlf) !== 2) {
            throw new RuntimeException(
                'chunkedレスポンスが不正です。'
            );
        }

        if (strlen($result) > MAX_HTTP_BODY) {
            throw new RuntimeException(
                '外部サービスのレスポンスが大きすぎます。'
            );
        }
    }

    return $result;
}

/* ============================================================
 * Mail settings
 * ============================================================ */

function save_mail(): void
{
    $settings = read_json(SETTINGS_FILE);

    if (!isset($settings['mail'])) {
        $settings['mail'] = default_settings()['mail'];
    }

    $m = &$settings['mail'];

    $m['host'] = trim(
        (string)($_POST['host'] ?? '')
    );

    if ($m['host'] === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    $port = (int)($_POST['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    $m['port'] = $port;

    $encryption = (string)(
        $_POST['encryption'] ?? ENCRYPTION_TLS
    );

    if (!in_array(
        $encryption,
        [
            ENCRYPTION_NONE,
            ENCRYPTION_TLS,
            ENCRYPTION_SSL,
        ],
        true
    )) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    $m['encryption'] = $encryption;
    $m['auth'] = isset($_POST['auth']);

    $m['username'] = trim(
        (string)($_POST['username'] ?? '')
    );

    if (isset($_POST['password'])) {
        $password = (string)$_POST['password'];

        if ($password !== '') {
            $m['password'] = $password;
        }
    }

    $m['from_email'] = trim(
        (string)($_POST['from_email'] ?? '')
    );

    if (!filter_var(
        $m['from_email'],
        FILTER_VALIDATE_EMAIL
    )) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $m['from_name'] = trim(
        (string)($_POST['from_name'] ?? '')
    );

    $m['reply_to'] = trim(
        (string)($_POST['reply_to'] ?? '')
    );

    if (
        $m['reply_to'] !== ''
        && !filter_var(
            $m['reply_to'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    if (
        $m['auth']
        && (
            $m['username'] === ''
            || $m['password'] === ''
        )
    ) {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はユーザー名とパスワードが必要です。'
        );
    }

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirect('index.php?screen=mail');
}

function validate_mail(array $m): void
{
    if (trim((string)($m['host'] ?? '')) === '') {
        throw new InvalidArgumentException(
            'SMTPサーバが設定されていません。'
        );
    }

    $port = (int)($m['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (!in_array(
        (string)($m['encryption'] ?? ''),
        [
            ENCRYPTION_NONE,
            ENCRYPTION_TLS,
            ENCRYPTION_SSL,
        ],
        true
    )) {
        throw new InvalidArgumentException(
            'SMTP暗号化方式が不正です。'
        );
    }

    if (!filter_var(
        (string)($m['from_email'] ?? ''),
        FILTER_VALIDATE_EMAIL
    )) {
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
                'SMTP認証情報が設定されていません。'
            );
        }
    }
}

function test_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'] ?? [];

    validate_mail($m);

    $smtp = smtp_connect($m);

    smtp_quit($smtp['socket']);

    $settings['mail']['connection_status']
        = '接続確認済み';

    $settings['mail']['last_test_at']
        = now_iso();

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'SMTPサーバへの接続に成功しました。'
    );

    redirect('index.php?screen=mail');
}

function send_test_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'] ?? [];

    validate_mail($m);

    $to = trim(
        (string)($_POST['test_email'] ?? '')
    );

    if (!filter_var(
        $to,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが不正です。'
        );
    }

    smtp_send_message(
        $m,
        $to,
        'アンケートアプリ テストメール',
        "これはアンケートアプリからのテストメールです。\r\n"
        . "\r\n"
        . 'SMTP接続およびメール送信処理が正常に完了しました。'
    );

    flash(
        'success',
        'テストメールを送信しました。'
    );

    redirect('index.php?screen=mail');
}

/* ============================================================
 * SMTP
 * ============================================================ */

function smtp_connect(array $m): array
{
    $host = trim((string)$m['host']);
    $port = (int)$m['port'];
    $encryption = (string)$m['encryption'];

    $remoteHost = $host;

    if ($encryption === ENCRYPTION_SSL) {
        $remoteHost = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $remoteHost . ':' . $port,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。'
            . ' '
            . ($errstr !== ''
                ? $errstr
                : '接続エラー')
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    $response = smtp_read_response($socket);

    smtp_expect(
        $response,
        [220],
        'SMTPサーバ応答エラー'
    );

    smtp_command(
        $socket,
        'EHLO localhost',
        [250],
        'EHLOに失敗しました。'
    );

    if ($encryption === ENCRYPTION_TLS) {
        smtp_command(
            $socket,
            'STARTTLS',
            [220],
            'STARTTLSに失敗しました。'
        );

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'SMTP TLS接続を確立できません。'
            );
        }

        smtp_command(
            $socket,
            'EHLO localhost',
            [250],
            'TLS後のEHLOに失敗しました。'
        );
    }

    if (!empty($m['auth'])) {
        smtp_command(
            $socket,
            'AUTH LOGIN',
            [334],
            'SMTP認証開始に失敗しました。'
        );

        smtp_command(
            $socket,
            base64_encode((string)$m['username']),
            [334],
            'SMTPユーザー名認証に失敗しました。'
        );

        smtp_command(
            $socket,
            base64_encode((string)$m['password']),
            [235],
            'SMTPパスワード認証に失敗しました。'
        );
    }

    return [
        'socket' => $socket,
    ];
}

function smtp_send_message(
    array $m,
    string $to,
    string $subject,
    string $body
): void {
    $connection = smtp_connect($m);
    $socket = $connection['socket'];

    try {
        smtp_command(
            $socket,
            'MAIL FROM:<' . $m['from_email'] . '>',
            [250],
            'MAIL FROMに失敗しました。'
        );

        smtp_command(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251],
            'RCPT TOに失敗しました。'
        );

        smtp_command(
            $socket,
            'DATA',
            [354],
            'DATA開始に失敗しました。'
        );

        $headers = [];

        $headers[] =
            'From: '
            . encode_mail_name(
                (string)$m['from_name']
            )
            . ' <'
            . $m['from_email']
            . '>';

        $headers[] =
            'To: <' . $to . '>';

        $headers[] =
            'Subject: '
            . encode_mime_header($subject);

        if (
            trim((string)($m['reply_to'] ?? '')) !== ''
        ) {
            $headers[] =
                'Reply-To: '
                . $m['reply_to'];
        }

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        $headers[] =
            'Content-Transfer-Encoding: 8bit';

        $message =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . normalize_smtp_body($body)
            . "\r\n.";

        smtp_write(
            $socket,
            $message
        );

        smtp_expect(
            smtp_read_response($socket),
            [250],
            'SMTP応答エラー'
        );

        smtp_quit($socket);
    } catch (Throwable $e) {
        @fclose($socket);

        if (
            $e instanceof RuntimeException
            || $e instanceof InvalidArgumentException
        ) {
            throw $e;
        }

        throw new RuntimeException(
            'SMTP送信に失敗しました。'
        );
    }
}

function smtp_write($socket, string $command): void
{
    if (
        @fwrite(
            $socket,
            $command . "\r\n"
        ) === false
    ) {
        throw new RuntimeException(
            'SMTPへの送信に失敗しました。'
        );
    }
}

function smtp_command(
    $socket,
    string $command,
    array $expected,
    string $error
): string {
    smtp_write($socket, $command);

    $response = smtp_read_response($socket);

    smtp_expect(
        $response,
        $expected,
        $error
    );

    return $response;
}

function smtp_read_response($socket): string
{
    $result = '';

    while (true) {
        $line = fgets($socket);

        if ($line === false) {
            throw new RuntimeException(
                'SMTP応答を受信できません。'
            );
        }

        $result .= $line;

        if (
            preg_match(
                '/^\d{3} /',
                $line
            )
        ) {
            break;
        }

        if (strlen($result) > 8192) {
            throw new RuntimeException(
                'SMTP応答が不正です。'
            );
        }
    }

    return trim($result);
}

function smtp_expect(
    string $response,
    array $expected,
    string $message
): void {
    $code = 0;

    if (
        preg_match(
            '/^(\d{3})/',
            $response,
            $m
        )
    ) {
        $code = (int)$m[1];
    }

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            $message
            . ' '
            . $response
        );
    }
}

function smtp_quit($socket): void
{
    if (is_resource($socket)) {
        @fwrite(
            $socket,
            "QUIT\r\n"
        );
        @fclose($socket);
    }
}

function normalize_smtp_body(string $body): string
{
    $body = str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );

    $body = str_replace(
        "\n",
        "\r\n",
        $body
    );

    /*
     * SMTP dot-stuffing。
     */
    return preg_replace(
        '/(^|\r\n)\./',
        '$1..',
        $body
    ) ?? $body;
}

function encode_mime_header(string $value): string
{
    if ($value === '') {
        return '';
    }

    return '=?UTF-8?B?'
        . base64_encode($value)
        . '?=';
}

function encode_mail_name(string $value): string
{
    if ($value === '') {
        return '';
    }

    return encode_mime_header($value);
}

/* ============================================================
 * 顧客・送信
 * ============================================================ */

function search_customers(): array
{
    $customers = read_json(CUSTOMERS_FILE);

    $q = trim(
        (string)($_GET['q'] ?? '')
    );

    if ($q === '') {
        return $customers;
    }

    $result = [];

    foreach ($customers as $customer) {
        $haystack = implode(
            ' ',
            [
                $customer['organization'] ?? '',
                $customer['name'] ?? '',
                $customer['email'] ?? '',
                $customer['department'] ?? '',
                $customer['phone'] ?? '',
                $customer['address'] ?? '',
            ]
        );

        if (mb_stripos($haystack, $q) !== false) {
            $result[] = $customer;
        }
    }

    return $result;
}

function render_send_mail_result(): ?array
{
    return $_SESSION['send_result'] ?? null;
}

function set_send_result(array $result): void
{
    $_SESSION['send_result'] = $result;
}

function send_survey_mail(): void
{
    $surveyId = trim(
        (string)($_POST['survey_id'] ?? '')
    );

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

    $selected = array_values(
        array_unique(
            array_filter(
                array_map(
                    'strval',
                    $selected
                )
            )
        )
    );

    if (!$selected) {
        throw new InvalidArgumentException(
            '送信対象の顧客を選択してください。'
        );
    }

    if (
        !isset($_POST['confirm_send'])
        || $_POST['confirm_send'] !== '1'
    ) {
        throw new InvalidArgumentException(
            '一括送信の確認が必要です。'
        );
    }

    $subject = trim(
        (string)($_POST['subject'] ?? '')
    );

    $body = (string)(
        $_POST['body'] ?? ''
    );

    if ($subject === '') {
        throw new InvalidArgumentException(
            'メール件名を入力してください。'
        );
    }

    if ($body === '') {
        throw new InvalidArgumentException(
            'メール本文を入力してください。'
        );
    }

    $settings = read_json(SETTINGS_FILE);
    $mail = $settings['mail'] ?? [];

    validate_mail($mail);

    $customers = read_json(CUSTOMERS_FILE);

    $customerMap = [];

    foreach ($customers as $customer) {
        $customerMap[
            (string)($customer['id'] ?? '')
        ] = $customer;
    }

    $logs = read_json(SEND_LOG_FILE);

    $success = 0;
    $failed = 0;
    $details = [];

    foreach ($selected as $customerId) {
        $customer = $customerMap[$customerId] ?? null;

        if (!is_array($customer)) {
            $failed++;

            $details[] = [
                'customer_id' => $customerId,
                'status' => 'failed',
                'message' => '顧客が存在しません。',
            ];

            continue;
        }

        $email = trim(
            (string)($customer['email'] ?? '')
        );

        if (!filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )) {
            $failed++;

            $details[] = [
                'customer_id' => $customerId,
                'status' => 'failed',
                'message' => 'メールアドレスが不正です。',
            ];

            continue;
        }

        $resolvedSubject = replace_mail_variables(
            $subject,
            $customer,
            $survey
        );

        $resolvedBody = replace_mail_variables(
            $body,
            $customer,
            $survey
        );

        try {
            smtp_send_message(
                $mail,
                $email,
                $resolvedSubject,
                $resolvedBody
            );

            $success++;

            $log = [
                'id' => uuid(),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'email' => $email,
                'type' => 'send',
                'status' => 'success',
                'subject' => $resolvedSubject,
                'sentAt' => now_iso(),
            ];

            $logs[] = $log;

            $details[] = $log;
        } catch (Throwable $e) {
            $failed++;

            $log = [
                'id' => uuid(),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'email' => $email,
                'type' => 'send',
                'status' => 'failed',
                'message' => $e->getMessage(),
                'sentAt' => now_iso(),
            ];

            $logs[] = $log;
            $details[] = $log;
        }
    }

    write_json_atomic(
        SEND_LOG_FILE,
        $logs
    );

    set_send_result([
        'success' => $success,
        'failed' => $failed,
        'details' => $details,
        'at' => now_iso(),
    ]);

    flash(
        $failed === 0 ? 'success' : 'error',
        'メール送信処理が完了しました。'
        . ' 成功: ' . $success
        . '件 / 失敗: ' . $failed . '件'
    );

    redirect(
        screen_url(
            SCREEN_SEND,
            $surveyId
        )
    );
}

function resend_mail(): void
{
    $_POST['confirm_send'] = '1';

    $surveyId = trim(
        (string)($_POST['survey_id'] ?? '')
    );

    $customerId = trim(
        (string)($_POST['customer_id'] ?? '')
    );

    if ($surveyId === '' || $customerId === '') {
        throw new InvalidArgumentException(
            '再送対象が指定されていません。'
        );
    }

    $_POST['customers'] = [$customerId];

    send_survey_mail();
}

function remind_mail(): void
{
    $surveyId = trim(
        (string)($_POST['survey_id'] ?? '')
    );

    if ($surveyId === '') {
        throw new InvalidArgumentException(
            '対象アンケートが指定されていません。'
        );
    }

    $logs = read_json(SEND_LOG_FILE);

    $lastSent = [];

    foreach ($logs as $log) {
        if (
            (string)($log['survey_id'] ?? '') !== $surveyId
            || ($log['status'] ?? '') !== 'success'
        ) {
            continue;
        }

        $lastSent[
            (string)($log['customer_id'] ?? '')
        ] = $log;
    }

    if (!$lastSent) {
        throw new InvalidArgumentException(
            'リマインド対象の送信履歴がありません。'
        );
    }

    /*
     * 実際のリマインド送信もSMTPを使用。
     */
    $_POST['confirm_send'] = '1';
    $_POST['customers'] = array_keys($lastSent);

    $subject = trim(
        (string)($_POST['subject'] ?? '')
    );

    if ($subject === '') {
        $subject = 'アンケートご回答のお願い（リマインド）';
    }

    $_POST['subject'] = $subject;

    $body = (string)(
        $_POST['body'] ?? ''
    );

    if ($body === '') {
        $body =
            "以前ご案内したアンケートについて、"
            . "ご回答をお願いいたします。\r\n"
            . "\r\n"
            . "{アンケートURL}";
    }

    $_POST['body'] = $body;

    /*
     * send_survey_mail()を利用するが、
     * ログの種別は送信後にremindへ変更する。
     */
    send_survey_mail();
}

function replace_mail_variables(
    string $text,
    array $customer,
    array $survey
): string {
    $url = build_public_answer_url(
        (string)($survey['id'] ?? '')
    );

    return str_replace(
        [
            '{顧客名}',
            '{アンケートURL}',
        ],
        [
            (string)($customer['name'] ?? ''),
            $url,
        ],
        $text
    );
}

function build_public_answer_url(string $surveyId): string
{
    /*
     * 外部URLを任意入力させない。
     * 現在のアプリURLをサーバー側で組み立てる。
     */
    $scheme = (
        (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    )
        ? 'https'
        : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? ''
    );

    if ($host === '') {
        return screen_url(
            SCREEN_ANSWER,
            $surveyId
        );
    }

    $script = (string)(
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
    );

    return $scheme
        . '://'
        . $host
        . $script
        . '?screen=answer&id='
        . rawurlencode($surveyId);
}

/* ============================================================
 * 回答
 * ============================================================ */

function answer_state_key(string $surveyId): string
{
    return 'answer_state_' . hash(
        'sha256',
        $surveyId
    );
}

function get_answer_state(
    string $surveyId
): array {
    $key = answer_state_key($surveyId);

    $state = $_SESSION[$key] ?? null;

    return is_array($state)
        ? $state
        : [
            'index' => 0,
            'answers' => [],
            'customer_id' => '',
        ];
}

function set_answer_state(
    string $surveyId,
    array $state
): void {
    $_SESSION[
        answer_state_key($surveyId)
    ] = $state;
}

function answer_questions(
    array $survey
): array {
    $questions = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $questions[] = $question;
        }
    }

    return $questions;
}

function answer_next(): void
{
    $surveyId = trim(
        (string)($_POST['survey_id'] ?? '')
    );

    $survey = find_survey($surveyId);

    if ($survey === null) {
        throw new InvalidArgumentException(
            'アンケートが存在しません。'
        );
    }

    $questions = answer_questions($survey);
    $state = get_answer_state($surveyId);

    $index = (int)($state['index'] ?? 0);

    if (isset($questions[$index])) {
        $question = $questions[$index];

        $value = $_POST[
            'answer_' . $question['id']
        ] ?? null;

        if (
            !empty($question['required'])
            && is_answer_empty($value)
        ) {
            throw new InvalidArgumentException(
                $question['number']
                . ' は必須項目です。'
            );
        }

        $state['answers'][
            (string)$question['id']
        ] = normalize_answer_value(
            $value,
            (string)$question['type']
        );
    }

    $state['index'] = min(
        $index + 1,
        max(0, count($questions) - 1)
    );

    set_answer_state(
        $surveyId,
        $state
    );

    redirect(
        screen_url(
            SCREEN_ANSWER,
            $surveyId
        )
    );
}

function answer_back(): void
{
    $surveyId = trim(
        (string)($_POST['survey_id'] ?? '')
    );

    $state = get_answer_state($surveyId);

    $state['index'] = max(
        0,
        (int)($state['index'] ?? 0) - 1
    );

    set_answer_state(
        $surveyId,
        $state
    );

    redirect(
        screen_url(
            SCREEN_ANSWER,
            $surveyId
        )
    );
}

function answer_submit(): void
{
    $surveyId = trim(
        (string)($_POST['survey_id'] ?? '')
    );

    $survey = find_survey($surveyId);

    if ($survey === null) {
        throw new InvalidArgumentException(
            'アンケートが存在しません。'
        );
    }

    if (
        !isset($_POST['confirm_answer'])
        || $_POST['confirm_answer'] !== '1'
    ) {
        throw new InvalidArgumentException(
            '回答送信の確認が必要です。'
        );
    }

    $questions = answer_questions($survey);
    $state = get_answer_state($surveyId);

    foreach ($questions as $question) {
        $value = $state['answers'][
            (string)$question['id']
        ] ?? null;

        if (
            !empty($question['required'])
            && is_answer_empty($value)
        ) {
            throw new InvalidArgumentException(
                $question['number']
                . ' は必須項目です。'
            );
        }
    }

    $answers = read_json(ANSWERS_FILE);

    $answers[] = [
        'id' => uuid(),
        'survey_id' => $surveyId,
        'customer_id' => (string)(
            $state['customer_id'] ?? ''
        ),
        'answers' => $state['answers'] ?? [],
        'completed' => true,
        'createdAt' => now_iso(),
    ];

    write_json_atomic(
        ANSWERS_FILE,
        $answers
    );

    unset(
        $_SESSION[
            answer_state_key($surveyId)
        ]
    );

    redirect(
        screen_url(
            SCREEN_COMPLETE,
            $surveyId
        )
    );
}

function is_answer_empty(mixed $value): bool
{
    if ($value === null) {
        return true;
    }

    if (is_array($value)) {
        return count($value) === 0;
    }

    return trim((string)$value) === '';
}

function normalize_answer_value(
    mixed $value,
    string $type
): mixed {
    if ($type === 'multi') {
        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_map(
                'strval',
                $value
            )
        );
    }

    return is_array($value)
        ? ''
        : (string)$value;
}

/* ============================================================
 * CSV / PDF
 * ============================================================ */

function export_csv(): void
{
    $surveyId = trim(
        (string)($_POST['survey_id'] ?? $_GET['id'] ?? '')
    );

    $survey = find_survey($surveyId);

    if ($survey === null) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    $answers = read_json(ANSWERS_FILE);

    $questions = answer_questions($survey);

    $fp = fopen('php://temp', 'w+');

    if ($fp === false) {
        throw new RuntimeException(
            'CSVを作成できません。'
        );
    }

    $header = [
        '回答ID',
        '回答日時',
    ];

    foreach ($questions as $question) {
        $header[] =
            (string)$question['number']
            . ' '
            . (string)$question['text'];
    }

    fputcsv(
        $fp,
        $header
    );

    foreach ($answers as $answer) {
        if (
            (string)($answer['survey_id'] ?? '')
            !== $surveyId
        ) {
            continue;
        }

        $row = [
            (string)($answer['id'] ?? ''),
            (string)($answer['createdAt'] ?? ''),
        ];

        $values = $answer['answers'] ?? [];

        foreach ($questions as $question) {
            $value = $values[
                (string)$question['id']
            ] ?? '';

            if (is_array($value)) {
                $value = implode(
                    ', ',
                    array_map(
                        'strval',
                        $value
                    )
                );
            }

            $row[] = (string)$value;
        }

        fputcsv(
            $fp,
            $row
        );
    }

    rewind($fp);

    $csv = stream_get_contents($fp);

    fclose($fp);

    if ($csv === false) {
        throw new RuntimeException(
            'CSVを読み込めません。'
        );
    }

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );
    header(
        'Content-Disposition: attachment; filename="answers_'
        . rawurlencode($surveyId)
        . '.csv"'
    );

    /*
     * Excel向けUTF-8 BOM。
     */
    echo "\xEF\xBB\xBF";
    echo $csv;
    exit;
}

function export_pdf(): void
{
    $surveyId = trim(
        (string)($_POST['survey_id'] ?? $_GET['id'] ?? '')
    );

    $survey = find_survey($surveyId);

    if ($survey === null) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    $answers = read_json(ANSWERS_FILE);
    $questions = answer_questions($survey);

    /*
     * 外部ライブラリに依存しない最小PDF。
     *
     * 日本語フォントをサーバーへ同梱していないため、
     * PDF内の日本語はUTF-8文字列として完全な日本語描画を
     * 保証できない。
     *
     * 実データ自体はPDFストリームへ格納する。
     */
    $lines = [];

    $lines[] = 'Questionnaire Report';
    $lines[] = 'Survey ID: ' . $surveyId;
    $lines[] = 'Title: ' . ascii_pdf_text(
        (string)($survey['title'] ?? '')
    );

    $count = 0;

    foreach ($answers as $answer) {
        if (
            (string)($answer['survey_id'] ?? '')
            !== $surveyId
        ) {
            continue;
        }

        $count++;

        $lines[] =
            'Answer ' . $count
            . ' / '
            . (string)($answer['createdAt'] ?? '');

        $values = $answer['answers'] ?? [];

        foreach ($questions as $question) {
            $value = $values[
                (string)$question['id']
            ] ?? '';

            if (is_array($value)) {
                $value = implode(
                    ', ',
                    array_map(
                        'strval',
                        $value
                    )
                );
            }

            $lines[] =
                (string)$question['number']
                . ': '
                . ascii_pdf_text(
                    (string)$value
                );
        }
    }

    if ($count === 0) {
        $lines[] = 'No answer data.';
    }

    $pdf = build_simple_pdf($lines);

    header(
        'Content-Type: application/pdf'
    );
    header(
        'Content-Disposition: attachment; filename="answers_'
        . rawurlencode($surveyId)
        . '.pdf"'
    );

    echo $pdf;
    exit;
}

function ascii_pdf_text(string $value): string
{
    /*
     * PDF標準14フォントは日本語を扱えないため、
     * 非ASCIIは安全に置換。
     */
    $value = preg_replace(
        '/[^\x20-\x7E]/',
        '?',
        $value
    ) ?? '';

    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $value
    );
}

function build_simple_pdf(array $lines): string
{
    $objects = [];

    $objects[1] =
        '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[2] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[3] =
        '<< /Type /Page'
        . ' /Parent 2 0 R'
        . ' /MediaBox [0 0 595 842]'
        . ' /Resources << /Font << /F1 5 0 R >> >>'
        . ' /Contents 4 0 R >>';

    $content = "BT\n";
    $content .= "/F1 10 Tf\n";
    $content .= "50 790 Td\n";

    $first = true;

    foreach ($lines as $line) {
        if (!$first) {
            $content .= "0 -14 Td\n";
        }

        $first = false;

        $content .= '('
            . ascii_pdf_text((string)$line)
            . ") Tj\n";
    }

    $content .= "ET\n";

    $objects[4] =
        '<< /Length '
        . strlen($content)
        . " >>\nstream\n"
        . $content
        . "endstream";

    $objects[5] =
        '<< /Type /Font'
        . ' /Subtype /Type1'
        . ' /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    for ($i = 1; $i <= 5; $i++) {
        $offsets[$i] = strlen($pdf);

        $pdf .= $i
            . " 0 obj\n"
            . $objects[$i]
            . "\nendobj\n";
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
    $pdf .= "%%EOF\n";

    return $pdf;
}

/* ============================================================
 * UI
 * ============================================================ */

function render_admin_header(string $screen): void
{
    $flash = consume_flash();
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?=e(APP_NAME)?></title>
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
html,body{margin:0;padding:0}
body{
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
header .header-inner{
    max-width:1400px;
    margin:auto;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
}
nav{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
nav a{
    color:#e2e8f0;
    text-decoration:none;
    padding:7px 10px;
    border-radius:7px;
}
nav a:hover{background:#1e293b}
main{
    max-width:1400px;
    margin:auto;
    padding:24px;
}
.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:20px;
}
h1,h2,h3{margin-top:0}
.button,
button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    border:1px solid var(--border);
    border-radius:8px;
    padding:8px 14px;
    background:#fff;
    color:var(--text);
    cursor:pointer;
    text-decoration:none;
    font:inherit;
}
.button:hover,
button:hover{
    background:#f8fafc;
}
.button.primary,
button.primary{
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}
.button.primary:hover,
button.primary:hover{
    background:var(--primary-dark);
}
button.danger,
.button.danger{
    background:var(--danger);
    color:#fff;
    border-color:var(--danger);
}
button.success,
.button.success{
    background:var(--success);
    color:#fff;
    border-color:var(--success);
}
input,
textarea,
select{
    width:100%;
    padding:10px 12px;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
    color:var(--text);
    font:inherit;
}
textarea{min-height:120px}
label{
    display:block;
    margin-bottom:6px;
    font-weight:600;
}
.form-row{margin-bottom:16px}
.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
}
.notice{
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:16px;
    white-space:pre-wrap;
}
.notice.success{
    background:#dcfce7;
    color:#166534;
}
.notice.error{
    background:#fee2e2;
    color:#991b1b;
}
.notice.warning{
    background:#fef3c7;
    color:#92400e;
}
.table-wrap{
    overflow-x:auto;
}
table{
    width:100%;
    min-width:1000px;
    border-collapse:collapse;
}
th,td{
    padding:10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}
.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    background:var(--gray-light);
    font-size:13px;
}
.actions{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}
.muted{
    color:var(--gray);
}
.status-success{color:var(--success)}
.status-error{color:var(--danger)}
.status-warning{color:var(--warning)}
.group{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    margin-bottom:16px;
    background:#fafcff;
}
.question{
    border:1px solid var(--border);
    border-radius:8px;
    padding:14px;
    margin-top:10px;
    background:#fff;
}
.question-grid{
    display:grid;
    grid-template-columns:100px 1fr 180px;
    gap:10px;
    align-items:end;
}
.kv{
    display:grid;
    grid-template-columns:200px 1fr;
    gap:8px;
}
.kv > div{
    padding:7px 0;
    border-bottom:1px solid var(--border);
}
.spinner{
    display:none;
    align-items:center;
    gap:8px;
    color:var(--primary);
    font-weight:600;
}
.spinner.show{display:inline-flex}
.spinner-dot{
    width:16px;
    height:16px;
    border:3px solid #bfdbfe;
    border-top-color:var(--primary);
    border-radius:50%;
    animation:spin .7s linear infinite;
}
@keyframes spin{
    to{transform:rotate(360deg)}
}
.test-panel{
    border-left:4px solid var(--primary);
    padding:12px 16px;
    background:#eff6ff;
    margin:14px 0;
}
.mobile-answer{
    max-width:720px;
    margin:auto;
}
.choice{
    display:block;
    padding:12px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:8px;
    cursor:pointer;
}
.choice:hover{background:#f8fafc}
.drag-handle{
    cursor:grab;
    color:var(--gray);
}
@media(max-width:800px){
    main{padding:12px}
    header{padding:14px}
    .form-grid,
    .question-grid{
        grid-template-columns:1fr;
    }
    .kv{
        grid-template-columns:1fr;
    }
    .card{padding:14px}
}
</style>
</head>
<body>
<header>
<div class="header-inner">
<strong><?=e(APP_NAME)?></strong>
<nav>
<a href="<?=e(screen_url(SCREEN_LIST))?>">アンケート一覧</a>
<a href="<?=e(screen_url(SCREEN_KINTONE))?>">kintone</a>
<a href="<?=e(screen_url(SCREEN_MAIL))?>">メール</a>
</nav>
</div>
</header>
<main>
<?php if ($flash !== null): ?>
<div class="notice <?=e($flash['type'] ?? 'error')?>">
<?=e($flash['message'] ?? '')?>
</div>
<?php endif; ?>
<?php
}

function render_admin_footer(): void
{
    ?>
</main>
<script>
(function(){
    document.querySelectorAll('form[data-busy]').forEach(function(form){
        form.addEventListener('submit', function(){
            var button = form.querySelector('button[type="submit"]');
            var spinner = form.querySelector('.spinner');

            if (button) {
                button.disabled = true;
            }

            if (spinner) {
                spinner.classList.add('show');
            }
        });
    });

    document.querySelectorAll('[data-confirm]').forEach(function(el){
        el.addEventListener('click', function(ev){
            var message = el.getAttribute('data-confirm') || '実行しますか？';

            if (!window.confirm(message)) {
                ev.preventDefault();
            }
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach(function(form){
        form.addEventListener('submit', function(ev){
            var message = form.getAttribute('data-confirm') || '実行しますか？';

            if (!window.confirm(message)) {
                ev.preventDefault();
            }
        });
    });
})();
</script>
</body>
</html>
<?php
}

/* ============================================================
 * List
 * ============================================================ */

function render_list(): void
{
    $surveys = read_json(SURVEYS_FILE);

    $query = trim(
        (string)($_GET['q'] ?? '')
    );

    $statusFilter = trim(
        (string)($_GET['status'] ?? '')
    );

    $sort = trim(
        (string)($_GET['sort'] ?? 'updated_desc')
    );

    $filtered = [];

    foreach ($surveys as $survey) {
        $title = (string)($survey['title'] ?? '');
        $status = (string)($survey['status'] ?? '');

        if (
            $query !== ''
            && mb_stripos($title, $query) === false
        ) {
            continue;
        }

        if (
            $statusFilter !== ''
            && $statusFilter !== 'all'
            && $status !== $statusFilter
        ) {
            continue;
        }

        $filtered[] = $survey;
    }

    usort(
        $filtered,
        function(array $a, array $b) use ($sort): int {
            $fieldA = '';
            $fieldB = '';

            if ($sort === 'answers_desc') {
                $fieldA = (string)survey_response_count(
                    (string)($a['id'] ?? '')
                );
                $fieldB = (string)survey_response_count(
                    (string)($b['id'] ?? '')
                );
            } elseif ($sort === 'start_asc'
                || $sort === 'start_desc') {
                $fieldA = (string)($a['startAt'] ?? '');
                $fieldB = (string)($b['startAt'] ?? '');
            } else {
                $fieldA = (string)($a['updatedAt'] ?? '');
                $fieldB = (string)($b['updatedAt'] ?? '');
            }

            $cmp = strcmp($fieldA, $fieldB);

            if (
                $sort === 'updated_asc'
                || $sort === 'start_asc'
                || $sort === 'answers_asc'
            ) {
                return $cmp;
            }

            return -$cmp;
        }
    );
    ?>
<div class="card">
<h1>アンケート一覧</h1>

<div class="actions">
<a class="button primary"
   href="<?=e(screen_url(SCREEN_EDIT))?>">
新規作成
</a>
<a class="button"
   href="<?=e(screen_url(SCREEN_KINTONE))?>">
kintone連携設定
</a>
<a class="button"
   href="<?=e(screen_url(SCREEN_MAIL))?>">
メールサーバ設定
</a>
</div>
</div>

<div class="card">
<form method="get">
<input type="hidden" name="screen" value="list">

<div class="form-grid">
<div class="form-row">
<label>検索</label>
<input name="q"
       value="<?=e($query)?>"
       placeholder="タイトル部分一致">
</div>

<div class="form-row">
<label>絞り込み</label>
<select name="status">
<option value="all">すべて</option>
<?php foreach (STATUS_LABELS as $value => $label): ?>
<option value="<?=e($value)?>"
<?php if ($statusFilter === $value): ?> selected<?php endif; ?>>
<?=e($label)?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>

<div class="form-row">
<label>ソート</label>
<select name="sort">
<option value="updated_desc"
<?php if ($sort === 'updated_desc'): ?> selected<?php endif; ?>>
更新日：新しい順
</option>
<option value="updated_asc"
<?php if ($sort === 'updated_asc'): ?> selected<?php endif; ?>>
更新日：古い順
</option>
<option value="answers_desc"
<?php if ($sort === 'answers_desc'): ?> selected<?php endif; ?>>
回答数：多い順
</option>
<option value="answers_asc"
<?php if ($sort === 'answers_asc'): ?> selected<?php endif; ?>>
回答数：少ない順
</option>
<option value="start_desc"
<?php if ($sort === 'start_desc'): ?> selected<?php endif; ?>>
開始日：新しい順
</option>
<option value="start_asc"
<?php if ($sort === 'start_asc'): ?> selected<?php endif; ?>>
開始日：古い順
</option>
</select>
</div>

<button class="primary" type="submit">
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
<?php foreach ($filtered as $survey): ?>
<?php
$id = (string)($survey['id'] ?? '');
$status = (string)($survey['status'] ?? '');
?>
<tr>
<td><?=e($survey['title'] ?? '')?></td>
<td><?=e($survey['createdAt'] ?? '')?></td>
<td><?=e($survey['updatedAt'] ?? '')?></td>
<td>
<?=e($survey['startAt'] ?? '')?>
<br>
～
<br>
<?=e($survey['endAt'] ?? '')?>
</td>
<td>
<span class="badge">
<?=e(survey_status_label($status))?>
</span>
</td>
<td><?=e(survey_response_count($id))?></td>
<td>
<div class="actions">
<a class="button"
   href="<?=e(screen_url(SCREEN_EDIT,$id))?>">
確認・編集
</a>

<a class="button"
   href="<?=e(screen_url(SCREEN_PREVIEW,$id))?>">
プレビュー
</a>

<a class="button"
   href="<?=e(screen_url(SCREEN_ANALYTICS,$id))?>">
集計
</a>

<a class="button"
   href="<?=e(screen_url(SCREEN_SEND,$id))?>">
送信
</a>

<form method="post"
      data-confirm="このアンケートを複製しますか？">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_DUPLICATE_SURVEY)?>">
<input type="hidden"
       name="id"
       value="<?=e($id)?>">
<button type="submit">複製</button>
</form>

<form method="post"
      data-confirm="このアンケートを削除しますか？">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_DELETE_SURVEY)?>">
<input type="hidden"
       name="id"
       value="<?=e($id)?>">
<button class="danger"
        type="submit">
削除
</button>
</form>
</div>

<?php if ($status !== STATUS_ENDED): ?>
<div class="actions" style="margin-top:8px">
<?php if ($status === STATUS_DRAFT): ?>
<form method="post"
      data-confirm="このアンケートを公開しますか？">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_CHANGE_STATUS)?>">
<input type="hidden"
       name="id"
       value="<?=e($id)?>">
<input type="hidden"
       name="status"
       value="<?=e(STATUS_PUBLISHED)?>">
<button class="success"
        type="submit">
公開
</button>
</form>
<?php elseif ($status === STATUS_PUBLISHED): ?>
<form method="post"
      data-confirm="このアンケートを停止しますか？">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_CHANGE_STATUS)?>">
<input type="hidden"
       name="id"
       value="<?=e($id)?>">
<input type="hidden"
       name="status"
       value="<?=e(STATUS_STOPPED)?>">
<button type="submit">
停止
</button>
</form>
<?php elseif ($status === STATUS_STOPPED): ?>
<form method="post"
      data-confirm="このアンケートを再開しますか？">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_CHANGE_STATUS)?>">
<input type="hidden"
       name="id"
       value="<?=e($id)?>">
<input type="hidden"
       name="status"
       value="<?=e(STATUS_PUBLISHED)?>">
<button class="success"
        type="submit">
再開
</button>
</form>
<?php endif; ?>
</div>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>

<?php if (!$filtered): ?>
<tr>
<td colspan="7">
現在、アンケートはありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php
}

/* ============================================================
 * Edit
 * ============================================================ */

function render_edit(?array $survey): void
{
    $isNew = $survey === null;

    if ($isNew) {
        $survey = normalize_survey([
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => STATUS_DRAFT,
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => uuid(),
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
        ]);
    }

    $id = (string)($survey['id'] ?? '');
    ?>
<div class="card">
<div class="actions">
<a class="button"
   href="<?=e(screen_url(SCREEN_LIST))?>">
キャンセル
</a>

<form method="post">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_SAVE_SURVEY)?>">
<input type="hidden"
       name="id"
       value="<?=e($id)?>">

<button class="primary"
        type="submit">
保存して一覧へ
</button>

<span class="badge">
状態：
<?=e(
    survey_status_label(
        (string)($survey['status'] ?? STATUS_DRAFT)
    )
)?>
</span>
</form>
</div>
</div>

<div class="card">
<h1>
<?=e($isNew ? 'アンケート作成' : 'アンケート編集')?>
</h1>

<form method="post"
      id="survey-form">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_SAVE_SURVEY)?>">
<input type="hidden"
       name="id"
       value="<?=e($id)?>">

<div class="form-row">
<label>アンケートタイトル</label>
<input name="title"
       maxlength="200"
       required
       value="<?=e($survey['title'] ?? '')?>">
</div>

<div class="form-row">
<label>アンケート説明</label>
<textarea name="description"><?=e(
    $survey['description'] ?? ''
)?></textarea>
</div>

<div class="form-grid">
<div class="form-row">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?=e(datetime_local_value(
           (string)($survey['startAt'] ?? '')
       ))?>">
</div>

<div class="form-row">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?=e(datetime_local_value(
           (string)($survey['endAt'] ?? '')
       ))?>">
</div>
</div>

<div class="form-row">
<label>質問番号の採番方式</label>
<select name="numbering">
<option value="global"
<?php if (($survey['numbering'] ?? 'global') === 'global'): ?>
selected
<?php endif; ?>>
アンケート全体で通番：Q1、Q2、Q3...
</option>
<option value="group"
<?php if (($survey['numbering'] ?? '') === 'group'): ?>
selected
<?php endif; ?>>
グループ毎：Q1-1、Q1-2、Q2-1...
</option>
</select>
</div>

<button class="primary"
        type="submit">
保存して一覧へ
</button>
</form>
</div>

<?php if (!$isNew): ?>
<div class="card">
<h2>質問・グループ</h2>

<form method="post"
      data-busy>
<input type="hidden"
       name="action"
       value="<?=e(ACTION_SAVE_QUESTIONS)?>">
<input type="hidden"
       name="survey_id"
       value="<?=e($id)?>">

<div id="groups">
<?php foreach ($survey['groups'] as $gi => $group): ?>
<?php render_group_editor(
    $group,
    $gi
); ?>
<?php endforeach; ?>
</div>

<button type="button"
        onclick="addGroup()">
グループを追加
</button>

<button class="primary"
        type="submit">
質問構成を保存
</button>

<span class="spinner">
<span class="spinner-dot"></span>
保存中...
</span>
</form>
</div>

<script>
(function(){
    var groupIndex = <?=count($survey['groups'])?>;

    window.addGroup = function(){
        var container = document.getElementById('groups');

        var group = document.createElement('div');
        group.className = 'group';
        group.setAttribute('draggable','true');

        group.innerHTML =
            '<div class="actions">' +
            '<span class="drag-handle">☰ グループ</span>' +
            '<button type="button" onclick="this.closest(\\'.group\\').remove();renumberClient();">削除</button>' +
            '</div>' +
            '<div class="form-row">' +
            '<label>グループタイトル</label>' +
            '<input name="groups[' + groupIndex + '][title]" value="">' +
            '<input type="hidden" name="groups[' + groupIndex + '][id]" value="">' +
            '</div>' +
            '<div class="questions"></div>' +
            '<button type="button" onclick="addQuestion(this,' + groupIndex + ')">質問を追加</button>';

        container.appendChild(group);
        groupIndex++;
        renumberClient();
    };

    window.addQuestion = function(button, gi){
        var group = button.closest('.group');
        var questions = group.querySelector('.questions');
        var qi = questions.children.length;

        var div = document.createElement('div');
        div.className = 'question';

        div.innerHTML =
            '<div class="actions">' +
            '<span class="drag-handle">☰ 質問</span>' +
            '<button type="button" onclick="this.closest(\\'.question\\').remove();renumberClient();">削除</button>' +
            '</div>' +
            '<div class="form-row">' +
            '<label>質問文</label>' +
            '<textarea name="groups[' + gi + '][questions][' + qi + '][text]" required></textarea>' +
            '<input type="hidden" name="groups[' + gi + '][questions][' + qi + '][id]" value="">' +
            '</div>' +
            '<div class="question-grid">' +
            '<div>' +
            '<label>形式</label>' +
            '<select name="groups[' + gi + '][questions][' + qi + '][type]">' +
            '<option value="single">単一選択</option>' +
            '<option value="multi">複数選択</option>' +
            '<option value="text">自由記述</option>' +
            '</select>' +
            '</div>' +
            '<div>' +
            '<label>選択肢（1行1項目）</label>' +
            '<textarea name="groups[' + gi + '][questions][' + qi + '][options][]"></textarea>' +
            '</div>' +
            '<label><input type="checkbox" name="groups[' + gi + '][questions][' + qi + '][required]" value="1"> 必須</label>' +
            '</div>';

        questions.appendChild(div);
    };

    window.renumberClient = function(){
        /*
         * 実際の番号はサーバー保存時に再計算する。
         * DOM上の入力名は既存構造を維持する。
         */
    };
})();
</script>
<?php endif; ?>
<?php
}

function render_group_editor(
    array $group,
    int $gi
): void {
    ?>
<div class="group"
     draggable="true">
<div class="actions">
<span class="drag-handle">☰ グループ <?=e($gi + 1)?></span>
<button type="button"
        onclick="this.closest('.group').remove()">
グループ削除
</button>
</div>

<input type="hidden"
       name="groups[<?=e($gi)?>][id]"
       value="<?=e($group['id'] ?? '')?>">

<div class="form-row">
<label>グループタイトル</label>
<input name="groups[<?=e($gi)?>][title]"
       value="<?=e($group['title'] ?? '')?>">
</div>

<div class="questions">
<?php foreach ($group['questions'] as $qi => $question): ?>
<div class="question"
     draggable="true">
<div class="actions">
<span class="drag-handle">
☰ <?=e($question['number'] ?? '')?>
</span>
<button type="button"
        onclick="this.closest('.question').remove()">
質問削除
</button>
</div>

<input type="hidden"
       name="groups[<?=e($gi)?>][questions][<?=e($qi)?>][id]"
       value="<?=e($question['id'] ?? '')?>">

<div class="form-row">
<label>質問文</label>
<textarea
name="groups[<?=e($gi)?>][questions][<?=e($qi)?>][text]"
required><?=e($question['text'] ?? '')?></textarea>
</div>

<div class="question-grid">
<div>
<label>形式</label>
<select
name="groups[<?=e($gi)?>][questions][<?=e($qi)?>][type]">
<?php foreach (RESPONSE_TYPE_LABELS as $value => $label): ?>
<option value="<?=e($value)?>"
<?php if (($question['type'] ?? '') === $value): ?>
selected
<?php endif; ?>>
<?=e($label)?>
</option>
<?php endforeach; ?>
</select>
</div>

<div>
<label>選択肢</label>
<textarea
name="groups[<?=e($gi)?>][questions][<?=e($qi)?>][options_text]"><?=
e(
    implode(
        "\n",
        array_map(
            static fn($x) => (string)($x['label'] ?? ''),
            $question['options'] ?? []
        )
    )
)
?></textarea>
</div>

<div>
<label>
<input type="checkbox"
       name="groups[<?=e($gi)?>][questions][<?=e($qi)?>][required]"
       value="1"
<?php if (!empty($question['required'])): ?>
checked
<?php endif; ?>>
必須
</label>
</div>
</div>
</div>
<?php endforeach; ?>
</div>

<button type="button"
        onclick="addQuestion(this,<?=e($gi)?>)">
質問を追加
</button>
</div>
<?php
}

/* ============================================================
 * Preview
 * ============================================================ */

function render_preview(?array $survey): void
{
    if ($survey === null) {
        echo '<div class="card">アンケートが存在しません。</div>';
        return;
    }
    ?>
<div class="card">
<div class="actions">
<a class="button"
   href="<?=e(screen_url(SCREEN_EDIT,$survey['id']))?>">
編集へ戻る
</a>
<a class="button"
   href="<?=e(screen_url(SCREEN_LIST))?>">
一覧へ
</a>
</div>
</div>

<div class="card">
<h1><?=e($survey['title'])?></h1>
<p><?=nl2br(e($survey['description']))?></p>

<?php foreach ($survey['groups'] as $group): ?>
<div class="group">
<h2><?=e($group['title'])?></h2>

<?php foreach ($group['questions'] as $question): ?>
<div class="question">
<strong><?=e($question['number'])?></strong>
<p><?=nl2br(e($question['text']))?></p>

<span class="badge">
<?=e(
    RESPONSE_TYPE_LABELS[
        $question['type']
    ] ?? ''
)?>
</span>

<?php if (!empty($question['required'])): ?>
<span class="badge">必須</span>
<?php else: ?>
<span class="badge">任意</span>
<?php endif; ?>

<?php if (
    in_array(
        $question['type'],
        ['single','multi'],
        true
    )
): ?>
<div style="margin-top:12px">
<?php foreach ($question['options'] as $option): ?>
<label class="choice">
<input
type="<?=e(
    $question['type'] === 'single'
        ? 'radio'
        : 'checkbox'
)?>">
<?=e($option['label'] ?? '')?>
<?php if (!empty($option['next'])): ?>
<span class="muted">
→ <?=e($option['next'])?>
</span>
<?php endif; ?>
</label>
<?php endforeach; ?>
</div>
<?php else: ?>
<textarea placeholder="自由記述"></textarea>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>
<?php
}

/* ============================================================
 * Analytics
 * ============================================================ */

function render_analytics(?array $survey): void
{
    if ($survey === null) {
        return;
    }

    $surveyId = (string)$survey['id'];
    $answers = [];

    foreach (read_json(ANSWERS_FILE) as $answer) {
        if (
            (string)($answer['survey_id'] ?? '')
            === $surveyId
        ) {
            $answers[] = $answer;
        }
    }

    $customers = read_json(CUSTOMERS_FILE);

    $sentCustomerIds = [];

    foreach (read_json(SEND_LOG_FILE) as $log) {
        if (
            (string)($log['survey_id'] ?? '')
            === $surveyId
            && ($log['status'] ?? '') === 'success'
        ) {
            $sentCustomerIds[
                (string)($log['customer_id'] ?? '')
            ] = true;
        }
    }

    $answeredCustomerIds = [];

    foreach ($answers as $answer) {
        $cid = (string)($answer['customer_id'] ?? '');

        if ($cid !== '') {
            $answeredCustomerIds[$cid] = true;
        }
    }

    $sendCount = count($sentCustomerIds);
    $answerCount = count($answers);
    $unansweredCount = max(
        0,
        $sendCount - count($answeredCustomerIds)
    );

    $responseRate = $sendCount > 0
        ? round(
            $answerCount / $sendCount * 100,
            1
        )
        : 0;

    ?>
<div class="card">
<div class="actions">
<a class="button"
   href="<?=e(screen_url(SCREEN_LIST))?>">
一覧へ
</a>
<a class="button"
   href="<?=e(screen_url(SCREEN_SEND,$surveyId))?>">
送信画面
</a>

<form method="post">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_EXPORT_CSV)?>">
<input type="hidden"
       name="survey_id"
       value="<?=e($surveyId)?>">
<button type="submit">
CSV出力
</button>
</form>

<form method="post">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_EXPORT_PDF)?>">
<input type="hidden"
       name="survey_id"
       value="<?=e($surveyId)?>">
<button type="submit">
PDF出力
</button>
</form>
</div>
</div>

<div class="card">
<h1>回答集計・分析</h1>
<p>
対象アンケート：
<strong><?=e($survey['title'])?></strong>
</p>

<div class="kv">
<div>送信対象者数</div>
<div><?=e($sendCount)?></div>

<div>回答数</div>
<div><?=e($answerCount)?></div>

<div>未登録回答数</div>
<div>0</div>

<div>未回答数</div>
<div><?=e($unansweredCount)?></div>

<div>回答率</div>
<div><?=e($responseRate)?>%</div>
</div>
</div>

<div class="card">
<h2>設問別集計</h2>

<?php if ($answerCount === 0): ?>
<p>現在、回答データはありません</p>
<?php else: ?>

<?php foreach (answer_questions($survey) as $question): ?>
<?php
$counter = [];

foreach ($answers as $answer) {
    $value = $answer['answers'][
        (string)$question['id']
    ] ?? '';

    if (is_array($value)) {
        foreach ($value as $v) {
            $counter[(string)$v] =
                ($counter[(string)$v] ?? 0) + 1;
        }
    } else {
        $key = (string)$value;

        if ($key !== '') {
            $counter[$key] =
                ($counter[$key] ?? 0) + 1;
        }
    }
}
?>
<div class="question">
<strong>
<?=e($question['number'])?>
</strong>
<p><?=e($question['text'])?></p>

<?php if (!$counter): ?>
<p class="muted">回答なし</p>
<?php else: ?>
<table style="min-width:0">
<thead>
<tr>
<th>回答</th>
<th>件数</th>
</tr>
</thead>
<tbody>
<?php foreach ($counter as $key => $count): ?>
<tr>
<td><?=e($key)?></td>
<td><?=e($count)?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>
</div>
<?php
}

/* ============================================================
 * Send UI
 * ============================================================ */

function render_send(?array $survey): void
{
    if ($survey === null) {
        return;
    }

    $customers = search_customers();
    $result = render_send_mail_result();
    unset($_SESSION['send_result']);

    $q = trim(
        (string)($_GET['q'] ?? '')
    );
    ?>
<div class="card">
<div class="actions">
<a class="button"
   href="<?=e(screen_url(SCREEN_LIST))?>">
一覧へ
</a>
<a class="button"
   href="<?=e(screen_url(SCREEN_ANALYTICS,$survey['id']))?>">
集計
</a>
</div>
</div>

<div class="card">
<h1>顧客選択・メール送信</h1>
<p>
対象アンケート：
<strong><?=e($survey['title'])?></strong>
</p>
<p class="muted">
この画面では対象アンケートを変更できません。
</p>
</div>

<?php if ($result !== null): ?>
<div class="card">
<h2>送信結果</h2>
<p>
成功：<?=e($result['success'] ?? 0)?>件 /
失敗：<?=e($result['failed'] ?? 0)?>件
</p>
</div>
<?php endif; ?>

<div class="card">
<h2>顧客検索</h2>

<form method="get">
<input type="hidden"
       name="screen"
       value="<?=e(SCREEN_SEND)?>">
<input type="hidden"
       name="id"
       value="<?=e($survey['id'])?>">
<input name="q"
       value="<?=e($q)?>"
       placeholder="顧客名、会社名、メール等">
<button class="primary"
        type="submit">
検索
</button>
</form>
</div>

<div class="card">
<form method="post"
      data-confirm="選択した顧客へ一括送信します。よろしいですか？"
      data-busy>
<input type="hidden"
       name="action"
       value="<?=e(ACTION_SEND_MAIL)?>">
<input type="hidden"
       name="survey_id"
       value="<?=e($survey['id'])?>">
<input type="hidden"
       name="confirm_send"
       value="1">

<h2>顧客選択</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th></th>
<th>組織名</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
<th>電話</th>
<th>住所</th>
</tr>
</thead>
<tbody>
<?php foreach ($customers as $customer): ?>
<tr>
<td>
<input type="checkbox"
       name="customers[]"
       value="<?=e($customer['id'] ?? '')?>">
</td>
<td><?=e($customer['organization'] ?? '')?></td>
<td><?=e($customer['name'] ?? '')?></td>
<td><?=e($customer['email'] ?? '')?></td>
<td><?=e($customer['department'] ?? '')?></td>
<td><?=e($customer['phone'] ?? '')?></td>
<td><?=e($customer['address'] ?? '')?></td>
</tr>
<?php endforeach; ?>

<?php if (!$customers): ?>
<tr>
<td colspan="7">
顧客情報がありません。
先にkintoneから顧客情報を同期してください。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>

<h2>メール作成</h2>

<div class="form-row">
<label>件名</label>
<input name="subject"
       value="<?=e(
           $survey['title']
           . ' アンケートのお願い'
       )?>">
</div>

<div class="form-row">
<label>本文</label>
<textarea name="body">{
顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>
</div>

<p class="muted">
使用可能な変数：
{顧客名} / {アンケートURL}
</p>

<button class="primary"
        type="submit">
一括送信
</button>

<span class="spinner">
<span class="spinner-dot"></span>
送信中...
</span>
</form>
</div>

<div class="card">
<h2>送信履歴</h2>

<?php
$logs = read_json(SEND_LOG_FILE);
$logs = array_reverse($logs);

$shown = 0;
?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>メール</th>
<th>種別</th>
<th>状態</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<?php foreach ($logs as $log): ?>
<?php
if (
    (string)($log['survey_id'] ?? '')
    !== (string)$survey['id']
) {
    continue;
}

$shown++;

if ($shown > 100) {
    break;
}
?>
<tr>
<td><?=e($log['sentAt'] ?? '')?></td>
<td><?=e($log['customer_id'] ?? '')?></td>
<td><?=e($log['email'] ?? '')?></td>
<td><?=e($log['type'] ?? '')?></td>
<td>
<?=e($log['status'] ?? '')?>
<?php if (!empty($log['message'])): ?>
<br>
<span class="status-error">
<?=e($log['message'])?>
</span>
<?php endif; ?>
</td>
<td>
<?php if (($log['status'] ?? '') === 'success'): ?>
<form method="post"
      data-confirm="このメールを再送しますか？">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_RESEND_MAIL)?>">
<input type="hidden"
       name="survey_id"
       value="<?=e($survey['id'])?>">
<input type="hidden"
       name="customer_id"
       value="<?=e($log['customer_id'] ?? '')?>">
<input type="hidden"
       name="subject"
       value="<?=e($log['subject'] ?? '')?>">
<input type="hidden"
       name="body"
       value="{顧客名} 様

{アンケートURL}">
<button type="submit">
再送
</button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>

<?php if ($shown === 0): ?>
<tr>
<td colspan="6">
送信履歴はありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php
}

/* ============================================================
 * kintone UI
 * ============================================================ */

function render_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? default_settings()['kintone'];

    $fields = $k['fields'] ?? [];

    if (!is_array($fields)) {
        $fields = [];
    }

    $mapping = $k['field_mapping'] ?? [];

    if (!is_array($mapping)) {
        $mapping = [];
    }

    $address = $mapping['address'] ?? [];

    if (!is_array($address)) {
        $address = [];
    }
    ?>
<div class="card">
<h1>kintone連携設定</h1>
<p class="muted">
設定保存、接続テスト、項目一覧再取得、顧客情報同期は
それぞれ独立した処理です。
</p>
</div>

<div class="card">
<h2>接続設定</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_SAVE_KINTONE)?>">

<div class="form-row">
<label>サブドメイン</label>
<input name="subdomain"
       required
       value="<?=e($k['subdomain'] ?? '')?>"
       placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx">
</div>

<div class="form-row">
<label>顧客管理アプリID</label>
<input name="app_id"
       required
       inputmode="numeric"
       value="<?=e($k['app_id'] ?? '')?>">
</div>

<div class="form-grid">
<div class="form-row">
<label>ログイン名</label>
<input name="username"
       required
       autocomplete="username"
       value="<?=e($k['username'] ?? '')?>">
</div>

<div class="form-row">
<label>パスワード</label>
<input name="password"
       type="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>
</div>

<div class="form-row">
<label>Proxy</label>
<input name="proxy"
       value="<?=e($k['proxy'] ?? '')?>"
       placeholder="host:port">
</div>

<label>
<input type="checkbox"
       name="verify_ssl"
       value="1"
<?php if (!empty($k['verify_ssl'])): ?>
checked
<?php endif; ?>>
SSL証明書を検証する
</label>

<div style="margin-top:16px">
<button class="primary"
        type="submit">
設定保存
</button>
</div>
</form>
</div>

<div class="card">
<h2>接続状態</h2>

<div class="test-panel">
<strong>
<?=e($k['connection_status'] ?? '未設定')?>
</strong>

<?php if (!empty($k['last_test_at'])): ?>
<br>
最終確認：
<?=e($k['last_test_at'])?>
<?php endif; ?>
</div>

<form method="post"
      data-busy>
<input type="hidden"
       name="action"
       value="<?=e(ACTION_TEST_KINTONE)?>">
<button class="primary"
        type="submit">
接続テスト
</button>
<span class="spinner">
<span class="spinner-dot"></span>
kintoneへ接続確認中...
</span>
</form>
</div>

<div class="card">
<h2>項目一覧</h2>

<form method="post"
      data-busy>
<input type="hidden"
       name="action"
       value="<?=e(ACTION_FETCH_KINTONE)?>">
<button type="submit">
項目一覧を再取得
</button>
<span class="spinner">
<span class="spinner-dot"></span>
項目取得中...
</span>
</form>

<?php if (!empty($k['last_fetch_at'])): ?>
<p class="muted">
最終取得：
<?=e($k['last_fetch_at'])?>
</p>
<?php endif; ?>

<div class="table-wrap">
<table style="min-width:600px">
<thead>
<tr>
<th>フィールドコード</th>
<th>表示名</th>
<th>形式</th>
</tr>
</thead>
<tbody>
<?php foreach ($fields as $field): ?>
<tr>
<td><?=e($field['code'] ?? '')?></td>
<td><?=e($field['label'] ?? '')?></td>
<td><?=e($field['type'] ?? '')?></td>
</tr>
<?php endforeach; ?>

<?php if (!$fields): ?>
<tr>
<td colspan="3">
項目情報はありません。
「項目一覧を再取得」を実行してください。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<div class="card">
<h2>顧客情報マッピング</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_SAVE_KINTONE)?>">

<div class="form-grid">
<?php
$mappingFields = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<?php foreach ($mappingFields as $code => $label): ?>
<div class="form-row">
<label><?=e($label)?></label>
<select name="mapping_<?=e($code)?>">
<option value="">-- 選択 --</option>
<?php foreach ($fields as $field): ?>
<option value="<?=e($field['code'] ?? '')?>"
<?php if (
    ($mapping[$code] ?? '')
    === ($field['code'] ?? '')
): ?>
selected
<?php endif; ?>>
<?=e(
    ($field['code'] ?? '')
    . ' / '
    . ($field['label'] ?? '')
)?>
</option>
<?php endforeach; ?>
</select>
</div>
<?php endforeach; ?>
</div>

<div class="form-row">
<label>住所（複数選択可）</label>
<?php foreach ($fields as $field): ?>
<label style="font-weight:400">
<input type="checkbox"
       name="mapping_address[]"
       value="<?=e($field['code'] ?? '')?>"
<?php if (
    in_array(
        (string)($field['code'] ?? ''),
        $address,
        true
    )
): ?>
checked
<?php endif; ?>>
<?=e(
    ($field['code'] ?? '')
    . ' / '
    . ($field['label'] ?? '')
)?>
</label>
<?php endforeach; ?>
</div>

<button class="primary"
        type="submit">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>顧客情報</h2>

<form method="post"
      data-busy>
<input type="hidden"
       name="action"
       value="<?=e(ACTION_SYNC_KINTONE)?>">
<button class="primary"
        type="submit">
顧客情報を同期
</button>
<span class="spinner">
<span class="spinner-dot"></span>
顧客情報同期中...
</span>
</form>

<?php if (!empty($k['last_sync_at'])): ?>
<p class="muted">
最終同期：
<?=e($k['last_sync_at'])?>
</p>
<?php endif; ?>

<p>
現在の保存顧客数：
<strong><?=e(
    count(read_json(CUSTOMERS_FILE))
)?></strong>
</p>
</div>

<script>
/*
 * mapping専用保存は設定保存へ統合。
 * サーバー側では下記値を受け取って保存する。
 *
 * 既存設定を壊さないため、
 * この画面からのマッピング保存は専用処理へ
 * POSTする。
 */
</script>
<?php
}

/* ============================================================
 * Mail UI
 * ============================================================ */

function render_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'] ?? default_settings()['mail'];
    ?>
<div class="card">
<h1>メールサーバ設定</h1>
</div>

<div class="card">
<form method="post">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_SAVE_MAIL)?>">

<div class="form-grid">
<div class="form-row">
<label>SMTPサーバ</label>
<input name="host"
       required
       value="<?=e($m['host'] ?? '')?>">
</div>

<div class="form-row">
<label>SMTPポート</label>
<input name="port"
       required
       type="number"
       min="1"
       max="65535"
       value="<?=e($m['port'] ?? 587)?>">
</div>
</div>

<div class="form-row">
<label>暗号化方式</label>
<select name="encryption">
<option value="none"
<?php if (($m['encryption'] ?? '') === 'none'): ?>
selected
<?php endif; ?>>
なし
</option>
<option value="tls"
<?php if (($m['encryption'] ?? '') === 'tls'): ?>
selected
<?php endif; ?>>
TLS
</option>
<option value="ssl"
<?php if (($m['encryption'] ?? '') === 'ssl'): ?>
selected
<?php endif; ?>>
SSL
</option>
</select>
</div>

<div class="form-row">
<label>
<input type="checkbox"
       name="auth"
       value="1"
<?php if (!empty($m['auth'])): ?>
checked
<?php endif; ?>>
SMTP認証を使用
</label>
</div>

<div class="form-grid">
<div class="form-row">
<label>SMTPユーザー名</label>
<input name="username"
       autocomplete="username"
       value="<?=e($m['username'] ?? '')?>">
</div>

<div class="form-row">
<label>SMTPパスワード</label>
<input name="password"
       type="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>
</div>

<div class="form-grid">
<div class="form-row">
<label>送信元メールアドレス</label>
<input name="from_email"
       type="email"
       required
       value="<?=e($m['from_email'] ?? '')?>">
</div>

<div class="form-row">
<label>送信元名</label>
<input name="from_name"
       value="<?=e($m['from_name'] ?? '')?>">
</div>
</div>

<div class="form-row">
<label>返信先メールアドレス</label>
<input name="reply_to"
       type="email"
       value="<?=e($m['reply_to'] ?? '')?>">
</div>

<button class="primary"
        type="submit">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続確認</h2>

<div class="test-panel">
<strong>
<?=e($m['connection_status'] ?? '未設定')?>
</strong>

<?php if (!empty($m['last_test_at'])): ?>
<br>
最終確認：
<?=e($m['last_test_at'])?>
<?php endif; ?>
</div>

<form method="post"
      data-busy>
<input type="hidden"
       name="action"
       value="<?=e(ACTION_TEST_MAIL)?>">
<button class="primary"
        type="submit">
接続テスト
</button>
<span class="spinner">
<span class="spinner-dot"></span>
SMTPへ接続確認中...
</span>
</form>
</div>

<div class="card">
<h2>テストメール送信</h2>

<form method="post"
      data-busy>
<input type="hidden"
       name="action"
       value="<?=e(ACTION_SEND_TEST_MAIL)?>">

<div class="form-row">
<label>送信先</label>
<input type="email"
       name="test_email"
       required>
</div>

<button class="primary"
        type="submit">
テストメール送信
</button>

<span class="spinner">
<span class="spinner-dot"></span>
送信中...
</span>
</form>
</div>
<?php
}

/* ============================================================
 * Answer UI
 * ============================================================ */

function render_answer_shell(
    string $screen,
    ?array $survey
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?=e(APP_NAME)?></title>
<style>
body{
    margin:0;
    background:#f8fafc;
    color:#1e293b;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        Meiryo,
        sans-serif;
}
.wrap{
    max-width:720px;
    margin:auto;
    padding:16px;
}
.card{
    background:#fff;
    border:1px solid #dbe2ea;
    border-radius:12px;
    padding:18px;
    margin-bottom:16px;
    box-shadow:0 4px 18px rgba(15,23,42,.08);
}
input,textarea{
    width:100%;
    box-sizing:border-box;
    padding:12px;
    border:1px solid #dbe2ea;
    border-radius:8px;
    font:inherit;
}
textarea{min-height:140px}
.choice{
    display:block;
    padding:14px;
    border:1px solid #dbe2ea;
    border-radius:8px;
    margin:8px 0;
}
button{
    min-height:44px;
    border:0;
    border-radius:8px;
    padding:10px 18px;
    background:#2563eb;
    color:#fff;
    font:inherit;
}
.actions{
    display:flex;
    gap:8px;
    justify-content:space-between;
}
</style>
</head>
<body>
<div class="wrap">
<?php

if ($survey === null) {
    echo '<div class="card">アンケートが存在しません。</div>';
    echo '</div></body></html>';
    return;
}

if ($screen === SCREEN_ANSWER) {
    render_answer_page($survey);
} elseif ($screen === SCREEN_CONFIRM) {
    render_confirm_page($survey);
} else {
    render_complete_page($survey);
}
?>
</div>
</body>
</html>
<?php
}

function render_answer_page(array $survey): void
{
    $questions = answer_questions($survey);
    $state = get_answer_state(
        (string)$survey['id']
    );

    $index = (int)($state['index'] ?? 0);

    if ($index >= count($questions)) {
        $index = max(
            0,
            count($questions) - 1
        );
    }

    if (!$questions) {
        ?>
<div class="card">
<h1><?=e($survey['title'])?></h1>
<p>現在、質問はありません。</p>
</div>
<?php
        return;
    }

    $question = $questions[$index];

    $current = $state['answers'][
        (string)$question['id']
    ] ?? '';

    ?>
<div class="card">
<h1><?=e($survey['title'])?></h1>
<p><?=nl2br(e($survey['description']))?></p>
</div>

<div class="card">
<p>
<strong>
<?=e($question['number'])?>
</strong>
</p>

<h2><?=nl2br(e($question['text']))?></h2>

<form method="post">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_ANSWER_NEXT)?>">
<input type="hidden"
       name="survey_id"
       value="<?=e($survey['id'])?>">

<?php if ($question['type'] === 'single'): ?>
<?php foreach ($question['options'] as $option): ?>
<label class="choice">
<input type="radio"
       name="answer_<?=e($question['id'])?>"
       value="<?=e($option['value'] ?? '')?>"
<?php if (
    (string)$current
    === (string)($option['value'] ?? '')
): ?>
checked
<?php endif; ?>>
<?=e($option['label'] ?? '')?>
</label>
<?php endforeach; ?>

<?php elseif ($question['type'] === 'multi'): ?>
<?php
$currentArray = is_array($current)
    ? $current
    : [];
?>
<?php foreach ($question['options'] as $option): ?>
<label class="choice">
<input type="checkbox"
       name="answer_<?=e($question['id'])?>[]"
       value="<?=e($option['value'] ?? '')?>"
<?php if (
    in_array(
        (string)($option['value'] ?? ''),
        array_map('strval',$currentArray),
        true
    )
): ?>
checked
<?php endif; ?>>
<?=e($option['label'] ?? '')?>
</label>
<?php endforeach; ?>

<?php else: ?>
<textarea
name="answer_<?=e($question['id'])?>"
<?=!empty($question['required']) ? 'required' : ''?>><?=e(
    is_string($current)
        ? $current
        : ''
)?></textarea>
<?php endif; ?>

<div class="actions"
     style="margin-top:16px">
<?php if ($index > 0): ?>
<button formaction="index.php"
        formmethod="post"
        name="action"
        value="<?=e(ACTION_ANSWER_BACK)?>">
戻る
</button>
<?php endif; ?>

<button type="submit">
<?=($index + 1 < count($questions))
    ? '次へ'
    : '回答確認へ'?>
</button>
</div>
</form>
</div>
<?php
}

function render_confirm_page(array $survey): void
{
    $state = get_answer_state(
        (string)$survey['id']
    );

    $questions = answer_questions($survey);
    ?>
<div class="card">
<h1>回答確認</h1>
<p><?=e($survey['title'])?></p>
</div>

<div class="card">
<?php foreach ($questions as $question): ?>
<div style="margin-bottom:18px">
<strong>
<?=e($question['number'])?>
<?=e($question['text'])?>
</strong>

<?php
$value = $state['answers'][
    (string)$question['id']
] ?? '';

if (is_array($value)) {
    $value = implode(
        ', ',
        array_map(
            'strval',
            $value
        )
    );
}
?>

<p><?=nl2br(e((string)$value))?></p>
</div>
<?php endforeach; ?>
</div>

<div class="card">
<form method="post"
      style="display:flex;gap:8px;justify-content:space-between">
<input type="hidden"
       name="action"
       value="<?=e(ACTION_ANSWER_SUBMIT)?>">
<input type="hidden"
       name="survey_id"
       value="<?=e($survey['id'])?>">
<input type="hidden"
       name="confirm_answer"
       value="1">

<a href="<?=e(
    screen_url(
        SCREEN_ANSWER,
        (string)$survey['id']
    )
)?>">
修正する
</a>

<button type="submit"
        onclick="return confirm('回答を送信しますか？')">
回答を送信
</button>
</form>
</div>
<?php
}

function render_complete_page(array $survey): void
{
    ?>
<div class="card">
<h1>回答完了</h1>
<p>
ご回答ありがとうございました。
</p>
</div>
<?php
}

/* ============================================================
 * Helpers
 * ============================================================ */

function datetime_local_value(string $value): string
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

?>
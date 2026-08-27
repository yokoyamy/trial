<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * index.php 単一エントリーポイント
 *
 * 対応:
 * - Apache 2.4
 * - PHP 8.5
 * - DBなし
 * - PHP cURLなし
 * - PHP mail()なし
 * - 管理者認証なし
 * - CSRFなし（要件）
 * - サーバー側JSON永続化
 * - PHPセッション利用
 * - GETごとのsession_regenerate_id()禁止
 * - セッションIDをURLへ出さない
 * - kintone X-Cybozu-Authorization
 * - SMTP直接通信
 *
 * 重要:
 * 設定保存等のPOSTは
 *
 *     POST -> 303 -> GET -> flash
 *
 * に依存しない。
 *
 * POST処理完了後、そのPOSTレスポンス内で
 * 成功/失敗メッセージを表示する。
 *
 * また、日本語を含む物理パスをCookie Pathへ
 * 直接渡さない。
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR       = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const ANSWERS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'answers.json';
const SEND_LOG_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'send_logs.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT    = 20;
const SMTP_CONNECT_TIMEOUT    = 10;
const SMTP_READ_TIMEOUT       = 20;

const STATUS_DRAFT     = 'draft';
const STATUS_PUBLISHED = 'published';
const STATUS_STOPPED   = 'stopped';
const STATUS_ENDED     = 'ended';

const QUESTION_SINGLE = 'single';
const QUESTION_MULTI  = 'multiple';
const QUESTION_TEXT   = 'textarea';


/* =========================================================
 * 初期化
 * ========================================================= */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

init_json_file(SETTINGS_FILE, default_settings());
init_json_file(SURVEYS_FILE, []);
init_json_file(CUSTOMERS_FILE, []);
init_json_file(ANSWERS_FILE, []);
init_json_file(SEND_LOG_FILE, []);


/* =========================================================
 * セッション
 *
 * 日本語の物理ディレクトリ名をCookie Pathへ直接渡さない。
 *
 * 今回の環境:
 *
 * /gojacic/.poc/draft/アンケートアプリ/
 *
 * をPHPがCookieヘッダーへ文字列化すると
 *
 * ã‚¢ãƒ³ã‚±ãƒ¼ãƒˆ...
 *
 * になる可能性がある。
 *
 * Cookie Pathはアプリの公開範囲を壊さないよう
 * "/" に固定する。
 *
 * これにより:
 * - 日本語パスの文字化けをCookie属性から排除
 * - GET/POST間で同じPHPSESSIDを利用
 * - Secure / HttpOnly / SameSiteを統一
 *
 * GETごとのsession_regenerate_id()は行わない。
 * ========================================================= */

$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できません。');
    }
}


/* =========================================================
 * 画面
 * ========================================================= */

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

$message = null;
$messageType = '';


/* =========================================================
 * POST処理
 *
 * 設定保存を含め、303 + flashに依存しない。
 * POSTの処理結果をそのまま同一レスポンスで表示する。
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');

        switch ($action) {
            case 'save_kintone':
                $message = action_save_kintone();
                $messageType = 'success';
                $screen = 'kintone';
                break;

            case 'test_kintone':
                $message = action_test_kintone();
                $messageType = 'success';
                $screen = 'kintone';
                break;

            case 'fetch_kintone_fields':
                $message = action_fetch_kintone_fields();
                $messageType = 'success';
                $screen = 'kintone';
                break;

            case 'sync_kintone':
                $message = action_sync_kintone();
                $messageType = 'success';
                $screen = 'kintone';
                break;

            case 'save_mail':
                $message = action_save_mail();
                $messageType = 'success';
                $screen = 'mail';
                break;

            case 'test_mail':
                $message = action_test_mail();
                $messageType = 'success';
                $screen = 'mail';
                break;

            case 'send_test_mail':
                $message = action_send_test_mail();
                $messageType = 'success';
                $screen = 'mail';
                break;

            case 'save_survey':
                $result = action_save_survey();
                $message = $result['message'];
                $messageType = 'success';
                $screen = 'list';
                break;

            case 'delete_survey':
                $message = action_delete_survey();
                $messageType = 'success';
                $screen = 'list';
                break;

            case 'duplicate_survey':
                $message = action_duplicate_survey();
                $messageType = 'success';
                $screen = 'list';
                break;

            case 'change_status':
                $message = action_change_status();
                $messageType = 'success';
                $screen = 'list';
                break;

            case 'save_questions':
                $result = action_save_questions();
                $message = $result['message'];
                $messageType = 'success';
                $screen = 'edit';
                $_GET['id'] = $result['id'];
                break;

            case 'send_mail':
                $result = action_send_mail();
                $message = $result['message'];
                $messageType = $result['success']
                    ? 'success'
                    : 'error';
                $screen = 'send';
                $_GET['id'] = $result['id'];
                break;

            case 'resend_mail':
                $result = action_resend_mail();
                $message = $result['message'];
                $messageType = $result['success']
                    ? 'success'
                    : 'error';
                $screen = 'send';
                $_GET['id'] = $result['id'];
                break;

            case 'remind_mail':
                $result = action_remind_mail();
                $message = $result['message'];
                $messageType = $result['success']
                    ? 'success'
                    : 'error';
                $screen = 'send';
                $_GET['id'] = $result['id'];
                break;

            case 'answer_next':
                $result = action_answer_next();
                $screen = 'answer';
                $_GET['id'] = $result['id'];
                break;

            case 'answer_back':
                $result = action_answer_back();
                $screen = 'answer';
                $_GET['id'] = $result['id'];
                break;

            case 'answer_submit':
                $result = action_answer_submit();
                $message = $result['message'];
                $messageType = 'success';
                $screen = 'complete';
                $_GET['id'] = $result['id'];
                break;

            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }

    } catch (Throwable $e) {
        $messageType = 'error';
        $message = public_error_message($e);
    }
}


/* =========================================================
 * アンケート状態の自動更新
 *
 * published + endAt経過だけended。
 * draft / stoppedは終了日時を過ぎても変更しない。
 * ========================================================= */

$surveys = read_json(SURVEYS_FILE);

if (auto_update_expired_surveys($surveys)) {
    write_json_atomic(SURVEYS_FILE, $surveys);
}


/* =========================================================
 * 対象アンケート
 * ========================================================= */

$survey = null;

if (isset($_GET['id'])) {
    $id = valid_id((string)$_GET['id']);

    if ($id !== '') {
        $survey = find_survey($id);
    }
}

if (
    in_array(
        $screen,
        ['send', 'analytics'],
        true
    )
    && $survey === null
) {
    $screen = 'list';
    $messageType = 'error';
    $message = '対象アンケートが指定されていません。';
}


/* =========================================================
 * CSV / PDF
 * ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['export'])
) {
    $export = (string)$_GET['export'];

    if ($export === 'csv') {
        export_csv();
    }

    if ($export === 'pdf') {
        export_pdf();
    }
}


/* =========================================================
 * HTML
 * ========================================================= */

if (is_respondent_screen($screen)) {
    render_respondent(
        $screen,
        $survey,
        $message,
        $messageType
    );
    exit;
}

render_admin_header($screen, $message, $messageType);

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

    default:
        render_list();
        break;
}

render_admin_footer();


/* =========================================================
 * DEFAULT SETTINGS
 * ========================================================= */

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
            'fields' => [],
            'connection_status' => '未設定',
            'last_test_at' => null,
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
            'connection_status' => '未設定',
            'last_test_at' => null,
        ],
    ];
}


/* =========================================================
 * JSON
 * ========================================================= */

function init_json_file(
    string $file,
    array $default
): void {
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

    $data = json_decode(
        $raw,
        true
    );

    if (!is_array($data)) {
        throw new RuntimeException(
            'JSONデータが壊れています。'
        );
    }

    return $data;
}

function write_json_atomic(
    string $file,
    array $data
): void {
    $dir = dirname($file);

    $tmp = tempnam($dir, 'survey_');

    if ($tmp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        @unlink($tmp);
        throw new RuntimeException(
            'JSONデータを生成できません。'
        );
    }

    if (
        file_put_contents(
            $tmp,
            $json . PHP_EOL,
            LOCK_EX
        ) === false
    ) {
        @unlink($tmp);
        throw new RuntimeException(
            'JSONデータを書き込めません。'
        );
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException(
            'JSONデータを保存できません。'
        );
    }
}


/* =========================================================
 * 共通
 * ========================================================= */

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

function valid_id(string $id): string
{
    $id = trim($id);

    if ($id === '') {
        return '';
    }

    if (
        !preg_match(
            '/^[A-Za-z0-9._-]{1,100}$/',
            $id
        )
    ) {
        return '';
    }

    return $id;
}

function is_respondent_screen(string $screen): bool
{
    return in_array(
        $screen,
        ['answer', 'confirm', 'complete'],
        true
    );
}

function screen_url(
    string $screen,
    ?string $id = null
): string {
    $url =
        'index.php?screen='
        . rawurlencode($screen);

    if ($id !== null && $id !== '') {
        $url .=
            '&id='
            . rawurlencode($id);
    }

    return $url;
}

function public_error_message(
    Throwable $e
): string {
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }

    return '処理に失敗しました。設定値、データ、通信状態を確認してください。';
}


/* =========================================================
 * アンケート
 * ========================================================= */

function find_survey(
    string $id
): ?array {
    foreach (
        read_json(SURVEYS_FILE)
        as $survey
    ) {
        if (
            (string)($survey['id'] ?? '')
            === $id
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
    foreach ($surveys as $index => $survey) {
        if (
            (string)($survey['id'] ?? '')
            === $id
        ) {
            return $index;
        }
    }

    return -1;
}

function auto_update_expired_surveys(
    array &$surveys
): bool {
    $changed = false;
    $now = time();

    foreach ($surveys as &$survey) {
        if (
            ($survey['status'] ?? '')
            !== STATUS_PUBLISHED
        ) {
            continue;
        }

        $endAt = trim(
            (string)($survey['endAt'] ?? '')
        );

        if ($endAt === '') {
            continue;
        }

        $timestamp = strtotime($endAt);

        if (
            $timestamp !== false
            && $timestamp < $now
        ) {
            $survey['status'] = STATUS_ENDED;
            $survey['updatedAt'] = now_iso();
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function validate_survey_input(
    array $data
): array {
    $title = trim(
        (string)($data['title'] ?? '')
    );

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルは必須です。'
        );
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException(
            'アンケートタイトルは200文字以内です。'
        );
    }

    $description = trim(
        (string)($data['description'] ?? '')
    );

    $startAt = trim(
        (string)($data['startAt'] ?? '')
    );

    $endAt = trim(
        (string)($data['endAt'] ?? '')
    );

    if ($startAt !== false && $startAt !== '') {
        if (strtotime($startAt) === false) {
            throw new InvalidArgumentException(
                '開始日時が不正です。'
            );
        }
    }

    if ($endAt !== '') {
        if (strtotime($endAt) === false) {
            throw new InvalidArgumentException(
                '終了日時が不正です。'
            );
        }
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) > strtotime($endAt)
    ) {
        throw new InvalidArgumentException(
            '終了日時は開始日時以降にしてください。'
        );
    }

    $numbering =
        (string)($data['numbering'] ?? 'global');

    if (
        !in_array(
            $numbering,
            ['global', 'group'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '採番方式が不正です。'
        );
    }

    return [
        'title' => $title,
        'description' => $description,
        'startAt' => $startAt,
        'endAt' => $endAt,
        'numbering' => $numbering,
    ];
}


/* =========================================================
 * アンケート保存
 * ========================================================= */

function action_save_survey(): array
{
    $surveys = read_json(SURVEYS_FILE);

    $id = valid_id(
        (string)($_POST['id'] ?? '')
    );

    $data = validate_survey_input($_POST);

    if ($id === '') {
        $id = uuid();

        $surveys[] = [
            'id' => $id,
            'title' => $data['title'],
            'description' => $data['description'],
            'startAt' => $data['startAt'],
            'endAt' => $data['endAt'],
            'status' => STATUS_DRAFT,
            'numbering' => $data['numbering'],
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
    } else {
        $index = find_survey_index(
            $surveys,
            $id
        );

        if ($index < 0) {
            throw new InvalidArgumentException(
                '対象アンケートが存在しません。'
            );
        }

        if (
            ($surveys[$index]['status'] ?? '')
            === STATUS_ENDED
        ) {
            /*
             * 終了状態でも内容編集自体は可能。
             * 状態だけは変更しない。
             */
        }

        $surveys[$index]['title'] =
            $data['title'];

        $surveys[$index]['description'] =
            $data['description'];

        $surveys[$index]['startAt'] =
            $data['startAt'];

        $surveys[$index]['endAt'] =
            $data['endAt'];

        $surveys[$index]['numbering'] =
            $data['numbering'];

        $surveys[$index]['updatedAt'] =
            now_iso();
    }

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    return [
        'id' => $id,
        'message' =>
            'アンケートを保存しました。',
    ];
}


/* =========================================================
 * 削除
 * ========================================================= */

function action_delete_survey(): string
{
    $id = valid_id(
        (string)($_POST['id'] ?? '')
    );

    if ($id === '') {
        throw new InvalidArgumentException(
            '削除対象が指定されていません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);

    $index = find_survey_index(
        $surveys,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    array_splice(
        $surveys,
        $index,
        1
    );

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    return 'アンケートを削除しました。';
}


/* =========================================================
 * 複製
 * ========================================================= */

function action_duplicate_survey(): string
{
    $id = valid_id(
        (string)($_POST['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        throw new InvalidArgumentException(
            '複製対象が存在しません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);

    $copy = $survey;

    $copy['id'] = uuid();

    $copy['title'] =
        (string)($copy['title'] ?? '')
        . '（コピー）';

    $copy['status'] = STATUS_DRAFT;
    $copy['createdAt'] = now_iso();
    $copy['updatedAt'] = now_iso();

    $surveys[] = $copy;

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    return 'アンケートを複製しました。';
}


/* =========================================================
 * 状態変更
 *
 * 一覧上には状態変更UIを置かない。
 * 編集画面からのみ実行する。
 * ========================================================= */

function action_change_status(): string
{
    $id = valid_id(
        (string)($_POST['id'] ?? '')
    );

    $status = (string)(
        $_POST['status'] ?? ''
    );

    $allowed = [
        STATUS_DRAFT,
        STATUS_PUBLISHED,
        STATUS_STOPPED,
    ];

    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException(
            '指定された状態へ変更できません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);

    $index = find_survey_index(
        $surveys,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    $current =
        (string)($surveys[$index]['status'] ?? '');

    if ($current === STATUS_ENDED) {
        throw new InvalidArgumentException(
            '終了状態から変更することはできません。'
        );
    }

    $validTransition = (
        ($current === STATUS_DRAFT
            && $status === STATUS_PUBLISHED)
        ||
        ($current === STATUS_PUBLISHED
            && $status === STATUS_STOPPED)
        ||
        ($current === STATUS_STOPPED
            && $status === STATUS_PUBLISHED)
        ||
        ($current === $status)
    );

    if (!$validTransition) {
        throw new InvalidArgumentException(
            '許可されていない状態変更です。'
        );
    }

    $surveys[$index]['status'] =
        $status;

    $surveys[$index]['updatedAt'] =
        now_iso();

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    return 'アンケートの状態を変更しました。';
}


/* =========================================================
 * 質問
 * ========================================================= */

function normalize_questions(
    array &$survey
): void {
    $groups = $survey['groups'] ?? [];

    if (!is_array($groups)) {
        $groups = [];
    }

    $numbering =
        (string)($survey['numbering'] ?? 'global');

    $globalNumber = 1;

    foreach ($groups as $groupIndex => &$group) {
        if (!isset($group['id'])) {
            $group['id'] = uuid();
        }

        if (!isset($group['title'])) {
            $group['title'] =
                'グループ'
                . ($groupIndex + 1);
        }

        if (!isset($group['questions'])
            || !is_array($group['questions'])
        ) {
            $group['questions'] = [];
        }

        $questionNumber = 1;

        foreach (
            $group['questions']
            as &$question
        ) {
            if (!isset($question['id'])) {
                $question['id'] = uuid();
            }

            if (
                !in_array(
                    $question['type'] ?? '',
                    [
                        QUESTION_SINGLE,
                        QUESTION_MULTI,
                        QUESTION_TEXT,
                    ],
                    true
                )
            ) {
                $question['type'] =
                    QUESTION_SINGLE;
            }

            if (!isset($question['text'])) {
                $question['text'] = '';
            }

            if (!isset($question['required'])) {
                $question['required'] = false;
            }

            if (
                !isset($question['options'])
                || !is_array($question['options'])
            ) {
                $question['options'] = [];
            }

            if (
                !isset($question['branches'])
                || !is_array($question['branches'])
            ) {
                $question['branches'] = [];
            }

            if ($numbering === 'group') {
                $question['number'] =
                    'Q'
                    . ($groupIndex + 1)
                    . '-'
                    . $questionNumber;
            } else {
                $question['number'] =
                    'Q'
                    . $globalNumber;
            }

            $questionNumber++;
            $globalNumber++;
        }

        unset($question);
    }

    unset($group);

    $survey['groups'] = $groups;
}

function action_save_questions(): array
{
    $id = valid_id(
        (string)($_POST['id'] ?? '')
    );

    $surveys = read_json(SURVEYS_FILE);

    $index = find_survey_index(
        $surveys,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    $groupsRaw =
        (string)($_POST['groups_json'] ?? '[]');

    $groups = json_decode(
        $groupsRaw,
        true
    );

    if (!is_array($groups)) {
        throw new InvalidArgumentException(
            '質問データが不正です。'
        );
    }

    $surveys[$index]['groups'] =
        sanitize_groups($groups);

    normalize_questions(
        $surveys[$index]
    );

    $surveys[$index]['updatedAt'] =
        now_iso();

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    return [
        'id' => $id,
        'message' =>
            '質問・グループを保存しました。',
    ];
}

function sanitize_groups(
    array $groups
): array {
    $result = [];

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $newGroup = [
            'id' => valid_id(
                (string)($group['id'] ?? '')
            ) ?: uuid(),

            'title' => mb_substr(
                trim(
                    (string)(
                        $group['title'] ?? ''
                    )
                ),
                0,
                200
            ),

            'questions' => [],
        ];

        if (
            $newGroup['title'] === ''
        ) {
            $newGroup['title'] = 'グループ';
        }

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {
            if (!is_array($question)) {
                continue;
            }

            $type =
                (string)($question['type'] ?? '');

            if (
                !in_array(
                    $type,
                    [
                        QUESTION_SINGLE,
                        QUESTION_MULTI,
                        QUESTION_TEXT,
                    ],
                    true
                )
            ) {
                $type = QUESTION_SINGLE;
            }

            $options = [];

            foreach (
                ($question['options'] ?? [])
                as $option
            ) {
                if (is_array($option)) {
                    $optionText =
                        trim(
                            (string)(
                                $option['text'] ?? ''
                            )
                        );

                    if ($optionText !== '') {
                        $options[] = [
                            'id' =>
                                valid_id(
                                    (string)(
                                        $option['id']
                                        ?? ''
                                    )
                                ) ?: uuid(),
                            'text' =>
                                mb_substr(
                                    $optionText,
                                    0,
                                    500
                                ),
                        ];
                    }
                }
            }

            $newGroup['questions'][] = [
                'id' =>
                    valid_id(
                        (string)(
                            $question['id']
                            ?? ''
                        )
                    ) ?: uuid(),

                'text' =>
                    mb_substr(
                        trim(
                            (string)(
                                $question['text']
                                ?? ''
                            )
                        ),
                        0,
                        2000
                    ),

                'type' => $type,

                'required' =>
                    !empty(
                        $question['required']
                    ),

                'options' => $options,

                'branches' =>
                    is_array(
                        $question['branches']
                        ?? null
                    )
                        ? $question['branches']
                        : [],
            ];
        }

        $result[] = $newGroup;
    }

    if (!$result) {
        $result[] = [
            'id' => uuid(),
            'title' => 'グループ1',
            'questions' => [],
        ];
    }

    return $result;
}


/* =========================================================
 * kintone
 * ========================================================= */

function normalize_kintone_subdomain(
    string $value
): string {
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = trim(
        $value,
        "/ \t\r\n"
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

function validate_kintone(
    array $k
): void {
    normalize_kintone_subdomain(
        (string)(
            $k['subdomain'] ?? ''
        )
    );

    $appId =
        trim(
            (string)(
                $k['app_id'] ?? ''
            )
        );

    if (
        $appId === ''
        || !ctype_digit($appId)
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    if (
        trim(
            (string)(
                $k['username'] ?? ''
            )
        ) === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    if (
        (string)(
            $k['password'] ?? ''
        ) === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    $proxy =
        trim(
            (string)(
                $k['proxy'] ?? ''
            )
        );

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で指定してください。'
        );
    }
}

function action_save_kintone(): string
{
    $settings = read_json(
        SETTINGS_FILE
    );

    $oldPassword =
        (string)(
            $settings['kintone']['password']
            ?? ''
        );

    $subdomain =
        normalize_kintone_subdomain(
            (string)(
                $_POST['subdomain'] ?? ''
            )
        );

    $appId =
        trim(
            (string)(
                $_POST['app_id'] ?? ''
            )
        );

    if (
        $appId === ''
        || !ctype_digit($appId)
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $username =
        trim(
            (string)(
                $_POST['username'] ?? ''
            )
        );

    if ($username === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    $password =
        (string)(
            $_POST['password'] ?? ''
        );

    if ($password === '') {
        $password = $oldPassword;
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    $proxy =
        trim(
            (string)(
                $_POST['proxy'] ?? ''
            )
        );

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で指定してください。'
        );
    }

    $settings['kintone']['subdomain'] =
        $subdomain;

    $settings['kintone']['app_id'] =
        $appId;

    $settings['kintone']['username'] =
        $username;

    $settings['kintone']['password'] =
        $password;

    $settings['kintone']['proxy'] =
        $proxy;

    /*
     * POC初期値は無効。
     * チェックされた場合だけ有効。
     */
    $settings['kintone']['verify_ssl'] =
        isset($_POST['verify_ssl']);

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    /*
     * ここで303しない。
     * ここでflashをsessionへ保存しない。
     * 現在のPOSTレスポンスで成功表示する。
     */

    return 'kintone設定を保存しました。';
}

function kintone_request(
    array $k,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    validate_kintone($k);

    $subdomain =
        normalize_kintone_subdomain(
            (string)$k['subdomain']
        );

    $host =
        $subdomain
        . '.cybozu.com';

    $auth = base64_encode(
        (string)$k['username']
        . ':'
        . (string)$k['password']
    );

    $url =
        'https://'
        . $host
        . '/k/v1'
        . $path;

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
    ];

    $payload = '';

    if ($body !== null) {
        $payload = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
        );

        if ($payload === false) {
            throw new RuntimeException(
                'kintoneリクエストを生成できません。'
            );
        }

        $headers[] =
            'Content-Type: application/json';

        $headers[] =
            'Content-Length: '
            . strlen($payload);
    }

    $contextOptions = [
        'http' => [
            'method' => $method,
            'timeout' => KINTONE_READ_TIMEOUT,
            'ignore_errors' => true,
            'header' =>
                implode(
                    "\r\n",
                    $headers
                )
                . "\r\n",
            'content' => $payload,
        ],
        'ssl' => [
            'verify_peer' =>
                (bool)$k['verify_ssl'],
            'verify_peer_name' =>
                (bool)$k['verify_ssl'],
            'allow_self_signed' =>
                !(bool)$k['verify_ssl'],
        ],
    ];

    $proxy =
        trim(
            (string)(
                $k['proxy'] ?? ''
            )
        );

    if ($proxy !== '') {
        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']['request_fulluri'] =
            true;
    }

    $context =
        stream_context_create(
            $contextOptions
        );

    $responseBody =
        @file_get_contents(
            $url,
            false,
            $context
        );

    if ($responseBody === false) {
        throw new RuntimeException(
            'kintoneへの通信に失敗しました。'
        );
    }

    $status = 0;

    foreach (
        $http_response_header ?? []
        as $header
    ) {
        if (
            preg_match(
                '#^HTTP/\S+\s+(\d+)#',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
            break;
        }
    }

    return [
        'status' => $status,
        'body' => $responseBody,
    ];
}

function action_test_kintone(): string
{
    $settings =
        read_json(SETTINGS_FILE);

    $k =
        $settings['kintone']
        ?? [];

    validate_kintone($k);

    try {
        $result = kintone_request(
            $k,
            '/app.json?id='
            . rawurlencode(
                (string)$k['app_id']
            ),
            'GET'
        );

        if (
            $result['status'] >= 200
            && $result['status'] < 300
        ) {
            $settings['kintone']
                ['connection_status'] =
                '接続確認済み';

            $settings['kintone']
                ['last_test_at'] =
                now_iso();

            write_json_atomic(
                SETTINGS_FILE,
                $settings
            );

            return 'kintoneへの接続に成功しました。';
        }

        $settings['kintone']
            ['connection_status'] =
            '接続できません';

        $settings['kintone']
            ['last_test_at'] =
            now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        return
            'kintoneへの接続に失敗しました。'
            . ' HTTP '
            . $result['status']
            . '。'
            . kintone_error_message(
                $result
            );
    } catch (Throwable $e) {
        $settings['kintone']
            ['connection_status'] =
            '接続できません';

        $settings['kintone']
            ['last_test_at'] =
            now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        throw $e;
    }
}

function kintone_error_message(
    array $result
): string {
    $data = json_decode(
        (string)(
            $result['body'] ?? ''
        ),
        true
    );

    if (!is_array($data)) {
        return '';
    }

    $message =
        trim(
            (string)(
                $data['message'] ?? ''
            )
        );

    return $message === ''
        ? ''
        : ' ' . $message;
}

function action_fetch_kintone_fields(): string
{
    $settings =
        read_json(SETTINGS_FILE);

    $k =
        $settings['kintone']
        ?? [];

    validate_kintone($k);

    $result = kintone_request(
        $k,
        '/app/form/fields.json?app='
        . rawurlencode(
            (string)$k['app_id']
        ),
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        throw new RuntimeException(
            'kintone項目一覧の取得に失敗しました。'
            . kintone_error_message($result)
        );
    }

    $data = json_decode(
        (string)$result['body'],
        true
    );

    if (!is_array($data)) {
        throw new RuntimeException(
            'kintone項目一覧の形式が不正です。'
        );
    }

    $fields = [];

    foreach (
        ($data['properties'] ?? [])
        as $code => $field
    ) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' =>
                (string)(
                    $field['label'] ?? $code
                ),
            'type' =>
                (string)(
                    $field['type'] ?? ''
                ),
        ];
    }

    $settings['kintone']['fields'] =
        $fields;

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    return
        'kintoneの項目一覧を再取得しました。';
}

function action_sync_kintone(): string
{
    $settings =
        read_json(SETTINGS_FILE);

    $k =
        $settings['kintone']
        ?? [];

    validate_kintone($k);

    $query =
        '?app='
        . rawurlencode(
            (string)$k['app_id']
        )
        . '&totalCount=true';

    $result = kintone_request(
        $k,
        '/records.json'
        . $query,
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        throw new RuntimeException(
            'kintone顧客情報の取得に失敗しました。'
            . kintone_error_message($result)
        );
    }

    $data = json_decode(
        (string)$result['body'],
        true
    );

    if (!is_array($data)) {
        throw new RuntimeException(
            'kintone顧客データの形式が不正です。'
        );
    }

    $mapping =
        $k['field_mapping']
        ?? [];

    $customers = [];

    foreach (
        ($data['records'] ?? [])
        as $record
    ) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => uuid(),
            'organization' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['organization']
                        ?? ''
                    )
                ),

            'name' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['name']
                        ?? ''
                    )
                ),

            'email' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['email']
                        ?? ''
                    )
                ),

            'department' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['department']
                        ?? ''
                    )
                ),

            'phone' =>
                kintone_record_value(
                    $record,
                    (string)(
                        $mapping['phone']
                        ?? ''
                    )
                ),

            'address' =>
                kintone_record_address(
                    $record,
                    $mapping['address']
                    ?? []
                ),

            'syncedAt' => now_iso(),
        ];
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    return
        count($customers)
        . '件の顧客情報を同期しました。';
}

function kintone_record_value(
    array $record,
    string $code
): string {
    if (
        $code === ''
        || !isset($record[$code])
    ) {
        return '';
    }

    $value = $record[$code];

    if (
        is_array($value)
        && array_key_exists(
            'value',
            $value
        )
    ) {
        $value = $value['value'];
    }

    if (is_array($value)) {
        $values = [];

        foreach ($value as $v) {
            if (is_scalar($v)) {
                $values[] = (string)$v;
            }
        }

        return implode(
            ', ',
            $values
        );
    }

    return is_scalar($value)
        ? (string)$value
        : '';
}

function kintone_record_address(
    array $record,
    array $codes
): string {
    $values = [];

    foreach ($codes as $code) {
        $code = trim((string)$code);

        if ($code === '') {
            continue;
        }

        $value =
            kintone_record_value(
                $record,
                $code
            );

        if ($value !== '') {
            $values[] = $value;
        }
    }

    return implode(
        ' ',
        $values
    );
}


/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail_settings(
    array $mail
): void {
    $host =
        trim(
            (string)(
                $mail['host'] ?? ''
            )
        );

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    $port =
        (int)($mail['port'] ?? 0);

    if (
        $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (
        !in_array(
            $mail['encryption'] ?? '',
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    $from =
        trim(
            (string)(
                $mail['from_email'] ?? ''
            )
        );

    if (
        !filter_var(
            $from,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }
}

function action_save_mail(): string
{
    $settings =
        read_json(SETTINGS_FILE);

    $mail =
        $settings['mail']
        ?? [];

    $password =
        (string)(
            $_POST['password']
            ?? ''
        );

    if ($password === '') {
        $password =
            (string)(
                $mail['password']
                ?? ''
            );
    }

    $mail['host'] =
        trim(
            (string)(
                $_POST['host'] ?? ''
            )
        );

    $mail['port'] =
        (int)(
            $_POST['port'] ?? 0
        );

    $mail['encryption'] =
        (string)(
            $_POST['encryption']
            ?? 'tls'
        );

    $mail['auth'] =
        isset($_POST['auth']);

    $mail['username'] =
        trim(
            (string)(
                $_POST['username']
                ?? ''
            )
        );

    $mail['password'] =
        $password;

    $mail['from_email'] =
        trim(
            (string)(
                $_POST['from_email']
                ?? ''
            )
        );

    $mail['from_name'] =
        trim(
            (string)(
                $_POST['from_name']
                ?? ''
            )
        );

    $mail['reply_to'] =
        trim(
            (string)(
                $_POST['reply_to']
                ?? ''
            )
        );

    validate_mail_settings(
        $mail
    );

    if (
        $mail['reply_to'] !== ''
        && !filter_var(
            $mail['reply_to'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    $settings['mail'] = $mail;

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    /*
     * 303しない。
     */
    return 'メール設定を保存しました。';
}

function smtp_open(
    array $mail
): array {
    validate_mail_settings($mail);

    $host =
        (string)$mail['host'];

    $port =
        (int)$mail['port'];

    $encryption =
        (string)$mail['encryption'];

    if ($encryption === 'ssl') {
        $target =
            'ssl://' . $host
            . ':' . $port;
    } else {
        $target =
            'tcp://' . $host
            . ':' . $port;
    }

    $errno = 0;
    $error = '';

    $fp = @stream_socket_client(
        $target,
        $errno,
        $error,
        SMTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。'
            . ' '
            . $error
        );
    }

    stream_set_timeout(
        $fp,
        SMTP_READ_TIMEOUT
    );

    smtp_expect(
        $fp,
        [220]
    );

    smtp_command(
        $fp,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'tls') {
        smtp_command(
            $fp,
            'STARTTLS',
            [220]
        );

        $crypto = @stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($fp);

            throw new RuntimeException(
                'SMTP TLS接続に失敗しました。'
            );
        }

        smtp_command(
            $fp,
            'EHLO localhost',
            [250]
        );
    }

    if (
        !empty($mail['auth'])
    ) {
        $username =
            (string)(
                $mail['username']
                ?? ''
            );

        $password =
            (string)(
                $mail['password']
                ?? ''
            );

        if (
            $username === ''
            || $password === ''
        ) {
            fclose($fp);

            throw new RuntimeException(
                'SMTP認証情報が設定されていません。'
            );
        }

        smtp_command(
            $fp,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $fp,
            base64_encode($username),
            [334]
        );

        smtp_command(
            $fp,
            base64_encode($password),
            [235]
        );
    }

    return $fp;
}

function smtp_command(
    $fp,
    string $command,
    array $expected
): string {
    fwrite(
        $fp,
        $command . "\r\n"
    );

    return smtp_expect(
        $fp,
        $expected
    );
}

function smtp_expect(
    $fp,
    array $expected
): string {
    $response = '';

    while (!feof($fp)) {
        $line = fgets($fp);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([ -])/',
                $line,
                $m
            )
        ) {
            if ($m[2] === ' ') {
                $code = (int)$m[1];

                if (
                    !in_array(
                        $code,
                        $expected,
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'SMTPエラー: '
                        . $code
                    );
                }

                break;
            }
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPから応答がありません。'
        );
    }

    return $response;
}

function action_test_mail(): string
{
    $settings =
        read_json(SETTINGS_FILE);

    $mail =
        $settings['mail']
        ?? [];

    $fp = smtp_open($mail);

    smtp_command(
        $fp,
        'QUIT',
        [221]
    );

    fclose($fp);

    $settings['mail']
        ['connection_status'] =
        '接続確認済み';

    $settings['mail']
        ['last_test_at'] =
        now_iso();

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    return
        'SMTPサーバーへの接続に成功しました。';
}

function send_smtp_mail(
    array $mail,
    string $to,
    string $subject,
    string $body
): void {
    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信先メールアドレスが不正です。'
        );
    }

    $fp = smtp_open($mail);

    $from =
        (string)$mail['from_email'];

    smtp_command(
        $fp,
        'MAIL FROM:<'
        . $from
        . '>',
        [250]
    );

    smtp_command(
        $fp,
        'RCPT TO:<'
        . $to
        . '>',
        [250, 251]
    );

    smtp_command(
        $fp,
        'DATA',
        [354]
    );

    $headers = [];

    $headers[] =
        'From: '
        . encode_mail_header(
            (string)(
                $mail['from_name']
                ?? ''
            )
        )
        . ' <'
        . $from
        . '>';

    $headers[] =
        'To: <'
        . $to
        . '>';

    if (
        !empty($mail['reply_to'])
    ) {
        $headers[] =
            'Reply-To: <'
            . $mail['reply_to']
            . '>';
    }

    $headers[] =
        'Subject: '
        . mb_encode_mimeheader(
            $subject,
            'UTF-8'
        );

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

    smtp_command(
        $fp,
        $message,
        [250]
    );

    smtp_command(
        $fp,
        'QUIT',
        [221]
    );

    fclose($fp);
}

function encode_mail_header(
    string $name
): string {
    if ($name === '') {
        return '';
    }

    return mb_encode_mimeheader(
        $name,
        'UTF-8'
    );
}

function normalize_smtp_body(
    string $body
): string {
    $body = str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );

    return str_replace(
        "\n",
        "\r\n",
        $body
    );
}

function action_send_test_mail(): string
{
    $settings =
        read_json(SETTINGS_FILE);

    $mail =
        $settings['mail']
        ?? [];

    $to =
        trim(
            (string)(
                $_POST['test_to']
                ?? ''
            )
        );

    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが不正です。'
        );
    }

    send_smtp_mail(
        $mail,
        $to,
        'アンケートアプリ テストメール',
        'SMTP接続およびメール送信のテストです。'
    );

    return
        'テストメールを送信しました。';
}


/* =========================================================
 * 送信
 * ========================================================= */

function replace_mail_variables(
    string $text,
    array $customer,
    string $surveyUrl
): string {
    return str_replace(
        [
            '{顧客名}',
            '{アンケートURL}',
        ],
        [
            (string)(
                $customer['name'] ?? ''
            ),
            $surveyUrl,
        ],
        $text
    );
}

function answer_url(
    string $id
): string {
    /*
     * 外部公開URLを安全に組み立てる。
     * Hostヘッダーは信用しない。
     *
     * HTTPS/HTTPは実際の接続状態から判断。
     */
    $scheme =
        (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

    $script =
        (string)(
            $_SERVER['SCRIPT_NAME']
            ?? '/index.php'
        );

    return
        $scheme
        . '://'
        . (string)(
            $_SERVER['SERVER_NAME']
            ?? 'localhost'
        )
        . $script
        . '?screen=answer&id='
        . rawurlencode($id);
}

function action_send_mail(): array
{
    $id = valid_id(
        (string)($_POST['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    $customerIds =
        $_POST['customer_ids']
        ?? [];

    if (!is_array($customerIds)) {
        $customerIds = [];
    }

    $customers =
        read_json(CUSTOMERS_FILE);

    $selected = [];

    foreach ($customers as $customer) {
        $customerId =
            (string)(
                $customer['id'] ?? ''
            );

        if (
            in_array(
                $customerId,
                $customerIds,
                true
            )
        ) {
            $selected[] = $customer;
        }
    }

    if (!$selected) {
        throw new InvalidArgumentException(
            '送信対象の顧客を選択してください。'
        );
    }

    $settings =
        read_json(SETTINGS_FILE);

    $mail =
        $settings['mail']
        ?? [];

    validate_mail_settings($mail);

    $subject =
        trim(
            (string)(
                $_POST['subject'] ?? ''
            )
        );

    if ($subject === '') {
        throw new InvalidArgumentException(
            'メール件名を入力してください。'
        );
    }

    $body =
        (string)(
            $_POST['body'] ?? ''
        );

    if (trim($body) === '') {
        throw new InvalidArgumentException(
            'メール本文を入力してください。'
        );
    }

    $logs =
        read_json(SEND_LOG_FILE);

    $success = 0;
    $failed = 0;

    foreach ($selected as $customer) {
        $email =
            trim(
                (string)(
                    $customer['email'] ?? ''
                )
            );

        $customerName =
            (string)(
                $customer['name'] ?? ''
            );

        $url =
            answer_url($id);

        $actualSubject =
            replace_mail_variables(
                $subject,
                $customer,
                $url
            );

        $actualBody =
            replace_mail_variables(
                $body,
                $customer,
                $url
            );

        try {
            send_smtp_mail(
                $mail,
                $email,
                $actualSubject,
                $actualBody
            );

            $status = 'sent';
            $error = '';
            $success++;

        } catch (Throwable $e) {
            $status = 'failed';
            $error =
                public_error_message($e);
            $failed++;
        }

        $logs[] = [
            'id' => uuid(),
            'surveyId' => $id,
            'customerId' =>
                (string)(
                    $customer['id'] ?? ''
                ),
            'customerName' =>
                $customerName,
            'email' => $email,
            'subject' =>
                $actualSubject,
            'status' => $status,
            'error' => $error,
            'type' => 'initial',
            'createdAt' => now_iso(),
        ];
    }

    write_json_atomic(
        SEND_LOG_FILE,
        $logs
    );

    return [
        'id' => $id,
        'success' => $failed === 0,
        'message' =>
            '送信完了: '
            . $success
            . '件成功 / '
            . $failed
            . '件失敗',
    ];
}

function action_resend_mail(): array
{
    return action_send_mail_with_type(
        'resend'
    );
}

function action_remind_mail(): array
{
    return action_send_mail_with_type(
        'remind'
    );
}

function action_send_mail_with_type(
    string $type
): array {
    $result =
        action_send_mail();

    /*
     * 最後に作成された履歴へtypeを設定。
     */
    $logs =
        read_json(SEND_LOG_FILE);

    if ($logs) {
        $last =
            count($logs) - 1;

        $logs[$last]['type'] =
            $type;

        write_json_atomic(
            SEND_LOG_FILE,
            $logs
        );
    }

    return $result;
}


/* =========================================================
 * 回答
 * ========================================================= */

function answer_questions(
    array $survey
): array {
    $questions = [];

    foreach (
        ($survey['groups'] ?? [])
        as $group
    ) {
        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {
            $questions[] = $question;
        }
    }

    return $questions;
}

function action_answer_next(): array
{
    $id = valid_id(
        (string)($_POST['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        throw new InvalidArgumentException(
            'アンケートが存在しません。'
        );
    }

    validate_answer_post(
        $survey,
        $_POST
    );

    $_SESSION['answer_state'][$id] =
        $_POST['answer'] ?? [];

    return [
        'id' => $id,
    ];
}

function action_answer_back(): array
{
    $id = valid_id(
        (string)($_POST['id'] ?? '')
    );

    return [
        'id' => $id,
    ];
}

function validate_answer_post(
    array $survey,
    array $post
): void {
    $answers =
        $post['answer'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    foreach (
        answer_questions($survey)
        as $question
    ) {
        if (
            empty($question['required'])
        ) {
            continue;
        }

        $questionId =
            (string)(
                $question['id'] ?? ''
            );

        $value =
            $answers[$questionId]
            ?? null;

        $empty = false;

        if (is_array($value)) {
            $empty =
                count(
                    array_filter(
                        $value,
                        static fn($v) =>
                            trim((string)$v) !== ''
                    )
                ) === 0;
        } else {
            $empty =
                trim(
                    (string)$value
                ) === '';
        }

        if ($empty) {
            throw new InvalidArgumentException(
                (string)(
                    $question['number']
                    ?? '質問'
                )
                . ' は必須です。'
            );
        }
    }
}

function action_answer_submit(): array
{
    $id = valid_id(
        (string)($_POST['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        throw new InvalidArgumentException(
            'アンケートが存在しません。'
        );
    }

    $answers =
        $_SESSION['answer_state'][$id]
        ?? $_POST['answer']
        ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    validate_answer_post(
        $survey,
        ['answer' => $answers]
    );

    $answerData = [
        'id' => uuid(),
        'surveyId' => $id,
        'answers' => $answers,
        'createdAt' => now_iso(),
    ];

    $all =
        read_json(ANSWERS_FILE);

    $all[] = $answerData;

    write_json_atomic(
        ANSWERS_FILE,
        $all
    );

    unset(
        $_SESSION['answer_state'][$id]
    );

    return [
        'id' => $id,
        'message' =>
            '回答を送信しました。',
    ];
}


/* =========================================================
 * CSV
 * ========================================================= */

function export_csv(): never
{
    $id = valid_id(
        (string)($_GET['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        http_response_code(404);
        exit('対象アンケートが存在しません。');
    }

    $answers =
        read_json(ANSWERS_FILE);

    $questions =
        answer_questions($survey);

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="survey_'
        . $id
        . '.csv"'
    );

    $fp = fopen(
        'php://output',
        'wb'
    );

    if ($fp === false) {
        throw new RuntimeException(
            'CSVを出力できません。'
        );
    }

    /*
     * Excel向けUTF-8 BOM。
     */
    fwrite(
        $fp,
        "\xEF\xBB\xBF"
    );

    $header = ['回答ID', '回答日時'];

    foreach ($questions as $question) {
        $header[] =
            (string)(
                $question['number']
                ?? ''
            )
            . ' '
            . (string)(
                $question['text']
                ?? ''
            );
    }

    fputcsv(
        $fp,
        $header
    );

    foreach ($answers as $answer) {
        if (
            (string)(
                $answer['surveyId']
                ?? ''
            ) !== $id
        ) {
            continue;
        }

        $row = [
            (string)(
                $answer['id'] ?? ''
            ),
            (string)(
                $answer['createdAt']
                ?? ''
            ),
        ];

        $values =
            $answer['answers']
            ?? [];

        foreach ($questions as $question) {
            $qid =
                (string)(
                    $question['id']
                    ?? ''
                );

            $value =
                $values[$qid] ?? '';

            if (is_array($value)) {
                $value =
                    implode(
                        ', ',
                        array_map(
                            'strval',
                            $value
                        )
                    );
            }

            $row[] =
                (string)$value;
        }

        fputcsv(
            $fp,
            $row
        );
    }

    fclose($fp);
    exit;
}


/* =========================================================
 * PDF
 *
 * 外部ライブラリ不要の最小PDF生成。
 * 日本語フォント埋め込みは環境依存になるため、
 * POCではASCII化した集計値を出力する。
 * ========================================================= */

function export_pdf(): never
{
    $id = valid_id(
        (string)($_GET['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        http_response_code(404);
        exit('対象アンケートが存在しません。');
    }

    $answers =
        read_json(ANSWERS_FILE);

    $count = 0;

    foreach ($answers as $answer) {
        if (
            (string)(
                $answer['surveyId']
                ?? ''
            ) === $id
        ) {
            $count++;
        }
    }

    $title =
        preg_replace(
            '/[^\x20-\x7E]/',
            '?',
            (string)(
                $survey['title']
                ?? ''
            )
        ) ?? 'Survey';

    $lines = [
        'Survey Report',
        'Title: ' . $title,
        'Answers: ' . $count,
        'Generated: ' . now_iso(),
    ];

    $pdf = create_simple_pdf(
        $lines
    );

    header(
        'Content-Type: application/pdf'
    );

    header(
        'Content-Disposition: attachment; filename="survey_'
        . $id
        . '.pdf"'
    );

    header(
        'Content-Length: '
        . strlen($pdf)
    );

    echo $pdf;
    exit;
}

function create_simple_pdf(
    array $lines
): string {
    $objects = [];

    $objects[] =
        '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[] =
        '<< /Type /Page /Parent 2 0 R '
        . '/MediaBox [0 0 595 842] '
        . '/Resources << /Font << /F1 4 0 R >> >> '
        . '/Contents 5 0 R >>';

    $objects[] =
        '<< /Type /Font /Subtype /Type1 '
        . '/BaseFont /Helvetica >>';

    $content = "BT\n/F1 12 Tf\n50 790 Td\n";

    foreach ($lines as $index => $line) {
        $safe =
            str_replace(
                [
                    '\\',
                    '(',
                    ')',
                ],
                [
                    '\\\\',
                    '\\(',
                    '\\)',
                ],
                (string)$line
            );

        if ($index > 0) {
            $content .=
                "0 -20 Td\n";
        }

        $content .=
            '('
            . $safe
            . ") Tj\n";
    }

    $content .= "ET\n";

    $objects[] =
        '<< /Length '
        . strlen($content)
        . " >>\nstream\n"
        . $content
        . "endstream";

    $pdf =
        "%PDF-1.4\n";

    $offsets = [0];

    foreach ($objects as $index => $object) {
        $offsets[] =
            strlen($pdf);

        $pdf .=
            ($index + 1)
            . " 0 obj\n"
            . $object
            . "\nendobj\n";
    }

    $xref =
        strlen($pdf);

    $pdf .=
        "xref\n"
        . "0 "
        . (count($objects) + 1)
        . "\n";

    $pdf .=
        "0000000000 65535 f \n";

    for (
        $i = 1;
        $i <= count($objects);
        $i++
    ) {
        $pdf .=
            sprintf(
                "%010d 00000 n \n",
                $offsets[$i]
            );
    }

    $pdf .=
        "trailer\n"
        . "<< /Size "
        . (count($objects) + 1)
        . " /Root 1 0 R >>\n"
        . "startxref\n"
        . $xref
        . "\n%%EOF";

    return $pdf;
}


/* =========================================================
 * 管理画面HTML
 * ========================================================= */

function render_admin_header(
    string $screen,
    ?string $message,
    string $messageType
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>
<title>アンケートアプリ</title>

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

.admin-header{
    background:#0f172a;
    color:#fff;
    min-height:60px;
    padding:0 24px;
    display:flex;
    align-items:center;
    gap:24px;
}

.admin-brand{
    font-weight:700;
    white-space:nowrap;
}

.admin-nav{
    display:flex;
    gap:5px;
    overflow-x:auto;
}

.admin-nav a{
    color:#cbd5e1;
    text-decoration:none;
    padding:10px 12px;
    border-radius:7px;
    white-space:nowrap;
}

.admin-nav a:hover,
.admin-nav a.active{
    color:#fff;
    background:#1e293b;
}

.page{
    max-width:1400px;
    margin:auto;
    padding:24px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:20px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

h1{
    margin-top:0;
    font-size:25px;
}

h2{
    font-size:20px;
}

h3{
    font-size:17px;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}

.form-group{
    margin-bottom:16px;
}

.form-group.full{
    grid-column:1/-1;
}

label{
    display:block;
    font-weight:600;
    margin-bottom:6px;
}

input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
textarea,
select{
    width:100%;
    padding:10px 12px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    background:#fff;
    color:var(--text);
}

textarea{
    min-height:120px;
    resize:vertical;
}

button,
.button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 14px;
    border-radius:8px;
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    text-decoration:none;
    cursor:pointer;
}

button.primary,
.button.primary{
    color:#fff;
    background:var(--primary);
    border-color:var(--primary);
}

button.primary:hover,
.button.primary:hover{
    background:var(--primary-dark);
}

button.danger,
.button.danger{
    color:#fff;
    background:var(--danger);
    border-color:var(--danger);
}

button.warning,
.button.warning{
    color:#fff;
    background:var(--warning);
    border-color:var(--warning);
}

.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.notice{
    padding:13px 16px;
    border-radius:8px;
    margin-bottom:20px;
}

.notice.success{
    color:#166534;
    background:#dcfce7;
    border:1px solid #bbf7d0;
}

.notice.error{
    color:#991b1b;
    background:#fee2e2;
    border:1px solid #fecaca;
}

.notice.warning{
    color:#92400e;
    background:#fef3c7;
    border:1px solid #fde68a;
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
    padding:11px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}

th{
    background:#f8fafc;
    white-space:nowrap;
}

.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge.draft{
    background:#e2e8f0;
    color:#475569;
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

.editor-topbar{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:20px;
}

.editor-topbar .state-area{
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:8px;
}

.question-card{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    margin-bottom:12px;
    background:#fff;
}

.question-card.dragging,
.group-card.dragging{
    opacity:.45;
}

.group-card{
    border:1px solid var(--border);
    border-radius:10px;
    padding:15px;
    margin-bottom:18px;
    background:#f8fafc;
}

.group-head{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:14px;
}

.drag-handle{
    cursor:grab;
    color:#64748b;
    user-select:none;
}

.question-options{
    margin-top:12px;
    margin-left:28px;
}

.option-row{
    display:flex;
    gap:7px;
    margin-bottom:7px;
}

.option-row input{
    flex:1;
}

.branch-box{
    margin-top:12px;
    padding:12px;
    border-left:3px solid var(--primary);
    background:#eff6ff;
}

.target-banner{
    background:#eff6ff;
    border:1px solid #bfdbfe;
    border-radius:10px;
    padding:15px 18px;
    margin-bottom:20px;
}

.target-banner .label{
    color:#1d4ed8;
    font-size:12px;
    font-weight:700;
}

.target-banner .title{
    font-size:18px;
    font-weight:700;
    margin-top:4px;
}

.send-tabs{
    display:flex;
    border-bottom:1px solid var(--border);
    margin-bottom:18px;
}

.send-tab{
    border:0;
    background:none;
    padding:12px 18px;
    border-bottom:3px solid transparent;
    color:#64748b;
}

.send-tab.active{
    color:var(--primary);
    border-bottom-color:var(--primary);
    font-weight:700;
}

.customer-table{
    min-width:1200px;
}

.template-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}

.mail-preview{
    white-space:pre-wrap;
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:8px;
    padding:15px;
    min-height:170px;
}

.history-detail{
    background:#f8fafc;
    padding:15px;
    border-radius:8px;
    margin-top:10px;
}

.summary-grid{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
    margin-bottom:20px;
}

.summary-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
}

.summary-card .number{
    font-size:27px;
    font-weight:700;
    margin-top:5px;
}

.bar{
    height:22px;
    border-radius:5px;
    background:#e2e8f0;
    overflow:hidden;
}

.bar > span{
    display:block;
    height:100%;
    background:var(--primary);
}

.answer-list{
    display:flex;
    flex-direction:column;
    gap:10px;
}

.answer-item{
    border:1px solid var(--border);
    border-radius:8px;
    padding:12px;
}

.settings-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
}

.mapping{
    display:grid;
    grid-template-columns:180px 1fr;
    gap:10px;
    align-items:center;
}

.address-checks{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:8px;
}

.address-checks label{
    font-weight:400;
    display:flex;
    gap:7px;
}

.status-box{
    margin-top:15px;
    padding:14px;
    border-radius:8px;
    background:#f8fafc;
    border:1px solid var(--border);
}

.empty{
    padding:35px;
    text-align:center;
    color:#64748b;
}

@media(max-width:1000px){
    .summary-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .settings-grid,
    .template-grid,
    .form-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:700px){
    .admin-header{
        height:auto;
        min-height:60px;
        padding:10px 14px;
        flex-wrap:wrap;
        gap:7px;
    }

    .admin-nav{
        order:3;
        width:100%;
    }

    .page{
        padding:16px;
    }

    .summary-grid{
        grid-template-columns:1fr 1fr;
    }

    .mapping{
        grid-template-columns:1fr;
    }

    .address-checks{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<header class="admin-header">

<div class="admin-brand">
アンケートアプリ
</div>

<nav class="admin-nav">

<a
    href="index.php?screen=list"
    class="<?= $screen === 'list' ? 'active' : '' ?>"
>
アンケート一覧
</a>

<a
    href="index.php?screen=kintone"
    class="<?= $screen === 'kintone' ? 'active' : '' ?>"
>
kintone連携
</a>

<a
    href="index.php?screen=mail"
    class="<?= $screen === 'mail' ? 'active' : '' ?>"
>
メール設定
</a>

</nav>

</header>

<main class="page">

<?php if (
    $message !== null
    && $message !== ''
): ?>

<div class="notice <?=e($messageType)?>">
<?=e($message)?>
</div>

<?php endif; ?>

<?php
}

function render_admin_footer(): void
{
?>
</main>

<script>
function confirmAction(message){
    return window.confirm(message);
}

function togglePassword(id){
    const el = document.getElementById(id);
    if(!el) return;

    el.type =
        el.type === 'password'
            ? 'text'
            : 'password';
}

function selectAllCustomers(source){
    document
        .querySelectorAll(
            '.customer-check'
        )
        .forEach(function(el){
            el.checked = source.checked;
        });
}
</script>

</body>
</html>
<?php
}


/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(): void
{
    $surveys =
        read_json(SURVEYS_FILE);

    $query =
        trim(
            (string)(
                $_GET['q'] ?? ''
            )
        );

    $status =
        (string)(
            $_GET['status'] ?? ''
        );

    $sort =
        (string)(
            $_GET['sort'] ?? 'updated_desc'
        );

    $answers =
        read_json(ANSWERS_FILE);

    foreach ($surveys as &$survey) {
        $count = 0;

        foreach ($answers as $answer) {
            if (
                (string)(
                    $answer['surveyId']
                    ?? ''
                )
                === (string)(
                    $survey['id']
                    ?? ''
                )
            ) {
                $count++;
            }
        }

        $survey['_answerCount'] =
            $count;
    }

    unset($survey);

    $surveys =
        array_values(
            array_filter(
                $surveys,
                static function(
                    array $survey
                ) use (
                    $query,
                    $status
                ): bool {
                    if (
                        $query !== ''
                        && mb_stripos(
                            (string)(
                                $survey['title']
                                ?? ''
                            ),
                            $query
                        ) === false
                    ) {
                        return false;
                    }

                    if (
                        $status !== ''
                        && $status !== 'all'
                        && (
                            string
                            )(
                                $survey['status']
                                ?? ''
                            ) !== $status
                    ) {
                        return false;
                    }

                    return true;
                }
            )
        );

    usort(
        $surveys,
        static function(
            array $a,
            array $b
        ) use ($sort): int {
            if ($sort === 'updated_asc') {
                return strcmp(
                    (string)(
                        $a['updatedAt']
                        ?? ''
                    ),
                    (string)(
                        $b['updatedAt']
                        ?? ''
                    )
                );
            }

            if ($sort === 'answers_desc') {
                return (
                    (int)(
                        $b['_answerCount']
                        ?? 0
                    )
                    <=>
                    (int)(
                        $a['_answerCount']
                        ?? 0
                    )
                );
            }

            if ($sort === 'answers_asc') {
                return (
                    (int)(
                        $a['_answerCount']
                        ?? 0
                    )
                    <=>
                    (int)(
                        $b['_answerCount']
                        ?? 0
                    )
                );
            }

            if ($sort === 'start_desc') {
                return strcmp(
                    (string)(
                        $b['startAt']
                        ?? ''
                    ),
                    (string)(
                        $a['startAt']
                        ?? ''
                    )
                );
            }

            if ($sort === 'start_asc') {
                return strcmp(
                    (string)(
                        $a['startAt']
                        ?? ''
                    ),
                    (string)(
                        $b['startAt']
                        ?? ''
                    )
                );
            }

            return strcmp(
                (string)(
                    $b['updatedAt']
                    ?? ''
                ),
                (string)(
                    $a['updatedAt']
                    ?? ''
                )
            );
        }
    );
?>
<div class="card">

<h1>アンケート一覧</h1>

<form
    method="get"
    action="index.php"
>
<input
    type="hidden"
    name="screen"
    value="list"
>

<div class="form-grid">

<div class="form-group">
<label>検索</label>
<input
    type="text"
    name="q"
    value="<?=e($query)?>"
    placeholder="タイトルで検索"
>
</div>

<div class="form-group">
<label>絞り込み</label>
<select name="status">
<option value="all">すべて</option>
<option
    value="published"
    <?=$status === 'published'
        ? 'selected' : ''?>
>公開中</option>
<option
    value="draft"
    <?=$status === 'draft'
        ? 'selected' : ''?>
>下書き</option>
<option
    value="stopped"
    <?=$status === 'stopped'
        ? 'selected' : ''?>
>停止</option>
<option
    value="ended"
    <?=$status === 'ended'
        ? 'selected' : ''?>
>終了</option>
</select>
</div>

<div class="form-group">
<label>ソート</label>
<select name="sort">
<option
    value="updated_desc"
    <?=$sort === 'updated_desc'
        ? 'selected' : ''?>
>更新日：新しい順</option>

<option
    value="updated_asc"
    <?=$sort === 'updated_asc'
        ? 'selected' : ''?>
>更新日：古い順</option>

<option
    value="answers_desc"
    <?=$sort === 'answers_desc'
        ? 'selected' : ''?>
>回答数：多い順</option>

<option
    value="answers_asc"
    <?=$sort === 'answers_asc'
        ? 'selected' : ''?>
>回答数：少ない順</option>

<option
    value="start_desc"
    <?=$sort === 'start_desc'
        ? 'selected' : ''?>
>開始日：新しい順</option>

<option
    value="start_asc"
    <?=$sort === 'start_asc'
        ? 'selected' : ''?>
>開始日：古い順</option>
</select>
</div>

</div>

<button
    class="primary"
    type="submit"
>
検索・絞り込み
</button>

</form>

</div>

<div class="card">

<div class="actions">
<a
    class="button primary"
    href="index.php?screen=edit"
>
新規作成
</a>
</div>

<br>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>タイトル</th>
<th>作成日</th>
<th>更新日</th>
<th>アンケート期間</th>
<th>ステータス</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>

<tbody>

<?php if (!$surveys): ?>

<tr>
<td
    colspan="7"
    class="empty"
>
アンケートはありません。
</td>
</tr>

<?php endif; ?>

<?php foreach ($surveys as $survey): ?>

<?php
$id =
    (string)(
        $survey['id'] ?? ''
    );

$status =
    (string)(
        $survey['status'] ?? ''
    );

$statusLabel = [
    'draft' => '下書き',
    'published' => '公開中',
    'stopped' => '停止',
    'ended' => '終了',
][$status] ?? $status;
?>

<tr>

<td>
<strong>
<?=e($survey['title'] ?? '')?>
</strong>
</td>

<td>
<?=e($survey['createdAt'] ?? '')?>
</td>

<td>
<?=e($survey['updatedAt'] ?? '')?>
</td>

<td>
<?=e($survey['startAt'] ?? '')?>
～
<?=e($survey['endAt'] ?? '')?>
</td>

<td>
<span class="badge <?=e($status)?>">
<?=e($statusLabel)?>
</span>
</td>

<td>
<?=e($survey['_answerCount'] ?? 0)?>
</td>

<td>

<div class="actions">

<a
    class="button"
    href="<?=e(
        screen_url(
            'edit',
            $id
        )
    )?>"
>
確認・編集
</a>

<a
    class="button"
    href="<?=e(
        screen_url(
            'preview',
            $id
        )
    )?>"
>
プレビュー
</a>

<a
    class="button"
    href="<?=e(
        screen_url(
            'analytics',
            $id
        )
    )?>"
>
集計
</a>

<a
    class="button"
    href="<?=e(
        screen_url(
            'send',
            $id
        )
    )?>"
>
送信
</a>

<form
    method="post"
    action="index.php?screen=list"
    style="display:inline"
>
<input
    type="hidden"
    name="action"
    value="duplicate_survey"
>
<input
    type="hidden"
    name="id"
    value="<?=e($id)?>"
>
<button
    type="submit"
    onclick="return confirmAction('このアンケートを複製しますか？')"
>
複製
</button>
</form>

<form
    method="post"
    action="index.php?screen=list"
    style="display:inline"
>
<input
    type="hidden"
    name="action"
    value="delete_survey"
>
<input
    type="hidden"
    name="id"
    value="<?=e($id)?>"
>
<button
    class="danger"
    type="submit"
    onclick="return confirmAction('このアンケートを削除しますか？')"
>
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


/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(
    ?array $survey
): void {
    $isNew = $survey === null;

    if ($isNew) {
        $survey = [
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
        ];
    }

    normalize_questions($survey);

    $id =
        (string)(
            $survey['id'] ?? ''
        );

    $status =
        (string)(
            $survey['status']
            ?? STATUS_DRAFT
        );

    $statusLabel = [
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
    ][$status] ?? $status;
?>
<div class="card">

<div class="editor-topbar">

<a
    class="button"
    href="index.php?screen=list"
>
キャンセル
</a>

<form
    method="post"
    action="index.php?screen=edit"
    style="display:inline"
>
<input
    type="hidden"
    name="action"
    value="save_survey"
>
<input
    type="hidden"
    name="id"
    value="<?=e($id)?>"
>

<button
    class="primary"
    type="submit"
>
保存して一覧へ
</button>

<?php
/*
 * ここは保存用formの外に出すと
 * inputが送信されないため、
 * 実際の質問データは別保存フォームを使用。
 */
?>

</form>

<div class="state-area">

<span>
状態：
<strong>
<?=e($statusLabel)?>
</strong>
</span>

<?php if ($status !== STATUS_ENDED): ?>

<form
    method="post"
    action="index.php?screen=edit&id=<?=e($id)?>"
>
<input
    type="hidden"
    name="action"
    value="change_status"
>
<input
    type="hidden"
    name="id"
    value="<?=e($id)?>"
>

<select
    name="status"
    onchange="if(this.value !== '<?=e($status)?>' && confirmAction('状態を変更しますか？')) this.form.submit();"
>
<option
    value="<?=e($status)?>"
>
<?=e($statusLabel)?>
</option>

<?php if ($status === STATUS_DRAFT): ?>

<option value="published">
公開中
</option>

<?php elseif ($status === STATUS_PUBLISHED): ?>

<option value="stopped">
停止
</option>

<?php elseif ($status === STATUS_STOPPED): ?>

<option value="published">
公開中
</option>

<?php endif; ?>

</select>

</form>

<?php endif; ?>

</div>

</div>

<h1>アンケート作成・編集</h1>

<form
    method="post"
    action="index.php?screen=edit"
>
<input
    type="hidden"
    name="action"
    value="save_survey"
>

<input
    type="hidden"
    name="id"
    value="<?=e($id)?>"
>

<div class="form-grid">

<div class="form-group full">
<label>アンケートタイトル</label>
<input
    type="text"
    name="title"
    maxlength="200"
    required
    value="<?=e($survey['title'] ?? '')?>"
>
</div>

<div class="form-group full">
<label>アンケート説明</label>
<textarea
    name="description"
><?=e($survey['description'] ?? '')?></textarea>
</div>

<div class="form-group">
<label>開始日時</label>
<input
    type="datetime-local"
    name="startAt"
    value="<?=e($survey['startAt'] ?? '')?>"
>
</div>

<div class="form-group">
<label>終了日時</label>
<input
    type="datetime-local"
    name="endAt"
    value="<?=e($survey['endAt'] ?? '')?>"
>
</div>

<div class="form-group">
<label>質問番号の採番方式</label>
<select name="numbering">
<option
    value="global"
    <?=($survey['numbering'] ?? 'global')
        === 'global'
        ? 'selected'
        : ''?>
>
アンケート全体で通番：Q1、Q2、Q3...
</option>

<option
    value="group"
    <?=($survey['numbering'] ?? '')
        === 'group'
        ? 'selected'
        : ''?>
>
グループ毎：Q1-1、Q1-2、Q2-1...
</option>
</select>
</div>

</div>

<div class="actions">
<button
    class="primary"
    type="submit"
>
保存して一覧へ
</button>
</div>

</form>

<hr>

<div class="card">

<h2>グループ・質問</h2>

<div id="question-editor">

<?php foreach (
    $survey['groups']
    as $groupIndex => $group
): ?>

<div
    class="group-card"
    draggable="true"
    data-group-id="<?=e($group['id'])?>"
>

<div class="group-head">

<span class="drag-handle">
↕
</span>

<strong>
<?=e($group['title'])?>
</strong>

</div>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div
    class="question-card"
    draggable="true"
>
<strong>
<?=e($question['number'] ?? '')?>
</strong>

<p>
<?=e($question['text'] ?? '')?>
</p>

<p>
回答形式：
<?=e(
    [
        'single' => '単一選択',
        'multiple' => '複数選択',
        'textarea' => '自由記述',
    ][$question['type'] ?? '']
    ?? ''
)?>
</p>

<p>
必須：
<?=!empty($question['required'])
    ? 'はい'
    : 'いいえ'?>
</p>

</div>

<?php endforeach; ?>

<button
    type="button"
    class="button"
    onclick="alert('質問追加UIはここから追加できます。')"
>
質問を追加
</button>

</div>

<?php endforeach; ?>

</div>

<p>
<button
    type="button"
    class="button"
    onclick="alert('グループ追加UIはここから追加できます。')"
>
グループを追加
</button>
</p>

</div>

<?php if (!$isNew): ?>

<div class="card">

<h2>質問データ保存</h2>

<form
    method="post"
    action="index.php?screen=edit&id=<?=e($id)?>"
    id="questions-form"
>
<input
    type="hidden"
    name="action"
    value="save_questions"
>

<input
    type="hidden"
    name="id"
    value="<?=e($id)?>"
>

<input
    type="hidden"
    name="groups_json"
    id="groups-json"
    value="<?=e(
        json_encode(
            $survey['groups'],
            JSON_UNESCAPED_UNICODE
        )
    )?>"
>

<button
    class="primary"
    type="submit"
>
質問・グループを保存
</button>

</form>

</div>

<?php endif; ?>

</div>

<script>
(function(){
    const editor =
        document.getElementById(
            'question-editor'
        );

    if(!editor) return;

    let dragged = null;

    editor
        .querySelectorAll(
            '.group-card'
        )
        .forEach(function(group){
            group.addEventListener(
                'dragstart',
                function(){
                    dragged = group;
                    group.classList.add(
                        'dragging'
                    );
                }
            );

            group.addEventListener(
                'dragend',
                function(){
                    group.classList.remove(
                        'dragging'
                    );
                    dragged = null;
                }
            );

            group.addEventListener(
                'dragover',
                function(e){
                    e.preventDefault();

                    if(
                        dragged
                        && dragged !== group
                    ){
                        const rect =
                            group.getBoundingClientRect();

                        const before =
                            e.clientY
                            < rect.top
                            + rect.height / 2;

                        if(before){
                            group.parentNode
                                .insertBefore(
                                    dragged,
                                    group
                                );
                        }else{
                            group.parentNode
                                .insertBefore(
                                    dragged,
                                    group.nextSibling
                                );
                        }
                    }
                }
            );
        });
})();
</script>
<?php
}


/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(
    ?array $survey
): void {
?>
<div class="card">

<h1>プレビュー</h1>

<?php if ($survey === null): ?>

<div class="notice error">
対象アンケートが存在しません。
</div>

<?php else: ?>

<div class="target-banner">
<div class="label">
PREVIEW
</div>

<div class="title">
<?=e($survey['title'] ?? '')?>
</div>
</div>

<div class="card">

<h2>
<?=e($survey['title'] ?? '')?>
</h2>

<p>
<?=nl2br(
    e(
        $survey['description'] ?? ''
    )
)?>
</p>

<?php
normalize_questions($survey);
?>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<section class="group-card">

<h3>
<?=e($group['title'])?>
</h3>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<strong>
<?=e($question['number'])?>
<?=e($question['text'])?>

<?php if (
    !empty($question['required'])
): ?>

<span style="color:#dc2626">
必須
</span>

<?php endif; ?>

</strong>

<?php if (
    $question['type']
    === QUESTION_SINGLE
): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label
    class="respondent-option"
>
<input
    type="radio"
    disabled
>
<?=e($option['text'])?>
</label>

<?php endforeach; ?>

<?php elseif (
    $question['type']
    === QUESTION_MULTI
): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label
    class="respondent-option"
>
<input
    type="checkbox"
    disabled
>
<?=e($option['text'])?>
</label>

<?php endforeach; ?>

<?php else: ?>

<textarea
    disabled
    style="min-height:140px"
></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

</section>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>
<?php
}


/* =========================================================
 * 送信
 * ========================================================= */

function render_send(
    ?array $survey
): void {
    if ($survey === null) {
        return;
    }

    $customers =
        read_json(CUSTOMERS_FILE);

    $logs =
        read_json(SEND_LOG_FILE);

    $id =
        (string)$survey['id'];

    $surveyLogs =
        array_values(
            array_filter(
                $logs,
                static function(
                    array $log
                ) use ($id): bool {
                    return
                        (string)(
                            $log['surveyId']
                            ?? ''
                        ) === $id;
                }
            )
        );
?>
<div class="target-banner">

<div class="label">
対象アンケート
</div>

<div class="title">
<?=e($survey['title'] ?? '')?>
</div>

</div>

<div class="card">

<div class="send-tabs">
<button
    class="send-tab active"
    type="button"
>
顧客選択・送信
</button>

<button
    class="send-tab"
    type="button"
    onclick="document.getElementById('history').scrollIntoView()"
>
送信履歴
</button>
</div>

<form
    method="post"
    action="index.php?screen=send&id=<?=e($id)?>"
>

<input
    type="hidden"
    name="action"
    value="send_mail"
>

<input
    type="hidden"
    name="id"
    value="<?=e($id)?>"
>

<h2>顧客選択</h2>

<div class="table-wrap">

<table class="customer-table">

<thead>
<tr>
<th>
<input
    type="checkbox"
    onclick="selectAllCustomers(this)"
>
</th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署</th>
<th>電話</th>
<th>住所</th>
</tr>
</thead>

<tbody>

<?php if (!$customers): ?>

<tr>
<td
    colspan="7"
    class="empty"
>
顧客情報がありません。
kintone設定から顧客情報を同期してください。
</td>
</tr>

<?php endif; ?>

<?php foreach (
    $customers
    as $customer
): ?>

<tr>

<td>
<input
    class="customer-check"
    type="checkbox"
    name="customer_ids[]"
    value="<?=e(
        $customer['id']
        ?? ''
    )?>"
>
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

<br>

<div class="template-grid">

<div>

<div class="form-group">
<label>メール件名</label>

<input
    type="text"
    name="subject"
    value="<?=e(
        '【アンケート】'
        . ($survey['title'] ?? '')
    )?>"
    required
>
</div>

<div class="form-group">
<label>メール本文</label>

<textarea
    name="body"
    required
>ご担当者様

アンケートへのご協力をお願いいたします。

{顧客名} 様

アンケートURL：
{アンケートURL}

よろしくお願いいたします。</textarea>

</div>

</div>

<div>

<label>送信文確認</label>

<div
    class="mail-preview"
    id="mail-preview"
>
顧客を選択すると実際の送信対象が決まります。
</div>

</div>

</div>

<button
    class="primary"
    type="submit"
    onclick="return confirmAction('選択した顧客へメールを送信しますか？')"
>
一括送信
</button>

</form>

</div>

<div
    class="card"
    id="history"
>

<h2>送信履歴</h2>

<?php if (!$surveyLogs): ?>

<div class="empty">
送信履歴はありません。
</div>

<?php else: ?>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>メール</th>
<th>種別</th>
<th>結果</th>
<th>内容</th>
</tr>
</thead>

<tbody>

<?php foreach (
    array_reverse(
        $surveyLogs
    ) as $log
): ?>

<tr>

<td>
<?=e(
    $log['createdAt']
    ?? ''
)?>
</td>

<td>
<?=e(
    $log['customerName']
    ?? ''
)?>
</td>

<td>
<?=e(
    $log['email']
    ?? ''
)?>
</td>

<td>
<?=e(
    $log['type']
    ?? 'initial'
)?>
</td>

<td>
<?php if (
    ($log['status'] ?? '')
    === 'sent'
): ?>

<span class="badge published">
送信済み
</span>

<?php else: ?>

<span class="badge ended">
失敗
</span>

<?php endif; ?>
</td>

<td>
<?=e(
    $log['error']
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
<?php
}


/* =========================================================
 * 集計
 * ========================================================= */

function render_analytics(
    ?array $survey
): void {
    if ($survey === null) {
        return;
    }

    $id =
        (string)$survey['id'];

    $answers =
        array_values(
            array_filter(
                read_json(ANSWERS_FILE),
                static function(
                    array $answer
                ) use ($id): bool {
                    return
                        (string)(
                            $answer['surveyId']
                            ?? ''
                        ) === $id;
                }
            )
        );

    $logs =
        array_values(
            array_filter(
                read_json(SEND_LOG_FILE),
                static function(
                    array $log
                ) use ($id): bool {
                    return
                        (string)(
                            $log['surveyId']
                            ?? ''
                        ) === $id;
                }
            )
        );

    $sentCustomers = [];

    foreach ($logs as $log) {
        if (
            ($log['status'] ?? '')
            === 'sent'
        ) {
            $sentCustomers[
                (string)(
                    $log['customerId']
                    ?? ''
                )
            ] = true;
        }
    }

    $sentCount =
        count($sentCustomers);

    $answerCount =
        count($answers);

    $rate =
        $sentCount > 0
            ? round(
                $answerCount
                / $sentCount
                * 100,
                1
            )
            : 0;

    normalize_questions($survey);

?>
<div class="target-banner">

<div class="label">
対象アンケート
</div>

<div class="title">
<?=e($survey['title'] ?? '')?>
</div>

</div>

<div class="summary-grid">

<div class="summary-card">
送信対象者数
<div class="number">
<?=e($sentCount)?>
</div>
</div>

<div class="summary-card">
回答数
<div class="number">
<?=e($answerCount)?>
</div>
</div>

<div class="summary-card">
未登録回答数
<div class="number">0</div>
</div>

<div class="summary-card">
未回答数
<div class="number">
<?=e(
    max(
        0,
        $sentCount
        - $answerCount
    )
)?>
</div>
</div>

<div class="summary-card">
回答率
<div class="number">
<?=e($rate)?>%
</div>
</div>

</div>

<div class="card">

<div class="actions">

<a
    class="button"
    href="index.php?screen=analytics&id=<?=e($id)?>&export=csv"
>
CSV出力
</a>

<a
    class="button"
    href="index.php?screen=analytics&id=<?=e($id)?>&export=pdf"
>
PDF出力
</a>

</div>

</div>

<div class="card">

<h2>設問別集計</h2>

<?php if (!$answers): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h3>
<?=e($group['title'])?>
</h3>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$qid =
    (string)(
        $question['id']
        ?? ''
    );

$values = [];

foreach ($answers as $answer) {
    $v =
        $answer['answers'][$qid]
        ?? '';

    if (is_array($v)) {
        foreach ($v as $x) {
            $values[] =
                (string)$x;
        }
    } else {
        $values[] =
            (string)$v;
    }
}

$total =
    count($values);

?>

<div class="answer-item">

<strong>
<?=e($question['number'] ?? '')?>
<?=e($question['text'] ?? '')?>
</strong>

<?php if (
    in_array(
        $question['type'] ?? '',
        [
            QUESTION_SINGLE,
            QUESTION_MULTI,
        ],
        true
    )
): ?>

<?php
$counts = [];

foreach ($values as $value) {
    if ($value === '') {
        continue;
    }

    if (!isset($counts[$value])) {
        $counts[$value] = 0;
    }

    $counts[$value]++;
}
?>

<?php foreach (
    $counts
    as $value => $count
): ?>

<p>
<?=e($value)?>
：
<?=e($count)?>件
</p>

<div class="bar">
<span
    style="width:<?=e(
        $total > 0
            ? ($count / $total * 100)
            : 0
    )?>%"
></span>
</div>

<?php endforeach; ?>

<?php else: ?>

<?php foreach (
    $values
    as $value
): ?>

<p>
<?=nl2br(e($value))?>
</p>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<?php endif; ?>

</div>

<div class="card">

<h2>個別回答</h2>

<?php if (!$answers): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<div class="answer-list">

<?php foreach (
    $answers
    as $answer
): ?>

<div class="answer-item">

<strong>
<?=e(
    $answer['createdAt']
    ?? ''
)?>
</strong>

<?php
foreach (
    ($answer['answers'] ?? [])
    as $qid => $value
):
?>

<p>
<strong>
<?=e($qid)?>
</strong><br>

<?php
if (is_array($value)) {
    echo e(
        implode(
            ', ',
            array_map(
                'strval',
                $value
            )
        )
    );
} else {
    echo nl2br(
        e($value)
    );
}
?>

</p>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>
<?php
}


/* =========================================================
 * kintone設定画面
 * ========================================================= */

function render_kintone(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $k =
        $settings['kintone']
        ?? default_settings()['kintone'];

    $fields =
        $k['fields']
        ?? [];

    $mapping =
        $k['field_mapping']
        ?? [];
?>
<div class="card">

<h1>kintone連携設定</h1>

<div class="settings-grid">

<div>

<form
    method="post"
    action="index.php?screen=kintone"
>

<input
    type="hidden"
    name="action"
    value="save_kintone"
>

<div class="form-group">

<label>
サブドメイン
</label>

<input
    type="text"
    name="subdomain"
    value="<?=e(
        $k['subdomain'] ?? ''
    )?>"
    placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com"
>

</div>

<div class="form-group">

<label>
顧客管理アプリID
</label>

<input
    type="text"
    name="app_id"
    value="<?=e(
        $k['app_id'] ?? ''
    )?>"
>

</div>

<div class="form-group">

<label>
ログイン名
</label>

<input
    type="text"
    name="username"
    value="<?=e(
        $k['username'] ?? ''
    )?>"
>

</div>

<div class="form-group">

<label>
パスワード
</label>

<input
    id="kintone-password"
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更しない場合は空欄"
>

</div>

<div class="form-group">

<label>
Proxy
</label>

<input
    type="text"
    name="proxy"
    value="<?=e(
        $k['proxy'] ?? ''
    )?>"
    placeholder="host:port"
>

</div>

<div class="form-group">

<label>
<input
    type="checkbox"
    name="verify_ssl"
    value="1"
    <?=!empty(
        $k['verify_ssl']
    )
        ? 'checked'
        : ''?>
>
SSL証明書を検証する
</label>

</div>

<button
    class="primary"
    type="submit"
>
設定保存
</button>

</form>

</div>

<div>

<div class="status-box">

<strong>
接続状態
</strong>

<p>
<?=e(
    $k['connection_status']
    ?? '未設定'
)?>
</p>

<?php if (
    !empty($k['last_test_at'])
): ?>

<small>
最終確認：
<?=e(
    $k['last_test_at']
)?>
</small>

<?php endif; ?>

</div>

<br>

<form
    method="post"
    action="index.php?screen=kintone"
>
<input
    type="hidden"
    name="action"
    value="test_kintone"
>

<button
    type="submit"
    onclick="this.disabled=true;this.innerText='接続確認中...';"
>
接続テスト
</button>

</form>

<br>

<form
    method="post"
    action="index.php?screen=kintone"
>
<input
    type="hidden"
    name="action"
    value="fetch_kintone_fields"
>

<button
    type="submit"
    onclick="this.disabled=true;this.innerText='取得中...';"
>
項目一覧を再取得
</button>

</form>

<br>

<form
    method="post"
    action="index.php?screen=kintone"
>
<input
    type="hidden"
    name="action"
    value="sync_kintone"
>

<button
    type="submit"
    onclick="this.disabled=true;this.innerText='同期中...';"
>
顧客情報を同期
</button>

</form>

</div>

</div>

</div>


<div class="card">

<h2>項目マッピング</h2>

<div class="mapping">

<strong>組織名</strong>

<select name="organization" form="mapping-form">
<option value="">未設定</option>

<?php foreach (
    $fields
    as $field
): ?>

<option
    value="<?=e($field['code'])?>"
    <?=(
        ($mapping['organization'] ?? '')
        === $field['code']
    )
        ? 'selected'
        : ''?>
>
<?=e(
    $field['label']
    . ' ['
    . $field['code']
    . ']'
)?>
</option>

<?php endforeach; ?>

</select>

<strong>氏名</strong>

<select name="name" form="mapping-form">
<option value="">未設定</option>

<?php foreach (
    $fields
    as $field
): ?>

<option
    value="<?=e($field['code'])?>"
    <?=(
        ($mapping['name'] ?? '')
        === $field['code']
    )
        ? 'selected'
        : ''?>
>
<?=e(
    $field['label']
    . ' ['
    . $field['code']
    . ']'
)?>
</option>

<?php endforeach; ?>

</select>

<strong>メールアドレス</strong>

<select name="email" form="mapping-form">
<option value="">未設定</option>

<?php foreach (
    $fields
    as $field
): ?>

<option
    value="<?=e($field['code'])?>"
    <?=(
        ($mapping['email'] ?? '')
        === $field['code']
    )
        ? 'selected'
        : ''?>
>
<?=e(
    $field['label']
    . ' ['
    . $field['code']
    . ']'
)?>
</option>

<?php endforeach; ?>

</select>

<strong>部署名</strong>

<select name="department" form="mapping-form">
<option value="">未設定</option>

<?php foreach (
    $fields
    as $field
): ?>

<option
    value="<?=e($field['code'])?>"
    <?=(
        ($mapping['department'] ?? '')
        === $field['code']
    )
        ? 'selected'
        : ''?>
>
<?=e(
    $field['label']
    . ' ['
    . $field['code']
    . ']'
)?>
</option>

<?php endforeach; ?>

</select>

<strong>電話番号</strong>

<select name="phone" form="mapping-form">
<option value="">未設定</option>

<?php foreach (
    $fields
    as $field
): ?>

<option
    value="<?=e($field['code'])?>"
    <?=(
        ($mapping['phone'] ?? '')
        === $field['code']
    )
        ? 'selected'
        : ''?>
>
<?=e(
    $field['label']
    . ' ['
    . $field['code']
    . ']'
)?>
</option>

<?php endforeach; ?>

</select>

</div>

</div>

<?php if ($fields): ?>

<div class="card">

<h2>取得済みkintone項目</h2>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>フィールドコード</th>
<th>ラベル</th>
<th>タイプ</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $fields
    as $field
): ?>

<tr>
<td><?=e($field['code'])?></td>
<td><?=e($field['label'])?></td>
<td><?=e($field['type'])?></td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<?php endif; ?>

<?php
}


/* =========================================================
 * メール設定
 * ========================================================= */

function render_mail(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $mail =
        $settings['mail']
        ?? default_settings()['mail'];
?>
<div class="card">

<h1>メールサーバ設定</h1>

<div class="settings-grid">

<div>

<form
    method="post"
    action="index.php?screen=mail"
>

<input
    type="hidden"
    name="action"
    value="save_mail"
>

<div class="form-group">
<label>SMTPサーバ</label>

<input
    type="text"
    name="host"
    value="<?=e(
        $mail['host'] ?? ''
    )?>"
>
</div>

<div class="form-group">
<label>SMTPポート</label>

<input
    type="number"
    name="port"
    min="1"
    max="65535"
    value="<?=e(
        $mail['port'] ?? 587
    )?>"
>
</div>

<div class="form-group">
<label>暗号化方式</label>

<select name="encryption">

<option
    value="ssl"
    <?=($mail['encryption'] ?? '')
        === 'ssl'
        ? 'selected'
        : ''?>
>
SSL
</option>

<option
    value="tls"
    <?=($mail['encryption'] ?? 'tls')
        === 'tls'
        ? 'selected'
        : ''?>
>
TLS
</option>

<option
    value="none"
    <?=($mail['encryption'] ?? '')
        === 'none'
        ? 'selected'
        : ''?>
>
なし
</option>

</select>

</div>

<div class="form-group">

<label>
<input
    type="checkbox"
    name="auth"
    value="1"
    <?=!empty(
        $mail['auth']
    )
        ? 'checked'
        : ''?>
>
SMTP認証を使用
</label>

</div>

<div class="form-group">
<label>SMTPユーザー名</label>

<input
    type="text"
    name="username"
    value="<?=e(
        $mail['username'] ?? ''
    )?>"
>
</div>

<div class="form-group">
<label>SMTPパスワード</label>

<input
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更しない場合は空欄"
>
</div>

<div class="form-group">
<label>送信元メールアドレス</label>

<input
    type="email"
    name="from_email"
    value="<?=e(
        $mail['from_email'] ?? ''
    )?>"
>
</div>

<div class="form-group">
<label>送信元名</label>

<input
    type="text"
    name="from_name"
    value="<?=e(
        $mail['from_name'] ?? ''
    )?>"
>
</div>

<div class="form-group">
<label>返信先メールアドレス</label>

<input
    type="email"
    name="reply_to"
    value="<?=e(
        $mail['reply_to'] ?? ''
    )?>"
>
</div>

<button
    class="primary"
    type="submit"
>
設定保存
</button>

</form>

</div>

<div>

<div class="status-box">

<strong>
接続状態
</strong>

<p>
<?=e(
    $mail['connection_status']
    ?? '未設定'
)?>
</p>

<?php if (
    !empty(
        $mail['last_test_at']
    )
): ?>

<small>
最終確認：
<?=e(
    $mail['last_test_at']
)?>
</small>

<?php endif; ?>

</div>

<br>

<form
    method="post"
    action="index.php?screen=mail"
>

<input
    type="hidden"
    name="action"
    value="test_mail"
>

<button
    type="submit"
    onclick="this.disabled=true;this.innerText='接続確認中...';"
>
接続テスト
</button>

</form>

<br>

<form
    method="post"
    action="index.php?screen=mail"
>

<input
    type="hidden"
    name="action"
    value="send_test_mail"
>

<div class="form-group">

<label>
テスト送信先
</label>

<input
    type="email"
    name="test_to"
    required
>

</div>

<button
    type="submit"
    onclick="return confirmAction('テストメールを送信しますか？')"
>
テストメール送信
</button>

</form>

</div>

</div>

</div>
<?php
}


/* =========================================================
 * 回答者画面
 *
 * 管理者メニューを一切表示しない。
 * ========================================================= */

function render_respondent(
    string $screen,
    ?array $survey,
    ?string $message,
    string $messageType
): void {
?>
<!doctype html>
<html lang="ja">
<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
アンケート回答
</title>

<style>
:root{
    --primary:#2563eb;
    --danger:#dc2626;
    --border:#dbe2ea;
    --text:#1e293b;
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
        Meiryo,
        sans-serif;
}

.respondent{
    min-height:100vh;
}

.respondent-header{
    background:#fff;
    border-bottom:1px solid var(--border);
    padding:20px;
}

.respondent-header-inner{
    max-width:760px;
    margin:auto;
}

.respondent-main{
    max-width:760px;
    margin:25px auto;
    padding:0 16px 50px;
}

.respondent-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:25px;
    box-shadow:var(--shadow);
}

.progress{
    height:7px;
    background:#e2e8f0;
    border-radius:5px;
    overflow:hidden;
    margin:15px 0 25px;
}

.progress span{
    display:block;
    height:100%;
    background:var(--primary);
}

.respondent-question{
    margin:0 0 28px;
}

.required{
    color:var(--danger);
    font-size:12px;
    margin-left:5px;
}

.respondent-option{
    display:block;
    padding:13px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    margin:8px 0;
}

.respondent-actions{
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:25px;
}

button{
    min-height:44px;
    padding:10px 18px;
    border-radius:8px;
    border:1px solid #cbd5e1;
    background:#fff;
    cursor:pointer;
}

button.primary{
    color:#fff;
    background:#2563eb;
    border-color:#2563eb;
}

.notice{
    padding:12px;
    border-radius:8px;
    margin-bottom:18px;
    background:#fee2e2;
    color:#991b1b;
}

textarea{
    width:100%;
    min-height:150px;
    padding:12px;
    border:1px solid #cbd5e1;
    border-radius:8px;
}

@media(max-width:600px){
    .respondent-card{
        padding:18px;
    }

    .respondent-actions{
        flex-direction:column;
    }

    button{
        width:100%;
    }
}
</style>

</head>

<body>

<div class="respondent">

<div class="respondent-header">

<div class="respondent-header-inner">

<?php if ($survey !== null): ?>

<strong>
<?=e($survey['title'] ?? '')?>
</strong>

<?php endif; ?>

</div>

</div>

<main class="respondent-main">

<?php if (
    $message !== null
    && $message !== ''
): ?>

<div class="notice">
<?=e($message)?>
</div>

<?php endif; ?>

<?php
if ($screen === 'answer') {
    render_answer_page($survey);
} elseif ($screen === 'confirm') {
    render_confirm_page($survey);
} else {
    render_complete_page($survey);
}
?>

</main>

</div>

</body>
</html>
<?php
}

function render_answer_page(
    ?array $survey
): void {
    if ($survey === null) {
?>
<div class="respondent-card">
アンケートが存在しません。
</div>
<?php
        return;
    }

    $id =
        (string)$survey['id'];

    normalize_questions($survey);

    $saved =
        $_SESSION['answer_state'][$id]
        ?? [];

    if (!is_array($saved)) {
        $saved = [];
    }

    $questions =
        answer_questions($survey);
?>
<div class="respondent-card">

<h1>
<?=e($survey['title'] ?? '')?>
</h1>

<p>
<?=nl2br(
    e(
        $survey['description']
        ?? ''
    )
)?>
</p>

<div class="progress">
<span
    style="width:100%"
></span>
</div>

<form
    method="post"
    action="index.php?screen=answer&id=<?=e($id)?>"
>

<input
    type="hidden"
    name="action"
    value="answer_next"
>

<input
    type="hidden"
    name="id"
    value="<?=e($id)?>"
>

<?php foreach (
    $questions
    as $question
): ?>

<?php
$qid =
    (string)(
        $question['id']
        ?? ''
    );

$value =
    $saved[$qid] ?? '';
?>

<div class="respondent-question">

<strong>
<?=e($question['number'] ?? '')?>
<?=e($question['text'] ?? '')?>

<?php if (
    !empty($question['required'])
): ?>

<span class="required">
必須
</span>

<?php endif; ?>

</strong>

<?php if (
    ($question['type'] ?? '')
    === QUESTION_SINGLE
): ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<label
    class="respondent-option"
>

<input
    type="radio"
    name="answer[<?=e($qid)?>]"
    value="<?=e(
        $option['id'] ?? ''
    )?>"
    <?=(
        $value
        === ($option['id'] ?? '')
    )
        ? 'checked'
        : ''?>
>

<?=e(
    $option['text'] ?? ''
)?>

</label>

<?php endforeach; ?>

<?php elseif (
    ($question['type'] ?? '')
    === QUESTION_MULTI
): ?>

<?php
$selected =
    is_array($value)
        ? $value
        : [];
?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<label
    class="respondent-option"
>

<input
    type="checkbox"
    name="answer[<?=e($qid)?>][]"
    value="<?=e(
        $option['id'] ?? ''
    )?>"
    <?=in_array(
        $option['id'] ?? '',
        $selected,
        true
    )
        ? 'checked'
        : ''?>
>

<?=e(
    $option['text'] ?? ''
)?>

</label>

<?php endforeach; ?>

<?php else: ?>

<textarea
    name="answer[<?=e($qid)?>]"
><?=e($value)?></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

<div class="respondent-actions">

<div></div>

<button
    class="primary"
    type="submit"
>
回答確認へ
</button>

</div>

</form>

</div>
<?php
}

function render_confirm_page(
    ?array $survey
): void {
    if ($survey === null) {
?>
<div class="respondent-card">
アンケートが存在しません。
</div>
<?php
        return;
    }

    $id =
        (string)$survey['id'];

    $answers =
        $_SESSION['answer_state'][$id]
        ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    normalize_questions($survey);
?>
<div class="respondent-card">

<h1>回答確認</h1>

<?php foreach (
    answer_questions($survey)
    as $question
): ?>

<?php
$qid =
    (string)(
        $question['id']
        ?? ''
    );

$value =
    $answers[$qid]
    ?? '';

if (is_array($value)) {
    $value =
        implode(
            ', ',
            array_map(
                'strval',
                $value
            )
        );
}
?>

<div class="respondent-question">

<strong>
<?=e($question['number'] ?? '')?>
<?=e($question['text'] ?? '')?>
</strong>

<p>
<?=nl2br(e($value))?>
</p>

</div>

<?php endforeach; ?>

<div class="respondent-actions">

<form
    method="post"
    action="index.php?screen=answer&id=<?=e($id)?>"
>
<input
    type="hidden"
    name="action"
    value="answer_back"
>
<input
    type="hidden"
    name="id"
    value="<?=e($id)?>"
>

<button type="submit">
戻る
</button>

</form>

<form
    method="post"
    action="index.php?screen=confirm&id=<?=e($id)?>"
>
<input
    type="hidden"
    name="action"
    value="answer_submit"
>
<input
    type="hidden"
    name="id"
    value="<?=e($id)?>"
>

<button
    class="primary"
    type="submit"
    onclick="return confirm('この回答を送信しますか？')"
>
回答を送信
</button>

</form>

</div>

</div>
<?php
}

function render_complete_page(
    ?array $survey
): void {
?>
<div class="respondent-card">

<h1>
回答完了
</h1>

<p>
回答を受け付けました。
ご協力ありがとうございました。
</p>

</div>
<?php
}
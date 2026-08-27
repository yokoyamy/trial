<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * Single Entry Point / Apache 2.4 / PHP 8.5
 *
 * 要件:
 * - DBなし
 * - 管理者認証なし
 * - CSRFなし（仕様）
 * - PHP cURLなし
 * - PHP mail()なし
 * - kintone: X-Cybozu-Authorization
 * - SMTP: ソケット通信
 * - サーバー側JSON永続化
 * - GETごとのsession_regenerate_id()なし
 * - 日本語公開パスをCookie Pathへ直接使用しない
 * - 設定保存はPOST自身で結果を表示
 * - 外部通信はタイムアウト必須
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR       = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const ANSWERS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'answers.json';
const SEND_LOG_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'send_logs.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT    = 20;

const ALLOWED_SCREENS = [
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

/*
|--------------------------------------------------------------------------
| 初期化
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Session
|
| 日本語ディレクトリ名をCookie Pathへ直接渡さない。
| GETごとにセッションIDを再生成しない。
|--------------------------------------------------------------------------
*/

$https = (
    (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
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

/*
|--------------------------------------------------------------------------
| Routing
|--------------------------------------------------------------------------
*/

$screen = (string)($_GET['screen'] ?? 'list');

if (!in_array($screen, ALLOWED_SCREENS, true)) {
    $screen = 'list';
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/*
|--------------------------------------------------------------------------
| POST処理
|
| 設定画面についてはPRG/303/flash依存を禁止。
| POSTした同じ画面をそのまま描画する。
|--------------------------------------------------------------------------
*/

$postResult = null;

if ($isPost) {
    $action = (string)($_POST['action'] ?? '');

    try {
        $postResult = handle_post($action, $screen);
    } catch (Throwable $e) {
        $postResult = [
            'type' => 'error',
            'message' => '処理に失敗しました。',
            'detail' => public_error_message($e),
        ];
    }
}

/*
|--------------------------------------------------------------------------
| 自動終了判定
|--------------------------------------------------------------------------
*/

$surveys = read_json(SURVEYS_FILE);

$changed = false;

foreach ($surveys as &$s) {
    if (
        ($s['status'] ?? '') === 'published'
        && !empty($s['endAt'])
    ) {
        $end = strtotime((string)$s['endAt']);

        if ($end !== false && $end < time()) {
            $s['status'] = 'ended';
            $s['updatedAt'] = now_iso();
            $changed = true;
        }
    }
}

unset($s);

if ($changed) {
    write_json_atomic(SURVEYS_FILE, $surveys);
}

/*
|--------------------------------------------------------------------------
| 対象アンケート
|--------------------------------------------------------------------------
*/

$survey = null;

if (
    in_array(
        $screen,
        ['edit', 'preview', 'send', 'analytics', 'answer', 'confirm', 'complete'],
        true
    )
) {
    $id = trim((string)($_GET['id'] ?? ''));

    if ($id !== '') {
        $survey = find_survey($id);
    }

    /*
     * 集計・送信は対象アンケート必須。
     * 別アンケートを画面内で選択させない。
     */
    if (
        in_array($screen, ['send', 'analytics'], true)
        && $survey === null
    ) {
        if (!$isPost) {
            redirect('index.php?screen=list');
        }
    }
}

/*
|--------------------------------------------------------------------------
| HTML
|--------------------------------------------------------------------------
*/

render_header($screen);

if ($postResult !== null) {
    render_result($postResult);
}

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


/*
|--------------------------------------------------------------------------
| Defaults
|--------------------------------------------------------------------------
*/

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
            'connection_status' => '未設定',
            'last_test_at' => null,
        ],
    ];
}


/*
|--------------------------------------------------------------------------
| JSON persistence
|--------------------------------------------------------------------------
*/

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

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('保存ディレクトリを作成できません。');
        }
    }

    $tmp = tempnam($dir, 'survey_');

    if ($tmp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        @unlink($tmp);
        throw new RuntimeException('JSONを生成できません。');
    }

    if (
        file_put_contents(
            $tmp,
            $json . PHP_EOL,
            LOCK_EX
        ) === false
    ) {
        @unlink($tmp);
        throw new RuntimeException('データを書き込めません。');
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データを保存できません。');
    }
}


/*
|--------------------------------------------------------------------------
| Common
|--------------------------------------------------------------------------
*/

function now_iso(): string
{
    return date('c');
}

function uid(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(12));
}

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function screen_url(string $screen, array $params = []): string
{
    $query = ['screen' => $screen];

    foreach ($params as $key => $value) {
        $query[$key] = $value;
    }

    return 'index.php?' . http_build_query($query);
}

function public_error_message(Throwable $e): string
{
    if ($e instanceof InvalidArgumentException) {
        return ' ' . $e->getMessage();
    }

    if ($e instanceof RuntimeException) {
        return ' ' . $e->getMessage();
    }

    return '';
}

function result_ok(string $message, array $extra = []): array
{
    return array_merge([
        'type' => 'success',
        'message' => $message,
    ], $extra);
}

function result_error(string $message, array $extra = []): array
{
    return array_merge([
        'type' => 'error',
        'message' => $message,
    ], $extra);
}


/*
|--------------------------------------------------------------------------
| POST Dispatcher
|--------------------------------------------------------------------------
*/

function handle_post(string $action, string $screen): array
{
    switch ($action) {

        case 'save_kintone':
            return action_save_kintone();

        case 'test_kintone':
            return action_test_kintone();

        case 'fetch_kintone_fields':
            return action_fetch_kintone_fields();

        case 'sync_kintone':
            return action_sync_kintone();

        case 'save_mail':
            return action_save_mail();

        case 'test_mail':
            return action_test_mail();

        case 'send_test_mail':
            return action_send_test_mail();

        case 'save_survey':
            return action_save_survey();

        case 'delete_survey':
            return action_delete_survey();

        case 'duplicate_survey':
            return action_duplicate_survey();

        case 'change_status':
            return action_change_status();

        case 'save_questions':
            return action_save_questions();

        case 'send_mail':
            return action_send_mail();

        case 'resend_mail':
            return action_resend_mail();

        case 'remind_mail':
            return action_remind_mail();

        case 'answer_save':
            return action_answer_save();

        case 'answer_submit':
            return action_answer_submit();

        case 'export_csv':
            return action_export_csv();

        case 'export_pdf':
            return action_export_pdf();

        default:
            return result_error('不明な操作です。');
    }
}


/*
|--------------------------------------------------------------------------
| Survey helpers
|--------------------------------------------------------------------------
*/

function find_survey(string $id): ?array
{
    foreach (read_json(SURVEYS_FILE) as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function normalize_survey(array $input, ?array $old = null): array
{
    $title = trim((string)($input['title'] ?? ''));

    if ($title === '') {
        throw new InvalidArgumentException('アンケートタイトルを入力してください。');
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException('タイトルは200文字以内で入力してください。');
    }

    $description = trim((string)($input['description'] ?? ''));

    if (mb_strlen($description) > 5000) {
        throw new InvalidArgumentException('説明は5000文字以内で入力してください。');
    }

    $startAt = trim((string)($input['startAt'] ?? ''));
    $endAt   = trim((string)($input['endAt'] ?? ''));

    if ($startAt !== '' && strtotime($startAt) === false) {
        throw new InvalidArgumentException('開始日時が不正です。');
    }

    if ($endAt !== '' && strtotime($endAt) === false) {
        throw new InvalidArgumentException('終了日時が不正です。');
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) > strtotime($endAt)
    ) {
        throw new InvalidArgumentException('終了日時は開始日時以降にしてください。');
    }

    $numbering = (string)($input['numbering'] ?? 'global');

    if (!in_array($numbering, ['global', 'group'], true)) {
        $numbering = 'global';
    }

    return [
        'id' => $old['id'] ?? uid('survey-'),
        'title' => $title,
        'description' => $description,
        'startAt' => $startAt,
        'endAt' => $endAt,
        'numbering' => $numbering,
        'status' => $old['status'] ?? 'draft',
        'groups' => $old['groups'] ?? [],
        'createdAt' => $old['createdAt'] ?? now_iso(),
        'updatedAt' => now_iso(),
    ];
}

function recalc_questions(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {

        $questionNo = 1;

        foreach ($group['questions'] as &$question) {

            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] = 'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);

        $groupNo++;
    }

    unset($group);
}

function normalize_question(array $q): array
{
    $type = (string)($q['type'] ?? 'single');

    if (!in_array($type, ['single', 'multiple', 'text'], true)) {
        $type = 'single';
    }

    $options = [];

    if ($type !== 'text') {
        foreach ((array)($q['options'] ?? []) as $option) {
            $value = trim((string)$option);

            if ($value !== '') {
                $options[] = $value;
            }
        }

        if (!$options) {
            $options = ['選択肢1'];
        }
    }

    return [
        'id' => (string)($q['id'] ?? uid('question-')),
        'number' => (string)($q['number'] ?? ''),
        'text' => trim((string)($q['text'] ?? '')),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $options,
        'branches' => is_array($q['branches'] ?? null)
            ? $q['branches']
            : [],
    ];
}


/*
|--------------------------------------------------------------------------
| Survey actions
|--------------------------------------------------------------------------
*/

function action_save_survey(): array
{
    $id = trim((string)($_POST['id'] ?? ''));

    $surveys = read_json(SURVEYS_FILE);

    $old = null;

    if ($id !== '') {
        foreach ($surveys as $candidate) {
            if (($candidate['id'] ?? '') === $id) {
                $old = $candidate;
                break;
            }
        }
    }

    $survey = normalize_survey($_POST, $old);

    if ($old === null) {
        $surveys[] = $survey;
    } else {
        foreach ($surveys as $index => $candidate) {
            if (($candidate['id'] ?? '') === $id) {
                $surveys[$index] = $survey;
                break;
            }
        }
    }

    write_json_atomic(SURVEYS_FILE, $surveys);

    /*
     * 保存後にファイルから再読込して確認する。
     * 「保存しました」と表示するだけにはしない。
     */
    $verified = find_survey($survey['id']);

    if ($verified === null) {
        throw new RuntimeException('保存後のデータ確認に失敗しました。');
    }

    return result_ok(
        'アンケートを保存しました。',
        [
            'redirect' => screen_url('list'),
        ]
    );
}

function action_delete_survey(): array
{
    $id = trim((string)($_POST['id'] ?? ''));

    if ($id === '') {
        return result_error('アンケートIDがありません。');
    }

    $surveys = read_json(SURVEYS_FILE);

    $new = [];

    $found = false;

    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $id) {
            $found = true;
            continue;
        }

        $new[] = $survey;
    }

    if (!$found) {
        return result_error('対象アンケートが見つかりません。');
    }

    write_json_atomic(SURVEYS_FILE, $new);

    return result_ok(
        'アンケートを削除しました。',
        ['redirect' => screen_url('list')]
    );
}

function action_duplicate_survey(): array
{
    $id = trim((string)($_POST['id'] ?? ''));

    $survey = find_survey($id);

    if ($survey === null) {
        return result_error('複製対象のアンケートが見つかりません。');
    }

    $copy = $survey;

    $copy['id'] = uid('survey-');
    $copy['title'] = ($survey['title'] ?? '') . '（コピー）';
    $copy['status'] = 'draft';
    $copy['createdAt'] = now_iso();
    $copy['updatedAt'] = now_iso();

    foreach ($copy['groups'] as &$group) {
        $group['id'] = uid('group-');

        foreach ($group['questions'] as &$question) {
            $question['id'] = uid('question-');
        }

        unset($question);
    }

    unset($group);

    recalc_questions($copy);

    $surveys = read_json(SURVEYS_FILE);
    $surveys[] = $copy;

    write_json_atomic(SURVEYS_FILE, $surveys);

    return result_ok(
        'アンケートを複製しました。',
        ['redirect' => screen_url('list')]
    );
}

function action_change_status(): array
{
    $id = trim((string)($_POST['id'] ?? ''));
    $status = trim((string)($_POST['status'] ?? ''));

    if (!in_array($status, ['draft', 'published', 'stopped'], true)) {
        return result_error('不正な状態変更です。');
    }

    $surveys = read_json(SURVEYS_FILE);

    foreach ($surveys as &$survey) {

        if (($survey['id'] ?? '') !== $id) {
            continue;
        }

        if (($survey['status'] ?? '') === 'ended') {
            return result_error('終了したアンケートは状態変更できません。');
        }

        $current = (string)($survey['status'] ?? 'draft');

        $valid = (
            ($current === 'draft' && $status === 'published')
            || ($current === 'published' && $status === 'stopped')
            || ($current === 'stopped' && $status === 'published')
        );

        if (!$valid) {
            return result_error('許可されていない状態遷移です。');
        }

        $survey['status'] = $status;
        $survey['updatedAt'] = now_iso();

        write_json_atomic(SURVEYS_FILE, $surveys);

        return result_ok('状態を変更しました。');
    }

    unset($survey);

    return result_error('アンケートが見つかりません。');
}

function action_save_questions(): array
{
    $id = trim((string)($_POST['survey_id'] ?? ''));

    $surveys = read_json(SURVEYS_FILE);

    foreach ($surveys as &$survey) {

        if (($survey['id'] ?? '') !== $id) {
            continue;
        }

        $raw = json_decode(
            (string)($_POST['structure'] ?? ''),
            true
        );

        if (!is_array($raw)) {
            return result_error('質問データが不正です。');
        }

        $groups = [];

        foreach ($raw as $g) {
            $group = [
                'id' => (string)($g['id'] ?? uid('group-')),
                'title' => trim((string)($g['title'] ?? '')),
                'questions' => [],
            ];

            foreach ((array)($g['questions'] ?? []) as $q) {
                $group['questions'][] = normalize_question($q);
            }

            $groups[] = $group;
        }

        $survey['groups'] = $groups;

        recalc_questions($survey);

        $survey['updatedAt'] = now_iso();

        write_json_atomic(SURVEYS_FILE, $surveys);

        return result_ok('質問構成を保存しました。');
    }

    unset($survey);

    return result_error('アンケートが見つかりません。');
}


/*
|--------------------------------------------------------------------------
| kintone
|--------------------------------------------------------------------------
*/

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    $value = preg_replace('#^https?://#i', '', $value);
    $value = preg_replace('#/.*$#', '', $value);
    $value = preg_replace('#\.cybozu\.com$#i', '', $value);

    if (
        $value === ''
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/', $value)
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

    if (
        $proxy !== ''
        && !preg_match('/^[^:\s\/]+:\d{1,5}$/', $proxy)
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }
}

function action_save_kintone(): array
{
    $settings = read_json(SETTINGS_FILE);

    $k = $settings['kintone'] ?? default_settings()['kintone'];

    $k['subdomain'] = normalize_kintone_subdomain(
        (string)($_POST['subdomain'] ?? '')
    );

    $appId = trim((string)($_POST['app_id'] ?? ''));

    if ($appId === '' || !ctype_digit($appId)) {
        return result_error('顧客管理アプリIDは数字で入力してください。');
    }

    $k['app_id'] = $appId;
    $k['username'] = trim((string)($_POST['username'] ?? ''));

    if ($k['username'] === '') {
        return result_error('ログイン名を入力してください。');
    }

    /*
     * パスワードは空欄なら既存値を維持。
     */
    if ((string)($_POST['password'] ?? '') !== '') {
        $k['password'] = (string)$_POST['password'];
    }

    if ($k['password'] === '') {
        return result_error('パスワードを入力してください。');
    }

    $k['proxy'] = trim((string)($_POST['proxy'] ?? ''));
    $k['verify_ssl'] = isset($_POST['verify_ssl']);

    if (
        $k['proxy'] !== ''
        && !preg_match('/^[^:\s\/]+:\d{1,5}$/', $k['proxy'])
    ) {
        return result_error('Proxyはhost:port形式で入力してください。');
    }

    $settings['kintone'] = $k;

    write_json_atomic(SETTINGS_FILE, $settings);

    /*
     * 実ファイルから再取得して確認。
     */
    $check = read_json(SETTINGS_FILE);

    if (
        ($check['kintone']['subdomain'] ?? '')
        !== $k['subdomain']
    ) {
        return result_error(
            '設定ファイルへの保存確認に失敗しました。'
        );
    }

    return result_ok(
        'kintone設定を保存しました。',
        [
            'saved_at' => now_iso(),
        ]
    );
}

function action_test_kintone(): array
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    try {
        validate_kintone($k);

        /*
         * 1回だけ接続。
         * 認証失敗時のリトライは禁止。
         */
        $response = kintone_request(
            $k,
            '/k/v1/app.json?id=' . rawurlencode((string)$k['app_id']),
            'GET'
        );

        $settings['kintone']['last_test_at'] = now_iso();

        if (
            $response['status'] >= 200
            && $response['status'] < 300
        ) {
            $settings['kintone']['connection_status'] = '接続確認済み';

            write_json_atomic(SETTINGS_FILE, $settings);

            return result_ok(
                'kintoneへの接続に成功しました。',
                [
                    'http_status' => $response['status'],
                ]
            );
        }

        $settings['kintone']['connection_status'] = '接続できません';

        write_json_atomic(SETTINGS_FILE, $settings);

        return result_error(
            'kintoneへの接続に失敗しました。',
            [
                'detail' => kintone_public_error(
                    $response['status'],
                    $response['body']
                ),
            ]
        );

    } catch (Throwable $e) {

        $settings['kintone']['connection_status'] = '接続できません';
        $settings['kintone']['last_test_at'] = now_iso();

        write_json_atomic(SETTINGS_FILE, $settings);

        return result_error(
            'kintone接続エラー。',
            [
                'detail' => public_error_message($e),
            ]
        );
    }
}

function action_fetch_kintone_fields(): array
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    try {
        validate_kintone($k);

        $response = kintone_request(
            $k,
            '/k/v1/app.json?id=' . rawurlencode((string)$k['app_id']),
            'GET'
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return result_error(
                'kintoneの項目一覧取得に失敗しました。',
                [
                    'detail' => kintone_public_error(
                        $response['status'],
                        $response['body']
                    ),
                ]
            );
        }

        $data = json_decode($response['body'], true);

        if (!is_array($data)) {
            return result_error('kintoneから不正な応答が返りました。');
        }

        $properties = $data['properties'] ?? [];

        if (!is_array($properties)) {
            return result_error('kintoneの項目情報を解析できません。');
        }

        $fields = [];

        foreach ($properties as $code => $field) {
            $fields[] = [
                'code' => (string)$code,
                'label' => (string)($field['label'] ?? $code),
                'type' => (string)($field['type'] ?? ''),
            ];
        }

        usort(
            $fields,
            static fn(array $a, array $b): int =>
                strcmp($a['label'], $b['label'])
        );

        $settings['kintone']['fields'] = $fields;

        write_json_atomic(SETTINGS_FILE, $settings);

        return result_ok(
            'kintoneの項目一覧を再取得しました。',
            [
                'field_count' => count($fields),
            ]
        );

    } catch (Throwable $e) {
        return result_error(
            'kintone項目取得エラー。',
            [
                'detail' => public_error_message($e),
            ]
        );
    }
}

function action_sync_kintone(): array
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    try {
        validate_kintone($k);

        $mapping = $k['field_mapping'] ?? [];

        $fields = [
            'organization' => trim((string)($mapping['organization'] ?? '')),
            'name' => trim((string)($mapping['name'] ?? '')),
            'email' => trim((string)($mapping['email'] ?? '')),
            'department' => trim((string)($mapping['department'] ?? '')),
            'phone' => trim((string)($mapping['phone'] ?? '')),
            'address' => array_values(
                array_filter(
                    array_map(
                        'strval',
                        (array)($mapping['address'] ?? [])
                    )
                )
            ),
        ];

        if ($fields['name'] === '') {
            return result_error('氏名の項目を指定してください。');
        }

        if ($fields['email'] === '') {
            return result_error('メールアドレスの項目を指定してください。');
        }

        $customers = [];

        $offset = 0;
        $limit = 500;

        do {
            $query = 'limit ' . $limit . ' offset ' . $offset;

            $path =
                '/k/v1/records.json'
                . '?app=' . rawurlencode((string)$k['app_id'])
                . '&query=' . rawurlencode($query);

            $response = kintone_request(
                $k,
                $path,
                'GET'
            );

            if ($response['status'] < 200 || $response['status'] >= 300) {
                return result_error(
                    'kintone顧客情報の取得に失敗しました。',
                    [
                        'detail' => kintone_public_error(
                            $response['status'],
                            $response['body']
                        ),
                    ]
                );
            }

            $data = json_decode($response['body'], true);

            if (!is_array($data)) {
                return result_error('kintone応答を解析できません。');
            }

            $records = (array)($data['records'] ?? []);

            foreach ($records as $record) {

                $get = static function (string $code) use ($record): string {
                    if ($code === '') {
                        return '';
                    }

                    $value = $record[$code]['value'] ?? '';

                    if (is_array($value)) {
                        $parts = [];

                        foreach ($value as $item) {
                            if (is_array($item)) {
                                $parts[] = (string)($item['name'] ?? $item['code'] ?? '');
                            } else {
                                $parts[] = (string)$item;
                            }
                        }

                        return implode(', ', $parts);
                    }

                    return trim((string)$value);
                };

                $addressParts = [];

                foreach ($fields['address'] as $code) {
                    $value = $get($code);

                    if ($value !== '') {
                        $addressParts[] = $value;
                    }
                }

                $customers[] = [
                    'id' => uid('customer-'),
                    'kintone_record_id' =>
                        (string)($record['$id']['value'] ?? ''),
                    'organization' => $get($fields['organization']),
                    'name' => $get($fields['name']),
                    'email' => $get($fields['email']),
                    'department' => $get($fields['department']),
                    'phone' => $get($fields['phone']),
                    'address' => implode(' ', $addressParts),
                    'updatedAt' => now_iso(),
                ];
            }

            $count = count($records);
            $offset += $count;

        } while ($count === $limit);

        write_json_atomic(CUSTOMERS_FILE, $customers);

        return result_ok(
            '顧客情報を同期しました。',
            [
                'customer_count' => count($customers),
            ]
        );

    } catch (Throwable $e) {
        return result_error(
            '顧客情報同期エラー。',
            [
                'detail' => public_error_message($e),
            ]
        );
    }
}

function kintone_request(
    array $k,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    validate_kintone($k);

    $host = normalize_kintone_subdomain(
        (string)$k['subdomain']
    ) . '.cybozu.com';

    $authorization = base64_encode(
        (string)$k['username']
        . ':'
        . (string)$k['password']
    );

    $payload = '';

    if ($body !== null) {
        $payload = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            throw new RuntimeException('JSONを生成できません。');
        }
    }

    $headers = [
        'Host: ' . $host,
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
        'Content-Type: application/json',
        'Connection: close',
    ];

    if ($payload !== '') {
        $headers[] = 'Content-Length: ' . strlen($payload);
    }

    return raw_http_request(
        $host,
        443,
        $path,
        $method,
        implode("\r\n", $headers),
        $payload,
        (string)($k['proxy'] ?? ''),
        (bool)($k['verify_ssl'] ?? false)
    );
}


/*
|--------------------------------------------------------------------------
| Raw HTTPS
|
| PHP cURLを使わず、stream_socket_clientで実装。
| Proxy指定時はCONNECT。
|--------------------------------------------------------------------------
*/

function raw_http_request(
    string $host,
    int $port,
    string $path,
    string $method,
    string $headers,
    string $body = '',
    string $proxy = '',
    bool $verifySsl = false
): array {

    $socket = null;

    $connectHost = $host;
    $connectPort = $port;

    if ($proxy !== '') {
        [$proxyHost, $proxyPort] = parse_host_port($proxy);

        $connectHost = $proxyHost;
        $connectPort = $proxyPort;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        'tcp://' . $connectHost . ':' . $connectPort,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            '外部サービスへ接続できません。'
        );
    }

    stream_set_timeout($socket, READ_TIMEOUT);

    if ($proxy !== '') {

        $connectRequest =
            "CONNECT {$host}:{$port} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Connection: close\r\n"
            . "\r\n";

        fwrite($socket, $connectRequest);

        $connectResponse = read_http_headers($socket);

        if ($connectResponse['status'] !== 200) {
            fclose($socket);

            throw new RuntimeException(
                'Proxy CONNECTに失敗しました。HTTP '
                . $connectResponse['status']
            );
        }
    }

    $crypto = @stream_socket_enable_crypto(
        $socket,
        true,
        STREAM_CRYPTO_METHOD_TLS_CLIENT
    );

    if ($crypto !== true) {
        fclose($socket);

        throw new RuntimeException(
            'TLS接続を確立できません。'
        );
    }

    /*
     * verify_ssl=false は仕様上POCでは許可。
     * stream_socket_enable_crypto()だけでは証明書検証設定を
     *細かく制御できないため、実環境ではverify_ssl=trueを推奨。
     */
    if (!$verifySsl) {
        /*
         * 接続自体はTLSで暗号化する。
         * 証明書検証を無効化する設定はPOC要件。
         */
    }

    $request =
        $method
        . ' '
        . $path
        . " HTTP/1.1\r\n"
        . $headers
        . "\r\n\r\n"
        . $body;

    if (@fwrite($socket, $request) === false) {
        fclose($socket);

        throw new RuntimeException(
            '外部サービスへの送信に失敗しました。'
        );
    }

    $response = read_http_response($socket);

    fclose($socket);

    return $response;
}

function parse_host_port(string $value): array
{
    $parts = explode(':', trim($value), 2);

    if (count($parts) !== 2) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で指定してください。'
        );
    }

    $host = trim($parts[0]);
    $port = (int)$parts[1];

    if ($host === '' || $port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'Proxy設定が不正です。'
        );
    }

    return [$host, $port];
}

function read_http_headers($socket): array
{
    $headers = '';

    while (!feof($socket)) {
        $line = fgets($socket);

        if ($line === false) {
            break;
        }

        $headers .= $line;

        if (substr($headers, -4) === "\r\n\r\n") {
            break;
        }

        if (strlen($headers) > 1024 * 1024) {
            throw new RuntimeException(
                'HTTPヘッダーが大きすぎます。'
            );
        }
    }

    $first = strtok($headers, "\r\n");

    preg_match(
        '/HTTP\/\d(?:\.\d)?\s+(\d{3})/',
        (string)$first,
        $m
    );

    return [
        'status' => (int)($m[1] ?? 0),
        'headers' => $headers,
    ];
}

function read_http_response($socket): array
{
    $headerBlock = '';

    while (!feof($socket)) {
        $line = fgets($socket);

        if ($line === false) {
            break;
        }

        $headerBlock .= $line;

        if (substr($headerBlock, -4) === "\r\n\r\n") {
            break;
        }

        if (strlen($headerBlock) > 1024 * 1024) {
            throw new RuntimeException(
                'HTTPレスポンスヘッダーが大きすぎます。'
            );
        }
    }

    $lines = preg_split(
        "/\r\n|\n|\r/",
        trim($headerBlock)
    );

    $status = 0;
    $responseHeaders = [];

    if (!empty($lines[0])) {
        preg_match(
            '/HTTP\/\d(?:\.\d)?\s+(\d{3})/',
            $lines[0],
            $m
        );

        $status = (int)($m[1] ?? 0);
    }

    for ($i = 1; $i < count($lines); $i++) {
        $line = $lines[$i];

        if (strpos($line, ':') === false) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);

        $responseHeaders[strtolower(trim($name))] =
            trim($value);
    }

    $body = '';

    $transferEncoding =
        strtolower((string)($responseHeaders['transfer-encoding'] ?? ''));

    $contentLength =
        isset($responseHeaders['content-length'])
            ? (int)$responseHeaders['content-length']
            : null;

    if (strpos($transferEncoding, 'chunked') !== false) {

        while (!feof($socket)) {

            $sizeLine = fgets($socket);

            if ($sizeLine === false) {
                break;
            }

            $size = hexdec(trim($sizeLine));

            if ($size === 0) {
                fgets($socket);
                break;
            }

            $remaining = $size;

            while ($remaining > 0 && !feof($socket)) {
                $chunk = fread(
                    $socket,
                    min($remaining, 8192)
                );

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $body .= $chunk;
                $remaining -= strlen($chunk);
            }

            fgets($socket);
        }

    } elseif ($contentLength !== null) {

        $remaining = $contentLength;

        while ($remaining > 0 && !feof($socket)) {
            $chunk = fread(
                $socket,
                min($remaining, 8192)
            );

            if ($chunk === false || $chunk === '') {
                break;
            }

            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

    } else {

        while (!feof($socket)) {
            $chunk = fread($socket, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $body .= $chunk;
        }
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $body,
    ];
}

function kintone_public_error(int $status, string $body): string
{
    $data = json_decode($body, true);

    if (is_array($data)) {
        $message = trim((string)($data['message'] ?? ''));

        if ($message !== '') {
            return ' ' . $message;
        }
    }

    if ($status > 0) {
        return ' HTTP ' . $status;
    }

    return '';
}


/*
|--------------------------------------------------------------------------
| Mail settings
|--------------------------------------------------------------------------
*/

function action_save_mail(): array
{
    $settings = read_json(SETTINGS_FILE);

    $mail = $settings['mail'] ?? default_settings()['mail'];

    $host = trim((string)($_POST['host'] ?? ''));

    if ($host === '') {
        return result_error('SMTPサーバを入力してください。');
    }

    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)) {
        return result_error('SMTPサーバの形式が不正です。');
    }

    $port = (int)($_POST['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        return result_error('SMTPポートが不正です。');
    }

    $encryption = (string)($_POST['encryption'] ?? 'tls');

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        return result_error('暗号化方式が不正です。');
    }

    $fromEmail = trim((string)($_POST['from_email'] ?? ''));

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return result_error(
            '送信元メールアドレスが不正です。'
        );
    }

    $replyTo = trim((string)($_POST['reply_to'] ?? ''));

    if (
        $replyTo !== ''
        && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)
    ) {
        return result_error(
            '返信先メールアドレスが不正です。'
        );
    }

    $mail['host'] = $host;
    $mail['port'] = $port;
    $mail['encryption'] = $encryption;
    $mail['auth'] = isset($_POST['auth']);
    $mail['username'] = trim((string)($_POST['username'] ?? ''));

    if ((string)($_POST['password'] ?? '') !== '') {
        $mail['password'] = (string)$_POST['password'];
    }

    $mail['from_email'] = $fromEmail;
    $mail['from_name'] = trim((string)($_POST['from_name'] ?? ''));
    $mail['reply_to'] = $replyTo;

    if ($mail['auth'] && $mail['username'] === '') {
        return result_error(
            'SMTP認証を使用する場合はユーザー名が必要です。'
        );
    }

    if ($mail['auth'] && $mail['password'] === '') {
        return result_error(
            'SMTP認証を使用する場合はパスワードが必要です。'
        );
    }

    $settings['mail'] = $mail;

    write_json_atomic(SETTINGS_FILE, $settings);

    $check = read_json(SETTINGS_FILE);

    if (
        ($check['mail']['host'] ?? '')
        !== $mail['host']
    ) {
        return result_error(
            'SMTP設定の保存確認に失敗しました。'
        );
    }

    return result_ok(
        'メールサーバ設定を保存しました。',
        ['saved_at' => now_iso()]
    );
}

function validate_mail(array $mail): void
{
    if (trim((string)($mail['host'] ?? '')) === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを設定してください。'
        );
    }

    $port = (int)($mail['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (!in_array(
        (string)($mail['encryption'] ?? ''),
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new InvalidArgumentException(
            'SMTP暗号化方式が不正です。'
        );
    }

    if (!filter_var(
        (string)($mail['from_email'] ?? ''),
        FILTER_VALIDATE_EMAIL
    )) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    if (
        !empty($mail['auth'])
        && (
            trim((string)($mail['username'] ?? '')) === ''
            || (string)($mail['password'] ?? '') === ''
        )
    ) {
        throw new InvalidArgumentException(
            'SMTP認証情報が不足しています。'
        );
    }
}

function action_test_mail(): array
{
    $settings = read_json(SETTINGS_FILE);
    $mail = $settings['mail'] ?? [];

    try {
        validate_mail($mail);

        $smtp = smtp_connect($mail);

        smtp_command($smtp, 'QUIT', [221]);

        fclose($smtp);

        $settings['mail']['connection_status'] =
            '接続確認済み';

        $settings['mail']['last_test_at'] =
            now_iso();

        write_json_atomic(SETTINGS_FILE, $settings);

        return result_ok(
            'SMTPサーバへの接続に成功しました。'
        );

    } catch (Throwable $e) {

        $settings['mail']['connection_status'] =
            '接続できません';

        $settings['mail']['last_test_at'] =
            now_iso();

        write_json_atomic(SETTINGS_FILE, $settings);

        return result_error(
            'SMTP接続エラー。',
            [
                'detail' => public_error_message($e),
            ]
        );
    }
}

function action_send_test_mail(): array
{
    $settings = read_json(SETTINGS_FILE);
    $mail = $settings['mail'] ?? [];

    try {
        validate_mail($mail);

        $to = trim((string)($_POST['test_to'] ?? ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return result_error(
                'テスト送信先メールアドレスが不正です。'
            );
        }

        smtp_send(
            $mail,
            $to,
            'アンケートアプリ テストメール',
            'SMTP接続およびメール送信テストです。'
        );

        return result_ok(
            'テストメールを送信しました。'
        );

    } catch (Throwable $e) {
        return result_error(
            'テストメール送信に失敗しました。',
            [
                'detail' => public_error_message($e),
            ]
        );
    }
}

function smtp_connect(array $mail)
{
    $host = (string)$mail['host'];
    $port = (int)$mail['port'];
    $encryption = (string)$mail['encryption'];

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host . ':' . $port;
    } else {
        $target = 'tcp://' . $host . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません。'
        );
    }

    stream_set_timeout($socket, READ_TIMEOUT);

    smtp_expect($socket, [220]);

    smtp_command($socket, 'EHLO localhost', [250]);

    if ($encryption === 'tls') {

        smtp_command($socket, 'STARTTLS', [220]);

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'SMTP TLSを開始できません。'
            );
        }

        smtp_command($socket, 'EHLO localhost', [250]);
    }

    if (!empty($mail['auth'])) {

        smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $socket,
            base64_encode((string)$mail['username']),
            [334]
        );

        smtp_command(
            $socket,
            base64_encode((string)$mail['password']),
            [235]
        );
    }

    return $socket;
}

function smtp_expect($socket, array $expected): string
{
    $response = '';

    while (!feof($socket)) {

        $line = fgets($socket);

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
                break;
            }
        }
    }

    $code = (int)substr(trim($response), 0, 3);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'SMTP応答エラー。'
        );
    }

    return $response;
}

function smtp_command(
    $socket,
    string $command,
    array $expected
): string {
    if (@fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException(
            'SMTPコマンド送信に失敗しました。'
        );
    }

    return smtp_expect($socket, $expected);
}

function smtp_send(
    array $mail,
    string $to,
    string $subject,
    string $body
): void {
    $socket = smtp_connect($mail);

    try {

        smtp_command(
            $socket,
            'MAIL FROM:<' . $mail['from_email'] . '>',
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

        $headers = [];

        $headers[] =
            'From: '
            . encode_mail_name((string)$mail['from_name'])
            . ' <'
            . $mail['from_email']
            . '>';

        $headers[] =
            'To: <' . $to . '>';

        $headers[] =
            'Subject: ' . encode_subject($subject);

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        $headers[] =
            'Content-Transfer-Encoding: 8bit';

        if (!empty($mail['reply_to'])) {
            $headers[] =
                'Reply-To: ' . $mail['reply_to'];
        }

        $message =
            implode("\r\n", $headers)
            . "\r\n\r\n"
            . normalize_mail_body($body);

        $message = preg_replace(
            "/\r?\n/",
            "\r\n",
            $message
        );

        $message = preg_replace(
            '/^\./m',
            '..',
            $message
        );

        fwrite(
            $socket,
            $message
            . "\r\n.\r\n"
        );

        smtp_expect($socket, [250]);

        smtp_command(
            $socket,
            'QUIT',
            [221]
        );

    } finally {
        fclose($socket);
    }
}

function encode_subject(string $value): string
{
    return '=?UTF-8?B?'
        . base64_encode($value)
        . '?=';
}

function encode_mail_name(string $name): string
{
    if ($name === '') {
        return '';
    }

    return '=?UTF-8?B?'
        . base64_encode($name)
        . '?=';
}

function normalize_mail_body(string $body): string
{
    return str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );
}


/*
|--------------------------------------------------------------------------
| Answer / send / analytics
|--------------------------------------------------------------------------
*/

function action_answer_save(): array
{
    $id = trim((string)($_POST['survey_id'] ?? ''));

    $survey = find_survey($id);

    if ($survey === null) {
        return result_error('アンケートが見つかりません。');
    }

    $answers = [];

    foreach ((array)($_POST['answer'] ?? []) as $qid => $value) {
        if (is_array($value)) {
            $answers[(string)$qid] =
                array_values(array_map('strval', $value));
        } else {
            $answers[(string)$qid] = (string)$value;
        }
    }

    $_SESSION['answer_draft_' . $id] = $answers;

    return result_ok('回答内容を一時保存しました。');
}

function action_answer_submit(): array
{
    $id = trim((string)($_POST['survey_id'] ?? ''));

    $survey = find_survey($id);

    if ($survey === null) {
        return result_error('アンケートが見つかりません。');
    }

    $answers = [];

    foreach ((array)($_POST['answer'] ?? []) as $qid => $value) {
        $answers[(string)$qid] = is_array($value)
            ? array_values(array_map('strval', $value))
            : (string)$value;
    }

    $errors = validate_answers(
        $survey,
        $answers
    );

    if ($errors) {
        return result_error(
            implode(' ', $errors)
        );
    }

    $all = read_json(ANSWERS_FILE);

    $all[] = [
        'id' => uid('answer-'),
        'survey_id' => $survey['id'],
        'answers' => $answers,
        'createdAt' => now_iso(),
    ];

    write_json_atomic(ANSWERS_FILE, $all);

    unset($_SESSION['answer_draft_' . $id]);

    return result_ok(
        '回答を送信しました。',
        [
            'redirect' => screen_url(
                'complete',
                ['id' => $id]
            ),
        ]
    );
}

function validate_answers(
    array $survey,
    array $answers
): array {
    $errors = [];

    foreach ((array)($survey['groups'] ?? []) as $group) {

        foreach ((array)($group['questions'] ?? []) as $q) {

            $qid = (string)($q['id'] ?? '');

            if (empty($q['required'])) {
                continue;
            }

            $value = $answers[$qid] ?? '';

            $empty = false;

            if (is_array($value)) {
                $empty = count($value) === 0;
            } else {
                $empty = trim((string)$value) === '';
            }

            if ($empty) {
                $errors[] =
                    ($q['number'] ?? '質問')
                    . ' は必須です。';
            }
        }
    }

    return $errors;
}

function action_send_mail(): array
{
    return send_selected_mail(false);
}

function action_resend_mail(): array
{
    return send_selected_mail(true);
}

function action_remind_mail(): array
{
    return send_selected_mail(true);
}

function send_selected_mail(bool $isResend): array
{
    $surveyId = trim((string)($_POST['survey_id'] ?? ''));

    $survey = find_survey($surveyId);

    if ($survey === null) {
        return result_error('対象アンケートがありません。');
    }

    $settings = read_json(SETTINGS_FILE);
    $mail = $settings['mail'] ?? [];

    try {
        validate_mail($mail);
    } catch (Throwable $e) {
        return result_error(
            'メールサーバ設定を確認してください。',
            ['detail' => public_error_message($e)]
        );
    }

    $customerIds = array_values(
        array_filter(
            array_map(
                'strval',
                (array)($_POST['customer_ids'] ?? [])
            )
        )
    );

    if (!$customerIds) {
        return result_error(
            '送信対象の顧客を選択してください。'
        );
    }

    $subject = trim((string)($_POST['subject'] ?? ''));

    if ($subject === '') {
        return result_error(
            'メール件名を入力してください。'
        );
    }

    $body = (string)($_POST['body'] ?? '');

    if ($body === '') {
        return result_error(
            'メール本文を入力してください。'
        );
    }

    $customers = read_json(CUSTOMERS_FILE);
    $logs = read_json(SEND_LOG_FILE);

    $sent = 0;
    $failed = 0;

    foreach ($customers as $customer) {

        if (
            !in_array(
                (string)($customer['id'] ?? ''),
                $customerIds,
                true
            )
        ) {
            continue;
        }

        $email = trim((string)($customer['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $failed++;

            $logs[] = [
                'id' => uid('send-'),
                'survey_id' => $surveyId,
                'customer_id' => $customer['id'] ?? '',
                'email' => $email,
                'status' => 'failed',
                'mode' => $isResend ? 'resend' : 'send',
                'error' => 'メールアドレス不正',
                'createdAt' => now_iso(),
            ];

            continue;
        }

        $personalBody = str_replace(
            [
                '{顧客名}',
                '{アンケートURL}',
            ],
            [
                (string)($customer['name'] ?? ''),
                answer_url($surveyId),
            ],
            $body
        );

        try {

            smtp_send(
                $mail,
                $email,
                $subject,
                $personalBody
            );

            $sent++;

            $logs[] = [
                'id' => uid('send-'),
                'survey_id' => $surveyId,
                'customer_id' => $customer['id'] ?? '',
                'email' => $email,
                'status' => 'sent',
                'mode' => $isResend ? 'resend' : 'send',
                'createdAt' => now_iso(),
            ];

        } catch (Throwable $e) {

            $failed++;

            $logs[] = [
                'id' => uid('send-'),
                'survey_id' => $surveyId,
                'customer_id' => $customer['id'] ?? '',
                'email' => $email,
                'status' => 'failed',
                'mode' => $isResend ? 'resend' : 'send',
                'error' => public_error_message($e),
                'createdAt' => now_iso(),
            ];
        }
    }

    write_json_atomic(SEND_LOG_FILE, $logs);

    return result_ok(
        'メール送信処理が完了しました。',
        [
            'sent' => $sent,
            'failed' => $failed,
        ]
    );
}

function answer_url(string $surveyId): string
{
    $scheme =
        (
            (!empty($_SERVER['HTTPS'])
             && $_SERVER['HTTPS'] !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
        )
            ? 'https'
            : 'http';

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    return $scheme
        . '://'
        . $host
        . '/'
        . ltrim(
            dirname(
                (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
            ),
            '/'
        )
        . '/index.php?screen=answer&id='
        . rawurlencode($surveyId);
}


/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/

function action_export_csv(): array
{
    $id = trim((string)($_POST['survey_id'] ?? ''));

    $survey = find_survey($id);

    if ($survey === null) {
        return result_error('アンケートが見つかりません。');
    }

    $answers = read_json(ANSWERS_FILE);

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey_'
        . preg_replace('/[^A-Za-z0-9_-]/', '_', $id)
        . '.csv"'
    );

    $out = fopen('php://output', 'wb');

    if ($out === false) {
        throw new RuntimeException(
            'CSV出力を開始できません。'
        );
    }

    fwrite($out, "\xEF\xBB\xBF");

    $header = [
        '回答ID',
        '回答日時',
    ];

    $questions = flatten_questions($survey);

    foreach ($questions as $q) {
        $header[] =
            ($q['number'] ?? '')
            . ' '
            . ($q['text'] ?? '');
    }

    fputcsv($out, $header);

    foreach ($answers as $answer) {

        if (($answer['survey_id'] ?? '') !== $id) {
            continue;
        }

        $row = [
            $answer['id'] ?? '',
            $answer['createdAt'] ?? '',
        ];

        foreach ($questions as $q) {

            $value =
                $answer['answers'][$q['id']] ?? '';

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $row[] = $value;
        }

        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}

function action_export_pdf(): array
{
    $id = trim((string)($_POST['survey_id'] ?? ''));

    $survey = find_survey($id);

    if ($survey === null) {
        return result_error('アンケートが見つかりません。');
    }

    $answers = read_json(ANSWERS_FILE);

    $lines = [];

    $lines[] = 'Survey Report';
    $lines[] = 'Title: ' . ascii_pdf_text(
        (string)($survey['title'] ?? '')
    );
    $lines[] = 'Generated: ' . date('Y-m-d H:i:s');

    $count = 0;

    foreach ($answers as $answer) {

        if (($answer['survey_id'] ?? '') !== $id) {
            continue;
        }

        $count++;

        $lines[] =
            'Answer ' . $count
            . ' / '
            . ($answer['createdAt'] ?? '');

        foreach (
            (array)($answer['answers'] ?? [])
            as $qid => $value
        ) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $lines[] =
                (string)$qid
                . ': '
                . ascii_pdf_text((string)$value);
        }
    }

    $pdf = make_simple_pdf($lines);

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey_'
        . preg_replace('/[^A-Za-z0-9_-]/', '_', $id)
        . '.pdf"'
    );

    echo $pdf;
    exit;
}

function ascii_pdf_text(string $value): string
{
    $value = preg_replace('/[^\x20-\x7E]/', '?', $value);

    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $value
    );
}

function make_simple_pdf(array $lines): string
{
    $objects = [];

    $objects[] =
        '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $stream = "BT\n/F1 10 Tf\n50 780 Td\n";

    foreach ($lines as $index => $line) {

        if ($index > 0) {
            $stream .= "0 -14 Td\n";
        }

        $stream .=
            '('
            . ascii_pdf_text((string)$line)
            . ") Tj\n";
    }

    $stream .= "ET";

    $objects[] =
        '<< /Type /Page'
        . ' /Parent 2 0 R'
        . ' /MediaBox [0 0 595 842]'
        . ' /Resources << /Font << /F1 4 0 R >> >>'
        . ' /Contents 5 0 R >>';

    $objects[] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $objects[] =
        '<< /Length '
        . strlen($stream)
        . " >>\nstream\n"
        . $stream
        . "\nendstream";

    $pdf = "%PDF-1.4\n";

    $offsets = [0];

    foreach ($objects as $index => $object) {

        $objectNumber = $index + 1;

        $offsets[$objectNumber] = strlen($pdf);

        $pdf .=
            $objectNumber
            . " 0 obj\n"
            . $object
            . "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .=
        "xref\n"
        . "0 "
        . (count($objects) + 1)
        . "\n"
        . "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
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


/*
|--------------------------------------------------------------------------
| Rendering - header
|--------------------------------------------------------------------------
*/

function render_header(string $screen): void
{
    $answerer = in_array(
        $screen,
        ['answer', 'confirm', 'complete'],
        true
    );
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート管理</title>

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

a{
    color:var(--primary);
    text-decoration:none;
}

a:hover{
    text-decoration:underline;
}

.admin-header{
    background:#0f172a;
    color:#fff;
    padding:0 24px;
}

.header-inner{
    max-width:1400px;
    margin:auto;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand{
    color:#fff;
    font-size:20px;
    font-weight:700;
}

.nav{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.nav a{
    color:#cbd5e1;
    padding:9px 12px;
    border-radius:8px;
}

.nav a:hover{
    background:#1e293b;
    color:#fff;
    text-decoration:none;
}

.container{
    max-width:1400px;
    margin:0 auto;
    padding:28px 20px 60px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:18px;
}

.card h1,
.card h2,
.card h3{
    margin-top:0;
}

.toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.button,
button{
    border:0;
    border-radius:8px;
    padding:10px 15px;
    font-size:14px;
    cursor:pointer;
    background:#e2e8f0;
    color:#0f172a;
}

button:hover,
.button:hover{
    filter:brightness(.97);
    text-decoration:none;
}

.primary{
    background:var(--primary);
    color:#fff;
}

.primary:hover{
    background:var(--primary-dark);
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

.muted{
    color:var(--gray);
}

.result{
    border-radius:10px;
    padding:14px 16px;
    margin-bottom:18px;
    border:1px solid var(--border);
    background:#fff;
}

.result.success{
    color:#166534;
    background:#f0fdf4;
    border-color:#bbf7d0;
}

.result.error{
    color:#991b1b;
    background:#fef2f2;
    border-color:#fecaca;
}

.result-detail{
    margin-top:8px;
    font-size:13px;
    white-space:pre-wrap;
}

.form-grid{
    display:grid;
    grid-template-columns:180px minmax(0,1fr);
    gap:14px 18px;
    align-items:center;
}

.form-grid label{
    font-weight:600;
}

input,
textarea,
select{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    font:inherit;
    background:#fff;
}

textarea{
    min-height:130px;
    resize:vertical;
}

input:focus,
textarea:focus,
select:focus{
    outline:3px solid rgba(37,99,235,.12);
    border-color:var(--primary);
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:18px;
}

.table-wrap{
    width:100%;
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

th,
td{
    border-bottom:1px solid var(--border);
    padding:12px 10px;
    text-align:left;
    vertical-align:top;
}

th{
    background:#f8fafc;
    white-space:nowrap;
}

.badge{
    display:inline-flex;
    padding:4px 8px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge.draft{
    background:#e2e8f0;
    color:#334155;
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

.stat-grid{
    display:grid;
    grid-template-columns:
        repeat(auto-fit,minmax(180px,1fr));
    gap:14px;
}

.stat{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    background:#fff;
}

.stat-label{
    color:var(--gray);
    font-size:13px;
}

.stat-value{
    font-size:26px;
    font-weight:700;
    margin-top:5px;
}

.question{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    margin-bottom:12px;
    background:#fff;
}

.question.dragging,
.group.dragging{
    opacity:.45;
}

.group{
    border:1px solid var(--border);
    border-radius:12px;
    padding:16px;
    margin-bottom:16px;
    background:#f8fafc;
}

.group-header{
    display:flex;
    gap:10px;
    align-items:center;
    margin-bottom:14px;
}

.drag-handle{
    cursor:grab;
    user-select:none;
    color:var(--gray);
}

.option-list{
    display:grid;
    gap:7px;
    margin-top:10px;
}

.answer-card{
    max-width:760px;
    margin:20px auto;
}

.answer-choice{
    display:flex;
    gap:10px;
    align-items:flex-start;
    padding:12px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:8px;
}

.answer-choice input{
    width:auto;
    margin-top:4px;
}

.help{
    color:var(--gray);
    font-size:13px;
}

.empty{
    padding:35px;
    text-align:center;
    color:var(--gray);
}

.small{
    font-size:12px;
}

pre.error-detail{
    white-space:pre-wrap;
    overflow:auto;
}

@media(max-width:720px){
    .admin-header{
        padding:0 12px;
    }

    .header-inner{
        align-items:flex-start;
        padding:14px 0;
        flex-direction:column;
    }

    .container{
        padding:18px 12px 40px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .card{
        padding:16px;
    }

    button,
    .button{
        min-height:44px;
    }
}
</style>
</head>

<body>

<?php if (!$answerer): ?>
<header class="admin-header">
<div class="header-inner">
    <a class="brand"
       href="<?=e(screen_url('list'))?>">
        アンケート管理
    </a>

    <nav class="nav">
        <a href="<?=e(screen_url('list'))?>">一覧</a>
        <a href="<?=e(screen_url('kintone'))?>">kintone</a>
        <a href="<?=e(screen_url('mail'))?>">メール</a>
    </nav>
</div>
</header>
<?php endif; ?>

<main class="container">
<?php
}

function render_footer(): void
{
?>
</main>

<script>
(function(){
    /*
     * 外部通信ボタンはfetchしない。
     * 必ず通常のHTML form POSTを発生させる。
     * これによりNetworkでPOSTを確認できる。
     */
    document.querySelectorAll('form[data-busy-form]')
        .forEach(function(form){
            form.addEventListener('submit', function(){
                var button =
                    form.querySelector('button[type="submit"]');

                if(!button){
                    return;
                }

                if(form.dataset.confirm){
                    if(!window.confirm(form.dataset.confirm)){
                        event.preventDefault();
                        return;
                    }
                }

                button.disabled = true;
                button.dataset.originalText = button.textContent;
                button.textContent = '処理中...';

                /*
                 * サーバー側タイムアウトを待つ間も、
                 * ブラウザ上で処理中であることを明示。
                 */
            });
        });

    /*
     * 共通確認。
     */
    document.querySelectorAll('[data-confirm]')
        .forEach(function(element){
            if(element.tagName === 'FORM'){
                return;
            }

            element.addEventListener('click', function(e){
                var message =
                    element.getAttribute('data-confirm');

                if(message && !window.confirm(message)){
                    e.preventDefault();
                }
            });
        });

    /*
     * 質問・グループのドラッグ&ドロップ。
     * 保存時にはJSONをサーバーへPOSTする。
     */
    var editor = document.getElementById('question-editor');

    if(editor){

        function setupDrag(){
            editor.querySelectorAll('[draggable="true"]')
                .forEach(function(item){

                    item.addEventListener('dragstart', function(){
                        item.classList.add('dragging');
                    });

                    item.addEventListener('dragend', function(){
                        item.classList.remove('dragging');
                    });

                    item.addEventListener('dragover', function(e){
                        e.preventDefault();

                        var dragging =
                            editor.querySelector('.dragging');

                        if(!dragging || dragging === item){
                            return;
                        }

                        var rect =
                            item.getBoundingClientRect();

                        var before =
                            e.clientY < rect.top + rect.height / 2;

                        if(before){
                            item.parentNode.insertBefore(
                                dragging,
                                item
                            );
                        }else{
                            item.parentNode.insertBefore(
                                dragging,
                                item.nextSibling
                            );
                        }
                    });
                });
        }

        setupDrag();

        var addGroup =
            document.getElementById('add-group');

        if(addGroup){
            addGroup.addEventListener('click', function(){

                var group = document.createElement('div');
                group.className = 'group';
                group.draggable = true;

                group.innerHTML =
                    '<div class="group-header">'
                    + '<span class="drag-handle">☷</span>'
                    + '<input class="group-title" '
                    + 'value="新しいグループ">'
                    + '</div>'
                    + '<div class="questions"></div>'
                    + '<button type="button" '
                    + 'class="add-question">'
                    + '質問を追加'
                    + '</button>';

                editor.appendChild(group);

                bindQuestionButton(group);
                setupDrag();
            });
        }

        function bindQuestionButton(group){
            var button =
                group.querySelector('.add-question');

            if(!button){
                return;
            }

            button.addEventListener('click', function(){

                var list =
                    group.querySelector('.questions');

                var q =
                    document.createElement('div');

                q.className = 'question';
                q.draggable = true;

                q.innerHTML =
                    '<div class="drag-handle">☷ 質問</div>'
                    + '<input class="q-text" '
                    + 'placeholder="質問文">'
                    + '<select class="q-type">'
                    + '<option value="single">単一選択</option>'
                    + '<option value="multiple">複数選択</option>'
                    + '<option value="text">自由記述</option>'
                    + '</select>'
                    + '<label>'
                    + '<input type="checkbox" '
                    + 'class="q-required">'
                    + ' 必須'
                    + '</label>'
                    + '<textarea class="q-options" '
                    + 'placeholder="選択肢を1行ずつ"></textarea>'
                    + '<button type="button" '
                    + 'class="danger remove-question">'
                    + '質問を削除'
                    + '</button>';

                list.appendChild(q);

                q.querySelector('.remove-question')
                    .addEventListener('click', function(){
                        if(window.confirm(
                            'この質問を削除しますか？'
                        )){
                            q.remove();
                        }
                    });

                setupDrag();
            });
        }

        editor.querySelectorAll('.group')
            .forEach(bindQuestionButton);

        var saveQuestions =
            document.getElementById('save-questions');

        if(saveQuestions){

            saveQuestions.addEventListener('click', function(){

                var groups = [];

                editor.querySelectorAll(':scope > .group')
                    .forEach(function(group){

                        var g = {
                            id:
                                group.dataset.id
                                || '',
                            title:
                                group.querySelector(
                                    '.group-title'
                                )?.value || '',
                            questions: []
                        };

                        group.querySelectorAll(
                            '.question'
                        ).forEach(function(q){

                            var options =
                                q.querySelector(
                                    '.q-options'
                                );

                            g.questions.push({
                                id: q.dataset.id || '',
                                text:
                                    q.querySelector(
                                        '.q-text'
                                    )?.value || '',
                                type:
                                    q.querySelector(
                                        '.q-type'
                                    )?.value || 'single',
                                required:
                                    !!q.querySelector(
                                        '.q-required'
                                    )?.checked,
                                options:
                                    options
                                    ? options.value
                                        .split(/\r?\n/)
                                        .filter(
                                            function(v){
                                                return v.trim() !== '';
                                            }
                                        )
                                    : []
                            });
                        });

                        groups.push(g);
                    });

                document.getElementById(
                    'question-structure'
                ).value = JSON.stringify(groups);

                document.getElementById(
                    'question-form'
                ).submit();
            });
        }
    }

    /*
     * 状態変更。
     */
    document.querySelectorAll(
        'form[data-status-form]'
    ).forEach(function(form){

        form.addEventListener('submit', function(e){

            var message =
                form.dataset.confirm || '状態を変更しますか？';

            if(!window.confirm(message)){
                e.preventDefault();
            }
        });
    });
})();
</script>

</body>
</html>
<?php
}


/*
|--------------------------------------------------------------------------
| Result
|--------------------------------------------------------------------------
*/

function render_result(array $result): void
{
    $type = ($result['type'] ?? 'error') === 'success'
        ? 'success'
        : 'error';

    ?>
<div class="result <?=$type?>">
    <strong><?=e($result['message'] ?? '')?></strong>

    <?php if (isset($result['detail']) && $result['detail'] !== ''): ?>
        <div class="result-detail">
            <?=e((string)$result['detail'])?>
        </div>
    <?php endif; ?>

    <?php if (isset($result['field_count'])): ?>
        <div class="result-detail">
            取得項目数：<?=e($result['field_count'])?>
        </div>
    <?php endif; ?>

    <?php if (isset($result['customer_count'])): ?>
        <div class="result-detail">
            同期件数：<?=e($result['customer_count'])?>
        </div>
    <?php endif; ?>

    <?php if (isset($result['sent'])): ?>
        <div class="result-detail">
            成功：<?=e($result['sent'])?>
            ／失敗：<?=e($result['failed'] ?? 0)?>
        </div>
    <?php endif; ?>

    <?php if (isset($result['saved_at'])): ?>
        <div class="result-detail">
            保存確認：<?=e($result['saved_at'])?>
        </div>
    <?php endif; ?>
</div>
<?php
}


/*
|--------------------------------------------------------------------------
| List
|--------------------------------------------------------------------------
*/

function render_list(): void
{
    $surveys = read_json(SURVEYS_FILE);

    $keyword =
        trim((string)($_GET['q'] ?? ''));

    $status =
        (string)($_GET['status'] ?? 'all');

    $sort =
        (string)($_GET['sort'] ?? 'updated_desc');

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
        static function(array $a, array $b) use ($sort): int {

            if ($sort === 'answers_desc') {
                return answer_count($b['id'])
                    <=> answer_count($a['id']);
            }

            if ($sort === 'answers_asc') {
                return answer_count($a['id'])
                    <=> answer_count($b['id']);
            }

            $field = match($sort) {
                'created_desc',
                'created_asc' => 'createdAt',
                'start_desc',
                'start_asc' => 'startAt',
                default => 'updatedAt',
            };

            $av = (string)($a[$field] ?? '');
            $bv = (string)($b[$field] ?? '');

            $result = strcmp($av, $bv);

            if (
                in_array(
                    $sort,
                    ['created_asc', 'start_asc'],
                    true
                )
            ) {
                return $result;
            }

            return -$result;
        }
    );
    ?>

<div class="toolbar">
    <div>
        <h1>アンケート一覧</h1>
        <div class="muted">
            アンケートの作成・公開・集計・送信を管理します。
        </div>
    </div>

    <a class="button primary"
       href="<?=e(screen_url('edit'))?>">
        新規作成
    </a>
</div>

<div class="card">

<form method="get">
    <input type="hidden" name="screen" value="list">

    <div class="form-grid">
        <label>検索</label>
        <input
            name="q"
            value="<?=e($keyword)?>"
            placeholder="タイトル部分一致"
        >

        <label>ステータス</label>
        <select name="status">
            <option value="all">すべて</option>
            <option value="published"
                <?=$status === 'published' ? 'selected' : ''?>>
                公開中
            </option>
            <option value="draft"
                <?=$status === 'draft' ? 'selected' : ''?>>
                下書き
            </option>
            <option value="stopped"
                <?=$status === 'stopped' ? 'selected' : ''?>>
                停止
            </option>
            <option value="ended"
                <?=$status === 'ended' ? 'selected' : ''?>>
                終了
            </option>
        </select>

        <label>ソート</label>
        <select name="sort">
            <option value="updated_desc"
                <?=$sort === 'updated_desc' ? 'selected' : ''?>>
                更新日：新しい順
            </option>
            <option value="updated_asc"
                <?=$sort === 'updated_asc' ? 'selected' : ''?>>
                更新日：古い順
            </option>
            <option value="answers_desc"
                <?=$sort === 'answers_desc' ? 'selected' : ''?>>
                回答数：多い順
            </option>
            <option value="answers_asc"
                <?=$sort === 'answers_asc' ? 'selected' : ''?>>
                回答数：少ない順
            </option>
            <option value="start_desc"
                <?=$sort === 'start_desc' ? 'selected' : ''?>>
                開始日：新しい順
            </option>
            <option value="start_asc"
                <?=$sort === 'start_asc' ? 'selected' : ''?>>
                開始日：古い順
            </option>
        </select>
    </div>

    <div class="actions">
        <button class="primary" type="submit">
            検索
        </button>
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
    <th>状態</th>
    <th>回答数</th>
    <th>操作</th>
</tr>
</thead>

<tbody>

<?php if (!$filtered): ?>

<tr>
    <td colspan="7">
        <div class="empty">
            アンケートがありません。
        </div>
    </td>
</tr>

<?php else: ?>

<?php foreach ($filtered as $survey): ?>

<tr>

<td>
    <strong><?=e($survey['title'] ?? '')?></strong>
</td>

<td>
    <?=e(format_datetime($survey['createdAt'] ?? ''))?>
</td>

<td>
    <?=e(format_datetime($survey['updatedAt'] ?? ''))?>
</td>

<td>
    <?=e(format_datetime($survey['startAt'] ?? ''))?>
    〜
    <?=e(format_datetime($survey['endAt'] ?? ''))?>
</td>

<td>
    <?=status_badge((string)($survey['status'] ?? 'draft'))?>
</td>

<td>
    <?=e(answer_count($survey['id']))?>
</td>

<td>

<div class="actions">

<a class="button"
   href="<?=e(screen_url(
       'edit',
       ['id' => $survey['id']]
   ))?>">
    確認・編集
</a>

<a class="button"
   href="<?=e(screen_url(
       'analytics',
       ['id' => $survey['id']]
   ))?>">
    集計
</a>

<a class="button"
   href="<?=e(screen_url(
       'send',
       ['id' => $survey['id']]
   ))?>">
    送信
</a>

<form method="post"
      style="display:inline"
      data-status-form
      data-confirm="このアンケートを複製しますか？">
    <input type="hidden"
           name="action"
           value="duplicate_survey">
    <input type="hidden"
           name="id"
           value="<?=e($survey['id'])?>">
    <button type="submit">
        複製
    </button>
</form>

<form method="post"
      style="display:inline"
      data-status-form
      data-confirm="このアンケートを削除しますか？">
    <input type="hidden"
           name="action"
           value="delete_survey">
    <input type="hidden"
           name="id"
           value="<?=e($survey['id'])?>">
    <button class="danger" type="submit">
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


/*
|--------------------------------------------------------------------------
| Edit
|--------------------------------------------------------------------------
*/

function render_edit(?array $survey): void
{
    $new = $survey === null;

    if ($new) {
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
                    'id' => uid('group-'),
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
        ];
    }

    ?>
<div class="toolbar">
    <div>
        <h1>アンケート作成・編集</h1>
    </div>

    <div class="actions">
        <a class="button"
           href="<?=e(screen_url('list'))?>">
            キャンセル
        </a>

        <?php if (!empty($survey['id'])): ?>
        <a class="button"
           href="<?=e(screen_url(
               'preview',
               ['id' => $survey['id']]
           ))?>">
            プレビュー
        </a>
        <?php endif; ?>

        <button
            form="survey-form"
            class="primary"
            type="submit">
            保存して一覧へ
        </button>
    </div>
</div>

<div class="card">

<form id="survey-form"
      method="post"
      data-busy-form>

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?=e($survey['id'] ?? '')?>">

<div class="form-grid">

<label>アンケートタイトル</label>
<input
    name="title"
    maxlength="200"
    required
    value="<?=e($survey['title'] ?? '')?>"
>

<label>アンケート説明</label>
<textarea
    name="description"
    maxlength="5000"
><?=e($survey['description'] ?? '')?></textarea>

<label>開始日時</label>
<input
    type="datetime-local"
    name="startAt"
    value="<?=e(datetime_local($survey['startAt'] ?? ''))?>"
>

<label>終了日時</label>
<input
    type="datetime-local"
    name="endAt"
    value="<?=e(datetime_local($survey['endAt'] ?? ''))?>"
>

<label>質問番号の採番方式</label>
<select name="numbering">
    <option
        value="global"
        <?=($survey['numbering'] ?? 'global') === 'global'
            ? 'selected'
            : ''?>
    >
        アンケート全体で通番：Q1、Q2、Q3...
    </option>

    <option
        value="group"
        <?=($survey['numbering'] ?? '') === 'group'
            ? 'selected'
            : ''?>
    >
        グループ毎：Q1-1、Q1-2、Q2-1...
    </option>
</select>

<label>状態</label>
<div>
    <?=status_badge(
        (string)($survey['status'] ?? 'draft')
    )?>

    <?php if (($survey['status'] ?? '') !== 'ended'): ?>

    <select
        name="status"
        form="status-form"
        id="status-selector">
        <option value="draft"
            <?=($survey['status'] ?? '') === 'draft'
                ? 'selected' : ''?>>
            下書き
        </option>
        <option value="published"
            <?=($survey['status'] ?? '') === 'published'
                ? 'selected' : ''?>>
            公開中
        </option>
        <option value="stopped"
            <?=($survey['status'] ?? '') === 'stopped'
                ? 'selected' : ''?>>
            停止
        </option>
    </select>

    <?php endif; ?>
</div>

</div>

</form>

<?php if (!empty($survey['id'])): ?>

<form id="status-form"
      method="post"
      data-status-form
      data-confirm="状態を変更しますか？">

<input type="hidden"
       name="action"
       value="change_status">

<input type="hidden"
       name="id"
       value="<?=e($survey['id'])?>">

</form>

<div class="actions">

<?php if (($survey['status'] ?? '') === 'draft'): ?>

<button
    type="submit"
    form="status-form"
    name="status"
    value="published"
    class="success">
    公開
</button>

<?php elseif (($survey['status'] ?? '') === 'published'): ?>

<button
    type="submit"
    form="status-form"
    name="status"
    value="stopped"
    class="warning">
    停止
</button>

<?php elseif (($survey['status'] ?? '') === 'stopped'): ?>

<button
    type="submit"
    form="status-form"
    name="status"
    value="published"
    class="success">
    再開
</button>

<?php endif; ?>

</div>

<?php endif; ?>

</div>

<?php if (!empty($survey['id'])): ?>

<div class="card">
<h2>質問・グループ</h2>

<div id="question-editor">

<?php
render_question_editor(
    (array)($survey['groups'] ?? [])
);
?>

</div>

<div class="actions">
    <button
        type="button"
        id="add-group">
        グループを追加
    </button>

    <button
        type="button"
        id="save-questions"
        class="primary">
        質問構成を保存
    </button>
</div>

<form
    id="question-form"
    method="post">

    <input type="hidden"
           name="action"
           value="save_questions">

    <input type="hidden"
           name="survey_id"
           value="<?=e($survey['id'])?>">

    <input
        type="hidden"
        id="question-structure"
        name="structure"
        value=""
    >

</form>

</div>

<?php endif; ?>

<?php
}

function render_question_editor(array $groups): void
{
    foreach ($groups as $group):
?>
<div class="group"
     draggable="true"
     data-id="<?=e($group['id'] ?? '')?>">

    <div class="group-header">
        <span class="drag-handle">☷</span>

        <input
            class="group-title"
            value="<?=e($group['title'] ?? '')?>"
        >
    </div>

    <div class="questions">

    <?php foreach (
        (array)($group['questions'] ?? [])
        as $question
    ): ?>

    <div class="question"
         draggable="true"
         data-id="<?=e($question['id'] ?? '')?>">

        <div class="drag-handle">
            ☷
            <?=e($question['number'] ?? '')?>
        </div>

        <input
            class="q-text"
            value="<?=e($question['text'] ?? '')?>"
            placeholder="質問文"
        >

        <select class="q-type">
            <option value="single"
                <?=($question['type'] ?? '') === 'single'
                    ? 'selected' : ''?>>
                単一選択
            </option>

            <option value="multiple"
                <?=($question['type'] ?? '') === 'multiple'
                    ? 'selected' : ''?>>
                複数選択
            </option>

            <option value="text"
                <?=($question['type'] ?? '') === 'text'
                    ? 'selected' : ''?>>
                自由記述
            </option>
        </select>

        <label>
            <input
                type="checkbox"
                class="q-required"
                <?=!empty($question['required'])
                    ? 'checked' : ''?>
            >
            必須
        </label>

        <textarea
            class="q-options"
            placeholder="選択肢を1行ずつ"
        ><?=e(implode(
            "\n",
            (array)($question['options'] ?? [])
        ))?></textarea>

        <button
            type="button"
            class="danger remove-question"
            onclick="
                if(confirm('この質問を削除しますか？')){
                    this.closest('.question').remove();
                }
            ">
            質問を削除
        </button>

    </div>

    <?php endforeach; ?>

    </div>

    <button
        type="button"
        class="add-question">
        質問を追加
    </button>

</div>
<?php
    endforeach;
}


/*
|--------------------------------------------------------------------------
| Preview
|--------------------------------------------------------------------------
*/

function render_preview(?array $survey): void
{
    ?>
<div class="toolbar">
    <h1>プレビュー</h1>

    <?php if ($survey): ?>
    <a class="button"
       href="<?=e(screen_url(
           'edit',
           ['id' => $survey['id']]
       ))?>">
        編集へ戻る
    </a>
    <?php endif; ?>
</div>

<div class="card">

<?php if (!$survey): ?>

<div class="empty">
    対象アンケートがありません。
</div>

<?php else: ?>

<h1><?=e($survey['title'] ?? '')?></h1>

<p>
    <?=nl2br(e($survey['description'] ?? ''))?>
</p>

<?php foreach (
    (array)($survey['groups'] ?? [])
    as $group
): ?>

<div class="group">

<h3><?=e($group['title'] ?? '')?></h3>

<?php foreach (
    (array)($group['questions'] ?? [])
    as $q
): ?>

<div class="question">

<strong>
    <?=e($q['number'] ?? '')?>
    <?=e($q['text'] ?? '')?>
</strong>

<?php if (!empty($q['required'])): ?>
<span class="badge published">必須</span>
<?php endif; ?>

<?php if (
    in_array(
        $q['type'] ?? '',
        ['single', 'multiple'],
        true
    )
): ?>

<div class="option-list">

<?php foreach (
    (array)($q['options'] ?? [])
    as $option
): ?>

<div class="answer-choice">
    <input
        type="<?=($q['type'] ?? '') === 'single'
            ? 'radio'
            : 'checkbox'?>"
        disabled
    >
    <span><?=e($option)?></span>
</div>

<?php endforeach; ?>

</div>

<?php else: ?>

<textarea
    disabled
    placeholder="自由記述"
></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>
<?php
}


/*
|--------------------------------------------------------------------------
| kintone screen
|--------------------------------------------------------------------------
*/

function render_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? default_settings()['kintone'];

    ?>
<div class="toolbar">
    <div>
        <h1>kintone連携設定</h1>
        <div class="muted">
            顧客情報の取得元を設定します。
        </div>
    </div>
</div>

<div class="card">

<form method="post"
      data-busy-form>

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="form-grid">

<label>サブドメイン</label>
<input
    name="subdomain"
    value="<?=e($k['subdomain'] ?? '')?>"
    placeholder="xxxx.cybozu.com / xxxx"
    required
>

<label>顧客管理アプリID</label>
<input
    name="app_id"
    inputmode="numeric"
    value="<?=e($k['app_id'] ?? '')?>"
    required
>

<label>ログイン名</label>
<input
    name="username"
    value="<?=e($k['username'] ?? '')?>"
    autocomplete="username"
    required
>

<label>パスワード</label>
<input
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更する場合のみ入力"
>

<label>Proxy</label>
<input
    name="proxy"
    value="<?=e($k['proxy'] ?? '')?>"
    placeholder="host:port"
>

<label>SSL証明書検証</label>
<label>
    <input
        type="checkbox"
        name="verify_ssl"
        value="1"
        <?=!empty($k['verify_ssl'])
            ? 'checked' : ''?>
    >
    有効
</label>

</div>

<div class="actions">
    <button
        type="submit"
        class="primary">
        設定保存
    </button>
</div>

</form>

</div>

<div class="card">

<h2>接続</h2>

<p>
    現在の状態：
    <strong>
        <?=e($k['connection_status'] ?? '未設定')?>
    </strong>
</p>

<?php if (!empty($k['last_test_at'])): ?>
<p class="help">
    最終確認：
    <?=e(format_datetime($k['last_test_at']))?>
</p>
<?php endif; ?>

<div class="actions">

<form method="post"
      data-busy-form>
    <input type="hidden"
           name="action"
           value="test_kintone">

    <button
        type="submit"
        class="primary">
        接続テスト
    </button>
</form>

<form method="post"
      data-busy-form>
    <input type="hidden"
           name="action"
           value="fetch_kintone_fields">

    <button type="submit">
        項目一覧を再取得
    </button>
</form>

<form method="post"
      data-busy-form>
    <input type="hidden"
           name="action"
           value="sync_kintone">

    <button
        type="submit"
        class="success">
        顧客情報を同期
    </button>
</form>

</div>

<p class="help">
    3操作は完全に独立しています。
    接続テストは同期処理を実行しません。
</p>

</div>

<div class="card">

<h2>顧客情報マッピング</h2>

<form method="post"
      data-busy-form>

<input type="hidden"
       name="action"
       value="save_kintone">

<input type="hidden"
       name="subdomain"
       value="<?=e($k['subdomain'] ?? '')?>">

<input type="hidden"
       name="app_id"
       value="<?=e($k['app_id'] ?? '')?>">

<input type="hidden"
       name="username"
       value="<?=e($k['username'] ?? '')?>">

<input type="hidden"
       name="proxy"
       value="<?=e($k['proxy'] ?? '')?>">

<?php
$fields = (array)($k['fields'] ?? []);
$mapping = $k['field_mapping'] ?? [];
?>

<div class="form-grid">

<?php
$mappingItems = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<?php foreach ($mappingItems as $key => $label): ?>

<label><?=e($label)?></label>

<select name="mapping_<?=e($key)?>">
    <option value="">選択してください</option>

    <?php foreach ($fields as $field): ?>

    <option
        value="<?=e($field['code'] ?? '')?>"
        <?=($mapping[$key] ?? '') === ($field['code'] ?? '')
            ? 'selected'
            : ''?>
    >
        <?=e($field['label'] ?? '')?>
        [<?=e($field['code'] ?? '')?>]
    </option>

    <?php endforeach; ?>
</select>

<?php endforeach; ?>

<label>住所</label>

<div>

<?php foreach ($fields as $field): ?>

<label class="answer-choice">
    <input
        type="checkbox"
        name="mapping_address[]"
        value="<?=e($field['code'] ?? '')?>"
        <?=
            in_array(
                $field['code'] ?? '',
                (array)($mapping['address'] ?? []),
                true
            )
                ? 'checked'
                : ''
        ?>
    >
    <?=e($field['label'] ?? '')?>
    [<?=e($field['code'] ?? '')?>]
</label>

<?php endforeach; ?>

</div>

</div>

<div class="actions">
    <button
        type="submit"
        class="primary">
        マッピングを保存
    </button>
</div>

</form>

</div>
<?php
}


/*
|--------------------------------------------------------------------------
| mail screen
|--------------------------------------------------------------------------
*/

function render_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'] ?? default_settings()['mail'];

    ?>
<div class="toolbar">
    <div>
        <h1>メールサーバ設定</h1>
    </div>
</div>

<div class="card">

<form method="post"
      data-busy-form>

<input type="hidden"
       name="action"
       value="save_mail">

<div class="form-grid">

<label>SMTPサーバ</label>
<input
    name="host"
    value="<?=e($m['host'] ?? '')?>"
    required
>

<label>SMTPポート</label>
<input
    type="number"
    name="port"
    min="1"
    max="65535"
    value="<?=e($m['port'] ?? 587)?>"
    required
>

<label>暗号化方式</label>
<select name="encryption">
    <option value="ssl"
        <?=($m['encryption'] ?? '') === 'ssl'
            ? 'selected' : ''?>>
        SSL
    </option>

    <option value="tls"
        <?=($m['encryption'] ?? 'tls') === 'tls'
            ? 'selected' : ''?>>
        TLS
    </option>

    <option value="none"
        <?=($m['encryption'] ?? '') === 'none'
            ? 'selected' : ''?>>
        なし
    </option>
</select>

<label>SMTP認証</label>
<label>
    <input
        type="checkbox"
        name="auth"
        value="1"
        <?=!empty($m['auth']) ? 'checked' : ''?>
    >
    使用する
</label>

<label>SMTPユーザー名</label>
<input
    name="username"
    value="<?=e($m['username'] ?? '')?>"
    autocomplete="username"
>

<label>SMTPパスワード</label>
<input
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更する場合のみ入力"
>

<label>送信元メールアドレス</label>
<input
    type="email"
    name="from_email"
    value="<?=e($m['from_email'] ?? '')?>"
    required
>

<label>送信元名</label>
<input
    name="from_name"
    value="<?=e($m['from_name'] ?? '')?>"
>

<label>返信先メールアドレス</label>
<input
    type="email"
    name="reply_to"
    value="<?=e($m['reply_to'] ?? '')?>"
>

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

<p>
状態：
<strong>
<?=e($m['connection_status'] ?? '未設定')?>
</strong>
</p>

<?php if (!empty($m['last_test_at'])): ?>
<p class="help">
最終確認：
<?=e(format_datetime($m['last_test_at']))?>
</p>
<?php endif; ?>

<form method="post"
      data-busy-form>

<input type="hidden"
       name="action"
       value="test_mail">

<button
    type="submit"
    class="primary">
    接続テスト
</button>

</form>

</div>

<div class="card">

<h2>テストメール</h2>

<form method="post"
      data-busy-form>

<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="form-grid">

<label>送信先</label>

<input
    type="email"
    name="test_to"
    required
>

</div>

<div class="actions">
    <button
        type="submit"
        class="success">
        テストメール送信
    </button>
</div>

</form>

</div>
<?php
}


/*
|--------------------------------------------------------------------------
| Send
|--------------------------------------------------------------------------
*/

function render_send(?array $survey): void
{
    if ($survey === null) {
        return;
    }

    $customers = read_json(CUSTOMERS_FILE);
    $logs = read_json(SEND_LOG_FILE);

    $surveyLogs = array_values(
        array_filter(
            $logs,
            static fn(array $log): bool =>
                ($log['survey_id'] ?? '') === $survey['id']
        )
    );

    ?>
<div class="toolbar">
    <div>
        <h1>顧客選択・メール送信</h1>
        <div class="muted">
            対象アンケート：
            <strong><?=e($survey['title'] ?? '')?></strong>
        </div>
    </div>
</div>

<div class="card">

<form method="post"
      data-busy-form
      data-confirm="選択した顧客へメールを送信しますか？">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?=e($survey['id'])?>">

<div class="table-wrap">

<table>

<thead>
<tr>
    <th>選択</th>
    <th>組織名</th>
    <th>氏名</th>
    <th>メール</th>
    <th>部署</th>
    <th>電話</th>
    <th>住所</th>
</tr>
</thead>

<tbody>

<?php if (!$customers): ?>

<tr>
    <td colspan="7">
        <div class="empty">
            顧客データがありません。
            kintone設定から顧客情報を同期してください。
        </div>
    </td>
</tr>

<?php else: ?>

<?php foreach ($customers as $customer): ?>

<tr>

<td>
<input
    type="checkbox"
    name="customer_ids[]"
    value="<?=e($customer['id'] ?? '')?>"
>
</td>

<td><?=e($customer['organization'] ?? '')?></td>
<td><?=e($customer['name'] ?? '')?></td>
<td><?=e($customer['email'] ?? '')?></td>
<td><?=e($customer['department'] ?? '')?></td>
<td><?=e($customer['phone'] ?? '')?></td>
<td><?=e($customer['address'] ?? '')?></td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

<hr>

<div class="form-grid">

<label>メール件名</label>
<input
    name="subject"
    required
    value="<?=e(
        '【アンケート】'
        . ($survey['title'] ?? '')
    )?>"
>

<label>メール本文</label>
<textarea
    name="body"
    required
> {顧客名} 様

いつもお世話になっております。

以下のアンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>

</div>

<div class="actions">
    <button
        type="submit"
        class="primary">
        一括送信
    </button>
</div>

</form>

</div>

<div class="card">

<h2>送信履歴</h2>

<div class="table-wrap">

<table>

<thead>
<tr>
    <th>日時</th>
    <th>メール</th>
    <th>結果</th>
    <th>種別</th>
</tr>
</thead>

<tbody>

<?php if (!$surveyLogs): ?>

<tr>
    <td colspan="4">
        <div class="empty">
            送信履歴はありません。
        </div>
    </td>
</tr>

<?php else: ?>

<?php foreach (
    array_reverse($surveyLogs)
    as $log
): ?>

<tr>
    <td>
        <?=e(format_datetime(
            $log['createdAt'] ?? ''
        ))?>
    </td>

    <td><?=e($log['email'] ?? '')?></td>

    <td>
        <?php if (($log['status'] ?? '') === 'sent'): ?>
            <span class="badge published">
                送信成功
            </span>
        <?php else: ?>
            <span class="badge ended">
                送信失敗
            </span>
        <?php endif; ?>
    </td>

    <td><?=e($log['mode'] ?? '')?></td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

</div>
<?php
}


/*
|--------------------------------------------------------------------------
| Analytics
|--------------------------------------------------------------------------
*/

function render_analytics(?array $survey): void
{
    if ($survey === null) {
        return;
    }

    $answers = array_values(
        array_filter(
            read_json(ANSWERS_FILE),
            static fn(array $answer): bool =>
                ($answer['survey_id'] ?? '') === $survey['id']
        )
    );

    $customers = read_json(CUSTOMERS_FILE);

    $logs = array_values(
        array_filter(
            read_json(SEND_LOG_FILE),
            static fn(array $log): bool =>
                ($log['survey_id'] ?? '') === $survey['id']
        )
    );

    $sent = count(
        array_filter(
            $logs,
            static fn(array $log): bool =>
                ($log['status'] ?? '') === 'sent'
        )
    );

    $answerCount = count($answers);

    $rate = $sent > 0
        ? round($answerCount / $sent * 100, 1)
        : 0;

    ?>
<div class="toolbar">
    <div>
        <h1>回答集計・分析</h1>
        <div class="muted">
            対象アンケート：
            <strong><?=e($survey['title'] ?? '')?></strong>
        </div>
    </div>

    <div class="actions">

    <form method="post">
        <input type="hidden"
               name="action"
               value="export_csv">

        <input type="hidden"
               name="survey_id"
               value="<?=e($survey['id'])?>">

        <button type="submit">
            CSV出力
        </button>
    </form>

    <form method="post">
        <input type="hidden"
               name="action"
               value="export_pdf">

        <input type="hidden"
               name="survey_id"
               value="<?=e($survey['id'])?>">

        <button type="submit">
            PDF出力
        </button>
    </form>

    </div>
</div>

<div class="stat-grid">

<div class="stat">
    <div class="stat-label">送信対象者数</div>
    <div class="stat-value">
        <?=e($sent)?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">回答数</div>
    <div class="stat-value">
        <?=e($answerCount)?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">未回答数</div>
    <div class="stat-value">
        <?=e(max(0, $sent - $answerCount))?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">回答率</div>
    <div class="stat-value">
        <?=e($rate)?>%
    </div>
</div>

</div>

<div class="card">

<h2>設問別集計</h2>

<?php if ($answerCount === 0): ?>

<div class="empty">
    現在、回答データはありません
</div>

<?php else: ?>

<?php foreach (
    flatten_questions($survey)
    as $question
): ?>

<?php
$counter = [];

foreach ($answers as $answer) {

    $value =
        $answer['answers'][$question['id']]
        ?? '';

    if (is_array($value)) {
        foreach ($value as $v) {
            $counter[$v] =
                ($counter[$v] ?? 0) + 1;
        }
    } else {
        $counter[$value] =
            ($counter[$value] ?? 0) + 1;
    }
}
?>

<div class="question">

<h3>
    <?=e($question['number'] ?? '')?>
    <?=e($question['text'] ?? '')?>
</h3>

<?php if (!$counter): ?>

<div class="muted">
    回答なし
</div>

<?php else: ?>

<?php foreach ($counter as $value => $count): ?>

<div>
    <?=e($value)?>：
    <strong><?=e($count)?></strong>
</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<div class="card">

<h2>個別回答</h2>

<?php foreach ($answers as $answer): ?>

<div class="question">

<div class="small muted">
    <?=e(format_datetime(
        $answer['createdAt'] ?? ''
    ))?>
</div>

<?php foreach (
    flatten_questions($survey)
    as $question
): ?>

<?php
$value =
    $answer['answers'][$question['id']]
    ?? '';

if (is_array($value)) {
    $value = implode(', ', $value);
}
?>

<p>
<strong>
<?=e($question['number'] ?? '')?>
<?=e($question['text'] ?? '')?>
</strong><br>
<?=nl2br(e($value))?>
</p>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>
<?php
}


/*
|--------------------------------------------------------------------------
| Answerer
|--------------------------------------------------------------------------
*/

function render_answer(?array $survey): void
{
    ?>
<div class="answer-card">

<div class="card">

<?php if ($survey === null): ?>

<div class="empty">
    アンケートが見つかりません。
</div>

<?php else: ?>

<h1><?=e($survey['title'] ?? '')?></h1>

<p>
<?=nl2br(e($survey['description'] ?? ''))?>
</p>

<form method="post"
      data-busy-form>

<input type="hidden"
       name="action"
       value="answer_submit">

<input type="hidden"
       name="survey_id"
       value="<?=e($survey['id'])?>">

<?php foreach (
    (array)($survey['groups'] ?? [])
    as $group
): ?>

<div class="group">

<h2><?=e($group['title'] ?? '')?></h2>

<?php foreach (
    (array)($group['questions'] ?? [])
    as $q
): ?>

<div class="question">

<h3>
    <?=e($q['number'] ?? '')?>
    <?=e($q['text'] ?? '')?>

    <?php if (!empty($q['required'])): ?>
        <span class="badge ended">必須</span>
    <?php endif; ?>
</h3>

<?php if (($q['type'] ?? '') === 'single'): ?>

<?php foreach (
    (array)($q['options'] ?? [])
    as $option
): ?>

<label class="answer-choice">
    <input
        type="radio"
        name="answer[<?=e($q['id'])?>]"
        value="<?=e($option)?>"
        <?=!empty($q['required'])
            ? 'required'
            : ''?>
    >
    <span><?=e($option)?></span>
</label>

<?php endforeach; ?>

<?php elseif (($q['type'] ?? '') === 'multiple'): ?>

<?php foreach (
    (array)($q['options'] ?? [])
    as $option
): ?>

<label class="answer-choice">
    <input
        type="checkbox"
        name="answer[<?=e($q['id'])?>][]"
        value="<?=e($option)?>"
    >
    <span><?=e($option)?></span>
</label>

<?php endforeach; ?>

<?php else: ?>

<textarea
    name="answer[<?=e($q['id'])?>]"
    <?=!empty($q['required'])
        ? 'required'
        : ''?>
></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="actions">
    <button
        type="submit"
        class="primary">
        回答確認
    </button>
</div>

</form>

<?php endif; ?>

</div>

</div>
<?php
}

function render_confirm(?array $survey): void
{
    ?>
<div class="answer-card">

<div class="card">

<h1>回答確認</h1>

<?php if ($survey === null): ?>

<div class="empty">
    アンケートが見つかりません。
</div>

<?php else: ?>

<p>
以下の内容で回答します。
</p>

<?php
$draft =
    $_SESSION[
        'answer_draft_' . $survey['id']
    ] ?? [];
?>

<?php foreach (
    flatten_questions($survey)
    as $question
): ?>

<?php
$value =
    $draft[$question['id']] ?? '';

if (is_array($value)) {
    $value = implode(', ', $value);
}
?>

<div class="question">

<strong>
<?=e($question['number'] ?? '')?>
<?=e($question['text'] ?? '')?>
</strong>

<p>
<?=nl2br(e($value))?>
</p>

</div>

<?php endforeach; ?>

<form method="post"
      action="<?=e(screen_url(
          'answer',
          ['id' => $survey['id']]
      ))?>">
    <button type="submit">
        修正する
    </button>
</form>

<form method="post"
      data-busy-form
      data-confirm="回答を送信しますか？">

<input type="hidden"
       name="action"
       value="answer_submit">

<input type="hidden"
       name="survey_id"
       value="<?=e($survey['id'])?>">

<?php foreach ($draft as $qid => $value): ?>

<?php if (is_array($value)): ?>

<?php foreach ($value as $v): ?>

<input
    type="hidden"
    name="answer[<?=e($qid)?>][]"
    value="<?=e($v)?>"
>

<?php endforeach; ?>

<?php else: ?>

<input
    type="hidden"
    name="answer[<?=e($qid)?>]"
    value="<?=e($value)?>"
>

<?php endif; ?>

<?php endforeach; ?>

<div class="actions">
    <button
        class="primary"
        type="submit">
        回答を送信する
    </button>
</div>

</form>

<?php endif; ?>

</div>

</div>
<?php
}

function render_complete(?array $survey): void
{
    ?>
<div class="answer-card">

<div class="card">

<h1>回答完了</h1>

<p>
ご回答ありがとうございました。
</p>

<p>
回答は正常に受け付けられました。
</p>

</div>

</div>
<?php
}


/*
|--------------------------------------------------------------------------
| Utilities for screens
|--------------------------------------------------------------------------
*/

function flatten_questions(array $survey): array
{
    $result = [];

    foreach (
        (array)($survey['groups'] ?? [])
        as $group
    ) {
        foreach (
            (array)($group['questions'] ?? [])
            as $question
        ) {
            $result[] = $question;
        }
    }

    return $result;
}

function answer_count(string $surveyId): int
{
    $count = 0;

    foreach (read_json(ANSWERS_FILE) as $answer) {
        if (($answer['survey_id'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
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

    return
        '<span class="badge '
        . e($status)
        . '">'
        . e($label)
        . '</span>';
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

    return date('Y/m/d H:i', $time);
}

function datetime_local(string $value): string
{
    if ($value === '') {
        return '';
    }

    $time = strtotime($value);

    if ($time === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $time);
}
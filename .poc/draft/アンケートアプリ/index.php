<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * PHP 8.5 / Apache 2.4 / cURL
 * DB不使用
 *
 * 単一エントリーポイント:
 *   ?screen=list
 *   ?screen=edit&id=survey-xxx
 *   ?screen=preview&id=survey-xxx
 *   ?screen=send&id=survey-xxx
 *   ?screen=analytics&id=survey-xxx
 *   ?screen=kintone
 *   ?screen=mail
 *   ?screen=answer&id=survey-xxx
 *   ?screen=confirm&id=survey-xxx
 *   ?screen=complete&id=survey-xxx
 *
 * 重要:
 * - CSRF機構は要件上実装しない
 * - 管理者認証はPOCでは実装しない
 * - kintone APIトークンは使用しない
 * - X-Cybozu-Authorizationはサーバー側だけで生成
 * - PHP mail()は使用しない
 */

date_default_timezone_set('Asia/Tokyo');

const APP_NAME = 'アンケート管理';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'survey_data';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT    = 20;
const SMTP_CONNECT_TIMEOUT    = 10;
const SMTP_READ_TIMEOUT       = 20;

const STATUS_DRAFT     = 'draft';
const STATUS_PUBLISHED = 'published';
const STATUS_STOPPED   = 'stopped';
const STATUS_ENDED     = 'ended';

/* ============================================================
 * 初期化
 * ============================================================ */

init_session();
ensure_data_dir();

if (!isset($_SESSION['answer_state']) || !is_array($_SESSION['answer_state'])) {
    $_SESSION['answer_state'] = [];
}

$screen = normalize_screen($_GET['screen'] ?? 'list');

$operationResult = null;

/*
 * POST処理
 *
 * 設定保存等について、
 * POST -> 303 -> GET -> flash という方式には依存しない。
 * POST処理後、そのまま同一リクエストで結果を表示する。
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $operationResult = handle_post();
}

/*
 * 公開中アンケートの終了状態を自動判定。
 */
auto_end_surveys();

/* ============================================================
 * セッション
 * ============================================================ */

function init_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    if ($path === DIRECTORY_SEPARATOR || $path === '.' || $path === '') {
        $path = '/';
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $path,
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できませんでした。');
    }
}

/* ============================================================
 * ファイル永続化
 * ============================================================ */

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            http_response_code(500);
            exit('データ保存ディレクトリを作成できません。');
        }
    }

    $files = [
        'surveys.dat',
        'answers.dat',
        'customers.dat',
        'send_history.dat',
        'settings.dat',
    ];

    foreach ($files as $file) {
        $path = DATA_DIR . DIRECTORY_SEPARATOR . $file;

        if (!file_exists($path)) {
            atomic_write($path, serialize([]));
        }
    }
}

function atomic_write(string $path, string $contents): bool
{
    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        return false;
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function read_store(string $name): array
{
    $allowed = [
        'surveys',
        'answers',
        'customers',
        'send_history',
        'settings',
    ];

    if (!in_array($name, $allowed, true)) {
        return [];
    }

    $path = DATA_DIR . DIRECTORY_SEPARATOR . $name . '.dat';

    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);

    if ($raw === false || $raw === '') {
        return [];
    }

    $data = @unserialize($raw, ['allowed_classes' => false]);

    return is_array($data) ? $data : [];
}

function write_store(string $name, array $data): bool
{
    $allowed = [
        'surveys',
        'answers',
        'customers',
        'send_history',
        'settings',
    ];

    if (!in_array($name, $allowed, true)) {
        return false;
    }

    $path = DATA_DIR . DIRECTORY_SEPARATOR . $name . '.dat';

    return atomic_write($path, serialize($data));
}

/* ============================================================
 * 共通関数
 * ============================================================ */

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
    return date('Y-m-d\TH:i:s');
}

function normalize_screen(string $screen): string
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

    return in_array($screen, $allowed, true) ? $screen : 'list';
}

function redirect_screen(string $screen, ?string $id = null): void
{
    $params = ['screen' => $screen];

    if ($id !== null && $id !== '') {
        $params['id'] = $id;
    }

    $url = basename($_SERVER['PHP_SELF']) . '?' . http_build_query($params);

    header('Location: ' . $url);
    exit;
}

function result(string $type, string $message): array
{
    return [
        'type' => $type,
        'message' => $message,
    ];
}

function valid_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id);
}

function clean_text(mixed $value, int $max = 5000): string
{
    $value = trim((string)$value);

    if (mb_strlen($value, 'UTF-8') > $max) {
        $value = mb_substr($value, 0, $max, 'UTF-8');
    }

    return $value;
}

function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function valid_datetime(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);

    return $dt instanceof DateTime && $dt->format('Y-m-d\TH:i') === $value;
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    if ($ts === false) {
        return e($value);
    }

    return date('Y/m/d H:i', $ts);
}

function status_label(string $status): string
{
    return match ($status) {
        STATUS_DRAFT     => '下書き',
        STATUS_PUBLISHED => '公開中',
        STATUS_STOPPED   => '停止',
        STATUS_ENDED     => '終了',
        default          => $status,
    };
}

function status_class(string $status): string
{
    return match ($status) {
        STATUS_PUBLISHED => 'status-published',
        STATUS_STOPPED   => 'status-stopped',
        STATUS_ENDED     => 'status-ended',
        default          => 'status-draft',
    };
}

/* ============================================================
 * アンケート
 * ============================================================ */

function default_survey(): array
{
    return [
        'id' => 'survey-' . bin2hex(random_bytes(6)),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => STATUS_DRAFT,
        'numbering' => 'global',
        'createdAt' => now_iso(),
        'updatedAt' => now_iso(),
        'groups' => [
            [
                'id' => 'group-' . bin2hex(random_bytes(5)),
                'title' => 'グループ1',
                'questions' => [
                    [
                        'id' => 'question-' . bin2hex(random_bytes(5)),
                        'text' => '',
                        'type' => 'single',
                        'required' => false,
                        'options' => ['選択肢1', '選択肢2'],
                        'branches' => [],
                    ],
                ],
            ],
        ],
    ];
}

function get_survey(string $id): ?array
{
    if (!valid_id($id)) {
        return null;
    }

    $surveys = read_store('surveys');

    if (!isset($surveys[$id]) || !is_array($surveys[$id])) {
        return null;
    }

    $survey = $surveys[$id];

    if (
        ($survey['status'] ?? '') === STATUS_PUBLISHED
        && !empty($survey['endAt'])
        && strtotime((string)$survey['endAt']) !== false
        && strtotime((string)$survey['endAt']) < time()
    ) {
        $survey['status'] = STATUS_ENDED;
        $survey['updatedAt'] = now_iso();

        $surveys[$id] = $survey;
        write_store('surveys', $surveys);
    }

    return $survey;
}

function save_survey(array $survey): bool
{
    if (
        empty($survey['id'])
        || !valid_id((string)$survey['id'])
    ) {
        return false;
    }

    $surveys = read_store('surveys');
    $surveys[$survey['id']] = $survey;

    return write_store('surveys', $surveys);
}

function auto_end_surveys(): void
{
    $surveys = read_store('surveys');
    $changed = false;

    foreach ($surveys as $id => &$survey) {
        if (!is_array($survey)) {
            continue;
        }

        if (
            ($survey['status'] ?? '') === STATUS_PUBLISHED
            && !empty($survey['endAt'])
            && strtotime((string)$survey['endAt']) !== false
            && strtotime((string)$survey['endAt']) < time()
        ) {
            $survey['status'] = STATUS_ENDED;
            $survey['updatedAt'] = now_iso();
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        write_store('surveys', $surveys);
    }
}

function renumber_questions(array &$survey): void
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

/* ============================================================
 * POST処理
 * ============================================================ */

function handle_post(): ?array
{
    $action = (string)($_POST['action'] ?? '');

    return match ($action) {
        'save_survey'       => post_save_survey(),
        'delete_survey'     => post_delete_survey(),
        'duplicate_survey'  => post_duplicate_survey(),
        'change_status'     => post_change_status(),
        'save_kintone'      => post_save_kintone(),
        'test_kintone'      => post_test_kintone(),
        'sync_kintone'      => post_sync_kintone(),
        'save_mail'         => post_save_mail(),
        'test_mail'         => post_test_mail(),
        'send_mail'         => post_send_mail(),
        'save_answer'       => post_save_answer(),
        'submit_answer'     => post_submit_answer(),
        default             => result('error', '不正な操作です。'),
    };
}

/* ============================================================
 * アンケート保存
 * ============================================================ */

function post_save_survey(): array
{
    $id = clean_text($_POST['id'] ?? '', 80);

    $survey = $id !== '' ? get_survey($id) : null;

    if ($survey === null) {
        $survey = default_survey();
    }

    $title = clean_text($_POST['title'] ?? '', 200);
    $description = clean_text($_POST['description'] ?? '', 5000);
    $startAt = clean_text($_POST['startAt'] ?? '', 30);
    $endAt = clean_text($_POST['endAt'] ?? '', 30);
    $numbering = clean_text($_POST['numbering'] ?? 'global', 20);

    if ($title === '') {
        return result('error', 'アンケートタイトルを入力してください。');
    }

    if (!valid_datetime($startAt) || !valid_datetime($endAt)) {
        return result('error', '日時の形式が正しくありません。');
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) > strtotime($endAt)
    ) {
        return result('error', '終了日時は開始日時より後にしてください。');
    }

    if (!in_array($numbering, ['global', 'group'], true)) {
        $numbering = 'global';
    }

    $survey['title'] = $title;
    $survey['description'] = $description;
    $survey['startAt'] = $startAt;
    $survey['endAt'] = $endAt;
    $survey['numbering'] = $numbering;
    $survey['updatedAt'] = now_iso();

    if (!isset($survey['status'])) {
        $survey['status'] = STATUS_DRAFT;
    }

    /*
     * 編集画面から送信されたグループ・質問情報。
     */
    $groupsJson = $_POST['groups_json'] ?? '';

    if ($groupsJson !== '') {
        $groups = json_decode($groupsJson, true);

        if (is_array($groups)) {
            $survey['groups'] = normalize_groups($groups);
        }
    }

    renumber_questions($survey);

    if (!save_survey($survey)) {
        return result('error', 'アンケートを保存できませんでした。');
    }

    redirect_screen('list');
    return result('success', '保存しました。');
}

function normalize_groups(array $groups): array
{
    $result = [];

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $groupId = clean_text($group['id'] ?? '', 80);

        if (!valid_id($groupId)) {
            $groupId = 'group-' . bin2hex(random_bytes(5));
        }

        $title = clean_text($group['title'] ?? '', 200);

        if ($title === '') {
            $title = 'グループ';
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $question) {
            if (!is_array($question)) {
                continue;
            }

            $questionId = clean_text($question['id'] ?? '', 80);

            if (!valid_id($questionId)) {
                $questionId = 'question-' . bin2hex(random_bytes(5));
            }

            $type = (string)($question['type'] ?? 'single');

            if (!in_array($type, ['single', 'multiple', 'text'], true)) {
                $type = 'single';
            }

            $options = [];

            foreach (($question['options'] ?? []) as $option) {
                $option = clean_text($option, 300);

                if ($option !== '') {
                    $options[] = $option;
                }
            }

            if ($type !== 'text' && count($options) === 0) {
                $options = ['選択肢1', '選択肢2'];
            }

            $branches = [];

            foreach (($question['branches'] ?? []) as $branch) {
                if (!is_array($branch)) {
                    continue;
                }

                $branches[] = [
                    'option' => clean_text($branch['option'] ?? '', 300),
                    'questionId' => clean_text($branch['questionId'] ?? '', 80),
                ];
            }

            $questions[] = [
                'id' => $questionId,
                'text' => clean_text($question['text'] ?? '', 2000),
                'type' => $type,
                'required' => !empty($question['required']),
                'options' => $options,
                'branches' => $branches,
            ];
        }

        $result[] = [
            'id' => $groupId,
            'title' => $title,
            'questions' => $questions,
        ];
    }

    if (count($result) === 0) {
        $result[] = [
            'id' => 'group-' . bin2hex(random_bytes(5)),
            'title' => 'グループ1',
            'questions' => [],
        ];
    }

    return $result;
}

/* ============================================================
 * 削除
 * ============================================================ */

function post_delete_survey(): array
{
    $id = clean_text($_POST['id'] ?? '', 80);

    if (!valid_id($id)) {
        return result('error', 'アンケートIDが不正です。');
    }

    $surveys = read_store('surveys');

    if (!isset($surveys[$id])) {
        return result('error', 'アンケートが存在しません。');
    }

    unset($surveys[$id]);

    if (!write_store('surveys', $surveys)) {
        return result('error', '削除に失敗しました。');
    }

    redirect_screen('list');
    return result('success', '削除しました。');
}

function post_duplicate_survey(): array
{
    $id = clean_text($_POST['id'] ?? '', 80);
    $survey = get_survey($id);

    if ($survey === null) {
        return result('error', '複製元アンケートが存在しません。');
    }

    $new = $survey;
    $new['id'] = 'survey-' . bin2hex(random_bytes(6));
    $new['title'] = ($survey['title'] ?? '') . '（コピー）';
    $new['status'] = STATUS_DRAFT;
    $new['createdAt'] = now_iso();
    $new['updatedAt'] = now_iso();

    if (!save_survey($new)) {
        return result('error', '複製に失敗しました。');
    }

    redirect_screen('list');
    return result('success', '複製しました。');
}

/* ============================================================
 * 状態変更
 * ============================================================ */

function post_change_status(): array
{
    $id = clean_text($_POST['id'] ?? '', 80);
    $next = clean_text($_POST['next_status'] ?? '', 30);

    $survey = get_survey($id);

    if ($survey === null) {
        return result('error', 'アンケートが存在しません。');
    }

    $current = $survey['status'] ?? STATUS_DRAFT;

    if ($current === STATUS_ENDED) {
        return result('error', '終了したアンケートは状態変更できません。');
    }

    $allowed = [
        STATUS_DRAFT => [STATUS_PUBLISHED],
        STATUS_PUBLISHED => [STATUS_STOPPED],
        STATUS_STOPPED => [STATUS_PUBLISHED],
    ];

    if (!isset($allowed[$current]) || !in_array($next, $allowed[$current], true)) {
        return result('error', '許可されていない状態変更です。');
    }

    $survey['status'] = $next;
    $survey['updatedAt'] = now_iso();

    if (!save_survey($survey)) {
        return result('error', '状態変更に失敗しました。');
    }

    return result('success', '状態を変更しました。');
}

/* ============================================================
 * kintone設定
 * ============================================================ */

function get_kintone_config(): array
{
    $settings = read_store('settings');
    $config = $settings['kintone'] ?? [];

    return is_array($config) ? $config : [];
}

function post_save_kintone(): array
{
    $subdomain = clean_text($_POST['subdomain'] ?? '', 255);
    $appId = clean_text($_POST['app_id'] ?? '', 30);
    $loginName = clean_text($_POST['login_name'] ?? '', 255);
    $password = (string)($_POST['password'] ?? '');
    $proxy = clean_text($_POST['proxy'] ?? '', 255);
    $verifySsl = isset($_POST['verify_ssl']);

    if ($subdomain === '') {
        return result('error', 'kintoneサブドメインを入力してください。');
    }

    $host = normalize_kintone_host($subdomain);

    if ($host === '') {
        return result('error', 'kintoneサブドメインが不正です。');
    }

    if (!ctype_digit($appId) || (int)$appId <= 0) {
        return result('error', '顧客管理アプリIDが不正です。');
    }

    if ($loginName === '') {
        return result('error', 'ログイン名を入力してください。');
    }

    $old = get_kintone_config();

    /*
     * パスワード未入力の場合は既存値を維持。
     * ブラウザに既存パスワードを返さない。
     */
    if ($password === '') {
        $password = (string)($old['password'] ?? '');
    }

    if ($password === '') {
        return result('error', 'パスワードを入力してください。');
    }

    if ($proxy !== '' && !valid_proxy($proxy)) {
        return result('error', 'Proxyは host:port 形式で入力してください。');
    }

    $settings = read_store('settings');

    $settings['kintone'] = [
        'host' => $host,
        'app_id' => (int)$appId,
        'login_name' => $loginName,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' => $verifySsl,
        'updated_at' => now_iso(),
    ];

    if (!write_store('settings', $settings)) {
        return result('error', 'kintone設定を保存できませんでした。');
    }

    return result('success', 'kintone設定を保存しました。');
}

function normalize_kintone_host(string $value): string
{
    $value = trim($value);
    $value = preg_replace('#^https?://#i', '', $value);
    $value = trim($value, "/ \t\r\n");

    /*
     * xxxx.cybozu.com または xxxx を許可。
     */
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*\.cybozu\.com$/i', $value)) {
        return strtolower($value);
    }

    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $value)) {
        return strtolower($value . '.cybozu.com');
    }

    return '';
}

function valid_proxy(string $proxy): bool
{
    return (bool)preg_match(
        '/^[A-Za-z0-9._-]+:\d{1,5}$/',
        $proxy
    );
}

/* ============================================================
 * kintone通信
 * ============================================================ */

function kintone_authorization(string $loginName, string $password): string
{
    /*
     * kintoneのX-Cybozu-Authorization:
     * Base64(username:password)
     *
     * この値はこの関数内で生成し、
     * ブラウザ・ログ・エラー表示には渡さない。
     */
    return base64_encode($loginName . ':' . $password);
}

function kintone_request(
    string $method,
    string $path,
    ?array $query = null
): array {
    $config = get_kintone_config();

    if (
        empty($config['host'])
        || empty($config['app_id'])
        || empty($config['login_name'])
        || !isset($config['password'])
    ) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'kintone設定が未完了です。',
            'data' => null,
        ];
    }

    $host = (string)$config['host'];
    $loginName = (string)$config['login_name'];
    $password = (string)$config['password'];

    /*
     * 認証情報はURLに入れない。
     */
    $url = 'https://' . $host . '/k/v1/' . ltrim($path, '/');

    if (is_array($query) && count($query) > 0) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init();

    if ($ch === false) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'cURLを初期化できません。',
            'data' => null,
        ];
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Cybozu-Authorization: ' .
            kintone_authorization($loginName, $password),
    ];

    $verifySsl = !empty($config['verify_ssl']);

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => KINTONE_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => KINTONE_READ_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ];

    if (!empty($config['proxy'])) {
        [$proxyHost, $proxyPort] = explode(':', $config['proxy'], 2);

        $options[CURLOPT_PROXY] = $proxyHost;
        $options[CURLOPT_PROXYPORT] = (int)$proxyPort;
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $curlNo = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'kintone通信エラー: ' . $curlError .
                ' (cURL ' . $curlNo . ')',
            'data' => null,
        ];
    }

    $data = json_decode($body, true);

    if ($status < 200 || $status >= 300) {
        /*
         * kintoneのエラー本文は原因特定に必要なため、
         * 認証情報を含まない範囲で返す。
         */
        $detail = '';

        if (is_array($data)) {
            $detail = clean_text(
                $data['message']
                    ?? $data['errors']['message']
                    ?? '',
                1000
            );
        }

        return [
            'ok' => false,
            'status' => $status,
            'error' => $detail !== ''
                ? 'kintone APIエラー: ' . $detail
                : 'kintone APIがHTTP ' . $status . 'を返しました。',
            'data' => $data,
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'error' => '',
        'data' => $data,
    ];
}

function post_test_kintone(): array
{
    /*
     * 接続テストは「実際のkintone」に対して行う。
     * 同期処理はここでは実施しない。
     *
     * app.json?id=123 を使用。
     */
    $config = get_kintone_config();

    if (empty($config['app_id'])) {
        return result('error', 'kintone設定を保存してから接続テストを実行してください。');
    }

    $response = kintone_request(
        'GET',
        'app.json',
        ['id' => (int)$config['app_id']]
    );

    if (!$response['ok']) {
        return result(
            'error',
            'kintone接続テスト失敗。HTTP ' .
            (int)$response['status'] .
            ' / ' .
            $response['error']
        );
    }

    return result('success', 'kintone接続テスト成功。アプリへのアクセスを確認しました。');
}

function post_sync_kintone(): array
{
    $config = get_kintone_config();

    if (empty($config['app_id'])) {
        return result('error', 'kintone設定が未完了です。');
    }

    /*
     * 顧客データ取得。
     * フィールドコードは環境差があるため、
     * 項目一覧取得後に設定画面でマッピング可能にする。
     */
    $response = kintone_request(
        'GET',
        'records.json',
        [
            'app' => (int)$config['app_id'],
            'totalCount' => 'true',
            'size' => 500,
        ]
    );

    if (!$response['ok']) {
        return result(
            'error',
            '顧客情報の同期に失敗しました。HTTP ' .
            (int)$response['status'] .
            ' / ' .
            $response['error']
        );
    }

    $records = $response['data']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => clean_text(
                $record['$id']['value']
                    ?? $record['id']['value']
                    ?? '',
                100
            ),
            'organization' => extract_kintone_value(
                $record,
                ['組織名', 'organization', 'company']
            ),
            'name' => extract_kintone_value(
                $record,
                ['氏名', 'name', 'customer_name']
            ),
            'email' => extract_kintone_value(
                $record,
                ['メールアドレス', 'email', 'mail']
            ),
            'department' => extract_kintone_value(
                $record,
                ['部署名', 'department']
            ),
            'phone' => extract_kintone_value(
                $record,
                ['電話番号', 'phone', 'tel']
            ),
            'address' => extract_kintone_value(
                $record,
                ['住所', 'address']
            ),
            'raw' => $record,
            'synced_at' => now_iso(),
        ];
    }

    if (!write_store('customers', $customers)) {
        return result('error', '顧客情報の保存に失敗しました。');
    }

    return result(
        'success',
        count($customers) . '件の顧客情報を同期しました。'
    );
}

function extract_kintone_value(array $record, array $keys): string
{
    foreach ($keys as $key) {
        if (
            isset($record[$key])
            && is_array($record[$key])
            && array_key_exists('value', $record[$key])
        ) {
            $value = $record[$key]['value'];

            if (is_scalar($value)) {
                return clean_text($value, 1000);
            }
        }
    }

    return '';
}

/* ============================================================
 * SMTP
 * ============================================================ */

function get_mail_config(): array
{
    $settings = read_store('settings');
    $config = $settings['mail'] ?? [];

    return is_array($config) ? $config : [];
}

function post_save_mail(): array
{
    $server = clean_text($_POST['smtp_server'] ?? '', 255);
    $port = clean_text($_POST['smtp_port'] ?? '', 10);
    $encryption = clean_text($_POST['encryption'] ?? 'tls', 20);
    $auth = !empty($_POST['smtp_auth']);
    $username = clean_text($_POST['smtp_username'] ?? '', 255);
    $password = (string)($_POST['smtp_password'] ?? '');
    $from = clean_text($_POST['from_email'] ?? '', 255);
    $fromName = clean_text($_POST['from_name'] ?? '', 200);
    $replyTo = clean_text($_POST['reply_to'] ?? '', 255);

    if ($server === '') {
        return result('error', 'SMTPサーバを入力してください。');
    }

    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        return result('error', 'SMTPポートが不正です。');
    }

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        return result('error', '暗号化方式が不正です。');
    }

    if (!valid_email($from)) {
        return result('error', '送信元メールアドレスが不正です。');
    }

    if ($replyTo !== '' && !valid_email($replyTo)) {
        return result('error', '返信先メールアドレスが不正です。');
    }

    $old = get_mail_config();

    if ($password === '') {
        $password = (string)($old['password'] ?? '');
    }

    if ($auth && ($username === '' || $password === '')) {
        return result('error', 'SMTP認証を使用する場合はユーザー名とパスワードが必要です。');
    }

    $settings = read_store('settings');

    $settings['mail'] = [
        'server' => $server,
        'port' => (int)$port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'from_email' => $from,
        'from_name' => $fromName,
        'reply_to' => $replyTo,
        'updated_at' => now_iso(),
    ];

    if (!write_store('settings', $settings)) {
        return result('error', 'メール設定を保存できませんでした。');
    }

    return result('success', 'メール設定を保存しました。');
}

/*
 * SMTPコマンド送受信。
 */
function smtp_read($socket): array
{
    $start = microtime(true);
    $data = '';

    while (!feof($socket)) {
        $line = fgets($socket, 4096);

        if ($line === false) {
            break;
        }

        $data .= $line;

        /*
         * SMTP multiline response:
         * 250-xxxx
         * 250 xxxx
         */
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }

        if ((microtime(true) - $start) > SMTP_READ_TIMEOUT) {
            break;
        }
    }

    $code = 0;

    if (preg_match('/^(\d{3})/m', $data, $m)) {
        $code = (int)$m[1];
    }

    return [
        'code' => $code,
        'data' => $data,
    ];
}

function smtp_command($socket, string $command, array $expected): array
{
    fwrite($socket, $command . "\r\n");

    $response = smtp_read($socket);

    return [
        'ok' => in_array($response['code'], $expected, true),
        'response' => $response,
    ];
}

function smtp_connect_test(array $config): array
{
    $server = (string)($config['server'] ?? '');
    $port = (int)($config['port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'none');

    if ($server === '' || $port <= 0) {
        return [
            'ok' => false,
            'message' => 'SMTP設定が未完了です。',
        ];
    }

    $target = $server;

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $server;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target . ':' . $port,
        $errno,
        $errstr,
        SMTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return [
            'ok' => false,
            'message' => 'SMTP接続失敗: ' . $errstr,
        ];
    }

    stream_set_timeout($socket, SMTP_READ_TIMEOUT);

    $greeting = smtp_read($socket);

    if (!in_array($greeting['code'], [220], true)) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTP greeting error: ' . $greeting['code'],
        ];
    }

    $hostName = $_SERVER['SERVER_NAME'] ?? 'localhost';

    $ehlo = smtp_command(
        $socket,
        'EHLO ' . preg_replace('/[^A-Za-z0-9._-]/', '', $hostName),
        [250]
    );

    if (!$ehlo['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'EHLOに失敗しました。',
        ];
    }

    /*
     * STARTTLS
     */
    if ($encryption === 'tls') {
        $tls = smtp_command($socket, 'STARTTLS', [220]);

        if (!$tls['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'STARTTLSに失敗しました。',
            ];
        }

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'TLS暗号化を開始できませんでした。',
            ];
        }

        $ehlo = smtp_command(
            $socket,
            'EHLO ' . preg_replace('/[^A-Za-z0-9._-]/', '', $hostName),
            [250]
        );

        if (!$ehlo['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'TLS後のEHLOに失敗しました。',
            ];
        }
    }

    /*
     * SMTP AUTH
     */
    if (!empty($config['auth'])) {
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');

        $auth = smtp_command($socket, 'AUTH LOGIN', [334]);

        if (!$auth['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTP AUTH LOGINを開始できませんでした。',
            ];
        }

        $auth = smtp_command(
            $socket,
            base64_encode($username),
            [334]
        );

        if (!$auth['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTPユーザー名認証に失敗しました。',
            ];
        }

        $auth = smtp_command(
            $socket,
            base64_encode($password),
            [235]
        );

        if (!$auth['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTPパスワード認証に失敗しました。',
            ];
        }
    }

    smtp_command($socket, 'QUIT', [221]);

    fclose($socket);

    return [
        'ok' => true,
        'message' => 'SMTP接続を確認しました。',
    ];
}

function post_test_mail(): array
{
    $config = get_mail_config();

    $test = smtp_connect_test($config);

    if (!$test['ok']) {
        return result('error', $test['message']);
    }

    return result('success', $test['message']);
}

/*
 * SMTPによる実メール送信。
 */
function smtp_send_mail(
    array $config,
    string $to,
    string $subject,
    string $body
): array {
    $server = (string)($config['server'] ?? '');
    $port = (int)($config['port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'none');

    $from = (string)($config['from_email'] ?? '');
    $fromName = (string)($config['from_name'] ?? '');
    $replyTo = (string)($config['reply_to'] ?? '');

    if (
        $server === ''
        || $port <= 0
        || !valid_email($from)
        || !valid_email($to)
    ) {
        return [
            'ok' => false,
            'message' => 'SMTP送信設定が不正です。',
        ];
    }

    $target = $encryption === 'ssl'
        ? 'ssl://' . $server
        : $server;

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target . ':' . $port,
        $errno,
        $errstr,
        SMTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return [
            'ok' => false,
            'message' => 'SMTP接続失敗: ' . $errstr,
        ];
    }

    stream_set_timeout($socket, SMTP_READ_TIMEOUT);

    if (smtp_read($socket)['code'] !== 220) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTP greeting error',
        ];
    }

    $hostname = preg_replace(
        '/[^A-Za-z0-9._-]/',
        '',
        $_SERVER['SERVER_NAME'] ?? 'localhost'
    );

    $cmd = smtp_command(
        $socket,
        'EHLO ' . $hostname,
        [250]
    );

    if (!$cmd['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'EHLO failed',
        ];
    }

    if ($encryption === 'tls') {
        $cmd = smtp_command($socket, 'STARTTLS', [220]);

        if (!$cmd['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'STARTTLS failed',
            ];
        }

        if (
            stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            ) !== true
        ) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'TLS initialization failed',
            ];
        }

        $cmd = smtp_command(
            $socket,
            'EHLO ' . $hostname,
            [250]
        );

        if (!$cmd['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'EHLO after TLS failed',
            ];
        }
    }

    if (!empty($config['auth'])) {
        $cmd = smtp_command($socket, 'AUTH LOGIN', [334]);

        if (!$cmd['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTP AUTH failed',
            ];
        }

        $cmd = smtp_command(
            $socket,
            base64_encode((string)$config['username']),
            [334]
        );

        if (!$cmd['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTP username authentication failed',
            ];
        }

        $cmd = smtp_command(
            $socket,
            base64_encode((string)$config['password']),
            [235]
        );

        if (!$cmd['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTP password authentication failed',
            ];
        }
    }

    if (!smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250])['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'MAIL FROM failed',
        ];
    }

    if (!smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251])['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'RCPT TO failed',
        ];
    }

    if (!smtp_command($socket, 'DATA', [354])['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'DATA failed',
        ];
    }

    $encodedSubject = '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $fromHeader = $from;

    if ($fromName !== '') {
        $fromHeader =
            '=?UTF-8?B?' .
            base64_encode($fromName) .
            '?= <' .
            $from .
            '>';
    }

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . $fromHeader,
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    /*
     * SMTP dot-stuffing。
     */
    $safeBody = preg_replace(
        '/^\./m',
        '..',
        str_replace(["\r\n", "\r"], "\n", $body)
    );

    $message =
        implode("\r\n", $headers) .
        "\r\n\r\n" .
        str_replace("\n", "\r\n", $safeBody) .
        "\r\n.\r\n";

    fwrite($socket, $message);

    $response = smtp_read($socket);

    if (!in_array($response['code'], [250], true)) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTP送信失敗: HTTPではなくSMTPコード ' .
                $response['code'],
        ];
    }

    smtp_command($socket, 'QUIT', [221]);

    fclose($socket);

    return [
        'ok' => true,
        'message' => 'メールを送信しました。',
    ];
}

/* ============================================================
 * 顧客・送信
 * ============================================================ */

function customers(): array
{
    $data = read_store('customers');

    return array_values(
        array_filter(
            $data,
            static fn($item) => is_array($item)
        )
    );
}

function post_send_mail(): array
{
    $surveyId = clean_text($_POST['survey_id'] ?? '', 80);
    $survey = get_survey($surveyId);

    if ($survey === null) {
        return result('error', '対象アンケートが存在しません。');
    }

    $selected = $_POST['customer_ids'] ?? [];

    if (!is_array($selected)) {
        $selected = [];
    }

    $selected = array_values(
        array_filter(
            array_map(
                static fn($v) => clean_text($v, 100),
                $selected
            ),
            static fn($v) => $v !== ''
        )
    );

    if (count($selected) === 0) {
        return result('error', '送信対象の顧客を選択してください。');
    }

    $subject = clean_text($_POST['mail_subject'] ?? '', 500);
    $body = clean_text($_POST['mail_body'] ?? '', 10000);

    if ($subject === '' || $body === '') {
        return result('error', 'メール件名と本文を入力してください。');
    }

    $config = get_mail_config();

    if (empty($config['server'])) {
        return result('error', 'メールサーバ設定が未完了です。');
    }

    $customerList = customers();
    $history = read_store('send_history');

    $sent = 0;
    $failed = 0;
    $details = [];

    foreach ($customerList as $customer) {
        $customerId = (string)($customer['id'] ?? '');

        if (!in_array($customerId, $selected, true)) {
            continue;
        }

        $email = (string)($customer['email'] ?? '');

        if (!valid_email($email)) {
            $failed++;

            $details[] = [
                'customer_id' => $customerId,
                'email' => $email,
                'ok' => false,
                'message' => 'メールアドレスが不正です。',
            ];

            continue;
        }

        $customerName = (string)($customer['name'] ?? '');

        $personalBody = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [
                $customerName,
                answer_url((string)$survey['id']),
            ],
            $body
        );

        $send = smtp_send_mail(
            $config,
            $email,
            $subject,
            $personalBody
        );

        if ($send['ok']) {
            $sent++;
        } else {
            $failed++;
        }

        $details[] = [
            'customer_id' => $customerId,
            'email' => $email,
            'ok' => $send['ok'],
            'message' => $send['message'],
        ];

        $history[] = [
            'id' => 'send-' . bin2hex(random_bytes(6)),
            'survey_id' => $survey['id'],
            'customer_id' => $customerId,
            'email' => $email,
            'sent_at' => now_iso(),
            'success' => $send['ok'],
            'message' => $send['message'],
        ];
    }

    write_store('send_history', $history);

    return result(
        $failed === 0 ? 'success' : 'error',
        '送信結果: 成功 ' . $sent . '件 / 失敗 ' . $failed . '件'
    );
}

function answer_url(string $surveyId): string
{
    $scheme = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http'
    );

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $script = basename($_SERVER['PHP_SELF']);

    return $scheme .
        '://' .
        $host .
        dirname($_SERVER['SCRIPT_NAME'] ?? '/') .
        '/' .
        $script .
        '?screen=answer&id=' .
        rawurlencode($surveyId);
}

/* ============================================================
 * 回答
 * ============================================================ */

function visible_questions(array $survey, array $answers): array
{
    $result = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            if (question_is_visible($survey, $question, $answers)) {
                $result[] = $question;
            }
        }
    }

    return $result;
}

function question_is_visible(
    array $survey,
    array $question,
    array $answers
): bool {
    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $source) {
            foreach (($source['branches'] ?? []) as $branch) {
                if (
                    ($branch['questionId'] ?? '') === ($question['id'] ?? '')
                ) {
                    $sourceAnswer = $answers[$source['id']] ?? null;

                    $matches = false;

                    if (is_array($sourceAnswer)) {
                        $matches = in_array(
                            (string)($branch['option'] ?? ''),
                            array_map('strval', $sourceAnswer),
                            true
                        );
                    } else {
                        $matches = (string)$sourceAnswer ===
                            (string)($branch['option'] ?? '');
                    }

                    if ($matches) {
                        return true;
                    }
                }
            }
        }
    }

    /*
     * 分岐指定のない質問は表示。
     */
    $hasIncomingBranch = false;

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $source) {
            foreach (($source['branches'] ?? []) as $branch) {
                if (
                    ($branch['questionId'] ?? '') === ($question['id'] ?? '')
                ) {
                    $hasIncomingBranch = true;
                }
            }
        }
    }

    return !$hasIncomingBranch;
}

function validate_answers(
    array $survey,
    array $answers
): array {
    $errors = [];

    foreach (visible_questions($survey, $answers) as $question) {
        $id = (string)$question['id'];
        $value = $answers[$id] ?? null;

        if (!empty($question['required'])) {
            $empty = false;

            if ($question['type'] === 'multiple') {
                $empty = !is_array($value) || count($value) === 0;
            } else {
                $empty = trim((string)$value) === '';
            }

            if ($empty) {
                $errors[] =
                    ($question['number'] ?? '') .
                    '「' .
                    ($question['text'] ?? '') .
                    '」は必須です。';
            }
        }
    }

    return $errors;
}

function post_save_answer(): array
{
    $surveyId = clean_text($_POST['survey_id'] ?? '', 80);
    $survey = get_survey($surveyId);

    if ($survey === null) {
        return result('error', 'アンケートが存在しません。');
    }

    if (($survey['status'] ?? '') !== STATUS_PUBLISHED) {
        return result('error', 'このアンケートは現在回答できません。');
    }

    $answers = $_POST['answers'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $_SESSION['answer_state'][$surveyId] = $answers;

    $errors = validate_answers($survey, $answers);

    if (count($errors) > 0) {
        return result(
            'error',
            implode(' / ', $errors)
        );
    }

    redirect_screen('confirm', $surveyId);

    return result('success', '確認画面へ進みます。');
}

function post_submit_answer(): array
{
    $surveyId = clean_text($_POST['survey_id'] ?? '', 80);
    $survey = get_survey($surveyId);

    if ($survey === null) {
        return result('error', 'アンケートが存在しません。');
    }

    $answers = $_SESSION['answer_state'][$surveyId] ?? [];

    if (!is_array($answers)) {
        return result('error', '回答データが存在しません。');
    }

    $errors = validate_answers($survey, $answers);

    if (count($errors) > 0) {
        return result('error', '必須項目を確認してください。');
    }

    $allAnswers = read_store('answers');

    $allAnswers[] = [
        'id' => 'answer-' . bin2hex(random_bytes(8)),
        'survey_id' => $surveyId,
        'submitted_at' => now_iso(),
        'answers' => $answers,
    ];

    if (!write_store('answers', $allAnswers)) {
        return result('error', '回答を保存できませんでした。');
    }

    unset($_SESSION['answer_state'][$surveyId]);

    redirect_screen('complete', $surveyId);

    return result('success', '回答を送信しました。');
}

/* ============================================================
 * 集計
 * ============================================================ */

function survey_answers(string $surveyId): array
{
    return array_values(
        array_filter(
            read_store('answers'),
            static function ($answer) use ($surveyId): bool {
                return is_array($answer)
                    && (string)($answer['survey_id'] ?? '') === $surveyId;
            }
        )
    );
}

function answer_count(string $surveyId): int
{
    return count(survey_answers($surveyId));
}

function send_count(string $surveyId): int
{
    $count = 0;

    foreach (read_store('send_history') as $history) {
        if (
            is_array($history)
            && (string)($history['survey_id'] ?? '') === $surveyId
            && !empty($history['success'])
        ) {
            $count++;
        }
    }

    return $count;
}

function csv_download(string $surveyId): void
{
    $survey = get_survey($surveyId);

    if ($survey === null) {
        http_response_code(404);
        exit('Not Found');
    }

    $answers = survey_answers($surveyId);

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        preg_replace('/[^A-Za-z0-9_-]/', '_', $surveyId) .
        '.csv"'
    );

    $out = fopen('php://output', 'w');

    if ($out === false) {
        exit;
    }

    /*
     * Excel向けUTF-8 BOM。
     */
    fwrite($out, "\xEF\xBB\xBF");

    $header = [
        '回答ID',
        '回答日時',
    ];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $header[] = (string)($question['number'] ?? '');
            $header[] = (string)($question['text'] ?? '');
        }
    }

    fputcsv($out, $header);

    foreach ($answers as $answer) {
        $row = [
            $answer['id'] ?? '',
            $answer['submitted_at'] ?? '',
        ];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $value = $answer['answers'][$question['id']] ?? '';

                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
                }

                $row[] = $value;
                $row[] = '';
            }
        }

        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}

/* ============================================================
 * HTML
 * ============================================================ */

function page_start(string $title, bool $admin = true): void
{
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> - <?= e(APP_NAME) ?></title>
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

body {
    margin:0;
    color:var(--text);
    background:#f8fafc;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
}

.admin-header {
    background:#0f172a;
    color:#fff;
    padding:16px 24px;
}

.admin-header-inner {
    max-width:1280px;
    margin:auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
}

.admin-header a {
    color:#fff;
    text-decoration:none;
}

.container {
    max-width:1280px;
    margin:auto;
    padding:24px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:24px;
    margin-bottom:20px;
    box-shadow:var(--shadow);
}

h1,
h2,
h3 {
    margin-top:0;
}

.form-row {
    margin-bottom:18px;
}

label {
    display:block;
    font-weight:700;
    margin-bottom:7px;
}

input,
textarea,
select {
    width:100%;
    padding:11px 12px;
    border:1px solid #cbd5e1;
    border-radius:8px;
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
    outline:3px solid rgba(37,99,235,.15);
    border-color:var(--primary);
}

button,
.btn {
    display:inline-block;
    border:0;
    border-radius:8px;
    padding:10px 16px;
    font:inherit;
    font-weight:700;
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
    color:var(--text);
}

.success {
    background:var(--success);
    color:#fff;
}

.danger {
    background:var(--danger);
    color:#fff;
}

.warning {
    background:var(--warning);
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

.notice.info {
    color:#1e40af;
    background:#dbeafe;
    border:1px solid #93c5fd;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    min-width:950px;
    border-collapse:collapse;
}

th,
td {
    padding:12px;
    border-bottom:1px solid #e2e8f0;
    text-align:left;
    vertical-align:top;
}

th {
    background:#f8fafc;
    white-space:nowrap;
}

.status {
    display:inline-block;
    padding:4px 9px;
    border-radius:999px;
    font-size:.85rem;
    font-weight:700;
}

.status-draft {
    background:#e2e8f0;
    color:#475569;
}

.status-published {
    background:#dcfce7;
    color:#166534;
}

.status-stopped {
    background:#fef3c7;
    color:#92400e;
}

.status-ended {
    background:#fee2e2;
    color:#991b1b;
}

.grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.grid-3 {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:18px;
}

.metric {
    padding:20px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
}

.metric strong {
    display:block;
    font-size:2rem;
    margin-top:8px;
}

.group-card {
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    margin-bottom:16px;
    background:#fff;
}

.question-card {
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:16px;
    margin:12px 0;
    background:#f8fafc;
}

.drag-handle {
    cursor:grab;
    color:var(--gray);
    font-size:.9rem;
}

.preview-question {
    padding:18px 0;
    border-bottom:1px solid var(--border);
}

.required {
    color:var(--danger);
}

.answer-page {
    max-width:760px;
    margin:auto;
}

.answer-option {
    display:block;
    padding:13px;
    border:1px solid var(--border);
    border-radius:8px;
    margin:8px 0;
    background:#fff;
}

.answer-option input {
    width:auto;
    margin-right:8px;
}

.processing {
    pointer-events:none;
    opacity:.65;
}

.spinner {
    display:none;
    margin-left:8px;
}

.processing .spinner {
    display:inline-block;
}

.small {
    font-size:.9rem;
    color:var(--gray);
}

code {
    background:#f1f5f9;
    padding:2px 5px;
    border-radius:4px;
}

@media(max-width:800px) {
    .container {
        padding:12px;
    }

    .card {
        padding:16px;
    }

    .grid,
    .grid-3 {
        grid-template-columns:1fr;
    }

    .admin-header {
        padding:14px;
    }

    .admin-header-inner {
        align-items:flex-start;
        flex-direction:column;
    }

    .answer-option {
        padding:16px 12px;
    }
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header class="admin-header">
    <div class="admin-header-inner">
        <a href="<?= e(basename($_SERVER['PHP_SELF'])) ?>?screen=list">
            <strong><?= e(APP_NAME) ?></strong>
        </a>
        <nav class="actions">
            <a href="?screen=list">アンケート一覧</a>
            <a href="?screen=kintone">kintone</a>
            <a href="?screen=mail">メール</a>
        </nav>
    </div>
</header>
<?php endif; ?>
<main class="<?= $admin ? 'container' : 'container answer-page' ?>">
<?php
}

function page_end(): void
{
    ?>
</main>
<script>
document.querySelectorAll('form[data-processing]').forEach(function(form) {
    form.addEventListener('submit', function() {
        form.classList.add('processing');

        form.querySelectorAll('button').forEach(function(button) {
            button.disabled = true;
        });
    });
});

document.querySelectorAll('[data-confirm]').forEach(function(form) {
    form.addEventListener('submit', function(event) {
        const message = form.getAttribute('data-confirm');

        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
});
</script>
</body>
</html>
<?php
}

/* ============================================================
 * 一覧
 * ============================================================ */

function render_list(?array $operationResult): void
{
    $surveys = array_values(read_store('surveys'));

    usort(
        $surveys,
        static function ($a, $b): int {
            return strcmp(
                (string)($b['updatedAt'] ?? ''),
                (string)($a['updatedAt'] ?? '')
            );
        }
    );

    page_start('アンケート一覧');

    if ($operationResult !== null) {
        ?>
        <div class="notice <?= e($operationResult['type']) ?>">
            <?= e($operationResult['message']) ?>
        </div>
        <?php
    }
    ?>

    <div class="card">
        <div class="actions" style="justify-content:space-between;">
            <div>
                <h1>アンケート一覧</h1>
                <p class="small">更新日の新しい順で表示しています。</p>
            </div>
            <a class="btn primary" href="?screen=edit">＋ 新規作成</a>
        </div>
    </div>

    <div class="card">
        <div class="grid">
            <div class="form-row">
                <label for="search">タイトル検索</label>
                <input id="search" type="search" placeholder="タイトルを入力してEnter">
            </div>

            <div class="form-row">
                <label for="statusFilter">ステータス</label>
                <select id="statusFilter">
                    <option value="">すべて</option>
                    <option value="published">公開中</option>
                    <option value="draft">下書き</option>
                    <option value="stopped">停止</option>
                    <option value="ended">終了</option>
                </select>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table id="surveyTable">
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
                <?php foreach ($surveys as $survey): ?>
                    <?php
                    $id = (string)($survey['id'] ?? '');
                    $status = (string)($survey['status'] ?? STATUS_DRAFT);
                    ?>
                    <tr
                        data-title="<?= e(mb_strtolower((string)($survey['title'] ?? ''), 'UTF-8')) ?>"
                        data-status="<?= e($status) ?>"
                    >
                        <td>
                            <strong><?= e($survey['title'] ?? '') ?></strong>
                        </td>
                        <td><?= e(format_datetime($survey['createdAt'] ?? null)) ?></td>
                        <td><?= e(format_datetime($survey['updatedAt'] ?? null)) ?></td>
                        <td>
                            <?= e(format_datetime($survey['startAt'] ?? null)) ?>
                            ～
                            <?= e(format_datetime($survey['endAt'] ?? null)) ?>
                        </td>
                        <td>
                            <span class="status <?= e(status_class($status)) ?>">
                                <?= e(status_label($status)) ?>
                            </span>
                        </td>
                        <td><?= answer_count($id) ?></td>
                        <td>
                            <div class="actions">
                                <a class="btn secondary"
                                   href="?screen=edit&id=<?= e($id) ?>">
                                    確認・編集
                                </a>

                                <a class="btn secondary"
                                   href="?screen=preview&id=<?= e($id) ?>">
                                    プレビュー
                                </a>

                                <a class="btn secondary"
                                   href="?screen=analytics&id=<?= e($id) ?>">
                                    集計
                                </a>

                                <a class="btn secondary"
                                   href="?screen=send&id=<?= e($id) ?>">
                                    送信
                                </a>

                                <form method="post"
                                      data-confirm="このアンケートを複製しますか？">
                                    <input type="hidden" name="action" value="duplicate_survey">
                                    <input type="hidden" name="id" value="<?= e($id) ?>">
                                    <button class="secondary">複製</button>
                                </form>

                                <form method="post"
                                      data-confirm="このアンケートを削除しますか？">
                                    <input type="hidden" name="action" value="delete_survey">
                                    <input type="hidden" name="id" value="<?= e($id) ?>">
                                    <button class="danger">削除</button>
                                </form>
                            </div>

                            <?php if ($status !== STATUS_ENDED): ?>
                                <div class="actions" style="margin-top:8px;">
                                    <?php if ($status === STATUS_DRAFT): ?>
                                        <form method="post"
                                              data-confirm="このアンケートを公開しますか？">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="id" value="<?= e($id) ?>">
                                            <input type="hidden" name="next_status" value="published">
                                            <button class="success">公開</button>
                                        </form>
                                    <?php elseif ($status === STATUS_PUBLISHED): ?>
                                        <form method="post"
                                              data-confirm="このアンケートを停止しますか？">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="id" value="<?= e($id) ?>">
                                            <input type="hidden" name="next_status" value="stopped">
                                            <button class="warning">停止</button>
                                        </form>
                                    <?php elseif ($status === STATUS_STOPPED): ?>
                                        <form method="post"
                                              data-confirm="このアンケートを再開しますか？">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="id" value="<?= e($id) ?>">
                                            <input type="hidden" name="next_status" value="published">
                                            <button class="success">再開</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (count($surveys) === 0): ?>
                    <tr>
                        <td colspan="7">アンケートはまだありません。</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<script>
(function() {
    const search = document.getElementById('search');
    const filter = document.getElementById('statusFilter');
    const rows = document.querySelectorAll('#surveyTable tbody tr[data-title]');

    function apply() {
        const word = search.value.trim().toLowerCase();
        const status = filter.value;

        rows.forEach(function(row) {
            const title = row.dataset.title || '';
            const rowStatus = row.dataset.status || '';

            const okTitle = !word || title.includes(word);
            const okStatus = !status || rowStatus === status;

            row.style.display = okTitle && okStatus ? '' : 'none';
        });
    }

    search.addEventListener('keydown', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            apply();
        }
    });

    filter.addEventListener('change', apply);
})();
</script>

    <?php
    page_end();
}

/* ============================================================
 * 編集
 * ============================================================ */

function render_edit(?array $operationResult): void
{
    $id = clean_text($_GET['id'] ?? '', 80);

    $survey = $id !== '' ? get_survey($id) : null;

    if ($survey === null) {
        $survey = default_survey();
    }

    renumber_questions($survey);

    page_start('アンケート作成・編集');

    if ($operationResult !== null) {
        ?>
        <div class="notice <?= e($operationResult['type']) ?>">
            <?= e($operationResult['message']) ?>
        </div>
        <?php
    }
    ?>

    <form method="post" data-processing id="surveyForm">
        <input type="hidden" name="action" value="save_survey">
        <input type="hidden" name="id" value="<?= e($survey['id']) ?>">
        <input type="hidden" name="groups_json" id="groups_json">

        <div class="card">
            <div class="actions" style="justify-content:space-between;">
                <a class="btn secondary"
                   href="?screen=list">
                    キャンセル
                </a>

                <div class="actions">
                    <label style="margin:0;">状態</label>

                    <?php
                    $status = (string)($survey['status'] ?? STATUS_DRAFT);
                    ?>

                    <select disabled>
                        <option><?= e(status_label($status)) ?></option>
                    </select>

                    <button class="primary" type="submit">
                        保存して一覧へ
                        <span class="spinner">処理中...</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="card">
            <h1>アンケート基本情報</h1>

            <div class="form-row">
                <label for="title">アンケートタイトル</label>
                <input
                    id="title"
                    name="title"
                    maxlength="200"
                    required
                    value="<?= e($survey['title'] ?? '') ?>"
                >
            </div>

            <div class="form-row">
                <label for="description">アンケート説明</label>
                <textarea
                    id="description"
                    name="description"
                    maxlength="5000"
                ><?= e($survey['description'] ?? '') ?></textarea>
            </div>

            <div class="grid">
                <div class="form-row">
                    <label for="startAt">開始日時</label>
                    <input
                        id="startAt"
                        type="datetime-local"
                        name="startAt"
                        value="<?= e($survey['startAt'] ?? '') ?>"
                    >
                </div>

                <div class="form-row">
                    <label for="endAt">終了日時</label>
                    <input
                        id="endAt"
                        type="datetime-local"
                        name="endAt"
                        value="<?= e($survey['endAt'] ?? '') ?>"
                    >
                </div>
            </div>

            <div class="form-row">
                <label for="numbering">質問番号の採番方式</label>
                <select id="numbering" name="numbering">
                    <option value="global"
                        <?= ($survey['numbering'] ?? 'global') === 'global'
                            ? 'selected' : '' ?>>
                        アンケート全体で通番：Q1、Q2、Q3...
                    </option>
                    <option value="group"
                        <?= ($survey['numbering'] ?? '') === 'group'
                            ? 'selected' : '' ?>>
                        グループ毎：Q1-1、Q1-2、Q2-1...
                    </option>
                </select>
            </div>
        </div>

        <div class="card">
            <div class="actions" style="justify-content:space-between;">
                <div>
                    <h2>質問・グループ</h2>
                    <p class="small">
                        ドラッグ＆ドロップで並び替えできます。
                    </p>
                </div>

                <button type="button" class="secondary" id="addGroup">
                    ＋ グループを追加
                </button>
            </div>

            <div id="groups"></div>
        </div>
    </form>

<script>
(function() {
    let groups = <?= json_encode(
        $survey['groups'],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    const groupsEl = document.getElementById('groups');
    const hidden = document.getElementById('groups_json');
    const numbering = document.getElementById('numbering');

    function uid(prefix) {
        return prefix + '-' +
            Math.random().toString(36).slice(2, 10);
    }

    function renumber() {
        let global = 1;

        groups.forEach(function(group, gi) {
            group.questions.forEach(function(question, qi) {
                question.number =
                    numbering.value === 'group'
                        ? 'Q' + (gi + 1) + '-' + (qi + 1)
                        : 'Q' + global;

                global++;
            });
        });
    }

    function sync() {
        renumber();
        hidden.value = JSON.stringify(groups);
        render();
    }

    function render() {
        groupsEl.innerHTML = '';

        groups.forEach(function(group, gi) {
            const groupEl = document.createElement('div');
            groupEl.className = 'group-card';
            groupEl.draggable = true;
            groupEl.dataset.index = gi;

            groupEl.innerHTML = `
                <div class="actions">
                    <span class="drag-handle">☰ グループをドラッグ</span>
                    <strong>グループ ${gi + 1}</strong>
                    <button type="button" class="secondary add-question">
                        ＋ 質問を追加
                    </button>
                    <button type="button" class="danger delete-group">
                        グループ削除
                    </button>
                </div>

                <div class="form-row" style="margin-top:14px;">
                    <label>グループタイトル</label>
                    <input
                        class="group-title"
                        value=""
                        maxlength="200"
                    >
                </div>

                <div class="questions"></div>
            `;

            groupEl.querySelector('.group-title').value =
                group.title || '';

            const questionsEl =
                groupEl.querySelector('.questions');

            group.questions.forEach(function(question, qi) {
                const q = document.createElement('div');

                q.className = 'question-card';
                q.draggable = true;
                q.dataset.index = qi;

                q.innerHTML = `
                    <div class="actions">
                        <span class="drag-handle">
                            ☰
                            <strong class="question-number"></strong>
                        </span>

                        <button type="button"
                                class="danger delete-question">
                            質問削除
                        </button>
                    </div>

                    <div class="form-row" style="margin-top:12px;">
                        <label>質問文</label>
                        <textarea
                            class="question-text"
                            maxlength="2000"
                        ></textarea>
                    </div>

                    <div class="grid">
                        <div class="form-row">
                            <label>回答形式</label>
                            <select class="question-type">
                                <option value="single">単一選択</option>
                                <option value="multiple">複数選択</option>
                                <option value="text">自由記述</option>
                            </select>
                        </div>

                        <div class="form-row">
                            <label>
                                <input
                                    type="checkbox"
                                    class="question-required"
                                    style="width:auto;"
                                >
                                必須
                            </label>
                        </div>
                    </div>

                    <div class="options-area"></div>
                `;

                q.querySelector('.question-number').textContent =
                    question.number || '';

                q.querySelector('.question-text').value =
                    question.text || '';

                q.querySelector('.question-type').value =
                    question.type || 'single';

                q.querySelector('.question-required').checked =
                    !!question.required;

                const optionsArea =
                    q.querySelector('.options-area');

                function renderOptions() {
                    if (question.type === 'text') {
                        optionsArea.innerHTML =
                            '<p class="small">自由記述のため選択肢はありません。</p>';
                        return;
                    }

                    optionsArea.innerHTML = `
                        <label>選択肢</label>
                        <div class="option-list"></div>
                        <button type="button"
                                class="secondary add-option">
                            ＋ 選択肢を追加
                        </button>
                    `;

                    const list =
                        optionsArea.querySelector('.option-list');

                    (question.options || []).forEach(function(option, oi) {
                        const row = document.createElement('div');
                        row.className = 'actions';
                        row.style.marginBottom = '8px';

                        row.innerHTML = `
                            <input class="option-input"
                                   value=""
                                   maxlength="300">
                            <button type="button"
                                    class="danger delete-option">
                                削除
                            </button>
                        `;

                        row.querySelector('.option-input').value =
                            option;

                        row.querySelector('.option-input')
                            .addEventListener('input', function() {
                                question.options[oi] = this.value;
                                syncHiddenOnly();
                            });

                        row.querySelector('.delete-option')
                            .addEventListener('click', function() {
                                question.options.splice(oi, 1);
                                sync();
                            });

                        list.appendChild(row);
                    });

                    optionsArea.querySelector('.add-option')
                        .addEventListener('click', function() {
                            question.options.push('新しい選択肢');
                            sync();
                        });
                }

                renderOptions();

                q.querySelector('.question-text')
                    .addEventListener('input', function() {
                        question.text = this.value;
                        syncHiddenOnly();
                    });

                q.querySelector('.question-type')
                    .addEventListener('change', function() {
                        question.type = this.value;

                        if (question.type === 'text') {
                            question.options = [];
                        } else if (!question.options.length) {
                            question.options = [
                                '選択肢1',
                                '選択肢2'
                            ];
                        }

                        sync();
                    });

                q.querySelector('.question-required')
                    .addEventListener('change', function() {
                        question.required = this.checked;
                        syncHiddenOnly();
                    });

                q.querySelector('.delete-question')
                    .addEventListener('click', function() {
                        if (!confirm('この質問を削除しますか？')) {
                            return;
                        }

                        group.questions.splice(qi, 1);
                        sync();
                    });

                questionsEl.appendChild(q);
            });

            groupEl.querySelector('.group-title')
                .addEventListener('input', function() {
                    group.title = this.value;
                    syncHiddenOnly();
                });

            groupEl.querySelector('.delete-group')
                .addEventListener('click', function() {
                    if (!confirm('このグループを削除しますか？')) {
                        return;
                    }

                    groups.splice(gi, 1);

                    if (groups.length === 0) {
                        groups.push({
                            id: uid('group'),
                            title: 'グループ1',
                            questions: []
                        });
                    }

                    sync();
                });

            groupEl.querySelector('.add-question')
                .addEventListener('click', function() {
                    group.questions.push({
                        id: uid('question'),
                        text: '',
                        type: 'single',
                        required: false,
                        options: ['選択肢1', '選択肢2'],
                        branches: []
                    });

                    sync();
                });

            groupsEl.appendChild(groupEl);
        });
    }

    function syncHiddenOnly() {
        renumber();
        hidden.value = JSON.stringify(groups);

        document.querySelectorAll('.question-number')
            .forEach(function(el, index) {
                let n = 0;

                groups.forEach(function(group) {
                    group.questions.forEach(function(question) {
                        if (question.number === el.textContent) {
                            return;
                        }
                    });
                });
            });
    }

    document.getElementById('addGroup')
        .addEventListener('click', function() {
            groups.push({
                id: uid('group'),
                title: '新しいグループ',
                questions: []
            });

            sync();
        });

    numbering.addEventListener('change', sync);

    document.getElementById('surveyForm')
        .addEventListener('submit', function() {
            renumber();
            hidden.value = JSON.stringify(groups);
        });

    sync();
})();
</script>

    <?php
    page_end();
}

/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(): void
{
    $id = clean_text($_GET['id'] ?? '', 80);
    $survey = get_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    renumber_questions($survey);

    page_start('プレビュー');
    ?>

    <div class="card">
        <div class="actions">
            <a class="btn secondary"
               href="?screen=edit&id=<?= e($id) ?>">
                編集へ戻る
            </a>
            <a class="btn primary"
               href="<?= e(answer_url($id)) ?>"
               target="_blank"
               rel="noopener">
                回答者画面を開く
            </a>
        </div>
    </div>

    <div class="card">
        <h1><?= e($survey['title']) ?></h1>

        <?php if (($survey['description'] ?? '') !== ''): ?>
            <p><?= nl2br(e($survey['description'])) ?></p>
        <?php endif; ?>

        <?php foreach ($survey['groups'] as $group): ?>
            <div class="group-card">
                <h2><?= e($group['title']) ?></h2>

                <?php foreach ($group['questions'] as $question): ?>
                    <div class="preview-question">
                        <strong>
                            <?= e($question['number'] ?? '') ?>
                            <?= e($question['text'] ?? '') ?>

                            <?php if (!empty($question['required'])): ?>
                                <span class="required">＊必須</span>
                            <?php endif; ?>
                        </strong>

                        <?php if ($question['type'] === 'text'): ?>
                            <textarea disabled></textarea>
                        <?php else: ?>
                            <?php foreach ($question['options'] as $option): ?>
                                <label class="answer-option">
                                    <input
                                        type="<?= $question['type'] === 'multiple'
                                            ? 'checkbox'
                                            : 'radio' ?>"
                                        disabled
                                    >
                                    <?= e($option) ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php
    page_end();
}

/* ============================================================
 * kintone設定画面
 * ============================================================ */

function render_kintone(?array $operationResult): void
{
    $config = get_kintone_config();

    page_start('kintone連携設定');

    if ($operationResult !== null) {
        ?>
        <div class="notice <?= e($operationResult['type']) ?>">
            <?= e($operationResult['message']) ?>
        </div>
        <?php
    }
    ?>

    <div class="card">
        <h1>kintone連携設定</h1>

        <form method="post" data-processing>
            <input type="hidden" name="action" value="save_kintone">

            <div class="form-row">
                <label for="subdomain">サブドメイン</label>
                <input
                    id="subdomain"
                    name="subdomain"
                    placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
                    value="<?= e($config['host'] ?? '') ?>"
                >
            </div>

            <div class="form-row">
                <label for="app_id">顧客管理アプリID</label>
                <input
                    id="app_id"
                    name="app_id"
                    inputmode="numeric"
                    value="<?= e($config['app_id'] ?? '') ?>"
                >
            </div>

            <div class="form-row">
                <label for="login_name">ログイン名</label>
                <input
                    id="login_name"
                    name="login_name"
                    autocomplete="username"
                    value="<?= e($config['login_name'] ?? '') ?>"
                >
            </div>

            <div class="form-row">
                <label for="password">パスワード</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    placeholder="変更しない場合は空欄"
                >
            </div>

            <div class="form-row">
                <label for="proxy">Proxy</label>
                <input
                    id="proxy"
                    name="proxy"
                    placeholder="host:port"
                    value="<?= e($config['proxy'] ?? '') ?>"
                >
            </div>

            <div class="form-row">
                <label>
                    <input
                        type="checkbox"
                        name="verify_ssl"
                        style="width:auto;"
                        <?= !empty($config['verify_ssl']) ? 'checked' : '' ?>
                    >
                    SSL証明書を検証する
                </label>

                <p class="small">
                    POCでは無効を初期値としています。
                </p>
            </div>

            <button class="primary">
                設定保存
            </button>
        </form>
    </div>

    <div class="card">
        <h2>接続確認</h2>

        <p>
            接続テストは実際のkintoneへ
            <code>app.json?id=アプリID</code>
            を使用して接続します。
        </p>

        <form method="post" data-processing>
            <input type="hidden" name="action" value="test_kintone">
            <button class="primary">
                接続テスト
                <span class="spinner">接続中...</span>
            </button>
        </form>

        <hr style="margin:24px 0;">

        <h2>顧客情報</h2>

        <form method="post" data-processing>
            <input type="hidden" name="action" value="sync_kintone">
            <button class="secondary">
                顧客情報を同期
                <span class="spinner">同期中...</span>
            </button>
        </form>
    </div>

    <div class="card">
        <h2>認証方式</h2>
        <p>
            ログイン名・パスワードから
            <code>X-Cybozu-Authorization</code>
            をサーバー側で生成します。
        </p>
        <p class="small">
            認証ヘッダーおよびパスワードはブラウザJavaScriptへ渡しません。
        </p>
    </div>

    <?php
    page_end();
}

/* ============================================================
 * メール設定
 * ============================================================ */

function render_mail(?array $operationResult): void
{
    $config = get_mail_config();

    page_start('メールサーバ設定');

    if ($operationResult !== null) {
        ?>
        <div class="notice <?= e($operationResult['type']) ?>">
            <?= e($operationResult['message']) ?>
        </div>
        <?php
    }
    ?>

    <div class="card">
        <h1>メールサーバ設定</h1>

        <form method="post" data-processing>
            <input type="hidden" name="action" value="save_mail">

            <div class="grid">
                <div class="form-row">
                    <label>SMTPサーバ</label>
                    <input
                        name="smtp_server"
                        value="<?= e($config['server'] ?? '') ?>"
                    >
                </div>

                <div class="form-row">
                    <label>SMTPポート</label>
                    <input
                        name="smtp_port"
                        inputmode="numeric"
                        value="<?= e($config['port'] ?? '') ?>"
                    >
                </div>
            </div>

            <div class="form-row">
                <label>暗号化方式</label>
                <select name="encryption">
                    <?php foreach (['ssl', 'tls', 'none'] as $enc): ?>
                        <option
                            value="<?= e($enc) ?>"
                            <?= ($config['encryption'] ?? 'tls') === $enc
                                ? 'selected' : '' ?>
                        >
                            <?= e(strtoupper($enc)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label>
                    <input
                        type="checkbox"
                        name="smtp_auth"
                        style="width:auto;"
                        <?= !empty($config['auth']) ? 'checked' : '' ?>
                    >
                    SMTP認証を使用する
                </label>
            </div>

            <div class="grid">
                <div class="form-row">
                    <label>SMTPユーザー名</label>
                    <input
                        name="smtp_username"
                        value="<?= e($config['username'] ?? '') ?>"
                    >
                </div>

                <div class="form-row">
                    <label>SMTPパスワード</label>
                    <input
                        name="smtp_password"
                        type="password"
                        placeholder="変更しない場合は空欄"
                    >
                </div>
            </div>

            <div class="grid">
                <div class="form-row">
                    <label>送信元メールアドレス</label>
                    <input
                        name="from_email"
                        type="email"
                        value="<?= e($config['from_email'] ?? '') ?>"
                    >
                </div>

                <div class="form-row">
                    <label>送信元名</label>
                    <input
                        name="from_name"
                        value="<?= e($config['from_name'] ?? '') ?>"
                    >
                </div>
            </div>

            <div class="form-row">
                <label>返信先メールアドレス</label>
                <input
                    name="reply_to"
                    type="email"
                    value="<?= e($config['reply_to'] ?? '') ?>"
                >
            </div>

            <button class="primary">
                設定保存
            </button>
        </form>
    </div>

    <div class="card">
        <h2>接続テスト</h2>

        <form method="post" data-processing>
            <input type="hidden" name="action" value="test_mail">
            <button class="primary">
                接続テスト
                <span class="spinner">接続中...</span>
            </button>
        </form>

        <p class="small">
            接続テストとテストメール送信は別操作です。
        </p>
    </div>

    <?php
    page_end();
}

/* ============================================================
 * 送信画面
 * ============================================================ */

function render_send(?array $operationResult): void
{
    $id = clean_text($_GET['id'] ?? '', 80);
    $survey = get_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    $customerList = customers();

    page_start('顧客選択・メール送信');

    if ($operationResult !== null) {
        ?>
        <div class="notice <?= e($operationResult['type']) ?>">
            <?= e($operationResult['message']) ?>
        </div>
        <?php
    }
    ?>

    <div class="card">
        <h1>顧客選択・メール送信</h1>

        <p>
            対象アンケート：
            <strong><?= e($survey['title']) ?></strong>
        </p>

        <p class="small">
            対象アンケートはURLのIDで固定されています。
        </p>
    </div>

    <div class="card">
        <h2>顧客検索</h2>

        <input
            id="customerSearch"
            type="search"
            placeholder="氏名・組織名・メールアドレスで検索"
        >

        <form method="post" data-processing style="margin-top:20px;">
            <input type="hidden" name="action" value="send_mail">
            <input type="hidden" name="survey_id" value="<?= e($id) ?>">

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>
                            <input
                                type="checkbox"
                                id="selectAll"
                                style="width:auto;"
                            >
                        </th>
                        <th>組織名</th>
                        <th>氏名</th>
                        <th>メール</th>
                        <th>部署</th>
                        <th>電話</th>
                        <th>住所</th>
                    </tr>
                    </thead>
                    <tbody id="customerRows">
                    <?php foreach ($customerList as $customer): ?>
                        <?php
                        $searchText = mb_strtolower(
                            implode(' ', [
                                $customer['organization'] ?? '',
                                $customer['name'] ?? '',
                                $customer['email'] ?? '',
                                $customer['department'] ?? '',
                            ]),
                            'UTF-8'
                        );
                        ?>
                        <tr data-search="<?= e($searchText) ?>">
                            <td>
                                <input
                                    type="checkbox"
                                    name="customer_ids[]"
                                    value="<?= e($customer['id'] ?? '') ?>"
                                    style="width:auto;"
                                >
                            </td>
                            <td><?= e($customer['organization'] ?? '') ?></td>
                            <td><?= e($customer['name'] ?? '') ?></td>
                            <td><?= e($customer['email'] ?? '') ?></td>
                            <td><?= e($customer['department'] ?? '') ?></td>
                            <td><?= e($customer['phone'] ?? '') ?></td>
                            <td><?= e($customer['address'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-row" style="margin-top:24px;">
                <label>メール件名</label>
                <input
                    name="mail_subject"
                    required
                    value="<?= e($survey['title'] . ' アンケートのお願い') ?>"
                >
            </div>

            <div class="form-row">
                <label>メール本文</label>
                <textarea
                    name="mail_body"
                    required
                >{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
            </div>

            <button
                class="primary"
                data-confirm="選択した顧客へメールを一括送信します。実行しますか？"
            >
                一括送信
                <span class="spinner">送信中...</span>
            </button>
        </form>
    </div>

    <div class="card">
        <h2>送信履歴</h2>

        <?php
        $history = array_reverse(read_store('send_history'));
        $history = array_values(
            array_filter(
                $history,
                static fn($item) =>
                    is_array($item)
                    && ($item['survey_id'] ?? '') === $id
            )
        );
        ?>

        <?php if (count($history) === 0): ?>
            <p>送信履歴はありません。</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>日時</th>
                        <th>メール</th>
                        <th>結果</th>
                        <th>内容</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $item): ?>
                        <tr>
                            <td><?= e(format_datetime($item['sent_at'] ?? null)) ?></td>
                            <td><?= e($item['email'] ?? '') ?></td>
                            <td>
                                <?= !empty($item['success'])
                                    ? '成功'
                                    : '失敗' ?>
                            </td>
                            <td><?= e($item['message'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<script>
(function() {
    const search = document.getElementById('customerSearch');
    const selectAll = document.getElementById('selectAll');

    search.addEventListener('input', function() {
        const word = this.value.trim().toLowerCase();

        document.querySelectorAll('#customerRows tr[data-search]')
            .forEach(function(row) {
                row.style.display =
                    !word || row.dataset.search.includes(word)
                        ? ''
                        : 'none';
            });
    });

    selectAll.addEventListener('change', function() {
        document.querySelectorAll(
            '#customerRows input[type="checkbox"]'
        ).forEach(function(box) {
            box.checked = selectAll.checked;
        });
    });
})();
</script>

    <?php
    page_end();
}

/* ============================================================
 * 集計画面
 * ============================================================ */

function render_analytics(): void
{
    $id = clean_text($_GET['id'] ?? '', 80);
    $survey = get_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    $answers = survey_answers($id);
    $sent = send_count($id);
    $answerCount = count($answers);

    $rate = $sent > 0
        ? round(($answerCount / $sent) * 100, 1)
        : 0;

    page_start('回答集計・分析');
    ?>

    <div class="card">
        <div class="actions">
            <a class="btn secondary" href="?screen=list">
                一覧へ戻る
            </a>

            <a class="btn primary"
               href="?screen=analytics&id=<?= e($id) ?>&download=csv">
                CSV出力
            </a>
        </div>
    </div>

    <div class="card">
        <h1>回答集計・分析</h1>
        <p>
            対象アンケート：
            <strong><?= e($survey['title']) ?></strong>
        </p>
    </div>

    <div class="grid-3">
        <div class="metric">
            <span>送信対象者数</span>
            <strong><?= $sent ?></strong>
        </div>

        <div class="metric">
            <span>回答数</span>
            <strong><?= $answerCount ?></strong>
        </div>

        <div class="metric">
            <span>回答率</span>
            <strong><?= e((string)$rate) ?>%</strong>
        </div>
    </div>

    <?php if ($answerCount === 0): ?>
        <div class="card">
            <p>現在、回答データはありません</p>
        </div>
    <?php else: ?>

        <?php foreach ($survey['groups'] as $group): ?>
            <div class="card">
                <h2><?= e($group['title']) ?></h2>

                <?php foreach ($group['questions'] as $question): ?>
                    <?php
                    $counts = [];

                    if ($question['type'] !== 'text') {
                        foreach ($question['options'] as $option) {
                            $counts[$option] = 0;
                        }

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
                            } else {
                                if (isset($counts[$value])) {
                                    $counts[$value]++;
                                }
                            }
                        }
                    }
                    ?>

                    <div class="question-card">
                        <h3>
                            <?= e($question['number'] ?? '') ?>
                            <?= e($question['text'] ?? '') ?>
                        </h3>

                        <?php if ($question['type'] === 'text'): ?>
                            <div>
                                <?php foreach ($answers as $answer): ?>
                                    <?php
                                    $value =
                                        $answer['answers'][$question['id']]
                                        ?? '';
                                    ?>
                                    <?php if (trim((string)$value) !== ''): ?>
                                        <p>
                                            <?= nl2br(e($value)) ?>
                                        </p>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                <tr>
                                    <th>選択肢</th>
                                    <th>件数</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($counts as $option => $count): ?>
                                    <tr>
                                        <td><?= e($option) ?></td>
                                        <td><?= $count ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
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
                        <th>回答日時</th>
                        <?php foreach ($survey['groups'] as $group): ?>
                            <?php foreach ($group['questions'] as $question): ?>
                                <th>
                                    <?= e($question['number'] ?? '') ?>
                                </th>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($answers as $answer): ?>
                        <tr>
                            <td><?= e($answer['id'] ?? '') ?></td>
                            <td><?= e(format_datetime($answer['submitted_at'] ?? null)) ?></td>

                            <?php foreach ($survey['groups'] as $group): ?>
                                <?php foreach ($group['questions'] as $question): ?>
                                    <?php
                                    $value =
                                        $answer['answers'][$question['id']]
                                        ?? '';

                                    if (is_array($value)) {
                                        $value = implode(', ', $value);
                                    }
                                    ?>
                                    <td><?= nl2br(e($value)) ?></td>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

    <?php
    page_end();
}

/* ============================================================
 * 回答者画面
 * ============================================================ */

function render_answer(): void
{
    $id = clean_text($_GET['id'] ?? '', 80);
    $survey = get_survey($id);

    if ($survey === null) {
        http_response_code(404);
        page_start('アンケート', false);
        ?>
        <div class="card">
            <h1>アンケートが見つかりません</h1>
            <p>指定されたアンケートは存在しません。</p>
        </div>
        <?php
        page_end();
        return;
    }

    if (($survey['status'] ?? '') !== STATUS_PUBLISHED) {
        page_start('アンケート', false);
        ?>
        <div class="card">
            <h1>現在回答できません</h1>
            <p>このアンケートは現在公開されていません。</p>
        </div>
        <?php
        page_end();
        return;
    }

    $answers = $_SESSION['answer_state'][$id] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    page_start('アンケート回答', false);
    ?>

    <div class="card">
        <h1><?= e($survey['title']) ?></h1>

        <?php if (($survey['description'] ?? '') !== ''): ?>
            <p><?= nl2br(e($survey['description'])) ?></p>
        <?php endif; ?>
    </div>

    <form method="post" data-processing>
        <input type="hidden" name="action" value="save_answer">
        <input type="hidden" name="survey_id" value="<?= e($id) ?>">

        <?php foreach ($survey['groups'] as $group): ?>
            <div class="card">
                <h2><?= e($group['title']) ?></h2>

                <?php foreach ($group['questions'] as $question): ?>
                    <div class="question-card">
                        <h3>
                            <?= e($question['number'] ?? '') ?>
                            <?= e($question['text'] ?? '') ?>

                            <?php if (!empty($question['required'])): ?>
                                <span class="required">＊必須</span>
                            <?php endif; ?>
                        </h3>

                        <?php if ($question['type'] === 'text'): ?>

                            <textarea
                                name="answers[<?= e($question['id']) ?>]"
                                <?= !empty($question['required'])
                                    ? 'required' : '' ?>
                            ><?= e($answers[$question['id']] ?? '') ?></textarea>

                        <?php elseif ($question['type'] === 'multiple'): ?>

                            <?php
                            $selected =
                                is_array($answers[$question['id']] ?? null)
                                    ? $answers[$question['id']]
                                    : [];
                            ?>

                            <?php foreach ($question['options'] as $option): ?>
                                <label class="answer-option">
                                    <input
                                        type="checkbox"
                                        name="answers[<?= e($question['id']) ?>][]"
                                        value="<?= e($option) ?>"
                                        <?= in_array(
                                            $option,
                                            $selected,
                                            true
                                        ) ? 'checked' : '' ?>
                                    >
                                    <?= e($option) ?>
                                </label>
                            <?php endforeach; ?>

                        <?php else: ?>

                            <?php foreach ($question['options'] as $option): ?>
                                <label class="answer-option">
                                    <input
                                        type="radio"
                                        name="answers[<?= e($question['id']) ?>]"
                                        value="<?= e($option) ?>"
                                        <?= (string)($answers[$question['id']] ?? '')
                                            === (string)$option
                                            ? 'checked' : '' ?>
                                    >
                                    <?= e($option) ?>
                                </label>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="card">
            <button class="primary" type="submit">
                回答を確認する
                <span class="spinner">処理中...</span>
            </button>
        </div>
    </form>

    <?php
    page_end();
}

/* ============================================================
 * 回答確認
 * ============================================================ */

function render_confirm(): void
{
    $id = clean_text($_GET['id'] ?? '', 80);
    $survey = get_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    $answers = $_SESSION['answer_state'][$id] ?? [];

    if (!is_array($answers)) {
        redirect_screen('answer', $id);
    }

    page_start('回答確認', false);
    ?>

    <div class="card">
        <h1>回答確認</h1>
        <p>
            <?= e($survey['title']) ?>
        </p>
    </div>

    <?php foreach ($survey['groups'] as $group): ?>
        <div class="card">
            <h2><?= e($group['title']) ?></h2>

            <?php foreach ($group['questions'] as $question): ?>
                <div class="question-card">
                    <strong>
                        <?= e($question['number'] ?? '') ?>
                        <?= e($question['text'] ?? '') ?>
                    </strong>

                    <p>
                        <?php
                        $value =
                            $answers[$question['id']]
                            ?? '';

                        if (is_array($value)) {
                            $value = implode('、', $value);
                        }

                        echo nl2br(e($value));
                        ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <div class="actions">
            <a class="btn secondary"
               href="?screen=answer&id=<?= e($id) ?>">
                回答を修正
            </a>

            <form method="post"
                  data-processing
                  data-confirm="回答を送信します。よろしいですか？">
                <input type="hidden" name="action" value="submit_answer">
                <input type="hidden" name="survey_id" value="<?= e($id) ?>">
                <button class="primary">
                    回答を送信
                    <span class="spinner">送信中...</span>
                </button>
            </form>
        </div>
    </div>

    <?php
    page_end();
}

/* ============================================================
 * 完了
 * ============================================================ */

function render_complete(): void
{
    page_start('回答完了', false);
    ?>

    <div class="card">
        <h1>回答ありがとうございました</h1>
        <p>
            回答を正常に受け付けました。
        </p>
        <p class="small">
            この画面から管理者画面へ移動する導線はありません。
        </p>
    </div>

    <?php
    page_end();
}

/* ============================================================
 * CSVダウンロード判定
 * ============================================================ */

if (
    $screen === 'analytics'
    && isset($_GET['download'])
    && $_GET['download'] === 'csv'
) {
    $id = clean_text($_GET['id'] ?? '', 80);
    csv_download($id);
}

/* ============================================================
 * 画面ルーティング
 * ============================================================ */

switch ($screen) {
    case 'list':
        render_list($operationResult);
        break;

    case 'edit':
        render_edit($operationResult);
        break;

    case 'preview':
        render_preview();
        break;

    case 'send':
        render_send($operationResult);
        break;

    case 'analytics':
        render_analytics();
        break;

    case 'kintone':
        render_kintone($operationResult);
        break;

    case 'mail':
        render_mail($operationResult);
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
        render_list($operationResult);
        break;
}
?>
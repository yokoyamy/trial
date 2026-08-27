<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * 実行環境:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLなし
 *
 * 設計方針:
 *   - index.phpを唯一のWeb入口とする
 *   - 初回起動時にデータ保存先と管理者認証情報を設定する
 *   - 業務データとブートストラップ設定を分離する
 *   - 業務データはWeb公開領域外へ保存する
 *   - 管理者認証はPHPセッションで行う
 *   - POSTには共通CSRF対策を適用する
 *   - GETアクセスによってセッションを破棄しない
 *   - DBは使用しない
 *   - PHP cURLは使用しない
 *
 * 注意:
 *   本ファイルをGitへ登録する場合、
 *   管理者パスワード、kintone認証情報、SMTP認証情報、
 *   ブートストラップ設定等の環境固有情報を
 *   ソースコードへ記述してはならない。
 */


/* =========================================================
 * 1. 基本設定
 * ========================================================= */

const APP_NAME = 'アンケートアプリ';

const SCREEN_SETUP     = 'setup';
const SCREEN_LOGIN     = 'login';
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

const STATUS_DRAFT   = 'draft';
const STATUS_OPEN    = 'open';
const STATUS_STOPPED = 'stopped';
const STATUS_FINISHED = 'finished';

const ANSWER_SINGLE = 'single';
const ANSWER_MULTI  = 'multi';
const ANSWER_TEXT   = 'text';


/* =========================================================
 * 2. ブートストラップ設定
 * =========================================================
 *
 * 重要:
 *   業務データの保存場所は固定相対パスにしない。
 *
 *   初回起動時には、Web公開領域とは別に設置された
 *   「ブートストラップ設定ファイル」を確認する。
 *
 *   実運用では APP_BOOTSTRAP_FILE を環境に合わせて
 *   設定する。
 *
 *   ここだけは「アプリケーションコードの配置場所」と
 *   「業務データの保存場所」を結びつけない。
 *
 *   環境変数 SURVEY_BOOTSTRAP_FILE があれば優先する。
 */

function bootstrapFile(): string
{
    $env = getenv('SURVEY_BOOTSTRAP_FILE');

    if ($env !== false && trim($env) !== '') {
        return trim($env);
    }

    /*
     * 環境変数が指定されていない場合。
     *
     * ここで業務データ保存先を決め打ちしない。
     *
     * 初回起動を可能にするための最小限の設定領域として
     * PHPプロセスから利用可能な場所を使用する。
     *
     * 本番環境ではWeb公開領域外を推奨する。
     */
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'survey-app-bootstrap.php';
}


/* =========================================================
 * 3. セッション
 * ========================================================= */

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (
            isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        )
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

startSession();


/* =========================================================
 * 4. 共通ユーティリティ
 * ========================================================= */

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

function currentScreen(): string
{
    return (string)($_GET['screen'] ?? SCREEN_LIST);
}

function requestMethod(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function post(string $name, mixed $default = null): mixed
{
    return $_POST[$name] ?? $default;
}

function get(string $name, mixed $default = null): mixed
{
    return $_GET[$name] ?? $default;
}

function appUrl(array $params = []): string
{
    return 'index.php' . (
        $params
            ? '?' . http_build_query($params)
            : ''
    );
}


/* =========================================================
 * 5. JSONファイル操作
 * ========================================================= */

function atomicWriteJson(string $file, array $data): void
{
    $dir = dirname($file);

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException(
                '保存先ディレクトリを作成できません。'
            );
        }
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
    );

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException(
            '一時ファイルへ保存できません。'
        );
    }

    chmod($tmp, 0600);

    if (!rename($tmp, $file)) {
        @unlink($tmp);

        throw new RuntimeException(
            '保存ファイルを確定できません。'
        );
    }
}

function readJson(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }

    $fp = fopen($file, 'rb');

    if ($fp === false) {
        throw new RuntimeException(
            'データファイルを開けません。'
        );
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        $contents = stream_get_contents($fp);

        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $decoded = json_decode(
        $contents,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    return is_array($decoded) ? $decoded : $default;
}


/* =========================================================
 * 6. ブートストラップ
 * ========================================================= */

function loadBootstrap(): array
{
    $file = bootstrapFile();

    if (!is_file($file)) {
        return [
            'setup_completed' => false,
        ];
    }

    try {
        return readJson($file, [
            'setup_completed' => false,
        ]);
    } catch (Throwable) {
        return [
            'setup_completed' => false,
        ];
    }
}

function saveBootstrap(array $config): void
{
    $file = bootstrapFile();

    atomicWriteJson($file, $config);

    /*
     * 設定ファイルはPHPから読ませる。
     * Web公開領域に置かれた場合でも、PHPソースとして
     * 解釈されることを想定した名前にする。
     *
     * ただし本番ではWeb公開領域外を必須とする。
     */
    @chmod($file, 0600);
}

$bootstrap = loadBootstrap();


/* =========================================================
 * 7. CSRF
 * ========================================================= */

function csrfToken(): string
{
    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || $_SESSION['csrf_token'] === ''
    ) {
        $_SESSION['csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="'
        . e(csrfToken())
        . '">';
}

function verifyCsrf(): void
{
    if (requestMethod() !== 'POST') {
        return;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? null;
    $postedToken = $_POST['_csrf'] ?? null;

    if (
        !is_string($sessionToken)
        || $sessionToken === ''
        || !is_string($postedToken)
        || $postedToken === ''
        || !hash_equals($sessionToken, $postedToken)
    ) {
        http_response_code(403);

        renderError(
            'CSRF検証に失敗しました。',
            '画面を再読み込みしてから、もう一度操作してください。'
        );

        exit;
    }
}


/* =========================================================
 * 8. 認証
 * ========================================================= */

function isAuthenticated(): bool
{
    return !empty($_SESSION['admin_authenticated']);
}

function requireAdmin(): void
{
    if (!isAuthenticated()) {
        redirect(appUrl([
            'screen' => SCREEN_LOGIN,
        ]));
    }
}

function loginAdmin(): void
{
    session_regenerate_id(true);

    $_SESSION['admin_authenticated'] = true;

    /*
     * セッションIDを再生成した後にCSRFトークンも
     * セッションへ保持する。
     */
    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

function logoutAdmin(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }

    session_destroy();
}


/* =========================================================
 * 9. データ保存先
 * ========================================================= */

function dataDir(): string
{
    global $bootstrap;

    if (
        empty($bootstrap['setup_completed'])
        || empty($bootstrap['data_dir'])
    ) {
        throw new RuntimeException(
            'データ保存先が設定されていません。'
        );
    }

    return (string)$bootstrap['data_dir'];
}

function dataFile(string $name): string
{
    /*
     * ファイル名はアプリケーションが決めた固定値のみ。
     * ユーザー入力をファイル名にしない。
     */
    $allowed = [
        'surveys.json',
        'answers.json',
        'customers.json',
        'mail_logs.json',
        'settings.json',
    ];

    if (!in_array($name, $allowed, true)) {
        throw new InvalidArgumentException(
            '不正なデータファイルです。'
        );
    }

    return rtrim(dataDir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . $name;
}

function initializeDataStore(string $dir): void
{
    if ($dir === '') {
        throw new RuntimeException(
            '保存先を指定してください。'
        );
    }

    /*
     * 相対パスは禁止。
     * 初回セットアップで管理者が明示的に指定した場所を
     * 絶対パスとして扱う。
     */
    if (!str_starts_with($dir, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException(
            'データ保存先は絶対パスで指定してください。'
        );
    }

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true)) {
            throw new RuntimeException(
                '指定された保存先を作成できません。'
            );
        }
    }

    if (!is_readable($dir) || !is_writable($dir)) {
        throw new RuntimeException(
            '指定された保存先を読み書きできません。'
        );
    }

    /*
     * Web公開領域にある可能性を完全自動判定するのは
     * Apacheの構成次第で不確実なので、
     * アプリケーション自身のURL配下を指定した場合は拒否する。
     */
    $documentRoot = realpath(
        (string)($_SERVER['DOCUMENT_ROOT'] ?? '')
    );

    $realDir = realpath($dir);

    if (
        $documentRoot !== false
        && $realDir !== false
        && (
            $realDir === $documentRoot
            || str_starts_with(
                $realDir,
                $documentRoot . DIRECTORY_SEPARATOR
            )
        )
    ) {
        throw new RuntimeException(
            'Web公開領域内はデータ保存先に指定できません。'
        );
    }

    $files = [
        'surveys.json' => [],
        'answers.json' => [],
        'customers.json' => [],
        'mail_logs.json' => [],
        'settings.json' => [],
    ];

    foreach ($files as $file => $default) {
        $path = rtrim($dir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $file;

        if (!is_file($path)) {
            atomicWriteJson($path, $default);
        }
    }
}


/* =========================================================
 * 10. 初期セットアップ
 * ========================================================= */

function setupCompleted(): bool
{
    global $bootstrap;

    return !empty($bootstrap['setup_completed']);
}

function validateSetupInput(): array
{
    $dir = trim((string)post('data_dir', ''));
    $adminId = trim((string)post('admin_id', ''));
    $password = (string)post('admin_password', '');
    $passwordConfirm = (string)post(
        'admin_password_confirm',
        ''
    );

    $errors = [];

    if ($dir === '') {
        $errors[] = 'データ保存先を指定してください。';
    }

    if ($adminId === '') {
        $errors[] = '管理者IDを指定してください。';
    }

    if ($password === '') {
        $errors[] = '管理者パスワードを指定してください。';
    }

    if (strlen($password) < 10) {
        $errors[] =
            '管理者パスワードは10文字以上にしてください。';
    }

    if (!hash_equals($password, $passwordConfirm)) {
        $errors[] =
            '管理者パスワードと確認用パスワードが一致しません。';
    }

    if ($errors) {
        throw new InvalidArgumentException(
            implode("\n", $errors)
        );
    }

    return [
        'data_dir' => $dir,
        'admin_id' => $adminId,
        'admin_password_hash' => password_hash(
            $password,
            PASSWORD_DEFAULT
        ),
    ];
}


/* =========================================================
 * 11. データ取得
 * ========================================================= */

function surveys(): array
{
    return readJson(
        dataFile('surveys.json'),
        []
    );
}

function saveSurveys(array $items): void
{
    atomicWriteJson(
        dataFile('surveys.json'),
        array_values($items)
    );
}

function answers(): array
{
    return readJson(
        dataFile('answers.json'),
        []
    );
}

function saveAnswers(array $items): void
{
    atomicWriteJson(
        dataFile('answers.json'),
        array_values($items)
    );
}

function customers(): array
{
    return readJson(
        dataFile('customers.json'),
        []
    );
}

function saveCustomers(array $items): void
{
    atomicWriteJson(
        dataFile('customers.json'),
        array_values($items)
    );
}

function mailLogs(): array
{
    return readJson(
        dataFile('mail_logs.json'),
        []
    );
}

function saveMailLogs(array $items): void
{
    atomicWriteJson(
        dataFile('mail_logs.json'),
        array_values($items)
    );
}

function settings(): array
{
    return readJson(
        dataFile('settings.json'),
        []
    );
}

function saveSettings(array $items): void
{
    atomicWriteJson(
        dataFile('settings.json'),
        $items
    );
}


/* =========================================================
 * 12. ID生成
 * ========================================================= */

function id(string $prefix): string
{
    return $prefix . '_' . date('YmdHis')
        . '_' . bin2hex(random_bytes(5));
}


/* =========================================================
 * 13. アンケート状態
 * ========================================================= */

function effectiveStatus(array $survey): string
{
    $status = (string)($survey['status'] ?? STATUS_DRAFT);

    if (
        $status === STATUS_OPEN
        && !empty($survey['end_at'])
    ) {
        $end = strtotime((string)$survey['end_at']);

        if ($end !== false && $end < time()) {
            return STATUS_FINISHED;
        }
    }

    return $status;
}

function statusLabel(string $status): string
{
    return match ($status) {
        STATUS_DRAFT => '下書き',
        STATUS_OPEN => '公開中',
        STATUS_STOPPED => '停止',
        STATUS_FINISHED => '終了',
        default => '不明',
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        STATUS_OPEN => 'badge-success',
        STATUS_STOPPED => 'badge-warning',
        STATUS_FINISHED => 'badge-danger',
        default => 'badge-draft',
    };
}


/* =========================================================
 * 14. アンケート検索
 * ========================================================= */

function findSurvey(string $id): ?array
{
    foreach (surveys() as $survey) {
        if (($survey['id'] ?? '') === $id) {
            $survey['status'] = effectiveStatus($survey);
            return $survey;
        }
    }

    return null;
}

function surveyAnswerCount(string $surveyId): int
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
 * 15. 質問番号
 * ========================================================= */

function renumberSurvey(array &$survey): void
{
    $mode = $survey['numbering'] ?? 'global';

    $global = 0;

    foreach ($survey['groups'] as $groupIndex => &$group) {
        $groupNumber = $groupIndex + 1;
        $questionNumber = 0;

        foreach ($group['questions'] as &$question) {
            $global++;
            $questionNumber++;

            if ($mode === 'group') {
                $question['number'] =
                    'Q' . $groupNumber . '-' . $questionNumber;
            } else {
                $question['number'] =
                    'Q' . $global;
            }
        }

        unset($question);
    }

    unset($group);
}


/* =========================================================
 * 16. アンケート初期値
 * ========================================================= */

function emptySurvey(): array
{
    return [
        'id' => id('survey'),
        'title' => '',
        'description' => '',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'start_at' => '',
        'end_at' => '',
        'status' => STATUS_DRAFT,
        'numbering' => 'global',
        'groups' => [
            [
                'id' => id('group'),
                'title' => '基本情報',
                'questions' => [],
            ],
        ],
    ];
}


/* =========================================================
 * 17. 画面共通HTML
 * ========================================================= */

function renderHeader(
    string $title,
    bool $admin = true
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e(APP_NAME) ?></title>

<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --success: #16a34a;
    --warning: #d97706;
    --danger: #dc2626;
    --gray: #64748b;
    --gray-light: #f1f5f9;
    --border: #dbe2ea;
    --text: #1e293b;
    --white: #ffffff;
    --background: #f8fafc;
    --header: #0f172a;
    --shadow: 0 4px 18px rgba(15,23,42,.08);
    --radius: 10px;
}

* { box-sizing: border-box; }

html { font-size: 16px; }

body {
    margin: 0;
    background: var(--background);
    color: var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    line-height: 1.6;
}

a {
    color: var(--primary);
    text-decoration: none;
}

a:hover { text-decoration: underline; }

button,
input,
select,
textarea {
    font: inherit;
}

button { cursor: pointer; }

h1 {
    font-size: 1.65rem;
    margin: 0 0 6px;
}

h2 {
    font-size: 1.2rem;
    margin: 0 0 18px;
}

h3 {
    font-size: 1rem;
    margin: 0 0 12px;
}

.app-header {
    background: var(--header);
    color: var(--white);
    min-height: 64px;
    display: flex;
    align-items: center;
    padding: 0 24px;
}

.brand {
    color: #fff;
    font-weight: 700;
    font-size: 1.1rem;
}

.nav {
    margin-left: auto;
    display: flex;
    gap: 8px;
    align-items: center;
}

.nav a {
    color: #cbd5e1;
    padding: 8px 12px;
    border-radius: 6px;
}

.nav a:hover,
.nav a.active {
    color: #fff;
    background: rgba(255,255,255,.08);
    text-decoration: none;
}

.container {
    width: min(1200px, calc(100% - 32px));
    margin: 0 auto;
    padding: 28px 0 48px;
}

.auth-container {
    width: min(520px, calc(100% - 32px));
    margin: 0 auto;
    padding: 48px 0;
}

.card,
.auth-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 22px;
    margin-bottom: 20px;
}

.page-title {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 22px;
}

.form-row {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-bottom: 18px;
}

.form-row label {
    font-weight: 600;
}

input[type=text],
input[type=search],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
select,
textarea {
    width: 100%;
    min-height: 42px;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: #fff;
    color: var(--text);
}

textarea {
    min-height: 140px;
    resize: vertical;
}

input:focus,
select:focus,
textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 40px;
    padding: 8px 14px;
    border: 1px solid transparent;
    border-radius: 7px;
    font-weight: 600;
    line-height: 1.3;
    white-space: nowrap;
}

.btn:hover { text-decoration: none; }

.btn-primary {
    background: var(--primary);
    color: #fff;
}

.btn-primary:hover {
    background: var(--primary-dark);
}

.btn-secondary {
    background: #fff;
    color: var(--text);
    border-color: var(--border);
}

.btn-secondary:hover {
    background: var(--gray-light);
}

.btn-success {
    background: var(--success);
    color: #fff;
}

.btn-warning {
    background: var(--warning);
    color: #fff;
}

.btn-danger {
    background: var(--danger);
    color: #fff;
}

.btn-sm {
    min-height: 34px;
    padding: 6px 10px;
    font-size: .875rem;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.searchbar {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
}

.search-input { flex: 1; }

.table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: middle;
}

th {
    background: var(--gray-light);
    font-size: .875rem;
    white-space: nowrap;
}

.badge {
    display: inline-flex;
    align-items: center;
    min-height: 26px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 700;
    white-space: nowrap;
}

.badge-success {
    background: #dcfce7;
    color: #166534;
}

.badge-warning {
    background: #fef3c7;
    color: #92400e;
}

.badge-danger {
    background: #fee2e2;
    color: #991b1b;
}

.badge-draft {
    background: #e0e7ff;
    color: #3730a3;
}

.muted { color: var(--gray); }

.alert {
    padding: 13px 15px;
    border-radius: 8px;
    margin-bottom: 18px;
    white-space: pre-line;
}

.alert-danger {
    background: #fee2e2;
    color: #991b1b;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
}

.alert-warning {
    background: #fef3c7;
    color: #92400e;
}

.empty {
    padding: 34px 20px;
    text-align: center;
    color: var(--gray);
    background: #f8fafc;
    border: 1px dashed var(--border);
    border-radius: 8px;
}

.grid {
    display: grid;
    gap: 18px;
}

.grid-2 {
    grid-template-columns: repeat(2,minmax(0,1fr));
}

.group-card {
    border: 1px solid var(--border);
    border-radius: 9px;
    overflow: hidden;
    margin-bottom: 18px;
}

.group-header {
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 14px 16px;
    background: var(--gray-light);
    border-bottom: 1px solid var(--border);
}

.group-title {
    flex: 1;
}

.group-body {
    padding: 16px;
}

.question-card {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
}

.question-header {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 14px;
}

.question-number {
    color: var(--primary);
    font-weight: 700;
}

.choice-list {
    display: grid;
    gap: 10px;
}

.choice {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 7px;
}

@media(max-width:720px) {
    .app-header {
        min-height: auto;
        padding: 12px 16px;
        flex-wrap: wrap;
    }

    .nav {
        width: 100%;
        margin-left: 0;
        overflow-x: auto;
    }

    .container {
        width: calc(100% - 20px);
        padding-top: 18px;
    }

    .page-title {
        flex-direction: column;
    }

    .grid-2 {
        grid-template-columns: 1fr;
    }

    .searchbar {
        flex-direction: column;
    }

    .searchbar .btn {
        width: 100%;
    }

    .auth-container {
        width: calc(100% - 20px);
        padding-top: 24px;
    }
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header class="app-header">
    <a class="brand"
       href="<?= e(appUrl(['screen' => SCREEN_LIST])) ?>">
        <?= e(APP_NAME) ?>
    </a>

    <?php if (isAuthenticated()): ?>
    <nav class="nav">
        <a href="<?= e(appUrl(['screen' => SCREEN_LIST])) ?>">
            アンケート
        </a>
        <a href="<?= e(appUrl(['screen' => SCREEN_KINTONE])) ?>">
            kintone
        </a>
        <a href="<?= e(appUrl(['screen' => SCREEN_MAIL])) ?>">
            メール
        </a>
        <a href="<?= e(appUrl(['action' => 'logout'])) ?>">
            ログアウト
        </a>
    </nav>
    <?php endif; ?>
</header>
<?php endif; ?>
<?php
}

function renderFooter(): void
{
    ?>
</body>
</html>
<?php
}


/* =========================================================
 * 18. エラー画面
 * ========================================================= */

function renderError(
    string $title,
    string $message
): void {
    renderHeader($title);

    ?>
<div class="container">
    <div class="card">
        <h1><?= e($title) ?></h1>

        <div class="alert alert-danger">
            <?= e($message) ?>
        </div>

        <a class="btn btn-secondary"
           href="<?= e(appUrl()) ?>">
            戻る
        </a>
    </div>
</div>
<?php

    renderFooter();
}


/* =========================================================
 * 19. 初回セットアップ画面
 * ========================================================= */

function renderSetup(?string $error = null): void
{
    renderHeader('初回セットアップ', false);

    ?>
<div class="auth-container">
    <div class="auth-card">

        <h1>初回セットアップ</h1>

        <p class="muted">
            アンケートアプリを利用するための
            初期設定を行います。
        </p>

        <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
        <?php endif; ?>

        <form method="post"
              action="<?= e(appUrl(['screen' => SCREEN_SETUP])) ?>">

            <?= csrfField() ?>

            <div class="form-row">
                <label for="data_dir">
                    データ保存先
                </label>

                <input
                    id="data_dir"
                    type="text"
                    name="data_dir"
                    placeholder="/var/lib/survey-app/data"
                    required
                >

                <small class="muted">
                    絶対パスで指定してください。
                    Web公開ディレクトリは指定できません。
                </small>
            </div>

            <div class="form-row">
                <label for="admin_id">
                    管理者ID
                </label>

                <input
                    id="admin_id"
                    type="text"
                    name="admin_id"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="form-row">
                <label for="admin_password">
                    管理者パスワード
                </label>

                <input
                    id="admin_password"
                    type="password"
                    name="admin_password"
                    autocomplete="new-password"
                    required
                >
            </div>

            <div class="form-row">
                <label for="admin_password_confirm">
                    管理者パスワード（確認）
                </label>

                <input
                    id="admin_password_confirm"
                    type="password"
                    name="admin_password_confirm"
                    autocomplete="new-password"
                    required
                >
            </div>

            <div class="actions">
                <button
                    class="btn btn-primary"
                    type="submit">
                    初期設定を完了する
                </button>
            </div>
        </form>
    </div>
</div>
<?php

    renderFooter();
}


/* =========================================================
 * 20. ログイン
 * ========================================================= */

function renderLogin(?string $error = null): void
{
    renderHeader('管理者ログイン', false);

    ?>
<div class="auth-container">
    <div class="auth-card">

        <h1>管理者ログイン</h1>

        <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
        <?php endif; ?>

        <form method="post"
              action="<?= e(appUrl(['screen' => SCREEN_LOGIN])) ?>">

            <?= csrfField() ?>

            <div class="form-row">
                <label for="admin_id">
                    管理者ID
                </label>

                <input
                    id="admin_id"
                    type="text"
                    name="admin_id"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="form-row">
                <label for="admin_password">
                    パスワード
                </label>

                <input
                    id="admin_password"
                    type="password"
                    name="admin_password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button
                class="btn btn-primary"
                type="submit">
                ログイン
            </button>
        </form>
    </div>
</div>
<?php

    renderFooter();
}


/* =========================================================
 * 21. アンケート一覧
 * ========================================================= */

function renderList(): void
{
    requireAdmin();

    $keyword = trim((string)get('q', ''));
    $filter = (string)get('status', 'all');
    $sort = (string)get('sort', 'updated_desc');

    $items = surveys();

    foreach ($items as &$survey) {
        $survey['status'] =
            effectiveStatus($survey);
    }

    unset($survey);

    $items = array_filter(
        $items,
        function (array $survey) use (
            $keyword,
            $filter
        ): bool {
            if (
                $keyword !== ''
                && mb_stripos(
                    (string)($survey['title'] ?? ''),
                    $keyword
                ) === false
            ) {
                return false;
            }

            if (
                $filter !== 'all'
                && ($survey['status'] ?? '') !== $filter
            ) {
                return false;
            }

            return true;
        }
    );

    usort(
        $items,
        function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)($a['updated_at'] ?? ''),
                        (string)($b['updated_at'] ?? '')
                    ),

                'answers_desc' =>
                    surveyAnswerCount((string)$b['id'])
                    <=>
                    surveyAnswerCount((string)$a['id']),

                'answers_asc' =>
                    surveyAnswerCount((string)$a['id'])
                    <=>
                    surveyAnswerCount((string)$b['id']),

                'start_desc' =>
                    strcmp(
                        (string)($b['start_at'] ?? ''),
                        (string)($a['start_at'] ?? '')
                    ),

                'start_asc' =>
                    strcmp(
                        (string)($a['start_at'] ?? ''),
                        (string)($b['start_at'] ?? '')
                    ),

                default =>
                    strcmp(
                        (string)($b['updated_at'] ?? ''),
                        (string)($a['updated_at'] ?? '')
                    ),
            };
        }
    );

    renderHeader('アンケート一覧');

    ?>
<div class="container">

    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <div class="muted">
                アンケートの作成・編集・送信・集計を行います。
            </div>
        </div>

        <div class="actions">
            <a class="btn btn-primary"
               href="<?= e(appUrl([
                   'screen' => SCREEN_EDIT,
               ])) ?>">
                新規作成
            </a>
        </div>
    </div>

    <div class="card">

        <form class="searchbar"
              method="get">

            <input
                type="hidden"
                name="screen"
                value="list">

            <input
                class="search-input"
                type="search"
                name="q"
                value="<?= e($keyword) ?>"
                placeholder="タイトルで検索">

            <select name="status">
                <option value="all"
                    <?= $filter === 'all' ? 'selected' : '' ?>>
                    すべて
                </option>

                <option value="open"
                    <?= $filter === STATUS_OPEN ? 'selected' : '' ?>>
                    公開中
                </option>

                <option value="draft"
                    <?= $filter === STATUS_DRAFT ? 'selected' : '' ?>>
                    下書き
                </option>

                <option value="stopped"
                    <?= $filter === STATUS_STOPPED ? 'selected' : '' ?>>
                    停止
                </option>

                <option value="finished"
                    <?= $filter === STATUS_FINISHED ? 'selected' : '' ?>>
                    終了
                </option>
            </select>

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

            <button class="btn btn-secondary"
                    type="submit">
                検索
            </button>
        </form>

        <?php if (!$items): ?>

        <div class="empty">
            アンケートはありません。
        </div>

        <?php else: ?>

        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>タイトル</th>
                <th>作成日</th>
                <th>更新日</th>
                <th>開始日時</th>
                <th>終了日時</th>
                <th>状態</th>
                <th>回答数</th>
                <th>操作</th>
            </tr>
            </thead>

            <tbody>

            <?php foreach ($items as $survey): ?>

            <tr>
                <td>
                    <strong>
                        <?= e($survey['title'] ?: '無題') ?>
                    </strong>
                </td>

                <td>
                    <?= e($survey['created_at'] ?? '') ?>
                </td>

                <td>
                    <?= e($survey['updated_at'] ?? '') ?>
                </td>

                <td>
                    <?= e($survey['start_at'] ?? '') ?>
                </td>

                <td>
                    <?= e($survey['end_at'] ?? '') ?>
                </td>

                <td>
                    <span class="badge
                        <?= e(statusClass(
                            (string)$survey['status']
                        )) ?>">
                        <?= e(statusLabel(
                            (string)$survey['status']
                        )) ?>
                    </span>
                </td>

                <td>
                    <?= e(
                        surveyAnswerCount(
                            (string)$survey['id']
                        )
                    ) ?>
                </td>

                <td>
                    <div class="actions">

                        <a class="btn btn-secondary btn-sm"
                           href="<?= e(appUrl([
                               'screen' => SCREEN_EDIT,
                               'id' => $survey['id'],
                           ])) ?>">
                            編集
                        </a>

                        <a class="btn btn-secondary btn-sm"
                           href="<?= e(appUrl([
                               'screen' => SCREEN_PREVIEW,
                               'id' => $survey['id'],
                           ])) ?>">
                            プレビュー
                        </a>

                        <a class="btn btn-secondary btn-sm"
                           href="<?= e(appUrl([
                               'screen' => SCREEN_ANALYTICS,
                               'id' => $survey['id'],
                           ])) ?>">
                            集計
                        </a>

                        <a class="btn btn-secondary btn-sm"
                           href="<?= e(appUrl([
                               'screen' => SCREEN_SEND,
                               'id' => $survey['id'],
                           ])) ?>">
                            送信
                        </a>

                        <form method="post"
                              action="<?= e(appUrl([
                                  'action' => 'duplicate',
                              ])) ?>"
                              onsubmit="return confirm('このアンケートを複製しますか？');">

                            <?= csrfField() ?>

                            <input type="hidden"
                                   name="id"
                                   value="<?= e($survey['id']) ?>">

                            <button
                                class="btn btn-secondary btn-sm"
                                type="submit">
                                複製
                            </button>
                        </form>

                        <form method="post"
                              action="<?= e(appUrl([
                                  'action' => 'delete',
                              ])) ?>"
                              onsubmit="return confirm('削除しますか？');">

                            <?= csrfField() ?>

                            <input type="hidden"
                                   name="id"
                                   value="<?= e($survey['id']) ?>">

                            <button
                                class="btn btn-danger btn-sm"
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

        <?php endif; ?>
    </div>
</div>
<?php

    renderFooter();
}


/* =========================================================
 * 22. アンケート編集
 * ========================================================= */

function renderEdit(?string $id = null): void
{
    requireAdmin();

    $survey = $id
        ? findSurvey($id)
        : emptySurvey();

    if ($id && $survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '指定されたアンケートは存在しません。'
        );
        return;
    }

    renderHeader(
        $id ? 'アンケート編集' : 'アンケート作成'
    );

    ?>
<div class="container">

<div class="page-title">
    <div>
        <h1>
            <?= $id ? 'アンケート編集' : 'アンケート作成' ?>
        </h1>
    </div>

    <div class="actions">
        <a class="btn btn-secondary"
           href="<?= e(appUrl([
               'screen' => SCREEN_LIST,
           ])) ?>">
            キャンセル
        </a>

        <a class="btn btn-secondary"
           href="<?= e(appUrl([
               'screen' => SCREEN_PREVIEW,
               'id' => $survey['id'],
           ])) ?>">
            プレビュー
        </a>
    </div>
</div>

<form method="post"
      action="<?= e(appUrl([
          'action' => 'save_survey',
      ])) ?>">

    <?= csrfField() ?>

    <input type="hidden"
           name="id"
           value="<?= e($survey['id']) ?>">

    <div class="card">

        <div class="form-row">
            <label for="title">
                タイトル
            </label>

            <input
                id="title"
                type="text"
                name="title"
                value="<?= e($survey['title']) ?>"
                required>
        </div>

        <div class="form-row">
            <label for="description">
                説明
            </label>

            <textarea
                id="description"
                name="description"><?= e(
                    $survey['description']
                ) ?></textarea>
        </div>

        <div class="grid grid-2">

            <div class="form-row">
                <label for="start_at">
                    開始日時
                </label>

                <input
                    id="start_at"
                    type="datetime-local"
                    name="start_at"
                    value="<?= e(
                        $survey['start_at']
                    ) ?>">
            </div>

            <div class="form-row">
                <label for="end_at">
                    終了日時
                </label>

                <input
                    id="end_at"
                    type="datetime-local"
                    name="end_at"
                    value="<?= e(
                        $survey['end_at']
                    ) ?>">
            </div>

        </div>

        <div class="form-row">
            <label for="numbering">
                質問番号
            </label>

            <select id="numbering"
                    name="numbering">

                <option value="global"
                    <?= ($survey['numbering'] ?? '')
                        === 'global'
                        ? 'selected'
                        : '' ?>>
                    アンケート全体で通番
                </option>

                <option value="group"
                    <?= ($survey['numbering'] ?? '')
                        === 'group'
                        ? 'selected'
                        : '' ?>>
                    グループ単位で採番
                </option>

            </select>
        </div>
    </div>


    <?php foreach ($survey['groups'] as $gi => $group): ?>

    <div class="group-card">

        <div class="group-header">

            <strong>
                グループ <?= e($gi + 1) ?>
            </strong>

            <input
                class="group-title"
                type="text"
                name="groups[<?= e($gi) ?>][title]"
                value="<?= e($group['title']) ?>"
                placeholder="グループタイトル">

            <button
                class="btn btn-danger btn-sm"
                type="button"
                onclick="removeGroup(this)">
                グループ削除
            </button>

        </div>

        <div class="group-body">

        <?php foreach (
            $group['questions'] as $qi => $question
        ): ?>

            <div class="question-card">

                <div class="question-header">

                    <span class="question-number">
                        <?= e(
                            $question['number']
                            ?? 'Q?'
                        ) ?>
                    </span>

                    <strong>
                        質問
                    </strong>

                    <button
                        class="btn btn-danger btn-sm"
                        type="button"
                        onclick="removeQuestion(this)">
                        削除
                    </button>

                </div>

                <div class="form-row">

                    <label>
                        質問文
                    </label>

                    <textarea
                        name="groups[<?= e($gi) ?>][questions][<?= e($qi) ?>][text]"
                        required><?= e(
                            $question['text'] ?? ''
                        ) ?></textarea>
                </div>

                <div class="grid grid-2">

                    <div class="form-row">

                        <label>
                            回答形式
                        </label>

                        <select
                            name="groups[<?= e($gi) ?>][questions][<?= e($qi) ?>][type]">

                            <option value="single"
                                <?= ($question['type'] ?? '')
                                    === ANSWER_SINGLE
                                    ? 'selected'
                                    : '' ?>>
                                単一選択
                            </option>

                            <option value="multi"
                                <?= ($question['type'] ?? '')
                                    === ANSWER_MULTI
                                    ? 'selected'
                                    : '' ?>>
                                複数選択
                            </option>

                            <option value="text"
                                <?= ($question['type'] ?? '')
                                    === ANSWER_TEXT
                                    ? 'selected'
                                    : '' ?>>
                                自由記述
                            </option>

                        </select>
                    </div>

                    <div class="form-row">

                        <label>
                            必須
                        </label>

                        <label class="checkbox">
                            <input
                                type="checkbox"
                                name="groups[<?= e($gi) ?>][questions][<?= e($qi) ?>][required]"
                                value="1"
                                <?= !empty(
                                    $question['required']
                                ) ? 'checked' : '' ?>>
                            必須回答
                        </label>

                    </div>
                </div>

                <div class="form-row">

                    <label>
                        選択肢
                    </label>

                    <textarea
                        name="groups[<?= e($gi) ?>][questions][<?= e($qi) ?>][choices]"
                        placeholder="1行に1選択肢"><?= e(
                            implode(
                                "\n",
                                $question['choices'] ?? []
                            )
                        ) ?></textarea>

                    <small class="muted">
                        単一選択・複数選択の場合のみ使用します。
                    </small>
                </div>

            </div>

        <?php endforeach; ?>

        <button
            class="btn btn-secondary"
            type="button"
            onclick="addQuestion(this)">
            質問を追加
        </button>

        </div>
    </div>

    <?php endforeach; ?>


    <div class="actions">
        <button
            class="btn btn-secondary"
            type="button"
            onclick="addGroup()">
            グループを追加
        </button>

        <button
            class="btn btn-primary"
            type="submit">
            保存して一覧へ
        </button>
    </div>

</form>
</div>


<script>
function removeQuestion(button) {
    const card = button.closest('.question-card');

    if (!card) {
        return;
    }

    if (!confirm('この質問を削除しますか？')) {
        return;
    }

    card.remove();
}

function removeGroup(button) {
    const group = button.closest('.group-card');

    if (!group) {
        return;
    }

    if (!confirm('このグループを削除しますか？')) {
        return;
    }

    group.remove();
}

function addQuestion(button) {
    const body = button.closest('.group-body');

    if (!body) {
        return;
    }

    const groupCard = button.closest('.group-card');
    const groupIndex =
        [...document.querySelectorAll('.group-card')]
        .indexOf(groupCard);

    const questionCards =
        body.querySelectorAll('.question-card');

    const questionIndex = questionCards.length;

    const div = document.createElement('div');

    div.className = 'question-card';

    div.innerHTML = `
        <div class="question-header">
            <span class="question-number">Q?</span>
            <strong>質問</strong>
            <button
                class="btn btn-danger btn-sm"
                type="button"
                onclick="removeQuestion(this)">
                削除
            </button>
        </div>

        <div class="form-row">
            <label>質問文</label>
            <textarea
                name="groups[${groupIndex}][questions][${questionIndex}][text]"
                required></textarea>
        </div>

        <div class="grid grid-2">
            <div class="form-row">
                <label>回答形式</label>
                <select
                    name="groups[${groupIndex}][questions][${questionIndex}][type]">
                    <option value="single">単一選択</option>
                    <option value="multi">複数選択</option>
                    <option value="text">自由記述</option>
                </select>
            </div>

            <div class="form-row">
                <label>必須</label>
                <label class="checkbox">
                    <input
                        type="checkbox"
                        name="groups[${groupIndex}][questions][${questionIndex}][required]"
                        value="1">
                    必須回答
                </label>
            </div>
        </div>

        <div class="form-row">
            <label>選択肢</label>
            <textarea
                name="groups[${groupIndex}][questions][${questionIndex}][choices]"
                placeholder="1行に1選択肢"></textarea>
        </div>
    `;

    body.insertBefore(div, button);
}

function addGroup() {
    const form = document.querySelector('form[action*="save_survey"]');

    if (!form) {
        return;
    }

    const groups =
        form.querySelectorAll('.group-card');

    const groupIndex = groups.length;

    const group = document.createElement('div');

    group.className = 'group-card';

    group.innerHTML = `
        <div class="group-header">
            <strong>グループ ${groupIndex + 1}</strong>

            <input
                class="group-title"
                type="text"
                name="groups[${groupIndex}][title]"
                value=""
                placeholder="グループタイトル">

            <button
                class="btn btn-danger btn-sm"
                type="button"
                onclick="removeGroup(this)">
                グループ削除
            </button>
        </div>

        <div class="group-body">

            <button
                class="btn btn-secondary"
                type="button"
                onclick="addQuestion(this)">
                質問を追加
            </button>

        </div>
    `;

    const actions = form.querySelector('.actions');

    form.insertBefore(group, actions);
}
</script>
<?php

    renderFooter();
}


/* =========================================================
 * 23. プレビュー
 * ========================================================= */

function renderPreview(string $id): void
{
    requireAdmin();

    $survey = findSurvey($id);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '指定されたアンケートは存在しません。'
        );
        return;
    }

    renderHeader('プレビュー');

    ?>
<div class="container">

    <div class="page-title">
        <div>
            <h1><?= e($survey['title']) ?></h1>

            <?php if ($survey['description']): ?>
            <div class="muted">
                <?= nl2br(e(
                    $survey['description']
                )) ?>
            </div>
            <?php endif; ?>
        </div>

        <a class="btn btn-secondary"
           href="<?= e(appUrl([
               'screen' => SCREEN_EDIT,
               'id' => $survey['id'],
           ])) ?>">
            編集へ戻る
        </a>
    </div>

    <?php foreach ($survey['groups'] as $group): ?>

    <div class="card">

        <h2><?= e($group['title']) ?></h2>

        <?php foreach ($group['questions'] as $question): ?>

        <div class="question-card">

            <div class="question-header">
                <span class="question-number">
                    <?= e($question['number']) ?>
                </span>

                <strong>
                    <?= e($question['text']) ?>
                </strong>

                <?php if (!empty($question['required'])): ?>
                <span class="badge badge-danger">
                    必須
                </span>
                <?php endif; ?>
            </div>

            <?php if (
                ($question['type'] ?? '')
                === ANSWER_SINGLE
                || ($question['type'] ?? '')
                === ANSWER_MULTI
            ): ?>

            <div class="choice-list">

                <?php foreach (
                    $question['choices'] ?? []
                    as $choice
                ): ?>

                <label class="choice">

                    <input
                        type="<?= ($question['type'] ?? '')
                            === ANSWER_SINGLE
                            ? 'radio'
                            : 'checkbox' ?>"
                        disabled>

                    <span><?= e($choice) ?></span>

                </label>

                <?php endforeach; ?>

            </div>

            <?php else: ?>

            <textarea disabled
                      placeholder="回答欄"></textarea>

            <?php endif; ?>

        </div>

        <?php endforeach; ?>

    </div>

    <?php endforeach; ?>

</div>
<?php

    renderFooter();
}


/* =========================================================
 * 24. 回答画面
 * ========================================================= */

function renderAnswer(string $id): void
{
    $survey = findSurvey($id);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '指定されたアンケートは存在しません。'
        );
        return;
    }

    if ($survey['status'] !== STATUS_OPEN) {
        renderError(
            '回答できません。',
            'このアンケートは現在回答を受け付けていません。'
        );
        return;
    }

    renderHeader(
        (string)$survey['title'],
        false
    );

    ?>
<div class="answer-container">

    <div class="answer-card">

        <h1><?= e($survey['title']) ?></h1>

        <?php if ($survey['description']): ?>
        <p>
            <?= nl2br(e(
                $survey['description']
            )) ?>
        </p>
        <?php endif; ?>

    </div>

<form method="post"
      action="<?= e(appUrl([
          'action' => 'prepare_answer',
      ])) ?>">

    <?= csrfField() ?>

    <input type="hidden"
           name="survey_id"
           value="<?= e($survey['id']) ?>">

    <?php foreach ($survey['groups'] as $group): ?>

    <div class="answer-card">

        <h2><?= e($group['title']) ?></h2>

        <?php foreach (
            $group['questions']
            as $question
        ): ?>

        <div class="question-card">

            <div class="question-header">
                <span class="question-number">
                    <?= e($question['number']) ?>
                </span>

                <strong>
                    <?= e($question['text']) ?>
                </strong>

                <?php if (!empty($question['required'])): ?>
                <span class="badge badge-danger">
                    必須
                </span>
                <?php endif; ?>
            </div>

            <?php if (
                ($question['type'] ?? '')
                === ANSWER_SINGLE
            ): ?>

                <div class="choice-list">

                <?php foreach (
                    $question['choices'] ?? []
                    as $ci => $choice
                ): ?>

                <label class="choice">

                    <input
                        type="radio"
                        name="answers[<?= e($question['id']) ?>]"
                        value="<?= e($ci) ?>"
                        <?= !empty(
                            $question['required']
                        ) ? 'required' : '' ?>>

                    <span><?= e($choice) ?></span>

                </label>

                <?php endforeach; ?>

                </div>

            <?php elseif (
                ($question['type'] ?? '')
                === ANSWER_MULTI
            ): ?>

                <div class="choice-list">

                <?php foreach (
                    $question['choices'] ?? []
                    as $ci => $choice
                ): ?>

                <label class="choice">

                    <input
                        type="checkbox"
                        name="answers[<?= e($question['id']) ?>][]"
                        value="<?= e($ci) ?>">

                    <span><?= e($choice) ?></span>

                </label>

                <?php endforeach; ?>

                </div>

            <?php else: ?>

                <textarea
                    name="answers[<?= e($question['id']) ?>]"
                    <?= !empty(
                        $question['required']
                    ) ? 'required' : '' ?>></textarea>

            <?php endif; ?>

        </div>

        <?php endforeach; ?>

    </div>

    <?php endforeach; ?>

    <div class="actions">

        <button
            class="btn btn-primary"
            type="submit">
            回答を確認する
        </button>

    </div>

</form>
</div>
<?php

    renderFooter();
}


/* =========================================================
 * 25. 回答確認
 * ========================================================= */

function renderConfirm(): void
{
    $surveyId =
        (string)($_SESSION['answer_draft']['survey_id'] ?? '');

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        renderError(
            '回答を確認できません。',
            '回答情報が見つかりません。'
        );
        return;
    }

    $submitted =
        $_SESSION['answer_draft']['answers'] ?? [];

    renderHeader(
        '回答確認',
        false
    );

    ?>
<div class="answer-container">

<div class="answer-card">

<h1>回答確認</h1>

<p class="muted">
    内容を確認して送信してください。
</p>

<?php foreach ($survey['groups'] as $group): ?>

<h2><?= e($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<?php
$value =
    $submitted[$question['id']] ?? '';

if (is_array($value)) {
    $display = [];

    foreach ($value as $index) {
        if (isset($question['choices'][$index])) {
            $display[] =
                $question['choices'][$index];
        }
    }

    $value = implode('、', $display);
} elseif (
    ($question['type'] ?? '') !== ANSWER_TEXT
    && $value !== ''
    && isset($question['choices'][$value])
) {
    $value = $question['choices'][$value];
}
?>

<div class="question-card">

    <div class="question-header">
        <span class="question-number">
            <?= e($question['number']) ?>
        </span>

        <strong>
            <?= e($question['text']) ?>
        </strong>
    </div>

    <div>
        <?= nl2br(e((string)$value)) ?>
    </div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="actions">

<form method="get"
      action="index.php">
    <input type="hidden"
           name="screen"
           value="<?= e(SCREEN_ANSWER) ?>">
    <input type="hidden"
           name="id"
           value="<?= e($surveyId) ?>">

    <button
        class="btn btn-secondary"
        type="submit">
        修正する
    </button>
</form>

<form method="post"
      action="<?= e(appUrl([
          'action' => 'submit_answer',
      ])) ?>">

    <?= csrfField() ?>

    <button
        class="btn btn-primary"
        type="submit">
        回答を送信する
    </button>

</form>

</div>

</div>
</div>
<?php

    renderFooter();
}


/* =========================================================
 * 26. 回答完了
 * ========================================================= */

function renderComplete(): void
{
    renderHeader(
        '回答完了',
        false
    );

    ?>
<div class="answer-container">

<div class="answer-card">

<h1>回答ありがとうございました。</h1>

<p>
    回答を正常に受け付けました。
</p>

</div>

</div>
<?php

    renderFooter();
}


/* =========================================================
 * 27. 集計
 * ========================================================= */

function renderAnalytics(string $id): void
{
    requireAdmin();

    $survey = findSurvey($id);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '指定されたアンケートは存在しません。'
        );
        return;
    }

    $items = array_values(
        array_filter(
            answers(),
            fn(array $answer): bool =>
                ($answer['survey_id'] ?? '') === $id
        )
    );

    renderHeader('回答集計・分析');

    ?>
<div class="container">

<div class="page-title">

<div>
    <h1>回答集計・分析</h1>

    <div class="muted">
        <?= e($survey['title']) ?>
    </div>
</div>

<div class="actions">

<a class="btn btn-secondary"
   href="<?= e(appUrl([
       'screen' => SCREEN_LIST,
   ])) ?>">
    一覧へ戻る
</a>

</div>

</div>

<div class="card">

<div class="grid grid-2">

<div>
    <strong>回答数</strong>
    <div>
        <?= e(count($items)) ?>
    </div>
</div>

<div>
    <strong>未登録回答数</strong>
    <div>
        <?php
        $unregistered = 0;

        foreach ($items as $answer) {
            if (empty($answer['customer_id'])) {
                $unregistered++;
            }
        }

        echo e($unregistered);
        ?>
    </div>
</div>

</div>

</div>

<?php if (!$items): ?>

<div class="card">

<div class="empty">
    現在、回答データはありません
</div>

</div>

<?php else: ?>

<?php foreach ($survey['groups'] as $group): ?>

<div class="card">

<h2><?= e($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<?php
$counts = [];

foreach ($question['choices'] ?? [] as $index => $choice) {
    $counts[$index] = 0;
}

foreach ($items as $answer) {
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
        $value !== null
        && isset($counts[$value])
    ) {
        $counts[$value]++;
    }
}
?>

<div class="question-card">

<div class="question-header">

<span class="question-number">
    <?= e($question['number']) ?>
</span>

<strong>
    <?= e($question['text']) ?>
</strong>

</div>

<?php if ($question['type'] !== ANSWER_TEXT): ?>

<div class="table-wrap">

<table>

<thead>
<tr>
    <th>選択肢</th>
    <th>回答数</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $question['choices'] ?? []
    as $index => $choice
): ?>

<tr>
    <td><?= e($choice) ?></td>
    <td><?= e($counts[$index] ?? 0) ?></td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

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
    <th>回答日時</th>
    <th>回答内容</th>
</tr>
</thead>

<tbody>

<?php foreach ($items as $answer): ?>

<tr>

<td>
    <?= e($answer['created_at'] ?? '') ?>
</td>

<td>
    <details>
        <summary>表示</summary>

        <pre><?= e(
            json_encode(
                $answer['answers'] ?? [],
                JSON_UNESCAPED_UNICODE
                | JSON_PRETTY_PRINT
            )
        ) ?></pre>
    </details>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<?php endif; ?>

</div>
<?php

    renderFooter();
}


/* =========================================================
 * 28. kintone設定
 * ========================================================= */

function renderKintone(): void
{
    requireAdmin();

    $config =
        settings()['kintone'] ?? [];

    renderHeader('kintone連携設定');

    ?>
<div class="container">

<div class="page-title">
    <div>
        <h1>kintone連携設定</h1>
        <div class="muted">
            顧客情報の取得元を設定します。
        </div>
    </div>
</div>

<div class="card">

<form method="post"
      action="<?= e(appUrl([
          'action' => 'save_kintone',
      ])) ?>">

<?= csrfField() ?>

<div class="form-row">
    <label>サブドメイン</label>
    <input
        type="text"
        name="subdomain"
        value="<?= e(
            $config['subdomain'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>顧客管理アプリID</label>
    <input
        type="number"
        name="app_id"
        value="<?= e(
            $config['app_id'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>ログイン名</label>
    <input
        type="text"
        name="username"
        value="<?= e(
            $config['username'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>パスワード</label>
    <input
        type="password"
        name="password"
        autocomplete="new-password">
</div>

<div class="form-row">
    <label>Proxy</label>
    <input
        type="text"
        name="proxy"
        value="<?= e(
            $config['proxy'] ?? ''
        ) ?>"
        placeholder="host:port">
</div>

<div class="form-row">

<label>SSL証明書検証</label>

<label class="checkbox">
    <input
        type="checkbox"
        name="verify_ssl"
        value="1"
        <?= !isset($config['verify_ssl'])
            || !empty($config['verify_ssl'])
            ? 'checked'
            : '' ?>>
    有効
</label>

</div>

<button
    class="btn btn-primary"
    type="submit">
    設定を保存
</button>

</form>

</div>

</div>
<?php

    renderFooter();
}


/* =========================================================
 * 29. メール設定
 * ========================================================= */

function renderMail(): void
{
    requireAdmin();

    $config =
        settings()['mail'] ?? [];

    renderHeader('メールサーバ設定');

    ?>
<div class="container">

<div class="page-title">
    <div>
        <h1>メールサーバ設定</h1>
    </div>
</div>

<div class="card">

<form method="post"
      action="<?= e(appUrl([
          'action' => 'save_mail',
      ])) ?>">

<?= csrfField() ?>

<div class="form-row">
    <label>SMTPサーバ</label>
    <input
        type="text"
        name="host"
        value="<?= e(
            $config['host'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>SMTPポート</label>
    <input
        type="number"
        name="port"
        value="<?= e(
            $config['port'] ?? 587
        ) ?>">
</div>

<div class="form-row">
    <label>暗号化方式</label>

    <select name="encryption">

        <option value="none"
            <?= ($config['encryption'] ?? '')
                === 'none'
                ? 'selected'
                : '' ?>>
            なし
        </option>

        <option value="tls"
            <?= ($config['encryption'] ?? '')
                === 'tls'
                ? 'selected'
                : '' ?>>
            TLS
        </option>

    </select>
</div>

<div class="form-row">

<label>SMTP認証</label>

<label class="checkbox">
    <input
        type="checkbox"
        name="auth"
        value="1"
        <?= !empty($config['auth'])
            ? 'checked'
            : '' ?>>
    使用する
</label>

</div>

<div class="form-row">
    <label>SMTPユーザー名</label>
    <input
        type="text"
        name="username"
        value="<?= e(
            $config['username'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>SMTPパスワード</label>
    <input
        type="password"
        name="password"
        autocomplete="new-password">
</div>

<div class="form-row">
    <label>送信元メールアドレス</label>
    <input
        type="email"
        name="from_email"
        value="<?= e(
            $config['from_email'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>送信元名</label>
    <input
        type="text"
        name="from_name"
        value="<?= e(
            $config['from_name'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>返信先メールアドレス</label>
    <input
        type="email"
        name="reply_to"
        value="<?= e(
            $config['reply_to'] ?? ''
        ) ?>">
</div>

<button
    class="btn btn-primary"
    type="submit">
    設定を保存
</button>

</form>

</div>

</div>
<?php

    renderFooter();
}


/* =========================================================
 * 30. POST: 初回セットアップ
 * ========================================================= */

function handleSetup(): never
{
    global $bootstrap;

    try {
        $values = validateSetupInput();

        initializeDataStore(
            $values['data_dir']
        );

        $bootstrap = [
            'setup_completed' => true,
            'data_dir' => realpath(
                $values['data_dir']
            ),
            'admin_id' => $values['admin_id'],
            'admin_password_hash' =>
                $values['admin_password_hash'],
            'created_at' =>
                date('Y-m-d H:i:s'),
        ];

        saveBootstrap($bootstrap);

        redirect(appUrl([
            'screen' => SCREEN_LOGIN,
        ]));

    } catch (Throwable $e) {

        renderSetup(
            $e->getMessage()
        );

        exit;
    }
}


/* =========================================================
 * 31. POST: ログイン
 * ========================================================= */

function handleLogin(): never
{
    global $bootstrap;

    $adminId =
        trim((string)post('admin_id', ''));

    $password =
        (string)post('admin_password', '');

    if (
        $adminId === ''
        || $password === ''
        || !hash_equals(
            (string)($bootstrap['admin_id'] ?? ''),
            $adminId
        )
        || !password_verify(
            $password,
            (string)(
                $bootstrap['admin_password_hash']
                ?? ''
            )
        )
    ) {
        renderLogin(
            '管理者IDまたはパスワードが正しくありません。'
        );

        exit;
    }

    loginAdmin();

    redirect(appUrl([
        'screen' => SCREEN_LIST,
    ]));
}


/* =========================================================
 * 32. POST: アンケート保存
 * ========================================================= */

function handleSaveSurvey(): never
{
    requireAdmin();

    $id = trim((string)post('id', ''));

    if ($id === '') {
        throw new RuntimeException(
            'アンケートIDがありません。'
        );
    }

    $items = surveys();

    $index = null;

    foreach ($items as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            $index = $i;
            break;
        }
    }

    $survey =
        $index === null
            ? emptySurvey()
            : $items[$index];

    $survey['id'] = $id;
    $survey['title'] =
        trim((string)post('title', ''));

    $survey['description'] =
        (string)post('description', '');

    $survey['start_at'] =
        trim((string)post('start_at', ''));

    $survey['end_at'] =
        trim((string)post('end_at', ''));

    $survey['numbering'] =
        (string)post('numbering', 'global');

    if (!in_array(
        $survey['numbering'],
        ['global', 'group'],
        true
    )) {
        $survey['numbering'] = 'global';
    }

    if ($survey['title'] === '') {
        throw new InvalidArgumentException(
            'タイトルを入力してください。'
        );
    }

    $groups = post('groups', []);

    $normalizedGroups = [];

    if (is_array($groups)) {

        foreach ($groups as $group) {

            if (!is_array($group)) {
                continue;
            }

            $groupTitle =
                trim((string)(
                    $group['title'] ?? ''
                ));

            $questions =
                $group['questions'] ?? [];

            $normalizedQuestions = [];

            if (is_array($questions)) {

                foreach ($questions as $question) {

                    if (!is_array($question)) {
                        continue;
                    }

                    $text =
                        trim((string)(
                            $question['text'] ?? ''
                        ));

                    if ($text === '') {
                        continue;
                    }

                    $type =
                        (string)(
                            $question['type'] ?? ANSWER_TEXT
                        );

                    if (!in_array(
                        $type,
                        [
                            ANSWER_SINGLE,
                            ANSWER_MULTI,
                            ANSWER_TEXT,
                        ],
                        true
                    )) {
                        $type = ANSWER_TEXT;
                    }

                    $choicesText =
                        (string)(
                            $question['choices'] ?? ''
                        );

                    $choices = [];

                    foreach (
                        preg_split(
                            '/\R/u',
                            $choicesText
                        ) ?: []
                        as $choice
                    ) {
                        $choice =
                            trim((string)$choice);

                        if ($choice !== '') {
                            $choices[] = $choice;
                        }
                    }

                    $normalizedQuestions[] = [
                        'id' =>
                            (string)(
                                $question['id']
                                ?? id('question')
                            ),
                        'number' => '',
                        'text' => $text,
                        'type' => $type,
                        'required' =>
                            !empty(
                                $question['required']
                            ),
                        'choices' => $choices,
                    ];
                }
            }

            $normalizedGroups[] = [
                'id' =>
                    (string)(
                        $group['id']
                        ?? id('group')
                    ),
                'title' =>
                    $groupTitle !== ''
                        ? $groupTitle
                        : 'グループ',
                'questions' =>
                    $normalizedQuestions,
            ];
        }
    }

    if (!$normalizedGroups) {
        $normalizedGroups[] = [
            'id' => id('group'),
            'title' => '基本情報',
            'questions' => [],
        ];
    }

    $survey['groups'] =
        $normalizedGroups;

    /*
     * 新規作成の場合は下書き。
     * 既存編集では状態を維持する。
     */
    if ($index === null) {
        $survey['status'] = STATUS_DRAFT;
        $survey['created_at'] =
            date('Y-m-d H:i:s');
    }

    $survey['updated_at'] =
        date('Y-m-d H:i:s');

    renumberSurvey($survey);

    if ($index === null) {
        $items[] = $survey;
    } else {
        $items[$index] = $survey;
    }

    saveSurveys($items);

    redirect(appUrl([
        'screen' => SCREEN_LIST,
    ]));
}


/* =========================================================
 * 33. POST: 複製
 * ========================================================= */

function handleDuplicate(): never
{
    requireAdmin();

    $sourceId =
        (string)post('id', '');

    $source = findSurvey($sourceId);

    if ($source === null) {
        throw new RuntimeException(
            '複製元のアンケートが見つかりません。'
        );
    }

    $copy = $source;

    $copy['id'] = id('survey');
    $copy['title'] .= '（複製）';
    $copy['status'] = STATUS_DRAFT;
    $copy['created_at'] =
        date('Y-m-d H:i:s');
    $copy['updated_at'] =
        date('Y-m-d H:i:s');

    foreach ($copy['groups'] as &$group) {

        $group['id'] = id('group');

        foreach (
            $group['questions']
            as &$question
        ) {
            $question['id'] =
                id('question');
        }

        unset($question);
    }

    unset($group);

    renumberSurvey($copy);

    $items = surveys();
    $items[] = $copy;

    saveSurveys($items);

    redirect(appUrl([
        'screen' => SCREEN_LIST,
    ]));
}


/* =========================================================
 * 34. POST: 削除
 * ========================================================= */

function handleDelete(): never
{
    requireAdmin();

    $id =
        (string)post('id', '');

    $items = surveys();

    $found = false;

    $items = array_values(
        array_filter(
            $items,
            function (array $survey) use (
                $id,
                &$found
            ): bool {
                if (($survey['id'] ?? '') === $id) {
                    $found = true;
                    return false;
                }

                return true;
            }
        )
    );

    if (!$found) {
        throw new RuntimeException(
            '削除対象が見つかりません。'
        );
    }

    saveSurveys($items);

    redirect(appUrl([
        'screen' => SCREEN_LIST,
    ]));
}


/* =========================================================
 * 35. POST: 回答準備
 * ========================================================= */

function handlePrepareAnswer(): never
{
    $surveyId =
        (string)post('survey_id', '');

    $survey =
        findSurvey($surveyId);

    if (
        $survey === null
        || $survey['status'] !== STATUS_OPEN
    ) {
        throw new RuntimeException(
            'このアンケートは回答できません。'
        );
    }

    $submitted =
        post('answers', []);

    if (!is_array($submitted)) {
        $submitted = [];
    }

    foreach ($survey['groups'] as $group) {

        foreach (
            $group['questions']
            as $question
        ) {

            if (
                empty($question['required'])
            ) {
                continue;
            }

            $value =
                $submitted[
                    $question['id']
                ] ?? null;

            $missing =
                $value === null
                || $value === ''
                || (
                    is_array($value)
                    && count($value) === 0
                );

            if ($missing) {
                throw new InvalidArgumentException(
                    '必須項目が未回答です。'
                );
            }
        }
    }

    $_SESSION['answer_draft'] = [
        'survey_id' => $surveyId,
        'answers' => $submitted,
    ];

    redirect(appUrl([
        'screen' => SCREEN_CONFIRM,
    ]));
}


/* =========================================================
 * 36. POST: 回答送信
 * ========================================================= */

function handleSubmitAnswer(): never
{
    $draft =
        $_SESSION['answer_draft'] ?? null;

    if (
        !is_array($draft)
        || empty($draft['survey_id'])
    ) {
        throw new RuntimeException(
            '回答情報がありません。'
        );
    }

    $surveyId =
        (string)$draft['survey_id'];

    $survey =
        findSurvey($surveyId);

    if (
        $survey === null
        || $survey['status'] !== STATUS_OPEN
    ) {
        throw new RuntimeException(
            'このアンケートは現在回答を受け付けていません。'
        );
    }

    $items = answers();

    $items[] = [
        'id' => id('answer'),
        'survey_id' => $surveyId,
        'customer_id' => null,
        'created_at' =>
            date('Y-m-d H:i:s'),
        'answers' =>
            is_array($draft['answers'] ?? null)
                ? $draft['answers']
                : [],
    ];

    saveAnswers($items);

    unset($_SESSION['answer_draft']);

    redirect(appUrl([
        'screen' => SCREEN_COMPLETE,
    ]));
}


/* =========================================================
 * 37. POST: kintone設定
 * ========================================================= */

function handleSaveKintone(): never
{
    requireAdmin();

    $all = settings();

    $current =
        $all['kintone'] ?? [];

    $password =
        (string)post('password', '');

    $all['kintone'] = [
        'subdomain' =>
            trim((string)post(
                'subdomain',
                ''
            )),

        'app_id' =>
            trim((string)post(
                'app_id',
                ''
            )),

        'username' =>
            trim((string)post(
                'username',
                ''
            )),

        /*
         * 空欄なら既存値を維持。
         */
        'password' =>
            $password !== ''
                ? $password
                : ($current['password'] ?? ''),

        'proxy' =>
            trim((string)post(
                'proxy',
                ''
            )),

        'verify_ssl' =>
            !empty(
                $_POST['verify_ssl']
            ),
    ];

    saveSettings($all);

    redirect(appUrl([
        'screen' => SCREEN_KINTONE,
    ]));
}


/* =========================================================
 * 38. POST: メール設定
 * ========================================================= */

function handleSaveMail(): never
{
    requireAdmin();

    $all = settings();

    $current =
        $all['mail'] ?? [];

    $password =
        (string)post('password', '');

    $all['mail'] = [
        'host' =>
            trim((string)post(
                'host',
                ''
            )),

        'port' =>
            (int)post(
                'port',
                587
            ),

        'encryption' =>
            (string)post(
                'encryption',
                'tls'
            ),

        'auth' =>
            !empty(
                $_POST['auth']
            ),

        'username' =>
            trim((string)post(
                'username',
                ''
            )),

        'password' =>
            $password !== ''
                ? $password
                : ($current['password'] ?? ''),

        'from_email' =>
            trim((string)post(
                'from_email',
                ''
            )),

        'from_name' =>
            trim((string)post(
                'from_name',
                ''
            )),

        'reply_to' =>
            trim((string)post(
                'reply_to',
                ''
            )),
    ];

    saveSettings($all);

    redirect(appUrl([
        'screen' => SCREEN_MAIL,
    ]));
}


/* =========================================================
 * 39. 状態変更
 * ========================================================= */

function handleChangeStatus(): never
{
    requireAdmin();

    $id =
        (string)post('id', '');

    $newStatus =
        (string)post('status', '');

    $allowed = [
        STATUS_DRAFT,
        STATUS_OPEN,
        STATUS_STOPPED,
    ];

    if (!in_array(
        $newStatus,
        $allowed,
        true
    )) {
        throw new InvalidArgumentException(
            '不正な状態です。'
        );
    }

    $items = surveys();

    foreach ($items as &$survey) {

        if (($survey['id'] ?? '') !== $id) {
            continue;
        }

        $current =
            effectiveStatus($survey);

        if ($current === STATUS_FINISHED) {
            throw new InvalidArgumentException(
                '終了したアンケートは変更できません。'
            );
        }

        $valid = match (true) {
            $current === STATUS_DRAFT
                && $newStatus === STATUS_OPEN => true,

            $current === STATUS_OPEN
                && $newStatus === STATUS_STOPPED => true,

            $current === STATUS_STOPPED
                && $newStatus === STATUS_OPEN => true,

            default => false,
        };

        if (!$valid) {
            throw new InvalidArgumentException(
                '許可されていない状態変更です。'
            );
        }

        $survey['status'] = $newStatus;
        $survey['updated_at'] =
            date('Y-m-d H:i:s');

        saveSurveys($items);

        redirect(appUrl([
            'screen' => SCREEN_LIST,
        ]));
    }

    unset($survey);

    throw new RuntimeException(
        '対象アンケートが見つかりません。'
    );
}


/* =========================================================
 * 40. POST/GETアクション
 * ========================================================= */

function dispatchAction(): void
{
    global $bootstrap;

    $action =
        (string)get('action', '');

    /*
     * POST処理はCSRF検証を最初に行う。
     */
    if (requestMethod() === 'POST') {
        verifyCsrf();
    }

    switch ($action) {

        case 'logout':
            if (requestMethod() !== 'GET') {
                http_response_code(405);
                exit;
            }

            /*
             * 状態変更なので本来POSTを使用する。
             * GETログアウトを採用する場合でも、
             * ここでは認証状態だけを破棄する。
             */
            logoutAdmin();

            redirect(appUrl([
                'screen' => SCREEN_LOGIN,
            ]));
            break;

        case 'setup':

            if (setupCompleted()) {
                redirect(appUrl([
                    'screen' => SCREEN_LOGIN,
                ]));
            }

            handleSetup();
            break;

        case 'login':

            if (!setupCompleted()) {
                redirect(appUrl([
                    'screen' => SCREEN_SETUP,
                ]));
            }

            handleLogin();
            break;

        case 'save_survey':
            handleSaveSurvey();
            break;

        case 'duplicate':
            handleDuplicate();
            break;

        case 'delete':
            handleDelete();
            break;

        case 'prepare_answer':
            handlePrepareAnswer();
            break;

        case 'submit_answer':
            handleSubmitAnswer();
            break;

        case 'save_kintone':
            handleSaveKintone();
            break;

        case 'save_mail':
            handleSaveMail();
            break;

        case 'change_status':
            handleChangeStatus();
            break;

        default:
            break;
    }
}


/* =========================================================
 * 41. 画面ディスパッチ
 * ========================================================= */

function dispatchScreen(): void
{
    if (!setupCompleted()) {

        /*
         * 未セットアップ時は必ずセットアップ画面。
         */
        if (
            currentScreen() === SCREEN_SETUP
            && requestMethod() === 'GET'
        ) {
            renderSetup();
            return;
        }

        /*
         * POSTのセットアップはaction=setupで処理。
         */
        renderSetup();
        return;
    }

    $screen = currentScreen();

    switch ($screen) {

        case SCREEN_SETUP:
            redirect(appUrl([
                'screen' => SCREEN_LOGIN,
            ]));
            break;

        case SCREEN_LOGIN:

            if (isAuthenticated()) {
                redirect(appUrl([
                    'screen' => SCREEN_LIST,
                ]));
            }

            renderLogin();
            break;

        case SCREEN_LIST:
            renderList();
            break;

        case SCREEN_EDIT:
            renderEdit(
                get('id')
                    ? (string)get('id')
                    : null
            );
            break;

        case SCREEN_PREVIEW:
            requireAdmin();

            renderPreview(
                (string)get('id', '')
            );
            break;

        case SCREEN_ANALYTICS:
            renderAnalytics(
                (string)get('id', '')
            );
            break;

        case SCREEN_SEND:
            renderSend(
                (string)get('id', '')
            );
            break;

        case SCREEN_KINTONE:
            renderKintone();
            break;

        case SCREEN_MAIL:
            renderMail();
            break;

        case SCREEN_ANSWER:
            renderAnswer(
                (string)get('id', '')
            );
            break;

        case SCREEN_CONFIRM:
            renderConfirm();
            break;

        case SCREEN_COMPLETE:
            renderComplete();
            break;

        default:
            if (isAuthenticated()) {
                redirect(appUrl([
                    'screen' => SCREEN_LIST,
                ]));
            }

            redirect(appUrl([
                'screen' => SCREEN_LOGIN,
            ]));
    }
}


/* =========================================================
 * 42. 送信画面
 * ========================================================= */

function renderSend(string $id): void
{
    requireAdmin();

    $survey = findSurvey($id);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '指定されたアンケートは存在しません。'
        );
        return;
    }

    $customerList = customers();

    $logs = array_values(
        array_filter(
            mailLogs(),
            fn(array $log): bool =>
                ($log['survey_id'] ?? '') === $id
        )
    );

    renderHeader('顧客選択・メール送信');

    ?>
<div class="container">

<div class="page-title">

<div>
<h1>顧客選択・メール送信</h1>
<div class="muted">
<?= e($survey['title']) ?>
</div>
</div>

<a class="btn btn-secondary"
   href="<?= e(appUrl([
       'screen' => SCREEN_LIST,
   ])) ?>">
一覧へ戻る
</a>

</div>

<div class="card">

<h2>顧客</h2>

<?php if (!$customerList): ?>

<div class="empty">
顧客データがありません。
</div>

<?php else: ?>

<form method="post"
      action="<?= e(appUrl([
          'action' => 'send_mail',
      ])) ?>">

<?= csrfField() ?>

<input type="hidden"
       name="survey_id"
       value="<?= e($id) ?>">

<div class="table-wrap">

<table>

<thead>
<tr>
<th>選択</th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
</tr>
</thead>

<tbody>

<?php foreach ($customerList as $customer): ?>

<tr>

<td>
<input
    type="checkbox"
    name="customer_ids[]"
    value="<?= e(
        $customer['id'] ?? ''
    ) ?>">
</td>

<td>
<?= e(
    $customer['organization']
    ?? ''
) ?>
</td>

<td>
<?= e(
    $customer['name']
    ?? ''
) ?>
</td>

<td>
<?= e(
    $customer['email']
    ?? ''
) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<div class="form-row">

<label>
件名
</label>

<input
    type="text"
    name="subject"
    value="<?= e(
        $survey['title']
        . ' のご案内'
    ) ?>">

</div>

<div class="form-row">

<label>
本文
</label>

<textarea
    name="body">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>

</div>

<button
    class="btn btn-primary"
    type="submit">
送信
</button>

</form>

<?php endif; ?>

</div>

<div class="card">

<h2>送信履歴</h2>

<?php if (!$logs): ?>

<div class="empty">
送信履歴はありません。
</div>

<?php else: ?>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>送信日時</th>
<th>顧客</th>
<th>宛先</th>
<th>結果</th>
</tr>
</thead>

<tbody>

<?php foreach ($logs as $log): ?>

<tr>

<td>
<?= e($log['created_at'] ?? '') ?>
</td>

<td>
<?= e($log['customer_name'] ?? '') ?>
</td>

<td>
<?= e($log['email'] ?? '') ?>
</td>

<td>
<?php if (!empty($log['success'])): ?>
<span class="badge badge-success">
送信済み
</span>
<?php else: ?>
<span class="badge badge-danger">
失敗
</span>
<?php endif; ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>

</div>
<?php

    renderFooter();
}


/* =========================================================
 * 43. メール送信
 * ========================================================= */

function handleSendMail(): never
{
    requireAdmin();

    /*
     * SMTP実送信処理は、利用可能な標準PHP機能を
     * 確認したうえで実装する。
     *
     * このファイルでは、SMTP認証情報をブラウザへ
     * 出さないことを優先し、送信処理そのものを
     * application serviceとして分離できるようにする。
     *
     * PHP cURLは使用しない。
     */

    throw new RuntimeException(
        'SMTP送信処理は実行環境のSMTP仕様確認後に有効化してください。'
    );
}


/* =========================================================
 * 44. 例外処理
 * ========================================================= */

set_exception_handler(
    function (Throwable $e): void {

        /*
         * 本番では詳細な例外内容を利用者へ表示しない。
         * パスワード、CSRF情報、セッションID等を
         * エラー画面へ出さない。
         */

        http_response_code(500);

        renderError(
            '処理に失敗しました。',
            '処理を完了できませんでした。'
        );
    }
);


/* =========================================================
 * 45. メイン
 * ========================================================= */

try {

    /*
     * actionを処理する。
     */
    if (isset($_GET['action'])) {
        dispatchAction();
    }

    /*
     * 未セットアップ時のPOST。
     */
    if (
        !setupCompleted()
        && requestMethod() === 'POST'
    ) {
        verifyCsrf();
        handleSetup();
    }

    /*
     * ログインPOST。
     */
    if (
        setupCompleted()
        && currentScreen() === SCREEN_LOGIN
        && requestMethod() === 'POST'
    ) {
        verifyCsrf();
        handleLogin();
    }

    dispatchScreen();

} catch (Throwable $e) {

    /*
     * 利用者向けには内部情報を露出しない。
     */
    http_response_code(500);

    renderError(
        '処理に失敗しました。',
        '処理を完了できませんでした。'
    );
}
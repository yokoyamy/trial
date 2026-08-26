<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * 通信基盤 第1段階
 * screen: admin
 * Request ID: c22fc0418a3e0126fad9e587d67c1353
 *
 * Apache + PHP 8.x
 * 外部ライブラリ不要
 */

session_start();

/* =========================================================
 * Configuration
 * ======================================================= */

const APP_NAME = 'アンケート管理システム';
const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/surveys.json';

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}

if (!file_exists(DATA_FILE)) {
    file_put_contents(
        DATA_FILE,
        json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

/* =========================================================
 * Helpers
 * ======================================================= */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function loadSurveys(): array
{
    if (!file_exists(DATA_FILE)) {
        return [];
    }

    $json = file_get_contents(DATA_FILE);
    $data = json_decode($json ?: '[]', true);

    return is_array($data) ? $data : [];
}

function saveSurveys(array $surveys): bool
{
    return file_put_contents(
        DATA_FILE,
        json_encode(
            array_values($surveys),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ),
        LOCK_EX
    ) !== false;
}

function redirect(string $url = 'index.php'): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $flash;
}

function generateId(): string
{
    return bin2hex(random_bytes(8));
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf'] ?? '';

    if (
        !$token ||
        empty($_SESSION['csrf']) ||
        !hash_equals($_SESSION['csrf'], $token)
    ) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function findSurvey(array $surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function statusLabel(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'closed' => '終了',
        default => '下書き',
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        'published' => 'status-published',
        'closed' => 'status-closed',
        default => 'status-draft',
    };
}

/* =========================================================
 * Actions
 * ======================================================= */

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $surveys = loadSurveys();

    switch ($action) {

        case 'create':
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));

            if ($title === '') {
                flash('アンケート名を入力してください。', 'error');
                redirect();
            }

            $now = date('Y-m-d H:i:s');

            $surveys[] = [
                'id' => generateId(),
                'title' => $title,
                'description' => $description,
                'status' => 'draft',
                'responses' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (saveSurveys($surveys)) {
                flash('アンケートを作成しました。');
            } else {
                flash('保存に失敗しました。', 'error');
            }

            redirect();

        case 'update':
            $id = (string)($_POST['id'] ?? '');
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));

            if ($title === '') {
                flash('アンケート名を入力してください。', 'error');
                redirect();
            }

            foreach ($surveys as &$survey) {
                if (($survey['id'] ?? '') === $id) {
                    $survey['title'] = $title;
                    $survey['description'] = $description;
                    $survey['updated_at'] = date('Y-m-d H:i:s');
                    break;
                }
            }
            unset($survey);

            if (saveSurveys($surveys)) {
                flash('アンケートを更新しました。');
            } else {
                flash('更新に失敗しました。', 'error');
            }

            redirect();

        case 'status':
            $id = (string)($_POST['id'] ?? '');
            $status = (string)($_POST['status'] ?? 'draft');

            if (!in_array($status, ['draft', 'published', 'closed'], true)) {
                flash('不正なステータスです。', 'error');
                redirect();
            }

            foreach ($surveys as &$survey) {
                if (($survey['id'] ?? '') === $id) {
                    $survey['status'] = $status;
                    $survey['updated_at'] = date('Y-m-d H:i:s');
                    break;
                }
            }
            unset($survey);

            saveSurveys($surveys);
            flash('ステータスを変更しました。');
            redirect();

        case 'delete':
            $id = (string)($_POST['id'] ?? '');

            $before = count($surveys);

            $surveys = array_filter(
                $surveys,
                static fn(array $survey): bool =>
                    ($survey['id'] ?? '') !== $id
            );

            if ($before === count($surveys)) {
                flash('対象データが見つかりません。', 'error');
            } elseif (saveSurveys($surveys)) {
                flash('アンケートを削除しました。');
            } else {
                flash('削除に失敗しました。', 'error');
            }

            redirect();
    }
}

/* =========================================================
 * Display
 * ======================================================= */

$surveys = loadSurveys();

usort(
    $surveys,
    static fn(array $a, array $b): int =>
        strcmp(
            (string)($b['updated_at'] ?? ''),
            (string)($a['updated_at'] ?? '')
        )
);

$flash = getFlash();

$total = count($surveys);
$published = count(
    array_filter(
        $surveys,
        static fn(array $s): bool =>
            ($s['status'] ?? '') === 'published'
    )
);
$draft = count(
    array_filter(
        $surveys,
        static fn(array $s): bool =>
            ($s['status'] ?? '') === 'draft'
    )
);
$responses = array_sum(
    array_map(
        static fn(array $s): int => (int)($s['responses'] ?? 0),
        $surveys
    )
);

$editId = (string)($_GET['edit'] ?? '');
$editSurvey = $editId !== '' ? findSurvey($surveys, $editId) : null;

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?> - 管理</title>

<style>
* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    background: #f5f7fa;
    color: #1f2937;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Hiragino Kaku Gothic ProN",
        "Yu Gothic",
        Meiryo,
        sans-serif;
}

a {
    color: inherit;
    text-decoration: none;
}

button,
input,
textarea,
select {
    font: inherit;
}

.header {
    height: 64px;
    background: #111827;
    color: #fff;
    display: flex;
    align-items: center;
    padding: 0 28px;
    justify-content: space-between;
}

.logo {
    font-size: 18px;
    font-weight: 700;
}

.logo span {
    color: #93c5fd;
    margin-left: 8px;
    font-size: 13px;
    font-weight: 500;
}

.header-right {
    font-size: 13px;
    color: #d1d5db;
}

.layout {
    display: flex;
    min-height: calc(100vh - 64px);
}

.sidebar {
    width: 230px;
    background: #fff;
    border-right: 1px solid #e5e7eb;
    padding: 24px 14px;
}

.nav-title {
    color: #9ca3af;
    font-size: 11px;
    font-weight: 700;
    padding: 0 12px 8px;
    letter-spacing: .08em;
}

.nav-item {
    display: block;
    padding: 11px 12px;
    border-radius: 7px;
    margin-bottom: 4px;
    font-size: 14px;
    color: #4b5563;
}

.nav-item.active {
    background: #eff6ff;
    color: #2563eb;
    font-weight: 700;
}

.main {
    flex: 1;
    padding: 30px;
    max-width: 1500px;
}

.page-title {
    margin: 0;
    font-size: 25px;
    font-weight: 700;
}

.page-subtitle {
    color: #6b7280;
    font-size: 13px;
    margin-top: 7px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
}

.btn {
    border: 0;
    border-radius: 7px;
    padding: 10px 16px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}

.btn-primary {
    background: #2563eb;
    color: #fff;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-secondary {
    background: #fff;
    color: #374151;
    border: 1px solid #d1d5db;
}

.btn-danger {
    background: #fff;
    color: #dc2626;
    border: 1px solid #fecaca;
}

.btn-small {
    padding: 7px 10px;
    font-size: 12px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 9px;
    padding: 18px 20px;
}

.stat-label {
    color: #6b7280;
    font-size: 12px;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 26px;
    font-weight: 700;
}

.card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 9px;
    overflow: hidden;
}

.card-header {
    padding: 17px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-title {
    font-size: 15px;
    font-weight: 700;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid #eef0f3;
    font-size: 13px;
    white-space: nowrap;
}

th {
    color: #6b7280;
    font-size: 11px;
    font-weight: 700;
    background: #fafafa;
}

tr:last-child td {
    border-bottom: 0;
}

.survey-title {
    font-weight: 600;
    color: #111827;
}

.survey-description {
    color: #6b7280;
    margin-top: 4px;
    max-width: 420px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.status {
    display: inline-flex;
    align-items: center;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}

.status-draft {
    background: #f3f4f6;
    color: #4b5563;
}

.status-published {
    background: #dcfce7;
    color: #166534;
}

.status-closed {
    background: #fee2e2;
    color: #991b1b;
}

.actions {
    display: flex;
    gap: 6px;
    align-items: center;
}

.inline-form {
    display: inline;
}

.empty {
    padding: 60px 20px;
    text-align: center;
    color: #9ca3af;
}

.alert {
    border-radius: 7px;
    padding: 12px 15px;
    margin-bottom: 20px;
    font-size: 13px;
}

.alert-success {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #047857;
}

.alert-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(17, 24, 39, .45);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 100;
}

.modal {
    width: min(600px, 100%);
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
}

.modal-header {
    padding: 18px 22px;
    border-bottom: 1px solid #e5e7eb;
    font-weight: 700;
}

.modal-body {
    padding: 22px;
}

.modal-footer {
    padding: 15px 22px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.form-group {
    margin-bottom: 17px;
}

.form-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 7px;
}

.form-control {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 10px 11px;
    outline: none;
    background: #fff;
}

.form-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}

textarea.form-control {
    min-height: 110px;
    resize: vertical;
}

@media (max-width: 900px) {
    .sidebar {
        display: none;
    }

    .main {
        padding: 20px;
    }

    .stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 560px) {
    .header {
        padding: 0 16px;
    }

    .main {
        padding: 14px;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .topbar {
        flex-direction: column;
        gap: 15px;
    }
}
</style>
</head>

<body>

<header class="header">
    <div class="logo">
        <?= h(APP_NAME) ?>
        <span>通信基盤 第1段階</span>
    </div>
    <div class="header-right">
        screen: admin
    </div>
</header>

<div class="layout">

    <aside class="sidebar">
        <div class="nav-title">MENU</div>

        <a href="index.php" class="nav-item active">
            アンケート管理
        </a>

        <a href="index.php" class="nav-item">
            回答データ
        </a>

        <a href="index.php" class="nav-item">
            集計・レポート
        </a>

        <div style="height:25px"></div>

        <div class="nav-title">SYSTEM</div>

        <a href="index.php" class="nav-item">
            システム設定
        </a>
    </aside>

    <main class="main">

        <div class="topbar">
            <div>
                <h1 class="page-title">アンケート管理</h1>
                <div class="page-subtitle">
                    アンケートの作成・公開状態・回答状況を管理します。
                </div>
            </div>

            <button
                type="button"
                class="btn btn-primary"
                onclick="openCreateModal()"
            >
                ＋ アンケート作成
            </button>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <section class="stats">

            <div class="stat">
                <div class="stat-label">アンケート総数</div>
                <div class="stat-value"><?= $total ?></div>
            </div>

            <div class="stat">
                <div class="stat-label">公開中</div>
                <div class="stat-value"><?= $published ?></div>
            </div>

            <div class="stat">
                <div class="stat-label">下書き</div>
                <div class="stat-value"><?= $draft ?></div>
            </div>

            <div class="stat">
                <div class="stat-label">回答総数</div>
                <div class="stat-value"><?= $responses ?></div>
            </div>

        </section>

        <section class="card">

            <div class="card-header">
                <div class="card-title">アンケート一覧</div>
                <div style="font-size:12px;color:#9ca3af">
                    <?= $total ?> 件
                </div>
            </div>

            <?php if (!$surveys): ?>

                <div class="empty">
                    <div style="font-size:30px;margin-bottom:10px">📋</div>
                    <div>アンケートがありません。</div>
                    <div style="font-size:12px;margin-top:5px">
                        「アンケート作成」から登録してください。
                    </div>
                </div>

            <?php else: ?>

                <div class="table-wrap">

                    <table>

                        <thead>
                        <tr>
                            <th>アンケート</th>
                            <th>ステータス</th>
                            <th>回答数</th>
                            <th>更新日時</th>
                            <th>操作</th>
                        </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($surveys as $survey): ?>

                            <?php
                            $id = (string)$survey['id'];
                            $status = (string)($survey['status'] ?? 'draft');
                            ?>

                            <tr>

                                <td>
                                    <div class="survey-title">
                                        <?= h($survey['title'] ?? '') ?>
                                    </div>

                                    <?php if (!empty($survey['description'])): ?>
                                        <div class="survey-description">
                                            <?= h($survey['description']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="status <?= h(statusClass($status)) ?>">
                                        <?= h(statusLabel($status)) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= (int)($survey['responses'] ?? 0) ?>
                                </td>

                                <td>
                                    <?= h($survey['updated_at'] ?? '-') ?>
                                </td>

                                <td>
                                    <div class="actions">

                                        <a
                                            href="?edit=<?= urlencode($id) ?>"
                                            class="btn btn-secondary btn-small"
                                        >
                                            編集
                                        </a>

                                        <?php if ($status === 'draft'): ?>

                                            <form
                                                method="post"
                                                class="inline-form"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf"
                                                    value="<?= h(csrfToken()) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="status"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= h($id) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="status"
                                                    value="published"
                                                >

                                                <button
                                                    class="btn btn-primary btn-small"
                                                    type="submit"
                                                >
                                                    公開
                                                </button>
                                            </form>

                                        <?php elseif ($status === 'published'): ?>

                                            <form
                                                method="post"
                                                class="inline-form"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf"
                                                    value="<?= h(csrfToken()) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="status"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= h($id) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="status"
                                                    value="closed"
                                                >

                                                <button
                                                    class="btn btn-secondary btn-small"
                                                    type="submit"
                                                >
                                                    終了
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                        <form
                                            method="post"
                                            class="inline-form"
                                            onsubmit="return confirm('このアンケートを削除しますか？');"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= h(csrfToken()) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >
                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= h($id) ?>"
                                            >

                                            <button
                                                class="btn btn-danger btn-small"
                                                type="submit"
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

            <?php endif; ?>

        </section>

    </main>
</div>

<!-- Create Modal -->
<div
    id="createModal"
    class="modal-backdrop"
    style="display:none"
    onclick="closeCreateModal(event)"
>
    <div class="modal" onclick="event.stopPropagation()">

        <div class="modal-header">
            アンケート作成
        </div>

        <form method="post">

            <div class="modal-body">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="create"
                >

                <div class="form-group">

                    <label class="form-label">
                        アンケート名
                    </label>

                    <input
                        type="text"
                        name="title"
                        class="form-control"
                        maxlength="200"
                        required
                        autofocus
                        placeholder="例：2026年度 顧客満足度アンケート"
                    >

                </div>

                <div class="form-group">

                    <label class="form-label">
                        説明
                    </label>

                    <textarea
                        name="description"
                        class="form-control"
                        maxlength="1000"
                        placeholder="アンケートの目的や回答者への説明を入力してください。"
                    ></textarea>

                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeCreateModal()"
                >
                    キャンセル
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    作成する
                </button>

            </div>

        </form>

    </div>
</div>

<!-- Edit Modal -->
<?php if ($editSurvey): ?>

<div
    class="modal-backdrop"
    onclick="closeEditModal()"
>
    <div class="modal" onclick="event.stopPropagation()">

        <div class="modal-header">
            アンケート編集
        </div>

        <form method="post">

            <div class="modal-body">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="update"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= h($editSurvey['id']) ?>"
                >

                <div class="form-group">

                    <label class="form-label">
                        アンケート名
                    </label>

                    <input
                        type="text"
                        name="title"
                        class="form-control"
                        maxlength="200"
                        required
                        value="<?= h($editSurvey['title'] ?? '') ?>"
                    >

                </div>

                <div class="form-group">

                    <label class="form-label">
                        説明
                    </label>

                    <textarea
                        name="description"
                        class="form-control"
                        maxlength="1000"
                    ><?= h($editSurvey['description'] ?? '') ?></textarea>

                </div>

            </div>

            <div class="modal-footer">

                <a
                    href="index.php"
                    class="btn btn-secondary"
                >
                    キャンセル
                </a>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    保存する
                </button>

            </div>

        </form>

    </div>
</div>

<?php endif; ?>

<script>
function openCreateModal() {
    document.getElementById('createModal').style.display = 'flex';

    const input = document.querySelector(
        '#createModal input[name="title"]'
    );

    if (input) {
        setTimeout(() => input.focus(), 50);
    }
}

function closeCreateModal(event) {
    if (event && event.target !== event.currentTarget) {
        return;
    }

    document.getElementById('createModal').style.display = 'none';
}

function closeEditModal() {
    window.location.href = 'index.php';
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('createModal');

        if (modal) {
            modal.style.display = 'none';
        }

        <?php if ($editSurvey): ?>
        window.location.href = 'index.php';
        <?php endif; ?>
    }
});
</script>

</body>
</html>
<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * index.php
 *
 * 第1実装：
 * - 同居アプリとのセッション分離
 * - 単一入口
 * - S01～S14
 * - A01～A06
 * - query stringによる画面状態
 * - pushState
 * - replaceState
 * - popstate
 * - 直接URLアクセス
 * - 再読み込み
 * - 画面遷移
 *
 * ※業務APIは次段階で実装する。
 * ============================================================
 */


/* ============================================================
 * 1. PHP基本設定
 * ============================================================ */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

error_reporting(E_ALL);

ob_start();


/* ============================================================
 * 2. アプリ専用セッション
 * ============================================================ */

const APP_SESSION_NAME = 'SURVEY_MANAGER_SESSION';

if (session_status() === PHP_SESSION_NONE) {

    session_name(APP_SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (
            isset($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off'
        ),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}


/* ============================================================
 * 3. アプリ専用セッション領域
 * ============================================================ */

if (!isset($_SESSION['survey_manager'])) {
    $_SESSION['survey_manager'] = [];
}


/* ============================================================
 * 4. CSRFトークン
 * ============================================================ */

if (
    !isset($_SESSION['survey_manager']['csrf_token'])
    || !is_string($_SESSION['survey_manager']['csrf_token'])
) {
    $_SESSION['survey_manager']['csrf_token'] =
        bin2hex(random_bytes(32));
}


/* ============================================================
 * 5. 例外処理
 * ============================================================ */

set_exception_handler(
    function (Throwable $e): void {

        error_log(
            '[survey_manager] '
            . $e->getMessage()
            . ' '
            . $e->getFile()
            . ':'
            . $e->getLine()
        );

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);

        header(
            'Content-Type: text/html; charset=utf-8'
        );

        echo '<!doctype html>';
        echo '<html lang="ja">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<title>システムエラー</title>';
        echo '</head>';
        echo '<body>';
        echo '<h1>システムエラー</h1>';
        echo '<p>サーバー内部エラーが発生しました。</p>';
        echo '</body>';
        echo '</html>';

        exit;
    }
);


/* ============================================================
 * 6. 共通関数
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function url(
    string $screen,
    array $params = []
): string {

    $query = [
        'screen' => $screen,
    ];

    foreach ($params as $key => $value) {

        if ($value !== null && $value !== '') {
            $query[$key] = $value;
        }
    }

    return 'index.php?' . http_build_query($query);
}


/* ============================================================
 * 7. 画面ID
 * ============================================================ */

$screen = $_GET['screen'] ?? 'admin';

$allowedScreens = [

    // 管理者
    'admin',
    'surveys',
    'survey',
    'questions',
    'question-edit',
    'conditions',
    'customers',
    'send',
    'send-confirm',
    'send-history',
    'summary',
    'kintone',
    'smtp',
    'settings',

    // 回答者
    'start',
    'answer',
    'confirm',
    'complete',
    'already-answered',
    'unavailable',
];


if (
    !is_string($screen)
    || !in_array($screen, $allowedScreens, true)
) {
    $screen = 'admin';
}


/* ============================================================
 * 8. URLパラメータ
 * ============================================================ */

$surveyId = isset($_GET['surveyId'])
    ? (string)$_GET['surveyId']
    : '';

$customerId = isset($_GET['customerId'])
    ? (string)$_GET['customerId']
    : '';

$questionId = isset($_GET['questionId'])
    ? (string)$_GET['questionId']
    : '';


/* ============================================================
 * 9. HTML開始
 * ============================================================ */

header(
    'Content-Type: text/html; charset=utf-8'
);

?>
<!doctype html>

<html lang="ja">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
    アンケート管理システム
</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f3f5f8;
    color: #222;
    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

.app {
    min-height: 100vh;
}

.header {
    height: 64px;
    background: #1f2937;
    color: #fff;

    display: flex;
    align-items: center;

    padding: 0 24px;
}

.header-title {
    font-size: 20px;
    font-weight: 700;
}

.layout {
    display: flex;
    min-height: calc(100vh - 64px);
}

.sidebar {
    width: 240px;
    background: #111827;
    color: #fff;
    padding: 20px 12px;
}

.sidebar-title {
    padding: 10px 12px;
    font-weight: 700;
    color: #9ca3af;
    font-size: 13px;
}

.nav-button {
    width: 100%;
    border: 0;
    background: transparent;
    color: #e5e7eb;

    padding: 11px 12px;

    text-align: left;
    border-radius: 6px;

    cursor: pointer;
    font-size: 14px;
}

.nav-button:hover {
    background: #374151;
}

.content {
    flex: 1;
    padding: 28px;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;

    margin-bottom: 24px;
}

.page-title {
    margin: 0;
    font-size: 28px;
}

.card {
    background: #fff;
    border-radius: 10px;
    padding: 22px;
    margin-bottom: 20px;

    box-shadow:
        0 1px 3px rgba(0,0,0,.08);
}

.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-height: 40px;

    padding: 0 16px;

    border: 1px solid #d1d5db;
    border-radius: 6px;

    background: #fff;
    color: #111827;

    cursor: pointer;
    text-decoration: none;

    font-size: 14px;
    font-weight: 600;

    margin-right: 8px;
    margin-bottom: 8px;
}

.button:hover {
    background: #f3f4f6;
}

.button-primary {
    background: #2563eb;
    color: #fff;
    border-color: #2563eb;
}

.button-primary:hover {
    background: #1d4ed8;
}

.button-danger {
    background: #dc2626;
    color: #fff;
    border-color: #dc2626;
}

.button-success {
    background: #16a34a;
    color: #fff;
    border-color: #16a34a;
}

.button-warning {
    background: #d97706;
    color: #fff;
    border-color: #d97706;
}

.grid {
    display: grid;
    grid-template-columns:
        repeat(auto-fit, minmax(220px, 1fr));

    gap: 16px;
}

.menu-card {
    background: #fff;

    border-radius: 10px;
    padding: 20px;

    box-shadow:
        0 1px 3px rgba(0,0,0,.08);
}

.menu-card h3 {
    margin-top: 0;
}

.menu-card p {
    color: #6b7280;
    font-size: 14px;
}

input,
textarea,
select {
    width: 100%;
    padding: 10px;

    border: 1px solid #d1d5db;
    border-radius: 6px;

    font: inherit;
}

.form-row {
    margin-bottom: 16px;
}

.form-row label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}

.status {
    display: inline-block;

    padding: 5px 10px;

    border-radius: 999px;

    background: #e5e7eb;

    font-size: 13px;
}

.info {
    background: #eff6ff;
    border-left: 4px solid #2563eb;

    padding: 14px;
    margin-bottom: 20px;
}

.warning {
    background: #fff7ed;
    border-left: 4px solid #f97316;

    padding: 14px;
    margin-bottom: 20px;
}

.mobile-menu {
    display: none;
}

@media (max-width: 800px) {

    .sidebar {
        display: none;
    }

    .mobile-menu {
        display: block;
        margin-bottom: 15px;
    }

    .content {
        padding: 16px;
    }

    .page-header {
        display: block;
    }

    .page-title {
        margin-bottom: 15px;
    }
}

</style>

</head>


<body>


<div class="app">


<header class="header">

    <div class="header-title">
        アンケート管理システム
    </div>

</header>


<div class="layout">


<!-- ========================================================
     サイドメニュー
     ======================================================== -->

<aside class="sidebar">

    <div class="sidebar-title">
        管理者メニュー
    </div>

    <button
        class="nav-button"
        data-screen="admin"
    >
        管理者トップ
    </button>

    <button
        class="nav-button"
        data-screen="surveys"
    >
        アンケート管理
    </button>

    <button
        class="nav-button"
        data-screen="customers"
    >
        顧客管理
    </button>

    <button
        class="nav-button"
        data-screen="send-history"
    >
        送信履歴
    </button>

    <button
        class="nav-button"
        data-screen="summary"
    >
        集計
    </button>

    <button
        class="nav-button"
        data-screen="kintone"
    >
        kintone設定
    </button>

    <button
        class="nav-button"
        data-screen="smtp"
    >
        SMTP設定
    </button>

    <button
        class="nav-button"
        data-screen="settings"
    >
        システム設定
    </button>

</aside>


<!-- ========================================================
     メイン
     ======================================================== -->

<main class="content">

<div
    id="app"
    class="container"
>

<?php


/* ============================================================
 * S01 管理者トップ
 * ============================================================ */

if ($screen === 'admin'):

?>

<div class="page-header">

    <h1 class="page-title">
        管理者トップ
    </h1>

</div>


<div class="grid">

    <div class="menu-card">

        <h3>アンケート管理</h3>

        <p>
            アンケートの作成・編集・公開・停止を行います。
        </p>

        <button
            class="button button-primary"
            data-screen="surveys"
        >
            アンケート管理
        </button>

    </div>


    <div class="menu-card">

        <h3>顧客管理</h3>

        <p>
            顧客確認・同期・送信対象選択を行います。
        </p>

        <button
            class="button"
            data-screen="customers"
        >
            顧客管理
        </button>

    </div>


    <div class="menu-card">

        <h3>送信履歴</h3>

        <p>
            メール送信結果と再送を確認します。
        </p>

        <button
            class="button"
            data-screen="send-history"
        >
            送信履歴
        </button>

    </div>


    <div class="menu-card">

        <h3>集計</h3>

        <p>
            アンケート回答結果を確認します。
        </p>

        <button
            class="button"
            data-screen="summary"
        >
            集計
        </button>

    </div>


    <div class="menu-card">

        <h3>kintone設定</h3>

        <p>
            接続設定・項目取得・顧客同期を管理します。
        </p>

        <button
            class="button"
            data-screen="kintone"
        >
            kintone設定
        </button>

    </div>


    <div class="menu-card">

        <h3>SMTP設定</h3>

        <p>
            メールサーバー設定とテストメールを管理します。
        </p>

        <button
            class="button"
            data-screen="smtp"
        >
            SMTP設定
        </button>

    </div>

</div>


<?php


/* ============================================================
 * S02 アンケート一覧
 * ============================================================ */

elseif ($screen === 'surveys'):

?>

<div class="page-header">

    <h1 class="page-title">
        アンケート一覧
    </h1>

    <div>

        <button
            class="button button-primary"
            data-screen="survey"
            data-survey-id="survey_new"
        >
            新規作成
        </button>

        <button
            class="button"
            data-screen="admin"
        >
            トップへ戻る
        </button>

    </div>

</div>


<div class="card">

    <h2>アンケート</h2>

    <div class="info">
        現在は画面フロー確認用のサンプルデータを表示しています。
    </div>


    <table
        style="width:100%; border-collapse:collapse;"
    >

        <thead>

        <tr>

            <th style="text-align:left;padding:10px;">
                アンケート
            </th>

            <th style="text-align:left;padding:10px;">
                状態
            </th>

            <th style="text-align:left;padding:10px;">
                操作
            </th>

        </tr>

        </thead>

        <tbody>

        <tr>

            <td style="padding:10px;">
                顧客満足度アンケート
            </td>

            <td style="padding:10px;">
                <span class="status">
                    draft
                </span>
            </td>

            <td style="padding:10px;">

                <button
                    class="button"
                    data-screen="survey"
                    data-survey-id="survey_001"
                >
                    編集
                </button>

                <button
                    class="button"
                    data-screen="survey"
                    data-survey-id="survey_001"
                >
                    複製
                </button>

                <button
                    class="button"
                    data-screen="summary"
                    data-survey-id="survey_001"
                >
                    集計
                </button>

                <button
                    class="button"
                    data-screen="send"
                    data-survey-id="survey_001"
                >
                    送信
                </button>

                <button
                    class="button button-success"
                    type="button"
                    data-demo-action="publish"
                >
                    公開
                </button>

                <button
                    class="button button-danger"
                    type="button"
                    data-demo-action="delete"
                >
                    削除
                </button>

            </td>

        </tr>

        </tbody>

    </table>

</div>


<?php


/* ============================================================
 * S03 アンケート編集
 * ============================================================ */

elseif ($screen === 'survey'):

?>

<div class="page-header">

    <h1 class="page-title">
        アンケート編集
    </h1>

</div>


<div class="info">

    対象 surveyId：
    <strong><?= h($surveyId) ?></strong>

</div>


<div class="card">

    <div class="form-row">

        <label>
            アンケート名称
        </label>

        <input
            type="text"
            value="顧客満足度アンケート"
        >

    </div>


    <div class="form-row">

        <label>
            説明
        </label>

        <textarea rows="4">アンケートへのご協力をお願いします。</textarea>

    </div>


    <div class="form-row">

        <label>
            状態
        </label>

        <select>

            <option>draft</option>
            <option>published</option>
            <option>stopped</option>
            <option>ended</option>

        </select>

    </div>


    <button
        class="button button-primary"
        type="button"
        data-demo-action="save"
    >
        保存
    </button>


    <button
        class="button"
        data-screen="questions"
        data-survey-id="<?= h($surveyId ?: 'survey_001') ?>"
    >
        質問管理
    </button>


    <button
        class="button"
        data-screen="conditions"
        data-survey-id="<?= h($surveyId ?: 'survey_001') ?>"
    >
        条件分岐設定
    </button>


    <button
        class="button button-success"
        type="button"
        data-demo-action="publish"
    >
        公開
    </button>


    <button
        class="button button-warning"
        type="button"
        data-demo-action="stop"
    >
        停止
    </button>


    <button
        class="button"
        type="button"
        data-demo-action="resume"
    >
        再開
    </button>


    <button
        class="button"
        data-screen="surveys"
    >
        戻る
    </button>

</div>


<?php


/* ============================================================
 * S04 質問管理
 * ============================================================ */

elseif ($screen === 'questions'):

?>

<div class="page-header">

    <h1 class="page-title">
        質問管理
    </h1>

</div>


<div class="info">

    対象 surveyId：
    <strong><?= h($surveyId) ?></strong>

</div>


<div class="card">

    <button
        class="button button-primary"
        data-screen="question-edit"
        data-survey-id="<?= h($surveyId) ?>"
    >
        質問追加
    </button>


    <button
        class="button"
        data-screen="conditions"
        data-survey-id="<?= h($surveyId) ?>"
    >
        条件分岐設定
    </button>


    <button
        class="button"
        data-screen="survey"
        data-survey-id="<?= h($surveyId) ?>"
    >
        戻る
    </button>

</div>


<div class="card">

    <h2>質問一覧</h2>

    <div style="margin-bottom:15px;">

        <strong>Q1.</strong>
        サービスに満足していますか？

        <button
            class="button"
            data-screen="question-edit"
            data-survey-id="<?= h($surveyId) ?>"
            data-question-id="question_001"
        >
            編集
        </button>

        <button
            class="button button-danger"
            type="button"
            data-demo-action="delete"
        >
            削除
        </button>

    </div>


    <div>

        <strong>Q2.</strong>
        改善点を教えてください。

        <button
            class="button"
            data-screen="question-edit"
            data-survey-id="<?= h($surveyId) ?>"
            data-question-id="question_002"
        >
            編集
        </button>

        <button
            class="button button-danger"
            type="button"
            data-demo-action="delete"
        >
            削除
        </button>

    </div>

</div>


<?php


/* ============================================================
 * S05 質問編集
 * ============================================================ */

elseif ($screen === 'question-edit'):

?>

<div class="page-header">

    <h1 class="page-title">
        質問編集
    </h1>

</div>


<div class="info">

    surveyId：
    <strong><?= h($surveyId) ?></strong>

    <br>

    questionId：
    <strong>
        <?= h($questionId ?: '新規質問') ?>
    </strong>

</div>


<div class="card">

    <div class="form-row">

        <label>
            質問文
        </label>

        <textarea rows="4">質問内容を入力してください。</textarea>

    </div>


    <div class="form-row">

        <label>
            回答形式
        </label>

        <select>

            <option>text</option>
            <option>textarea</option>
            <option>radio</option>
            <option>checkbox</option>
            <option>select</option>

        </select>

    </div>


    <div class="form-row">

        <label>
            必須
        </label>

        <select>

            <option>必須</option>
            <option>任意</option>

        </select>

    </div>


    <button
        class="button button-primary"
        type="button"
        data-screen="questions"
        data-survey-id="<?= h($surveyId) ?>"
    >
        保存
    </button>


    <button
        class="button"
        data-screen="questions"
        data-survey-id="<?= h($surveyId) ?>"
    >
        キャンセル
    </button>


    <?php if ($questionId !== ''): ?>

    <button
        class="button button-danger"
        type="button"
        data-demo-action="delete"
    >
        削除
    </button>

    <?php endif; ?>

</div>


<?php


/* ============================================================
 * S06 条件分岐
 * ============================================================ */

elseif ($screen === 'conditions'):

?>

<div class="page-header">

    <h1 class="page-title">
        条件分岐設定
    </h1>

</div>


<div class="info">

    対象 surveyId：
    <strong><?= h($surveyId) ?></strong>

</div>


<div class="card">

    <button
        class="button button-primary"
        type="button"
        data-demo-action="add-condition"
    >
        条件追加
    </button>


    <button
        class="button"
        type="button"
        data-demo-action="save"
    >
        保存
    </button>


    <button
        class="button"
        data-screen="questions"
        data-survey-id="<?= h($surveyId) ?>"
    >
        戻る
    </button>

</div>


<div class="card">

    <h2>条件一覧</h2>

    <p>
        Q1の回答が「はい」の場合 → Q2へ
    </p>

    <button
        class="button"
        type="button"
        data-demo-action="edit"
    >
        編集
    </button>

    <button
        class="button button-danger"
        type="button"
        data-demo-action="delete"
    >
        削除
    </button>

</div>


<?php


/* ============================================================
 * S07 顧客一覧
 * ============================================================ */

elseif ($screen === 'customers'):

?>

<div class="page-header">

    <h1 class="page-title">
        顧客一覧
    </h1>

</div>


<div class="card">

    <div class="form-row">

        <label>
            顧客検索
        </label>

        <input
            type="text"
            placeholder="顧客名・メールアドレス"
        >

    </div>


    <button
        class="button"
        type="button"
        data-demo-action="search"
    >
        検索
    </button>


    <button
        class="button button-primary"
        type="button"
        data-demo-action="sync"
    >
        kintone同期
    </button>


    <button
        class="button"
        data-screen="send"
        data-survey-id="survey_001"
    >
        送信対象選択
    </button>


    <button
        class="button"
        data-screen="admin"
    >
        戻る
    </button>

</div>


<div class="card">

    <h2>顧客</h2>

    <label>
        <input type="checkbox">
        株式会社サンプル
        customer_001
        sample@example.com
    </label>

</div>


<?php


/* ============================================================
 * S08 メール送信
 * ============================================================ */

elseif ($screen === 'send'):

?>

<div class="page-header">

    <h1 class="page-title">
        メール送信
    </h1>

</div>


<div class="info">

    対象 surveyId：
    <strong><?= h($surveyId) ?></strong>

</div>


<div class="card">

    <div class="form-row">

        <label>
            メール件名
        </label>

        <input
            type="text"
            value="アンケートご協力のお願い"
        >

    </div>


    <div class="form-row">

        <label>
            メール本文
        </label>

        <textarea rows="10">アンケートへのご協力をお願いします。</textarea>

    </div>


    <div class="form-row">

        <strong>
            送信対象：1名
        </strong>
    </div>


    <button
        class="button button-primary"
        data-screen="send-confirm"
        data-survey-id="<?= h($surveyId) ?>"
    >
        送信確認
    </button>


    <button
        class="button"
        data-screen="customers"
    >
        戻る
    </button>

</div>


<?php


/* ============================================================
 * S09 送信確認
 * ============================================================ */

elseif ($screen === 'send-confirm'):

?>

<div class="page-header">

    <h1 class="page-title">
        送信確認
    </h1>

</div>


<div class="card">

    <p>
        対象アンケート：
        <?= h($surveyId) ?>
    </p>

    <p>
        送信対象人数：1名
    </p>

    <p>
        件名：アンケートご協力のお願い
    </p>

    <p>
        本文：アンケートへのご協力をお願いします。
    </p>


    <button
        class="button button-primary"
        data-screen="send"
        data-survey-id="<?= h($surveyId) ?>"
        data-demo-action="send"
    >
        送信実行
    </button>


    <button
        class="button"
        data-screen="send"
        data-survey-id="<?= h($surveyId) ?>"
    >
        修正
    </button>


    <button
        class="button"
        data-screen="send"
        data-survey-id="<?= h($surveyId) ?>"
    >
        キャンセル
    </button>

</div>


<?php


/* ============================================================
 * S10 送信履歴
 * ============================================================ */

elseif ($screen === 'send-history'):

?>

<div class="page-header">

    <h1 class="page-title">
        送信履歴
    </h1>

</div>


<div class="card">

    <table style="width:100%;">

        <tr>

            <th>送信日時</th>
            <th>surveyId</th>
            <th>customerId</th>
            <th>結果</th>
            <th>操作</th>

        </tr>

        <tr>

            <td>2026-08-26 10:00</td>

            <td>survey_001</td>

            <td>customer_001</td>

            <td>
                <span class="status">
                    成功
                </span>
            </td>

            <td>

                <button
                    class="button"
                    type="button"
                    data-demo-action="resend"
                >
                    再送
                </button>

                <button
                    class="button"
                    type="button"
                    data-demo-action="detail"
                >
                    詳細
                </button>

            </td>

        </tr>

    </table>

</div>


<?php


/* ============================================================
 * S11 集計
 * ============================================================ */

elseif ($screen === 'summary'):

?>

<div class="page-header">

    <h1 class="page-title">
        集計
    </h1>

</div>


<div class="info">

    対象 surveyId：
    <strong><?= h($surveyId) ?></strong>

</div>


<div class="grid">

    <div class="menu-card">

        <h3>送信対象者数</h3>

        <strong style="font-size:30px;">
            100
        </strong>

    </div>


    <div class="menu-card">

        <h3>回答数</h3>

        <strong style="font-size:30px;">
            72
        </strong>

    </div>


    <div class="menu-card">

        <h3>回答率</h3>

        <strong style="font-size:30px;">
            72%
        </strong>

    </div>

</div>


<div class="card">

    <button
        class="button"
        type="button"
        data-demo-action="csv"
    >
        CSV出力
    </button>


    <button
        class="button"
        type="button"
        data-demo-action="pdf"
    >
        PDF出力
    </button>


    <button
        class="button"
        data-screen="surveys"
    >
        戻る
    </button>

</div>


<?php


/* ============================================================
 * S12 kintone設定
 * ============================================================ */

elseif ($screen === 'kintone'):

?>

<div class="page-header">

    <h1 class="page-title">
        kintone設定
    </h1>

</div>


<div class="card">

    <div class="form-row">

        <label>subdomain</label>

        <input
            type="text"
            placeholder="xxxx.cybozu.com"
        >

    </div>


    <div class="form-row">

        <label>appId</label>

        <input type="text">
    </div>


    <div class="form-row">

        <label>loginName</label>

        <input type="text">
    </div>


    <div class="form-row">

        <label>password</label>

        <input
            type="password"
        >
    </div>


    <div class="form-row">

        <label>sslVerify</label>

        <select>

            <option value="false">
                false
            </option>

            <option value="true">
                true
            </option>

        </select>

    </div>


    <div class="form-row">

        <label>proxy</label>

        <input
            type="text"
            placeholder="host:port"
        >

    </div>


    <button
        class="button button-primary"
        type="button"
        data-demo-action="save"
    >
        保存
    </button>


    <button
        class="button"
        type="button"
        data-demo-action="test-kintone"
    >
        接続テスト
    </button>


    <button
        class="button"
        type="button"
        data-demo-action="fields"
    >
        項目一覧取得
    </button>


    <button
        class="button"
        type="button"
        data-demo-action="sync"
    >
        顧客同期
    </button>

</div>


<?php


/* ============================================================
 * S13 SMTP設定
 * ============================================================ */

elseif ($screen === 'smtp'):

?>

<div class="page-header">

    <h1 class="page-title">
        SMTP設定
    </h1>

</div>


<div class="card">

<?php

$smtpFields = [
    'smtpHost',
    'smtpPort',
    'encryption',
    'auth',
    'username',
    'password',
    'fromAddress',
    'fromName',
    'replyTo',
];

foreach ($smtpFields as $field):

?>

<div class="form-row">

    <label>
        <?= h($field) ?>
    </label>

    <input
        type="<?= $field === 'password'
            ? 'password'
            : 'text' ?>"
    >

</div>

<?php endforeach; ?>


<button
    class="button button-primary"
    type="button"
    data-demo-action="save"
>
    保存
</button>


<button
    class="button"
    type="button"
    data-demo-action="smtp-test"
>
    テストメール
</button>

</div>


<?php


/* ============================================================
 * S14 システム設定
 * ============================================================ */

elseif ($screen === 'settings'):

?>

<div class="page-header">

    <h1 class="page-title">
        システム設定
    </h1>

</div>


<div class="card">

    <div class="form-row">

        <label>
            システム名
        </label>

        <input
            type="text"
            value="アンケート管理システム"
        >

    </div>


    <button
        class="button button-primary"
        type="button"
        data-demo-action="save"
    >
        保存
    </button>

</div>


<?php


/* ============================================================
 * A01 回答開始
 * ============================================================ */

elseif ($screen === 'start'):

?>

<div class="page-header">

    <h1 class="page-title">
        アンケート回答開始
    </h1>

</div>


<div class="card">

    <p>
        アンケートへの回答を開始します。
    </p>

    <p>
        surveyId：
        <?= h($surveyId) ?>
    </p>

    <p>
        customerId：
        <?= h($customerId) ?>
    </p>


    <button
        class="button button-primary"
        data-screen="answer"
        data-survey-id="<?= h($surveyId) ?>"
        data-customer-id="<?= h($customerId) ?>"
    >
        回答開始
    </button>

</div>


<?php


/* ============================================================
 * A02 回答
 * ============================================================ */

elseif ($screen === 'answer'):

?>

<div class="page-header">

    <h1 class="page-title">
        アンケート回答
    </h1>

</div>


<div class="card">

    <div class="info">

        surveyId：
        <?= h($surveyId) ?>

        <br>

        customerId：
        <?= h($customerId) ?>

    </div>


    <div class="form-row">

        <label>
            Q1. サービスに満足していますか？
        </label>

        <select>

            <option>選択してください</option>
            <option>はい</option>
            <option>いいえ</option>

        </select>

    </div>


    <button
        class="button"
        data-screen="answer"
        data-survey-id="<?= h($surveyId) ?>"
        data-customer-id="<?= h($customerId) ?>"
    >
        次へ
    </button>


    <button
        class="button"
        data-screen="answer"
        data-survey-id="<?= h($surveyId) ?>"
        data-customer-id="<?= h($customerId) ?>"
    >
        戻る
    </button>


    <button
        class="button button-primary"
        data-screen="confirm"
        data-survey-id="<?= h($surveyId) ?>"
        data-customer-id="<?= h($customerId) ?>"
    >
        確認
    </button>

</div>


<?php


/* ============================================================
 * A03 回答確認
 * ============================================================ */

elseif ($screen === 'confirm'):

?>

<div class="page-header">

    <h1 class="page-title">
        回答内容確認
    </h1>

</div>


<div class="card">

    <p>
        Q1：
        はい
    </p>


    <button
        class="button"
        data-screen="answer"
        data-survey-id="<?= h($surveyId) ?>"
        data-customer-id="<?= h($customerId) ?>"
    >
        修正
    </button>


    <button
        class="button button-primary"
        data-screen="complete"
        data-survey-id="<?= h($surveyId) ?>"
        data-customer-id="<?= h($customerId) ?>"
        data-demo-action="submit-answer"
    >
        送信
    </button>

</div>


<?php


/* ============================================================
 * A04 回答完了
 * ============================================================ */

elseif ($screen === 'complete'):

?>

<div class="page-header">

    <h1 class="page-title">
        回答完了
    </h1>

</div>


<div class="card">

    <div class="info">

        回答を正常に受け付けました。

    </div>

    <p>
        ご回答ありがとうございました。
    </p>

</div>


<?php


/* ============================================================
 * A05 回答済み
 * ============================================================ */

elseif ($screen === 'already-answered'):

?>

<div class="page-header">

    <h1 class="page-title">
        回答済み
    </h1>

</div>


<div class="card">

    <div class="warning">

        このアンケートはすでに回答済みです。

    </div>

</div>


<?php


/* ============================================================
 * A06 回答不可
 * ============================================================ */

elseif ($screen === 'unavailable'):

?>

<div class="page-header">

    <h1 class="page-title">
        回答不可
    </h1>

</div>


<div class="card">

    <div class="warning">

        現在、このアンケートには回答できません。

    </div>

</div>


<?php endif; ?>


</div>

</main>

</div>

</div>


<script>

/* ============================================================
 * JavaScript
 * URLを画面状態の正規情報として扱う
 * ============================================================ */

(function () {

    'use strict';


    /* --------------------------------------------------------
     * 現在のアプリケーション入口
     * -------------------------------------------------------- */

    const APP_ENTRY =
        window.location.pathname;


    /* --------------------------------------------------------
     * URL生成
     * -------------------------------------------------------- */

    function buildUrl(screen, params = {}) {

        const url =
            new URL(
                window.location.href
            );

        url.search = '';

        url.searchParams.set(
            'screen',
            screen
        );


        Object.keys(params).forEach(function (key) {

            const value = params[key];

            if (
                value !== undefined
                && value !== null
                && value !== ''
            ) {

                url.searchParams.set(
                    key,
                    value
                );
            }

        });


        return (
            url.pathname
            + url.search
        );
    }


    /* --------------------------------------------------------
     * 画面遷移
     *
     * 業務上別画面へ移動する場合はpushState
     * -------------------------------------------------------- */

    function navigate(
        screen,
        params = {}
    ) {

        const target =
            buildUrl(
                screen,
                params
            );


        window.history.pushState(
            {
                screen: screen,
                params: params
            },
            '',
            target
        );


        /*
         * URLを変更しただけではPHP画面は再描画されない。
         *
         * 今回は安全側として、
         * pushState後にURLへ再アクセスする。
         *
         * 次段階ではrenderScreen()へ置き換える。
         */
        window.location.href =
            target;
    }


    /* --------------------------------------------------------
     * data-screenボタン
     * -------------------------------------------------------- */

    document.addEventListener(
        'click',
        function (event) {

            const target =
                event.target.closest(
                    '[data-screen]'
                );


            if (!target) {
                return;
            }


            event.preventDefault();


            const screen =
                target.dataset.screen;


            const params = {};


            if (target.dataset.surveyId) {

                params.surveyId =
                    target.dataset.surveyId;
            }


            if (target.dataset.customerId) {

                params.customerId =
                    target.dataset.customerId;
            }


            if (target.dataset.questionId) {

                params.questionId =
                    target.dataset.questionId;
            }


            navigate(
                screen,
                params
            );

        }
    );


    /* --------------------------------------------------------
     * デモ操作
     *
     * 現段階では業務API未実装。
     * 勝手に「実装済み」と扱わない。
     * -------------------------------------------------------- */

    document.addEventListener(
        'click',
        function (event) {

            const target =
                event.target.closest(
                    '[data-demo-action]'
                );


            if (!target) {
                return;
            }


            event.preventDefault();


            const action =
                target.dataset.demoAction;


            alert(
                'この操作の業務APIは次段階で実装します。\n\n'
                + 'action: '
                + action
            );

        }
    );


    /* --------------------------------------------------------
     * popstate
     * -------------------------------------------------------- */

    window.addEventListener(
        'popstate',
        function () {

            /*
             * JavaScript内部状態を正としない。
             *
             * 現在URLから画面を再構築する。
             */
            window.location.reload();

        }
    );


})();

</script>


</body>

</html>

<?php

ob_end_flush();
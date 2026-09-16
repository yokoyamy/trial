<?php
/*
 * アンケート管理アプリ モック
 *
 * Apache + PHP / 1ファイル構成
 *
 * 重要:
 * - localStorage / sessionStorage は使用しません。
 * - PHPセッションをモック状態の保存先として使用します。
 * - sandbox iframe 等でPHPセッションが利用できない場合も、
 *   JavaScriptメモリ上で操作を継続できます。
 * - DB / kintone / SMTP には接続しません。
 */

session_start();

$appTitle = 'アンケート管理アプリ';

/* =========================================================
   PHP Session API
   ========================================================= */

if (isset($_GET['mock_api'])) {
    header('Content-Type: application/json; charset=UTF-8');

    $api = (string)$_GET['mock_api'];

    if ($api === 'load') {
        echo json_encode(
            $_SESSION['questionnaire_mock_state'] ?? null,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode([
                'ok' => false,
                'message' => 'POST only'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid JSON'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $_SESSION['questionnaire_mock_state'] = $data;

        echo json_encode([
            'ok' => true
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($api === 'reset') {
        unset($_SESSION['questionnaire_mock_state']);

        echo json_encode([
            'ok' => true
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);

    echo json_encode([
        'ok' => false,
        'message' => 'Unknown API'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= htmlspecialchars($appTitle, ENT_QUOTES, 'UTF-8') ?></title>

<style>
:root {
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --info:#0891b2;

    --gray-50:#f8fafc;
    --gray-100:#f1f5f9;
    --gray-200:#e2e8f0;
    --gray-300:#cbd5e1;
    --gray-400:#94a3b8;
    --gray-500:#64748b;
    --gray-600:#475569;
    --gray-700:#334155;
    --gray-800:#1e293b;
    --gray-900:#0f172a;

    --white:#fff;

    --shadow:0 2px 10px rgba(15,23,42,.08);
    --radius:10px;
}

* {
    box-sizing:border-box;
}

html,
body {
    margin:0;
    padding:0;
    min-height:100%;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    background:var(--gray-50);
    color:var(--gray-800);
    font-size:14px;
}

button,
input,
select,
textarea {
    font:inherit;
}

button {
    cursor:pointer;
}

button:disabled {
    cursor:not-allowed;
    opacity:.55;
}

textarea {
    min-height:100px;
    resize:vertical;
}

input[type="text"],
input[type="email"],
input[type="datetime-local"],
input[type="number"],
select,
textarea {
    width:100%;
    border:1px solid var(--gray-300);
    border-radius:7px;
    padding:9px 10px;
    background:#fff;
    color:var(--gray-800);
}

input:focus,
select:focus,
textarea:focus {
    outline:none;
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.1);
}

a {
    color:inherit;
    text-decoration:none;
}

/* =========================================================
   Layout
   ========================================================= */

.app {
    min-height:100vh;
    display:flex;
}

.sidebar {
    width:250px;
    background:#172033;
    color:#fff;
    position:fixed;
    inset:0 auto 0 0;
    overflow-y:auto;
    z-index:30;
}

.logo {
    height:68px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    padding:0 22px;
    border-bottom:1px solid rgba(255,255,255,.1);
    font-size:18px;
    font-weight:700;
}

.logo small {
    display:block;
    font-size:10px;
    font-weight:400;
    color:#94a3b8;
    margin-top:3px;
}

.nav {
    padding:14px 10px;
}

.nav-section {
    color:#64748b;
    font-size:11px;
    margin:15px 10px 7px;
    font-weight:700;
}

.nav button {
    width:100%;
    border:0;
    background:transparent;
    color:#cbd5e1;
    text-align:left;
    padding:10px 12px;
    border-radius:7px;
    margin-bottom:2px;
}

.nav button:hover,
.nav button.active {
    background:#26344f;
    color:#fff;
}

.nav button .icon {
    width:22px;
    display:inline-block;
}

.main {
    margin-left:250px;
    width:calc(100% - 250px);
    min-height:100vh;
}

.topbar {
    height:68px;
    background:#fff;
    border-bottom:1px solid var(--gray-200);
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 28px;
    position:sticky;
    top:0;
    z-index:20;
}

.topbar-title {
    font-size:17px;
    font-weight:700;
}

.user {
    color:var(--gray-500);
    font-size:13px;
}

.content {
    padding:28px;
    max-width:1600px;
    margin:0 auto;
}

/* =========================================================
   Common
   ========================================================= */

.page-head {
    display:flex;
    justify-content:space-between;
    gap:20px;
    align-items:flex-start;
    margin-bottom:22px;
}

.page-title {
    margin:0;
    font-size:24px;
    color:var(--gray-900);
}

.page-description {
    margin:7px 0 0;
    color:var(--gray-500);
    line-height:1.7;
}

.actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.btn {
    border:1px solid var(--gray-300);
    background:#fff;
    color:var(--gray-700);
    padding:8px 13px;
    border-radius:7px;
    font-weight:600;
    line-height:1.2;
}

.btn:hover {
    background:var(--gray-50);
}

.btn-primary {
    color:#fff;
    background:var(--primary);
    border-color:var(--primary);
}

.btn-primary:hover {
    background:var(--primary-dark);
}

.btn-success {
    color:#fff;
    background:var(--success);
    border-color:var(--success);
}

.btn-warning {
    color:#fff;
    background:var(--warning);
    border-color:var(--warning);
}

.btn-danger {
    color:#fff;
    background:var(--danger);
    border-color:var(--danger);
}

.btn-info {
    color:#fff;
    background:var(--info);
    border-color:var(--info);
}

.btn-sm {
    padding:6px 9px;
    font-size:12px;
}

.btn-link {
    border:0;
    background:transparent;
    color:var(--primary);
    padding:2px 4px;
    font-weight:600;
}

.card {
    background:#fff;
    border:1px solid var(--gray-200);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.card-head {
    padding:16px 18px;
    border-bottom:1px solid var(--gray-200);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
}

.card-body {
    padding:18px;
}

.card-title {
    margin:0;
    font-size:16px;
    font-weight:700;
}

.muted {
    color:var(--gray-500);
}

.small {
    font-size:12px;
}

.help {
    margin-top:5px;
    font-size:12px;
    color:var(--gray-500);
}

.form-group {
    margin-bottom:14px;
}

.form-label {
    display:block;
    font-weight:700;
    margin-bottom:6px;
    color:var(--gray-700);
}

.required {
    color:var(--danger);
}

.form-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-grid .full {
    grid-column:1 / -1;
}

.info-box,
.success-box,
.error-box,
.warning-box {
    border-radius:8px;
    padding:12px 14px;
    margin-bottom:15px;
    line-height:1.7;
}

.info-box {
    border:1px solid #bae6fd;
    background:#f0f9ff;
    color:#075985;
}

.success-box {
    border:1px solid #bbf7d0;
    background:#f0fdf4;
    color:#166534;
}

.error-box {
    border:1px solid #fecaca;
    background:#fef2f2;
    color:#991b1b;
}

.warning-box {
    border:1px solid #fcd34d;
    background:#fffbeb;
    color:#92400e;
}

.error-box ul {
    margin:7px 0 0 18px;
}

/* =========================================================
   Status
   ========================================================= */

.status {
    display:inline-flex;
    align-items:center;
    padding:4px 8px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
    border:1px solid transparent;
}

.status-draft {
    color:#475569;
    background:#f1f5f9;
    border-color:#cbd5e1;
}

.status-wait {
    color:#92400e;
    background:#fef3c7;
    border-color:#fcd34d;
}

.status-active {
    color:#166534;
    background:#dcfce7;
    border-color:#86efac;
}

.status-ended {
    color:#1e40af;
    background:#dbeafe;
    border-color:#93c5fd;
}

.status-archived {
    color:#475569;
    background:#e2e8f0;
    border-color:#cbd5e1;
}

/* =========================================================
   Tables
   ========================================================= */

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
    min-width:850px;
}

th,
td {
    padding:11px 12px;
    border-bottom:1px solid var(--gray-200);
    vertical-align:middle;
    text-align:left;
}

th {
    background:var(--gray-50);
    color:var(--gray-600);
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}

tr:last-child td {
    border-bottom:0;
}

.actions-cell {
    white-space:nowrap;
}

.actions-cell .btn {
    margin:2px;
}

/* =========================================================
   Dashboard
   ========================================================= */

.stat-grid {
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:14px;
    margin-bottom:24px;
}

.stat-card {
    background:#fff;
    border:1px solid var(--gray-200);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:18px;
    cursor:pointer;
}

.stat-card:hover {
    border-color:var(--primary);
}

.stat-label {
    color:var(--gray-500);
    font-size:12px;
}

.stat-number {
    font-size:30px;
    font-weight:800;
    margin:7px 0 0;
}

.dashboard-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
}

/* =========================================================
   Editor
   ========================================================= */

.editor-layout {
    display:grid;
    grid-template-columns:240px minmax(0,1fr);
    gap:18px;
}

.editor-sidebar {
    position:sticky;
    top:88px;
    align-self:start;
}

.editor-nav-item {
    width:100%;
    border:0;
    background:transparent;
    padding:10px 12px;
    text-align:left;
    border-radius:7px;
    margin-bottom:3px;
}

.editor-nav-item:hover,
.editor-nav-item.active {
    background:#eff6ff;
    color:var(--primary);
}

.group-list {
    display:flex;
    flex-direction:column;
    gap:14px;
}

.group-box {
    border:1px solid var(--gray-200);
    border-radius:9px;
    background:#fff;
}

.group-box.drag-over {
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.08);
}

.group-head {
    padding:12px 14px;
    background:var(--gray-50);
    border-bottom:1px solid var(--gray-200);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
}

.group-name {
    font-weight:700;
}

.group-body {
    padding:10px;
}

.question-list {
    min-height:20px;
}

.question-card {
    border:1px solid var(--gray-200);
    border-radius:8px;
    margin-bottom:8px;
    padding:12px;
    background:#fff;
}

.question-card:last-child {
    margin-bottom:0;
}

.question-card.dragging {
    opacity:.45;
}

.question-card.drag-over {
    border-color:var(--primary);
    background:#eff6ff;
}

.question-head {
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
}

.question-main {
    display:flex;
    gap:8px;
    flex:1;
    min-width:0;
}

.drag-handle {
    cursor:grab;
    color:var(--gray-400);
    font-size:18px;
}

.question-title {
    flex:1;
    min-width:0;
}

.question-number {
    color:var(--primary);
    font-weight:800;
}

.question-actions {
    white-space:nowrap;
}

.choice-list {
    margin-top:10px;
    display:grid;
    gap:7px;
}

.choice-row {
    display:flex;
    gap:7px;
}

.choice-row input {
    flex:1;
}

.question-add-footer {
    display:flex;
    justify-content:center;
    padding:10px 0 2px;
}

.group-list-footer {
    display:flex;
    justify-content:center;
    padding:14px 0 0;
}

.branch-row {
    display:grid;
    grid-template-columns:180px 1fr;
    gap:8px;
    margin-top:8px;
    padding:9px;
    background:var(--gray-50);
    border-radius:7px;
}

.branch-box {
    margin-top:12px;
    padding:10px;
    background:#f8fafc;
    border-radius:8px;
}

/* =========================================================
   Preview / Answer
   ========================================================= */

.preview-shell {
    max-width:900px;
    margin:0 auto;
}

.preview-header {
    padding:26px;
    background:#fff;
    border:1px solid var(--gray-200);
    border-radius:var(--radius);
    margin-bottom:16px;
}

.answer-question {
    background:#fff;
    border:1px solid var(--gray-200);
    border-radius:var(--radius);
    padding:18px;
    margin-bottom:13px;
}

.answer-question-title {
    font-weight:700;
    margin-bottom:12px;
    line-height:1.7;
}

.required-label {
    color:var(--danger);
    font-size:11px;
    border:1px solid #fecaca;
    background:#fef2f2;
    padding:2px 6px;
    border-radius:999px;
    margin-left:6px;
}

.option {
    margin:8px 0;
    display:flex;
    align-items:flex-start;
    gap:8px;
}

.answer-footer {
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:18px;
}

/* =========================================================
   KPI
   ========================================================= */

.kpi-grid {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
}

.kpi {
    border:1px solid var(--gray-200);
    border-radius:9px;
    padding:15px;
}

.kpi-label {
    font-size:12px;
    color:var(--gray-500);
}

.kpi-value {
    font-size:26px;
    font-weight:800;
    margin-top:5px;
}

/* =========================================================
   Search
   ========================================================= */

.search-panel {
    display:grid;
    grid-template-columns:1fr 180px auto;
    gap:10px;
    align-items:end;
}

/* =========================================================
   Modal / Toast
   ========================================================= */

.modal-backdrop {
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.48);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:100;
    padding:20px;
}

.modal-backdrop.show {
    display:flex;
}

.modal {
    width:min(760px,100%);
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:12px;
    box-shadow:0 20px 60px rgba(15,23,42,.25);
}

.modal-head {
    padding:17px 20px;
    border-bottom:1px solid var(--gray-200);
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.modal-body {
    padding:20px;
}

.modal-footer {
    padding:14px 20px;
    border-top:1px solid var(--gray-200);
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.modal-close {
    border:0;
    background:transparent;
    color:var(--gray-500);
    font-size:22px;
}

.toast {
    position:fixed;
    right:20px;
    bottom:20px;
    background:#172033;
    color:#fff;
    padding:12px 16px;
    border-radius:8px;
    box-shadow:var(--shadow);
    z-index:200;
    display:none;
}

.toast.show {
    display:block;
}

/* =========================================================
   Responsive
   ========================================================= */

@media (max-width:1100px) {
    .stat-grid {
        grid-template-columns:repeat(3,1fr);
    }

    .editor-layout {
        grid-template-columns:1fr;
    }

    .editor-sidebar {
        position:static;
    }
}

@media (max-width:800px) {
    .sidebar {
        width:68px;
    }

    .logo {
        padding:0;
        align-items:center;
        justify-content:center;
        font-size:0;
    }

    .logo::before {
        content:"A";
        font-size:22px;
        font-weight:800;
    }

    .logo small,
    .nav-section,
    .nav button span:not(.icon) {
        display:none;
    }

    .nav button {
        text-align:center;
        padding:11px 4px;
    }

    .main {
        margin-left:68px;
        width:calc(100% - 68px);
    }

    .content {
        padding:18px;
    }

    .dashboard-grid {
        grid-template-columns:1fr;
    }

    .form-grid {
        grid-template-columns:1fr;
    }

    .form-grid .full {
        grid-column:auto;
    }

    .stat-grid {
        grid-template-columns:repeat(2,1fr);
    }

    .kpi-grid {
        grid-template-columns:repeat(2,1fr);
    }

    .search-panel {
        grid-template-columns:1fr;
    }

    .branch-row {
        grid-template-columns:1fr;
    }
}

@media (max-width:500px) {
    .stat-grid {
        grid-template-columns:1fr;
    }

    .page-head {
        flex-direction:column;
    }

    .topbar {
        padding:0 14px;
    }

    .content {
        padding:12px;
    }

    .kpi-grid {
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<div class="app">

    <aside class="sidebar">

        <div class="logo">
            アンケート管理
            <small>Questionnaire Management</small>
        </div>

        <nav class="nav">

            <div class="nav-section">管理</div>

            <button data-page="home" onclick="navigate('home')">
                <span class="icon">⌂</span>
                <span>ホーム</span>
            </button>

            <button data-page="surveys" onclick="navigate('surveys')">
                <span class="icon">▤</span>
                <span>アンケート一覧</span>
            </button>

            <button data-page="responses" onclick="navigate('responses')">
                <span class="icon">▥</span>
                <span>回答状況</span>
            </button>

            <button data-page="response-detail" onclick="navigate('response-detail')">
                <span class="icon">☷</span>
                <span>回答内容</span>
            </button>

            <div class="nav-section">送付</div>

            <button data-page="send" onclick="openSend()">
                <span class="icon">✉</span>
                <span>アンケート送付</span>
            </button>

            <button data-page="send-result" onclick="navigate('send-result')">
                <span class="icon">✓</span>
                <span>送付結果</span>
            </button>

            <div class="nav-section">設定</div>

            <button data-page="settings" onclick="navigate('settings')">
                <span class="icon">⚙</span>
                <span>各種設定</span>
            </button>

            <div class="nav-section">回答者確認</div>

            <button data-page="answer" onclick="startAnswer()">
                <span class="icon">▣</span>
                <span>回答者画面</span>
            </button>

            <div class="nav-section">モック</div>

            <button onclick="resetMock()">
                <span class="icon">↻</span>
                <span>初期状態へ戻す</span>
            </button>

        </nav>

    </aside>

    <main class="main">

        <header class="topbar">
            <div id="topbarTitle" class="topbar-title">
                ホーム
            </div>

            <div class="user">
                アンケート運営管理者
            </div>
        </header>

        <div id="appContent" class="content"></div>

    </main>

</div>

<div id="modalBackdrop" class="modal-backdrop">

    <div class="modal">

        <div class="modal-head">

            <strong id="modalTitle">
                確認
            </strong>

            <button
                class="modal-close"
                onclick="closeModal()"
            >
                ×
            </button>

        </div>

        <div
            id="modalBody"
            class="modal-body"
        ></div>

        <div
            id="modalFooter"
            class="modal-footer"
        ></div>

    </div>

</div>

<div id="toast" class="toast"></div>

<script>
'use strict';

/* =========================================================
   Mock Data
   ========================================================= */

const defaultData = {
    currentSurveyId:1,
    currentPage:'home',
    listFilter:'all',
    listSearch:'',
    responseSurveyId:1,

    settings:{
        kintone:{
            host:'https://example.cybozu.com',
            app:'123',
            nameField:'顧客名',
            contactField:'担当者名',
            emailField:'メールアドレス',
            connected:true,
            updatedAt:'2026-09-16 09:30'
        },

        smtp:{
            host:'smtp.example.jp',
            port:'587',
            from:'questionnaire@example.jp',
            encryption:'STARTTLS',
            configured:true
        }
    },

    customers:[
        {
            id:1,
            name:'株式会社青山商事',
            contact:'田中 太郎',
            email:'tanaka@example.jp'
        },
        {
            id:2,
            name:'株式会社赤坂商会',
            contact:'佐藤 花子',
            email:'sato@example.jp'
        },
        {
            id:3,
            name:'東京サンプル株式会社',
            contact:'鈴木 一郎',
            email:'suzuki@example.jp'
        },
        {
            id:4,
            name:'港区ソリューションズ',
            contact:'高橋 次郎',
            email:'takahashi@example.jp'
        },
        {
            id:5,
            name:'サンプル製作所',
            contact:'伊藤 三郎',
            email:'ito@example.jp'
        },
        {
            id:6,
            name:'見本産業株式会社',
            contact:'渡辺 美咲',
            email:'watanabe@example.jp'
        }
    ],

    surveys:[
        {
            id:1,
            name:'2026年度 顧客満足度アンケート',
            description:'サービスをご利用いただいたお客様への満足度調査です。',
            guidance:'各質問にご回答ください。所要時間は約5分です。',
            completeMessage:'ご回答ありがとうございました。',
            status:'active',
            createdAt:'2026-08-01',
            updatedAt:'2026-09-15 16:20',
            startAt:'2026-09-01T09:00',
            endAt:'2026-09-30T18:00',
            numberMode:'global',
            sentCount:80,
            responseCount:42,
            selectedCustomerIds:[1,2,3,4,5,6],
            lastSentAt:'2026-09-10 10:00',

            groups:[
                {id:'g1',name:'基本情報'},
                {id:'g2',name:'サービス評価'}
            ],

            questions:[
                {
                    id:'q1',
                    groupId:'g1',
                    text:'当社サービスを利用したことがありますか？',
                    type:'single',
                    required:true,
                    help:'',
                    choices:[
                        {id:'c1',text:'はい'},
                        {id:'c2',text:'いいえ'}
                    ],
                    branches:{
                        c1:{type:'next'},
                        c2:{type:'question',target:'q4'}
                    }
                },
                {
                    id:'q2',
                    groupId:'g2',
                    text:'サービスの満足度を教えてください。',
                    type:'rating',
                    required:true,
                    help:'1が最低、5が最高です。',
                    choices:[
                        {id:'r1',text:'1'},
                        {id:'r2',text:'2'},
                        {id:'r3',text:'3'},
                        {id:'r4',text:'4'},
                        {id:'r5',text:'5'}
                    ],
                    branches:{}
                },
                {
                    id:'q3',
                    groupId:'g2',
                    text:'改善してほしい点があれば教えてください。',
                    type:'text',
                    required:false,
                    help:'',
                    choices:[],
                    branches:{}
                },
                {
                    id:'q4',
                    groupId:'g2',
                    text:'今後利用してみたいサービスを教えてください。',
                    type:'multiple',
                    required:false,
                    help:'',
                    choices:[
                        {id:'m1',text:'オンラインサポート'},
                        {id:'m2',text:'レポート機能'},
                        {id:'m3',text:'コンサルティング'}
                    ],
                    branches:{}
                }
            ],

            answers:[
                {
                    id:1,
                    number:'R-0001',
                    answeredAt:'2026-09-12 10:21',
                    respondent:'田中 太郎',
                    values:{
                        q1:'はい',
                        q2:'5',
                        q3:'特にありません。',
                        q4:['レポート機能']
                    }
                },
                {
                    id:2,
                    number:'R-0002',
                    answeredAt:'2026-09-12 14:05',
                    respondent:'佐藤 花子',
                    values:{
                        q1:'はい',
                        q2:'4',
                        q3:'サポート時間を増やしてほしい。',
                        q4:['オンラインサポート']
                    }
                },
                {
                    id:3,
                    number:'R-0003',
                    answeredAt:'2026-09-13 09:12',
                    respondent:'鈴木 一郎',
                    values:{
                        q1:'いいえ',
                        q4:['コンサルティング']
                    }
                }
            ]
        },

        {
            id:2,
            name:'新サービス利用意向調査',
            description:'新サービスについての利用意向を確認します。',
            guidance:'簡単なアンケートです。',
            completeMessage:'ご回答ありがとうございました。',
            status:'wait',
            createdAt:'2026-09-03',
            updatedAt:'2026-09-14 11:10',
            startAt:'2026-09-20T09:00',
            endAt:'2026-10-10T18:00',
            numberMode:'group',
            sentCount:25,
            responseCount:0,
            selectedCustomerIds:[1,2,3],
            lastSentAt:'2026-09-15 09:30',

            groups:[
                {id:'g1',name:'利用意向'},
                {id:'g2',name:'ご意見'}
            ],

            questions:[
                {
                    id:'q1',
                    groupId:'g1',
                    text:'新サービスを利用したいと思いますか？',
                    type:'single',
                    required:true,
                    help:'',
                    choices:[
                        {id:'c1',text:'ぜひ利用したい'},
                        {id:'c2',text:'検討したい'},
                        {id:'c3',text:'利用予定はない'}
                    ],
                    branches:{}
                },
                {
                    id:'q2',
                    groupId:'g2',
                    text:'ご意見があれば教えてください。',
                    type:'text',
                    required:false,
                    help:'',
                    choices:[],
                    branches:{}
                }
            ],

            answers:[]
        },

        {
            id:3,
            name:'2026年 上期サービス調査',
            description:'上期のサービス利用状況調査です。',
            guidance:'',
            completeMessage:'ご協力ありがとうございました。',
            status:'ended',
            createdAt:'2026-04-01',
            updatedAt:'2026-09-01 18:00',
            startAt:'2026-04-10T09:00',
            endAt:'2026-08-31T18:00',
            numberMode:'global',
            sentCount:120,
            responseCount:95,
            selectedCustomerIds:[],
            lastSentAt:'2026-04-10 09:00',

            groups:[
                {id:'g1',name:'サービス評価'}
            ],

            questions:[
                {
                    id:'q1',
                    groupId:'g1',
                    text:'サービス全体の満足度を教えてください。',
                    type:'rating',
                    required:true,
                    help:'',
                    choices:[
                        {id:'r1',text:'1'},
                        {id:'r2',text:'2'},
                        {id:'r3',text:'3'},
                        {id:'r4',text:'4'},
                        {id:'r5',text:'5'}
                    ],
                    branches:{}
                }
            ],

            answers:[]
        },

        {
            id:4,
            name:'2025年度 利用者アンケート',
            description:'昨年度の利用者アンケートです。',
            guidance:'',
            completeMessage:'ありがとうございました。',
            status:'archived',
            createdAt:'2025-04-01',
            updatedAt:'2026-04-01 10:00',
            startAt:'2025-04-10T09:00',
            endAt:'2025-09-30T18:00',
            numberMode:'global',
            sentCount:100,
            responseCount:81,
            selectedCustomerIds:[],
            lastSentAt:'2025-04-10 09:00',

            groups:[
                {id:'g1',name:'アンケート'}
            ],

            questions:[
                {
                    id:'q1',
                    groupId:'g1',
                    text:'昨年度のサービスに満足しましたか？',
                    type:'single',
                    required:true,
                    help:'',
                    choices:[
                        {id:'c1',text:'はい'},
                        {id:'c2',text:'いいえ'}
                    ],
                    branches:{}
                }
            ],

            answers:[]
        }
    ],

    sendResults:[
        {
            id:1,
            surveyId:1,
            target:80,
            success:78,
            failed:2,
            sentAt:'2026-09-10 10:00',
            failedCustomers:[
                'メールアドレス不備：株式会社青山商事',
                '送信エラー：港区ソリューションズ'
            ]
        }
    ]
};

/* =========================================================
   Runtime State
   ========================================================= */

let state = clone(defaultData);

let editingQuestionId = null;
let draggingQuestionId = null;
let sendDraft = null;

let answerState = {
    surveyId:null,
    currentIndex:0,
    values:{},
    visibleQuestions:[]
};

let serverPersistenceAvailable = false;

/* =========================================================
   Utility
   ========================================================= */

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function uid(prefix) {
    return prefix +
        '_' +
        Date.now().toString(36) +
        '_' +
        Math.random().toString(36).slice(2,8);
}

function nowString() {
    const d = new Date();

    const pad = n =>
        String(n).padStart(2,'0');

    return [
        d.getFullYear(),
        pad(d.getMonth()+1),
        pad(d.getDate())
    ].join('-') +
    ' ' +
    [
        pad(d.getHours()),
        pad(d.getMinutes())
    ].join(':');
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return String(value)
        .replace('T',' ');
}

function statusLabel(status) {
    return {
        draft:'作成中',
        wait:'回答開始待ち',
        active:'回答受付中',
        ended:'回答受付終了',
        archived:'保管'
    }[status] || status;
}

function statusBadge(status) {
    return `
        <span class="status status-${escapeHtml(status)}">
            ${escapeHtml(statusLabel(status))}
        </span>
    `;
}

function questionTypeLabel(type) {
    return {
        text:'文章を入力する質問',
        single:'1つだけ選ぶ質問',
        multiple:'複数選ぶ質問',
        rating:'段階的に評価する質問'
    }[type] || type;
}

function countByStatus(status) {
    return state.surveys.filter(
        s => s.status === status
    ).length;
}

function currentSurvey() {
    return state.surveys.find(
        s => String(s.id) === String(state.currentSurveyId)
    );
}

function setCurrentSurvey(id) {
    state.currentSurveyId = Number(id);
}

function canEdit(survey) {
    return !!survey &&
        (
            survey.status === 'draft' ||
            survey.status === 'wait'
        );
}

function canStructuralEdit(survey) {
    return !!survey &&
        survey.status === 'draft';
}

function questionNumber(survey,q) {
    if (!survey || !q) {
        return '-';
    }

    if (survey.numberMode === 'group') {
        const sameGroup =
            survey.questions.filter(
                x => x.groupId === q.groupId
            );

        const index =
            sameGroup.findIndex(
                x => x.id === q.id
            );

        return index >= 0
            ? index + 1
            : '-';
    }

    const index =
        survey.questions.findIndex(
            x => x.id === q.id
        );

    return index >= 0
        ? index + 1
        : '-';
}

function normalizeQuestion(q) {
    if (!Array.isArray(q.choices)) {
        q.choices = [];
    }

    if (!q.branches || typeof q.branches !== 'object') {
        q.branches = {};
    }

    q.choices = q.choices.map(
        (choice,index) => {
            if (typeof choice === 'string') {
                return {
                    id:'c_' + index + '_' + Date.now(),
                    text:choice
                };
            }

            return {
                id:choice.id || uid('c'),
                text:choice.text || ''
            };
        }
    );
}

function responseRate(survey) {
    const target =
        Number(survey.sentCount || 0);

    const response =
        Number(survey.responseCount || 0);

    if (target <= 0) {
        return null;
    }

    return Math.round(
        response / target * 100
    );
}

/* =========================================================
   Persistence
   =========================================================
   localStorage は一切使用しない。
   ========================================================= */

async function loadServerState() {
    try {
        const response =
            await fetch(
                '?mock_api=load',
                {
                    method:'GET',
                    cache:'no-store',
                    credentials:'same-origin'
                }
            );

        if (!response.ok) {
            return false;
        }

        const loaded =
            await response.json();

        if (!loaded || typeof loaded !== 'object') {
            return false;
        }

        state = mergeState(loaded);

        serverPersistenceAvailable = true;

        return true;

    } catch (error) {
        /*
         * sandbox / セッションCookie制限等でも
         * ここではエラーを画面へ投げない。
         */
        serverPersistenceAvailable = false;

        return false;
    }
}

function mergeState(loaded) {
    const merged =
        clone(defaultData);

    Object.assign(
        merged,
        loaded
    );

    if (!Array.isArray(merged.surveys)) {
        merged.surveys =
            clone(defaultData.surveys);
    }

    if (!Array.isArray(merged.customers)) {
        merged.customers =
            clone(defaultData.customers);
    }

    if (!Array.isArray(merged.sendResults)) {
        merged.sendResults = [];
    }

    if (!merged.settings) {
        merged.settings =
            clone(defaultData.settings);
    }

    if (!merged.settings.kintone) {
        merged.settings.kintone =
            clone(defaultData.settings.kintone);
    }

    if (!merged.settings.smtp) {
        merged.settings.smtp =
            clone(defaultData.settings.smtp);
    }

    if (typeof merged.listFilter === 'undefined') {
        merged.listFilter = 'all';
    }

    if (typeof merged.listSearch === 'undefined') {
        merged.listSearch = '';
    }

    return merged;
}

function saveState() {
    /*
     * 非同期保存。
     * 保存失敗しても画面操作を止めない。
     */
    try {
        fetch(
            '?mock_api=save',
            {
                method:'POST',
                headers:{
                    'Content-Type':'application/json'
                },
                body:JSON.stringify(state),
                credentials:'same-origin',
                cache:'no-store'
            }
        )
        .then(response => {
            if (response.ok) {
                serverPersistenceAvailable = true;
            }
        })
        .catch(() => {
            serverPersistenceAvailable = false;
        });

    } catch (error) {
        serverPersistenceAvailable = false;
    }
}

async function resetMock() {

    showConfirm(
        'モックデータを初期状態へ戻す',

        `
            <p>
                モックデータを初期状態へ戻します。
            </p>

            <p class="text-danger">
                編集したアンケート、送付先、
                回答データ等も初期状態に戻ります。
            </p>
        `,

        '初期状態へ戻す',

        async function() {

            state = clone(defaultData);
            sendDraft = null;
            editingQuestionId = null;

            try {
                await fetch(
                    '?mock_api=reset',
                    {
                        method:'POST',
                        credentials:'same-origin',
                        cache:'no-store'
                    }
                );
            } catch (error) {
                /* 操作は継続 */
            }

            closeModal();

            navigate('home');

            toast(
                'モックデータを初期状態へ戻しました。'
            );
        },

        'danger'
    );
}

/* =========================================================
   Navigation
   ========================================================= */

const pageTitles = {
    home:'ホーム',
    surveys:'アンケート一覧',
    editor:'アンケート編集',
    preview:'公開前確認',
    responses:'回答状況',
    'response-detail':'回答内容',
    send:'アンケート送付',
    'send-confirm':'送付確認',
    'send-result':'送付結果',
    settings:'各種設定',
    answer:'回答者向けアンケート',
    'answer-confirm':'回答確認',
    'answer-complete':'回答完了'
};

function navigate(page) {

    state.currentPage = page;

    saveState();

    const title =
        pageTitles[page] ||
        'アンケート管理';

    const topbarTitle =
        document.getElementById('topbarTitle');

    if (topbarTitle) {
        topbarTitle.textContent = title;
    }

    document
        .querySelectorAll('.nav button[data-page]')
        .forEach(button => {
            button.classList.toggle(
                'active',
                button.dataset.page === page
            );
        });

    renderPage();

    window.scrollTo({
        top:0,
        behavior:'smooth'
    });
}

function renderPage() {

    syncSurveyStatuses();

    const root =
        document.getElementById('appContent');

    if (!root) {
        return;
    }

    switch (state.currentPage) {

        case 'home':
            root.innerHTML = renderHome();
            break;

        case 'surveys':
            root.innerHTML = renderSurveyList();
            break;

        case 'editor':
            root.innerHTML = renderEditor();
            break;

        case 'preview':
            root.innerHTML = renderPreview();
            break;

        case 'responses':
            root.innerHTML = renderResponses();
            break;

        case 'response-detail':
            root.innerHTML = renderResponseDetail();
            break;

        case 'send':
            root.innerHTML = renderSend();
            break;

        case 'send-confirm':
            root.innerHTML = renderSendConfirm();
            break;

        case 'send-result':
            root.innerHTML = renderSendResult();
            break;

        case 'settings':
            root.innerHTML = renderSettings();
            break;

        case 'answer':
            root.innerHTML = renderAnswer();
            break;

        case 'answer-confirm':
            root.innerHTML = renderAnswerConfirm();
            break;

        case 'answer-complete':
            root.innerHTML = renderAnswerComplete();
            break;

        default:
            state.currentPage = 'home';
            root.innerHTML = renderHome();
            break;
    }
}

/* =========================================================
   Status
   ========================================================= */

function syncSurveyStatuses() {

    const now = new Date();

    let changed = false;

    state.surveys.forEach(survey => {

        if (
            survey.status === 'wait' &&
            survey.startAt
        ) {
            const start =
                new Date(survey.startAt);

            if (now >= start) {
                survey.status = 'active';
                survey.updatedAt = nowString();
                changed = true;
            }
        }

        if (
            (
                survey.status === 'wait' ||
                survey.status === 'active'
            ) &&
            survey.endAt
        ) {
            const end =
                new Date(survey.endAt);

            if (now >= end) {
                survey.status = 'ended';
                survey.updatedAt = nowString();
                changed = true;
            }
        }
    });

    if (changed) {
        saveState();
    }
}

/* =========================================================
   Home
   ========================================================= */

function statCard(label,number,status) {
    return `
        <div
            class="stat-card"
            onclick="filterStatus('${escapeHtml(status)}')"
        >
            <div class="stat-label">
                ${escapeHtml(label)}
            </div>

            <div class="stat-number">
                ${number}
            </div>
        </div>
    `;
}

function filterStatus(status) {
    state.listFilter = status;
    state.listSearch = '';
    navigate('surveys');
}

function renderHome() {

    const sorted =
        [...state.surveys].sort(
            (a,b) =>
                String(b.updatedAt || '')
                .localeCompare(
                    String(a.updatedAt || '')
                )
        );

    const recent =
        sorted.slice(0,5);

    const active =
        state.surveys.filter(
            s =>
                s.status === 'wait' ||
                s.status === 'active'
        );

    const responseCheck =
        state.surveys.filter(
            s =>
                s.status === 'active' ||
                s.status === 'ended'
        );

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">
                    ホーム
                </h1>

                <p class="page-description">
                    アンケートの運営状況と
                    次に行う操作を確認できます。
                </p>
            </div>

            <div class="actions">
                <button
                    class="btn btn-primary"
                    onclick="newSurvey()"
                >
                    ＋ 新しいアンケートを作成する
                </button>
            </div>
        </div>

        <div class="stat-grid">

            ${statCard(
                '作成中',
                countByStatus('draft'),
                'draft'
            )}

            ${statCard(
                '回答開始待ち',
                countByStatus('wait'),
                'wait'
            )}

            ${statCard(
                '回答受付中',
                countByStatus('active'),
                'active'
            )}

            ${statCard(
                '回答受付終了',
                countByStatus('ended'),
                'ended'
            )}

            ${statCard(
                '保管',
                countByStatus('archived'),
                'archived'
            )}

        </div>

        <div class="dashboard-grid">

            <div class="card">

                <div class="card-head">
                    <h2 class="card-title">
                        最近更新したアンケート
                    </h2>

                    <button
                        class="btn btn-sm"
                        onclick="navigate('surveys')"
                    >
                        一覧を見る
                    </button>
                </div>

                <div class="card-body">

                    ${
                        recent.length
                        ?
                        `
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>アンケート名</th>
                                        <th>状態</th>
                                        <th>更新日</th>
                                        <th>回答数</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    ${recent.map(
                                        survey => `
                                        <tr>
                                            <td>
                                                <strong>
                                                    ${escapeHtml(survey.name)}
                                                </strong>
                                            </td>

                                            <td>
                                                ${statusBadge(survey.status)}
                                            </td>

                                            <td>
                                                ${escapeHtml(
                                                    formatDate(
                                                        survey.updatedAt
                                                    )
                                                )}
                                            </td>

                                            <td>
                                                ${Number(
                                                    survey.responseCount || 0
                                                )}件
                                            </td>

                                            <td class="actions-cell">
                                                <button
                                                    class="btn btn-sm"
                                                    onclick="openSurvey(${survey.id})"
                                                >
                                                    開く
                                                </button>
                                            </td>
                                        </tr>
                                        `
                                    ).join('')}
                                </tbody>
                            </table>
                        </div>
                        `
                        :
                        '<div class="muted">アンケートはありません。</div>'
                    }

                </div>

            </div>

            <div class="card">

                <div class="card-head">
                    <h2 class="card-title">
                        現在運営中のアンケート
                    </h2>
                </div>

                <div class="card-body">

                    ${
                        active.length
                        ?
                        active.map(
                            survey => `
                                <div
                                    style="
                                        padding:10px 0;
                                        border-bottom:1px solid var(--gray-200);
                                    "
                                >
                                    <div>
                                        <strong>
                                            ${escapeHtml(survey.name)}
                                        </strong>
                                    </div>

                                    <div style="margin-top:5px">
                                        ${statusBadge(survey.status)}
                                    </div>

                                    <div
                                        class="small muted"
                                        style="margin-top:5px"
                                    >
                                        ${escapeHtml(
                                            formatDate(survey.startAt)
                                        )}
                                        ～ 
                                        ${escapeHtml(
                                            formatDate(survey.endAt)
                                        )}
                                    </div>
                                </div>
                            `
                        ).join('')
                        :
                        '<div class="muted">現在運営中のアンケートはありません。</div>'
                    }

                </div>

            </div>

            <div class="card">

                <div class="card-head">
                    <h2 class="card-title">
                        回答状況を確認できるアンケート
                    </h2>
                </div>

                <div class="card-body">

                    ${
                        responseCheck.length
                        ?
                        responseCheck.map(
                            survey => {
                                const rate =
                                    responseRate(survey);

                                return `
                                    <div
                                        style="
                                            padding:10px 0;
                                            border-bottom:1px solid var(--gray-200);
                                        "
                                    >
                                        <strong>
                                            ${escapeHtml(survey.name)}
                                        </strong>

                                        <div style="margin-top:5px">
                                            ${statusBadge(survey.status)}
                                        </div>

                                        <div
                                            class="small"
                                            style="margin-top:5px"
                                        >
                                            回答数：
                                            ${Number(
                                                survey.responseCount || 0
                                            )}件

                                            ${
                                                rate !== null
                                                ?
                                                ` / ${Number(
                                                    survey.sentCount || 0
                                                )}件
                                                （回答率 ${rate}%）`
                                                :
                                                ''
                                            }
                                        </div>

                                        <div style="margin-top:7px">
                                            <button
                                                class="btn btn-sm"
                                                onclick="openResponses(${survey.id})"
                                            >
                                                回答状況を見る
                                            </button>
                                        </div>
                                    </div>
                                `;
                            }
                        ).join('')
                        :
                        '<div class="muted">対象アンケートはありません。</div>'
                    }

                </div>

            </div>

        </div>
    `;
}

/* =========================================================
   Survey List
   ========================================================= */

function renderSurveyList() {

    const filter =
        state.listFilter || 'all';

    const keyword =
        String(state.listSearch || '')
        .trim()
        .toLowerCase();

    let surveys =
        [...state.surveys];

    if (filter !== 'all') {
        surveys =
            surveys.filter(
                survey =>
                    survey.status === filter
            );
    }

    if (keyword) {
        surveys =
            surveys.filter(
                survey =>
                    String(survey.name || '')
                    .toLowerCase()
                    .includes(keyword)
            );
    }

    surveys.sort(
        (a,b) =>
            String(b.updatedAt || '')
            .localeCompare(
                String(a.updatedAt || '')
            )
    );

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    アンケート一覧
                </h1>

                <p class="page-description">
                    一覧から任意のアンケートを選択して、
                    内容確認・編集・回答状況確認・送付を行えます。
                </p>
            </div>

            <div class="actions">
                <button
                    class="btn btn-primary"
                    onclick="newSurvey()"
                >
                    ＋ 新規作成
                </button>
            </div>

        </div>

        <div class="card">

            <div class="card-body">

                <div class="search-panel">

                    <div>
                        <label class="form-label">
                            アンケート名
                        </label>

                        <input
                            type="text"
                            id="surveySearch"
                            value="${escapeHtml(state.listSearch)}"
                            placeholder="アンケート名で検索"
                            onkeydown="
                                if(event.key === 'Enter'){
                                    applySurveySearch();
                                }
                            "
                        >
                    </div>

                    <div>
                        <label class="form-label">
                            状態
                        </label>

                        <select
                            id="surveyFilter"
                            onchange="applySurveyFilter()"
                        >
                            <option
                                value="all"
                                ${filter === 'all' ? 'selected' : ''}
                            >
                                すべて
                            </option>

                            <option
                                value="draft"
                                ${filter === 'draft' ? 'selected' : ''}
                            >
                                作成中
                            </option>

                            <option
                                value="wait"
                                ${filter === 'wait' ? 'selected' : ''}
                            >
                                回答開始待ち
                            </option>

                            <option
                                value="active"
                                ${filter === 'active' ? 'selected' : ''}
                            >
                                回答受付中
                            </option>

                            <option
                                value="ended"
                                ${filter === 'ended' ? 'selected' : ''}
                            >
                                回答受付終了
                            </option>

                            <option
                                value="archived"
                                ${filter === 'archived' ? 'selected' : ''}
                            >
                                保管
                            </option>
                        </select>
                    </div>

                    <div class="actions">

                        <button
                            class="btn btn-primary"
                            onclick="applySurveySearch()"
                        >
                            検索
                        </button>

                        <button
                            class="btn"
                            onclick="clearSurveySearch()"
                        >
                            クリア
                        </button>

                    </div>

                </div>

            </div>

        </div>

        <div class="card">

            <div class="card-head">

                <div>
                    <strong>
                        ${
                            filter === 'all'
                            ? 'すべてのアンケート'
                            : escapeHtml(statusLabel(filter))
                        }
                    </strong>

                    <span class="small muted">
                        （${surveys.length}件）
                    </span>
                </div>

            </div>

            <div class="card-body">

                ${
                    surveys.length
                    ?
                    `
                    <div class="table-wrap">

                        <table>

                            <thead>
                                <tr>
                                    <th>アンケート名</th>
                                    <th>状態</th>
                                    <th>作成日</th>
                                    <th>更新日</th>
                                    <th>回答受付期間</th>
                                    <th>回答状況</th>
                                    <th>操作</th>
                                </tr>
                            </thead>

                            <tbody>

                                ${surveys.map(
                                    survey => renderSurveyRow(survey)
                                ).join('')}

                            </tbody>

                        </table>

                    </div>
                    `
                    :
                    `
                    <div class="muted">
                        条件に一致するアンケートがありません。
                    </div>
                    `
                }

            </div>

        </div>
    `;
}

function renderSurveyRow(survey) {

    const rate =
        responseRate(survey);

    return `
        <tr>

            <td>
                <strong>
                    ${escapeHtml(
                        survey.name || '名称未設定'
                    )}
                </strong>
            </td>

            <td>
                ${statusBadge(survey.status)}
            </td>

            <td>
                ${escapeHtml(
                    formatDate(survey.createdAt)
                )}
            </td>

            <td>
                ${escapeHtml(
                    formatDate(survey.updatedAt)
                )}
            </td>

            <td>
                ${
                    survey.startAt || survey.endAt
                    ?
                    `
                        ${escapeHtml(
                            formatDate(survey.startAt)
                        )}
                        ～
                        ${escapeHtml(
                            formatDate(survey.endAt)
                        )}
                    `
                    :
                    '指定なし'
                }
            </td>

            <td>
                ${Number(
                    survey.responseCount || 0
                )}件

                ${
                    rate !== null
                    ?
                    `
                        <div class="small muted">
                            ${rate}%
                        </div>
                    `
                    :
                    ''
                }
            </td>

            <td class="actions-cell">

                <button
                    class="btn btn-sm"
                    onclick="openSurvey(${survey.id})"
                >
                    内容を見る
                </button>

                ${
                    canEdit(survey)
                    ?
                    `
                    <button
                        class="btn btn-sm btn-primary"
                        onclick="editSurvey(${survey.id})"
                    >
                        編集する
                    </button>
                    `
                    :
                    ''
                }

                ${
                    survey.status === 'draft'
                    ?
                    `
                    <button
                        class="btn btn-sm btn-success"
                        onclick="openPreview(${survey.id})"
                    >
                        公開前確認
                    </button>
                    `
                    :
                    ''
                }

                ${
                    survey.status === 'wait' ||
                    survey.status === 'active'
                    ?
                    `
                    <button
                        class="btn btn-sm btn-info"
                        onclick="openSend(${survey.id})"
                    >
                        送付
                    </button>
                    `
                    :
                    ''
                }

                ${
                    survey.status === 'wait' ||
                    survey.status === 'active' ||
                    survey.status === 'ended'
                    ?
                    `
                    <button
                        class="btn btn-sm"
                        onclick="openResponses(${survey.id})"
                    >
                        回答状況
                    </button>

                    <button
                        class="btn btn-sm"
                        onclick="openResponseDetail(${survey.id})"
                    >
                        回答内容
                    </button>
                    `
                    :
                    ''
                }

                ${
                    survey.status === 'active'
                    ?
                    `
                    <button
                        class="btn btn-sm btn-warning"
                        onclick="finishSurvey(${survey.id})"
                    >
                        受付終了
                    </button>
                    `
                    :
                    ''
                }

                ${
                    survey.status === 'ended'
                    ?
                    `
                    <button
                        class="btn btn-sm"
                        onclick="archiveSurvey(${survey.id})"
                    >
                        保管
                    </button>
                    `
                    :
                    ''
                }

                ${
                    survey.status === 'draft'
                    ?
                    `
                    <button
                        class="btn btn-sm btn-danger"
                        onclick="deleteSurvey(${survey.id})"
                    >
                        削除
                    </button>
                    `
                    :
                    ''
                }

            </td>

        </tr>
    `;
}

function applySurveySearch() {
    state.listSearch =
        document.getElementById('surveySearch')?.value || '';

    saveState();
    renderPage();
}

function applySurveyFilter() {
    state.listFilter =
        document.getElementById('surveyFilter')?.value || 'all';

    saveState();
    renderPage();
}

function clearSurveySearch() {
    state.listSearch = '';
    state.listFilter = 'all';

    saveState();
    renderPage();
}

/* =========================================================
   Survey Create / Edit
   ========================================================= */

function newSurvey() {

    const newId =
        Math.max(
            0,
            ...state.surveys.map(
                s => Number(s.id) || 0
            )
        ) + 1;

    const survey = {
        id:newId,
        name:'',
        description:'',
        guidance:'',
        completeMessage:'ご回答ありがとうございました。',
        status:'draft',
        createdAt:nowString().slice(0,10),
        updatedAt:nowString(),
        startAt:'',
        endAt:'',
        numberMode:'global',
        sentCount:0,
        responseCount:0,
        selectedCustomerIds:[],
        lastSentAt:'',
        groups:[
            {
                id:uid('g'),
                name:'基本情報'
            }
        ],
        questions:[],
        answers:[]
    };

    state.surveys.unshift(survey);

    state.currentSurveyId = newId;

    saveState();

    navigate('editor');
}

function editSurvey(id) {
    setCurrentSurvey(id);
    navigate('editor');
}

function openSurvey(id) {
    setCurrentSurvey(id);
    navigate('editor');
}

/* =========================================================
   Editor
   ========================================================= */

function renderEditor() {

    const survey =
        currentSurvey();

    if (!survey) {
        return `
            <div class="error-box">
                アンケートが見つかりません。
            </div>
        `;
    }

    const editable =
        canEdit(survey);

    const structuralLocked =
        !canStructuralEdit(survey);

    return `
        <div class="page-head">

            <div>

                <h1 class="page-title">
                    ${
                        survey.status === 'draft'
                        ? 'アンケート編集'
                        : 'アンケート内容'
                    }
                </h1>

                <p class="page-description">
                    ${statusBadge(survey.status)}

                    <span style="margin-left:8px">
                        ${escapeHtml(
                            survey.name || '名称未設定'
                        )}
                    </span>
                </p>

            </div>

            <div class="actions">

                <button
                    class="btn"
                    onclick="navigate('surveys')"
                >
                    アンケート一覧へ戻る
                </button>

                ${
                    editable
                    ?
                    `
                    <button
                        class="btn"
                        onclick="saveSurvey()"
                    >
                        保存する
                    </button>
                    `
                    :
                    ''
                }

                ${
                    survey.status === 'draft'
                    ?
                    `
                    <button
                        class="btn btn-primary"
                        onclick="openPreview(${survey.id})"
                    >
                        公開前確認
                    </button>
                    `
                    :
                    ''
                }

                ${
                    survey.status === 'wait' ||
                    survey.status === 'active'
                    ?
                    `
                    <button
                        class="btn btn-info"
                        onclick="openSend(${survey.id})"
                    >
                        アンケートを送付
                    </button>
                    `
                    :
                    ''
                }

            </div>

        </div>

        ${
            structuralLocked
            ?
            `
            <div class="info-box">
                公開後のアンケートは、
                既存回答との対応関係を維持するため、
                質問・選択肢・グループ・分岐などの構造を変更できません。
                この画面は参照用です。
            </div>
            `
            :
            ''
        }

        <div class="editor-layout">

            <div class="card editor-sidebar">

                <div class="card-head">
                    <h2 class="card-title">
                        編集メニュー
                    </h2>
                </div>

                <div class="card-body">

                    <button
                        class="editor-nav-item active"
                        onclick="
                            document
                                .getElementById('basic')
                                .scrollIntoView({
                                    behavior:'smooth'
                                })
                        "
                    >
                        基本情報
                    </button>

                    <button
                        class="editor-nav-item"
                        onclick="
                            document
                                .getElementById('questions')
                                .scrollIntoView({
                                    behavior:'smooth'
                                })
                        "
                    >
                        質問・グループ
                    </button>

                    <button
                        class="editor-nav-item"
                        onclick="
                            document
                                .getElementById('branch')
                                .scrollIntoView({
                                    behavior:'smooth'
                                })
                        "
                    >
                        分岐設定
                    </button>

                    <button
                        class="editor-nav-item"
                        onclick="openPreview(${survey.id})"
                    >
                        内容をプレビュー
                    </button>

                </div>

            </div>

            <div>

                <div
                    class="card"
                    id="basic"
                >

                    <div class="card-head">
                        <h2 class="card-title">
                            基本情報
                        </h2>
                    </div>

                    <div class="card-body">

                        <div id="editorErrors"></div>

                        <div class="form-grid">

                            <div class="form-group full">

                                <label class="form-label">
                                    アンケート名
                                    <span class="required">*</span>
                                </label>

                                <input
                                    type="text"
                                    id="surveyName"
                                    value="${escapeHtml(survey.name)}"
                                    ${editable ? '' : 'disabled'}
                                >

                            </div>

                            <div class="form-group full">

                                <label class="form-label">
                                    説明文
                                </label>

                                <textarea
                                    id="surveyDescription"
                                    ${editable ? '' : 'disabled'}
                                >${escapeHtml(
                                    survey.description
                                )}</textarea>

                            </div>

                            <div class="form-group">

                                <label class="form-label">
                                    回答受付開始日時
                                </label>

                                <input
                                    type="datetime-local"
                                    id="surveyStartAt"
                                    value="${escapeHtml(survey.startAt)}"
                                    ${editable ? '' : 'disabled'}
                                >

                            </div>

                            <div class="form-group">

                                <label class="form-label">
                                    回答受付終了日時
                                </label>

                                <input
                                    type="datetime-local"
                                    id="surveyEndAt"
                                    value="${escapeHtml(survey.endAt)}"
                                    ${editable ? '' : 'disabled'}
                                >

                            </div>

                            <div class="form-group full">

                                <label class="form-label">
                                    回答者への案内文
                                </label>

                                <textarea
                                    id="surveyGuidance"
                                    ${editable ? '' : 'disabled'}
                                >${escapeHtml(
                                    survey.guidance
                                )}</textarea>

                            </div>

                            <div class="form-group full">

                                <label class="form-label">
                                    回答完了時のメッセージ
                                </label>

                                <textarea
                                    id="surveyCompleteMessage"
                                    ${editable ? '' : 'disabled'}
                                >${escapeHtml(
                                    survey.completeMessage
                                )}</textarea>

                            </div>

                            <div class="form-group">

                                <label class="form-label">
                                    質問番号方式
                                </label>

                                <select
                                    id="numberMode"
                                    ${structuralLocked ? 'disabled' : ''}
                                >
                                    <option
                                        value="global"
                                        ${
                                            survey.numberMode === 'global'
                                            ? 'selected'
                                            : ''
                                        }
                                    >
                                        アンケート全体で連番
                                    </option>

                                    <option
                                        value="group"
                                        ${
                                            survey.numberMode === 'group'
                                            ? 'selected'
                                            : ''
                                        }
                                    >
                                        グループごとに連番
                                    </option>
                                </select>

                            </div>

                        </div>

                    </div>

                </div>

                <div
                    class="card"
                    id="questions"
                >

                    <div class="card-head">

                        <div>
                            <h2 class="card-title">
                                質問・グループ
                            </h2>

                            <div class="small muted">
                                グループ内の質問をドラッグして並べ替えできます。
                            </div>
                        </div>

                    </div>

                    <div class="card-body">

                        <div class="group-list">

                            ${survey.groups.map(
                                group =>
                                    renderGroup(
                                        survey,
                                        group,
                                        structuralLocked
                                    )
                            ).join('')}

                        </div>

                        ${
                            !structuralLocked
                            ?
                            `
                            <div class="group-list-footer">

                                <button
                                    class="btn btn-primary"
                                    onclick="addGroup()"
                                >
                                    ＋ グループを追加
                                </button>

                            </div>
                            `
                            :
                            ''
                        }

                    </div>

                </div>

                <div
                    class="card"
                    id="branch"
                >

                    <div class="card-head">
                        <h2 class="card-title">
                            分岐設定
                        </h2>
                    </div>

                    <div class="card-body">
                        ${renderBranchSettings(
                            survey,
                            structuralLocked
                        )}
                    </div>

                </div>

            </div>

        </div>
    `;
}

/* =========================================================
   Group
   ========================================================= */

function renderGroup(survey,group,locked) {

    const questions =
        survey.questions.filter(
            q => q.groupId === group.id
        );

    return `
        <div
            class="group-box"
            data-group-id="${escapeHtml(group.id)}"
            ondragover="groupDragOver(event)"
            ondragleave="groupDragLeave(event)"
            ondrop="dropQuestionToGroup(event,'${escapeHtml(group.id)}')"
        >

            <div class="group-head">

                <div style="flex:1">

                    ${
                        locked
                        ?
                        `
                        <div class="group-name">
                            ${escapeHtml(group.name)}
                        </div>
                        `
                        :
                        `
                        <input
                            type="text"
                            value="${escapeHtml(group.name)}"
                            onchange="
                                renameGroup(
                                    '${escapeHtml(group.id)}',
                                    this.value
                                )
                            "
                        >
                        `
                    }

                </div>

                ${
                    !locked
                    ?
                    `
                    <div class="actions">

                        <button
                            class="btn btn-sm btn-danger"
                            onclick="
                                deleteGroup(
                                    '${escapeHtml(group.id)}'
                                )
                            "
                        >
                            グループ削除
                        </button>

                    </div>
                    `
                    :
                    ''
                }

            </div>

            <div class="group-body">

                <div class="question-list">

                    ${
                        questions.length
                        ?
                        questions.map(
                            q =>
                                renderQuestion(
                                    survey,
                                    q,
                                    locked
                                )
                        ).join('')
                        :
                        `
                        <div class="muted small">
                            このグループには質問がありません。
                        </div>
                        `
                    }

                </div>

                ${
                    !locked
                    ?
                    `
                    <div class="question-add-footer">

                        <button
                            class="btn btn-sm btn-primary"
                            onclick="
                                addQuestion(
                                    '${escapeHtml(group.id)}'
                                )
                            "
                        >
                            ＋ 質問を追加
                        </button>

                    </div>
                    `
                    :
                    ''
                }

            </div>

        </div>
    `;
}

/* =========================================================
   Question
   ========================================================= */

function renderQuestion(survey,q,locked) {

    normalizeQuestion(q);

    const number =
        questionNumber(survey,q);

    return `
        <div
            class="question-card"
            draggable="${locked ? 'false' : 'true'}"
            data-question-id="${escapeHtml(q.id)}"
            ondragstart="
                questionDragStart(
                    event,
                    '${escapeHtml(q.id)}'
                )
            "
            ondragend="questionDragEnd(event)"
            ondragover="
                questionDragOver(
                    event
                )
            "
            ondragleave="
                questionDragLeave(
                    event
                )
            "
            ondrop="
                dropQuestionBefore(
                    event,
                    '${escapeHtml(q.id)}'
                )
            "
        >

            <div class="question-head">

                <div class="question-main">

                    ${
                        locked
                        ?
                        ''
                        :
                        `
                        <div class="drag-handle">
                            ⋮⋮
                        </div>
                        `
                    }

                    <div class="question-title">

                        <div>
                            <span class="question-number">
                                質問${escapeHtml(number)}
                            </span>

                            <span style="margin-left:7px">
                                ${escapeHtml(
                                    q.text || '未入力の質問'
                                )}
                            </span>
                        </div>

                        <div
                            class="small muted"
                            style="margin-top:5px"
                        >
                            ${escapeHtml(
                                questionTypeLabel(q.type)
                            )}

                            ／

                            ${
                                q.required
                                ? '必須'
                                : '任意'
                            }
                        </div>

                    </div>

                </div>

                <div class="question-actions">

                    <button
                        class="btn btn-sm"
                        onclick="
                            openQuestionEditor(
                                '${escapeHtml(q.id)}'
                            )
                        "
                    >
                        ${locked ? '内容を見る' : '編集'}
                    </button>

                    ${
                        !locked
                        ?
                        `
                        <button
                            class="btn btn-sm btn-danger"
                            onclick="
                                deleteQuestion(
                                    '${escapeHtml(q.id)}'
                                )
                            "
                        >
                            削除
                        </button>
                        `
                        :
                        ''
                    }

                </div>

            </div>

            ${
                q.help
                ?
                `
                <div
                    class="small muted"
                    style="margin-top:8px"
                >
                    ${escapeHtml(q.help)}
                </div>
                `
                :
                ''
            }

            ${
                q.choices.length
                ?
                `
                <div class="choice-list">

                    ${q.choices.map(
                        c => `
                            <div class="small">
                                ・${escapeHtml(c.text)}
                            </div>
                        `
                    ).join('')}

                </div>
                `
                :
                ''
            }

            ${
                q.type === 'single' &&
                q.choices.length
                ?
                renderQuestionBranchSummary(
                    survey,
                    q
                )
                :
                ''
            }

        </div>
    `;
}

/* =========================================================
   Add Question
   ========================================================= */

function addQuestion(groupId) {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    if (!canStructuralEdit(survey)) {
        toast(
            '公開後は質問構造を変更できません。'
        );
        return;
    }

    if (!survey.groups.length) {

        survey.groups.push({
            id:uid('g'),
            name:'基本情報'
        });

        groupId =
            survey.groups[0].id;
    }

    if (!groupId) {
        groupId =
            survey.groups[0].id;
    }

    const q = {
        id:uid('q'),
        groupId:groupId,
        text:'',
        type:'text',
        required:false,
        help:'',
        choices:[],
        branches:{}
    };

    survey.questions.push(q);

    survey.updatedAt =
        nowString();

    saveState();
    renderPage();

    openQuestionEditor(q.id);
}

/* =========================================================
   Question Editor
   ========================================================= */

function openQuestionEditor(questionId) {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    const q =
        survey.questions.find(
            x => x.id === questionId
        );

    if (!q) {
        return;
    }

    normalizeQuestion(q);

    editingQuestionId =
        questionId;

    const locked =
        !canStructuralEdit(survey);

    showModal(
        locked
        ? '質問内容'
        : '質問を編集する',

        `
        <div class="form-group">

            <label class="form-label">
                質問文
            </label>

            <textarea
                id="editQText"
                ${locked ? 'disabled' : ''}
            >${escapeHtml(q.text)}</textarea>

        </div>

        <div class="form-group">

            <label class="form-label">
                回答形式
            </label>

            <select
                id="editQType"
                onchange="renderQuestionChoiceEditor()"
                ${locked ? 'disabled' : ''}
            >

                <option
                    value="text"
                    ${
                        q.type === 'text'
                        ? 'selected'
                        : ''
                    }
                >
                    文章を入力する質問
                </option>

                <option
                    value="single"
                    ${
                        q.type === 'single'
                        ? 'selected'
                        : ''
                    }
                >
                    1つだけ選ぶ質問
                </option>

                <option
                    value="multiple"
                    ${
                        q.type === 'multiple'
                        ? 'selected'
                        : ''
                    }
                >
                    複数選ぶ質問
                </option>

                <option
                    value="rating"
                    ${
                        q.type === 'rating'
                        ? 'selected'
                        : ''
                    }
                >
                    段階的に評価する質問
                </option>

            </select>

        </div>

        <div class="form-group">

            <label>
                <input
                    type="checkbox"
                    id="editQRequired"
                    ${
                        q.required
                        ? 'checked'
                        : ''
                    }
                    ${locked ? 'disabled' : ''}
                >

                必須回答
            </label>

        </div>

        <div class="form-group">

            <label class="form-label">
                補足説明
            </label>

            <textarea
                id="editQHelp"
                ${locked ? 'disabled' : ''}
            >${escapeHtml(q.help)}</textarea>

        </div>

        <div id="questionChoiceEditor">
            ${renderQuestionChoiceEditorHtml(q)}
        </div>
        `,

        locked
        ?
        `
            <button
                class="btn"
                onclick="closeModal()"
            >
                閉じる
            </button>
        `
        :
        `
            <button
                class="btn"
                onclick="closeModal()"
            >
                キャンセル
            </button>

            <button
                class="btn btn-primary"
                onclick="
                    saveQuestionEdit(
                        '${escapeHtml(questionId)}'
                    )
                "
            >
                保存する
            </button>
        `
    );
}

function renderQuestionChoiceEditor() {

    const container =
        document.getElementById(
            'questionChoiceEditor'
        );

    if (!container) {
        return;
    }

    const survey =
        currentSurvey();

    const q =
        survey?.questions.find(
            x => x.id === editingQuestionId
        );

    if (!q) {
        return;
    }

    const type =
        document.getElementById(
            'editQType'
        )?.value || q.type;

    const temp =
        clone(q);

    temp.type = type;

    container.innerHTML =
        renderQuestionChoiceEditorHtml(temp);
}

function renderQuestionChoiceEditorHtml(q) {

    const type =
        document.getElementById('editQType')?.value ||
        q.type;

    if (
        type !== 'single' &&
        type !== 'multiple' &&
        type !== 'rating'
    ) {
        return `
            <div class="info-box">
                この質問形式では選択肢はありません。
            </div>
        `;
    }

    if (type === 'rating') {
        return `
            <div class="form-group">

                <label class="form-label">
                    評価段階
                </label>

                <div class="small muted">
                    1～5の5段階評価を使用します。
                </div>

            </div>
        `;
    }

    const choices =
        Array.isArray(q.choices) &&
        q.choices.length
        ?
        q.choices
        :
        [
            {
                id:uid('c'),
                text:''
            }
        ];

    return `
        <div class="form-group">

            <label class="form-label">
                選択肢
            </label>

            <div id="modalChoices">

                ${choices.map(
                    choice => `
                        <div
                            class="choice-row"
                            data-choice-id="${escapeHtml(
                                choice.id
                            )}"
                            style="margin-bottom:7px"
                        >

                            <input
                                type="text"
                                value="${escapeHtml(
                                    choice.text
                                )}"
                                placeholder="選択肢"
                            >

                            <button
                                type="button"
                                class="btn btn-sm btn-danger"
                                onclick="
                                    this.parentElement.remove()
                                "
                            >
                                削除
                            </button>

                        </div>
                    `
                ).join('')}

            </div>

            <button
                type="button"
                class="btn btn-sm"
                onclick="addModalChoice()"
            >
                ＋ 選択肢を追加
            </button>

        </div>
    `;
}

function addModalChoice() {

    const list =
        document.getElementById(
            'modalChoices'
        );

    if (!list) {
        return;
    }

    const id =
        uid('c');

    const row =
        document.createElement('div');

    row.className =
        'choice-row';

    row.style.marginBottom =
        '7px';

    row.dataset.choiceId =
        id;

    row.innerHTML = `
        <input
            type="text"
            placeholder="選択肢"
        >

        <button
            type="button"
            class="btn btn-sm btn-danger"
            onclick="
                this.parentElement.remove()
            "
        >
            削除
        </button>
    `;

    list.appendChild(row);
}

function saveQuestionEdit(questionId) {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    if (!canStructuralEdit(survey)) {
        toast(
            'このアンケートは質問構造を変更できません。'
        );
        closeModal();
        return;
    }

    const q =
        survey.questions.find(
            x => x.id === questionId
        );

    if (!q) {
        return;
    }

    const text =
        document.getElementById(
            'editQText'
        )?.value.trim() || '';

    const type =
        document.getElementById(
            'editQType'
        )?.value || 'text';

    const required =
        !!document.getElementById(
            'editQRequired'
        )?.checked;

    const help =
        document.getElementById(
            'editQHelp'
        )?.value || '';

    const errors = [];

    if (!text) {
        errors.push(
            '質問文を入力してください。'
        );
    }

    let choices = [];

    if (
        type === 'single' ||
        type === 'multiple'
    ) {

        document
            .querySelectorAll(
                '#modalChoices .choice-row'
            )
            .forEach(row => {

                const value =
                    row.querySelector(
                        'input'
                    )?.value.trim() || '';

                if (value) {
                    choices.push({
                        id:
                            row.dataset.choiceId ||
                            uid('c'),
                        text:value
                    });
                }
            });

        if (!choices.length) {
            errors.push(
                '選択式質問には1件以上の選択肢が必要です。'
            );
        }
    }

    if (type === 'rating') {
        choices =
            [1,2,3,4,5].map(
                n => ({
                    id:'r' + n,
                    text:String(n)
                })
            );
    }

    if (errors.length) {

        document.getElementById(
            'modalBody'
        ).insertAdjacentHTML(
            'afterbegin',

            `
            <div class="error-box">
                <ul>
                    ${errors.map(
                        error =>
                            `<li>${escapeHtml(error)}</li>`
                    ).join('')}
                </ul>
            </div>
            `
        );

        return;
    }

    q.text =
        text;

    q.type =
        type;

    q.required =
        required;

    q.help =
        help;

    q.choices =
        choices;

    if (type !== 'single') {
        q.branches = {};
    }

    survey.updatedAt =
        nowString();

    saveState();

    closeModal();

    renderPage();

    toast(
        '質問を保存しました。'
    );
}

/* =========================================================
   Delete Question
   ========================================================= */

function deleteQuestion(questionId) {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    if (!canStructuralEdit(survey)) {
        toast(
            '公開後は質問を削除できません。'
        );
        return;
    }

    const q =
        survey.questions.find(
            x => x.id === questionId
        );

    if (!q) {
        return;
    }

    showConfirm(
        '質問を削除する',

        `
            <p>
                「${escapeHtml(
                    q.text || '未入力の質問'
                )}」
                を削除します。
            </p>
        `,

        '質問を削除する',

        function() {

            survey.questions =
                survey.questions.filter(
                    x => x.id !== questionId
                );

            survey.questions.forEach(
                parent => {

                    Object.keys(
                        parent.branches || {}
                    ).forEach(
                        choiceId => {

                            const branch =
                                parent.branches[choiceId];

                            if (
                                branch.type === 'question' &&
                                branch.target === questionId
                            ) {
                                delete parent.branches[
                                    choiceId
                                ];
                            }
                        }
                    );
                }
            );

            survey.updatedAt =
                nowString();

            saveState();

            closeModal();
            renderPage();

            toast(
                '質問を削除しました。'
            );
        },

        'danger'
    );
}

/* =========================================================
   Group
   ========================================================= */

function addGroup() {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    if (!canStructuralEdit(survey)) {
        toast(
            '公開後はグループを変更できません。'
        );
        return;
    }

    survey.groups.push({
        id:uid('g'),
        name:'グループ' +
            (survey.groups.length + 1)
    });

    survey.updatedAt =
        nowString();

    saveState();

    renderPage();

    toast(
        'グループを追加しました。'
    );
}

function renameGroup(id,name) {

    const survey =
        currentSurvey();

    if (!survey ||
        !canStructuralEdit(survey)) {
        return;
    }

    const group =
        survey.groups.find(
            g => g.id === id
        );

    if (!group) {
        return;
    }

    const value =
        String(name || '').trim();

    if (!value) {
        toast(
            'グループ名を入力してください。'
        );

        renderPage();
        return;
    }

    group.name =
        value;

    survey.updatedAt =
        nowString();

    saveState();
}

function deleteGroup(id) {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    if (!canStructuralEdit(survey)) {
        toast(
            '公開後はグループを変更できません。'
        );
        return;
    }

    const group =
        survey.groups.find(
            g => g.id === id
        );

    if (!group) {
        return;
    }

    const questions =
        survey.questions.filter(
            q => q.groupId === id
        );

    if (!questions.length) {

        showConfirm(
            'グループを削除する',

            `
                <p>
                    「${escapeHtml(group.name)}」
                    を削除します。
                </p>
            `,

            'グループを削除する',

            function() {

                survey.groups =
                    survey.groups.filter(
                        g => g.id !== id
                    );

                survey.updatedAt =
                    nowString();

                saveState();

                closeModal();
                renderPage();

                toast(
                    'グループを削除しました。'
                );
            },

            'danger'
        );

        return;
    }

    showModal(
        'グループを削除する',

        `
            <p>
                「${escapeHtml(group.name)}」
                には
                ${questions.length}問
                の質問があります。
            </p>

            <div class="info-box">
                質問を失わないよう、
                削除方法を選択してください。
            </div>
        `,

        `
            <button
                class="btn"
                onclick="closeModal()"
            >
                キャンセル
            </button>

            <button
                class="btn btn-warning"
                onclick="
                    deleteGroupKeepQuestions(
                        '${escapeHtml(id)}'
                    )
                "
            >
                質問を未設定にする
            </button>

            <button
                class="btn btn-danger"
                onclick="
                    deleteGroupWithQuestions(
                        '${escapeHtml(id)}'
                    )
                "
            >
                質問とグループを削除
            </button>
        `
    );
}

function deleteGroupKeepQuestions(id) {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    survey.questions.forEach(
        q => {
            if (q.groupId === id) {
                q.groupId = null;
            }
        }
    );

    survey.groups =
        survey.groups.filter(
            g => g.id !== id
        );

    survey.updatedAt =
        nowString();

    saveState();

    closeModal();
    renderPage();

    toast(
        'グループを削除し、質問を未設定にしました。'
    );
}

function deleteGroupWithQuestions(id) {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    const questionIds =
        survey.questions
        .filter(
            q => q.groupId === id
        )
        .map(
            q => q.id
        );

    survey.questions =
        survey.questions.filter(
            q => q.groupId !== id
        );

    survey.questions.forEach(
        parent => {

            Object.keys(
                parent.branches || {}
            ).forEach(
                choiceId => {

                    const branch =
                        parent.branches[choiceId];

                    if (
                        branch.type === 'question' &&
                        questionIds.includes(
                            branch.target
                        )
                    ) {
                        delete parent.branches[
                            choiceId
                        ];
                    }
                }
            );
        }
    );

    survey.groups =
        survey.groups.filter(
            g => g.id !== id
        );

    survey.updatedAt =
        nowString();

    saveState();

    closeModal();
    renderPage();

    toast(
        'グループと質問を削除しました。'
    );
}

/* =========================================================
   Drag & Drop
   ========================================================= */

function questionDragStart(event,id) {

    draggingQuestionId =
        id;

    event.dataTransfer.effectAllowed =
        'move';

    event.dataTransfer.setData(
        'text/plain',
        id
    );

    event.currentTarget.classList.add(
        'dragging'
    );
}

function questionDragEnd(event) {

    event.currentTarget.classList.remove(
        'dragging'
    );

    document
        .querySelectorAll(
            '.drag-over'
        )
        .forEach(
            element =>
                element.classList.remove(
                    'drag-over'
                )
        );

    draggingQuestionId =
        null;
}

function questionDragOver(event) {

    event.preventDefault();

    if (draggingQuestionId) {
        event.currentTarget.classList.add(
            'drag-over'
        );
    }
}

function questionDragLeave(event) {
    event.currentTarget.classList.remove(
        'drag-over'
    );
}

function groupDragOver(event) {

    event.preventDefault();

    event.currentTarget.classList.add(
        'drag-over'
    );
}

function groupDragLeave(event) {

    event.currentTarget.classList.remove(
        'drag-over'
    );
}

function dropQuestionBefore(
    event,
    targetQuestionId
) {

    event.preventDefault();
    event.stopPropagation();

    const survey =
        currentSurvey();

    if (!survey ||
        !canStructuralEdit(survey)) {
        return;
    }

    const sourceId =
        draggingQuestionId ||
        event.dataTransfer.getData(
            'text/plain'
        );

    if (!sourceId ||
        sourceId === targetQuestionId) {
        return;
    }

    const sourceIndex =
        survey.questions.findIndex(
            q => q.id === sourceId
        );

    if (sourceIndex < 0) {
        return;
    }

    const source =
        survey.questions.splice(
            sourceIndex,
            1
        )[0];

    let targetIndex =
        survey.questions.findIndex(
            q => q.id === targetQuestionId
        );

    if (targetIndex < 0) {
        targetIndex =
            survey.questions.length;
    }

    survey.questions.splice(
        targetIndex,
        0,
        source
    );

    survey.updatedAt =
        nowString();

    draggingQuestionId =
        null;

    saveState();
    renderPage();
}

function dropQuestionToGroup(
    event,
    groupId
) {

    event.preventDefault();

    const survey =
        currentSurvey();

    if (!survey ||
        !canStructuralEdit(survey)) {
        return;
    }

    const sourceId =
        draggingQuestionId ||
        event.dataTransfer.getData(
            'text/plain'
        );

    const q =
        survey.questions.find(
            x => x.id === sourceId
        );

    if (!q) {
        return;
    }

    q.groupId =
        groupId;

    survey.updatedAt =
        nowString();

    draggingQuestionId =
        null;

    saveState();
    renderPage();
}

/* =========================================================
   Branch
   ========================================================= */

function renderQuestionBranchSummary(
    survey,
    q
) {

    return `
        <div class="branch-box">

            <div class="small">
                <strong>分岐</strong>
            </div>

            ${q.choices.map(
                choice => {

                    const branch =
                        q.branches?.[choice.id] ||
                        {
                            type:'next'
                        };

                    return `
                        <div
                            style="
                                margin-top:7px;
                                padding:8px;
                                background:#fff;
                                border-radius:6px;
                            "
                        >
                            <strong>
                                ${escapeHtml(choice.text)}
                            </strong>

                            <span class="muted">
                                →
                                ${escapeHtml(
                                    branchTargetLabel(
                                        survey,
                                        branch
                                    )
                                )}
                            </span>
                        </div>
                    `;
                }
            ).join('')}

        </div>
    `;
}

function branchTargetLabel(
    survey,
    branch
) {

    if (!branch) {
        return '次の質問';
    }

    switch (branch.type) {

        case 'next':
            return '次の質問';

        case 'end':
            return 'アンケート終了';

        case 'question': {

            const q =
                survey.questions.find(
                    x =>
                        x.id === branch.target
                );

            return q
                ? `質問${questionNumber(survey,q)}：${q.text}`
                : '存在しない質問';
        }

        case 'group': {

            const g =
                survey.groups.find(
                    x =>
                        x.id === branch.target
                );

            return g
                ? `グループ：${g.name}`
                : '存在しないグループ';
        }

        default:
            return '次の質問';
    }
}

function renderBranchSettings(
    survey,
    locked
) {

    const singles =
        survey.questions.filter(
            q => q.type === 'single'
        );

    if (!singles.length) {
        return `
            <div class="info-box">
                単一選択式の質問がありません。
                分岐設定は単一選択式質問に対して行います。
            </div>
        `;
    }

    return singles.map(
        q => {

            normalizeQuestion(q);

            return `
                <div
                    style="
                        padding:14px 0;
                        border-bottom:1px solid var(--gray-200);
                    "
                >

                    <strong>
                        質問${questionNumber(survey,q)}
                        ：${escapeHtml(q.text)}
                    </strong>

                    ${q.choices.map(
                        choice => {

                            const current =
                                q.branches?.[choice.id] ||
                                {
                                    type:'next'
                                };

                            return `
                                <div class="branch-row">

                                    <div>
                                        ${escapeHtml(
                                            choice.text
                                        )}
                                    </div>

                                    <select
                                        ${locked ? 'disabled' : ''}
                                        onchange="
                                            updateBranchType(
                                                '${escapeHtml(q.id)}',
                                                '${escapeHtml(choice.id)}',
                                                this.value
                                            )
                                        "
                                    >

                                        <option
                                            value="next"
                                            ${
                                                current.type === 'next'
                                                ? 'selected'
                                                : ''
                                            }
                                        >
                                            次の質問
                                        </option>

                                        <option
                                            value="end"
                                            ${
                                                current.type === 'end'
                                                ? 'selected'
                                                : ''
                                            }
                                        >
                                            アンケート終了
                                        </option>

                                        <option
                                            value="question"
                                            ${
                                                current.type === 'question'
                                                ? 'selected'
                                                : ''
                                            }
                                        >
                                            特定の質問
                                        </option>

                                        <option
                                            value="group"
                                            ${
                                                current.type === 'group'
                                                ? 'selected'
                                                : ''
                                            }
                                        >
                                            特定のグループ
                                        </option>

                                    </select>

                                </div>
                            `;
                        }
                    ).join('')}

                </div>
            `;
        }
    ).join('');
}

function updateBranchType(
    questionId,
    choiceId,
    type
) {

    const survey =
        currentSurvey();

    if (!survey ||
        !canStructuralEdit(survey)) {
        return;
    }

    const q =
        survey.questions.find(
            x => x.id === questionId
        );

    if (!q) {
        return;
    }

    if (!q.branches) {
        q.branches = {};
    }

    q.branches[choiceId] = {
        type:type
    };

    if (type === 'question') {

        const target =
            survey.questions.find(
                x => x.id !== q.id
            );

        if (target) {
            q.branches[choiceId].target =
                target.id;
        }
    }

    if (type === 'group') {

        const target =
            survey.groups.find(
                x => x.id !== q.groupId
            );

        if (target) {
            q.branches[choiceId].target =
                target.id;
        }
    }

    survey.updatedAt =
        nowString();

    saveState();
    renderPage();
}

/* =========================================================
   Survey Save / Publish
   ========================================================= */

function readEditorValues(survey) {

    if (!canEdit(survey)) {
        return;
    }

    survey.name =
        document.getElementById(
            'surveyName'
        )?.value.trim() || '';

    survey.description =
        document.getElementById(
            'surveyDescription'
        )?.value || '';

    survey.startAt =
        document.getElementById(
            'surveyStartAt'
        )?.value || '';

    survey.endAt =
        document.getElementById(
            'surveyEndAt'
        )?.value || '';

    survey.guidance =
        document.getElementById(
            'surveyGuidance'
        )?.value || '';

    survey.completeMessage =
        document.getElementById(
            'surveyCompleteMessage'
        )?.value || '';

    if (canStructuralEdit(survey)) {
        survey.numberMode =
            document.getElementById(
                'numberMode'
            )?.value || 'global';
    }
}

function validateSurvey(survey) {

    const errors = [];

    if (!String(survey.name || '').trim()) {
        errors.push(
            'アンケート名を入力してください。'
        );
    }

    if (
        survey.startAt &&
        survey.endAt &&
        new Date(survey.startAt) >=
        new Date(survey.endAt)
    ) {
        errors.push(
            '回答受付開始日時は終了日時より前にしてください。'
        );
    }

    survey.questions.forEach(
        (q,index) => {

            if (!String(q.text || '').trim()) {
                errors.push(
                    `質問${index + 1}の質問文を入力してください。`
                );
            }

            normalizeQuestion(q);

            if (
                (
                    q.type === 'single' ||
                    q.type === 'multiple' ||
                    q.type === 'rating'
                ) &&
                q.choices.length === 0
            ) {
                errors.push(
                    `質問${index + 1}には選択肢が必要です。`
                );
            }
        }
    );

    return errors;
}

function saveSurvey() {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    if (!canEdit(survey)) {
        toast(
            'このアンケートは編集できません。'
        );
        return;
    }

    readEditorValues(survey);

    const errors =
        validateSurvey(survey);

    const errorBox =
        document.getElementById(
            'editorErrors'
        );

    if (errors.length) {

        if (errorBox) {
            errorBox.innerHTML = `
                <div class="error-box">

                    <strong>
                        入力内容を確認してください。
                    </strong>

                    <ul>
                        ${errors.map(
                            error =>
                                `<li>${escapeHtml(error)}</li>`
                        ).join('')}
                    </ul>

                </div>
            `;
        }

        return;
    }

    survey.updatedAt =
        nowString();

    saveState();

    renderPage();

    toast(
        'アンケートを保存しました。'
    );
}

function openPreview(id) {

    setCurrentSurvey(id);

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    if (survey.status === 'draft') {
        readEditorValues(survey);
        saveState();
    }

    navigate('preview');
}

function publishSurvey(id) {

    const survey =
        state.surveys.find(
            s => Number(s.id) === Number(id)
        );

    if (!survey) {
        return;
    }

    const errors =
        validateSurvey(survey);

    if (errors.length) {

        showModal(
            '公開できません',

            `
                <div class="error-box">

                    <strong>
                        以下を修正してください。
                    </strong>

                    <ul>
                        ${errors.map(
                            error =>
                                `<li>${escapeHtml(error)}</li>`
                        ).join('')}
                    </ul>

                </div>
            `,

            `
                <button
                    class="btn"
                    onclick="closeModal()"
                >
                    閉じる
                </button>
            `
        );

        return;
    }

    showConfirm(
        'アンケートを公開する',

        `
            <p>
                「${escapeHtml(survey.name)}」
                を公開します。
            </p>

            <p class="small muted">
                公開後は質問・グループ等の構造を
                変更できません。
            </p>
        `,

        '公開する',

        function() {

            const now =
                new Date();

            if (
                survey.startAt &&
                now < new Date(survey.startAt)
            ) {
                survey.status = 'wait';
            } else {
                survey.status = 'active';
            }

            if (
                survey.endAt &&
                now >= new Date(survey.endAt)
            ) {
                survey.status = 'ended';
            }

            survey.updatedAt =
                nowString();

            saveState();

            closeModal();
            renderPage();

            toast(
                'アンケートを公開しました。'
            );
        },

        'success'
    );
}

function finishSurvey(id) {

    const survey =
        state.surveys.find(
            s => Number(s.id) === Number(id)
        );

    if (!survey) {
        return;
    }

    showConfirm(
        '回答受付を終了する',

        `
            <p>
                「${escapeHtml(survey.name)}」
                の回答受付を終了します。
            </p>
        `,

        '回答受付を終了する',

        function() {

            survey.status =
                'ended';

            survey.updatedAt =
                nowString();

            saveState();

            closeModal();
            renderPage();

            toast(
                '回答受付を終了しました。'
            );
        },

        'warning'
    );
}

function archiveSurvey(id) {

    const survey =
        state.surveys.find(
            s => Number(s.id) === Number(id)
        );

    if (!survey ||
        survey.status !== 'ended') {
        return;
    }

    showConfirm(
        'アンケートを保管する',

        `
            <p>
                「${escapeHtml(survey.name)}」
                を保管します。
            </p>
        `,

        '保管する',

        function() {

            survey.status =
                'archived';

            survey.updatedAt =
                nowString();

            saveState();

            closeModal();
            renderPage();

            toast(
                'アンケートを保管しました。'
            );
        },

        'success'
    );
}

function deleteSurvey(id) {

    const survey =
        state.surveys.find(
            s => Number(s.id) === Number(id)
        );

    if (!survey ||
        survey.status !== 'draft') {
        return;
    }

    showConfirm(
        'アンケートを削除する',

        `
            <p>
                「${escapeHtml(survey.name || '名称未設定')}」
                を削除します。
            </p>
        `,

        '削除する',

        function() {

            state.surveys =
                state.surveys.filter(
                    s => Number(s.id) !== Number(id)
                );

            if (!state.surveys.length) {
                state.currentSurveyId = null;
            } else if (
                Number(state.currentSurveyId) === Number(id)
            ) {
                state.currentSurveyId =
                    state.surveys[0].id;
            }

            saveState();

            closeModal();
            navigate('surveys');

            toast(
                'アンケートを削除しました。'
            );
        },

        'danger'
    );
}

/* =========================================================
   Preview
   ========================================================= */

function renderPreview() {

    const survey =
        currentSurvey();

    if (!survey) {
        return '';
    }

    const errors =
        validateSurvey(survey);

    return `
        <div class="page-head">

            <div>

                <h1 class="page-title">
                    公開前確認
                </h1>

                <p class="page-description">
                    回答者からどのように見えるか確認できます。
                </p>

            </div>

            <div class="actions">

                <button
                    class="btn"
                    onclick="navigate('editor')"
                >
                    編集画面に戻る
                </button>

                ${
                    survey.status === 'draft'
                    ?
                    `
                    <button
                        class="btn btn-primary"
                        onclick="publishSurvey(${survey.id})"
                    >
                        この内容で公開する
                    </button>
                    `
                    :
                    ''
                }

            </div>

        </div>

        ${
            errors.length
            ?
            `
            <div class="error-box">

                <strong>
                    公開前チェックで問題があります。
                </strong>

                <ul>
                    ${errors.map(
                        error =>
                            `<li>${escapeHtml(error)}</li>`
                    ).join('')}
                </ul>

            </div>
            `
            :
            `
            <div class="success-box">
                公開前チェックをすべて通過しています。
            </div>
            `
        }

        <div class="preview-shell">

            <div class="preview-header">

                <div class="small muted">
                    アンケート名
                </div>

                <h2 style="margin:5px 0 10px">
                    ${escapeHtml(
                        survey.name || '未設定'
                    )}
                </h2>

                ${
                    survey.description
                    ?
                    `
                    <p style="white-space:pre-wrap">
                        ${escapeHtml(
                            survey.description
                        )}
                    </p>
                    `
                    :
                    ''
                }

                ${
                    survey.guidance
                    ?
                    `
                    <div class="info-box">
                        ${escapeHtml(
                            survey.guidance
                        )}
                    </div>
                    `
                    :
                    ''
                }

                <div class="small muted">
                    回答受付期間：
                    ${escapeHtml(
                        formatDate(survey.startAt)
                    )}
                    ～
                    ${escapeHtml(
                        formatDate(survey.endAt)
                    )}
                </div>

            </div>

            ${survey.groups.map(
                group => {

                    const questions =
                        survey.questions.filter(
                            q =>
                                q.groupId === group.id
                        );

                    return `
                        <div class="card">

                            <div class="card-head">
                                <h3 class="card-title">
                                    ${escapeHtml(group.name)}
                                </h3>
                            </div>

                            <div class="card-body">

                                ${
                                    questions.length
                                    ?
                                    questions.map(
                                        q =>
                                            renderPreviewQuestion(
                                                survey,
                                                q
                                            )
                                    ).join('')
                                    :
                                    '<div class="muted">質問なし</div>'
                                }

                            </div>

                        </div>
                    `;
                }
            ).join('')}

        </div>
    `;
}

function renderPreviewQuestion(
    survey,
    q
) {

    normalizeQuestion(q);

    return `
        <div class="answer-question">

            <div class="answer-question-title">

                質問${escapeHtml(
                    questionNumber(survey,q)
                )}

                ${escapeHtml(q.text)}

                ${
                    q.required
                    ?
                    `
                    <span class="required-label">
                        必須
                    </span>
                    `
                    :
                    ''
                }

            </div>

            ${
                q.help
                ?
                `
                <div
                    class="small muted"
                    style="margin-bottom:10px"
                >
                    ${escapeHtml(q.help)}
                </div>
                `
                :
                ''
            }

            ${renderAnswerInput(q)}

        </div>
    `;
}

function renderAnswerInput(q) {

    normalizeQuestion(q);

    if (q.type === 'text') {
        return `
            <textarea
                placeholder="回答を入力してください"
            ></textarea>
        `;
    }

    if (
        q.type === 'single' ||
        q.type === 'rating'
    ) {
        return q.choices.map(
            choice => `
                <label class="option">
                    <input
                        type="radio"
                        name="preview_${escapeHtml(q.id)}"
                    >
                    <span>
                        ${escapeHtml(choice.text)}
                    </span>
                </label>
            `
        ).join('');
    }

    if (q.type === 'multiple') {
        return q.choices.map(
            choice => `
                <label class="option">
                    <input
                        type="checkbox"
                    >
                    <span>
                        ${escapeHtml(choice.text)}
                    </span>
                </label>
            `
        ).join('');
    }

    return '';
}

/* =========================================================
   Responses
   ========================================================= */

function openResponses(id) {

    setCurrentSurvey(id);
    state.responseSurveyId =
        Number(id);

    navigate('responses');
}

function renderResponses() {

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) ===
                Number(state.responseSurveyId || state.currentSurveyId)
        );

    if (!survey) {
        return `
            <div class="error-box">
                アンケートが見つかりません。
            </div>
        `;
    }

    const rate =
        responseRate(survey);

    const answers =
        Array.isArray(survey.answers)
        ? survey.answers
        : [];

    return `
        <div class="page-head">

            <div>

                <h1 class="page-title">
                    回答状況
                </h1>

                <p class="page-description">
                    ${escapeHtml(survey.name)}
                </p>

            </div>

            <div class="actions">

                <button
                    class="btn"
                    onclick="navigate('surveys')"
                >
                    アンケート一覧
                </button>

                <button
                    class="btn btn-primary"
                    onclick="
                        openResponseDetail(
                            ${survey.id}
                        )
                    "
                >
                    回答内容を見る
                </button>

            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    回答状況の概要
                </h2>

                ${statusBadge(survey.status)}
            </div>

            <div class="card-body">

                <div class="kpi-grid">

                    <div class="kpi">
                        <div class="kpi-label">
                            回答対象者数
                        </div>

                        <div class="kpi-value">
                            ${Number(
                                survey.sentCount || 0
                            )}
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            回答数
                        </div>

                        <div class="kpi-value">
                            ${Number(
                                survey.responseCount || 0
                            )}
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            回答率
                        </div>

                        <div class="kpi-value">
                            ${
                                rate === null
                                ? '-'
                                : rate + '%'
                            }
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            未回答
                        </div>

                        <div class="kpi-value">
                            ${
                                Math.max(
                                    0,
                                    Number(
                                        survey.sentCount || 0
                                    ) -
                                    Number(
                                        survey.responseCount || 0
                                    )
                                )
                            }
                        </div>
                    </div>

                </div>

            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    回答一覧
                </h2>
            </div>

            <div class="card-body">

                ${
                    answers.length
                    ?
                    `
                    <div class="table-wrap">

                        <table>

                            <thead>
                                <tr>
                                    <th>回答番号</th>
                                    <th>回答者</th>
                                    <th>回答日時</th>
                                    <th>操作</th>
                                </tr>
                            </thead>

                            <tbody>

                                ${answers.map(
                                    answer => `
                                        <tr>

                                            <td>
                                                ${escapeHtml(
                                                    answer.number
                                                )}
                                            </td>

                                            <td>
                                                ${escapeHtml(
                                                    answer.respondent
                                                )}
                                            </td>

                                            <td>
                                                ${escapeHtml(
                                                    answer.answeredAt
                                                )}
                                            </td>

                                            <td>
                                                <button
                                                    class="btn btn-sm"
                                                    onclick="
                                                        openAnswerDetail(
                                                            ${survey.id},
                                                            ${answer.id}
                                                        )
                                                    "
                                                >
                                                    内容を見る
                                                </button>
                                            </td>

                                        </tr>
                                    `
                                ).join('')}

                            </tbody>

                        </table>

                    </div>
                    `
                    :
                    `
                    <div class="muted">
                        回答データはありません。
                    </div>
                    `
                }

            </div>

        </div>
    `;
}

function openResponseDetail(id) {

    setCurrentSurvey(id);

    state.responseSurveyId =
        Number(id);

    navigate('response-detail');
}

function openAnswerDetail(
    surveyId,
    answerId
) {

    setCurrentSurvey(surveyId);

    state.responseSurveyId =
        Number(surveyId);

    const survey =
        currentSurvey();

    const answer =
        survey?.answers.find(
            a =>
                Number(a.id) ===
                Number(answerId)
        );

    if (!answer) {
        toast(
            '回答が見つかりません。'
        );
        return;
    }

    showModal(
        '回答内容',

        `
            <div class="form-group">
                <label class="form-label">
                    回答番号
                </label>
                <div>
                    ${escapeHtml(answer.number)}
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">
                    回答者
                </label>
                <div>
                    ${escapeHtml(answer.respondent)}
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">
                    回答日時
                </label>
                <div>
                    ${escapeHtml(answer.answeredAt)}
                </div>
            </div>

            ${
                survey.questions.map(
                    q => `
                        <div class="form-group">

                            <label class="form-label">
                                ${escapeHtml(q.text)}
                            </label>

                            <div>
                                ${escapeHtml(
                                    formatAnswerValue(
                                        answer.values?.[q.id]
                                    )
                                )}
                            </div>

                        </div>
                    `
                ).join('')
            }
        `,

        `
            <button
                class="btn"
                onclick="closeModal()"
            >
                閉じる
            </button>
        `
    );
}

function formatAnswerValue(value) {

    if (Array.isArray(value)) {
        return value.join('、');
    }

    if (
        value === null ||
        typeof value === 'undefined' ||
        value === ''
    ) {
        return '未回答';
    }

    return String(value);
}

function renderResponseDetail() {

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) ===
                Number(state.responseSurveyId || state.currentSurveyId)
        );

    if (!survey) {
        return `
            <div class="error-box">
                アンケートが見つかりません。
            </div>
        `;
    }

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    回答内容
                </h1>

                <p class="page-description">
                    ${escapeHtml(survey.name)}
                </p>
            </div>

            <div class="actions">

                <button
                    class="btn"
                    onclick="openResponses(${survey.id})"
                >
                    回答状況へ戻る
                </button>

            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    回答一覧
                </h2>
            </div>

            <div class="card-body">

                ${
                    survey.answers.length
                    ?
                    `
                    <div class="table-wrap">

                        <table>

                            <thead>
                                <tr>
                                    <th>回答番号</th>
                                    <th>回答者</th>
                                    <th>回答日時</th>
                                    <th>操作</th>
                                </tr>
                            </thead>

                            <tbody>

                                ${survey.answers.map(
                                    answer => `
                                        <tr>

                                            <td>
                                                ${escapeHtml(
                                                    answer.number
                                                )}
                                            </td>

                                            <td>
                                                ${escapeHtml(
                                                    answer.respondent
                                                )}
                                            </td>

                                            <td>
                                                ${escapeHtml(
                                                    answer.answeredAt
                                                )}
                                            </td>

                                            <td>
                                                <button
                                                    class="btn btn-sm"
                                                    onclick="
                                                        openAnswerDetail(
                                                            ${survey.id},
                                                            ${answer.id}
                                                        )
                                                    "
                                                >
                                                    回答内容を見る
                                                </button>
                                            </td>

                                        </tr>
                                    `
                                ).join('')}

                            </tbody>

                        </table>

                    </div>
                    `
                    :
                    `
                    <div class="muted">
                        回答データはありません。
                    </div>
                    `
                }

            </div>

        </div>
    `;
}

/* =========================================================
   Send
   =========================================================
   送付先は顧客一覧を表形式で直接選択する。
   ========================================================= */

function openSend(id) {

    if (id) {
        setCurrentSurvey(id);
    }

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    if (
        survey.status !== 'wait' &&
        survey.status !== 'active'
    ) {
        toast(
            '現在の状態ではアンケートを送付できません。'
        );
        return;
    }

    sendDraft = {
        surveyId:survey.id,

        customerIds:[
            ...(survey.selectedCustomerIds || [])
        ],

        subject:
            `${survey.name}のご案内`,

        body:
            `いつもお世話になっております。\n\n` +
            `以下のアンケートへのご回答をお願いいたします。\n\n` +
            `${survey.name}\n\n` +
            `よろしくお願いいたします。`
    };

    navigate('send');
}

function renderSend() {

    const survey =
        currentSurvey();

    if (!survey) {
        return `
            <div class="error-box">
                アンケートが見つかりません。
            </div>
        `;
    }

    if (!sendDraft ||
        Number(sendDraft.surveyId) !== Number(survey.id)
    ) {
        sendDraft = {
            surveyId:survey.id,
            customerIds:[
                ...(survey.selectedCustomerIds || [])
            ],
            subject:`${survey.name}のご案内`,
            body:
                `いつもお世話になっております。\n\n` +
                `以下のアンケートへのご回答をお願いいたします。\n\n` +
                `${survey.name}\n\n` +
                `よろしくお願いいたします。`
        };
    }

    const selectedCount =
        sendDraft.customerIds.length;

    return `
        <div class="page-head">

            <div>

                <h1 class="page-title">
                    アンケート送付
                </h1>

                <p class="page-description">
                    ${escapeHtml(survey.name)}
                </p>

            </div>

            <div class="actions">

                <button
                    class="btn"
                    onclick="navigate('surveys')"
                >
                    アンケート一覧
                </button>

                <button
                    class="btn btn-primary"
                    onclick="openSendConfirm()"
                >
                    送付内容を確認する
                </button>

            </div>

        </div>

        <div class="card">

            <div class="card-head">

                <div>

                    <h2 class="card-title">
                        送付先
                    </h2>

                    <div class="small muted">
                        顧客一覧から送付対象を選択してください。
                    </div>

                </div>

                <div class="actions">

                    <strong>
                        選択中：${selectedCount}件
                    </strong>

                    <button
                        class="btn btn-sm"
                        onclick="selectAllCustomers()"
                    >
                        全選択
                    </button>

                    <button
                        class="btn btn-sm"
                        onclick="clearCustomers()"
                    >
                        全解除
                    </button>

                </div>

            </div>

            <div class="card-body">

                <div class="table-wrap">

                    <table>

                        <thead>

                            <tr>
                                <th style="width:70px">
                                    選択
                                </th>

                                <th>
                                    顧客名
                                </th>

                                <th>
                                    担当者名
                                </th>

                                <th>
                                    メールアドレス
                                </th>
                            </tr>

                        </thead>

                        <tbody>

                            ${state.customers.map(
                                customer => {

                                    const checked =
                                        sendDraft.customerIds
                                        .includes(
                                            Number(customer.id)
                                        );

                                    return `
                                        <tr>

                                            <td
                                                style="text-align:center"
                                            >

                                                <input
                                                    type="checkbox"
                                                    ${
                                                        checked
                                                        ? 'checked'
                                                        : ''
                                                    }
                                                    onchange="
                                                        toggleCustomer(
                                                            ${customer.id},
                                                            this.checked
                                                        )
                                                    "
                                                    style="
                                                        width:18px;
                                                        height:18px;
                                                    "
                                                >

                                            </td>

                                            <td>
                                                ${escapeHtml(
                                                    customer.name
                                                )}
                                            </td>

                                            <td>
                                                ${escapeHtml(
                                                    customer.contact
                                                )}
                                            </td>

                                            <td>
                                                ${escapeHtml(
                                                    customer.email
                                                )}
                                            </td>

                                        </tr>
                                    `;
                                }
                            ).join('')}

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    送付内容
                </h2>

            </div>

            <div class="card-body">

                <div class="form-group">

                    <label class="form-label">
                        件名
                    </label>

                    <input
                        type="text"
                        id="sendSubject"
                        value="${escapeHtml(
                            sendDraft.subject
                        )}"
                    >

                </div>

                <div class="form-group">

                    <label class="form-label">
                        本文
                    </label>

                    <textarea
                        id="sendBody"
                        style="min-height:220px"
                    >${escapeHtml(
                        sendDraft.body
                    )}</textarea>

                </div>

            </div>

        </div>
    `;
}

function toggleCustomer(
    customerId,
    checked
) {

    if (!sendDraft) {
        return;
    }

    const id =
        Number(customerId);

    const set =
        new Set(
            sendDraft.customerIds.map(
                Number
            )
        );

    if (checked) {
        set.add(id);
    } else {
        set.delete(id);
    }

    sendDraft.customerIds =
        [...set];

    renderPage();
}

function selectAllCustomers() {

    if (!sendDraft) {
        return;
    }

    sendDraft.customerIds =
        state.customers.map(
            customer =>
                Number(customer.id)
        );

    renderPage();
}

function clearCustomers() {

    if (!sendDraft) {
        return;
    }

    sendDraft.customerIds =
        [];

    renderPage();
}

function openSendConfirm() {

    if (!sendDraft) {
        return;
    }

    sendDraft.subject =
        document.getElementById(
            'sendSubject'
        )?.value || '';

    sendDraft.body =
        document.getElementById(
            'sendBody'
        )?.value || '';

    if (!sendDraft.customerIds.length) {
        toast(
            '送付先を1件以上選択してください。'
        );
        return;
    }

    navigate('send-confirm');
}

function renderSendConfirm() {

    const survey =
        currentSurvey();

    if (!survey ||
        !sendDraft) {
        return '';
    }

    const selected =
        state.customers.filter(
            customer =>
                sendDraft.customerIds.includes(
                    Number(customer.id)
                )
        );

    return `
        <div class="page-head">

            <div>

                <h1 class="page-title">
                    送付確認
                </h1>

                <p class="page-description">
                    送付内容を確認してください。
                </p>

            </div>

            <div class="actions">

                <button
                    class="btn"
                    onclick="navigate('send')"
                >
                    戻る
                </button>

                <button
                    class="btn btn-primary"
                    onclick="executeSend()"
                >
                    この内容で送付する
                </button>

            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    送付内容
                </h2>
            </div>

            <div class="card-body">

                <div class="form-group">

                    <label class="form-label">
                        アンケート
                    </label>

                    <div>
                        ${escapeHtml(survey.name)}
                    </div>

                </div>

                <div class="form-group">

                    <label class="form-label">
                        件名
                    </label>

                    <div>
                        ${escapeHtml(
                            sendDraft.subject
                        )}
                    </div>

                </div>

                <div class="form-group">

                    <label class="form-label">
                        本文
                    </label>

                    <div
                        style="white-space:pre-wrap"
                    >
                        ${escapeHtml(
                            sendDraft.body
                        )}
                    </div>

                </div>

            </div>

        </div>

        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    送付先
                </h2>

                <span>
                    ${selected.length}件
                </span>

            </div>

            <div class="card-body">

                <div class="table-wrap">

                    <table>

                        <thead>
                            <tr>
                                <th>顧客名</th>
                                <th>担当者名</th>
                                <th>メールアドレス</th>
                            </tr>
                        </thead>

                        <tbody>

                            ${selected.map(
                                customer => `
                                    <tr>

                                        <td>
                                            ${escapeHtml(
                                                customer.name
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                customer.contact
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                customer.email
                                            )}
                                        </td>

                                    </tr>
                                `
                            ).join('')}

                        </tbody>

                    </table>

                </div>

            </div>

        </div>
    `;
}

function executeSend() {

    const survey =
        currentSurvey();

    if (!survey ||
        !sendDraft) {
        return;
    }

    const target =
        sendDraft.customerIds.length;

    const success =
        target;

    const failed =
        0;

    survey.selectedCustomerIds =
        [...sendDraft.customerIds];

    survey.sentCount =
        Number(survey.sentCount || 0) +
        success;

    survey.lastSentAt =
        nowString();

    survey.updatedAt =
        nowString();

    state.sendResults.unshift({
        id:
            Math.max(
                0,
                ...state.sendResults.map(
                    r => Number(r.id) || 0
                )
            ) + 1,

        surveyId:survey.id,
        target:target,
        success:success,
        failed:failed,
        sentAt:nowString(),
        failedCustomers:[]
    });

    saveState();

    sendDraft = null;

    navigate('send-result');

    toast(
        'アンケートを送付しました。'
    );
}

function renderSendResult() {

    const result =
        state.sendResults[0];

    if (!result) {
        return `
            <div class="card">
                <div class="card-body">
                    送付結果はありません。
                </div>
            </div>
        `;
    }

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) ===
                Number(result.surveyId)
        );

    return `
        <div class="page-head">

            <div>

                <h1 class="page-title">
                    送付結果
                </h1>

                <p class="page-description">
                    最新の送付結果を表示しています。
                </p>

            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    送付結果
                </h2>
            </div>

            <div class="card-body">

                <div class="kpi-grid">

                    <div class="kpi">
                        <div class="kpi-label">
                            アンケート
                        </div>

                        <div style="margin-top:8px">
                            ${escapeHtml(
                                survey?.name || '-'
                            )}
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            対象
                        </div>

                        <div class="kpi-value">
                            ${result.target}
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            成功
                        </div>

                        <div class="kpi-value text-success">
                            ${result.success}
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            失敗
                        </div>

                        <div class="kpi-value text-danger">
                            ${result.failed}
                        </div>
                    </div>

                </div>

                <div
                    class="small muted"
                    style="margin-top:15px"
                >
                    送付日時：
                    ${escapeHtml(result.sentAt)}
                </div>

            </div>

        </div>

        ${
            result.failedCustomers?.length
            ?
            `
            <div class="card">

                <div class="card-head">
                    <h2 class="card-title">
                        送付失敗
                    </h2>
                </div>

                <div class="card-body">

                    <ul>
                        ${result.failedCustomers.map(
                            item =>
                                `<li>${escapeHtml(item)}</li>`
                        ).join('')}
                    </ul>

                </div>

            </div>
            `
            :
            ''
        }
    `;
}

/* =========================================================
   Settings
   ========================================================= */

function renderSettings() {

    const k =
        state.settings.kintone;

    const smtp =
        state.settings.smtp;

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    各種設定
                </h1>

                <p class="page-description">
                    外部サービス接続設定のモックです。
                </p>
            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    kintone設定
                </h2>
            </div>

            <div class="card-body">

                <div class="form-grid">

                    <div class="form-group">
                        <label class="form-label">
                            ホスト
                        </label>

                        <input
                            type="text"
                            id="kHost"
                            value="${escapeHtml(k.host)}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            アプリID
                        </label>

                        <input
                            type="text"
                            id="kApp"
                            value="${escapeHtml(k.app)}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            顧客名フィールド
                        </label>

                        <input
                            type="text"
                            id="kName"
                            value="${escapeHtml(k.nameField)}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            担当者名フィールド
                        </label>

                        <input
                            type="text"
                            id="kContact"
                            value="${escapeHtml(k.contactField)}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            メールアドレスフィールド
                        </label>

                        <input
                            type="text"
                            id="kEmail"
                            value="${escapeHtml(k.emailField)}"
                        >
                    </div>

                </div>

                <button
                    class="btn btn-primary"
                    onclick="saveSettings()"
                >
                    kintone設定を保存
                </button>

            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    SMTP設定
                </h2>
            </div>

            <div class="card-body">

                <div class="form-grid">

                    <div class="form-group">
                        <label class="form-label">
                            SMTPホスト
                        </label>

                        <input
                            type="text"
                            id="smtpHost"
                            value="${escapeHtml(smtp.host)}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            ポート
                        </label>

                        <input
                            type="text"
                            id="smtpPort"
                            value="${escapeHtml(smtp.port)}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            From
                        </label>

                        <input
                            type="email"
                            id="smtpFrom"
                            value="${escapeHtml(smtp.from)}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            暗号化
                        </label>

                        <select id="smtpEncryption">

                            <option
                                value="STARTTLS"
                                ${
                                    smtp.encryption === 'STARTTLS'
                                    ? 'selected'
                                    : ''
                                }
                            >
                                STARTTLS
                            </option>

                            <option
                                value="SSL/TLS"
                                ${
                                    smtp.encryption === 'SSL/TLS'
                                    ? 'selected'
                                    : ''
                                }
                            >
                                SSL/TLS
                            </option>

                        </select>
                    </div>

                </div>

                <button
                    class="btn btn-primary"
                    onclick="saveSettings()"
                >
                    SMTP設定を保存
                </button>

            </div>

        </div>
    `;
}

function saveSettings() {

    state.settings.kintone.host =
        document.getElementById(
            'kHost'
        )?.value || '';

    state.settings.kintone.app =
        document.getElementById(
            'kApp'
        )?.value || '';

    state.settings.kintone.nameField =
        document.getElementById(
            'kName'
        )?.value || '';

    state.settings.kintone.contactField =
        document.getElementById(
            'kContact'
        )?.value || '';

    state.settings.kintone.emailField =
        document.getElementById(
            'kEmail'
        )?.value || '';

    state.settings.kintone.updatedAt =
        nowString();

    state.settings.smtp.host =
        document.getElementById(
            'smtpHost'
        )?.value || '';

    state.settings.smtp.port =
        document.getElementById(
            'smtpPort'
        )?.value || '';

    state.settings.smtp.from =
        document.getElementById(
            'smtpFrom'
        )?.value || '';

    state.settings.smtp.encryption =
        document.getElementById(
            'smtpEncryption'
        )?.value || 'STARTTLS';

    saveState();

    toast(
        '設定を保存しました。'
    );
}

/* =========================================================
   Answerer
   ========================================================= */

function startAnswer() {

    const candidates =
        state.surveys.filter(
            s => s.status === 'active'
        );

    const survey =
        candidates[0] ||
        state.surveys[0];

    if (!survey) {
        toast(
            '回答可能なアンケートがありません。'
        );
        return;
    }

    setCurrentSurvey(survey.id);

    answerState = {
        surveyId:survey.id,
        currentIndex:0,
        values:{},
        visibleQuestions:
            [...survey.questions]
    };

    navigate('answer');
}

function renderAnswer() {

    const survey =
        currentSurvey();

    if (!survey) {
        return `
            <div class="error-box">
                アンケートが見つかりません。
            </div>
        `;
    }

    if (survey.status !== 'active') {
        return `
            <div class="error-box">
                現在このアンケートは回答を受け付けていません。
            </div>
        `;
    }

    const questions =
        answerState.visibleQuestions.length
        ?
        answerState.visibleQuestions
        :
        survey.questions;

    const q =
        questions[answerState.currentIndex];

    if (!q) {
        return renderAnswerComplete();
    }

    const progress =
        Math.round(
            (
                answerState.currentIndex + 1
            ) /
            questions.length *
            100
        );

    return `
        <div class="preview-shell">

            <div class="preview-header">

                <div class="small muted">
                    回答者向け画面
                </div>

                <h1 style="margin:6px 0">
                    ${escapeHtml(survey.name)}
                </h1>

                ${
                    survey.guidance
                    ?
                    `
                    <p
                        style="white-space:pre-wrap"
                    >
                        ${escapeHtml(
                            survey.guidance
                        )}
                    </p>
                    `
                    :
                    ''
                }

                <div
                    style="
                        height:8px;
                        background:var(--gray-200);
                        border-radius:999px;
                        overflow:hidden;
                        margin-top:18px;
                    "
                >
                    <div
                        style="
                            width:${progress}%;
                            height:100%;
                            background:var(--primary);
                        "
                    ></div>
                </div>

                <div
                    class="small muted"
                    style="margin-top:7px"
                >
                    ${answerState.currentIndex + 1}
                    /
                    ${questions.length}
                </div>

            </div>

            <div class="answer-question">

                <div class="answer-question-title">

                    質問${escapeHtml(
                        questionNumber(survey,q)
                    )}

                    ${escapeHtml(q.text)}

                    ${
                        q.required
                        ?
                        `
                        <span class="required-label">
                            必須
                        </span>
                        `
                        :
                        ''
                    }

                </div>

                ${
                    q.help
                    ?
                    `
                    <div
                        class="small muted"
                        style="margin-bottom:10px"
                    >
                        ${escapeHtml(q.help)}
                    </div>
                    `
                    :
                    ''
                }

                ${renderInteractiveAnswerInput(q)}

            </div>

            <div class="answer-footer">

                <button
                    class="btn"
                    ${
                        answerState.currentIndex === 0
                        ? 'disabled'
                        : ''
                    }
                    onclick="answerBack()"
                >
                    戻る
                </button>

                <button
                    class="btn btn-primary"
                    onclick="answerNext()"
                >
                    ${
                        answerState.currentIndex + 1 === questions.length
                        ? '回答を確認する'
                        : '次へ'
                    }
                </button>

            </div>

        </div>
    `;
}

function renderInteractiveAnswerInput(q) {

    const value =
        answerState.values[q.id];

    if (q.type === 'text') {
        return `
            <textarea
                id="answerText"
                placeholder="回答を入力してください"
            >${escapeHtml(
                value || ''
            )}</textarea>
        `;
    }

    if (
        q.type === 'single' ||
        q.type === 'rating'
    ) {

        return q.choices.map(
            choice => `
                <label class="option">

                    <input
                        type="radio"
                        name="answerChoice"
                        value="${escapeHtml(choice.text)}"
                        ${
                            String(value || '') ===
                            String(choice.text)
                            ? 'checked'
                            : ''
                        }
                    >

                    <span>
                        ${escapeHtml(choice.text)}
                    </span>

                </label>
            `
        ).join('');
    }

    if (q.type === 'multiple') {

        const selected =
            Array.isArray(value)
            ? value
            : [];

        return q.choices.map(
            choice => `
                <label class="option">

                    <input
                        type="checkbox"
                        name="answerMultiple"
                        value="${escapeHtml(choice.text)}"
                        ${
                            selected.includes(
                                choice.text
                            )
                            ? 'checked'
                            : ''
                        }
                    >

                    <span>
                        ${escapeHtml(choice.text)}
                    </span>

                </label>
            `
        ).join('');
    }

    return '';
}

function captureAnswer() {

    const survey =
        currentSurvey();

    const questions =
        answerState.visibleQuestions;

    const q =
        questions[
            answerState.currentIndex
        ];

    if (!q) {
        return;
    }

    if (q.type === 'text') {

        answerState.values[q.id] =
            document.getElementById(
                'answerText'
            )?.value || '';

        return;
    }

    if (
        q.type === 'single' ||
        q.type === 'rating'
    ) {

        const selected =
            document.querySelector(
                'input[name="answerChoice"]:checked'
            );

        answerState.values[q.id] =
            selected?.value || '';

        return;
    }

    if (q.type === 'multiple') {

        answerState.values[q.id] =
            [...document.querySelectorAll(
                'input[name="answerMultiple"]:checked'
            )].map(
                input => input.value
            );
    }
}

function answerNext() {

    const survey =
        currentSurvey();

    const q =
        answerState.visibleQuestions[
            answerState.currentIndex
        ];

    if (!q) {
        return;
    }

    captureAnswer();

    const value =
        answerState.values[q.id];

    if (
        q.required &&
        (
            value === '' ||
            value === null ||
            typeof value === 'undefined' ||
            (
                Array.isArray(value) &&
                value.length === 0
            )
        )
    ) {
        toast(
            '必須項目を入力してください。'
        );
        return;
    }

    const branch =
        q.type === 'single'
        ?
        q.branches?.[
            q.choices.find(
                c =>
                    c.text === value
            )?.id
        ]
        :
        null;

    if (branch?.type === 'end') {
        navigate('answer-confirm');
        return;
    }

    if (
        answerState.currentIndex + 1 <
        answerState.visibleQuestions.length
    ) {

        answerState.currentIndex++;

        renderPage();

    } else {

        navigate('answer-confirm');
    }
}

function answerBack() {

    captureAnswer();

    if (answerState.currentIndex > 0) {
        answerState.currentIndex--;
        renderPage();
    }
}

function renderAnswerConfirm() {

    const survey =
        currentSurvey();

    return `
        <div class="preview-shell">

            <div class="page-head">

                <div>
                    <h1 class="page-title">
                        回答確認
                    </h1>

                    <p class="page-description">
                        入力内容を確認してください。
                    </p>
                </div>

            </div>

            <div class="card">

                <div class="card-head">
                    <h2 class="card-title">
                        ${escapeHtml(
                            survey?.name || ''
                        )}
                    </h2>
                </div>

                <div class="card-body">

                    ${survey.questions.map(
                        q => `
                            <div class="form-group">

                                <label class="form-label">
                                    ${escapeHtml(q.text)}
                                </label>

                                <div>
                                    ${escapeHtml(
                                        formatAnswerValue(
                                            answerState.values[q.id]
                                        )
                                    )}
                                </div>

                            </div>
                        `
                    ).join('')}

                </div>

            </div>

            <div class="answer-footer">

                <button
                    class="btn"
                    onclick="
                        answerState.currentIndex =
                            Math.max(
                                0,
                                answerState.visibleQuestions.length - 1
                            );

                        navigate('answer')
                    "
                >
                    修正する
                </button>

                <button
                    class="btn btn-primary"
                    onclick="submitAnswer()"
                >
                    回答を送信する
                </button>

            </div>

        </div>
    `;
}

function submitAnswer() {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    const answerId =
        Math.max(
            0,
            ...survey.answers.map(
                a => Number(a.id) || 0
            )
        ) + 1;

    survey.answers.push({
        id:answerId,
        number:
            'R-' +
            String(answerId).padStart(4,'0'),
        answeredAt:nowString(),
        respondent:'モック回答者',
        values:clone(
            answerState.values
        )
    });

    survey.responseCount =
        Number(survey.responseCount || 0) +
        1;

    survey.updatedAt =
        nowString();

    saveState();

    navigate('answer-complete');
}

function renderAnswerComplete() {

    const survey =
        currentSurvey();

    return `
        <div class="preview-shell">

            <div class="card">

                <div class="card-body">

                    <div
                        class="success-box"
                        style="margin:0"
                    >
                        <h2
                            style="
                                margin-top:0
                            "
                        >
                            回答が完了しました
                        </h2>

                        <p>
                            ${escapeHtml(
                                survey?.completeMessage ||
                                'ご回答ありがとうございました。'
                            )}
                        </p>

                    </div>

                    <div
                        style="
                            margin-top:18px;
                            text-align:center;
                        "
                    >
                        <button
                            class="btn"
                            onclick="navigate('home')"
                        >
                            ホームへ戻る
                        </button>
                    </div>

                </div>

            </div>

        </div>
    `;
}

/* =========================================================
   Modal
   ========================================================= */

function showModal(
    title,
    body,
    footer
) {

    document.getElementById(
        'modalTitle'
    ).textContent =
        title;

    document.getElementById(
        'modalBody'
    ).innerHTML =
        body;

    document.getElementById(
        'modalFooter'
    ).innerHTML =
        footer;

    document.getElementById(
        'modalBackdrop'
    ).classList.add(
        'show'
    );
}

function showConfirm(
    title,
    body,
    confirmLabel,
    callback,
    kind
) {

    const buttonClass =
        kind === 'danger'
        ? 'btn-danger'
        :
        kind === 'warning'
        ? 'btn-warning'
        :
        kind === 'success'
        ? 'btn-success'
        :
        'btn-primary';

    showModal(
        title,
        body,

        `
            <button
                class="btn"
                onclick="closeModal()"
            >
                キャンセル
            </button>

            <button
                class="btn ${buttonClass}"
                id="modalConfirmButton"
            >
                ${escapeHtml(confirmLabel)}
            </button>
        `
    );

    document.getElementById(
        'modalConfirmButton'
    ).onclick =
        callback;
}

function closeModal() {

    document.getElementById(
        'modalBackdrop'
    ).classList.remove(
        'show'
    );
}

/* =========================================================
   Toast
   ========================================================= */

let toastTimer = null;

function toast(message) {

    const element =
        document.getElementById(
            'toast'
        );

    if (!element) {
        return;
    }

    element.textContent =
        message;

    element.classList.add(
        'show'
    );

    clearTimeout(
        toastTimer
    );

    toastTimer =
        setTimeout(
            () => {
                element.classList.remove(
                    'show'
                );
            },
            2400
        );
}

/* =========================================================
   Initialization
   ========================================================= */

async function initApp() {

    /*
     * localStorage は使用しない。
     *
     * PHPセッションから読み込めない場合も
     * defaultData でそのまま起動する。
     */
    await loadServerState();

    syncSurveyStatuses();

    const page =
        state.currentPage ||
        'home';

    const title =
        pageTitles[page] ||
        'ホーム';

    document.getElementById(
        'topbarTitle'
    ).textContent =
        title;

    document
        .querySelectorAll(
            '.nav button[data-page]'
        )
        .forEach(
            button => {
                button.classList.toggle(
                    'active',
                    button.dataset.page === page
                );
            }
        );

    renderPage();
}

/*
 * 外側のPoCタブ機構には依存しない。
 * このファイル単体で起動する。
 */
document.addEventListener(
    'DOMContentLoaded',
    initApp
);
</script>

</body>
</html>
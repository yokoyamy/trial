<?php
/*
 * アンケート管理アプリ モック
 * Apache + PHP / 1ファイル構成
 *
 * - DB / kintone / SMTP には接続しません
 * - ブラウザ localStorage でモック状態を保持します
 * - HTML / CSS / JavaScript を本ファイルに同梱
 */

session_start();

$appTitle = 'アンケート管理アプリ';
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
    opacity:.9;
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
    line-height:1.6;
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

.card-title {
    margin:0;
    font-size:16px;
    font-weight:700;
}

.card-body {
    padding:18px;
}

.muted {
    color:var(--gray-500);
}

.small {
    font-size:12px;
}

.text-danger {
    color:var(--danger);
}

.text-success {
    color:var(--success);
}

.text-warning {
    color:var(--warning);
}

.text-primary {
    color:var(--primary);
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
   Forms
========================================================= */

.form-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-grid .full {
    grid-column:1 / -1;
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

.form-label .required {
    color:var(--danger);
    margin-left:4px;
}

input[type="text"],
input[type="email"],
input[type="datetime-local"],
input[type="number"],
input[type="password"],
select,
textarea {
    width:100%;
    border:1px solid var(--gray-300);
    border-radius:7px;
    padding:9px 10px;
    background:#fff;
    color:var(--gray-800);
}

textarea {
    min-height:100px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus {
    outline:none;
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.1);
}

input:disabled,
select:disabled,
textarea:disabled {
    background:var(--gray-100);
    color:var(--gray-500);
}

.help {
    margin-top:5px;
    font-size:12px;
    color:var(--gray-500);
}

.error-box {
    border:1px solid #fecaca;
    background:#fef2f2;
    color:#991b1b;
    border-radius:8px;
    padding:12px 14px;
    margin-bottom:15px;
}

.error-box ul {
    margin:7px 0 0 18px;
    padding:0;
}

.success-box {
    border:1px solid #bbf7d0;
    background:#f0fdf4;
    color:#166534;
    border-radius:8px;
    padding:12px 14px;
    margin-bottom:15px;
}

.info-box {
    border:1px solid #bae6fd;
    background:#f0f9ff;
    color:#075985;
    border-radius:8px;
    padding:12px 14px;
    margin-bottom:15px;
}

/* =========================================================
   Survey List Search
========================================================= */

.search-panel {
    display:grid;
    grid-template-columns:minmax(250px,1fr) 230px auto;
    gap:12px;
    align-items:end;
    padding:14px;
    background:var(--gray-50);
    border:1px solid var(--gray-200);
    border-radius:8px;
    margin-bottom:18px;
}

.search-actions {
    display:flex;
    gap:8px;
    white-space:nowrap;
}

/* =========================================================
   Editor
========================================================= */

.editor-layout {
    display:grid;
    grid-template-columns:250px minmax(0,1fr);
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
    gap:12px;
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

.question-list {
    min-height:28px;
    padding:8px;
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

.drag-handle {
    cursor:grab;
    color:var(--gray-400);
    font-size:18px;
    padding-right:7px;
}

.question-title {
    flex:1;
    line-height:1.6;
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
    margin-bottom:7px;
}

.choice-row input {
    flex:1;
}

.branch-box {
    margin-top:12px;
    padding:10px;
    background:var(--gray-50);
    border-radius:7px;
}

.branch-row {
    display:grid;
    grid-template-columns:minmax(150px,1fr) minmax(180px,1fr);
    gap:8px;
    margin-top:8px;
}

.question-add-footer {
    display:flex;
    justify-content:flex-end;
    padding:12px 8px 8px;
    border-top:1px dashed var(--gray-300);
    margin-top:4px;
}

.group-list-footer {
    display:flex;
    justify-content:center;
    padding:18px 0 4px;
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

.answer-progress {
    margin:15px 0 20px;
}

.progress-track {
    height:8px;
    background:var(--gray-200);
    border-radius:999px;
    overflow:hidden;
}

.progress-bar {
    height:100%;
    background:var(--primary);
    border-radius:999px;
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

.option input {
    margin-top:3px;
}

.answer-footer {
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:18px;
}

/* =========================================================
   KPI / Chart
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

.bar-chart {
    display:flex;
    align-items:flex-end;
    gap:12px;
    height:220px;
    padding:20px 10px 0;
}

.bar-column {
    flex:1;
    height:100%;
    display:flex;
    flex-direction:column;
    justify-content:flex-end;
    align-items:center;
}

.bar {
    width:70%;
    min-height:2px;
    background:linear-gradient(180deg,#60a5fa,#2563eb);
    border-radius:5px 5px 0 0;
}

.bar-label {
    margin-top:7px;
    font-size:11px;
    color:var(--gray-500);
}

/* =========================================================
   Send
========================================================= */

.recipient-table {
    min-width:760px;
}

.recipient-check {
    width:70px;
    text-align:center;
}

.recipient-check input {
    width:18px;
    height:18px;
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
    width:min(700px,100%);
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
        justify-content:center;
        align-items:center;
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
            <div id="topbarTitle" class="topbar-title">ホーム</div>
            <div class="user">アンケート運営管理者</div>
        </header>

        <div id="appContent" class="content"></div>

    </main>

</div>

<div id="modalBackdrop" class="modal-backdrop">

    <div class="modal">

        <div class="modal-head">
            <strong id="modalTitle">確認</strong>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>

        <div id="modalBody" class="modal-body"></div>

        <div id="modalFooter" class="modal-footer"></div>

    </div>

</div>

<div id="toast" class="toast"></div>


<script>
'use strict';

/* =========================================================
   Mock Data
========================================================= */

const STORAGE_KEY = 'questionnaire_mock_v3';

const defaultData = {

    currentSurveyId: 1,
    currentPage: 'home',

    listFilter: 'all',
    listSearch: '',
    responseSurveyId: 1,

    settings: {

        kintone: {
            host:'https://example.cybozu.com',
            app:'123',
            nameField:'顧客名',
            contactField:'担当者名',
            emailField:'メールアドレス',
            connected:true,
            updatedAt:'2026-09-16 09:30'
        },

        smtp: {
            host:'smtp.example.jp',
            port:'587',
            from:'questionnaire@example.jp',
            encryption:'STARTTLS',
            configured:true
        }

    },

    customers: [

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

    surveys: [

        {
            id:1,
            name:'2026年度 顧客満足度アンケート',
            description:'サービスをご利用いただいたお客様への満足度調査です。',
            guidance:'各質問にご回答ください。所要時間は約5分です。',
            completeMessage:'ご回答ありがとうございました.',

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
                {
                    id:'g1',
                    name:'基本情報'
                },
                {
                    id:'g2',
                    name:'サービス評価'
                }
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
                        {
                            id:'c1',
                            text:'はい'
                        },
                        {
                            id:'c2',
                            text:'いいえ'
                        }
                    ],
                    branches:{
                        c1:{
                            type:'next'
                        },
                        c2:{
                            type:'question',
                            target:'q4'
                        }
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
                        {
                            id:'m1',
                            text:'オンラインサポート'
                        },
                        {
                            id:'m2',
                            text:'レポート機能'
                        },
                        {
                            id:'m3',
                            text:'コンサルティング'
                        }
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
                {
                    id:'g1',
                    name:'利用意向'
                },
                {
                    id:'g2',
                    name:'ご意見'
                }
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
                        {
                            id:'c1',
                            text:'ぜひ利用したい'
                        },
                        {
                            id:'c2',
                            text:'検討したい'
                        },
                        {
                            id:'c3',
                            text:'利用予定はない'
                        }
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
                {
                    id:'g1',
                    name:'サービス評価'
                }
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
                {
                    id:'g1',
                    name:'アンケート'
                }
            ],

            questions:[
                {
                    id:'q1',
                    groupId:'g1',
                    text:'サービスに満足していますか？',
                    type:'single',
                    required:true,
                    help:'',
                    choices:[
                        {
                            id:'c1',
                            text:'はい'
                        },
                        {
                            id:'c2',
                            text:'いいえ'
                        }
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
   State
========================================================= */

let state = loadState();

let editorQuestionDragId = null;

let answerState = {
    surveyId:null,
    currentIndex:0,
    values:{},
    visibleQuestions:[]
};

let sendDraft = null;


/* =========================================================
   State Helpers
========================================================= */

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function loadState() {

    try {

        const raw = localStorage.getItem(STORAGE_KEY);

        if (raw) {

            const loaded = JSON.parse(raw);

            /*
             * 旧モックデータとの互換性を確保
             */
            const merged = clone(defaultData);

            Object.assign(merged, loaded);

            if (!Array.isArray(merged.surveys)) {
                merged.surveys = clone(defaultData.surveys);
            }

            if (!Array.isArray(merged.customers)) {
                merged.customers = clone(defaultData.customers);
            }

            if (!Array.isArray(merged.sendResults)) {
                merged.sendResults = [];
            }

            if (!merged.settings) {
                merged.settings = clone(defaultData.settings);
            }

            if (!merged.settings.kintone) {
                merged.settings.kintone = clone(defaultData.settings.kintone);
            }

            if (!merged.settings.smtp) {
                merged.settings.smtp = clone(defaultData.settings.smtp);
            }

            if (typeof merged.listFilter === 'undefined') {
                merged.listFilter = 'all';
            }

            if (typeof merged.listSearch === 'undefined') {
                merged.listSearch = '';
            }

            return merged;
        }

    } catch (e) {

        console.warn(e);

    }

    return clone(defaultData);
}

function saveState() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}

function resetMock() {

    showConfirm(
        'モックデータを初期状態へ戻す',

        `
            <p>
                現在ブラウザに保存されているモックデータを
                初期状態へ戻します。
            </p>

            <p class="text-danger">
                編集したアンケート、送付先、回答データ等も
                初期状態に戻ります。
            </p>
        `,

        '初期状態へ戻す',

        function() {

            state = clone(defaultData);

            sendDraft = null;

            saveState();

            closeModal();

            navigate('home');

            toast('モックデータを初期状態へ戻しました。');
        },

        'danger'
    );
}


/* =========================================================
   Generic Helpers
========================================================= */

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

    const pad = n => String(n).padStart(2,'0');

    return [
        d.getFullYear(),
        pad(d.getMonth()+1),
        pad(d.getDate())
    ].join('-') + ' ' +
    [
        pad(d.getHours()),
        pad(d.getMinutes())
    ].join(':');

}

function formatDate(value) {

    if (!value) {
        return '-';
    }

    return String(value).replace('T',' ');

}

function currentSurvey() {

    return state.surveys.find(
        s => Number(s.id) === Number(state.currentSurveyId)
    ) || state.surveys[0] || null;

}

function setCurrentSurvey(id) {

    state.currentSurveyId = Number(id);

    state.responseSurveyId = Number(id);

    saveState();

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

function statusClass(status) {

    return {

        draft:'status-draft',

        wait:'status-wait',

        active:'status-active',

        ended:'status-ended',

        archived:'status-archived'

    }[status] || 'status-draft';

}

function statusBadge(status) {

    return `
        <span class="status ${statusClass(status)}">
            ${escapeHtml(statusLabel(status))}
        </span>
    `;

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

function countByStatus(status) {

    return state.surveys.filter(
        s => s.status === status
    ).length;

}

function responseRate(survey) {

    if (!survey) {
        return null;
    }

    const target = Number(survey.sentCount || 0);
    const response = Number(survey.responseCount || 0);

    if (target <= 0) {
        return null;
    }

    return Math.round(response / target * 100);

}

function questionTypeLabel(type) {

    return {

        text:'文章を入力する質問',

        single:'1つだけ選ぶ質問',

        multiple:'複数選ぶ質問',

        rating:'段階的に評価する質問'

    }[type] || type;

}

function questionNumber(survey, question) {

    if (!survey || !question) {
        return '-';
    }

    if (survey.numberMode === 'group') {

        const sameGroup = survey.questions.filter(
            q => q.groupId === question.groupId
        );

        const index = sameGroup.findIndex(
            q => q.id === question.id
        );

        return index >= 0 ? index + 1 : '-';

    }

    const index = survey.questions.findIndex(
        q => q.id === question.id
    );

    return index >= 0 ? index + 1 : '-';

}

function normalizeChoices(question) {

    if (!Array.isArray(question.choices)) {
        question.choices = [];
    }

    question.choices = question.choices.map(
        (choice, index) => {

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

function getChoiceText(choice) {

    return typeof choice === 'string'
        ? choice
        : choice?.text || '';

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
        .forEach(btn => {

            btn.classList.toggle(
                'active',
                btn.dataset.page === page
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
   Survey Status
========================================================= */

function syncSurveyStatuses() {

    const now = new Date();

    let changed = false;

    state.surveys.forEach(survey => {

        if (
            survey.status === 'wait' &&
            survey.startAt
        ) {

            const start = new Date(survey.startAt);

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

            const end = new Date(survey.endAt);

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

function statCard(label, number, status) {

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

    navigate('surveys');

}

function renderHome() {

    const sorted =
        [...state.surveys]
        .sort(
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
                    アンケートの運営状況と次に行う操作を確認できます。
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
                                                        ${escapeHtml(survey.updatedAt || '-')}
                                                    </td>

                                                    <td>
                                                        ${Number(survey.responseCount || 0)}件
                                                    </td>

                                                    <td>
                                                        <button
                                                            class="btn btn-sm btn-primary"
                                                            onclick="openSurvey(${survey.id})"
                                                        >
                                                            内容を開く
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
                                アンケートはありません。
                            </div>
                        `
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
                                        padding:12px 0;
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

                                    <div class="small muted" style="margin-top:7px">
                                        回答受付：
                                        ${formatDate(survey.startAt)}
                                        ～ 
                                        ${formatDate(survey.endAt)}
                                    </div>

                                </div>

                            `
                        ).join('')
                        :
                        `
                            <div class="muted">
                                現在運営中のアンケートはありません。
                            </div>
                        `
                    }

                </div>

            </div>


            <div class="card">

                <div class="card-head">

                    <h2 class="card-title">
                        回答状況を確認したいアンケート
                    </h2>

                </div>

                <div class="card-body">

                    ${
                        responseCheck.length
                        ?
                        `
                            <div class="table-wrap">

                                <table>

                                    <thead>
                                        <tr>
                                            <th>アンケート名</th>
                                            <th>状態</th>
                                            <th>回答数</th>
                                            <th>回答率</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        ${responseCheck.map(
                                            survey => {

                                                const rate =
                                                    responseRate(survey);

                                                return `

                                                    <tr>

                                                        <td>
                                                            ${escapeHtml(survey.name)}
                                                        </td>

                                                        <td>
                                                            ${statusBadge(survey.status)}
                                                        </td>

                                                        <td>
                                                            ${Number(survey.responseCount || 0)}件
                                                        </td>

                                                        <td>
                                                            ${
                                                                rate === null
                                                                ? '-'
                                                                : rate + '%'
                                                            }
                                                        </td>

                                                        <td>
                                                            <button
                                                                class="btn btn-sm"
                                                                onclick="openResponses(${survey.id})"
                                                            >
                                                                回答状況を見る
                                                            </button>
                                                        </td>

                                                    </tr>

                                                `;

                                            }
                                        ).join('')}

                                    </tbody>

                                </table>

                            </div>
                        `
                        :
                        `
                            <div class="muted">
                                回答状況を確認できるアンケートはありません。
                            </div>
                        `
                    }

                </div>

            </div>


            <div class="card">

                <div class="card-head">

                    <h2 class="card-title">
                        モック操作
                    </h2>

                </div>

                <div class="card-body">

                    <div class="info-box">
                        この画面はモックです。
                        実際のkintone、SMTP、データベースには接続しません。
                    </div>

                    <div class="actions">

                        <button
                            class="btn"
                            onclick="navigate('settings')"
                        >
                            各種設定
                        </button>

                        <button
                            class="btn"
                            onclick="startAnswer()"
                        >
                            回答者画面を確認
                        </button>

                        <button
                            class="btn btn-danger"
                            onclick="resetMock()"
                        >
                            モックを初期化
                        </button>

                    </div>

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
                    任意のアンケートを選択して内容の確認・編集・回答状況確認・送付等を行えます。
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

            <div class="card-head">

                <div>

                    <strong>
                        ${
                            filter === 'all'
                            ? 'すべてのアンケート'
                            : statusLabel(filter)
                        }
                    </strong>

                    <span class="small muted">
                        （${surveys.length}件）
                    </span>

                </div>

            </div>


            <div class="card-body">

                <div class="search-panel">

                    <div>

                        <label
                            class="form-label"
                            for="surveySearch"
                        >
                            アンケート名で検索
                        </label>

                        <input
                            id="surveySearch"
                            type="text"
                            value="${escapeHtml(state.listSearch || '')}"
                            placeholder="アンケート名を入力"
                            onkeydown="
                                if(event.key === 'Enter'){
                                    applySurveySearch();
                                }
                            "
                        >

                    </div>


                    <div>

                        <label
                            class="form-label"
                            for="surveyFilter"
                        >
                            状態
                        </label>

                        <select
                            id="surveyFilter"
                            onchange="applySurveyFilter(this.value)"
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


                    <div class="search-actions">

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
                            条件クリア
                        </button>

                    </div>

                </div>


                <div class="table-wrap">

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    アンケート名
                                </th>

                                <th>
                                    状態
                                </th>

                                <th>
                                    作成日
                                </th>

                                <th>
                                    更新日
                                </th>

                                <th>
                                    回答受付期間
                                </th>

                                <th>
                                    回答状況
                                </th>

                                <th>
                                    主な操作
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            ${
                                surveys.length
                                ?
                                surveys.map(
                                    survey =>
                                        surveyRow(survey)
                                ).join('')
                                :
                                `
                                    <tr>

                                        <td
                                            colspan="7"
                                            style="
                                                text-align:center;
                                                padding:35px;
                                            "
                                            class="muted"
                                        >
                                            該当するアンケートはありません。
                                        </td>

                                    </tr>
                                `
                            }

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    `;

}

function surveyRow(survey) {

    const rate =
        responseRate(survey);

    return `

        <tr>

            <td>

                <strong>
                    ${escapeHtml(survey.name || '名称未設定')}
                </strong>

                <div class="small muted">
                    ${survey.questions?.length || 0}問
                </div>

            </td>


            <td>
                ${statusBadge(survey.status)}
            </td>


            <td>
                ${escapeHtml(survey.createdAt || '-')}
            </td>


            <td>
                ${escapeHtml(survey.updatedAt || '-')}
            </td>


            <td>

                ${formatDate(survey.startAt)}
                <br>
                <span class="muted">～</span>
                <br>
                ${formatDate(survey.endAt)}

            </td>


            <td>

                <strong>
                    ${Number(survey.responseCount || 0)}
                </strong>
                件

                <div class="small muted">
                    ${
                        rate === null
                        ? '回答率 -'
                        : '回答率 ' + rate + '%'
                    }
                </div>

            </td>


            <td class="actions-cell">

                <!--
                    ★ 全状態のアンケートを一覧から開ける。
                    作成中は編集、
                    公開済みは参照モード。
                -->

                <button
                    class="btn btn-sm btn-primary"
                    onclick="openSurvey(${survey.id})"
                >
                    内容を開く
                </button>


                ${
                    survey.status === 'draft'
                    ?
                    `
                        <button
                            class="btn btn-sm"
                            onclick="editSurvey(${survey.id})"
                        >
                            編集する
                        </button>

                        <button
                            class="btn btn-sm"
                            onclick="openPreview(${survey.id})"
                        >
                            公開前確認
                        </button>

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


                ${
                    survey.status === 'wait'
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
                            onclick="openSend(${survey.id})"
                        >
                            送付する
                        </button>

                        <button
                            class="btn btn-sm btn-success"
                            onclick="startSurvey(${survey.id})"
                        >
                            回答受付を開始
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

                        <button
                            class="btn btn-sm"
                            onclick="openSend(${survey.id})"
                        >
                            送付する
                        </button>

                        <button
                            class="btn btn-sm btn-warning"
                            onclick="endSurvey(${survey.id})"
                        >
                            回答受付を終了
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

                        <button
                            class="btn btn-sm btn-primary"
                            onclick="archiveSurvey(${survey.id})"
                        >
                            保管する
                        </button>
                    `
                    :
                    ''
                }


                ${
                    survey.status === 'archived'
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

            </td>

        </tr>

    `;

}

function applySurveySearch() {

    const input =
        document.getElementById('surveySearch');

    state.listSearch =
        input ? input.value : '';

    saveState();

    renderPage();

}

function applySurveyFilter(value) {

    state.listFilter = value;

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
   Survey Create / Open / Edit
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

    /*
     * ★ 任意のアンケートを一覧から開ける。
     * draft / wait は編集、
     * active / ended / archived は参照モード。
     */
    navigate('editor');

}


/* =========================================================
   Editor
========================================================= */

function renderEditor() {

    const survey = currentSurvey();

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

    const readOnly =
        !editable;

    const ungrouped =
        survey.questions.filter(
            q =>
                !survey.groups.some(
                    g => g.id === q.groupId
                )
        );

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
                        ${escapeHtml(survey.name || '名称未設定')}
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
                    survey.status === 'draft'
                    ?
                    `
                        <button
                            class="btn"
                            onclick="saveSurvey()"
                        >
                            保存する
                        </button>

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
                    survey.status === 'wait'
                    ?
                    `
                        <button
                            class="btn"
                            onclick="saveSurvey()"
                        >
                            基本情報を保存
                        </button>

                        <button
                            class="btn btn-primary"
                            onclick="openSend(${survey.id})"
                        >
                            アンケートを送付
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
                            class="btn"
                            onclick="openResponses(${survey.id})"
                        >
                            回答状況
                        </button>

                        <button
                            class="btn btn-primary"
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
            readOnly
            ?
            `
                <div class="info-box">

                    このアンケートは
                    「${escapeHtml(statusLabel(survey.status))}」
                    です。

                    公開後のアンケートは、
                    既存回答との対応関係を維持するため
                    質問・選択肢・グループ・分岐などの構造を変更できません。

                    この画面は参照用です。

                </div>
            `
            :
            survey.status === 'wait'
            ?
            `
                <div class="info-box">

                    公開済み・回答開始待ちです。
                    基本情報は変更できますが、
                    質問・選択肢・グループ・分岐などの構造は変更できません。

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


                <!-- =================================================
                     Basic
                ================================================= -->

                <div class="card" id="basic">

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

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <input
                                    id="surveyName"
                                    type="text"
                                    value="${escapeHtml(survey.name)}"
                                    ${readOnly ? 'disabled' : ''}
                                >

                            </div>


                            <div class="form-group full">

                                <label class="form-label">
                                    説明文
                                </label>

                                <textarea
                                    id="surveyDescription"
                                    ${readOnly ? 'disabled' : ''}
                                >${escapeHtml(survey.description)}</textarea>

                            </div>


                            <div class="form-group">

                                <label class="form-label">
                                    回答受付開始日時
                                </label>

                                <input
                                    id="surveyStartAt"
                                    type="datetime-local"
                                    value="${escapeHtml(survey.startAt)}"
                                    ${readOnly ? 'disabled' : ''}
                                >

                            </div>


                            <div class="form-group">

                                <label class="form-label">
                                    回答受付終了日時
                                </label>

                                <input
                                    id="surveyEndAt"
                                    type="datetime-local"
                                    value="${escapeHtml(survey.endAt)}"
                                    ${readOnly ? 'disabled' : ''}
                                >

                            </div>


                            <div class="form-group">

                                <label class="form-label">
                                    回答者への案内文
                                </label>

                                <textarea
                                    id="surveyGuidance"
                                    ${readOnly ? 'disabled' : ''}
                                >${escapeHtml(survey.guidance)}</textarea>

                            </div>


                            <div class="form-group">

                                <label class="form-label">
                                    回答完了時のメッセージ
                                </label>

                                <textarea
                                    id="surveyCompleteMessage"
                                    ${readOnly ? 'disabled' : ''}
                                >${escapeHtml(survey.completeMessage)}</textarea>

                            </div>


                            <div class="form-group">

                                <label class="form-label">
                                    質問番号
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


                        ${
                            !readOnly
                            ?
                            `
                                <div class="actions">

                                    <button
                                        class="btn btn-primary"
                                        onclick="saveSurvey()"
                                    >
                                        基本情報を保存する
                                    </button>

                                </div>
                            `
                            :
                            ''
                        }

                    </div>

                </div>


                <!-- =================================================
                     Questions
                ================================================= -->

                <div class="card" id="questions">

                    <div class="card-head">

                        <div>

                            <h2 class="card-title">
                                質問・グループ
                            </h2>

                            <div class="small muted">
                                ${
                                    structuralLocked
                                    ?
                                    '公開済みのため構造変更できません。'
                                    :
                                    '各グループの末尾から質問を追加できます。質問はドラッグ＆ドロップで並べ替え・グループ移動できます。'
                                }
                            </div>

                        </div>

                    </div>


                    <div class="card-body">

                        <div
                            id="groupList"
                            class="group-list"
                        >

                            ${
                                survey.groups.length
                                ?
                                survey.groups.map(
                                    group =>
                                        renderGroup(
                                            survey,
                                            group,
                                            structuralLocked
                                        )
                                ).join('')
                                :
                                `
                                    <div class="info-box">
                                        グループがありません。
                                    </div>
                                `
                            }


                            <!-- =========================================
                                 Ungrouped
                            ========================================== -->

                            <div
                                class="group-box"
                                ondragover="allowDrop(event)"
                                ondrop="dropQuestionToGroup(event,null)"
                            >

                                <div class="group-head">

                                    <div>

                                        <div class="group-name">
                                            グループ未設定
                                        </div>

                                        <div class="small muted">
                                            グループに所属していない質問
                                        </div>

                                    </div>

                                </div>


                                <div class="question-list">

                                    ${
                                        ungrouped.length
                                        ?
                                        ungrouped.map(
                                            q =>
                                                renderQuestion(
                                                    survey,
                                                    q,
                                                    structuralLocked
                                                )
                                        ).join('')
                                        :
                                        `
                                            <div
                                                class="small muted"
                                                style="padding:8px"
                                            >
                                                質問はありません。
                                            </div>
                                        `
                                    }

                                </div>


                                ${
                                    !structuralLocked
                                    ?
                                    `
                                        <div class="question-add-footer">

                                            <button
                                                class="btn btn-sm btn-primary"
                                                onclick="addQuestion(null)"
                                            >
                                                ＋ 質問を追加
                                            </button>

                                        </div>
                                    `
                                    :
                                    ''
                                }

                            </div>


                            <!-- =========================================
                                 ★ Group Add Button
                                 Always at the end of group list.
                            ========================================== -->

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

                </div>


                <!-- =================================================
                     Branch
                ================================================= -->

                <div class="card" id="branch">

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

function renderGroup(survey, group, locked) {

    const questions =
        survey.questions.filter(
            q => q.groupId === group.id
        );

    return `

        <div
            class="group-box"
            data-group-id="${escapeHtml(group.id)}"
            ondragover="allowDrop(event)"
            ondrop="
                dropQuestionToGroup(
                    event,
                    '${escapeHtml(group.id)}'
                )
            "
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
                                style="font-weight:700"
                            >
                        `
                    }


                    <div class="small muted">
                        ${questions.length}問
                    </div>

                </div>


                ${
                    !locked
                    ?
                    `
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
                    `
                    :
                    ''
                }

            </div>


            <div
                class="question-list"
                data-group-id="${escapeHtml(group.id)}"
            >

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
                        <div
                            class="small muted"
                            style="padding:8px"
                        >
                            このグループには質問がありません。
                        </div>
                    `
                }

            </div>


            <!-- ★ 質問追加ボタンは各グループの末尾 -->

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

    `;

}


/* =========================================================
   Question
========================================================= */

function renderQuestion(survey, q, locked) {

    normalizeChoices(q);

    const number =
        questionNumber(survey,q);

    return `

        <div
            class="question-card"
            draggable="${locked ? 'false' : 'true'}"
            data-question-id="${escapeHtml(q.id)}"

            ${
                locked
                ?
                ''
                :
                `
                    ondragstart="questionDragStart(event,'${escapeHtml(q.id)}')"
                    ondragend="questionDragEnd(event)"
                    ondragover="questionDragOver(event)"
                    ondragleave="questionDragLeave(event)"
                    ondrop="dropQuestionBefore(event,'${escapeHtml(q.id)}')"
                `
            }
        >


            <div class="question-head">


                <div
                    style="
                        display:flex;
                        flex:1;
                        min-width:0;
                    "
                >

                    ${
                        !locked
                        ?
                        `
                            <div class="drag-handle" title="ドラッグして並べ替え">
                                ⋮⋮
                            </div>
                        `
                        :
                        ''
                    }


                    <div class="question-title">

                        <div>

                            <span class="question-number">
                                質問${number}
                            </span>

                            <span
                                class="status"
                                style="
                                    margin-left:6px;
                                    font-size:10px;
                                "
                            >
                                ${escapeHtml(
                                    questionTypeLabel(q.type)
                                )}
                            </span>

                            ${
                                q.required
                                ?
                                `
                                    <span
                                        class="required-label"
                                        style="font-size:10px"
                                    >
                                        必須
                                    </span>
                                `
                                :
                                ''
                            }

                        </div>


                        <div
                            style="
                                margin-top:7px;
                                white-space:pre-wrap;
                            "
                        >
                            ${
                                escapeHtml(
                                    q.text ||
                                    '質問文未入力'
                                )
                            }
                        </div>


                        ${
                            q.help
                            ?
                            `
                                <div class="help">
                                    ${escapeHtml(q.help)}
                                </div>
                            `
                            :
                            ''
                        }


                        ${
                            q.choices?.length
                            ?
                            `
                                <div class="choice-list">

                                    ${q.choices.map(
                                        choice => `

                                            <div class="small">

                                                ・
                                                ${escapeHtml(
                                                    getChoiceText(choice)
                                                )}

                                            </div>

                                        `
                                    ).join('')}

                                </div>
                            `
                            :
                            ''
                        }

                    </div>

                </div>


                <div class="question-actions">

                    <button
                        class="btn btn-sm"
                        onclick="
                            editQuestion(
                                '${escapeHtml(q.id)}'
                            )
                        "
                    >
                        ${
                            locked
                            ? '内容を見る'
                            : '編集'
                        }
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
                q.type === 'single' &&
                q.choices?.length
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

function addQuestion(groupId = null) {

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


    /*
     * グループがまだ存在しない場合は
     * 自動で基本情報グループを作成。
     */

    if (!survey.groups.length) {

        survey.groups.push({

            id:uid('g'),

            name:'基本情報'

        });

    }


    if (
        groupId &&
        !survey.groups.some(
            g => g.id === groupId
        )
    ) {

        toast(
            '指定されたグループが見つかりません。'
        );

        return;

    }


    const question = {

        id:uid('q'),

        /*
         * ★ 押したグループへ追加
         */
        groupId:groupId || null,

        text:'',

        type:'text',

        required:false,

        help:'',

        choices:[],

        branches:{}

    };


    survey.questions.push(question);

    survey.updatedAt = nowString();

    saveState();

    renderPage();


    setTimeout(
        () =>
            editQuestion(question.id),
        50
    );

}


/* =========================================================
   Edit Question
========================================================= */

function editQuestion(questionId) {

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

    /*
     * ★ 既存質問編集対象を明示的に保持
     */
    window.editingQuestionId = questionId;

    const locked =
        !canStructuralEdit(survey);

    if (locked) {

        showModal(

            '質問内容',

            renderQuestionReadonly(
                survey,
                q
            ),

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


    normalizeChoices(q);


    showModal(

        '質問を編集する',

        renderQuestionForm(
            survey,
            q
        ),

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
                        '${escapeHtml(q.id)}'
                    )
                "
            >
                保存する
            </button>
        `

    );


    bindQuestionTypeEvents();

}


/* =========================================================
   Question Form
========================================================= */

function renderQuestionReadonly(survey,q) {

    normalizeChoices(q);

    return `

        <div class="form-group">

            <label class="form-label">
                質問文
            </label>

            <div
                style="
                    white-space:pre-wrap;
                    padding:10px;
                    background:var(--gray-50);
                    border-radius:7px;
                "
            >
                ${escapeHtml(q.text)}
            </div>

        </div>


        <div class="form-group">

            <label class="form-label">
                回答形式
            </label>

            <div>
                ${escapeHtml(
                    questionTypeLabel(q.type)
                )}
            </div>

        </div>


        <div class="form-group">

            <label class="form-label">
                必須回答
            </label>

            <div>
                ${q.required ? '必須' : '任意'}
            </div>

        </div>


        ${
            q.choices?.length
            ?
            `
                <div class="form-group">

                    <label class="form-label">
                        選択肢
                    </label>

                    <ul>
                        ${q.choices.map(
                            c =>
                                `<li>${escapeHtml(
                                    getChoiceText(c)
                                )}</li>`
                        ).join('')}
                    </ul>

                </div>
            `
            :
            ''
        }


        ${
            q.help
            ?
            `
                <div class="form-group">

                    <label class="form-label">
                        補足説明
                    </label>

                    <div>
                        ${escapeHtml(q.help)}
                    </div>

                </div>
            `
            :
            ''
        }

    `;

}

function renderQuestionForm(survey,q) {

    normalizeChoices(q);

    return `

        <div class="form-group">

            <label class="form-label">

                質問文

                <span class="required">
                    *
                </span>

            </label>

            <textarea
                id="editQText"
                style="min-height:120px"
            >${escapeHtml(q.text)}</textarea>

        </div>


        <div class="form-group">

            <label class="form-label">
                回答形式
            </label>

            <select
                id="editQType"
                onchange="renderChoiceEditorFromModal()"
            >

                <option
                    value="text"
                    ${q.type === 'text' ? 'selected' : ''}
                >
                    文章を入力する質問
                </option>

                <option
                    value="single"
                    ${q.type === 'single' ? 'selected' : ''}
                >
                    1つだけ選ぶ質問
                </option>

                <option
                    value="multiple"
                    ${q.type === 'multiple' ? 'selected' : ''}
                >
                    複数選ぶ質問
                </option>

                <option
                    value="rating"
                    ${q.type === 'rating' ? 'selected' : ''}
                >
                    段階的に評価する質問
                </option>

            </select>

        </div>


        <div class="form-group">

            <label>

                <input
                    id="editQRequired"
                    type="checkbox"
                    ${q.required ? 'checked' : ''}
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
            >${escapeHtml(q.help)}</textarea>

        </div>


        <div
            id="modalChoiceEditor"
            class="form-group"
        >
            ${renderChoiceEditor(q)}
        </div>

    `;

}

function renderChoiceEditor(q) {

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

            <label class="form-label">
                評価段階
            </label>

            <div class="small muted">
                段階評価は1～5を使用します。
            </div>

            <div
                class="choice-list"
                style="margin-top:10px"
            >

                ${[1,2,3,4,5].map(
                    n =>
                        `
                            <div class="choice-row">

                                <input
                                    type="text"
                                    value="${n}"
                                    disabled
                                >

                            </div>
                        `
                ).join('')}

            </div>

        `;

    }


    const choices =
        q.choices?.length
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

        <label class="form-label">
            選択肢
        </label>

        <div
            id="modalChoices"
        >

            ${choices.map(
                choice => `

                    <div
                        class="choice-row"
                        data-choice-id="${escapeHtml(choice.id)}"
                    >

                        <input
                            type="text"
                            value="${escapeHtml(
                                getChoiceText(choice)
                            )}"
                            placeholder="選択肢"
                        >

                        <button
                            class="btn btn-sm btn-danger"
                            type="button"
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
            class="btn btn-sm"
            type="button"
            onclick="addModalChoice()"
        >
            ＋ 選択肢を追加
        </button>

    `;

}

function bindQuestionTypeEvents() {

    /*
     * 初期表示時にも正しい選択肢UIを描画
     */
    setTimeout(
        () =>
            renderChoiceEditorFromModal(),
        0
    );

}

function renderChoiceEditorFromModal() {

    const q =
        currentSurvey()
        ?.questions.find(
            x =>
                x.id ===
                window.editingQuestionId
        );

    if (!q) {
        return;
    }

    const container =
        document.getElementById(
            'modalChoiceEditor'
        );

    if (!container) {
        return;
    }

    container.innerHTML =
        renderChoiceEditor(q);

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

    const div =
        document.createElement('div');

    div.className =
        'choice-row';

    div.dataset.choiceId =
        id;

    div.innerHTML = `

        <input
            type="text"
            value=""
            placeholder="選択肢"
        >

        <button
            class="btn btn-sm btn-danger"
            type="button"
            onclick="
                this.parentElement.remove()
            "
        >
            削除
        </button>

    `;

    list.appendChild(div);

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

                const input =
                    row.querySelector('input');

                const value =
                    input?.value.trim() || '';

                if (value) {

                    choices.push({

                        id:
                            row.dataset.choiceId ||
                            uid('c'),

                        text:value

                    });

                }

            });

        /*
         * 空欄は削除扱い。
         */
    }


    if (type === 'rating') {

        choices =
            [1,2,3,4,5].map(
                (n,index) => ({
                    id:'r' + (index + 1),
                    text:String(n)
                })
            );

    }


    if (
        (
            type === 'single' ||
            type === 'multiple' ||
            type === 'rating'
        ) &&
        choices.length === 0
    ) {

        errors.push(
            '選択式質問には1件以上の選択肢が必要です。'
        );

    }


    if (errors.length) {

        showValidationErrors(
            errors
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

    if (!q.branches) {
        q.branches = {};
    }


    /*
     * 回答形式変更時に存在しない分岐を整理
     */
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


    const references = [];

    survey.questions.forEach(
        parent => {

            Object.entries(
                parent.branches || {}
            ).forEach(
                ([choiceId,branch]) => {

                    if (
                        branch.type === 'question' &&
                        branch.target === questionId
                    ) {

                        references.push(
                            parent
                        );

                    }

                }
            );

        }
    );


    showConfirm(

        '質問を削除する',

        `
            <p>
                「${escapeHtml(
                    q.text ||
                    '未入力の質問'
                )}」
                を削除します。
            </p>

            ${
                references.length
                ?
                `
                    <div class="error-box">

                        この質問は
                        分岐先として使用されています。

                        削除すると、
                        該当する分岐設定も削除されます。

                    </div>
                `
                :
                ''
            }
        `,

        '質問を削除する',

        function() {

            state.surveys =
                state.surveys;

            survey.questions =
                survey.questions.filter(
                    x =>
                        x.id !== questionId
                );


            /*
             * 削除質問を参照している分岐を削除
             */
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


    const number =
        survey.groups.length + 1;


    survey.groups.push({

        id:uid('g'),

        name:'グループ' + number

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

    if (!survey) {
        return;
    }

    if (!canStructuralEdit(survey)) {
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

    const group =
        survey.groups.find(
            g => g.id === id
        );

    if (!group) {
        closeModal();
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

    const group =
        survey.groups.find(
            g => g.id === id
        );

    if (!group) {
        closeModal();
        return;
    }

    const questionIds =
        survey.questions
        .filter(q => q.groupId === id)
        .map(q => q.id);


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
   Question Drag & Drop
========================================================= */

function questionDragStart(event,id) {

    editorQuestionDragId =
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
        .querySelectorAll('.drag-over')
        .forEach(
            el =>
                el.classList.remove(
                    'drag-over'
                )
        );

    editorQuestionDragId =
        null;

}

function allowDrop(event) {

    event.preventDefault();

    event.dataTransfer.dropEffect =
        'move';

}

function questionDragOver(event) {

    event.preventDefault();

    event.dataTransfer.dropEffect =
        'move';

    event.currentTarget.classList.add(
        'drag-over'
    );

}

function questionDragLeave(event) {

    event.currentTarget.classList.remove(
        'drag-over'
    );

}

function dropQuestionBefore(event,targetQuestionId) {

    event.preventDefault();

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    if (!canStructuralEdit(survey)) {

        toast(
            '公開後は質問を並べ替えできません。'
        );

        return;

    }


    const sourceId =
        editorQuestionDragId ||
        event.dataTransfer.getData(
            'text/plain'
        );

    if (
        !sourceId ||
        sourceId === targetQuestionId
    ) {

        questionDragEnd(
            event
        );

        return;

    }


    const sourceIndex =
        survey.questions.findIndex(
            q => q.id === sourceId
        );

    const targetIndex =
        survey.questions.findIndex(
            q => q.id === targetQuestionId
        );

    if (
        sourceIndex < 0 ||
        targetIndex < 0
    ) {
        return;
    }


    const source =
        survey.questions.splice(
            sourceIndex,
            1
        )[0];


    let insertIndex =
        survey.questions.findIndex(
            q => q.id === targetQuestionId
        );


    if (insertIndex < 0) {
        insertIndex =
            survey.questions.length;
    }


    survey.questions.splice(
        insertIndex,
        0,
        source
    );


    survey.updatedAt =
        nowString();

    saveState();

    renderPage();

}

function dropQuestionToGroup(event,groupId) {

    event.preventDefault();

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


    const sourceId =
        editorQuestionDragId ||
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


    /*
     * 同じグループへのドロップは
     * グループ末尾へ移動
     */
    const currentGroupId =
        q.groupId || null;

    if (
        currentGroupId === groupId
    ) {

        const sourceIndex =
            survey.questions.findIndex(
                x => x.id === sourceId
            );

        const source =
            survey.questions.splice(
                sourceIndex,
                1
            )[0];

        /*
         * 対象グループの最後の質問を検索
         */
        let lastIndex = -1;

        survey.questions.forEach(
            (item,index) => {

                if (
                    (item.groupId || null) ===
                    (groupId || null)
                ) {

                    lastIndex = index;

                }

            }
        );

        survey.questions.splice(
            lastIndex + 1,
            0,
            source
        );

    } else {

        q.groupId =
            groupId || null;

    }


    survey.updatedAt =
        nowString();

    saveState();

    renderPage();

}


/* =========================================================
   Branch Settings
========================================================= */

function renderQuestionBranchSummary(
    survey,
    q
) {

    const rows =
        q.choices.map(
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
                            background:var(--gray-50);
                            border-radius:6px;
                        "
                    >

                        <strong>
                            ${escapeHtml(
                                getChoiceText(choice)
                            )}
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
        )
        .join('');


    return `

        <div class="branch-box">

            <div class="small">
                <strong>
                    分岐
                </strong>
            </div>

            ${rows}

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

    switch(branch.type) {

        case 'next':
            return '次の質問';

        case 'end':
            return '回答終了';

        case 'question': {

            const q =
                survey.questions.find(
                    x => x.id === branch.target
                );

            return q
                ? `質問${questionNumber(survey,q)}：${q.text}`
                : '存在しない質問';

        }

        case 'group': {

            const g =
                survey.groups.find(
                    x => x.id === branch.target
                );

            return g
                ? `グループ：${g.name}`
                : '存在しないグループ';

        }

        default:
            return '未設定';

    }

}

function renderBranchSettings(
    survey,
    locked
) {

    const questions =
        survey.questions.filter(
            q =>
                q.type === 'single' &&
                q.choices?.length
        );

    if (!questions.length) {

        return `
            <div class="muted">
                1つだけ選ぶ質問がないため、
                分岐設定はありません。
            </div>
        `;

    }


    return questions.map(
        q => `

            <div
                style="
                    padding:15px;
                    border:1px solid var(--gray-200);
                    border-radius:8px;
                    margin-bottom:12px;
                "
            >

                <div>

                    <strong>
                        質問${questionNumber(survey,q)}
                    </strong>

                    <span style="margin-left:5px">
                        ${escapeHtml(q.text)}
                    </span>

                </div>


                ${q.choices.map(
                    choice => {

                        const branch =
                            q.branches?.[choice.id] ||
                            {
                                type:'next'
                            };

                        return `

                            <div class="branch-row">

                                <div>

                                    <strong>
                                        ${escapeHtml(
                                            getChoiceText(choice)
                                        )}
                                    </strong>

                                </div>


                                ${
                                    locked
                                    ?
                                    `
                                        <div>
                                            ${escapeHtml(
                                                branchTargetLabel(
                                                    survey,
                                                    branch
                                                )
                                            )}
                                        </div>
                                    `
                                    :
                                    `
                                        <select
                                            onchange="
                                                setBranch(
                                                    '${escapeHtml(q.id)}',
                                                    '${escapeHtml(choice.id)}',
                                                    this.value
                                                )
                                            "
                                        >

                                            <option
                                                value="next"
                                                ${
                                                    branch.type === 'next'
                                                    ? 'selected'
                                                    : ''
                                                }
                                            >
                                                次の質問
                                            </option>

                                            <option
                                                value="end"
                                                ${
                                                    branch.type === 'end'
                                                    ? 'selected'
                                                    : ''
                                                }
                                            >
                                                回答終了
                                            </option>

                                            <option
                                                value="question"
                                                ${
                                                    branch.type === 'question'
                                                    ? 'selected'
                                                    : ''
                                                }
                                            >
                                                指定した質問
                                            </option>

                                        </select>

                                        ${
                                            branch.type === 'question'
                                            ?
                                            `
                                                <select
                                                    onchange="
                                                        setBranchTarget(
                                                            '${escapeHtml(q.id)}',
                                                            '${escapeHtml(choice.id)}',
                                                            this.value
                                                        )
                                                    "
                                                >

                                                    <option value="">
                                                        質問を選択
                                                    </option>

                                                    ${survey.questions
                                                        .filter(
                                                            target =>
                                                                target.id !== q.id
                                                        )
                                                        .map(
                                                            target =>
                                                                `
                                                                    <option
                                                                        value="${escapeHtml(target.id)}"
                                                                        ${
                                                                            branch.target === target.id
                                                                            ? 'selected'
                                                                            : ''
                                                                        }
                                                                    >
                                                                        質問${questionNumber(survey,target)}：
                                                                        ${escapeHtml(target.text || '未入力')}
                                                                    </option>
                                                                `
                                                        )
                                                        .join('')}

                                                </select>
                                            `
                                            :
                                            ''
                                        }
                                    `
                                }

                            </div>

                        `;

                    }
                ).join('')}

            </div>

        `
    ).join('');

}

function setBranch(
    questionId,
    choiceId,
    type
) {

    const survey =
        currentSurvey();

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

    if (type !== 'question') {
        delete q.branches[choiceId].target;
    }

    survey.updatedAt =
        nowString();

    saveState();

    renderPage();

}

function setBranchTarget(
    questionId,
    choiceId,
    target
) {

    const survey =
        currentSurvey();

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
        type:'question',
        target:target
    };

    survey.updatedAt =
        nowString();

    saveState();

    renderPage();

}


/* =========================================================
   Save Survey
========================================================= */

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


    const name =
        document.getElementById(
            'surveyName'
        )?.value.trim() || '';

    const description =
        document.getElementById(
            'surveyDescription'
        )?.value || '';

    const startAt =
        document.getElementById(
            'surveyStartAt'
        )?.value || '';

    const endAt =
        document.getElementById(
            'surveyEndAt'
        )?.value || '';

    const guidance =
        document.getElementById(
            'surveyGuidance'
        )?.value || '';

    const completeMessage =
        document.getElementById(
            'surveyCompleteMessage'
        )?.value || '';

    const numberMode =
        document.getElementById(
            'numberMode'
        )?.value ||
        survey.numberMode;


    const errors = [];


    if (!name) {

        errors.push(
            'アンケート名を入力してください。'
        );

    }


    if (
        startAt &&
        endAt &&
        new Date(startAt) >= new Date(endAt)
    ) {

        errors.push(
            '回答受付開始日時は終了日時より前にしてください。'
        );

    }


    if (errors.length) {

        showValidationErrors(
            errors,
            'editorErrors'
        );

        return;

    }


    survey.name =
        name;

    survey.description =
        description;

    survey.startAt =
        startAt;

    survey.endAt =
        endAt;

    survey.guidance =
        guidance;

    survey.completeMessage =
        completeMessage;

    survey.numberMode =
        numberMode;

    survey.updatedAt =
        nowString();


    saveState();

    renderPage();

    toast(
        'アンケートを保存しました。'
    );

}


/* =========================================================
   Preview / Validation
========================================================= */

function validateSurvey(survey) {

    const errors = [];

    if (!survey) {

        return {
            errors:['アンケートが見つかりません。']
        };

    }


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


            if (
                (
                    q.type === 'single' ||
                    q.type === 'multiple' ||
                    q.type === 'rating'
                ) &&
                (!q.choices || q.choices.length === 0)
            ) {

                errors.push(
                    `質問${index + 1}には選択肢が必要です。`
                );

            }

        }
    );


    return {
        errors:errors
    };

}

function openPreview(id) {

    setCurrentSurvey(id);

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    navigate('preview');

}

function renderPreview() {

    const survey =
        currentSurvey();

    if (!survey) {
        return '';
    }

    const validation =
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
                            公開する
                        </button>
                    `
                    :
                    ''
                }

            </div>

        </div>


        ${
            validation.errors.length
            ?
            `
                <div class="error-box">

                    <strong>
                        公開前チェックで問題があります。
                    </strong>

                    <ul>

                        ${validation.errors.map(
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
                        <p
                            style="white-space:pre-wrap"
                        >
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

            </div>


            ${renderPreviewQuestions(survey)}


            ${
                survey.completeMessage
                ?
                `
                    <div class="card">

                        <div class="card-body">

                            <strong>
                                回答完了時のメッセージ
                            </strong>

                            <p>
                                ${escapeHtml(
                                    survey.completeMessage
                                )}
                            </p>

                        </div>

                    </div>
                `
                :
                ''
            }

        </div>

    `;

}

function renderPreviewQuestions(survey) {

    return survey.groups.map(
        group => {

            const questions =
                survey.questions.filter(
                    q =>
                        q.groupId === group.id
                );

            if (!questions.length) {
                return '';
            }

            return `

                <div class="card">

                    <div class="card-head">

                        <h3 class="card-title">
                            ${escapeHtml(group.name)}
                        </h3>

                    </div>

                    <div class="card-body">

                        ${questions.map(
                            q =>
                                renderPreviewQuestion(
                                    survey,
                                    q
                                )
                        ).join('')}

                    </div>

                </div>

            `;

        }
    ).join('') +
    renderPreviewUngrouped(survey);

}

function renderPreviewUngrouped(survey) {

    const questions =
        survey.questions.filter(
            q => !q.groupId
        );

    if (!questions.length) {
        return '';
    }

    return `

        <div class="card">

            <div class="card-head">

                <h3 class="card-title">
                    グループ未設定
                </h3>

            </div>

            <div class="card-body">

                ${questions.map(
                    q =>
                        renderPreviewQuestion(
                            survey,
                            q
                        )
                ).join('')}

            </div>

        </div>

    `;

}

function renderPreviewQuestion(
    survey,
    q
) {

    normalizeChoices(q);

    return `

        <div class="answer-question">

            <div class="answer-question-title">

                質問${questionNumber(survey,q)}.
                ${escapeHtml(q.text)}

                ${
                    q.required
                    ?
                    '<span class="required-label">必須</span>'
                    :
                    ''
                }

            </div>


            ${renderLiveAnswerInput(
                q,
                true
            )}


            ${
                q.help
                ?
                `
                    <div class="help">
                        ${escapeHtml(q.help)}
                    </div>
                `
                :
                ''
            }

        </div>

    `;

}


/* =========================================================
   Publish / State Operations
========================================================= */

function publishSurvey(id) {

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) === Number(id)
        );

    if (!survey) {
        return;
    }


    const validation =
        validateSurvey(survey);

    if (validation.errors.length) {

        showValidationErrors(
            validation.errors
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

            <p>
                公開後は質問構造や分岐などの変更が制限されます。
            </p>

            <p>
                回答受付開始：
                <strong>
                    ${
                        formatDate(survey.startAt) ||
                        '未設定（公開後すぐ開始）'
                    }
                </strong>
            </p>
        `,

        '公開する',

        function() {

            const now =
                new Date();

            if (
                survey.endAt &&
                new Date(survey.endAt) <= now
            ) {

                survey.status =
                    'ended';

            } else if (
                survey.startAt &&
                new Date(survey.startAt) > now
            ) {

                survey.status =
                    'wait';

            } else {

                survey.status =
                    'active';

            }


            survey.updatedAt =
                nowString();

            saveState();

            closeModal();

            navigate('surveys');

            toast(
                'アンケートを公開しました。'
            );

        },

        'primary'
    );

}

function startSurvey(id) {

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) === Number(id)
        );

    if (!survey) {
        return;
    }

    showConfirm(

        '回答受付を開始する',

        `
            <p>
                「${escapeHtml(survey.name)}」
                の回答受付を開始します。
            </p>

            <p>
                開始後は回答者が回答できるようになります。
            </p>
        `,

        '回答受付を開始する',

        function() {

            survey.status =
                'active';

            survey.updatedAt =
                nowString();

            saveState();

            closeModal();

            navigate('surveys');

            toast(
                '回答受付を開始しました。'
            );

        },

        'primary'
    );

}

function endSurvey(id) {

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) === Number(id)
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

            <p>
                終了後は新しい回答を受け付けません。
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

            navigate('surveys');

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
            s =>
                Number(s.id) === Number(id)
        );

    if (!survey) {
        return;
    }

    showConfirm(

        'アンケートを保管する',

        `
            <p>
                「${escapeHtml(survey.name)}」
                を保管します。
            </p>

            <p>
                保管後は再公開・再送付できません。
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

            navigate('surveys');

            toast(
                'アンケートを保管しました。'
            );

        },

        'primary'
    );

}

function deleteSurvey(id) {

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) === Number(id)
        );

    if (!survey) {
        return;
    }

    if (survey.status !== 'draft') {

        toast(
            '作成中のアンケートのみ削除できます。'
        );

        return;

    }


    showConfirm(

        'アンケートを削除する',

        `
            <p>
                「${escapeHtml(survey.name || '名称未設定')}」
                を削除します。
            </p>

            <p class="text-danger">
                削除したアンケートは元に戻せません。
            </p>
        `,

        '削除する',

        function() {

            state.surveys =
                state.surveys.filter(
                    s =>
                        Number(s.id) !== Number(id)
                );

            if (
                state.surveys.length &&
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
   Responses
========================================================= */

function openResponses(id) {

    if (id) {
        setCurrentSurvey(id);
    }

    navigate('responses');

}

function renderResponses() {

    const survey =
        currentSurvey();

    if (!survey) {

        return `
            <div class="error-box">
                アンケートが見つかりません。
            </div>
        `;

    }

    const rate =
        responseRate(survey);

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
                    class="btn"
                    onclick="openResponseDetail(${survey.id})"
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
                            ${Number(survey.sentCount || 0)}
                        </div>

                    </div>


                    <div class="kpi">

                        <div class="kpi-label">
                            回答数
                        </div>

                        <div class="kpi-value">
                            ${Number(survey.responseCount || 0)}
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
                            最終送付
                        </div>

                        <div class="kpi-value" style="font-size:16px">
                            ${escapeHtml(
                                survey.lastSentAt || '-'
                            )}
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    回答推移
                </h2>

            </div>

            <div class="card-body">

                ${renderResponseChart(survey)}

            </div>

        </div>

    `;

}

function renderResponseChart(survey) {

    const total =
        Number(survey.responseCount || 0);

    const values = [

        {
            label:'9/10',
            value:Math.round(total * .15)
        },

        {
            label:'9/11',
            value:Math.round(total * .27)
        },

        {
            label:'9/12',
            value:Math.round(total * .55)
        },

        {
            label:'9/13',
            value:Math.round(total * .68)
        },

        {
            label:'9/14',
            value:Math.round(total * .82)
        },

        {
            label:'9/15',
            value:total
        }

    ];

    const max =
        Math.max(
            1,
            ...values.map(x => x.value)
        );

    return `

        <div class="bar-chart">

            ${values.map(
                item => `

                    <div class="bar-column">

                        <div
                            class="bar"
                            style="
                                height:${Math.max(
                                    2,
                                    Math.round(
                                        item.value /
                                        max *
                                        180
                                    )
                                )}px
                            "
                            title="${item.value}件"
                        ></div>

                        <div class="bar-label">
                            ${escapeHtml(item.label)}
                        </div>

                    </div>

                `
            ).join('')}

        </div>

    `;

}


/* =========================================================
   Response Detail
========================================================= */

function openResponseDetail(id) {

    if (id) {
        setCurrentSurvey(id);
    }

    navigate('response-detail');

}

function renderResponseDetail() {

    const survey =
        currentSurvey();

    if (!survey) {
        return '';
    }

    const answers =
        Array.isArray(survey.answers)
        ? survey.answers
        : [];

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

                <div>

                    <strong>
                        回答一覧
                    </strong>

                    <span class="small muted">
                        （${answers.length}件）
                    </span>

                </div>

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

                                        <th>
                                            回答番号
                                        </th>

                                        <th>
                                            回答日時
                                        </th>

                                        <th>
                                            回答者
                                        </th>

                                        <th>
                                            操作
                                        </th>

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
                                                        answer.answeredAt
                                                    )}
                                                </td>

                                                <td>
                                                    ${escapeHtml(
                                                        answer.respondent
                                                    )}
                                                </td>

                                                <td>

                                                    <button
                                                        class="btn btn-sm"
                                                        onclick="
                                                            showAnswerDetail(
                                                                ${answer.id}
                                                            )
                                                        "
                                                    >
                                                        回答を見る
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

function showAnswerDetail(answerId) {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    const answer =
        survey.answers.find(
            a =>
                Number(a.id) === Number(answerId)
        );

    if (!answer) {
        return;
    }

    showModal(

        '回答内容',

        `

            <div class="small muted">
                ${escapeHtml(answer.number)}
                /
                ${escapeHtml(answer.answeredAt)}
                /
                ${escapeHtml(answer.respondent)}
            </div>


            <div style="margin-top:18px">

                ${survey.questions.map(
                    q => {

                        const value =
                            answer.values?.[q.id];

                        return `

                            <div
                                style="
                                    padding:12px 0;
                                    border-bottom:1px solid var(--gray-200);
                                "
                            >

                                <div
                                    style="
                                        font-weight:700;
                                        margin-bottom:6px;
                                    "
                                >
                                    質問${questionNumber(survey,q)}
                                    ：
                                    ${escapeHtml(q.text)}
                                </div>

                                <div style="white-space:pre-wrap">
                                    ${
                                        escapeHtml(
                                            Array.isArray(value)
                                            ? value.join('、')
                                            : value ?? '未回答'
                                        )
                                    }
                                </div>

                            </div>

                        `;

                    }
                ).join('')}

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

}


/* =========================================================
   Send
   ★ 顧客一覧表から直接送付先を選択
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


    /*
     * ★ 送付先をアンケートごとに保持
     */
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
        return '';
    }

    if (!sendDraft) {

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


        <!-- =====================================================
             ★ 送付先
             顧客一覧から直接チェック
        ====================================================== -->

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

                    <table class="recipient-table">

                        <thead>

                            <tr>

                                <th class="recipient-check">
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
                                customer => `

                                    <tr>

                                        <td class="recipient-check">

                                            <input
                                                type="checkbox"
                                                ${
                                                    sendDraft.customerIds.includes(
                                                        customer.id
                                                    )
                                                    ? 'checked'
                                                    : ''
                                                }
                                                onchange="
                                                    toggleCustomer(
                                                        ${customer.id}
                                                    )
                                                "
                                            >

                                        </td>


                                        <td>

                                            <strong>
                                                ${escapeHtml(
                                                    customer.name
                                                )}
                                            </strong>

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


                ${
                    selectedCount === 0
                    ?
                    `
                        <div
                            class="info-box"
                            style="margin-top:15px;margin-bottom:0"
                        >
                            送付先が選択されていません。
                        </div>
                    `
                    :
                    `
                        <div
                            class="success-box"
                            style="margin-top:15px;margin-bottom:0"
                        >
                            ${selectedCount}件の顧客が
                            送付対象として選択されています。
                        </div>
                    `
                }

            </div>

        </div>


        <!-- =====================================================
             Mail
        ====================================================== -->

        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    メール内容
                </h2>

            </div>


            <div class="card-body">

                <div class="form-group">

                    <label class="form-label">
                        件名
                    </label>

                    <input
                        id="sendSubject"
                        type="text"
                        value="${escapeHtml(
                            sendDraft.subject
                        )}"
                        onchange="updateSendDraft()"
                    >

                </div>


                <div class="form-group">

                    <label class="form-label">
                        本文
                    </label>

                    <textarea
                        id="sendBody"
                        style="min-height:220px"
                        onchange="updateSendDraft()"
                    >${escapeHtml(
                        sendDraft.body
                    )}</textarea>

                </div>


                <div class="actions">

                    <button
                        class="btn btn-primary"
                        onclick="openSendConfirm()"
                    >
                        送付内容を確認する
                    </button>

                </div>

            </div>

        </div>

    `;

}

function updateSendDraft() {

    if (!sendDraft) {
        return;
    }

    const subject =
        document.getElementById(
            'sendSubject'
        );

    const body =
        document.getElementById(
            'sendBody'
        );

    if (subject) {
        sendDraft.subject =
            subject.value;
    }

    if (body) {
        sendDraft.body =
            body.value;
    }

}

function toggleCustomer(id) {

    if (!sendDraft) {
        return;
    }

    const index =
        sendDraft.customerIds.indexOf(
            id
        );

    if (index >= 0) {

        sendDraft.customerIds.splice(
            index,
            1
        );

    } else {

        sendDraft.customerIds.push(
            id
        );

    }

    renderPage();

}

function selectAllCustomers() {

    if (!sendDraft) {
        return;
    }

    sendDraft.customerIds =
        state.customers.map(
            customer => customer.id
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


/* =========================================================
   Send Confirm
========================================================= */

function openSendConfirm() {

    updateSendDraft();

    if (!sendDraft) {
        return;
    }


    if (
        !sendDraft.customerIds.length
    ) {

        toast(
            '送付先を1件以上選択してください。'
        );

        return;

    }


    if (
        !String(
            sendDraft.subject || ''
        ).trim()
    ) {

        toast(
            'メール件名を入力してください。'
        );

        return;

    }


    if (
        !String(
            sendDraft.body || ''
        ).trim()
    ) {

        toast(
            'メール本文を入力してください。'
        );

        return;

    }


    navigate(
        'send-confirm'
    );

}

function renderSendConfirm() {

    const survey =
        currentSurvey();

    if (!survey || !sendDraft) {
        return '';
    }


    const selected =
        state.customers.filter(
            customer =>
                sendDraft.customerIds.includes(
                    customer.id
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
                    送付画面へ戻る
                </button>

                <button
                    class="btn btn-primary"
                    onclick="executeSend()"
                >
                    送付を実行する
                </button>

            </div>

        </div>


        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    アンケート
                </h2>
            </div>

            <div class="card-body">

                <strong>
                    ${escapeHtml(survey.name)}
                </strong>

                <div style="margin-top:8px">
                    ${statusBadge(survey.status)}
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


        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    メール内容
                </h2>

            </div>

            <div class="card-body">

                <div class="form-group">

                    <div class="small muted">
                        件名
                    </div>

                    <div
                        style="
                            margin-top:6px;
                            font-weight:700;
                        "
                    >
                        ${escapeHtml(
                            sendDraft.subject
                        )}
                    </div>

                </div>


                <div class="form-group">

                    <div class="small muted">
                        本文
                    </div>

                    <div
                        style="
                            white-space:pre-wrap;
                            border:1px solid var(--gray-200);
                            padding:14px;
                            border-radius:7px;
                            margin-top:6px;
                        "
                    >
                        ${escapeHtml(
                            sendDraft.body
                        )}
                    </div>

                </div>


                <div class="info-box">

                    モックでは実際のメール送信は行いません。

                </div>

            </div>

        </div>

    `;

}

function executeSend() {

    const survey =
        currentSurvey();

    if (!survey || !sendDraft) {
        return;
    }


    const target =
        sendDraft.customerIds.length;


    /*
     * モックとして
     * メールアドレスのない顧客を失敗扱い
     */
    const failedCustomers = [];

    state.customers.forEach(
        customer => {

            if (
                sendDraft.customerIds.includes(
                    customer.id
                ) &&
                !String(customer.email || '').trim()
            ) {

                failedCustomers.push(
                    `メールアドレス不備：${customer.name}`
                );

            }

        }
    );


    const failed =
        failedCustomers.length;

    const success =
        target - failed;


    survey.selectedCustomerIds =
        [...sendDraft.customerIds];

    survey.sentCount =
        Number(survey.sentCount || 0) +
        success;

    survey.lastSentAt =
        nowString();

    survey.updatedAt =
        nowString();


    const result = {

        id:
            Date.now(),

        surveyId:
            survey.id,

        target:
            target,

        success:
            success,

        failed:
            failed,

        sentAt:
            nowString(),

        failedCustomers:
            failedCustomers

    };


    state.sendResults.unshift(
        result
    );


    saveState();

    sendDraft = null;

    navigate(
        'send-result'
    );

    toast(
        'アンケート送付を実行しました。'
    );

}


/* =========================================================
   Send Result
========================================================= */

function renderSendResult() {

    const result =
        state.sendResults[0];

    if (!result) {

        return `

            <div class="card">

                <div class="card-body">

                    <div class="muted">
                        送付結果はありません。
                    </div>

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
                    ${escapeHtml(
                        survey?.name || ''
                    )}
                </p>

            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    最新の送付結果
                </h2>

                <span class="small muted">
                    ${escapeHtml(result.sentAt)}
                </span>

            </div>


            <div class="card-body">

                <div class="kpi-grid">

                    <div class="kpi">

                        <div class="kpi-label">
                            送付対象
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


                    <div class="kpi">

                        <div class="kpi-label">
                            成功率
                        </div>

                        <div class="kpi-value">

                            ${
                                result.target
                                ?
                                Math.round(
                                    result.success /
                                    result.target *
                                    100
                                ) + '%'
                                :
                                '-'
                            }

                        </div>

                    </div>

                </div>


                ${
                    result.failedCustomers?.length
                    ?
                    `
                        <div
                            class="error-box"
                            style="margin-top:18px"
                        >

                            <strong>
                                送付できなかった顧客
                            </strong>

                            <ul>

                                ${result.failedCustomers.map(
                                    item =>
                                        `<li>${escapeHtml(item)}</li>`
                                ).join('')}

                            </ul>

                        </div>
                    `
                    :
                    `
                        <div
                            class="success-box"
                            style="margin-top:18px"
                        >
                            すべての送付対象への送付に成功しました。
                        </div>
                    `
                }

            </div>

        </div>

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
                    kintoneとメール送信のモック設定を確認・変更できます。
                </p>

            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <div>

                    <h2 class="card-title">
                        kintone設定
                    </h2>

                    <div class="small muted">
                        顧客一覧の取得元
                    </div>

                </div>

                <div>

                    ${
                        k.connected
                        ?
                        '<span class="status status-active">接続済み</span>'
                        :
                        '<span class="status status-ended">未接続</span>'
                    }

                </div>

            </div>


            <div class="card-body">

                <div class="form-grid">


                    <div class="form-group">

                        <label class="form-label">
                            接続先
                        </label>

                        <input
                            id="kHost"
                            type="text"
                            value="${escapeHtml(k.host)}"
                        >

                    </div>


                    <div class="form-group">

                        <label class="form-label">
                            対象アプリ
                        </label>

                        <input
                            id="kApp"
                            type="text"
                            value="${escapeHtml(k.app)}"
                        >

                    </div>


                    <div class="form-group">

                        <label class="form-label">
                            顧客名として利用する項目
                        </label>

                        <input
                            id="kName"
                            type="text"
                            value="${escapeHtml(k.nameField)}"
                        >

                    </div>


                    <div class="form-group">

                        <label class="form-label">
                            担当者名として利用する項目
                        </label>

                        <input
                            id="kContact"
                            type="text"
                            value="${escapeHtml(k.contactField)}"
                        >

                    </div>


                    <div class="form-group">

                        <label class="form-label">
                            メールアドレスとして利用する項目
                        </label>

                        <input
                            id="kEmail"
                            type="text"
                            value="${escapeHtml(k.emailField)}"
                        >

                    </div>

                </div>


                <div class="actions">

                    <button
                        class="btn"
                        onclick="saveKintone()"
                    >
                        設定を保存する
                    </button>

                    <button
                        class="btn btn-primary"
                        onclick="testKintone()"
                    >
                        接続確認
                    </button>

                    <button
                        class="btn"
                        onclick="refreshCustomers()"
                    >
                        顧客一覧を更新する
                    </button>

                </div>


                <div class="small muted" style="margin-top:10px">
                    最終更新：
                    ${escapeHtml(
                        k.updatedAt || '-'
                    )}
                </div>

            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <div>

                    <h2 class="card-title">
                        メール設定
                    </h2>

                    <div class="small muted">
                        SMTP送信設定
                    </div>

                </div>

                <div>

                    ${
                        smtp.configured
                        ?
                        '<span class="status status-active">設定済み</span>'
                        :
                        '<span class="status status-ended">未設定</span>'
                    }

                </div>

            </div>


            <div class="card-body">

                <div class="form-grid">


                    <div class="form-group">

                        <label class="form-label">
                            SMTPサーバー
                        </label>

                        <input
                            id="smtpHost"
                            type="text"
                            value="${escapeHtml(smtp.host)}"
                        >

                    </div>


                    <div class="form-group">

                        <label class="form-label">
                            ポート
                        </label>

                        <input
                            id="smtpPort"
                            type="number"
                            value="${escapeHtml(smtp.port)}"
                        >

                    </div>


                    <div class="form-group">

                        <label class="form-label">
                            送信元メールアドレス
                        </label>

                        <input
                            id="smtpFrom"
                            type="email"
                            value="${escapeHtml(smtp.from)}"
                        >

                    </div>


                    <div class="form-group">

                        <label class="form-label">
                            暗号化方式
                        </label>

                        <select id="smtpEncryption">

                            <option
                                value="なし"
                                ${
                                    smtp.encryption === 'なし'
                                    ? 'selected'
                                    : ''
                                }
                            >
                                なし
                            </option>

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


                <div class="actions">

                    <button
                        class="btn"
                        onclick="saveSmtp()"
                    >
                        設定を保存する
                    </button>

                    <button
                        class="btn btn-primary"
                        onclick="testSmtp()"
                    >
                        設定確認
                    </button>

                </div>

            </div>

        </div>

    `;

}

function saveKintone() {

    const k =
        state.settings.kintone;

    k.host =
        document.getElementById(
            'kHost'
        ).value;

    k.app =
        document.getElementById(
            'kApp'
        ).value;

    k.nameField =
        document.getElementById(
            'kName'
        ).value;

    k.contactField =
        document.getElementById(
            'kContact'
        ).value;

    k.emailField =
        document.getElementById(
            'kEmail'
        ).value;

    k.updatedAt =
        nowString();

    saveState();

    toast(
        'kintone設定を保存しました。'
    );

}

function testKintone() {

    state.settings.kintone.connected =
        true;

    state.settings.kintone.updatedAt =
        nowString();

    saveState();

    showModal(

        'kintone接続確認',

        `
            <div class="success-box">
                接続確認に成功しました。
            </div>

            <p class="small muted">
                ※モックのため実際のkintoneには接続していません。
            </p>
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

function refreshCustomers() {

    state.settings.kintone.updatedAt =
        nowString();

    saveState();

    toast(
        '顧客一覧を更新しました。（モック）'
    );

}

function saveSmtp() {

    const smtp =
        state.settings.smtp;

    smtp.host =
        document.getElementById(
            'smtpHost'
        ).value;

    smtp.port =
        document.getElementById(
            'smtpPort'
        ).value;

    smtp.from =
        document.getElementById(
            'smtpFrom'
        ).value;

    smtp.encryption =
        document.getElementById(
            'smtpEncryption'
        ).value;

    smtp.configured =
        true;

    saveState();

    toast(
        'メール設定を保存しました。'
    );

}

function testSmtp() {

    state.settings.smtp.configured =
        true;

    saveState();

    showModal(

        'SMTP設定確認',

        `
            <div class="success-box">
                SMTP設定の確認に成功しました。
            </div>

            <p class="small muted">
                ※モックのため実際のSMTPサーバーには接続していません。
            </p>
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


/* =========================================================
   Answerer
========================================================= */

function startAnswer(id) {

    if (id) {
        setCurrentSurvey(id);
    }

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }


    syncSurveyStatuses();


    if (survey.status !== 'active') {

        showModal(

            '回答できません',

            `
                <div class="error-box">

                    現在のアンケート状態は
                    「${escapeHtml(
                        statusLabel(
                            survey.status
                        )
                    )}」
                    です。

                    回答受付中のアンケートのみ回答できます。

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


    answerState = {

        surveyId:
            survey.id,

        currentIndex:
            0,

        values:{},

        visibleQuestions:
            calculateVisibleQuestions(
                survey,
                {}
            )

    };


    navigate('answer');

}

function calculateVisibleQuestions(
    survey,
    values
) {

    const result = [];

    let index = 0;

    let guard = 0;


    while (
        index < survey.questions.length &&
        guard < 100
    ) {

        guard++;


        const q =
            survey.questions[index];


        if (
            !result.some(
                x => x.id === q.id
            )
        ) {

            result.push(q);

        }


        if (q.type !== 'single') {

            index++;

            continue;

        }


        const value =
            values[q.id];


        if (!value) {

            index++;

            continue;

        }


        const choice =
            q.choices.find(
                c =>
                    getChoiceText(c) === value
            );


        const branch =
            choice
            ? q.branches?.[choice.id]
            : null;


        if (
            !branch ||
            branch.type === 'next'
        ) {

            index++;

            continue;

        }


        if (branch.type === 'end') {

            break;

        }


        if (branch.type === 'question') {

            const targetIndex =
                survey.questions.findIndex(
                    x =>
                        x.id ===
                        branch.target
                );

            if (targetIndex < 0) {

                index++;

            } else {

                index =
                    targetIndex;

            }

            continue;

        }


        if (branch.type === 'group') {

            const targetIndex =
                survey.questions.findIndex(
                    x =>
                        x.groupId ===
                        branch.target
                );

            if (targetIndex < 0) {

                index++;

            } else {

                index =
                    targetIndex;

            }

            continue;

        }


        index++;

    }


    return result;

}

function renderAnswer() {

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) ===
                Number(answerState.surveyId)
        );

    if (!survey) {

        return `
            <div class="error-box">
                回答対象のアンケートが見つかりません。
            </div>
        `;

    }


    answerState.visibleQuestions =
        calculateVisibleQuestions(
            survey,
            answerState.values
        );


    const q =
        answerState.visibleQuestions[
            answerState.currentIndex
        ];


    if (!q) {

        navigate('answer-confirm');

        return '';

    }


    const total =
        answerState.visibleQuestions.length;

    const current =
        answerState.currentIndex + 1;

    const progress =
        Math.round(
            (
                answerState.currentIndex /
                Math.max(1,total)
            ) *
            100
        );


    return `

        <div class="preview-shell">


            <div class="preview-header">

                <div class="small muted">
                    回答者向けアンケート
                </div>

                <h1 class="page-title">
                    ${escapeHtml(
                        survey.name
                    )}
                </h1>


                ${
                    survey.description
                    ?
                    `
                        <p>
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


                <div class="answer-progress">

                    <div
                        style="
                            display:flex;
                            justify-content:space-between;
                            margin-bottom:6px;
                        "
                    >

                        <span class="small">
                            回答進捗
                        </span>

                        <span class="small">
                            ${current} / ${total}
                        </span>

                    </div>


                    <div class="progress-track">

                        <div
                            class="progress-bar"
                            style="
                                width:${progress}%
                            "
                        ></div>

                    </div>

                </div>

            </div>


            <div class="answer-question">

                <div class="answer-question-title">

                    質問${questionNumber(survey,q)}.
                    ${escapeHtml(q.text)}

                    ${
                        q.required
                        ?
                        '<span class="required-label">必須</span>'
                        :
                        ''
                    }

                </div>


                ${renderLiveAnswerInput(q)}


                ${
                    q.help
                    ?
                    `
                        <div class="help">
                            ${escapeHtml(q.help)}
                        </div>
                    `
                    :
                    ''
                }

            </div>


            <div id="answerError"></div>


            <div class="answer-footer">

                <div>

                    ${
                        answerState.currentIndex > 0
                        ?
                        `
                            <button
                                class="btn"
                                onclick="answerBack()"
                            >
                                前へ戻る
                            </button>
                        `
                        :
                        ''
                    }

                </div>


                <div>

                    ${
                        answerState.currentIndex <
                        total - 1
                        ?
                        `
                            <button
                                class="btn btn-primary"
                                onclick="answerNext()"
                            >
                                次へ進む
                            </button>
                        `
                        :
                        `
                            <button
                                class="btn btn-primary"
                                onclick="goAnswerConfirm()"
                            >
                                回答を確認する
                            </button>
                        `
                    }

                </div>

            </div>

        </div>

    `;

}

function renderLiveAnswerInput(
    q,
    preview = false
) {

    const value =
        answerState.values[q.id];


    switch(q.type) {

        case 'text':

            return `

                <textarea
                    ${
                        preview
                        ? 'disabled'
                        : ''
                    }
                    id="answer_${escapeHtml(q.id)}"
                    ${
                        preview
                        ? ''
                        : `
                            onchange="
                                saveAnswerValue(
                                    '${escapeHtml(q.id)}',
                                    this.value
                                )
                            "

                            oninput="
                                saveAnswerValue(
                                    '${escapeHtml(q.id)}',
                                    this.value
                                )
                            "
                        `
                    }
                >${escapeHtml(
                    value || ''
                )}</textarea>

            `;


        case 'single':

            return q.choices.map(
                choice => {

                    const text =
                        getChoiceText(choice);

                    return `

                        <label class="option">

                            <input
                                type="radio"
                                name="answer_${escapeHtml(q.id)}"
                                value="${escapeHtml(text)}"
                                ${
                                    value === text
                                    ? 'checked'
                                    : ''
                                }
                                ${
                                    preview
                                    ? 'disabled'
                                    : `
                                        onchange="
                                            saveAnswerValue(
                                                '${escapeHtml(q.id)}',
                                                this.value
                                            )
                                        "
                                    `
                                }
                            >

                            <span>
                                ${escapeHtml(text)}
                            </span>

                        </label>

                    `;

                }
            ).join('');


        case 'multiple': {

            const selected =
                Array.isArray(value)
                ? value
                : [];

            return q.choices.map(
                choice => {

                    const text =
                        getChoiceText(choice);

                    return `

                        <label class="option">

                            <input
                                type="checkbox"
                                value="${escapeHtml(text)}"
                                ${
                                    selected.includes(text)
                                    ? 'checked'
                                    : ''
                                }
                                ${
                                    preview
                                    ? 'disabled'
                                    : `
                                        onchange="
                                            toggleAnswerMultiple(
                                                '${escapeHtml(q.id)}',
                                                this.value,
                                                this.checked
                                            )
                                        "
                                    `
                                }
                            >

                            <span>
                                ${escapeHtml(text)}
                            </span>

                        </label>

                    `;

                }
            ).join('');

        }


        case 'rating':

            return `

                <div
                    style="
                        display:flex;
                        gap:8px;
                        flex-wrap:wrap;
                    "
                >

                    ${q.choices.map(
                        choice => {

                            const text =
                                getChoiceText(choice);

                            return `

                                <label
                                    style="
                                        border:1px solid var(--gray-300);
                                        padding:10px 15px;
                                        border-radius:7px;
                                        cursor:pointer;
                                    "
                                >

                                    <input
                                        type="radio"
                                        name="answer_${escapeHtml(q.id)}"
                                        value="${escapeHtml(text)}"
                                        ${
                                            value === text
                                            ? 'checked'
                                            : ''
                                        }
                                        ${
                                            preview
                                            ? 'disabled'
                                            : `
                                                onchange="
                                                    saveAnswerValue(
                                                        '${escapeHtml(q.id)}',
                                                        this.value
                                                    )
                                                "
                                            `
                                        }
                                    >

                                    ${escapeHtml(text)}

                                </label>

                            `;

                        }
                    ).join('')}

                </div>

            `;


        default:
            return '';

    }

}

function saveAnswerValue(
    questionId,
    value
) {

    answerState.values[
        questionId
    ] = value;

}

function toggleAnswerMultiple(
    questionId,
    value,
    checked
) {

    let current =
        Array.isArray(
            answerState.values[
                questionId
            ]
        )
        ?
        answerState.values[
            questionId
        ]
        :
        [];


    if (checked) {

        if (!current.includes(value)) {
            current.push(value);
        }

    } else {

        current =
            current.filter(
                v => v !== value
            );

    }


    answerState.values[
        questionId
    ] = current;

}

function validateAnswerQuestion(q) {

    if (!q.required) {
        return true;
    }

    const value =
        answerState.values[q.id];


    if (q.type === 'multiple') {

        return (
            Array.isArray(value) &&
            value.length > 0
        );

    }


    return (
        value !== undefined &&
        value !== null &&
        String(value).trim() !== ''
    );

}

function answerNext() {

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) ===
                Number(answerState.surveyId)
        );

    const q =
        answerState.visibleQuestions[
            answerState.currentIndex
        ];

    if (!validateAnswerQuestion(q)) {

        const error =
            document.getElementById(
                'answerError'
            );

        if (error) {

            error.innerHTML = `

                <div class="error-box">

                    この質問は必須です。
                    回答を入力してから次へ進んでください。

                </div>

            `;

        }

        return;

    }


    answerState.visibleQuestions =
        calculateVisibleQuestions(
            survey,
            answerState.values
        );


    answerState.currentIndex++;


    if (
        answerState.currentIndex >=
        answerState.visibleQuestions.length
    ) {

        navigate(
            'answer-confirm'
        );

    } else {

        renderPage();

    }

}

function answerBack() {

    if (
        answerState.currentIndex > 0
    ) {

        answerState.currentIndex--;

        renderPage();

    }

}

function goAnswerConfirm() {

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) ===
                Number(answerState.surveyId)
        );

    if (!survey) {
        return;
    }


    answerState.visibleQuestions =
        calculateVisibleQuestions(
            survey,
            answerState.values
        );


    const errors =
        answerState.visibleQuestions.filter(
            q =>
                !validateAnswerQuestion(q)
        );


    if (errors.length) {

        showModal(

            '未回答の質問があります',

            `
                <div class="error-box">

                    以下の必須質問に回答してください。

                    <ul>

                        ${errors.map(
                            q =>
                                `
                                    <li>
                                        質問${questionNumber(survey,q)}：
                                        ${escapeHtml(q.text)}
                                    </li>
                                `
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


    navigate(
        'answer-confirm'
    );

}

function renderAnswerConfirm() {

    const survey =
        state.surveys.find(
            s =>
                Number(s.id) ===
                Number(answerState.surveyId)
        );

    if (!survey) {
        return '';
    }


    answerState.visibleQuestions =
        calculateVisibleQuestions(
            survey,
            answerState.values
        );


    return `

        <div class="preview-shell">

            <div class="preview-header">

                <div class="small muted">
                    回答確認
                </div>

                <h1 class="page-title">
                    ${escapeHtml(
                        survey.name
                    )}
                </h1>

                <p>
                    入力した回答を確認してください。
                </p>

            </div>


            ${answerState.visibleQuestions.map(
                q => {

                    const value =
                        answerState.values[q.id];

                    return `

                        <div class="answer-question">

                            <div class="small muted">
                                質問${questionNumber(survey,q)}
                            </div>

                            <div class="answer-question-title">
                                ${escapeHtml(q.text)}
                            </div>

                            <div
                                style="white-space:pre-wrap"
                            >
                                ${escapeHtml(
                                    Array.isArray(value)
                                    ? value.join('、')
                                    : value
                                )}
                            </div>

                        </div>

                    `;

                }
            ).join('')}


            <div class="answer-footer">

                <button
                    class="btn"
                    onclick="
                        answerState.currentIndex=0;
                        navigate('answer')
                    "
                >
                    回答を修正する
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
        state.surveys.find(
            s =>
                Number(s.id) ===
                Number(answerState.surveyId)
        );

    if (!survey) {
        return;
    }


    showConfirm(

        '回答を送信する',

        `
            <p>
                入力した回答を送信します。
            </p>

            <p>
                送信後は回答内容を変更できません。
            </p>
        `,

        '回答を送信する',

        function() {

            if (!Array.isArray(survey.answers)) {
                survey.answers = [];
            }

            const nextId =
                survey.answers.length + 1;


            survey.answers.push({

                id:
                    Date.now(),

                number:
                    'R-' +
                    String(nextId)
                    .padStart(4,'0'),

                answeredAt:
                    nowString(),

                respondent:
                    'モック回答者',

                values:
                    clone(
                        answerState.values
                    )

            });


            survey.responseCount =
                Number(
                    survey.responseCount || 0
                ) + 1;

            survey.updatedAt =
                nowString();


            saveState();

            closeModal();

            navigate(
                'answer-complete'
            );

        },

        'primary'
    );

}

function renderAnswerComplete() {

    const survey =
        currentSurvey();

    return `

        <div class="preview-shell">

            <div
                class="card"
                style="margin-top:60px"
            >

                <div
                    class="card-body"
                    style="
                        text-align:center;
                        padding:50px 25px;
                    "
                >

                    <div
                        style="
                            width:70px;
                            height:70px;
                            border-radius:50%;
                            background:#dcfce7;
                            color:#15803d;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            margin:0 auto 20px;
                            font-size:36px;
                        "
                    >
                        ✓
                    </div>


                    <h1 style="margin:0 0 12px">
                        回答が完了しました
                    </h1>


                    <p>
                        ${escapeHtml(
                            survey?.completeMessage ||
                            'ご回答ありがとうございました。'
                        )}
                    </p>


                    <div style="margin-top:25px">

                        <button
                            class="btn"
                            onclick="navigate('home')"
                        >
                            管理画面へ戻る
                        </button>

                    </div>

                </div>

            </div>

        </div>

    `;

}


/* =========================================================
   Validation / Modal / Toast
========================================================= */

function showValidationErrors(
    errors,
    targetId = null
) {

    const html = `

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


    if (
        targetId &&
        document.getElementById(targetId)
    ) {

        document.getElementById(
            targetId
        ).innerHTML =
            html;

        document.getElementById(
            targetId
        ).scrollIntoView({
            behavior:'smooth',
            block:'center'
        });

        return;

    }


    showModal(

        '入力内容の確認',

        html,

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

function showModal(
    title,
    body,
    footer
) {

    document.getElementById(
        'modalTitle'
    ).innerHTML =
        title;

    document.getElementById(
        'modalBody'
    ).innerHTML =
        body;

    document.getElementById(
        'modalFooter'
    ).innerHTML =
        footer || '';

    document.getElementById(
        'modalBackdrop'
    ).classList.add('show');

}

function showConfirm(
    title,
    body,
    confirmLabel,
    onConfirm,
    kind='primary'
) {

    const buttonClass =
        kind === 'danger'
        ?
        'btn-danger'
        :
        kind === 'warning'
        ?
        'btn-warning'
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
                onclick="
                    window.__modalConfirm &&
                    window.__modalConfirm()
                "
            >
                ${escapeHtml(
                    confirmLabel
                )}
            </button>

        `

    );


    window.__modalConfirm =
        function() {

            window.__modalConfirm =
                null;

            onConfirm();

        };

}

function closeModal() {

    document.getElementById(
        'modalBackdrop'
    ).classList.remove('show');

    window.__modalConfirm =
        null;

    window.editingQuestionId =
        null;

}

function toast(message) {

    const el =
        document.getElementById(
            'toast'
        );

    el.textContent =
        message;

    el.classList.add(
        'show'
    );

    clearTimeout(
        window.__toastTimer
    );

    window.__toastTimer =
        setTimeout(
            () => {

                el.classList.remove(
                    'show'
                );

            },
            2500
        );

}


/* =========================================================
   Initialization
========================================================= */

document
    .getElementById(
        'modalBackdrop'
    )
    .addEventListener(
        'click',
        function(event) {

            if (
                event.target ===
                this
            ) {

                closeModal();

            }

        }
    );


if (
    !state.currentSurveyId &&
    state.surveys.length
) {

    state.currentSurveyId =
        state.surveys[0].id;

}


saveState();

navigate(
    state.currentPage ||
    'home'
);


/*
 * 1分ごとに日時ベースの状態を同期
 */
setInterval(
    function() {

        const before =
            JSON.stringify(
                state.surveys.map(
                    s => ({
                        id:s.id,
                        status:s.status,
                        startAt:s.startAt,
                        endAt:s.endAt
                    })
                )
            );

        syncSurveyStatuses();

        const after =
            JSON.stringify(
                state.surveys.map(
                    s => ({
                        id:s.id,
                        status:s.status,
                        startAt:s.startAt,
                        endAt:s.endAt
                    })
                )
            );

        if (
            before !== after
        ) {

            if (
                state.currentPage === 'home' ||
                state.currentPage === 'surveys'
            ) {

                renderPage();

            }

        }

    },
    60000
);

</script>

</body>
</html>
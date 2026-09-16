<?php
/*
 * ============================================================
 * アンケート管理アプリ モック
 * ============================================================
 *
 * Apache + PHP / 1ファイル構成
 *
 * - DB接続なし
 * - kintone接続なし
 * - SMTP接続なし
 * - HTML / CSS / JavaScript を本ファイルに同梱
 *
 * localStorage が利用できない sandbox iframe 環境では
 * JavaScript のメモリ上に状態を保持する。
 *
 * ============================================================
 */

declare(strict_types=1);

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
    opacity:.5;
}

a {
    color:inherit;
    text-decoration:none;
}

/* ============================================================
   Layout
============================================================ */

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
    min-height:68px;
    display:flex;
    align-items:center;
    padding:12px 22px;
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

/* ============================================================
   Common
============================================================ */

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

/* ============================================================
   Status
============================================================ */

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

/* ============================================================
   Dashboard
============================================================ */

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

/* ============================================================
   Tables
============================================================ */

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

.actions-cell {
    white-space:nowrap;
}

.actions-cell .btn {
    margin:2px;
}

/* ============================================================
   Forms
============================================================ */

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

.required {
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

.readonly-field {
    background:var(--gray-100)!important;
    color:var(--gray-500)!important;
}

.help {
    margin-top:5px;
    font-size:12px;
    color:var(--gray-500);
}

.error-box,
.success-box,
.info-box {
    border-radius:8px;
    padding:12px 14px;
    margin-bottom:15px;
}

.error-box {
    border:1px solid #fecaca;
    background:#fef2f2;
    color:#991b1b;
}

.success-box {
    border:1px solid #bbf7d0;
    background:#f0fdf4;
    color:#166534;
}

.info-box {
    border:1px solid #bae6fd;
    background:#f0f9ff;
    color:#075985;
}

.error-box ul {
    margin:7px 0 0 18px;
    padding:0;
}

/* ============================================================
   Editor
============================================================ */

.editor-layout {
    display:grid;
    grid-template-columns:280px minmax(0,1fr);
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
    min-height:30px;
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

.branch-row {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
    margin-top:8px;
    padding:9px;
    background:var(--gray-50);
    border-radius:7px;
}

/* ============================================================
   Preview / Answer
============================================================ */

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

/* ============================================================
   KPI
============================================================ */

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

/* ============================================================
   Modal
============================================================ */

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
    width:min(720px,100%);
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

/* ============================================================
   Toast
============================================================ */

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

/* ============================================================
   Responsive
============================================================ */

@media(max-width:1100px) {
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

@media(max-width:800px) {
    .sidebar {
        width:68px;
    }

    .logo {
        padding:0;
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
}

@media(max-width:500px) {
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
        <div>
            アンケート管理
            <small>Questionnaire Management</small>
        </div>
    </div>

    <nav class="nav">

        <div class="nav-section">管理</div>

        <button type="button" data-action="navigate" data-page="home">
            <span class="icon">⌂</span>
            <span>ホーム</span>
        </button>

        <button type="button" data-action="navigate" data-page="surveys">
            <span class="icon">▤</span>
            <span>アンケート一覧</span>
        </button>

        <button type="button" data-action="new-survey">
            <span class="icon">＋</span>
            <span>新規作成</span>
        </button>

        <div class="nav-section">回答</div>

        <button type="button" data-action="navigate" data-page="responses">
            <span class="icon">▥</span>
            <span>回答状況</span>
        </button>

        <button type="button" data-action="response-detail">
            <span class="icon">☷</span>
            <span>回答内容</span>
        </button>

        <div class="nav-section">送付</div>

        <button type="button" data-action="open-send">
            <span class="icon">✉</span>
            <span>アンケート送付</span>
        </button>

        <button type="button" data-action="navigate" data-page="send-result">
            <span class="icon">✓</span>
            <span>送付結果</span>
        </button>

        <div class="nav-section">設定</div>

        <button type="button" data-action="navigate" data-page="settings">
            <span class="icon">⚙</span>
            <span>各種設定</span>
        </button>

        <div class="nav-section">回答者確認</div>

        <button type="button" data-action="start-answer">
            <span class="icon">▣</span>
            <span>回答画面を確認</span>
        </button>

        <div class="nav-section">モック</div>

        <button type="button" data-action="reset">
            <span class="icon">↻</span>
            <span>データ初期化</span>
        </button>

    </nav>
</aside>


<main class="main">

    <header class="topbar">
        <div id="topbarTitle" class="topbar-title">
            ホーム
        </div>

        <div class="user">
            モック管理者
        </div>
    </header>

    <main id="appContent" class="content"></main>

</main>

</div>


<!-- Modal -->

<div
    id="modalBackdrop"
    class="modal-backdrop"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
>

    <div class="modal">

        <div class="modal-head">

            <strong id="modalTitle">
                確認
            </strong>

            <button
                type="button"
                class="modal-close"
                data-action="close-modal"
                aria-label="閉じる"
            >
                ×
            </button>

        </div>

        <div id="modalBody" class="modal-body"></div>

        <div id="modalFooter" class="modal-footer"></div>

    </div>

</div>


<div id="toast" class="toast"></div>


<script>
'use strict';

/* ============================================================
   1. Default Data
   ============================================================ */

const defaultData = {

    currentPage: 'home',

    currentSurveyId: 1,

    settings: {

        kintone: {
            host: 'https://example.cybozu.com',
            app: '100',
            nameField: 'customer_name',
            contactField: 'contact_name',
            emailField: 'email',
            connected: true,
            updatedAt: '2026-09-15 10:00'
        },

        smtp: {
            host: 'smtp.example.com',
            port: '587',
            from: 'survey@example.com',
            encryption: 'STARTTLS',
            configured: true,
            updatedAt: '2026-09-15 10:00'
        }

    },

    customers: [

        {
            id:1,
            name:'株式会社赤坂商事',
            contact:'山田 太郎',
            email:'taro.yamada@example.com'
        },

        {
            id:2,
            name:'港区ソリューションズ',
            contact:'佐藤 花子',
            email:'hanako.sato@example.com'
        },

        {
            id:3,
            name:'株式会社青山商事',
            contact:'鈴木 一郎',
            email:'ichiro.suzuki@example.com'
        },

        {
            id:4,
            name:'六本木サービス株式会社',
            contact:'田中 美咲',
            email:'misaki.tanaka@example.com'
        },

        {
            id:5,
            name:'麻布テクノロジー株式会社',
            contact:'高橋 健',
            email:'ken.takahashi@example.com'
        },

        {
            id:6,
            name:'株式会社虎ノ門企画',
            contact:'伊藤 真由',
            email:'mayu.ito@example.com'
        }

    ],

    surveys: [

        {
            id:1,
            name:'2026年度 顧客満足度アンケート',
            description:'2026年度のサービス満足度を確認するアンケートです。',
            guidance:'ご多忙のところ恐れ入りますが、アンケートへのご協力をお願いいたします。',
            completeMessage:'ご回答ありがとうございました。',
            status:'active',
            createdAt:'2026-08-01',
            updatedAt:'2026-09-10 10:00',
            startAt:'2026-09-01T09:00',
            endAt:'2026-10-31T18:00',
            numberMode:'global',
            sentCount:80,
            responseCount:52,
            selectedCustomerIds:[1,2,3,4,5],
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
                    text:'ご利用いただいているサービス名を教えてください。',
                    type:'text',
                    required:true,
                    help:'',
                    choices:[],
                    branches:{}
                },
                {
                    id:'q2',
                    groupId:'g2',
                    text:'サービス全体の満足度を教えてください。',
                    type:'rating',
                    required:true,
                    help:'1が非常に不満、5が非常に満足です。',
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
                    text:'今後も利用したいと思いますか？',
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
                    id:'q4',
                    groupId:'g2',
                    text:'改善してほしい点があれば教えてください。',
                    type:'text',
                    required:false,
                    help:'',
                    choices:[],
                    branches:{}
                }
            ],
            answers:[
                {
                    id:1001,
                    number:'R-0001',
                    answeredAt:'2026-09-10 11:30',
                    respondent:'山田 太郎',
                    values:{
                        q1:'顧客管理サービス',
                        q2:'5',
                        q3:'はい',
                        q4:'特にありません。'
                    }
                },
                {
                    id:1002,
                    number:'R-0002',
                    answeredAt:'2026-09-11 14:20',
                    respondent:'佐藤 花子',
                    values:{
                        q1:'顧客管理サービス',
                        q2:'4',
                        q3:'はい',
                        q4:'検索機能がさらに高速になると助かります。'
                    }
                }
            ]
        },

        {
            id:2,
            name:'2026年 新サービス利用意向調査',
            description:'新サービスに関する利用意向を確認します。',
            guidance:'簡単なアンケートです。ぜひご回答ください。',
            completeMessage:'ご協力ありがとうございました。',
            status:'wait',
            createdAt:'2026-08-15',
            updatedAt:'2026-09-12 15:00',
            startAt:'2026-10-01T09:00',
            endAt:'2026-11-30T18:00',
            numberMode:'group',
            sentCount:0,
            responseCount:0,
            selectedCustomerIds:[],
            lastSentAt:'',
            groups:[
                {
                    id:'g1',
                    name:'利用意向'
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
                        {id:'c1',text:'ぜひ利用したい'},
                        {id:'c2',text:'検討したい'},
                        {id:'c3',text:'利用しない'}
                    ],
                    branches:{
                        c1:{type:'next'},
                        c2:{type:'next'},
                        c3:{type:'end'}
                    }
                },
                {
                    id:'q2',
                    groupId:'g1',
                    text:'利用する場合、重視する点を教えてください。',
                    type:'multiple',
                    required:false,
                    help:'',
                    choices:[
                        {id:'c1',text:'価格'},
                        {id:'c2',text:'機能'},
                        {id:'c3',text:'サポート'}
                    ],
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


/* ============================================================
   2. Runtime state
============================================================ */

const STORAGE_KEY = 'questionnaire_mock_v3';

let state = null;

let memoryStorage = null;

let localStorageAvailable = false;

let modalConfirmHandler = null;

let draggedQuestionId = null;

const answerState = {
    surveyId:null,
    questionIds:[],
    currentIndex:0,
    values:{}
};


/* ============================================================
   3. Storage
============================================================ */

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function testLocalStorage() {

    try {

        const storage = window.localStorage;

        const key =
            '__questionnaire_mock_storage_test__';

        storage.setItem(key,'1');
        storage.removeItem(key);

        localStorageAvailable = true;

    } catch (error) {

        localStorageAvailable = false;

        console.warn(
            'localStorage は利用できません。メモリ上でモック状態を保持します。',
            error
        );
    }

    return localStorageAvailable;
}

function storageRead() {

    if (localStorageAvailable) {

        try {
            return window.localStorage.getItem(
                STORAGE_KEY
            );

        } catch (error) {

            localStorageAvailable = false;

            console.warn(
                'localStorage の読み込みに失敗しました。メモリストレージへ切り替えます。',
                error
            );
        }
    }

    return memoryStorage;
}

function storageWrite(value) {

    memoryStorage = value;

    if (localStorageAvailable) {

        try {

            window.localStorage.setItem(
                STORAGE_KEY,
                value
            );

            return true;

        } catch (error) {

            localStorageAvailable = false;

            console.warn(
                'localStorage の保存に失敗しました。メモリストレージへ切り替えます。',
                error
            );
        }
    }

    return false;
}

function normalizeState(data) {

    const base = clone(defaultData);

    const result = {
        ...base,
        ...data
    };

    result.settings = {
        ...base.settings,
        ...(data.settings || {})
    };

    result.settings.kintone = {
        ...base.settings.kintone,
        ...((data.settings || {}).kintone || {})
    };

    result.settings.smtp = {
        ...base.settings.smtp,
        ...((data.settings || {}).smtp || {})
    };

    result.customers =
        Array.isArray(data.customers)
            ? data.customers
            : base.customers;

    result.surveys =
        Array.isArray(data.surveys)
            ? data.surveys
            : base.surveys;

    result.sendResults =
        Array.isArray(data.sendResults)
            ? data.sendResults
            : base.sendResults;

    result.surveys =
        result.surveys.map(survey => ({

            ...survey,

            groups:
                Array.isArray(survey.groups)
                    ? survey.groups
                    : [],

            questions:
                Array.isArray(survey.questions)
                    ? survey.questions
                    : [],

            answers:
                Array.isArray(survey.answers)
                    ? survey.answers
                    : [],

            selectedCustomerIds:
                Array.isArray(survey.selectedCustomerIds)
                    ? survey.selectedCustomerIds
                    : [],

            sentCount:
                Number(survey.sentCount || 0),

            responseCount:
                Number(survey.responseCount || 0)

        }));

    return result;
}

function loadState() {

    testLocalStorage();

    try {

        const raw = storageRead();

        if (raw) {

            const parsed = JSON.parse(raw);

            if (
                parsed &&
                typeof parsed === 'object' &&
                Array.isArray(parsed.surveys)
            ) {

                return normalizeState(parsed);
            }
        }

    } catch (error) {

        console.warn(
            '保存データの読み込みに失敗しました。初期データを使用します。',
            error
        );
    }

    return clone(defaultData);
}

function saveState() {

    if (!state) {
        return false;
    }

    try {

        storageWrite(
            JSON.stringify(state)
        );

        return true;

    } catch (error) {

        console.warn(
            'モック状態の保存に失敗しました。',
            error
        );

        return false;
    }
}


/* ============================================================
   4. Utility
============================================================ */

function escapeHtml(value) {

    return String(value ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function escapeAttr(value) {
    return escapeHtml(value);
}

function uid(prefix='id') {

    return prefix +
        '_' +
        Date.now().toString(36) +
        '_' +
        Math.random()
            .toString(36)
            .slice(2,8);
}

function nowString() {

    const d = new Date();

    const pad =
        n => String(n).padStart(2,'0');

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
        return '';
    }

    return String(value)
        .replace('T',' ');
}

function currentSurvey() {

    if (
        !state ||
        !Array.isArray(state.surveys)
    ) {
        return null;
    }

    let survey =
        state.surveys.find(
            s =>
                Number(s.id) ===
                Number(state.currentSurveyId)
        );

    if (!survey) {
        survey = state.surveys[0] || null;
    }

    if (survey) {
        state.currentSurveyId = survey.id;
    }

    return survey;
}

function getSurvey(id) {

    return state.surveys.find(
        s => Number(s.id) === Number(id)
    ) || null;
}

function questionNumber(survey,q) {

    if (survey.numberMode === 'group') {

        const list =
            survey.questions.filter(
                x => x.groupId === q.groupId
            );

        return (
            list.findIndex(
                x => x.id === q.id
            ) + 1
        );
    }

    return (
        survey.questions.findIndex(
            x => x.id === q.id
        ) + 1
    );
}

function questionTypeLabel(type) {

    return {
        text:'文章を入力する',
        single:'1つだけ選ぶ',
        multiple:'複数選ぶ',
        rating:'段階で評価する'
    }[type] || type;
}

function statusLabel(status) {

    return {
        draft:'作成中',
        wait:'公開済み・回答開始待ち',
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

function canEdit(survey) {

    return (
        survey &&
        (
            survey.status === 'draft' ||
            survey.status === 'wait'
        )
    );
}

function canStructuralEdit(survey) {

    return (
        survey &&
        survey.status === 'draft'
    );
}

function countByStatus(status) {

    return state.surveys.filter(
        s => s.status === status
    ).length;
}

function responseRate(survey) {

    if (!survey || !survey.sentCount) {
        return 0;
    }

    return Math.round(
        survey.responseCount /
        survey.sentCount *
        100
    );
}


/* ============================================================
   5. Navigation
============================================================ */

const pageTitles = {

    home:'ホーム',

    surveys:'アンケート一覧',

    editor:'アンケート編集',

    preview:'公開前確認',

    responses:'回答状況',

    'response-detail':'回答内容',

    send:'アンケート送付',

    customers:'顧客選択',

    'send-confirm':'送付確認',

    'send-result':'送付結果',

    settings:'各種設定',

    answer:'回答者向けアンケート',

    'answer-confirm':'回答確認',

    'answer-complete':'回答完了'

};

function navigate(page) {

    const allowed =
        Object.prototype.hasOwnProperty.call(
            pageTitles,
            page
        );

    if (!allowed) {
        page = 'home';
    }

    state.currentPage = page;

    saveState();

    const title =
        document.getElementById(
            'topbarTitle'
        );

    if (title) {
        title.textContent =
            pageTitles[page];
    }

    document
        .querySelectorAll('.nav button')
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

    const root =
        document.getElementById(
            'appContent'
        );

    if (!root) {
        return;
    }

    try {

        switch (state.currentPage) {

            case 'home':
                root.innerHTML =
                    renderHome();
                break;

            case 'surveys':
                root.innerHTML =
                    renderSurveyList();
                break;

            case 'editor':
                root.innerHTML =
                    renderEditor();
                break;

            case 'preview':
                root.innerHTML =
                    renderPreview();
                break;

            case 'responses':
                root.innerHTML =
                    renderResponses();
                break;

            case 'response-detail':
                root.innerHTML =
                    renderResponseDetail();
                break;

            case 'send':
                root.innerHTML =
                    renderSend();
                break;

            case 'customers':
                root.innerHTML =
                    renderCustomers();
                break;

            case 'send-confirm':
                root.innerHTML =
                    renderSendConfirm();
                break;

            case 'send-result':
                root.innerHTML =
                    renderSendResult();
                break;

            case 'settings':
                root.innerHTML =
                    renderSettings();
                break;

            case 'answer':
                root.innerHTML =
                    renderAnswer();
                break;

            case 'answer-confirm':
                root.innerHTML =
                    renderAnswerConfirm();
                break;

            case 'answer-complete':
                root.innerHTML =
                    renderAnswerComplete();
                break;

            default:
                state.currentPage = 'home';
                root.innerHTML =
                    renderHome();
        }

    } catch (error) {

        console.error(
            '画面描画エラー:',
            error
        );

        root.innerHTML = `
            <div class="error-box">
                <strong>
                    画面の描画中にエラーが発生しました。
                </strong>

                <p>
                    ${escapeHtml(error.message)}
                </p>

                <button
                    type="button"
                    class="btn btn-danger"
                    data-action="reset">
                    モックデータを初期化する
                </button>
            </div>
        `;
    }
}


/* ============================================================
   6. Home
============================================================ */

function renderHome() {

    const draft =
        countByStatus('draft');

    const wait =
        countByStatus('wait');

    const active =
        countByStatus('active');

    const ended =
        countByStatus('ended');

    const archived =
        countByStatus('archived');

    const activeSurveys =
        state.surveys.filter(
            s => s.status === 'active'
        );

    return `

        <div class="page-head">

            <div>
                <h1 class="page-title">
                    アンケート運営状況
                </h1>

                <p class="page-description">
                    現在のアンケート運営状況と次に必要な操作を確認できます。
                </p>
            </div>

            <div class="actions">

                <button
                    type="button"
                    class="btn btn-primary"
                    data-action="new-survey">
                    ＋ 新しいアンケートを作成する
                </button>

                <button
                    type="button"
                    class="btn"
                    data-action="navigate"
                    data-page="surveys">
                    アンケート一覧
                </button>

            </div>

        </div>


        <div class="stat-grid">

            ${statCard(
                '作成中',
                draft,
                'surveys',
                'draft'
            )}

            ${statCard(
                '回答開始待ち',
                wait,
                'surveys',
                'wait'
            )}

            ${statCard(
                '回答受付中',
                active,
                'surveys',
                'active'
            )}

            ${statCard(
                '回答受付終了',
                ended,
                'surveys',
                'ended'
            )}

            ${statCard(
                '保管',
                archived,
                'surveys',
                'archived'
            )}

        </div>


        <div class="dashboard-grid">

            <div class="card">

                <div class="card-head">

                    <h2 class="card-title">
                        回答受付中
                    </h2>

                    <button
                        type="button"
                        class="btn btn-sm"
                        data-action="navigate"
                        data-page="responses">
                        回答状況を見る
                    </button>

                </div>

                <div class="card-body">

                    ${
                        activeSurveys.length
                            ? activeSurveys.map(
                                s => `
                                    <div
                                        style="
                                            padding:12px 0;
                                            border-bottom:1px solid var(--gray-200);
                                        ">

                                        <div style="
                                            display:flex;
                                            justify-content:space-between;
                                            gap:10px;
                                        ">

                                            <strong>
                                                ${escapeHtml(s.name)}
                                            </strong>

                                            <span class="status ${statusClass(s.status)}">
                                                ${statusLabel(s.status)}
                                            </span>

                                        </div>

                                        <div class="small muted"
                                             style="margin-top:7px">

                                            送付 ${s.sentCount}件 /
                                            回答 ${s.responseCount}件 /
                                            回答率 ${responseRate(s)}%

                                        </div>

                                    </div>
                                `
                            ).join('')
                            : `
                                <div class="muted">
                                    現在、回答受付中のアンケートはありません。
                                </div>
                            `
                    }

                </div>

            </div>


            <div class="card">

                <div class="card-head">

                    <h2 class="card-title">
                        最近更新されたアンケート
                    </h2>

                    <button
                        type="button"
                        class="btn btn-sm"
                        data-action="navigate"
                        data-page="surveys">
                        一覧を見る
                    </button>

                </div>

                <div class="card-body">

                    ${state.surveys
                        .slice()
                        .sort(
                            (a,b) =>
                                String(b.updatedAt)
                                    .localeCompare(
                                        String(a.updatedAt)
                                    )
                        )
                        .slice(0,5)
                        .map(
                            s => `

                                <div style="
                                    padding:12px 0;
                                    border-bottom:1px solid var(--gray-200);
                                ">

                                    <button
                                        type="button"
                                        class="btn-link"
                                        data-action="open-survey"
                                        data-id="${s.id}">
                                        ${escapeHtml(s.name)}
                                    </button>

                                    <div class="small muted">
                                        更新：${escapeHtml(s.updatedAt)}
                                    </div>

                                </div>
                            `
                        )
                        .join('')}

                </div>

            </div>

        </div>
    `;
}

function statCard(label,number,page,status) {

    return `
        <button
            type="button"
            class="stat-card"
            data-action="navigate"
            data-page="${page}">

            <div class="stat-label">
                ${escapeHtml(label)}
            </div>

            <div class="stat-number">
                ${number}
            </div>

            <div class="small muted">
                状態を確認
            </div>

        </button>
    `;
}


/* ============================================================
   7. Survey List
============================================================ */

function renderSurveyList() {

    return `

        <div class="page-head">

            <div>
                <h1 class="page-title">
                    アンケート一覧
                </h1>

                <p class="page-description">
                    作成したアンケートの状態・回答状況・主な操作を確認できます。
                </p>
            </div>

            <div class="actions">

                <button
                    type="button"
                    class="btn btn-primary"
                    data-action="new-survey">
                    ＋ 新しいアンケートを作成する
                </button>

            </div>

        </div>


        <div class="card">

            <div class="card-body">

                <div class="table-wrap">

                    <table>

                        <thead>

                            <tr>
                                <th>アンケート名</th>
                                <th>状態</th>
                                <th>作成日</th>
                                <th>更新日</th>
                                <th>回答開始</th>
                                <th>回答終了</th>
                                <th>送付数</th>
                                <th>回答数</th>
                                <th>回答率</th>
                                <th>主な操作</th>
                            </tr>

                        </thead>

                        <tbody>

                            ${
                                state.surveys.length
                                    ? state.surveys.map(
                                        renderSurveyRow
                                    ).join('')
                                    : `
                                        <tr>
                                            <td colspan="10"
                                                style="text-align:center">
                                                アンケートはありません。
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

function renderSurveyRow(survey) {

    let actions = `

        <button
            type="button"
            class="btn btn-sm"
            data-action="open-survey"
            data-id="${survey.id}">
            内容を見る
        </button>

        <button
            type="button"
            class="btn btn-sm"
            data-action="responses"
            data-id="${survey.id}">
            回答状況
        </button>

    `;

    if (canEdit(survey)) {

        actions += `

            <button
                type="button"
                class="btn btn-sm"
                data-action="edit-survey"
                data-id="${survey.id}">
                編集
            </button>

        `;
    }

    if (survey.status === 'draft') {

        actions += `

            <button
                type="button"
                class="btn btn-sm btn-primary"
                data-action="preview"
                data-id="${survey.id}">
                公開前確認
            </button>

        `;
    }

    if (survey.status === 'wait') {

        actions += `

            <button
                type="button"
                class="btn btn-sm btn-success"
                data-action="start-survey"
                data-id="${survey.id}">
                回答受付開始
            </button>

        `;
    }

    if (
        survey.status === 'wait' ||
        survey.status === 'active'
    ) {

        actions += `

            <button
                type="button"
                class="btn btn-sm btn-info"
                data-action="open-send"
                data-id="${survey.id}">
                アンケート送付
            </button>

        `;
    }

    if (survey.status === 'active') {

        actions += `

            <button
                type="button"
                class="btn btn-sm btn-warning"
                data-action="end-survey"
                data-id="${survey.id}">
                回答受付終了
            </button>

        `;
    }

    if (survey.status === 'ended') {

        actions += `

            <button
                type="button"
                class="btn btn-sm"
                data-action="archive-survey"
                data-id="${survey.id}">
                保管
            </button>

        `;
    }

    if (survey.status === 'draft') {

        actions += `

            <button
                type="button"
                class="btn btn-sm btn-danger"
                data-action="delete-survey"
                data-id="${survey.id}">
                削除
            </button>

        `;
    }

    return `

        <tr>

            <td>
                <button
                    type="button"
                    class="btn-link"
                    data-action="open-survey"
                    data-id="${survey.id}">
                    ${escapeHtml(survey.name || '名称未設定')}
                </button>
            </td>

            <td>
                <span class="status ${statusClass(survey.status)}">
                    ${statusLabel(survey.status)}
                </span>
            </td>

            <td>${escapeHtml(survey.createdAt)}</td>

            <td>${escapeHtml(survey.updatedAt)}</td>

            <td>${escapeHtml(formatDate(survey.startAt) || '—')}</td>

            <td>${escapeHtml(formatDate(survey.endAt) || '—')}</td>

            <td>${survey.sentCount}</td>

            <td>${survey.responseCount}</td>

            <td>${responseRate(survey)}%</td>

            <td class="actions-cell">
                ${actions}
            </td>

        </tr>
    `;
}


/* ============================================================
   8. Survey CRUD
============================================================ */

function newSurvey() {

    const ids =
        state.surveys
            .map(s => Number(s.id))
            .filter(Number.isFinite);

    const id =
        (ids.length
            ? Math.max(...ids)
            : 0) + 1;

    const survey = {

        id,

        name:'',

        description:'',

        guidance:'',

        completeMessage:
            'ご回答ありがとうございました。',

        status:'draft',

        createdAt:
            new Date()
                .toISOString()
                .slice(0,10),

        updatedAt:
            nowString(),

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

    state.currentSurveyId = id;

    saveState();

    navigate('editor');
}

function editSurvey(id) {

    const survey =
        getSurvey(id);

    if (!survey) {
        toast('アンケートが見つかりません。');
        return;
    }

    state.currentSurveyId =
        survey.id;

    saveState();

    navigate('editor');
}

function openSurvey(id) {

    const survey =
        getSurvey(
            id || state.currentSurveyId
        );

    if (!survey) {
        toast('アンケートが見つかりません。');
        return;
    }

    state.currentSurveyId =
        survey.id;

    saveState();

    if (survey.status === 'draft') {
        navigate('editor');
    } else {
        navigate('preview');
    }
}


/* ============================================================
   9. Editor
============================================================ */

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

    const structural =
        canStructuralEdit(survey);

    return `

        <div class="page-head">

            <div>

                <h1 class="page-title">
                    アンケート編集
                </h1>

                <p class="page-description">
                    アンケートの基本情報・質問・グループ・分岐を設定します。
                </p>

            </div>

            <div class="actions">

                <button
                    type="button"
                    class="btn"
                    data-action="navigate"
                    data-page="surveys">
                    一覧へ戻る
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    data-action="save-survey">
                    保存する
                </button>

            </div>

        </div>


        <div id="editorErrors"></div>


        <div class="editor-layout">

            <aside class="editor-sidebar">

                <div class="card">

                    <div class="card-body">

                        <button
                            type="button"
                            class="editor-nav-item active"
                            data-action="scroll-editor"
                            data-target="basic">
                            基本情報
                        </button>

                        <button
                            type="button"
                            class="editor-nav-item"
                            data-action="scroll-editor"
                            data-target="questions">
                            質問
                        </button>

                        <button
                            type="button"
                            class="editor-nav-item"
                            data-action="scroll-editor"
                            data-target="branch">
                            分岐
                        </button>

                        <button
                            type="button"
                            class="editor-nav-item"
                            data-action="preview"
                            data-id="${survey.id}">
                            公開前確認
                        </button>

                    </div>

                </div>


                <div class="card">

                    <div class="card-head">

                        <h2 class="card-title">
                            状態
                        </h2>

                    </div>

                    <div class="card-body">

                        <span class="status ${statusClass(survey.status)}">
                            ${statusLabel(survey.status)}
                        </span>

                        <p class="small muted">
                            更新：${escapeHtml(survey.updatedAt)}
                        </p>

                    </div>

                </div>

            </aside>


            <section>

                <div id="editor-basic"
                     class="card">

                    <div class="card-head">

                        <h2 class="card-title">
                            基本情報
                        </h2>

                    </div>

                    <div class="card-body">

                        ${renderBasicForm(survey)}

                    </div>

                </div>


                <div id="editor-questions"
                     class="card">

                    <div class="card-head">

                        <div>

                            <h2 class="card-title">
                                質問
                            </h2>

                            <div class="small muted">
                                質問をグループ化し、順番を変更できます。
                            </div>

                        </div>

                        <div class="actions">

                            ${
                                structural
                                    ? `
                                        <button
                                            type="button"
                                            class="btn btn-sm"
                                            data-action="add-group">
                                            ＋ グループ追加
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-primary"
                                            data-action="add-question">
                                            ＋ 質問追加
                                        </button>
                                    `
                                    : `
                                        <span class="small muted">
                                            公開後は質問構造を変更できません。
                                        </span>
                                    `
                            }

                        </div>

                    </div>

                    <div class="card-body">

                        ${renderQuestionGroups(survey)}

                    </div>

                </div>


                <div id="editor-branch"
                     class="card">

                    <div class="card-head">

                        <h2 class="card-title">
                            分岐設定
                        </h2>

                    </div>

                    <div class="card-body">

                        ${renderBranchSummary(survey)}

                    </div>

                </div>

            </section>

        </div>
    `;
}

function renderBasicForm(survey) {

    const editable =
        canEdit(survey);

    return `

        <div class="form-grid">

            <div class="form-group full">

                <label class="form-label">
                    アンケート名
                    <span class="required">*</span>
                </label>

                <input
                    id="surveyName"
                    type="text"
                    value="${escapeAttr(survey.name)}"
                    ${editable ? '' : 'readonly'}
                >

            </div>


            <div class="form-group full">

                <label class="form-label">
                    説明文
                </label>

                <textarea
                    id="surveyDescription"
                    ${editable ? '' : 'readonly'}
                >${escapeHtml(survey.description)}</textarea>

            </div>


            <div class="form-group">

                <label class="form-label">
                    回答受付開始日時
                </label>

                <input
                    id="surveyStartAt"
                    type="datetime-local"
                    value="${escapeAttr(survey.startAt)}"
                    ${editable ? '' : 'readonly'}
                >

            </div>


            <div class="form-group">

                <label class="form-label">
                    回答受付終了日時
                </label>

                <input
                    id="surveyEndAt"
                    type="datetime-local"
                    value="${escapeAttr(survey.endAt)}"
                    ${editable ? '' : 'readonly'}
                >

            </div>


            <div class="form-group full">

                <label class="form-label">
                    回答者への案内文
                </label>

                <textarea
                    id="surveyGuidance"
                    ${editable ? '' : 'readonly'}
                >${escapeHtml(survey.guidance)}</textarea>

            </div>


            <div class="form-group full">

                <label class="form-label">
                    完了時のメッセージ
                </label>

                <textarea
                    id="surveyCompleteMessage"
                    ${editable ? '' : 'readonly'}
                >${escapeHtml(survey.completeMessage)}</textarea>

            </div>


            <div class="form-group">

                <label class="form-label">
                    質問番号
                </label>

                <select
                    id="surveyNumberMode"
                    ${canStructuralEdit(survey) ? '' : 'disabled'}
                >

                    <option
                        value="global"
                        ${survey.numberMode === 'global' ? 'selected' : ''}>
                        アンケート全体で連番
                    </option>

                    <option
                        value="group"
                        ${survey.numberMode === 'group' ? 'selected' : ''}>
                        グループごとに連番
                    </option>

                </select>

            </div>

        </div>
    `;
}

function renderQuestionGroups(survey) {

    if (!survey.groups.length) {

        return `
            <div class="info-box">
                グループがありません。グループを追加してください。
            </div>
        `;
    }

    return `
        <div class="group-list">

            ${survey.groups.map(
                group =>
                    renderGroup(
                        survey,
                        group
                    )
            ).join('')}

        </div>
    `;
}

function renderGroup(survey,group) {

    const questions =
        survey.questions.filter(
            q => q.groupId === group.id
        );

    return `

        <div
            class="group-box"
            data-group-id="${escapeAttr(group.id)}"
            data-action="group-drop-zone"
        >

            <div class="group-head">

                <div class="group-name">
                    ${escapeHtml(group.name)}
                </div>

                <div class="actions">

                    <button
                        type="button"
                        class="btn btn-sm"
                        data-action="rename-group"
                        data-group-id="${escapeAttr(group.id)}">
                        名前変更
                    </button>

                    ${
                        survey.groups.length > 1 &&
                        canStructuralEdit(survey)
                            ? `
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    data-action="delete-group"
                                    data-group-id="${escapeAttr(group.id)}">
                                    削除
                                </button>
                            `
                            : ''
                    }

                </div>

            </div>


            <div
                class="question-list"
                data-group-id="${escapeAttr(group.id)}"
                data-action="drop-question"
            >

                ${
                    questions.length
                        ? questions.map(
                            q =>
                                renderQuestionCard(
                                    survey,
                                    q
                                )
                        ).join('')
                        : `
                            <div class="muted small"
                                 style="padding:10px">
                                このグループには質問がありません。
                                質問をここへドラッグできます。
                            </div>
                        `
                }

            </div>

        </div>
    `;
}

function renderQuestionCard(survey,q) {

    const choices =
        Array.isArray(q.choices)
            ? q.choices
            : [];

    return `

        <div
            class="question-card"
            draggable="${canStructuralEdit(survey) ? 'true' : 'false'}"
            data-question-id="${escapeAttr(q.id)}"
            data-action="question-drag"
        >

            <div class="question-head">

                <div class="question-title">

                    <span class="drag-handle">
                        ${canStructuralEdit(survey) ? '☷' : '•'}
                    </span>

                    <span class="question-number">
                        質問${questionNumber(survey,q)}
                    </span>

                    ${escapeHtml(q.text || '質問文未設定')}

                    ${
                        q.required
                            ? `
                                <span class="required-label">
                                    必須
                                </span>
                            `
                            : ''
                    }

                    <div class="small muted"
                         style="margin-top:5px">

                        ${questionTypeLabel(q.type)}

                    </div>

                </div>


                <div class="question-actions">

                    <button
                        type="button"
                        class="btn btn-sm"
                        data-action="edit-question"
                        data-question-id="${escapeAttr(q.id)}">
                        編集
                    </button>

                    ${
                        canStructuralEdit(survey)
                            ? `
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    data-action="delete-question"
                                    data-question-id="${escapeAttr(q.id)}">
                                    削除
                                </button>
                            `
                            : ''
                    }

                </div>

            </div>


            ${
                choices.length
                    ? `
                        <div class="choice-list">

                            ${choices.map(
                                c => `
                                    <div class="small">
                                        ・ ${escapeHtml(
                                            typeof c === 'string'
                                                ? c
                                                : c.text
                                        )}
                                    </div>
                                `
                            ).join('')}

                        </div>
                    `
                    : ''
            }


            ${
                q.help
                    ? `
                        <div class="help">
                            ${escapeHtml(q.help)}
                        </div>
                    `
                    : ''
            }

        </div>
    `;
}


/* ============================================================
   10. Groups / Questions
============================================================ */

function addGroup() {

    const survey =
        currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後はグループを変更できません。');
        return;
    }

    const name =
        prompt(
            'グループ名を入力してください。',
            `グループ${survey.groups.length + 1}`
        );

    if (name === null) {
        return;
    }

    const trimmed =
        name.trim();

    if (!trimmed) {
        toast('グループ名を入力してください。');
        return;
    }

    survey.groups.push({
        id:uid('g'),
        name:trimmed
    });

    survey.updatedAt =
        nowString();

    saveState();

    renderPage();

    toast('グループを追加しました。');
}

function renameGroup(groupId) {

    const survey =
        currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後はグループを変更できません。');
        return;
    }

    const group =
        survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    const name =
        prompt(
            'グループ名を入力してください。',
            group.name
        );

    if (name === null) {
        return;
    }

    if (!name.trim()) {
        toast('グループ名を入力してください。');
        return;
    }

    group.name =
        name.trim();

    survey.updatedAt =
        nowString();

    saveState();

    renderPage();

    toast('グループ名を変更しました。');
}

function deleteGroup(groupId) {

    const survey =
        currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後はグループを変更できません。');
        return;
    }

    if (survey.groups.length <= 1) {
        toast('最後のグループは削除できません。');
        return;
    }

    const group =
        survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    showConfirm(
        'グループを削除する',
        `
            <p>
                「${escapeHtml(group.name)}」を削除します。
            </p>

            <p>
                所属する質問は、先頭のグループへ移動します。
            </p>
        `,
        '削除する',
        () => {

            const target =
                survey.groups.find(
                    g => g.id !== groupId
                );

            survey.questions
                .filter(
                    q => q.groupId === groupId
                )
                .forEach(
                    q => q.groupId = target.id
                );

            survey.groups =
                survey.groups.filter(
                    g => g.id !== groupId
                );

            survey.updatedAt =
                nowString();

            saveState();

            closeModal();

            renderPage();

            toast('グループを削除しました。');

        },
        'danger'
    );
}

function addQuestion() {

    const survey =
        currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後は質問構造を変更できません。');
        return;
    }

    if (!survey.groups.length) {
        toast('先にグループを追加してください。');
        return;
    }

    const q = {

        id:uid('q'),

        groupId:
            survey.groups[0].id,

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

    editQuestion(q.id);
}

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
        toast('質問が見つかりません。');
        return;
    }

    const editable =
        canStructuralEdit(survey);

    showModal(
        '質問を編集する',
        renderQuestionForm(
            survey,
            q,
            editable
        ),
        `
            <button
                type="button"
                class="btn"
                data-action="close-modal">
                キャンセル
            </button>

            ${
                editable
                    ? `
                        <button
                            type="button"
                            class="btn btn-primary"
                            data-action="save-question"
                            data-question-id="${escapeAttr(q.id)}">
                            保存する
                        </button>
                    `
                    : ''
            }
        `
    );
}

function renderQuestionForm(
    survey,
    q,
    editable
) {

    return `

        <div class="form-group">

            <label class="form-label">
                質問文
                <span class="required">*</span>
            </label>

            <textarea
                id="questionText"
                ${editable ? '' : 'readonly'}
            >${escapeHtml(q.text)}</textarea>

        </div>


        <div class="form-group">

            <label class="form-label">
                質問の種類
            </label>

            <select
                id="questionType"
                ${editable ? '' : 'disabled'}
                data-action="question-type-change"
            >

                <option
                    value="text"
                    ${q.type === 'text' ? 'selected' : ''}>
                    文章を入力する
                </option>

                <option
                    value="single"
                    ${q.type === 'single' ? 'selected' : ''}>
                    1つだけ選ぶ
                </option>

                <option
                    value="multiple"
                    ${q.type === 'multiple' ? 'selected' : ''}>
                    複数選ぶ
                </option>

                <option
                    value="rating"
                    ${q.type === 'rating' ? 'selected' : ''}>
                    段階で評価する
                </option>

            </select>

        </div>


        <div class="form-group">

            <label>

                <input
                    id="questionRequired"
                    type="checkbox"
                    ${q.required ? 'checked' : ''}
                    ${editable ? '' : 'disabled'}
                >

                必須回答

            </label>

        </div>


        <div class="form-group">

            <label class="form-label">
                補足説明
            </label>

            <textarea
                id="questionHelp"
                ${editable ? '' : 'readonly'}
            >${escapeHtml(q.help)}</textarea>

        </div>


        <div
            id="questionChoices"
            class="form-group"
        >

            ${renderQuestionChoiceEditor(q)}

        </div>


        <div class="form-group">

            <label class="form-label">
                所属グループ
            </label>

            <select
                id="questionGroup"
                ${editable ? '' : 'disabled'}
            >

                ${survey.groups.map(
                    g => `
                        <option
                            value="${escapeAttr(g.id)}"
                            ${q.groupId === g.id ? 'selected' : ''}>
                            ${escapeHtml(g.name)}
                        </option>
                    `
                ).join('')}

            </select>

        </div>

    `;
}

function renderQuestionChoiceEditor(q) {

    if (
        q.type !== 'single' &&
        q.type !== 'multiple' &&
        q.type !== 'rating'
    ) {
        return '';
    }

    let choices =
        Array.isArray(q.choices)
            ? q.choices
            : [];

    if (
        q.type === 'rating' &&
        choices.length === 0
    ) {

        choices =
            ['1','2','3','4','5']
                .map(
                    (text,index) => ({
                        id:'r' + (index + 1),
                        text
                    })
                );
    }

    return `

        <label class="form-label">
            選択肢
        </label>

        <div id="choiceRows">

            ${choices.map(
                (c,index) => `

                    <div
                        class="choice-row"
                        data-choice-index="${index}"
                    >

                        <input
                            type="text"
                            value="${escapeAttr(
                                typeof c === 'string'
                                    ? c
                                    : c.text
                            )}"
                            ${q.type === 'rating' ? 'readonly' : ''}
                        >

                        ${
                            q.type !== 'rating'
                                ? `
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-danger"
                                        data-action="remove-choice">
                                        削除
                                    </button>
                                `
                                : ''
                        }

                    </div>
                `
            ).join('')}

        </div>

        ${
            q.type !== 'rating'
                ? `
                    <button
                        type="button"
                        class="btn btn-sm"
                        data-action="add-choice">
                        ＋ 選択肢を追加
                    </button>
                `
                : ''
        }

    `;
}

function saveQuestionEdit(questionId) {

    const survey =
        currentSurvey();

    const q =
        survey.questions.find(
            x => x.id === questionId
        );

    if (!q) {
        return;
    }

    if (!canStructuralEdit(survey)) {
        closeModal();
        return;
    }

    const text =
        document.getElementById(
            'questionText'
        )?.value.trim() || '';

    const type =
        document.getElementById(
            'questionType'
        )?.value || 'text';

    const required =
        !!document.getElementById(
            'questionRequired'
        )?.checked;

    const help =
        document.getElementById(
            'questionHelp'
        )?.value || '';

    const groupId =
        document.getElementById(
            'questionGroup'
        )?.value || survey.groups[0]?.id;

    const errors = [];

    if (!text) {
        errors.push('質問文を入力してください。');
    }

    if (
        (
            type === 'single' ||
            type === 'multiple'
        )
    ) {

        const inputs =
            document.querySelectorAll(
                '#choiceRows input'
            );

        const choices =
            Array.from(inputs)
                .map(
                    (input,index) => ({
                        id:
                            q.choices[index]?.id ||
                            uid('c'),
                        text:
                            input.value.trim()
                    })
                )
                .filter(
                    c => c.text !== ''
                );

        if (!choices.length) {
            errors.push(
                '選択式質問には1つ以上の選択肢を設定してください。'
            );
        }

        q.choices = choices;

    } else if (type === 'rating') {

        q.choices =
            ['1','2','3','4','5']
                .map(
                    (text,index) => ({
                        id:'r' + (index + 1),
                        text
                    })
                );

    } else {

        q.choices = [];
    }

    if (errors.length) {

        showValidationErrors(
            errors
        );

        return;
    }

    q.text = text;

    q.type = type;

    q.required = required;

    q.help = help;

    q.groupId = groupId;

    if (type !== 'single') {
        q.branches = {};
    }

    survey.updatedAt =
        nowString();

    saveState();

    closeModal();

    renderPage();

    toast('質問を保存しました。');
}

function deleteQuestion(questionId) {

    const survey =
        currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後は質問を削除できません。');
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
                「${escapeHtml(q.text || '質問文未設定')}」を削除します。
            </p>
        `,
        '削除する',
        () => {

            survey.questions =
                survey.questions.filter(
                    x => x.id !== questionId
                );

            survey.updatedAt =
                nowString();

            saveState();

            closeModal();

            renderPage();

            toast('質問を削除しました。');

        },
        'danger'
    );
}


/* ============================================================
   11. Drag & Drop
============================================================ */

function handleQuestionDragStart(event) {

    const card =
        event.target.closest(
            '.question-card'
        );

    if (!card) {
        return;
    }

    draggedQuestionId =
        card.dataset.questionId;

    card.classList.add('dragging');

    if (
        event.dataTransfer
    ) {

        event.dataTransfer.effectAllowed =
            'move';

        event.dataTransfer.setData(
            'text/plain',
            draggedQuestionId
        );
    }
}

function handleQuestionDragEnd(event) {

    const card =
        event.target.closest(
            '.question-card'
        );

    if (card) {
        card.classList.remove(
            'dragging'
        );
    }

    document
        .querySelectorAll('.drag-over')
        .forEach(
            el =>
                el.classList.remove(
                    'drag-over'
                )
        );

    draggedQuestionId = null;
}

function handleQuestionDragOver(event) {

    event.preventDefault();

    const card =
        event.target.closest(
            '.question-card'
        );

    const list =
        event.target.closest(
            '.question-list'
        );

    if (card) {
        card.classList.add(
            'drag-over'
        );
    }

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect =
            'move';
    }

    return list;
}

function handleQuestionDrop(event) {

    event.preventDefault();

    const survey =
        currentSurvey();

    if (!canStructuralEdit(survey)) {
        return;
    }

    const sourceId =
        draggedQuestionId ||
        event.dataTransfer?.getData(
            'text/plain'
        );

    if (!sourceId) {
        return;
    }

    const targetCard =
        event.target.closest(
            '.question-card'
        );

    const targetList =
        event.target.closest(
            '.question-list'
        );

    const sourceIndex =
        survey.questions.findIndex(
            q => q.id === sourceId
        );

    if (sourceIndex < 0) {
        return;
    }

    const source =
        survey.questions[sourceIndex];

    survey.questions.splice(
        sourceIndex,
        1
    );

    if (targetCard) {

        const targetId =
            targetCard.dataset.questionId;

        const targetIndex =
            survey.questions.findIndex(
                q => q.id === targetId
            );

        if (targetIndex >= 0) {

            const rect =
                targetCard.getBoundingClientRect();

            const insertAfter =
                event.clientY >
                rect.top +
                rect.height / 2;

            survey.questions.splice(
                targetIndex +
                (insertAfter ? 1 : 0),
                0,
                source
            );

        } else {

            survey.questions.push(
                source
            );
        }

    } else if (targetList) {

        source.groupId =
            targetList.dataset.groupId;

        const groupQuestions =
            survey.questions.filter(
                q =>
                    q.groupId ===
                    targetList.dataset.groupId
            );

        const last =
            groupQuestions[
                groupQuestions.length - 1
            ];

        if (last) {

            const index =
                survey.questions.findIndex(
                    q => q.id === last.id
                );

            survey.questions.splice(
                index + 1,
                0,
                source
            );

        } else {

            survey.questions.push(
                source
            );
        }

    } else {

        survey.questions.push(
            source
        );
    }

    survey.updatedAt =
        nowString();

    saveState();

    renderPage();
}


/* ============================================================
   12. Preview / Validation
============================================================ */

function validateSurvey(survey) {

    const errors = [];

    if (!survey.name.trim()) {
        errors.push(
            'アンケート名を設定してください。'
        );
    }

    if (!survey.questions.length) {
        errors.push(
            '質問を1件以上登録してください。'
        );
    }

    survey.questions.forEach(
        (q,index) => {

            if (!q.text.trim()) {

                errors.push(
                    `質問${index + 1}の質問文を設定してください。`
                );
            }

            if (
                (
                    q.type === 'single' ||
                    q.type === 'multiple' ||
                    q.type === 'rating'
                ) &&
                (
                    !Array.isArray(q.choices) ||
                    !q.choices.length
                )
            ) {

                errors.push(
                    `質問${index + 1}の選択肢を設定してください。`
                );
            }

            if (
                q.groupId &&
                !survey.groups.some(
                    g => g.id === q.groupId
                )
            ) {

                errors.push(
                    `質問${index + 1}の所属グループが存在しません。`
                );
            }

            if (
                q.type === 'single' &&
                q.branches
            ) {

                q.choices.forEach(
                    choice => {

                        const branch =
                            q.branches[choice.id];

                        if (!branch) {
                            return;
                        }

                        if (
                            branch.type === 'question' &&
                            !survey.questions.some(
                                x =>
                                    x.id ===
                                    branch.target
                            )
                        ) {

                            errors.push(
                                `質問${index + 1}の分岐先質問が存在しません。`
                            );
                        }

                        if (
                            branch.type === 'group' &&
                            !survey.groups.some(
                                g =>
                                    g.id ===
                                    branch.target
                            )
                        ) {

                            errors.push(
                                `質問${index + 1}の分岐先グループが存在しません。`
                            );
                        }
                    }
                );
            }
        }
    );

    if (
        survey.startAt &&
        survey.endAt
    ) {

        const start =
            new Date(survey.startAt);

        const end =
            new Date(survey.endAt);

        if (
            !Number.isNaN(start.getTime()) &&
            !Number.isNaN(end.getTime()) &&
            start >= end
        ) {

            errors.push(
                '回答受付開始日時は終了日時より前に設定してください。'
            );
        }
    }

    return {
        errors
    };
}

function renderPreview() {

    const survey =
        currentSurvey();

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
                    公開前確認
                </h1>

                <p class="page-description">
                    回答者から見える内容と公開前チェックを確認します。
                </p>

            </div>

            <div class="actions">

                <button
                    type="button"
                    class="btn"
                    data-action="edit-survey"
                    data-id="${survey.id}">
                    編集画面へ戻る
                </button>

                ${
                    survey.status === 'draft'
                        ? `
                            <button
                                type="button"
                                class="btn btn-primary"
                                data-action="publish"
                                data-id="${survey.id}">
                                公開する
                            </button>
                        `
                        : ''
                }

            </div>

        </div>


        ${renderPreviewChecks(survey)}


        <div class="preview-shell">

            <div class="preview-header">

                <span class="status ${statusClass(survey.status)}">
                    ${statusLabel(survey.status)}
                </span>

                <h1>
                    ${escapeHtml(survey.name || '名称未設定')}
                </h1>

                <p>
                    ${escapeHtml(survey.description)}
                </p>

                ${
                    survey.guidance
                        ? `
                            <div class="info-box">
                                ${escapeHtml(survey.guidance)}
                            </div>
                        `
                        : ''
                }

            </div>


            ${renderPreviewQuestions(survey)}


            <div class="card">

                <div class="card-head">

                    <h2 class="card-title">
                        完了時
                    </h2>

                </div>

                <div class="card-body">

                    ${escapeHtml(
                        survey.completeMessage ||
                        'ご回答ありがとうございました。'
                    )}

                </div>

            </div>

        </div>
    `;
}

function renderPreviewChecks(survey) {

    const validation =
        validateSurvey(survey);

    if (!validation.errors.length) {

        return `
            <div class="success-box">
                公開前チェックに問題はありません。
                公開可能な状態です。
            </div>
        `;
    }

    return `

        <div class="error-box">

            <strong>
                公開前に修正が必要です。
            </strong>

            <ul>

                ${validation.errors.map(
                    e => `
                        <li>
                            ${escapeHtml(e)}
                        </li>
                    `
                ).join('')}

            </ul>

        </div>
    `;
}

function renderPreviewQuestions(survey) {

    return survey.groups.map(
        group => {

            const questions =
                survey.questions.filter(
                    q =>
                        q.groupId ===
                        group.id
                );

            if (!questions.length) {
                return '';
            }

            return `

                <div class="card">

                    <div class="card-head">

                        <h2 class="card-title">
                            ${escapeHtml(group.name)}
                        </h2>

                    </div>

                    <div class="card-body">

                        ${questions.map(
                            q => `
                                <div class="answer-question">

                                    <div class="small muted">
                                        質問${questionNumber(survey,q)}
                                    </div>

                                    <div class="answer-question-title">

                                        ${escapeHtml(q.text)}

                                        ${
                                            q.required
                                                ? `
                                                    <span class="required-label">
                                                        必須
                                                    </span>
                                                `
                                                : ''
                                        }

                                    </div>

                                    ${
                                        q.type === 'text'
                                            ? `
                                                <textarea
                                                    readonly
                                                    placeholder="文章入力欄">
                                                </textarea>
                                            `
                                            : `
                                                <div class="choice-list">

                                                    ${q.choices.map(
                                                        c => `
                                                            <label class="option">

                                                                <input
                                                                    type="${
                                                                        q.type === 'multiple'
                                                                            ? 'checkbox'
                                                                            : 'radio'
                                                                    }"
                                                                    disabled
                                                                >

                                                                <span>
                                                                    ${escapeHtml(c.text)}
                                                                </span>

                                                            </label>
                                                        `
                                                    ).join('')}

                                                </div>
                                            `
                                    }

                                    ${
                                        q.help
                                            ? `
                                                <div class="help">
                                                    ${escapeHtml(q.help)}
                                                </div>
                                            `
                                            : ''
                                    }

                                </div>
                            `
                        ).join('')}

                    </div>

                </div>
            `;
        }
    ).join('');
}

function publishSurvey(id) {

    const survey =
        getSurvey(id);

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
                「${escapeHtml(survey.name)}」を公開します。
            </p>

            <p>
                公開後は質問構造や分岐などの変更に制限があります。
            </p>

            <p>
                回答開始日時：
                <strong>
                    ${
                        escapeHtml(
                            formatDate(survey.startAt) ||
                            '未設定（公開後すぐ開始）'
                        )
                    }
                </strong>
            </p>
        `,
        '公開する',
        () => {

            const now =
                new Date();

            if (survey.startAt) {

                const start =
                    new Date(
                        survey.startAt
                    );

                survey.status =
                    start > now
                        ? 'wait'
                        : 'active';

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
        }
    );
}


/* ============================================================
   13. Branch
============================================================ */

function renderBranchSummary(survey) {

    const questions =
        survey.questions.filter(
            q => q.type === 'single'
        );

    if (!questions.length) {

        return `
            <div class="muted">
                「1つだけ選ぶ」質問がないため、
                分岐設定はありません。
            </div>
        `;
    }

    return `

        <div class="info-box">

            分岐は「1つだけ選ぶ」質問で設定できます。
            各選択肢について、
            次の質問・特定の質問・特定のグループ・回答終了を指定できます。

        </div>


        ${questions.map(
            q => `

                <div
                    style="
                        border:1px solid var(--gray-200);
                        border-radius:8px;
                        margin-bottom:12px;
                        padding:14px;
                    "
                >

                    <div style="font-weight:700">

                        質問${questionNumber(survey,q)}：
                        ${escapeHtml(q.text)}

                    </div>


                    ${q.choices.map(
                        choice => {

                            const branch =
                                q.branches?.[
                                    choice.id
                                ] ||
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

                                    <div class="small">

                                        → ${
                                            escapeHtml(
                                                branchTargetLabel(
                                                    survey,
                                                    branch
                                                )
                                            )
                                        }

                                    </div>

                                </div>
                            `;
                        }
                    ).join('')}

                    ${
                        canStructuralEdit(survey)
                            ? `
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    style="margin-top:10px"
                                    data-action="edit-branches"
                                    data-question-id="${escapeAttr(q.id)}">
                                    分岐を設定
                                </button>
                            `
                            : ''
                    }

                </div>
            `
        ).join('')}

    `;
}

function branchTargetLabel(
    survey,
    branch
) {

    if (!branch) {
        return '未設定';
    }

    switch (branch.type) {

        case 'next':
            return '次の質問';

        case 'end':
            return '回答終了';

        case 'question': {

            const q =
                survey.questions.find(
                    x =>
                        x.id ===
                        branch.target
                );

            return q
                ? `質問${questionNumber(survey,q)}：${q.text}`
                : '存在しない質問';
        }

        case 'group': {

            const g =
                survey.groups.find(
                    x =>
                        x.id ===
                        branch.target
                );

            return g
                ? `グループ：${g.name}`
                : '存在しないグループ';
        }

        default:
            return '未設定';
    }
}

function editBranches(questionId) {

    const survey =
        currentSurvey();

    const q =
        survey.questions.find(
            x => x.id === questionId
        );

    if (
        !q ||
        q.type !== 'single'
    ) {
        return;
    }

    showModal(
        '分岐設定',
        `
            ${q.choices.map(
                choice => {

                    const branch =
                        q.branches?.[
                            choice.id
                        ] ||
                        {
                            type:'next'
                        };

                    return `

                        <div
                            style="
                                padding:12px 0;
                                border-bottom:1px solid var(--gray-200);
                            "
                        >

                            <strong>
                                ${escapeHtml(choice.text)}
                            </strong>

                            <div class="form-group"
                                 style="margin-top:8px">

                                <select
                                    data-branch-type="${escapeAttr(choice.id)}"
                                    data-choice-id="${escapeAttr(choice.id)}"
                                >

                                    <option
                                        value="next"
                                        ${branch.type === 'next' ? 'selected' : ''}>
                                        次の質問
                                    </option>

                                    <option
                                        value="question"
                                        ${branch.type === 'question' ? 'selected' : ''}>
                                        特定の質問
                                    </option>

                                    <option
                                        value="group"
                                        ${branch.type === 'group' ? 'selected' : ''}>
                                        特定のグループ
                                    </option>

                                    <option
                                        value="end"
                                        ${branch.type === 'end' ? 'selected' : ''}>
                                        回答終了
                                    </option>

                                </select>

                            </div>

                            <div
                                class="form-group"
                                data-branch-target-wrap="${escapeAttr(choice.id)}"
                            >

                                ${renderBranchTargetSelect(
                                    survey,
                                    branch,
                                    choice.id
                                )}

                            </div>

                        </div>
                    `;
                }
            ).join('')}
        `,
        `
            <button
                type="button"
                class="btn"
                data-action="close-modal">
                キャンセル
            </button>

            <button
                type="button"
                class="btn btn-primary"
                data-action="save-branches"
                data-question-id="${escapeAttr(questionId)}">
                保存する
            </button>
        `
    );
}

function renderBranchTargetSelect(
    survey,
    branch,
    choiceId
) {

    if (
        branch.type !== 'question' &&
        branch.type !== 'group'
    ) {
        return '';
    }

    if (branch.type === 'question') {

        return `

            <select
                data-branch-target="${escapeAttr(choiceId)}">

                <option value="">
                    選択してください
                </option>

                ${survey.questions
                    .filter(
                        q => q.id !== choiceId
                    )
                    .map(
                        q => `
                            <option
                                value="${escapeAttr(q.id)}"
                                ${branch.target === q.id ? 'selected' : ''}>
                                質問${questionNumber(survey,q)}：
                                ${escapeHtml(q.text)}
                            </option>
                        `
                    ).join('')}

            </select>
        `;
    }

    return `

        <select
            data-branch-target="${escapeAttr(choiceId)}">

            <option value="">
                選択してください
            </option>

            ${survey.groups.map(
                g => `
                    <option
                        value="${escapeAttr(g.id)}"
                        ${branch.target === g.id ? 'selected' : ''}>
                        ${escapeHtml(g.name)}
                    </option>
                `
            ).join('')}

        </select>
    `;
}

function saveBranches(questionId) {

    const survey =
        currentSurvey();

    const q =
        survey.questions.find(
            x => x.id === questionId
        );

    if (!q) {
        return;
    }

    q.branches = {};

    const errors = [];

    q.choices.forEach(
        choice => {

            const typeSelect =
                document.querySelector(
                    `[data-branch-type="${CSS.escape(choice.id)}"]`
                );

            const type =
                typeSelect?.value ||
                'next';

            let branch = {
                type
            };

            if (
                type === 'question' ||
                type === 'group'
            ) {

                const target =
                    document.querySelector(
                        `[data-branch-target="${CSS.escape(choice.id)}"]`
                    )?.value || '';

                if (!target) {

                    errors.push(
                        `「${choice.text}」の分岐先を設定してください。`
                    );

                } else {

                    branch.target =
                        target;
                }
            }

            q.branches[
                choice.id
            ] = branch;
        }
    );

    if (errors.length) {

        showValidationErrors(
            errors
        );

        return;
    }

    survey.updatedAt =
        nowString();

    saveState();

    closeModal();

    renderPage();

    toast('分岐設定を保存しました。');
}


/* ============================================================
   14. Survey State
============================================================ */

function startSurvey(id) {

    const survey =
        getSurvey(id);

    if (!survey) {
        return;
    }

    if (survey.status !== 'wait') {

        toast(
            '回答受付を開始できる状態ではありません。'
        );

        return;
    }

    showConfirm(
        '回答受付を開始する',
        `
            <p>
                「${escapeHtml(survey.name)}」の
                回答受付を開始します。
            </p>
        `,
        '回答受付を開始する',
        () => {

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
        }
    );
}

function endSurvey(id) {

    const survey =
        getSurvey(id);

    if (!survey) {
        return;
    }

    if (survey.status !== 'active') {

        toast(
            '回答受付中のアンケートではありません。'
        );

        return;
    }

    showConfirm(
        '回答受付を終了する',
        `
            <p>
                「${escapeHtml(survey.name)}」の
                回答受付を終了します。
            </p>

            <p>
                終了すると新しい回答を受け付けなくなります。
            </p>
        `,
        '回答受付を終了する',
        () => {

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
        getSurvey(id);

    if (!survey) {
        return;
    }

    if (survey.status !== 'ended') {

        toast(
            '回答受付終了後に保管できます。'
        );

        return;
    }

    showConfirm(
        'アンケートを保管する',
        `
            <p>
                「${escapeHtml(survey.name)}」を保管します。
            </p>
        `,
        '保管する',
        () => {

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
        }
    );
}

function deleteSurvey(id) {

    const survey =
        getSurvey(id);

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
                「${escapeHtml(survey.name || '名称未設定')}」を削除します。
            </p>

            <p>
                この操作は取り消せません。
            </p>
        `,
        '削除する',
        () => {

            state.surveys =
                state.surveys.filter(
                    s =>
                        Number(s.id) !==
                        Number(id)
                );

            if (
                Number(state.currentSurveyId) ===
                Number(id)
            ) {

                state.currentSurveyId =
                    state.surveys[0]?.id ||
                    null;
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

function saveSurvey() {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    if (!canEdit(survey)) {

        toast(
            '現在の状態では編集できません。'
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
            'surveyNumberMode'
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
        new Date(startAt) >=
        new Date(endAt)
    ) {

        errors.push(
            '回答受付開始日時は終了日時より前に設定してください。'
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

    toast(
        'アンケートを保存しました。'
    );

    renderPage();
}


/* ============================================================
   15. Responses
============================================================ */

function openResponses(id) {

    if (id) {

        const survey =
            getSurvey(id);

        if (survey) {
            state.currentSurveyId =
                survey.id;
        }
    }

    saveState();

    navigate('responses');
}

function renderResponses() {

    const survey =
        currentSurvey();

    if (!survey) {

        return `
            <div class="error-box">
                アンケートがありません。
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
                    アンケートごとの送付数・回答数・回答率を確認できます。
                </p>

            </div>

            <div class="actions">

                <button
                    type="button"
                    class="btn"
                    data-action="navigate"
                    data-page="surveys">
                    アンケート一覧
                </button>

                <button
                    type="button"
                    class="btn"
                    data-action="response-detail"
                    data-id="${survey.id}">
                    回答内容を見る
                </button>

            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <div>

                    <h2 class="card-title">
                        ${escapeHtml(survey.name)}
                    </h2>

                    <div class="small muted">
                        ${statusLabel(survey.status)}
                    </div>

                </div>

                <span class="status ${statusClass(survey.status)}">
                    ${statusLabel(survey.status)}
                </span>

            </div>


            <div class="card-body">

                <div class="kpi-grid">

                    <div class="kpi">
                        <div class="kpi-label">
                            送付数
                        </div>
                        <div class="kpi-value">
                            ${survey.sentCount}
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            回答数
                        </div>
                        <div class="kpi-value">
                            ${survey.responseCount}
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            回答率
                        </div>
                        <div class="kpi-value">
                            ${rate}%
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            回答受付期間
                        </div>
                        <div class="small"
                             style="margin-top:8px">

                            ${escapeHtml(
                                formatDate(survey.startAt) || '未設定'
                            )}

                            ～

                            ${escapeHtml(
                                formatDate(survey.endAt) || '未設定'
                            )}

                        </div>
                    </div>

                </div>


                <div style="margin-top:25px">

                    <strong>
                        回答状況
                    </strong>

                    <div
                        class="progress-track"
                        style="margin-top:8px"
                    >

                        <div
                            class="progress-bar"
                            style="width:${Math.min(rate,100)}%"
                        ></div>

                    </div>

                    <div class="small muted"
                         style="margin-top:5px">

                        ${survey.responseCount}
                        /
                        ${survey.sentCount}
                        件回答

                    </div>

                </div>

            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    アンケートを選択
                </h2>

            </div>

            <div class="card-body">

                ${state.surveys.map(
                    s => `

                        <button
                            type="button"
                            class="btn ${
                                Number(s.id) ===
                                Number(survey.id)
                                    ? 'btn-primary'
                                    : ''
                            }"
                            style="margin:3px"
                            data-action="responses"
                            data-id="${s.id}">
                            ${escapeHtml(s.name)}
                        </button>

                    `
                ).join('')}

            </div>

        </div>
    `;
}


/* ============================================================
   16. Response Detail
============================================================ */

function openResponseDetail(id) {

    if (id) {

        const survey =
            getSurvey(id);

        if (survey) {
            state.currentSurveyId =
                survey.id;
        }
    }

    saveState();

    navigate('response-detail');
}

function renderResponseDetail() {

    const survey =
        currentSurvey();

    if (!survey) {
        return `
            <div class="error-box">
                アンケートがありません。
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
                    回答を1件ずつ確認できます。
                </p>

            </div>

            <div class="actions">

                <button
                    type="button"
                    class="btn"
                    data-action="responses"
                    data-id="${survey.id}">
                    回答状況へ戻る
                </button>

            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    ${escapeHtml(survey.name)}
                </h2>

            </div>

            <div class="card-body">

                ${
                    survey.answers.length
                        ? survey.answers.map(
                            answer =>
                                renderAnswerRecord(
                                    survey,
                                    answer
                                )
                        ).join('')
                        : `
                            <div class="muted">
                                回答はまだありません。
                            </div>
                        `
                }

            </div>

        </div>
    `;
}

function renderAnswerRecord(
    survey,
    answer
) {

    return `

        <div
            style="
                border:1px solid var(--gray-200);
                border-radius:8px;
                padding:15px;
                margin-bottom:12px;
            "
        >

            <div style="
                display:flex;
                justify-content:space-between;
                gap:10px;
                margin-bottom:12px;
            ">

                <strong>
                    ${escapeHtml(answer.number)}
                </strong>

                <span class="small muted">
                    ${escapeHtml(answer.answeredAt)}
                </span>

            </div>

            <div class="small muted">
                回答者：${escapeHtml(answer.respondent)}
            </div>

            <div style="margin-top:15px">

                ${survey.questions.map(
                    q => {

                        const value =
                            answer.values?.[
                                q.id
                            ];

                        return `

                            <div
                                style="
                                    padding:10px 0;
                                    border-bottom:1px solid var(--gray-200);
                                "
                            >

                                <div style="font-weight:700">
                                    質問${questionNumber(survey,q)}
                                    ：
                                    ${escapeHtml(q.text)}
                                </div>

                                <div
                                    style="
                                        margin-top:5px;
                                        white-space:pre-wrap;
                                    "
                                >
                                    ${
                                        Array.isArray(value)
                                            ? escapeHtml(
                                                value.join('、')
                                            )
                                            : escapeHtml(
                                                value ?? '未回答'
                                            )
                                    }
                                </div>

                            </div>
                        `;
                    }
                ).join('')}

            </div>

        </div>
    `;
}


/* ============================================================
   17. Send
============================================================ */

function openSend(id) {

    if (id) {

        const survey =
            getSurvey(id);

        if (survey) {
            state.currentSurveyId =
                survey.id;
        }
    }

    saveState();

    navigate('send');
}

function renderSend() {

    const survey =
        currentSurvey();

    if (!survey) {
        return `
            <div class="error-box">
                アンケートがありません。
            </div>
        `;
    }

    if (
        survey.status !== 'wait' &&
        survey.status !== 'active'
    ) {

        return `

            <div class="error-box">

                このアンケートは現在送付できる状態ではありません。

                <div style="margin-top:10px">

                    <button
                        type="button"
                        class="btn"
                        data-action="navigate"
                        data-page="surveys">
                        アンケート一覧へ戻る
                    </button>

                </div>

            </div>
        `;
    }

    const selected =
        survey.selectedCustomerIds || [];

    return `

        <div class="page-head">

            <div>

                <h1 class="page-title">
                    アンケート送付
                </h1>

                <p class="page-description">
                    送付先を選択し、メール内容を確認して送付します。
                </p>

            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    送付対象
                </h2>

            </div>

            <div class="card-body">

                <strong>
                    ${escapeHtml(survey.name)}
                </strong>

                <p class="small muted">
                    現在の選択：
                    <span id="selectedCustomerCount">
                        ${selected.length}
                    </span>
                    件
                </p>

            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    顧客一覧
                </h2>

                <div class="actions">

                    <button
                        type="button"
                        class="btn btn-sm"
                        data-action="select-all-customers">
                        全選択
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm"
                        data-action="clear-customers">
                        全解除
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm"
                        data-action="refresh-customers">
                        顧客一覧を更新
                    </button>

                </div>

            </div>

            <div class="card-body">

                <div class="table-wrap">

                    <table>

                        <thead>

                            <tr>
                                <th></th>
                                <th>顧客名</th>
                                <th>担当者名</th>
                                <th>メールアドレス</th>
                            </tr>

                        </thead>

                        <tbody>

                            ${state.customers.map(
                                customer => `

                                    <tr>

                                        <td>

                                            <input
                                                type="checkbox"
                                                data-customer-id="${customer.id}"
                                                data-action="toggle-customer"
                                                ${
                                                    selected.includes(
                                                        customer.id
                                                    )
                                                        ? 'checked'
                                                        : ''
                                                }
                                            >

                                        </td>

                                        <td>
                                            ${escapeHtml(customer.name)}
                                        </td>

                                        <td>
                                            ${escapeHtml(customer.contact)}
                                        </td>

                                        <td>
                                            ${escapeHtml(customer.email)}
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

                    <label class="form-label">
                        件名
                    </label>

                    <input
                        id="sendSubject"
                        type="text"
                        value="${escapeAttr(
                            survey.name +
                            ' のご案内'
                        )}"
                    >

                </div>

                <div class="form-group">

                    <label class="form-label">
                        本文
                    </label>

                    <textarea id="sendBody">${escapeHtml(
                        survey.guidance ||
                        'アンケートへのご協力をお願いいたします。'
                    )}</textarea>

                </div>

            </div>

        </div>


        <div class="actions">

            <button
                type="button"
                class="btn"
                data-action="navigate"
                data-page="surveys">
                キャンセル
            </button>

            <button
                type="button"
                class="btn btn-primary"
                data-action="send-confirm">
                送付内容を確認する
            </button>

        </div>
    `;
}

function toggleCustomer(id) {

    const survey =
        currentSurvey();

    const customerId =
        Number(id);

    if (!survey) {
        return;
    }

    if (!Array.isArray(
        survey.selectedCustomerIds
    )) {

        survey.selectedCustomerIds =
            [];
    }

    if (
        survey.selectedCustomerIds.includes(
            customerId
        )
    ) {

        survey.selectedCustomerIds =
            survey.selectedCustomerIds.filter(
                x => x !== customerId
            );

    } else {

        survey.selectedCustomerIds.push(
            customerId
        );
    }

    saveState();

    updateSelectedCustomerCount();
}

function updateSelectedCustomerCount() {

    const survey =
        currentSurvey();

    const count =
        survey?.selectedCustomerIds?.length ||
        0;

    const element =
        document.getElementById(
            'selectedCustomerCount'
        );

    if (element) {
        element.textContent =
            String(count);
    }
}

function selectAllCustomers() {

    const survey =
        currentSurvey();

    survey.selectedCustomerIds =
        state.customers.map(
            c => Number(c.id)
        );

    saveState();

    renderPage();

    toast(
        '顧客を全選択しました。'
    );
}

function clearCustomers() {

    const survey =
        currentSurvey();

    survey.selectedCustomerIds =
        [];

    saveState();

    renderPage();

    toast(
        '顧客の選択を解除しました。'
    );
}

function refreshCustomers() {

    /*
     * kintone接続を行う代わりに、
     * モック顧客一覧を再表示する。
     */

    state.settings.kintone.updatedAt =
        nowString();

    saveState();

    renderPage();

    toast(
        '顧客一覧を更新しました。'
    );
}

function executeSend() {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    const selected =
        survey.selectedCustomerIds || [];

    if (!selected.length) {

        toast(
            '送付先を1件以上選択してください。'
        );

        return;
    }

    if (
        !state.settings.kintone.connected
    ) {

        toast(
            '顧客一覧を取得できないため送付できません。'
        );

        return;
    }

    if (
        !state.settings.smtp.configured
    ) {

        toast(
            'SMTP設定を確認してください。'
        );

        return;
    }

    showConfirm(
        'アンケートを送付する',
        `
            <p>
                アンケート：
                <strong>${escapeHtml(survey.name)}</strong>
            </p>

            <p>
                送付先：
                <strong>${selected.length}件</strong>
            </p>

            <p>
                件名：
                ${escapeHtml(
                    document.getElementById(
                        'sendSubject'
                    )?.value ||
                    ''
                )}
            </p>
        `,
        '送付する',
        () => {

            const target =
                selected.length;

            /*
             * モックなので全件成功を基本とし、
             * 一部失敗の例も再現。
             */
            const failed =
                target >= 2
                    ? 1
                    : 0;

            const success =
                target -
                failed;

            survey.sentCount +=
                success;

            survey.lastSentAt =
                nowString();

            survey.updatedAt =
                nowString();

            const nextId =
                state.sendResults.length
                    ? Math.max(
                        ...state.sendResults.map(
                            r => Number(r.id)
                        )
                    ) + 1
                    : 1;

            state.sendResults.unshift({

                id:nextId,

                surveyId:survey.id,

                target,

                success,

                failed,

                sentAt:
                    nowString(),

                failedCustomers:
                    failed
                        ? [
                            'モック送信エラー：' +
                            (
                                state.customers.find(
                                    c =>
                                        c.id ===
                                        selected[0]
                                )?.name ||
                                '顧客'
                            )
                        ]
                        : []

            });

            saveState();

            closeModal();

            navigate('send-result');

            toast(
                'アンケートを送付しました。'
            );
        }
    );
}

function renderSendConfirm() {

    const survey =
        currentSurvey();

    const selected =
        survey?.selectedCustomerIds ||
        [];

    const subject =
        document.getElementById(
            'sendSubject'
        )?.value ||
        survey?.name + ' のご案内';

    const body =
        document.getElementById(
            'sendBody'
        )?.value ||
        survey?.guidance ||
        '';

    if (!selected.length) {

        toast(
            '送付先を1件以上選択してください。'
        );

        navigate('send');

        return '';
    }

    showModal(
        '送付前確認',
        `
            <div class="info-box">
                送信前に内容を確認してください。
            </div>

            <p>
                <strong>アンケート名</strong><br>
                ${escapeHtml(survey.name)}
            </p>

            <p>
                <strong>送付先件数</strong><br>
                ${selected.length}件
            </p>

            <p>
                <strong>件名</strong><br>
                ${escapeHtml(subject)}
            </p>

            <p style="white-space:pre-wrap">
                <strong>本文</strong><br>
                ${escapeHtml(body)}
            </p>
        `,
        `
            <button
                type="button"
                class="btn"
                data-action="close-modal">
                戻る
            </button>

            <button
                type="button"
                class="btn btn-primary"
                data-action="execute-send">
                アンケートを送付する
            </button>
        `
    );

    return '';
}


/* ============================================================
   18. Send Result
============================================================ */

function renderSendResult() {

    return `

        <div class="page-head">

            <div>

                <h1 class="page-title">
                    送付結果
                </h1>

                <p class="page-description">
                    アンケート案内メールの送付結果を確認できます。
                </p>

            </div>

            <button
                type="button"
                class="btn"
                data-action="open-send">
                アンケートを送付する
            </button>

        </div>


        <div class="card">

            <div class="card-body">

                ${
                    state.sendResults.length
                        ? `
                            <div class="table-wrap">

                                <table>

                                    <thead>

                                        <tr>
                                            <th>アンケート</th>
                                            <th>送付対象</th>
                                            <th>成功</th>
                                            <th>失敗</th>
                                            <th>送付日時</th>
                                            <th></th>
                                        </tr>

                                    </thead>

                                    <tbody>

                                        ${state.sendResults.map(
                                            r => {

                                                const survey =
                                                    getSurvey(
                                                        r.surveyId
                                                    );

                                                return `

                                                    <tr>

                                                        <td>
                                                            ${escapeHtml(
                                                                survey?.name ||
                                                                '削除されたアンケート'
                                                            )}
                                                        </td>

                                                        <td>
                                                            ${r.target}
                                                        </td>

                                                        <td class="text-success">
                                                            ${r.success}
                                                        </td>

                                                        <td class="text-danger">
                                                            ${r.failed}
                                                        </td>

                                                        <td>
                                                            ${escapeHtml(r.sentAt)}
                                                        </td>

                                                        <td>

                                                            <button
                                                                type="button"
                                                                class="btn btn-sm"
                                                                data-action="show-send-result"
                                                                data-id="${r.id}">
                                                                結果を見る
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
                        : `
                            <div class="muted">
                                送付履歴はありません。
                            </div>
                        `
                }

            </div>

        </div>
    `;
}

function showSendResult(id) {

    const result =
        state.sendResults.find(
            r => Number(r.id) ===
                 Number(id)
        );

    if (!result) {
        return;
    }

    const survey =
        getSurvey(
            result.surveyId
        );

    showModal(
        '送付結果',
        `
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
                        送付日時
                    </div>
                    <div class="small"
                         style="margin-top:8px">
                        ${escapeHtml(result.sentAt)}
                    </div>
                </div>

            </div>

            <p>
                ${escapeHtml(
                    survey?.name ||
                    ''
                )}
            </p>

            ${
                result.failedCustomers?.length
                    ? `
                        <div style="margin-top:18px">

                            <strong>
                                送付できなかった顧客
                            </strong>

                            <ul>

                                ${result.failedCustomers.map(
                                    x =>
                                        `<li>${escapeHtml(x)}</li>`
                                ).join('')}

                            </ul>

                        </div>
                    `
                    : ''
            }
        `,
        `
            <button
                type="button"
                class="btn"
                data-action="close-modal">
                閉じる
            </button>
        `
    );
}


/* ============================================================
   19. Settings
============================================================ */

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
                        顧客一覧を取得するための設定
                    </div>

                </div>

                ${
                    k.connected
                        ? `
                            <span class="status status-active">
                                接続確認済み
                            </span>
                        `
                        : `
                            <span class="status status-ended">
                                未確認
                            </span>
                        `
                }

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
                            value="${escapeAttr(k.host)}"
                        >

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            対象アプリ
                        </label>

                        <input
                            id="kApp"
                            type="text"
                            value="${escapeAttr(k.app)}"
                        >

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            顧客名として利用する項目
                        </label>

                        <input
                            id="kNameField"
                            type="text"
                            value="${escapeAttr(k.nameField)}"
                        >

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            担当者名として利用する項目
                        </label>

                        <input
                            id="kContactField"
                            type="text"
                            value="${escapeAttr(k.contactField)}"
                        >

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            メールアドレスとして利用する項目
                        </label>

                        <input
                            id="kEmailField"
                            type="text"
                            value="${escapeAttr(k.emailField)}"
                        >

                    </div>

                </div>


                <div class="actions">

                    <button
                        type="button"
                        class="btn btn-primary"
                        data-action="save-kintone">
                        保存する
                    </button>

                    <button
                        type="button"
                        class="btn"
                        data-action="test-kintone">
                        接続確認
                    </button>

                </div>

                ${
                    k.updatedAt
                        ? `
                            <div class="small muted"
                                 style="margin-top:10px">
                                最終更新：${escapeHtml(k.updatedAt)}
                            </div>
                        `
                        : ''
                }

            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <div>

                    <h2 class="card-title">
                        SMTP設定
                    </h2>

                    <div class="small muted">
                        アンケート案内メール送信用のモック設定
                    </div>

                </div>

                ${
                    smtp.configured
                        ? `
                            <span class="status status-active">
                                設定済み
                            </span>
                        `
                        : `
                            <span class="status status-ended">
                                未設定
                            </span>
                        `
                }

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
                            value="${escapeAttr(smtp.host)}"
                        >

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            ポート
                        </label>

                        <input
                            id="smtpPort"
                            type="number"
                            value="${escapeAttr(smtp.port)}"
                        >

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            送信元メールアドレス
                        </label>

                        <input
                            id="smtpFrom"
                            type="email"
                            value="${escapeAttr(smtp.from)}"
                        >

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            送信設定
                        </label>

                        <select id="smtpEncryption">

                            <option
                                value="STARTTLS"
                                ${smtp.encryption === 'STARTTLS' ? 'selected' : ''}>
                                STARTTLS
                            </option>

                            <option
                                value="SSL/TLS"
                                ${smtp.encryption === 'SSL/TLS' ? 'selected' : ''}>
                                SSL/TLS
                            </option>

                            <option
                                value="NONE"
                                ${smtp.encryption === 'NONE' ? 'selected' : ''}>
                                なし
                            </option>

                        </select>

                    </div>

                </div>


                <div class="actions">

                    <button
                        type="button"
                        class="btn btn-primary"
                        data-action="save-smtp">
                        保存する
                    </button>

                    <button
                        type="button"
                        class="btn"
                        data-action="test-smtp">
                        送信設定を確認
                    </button>

                </div>

                ${
                    smtp.updatedAt
                        ? `
                            <div class="small muted"
                                 style="margin-top:10px">
                                最終更新：${escapeHtml(smtp.updatedAt)}
                            </div>
                        `
                        : ''
                }

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
        )?.value.trim() || '';

    k.app =
        document.getElementById(
            'kApp'
        )?.value.trim() || '';

    k.nameField =
        document.getElementById(
            'kNameField'
        )?.value.trim() || '';

    k.contactField =
        document.getElementById(
            'kContactField'
        )?.value.trim() || '';

    k.emailField =
        document.getElementById(
            'kEmailField'
        )?.value.trim() || '';

    k.updatedAt =
        nowString();

    saveState();

    renderPage();

    toast(
        'kintone設定を保存しました。'
    );
}

function testKintone() {

    const k =
        state.settings.kintone;

    if (
        !k.host ||
        !k.app ||
        !k.nameField ||
        !k.contactField ||
        !k.emailField
    ) {

        showValidationErrors([
            'kintone設定をすべて入力してください。'
        ]);

        return;
    }

    k.connected =
        true;

    k.updatedAt =
        nowString();

    saveState();

    renderPage();

    toast(
        'kintone接続確認に成功しました（モック）。'
    );
}

function saveSmtp() {

    const smtp =
        state.settings.smtp;

    smtp.host =
        document.getElementById(
            'smtpHost'
        )?.value.trim() || '';

    smtp.port =
        document.getElementById(
            'smtpPort'
        )?.value.trim() || '';

    smtp.from =
        document.getElementById(
            'smtpFrom'
        )?.value.trim() || '';

    smtp.encryption =
        document.getElementById(
            'smtpEncryption'
        )?.value || 'STARTTLS';

    smtp.configured =
        !!(
            smtp.host &&
            smtp.port &&
            smtp.from
        );

    smtp.updatedAt =
        nowString();

    saveState();

    renderPage();

    toast(
        'SMTP設定を保存しました。'
    );
}

function testSmtp() {

    const smtp =
        state.settings.smtp;

    if (
        !smtp.host ||
        !smtp.port ||
        !smtp.from
    ) {

        showValidationErrors([
            'SMTP設定をすべて入力してください。'
        ]);

        return;
    }

    smtp.configured =
        true;

    smtp.updatedAt =
        nowString();

    saveState();

    renderPage();

    toast(
        'SMTP設定の確認に成功しました（モック）。'
    );
}


/* ============================================================
   20. Answerer
============================================================ */

function startAnswer(id) {

    const survey =
        getSurvey(
            id || state.currentSurveyId
        );

    if (!survey) {
        toast('回答対象のアンケートがありません。');
        return;
    }

    if (survey.status !== 'active') {

        toast(
            '現在このアンケートは回答受付中ではありません。'
        );

        return;
    }

    state.currentSurveyId =
        survey.id;

    answerState.surveyId =
        survey.id;

    answerState.questionIds =
        survey.questions.map(
            q => q.id
        );

    answerState.currentIndex =
        0;

    answerState.values =
        {};

    saveState();

    navigate('answer');
}

function getVisibleQuestionIds(survey) {

    /*
     * モックでは分岐を実際に評価して
     * 表示対象質問を決定する。
     */
    const ids = [];

    let currentIndex = 0;

    let safety = 0;

    while (
        currentIndex <
        survey.questions.length &&
        safety < 100
    ) {

        safety++;

        const q =
            survey.questions[
                currentIndex
            ];

        if (!q) {
            break;
        }

        ids.push(q.id);

        let nextIndex =
            currentIndex + 1;

        const value =
            answerState.values[
                q.id
            ];

        if (
            q.type === 'single' &&
            value
        ) {

            const selected =
                q.choices.find(
                    c =>
                        c.text === value ||
                        c.id === value
                );

            if (selected) {

                const branch =
                    q.branches?.[
                        selected.id
                    ];

                if (branch) {

                    if (
                        branch.type === 'end'
                    ) {

                        break;
                    }

                    if (
                        branch.type === 'question'
                    ) {

                        const index =
                            survey.questions.findIndex(
                                x =>
                                    x.id ===
                                    branch.target
                            );

                        if (index >= 0) {
                            nextIndex = index;
                        }
                    }

                    if (
                        branch.type === 'group'
                    ) {

                        const index =
                            survey.questions.findIndex(
                                x =>
                                    x.groupId ===
                                    branch.target
                            );

                        if (index >= 0) {
                            nextIndex = index;
                        }
                    }
                }
            }
        }

        currentIndex =
            nextIndex;
    }

    return ids;
}

function renderAnswer() {

    const survey =
        currentSurvey();

    if (!survey) {

        return `
            <div class="error-box">
                回答対象が見つかりません。
            </div>
        `;
    }

    if (
        survey.status !== 'active'
    ) {

        return `

            <div class="preview-shell">

                <div class="error-box">

                    このアンケートは現在回答を受け付けていません。

                    <div style="margin-top:10px">

                        <button
                            type="button"
                            class="btn"
                            data-action="navigate"
                            data-page="home">
                            戻る
                        </button>

                    </div>

                </div>

            </div>
        `;
    }

    const visibleIds =
        getVisibleQuestionIds(
            survey
        );

    if (!visibleIds.length) {

        return `
            <div class="error-box">
                回答可能な質問がありません。
            </div>
        `;
    }

    if (
        answerState.surveyId !==
        survey.id
    ) {

        answerState.surveyId =
            survey.id;

        answerState.questionIds =
            visibleIds;

        answerState.currentIndex =
            0;

        answerState.values =
            {};
    }

    answerState.questionIds =
        visibleIds;

    if (
        answerState.currentIndex >=
        visibleIds.length
    ) {

        answerState.currentIndex =
            visibleIds.length - 1;
    }

    const q =
        survey.questions.find(
            question =>
                question.id ===
                visibleIds[
                    answerState.currentIndex
                ]
        );

    if (!q) {
        return '';
    }

    const group =
        survey.groups.find(
            g =>
                g.id === q.groupId
        );

    const progress =
        Math.round(
            (
                answerState.currentIndex + 1
            ) /
            visibleIds.length *
            100
        );

    return `

        <div class="preview-shell">

            <div class="preview-header">

                <h1>
                    ${escapeHtml(survey.name)}
                </h1>

                ${
                    survey.description
                        ? `
                            <p>
                                ${escapeHtml(survey.description)}
                            </p>
                        `
                        : ''
                }

                ${
                    survey.guidance
                        ? `
                            <div class="info-box">
                                ${escapeHtml(survey.guidance)}
                            </div>
                        `
                        : ''
                }

            </div>


            <div class="answer-progress">

                <div style="
                    display:flex;
                    justify-content:space-between;
                    margin-bottom:6px;
                ">

                    <span>
                        現在の回答状況
                    </span>

                    <span class="small muted">
                        ${answerState.currentIndex + 1}
                        /
                        ${visibleIds.length}
                    </span>

                </div>

                <div class="progress-track">

                    <div
                        class="progress-bar"
                        style="width:${progress}%"
                    ></div>

                </div>

            </div>


            <div class="answer-question">

                <div class="small muted">
                    ${escapeHtml(group?.name || '')}
                </div>

                <div class="answer-question-title">

                    質問${questionNumber(survey,q)}：
                    ${escapeHtml(q.text)}

                    ${
                        q.required
                            ? `
                                <span class="required-label">
                                    必須
                                </span>
                            `
                            : ''
                    }

                </div>


                ${renderAnswerInput(q)}


                ${
                    q.help
                        ? `
                            <div class="help">
                                ${escapeHtml(q.help)}
                            </div>
                        `
                        : ''
                }


                <div id="answerError"
                     class="error-box"
                     style="display:none;margin-top:15px">
                </div>


                <div class="answer-footer">

                    <button
                        type="button"
                        class="btn"
                        data-action="answer-back">
                        ${
                            answerState.currentIndex > 0
                                ? '前の質問'
                                : '回答をやめる'
                        }
                    </button>

                    <button
                        type="button"
                        class="btn btn-primary"
                        data-action="answer-next">
                        ${
                            answerState.currentIndex <
                            visibleIds.length - 1
                                ? '次へ'
                                : '回答内容を確認する'
                        }
                    </button>

                </div>

            </div>

        </div>
    `;
}

function renderAnswerInput(q) {

    const value =
        answerState.values[
            q.id
        ];

    if (q.type === 'text') {

        return `

            <textarea
                id="answerText"
                data-answer-input="${escapeAttr(q.id)}"
            >${escapeHtml(
                typeof value === 'string'
                    ? value
                    : ''
            )}</textarea>
        `;
    }

    if (q.type === 'multiple') {

        const values =
            Array.isArray(value)
                ? value
                : [];

        return `

            <div class="choice-list">

                ${q.choices.map(
                    c => `

                        <label class="option">

                            <input
                                type="checkbox"
                                value="${escapeAttr(c.text)}"
                                data-answer-input="${escapeAttr(q.id)}"
                                ${values.includes(c.text) ? 'checked' : ''}
                            >

                            <span>
                                ${escapeHtml(c.text)}
                            </span>

                        </label>
                    `
                ).join('')}

            </div>
        `;
    }

    return `

        <div class="choice-list">

            ${q.choices.map(
                c => `

                    <label class="option">

                        <input
                            type="radio"
                            name="answer_${escapeAttr(q.id)}"
                            value="${escapeAttr(c.text)}"
                            data-answer-input="${escapeAttr(q.id)}"
                            ${value === c.text ? 'checked' : ''}
                        >

                        <span>
                            ${escapeHtml(c.text)}
                        </span>

                    </label>
                `
            ).join('')}

        </div>
    `;
}

function captureCurrentAnswer() {

    const survey =
        currentSurvey();

    if (!survey) {
        return;
    }

    const qId =
        answerState.questionIds[
            answerState.currentIndex
        ];

    if (!qId) {
        return;
    }

    const q =
        survey.questions.find(
            x =>
                x.id === qId
        );

    if (!q) {
        return;
    }

    if (q.type === 'text') {

        answerState.values[q.id] =
            document.getElementById(
                'answerText'
            )?.value || '';

    } else if (
        q.type === 'multiple'
    ) {

        answerState.values[q.id] =
            Array.from(
                document.querySelectorAll(
                    `[data-answer-input="${CSS.escape(q.id)}"]:checked`
                )
            ).map(
                el => el.value
            );

    } else {

        answerState.values[q.id] =
            document.querySelector(
                `[data-answer-input="${CSS.escape(q.id)}"]:checked`
            )?.value || '';
    }
}

function validateCurrentAnswer() {

    const survey =
        currentSurvey();

    const qId =
        answerState.questionIds[
            answerState.currentIndex
        ];

    const q =
        survey.questions.find(
            x => x.id === qId
        );

    if (!q) {
        return [];
    }

    captureCurrentAnswer();

    const value =
        answerState.values[
            q.id
        ];

    if (
        q.required &&
        (
            value === '' ||
            value === null ||
            value === undefined ||
            (
                Array.isArray(value) &&
                value.length === 0
            )
        )
    ) {

        return [
            `質問${questionNumber(survey,q)}「${q.text}」に回答してください。`
        ];
    }

    return [];
}

function answerNext() {

    const survey =
        currentSurvey();

    const errors =
        validateCurrentAnswer();

    if (errors.length) {

        const box =
            document.getElementById(
                'answerError'
            );

        if (box) {

            box.innerHTML =
                `<ul>${errors.map(
                    e =>
                        `<li>${escapeHtml(e)}</li>`
                ).join('')}</ul>`;

            box.style.display =
                'block';
        }

        return;
    }

    /*
     * 回答後に分岐を再計算。
     */
    answerState.questionIds =
        getVisibleQuestionIds(
            survey
        );

    if (
        answerState.currentIndex <
        answerState.questionIds.length - 1
    ) {

        answerState.currentIndex++;

        navigate('answer');

    } else {

        navigate(
            'answer-confirm'
        );
    }
}

function answerBack() {

    captureCurrentAnswer();

    if (
        answerState.currentIndex > 0
    ) {

        answerState.currentIndex--;

        navigate('answer');

    } else {

        navigate('home');
    }
}

function renderAnswerConfirm() {

    const survey =
        currentSurvey();

    const ids =
        getVisibleQuestionIds(
            survey
        );

    return `

        <div class="preview-shell">

            <div class="preview-header">

                <h1>
                    回答確認
                </h1>

                <p>
                    送信前に回答内容を確認してください。
                </p>

            </div>


            ${ids.map(
                id => {

                    const q =
                        survey.questions.find(
                            x =>
                                x.id === id
                        );

                    const value =
                        answerState.values[
                            id
                        ];

                    return `

                        <div class="answer-question">

                            <div class="small muted">
                                質問${questionNumber(survey,q)}
                            </div>

                            <div class="answer-question-title">
                                ${escapeHtml(q.text)}
                            </div>

                            <div style="white-space:pre-wrap">

                                ${
                                    Array.isArray(value)
                                        ? escapeHtml(
                                            value.join('、')
                                        )
                                        : escapeHtml(
                                            value || '未回答'
                                        )
                                }

                            </div>

                        </div>
                    `;
                }
            ).join('')}


            <div class="answer-footer">

                <button
                    type="button"
                    class="btn"
                    data-action="answer-edit">
                    回答を修正する
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    data-action="submit-answer">
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
        () => {

            const nextNumber =
                survey.answers.length + 1;

            survey.answers.push({

                id:
                    Date.now(),

                number:
                    'R-' +
                    String(nextNumber)
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

            survey.responseCount++;

            survey.updatedAt =
                nowString();

            saveState();

            closeModal();

            navigate(
                'answer-complete'
            );
        }
    );
}

function renderAnswerComplete() {

    const survey =
        currentSurvey();

    return `

        <div class="preview-shell">

            <div class="card"
                 style="margin-top:60px">

                <div
                    class="card-body"
                    style="
                        text-align:center;
                        padding:50px 25px;
                    "
                >

                    <div style="
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
                    ">
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
                            type="button"
                            class="btn"
                            data-action="navigate"
                            data-page="home">
                            管理画面へ戻る
                        </button>

                    </div>

                </div>

            </div>

        </div>
    `;
}


/* ============================================================
   21. Modal / Toast
============================================================ */

function showModal(
    title,
    body,
    footer = ''
) {

    const backdrop =
        document.getElementById(
            'modalBackdrop'
        );

    const titleElement =
        document.getElementById(
            'modalTitle'
        );

    const bodyElement =
        document.getElementById(
            'modalBody'
        );

    const footerElement =
        document.getElementById(
            'modalFooter'
        );

    if (
        !backdrop ||
        !titleElement ||
        !bodyElement ||
        !footerElement
    ) {
        return;
    }

    titleElement.innerHTML =
        title;

    bodyElement.innerHTML =
        body;

    footerElement.innerHTML =
        footer;

    backdrop.classList.add(
        'show'
    );

    backdrop.setAttribute(
        'aria-hidden',
        'false'
    );
}

function closeModal() {

    const backdrop =
        document.getElementById(
            'modalBackdrop'
        );

    if (backdrop) {

        backdrop.classList.remove(
            'show'
        );

        backdrop.setAttribute(
            'aria-hidden',
            'true'
        );
    }

    modalConfirmHandler =
        null;
}

function showConfirm(
    title,
    body,
    confirmLabel,
    callback,
    kind='primary'
) {

    const buttonClass =
        kind === 'danger'
            ? 'btn-danger'
            : kind === 'warning'
                ? 'btn-warning'
                : 'btn-primary';

    modalConfirmHandler =
        callback;

    showModal(
        title,
        body,
        `
            <button
                type="button"
                class="btn"
                data-action="close-modal">
                キャンセル
            </button>

            <button
                type="button"
                class="btn ${buttonClass}"
                data-action="modal-confirm">
                ${escapeHtml(confirmLabel)}
            </button>
        `
    );
}

function showValidationErrors(
    errors,
    targetId=null
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
        '入力内容を確認してください',
        html,
        `
            <button
                type="button"
                class="btn"
                data-action="close-modal">
                閉じる
            </button>
        `
    );
}

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
        window.__mockToastTimer
    );

    window.__mockToastTimer =
        setTimeout(
            () => {

                element.classList.remove(
                    'show'
                );

            },
            2500
        );
}


/* ============================================================
   22. Event Delegation
============================================================ */

document.addEventListener(
    'click',
    function(event) {

        const target =
            event.target.closest(
                '[data-action]'
            );

        if (!target) {
            return;
        }

        const action =
            target.dataset.action;

        try {

            switch (action) {

                case 'navigate':
                    navigate(
                        target.dataset.page
                    );
                    break;

                case 'new-survey':
                    newSurvey();
                    break;

                case 'open-survey':
                    openSurvey(
                        target.dataset.id
                    );
                    break;

                case 'edit-survey':
                    editSurvey(
                        target.dataset.id
                    );
                    break;

                case 'preview':
                    state.currentSurveyId =
                        Number(
                            target.dataset.id
                        );
                    saveState();
                    navigate('preview');
                    break;

                case 'publish':
                    publishSurvey(
                        target.dataset.id
                    );
                    break;

                case 'start-survey':
                    startSurvey(
                        target.dataset.id
                    );
                    break;

                case 'end-survey':
                    endSurvey(
                        target.dataset.id
                    );
                    break;

                case 'archive-survey':
                    archiveSurvey(
                        target.dataset.id
                    );
                    break;

                case 'delete-survey':
                    deleteSurvey(
                        target.dataset.id
                    );
                    break;

                case 'responses':
                    openResponses(
                        target.dataset.id
                    );
                    break;

                case 'response-detail':
                    openResponseDetail(
                        target.dataset.id
                    );
                    break;

                case 'open-send':
                    openSend(
                        target.dataset.id
                    );
                    break;

                case 'send-confirm':
                    renderSendConfirm();
                    break;

                case 'execute-send':
                    executeSend();
                    break;

                case 'show-send-result':
                    showSendResult(
                        target.dataset.id
                    );
                    break;

                case 'save-survey':
                    saveSurvey();
                    break;

                case 'add-group':
                    addGroup();
                    break;

                case 'rename-group':
                    renameGroup(
                        target.dataset.groupId
                    );
                    break;

                case 'delete-group':
                    deleteGroup(
                        target.dataset.groupId
                    );
                    break;

                case 'add-question':
                    addQuestion();
                    break;

                case 'edit-question':
                    editQuestion(
                        target.dataset.questionId
                    );
                    break;

                case 'delete-question':
                    deleteQuestion(
                        target.dataset.questionId
                    );
                    break;

                case 'add-choice':
                    addChoiceRow();
                    break;

                case 'remove-choice':
                    target
                        .closest('.choice-row')
                        ?.remove();
                    break;

                case 'edit-branches':
                    editBranches(
                        target.dataset.questionId
                    );
                    break;

                case 'save-branches':
                    saveBranches(
                        target.dataset.questionId
                    );
                    break;

                case 'select-all-customers':
                    selectAllCustomers();
                    break;

                case 'clear-customers':
                    clearCustomers();
                    break;

                case 'refresh-customers':
                    refreshCustomers();
                    break;

                case 'toggle-customer':
                    /*
                     * checkbox の click は change イベント側で処理するため
                     * ここでは何もしない。
                     */
                    break;

                case 'save-kintone':
                    saveKintone();
                    break;

                case 'test-kintone':
                    testKintone();
                    break;

                case 'save-smtp':
                    saveSmtp();
                    break;

                case 'test-smtp':
                    testSmtp();
                    break;

                case 'start-answer':
                    startAnswer();
                    break;

                case 'answer-next':
                    answerNext();
                    break;

                case 'answer-back':
                    answerBack();
                    break;

                case 'answer-edit':
                    answerState.currentIndex =
                        0;
                    navigate('answer');
                    break;

                case 'submit-answer':
                    submitAnswer();
                    break;

                case 'close-modal':
                    closeModal();
                    break;

                case 'modal-confirm':

                    if (
                        typeof modalConfirmHandler ===
                        'function'
                    ) {

                        const handler =
                            modalConfirmHandler;

                        modalConfirmHandler =
                            null;

                        handler();
                    }

                    break;

                case 'scroll-editor': {

                    const element =
                        document.getElementById(
                            'editor-' +
                            target.dataset.target
                        );

                    element?.scrollIntoView({
                        behavior:'smooth',
                        block:'start'
                    });

                    break;
                }

                default:
                    break;
            }

        } catch (error) {

            console.error(
                '操作エラー:',
                action,
                error
            );

            toast(
                '操作中にエラーが発生しました。'
            );
        }
    }
);


/* ============================================================
   23. Change Events
============================================================ */

document.addEventListener(
    'change',
    function(event) {

        const target =
            event.target;

        if (
            target.matches(
                '[data-action="toggle-customer"]'
            )
        ) {

            toggleCustomer(
                target.dataset.customerId
            );

            return;
        }

        if (
            target.matches(
                '[data-action="question-type-change"]'
            )
        ) {

            const box =
                document.getElementById(
                    'questionChoices'
                );

            if (!box) {
                return;
            }

            const q = {
                type:
                    target.value,
                choices:[]
            };

            box.innerHTML =
                renderQuestionChoiceEditor(q);

            return;
        }

        if (
            target.matches(
                '[data-branch-type]'
            )
        ) {

            const choiceId =
                target.dataset.choiceId;

            const wrap =
                document.querySelector(
                    `[data-branch-target-wrap="${CSS.escape(choiceId)}"]`
                );

            if (!wrap) {
                return;
            }

            const survey =
                currentSurvey();

            const branch = {
                type:
                    target.value
            };

            wrap.innerHTML =
                renderBranchTargetSelect(
                    survey,
                    branch,
                    choiceId
                );
        }
    }
);


/* ============================================================
   24. Drag Events
============================================================ */

document.addEventListener(
    'dragstart',
    function(event) {

        if (
            event.target.closest(
                '.question-card'
            )
        ) {

            handleQuestionDragStart(
                event
            );
        }
    }
);

document.addEventListener(
    'dragend',
    function(event) {

        if (
            event.target.closest(
                '.question-card'
            )
        ) {

            handleQuestionDragEnd(
                event
            );
        }
    }
);

document.addEventListener(
    'dragover',
    function(event) {

        if (
            event.target.closest(
                '.question-list'
            )
        ) {

            handleQuestionDragOver(
                event
            );
        }
    }
);

document.addEventListener(
    'drop',
    function(event) {

        if (
            event.target.closest(
                '.question-list'
            )
        ) {

            handleQuestionDrop(
                event
            );
        }
    }
);


/* ============================================================
   25. Choice editor
============================================================ */

function addChoiceRow() {

    const list =
        document.getElementById(
            'choiceRows'
        );

    if (!list) {
        return;
    }

    const row =
        document.createElement(
            'div'
        );

    row.className =
        'choice-row';

    row.innerHTML = `

        <input
            type="text"
            value=""
        >

        <button
            type="button"
            class="btn btn-sm btn-danger"
            data-action="remove-choice">
            削除
        </button>
    `;

    list.appendChild(row);
}


/* ============================================================
   26. Modal backdrop
============================================================ */

document
    .getElementById(
        'modalBackdrop'
    )
    ?.addEventListener(
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

document.addEventListener(
    'keydown',
    function(event) {

        if (
            event.key === 'Escape'
        ) {

            closeModal();
        }
    }
);


/* ============================================================
   27. Reset
============================================================ */

function resetMock() {

    showConfirm(
        'モックデータを初期化する',
        `
            <p>
                すべてのモックデータを初期状態に戻します。
            </p>

            <p>
                この操作は取り消せません。
            </p>
        `,
        '初期化する',
        () => {

            state =
                clone(defaultData);

            memoryStorage =
                null;

            /*
             * localStorage が利用可能なら保存。
             * sandbox ならメモリ上だけ初期状態に戻る。
             */
            saveState();

            closeModal();

            navigate('home');

            toast(
                'モックデータを初期状態に戻しました。'
            );
        },
        'danger'
    );
}


/* ============================================================
   28. Global compatibility API
   ============================================================
   既存HTMLや外部デバッグ操作から呼ばれても動くよう、
   主要関数のみ window に公開する。
============================================================ */

Object.assign(
    window,
    {
        navigate,
        renderPage,
        newSurvey,
        editSurvey,
        openSurvey,
        openResponses,
        openResponseDetail,
        openSend,
        startAnswer,

        addGroup,
        renameGroup,
        deleteGroup,
        addQuestion,
        editQuestion,
        saveQuestionEdit,
        deleteQuestion,

        publishSurvey,
        startSurvey,
        endSurvey,
        archiveSurvey,
        deleteSurvey,
        saveSurvey,

        selectAllCustomers,
        clearCustomers,
        toggleCustomer,
        executeSend,

        saveKintone,
        testKintone,
        saveSmtp,
        testSmtp,

        answerNext,
        answerBack,
        submitAnswer,

        showModal,
        showConfirm,
        closeModal,
        showValidationErrors,

        resetMock
    }
);


/* ============================================================
   29. Initialization
============================================================ */

function initializeMock() {

    try {

        /*
         * ここで初めて state を作る。
         *
         * defaultData は必ずこのコードより前に
         * 定義されている。
         */
        state =
            loadState();

        if (
            !state ||
            !Array.isArray(state.surveys)
        ) {

            state =
                clone(defaultData);
        }

        if (
            !state.currentSurveyId &&
            state.surveys.length
        ) {

            state.currentSurveyId =
                state.surveys[0].id;
        }

        if (
            !state.currentPage ||
            !pageTitles[state.currentPage]
        ) {

            state.currentPage =
                'home';
        }

        saveState();

        navigate(
            state.currentPage
        );

    } catch (error) {

        console.error(
            'モック初期化エラー:',
            error
        );

        /*
         * Storageエラー等があっても、
         * 初期データから起動できるようにする。
         */
        try {

            state =
                clone(defaultData);

            state.currentPage =
                'home';

            state.currentSurveyId =
                state.surveys[0]?.id ||
                null;

            /*
             * 保存に失敗しても画面描画は続行。
             */
            try {
                saveState();
            } catch (_) {}

            navigate('home');

        } catch (fatalError) {

            console.error(
                'モックの再初期化にも失敗しました。',
                fatalError
            );

            const root =
                document.getElementById(
                    'appContent'
                );

            if (root) {

                root.innerHTML = `

                    <div class="error-box">

                        <strong>
                            モック画面の初期化に失敗しました。
                        </strong>

                        <p>
                            ブラウザを再読み込みしてください。
                        </p>

                        <button
                            type="button"
                            class="btn btn-danger"
                            onclick="location.reload()">
                            再読み込み
                        </button>

                    </div>
                `;
            }
        }
    }
}


/* ============================================================
   30. Start
============================================================ */

if (
    document.readyState ===
    'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        initializeMock,
        {
            once:true
        }
    );

} else {

    initializeMock();
}

</script>

</body>
</html>
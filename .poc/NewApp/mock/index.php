<?php
/*
 * アンケート管理アプリ モック
 * Apache + PHP / 1ファイル構成
 *
 * 実データベース、kintone、SMTPには接続しません。
 * ブラウザの localStorage を利用してモック状態を保持します。
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
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --success: #16a34a;
    --warning: #d97706;
    --danger: #dc2626;
    --info: #0891b2;
    --gray-50: #f8fafc;
    --gray-100: #f1f5f9;
    --gray-200: #e2e8f0;
    --gray-300: #cbd5e1;
    --gray-400: #94a3b8;
    --gray-500: #64748b;
    --gray-600: #475569;
    --gray-700: #334155;
    --gray-800: #1e293b;
    --gray-900: #0f172a;
    --white: #fff;
    --shadow: 0 2px 10px rgba(15,23,42,.08);
    --radius: 10px;
}

* {
    box-sizing: border-box;
}

html, body {
    margin: 0;
    padding: 0;
    min-height: 100%;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    background: var(--gray-50);
    color: var(--gray-800);
    font-size: 14px;
}

button,
input,
select,
textarea {
    font: inherit;
}

button {
    cursor: pointer;
}

a {
    color: inherit;
    text-decoration: none;
}

/* =========================
   Layout
========================= */

.app {
    min-height: 100vh;
    display: flex;
}

.sidebar {
    width: 250px;
    background: #172033;
    color: #fff;
    position: fixed;
    inset: 0 auto 0 0;
    overflow-y: auto;
    z-index: 30;
}

.logo {
    height: 68px;
    display: flex;
    align-items: center;
    padding: 0 22px;
    border-bottom: 1px solid rgba(255,255,255,.1);
    font-size: 18px;
    font-weight: 700;
}

.logo small {
    display: block;
    font-size: 10px;
    font-weight: 400;
    color: #94a3b8;
    margin-top: 3px;
}

.nav {
    padding: 14px 10px;
}

.nav-section {
    color: #64748b;
    font-size: 11px;
    margin: 15px 10px 7px;
    font-weight: 700;
}

.nav button {
    width: 100%;
    border: 0;
    background: transparent;
    color: #cbd5e1;
    text-align: left;
    padding: 10px 12px;
    border-radius: 7px;
    margin-bottom: 2px;
}

.nav button:hover,
.nav button.active {
    background: #26344f;
    color: #fff;
}

.nav button .icon {
    width: 22px;
    display: inline-block;
    opacity: .9;
}

.main {
    margin-left: 250px;
    width: calc(100% - 250px);
    min-height: 100vh;
}

.topbar {
    height: 68px;
    background: #fff;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 28px;
    position: sticky;
    top: 0;
    z-index: 20;
}

.topbar-title {
    font-size: 17px;
    font-weight: 700;
}

.user {
    color: var(--gray-500);
    font-size: 13px;
}

.content {
    padding: 28px;
    max-width: 1600px;
    margin: 0 auto;
}

/* =========================
   Common
========================= */

.page-head {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    align-items: flex-start;
    margin-bottom: 22px;
}

.page-title {
    margin: 0;
    font-size: 24px;
    color: var(--gray-900);
}

.page-description {
    margin: 7px 0 0;
    color: var(--gray-500);
}

.actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.btn {
    border: 1px solid var(--gray-300);
    background: #fff;
    color: var(--gray-700);
    padding: 8px 13px;
    border-radius: 7px;
    font-weight: 600;
    line-height: 1.2;
}

.btn:hover {
    background: var(--gray-50);
}

.btn-primary {
    color: #fff;
    background: var(--primary);
    border-color: var(--primary);
}

.btn-primary:hover {
    background: var(--primary-dark);
}

.btn-success {
    color: #fff;
    background: var(--success);
    border-color: var(--success);
}

.btn-warning {
    color: #fff;
    background: var(--warning);
    border-color: var(--warning);
}

.btn-danger {
    color: #fff;
    background: var(--danger);
    border-color: var(--danger);
}

.btn-info {
    color: #fff;
    background: var(--info);
    border-color: var(--info);
}

.btn-sm {
    padding: 6px 9px;
    font-size: 12px;
}

.btn-link {
    border: 0;
    background: transparent;
    color: var(--primary);
    padding: 2px 4px;
    font-weight: 600;
}

.card {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    margin-bottom: 20px;
}

.card-head {
    padding: 16px 18px;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.card-title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
}

.card-body {
    padding: 18px;
}

.muted {
    color: var(--gray-500);
}

.small {
    font-size: 12px;
}

.text-danger {
    color: var(--danger);
}

.text-success {
    color: var(--success);
}

.text-warning {
    color: var(--warning);
}

.text-primary {
    color: var(--primary);
}

/* =========================
   Status
========================= */

.status {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
    border: 1px solid transparent;
}

.status-draft {
    color: #475569;
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.status-wait {
    color: #92400e;
    background: #fef3c7;
    border-color: #fcd34d;
}

.status-active {
    color: #166534;
    background: #dcfce7;
    border-color: #86efac;
}

.status-ended {
    color: #1e40af;
    background: #dbeafe;
    border-color: #93c5fd;
}

.status-archived {
    color: #475569;
    background: #e2e8f0;
    border-color: #cbd5e1;
}

/* =========================
   Dashboard
========================= */

.stat-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}

.stat-card {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 18px;
    cursor: pointer;
}

.stat-card:hover {
    border-color: var(--primary);
}

.stat-label {
    color: var(--gray-500);
    font-size: 12px;
}

.stat-number {
    font-size: 30px;
    font-weight: 800;
    margin: 7px 0 0;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.dashboard-full {
    grid-column: 1 / -1;
}

/* =========================
   Tables
========================= */

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th,
td {
    padding: 11px 12px;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: middle;
    text-align: left;
}

th {
    background: var(--gray-50);
    color: var(--gray-600);
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

tr:last-child td {
    border-bottom: 0;
}

.actions-cell {
    white-space: nowrap;
}

.actions-cell .btn {
    margin: 2px;
}

/* =========================
   Forms
========================= */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.form-grid .full {
    grid-column: 1 / -1;
}

.form-group {
    margin-bottom: 14px;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-label {
    display: block;
    font-weight: 700;
    margin-bottom: 6px;
    color: var(--gray-700);
}

.form-label .required {
    color: var(--danger);
    margin-left: 4px;
}

input[type="text"],
input[type="email"],
input[type="datetime-local"],
input[type="number"],
input[type="password"],
select,
textarea {
    width: 100%;
    border: 1px solid var(--gray-300);
    border-radius: 7px;
    padding: 9px 10px;
    background: #fff;
    color: var(--gray-800);
}

textarea {
    min-height: 100px;
    resize: vertical;
}

input:focus,
select:focus,
textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}

.readonly-field {
    background: var(--gray-100) !important;
    color: var(--gray-500) !important;
}

.help {
    margin-top: 5px;
    font-size: 12px;
    color: var(--gray-500);
}

.error-box {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #991b1b;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 15px;
}

.error-box ul {
    margin: 7px 0 0 18px;
    padding: 0;
}

.success-box {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #166534;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 15px;
}

.info-box {
    border: 1px solid #bae6fd;
    background: #f0f9ff;
    color: #075985;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 15px;
}

/* =========================
   Editor
========================= */

.editor-layout {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    gap: 18px;
}

.editor-sidebar {
    position: sticky;
    top: 88px;
    align-self: start;
}

.editor-nav-item {
    width: 100%;
    border: 0;
    background: transparent;
    padding: 10px 12px;
    text-align: left;
    border-radius: 7px;
    margin-bottom: 3px;
}

.editor-nav-item:hover,
.editor-nav-item.active {
    background: #eff6ff;
    color: var(--primary);
}

.group-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.group-box {
    border: 1px solid var(--gray-200);
    border-radius: 9px;
    background: #fff;
}

.group-head {
    padding: 12px 14px;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.group-name {
    font-weight: 700;
}

.question-list {
    min-height: 20px;
    padding: 8px;
}

.question-card {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    margin-bottom: 8px;
    padding: 12px;
    background: #fff;
}

.question-card:last-child {
    margin-bottom: 0;
}

.question-card.dragging {
    opacity: .45;
}

.question-card.drag-over {
    border-color: var(--primary);
    background: #eff6ff;
}

.question-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
}

.drag-handle {
    cursor: grab;
    color: var(--gray-400);
    font-size: 18px;
    padding-right: 7px;
}

.question-title {
    flex: 1;
}

.question-number {
    color: var(--primary);
    font-weight: 800;
}

.question-actions {
    white-space: nowrap;
}

.choice-list {
    margin-top: 10px;
    display: grid;
    gap: 7px;
}

.choice-row {
    display: flex;
    gap: 7px;
}

.choice-row input {
    flex: 1;
}

.branch-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: 8px;
    padding: 9px;
    background: var(--gray-50);
    border-radius: 7px;
}

/* =========================
   Preview / Answer
========================= */

.preview-shell {
    max-width: 900px;
    margin: 0 auto;
}

.preview-header {
    padding: 26px;
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    margin-bottom: 16px;
}

.answer-progress {
    margin: 15px 0 20px;
}

.progress-track {
    height: 8px;
    background: var(--gray-200);
    border-radius: 999px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: var(--primary);
    border-radius: 999px;
}

.answer-question {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 18px;
    margin-bottom: 13px;
}

.answer-question-title {
    font-weight: 700;
    margin-bottom: 12px;
    line-height: 1.7;
}

.required-label {
    color: var(--danger);
    font-size: 11px;
    border: 1px solid #fecaca;
    background: #fef2f2;
    padding: 2px 6px;
    border-radius: 999px;
    margin-left: 6px;
}

.option {
    margin: 8px 0;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.option input {
    margin-top: 3px;
}

.answer-footer {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-top: 18px;
}

/* =========================
   KPI
========================= */

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.kpi {
    border: 1px solid var(--gray-200);
    border-radius: 9px;
    padding: 15px;
}

.kpi-label {
    font-size: 12px;
    color: var(--gray-500);
}

.kpi-value {
    font-size: 26px;
    font-weight: 800;
    margin-top: 5px;
}

/* =========================
   Modal / Toast
========================= */

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.48);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100;
    padding: 20px;
}

.modal-backdrop.show {
    display: flex;
}

.modal {
    width: min(620px, 100%);
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(15,23,42,.25);
}

.modal-head {
    padding: 17px 20px;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 14px 20px;
    border-top: 1px solid var(--gray-200);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.modal-close {
    border: 0;
    background: transparent;
    color: var(--gray-500);
    font-size: 22px;
}

.toast {
    position: fixed;
    right: 20px;
    bottom: 20px;
    background: #172033;
    color: #fff;
    padding: 12px 16px;
    border-radius: 8px;
    box-shadow: var(--shadow);
    z-index: 200;
    display: none;
}

.toast.show {
    display: block;
}

/* =========================
   Responsive
========================= */

@media (max-width: 1100px) {
    .stat-grid {
        grid-template-columns: repeat(3, 1fr);
    }

    .editor-layout {
        grid-template-columns: 1fr;
    }

    .editor-sidebar {
        position: static;
    }
}

@media (max-width: 800px) {
    .sidebar {
        width: 68px;
    }

    .logo {
        padding: 0;
        justify-content: center;
        font-size: 0;
    }

    .logo::before {
        content: "A";
        font-size: 22px;
        font-weight: 800;
    }

    .logo small,
    .nav-section,
    .nav button span:not(.icon) {
        display: none;
    }

    .nav button {
        text-align: center;
        padding: 11px 4px;
    }

    .main {
        margin-left: 68px;
        width: calc(100% - 68px);
    }

    .content {
        padding: 18px;
    }

    .dashboard-grid {
        grid-template-columns: 1fr;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-grid .full {
        grid-column: auto;
    }

    .stat-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 500px) {
    .stat-grid {
        grid-template-columns: 1fr;
    }

    .page-head {
        flex-direction: column;
    }

    .topbar {
        padding: 0 14px;
    }

    .content {
        padding: 12px;
    }

    .kpi-grid {
        grid-template-columns: 1fr;
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

            <button data-page="editor" onclick="newSurvey()">
                <span class="icon">＋</span>
                <span>新規作成</span>
            </button>

            <div class="nav-section">回答</div>

            <button data-page="responses" onclick="navigate('responses')">
                <span class="icon">▥</span>
                <span>回答状況</span>
            </button>

            <button data-page="response-detail" onclick="openResponseDetail()">
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

const STORAGE_KEY = 'questionnaire_mock_v2';

const defaultData = {
    currentSurveyId: 1,
    currentPage: 'home',

    settings: {
        kintone: {
            host: 'https://example.cybozu.com',
            app: '123',
            nameField: '顧客名',
            contactField: '担当者名',
            emailField: 'メールアドレス',
            connected: true,
            updatedAt: '2026-09-16 09:30'
        },
        smtp: {
            host: 'smtp.example.jp',
            port: '587',
            from: 'questionnaire@example.jp',
            encryption: 'STARTTLS',
            configured: true
        }
    },

    customers: [
        { id: 1, name: '株式会社青山商事', contact: '田中 太郎', email: 'tanaka@example.jp' },
        { id: 2, name: '株式会社赤坂商会', contact: '佐藤 花子', email: 'sato@example.jp' },
        { id: 3, name: '東京サンプル株式会社', contact: '鈴木 一郎', email: 'suzuki@example.jp' },
        { id: 4, name: '港区ソリューションズ', contact: '高橋 次郎', email: 'takahashi@example.jp' },
        { id: 5, name: 'サンプル製作所', contact: '伊藤 三郎', email: 'ito@example.jp' },
        { id: 6, name: '見本産業株式会社', contact: '渡辺 美咲', email: 'watanabe@example.jp' }
    ],

    surveys: [
        {
            id: 1,
            name: '2026年度 顧客満足度アンケート',
            description: 'サービスをご利用いただいたお客様への満足度調査です。',
            guidance: '各質問にご回答ください。所要時間は約5分です。',
            completeMessage: 'ご回答ありがとうございました。',
            status: 'active',
            createdAt: '2026-08-01',
            updatedAt: '2026-09-15 16:20',
            startAt: '2026-09-01T09:00',
            endAt: '2026-09-30T18:00',
            numberMode: 'global',
            sentCount: 80,
            responseCount: 42,
            selectedCustomerIds: [1,2,3,4,5,6],
            lastSentAt: '2026-09-10 10:00',

            groups: [
                { id: 'g1', name: '基本情報' },
                { id: 'g2', name: 'サービス評価' }
            ],

            questions: [
                {
                    id: 'q1',
                    groupId: 'g1',
                    text: '当社サービスを利用したことがありますか？',
                    type: 'single',
                    required: true,
                    help: '',
                    choices: [
                        { id: 'c1', text: 'はい' },
                        { id: 'c2', text: 'いいえ' }
                    ],
                    branches: {
                        c1: { type: 'next' },
                        c2: { type: 'question', target: 'q4' }
                    }
                },
                {
                    id: 'q2',
                    groupId: 'g2',
                    text: 'サービスの満足度を教えてください。',
                    type: 'rating',
                    required: true,
                    help: '1が最低、5が最高です。',
                    choices: ['1','2','3','4','5'].map((x,i) => ({
                        id: 'r' + (i+1),
                        text: x
                    })),
                    branches: {}
                },
                {
                    id: 'q3',
                    groupId: 'g2',
                    text: '改善してほしい点があれば教えてください。',
                    type: 'text',
                    required: false,
                    help: '',
                    choices: [],
                    branches: {}
                },
                {
                    id: 'q4',
                    groupId: 'g2',
                    text: '今後利用してみたいサービスを教えてください。',
                    type: 'multiple',
                    required: false,
                    help: '',
                    choices: [
                        { id: 'm1', text: 'オンラインサポート' },
                        { id: 'm2', text: 'レポート機能' },
                        { id: 'm3', text: 'コンサルティング' }
                    ],
                    branches: {}
                }
            ],

            answers: [
                {
                    id: 1,
                    number: 'R-0001',
                    answeredAt: '2026-09-12 10:21',
                    respondent: '田中 太郎',
                    values: {
                        q1: 'はい',
                        q2: '5',
                        q3: '特にありません。',
                        q4: ['レポート機能']
                    }
                },
                {
                    id: 2,
                    number: 'R-0002',
                    answeredAt: '2026-09-12 14:05',
                    respondent: '佐藤 花子',
                    values: {
                        q1: 'はい',
                        q2: '4',
                        q3: 'サポート時間を増やしてほしい。',
                        q4: ['オンラインサポート']
                    }
                },
                {
                    id: 3,
                    number: 'R-0003',
                    answeredAt: '2026-09-13 09:12',
                    respondent: '鈴木 一郎',
                    values: {
                        q1: 'いいえ',
                        q4: ['コンサルティング']
                    }
                }
            ]
        },

        {
            id: 2,
            name: '新サービス利用意向調査',
            description: '新サービスについての利用意向を確認します。',
            guidance: '簡単なアンケートです。',
            completeMessage: 'ご回答ありがとうございました。',
            status: 'wait',
            createdAt: '2026-09-03',
            updatedAt: '2026-09-14 11:10',
            startAt: '2026-09-20T09:00',
            endAt: '2026-10-10T18:00',
            numberMode: 'group',
            sentCount: 25,
            responseCount: 0,
            selectedCustomerIds: [1,2,3],
            lastSentAt: '2026-09-15 09:30',

            groups: [
                { id: 'g1', name: '利用意向' },
                { id: 'g2', name: 'ご意見' }
            ],

            questions: [
                {
                    id: 'q1',
                    groupId: 'g1',
                    text: '新サービスを利用したいと思いますか？',
                    type: 'single',
                    required: true,
                    help: '',
                    choices: [
                        { id: 'c1', text: 'ぜひ利用したい' },
                        { id: 'c2', text: '検討したい' },
                        { id: 'c3', text: '利用予定はない' }
                    ],
                    branches: {}
                },
                {
                    id: 'q2',
                    groupId: 'g2',
                    text: 'ご意見があれば教えてください。',
                    type: 'text',
                    required: false,
                    help: '',
                    choices: [],
                    branches: {}
                }
            ],

            answers: []
        },

        {
            id: 3,
            name: '2026年 上期サービス調査',
            description: '上期のサービス利用状況調査です。',
            guidance: '',
            completeMessage: 'ご協力ありがとうございました。',
            status: 'ended',
            createdAt: '2026-04-01',
            updatedAt: '2026-09-01 18:00',
            startAt: '2026-04-10T09:00',
            endAt: '2026-08-31T18:00',
            numberMode: 'global',
            sentCount: 120,
            responseCount: 95,
            selectedCustomerIds: [],
            lastSentAt: '2026-04-10 09:00',

            groups: [
                { id: 'g1', name: 'サービス評価' }
            ],

            questions: [
                {
                    id: 'q1',
                    groupId: 'g1',
                    text: 'サービス全体の満足度を教えてください。',
                    type: 'rating',
                    required: true,
                    help: '',
                    choices: ['1','2','3','4','5'].map((x,i) => ({
                        id: 'r' + i,
                        text: x
                    })),
                    branches: {}
                }
            ],

            answers: []
        },

        {
            id: 4,
            name: '2025年度 利用者アンケート',
            description: '昨年度の利用者アンケートです。',
            guidance: '',
            completeMessage: 'ありがとうございました。',
            status: 'archived',
            createdAt: '2025-04-01',
            updatedAt: '2026-04-01 10:00',
            startAt: '2025-04-10T09:00',
            endAt: '2025-09-30T18:00',
            numberMode: 'global',
            sentCount: 100,
            responseCount: 81,
            selectedCustomerIds: [],
            lastSentAt: '2025-04-10 09:00',

            groups: [
                { id: 'g1', name: 'アンケート' }
            ],

            questions: [
                {
                    id: 'q1',
                    groupId: 'g1',
                    text: 'サービスに満足していますか？',
                    type: 'single',
                    required: true,
                    help: '',
                    choices: [
                        { id: 'c1', text: 'はい' },
                        { id: 'c2', text: 'いいえ' }
                    ],
                    branches: {}
                }
            ],

            answers: []
        }
    ],

    sendResults: [
        {
            id: 1,
            surveyId: 1,
            target: 80,
            success: 78,
            failed: 2,
            sentAt: '2026-09-10 10:00',
            failedCustomers: ['メールアドレス不備：株式会社青山商事', '送信エラー：港区ソリューションズ']
        }
    ]
};

let state = loadState();

function loadState() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) {
            return JSON.parse(raw);
        }
    } catch (e) {
        console.warn(e);
    }

    return JSON.parse(JSON.stringify(defaultData));
}

function saveState() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}

function resetMock() {
    if (!confirm('モックデータを初期状態へ戻します。よろしいですか？')) return;
    state = JSON.parse(JSON.stringify(defaultData));
    saveState();
    navigate('home');
    toast('モックデータを初期化しました。');
}

/* =========================================================
   Helpers
========================================================= */

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function uid(prefix) {
    return prefix + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2,7);
}

function currentSurvey() {
    return state.surveys.find(s => Number(s.id) === Number(state.currentSurveyId)) || state.surveys[0];
}

function setCurrentSurvey(id) {
    state.currentSurveyId = Number(id);
    saveState();
}

function statusLabel(status) {
    return {
        draft: '作成中',
        wait: '公開済み・回答開始待ち',
        active: '回答受付中',
        ended: '回答受付終了',
        archived: '保管'
    }[status] || status;
}

function statusClass(status) {
    return {
        draft: 'status-draft',
        wait: 'status-wait',
        active: 'status-active',
        ended: 'status-ended',
        archived: 'status-archived'
    }[status] || 'status-draft';
}

function statusBadge(status) {
    return `<span class="status ${statusClass(status)}">${escapeHtml(statusLabel(status))}</span>`;
}

function formatDate(value) {
    if (!value) return '-';
    return String(value).replace('T', ' ');
}

function responseRate(survey) {
    if (!survey.sentCount) return null;
    return Math.round((survey.responseCount / survey.sentCount) * 100);
}

function allQuestions(survey) {
    return survey.questions || [];
}

function getGroup(survey, groupId) {
    return survey.groups.find(g => g.id === groupId);
}

function questionNumber(survey, question) {
    if (survey.numberMode === 'group') {
        const sameGroup = survey.questions.filter(q => q.groupId === question.groupId);
        return sameGroup.findIndex(q => q.id === question.id) + 1;
    }

    return survey.questions.findIndex(q => q.id === question.id) + 1;
}

function questionTypeLabel(type) {
    return {
        text: '文章を入力する',
        single: '1つだけ選ぶ',
        multiple: '複数選ぶ',
        rating: '段階で評価する'
    }[type] || type;
}

function canEdit(survey) {
    return survey.status === 'draft' || survey.status === 'wait';
}

function canStructuralEdit(survey) {
    return survey.status === 'draft';
}

function countByStatus(status) {
    return state.surveys.filter(s => s.status === status).length;
}

function selectedSurveyIdFromPage() {
    return Number(state.currentSurveyId || 1);
}

/* =========================================================
   Navigation
========================================================= */

const pageTitles = {
    home: 'ホーム',
    surveys: 'アンケート一覧',
    editor: 'アンケート編集',
    preview: '公開前確認',
    responses: '回答状況',
    'response-detail': '回答内容',
    send: 'アンケート送付',
    customers: '顧客選択',
    'send-confirm': '送付確認',
    'send-result': '送付結果',
    settings: '各種設定',
    answer: '回答者向けアンケート',
    answer-confirm: '回答確認',
    answer-complete: '回答完了'
};

function navigate(page) {
    state.currentPage = page;
    saveState();

    const title = pageTitles[page] || 'アンケート管理';
    document.getElementById('topbarTitle').textContent = title;

    document.querySelectorAll('.nav button').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.page === page);
    });

    renderPage();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function renderPage() {
    const root = document.getElementById('appContent');

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

        case 'customers':
            root.innerHTML = renderCustomers();
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
            root.innerHTML = renderHome();
    }

    bindPageEvents();
}

/* =========================================================
   Home
========================================================= */

function renderHome() {
    const surveys = state.surveys;

    const recent = [...surveys]
        .sort((a,b) => String(b.updatedAt).localeCompare(String(a.updatedAt)))
        .slice(0, 5);

    const needSend = surveys.filter(s =>
        ['wait','active'].includes(s.status) &&
        Number(s.sentCount || 0) === 0
    );

    const needResponseCheck = surveys.filter(s =>
        ['wait','active','ended','archived'].includes(s.status) &&
        Number(s.responseCount || 0) > 0
    ).slice(0,5);

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">ホーム</h1>
                <p class="page-description">
                    アンケートの運営状況と次に行う操作を確認できます。
                </p>
            </div>
            <div class="actions">
                <button class="btn btn-primary" onclick="newSurvey()">＋ 新しいアンケートを作成する</button>
            </div>
        </div>

        <div class="stat-grid">
            ${statCard('作成中', countByStatus('draft'), 'draft')}
            ${statCard('公開済み・回答開始待ち', countByStatus('wait'), 'wait')}
            ${statCard('回答受付中', countByStatus('active'), 'active')}
            ${statCard('回答受付終了', countByStatus('ended'), 'ended')}
            ${statCard('保管', countByStatus('archived'), 'archived')}
        </div>

        <div class="dashboard-grid">

            <div class="card">
                <div class="card-head">
                    <h2 class="card-title">最近更新したアンケート</h2>
                    <button class="btn btn-sm" onclick="navigate('surveys')">一覧を見る</button>
                </div>
                <div class="card-body">
                    ${recent.length ? `
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
                                    ${recent.map(s => `
                                        <tr>
                                            <td><strong>${escapeHtml(s.name)}</strong></td>
                                            <td>${statusBadge(s.status)}</td>
                                            <td>${escapeHtml(s.updatedAt)}</td>
                                            <td>${s.responseCount}件</td>
                                            <td>
                                                <button class="btn-link" onclick="openSurvey(${s.id})">開く</button>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    ` : `<div class="muted">該当するアンケートはありません。</div>`}
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2 class="card-title">送付が必要なアンケート</h2>
                    <button class="btn btn-sm" onclick="openSend()">送付画面</button>
                </div>
                <div class="card-body">
                    ${needSend.length ? needSend.map(s => `
                        <div style="padding:12px 0;border-bottom:1px solid var(--gray-200)">
                            <div style="display:flex;justify-content:space-between;gap:10px">
                                <strong>${escapeHtml(s.name)}</strong>
                                ${statusBadge(s.status)}
                            </div>
                            <div class="small muted" style="margin-top:6px">
                                送付数：${s.sentCount}件
                            </div>
                            <div style="margin-top:8px">
                                <button class="btn btn-sm btn-primary" onclick="openSend(${s.id})">
                                    アンケートを送付する
                                </button>
                            </div>
                        </div>
                    `).join('') : `
                        <div class="success-box">
                            現在、送付が必要なアンケートはありません。
                        </div>
                    `}
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2 class="card-title">回答状況を確認したいアンケート</h2>
                    <button class="btn btn-sm" onclick="navigate('responses')">回答状況一覧</button>
                </div>
                <div class="card-body">
                    ${needResponseCheck.length ? `
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>アンケート名</th>
                                        <th>状態</th>
                                        <th>送付数</th>
                                        <th>回答数</th>
                                        <th>回答率</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${needResponseCheck.map(s => `
                                        <tr>
                                            <td>${escapeHtml(s.name)}</td>
                                            <td>${statusBadge(s.status)}</td>
                                            <td>${s.sentCount}</td>
                                            <td>${s.responseCount}</td>
                                            <td>${responseRate(s) === null ? '-' : responseRate(s) + '%'}</td>
                                            <td>
                                                <button class="btn btn-sm" onclick="openResponses(${s.id})">
                                                    回答状況を見る
                                                </button>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    ` : `<div class="muted">回答状況を確認できるアンケートはありません。</div>`}
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2 class="card-title">現在運営中のアンケート</h2>
                </div>
                <div class="card-body">
                    ${surveys.filter(s => ['wait','active'].includes(s.status)).map(s => `
                        <div style="padding:12px 0;border-bottom:1px solid var(--gray-200)">
                            <div style="display:flex;justify-content:space-between;gap:10px">
                                <strong>${escapeHtml(s.name)}</strong>
                                ${statusBadge(s.status)}
                            </div>
                            <div class="small muted" style="margin-top:6px">
                                受付期間：
                                ${formatDate(s.startAt)}
                                ～
                                ${formatDate(s.endAt)}
                            </div>
                            <div class="actions" style="margin-top:9px">
                                <button class="btn btn-sm" onclick="openResponses(${s.id})">回答状況を見る</button>
                                <button class="btn btn-sm" onclick="openResponseDetail(${s.id})">回答内容を見る</button>
                                <button class="btn btn-sm btn-primary" onclick="openSend(${s.id})">送付する</button>
                            </div>
                        </div>
                    `).join('') || `<div class="muted">ありません。</div>`}
                </div>
            </div>

        </div>

        <div class="card" style="margin-top:20px">
            <div class="card-head">
                <h2 class="card-title">モック操作</h2>
            </div>
            <div class="card-body">
                <div class="info-box">
                    この画面はモックです。データはブラウザの localStorage に保存されます。
                    実際のkintone・SMTPには接続しません。
                </div>
                <button class="btn btn-danger" onclick="resetMock()">モックデータを初期化する</button>
            </div>
        </div>
    `;
}

function statCard(label, number, status) {
    return `
        <div class="stat-card" onclick="filterStatus('${status}')">
            <div class="stat-label">${escapeHtml(label)}</div>
            <div class="stat-number">${number}</div>
        </div>
    `;
}

function filterStatus(status) {
    state.listFilter = status;
    navigate('surveys');
}

/* =========================================================
   Survey List
========================================================= */

function renderSurveyList() {
    const filter = state.listFilter || 'all';

    let surveys = state.surveys;

    if (filter !== 'all') {
        surveys = surveys.filter(s => s.status === filter);
    }

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">アンケート一覧</h1>
                <p class="page-description">
                    作成したアンケートの状態、送付数、回答状況を確認できます。
                </p>
            </div>
            <div class="actions">
                <button class="btn" onclick="state.listFilter='all';renderPage()">すべて</button>
                <button class="btn btn-primary" onclick="newSurvey()">＋ 新規作成</button>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <strong>${filter === 'all' ? 'すべてのアンケート' : statusLabel(filter)}</strong>
                    <span class="small muted">（${surveys.length}件）</span>
                </div>

                <select onchange="state.listFilter=this.value;renderPage()" style="width:220px">
                    <option value="all" ${filter === 'all' ? 'selected' : ''}>すべて</option>
                    <option value="draft" ${filter === 'draft' ? 'selected' : ''}>作成中</option>
                    <option value="wait" ${filter === 'wait' ? 'selected' : ''}>公開済み・回答開始待ち</option>
                    <option value="active" ${filter === 'active' ? 'selected' : ''}>回答受付中</option>
                    <option value="ended" ${filter === 'ended' ? 'selected' : ''}>回答受付終了</option>
                    <option value="archived" ${filter === 'archived' ? 'selected' : ''}>保管</option>
                </select>
            </div>

            <div class="card-body">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>アンケート名</th>
                                <th>状態</th>
                                <th>作成日</th>
                                <th>更新日</th>
                                <th>回答受付開始</th>
                                <th>回答受付終了</th>
                                <th>送付数</th>
                                <th>回答数</th>
                                <th>回答状況</th>
                                <th>主な操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${surveys.length ? surveys.map(s => surveyRow(s)).join('') : `
                                <tr>
                                    <td colspan="10" class="muted" style="text-align:center;padding:30px">
                                        該当するアンケートはありません。
                                    </td>
                                </tr>
                            `}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
}

function surveyRow(s) {
    const rate = responseRate(s);

    return `
        <tr>
            <td>
                <strong>${escapeHtml(s.name)}</strong>
                <div class="small muted">${s.questions.length}問</div>
            </td>
            <td>${statusBadge(s.status)}</td>
            <td>${escapeHtml(s.createdAt)}</td>
            <td>${escapeHtml(s.updatedAt)}</td>
            <td>${formatDate(s.startAt)}</td>
            <td>${formatDate(s.endAt)}</td>
            <td>${s.sentCount}件</td>
            <td>${s.responseCount}件</td>
            <td>
                ${rate === null ? '-' : `${rate}%`}
            </td>
            <td class="actions-cell">
                <button class="btn btn-sm" onclick="openSurvey(${s.id})">内容を見る</button>
                ${s.status === 'draft' ? `
                    <button class="btn btn-sm" onclick="editSurvey(${s.id})">編集する</button>
                    <button class="btn btn-sm btn-primary" onclick="openPreview(${s.id})">公開前確認</button>
                ` : ''}
                ${s.status === 'wait' ? `
                    <button class="btn btn-sm" onclick="startSurvey(${s.id})">回答受付を開始する</button>
                    <button class="btn btn-sm" onclick="openSend(${s.id})">送付する</button>
                ` : ''}
                ${s.status === 'active' ? `
                    <button class="btn btn-sm" onclick="openResponses(${s.id})">回答状況</button>
                    <button class="btn btn-sm" onclick="openResponseDetail(${s.id})">回答内容</button>
                    <button class="btn btn-sm" onclick="openSend(${s.id})">送付する</button>
                    <button class="btn btn-sm btn-warning" onclick="endSurvey(${s.id})">回答受付を終了する</button>
                ` : ''}
                ${s.status === 'ended' ? `
                    <button class="btn btn-sm" onclick="openResponses(${s.id})">回答状況</button>
                    <button class="btn btn-sm" onclick="openResponseDetail(${s.id})">回答内容</button>
                    <button class="btn btn-sm btn-primary" onclick="archiveSurvey(${s.id})">保管する</button>
                ` : ''}
                ${s.status === 'archived' ? `
                    <button class="btn btn-sm" onclick="openResponses(${s.id})">回答状況</button>
                    <button class="btn btn-sm" onclick="openResponseDetail(${s.id})">回答内容</button>
                ` : ''}
                ${s.status === 'draft' ? `
                    <button class="btn btn-sm btn-danger" onclick="deleteSurvey(${s.id})">削除</button>
                ` : ''}
            </td>
        </tr>
    `;
}

/* =========================================================
   Editor
========================================================= */

let editorQuestionDragId = null;

function newSurvey() {
    const newId = Math.max(0, ...state.surveys.map(s => Number(s.id))) + 1;

    const survey = {
        id: newId,
        name: '',
        description: '',
        guidance: '',
        completeMessage: 'ご回答ありがとうございました。',
        status: 'draft',
        createdAt: new Date().toISOString().slice(0,10),
        updatedAt: new Date().toISOString().slice(0,16).replace('T',' '),
        startAt: '',
        endAt: '',
        numberMode: 'global',
        sentCount: 0,
        responseCount: 0,
        selectedCustomerIds: [],
        lastSentAt: '',
        groups: [
            { id: uid('g'), name: '基本情報' }
        ],
        questions: [],
        answers: []
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
    const survey = currentSurvey();

    if (survey.status === 'draft' || survey.status === 'wait') {
        navigate('editor');
    } else {
        navigate('responses');
    }
}

function renderEditor() {
    const survey = currentSurvey();

    if (!survey) {
        return `<div class="error-box">アンケートが見つかりません。</div>`;
    }

    const structuralLocked = !canStructuralEdit(survey);
    const editable = canEdit(survey);

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">${survey.id ? 'アンケート編集' : 'アンケート作成'}</h1>
                <p class="page-description">
                    現在の状態：${statusBadge(survey.status)}
                </p>
            </div>

            <div class="actions">
                ${survey.status === 'draft' ? `
                    <button class="btn" onclick="saveSurvey()">保存する</button>
                    <button class="btn btn-primary" onclick="openPreview(${survey.id})">公開前確認</button>
                ` : ''}
                ${survey.status === 'wait' ? `
                    <button class="btn" onclick="saveSurvey()">変更を保存する</button>
                    <button class="btn btn-primary" onclick="startSurvey(${survey.id})">回答受付を開始する</button>
                ` : ''}
                ${survey.status !== 'draft' && survey.status !== 'wait' ? `
                    <button class="btn" onclick="openResponses(${survey.id})">回答状況を見る</button>
                ` : ''}
            </div>
        </div>

        ${structuralLocked ? `
            <div class="info-box">
                このアンケートは公開済みです。
                回答データとの整合性を保つため、質問文・質問種類・選択肢・グループ構成・分岐などの
                構造変更はできません。
            </div>
        ` : ''}

        <div class="editor-layout">

            <div class="card editor-sidebar">
                <div class="card-head">
                    <h2 class="card-title">編集メニュー</h2>
                </div>
                <div class="card-body">
                    <button class="editor-nav-item active" onclick="document.getElementById('basic').scrollIntoView({behavior:'smooth'})">
                        基本情報
                    </button>
                    <button class="editor-nav-item" onclick="document.getElementById('questions').scrollIntoView({behavior:'smooth'})">
                        質問・グループ
                    </button>
                    <button class="editor-nav-item" onclick="document.getElementById('branch').scrollIntoView({behavior:'smooth'})">
                        分岐設定
                    </button>
                    <button class="editor-nav-item" onclick="openPreview(${survey.id})">
                        公開前確認
                    </button>
                </div>
            </div>

            <div>

                <div class="card" id="basic">
                    <div class="card-head">
                        <h2 class="card-title">基本情報</h2>
                    </div>

                    <div class="card-body">
                        <div id="editorErrors"></div>

                        <div class="form-grid">

                            <div class="form-group full">
                                <label class="form-label">
                                    アンケート名 <span class="required">*</span>
                                </label>
                                <input
                                    id="surveyName"
                                    type="text"
                                    value="${escapeHtml(survey.name)}"
                                    ${!editable ? 'disabled' : ''}
                                    placeholder="例：2026年度 顧客満足度アンケート"
                                >
                            </div>

                            <div class="form-group full">
                                <label class="form-label">説明文</label>
                                <textarea id="surveyDescription" ${!editable ? 'disabled' : ''}>${escapeHtml(survey.description)}</textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">回答受付開始日時</label>
                                <input
                                    id="surveyStartAt"
                                    type="datetime-local"
                                    value="${escapeHtml(survey.startAt)}"
                                    ${!editable ? 'disabled' : ''}
                                >
                                <div class="help">
                                    公開時点で未来の場合は「公開済み・回答開始待ち」になります。
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">回答受付終了日時</label>
                                <input
                                    id="surveyEndAt"
                                    type="datetime-local"
                                    value="${escapeHtml(survey.endAt)}"
                                    ${!editable ? 'disabled' : ''}
                                >
                            </div>

                            <div class="form-group">
                                <label class="form-label">回答者への案内文</label>
                                <textarea id="surveyGuidance" ${!editable ? 'disabled' : ''}>${escapeHtml(survey.guidance)}</textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">完了時のメッセージ</label>
                                <textarea id="surveyCompleteMessage" ${!editable ? 'disabled' : ''}>${escapeHtml(survey.completeMessage)}</textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">質問番号</label>
                                <select id="numberMode" ${structuralLocked ? 'disabled' : ''}>
                                    <option value="global" ${survey.numberMode === 'global' ? 'selected' : ''}>
                                        アンケート全体で連番
                                    </option>
                                    <option value="group" ${survey.numberMode === 'group' ? 'selected' : ''}>
                                        グループごとに連番
                                    </option>
                                </select>
                            </div>

                        </div>

                        ${survey.status === 'wait' ? `
                            <div class="info-box" style="margin-top:15px;margin-bottom:0">
                                公開済み・回答開始待ちです。公開済みのため質問構造は変更できません。
                                案内文などの非構造情報は変更できます。
                            </div>
                        ` : ''}
                    </div>
                </div>

                <div class="card" id="questions">
                    <div class="card-head">
                        <div>
                            <h2 class="card-title">質問・グループ</h2>
                            <div class="small muted">ドラッグ＆ドロップで質問の順番・グループを変更できます。</div>
                        </div>

                        ${!structuralLocked ? `
                            <div class="actions">
                                <button class="btn btn-sm" onclick="addGroup()">＋ グループ追加</button>
                                <button class="btn btn-sm btn-primary" onclick="addQuestion()">＋ 質問追加</button>
                            </div>
                        ` : ''}
                    </div>

                    <div class="card-body">
                        <div id="groupList" class="group-list">
                            ${survey.groups.map(group => renderGroupEditor(survey, group, structuralLocked)).join('')}

                            <div
                                class="group-box"
                                ondragover="allowDrop(event)"
                                ondrop="dropQuestionToGroup(event, null)"
                            >
                                <div class="group-head">
                                    <div>
                                        <div class="group-name">グループ未設定</div>
                                        <div class="small muted">グループに所属していない質問</div>
                                    </div>
                                </div>

                                <div class="question-list">
                                    ${survey.questions.filter(q => !survey.groups.some(g => g.id === q.groupId)).map(q =>
                                        renderQuestionEditor(survey, q, structuralLocked)
                                    ).join('') || '<div class="small muted" style="padding:8px">質問はありません。</div>'}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card" id="branch">
                    <div class="card-head">
                        <h2 class="card-title">分岐設定</h2>
                    </div>
                    <div class="card-body">
                        ${renderBranchSummary(survey)}
                    </div>
                </div>

            </div>
        </div>
    `;
}

function renderGroupEditor(survey, group, locked) {
    const questions = survey.questions.filter(q => q.groupId === group.id);

    return `
        <div
            class="group-box"
            data-group-id="${escapeHtml(group.id)}"
            ondragover="allowDrop(event)"
            ondrop="dropQuestionToGroup(event, '${escapeHtml(group.id)}')"
        >
            <div class="group-head">
                <div style="flex:1">
                    ${locked ? `
                        <div class="group-name">${escapeHtml(group.name)}</div>
                    ` : `
                        <input
                            type="text"
                            value="${escapeHtml(group.name)}"
                            onchange="renameGroup('${escapeHtml(group.id)}', this.value)"
                            style="font-weight:700"
                        >
                    `}
                    <div class="small muted">${questions.length}問</div>
                </div>

                ${!locked ? `
                    <button class="btn btn-sm btn-danger" onclick="deleteGroup('${escapeHtml(group.id)}')">
                        グループ削除
                    </button>
                ` : ''}
            </div>

            <div
                class="question-list"
                data-group-id="${escapeHtml(group.id)}"
            >
                ${questions.length
                    ? questions.map(q => renderQuestionEditor(survey, q, locked)).join('')
                    : '<div class="small muted" style="padding:8px">ここに質問を追加できます。</div>'
                }
            </div>
        </div>
    `;
}

function renderQuestionEditor(survey, q, locked) {
    const groupOptions = survey.groups.map(g => `
        <option value="${escapeHtml(g.id)}" ${q.groupId === g.id ? 'selected' : ''}>
            ${escapeHtml(g.name)}
        </option>
    `).join('');

    return `
        <div
            class="question-card"
            draggable="${locked ? 'false' : 'true'}"
            data-question-id="${escapeHtml(q.id)}"
            ondragstart="dragQuestion(event, '${escapeHtml(q.id)}')"
            ondragover="questionDragOver(event)"
            ondragleave="questionDragLeave(event)"
            ondrop="dropQuestion(event, '${escapeHtml(q.id)}')"
            ondragend="dragQuestionEnd(event)"
        >
            <div class="question-head">
                <div style="display:flex;flex:1">
                    ${!locked ? '<div class="drag-handle">⋮⋮</div>' : ''}
                    <div class="question-title">
                        <div>
                            <span class="question-number">
                                ${questionNumber(survey,q)}.
                            </span>
                            <strong>${escapeHtml(q.text || '未入力の質問')}</strong>
                            ${q.required ? '<span class="required-label">必須</span>' : ''}
                        </div>
                        <div class="small muted" style="margin-top:5px">
                            ${escapeHtml(questionTypeLabel(q.type))}
                            ${q.groupId ? ' / ' + escapeHtml(getGroup(survey,q.groupId)?.name || '不明') : ' / グループ未設定'}
                        </div>
                    </div>
                </div>

                ${!locked ? `
                    <div class="question-actions">
                        <button class="btn btn-sm" onclick="editQuestion('${escapeHtml(q.id)}')">編集</button>
                        <button class="btn btn-sm btn-danger" onclick="deleteQuestion('${escapeHtml(q.id)}')">削除</button>
                    </div>
                ` : ''}
            </div>

            ${q.help ? `
                <div class="small muted" style="margin-top:8px">
                    ${escapeHtml(q.help)}
                </div>
            ` : ''}

            ${q.choices && q.choices.length ? `
                <div class="choice-list">
                    ${q.choices.map(c => `
                        <div class="small">
                            ・ ${escapeHtml(typeof c === 'string' ? c : c.text)}
                        </div>
                    `).join('')}
                </div>
            ` : ''}

            ${q.type === 'single' && q.choices && q.choices.length ? `
                <div style="margin-top:12px">
                    <div class="small" style="font-weight:700;margin-bottom:5px">分岐</div>
                    ${q.choices.map(c => {
                        const choice = typeof c === 'string'
                            ? { id: 'c_'+c, text:c }
                            : c;
                        const b = q.branches?.[choice.id] || {type:'next'};
                        return `
                            <div class="branch-row">
                                <div>${escapeHtml(choice.text)}</div>
                                <div class="small">
                                    → ${escapeHtml(branchTargetLabel(survey,b))}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            ` : ''}
        </div>
    `;
}

function branchTargetLabel(survey, branch) {
    if (!branch) return '未設定';

    switch (branch.type) {
        case 'next':
            return '次の質問';
        case 'end':
            return '回答終了';
        case 'question': {
            const q = survey.questions.find(x => x.id === branch.target);
            return q ? `質問${questionNumber(survey,q)}：${q.text}` : '存在しない質問';
        }
        case 'group': {
            const g = survey.groups.find(x => x.id === branch.target);
            return g ? `グループ：${g.name}` : '存在しないグループ';
        }
        default:
            return '未設定';
    }
}

function renderBranchSummary(survey) {
    const branchQuestions = survey.questions.filter(q => q.type === 'single');

    if (!branchQuestions.length) {
        return `<div class="muted">「1つだけ選ぶ」質問がないため、分岐設定はありません。</div>`;
    }

    return `
        <div class="info-box">
            分岐は「1つだけ選ぶ」質問で設定できます。
            各選択肢について、次の質問・特定の質問・特定のグループ・回答終了を指定できます。
        </div>

        ${branchQuestions.map(q => `
            <div style="border:1px solid var(--gray-200);border-radius:8px;margin-bottom:12px;padding:14px">
                <div style="font-weight:700">
                    質問${questionNumber(survey,q)}：${escapeHtml(q.text)}
                </div>

                ${q.choices.map(c => {
                    const choice = typeof c === 'string'
                        ? {id:'c_'+c,text:c}
                        : c;

                    const b = q.branches?.[choice.id] || {type:'next'};

                    return `
                        <div style="margin-top:9px;padding:9px;background:var(--gray-50);border-radius:6px">
                            <strong>${escapeHtml(choice.text)}</strong>
                            <span class="muted"> → ${escapeHtml(branchTargetLabel(survey,b))}</span>
                        </div>
                    `;
                }).join('')}
            </div>
        `).join('')}
    `;
}

function addGroup() {
    const survey = currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後はグループ構成を変更できません。');
        return;
    }

    survey.groups.push({
        id: uid('g'),
        name: '新しいグループ'
    });

    survey.updatedAt = nowString();
    saveState();
    renderPage();
}

function renameGroup(id, name) {
    const survey = currentSurvey();
    const group = survey.groups.find(g => g.id === id);

    if (!group) return;

    group.name = name.trim() || '名称未設定グループ';
    survey.updatedAt = nowString();
    saveState();
}

function deleteGroup(id) {
    const survey = currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後はグループを削除できません。');
        return;
    }

    const group = survey.groups.find(g => g.id === id);
    if (!group) return;

    const questions = survey.questions.filter(q => q.groupId === id);

    showConfirm(
        'グループを削除する',
        `
            <p>「${escapeHtml(group.name)}」を削除します。</p>
            <p>所属している質問は「グループ未設定」になります。</p>
        `,
        'グループを削除する',
        () => {
            questions.forEach(q => q.groupId = null);
            survey.groups = survey.groups.filter(g => g.id !== id);
            survey.updatedAt = nowString();
            saveState();
            renderPage();
            toast('グループを削除しました。');
        },
        'danger'
    );
}

function addQuestion() {
    const survey = currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後は質問構造を変更できません。');
        return;
    }

    if (!survey.groups.length) {
        survey.groups.push({
            id: uid('g'),
            name: '基本情報'
        });
    }

    const question = {
        id: uid('q'),
        groupId: survey.groups[0].id,
        text: '',
        type: 'text',
        required: false,
        help: '',
        choices: [],
        branches: {}
    };

    survey.questions.push(question);
    survey.updatedAt = nowString();

    saveState();
    renderPage();

    setTimeout(() => editQuestion(question.id), 50);
}

function editQuestion(questionId) {
    const survey = currentSurvey();
    const q = survey.questions.find(x => x.id === questionId);

    if (!q) return;

    const locked = !canStructuralEdit(survey);

    if (locked) {
        showModal(
            '質問内容',
            renderQuestionReadonly(survey,q),
            `<button class="btn" onclick="closeModal()">閉じる</button>`
        );
        return;
    }

    showModal(
        '質問を編集する',
        renderQuestionForm(survey,q),
        `
            <button class="btn" onclick="closeModal()">キャンセル</button>
            <button class="btn btn-primary" onclick="saveQuestionEdit('${escapeHtml(q.id)}')">保存する</button>
        `
    );

    bindQuestionTypeEvents();
}

function renderQuestionReadonly(survey,q) {
    return `
        <div class="form-group">
            <label class="form-label">質問文</label>
            <div>${escapeHtml(q.text)}</div>
        </div>

        <div class="form-group">
            <label class="form-label">質問の種類</label>
            <div>${escapeHtml(questionTypeLabel(q.type))}</div>
        </div>

        <div class="form-group">
            <label class="form-label">必須</label>
            <div>${q.required ? '必須' : '任意'}</div>
        </div>

        ${q.choices?.length ? `
            <div class="form-group">
                <label class="form-label">選択肢</label>
                ${q.choices.map(c => `<div>・${escapeHtml(typeof c === 'string' ? c : c.text)}</div>`).join('')}
            </div>
        ` : ''}

        ${q.help ? `
            <div class="form-group">
                <label class="form-label">補足説明</label>
                <div>${escapeHtml(q.help)}</div>
            </div>
        ` : ''}
    `;
}

function renderQuestionForm(survey,q) {
    return `
        <div class="form-group">
            <label class="form-label">質問文 <span class="required">*</span></label>
            <textarea id="editQText">${escapeHtml(q.text)}</textarea>
        </div>

        <div class="form-group">
            <label class="form-label">質問の種類</label>
            <select id="editQType">
                <option value="text" ${q.type === 'text' ? 'selected' : ''}>文章を入力する</option>
                <option value="single" ${q.type === 'single' ? 'selected' : ''}>1つだけ選ぶ</option>
                <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選ぶ</option>
                <option value="rating" ${q.type === 'rating' ? 'selected' : ''}>段階で評価する</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">所属グループ</label>
            <select id="editQGroup">
                <option value="">グループ未設定</option>
                ${survey.groups.map(g => `
                    <option value="${escapeHtml(g.id)}" ${q.groupId === g.id ? 'selected' : ''}>
                        ${escapeHtml(g.name)}
                    </option>
                `).join('')}
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">
                <input id="editQRequired" type="checkbox" ${q.required ? 'checked' : ''}>
                必須回答にする
            </label>
        </div>

        <div class="form-group">
            <label class="form-label">補足説明</label>
            <textarea id="editQHelp">${escapeHtml(q.help || '')}</textarea>
        </div>

        <div id="choiceEditor"></div>
    `;
}

function bindQuestionTypeEvents() {
    const type = document.getElementById('editQType');
    if (!type) return;

    type.addEventListener('change', renderChoiceEditorFromModal);
    renderChoiceEditorFromModal();
}

function renderChoiceEditorFromModal() {
    const type = document.getElementById('editQType')?.value;
    const box = document.getElementById('choiceEditor');

    if (!box) return;

    if (!['single','multiple','rating'].includes(type)) {
        box.innerHTML = '';
        return;
    }

    const survey = currentSurvey();

    // 現在編集中の質問を推定するため、モーダルを開く前の選択値は
    // saveQuestionEdit側で保持する。
    const q = survey.questions.find(x => x.id === state.editingQuestionId);

    let choices = q?.choices || [];

    if (!choices.length && type === 'rating') {
        choices = ['1','2','3','4','5'].map((x,i) => ({
            id: uid('c'),
            text: x
        }));
    }

    box.innerHTML = `
        <label class="form-label">選択肢</label>

        <div id="modalChoices">
            ${choices.map((c,index) => {
                const text = typeof c === 'string' ? c : c.text;
                const id = typeof c === 'string' ? 'choice_' + index : c.id;

                return `
                    <div class="choice-row" data-choice-id="${escapeHtml(id)}">
                        <input type="text" value="${escapeHtml(text)}">
                        <button class="btn btn-sm btn-danger" type="button"
                            onclick="this.parentElement.remove()">
                            削除
                        </button>
                    </div>
                `;
            }).join('')}
        </div>

        ${type !== 'rating' ? `
            <button class="btn btn-sm" type="button" onclick="addModalChoice()">
                ＋ 選択肢を追加
            </button>
        ` : ''}
    `;
}

function addModalChoice() {
    const list = document.getElementById('modalChoices');
    if (!list) return;

    const id = uid('c');

    const div = document.createElement('div');
    div.className = 'choice-row';
    div.dataset.choiceId = id;
    div.innerHTML = `
        <input type="text" value="">
        <button class="btn btn-sm btn-danger" type="button" onclick="this.parentElement.remove()">
            削除
        </button>
    `;

    list.appendChild(div);
}

function saveQuestionEdit(questionId) {
    const survey = currentSurvey();
    const q = survey.questions.find(x => x.id === questionId);

    if (!q) return;

    const text = document.getElementById('editQText').value.trim();
    const type = document.getElementById('editQType').value;
    const groupId = document.getElementById('editQGroup').value || null;
    const required = document.getElementById('editQRequired').checked;
    const help = document.getElementById('editQHelp').value.trim();

    if (!text) {
        toast('質問文を入力してください。');
        return;
    }

    let choices = [];

    if (['single','multiple','rating'].includes(type)) {
        document.querySelectorAll('#modalChoices .choice-row input').forEach(input => {
            const value = input.value.trim();

            if (value) {
                choices.push({
                    id: uid('c'),
                    text: value
                });
            }
        });

        if (!choices.length) {
            toast('選択肢を1つ以上入力してください。');
            return;
        }
    }

    q.text = text;
    q.type = type;
    q.groupId = groupId;
    q.required = required;
    q.help = help;
    q.choices = choices;

    if (type !== 'single') {
        q.branches = {};
    }

    survey.updatedAt = nowString();

    saveState();
    closeModal();
    renderPage();
    toast('質問を保存しました。');
}

function deleteQuestion(questionId) {
    const survey = currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後は質問を削除できません。');
        return;
    }

    const q = survey.questions.find(x => x.id === questionId);
    if (!q) return;

    const references = [];

    survey.questions.forEach(other => {
        if (!other.branches) return;

        Object.entries(other.branches).forEach(([choiceId,branch]) => {
            if (branch.type === 'question' && branch.target === questionId) {
                references.push(other);
            }
        });
    });

    showConfirm(
        '質問を削除する',
        `
            <p>「${escapeHtml(q.text || '未入力の質問')}」を削除します。</p>
            ${references.length ? `
                <div class="error-box">
                    この質問は分岐先として使用されています。
                    削除すると分岐設定の修正が必要になります。
                </div>
            ` : ''}
        `,
        '質問を削除する',
        () => {
            survey.questions = survey.questions.filter(x => x.id !== questionId);

            references.forEach(parent => {
                Object.keys(parent.branches || {}).forEach(key => {
                    if (parent.branches[key].type === 'question' &&
                        parent.branches[key].target === questionId) {
                        delete parent.branches[key];
                    }
                });
            });

            survey.updatedAt = nowString();
            saveState();
            renderPage();
            toast('質問を削除しました。');
        },
        'danger'
    );
}

/* =========================================================
   Drag & Drop
========================================================= */

function dragQuestion(event, questionId) {
    editorQuestionDragId = questionId;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', questionId);

    event.currentTarget.classList.add('dragging');
}

function allowDrop(event) {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
}

function questionDragOver(event) {
    event.preventDefault();
    event.currentTarget.classList.add('drag-over');
}

function questionDragLeave(event) {
    event.currentTarget.classList.remove('drag-over');
}

function dragQuestionEnd(event) {
    event.currentTarget.classList.remove('dragging');
    document.querySelectorAll('.question-card').forEach(el => {
        el.classList.remove('drag-over');
    });
}

function dropQuestion(event, targetQuestionId) {
    event.preventDefault();

    const survey = currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後は質問の順番を変更できません。');
        return;
    }

    const sourceId = editorQuestionDragId || event.dataTransfer.getData('text/plain');

    if (!sourceId || sourceId === targetQuestionId) return;

    const sourceIndex = survey.questions.findIndex(q => q.id === sourceId);
    const targetIndex = survey.questions.findIndex(q => q.id === targetQuestionId);

    if (sourceIndex < 0 || targetIndex < 0) return;

    const [item] = survey.questions.splice(sourceIndex,1);
    survey.questions.splice(targetIndex,0,item);

    survey.updatedAt = nowString();
    saveState();
    renderPage();
}

function dropQuestionToGroup(event, groupId) {
    event.preventDefault();

    const survey = currentSurvey();

    if (!canStructuralEdit(survey)) {
        toast('公開後はグループを変更できません。');
        return;
    }

    const sourceId = editorQuestionDragId || event.dataTransfer.getData('text/plain');
    const q = survey.questions.find(x => x.id === sourceId);

    if (!q) return;

    q.groupId = groupId || null;
    survey.updatedAt = nowString();

    saveState();
    renderPage();
}

/* =========================================================
   Preview
========================================================= */

function openPreview(id) {
    setCurrentSurvey(id);

    const survey = currentSurvey();
    const validation = validateSurvey(survey);

    if (validation.errors.length) {
        showValidationErrors(validation.errors);
        return;
    }

    navigate('preview');
}

function validateSurvey(survey) {
    const errors = [];

    if (!survey.name.trim()) {
        errors.push('アンケート名を入力してください。');
    }

    if (!survey.questions.length) {
        errors.push('質問を1件以上登録してください。');
    }

    survey.questions.forEach((q,index) => {
        if (!q.text.trim()) {
            errors.push(`質問${index + 1}：質問文を入力してください。`);
        }

        if (['single','multiple','rating'].includes(q.type) &&
            (!q.choices || !q.choices.length)) {
            errors.push(`質問${index + 1}：選択肢を1件以上設定してください。`);
        }

        if (q.type === 'single') {
            q.choices.forEach(choice => {
                const branch = q.branches?.[choice.id];

                if (!branch) {
                    errors.push(`質問${index + 1}「${choice.text}」の分岐先が設定されていません。`);
                    return;
                }

                if (branch.type === 'question') {
                    const exists = survey.questions.some(x => x.id === branch.target);
                    if (!exists) {
                        errors.push(`質問${index + 1}「${choice.text}」の分岐先質問が存在しません。`);
                    }
                }

                if (branch.type === 'group') {
                    const exists = survey.groups.some(x => x.id === branch.target);
                    if (!exists) {
                        errors.push(`質問${index + 1}「${choice.text}」の分岐先グループが存在しません。`);
                    }
                }
            });
        }
    });

    if (survey.startAt && survey.endAt) {
        if (survey.startAt >= survey.endAt) {
            errors.push('回答受付開始日時は回答受付終了日時より前に設定してください。');
        }
    }

    const branchCycle = detectBranchCycle(survey);

    if (branchCycle) {
        errors.push('分岐設定が循環しており、回答者が正常に進めない可能性があります。');
    }

    return {
        valid: errors.length === 0,
        errors
    };
}

function detectBranchCycle(survey) {
    const graph = {};

    survey.questions.forEach(q => {
        graph[q.id] = [];

        if (q.type !== 'single') return;

        Object.values(q.branches || {}).forEach(branch => {
            if (branch.type === 'question' && branch.target) {
                graph[q.id].push(branch.target);
            }
        });
    });

    const visiting = new Set();
    const visited = new Set();

    function dfs(node) {
        if (visiting.has(node)) return true;
        if (visited.has(node)) return false;

        visiting.add(node);

        for (const next of (graph[node] || [])) {
            if (dfs(next)) return true;
        }

        visiting.delete(node);
        visited.add(node);

        return false;
    }

    return Object.keys(graph).some(dfs);
}

function renderPreview() {
    const survey = currentSurvey();
    const validation = validateSurvey(survey);

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">公開前確認</h1>
                <p class="page-description">
                    回答者からどのように見えるか確認してから公開します。
                </p>
            </div>

            <div class="actions">
                <button class="btn" onclick="navigate('editor')">編集画面に戻る</button>
                <button class="btn btn-primary" onclick="publishSurvey(${survey.id})">公開する</button>
            </div>
        </div>

        ${validation.errors.length ? `
            <div class="error-box">
                <strong>公開前チェックで問題が見つかりました。</strong>
                <ul>
                    ${validation.errors.map(e => `<li>${escapeHtml(e)}</li>`).join('')}
                </ul>
            </div>
        ` : `
            <div class="success-box">
                公開前チェックをすべて通過しています。
            </div>
        `}

        <div class="preview-shell">

            <div class="preview-header">
                <div class="small muted">アンケート名</div>
                <h2 style="margin:5px 0 10px">${escapeHtml(survey.name || '未設定')}</h2>

                ${survey.description ? `
                    <p style="white-space:pre-wrap">${escapeHtml(survey.description)}</p>
                ` : ''}

                ${survey.guidance ? `
                    <div class="info-box">${escapeHtml(survey.guidance)}</div>
                ` : ''}

                <div class="small muted">
                    回答受付：
                    ${formatDate(survey.startAt)}
                    ～
                    ${formatDate(survey.endAt)}
                </div>
            </div>

            ${survey.groups.map(group => `
                <div class="card">
                    <div class="card-head">
                        <h2 class="card-title">${escapeHtml(group.name)}</h2>
                    </div>

                    <div class="card-body">
                        ${survey.questions
                            .filter(q => q.groupId === group.id)
                            .map(q => renderPreviewQuestion(survey,q))
                            .join('') || '<div class="muted">質問なし</div>'}
                    </div>
                </div>
            `).join('')}

            ${survey.questions.filter(q => !survey.groups.some(g => g.id === q.groupId)).length ? `
                <div class="card">
                    <div class="card-head">
                        <h2 class="card-title">グループ未設定</h2>
                    </div>
                    <div class="card-body">
                        ${survey.questions
                            .filter(q => !survey.groups.some(g => g.id === q.groupId))
                            .map(q => renderPreviewQuestion(survey,q))
                            .join('')}
                    </div>
                </div>
            ` : ''}

            <div class="card">
                <div class="card-body">
                    <strong>完了時の表示</strong>
                    <p>${escapeHtml(survey.completeMessage)}</p>
                </div>
            </div>

        </div>
    `;
}

function renderPreviewQuestion(survey,q) {
    return `
        <div class="answer-question">
            <div class="answer-question-title">
                ${questionNumber(survey,q)}.
                ${escapeHtml(q.text || '未入力')}
                ${q.required ? '<span class="required-label">必須</span>' : ''}
            </div>

            ${renderQuestionInput(q, true)}

            ${q.help ? `
                <div class="help">${escapeHtml(q.help)}</div>
            ` : ''}
        </div>
    `;
}

function renderQuestionInput(q, disabled) {
    const attr = disabled ? 'disabled' : '';

    switch (q.type) {
        case 'text':
            return `<textarea ${attr} placeholder="回答を入力してください"></textarea>`;

        case 'single':
            return q.choices.map(c => `
                <label class="option">
                    <input type="radio" name="preview_${q.id}" ${attr}>
                    <span>${escapeHtml(typeof c === 'string' ? c : c.text)}</span>
                </label>
            `).join('');

        case 'multiple':
            return q.choices.map(c => `
                <label class="option">
                    <input type="checkbox" ${attr}>
                    <span>${escapeHtml(typeof c === 'string' ? c : c.text)}</span>
                </label>
            `).join('');

        case 'rating':
            return `
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    ${q.choices.map(c => `
                        <label style="border:1px solid var(--gray-300);padding:8px 13px;border-radius:7px">
                            <input type="radio" name="preview_${q.id}" ${attr}>
                            ${escapeHtml(typeof c === 'string' ? c : c.text)}
                        </label>
                    `).join('')}
                </div>
            `;

        default:
            return '';
    }
}

function publishSurvey(id) {
    const survey = state.surveys.find(s => Number(s.id) === Number(id));
    if (!survey) return;

    const validation = validateSurvey(survey);

    if (validation.errors.length) {
        showValidationErrors(validation.errors);
        return;
    }

    showConfirm(
        'アンケートを公開する',
        `
            <p>「${escapeHtml(survey.name)}」を公開します。</p>
            <p>公開後は質問構造や分岐などの変更に制限があります。</p>
            <p>
                回答開始日時：
                <strong>${formatDate(survey.startAt) || '未設定（公開後すぐ開始）'}</strong>
            </p>
        `,
        '公開する',
        () => {
            const now = new Date();

            if (survey.startAt) {
                const start = new Date(survey.startAt);

                if (start > now) {
                    survey.status = 'wait';
                } else {
                    survey.status = 'active';
                }
            } else {
                survey.status = 'active';
            }

            survey.updatedAt = nowString();

            saveState();
            closeModal();
            navigate('surveys');
            toast('アンケートを公開しました。');
        },
        'primary'
    );
}

/* =========================================================
   Survey State
========================================================= */

function startSurvey(id) {
    const survey = state.surveys.find(s => Number(s.id) === Number(id));
    if (!survey) return;

    if (survey.status !== 'wait') {
        toast('回答受付開始できる状態ではありません。');
        return;
    }

    showConfirm(
        '回答受付を開始する',
        `
            <p>「${escapeHtml(survey.name)}」の回答受付を開始します。</p>
            <p>開始後は回答者がアンケートに回答できるようになります。</p>
        `,
        '回答受付を開始する',
        () => {
            survey.status = 'active';
            survey.updatedAt = nowString();
            saveState();
            closeModal();
            navigate('surveys');
            toast('回答受付を開始しました。');
        },
        'primary'
    );
}

function endSurvey(id) {
    const survey = state.surveys.find(s => Number(s.id) === Number(id));
    if (!survey) return;

    showConfirm(
        '回答受付を終了する',
        `
            <p>「${escapeHtml(survey.name)}」の回答受付を終了します。</p>
            <p>終了すると新しい回答を受け付けなくなります。</p>
        `,
        '回答受付を終了する',
        () => {
            survey.status = 'ended';
            survey.updatedAt = nowString();
            saveState();
            closeModal();
            navigate('surveys');
            toast('回答受付を終了しました。');
        },
        'warning'
    );
}

function archiveSurvey(id) {
    const survey = state.surveys.find(s => Number(s.id) === Number(id));
    if (!survey) return;

    showConfirm(
        'アンケートを保管する',
        `
            <p>「${escapeHtml(survey.name)}」を保管します。</p>
            <p>保管後は再公開・再送付できません。</p>
        `,
        '保管する',
        () => {
            survey.status = 'archived';
            survey.updatedAt = nowString();
            saveState();
            closeModal();
            navigate('surveys');
            toast('アンケートを保管しました。');
        },
        'primary'
    );
}

function deleteSurvey(id) {
    const survey = state.surveys.find(s => Number(s.id) === Number(id));
    if (!survey) return;

    showConfirm(
        'アンケートを削除する',
        `
            <p>「${escapeHtml(survey.name || '名称未設定')}」を削除します。</p>
            <p class="text-danger">このモックでは削除後の復元はできません。</p>
        `,
        '削除する',
        () => {
            state.surveys = state.surveys.filter(s => Number(s.id) !== Number(id));
            state.currentSurveyId = state.surveys[0]?.id || null;

            saveState();
            closeModal();
            navigate('surveys');
            toast('アンケートを削除しました。');
        },
        'danger'
    );
}

function saveSurvey() {
    const survey = currentSurvey();
    if (!survey) return;

    const name = document.getElementById('surveyName')?.value.trim() || '';
    const description = document.getElementById('surveyDescription')?.value || '';
    const startAt = document.getElementById('surveyStartAt')?.value || '';
    const endAt = document.getElementById('surveyEndAt')?.value || '';
    const guidance = document.getElementById('surveyGuidance')?.value || '';
    const completeMessage = document.getElementById('surveyCompleteMessage')?.value || '';
    const numberMode = document.getElementById('numberMode')?.value || 'global';

    const errors = [];

    if (!name) {
        errors.push('アンケート名を入力してください。');
    }

    if (startAt && endAt && startAt >= endAt) {
        errors.push('回答受付開始日時は終了日時より前にしてください。');
    }

    if (errors.length) {
        showValidationErrors(errors, 'editorErrors');
        return;
    }

    survey.name = name;
    survey.description = description;
    survey.startAt = startAt;
    survey.endAt = endAt;
    survey.guidance = guidance;
    survey.completeMessage = completeMessage;
    survey.numberMode = numberMode;
    survey.updatedAt = nowString();

    saveState();
    renderPage();
    toast('保存しました。');
}

/* =========================================================
   Responses
========================================================= */

function openResponses(id) {
    setCurrentSurvey(id);
    navigate('responses');
}

function renderResponses() {
    const survey = currentSurvey();

    if (!survey) {
        return `<div class="error-box">アンケートが見つかりません。</div>`;
    }

    const rate = responseRate(survey);

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">回答状況</h1>
                <p class="page-description">
                    ${escapeHtml(survey.name)}
                </p>
            </div>

            <div class="actions">
                <button class="btn" onclick="navigate('surveys')">一覧へ戻る</button>
                <button class="btn" onclick="openResponseDetail(${survey.id})">回答内容を見る</button>
                ${['wait','active'].includes(survey.status) ? `
                    <button class="btn btn-primary" onclick="openSend(${survey.id})">アンケートを送付する</button>
                ` : ''}
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                    <div>${statusBadge(survey.status)}</div>
                    <div class="muted">
                        回答受付期間：
                        ${formatDate(survey.startAt)}
                        ～
                        ${formatDate(survey.endAt)}
                    </div>
                </div>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="kpi card">
                <div class="kpi-label">送付数</div>
                <div class="kpi-value">${survey.sentCount}</div>
            </div>
            <div class="kpi card">
                <div class="kpi-label">回答数</div>
                <div class="kpi-value">${survey.responseCount}</div>
            </div>
            <div class="kpi card">
                <div class="kpi-label">回答率</div>
                <div class="kpi-value">${rate === null ? '-' : rate + '%'}</div>
            </div>
            <div class="kpi card">
                <div class="kpi-label">未回答数</div>
                <div class="kpi-value">${Math.max(0, survey.sentCount - survey.responseCount)}</div>
            </div>
        </div>

        <div class="card" style="margin-top:20px">
            <div class="card-head">
                <h2 class="card-title">回答の推移</h2>
            </div>
            <div class="card-body">
                ${renderResponseChart(survey)}
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2 class="card-title">回答状況の概要</h2>
            </div>
            <div class="card-body">
                <div class="info-box">
                    ${survey.responseCount}件の回答を受け付けています。
                    送付数 ${survey.sentCount}件に対して
                    ${rate === null ? '回答率は算出できません。' : `回答率は${rate}%です。`}
                </div>
            </div>
        </div>
    `;
}

function renderResponseChart(survey) {
    const max = Math.max(1, survey.responseCount);
    const days = [
        { label:'9/10', value:Math.round(survey.responseCount * .15) },
        { label:'9/11', value:Math.round(survey.responseCount * .27) },
        { label:'9/12', value:Math.round(survey.responseCount * .55) },
        { label:'9/13', value:Math.round(survey.responseCount * .68) },
        { label:'9/14', value:Math.round(survey.responseCount * .82) },
        { label:'9/15', value:survey.responseCount }
    ];

    return `
        <div style="display:flex;align-items:flex-end;gap:14px;height:220px;padding:20px 10px 10px">
            ${days.map(d => `
                <div style="flex:1;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center">
                    <div class="small">${d.value}</div>
                    <div style="
                        width:70%;
                        max-width:65px;
                        height:${Math.max(5, Math.round((d.value / max) * 150))}px;
                        background:linear-gradient(#60a5fa,#2563eb);
                        border-radius:6px 6px 0 0
                    "></div>
                    <div class="small muted" style="margin-top:6px">${d.label}</div>
                </div>
            `).join('')}
        </div>
    `;
}

/* =========================================================
   Response Detail
========================================================= */

function openResponseDetail(id) {
    if (id) setCurrentSurvey(id);
    navigate('response-detail');
}

function renderResponseDetail() {
    const survey = currentSurvey();

    if (!survey) {
        return `<div class="error-box">アンケートが見つかりません。</div>`;
    }

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">回答内容</h1>
                <p class="page-description">${escapeHtml(survey.name)}</p>
            </div>

            <div class="actions">
                <button class="btn" onclick="openResponses(${survey.id})">回答状況へ戻る</button>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2 class="card-title">回答一覧</h2>
                <div class="small muted">${survey.answers.length}件</div>
            </div>

            <div class="card-body">
                ${survey.answers.length ? `
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>回答番号</th>
                                    <th>回答日時</th>
                                    <th>回答者</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${survey.answers.map(a => `
                                    <tr>
                                        <td>${escapeHtml(a.number)}</td>
                                        <td>${escapeHtml(a.answeredAt)}</td>
                                        <td>${escapeHtml(a.respondent)}</td>
                                        <td>
                                            <button class="btn btn-sm" onclick="viewAnswer(${a.id})">
                                                回答内容を見る
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : `
                    <div class="muted" style="padding:20px;text-align:center">
                        まだ回答はありません。
                    </div>
                `}
            </div>
        </div>
    `;
}

function viewAnswer(answerId) {
    const survey = currentSurvey();
    const answer = survey.answers.find(a => Number(a.id) === Number(answerId));

    if (!answer) return;

    showModal(
        `回答内容：${answer.number}`,
        `
            <div class="small muted" style="margin-bottom:15px">
                回答日時：${escapeHtml(answer.answeredAt)}<br>
                回答者：${escapeHtml(answer.respondent)}
            </div>

            ${survey.questions.map(q => {
                const value = answer.values?.[q.id];

                if (value === undefined) return '';

                const display = Array.isArray(value)
                    ? value.join('、')
                    : value;

                return `
                    <div style="border-bottom:1px solid var(--gray-200);padding:10px 0">
                        <div class="small muted">
                            質問${questionNumber(survey,q)}
                        </div>
                        <div style="font-weight:700;margin-top:3px">
                            ${escapeHtml(q.text)}
                        </div>
                        <div style="margin-top:6px;white-space:pre-wrap">
                            ${escapeHtml(display)}
                        </div>
                    </div>
                `;
            }).join('')}
        `,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}

/* =========================================================
   Send
========================================================= */

function openSend(id) {
    if (id) setCurrentSurvey(id);

    const survey = currentSurvey();

    if (!survey) return;

    if (survey.status === 'archived') {
        toast('保管済みアンケートは送付できません。');
        return;
    }

    if (!['wait','active'].includes(survey.status)) {
        toast('現在の状態ではアンケートを送付できません。');
        return;
    }

    state.sendDraft = {
        surveyId: survey.id,
        customerIds: [...(survey.selectedCustomerIds || [])],
        subject: `${survey.name}のご案内`,
        body: `いつもお世話になっております。\n\n以下のアンケートへのご回答をお願いいたします。\n\n${survey.name}\n\nよろしくお願いいたします。`
    };

    saveState();
    navigate('send');
}

function renderSend() {
    const survey = currentSurvey();
    const draft = state.sendDraft || {
        surveyId: survey.id,
        customerIds: [],
        subject: `${survey.name}のご案内`,
        body: ''
    };

    const selected = state.customers.filter(c => draft.customerIds.includes(c.id));

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">アンケート送付</h1>
                <p class="page-description">
                    ${escapeHtml(survey.name)}
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2 class="card-title">送付対象</h2>
            </div>
            <div class="card-body">

                <div class="info-box">
                    選択中：<strong>${selected.length}件</strong>
                </div>

                <div class="actions">
                    <button class="btn btn-primary" onclick="navigate('customers')">
                        顧客を選択する
                    </button>
                    <button class="btn" onclick="selectAllCustomers()">全選択する</button>
                    <button class="btn" onclick="clearCustomers()">全選択を解除する</button>
                </div>

                ${selected.length ? `
                    <div style="margin-top:15px">
                        ${selected.map(c => `
                            <span style="
                                display:inline-flex;
                                align-items:center;
                                gap:5px;
                                background:#eff6ff;
                                color:#1d4ed8;
                                border-radius:999px;
                                padding:5px 9px;
                                margin:3px;
                            ">
                                ${escapeHtml(c.name)}
                                <button
                                    style="border:0;background:transparent;color:#1d4ed8"
                                    onclick="toggleCustomer(${c.id})"
                                >×</button>
                            </span>
                        `).join('')}
                    </div>
                ` : ''}
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2 class="card-title">メール内容</h2>
            </div>
            <div class="card-body">

                <div class="form-group">
                    <label class="form-label">件名</label>
                    <input
                        id="sendSubject"
                        type="text"
                        value="${escapeHtml(draft.subject)}"
                        onchange="updateSendDraft()"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label">本文</label>
                    <textarea
                        id="sendBody"
                        style="min-height:220px"
                        onchange="updateSendDraft()"
                    >${escapeHtml(draft.body)}</textarea>
                </div>

                <div class="info-box">
                    実際のSMTP送信は行わず、モック上で送付結果を生成します。
                </div>

                <div class="actions">
                    <button class="btn btn-primary" onclick="openSendConfirm()">
                        送付内容を確認する
                    </button>
                </div>
            </div>
        </div>
    `;
}

function updateSendDraft() {
    if (!state.sendDraft) return;

    state.sendDraft.subject = document.getElementById('sendSubject')?.value || '';
    state.sendDraft.body = document.getElementById('sendBody')?.value || '';

    saveState();
}

function selectAllCustomers() {
    if (!state.sendDraft) return;

    state.sendDraft.customerIds = state.customers.map(c => c.id);
    saveState();
    renderPage();
}

function clearCustomers() {
    if (!state.sendDraft) return;

    state.sendDraft.customerIds = [];
    saveState();
    renderPage();
}

function toggleCustomer(id) {
    if (!state.sendDraft) return;

    const index = state.sendDraft.customerIds.indexOf(id);

    if (index >= 0) {
        state.sendDraft.customerIds.splice(index,1);
    } else {
        state.sendDraft.customerIds.push(id);
    }

    saveState();
    renderPage();
}

function renderCustomers() {
    const draft = state.sendDraft || {
        customerIds: []
    };

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">顧客選択</h1>
                <p class="page-description">
                    アンケートの送付先を選択してください。
                </p>
            </div>

            <div class="actions">
                <button class="btn" onclick="navigate('send')">送付画面へ戻る</button>
                <button class="btn btn-primary" onclick="navigate('send')">選択を確定する</button>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <strong>顧客一覧</strong>
                    <span class="small muted">選択中 ${draft.customerIds.length}件</span>
                </div>

                <div class="actions">
                    <button class="btn btn-sm" onclick="selectAllCustomers()">全選択</button>
                    <button class="btn btn-sm" onclick="clearCustomers()">全解除</button>
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
                            ${state.customers.map(c => `
                                <tr>
                                    <td>
                                        <input
                                            type="checkbox"
                                            ${draft.customerIds.includes(c.id) ? 'checked' : ''}
                                            onchange="toggleCustomer(${c.id})"
                                        >
                                    </td>
                                    <td>${escapeHtml(c.name)}</td>
                                    <td>${escapeHtml(c.contact)}</td>
                                    <td>${escapeHtml(c.email)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
}

function openSendConfirm() {
    updateSendDraft();

    const draft = state.sendDraft;
    const survey = currentSurvey();

    if (!draft.customerIds.length) {
        toast('送付先を1件以上選択してください。');
        return;
    }

    if (!draft.subject.trim()) {
        toast('メール件名を入力してください。');
        return;
    }

    if (!draft.body.trim()) {
        toast('メール本文を入力してください。');
        return;
    }

    navigate('send-confirm');
}

function renderSendConfirm() {
    const draft = state.sendDraft;
    const survey = currentSurvey();
    const selected = state.customers.filter(c => draft.customerIds.includes(c.id));

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">送付確認</h1>
                <p class="page-description">
                    送信前に送付先とメール内容を確認してください。
                </p>
            </div>

            <div class="actions">
                <button class="btn" onclick="navigate('send')">修正する</button>
                <button class="btn btn-primary" onclick="executeSend()">この内容で送付する</button>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2 class="card-title">送付情報</h2>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div>
                        <div class="small muted">アンケート名</div>
                        <strong>${escapeHtml(survey.name)}</strong>
                    </div>
                    <div>
                        <div class="small muted">送付先件数</div>
                        <strong>${selected.length}件</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2 class="card-title">送付先</h2>
            </div>
            <div class="card-body">
                ${selected.map(c => `
                    <div style="padding:8px 0;border-bottom:1px solid var(--gray-200)">
                        <strong>${escapeHtml(c.name)}</strong>
                        <span class="muted"> / ${escapeHtml(c.contact)} / ${escapeHtml(c.email)}</span>
                    </div>
                `).join('')}
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2 class="card-title">メール内容</h2>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <div class="small muted">件名</div>
                    <strong>${escapeHtml(draft.subject)}</strong>
                </div>

                <div class="form-group">
                    <div class="small muted">本文</div>
                    <div style="white-space:pre-wrap;border:1px solid var(--gray-200);padding:14px;border-radius:7px;margin-top:6px">
                        ${escapeHtml(draft.body)}
                    </div>
                </div>

                <div class="info-box">
                    送付実行後、送付対象・成功件数・失敗件数をモック上で確認できます。
                </div>
            </div>
        </div>
    `;
}

function executeSend() {
    const draft = state.sendDraft;
    const survey = currentSurvey();

    if (!draft || !draft.customerIds.length) {
        toast('送付先が選択されていません。');
        return;
    }

    showConfirm(
        'アンケートを送付する',
        `
            <p>以下の内容でアンケートを送付します。</p>
            <ul>
                <li>アンケート：${escapeHtml(survey.name)}</li>
                <li>送付先：${draft.customerIds.length}件</li>
                <li>件名：${escapeHtml(draft.subject)}</li>
            </ul>
            <p>モックでは実際のメールは送信されません。</p>
        `,
        'アンケートを送付する',
        () => {
            const target = draft.customerIds.length;

            // モックとして一部失敗させる
            const failed = target >= 5 ? 1 : 0;
            const success = target - failed;

            survey.sentCount += success;
            survey.selectedCustomerIds = [...draft.customerIds];
            survey.lastSentAt = nowString();
            survey.updatedAt = nowString();

            const failedCustomers = failed
                ? [state.customers.find(c => c.id === draft.customerIds[draft.customerIds.length - 1])?.name + '：送信エラー']
                : [];

            state.sendResults.unshift({
                id: Date.now(),
                surveyId: survey.id,
                target,
                success,
                failed,
                sentAt: nowString(),
                failedCustomers
            });

            saveState();
            closeModal();
            navigate('send-result');
            toast('アンケート送付処理が完了しました。');
        },
        'primary'
    );
}

/* =========================================================
   Send Result
========================================================= */

function renderSendResult() {
    const results = state.sendResults || [];

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">送付結果</h1>
                <p class="page-description">
                    アンケート案内メールの送付結果を確認できます。
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2 class="card-title">送付履歴</h2>
            </div>

            <div class="card-body">
                ${results.length ? `
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>アンケート</th>
                                    <th>送付対象</th>
                                    <th>送付成功</th>
                                    <th>送付失敗</th>
                                    <th>送付日時</th>
                                    <th>詳細</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${results.map(r => {
                                    const s = state.surveys.find(x => Number(x.id) === Number(r.surveyId));

                                    return `
                                        <tr>
                                            <td>${escapeHtml(s?.name || '不明')}</td>
                                            <td>${r.target}件</td>
                                            <td class="text-success">${r.success}件</td>
                                            <td class="${r.failed ? 'text-danger' : ''}">${r.failed}件</td>
                                            <td>${escapeHtml(r.sentAt)}</td>
                                            <td>
                                                <button class="btn btn-sm" onclick="showSendResult(${r.id})">
                                                    結果を見る
                                                </button>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : `
                    <div class="muted" style="text-align:center;padding:20px">
                        送付履歴はありません。
                    </div>
                `}
            </div>
        </div>
    `;
}

function showSendResult(id) {
    const result = state.sendResults.find(r => Number(r.id) === Number(id));
    if (!result) return;

    showModal(
        '送付結果',
        `
            <div class="kpi-grid">
                <div class="kpi">
                    <div class="kpi-label">送付対象</div>
                    <div class="kpi-value">${result.target}</div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">成功</div>
                    <div class="kpi-value text-success">${result.success}</div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">失敗</div>
                    <div class="kpi-value text-danger">${result.failed}</div>
                </div>
            </div>

            <div style="margin-top:18px">
                <strong>送付日時</strong>
                <div>${escapeHtml(result.sentAt)}</div>
            </div>

            ${result.failedCustomers?.length ? `
                <div style="margin-top:18px">
                    <strong>送付できなかった顧客</strong>
                    <ul>
                        ${result.failedCustomers.map(x => `<li>${escapeHtml(x)}</li>`).join('')}
                    </ul>
                </div>
            ` : ''}
        `,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}

/* =========================================================
   Settings
========================================================= */

function renderSettings() {
    const k = state.settings.kintone;
    const smtp = state.settings.smtp;

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">各種設定</h1>
                <p class="page-description">
                    kintoneとメール送信のモック設定を確認・変更できます。
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">kintone設定</h2>
                    <div class="small muted">
                        顧客一覧を取得するための設定
                    </div>
                </div>
                <div>
                    ${k.connected
                        ? '<span class="status status-active">接続確認済み</span>'
                        : '<span class="status status-ended">未確認</span>'
                    }
                </div>
            </div>

            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">接続先</label>
                        <input id="kHost" type="text" value="${escapeHtml(k.host)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">対象アプリ</label>
                        <input id="kApp" type="text" value="${escapeHtml(k.app)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">顧客名として利用する項目</label>
                        <input id="kName" type="text" value="${escapeHtml(k.nameField)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">担当者名として利用する項目</label>
                        <input id="kContact" type="text" value="${escapeHtml(k.contactField)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">メールアドレスとして利用する項目</label>
                        <input id="kEmail" type="text" value="${escapeHtml(k.emailField)}">
                    </div>
                </div>

                <div class="actions">
                    <button class="btn" onclick="saveKintone()">設定を保存する</button>
                    <button class="btn btn-primary" onclick="testKintone()">接続確認</button>
                    <button class="btn" onclick="refreshCustomers()">顧客一覧を更新する</button>
                </div>

                <div class="small muted" style="margin-top:10px">
                    最終更新：${escapeHtml(k.updatedAt || '-')}
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">メール設定</h2>
                    <div class="small muted">
                        SMTP送信設定
                    </div>
                </div>
                <div>
                    ${smtp.configured
                        ? '<span class="status status-active">設定済み</span>'
                        : '<span class="status status-ended">未設定</span>'
                    }
                </div>
            </div>

            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">SMTPサーバー</label>
                        <input id="smtpHost" type="text" value="${escapeHtml(smtp.host)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">ポート</label>
                        <input id="smtpPort" type="number" value="${escapeHtml(smtp.port)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">送信元メールアドレス</label>
                        <input id="smtpFrom" type="email" value="${escapeHtml(smtp.from)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">暗号化方式</label>
                        <select id="smtpEncryption">
                            <option value="なし" ${smtp.encryption === 'なし' ? 'selected' : ''}>なし</option>
                            <option value="STARTTLS" ${smtp.encryption === 'STARTTLS' ? 'selected' : ''}>STARTTLS</option>
                            <option value="SSL/TLS" ${smtp.encryption === 'SSL/TLS' ? 'selected' : ''}>SSL/TLS</option>
                        </select>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn" onclick="saveSmtp()">設定を保存する</button>
                    <button class="btn btn-primary" onclick="testSmtp()">設定確認</button>
                </div>
            </div>
        </div>
    `;
}

function saveKintone() {
    state.settings.kintone.host = document.getElementById('kHost').value;
    state.settings.kintone.app = document.getElementById('kApp').value;
    state.settings.kintone.nameField = document.getElementById('kName').value;
    state.settings.kintone.contactField = document.getElementById('kContact').value;
    state.settings.kintone.emailField = document.getElementById('kEmail').value;
    state.settings.kintone.updatedAt = nowString();

    saveState();
    toast('kintone設定を保存しました。');
}

function testKintone() {
    state.settings.kintone.connected = true;
    state.settings.kintone.updatedAt = nowString();
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
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}

function refreshCustomers() {
    state.settings.kintone.updatedAt = nowString();
    saveState();

    toast('顧客一覧を更新しました。（モック）');
}

function saveSmtp() {
    state.settings.smtp.host = document.getElementById('smtpHost').value;
    state.settings.smtp.port = document.getElementById('smtpPort').value;
    state.settings.smtp.from = document.getElementById('smtpFrom').value;
    state.settings.smtp.encryption = document.getElementById('smtpEncryption').value;
    state.settings.smtp.configured = true;

    saveState();
    toast('メール設定を保存しました。');
}

function testSmtp() {
    state.settings.smtp.configured = true;
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
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}

/* =========================================================
   Answerer Flow
========================================================= */

let answerState = {
    surveyId: null,
    currentIndex: 0,
    values: {},
    visibleQuestions: []
};

function startAnswer(id) {
    if (id) setCurrentSurvey(id);

    const survey = currentSurvey();

    if (!survey) return;

    if (survey.status !== 'active') {
        showModal(
            '回答できません',
            `
                <div class="error-box">
                    現在のアンケート状態は「${escapeHtml(statusLabel(survey.status))}」です。
                    回答受付中のアンケートのみ回答できます。
                </div>
            `,
            `<button class="btn" onclick="closeModal()">閉じる</button>`
        );
        return;
    }

    answerState = {
        surveyId: survey.id,
        currentIndex: 0,
        values: {},
        visibleQuestions: calculateVisibleQuestions(survey, {})
    };

    navigate('answer');
}

function calculateVisibleQuestions(survey, values) {
    const result = [];
    let index = 0;
    let guard = 0;

    while (index < survey.questions.length && guard < 100) {
        guard++;

        const q = survey.questions[index];

        if (!result.some(x => x.id === q.id)) {
            result.push(q);
        }

        if (q.type !== 'single') {
            index++;
            continue;
        }

        const value = values[q.id];

        if (!value) {
            index++;
            continue;
        }

        const choice = q.choices.find(c =>
            (typeof c === 'string' ? c : c.text) === value
        );

        const branch = choice ? q.branches?.[choice.id] : null;

        if (!branch || branch.type === 'next') {
            index++;
            continue;
        }

        if (branch.type === 'end') {
            break;
        }

        if (branch.type === 'question') {
            const targetIndex = survey.questions.findIndex(x => x.id === branch.target);

            if (targetIndex < 0) {
                index++;
            } else {
                index = targetIndex;
            }

            continue;
        }

        if (branch.type === 'group') {
            const targetIndex = survey.questions.findIndex(x => x.groupId === branch.target);

            if (targetIndex < 0) {
                index++;
            } else {
                index = targetIndex;
            }

            continue;
        }

        index++;
    }

    return result;
}

function renderAnswer() {
    const survey = state.surveys.find(s => Number(s.id) === Number(answerState.surveyId));

    if (!survey) {
        return `<div class="error-box">回答対象のアンケートが見つかりません。</div>`;
    }

    answerState.visibleQuestions = calculateVisibleQuestions(survey, answerState.values);

    const q = answerState.visibleQuestions[answerState.currentIndex];

    if (!q) {
        navigate('answer-confirm');
        return '';
    }

    const total = answerState.visibleQuestions.length;
    const current = answerState.currentIndex + 1;
    const progress = Math.round((answerState.currentIndex / total) * 100);

    return `
        <div class="preview-shell">

            <div class="preview-header">
                <div class="small muted">回答者向けアンケート</div>
                <h1 class="page-title">${escapeHtml(survey.name)}</h1>

                ${survey.description ? `
                    <p>${escapeHtml(survey.description)}</p>
                ` : ''}

                ${survey.guidance ? `
                    <div class="info-box">${escapeHtml(survey.guidance)}</div>
                ` : ''}

                <div class="answer-progress">
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px">
                        <span class="small">現在の回答状況</span>
                        <span class="small">${current} / ${total}</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-bar" style="width:${progress}%"></div>
                    </div>
                </div>
            </div>

            <div class="answer-question">
                <div class="answer-question-title">
                    ${questionNumber(survey,q)}.
                    ${escapeHtml(q.text)}
                    ${q.required ? '<span class="required-label">必須</span>' : ''}
                </div>

                ${renderLiveAnswerInput(q)}

                ${q.help ? `
                    <div class="help">${escapeHtml(q.help)}</div>
                ` : ''}
            </div>

            <div id="answerError"></div>

            <div class="answer-footer">
                <div>
                    ${answerState.currentIndex > 0 ? `
                        <button class="btn" onclick="answerBack()">前へ戻る</button>
                    ` : ''}
                </div>

                <div>
                    ${answerState.currentIndex < total - 1 ? `
                        <button class="btn btn-primary" onclick="answerNext()">次へ進む</button>
                    ` : `
                        <button class="btn btn-primary" onclick="goAnswerConfirm()">回答を確認する</button>
                    `}
                </div>
            </div>
        </div>
    `;
}

function renderLiveAnswerInput(q) {
    const value = answerState.values[q.id];

    switch (q.type) {
        case 'text':
            return `
                <textarea
                    id="answer_${escapeHtml(q.id)}"
                    onchange="saveAnswerValue('${escapeHtml(q.id)}', this.value)"
                    oninput="saveAnswerValue('${escapeHtml(q.id)}', this.value)"
                >${escapeHtml(value || '')}</textarea>
            `;

        case 'single':
            return q.choices.map(c => {
                const choice = typeof c === 'string'
                    ? {id:'c_'+c,text:c}
                    : c;

                return `
                    <label class="option">
                        <input
                            type="radio"
                            name="answer_${escapeHtml(q.id)}"
                            value="${escapeHtml(choice.text)}"
                            ${value === choice.text ? 'checked' : ''}
                            onchange="saveAnswerValue('${escapeHtml(q.id)}', this.value)"
                        >
                        <span>${escapeHtml(choice.text)}</span>
                    </label>
                `;
            }).join('');

        case 'multiple': {
            const selected = Array.isArray(value) ? value : [];

            return q.choices.map(c => {
                const choice = typeof c === 'string'
                    ? {id:'c_'+c,text:c}
                    : c;

                return `
                    <label class="option">
                        <input
                            type="checkbox"
                            value="${escapeHtml(choice.text)}"
                            ${selected.includes(choice.text) ? 'checked' : ''}
                            onchange="toggleAnswerMultiple('${escapeHtml(q.id)}', this.value, this.checked)"
                        >
                        <span>${escapeHtml(choice.text)}</span>
                    </label>
                `;
            }).join('');
        }

        case 'rating':
            return `
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    ${q.choices.map(c => {
                        const choice = typeof c === 'string'
                            ? {id:'c_'+c,text:c}
                            : c;

                        return `
                            <label style="
                                border:1px solid var(--gray-300);
                                padding:10px 15px;
                                border-radius:7px;
                                cursor:pointer
                            ">
                                <input
                                    type="radio"
                                    name="answer_${escapeHtml(q.id)}"
                                    value="${escapeHtml(choice.text)}"
                                    ${value === choice.text ? 'checked' : ''}
                                    onchange="saveAnswerValue('${escapeHtml(q.id)}', this.value)"
                                >
                                ${escapeHtml(choice.text)}
                            </label>
                        `;
                    }).join('')}
                </div>
            `;

        default:
            return '';
    }
}

function saveAnswerValue(questionId,value) {
    answerState.values[questionId] = value;
}

function toggleAnswerMultiple(questionId,value,checked) {
    let current = Array.isArray(answerState.values[questionId])
        ? answerState.values[questionId]
        : [];

    if (checked) {
        if (!current.includes(value)) current.push(value);
    } else {
        current = current.filter(v => v !== value);
    }

    answerState.values[questionId] = current;
}

function validateAnswerQuestion(q) {
    if (!q.required) return true;

    const value = answerState.values[q.id];

    if (q.type === 'multiple') {
        return Array.isArray(value) && value.length > 0;
    }

    return value !== undefined && value !== null && String(value).trim() !== '';
}

function answerNext() {
    const survey = state.surveys.find(s => Number(s.id) === Number(answerState.surveyId));
    const q = answerState.visibleQuestions[answerState.currentIndex];

    if (!validateAnswerQuestion(q)) {
        document.getElementById('answerError').innerHTML = `
            <div class="error-box">
                この質問は必須です。回答を入力してから次へ進んでください。
            </div>
        `;
        return;
    }

    answerState.visibleQuestions = calculateVisibleQuestions(survey, answerState.values);

    answerState.currentIndex++;

    if (answerState.currentIndex >= answerState.visibleQuestions.length) {
        navigate('answer-confirm');
    } else {
        renderPage();
    }
}

function answerBack() {
    if (answerState.currentIndex > 0) {
        answerState.currentIndex--;
        renderPage();
    }
}

function goAnswerConfirm() {
    const survey = state.surveys.find(s => Number(s.id) === Number(answerState.surveyId));

    answerState.visibleQuestions = calculateVisibleQuestions(survey, answerState.values);

    const errors = answerState.visibleQuestions
        .filter(q => !validateAnswerQuestion(q));

    if (errors.length) {
        showModal(
            '未回答の質問があります',
            `
                <div class="error-box">
                    以下の必須質問に回答してください。
                    <ul>
                        ${errors.map(q => `
                            <li>質問${questionNumber(survey,q)}：${escapeHtml(q.text)}</li>
                        `).join('')}
                    </ul>
                </div>
            `,
            `<button class="btn" onclick="closeModal()">閉じる</button>`
        );
        return;
    }

    navigate('answer-confirm');
}

function renderAnswerConfirm() {
    const survey = state.surveys.find(s => Number(s.id) === Number(answerState.surveyId));

    if (!survey) {
        return `<div class="error-box">回答対象のアンケートが見つかりません。</div>`;
    }

    answerState.visibleQuestions = calculateVisibleQuestions(survey, answerState.values);

    return `
        <div class="preview-shell">

            <div class="preview-header">
                <div class="small muted">回答確認</div>
                <h1 class="page-title">${escapeHtml(survey.name)}</h1>
                <p>入力した回答を確認してください。</p>
            </div>

            ${answerState.visibleQuestions.map(q => {
                const value = answerState.values[q.id];

                return `
                    <div class="answer-question">
                        <div class="small muted">
                            質問${questionNumber(survey,q)}
                        </div>

                        <div class="answer-question-title">
                            ${escapeHtml(q.text)}
                        </div>

                        <div style="white-space:pre-wrap">
                            ${escapeHtml(Array.isArray(value) ? value.join('、') : value)}
                        </div>
                    </div>
                `;
            }).join('')}

            <div class="answer-footer">
                <button class="btn" onclick="answerState.currentIndex=0;navigate('answer')">
                    回答を修正する
                </button>

                <button class="btn btn-primary" onclick="submitAnswer()">
                    回答を送信する
                </button>
            </div>
        </div>
    `;
}

function submitAnswer() {
    const survey = state.surveys.find(s => Number(s.id) === Number(answerState.surveyId));

    if (!survey) return;

    showConfirm(
        '回答を送信する',
        `
            <p>入力した回答を送信します。</p>
            <p>送信後は回答内容を変更できません。</p>
        `,
        '回答を送信する',
        () => {
            const nextId = survey.answers.length + 1;

            survey.answers.push({
                id: Date.now(),
                number: 'R-' + String(nextId).padStart(4,'0'),
                answeredAt: nowString(),
                respondent: 'モック回答者',
                values: JSON.parse(JSON.stringify(answerState.values))
            });

            survey.responseCount++;
            survey.updatedAt = nowString();

            saveState();
            closeModal();
            navigate('answer-complete');
        },
        'primary'
    );
}

function renderAnswerComplete() {
    const survey = currentSurvey();

    return `
        <div class="preview-shell">
            <div class="card" style="margin-top:60px">
                <div class="card-body" style="text-align:center;padding:50px 25px">

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
                    ">✓</div>

                    <h1 style="margin:0 0 12px">回答が完了しました</h1>

                    <p>${escapeHtml(survey?.completeMessage || 'ご回答ありがとうございました。')}</p>

                    <div style="margin-top:25px">
                        <button class="btn" onclick="navigate('home')">
                            管理画面へ戻る
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

function showModal(title,body,footer) {
    document.getElementById('modalTitle').innerHTML = title;
    document.getElementById('modalBody').innerHTML = body;
    document.getElementById('modalFooter').innerHTML = footer || '';

    document.getElementById('modalBackdrop').classList.add('show');
}

function showConfirm(title,body,confirmLabel,onConfirm,kind='primary') {
    const buttonClass = kind === 'danger'
        ? 'btn-danger'
        : kind === 'warning'
            ? 'btn-warning'
            : 'btn-primary';

    showModal(
        title,
        body,
        `
            <button class="btn" onclick="closeModal()">キャンセル</button>
            <button class="btn ${buttonClass}" onclick="window.__modalConfirm && window.__modalConfirm()">
                ${escapeHtml(confirmLabel)}
            </button>
        `
    );

    window.__modalConfirm = function() {
        window.__modalConfirm = null;
        onConfirm();
    };
}

function closeModal() {
    document.getElementById('modalBackdrop').classList.remove('show');
    window.__modalConfirm = null;
}

/* =========================================================
   Validation / Toast
========================================================= */

function showValidationErrors(errors, targetId = null) {
    const html = `
        <div class="error-box">
            <strong>入力内容を確認してください。</strong>
            <ul>
                ${errors.map(e => `<li>${escapeHtml(e)}</li>`).join('')}
            </ul>
        </div>
    `;

    if (targetId && document.getElementById(targetId)) {
        document.getElementById(targetId).innerHTML = html;
        document.getElementById(targetId).scrollIntoView({behavior:'smooth',block:'center'});
        return;
    }

    showModal(
        '公開前チェック',
        html,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}

function toast(message) {
    const el = document.getElementById('toast');

    el.textContent = message;
    el.classList.add('show');

    clearTimeout(window.__toastTimer);

    window.__toastTimer = setTimeout(() => {
        el.classList.remove('show');
    }, 2500);
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

/* =========================================================
   Events
========================================================= */

function bindPageEvents() {
    // モーダル外側クリックで閉じる
    const backdrop = document.getElementById('modalBackdrop');

    if (backdrop && !backdrop.dataset.bound) {
        backdrop.dataset.bound = '1';

        backdrop.addEventListener('click', function(e) {
            if (e.target === backdrop) {
                closeModal();
            }
        });
    }
}

/* =========================================================
   Initialization
========================================================= */

if (!state.currentSurveyId && state.surveys.length) {
    state.currentSurveyId = state.surveys[0].id;
}

saveState();
navigate(state.currentPage || 'home');

</script>

</body>
</html>
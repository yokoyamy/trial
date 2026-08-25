<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * PHP 8.5 / Apache 2.4
 *
 * 全画面:
 *   index.php?view=...
 *
 * API:
 *   POST index.php
 *   action=...
 *
 * DBは使用せず data/*.json に永続化する。
 */

/* =========================================================
 * 基本設定
 * ======================================================= */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const SURVEYS_FILE      = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE    = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const RESPONSES_FILE    = DATA_DIR . DIRECTORY_SEPARATOR . 'responses.json';
const HISTORY_FILE      = DATA_DIR . DIRECTORY_SEPARATOR . 'send_history.json';
const KINTONE_FILE      = DATA_DIR . DIRECTORY_SEPARATOR . 'kintone.json';
const MAIL_FILE         = DATA_DIR . DIRECTORY_SEPARATOR . 'mail.json';

const ALLOWED_VIEWS = [
    'admin-survey-list',
    'admin-survey-edit',
    'admin-preview',
    'admin-send',
    'admin-aggregation',
    'admin-kintone',
    'admin-mail',
    'answer',
    'confirm',
    'complete',
];

const ADMIN_VIEWS = [
    'admin-survey-list',
    'admin-survey-edit',
    'admin-preview',
    'admin-send',
    'admin-aggregation',
    'admin-kintone',
    'admin-mail',
];

const RESPONDENT_VIEWS = [
    'answer',
    'confirm',
    'complete',
];

/* =========================================================
 * PHP共通処理
 * ======================================================= */

error_reporting(E_ALL);
ini_set('display_errors', '0');

date_default_timezone_set('Asia/Tokyo');

ensureDataDirectory();
ensureInitialData();

/* ---------------------------------------------------------
 * API
 * ------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleApiRequest();
    exit;
}

/* ---------------------------------------------------------
 * GET画面
 * ------------------------------------------------------- */

$view = normalizeView($_GET['view'] ?? '');

if (!in_array($view, ALLOWED_VIEWS, true)) {
    $view = 'admin-survey-list';
}

$surveyId = normalizeId($_GET['surveyId'] ?? '');
$token    = normalizeToken($_GET['token'] ?? '');

$surveys = readJson(SURVEYS_FILE, []);
$surveys = applyFinishedStates($surveys);
writeJson(SURVEYS_FILE, $surveys);

$survey = $surveyId !== '' ? findSurvey($surveys, $surveyId) : null;

/*
 * surveyId必須画面の検証
 */
if (in_array($view, [
    'admin-survey-edit',
    'admin-preview',
    'admin-send',
    'admin-aggregation',
    'answer',
    'confirm',
    'complete',
], true)) {
    if ($surveyId === '' || $survey === null) {
        $view = 'admin-survey-list';
        $surveyId = '';
        $survey = null;
    }
}

/*
 * 回答者画面では公開状態を確認する。
 */
if (in_array($view, ['answer', 'confirm'], true) && $survey !== null) {
    if (($survey['status'] ?? '') !== 'published') {
        $respondentError = 'このアンケートは現在回答できません。';
    }
}

/* =========================================================
 * HTML
 * ======================================================= */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>アンケート管理システム</title>

<style>
:root {
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#15803d;
    --warning:#b45309;
    --danger:#dc2626;
    --text:#1f2937;
    --muted:#6b7280;
    --border:#d1d5db;
    --bg:#f3f4f6;
    --card:#ffffff;
    --sidebar:#111827;
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
        sans-serif;
    color:var(--text);
    background:var(--bg);
}

button,
input,
textarea,
select {
    font:inherit;
}

button {
    cursor:pointer;
}

a {
    color:inherit;
}

.app {
    min-height:100vh;
}

.admin-header {
    height:64px;
    background:#fff;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 24px;
    position:sticky;
    top:0;
    z-index:20;
}

.admin-header-title {
    font-size:20px;
    font-weight:700;
}

.admin-nav {
    display:flex;
    gap:8px;
    align-items:center;
}

.admin-nav button {
    border:0;
    background:transparent;
    padding:9px 12px;
    border-radius:8px;
}

.admin-nav button:hover {
    background:#f3f4f6;
}

.container {
    width:min(1440px, calc(100% - 32px));
    margin:24px auto;
}

.page-title {
    margin:0 0 20px;
    font-size:28px;
}

.page-subtitle {
    color:var(--muted);
    margin-top:-12px;
    margin-bottom:20px;
}

.card {
    background:var(--card);
    border:1px solid var(--border);
    border-radius:12px;
    padding:20px;
    margin-bottom:20px;
}

.card-title {
    margin:0 0 16px;
    font-size:18px;
}

.toolbar {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    margin-bottom:16px;
}

.toolbar .grow {
    flex:1;
}

input,
textarea,
select {
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

textarea {
    min-height:120px;
    resize:vertical;
}

input:focus,
textarea:focus,
select:focus {
    outline:2px solid rgba(37,99,235,.15);
    border-color:var(--primary);
}

.form-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
}

.form-grid.three {
    grid-template-columns:repeat(3,minmax(0,1fr));
}

.form-group {
    display:flex;
    flex-direction:column;
    gap:7px;
}

.form-group.full {
    grid-column:1 / -1;
}

.form-label {
    font-weight:600;
    font-size:14px;
}

.btn {
    border:1px solid transparent;
    border-radius:8px;
    padding:10px 15px;
    min-height:42px;
    font-weight:600;
}

.btn-primary {
    color:#fff;
    background:var(--primary);
}

.btn-primary:hover {
    background:var(--primary-dark);
}

.btn-secondary {
    background:#fff;
    border-color:#cbd5e1;
}

.btn-danger {
    color:#fff;
    background:var(--danger);
}

.btn-success {
    color:#fff;
    background:var(--success);
}

.btn-warning {
    color:#fff;
    background:var(--warning);
}

.btn-sm {
    padding:7px 10px;
    min-height:34px;
    font-size:13px;
}

.badge {
    display:inline-flex;
    align-items:center;
    padding:5px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}

.badge-draft {
    background:#e5e7eb;
    color:#374151;
}

.badge-published {
    background:#dcfce7;
    color:#166534;
}

.badge-stopped {
    background:#fef3c7;
    color:#92400e;
}

.badge-finished {
    background:#fee2e2;
    color:#991b1b;
}

.table-wrap {
    width:100%;
    overflow-x:auto;
}

table {
    width:100%;
    min-width:900px;
    border-collapse:collapse;
}

th,
td {
    border-bottom:1px solid #e5e7eb;
    padding:12px 10px;
    text-align:left;
    vertical-align:middle;
}

th {
    background:#f8fafc;
    font-size:13px;
    white-space:nowrap;
}

.actions {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}

.search-box {
    max-width:380px;
}

.filter-group {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}

.filter-btn {
    border:1px solid var(--border);
    background:#fff;
    border-radius:999px;
    padding:7px 12px;
}

.filter-btn.active {
    background:#111827;
    color:#fff;
    border-color:#111827;
}

.editor-group {
    border:1px solid #cbd5e1;
    border-radius:12px;
    margin-bottom:16px;
    background:#f8fafc;
}

.editor-group-header {
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px;
    background:#eef2ff;
    border-bottom:1px solid #cbd5e1;
}

.drag-handle {
    cursor:grab;
    padding:6px;
    user-select:none;
}

.group-title-input {
    flex:1;
}

.question-list {
    padding:12px;
    min-height:30px;
}

.question-card {
    background:#fff;
    border:1px solid #d1d5db;
    border-radius:10px;
    padding:14px;
    margin-bottom:10px;
}

.question-header {
    display:flex;
    gap:10px;
    align-items:center;
    margin-bottom:12px;
}

.question-number {
    min-width:60px;
    font-weight:800;
    color:var(--primary);
}

.question-actions {
    margin-left:auto;
    display:flex;
    gap:5px;
}

.choice-row {
    display:flex;
    gap:8px;
    margin-top:7px;
}

.choice-row input {
    flex:1;
}

.branch-row {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
    margin-top:8px;
}

.add-row {
    margin-top:10px;
}

.drag-over {
    outline:2px dashed var(--primary);
    background:#eff6ff;
}

.modal-backdrop {
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.55);
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    z-index:100;
}

.modal-backdrop.show {
    display:flex;
}

.modal {
    width:min(700px,100%);
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:14px;
    padding:22px;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
}

.modal-title {
    margin:0 0 12px;
}

.modal-body {
    margin-bottom:20px;
    white-space:pre-wrap;
}

.modal-actions {
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.toast-container {
    position:fixed;
    right:18px;
    bottom:18px;
    z-index:200;
    display:flex;
    flex-direction:column;
    gap:8px;
}

.toast {
    background:#111827;
    color:#fff;
    border-radius:8px;
    padding:12px 15px;
    box-shadow:0 10px 30px rgba(0,0,0,.2);
    max-width:420px;
}

.toast.error {
    background:#991b1b;
}

.toast.success {
    background:#166534;
}

.stats {
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px;
}

.stat {
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
}

.stat-label {
    font-size:13px;
    color:var(--muted);
}

.stat-value {
    font-size:28px;
    font-weight:800;
    margin-top:5px;
}

.bar-row {
    display:grid;
    grid-template-columns:minmax(180px,1fr) 3fr 80px;
    align-items:center;
    gap:10px;
    margin:10px 0;
}

.bar {
    height:22px;
    border-radius:999px;
    background:#e5e7eb;
    overflow:hidden;
}

.bar > span {
    display:block;
    height:100%;
    background:var(--primary);
}

.preview-shell {
    background:#e5e7eb;
    padding:30px;
    border-radius:12px;
}

.preview-device {
    background:#fff;
    margin:auto;
    min-height:600px;
    padding:30px;
    border-radius:10px;
    max-width:100%;
}

.preview-device.mobile {
    width:390px;
    max-width:100%;
}

.answer-container {
    width:min(760px,calc(100% - 28px));
    margin:25px auto;
}

.answer-header {
    background:#fff;
    border-radius:14px;
    padding:22px;
    margin-bottom:18px;
}

.answer-question {
    background:#fff;
    border-radius:14px;
    padding:20px;
    margin-bottom:14px;
}

.answer-question.error {
    border:2px solid #dc2626;
}

.choice-label {
    display:flex;
    align-items:center;
    gap:10px;
    border:1px solid #d1d5db;
    border-radius:10px;
    padding:14px;
    margin:8px 0;
    cursor:pointer;
}

.choice-label:hover {
    background:#f8fafc;
}

.choice-label input {
    width:auto;
    flex:none;
}

.answer-nav {
    display:flex;
    justify-content:space-between;
    gap:10px;
}

.empty {
    padding:40px;
    text-align:center;
    color:var(--muted);
}

.alert {
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:15px;
}

.alert-error {
    background:#fee2e2;
    color:#991b1b;
}

.alert-success {
    background:#dcfce7;
    color:#166534;
}

.alert-info {
    background:#dbeafe;
    color:#1e40af;
}

.code {
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    background:#f3f4f6;
    border-radius:5px;
    padding:2px 5px;
}

.history-detail {
    background:#f8fafc;
    padding:12px;
    border-radius:8px;
    margin-top:8px;
    white-space:pre-wrap;
}

.kintone-fields {
    max-height:400px;
    overflow:auto;
}

.mapping-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
}

.check-list {
    display:grid;
    gap:8px;
}

.check-list label {
    display:flex;
    gap:8px;
    align-items:center;
}

.check-list input {
    width:auto;
}

.hidden {
    display:none !important;
}

.text-muted {
    color:var(--muted);
}

.text-danger {
    color:var(--danger);
}

.text-success {
    color:var(--success);
}

@media (max-width:900px) {
    .stats {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .form-grid,
    .form-grid.three {
        grid-template-columns:1fr;
    }

    .admin-header {
        height:auto;
        min-height:64px;
        padding:12px 15px;
        align-items:flex-start;
        gap:10px;
        flex-direction:column;
    }

    .admin-nav {
        width:100%;
        overflow-x:auto;
    }

    .bar-row {
        grid-template-columns:1fr;
        gap:5px;
    }
}

@media (max-width:600px) {
    .container {
        width:min(100% - 20px,1440px);
        margin:12px auto;
    }

    .card {
        padding:14px;
    }

    .page-title {
        font-size:23px;
    }

    .stats {
        grid-template-columns:1fr 1fr;
    }

    .btn {
        min-height:44px;
    }

    .question-header {
        align-items:flex-start;
        flex-wrap:wrap;
    }

    .question-actions {
        width:100%;
        margin-left:0;
    }

    .branch-row {
        grid-template-columns:1fr;
    }

    .preview-shell {
        padding:8px;
    }

    .preview-device {
        padding:15px;
    }
}
</style>
</head>

<body>

<div id="app"></div>

<div id="modalBackdrop" class="modal-backdrop">
    <div class="modal">
        <h2 id="modalTitle" class="modal-title">確認</h2>
        <div id="modalBody" class="modal-body"></div>
        <div class="modal-actions">
            <button id="modalCancel" class="btn btn-secondary">キャンセル</button>
            <button id="modalExecute" class="btn btn-primary">実行</button>
        </div>
    </div>
</div>

<div id="toastContainer" class="toast-container"></div>

<script>
'use strict';

/* =========================================================
 * 初期PHPデータ
 * ======================================================= */

const INITIAL_VIEW = <?= json_encode($view, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_SURVEY_ID = <?= json_encode($surveyId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_TOKEN = <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_SURVEY = <?= json_encode($survey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_RESPONDENT_ERROR = <?= json_encode($respondentError ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

/* =========================================================
 * アプリ状態
 * ======================================================= */

const state = {
    currentView: INITIAL_VIEW,
    editingSurveyId: '',
    aggregationSurveyId: '',
    sendingSurveyId: '',
    surveyId: INITIAL_SURVEY_ID,
    answerToken: INITIAL_TOKEN,

    surveys: [],
    customers: [],
    responses: [],
    history: [],

    editingSurvey: null,
    answerData: {},
    answerStep: 0,

    surveySearch: '',
    surveyFilter: 'all',
    surveySort: 'updatedDesc',

    customerSearch: '',
    customerStatus: 'all',
    selectedCustomerIds: new Set(),

    sendSubject: 'アンケートご回答のお願い',
    sendBody:
        '{顧客名} 様\n\n' +
        'アンケートへのご協力をお願いいたします。\n\n' +
        '{アンケートURL}\n\n' +
        'よろしくお願いいたします。',

    sendType: 'bulk',
    sendResult: null,

    aggregationQuestionSelection: {},

    kintone: null,
    mail: null,
};

const app = document.getElementById('app');

/* =========================================================
 * API通信
 * ======================================================= */

async function api(action, payload = {}) {
    const body = new URLSearchParams();
    body.set('action', action);

    Object.entries(payload).forEach(([key, value]) => {
        if (value === undefined || value === null) {
            return;
        }

        if (typeof value === 'object') {
            body.set(key, JSON.stringify(value));
        } else {
            body.set(key, String(value));
        }
    });

    let response;

    try {
        response = await fetch('index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json'
            },
            body
        });
    } catch (error) {
        throw new Error('通信失敗: ' + error.message);
    }

    const text = await response.text();

    if (!response.ok) {
        throw new Error(
            'HTTPエラー ' +
            response.status +
            '\n' +
            text.slice(0, 500)
        );
    }

    let json;

    try {
        json = JSON.parse(text);
    } catch (error) {
        throw new Error(
            'JSON解析失敗\n' +
            text.slice(0, 1000)
        );
    }

    if (!json.ok) {
        const message =
            json.error?.message ||
            'PHP処理に失敗しました。';

        const code =
            json.error?.code
                ? '[' + json.error.code + '] '
                : '';

        throw new Error(code + message);
    }

    return json.data;
}

/* =========================================================
 * URL
 * ======================================================= */

function readUrlState() {
    const params = new URLSearchParams(location.search);

    let view = params.get('view') || 'admin-survey-list';

    const allowed = [
        'admin-survey-list',
        'admin-survey-edit',
        'admin-preview',
        'admin-send',
        'admin-aggregation',
        'admin-kintone',
        'admin-mail',
        'answer',
        'confirm',
        'complete'
    ];

    if (!allowed.includes(view)) {
        view = 'admin-survey-list';
    }

    const surveyId = params.get('surveyId') || '';
    const token = params.get('token') || '';

    return {
        view,
        surveyId,
        token
    };
}

function buildUrl(params) {
    const url = new URL('index.php', location.href);

    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, value);
        }
    });

    return url.pathname + url.search;
}

function navigate(params, replace = false) {
    const url = buildUrl(params);

    if (replace) {
        history.replaceState({}, '', url);
    } else {
        history.pushState({}, '', url);
    }

    syncStateFromUrl();
    render();
}

function syncStateFromUrl() {
    const urlState = readUrlState();

    state.currentView = urlState.view;
    state.surveyId = urlState.surveyId;
    state.answerToken = urlState.token;

    state.editingSurveyId = '';
    state.aggregationSurveyId = '';
    state.sendingSurveyId = '';

    if (
        state.currentView === 'admin-survey-edit' ||
        state.currentView === 'admin-preview'
    ) {
        state.editingSurveyId = state.surveyId;
    }

    if (state.currentView === 'admin-aggregation') {
        state.aggregationSurveyId = state.surveyId;
    }

    if (state.currentView === 'admin-send') {
        state.sendingSurveyId = state.surveyId;
    }

    if (state.currentView === 'answer' ||
        state.currentView === 'confirm' ||
        state.currentView === 'complete') {
        state.surveyId = urlState.surveyId;
        state.answerToken = urlState.token;
    }
}

window.addEventListener('popstate', () => {
    syncStateFromUrl();
    render();
});

/* =========================================================
 * 共通
 * ======================================================= */

function esc(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
}

function escapeAttr(value) {
    return esc(value)
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) {
        return value;
    }

    return d.toLocaleString('ja-JP');
}

function statusLabel(status) {
    return {
        draft: '下書き',
        published: '公開中',
        stopped: '停止',
        finished: '終了'
    }[status] || status;
}

function statusBadge(status) {
    return `<span class="badge badge-${esc(status)}">${esc(statusLabel(status))}</span>`;
}

function showToast(message, type = '') {
    const node = document.createElement('div');

    node.className = 'toast ' + type;
    node.textContent = message;

    document.getElementById('toastContainer').appendChild(node);

    setTimeout(() => {
        node.remove();
    }, 4000);
}

function showError(error) {
    console.error(error);
    showToast(error.message || String(error), 'error');
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function findSurvey(id) {
    return state.surveys.find(s => s.surveyId === id) || null;
}

function currentSurvey() {
    return findSurvey(state.surveyId);
}

function totalQuestions(survey) {
    return (survey?.groups || [])
        .reduce((sum, g) => sum + (g.questions || []).length, 0);
}

function totalResponses(surveyId) {
    return state.responses.filter(r => r.surveyId === surveyId).length;
}

/* =========================================================
 * データロード
 * ======================================================= */

async function loadAllData() {
    const data = await api('load_data');

    state.surveys = data.surveys || [];
    state.customers = data.customers || [];
    state.responses = data.responses || [];
    state.history = data.history || [];
    state.kintone = data.kintone || {};
    state.mail = data.mail || {};

    if (state.currentView === 'admin-survey-edit') {
        if (state.editingSurveyId) {
            const survey = findSurvey(state.editingSurveyId);

            if (survey) {
                state.editingSurvey = clone(survey);
            }
        } else {
            state.editingSurvey = makeEmptySurvey();
        }
    }
}

/* =========================================================
 * モーダル
 * ======================================================= */

let modalCallback = null;

function openModal(title, body, callback, executeText = '実行') {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').textContent = body;
    document.getElementById('modalExecute').textContent = executeText;

    modalCallback = callback;

    document
        .getElementById('modalBackdrop')
        .classList.add('show');
}

function closeModal() {
    document
        .getElementById('modalBackdrop')
        .classList.remove('show');

    modalCallback = null;
}

document.getElementById('modalCancel').addEventListener(
    'click',
    closeModal
);

document.getElementById('modalExecute').addEventListener(
    'click',
    async () => {
        if (!modalCallback) {
            closeModal();
            return;
        }

        const callback = modalCallback;

        closeModal();

        try {
            await callback();
        } catch (error) {
            showError(error);
        }
    }
);

/* =========================================================
 * 管理者ヘッダー
 * ======================================================= */

function renderAdminHeader() {
    return `
        <header class="admin-header">
            <div class="admin-header-title">
                アンケート管理システム
            </div>

            <nav class="admin-nav">
                <button onclick="navigate({view:'admin-survey-list'})">
                    アンケート一覧
                </button>

                <button onclick="navigate({view:'admin-kintone'})">
                    kintone連携設定
                </button>

                <button onclick="navigate({view:'admin-mail'})">
                    メールサーバ設定
                </button>

                <button onclick="navigate({view:'admin-survey-list'}, true)">
                    ログアウト
                </button>
            </nav>
        </header>
    `;
}

/* =========================================================
 * 一覧
 * ======================================================= */

function renderSurveyList() {
    let surveys = [...state.surveys];

    const keyword = state.surveySearch.trim().toLowerCase();

    if (keyword) {
        surveys = surveys.filter(s =>
            String(s.title || '')
                .toLowerCase()
                .includes(keyword)
        );
    }

    if (state.surveyFilter !== 'all') {
        surveys = surveys.filter(
            s => s.status === state.surveyFilter
        );
    }

    surveys.sort((a, b) => {
        switch (state.surveySort) {
            case 'updatedAsc':
                return String(a.updatedAt)
                    .localeCompare(String(b.updatedAt));

            case 'responsesDesc':
                return totalResponses(b.surveyId) -
                    totalResponses(a.surveyId);

            case 'responsesAsc':
                return totalResponses(a.surveyId) -
                    totalResponses(b.surveyId);

            case 'startDesc':
                return String(b.startDate || '')
                    .localeCompare(String(a.startDate || ''));

            case 'startAsc':
                return String(a.startDate || '')
                    .localeCompare(String(b.startDate || ''));

            default:
                return String(b.updatedAt)
                    .localeCompare(String(a.updatedAt));
        }
    });

    return `
        ${renderAdminHeader()}

        <main class="container">
            <h1 class="page-title">アンケート一覧</h1>

            <div class="card">
                <div class="toolbar">
                    <div class="search-box grow">
                        <input
                            id="surveySearch"
                            type="search"
                            placeholder="タイトルを検索"
                            value="${escapeAttr(state.surveySearch)}"
                        >
                    </div>

                    <select id="surveySort" style="max-width:230px">
                        <option value="updatedDesc"
                            ${state.surveySort === 'updatedDesc' ? 'selected' : ''}>
                            更新日 新しい順
                        </option>
                        <option value="updatedAsc"
                            ${state.surveySort === 'updatedAsc' ? 'selected' : ''}>
                            更新日 古い順
                        </option>
                        <option value="responsesDesc"
                            ${state.surveySort === 'responsesDesc' ? 'selected' : ''}>
                            回答数 多い順
                        </option>
                        <option value="responsesAsc"
                            ${state.surveySort === 'responsesAsc' ? 'selected' : ''}>
                            回答数 少ない順
                        </option>
                        <option value="startDesc"
                            ${state.surveySort === 'startDesc' ? 'selected' : ''}>
                            開始日 新しい順
                        </option>
                        <option value="startAsc"
                            ${state.surveySort === 'startAsc' ? 'selected' : ''}>
                            開始日 古い順
                        </option>
                    </select>

                    <button
                        class="btn btn-primary"
                        onclick="navigate({view:'admin-survey-edit'})">
                        新規作成
                    </button>
                </div>

                <div class="filter-group">
                    ${[
                        ['all', 'すべて'],
                        ['published', '公開中'],
                        ['draft', '下書き'],
                        ['stopped', '停止'],
                        ['finished', '終了']
                    ].map(([value, label]) => `
                        <button
                            class="filter-btn ${state.surveyFilter === value ? 'active' : ''}"
                            onclick="setSurveyFilter('${value}')">
                            ${label}
                        </button>
                    `).join('')}
                </div>
            </div>

            <div class="card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>作成日</th>
                                <th>更新日</th>
                                <th>タイトル</th>
                                <th>アンケート期間</th>
                                <th>ステータス</th>
                                <th>回答数</th>
                                <th>操作</th>
                            </tr>
                        </thead>

                        <tbody>
                            ${
                                surveys.length
                                    ? surveys.map(renderSurveyRow).join('')
                                    : `
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty">
                                                    アンケートがありません。
                                                </div>
                                            </td>
                                        </tr>
                                    `
                            }
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    `;
}

function renderSurveyRow(survey) {
    return `
        <tr>
            <td>${esc(formatDate(survey.createdAt))}</td>
            <td>${esc(formatDate(survey.updatedAt))}</td>
            <td>
                <strong>${esc(survey.title || '無題')}</strong>
            </td>
            <td>
                ${esc(formatDate(survey.startDate))}
                ～
                ${esc(formatDate(survey.endDate))}
            </td>
            <td>${statusBadge(survey.status)}</td>
            <td>${totalResponses(survey.surveyId)}</td>
            <td>
                <div class="actions">
                    <button
                        class="btn btn-secondary btn-sm"
                        onclick="navigate({
                            view:'admin-survey-edit',
                            surveyId:'${escapeAttr(survey.surveyId)}'
                        })">
                        確認・編集
                    </button>

                    <button
                        class="btn btn-secondary btn-sm"
                        onclick="navigate({
                            view:'admin-aggregation',
                            surveyId:'${escapeAttr(survey.surveyId)}'
                        })">
                        集計
                    </button>

                    <button
                        class="btn btn-primary btn-sm"
                        onclick="navigate({
                            view:'admin-send',
                            surveyId:'${escapeAttr(survey.surveyId)}'
                        })">
                        送信
                    </button>

                    <button
                        class="btn btn-secondary btn-sm"
                        onclick="confirmDuplicate('${escapeAttr(survey.surveyId)}')">
                        複製
                    </button>

                    <button
                        class="btn btn-danger btn-sm"
                        onclick="confirmDeleteSurvey('${escapeAttr(survey.surveyId)}')">
                        削除
                    </button>
                </div>
            </td>
        </tr>
    `;
}

function setSurveyFilter(value) {
    state.surveyFilter = value;
    render();
}

/* =========================================================
 * 編集
 * ======================================================= */

function makeEmptySurvey() {
    return {
        surveyId: '',
        title: '',
        description: '',
        startDate: '',
        endDate: '',
        status: 'draft',
        numberingMode: 'all',
        allowResubmit: false,
        groups: [
            {
                groupId: createClientId('group'),
                title: 'グループ1',
                sortOrder: 1,
                questions: []
            }
        ],
        createdAt: '',
        updatedAt: ''
    };
}

function renderSurveyEdit() {
    const survey = state.editingSurvey;

    if (!survey) {
        return `
            ${renderAdminHeader()}
            <main class="container">
                <div class="alert alert-error">
                    対象アンケートを取得できません。
                </div>
            </main>
        `;
    }

    return `
        ${renderAdminHeader()}

        <main class="container">
            <div class="toolbar">
                <h1 class="page-title grow">
                    ${survey.surveyId ? 'アンケート編集' : 'アンケート新規作成'}
                </h1>

                <button
                    class="btn btn-secondary"
                    onclick="cancelEdit()">
                    キャンセル
                </button>

                <button
                    class="btn btn-primary"
                    onclick="saveSurvey()">
                    保存して一覧へ
                </button>
            </div>

            <div class="card">
                <h2 class="card-title">基本情報</h2>

                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label">タイトル</label>
                        <input
                            id="editTitle"
                            value="${escapeAttr(survey.title)}"
                            maxlength="200"
                        >
                    </div>

                    <div class="form-group full">
                        <label class="form-label">説明</label>
                        <textarea id="editDescription">${esc(survey.description)}</textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">開始日時</label>
                        <input
                            id="editStartDate"
                            type="datetime-local"
                            value="${escapeAttr(toDatetimeLocal(survey.startDate))}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">終了日時</label>
                        <input
                            id="editEndDate"
                            type="datetime-local"
                            value="${escapeAttr(toDatetimeLocal(survey.endDate))}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">質問番号採番方式</label>
                        <select id="editNumberingMode">
                            <option value="all"
                                ${survey.numberingMode === 'all' ? 'selected' : ''}>
                                アンケート全体で通番
                            </option>
                            <option value="group"
                                ${survey.numberingMode === 'group' ? 'selected' : ''}>
                                グループ毎に採番
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">再回答</label>
                        <select id="editAllowResubmit">
                            <option value="false"
                                ${!survey.allowResubmit ? 'selected' : ''}>
                                再回答不可
                            </option>
                            <option value="true"
                                ${survey.allowResubmit ? 'selected' : ''}>
                                再回答可能
                            </option>
                        </select>
                    </div>
                </div>
            </div>

            ${
                survey.surveyId
                    ? renderStatusControl(survey)
                    : ''
            }

            <div class="card">
                <h2 class="card-title">グループ・質問</h2>

                <div id="groupEditor">
                    ${(survey.groups || [])
                        .sort((a,b) => a.sortOrder - b.sortOrder)
                        .map((g, gi) => renderGroupEditor(g, gi, survey))
                        .join('')}
                </div>

                <button
                    class="btn btn-secondary"
                    onclick="addGroup()">
                    ＋ グループ追加
                </button>
            </div>
        </main>
    `;
}

function renderStatusControl(survey) {
    let options = '';

    if (survey.status === 'draft') {
        options = `
            <option value="draft" selected>下書き</option>
            <option value="published">公開中</option>
        `;
    } else if (survey.status === 'published') {
        options = `
            <option value="published" selected>公開中</option>
            <option value="stopped">停止</option>
        `;
    } else if (survey.status === 'stopped') {
        options = `
            <option value="stopped" selected>停止</option>
            <option value="published">公開中</option>
        `;
    } else {
        options = `
            <option value="finished" selected>終了</option>
        `;
    }

    return `
        <div class="card">
            <h2 class="card-title">ステータス</h2>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">状態</label>

                    <select
                        id="statusChange"
                        ${survey.status === 'finished' ? 'disabled' : ''}>
                        ${options}
                    </select>
                </div>

                <div>
                    <div class="form-label">現在の状態</div>
                    <div style="margin-top:8px">
                        ${statusBadge(survey.status)}
                    </div>
                </div>
            </div>
        </div>
    `;
}

function renderGroupEditor(group, groupIndex, survey) {
    const questions = [...(group.questions || [])]
        .sort((a,b) => a.sortOrder - b.sortOrder);

    return `
        <section
            class="editor-group"
            data-group-id="${escapeAttr(group.groupId)}"
            draggable="true"
            ondragstart="onGroupDragStart(event,'${escapeAttr(group.groupId)}')"
            ondragover="onGroupDragOver(event)"
            ondrop="onGroupDrop(event,'${escapeAttr(group.groupId)}')">

            <div class="editor-group-header">
                <span class="drag-handle">☷</span>

                <input
                    class="group-title-input"
                    value="${escapeAttr(group.title)}"
                    onchange="updateGroupTitle(
                        '${escapeAttr(group.groupId)}',
                        this.value
                    )"
                >

                <button
                    class="btn btn-danger btn-sm"
                    onclick="confirmDeleteGroup('${escapeAttr(group.groupId)}')">
                    グループ削除
                </button>
            </div>

            <div class="question-list"
                data-group-id="${escapeAttr(group.groupId)}"
                ondragover="onQuestionDragOver(event)"
                ondrop="onQuestionDrop(
                    event,
                    '${escapeAttr(group.groupId)}'
                )">

                ${questions.map(q =>
                    renderQuestionEditor(
                        q,
                        group,
                        survey,
                        groupIndex
                    )
                ).join('')}

                <button
                    class="btn btn-secondary btn-sm"
                    onclick="addQuestion('${escapeAttr(group.groupId)}')">
                    ＋ 質問追加
                </button>
            </div>
        </section>
    `;
}

function renderQuestionEditor(question, group, survey, groupIndex) {
    const choices = question.choices || [];
    const branchRules = question.branchRules || [];

    const questionNumber =
        question.questionNumber ||
        getQuestionNumber(survey, question.questionId);

    return `
        <article
            class="question-card"
            draggable="true"
            data-question-id="${escapeAttr(question.questionId)}"
            ondragstart="onQuestionDragStart(
                event,
                '${escapeAttr(question.questionId)}'
            )">

            <div class="question-header">
                <span class="drag-handle">☷</span>

                <span class="question-number">
                    ${esc(questionNumber)}
                </span>

                <strong>
                    質問
                </strong>

                <div class="question-actions">
                    <button
                        class="btn btn-danger btn-sm"
                        onclick="confirmDeleteQuestion(
                            '${escapeAttr(group.groupId)}',
                            '${escapeAttr(question.questionId)}'
                        )">
                        削除
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">質問文</label>
                <textarea
                    onchange="updateQuestion(
                        '${escapeAttr(group.groupId)}',
                        '${escapeAttr(question.questionId)}',
                        'questionText',
                        this.value
                    )"
                >${esc(question.questionText)}</textarea>
            </div>

            <div class="form-grid" style="margin-top:12px">
                <div class="form-group">
                    <label class="form-label">回答形式</label>

                    <select
                        onchange="changeQuestionType(
                            '${escapeAttr(group.groupId)}',
                            '${escapeAttr(question.questionId)}',
                            this.value
                        )">

                        <option value="single"
                            ${question.type === 'single' ? 'selected' : ''}>
                            単一選択
                        </option>

                        <option value="multiple"
                            ${question.type === 'multiple' ? 'selected' : ''}>
                            複数選択
                        </option>

                        <option value="text"
                            ${question.type === 'text' ? 'selected' : ''}>
                            自由記述
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">回答</label>

                    <select
                        onchange="updateQuestion(
                            '${escapeAttr(group.groupId)}',
                            '${escapeAttr(question.questionId)}',
                            'required',
                            this.value === 'true'
                        )">

                        <option value="true"
                            ${question.required ? 'selected' : ''}>
                            必須
                        </option>

                        <option value="false"
                            ${!question.required ? 'selected' : ''}>
                            任意
                        </option>
                    </select>
                </div>
            </div>

            ${
                question.type === 'single' ||
                question.type === 'multiple'
                    ? renderChoicesEditor(
                        question,
                        group
                    )
                    : ''
            }

            ${
                question.type === 'single'
                    ? renderBranchEditor(
                        question,
                        group,
                        survey
                    )
                    : ''
            }

            <div class="form-group" style="margin-top:12px">
                <label class="form-label">移動先グループ</label>

                <select
                    onchange="moveQuestion(
                        '${escapeAttr(group.groupId)}',
                        '${escapeAttr(question.questionId)}',
                        this.value
                    )">

                    <option value="">
                        このグループ
                    </option>

                    ${survey.groups
                        .filter(g => g.groupId !== group.groupId)
                        .map(g => `
                            <option value="${escapeAttr(g.groupId)}">
                                ${esc(g.title)}
                            </option>
                        `).join('')}
                </select>
            </div>
        </article>
    `;
}

function renderChoicesEditor(question, group) {
    return `
        <div style="margin-top:15px">
            <label class="form-label">選択肢</label>

            <div>
                ${(question.choices || [])
                    .sort((a,b) => a.sortOrder - b.sortOrder)
                    .map(choice => `
                        <div class="choice-row">
                            <input
                                value="${escapeAttr(choice.label)}"
                                onchange="updateChoice(
                                    '${escapeAttr(group.groupId)}',
                                    '${escapeAttr(question.questionId)}',
                                    '${escapeAttr(choice.choiceId)}',
                                    this.value
                                )"
                            >

                            <button
                                class="btn btn-danger btn-sm"
                                onclick="deleteChoice(
                                    '${escapeAttr(group.groupId)}',
                                    '${escapeAttr(question.questionId)}',
                                    '${escapeAttr(choice.choiceId)}'
                                )">
                                削除
                            </button>
                        </div>
                    `).join('')}
            </div>

            <div class="add-row">
                <button
                    class="btn btn-secondary btn-sm"
                    onclick="addChoice(
                        '${escapeAttr(group.groupId)}',
                        '${escapeAttr(question.questionId)}'
                    )">
                    ＋ 選択肢追加
                </button>
            </div>
        </div>
    `;
}

function renderBranchEditor(question, group, survey) {
    const choices = question.choices || [];

    return `
        <div style="margin-top:15px">
            <label class="form-label">条件分岐</label>

            ${
                choices.length
                    ? choices.map(choice => {
                        const rule =
                            (question.branchRules || [])
                                .find(r =>
                                    r.choiceId === choice.choiceId
                                );

                        return `
                            <div class="branch-row">
                                <div>
                                    <div class="text-muted">
                                        ${esc(choice.label)}
                                    </div>
                                </div>

                                <select
                                    onchange="setBranchRule(
                                        '${escapeAttr(group.groupId)}',
                                        '${escapeAttr(question.questionId)}',
                                        '${escapeAttr(choice.choiceId)}',
                                        this.value
                                    )">

                                    <option value="">
                                        次の質問を指定しない
                                    </option>

                                    ${getAllQuestions(survey)
                                        .filter(q =>
                                            q.questionId !==
                                            question.questionId
                                        )
                                        .map(q => `
                                            <option
                                                value="${escapeAttr(q.questionId)}"
                                                ${rule?.nextQuestionId === q.questionId ? 'selected' : ''}>
                                                ${esc(q.questionNumber)}
                                                ${esc(q.questionText)}
                                            </option>
                                        `).join('')}
                                </select>
                            </div>
                        `;
                    }).join('')
                    : `
                        <div class="text-muted">
                            選択肢を追加すると条件分岐を設定できます。
                        </div>
                    `
            }
        </div>
    `;
}

/* =========================================================
 * 編集操作
 * ======================================================= */

function updateGroupTitle(groupId, value) {
    const group = state.editingSurvey.groups
        .find(g => g.groupId === groupId);

    if (group) {
        group.title = value;
    }
}

function addGroup() {
    const survey = state.editingSurvey;

    survey.groups.push({
        groupId: createClientId('group'),
        title: `グループ${survey.groups.length + 1}`,
        sortOrder: survey.groups.length + 1,
        questions: []
    });

    recalculateNumbers(survey);
    render();
}

function confirmDeleteGroup(groupId) {
    const group = state.editingSurvey.groups
        .find(g => g.groupId === groupId);

    if (!group) {
        return;
    }

    const hasQuestions =
        (group.questions || []).length > 0;

    const message =
        hasQuestions
            ? 'このグループには質問があります。グループと質問を削除しますか？'
            : 'このグループを削除しますか？';

    openModal(
        'グループ削除',
        message,
        async () => {
            state.editingSurvey.groups =
                state.editingSurvey.groups
                    .filter(g => g.groupId !== groupId);

            if (!state.editingSurvey.groups.length) {
                state.editingSurvey.groups.push({
                    groupId: createClientId('group'),
                    title: 'グループ1',
                    sortOrder: 1,
                    questions: []
                });
            }

            recalculateNumbers(state.editingSurvey);
            render();
        },
        '削除'
    );
}

function addQuestion(groupId) {
    const group = state.editingSurvey.groups
        .find(g => g.groupId === groupId);

    if (!group) {
        return;
    }

    group.questions.push({
        questionId: createClientId('question'),
        groupId,
        sortOrder: group.questions.length + 1,
        questionText: '',
        type: 'single',
        required: false,
        choices: [
            {
                choiceId: createClientId('choice'),
                label: '選択肢1',
                sortOrder: 1
            },
            {
                choiceId: createClientId('choice'),
                label: '選択肢2',
                sortOrder: 2
            }
        ],
        branchRules: []
    });

    recalculateNumbers(state.editingSurvey);
    render();
}

function confirmDeleteQuestion(groupId, questionId) {
    openModal(
        '質問削除',
        'この質問を削除しますか？条件分岐の参照も整理されます。',
        async () => {
            const group = state.editingSurvey.groups
                .find(g => g.groupId === groupId);

            if (!group) {
                return;
            }

            group.questions =
                group.questions.filter(
                    q => q.questionId !== questionId
                );

            for (const g of state.editingSurvey.groups) {
                for (const q of g.questions) {
                    q.branchRules =
                        (q.branchRules || [])
                            .filter(r =>
                                r.nextQuestionId !== questionId
                            );
                }
            }

            recalculateNumbers(state.editingSurvey);
            render();
        },
        '削除'
    );
}

function updateQuestion(
    groupId,
    questionId,
    key,
    value
) {
    const question = getQuestion(
        state.editingSurvey,
        questionId
    );

    if (!question) {
        return;
    }

    question[key] = value;
}

function changeQuestionType(
    groupId,
    questionId,
    type
) {
    const question = getQuestion(
        state.editingSurvey,
        questionId
    );

    if (!question) {
        return;
    }

    question.type = type;

    if (type === 'text') {
        question.choices = [];
        question.branchRules = [];
    }

    if (
        (type === 'single' || type === 'multiple') &&
        (!question.choices || !question.choices.length)
    ) {
        question.choices = [
            {
                choiceId: createClientId('choice'),
                label: '選択肢1',
                sortOrder: 1
            },
            {
                choiceId: createClientId('choice'),
                label: '選択肢2',
                sortOrder: 2
            }
        ];
    }

    render();
}

function addChoice(groupId, questionId) {
    const question =
        getQuestion(state.editingSurvey, questionId);

    if (!question) {
        return;
    }

    const choices = question.choices || [];

    choices.push({
        choiceId: createClientId('choice'),
        label: `選択肢${choices.length + 1}`,
        sortOrder: choices.length + 1
    });

    question.choices = choices;

    render();
}

function updateChoice(
    groupId,
    questionId,
    choiceId,
    value
) {
    const question =
        getQuestion(state.editingSurvey, questionId);

    if (!question) {
        return;
    }

    const choice =
        question.choices.find(c => c.choiceId === choiceId);

    if (choice) {
        choice.label = value;
    }
}

function deleteChoice(
    groupId,
    questionId,
    choiceId
) {
    const question =
        getQuestion(state.editingSurvey, questionId);

    if (!question) {
        return;
    }

    question.choices =
        question.choices.filter(
            c => c.choiceId !== choiceId
        );

    question.choices.forEach((c, i) => {
        c.sortOrder = i + 1;
    });

    question.branchRules =
        (question.branchRules || [])
            .filter(r => r.choiceId !== choiceId);

    render();
}

function setBranchRule(
    groupId,
    questionId,
    choiceId,
    nextQuestionId
) {
    const question =
        getQuestion(state.editingSurvey, questionId);

    if (!question) {
        return;
    }

    question.branchRules =
        (question.branchRules || [])
            .filter(r => r.choiceId !== choiceId);

    if (nextQuestionId) {
        question.branchRules.push({
            questionId,
            choiceId,
            nextQuestionId
        });
    }
}

function moveQuestion(
    fromGroupId,
    questionId,
    toGroupId
) {
    if (!toGroupId || fromGroupId === toGroupId) {
        return;
    }

    const fromGroup =
        state.editingSurvey.groups
            .find(g => g.groupId === fromGroupId);

    const toGroup =
        state.editingSurvey.groups
            .find(g => g.groupId === toGroupId);

    if (!fromGroup || !toGroup) {
        return;
    }

    const index =
        fromGroup.questions.findIndex(
            q => q.questionId === questionId
        );

    if (index < 0) {
        return;
    }

    const [question] =
        fromGroup.questions.splice(index, 1);

    question.groupId = toGroupId;
    toGroup.questions.push(question);

    recalculateNumbers(state.editingSurvey);
    render();
}

function getQuestion(survey, questionId) {
    for (const group of survey.groups || []) {
        const question =
            (group.questions || [])
                .find(q => q.questionId === questionId);

        if (question) {
            return question;
        }
    }

    return null;
}

function getAllQuestions(survey) {
    return (survey.groups || [])
        .sort((a,b) => a.sortOrder - b.sortOrder)
        .flatMap(group =>
            (group.questions || [])
                .sort((a,b) => a.sortOrder - b.sortOrder)
        );
}

function getQuestionNumber(survey, questionId) {
    let index = 0;

    for (const group of survey.groups || []) {
        for (let i = 0; i < group.questions.length; i++) {
            const q = group.questions[i];

            if (q.questionId === questionId) {
                if (survey.numberingMode === 'group') {
                    return `Q${group.sortOrder}-${i + 1}`;
                }

                return `Q${index + 1}`;
            }

            index++;
        }
    }

    return '';
}

function recalculateNumbers(survey) {
    survey.groups =
        [...survey.groups]
            .sort((a,b) => a.sortOrder - b.sortOrder);

    let globalIndex = 1;

    survey.groups.forEach((group, gi) => {
        group.sortOrder = gi + 1;

        group.questions =
            [...(group.questions || [])];

        group.questions.forEach((question, qi) => {
            question.groupId = group.groupId;
            question.sortOrder = qi + 1;

            question.questionNumber =
                survey.numberingMode === 'group'
                    ? `Q${gi + 1}-${qi + 1}`
                    : `Q${globalIndex}`;

            globalIndex++;
        });
    });
}

/* =========================================================
 * D&D
 * ======================================================= */

let draggedGroupId = '';
let draggedQuestionId = '';

function onGroupDragStart(event, groupId) {
    draggedGroupId = groupId;
    event.dataTransfer.effectAllowed = 'move';
}

function onGroupDragOver(event) {
    event.preventDefault();
    event.currentTarget.classList.add('drag-over');
}

function onGroupDrop(event, targetGroupId) {
    event.preventDefault();

    event.currentTarget.classList.remove('drag-over');

    if (!draggedGroupId ||
        draggedGroupId === targetGroupId) {
        return;
    }

    const groups = state.editingSurvey.groups;

    const fromIndex =
        groups.findIndex(g => g.groupId === draggedGroupId);

    const toIndex =
        groups.findIndex(g => g.groupId === targetGroupId);

    if (fromIndex < 0 || toIndex < 0) {
        return;
    }

    const [group] = groups.splice(fromIndex, 1);

    groups.splice(toIndex, 0, group);

    recalculateNumbers(state.editingSurvey);
    draggedGroupId = '';

    render();
}

function onQuestionDragStart(event, questionId) {
    draggedQuestionId = questionId;
    event.dataTransfer.effectAllowed = 'move';
}

function onQuestionDragOver(event) {
    event.preventDefault();
}

function onQuestionDrop(event, targetGroupId) {
    event.preventDefault();

    if (!draggedQuestionId) {
        return;
    }

    let sourceGroup = null;
    let question = null;

    for (const group of state.editingSurvey.groups) {
        const index =
            group.questions.findIndex(
                q => q.questionId === draggedQuestionId
            );

        if (index >= 0) {
            sourceGroup = group;
            question = group.questions[index];
            group.questions.splice(index, 1);
            break;
        }
    }

    if (!question) {
        return;
    }

    const targetGroup =
        state.editingSurvey.groups
            .find(g => g.groupId === targetGroupId);

    if (!targetGroup) {
        return;
    }

    question.groupId = targetGroupId;
    targetGroup.questions.push(question);

    recalculateNumbers(state.editingSurvey);

    draggedQuestionId = '';

    render();
}

/* =========================================================
 * 保存・状態変更
 * ======================================================= */

async function saveSurvey() {
    const survey = state.editingSurvey;

    survey.title =
        document.getElementById('editTitle')?.value.trim() || '';

    survey.description =
        document.getElementById('editDescription')?.value || '';

    survey.startDate =
        fromDatetimeLocal(
            document.getElementById('editStartDate')?.value || ''
        );

    survey.endDate =
        fromDatetimeLocal(
            document.getElementById('editEndDate')?.value || ''
        );

    survey.numberingMode =
        document.getElementById('editNumberingMode')?.value || 'all';

    survey.allowResubmit =
        document.getElementById('editAllowResubmit')?.value === 'true';

    recalculateNumbers(survey);

    try {
        const result = await api(
            'save_survey',
            {
                survey: JSON.stringify(survey)
            }
        );

        showToast('アンケートを保存しました。', 'success');

        state.surveys = result.surveys || state.surveys;

        navigate(
            {view:'admin-survey-list'},
            true
        );
    } catch (error) {
        showError(error);
    }
}

function cancelEdit() {
    openModal(
        '編集内容を破棄',
        '編集内容を破棄しますか？',
        async () => {
            navigate({view:'admin-survey-list'});
        },
        '破棄して戻る'
    );
}

async function changeStatus(status) {
    const surveyId = state.editingSurveyId;

    if (!surveyId) {
        return;
    }

    const labels = {
        published: '公開',
        stopped: '停止'
    };

    const label = labels[status] || '状態変更';

    openModal(
        label,
        `このアンケートを「${statusLabel(status)}」にしますか？`,
        async () => {
            const data = await api(
                'change_status',
                {
                    surveyId,
                    status
                }
            );

            state.surveys = data.surveys;

            state.editingSurvey =
                findSurvey(surveyId);

            render();

            showToast('状態を変更しました。', 'success');
        }
    );
}

/* =========================================================
 * 複製・削除
 * ======================================================= */

function confirmDuplicate(surveyId) {
    const survey = findSurvey(surveyId);

    if (!survey) {
        return;
    }

    openModal(
        'アンケート複製',
        `「${survey.title}」を複製しますか？`,
        async () => {
            const data = await api(
                'duplicate_survey',
                {surveyId}
            );

            state.surveys = data.surveys;

            render();

            showToast('アンケートを複製しました。', 'success');
        },
        '複製'
    );
}

function confirmDeleteSurvey(surveyId) {
    const survey = findSurvey(surveyId);

    if (!survey) {
        return;
    }

    openModal(
        'アンケート削除',
        `「${survey.title}」を削除しますか？関連回答データも整理されます。`,
        async () => {
            const data = await api(
                'delete_survey',
                {surveyId}
            );

            state.surveys = data.surveys;
            state.responses = data.responses;

            navigate(
                {view:'admin-survey-list'},
                true
            );

            showToast('アンケートを削除しました。', 'success');
        },
        '削除'
    );
}

/* =========================================================
 * プレビュー
 * ======================================================= */

function renderPreview() {
    const survey =
        findSurvey(state.editingSurveyId);

    if (!survey) {
        return renderAdminHeader() + `
            <main class="container">
                <div class="alert alert-error">
                    対象アンケートがありません。
                </div>
            </main>
        `;
    }

    return `
        ${renderAdminHeader()}

        <main class="container">
            <div class="toolbar">
                <h1 class="page-title grow">
                    プレビュー
                </h1>

                <button
                    class="btn btn-secondary"
                    onclick="navigate({
                        view:'admin-survey-edit',
                        surveyId:'${escapeAttr(survey.surveyId)}'
                    })">
                    編集へ戻る
                </button>

                <button
                    class="btn btn-secondary"
                    onclick="togglePreviewDevice()">
                    PC / スマートフォン切替
                </button>
            </div>

            <div class="preview-shell">
                <div id="previewDevice" class="preview-device">
                    <h1>${esc(survey.title)}</h1>

                    <p class="text-muted">
                        ${esc(survey.description)}
                    </p>

                    ${survey.groups.map(group => `
                        <section class="card">
                            <h2>${esc(group.title)}</h2>

                            ${(group.questions || []).map(q => `
                                <div class="answer-question">
                                    <div>
                                        <strong>
                                            ${esc(q.questionNumber)}
                                            ${q.required ? ' *' : ''}
                                        </strong>
                                    </div>

                                    <h3>
                                        ${esc(q.questionText)}
                                    </h3>

                                    ${
                                        q.type === 'text'
                                            ? `
                                                <textarea
                                                    placeholder="回答を入力してください"
                                                    disabled>
                                                </textarea>
                                            `
                                            : `
                                                ${(q.choices || [])
                                                    .map(c => `
                                                        <label class="choice-label">
                                                            <input
                                                                type="${q.type === 'single' ? 'radio' : 'checkbox'}"
                                                                disabled
                                                            >
                                                            ${esc(c.label)}
                                                        </label>
                                                    `).join('')}
                                            `
                                    }
                                </div>
                            `).join('')}
                        </section>
                    `).join('')}

                    <div class="alert alert-info">
                        プレビューから回答送信は行いません。
                    </div>
                </div>
            </div>
        </main>
    `;
}

function togglePreviewDevice() {
    const device =
        document.getElementById('previewDevice');

    if (device) {
        device.classList.toggle('mobile');
    }
}

/* =========================================================
 * 回答者
 * ======================================================= */

function renderAnswer() {
    const survey = currentSurvey();

    if (!survey) {
        return `
            <main class="answer-container">
                <div class="alert alert-error">
                    対象アンケートが存在しません。
                </div>
            </main>
        `;
    }

    if (INITIAL_RESPONDENT_ERROR) {
        return `
            <main class="answer-container">
                <div class="answer-header">
                    <h1>${esc(survey.title)}</h1>
                </div>

                <div class="alert alert-error">
                    ${esc(INITIAL_RESPONDENT_ERROR)}
                </div>
            </main>
        `;
    }

    if (survey.status !== 'published') {
        return `
            <main class="answer-container">
                <div class="answer-header">
                    <h1>${esc(survey.title)}</h1>
                </div>

                <div class="alert alert-error">
                    このアンケートは現在回答できません。
                </div>
            </main>
        `;
    }

    if (state.answerToken) {
        const existing =
            state.responses.find(r =>
                r.surveyId === survey.surveyId &&
                r.token === state.answerToken
            );

        if (existing &&
            existing.completedAt &&
            !survey.allowResubmit) {
            return renderAlreadyAnswered();
        }
    }

    const questions =
        getVisibleQuestions(survey);

    return `
        <main class="answer-container">

            <section class="answer-header">
                <h1>${esc(survey.title)}</h1>
                <p>${esc(survey.description)}</p>
            </section>

            ${
                questions.map(q =>
                    renderAnswerQuestion(q)
                ).join('')
            }

            <div class="answer-nav">
                <span></span>

                <button
                    class="btn btn-primary"
                    onclick="goToConfirm()">
                    次へ
                </button>
            </div>
        </main>
    `;
}

function renderAlreadyAnswered() {
    return `
        <main class="answer-container">
            <section class="answer-header">
                <h1>回答済み</h1>

                <div class="alert alert-info">
                    このアンケートはすでに回答済みです。
                </div>
            </section>
        </main>
    `;
}

function renderAnswerQuestion(question) {
    const value =
        state.answerData[question.questionId];

    return `
        <section
            class="answer-question"
            id="answer-question-${escapeAttr(question.questionId)}">

            <div class="question-number">
                ${esc(question.questionNumber)}
            </div>

            <h2 style="font-size:18px">
                ${esc(question.questionText)}
                ${question.required ? '<span class="text-danger"> *</span>' : ''}
            </h2>

            ${
                question.type === 'text'
                    ? `
                        <textarea
                            data-question-id="${escapeAttr(question.questionId)}"
                            onchange="setAnswer(
                                '${escapeAttr(question.questionId)}',
                                this.value
                            )">${esc(value || '')}</textarea>
                    `
                    : `
                        ${(question.choices || []).map(choice => {
                            const checked =
                                Array.isArray(value)
                                    ? value.includes(choice.choiceId)
                                    : value === choice.choiceId;

                            return `
                                <label class="choice-label">
                                    <input
                                        type="${question.type === 'single' ? 'radio' : 'checkbox'}"
                                        name="q_${escapeAttr(question.questionId)}"
                                        value="${escapeAttr(choice.choiceId)}"
                                        ${checked ? 'checked' : ''}
                                        onchange="setChoiceAnswer(
                                            '${escapeAttr(question.questionId)}',
                                            '${escapeAttr(choice.choiceId)}',
                                            ${question.type === 'multiple'}
                                        )"
                                    >

                                    ${esc(choice.label)}
                                </label>
                            `;
                        }).join('')}
                    `
            }

            <div
                class="text-danger hidden"
                id="error-${escapeAttr(question.questionId)}">
                必須項目です。
            </div>
        </section>
    `;
}

function setAnswer(questionId, value) {
    state.answerData[questionId] = value;
}

function setChoiceAnswer(
    questionId,
    choiceId,
    multiple
) {
    if (multiple) {
        let values =
            Array.isArray(state.answerData[questionId])
                ? [...state.answerData[questionId]]
                : [];

        if (values.includes(choiceId)) {
            values = values.filter(v => v !== choiceId);
        } else {
            values.push(choiceId);
        }

        state.answerData[questionId] = values;
    } else {
        state.answerData[questionId] = choiceId;
    }
}

function getVisibleQuestions(survey) {
    const all = getAllQuestions(survey);

    if (!all.length) {
        return [];
    }

    const visible = [];
    let currentIndex = 0;

    while (currentIndex < all.length) {
        const question = all[currentIndex];

        visible.push(question);

        if (question.type === 'single') {
            const answer =
                state.answerData[question.questionId];

            const rule =
                (question.branchRules || [])
                    .find(r =>
                        r.choiceId === answer
                    );

            if (rule?.nextQuestionId) {
                const nextIndex =
                    all.findIndex(q =>
                        q.questionId === rule.nextQuestionId
                    );

                if (nextIndex >= 0) {
                    currentIndex = nextIndex;
                    continue;
                }
            }
        }

        currentIndex++;
    }

    return visible;
}

function validateAnswers() {
    const survey = currentSurvey();

    if (!survey) {
        return false;
    }

    const questions =
        getVisibleQuestions(survey);

    let valid = true;

    questions.forEach(question => {
        const value =
            state.answerData[question.questionId];

        let answered = false;

        if (Array.isArray(value)) {
            answered = value.length > 0;
        } else {
            answered =
                String(value ?? '').trim() !== '';
        }

        const error =
            document.getElementById(
                `error-${question.questionId}`
            );

        if (question.required && !answered) {
            valid = false;

            if (error) {
                error.classList.remove('hidden');
            }

            const element =
                document.getElementById(
                    `answer-question-${question.questionId}`
                );

            element?.classList.add('error');
        } else {
            error?.classList.add('hidden');

            document.getElementById(
                `answer-question-${question.questionId}`
            )?.classList.remove('error');
        }
    });

    return valid;
}

function goToConfirm() {
    if (!validateAnswers()) {
        showToast(
            '必須項目を入力してください。',
            'error'
        );
        return;
    }

    navigate({
        view:'confirm',
        surveyId:state.surveyId,
        ...(state.answerToken
            ? {token:state.answerToken}
            : {})
    });
}

function renderConfirm() {
    const survey = currentSurvey();

    if (!survey) {
        return `
            <main class="answer-container">
                <div class="alert alert-error">
                    対象アンケートがありません。
                </div>
            </main>
        `;
    }

    const questions =
        getVisibleQuestions(survey);

    return `
        <main class="answer-container">
            <section class="answer-header">
                <h1>回答内容確認</h1>
                <p>${esc(survey.title)}</p>
            </section>

            ${questions.map(q => {
                const value =
                    state.answerData[q.questionId];

                let display = '';

                if (q.type === 'text') {
                    display = String(value || '');
                } else if (Array.isArray(value)) {
                    display = value
                        .map(id =>
                            q.choices.find(
                                c => c.choiceId === id
                            )?.label || ''
                        )
                        .filter(Boolean)
                        .join('、');
                } else {
                    display =
                        q.choices.find(
                            c => c.choiceId === value
                        )?.label || '';
                }

                return `
                    <section class="answer-question">
                        <div class="question-number">
                            ${esc(q.questionNumber)}
                        </div>

                        <h2 style="font-size:18px">
                            ${esc(q.questionText)}
                        </h2>

                        <div>
                            ${esc(display || '未回答')}
                        </div>
                    </section>
                `;
            }).join('')}

            <div class="answer-nav">
                <button
                    class="btn btn-secondary"
                    onclick="navigate({
                        view:'answer',
                        surveyId:'${escapeAttr(state.surveyId)}'
                        ${state.answerToken
                            ? `,token:'${escapeAttr(state.answerToken)}'`
                            : ''}
                    })">
                    戻る
                </button>

                <button
                    class="btn btn-primary"
                    onclick="confirmSubmitResponse()">
                    回答を送信
                </button>
            </div>
        </main>
    `;
}

function confirmSubmitResponse() {
    openModal(
        '回答送信',
        'この内容で回答を送信しますか？送信後は設定により再回答できない場合があります。',
        submitResponse,
        '送信'
    );
}

async function submitResponse() {
    try {
        const data = await api(
            'save_response',
            {
                surveyId:state.surveyId,
                token:state.answerToken,
                answers:JSON.stringify(state.answerData)
            }
        );

        navigate({
            view:'complete',
            surveyId:state.surveyId,
            ...(state.answerToken
                ? {token:state.answerToken}
                : {})
        });

        showToast('回答を送信しました。', 'success');
    } catch (error) {
        showError(error);
    }
}

function renderComplete() {
    return `
        <main class="answer-container">
            <section class="answer-header">
                <h1>回答完了</h1>

                <div class="alert alert-success">
                    回答を受け付けました。ご協力ありがとうございました。
                </div>
            </section>
        </main>
    `;
}

/* =========================================================
 * 送信画面
 * ======================================================= */

function renderSend() {
    const survey =
        findSurvey(state.sendingSurveyId);

    if (!survey) {
        return `
            ${renderAdminHeader()}
            <main class="container">
                <div class="alert alert-error">
                    対象アンケートが存在しません。
                </div>
            </main>
        `;
    }

    let customers = [...state.customers];

    const keyword =
        state.customerSearch.trim().toLowerCase();

    if (keyword) {
        customers = customers.filter(c => {
            return [
                c.name,
                c.organizationName,
                c.email
            ].some(v =>
                String(v || '')
                    .toLowerCase()
                    .includes(keyword)
            );
        });
    }

    if (state.customerStatus !== 'all') {
        customers =
            customers.filter(c =>
                customerStatus(c, survey.surveyId) ===
                state.customerStatus
            );
    }

    return `
        ${renderAdminHeader()}

        <main class="container">
            <div class="toolbar">
                <h1 class="page-title grow">
                    顧客選択・メール送信
                </h1>

                <button
                    class="btn btn-secondary"
                    onclick="navigate({
                        view:'admin-survey-list'
                    })">
                    一覧へ戻る
                </button>
            </div>

            <div class="card">
                <h2 class="card-title">
                    対象アンケート
                </h2>

                <strong>
                    ${esc(survey.title)}
                </strong>

                <div style="margin-top:8px">
                    ${statusBadge(survey.status)}
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">顧客検索・選択</h2>

                <div class="toolbar">
                    <input
                        class="search-box grow"
                        id="customerSearch"
                        placeholder="顧客名・組織名・メールアドレス"
                        value="${escapeAttr(state.customerSearch)}"
                    >

                    <select
                        id="customerStatus"
                        style="max-width:230px">
                        <option value="all">すべて</option>
                        <option value="unsent"
                            ${state.customerStatus === 'unsent' ? 'selected' : ''}>
                            未送信
                        </option>
                        <option value="sent"
                            ${state.customerStatus === 'sent' ? 'selected' : ''}>
                            送信済み / 未回答
                        </option>
                        <option value="answered"
                            ${state.customerStatus === 'answered' ? 'selected' : ''}>
                            回答済み
                        </option>
                    </select>

                    <button
                        class="btn btn-secondary"
                        onclick="selectReminders()">
                        リマインド対象を選択
                    </button>
                </div>

                <div class="toolbar">
                    <button
                        class="btn btn-secondary btn-sm"
                        onclick="selectAllVisibleCustomers()">
                        表示顧客をすべて選択
                    </button>

                    <button
                        class="btn btn-secondary btn-sm"
                        onclick="clearSelectedCustomers()">
                        すべて解除
                    </button>

                    <span>
                        選択件数:
                        <strong id="selectedCustomerCount">
                            ${state.selectedCustomerIds.size}
                        </strong>
                    </span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th></th>
                                <th>組織名</th>
                                <th>氏名</th>
                                <th>メールアドレス</th>
                                <th>電話番号</th>
                                <th>最終送信日時</th>
                                <th>送信回数</th>
                                <th>回答ステータス</th>
                                <th>kintone</th>
                            </tr>
                        </thead>

                        <tbody>
                            ${customers.map(c =>
                                renderCustomerRow(
                                    c,
                                    survey.surveyId
                                )
                            ).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">メール作成</h2>

                <div class="form-group">
                    <label class="form-label">件名</label>

                    <input
                        id="sendSubject"
                        value="${escapeAttr(state.sendSubject)}"
                    >
                </div>

                <div class="form-group" style="margin-top:12px">
                    <label class="form-label">本文</label>

                    <textarea id="sendBody">${esc(state.sendBody)}</textarea>
                </div>

                <div class="alert alert-info">
                    使用可能な変数:
                    <span class="code">{顧客名}</span>
                    <span class="code">{アンケートURL}</span>
                </div>

                <div class="toolbar">
                    <button
                        class="btn btn-secondary"
                        onclick="previewMail()">
                        メール内容確認
                    </button>

                    <button
                        class="btn btn-primary"
                        onclick="confirmBulkSend('bulk')">
                        一括送信
                    </button>

                    <button
                        class="btn btn-warning"
                        onclick="confirmBulkSend('resend')">
                        再送
                    </button>

                    <button
                        class="btn btn-success"
                        onclick="confirmBulkSend('reminder')">
                        リマインド送信
                    </button>
                </div>
            </div>

            ${renderSendResult()}

            ${renderHistory(survey.surveyId)}
        </main>
    `;
}

function renderCustomerRow(customer, surveyId) {
    const status =
        customerStatus(customer, surveyId);

    const checked =
        state.selectedCustomerIds.has(
            customer.customerId
        );

    return `
        <tr>
            <td>
                <input
                    type="checkbox"
                    ${checked ? 'checked' : ''}
                    onchange="toggleCustomer(
                        '${escapeAttr(customer.customerId)}',
                        this.checked
                    )"
                >
            </td>

            <td>${esc(customer.organizationName)}</td>
            <td>${esc(customer.name)}</td>
            <td>${esc(customer.email)}</td>
            <td>${esc(customer.phone)}</td>
            <td>${esc(formatDate(customer.lastSentAt))}</td>
            <td>${esc(customer.sendCount || 0)}</td>
            <td>${esc(customerStatusLabel(status))}</td>
            <td>${esc(customer.kintoneStatus || '-')}</td>
        </tr>
    `;
}

function customerStatus(customer, surveyId) {
    const response =
        state.responses.find(r =>
            r.surveyId === surveyId &&
            r.customerId === customer.customerId &&
            r.completedAt
        );

    if (response) {
        return 'answered';
    }

    if ((customer.sendCount || 0) > 0) {
        return 'sent';
    }

    return 'unsent';
}

function customerStatusLabel(status) {
    return {
        unsent:'未送信',
        sent:'送信済み / 未回答',
        answered:'回答済み'
    }[status] || status;
}

function toggleCustomer(customerId, checked) {
    if (checked) {
        state.selectedCustomerIds.add(customerId);
    } else {
        state.selectedCustomerIds.delete(customerId);
    }

    updateSelectedCount();
}

function updateSelectedCount() {
    const node =
        document.getElementById('selectedCustomerCount');

    if (node) {
        node.textContent =
            state.selectedCustomerIds.size;
    }
}

function selectAllVisibleCustomers() {
    state.customers.forEach(customer => {
        const keyword =
            state.customerSearch.trim().toLowerCase();

        const matches =
            !keyword ||
            [
                customer.name,
                customer.organizationName,
                customer.email
            ].some(v =>
                String(v || '')
                    .toLowerCase()
                    .includes(keyword)
            );

        const statusMatches =
            state.customerStatus === 'all' ||
            customerStatus(
                customer,
                state.sendingSurveyId
            ) === state.customerStatus;

        if (matches && statusMatches) {
            state.selectedCustomerIds.add(
                customer.customerId
            );
        }
    });

    render();
}

function clearSelectedCustomers() {
    state.selectedCustomerIds.clear();
    render();
}

function selectReminders() {
    state.selectedCustomerIds.clear();

    state.customers.forEach(customer => {
        if (
            customerStatus(
                customer,
                state.sendingSurveyId
            ) === 'sent'
        ) {
            state.selectedCustomerIds.add(
                customer.customerId
            );
        }
    });

    render();
}

function saveMailFields() {
    state.sendSubject =
        document.getElementById('sendSubject')?.value ||
        state.sendSubject;

    state.sendBody =
        document.getElementById('sendBody')?.value ||
        state.sendBody;
}

function expandMail(text, customer, survey) {
    const url =
        buildAnswerUrl(
            survey.surveyId,
            customer.customerId
        );

    return String(text || '')
        .replaceAll(
            '{顧客名}',
            customer.name || ''
        )
        .replaceAll(
            '{アンケートURL}',
            url
        );
}

function buildAnswerUrl(surveyId, customerId) {
    const url =
        new URL('index.php', location.href);

    url.searchParams.set('view', 'answer');
    url.searchParams.set('surveyId', surveyId);

    if (customerId) {
        url.searchParams.set(
            'token',
            customerId
        );
    }

    return url.href;
}

function previewMail() {
    saveMailFields();

    const survey =
        findSurvey(state.sendingSurveyId);

    const customers =
        state.customers.filter(c =>
            state.selectedCustomerIds.has(
                c.customerId
            )
        );

    if (!customers.length) {
        showToast(
            '顧客を選択してください。',
            'error'
        );
        return;
    }

    const content =
        customers.map(customer => {
            const subject =
                expandMail(
                    state.sendSubject,
                    customer,
                    survey
                );

            const body =
                expandMail(
                    state.sendBody,
                    customer,
                    survey
                );

            return `
顧客: ${customer.name}
メール: ${customer.email}

件名:
${subject}

本文:
${body}
`;
        }).join('\n----------------\n');

    openModal(
        '送信予定メール確認',
        content,
        async () => {},
        '閉じる'
    );
}

function confirmBulkSend(type) {
    saveMailFields();

    const selected =
        state.customers.filter(c =>
            state.selectedCustomerIds.has(
                c.customerId
            )
        );

    if (!selected.length) {
        showToast(
            '送信対象を選択してください。',
            'error'
        );
        return;
    }

    const sentAlready =
        selected.filter(c =>
            (c.sendCount || 0) > 0
        ).length;

    let message =
        `${selected.length}件に送信しますか？`;

    if (type === 'resend' && sentAlready) {
        message +=
            `\n${sentAlready}件は送信済み顧客です。`;
    }

    if (type === 'reminder') {
        message =
            `${selected.length}件の未回答顧客へリマインド送信しますか？`;
    }

    openModal(
        type === 'resend'
            ? '再送確認'
            : type === 'reminder'
                ? 'リマインド確認'
                : '一括送信確認',
        message,
        () => executeSend(type),
        '送信'
    );
}

async function executeSend(type) {
    const survey =
        findSurvey(state.sendingSurveyId);

    const customerIds =
        [...state.selectedCustomerIds];

    try {
        const data = await api(
            'send_mail',
            {
                surveyId:state.sendingSurveyId,
                customerIds:JSON.stringify(customerIds),
                subject:state.sendSubject,
                body:state.sendBody,
                sendType:type
            }
        );

        state.sendResult = data.result;

        state.customers = data.customers;
        state.history = data.history;

        render();

        showToast(
            `送信処理完了: 成功 ${data.result.successCount}件 / 失敗 ${data.result.failureCount}件`,
            data.result.failureCount
                ? 'error'
                : 'success'
        );
    } catch (error) {
        showError(error);
    }
}

function renderSendResult() {
    if (!state.sendResult) {
        return '';
    }

    const result = state.sendResult;

    return `
        <div class="card">
            <h2 class="card-title">送信結果</h2>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">対象件数</div>
                    <div class="stat-value">
                        ${result.totalCount}
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-label">成功件数</div>
                    <div class="stat-value text-success">
                        ${result.successCount}
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-label">失敗件数</div>
                    <div class="stat-value text-danger">
                        ${result.failureCount}
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-label">送信日時</div>
                    <div style="margin-top:8px">
                        ${esc(formatDate(result.sentAt))}
                    </div>
                </div>
            </div>

            <div style="margin-top:15px">
                ${result.details.map(d => `
                    <div class="alert ${
                        d.success
                            ? 'alert-success'
                            : 'alert-error'
                    }">
                        <strong>${esc(d.customerName)}</strong>
                        -
                        ${d.success
                            ? '送信成功'
                            : '送信失敗'}
                        ${
                            d.error
                                ? `<br>${esc(d.error)}`
                                : ''
                        }
                    </div>
                `).join('')}
            </div>
        </div>
    `;
}

function renderHistory(surveyId) {
    const histories =
        state.history.filter(
            h => h.surveyId === surveyId
        );

    return `
        <div class="card">
            <h2 class="card-title">
                送信履歴
            </h2>

            ${
                histories.length
                    ? histories.map(h => `
                        <details style="margin-bottom:10px">
                            <summary>
                                ${esc(formatDate(h.sentAt))}
                                /
                                ${esc(h.sendType)}
                                /
                                ${esc(h.subject)}
                                /
                                ${h.customerIds.length}件
                            </summary>

                            <div class="history-detail">
                                実行者:
                                ${esc(h.executedBy || '管理画面')}

                                件名:
                                ${esc(h.subject)}

                                ${h.details.map(d => `
                                    ----------------
                                    顧客:
                                    ${esc(d.customerName)}

                                    メール:
                                    ${esc(d.email)}

                                    アンケートURL:
                                    ${esc(d.surveyUrl)}

                                    本文:
                                    ${esc(d.expandedBody)}

                                    結果:
                                    ${d.success ? '成功' : '失敗'}
                                    ${d.error ? '\n' + esc(d.error) : ''}
                                `).join('\n')}
                            </div>
                        </details>
                    `).join('')
                    : `
                        <div class="empty">
                            送信履歴はありません。
                        </div>
                    `
            }
        </div>
    `;
}

/* =========================================================
 * 集計
 * ======================================================= */

function renderAggregation() {
    const survey =
        findSurvey(state.aggregationSurveyId);

    if (!survey) {
        return `
            ${renderAdminHeader()}
            <main class="container">
                <div class="alert alert-error">
                    対象アンケートが存在しません。
                </div>
            </main>
        `;
    }

    const responses =
        state.responses.filter(
            r => r.surveyId === survey.surveyId
        );

    const customerIds =
        new Set(
            state.customers.map(
                c => c.customerId
            )
        );

    const registeredResponses =
        responses.filter(
            r => r.customerId &&
                customerIds.has(r.customerId)
        );

    const unregistered =
        responses.filter(
            r => !r.customerId ||
                !customerIds.has(r.customerId)
        );

    const sentCount =
        state.customers.filter(
            c => (c.sendCount || 0) > 0
        ).length;

    const answeredCount =
        responses.filter(
            r => r.completedAt
        ).length;

    const unanswered =
        Math.max(
            sentCount - answeredCount,
            0
        );

    const rate =
        sentCount > 0
            ? ((answeredCount / sentCount) * 100).toFixed(1)
            : '0.0';

    return `
        ${renderAdminHeader()}

        <main class="container">
            <div class="toolbar">
                <h1 class="page-title grow">
                    回答集計・分析
                </h1>

                <button
                    class="btn btn-secondary"
                    onclick="exportCsv()">
                    CSV出力
                </button>

                <button
                    class="btn btn-secondary"
                    onclick="exportPdf()">
                    PDF出力
                </button>

                <button
                    class="btn btn-secondary"
                    onclick="navigate({
                        view:'admin-survey-list'
                    })">
                    一覧へ戻る
                </button>
            </div>

            <div class="card">
                <h2 class="card-title">
                    ${esc(survey.title)}
                </h2>

                <div>
                    対象アンケート:
                    <strong>
                        ${esc(survey.surveyId)}
                    </strong>
                </div>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">
                        送信対象者数
                    </div>
                    <div class="stat-value">
                        ${sentCount}
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-label">
                        回答数
                    </div>
                    <div class="stat-value">
                        ${answeredCount}
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-label">
                        未登録顧客からの回答
                    </div>
                    <div class="stat-value">
                        ${unregistered.length}
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-label">
                        未回答数
                    </div>
                    <div class="stat-value">
                        ${unanswered}
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-label">
                        回答率
                    </div>
                    <div class="stat-value">
                        ${rate}%
                    </div>
                </div>
            </div>

            <div class="card" style="margin-top:20px">
                <div class="toolbar">
                    <h2 class="card-title grow">
                        設問別集計
                    </h2>

                    <button
                        class="btn btn-secondary btn-sm"
                        onclick="selectAllAggregationQuestions()">
                        すべて選択
                    </button>

                    <button
                        class="btn btn-secondary btn-sm"
                        onclick="clearAggregationQuestions()">
                        すべて解除
                    </button>
                </div>

                ${getAllQuestions(survey)
                    .map(q =>
                        renderQuestionAggregation(
                            q,
                            responses
                        )
                    ).join('')}
            </div>

            <div class="card">
                <h2 class="card-title">
                    個別回答
                </h2>

                ${renderIndividualResponses(
                    survey,
                    responses
                )}
            </div>
        </main>
    `;
}

function renderQuestionAggregation(
    question,
    responses
) {
    const selected =
        state.aggregationQuestionSelection[
            question.questionId
        ] !== false;

    const values =
        responses
            .map(r =>
                r.answers?.[question.questionId]
            )
            .filter(v =>
                v !== undefined &&
                v !== null &&
                v !== ''
            );

    if (!selected) {
        return `
            <div class="card">
                <label>
                    <input
                        type="checkbox"
                        onchange="toggleAggregationQuestion(
                            '${escapeAttr(question.questionId)}',
                            this.checked
                        )"
                    >
                    ${esc(question.questionNumber)}
                    ${esc(question.questionText)}
                </label>
            </div>
        `;
    }

    if (question.type === 'text') {
        return `
            <div class="card">
                <div class="toolbar">
                    <label>
                        <input
                            type="checkbox"
                            checked
                            onchange="toggleAggregationQuestion(
                                '${escapeAttr(question.questionId)}',
                                this.checked
                            )"
                        >
                        <strong>
                            ${esc(question.questionNumber)}
                            ${esc(question.questionText)}
                        </strong>
                    </label>
                </div>

                <div>
                    ${
                        values.length
                            ? values.map(v => `
                                <div class="alert alert-info">
                                    ${esc(v)}
                                </div>
                            `).join('')
                            : `
                                <div class="empty">
                                    回答なし
                                </div>
                            `
                    }
                </div>
            </div>
        `;
    }

    const counts = {};

    question.choices.forEach(choice => {
        counts[choice.choiceId] = 0;
    });

    values.forEach(value => {
        const selectedValues =
            Array.isArray(value)
                ? value
                : [value];

        selectedValues.forEach(id => {
            if (counts[id] !== undefined) {
                counts[id]++;
            }
        });
    });

    const max =
        Math.max(
            1,
            ...Object.values(counts)
        );

    const total = values.length;

    return `
        <div class="card">
            <div class="toolbar">
                <label>
                    <input
                        type="checkbox"
                        checked
                        onchange="toggleAggregationQuestion(
                            '${escapeAttr(question.questionId)}',
                            this.checked
                        )"
                    >

                    <strong>
                        ${esc(question.questionNumber)}
                        ${esc(question.questionText)}
                    </strong>
                </label>
            </div>

            ${question.choices.map(choice => {
                const count =
                    counts[choice.choiceId] || 0;

                const percent =
                    total
                        ? ((count / total) * 100).toFixed(1)
                        : '0.0';

                const width =
                    (count / max) * 100;

                return `
                    <div class="bar-row">
                        <div>
                            ${esc(choice.label)}
                        </div>

                        <div class="bar">
                            <span style="width:${width}%"></span>
                        </div>

                        <div>
                            ${count}件
                            (${percent}%)
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
}

function toggleAggregationQuestion(
    questionId,
    checked
) {
    state.aggregationQuestionSelection[
        questionId
    ] = checked;

    render();
}

function selectAllAggregationQuestions() {
    const survey =
        findSurvey(state.aggregationSurveyId);

    if (!survey) {
        return;
    }

    getAllQuestions(survey)
        .forEach(q => {
            state.aggregationQuestionSelection[
                q.questionId
            ] = true;
        });

    render();
}

function clearAggregationQuestions() {
    const survey =
        findSurvey(state.aggregationSurveyId);

    if (!survey) {
        return;
    }

    getAllQuestions(survey)
        .forEach(q => {
            state.aggregationQuestionSelection[
                q.questionId
            ] = false;
        });

    render();
}

function renderIndividualResponses(
    survey,
    responses
) {
    if (!responses.length) {
        return `
            <div class="empty">
                回答データはありません。
            </div>
        `;
    }

    return `
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>回答日時</th>
                        <th>回答者</th>
                        <th>メール</th>
                        <th>回答数</th>
                    </tr>
                </thead>

                <tbody>
                    ${responses.map(r => {
                        const customer =
                            state.customers.find(
                                c => c.customerId === r.customerId
                            );

                        return `
                            <tr>
                                <td>
                                    ${esc(formatDate(r.completedAt))}
                                </td>

                                <td>
                                    ${esc(
                                        customer?.name ||
                                        r.respondentName ||
                                        '未登録回答者'
                                    )}
                                </td>

                                <td>
                                    ${esc(
                                        customer?.email ||
                                        r.respondentEmail ||
                                        '-'
                                    )}
                                </td>

                                <td>
                                    ${Object.keys(
                                        r.answers || {}
                                    ).length}
                                </td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;
}

async function exportCsv() {
    const survey =
        findSurvey(state.aggregationSurveyId);

    if (!survey) {
        return;
    }

    try {
        const data =
            await api(
                'export_csv',
                {surveyId:survey.surveyId}
            );

        if (data.csv) {
            const blob =
                new Blob(
                    ['\uFEFF' + data.csv],
                    {type:'text/csv;charset=utf-8'}
                );

            const url =
                URL.createObjectURL(blob);

            const a =
                document.createElement('a');

            a.href = url;
            a.download =
                `survey-${survey.surveyId}.csv`;

            a.click();

            URL.revokeObjectURL(url);
        }

        showToast(
            'CSV出力を実行しました。',
            'success'
        );
    } catch (error) {
        showError(error);
    }
}

function exportPdf() {
    showToast(
        'PDF出力操作を実行しました。実PDF生成はプロトタイプ仕様上省略しています。',
        'success'
    );
}

/* =========================================================
 * kintone
 * ======================================================= */

function renderKintone() {
    const k =
        state.kintone || {};

    const fields =
        k.fields || [];

    const mapping =
        k.mapping || {};

    return `
        ${renderAdminHeader()}

        <main class="container">
            <h1 class="page-title">
                kintone連携設定
            </h1>

            <div class="card">
                <h2 class="card-title">
                    接続設定
                </h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            サブドメイン
                        </label>

                        <input
                            id="kSubdomain"
                            placeholder="https://xxxx.cybozu.com"
                            value="${escapeAttr(k.subdomain || '')}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            顧客管理アプリID
                        </label>

                        <input
                            id="kAppId"
                            value="${escapeAttr(k.appId || '')}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            ログイン名
                        </label>

                        <input
                            id="kLoginName"
                            value="${escapeAttr(k.loginName || '')}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            パスワード
                        </label>

                        <input
                            id="kPassword"
                            type="password"
                            placeholder="変更しない場合は空欄"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            SSL証明書検証
                        </label>

                        <select id="kSslVerify">
                            <option value="false"
                                ${!k.sslVerify ? 'selected' : ''}>
                                検証しない
                            </option>

                            <option value="true"
                                ${k.sslVerify ? 'selected' : ''}>
                                検証する
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            プロキシ
                        </label>

                        <input
                            id="kProxy"
                            placeholder="proxy.example.local:8080"
                            value="${escapeAttr(k.proxy || '')}"
                        >
                    </div>
                </div>

                <div class="toolbar" style="margin-top:16px">
                    <button
                        class="btn btn-primary"
                        onclick="saveKintone()">
                        設定保存
                    </button>

                    <button
                        class="btn btn-secondary"
                        onclick="testKintone()">
                        接続テスト
                    </button>

                    <button
                        class="btn btn-secondary"
                        onclick="getKintoneFields()">
                        項目一覧を再取得
                    </button>

                    <button
                        class="btn btn-success"
                        onclick="syncKintone()">
                        顧客情報を同期
                    </button>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">
                    kintoneフィールド
                </h2>

                ${
                    fields.length
                        ? `
                            <div class="kintone-fields">
                                <div class="table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>フィールドコード</th>
                                                <th>日本語ラベル</th>
                                                <th>タイプ</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            ${fields.map(f => `
                                                <tr>
                                                    <td>${esc(f.code)}</td>
                                                    <td>${esc(f.label)}</td>
                                                    <td>${esc(f.type)}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `
                        : `
                            <div class="empty">
                                「項目一覧を再取得」を実行してください。
                            </div>
                        `
                }
            </div>

            <div class="card">
                <h2 class="card-title">
                    フィールドマッピング
                </h2>

                <div class="mapping-grid">
                    ${renderMappingSelect(
                        'organizationName',
                        '組織名',
                        mapping.organizationName,
                        fields
                    )}

                    ${renderMappingSelect(
                        'name',
                        '氏名',
                        mapping.name,
                        fields
                    )}

                    ${renderMappingSelect(
                        'email',
                        'メールアドレス',
                        mapping.email,
                        fields
                    )}

                    ${renderMappingSelect(
                        'department',
                        '部署名',
                        mapping.department,
                        fields
                    )}

                    ${renderMappingSelect(
                        'phone',
                        '電話番号',
                        mapping.phone,
                        fields
                    )}
                </div>

                <div style="margin-top:18px">
                    <strong>住所</strong>

                    <div class="check-list" style="margin-top:10px">
                        ${fields.map(f => `
                            <label>
                                <input
                                    type="checkbox"
                                    class="addressField"
                                    value="${escapeAttr(f.code)}"
                                    ${(mapping.address || []).includes(f.code)
                                        ? 'checked'
                                        : ''}
                                >
                                ${esc(f.label)}
                                (${esc(f.code)})
                            </label>
                        `).join('')}
                    </div>
                </div>

                <button
                    class="btn btn-primary"
                    style="margin-top:16px"
                    onclick="saveKintoneMapping()">
                    マッピング保存
                </button>
            </div>
        </main>
    `;
}

function renderMappingSelect(
    key,
    label,
    selected,
    fields
) {
    return `
        <div class="form-group">
            <label class="form-label">
                ${esc(label)}
            </label>

            <select id="mapping_${escapeAttr(key)}">
                <option value="">
                    未設定
                </option>

                ${fields.map(f => `
                    <option
                        value="${escapeAttr(f.code)}"
                        ${selected === f.code ? 'selected' : ''}>
                        ${esc(f.label)}
                        (${esc(f.code)})
                    </option>
                `).join('')}
            </select>
        </div>
    `;
}

function getKintoneForm() {
    return {
        subdomain:
            document.getElementById('kSubdomain')?.value || '',

        appId:
            document.getElementById('kAppId')?.value || '',

        loginName:
            document.getElementById('kLoginName')?.value || '',

        password:
            document.getElementById('kPassword')?.value || '',

        sslVerify:
            document.getElementById('kSslVerify')?.value === 'true',

        proxy:
            document.getElementById('kProxy')?.value || ''
    };
}

async function saveKintone() {
    try {
        const form =
            getKintoneForm();

        const data =
            await api(
                'save_kintone',
                {
                    settings:
                        JSON.stringify(form)
                }
            );

        state.kintone =
            data.kintone;

        render();

        showToast(
            'kintone設定を保存しました。',
            'success'
        );
    } catch (error) {
        showError(error);
    }
}

async function testKintone() {
    try {
        const settings =
            getKintoneForm();

        const data =
            await api(
                'kintone_test',
                {
                    settings:
                        JSON.stringify(settings)
                }
            );

        showToast(
            data.message || '接続成功',
            'success'
        );
    } catch (error) {
        showError(error);
    }
}

async function getKintoneFields() {
    try {
        const settings =
            getKintoneForm();

        const data =
            await api(
                'kintone_fields',
                {
                    settings:
                        JSON.stringify(settings)
                }
            );

        state.kintone.fields =
            data.fields || [];

        render();

        showToast(
            'kintone項目一覧を取得しました。',
            'success'
        );
    } catch (error) {
        showError(error);
    }
}

async function saveKintoneMapping() {
    const mapping = {
        organizationName:
            document.getElementById(
                'mapping_organizationName'
            )?.value || '',

        name:
            document.getElementById(
                'mapping_name'
            )?.value || '',

        email:
            document.getElementById(
                'mapping_email'
            )?.value || '',

        department:
            document.getElementById(
                'mapping_department'
            )?.value || '',

        phone:
            document.getElementById(
                'mapping_phone'
            )?.value || '',

        address:
            [...document.querySelectorAll(
                '.addressField:checked'
            )].map(
                el => el.value
            )
    };

    try {
        const data =
            await api(
                'save_kintone_mapping',
                {
                    mapping:
                        JSON.stringify(mapping)
                }
            );

        state.kintone =
            data.kintone;

        showToast(
            'フィールドマッピングを保存しました。',
            'success'
        );
    } catch (error) {
        showError(error);
    }
}

async function syncKintone() {
    try {
        const data =
            await api(
                'kintone_sync'
            );

        state.customers =
            data.customers || [];

        showToast(
            data.message || '顧客同期完了',
            'success'
        );
    } catch (error) {
        showError(error);
    }
}

/* =========================================================
 * メール設定
 * ======================================================= */

function renderMail() {
    const mail =
        state.mail || {};

    return `
        ${renderAdminHeader()}

        <main class="container">
            <h1 class="page-title">
                メールサーバ設定
            </h1>

            <div class="card">
                <h2 class="card-title">
                    SMTP設定
                </h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            SMTPサーバ
                        </label>

                        <input
                            id="mServer"
                            value="${escapeAttr(mail.server || '')}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            SMTPポート
                        </label>

                        <input
                            id="mPort"
                            type="number"
                            value="${escapeAttr(mail.port || 587)}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            暗号化方式
                        </label>

                        <select id="mEncryption">
                            <option value="none"
                                ${mail.encryption === 'none' ? 'selected' : ''}>
                                なし
                            </option>

                            <option value="tls"
                                ${mail.encryption === 'tls' ? 'selected' : ''}>
                                STARTTLS
                            </option>

                            <option value="ssl"
                                ${mail.encryption === 'ssl' ? 'selected' : ''}>
                                SSL/TLS
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            SMTP認証
                        </label>

                        <select id="mAuth">
                            <option value="false"
                                ${!mail.auth ? 'selected' : ''}>
                                なし
                            </option>

                            <option value="true"
                                ${mail.auth ? 'selected' : ''}>
                                あり
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            SMTPユーザー名
                        </label>

                        <input
                            id="mUsername"
                            value="${escapeAttr(mail.username || '')}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            SMTPパスワード
                        </label>

                        <input
                            id="mPassword"
                            type="password"
                            placeholder="変更しない場合は空欄"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            送信元メールアドレス
                        </label>

                        <input
                            id="mFrom"
                            type="email"
                            value="${escapeAttr(mail.from || '')}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            送信元名
                        </label>

                        <input
                            id="mFromName"
                            value="${escapeAttr(mail.fromName || '')}"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            返信先メールアドレス
                        </label>

                        <input
                            id="mReplyTo"
                            type="email"
                            value="${escapeAttr(mail.replyTo || '')}"
                        >
                    </div>
                </div>

                <div style="margin-top:18px">
                    <strong>接続状態:</strong>

                    <span class="badge ${
                        mail.status === 'connected'
                            ? 'badge-published'
                            : mail.status === 'error'
                                ? 'badge-finished'
                                : 'badge-draft'
                    }">
                        ${
                            mail.status === 'connected'
                                ? '接続確認済み'
                                : mail.status === 'error'
                                    ? '接続できません'
                                    : '未設定'
                        }
                    </span>
                </div>

                <div class="toolbar" style="margin-top:18px">
                    <button
                        class="btn btn-primary"
                        onclick="saveMailSettings()">
                        設定保存
                    </button>

                    <button
                        class="btn btn-secondary"
                        onclick="testMail()">
                        テストメール
                    </button>
                </div>
            </div>
        </main>
    `;
}

function getMailForm() {
    return {
        server:
            document.getElementById('mServer')?.value || '',

        port:
            Number(
                document.getElementById('mPort')?.value || 587
            ),

        encryption:
            document.getElementById('mEncryption')?.value || 'none',

        auth:
            document.getElementById('mAuth')?.value === 'true',

        username:
            document.getElementById('mUsername')?.value || '',

        password:
            document.getElementById('mPassword')?.value || '',

        from:
            document.getElementById('mFrom')?.value || '',

        fromName:
            document.getElementById('mFromName')?.value || '',

        replyTo:
            document.getElementById('mReplyTo')?.value || ''
    };
}

async function saveMailSettings() {
    try {
        const settings =
            getMailForm();

        const data =
            await api(
                'save_mail',
                {
                    settings:
                        JSON.stringify(settings)
                }
            );

        state.mail =
            data.mail;

        render();

        showToast(
            'メール設定を保存しました。',
            'success'
        );
    } catch (error) {
        showError(error);
    }
}

async function testMail() {
    try {
        const settings =
            getMailForm();

        const data =
            await api(
                'test_mail',
                {
                    settings:
                        JSON.stringify(settings)
                }
            );

        state.mail =
            data.mail || state.mail;

        showToast(
            data.message || 'テストメール送信成功',
            'success'
        );
    } catch (error) {
        showError(error);
    }
}

/* =========================================================
 * 検索イベント
 * ======================================================= */

function attachEvents() {
    const surveySearch =
        document.getElementById('surveySearch');

    if (surveySearch) {
        surveySearch.addEventListener(
            'input',
            () => {
                state.surveySearch =
                    surveySearch.value;

                render();
            }
        );

        surveySearch.addEventListener(
            'keydown',
            event => {
                if (event.key === 'Enter') {
                    state.surveySearch =
                        surveySearch.value;

                    render();
                }
            }
        );
    }

    const surveySort =
        document.getElementById('surveySort');

    if (surveySort) {
        surveySort.addEventListener(
            'change',
            () => {
                state.surveySort =
                    surveySort.value;

                render();
            }
        );
    }

    const customerSearch =
        document.getElementById('customerSearch');

    if (customerSearch) {
        customerSearch.addEventListener(
            'input',
            () => {
                state.customerSearch =
                    customerSearch.value;

                render();
            }
        );

        customerSearch.addEventListener(
            'keydown',
            event => {
                if (event.key === 'Enter') {
                    state.customerSearch =
                        customerSearch.value;

                    render();
                }
            }
        );
    }

    const customerStatus =
        document.getElementById('customerStatus');

    if (customerStatus) {
        customerStatus.addEventListener(
            'change',
            () => {
                state.customerStatus =
                    customerStatus.value;

                render();
            }
        );
    }

    const statusChange =
        document.getElementById('statusChange');

    if (statusChange) {
        statusChange.addEventListener(
            'change',
            () => {
                const survey =
                    findSurvey(
                        state.editingSurveyId
                    );

                if (!survey) {
                    return;
                }

                if (
                    statusChange.value !==
                    survey.status
                ) {
                    changeStatus(
                        statusChange.value
                    );
                }
            }
        );
    }
}

/* =========================================================
 * 画面描画
 * ======================================================= */

function render() {
    syncStateFromUrl();

    switch (state.currentView) {
        case 'admin-survey-list':
            app.innerHTML =
                renderSurveyList();
            break;

        case 'admin-survey-edit':
            app.innerHTML =
                renderSurveyEdit();
            break;

        case 'admin-preview':
            app.innerHTML =
                renderPreview();
            break;

        case 'admin-send':
            app.innerHTML =
                renderSend();
            break;

        case 'admin-aggregation':
            app.innerHTML =
                renderAggregation();
            break;

        case 'admin-kintone':
            app.innerHTML =
                renderKintone();
            break;

        case 'admin-mail':
            app.innerHTML =
                renderMail();
            break;

        case 'answer':
            app.innerHTML =
                renderAnswer();
            break;

        case 'confirm':
            app.innerHTML =
                renderConfirm();
            break;

        case 'complete':
            app.innerHTML =
                renderComplete();
            break;

        default:
            navigate(
                {view:'admin-survey-list'},
                true
            );
            return;
    }

    attachEvents();
}

/* =========================================================
 * ユーティリティ
 * ======================================================= */

function createClientId(prefix) {
    return prefix +
        '_' +
        Date.now().toString(36) +
        '_' +
        Math.random()
            .toString(36)
            .slice(2,10);
}

function toDatetimeLocal(value) {
    if (!value) {
        return '';
    }

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) {
        return '';
    }

    const pad = n =>
        String(n).padStart(2, '0');

    return (
        d.getFullYear() +
        '-' +
        pad(d.getMonth() + 1) +
        '-' +
        pad(d.getDate()) +
        'T' +
        pad(d.getHours()) +
        ':' +
        pad(d.getMinutes())
    );
}

function fromDatetimeLocal(value) {
    if (!value) {
        return '';
    }

    const d = new Date(value);

    return d.toISOString();
}

/* =========================================================
 * 起動
 * ======================================================= */

(async function boot() {
    try {
        syncStateFromUrl();

        await loadAllData();

        /*
         * URLに存在しないsurveyIdが指定された場合、
         * 対象業務を表示しない。
         */
        if (
            ['admin-survey-edit',
             'admin-preview',
             'admin-send',
             'admin-aggregation',
             'answer',
             'confirm',
             'complete'
            ].includes(state.currentView)
        ) {
            if (
                state.surveyId &&
                !findSurvey(state.surveyId)
            ) {
                navigate(
                    {view:'admin-survey-list'},
                    true
                );

                return;
            }
        }

        render();
    } catch (error) {
        console.error(error);

        app.innerHTML = `
            <main class="container">
                <div class="card">
                    <h1 class="page-title">
                        システムを起動できませんでした。
                    </h1>

                    <div class="alert alert-error">
                        ${esc(error.message)}
                    </div>
                </div>
            </main>
        `;
    }
})();
</script>

<?php

/* =========================================================
 * PHP API
 * ======================================================= */

function handleApiRequest(): void
{
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === '') {
            apiError(
                'INVALID_ACTION',
                'actionが指定されていません。',
                400
            );
        }

        switch ($action) {
            case 'load_data':
                apiSuccess([
                    'surveys'   => applyFinishedStates(
                        readJson(SURVEYS_FILE, [])
                    ),
                    'customers' => readJson(
                        CUSTOMERS_FILE,
                        []
                    ),
                    'responses' => readJson(
                        RESPONSES_FILE,
                        []
                    ),
                    'history'   => readJson(
                        HISTORY_FILE,
                        []
                    ),
                    'kintone'   => readJson(
                        KINTONE_FILE,
                        defaultKintoneSettings()
                    ),
                    'mail'      => sanitizeMailForClient(
                        readJson(
                            MAIL_FILE,
                            defaultMailSettings()
                        )
                    ),
                ]);

            case 'save_survey':
                apiSaveSurvey();
                break;

            case 'change_status':
                apiChangeStatus();
                break;

            case 'delete_survey':
                apiDeleteSurvey();
                break;

            case 'duplicate_survey':
                apiDuplicateSurvey();
                break;

            case 'save_response':
                apiSaveResponse();
                break;

            case 'send_mail':
                apiSendMail();
                break;

            case 'export_csv':
                apiExportCsv();
                break;

            case 'save_kintone':
                apiSaveKintone();
                break;

            case 'save_kintone_mapping':
                apiSaveKintoneMapping();
                break;

            case 'kintone_test':
                apiKintoneTest();
                break;

            case 'kintone_fields':
                apiKintoneFields();
                break;

            case 'kintone_sync':
                apiKintoneSync();
                break;

            case 'save_mail':
                apiSaveMail();
                break;

            case 'test_mail':
                apiTestMail();
                break;

            default:
                apiError(
                    'UNKNOWN_ACTION',
                    '未知のactionです。',
                    400
                );
        }
    } catch (Throwable $e) {
        error_log(
            '[survey-system] ' .
            $e->getMessage() .
            "\n" .
            $e->getTraceAsString()
        );

        apiError(
            'SERVER_ERROR',
            'サーバー処理に失敗しました。' .
            (isDebugMode()
                ? "\n" . $e->getMessage()
                : ''),
            500
        );
    }
}

function apiSuccess(mixed $data): never
{
    http_response_code(200);

    echo json_encode(
        [
            'ok' => true,
            'data' => $data
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function apiError(
    string $code,
    string $message,
    int $status = 400
): never {
    http_response_code($status);

    echo json_encode(
        [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/* =========================================================
 * データファイル
 * ======================================================= */

function ensureDataDirectory(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) &&
            !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'dataディレクトリを作成できません。'
            );
        }
    }
}

function ensureInitialData(): void
{
    if (!file_exists(SURVEYS_FILE)) {
        writeJson(
            SURVEYS_FILE,
            createInitialSurveys()
        );
    }

    if (!file_exists(CUSTOMERS_FILE)) {
        writeJson(
            CUSTOMERS_FILE,
            createInitialCustomers()
        );
    }

    if (!file_exists(RESPONSES_FILE)) {
        writeJson(
            RESPONSES_FILE,
            []
        );
    }

    if (!file_exists(HISTORY_FILE)) {
        writeJson(
            HISTORY_FILE,
            []
        );
    }

    if (!file_exists(KINTONE_FILE)) {
        writeJson(
            KINTONE_FILE,
            defaultKintoneSettings()
        );
    }

    if (!file_exists(MAIL_FILE)) {
        writeJson(
            MAIL_FILE,
            defaultMailSettings()
        );
    }
}

function readJson(
    string $file,
    mixed $default
): mixed {
    if (!file_exists($file)) {
        return $default;
    }

    $content = file_get_contents($file);

    if ($content === false ||
        trim($content) === '') {
        return $default;
    }

    $data =
        json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

    return $data;
}

function writeJson(
    string $file,
    mixed $data
): void {
    $json =
        json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT |
            JSON_THROW_ON_ERROR
        );

    $directory =
        dirname($file);

    $tmp =
        tempnam(
            $directory,
            'json_'
        );

    if ($tmp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    $fp =
        fopen($tmp, 'wb');

    if ($fp === false) {
        @unlink($tmp);

        throw new RuntimeException(
            '一時ファイルを開けません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                'JSONファイルのロックに失敗しました。'
            );
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException(
                'JSON書き込みに失敗しました。'
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);

        throw new RuntimeException(
            'JSONファイル更新に失敗しました。'
        );
    }
}

/* =========================================================
 * 初期データ
 * ======================================================= */

function createInitialSurveys(): array
{
    $now =
        new DateTimeImmutable('now');

    $past =
        $now
            ->modify('-2 days')
            ->format(DateTimeInterface::ATOM);

    $future =
        $now
            ->modify('+30 days')
            ->format(DateTimeInterface::ATOM);

    $future2 =
        $now
            ->modify('+60 days')
            ->format(DateTimeInterface::ATOM);

    return [
        makeSampleSurvey(
            'survey001',
            '顧客満足度アンケート',
            'published',
            $past,
            $future
        ),

        makeSampleSurvey(
            'survey002',
            '新サービス利用意向調査',
            'draft',
            $past,
            $future2
        ),

        makeSampleSurvey(
            'survey003',
            'イベント参加者アンケート',
            'stopped',
            $past,
            $future2
        ),

        makeSampleSurvey(
            'survey004',
            '過去終了アンケート',
            'finished',
            $past,
            $past
        ),

        makeSampleSurvey(
            'survey005',
            '下書き・過去日時確認用',
            'draft',
            $past,
            $past
        ),

        makeSampleSurvey(
            'survey006',
            '公開中・過去日時確認用',
            'published',
            $past,
            $past
        ),

        makeSampleSurvey(
            'survey007',
            '停止・過去日時確認用',
            'stopped',
            $past,
            $past
        )
    ];
}

function makeSampleSurvey(
    string $id,
    string $title,
    string $status,
    string $start,
    string $end
): array {
    $groupId =
        $id . '_group001';

    $q1 =
        $id . '_question001';

    $q2 =
        $id . '_question002';

    $c1 =
        $id . '_choice001';

    $c2 =
        $id . '_choice002';

    $now =
        date(DateTimeInterface::ATOM);

    return [
        'surveyId' => $id,
        'title' => $title,
        'description' =>
            'アンケートへのご協力をお願いいたします。',
        'startDate' => $start,
        'endDate' => $end,
        'status' => $status,
        'numberingMode' => 'all',
        'allowResubmit' => false,

        'groups' => [
            [
                'groupId' => $groupId,
                'title' => '基本アンケート',
                'sortOrder' => 1,

                'questions' => [
                    [
                        'questionId' => $q1,
                        'groupId' => $groupId,
                        'sortOrder' => 1,
                        'questionNumber' => 'Q1',
                        'questionText' =>
                            'サービスにどの程度満足していますか？',
                        'type' => 'single',
                        'required' => true,

                        'choices' => [
                            [
                                'choiceId' => $c1,
                                'label' => '満足',
                                'sortOrder' => 1
                            ],
                            [
                                'choiceId' => $c2,
                                'label' => '不満',
                                'sortOrder' => 2
                            ]
                        ],

                        'branchRules' => []
                    ],

                    [
                        'questionId' => $q2,
                        'groupId' => $groupId,
                        'sortOrder' => 2,
                        'questionNumber' => 'Q2',
                        'questionText' =>
                            'ご意見・ご要望があれば入力してください。',
                        'type' => 'text',
                        'required' => false,
                        'choices' => [],
                        'branchRules' => []
                    ]
                ]
            ]
        ],

        'createdAt' => $now,
        'updatedAt' => $now
    ];
}

function createInitialCustomers(): array
{
    return [
        [
            'customerId' => 'customer001',
            'organizationName' => 'サンプル株式会社',
            'name' => '山田 太郎',
            'email' => 'sample1@example.com',
            'department' => '営業部',
            'phone' => '03-0000-0001',
            'address' => [
                'postalCode' => '100-0001',
                'prefecture' => '東京都',
                'city' => '千代田区',
                'street' => '千代田1-1',
                'building' => ''
            ],
            'lastSentAt' => '',
            'sendCount' => 0,
            'answerStatus' => '未送信',
            'kintoneStatus' => '未同期'
        ],

        [
            'customerId' => 'customer002',
            'organizationName' => 'サンプル商事株式会社',
            'name' => '佐藤 花子',
            'email' => 'sample2@example.com',
            'department' => '企画部',
            'phone' => '03-0000-0002',
            'address' => [
                'postalCode' => '150-0001',
                'prefecture' => '東京都',
                'city' => '渋谷区',
                'street' => '神宮前1-1',
                'building' => ''
            ],
            'lastSentAt' => '',
            'sendCount' => 0,
            'answerStatus' => '未送信',
            'kintoneStatus' => '未同期'
        ],

        [
            'customerId' => 'customer003',
            'organizationName' => 'テスト合同会社',
            'name' => '鈴木 一郎',
            'email' => 'sample3@example.com',
            'department' => '管理部',
            'phone' => '03-0000-0003',
            'address' => [
                'postalCode' => '160-0001',
                'prefecture' => '東京都',
                'city' => '新宿区',
                'street' => '西新宿1-1',
                'building' => ''
            ],
            'lastSentAt' => '',
            'sendCount' => 0,
            'answerStatus' => '未送信',
            'kintoneStatus' => '未同期'
        ]
    ];
}

/* =========================================================
 * 状態
 * ======================================================= */

function applyFinishedStates(array $surveys): array
{
    $changed = false;

    $now =
        new DateTimeImmutable('now');

    foreach ($surveys as &$survey) {
        if (
            ($survey['status'] ?? '') === 'published' &&
            !empty($survey['endDate'])
        ) {
            try {
                $end =
                    new DateTimeImmutable(
                        $survey['endDate']
                    );

                if ($now > $end) {
                    $survey['status'] =
                        'finished';

                    $survey['updatedAt'] =
                        $now->format(
                            DateTimeInterface::ATOM
                        );

                    $changed = true;
                }
            } catch (Throwable) {
                /* 不正日時は変更しない */
            }
        }
    }

    unset($survey);

    if ($changed) {
        writeJson(
            SURVEYS_FILE,
            $surveys
        );
    }

    return $surveys;
}

function validStatusTransition(
    string $from,
    string $to
): bool {
    return
        ($from === 'draft' &&
            $to === 'published') ||

        ($from === 'published' &&
            $to === 'stopped') ||

        ($from === 'stopped' &&
            $to === 'published');
}

/* =========================================================
 * Survey API
 * ======================================================= */

function apiSaveSurvey(): never
{
    $raw =
        $_POST['survey'] ?? '';

    $survey =
        json_decode(
            (string)$raw,
            true
        );

    if (!is_array($survey)) {
        apiError(
            'INVALID_SURVEY',
            'アンケートデータが不正です。',
            400
        );
    }

    validateSurvey($survey);

    $surveys =
        readJson(
            SURVEYS_FILE,
            []
        );

    $surveyId =
        trim((string)($survey['surveyId'] ?? ''));

    if ($surveyId === '') {
        $surveyId =
            generateId('survey');
    }

    $now =
        date(DateTimeInterface::ATOM);

    $existingIndex = -1;

    foreach ($surveys as $index => $item) {
        if (($item['surveyId'] ?? '') === $surveyId) {
            $existingIndex = $index;
            break;
        }
    }

    if ($existingIndex < 0) {
        $survey['surveyId'] = $surveyId;
        $survey['status'] = 'draft';
        $survey['createdAt'] = $now;
    } else {
        $existing =
            $surveys[$existingIndex];

        $survey['surveyId'] =
            $existing['surveyId'];

        $survey['status'] =
            $existing['status'];

        $survey['createdAt'] =
            $existing['createdAt'] ?? $now;
    }

    $survey['updatedAt'] = $now;

    normalizeSurveyStructure($survey);

    if ($existingIndex < 0) {
        $surveys[] = $survey;
    } else {
        $surveys[$existingIndex] =
            $survey;
    }

    writeJson(
        SURVEYS_FILE,
        $surveys
    );

    apiSuccess([
        'surveys' => applyFinishedStates($surveys)
    ]);
}

function apiChangeStatus(): never
{
    $surveyId =
        validateId(
            $_POST['surveyId'] ?? '',
            'surveyId'
        );

    $newStatus =
        trim((string)(
            $_POST['status'] ?? ''
        ));

    $surveys =
        readJson(
            SURVEYS_FILE,
            []
        );

    $index =
        findSurveyIndex(
            $surveys,
            $surveyId
        );

    if ($index < 0) {
        apiError(
            'SURVEY_NOT_FOUND',
            '対象アンケートが存在しません。',
            404
        );
    }

    $surveys =
        applyFinishedStates($surveys);

    $current =
        $surveys[$index]['status'];

    if (!validStatusTransition(
        $current,
        $newStatus
    )) {
        apiError(
            'INVALID_STATUS_TRANSITION',
            '許可されていない状態遷移です。',
            400
        );
    }

    $surveys[$index]['status'] =
        $newStatus;

    $surveys[$index]['updatedAt'] =
        date(DateTimeInterface::ATOM);

    writeJson(
        SURVEYS_FILE,
        $surveys
    );

    apiSuccess([
        'surveys' =>
            applyFinishedStates($surveys)
    ]);
}

function apiDeleteSurvey(): never
{
    $surveyId =
        validateId(
            $_POST['surveyId'] ?? '',
            'surveyId'
        );

    $surveys =
        readJson(
            SURVEYS_FILE,
            []
        );

    $responses =
        readJson(
            RESPONSES_FILE,
            []
        );

    $before =
        count($surveys);

    $surveys =
        array_values(
            array_filter(
                $surveys,
                fn($s) =>
                    ($s['surveyId'] ?? '') !==
                    $surveyId
            )
        );

    if ($before === count($surveys)) {
        apiError(
            'SURVEY_NOT_FOUND',
            '対象アンケートが存在しません。',
            404
        );
    }

    $responses =
        array_values(
            array_filter(
                $responses,
                fn($r) =>
                    ($r['surveyId'] ?? '') !==
                    $surveyId
            )
        );

    writeJson(
        SURVEYS_FILE,
        $surveys
    );

    writeJson(
        RESPONSES_FILE,
        $responses
    );

    apiSuccess([
        'surveys' => $surveys,
        'responses' => $responses
    ]);
}

function apiDuplicateSurvey(): never
{
    $surveyId =
        validateId(
            $_POST['surveyId'] ?? '',
            'surveyId'
        );

    $surveys =
        readJson(
            SURVEYS_FILE,
            []
        );

    $survey =
        findSurvey(
            $surveys,
            $surveyId
        );

    if (!$survey) {
        apiError(
            'SURVEY_NOT_FOUND',
            '対象アンケートが存在しません。',
            404
        );
    }

    $copy =
        $survey;

    $newSurveyId =
        generateId('survey');

    $copy['surveyId'] =
        $newSurveyId;

    $copy['title'] =
        ($copy['title'] ?? '') .
        '（複製）';

    $copy['status'] =
        'draft';

    $copy['createdAt'] =
        date(DateTimeInterface::ATOM);

    $copy['updatedAt'] =
        date(DateTimeInterface::ATOM);

    foreach ($copy['groups'] as &$group) {
        $group['groupId'] =
            generateId('group');

        foreach ($group['questions'] as &$question) {
            $question['questionId'] =
                generateId('question');

            $question['groupId'] =
                $group['groupId'];

            foreach ($question['choices'] ?? [] as &$choice) {
                $choice['choiceId'] =
                    generateId('choice');
            }
            unset($choice);

            /*
             * 旧IDから新IDへの変換
             */
        }
        unset($question);
    }
    unset($group);

    /*
     * 条件分岐IDを再構成する。
     */
    $oldToNew = [];

    foreach ($survey['groups'] as $gi => $oldGroup) {
        $newGroup =
            $copy['groups'][$gi];

        foreach ($oldGroup['questions'] as $qi => $oldQuestion) {
            $oldToNew[
                $oldQuestion['questionId']
            ] =
                $newGroup['questions'][$qi]['questionId'];
        }
    }

    foreach ($copy['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            foreach ($question['branchRules'] ?? [] as &$rule) {
                $rule['questionId'] =
                    $question['questionId'];

                if (
                    isset($oldToNew[
                        $rule['nextQuestionId']
                    ])
                ) {
                    $rule['nextQuestionId'] =
                        $oldToNew[
                            $rule['nextQuestionId']
                        ];
                }
            }
            unset($rule);
        }
        unset($question);
    }
    unset($group);

    normalizeSurveyStructure($copy);

    $surveys[] =
        $copy;

    writeJson(
        SURVEYS_FILE,
        $surveys
    );

    apiSuccess([
        'surveys' =>
            applyFinishedStates($surveys)
    ]);
}

/* =========================================================
 * 回答 API
 * ======================================================= */

function apiSaveResponse(): never
{
    $surveyId =
        validateId(
            $_POST['surveyId'] ?? '',
            'surveyId'
        );

    $token =
        trim((string)(
            $_POST['token'] ?? ''
        ));

    $answers =
        json_decode(
            (string)(
                $_POST['answers'] ?? '{}'
            ),
            true
        );

    if (!is_array($answers)) {
        apiError(
            'INVALID_ANSWERS',
            '回答データが不正です。',
            400
        );
    }

    $surveys =
        applyFinishedStates(
            readJson(
                SURVEYS_FILE,
                []
            )
        );

    $survey =
        findSurvey(
            $surveys,
            $surveyId
        );

    if (!$survey) {
        apiError(
            'SURVEY_NOT_FOUND',
            '対象アンケートが存在しません。',
            404
        );
    }

    if (($survey['status'] ?? '') !== 'published') {
        apiError(
            'SURVEY_NOT_AVAILABLE',
            'このアンケートは現在回答できません。',
            400
        );
    }

    $visibleQuestions =
        getVisibleQuestionsServer(
            $survey,
            $answers
        );

    foreach ($visibleQuestions as $question) {
        if (!empty($question['required'])) {
            $value =
                $answers[
                    $question['questionId']
                ] ?? null;

            if (is_array($value)) {
                $valid =
                    count($value) > 0;
            } else {
                $valid =
                    trim((string)$value) !== '';
            }

            if (!$valid) {
                apiError(
                    'REQUIRED',
                    $question['questionNumber'] .
                    ' は必須項目です。',
                    400
                );
            }
        }
    }

    $responses =
        readJson(
            RESPONSES_FILE,
            []
        );

    $customerId =
        null;

    if ($token !== '') {
        $customers =
            readJson(
                CUSTOMERS_FILE,
                []
            );

        foreach ($customers as $customer) {
            if (
                ($customer['customerId'] ?? '') ===
                $token
            ) {
                $customerId =
                    $customer['customerId'];

                break;
            }
        }
    }

    if (!$survey['allowResubmit'] &&
        $token !== '') {
        foreach ($responses as $response) {
            if (
                ($response['surveyId'] ?? '') ===
                    $surveyId &&
                ($response['token'] ?? '') ===
                    $token &&
                !empty($response['completedAt'])
            ) {
                apiError(
                    'ALREADY_ANSWERED',
                    'このアンケートは回答済みです。',
                    409
                );
            }
        }
    }

    $response = [
        'responseId' =>
            generateId('response'),

        'surveyId' =>
            $surveyId,

        'token' =>
            $token !== ''
                ? $token
                : null,

        'customerId' =>
            $customerId,

        'respondentName' =>
            '',

        'respondentEmail' =>
            '',

        'answers' =>
            $answers,

        'createdAt' =>
            date(DateTimeInterface::ATOM),

        'completedAt' =>
            date(DateTimeInterface::ATOM)
    ];

    $responses[] =
        $response;

    writeJson(
        RESPONSES_FILE,
        $responses
    );

    if ($customerId !== null) {
        $customers =
            readJson(
                CUSTOMERS_FILE,
                []
            );

        foreach ($customers as &$customer) {
            if (
                ($customer['customerId'] ?? '') ===
                $customerId
            ) {
                $customer['answerStatus'] =
                    '回答済み';

                break;
            }
        }

        unset($customer);

        writeJson(
            CUSTOMERS_FILE,
            $customers
        );
    }

    apiSuccess([
        'response' => $response
    ]);
}

/* =========================================================
 * SMTP
 * ======================================================= */

function apiSendMail(): never
{
    $surveyId =
        validateId(
            $_POST['surveyId'] ?? '',
            'surveyId'
        );

    $customerIds =
        decodeArrayPost(
            $_POST['customerIds'] ?? '[]'
        );

    $subject =
        trim((string)(
            $_POST['subject'] ?? ''
        ));

    $body =
        (string)(
            $_POST['body'] ?? ''
        );

    $sendType =
        trim((string)(
            $_POST['sendType'] ?? 'bulk'
        ));

    if (!$customerIds) {
        apiError(
            'NO_CUSTOMERS',
            '送信対象顧客が選択されていません。',
            400
        );
    }

    if ($subject === '') {
        apiError(
            'INVALID_SUBJECT',
            'メール件名を入力してください。',
            400
        );
    }

    if ($body === '') {
        apiError(
            'INVALID_BODY',
            'メール本文を入力してください。',
            400
        );
    }

    $surveys =
        applyFinishedStates(
            readJson(
                SURVEYS_FILE,
                []
            )
        );

    $survey =
        findSurvey(
            $surveys,
            $surveyId
        );

    if (!$survey) {
        apiError(
            'SURVEY_NOT_FOUND',
            '対象アンケートが存在しません。',
            404
        );
    }

    $customers =
        readJson(
            CUSTOMERS_FILE,
            []
        );

    $mail =
        readJson(
            MAIL_FILE,
            defaultMailSettings()
        );

    $history =
        readJson(
            HISTORY_FILE,
            []
        );

    $details = [];

    $successCount = 0;
    $failureCount = 0;

    foreach ($customerIds as $customerId) {
        $customer = null;

        foreach ($customers as $c) {
            if (
                ($c['customerId'] ?? '') ===
                (string)$customerId
            ) {
                $customer = $c;
                break;
            }
        }

        if (!$customer) {
            $details[] = [
                'customerId' =>
                    (string)$customerId,
                'customerName' => '不明',
                'email' => '',
                'success' => false,
                'error' =>
                    '顧客が存在しません。',
                'surveyUrl' => '',
                'expandedBody' => ''
            ];

            $failureCount++;

            continue;
        }

        $surveyUrl =
            buildServerAnswerUrl(
                $surveyId,
                $customer['customerId']
            );

        $expandedSubject =
            expandMailTemplate(
                $subject,
                $customer,
                $surveyUrl
            );

        $expandedBody =
            expandMailTemplate(
                $body,
                $customer,
                $surveyUrl
            );

        if (empty($customer['email'])) {
            $success = false;
            $error =
                'メールアドレスが設定されていません。';
        } else {
            try {
                smtpSend(
                    $mail,
                    $customer['email'],
                    $expandedSubject,
                    $expandedBody
                );

                $success = true;
                $error = '';

                $successCount++;
            } catch (Throwable $e) {
                $success = false;
                $error =
                    isDebugMode()
                        ? $e->getMessage()
                        : 'SMTP送信に失敗しました。';

                $failureCount++;
            }
        }

        if ($success) {
            foreach ($customers as &$c) {
                if (
                    ($c['customerId'] ?? '') ===
                    $customer['customerId']
                ) {
                    $c['lastSentAt'] =
                        date(DateTimeInterface::ATOM);

                    $c['sendCount'] =
                        (int)($c['sendCount'] ?? 0) + 1;

                    if (
                        $c['answerStatus'] !==
                        '回答済み'
                    ) {
                        $c['answerStatus'] =
                            '送信済み / 未回答';
                    }

                    break;
                }
            }

            unset($c);
        }

        $details[] = [
            'customerId' =>
                $customer['customerId'],

            'customerName' =>
                $customer['name'] ?? '',

            'email' =>
                $customer['email'] ?? '',

            'success' =>
                $success,

            'error' =>
                $error,

            'surveyUrl' =>
                $surveyUrl,

            'expandedBody' =>
                $expandedBody
        ];
    }

    writeJson(
        CUSTOMERS_FILE,
        $customers
    );

    $history[] = [
        'historyId' =>
            generateId('history'),

        'surveyId' =>
            $surveyId,

        'sentAt' =>
            date(DateTimeInterface::ATOM),

        'sendType' =>
            $sendType,

        'customerIds' =>
            array_values($customerIds),

        'subject' =>
            $subject,

        'body' =>
            $body,

        'executedBy' =>
            '管理画面',

        'details' =>
            $details
    ];

    writeJson(
        HISTORY_FILE,
        $history
    );

    apiSuccess([
        'result' => [
            'totalCount' =>
                count($customerIds),

            'successCount' =>
                $successCount,

            'failureCount' =>
                $failureCount,

            'sentAt' =>
                date(DateTimeInterface::ATOM),

            'details' =>
                $details
        ],

        'customers' =>
            $customers,

        'history' =>
            $history
    ]);
}

function smtpSend(
    array $settings,
    string $to,
    string $subject,
    string $body
): void {
    $server =
        trim((string)(
            $settings['server'] ?? ''
        ));

    $port =
        (int)(
            $settings['port'] ?? 587
        );

    $encryption =
        $settings['encryption'] ?? 'none';

    $auth =
        !empty($settings['auth']);

    $username =
        (string)(
            $settings['username'] ?? ''
        );

    $password =
        (string)(
            $settings['password'] ?? ''
        );

    $from =
        trim((string)(
            $settings['from'] ?? ''
        ));

    $fromName =
        (string)(
            $settings['fromName'] ?? ''
        );

    $replyTo =
        trim((string)(
            $settings['replyTo'] ?? ''
        ));

    if ($server === '' ||
        $port <= 0 ||
        $from === '') {
        throw new RuntimeException(
            'SMTP設定が未設定です。'
        );
    }

    if (!filter_var(
        $from,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    $host =
        $server;

    $prefix = '';

    if ($encryption === 'ssl') {
        $prefix = 'ssl://';
    }

    $errno = 0;
    $errstr = '';

    $socket =
        @stream_socket_client(
            $prefix .
            $host .
            ':' .
            $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );

    if (!$socket) {
        throw new RuntimeException(
            'SMTP接続失敗: ' .
            $errstr .
            ' (' .
            $errno .
            ')'
        );
    }

    stream_set_timeout(
        $socket,
        20
    );

    smtpExpect(
        $socket,
        220
    );

    smtpCommand(
        $socket,
        'EHLO localhost',
        250
    );

    if ($encryption === 'tls') {
        smtpCommand(
            $socket,
            'STARTTLS',
            220
        );

        $crypto =
            stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'STARTTLSを開始できません。'
            );
        }

        smtpCommand(
            $socket,
            'EHLO localhost',
            250
        );
    }

    if ($auth) {
        if ($username === '' ||
            $password === '') {
            fclose($socket);

            throw new RuntimeException(
                'SMTP認証情報が未設定です。'
            );
        }

        smtpCommand(
            $socket,
            'AUTH LOGIN',
            334
        );

        smtpCommand(
            $socket,
            base64_encode($username),
            334
        );

        smtpCommand(
            $socket,
            base64_encode($password),
            235
        );
    }

    smtpCommand(
        $socket,
        'MAIL FROM:<' . $from . '>',
        250
    );

    smtpCommand(
        $socket,
        'RCPT TO:<' . $to . '>',
        250
    );

    smtpCommand(
        $socket,
        'DATA',
        354
    );

    $headers = [];

    $headers[] =
        'From: ' .
        formatMailAddress(
            $from,
            $fromName
        );

    $headers[] =
        'To: <' .
        $to .
        '>';

    if ($replyTo !== '' &&
        filter_var(
            $replyTo,
            FILTER_VALIDATE_EMAIL
        )) {
        $headers[] =
            'Reply-To: <' .
            $replyTo .
            '>';
    }

    $headers[] =
        'Subject: ' .
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

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
        ) .
        "\r\n\r\n" .
        normalizeMailBody($body) .
        "\r\n.";

    fwrite(
        $socket,
        $message .
        "\r\n"
    );

    smtpExpect(
        $socket,
        250
    );

    @fwrite(
        $socket,
        "QUIT\r\n"
    );

    fclose($socket);
}

function smtpCommand(
    $socket,
    string $command,
    int $expected
): void {
    fwrite(
        $socket,
        $command .
        "\r\n"
    );

    smtpExpect(
        $socket,
        $expected
    );
}

function smtpExpect(
    $socket,
    int $expected
): string {
    $response = '';

    while (!feof($socket)) {
        $line =
            fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    $code =
        (int)substr(
            trim($response),
            0,
            3
        );

    if ($code !== $expected) {
        throw new RuntimeException(
            'SMTP応答エラー: ' .
            trim($response)
        );
    }

    return $response;
}

function formatMailAddress(
    string $email,
    string $name
): string {
    if ($name === '') {
        return '<' . $email . '>';
    }

    return '=?UTF-8?B?' .
        base64_encode($name) .
        '?= <' .
        $email .
        '>';
}

function normalizeMailBody(
    string $body
): string {
    $body =
        str_replace(
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

/* =========================================================
 * CSV
 * ======================================================= */

function apiExportCsv(): never
{
    $surveyId =
        validateId(
            $_POST['surveyId'] ?? '',
            'surveyId'
        );

    $surveys =
        readJson(
            SURVEYS_FILE,
            []
        );

    $survey =
        findSurvey(
            $surveys,
            $surveyId
        );

    if (!$survey) {
        apiError(
            'SURVEY_NOT_FOUND',
            '対象アンケートが存在しません。',
            404
        );
    }

    $customers =
        readJson(
            CUSTOMERS_FILE,
            []
        );

    $responses =
        readJson(
            RESPONSES_FILE,
            []
        );

    $questions =
        getAllQuestionsServer(
            $survey
        );

    $fp =
        fopen('php://temp', 'w+');

    if (!$fp) {
        apiError(
            'CSV_ERROR',
            'CSV生成に失敗しました。',
            500
        );
    }

    $header = [
        '回答ID',
        '回答日時',
        '回答者',
        'メールアドレス'
    ];

    foreach ($questions as $question) {
        $header[] =
            $question['questionNumber'];

        $header[] =
            $question['questionText'];
    }

    fputcsv(
        $fp,
        $header
    );

    foreach ($responses as $response) {
        if (
            ($response['surveyId'] ?? '') !==
            $surveyId
        ) {
            continue;
        }

        $customer =
            null;

        foreach ($customers as $c) {
            if (
                ($c['customerId'] ?? '') ===
                ($response['customerId'] ?? '')
            ) {
                $customer = $c;
                break;
            }
        }

        $row = [
            $response['responseId'] ?? '',
            $response['completedAt'] ?? '',
            $customer['name'] ??
                $response['respondentName'] ??
                '',
            $customer['email'] ??
                $response['respondentEmail'] ??
                ''
        ];

        foreach ($questions as $question) {
            $value =
                $response['answers'][
                    $question['questionId']
                ] ?? '';

            if (is_array($value)) {
                $labels = [];

                foreach ($value as $choiceId) {
                    foreach (
                        $question['choices'] ?? []
                        as $choice
                    ) {
                        if (
                            ($choice['choiceId'] ?? '') ===
                            $choiceId
                        ) {
                            $labels[] =
                                $choice['label'];
                        }
                    }
                }

                $value =
                    implode(
                        '、',
                        $labels
                    );
            } else {
                foreach (
                    $question['choices'] ?? []
                    as $choice
                ) {
                    if (
                        ($choice['choiceId'] ?? '') ===
                        $value
                    ) {
                        $value =
                            $choice['label'];

                        break;
                    }
                }
            }

            $row[] =
                (string)$value;

            $row[] =
                $question['questionText'];
        }

        fputcsv(
            $fp,
            $row
        );
    }

    rewind($fp);

    $csv =
        stream_get_contents($fp);

    fclose($fp);

    apiSuccess([
        'csv' =>
            $csv ?: ''
    ]);
}

/* =========================================================
 * kintone
 * ======================================================= */

function defaultKintoneSettings(): array
{
    return [
        'subdomain' => '',
        'appId' => '',
        'loginName' => '',
        'password' => '',
        'sslVerify' => false,
        'proxy' => '',
        'fields' => [],
        'mapping' => [
            'organizationName' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => []
        ]
    ];
}

function apiSaveKintone(): never
{
    $settings =
        json_decode(
            (string)(
                $_POST['settings'] ?? '{}'
            ),
            true
        );

    if (!is_array($settings)) {
        apiError(
            'INVALID_KINTONE',
            'kintone設定が不正です。',
            400
        );
    }

    $current =
        readJson(
            KINTONE_FILE,
            defaultKintoneSettings()
        );

    $settings =
        normalizeKintoneSettings(
            $settings,
            $current
        );

    writeJson(
        KINTONE_FILE,
        $settings
    );

    apiSuccess([
        'kintone' =>
            sanitizeKintoneForClient(
                $settings
            )
    ]);
}

function apiSaveKintoneMapping(): never
{
    $mapping =
        json_decode(
            (string)(
                $_POST['mapping'] ?? '{}'
            ),
            true
        );

    if (!is_array($mapping)) {
        apiError(
            'INVALID_MAPPING',
            'マッピングデータが不正です。',
            400
        );
    }

    $settings =
        readJson(
            KINTONE_FILE,
            defaultKintoneSettings()
        );

    $settings['mapping'] =
        [
            'organizationName' =>
                trim((string)(
                    $mapping['organizationName'] ?? ''
                )),

            'name' =>
                trim((string)(
                    $mapping['name'] ?? ''
                )),

            'email' =>
                trim((string)(
                    $mapping['email'] ?? ''
                )),

            'department' =>
                trim((string)(
                    $mapping['department'] ?? ''
                )),

            'phone' =>
                trim((string)(
                    $mapping['phone'] ?? ''
                )),

            'address' =>
                array_values(
                    array_map(
                        'strval',
                        is_array(
                            $mapping['address'] ?? null
                        )
                            ? $mapping['address']
                            : []
                    )
                )
        ];

    writeJson(
        KINTONE_FILE,
        $settings
    );

    apiSuccess([
        'kintone' =>
            sanitizeKintoneForClient(
                $settings
            )
    ]);
}

function apiKintoneTest(): never
{
    $settings =
        getKintoneRequestSettings();

    kintoneRequest(
        $settings,
        'GET',
        '/k/v1/app.json',
        [
            'id' =>
                (int)$settings['appId']
        ]
    );

    apiSuccess([
        'message' =>
            'kintone接続成功'
    ]);
}

function apiKintoneFields(): never
{
    $settings =
        getKintoneRequestSettings();

    $result =
        kintoneRequest(
            $settings,
            'GET',
            '/k/v1/app/form/fields.json',
            [
                'app' =>
                    (int)$settings['appId']
            ]
        );

    $fields = [];

    foreach (
        ($result['properties'] ?? [])
        as $code => $field
    ) {
        $fields[] = [
            'code' => $code,
            'label' =>
                $field['label'] ?? $code,
            'type' =>
                $field['type'] ?? ''
        ];
    }

    $stored =
        readJson(
            KINTONE_FILE,
            defaultKintoneSettings()
        );

    $stored['fields'] =
        $fields;

    writeJson(
        KINTONE_FILE,
        $stored
    );

    apiSuccess([
        'fields' =>
            $fields
    ]);
}

function apiKintoneSync(): never
{
    $settings =
        readJson(
            KINTONE_FILE,
            defaultKintoneSettings()
        );

    $settings =
        normalizeKintoneSettings(
            $settings,
            $settings
        );

    if (
        trim((string)$settings['appId']) === ''
    ) {
        apiError(
            'KINTONE_CONFIG',
            'kintoneアプリIDが設定されていません。',
            400
        );
    }

    $mapping =
        $settings['mapping'] ?? [];

    $fields =
        kintoneRequest(
            $settings,
            'GET',
            '/k/v1/records.json',
            [
                'app' =>
                    (int)$settings['appId'],

                'query' =>
                    'limit 500'
            ]
        );

    $records =
        $fields['records'] ?? [];

    $customers = [];

    foreach ($records as $record) {
        $customers[] =
            mapKintoneRecordToCustomer(
                $record,
                $mapping
            );
    }

    writeJson(
        CUSTOMERS_FILE,
        $customers
    );

    apiSuccess([
        'message' =>
            '顧客同期完了: ' .
            count($customers) .
            '件',

        'customers' =>
            $customers
    ]);
}

function getKintoneRequestSettings(): array
{
    $raw =
        $_POST['settings'] ?? '';

    if ($raw !== '') {
        $settings =
            json_decode(
                (string)$raw,
                true
            );
    } else {
        $settings =
            readJson(
                KINTONE_FILE,
                defaultKintoneSettings()
            );
    }

    if (!is_array($settings)) {
        apiError(
            'KINTONE_CONFIG',
            'kintone設定が不正です。',
            400
        );
    }

    $current =
        readJson(
            KINTONE_FILE,
            defaultKintoneSettings()
        );

    return normalizeKintoneSettings(
        $settings,
        $current
    );
}

function normalizeKintoneSettings(
    array $settings,
    array $current
): array {
    $password =
        trim((string)(
            $settings['password'] ?? ''
        ));

    if ($password === '') {
        $password =
            (string)(
                $current['password'] ?? ''
            );
    }

    return [
        'subdomain' =>
            normalizeKintoneSubdomain(
                (string)(
                    $settings['subdomain'] ?? ''
                )
            ),

        'appId' =>
            preg_replace(
                '/[^0-9]/',
                '',
                (string)(
                    $settings['appId'] ?? ''
                )
            ),

        'loginName' =>
            trim((string)(
                $settings['loginName'] ?? ''
            )),

        'password' =>
            $password,

        'sslVerify' =>
            !empty($settings['sslVerify']),

        'proxy' =>
            normalizeProxy(
                (string)(
                    $settings['proxy'] ?? ''
                )
            ),

        'fields' =>
            $current['fields'] ?? [],

        'mapping' =>
            $current['mapping'] ??
            defaultKintoneSettings()['mapping']
    ];
}

function normalizeKintoneSubdomain(
    string $value
): string {
    $value =
        trim($value);

    if ($value === '') {
        return '';
    }

    $value =
        preg_replace(
            '#^https?://#i',
            '',
            $value
        );

    $value =
        trim(
            $value,
            "/ \t\n\r\0\x0B"
        );

    if (
        !str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {
        $value .= '.cybozu.com';
    }

    return
        'https://' .
        $value;
}

function normalizeProxy(
    string $value
): string {
    $value =
        trim($value);

    if ($value === '') {
        return '';
    }

    if (!preg_match(
        '/^[^:\s]+:\d+$/',
        $value
    )) {
        throw new InvalidArgumentException(
            'プロキシはhost:port形式で入力してください。'
        );
    }

    return $value;
}

function kintoneRequest(
    array $settings,
    string $method,
    string $path,
    array $query = [],
    ?array $body = null
): array {
    $base =
        normalizeKintoneSubdomain(
            (string)$settings['subdomain']
        );

    if ($base === '') {
        throw new RuntimeException(
            'kintoneサブドメインが未設定です。'
        );
    }

    if ($settings['appId'] === '') {
        throw new RuntimeException(
            'kintoneアプリIDが未設定です。'
        );
    }

    $url =
        $base .
        $path;

    if ($query) {
        $url .=
            '?' .
            http_build_query(
                $query
            );
    }

    $ch =
        curl_init();

    if ($ch === false) {
        throw new RuntimeException(
            'cURLを初期化できません。'
        );
    }

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode(
                $settings['loginName'] .
                ':' .
                $settings['password']
            )
    ];

    curl_setopt_array(
        $ch,
        [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER =>
                (bool)$settings['sslVerify'],
            CURLOPT_SSL_VERIFYHOST =>
                $settings['sslVerify']
                    ? 2
                    : 0
        ]
    );

    if ($settings['proxy'] !== '') {
        curl_setopt(
            $ch,
            CURLOPT_PROXY,
            $settings['proxy']
        );
    }

    if ($body !== null) {
        $json =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            $json
        );

        $headers[] =
            'Content-Type: application/json';

        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            $headers
        );
    }

    $raw =
        curl_exec($ch);

    if ($raw === false) {
        $error =
            curl_error($ch);

        curl_close($ch);

        throw new RuntimeException(
            'kintone API通信失敗: ' .
            $error
        );
    }

    $status =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    $result =
        json_decode(
            $raw,
            true
        );

    if (!is_array($result)) {
        throw new RuntimeException(
            'kintone APIレスポンスを解析できません。'
        );
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(
            'kintone APIエラー HTTP ' .
            $status .
            ': ' .
            ($result['message'] ?? $raw)
        );
    }

    return $result;
}

function mapKintoneRecordToCustomer(
    array $record,
    array $mapping
): array {
    $get = function(string $code) use ($record): string {
        if ($code === '') {
            return '';
        }

        $value =
            $record[$code]['value'] ??
            '';

        if (is_array($value)) {
            return implode(
                ', ',
                array_map(
                    'strval',
                    $value
                )
            );
        }

        return (string)$value;
    };

    $addressParts = [];

    foreach (
        ($mapping['address'] ?? [])
        as $code
    ) {
        $value =
            $get((string)$code);

        if ($value !== '') {
            $addressParts[] =
                $value;
        }
    }

    $email =
        $get(
            (string)(
                $mapping['email'] ?? ''
            )
        );

    $customerId =
        $email !== ''
            ? 'customer_' .
                substr(
                    sha1($email),
                    0,
                    16
                )
            : generateId('customer');

    return [
        'customerId' =>
            $customerId,

        'organizationName' =>
            $get(
                (string)(
                    $mapping['organizationName'] ?? ''
                )
            ),

        'name' =>
            $get(
                (string)(
                    $mapping['name'] ?? ''
                )
            ),

        'email' =>
            $email,

        'department' =>
            $get(
                (string)(
                    $mapping['department'] ?? ''
                )
            ),

        'phone' =>
            $get(
                (string)(
                    $mapping['phone'] ?? ''
                )
            ),

        'address' => [
            'raw' =>
                implode(
                    ' ',
                    $addressParts
                )
        ],

        'lastSentAt' => '',
        'sendCount' => 0,
        'answerStatus' => '未送信',
        'kintoneStatus' => '同期済み'
    ];
}

/* =========================================================
 * Mail API
 * ======================================================= */

function defaultMailSettings(): array
{
    return [
        'server' => '',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password' => '',
        'from' => '',
        'fromName' => '',
        'replyTo' => '',
        'status' => 'unset'
    ];
}

function apiSaveMail(): never
{
    $settings =
        json_decode(
            (string)(
                $_POST['settings'] ?? '{}'
            ),
            true
        );

    if (!is_array($settings)) {
        apiError(
            'INVALID_MAIL',
            'メール設定が不正です。',
            400
        );
    }

    $current =
        readJson(
            MAIL_FILE,
            defaultMailSettings()
        );

    $password =
        trim((string)(
            $settings['password'] ?? ''
        ));

    if ($password === '') {
        $password =
            (string)(
                $current['password'] ?? ''
            );
    }

    $mail = [
        'server' =>
            trim((string)(
                $settings['server'] ?? ''
            )),

        'port' =>
            max(
                1,
                (int)(
                    $settings['port'] ?? 587
                )
            ),

        'encryption' =>
            in_array(
                $settings['encryption'] ?? '',
                ['none','tls','ssl'],
                true
            )
                ? $settings['encryption']
                : 'tls',

        'auth' =>
            !empty($settings['auth']),

        'username' =>
            trim((string)(
                $settings['username'] ?? ''
            )),

        'password' =>
            $password,

        'from' =>
            trim((string)(
                $settings['from'] ?? ''
            )),

        'fromName' =>
            trim((string)(
                $settings['fromName'] ?? ''
            )),

        'replyTo' =>
            trim((string)(
                $settings['replyTo'] ?? ''
            )),

        'status' =>
            'unset'
    ];

    writeJson(
        MAIL_FILE,
        $mail
    );

    apiSuccess([
        'mail' =>
            sanitizeMailForClient(
                $mail
            )
    ]);
}

function apiTestMail(): never
{
    $settings =
        json_decode(
            (string)(
                $_POST['settings'] ?? '{}'
            ),
            true
        );

    if (!is_array($settings)) {
        apiError(
            'INVALID_MAIL',
            'メール設定が不正です。',
            400
        );
    }

    $current =
        readJson(
            MAIL_FILE,
            defaultMailSettings()
        );

    if (
        empty($settings['password']) &&
        !empty($current['password'])
    ) {
        $settings['password'] =
            $current['password'];
    }

    $to =
        trim((string)(
            $settings['replyTo'] ??
            $settings['from'] ??
            ''
        ));

    if (!filter_var(
        $to,
        FILTER_VALIDATE_EMAIL
    )) {
        apiError(
            'MAIL_TEST_TARGET',
            'テストメール送信先として有効なメールアドレスを設定してください。',
            400
        );
    }

    smtpSend(
        $settings,
        $to,
        'アンケート管理システム SMTPテスト',
        'SMTP接続テストメールです。'
    );

    $settings['status'] =
        'connected';

    writeJson(
        MAIL_FILE,
        $settings
    );

    apiSuccess([
        'message' =>
            'テストメール送信成功',

        'mail' =>
            sanitizeMailForClient(
                $settings
            )
    ]);
}

/* =========================================================
 * Server utilities
 * ======================================================= */

function normalizeView(mixed $value): string
{
    $value =
        is_string($value)
            ? trim($value)
            : '';

    return
        preg_match(
            '/^[a-z0-9-]{1,80}$/',
            $value
        )
            ? $value
            : '';
}

function normalizeId(mixed $value): string
{
    $value =
        is_string($value)
            ? trim($value)
            : '';

    if (
        $value === '' ||
        strlen($value) > 100 ||
        !preg_match(
            '/^[A-Za-z0-9_-]+$/',
            $value
        )
    ) {
        return '';
    }

    return $value;
}

function validateId(
    mixed $value,
    string $name
): string {
    $id =
        normalizeId($value);

    if ($id === '') {
        apiError(
            'INVALID_' .
                strtoupper($name),
            $name .
                'が不正です。',
            400
        );
    }

    return $id;
}

function normalizeToken(mixed $value): string
{
    $value =
        is_string($value)
            ? trim($value)
            : '';

    if (
        strlen($value) > 200 ||
        ($value !== '' &&
            !preg_match(
                '/^[A-Za-z0-9_.~-]+$/',
                $value
            ))
    ) {
        return '';
    }

    return $value;
}

function findSurvey(
    array $surveys,
    string $surveyId
): ?array {
    foreach ($surveys as $survey) {
        if (
            ($survey['surveyId'] ?? '') ===
            $surveyId
        ) {
            return $survey;
        }
    }

    return null;
}

function findSurveyIndex(
    array $surveys,
    string $surveyId
): int {
    foreach ($surveys as $i => $survey) {
        if (
            ($survey['surveyId'] ?? '') ===
            $surveyId
        ) {
            return $i;
        }
    }

    return -1;
}

function generateId(
    string $prefix
): string {
    return
        $prefix .
        '_' .
        date('YmdHis') .
        '_' .
        bin2hex(
            random_bytes(5)
        );
}

function createQuestionNumber(
    array $survey,
    string $questionId
): string {
    $global = 1;

    foreach ($survey['groups'] ?? [] as $gi => $group) {
        foreach (
            $group['questions'] ?? []
            as $qi => $question
        ) {
            if (
                ($question['questionId'] ?? '') ===
                $questionId
            ) {
                if (
                    ($survey['numberingMode'] ?? 'all') ===
                    'group'
                ) {
                    return
                        'Q' .
                        ($gi + 1) .
                        '-' .
                        ($qi + 1);
                }

                return 'Q' . $global;
            }

            $global++;
        }
    }

    return '';
}

function normalizeSurveyStructure(
    array &$survey
): void {
    $survey['groups'] =
        array_values(
            is_array($survey['groups'] ?? null)
                ? $survey['groups']
                : []
        );

    foreach (
        $survey['groups']
        as $gi => &$group
    ) {
        if (
            empty($group['groupId'])
        ) {
            $group['groupId'] =
                generateId('group');
        }

        $group['title'] =
            trim((string)(
                $group['title'] ?? ''
            ));

        $group['sortOrder'] =
            $gi + 1;

        $group['questions'] =
            array_values(
                is_array(
                    $group['questions'] ?? null
                )
                    ? $group['questions']
                    : []
            );

        foreach (
            $group['questions']
            as $qi => &$question
        ) {
            if (
                empty($question['questionId'])
            ) {
                $question['questionId'] =
                    generateId('question');
            }

            $question['groupId'] =
                $group['groupId'];

            $question['sortOrder'] =
                $qi + 1;

            $question['questionText'] =
                trim((string)(
                    $question['questionText'] ?? ''
                ));

            $question['type'] =
                in_array(
                    $question['type'] ?? '',
                    ['single','multiple','text'],
                    true
                )
                    ? $question['type']
                    : 'text';

            $question['required'] =
                !empty($question['required']);

            $question['choices'] =
                is_array(
                    $question['choices'] ?? null
                )
                    ? $question['choices']
                    : [];

            foreach (
                $question['choices']
                as $ci => &$choice
            ) {
                if (
                    empty($choice['choiceId'])
                ) {
                    $choice['choiceId'] =
                        generateId('choice');
                }

                $choice['label'] =
                    trim((string)(
                        $choice['label'] ?? ''
                    ));

                $choice['sortOrder'] =
                    $ci + 1;
            }

            unset($choice);

            if (
                $question['type'] === 'text'
            ) {
                $question['choices'] = [];
                $question['branchRules'] = [];
            }

            $question['branchRules'] =
                is_array(
                    $question['branchRules'] ?? null
                )
                    ? $question['branchRules']
                    : [];

            foreach (
                $question['branchRules']
                as &$rule
            ) {
                $rule['questionId'] =
                    $question['questionId'];

                $rule['choiceId'] =
                    trim((string)(
                        $rule['choiceId'] ?? ''
                    ));

                $rule['nextQuestionId'] =
                    trim((string)(
                        $rule['nextQuestionId'] ?? ''
                    ));
            }

            unset($rule);
        }

        unset($question);
    }

    unset($group);

    foreach (
        $survey['groups']
        as $gi => &$group
    ) {
        foreach (
            $group['questions']
            as $qi => &$question
        ) {
            $question['questionNumber'] =
                createQuestionNumber(
                    $survey,
                    $question['questionId']
                );
        }

        unset($question);
    }

    unset($group);
}

function validateSurvey(
    array $survey
): void {
    $title =
        trim((string)(
            $survey['title'] ?? ''
        ));

    if ($title === '') {
        apiError(
            'INVALID_TITLE',
            'アンケートタイトルを入力してください。',
            400
        );
    }

    if (
        !in_array(
            $survey['numberingMode'] ?? 'all',
            ['all','group'],
            true
        )
    ) {
        apiError(
            'INVALID_NUMBERING_MODE',
            '採番方式が不正です。',
            400
        );
    }

    foreach (
        $survey['groups'] ?? []
        as $group
    ) {
        foreach (
            $group['questions'] ?? []
            as $question
        ) {
            if (
                !in_array(
                    $question['type'] ?? '',
                    ['single','multiple','text'],
                    true
                )
            ) {
                apiError(
                    'INVALID_QUESTION_TYPE',
                    '回答形式が不正です。',
                    400
                );
            }
        }
    }
}

function getAllQuestionsServer(
    array $survey
): array {
    $questions = [];

    $groups =
        $survey['groups'] ?? [];

    usort(
        $groups,
        fn($a,$b) =>
            ($a['sortOrder'] ?? 0) <=>
            ($b['sortOrder'] ?? 0)
    );

    foreach ($groups as $group) {
        $qs =
            $group['questions'] ?? [];

        usort(
            $qs,
            fn($a,$b) =>
                ($a['sortOrder'] ?? 0) <=>
                ($b['sortOrder'] ?? 0)
        );

        foreach ($qs as $question) {
            $questions[] =
                $question;
        }
    }

    return $questions;
}

function getVisibleQuestionsServer(
    array $survey,
    array $answers
): array {
    $all =
        getAllQuestionsServer(
            $survey
        );

    $visible = [];
    $index = 0;
    $guard = 0;

    while (
        $index < count($all) &&
        $guard < count($all) * 2
    ) {
        $guard++;

        $question =
            $all[$index];

        $visible[] =
            $question;

        if (
            ($question['type'] ?? '') ===
            'single'
        ) {
            $answer =
                $answers[
                    $question['questionId']
                ] ?? '';

            foreach (
                $question['branchRules'] ?? []
                as $rule
            ) {
                if (
                    ($rule['choiceId'] ?? '') ===
                    $answer
                ) {
                    $nextIndex = -1;

                    foreach (
                        $all as $i => $candidate
                    ) {
                        if (
                            ($candidate['questionId'] ?? '') ===
                            ($rule['nextQuestionId'] ?? '')
                        ) {
                            $nextIndex = $i;
                            break;
                        }
                    }

                    if ($nextIndex >= 0) {
                        $index = $nextIndex;
                        continue 2;
                    }
                }
            }
        }

        $index++;
    }

    return $visible;
}

function expandMailTemplate(
    string $text,
    array $customer,
    string $surveyUrl
): string {
    return str_replace(
        [
            '{顧客名}',
            '{アンケートURL}'
        ],
        [
            (string)(
                $customer['name'] ?? ''
            ),
            $surveyUrl
        ],
        $text
    );
}

function buildServerAnswerUrl(
    string $surveyId,
    string $customerId
): string {
    $scheme =
        (!empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

    $host =
        $_SERVER['HTTP_HOST'] ??
        'localhost';

    $base =
        rtrim(
            dirname(
                $_SERVER['SCRIPT_NAME'] ??
                '/index.php'
            ),
            '/'
        );

    return
        $scheme .
        '://' .
        $host .
        $base .
        '/index.php?view=answer&surveyId=' .
        rawurlencode($surveyId) .
        '&token=' .
        rawurlencode($customerId);
}

function decodeArrayPost(
    mixed $value
): array {
    if (is_array($value)) {
        return array_values($value);
    }

    $data =
        json_decode(
            (string)$value,
            true
        );

    return is_array($data)
        ? array_values($data)
        : [];
}

function sanitizeMailForClient(
    array $mail
): array {
    unset($mail['password']);

    return $mail;
}

function sanitizeKintoneForClient(
    array $settings
): array {
    $copy =
        $settings;

    unset(
        $copy['password']
    );

    return $copy;
}

function isDebugMode(): bool
{
    return false;
}
?>
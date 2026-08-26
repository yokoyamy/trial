<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * Mock implementation
 *
 * Runtime:
 *   Apache 2.4
 *   PHP 8.5
 *
 * Restrictions:
 *   - No DB
 *   - No PHP cURL
 *   - No mail()
 *   - Single entry point: index.php
 *
 * Persistent mock data is maintained by localStorage.
 */

$screen = $_GET['screen'] ?? 'list';
$id     = $_GET['id'] ?? '';

$allowedScreens = [
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

if (!in_array($screen, $allowedScreens, true)) {
    $screen = 'list';
}

/*
 * The mock data is intentionally embedded in JS.
 * PHP only serves the single HTML entry point.
 */
$surveyId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

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
  --navy:#0f172a;
  --shadow:0 4px 18px rgba(15,23,42,.08);
}

*{box-sizing:border-box}

html,body{
  margin:0;
  padding:0;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
    "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
  color:var(--text);
  background:#f8fafc;
}

button,input,textarea,select{font:inherit}
button{cursor:pointer}
.hidden{display:none!important}

a{
  color:inherit;
  text-decoration:none;
}

/* =========================
   Header
========================= */

.admin-header{
  position:sticky;
  top:0;
  z-index:50;
  min-height:64px;
  background:var(--navy);
  color:#fff;
  display:flex;
  align-items:center;
  padding:0 24px;
  gap:28px;
  box-shadow:0 2px 10px rgba(0,0,0,.12);
}

.admin-logo{
  font-weight:700;
  white-space:nowrap;
  font-size:18px;
}

.admin-nav{
  display:flex;
  gap:4px;
  height:100%;
  align-items:center;
}

.admin-nav button{
  height:40px;
  padding:0 14px;
  border:0;
  border-radius:7px;
  color:#cbd5e1;
  background:transparent;
}

.admin-nav button:hover,
.admin-nav button.active{
  background:#1e293b;
  color:#fff;
}

.admin-spacer{flex:1}

/* =========================
   Layout
========================= */

.page{
  max-width:1500px;
  margin:0 auto;
  padding:28px;
}

.page-title{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  margin-bottom:24px;
}

.page-title h1{
  margin:0;
  font-size:26px;
}

.page-title p{
  margin:5px 0 0;
  color:var(--gray);
  font-size:13px;
}

.card{
  background:#fff;
  border:1px solid var(--border);
  border-radius:12px;
  box-shadow:var(--shadow);
}

.card-header{
  padding:18px 20px;
  border-bottom:1px solid var(--border);
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
}

.card-body{padding:20px}

/* =========================
   Buttons
========================= */

.btn{
  border:1px solid var(--border);
  background:#fff;
  color:var(--text);
  border-radius:7px;
  padding:9px 14px;
  min-height:40px;
}

.btn:hover{background:#f8fafc}

.btn-primary{
  background:var(--primary);
  color:#fff;
  border-color:var(--primary);
}

.btn-primary:hover{background:var(--primary-dark)}

.btn-success{
  background:var(--success);
  color:#fff;
  border-color:var(--success);
}

.btn-danger{
  background:var(--danger);
  color:#fff;
  border-color:var(--danger);
}

.btn-warning{
  background:var(--warning);
  color:#fff;
  border-color:var(--warning);
}

.btn-sm{
  min-height:32px;
  padding:6px 10px;
  font-size:13px;
}

.btn-group{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
}

/* =========================
   Form
========================= */

.form-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:18px;
}

.form-group{
  display:flex;
  flex-direction:column;
  gap:7px;
}

.form-group.full{
  grid-column:1/-1;
}

label{
  font-size:13px;
  font-weight:600;
}

input,
textarea,
select{
  width:100%;
  border:1px solid var(--border);
  border-radius:7px;
  background:#fff;
  padding:10px 12px;
  color:var(--text);
}

textarea{
  min-height:100px;
  resize:vertical;
}

input:focus,
textarea:focus,
select:focus{
  outline:3px solid rgba(37,99,235,.12);
  border-color:var(--primary);
}

/* =========================
   Table
========================= */

.table-wrap{
  width:100%;
  overflow-x:auto;
}

table{
  width:100%;
  border-collapse:collapse;
  min-width:1100px;
}

th,
td{
  border-bottom:1px solid var(--border);
  padding:13px 12px;
  text-align:left;
  vertical-align:middle;
  font-size:13px;
}

th{
  background:#f8fafc;
  font-weight:700;
  white-space:nowrap;
}

tbody tr:hover{
  background:#f8fafc;
}

/* =========================
   Status
========================= */

.status{
  display:inline-flex;
  align-items:center;
  border-radius:999px;
  padding:5px 10px;
  font-size:12px;
  font-weight:700;
  white-space:nowrap;
}

.status-draft{
  background:#e2e8f0;
  color:#475569;
}

.status-published{
  background:#dcfce7;
  color:#166534;
}

.status-stopped{
  background:#fef3c7;
  color:#92400e;
}

.status-ended{
  background:#fee2e2;
  color:#991b1b;
}

/* =========================
   Toolbar
========================= */

.toolbar{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom:16px;
}

.search-box{
  display:flex;
  gap:8px;
  flex:1;
  min-width:260px;
}

.filters{
  display:flex;
  gap:5px;
  flex-wrap:wrap;
}

.filter-btn{
  border:1px solid var(--border);
  background:#fff;
  padding:8px 12px;
  border-radius:7px;
}

.filter-btn.active{
  background:var(--primary);
  border-color:var(--primary);
  color:#fff;
}

/* =========================
   Editor
========================= */

.editor-top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:15px;
  margin-bottom:20px;
}

.editor-top-left,
.editor-top-right{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}

.editor-section{
  margin-bottom:20px;
}

.group-card{
  border:1px solid var(--border);
  border-radius:12px;
  background:#fff;
  margin-bottom:16px;
  box-shadow:var(--shadow);
}

.group-header{
  padding:14px 16px;
  background:#f8fafc;
  border-bottom:1px solid var(--border);
  display:flex;
  align-items:center;
  gap:10px;
}

.drag-handle{
  cursor:grab;
  color:#94a3b8;
  user-select:none;
}

.group-title-input{
  flex:1;
  font-weight:700;
}

.question-list{
  padding:12px;
  min-height:30px;
}

.question-card{
  border:1px solid var(--border);
  border-radius:10px;
  padding:15px;
  margin-bottom:10px;
  background:#fff;
}

.question-card:last-child{
  margin-bottom:0;
}

.question-head{
  display:flex;
  align-items:flex-start;
  gap:10px;
}

.question-number{
  color:var(--primary);
  font-weight:800;
  min-width:65px;
}

.question-text{
  flex:1;
  font-weight:600;
}

.question-actions{
  display:flex;
  gap:5px;
}

.question-body{
  margin-top:14px;
  padding-left:75px;
}

.option-list{
  display:flex;
  flex-direction:column;
  gap:7px;
  margin-top:10px;
}

.option-row{
  display:flex;
  align-items:center;
  gap:7px;
}

.option-row input[type=text]{
  flex:1;
}

.editor-footer{
  display:flex;
  justify-content:center;
  padding:5px 0 15px;
}

/* =========================
   Preview
========================= */

.preview-toolbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  margin-bottom:16px;
}

.device-preview{
  margin:0 auto;
  transition:.2s;
}

.device-preview.pc{
  max-width:1000px;
}

.device-preview.mobile{
  max-width:390px;
  border:10px solid #1e293b;
  border-radius:25px;
  overflow:hidden;
  box-shadow:0 10px 30px rgba(0,0,0,.18);
}

.preview-inner{
  background:#fff;
  padding:28px;
}

.preview-question{
  margin:0 0 24px;
}

.preview-question h3{
  margin:0 0 10px;
  font-size:16px;
}

.required{
  color:var(--danger);
  font-size:11px;
  margin-left:5px;
}

.preview-option{
  display:block;
  padding:12px;
  border:1px solid var(--border);
  border-radius:8px;
  margin-bottom:8px;
}

.preview-option:hover{
  background:#f8fafc;
}

.branch-note{
  color:#7c3aed;
  background:#f5f3ff;
  border-radius:6px;
  padding:7px 9px;
  margin-top:8px;
  font-size:12px;
}

/* =========================
   Dashboard
========================= */

.stats{
  display:grid;
  grid-template-columns:repeat(4,minmax(0,1fr));
  gap:15px;
  margin-bottom:20px;
}

.stat{
  background:#fff;
  border:1px solid var(--border);
  border-radius:12px;
  padding:20px;
  box-shadow:var(--shadow);
}

.stat-label{
  color:var(--gray);
  font-size:13px;
}

.stat-value{
  font-size:30px;
  font-weight:800;
  margin-top:5px;
}

/* =========================
   Send
========================= */

.send-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:18px;
}

.customer-list{
  max-height:460px;
  overflow:auto;
  border:1px solid var(--border);
  border-radius:8px;
}

.customer{
  display:flex;
  align-items:center;
  gap:10px;
  padding:12px;
  border-bottom:1px solid var(--border);
}

.customer:last-child{
  border-bottom:0;
}

.customer-info{
  flex:1;
}

.customer-name{
  font-weight:700;
}

.customer-email{
  color:var(--gray);
  font-size:12px;
}

.send-tabs{
  display:flex;
  gap:0;
  margin-bottom:16px;
}

.send-tab{
  border:1px solid var(--border);
  background:#fff;
  padding:9px 16px;
}

.send-tab:first-child{
  border-radius:7px 0 0 7px;
}

.send-tab:last-child{
  border-radius:0 7px 7px 0;
}

.send-tab.active{
  background:var(--primary);
  color:#fff;
  border-color:var(--primary);
}

/* =========================
   Analytics
========================= */

.bar{
  height:10px;
  border-radius:999px;
  background:#e2e8f0;
  overflow:hidden;
}

.bar > span{
  display:block;
  height:100%;
  background:var(--primary);
}

.answer-row{
  margin-bottom:18px;
}

.answer-row-head{
  display:flex;
  justify-content:space-between;
  gap:10px;
  margin-bottom:5px;
}

/* =========================
   Settings
========================= */

.settings-nav{
  display:flex;
  gap:8px;
  margin-bottom:20px;
}

.setting-section{
  max-width:900px;
}

/* =========================
   Respondent
========================= */

.respondent-page{
  min-height:100vh;
  background:#f1f5f9;
}

.respondent-header{
  background:#0f172a;
  color:#fff;
  padding:20px;
}

.respondent-container{
  max-width:760px;
  margin:0 auto;
  padding:20px;
}

.answer-card{
  background:#fff;
  border:1px solid var(--border);
  border-radius:12px;
  padding:20px;
  margin-bottom:15px;
}

.answer-card h2{
  font-size:18px;
  margin-top:0;
}

.answer-label{
  display:block;
  padding:14px;
  border:1px solid var(--border);
  border-radius:9px;
  margin:8px 0;
  cursor:pointer;
}

.answer-label:hover{
  background:#f8fafc;
}

.answer-navigation{
  display:flex;
  justify-content:space-between;
  gap:10px;
  margin-top:20px;
}

.complete{
  text-align:center;
  padding:70px 20px;
}

/* =========================
   Modal
========================= */

.modal-backdrop{
  position:fixed;
  inset:0;
  z-index:100;
  background:rgba(15,23,42,.5);
  display:flex;
  align-items:center;
  justify-content:center;
  padding:20px;
}

.modal{
  width:min(520px,100%);
  background:#fff;
  border-radius:12px;
  box-shadow:0 20px 60px rgba(0,0,0,.25);
}

.modal-header{
  padding:18px 20px;
  border-bottom:1px solid var(--border);
  font-weight:700;
}

.modal-body{
  padding:20px;
}

.modal-footer{
  padding:15px 20px;
  border-top:1px solid var(--border);
  display:flex;
  justify-content:flex-end;
  gap:8px;
}

/* =========================
   Toast
========================= */

.toast-container{
  position:fixed;
  right:20px;
  bottom:20px;
  z-index:200;
  display:flex;
  flex-direction:column;
  gap:8px;
}

.toast{
  background:#0f172a;
  color:#fff;
  padding:12px 16px;
  border-radius:8px;
  box-shadow:0 8px 25px rgba(0,0,0,.2);
  animation:toastIn .2s ease;
}

.toast.success{background:#166534}
.toast.error{background:#991b1b}

@keyframes toastIn{
  from{opacity:0;transform:translateY(8px)}
  to{opacity:1;transform:translateY(0)}
}

/* =========================
   Empty
========================= */

.empty{
  padding:50px 20px;
  text-align:center;
  color:var(--gray);
}

/* =========================
   Responsive
========================= */

@media(max-width:900px){
  .admin-header{
    padding:0 12px;
    gap:10px;
    overflow-x:auto;
  }

  .admin-nav{
    flex-shrink:0;
  }

  .page{
    padding:18px 12px;
  }

  .form-grid,
  .send-grid{
    grid-template-columns:1fr;
  }

  .stats{
    grid-template-columns:repeat(2,minmax(0,1fr));
  }

  .question-body{
    padding-left:0;
  }

  .question-head{
    flex-wrap:wrap;
  }
}

@media(max-width:600px){
  .admin-logo{
    font-size:15px;
  }

  .admin-nav button{
    padding:0 9px;
    font-size:12px;
  }

  .page-title{
    align-items:flex-start;
    flex-direction:column;
  }

  .stats{
    grid-template-columns:1fr 1fr;
  }

  .stat{
    padding:15px;
  }

  .stat-value{
    font-size:24px;
  }

  .preview-inner{
    padding:18px;
  }

  .respondent-container{
    padding:12px;
  }

  .answer-card{
    padding:16px;
  }
}
</style>
</head>

<body>

<?php if (in_array($screen, ['answer','confirm','complete'], true)): ?>

<!-- ============================================================
     Respondent UI
============================================================ -->

<div class="respondent-page">

  <div class="respondent-header">
    <div style="max-width:760px;margin:auto;font-weight:700">
      アンケート
    </div>
  </div>

  <main class="respondent-container" id="respondentApp"></main>

</div>

<?php else: ?>

<!-- ============================================================
     Admin UI
============================================================ -->

<header class="admin-header">
  <div class="admin-logo">アンケート管理システム</div>

  <nav class="admin-nav">
    <button
      data-nav="list"
      onclick="go('list')">
      アンケート
    </button>

    <button
      data-nav="kintone"
      onclick="go('kintone')">
      kintone
    </button>

    <button
      data-nav="mail"
      onclick="go('mail')">
      メール
    </button>
  </nav>

  <div class="admin-spacer"></div>

  <span style="font-size:12px;color:#cbd5e1">
    管理者
  </span>
</header>

<main class="page" id="adminApp"></main>

<?php endif; ?>

<!-- Common confirmation dialog -->
<div id="modalRoot"></div>

<div class="toast-container" id="toastContainer"></div>

<script>
'use strict';

/* ============================================================
   Constants
============================================================ */

const STORAGE_KEY = 'questionnaire_mock_v1';

const SCREEN = <?= json_encode($screen, JSON_UNESCAPED_UNICODE) ?>;
const INITIAL_ID = <?= json_encode($surveyId, JSON_UNESCAPED_UNICODE) ?>;

/* ============================================================
   Initial data
============================================================ */

const DEFAULT_DATA = {
  surveys: [
    {
      id: 'survey-001',
      title: '2026年度 顧客満足度アンケート',
      description: 'サービス改善のためのアンケートです。',
      startAt: '2026-08-01T09:00',
      endAt: '2026-09-30T23:59',
      status: 'published',
      numbering: 'global',
      createdAt: '2026-07-20T10:00:00',
      updatedAt: '2026-08-20T15:30:00',
      groups: [
        {
          id: 'group-001',
          title: '基本情報',
          questions: [
            {
              id: 'question-001',
              text: '今回ご利用いただいたサービスはいかがでしたか？',
              type: 'single',
              required: true,
              options: [
                {id:'option-001', text:'とても満足'},
                {id:'option-002', text:'満足'},
                {id:'option-003', text:'普通'},
                {id:'option-004', text:'やや不満'},
                {id:'option-005', text:'不満'}
              ],
              branches: {}
            },
            {
              id: 'question-002',
              text: '特に良かった点を教えてください。',
              type: 'multiple',
              required: false,
              options: [
                {id:'option-006', text:'価格'},
                {id:'option-007', text:'品質'},
                {id:'option-008', text:'対応'},
                {id:'option-009', text:'使いやすさ'}
              ],
              branches: {}
            }
          ]
        },
        {
          id: 'group-002',
          title: 'ご意見',
          questions: [
            {
              id: 'question-003',
              text: '改善してほしい点があれば教えてください。',
              type: 'text',
              required: false,
              options: [],
              branches: {}
            }
          ]
        }
      ]
    },
    {
      id: 'survey-002',
      title: '新サービス利用前アンケート',
      description: '新サービスに関する事前アンケートです。',
      startAt: '2026-08-15T09:00',
      endAt: '2026-10-31T23:59',
      status: 'draft',
      numbering: 'group',
      createdAt: '2026-08-10T11:00:00',
      updatedAt: '2026-08-25T17:20:00',
      groups: [
        {
          id: 'group-003',
          title: '利用状況',
          questions: [
            {
              id: 'question-004',
              text: '現在利用しているサービスを教えてください。',
              type: 'single',
              required: true,
              options: [
                {id:'option-010', text:'サービスA'},
                {id:'option-011', text:'サービスB'},
                {id:'option-012', text:'その他'}
              ],
              branches: {}
            }
          ]
        }
      ]
    }
  ],

  customers: [
    {
      id:'customer-001',
      organization:'株式会社サンプル',
      name:'山田 太郎',
      email:'yamada@example.com',
      department:'営業部',
      phone:'03-0000-0001',
      address:'東京都港区'
    },
    {
      id:'customer-002',
      organization:'株式会社サンプル',
      name:'佐藤 花子',
      email:'sato@example.com',
      department:'企画部',
      phone:'03-0000-0002',
      address:'東京都千代田区'
    },
    {
      id:'customer-003',
      organization:'テスト株式会社',
      name:'鈴木 一郎',
      email:'suzuki@example.com',
      department:'総務部',
      phone:'03-0000-0003',
      address:'東京都新宿区'
    },
    {
      id:'customer-004',
      organization:'テスト株式会社',
      name:'田中 次郎',
      email:'tanaka@example.com',
      department:'開発部',
      phone:'03-0000-0004',
      address:'東京都渋谷区'
    }
  ],

  responses: [
    {
      id:'response-001',
      surveyId:'survey-001',
      customerId:'customer-001',
      registered:true,
      createdAt:'2026-08-21T10:00:00',
      answers:{
        'question-001':'とても満足',
        'question-002':['品質','対応'],
        'question-003':'丁寧な対応が良かったです。'
      }
    },
    {
      id:'response-002',
      surveyId:'survey-001',
      customerId:null,
      registered:false,
      createdAt:'2026-08-22T14:00:00',
      answers:{
        'question-001':'満足',
        'question-002':['使いやすさ'],
        'question-003':''
      }
    }
  ],

  sendLogs: [
    {
      id:'send-001',
      surveyId:'survey-001',
      customerId:'customer-001',
      type:'send',
      status:'success',
      sentAt:'2026-08-20T09:30:00'
    }
  ],

  settings:{
    kintone:{
      subdomain:'',
      appId:'',
      username:'',
      password:'',
      sslVerify:false,
      addressFields:['address']
    },
    mail:{
      smtpServer:'',
      smtpPort:'587',
      encryption:'TLS',
      auth:true,
      username:'',
      password:'',
      fromEmail:'',
      fromName:'',
      replyTo:'',
      status:'未設定'
    }
  }
};

/* ============================================================
   State
============================================================ */

let data = loadData();

let currentEditId = INITIAL_ID || null;
let currentSurvey = null;
let editDraft = null;
let selectedCustomers = [];
let sendTab = 'send';
let customerSearch = '';
let listFilter = 'all';
let listSearch = '';
let listSort = 'updated_desc';

let answerState = {
  surveyId: INITIAL_ID || null,
  values: {},
  visibleQuestionIds: [],
  page: 0
};

/* ============================================================
   Storage
============================================================ */

function deepClone(value){
  return JSON.parse(JSON.stringify(value));
}

function loadData(){
  try{
    const raw = localStorage.getItem(STORAGE_KEY);

    if(!raw){
      const initial = deepClone(DEFAULT_DATA);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(initial));
      return initial;
    }

    return JSON.parse(raw);
  }catch(e){
    return deepClone(DEFAULT_DATA);
  }
}

function saveData(){
  localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
}

function resetMockData(){
  if(!confirm('モックデータを初期状態に戻します。よろしいですか？')){
    return;
  }

  data = deepClone(DEFAULT_DATA);
  saveData();
  toast('初期データに戻しました。','success');
  setTimeout(()=>location.reload(),500);
}

/* ============================================================
   Utilities
============================================================ */

function uid(prefix){
  return prefix + '-' + Math.random().toString(36).slice(2,10);
}

function nowISO(){
  return new Date().toISOString();
}

function formatDate(value){
  if(!value) return '-';

  const d = new Date(value);

  if(Number.isNaN(d.getTime())){
    return value;
  }

  return d.toLocaleString('ja-JP',{
    year:'numeric',
    month:'2-digit',
    day:'2-digit',
    hour:'2-digit',
    minute:'2-digit'
  });
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

function statusLabel(status){
  return {
    draft:'下書き',
    published:'公開中',
    stopped:'停止',
    ended:'終了'
  }[status] || status;
}

function statusClass(status){
  return 'status status-' + status;
}

function getSurvey(id){
  return data.surveys.find(s => s.id === id) || null;
}

function surveyResponseCount(id){
  return data.responses.filter(r => r.surveyId === id).length;
}

function surveySentCount(id){
  return data.sendLogs.filter(r =>
    r.surveyId === id &&
    r.status === 'success'
  ).length;
}

function flattenQuestions(survey){
  if(!survey) return [];

  const result = [];

  survey.groups.forEach((group,gIndex)=>{
    group.questions.forEach((question,qIndex)=>{
      result.push({
        ...question,
        groupId:group.id,
        groupIndex:gIndex,
        questionIndex:qIndex
      });
    });
  });

  return result;
}

/* ============================================================
   Automatic status
============================================================ */

function updateAutomaticStatuses(){
  const now = Date.now();
  let changed = false;

  data.surveys.forEach(survey=>{
    if(
      survey.status === 'published' &&
      survey.endAt &&
      new Date(survey.endAt).getTime() < now
    ){
      survey.status = 'ended';
      survey.updatedAt = nowISO();
      changed = true;
    }
  });

  if(changed){
    saveData();
  }
}

updateAutomaticStatuses();

/* ============================================================
   Navigation
============================================================ */

function go(screen,id=''){
  let url = 'index.php?screen=' + encodeURIComponent(screen);

  if(id){
    url += '&id=' + encodeURIComponent(id);
  }

  location.href = url;
}

function goList(){
  go('list');
}

function setActiveNavigation(){
  document.querySelectorAll('[data-nav]').forEach(el=>{
    el.classList.toggle(
      'active',
      el.dataset.nav === SCREEN ||
      (SCREEN === 'edit' && el.dataset.nav === 'list') ||
      (SCREEN === 'preview' && el.dataset.nav === 'list') ||
      (SCREEN === 'send' && el.dataset.nav === 'list') ||
      (SCREEN === 'analytics' && el.dataset.nav === 'list')
    );
  });
}

/* ============================================================
   Toast
============================================================ */

function toast(message,type=''){
  const root = document.getElementById('toastContainer');

  if(!root) return;

  const el = document.createElement('div');

  el.className = 'toast ' + type;
  el.textContent = message;

  root.appendChild(el);

  setTimeout(()=>{
    el.remove();
  },2800);
}

/* ============================================================
   Confirmation dialog
============================================================ */

function confirmAction(message,onConfirm,confirmText='実行する'){
  const root = document.getElementById('modalRoot');

  root.innerHTML = `
    <div class="modal-backdrop">
      <div class="modal">
        <div class="modal-header">確認</div>
        <div class="modal-body">
          ${escapeHtml(message)}
        </div>
        <div class="modal-footer">
          <button class="btn" onclick="closeModal()">キャンセル</button>
          <button class="btn btn-primary" id="modalConfirm">${escapeHtml(confirmText)}</button>
        </div>
      </div>
    </div>
  `;

  document.getElementById('modalConfirm').onclick = ()=>{
    closeModal();
    onConfirm();
  };
}

function closeModal(){
  const root = document.getElementById('modalRoot');

  if(root){
    root.innerHTML = '';
  }
}

/* ============================================================
   List
============================================================ */

function renderList(){
  updateAutomaticStatuses();

  const app = document.getElementById('adminApp');

  let surveys = [...data.surveys];

  if(listSearch.trim()){
    const q = listSearch.trim().toLowerCase();

    surveys = surveys.filter(s =>
      s.title.toLowerCase().includes(q)
    );
  }

  if(listFilter !== 'all'){
    surveys = surveys.filter(s => s.status === listFilter);
  }

  surveys.sort((a,b)=>{
    if(listSort === 'updated_desc'){
      return new Date(b.updatedAt) - new Date(a.updatedAt);
    }

    if(listSort === 'updated_asc'){
      return new Date(a.updatedAt) - new Date(b.updatedAt);
    }

    if(listSort === 'responses_desc'){
      return surveyResponseCount(b.id) - surveyResponseCount(a.id);
    }

    if(listSort === 'responses_asc'){
      return surveyResponseCount(a.id) - surveyResponseCount(b.id);
    }

    if(listSort === 'start_desc'){
      return new Date(b.startAt) - new Date(a.startAt);
    }

    if(listSort === 'start_asc'){
      return new Date(a.startAt) - new Date(b.startAt);
    }

    return 0;
  });

  app.innerHTML = `
    <div class="page-title">
      <div>
        <h1>アンケート一覧</h1>
        <p>アンケートの作成・公開・送信・集計を管理します。</p>
      </div>

      <button class="btn btn-primary" onclick="createSurvey()">
        ＋ 新規作成
      </button>
    </div>

    <div class="card">
      <div class="card-body">

        <div class="toolbar">
          <div class="search-box">
            <input
              id="surveySearch"
              value="${escapeHtml(listSearch)}"
              placeholder="タイトルで検索"
              onkeydown="if(event.key==='Enter')applyListSearch()"
            >
            <button class="btn" onclick="applyListSearch()">検索</button>
          </div>

          <select onchange="listSort=this.value;renderList()">
            <option value="updated_desc" ${listSort==='updated_desc'?'selected':''}>
              更新日：新しい順
            </option>
            <option value="updated_asc" ${listSort==='updated_asc'?'selected':''}>
              更新日：古い順
            </option>
            <option value="responses_desc" ${listSort==='responses_desc'?'selected':''}>
              回答数：多い順
            </option>
            <option value="responses_asc" ${listSort==='responses_asc'?'selected':''}>
              回答数：少ない順
            </option>
            <option value="start_desc" ${listSort==='start_desc'?'selected':''}>
              開始日：新しい順
            </option>
            <option value="start_asc" ${listSort==='start_asc'?'selected':''}>
              開始日：古い順
            </option>
          </select>
        </div>

        <div class="filters">
          ${filterButton('all','すべて')}
          ${filterButton('published','公開中')}
          ${filterButton('draft','下書き')}
          ${filterButton('stopped','停止')}
          ${filterButton('ended','終了')}
        </div>

      </div>
    </div>

    <div style="height:18px"></div>

    <div class="card">
      <div class="table-wrap">
        ${
          surveys.length
          ? `
            <table>
              <thead>
                <tr>
                  <th>タイトル</th>
                  <th>作成日</th>
                  <th>更新日</th>
                  <th>アンケート期間</th>
                  <th>ステータス</th>
                  <th>回答数</th>
                  <th>操作</th>
                </tr>
              </thead>
              <tbody>
                ${surveys.map(renderSurveyRow).join('')}
              </tbody>
            </table>
          `
          : `
            <div class="empty">
              該当するアンケートはありません。
            </div>
          `
        }
      </div>
    </div>
  `;
}

function filterButton(value,label){
  return `
    <button
      class="filter-btn ${listFilter===value?'active':''}"
      onclick="listFilter='${value}';renderList()">
      ${label}
    </button>
  `;
}

function renderSurveyRow(survey){
  return `
    <tr>
      <td>
        <strong>${escapeHtml(survey.title)}</strong>
        <div style="color:#64748b;font-size:11px;margin-top:3px">
          ${escapeHtml(survey.id)}
        </div>
      </td>

      <td>${formatDate(survey.createdAt)}</td>

      <td>${formatDate(survey.updatedAt)}</td>

      <td>
        ${formatDate(survey.startAt)}
        <br>
        〜
        <br>
        ${formatDate(survey.endAt)}
      </td>

      <td>
        <span class="${statusClass(survey.status)}">
          ${statusLabel(survey.status)}
        </span>
      </td>

      <td>${surveyResponseCount(survey.id)}</td>

      <td>
        <div class="btn-group">
          <button class="btn btn-sm"
            onclick="go('edit','${survey.id}')">
            確認・編集
          </button>

          <button class="btn btn-sm"
            onclick="go('analytics','${survey.id}')">
            集計
          </button>

          <button class="btn btn-sm"
            onclick="go('send','${survey.id}')">
            送信
          </button>

          <button class="btn btn-sm"
            onclick="duplicateSurvey('${survey.id}')">
            複製
          </button>

          <button class="btn btn-sm btn-danger"
            onclick="deleteSurvey('${survey.id}')">
            削除
          </button>
        </div>
      </td>
    </tr>
  `;
}

function applyListSearch(){
  listSearch = document.getElementById('surveySearch').value;
  renderList();
}

/* ============================================================
   Survey CRUD
============================================================ */

function createSurvey(){
  const survey = {
    id:uid('survey'),
    title:'新規アンケート',
    description:'',
    startAt:'',
    endAt:'',
    status:'draft',
    numbering:'global',
    createdAt:nowISO(),
    updatedAt:nowISO(),
    groups:[
      {
        id:uid('group'),
        title:'グループ1',
        questions:[
          {
            id:uid('question'),
            text:'',
            type:'single',
            required:true,
            options:[
              {id:uid('option'),text:'選択肢1'},
              {id:uid('option'),text:'選択肢2'}
            ],
            branches:{}
          }
        ]
      }
    ]
  };

  data.surveys.push(survey);
  saveData();

  go('edit',survey.id);
}

function duplicateSurvey(id){
  const original = getSurvey(id);

  if(!original) return;

  confirmAction(
    'このアンケートを複製して下書きとして追加します。',
    ()=>{
      const copy = deepClone(original);

      copy.id = uid('survey');
      copy.title += '（複製）';
      copy.status = 'draft';
      copy.createdAt = nowISO();
      copy.updatedAt = nowISO();

      copy.groups.forEach(group=>{
        group.id = uid('group');

        group.questions.forEach(question=>{
          question.id = uid('question');

          question.options.forEach(option=>{
            option.id = uid('option');
          });
        });
      });

      data.surveys.push(copy);
      saveData();

      toast('複製しました。','success');
      renderList();
    },
    '複製する'
  );
}

function deleteSurvey(id){
  const survey = getSurvey(id);

  if(!survey) return;

  confirmAction(
    `「${survey.title}」を削除します。この操作は取り消せません。`,
    ()=>{
      data.surveys = data.surveys.filter(s=>s.id!==id);
      data.responses = data.responses.filter(r=>r.surveyId!==id);
      data.sendLogs = data.sendLogs.filter(r=>r.surveyId!==id);

      saveData();

      toast('削除しました。','success');
      renderList();
    },
    '削除する'
  );
}

/* ============================================================
   Editor
============================================================ */

function renderEdit(){
  currentSurvey = getSurvey(INITIAL_ID);

  if(!currentSurvey){
    goList();
    return;
  }

  editDraft = deepClone(currentSurvey);

  renderEditor();
}

function renderEditor(){
  const app = document.getElementById('adminApp');

  renumberQuestions(editDraft);

  app.innerHTML = `
    <div class="editor-top">
      <div class="editor-top-left">
        <button class="btn" onclick="cancelEdit()">
          キャンセル
        </button>

        <button class="btn btn-primary" onclick="saveEditor()">
          保存して一覧へ
        </button>
      </div>

      <div class="editor-top-right">
        <span>状態：</span>

        ${
          editDraft.status === 'ended'
          ? `
            <span class="${statusClass(editDraft.status)}">
              終了
            </span>
          `
          : `
            <select id="statusSelect"
              onchange="requestStatusChange(this.value)">
              <option value="draft"
                ${editDraft.status==='draft'?'selected':''}>
                下書き
              </option>
              <option value="published"
                ${editDraft.status==='published'?'selected':''}>
                公開中
              </option>
              <option value="stopped"
                ${editDraft.status==='stopped'?'selected':''}>
                停止
              </option>
            </select>
          `
        }
      </div>
    </div>

    <div class="card editor-section">
      <div class="card-header">
        <strong>アンケート基本情報</strong>
      </div>

      <div class="card-body">
        <div class="form-grid">

          <div class="form-group full">
            <label>アンケートタイトル</label>
            <input
              value="${escapeHtml(editDraft.title)}"
              oninput="editDraft.title=this.value"
            >
          </div>

          <div class="form-group full">
            <label>アンケート説明</label>
            <textarea
              oninput="editDraft.description=this.value"
            >${escapeHtml(editDraft.description)}</textarea>
          </div>

          <div class="form-group">
            <label>開始日時</label>
            <input
              type="datetime-local"
              value="${escapeHtml(editDraft.startAt)}"
              onchange="editDraft.startAt=this.value"
            >
          </div>

          <div class="form-group">
            <label>終了日時</label>
            <input
              type="datetime-local"
              value="${escapeHtml(editDraft.endAt)}"
              onchange="editDraft.endAt=this.value"
            >
          </div>

          <div class="form-group">
            <label>質問番号の採番方式</label>
            <select onchange="editDraft.numbering=this.value;renderEditor()">
              <option value="global"
                ${editDraft.numbering==='global'?'selected':''}>
                アンケート全体で通番（Q1、Q2、Q3…）
              </option>
              <option value="group"
                ${editDraft.numbering==='group'?'selected':''}>
                グループ毎（Q1-1、Q1-2、Q2-1…）
              </option>
            </select>
          </div>

        </div>
      </div>
    </div>

    <div class="editor-section">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div>
          <h2 style="margin:0">グループ・質問</h2>
          <p style="color:#64748b;font-size:13px">
            ドラッグ＆ドロップで並び替え・グループ間移動ができます。
          </p>
        </div>

        <button class="btn btn-primary" onclick="addGroup()">
          ＋ グループを追加
        </button>
      </div>

      <div id="groupList">
        ${editDraft.groups.map((group,gIndex)=>renderGroup(group,gIndex)).join('')}
      </div>
    </div>

    <div class="editor-footer">
      <button class="btn btn-primary" onclick="addGroup()">
        ＋ グループを追加
      </button>
    </div>

    <div class="card">
      <div class="card-header">
        <strong>プレビュー</strong>
        <button class="btn" onclick="go('preview','${editDraft.id}')">
          プレビューを開く
        </button>
      </div>
    </div>
  `;
}

function renderGroup(group,gIndex){
  return `
    <div
      class="group-card"
      draggable="true"
      data-group-id="${group.id}"
      ondragstart="dragGroupStart(event,'${group.id}')"
      ondragover="event.preventDefault()"
      ondrop="dropGroup(event,'${group.id}')"
    >
      <div class="group-header">
        <span class="drag-handle">☷</span>

        <input
          class="group-title-input"
          value="${escapeHtml(group.title)}"
          oninput="changeGroupTitle('${group.id}',this.value)"
        >

        <span style="font-size:12px;color:#64748b">
          ${group.questions.length}問
        </span>

        <button
          class="btn btn-sm"
          onclick="moveGroup('${group.id}',-1)">
          ↑
        </button>

        <button
          class="btn btn-sm"
          onclick="moveGroup('${group.id}',1)">
          ↓
        </button>

        <button
          class="btn btn-sm btn-danger"
          onclick="deleteGroup('${group.id}')">
          削除
        </button>
      </div>

      <div
        class="question-list"
        data-group-drop="${group.id}"
        ondragover="event.preventDefault()"
        ondrop="dropQuestion(event,'${group.id}')"
      >
        ${
          group.questions.length
          ? group.questions.map((q,qIndex)=>
              renderQuestion(q,group,gIndex,qIndex)
            ).join('')
          : `
            <div class="empty" style="padding:25px">
              質問がありません。
            </div>
          `
        }
      </div>

      <div style="padding:12px;border-top:1px solid var(--border)">
        <button
          class="btn btn-primary"
          onclick="addQuestion('${group.id}')">
          ＋ 質問を追加
        </button>
      </div>
    </div>
  `;
}

function renderQuestion(question,group,gIndex,qIndex){
  return `
    <div
      class="question-card"
      draggable="true"
      data-question-id="${question.id}"
      ondragstart="dragQuestionStart(event,'${question.id}','${group.id}')"
      ondragover="event.preventDefault()"
      ondrop="dropQuestion(event,'${group.id}','${question.id}')"
    >

      <div class="question-head">
        <span class="drag-handle">☷</span>

        <span class="question-number">
          ${escapeHtml(question.number || '')}
        </span>

        <div class="question-text">
          ${escapeHtml(question.text || '質問文未入力')}
        </div>

        <div class="question-actions">
          <button
            class="btn btn-sm"
            onclick="moveQuestion('${question.id}','${group.id}',-1)">
            ↑
          </button>

          <button
            class="btn btn-sm"
            onclick="moveQuestion('${question.id}','${group.id}',1)">
            ↓
          </button>

          <button
            class="btn btn-sm btn-danger"
            onclick="deleteQuestion('${question.id}','${group.id}')">
            削除
          </button>
        </div>
      </div>

      <div class="question-body">

        <div class="form-group">
          <label>質問文</label>
          <textarea
            oninput="changeQuestion('${question.id}','text',this.value)"
          >${escapeHtml(question.text)}</textarea>
        </div>

        <div class="form-grid" style="margin-top:12px">

          <div class="form-group">
            <label>回答形式</label>
            <select
              onchange="changeQuestion('${question.id}','type',this.value);renderEditor()"
            >
              <option value="single"
                ${question.type==='single'?'selected':''}>
                単一選択
              </option>
              <option value="multiple"
                ${question.type==='multiple'?'selected':''}>
                複数選択
              </option>
              <option value="text"
                ${question.type==='text'?'selected':''}>
                自由記述
              </option>
            </select>
          </div>

          <div class="form-group">
            <label>回答必須</label>
            <select
              onchange="changeQuestion('${question.id}','required',this.value==='true')"
            >
              <option value="true" ${question.required?'selected':''}>
                必須
              </option>
              <option value="false" ${!question.required?'selected':''}>
                任意
              </option>
            </select>
          </div>

        </div>

        ${
          question.type !== 'text'
          ? renderOptions(question)
          : ''
        }

        ${
          question.type === 'single'
          ? renderBranches(question)
          : ''
        }

      </div>
    </div>
  `;
}

function renderOptions(question){
  return `
    <div style="margin-top:15px">
      <label>選択肢</label>

      <div class="option-list">
        ${question.options.map((option,index)=>`
          <div class="option-row">
            <input
              type="text"
              value="${escapeHtml(option.text)}"
              oninput="changeOption('${question.id}','${option.id}',this.value)"
            >

            <button
              class="btn btn-sm btn-danger"
              onclick="deleteOption('${question.id}','${option.id}')">
              削除
            </button>
          </div>
        `).join('')}
      </div>

      <button
        class="btn btn-sm"
        style="margin-top:8px"
        onclick="addOption('${question.id}')">
        ＋ 選択肢を追加
      </button>
    </div>
  `;
}

function renderBranches(question){
  const allQuestions = flattenQuestions(editDraft)
    .filter(q=>q.id!==question.id);

  return `
    <div style="margin-top:15px">
      <label>条件分岐</label>

      <p style="font-size:12px;color:#64748b">
        選択肢ごとに次に表示する質問を指定できます。
      </p>

      ${
        question.options.map(option=>`
          <div class="form-grid" style="margin-bottom:8px">
            <div>
              <input
                value="${escapeHtml(option.text)}"
                disabled
              >
            </div>

            <div>
              <select
                onchange="changeBranch(
                  '${question.id}',
                  '${option.id}',
                  this.value
                )"
              >
                <option value="">次の質問を指定しない</option>

                ${allQuestions.map(target=>`
                  <option
                    value="${target.id}"
                    ${question.branches &&
                      question.branches[option.id]===target.id
                      ?'selected':''}>
                    ${escapeHtml(target.number)}：
                    ${escapeHtml(target.text)}
                  </option>
                `).join('')}
              </select>
            </div>
          </div>
        `).join('')
      }
    </div>
  `;
}

/* ============================================================
   Editor mutations
============================================================ */

function renumberQuestions(survey){
  let globalIndex = 1;

  survey.groups.forEach((group,gIndex)=>{
    group.questions.forEach((question,qIndex)=>{
      if(survey.numbering === 'global'){
        question.number = `Q${globalIndex}`;
      }else{
        question.number = `Q${gIndex+1}-${qIndex+1}`;
      }

      globalIndex++;
    });
  });
}

function findQuestion(questionId){
  for(const group of editDraft.groups){
    const question = group.questions.find(q=>q.id===questionId);

    if(question){
      return {
        group,
        question
      };
    }
  }

  return null;
}

function changeGroupTitle(groupId,value){
  const group = editDraft.groups.find(g=>g.id===groupId);

  if(group){
    group.title = value;
  }
}

function changeQuestion(questionId,key,value){
  const result = findQuestion(questionId);

  if(!result) return;

  result.question[key] = value;
}

function changeOption(questionId,optionId,value){
  const result = findQuestion(questionId);

  if(!result) return;

  const option = result.question.options.find(o=>o.id===optionId);

  if(option){
    option.text = value;
  }
}

function addOption(questionId){
  const result = findQuestion(questionId);

  if(!result) return;

  result.question.options.push({
    id:uid('option'),
    text:'新しい選択肢'
  });

  renderEditor();
}

function deleteOption(questionId,optionId){
  const result = findQuestion(questionId);

  if(!result) return;

  if(result.question.options.length <= 1){
    toast('選択肢は1つ以上必要です。','error');
    return;
  }

  result.question.options =
    result.question.options.filter(o=>o.id!==optionId);

  if(result.question.branches){
    delete result.question.branches[optionId];
  }

  renderEditor();
}

function changeBranch(questionId,optionId,targetId){
  const result = findQuestion(questionId);

  if(!result) return;

  if(!result.question.branches){
    result.question.branches = {};
  }

  if(targetId){
    result.question.branches[optionId] = targetId;
  }else{
    delete result.question.branches[optionId];
  }
}

function addGroup(){
  editDraft.groups.push({
    id:uid('group'),
    title:`グループ${editDraft.groups.length+1}`,
    questions:[]
  });

  renderEditor();
}

function deleteGroup(groupId){
  const group = editDraft.groups.find(g=>g.id===groupId);

  if(!group) return;

  confirmAction(
    `「${group.title}」を削除します。含まれる質問も削除されます。`,
    ()=>{
      editDraft.groups =
        editDraft.groups.filter(g=>g.id!==groupId);

      renderEditor();
    },
    'グループを削除'
  );
}

function addQuestion(groupId){
  const group = editDraft.groups.find(g=>g.id===groupId);

  if(!group) return;

  group.questions.push({
    id:uid('question'),
    text:'',
    type:'single',
    required:true,
    options:[
      {id:uid('option'),text:'選択肢1'},
      {id:uid('option'),text:'選択肢2'}
    ],
    branches:{}
  });

  renderEditor();
}

function deleteQuestion(questionId,groupId){
  confirmAction(
    'この質問を削除します。',
    ()=>{
      const group = editDraft.groups.find(g=>g.id===groupId);

      if(!group) return;

      group.questions =
        group.questions.filter(q=>q.id!==questionId);

      renderEditor();
    },
    '質問を削除'
  );
}

function moveGroup(groupId,direction){
  const index = editDraft.groups.findIndex(g=>g.id===groupId);

  if(index < 0) return;

  const target = index + direction;

  if(target < 0 || target >= editDraft.groups.length){
    return;
  }

  const temp = editDraft.groups[index];

  editDraft.groups[index] = editDraft.groups[target];
  editDraft.groups[target] = temp;

  renderEditor();
}

function moveQuestion(questionId,groupId,direction){
  const group = editDraft.groups.find(g=>g.id===groupId);

  if(!group) return;

  const index = group.questions.findIndex(q=>q.id===questionId);

  if(index < 0) return;

  const target = index + direction;

  if(target < 0 || target >= group.questions.length){
    return;
  }

  const temp = group.questions[index];

  group.questions[index] = group.questions[target];
  group.questions[target] = temp;

  renderEditor();
}

let dragGroupId = null;
let dragQuestionId = null;
let dragQuestionSourceGroupId = null;

function dragGroupStart(event,groupId){
  dragGroupId = groupId;
  event.dataTransfer.effectAllowed = 'move';
}

function dropGroup(event,targetGroupId){
  event.preventDefault();

  if(!dragGroupId || dragGroupId===targetGroupId){
    return;
  }

  const from = editDraft.groups.findIndex(g=>g.id===dragGroupId);
  const to = editDraft.groups.findIndex(g=>g.id===targetGroupId);

  if(from < 0 || to < 0) return;

  const [group] = editDraft.groups.splice(from,1);

  editDraft.groups.splice(to,0,group);

  dragGroupId = null;

  renderEditor();
}

function dragQuestionStart(event,questionId,sourceGroupId){
  dragQuestionId = questionId;
  dragQuestionSourceGroupId = sourceGroupId;

  event.dataTransfer.effectAllowed = 'move';
}

function dropQuestion(event,targetGroupId,targetQuestionId=null){
  event.preventDefault();

  if(!dragQuestionId) return;

  const sourceGroup =
    editDraft.groups.find(g=>g.id===dragQuestionSourceGroupId);

  const targetGroup =
    editDraft.groups.find(g=>g.id===targetGroupId);

  if(!sourceGroup || !targetGroup) return;

  const sourceIndex =
    sourceGroup.questions.findIndex(q=>q.id===dragQuestionId);

  if(sourceIndex < 0) return;

  const [question] =
    sourceGroup.questions.splice(sourceIndex,1);

  if(targetQuestionId){
    let targetIndex =
      targetGroup.questions.findIndex(q=>q.id===targetQuestionId);

    if(targetIndex < 0){
      targetIndex = targetGroup.questions.length;
    }

    targetGroup.questions.splice(targetIndex,0,question);
  }else{
    targetGroup.questions.push(question);
  }

  dragQuestionId = null;
  dragQuestionSourceGroupId = null;

  renderEditor();
}

function requestStatusChange(newStatus){
  const oldStatus = editDraft.status;

  if(oldStatus === 'ended'){
    renderEditor();
    return;
  }

  if(newStatus === oldStatus){
    return;
  }

  const messages = {
    published:'アンケートを公開します。',
    stopped:'アンケートを停止します。',
    draft:'アンケートを下書きに戻します。'
  };

  confirmAction(
    messages[newStatus] || '状態を変更します。',
    ()=>{
      editDraft.status = newStatus;
      renderEditor();
    },
    '変更する'
  );
}

function saveEditor(){
  if(!editDraft.title.trim()){
    toast('アンケートタイトルを入力してください。','error');
    return;
  }

  if(
    editDraft.endAt &&
    editDraft.startAt &&
    new Date(editDraft.endAt) <= new Date(editDraft.startAt)
  ){
    toast('終了日時は開始日時より後にしてください。','error');
    return;
  }

  renumberQuestions(editDraft);

  editDraft.updatedAt = nowISO();

  const index =
    data.surveys.findIndex(s=>s.id===editDraft.id);

  if(index < 0){
    data.surveys.push(editDraft);
  }else{
    data.surveys[index] = deepClone(editDraft);
  }

  saveData();

  toast('保存しました。','success');

  setTimeout(()=>goList(),400);
}

function cancelEdit(){
  confirmAction(
    '編集内容を破棄して前の画面へ戻ります。',
    ()=>{
      goList();
    },
    '破棄する'
  );
}

/* ============================================================
   Preview
============================================================ */

let previewDevice = 'pc';

function renderPreview(){
  const survey = getSurvey(INITIAL_ID);

  if(!survey){
    goList();
    return;
  }

  renumberQuestions(survey);

  const app = document.getElementById('adminApp');

  app.innerHTML = `
    <div class="page-title">
      <div>
        <h1>プレビュー</h1>
        <p>${escapeHtml(survey.title)}</p>
      </div>

      <div class="btn-group">
        <button class="btn" onclick="go('edit','${survey.id}')">
          編集へ戻る
        </button>

        <button
          class="btn ${previewDevice==='pc'?'btn-primary':''}"
          onclick="previewDevice='pc';renderPreview()">
          PC
        </button>

        <button
          class="btn ${previewDevice==='mobile'?'btn-primary':''}"
          onclick="previewDevice='mobile';renderPreview()">
          スマートフォン
        </button>
      </div>
    </div>

    <div class="device-preview ${previewDevice}">
      <div class="preview-inner">

        <h1 style="margin-top:0">
          ${escapeHtml(survey.title)}
        </h1>

        ${
          survey.description
          ? `<p style="color:#64748b">
              ${escapeHtml(survey.description)}
             </p>`
          : ''
        }

        <hr style="border:0;border-top:1px solid #dbe2ea;margin:25px 0">

        ${survey.groups.map(group=>`
          <section style="margin-bottom:35px">
            <h2 style="font-size:20px">
              ${escapeHtml(group.title)}
            </h2>

            ${group.questions.map(question=>renderPreviewQuestion(question)).join('')}
          </section>
        `).join('')}

      </div>
    </div>
  `;
}

function renderPreviewQuestion(question){
  return `
    <div class="preview-question">
      <h3>
        ${escapeHtml(question.number)}
        ${escapeHtml(question.text)}
        ${question.required
          ? '<span class="required">必須</span>'
          : ''}
      </h3>

      ${
        question.type==='single'
        ? question.options.map(option=>`
            <label class="preview-option">
              <input type="radio" name="${question.id}">
              ${escapeHtml(option.text)}
            </label>
          `).join('')
        : ''
      }

      ${
        question.type==='multiple'
        ? question.options.map(option=>`
            <label class="preview-option">
              <input type="checkbox">
              ${escapeHtml(option.text)}
            </label>
          `).join('')
        : ''
      }

      ${
        question.type==='text'
        ? `
          <textarea
            placeholder="回答を入力してください"
            style="min-height:120px">
          </textarea>
        `
        : ''
      }

      ${
        question.type==='single' &&
        Object.keys(question.branches || {}).length
        ? `
          <div class="branch-note">
            条件分岐が設定されています
          </div>
        `
        : ''
      }
    </div>
  `;
}

/* ============================================================
   Send
============================================================ */

function renderSend(){
  const survey = getSurvey(INITIAL_ID);

  if(!survey){
    goList();
    return;
  }

  currentSurvey = survey;

  const app = document.getElementById('adminApp');

  const customers = data.customers.filter(customer=>{
    if(!customerSearch.trim()) return true;

    const q = customerSearch.toLowerCase();

    return [
      customer.organization,
      customer.name,
      customer.email,
      customer.department,
      customer.phone,
      customer.address
    ].some(v=>String(v).toLowerCase().includes(q));
  });

  app.innerHTML = `
    <div class="page-title">
      <div>
        <h1>顧客選択・メール送信</h1>
        <p>
          対象アンケート：
          <strong>${escapeHtml(survey.title)}</strong>
        </p>
      </div>

      <button class="btn" onclick="goList()">
        一覧へ戻る
      </button>
    </div>

    <div class="send-tabs">
      <button
        class="send-tab ${sendTab==='send'?'active':''}"
        onclick="sendTab='send';renderSend()">
        顧客選択・送信
      </button>

      <button
        class="send-tab ${sendTab==='history'?'active':''}"
        onclick="sendTab='history';renderSend()">
        送信履歴
      </button>
    </div>

    ${
      sendTab==='send'
      ? renderSendPanel(survey,customers)
      : renderSendHistory(survey)
    }
  `;
}

function renderSendPanel(survey,customers){
  return `
    <div class="send-grid">

      <div class="card">
        <div class="card-header">
          <strong>顧客選択</strong>
          <span>
            選択：
            <strong>${selectedCustomers.length}</strong>名
          </span>
        </div>

        <div class="card-body">

          <div class="toolbar">
            <input
              placeholder="顧客検索"
              value="${escapeHtml(customerSearch)}"
              oninput="customerSearch=this.value;renderSend()"
            >

            <button
              class="btn"
              onclick="selectAllVisibleCustomers()">
              表示中を全選択
            </button>
          </div>

          <div class="customer-list">
            ${
              customers.length
              ? customers.map(customer=>`
                <label class="customer">
                  <input
                    type="checkbox"
                    ${selectedCustomers.includes(customer.id)?'checked':''}
                    onchange="toggleCustomer('${customer.id}',this.checked)"
                  >

                  <div class="customer-info">
                    <div class="customer-name">
                      ${escapeHtml(customer.name)}
                    </div>

                    <div class="customer-email">
                      ${escapeHtml(customer.organization)}
                      /
                      ${escapeHtml(customer.email)}
                    </div>

                    <div style="font-size:11px;color:#94a3b8">
                      ${escapeHtml(customer.department)}
                      /
                      ${escapeHtml(customer.phone)}
                    </div>
                  </div>
                </label>
              `).join('')
              : `
                <div class="empty">
                  該当する顧客はいません。
                </div>
              `
            }
          </div>

        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <strong>メール作成</strong>
        </div>

        <div class="card-body">

          <div class="form-group">
            <label>件名</label>
            <input
              id="mailSubject"
              value="${escapeHtml(
                localStorage.getItem('mail_subject_'+survey.id)
                || '【アンケートのお願い】'+survey.title
              )}"
            >
          </div>

          <div class="form-group" style="margin-top:15px">
            <label>本文</label>

            <textarea id="mailBody" style="min-height:260px">${escapeHtml(
              localStorage.getItem('mail_body_'+survey.id)
              || `{顧客名} 様

いつもお世話になっております。

以下のアンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。`
            )}</textarea>
          </div>

          <div style="margin-top:10px;color:#64748b;font-size:12px">
            使用可能な変数：
            <code>{顧客名}</code>
            <code>{アンケートURL}</code>
          </div>

          <div style="height:15px"></div>

          <div class="btn-group">
            <button class="btn"
              onclick="saveMailDraft('${survey.id}')">
              下書きを保存
            </button>

            <button class="btn"
              onclick="previewMail('${survey.id}')">
              送信文確認
            </button>

            <button class="btn btn-primary"
              onclick="bulkSend('${survey.id}')">
              一括送信
            </button>

            <button class="btn btn-warning"
              onclick="resendSelected('${survey.id}')">
              再送
            </button>

            <button class="btn"
              onclick="remindSelected('${survey.id}')">
              リマインド
            </button>
          </div>

          <div
            id="sendResult"
            style="margin-top:18px">
          </div>

        </div>
      </div>

    </div>
  `;
}

function renderSendHistory(survey){
  const logs = data.sendLogs
    .filter(log=>log.surveyId===survey.id)
    .sort((a,b)=>new Date(b.sentAt)-new Date(a.sentAt));

  return `
    <div class="card">
      <div class="card-header">
        <strong>送信履歴</strong>
      </div>

      <div class="table-wrap">
        ${
          logs.length
          ? `
            <table style="min-width:800px">
              <thead>
                <tr>
                  <th>送信日時</th>
                  <th>顧客</th>
                  <th>種別</th>
                  <th>結果</th>
                </tr>
              </thead>

              <tbody>
                ${logs.map(log=>{
                  const customer =
                    data.customers.find(c=>c.id===log.customerId);

                  return `
                    <tr>
                      <td>${formatDate(log.sentAt)}</td>
                      <td>
                        ${customer
                          ? escapeHtml(customer.name)
                          : '<span style="color:#dc2626">未登録</span>'}
                      </td>
                      <td>${escapeHtml(log.type)}</td>
                      <td>
                        <span class="${log.status==='success'
                          ?'status status-published'
                          :'status status-ended'}">
                          ${log.status==='success'?'成功':'失敗'}
                        </span>
                      </td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
          `
          : `
            <div class="empty">
              送信履歴はありません。
            </div>
          `
        }
      </div>
    </div>
  `;
}

function toggleCustomer(id,checked){
  if(checked){
    if(!selectedCustomers.includes(id)){
      selectedCustomers.push(id);
    }
  }else{
    selectedCustomers =
      selectedCustomers.filter(x=>x!==id);
  }
}

function selectAllVisibleCustomers(){
  data.customers.forEach(customer=>{
    if(!customerSearch.trim()){
      if(!selectedCustomers.includes(customer.id)){
        selectedCustomers.push(customer.id);
      }
      return;
    }

    const q = customerSearch.toLowerCase();

    const matched = [
      customer.organization,
      customer.name,
      customer.email,
      customer.department,
      customer.phone,
      customer.address
    ].some(v=>String(v).toLowerCase().includes(q));

    if(matched && !selectedCustomers.includes(customer.id)){
      selectedCustomers.push(customer.id);
    }
  });

  renderSend();
}

function saveMailDraft(surveyId){
  const subject = document.getElementById('mailSubject').value;
  const body = document.getElementById('mailBody').value;

  localStorage.setItem('mail_subject_'+surveyId,subject);
  localStorage.setItem('mail_body_'+surveyId,body);

  toast('メール下書きを保存しました。','success');
}

function getMailTemplate(surveyId){
  return {
    subject:
      localStorage.getItem('mail_subject_'+surveyId)
      || '【アンケートのお願い】'+currentSurvey.title,

    body:
      localStorage.getItem('mail_body_'+surveyId)
      || `{顧客名} 様

アンケートへのご回答をお願いいたします。

{アンケートURL}`
  };
}

function previewMail(surveyId){
  saveMailDraft(surveyId);

  const template = getMailTemplate(surveyId);

  const sample =
    template.body
      .replaceAll('{顧客名}','山田 太郎')
      .replaceAll(
        '{アンケートURL}',
        location.origin + location.pathname +
        '?screen=answer&id='+surveyId
      );

  confirmAction(
    `件名：${template.subject}\n\n${sample}`,
    ()=>{},
    '閉じる'
  );
}

function bulkSend(surveyId){
  if(!selectedCustomers.length){
    toast('顧客を選択してください。','error');
    return;
  }

  confirmAction(
    `${selectedCustomers.length}名に一括送信します。モック送信を実行しますか？`,
    ()=>{
      executeSend(surveyId,'send');
    },
    '一括送信する'
  );
}

function resendSelected(surveyId){
  if(!selectedCustomers.length){
    toast('再送する顧客を選択してください。','error');
    return;
  }

  confirmAction(
    `${selectedCustomers.length}名へ再送します。`,
    ()=>{
      executeSend(surveyId,'resend');
    },
    '再送する'
  );
}

function remindSelected(surveyId){
  if(!selectedCustomers.length){
    toast('リマインド対象を選択してください。','error');
    return;
  }

  confirmAction(
    `${selectedCustomers.length}名へリマインドします。`,
    ()=>{
      executeSend(surveyId,'remind');
    },
    'リマインドする'
  );
}

function executeSend(surveyId,type){
  selectedCustomers.forEach(customerId=>{
    data.sendLogs.push({
      id:uid('send'),
      surveyId,
      customerId,
      type,
      status:'success',
      sentAt:nowISO()
    });
  });

  saveData();

  const result = document.getElementById('sendResult');

  if(result){
    result.innerHTML = `
      <div style="
        background:#dcfce7;
        color:#166534;
        border-radius:8px;
        padding:15px">
        <strong>送信成功</strong><br>
        ${selectedCustomers.length}件のメールを送信しました。
      </div>
    `;
  }

  toast('送信処理が完了しました。','success');
}

/* ============================================================
   Analytics
============================================================ */

function renderAnalytics(){
  const survey = getSurvey(INITIAL_ID);

  if(!survey){
    goList();
    return;
  }

  const responses =
    data.responses.filter(r=>r.surveyId===survey.id);

  const sentCount =
    data.sendLogs.filter(
      l=>l.surveyId===survey.id &&
      l.status==='success'
    ).length;

  const registeredResponses =
    responses.filter(r=>r.registered).length;

  const unregisteredResponses =
    responses.filter(r=>!r.registered).length;

  const answeredCustomers =
    new Set(
      responses
        .filter(r=>r.customerId)
        .map(r=>r.customerId)
    );

  const unanswered =
    Math.max(
      sentCount - answeredCustomers.size,
      0
    );

  const responseRate =
    sentCount
      ? Math.round(
          (answeredCustomers.size / sentCount) * 100
        )
      : 0;

  const app = document.getElementById('adminApp');

  app.innerHTML = `
    <div class="page-title">
      <div>
        <h1>回答集計・分析</h1>
        <p>
          対象アンケート：
          <strong>${escapeHtml(survey.title)}</strong>
        </p>
      </div>

      <div class="btn-group">
        <button class="btn" onclick="exportCSV('${survey.id}')">
          CSV
        </button>

        <button class="btn" onclick="exportPDF('${survey.id}')">
          PDF
        </button>

        <button class="btn" onclick="goList()">
          一覧へ戻る
        </button>
      </div>
    </div>

    <div class="stats">
      <div class="stat">
        <div class="stat-label">送信対象者数</div>
        <div class="stat-value">${sentCount}</div>
      </div>

      <div class="stat">
        <div class="stat-label">回答数</div>
        <div class="stat-value">${responses.length}</div>
      </div>

      <div class="stat">
        <div class="stat-label">未登録回答数</div>
        <div class="stat-value">${unregisteredResponses}</div>
      </div>

      <div class="stat">
        <div class="stat-label">未回答数</div>
        <div class="stat-value">${unanswered}</div>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px">
      <div class="card-header">
        <strong>回答率</strong>
        <strong>${responseRate}%</strong>
      </div>

      <div class="card-body">
        <div class="bar">
          <span style="width:${Math.min(responseRate,100)}%"></span>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px">
      <div class="card-header">
        <strong>設問別集計</strong>
      </div>

      <div class="card-body">
        ${
          responses.length
          ? flattenQuestions(survey)
              .map(q=>renderQuestionAnalytics(q,responses))
              .join('')
          : `
            <div class="empty">
              現在、回答データはありません
            </div>
          `
        }
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <strong>個別回答</strong>
      </div>

      <div class="table-wrap">
        ${
          responses.length
          ? `
            <table>
              <thead>
                <tr>
                  <th>回答日時</th>
                  <th>回答者</th>
                  <th>登録状態</th>
                  <th>回答内容</th>
                </tr>
              </thead>

              <tbody>
                ${responses.map(response=>{
                  const customer =
                    data.customers.find(
                      c=>c.id===response.customerId
                    );

                  return `
                    <tr>
                      <td>${formatDate(response.createdAt)}</td>

                      <td>
                        ${
                          customer
                          ? escapeHtml(customer.name)
                          : '<span style="color:#dc2626">未登録</span>'
                        }
                      </td>

                      <td>
                        ${
                          response.registered
                          ? '<span class="status status-published">登録済み</span>'
                          : '<span class="status status-ended">未登録</span>'
                        }
                      </td>

                      <td>
                        ${Object.entries(response.answers)
                          .map(([questionId,value])=>{
                            const q =
                              flattenQuestions(survey)
                              .find(x=>x.id===questionId);

                            if(!q) return '';

                            return `
                              <div style="margin-bottom:7px">
                                <strong>${escapeHtml(q.number)}</strong>
                                ${escapeHtml(
                                  Array.isArray(value)
                                    ? value.join('、')
                                    : value
                                )}
                              </div>
                            `;
                          }).join('')}
                      </td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
          `
          : `
            <div class="empty">
              現在、回答データはありません
            </div>
          `
        }
      </div>
    </div>
  `;
}

function renderQuestionAnalytics(question,responses){
  if(question.type==='text'){
    const answers = responses
      .map(r=>r.answers[question.id])
      .filter(Boolean);

    return `
      <div class="answer-row">
        <div class="answer-row-head">
          <strong>
            ${escapeHtml(question.number)}
            ${escapeHtml(question.text)}
          </strong>
          <span>${answers.length}件</span>
        </div>

        ${
          answers.length
          ? `
            <div style="
              background:#f8fafc;
              padding:10px;
              border-radius:8px">
              ${answers.slice(0,5).map(a=>`
                <div style="margin-bottom:5px">
                  ${escapeHtml(a)}
                </div>
              `).join('')}
            </div>
          `
          : '<span style="color:#64748b">回答なし</span>'
        }
      </div>
    `;
  }

  const total = responses.length;

  return `
    <div class="answer-row">
      <div class="answer-row-head">
        <strong>
          ${escapeHtml(question.number)}
          ${escapeHtml(question.text)}
        </strong>
      </div>

      ${question.options.map(option=>{
        let count = 0;

        responses.forEach(response=>{
          const value = response.answers[question.id];

          if(Array.isArray(value)){
            if(value.includes(option.text)){
              count++;
            }
          }else if(value===option.text){
            count++;
          }
        });

        const percent =
          total ? Math.round((count/total)*100) : 0;

        return `
          <div style="margin:10px 0">
            <div class="answer-row-head">
              <span>${escapeHtml(option.text)}</span>
              <span>${count}件 (${percent}%)</span>
            </div>

            <div class="bar">
              <span style="width:${percent}%"></span>
            </div>
          </div>
        `;
      }).join('')}
    </div>
  `;
}

function exportCSV(surveyId){
  toast('CSVを出力しました。','success');
}

function exportPDF(surveyId){
  toast('PDFを出力しました。','success');
}

/* ============================================================
   kintone
============================================================ */

function renderKintone(){
  const app = document.getElementById('adminApp');
  const setting = data.settings.kintone;

  app.innerHTML = `
    <div class="page-title">
      <div>
        <h1>kintone連携設定</h1>
        <p>顧客情報との連携設定を行います。</p>
      </div>
    </div>

    <div class="card setting-section">
      <div class="card-header">
        <strong>接続設定</strong>
      </div>

      <div class="card-body">

        <div class="form-grid">

          <div class="form-group">
            <label>サブドメイン</label>
            <input
              id="kSubdomain"
              value="${escapeHtml(setting.subdomain)}"
              placeholder="example.cybozu.com"
            >
          </div>

          <div class="form-group">
            <label>顧客管理アプリID</label>
            <input
              id="kAppId"
              value="${escapeHtml(setting.appId)}"
            >
          </div>

          <div class="form-group">
            <label>ログイン名</label>
            <input
              id="kUsername"
              value="${escapeHtml(setting.username)}"
            >
          </div>

          <div class="form-group">
            <label>パスワード</label>
            <input
              type="password"
              id="kPassword"
              value="${escapeHtml(setting.password)}"
            >
          </div>

          <div class="form-group">
            <label>SSL証明書検証</label>

            <label style="
              display:flex;
              flex-direction:row;
              align-items:center;
              gap:8px;
              font-weight:400">
              <input
                type="checkbox"
                id="kSSL"
                style="width:auto"
                ${setting.sslVerify?'checked':''}
              >
              SSL証明書検証を有効にする
            </label>
          </div>

        </div>

        <hr style="
          border:0;
          border-top:1px solid var(--border);
          margin:25px 0">

        <div class="form-group">
          <label>住所マッピング</label>

          <div style="display:flex;gap:15px;flex-wrap:wrap">
            ${[
              ['postal','郵便番号'],
              ['prefecture','都道府県'],
              ['city','市区町村'],
              ['address','住所'],
              ['building','建物名']
            ].map(([key,label])=>`
              <label style="
                display:flex;
                flex-direction:row;
                align-items:center;
                gap:6px;
                font-weight:400">
                <input
                  type="checkbox"
                  value="${key}"
                  style="width:auto"
                  ${setting.addressFields.includes(key)?'checked':''}
                  class="address-field">
                ${label}
              </label>
            `).join('')}
          </div>
        </div>

        <div style="height:18px"></div>

        <div class="btn-group">
          <button class="btn btn-primary"
            onclick="saveKintoneSettings()">
            設定保存
          </button>

          <button class="btn"
            onclick="testKintoneConnection()">
            接続テスト
          </button>

          <button class="btn"
            onclick="refreshKintoneFields()">
            項目一覧を再取得
          </button>

          <button class="btn"
            onclick="syncCustomers()">
            顧客情報を同期
          </button>
        </div>

        <div id="kintoneResult" style="margin-top:18px"></div>

      </div>
    </div>

    <div style="height:18px"></div>

    <div class="card setting-section">
      <div class="card-header">
        <strong>モック項目一覧</strong>
      </div>

      <div class="card-body">
        <div class="table-wrap">
          <table style="min-width:650px">
            <thead>
              <tr>
                <th>フィールドコード</th>
                <th>表示名</th>
                <th>型</th>
              </tr>
            </thead>

            <tbody>
              <tr>
                <td>organization</td>
                <td>組織名</td>
                <td>文字列</td>
              </tr>
              <tr>
                <td>name</td>
                <td>氏名</td>
                <td>文字列</td>
              </tr>
              <tr>
                <td>email</td>
                <td>メールアドレス</td>
                <td>文字列</td>
              </tr>
              <tr>
                <td>department</td>
                <td>部署名</td>
                <td>文字列</td>
              </tr>
              <tr>
                <td>phone</td>
                <td>電話番号</td>
                <td>文字列</td>
              </tr>
              <tr>
                <td>address</td>
                <td>住所</td>
                <td>文字列</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `;
}

function saveKintoneSettings(){
  data.settings.kintone.subdomain =
    document.getElementById('kSubdomain').value;

  data.settings.kintone.appId =
    document.getElementById('kAppId').value;

  data.settings.kintone.username =
    document.getElementById('kUsername').value;

  data.settings.kintone.password =
    document.getElementById('kPassword').value;

  data.settings.kintone.sslVerify =
    document.getElementById('kSSL').checked;

  data.settings.kintone.addressFields =
    [...document.querySelectorAll('.address-field:checked')]
      .map(el=>el.value);

  saveData();

  showSettingResult(
    'kintoneResult',
    '設定を保存しました。',
    'success'
  );
}

function testKintoneConnection(){
  showSettingResult(
    'kintoneResult',
    '接続成功（モック）',
    'success'
  );
}

function refreshKintoneFields(){
  showSettingResult(
    'kintoneResult',
    '項目一覧を再取得しました。',
    'success'
  );
}

function syncCustomers(){
  showSettingResult(
    'kintoneResult',
    '顧客情報を同期しました。4件の顧客を取得しました。',
    'success'
  );
}

function showSettingResult(id,message,type){
  const el = document.getElementById(id);

  if(!el) return;

  el.innerHTML = `
    <div style="
      padding:13px;
      border-radius:8px;
      background:${type==='success'?'#dcfce7':'#fee2e2'};
      color:${type==='success'?'#166534':'#991b1b'}">
      ${escapeHtml(message)}
    </div>
  `;
}

/* ============================================================
   Mail settings
============================================================ */

function renderMail(){
  const app = document.getElementById('adminApp');
  const setting = data.settings.mail;

  app.innerHTML = `
    <div class="page-title">
      <div>
        <h1>メールサーバ設定</h1>
        <p>SMTP接続設定を管理します。</p>
      </div>
    </div>

    <div class="card setting-section">
      <div class="card-header">
        <strong>SMTP設定</strong>

        <span class="status ${
          setting.status==='接続確認済み'
          ? 'status-published'
          : setting.status==='接続できません'
            ? 'status-ended'
            : 'status-draft'
        }">
          ${escapeHtml(setting.status)}
        </span>
      </div>

      <div class="card-body">

        <div class="form-grid">

          <div class="form-group">
            <label>SMTPサーバ</label>
            <input id="smtpServer"
              value="${escapeHtml(setting.smtpServer)}">
          </div>

          <div class="form-group">
            <label>SMTPポート</label>
            <input id="smtpPort"
              value="${escapeHtml(setting.smtpPort)}">
          </div>

          <div class="form-group">
            <label>暗号化方式</label>
            <select id="smtpEncryption">
              <option value="SSL"
                ${setting.encryption==='SSL'?'selected':''}>
                SSL
              </option>
              <option value="TLS"
                ${setting.encryption==='TLS'?'selected':''}>
                TLS
              </option>
              <option value="none"
                ${setting.encryption==='none'?'selected':''}>
                なし
              </option>
            </select>
          </div>

          <div class="form-group">
            <label>SMTP認証</label>
            <select id="smtpAuth">
              <option value="true"
                ${setting.auth?'selected':''}>
                あり
              </option>
              <option value="false"
                ${!setting.auth?'selected':''}>
                なし
              </option>
            </select>
          </div>

          <div class="form-group">
            <label>SMTPユーザー名</label>
            <input id="smtpUsername"
              value="${escapeHtml(setting.username)}">
          </div>

          <div class="form-group">
            <label>SMTPパスワード</label>
            <input type="password" id="smtpPassword"
              value="${escapeHtml(setting.password)}">
          </div>

          <div class="form-group">
            <label>送信元メールアドレス</label>
            <input id="fromEmail"
              value="${escapeHtml(setting.fromEmail)}">
          </div>

          <div class="form-group">
            <label>送信元名</label>
            <input id="fromName"
              value="${escapeHtml(setting.fromName)}">
          </div>

          <div class="form-group">
            <label>返信先メールアドレス</label>
            <input id="replyTo"
              value="${escapeHtml(setting.replyTo)}">
          </div>

        </div>

        <div style="height:20px"></div>

        <div class="btn-group">
          <button class="btn btn-primary"
            onclick="saveMailSettings()">
            設定保存
          </button>

          <button class="btn"
            onclick="testSMTP()">
            接続確認
          </button>

          <button class="btn"
            onclick="sendTestMail()">
            テストメール送信
          </button>
        </div>

        <div id="mailResult" style="margin-top:18px"></div>

      </div>
    </div>
  `;
}

function saveMailSettings(){
  const s = data.settings.mail;

  s.smtpServer =
    document.getElementById('smtpServer').value;

  s.smtpPort =
    document.getElementById('smtpPort').value;

  s.encryption =
    document.getElementById('smtpEncryption').value;

  s.auth =
    document.getElementById('smtpAuth').value === 'true';

  s.username =
    document.getElementById('smtpUsername').value;

  s.password =
    document.getElementById('smtpPassword').value;

  s.fromEmail =
    document.getElementById('fromEmail').value;

  s.fromName =
    document.getElementById('fromName').value;

  s.replyTo =
    document.getElementById('replyTo').value;

  saveData();

  showSettingResult(
    'mailResult',
    'メールサーバ設定を保存しました。',
    'success'
  );
}

function testSMTP(){
  data.settings.mail.status = '接続確認済み';

  saveData();

  showSettingResult(
    'mailResult',
    'SMTP接続成功（モック）',
    'success'
  );
}

function sendTestMail(){
  showSettingResult(
    'mailResult',
    'テストメールを送信しました（モック）。',
    'success'
  );
}

/* ============================================================
   Respondent
============================================================ */

function renderRespondent(){
  const survey = getSurvey(INITIAL_ID);

  const app = document.getElementById('respondentApp');

  if(!survey){
    app.innerHTML = `
      <div class="answer-card">
        <h2>アンケートが見つかりません</h2>
        <p>指定されたアンケートは存在しません。</p>
      </div>
    `;
    return;
  }

  if(survey.status !== 'published'){
    app.innerHTML = `
      <div class="answer-card">
        <h2>現在回答できません</h2>
        <p>
          このアンケートは現在公開されていません。
        </p>
      </div>
    `;
    return;
  }

  answerState.surveyId = survey.id;

  const questions = getVisibleQuestions(survey);

  answerState.visibleQuestionIds =
    questions.map(q=>q.id);

  app.innerHTML = `
    <div class="answer-card">
      <h1 style="font-size:24px">
        ${escapeHtml(survey.title)}
      </h1>

      ${
        survey.description
        ? `<p style="color:#64748b">
            ${escapeHtml(survey.description)}
           </p>`
        : ''
      }
    </div>

    <form id="answerForm">

      ${questions.map(q=>renderAnswerQuestion(q)).join('')}

      <div class="answer-card">
        <div class="answer-navigation">
          <span></span>

          <button
            type="button"
            class="btn btn-primary"
            onclick="validateAndConfirm()">
            回答を確認する
          </button>
        </div>
      </div>

    </form>
  `;
}

function getVisibleQuestions(survey){
  const questions = flattenQuestions(survey);

  if(!questions.length){
    return [];
  }

  /*
   * Mock branching implementation.
   * A question is hidden when an earlier single-choice
   * answer specifies a different next question.
   */
  const visible = [];

  let branchTarget = null;

  for(const q of questions){

    if(branchTarget){
      if(q.id !== branchTarget){
        continue;
      }

      branchTarget = null;
    }

    visible.push(q);

    if(q.type==='single'){
      const value = answerState.values[q.id];

      if(value && q.branches && q.branches){
        const option =
          q.options.find(o=>o.text===value);

        if(option && q.branches[option.id]){
          branchTarget = q.branches[option.id];
        }
      }
    }
  }

  return visible;
}

function renderAnswerQuestion(q){
  const value = answerState.values[q.id];

  return `
    <div class="answer-card">

      <h2>
        ${escapeHtml(q.number)}
        ${escapeHtml(q.text)}
        ${q.required
          ? '<span class="required">必須</span>'
          : ''}
      </h2>

      ${
        q.type==='single'
        ? q.options.map(option=>`
            <label class="answer-label">
              <input
                type="radio"
                name="${q.id}"
                value="${escapeHtml(option.text)}"
                ${value===option.text?'checked':''}
                onchange="answerState.values['${q.id}']=this.value"
              >
              ${escapeHtml(option.text)}
            </label>
          `).join('')
        : ''
      }

      ${
        q.type==='multiple'
        ? q.options.map(option=>{
            const checked =
              Array.isArray(value) &&
              value.includes(option.text);

            return `
              <label class="answer-label">
                <input
                  type="checkbox"
                  value="${escapeHtml(option.text)}"
                  ${checked?'checked':''}
                  onchange="
                    updateMultipleAnswer(
                      '${q.id}',
                      '${escapeHtml(option.text)}',
                      this.checked
                    )
                  "
                >
                ${escapeHtml(option.text)}
              </label>
            `;
          }).join('')
        : ''
      }

      ${
        q.type==='text'
        ? `
          <textarea
            style="min-height:150px"
            oninput="
              answerState.values['${q.id}']=this.value
            "
            placeholder="回答を入力してください"
          >${escapeHtml(value || '')}</textarea>
        `
        : ''
      }

    </div>
  `;
}

function updateMultipleAnswer(questionId,value,checked){
  if(!Array.isArray(answerState.values[questionId])){
    answerState.values[questionId] = [];
  }

  if(checked){
    if(!answerState.values[questionId].includes(value)){
      answerState.values[questionId].push(value);
    }
  }else{
    answerState.values[questionId] =
      answerState.values[questionId].filter(v=>v!==value);
  }
}

function validateAndConfirm(){
  const survey = getSurvey(answerState.surveyId);

  if(!survey) return;

  const questions = getVisibleQuestions(survey);

  for(const q of questions){
    if(!q.required) continue;

    const value = answerState.values[q.id];

    const empty =
      value === undefined ||
      value === null ||
      value === '' ||
      (Array.isArray(value) && value.length===0);

    if(empty){
      toast('必須項目に回答してください。','error');

      const target =
        document.querySelector(
          `[name="${q.id}"]`
        );

      if(target){
        target.scrollIntoView({
          behavior:'smooth',
          block:'center'
        });
      }

      return;
    }
  }

  go('confirm',survey.id);
}

/* ============================================================
   Confirm
============================================================ */

function renderConfirm(){
  const survey = getSurvey(INITIAL_ID);
  const app = document.getElementById('respondentApp');

  if(!survey){
    go('answer',INITIAL_ID);
    return;
  }

  const questions = getVisibleQuestions(survey);

  app.innerHTML = `
    <div class="answer-card">
      <h1>回答確認</h1>
      <p style="color:#64748b">
        入力内容をご確認ください。
      </p>
    </div>

    ${questions.map(q=>{
      const value = answerState.values[q.id];

      return `
        <div class="answer-card">
          <h2>
            ${escapeHtml(q.number)}
            ${escapeHtml(q.text)}
          </h2>

          <div style="
            white-space:pre-wrap;
            background:#f8fafc;
            padding:14px;
            border-radius:8px">
            ${escapeHtml(
              Array.isArray(value)
                ? value.join('、')
                : value || '未回答'
            )}
          </div>
        </div>
      `;
    }).join('')}

    <div class="answer-card">
      <div class="answer-navigation">
        <button
          class="btn"
          onclick="go('answer','${survey.id}')">
          回答を修正する
        </button>

        <button
          class="btn btn-primary"
          onclick="submitAnswer('${survey.id}')">
          回答を送信する
        </button>
      </div>
    </div>
  `;
}

function submitAnswer(surveyId){
  confirmAction(
    '回答を送信します。よろしいですか？',
    ()=>{
      const survey = getSurvey(surveyId);

      if(!survey) return;

      data.responses.push({
        id:uid('response'),
        surveyId,
        customerId:null,
        registered:false,
        createdAt:nowISO(),
        answers:deepClone(answerState.values)
      });

      saveData();

      go('complete',surveyId);
    },
    '送信する'
  );
}

/* ============================================================
   Complete
============================================================ */

function renderComplete(){
  const survey = getSurvey(INITIAL_ID);
  const app = document.getElementById('respondentApp');

  app.innerHTML = `
    <div class="answer-card complete">
      <div style="
        width:70px;
        height:70px;
        margin:0 auto 20px;
        border-radius:50%;
        background:#dcfce7;
        color:#16a34a;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:35px">
        ✓
      </div>

      <h1>回答ありがとうございました</h1>

      <p style="color:#64748b">
        アンケートの回答を受け付けました。
      </p>

      <p style="font-size:12px;color:#94a3b8">
        ${
          survey
          ? escapeHtml(survey.title)
          : ''
        }
      </p>
    </div>
  `;
}

/* ============================================================
   Reset answer when entering a new answer URL
============================================================ */

if(
  SCREEN === 'answer' &&
  answerState.surveyId !== INITIAL_ID
){
  answerState = {
    surveyId:INITIAL_ID,
    values:{},
    visibleQuestionIds:[],
    page:0
  };
}

/* ============================================================
   Rendering
============================================================ */

function render(){
  setActiveNavigation();

  if(
    SCREEN==='answer' ||
    SCREEN==='confirm' ||
    SCREEN==='complete'
  ){
    if(SCREEN==='answer'){
      renderRespondent();
    }else if(SCREEN==='confirm'){
      renderConfirm();
    }else{
      renderComplete();
    }

    return;
  }

  switch(SCREEN){
    case 'list':
      renderList();
      break;

    case 'edit':
      renderEdit();
      break;

    case 'preview':
      renderPreview();
      break;

    case 'send':
      renderSend();
      break;

    case 'analytics':
      renderAnalytics();
      break;

    case 'kintone':
      renderKintone();
      break;

    case 'mail':
      renderMail();
      break;

    default:
      renderList();
  }
}

render();
</script>

</body>
</html>
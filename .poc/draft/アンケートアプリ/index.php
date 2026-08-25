<?php
/*
 * アンケート管理システム インタラクティブモック
 * PHP 8.5 / Apache 2.4
 *
 * 今回の修正:
 * - 質問番号を必ず Q 形式で表示
 * - 全体通番: Q1 / Q2 / Q3 ...
 * - グループ毎: Q1-1 / Q1-2 / Q2-1 ...
 * - 編集画面
 * - プレビュー画面
 * - 回答者画面
 * - 回答確認画面
 * - 集計画面
 * で同一の質問番号を表示
 */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム モック</title>

<style>
:root{
  --primary:#2563eb;
  --primary-dark:#1d4ed8;
  --success:#059669;
  --warning:#d97706;
  --danger:#dc2626;
  --gray:#64748b;
  --light:#f8fafc;
  --line:#e2e8f0;
  --text:#1e293b;
  --card:#fff;
  --shadow:0 4px 18px rgba(15,23,42,.08);
}

*{box-sizing:border-box}

body{
  margin:0;
  background:#f1f5f9;
  color:var(--text);
  font-family:-apple-system,BlinkMacSystemFont,
    "Segoe UI","Noto Sans JP",sans-serif;
}

button,input,textarea,select{
  font:inherit;
}

button{
  cursor:pointer;
}

.hidden{
  display:none!important;
}

.admin-header{
  position:sticky;
  top:0;
  z-index:50;
  background:#0f172a;
  color:#fff;
  display:flex;
  align-items:center;
  gap:8px;
  padding:12px 20px;
  box-shadow:0 2px 8px #0002;
}

.logo{
  font-weight:800;
  margin-right:20px;
  white-space:nowrap;
}

.nav-btn{
  background:transparent;
  color:#cbd5e1;
  border:0;
  padding:9px 12px;
  border-radius:7px;
}

.nav-btn:hover,
.nav-btn.active{
  background:#1e293b;
  color:#fff;
}

.logout{
  margin-left:auto;
}

.container{
  max-width:1440px;
  margin:auto;
  padding:24px;
}

.page-title{
  font-size:25px;
  font-weight:800;
  margin:0 0 20px;
}

.card{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:12px;
  padding:20px;
  box-shadow:var(--shadow);
  margin-bottom:18px;
}

.toolbar{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
  margin-bottom:16px;
}

.toolbar .grow{
  flex:1;
}

input[type=text],
input[type=email],
input[type=password],
input[type=datetime-local],
input[type=number],
textarea,
select{
  width:100%;
  border:1px solid #cbd5e1;
  border-radius:7px;
  padding:9px 11px;
  background:#fff;
  color:var(--text);
}

textarea{
  min-height:110px;
  resize:vertical;
}

label{
  display:block;
  font-weight:700;
  margin-bottom:6px;
}

.form-row{
  margin-bottom:16px;
}

.form-grid{
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:16px;
}

.btn{
  border:1px solid #cbd5e1;
  background:#fff;
  color:#334155;
  border-radius:7px;
  padding:9px 14px;
  font-weight:700;
}

.btn:hover{
  background:#f8fafc;
}

.btn-primary{
  background:var(--primary);
  color:#fff;
  border-color:var(--primary);
}

.btn-primary:hover{
  background:var(--primary-dark);
}

.btn-danger{
  background:#fff;
  color:var(--danger);
  border-color:#fecaca;
}

.btn-success{
  background:var(--success);
  color:#fff;
  border-color:var(--success);
}

.btn-warning{
  background:#fff7ed;
  color:#9a3412;
  border-color:#fed7aa;
}

.btn-sm{
  padding:6px 9px;
  font-size:13px;
}

.actions{
  display:flex;
  gap:6px;
  flex-wrap:wrap;
}

.status{
  display:inline-flex;
  align-items:center;
  padding:4px 9px;
  border-radius:99px;
  font-size:12px;
  font-weight:800;
  white-space:nowrap;
}

.status-draft{
  background:#f1f5f9;
  color:#475569;
}

.status-open{
  background:#dcfce7;
  color:#166534;
}

.status-stop{
  background:#fef3c7;
  color:#92400e;
}

.status-end{
  background:#fee2e2;
  color:#991b1b;
}

table{
  width:100%;
  border-collapse:collapse;
}

th,td{
  border-bottom:1px solid var(--line);
  padding:12px 9px;
  text-align:left;
  vertical-align:middle;
}

th{
  background:#f8fafc;
  font-size:13px;
  white-space:nowrap;
}

td{
  font-size:14px;
}

.table-wrap{
  overflow:auto;
}

.alert{
  padding:12px 14px;
  border-radius:8px;
  margin:10px 0;
  font-weight:600;
}

.alert-success{
  background:#ecfdf5;
  color:#065f46;
  border:1px solid #a7f3d0;
}

.alert-error{
  background:#fef2f2;
  color:#991b1b;
  border:1px solid #fecaca;
}

.alert-info{
  background:#eff6ff;
  color:#1e40af;
  border:1px solid #bfdbfe;
}

.section-title{
  font-size:18px;
  font-weight:800;
  margin:0 0 15px;
}

.top-actions{
  display:flex;
  align-items:center;
  gap:10px;
  margin-bottom:18px;
  padding-bottom:16px;
  border-bottom:1px solid var(--line);
}

.top-actions .state{
  margin-left:auto;
  display:flex;
  align-items:center;
  gap:8px;
  min-width:230px;
}

.question-card,
.group-card{
  background:#fff;
  border:1px solid var(--line);
  border-radius:10px;
  padding:16px;
  margin-bottom:12px;
}

.group-card{
  background:#f8fafc;
}

.group-head{
  display:flex;
  gap:10px;
  align-items:center;
  margin-bottom:12px;
}

.group-head input{
  font-weight:800;
}

.drag-handle{
  cursor:grab;
  color:#94a3b8;
  font-size:20px;
}

.question-head{
  display:flex;
  align-items:center;
  gap:8px;
  margin-bottom:12px;
}

.qno{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:54px;
  padding:5px 9px;
  border-radius:6px;
  background:#eff6ff;
  color:var(--primary);
  font-weight:900;
}

.question-head .spacer{
  flex:1;
}

.question-number{
  display:block;
  color:var(--primary);
  font-size:14px;
  font-weight:900;
  margin-bottom:7px;
}

.question-text{
  font-size:17px;
  font-weight:800;
  margin-bottom:12px;
}

.required{
  color:var(--danger);
  font-size:12px;
  margin-left:5px;
}

.choice-row{
  display:flex;
  gap:7px;
  margin:7px 0;
}

.choice-row input{
  flex:1;
}

.radio-row{
  display:flex;
  gap:20px;
  align-items:center;
  flex-wrap:wrap;
}

.radio-row label{
  font-weight:500;
  margin:0;
}

.answer-choice{
  display:block;
  border:1px solid #cbd5e1;
  padding:13px;
  border-radius:8px;
  margin:8px 0;
  cursor:pointer;
}

.answer-choice:hover{
  background:#f8fafc;
}

.answer-choice input{
  width:auto;
  margin-right:8px;
}

.preview-frame{
  border:1px solid #cbd5e1;
  border-radius:12px;
  background:#e2e8f0;
  padding:20px;
}

.preview-device{
  background:#fff;
  margin:auto;
  min-height:500px;
  padding:25px;
  border-radius:8px;
}

.preview-device.mobile{
  max-width:390px;
}

.preview-device.pc{
  max-width:100%;
}

.answer-progress{
  height:7px;
  background:#e2e8f0;
  border-radius:99px;
  margin-bottom:22px;
}

.answer-progress span{
  display:block;
  height:100%;
  background:var(--primary);
  border-radius:99px;
}

.modal-backdrop{
  position:fixed;
  inset:0;
  background:#0008;
  z-index:100;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:20px;
}

.modal{
  background:#fff;
  border-radius:12px;
  width:min(520px,100%);
  box-shadow:0 20px 60px #0005;
  padding:22px;
}

.modal h3{
  margin:0 0 10px;
}

.modal-actions{
  display:flex;
  justify-content:flex-end;
  gap:8px;
  margin-top:20px;
}

.toast{
  position:fixed;
  right:20px;
  bottom:20px;
  z-index:200;
  background:#0f172a;
  color:#fff;
  padding:13px 18px;
  border-radius:8px;
  box-shadow:var(--shadow);
}

.muted{
  color:var(--gray);
}

.small{
  font-size:12px;
}

.empty{
  padding:45px;
  text-align:center;
  color:#64748b;
}

.bar{
  height:12px;
  background:#e2e8f0;
  border-radius:99px;
  overflow:hidden;
  margin-top:5px;
}

.bar>span{
  display:block;
  height:100%;
  background:var(--primary);
}

.kpi-grid{
  display:grid;
  grid-template-columns:repeat(5,1fr);
  gap:12px;
}

.kpi{
  background:#fff;
  border:1px solid var(--line);
  border-radius:10px;
  padding:15px;
}

.kpi .num{
  font-size:27px;
  font-weight:900;
  margin-top:5px;
}

.kpi .label{
  font-size:12px;
  color:var(--gray);
}

.tabs{
  display:flex;
  border-bottom:1px solid var(--line);
  margin-bottom:18px;
  gap:4px;
}

.tab{
  border:0;
  background:transparent;
  padding:11px 16px;
  font-weight:800;
  color:#64748b;
  border-bottom:2px solid transparent;
}

.tab.active{
  color:var(--primary);
  border-bottom-color:var(--primary);
}

.badge{
  padding:3px 7px;
  border-radius:5px;
  font-size:11px;
  font-weight:800;
}

.badge-ok{
  background:#dcfce7;
  color:#166534;
}

.badge-no{
  background:#fee2e2;
  color:#991b1b;
}

@media(max-width:900px){
  .kpi-grid{
    grid-template-columns:repeat(2,1fr);
  }

  .form-grid{
    grid-template-columns:1fr;
  }

  .admin-header{
    overflow:auto;
  }

  .logo{
    margin-right:5px;
  }

  .container{
    padding:14px;
  }
}

@media(max-width:600px){
  .top-actions{
    align-items:stretch;
    flex-wrap:wrap;
  }

  .top-actions .state{
    margin-left:0;
    width:100%;
  }

  .toolbar{
    align-items:stretch;
  }

  .toolbar>*{
    width:100%;
  }
}
</style>
</head>

<body>

<div id="app"></div>
<div id="modalRoot"></div>
<div id="toastRoot"></div>

<script>
"use strict";

/* =========================================================
   状態
========================================================= */

const state = {
  page:"list",
  selectedSurveyId:null,

  editId:null,
  editDraft:null,

  previewMode:"pc",

  answerAnswers:{},

  admin:true,

  listSearch:"",
  listStatus:"all",
  sortKey:"updated_desc",

  sendTab:"customers",
  selectedCustomers:[],
  sendResult:null,

  kintone:{
    subdomain:"",
    appId:"",
    login:"",
    password:"",
    ssl:true,
    connection:"未設定"
  },

  mail:{
    smtp:"",
    port:"587",
    encryption:"TLS",
    auth:true,
    username:"",
    password:"",
    from:"",
    fromName:"",
    replyTo:"",
    status:"未設定"
  }
};

/* =========================================================
   サンプルデータ
========================================================= */

const surveys = [
  {
    id:1,
    title:"2026年度 顧客満足度アンケート",
    description:"サービスに関する満足度をお聞かせください。",
    start:"2026-04-01T09:00",
    end:"2026-12-31T23:59",
    status:"公開中",
    created:"2026/03/10",
    updated:"2026/08/24",
    responses:128,
    allowReanswer:false,

    /* global = Q1,Q2,Q3... */
    /* group  = Q1-1,Q1-2,Q2-1... */
    numbering:"global",

    groups:[
      {
        id:11,
        title:"基本評価",
        questions:[
          {
            id:101,
            text:"サービス全体の満足度を教えてください。",
            type:"single",
            required:true,
            choices:[
              "とても満足",
              "満足",
              "普通",
              "不満",
              "とても不満"
            ],
            branch:{}
          },
          {
            id:102,
            text:"特に良かった点を教えてください。",
            type:"multi",
            required:false,
            choices:[
              "品質",
              "価格",
              "サポート",
              "使いやすさ"
            ],
            branch:{}
          }
        ]
      },
      {
        id:12,
        title:"ご意見",
        questions:[
          {
            id:103,
            text:"ご意見・ご要望があればご記入ください。",
            type:"text",
            required:false,
            choices:[],
            branch:{}
          }
        ]
      }
    ]
  },

  {
    id:2,
    title:"2026年度 サービス改善アンケート",
    description:"今後のサービス改善に向けたアンケートです。",
    start:"2026-07-01T09:00",
    end:"2026-08-10T23:59",
    status:"下書き",
    created:"2026/07/01",
    updated:"2026/08/20",
    responses:0,
    allowReanswer:false,
    numbering:"global",

    groups:[
      {
        id:21,
        title:"サービスについて",
        questions:[
          {
            id:201,
            text:"現在のサービスについて教えてください。",
            type:"single",
            required:true,
            choices:["満足","普通","不満"],
            branch:{}
          }
        ]
      }
    ]
  },

  {
    id:3,
    title:"2026年 上期フォローアップ",
    description:"上期のお客様フォローアップです。",
    start:"2026-05-01T09:00",
    end:"2026-08-10T23:59",
    status:"停止",
    created:"2026/04/20",
    updated:"2026/08/18",
    responses:54,
    allowReanswer:false,
    numbering:"group",

    groups:[
      {
        id:31,
        title:"第1グループ",
        questions:[
          {
            id:301,
            text:"担当者の対応はいかがでしたか？",
            type:"single",
            required:true,
            choices:["非常に良い","良い","普通","悪い"],
            branch:{}
          },
          {
            id:302,
            text:"改善点があれば教えてください。",
            type:"text",
            required:false,
            choices:[],
            branch:{}
          }
        ]
      },
      {
        id:32,
        title:"第2グループ",
        questions:[
          {
            id:303,
            text:"今後も利用したいですか？",
            type:"single",
            required:true,
            choices:["はい","いいえ"],
            branch:{}
          }
        ]
      }
    ]
  },

  {
    id:4,
    title:"2025年度 顧客満足度アンケート",
    description:"終了済みアンケートのサンプルです。",
    start:"2025-04-01T09:00",
    end:"2025-08-10T23:59",
    status:"終了",
    created:"2025/03/01",
    updated:"2025/08/11",
    responses:210,
    allowReanswer:false,
    numbering:"global",

    groups:[
      {
        id:41,
        title:"評価",
        questions:[
          {
            id:401,
            text:"昨年度のサービスに満足しましたか？",
            type:"single",
            required:true,
            choices:["はい","いいえ"],
            branch:{}
          }
        ]
      }
    ]
  }
];

const customers = [
  {
    id:1,
    org:"株式会社サンプル",
    name:"山田 太郎",
    email:"taro@example.com",
    tel:"03-1111-1111",
    address:"東京都港区",
    sent:"2026/08/20 10:10",
    count:1,
    status:"送信済み / 未回答",
    kintone:true
  },
  {
    id:2,
    org:"株式会社テスト",
    name:"佐藤 花子",
    email:"hanako@example.com",
    tel:"03-2222-2222",
    address:"東京都新宿区",
    sent:"2026/08/21 14:20",
    count:2,
    status:"回答済み",
    kintone:true
  },
  {
    id:3,
    org:"合同会社モック",
    name:"鈴木 一郎",
    email:"ichiro@example.com",
    tel:"03-3333-3333",
    address:"東京都渋谷区",
    sent:"-",
    count:0,
    status:"未送信",
    kintone:false
  },
  {
    id:4,
    org:"株式会社デモ",
    name:"田中 次郎",
    email:"jiro@example.com",
    tel:"03-4444-4444",
    address:"東京都千代田区",
    sent:"2026/08/22 09:30",
    count:1,
    status:"送信済み / 未回答",
    kintone:true
  },
  {
    id:5,
    org:"有限会社サンプル商事",
    name:"高橋 美咲",
    email:"misaki@example.com",
    tel:"03-5555-5555",
    address:"東京都品川区",
    sent:"-",
    count:0,
    status:"未送信",
    kintone:false
  }
];

const defaultMailSubject =
  "アンケートのお願い {顧客名}様";

const defaultMailBody =
`{顧客名}様

いつもお世話になっております。

以下のURLよりアンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力のほど、よろしくお願いいたします。`;


/* =========================================================
   共通
========================================================= */

function esc(v){
  return String(v ?? "").replace(
    /[&<>"']/g,
    m => ({
      "&":"&amp;",
      "<":"&lt;",
      ">":"&gt;",
      '"':"&quot;",
      "'":"&#39;"
    }[m])
  );
}

function clone(v){
  return JSON.parse(JSON.stringify(v));
}

function uid(){
  return Date.now()+Math.floor(Math.random()*10000);
}

function surveyById(id){
  return surveys.find(s => s.id == id);
}

function findGroup(s,qid){
  return s.groups.find(
    g => g.questions.some(q => q.id == qid)
  );
}

function findGroupId(s,qid){
  return findGroup(s,qid)?.id ?? null;
}

function formatDate(v){
  if(!v)return "-";
  return String(v).replace("T"," ");
}

function statusClass(s){
  if(s==="公開中")return "status-open";
  if(s==="下書き")return "status-draft";
  if(s==="停止")return "status-stop";
  return "status-end";
}

function showToast(msg){
  const root=document.getElementById("toastRoot");

  root.innerHTML =
    `<div class="toast">${esc(msg)}</div>`;

  setTimeout(
    () => root.innerHTML="",
    2400
  );
}

function confirmDialog(title,message,callback){

  document.getElementById("modalRoot").innerHTML=`
    <div class="modal-backdrop">
      <div class="modal">
        <h3>${esc(title)}</h3>

        <div>${esc(message)}</div>

        <div class="modal-actions">
          <button
            class="btn"
            onclick="closeModal()">
            キャンセル
          </button>

          <button
            class="btn btn-primary"
            id="modalExec">
            実行
          </button>
        </div>
      </div>
    </div>
  `;

  document.getElementById("modalExec").onclick=()=>{
    closeModal();
    callback();
  };
}

function closeModal(){
  document.getElementById("modalRoot").innerHTML="";
}


/* =========================================================
   ★ 質問番号
========================================================= */

/*
 * 要件上の質問番号は必ず「Q」で始める。
 *
 * global:
 *   Q1
 *   Q2
 *   Q3
 *
 * group:
 *   Q1-1
 *   Q1-2
 *   Q2-1
 *
 * IDではなく画面上の質問順から算出するため、
 * 質問を追加・削除・並び替えしても自動的に更新される。
 */
function getQuestionNumber(s,gid,qid){

  if(!s || !gid || !qid){
    return "";
  }

  if(s.numbering === "group"){

    const groupIndex =
      s.groups.findIndex(
        g => g.id == gid
      );

    const group =
      s.groups.find(
        g => g.id == gid
      );

    if(groupIndex < 0 || !group){
      return "";
    }

    const questionIndex =
      group.questions.findIndex(
        q => q.id == qid
      );

    if(questionIndex < 0){
      return "";
    }

    return `Q${groupIndex + 1}-${questionIndex + 1}`;
  }

  let number = 0;

  for(const group of s.groups){

    for(const question of group.questions){

      number++;

      if(question.id == qid){
        return `Q${number}`;
      }
    }
  }

  return "";
}


/* =========================================================
   描画
========================================================= */

function render(){

  document.getElementById("app").innerHTML =
    state.admin
      ? adminHeader() +
        `<main class="container">${pageHtml()}</main>`
      : pageHtml();
}

function adminHeader(){

  const nav = [
    ["list","アンケート一覧"],
    ["kintone","kintone連携設定"],
    ["mailserver","メールサーバ設定"]
  ];

  return `
    <header class="admin-header">

      <div class="logo">
        📋 アンケート管理
      </div>

      ${nav.map(n => `
        <button
          class="nav-btn ${state.page===n[0]?"active":""}"
          onclick="state.page='${n[0]}';render()">
          ${n[1]}
        </button>
      `).join("")}

      <button
        class="nav-btn logout"
        onclick="showToast('ログアウトしました')">
        ログアウト
      </button>

    </header>
  `;
}

function pageHtml(){

  switch(state.page){

    case "list":
      return listPage();

    case "edit":
      return editPage();

    case "preview":
      return previewPage();

    case "send":
      return sendPage();

    case "summary":
      return summaryPage();

    case "kintone":
      return kintonePage();

    case "mailserver":
      return mailServerPage();

    case "answer":
      return answerPage();

    case "confirm":
      return answerConfirmPage();

    case "complete":
      return completePage();

    default:
      return listPage();
  }
}


/* =========================================================
   一覧
========================================================= */

function listPage(){

  let list = surveys.filter(s => {

    const q =
      state.listSearch.toLowerCase();

    return (
      (!q ||
        s.title.toLowerCase().includes(q))
      &&
      (
        state.listStatus==="all" ||
        s.status===state.listStatus
      )
    );
  });

  list.sort((a,b)=>{

    switch(state.sortKey){

      case "updated_asc":
        return a.updated.localeCompare(b.updated);

      case "responses_desc":
        return b.responses-a.responses;

      case "responses_asc":
        return a.responses-b.responses;

      case "start_desc":
        return b.start.localeCompare(a.start);

      case "start_asc":
        return a.start.localeCompare(b.start);

      default:
        return b.updated.localeCompare(a.updated);
    }
  });

  return `
    <h1 class="page-title">
      アンケート一覧
    </h1>

    <div class="toolbar">

      <button
        class="btn btn-primary"
        onclick="newSurvey()">
        ＋ 新規アンケート作成
      </button>

      <div class="grow"></div>

      <input
        id="searchInput"
        type="text"
        placeholder="タイトルで検索"
        value="${esc(state.listSearch)}"
        style="max-width:280px">

      <select
        id="statusFilter"
        style="max-width:150px">
        <option value="all"
          ${state.listStatus==="all"?"selected":""}>
          すべて
        </option>
        <option value="公開中"
          ${state.listStatus==="公開中"?"selected":""}>
          公開中
        </option>
        <option value="下書き"
          ${state.listStatus==="下書き"?"selected":""}>
          下書き
        </option>
        <option value="停止"
          ${state.listStatus==="停止"?"selected":""}>
          停止
        </option>
        <option value="終了"
          ${state.listStatus==="終了"?"selected":""}>
          終了
        </option>
      </select>

      <select
        id="sortSelect"
        style="max-width:210px">

        <option value="updated_desc"
          ${state.sortKey==="updated_desc"?"selected":""}>
          更新日：新しい順
        </option>

        <option value="updated_asc"
          ${state.sortKey==="updated_asc"?"selected":""}>
          更新日：古い順
        </option>

        <option value="responses_desc"
          ${state.sortKey==="responses_desc"?"selected":""}>
          回答数：多い順
        </option>

        <option value="responses_asc"
          ${state.sortKey==="responses_asc"?"selected":""}>
          回答数：少ない順
        </option>

      </select>
    </div>

    <div class="card">
      <div class="table-wrap">

        <table>

          <thead>
            <tr>
              <th>作成日 / 更新日</th>
              <th>タイトル</th>
              <th>アンケート期間</th>
              <th>ステータス</th>
              <th>回答数</th>
              <th>操作</th>
            </tr>
          </thead>

          <tbody>

            ${
              list.length
              ? list.map(surveyRow).join("")
              : `
                <tr>
                  <td
                    colspan="6"
                    class="empty">
                    該当するアンケートがありません
                  </td>
                </tr>
              `
            }

          </tbody>

        </table>

      </div>
    </div>
  `;
}

function surveyRow(s){

  return `
    <tr>

      <td>
        ${esc(s.created)}
        <br>
        <span class="muted small">
          ${esc(s.updated)}
        </span>
      </td>

      <td>
        <strong>${esc(s.title)}</strong>
      </td>

      <td>
        ${formatDate(s.start)}
        <br>
        ～ ${formatDate(s.end)}
      </td>

      <td>
        <span class="status ${statusClass(s.status)}">
          ${esc(s.status)}
        </span>
      </td>

      <td>
        ${s.responses}
      </td>

      <td>
        <div class="actions">

          <button
            class="btn btn-sm"
            onclick="editSurvey(${s.id})">
            確認・編集
          </button>

          <button
            class="btn btn-sm"
            onclick="openPreview(${s.id})">
            プレビュー
          </button>

          <button
            class="btn btn-sm"
            onclick="openSummary(${s.id})">
            集計
          </button>

          <button
            class="btn btn-sm"
            onclick="openSend(${s.id})">
            送信
          </button>

          <button
            class="btn btn-sm"
            onclick="duplicateSurvey(${s.id})">
            複製
          </button>

          <button
            class="btn btn-sm btn-danger"
            onclick="deleteSurvey(${s.id})">
            削除
          </button>

        </div>
      </td>

    </tr>
  `;
}


/* =========================================================
   CRUD
========================================================= */

function newSurvey(){

  state.editId=null;

  state.editDraft={
    id:null,
    title:"",
    description:"",
    start:"",
    end:"",
    status:"下書き",
    created:"",
    updated:"",
    responses:0,
    allowReanswer:false,
    numbering:"global",
    groups:[]
  };

  addGroup();

  state.page="edit";

  render();
}

function editSurvey(id){

  const s=surveyById(id);

  if(!s)return;

  state.editId=id;
  state.editDraft=clone(s);
  state.page="edit";

  render();
}

function duplicateSurvey(id){

  const s=surveyById(id);

  if(!s)return;

  const d=clone(s);

  d.id=uid();
  d.status="下書き";
  d.responses=0;

  d.created=
    new Date().toLocaleDateString("ja-JP");

  d.updated=d.created;

  surveys.push(d);

  showToast("アンケートを複製しました");

  render();
}

function deleteSurvey(id){

  const s=surveyById(id);

  if(!s)return;

  confirmDialog(
    "アンケートを削除",
    `「${s.title}」を削除しますか？`,
    ()=>{
      const index =
        surveys.findIndex(x=>x.id===id);

      if(index>=0){
        surveys.splice(index,1);
      }

      showToast("削除しました");

      render();
    }
  );
}


/* =========================================================
   編集画面
========================================================= */

function editPage(){

  const s=state.editDraft;

  return `
    <h1 class="page-title">
      アンケート作成・編集
    </h1>

    <div class="card">

      <div class="top-actions">

        <button
          class="btn"
          onclick="cancelEdit()">
          キャンセル
        </button>

        <button
          class="btn btn-primary"
          onclick="saveSurvey()">
          保存して一覧へ
        </button>

        <div class="state">

          <strong>状態：</strong>

          <select id="editStatus">

            <option
              value="下書き"
              ${s.status==="下書き"?"selected":""}>
              下書き
            </option>

            <option
              value="公開中"
              ${s.status==="公開中"?"selected":""}>
              公開中
            </option>

            <option
              value="停止"
              ${s.status==="停止"?"selected":""}>
              停止
            </option>

          </select>

        </div>

      </div>

      <div class="section-title">
        基本情報
      </div>

      <div class="form-row">
        <label>アンケートタイトル</label>
        <input
          id="editTitle"
          value="${esc(s.title)}">
      </div>

      <div class="form-row">
        <label>アンケート説明</label>
        <textarea
          id="editDescription">${esc(s.description)}</textarea>
      </div>

      <div class="form-grid">

        <div class="form-row">
          <label>開始日時</label>
          <input
            id="editStart"
            type="datetime-local"
            value="${esc(s.start)}">
        </div>

        <div class="form-row">
          <label>終了日時</label>
          <input
            id="editEnd"
            type="datetime-local"
            value="${esc(s.end)}">
        </div>

      </div>

      <div class="form-row">

        <label>
          質問番号の採番方式
        </label>

        <div class="radio-row">

          <label>
            <input
              type="radio"
              name="numbering"
              value="global"
              ${s.numbering==="global"?"checked":""}
              onchange="changeNumbering('global')">
            アンケート全体で通番
          </label>

          <label>
            <input
              type="radio"
              name="numbering"
              value="group"
              ${s.numbering==="group"?"checked":""}
              onchange="changeNumbering('group')">
            グループ毎に採番
          </label>

        </div>

      </div>

      <div class="alert alert-info">
        質問番号は
        <strong>Q1 / Q2 / Q3...</strong>
        または
        <strong>Q1-1 / Q1-2 / Q2-1...</strong>
        の形式で表示されます。
      </div>

    </div>


    <div class="card">

      <div class="section-title">
        グループ・質問
      </div>

      <div>
        ${s.groups.map(groupHtml).join("")}
      </div>

      <button
        class="btn btn-primary"
        onclick="addGroup()">
        ＋ グループを追加
      </button>

    </div>


    <div class="card">

      <label>
        <input
          type="checkbox"
          ${s.allowReanswer?"checked":""}
          onchange="state.editDraft.allowReanswer=this.checked">
        個別回答URLの再回答を許可する
      </label>

    </div>


    <div class="actions">

      <button
        class="btn"
        onclick="cancelEdit()">
        キャンセル
      </button>

      <button
        class="btn btn-primary"
        onclick="saveSurvey()">
        保存して一覧へ
      </button>

      <button
        class="btn"
        onclick="openPreviewFromEdit()">
        プレビュー
      </button>

    </div>
  `;
}

function groupHtml(g){

  return `
    <div class="group-card">

      <div class="group-head">

        <span class="drag-handle">
          ☷
        </span>

        <input
          value="${esc(g.title)}"
          onchange="
            updateGroup(
              ${g.id},
              'title',
              this.value
            )
          ">

        <button
          class="btn btn-sm btn-danger"
          onclick="deleteGroup(${g.id})">
          削除
        </button>

      </div>

      ${
        g.questions.length
        ? g.questions
            .map((q,qi)=>questionHtml(g,q))
            .join("")
        : `
          <div class="empty">
            質問がありません
          </div>
        `
      }

      <button
        class="btn btn-sm btn-primary"
        onclick="addQuestion(${g.id})">
        ＋ 質問を追加
      </button>

    </div>
  `;
}

function questionHtml(g,q){

  const number =
    getQuestionNumber(
      state.editDraft,
      g.id,
      q.id
    );

  return `
    <div class="question-card">

      <div class="question-head">

        <span class="drag-handle">
          ⋮⋮
        </span>

        <!-- ★ 質問番号を Q 付きで表示 -->
        <span class="qno">
          ${esc(number)}
        </span>

        <span class="spacer"></span>

        <button
          class="btn btn-sm btn-danger"
          onclick="
            deleteQuestion(
              ${g.id},
              ${q.id}
            )
          ">
          削除
        </button>

      </div>


      <div class="form-row">

        <label>
          質問文
        </label>

        <textarea
          onchange="
            updateQuestion(
              ${g.id},
              ${q.id},
              'text',
              this.value
            )
          ">${esc(q.text)}</textarea>

      </div>


      <div class="form-grid">

        <div>

          <label>
            回答形式
          </label>

          <select
            onchange="
              changeQuestionType(
                ${g.id},
                ${q.id},
                this.value
              )
            ">

            <option
              value="single"
              ${q.type==="single"?"selected":""}>
              単一選択
            </option>

            <option
              value="multi"
              ${q.type==="multi"?"selected":""}>
              複数選択
            </option>

            <option
              value="text"
              ${q.type==="text"?"selected":""}>
              自由記述
            </option>

          </select>

        </div>

        <div>

          <label>
            <input
              type="checkbox"
              ${q.required?"checked":""}
              onchange="
                updateQuestion(
                  ${g.id},
                  ${q.id},
                  'required',
                  this.checked
                )
              ">
            必須回答
          </label>

        </div>

      </div>


      ${
        q.type!=="text"
        ? `
          <div class="form-row">

            <label>
              選択肢
            </label>

            ${q.choices.map((choice,i)=>`

              <div class="choice-row">

                <input
                  value="${esc(choice)}"
                  onchange="
                    updateChoice(
                      ${g.id},
                      ${q.id},
                      ${i},
                      this.value
                    )
                  ">

                <button
                  class="btn btn-sm btn-danger"
                  onclick="
                    removeChoice(
                      ${g.id},
                      ${q.id},
                      ${i}
                    )
                  ">
                  削除
                </button>

              </div>

            `).join("")}

            <button
              class="btn btn-sm"
              onclick="
                addChoice(
                  ${g.id},
                  ${q.id}
                )
              ">
              ＋ 選択肢追加
            </button>

          </div>
        `
        : ""
      }

    </div>
  `;
}


/* =========================================================
   編集操作
========================================================= */

function syncBasic(){

  const s=state.editDraft;

  s.title =
    document.getElementById("editTitle")?.value || "";

  s.description =
    document.getElementById("editDescription")?.value || "";

  s.start =
    document.getElementById("editStart")?.value || "";

  s.end =
    document.getElementById("editEnd")?.value || "";

  s.status =
    document.getElementById("editStatus")?.value ||
    s.status;
}

function saveSurvey(){

  syncBasic();

  if(!state.editDraft.title.trim()){
    showToast("タイトルを入力してください");
    return;
  }

  const d=state.editDraft;

  d.updated =
    new Date().toLocaleDateString("ja-JP");

  if(!d.id){

    d.id=uid();

    d.created=d.updated;

    d.status="下書き";

    surveys.push(clone(d));

  }else{

    const i =
      surveys.findIndex(
        x=>x.id===d.id
      );

    if(i>=0){
      surveys[i]=clone(d);
    }
  }

  showToast("保存しました");

  state.page="list";
  state.editDraft=null;

  render();
}

function cancelEdit(){

  confirmDialog(
    "変更を破棄",
    "編集内容を破棄して一覧へ戻りますか？",
    ()=>{
      state.page="list";
      state.editDraft=null;
      render();
    }
  );
}

function changeNumbering(v){

  state.editDraft.numbering=v;

  /*
   * ★ 採番方式変更時に即座に再描画。
   * Q1 → Q1-1 のように表示が変わる。
   */
  render();
}

function addGroup(){

  state.editDraft.groups.push({
    id:uid(),
    title:
      `グループ${state.editDraft.groups.length+1}`,
    questions:[]
  });

  render();
}

function deleteGroup(id){

  state.editDraft.groups =
    state.editDraft.groups.filter(
      g=>g.id!==id
    );

  render();
}

function updateGroup(id,key,value){

  const g =
    state.editDraft.groups.find(
      x=>x.id===id
    );

  if(g){
    g[key]=value;
  }
}

function addQuestion(groupId){

  const g =
    state.editDraft.groups.find(
      x=>x.id===groupId
    );

  if(!g)return;

  g.questions.push({
    id:uid(),
    text:"",
    type:"single",
    required:false,
    choices:[
      "選択肢1",
      "選択肢2"
    ],
    branch:{}
  });

  /*
   * 質問追加後に再描画するため、
   * Q番号も即時更新される。
   */
  render();
}

function deleteQuestion(gid,qid){

  state.editDraft.groups.forEach(g=>{
    if(g.id===gid){
      g.questions =
        g.questions.filter(
          q=>q.id!==qid
        );
    }
  });

  render();
}

function findQuestion(gid,qid){

  const g =
    state.editDraft.groups.find(
      x=>x.id===gid
    );

  return g?.questions.find(
    x=>x.id===qid
  );
}

function updateQuestion(
  gid,
  qid,
  key,
  value
){

  const q=findQuestion(gid,qid);

  if(q){
    q[key]=value;
  }
}

function addChoice(gid,qid){

  const q=findQuestion(gid,qid);

  if(q){
    q.choices.push(
      `選択肢${q.choices.length+1}`
    );
  }

  render();
}

function removeChoice(gid,qid,index){

  const q=findQuestion(gid,qid);

  if(q){
    q.choices.splice(index,1);
  }

  render();
}

function updateChoice(
  gid,
  qid,
  index,
  value
){

  const q=findQuestion(gid,qid);

  if(q){
    q.choices[index]=value;
  }
}

function changeQuestionType(
  gid,
  qid,
  type
){

  const q=findQuestion(gid,qid);

  if(!q)return;

  q.type=type;

  if(type==="text"){
    q.choices=[];
  }else if(!q.choices.length){
    q.choices=[
      "選択肢1",
      "選択肢2"
    ];
  }

  render();
}


/* =========================================================
   プレビュー
========================================================= */

function openPreview(id){

  const s=surveyById(id);

  if(!s)return;

  state.editDraft=clone(s);
  state.editId=id;
  state.page="preview";

  render();
}

function openPreviewFromEdit(){

  syncBasic();

  state.page="preview";

  render();
}

function previewPage(){

  const s=state.editDraft;

  return `
    <h1 class="page-title">
      プレビュー
    </h1>

    <div class="toolbar">

      <button
        class="btn ${state.previewMode==="pc"?"btn-primary":""}"
        onclick="
          state.previewMode='pc';
          render()
        ">
        PC表示
      </button>

      <button
        class="btn ${state.previewMode==="mobile"?"btn-primary":""}"
        onclick="
          state.previewMode='mobile';
          render()
        ">
        スマートフォン表示
      </button>

      <button
        class="btn"
        onclick="
          state.page='edit';
          render()
        ">
        編集へ戻る
      </button>

    </div>

    <div class="preview-frame">

      <div
        class="preview-device ${state.previewMode}">

        ${answerSurveyHtml(s,true)}

      </div>

    </div>
  `;
}


/* =========================================================
   回答者画面
========================================================= */

function startAnswer(id){

  const s=surveyById(id);

  if(!s)return;

  state.admin=false;

  state.selectedSurveyId=id;

  state.answerAnswers={};

  state.page="answer";

  render();
}

function answerSurveyHtml(
  s,
  preview=false
){

  return `
    <div>

      <h1>
        ${esc(s.title)}
      </h1>

      <p class="muted">
        ${esc(s.description)}
      </p>

      <div class="alert alert-info">
        アンケート期間：
        ${formatDate(s.start)}
        ～
        ${formatDate(s.end)}
      </div>

      ${s.groups.map(g=>`

        <div style="margin-top:28px">

          <h2>
            ${esc(g.title)}
          </h2>

          ${g.questions
            .map(q=>answerQuestionHtml(s,g,q))
            .join("")}

        </div>

      `).join("")}

      <div
        class="actions"
        style="margin-top:25px">

        <button
          class="btn btn-primary"
          onclick="
            ${
              preview
              ? "showToast('プレビュー送信は実行されません')"
              : "goAnswerConfirm()"
            }
          ">
          次へ
        </button>

      </div>

    </div>
  `;
}

function answerQuestionHtml(
  s,
  g,
  q
){

  /*
   * ★ ここが今回の重要修正。
   *
   * 回答者画面でも必ず
   * Q1 / Q2 / Q1-1 ...
   * を表示する。
   */
  const number =
    getQuestionNumber(
      s,
      g.id,
      q.id
    );

  return `
    <div
      class="question-card"
      data-question-id="${q.id}">

      <div class="question-number">
        ${esc(number)}
      </div>

      <div class="question-text">
        ${esc(q.text)}

        ${
          q.required
          ? `<span class="required">必須</span>`
          : ""
        }
      </div>


      ${
        q.type==="text"
        ? `
          <textarea
            placeholder="回答を入力してください"
            onchange="
              state.answerAnswers[${q.id}]
              =this.value
            "></textarea>
        `
        : q.choices.map((choice,i)=>`

          <label class="answer-choice">

            <input
              type="${q.type==="multi"?"checkbox":"radio"}"
              name="q_${q.id}"
              value="${esc(choice)}"
              onchange="
                setAnswer(
                  ${q.id},
                  this.value,
                  this.checked,
                  '${q.type}'
                )
              ">

            ${esc(choice)}

          </label>

        `).join("")
      }

    </div>
  `;
}

function setAnswer(
  qid,
  value,
  checked,
  type
){

  if(type==="multi"){

    if(!Array.isArray(
      state.answerAnswers[qid]
    )){
      state.answerAnswers[qid]=[];
    }

    if(checked){

      if(
        !state.answerAnswers[qid]
          .includes(value)
      ){
        state.answerAnswers[qid].push(value);
      }

    }else{

      state.answerAnswers[qid] =
        state.answerAnswers[qid]
          .filter(v=>v!==value);
    }

  }else{

    state.answerAnswers[qid]=value;
  }
}

function answerPage(){

  const s=
    surveyById(
      state.selectedSurveyId
    );

  if(!s){

    state.admin=true;
    state.page="list";

    return "";
  }

  return `
    <div
      style="
        max-width:850px;
        margin:40px auto;
        padding:20px;
      ">

      <div class="card">

        ${answerSurveyHtml(s,false)}

      </div>

    </div>
  `;
}

function goAnswerConfirm(){

  state.page="confirm";

  render();
}

function answerConfirmPage(){

  const s=
    surveyById(
      state.selectedSurveyId
    );

  if(!s)return "";

  return `
    <div
      style="
        max-width:850px;
        margin:40px auto;
      ">

      <h1 class="page-title">
        回答内容の確認
      </h1>

      <div class="card">

        ${s.groups.map(g=>`

          <div style="margin-bottom:30px">

            <h2>
              ${esc(g.title)}
            </h2>

            ${g.questions.map(q=>{

              const number =
                getQuestionNumber(
                  s,
                  g.id,
                  q.id
                );

              let answer =
                state.answerAnswers[q.id];

              if(Array.isArray(answer)){
                answer=answer.join("、");
              }

              if(!answer){
                answer="未回答";
              }

              return `
                <div
                  class="question-card">

                  <div class="question-number">
                    ${esc(number)}
                  </div>

                  <div class="question-text">
                    ${esc(q.text)}
                  </div>

                  <div>
                    ${esc(answer)}
                  </div>

                </div>
              `;

            }).join("")}

          </div>

        `).join("")}

      </div>

      <div class="actions">

        <button
          class="btn"
          onclick="
            state.page='answer';
            render()
          ">
          戻る
        </button>

        <button
          class="btn btn-primary"
          onclick="submitAnswer()">
          回答を送信
        </button>

      </div>

    </div>
  `;
}

function submitAnswer(){

  state.page="complete";

  render();
}

function completePage(){

  return `
    <div
      style="
        max-width:650px;
        margin:90px auto;
        text-align:center;
      ">

      <div class="card">

        <div style="font-size:55px">
          ✓
        </div>

        <h1>
          回答ありがとうございました
        </h1>

        <p class="muted">
          アンケートへの回答を受け付けました。
        </p>

      </div>

    </div>
  `;
}


/* =========================================================
   顧客送信
========================================================= */

function openSend(id){

  state.selectedSurveyId=id;
  state.sendTab="customers";
  state.selectedCustomers=[];
  state.page="send";

  render();
}

function sendPage(){

  const s=
    surveyById(
      state.selectedSurveyId
    );

  if(!s){
    state.page="list";
    return "";
  }

  return `
    <h1 class="page-title">
      顧客選択・メール送信
    </h1>

    <div class="card">

      <div class="section-title">
        送信対象アンケート
      </div>

      <strong style="font-size:20px">
        ${esc(s.title)}
      </strong>

    </div>

    <div class="tabs">

      <button
        class="tab ${state.sendTab==="customers"?"active":""}"
        onclick="
          state.sendTab='customers';
          render()
        ">
        顧客選択・送信
      </button>

      <button
        class="tab ${state.sendTab==="history"?"active":""}"
        onclick="
          state.sendTab='history';
          render()
        ">
        送信履歴
      </button>

    </div>

    ${
      state.sendTab==="customers"
      ? sendCustomersHtml(s)
      : historyHtml()
    }
  `;
}

function sendCustomersHtml(s){

  return `
    <div class="card">

      <div class="table-wrap">

        <table>

          <thead>
            <tr>
              <th>選択</th>
              <th>組織名</th>
              <th>氏名</th>
              <th>メール</th>
              <th>電話</th>
              <th>回答ステータス</th>
            </tr>
          </thead>

          <tbody>

            ${customers.map(c=>`

              <tr>

                <td>
                  <input
                    type="checkbox"
                    ${state.selectedCustomers.includes(c.id)?"checked":""}
                    onchange="
                      toggleCustomer(
                        ${c.id},
                        this.checked
                      )
                    ">
                </td>

                <td>${esc(c.org)}</td>
                <td>${esc(c.name)}</td>
                <td>${esc(c.email)}</td>
                <td>${esc(c.tel)}</td>
                <td>${esc(c.status)}</td>

              </tr>

            `).join("")}

          </tbody>

        </table>

      </div>

    </div>


    <div class="card">

      <div class="section-title">
        メールテンプレート
      </div>

      <div class="form-row">

        <label>
          メール件名
        </label>

        <input
          value="${esc(defaultMailSubject)}">

      </div>

      <div class="form-row">

        <label>
          メール本文
        </label>

        <textarea
          style="min-height:180px">${esc(defaultMailBody)}</textarea>

      </div>

      <div class="alert alert-info">
        動的変数：
        {顧客名}
        {アンケートURL}
      </div>

      <button
        class="btn btn-primary"
        onclick="sendSelected()">
        一括送信
      </button>

    </div>


    <div class="card">

      <div class="section-title">
        回答者向け画面テスト
      </div>

      <button
        class="btn"
        onclick="
          startAnswer(${s.id})
        ">
        このアンケートを回答者として開く
      </button>

    </div>
  `;
}

function toggleCustomer(id,checked){

  if(
    checked &&
    !state.selectedCustomers.includes(id)
  ){
    state.selectedCustomers.push(id);
  }

  if(!checked){

    state.selectedCustomers =
      state.selectedCustomers.filter(
        x=>x!==id
      );
  }
}

function sendSelected(){

  if(!state.selectedCustomers.length){

    showToast(
      "送信対象を選択してください"
    );

    return;
  }

  showToast(
    `${state.selectedCustomers.length}件に送信しました`
  );
}


/* =========================================================
   送信履歴
========================================================= */

function historyHtml(){

  return `
    <div class="card">

      <div class="section-title">
        送信履歴
      </div>

      <table>

        <thead>
          <tr>
            <th>送信日時</th>
            <th>対象件数</th>
            <th>成功</th>
            <th>失敗</th>
          </tr>
        </thead>

        <tbody>

          <tr>
            <td>2026/08/22 10:00</td>
            <td>5</td>
            <td>5</td>
            <td>0</td>
          </tr>

        </tbody>

      </table>

    </div>
  `;
}


/* =========================================================
   集計
========================================================= */

function openSummary(id){

  state.selectedSurveyId=id;
  state.page="summary";

  render();
}

function summaryPage(){

  const s=
    surveyById(
      state.selectedSurveyId
    );

  if(!s){
    state.page="list";
    return "";
  }

  const sent=customers.length;
  const answered=s.responses;
  const unanswered=
    Math.max(sent-answered,0);

  const rate=
    sent
    ? Math.min(
        100,
        Math.round(
          answered/sent*100
        )
      )
    : 0;

  return `
    <h1 class="page-title">
      回答集計・分析
    </h1>

    <div class="card">

      <div class="section-title">
        集計対象アンケート
      </div>

      <strong style="font-size:20px">
        ${esc(s.title)}
      </strong>

    </div>


    <div class="kpi-grid">

      <div class="kpi">
        <div class="label">
          送信対象者数
        </div>
        <div class="num">
          ${sent}
        </div>
      </div>

      <div class="kpi">
        <div class="label">
          回答数
        </div>
        <div class="num">
          ${answered}
        </div>
      </div>

      <div class="kpi">
        <div class="label">
          未回答数
        </div>
        <div class="num">
          ${unanswered}
        </div>
      </div>

      <div class="kpi">
        <div class="label">
          回答率
        </div>
        <div class="num">
          ${rate}%
        </div>
      </div>

    </div>


    ${s.groups.map(g=>`

      <div class="card">

        <div class="section-title">
          ${esc(g.title)}
        </div>

        ${g.questions.map(q=>{

          const number =
            getQuestionNumber(
              s,
              g.id,
              q.id
            );

          return `
            <div
              class="question-card">

              <div class="question-number">
                ${esc(number)}
              </div>

              <div class="question-text">
                ${esc(q.text)}
              </div>

              ${
                q.type==="text"
                ? `
                  <p class="muted">
                    自由記述回答一覧
                  </p>

                  <ul>
                    <li>
                      サービスが使いやすかったです。
                    </li>
                    <li>
                      サポート対応が良かったです。
                    </li>
                  </ul>
                `
                : q.choices.map(
                    (choice,i)=>{

                      const n=
                        Math.max(
                          0,
                          Math.round(
                            Math.max(
                              1,
                              answered
                            ) *
                            (
                              .42 -
                              i*.07
                            )
                          )
                        );

                      const pct=
                        Math.round(
                          n /
                          Math.max(
                            1,
                            answered
                          ) *
                          100
                        );

                      return `
                        <div
                          style="margin:12px 0">

                          <div>
                            <strong>
                              ${esc(choice)}
                            </strong>
                            ${n}件
                            (${pct}%)
                          </div>

                          <div class="bar">
                            <span
                              style="
                                width:${pct}%
                              ">
                            </span>
                          </div>

                        </div>
                      `;
                    }
                  ).join("")
              }

            </div>
          `;
        }).join("")}

      </div>

    `).join("")}


    <div class="card">

      <div class="section-title">
        個別回答
      </div>

      <table>

        <thead>
          <tr>
            <th>組織名</th>
            <th>氏名</th>
            <th>回答日時</th>
            <th>回答概要</th>
          </tr>
        </thead>

        <tbody>

          ${customers
            .filter(
              c=>c.status==="回答済み"
            )
            .map(c=>`

              <tr>

                <td>${esc(c.org)}</td>

                <td>${esc(c.name)}</td>

                <td>
                  2026/08/23 11:30
                </td>

                <td>
                  回答済み
                </td>

              </tr>

            `).join("")}

        </tbody>

      </table>

    </div>


    <div class="actions">

      <button
        class="btn btn-primary"
        onclick="
          showToast('CSV出力操作を実行しました')
        ">
        CSVダウンロード
      </button>

      <button
        class="btn"
        onclick="
          showToast('PDF出力操作を実行しました')
        ">
        PDF出力
      </button>

    </div>
  `;
}


/* =========================================================
   kintone設定
========================================================= */

function kintonePage(){

  return `
    <h1 class="page-title">
      kintone連携設定
    </h1>

    <div class="card">

      <div class="section-title">
        接続設定
      </div>

      <div class="form-grid">

        <div class="form-row">
          <label>
            サブドメイン
          </label>
          <input
            placeholder="example">
        </div>

        <div class="form-row">
          <label>
            アプリID
          </label>
          <input
            type="number"
            placeholder="123">
        </div>

        <div class="form-row">
          <label>
            ログイン名
          </label>
          <input>
        </div>

        <div class="form-row">
          <label>
            パスワード
          </label>
          <input
            type="password">
        </div>

      </div>

      <button
        class="btn btn-primary"
        onclick="
          showToast('接続テスト成功')
        ">
        接続テスト
      </button>

    </div>


    <div class="card">

      <div class="section-title">
        顧客情報マッピング
      </div>

      <table>

        <thead>
          <tr>
            <th>顧客項目</th>
            <th>kintoneフィールド</th>
          </tr>
        </thead>

        <tbody>

          <tr>
            <td>組織名</td>
            <td><input placeholder="company"></td>
          </tr>

          <tr>
            <td>氏名</td>
            <td><input placeholder="name"></td>
          </tr>

          <tr>
            <td>メール</td>
            <td><input placeholder="email"></td>
          </tr>

          <tr>
            <td>電話</td>
            <td><input placeholder="tel"></td>
          </tr>

        </tbody>

      </table>

      <br>

      <button
        class="btn btn-primary"
        onclick="
          showToast('kintone設定を保存しました')
        ">
        保存
      </button>

    </div>
  `;
}


/* =========================================================
   メールサーバ設定
========================================================= */

function mailServerPage(){

  return `
    <h1 class="page-title">
      メールサーバ設定
    </h1>

    <div class="card">

      <div class="section-title">
        SMTP設定
      </div>

      <div class="form-grid">

        <div class="form-row">
          <label>
            SMTPサーバ
          </label>
          <input
            placeholder="smtp.example.com">
        </div>

        <div class="form-row">
          <label>
            ポート
          </label>
          <input
            type="number"
            value="587">
        </div>

        <div class="form-row">
          <label>
            暗号化
          </label>

          <select>
            <option>TLS</option>
            <option>SSL</option>
            <option>なし</option>
          </select>
        </div>

        <div class="form-row">
          <label>
            ユーザー名
          </label>
          <input>
        </div>

        <div class="form-row">
          <label>
            パスワード
          </label>
          <input
            type="password">
        </div>

        <div class="form-row">
          <label>
            送信元メールアドレス
          </label>
          <input
            type="email">
        </div>

        <div class="form-row">
          <label>
            送信元名
          </label>
          <input>
        </div>

        <div class="form-row">
          <label>
            Reply-To
          </label>
          <input
            type="email">
        </div>

      </div>

      <div class="actions">

        <button
          class="btn"
          onclick="
            showToast('SMTP接続テスト成功')
          ">
          接続テスト
        </button>

        <button
          class="btn btn-primary"
          onclick="
            showToast('メールサーバ設定を保存しました')
          ">
          保存
        </button>

      </div>

    </div>
  `;
}


/* =========================================================
   イベント
========================================================= */

document.addEventListener(
  "keydown",
  e => {

    if(
      e.key==="Enter" &&
      document.activeElement?.id==="searchInput"
    ){

      state.listSearch =
        document.activeElement.value;

      render();
    }
  }
);

document.addEventListener(
  "change",
  e => {

    if(e.target.id==="statusFilter"){

      state.listStatus =
        e.target.value;

      render();
    }

    if(e.target.id==="sortSelect"){

      state.sortKey =
        e.target.value;

      render();
    }

    if(
      e.target.id==="editStatus" &&
      state.editDraft
    ){

      state.editDraft.status =
        e.target.value;

      render();
    }
  }
);


/* =========================================================
   初期表示
========================================================= */

render();

</script>

</body>
</html>
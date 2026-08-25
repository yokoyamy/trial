<?php
/**
 * アンケート管理システム モック
 *
 * PHP / Apache 上でそのまま表示できる
 * DB・kintone API・SMTP・認証は未接続のモック
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
  font-family:
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    "Noto Sans JP",
    sans-serif;
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
  color:var(--danger);
  border-color:#fecaca;
}

.btn-success{
  background:var(--success);
  color:#fff;
  border-color:var(--success);
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

.question-card{
  background:#fff;
  border:1px solid var(--line);
  border-radius:10px;
  padding:16px;
  margin-bottom:12px;
}

.group-card{
  background:#f8fafc;
  border:1px solid var(--line);
  border-radius:10px;
  padding:16px;
  margin-bottom:15px;
}

.group-head,
.question-head{
  display:flex;
  gap:10px;
  align-items:center;
  margin-bottom:12px;
}

.question-head .qno{
  font-weight:900;
  color:var(--primary);
  min-width:55px;
}

.question-head .spacer{
  flex:1;
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

.bar{
  height:12px;
  background:#e2e8f0;
  border-radius:99px;
  overflow:hidden;
  margin-top:5px;
}

.bar span{
  display:block;
  height:100%;
  background:var(--primary);
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

.setting-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:20px;
}

.checkbox-list{
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:8px;
}

.checkbox-list label{
  font-weight:500;
}

@media(max-width:900px){
  .kpi-grid{
    grid-template-columns:repeat(2,1fr);
  }

  .form-grid,
  .setting-grid{
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
   サンプルデータ
========================================================= */

const state = {
  page:"list",
  selectedSurveyId:null,
  editId:null,
  editDraft:null,
  answerAnswers:{},
  previewMode:"pc",
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
    connection:"未設定",
    fields:[],
    mapped:{
      org:"",
      name:"",
      email:"",
      dept:"",
      tel:"",
      address:[]
    },
    synced:false
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
  },

  sendHistory:[]
};

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
            choices:["とても満足","満足","普通","不満","とても不満"]
          },
          {
            id:102,
            text:"特に良かった点を教えてください。",
            type:"multi",
            required:false,
            choices:["品質","価格","サポート","使いやすさ"]
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
            choices:[]
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
            choices:["満足","普通","不満"]
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
            choices:["非常に良い","良い","普通","悪い"]
          },
          {
            id:302,
            text:"改善点があれば教えてください。",
            type:"text",
            required:false,
            choices:[]
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
            choices:["はい","いいえ"]
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
            choices:["はい","いいえ"]
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

/*
 * 元ファイルに混入していた
 * 「」を除去。
 */
const defaultMailSubject =
  "アンケートのお願い {顧客名}様";

const defaultMailBody = `{顧客名}様

いつもお世話になっております。

以下のURLよりアンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力のほど、よろしくお願いいたします。`;


/* =========================================================
   共通関数
========================================================= */

function esc(value){
  return String(value ?? "").replace(/[&<>"']/g,function(m){
    return {
      "&":"&amp;",
      "<":"&lt;",
      ">":"&gt;",
      '"':"&quot;",
      "'":"&#39;"
    }[m];
  });
}

function clone(value){
  return JSON.parse(JSON.stringify(value));
}

function uid(){
  return Date.now() + Math.floor(Math.random()*10000);
}

function surveyById(id){
  return surveys.find(function(s){
    return Number(s.id) === Number(id);
  });
}

function formatDate(value){
  if(!value) return "-";
  return String(value).replace("T"," ");
}

function statusClass(status){
  if(status === "公開中") return "status-open";
  if(status === "下書き") return "status-draft";
  if(status === "停止") return "status-stop";
  return "status-end";
}

function showToast(message){
  const root = document.getElementById("toastRoot");

  root.innerHTML =
    '<div class="toast">' + esc(message) + '</div>';

  setTimeout(function(){
    root.innerHTML = "";
  },2400);
}

function confirmDialog(title,message,callback){
  const root = document.getElementById("modalRoot");

  root.innerHTML = `
    <div class="modal-backdrop">
      <div class="modal">
        <h3>${esc(title)}</h3>
        <div>${esc(message)}</div>

        <div class="modal-actions">
          <button class="btn" onclick="closeModal()">
            キャンセル
          </button>

          <button class="btn btn-primary" id="modalExec">
            実行
          </button>
        </div>
      </div>
    </div>
  `;

  document.getElementById("modalExec").onclick = function(){
    closeModal();
    callback();
  };
}

function closeModal(){
  document.getElementById("modalRoot").innerHTML = "";
}

function applyAutomaticEnd(){
  const now = new Date();

  surveys.forEach(function(s){
    if(s.status === "公開中" && s.end){
      const end = new Date(s.end);

      if(!isNaN(end.getTime()) && now > end){
        s.status = "終了";
      }
    }
  });
}


/* =========================================================
   レンダリング
========================================================= */

function render(){
  try{
    applyAutomaticEnd();

    const app = document.getElementById("app");

    if(!app){
      throw new Error("#app が見つかりません");
    }

    app.innerHTML =
      (state.admin ? adminHeader() : "") +
      (state.admin
        ? '<main class="container">' + pageHtml() + '</main>'
        : pageHtml());

    bindGlobal();

  }catch(error){

    console.error(error);

    document.getElementById("app").innerHTML = `
      <main class="container">
        <div class="card">
          <h1>画面の表示中にエラーが発生しました</h1>

          <div class="alert alert-error">
            ${esc(error.message)}
          </div>

          <p>
            ブラウザの開発者ツールの Console に詳細が出力されています。
          </p>

          <button class="btn btn-primary"
                  onclick="location.reload()">
            再読み込み
          </button>
        </div>
      </main>
    `;
  }
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

      ${nav.map(function(n){
        return `
          <button
            class="nav-btn ${state.page === n[0] ? "active" : ""}"
            data-page="${n[0]}">
            ${n[1]}
          </button>
        `;
      }).join("")}

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

function bindGlobal(){

  document.querySelectorAll("[data-page]").forEach(function(button){

    button.onclick = function(){

      state.page = button.dataset.page;

      state.selectedSurveyId = null;

      render();
    };
  });
}


/* =========================================================
   一覧
========================================================= */

function listPage(){

  let list = surveys.filter(function(s){

    const keyword =
      state.listSearch.trim().toLowerCase();

    const matchKeyword =
      !keyword ||
      s.title.toLowerCase().includes(keyword);

    const matchStatus =
      state.listStatus === "all" ||
      s.status === state.listStatus;

    return matchKeyword && matchStatus;
  });

  list.sort(function(a,b){

    switch(state.sortKey){

      case "updated_asc":
        return a.updated.localeCompare(b.updated);

      case "responses_desc":
        return b.responses - a.responses;

      case "responses_asc":
        return a.responses - b.responses;

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
        placeholder="タイトルで検索（Enter）"
        value="${esc(state.listSearch)}"
        style="max-width:280px">

      <select
        id="statusFilter"
        style="max-width:150px">

        ${filterOption("all","すべて")}
        ${filterOption("公開中","公開中")}
        ${filterOption("下書き","下書き")}
        ${filterOption("停止","停止")}
        ${filterOption("終了","終了")}

      </select>

      <select
        id="sortSelect"
        style="max-width:210px">

        ${sortOption("updated_desc","更新日：新しい順")}
        ${sortOption("updated_asc","更新日：古い順")}
        ${sortOption("responses_desc","回答数：多い順")}
        ${sortOption("responses_asc","回答数：少ない順")}
        ${sortOption("start_desc","開始日：新しい順")}
        ${sortOption("start_asc","開始日：古い順")}

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
                    <td colspan="6" class="empty">
                      該当するアンケートがありません
                    </td>
                  </tr>
                `
            }

          </tbody>

        </table>

      </div>

    </div>

    <div class="card">

      <div class="section-title">
        モックについて
      </div>

      <p class="muted">
        この画面はDB・kintone・SMTPへ接続しない
        フロントエンドモックです。
      </p>

      <p class="muted">
        ブラウザを再読み込みするとサンプル状態に戻ります。
      </p>

    </div>
  `;
}

function filterOption(value,text){
  return `
    <option value="${value}"
      ${state.listStatus === value ? "selected" : ""}>
      ${text}
    </option>
  `;
}

function sortOption(value,text){
  return `
    <option value="${value}"
      ${state.sortKey === value ? "selected" : ""}>
      ${text}
    </option>
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

          ${
            s.status === "公開中"
              ? `
                <button
                  class="btn btn-sm"
                  onclick="startAnswer(${s.id})">
                  回答画面
                </button>
              `
              : ""
          }

        </div>

      </td>

    </tr>
  `;
}


/* =========================================================
   一覧イベント
========================================================= */

document.addEventListener("keydown",function(e){

  if(
    e.key === "Enter" &&
    document.activeElement &&
    document.activeElement.id === "searchInput"
  ){
    state.listSearch =
      document.activeElement.value;

    render();
  }
});

document.addEventListener("change",function(e){

  if(e.target.id === "statusFilter"){
    state.listStatus = e.target.value;
    render();
  }

  if(e.target.id === "sortSelect"){
    state.sortKey = e.target.value;
    render();
  }

});


/* =========================================================
   CRUD
========================================================= */

function newSurvey(){

  state.editId = null;

  state.editDraft = {
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

  addGroup(false);
}

function editSurvey(id){

  const survey = surveyById(id);

  if(!survey) return;

  state.editId = id;
  state.editDraft = clone(survey);
  state.page = "edit";

  render();
}

function duplicateSurvey(id){

  const survey = surveyById(id);

  if(!survey) return;

  confirmDialog(
    "アンケートを複製",
    "このアンケートを下書きとして複製しますか？",
    function(){

      const copy = clone(survey);

      copy.id = uid();
      copy.status = "下書き";
      copy.responses = 0;

      const today =
        new Date().toLocaleDateString("ja-JP");

      copy.created = today;
      copy.updated = today;

      surveys.push(copy);

      showToast("アンケートを複製しました");

      render();
    }
  );
}

function deleteSurvey(id){

  const survey = surveyById(id);

  if(!survey) return;

  confirmDialog(
    "アンケートを削除",
    "「" + survey.title + "」を削除しますか？",
    function(){

      const index =
        surveys.findIndex(function(x){
          return x.id === id;
        });

      if(index >= 0){
        surveys.splice(index,1);
      }

      showToast("削除しました");

      render();
    }
  );
}


/* =========================================================
   編集
========================================================= */

function editPage(){

  const s = state.editDraft;

  if(!s){
    state.page = "list";
    return listPage();
  }

  const ended = s.status === "終了";

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

          <select
            id="editStatus"
            ${ended ? "disabled" : ""}>

            ${editableStatuses(s.status)}

          </select>

        </div>

      </div>

      ${
        ended
          ? `
            <div class="alert alert-info">
              このアンケートは終了しています。
              状態変更はできません。
            </div>
          `
          : ""
      }

      <div class="section-title">
        基本情報
      </div>

      <div class="form-row">

        <label>
          アンケートタイトル
        </label>

        <input
          id="editTitle"
          value="${esc(s.title)}">

      </div>

      <div class="form-row">

        <label>
          アンケート説明
        </label>

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
              ${s.numbering === "global" ? "checked" : ""}
              onchange="changeNumbering('global')">
            アンケート全体で通番
          </label>

          <label>
            <input
              type="radio"
              name="numbering"
              value="group"
              ${s.numbering === "group" ? "checked" : ""}
              onchange="changeNumbering('group')">
            グループ毎に採番
          </label>

        </div>

      </div>

    </div>

    <div class="card">

      <div class="section-title">
        グループ・質問
      </div>

      <div>
        ${
          s.groups
            .map(function(g,i){
              return groupHtml(g,i);
            })
            .join("")
        }
      </div>

      <button
        class="btn btn-primary"
        onclick="addGroup()">
        ＋ グループを追加
      </button>

    </div>

    <div class="card">

      <label
        style="display:flex;gap:8px;align-items:center;font-weight:600">

        <input
          type="checkbox"
          ${s.allowReanswer ? "checked" : ""}
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
        class="btn"
        onclick="openPreviewFromEdit()">
        プレビュー
      </button>

      <button
        class="btn btn-primary"
        onclick="saveSurvey()">
        保存して一覧へ
      </button>

    </div>
  `;
}

function editableStatuses(current){

  if(current === "終了"){
    return '<option value="終了">終了</option>';
  }

  let options = [];

  if(current === "下書き"){
    options = [
      ["下書き","下書き"],
      ["公開中","公開"]
    ];
  }

  if(current === "公開中"){
    options = [
      ["公開中","公開中"],
      ["停止","停止"]
    ];
  }

  if(current === "停止"){
    options = [
      ["停止","停止"],
      ["公開中","再開"]
    ];
  }

  return options.map(function(x){
    return `
      <option
        value="${x[0]}"
        ${current === x[0] ? "selected" : ""}>
        ${x[1]}
      </option>
    `;
  }).join("");
}

function syncBasic(){

  const s = state.editDraft;

  const title =
    document.getElementById("editTitle");

  const description =
    document.getElementById("editDescription");

  const start =
    document.getElementById("editStart");

  const end =
    document.getElementById("editEnd");

  if(title) s.title = title.value;
  if(description) s.description = description.value;
  if(start) s.start = start.value;
  if(end) s.end = end.value;

  const status =
    document.getElementById("editStatus");

  if(status && !status.disabled){
    s.status = status.value;
  }
}

function saveSurvey(){

  syncBasic();

  const s = state.editDraft;

  if(!s.title.trim()){
    showToast("タイトルを入力してください");
    return;
  }

  if(!s.groups.length){
    showToast("グループを1つ以上追加してください");
    return;
  }

  const today =
    new Date().toLocaleDateString("ja-JP");

  s.updated = today;

  if(!s.id){

    s.id = uid();
    s.created = today;
    s.status = "下書き";

    surveys.push(clone(s));

  }else{

    const index =
      surveys.findIndex(function(x){
        return x.id === s.id;
      });

    if(index >= 0){
      surveys[index] = clone(s);
    }
  }

  showToast("保存しました");

  state.page = "list";
  state.editDraft = null;

  render();
}

function cancelEdit(){

  confirmDialog(
    "変更を破棄",
    "編集内容を破棄して一覧へ戻りますか？",
    function(){

      state.page = "list";
      state.editDraft = null;

      render();
    }
  );
}

function changeNumbering(value){

  state.editDraft.numbering = value;

  render();
}

function addGroup(){

  if(!state.editDraft) return;

  state.editDraft.groups.push({
    id:uid(),
    title:
      "グループ" +
      (state.editDraft.groups.length + 1),
    questions:[]
  });

  renumber();

  state.page = "edit";

  render();
}

function deleteGroup(id){

  const group =
    state.editDraft.groups.find(function(g){
      return g.id === id;
    });

  if(!group) return;

  confirmDialog(
    "グループを削除",
    group.questions.length
      ? "このグループには質問があります。削除しますか？"
      : "このグループを削除しますか？",
    function(){

      state.editDraft.groups =
        state.editDraft.groups.filter(function(g){
          return g.id !== id;
        });

      renumber();
      render();
    }
  );
}

function updateGroup(id,key,value){

  const group =
    state.editDraft.groups.find(function(g){
      return g.id === id;
    });

  if(group){
    group[key] = value;
  }
}

function addQuestion(groupId){

  const group =
    state.editDraft.groups.find(function(g){
      return g.id === groupId;
    });

  if(!group) return;

  group.questions.push({
    id:uid(),
    text:"",
    type:"single",
    required:false,
    choices:["選択肢1","選択肢2"]
  });

  renumber();

  render();
}

function deleteQuestion(groupId,questionId){

  confirmDialog(
    "質問を削除",
    "この質問を削除しますか？",
    function(){

      const group =
        state.editDraft.groups.find(function(g){
          return g.id === groupId;
        });

      if(!group) return;

      group.questions =
        group.questions.filter(function(q){
          return q.id !== questionId;
        });

      renumber();
      render();
    }
  );
}

function updateQuestion(groupId,questionId,key,value){

  const group =
    state.editDraft.groups.find(function(g){
      return g.id === groupId;
    });

  if(!group) return;

  const question =
    group.questions.find(function(q){
      return q.id === questionId;
    });

  if(question){
    question[key] = value;
  }
}

function addChoice(groupId,questionId){

  const group =
    state.editDraft.groups.find(function(g){
      return g.id === groupId;
    });

  if(!group) return;

  const question =
    group.questions.find(function(q){
      return q.id === questionId;
    });

  if(!question) return;

  question.choices.push(
    "選択肢" + (question.choices.length + 1)
  );

  render();
}

function deleteChoice(groupId,questionId,index){

  const group =
    state.editDraft.groups.find(function(g){
      return g.id === groupId;
    });

  if(!group) return;

  const question =
    group.questions.find(function(q){
      return q.id === questionId;
    });

  if(!question) return;

  question.choices.splice(index,1);

  render();
}

function updateChoice(
  groupId,
  questionId,
  index,
  value
){

  const group =
    state.editDraft.groups.find(function(g){
      return g.id === groupId;
    });

  if(!group) return;

  const question =
    group.questions.find(function(q){
      return q.id === questionId;
    });

  if(question){
    question.choices[index] = value;
  }
}

function groupHtml(group,groupIndex){

  return `
    <div class="group-card">

      <div class="group-head">

        <strong>
          グループ
        </strong>

        <input
          value="${esc(group.title)}"
          oninput="updateGroup(
            ${group.id},
            'title',
            this.value
          )">

        <button
          class="btn btn-sm btn-danger"
          onclick="deleteGroup(${group.id})">
          削除
        </button>

      </div>

      ${
        group.questions.length
          ? group.questions.map(function(q){
              return questionHtml(
                group,
                q
              );
            }).join("")
          : `
            <div class="empty">
              質問がありません
            </div>
          `
      }

      <button
        class="btn"
        onclick="addQuestion(${group.id})">
        ＋ 質問を追加
      </button>

    </div>
  `;
}

function questionHtml(group,q){

  const survey = state.editDraft;

  const number =
    getQuestionNumber(
      survey,
      group.id,
      q.id
    );

  return `
    <div class="question-card">

      <div class="question-head">

        <span class="qno">
          ${number}
        </span>

        <strong>
          質問
        </strong>

        <span class="spacer"></span>

        <button
          class="btn btn-sm btn-danger"
          onclick="deleteQuestion(
            ${group.id},
            ${q.id}
          )">
          削除
        </button>

      </div>

      <div class="form-row">

        <label>
          質問文
        </label>

        <input
          value="${esc(q.text)}"
          oninput="updateQuestion(
            ${group.id},
            ${q.id},
            'text',
            this.value
          )">

      </div>

      <div class="form-grid">

        <div class="form-row">

          <label>
            回答形式
          </label>

          <select
            onchange="updateQuestion(
              ${group.id},
              ${q.id},
              'type',
              this.value
            );render()">

            <option
              value="single"
              ${q.type === "single" ? "selected" : ""}>
              単一選択
            </option>

            <option
              value="multi"
              ${q.type === "multi" ? "selected" : ""}>
              複数選択
            </option>

            <option
              value="text"
              ${q.type === "text" ? "selected" : ""}>
              自由記述
            </option>

          </select>

        </div>

        <div class="form-row">

          <label>
            必須回答
          </label>

          <label style="font-weight:500">

            <input
              type="checkbox"
              ${q.required ? "checked" : ""}
              onchange="updateQuestion(
                ${group.id},
                ${q.id},
                'required',
                this.checked
              )">

            必須にする

          </label>

        </div>

      </div>

      ${
        q.type !== "text"
          ? `
            <div class="form-row">

              <label>
                選択肢
              </label>

              ${q.choices.map(function(choice,index){

                return `
                  <div class="choice-row">

                    <input
                      value="${esc(choice)}"
                      oninput="updateChoice(
                        ${group.id},
                        ${q.id},
                        ${index},
                        this.value
                      )">

                    <button
                      class="btn btn-sm"
                      onclick="deleteChoice(
                        ${group.id},
                        ${q.id},
                        ${index}
                      )">
                      削除
                    </button>

                  </div>
                `;

              }).join("")}

              <button
                class="btn btn-sm"
                onclick="addChoice(
                  ${group.id},
                  ${q.id}
                )">
                ＋ 選択肢追加
              </button>

            </div>
          `
          : ""
      }

    </div>
  `;
}

function renumber(){
  /* 表示時に計算するため実データへの番号保存は不要 */
}

function getQuestionNumber(
  survey,
  groupId,
  questionId
){

  if(!survey) return "";

  let globalNo = 0;

  for(const group of survey.groups){

    let groupNo = 0;

    for(const q of group.questions){

      globalNo++;
      groupNo++;

      if(q.id === questionId){

        return survey.numbering === "group"
          ? groupNo + "."
          : globalNo + ".";
      }
    }
  }

  return "";
}


/* =========================================================
   プレビュー
========================================================= */

function openPreviewFromEdit(){

  syncBasic();

  state.page = "preview";

  render();
}

function previewPage(){

  const s = state.editDraft;

  return `
    <h1 class="page-title">
      プレビュー
    </h1>

    <div class="toolbar">

      <button
        class="btn ${state.previewMode === "pc" ? "btn-primary" : ""}"
        onclick="state.previewMode='pc';render()">
        PC表示
      </button>

      <button
        class="btn ${state.previewMode === "mobile" ? "btn-primary" : ""}"
        onclick="state.previewMode='mobile';render()">
        スマートフォン表示
      </button>

      <button
        class="btn"
        onclick="state.page='edit';render()">
        編集へ戻る
      </button>

    </div>

    <div class="preview-frame">

      <div class="preview-device ${state.previewMode}">

        ${answerSurveyHtml(s,true)}

      </div>

    </div>
  `;
}


/* =========================================================
   回答者画面
========================================================= */

function startAnswer(id){

  const survey = surveyById(id);

  if(!survey) return;

  state.admin = false;
  state.selectedSurveyId = id;
  state.answerAnswers = {};
  state.page = "answer";

  render();
}

function answerPage(){

  const survey =
    surveyById(state.selectedSurveyId);

  if(!survey){

    state.admin = true;
    state.page = "list";

    return listPage();
  }

  return `
    <div style="max-width:900px;margin:40px auto">

      ${answerSurveyHtml(survey,false)}

    </div>
  `;
}

function answerSurveyHtml(survey,preview){

  return `
    <div>

      <h1>
        ${esc(survey.title)}
      </h1>

      <p class="muted">
        ${esc(survey.description)}
      </p>

      <div class="alert alert-info">
        アンケート期間：
        ${formatDate(survey.start)}
        ～
        ${formatDate(survey.end)}
      </div>

      ${survey.groups.map(function(group){

        return `
          <div style="margin-top:28px">

            <h2>
              ${esc(group.title)}
            </h2>

            ${group.questions.map(function(q){
              return answerQuestionHtml(
                survey,
                q,
                group.id
              );
            }).join("")}

          </div>
        `;

      }).join("")}

      <div class="actions"
           style="margin-top:25px">

        <button
          class="btn btn-primary"
          onclick="${
            preview
              ? "showToast('プレビュー送信は実行されません')"
              : "nextAnswer()"
          }">
          次へ
        </button>

      </div>

    </div>
  `;
}

function answerQuestionHtml(
  survey,
  q,
  groupId
){

  const number =
    getQuestionNumber(
      survey,
      groupId,
      q.id
    );

  const value =
    state.answerAnswers[q.id];

  let html = "";

  if(q.type === "single"){

    html = q.choices.map(function(choice){

      return `
        <label class="answer-choice">

          <input
            type="radio"
            name="q${q.id}"
            value="${esc(choice)}"
            ${value === choice ? "checked" : ""}
            onchange="answerValue(
              ${q.id},
              this.value
            )">

          ${esc(choice)}

        </label>
      `;

    }).join("");

  }else if(q.type === "multi"){

    html = q.choices.map(function(choice){

      const checked =
        Array.isArray(value) &&
        value.includes(choice);

      return `
        <label class="answer-choice">

          <input
            type="checkbox"
            value="${esc(choice)}"
            ${checked ? "checked" : ""}
            onchange="answerMulti(
              ${q.id},
              this.value,
              this.checked
            )">

          ${esc(choice)}

        </label>
      `;

    }).join("");

  }else{

    html = `
      <textarea
        onchange="answerValue(
          ${q.id},
          this.value
        )">${esc(value || "")}</textarea>
    `;
  }

  return `
    <div class="form-row"
         style="margin-top:20px">

      <label>

        ${number}
        ${esc(q.text)}

        ${
          q.required
            ? '<span style="color:#dc2626">＊必須</span>'
            : ""
        }

      </label>

      ${html}

    </div>
  `;
}

function answerValue(id,value){
  state.answerAnswers[id] = value;
}

function answerMulti(id,value,checked){

  let values =
    state.answerAnswers[id] || [];

  if(!Array.isArray(values)){
    values = [];
  }

  if(checked && !values.includes(value)){
    values.push(value);
  }

  if(!checked){

    values =
      values.filter(function(v){
        return v !== value;
      });
  }

  state.answerAnswers[id] = values;
}

function nextAnswer(){

  const survey =
    surveyById(state.selectedSurveyId);

  if(!survey) return;

  for(const group of survey.groups){

    for(const q of group.questions){

      if(q.required){

        const value =
          state.answerAnswers[q.id];

        const empty =
          value === undefined ||
          value === "" ||
          (
            Array.isArray(value) &&
            value.length === 0
          );

        if(empty){

          showToast(
            getQuestionNumber(
              survey,
              group.id,
              q.id
            ) + " は必須回答です"
          );

          return;
        }
      }
    }
  }

  state.page = "confirm";

  render();
}

function answerConfirmPage(){

  const survey =
    surveyById(state.selectedSurveyId);

  if(!survey) return "";

  const questions =
    survey.groups.flatMap(function(g){
      return g.questions.map(function(q){
        return {
          q:q,
          groupId:g.id
        };
      });
    });

  return `
    <div style="max-width:800px;margin:40px auto">

      <h1 class="page-title">
        回答確認
      </h1>

      <div class="card">

        ${questions.map(function(item){

          const q = item.q;
          const value =
            state.answerAnswers[q.id];

          const display =
            Array.isArray(value)
              ? value.join("、")
              : value || "未回答";

          return `
            <div style="
              padding:13px 0;
              border-bottom:1px solid var(--line)">

              <strong>
                ${getQuestionNumber(
                  survey,
                  item.groupId,
                  q.id
                )}
                ${esc(q.text)}
              </strong>

              <div style="margin-top:6px">
                ${esc(display)}
              </div>

              <button
                class="btn btn-sm"
                onclick="state.page='answer';render()">
                修正
              </button>

            </div>
          `;

        }).join("")}

      </div>

      <div class="actions">

        <button
          class="btn"
          onclick="state.page='answer';render()">
          戻る
        </button>

        <button
          class="btn btn-primary"
          onclick="submitAnswer()">
          回答を送信する
        </button>

      </div>

    </div>
  `;
}

function submitAnswer(){

  confirmDialog(
    "回答を送信",
    "回答を送信します。よろしいですか？",
    function(){

      const survey =
        surveyById(state.selectedSurveyId);

      if(survey){
        survey.responses++;
      }

      state.page = "complete";

      render();
    }
  );
}

function completePage(){

  return `
    <div style="
      max-width:650px;
      margin:90px auto;
      text-align:center">

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

  state.selectedSurveyId = id;
  state.sendTab = "customers";
  state.sendResult = null;
  state.selectedCustomers = [];
  state.page = "send";

  render();
}

function sendPage(){

  const survey =
    surveyById(state.selectedSurveyId);

  if(!survey){

    state.page = "list";

    return listPage();
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
        ${esc(survey.title)}
      </strong>

    </div>

    <div class="tabs">

      <button
        class="tab ${state.sendTab === "customers" ? "active" : ""}"
        onclick="state.sendTab='customers';render()">
        顧客選択・送信
      </button>

      <button
        class="tab ${state.sendTab === "history" ? "active" : ""}"
        onclick="state.sendTab='history';render()">
        送信履歴
      </button>

    </div>

    ${
      state.sendTab === "customers"
        ? sendCustomersHtml(survey)
        : historyHtml()
    }
  `;
}

function sendCustomersHtml(survey){

  return `
    ${
      state.sendResult
        ? `
          <div class="alert alert-success">

            送信結果：
            対象 ${state.sendResult.total}件 /
            成功 ${state.sendResult.success}件 /
            失敗 ${state.sendResult.fail}件

            <br>

            送信日時：
            ${esc(state.sendResult.date)}

          </div>
        `
        : ""
    }

    <div class="card">

      <div class="toolbar">

        <input
          id="customerSearch"
          placeholder="顧客名・組織名・メールアドレスで検索">

        <select id="customerStatus">

          <option value="">
            すべて
          </option>

          <option>
            未送信
          </option>

          <option>
            送信済み / 未回答
          </option>

          <option>
            回答済み
          </option>

        </select>

      </div>

      <div class="table-wrap">

        <table>

          <thead>
            <tr>

              <th>
                <input
                  type="checkbox"
                  onclick="toggleAllCustomers(this.checked)">
              </th>

              <th>組織名</th>
              <th>氏名</th>
              <th>メール</th>
              <th>電話</th>
              <th>最終送信</th>
              <th>回数</th>
              <th>回答ステータス</th>
              <th>kintone</th>

            </tr>
          </thead>

          <tbody>

            ${customers.map(function(c){

              return `
                <tr>

                  <td>
                    <input
                      type="checkbox"
                      ${state.selectedCustomers.includes(c.id) ? "checked" : ""}
                      onchange="toggleCustomer(
                        ${c.id},
                        this.checked
                      )">
                  </td>

                  <td>${esc(c.org)}</td>
                  <td>${esc(c.name)}</td>
                  <td>${esc(c.email)}</td>
                  <td>${esc(c.tel)}</td>
                  <td>${esc(c.sent)}</td>
                  <td>${c.count}</td>
                  <td>${esc(c.status)}</td>
                  <td>${c.kintone ? "✓" : "-"}</td>

                </tr>
              `;

            }).join("")}

          </tbody>

        </table>

      </div>

    </div>

    <div class="card">

      <div class="section-title">
        メール内容
      </div>

      <div class="form-row">

        <label>
          件名
        </label>

        <input
          id="mailSubject"
          value="${esc(defaultMailSubject)}">

      </div>

      <div class="form-row">

        <label>
          本文
        </label>

        <textarea
          id="mailBody">${esc(defaultMailBody)}</textarea>

      </div>

      <div class="alert alert-info">

        ※ モックのため実際のメールは送信されません。

      </div>

      <button
        class="btn btn-primary"
        onclick="sendMail()">
        選択した顧客へ送信
      </button>

    </div>
  `;
}

function toggleCustomer(id,checked){

  if(checked){

    if(!state.selectedCustomers.includes(id)){
      state.selectedCustomers.push(id);
    }

  }else{

    state.selectedCustomers =
      state.selectedCustomers.filter(function(x){
        return x !== id;
      });
  }
}

function toggleAllCustomers(checked){

  state.selectedCustomers =
    checked
      ? customers.map(function(c){
          return c.id;
        })
      : [];

  render();
}

function sendMail(){

  const total =
    state.selectedCustomers.length;

  if(total === 0){

    showToast(
      "送信対象の顧客を選択してください"
    );

    return;
  }

  const date =
    new Date().toLocaleString("ja-JP");

  state.sendResult = {
    total:total,
    success:total,
    fail:0,
    date:date
  };

  state.sendHistory.unshift({
    date:date,
    count:total
  });

  customers.forEach(function(c){

    if(state.selectedCustomers.includes(c.id)){

      c.sent = date;
      c.count++;
      c.status = "送信済み / 未回答";
    }
  });

  state.selectedCustomers = [];

  showToast(
    total + "件のメール送信を実行しました"
  );

  render();
}

function historyHtml(){

  return `
    <div class="card">

      <div class="section-title">
        送信履歴
      </div>

      ${
        state.sendHistory.length
          ? state.sendHistory.map(function(h){

              return `
                <div style="
                  border:1px solid var(--line);
                  border-radius:8px;
                  padding:13px;
                  margin-bottom:8px">

                  <strong>
                    ${esc(h.date)}
                  </strong>

                  <br>

                  ${h.count}件送信

                </div>
              `;

            }).join("")
          : `
            <div class="empty">
              送信履歴はありません
            </div>
          `
      }

    </div>
  `;
}


/* =========================================================
   集計
========================================================= */

function openSummary(id){

  state.selectedSurveyId = id;
  state.page = "summary";

  render();
}

function summaryPage(){

  const survey =
    surveyById(state.selectedSurveyId);

  if(!survey){

    state.page = "list";

    return listPage();
  }

  const sent = customers.length;
  const answered = survey.responses;
  const unanswered =
    Math.max(sent - answered,0);

  const rate =
    sent
      ? Math.min(
          100,
          Math.round(answered / sent * 100)
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
        ${esc(survey.title)}
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
          未登録顧客からの回答数
        </div>
        <div class="num">
          ${Math.round(answered * .08)}
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

    ${survey.groups.flatMap(function(g){
      return g.questions.map(function(q){
        return aggregateQuestion(
          survey,
          q,
          g.id
        );
      });
    }).join("")}

    <div class="card">

      <div class="section-title">
        個別回答
      </div>

      ${
        survey.responses
          ? `
            <div class="table-wrap">

              <table>

                <thead>
                  <tr>
                    <th>組織名</th>
                    <th>氏名</th>
                    <th>回答日時</th>
                    <th>回答概要</th>
                    <th></th>
                  </tr>
                </thead>

                <tbody>

                  ${customers
                    .filter(function(c){
                      return c.status === "回答済み";
                    })
                    .map(function(c){

                      return `
                        <tr>

                          <td>${esc(c.org)}</td>
                          <td>${esc(c.name)}</td>
                          <td>2026/08/23 11:30</td>
                          <td>回答済み</td>

                          <td>
                            <button
                              class="btn btn-sm"
                              onclick="showToast('全回答を表示しました')">
                              全回答を表示
                            </button>
                          </td>

                        </tr>
                      `;

                    }).join("")}

                </tbody>

              </table>

            </div>
          `
          : `
            <div class="empty">
              現在、回答データはありません
            </div>
          `
      }

    </div>

    <div class="actions">

      <button
        class="btn btn-primary"
        onclick="showToast('CSV出力操作を実行しました')">
        CSVダウンロード
      </button>

      <button
        class="btn"
        onclick="showToast('PDF出力操作を実行しました')">
        PDF出力
      </button>

    </div>
  `;
}

function aggregateQuestion(
  survey,
  q,
  groupId
){

  const number =
    getQuestionNumber(
      survey,
      groupId,
      q.id
    );

  if(q.type === "text"){

    return `
      <div class="card">

        <div class="section-title">
          ${number}
          ${esc(q.text)}
        </div>

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

      </div>
    `;
  }

  const total =
    Math.max(1,survey.responses);

  return `
    <div class="card">

      <div class="section-title">
        ${number}
        ${esc(q.text)}
      </div>

      ${
        q.choices.map(function(choice,index){

          const count =
            Math.max(
              0,
              Math.round(
                total * (.42 - index * .07)
              )
            );

          const percent =
            Math.round(count / total * 100);

          return `
            <div style="margin:12px 0">

              <div>
                <strong>
                  ${esc(choice)}
                </strong>

                ${count}件
                (${percent}%)
              </div>

              <div class="bar">
                <span
                  style="width:${percent}%">
                </span>
              </div>

            </div>
          `;

        }).join("")
      }

    </div>
  `;
}


/* =========================================================
   kintone
========================================================= */

function kintonePage(){

  const k = state.kintone;

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
            id="kSub"
            value="${esc(k.subdomain)}"
            placeholder="example">

        </div>

        <div class="form-row">

          <label>
            顧客管理アプリID
          </label>

          <input
            id="kApp"
            value="${esc(k.appId)}">

        </div>

        <div class="form-row">

          <label>
            ログイン名
          </label>

          <input
            id="kLogin"
            value="${esc(k.login)}">

        </div>

        <div class="form-row">

          <label>
            パスワード
          </label>

          <input
            id="kPass"
            type="password"
            value="${esc(k.password)}">

        </div>

      </div>

      <label style="font-weight:500">

        <input
          id="kSSL"
          type="checkbox"
          ${k.ssl ? "checked" : ""}>

        SSL証明書を検証する

      </label>

      <div class="actions"
           style="margin-top:16px">

        <button
          class="btn"
          onclick="saveKintone()">
          設定を保存
        </button>

        <button
          class="btn btn-primary"
          onclick="testKintone()">
          接続テスト
        </button>

      </div>

      <div style="margin-top:14px">

        接続状態：

        <span class="status ${
          k.connection === "接続成功"
            ? "status-open"
            : k.connection === "接続失敗"
              ? "status-end"
              : "status-draft"
        }">

          ${esc(k.connection)}

        </span>

      </div>

    </div>

    <div class="card">

      <div class="section-title">
        kintone項目
      </div>

      <button
        class="btn"
        onclick="getKintoneFields()">
        項目一覧を再取得
      </button>

      ${
        k.fields.length
          ? `
            <div class="alert alert-success">
              項目一覧を取得しました。
            </div>

            <table>

              <thead>
                <tr>
                  <th>フィールドコード</th>
                  <th>日本語フィールドラベル</th>
                </tr>
              </thead>

              <tbody>

                ${k.fields.map(function(f){

                  return `
                    <tr>
                      <td>${esc(f.code)}</td>
                      <td>${esc(f.label)}</td>
                    </tr>
                  `;

                }).join("")}

              </tbody>

            </table>
          `
          : ""
      }

    </div>

    <div class="card">

      <div class="section-title">
        フィールドマッピング
      </div>

      <div class="form-grid">

        ${mappingSelect("org","組織名")}
        ${mappingSelect("name","氏名")}
        ${mappingSelect("email","メールアドレス")}
        ${mappingSelect("dept","部署名")}
        ${mappingSelect("tel","電話番号")}

      </div>

      <div class="form-row">

        <label>
          住所マッピング
        </label>

        <div class="checkbox-list">

          ${
            [
              "都道府県",
              "市区町村",
              "番地",
              "建物名",
              "郵便番号"
            ].map(function(x){

              return `
                <label>

                  <input
                    type="checkbox"
                    ${
                      k.mapped.address.includes(x)
                        ? "checked"
                        : ""
                    }
                    onchange="toggleAddress(
                      '${x}',
                      this.checked
                    )">

                  ${x}

                </label>
              `;

            }).join("")
          }

        </div>

      </div>

      <button
        class="btn btn-success"
        onclick="syncCustomers()">
        顧客情報を同期
      </button>

      ${
        k.synced
          ? `
            <div class="alert alert-success">
              顧客情報を同期しました。
            </div>
          `
          : ""
      }

    </div>
  `;
}

function mappingSelect(key,label){

  const options = [
    "",
    "org_name",
    "name",
    "email",
    "department",
    "phone"
  ];

  return `
    <div class="form-row">

      <label>
        ${label}
      </label>

      <select
        onchange="
          state.kintone.mapped.${key}=this.value
        ">

        ${options.map(function(value){

          return `
            <option
              value="${value}"
              ${
                state.kintone.mapped[key] === value
                  ? "selected"
                  : ""
              }>

              ${value || "選択してください"}

            </option>
          `;

        }).join("")}

      </select>

    </div>
  `;
}

function saveKintone(){

  const k = state.kintone;

  k.subdomain =
    document.getElementById("kSub").value;

  k.appId =
    document.getElementById("kApp").value;

  k.login =
    document.getElementById("kLogin").value;

  k.password =
    document.getElementById("kPass").value;

  k.ssl =
    document.getElementById("kSSL").checked;

  showToast(
    "kintone設定を保存しました"
  );
}

function testKintone(){

  state.kintone.connection =
    "接続成功";

  showToast(
    "kintoneへの接続テストに成功しました"
  );

  render();
}

function getKintoneFields(){

  state.kintone.fields = [
    {
      code:"org_name",
      label:"組織名"
    },
    {
      code:"name",
      label:"氏名"
    },
    {
      code:"email",
      label:"メールアドレス"
    },
    {
      code:"department",
      label:"部署名"
    },
    {
      code:"phone",
      label:"電話番号"
    },
    {
      code:"address",
      label:"住所"
    }
  ];

  showToast(
    "kintone項目を取得しました"
  );

  render();
}

function toggleAddress(value,checked){

  const address =
    state.kintone.mapped.address;

  if(checked){

    if(!address.includes(value)){
      address.push(value);
    }

  }else{

    const index =
      address.indexOf(value);

    if(index >= 0){
      address.splice(index,1);
    }
  }
}

function syncCustomers(){

  state.kintone.synced = true;

  showToast(
    "顧客情報を同期しました"
  );

  render();
}


/* =========================================================
   メールサーバ設定
========================================================= */

function mailServerPage(){

  const m = state.mail;

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
            id="smtpHost"
            value="${esc(m.smtp)}"
            placeholder="smtp.example.com">

        </div>

        <div class="form-row">

          <label>
            ポート
          </label>

          <input
            id="smtpPort"
            type="number"
            value="${esc(m.port)}">

        </div>

        <div class="form-row">

          <label>
            暗号化
          </label>

          <select id="smtpEncryption">

            <option
              value="TLS"
              ${m.encryption === "TLS" ? "selected" : ""}>
              TLS
            </option>

            <option
              value="SSL"
              ${m.encryption === "SSL" ? "selected" : ""}>
              SSL
            </option>

            <option
              value="なし"
              ${m.encryption === "なし" ? "selected" : ""}>
              なし
            </option>

          </select>

        </div>

        <div class="form-row">

          <label>
            ユーザー名
          </label>

          <input
            id="smtpUser"
            value="${esc(m.username)}">

        </div>

        <div class="form-row">

          <label>
            パスワード
          </label>

          <input
            id="smtpPassword"
            type="password"
            value="${esc(m.password)}">

        </div>

        <div class="form-row">

          <label>
            送信元メールアドレス
          </label>

          <input
            id="smtpFrom"
            type="email"
            value="${esc(m.from)}">

        </div>

        <div class="form-row">

          <label>
            送信元名
          </label>

          <input
            id="smtpFromName"
            value="${esc(m.fromName)}">

        </div>

        <div class="form-row">

          <label>
            Reply-To
          </label>

          <input
            id="smtpReply"
            type="email"
            value="${esc(m.replyTo)}">

        </div>

      </div>

      <div class="actions">

        <button
          class="btn"
          onclick="saveMailServer()">
          設定を保存
        </button>

        <button
          class="btn btn-primary"
          onclick="testMailServer()">
          接続テスト
        </button>

      </div>

      <div style="margin-top:15px">

        設定状態：

        <span class="status ${
          m.status === "接続成功"
            ? "status-open"
            : "status-draft"
        }">

          ${esc(m.status)}

        </span>

      </div>

    </div>
  `;
}

function saveMailServer(){

  const m = state.mail;

  m.smtp =
    document.getElementById("smtpHost").value;

  m.port =
    document.getElementById("smtpPort").value;

  m.encryption =
    document.getElementById(
      "smtpEncryption"
    ).value;

  m.username =
    document.getElementById("smtpUser").value;

  m.password =
    document.getElementById(
      "smtpPassword"
    ).value;

  m.from =
    document.getElementById("smtpFrom").value;

  m.fromName =
    document.getElementById(
      "smtpFromName"
    ).value;

  m.replyTo =
    document.getElementById(
      "smtpReply"
    ).value;

  showToast(
    "メールサーバ設定を保存しました"
  );
}

function testMailServer(){

  state.mail.status =
    "接続成功";

  showToast(
    "SMTP接続テストに成功しました"
  );

  render();
}


/* =========================================================
   起動
========================================================= */

document.addEventListener(
  "DOMContentLoaded",
  function(){

    render();

  }
);

</script>

</body>
</html>
<?php
/*
 * アンケート管理システム モック
 * Apache 2.4 / PHP 8.5
 * 1ファイル完結版
 *
 * 本番用DB / API / 認証 / SMTP / kintone APIは使用しません。
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム モック</title>

<style>
:root{
  --primary:#2563eb;
  --primary-dark:#1d4ed8;
  --success:#16a34a;
  --warning:#d97706;
  --danger:#dc2626;
  --muted:#64748b;
  --bg:#f5f7fb;
  --card:#fff;
  --border:#dbe2ea;
  --text:#172033;
  --shadow:0 4px 16px rgba(15,23,42,.07);
}

*{box-sizing:border-box}
body{
  margin:0;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",
    "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
  background:var(--bg);
  color:var(--text);
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.hidden{display:none!important}

.app-header{
  height:60px;
  background:#172033;
  color:#fff;
  display:flex;
  align-items:center;
  padding:0 24px;
  position:sticky;
  top:0;
  z-index:50;
}
.logo{
  font-weight:700;
  margin-right:32px;
  white-space:nowrap;
}
.nav{
  display:flex;
  gap:4px;
  height:100%;
}
.nav button{
  background:transparent;
  color:#cbd5e1;
  border:0;
  padding:0 15px;
}
.nav button:hover,
.nav button.active{
  color:#fff;
  background:#243149;
}
.logout{
  margin-left:auto;
  color:#cbd5e1!important;
}

main{
  max-width:1440px;
  margin:0 auto;
  padding:28px;
}

.page-title{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:16px;
  margin-bottom:22px;
}
.page-title h1{
  margin:0;
  font-size:26px;
}
.page-title p{
  margin:5px 0 0;
  color:var(--muted);
  font-size:14px;
}

.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:12px;
  box-shadow:var(--shadow);
  padding:20px;
  margin-bottom:20px;
}
.card h2,.card h3{
  margin:0 0 16px;
}
.card-title{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  margin-bottom:16px;
}

.btn{
  border:1px solid var(--border);
  background:#fff;
  color:var(--text);
  border-radius:8px;
  padding:9px 15px;
  font-weight:600;
}
.btn:hover{background:#f8fafc}
.btn.primary{
  background:var(--primary);
  border-color:var(--primary);
  color:#fff;
}
.btn.primary:hover{background:var(--primary-dark)}
.btn.success{
  background:var(--success);
  border-color:var(--success);
  color:#fff;
}
.btn.danger{
  color:#fff;
  background:var(--danger);
  border-color:var(--danger);
}
.btn.warning{
  background:#f59e0b;
  border-color:#f59e0b;
  color:#fff;
}
.btn.small{
  padding:6px 10px;
  font-size:12px;
}
.btn:disabled{
  opacity:.45;
  cursor:not-allowed;
}

.toolbar{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  align-items:center;
}
.search{
  display:flex;
  gap:6px;
}
input,textarea,select{
  border:1px solid #cbd5e1;
  border-radius:8px;
  padding:9px 11px;
  background:#fff;
  color:var(--text);
}
input:focus,textarea:focus,select:focus{
  outline:2px solid rgba(37,99,235,.15);
  border-color:var(--primary);
}
textarea{
  min-height:110px;
  resize:vertical;
}
.field{
  margin-bottom:18px;
}
.field label{
  display:block;
  font-weight:700;
  margin-bottom:7px;
}
.field .hint{
  color:var(--muted);
  font-size:12px;
  margin-top:5px;
}

.grid2{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:18px;
}
.grid3{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:18px;
}

.table-wrap{
  overflow:auto;
}
table{
  width:100%;
  border-collapse:collapse;
  min-width:1050px;
}
th,td{
  border-bottom:1px solid #e5eaf0;
  padding:12px 10px;
  text-align:left;
  vertical-align:middle;
}
th{
  background:#f8fafc;
  color:#475569;
  font-size:13px;
  white-space:nowrap;
}
td{font-size:14px}

.badge{
  display:inline-flex;
  align-items:center;
  border-radius:999px;
  padding:4px 9px;
  font-size:12px;
  font-weight:700;
}
.badge.draft{background:#e2e8f0;color:#475569}
.badge.public{background:#dcfce7;color:#166534}
.badge.stop{background:#fef3c7;color:#92400e}
.badge.end{background:#fee2e2;color:#991b1b}
.badge.ok{background:#dcfce7;color:#166534}
.badge.ng{background:#fee2e2;color:#991b1b}
.badge.info{background:#dbeafe;color:#1e40af}

.action-row{
  display:flex;
  flex-wrap:wrap;
  gap:6px;
}

.editor-actions{
  position:sticky;
  top:60px;
  z-index:30;
  background:#fff;
  border:1px solid var(--border);
  border-radius:12px;
  box-shadow:var(--shadow);
  padding:14px 18px;
  margin-bottom:20px;
  display:flex;
  align-items:center;
  gap:10px;
}
.editor-actions .state-control{
  margin-left:auto;
  display:flex;
  align-items:center;
  gap:8px;
  font-weight:700;
}

.section-title{
  border-left:4px solid var(--primary);
  padding-left:10px;
  margin:0 0 18px;
}

.group{
  border:1px solid var(--border);
  border-radius:12px;
  margin-bottom:18px;
  background:#fbfdff;
}
.group-header{
  display:flex;
  align-items:center;
  gap:10px;
  padding:14px 16px;
  background:#f8fafc;
  border-bottom:1px solid var(--border);
  border-radius:12px 12px 0 0;
}
.drag-handle{
  color:#94a3b8;
  cursor:grab;
}
.group-header input{
  flex:1;
  font-weight:700;
}
.question-list{
  padding:12px;
  min-height:40px;
}
.question{
  background:#fff;
  border:1px solid #dfe6ee;
  border-radius:10px;
  padding:14px;
  margin-bottom:10px;
}
.question:last-child{margin-bottom:0}
.question-top{
  display:flex;
  gap:8px;
  align-items:center;
}
.q-number{
  font-weight:800;
  color:var(--primary);
  min-width:60px;
}
.q-text{
  flex:1;
}
.q-body{
  padding-left:68px;
  margin-top:10px;
}
.options{
  margin-top:10px;
}
.option-row{
  display:flex;
  gap:7px;
  margin-bottom:7px;
}
.option-row input{flex:1}
.radio-row{
  display:flex;
  gap:18px;
  flex-wrap:wrap;
}
.add-row{
  margin-top:12px;
}
.empty{
  text-align:center;
  padding:45px 20px;
  color:var(--muted);
}

.stats{
  display:grid;
  grid-template-columns:repeat(5,1fr);
  gap:12px;
}
.stat{
  background:#fff;
  border:1px solid var(--border);
  border-radius:10px;
  padding:16px;
}
.stat .label{
  color:var(--muted);
  font-size:12px;
}
.stat .value{
  font-size:25px;
  font-weight:800;
  margin-top:5px;
}

.bar{
  height:12px;
  background:#e5e7eb;
  border-radius:10px;
  overflow:hidden;
}
.bar > span{
  display:block;
  height:100%;
  background:var(--primary);
}

.tabs{
  display:flex;
  gap:4px;
  border-bottom:1px solid var(--border);
  margin-bottom:18px;
}
.tabs button{
  border:0;
  background:transparent;
  padding:11px 16px;
  color:var(--muted);
  border-bottom:2px solid transparent;
}
.tabs button.active{
  color:var(--primary);
  border-bottom-color:var(--primary);
  font-weight:700;
}

.mail-preview{
  background:#f8fafc;
  border:1px dashed #cbd5e1;
  padding:16px;
  border-radius:10px;
  white-space:pre-wrap;
}

.modal-backdrop{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.48);
  display:flex;
  align-items:center;
  justify-content:center;
  z-index:100;
  padding:20px;
}
.modal{
  background:#fff;
  width:min(600px,100%);
  border-radius:14px;
  box-shadow:0 20px 50px rgba(0,0,0,.25);
  overflow:hidden;
}
.modal-header{
  padding:17px 20px;
  border-bottom:1px solid var(--border);
  font-weight:800;
}
.modal-body{padding:20px}
.modal-footer{
  display:flex;
  justify-content:flex-end;
  gap:8px;
  padding:14px 20px;
  border-top:1px solid var(--border);
}

.toast{
  position:fixed;
  right:20px;
  bottom:20px;
  background:#172033;
  color:#fff;
  padding:12px 17px;
  border-radius:9px;
  z-index:200;
  box-shadow:var(--shadow);
}

.preview-phone{
  width:390px;
  max-width:100%;
  margin:auto;
  border:8px solid #1e293b;
  border-radius:28px;
  overflow:hidden;
  background:#fff;
}
.preview-desktop{
  border:1px solid var(--border);
  border-radius:12px;
  overflow:hidden;
}
.preview-inner{
  padding:24px;
}
.preview-choice{
  padding:11px;
  border:1px solid #cbd5e1;
  border-radius:8px;
  margin:7px 0;
}

.alert{
  padding:12px 14px;
  border-radius:8px;
  margin-bottom:15px;
}
.alert.info{background:#eff6ff;color:#1e40af}
.alert.success{background:#f0fdf4;color:#166534}
.alert.error{background:#fef2f2;color:#991b1b}
.alert.warning{background:#fffbeb;color:#92400e}

.check-list{
  display:grid;
  gap:8px;
}
.check-list label{
  display:flex;
  align-items:center;
  gap:8px;
  font-weight:400;
}

.mobile-only{display:none}

@media(max-width:900px){
  main{padding:18px}
  .grid2,.grid3,.stats{
    grid-template-columns:1fr;
  }
  .editor-actions{
    position:relative;
    top:auto;
    flex-wrap:wrap;
  }
  .editor-actions .state-control{
    margin-left:0;
    width:100%;
  }
}

@media(max-width:650px){
  .app-header{
    padding:0 12px;
    height:auto;
    min-height:58px;
    flex-wrap:wrap;
  }
  .logo{
    width:100%;
    padding-top:10px;
    margin:0;
  }
  .nav{
    width:100%;
    overflow:auto;
    height:42px;
  }
  .nav button{
    white-space:nowrap;
    padding:0 10px;
  }
  main{padding:12px}
  .page-title{
    align-items:flex-start;
    flex-direction:column;
  }
  .question-top{
    flex-wrap:wrap;
  }
  .q-body{
    padding-left:0;
  }
}
</style>
</head>

<body>

<div id="app"></div>
<div id="modalRoot"></div>
<div id="toastRoot"></div>

<script>
/* =========================================================
   モックデータ
========================================================= */

const now = new Date();

let state = {
  screen: "list",
  previousScreen: "list",
  editingId: null,
  previewMode: "desktop",
  mailTab: "send",
  aggregateId: null,
  answerStep: 0,
  answerData: {},
  answerSubmitted: false,
  search: "",
  statusFilter: "all",
  sort: "updated-desc",
  customerSearch: "",
  selectedCustomers: [],
  sendResult: null,
  kintoneTest: null,
  kintoneFieldsLoaded: false,
  customerSynced: false,
  smtpTest: null
};

let surveys = [
  {
    id:1,
    title:"2026年度 顧客満足度アンケート",
    description:"サービスに関するご意見をお聞かせください。",
    start:"2026-08-01T09:00",
    end:"2026-09-30T23:59",
    status:"public",
    updated:"2026-08-24T14:20",
    created:"2026-07-20",
    responses:128,
    numbering:"global",
    allowResubmit:false,
    groups:[
      {
        id:101,
        title:"サービスについて",
        questions:[
          {
            id:1001,
            text:"サービス全体の満足度を教えてください。",
            type:"single",
            required:true,
            options:["とても満足","満足","普通","不満","とても不満"],
            branch:{}
          },
          {
            id:1002,
            text:"特に評価している点を教えてください。",
            type:"multiple",
            required:false,
            options:["価格","品質","サポート","使いやすさ"],
            branch:{}
          },
          {
            id:1003,
            text:"ご意見・ご要望をご自由にお聞かせください。",
            type:"text",
            required:false,
            options:[],
            branch:{}
          }
        ]
      },
      {
        id:102,
        title:"今後について",
        questions:[
          {
            id:1004,
            text:"今後も利用したいと思いますか？",
            type:"single",
            required:true,
            options:["はい","どちらともいえない","いいえ"],
            branch:{}
          }
        ]
      }
    ]
  },
  {
    id:2,
    title:"新サービス利用意向調査",
    description:"新サービスについてのアンケートです。",
    start:"2026-08-01T00:00",
    end:"2026-08-10T23:59",
    status:"draft",
    updated:"2026-08-23T11:30",
    created:"2026-08-20",
    responses:0,
    numbering:"global",
    allowResubmit:false,
    groups:[
      {
        id:201,
        title:"基本質問",
        questions:[
          {
            id:2001,
            text:"新サービスに興味がありますか？",
            type:"single",
            required:true,
            options:["はい","いいえ"],
            branch:{}
          }
        ]
      }
    ]
  },
  {
    id:3,
    title:"過去終了日時・停止テスト",
    description:"終了日時が過去でも停止なら終了しないことを確認するサンプル。",
    start:"2026-08-01T00:00",
    end:"2026-08-10T23:59",
    status:"stop",
    updated:"2026-08-22T10:00",
    created:"2026-08-21",
    responses:12,
    numbering:"global",
    allowResubmit:false,
    groups:[
      {
        id:301,
        title:"確認",
        questions:[
          {
            id:3001,
            text:"停止状態の確認です。",
            type:"single",
            required:false,
            options:["確認しました"],
            branch:{}
          }
        ]
      }
    ]
  },
  {
    id:4,
    title:"過去終了日時・公開中テスト",
    description:"終了日時が過去の公開中アンケート。",
    start:"2026-08-01T00:00",
    end:"2026-08-10T23:59",
    status:"public",
    updated:"2026-08-21T09:00",
    created:"2026-08-21",
    responses:35,
    numbering:"global",
    allowResubmit:false,
    groups:[
      {
        id:401,
        title:"終了判定確認",
        questions:[
          {
            id:4001,
            text:"このアンケートは自動的に終了します。",
            type:"text",
            required:false,
            options:[],
            branch:{}
          }
        ]
      }
    ]
  }
];

let customers = [
  {id:1,org:"株式会社サンプル",name:"山田 太郎",email:"yamada@example.com",phone:"03-1234-5678",address:"東京都港区",lastSent:"2026-08-20 10:20",count:2,status:"回答済み",kintone:true},
  {id:2,org:"テスト商事株式会社",name:"佐藤 花子",email:"sato@example.com",phone:"03-2345-6789",address:"東京都渋谷区",lastSent:"2026-08-22 14:00",count:1,status:"送信済み / 未回答",kintone:true},
  {id:3,org:"株式会社デモ",name:"鈴木 一郎",email:"suzuki@example.com",phone:"03-3456-7890",address:"東京都新宿区",lastSent:"",count:0,status:"未送信",kintone:false},
  {id:4,org:"未来株式会社",name:"田中 美咲",email:"tanaka@example.com",phone:"03-4567-8901",address:"東京都千代田区",lastSent:"2026-08-18 09:10",count:1,status:"送信済み / 未回答",kintone:false},
  {id:5,org:"株式会社ABC",name:"高橋 健",email:"takahashi@example.com",phone:"03-5678-9012",address:"東京都品川区",lastSent:"2026-08-17 16:40",count:1,status:"回答済み",kintone:true}
];

let sendHistories = [
  {
    id:1,
    date:"2026-08-22 14:00",
    type:"一括送信",
    count:3,
    subject:"【アンケート】2026年度 顧客満足度アンケート",
    operator:"管理者",
    customers:[2,4,5],
    body:"{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}"
  }
];

let settings = {
  kintone:{
    subdomain:"example.cybozu.com",
    appId:"123",
    login:"admin",
    password:"",
    ssl:true,
    mapping:{
      org:"company_name",
      name:"name",
      email:"email",
      dept:"department",
      phone:"tel",
      address:["prefecture","city","address","building","zip"]
    }
  },
  smtp:{
    server:"smtp.example.com",
    port:"587",
    encryption:"TLS",
    auth:true,
    username:"mailer@example.com",
    password:"",
    from:"survey@example.com",
    fromName:"アンケート事務局",
    reply:"support@example.com"
  }
};

/* =========================================================
   共通関数
========================================================= */

function esc(v){
  return String(v ?? "")
    .replaceAll("&","&amp;")
    .replaceAll("<","&lt;")
    .replaceAll(">","&gt;")
    .replaceAll('"',"&quot;")
    .replaceAll("'","&#039;");
}

function uid(){
  return Date.now()+Math.floor(Math.random()*10000);
}

function statusLabel(s){
  return {
    draft:"下書き",
    public:"公開中",
    stop:"停止",
    end:"終了"
  }[s] || s;
}

function statusBadge(s){
  return `<span class="badge ${s}">${statusLabel(s)}</span>`;
}

function toast(message){
  const root=document.getElementById("toastRoot");
  root.innerHTML=`<div class="toast">${esc(message)}</div>`;
  setTimeout(()=>root.innerHTML="",2600);
}

function confirmModal(title,message,onExecute){
  document.getElementById("modalRoot").innerHTML=`
    <div class="modal-backdrop">
      <div class="modal">
        <div class="modal-header">${esc(title)}</div>
        <div class="modal-body">${message}</div>
        <div class="modal-footer">
          <button class="btn" onclick="closeModal()">キャンセル</button>
          <button class="btn primary" id="modalExecute">実行</button>
        </div>
      </div>
    </div>`;
  document.getElementById("modalExecute").onclick=()=>{
    closeModal();
    onExecute();
  };
}

function closeModal(){
  document.getElementById("modalRoot").innerHTML="";
}

function getSurvey(id){
  return surveys.find(s=>s.id==id);
}

function autoEndSurvey(s){
  if(s.status==="public" && s.end){
    const end=new Date(s.end);
    if(new Date()>end){
      s.status="end";
    }
  }
}

function applyAutoEnd(){
  surveys.forEach(autoEndSurvey);
}

function renumber(s){
  let global=1;

  s.groups.forEach((g,gi)=>{
    g.questions.forEach((q,qi)=>{
      q.number=s.numbering==="group"
        ? `Q${gi+1}-${qi+1}`
        : `Q${global++}`;
    });
  });
}

function clone(obj){
  return JSON.parse(JSON.stringify(obj));
}

function escapeForAttr(v){
  return esc(v).replaceAll("\n","&#10;");
}

function render(){
  applyAutoEnd();

  const app=document.getElementById("app");

  if(state.screen.startsWith("answer")){
    app.innerHTML=renderAnswerScreen();
    return;
  }

  app.innerHTML=`
    ${renderHeader()}
    <main>
      ${renderScreen()}
    </main>`;

  bindGlobal();
}

function renderHeader(){
  const active = state.screen==="list" || state.screen==="edit" || state.screen==="preview"
    ? "surveys"
    : state.screen.startsWith("aggregate") ? "aggregate"
    : state.screen.startsWith("mail") ? "mail"
    : state.screen==="kintone" ? "kintone"
    : state.screen==="smtp" ? "smtp" : "";

  return `
  <header class="app-header">
    <div class="logo">アンケート管理システム</div>
    <nav class="nav">
      <button class="${active==="surveys"?"active":""}" onclick="go('list')">アンケート一覧</button>
      <button class="${active==="kintone"?"active":""}" onclick="go('kintone')">kintone連携設定</button>
      <button class="${active==="smtp"?"active":""}" onclick="go('smtp')">メールサーバ設定</button>
      <button class="${active==="aggregate"?"active":""}" onclick="openAggregateFromFirst()">集計</button>
      <button class="${active==="mail"?"active":""}" onclick="openMailFromFirst()">顧客送信</button>
    </nav>
    <button class="nav logout" onclick="toast('ログアウトはモックです')">ログアウト</button>
  </header>`;
}

function renderScreen(){
  switch(state.screen){
    case "list": return renderList();
    case "edit": return renderEdit();
    case "preview": return renderPreview();
    case "mail": return renderMail();
    case "aggregate": return renderAggregate();
    case "kintone": return renderKintone();
    case "smtp": return renderSmtp();
    default: return renderList();
  }
}

/* =========================================================
   アンケート一覧
========================================================= */

function renderList(){
  let list=surveys.filter(s=>{
    const hit=!state.search || s.title.toLowerCase().includes(state.search.toLowerCase());
    const status=state.statusFilter==="all" || s.status===state.statusFilter;
    return hit && status;
  });

  list.sort((a,b)=>{
    if(state.sort==="updated-desc") return b.updated.localeCompare(a.updated);
    if(state.sort==="updated-asc") return a.updated.localeCompare(b.updated);
    if(state.sort==="responses-desc") return b.responses-a.responses;
    if(state.sort==="responses-asc") return a.responses-b.responses;
    if(state.sort==="start-desc") return b.start.localeCompare(a.start);
    return a.start.localeCompare(b.start);
  });

  return `
    <div class="page-title">
      <div>
        <h1>アンケート一覧</h1>
        <p>登録されているアンケートを管理します。</p>
      </div>
      <button class="btn primary" onclick="newSurvey()">＋ 新規アンケート作成</button>
    </div>

    <div class="card">
      <div class="toolbar">
        <div class="search">
          <input id="searchInput" value="${esc(state.search)}"
                 placeholder="タイトルで検索"
                 onkeydown="if(event.key==='Enter')doSearch()">
          <button class="btn" onclick="doSearch()">検索</button>
        </div>

        <select onchange="state.statusFilter=this.value;render()">
          <option value="all" ${state.statusFilter==="all"?"selected":""}>すべて</option>
          <option value="public" ${state.statusFilter==="public"?"selected":""}>公開中</option>
          <option value="draft" ${state.statusFilter==="draft"?"selected":""}>下書き</option>
          <option value="stop" ${state.statusFilter==="stop"?"selected":""}>停止</option>
          <option value="end" ${state.statusFilter==="end"?"selected":""}>終了</option>
        </select>

        <select onchange="state.sort=this.value;render()">
          <option value="updated-desc" ${state.sort==="updated-desc"?"selected":""}>更新日：新しい順</option>
          <option value="updated-asc" ${state.sort==="updated-asc"?"selected":""}>更新日：古い順</option>
          <option value="responses-desc" ${state.sort==="responses-desc"?"selected":""}>回答数：多い順</option>
          <option value="responses-asc" ${state.sort==="responses-asc"?"selected":""}>回答数：少ない順</option>
          <option value="start-desc" ${state.sort==="start-desc"?"selected":""}>開始日：新しい順</option>
          <option value="start-asc" ${state.sort==="start-asc"?"selected":""}>開始日：古い順</option>
        </select>
      </div>
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
          list.length ? list.map(s=>`
            <tr>
              <td>
                ${esc(s.created)}<br>
                <small style="color:#64748b">更新 ${esc(s.updated)}</small>
              </td>
              <td>
                <strong>${esc(s.title)}</strong><br>
                <small style="color:#64748b">${esc(s.description)}</small>
              </td>
              <td>
                ${formatDate(s.start)}<br>
                ～ ${formatDate(s.end)}
              </td>
              <td>${statusBadge(s.status)}</td>
              <td>${s.responses}</td>
              <td>
                <div class="action-row">
                  <button class="btn small" onclick="editSurvey(${s.id})">確認・編集</button>
                  <button class="btn small" onclick="openAggregate(${s.id})">集計</button>
                  <button class="btn small" onclick="openMail(${s.id})">送信</button>
                  <button class="btn small" onclick="duplicateSurvey(${s.id})">複製</button>
                  <button class="btn small danger" onclick="deleteSurvey(${s.id})">削除</button>
                </div>
              </td>
            </tr>
          `).join("") :
          `<tr><td colspan="6" class="empty">該当するアンケートはありません。</td></tr>`
        }
        </tbody>
      </table>
      </div>
    </div>

    <div class="card">
      <h3>終了状態の仕様</h3>
      <div class="alert info">
        <strong>公開中 ＋ 終了日時経過 → 終了</strong><br>
        下書き・停止は終了日時を過ぎても、それぞれの状態を維持します。
      </div>
    </div>
  `;
}

function formatDate(v){
  if(!v)return "-";
  return v.replace("T"," ");
}

function doSearch(){
  state.search=document.getElementById("searchInput").value.trim();
  render();
}

function newSurvey(){
  const s={
    id:uid(),
    title:"",
    description:"",
    start:"",
    end:"",
    status:"draft",
    updated:new Date().toISOString().slice(0,16),
    created:new Date().toISOString().slice(0,10),
    responses:0,
    numbering:"global",
    allowResubmit:false,
    groups:[
      {
        id:uid(),
        title:"グループ1",
        questions:[
          {
            id:uid(),
            text:"",
            type:"single",
            required:true,
            options:["選択肢1","選択肢2"],
            branch:{}
          }
        ]
      }
    ]
  };
  surveys.push(s);
  state.editingId=s.id;
  state.screen="edit";
  render();
}

function editSurvey(id){
  state.editingId=id;
  state.screen="edit";
  render();
}

function deleteSurvey(id){
  const s=getSurvey(id);
  confirmModal(
    "アンケート削除",
    `「<strong>${esc(s.title||"無題のアンケート")}</strong>」を削除しますか？`,
    ()=>{
      surveys=surveys.filter(x=>x.id!==id);
      toast("アンケートを削除しました");
      render();
    }
  );
}

function duplicateSurvey(id){
  const original=getSurvey(id);
  confirmModal(
    "アンケート複製",
    `「<strong>${esc(original.title)}</strong>」を複製しますか？<br><br>
     タイトル・説明・期間・グループ・質問・選択肢・必須設定・条件分岐を複製し、
     状態は下書きとして作成します。回答データと送信履歴は複製しません。`,
    ()=>{
      const s=clone(original);
      s.id=uid();
      s.title=(original.title||"無題")+"（複製）";
      s.status="draft";
      s.responses=0;
      s.created=new Date().toISOString().slice(0,10);
      s.updated=new Date().toISOString().slice(0,16);
      surveys.push(s);
      toast("下書きアンケートを複製しました");
      render();
    }
  );
}

/* =========================================================
   作成・編集
========================================================= */

function renderEdit(){
  const s=getSurvey(state.editingId);
  if(!s)return renderList();

  renumber(s);

  const endDisabled=s.status==="end";

  return `
    <div class="page-title">
      <div>
        <h1>アンケート作成・編集</h1>
        <p>${s.id ? "アンケート内容を編集します。" : ""}</p>
      </div>
    </div>

    <div class="editor-actions">
      <button class="btn" onclick="cancelEdit()">キャンセル</button>
      <button class="btn primary" onclick="saveSurvey()">保存して一覧へ</button>

      <div class="state-control">
        状態：
        ${
          endDisabled
          ? `<select disabled><option>終了</option></select>`
          : `
            <select id="statusSelect" onchange="requestStatusChange(this.value)">
              <option value="draft" ${s.status==="draft"?"selected":""}>下書き</option>
              <option value="public" ${s.status==="public"?"selected":""}>公開</option>
              <option value="stop" ${s.status==="stop"?"selected":""}>停止</option>
            </select>`
        }
      </div>
    </div>

    <div class="card">
      <h2 class="section-title">基本情報</h2>

      <div class="field">
        <label>アンケートタイトル</label>
        <input id="surveyTitle" style="width:100%"
               value="${escapeForAttr(s.title)}"
               oninput="getSurvey(${s.id}).title=this.value">
      </div>

      <div class="field">
        <label>アンケート説明</label>
        <textarea id="surveyDescription"
                  oninput="getSurvey(${s.id}).description=this.value">${esc(s.description)}</textarea>
      </div>

      <div class="grid2">
        <div class="field">
          <label>開始日時</label>
          <input type="datetime-local"
                 value="${esc(s.start)}"
                 onchange="getSurvey(${s.id}).start=this.value">
        </div>
        <div class="field">
          <label>終了日時</label>
          <input type="datetime-local"
                 value="${esc(s.end)}"
                 onchange="getSurvey(${s.id}).end=this.value">
          <div class="hint">過去日時も入力できます。終了するのは公開中の場合のみです。</div>
        </div>
      </div>

      <div class="field">
        <label>質問番号の採番方式</label>
        <div class="radio-row">
          <label>
            <input type="radio" name="numbering"
              ${s.numbering==="global"?"checked":""}
              onchange="changeNumbering('global')">
            アンケート全体で通番
          </label>
          <label>
            <input type="radio" name="numbering"
              ${s.numbering==="group"?"checked":""}
              onchange="changeNumbering('group')">
            グループ毎に採番
          </label>
        </div>
      </div>

      <div class="field">
        <label>
          <input type="checkbox"
            ${s.allowResubmit?"checked":""}
            onchange="getSurvey(${s.id}).allowResubmit=this.checked">
          回答済み個別URLからの再回答を許可する
        </label>
      </div>
    </div>

    <div class="card">
      <div class="card-title">
        <h2 class="section-title">グループ・質問</h2>
      </div>

      <div id="groupContainer">
        ${s.groups.map((g,gi)=>renderGroup(s,g,gi)).join("")}
      </div>

      <button class="btn" onclick="addGroup()">＋ グループを追加</button>
    </div>
  `;
}

function renderGroup(s,g,gi){
  return `
    <div class="group" draggable="true"
         ondragstart="dragGroupStart(${gi})"
         ondragover="event.preventDefault()"
         ondrop="dropGroup(${gi})">

      <div class="group-header">
        <span class="drag-handle">☷</span>
        <input value="${escapeForAttr(g.title)}"
               oninput="getSurvey(${s.id}).groups[${gi}].title=this.value">
        <button class="btn small danger" onclick="deleteGroup(${gi})">グループ削除</button>
      </div>

      <div class="question-list"
           ondragover="event.preventDefault()"
           ondrop="dropQuestion(${gi},null)">

        ${g.questions.map((q,qi)=>renderQuestion(s,g,q,gi,qi)).join("")}

        <div class="add-row">
          <button class="btn small" onclick="addQuestion(${gi})">＋ 質問を追加</button>
        </div>
      </div>
    </div>
  `;
}

function renderQuestion(s,g,q,gi,qi){
  renumber(s);

  return `
    <div class="question"
         draggable="true"
         ondragstart="dragQuestionStart(${gi},${qi})"
         ondragover="event.preventDefault()"
         ondrop="dropQuestion(${gi},${qi})">

      <div class="question-top">
        <span class="drag-handle">⋮⋮</span>
        <span class="q-number">${esc(q.number||"")}</span>
        <input class="q-text"
               value="${escapeForAttr(q.text)}"
               placeholder="質問文を入力"
               oninput="getSurvey(${s.id}).groups[${gi}].questions[${qi}].text=this.value">
        <button class="btn small danger" onclick="deleteQuestion(${gi},${qi})">削除</button>
      </div>

      <div class="q-body">
        <div class="grid2">
          <div class="field">
            <label>回答形式</label>
            <select onchange="changeQuestionType(${gi},${qi},this.value)">
              <option value="single" ${q.type==="single"?"selected":""}>単一選択</option>
              <option value="multiple" ${q.type==="multiple"?"selected":""}>複数選択</option>
              <option value="text" ${q.type==="text"?"selected":""}>自由記述</option>
            </select>
          </div>

          <div class="field">
            <label>必須 / 任意</label>
            <select onchange="getSurvey(${s.id}).groups[${gi}].questions[${qi}].required=this.value==='required'">
              <option value="required" ${q.required?"selected":""}>必須</option>
              <option value="optional" ${!q.required?"selected":""}>任意</option>
            </select>
          </div>
        </div>

        ${
          q.type!=="text"
          ? `
            <div class="field">
              <label>選択肢</label>
              <div class="options">
                ${q.options.map((o,oi)=>`
                  <div class="option-row">
                    <input value="${escapeForAttr(o)}"
                      oninput="getSurvey(${s.id}).groups[${gi}].questions[${qi}].options[${oi}]=this.value">
                    <button class="btn small danger"
                      onclick="deleteOption(${gi},${qi},${oi})">削除</button>
                  </div>
                `).join("")}
              </div>
              <button class="btn small" onclick="addOption(${gi},${qi})">＋ 選択肢を追加</button>
            </div>
          ` : `
            <div class="alert info">
              自由記述は回答形式として1種類のみです。
              1行テキスト・複数行テキストは別形式として扱いません。
            </div>
          `
        }

        ${
          q.type==="single"
          ? `
            <div class="field">
              <label>条件分岐</label>
              ${renderBranchEditor(s,g,q,gi,qi)}
            </div>
          ` : ""
        }
      </div>
    </div>
  `;
}

function renderBranchEditor(s,g,q,gi,qi){
  const targets=[];
  s.groups.forEach((gg,gii)=>{
    gg.questions.forEach((qq,qii)=>{
      if(!(gii===gi && qii===qi)){
        targets.push({
          value:`${gii}:${qii}`,
          label:`${qq.number||""} ${qq.text||"（未入力）"}`
        });
      }
    });
  });

  return q.options.map((o,oi)=>`
    <div class="grid2" style="margin-bottom:7px">
      <div>
        <small>${esc(o)}</small>
      </div>
      <select onchange="setBranch(${gi},${qi},${oi},this.value)">
        <option value="">次の質問を指定しない</option>
        ${targets.map(t=>`
          <option value="${t.value}"
            ${q.branch && q.branch[oi]===t.value?"selected":""}>
            ${esc(t.label)}
          </option>
        `).join("")}
      </select>
    </div>
  `).join("");
}

function setBranch(gi,qi,oi,value){
  const s=getSurvey(state.editingId);
  const q=s.groups[gi].questions[qi];
  q.branch=q.branch||{};
  if(value)q.branch[oi]=value;
  else delete q.branch[oi];
  render();
}

function changeQuestionType(gi,qi,type){
  const s=getSurvey(state.editingId);
  const q=s.groups[gi].questions[qi];
  q.type=type;
  if(type==="text"){
    q.options=[];
  }else if(!q.options.length){
    q.options=["選択肢1","選択肢2"];
  }
  render();
}

function addGroup(){
  const s=getSurvey(state.editingId);
  s.groups.push({
    id:uid(),
    title:`グループ${s.groups.length+1}`,
    questions:[]
  });
  renumber(s);
  render();
}

function deleteGroup(gi){
  const s=getSurvey(state.editingId);
  const g=s.groups[gi];

  confirmModal(
    "グループ削除",
    g.questions.length
      ? `このグループには <strong>${g.questions.length}件</strong> の質問があります。削除しますか？`
      : "このグループを削除しますか？",
    ()=>{
      s.groups.splice(gi,1);
      if(!s.groups.length){
        s.groups.push({id:uid(),title:"グループ1",questions:[]});
      }
      renumber(s);
      render();
    }
  );
}

function addQuestion(gi){
  const s=getSurvey(state.editingId);
  s.groups[gi].questions.push({
    id:uid(),
    text:"",
    type:"single",
    required:false,
    options:["選択肢1","選択肢2"],
    branch:{}
  });
  renumber(s);
  render();
}

function deleteQuestion(gi,qi){
  confirmModal(
    "質問削除",
    "この質問を削除しますか？",
    ()=>{
      const s=getSurvey(state.editingId);
      s.groups[gi].questions.splice(qi,1);
      renumber(s);
      render();
    }
  );
}

function addOption(gi,qi){
  const s=getSurvey(state.editingId);
  s.groups[gi].questions[qi].options.push(
    `選択肢${s.groups[gi].questions[qi].options.length+1}`
  );
  render();
}

function deleteOption(gi,qi,oi){
  const s=getSurvey(state.editingId);
  if(s.groups[gi].questions[qi].options.length<=1){
    toast("選択肢は1つ以上必要です");
    return;
  }
  s.groups[gi].questions[qi].options.splice(oi,1);
  render();
}

function changeNumbering(mode){
  const s=getSurvey(state.editingId);
  s.numbering=mode;
  renumber(s);
  render();
}

/* ドラッグ＆ドロップ */
let dragInfo=null;

function dragGroupStart(gi){
  dragInfo={type:"group",gi};
}

function dropGroup(target){
  if(!dragInfo || dragInfo.type!=="group")return;
  const s=getSurvey(state.editingId);
  const from=dragInfo.gi;
  if(from===target)return;

  const item=s.groups.splice(from,1)[0];
  s.groups.splice(target,0,item);
  renumber(s);
  dragInfo=null;
  render();
}

function dragQuestionStart(gi,qi){
  dragInfo={type:"question",gi,qi};
}

function dropQuestion(targetGi,targetQi){
  if(!dragInfo || dragInfo.type!=="question")return;

  const s=getSurvey(state.editingId);
  const fromGi=dragInfo.gi;
  const fromQi=dragInfo.qi;

  const q=s.groups[fromGi].questions.splice(fromQi,1)[0];

  if(targetQi===null){
    s.groups[targetGi].questions.push(q);
  }else{
    let insertIndex=targetQi;
    if(fromGi===targetGi && fromQi<targetQi)insertIndex--;
    s.groups[targetGi].questions.splice(insertIndex,0,q);
  }

  renumber(s);
  dragInfo=null;
  render();
}

function saveSurvey(){
  const s=getSurvey(state.editingId);
  s.updated=new Date().toISOString().slice(0,16);

  /*
   * 新規作成時は下書きとして保存する仕様。
   * 既存編集では現在状態を維持。
   */
  toast("保存しました");
  state.screen="list";
  render();
}

function cancelEdit(){
  confirmModal(
    "作成内容の破棄",
    "変更内容を破棄して前の画面へ戻りますか？",
    ()=>{
      state.screen="list";
      render();
    }
  );
}

function requestStatusChange(target){
  const s=getSurvey(state.editingId);
  const current=s.status;

  if(current==="end"){
    render();
    return;
  }

  if(target===current){
    render();
    return;
  }

  /*
   * 実行可能な遷移
   */
  const allowed =
    (current==="draft" && target==="public") ||
    (current==="public" && target==="stop") ||
    (current==="stop" && target==="public");

  if(!allowed){
    toast("この状態変更は実行できません");
    render();
    return;
  }

  const labels={
    public:"公開",
    stop:"停止"
  };

  confirmModal(
    labels[target],
    `「このアンケートを${labels[target]}しますか？」`,
    ()=>{
      s.status=target;
      s.updated=new Date().toISOString().slice(0,16);
      toast(`状態を「${statusLabel(target)}」へ変更しました`);
      render();
    }
  );
}

/* =========================================================
   プレビュー
========================================================= */

function renderPreview(){
  const s=getSurvey(state.editingId);
  if(!s)return renderList();

  renumber(s);

  return `
    <div class="page-title">
      <div>
        <h1>プレビュー</h1>
        <p>回答者画面を確認します。ここでの送信は実際の送信を行いません。</p>
      </div>
      <div class="toolbar">
        <button class="btn ${state.previewMode==="desktop"?"primary":""}"
                onclick="state.previewMode='desktop';render()">PC表示</button>
        <button class="btn ${state.previewMode==="mobile"?"primary":""}"
                onclick="state.previewMode='mobile';render()">スマートフォン表示</button>
        <button class="btn" onclick="state.screen='edit';render()">編集へ戻る</button>
      </div>
    </div>

    <div class="${state.previewMode==="mobile"?"preview-phone":"preview-desktop"}">
      <div class="preview-inner">
        <h1>${esc(s.title||"アンケートタイトル")}</h1>
        <p>${esc(s.description)}</p>
        <p style="font-size:12px;color:#64748b">
          ${formatDate(s.start)} ～ ${formatDate(s.end)}
        </p>

        ${s.groups.map(g=>`
          <div style="margin-top:28px">
            <h2>${esc(g.title)}</h2>
            ${g.questions.map(q=>`
              <div class="card">
                <div style="font-weight:700">
                  ${esc(q.number)} ${esc(q.text||"質問文未入力")}
                  ${q.required?'<span style="color:#dc2626"> *</span>':""}
                </div>

                ${
                  q.type==="single"
                  ? q.options.map((o,i)=>`
                    <label class="preview-choice">
                      <input type="radio" name="p${q.id}">
                      ${esc(o)}
                    </label>
                  `).join("")
                  : q.type==="multiple"
                  ? q.options.map((o,i)=>`
                    <label class="preview-choice">
                      <input type="checkbox">
                      ${esc(o)}
                    </label>
                  `).join("")
                  : `<textarea style="width:100%;margin-top:10px"
                         placeholder="回答を入力してください"></textarea>`
                }
              </div>
            `).join("")}
          </div>
        `).join("")}

        <div class="action-row">
          <button class="btn">戻る</button>
          <button class="btn primary" onclick="toast('プレビューでは実際の送信は行いません')">
            回答確認
          </button>
        </div>
      </div>
    </div>
  `;
}

/* =========================================================
   顧客選択・メール送信
========================================================= */

function openMail(id){
  state.editingId=id;
  state.screen="mail";
  state.mailTab="send";
  state.selectedCustomers=[];
  state.sendResult=null;
  render();
}

function openMailFromFirst(){
  if(!surveys.length)return;
  openMail(surveys[0].id);
}

function renderMail(){
  const s=getSurvey(state.editingId);
  if(!s)return renderList();

  const filtered=customers.filter(c=>{
    const q=state.customerSearch.toLowerCase();
    if(!q)return true;
    return [c.name,c.org,c.email,c.status].join(" ").toLowerCase().includes(q);
  });

  return `
    <div class="page-title">
      <div>
        <h1>顧客選択・メール送信</h1>
        <p>「${esc(s.title)}」の送信業務を行います。</p>
      </div>
      <button class="btn" onclick="go('list')">一覧へ戻る</button>
    </div>

    <div class="tabs">
      <button class="${state.mailTab==="send"?"active":""}"
              onclick="state.mailTab='send';render()">顧客選択・送信</button>
      <button class="${state.mailTab==="history"?"active":""}"
              onclick="state.mailTab='history';render()">送信履歴</button>
    </div>

    ${
      state.mailTab==="history"
      ? renderMailHistory(s)
      : renderMailSend(s,filtered)
    }
  `;
}

function renderMailSend(s,filtered){
  return `
    ${state.sendResult ? `
      <div class="alert ${state.sendResult.fail ? "warning":"success"}">
        <strong>送信結果</strong><br>
        対象件数：${state.sendResult.total}件　
        成功：${state.sendResult.success}件　
        失敗：${state.sendResult.fail}件　
        送信日時：${state.sendResult.date}
      </div>
    `:""}

    <div class="card">
      <h2 class="section-title">顧客選択</h2>

      <div class="toolbar" style="margin-bottom:14px">
        <input style="min-width:300px"
          placeholder="顧客名・組織名・メールアドレス・ステータス"
          value="${esc(state.customerSearch)}"
          oninput="state.customerSearch=this.value;render()">

        <button class="btn small" onclick="selectAllVisible()">表示中を全選択</button>
        <button class="btn small" onclick="state.selectedCustomers=[];render()">選択解除</button>
      </div>

      <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>選択</th>
            <th>組織名</th>
            <th>氏名</th>
            <th>メールアドレス</th>
            <th>電話番号</th>
            <th>住所</th>
            <th>最終送信日時</th>
            <th>送信回数</th>
            <th>回答ステータス</th>
            <th>kintone</th>
          </tr>
        </thead>
        <tbody>
        ${filtered.map(c=>`
          <tr>
            <td>
              <input type="checkbox"
                ${state.selectedCustomers.includes(c.id)?"checked":""}
                onchange="toggleCustomer(${c.id},this.checked)">
            </td>
            <td>${esc(c.org)}</td>
            <td>${esc(c.name)}</td>
            <td>${esc(c.email)}</td>
            <td>${esc(c.phone)}</td>
            <td>${esc(c.address)}</td>
            <td>${esc(c.lastSent||"-")}</td>
            <td>${c.count}</td>
            <td>${esc(c.status)}</td>
            <td>
              ${c.kintone
                ? '<span class="badge ok">✓ 登録済み</span>'
                : '<span class="badge ng">未登録</span>'}
            </td>
          </tr>
        `).join("")}
        </tbody>
      </table>
      </div>
    </div>

    <div class="grid2">
      <div class="card">
        <h2 class="section-title">メール内容</h2>

        <div class="field">
          <label>メール件名</label>
          <input id="mailSubject" style="width:100%"
            value="【アンケート】${esc(s.title)}">
        </div>

        <div class="field">
          <label>メール本文</label>
          <textarea id="mailBody" style="width:100%">{{顧客名}} 様

アンケートへのご協力をお願いいたします。

以下のURLからご回答ください。
{{アンケートURL}}

よろしくお願いいたします。</textarea>
          <div class="hint">
            使用可能な動的変数：
            {顧客名}　{アンケートURL}
          </div>
        </div>

        <div class="action-row">
          <button class="btn" onclick="previewMail()">送信文を確認</button>
          <button class="btn primary" onclick="sendMail('normal')">一括送信</button>
          <button class="btn warning" onclick="sendMail('reminder')">リマインド</button>
        </div>
      </div>

      <div class="card">
        <h2 class="section-title">送信対象</h2>

        <div class="stat">
          <div class="label">選択件数</div>
          <div class="value">${state.selectedCustomers.length}</div>
        </div>

        <div style="margin-top:15px">
          ${
            state.selectedCustomers.length
            ? state.selectedCustomers.map(id=>{
                const c=customers.find(x=>x.id===id);
                return `<div style="padding:6px 0;border-bottom:1px solid #eee">
                  ${esc(c.name)} / ${esc(c.email)}
                </div>`;
              }).join("")
            : `<div class="empty">送信対象を選択してください。</div>`
          }
        </div>
      </div>
    </div>
  `;
}

function renderMailHistory(s){
  const histories=sendHistories;

  return `
    <div class="card">
      <div class="card-title">
        <h2>送信履歴</h2>
        <span class="badge info">同一画面内の機能</span>
      </div>

      <div class="alert info">
        送信履歴は独立画面ではありません。
        この画面内で過去の送信内容を確認できます。
      </div>

      ${
        histories.length
        ? histories.map(h=>`
          <div class="card" style="background:#f8fafc">
            <div class="card-title">
              <strong>${esc(h.subject)}</strong>
              <span class="badge info">${esc(h.type)}</span>
            </div>

            <div class="grid3">
              <div><small>送信日時</small><br><strong>${esc(h.date)}</strong></div>
              <div><small>送信件数</small><br><strong>${h.count}件</strong></div>
              <div><small>送信実行者</small><br><strong>${esc(h.operator)}</strong></div>
            </div>

            <div style="margin-top:15px">
              <strong>対象顧客</strong>
              <div style="margin-top:7px">
                ${h.customers.map(id=>{
                  const c=customers.find(x=>x.id===id);
                  return `<span class="badge info" style="margin:2px">
                    ${esc(c?.name||"不明")}
                  </span>`;
                }).join("")}
              </div>
            </div>

            <div style="margin-top:15px">
              <button class="btn small"
                onclick="showHistoryDetail(${h.id})">
                送信済みメールを確認
              </button>
            </div>
          </div>
        `).join("")
        : `<div class="empty">送信履歴はありません。</div>`
      }
    </div>
  `;
}

function toggleCustomer(id,checked){
  if(checked){
    if(!state.selectedCustomers.includes(id))
      state.selectedCustomers.push(id);
  }else{
    state.selectedCustomers=state.selectedCustomers.filter(x=>x!==id);
  }
  render();
}

function selectAllVisible(){
  customers.forEach(c=>{
    const q=state.customerSearch.toLowerCase();
    const hit=!q || [c.name,c.org,c.email,c.status].join(" ").toLowerCase().includes(q);
    if(hit && !state.selectedCustomers.includes(c.id))
      state.selectedCustomers.push(c.id);
  });
  render();
}

function previewMail(){
  const subject=document.getElementById("mailSubject").value;
  const body=document.getElementById("mailBody").value;

  document.getElementById("modalRoot").innerHTML=`
    <div class="modal-backdrop">
      <div class="modal" style="max-width:800px">
        <div class="modal-header">送信文確認</div>
        <div class="modal-body">
          <div><strong>件名</strong></div>
          <div style="margin:7px 0 18px">${esc(subject)}</div>
          <div><strong>本文</strong></div>
          <div class="mail-preview">${esc(body)
            .replaceAll("{顧客名}","山田 太郎")
            .replaceAll("{アンケートURL}","https://example.com/survey/individual/xxxxx")}</div>
        </div>
        <div class="modal-footer">
          <button class="btn" onclick="closeModal()">閉じる</button>
        </div>
      </div>
    </div>`;
}

function sendMail(type){
  if(!state.selectedCustomers.length){
    toast("送信対象を選択してください");
    return;
  }

  const already=state.selectedCustomers.filter(id=>{
    const c=customers.find(x=>x.id===id);
    return c.count>0;
  });

  if(type==="normal" && already.length){
    confirmModal(
      "送信確認",
      `既に送信済みの宛先が <strong>${already.length}件</strong> 含まれています。<br>再送しますか？`,
      ()=>executeSend(type)
    );
  }else{
    confirmModal(
      type==="reminder" ? "リマインド送信" : "メール一括送信",
      `${state.selectedCustomers.length}件へ送信しますか？`,
      ()=>executeSend(type)
    );
  }
}

function executeSend(type){
  const subject=document.getElementById("mailSubject")?.value || "アンケート";
  const body=document.getElementById("mailBody")?.value || "";

  let success=0,fail=0;

  state.selectedCustomers.forEach(id=>{
    const c=customers.find(x=>x.id===id);
    if(Math.random()<0.9){
      success++;
      c.count++;
      c.lastSent=new Date().toLocaleString("ja-JP");
      c.status="送信済み / 未回答";
    }else{
      fail++;
    }
  });

  sendHistories.unshift({
    id:uid(),
    date:new Date().toLocaleString("ja-JP"),
    type:type==="reminder"?"リマインド":"一括送信",
    count:state.selectedCustomers.length,
    subject,
    operator:"管理者",
    customers:[...state.selectedCustomers],
    body
  });

  state.sendResult={
    total:state.selectedCustomers.length,
    success,
    fail,
    date:new Date().toLocaleString("ja-JP")
  };

  toast("送信処理を実行しました");
  render();
}

function showHistoryDetail(id){
  const h=sendHistories.find(x=>x.id===id);
  if(!h)return;

  const c=customers.find(x=>x.id===h.customers[0]);

  const renderedBody=h.body
    .replaceAll("{顧客名}",c?.name||"顧客名")
    .replaceAll("{アンケートURL}",
      "https://example.com/survey/individual/"+(c?.id||"xxxxx"));

  document.getElementById("modalRoot").innerHTML=`
    <div class="modal-backdrop">
      <div class="modal" style="max-width:800px">
        <div class="modal-header">送信済みメール</div>
        <div class="modal-body">
          <p><strong>件名：</strong>${esc(h.subject)}</p>
          <p><strong>対象：</strong>${esc(c?.name||"")}</p>
          <div class="mail-preview">${esc(renderedBody)}</div>
        </div>
        <div class="modal-footer">
          <button class="btn" onclick="closeModal()">閉じる</button>
        </div>
      </div>
    </div>`;
}

/* =========================================================
   集計
========================================================= */

function openAggregate(id){
  state.aggregateId=id;
  state.screen="aggregate";
  render();
}

function openAggregateFromFirst(){
  if(!surveys.length)return;
  openAggregate(surveys[0].id);
}

function renderAggregate(){
  const s=getSurvey(state.aggregateId);
  if(!s)return renderList();

  renumber(s);

  const total=Math.max(s.responses+40,1);
  const rate=Math.round((s.responses/total)*100);

  return `
    <div class="page-title">
      <div>
        <h1>回答集計・分析</h1>
        <p>${esc(s.title)}</p>
      </div>
      <div class="action-row">
        <button class="btn" onclick="exportCsv()">CSVダウンロード</button>
        <button class="btn" onclick="exportPdf()">PDF出力</button>
        <button class="btn" onclick="go('list')">一覧へ</button>
      </div>
    </div>

    <div class="stats">
      <div class="stat">
        <div class="label">送信対象者数</div>
        <div class="value">${total}</div>
      </div>
      <div class="stat">
        <div class="label">回答数</div>
        <div class="value">${s.responses}</div>
      </div>
      <div class="stat">
        <div class="label">未登録顧客からの回答</div>
        <div class="value">3</div>
      </div>
      <div class="stat">
        <div class="label">未回答数</div>
        <div class="value">${Math.max(total-s.responses,0)}</div>
      </div>
      <div class="stat">
        <div class="label">回答率</div>
        <div class="value">${rate}%</div>
      </div>
    </div>

    <div class="card" style="margin-top:20px">
      <h2 class="section-title">設問フィルター</h2>
      <div class="toolbar">
        <button class="btn small" onclick="toast('すべて選択しました')">すべて選択</button>
        <button class="btn small" onclick="toast('すべて解除しました')">すべて解除</button>
      </div>
      <div class="check-list" style="margin-top:12px">
        ${s.groups.flatMap(g=>g.questions).map(q=>`
          <label>
            <input type="checkbox" checked>
            ${esc(q.number)} ${esc(q.text||"質問文未入力")}
          </label>
        `).join("")}
      </div>
    </div>

    ${
      s.responses===0
      ? `<div class="card">
          <div class="empty">現在、回答データはありません</div>
        </div>`
      : `
      ${s.groups.map(g=>g.questions.map(q=>`
        <div class="card">
          <h2>${esc(q.number)} ${esc(q.text||"質問文未入力")}</h2>

          ${
            q.type==="text"
            ? `
              <div class="alert info">自由記述回答一覧</div>
              <div style="padding:12px;border-bottom:1px solid #eee">
                「今後もより使いやすいサービスを期待しています。」
              </div>
              <div style="padding:12px;border-bottom:1px solid #eee">
                「サポート対応が良かったです。」
              </div>
            `
            : `
              ${q.options.map((o,i)=>{
                const count=Math.max(1,Math.round(s.responses*(0.1+(i*0.08))));
                const pct=Math.round(count/s.responses*100);
                return `
                  <div style="margin-bottom:15px">
                    <div style="display:flex;justify-content:space-between">
                      <span>${esc(o)}</span>
                      <span>${count}件 / ${pct}%</span>
                    </div>
                    <div class="bar"><span style="width:${Math.min(pct,100)}%"></span></div>
                  </div>`;
              }).join("")}
            `
          }
        </div>
      `).join("")).join("")}
      `
    }

    <div class="card">
      <h2 class="section-title">個別回答</h2>
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
            ${customers.filter(c=>c.status==="回答済み").map(c=>`
              <tr>
                <td>${esc(c.org)}</td>
                <td>${esc(c.name)}</td>
                <td>2026-08-22 15:20</td>
                <td>満足度：満足</td>
                <td><button class="btn small" onclick="toast('全回答を表示しました')">全回答を表示</button></td>
              </tr>
            `).join("")}
          </tbody>
        </table>
      </div>
    </div>
  `;
}

function exportCsv(){
  toast("CSV出力操作を実行しました（モック）");
}

function exportPdf(){
  toast("PDF出力操作を実行しました（モック）");
}

/* =========================================================
   kintone
========================================================= */

function renderKintone(){
  const k=settings.kintone;

  return `
    <div class="page-title">
      <div>
        <h1>kintone連携設定</h1>
        <p>接続テスト・項目取得・顧客同期は独立した操作です。</p>
      </div>
    </div>

    <div class="card">
      <h2 class="section-title">接続設定</h2>

      <div class="grid2">
        <div class="field">
          <label>サブドメイン</label>
          <input style="width:100%" value="${esc(k.subdomain)}"
            oninput="settings.kintone.subdomain=this.value">
        </div>

        <div class="field">
          <label>顧客管理アプリID</label>
          <input style="width:100%" value="${esc(k.appId)}"
            oninput="settings.kintone.appId=this.value">
        </div>

        <div class="field">
          <label>ログイン名</label>
          <input style="width:100%" value="${esc(k.login)}"
            oninput="settings.kintone.login=this.value">
        </div>

        <div class="field">
          <label>パスワード</label>
          <input type="password" style="width:100%" value="${esc(k.password)}"
            oninput="settings.kintone.password=this.value">
        </div>
      </div>

      <div class="field">
        <label>
          <input type="checkbox" ${k.ssl?"checked":""}
            onchange="settings.kintone.ssl=this.checked">
          SSL証明書を検証する
        </label>
      </div>

      <div class="action-row">
        <button class="btn primary" onclick="testKintone()">接続テスト</button>
        <button class="btn" onclick="saveKintone()">設定を保存</button>
        <button class="btn" onclick="loadKintoneFields()">項目一覧を再取得</button>
        <button class="btn success" onclick="syncCustomers()">顧客情報を同期</button>
      </div>

      ${
        state.kintoneTest
        ? `<div class="alert ${state.kintoneTest==="success"?"success":"error"}"
                 style="margin-top:15px">
            ${state.kintoneTest==="success"
              ? "kintoneへの接続に成功しました"
              : "kintoneへの接続に失敗しました"}
          </div>`
        : ""
      }

      ${
        state.customerSynced
        ? `<div class="alert success">顧客情報を同期しました（モック）</div>`
        : ""
      }
    </div>

    <div class="card">
      <h2 class="section-title">フィールドマッピング</h2>

      ${
        state.kintoneFieldsLoaded
        ? `<div class="alert success">
             kintoneアプリの項目一覧を取得しました。
           </div>`
        : `<div class="alert info">
             「項目一覧を再取得」を押すとサンプル項目を表示します。
           </div>`
      }

      <div class="grid2">
        ${mappingSelect("org","組織名",["company_name","company","organization"])}
        ${mappingSelect("name","氏名",["name","customer_name","full_name"])}
        ${mappingSelect("email","メールアドレス",["email","mail_address"])}
        ${mappingSelect("dept","部署名",["department","dept"])}
        ${mappingSelect("phone","電話番号",["tel","phone"])}
      </div>

      <div class="field">
        <label>住所マッピング（複数選択）</label>
        <div class="check-list">
          ${[
            ["prefecture","都道府県"],
            ["city","市区町村"],
            ["address","番地"],
            ["building","建物名"],
            ["zip","郵便番号"]
          ].map(([v,l])=>`
            <label>
              <input type="checkbox"
                ${k.mapping.address.includes(v)?"checked":""}
                onchange="toggleAddressMapping('${v}',this.checked)">
              ${l}
            </label>
          `).join("")}
        </div>
      </div>
    </div>

    <div class="card">
      <h2 class="section-title">操作の状態</h2>
      <div class="grid3">
        <div>
          <strong>接続テスト</strong><br>
          ${state.kintoneTest==="success"
            ? '<span class="badge ok">接続成功</span>'
            : state.kintoneTest==="fail"
            ? '<span class="badge ng">接続失敗</span>'
            : '<span class="badge draft">未実行</span>'}
        </div>
        <div>
          <strong>項目一覧</strong><br>
          ${state.kintoneFieldsLoaded
            ? '<span class="badge ok">取得済み</span>'
            : '<span class="badge draft">未取得</span>'}
        </div>
        <div>
          <strong>顧客同期</strong><br>
          ${state.customerSynced
            ? '<span class="badge ok">同期済み</span>'
            : '<span class="badge draft">未実行</span>'}
        </div>
      </div>
    </div>
  `;
}

function mappingSelect(key,label,options){
  const value=settings.kintone.mapping[key]||"";
  return `
    <div class="field">
      <label>${label}</label>
      <select style="width:100%"
        onchange="settings.kintone.mapping['${key}']=this.value">
        ${options.map(o=>`
          <option value="${o}" ${o===value?"selected":""}>${o}</option>
        `).join("")}
      </select>
    </div>`;
}

function testKintone(){
  state.kintoneTest=Math.random()>.25?"success":"fail";
  render();
}

function saveKintone(){
  toast("kintone設定を保存しました（モック）");
}

function loadKintoneFields(){
  state.kintoneFieldsLoaded=true;
  toast("kintone項目一覧を取得しました");
  render();
}

function toggleAddressMapping(v,checked){
  const arr=settings.kintone.mapping.address;
  if(checked && !arr.includes(v))arr.push(v);
  if(!checked){
    settings.kintone.mapping.address=arr.filter(x=>x!==v);
  }
  render();
}

function syncCustomers(){
  state.customerSynced=true;
  toast("顧客情報を同期しました（モック）");
  render();
}

/* =========================================================
   SMTP
========================================================= */

function renderSmtp(){
  const s=settings.smtp;

  return `
    <div class="page-title">
      <div>
        <h1>メールサーバ設定</h1>
        <p>SMTP設定とテスト送信を行います。</p>
      </div>
    </div>

    <div class="card">
      <h2 class="section-title">SMTP設定</h2>

      <div class="grid2">
        <div class="field">
          <label>SMTPサーバ</label>
          <input style="width:100%" value="${esc(s.server)}"
            oninput="settings.smtp.server=this.value">
        </div>

        <div class="field">
          <label>SMTPポート</label>
          <input style="width:100%" value="${esc(s.port)}"
            oninput="settings.smtp.port=this.value">
        </div>

        <div class="field">
          <label>暗号化方式</label>
          <select style="width:100%"
            onchange="settings.smtp.encryption=this.value">
            <option ${s.encryption==="SSL"?"selected":""}>SSL</option>
            <option ${s.encryption==="TLS"?"selected":""}>TLS</option>
            <option ${s.encryption==="なし"?"selected":""}>なし</option>
          </select>
        </div>

        <div class="field">
          <label>SMTP認証</label>
          <select style="width:100%"
            onchange="settings.smtp.auth=this.value==='yes'">
            <option value="yes" ${s.auth?"selected":""}>あり</option>
            <option value="no" ${!s.auth?"selected":""}>なし</option>
          </select>
        </div>

        <div class="field">
          <label>SMTPユーザー名</label>
          <input style="width:100%" value="${esc(s.username)}"
            oninput="settings.smtp.username=this.value">
        </div>

        <div class="field">
          <label>SMTPパスワード</label>
          <input type="password" style="width:100%" value="${esc(s.password)}"
            oninput="settings.smtp.password=this.value">
        </div>

        <div class="field">
          <label>送信元メールアドレス</label>
          <input style="width:100%" value="${esc(s.from)}"
            oninput="settings.smtp.from=this.value">
        </div>

        <div class="field">
          <label>送信元名</label>
          <input style="width:100%" value="${esc(s.fromName)}"
            oninput="settings.smtp.fromName=this.value">
        </div>

        <div class="field">
          <label>返信先メールアドレス</label>
          <input style="width:100%" value="${esc(s.reply)}"
            oninput="settings.smtp.reply=this.value">
        </div>
      </div>

      <div class="action-row">
        <button class="btn primary" onclick="saveSmtp()">設定を保存</button>
        <button class="btn" onclick="testSmtp()">テストメールを送信</button>
      </div>

      ${
        state.smtpTest
        ? `<div class="alert ${state.smtpTest==="success"?"success":"error"}"
             style="margin-top:15px">
            ${
              state.smtpTest==="success"
              ? "メールサーバへの接続・テスト送信に成功しました（モック）"
              : "メールサーバへの接続・テスト送信に失敗しました（モック）"
            }
          </div>`
        :""
      }
    </div>

    <div class="card">
      <h2 class="section-title">接続状態</h2>
      ${
        state.smtpTest==="success"
        ? '<span class="badge ok">接続確認済み</span>'
        : state.smtpTest==="fail"
        ? '<span class="badge ng">接続できません</span>'
        : '<span class="badge draft">未設定 / 未確認</span>'
      }
    </div>
  `;
}

function saveSmtp(){
  toast("メールサーバ設定を保存しました（モック）");
}

function testSmtp(){
  state.smtpTest=Math.random()>.2?"success":"fail";
  render();
}

/* =========================================================
   回答者向け画面
========================================================= */

function startAnswer(id){
  state.editingId=id;
  state.screen="answer";
  state.answerStep=0;
  state.answerData={};
  state.answerSubmitted=false;
  render();
}

function renderAnswerScreen(){
  const s=getSurvey(state.editingId);

  if(!s){
    return `<div style="padding:30px">アンケートが見つかりません。</div>`;
  }

  autoEndSurvey(s);

  if(s.status!=="public"){
    return `
      <main style="max-width:760px;margin:0 auto">
        <div class="card" style="margin-top:40px">
          <h1>${esc(s.title)}</h1>
          <div class="alert warning">
            このアンケートは現在回答できません。
          </div>
        </div>
      </main>`;
  }

  if(state.answerSubmitted){
    return `
      <main style="max-width:760px;margin:0 auto">
        <div class="card" style="margin-top:60px;text-align:center;padding:50px 25px">
          <div style="font-size:50px">✓</div>
          <h1>回答ありがとうございました</h1>
          <p>回答を受け付けました。</p>
        </div>
      </main>`;
  }

  return renderAnswerForm(s);
}

function visibleQuestions(s){
  renumber(s);

  const all=s.groups.flatMap((g,gi)=>
    g.questions.map((q,qi)=>({q,gi,qi}))
  );

  /*
   * モック用条件分岐。
   * 回答した単一選択質問に分岐先がある場合、
   * 指定された質問を表示対象とする。
   */
  return all.filter(item=>{
    for(const other of all){
      if(other.q.type!=="single")continue;

      const ans=state.answerData[other.q.id];
      if(ans===undefined)continue;

      const oi=other.q.options.indexOf(ans);
      const target=other.q.branch?.[oi];

      if(target){
        const [tgi,tqi]=target.split(":").map(Number);
        if(item.gi===tgi && item.qi===tqi)return true;
      }
    }

    return true;
  });
}

function renderAnswerForm(s){
  const all=visibleQuestions(s);

  return `
    <main style="max-width:850px;margin:0 auto">
      <div class="card" style="margin-top:25px">
        <h1>${esc(s.title)}</h1>
        <p>${esc(s.description)}</p>
        <p style="font-size:12px;color:#64748b">
          ${formatDate(s.start)} ～ ${formatDate(s.end)}
        </p>
      </div>

      ${s.groups.map(g=>`
        <div class="card">
          <h2>${esc(g.title)}</h2>

          ${g.questions.map(q=>{
            const visible=all.some(x=>x.q.id===q.id);
            if(!visible)return "";

            return renderAnswerQuestion(q);
          }).join("")}
        </div>
      `).join("")}

      <div class="card">
        <div class="action-row" style="justify-content:space-between">
          <button class="btn" onclick="answerBack()">戻る</button>
          <button class="btn primary" onclick="answerConfirm()">回答確認</button>
        </div>
      </div>
    </main>
  `;
}

function renderAnswerQuestion(q){
  const value=state.answerData[q.id];

  return `
    <div class="field">
      <label>
        ${esc(q.number)} ${esc(q.text||"質問文未入力")}
        ${q.required?'<span style="color:#dc2626"> *</span>':""}
      </label>

      ${
        q.type==="single"
        ? q.options.map(o=>`
          <label class="preview-choice">
            <input type="radio"
              name="answer_${q.id}"
              value="${escapeForAttr(o)}"
              ${value===o?"checked":""}
              onchange="state.answerData[${q.id}]=this.value">
            ${esc(o)}
          </label>
        `).join("")
        : q.type==="multiple"
        ? q.options.map(o=>{
            const vals=Array.isArray(value)?value:[];
            return `
              <label class="preview-choice">
                <input type="checkbox"
                  value="${escapeForAttr(o)}"
                  ${vals.includes(o)?"checked":""}
                  onchange="toggleAnswerMulti(${q.id},this.value,this.checked)">
                ${esc(o)}
              </label>`;
          }).join("")
        : `
          <textarea style="width:100%"
            onchange="state.answerData[${q.id}]=this.value"
            placeholder="回答を入力してください">${esc(value||"")}</textarea>
        `
      }
    </div>
  `;
}

function toggleAnswerMulti(id,value,checked){
  if(!Array.isArray(state.answerData[id]))
    state.answerData[id]=[];

  if(checked){
    if(!state.answerData[id].includes(value))
      state.answerData[id].push(value);
  }else{
    state.answerData[id]=state.answerData[id].filter(x=>x!==value);
  }
  render();
}

function validateAnswers(){
  const s=getSurvey(state.editingId);
  const all=visibleQuestions(s);
  const missing=[];

  all.forEach(({q})=>{
    if(!q.required)return;

    const v=state.answerData[q.id];

    if(v===undefined || v==="" ||
      (Array.isArray(v)&&v.length===0)){
      missing.push(q.number);
    }
  });

  return missing;
}

function answerConfirm(){
  const missing=validateAnswers();

  if(missing.length){
    document.getElementById("modalRoot").innerHTML=`
      <div class="modal-backdrop">
        <div class="modal">
          <div class="modal-header">必須回答を確認してください</div>
          <div class="modal-body">
            未回答の必須項目があります。<br><br>
            ${missing.map(x=>`<span class="badge ng">${esc(x)}</span>`).join(" ")}
          </div>
          <div class="modal-footer">
            <button class="btn" onclick="closeModal()">閉じる</button>
          </div>
        </div>
      </div>`;
    return;
  }

  confirmModal(
    "回答送信",
    "入力内容を送信しますか？",
    ()=>{
      state.answerSubmitted=true;

      const s=getSurvey(state.editingId);
      s.responses++;

      const customer=customers[0];
      customer.status="回答済み";

      render();
    }
  );
}

function answerBack(){
  toast("回答画面の戻る操作です");
}

/* =========================================================
   グローバル操作
========================================================= */

function go(screen){
  state.screen=screen;
  render();
}

function bindGlobal(){
  /*
   * 一覧等から回答者画面を確認するための隠し機能ではなく、
   * モック操作確認用に画面下部のボタンを必要に応じて表示。
   */
}

/* =========================================================
   初期化
========================================================= */

applyAutoEnd();

render();

/*
 * モック確認用：
 * 回答者画面へ移動するにはブラウザコンソールから
 *
 * startAnswer(1)
 *
 * を実行できます。
 */
</script>

</body>
</html>
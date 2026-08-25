<?php
/*
 * アンケート管理システム インタラクティブモック
 * PHP 8.5 / Apache 2.4
 * DB / kintone API / SMTP / 認証なし
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
  --primary:#2563eb;--primary2:#1d4ed8;--success:#059669;
  --warning:#d97706;--danger:#dc2626;--gray:#64748b;
  --light:#f8fafc;--line:#e2e8f0;--text:#1e293b;
  --card:#fff;--shadow:0 4px 18px rgba(15,23,42,.08);
}
*{box-sizing:border-box}
body{margin:0;background:#f1f5f9;color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.hidden{display:none!important}
.admin-header{
  position:sticky;top:0;z-index:50;background:#0f172a;color:#fff;
  display:flex;align-items:center;gap:8px;padding:12px 20px;box-shadow:0 2px 8px #0002
}
.logo{font-weight:800;margin-right:20px;white-space:nowrap}
.nav-btn{background:transparent;color:#cbd5e1;border:0;padding:9px 12px;border-radius:7px}
.nav-btn:hover,.nav-btn.active{background:#1e293b;color:#fff}
.logout{margin-left:auto}
.container{max-width:1440px;margin:auto;padding:24px}
.page-title{font-size:25px;font-weight:800;margin:0 0 20px}
.page-subtitle{color:var(--gray);margin:-12px 0 20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px;box-shadow:var(--shadow);margin-bottom:18px}
.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.toolbar .grow{flex:1}
input[type=text],input[type=email],input[type=password],input[type=datetime-local],
input[type=number],textarea,select{
  width:100%;border:1px solid #cbd5e1;border-radius:7px;padding:9px 11px;
  background:#fff;color:var(--text)
}
textarea{min-height:110px;resize:vertical}
label{display:block;font-weight:700;margin-bottom:6px}
.form-row{margin-bottom:16px}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.btn{
  border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:7px;
  padding:9px 14px;font-weight:700
}
.btn:hover{background:#f8fafc}
.btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn-primary:hover{background:var(--primary2)}
.btn-danger{background:#fff;color:var(--danger);border-color:#fecaca}
.btn-success{background:var(--success);color:#fff;border-color:var(--success)}
.btn-warning{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
.btn-sm{padding:6px 9px;font-size:13px}
.actions{display:flex;gap:6px;flex-wrap:wrap}
.status{
 display:inline-flex;align-items:center;padding:4px 9px;border-radius:99px;
 font-size:12px;font-weight:800;white-space:nowrap
}
.status-draft{background:#f1f5f9;color:#475569}
.status-open{background:#dcfce7;color:#166534}
.status-stop{background:#fef3c7;color:#92400e}
.status-end{background:#fee2e2;color:#991b1b}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid var(--line);padding:12px 9px;text-align:left;vertical-align:middle}
th{background:#f8fafc;font-size:13px;white-space:nowrap}
td{font-size:14px}
.table-wrap{overflow:auto}
.alert{padding:12px 14px;border-radius:8px;margin:10px 0;font-weight:600}
.alert-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.alert-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.section-title{font-size:18px;font-weight:800;margin:0 0 15px}
.top-actions{
 display:flex;align-items:center;gap:10px;margin-bottom:18px;
 padding-bottom:16px;border-bottom:1px solid var(--line)
}
.top-actions .state{margin-left:auto;display:flex;align-items:center;gap:8px;min-width:230px}
.question-card,.group-card{
 background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:12px
}
.group-card{background:#f8fafc}
.group-head{display:flex;gap:10px;align-items:center;margin-bottom:12px}
.group-head input{font-weight:800}
.drag-handle{cursor:grab;color:#94a3b8;font-size:20px}
.question-head{display:flex;align-items:center;gap:8px;margin-bottom:12px}
.question-head .qno{font-weight:900;color:var(--primary);min-width:50px}
.question-head .spacer{flex:1}
.choice-row{display:flex;gap:7px;margin:7px 0}
.choice-row input{flex:1}
.radio-row{display:flex;gap:20px;align-items:center;flex-wrap:wrap}
.radio-row label{font-weight:500;margin:0}
.badge{padding:3px 7px;border-radius:5px;font-size:11px;font-weight:800}
.badge-ok{background:#dcfce7;color:#166534}
.badge-no{background:#fee2e2;color:#991b1b}
.tabs{display:flex;border-bottom:1px solid var(--line);margin-bottom:18px;gap:4px}
.tab{border:0;background:transparent;padding:11px 16px;font-weight:800;color:#64748b;border-bottom:2px solid transparent}
.tab.active{color:var(--primary);border-bottom-color:var(--primary)}
.kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.kpi{background:#fff;border:1px solid var(--line);border-radius:10px;padding:15px}
.kpi .num{font-size:27px;font-weight:900;margin-top:5px}
.kpi .label{font-size:12px;color:var(--gray)}
.bar{height:12px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin-top:5px}
.bar>span{display:block;height:100%;background:var(--primary)}
.preview-frame{border:1px solid #cbd5e1;border-radius:12px;background:#e2e8f0;padding:20px}
.preview-device{background:#fff;margin:auto;min-height:500px;padding:25px;border-radius:8px;transition:.2s}
.preview-device.mobile{max-width:390px}
.preview-device.pc{max-width:100%}
.answer-progress{height:7px;background:#e2e8f0;border-radius:99px;margin-bottom:22px}
.answer-progress span{display:block;height:100%;background:var(--primary);border-radius:99px}
.answer-choice{display:block;border:1px solid #cbd5e1;padding:13px;border-radius:8px;margin:8px 0;cursor:pointer}
.answer-choice:hover{background:#f8fafc}
.answer-choice input{width:auto;margin-right:8px}
.modal-backdrop{
 position:fixed;inset:0;background:#0008;z-index:100;display:flex;align-items:center;
 justify-content:center;padding:20px
}
.modal{background:#fff;border-radius:12px;width:min(520px,100%);box-shadow:0 20px 60px #0005;padding:22px}
.modal h3{margin:0 0 10px}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}
.toast{
 position:fixed;right:20px;bottom:20px;z-index:200;background:#0f172a;color:#fff;
 padding:13px 18px;border-radius:8px;box-shadow:var(--shadow)
}
.mail-preview{white-space:pre-wrap;background:#f8fafc;border:1px solid var(--line);padding:14px;border-radius:8px}
.history-item{border:1px solid var(--line);border-radius:8px;padding:13px;margin-bottom:8px}
.muted{color:var(--gray)}
.center{text-align:center}
.empty{padding:45px;text-align:center;color:#64748b}
.small{font-size:12px}
.dnd-over{border:2px dashed var(--primary)!important;background:#eff6ff!important}
.readonly{background:#f1f5f9!important}
.setting-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.checkbox-list{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
.checkbox-list label{font-weight:500}
@media(max-width:900px){
 .kpi-grid{grid-template-columns:repeat(2,1fr)}
 .form-grid,.setting-grid{grid-template-columns:1fr}
 .admin-header{overflow:auto}
 .logo{margin-right:5px}
 .container{padding:14px}
}
@media(max-width:600px){
 .kpi-grid{grid-template-columns:1fr 1fr}
 .top-actions{align-items:stretch;flex-wrap:wrap}
 .top-actions .state{margin-left:0;width:100%}
 .toolbar{align-items:stretch}
 .toolbar>*{width:100%}
 .toolbar .grow{flex:none}
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
const now = new Date();

const state = {
  page:"list",
  selectedSurveyId:null,
  editId:null,
  editDraft:null,
  answerStep:0,
  answerAnswers:{},
  previewMode:"pc",
  admin:true,
  listSearch:"",
  listStatus:"all",
  sortKey:"updated_desc",
  sendTab:"customers",
  selectedCustomers:[],
  sendResult:null,
  sendHistory:[],
  kintone:{
    subdomain:"",
    appId:"",
    login:"",
    password:"",
    ssl:true,
    connection:"未設定",
    fields:[],
    mapped:{org:"",name:"",email:"",dept:"",tel:"",address:[]},
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
  }
};

const surveys = [
 {
  id:1,title:"2026年度 顧客満足度アンケート",
  description:"サービスに関する満足度をお聞かせください。",
  start:"2026-04-01T09:00",end:"2026-12-31T23:59",
  status:"公開中",created:"2026/03/10",updated:"2026/08/24",
  responses:128,allowReanswer:false,numbering:"global",
  groups:[
   {id:11,title:"基本評価",questions:[
    {id:101,text:"サービス全体の満足度を教えてください。",type:"single",required:true,
     choices:["とても満足","満足","普通","不満","とても不満"],branch:{}},
    {id:102,text:"特に良かった点を教えてください。",type:"multi",required:false,
     choices:["品質","価格","サポート","使いやすさ"],branch:{}}
   ]},
   {id:12,title:"ご意見",questions:[
    {id:103,text:"ご意見・ご要望があればご記入ください。",type:"text",required:false,choices:[],branch:{}}
   ]}
  ]
 },
 {
  id:2,title:"2026年度 サービス改善アンケート",
  description:"今後のサービス改善に向けたアンケートです。",
  start:"2026-07-01T09:00",end:"2026-08-10T23:59",
  status:"下書き",created:"2026/07/01",updated:"2026/08/20",
  responses:0,allowReanswer:false,numbering:"global",
  groups:[
   {id:21,title:"サービスについて",questions:[
    {id:201,text:"現在のサービスについて教えてください。",type:"single",required:true,
     choices:["満足","普通","不満"],branch:{}}
   ]}
  ]
 },
 {
  id:3,title:"2026年 上期フォローアップ",
  description:"上期のお客様フォローアップです。",
  start:"2026-05-01T09:00",end:"2026-08-10T23:59",
  status:"停止",created:"2026/04/20",updated:"2026/08/18",
  responses:54,allowReanswer:false,numbering:"group",
  groups:[
   {id:31,title:"第1グループ",questions:[
    {id:301,text:"担当者の対応はいかがでしたか？",type:"single",required:true,
     choices:["非常に良い","良い","普通","悪い"],branch:{}},
    {id:302,text:"改善点があれば教えてください。",type:"text",required:false,choices:[],branch:{}}
   ]},
   {id:32,title:"第2グループ",questions:[
    {id:303,text:"今後も利用したいですか？",type:"single",required:true,
     choices:["はい","いいえ"],branch:{}}
   ]}
  ]
 },
 {
  id:4,title:"2025年度 顧客満足度アンケート",
  description:"終了済みアンケートのサンプルです。",
  start:"2025-04-01T09:00",end:"2025-08-10T23:59",
  status:"終了",created:"2025/03/01",updated:"2025/08/11",
  responses:210,allowReanswer:false,numbering:"global",
  groups:[
   {id:41,title:"評価",questions:[
    {id:401,text:"昨年度のサービスに満足しましたか？",type:"single",required:true,
     choices:["はい","いいえ"],branch:{}}
   ]}
  ]
 }
];

const customers = [
 {id:1,org:"株式会社サンプル",name:"山田 太郎",email:"taro@example.com",tel:"03-1111-1111",address:"東京都港区",sent:"2026/08/20 10:10",count:1,status:"送信済み / 未回答",kintone:true},
 {id:2,org:"株式会社テスト",name:"佐藤 花子",email:"hanako@example.com",tel:"03-2222-2222",address:"東京都新宿区",sent:"2026/08/21 14:20",count:2,status:"回答済み",kintone:true},
 {id:3,org:"合同会社モック",name:"鈴木 一郎",email:"ichiro@example.com",tel:"03-3333-3333",address:"東京都渋谷区",sent:"-",count:0,status:"未送信",kintone:false},
 {id:4,org:"株式会社デモ",name:"田中 次郎",email:"jiro@example.com",tel:"03-4444-4444",address:"東京都千代田区",sent:"2026/08/22 09:30",count:1,status:"送信済み / 未回答",kintone:true},
 {id:5,org:"有限会社サンプル商事",name:"高橋 美咲",email:"misaki@example.com",tel:"03-5555-5555",address:"東京都品川区",sent:"-",count:0,status:"未送信",kintone:false}
];

const defaultMailSubject = "【アンケートのお願い】{顧客名}様";
const defaultMailBody = `{顧客名}様

いつもお世話になっております。

以下のURLよりアンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力のほど、よろしくお願いいたします。`;

/* =========================================================
   共通
========================================================= */
function esc(v){
 return String(v??"").replace(/[&<>"']/g,m=>({
  "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"
 }[m]));
}
function clone(v){return JSON.parse(JSON.stringify(v))}
function uid(){return Date.now()+Math.floor(Math.random()*10000)}
function surveyById(id){return surveys.find(s=>s.id==id)}
function formatDate(v){
 if(!v)return "-";
 return String(v).replace("T"," ");
}
function statusClass(s){
 return s==="公開中"?"status-open":s==="下書き"?"status-draft":s==="停止"?"status-stop":"status-end";
}
function showToast(msg,type="info"){
 const root=document.getElementById("toastRoot");
 root.innerHTML=`<div class="toast">${esc(msg)}</div>`;
 setTimeout(()=>root.innerHTML="",2400);
}
function confirmDialog(title,message,callback){
 document.getElementById("modalRoot").innerHTML=`
 <div class="modal-backdrop" id="modal">
  <div class="modal">
   <h3>${esc(title)}</h3>
   <div>${esc(message)}</div>
   <div class="modal-actions">
    <button class="btn" onclick="closeModal()">キャンセル</button>
    <button class="btn btn-primary" id="modalExec">実行</button>
   </div>
  </div>
 </div>`;
 document.getElementById("modalExec").onclick=()=>{closeModal();callback()};
}
function closeModal(){document.getElementById("modalRoot").innerHTML=""}

function applyAutomaticEnd(){
 const current=new Date();
 surveys.forEach(s=>{
  if(s.status==="公開中" && s.end){
   const end=new Date(s.end);
   if(current>end)s.status="終了";
  }
 });
}
applyAutomaticEnd();

function render(){
 applyAutomaticEnd();
 document.getElementById("app").innerHTML=
  state.admin ? adminHeader()+`<main class="container">${pageHtml()}</main>` : pageHtml();
 bindGlobal();
}

function adminHeader(){
 const nav=[
  ["list","アンケート一覧"],
  ["kintone","kintone連携設定"],
  ["mailserver","メールサーバ設定"]
 ];
 return `<header class="admin-header">
   <div class="logo">📋 アンケート管理</div>
   ${nav.map(n=>`<button class="nav-btn ${state.page===n[0]?"active":""}" data-page="${n[0]}">${n[1]}</button>`).join("")}
   <button class="nav-btn logout" onclick="showToast('ログアウトしました')">ログアウト</button>
 </header>`;
}

function pageHtml(){
 switch(state.page){
  case "list":return listPage();
  case "edit":return editPage();
  case "preview":return previewPage();
  case "send":return sendPage();
  case "summary":return summaryPage();
  case "kintone":return kintonePage();
  case "mailserver":return mailServerPage();
  case "answer":return answerPage();
  case "confirm":return answerConfirmPage();
  case "complete":return completePage();
  default:return listPage();
 }
}

function bindGlobal(){
 document.querySelectorAll("[data-page]").forEach(b=>{
  b.onclick=()=>{state.page=b.dataset.page;state.selectedSurveyId=null;render()}
 });
}

/* =========================================================
   一覧
========================================================= */
function listPage(){
 let list=surveys.filter(s=>{
  const q=state.listSearch.toLowerCase();
  return (!q||s.title.toLowerCase().includes(q)) &&
    (state.listStatus==="all"||s.status===state.listStatus);
 });
 list.sort((a,b)=>{
  switch(state.sortKey){
   case "updated_asc":return a.updated.localeCompare(b.updated);
   case "responses_desc":return b.responses-a.responses;
   case "responses_asc":return a.responses-b.responses;
   case "start_desc":return b.start.localeCompare(a.start);
   case "start_asc":return a.start.localeCompare(b.start);
   default:return b.updated.localeCompare(a.updated);
  }
 });
 return `
 <h1 class="page-title">アンケート一覧</h1>
 <div class="toolbar">
  <button class="btn btn-primary" onclick="newSurvey()">＋ 新規アンケート作成</button>
  <div class="grow"></div>
  <input id="searchInput" type="text" placeholder="タイトルで検索（Enter）" value="${esc(state.listSearch)}" style="max-width:280px">
  <select id="statusFilter" style="max-width:150px">
   ${filterOption("all","すべて")}
   ${filterOption("公開中","公開中")}
   ${filterOption("下書き","下書き")}
   ${filterOption("停止","停止")}
   ${filterOption("終了","終了")}
  </select>
  <select id="sortSelect" style="max-width:210px">
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
    <thead><tr>
     <th>作成日 / 更新日</th><th>タイトル</th><th>アンケート期間</th>
     <th>ステータス</th><th>回答数</th><th>操作</th>
    </tr></thead>
    <tbody>
     ${list.length?list.map(surveyRow).join(""):
       `<tr><td colspan="6" class="empty">該当するアンケートがありません</td></tr>`}
    </tbody>
   </table>
  </div>
 </div>
 <div class="card">
  <div class="section-title">状態自動終了テスト</div>
  <div class="muted small">
   「公開中＋過去の終了日時」のサンプルを用意すると、公開中だけが終了になります。
   下書き・停止は終了日時を過ぎても状態を維持します。
  </div>
 </div>`;
}
function filterOption(v,t){return `<option value="${v}" ${state.listStatus===v?"selected":""}>${t}</option>`}
function sortOption(v,t){return `<option value="${v}" ${state.sortKey===v?"selected":""}>${t}</option>`}

function surveyRow(s){
 return `<tr>
  <td>${esc(s.created)}<br><span class="muted small">${esc(s.updated)}</span></td>
  <td><strong>${esc(s.title)}</strong></td>
  <td>${formatDate(s.start)}<br>～ ${formatDate(s.end)}</td>
  <td><span class="status ${statusClass(s.status)}">${esc(s.status)}</span></td>
  <td>${s.responses}</td>
  <td>
   <div class="actions">
    <button class="btn btn-sm" onclick="editSurvey(${s.id})">確認・編集</button>
    <button class="btn btn-sm" onclick="openSummary(${s.id})">集計</button>
    <button class="btn btn-sm" onclick="openSend(${s.id})">送信</button>
    <button class="btn btn-sm" onclick="duplicateSurvey(${s.id})">複製</button>
    <button class="btn btn-sm btn-danger" onclick="deleteSurvey(${s.id})">削除</button>
   </div>
  </td>
 </tr>`;
}

document.addEventListener("keydown",e=>{
 if(e.key==="Enter" && document.activeElement?.id==="searchInput"){
  state.listSearch=document.activeElement.value;render();
 }
});
document.addEventListener("change",e=>{
 if(e.target.id==="statusFilter"){state.listStatus=e.target.value;render()}
 if(e.target.id==="sortSelect"){state.sortKey=e.target.value;render()}
});

/* =========================================================
   CRUD
========================================================= */
function newSurvey(){
 state.editId=null;
 state.editDraft={
  id:null,title:"",description:"",start:"",end:"",
  status:"下書き",created:"",updated:"",
  responses:0,allowReanswer:false,numbering:"global",groups:[]
 };
 addGroup(false);
 state.page="edit";render();
}
function editSurvey(id){
 const s=surveyById(id);if(!s)return;
 state.editId=id;state.editDraft=clone(s);state.page="edit";render();
}
function duplicateSurvey(id){
 const s=surveyById(id);
 confirmDialog("アンケートを複製","このアンケートを下書きとして複製しますか？",()=>{
  const d=clone(s);
  d.id=uid();d.status="下書き";d.responses=0;
  d.created=new Date().toLocaleDateString("ja-JP");
  d.updated=d.created;
  surveys.push(d);
  showToast("アンケートを複製しました");
  render();
 });
}
function deleteSurvey(id){
 const s=surveyById(id);
 confirmDialog("アンケートを削除",`「${s.title}」を削除しますか？`,()=>{
  const i=surveys.findIndex(x=>x.id===id);
  if(i>=0)surveys.splice(i,1);
  showToast("削除しました");render();
 });
}

/* =========================================================
   編集
========================================================= */
function editPage(){
 const s=state.editDraft;
 const ended=s.status==="終了";
 return `
 <h1 class="page-title">アンケート作成・編集</h1>
 <div class="card">
  <div class="top-actions">
   <button class="btn" onclick="cancelEdit()">キャンセル</button>
   <button class="btn btn-primary" onclick="saveSurvey()">保存して一覧へ</button>
   <div class="state">
    <strong>状態：</strong>
    <select id="editStatus" ${ended?"disabled":""}>
     ${editableStatuses(s.status)}
    </select>
   </div>
  </div>

  ${ended?`<div class="alert alert-info">このアンケートは終了しています。状態変更はできません。</div>`:""}

  <div class="section-title">基本情報</div>
  <div class="form-row">
   <label>アンケートタイトル</label>
   <input id="editTitle" value="${esc(s.title)}">
  </div>
  <div class="form-row">
   <label>アンケート説明</label>
   <textarea id="editDescription">${esc(s.description)}</textarea>
  </div>
  <div class="form-grid">
   <div class="form-row">
    <label>開始日時</label>
    <input id="editStart" type="datetime-local" value="${esc(s.start)}">
   </div>
   <div class="form-row">
    <label>終了日時</label>
    <input id="editEnd" type="datetime-local" value="${esc(s.end)}">
   </div>
  </div>
  <div class="form-row">
   <label>質問番号の採番方式</label>
   <div class="radio-row">
    <label><input type="radio" name="numbering" value="global" ${s.numbering==="global"?"checked":""} onchange="changeNumbering('global')"> アンケート全体で通番</label>
    <label><input type="radio" name="numbering" value="group" ${s.numbering==="group"?"checked":""} onchange="changeNumbering('group')"> グループ毎に採番</label>
   </div>
  </div>
 </div>

 <div class="card">
  <div class="section-title">グループ・質問</div>
  <div id="groupsArea">
   ${s.groups.map((g,gi)=>groupHtml(g,gi)).join("")}
  </div>
  <button class="btn btn-primary" onclick="addGroup()">＋ グループを追加</button>
 </div>

 <div class="card">
  <label style="display:flex;gap:8px;align-items:center;font-weight:600">
   <input type="checkbox" ${s.allowReanswer?"checked":""} onchange="state.editDraft.allowReanswer=this.checked">
   個別回答URLの再回答を許可する
  </label>
 </div>

 <div class="actions">
  <button class="btn" onclick="cancelEdit()">キャンセル</button>
  <button class="btn btn-primary" onclick="saveSurvey()">保存して一覧へ</button>
  <button class="btn" onclick="openPreviewFromEdit()">プレビュー</button>
 </div>`;
}
function editableStatuses(current){
 let options=[];
 if(current==="終了")return `<option>終了</option>`;
 if(current==="下書き")options=[["下書き","下書き"],["公開中","公開"]];
 else if(current==="公開中")options=[["公開中","公開中"],["停止","停止"]];
 else if(current==="停止")options=[["停止","停止"],["公開中","再開"]];
 return options.map(x=>`<option value="${x[0]}" ${current===x[0]?"selected":""}>${x[1]}</option>`).join("");
}

document.addEventListener("change",e=>{
 if(e.target.id==="editStatus" && state.editDraft){
  const old=state.editDraft.status,newStatus=e.target.value;
  if(old===newStatus)return;
  const labels={公開中:"公開",停止:"停止"};
  const msg=newStatus==="公開中"?"このアンケートを公開しますか？":
   newStatus==="停止"?"このアンケートを停止しますか？":
   "このアンケートを再開しますか？";
  confirmDialog(labels[newStatus]||"状態変更",msg,()=>{
   state.editDraft.status=newStatus;render();
  });
 }
});

function syncBasic(){
 const s=state.editDraft;
 s.title=document.getElementById("editTitle")?.value||"";
 s.description=document.getElementById("editDescription")?.value||"";
 s.start=document.getElementById("editStart")?.value||"";
 s.end=document.getElementById("editEnd")?.value||"";
}
function saveSurvey(){
 syncBasic();
 if(!state.editDraft.title.trim()){
  showToast("タイトルを入力してください");return;
 }
 const d=state.editDraft;
 d.updated=new Date().toLocaleDateString("ja-JP");
 if(!d.id){
  d.id=uid();d.created=d.updated;d.status="下書き";
  surveys.push(clone(d));
 }else{
  const i=surveys.findIndex(x=>x.id===d.id);
  surveys[i]=clone(d);
 }
 showToast("保存しました");
 state.page="list";state.editDraft=null;render();
}
function cancelEdit(){
 confirmDialog("変更を破棄","編集内容を破棄して前の画面へ戻りますか？",()=>{
  state.page="list";state.editDraft=null;render();
 });
}
function changeNumbering(v){
 state.editDraft.numbering=v;render();
}
function addGroup(scroll=true){
 state.editDraft.groups.push({id:uid(),title:`グループ${state.editDraft.groups.length+1}`,questions:[]});
 renumber();
 render();
}
function deleteGroup(id){
 const g=state.editDraft.groups.find(x=>x.id===id);
 confirmDialog("グループを削除",g.questions.length?
  "このグループには質問があります。削除しますか？":"このグループを削除しますか？",()=>{
   state.editDraft.groups=state.editDraft.groups.filter(x=>x.id!==id);
   renumber();render();
 });
}
function updateGroup(id,key,value){
 const g=state.editDraft.groups.find(x=>x.id===id);if(g)g[key]=value;
}
function addQuestion(groupId){
 const g=state.editDraft.groups.find(x=>x.id===groupId);
 if(!g)return;
 g.questions.push({
  id:uid(),text:"",type:"single",required:false,choices:["選択肢1","選択肢2"],branch:{}
 });
 renumber();render();
}
function deleteQuestion(gid,qid){
 confirmDialog("質問を削除","この質問を削除しますか？",()=>{
  const g=state.editDraft.groups.find(x=>x.id===gid);
  g.questions=g.questions.filter(q=>q.id!==qid);
  renumber();render();
 });
}
function updateQuestion(gid,qid,key,value){
 const q=findQuestion(gid,qid);if(q)q[key]=value;
}
function findQuestion(gid,qid){
 const g=state.editDraft.groups.find(x=>x.id===gid);
 return g?.questions.find(x=>x.id===qid);
}
function addChoice(gid,qid){
 const q=findQuestion(gid,qid);if(q)q.choices.push("新しい選択肢");
 render();
}
function removeChoice(gid,qid,index){
 const q=findQuestion(gid,qid);if(q)q.choices.splice(index,1);
 render();
}
function updateChoice(gid,qid,i,v){
 const q=findQuestion(gid,qid);if(q)q.choices[i]=v;
}
function groupHtml(g,gi){
 return `<div class="group-card" draggable="true"
   ondragstart="dragGroup(event,${g.id})" ondragover="allowDrop(event)" ondrop="dropGroup(event,${g.id})">
   <div class="group-head">
    <span class="drag-handle">☷</span>
    <input value="${esc(g.title)}" onchange="updateGroup(${g.id},'title',this.value)">
    <button class="btn btn-sm btn-danger" onclick="deleteGroup(${g.id})">削除</button>
   </div>
   ${g.questions.map((q,qi)=>questionHtml(g,q,gi,qi)).join("")}
   <button class="btn btn-sm btn-primary" onclick="addQuestion(${g.id})">＋ 質問を追加</button>
  </div>`;
}
function questionHtml(g,q,gi,qi){
 const number=getQuestionNumber(state.editDraft,g.id,q.id);
 return `<div class="question-card" draggable="true"
   ondragstart="dragQuestion(event,${g.id},${q.id})"
   ondragover="allowDrop(event)" ondrop="dropQuestion(event,${g.id},${q.id})">
  <div class="question-head">
   <span class="drag-handle">⋮⋮</span>
   <span class="qno">${number}</span>
   <span class="spacer"></span>
   <button class="btn btn-sm btn-danger" onclick="deleteQuestion(${g.id},${q.id})">削除</button>
  </div>
  <div class="form-row">
   <label>質問文</label>
   <textarea onchange="updateQuestion(${g.id},${q.id},'text',this.value)">${esc(q.text)}</textarea>
  </div>
  <div class="form-grid">
   <div>
    <label>回答形式</label>
    <select onchange="changeQuestionType(${g.id},${q.id},this.value)">
     <option value="single" ${q.type==="single"?"selected":""}>単一選択</option>
     <option value="multi" ${q.type==="multi"?"selected":""}>複数選択</option>
     <option value="text" ${q.type==="text"?"selected":""}>自由記述</option>
    </select>
   </div>
   <div style="display:flex;align-items:end">
    <label style="font-weight:600"><input type="checkbox" ${q.required?"checked":""}
      onchange="updateQuestion(${g.id},${q.id},'required',this.checked)"> 必須回答</label>
   </div>
  </div>
  ${q.type!=="text"?`
   <div class="form-row">
    <label>選択肢</label>
    ${q.choices.map((c,i)=>`<div class="choice-row">
      <input value="${esc(c)}" onchange="updateChoice(${g.id},${q.id},${i},this.value)">
      <button class="btn btn-sm btn-danger" onclick="removeChoice(${g.id},${q.id},${i})">削除</button>
    </div>`).join("")}
    <button class="btn btn-sm" onclick="addChoice(${g.id},${q.id})">＋ 選択肢追加</button>
   </div>`:""}
  ${q.type==="single"?branchHtml(g,q):""}
 </div>`;
}
function branchHtml(g,q){
 const choices=q.choices||[];
 return `<div class="form-row">
  <label>条件分岐（選択肢ごとの次の質問）</label>
  ${choices.map((c,i)=>`
   <div class="choice-row">
    <div style="width:45%;padding:9px 0">${esc(c)}</div>
    <select onchange="setBranch(${q.id},${i},this.value)">
     <option value="">次の質問：通常</option>
     ${allQuestions().filter(x=>x.q.id!==q.id).map(x=>
       `<option value="${x.q.id}" ${q.branch?.[i]==x.q.id?"selected":""}>${getQuestionNumber(state.editDraft,x.g.id,x.q.id)} ${esc(x.q.text||"未入力")}</option>`
     ).join("")}
    </select>
   </div>`).join("")}
 </div>`;
}
function allQuestions(){
 const out=[];
 state.editDraft.groups.forEach(g=>g.questions.forEach(q=>out.push({g,q})));
 return out;
}
function setBranch(qid,index,val){
 const item=allQuestions().find(x=>x.q.id===qid);
 if(item){item.q.branch=item.q.branch||{};item.q.branch[index]=val?Number(val):""}
}
function changeQuestionType(gid,qid,type){
 const q=findQuestion(gid,qid);q.type=type;
 if(type==="text")q.choices=[];
 else if(!q.choices.length)q.choices=["選択肢1","選択肢2"];
 render();
}

/* =========================================================
   採番・D&D
========================================================= */
function renumber(){}
function getQuestionNumber(s,gid,qid){
 let n=0;
 if(s.numbering==="global"){
  for(const g of s.groups){
   for(const q of g.questions){
    n++;
    if(q.id===qid)return "Q"+n;
   }
  }
 }else{
  const gi=s.groups.findIndex(g=>g.id===gid)+1;
  const g=s.groups.find(x=>x.id===gid);
  const qi=g?.questions.findIndex(q=>q.id===qid)+1;
  return `Q${gi}-${qi}`;
 }
 return "";
}
let dragData=null;
function dragGroup(e,id){dragData={type:"group",id}}
function dragQuestion(e,gid,qid){dragData={type:"question",gid,qid}}
function allowDrop(e){e.preventDefault();e.currentTarget.classList.add("dnd-over")}
function dropGroup(e,targetId){
 e.preventDefault();e.currentTarget.classList.remove("dnd-over");
 if(!dragData||dragData.type!=="group"||dragData.id===targetId)return;
 const arr=state.editDraft.groups;
 const a=arr.findIndex(x=>x.id===dragData.id),b=arr.findIndex(x=>x.id===targetId);
 const [x]=arr.splice(a,1);arr.splice(b,0,x);render();
}
function dropQuestion(e,targetGid,targetQid){
 e.preventDefault();e.currentTarget.classList.remove("dnd-over");
 if(!dragData||dragData.type!=="question")return;
 const source=state.editDraft.groups.find(g=>g.id===dragData.gid);
 const target=state.editDraft.groups.find(g=>g.id===targetGid);
 if(!source||!target)return;
 const si=source.questions.findIndex(q=>q.id===dragData.qid);
 const [q]=source.questions.splice(si,1);
 const ti=target.questions.findIndex(q=>q.id===targetQid);
 target.questions.splice(ti<0?target.questions.length:ti,0,q);
 renumber();render();
}

/* =========================================================
   プレビュー
========================================================= */
function openPreviewFromEdit(){
 syncBasic();state.page="preview";render();
}
function previewPage(){
 const s=state.editDraft;
 return `<h1 class="page-title">プレビュー</h1>
 <div class="toolbar">
  <button class="btn ${state.previewMode==="pc"?"btn-primary":""}" onclick="state.previewMode='pc';render()">PC表示</button>
  <button class="btn ${state.previewMode==="mobile"?"btn-primary":""}" onclick="state.previewMode='mobile';render()">スマートフォン表示</button>
  <button class="btn" onclick="state.page='edit';render()">編集へ戻る</button>
 </div>
 <div class="preview-frame">
  <div class="preview-device ${state.previewMode}">
   ${answerSurveyHtml(s,true)}
  </div>
 </div>`;
}

/* =========================================================
   回答者
========================================================= */
function startAnswer(id){
 const s=surveyById(id);
 if(!s)return;
 state.admin=false;state.selectedSurveyId=id;state.answerStep=0;
 state.answerAnswers={};state.page="answer";render();
}
function answerSurveyHtml(s,preview=false){
 return `<div>
  <h1>${esc(s.title)}</h1>
  <p class="muted">${esc(s.description)}</p>
  <div class="alert alert-info">アンケート期間：${formatDate(s.start)} ～ ${formatDate(s.end)}</div>
  ${s.groups.map(g=>`
   <div style="margin-top:28px">
    <h2>${esc(g.title)}</h2>
    ${g.questions.map(q=>answerQuestionHtml(s,q)).join("")}
   </div>`).join("")}
  <div class="actions" style="margin-top:25px">
   <button class="btn btn-primary" onclick="${preview?"showToast('プレビュー送信は実行されません'):"nextAnswer()"}">次へ</button>
  </div>
 </div>`;
}
function answerQuestionHtml(s,q){
 const number=getQuestionNumber(s,findGroupId(s,q.id),q.id);
 const value=state.answerAnswers[q.id];
 return `<div class="form-row" style="margin-top:20px">
  <label>${number} ${esc(q.text)} ${q.required?'<span style="color:#dc2626">＊必須</span>':""}</label>
  ${q.type==="single"?q.choices.map((c,i)=>`
   <label class="answer-choice"><input type="radio" name="q${q.id}" value="${esc(c)}"
    ${value===c?"checked":""} onchange="answerValue(${q.id},this.value)"> ${esc(c)}</label>`).join(""):
   q.type==="multi"?q.choices.map(c=>`
   <label class="answer-choice"><input type="checkbox" value="${esc(c)}"
    ${(value||[]).includes(c)?"checked":""} onchange="answerMulti(${q.id},this.value,this.checked)"> ${esc(c)}</label>`).join(""):
   `<textarea onchange="answerValue(${q.id},this.value)">${esc(value||"")}</textarea>`}
 </div>`;
}
function findGroupId(s,qid){
 for(const g of s.groups)if(g.questions.some(q=>q.id===qid))return g.id;
 return null;
}
function answerValue(id,v){state.answerAnswers[id]=v}
function answerMulti(id,v,checked){
 const a=state.answerAnswers[id]||[];
 if(checked&&!a.includes(v))a.push(v);
 if(!checked){const i=a.indexOf(v);if(i>=0)a.splice(i,1)}
 state.answerAnswers[id]=a;
}
function nextAnswer(){
 const s=surveyById(state.selectedSurveyId);
 for(const g of s.groups)for(const q of g.questions){
  if(q.required){
   const v=state.answerAnswers[q.id];
   if(v===undefined||v===""||(Array.isArray(v)&&!v.length)){
    showToast(`${getQuestionNumber(s,g.id,q.id)} は必須回答です`);return;
   }
  }
 }
 state.page="confirm";render();
}
function answerConfirmPage(){
 const s=surveyById(state.selectedSurveyId);
 return `<div style="max-width:800px;margin:40px auto">
  <h1 class="page-title">回答確認</h1>
  <div class="card">
   ${s.groups.flatMap(g=>g.questions).map(q=>{
    const v=state.answerAnswers[q.id];
    return `<div style="padding:13px 0;border-bottom:1px solid var(--line)">
      <strong>${getQuestionNumber(s,findGroupId(s,q.id),q.id)} ${esc(q.text)}</strong>
      <div style="margin-top:6px">${esc(Array.isArray(v)?v.join("、"):v||"未回答")}</div>
      <button class="btn btn-sm" onclick="state.page='answer';render()">修正</button>
    </div>`;
   }).join("")}
  </div>
  <div class="actions">
   <button class="btn" onclick="state.page='answer';render()">戻る</button>
   <button class="btn btn-primary" onclick="submitAnswer()">回答を送信する</button>
  </div>
 </div>`;
}
function submitAnswer(){
 confirmDialog("回答を送信","回答を送信します。よろしいですか？",()=>{
  state.page="complete";render();
 });
}
function completePage(){
 return `<div style="max-width:650px;margin:90px auto;text-align:center">
  <div class="card">
   <div style="font-size:55px">✓</div>
   <h1>回答ありがとうございました</h1>
   <p class="muted">アンケートへの回答を受け付けました。</p>
  </div>
 </div>`;
}

/* =========================================================
   顧客送信
========================================================= */
function openSend(id){
 state.selectedSurveyId=id;state.sendTab="customers";state.sendResult=null;state.selectedCustomers=[];state.page="send";render();
}
function sendPage(){
 const s=surveyById(state.selectedSurveyId);
 if(!s){state.page="list";return `<div/>`;}
 return `<h1 class="page-title">顧客選択・メール送信</h1>
 <div class="card">
  <div class="section-title">送信対象アンケート</div>
  <strong style="font-size:20px">${esc(s.title)}</strong>
 </div>
 <div class="tabs">
  <button class="tab ${state.sendTab==="customers"?"active":""}" onclick="state.sendTab='customers';render()">顧客選択・送信</button>
  <button class="tab ${state.sendTab==="history"?"active":""}" onclick="state.sendTab='history';render()">送信履歴</button>
 </div>
 ${state.sendTab==="customers"?sendCustomersHtml(s):historyHtml()}`;
}
function sendCustomersHtml(s){
 let filtered=customers;
 return `
 ${state.sendResult?`<div class="alert ${state.sendResult.fail?"alert-error":"alert-success"}">
  送信結果：対象 ${state.sendResult.total}件 / 成功 ${state.sendResult.success}件 / 失敗 ${state.sendResult.fail}件
  <br>送信日時：${state.sendResult.date}
 </div>`:""}
 <div class="card">
  <div class="toolbar">
   <input id="customerSearch" placeholder="顧客名・組織名・メールアドレスで検索">
   <select id="customerStatus">
    <option value="">すべて</option><option>未送信</option><option>送信済み / 未回答</option><option>回答済み</option>
   </select>
  </div>
  <div class="table-wrap">
   <table><thead><tr>
    <th><input type="checkbox" onclick="toggleAllCustomers(this.checked)"></th>
    <th>組織名</th><th>氏名</th><th>メール</th><th>電話</th><th>最終送信</th><th>回数</th><th>回答ステータス</th><th>kintone</th>
   </tr></thead><tbody>
   ${filtered.map(c=>`<tr>
    <td><input type="checkbox" ${state.selectedCustomers.includes(c.id)?"checked":""} onchange="toggleCustomer(${c.id},this.checked)"></td>
    <td>${esc(c.org)}</td><td>${esc(c.name)}</td><td>${esc(c.email)}</td><td>${esc(c.tel)}</td>
    <td>${esc(c.sent)}</td><td>${c.count}</td><td>${esc(c.status)}</td>
    <td>${c.kintone?'<span class="badge badge-ok">✓ 登録済み</span>':'<span class="badge badge-no">未登録</span>'}</td>
   </tr>`).join("")}
   </tbody></table>
  </div>
 </div>

 <div class="card">
  <div class="section-title">メールテンプレート</div>
  <div class="form-row">
   <label>メール件名</label>
   <input id="mailSubject" value="${esc(defaultMailSubject)}">
  </div>
  <div class="form-row">
   <label>メール本文</label>
   <textarea id="mailBody" style="min-height:180px">${esc(defaultMailBody)}</textarea>
  </div>
  <div class="alert alert-info">
   動的変数：{顧客名}　{アンケートURL}
  </div>
  <div class="actions">
   <button class="btn" onclick="previewMail()">送信文を確認</button>
   <button class="btn btn-warning" onclick="remindCustomers()">未回答者へリマインド</button>
   <button class="btn btn-primary" onclick="sendSelected()">一括送信</button>
  </div>
 </div>

 <div class="card">
  <div class="section-title">回答者向け画面テスト</div>
  <button class="btn" onclick="startAnswer(${s.id})">このアンケートを回答者として開く</button>
  <span class="muted small">回答者画面には管理者ナビゲーションを表示しません。</span>
 </div>`;
}
function toggleCustomer(id,checked){
 if(checked&&!state.selectedCustomers.includes(id))state.selectedCustomers.push(id);
 if(!checked)state.selectedCustomers=state.selectedCustomers.filter(x=>x!==id);
}
function toggleAllCustomers(checked){
 state.selectedCustomers=checked?customers.map(c=>c.id):[];render();
}
function selectedCustomerObjects(){
 return customers.filter(c=>state.selectedCustomers.includes(c.id));
}
function sendSelected(){
 const cs=selectedCustomerObjects();
 if(!cs.length){showToast("送信対象を選択してください");return}
 const already=cs.some(c=>c.count>0);
 const message=already?
  "既に送信済みの宛先が含まれています。再送しますか？":
  `${cs.length}件へメールを送信しますか？`;
 confirmDialog(already?"再送確認":"一括送信",message,()=>executeSend(cs,"一括送信"));
}
function remindCustomers(){
 const cs=customers.filter(c=>c.status==="送信済み / 未回答");
 if(!cs.length){showToast("リマインド対象の未回答者がありません");return}
 state.selectedCustomers=cs.map(c=>c.id);
 confirmDialog("リマインド",`${cs.length}件の未回答者へリマインドを送信しますか？`,()=>executeSend(cs,"リマインド"));
}
function executeSend(cs,type){
 let fail=cs.filter((c,i)=>i%5===4).length;
 let success=cs.length-fail;
 const date=new Date().toLocaleString("ja-JP");
 cs.forEach((c,i)=>{
  c.count++;
  c.sent=date;
  if(i%5!==4)c.status="送信済み / 未回答";
 });
 const subject=document.getElementById("mailSubject")?.value||defaultMailSubject;
 const body=document.getElementById("mailBody")?.value||defaultMailBody;
 state.sendHistory.unshift({
  id:uid(),date,type,count:cs.length,subject,
  executor:"モック管理者",
  customers:cs.map(c=>c.name),
  body, surveyId:state.selectedSurveyId,
  result:`成功 ${success} / 失敗 ${fail}`
 });
 state.sendResult={total:cs.length,success,fail,date};
 state.selectedCustomers=[];
 render();
}
function previewMail(){
 const cs=selectedCustomerObjects();
 const c=cs[0]||customers[0];
 const subject=(document.getElementById("mailSubject")?.value||defaultMailSubject)
  .replaceAll("{顧客名}",c.name);
 const body=(document.getElementById("mailBody")?.value||defaultMailBody)
  .replaceAll("{顧客名}",c.name)
  .replaceAll("{アンケートURL}",location.href.split("#")[0]+"?survey="+state.selectedSurveyId);
 document.getElementById("modalRoot").innerHTML=`
 <div class="modal-backdrop"><div class="modal">
  <h3>送信文を確認</h3>
  <strong>${esc(subject)}</strong>
  <div class="mail-preview" style="margin-top:12px">${esc(body)}</div>
  <div class="modal-actions"><button class="btn btn-primary" onclick="closeModal()">閉じる</button></div>
 </div></div>`;
}
function historyHtml(){
 const list=state.sendHistory.filter(h=>h.surveyId===state.selectedSurveyId);
 return `<div class="card">
  ${list.length?list.map(h=>`
   <div class="history-item">
    <strong>${esc(h.type)}</strong>　${esc(h.date)}
    <div class="small muted">件数：${h.count} / 実行者：${esc(h.executor)} / ${esc(h.result)}</div>
    <div style="margin-top:7px"><strong>件名：</strong>${esc(h.subject)}</div>
    <button class="btn btn-sm" style="margin-top:8px" onclick="showHistoryDetail(${h.id})">送信内容を確認</button>
   </div>`).join(""):`<div class="empty">送信履歴はありません</div>`}
 </div>`;
}
function showHistoryDetail(id){
 const h=state.sendHistory.find(x=>x.id===id);
 if(!h)return;
 document.getElementById("modalRoot").innerHTML=`
 <div class="modal-backdrop"><div class="modal">
  <h3>送信済みメール</h3>
  <p><strong>件名：</strong>${esc(h.subject)}</p>
  <p><strong>対象顧客：</strong>${esc(h.customers.join("、"))}</p>
  <div class="mail-preview">${esc(h.body)}</div>
  <p class="small muted">個別URLは {アンケートURL} を顧客ごとのURLに差し替えて送信する想定です。</p>
  <div class="modal-actions"><button class="btn btn-primary" onclick="closeModal()">閉じる</button></div>
 </div></div>`;
}

/* =========================================================
   集計
========================================================= */
function openSummary(id){
 state.selectedSurveyId=id;state.page="summary";render();
}
function summaryPage(){
 const s=surveyById(state.selectedSurveyId);
 if(!s){state.page="list";return ""}
 const sent=customers.length,answered=s.responses,unanswered=Math.max(sent-answered,0);
 const rate=sent?Math.min(100,Math.round(answered/sent*100)):0;
 return `<h1 class="page-title">回答集計・分析</h1>
 <div class="card">
  <div class="section-title">集計対象アンケート</div>
  <strong style="font-size:20px">${esc(s.title)}</strong>
 </div>
 <div class="kpi-grid">
  <div class="kpi"><div class="label">送信対象者数</div><div class="num">${sent}</div></div>
  <div class="kpi"><div class="label">回答数</div><div class="num">${answered}</div></div>
  <div class="kpi"><div class="label">未登録顧客からの回答数</div><div class="num">${Math.round(answered*.08)}</div></div>
  <div class="kpi"><div class="label">未回答数</div><div class="num">${unanswered}</div></div>
  <div class="kpi"><div class="label">回答率</div><div class="num">${rate}%</div></div>
 </div>
 <div class="card">
  <div class="toolbar">
   <strong>設問フィルター</strong>
   <button class="btn btn-sm" onclick="document.querySelectorAll('.qfilter').forEach(x=>x.checked=true)">すべて選択</button>
   <button class="btn btn-sm" onclick="document.querySelectorAll('.qfilter').forEach(x=>x.checked=false)">すべて解除</button>
  </div>
  ${s.groups.flatMap(g=>g.questions).map(q=>`
   <label style="display:inline-flex;margin:5px 12px 5px 0;font-weight:500">
    <input class="qfilter" type="checkbox" checked> ${getQuestionNumber(s,findGroupId(s,q.id),q.id)} ${esc(q.text)}
   </label>`).join("")}
 </div>
 ${s.groups.flatMap(g=>g.questions).map(q=>aggregateQuestion(s,q)).join("")}
 <div class="card">
  <div class="section-title">個別回答</div>
  ${s.responses?`
   <table><thead><tr><th>組織名</th><th>氏名</th><th>回答日時</th><th>回答概要</th><th></th></tr></thead>
   <tbody>
   ${customers.filter(c=>c.status==="回答済み").map(c=>`
    <tr><td>${esc(c.org)}</td><td>${esc(c.name)}</td><td>2026/08/23 11:30</td>
    <td>回答済み</td><td><button class="btn btn-sm" onclick="showToast('全回答を表示しました')">全回答を表示</button></td></tr>`).join("")}
   </tbody></table>`:`<div class="empty">現在、回答データはありません</div>`}
 </div>
 <div class="actions">
  <button class="btn btn-primary" onclick="showToast('CSV出力操作を実行しました')">CSVダウンロード</button>
  <button class="btn" onclick="showToast('PDF出力操作を実行しました')">PDF出力</button>
 </div>`;
}
function aggregateQuestion(s,q){
 if(q.type==="text")return `<div class="card">
  <div class="section-title">${getQuestionNumber(s,findGroupId(s,q.id),q.id)} ${esc(q.text)}</div>
  <p class="muted">自由記述回答一覧</p>
  <ul><li>サービスが使いやすかったです。</li><li>サポート対応が良かったです。</li></ul>
 </div>`;
 const total=Math.max(1,s.responses);
 return `<div class="card">
  <div class="section-title">${getQuestionNumber(s,findGroupId(s,q.id),q.id)} ${esc(q.text)}</div>
  ${q.choices.map((c,i)=>{
   const n=Math.max(0,Math.round(total*(.42-i*.07)));
   const pct=Math.round(n/total*100);
   return `<div style="margin:12px 0"><div><strong>${esc(c)}</strong>　${n}件 (${pct}%)</div>
   <div class="bar"><span style="width:${pct}%"></span></div></div>`;
  }).join("")}
 </div>`;
}

/* =========================================================
   kintone
========================================================= */
function kintonePage(){
 const k=state.kintone;
 return `<h1 class="page-title">kintone連携設定</h1>
 <div class="card">
  <div class="section-title">接続設定</div>
  <div class="form-grid">
   <div class="form-row"><label>サブドメイン</label><input id="kSub" value="${esc(k.subdomain)}" placeholder="example"></div>
   <div class="form-row"><label>顧客管理アプリID</label><input id="kApp" value="${esc(k.appId)}"></div>
   <div class="form-row"><label>ログイン名</label><input id="kLogin" value="${esc(k.login)}"></div>
   <div class="form-row"><label>パスワード</label><input id="kPass" type="password" value="${esc(k.password)}"></div>
  </div>
  <label style="font-weight:500"><input id="kSSL" type="checkbox" ${k.ssl?"checked":""}> SSL証明書を検証する</label>
  <div class="actions" style="margin-top:16px">
   <button class="btn" onclick="saveKintone()">設定を保存</button>
   <button class="btn btn-primary" onclick="testKintone()">接続テスト</button>
  </div>
  <div style="margin-top:14px">接続状態：
   <span class="status ${k.connection==="接続成功"?"status-open":k.connection==="接続失敗"?"status-end":"status-draft"}">${esc(k.connection)}</span>
  </div>
 </div>

 <div class="card">
  <div class="section-title">kintone項目</div>
  <button class="btn" onclick="getKintoneFields()">項目一覧を再取得</button>
  ${k.fields.length?`<div class="alert alert-success">項目一覧を取得しました。</div>
   <table><thead><tr><th>フィールドコード</th><th>日本語フィールドラベル</th></tr></thead>
   <tbody>${k.fields.map(f=>`<tr><td>${esc(f.code)}</td><td>${esc(f.label)}</td></tr>`).join("")}</tbody></table>`:""}
 </div>

 <div class="card">
  <div class="section-title">フィールドマッピング</div>
  <div class="form-grid">
   ${mappingSelect("org","組織名")}
   ${mappingSelect("name","氏名")}
   ${mappingSelect("email","メールアドレス")}
   ${mappingSelect("dept","部署名")}
   ${mappingSelect("tel","電話番号")}
  </div>
  <div class="form-row">
   <label>住所マッピング（複数選択）</label>
   <div class="checkbox-list">
    ${["都道府県","市区町村","番地","建物名","郵便番号"].map(x=>
      `<label><input type="checkbox" ${k.mapped.address.includes(x)?"checked":""}
       onchange="toggleAddress('${x}',this.checked)"> ${x}</label>`).join("")}
   </div>
  </div>
  <button class="btn btn-success" onclick="syncCustomers()">顧客情報を同期</button>
  ${k.synced?`<div class="alert alert-success">顧客情報を同期しました。</div>`:""}
 </div>`;
}
function mappingSelect(key,label){
 const opts=["","org_name","name","email","department","phone"];
 return `<div class="form-row"><label>${label}</label>
 <select onchange="state.kintone.mapped.${key}=this.value">
 ${opts.map(x=>`<option value="${x}" ${state.kintone.mapped[key]===x?"selected":""}>${x||"選択してください"}</option>`).join("")}
 </select></div>`;
}
function saveKintone(){
 const k=state.kintone;
 k.subdomain=document.getElementById("kSub").value;
 k.appId=document.getElementById("kApp").value;
 k.login=document.getElementById("kLogin").value;
 k.password=document.getElementById("kPass").value;
 k.ssl=document.getElementById("kSSL").checked;
 showToast("kintone設定を保存しました");
}
function testKintone(){
 saveKintone();
 const ok=state.kintone.subdomain&&state.kintone.appId&&state.kintone.login;
 state.kintone.connection=ok?"接続成功":"接続失敗";
 render();
 showToast(ok?"kintoneへの接続に成功しました":"kintoneへの接続に失敗しました");
}
function getKintoneFields(){
 state.kintone.fields=[
  {code:"company_name",label:"組織名"},
  {code:"name",label:"氏名"},
  {code:"email",label:"メールアドレス"},
  {code:"department",label:"部署名"},
  {code:"phone",label:"電話番号"},
  {code:"prefecture",label:"都道府県"},
  {code:"city",label:"市区町村"},
  {code:"address",label:"番地"},
  {code:"building",label:"建物名"},
  {code:"postal",label:"郵便番号"}
 ];
 render();showToast("項目一覧を再取得しました");
}
function toggleAddress(v,checked){
 if(checked&&!state.kintone.mapped.address.includes(v))state.kintone.mapped.address.push(v);
 if(!checked)state.kintone.mapped.address=state.kintone.mapped.address.filter(x=>x!==v);
}
function syncCustomers(){
 if(state.kintone.connection!=="接続成功"){
  showToast("先に接続テストを成功させてください");return;
 }
 state.kintone.synced=true;
 render();showToast("顧客情報を同期しました");
}

/* =========================================================
   メールサーバ
========================================================= */
function mailServerPage(){
 const m=state.mail;
 return `<h1 class="page-title">メールサーバ設定</h1>
 <div class="card">
  <div class="form-grid">
   <div class="form-row"><label>SMTPサーバ</label><input id="smtp" value="${esc(m.smtp)}"></div>
   <div class="form-row"><label>SMTPポート</label><input id="smtpPort" value="${esc(m.port)}"></div>
   <div class="form-row"><label>暗号化方式</label>
    <select id="smtpEnc"><option>SSL</option><option ${m.encryption==="TLS"?"selected":""}>TLS</option><option ${m.encryption==="なし"?"selected":""}>なし</option></select>
   </div>
   <div class="form-row"><label>SMTPユーザー名</label><input id="smtpUser" value="${esc(m.username)}"></div>
   <div class="form-row"><label>SMTPパスワード</label><input id="smtpPass" type="password" value="${esc(m.password)}"></div>
   <div class="form-row"><label>送信元メールアドレス</label><input id="smtpFrom" type="email" value="${esc(m.from)}"></div>
   <div class="form-row"><label>送信元名</label><input id="smtpFromName" value="${esc(m.fromName)}"></div>
   <div class="form-row"><label>返信先メールアドレス</label><input id="smtpReply" type="email" value="${esc(m.replyTo)}"></div>
  </div>
  <label style="font-weight:500"><input id="smtpAuth" type="checkbox" ${m.auth?"checked":""}> SMTP認証を使用する</label>
  <div class="actions" style="margin-top:18px">
   <button class="btn btn-primary" onclick="saveMailServer()">設定を保存</button>
   <button class="btn" onclick="testMailServer()">接続確認</button>
   <button class="btn btn-success" onclick="testMail()">テストメール送信</button>
  </div>
  <div style="margin-top:15px">接続状態：
   <span class="status ${m.status==="接続確認済み"?"status-open":m.status==="接続できません"?"status-end":"status-draft"}">${esc(m.status)}</span>
  </div>
 </div>`;
}
function saveMailServer(){
 const m=state.mail;
 m.smtp=document.getElementById("smtp").value;
 m.port=document.getElementById("smtpPort").value;
 m.encryption=document.getElementById("smtpEnc").value;
 m.username=document.getElementById("smtpUser").value;
 m.password=document.getElementById("smtpPass").value;
 m.from=document.getElementById("smtpFrom").value;
 m.fromName=document.getElementById("smtpFromName").value;
 m.replyTo=document.getElementById("smtpReply").value;
 m.auth=document.getElementById("smtpAuth").checked;
 showToast("メールサーバ設定を保存しました");
}
function testMailServer(){
 saveMailServer();
 const ok=!!state.mail.smtp;
 state.mail.status=ok?"接続確認済み":"接続できません";
 render();showToast(ok?"メールサーバへ接続できました":"メールサーバへ接続できません");
}
function testMail(){
 confirmDialog("テストメール","モック上でテストメールを送信しますか？",()=>{
  showToast("テストメール送信成功");
 });
}

/* =========================================================
   初期表示
========================================================= */
render();

/* URL ?survey=ID で回答者画面を直接開けるモック */
const params=new URLSearchParams(location.search);
if(params.get("survey")){
 const sid=Number(params.get("survey"));
 if(surveyById(sid)){
  setTimeout(()=>startAnswer(sid),0);
 }
}
</script>
</body>
</html>
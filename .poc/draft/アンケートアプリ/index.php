<?php
declare(strict_types=1);

/*
 * アンケート管理システム - インタラクティブモック
 * Apache 2.4 / PHP 8.5
 * DB / API / SMTP / 認証なし
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム - モック</title>
<style>
:root{
  --primary:#2563eb;
  --primary-dark:#1d4ed8;
  --bg:#f5f7fb;
  --card:#fff;
  --text:#172033;
  --muted:#667085;
  --border:#dfe4ec;
  --danger:#dc2626;
  --warning:#d97706;
  --success:#16a34a;
  --info:#0891b2;
  --shadow:0 2px 10px rgba(15,23,42,.06);
  --radius:10px;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:var(--text);background:var(--bg)}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.5}
.app{min-height:100vh}
.header{
  height:64px;background:#172033;color:#fff;display:flex;align-items:center;
  padding:0 24px;position:sticky;top:0;z-index:30;
}
.header .brand{font-weight:700;font-size:18px;margin-right:38px;white-space:nowrap}
.nav{display:flex;gap:4px;flex:1}
.nav button{
  background:transparent;border:0;color:#dbe2ef;padding:10px 14px;border-radius:7px;
}
.nav button:hover,.nav button.active{background:#29364e;color:#fff}
.logout{border:1px solid #536079;background:transparent;color:#fff;border-radius:7px;padding:8px 14px}
.main{max-width:1440px;margin:0 auto;padding:28px 24px 60px}
.page-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:22px}
.page-head h1{font-size:25px;margin:0}
.page-head p{margin:6px 0 0;color:var(--muted);font-size:14px}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow)}
.toolbar{padding:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.search{position:relative;flex:1;min-width:240px}
.search input{width:100%;padding:10px 12px 10px 36px;border:1px solid var(--border);border-radius:7px}
.search span{position:absolute;left:12px;top:9px;color:#98a2b3}
select,input,textarea{
  border:1px solid var(--border);border-radius:7px;padding:9px 11px;background:#fff;color:var(--text)
}
textarea{resize:vertical}
.btn{
  border:1px solid var(--border);background:#fff;color:var(--text);
  border-radius:7px;padding:9px 14px;min-height:40px;
}
.btn:hover{background:#f8fafc}
.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-dark)}
.btn-danger{color:var(--danger);border-color:#fecaca;background:#fff}
.btn-success{background:var(--success);border-color:var(--success);color:#fff}
.btn-warning{color:#92400e;border-color:#fed7aa;background:#fff}
.btn-sm{padding:6px 9px;min-height:32px;font-size:13px}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:1050px}
th,td{padding:14px 15px;border-bottom:1px solid #edf0f4;text-align:left;vertical-align:middle}
th{background:#f8fafc;color:#475467;font-size:13px;font-weight:700;white-space:nowrap}
td{font-size:14px}
tr:hover td{background:#fcfdff}
.title-link{font-weight:700;color:#1d4ed8;cursor:pointer}
.title-link:hover{text-decoration:underline}
.badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:700;white-space:nowrap}
.badge-draft{background:#eef2f7;color:#475467}
.badge-published{background:#dcfce7;color:#166534}
.badge-stopped{background:#fff7ed;color:#9a3412}
.badge-ended{background:#f1f5f9;color:#64748b}
.badge-ok{background:#dcfce7;color:#166534}
.badge-error{background:#fee2e2;color:#991b1b}
.badge-info{background:#e0f2fe;color:#075985}
.actions{display:flex;gap:6px;flex-wrap:wrap}
.empty{padding:60px 20px;text-align:center;color:var(--muted)}
.hidden{display:none!important}

/* 編集 */
.editor-top{
  background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:15px 18px;margin-bottom:18px;box-shadow:var(--shadow);
  display:flex;align-items:center;gap:12px;
}
.editor-top h1{font-size:21px;margin:0 20px 0 0;white-space:nowrap}
.editor-actions{display:flex;gap:8px;align-items:center}
.state-box{margin-left:auto;display:flex;align-items:center;gap:9px}
.state-box label{font-size:13px;font-weight:700;color:#475467}
.state-box select{min-width:150px}
.section{padding:22px;margin-bottom:18px}
.section-title{font-size:18px;margin:0 0 18px;padding-bottom:12px;border-bottom:1px solid #edf0f4}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px}
.form-group{display:flex;flex-direction:column;gap:7px}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:13px;font-weight:700;color:#475467}
.form-group textarea{min-height:100px}
.radio-group{display:flex;gap:24px;flex-wrap:wrap}
.radio-group label{font-weight:400;color:var(--text);display:flex;gap:7px;align-items:center}

/* グループ・質問 */
.group{
  border:1px solid #d9e0ea;border-radius:10px;background:#fbfcfe;margin-bottom:16px;
}
.group.dragging,.question.dragging{opacity:.45}
.group-head{
  display:flex;align-items:center;gap:10px;padding:13px 15px;
  background:#f1f5f9;border-bottom:1px solid #d9e0ea;border-radius:10px 10px 0 0;
}
.drag-handle{cursor:grab;color:#98a2b3;font-size:18px}
.group-title-input{flex:1;font-weight:700;background:#fff}
.question-list{padding:12px}
.question{
  background:#fff;border:1px solid var(--border);border-radius:9px;
  padding:15px;margin-bottom:10px;
}
.question:last-child{margin-bottom:0}
.question-head{display:flex;align-items:center;gap:9px;margin-bottom:12px}
.question-no{font-weight:800;color:var(--primary);min-width:58px}
.question-actions{margin-left:auto;display:flex;gap:5px}
.question-body{display:grid;grid-template-columns:1fr 190px;gap:12px}
.question-text{width:100%;min-height:74px}
.question-options{margin-top:12px;padding:12px;background:#f8fafc;border-radius:7px}
.option-row{display:flex;gap:7px;margin-bottom:7px}
.option-row input{flex:1}
.required-row{display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px}
.branch-box{margin-top:12px;padding:12px;border-left:3px solid #60a5fa;background:#eff6ff;border-radius:0 7px 7px 0}
.branch-row{display:flex;align-items:center;gap:8px;margin-top:7px}
.add-area{text-align:center;padding:10px}
.add-group{text-align:center;margin-top:10px}
.help{font-size:12px;color:var(--muted)}
.number-preview{font-size:12px;color:var(--muted);margin-top:5px}

/* プレビュー */
.preview-shell{max-width:1000px;margin:0 auto}
.preview-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.preview-device{display:flex;gap:4px}
.preview-device button.active{background:#172033;color:#fff}
.preview-frame{background:#e5e7eb;padding:30px;border-radius:12px}
.preview-phone{max-width:420px;margin:auto}
.preview-content{background:#fff;border-radius:8px;padding:28px;min-height:500px}
.preview-title{font-size:24px;font-weight:800;margin-bottom:8px}
.preview-desc{color:#667085;margin-bottom:25px;white-space:pre-wrap}
.preview-group-title{font-size:18px;font-weight:800;border-bottom:2px solid #e5e7eb;padding-bottom:8px;margin:25px 0 15px}
.preview-q{margin:20px 0}
.preview-q-title{font-weight:700;margin-bottom:9px}
.required{color:#dc2626;font-size:11px;margin-left:6px}
.choice{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid #e5e7eb;border-radius:7px;margin:7px 0}
.preview-nav{display:flex;justify-content:space-between;margin-top:28px}

/* モーダル */
.modal-backdrop{
  position:fixed;inset:0;background:rgba(15,23,42,.52);z-index:100;
  display:flex;align-items:center;justify-content:center;padding:20px;
}
.modal{background:#fff;border-radius:12px;width:min(600px,100%);max-height:90vh;overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,.2)}
.modal-head{padding:18px 20px;border-bottom:1px solid #edf0f4;display:flex;justify-content:space-between}
.modal-head h3{margin:0;font-size:18px}
.modal-close{border:0;background:none;font-size:22px;color:#667085}
.modal-body{padding:20px}
.modal-foot{padding:14px 20px;border-top:1px solid #edf0f4;display:flex;justify-content:flex-end;gap:8px}
.toast{
  position:fixed;right:22px;bottom:22px;z-index:200;background:#172033;color:#fff;
  padding:13px 17px;border-radius:8px;box-shadow:0 8px 25px rgba(0,0,0,.18);font-size:14px
}

/* 顧客 */
.customer-toolbar{display:flex;gap:10px;flex-wrap:wrap;padding:15px;border-bottom:1px solid var(--border)}
.mail-layout{display:grid;grid-template-columns:1.4fr .9fr;gap:18px}
.mail-preview{position:sticky;top:85px}
.template-area textarea{min-height:230px;width:100%}

/* 集計 */
.summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px}
.summary-card{padding:17px}
.summary-label{font-size:12px;color:var(--muted)}
.summary-value{font-size:27px;font-weight:800;margin-top:5px}
.chart-row{margin:14px 0}
.chart-label{display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px}
.bar{height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden}
.bar span{display:block;height:100%;background:#3b82f6;border-radius:999px}

/* 設定 */
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.settings-field{display:flex;flex-direction:column;gap:6px;margin-bottom:15px}
.settings-field label{font-size:13px;font-weight:700;color:#475467}
.mapping{border:1px solid var(--border);border-radius:8px;padding:15px}
.mapping label{display:flex;gap:8px;margin:10px 0}

/* 回答者 */
.respondent-page{min-height:100vh;background:#f5f7fb}
.respondent-wrap{max-width:760px;margin:auto;padding:35px 18px 60px}
.respondent-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:30px}
.respondent-brand{font-size:13px;color:#667085;margin-bottom:20px}
.answer-q{margin:25px 0;padding-bottom:22px;border-bottom:1px solid #edf0f4}
.answer-q:last-child{border-bottom:0}
.answer-q-title{font-weight:700;margin-bottom:12px}
.answer-input{width:100%;min-height:44px}
.answer-actions{display:flex;justify-content:space-between;margin-top:25px}
.completed{text-align:center;padding:60px 20px}
.completed-icon{font-size:55px;color:#16a34a}

/* レスポンシブ */
@media(max-width:1000px){
  .summary-grid{grid-template-columns:repeat(3,1fr)}
  .mail-layout,.settings-grid{grid-template-columns:1fr}
  .mail-preview{position:static}
}
@media(max-width:760px){
  .header{padding:0 12px;height:auto;min-height:58px;flex-wrap:wrap}
  .header .brand{margin:0 20px 0 0}
  .nav{overflow:auto}
  .nav button{padding:8px 10px;font-size:12px}
  .main{padding:18px 12px 40px}
  .page-head{align-items:flex-start;flex-direction:column}
  .editor-top{align-items:stretch;flex-wrap:wrap}
  .editor-top h1{width:100%;margin:0}
  .editor-actions{flex-wrap:wrap}
  .state-box{margin-left:0;width:100%}
  .state-box select{flex:1}
  .form-grid{grid-template-columns:1fr}
  .form-group.full{grid-column:auto}
  .question-body{grid-template-columns:1fr}
  .summary-grid{grid-template-columns:repeat(2,1fr)}
  .preview-frame{padding:10px}
  .preview-content{padding:20px}
  .respondent-card{padding:20px}
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
  page: "list",
  previousPage: "list",
  editingId: null,
  previewDevice: "pc",
  respondentStep: 1,
  answerSubmitted: false,
  search: "",
  filterStatus: "all",
  sort: "updated_desc",
  customers: [
    {id:1,org:"株式会社サンプル",name:"山田 太郎",email:"taro@example.jp",phone:"03-1234-5678",address:"東京都港区",sent:"2026/08/20 10:15",count:1,status:"回答済み",kintone:true},
    {id:2,org:"株式会社テスト",name:"佐藤 花子",email:"hanako@example.jp",phone:"03-2345-6789",address:"東京都新宿区",sent:"2026/08/21 14:30",count:1,status:"送信済み / 未回答",kintone:true},
    {id:3,org:"合同会社デモ",name:"鈴木 一郎",email:"ichiro@example.jp",phone:"03-3456-7890",address:"東京都渋谷区",sent:"-",count:0,status:"未送信",kintone:false},
    {id:4,org:"有限会社サンプル商事",name:"田中 美咲",email:"misaki@example.jp",phone:"03-4567-8901",address:"東京都千代田区",sent:"2026/08/22 09:00",count:2,status:"送信済み / 未回答",kintone:false}
  ],
  surveys: [
    {
      id:1,
      created:"2026/08/01",
      updated:"2026/08/24 15:30",
      title:"サービス満足度アンケート",
      description:"弊社サービスをご利用いただいた皆様へのアンケートです。",
      start:"2026-08-01T09:00",
      end:"2026-09-30T23:59",
      status:"published",
      answers:128,
      target:200,
      numbering:"global",
      groups:[
        {
          id:"g1",title:"基本アンケート",
          questions:[
            {id:"q1",text:"サービスを利用したことがありますか？",type:"single",required:true,
             options:["はい","いいえ"],branch:{"はい":"q2","いいえ":"q4"}},
            {id:"q2",text:"サービスの満足度を教えてください。",type:"single",required:true,
             options:["非常に満足","満足","普通","不満","非常に不満"],branch:null},
            {id:"q3",text:"ご意見・ご要望があればご記入ください。",type:"textarea",required:false,options:[],branch:null}
          ]
        },
        {
          id:"g2",title:"今後について",
          questions:[
            {id:"q4",text:"今後もサービスを利用したいと思いますか？",type:"single",required:true,
             options:["ぜひ利用したい","利用したい","どちらともいえない","あまり利用したくない"],branch:null}
          ]
        }
      ]
    },
    {
      id:2,
      created:"2026/08/05",
      updated:"2026/08/23 11:20",
      title:"新商品に関するアンケート",
      description:"新商品の企画に関するアンケートです。",
      start:"2026-09-01T09:00",
      end:"2026-09-30T23:59",
      status:"draft",
      answers:0,
      target:80,
      numbering:"group",
      groups:[
        {
          id:"g3",title:"新商品について",
          questions:[
            {id:"q5",text:"新商品に期待することを教えてください。",type:"multiple",required:true,
             options:["価格","品質","デザイン","機能","サポート"],branch:null}
          ]
        }
      ]
    },
    {
      id:3,
      created:"2026/07/01",
      updated:"2026/08/10 09:15",
      title:"イベント参加者アンケート",
      description:"イベントご参加ありがとうございました。",
      start:"2026-07-10T10:00",
      end:"2026-08-10T18:00",
      status:"ended",
      answers:73,
      target:90,
      numbering:"global",
      groups:[
        {
          id:"g4",title:"イベントについて",
          questions:[
            {id:"q6",text:"イベント全体の満足度を教えてください。",type:"single",required:true,
             options:["5","4","3","2","1"],branch:null}
          ]
        }
      ]
    },
    {
      id:4,
      created:"2026/08/12",
      updated:"2026/08/22 16:45",
      title:"サポート対応について",
      description:"サポート品質向上のためのアンケートです。",
      start:"2026-08-15T09:00",
      end:"2026-10-15T23:59",
      status:"stopped",
      answers:42,
      target:100,
      numbering:"global",
      groups:[
        {
          id:"g5",title:"サポート対応",
          questions:[
            {id:"q7",text:"お問い合わせへの対応はいかがでしたか？",type:"single",required:true,
             options:["非常に良い","良い","普通","悪い"],branch:null}
          ]
        }
      ]
    }
  ],
  settings:{
    kintone:{
      subdomain:"example.cybozu.com",
      appId:"123",
      login:"admin",
      password:"password",
      ssl:true,
      connection:"connected",
      fields:[
        "組織名","氏名","メールアドレス","部署名","電話番号",
        "都道府県","市区町村","番地","建物名","郵便番号"
      ],
      mapping:{
        org:"組織名",name:"氏名",email:"メールアドレス",
        department:"部署名",phone:"電話番号",
        address:["都道府県","市区町村","番地","建物名","郵便番号"]
      }
    },
    mail:{
      host:"smtp.example.jp",port:"587",encryption:"TLS",
      auth:true,user:"noreply@example.jp",password:"password",
      from:"noreply@example.jp",fromName:"アンケート事務局",
      reply:"support@example.jp",status:"connected"
    }
  }
};

let draft = null;

/* =========================================================
   共通
========================================================= */
function esc(v){
  return String(v ?? "").replace(/[&<>"']/g,s=>({
    "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
  }[s]));
}
function clone(v){return JSON.parse(JSON.stringify(v))}
function uid(prefix="id"){return prefix+Date.now().toString(36)+Math.random().toString(36).slice(2,7)}
function statusLabel(s){
  return {published:"公開中",draft:"下書き",stopped:"停止",ended:"終了"}[s] || s;
}
function badge(s){
  return `<span class="badge badge-${s}">${esc(statusLabel(s))}</span>`;
}
function formatDate(v){
  if(!v)return "-";
  return v.replace("T"," ");
}
function findSurvey(id){return state.surveys.find(s=>s.id===Number(id))}
function getNextSurveyId(){return Math.max(0,...state.surveys.map(s=>s.id))+1}

function toast(message){
  const root=document.getElementById("toastRoot");
  root.innerHTML=`<div class="toast">${esc(message)}</div>`;
  setTimeout(()=>root.innerHTML="",2500);
}

function showModal(title,body,buttons=[
  {label:"キャンセル",className:"btn",action:null},
  {label:"実行",className:"btn btn-primary",action:null}
]){
  const root=document.getElementById("modalRoot");
  root.innerHTML=`
    <div class="modal-backdrop" id="modalBackdrop">
      <div class="modal">
        <div class="modal-head">
          <h3>${esc(title)}</h3>
          <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body">${body}</div>
        <div class="modal-foot">
          ${buttons.map((b,i)=>`<button class="${b.className}" data-modal-btn="${i}">${esc(b.label)}</button>`).join("")}
        </div>
      </div>
    </div>`;
  buttons.forEach((b,i)=>{
    document.querySelector(`[data-modal-btn="${i}"]`).onclick=()=>{
      if(b.action)b.action();
      else closeModal();
    };
  });
}
function closeModal(){document.getElementById("modalRoot").innerHTML=""}

/* =========================================================
   採番
========================================================= */
function recalcNumbers(s){
  let n=1;
  s.groups.forEach((g,gi)=>{
    g.questions.forEach((q,qi)=>{
      q.number=s.numbering==="global"
        ? `Q${n++}`
        : `Q${gi+1}-${qi+1}`;
    });
  });
}
function allQuestions(s){
  return s.groups.flatMap(g=>g.questions);
}
function questionById(s,id){
  for(const g of s.groups){
    const q=g.questions.find(q=>q.id===id);
    if(q)return q;
  }
  return null;
}

/* =========================================================
   管理者ヘッダー
========================================================= */
function adminHeader(active="list"){
  return `
  <header class="header">
    <div class="brand">アンケート管理</div>
    <nav class="nav">
      <button class="${active==="list"?"active":""}" onclick="navigate('list')">アンケート一覧</button>
      <button class="${active==="kintone"?"active":""}" onclick="navigate('kintone')">kintone連携設定</button>
      <button class="${active==="mail"?"active":""}" onclick="navigate('mail')">メールサーバ設定</button>
    </nav>
    <button class="logout" onclick="toast('モック：ログアウトしました')">ログアウト</button>
  </header>`;
}

/* =========================================================
   画面遷移
========================================================= */
function navigate(page,data={}){
  state.previousPage=state.page;
  state.page=page;
  if(page==="edit"){
    state.editingId=data.id ?? null;
    draft=data.survey ? clone(data.survey) : null;
    if(!draft){
      draft={
        id:null,created:new Date().toISOString().slice(0,10),
        updated:"",title:"",description:"",
        start:"",end:"",status:"draft",answers:0,target:0,
        numbering:"global",
        groups:[{
          id:uid("g"),title:"新しいグループ",
          questions:[{
            id:uid("q"),text:"",type:"single",required:false,
            options:["選択肢1","選択肢2"],branch:null
          }]
        }]
      };
    }
    recalcNumbers(draft);
  }
  if(page==="preview"){
    if(!draft)return;
    recalcNumbers(draft);
  }
  if(page==="respondent"){
    state.respondentStep=1;
    state.answerSubmitted=false;
  }
  render();
}

function render(){
  const app=document.getElementById("app");
  if(state.page==="respondent" || state.page==="answerConfirm" || state.page==="answerComplete"){
    app.innerHTML=renderRespondentPage();
    return;
  }

  let content="";
  if(state.page==="list")content=renderList();
  if(state.page==="edit")content=renderEditor();
  if(state.page==="preview")content=renderPreview();
  if(state.page==="customers")content=renderCustomers();
  if(state.page==="history")content=renderHistory();
  if(state.page==="analysis")content=renderAnalysis();
  if(state.page==="kintone")content=renderKintone();
  if(state.page==="mail")content=renderMail();

  const active =
    ["list","edit","preview","customers","history","analysis"].includes(state.page) ? "list" :
    state.page==="kintone" ? "kintone" :
    state.page==="mail" ? "mail" : "list";

  app.innerHTML=adminHeader(active)+`<main class="main">${content}</main>`;
}

/* =========================================================
   一覧
========================================================= */
function renderList(){
  let rows=state.surveys.filter(s=>{
    const searchOk=!state.search || s.title.toLowerCase().includes(state.search.toLowerCase());
    const filterOk=state.filterStatus==="all" || s.status===state.filterStatus;
    return searchOk && filterOk;
  });

  rows.sort((a,b)=>{
    if(state.sort==="updated_desc")return b.updated.localeCompare(a.updated);
    if(state.sort==="updated_asc")return a.updated.localeCompare(b.updated);
    if(state.sort==="answers_desc")return b.answers-a.answers;
    if(state.sort==="answers_asc")return a.answers-b.answers;
    if(state.sort==="start_desc")return b.start.localeCompare(a.start);
    if(state.sort==="start_asc")return a.start.localeCompare(b.start);
    return 0;
  });

  return `
  <div class="page-head">
    <div>
      <h1>アンケート一覧</h1>
      <p>登録されているアンケートを管理します。</p>
    </div>
    <button class="btn btn-primary" onclick="navigate('edit',{id:null})">＋ 新規アンケート作成</button>
  </div>

  <div class="card">
    <div class="toolbar">
      <div class="search">
        <span>⌕</span>
        <input id="searchInput" value="${esc(state.search)}"
          placeholder="タイトルで検索（Enterで検索）"
          onkeydown="if(event.key==='Enter'){state.search=this.value;render()}">
      </div>
      <select onchange="state.filterStatus=this.value;render()">
        <option value="all" ${state.filterStatus==="all"?"selected":""}>ステータス：すべて</option>
        <option value="published" ${state.filterStatus==="published"?"selected":""}>公開中</option>
        <option value="draft" ${state.filterStatus==="draft"?"selected":""}>下書き</option>
        <option value="stopped" ${state.filterStatus==="stopped"?"selected":""}>停止</option>
        <option value="ended" ${state.filterStatus==="ended"?"selected":""}>終了</option>
      </select>
      <select onchange="state.sort=this.value;render()">
        <option value="updated_desc" ${state.sort==="updated_desc"?"selected":""}>更新日：新しい順</option>
        <option value="updated_asc" ${state.sort==="updated_asc"?"selected":""}>更新日：古い順</option>
        <option value="answers_desc" ${state.sort==="answers_desc"?"selected":""}>回答数：多い順</option>
        <option value="answers_asc" ${state.sort==="answers_asc"?"selected":""}>回答数：少ない順</option>
        <option value="start_desc" ${state.sort==="start_desc"?"selected":""}>期間開始日：新しい順</option>
        <option value="start_asc" ${state.sort==="start_asc"?"selected":""}>期間開始日：古い順</option>
      </select>
    </div>

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
          ${rows.length ? rows.map(s=>`
          <tr>
            <td>${esc(s.created)}<br><span class="help">${esc(s.updated)}</span></td>
            <td><span class="title-link" onclick="navigate('edit',{id:${s.id},survey:findSurvey(${s.id})})">${esc(s.title)}</span></td>
            <td>${esc(formatDate(s.start))}<br>〜 ${esc(formatDate(s.end))}</td>
            <td>${badge(s.status)}</td>
            <td><strong>${s.answers}</strong> / ${s.target || "-"}</td>
            <td>
              <div class="actions">
                <button class="btn btn-sm" onclick="navigate('edit',{id:${s.id},survey:findSurvey(${s.id})})">確認・編集</button>
                <button class="btn btn-sm" onclick="navigate('analysis',{id:${s.id}})">集計</button>
                <button class="btn btn-sm" onclick="navigate('customers',{id:${s.id}})">送信</button>
                <button class="btn btn-sm" onclick="duplicateSurvey(${s.id})">複製</button>
                <button class="btn btn-sm btn-danger" onclick="deleteSurvey(${s.id})">削除</button>
              </div>
            </td>
          </tr>`).join("") : `
          <tr><td colspan="6"><div class="empty">該当するアンケートはありません。</div></td></tr>`}
        </tbody>
      </table>
    </div>
  </div>`;
}

/* =========================================================
   編集
========================================================= */
function renderEditor(){
  recalcNumbers(draft);
  const title=draft.id ? "アンケート作成・編集" : "アンケート作成";
  const stateOptions = draft.status==="draft"
    ? [["draft","下書き"],["published","公開"]]
    : draft.status==="published"
      ? [["published","公開中"],["stopped","停止"]]
      : draft.status==="stopped"
        ? [["stopped","停止"],["published","再開"]]
        : [["ended","終了"]];

  return `
  <div class="editor-top">
    <h1>${title}</h1>
    <div class="editor-actions">
      <button class="btn" onclick="cancelEdit()">キャンセル</button>
      <button class="btn btn-primary" onclick="saveSurvey()">保存して一覧へ</button>
      <button class="btn" onclick="navigate('preview')">プレビュー</button>
    </div>
    <div class="state-box">
      <label>状態：</label>
      <select id="stateSelect" onchange="requestStateChange(this.value)">
        ${stateOptions.map(o=>`<option value="${o[0]}" ${draft.status===o[0]?"selected":""}>${o[1]}</option>`).join("")}
      </select>
    </div>
  </div>

  <section class="card section">
    <h2 class="section-title">基本情報</h2>
    <div class="form-grid">
      <div class="form-group full">
        <label>アンケートタイトル</label>
        <input value="${esc(draft.title)}" oninput="draft.title=this.value" placeholder="アンケートタイトルを入力">
      </div>
      <div class="form-group full">
        <label>アンケート説明</label>
        <textarea oninput="draft.description=this.value" placeholder="回答者への説明を入力">${esc(draft.description)}</textarea>
      </div>
      <div class="form-group">
        <label>開始日時</label>
        <input type="datetime-local" value="${esc(draft.start)}" oninput="draft.start=this.value">
      </div>
      <div class="form-group">
        <label>終了日時</label>
        <input type="datetime-local" value="${esc(draft.end)}" oninput="draft.end=this.value">
      </div>
      <div class="form-group full">
        <label>質問番号の採番方式</label>
        <div class="radio-group">
          <label><input type="radio" name="numbering" value="global" ${draft.numbering==="global"?"checked":""} onchange="draft.numbering='global';recalcNumbers(draft);renderEditorBodyOnly()"> アンケート全体で通番</label>
          <label><input type="radio" name="numbering" value="group" ${draft.numbering==="group"?"checked":""} onchange="draft.numbering='group';recalcNumbers(draft);renderEditorBodyOnly()"> グループ毎に採番</label>
        </div>
        <div class="number-preview">質問番号は追加・削除・並び替え・グループ移動時に自動更新されます。</div>
      </div>
    </div>
  </section>

  <section class="card section">
    <h2 class="section-title">質問・グループ</h2>
    <div id="groupsContainer">
      ${draft.groups.map((g,gi)=>renderGroup(g,gi)).join("")}
    </div>
    <div class="add-group">
      <button class="btn btn-primary" onclick="addGroup()">＋ グループを追加</button>
    </div>
  </section>`;
}

function renderEditorBodyOnly(){
  const page=document.querySelector(".main");
  if(page)page.innerHTML=renderEditor();
}

function renderGroup(g,gi){
  recalcNumbers(draft);
  return `
  <div class="group" draggable="true"
       ondragstart="dragGroupStart(event,${gi})"
       ondragover="event.preventDefault()"
       ondrop="dropGroup(event,${gi})">
    <div class="group-head">
      <span class="drag-handle">⋮⋮</span>
      <strong>グループ${gi+1}</strong>
      <input class="group-title-input" value="${esc(g.title)}"
        oninput="draft.groups[${gi}].title=this.value">
      <button class="btn btn-sm btn-danger" onclick="deleteGroup(${gi})">削除</button>
    </div>
    <div class="question-list">
      ${g.questions.map((q,qi)=>renderQuestion(q,gi,qi)).join("")}
      <div class="add-area">
        <button class="btn btn-sm btn-primary" onclick="addQuestion(${gi})">＋ 質問を追加</button>
      </div>
    </div>
  </div>`;
}

function renderQuestion(q,gi,qi){
  recalcNumbers(draft);
  const typeLabel={single:"単一選択",multiple:"複数選択",text:"1行テキスト",textarea:"複数行テキスト"}[q.type];
  return `
  <div class="question" draggable="true"
       ondragstart="dragQuestionStart(event,${gi},${qi})"
       ondragover="event.preventDefault()"
       ondrop="dropQuestion(event,${gi},${qi})">
    <div class="question-head">
      <span class="drag-handle">⋮⋮</span>
      <span class="question-no">${esc(q.number)}</span>
      <strong>${esc(typeLabel)}</strong>
      <div class="question-actions">
        <button class="btn btn-sm" onclick="moveQuestionToGroup(${gi},${qi})">グループ移動</button>
        <button class="btn btn-sm btn-danger" onclick="deleteQuestion(${gi},${qi})">削除</button>
      </div>
    </div>

    <div class="question-body">
      <textarea class="question-text" placeholder="質問文を入力"
        oninput="draft.groups[${gi}].questions[${qi}].text=this.value">${esc(q.text)}</textarea>
      <select onchange="changeQuestionType(${gi},${qi},this.value)">
        <option value="single" ${q.type==="single"?"selected":""}>単一選択</option>
        <option value="multiple" ${q.type==="multiple"?"selected":""}>複数選択</option>
        <option value="text" ${q.type==="text"?"selected":""}>1行テキスト</option>
        <option value="textarea" ${q.type==="textarea"?"selected":""}>複数行テキスト</option>
      </select>
    </div>

    ${(q.type==="single" || q.type==="multiple") ? `
      <div class="question-options">
        <div class="help">選択肢</div>
        ${(q.options||[]).map((op,oi)=>`
          <div class="option-row">
            <input value="${esc(op)}" oninput="draft.groups[${gi}].questions[${qi}].options[${oi}]=this.value">
            <button class="btn btn-sm btn-danger" onclick="removeOption(${gi},${qi},${oi})">削除</button>
          </div>`).join("")}
        <button class="btn btn-sm" onclick="addOption(${gi},${qi})">＋ 選択肢を追加</button>
      </div>` : ""}

    <div class="required-row">
      <input type="checkbox" ${q.required?"checked":""}
        onchange="draft.groups[${gi}].questions[${qi}].required=this.checked">
      <span>必須回答</span>
    </div>

    ${q.type==="single" ? `
      <div class="branch-box">
        <strong>条件分岐</strong>
        <div class="help">選択肢ごとに次に表示する質問を設定できます。</div>
        ${(q.options||[]).map((op,oi)=>{
          const selected=q.branch?.[op] || "";
          return `
          <div class="branch-row">
            <span style="min-width:100px">${esc(op)}</span>
            <span>→</span>
            <select onchange="setBranch(${gi},${qi},${oi},this.value)">
              <option value="">次の質問（通常順）</option>
              ${allQuestions(draft).filter(x=>x.id!==q.id).map(x=>
                `<option value="${esc(x.id)}" ${selected===x.id?"selected":""}>${esc(x.number)} ${esc(x.text||"（未入力）")}</option>`
              ).join("")}
          </select>
          </div>`;
        }).join("")}
      </div>` : ""}
  </div>`;
}

/* =========================================================
   編集操作
========================================================= */
function saveSurvey(){
  if(!draft.title.trim()){
    showModal("入力エラー","アンケートタイトルを入力してください。",[
      {label:"閉じる",className:"btn btn-primary",action:null}
    ]);
    return;
  }

  recalcNumbers(draft);
  const now=new Date().toLocaleString("ja-JP",{hour12:false});
  draft.updated=now;

  if(!draft.id){
    draft.id=getNextSurveyId();
    draft.created=new Date().toISOString().slice(0,10);
    draft.status="draft";
    state.surveys.unshift(clone(draft));
    toast("アンケートを下書きとして保存しました");
  }else{
    const index=state.surveys.findIndex(s=>s.id===draft.id);
    if(index>=0)state.surveys[index]=clone(draft);
    toast("変更内容を保存しました");
  }
  state.page="list";
  render();
}

function cancelEdit(){
  showModal(
    "編集内容を破棄しますか？",
    `<p>保存していない変更は失われます。</p>`,
    [
      {label:"キャンセル",className:"btn",action:null},
      {label:"破棄して戻る",className:"btn btn-danger",action:()=>{
        closeModal();
        state.page=state.previousPage==="edit"?"list":state.previousPage;
        render();
      }}
    ]
  );
}

function requestStateChange(newStatus){
  const old=draft.status;
  if(newStatus===old)return;

  const labels={published:"公開",stopped:"停止"};
  if(newStatus==="published" && old==="draft"){
    confirmStateChange("公開", "このアンケートを公開しますか？",old,newStatus);
  }else if(newStatus==="stopped" && old==="published"){
    confirmStateChange("停止", "このアンケートを停止しますか？",old,newStatus);
  }else if(newStatus==="published" && old==="stopped"){
    confirmStateChange("再開", "このアンケートを再開しますか？",old,newStatus);
  }else{
    draft.status=old;
    render();
  }
}

function confirmStateChange(label,message,old,newStatus){
  showModal(message,`<p>状態を「${esc(label)}」へ変更します。</p>`,[
    {label:"キャンセル",className:"btn",action:()=>{
      closeModal();
      draft.status=old;
      render();
    }},
    {label:"実行",className:"btn btn-primary",action:()=>{
      draft.status=newStatus;
      closeModal();
      render();
      toast(`状態を「${statusLabel(newStatus)}」へ変更しました`);
    }}
  ]);
}

function addGroup(){
  draft.groups.push({
    id:uid("g"),title:"新しいグループ",
    questions:[]
  });
  recalcNumbers(draft);
  renderEditorBodyOnly();
}

function deleteGroup(gi){
  const g=draft.groups[gi];
  const count=g.questions.length;
  showModal("グループを削除しますか?",
    `<p>「${esc(g.title)}」を削除します。</p>
     ${count?`<p style="color:#dc2626;font-weight:700">このグループには質問が${count}件あります。質問も削除されます。</p>`:""}`,
    [
      {label:"キャンセル",className:"btn",action:null},
      {label:"削除",className:"btn btn-danger",action:()=>{
        draft.groups.splice(gi,1);
        recalcNumbers(draft);
        closeModal();
        renderEditorBodyOnly();
        toast("グループを削除しました");
      }}
    ]
  );
}

function addQuestion(gi){
  draft.groups[gi].questions.push({
    id:uid("q"),text:"",type:"single",required:false,
    options:["選択肢1","選択肢2"],branch:null
  });
  recalcNumbers(draft);
  renderEditorBodyOnly();
}

function deleteQuestion(gi,qi){
  showModal("質問を削除しますか？",
    `<p>「${esc(draft.groups[gi].questions[qi].text || "未入力の質問")}」を削除します。</p>`,
    [
      {label:"キャンセル",className:"btn",action:null},
      {label:"削除",className:"btn btn-danger",action:()=>{
        draft.groups[gi].questions.splice(qi,1);
        recalcNumbers(draft);
        closeModal();
        renderEditorBodyOnly();
        toast("質問を削除しました");
      }}
    ]
  );
}

function changeQuestionType(gi,qi,type){
  draft.groups[gi].questions[qi].type=type;
  if(type==="single"||type==="multiple"){
    if(!draft.groups[gi].questions[qi].options?.length)
      draft.groups[gi].questions[qi].options=["選択肢1","選択肢2"];
  }else{
    draft.groups[gi].questions[qi].options=[];
    draft.groups[gi].questions[qi].branch=null;
  }
  renderEditorBodyOnly();
}
function addOption(gi,qi){
  draft.groups[gi].questions[qi].options.push("新しい選択肢");
  renderEditorBodyOnly();
}
function removeOption(gi,qi,oi){
  draft.groups[gi].questions[qi].options.splice(oi,1);
  renderEditorBodyOnly();
}
function setBranch(gi,qi,oi,target){
  const q=draft.groups[gi].questions[qi];
  if(!q.branch)q.branch={};
  const option=q.options[oi];
  if(target)q.branch[option]=target;
  else delete q.branch[option];
}

let dragGroupIndex=null;
let dragQuestionData=null;

function dragGroupStart(e,gi){dragGroupIndex=gi}
function dropGroup(e,target){
  if(dragGroupIndex===null||dragGroupIndex===target)return;
  const item=draft.groups.splice(dragGroupIndex,1)[0];
  draft.groups.splice(target,0,item);
  dragGroupIndex=null;
  recalcNumbers(draft);
  renderEditorBodyOnly();
  toast("グループ順を変更しました");
}

function dragQuestionStart(e,gi,qi){
  dragQuestionData={gi,qi};
}
function dropQuestion(e,tgi,tqi){
  if(!dragQuestionData)return;
  const {gi,qi}=dragQuestionData;
  if(gi===tgi && qi===tqi){dragQuestionData=null;return}
  const q=draft.groups[gi].questions.splice(qi,1)[0];
  let insert=tqi;
  if(gi===tgi && qi<tqi)insert--;
  draft.groups[tgi].questions.splice(insert,0,q);
  dragQuestionData=null;
  recalcNumbers(draft);
  renderEditorBodyOnly();
  toast("質問順を変更しました");
}

function moveQuestionToGroup(gi,qi){
  const targets=draft.groups.map((g,i)=>`<option value="${i}">グループ${i+1}：${esc(g.title)}</option>`).join("");
  showModal("質問を別グループへ移動",
    `<p>「${esc(draft.groups[gi].questions[qi].text || "未入力の質問")}」</p>
     <div class="form-group">
       <label>移動先グループ</label>
       <select id="moveTarget" style="width:100%">${targets}</select>
     </div>`,
    [
      {label:"キャンセル",className:"btn",action:null},
      {label:"移動",className:"btn btn-primary",action:()=>{
        const target=Number(document.getElementById("moveTarget").value);
        if(target===gi){toast("現在と同じグループです");return}
        const q=draft.groups[gi].questions.splice(qi,1)[0];
        draft.groups[target].questions.push(q);
        recalcNumbers(draft);
        closeModal();
        renderEditorBodyOnly();
        toast("質問を移動しました");
      }}
    ]
  );
}

function duplicateSurvey(id){
  const s=findSurvey(id);
  showModal("アンケートを複製しますか？",
    `<p>「${esc(s.title)}」を複製します。</p>
     <p class="help">タイトル、説明、期間、グループ、質問、選択肢、必須設定、条件分岐、採番方式を複製します。</p>
     <p class="help">公開状態・回答データ・送信履歴は複製されません。</p>`,
    [
      {label:"キャンセル",className:"btn",action:null},
      {label:"複製する",className:"btn btn-primary",action:()=>{
        const copy=clone(s);
        copy.id=getNextSurveyId();
        copy.title=s.title+"（コピー）";
        copy.status="draft";
        copy.answers=0;
        copy.created=new Date().toISOString().slice(0,10);
        copy.updated=new Date().toLocaleString("ja-JP",{hour12:false});
        state.surveys.unshift(copy);
        closeModal();
        render();
        toast("下書きアンケートを複製しました");
      }}
    ]
  );
}

function deleteSurvey(id){
  const s=findSurvey(id);
  showModal("アンケートを削除しますか？",
    `<p>「${esc(s.title)}」を削除します。</p>
     <p style="color:#dc2626">この操作はモック上の一覧から対象を削除します。</p>`,
    [
      {label:"キャンセル",className:"btn",action:null},
      {label:"削除",className:"btn btn-danger",action:()=>{
        state.surveys=state.surveys.filter(x=>x.id!==id);
        closeModal();
        render();
        toast("アンケートを削除しました");
      }}
    ]
  );
}

/* =========================================================
   プレビュー
========================================================= */
function renderPreview(){
  recalcNumbers(draft);
  return `
  <div class="page-head">
    <div>
      <h1>プレビュー</h1>
      <p>実際の送信は行われません。</p>
    </div>
    <button class="btn" onclick="navigate('edit',{id:draft.id,survey:draft.id?findSurvey(draft.id):null})">編集へ戻る</button>
  </div>

  <div class="preview-shell">
    <div class="preview-toolbar">
      <span class="help">これはプレビュー表示のため送信されません</span>
      <div class="preview-device">
        <button class="btn btn-sm ${state.previewDevice==="pc"?"active":""}" onclick="state.previewDevice='pc';render()">PC</button>
        <button class="btn btn-sm ${state.previewDevice==="phone"?"active":""}" onclick="state.previewDevice='phone';render()">スマートフォン</button>
      </div>
    </div>
    <div class="preview-frame ${state.previewDevice==="phone"?"preview-phone":""}">
      <div class="preview-content">
        ${renderPreviewContent()}
      </div>
    </div>
  </div>`;
}

function renderPreviewContent(){
  return `
    <div class="preview-title">${esc(draft.title||"アンケートタイトル")}</div>
    <div class="preview-desc">${esc(draft.description||"アンケート説明")}</div>
    ${draft.groups.map(g=>`
      <div class="preview-group-title">${esc(g.title)}</div>
      ${g.questions.map(q=>`
        <div class="preview-q">
          <div class="preview-q-title">
            ${esc(q.number)} ${esc(q.text||"質問文を入力してください")}
            ${q.required?'<span class="required">必須</span>':""}
          </div>
          ${renderPreviewInput(q)}
        </div>`).join("")}
    `).join("")}
    <div class="preview-nav">
      <button class="btn" disabled>戻る</button>
      <button class="btn btn-primary" onclick="toast('プレビューのため送信されません')">回答を確認する</button>
    </div>`;
}
function renderPreviewInput(q){
  if(q.type==="single")
    return (q.options||[]).map(o=>`<label class="choice"><input type="radio" name="${esc(q.id)}"> ${esc(o)}</label>`).join("");
  if(q.type==="multiple")
    return (q.options||[]).map(o=>`<label class="choice"><input type="checkbox"> ${esc(o)}</label>`).join("");
  if(q.type==="text")return `<input class="answer-input" placeholder="回答を入力">`;
  return `<textarea class="answer-input" rows="5" placeholder="回答を入力"></textarea>`;
}

/* =========================================================
   顧客送信
========================================================= */
function renderCustomers(){
  const s=findSurvey(state.editingId) || state.surveys[0];
  const selected=window.selectedCustomers || [];
  return `
  <div class="page-head">
    <div>
      <h1>顧客選択・メール送信</h1>
      <p>対象アンケート：${esc(s.title)}</p>
    </div>
    <div>
      <button class="btn" onclick="navigate('history')">送信履歴</button>
    </div>
  </div>

  <div class="mail-layout">
    <div class="card">
      <div class="customer-toolbar">
        <input id="customerSearch" placeholder="顧客名・組織名・メールアドレスで検索" style="flex:1"
          oninput="filterCustomers(this.value)">
        <select id="customerStatusFilter" onchange="filterCustomers()">
          <option value="">ステータス：すべて</option>
          <option>未送信</option>
          <option>送信済み / 未回答</option>
          <option>回答済み</option>
        </select>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th><input type="checkbox" onchange="toggleAllCustomers(this.checked)"></th>
            <th>組織名</th><th>氏名</th><th>メールアドレス</th>
            <th>最終送信</th><th>回答ステータス</th><th>kintone</th>
          </tr></thead>
          <tbody id="customerRows">${customerRows(state.customers)}</tbody>
        </table>
      </div>
      <div style="padding:15px;border-top:1px solid var(--border);display:flex;gap:8px">
        <button class="btn btn-primary" onclick="bulkSend()">選択した顧客へ一括送信</button>
        <button class="btn" onclick="remindCustomers()">未回答者へリマインド</button>
      </div>
    </div>

    <div class="card section mail-preview">
      <h2 class="section-title">メールテンプレート</h2>
      <div class="form-group template-area">
        <label>メール件名</label>
        <input id="mailSubject" value="【アンケートのお願い】サービス満足度アンケート">
      </div>
      <div class="form-group template-area" style="margin-top:15px">
        <label>メール本文</label>
        <textarea id="mailBody">{顧客名} 様

いつもお世話になっております。
以下のアンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力よろしくお願いいたします。</textarea>
      </div>
      <div class="help">利用可能な変数：{顧客名} / {アンケートURL}</div>
    </div>
  </div>`;
}

function customerRows(list){
  const selected=window.selectedCustomers||[];
  return list.map(c=>`
    <tr>
      <td><input type="checkbox" class="customer-check" value="${c.id}" ${selected.includes(c.id)?"checked":""}
        onchange="toggleCustomer(${c.id},this.checked)"></td>
      <td>${esc(c.org)}</td>
      <td>${esc(c.name)}</td>
      <td>${esc(c.email)}</td>
      <td>${esc(c.sent)}</td>
      <td>${esc(c.status)}</td>
      <td>${c.kintone?'<span class="badge badge-ok">登録済み</span>':'<span class="badge badge-info">未登録</span>'}</td>
    </tr>`).join("");
}
function filterCustomers(value){
  const q=value ?? document.getElementById("customerSearch")?.value ?? "";
  const status=document.getElementById("customerStatusFilter")?.value ?? "";
  const list=state.customers.filter(c=>{
    const hit=!q || `${c.org}${c.name}${c.email}`.toLowerCase().includes(q.toLowerCase());
    return hit && (!status || c.status===status);
  });
  document.getElementById("customerRows").innerHTML=customerRows(list);
}
function toggleCustomer(id,checked){
  window.selectedCustomers=window.selectedCustomers||[];
  if(checked&&!window.selectedCustomers.includes(id))window.selectedCustomers.push(id);
  if(!checked)window.selectedCustomers=window.selectedCustomers.filter(x=>x!==id);
}
function toggleAllCustomers(checked){
  window.selectedCustomers=checked?state.customers.map(c=>c.id):[];
  filterCustomers();
}
function bulkSend(){
  const ids=window.selectedCustomers||[];
  if(!ids.length){toast("送信対象を選択してください");return}
  const already=state.customers.filter(c=>ids.includes(c.id)&&c.count>0);
  showModal("メールを一括送信しますか？",
    `<p>${ids.length}件の顧客へ送信します。</p>
     ${already.length?`<p style="color:#d97706">既に送信済みの宛先が${already.length}件含まれています。再送しますか？</p>`:""}`,
    [
      {label:"キャンセル",className:"btn",action:null},
      {label:"送信する",className:"btn btn-primary",action:()=>{
        state.customers.forEach(c=>{
          if(ids.includes(c.id)){
            c.sent=new Date().toLocaleString("ja-JP",{hour12:false});
            c.count++;
            if(c.status==="未送信")c.status="送信済み / 未回答";
          }
        });
        window.selectedCustomers=[];
        closeModal();
        render();
        toast("モック：メール送信成功");
      }}
    ]
  );
}
function remindCustomers(){
  const ids=state.customers.filter(c=>c.status==="送信済み / 未回答").map(c=>c.id);
  if(!ids.length){toast("未回答者はいません");return}
  window.selectedCustomers=ids;
  toast(`${ids.length}件の未回答者を選択しました`);
  render();
}

/* =========================================================
   履歴
========================================================= */
function renderHistory(){
  return `
  <div class="page-head">
    <div><h1>送信履歴</h1><p>送信済みメールの内容を確認できます。</p></div>
    <button class="btn" onclick="navigate('list')">一覧へ</button>
  </div>
  <div class="card table-wrap">
    <table>
      <thead><tr><th>送信日時</th><th>送信種別</th><th>送信件数</th><th>送信件名</th><th>送信実行者</th><th>対象顧客</th><th>操作</th></tr></thead>
      <tbody>
        <tr>
          <td>2026/08/22 16:20</td><td>一括送信</td><td>4件</td>
          <td>【アンケートのお願い】サービス満足度アンケート</td><td>管理者</td><td>4名</td>
          <td><button class="btn btn-sm" onclick="showMailHistory()">内容を確認</button></td>
        </tr>
        <tr>
          <td>2026/08/20 10:15</td><td>リマインド</td><td>2件</td>
          <td>【再送】アンケートご回答のお願い</td><td>管理者</td><td>2名</td>
          <td><button class="btn btn-sm" onclick="showMailHistory()">内容を確認</button></td>
        </tr>
      </tbody>
    </table>
  </div>`;
}
function showMailHistory(){
  showModal("送信済みメール",
    `<div class="form-group">
       <label>件名</label>
       <input style="width:100%" value="【アンケートのお願い】サービス満足度アンケート" readonly>
     </div>
     <div class="form-group" style="margin-top:15px">
       <label>顧客名差し込み後の本文</label>
       <textarea rows="10" style="width:100%" readonly>山田 太郎 様

いつもお世話になっております。
以下のアンケートへのご回答をお願いいたします。

https://example.jp/survey/abc123

ご協力よろしくお願いいたします。</textarea>
     </div>
     <div class="help">個別アンケートURL：モックURL</div>`,
    [{label:"閉じる",className:"btn btn-primary",action:null}]
  );
}

/* =========================================================
   集計
========================================================= */
function renderAnalysis(){
  const id=state.editingId || state.surveys[0].id;
  const s=findSurvey(id)||state.surveys[0];
  const rate=s.target?Math.round(s.answers/s.target*100):0;
  return `
  <div class="page-head">
    <div><h1>回答集計・分析</h1><p>集計対象：${esc(s.title)}</p></div>
    <div class="actions">
      <button class="btn" onclick="exportMock('CSV')">CSVダウンロード</button>
      <button class="btn" onclick="exportMock('PDF')">PDF出力</button>
      <button class="btn" onclick="navigate('list')">一覧へ</button>
    </div>
  </div>

  <div class="summary-grid">
    ${summaryCard("送信対象者数",s.target)}
    ${summaryCard("回答数",s.answers)}
    ${summaryCard("未登録顧客からの回答数",8)}
    ${summaryCard("未回答数",Math.max(0,s.target-s.answers))}
    ${summaryCard("回答率",rate+"%")}
  </div>

  <div class="card section">
    <h2 class="section-title">設問フィルター</h2>
    <div style="display:flex;gap:8px;margin-bottom:15px">
      <button class="btn btn-sm" onclick="document.querySelectorAll('.question-filter').forEach(x=>x.checked=true)">すべて選択</button>
      <button class="btn btn-sm" onclick="document.querySelectorAll('.question-filter').forEach(x=>x.checked=false)">すべて解除</button>
    </div>
    ${allQuestions(s).map(q=>`
      <label style="display:block;margin:9px 0">
        <input class="question-filter" type="checkbox" checked>
        ${esc(q.number)} ${esc(q.text||"未入力")}
      </label>`).join("")}
  </div>

  <div class="card section">
    <h2 class="section-title">設問別集計</h2>
    ${allQuestions(s).map(q=>renderAnalysisQuestion(q)).join("")}
  </div>

  <div class="card section">
    <h2 class="section-title">個別回答</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>組織名</th><th>氏名</th><th>回答日時</th><th>回答概要</th><th></th></tr></thead>
        <tbody>
          <tr><td>株式会社サンプル</td><td>山田 太郎</td><td>2026/08/23 11:20</td><td>満足 / ぜひ利用したい</td><td><button class="btn btn-sm" onclick="showIndividualAnswer()">全回答を表示</button></td></tr>
          <tr><td>株式会社テスト</td><td>佐藤 花子</td><td>2026/08/24 14:02</td><td>普通 / 利用したい</td><td><button class="btn btn-sm" onclick="showIndividualAnswer()">全回答を表示</button></td></tr>
        </tbody>
      </table>
    </div>
  </div>`;
}
function summaryCard(label,value){
  return `<div class="card summary-card"><div class="summary-label">${label}</div><div class="summary-value">${value}</div></div>`;
}
function renderAnalysisQuestion(q){
  if(q.type!=="single"&&q.type!=="multiple")
    return `<div class="chart-row"><strong>${esc(q.number)} ${esc(q.text)}</strong><p class="help">自由記述回答一覧：回答データを表示します。</p></div>`;
  const values=(q.options||[]).map((o,i)=>({o,n:[42,35,28,15,8][i]||5}));
  const total=values.reduce((a,b)=>a+b.n,0);
  return `
  <div class="chart-row">
    <strong>${esc(q.number)} ${esc(q.text)}</strong>
    ${values.map(v=>`
      <div style="margin-top:12px">
        <div class="chart-label"><span>${esc(v.o)}</span><span>${v.n}件 / ${Math.round(v.n/total*100)}%</span></div>
        <div class="bar"><span style="width:${Math.round(v.n/total*100)}%"></span></div>
      </div>`).join("")}
  </div>`;
}
function exportMock(type){
  toast(`モック：${type}出力操作を実行しました`);
}
function showIndividualAnswer(){
  showModal("個別回答",
    `<p><strong>株式会社サンプル / 山田 太郎</strong></p>
     <p class="help">回答日時：2026/08/23 11:20</p>
     <hr>
     <p><strong>Q1 サービスを利用したことがありますか？</strong><br>はい</p>
     <p><strong>Q2 サービスの満足度を教えてください。</strong><br>満足</p>
     <p><strong>Q3 ご意見・ご要望</strong><br>今後も利用したいと思います。</p>`,
    [{label:"閉じる",className:"btn btn-primary",action:null}]
  );
}

/* =========================================================
   kintone
========================================================= */
function renderKintone(){
  const k=state.settings.kintone;
  return `
  <div class="page-head">
    <div><h1>kintone連携設定</h1><p>顧客情報との連携設定を行います。</p></div>
  </div>

  <div class="settings-grid">
    <div class="card section">
      <h2 class="section-title">接続設定</h2>
      ${settingsInput("サブドメイン","kSub",k.subdomain)}
      ${settingsInput("顧客管理アプリID","kApp",k.appId)}
      ${settingsInput("ログイン名","kLogin",k.login)}
      ${settingsInput("パスワード","kPass",k.password,"password")}
      <label style="display:flex;gap:8px;margin:12px 0">
        <input type="checkbox" id="kSsl" ${k.ssl?"checked":""}> SSL証明書を検証する
      </label>
      <div style="margin:15px 0">${k.connection==="connected"?'<span class="badge badge-ok">接続確認済み</span>':'<span class="badge badge-error">接続できません</span>'}</div>
      <div class="actions">
        <button class="btn btn-primary" onclick="saveKintone()">設定を保存</button>
        <button class="btn" onclick="testKintone()">接続確認</button>
      </div>
    </div>

    <div class="card section">
      <h2 class="section-title">kintone項目</h2>
      <button class="btn btn-primary" onclick="refreshKintoneFields()">項目一覧を再取得</button>
      <div id="kFields" style="margin-top:15px">
        ${k.fields.map(x=>`<span class="badge badge-info" style="margin:3px">${esc(x)}</span>`).join("")}
      </div>
      <div style="margin-top:22px">
        <h3 style="font-size:15px">フィールドマッピング</h3>
        ${mappingSelect("組織名","org",k.mapping.org,k.fields)}
        ${mappingSelect("氏名","name",k.mapping.name,k.fields)}
        ${mappingSelect("メールアドレス","email",k.mapping.email,k.fields)}
        ${mappingSelect("部署名","department",k.mapping.department,k.fields)}
        ${mappingSelect("電話番号","phone",k.mapping.phone,k.fields)}
      </div>
    </div>

    <div class="card section">
      <h2 class="section-title">住所マッピング</h2>
      <p class="help">複数フィールドを選択して住所として扱います。</p>
      <div class="mapping">
        ${["都道府県","市区町村","番地","建物名","郵便番号"].map(f=>`
          <label><input type="checkbox" class="address-map" value="${esc(f)}" ${k.mapping.address.includes(f)?"checked":""}> ${esc(f)}</label>
        `).join("")}
      </div>
      <button class="btn btn-primary" style="margin-top:15px" onclick="saveKintone()">マッピングを保存</button>
    </div>

    <div class="card section">
      <h2 class="section-title">同期</h2>
      <p>顧客情報をモック上で同期します。</p>
      <button class="btn btn-primary" onclick="syncCustomers()">顧客情報を同期</button>
      <div style="margin-top:15px"><span class="badge badge-ok">顧客同期済み</span></div>
    </div>
  </div>`;
}
function settingsInput(label,id,value,type="text"){
  return `<div class="settings-field"><label>${label}</label><input id="${id}" type="${type}" value="${esc(value)}"></div>`;
}
function mappingSelect(label,key,value,fields){
  return `<div class="settings-field"><label>${label}</label>
    <select onchange="state.settings.kintone.mapping['${key}']=this.value">
      ${fields.map(f=>`<option ${f===value?"selected":""}>${esc(f)}</option>`).join("")}
    </select></div>`;
}
function saveKintone(){
  const k=state.settings.kintone;
  k.subdomain=document.getElementById("kSub").value;
  k.appId=document.getElementById("kApp").value;
  k.login=document.getElementById("kLogin").value;
  k.password=document.getElementById("kPass").value;
  k.ssl=document.getElementById("kSsl").checked;
  k.mapping.address=[...document.querySelectorAll(".address-map:checked")].map(x=>x.value);
  toast("kintone設定を保存しました");
}
function testKintone(){
  state.settings.kintone.connection="connected";
  render();
  toast("モック：kintone接続確認済み");
}
function refreshKintoneFields(){
  toast("モック：項目一覧を再取得しました");
}
function syncCustomers(){
  state.customers.forEach(c=>c.kintone=true);
  toast("モック：顧客情報を同期しました");
}

/* =========================================================
   メール設定
========================================================= */
function renderMail(){
  const m=state.settings.mail;
  return `
  <div class="page-head">
    <div><h1>メールサーバ設定</h1><p>SMTP接続設定を管理します。</p></div>
  </div>
  <div class="card section" style="max-width:850px">
    <h2 class="section-title">SMTP設定</h2>
    <div class="settings-grid">
      <div>
        ${settingsInput("SMTPサーバ","mHost",m.host)}
        ${settingsInput("SMTPポート","mPort",m.port)}
        <div class="settings-field"><label>暗号化方式</label>
          <select id="mEncryption">
            <option ${m.encryption==="SSL"?"selected":""}>SSL</option>
            <option ${m.encryption==="TLS"?"selected":""}>TLS</option>
            <option ${m.encryption==="なし"?"selected":""}>なし</option>
          </select>
        </div>
        <label><input type="checkbox" id="mAuth" ${m.auth?"checked":""}> SMTP認証を使用する</label>
      </div>
      <div>
        ${settingsInput("SMTPユーザー名","mUser",m.user)}
        ${settingsInput("SMTPパスワード","mPass",m.password,"password")}
        ${settingsInput("送信元メールアドレス","mFrom",m.from)}
        ${settingsInput("送信元名","mFromName",m.fromName)}
        ${settingsInput("返信先メールアドレス","mReply",m.reply)}
      </div>
    </div>
    <div style="margin:15px 0">${m.status==="connected"?'<span class="badge badge-ok">接続確認済み</span>':'<span class="badge badge-error">接続できません</span>'}</div>
    <div class="actions">
      <button class="btn btn-primary" onclick="saveMail()">設定を保存</button>
      <button class="btn" onclick="testMail()">接続確認</button>
      <button class="btn" onclick="sendTestMail()">テストメールを送信</button>
    </div>
  </div>`;
}
function saveMail(){
  const m=state.settings.mail;
  m.host=document.getElementById("mHost").value;
  m.port=document.getElementById("mPort").value;
  m.encryption=document.getElementById("mEncryption").value;
  m.auth=document.getElementById("mAuth").checked;
  m.user=document.getElementById("mUser").value;
  m.password=document.getElementById("mPass").value;
  m.from=document.getElementById("mFrom").value;
  m.fromName=document.getElementById("mFromName").value;
  m.reply=document.getElementById("mReply").value;
  toast("メールサーバ設定を保存しました");
}
function testMail(){
  state.settings.mail.status="connected";
  render();
  toast("モック：メールサーバ接続確認済み");
}
function sendTestMail(){
  showModal("テストメールを送信",
    `<p>モックでは実際のメール送信は行いません。</p><p>送信成功状態を再現します。</p>`,
    [
      {label:"キャンセル",className:"btn",action:null},
      {label:"送信",className:"btn btn-primary",action:()=>{
        closeModal();
        toast("モック：テストメール送信成功");
      }}
    ]
  );
}

/* =========================================================
   回答者画面
========================================================= */
let answerValues={};

function renderRespondentPage(){
  const s=state.surveys[0];
  recalcNumbers(s);

  if(state.page==="answerComplete"){
    return `
    <div class="respondent-page">
      <div class="respondent-wrap">
        <div class="respondent-card completed">
          <div class="completed-icon">✓</div>
          <h1>回答ありがとうございました</h1>
          <p>アンケートの回答を受け付けました。</p>
        </div>
      </div>
    </div>`;
  }

  if(state.page==="answerConfirm"){
    return `
    <div class="respondent-page">
      <div class="respondent-wrap">
        <div class="respondent-card">
          <div class="respondent-brand">アンケート回答</div>
          <h1>回答内容の確認</h1>
          <p class="help">入力内容をご確認ください。</p>
          ${allQuestions(s).map(q=>`
            <div class="answer-q">
              <div class="answer-q-title">${esc(q.number)} ${esc(q.text)} ${q.required?'<span class="required">必須</span>':""}</div>
              <div>${esc(displayAnswer(answerValues[q.id]))}</div>
            </div>`).join("")}
          <div class="answer-actions">
            <button class="btn" onclick="state.page='respondent';render()">戻る</button>
            <button class="btn btn-primary" onclick="confirmSubmitAnswer()">回答を送信する</button>
          </div>
        </div>
      </div>
    </div>`;
  }

  return `
  <div class="respondent-page">
    <div class="respondent-wrap">
      <div class="respondent-card">
        <div class="respondent-brand">アンケート回答</div>
        <h1>${esc(s.title)}</h1>
        <p style="white-space:pre-wrap;color:#667085">${esc(s.description)}</p>
        <hr style="border:0;border-top:1px solid #edf0f4;margin:25px 0">

        ${s.groups.map(g=>`
          <h2 class="preview-group-title">${esc(g.title)}</h2>
          ${g.questions.map(q=>renderAnswerQuestion(q)).join("")}
        `).join("")}

        <div class="answer-actions">
          <button class="btn" onclick="respondentBack()">戻る</button>
          <button class="btn btn-primary" onclick="goAnswerConfirm()">回答確認</button>
        </div>
      </div>
    </div>
  </div>`;
}

function renderAnswerQuestion(q){
  return `
  <div class="answer-q">
    <div class="answer-q-title">
      ${esc(q.number)} ${esc(q.text)}
      ${q.required?'<span class="required">必須</span>':""}
    </div>
    ${answerInput(q)}
    <div class="answer-error" id="err-${q.id}" style="display:none;color:#dc2626;font-size:13px;margin-top:7px">
      この質問は必須です。
    </div>
  </div>`;
}
function answerInput(q){
  const v=answerValues[q.id];
  if(q.type==="single"){
    return (q.options||[]).map(o=>`
      <label class="choice">
        <input type="radio" name="answer-${esc(q.id)}" value="${esc(o)}"
          ${v===o?"checked":""} onchange="answerValues['${q.id}']=this.value;applyBranch()">
        ${esc(o)}
      </label>`).join("");
  }
  if(q.type==="multiple"){
    return (q.options||[]).map(o=>`
      <label class="choice">
        <input type="checkbox" value="${esc(o)}"
          ${(Array.isArray(v)&&v.includes(o))?"checked":""}
          onchange="toggleMultipleAnswer('${q.id}',this.value,this.checked)">
        ${esc(o)}
      </label>`).join("");
  }
  if(q.type==="text")
    return `<input class="answer-input" value="${esc(v||"")}" oninput="answerValues['${q.id}']=this.value">`;
  return `<textarea class="answer-input" rows="5" oninput="answerValues['${q.id}']=this.value">${esc(v||"")}</textarea>`;
}
function toggleMultipleAnswer(id,value,checked){
  if(!Array.isArray(answerValues[id]))answerValues[id]=[];
  if(checked)answerValues[id].push(value);
  else answerValues[id]=answerValues[id].filter(x=>x!==value);
}
function displayAnswer(v){
  if(Array.isArray(v))return v.join("、")||"未回答";
  return v||"未回答";
}
function applyBranch(){
  /*
   * モックでは回答値に応じて表示対象を視覚的に変える。
   * 実運用の複雑な分岐エンジンではなく、画面確認用。
   */
  render();
}
function validateAnswers(){
  const s=state.surveys[0];
  let ok=true;
  allQuestions(s).forEach(q=>{
    const v=answerValues[q.id];
    const empty=Array.isArray(v)?v.length===0:!v;
    const el=document.getElementById("err-"+q.id);
    if(q.required&&empty){
      ok=false;
      if(el)el.style.display="block";
    }else if(el){
      el.style.display="none";
    }
  });
  return ok;
}
function goAnswerConfirm(){
  if(!validateAnswers()){
    toast("未回答の必須項目があります");
    return;
  }
  state.page="answerConfirm";
  render();
}
function confirmSubmitAnswer(){
  showModal("回答を送信しますか？",
    `<p>送信後は回答内容を変更できません。</p>`,
    [
      {label:"キャンセル",className:"btn",action:null},
      {label:"回答を送信する",className:"btn btn-primary",action:()=>{
        closeModal();
        state.page="answerComplete";
        state.answerSubmitted=true;
        render();
      }}
    ]
  );
}
function respondentBack(){
  toast("モック：前の質問位置へ戻ります");
}

/* =========================================================
   初期表示
========================================================= */
render();
</script>
</body>
</html>
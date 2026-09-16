<?php
/*
 * アンケート管理アプリ モック
 * 1ファイル完結版
 * PHP 5.8 / Apache 24 想定
 *
 * ※モック確認用のため、データはブラウザ上のJavaScript内で保持します。
 * ※画面遷移・入力・状態変更・回答・集計などの操作確認を目的としています。
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理</title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
    color:#263238;
    background:#f4f6f8;
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.app{
    min-height:100vh;
}
.header{
    height:64px;
    background:#17324d;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 28px;
}
.logo{
    font-size:20px;
    font-weight:700;
}
.header-sub{
    font-size:13px;
    opacity:.8;
}
.layout{
    display:flex;
    min-height:calc(100vh - 64px);
}
.sidebar{
    width:230px;
    background:#fff;
    border-right:1px solid #dfe5ea;
    padding:18px 12px;
}
.nav-title{
    font-size:11px;
    color:#87939d;
    font-weight:bold;
    padding:10px 12px 7px;
    letter-spacing:.08em;
}
.nav button{
    width:100%;
    border:0;
    background:none;
    text-align:left;
    padding:12px;
    border-radius:7px;
    color:#46535d;
    margin-bottom:3px;
}
.nav button:hover,
.nav button.active{
    background:#eaf2f8;
    color:#17517d;
    font-weight:600;
}
.main{
    flex:1;
    padding:28px;
    max-width:1400px;
}
.page-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:22px;
}
.page-title{
    margin:0;
    font-size:26px;
    color:#182b3a;
}
.page-desc{
    margin:7px 0 0;
    color:#687780;
    font-size:14px;
}
.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.btn{
    border:1px solid #cbd5dc;
    background:#fff;
    color:#334650;
    padding:9px 15px;
    border-radius:6px;
    font-weight:600;
}
.btn:hover{background:#f5f7f8}
.btn.primary{
    background:#1769aa;
    color:#fff;
    border-color:#1769aa;
}
.btn.primary:hover{background:#125789}
.btn.success{
    background:#247a55;
    color:#fff;
    border-color:#247a55;
}
.btn.danger{
    background:#b94343;
    color:#fff;
    border-color:#b94343;
}
.btn.small{
    padding:6px 10px;
    font-size:12px;
}
.btn.link{
    border:0;
    background:none;
    color:#1769aa;
    padding:4px 6px;
}
.card{
    background:#fff;
    border:1px solid #dfe5ea;
    border-radius:9px;
    padding:20px;
    margin-bottom:18px;
    box-shadow:0 1px 2px rgba(0,0,0,.03);
}
.card-title{
    font-size:17px;
    font-weight:700;
    margin-bottom:15px;
    color:#263a47;
}
.stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:20px;
}
.stat{
    background:#fff;
    border:1px solid #dfe5ea;
    border-radius:9px;
    padding:18px;
}
.stat-label{
    font-size:13px;
    color:#73808a;
}
.stat-num{
    font-size:30px;
    font-weight:700;
    margin-top:6px;
    color:#17324d;
}
.table-wrap{
    overflow-x:auto;
}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    padding:13px 12px;
    border-bottom:1px solid #e5eaee;
    text-align:left;
    vertical-align:middle;
    font-size:13px;
}
th{
    color:#66747d;
    background:#f8fafb;
    font-weight:700;
}
tr:hover td{background:#fbfcfd}
.status{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:4px 9px;
    border-radius:20px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}
.status.draft{background:#eef1f3;color:#596770}
.status.wait{background:#fff3d6;color:#8a6100}
.status.open{background:#e4f4eb;color:#17613f}
.status.closed{background:#f3e7e7;color:#914141}
.status.archive{background:#e8e8f3;color:#53537d}
.muted{color:#7a8790}
.empty{
    text-align:center;
    color:#78858e;
    padding:45px 20px;
}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
.form-group{
    margin-bottom:15px;
}
.form-group.full{grid-column:1/-1}
label{
    display:block;
    font-size:13px;
    font-weight:700;
    color:#465760;
    margin-bottom:6px;
}
.required{
    color:#c53d3d;
    font-size:11px;
    margin-left:4px;
}
input[type=text],
input[type=datetime-local],
textarea,
select{
    width:100%;
    border:1px solid #cbd5dc;
    border-radius:6px;
    padding:10px 11px;
    background:#fff;
    color:#263238;
}
textarea{min-height:95px;resize:vertical}
.help{
    color:#7c8992;
    font-size:12px;
    margin-top:5px;
}
.notice{
    padding:13px 15px;
    border-radius:7px;
    margin-bottom:15px;
    font-size:13px;
}
.notice.info{background:#eaf3fa;color:#245779}
.notice.warn{background:#fff5dc;color:#7a5a10}
.notice.success{background:#e8f5ee;color:#1e6443}
.notice.error{background:#fbeaea;color:#8c3333}
.question-card{
    border:1px solid #dce4e9;
    border-radius:8px;
    padding:17px;
    margin-bottom:12px;
    background:#fff;
}
.question-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:13px;
}
.question-no{
    font-weight:700;
    color:#17324d;
}
.question-actions{
    display:flex;
    gap:4px;
}
.option-row{
    display:flex;
    gap:7px;
    margin:7px 0;
}
.option-row input{flex:1}
.preview{
    max-width:800px;
    margin:auto;
}
.preview-question{
    padding:17px 0;
    border-bottom:1px solid #e2e7eb;
}
.preview-question:last-child{border-bottom:0}
.q-label{
    font-weight:700;
    line-height:1.6;
    margin-bottom:10px;
}
.required-badge{
    display:inline-block;
    color:#a62f2f;
    background:#fbe9e9;
    border-radius:4px;
    padding:2px 6px;
    font-size:11px;
    margin-left:7px;
}
.radio-list label,
.check-list label{
    display:flex;
    gap:8px;
    align-items:center;
    font-weight:400;
    margin:8px 0;
}
.progress{
    height:8px;
    background:#e7ecef;
    border-radius:8px;
    overflow:hidden;
}
.progress > div{
    height:100%;
    background:#2676aa;
}
.progress-label{
    display:flex;
    justify-content:space-between;
    color:#66757e;
    font-size:12px;
    margin-bottom:6px;
}
.detail-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:12px;
}
.detail-box{
    background:#f7f9fa;
    padding:13px;
    border-radius:7px;
}
.detail-label{
    font-size:11px;
    color:#7c8991;
}
.detail-value{
    margin-top:4px;
    font-weight:700;
}
.answer-card{
    border:1px solid #dce4e9;
    border-radius:8px;
    padding:15px;
    margin-bottom:10px;
}
.answer-header{
    display:flex;
    justify-content:space-between;
    margin-bottom:12px;
}
.answer-item{
    padding:9px 0;
    border-top:1px solid #edf0f2;
}
.answer-q{
    font-size:12px;
    color:#71808a;
}
.answer-a{
    margin-top:3px;
}
.breadcrumb{
    font-size:12px;
    color:#78858d;
    margin-bottom:10px;
}
.stepper{
    display:flex;
    margin-bottom:20px;
    background:#fff;
    border:1px solid #dfe5ea;
    border-radius:8px;
    overflow:hidden;
}
.step{
    flex:1;
    padding:11px 8px;
    text-align:center;
    font-size:12px;
    color:#839099;
    border-right:1px solid #e4e8eb;
}
.step:last-child{border-right:0}
.step.active{
    color:#175a86;
    background:#edf5fa;
    font-weight:700;
}
.step.done{
    color:#26724e;
}
.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(18,32,43,.46);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:1000;
    padding:20px;
}
.modal{
    width:min(500px,100%);
    background:#fff;
    border-radius:10px;
    padding:23px;
    box-shadow:0 15px 50px rgba(0,0,0,.2);
}
.modal h3{margin:0 0 10px}
.modal p{font-size:14px;line-height:1.7;color:#586771}
.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:20px;
}
.hidden{display:none!important}
.notice-list{
    display:flex;
    flex-direction:column;
    gap:8px;
}
.notice-item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:12px 14px;
    background:#f8fafb;
    border-radius:7px;
}
.notice-item-title{font-weight:700;font-size:13px}
.notice-item-sub{font-size:12px;color:#78858e;margin-top:3px}
.search{
    max-width:300px;
}
.filter-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:14px;
}
@media(max-width:900px){
    .sidebar{width:190px}
    .stats{grid-template-columns:repeat(2,1fr)}
    .detail-grid{grid-template-columns:1fr}
}
@media(max-width:700px){
    .header{padding:0 15px}
    .sidebar{display:none}
    .main{padding:18px 13px}
    .form-grid{grid-template-columns:1fr}
    .form-group.full{grid-column:auto}
    .stats{grid-template-columns:1fr 1fr}
    .page-head{flex-direction:column}
    .step{font-size:10px;padding:9px 4px}
}
</style>
</head>
<body>
<div class="app">
<header class="header">
    <div class="logo">アンケート管理</div>
    <div class="header-sub">運営管理画面</div>
</header>

<div class="layout">
<aside class="sidebar">
    <div class="nav-title">MENU</div>
    <nav class="nav">
        <button onclick="showPage('home')" id="nav-home">ホーム</button>
        <button onclick="showPage('list')" id="nav-list">アンケート一覧</button>
        <button onclick="startNewSurvey()" id="nav-new">新しいアンケートを作成</button>
        <button onclick="showPage('responses')" id="nav-responses">回答状況</button>
        <button onclick="showPage('archive')" id="nav-archive">保管済み</button>
    </nav>
    <div class="nav-title" style="margin-top:20px">回答者確認</div>
    <nav class="nav">
        <button onclick="openRespondentDemo()">回答画面を確認</button>
    </nav>
</aside>

<main class="main" id="main"></main>
</div>
</div>

<div id="modalArea"></div>

<script>
var surveys = [
    {
        id:1,
        name:"社員満足度アンケート 2026",
        description:"職場環境や業務についてのアンケートです。",
        guidance:"率直なご意見をお聞かせください。回答時間は約5分です。",
        start:"2026-09-01T09:00",
        end:"2026-09-30T18:00",
        complete:"ご回答ありがとうございました。",
        status:"open",
        created:"2026/08/20",
        updated:"2026/09/01",
        questions:[
            {text:"現在の職場環境に満足していますか？",type:"radio",required:true,options:["とても満足","満足","どちらともいえない","不満","とても不満"]},
            {text:"現在の業務で良いと感じている点を教えてください。",type:"text",required:false,options:[]},
            {text:"会社に改善してほしいことを教えてください。",type:"textarea",required:false,options:[]},
            {text:"会社全体としての満足度を5段階で評価してください。",type:"scale",required:true,options:["1","2","3","4","5"]}
        ],
        answers:[
            {no:1,date:"2026/09/05 10:24",values:["満足","チーム内のコミュニケーション","会議の効率化","4"]},
            {no:2,date:"2026/09/06 14:12",values:["とても満足","相談しやすい環境","特にありません","5"]},
            {no:3,date:"2026/09/08 09:51",values:["どちらともいえない","業務の自由度","情報共有の改善","3"]},
            {no:4,date:"2026/09/10 16:30",values:["満足","人間関係","設備の改善","4"]},
            {no:5,date:"2026/09/12 11:05",values:["満足","働きやすさ","業務マニュアルの充実","4"]}
        ]
    },
    {
        id:2,
        name:"新サービス利用前アンケート",
        description:"新しいサービスについての事前アンケートです。",
        guidance:"サービス開始前のご意見をお聞かせください。",
        start:"2026-09-20T09:00",
        end:"2026-10-05T18:00",
        complete:"ご協力ありがとうございました。",
        status:"wait",
        created:"2026/09/10",
        updated:"2026/09/15",
        questions:[
            {text:"新サービスに期待していますか？",type:"radio",required:true,options:["とても期待している","期待している","どちらともいえない","あまり期待していない","期待していない"]},
            {text:"期待する機能を教えてください。",type:"checkbox",required:false,options:["使いやすさ","料金","サポート","機能の豊富さ"]}
        ],
        answers:[]
    },
    {
        id:3,
        name:"2026年上期研修アンケート",
        description:"研修内容についてのアンケートです。",
        guidance:"今後の研修改善のため、ご意見をお願いします。",
        start:"2026-04-01T09:00",
        end:"2026-04-30T18:00",
        complete:"ご回答ありがとうございました。",
        status:"closed",
        created:"2026/03/15",
        updated:"2026/05/01",
        questions:[
            {text:"研修内容はいかがでしたか？",type:"radio",required:true,options:["とても良い","良い","普通","あまり良くない","良くない"]},
            {text:"今後取り上げてほしいテーマを教えてください。",type:"textarea",required:false,options:[]}
        ],
        answers:[
            {no:1,date:"2026/04/03 10:12",values:["とても良い","リーダーシップ研修"]},
            {no:2,date:"2026/04/05 15:21",values:["良い","プレゼンテーション研修"]},
            {no:3,date:"2026/04/08 09:33",values:["良い","データ活用研修"]}
        ]
    },
    {
        id:4,
        name:"2025年度社内イベント振り返り",
        description:"昨年度の社内イベントに関するアンケートです。",
        guidance:"",
        start:"2025-10-01T09:00",
        end:"2025-10-31T18:00",
        complete:"ありがとうございました。",
        status:"archive",
        created:"2025/09/10",
        updated:"2025/11/01",
        questions:[
            {text:"イベント全体の満足度を教えてください。",type:"scale",required:true,options:["1","2","3","4","5"]},
            {text:"ご意見があれば教えてください。",type:"textarea",required:false,options:[]}
        ],
        answers:[
            {no:1,date:"2025/10/05 11:10",values:["5","楽しかったです。"]},
            {no:2,date:"2025/10/07 16:21",values:["4","次回も参加したいです。"]}
        ]
    }
];

var nextId = 100;
var currentSurvey = null;
var editingSurvey = null;
var responseSurvey = null;
var responseAnswers = [];
var responseStep = 1;
var currentPage = "home";

function esc(s){
    return String(s == null ? "" : s)
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;");
}

function statusLabel(s){
    var m={
        draft:"作成中",
        wait:"回答開始待ち",
        open:"回答受付中",
        closed:"回答受付終了",
        archive:"保管"
    };
    return m[s] || s;
}

function statusHtml(s){
    return '<span class="status '+s+'">'+esc(statusLabel(s))+'</span>';
}

function formatDateTime(v){
    if(!v)return "未設定";
    return v.replace("T"," ");
}

function countStatus(s){
    return surveys.filter(function(x){return x.status===s}).length;
}

function showPage(page){
    currentPage=page;
    var ids=["home","list","responses","archive"];
    ids.forEach(function(id){
        var el=document.getElementById("nav-"+id);
        if(el)el.classList.toggle("active",page===id);
    });
    if(page==="home")renderHome();
    if(page==="list")renderList();
    if(page==="responses")renderResponses();
    if(page==="archive")renderArchive();
}

function renderHome(){
    var main=document.getElementById("main");
    var active=surveys.filter(function(s){return s.status==="open"||s.status==="wait"});
    var needs=surveys.filter(function(s){return s.status==="draft"||s.status==="wait"});
    main.innerHTML=
    '<div class="page-head">'+
        '<div><h1 class="page-title">ホーム</h1><p class="page-desc">アンケートの運営状況を確認できます。</p></div>'+
        '<div class="actions"><button class="btn primary" onclick="startNewSurvey()">＋ 新しいアンケートを作成</button></div>'+
    '</div>'+
    '<div class="stats">'+
        statBox("作成中",countStatus("draft"))+
        statBox("回答開始待ち",countStatus("wait"))+
        statBox("回答受付中",countStatus("open"))+
        statBox("回答受付終了",countStatus("closed"))+
    '</div>'+
    '<div class="card">'+
        '<div class="card-title">対応が必要なアンケート</div>'+
        '<div class="notice-list">'+
        (needs.length ? needs.map(function(s){
            return '<div class="notice-item"><div><div class="notice-item-title">'+esc(s.name)+'</div><div class="notice-item-sub">'+
                (s.status==="draft"?"作成途中です。内容を確認して公開できます。":"公開済みですが、回答開始前です。")+
                '</div></div><div>'+statusHtml(s.status)+' <button class="btn small" onclick="openSurvey('+s.id+')">確認する</button></div></div>';
        }).join("") : '<div class="empty">対応が必要なアンケートはありません。</div>')+
        '</div>'+
    '</div>'+
    '<div class="card">'+
        '<div class="card-title">最近更新したアンケート</div>'+
        '<div class="table-wrap"><table><thead><tr><th>アンケート名</th><th>状態</th><th>更新日</th><th>回答数</th><th></th></tr></thead><tbody>'+
        surveys.filter(function(s){return s.status!=="archive"}).slice(0,5).map(function(s){
            return '<tr><td><strong>'+esc(s.name)+'</strong></td><td>'+statusHtml(s.status)+'</td><td>'+esc(s.updated)+'</td><td>'+s.answers.length+'件</td><td><button class="btn small" onclick="openSurvey('+s.id+')">開く</button></td></tr>';
        }).join("")+
        '</tbody></table></div>'+
    '</div>';
}

function statBox(label,num){
    return '<div class="stat"><div class="stat-label">'+label+'</div><div class="stat-num">'+num+'</div></div>';
}

function renderList(){
    document.getElementById("main").innerHTML=
    '<div class="page-head">'+
        '<div><h1 class="page-title">アンケート一覧</h1><p class="page-desc">アンケートの状態を確認し、状態に応じた操作を行えます。</p></div>'+
        '<div class="actions"><button class="btn primary" onclick="startNewSurvey()">＋ 新しいアンケートを作成</button></div>'+
    '</div>'+
    '<div class="card">'+
        '<div class="filter-row"><div><strong>すべてのアンケート</strong></div><input class="search" id="surveySearch" type="text" placeholder="アンケート名を検索" oninput="filterList()"></div>'+
        '<div class="table-wrap"><table id="surveyTable"><thead><tr>'+
        '<th>アンケート名</th><th>状態</th><th>回答期間</th><th>回答数</th><th>更新日</th><th>操作</th>'+
        '</tr></thead><tbody>'+
        surveys.map(function(s){
            return listRow(s);
        }).join("")+
        '</tbody></table></div>'+
    '</div>';
}

function listRow(s){
    var ops='<button class="btn small" onclick="openSurvey('+s.id+')">内容を見る</button> ';
    if(s.status==="draft"){
        ops+='<button class="btn small" onclick="editSurvey('+s.id+')">編集する</button> ';
        ops+='<button class="btn small" onclick="previewSurvey('+s.id+')">公開前確認</button>';
    }else{
        ops+='<button class="btn small" onclick="showResponseStatus('+s.id+')">回答状況</button> ';
        ops+='<button class="btn small" onclick="showAnswers('+s.id+')">回答内容</button>';
    }
    return '<tr data-name="'+esc(s.name.toLowerCase())+'">'+
        '<td><strong>'+esc(s.name)+'</strong><div class="muted">'+esc(s.description)+'</div></td>'+
        '<td>'+statusHtml(s.status)+'</td>'+
        '<td>'+formatDateTime(s.start)+'<br>～ '+formatDateTime(s.end)+'</td>'+
        '<td>'+s.answers.length+'件</td>'+
        '<td>'+esc(s.updated)+'</td>'+
        '<td>'+ops+'</td>'+
    '</tr>';
}

function filterList(){
    var q=(document.getElementById("surveySearch").value||"").toLowerCase();
    document.querySelectorAll("#surveyTable tbody tr").forEach(function(row){
        row.style.display=(row.getAttribute("data-name")||"").indexOf(q)>=0?"":"none";
    });
}

function renderArchive(){
    document.getElementById("main").innerHTML=
    '<div class="page-head"><div><h1 class="page-title">保管済みアンケート</h1><p class="page-desc">終了したアンケートを確認できます。</p></div></div>'+
    '<div class="card"><div class="table-wrap"><table><thead><tr><th>アンケート名</th><th>保管日</th><th>回答数</th><th>操作</th></tr></thead><tbody>'+
    surveys.filter(function(s){return s.status==="archive"}).map(function(s){
        return '<tr><td><strong>'+esc(s.name)+'</strong><div class="muted">'+esc(s.description)+'</div></td><td>'+esc(s.updated)+'</td><td>'+s.answers.length+'件</td><td><button class="btn small" onclick="openSurvey('+s.id+')">内容を見る</button> <button class="btn small" onclick="showResponseStatus('+s.id+')">集計を見る</button> <button class="btn small" onclick="showAnswers('+s.id+')">回答内容</button></td></tr>';
    }).join("")+
    '</tbody></table></div></div>';
}

function renderResponses(){
    var open=surveys.filter(function(s){return s.status==="open"||s.status==="closed"});
    document.getElementById("main").innerHTML=
    '<div class="page-head"><div><h1 class="page-title">回答状況</h1><p class="page-desc">回答数・集計・個別回答を確認できます。</p></div></div>'+
    '<div class="card"><div class="table-wrap"><table><thead><tr><th>アンケート名</th><th>状態</th><th>回答数</th><th>回答期間</th><th>操作</th></tr></thead><tbody>'+
    open.map(function(s){
        return '<tr><td><strong>'+esc(s.name)+'</strong></td><td>'+statusHtml(s.status)+'</td><td>'+s.answers.length+'件</td><td>'+formatDateTime(s.start)+' ～ '+formatDateTime(s.end)+'</td><td><button class="btn small" onclick="showResponseStatus('+s.id+')">集計を見る</button> <button class="btn small" onclick="showAnswers('+s.id+')">回答内容を見る</button></td></tr>';
    }).join("")+
    '</tbody></table></div></div>';
}

function startNewSurvey(){
    editingSurvey={
        id:null,
        name:"",
        description:"",
        guidance:"",
        start:"",
        end:"",
        complete:"ご回答ありがとうございました。",
        status:"draft",
        created:"",
        updated:"",
        questions:[
            {text:"",type:"radio",required:true,options:["選択肢1","選択肢2"]}
        ],
        answers:[]
    };
    renderEditor();
}

function editSurvey(id){
    var s=findSurvey(id);
    if(!s)return;
    if(s.status!=="draft"){
        showNoticeModal("編集できません","公開後のアンケートは、回答内容との整合性を保つため原則として編集できません。");
        return;
    }
    editingSurvey=JSON.parse(JSON.stringify(s));
    renderEditor();
}

function renderEditor(){
    var s=editingSurvey;
    document.getElementById("main").innerHTML=
    '<div class="breadcrumb">アンケート一覧 ＞ アンケート作成・編集</div>'+
    '<div class="page-head"><div><h1 class="page-title">'+(s.id?"アンケートを編集":"新しいアンケートを作成")+'</h1><p class="page-desc">基本情報と質問を設定します。途中で保存して後から再開できます。</p></div></div>'+
    '<div class="stepper"><div class="step active">1. 基本情報</div><div class="step active">2. 質問作成</div><div class="step">3. 公開前確認</div><div class="step">4. 公開</div></div>'+
    '<div class="card">'+
        '<div class="card-title">基本情報</div>'+
        '<div class="form-grid">'+
            '<div class="form-group full"><label>アンケート名<span class="required">必須</span></label><input id="f-name" type="text" value="'+esc(s.name)+'" placeholder="例：2026年度 社員満足度アンケート"></div>'+
            '<div class="form-group full"><label>説明文</label><textarea id="f-description" placeholder="アンケートの目的や概要を入力してください">'+esc(s.description)+'</textarea></div>'+
            '<div class="form-group full"><label>回答者への案内文</label><textarea id="f-guidance" placeholder="回答に必要な案内を入力してください">'+esc(s.guidance)+'</textarea></div>'+
            '<div class="form-group"><label>回答受付開始日時</label><input id="f-start" type="datetime-local" value="'+esc(s.start)+'"><div class="help">開始日時を未来にすると、公開後は「回答開始待ち」になります。</div></div>'+
            '<div class="form-group"><label>回答受付終了日時</label><input id="f-end" type="datetime-local" value="'+esc(s.end)+'"></div>'+
            '<div class="form-group full"><label>回答完了時のメッセージ</label><textarea id="f-complete">'+esc(s.complete)+'</textarea></div>'+
        '</div>'+
    '</div>'+
    '<div class="card">'+
        '<div class="card-title">質問</div>'+
        '<div id="questions">'+s.questions.map(function(q,i){return questionEditor(q,i)}).join("")+'</div>'+
        '<button class="btn" onclick="addQuestion()">＋ 質問を追加</button>'+
    '</div>'+
    '<div class="actions" style="justify-content:flex-end">'+
        '<button class="btn" onclick="showPage(\\'list\\')">キャンセル</button>'+
        '<button class="btn" onclick="saveDraft()">途中保存</button>'+
        '<button class="btn primary" onclick="saveAndPreview()">保存して公開前確認</button>'+
    '</div>';
}

function questionEditor(q,i){
    var options="";
    if(q.type==="radio"||q.type==="checkbox"||q.type==="scale"){
        options='<div style="margin-top:12px"><label>選択肢</label><div id="options-'+i+'">'+
            q.options.map(function(o,j){
                return '<div class="option-row"><input type="text" value="'+esc(o)+'" data-option="'+i+'-'+j+'"><button class="btn small" onclick="removeOption('+i+','+j+')">削除</button></div>';
            }).join("")+
        '</div><button class="btn small" onclick="addOption('+i+')">＋ 選択肢を追加</button></div>';
    }
    return '<div class="question-card">'+
        '<div class="question-head"><div class="question-no">質問'+(i+1)+'</div><div class="question-actions">'+
            (i>0?'<button class="btn small" onclick="moveQuestion('+i+',-1)">↑</button>':'')+
            (i<editingSurvey.questions.length-1?'<button class="btn small" onclick="moveQuestion('+i+',1)">↓</button>':'')+
            '<button class="btn small" onclick="deleteQuestion('+i+')">削除</button>'+
        '</div></div>'+
        '<div class="form-group"><label>質問文<span class="required">必須</span></label><input id="q-text-'+i+'" type="text" value="'+esc(q.text)+'" placeholder="質問を入力してください"></div>'+
        '<div class="form-grid">'+
            '<div class="form-group"><label>質問の種類</label><select id="q-type-'+i+'" onchange="changeQuestionType('+i+',this.value)">'+
                '<option value="text" '+(q.type==="text"?"selected":"")+'>文章入力（短文）</option>'+
                '<option value="textarea" '+(q.type==="textarea"?"selected":"")+'>文章入力（長文）</option>'+
                '<option value="radio" '+(q.type==="radio"?"selected":"")+'>1つだけ選ぶ</option>'+
                '<option value="checkbox" '+(q.type==="checkbox"?"selected":"")+'>複数選ぶ</option>'+
                '<option value="scale" '+(q.type==="scale"?"selected":"")+'>5段階で評価</option>'+
            '</select></div>'+
            '<div class="form-group"><label>回答</label><label style="font-weight:400"><input type="checkbox" id="q-required-'+i+'" '+(q.required?"checked":"")+'> 必須回答にする</label></div>'+
        '</div>'+
        options+
    '</div>';
}

function collectEditor(){
    var s=editingSurvey;
    s.name=document.getElementById("f-name").value.trim();
    s.description=document.getElementById("f-description").value;
    s.guidance=document.getElementById("f-guidance").value;
    s.start=document.getElementById("f-start").value;
    s.end=document.getElementById("f-end").value;
    s.complete=document.getElementById("f-complete").value;
    s.questions.forEach(function(q,i){
        q.text=document.getElementById("q-text-"+i).value.trim();
        q.type=document.getElementById("q-type-"+i).value;
        q.required=document.getElementById("q-required-"+i).checked;
        if(q.type==="radio"||q.type==="checkbox"||q.type==="scale"){
            q.options=Array.prototype.slice.call(document.querySelectorAll('[data-option^="'+i+'-"]')).map(function(el){return el.value.trim()});
        }else{
            q.options=[];
        }
    });
    return s;
}

function validateSurvey(s){
    var errors=[];
    if(!s.name)errors.push("アンケート名を入力してください。");
    if(!s.questions.length)errors.push("質問を1つ以上登録してください。");
    if(s.start&&s.end&&s.start>=s.end)errors.push("回答終了日時は開始日時より後に設定してください。");
    s.questions.forEach(function(q,i){
        if(!q.text)errors.push("質問"+(i+1)+"の質問文を入力してください。");
        if((q.type==="radio"||q.type==="checkbox"||q.type==="scale") && q.options.filter(function(x){return x!==""}).length<2){
            errors.push("質問"+(i+1)+"の選択肢を2つ以上設定してください。");
        }
    });
    return errors;
}

function saveDraft(){
    var s=collectEditor();
    var errors=validateSurvey(s);
    if(errors.length){
        showErrorList(errors);
        return;
    }
    saveSurveyObject(s,"draft");
    showToast("作成途中として保存しました。");
    setTimeout(function(){showPage("list")},500);
}

function saveAndPreview(){
    var s=collectEditor();
    var errors=validateSurvey(s);
    if(errors.length){
        showErrorList(errors);
        return;
    }
    saveSurveyObject(s,"draft");
    currentSurvey=findSurvey(s.id);
    previewSurvey(s.id);
}

function saveSurveyObject(s,status){
    s.status=status;
    s.updated="2026/09/16";
    if(!s.created)s.created="2026/09/16";
    if(!s.id){
        s.id=nextId++;
        surveys.push(JSON.parse(JSON.stringify(s)));
        editingSurvey.id=s.id;
    }else{
        var index=surveys.findIndex(function(x){return x.id===s.id});
        if(index>=0)surveys[index]=JSON.parse(JSON.stringify(s));
    }
}

function addQuestion(){
    collectEditor();
    editingSurvey.questions.push({
        text:"",
        type:"radio",
        required:false,
        options:["選択肢1","選択肢2"]
    });
    renderEditor();
}

function deleteQuestion(i){
    if(editingSurvey.questions.length===1){
        showNoticeModal("削除できません","質問は1つ以上必要です。");
        return;
    }
    showConfirm("質問を削除しますか？","質問"+(i+1)+"を削除します。",function(){
        collectEditor();
        editingSurvey.questions.splice(i,1);
        renderEditor();
    });
}

function moveQuestion(i,dir){
    collectEditor();
    var j=i+dir;
    if(j<0||j>=editingSurvey.questions.length)return;
    var tmp=editingSurvey.questions[i];
    editingSurvey.questions[i]=editingSurvey.questions[j];
    editingSurvey.questions[j]=tmp;
    renderEditor();
}

function addOption(i){
    collectEditor();
    editingSurvey.questions[i].options.push("新しい選択肢");
    renderEditor();
}

function removeOption(i,j){
    collectEditor();
    if(editingSurvey.questions[i].options.length<=2){
        showNoticeModal("削除できません","選択式の質問には2つ以上の選択肢が必要です。");
        return;
    }
    editingSurvey.questions[i].options.splice(j,1);
    renderEditor();
}

function changeQuestionType(i,type){
    collectEditor();
    editingSurvey.questions[i].type=type;
    if(type==="radio"||type==="checkbox"||type==="scale"){
        if(editingSurvey.questions[i].options.length<2)editingSurvey.questions[i].options=["選択肢1","選択肢2"];
    }else{
        editingSurvey.questions[i].options=[];
    }
    renderEditor();
}

function previewSurvey(id){
    var s=findSurvey(id);
    if(!s)return;
    currentSurvey=s;
    document.getElementById("main").innerHTML=
    '<div class="breadcrumb">アンケート一覧 ＞ 公開前確認</div>'+
    '<div class="page-head"><div><h1 class="page-title">公開前確認</h1><p class="page-desc">回答者から見える内容を確認してください。</p></div></div>'+
    '<div class="stepper"><div class="step done">1. 基本情報</div><div class="step done">2. 質問作成</div><div class="step active">3. 公開前確認</div><div class="step">4. 公開</div></div>'+
    '<div class="notice info">公開前の確認画面です。内容に問題がなければ「この内容で公開する」を選択してください。</div>'+
    '<div class="card preview">'+
        '<h2 style="margin-top:0">'+esc(s.name)+'</h2>'+
        '<p style="line-height:1.7">'+esc(s.description)+'</p>'+
        (s.guidance?'<div class="notice info">'+esc(s.guidance)+'</div>':'')+
        '<div class="muted" style="font-size:12px">回答期間：'+formatDateTime(s.start)+' ～ '+formatDateTime(s.end)+'</div>'+
        '<hr style="border:0;border-top:1px solid #e5e9ec;margin:20px 0">'+
        s.questions.map(function(q,i){return previewQuestion(q,i)}).join("")+
    '</div>'+
    '<div class="actions" style="justify-content:flex-end">'+
        '<button class="btn" onclick="editSurvey('+s.id+')">編集に戻る</button>'+
        '<button class="btn primary" onclick="publishSurvey('+s.id+')">この内容で公開する</button>'+
    '</div>';
}

function previewQuestion(q,i){
    var html='<div class="preview-question"><div class="q-label">Q'+(i+1)+'. '+esc(q.text);
    if(q.required)html+='<span class="required-badge">必須</span>';
    html+='</div>';
    if(q.type==="text")html+='<input type="text" placeholder="回答を入力してください">';
    if(q.type==="textarea")html+='<textarea placeholder="回答を入力してください"></textarea>';
    if(q.type==="radio")html+='<div class="radio-list">'+q.options.map(function(o){return '<label><input type="radio" name="preview'+i+'"> '+esc(o)+'</label>'}).join("")+'</div>';
    if(q.type==="checkbox")html+='<div class="check-list">'+q.options.map(function(o){return '<label><input type="checkbox"> '+esc(o)+'</label>'}).join("")+'</div>';
    if(q.type==="scale")html+='<div class="radio-list">'+q.options.map(function(o){return '<label><input type="radio" name="preview'+i+'"> '+esc(o)+'</label>'}).join("")+'</div>';
    html+='</div>';
    return html;
}

function publishSurvey(id){
    var s=findSurvey(id);
    if(!s)return;
    showConfirm("アンケートを公開しますか？","公開すると、設定した回答開始日時に応じて回答を受け付けます。公開後は質問内容を原則変更できません。",function(){
        var now=new Date();
        var start=s.start?new Date(s.start):null;
        var end=s.end?new Date(s.end):null;
        if(end&&end<=now)s.status="closed";
        else if(start&&start>now)s.status="wait";
        else s.status="open";
        s.updated="2026/09/16";
        showPublished(s);
    });
}

function showPublished(s){
    document.getElementById("main").innerHTML=
    '<div class="page-head"><div><h1 class="page-title">公開しました</h1><p class="page-desc">アンケートの公開状態を確認してください。</p></div></div>'+
    '<div class="notice success">「'+esc(s.name)+'」を公開しました。</div>'+
    '<div class="card">'+
        '<div class="detail-grid">'+
            '<div class="detail-box"><div class="detail-label">現在の状態</div><div class="detail-value">'+statusHtml(s.status)+'</div></div>'+
            '<div class="detail-box"><div class="detail-label">回答開始</div><div class="detail-value">'+formatDateTime(s.start)+'</div></div>'+
            '<div class="detail-box"><div class="detail-label">回答終了</div><div class="detail-value">'+formatDateTime(s.end)+'</div></div>'+
        '</div>'+
    '</div>'+
    '<div class="card">'+
        '<div class="card-title">次にできること</div>'+
        '<div class="actions">'+
            '<button class="btn primary" onclick="openRespondentDemo('+s.id+')">回答画面を確認する</button>'+
            '<button class="btn" onclick="showResponseStatus('+s.id+')">回答状況を見る</button>'+
            '<button class="btn" onclick="showPage(\\'list\\')">アンケート一覧へ</button>'+
        '</div>'+
    '</div>';
}

function openSurvey(id){
    var s=findSurvey(id);
    if(!s)return;
    currentSurvey=s;
    document.getElementById("main").innerHTML=
    '<div class="breadcrumb">アンケート一覧 ＞ 内容確認</div>'+
    '<div class="page-head"><div><h1 class="page-title">'+esc(s.name)+'</h1><p class="page-desc">'+esc(s.description)+'</p></div><div>'+statusHtml(s.status)+'</div></div>'+
    '<div class="card">'+
        '<div class="detail-grid">'+
            '<div class="detail-box"><div class="detail-label">回答期間</div><div class="detail-value">'+formatDateTime(s.start)+' ～ '+formatDateTime(s.end)+'</div></div>'+
            '<div class="detail-box"><div class="detail-label">回答数</div><div class="detail-value">'+s.answers.length+'件</div></div>'+
            '<div class="detail-box"><div class="detail-label">最終更新</div><div class="detail-value">'+esc(s.updated)+'</div></div>'+
        '</div>'+
    '</div>'+
    '<div class="card"><div class="card-title">現在の状態と操作</div>'+
        stateActions(s)+
    '</div>'+
    '<div class="card"><div class="card-title">質問内容</div>'+
        s.questions.map(function(q,i){return '<div class="question-card"><div class="question-no">質問'+(i+1)+(q.required?' ・ 必須':' ・ 任意')+'</div><div style="margin-top:8px">'+esc(q.text)+'</div><div class="muted" style="margin-top:6px">回答形式：'+questionTypeLabel(q.type)+'</div></div>'}).join("")+
    '</div>';
}

function stateActions(s){
    var h='<div class="actions">';
    if(s.status==="draft"){
        h+='<button class="btn" onclick="editSurvey('+s.id+')">編集する</button>';
        h+='<button class="btn" onclick="previewSurvey('+s.id+')">公開前確認</button>';
    }
    if(s.status==="wait"){
        h+='<button class="btn primary" onclick="openRespondentDemo('+s.id+')">回答画面を確認</button>';
        h+='<button class="btn" onclick="showResponseStatus('+s.id+')">回答状況を見る</button>';
        h+='<button class="btn danger" onclick="closeSurvey('+s.id+')">回答受付を終了する</button>';
    }
    if(s.status==="open"){
        h+='<button class="btn primary" onclick="openRespondentDemo('+s.id+')">回答画面を確認</button>';
        h+='<button class="btn" onclick="showResponseStatus('+s.id+')">回答状況を見る</button>';
        h+='<button class="btn" onclick="showAnswers('+s.id+')">回答内容を見る</button>';
        h+='<button class="btn danger" onclick="closeSurvey('+s.id+')">回答受付を終了する</button>';
    }
    if(s.status==="closed"){
        h+='<button class="btn" onclick="showResponseStatus('+s.id+')">最終集計を見る</button>';
        h+='<button class="btn" onclick="showAnswers('+s.id+')">回答内容を見る</button>';
        h+='<button class="btn primary" onclick="archiveSurvey('+s.id+')">保管する</button>';
    }
    if(s.status==="archive"){
        h+='<button class="btn" onclick="showResponseStatus('+s.id+')">集計を見る</button>';
        h+='<button class="btn" onclick="showAnswers('+s.id+')">回答内容を見る</button>';
    }
    h+='</div>';
    return h;
}

function questionTypeLabel(t){
    var m={text:"文章入力（短文）",textarea:"文章入力（長文）",radio:"1つだけ選ぶ",checkbox:"複数選ぶ",scale:"5段階で評価"};
    return m[t]||t;
}

function showResponseStatus(id){
    var s=findSurvey(id);
    if(!s)return;
    currentSurvey=s;
    var total=s.answers.length;
    var summary=s.questions.map(function(q,qi){
        if(q.type==="radio"||q.type==="scale"){
            return '<div class="card"><div class="card-title">質問'+(qi+1)+'：'+esc(q.text)+'</div>'+
                q.options.map(function(opt){
                    var count=s.answers.filter(function(a){return a.values[qi]===opt}).length;
                    var pct=total?Math.round(count/total*100):0;
                    return '<div style="margin:12px 0"><div class="progress-label"><span>'+esc(opt)+'</span><span>'+count+'件（'+pct+'%）</span></div><div class="progress"><div style="width:'+pct+'%"></div></div></div>';
                }).join("")+
            '</div>';
        }
        if(q.type==="checkbox"){
            var counts={};
            q.options.forEach(function(o){counts[o]=0});
            s.answers.forEach(function(a){
                var v=a.values[qi]||"";
                q.options.forEach(function(o){if(v.indexOf(o)>=0)counts[o]++});
            });
            return '<div class="card"><div class="card-title">質問'+(qi+1)+'：'+esc(q.text)+'</div>'+
                q.options.map(function(opt){
                    var count=counts[opt]||0;
                    var pct=total?Math.round(count/total*100):0;
                    return '<div style="margin:12px 0"><div class="progress-label"><span>'+esc(opt)+'</span><span>'+count+'件（'+pct+'%）</span></div><div class="progress"><div style="width:'+pct+'%"></div></div></div>';
                }).join("")+
            '</div>';
        }
        var textAnswers=s.answers.map(function(a){return a.values[qi]||""}).filter(function(x){return x});
        return '<div class="card"><div class="card-title">質問'+(qi+1)+'：'+esc(q.text)+'</div><div class="muted">回答 '+textAnswers.length+'件</div><div style="margin-top:10px">'+
            (textAnswers.length?textAnswers.slice(0,5).map(function(a){return '<div style="padding:9px;border-top:1px solid #edf0f2">'+esc(a)+'</div>'}).join(""):'<div class="empty">回答はありません。</div>')+
        '</div></div>';
    }).join("");

    document.getElementById("main").innerHTML=
    '<div class="breadcrumb">回答状況 ＞ '+esc(s.name)+'</div>'+
    '<div class="page-head"><div><h1 class="page-title">回答状況・集計</h1><p class="page-desc">'+esc(s.name)+'</p></div><div>'+statusHtml(s.status)+'</div></div>'+
    '<div class="card"><div class="detail-grid">'+
        '<div class="detail-box"><div class="detail-label">回答数</div><div class="detail-value" style="font-size:24px">'+total+'件</div></div>'+
        '<div class="detail-box"><div class="detail-label">回答受付期間</div><div class="detail-value">'+formatDateTime(s.start)+' ～ '+formatDateTime(s.end)+'</div></div>'+
        '<div class="detail-box"><div class="detail-label">操作</div><div class="detail-value"><button class="btn small" onclick="showAnswers('+s.id+')">回答内容を見る</button></div></div>'+
    '</div></div>'+
    summary+
    '<div class="actions"><button class="btn" onclick="openSurvey('+s.id+')">アンケートに戻る</button></div>';
}

function showAnswers(id){
    var s=findSurvey(id);
    if(!s)return;
    document.getElementById("main").innerHTML=
    '<div class="breadcrumb">回答内容 ＞ '+esc(s.name)+'</div>'+
    '<div class="page-head"><div><h1 class="page-title">回答内容</h1><p class="page-desc">'+esc(s.name)+' の個別回答を確認できます。</p></div></div>'+
    '<div class="card"><div class="detail-grid">'+
        '<div class="detail-box"><div class="detail-label">回答数</div><div class="detail-value">'+s.answers.length+'件</div></div>'+
        '<div class="detail-box"><div class="detail-label">状態</div><div class="detail-value">'+statusHtml(s.status)+'</div></div>'+
    '</div></div>'+
    (s.answers.length?s.answers.map(function(a){
        return '<div class="answer-card"><div class="answer-header"><strong>回答 #'+a.no+'</strong><span class="muted">'+esc(a.date)+'</span></div>'+
            s.questions.map(function(q,qi){
                return '<div class="answer-item"><div class="answer-q">Q'+(qi+1)+' '+esc(q.text)+'</div><div class="answer-a">'+esc(a.values[qi]||"未回答")+'</div></div>';
            }).join("")+
        '</div>';
    }).join(""):'<div class="card"><div class="empty">まだ回答はありません。</div></div>')+
    '<button class="btn" onclick="openSurvey('+s.id+')">アンケートに戻る</button>';
}

function openRespondentDemo(id){
    if(id==null){
        var s=surveys.find(function(x){return x.status==="open"});
        if(!s){
            showNoticeModal("回答画面を表示できません","回答受付中のアンケートがありません。");
            return;
        }
        id=s.id;
    }
    responseSurvey=findSurvey(id);
    if(responseSurvey.status!=="open"){
        document.getElementById("main").innerHTML=
        '<div class="page-head"><div><h1 class="page-title">回答画面</h1><p class="page-desc">回答者がアンケートを開いたときの表示です。</p></div></div>'+
        '<div class="card preview">'+
            '<h2 style="margin-top:0">'+esc(responseSurvey.name)+'</h2>'+
            (responseSurvey.status==="wait"?
                '<div class="notice warn"><strong>回答受付開始前です。</strong><br>回答受付は '+formatDateTime(responseSurvey.start)+' に開始します。</div>':
                '<div class="notice error"><strong>回答受付は終了しました。</strong><br>現在このアンケートには回答できません。</div>')+
        '</div>'+
        '<button class="btn" onclick="openSurvey('+responseSurvey.id+')">管理画面に戻る</button>';
        return;
    }
    responseStep=1;
    responseAnswers=responseSurvey.questions.map(function(){return ""});
    renderRespondent();
}

function renderRespondent(){
    var s=responseSurvey;
    var html=
    '<div class="page-head"><div><h1 class="page-title">アンケート回答</h1><p class="page-desc">回答者から見た画面です。</p></div></div>'+
    '<div class="stepper"><div class="step active">1. 回答入力</div><div class="step '+(responseStep>=2?"active":"")+'">2. 回答確認</div><div class="step '+(responseStep>=3?"active":"")+'">3. 完了</div></div>';

    if(responseStep===1){
        html+='<div class="card preview"><h2 style="margin-top:0">'+esc(s.name)+'</h2><p style="line-height:1.7">'+esc(s.description)+'</p>'+
            (s.guidance?'<div class="notice info">'+esc(s.guidance)+'</div>':'')+
            '<div class="muted" style="font-size:12px">回答期間：'+formatDateTime(s.start)+' ～ '+formatDateTime(s.end)+'</div>';
        s.questions.forEach(function(q,i){
            html+='<div class="preview-question"><div class="q-label">Q'+(i+1)+'. '+esc(q.text)+(q.required?'<span class="required-badge">必須</span>':'')+'</div>';
            if(q.type==="text")html+='<input id="ans-'+i+'" type="text" value="'+esc(responseAnswers[i])+'" placeholder="回答を入力してください">';
            if(q.type==="textarea")html+='<textarea id="ans-'+i+'" placeholder="回答を入力してください">'+esc(responseAnswers[i])+'</textarea>';
            if(q.type==="radio"||q.type==="scale")html+='<div class="radio-list">'+q.options.map(function(o){return '<label><input type="radio" name="ans-'+i+'" value="'+esc(o)+'" '+(responseAnswers[i]===o?"checked":"")+'> '+esc(o)+'</label>'}).join("")+'</div>';
            if(q.type==="checkbox"){
                var selected=responseAnswers[i]?responseAnswers[i].split("、"):[];
                html+='<div class="check-list">'+q.options.map(function(o){return '<label><input type="checkbox" name="ans-'+i+'" value="'+esc(o)+'" '+(selected.indexOf(o)>=0?"checked":"")+'> '+esc(o)+'</label>'}).join("")+'</div>';
            }
            html+='</div>';
        });
        html+='</div><div class="actions" style="justify-content:flex-end"><button class="btn primary" onclick="goResponseConfirm()">回答内容を確認する</button></div>';
    }

    if(responseStep===2){
        html+='<div class="card preview"><h2 style="margin-top:0">'+esc(s.name)+'</h2><div class="notice info">送信前に回答内容を確認してください。修正する場合は「回答を修正する」を選択してください。</div>';
        s.questions.forEach(function(q,i){
            html+='<div class="preview-question"><div class="q-label">Q'+(i+1)+'. '+esc(q.text)+'</div><div>'+esc(responseAnswers[i]||"未回答")+'</div></div>';
        });
        html+='</div><div class="actions" style="justify-content:flex-end"><button class="btn" onclick="responseStep=1;renderRespondent()">回答を修正する</button><button class="btn primary" onclick="submitResponse()">回答を送信する</button></div>';
    }

    if(responseStep===3){
        html+='<div class="card preview" style="text-align:center;padding:45px 25px"><div style="font-size:48px;color:#28774f">✓</div><h2>回答が完了しました</h2><p style="line-height:1.8">'+esc(s.complete)+'</p></div><div class="actions" style="justify-content:center"><button class="btn" onclick="showPage(\\'home\\')">管理画面のホームへ</button></div>';
    }

    document.getElementById("main").innerHTML=html;
}

function collectResponse(){
    responseSurvey.questions.forEach(function(q,i){
        if(q.type==="radio"||q.type==="scale"){
            var checked=document.querySelector('input[name="ans-'+i+'"]:checked');
            responseAnswers[i]=checked?checked.value:"";
        }else if(q.type==="checkbox"){
            var checked2=document.querySelectorAll('input[name="ans-'+i+'"]:checked');
            var vals=[];
            checked2.forEach(function(x){vals.push(x.value)});
            responseAnswers[i]=vals.join("、");
        }else{
            var el=document.getElementById("ans-"+i);
            responseAnswers[i]=el?el.value:"";
        }
    });
}

function goResponseConfirm(){
    collectResponse();
    var errors=[];
    responseSurvey.questions.forEach(function(q,i){
        if(q.required&&!responseAnswers[i]){
            errors.push("質問"+(i+1)+"「"+q.text+"」を回答してください。");
        }
    });
    if(errors.length){
        showErrorList(errors);
        return;
    }
    responseStep=2;
    renderRespondent();
}

function submitResponse(){
    showConfirm("回答を送信しますか？","送信後はこの回答を完了したものとして扱います。",function(){
        var no=responseSurvey.answers.length+1;
        responseSurvey.answers.push({
            no:no,
            date:"2026/09/16 16:00",
            values:JSON.parse(JSON.stringify(responseAnswers))
        });
        responseStep=3;
        renderRespondent();
    });
}

function closeSurvey(id){
    var s=findSurvey(id);
    if(!s)return;
    showConfirm("回答受付を終了しますか？","終了すると新しい回答を受け付けなくなります。終了後も回答内容と集計は確認できます。",function(){
        s.status="closed";
        s.updated="2026/09/16";
        showToast("回答受付を終了しました。");
        setTimeout(function(){openSurvey(id)},500);
    });
}

function archiveSurvey(id){
    var s=findSurvey(id);
    if(!s)return;
    showConfirm("アンケートを保管しますか？","保管後は回答受付中の一覧から外れ、過去のアンケートとして整理されます。回答内容と集計は確認できます。",function(){
        s.status="archive";
        s.updated="2026/09/16";
        showToast("アンケートを保管しました。");
        setTimeout(function(){showPage("archive")},500);
    });
}

function findSurvey(id){
    return surveys.find(function(s){return s.id===id});
}

function showConfirm(title,text,ok){
    document.getElementById("modalArea").innerHTML=
    '<div class="modal-backdrop"><div class="modal">'+
        '<h3>'+esc(title)+'</h3><p>'+esc(text)+'</p>'+
        '<div class="modal-actions"><button class="btn" onclick="closeModal()">キャンセル</button><button class="btn primary" id="modalOk">確認する</button></div>'+
    '</div></div>';
    document.getElementById("modalOk").onclick=function(){
        closeModal();
        ok();
    };
}

function showNoticeModal(title,text){
    document.getElementById("modalArea").innerHTML=
    '<div class="modal-backdrop"><div class="modal">'+
        '<h3>'+esc(title)+'</h3><p>'+esc(text)+'</p>'+
        '<div class="modal-actions"><button class="btn primary" onclick="closeModal()">閉じる</button></div>'+
    '</div></div>';
}

function showErrorList(errors){
    document.getElementById("modalArea").innerHTML=
    '<div class="modal-backdrop"><div class="modal">'+
        '<h3>入力内容を確認してください</h3>'+
        '<div class="notice error"><ul style="margin:0;padding-left:20px">'+errors.map(function(e){return '<li>'+esc(e)+'</li>'}).join("")+'</ul></div>'+
        '<div class="modal-actions"><button class="btn primary" onclick="closeModal()">修正する</button></div>'+
    '</div></div>';
}

function closeModal(){
    document.getElementById("modalArea").innerHTML="";
}

function showToast(text){
    var el=document.createElement("div");
    el.style.position="fixed";
    el.style.right="22px";
    el.style.bottom="22px";
    el.style.background="#17324d";
    el.style.color="#fff";
    el.style.padding="13px 17px";
    el.style.borderRadius="7px";
    el.style.boxShadow="0 5px 20px rgba(0,0,0,.2)";
    el.style.zIndex="2000";
    el.textContent=text;
    document.body.appendChild(el);
    setTimeout(function(){el.remove()},2200);
}

/* 初期表示 */
showPage("home");
</script>
</body>
</html>
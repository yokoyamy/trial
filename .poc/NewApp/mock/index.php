<?php
namespace yokoyamy\newapp\questionnaire;

/**
 * アンケート管理アプリ モック
 * PHP 8.4/8.5対応
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$appTitle = 'アンケート管理アプリ';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($appTitle) ?></title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --info:#0891b2;
    --bg:#f5f7fb;
    --card:#fff;
    --line:#dfe4ec;
    --text:#243044;
    --muted:#667085;
    --nav:#172033;
}
*{box-sizing:border-box}
html,body{
    margin:0;
    min-height:100%;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",Meiryo,sans-serif;
    background:var(--bg);
    color:var(--text)
}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.45}
.app{min-height:100vh}
.sidebar{
    position:fixed;left:0;top:0;bottom:0;width:245px;
    background:var(--nav);color:#fff;z-index:20
}
.logo{
    height:68px;padding:16px 20px;
    border-bottom:1px solid #2b3548;
    font-size:18px;font-weight:700
}
.logo small{
    display:block;color:#9aa7bb;font-size:11px;font-weight:400;margin-top:3px
}
.nav{padding:15px 10px}
.nav-title{
    color:#77849a;font-size:11px;margin:12px 10px 6px
}
.nav button{
    display:block;width:100%;border:0;background:transparent;color:#cbd5e1;
    padding:10px 12px;text-align:left;border-radius:7px;margin:2px 0
}
.nav button:hover,.nav button.active{background:#283650;color:#fff}
.main{margin-left:245px;min-height:100vh}
.topbar{
    height:68px;background:#fff;border-bottom:1px solid var(--line);
    display:flex;align-items:center;justify-content:space-between;
    padding:0 26px;position:sticky;top:0;z-index:10
}
.top-title{font-weight:700}
.user{font-size:13px;color:var(--muted)}
.content{max-width:1550px;margin:auto;padding:26px}
.page-head{
    display:flex;justify-content:space-between;gap:15px;
    align-items:flex-start;margin-bottom:20px
}
h1{font-size:24px;margin:0;color:#172033}
h2{font-size:18px;margin:0}
h3{font-size:15px;margin:0}
.desc{color:var(--muted);margin:7px 0 0;line-height:1.6}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{
    border:1px solid #cbd3df;background:#fff;color:#344054;
    padding:8px 13px;border-radius:7px;font-weight:600
}
.btn:hover{background:#f8fafc}
.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.success{background:var(--success);border-color:var(--success);color:#fff}
.warning{background:var(--warning);border-color:var(--warning);color:#fff}
.danger{background:var(--danger);border-color:var(--danger);color:#fff}
.info{background:var(--info);border-color:var(--info);color:#fff}
.small{padding:5px 8px;font-size:12px}
.card{
    background:#fff;border:1px solid var(--line);border-radius:10px;
    box-shadow:0 2px 8px rgba(20,30,50,.05);margin-bottom:18px
}
.card-head{
    padding:15px 18px;border-bottom:1px solid var(--line);
    display:flex;justify-content:space-between;align-items:center;gap:12px
}
.card-body{padding:18px}
.status{
    display:inline-block;border-radius:99px;padding:4px 9px;
    font-size:12px;font-weight:700
}
.draft{background:#eef2f7;color:#475467}
.wait{background:#fff4cc;color:#92400e}
.active{background:#dcfce7;color:#166534}
.ended{background:#dbeafe;color:#1e40af}
.archived{background:#e5e7eb;color:#475569}
.notice{
    padding:12px 14px;border-radius:8px;margin-bottom:15px;line-height:1.6
}
.notice.info{color:#075985;background:#eff8ff;border:1px solid #b9e6fe}
.notice.warn{color:#92400e;background:#fffaeb;border:1px solid #fedf89}
.notice.error{color:#991b1b;background:#fef3f2;border:1px solid #fecdca}
.notice.ok{color:#166534;background:#ecfdf3;border:1px solid #abefc6}
.grid{display:grid;gap:16px}
.grid2{grid-template-columns:1fr 1fr}
.stats{
    display:grid;grid-template-columns:repeat(6,1fr);
    gap:12px;margin-bottom:20px
}
.stat{
    background:#fff;border:1px solid var(--line);border-radius:10px;
    padding:17px;cursor:pointer
}
.stat:hover{border-color:var(--primary)}
.stat-label{font-size:12px;color:var(--muted)}
.stat-value{font-size:28px;font-weight:800;margin-top:6px}
table{width:100%;border-collapse:collapse}
th,td{
    padding:10px 11px;border-bottom:1px solid var(--line);
    text-align:left;vertical-align:middle
}
th{
    background:#f8fafc;color:#667085;font-size:12px;
    white-space:nowrap
}
.actions-cell{white-space:nowrap}
.actions-cell .btn{margin:2px}
.table-scroll{overflow:auto}
.form-grid{
    display:grid;grid-template-columns:1fr 1fr;gap:16px
}
.full{grid-column:1/-1}
.field{margin-bottom:12px}
.field label{
    display:block;font-weight:700;font-size:13px;margin-bottom:6px
}
.req{color:var(--danger);margin-left:3px}
input[type=text],
input[type=email],
input[type=datetime-local],
select,
textarea{
    width:100%;padding:9px 10px;border:1px solid #cbd3df;
    border-radius:7px;background:#fff;color:var(--text)
}
textarea{min-height:90px;resize:vertical}
input:focus,select:focus,textarea:focus{
    outline:0;border-color:var(--primary);
    box-shadow:0 0 0 3px #2563eb18
}
.help{font-size:12px;color:var(--muted);margin-top:5px}
.search{
    display:grid;grid-template-columns:1fr 210px auto;gap:10px
}
.editor{
    display:grid;grid-template-columns:220px 1fr;gap:18px
}
.editor-nav{
    position:sticky;top:88px;align-self:start
}
.editor-nav button{
    display:block;width:100%;padding:10px 12px;border:0;
    background:transparent;text-align:left;border-radius:7px;margin-bottom:3px
}
.editor-nav button.active{
    background:#eaf2ff;color:#175cd3;font-weight:700
}
.group{
    border:1px solid var(--line);border-radius:9px;
    background:#fff;margin-bottom:14px
}
.group-head{
    background:#f8fafc;border-bottom:1px solid var(--line);
    padding:12px 14px;display:flex;justify-content:space-between;
    align-items:center;gap:10px
}
.question{
    padding:13px;border-bottom:1px solid #edf0f4
}
.question:last-child{border-bottom:0}
.question-top{
    display:flex;justify-content:space-between;gap:10px
}
.q-main{display:flex;gap:9px;flex:1}
.q-num{font-weight:800;color:var(--primary)}
.q-title{font-weight:600}
.q-actions{white-space:nowrap}
.choices{margin-top:10px}
.choice{display:flex;gap:7px;margin:6px 0}
.choice input{flex:1}
.add-row{
    text-align:center;padding:11px;border-top:1px dashed #ccd3dd
}
.empty{padding:25px;text-align:center;color:var(--muted)}
.move-buttons{
    display:flex;gap:3px;white-space:nowrap;margin-top:8px
}
.preview{max-width:900px;margin:auto}
.answer-q{
    background:#fff;border:1px solid var(--line);
    border-radius:10px;padding:17px;margin-bottom:12px
}
.required{color:var(--danger);font-size:11px}
.answer-option{display:flex;gap:8px;margin:8px 0}
.progress{
    height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden
}
.progress>span{display:block;height:100%;background:var(--primary)}
.kpis{
    display:grid;grid-template-columns:repeat(6,1fr);gap:12px
}
.kpi{border:1px solid var(--line);padding:14px;border-radius:8px;background:#fff}
.kpi label{font-size:12px;color:var(--muted)}
.kpi strong{display:block;font-size:25px;margin-top:4px}
.bar{
    height:12px;background:#e5e7eb;border-radius:20px;
    overflow:hidden;margin-top:5px
}
.bar span{display:block;height:100%;background:var(--primary)}
.modal{
    position:fixed;inset:0;background:#10182888;display:none;
    align-items:center;justify-content:center;padding:20px;z-index:100
}
.modal.show{display:flex}
.dialog{
    width:min(760px,100%);max-height:90vh;overflow:auto;
    background:#fff;border-radius:12px
}
.dialog-head,.dialog-foot{
    padding:15px 18px;border-bottom:1px solid var(--line)
}
.dialog-foot{
    border-top:1px solid var(--line);border-bottom:0;text-align:right
}
.dialog-body{padding:18px}
.toast{
    position:fixed;right:20px;bottom:20px;background:#172033;color:#fff;
    padding:12px 16px;border-radius:8px;display:none;z-index:200
}
.toast.show{display:block}
.step{
    display:flex;gap:8px;align-items:center;margin-bottom:18px;
    overflow:auto
}
.step span{
    padding:7px 12px;border-radius:20px;background:#eef2f7;
    color:#667085;font-size:12px;white-space:nowrap
}
.step span.current{background:#2563eb;color:#fff}
.respondent{
    max-width:920px;margin:0 auto
}
.respondent-header{
    background:#fff;border:1px solid var(--line);
    border-radius:12px;padding:22px;margin-bottom:15px
}
.check-list{margin:0;padding-left:20px}
.answer-summary{
    border:1px solid var(--line);border-radius:8px;
    padding:12px;margin-bottom:10px;background:#fafafa
}
.badge{
    display:inline-block;padding:3px 7px;border-radius:5px;
    background:#eef2ff;color:#3730a3;font-size:11px
}
.muted{color:var(--muted)}
@media(max-width:1150px){
    .sidebar{width:72px}
    .logo{font-size:0;text-align:center;padding:20px 0}
    .logo:before{content:"A";font-size:24px}
    .logo small,.nav-title,.nav button span{display:none}
    .main{margin-left:72px}
    .stats{grid-template-columns:repeat(3,1fr)}
    .kpis{grid-template-columns:repeat(3,1fr)}
    .editor{grid-template-columns:1fr}
    .editor-nav{position:static}
}
@media(max-width:700px){
    .content{padding:16px}
    .stats{grid-template-columns:repeat(2,1fr)}
    .kpis{grid-template-columns:1fr 1fr}
    .form-grid,.grid2{grid-template-columns:1fr}
    .full{grid-column:auto}
    .search{grid-template-columns:1fr}
    .page-head{display:block}
    .page-head .actions{margin-top:12px}
}
</style>
</head>
<body>

<div id="app"></div>

<div id="modal" class="modal">
    <div class="dialog">
        <div class="dialog-head" id="modalTitle"></div>
        <div class="dialog-body" id="modalBody"></div>
        <div class="dialog-foot" id="modalFoot"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
(function(){
"use strict";

var KEY="yokoyamy_questionnaire_mock_v3";

var customers=[
    {id:"c1",name:"株式会社サンプル商事",person:"田中 太郎",email:"tanaka@example.jp"},
    {id:"c2",name:"株式会社青空製作所",person:"佐藤 花子",email:"sato@example.jp"},
    {id:"c3",name:"山田産業株式会社",person:"山田 一郎",email:"yamada@example.jp"},
    {id:"c4",name:"東京ビジネス株式会社",person:"鈴木 次郎",email:"suzuki@example.jp"},
    {id:"c5",name:"未来サービス株式会社",person:"高橋 美咲",email:"takahashi@example.jp"}
];

function uid(prefix){
    return prefix+Date.now()+Math.floor(Math.random()*1000);
}
function clone(v){
    return JSON.parse(JSON.stringify(v));
}
function esc(v){
    var d=document.createElement("div");
    d.textContent=v==null?"":String(v);
    return d.innerHTML;
}
function save(){
    try{
        localStorage.setItem(KEY,JSON.stringify(state));
    }catch(e){}
}
function load(){
    try{
        var x=JSON.parse(localStorage.getItem(KEY)||"null");
        if(x)return x;
    }catch(e){}
    return seed();
}
function seed(){
    return {
        surveys:[
            {
                id:"s1",
                name:"2026年度 顧客満足度アンケート",
                description:"サービスご利用後の満足度を確認するアンケートです。",
                start:"",
                end:"",
                status:"active",
                created:"2026-09-01",
                updated:"2026-09-10",
                groups:[
                    {
                        id:"g1",
                        name:"サービスについて",
                        description:"ご利用いただいたサービスについてお聞きします。",
                        questions:[
                            {
                                id:"q1",
                                text:"今回のサービスに満足しましたか？",
                                type:"radio",
                                required:true,
                                choices:["とても満足","満足","やや不満","不満"],
                                branch:null
                            },
                            {
                                id:"q2",
                                text:"改善してほしい点を教えてください。",
                                type:"textarea",
                                required:false,
                                choices:[],
                                branch:null
                            }
                        ]
                    },
                    {
                        id:"g2",
                        name:"今後について",
                        description:"",
                        questions:[
                            {
                                id:"q3",
                                text:"今後も利用したいと思いますか？",
                                type:"radio",
                                required:true,
                                choices:["はい","いいえ"],
                                branch:null
                            }
                        ]
                    }
                ],
                ungrouped:[],
                recipients:{
                    c1:{status:"sent",answered:true,sentAt:"2026-09-05 10:00",answeredAt:"2026-09-06 15:20"},
                    c2:{status:"sent",answered:true,sentAt:"2026-09-05 10:05",answeredAt:"2026-09-07 09:10"},
                    c3:{status:"sent",answered:false,sentAt:"2026-09-05 10:10"},
                    c4:{status:"unsent",answered:false},
                    c5:{status:"sent",answered:false,sentAt:"2026-09-05 10:15"}
                },
                answers:[
                    {
                        customer:"c1",
                        answeredAt:"2026-09-06 15:20",
                        values:{
                            q1:"とても満足",
                            q2:"説明が分かりやすかった",
                            q3:"はい"
                        }
                    },
                    {
                        customer:"c2",
                        answeredAt:"2026-09-07 09:10",
                        values:{
                            q1:"満足",
                            q2:"特になし",
                            q3:"はい"
                        }
                    }
                ]
            },
            {
                id:"s2",
                name:"新サービス事前調査",
                description:"新サービスに関する事前調査です。",
                start:"2026-10-01T09:00",
                end:"2026-10-31T18:00",
                status:"wait",
                created:"2026-09-10",
                updated:"2026-09-10",
                groups:[],
                ungrouped:[],
                recipients:{},
                answers:[]
            },
            {
                id:"s3",
                name:"営業対応品質アンケート",
                description:"営業担当者の対応品質を確認します。",
                start:"",
                end:"2026-08-31T18:00",
                status:"ended",
                created:"2026-08-01",
                updated:"2026-09-01",
                groups:[],
                ungrouped:[],
                recipients:{},
                answers:[]
            },
            {
                id:"s4",
                name:"2025年度サービス評価",
                description:"昨年度の評価アンケートです。",
                start:"",
                end:"2026-03-31T18:00",
                status:"archived",
                created:"2025-04-01",
                updated:"2026-04-01",
                groups:[],
                ungrouped:[],
                recipients:{},
                answers:[]
            }
        ]
    };
}

var state=load();

var view={
    page:"list",
    surveyId:null,
    tab:"basic",
    search:"",
    status:"all",
    draft:null,
    recipients:{},
    sendSurveyId:null,
    answerCustomer:null,
    respondentSurveyId:null,
    respondentCustomerId:null,
    respondentMode:"input",
    respondentValues:{},
    dirty:false
};

function survey(id){
    return state.surveys.find(function(x){return x.id===id});
}

function statusLabel(s){
    return {
        draft:"作成中",
        wait:"回答開始待ち",
        active:"回答受付中",
        ended:"回答受付終了",
        archived:"保管"
    }[s]||s;
}

function statusClass(s){return s}

function allQuestions(s){
    var a=[];
    s.groups.forEach(function(g){
        g.questions.forEach(function(q){a.push(q)});
    });
    s.ungrouped.forEach(function(q){a.push(q)});
    return a;
}

function questionLocation(s,id){
    var result=null;
    s.groups.some(function(g){
        var index=g.questions.findIndex(function(q){return q.id===id});
        if(index>=0){
            result={list:g.questions,index:index,group:g};
            return true;
        }
        return false;
    });
    if(result)return result;
    var index=s.ungrouped.findIndex(function(q){return q.id===id});
    if(index>=0)return {list:s.ungrouped,index:index,group:null};
    return null;
}

function targetCount(s){
    return Object.keys(s.recipients||{}).length;
}
function sentCount(s){
    return Object.keys(s.recipients||{}).filter(function(id){
        return s.recipients[id].status==="sent";
    }).length;
}
function answeredCount(s){
    return (s.answers||[]).length;
}
function unansweredCount(s){
    return Math.max(targetCount(s)-answeredCount(s),0);
}
function rate(s){
    var t=targetCount(s);
    return t?Math.round(answeredCount(s)/t*100):0;
}

function refreshStatusByDate(s){
    if(!s || s.status==="draft" || s.status==="ended" || s.status==="archived")return;
    var now=new Date();
    var start=s.start?new Date(s.start):null;
    var end=s.end?new Date(s.end):null;

    if(end && now>=end){
        s.status="ended";
        return;
    }
    if(start && now<start){
        s.status="wait";
        return;
    }
    s.status="active";
}

function refreshAllStatuses(){
    state.surveys.forEach(refreshStatusByDate);
}

function toast(msg){
    var el=document.getElementById("toast");
    el.textContent=msg;
    el.classList.add("show");
    setTimeout(function(){el.classList.remove("show")},2200);
}

function modal(title,body,foot){
    document.getElementById("modalTitle").innerHTML="<strong>"+title+"</strong>";
    document.getElementById("modalBody").innerHTML=body;
    document.getElementById("modalFoot").innerHTML=foot||"";
    document.getElementById("modal").classList.add("show");
}
function closeModal(){
    document.getElementById("modal").classList.remove("show");
}
window.closeModal=closeModal;

function confirmAction(title,message,yesClass,yesText,fn){
    modal(
        title,
        "<p>"+message+"</p>",
        '<button class="btn" onclick="closeModal()">キャンセル</button> '+
        '<button class="btn '+yesClass+'" id="modalYes">'+yesText+"</button>"
    );
    document.getElementById("modalYes").onclick=function(){
        closeModal();
        fn();
    };
}

function render(){
    refreshAllStatuses();

    var root=document.getElementById("app");

    root.innerHTML=
        '<div class="app">'+
        '<aside class="sidebar">'+
        '<div class="logo">アンケート管理<small>Questionnaire Manager</small></div>'+
        '<nav class="nav">'+
        '<div class="nav-title">運営者メニュー</div>'+
        navBtn("list","📋","アンケート一覧")+
        navBtn("new","＋","アンケートを作成")+
        '<div class="nav-title">回答者画面</div>'+
        '<button onclick="openRespondentDemo()"><span>👤</span> 回答画面を確認</button>'+
        '</nav></aside>'+
        '<main class="main">'+
        '<header class="topbar">'+
        '<div class="top-title">'+pageTitle()+'</div>'+
        '<div class="user">アンケート運営者</div>'+
        '</header>'+
        '<div class="content" id="content"></div>'+
        '</main>'+
        '</div>';

    if(view.page==="list")renderList();
    else if(view.page==="new")renderNew();
    else if(view.page==="edit")renderEdit();
    else if(view.page==="send")renderSend();
    else if(view.page==="status")renderStatus();
    else if(view.page==="answers")renderAnswers();
    else if(view.page==="respondent")renderRespondent();
}

function pageTitle(){
    return {
        list:"アンケート一覧",
        new:"アンケート作成",
        edit:"アンケート編集",
        send:"アンケート送付",
        status:"回答状況",
        answers:"回答内容",
        respondent:"回答者向けアンケート"
    }[view.page]||"アンケート管理";
}

function navBtn(page,icon,text){
    return '<button class="'+(view.page===page?"active":"")+
        '" onclick="go(\''+page+'\')"><span>'+icon+"</span> "+text+"</button>";
}

window.go=function(page){
    if(page==="new"){
        view.draft=null;
        view.surveyId=null;
        view.tab="basic";
        view.page="new";
    }else{
        view.page=page;
    }
    render();
};

function stats(){
    refreshAllStatuses();
    var a=state.surveys;
    return {
        all:a.length,
        draft:a.filter(function(x){return x.status==="draft"}).length,
        wait:a.filter(function(x){return x.status==="wait"}).length,
        active:a.filter(function(x){return x.status==="active"}).length,
        ended:a.filter(function(x){return x.status==="ended"}).length,
        archived:a.filter(function(x){return x.status==="archived"}).length
    };
}

function renderList(){
    var c=document.getElementById("content");
    var st=stats();

    var rows=state.surveys.filter(function(s){
        return (!view.search || s.name.indexOf(view.search)>=0) &&
            (view.status==="all" || s.status===view.status);
    });

    c.innerHTML=
        '<div class="page-head">'+
        '<div><h1>アンケート一覧</h1>'+
        '<p class="desc">作成から公開、送付、回答確認、受付終了、保管までを一連で管理します。</p></div>'+
        '<div class="actions"><button class="btn primary" onclick="go(\'new\')">＋ アンケートを作成</button></div>'+
        '</div>'+
        '<div class="stats">'+
        stat("すべて",st.all,"all")+
        stat("作成中",st.draft,"draft")+
        stat("開始待ち",st.wait,"wait")+
        stat("受付中",st.active,"active")+
        stat("受付終了",st.ended,"ended")+
        stat("保管",st.archived,"archived")+
        '</div>'+
        '<div class="card"><div class="card-body">'+
        '<div class="search">'+
        '<input id="searchInput" type="text" value="'+esc(view.search)+'" placeholder="アンケート名で検索">'+
        '<select id="statusInput">'+statusOptions(view.status)+'</select>'+
        '<div><button class="btn primary" onclick="applySearch()">検索</button> '+
        '<button class="btn" onclick="clearSearch()">解除</button></div>'+
        '</div></div></div>'+
        '<div class="card"><div class="card-head">'+
        '<h2>アンケート</h2><span class="small" style="color:#667085">'+rows.length+"件</span>"+
        '</div><div class="table-scroll"><table><thead><tr>'+
        '<th>アンケート名</th><th>状態</th><th>受付期間</th>'+
        '<th>対象</th><th>回答</th><th>回答率</th><th>更新</th><th>操作</th>'+
        '</tr></thead><tbody>'+
        (rows.length?rows.map(listRow).join(""):
        '<tr><td colspan="8" class="empty">該当するアンケートはありません。</td></tr>')+
        '</tbody></table></div></div>';
}

function stat(label,num,status){
    return '<div class="stat" onclick="filterStatus(\''+status+'\')">'+
        '<div class="stat-label">'+label+"</div><div class=\"stat-value\">"+num+"</div></div>";
}

function statusOptions(selected){
    var a=[
        ["all","すべて"],["draft","作成中"],["wait","回答開始待ち"],
        ["active","回答受付中"],["ended","回答受付終了"],["archived","保管"]
    ];
    return a.map(function(x){
        return '<option value="'+x[0]+'" '+(x[0]===selected?"selected":"")+'>'+
            x[1]+"</option>";
    }).join("");
}

function filterStatus(s){
    view.status=s;
    render();
}
function applySearch(){
    view.search=document.getElementById("searchInput").value;
    view.status=document.getElementById("statusInput").value;
    render();
}
function clearSearch(){
    view.search="";
    view.status="all";
    render();
}

function listRow(s){
    return '<tr>'+
        '<td><strong>'+esc(s.name)+'</strong>'+
        '<div class="small" style="color:#667085">'+esc(s.description)+'</div></td>'+
        '<td><span class="status '+statusClass(s.status)+'">'+statusLabel(s.status)+'</span></td>'+
        '<td>'+esc(s.start||"指定なし")+'<br>～ '+esc(s.end||"指定なし")+'</td>'+
        '<td>'+targetCount(s)+'件</td>'+
        '<td>'+answeredCount(s)+'件</td>'+
        '<td>'+rate(s)+'%</td>'+
        '<td>'+esc(s.updated||s.created||"")+'</td>'+
        '<td class="actions-cell">'+listActions(s)+'</td>'+
        '</tr>';
}

function listActions(s){
    var x="";

    if(s.status==="draft"){
        x+='<button class="btn small primary" onclick="editSurvey(\''+s.id+'\')">編集</button>';
        x+='<button class="btn small success" onclick="publish(\''+s.id+'\')">公開前確認</button>';
        x+='<button class="btn small danger" onclick="deleteDraft(\''+s.id+'\')">削除</button>';
    }

    if(s.status==="wait"||s.status==="active"){
        x+='<button class="btn small" onclick="editSurvey(\''+s.id+'\')">内容確認</button>';
        x+='<button class="btn small info" onclick="sendSurvey(\''+s.id+'\')">送付</button>';
        x+='<button class="btn small" onclick="showStatus(\''+s.id+'\')">回答状況</button>';
        x+='<button class="btn small warning" onclick="endSurvey(\''+s.id+'\')">受付終了</button>';
    }

    if(s.status==="ended"){
        x+='<button class="btn small" onclick="editSurvey(\''+s.id+'\')">内容確認</button>';
        x+='<button class="btn small" onclick="showStatus(\''+s.id+'\')">回答状況</button>';
        x+='<button class="btn small" onclick="archiveSurvey(\''+s.id+'\')">保管</button>';
    }

    if(s.status==="archived"){
        x+='<button class="btn small" onclick="editSurvey(\''+s.id+'\')">内容確認</button>';
        x+='<button class="btn small" onclick="showStatus(\''+s.id+'\')">回答状況</button>';
        x+='<button class="btn small" onclick="showAggregation(\''+s.id+'\')">回答集計</button>';
    }

    return x;
}

window.editSurvey=function(id){
    view.surveyId=id;
    view.tab="basic";
    view.page="edit";
    render();
};

function currentEdit(){
    if(view.page==="new"){
        if(!view.draft){
            view.draft={
                id:uid("s"),
                name:"",
                description:"",
                start:"",
                end:"",
                status:"draft",
                created:new Date().toISOString().slice(0,10),
                updated:new Date().toISOString().slice(0,10),
                groups:[],
                ungrouped:[],
                recipients:{},
                answers:[]
            };
        }
        return view.draft;
    }
    return survey(view.surveyId);
}

function canEditStructure(s){
    return s.status==="draft";
}
function canEditBasic(s){
    return s.status==="draft"||s.status==="wait"||s.status==="active";
}

function renderNew(){renderEditor(true)}
function renderEdit(){renderEditor(false)}

function renderEditor(isNew){
    var s=currentEdit();
    var editable=canEditStructure(s);
    var basic=canEditBasic(s);

    var c=document.getElementById("content");

    c.innerHTML=
        '<div class="page-head"><div>'+
        '<h1>'+esc(isNew?"アンケート作成":s.name)+'</h1>'+
        '<p class="desc"><span class="status '+s.status+'">'+statusLabel(s.status)+'</span>　'+
        (isNew?"作成途中の内容を確認しながら設定できます。":
        "公開後は回答内容に影響する構造を変更できません。")+
        '</p></div>'+
        '<div class="actions">'+
        '<button class="btn" onclick="backList()">一覧へ戻る</button>'+
        (editable?'<button class="btn primary" onclick="saveEditor()">保存</button>':"")+
        ((isNew||s.status==="draft")?
        '<button class="btn success" onclick="openPublishFromEditor()">公開前確認</button>':"")+
        '</div></div>'+
        (s.status!=="draft"&&s.status!=="new"?
        '<div class="notice info">公開済みアンケートです。質問・選択肢・グループ・分岐などの構造は変更できません。受付日時は運用上の範囲で変更できます。</div>':"")+
        '<div class="editor"><aside class="editor-nav">'+
        editorNav("basic","基本情報")+
        editorNav("questions","質問・グループ")+
        editorNav("branch","分岐設定")+
        editorNav("preview","回答プレビュー")+
        '</aside><section>'+editorPanel(s,editable,basic)+'</section></div>';
}

function editorNav(tab,label){
    return '<button class="'+(view.tab===tab?"active":"")+
        '" onclick="setTab(\''+tab+'\')">'+label+"</button>";
}
function setTab(t){
    view.tab=t;
    render();
}

function editorPanel(s,editable,basic){
    if(view.tab==="basic")return basicPanel(s,basic);
    if(view.tab==="questions")return questionPanel(s,editable);
    if(view.tab==="branch")return branchPanel(s,editable);
    return previewPanel(s);
}

function basicPanel(s,editable){
    return '<div class="card"><div class="card-head"><h2>基本情報</h2></div>'+
        '<div class="card-body"><div class="form-grid">'+
        '<div class="field full"><label>アンケート名<span class="req">*</span></label>'+
        '<input id="fName" type="text" value="'+esc(s.name)+'" '+(editable?"":"disabled")+'></div>'+
        '<div class="field full"><label>回答者向け説明・案内文</label>'+
        '<textarea id="fDesc" '+(editable?"":"disabled")+'>'+esc(s.description)+'</textarea></div>'+
        '<div class="field"><label>回答受付開始日時</label>'+
        '<input id="fStart" type="datetime-local" value="'+esc(s.start)+'" '+(editable?"":"disabled")+'>'+
        '<div class="help">未設定の場合、公開後すぐ回答受付中になります。</div></div>'+
        '<div class="field"><label>回答受付終了日時</label>'+
        '<input id="fEnd" type="datetime-local" value="'+esc(s.end)+'" '+(editable?"":"disabled")+'>'+
        '<div class="help">未設定の場合、手動で受付終了するまで回答を受け付けます。</div></div>'+
        '</div>'+
        '<div class="notice info">公開後は質問、選択肢、グループ、分岐など回答内容に影響する構造を変更できません。</div>'+
        '</div></div>';
}

function saveBasic(s){
    if(!canEditBasic(s))return;

    var n=document.getElementById("fName");
    var d=document.getElementById("fDesc");
    var st=document.getElementById("fStart");
    var en=document.getElementById("fEnd");

    if(n)s.name=n.value.trim();
    if(d)s.description=d.value;
    if(st)s.start=st.value;
    if(en)s.end=en.value;

    s.updated=new Date().toISOString().slice(0,10);
}

function questionPanel(s,editable){
    var html=
        '<div class="card"><div class="card-head"><h2>質問・グループ</h2>'+
        (editable?
        '<button class="btn primary" onclick="addGroup()">＋ グループを追加</button>':"")+
        '</div><div class="card-body">';

    if(!s.groups.length&&!s.ungrouped.length){
        html+='<div class="empty">質問がありません。グループまたは未所属の質問を追加してください。</div>';
    }

    s.groups.forEach(function(g,gi){
        html+=groupHtml(s,g,gi,editable);
    });

    html+='<div class="group"><div class="group-head">'+
        '<strong>グループ未設定</strong>'+
        (editable?'<span class="small">未所属の質問</span>':"")+
        '</div><div class="group-body">'+
        questionListHtml(s,s.ungrouped,null,editable)+'</div></div>';

    html+='</div></div>';
    return html;
}

function groupHtml(s,g,gi,editable){
    return '<div class="group"><div class="group-head"><div>'+
        '<strong>'+esc(g.name||"名称未設定")+'</strong>'+
        '<div class="small" style="color:#667085">'+esc(g.description||"")+'</div>'+
        '</div>'+
        (editable?
        '<div><button class="btn small" onclick="editGroup(\''+g.id+'\')">編集</button> '+
        '<button class="btn small danger" onclick="deleteGroup(\''+g.id+'\')">削除</button></div>':"")+
        '</div><div class="group-body">'+
        questionListHtml(s,g.questions,g.id,editable)+
        '</div></div>';
}

function questionListHtml(s,list,gid,editable){
    var h="";

    list.forEach(function(q,i){
        h+=questionHtml(s,q,i,gid,editable);
    });

    if(editable){
        h+='<div class="add-row"><button class="btn small primary" onclick="addQuestion(\''+
            (gid||"ungrouped")+'\')">＋ 質問を追加</button></div>';
    }else if(!list.length){
        h+='<div class="empty">質問はありません。</div>';
    }

    return h;
}

function questionHtml(s,q,index,gid,editable){
    var opts="";

    if(q.type==="radio"||q.type==="checkbox"){
        opts='<div class="choices">'+
            q.choices.map(function(o,i){
                return '<div class="choice">'+
                    '<input type="text" value="'+esc(o)+'" '+
                    (editable?"":"disabled")+
                    ' onchange="changeChoice(\''+q.id+'\','+i+',this.value)">'+
                    '<button class="btn small danger" '+
                    (editable?'onclick="removeChoice(\''+q.id+'\','+i+')"' :"disabled")+
                    '>削除</button>'+
                    '</div>';
            }).join("")+
            (editable?
            '<button class="btn small" onclick="addChoice(\''+q.id+'\')">＋ 選択肢を追加</button>':"")+
            '</div>';
    }

    var move="";
    if(editable){
        move='<div class="move-buttons">'+
            '<button class="btn small" onclick="moveQuestion(\''+q.id+'\',-1)">↑ 上へ</button>'+
            '<button class="btn small" onclick="moveQuestion(\''+q.id+'\',1)">↓ 下へ</button>'+
            '<button class="btn small" onclick="moveQuestionGroup(\''+q.id+'\')">グループ移動</button>'+
            '</div>';
    }

    return '<div class="question"><div class="question-top">'+
        '<div class="q-main"><span class="q-num">Q'+
        (allQuestions(s).findIndex(function(x){return x.id===q.id})+1)+
        '</span><div class="q-title">'+
        esc(q.text||"質問文未設定")+' '+
        (q.required?'<span class="required">必須</span>':"")+
        '<div class="small" style="color:#667085">'+typeLabel(q.type)+'</div>'+
        '</div></div>'+
        (editable?
        '<div class="q-actions">'+
        '<button class="btn small" onclick="editQuestion(\''+q.id+'\')">編集</button> '+
        '<button class="btn small danger" onclick="deleteQuestion(\''+q.id+'\')">削除</button>'+
        '</div>':"")+
        '</div>'+opts+move+'</div>';
}

function typeLabel(t){
    return {
        text:"短文入力",
        textarea:"長文入力",
        radio:"単一選択",
        checkbox:"複数選択"
    }[t]||t;
}

function addGroup(){
    var s=currentEdit();
    s.groups.push({
        id:uid("g"),
        name:"新しいグループ",
        description:"",
        questions:[]
    });
    view.tab="questions";
    render();
    toast("グループを追加しました");
}
window.addGroup=addGroup;

function editGroup(id){
    var s=currentEdit();
    var g=s.groups.find(function(x){return x.id===id});
    if(!g)return;

    modal(
        "グループ編集",
        '<div class="field"><label>グループ名<span class="req">*</span></label>'+
        '<input id="mgName" type="text" value="'+esc(g.name)+'"></div>'+
        '<div class="field"><label>説明</label>'+
        '<textarea id="mgDesc">'+esc(g.description)+'</textarea></div>',
        '<button class="btn" onclick="closeModal()">キャンセル</button> '+
        '<button class="btn primary" id="saveGroup">保存</button>'
    );

    document.getElementById("saveGroup").onclick=function(){
        var name=document.getElementById("mgName").value.trim();
        if(!name){
            alert("グループ名を入力してください。");
            return;
        }
        g.name=name;
        g.description=document.getElementById("mgDesc").value;
        closeModal();
        render();
        toast("グループを更新しました");
    };
}
window.editGroup=editGroup;

function deleteGroup(id){
    var s=currentEdit();
    var g=s.groups.find(function(x){return x.id===id});
    if(!g)return;

    if(g.questions.length){
        modal(
            "グループ削除",
            "<p>このグループには質問が"+g.questions.length+"件あります。</p>"+
            "<p>削除すると質問は「グループ未設定」へ移動します。</p>",
            '<button class="btn" onclick="closeModal()">キャンセル</button> '+
            '<button class="btn danger" id="dg">質問を残して削除</button>'
        );

        document.getElementById("dg").onclick=function(){
            Array.prototype.push.apply(s.ungrouped,g.questions);
            s.groups=s.groups.filter(function(x){return x.id!==id});

            s.groups.forEach(function(other){
                other.questions.forEach(function(q){
                    if(q.branch&&q.branch.target===id)q.branch=null;
                });
            });
            s.ungrouped.forEach(function(q){
                if(q.branch&&q.branch.target===id)q.branch=null;
            });

            closeModal();
            render();
            toast("グループを削除しました");
        };
    }else{
        confirmAction(
            "グループ削除",
            "「"+esc(g.name)+"」を削除します。",
            "danger",
            "削除",
            function(){
                s.groups=s.groups.filter(function(x){return x.id!==id});
                render();
                toast("グループを削除しました");
            }
        );
    }
}
window.deleteGroup=deleteGroup;

function findQuestion(s,id){
    var loc=questionLocation(s,id);
    return loc?loc.list[loc.index]:null;
}

function addQuestion(gid){
    var s=currentEdit();
    var q={
        id:uid("q"),
        text:"",
        type:"text",
        required:false,
        choices:[],
        branch:null
    };

    if(gid==="ungrouped"){
        s.ungrouped.push(q);
    }else{
        var g=s.groups.find(function(x){return x.id===gid});
        if(g)g.questions.push(q);
    }

    editQuestion(q.id);
}
window.addQuestion=addQuestion;

function editQuestion(id){
    var s=currentEdit();
    var q=findQuestion(s,id);
    if(!q)return;

    modal(
        "質問編集",
        '<div class="field"><label>質問文<span class="req">*</span></label>'+
        '<textarea id="mqText">'+esc(q.text)+'</textarea></div>'+
        '<div class="field"><label>質問種類</label>'+
        '<select id="mqType">'+
        ["text","textarea","radio","checkbox"].map(function(t){
            return '<option value="'+t+'" '+(q.type===t?"selected":"")+'>'+
                typeLabel(t)+"</option>";
        }).join("")+
        '</select></div>'+
        '<label><input id="mqRequired" type="checkbox" '+
        (q.required?"checked":"")+'> 必須回答</label>',
        '<button class="btn" onclick="closeModal()">キャンセル</button> '+
        '<button class="btn primary" id="saveQ">保存</button>'
    );

    document.getElementById("saveQ").onclick=function(){
        var text=document.getElementById("mqText").value.trim();
        var type=document.getElementById("mqType").value;

        if(!text){
            alert("質問文を入力してください。");
            return;
        }

        q.text=text;
        q.type=type;
        q.required=document.getElementById("mqRequired").checked;

        if(type==="radio"||type==="checkbox"){
            if(q.choices.length<2)q.choices=["選択肢1","選択肢2"];
        }else{
            q.choices=[];
            q.branch=null;
        }

        if(type==="checkbox")q.branch=null;

        closeModal();
        render();
        toast("質問を更新しました");
    };
}
window.editQuestion=editQuestion;

function deleteQuestion(id){
    var s=currentEdit();
    var q=findQuestion(s,id);
    if(!q)return;

    var used=allQuestions(s).some(function(x){
        return x.branch&&x.branch.sourceQuestionId===id;
    });

    confirmAction(
        "質問削除",
        "「"+esc(q.text||"質問文未設定")+"」を削除します。"+
        (used?" この質問を条件として使用している分岐も解除されます。":""),
        "danger",
        "削除",
        function(){
            s.groups.forEach(function(g){
                g.questions=g.questions.filter(function(x){return x.id!==id});
            });
            s.ungrouped=s.ungrouped.filter(function(x){return x.id!==id});

            s.groups.forEach(function(g){
                g.questions.forEach(function(x){
                    if(x.branch&&x.branch.sourceQuestionId===id)x.branch=null;
                });
            });
            s.ungrouped.forEach(function(x){
                if(x.branch&&x.branch.sourceQuestionId===id)x.branch=null;
            });

            render();
            toast("質問を削除しました");
        }
    );
}
window.deleteQuestion=deleteQuestion;

function addChoice(id){
    var q=findQuestion(currentEdit(),id);
    if(!q)return;
    q.choices.push("新しい選択肢");
    render();
}
function removeChoice(id,i){
    var q=findQuestion(currentEdit(),id);
    if(!q)return;

    if(q.choices.length<=2){
        toast("選択式の選択肢は2件以上必要です");
        return;
    }

    q.choices.splice(i,1);
    render();
}
function changeChoice(id,i,v){
    var q=findQuestion(currentEdit(),id);
    if(q)q.choices[i]=v;
}
window.addChoice=addChoice;
window.removeChoice=removeChoice;
window.changeChoice=changeChoice;

function moveQuestion(id,direction){
    var s=currentEdit();
    var loc=questionLocation(s,id);
    if(!loc)return;

    var newIndex=loc.index+direction;
    if(newIndex<0||newIndex>=loc.list.length){
        toast("これ以上移動できません");
        return;
    }

    var tmp=loc.list[loc.index];
    loc.list[loc.index]=loc.list[newIndex];
    loc.list[newIndex]=tmp;
    render();
}
window.moveQuestion=moveQuestion;

function moveQuestionGroup(id){
    var s=currentEdit();
    var loc=questionLocation(s,id);
    if(!loc)return;

    var options='<option value="ungrouped">グループ未設定</option>';
    s.groups.forEach(function(g){
        if(g.id!==loc.group?.id){
            options+='<option value="'+g.id+'">'+esc(g.name)+'</option>';
        }
    });

    modal(
        "グループ移動",
        '<div class="field"><label>移動先</label>'+
        '<select id="moveGroup">'+options+'</select></div>',
        '<button class="btn" onclick="closeModal()">キャンセル</button> '+
        '<button class="btn primary" id="moveGroupYes">移動</button>'
    );

    document.getElementById("moveGroupYes").onclick=function(){
        var target=document.getElementById("moveGroup").value;
        var q=loc.list.splice(loc.index,1)[0];

        if(target==="ungrouped"){
            s.ungrouped.push(q);
        }else{
            var g=s.groups.find(function(x){return x.id===target});
            if(g)g.questions.push(q);
        }

        closeModal();
        render();
        toast("質問を移動しました");
    };
}
window.moveQuestionGroup=moveQuestionGroup;

function branchPanel(s,editable){
    /*
     * 要件定義では分岐対象は単一選択のみ。
     */
    var qs=allQuestions(s).filter(function(q){
        return q.type==="radio";
    });

    var html='<div class="card"><div class="card-head"><h2>分岐設定</h2></div>'+
        '<div class="card-body">';

    if(!qs.length){
        return html+
            '<div class="empty">分岐条件に利用できる単一選択質問がありません。</div>'+
            '</div></div>';
    }

    html+='<div class="notice info">単一選択の回答に応じて、次に表示する質問またはグループを指定できます。</div>';

    qs.forEach(function(q){
        html+='<div class="card"><div class="card-head"><strong>'+
            esc(q.text||"質問文未設定")+
            '</strong></div><div class="card-body">';

        if(editable){
            html+='<div class="field"><label>分岐条件</label>'+
                '<select onchange="setBranchQuestion(\''+q.id+'\',this.value)">'+
                '<option value="">分岐しない</option>'+
                '<option value="__self__" '+(q.branch?"selected":"")+
                '>回答内容によって分岐</option>'+
                '</select></div>';

            if(q.branch)html+=branchEditor(s,q);
        }else{
            html+=q.branch?branchText(s,q):'<span class="muted">分岐なし</span>';
        }

        html+='</div></div>';
    });

    return html+'</div></div>';
}

function branchEditor(s,q){
    var b=q.branch||{
        sourceQuestionId:q.id,
        choice:q.choices[0]||"",
        target:""
    };

    return '<div class="form-grid">'+
        '<div class="field"><label>回答</label>'+
        '<select onchange="setBranchValue(\''+q.id+'\',\'choice\',this.value)">'+
        q.choices.map(function(x){
            return '<option value="'+esc(x)+'" '+
                (b.choice===x?"selected":"")+'>'+esc(x)+'</option>';
        }).join("")+
        '</select></div>'+
        '<div class="field"><label>分岐先</label>'+
        '<select onchange="setBranchValue(\''+q.id+'\',\'target\',this.value)">'+
        '<option value="">指定なし</option>'+
        allTargets(s,q.id).map(function(x){
            return '<option value="'+x.id+'" '+
                (b.target===x.id?"selected":"")+'>'+esc(x.label)+'</option>';
        }).join("")+
        '</select></div>'+
        '</div>';
}

function allTargets(s,exclude){
    var r=[];

    s.groups.forEach(function(g){
        r.push({id:g.id,label:"グループ："+g.name});
    });

    allQuestions(s).forEach(function(q){
        if(q.id!==exclude){
            r.push({id:q.id,label:"質問："+q.text});
        }
    });

    return r;
}

function branchText(s,q){
    var b=q.branch;
    var t=allTargets(s,q.id).find(function(x){return x.id===b.target});

    return '<div class="notice info">「'+esc(b.choice)+
        '」の場合 → '+esc(t?t.label:"未指定")+'</div>';
}

function setBranchQuestion(id,v){
    var q=findQuestion(currentEdit(),id);

    if(v){
        q.branch={
            sourceQuestionId:id,
            choice:q.choices[0]||"",
            target:""
        };
    }else{
        q.branch=null;
    }

    render();
}
function setBranchValue(id,k,v){
    var q=findQuestion(currentEdit(),id);
    if(q&&q.branch)q.branch[k]=v;
}
window.setBranchQuestion=setBranchQuestion;
window.setBranchValue=setBranchValue;

function previewPanel(s){
    var qs=allQuestions(s);

    return '<div class="card"><div class="card-head"><h2>回答プレビュー</h2>'+
        '<button class="btn small primary" onclick="openRespondentFromSurvey(\''+
        s.id+'\')">回答者画面として確認</button>'+
        '</div><div class="card-body"><div class="preview">'+
        '<div class="notice info">入力内容はモック上の回答データとして保存されません。</div>'+
        '<div class="card" style="padding:20px"><h2>'+
        esc(s.name||"アンケート")+'</h2><p>'+
        esc(s.description||"")+'</p></div>'+
        qs.map(function(q,i){
            return '<div class="answer-q"><div><strong>Q'+
                (i+1)+'. '+esc(q.text||"質問文未設定")+
                '</strong> '+
                (q.required?'<span class="required">必須</span>':"")+
                '</div>'+answerInput(q)+'</div>';
        }).join("")+
        '</div></div></div>';
}

function answerInput(q){
    if(q.type==="textarea"){
        return '<textarea placeholder="回答を入力"></textarea>';
    }
    if(q.type==="text"){
        return '<input type="text" placeholder="回答を入力">';
    }
    if(q.type==="radio"){
        return q.choices.map(function(x){
            return '<label class="answer-option"><input type="radio" name="'+
                q.id+'"> '+esc(x)+'</label>';
        }).join("");
    }
    return q.choices.map(function(x){
        return '<label class="answer-option"><input type="checkbox"> '+
            esc(x)+'</label>';
    }).join("");
}

function validateSurvey(s){
    var e=[];

    if(!s.name.trim()){
        e.push("アンケート名を入力してください。");
    }

    if(s.start&&s.end&&s.start>=s.end){
        e.push("回答受付終了日時は開始日時より後にしてください。");
    }

    var qs=allQuestions(s);

    if(!qs.length){
        e.push("質問を1件以上設定してください。");
    }

    qs.forEach(function(q,i){
        if(!q.text.trim()){
            e.push("Q"+(i+1)+"の質問文を入力してください。");
        }

        if((q.type==="radio"||q.type==="checkbox")&&q.choices.length<2){
            e.push("Q"+(i+1)+"の選択肢を2件以上設定してください。");
        }

        if(q.branch){
            if(q.type!=="radio"){
                e.push("Q"+(i+1)+"の分岐は単一選択質問だけに設定できます。");
            }
            if(!q.branch.choice||q.choices.indexOf(q.branch.choice)<0){
                e.push("Q"+(i+1)+"の分岐条件となる回答を確認してください。");
            }
            if(!q.branch.target||
                !allTargets(s,q.id).some(function(x){return x.id===q.branch.target})){
                e.push("Q"+(i+1)+"の分岐先を設定してください。");
            }
        }
    });

    return e;
}

function saveEditor(){
    var s=currentEdit();

    if(canEditBasic(s))saveBasic(s);

    var e=validateSurvey(s);

    if(e.length){
        showErrors(e);
        return;
    }

    if(view.page==="new"){
        state.surveys.unshift(clone(s));
        view.surveyId=s.id;
        view.draft=null;
        view.page="edit";
    }

    save();
    view.dirty=false;
    toast("保存しました");
    render();
}

function showErrors(e){
    modal(
        "入力内容を確認してください",
        '<div class="notice error"><ul>'+
        e.map(function(x){return "<li>"+esc(x)+"</li>"}).join("")+
        '</ul></div>',
        '<button class="btn primary" onclick="closeModal()">確認</button>'
    );
}

function backList(){
    if(view.dirty){
        confirmAction(
            "未保存の変更があります",
            "保存していない変更を破棄して一覧へ戻りますか？",
            "danger",
            "破棄して戻る",
            function(){
                view.page="list";
                view.surveyId=null;
                view.draft=null;
                view.dirty=false;
                render();
            }
        );
        return;
    }

    view.page="list";
    view.surveyId=null;
    render();
}
window.backList=backList;

function openPublishFromEditor(){
    var s=currentEdit();

    if(canEditBasic(s))saveBasic(s);

    var e=validateSurvey(s);

    if(e.length){
        showErrors(e);
        return;
    }

    publishConfirm(s);
}

function publish(id){
    publishConfirm(survey(id));
}

function publishConfirm(s){
    var qs=allQuestions(s);

    modal(
        "公開前確認",
        '<div class="notice warn">公開すると質問・選択肢・グループ・分岐などの構造を変更できなくなります。</div>'+
        '<p><strong>アンケート：</strong>'+esc(s.name)+'</p>'+
        '<p><strong>質問数：</strong>'+qs.length+'件</p>'+
        '<p><strong>受付期間：</strong>'+esc(s.start||"公開後すぐ")+
        ' ～ '+esc(s.end||"指定なし")+'</p>'+
        '<h3>質問内容</h3>'+
        '<ol>'+qs.map(function(q){
            return '<li>'+esc(q.text)+
                (q.required?' <span class="required">必須</span>':"")+
                '（'+typeLabel(q.type)+'）</li>';
        }).join("")+'</ol>'+
        '<h3>分岐設定</h3>'+
        (qs.filter(function(q){return q.branch}).length?
        '<ul>'+qs.filter(function(q){return q.branch}).map(function(q){
            var t=allTargets(s,q.id).find(function(x){return x.id===q.branch.target});
            return '<li>「'+esc(q.text)+'」で「'+
                esc(q.branch.choice)+'」→ '+esc(t?t.label:"未指定")+'</li>';
        }).join("")+'</ul>':
        '<p class="muted">分岐設定なし</p>'),
        '<button class="btn" onclick="closeModal()">戻る</button> '+
        '<button class="btn success" id="publishYes">公開する</button>'
    );

    document.getElementById("publishYes").onclick=function(){
        s.status=s.start?"wait":"active";
        s.updated=new Date().toISOString().slice(0,10);
        save();
        closeModal();
        view.page="list";
        render();
        toast("公開しました");
    };
}

window.publish=publish;
window.openPublishFromEditor=openPublishFromEditor;

function deleteDraft(id){
    var s=survey(id);
    if(!s)return;

    confirmAction(
        "作成中アンケートの削除",
        "「"+esc(s.name||"名称未設定")+"」を削除します。保存済みの内容は失われます。",
        "danger",
        "削除する",
        function(){
            state.surveys=state.surveys.filter(function(x){return x.id!==id});
            save();
            render();
            toast("削除しました");
        }
    );
}
window.deleteDraft=deleteDraft;

function sendSurvey(id){
    var s=survey(id);
    refreshStatusByDate(s);

    if(!s||s.status==="ended"||s.status==="archived"){
        toast("受付終了後は送付できません");
        return;
    }

    view.sendSurveyId=id;
    view.recipients={};
    view.page="send";
    render();
}
window.sendSurvey=sendSurvey;

function renderSend(){
    var s=survey(view.sendSurveyId);
    var c=document.getElementById("content");

    if(!s)return;

    var canSend=s.status==="wait"||s.status==="active";

    c.innerHTML=
        '<div class="page-head"><div><h1>アンケート送付</h1>'+
        '<p class="desc">'+esc(s.name)+'</p></div>'+
        '<div class="actions"><button class="btn" onclick="backList()">一覧へ戻る</button></div>'+
        '</div>'+
        (canSend?
        '<div class="notice info">未送付の顧客だけでなく、送付済みの顧客も選択できます。送付済みの場合は再送として扱います。</div>':
        '<div class="notice warn">受付終了後は新規送付・再送はできません。</div>')+
        '<div class="card"><div class="card-head"><h2>送付先を選択</h2>'+
        '<div><button class="btn small" onclick="selectAllRecipients()" '+(!canSend?"disabled":"")+'>全選択</button> '+
        '<button class="btn small" onclick="clearRecipients()">全解除</button></div>'+
        '</div><div class="card-body">'+
        '<p>選択中：<strong>'+selectedRecipientCount()+'</strong>件</p>'+
        '<div class="table-scroll"><table><thead><tr>'+
        '<th>選択</th><th>顧客名</th><th>担当者</th><th>メールアドレス</th>'+
        '<th>送付状況</th><th>回答状況</th>'+
        '</tr></thead><tbody>'+
        customers.map(function(cu){
            var r=s.recipients[cu.id]||{status:"unsent",answered:false};
            var checked=view.recipients[cu.id]?"checked":"";
            var actionLabel=r.status==="sent"?"再送対象":"送付対象";

            return '<tr>'+
                '<td><input type="checkbox" '+checked+' '+
                (canSend?'':'disabled')+
                ' onchange="recipientChanged(\''+cu.id+'\',this.checked)"></td>'+
                '<td>'+esc(cu.name)+'</td>'+
                '<td>'+esc(cu.person)+'</td>'+
                '<td>'+esc(cu.email)+'</td>'+
                '<td>'+esc(r.status==="sent"?actionLabel:"未送付")+'</td>'+
                '<td>'+esc(r.answered?"回答済み":"未回答")+'</td>'+
                '</tr>';
        }).join("")+
        '</tbody></table></div></div></div>'+
        '<div class="actions" style="justify-content:flex-end">'+
        '<button class="btn primary" onclick="openSendConfirm()" '+
        (!canSend?"disabled":"")+'>選択した顧客へ送付</button></div>';
}

function selectedRecipientCount(){
    return Object.keys(view.recipients).length;
}

function recipientChanged(id,checked){
    if(checked)view.recipients[id]=true;
    else delete view.recipients[id];
    renderSend();
}
function toggleAllRecipients(chk){
    customers.forEach(function(c){
        if(chk)view.recipients[c.id]=true;
        else delete view.recipients[c.id];
    });
    renderSend();
}
function selectAllRecipients(){
    var s=survey(view.sendSurveyId);
    if(!s)return;

    customers.forEach(function(c){
        if(!(s.recipients[c.id]||{}).answered){
            view.recipients[c.id]=true;
        }
    });
    renderSend();
}
function clearRecipients(){
    view.recipients={};
    renderSend();
}
window.recipientChanged=recipientChanged;
window.toggleAllRecipients=toggleAllRecipients;
window.selectAllRecipients=selectAllRecipients;
window.clearRecipients=clearRecipients;

function openSendConfirm(){
    var s=survey(view.sendSurveyId);
    var ids=Object.keys(view.recipients);

    if(!ids.length){
        toast("送付先を1件以上選択してください");
        return;
    }

    var names=ids.map(function(id){
        var c=customers.find(function(x){return x.id===id});
        return c?c.name:"";
    }).join("、");

    modal(
        "送付確認",
        '<p><strong>アンケート：</strong>'+esc(s.name)+'</p>'+
        '<p><strong>送付対象者数：</strong>'+ids.length+'件</p>'+
        '<p><strong>送付対象者：</strong>'+esc(names)+'</p>'+
        '<div class="notice warn">送付済みの対象者は「再送」として扱います。</div>',
        '<button class="btn" onclick="closeModal()">戻る</button> '+
        '<button class="btn primary" id="sendYes">送付する</button>'
    );

    document.getElementById("sendYes").onclick=function(){
        ids.forEach(function(id){
            var r=s.recipients[id]||{status:"unsent",answered:false};
            r.status="sent";
            r.sentAt=new Date().toLocaleString("ja-JP");
            s.recipients[id]=r;
        });

        s.updated=new Date().toISOString().slice(0,10);
        save();
        closeModal();
        view.recipients={};
        renderSend();
        toast(ids.length+"件に送付しました");
    };
}
window.openSendConfirm=openSendConfirm;

function showStatus(id){
    view.surveyId=id;
    view.page="status";
    render();
}
window.showStatus=showStatus;

function renderStatus(){
    var s=survey(view.surveyId);
    if(!s)return;

    var c=document.getElementById("content");

    var t=targetCount(s);
    var sent=sentCount(s);
    var a=answeredCount(s);
    var un=unansweredCount(s);
    var unsent=Math.max(t-sent,0);

    c.innerHTML=
        '<div class="page-head"><div><h1>回答状況</h1>'+
        '<p class="desc">'+esc(s.name)+'</p></div>'+
        '<div class="actions">'+
        '<button class="btn" onclick="backList()">一覧へ戻る</button>'+
        '<button class="btn info" onclick="showAggregation(\''+s.id+'\')">回答集計</button>'+
        '</div></div>'+
        '<div class="kpis">'+
        '<div class="kpi"><label>回答対象者数</label><strong>'+t+'</strong></div>'+
        '<div class="kpi"><label>送付済み人数</label><strong>'+sent+'</strong></div>'+
        '<div class="kpi"><label>未送付人数</label><strong>'+unsent+'</strong></div>'+
        '<div class="kpi"><label>回答済み人数</label><strong>'+a+'</strong></div>'+
        '<div class="kpi"><label>未回答人数</label><strong>'+un+'</strong></div>'+
        '<div class="kpi"><label>回答率</label><strong>'+rate(s)+'%</strong></div>'+
        '</div>'+
        '<div class="card" style="margin-top:18px"><div class="card-head">'+
        '<h2>顧客別回答状況</h2>'+
        '<span class="small">未回答者を選択して再送できます</span>'+
        '</div><div class="card-body">'+
        '<div class="table-scroll"><table><thead><tr>'+
        '<th>顧客</th><th>送付状況</th><th>回答状況</th><th>回答日時</th><th>操作</th>'+
        '</tr></thead><tbody>'+
        customers.filter(function(c){return s.recipients[c.id]}).map(function(cu){
            var r=s.recipients[cu.id];

            return '<tr>'+
                '<td>'+esc(cu.name)+'</td>'+
                '<td>'+esc(r.status==="sent"?"送付済み":"未送付")+'</td>'+
                '<td>'+esc(r.answered?"回答済み":"未回答")+'</td>'+
                '<td>'+esc(r.answeredAt||"-")+'</td>'+
                '<td>'+
                (r.answered?
                '<button class="btn small" onclick="showAnswers(\''+
                s.id+'\',\''+cu.id+'\')">回答を見る</button>':
                (s.status==="active"?
                '<button class="btn small info" onclick="resendOne(\''+
                s.id+'\',\''+cu.id+'\')">再送</button>':""))+
                '</td></tr>';
        }).join("")+
        '</tbody></table></div></div></div>';
}

function resendOne(sid,cid){
    var s=survey(sid);
    var cu=customers.find(function(x){return x.id===cid});

    if(!s||s.status!=="active"){
        toast("回答受付中のみ再送できます");
        return;
    }

    confirmAction(
        "再送確認",
        "「"+esc(cu?cu.name:"対象者")+"」へアンケートを再送します。",
        "info",
        "再送する",
        function(){
            var r=s.recipients[cid]||{status:"unsent",answered:false};
            r.status="sent";
            r.sentAt=new Date().toLocaleString("ja-JP");
            s.recipients[cid]=r;
            save();
            render();
            toast("再送しました");
        }
    );
}
window.resendOne=resendOne;

function showAnswers(sid,cid){
    view.surveyId=sid;
    view.answerCustomer=cid;
    view.page="answers";
    render();
}
window.showAnswers=showAnswers;

function renderAnswers(){
    var s=survey(view.surveyId);
    var a=(s.answers||[]).find(function(x){
        return x.customer===view.answerCustomer;
    });
    var cu=customers.find(function(x){
        return x.id===view.answerCustomer;
    });
    var c=document.getElementById("content");

    c.innerHTML=
        '<div class="page-head"><div><h1>回答内容</h1>'+
        '<p class="desc">'+esc(s.name)+' / '+esc(cu?cu.name:"")+'</p></div>'+
        '<div class="actions">'+
        '<button class="btn" onclick="showStatus(\''+s.id+'\')">回答状況へ戻る</button>'+
        '<button class="btn info" onclick="showAggregation(\''+s.id+'\')">回答集計</button>'+
        '</div></div>'+
        '<div class="notice info">回答日時：'+esc(a?a.answeredAt:"-")+'</div>'+
        '<div class="card"><div class="card-body">'+
        (a?allQuestions(s).map(function(q,i){
            var value=a.values[q.id];

            if(Array.isArray(value))value=value.join("、");

            return '<div class="field"><label>Q'+(i+1)+' '+esc(q.text)+
                '</label><div style="padding:9px 10px;background:#f8fafc;border-radius:6px">'+
                esc(value||"回答なし")+'</div></div>';
        }).join(""):
        '<div class="empty">回答がありません。</div>')+
        '</div></div>';
}

function showAggregation(id){
    view.surveyId=id;
    view.page="aggregation";
    renderAggregation();
}
window.showAggregation=showAggregation;

function renderAggregation(){
    var s=survey(view.surveyId);
    if(!s)return;

    var c=document.getElementById("content");
    var qs=allQuestions(s);
    var html=
        '<div class="page-head"><div><h1>回答集計</h1>'+
        '<p class="desc">'+esc(s.name)+'</p></div>'+
        '<div class="actions"><button class="btn" onclick="showStatus(\''+
        s.id+'\')">回答状況へ戻る</button></div></div>'+
        '<div class="kpis">'+
        '<div class="kpi"><label>回答総数</label><strong>'+answeredCount(s)+'</strong></div>'+
        '<div class="kpi"><label>回答率</label><strong>'+rate(s)+'%</strong></div>'+
        '</div>';

    qs.forEach(function(q){
        html+='<div class="card"><div class="card-head"><h2>Q'+
            (qs.indexOf(q)+1)+'. '+esc(q.text)+'</h2>'+
            '<span class="badge">'+typeLabel(q.type)+'</span></div>'+
            '<div class="card-body">';

        if(q.type==="radio"||q.type==="checkbox"){
            var total=answeredCount(s);

            q.choices.forEach(function(choice){
                var count=0;

                (s.answers||[]).forEach(function(a){
                    var v=a.values[q.id];

                    if(Array.isArray(v)){
                        if(v.indexOf(choice)>=0)count++;
                    }else if(v===choice){
                        count++;
                    }
                });

                var pct=total?Math.round(count/total*100):0;

                html+='<div style="margin-bottom:15px">'+
                    '<div style="display:flex;justify-content:space-between">'+
                    '<span>'+esc(choice)+'</span>'+
                    '<strong>'+count+'件 / '+pct+'%</strong></div>'+
                    '<div class="bar"><span style="width:'+pct+'%"></span></div>'+
                    '</div>';
            });
        }else{
            html+='<div class="empty">自由記述は個別回答画面で確認できます。</div>';
        }

        html+='</div></div>';
    });

    c.innerHTML=html;
}

function endSurvey(id){
    var s=survey(id);
    if(!s)return;

    confirmAction(
        "回答受付終了",
        "「"+esc(s.name)+"」の回答受付を終了します。終了後は新しい回答、送付、再送を受け付けません。",
        "warning",
        "終了する",
        function(){
            s.status="ended";
            s.updated=new Date().toISOString().slice(0,10);
            save();
            render();
            toast("回答受付を終了しました");
        }
    );
}
window.endSurvey=endSurvey;

function archiveSurvey(id){
    var s=survey(id);
    if(!s)return;

    confirmAction(
        "保管",
        "「"+esc(s.name)+"」を保管します。保管後は公開・送付・再送・回答受付ができなくなります。",
        "danger",
        "保管する",
        function(){
            s.status="archived";
            s.updated=new Date().toISOString().slice(0,10);
            save();
            render();
            toast("保管しました");
        }
    );
}
window.archiveSurvey=archiveSurvey;

/* -------------------------------------------------------
 * 回答者向け画面
 * ----------------------------------------------------- */

function openRespondentDemo(){
    var active=state.surveys.find(function(s){
        refreshStatusByDate(s);
        return s.status==="active";
    });

    if(!active){
        toast("回答受付中のアンケートがありません");
        return;
    }

    openRespondentFromSurvey(active.id,"c3");
}
window.openRespondentDemo=openRespondentDemo;

function openRespondentFromSurvey(id,cid){
    view.respondentSurveyId=id;
    view.respondentCustomerId=cid||"c3";
    view.respondentValues={};
    view.respondentMode="input";
    view.page="respondent";
    render();
}
window.openRespondentFromSurvey=openRespondentFromSurvey;

function respondentSurvey(){
    return survey(view.respondentSurveyId);
}

function respondentAlreadyAnswered(s){
    return (s.answers||[]).some(function(a){
        return a.customer===view.respondentCustomerId;
    });
}

function respondentAvailableState(s){
    refreshStatusByDate(s);

    if(s.status==="wait")return "wait";
    if(s.status==="ended"||s.status==="archived")return "ended";
    if(s.status!=="active")return "wait";
    return "active";
}

function renderRespondent(){
    var s=respondentSurvey();
    var c=document.getElementById("content");

    if(!s){
        c.innerHTML='<div class="empty">アンケートがありません。</div>';
        return;
    }

    var availability=respondentAvailableState(s);

    if(availability==="wait"){
        c.innerHTML=respondentShell(
            '<div class="respondent-header">'+
            '<span class="status wait">回答開始前</span>'+
            '<h1 style="margin-top:10px">'+esc(s.name)+'</h1>'+
            '<p>'+esc(s.description)+'</p>'+
            '<div class="notice warn">回答受付開始日時になってから回答できます。</div>'+
            '<button class="btn" onclick="backList()">戻る</button>'+
            '</div>'
        );
        return;
    }

    if(availability==="ended"){
        c.innerHTML=respondentShell(
            '<div class="respondent-header">'+
            '<span class="status ended">回答受付終了</span>'+
            '<h1 style="margin-top:10px">'+esc(s.name)+'</h1>'+
            '<p>'+esc(s.description)+'</p>'+
            '<div class="notice warn">このアンケートは現在回答を受け付けていません。</div>'+
            '<button class="btn" onclick="backList()">戻る</button>'+
            '</div>'
        );
        return;
    }

    if(respondentAlreadyAnswered(s)){
        c.innerHTML=respondentShell(
            '<div class="respondent-header">'+
            '<span class="status active">回答済み</span>'+
            '<h1 style="margin-top:10px">'+esc(s.name)+'</h1>'+
            '<div class="notice ok">この対象者はすでに回答済みです。通常の操作では重複回答できません。</div>'+
            '<button class="btn" onclick="backList()">閉じる</button>'+
            '</div>'
        );
        return;
    }

    if(view.respondentMode==="input"){
        renderRespondentInput(s);
    }else if(view.respondentMode==="confirm"){
        renderRespondentConfirm(s);
    }else{
        renderRespondentComplete(s);
    }
}

function respondentShell(body){
    return '<div class="respondent">'+body+'</div>';
}

function visibleQuestions(s){
    var qs=allQuestions(s);
    var result=[];
    var hidden={};

    /*
     * モックとして単一選択の分岐を順番に評価する。
     * 分岐先が質問なら、その質問より前を通常表示し、
     * 指定された質問以降へジャンプする。
     */
    var jumpTo=null;

    for(var i=0;i<qs.length;i++){
        var q=qs[i];

        if(jumpTo){
            if(q.id===jumpTo){
                jumpTo=null;
            }else{
                continue;
            }
        }

        result.push(q);

        if(q.branch&&view.respondentValues[q.id]){
            if(view.respondentValues[q.id]===q.branch.choice){
                var target=q.branch.target;
                var targetQuestion=qs.find(function(x){return x.id===target});

                if(targetQuestion){
                    jumpTo=targetQuestion.id;
                }else{
                    var group=s.groups.find(function(g){return g.id===target});
                    if(group){
                        var first=group.questions[0];
                        if(first)jumpTo=first.id;
                    }
                }
            }
        }
    }

    return result;
}

function renderRespondentInput(s){
    var qs=visibleQuestions(s);

    var progress=qs.length?0:100;

    var html=
        '<div class="respondent">'+
        '<div class="respondent-header">'+
        '<span class="badge">回答者向け画面</span>'+
        '<h1 style="margin-top:10px">'+esc(s.name)+'</h1>'+
        '<p>'+esc(s.description)+'</p>'+
        '<div class="notice info">回答に関する注意事項：必須項目を確認してから送信してください。</div>'+
        '<div class="progress"><span style="width:'+progress+'%"></span></div>'+
        '</div>'+
        '<div class="step"><span class="current">1. 回答入力</span><span>2. 回答確認</span><span>3. 完了</span></div>';

    qs.forEach(function(q,i){
        html+='<div class="answer-q" id="rq_'+q.id+'">'+
            '<div><strong>Q'+(i+1)+'. '+esc(q.text)+'</strong> '+
            (q.required?'<span class="required">必須</span>':"")+
            '</div><div style="margin-top:12px">'+respondentInput(q)+'</div>'+
            '</div>';
    });

    html+='<div class="actions" style="justify-content:flex-end">'+
        '<button class="btn" onclick="backList()">中止</button>'+
        '<button class="btn primary" onclick="reviewRespondent()">回答内容を確認</button>'+
        '</div></div>';

    document.getElementById("content").innerHTML=html;
}

function respondentInput(q){
    var v=view.respondentValues[q.id];

    if(q.type==="text"){
        return '<input type="text" value="'+esc(v||"")+
            '" onchange="setRespondentValue(\''+q.id+'\',this.value)" '+
            'placeholder="回答を入力">';
    }

    if(q.type==="textarea"){
        return '<textarea onchange="setRespondentValue(\''+q.id+'\',this.value)" '+
            'placeholder="回答を入力">'+esc(v||"")+'</textarea>';
    }

    if(q.type==="radio"){
        return q.choices.map(function(x){
            return '<label class="answer-option">'+
                '<input type="radio" name="r_'+q.id+'" value="'+esc(x)+'" '+
                (v===x?"checked":"")+
                ' onchange="setRespondentValue(\''+q.id+'\',this.value)"> '+
                esc(x)+'</label>';
        }).join("");
    }

    var values=Array.isArray(v)?v:[];

    return q.choices.map(function(x){
        return '<label class="answer-option">'+
            '<input type="checkbox" value="'+esc(x)+'" '+
            (values.indexOf(x)>=0?"checked":"")+
            ' onchange="toggleRespondentChoice(\''+q.id+'\',this.value,this.checked)"> '+
            esc(x)+'</label>';
    }).join("");
}

function setRespondentValue(id,value){
    view.respondentValues[id]=value;
}
function toggleRespondentChoice(id,value,checked){
    var arr=Array.isArray(view.respondentValues[id])?
        view.respondentValues[id].slice():[];

    if(checked){
        if(arr.indexOf(value)<0)arr.push(value);
    }else{
        arr=arr.filter(function(x){return x!==value});
    }

    view.respondentValues[id]=arr;
}
window.setRespondentValue=setRespondentValue;
window.toggleRespondentChoice=toggleRespondentChoice;

function reviewRespondent(){
    var s=respondentSurvey();
    var qs=visibleQuestions(s);
    var errors=[];

    qs.forEach(function(q){
        if(!q.required)return;

        var v=view.respondentValues[q.id];
        var empty=Array.isArray(v)?v.length===0:(v==null||String(v).trim()==="");

        if(empty)errors.push("Q"+(allQuestions(s).findIndex(function(x){return x.id===q.id})+1)+
            "「"+q.text+"」が未回答です。");
    });

    if(errors.length){
        modal(
            "入力内容を確認してください",
            '<div class="notice error"><ul>'+
            errors.map(function(x){return "<li>"+esc(x)+"</li>"}).join("")+
            '</ul></div>',
            '<button class="btn primary" onclick="closeModal()">修正する</button>'
        );
        return;
    }

    view.respondentMode="confirm";
    render();
}
window.reviewRespondent=reviewRespondent;

function renderRespondentConfirm(s){
    var qs=visibleQuestions(s);

    var html=
        '<div class="respondent">'+
        '<div class="respondent-header">'+
        '<span class="badge">回答確認</span>'+
        '<h1 style="margin-top:10px">'+esc(s.name)+'</h1>'+
        '<p>送信前に回答内容を確認してください。</p>'+
        '</div>'+
        '<div class="step"><span>1. 回答入力</span><span class="current">2. 回答確認</span><span>3. 完了</span></div>';

    qs.forEach(function(q,i){
        var v=view.respondentValues[q.id];

        if(Array.isArray(v))v=v.join("、");
        if(!v)v="回答なし";

        html+='<div class="answer-summary">'+
            '<strong>Q'+(i+1)+'. '+esc(q.text)+'</strong>'+
            '<div style="margin-top:7px">'+esc(v)+'</div>'+
            '</div>';
    });

    html+='<div class="notice info">内容を修正する場合は回答画面へ戻ってください。</div>'+
        '<div class="actions" style="justify-content:flex-end">'+
        '<button class="btn" onclick="editRespondent()">回答画面へ戻る</button>'+
        '<button class="btn primary" onclick="confirmRespondentSubmit()">回答を送信する</button>'+
        '</div></div>';

    document.getElementById("content").innerHTML=html;
}

function editRespondent(){
    view.respondentMode="input";
    render();
}
window.editRespondent=editRespondent;

function confirmRespondentSubmit(){
    modal(
        "回答送信確認",
        "<p>この内容で回答を送信します。</p>"+
        '<div class="notice warn">送信後は通常の操作で回答を変更できません。</div>',
        '<button class="btn" onclick="closeModal()">戻る</button> '+
        '<button class="btn primary" id="submitAnswer">送信する</button>'
    );

    document.getElementById("submitAnswer").onclick=function(){
        submitRespondent();
    };
}
window.confirmRespondentSubmit=confirmRespondentSubmit;

function submitRespondent(){
    var s=respondentSurvey();
    var cid=view.respondentCustomerId;

    if(respondentAlreadyAnswered(s)){
        closeModal();
        toast("この対象者はすでに回答済みです");
        return;
    }

    var values=clone(view.respondentValues);

    s.answers.push({
        customer:cid,
        answeredAt:new Date().toLocaleString("ja-JP"),
        values:values
    });

    if(!s.recipients[cid]){
        s.recipients[cid]={status:"sent",answered:false};
    }

    s.recipients[cid].answered=true;
    s.recipients[cid].answeredAt=new Date().toLocaleString("ja-JP");

    s.updated=new Date().toISOString().slice(0,10);

    save();
    closeModal();

    view.respondentMode="complete";
    render();
}
window.submitRespondent=submitRespondent;

function renderRespondentComplete(s){
    document.getElementById("content").innerHTML=
        '<div class="respondent">'+
        '<div class="step"><span>1. 回答入力</span><span>2. 回答確認</span><span class="current">3. 完了</span></div>'+
        '<div class="respondent-header" style="text-align:center;padding:50px 25px">'+
        '<div style="font-size:48px">✓</div>'+
        '<h1 style="margin-top:15px">回答が完了しました</h1>'+
        '<p>'+esc(s.name)+'</p>'+
        '<div class="notice ok">回答を受け付けました。ご協力ありがとうございました。</div>'+
        '<button class="btn" onclick="go(\'list\')">閉じる</button>'+
        '</div></div>';
}

window.addEventListener("beforeunload",function(){
    save();
});

document.getElementById("modal").addEventListener("click",function(e){
    if(e.target===this)closeModal();
});

refreshAllStatuses();
render();

})();
</script>
</body>
</html>

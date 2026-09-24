<?php
/*
 * アンケート業務運営アプリ モック
 * 1ファイル完結版
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート業務運営アプリ</title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif;
    color:#263238;
    background:#f4f6f8;
    font-size:14px;
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.topbar{
    height:58px;
    background:#263b53;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 22px;
    gap:28px;
    position:sticky;
    top:0;
    z-index:100;
}
.logo{
    font-size:18px;
    font-weight:bold;
    white-space:nowrap;
}
.main-nav{
    display:flex;
    height:100%;
    gap:2px;
}
.main-nav button{
    border:0;
    background:transparent;
    color:#dce5ed;
    padding:0 18px;
    font-weight:bold;
}
.main-nav button:hover,
.main-nav button.active{
    background:#385572;
    color:#fff;
}
.top-right{
    margin-left:auto;
    color:#c9d4de;
    font-size:12px;
}
.container{
    max-width:1320px;
    margin:0 auto;
    padding:26px;
}
.page-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px;
}
.page-title h1{
    margin:0;
    font-size:24px;
}
.page-title p{
    margin:6px 0 0;
    color:#71808d;
}
.card{
    background:#fff;
    border:1px solid #dfe5ea;
    border-radius:8px;
    box-shadow:0 1px 2px rgba(0,0,0,.03);
    margin-bottom:18px;
}
.card-header{
    padding:16px 20px;
    border-bottom:1px solid #e7ebee;
    display:flex;
    align-items:center;
    justify-content:space-between;
}
.card-header h2{
    font-size:16px;
    margin:0;
}
.card-body{padding:20px}
.btn{
    border:1px solid #cbd4dc;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 14px;
    font-weight:bold;
}
.btn:hover{background:#f3f6f8}
.btn-primary{
    background:#2867a6;
    border-color:#2867a6;
    color:#fff;
}
.btn-primary:hover{background:#205887}
.btn-success{
    background:#218653;
    border-color:#218653;
    color:#fff;
}
.btn-danger{
    background:#fff;
    border-color:#d66a6a;
    color:#b33b3b;
}
.btn-small{
    padding:5px 9px;
    font-size:12px;
}
.status{
    display:inline-block;
    padding:4px 9px;
    border-radius:20px;
    font-size:12px;
    font-weight:bold;
}
.status-draft{background:#eef1f4;color:#65727e}
.status-open{background:#e6f5ed;color:#187342}
.status-closed{background:#f2e9e9;color:#985555}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    padding:13px 12px;
    border-bottom:1px solid #e8ecef;
    text-align:left;
    vertical-align:middle;
}
th{
    color:#657482;
    background:#fafbfc;
    font-size:12px;
}
tr:hover td{background:#fbfcfd}
.action-group{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}
.empty{
    padding:45px;
    text-align:center;
    color:#7c8994;
}
.form-grid{
    display:grid;
    grid-template-columns:180px 1fr;
    gap:14px 20px;
    align-items:start;
}
.form-grid label{
    font-weight:bold;
    padding-top:9px;
}
input[type=text],
input[type=date],
input[type=email],
input[type=number],
input[type=password],
textarea,
select{
    width:100%;
    border:1px solid #cbd5dc;
    border-radius:5px;
    padding:9px 11px;
    background:#fff;
}
textarea{min-height:90px;resize:vertical}
input:focus,textarea:focus,select:focus{
    outline:none;
    border-color:#4d8bc4;
    box-shadow:0 0 0 2px rgba(77,139,196,.12);
}
.editor-toolbar{
    position:sticky;
    top:58px;
    z-index:50;
    background:#f4f6f8;
    padding:10px 0;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}
.group{
    border:1px solid #d7dfe5;
    border-radius:8px;
    margin-bottom:18px;
    background:#fff;
}
.group.dragging{
    opacity:.55;
    border:2px dashed #4d8bc4;
}
.group-header{
    background:#f7f9fa;
    padding:12px 14px;
    border-bottom:1px solid #dfe5e9;
    display:flex;
    align-items:center;
    gap:10px;
}
.drag-handle{
    cursor:grab;
    color:#80909c;
    font-size:18px;
    user-select:none;
}
.group-title{
    flex:1;
}
.group-title input{
    font-weight:bold;
    background:transparent;
    border-color:transparent;
}
.group-title input:focus{
    background:#fff;
    border-color:#cbd5dc;
}
.question-list{
    padding:12px 14px 0;
    min-height:30px;
}
.question{
    border:1px solid #dfe5e9;
    border-radius:7px;
    padding:14px;
    margin-bottom:10px;
    background:#fff;
}
.question.dragging{
    opacity:.45;
    border:2px dashed #4d8bc4;
}
.question-head{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:12px;
}
.question-no{
    min-width:30px;
    height:30px;
    border-radius:50%;
    background:#eaf1f7;
    color:#2867a6;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:bold;
}
.question-text{
    flex:1;
}
.question-body{
    padding-left:40px;
}
.question-row{
    display:grid;
    grid-template-columns:120px 1fr 120px;
    gap:10px;
    margin-bottom:10px;
}
.choice-list{
    margin-top:8px;
}
.choice-row{
    display:flex;
    gap:8px;
    margin-bottom:6px;
}
.choice-row input{flex:1}
.choice-remove{
    border:0;
    background:#fff0f0;
    color:#a44;
    border-radius:4px;
    width:32px;
}
.question-actions{
    display:flex;
    gap:6px;
}
.question-add{
    padding:13px 14px;
    border-top:1px dashed #d8dfe4;
}
.group-add{
    text-align:center;
    padding:4px 0 20px;
}
.branch-box{
    background:#f5f8fb;
    border:1px solid #dce6ee;
    padding:10px;
    border-radius:5px;
    margin-top:10px;
}
.branch-row{
    display:grid;
    grid-template-columns:150px 1fr;
    gap:10px;
    align-items:center;
    margin-bottom:6px;
}
.editor-footer{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:18px;
}
.detail-tabs{
    display:flex;
    gap:0;
    border-bottom:1px solid #dfe5e9;
    margin-bottom:18px;
}
.detail-tabs button{
    border:0;
    border-bottom:3px solid transparent;
    background:transparent;
    padding:12px 20px;
    color:#667784;
    font-weight:bold;
}
.detail-tabs button.active{
    color:#2867a6;
    border-bottom-color:#2867a6;
}
.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
}
.stat{
    border:1px solid #e0e6ea;
    border-radius:7px;
    padding:18px;
    background:#fff;
}
.stat-label{font-size:12px;color:#71808d}
.stat-value{
    font-size:27px;
    font-weight:bold;
    margin-top:7px;
    color:#263b53;
}
.bar-wrap{margin:14px 0}
.bar-label{
    display:flex;
    justify-content:space-between;
    font-size:12px;
    margin-bottom:5px;
}
.bar{
    height:10px;
    background:#e9edf0;
    border-radius:10px;
    overflow:hidden;
}
.bar span{
    display:block;
    height:100%;
    background:#4b8fc5;
}
.content-preview{
    background:#fafbfc;
    border:1px solid #e1e7eb;
    border-radius:7px;
    padding:20px;
}
.preview-question{
    margin-bottom:22px;
}
.preview-question h3{
    font-size:15px;
    margin:0 0 10px;
}
.answer-option{
    display:block;
    padding:7px 0;
}
.answer-page{
    min-height:calc(100vh - 58px);
    background:#fff;
}
.answer-inner{
    max-width:760px;
    margin:0 auto;
    padding:45px 25px 70px;
}
.answer-title{
    font-size:26px;
    margin:0 0 8px;
}
.answer-desc{
    color:#697984;
    white-space:pre-line;
    margin-bottom:35px;
}
.answer-section{
    margin-bottom:30px;
}
.answer-section h2{
    font-size:18px;
    border-left:4px solid #2867a6;
    padding-left:10px;
}
.answer-q{
    margin:20px 0;
}
.answer-q-title{
    font-weight:bold;
    margin-bottom:10px;
}
.required{
    color:#c14a4a;
    font-size:11px;
    margin-left:5px;
}
.answer-footer{
    border-top:1px solid #e0e5e8;
    padding-top:25px;
    display:flex;
    justify-content:flex-end;
}
.send-confirm{
    background:#f8fafb;
    border:1px solid #dde4e8;
    border-radius:7px;
    padding:15px;
}
.customer-toolbar{
    display:flex;
    gap:10px;
    margin-bottom:15px;
}
.customer-count{
    color:#687782;
    padding:8px 0;
}
.mail-layout{
    display:grid;
    grid-template-columns:1fr 330px;
    gap:18px;
}
.selected-box{
    border:1px solid #dce4e9;
    border-radius:7px;
    background:#fafbfc;
    padding:15px;
}
.selected-list{
    max-height:350px;
    overflow:auto;
}
.selected-item{
    padding:8px 0;
    border-bottom:1px solid #e5e9ec;
}
.notice{
    padding:12px 15px;
    border-radius:5px;
    margin-bottom:15px;
}
.notice-info{background:#edf5fb;color:#315c7b}
.notice-success{background:#eaf7ef;color:#237044}
.notice-warning{background:#fff7e6;color:#8b641f}
.hidden{display:none!important}
.modal-bg{
    position:fixed;
    inset:0;
    background:rgba(25,35,45,.45);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:300;
}
.modal{
    width:520px;
    max-width:calc(100% - 30px);
    background:#fff;
    border-radius:8px;
    box-shadow:0 15px 45px rgba(0,0,0,.2);
}
.modal-header{
    padding:16px 20px;
    border-bottom:1px solid #e4e8eb;
    font-weight:bold;
}
.modal-body{padding:20px}
.modal-footer{
    padding:12px 20px;
    border-top:1px solid #e4e8eb;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}
.toast{
    position:fixed;
    right:20px;
    bottom:20px;
    background:#263b53;
    color:#fff;
    padding:12px 18px;
    border-radius:6px;
    box-shadow:0 5px 20px rgba(0,0,0,.18);
    z-index:500;
}
@media(max-width:900px){
    .topbar{gap:8px;padding:0 10px}
    .main-nav button{padding:0 9px;font-size:12px}
    .top-right{display:none}
    .container{padding:15px}
    .stat-grid{grid-template-columns:repeat(2,1fr)}
    .mail-layout{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div id="app"></div>

<div id="toast" class="toast hidden"></div>

<div id="confirmModal" class="modal-bg hidden">
    <div class="modal">
        <div class="modal-header" id="confirmTitle">確認</div>
        <div class="modal-body" id="confirmMessage"></div>
        <div class="modal-footer">
            <button class="btn" onclick="closeConfirm()">キャンセル</button>
            <button class="btn btn-danger" id="confirmOk">実行する</button>
        </div>
    </div>
</div>

<script>
var state = {
    page: 'list',
    selectedSurvey: null,
    detailTab: 'content',
    editingSurvey: null,
    editing: false,
    mailSelected: [],
    answerStep: 1
};

var surveys = [
    {
        id:1,
        name:'2026年度 お客様満足度アンケート',
        status:'open',
        created:'2026/09/01',
        period:'2026/09/01 ～ 2026/10/31',
        answers:128,
        rate:64,
        updated:'2026/09/20',
        description:'サービスをご利用いただいた皆様へのアンケートです。',
        groups:[
            {
                id:'g1',
                name:'ご利用状況',
                questions:[
                    {id:'q1',text:'当社サービスを利用したことがありますか？',type:'single',required:true,choices:['はい','いいえ'],branches:{'はい':'q2','いいえ':'q3'}},
                    {id:'q2',text:'どのくらいの頻度で利用していますか？',type:'single',required:true,choices:['毎日','週に数回','月に数回','それ以下'],branches:{}},
                    {id:'q3',text:'今後利用してみたいと思いますか？',type:'single',required:false,choices:['はい','いいえ','わからない'],branches:{}}
                ]
            },
            {
                id:'g2',
                name:'サービスについて',
                questions:[
                    {id:'q4',text:'サービスの満足度を教えてください。',type:'rating',required:true,choices:['1','2','3','4','5'],branches:{}},
                    {id:'q5',text:'ご意見・ご要望があればお聞かせください。',type:'text',required:false,choices:[],branches:{}}
                ]
            }
        ]
    },
    {
        id:2,
        name:'新商品に関するアンケート',
        status:'draft',
        created:'2026/09/15',
        period:'未公開',
        answers:0,
        rate:0,
        updated:'2026/09/21',
        description:'新商品についてのご意見をお聞かせください。',
        groups:[
            {
                id:'g3',
                name:'商品について',
                questions:[
                    {id:'q6',text:'新商品の印象を教えてください。',type:'single',required:true,choices:['とても良い','良い','普通','あまり良くない'],branches:{}}
                ]
            }
        ]
    },
    {
        id:3,
        name:'2025年度 サービス利用状況調査',
        status:'closed',
        created:'2025/09/01',
        period:'2025/09/01 ～ 2025/10/31',
        answers:205,
        rate:82,
        updated:'2025/11/01',
        description:'昨年度のサービス利用状況を確認します。',
        groups:[
            {
                id:'g4',
                name:'利用状況',
                questions:[
                    {id:'q7',text:'サービスに満足していますか？',type:'single',required:true,choices:['はい','いいえ'],branches:{}}
                ]
            }
        ]
    }
];

var customers = [
    {id:1,name:'株式会社青山商事',email:'aoyama@example.jp'},
    {id:2,name:'株式会社山田産業',email:'yamada@example.jp'},
    {id:3,name:'鈴木 太郎',email:'suzuki@example.jp'},
    {id:4,name:'田中 花子',email:'tanaka@example.jp'},
    {id:5,name:'株式会社中央企画',email:'chuo@example.jp'},
    {id:6,name:'佐藤 一郎',email:'sato@example.jp'},
    {id:7,name:'株式会社東都サービス',email:'toto@example.jp'}
];

function esc(s){
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function statusLabel(status){
    if(status==='open') return '<span class="status status-open">公開中</span>';
    if(status==='closed') return '<span class="status status-closed">終了</span>';
    return '<span class="status status-draft">下書き</span>';
}

function showToast(msg){
    var el=document.getElementById('toast');
    el.textContent=msg;
    el.classList.remove('hidden');
    setTimeout(function(){el.classList.add('hidden');},2200);
}

function surveyById(id){
    for(var i=0;i<surveys.length;i++){
        if(surveys[i].id==id) return surveys[i];
    }
    return null;
}

function allQuestions(survey){
    var arr=[];
    survey.groups.forEach(function(g){
        g.questions.forEach(function(q){arr.push(q);});
    });
    return arr;
}

function questionNo(survey,qid){
    var n=1;
    for(var i=0;i<survey.groups.length;i++){
        for(var j=0;j<survey.groups[i].questions.length;j++){
            if(survey.groups[i].questions[j].id===qid) return n;
            n++;
        }
    }
    return n;
}

function questionText(survey,qid){
    var qs=allQuestions(survey);
    for(var i=0;i<qs.length;i++){
        if(qs[i].id===qid) return qs[i].text;
    }
    return '削除された質問';
}

function render(){
    var app=document.getElementById('app');

    if(state.page==='answer'){
        renderAnswer(app);
        return;
    }

    app.innerHTML =
        '<header class="topbar">'+
            '<div class="logo">アンケート業務運営</div>'+
            '<nav class="main-nav">'+
                navButton('list','アンケート一覧')+
                navButton('create','アンケート作成')+
                navButton('customers','顧客一覧')+
                navButton('settings','メール送信設定')+
            '</nav>'+
            '<div class="top-right">アンケート運営者</div>'+
        '</header>'+
        '<main class="container" id="mainContent"></main>';

    if(state.page==='list') renderList();
    else if(state.page==='create') renderEditor();
    else if(state.page==='detail') renderDetail();
    else if(state.page==='customers') renderCustomers();
    else if(state.page==='settings') renderSettings();
    else if(state.page==='mail') renderMail();
}

function navButton(page,label){
    var active=(state.page===page || (page==='list' && state.page==='detail'))?' active':'';
    return '<button class="'+active+'" onclick="navigate(\''+page+'\')">'+label+'</button>';
}

function navigate(page){
    state.page=page;
    if(page==='create'){
        state.editingSurvey=null;
        state.editing=false;
    }
    render();
}

function renderList(){
    var html =
        '<div class="page-title">'+
            '<div><h1>アンケート一覧</h1><p>作成済みのアンケートを管理します</p></div>'+
            '<button class="btn btn-primary" onclick="navigate(\'create\')">＋ アンケート作成</button>'+
        '</div>'+
        '<div class="card">'+
            '<div class="card-header"><h2>アンケート</h2><span>'+surveys.length+'件</span></div>'+
            '<div class="card-body" style="padding:0">'+
            '<table><thead><tr>'+
                '<th>アンケート名</th><th>状態</th><th>公開期間</th><th>回答数</th><th>最終更新</th><th>操作</th>'+
            '</tr></thead><tbody>';

    surveys.forEach(function(s){
        html += '<tr>'+
            '<td><strong>'+esc(s.name)+'</strong></td>'+
            '<td>'+statusLabel(s.status)+'</td>'+
            '<td>'+esc(s.period)+'</td>'+
            '<td>'+s.answers+'件</td>'+
            '<td>'+esc(s.updated)+'</td>'+
            '<td><div class="action-group">'+
                '<button class="btn btn-small" onclick="openDetail('+s.id+',\'content\')">開く</button>'+
                '<button class="btn btn-small" onclick="editSurvey('+s.id+')">編集</button>'+
                (s.status==='open' ? '<button class="btn btn-small btn-danger" onclick="closeSurvey('+s.id+')">終了</button>':'')+
                (s.status==='draft' ? '<button class="btn btn-small btn-danger" onclick="deleteSurvey('+s.id+')">削除</button>':'')+
            '</div></td>'+
        '</tr>';
    });

    html+='</tbody></table></div></div>';

    document.getElementById('mainContent').innerHTML=html;
}

function editSurvey(id){
    var s=surveyById(id);
    state.editingSurvey=JSON.parse(JSON.stringify(s));
    state.editing=true;
    state.page='create';
    render();
}

function renderEditor(){
    if(!state.editingSurvey){
        state.editingSurvey={
            id:Date.now(),
            name:'',
            status:'draft',
            created:'2026/09/24',
            period:'未公開',
            answers:0,
            rate:0,
            updated:'2026/09/24',
            description:'',
            groups:[
                {id:'g'+Date.now(),name:'新しいグループ',questions:[]}
            ]
        };
    }

    var s=state.editingSurvey;
    var html=
        '<div class="page-title">'+
            '<div><h1>'+(state.editing?'アンケート編集':'アンケート作成')+'</h1><p>アンケート全体を1画面で編集できます</p></div>'+
            '<div class="action-group">'+
                '<button class="btn" onclick="navigate(\'list\')">一覧へ戻る</button>'+
                '<button class="btn btn-primary" onclick="saveSurvey()">保存する</button>'+
            '</div>'+
        '</div>'+
        '<div class="card">'+
            '<div class="card-header"><h2>アンケート基本情報</h2></div>'+
            '<div class="card-body">'+
                '<div class="form-grid">'+
                    '<label>アンケート名 *</label>'+
                    '<input id="surveyName" type="text" value="'+esc(s.name)+'" placeholder="例：2026年度 お客様満足度アンケート">'+
                    '<label>説明</label>'+
                    '<textarea id="surveyDesc" placeholder="回答者への説明を入力してください">'+esc(s.description)+'</textarea>'+
                    '<label>公開開始日</label>'+
                    '<input id="startDate" type="date">'+
                    '<label>公開終了日</label>'+
                    '<input id="endDate" type="date">'+
                    '<label>公開状態</label>'+
                    '<div>'+statusLabel(s.status)+'</div>'+
                '</div>'+
            '</div>'+
        '</div>'+
        '<div id="groupsContainer"></div>'+
        '<div class="group-add">'+
            '<button class="btn btn-primary" onclick="addGroup()">＋ グループ追加</button>'+
        '</div>'+
        '<div class="editor-footer">'+
            '<button class="btn" onclick="navigate(\'list\')">キャンセル</button>'+
            '<button class="btn btn-primary" onclick="saveSurvey()">保存する</button>'+
        '</div>';

    document.getElementById('mainContent').innerHTML=html;
    renderGroups();
}

function renderGroups(){
    var s=state.editingSurvey;
    var box=document.getElementById('groupsContainer');
    var html='';

    s.groups.forEach(function(g,gi){
        html+=
        '<div class="group" draggable="true" data-group-index="'+gi+'" ondragstart="groupDragStart(event)" ondragover="allowDrop(event)" ondrop="groupDrop(event)" ondragend="dragEnd(event)">'+
            '<div class="group-header">'+
                '<span class="drag-handle" title="ドラッグしてグループを移動">☷</span>'+
                '<span style="font-size:12px;color:#71808d">グループ '+(gi+1)+'</span>'+
                '<div class="group-title"><input type="text" value="'+esc(g.name)+'" onchange="updateGroupName('+gi+',this.value)"></div>'+
                '<button class="btn btn-small btn-danger" onclick="removeGroup('+gi+')">グループ削除</button>'+
            '</div>'+
            '<div class="question-list" id="questionList-'+gi+'">';

        g.questions.forEach(function(q,qi){
            html+=renderQuestion(s,g,gi,qi,q);
        });

        html+='</div>'+
            '<div class="question-add">'+
                '<button class="btn" onclick="addQuestion('+gi+')">＋ 質問追加</button>'+
            '</div>'+
        '</div>';
    });

    box.innerHTML=html;
}

function renderQuestion(s,g,gi,qi,q){
    var typeLabels={
        single:'単一選択',
        multi:'複数選択',
        text:'記述式',
        number:'数値',
        rating:'段階評価'
    };

    var html=
        '<div class="question" draggable="true" data-group-index="'+gi+'" data-question-index="'+qi+'" ondragstart="questionDragStart(event)" ondragover="allowDrop(event)" ondrop="questionDrop(event)" ondragend="dragEnd(event)">'+
            '<div class="question-head">'+
                '<span class="drag-handle" title="ドラッグして質問を移動">☷</span>'+
                '<span class="question-no">'+questionNo(s,q.id)+'</span>'+
                '<strong style="font-size:13px;color:#637381">質問</strong>'+
                '<div class="question-actions" style="margin-left:auto">'+
                    '<button class="btn btn-small btn-danger" onclick="removeQuestion('+gi+','+qi+')">削除</button>'+
                '</div>'+
            '</div>'+
            '<div class="question-body">'+
                '<input type="text" value="'+esc(q.text)+'" placeholder="質問文を入力してください" onchange="updateQuestion('+gi+','+qi+',\'text\',this.value)">'+
                '<div class="question-row" style="margin-top:10px">'+
                    '<select onchange="changeQuestionType('+gi+','+qi+',this.value)">'+
                        option('single','単一選択',q.type)+
                        option('multi','複数選択',q.type)+
                        option('text','記述式',q.type)+
                        option('number','数値',q.type)+
                        option('rating','段階評価',q.type)+
                    '</select>'+
                    '<div></div>'+
                    '<label style="display:flex;align-items:center;gap:6px;font-size:12px">'+
                        '<input type="checkbox" '+(q.required?'checked':'')+' onchange="updateQuestion('+gi+','+qi+',\'required\',this.checked)"> 必須回答'+
                    '</label>'+
                '</div>';

    if(q.type==='single' || q.type==='multi' || q.type==='rating'){
        html+='<div class="choice-list"><div style="font-size:12px;color:#687782;margin-bottom:5px">回答選択肢</div>';
        q.choices.forEach(function(c,ci){
            html+='<div class="choice-row">'+
                '<input type="text" value="'+esc(c)+'" onchange="updateChoice('+gi+','+qi+','+ci+',this.value)">'+
                '<button class="choice-remove" onclick="removeChoice('+gi+','+qi+','+ci+')">×</button>'+
            '</div>';
        });
        if(q.type!=='rating'){
            html+='<button class="btn btn-small" onclick="addChoice('+gi+','+qi+')">＋ 選択肢追加</button>';
        }
        html+='</div>';
    }

    if(q.type==='single'){
        html+='<div class="branch-box">'+
            '<strong style="font-size:12px">回答による分岐</strong>'+
            '<div style="font-size:11px;color:#71808d;margin:4px 0 8px">選択肢ごとに次に表示する質問を指定できます。</div>';

        q.choices.forEach(function(c){
            var target=q.branches && q.branches[c] ? q.branches[c] : '';
            html+='<div class="branch-row">'+
                '<span>'+esc(c)+'</span>'+
                '<select onchange="updateBranch('+gi+','+qi+',\''+encodeURIComponent(c)+'\',this.value)">'+
                    '<option value="">通常の順番で次へ</option>'+
                    allQuestions(s).filter(function(x){return x.id!==q.id;}).map(function(x){
                        return '<option value="'+x.id+'" '+(target===x.id?'selected':'')+'>質問'+questionNo(s,x.id)+'：'+esc(x.text)+'</option>';
                    }).join('')+
                '</select>'+
            '</div>';
        });

        html+='</div>';
    }

    html+='</div></div>';
    return html;
}

function option(v,label,current){
    return '<option value="'+v+'" '+(v===current?'selected':'')+'>'+label+'</option>';
}

function updateGroupName(gi,value){
    state.editingSurvey.groups[gi].name=value;
}

function updateQuestion(gi,qi,key,value){
    state.editingSurvey.groups[gi].questions[qi][key]=value;
}

function updateChoice(gi,qi,ci,value){
    var q=state.editingSurvey.groups[gi].questions[qi];
    q.choices[ci]=value;
}

function changeQuestionType(gi,qi,type){
    var q=state.editingSurvey.groups[gi].questions[qi];
    q.type=type;
    if(type==='text' || type==='number'){
        q.choices=[];
        q.branches={};
    }else if(type==='rating'){
        q.choices=['1','2','3','4','5'];
        q.branches={};
    }else if(!q.choices.length){
        q.choices=['選択肢1','選択肢2'];
    }
    renderGroups();
}

function addChoice(gi,qi){
    state.editingSurvey.groups[gi].questions[qi].choices.push('新しい選択肢');
    renderGroups();
}

function removeChoice(gi,qi,ci){
    var q=state.editingSurvey.groups[gi].questions[qi];
    if(q.choices.length<=1){
        showToast('選択肢は1つ以上必要です');
        return;
    }
    q.choices.splice(ci,1);
    renderGroups();
}

function updateBranch(gi,qi,choiceEncoded,target){
    var choice=decodeURIComponent(choiceEncoded);
    var q=state.editingSurvey.groups[gi].questions[qi];
    if(!q.branches) q.branches={};
    if(target) q.branches[choice]=target;
    else delete q.branches[choice];
}

function addGroup(){
    state.editingSurvey.groups.push({
        id:'g'+Date.now(),
        name:'新しいグループ',
        questions:[]
    });
    renderGroups();
    showToast('グループを末尾に追加しました');
}

function removeGroup(gi){
    var g=state.editingSurvey.groups[gi];
    showConfirm(
        'グループを削除しますか？',
        g.questions.length ?
        '「'+g.name+'」と、その中にある質問 '+g.questions.length+' 件を削除します。' :
        '「'+g.name+'」を削除します。',
        function(){
            state.editingSurvey.groups.splice(gi,1);
            renderGroups();
            showToast('グループを削除しました');
        }
    );
}

function addQuestion(gi){
    var id='q'+Date.now()+Math.floor(Math.random()*1000);
    state.editingSurvey.groups[gi].questions.push({
        id:id,
        text:'新しい質問',
        type:'single',
        required:false,
        choices:['選択肢1','選択肢2'],
        branches:{}
    });
    renderGroups();
    showToast('質問を末尾に追加しました');
}

function removeQuestion(gi,qi){
    var q=state.editingSurvey.groups[gi].questions[qi];
    showConfirm(
        '質問を削除しますか？',
        '「'+q.text+'」を削除します。分岐先に設定されている場合、その設定も確認が必要です。',
        function(){
            state.editingSurvey.groups[gi].questions.splice(qi,1);
            renderGroups();
            showToast('質問を削除しました');
        }
    );
}

var draggedQuestion=null;
var draggedGroup=null;

function groupDragStart(e){
    draggedGroup=parseInt(e.currentTarget.getAttribute('data-group-index'),10);
    e.currentTarget.classList.add('dragging');
    e.dataTransfer.effectAllowed='move';
}

function questionDragStart(e){
    draggedQuestion={
        group:parseInt(e.currentTarget.getAttribute('data-group-index'),10),
        index:parseInt(e.currentTarget.getAttribute('data-question-index'),10)
    };
    e.currentTarget.classList.add('dragging');
    e.dataTransfer.effectAllowed='move';
}

function allowDrop(e){
    e.preventDefault();
    e.dataTransfer.dropEffect='move';
}

function dragEnd(e){
    e.currentTarget.classList.remove('dragging');
}

function groupDrop(e){
    e.preventDefault();
    var target=parseInt(e.currentTarget.getAttribute('data-group-index'),10);
    if(draggedGroup===null || draggedGroup===target) return;

    var moved=state.editingSurvey.groups.splice(draggedGroup,1)[0];
    state.editingSurvey.groups.splice(target,0,moved);
    draggedGroup=null;
    renderGroups();
    showToast('グループの順番を変更しました');
}

function questionDrop(e){
    e.preventDefault();
    var targetGroup=parseInt(e.currentTarget.getAttribute('data-group-index'),10);
    var targetIndex=parseInt(e.currentTarget.getAttribute('data-question-index'),10);

    if(!draggedQuestion) return;
    if(draggedQuestion.group!==targetGroup){
        showToast('質問は同じグループ内で並べ替えてください');
        draggedQuestion=null;
        return;
    }

    if(draggedQuestion.index===targetIndex){
        draggedQuestion=null;
        return;
    }

    var list=state.editingSurvey.groups[targetGroup].questions;
    var moved=list.splice(draggedQuestion.index,1)[0];
    list.splice(targetIndex,0,moved);

    draggedQuestion=null;
    renderGroups();
    showToast('質問の順番を変更しました');
}

function saveSurvey(){
    var name=document.getElementById('surveyName').value.trim();
    if(!name){
        showToast('アンケート名を入力してください');
        document.getElementById('surveyName').focus();
        return;
    }

    state.editingSurvey.name=name;
    state.editingSurvey.description=document.getElementById('surveyDesc').value;
    state.editingSurvey.updated='2026/09/24';

    var found=false;
    for(var i=0;i<surveys.length;i++){
        if(surveys[i].id===state.editingSurvey.id){
            surveys[i]=JSON.parse(JSON.stringify(state.editingSurvey));
            found=true;
            break;
        }
    }
    if(!found) surveys.push(JSON.parse(JSON.stringify(state.editingSurvey)));

    showToast('アンケートを保存しました');
    state.editing=true;
    setTimeout(function(){render();},500);
}

function openDetail(id,tab){
    state.selectedSurvey=id;
    state.detailTab=tab||'content';
    state.page='detail';
    render();
}

function renderDetail(){
    var s=surveyById(state.selectedSurvey);
    if(!s){navigate('list');return;}

    var html=
        '<div class="page-title">'+
            '<div><h1>'+esc(s.name)+'</h1><p>'+statusLabel(s.status)+'　'+esc(s.period)+'</p></div>'+
            '<div class="action-group">'+
                '<button class="btn" onclick="navigate(\'list\')">一覧へ戻る</button>'+
                '<button class="btn btn-primary" onclick="editSurvey('+s.id+')">編集</button>'+
                (s.status==='open'?'<button class="btn btn-danger" onclick="closeSurvey('+s.id+')">アンケートを終了</button>':'')+
            '</div>'+
        '</div>'+
        '<div class="detail-tabs">'+
            detailTabButton('content','アンケート内容')+
            detailTabButton('status','回答状況')+
            detailTabButton('result','回答結果')+
            '<button onclick="openMail('+s.id+')">回答依頼メール</button>'+
        '</div>'+
        '<div id="detailBody"></div>';

    document.getElementById('mainContent').innerHTML=html;

    if(state.detailTab==='content') renderDetailContent(s);
    else if(state.detailTab==='status') renderStatus(s);
    else renderResult(s);
}

function detailTabButton(tab,label){
    return '<button class="'+(state.detailTab===tab?'active':'')+'" onclick="switchDetail(\''+tab+'\')">'+label+'</button>';
}

function switchDetail(tab){
    state.detailTab=tab;
    render();
}

function renderDetailContent(s){
    var html='<div class="card"><div class="card-header"><h2>アンケート構成</h2></div><div class="card-body">';
    html+='<p>'+esc(s.description)+'</p>';

    s.groups.forEach(function(g,gi){
        html+='<div class="content-preview" style="margin-bottom:15px">'+
            '<h3 style="margin-top:0">'+(gi+1)+'. '+esc(g.name)+'</h3>';
        g.questions.forEach(function(q,qi){
            html+='<div class="preview-question">'+
                '<h3>質問'+questionNo(s,q.id)+'　'+esc(q.text)+(q.required?' <span class="required">必須</span>':'')+'</h3>'+
                '<div style="font-size:12px;color:#71808d">回答形式：'+
                    ({single:'単一選択',multi:'複数選択',text:'記述式',number:'数値',rating:'段階評価'}[q.type]||q.type)+
                '</div>';
            if(q.choices.length){
                q.choices.forEach(function(c){html+='<div class="answer-option">○ '+esc(c)+'</div>';});
            }
            html+='</div>';
        });
        html+='</div>';
    });

    html+='</div></div>';
    document.getElementById('detailBody').innerHTML=html;
}

function renderStatus(s){
    var sent=s.status==='open'?200:0;
    var unanswered=Math.max(0,sent-s.answers);
    var html=
        '<div class="stat-grid">'+
            stat('回答数',s.answers+'件')+
            stat('回答率',s.rate+'%')+
            stat('未回答',unanswered+'件')+
            stat('公開期間',s.status==='open'?'公開中':'終了')+
        '</div>'+
        '<div class="card" style="margin-top:18px">'+
            '<div class="card-header"><h2>回答状況</h2></div>'+
            '<div class="card-body">'+
                '<div class="bar-wrap"><div class="bar-label"><span>回答率</span><strong>'+s.rate+'%</strong></div><div class="bar"><span style="width:'+s.rate+'%"></span></div></div>'+
                '<div class="bar-wrap"><div class="bar-label"><span>回答数</span><strong>'+s.answers+'件</strong></div><div class="bar"><span style="width:'+Math.min(100,s.answers/2)+'%"></span></div></div>'+
                '<div style="margin-top:25px;color:#687782">公開期間：'+esc(s.period)+'</div>'+
            '</div>'+
        '</div>'+
        '<div class="card">'+
            '<div class="card-header"><h2>回答状況の推移</h2></div>'+
            '<div class="card-body">'+
                '<div style="height:160px;display:flex;align-items:end;gap:18px;border-bottom:1px solid #dfe5e9;padding:0 20px">'+
                    barCol('9/1',18)+barCol('9/8',27)+barCol('9/15',41)+barCol('9/22',63)+barCol('9/29',78)+barCol('10/6',88)+
                '</div>'+
            '</div>'+
        '</div>';

    document.getElementById('detailBody').innerHTML=html;
}

function stat(label,value){
    return '<div class="stat"><div class="stat-label">'+label+'</div><div class="stat-value">'+value+'</div></div>';
}

function barCol(label,height){
    return '<div style="flex:1;text-align:center"><div style="height:'+height+'px;background:#5c98c7;border-radius:5px 5px 0 0"></div><div style="font-size:11px;color:#71808d;padding-top:5px">'+label+'</div></div>';
}

function renderResult(s){
    var html='<div class="card"><div class="card-header"><h2>回答結果</h2></div><div class="card-body">';

    var qs=allQuestions(s);
    qs.forEach(function(q,i){
        html+='<div style="border-bottom:1px solid #e3e8eb;padding:18px 0">'+
            '<strong>質問'+(i+1)+'　'+esc(q.text)+'</strong>';

        if(q.type==='single' || q.type==='multi' || q.type==='rating'){
            q.choices.forEach(function(c,ci){
                var count=Math.max(0,Math.round(s.answers*(ci===0?.48:ci===1?.27:ci===2?.16:.09)));
                var pct=s.answers?Math.round(count/s.answers*100):0;
                html+='<div class="bar-wrap">'+
                    '<div class="bar-label"><span>'+esc(c)+'</span><strong>'+count+'件（'+pct+'%）</strong></div>'+
                    '<div class="bar"><span style="width:'+pct+'%"></span></div>'+
                '</div>';
            });
        }else if(q.type==='text'){
            html+='<div style="margin-top:12px;background:#f7f9fa;padding:12px;border-radius:5px">'+
                '「実際に利用してみて、とても使いやすかったです。」<br><br>'+
                '「今後も継続して利用したいと思います。」<br><br>'+
                '「サポート対応についてもう少し詳しく知りたいです。」'+
            '</div>';
        }else{
            html+='<div style="margin-top:10px;color:#71808d">回答データを集計しています。</div>';
        }

        html+='</div>';
    });

    html+='</div></div>';
    document.getElementById('detailBody').innerHTML=html;
}

function openMail(id){
    state.selectedSurvey=id;
    state.page='mail';
    state.mailSelected=[];
    render();
}

function renderMail(){
    var s=surveyById(state.selectedSurvey);

    var html=
        '<div class="page-title">'+
            '<div><h1>回答依頼メール</h1><p>'+esc(s.name)+'</p></div>'+
            '<button class="btn" onclick="openDetail('+s.id+',\'content\')">アンケートへ戻る</button>'+
        '</div>'+
        '<div class="notice notice-info">顧客一覧から送信対象者を選択して、アンケート回答依頼メールを送信します。</div>'+
        '<div class="mail-layout">'+
            '<div>'+
                '<div class="card">'+
                    '<div class="card-header"><h2>送信対象者</h2><span id="selectedCount">0名選択</span></div>'+
                    '<div class="card-body">'+
                        '<div class="customer-toolbar">'+
                            '<button class="btn btn-small" onclick="selectAllCustomers()">全員選択</button>'+
                            '<button class="btn btn-small" onclick="clearCustomers()">選択解除</button>'+
                        '</div>'+
                        '<table><thead><tr><th style="width:45px"></th><th>顧客名</th><th>メールアドレス</th></tr></thead><tbody>'+
                        customers.map(function(c){
                            return '<tr>'+
                                '<td><input type="checkbox" class="customer-check" value="'+c.id+'" onchange="toggleCustomer('+c.id+',this.checked)"></td>'+
                                '<td>'+esc(c.name)+'</td>'+
                                '<td>'+esc(c.email)+'</td>'+
                            '</tr>';
                        }).join('')+
                        '</tbody></table>'+
                    '</div>'+
                '</div>'+
                '<div class="card">'+
                    '<div class="card-header"><h2>メール内容</h2></div>'+
                    '<div class="card-body">'+
                        '<div class="form-grid">'+
                            '<label>件名</label><input type="text" value="【アンケートのお願い】'+esc(s.name)+'">'+
                            '<label>本文</label><textarea style="min-height:220px">いつもお世話になっております。

'+esc(s.name)+'へのご回答をお願いいたします。

以下のボタンからアンケートにご回答ください。

［アンケートに回答する］

ご協力のほど、よろしくお願いいたします。</textarea>'+
                        '</div>'+
                    '</div>'+
                '</div>'+
                '<div class="editor-footer"><button class="btn btn-primary" onclick="confirmSendMail()">送信前に確認する</button></div>'+
            '</div>'+
            '<div>'+
                '<div class="selected-box">'+
                    '<strong>送信対象</strong>'+
                    '<div style="font-size:12px;color:#71808d;margin:5px 0 10px">選択した顧客が表示されます</div>'+
                    '<div class="selected-list" id="selectedList"><div class="empty" style="padding:20px">まだ選択されていません</div></div>'+
                '</div>'+
                '<div class="card" style="margin-top:15px">'+
                    '<div class="card-header"><h2>送信設定</h2></div>'+
                    '<div class="card-body">'+
                        '<p style="margin-top:0">送信元</p>'+
                        '<strong>アンケート事務局</strong><br>'+
                        '<span style="font-size:12px;color:#71808d">survey@example.jp</span>'+
                        '<hr style="border:0;border-top:1px solid #e3e8eb;margin:15px 0">'+
                        '<button class="btn btn-small" onclick="navigate(\'settings\')">メール送信設定を確認</button>'+
                    '</div>'+
                '</div>'+
            '</div>'+
        '</div>';

    document.getElementById('mainContent').innerHTML=html;
    updateSelectedCustomers();
}

function toggleCustomer(id,checked){
    if(checked){
        if(state.mailSelected.indexOf(id)<0) state.mailSelected.push(id);
    }else{
        state.mailSelected=state.mailSelected.filter(function(x){return x!==id;});
    }
    updateSelectedCustomers();
}

function selectAllCustomers(){
    state.mailSelected=customers.map(function(c){return c.id;});
    document.querySelectorAll('.customer-check').forEach(function(el){el.checked=true;});
    updateSelectedCustomers();
}

function clearCustomers(){
    state.mailSelected=[];
    document.querySelectorAll('.customer-check').forEach(function(el){el.checked=false;});
    updateSelectedCustomers();
}

function updateSelectedCustomers(){
    var count=document.getElementById('selectedCount');
    var list=document.getElementById('selectedList');
    if(count) count.textContent=state.mailSelected.length+'名選択';
    if(!list) return;

    if(!state.mailSelected.length){
        list.innerHTML='<div class="empty" style="padding:20px">まだ選択されていません</div>';
        return;
    }

    list.innerHTML=state.mailSelected.map(function(id){
        var c=customers.filter(function(x){return x.id===id;})[0];
        return '<div class="selected-item"><strong>'+esc(c.name)+'</strong><br><span style="font-size:12px;color:#71808d">'+esc(c.email)+'</span></div>';
    }).join('');
}

function confirmSendMail(){
    if(!state.mailSelected.length){
        showToast('送信対象者を選択してください');
        return;
    }

    showConfirm(
        'メールを送信しますか？',
        '選択した '+state.mailSelected.length+' 名にアンケート回答依頼メールを送信します。',
        function(){
            showToast('メールを送信しました（モック）');
        }
    );
}

function renderCustomers(){
    document.getElementById('mainContent').innerHTML=
        '<div class="page-title">'+
            '<div><h1>顧客一覧</h1><p>キントーンの顧客管理アプリから取得した顧客を想定しています</p></div>'+
            '<button class="btn" onclick="showToast(\'顧客一覧を更新しました（モック）\')">一覧を更新</button>'+
        '</div>'+
        '<div class="notice notice-info">この画面では、アンケートのメール送信対象者として選択できる顧客を確認します。</div>'+
        '<div class="card">'+
            '<div class="card-header"><h2>顧客一覧</h2><span>'+customers.length+'件</span></div>'+
            '<div class="card-body" style="padding:0">'+
                '<table><thead><tr><th>顧客名</th><th>メールアドレス</th><th>送信対象選択</th></tr></thead><tbody>'+
                customers.map(function(c){
                    return '<tr><td><strong>'+esc(c.name)+'</strong></td><td>'+esc(c.email)+'</td><td><button class="btn btn-small" onclick="navigate(\'list\')">アンケートを選択</button></td></tr>';
                }).join('')+
                '</tbody></table>'+
            '</div>'+
        '</div>'+
        '<div class="notice notice-warning">顧客一覧に登録されていない人からの回答も、アンケートの回答画面から受け付ける想定です。</div>';
}

function renderSettings(){
    document.getElementById('mainContent').innerHTML=
        '<div class="page-title">'+
            '<div><h1>メール送信設定</h1><p>アンケート回答依頼メールの送信設定</p></div>'+
            '<button class="btn btn-primary" onclick="saveSettings()">設定を保存</button>'+
        '</div>'+
        '<div class="card">'+
            '<div class="card-header"><h2>SMTP設定</h2></div>'+
            '<div class="card-body">'+
                '<div class="form-grid">'+
                    '<label>SMTPサーバ</label><input type="text" value="smtp.example.jp">'+
                    '<label>ポート番号</label><input type="number" value="587">'+
                    '<label>暗号化方式</label><select><option>STARTTLS</option><option>SSL/TLS</option><option>暗号化なし</option></select>'+
                    '<label>SMTP認証</label><div><label style="font-weight:normal;padding:0"><input type="checkbox" checked> 認証を使用する</label></div>'+
                    '<label>ユーザー名</label><input type="text" value="survey@example.jp">'+
                    '<label>パスワード</label><input type="password" value="********">'+
                    '<label>送信元メールアドレス</label><input type="email" value="survey@example.jp">'+
                    '<label>送信元名</label><input type="text" value="アンケート事務局">'+
                '</div>'+
            '</div>'+
        '</div>'+
        '<div class="card">'+
            '<div class="card-header"><h2>設定確認</h2></div>'+
            '<div class="card-body">'+
                '<p>現在の設定でメール送信を行えるか確認します。</p>'+
                '<button class="btn" onclick="showToast(\'メール送信設定を確認しました（モック）\')">設定を確認</button>'+
            '</div>'+
        '</div>';
}

function saveSettings(){
    showToast('メール送信設定を保存しました（モック）');
}

function closeSurvey(id){
    showConfirm(
        'アンケートを終了しますか？',
        '終了すると回答受付を終了します。',
        function(){
            var s=surveyById(id);
            s.status='closed';
            showToast('アンケートを終了しました');
            render();
        }
    );
}

function deleteSurvey(id){
    showConfirm(
        'アンケートを削除しますか？',
        '下書き「'+esc(surveyById(id).name)+'」を削除します。',
        function(){
            surveys=surveys.filter(function(s){return s.id!==id;});
            showToast('アンケートを削除しました');
            render();
        }
    );
}

function showConfirm(title,message,ok){
    document.getElementById('confirmTitle').textContent=title;
    document.getElementById('confirmMessage').innerHTML=message;
    document.getElementById('confirmModal').classList.remove('hidden');
    document.getElementById('confirmOk').onclick=function(){
        closeConfirm();
        ok();
    };
}

function closeConfirm(){
    document.getElementById('confirmModal').classList.add('hidden');
}

/* ---------------------------------------------------------
   回答者画面
   運営者用ヘッダー・メニュー・戻るボタンを表示しない
--------------------------------------------------------- */

function startAnswer(){
    state.page='answer';
    state.answerStep=1;
    render();
}

function renderAnswer(app){
    var s=surveys[0];

    var html='<div class="answer-page"><div class="answer-inner">';

    if(state.answerStep===1){
        html+=
            '<h1 class="answer-title">'+esc(s.name)+'</h1>'+
            '<div class="answer-desc">'+esc(s.description)+'</div>';

        s.groups.forEach(function(g){
            html+='<div class="answer-section"><h2>'+esc(g.name)+'</h2>';
            g.questions.forEach(function(q){
                html+=renderAnswerQuestion(q,s);
            });
            html+='</div>';
        });

        html+=
            '<div class="answer-footer">'+
                '<button class="btn btn-primary" onclick="answerPreview()">回答内容を確認する</button>'+
            '</div>';

    }else if(state.answerStep===2){
        html+=
            '<h1 class="answer-title">回答内容の確認</h1>'+
            '<div class="answer-desc">送信前に回答内容をご確認ください。</div>'+
            '<div class="send-confirm">';

        s.groups.forEach(function(g){
            html+='<h3>'+esc(g.name)+'</h3>';
            g.questions.forEach(function(q,i){
                html+='<div style="padding:10px 0;border-bottom:1px solid #e2e7ea">'+
                    '<strong>'+esc(q.text)+'</strong><br>'+
                    '<span style="color:#687782">回答：'+
                    (q.type==='single' ? esc(q.choices[0]) :
                     q.type==='rating' ? '4' :
                     q.type==='text' ? 'とても参考になりました。' :
                     q.type==='number' ? '5' : esc(q.choices[0]))+
                    '</span></div>';
            });
        });

        html+='</div>'+
            '<div class="answer-footer" style="justify-content:space-between">'+
                '<button class="btn" onclick="state.answerStep=1;render()">回答を修正する</button>'+
                '<button class="btn btn-primary" onclick="answerSubmit()">送信する</button>'+
            '</div>';

    }else{
        html+=
            '<div style="text-align:center;padding:90px 10px">'+
                '<div style="font-size:50px;color:#2b8b5c;margin-bottom:20px">✓</div>'+
                '<h1 class="answer-title">回答ありがとうございました</h1>'+
                '<p style="color:#687782">アンケートへの回答を正常に受け付けました。</p>'+
            '</div>';
    }

    html+='</div></div>';
    app.innerHTML=html;
}

function renderAnswerQuestion(q,s){
    var html='<div class="answer-q"><div class="answer-q-title">'+
        esc(q.text)+(q.required?'<span class="required">必須</span>':'')+
        '</div>';

    if(q.type==='single'){
        q.choices.forEach(function(c){
            html+='<label class="answer-option"><input type="radio" name="'+q.id+'"> '+esc(c)+'</label>';
        });
    }else if(q.type==='multi'){
        q.choices.forEach(function(c){
            html+='<label class="answer-option"><input type="checkbox"> '+esc(c)+'</label>';
        });
    }else if(q.type==='text'){
        html+='<textarea placeholder="回答を入力してください"></textarea>';
    }else if(q.type==='number'){
        html+='<input type="number" placeholder="数値を入力してください">';
    }else if(q.type==='rating'){
        html+='<div style="display:flex;gap:10px">'+q.choices.map(function(c){
            return '<label><input type="radio" name="'+q.id+'"> '+esc(c)+'</label>';
        }).join('')+'</div>';
    }

    return html+'</div>';
}

function answerPreview(){
    state.answerStep=2;
    render();
}

function answerSubmit(){
    state.answerStep=3;
    render();
}

/* 初期表示 */
render();

/*
 * モック確認用：
 * 回答者画面へ入るための操作をブラウザ上から確認できるよう、
 * アンケート一覧に小さなデモ導線を追加。
 */
setTimeout(function(){
    if(state.page==='list'){
        var main=document.getElementById('mainContent');
        if(main){
            var demo=document.createElement('div');
            demo.className='card';
            demo.innerHTML=
                '<div class="card-header"><h2>回答者画面の確認</h2></div>'+
                '<div class="card-body">'+
                '<p style="margin-top:0">運営者画面とは別の回答者画面を確認できます。</p>'+
                '<button class="btn" onclick="startAnswer()">回答者画面を開く</button>'+
                '</div>';
            main.appendChild(demo);
        }
    }
},50);
</script>
</body>
</html>

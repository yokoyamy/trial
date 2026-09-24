<?php
/*
 * アンケート業務運営アプリ モック
 * index.php 1ファイルで動作する画面確認用モック
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート業務運営</title>
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

.header{
    height:60px;
    background:#fff;
    border-bottom:1px solid #dfe3e8;
    display:flex;
    align-items:center;
    padding:0 24px;
    position:sticky;
    top:0;
    z-index:20;
}
.logo{
    font-size:19px;
    font-weight:700;
    color:#1f4f82;
    margin-right:35px;
    white-space:nowrap;
}
.main-nav{
    display:flex;
    height:100%;
    gap:4px;
}
.main-nav button{
    border:0;
    background:transparent;
    padding:0 18px;
    color:#52616b;
    border-bottom:3px solid transparent;
}
.main-nav button:hover{background:#f6f8fa}
.main-nav button.active{
    color:#1f4f82;
    font-weight:700;
    border-bottom-color:#2f75b5;
}

.app{
    max-width:1400px;
    margin:0 auto;
    padding:26px 28px 60px;
}

.page-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
}
.page-title h1{
    margin:0;
    font-size:25px;
}
.page-title p{
    margin:6px 0 0;
    color:#71808c;
}

.btn{
    border:1px solid #cbd3da;
    background:#fff;
    color:#34434e;
    border-radius:5px;
    padding:9px 16px;
    min-height:38px;
}
.btn:hover{background:#f5f7f9}
.btn-primary{
    background:#286fae;
    border-color:#286fae;
    color:#fff;
}
.btn-primary:hover{background:#205d93}
.btn-success{
    background:#32865a;
    border-color:#32865a;
    color:#fff;
}
.btn-danger{
    color:#b63a3a;
    border-color:#e3bcbc;
    background:#fff;
}
.btn-small{
    padding:6px 11px;
    min-height:32px;
    font-size:13px;
}

.card{
    background:#fff;
    border:1px solid #dfe4e8;
    border-radius:7px;
    box-shadow:0 1px 2px rgba(0,0,0,.03);
}
.card-body{padding:20px}

.toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:14px;
}
.toolbar-left,.toolbar-right{
    display:flex;
    align-items:center;
    gap:8px;
}

.table-wrap{overflow:auto}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    padding:13px 14px;
    border-bottom:1px solid #e8ecef;
    text-align:left;
    vertical-align:middle;
}
th{
    background:#f7f9fa;
    color:#596a76;
    font-size:13px;
    font-weight:600;
    white-space:nowrap;
}
tr:hover td{background:#fbfcfd}

.status{
    display:inline-block;
    border-radius:20px;
    padding:4px 10px;
    font-size:12px;
    white-space:nowrap;
}
.status-draft{background:#eef1f4;color:#5e6972}
.status-open{background:#e5f4eb;color:#26734a}
.status-end{background:#f2e8e8;color:#9a4b4b}

.link-btn{
    border:0;
    background:none;
    color:#246da6;
    padding:0;
}
.link-btn:hover{text-decoration:underline}

.empty{
    padding:55px 20px;
    text-align:center;
    color:#7b8993;
}

/* individual */
.sub-header{
    margin-bottom:20px;
}
.back-link{
    border:0;
    background:none;
    padding:0;
    color:#286fae;
    margin-bottom:10px;
}
.survey-heading{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
}
.survey-heading h1{margin:0 0 6px;font-size:25px}
.survey-heading p{margin:0;color:#74818b}

.tabs{
    display:flex;
    border-bottom:1px solid #d9dfe4;
    margin-bottom:20px;
}
.tabs button{
    border:0;
    border-bottom:3px solid transparent;
    background:transparent;
    padding:13px 20px;
    color:#687680;
}
.tabs button.active{
    color:#21669d;
    border-bottom-color:#286fae;
    font-weight:700;
}

/* editor */
.editor-actions{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:18px;
}
.editor-actions .actions{
    display:flex;
    gap:8px;
}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
.form-group{margin-bottom:15px}
.form-group.full{grid-column:1/-1}
label{
    display:block;
    font-weight:600;
    margin-bottom:7px;
    color:#485863;
}
.required{color:#c54a4a;margin-left:3px}
input[type=text],
input[type=date],
textarea,
select{
    width:100%;
    border:1px solid #cbd4da;
    border-radius:5px;
    padding:9px 11px;
    background:#fff;
    color:#263238;
}
textarea{resize:vertical;min-height:75px}
input:focus,textarea:focus,select:focus{
    outline:none;
    border-color:#5d9bc9;
    box-shadow:0 0 0 2px rgba(45,116,174,.1);
}

.section-title{
    font-size:17px;
    font-weight:700;
    margin:27px 0 12px;
    color:#354650;
}

.group-list{
    display:flex;
    flex-direction:column;
    gap:15px;
}
.group-card{
    border:1px solid #d6dde2;
    border-radius:7px;
    background:#fff;
}
.group-card.drag-over{
    border:2px dashed #3f82b7;
    background:#f3f8fc;
}
.group-head{
    display:flex;
    align-items:center;
    gap:9px;
    background:#f5f7f8;
    border-bottom:1px solid #dfe4e8;
    padding:12px 13px;
}
.drag-handle{
    color:#87949d;
    cursor:grab;
    font-size:18px;
    user-select:none;
}
.group-name{
    flex:1;
    font-weight:700;
}
.group-name input{
    width:100%;
    max-width:500px;
    padding:6px 9px;
    font-weight:700;
}
.group-actions{
    display:flex;
    gap:5px;
}

.questions{
    padding:12px;
    display:flex;
    flex-direction:column;
    gap:10px;
}
.question-card{
    border:1px solid #dce2e6;
    border-radius:6px;
    background:#fff;
    padding:14px;
}
.question-card.dragging{
    opacity:.45;
}
.question-card.drag-over{
    border:2px dashed #4c8bc0;
    background:#f3f8fc;
}
.question-top{
    display:flex;
    align-items:flex-start;
    gap:10px;
}
.question-number{
    min-width:48px;
    padding-top:9px;
    color:#356f9f;
    font-weight:700;
}
.question-main{flex:1}
.question-actions{
    display:flex;
    gap:4px;
}
.question-fields{
    display:grid;
    grid-template-columns:minmax(0,2fr) minmax(150px,1fr) auto;
    gap:10px;
    align-items:end;
}
.question-required{
    display:flex;
    align-items:center;
    gap:6px;
    height:38px;
    white-space:nowrap;
}
.question-required input{width:auto}
.options{
    margin-top:10px;
    padding:11px;
    background:#f8fafb;
    border-radius:5px;
    border:1px solid #e1e6e9;
}
.option-row{
    display:flex;
    gap:7px;
    margin-bottom:7px;
}
.option-row input{flex:1}
.option-delete{
    width:32px;
    border:1px solid #d5dce1;
    background:#fff;
    border-radius:4px;
    color:#a04c4c;
}
.add-option{
    border:0;
    background:none;
    color:#286fae;
    padding:4px 0;
}
.add-question{
    margin-top:4px;
    border:1px dashed #b9c8d2;
    color:#286fae;
    background:#fafcfd;
    width:100%;
    padding:9px;
    border-radius:5px;
}
.add-question:hover{background:#f0f6fa}
.add-group{
    margin-top:16px;
    width:100%;
    border:1px dashed #9eb8c9;
    background:#f9fbfc;
    color:#286fae;
    padding:12px;
    border-radius:6px;
}
.add-group:hover{background:#eff6fa}

.numbering{
    margin-top:16px;
    padding:13px 15px;
    background:#f7f9fa;
    border:1px solid #dfe5e9;
    border-radius:6px;
}
.radio-list{
    display:flex;
    gap:25px;
    margin-top:8px;
}
.radio-list label{
    font-weight:400;
    margin:0;
}

/* dashboard */
.metric-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px;
}
.metric{
    padding:18px;
}
.metric-label{color:#73818a;font-size:13px}
.metric-value{
    font-size:28px;
    font-weight:700;
    margin-top:6px;
    color:#24485f;
}
.metric-sub{color:#82909a;font-size:12px;margin-top:3px}
.dashboard-grid{
    display:grid;
    grid-template-columns:1.2fr .8fr;
    gap:18px;
}
.chart{
    height:250px;
    display:flex;
    align-items:flex-end;
    gap:13px;
    padding:20px 18px 35px;
}
.bar-wrap{
    flex:1;
    height:100%;
    display:flex;
    flex-direction:column;
    justify-content:flex-end;
    align-items:center;
}
.bar{
    width:75%;
    max-width:48px;
    background:#5b91bc;
    border-radius:4px 4px 0 0;
    min-height:5px;
}
.bar-label{font-size:11px;color:#788690;margin-top:6px}
.bar-value{font-size:11px;color:#52626c;margin-bottom:4px}

.result-row{
    display:flex;
    justify-content:space-between;
    margin:10px 0 6px;
}
.progress{
    height:9px;
    background:#edf0f2;
    border-radius:10px;
    overflow:hidden;
}
.progress span{
    display:block;
    height:100%;
    background:#5d96bd;
}
.answer-list{
    display:flex;
    flex-direction:column;
    gap:9px;
}
.answer-item{
    padding:12px;
    border:1px solid #e0e5e8;
    border-radius:5px;
    background:#fafbfc;
}

/* toast */
.toast{
    position:fixed;
    right:24px;
    bottom:24px;
    background:#273840;
    color:#fff;
    padding:12px 18px;
    border-radius:5px;
    box-shadow:0 5px 20px rgba(0,0,0,.18);
    display:none;
    z-index:100;
}
.toast.show{display:block}

/* modal */
.modal-bg{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(30,40,48,.45);
    align-items:center;
    justify-content:center;
    z-index:90;
}
.modal-bg.show{display:flex}
.modal{
    width:min(500px,calc(100% - 30px));
    background:#fff;
    border-radius:7px;
    box-shadow:0 15px 45px rgba(0,0,0,.2);
}
.modal-head{
    padding:16px 19px;
    border-bottom:1px solid #e1e5e8;
    font-weight:700;
}
.modal-body{padding:19px}
.modal-foot{
    padding:13px 19px;
    border-top:1px solid #e1e5e8;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

/* preview */
.preview-box{
    border:1px solid #dfe4e7;
    border-radius:6px;
    background:#f8f9fa;
    padding:20px;
}
.preview-group{
    background:#fff;
    border:1px solid #e0e5e8;
    border-radius:6px;
    margin-bottom:14px;
    padding:16px;
}
.preview-question{
    padding:12px 0;
    border-bottom:1px solid #edf0f2;
}
.preview-question:last-child{border-bottom:0}
.preview-q{
    font-weight:600;
    margin-bottom:9px;
}
.choice{margin:6px 0;color:#56656f}

/* responsive */
@media(max-width:900px){
    .metric-grid{grid-template-columns:repeat(2,1fr)}
    .dashboard-grid{grid-template-columns:1fr}
    .question-fields{grid-template-columns:1fr}
    .form-grid{grid-template-columns:1fr}
    .form-group.full{grid-column:auto}
}
@media(max-width:650px){
    .header{padding:0 12px;overflow:auto}
    .logo{margin-right:10px}
    .main-nav button{padding:0 9px}
    .app{padding:18px 12px 40px}
    .metric-grid{grid-template-columns:1fr 1fr}
    .page-title,.survey-heading{align-items:flex-start;gap:12px}
}
</style>
</head>

<body>

<header class="header">
    <div class="logo">アンケート業務運営</div>
    <nav class="main-nav">
        <button id="nav-list" onclick="showPage('list')">アンケート一覧</button>
        <button id="nav-create" onclick="openCreate()">アンケート作成</button>
    </nav>
</header>

<main class="app" id="app"></main>

<div class="toast" id="toast"></div>

<div class="modal-bg" id="confirmModal">
    <div class="modal">
        <div class="modal-head" id="modalTitle">確認</div>
        <div class="modal-body" id="modalMessage"></div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal()">キャンセル</button>
            <button class="btn-danger btn" id="modalOk">実行する</button>
        </div>
    </div>
</div>

<script>
(function(){

var surveys = [
    {
        id:1,
        name:"サービス満足度アンケート",
        description:"サービスをご利用いただいたお客様への満足度調査です。",
        status:"open",
        created:"2026/09/01",
        start:"2026-09-01",
        end:"2026-09-30",
        responses:128,
        target:180,
        updated:"2026/09/20",
        numbering:"global",
        groups:[
            {
                id:101,
                name:"基本情報",
                questions:[
                    {id:1001,text:"今回ご利用いただいたサービスを教えてください。",type:"single",required:true,options:["サービスA","サービスB","サービスC"]},
                    {id:1002,text:"ご利用頻度を教えてください。",type:"single",required:false,options:["初めて","月に1回程度","月に2〜3回","週1回以上"]}
                ]
            },
            {
                id:102,
                name:"サービスについて",
                questions:[
                    {id:1003,text:"満足した点を教えてください。",type:"multiple",required:false,options:["品質","価格","サポート","使いやすさ"]},
                    {id:1004,text:"サービスについてご意見があればご記入ください。",type:"text",required:false,options:[]}
                ]
            }
        ]
    },
    {
        id:2,
        name:"新商品に関するアンケート",
        description:"新商品の企画に関するアンケートです。",
        status:"draft",
        created:"2026/09/15",
        start:"2026-10-01",
        end:"2026-10-31",
        responses:0,
        target:0,
        updated:"2026/09/22",
        numbering:"group",
        groups:[
            {
                id:201,
                name:"新商品について",
                questions:[
                    {id:2001,text:"新商品のどの点に期待しますか？",type:"multiple",required:true,options:["価格","機能","デザイン","サポート"]}
                ]
            }
        ]
    },
    {
        id:3,
        name:"2026年度 顧客満足度調査",
        description:"年度末の顧客満足度調査です。",
        status:"end",
        created:"2026/03/01",
        start:"2026-03-01",
        end:"2026-03-31",
        responses:214,
        target:260,
        updated:"2026/04/02",
        numbering:"global",
        groups:[
            {
                id:301,
                name:"全体評価",
                questions:[
                    {id:3001,text:"総合的な満足度を教えてください。",type:"single",required:true,options:["満足","やや満足","やや不満","不満"]},
                    {id:3002,text:"今後も利用したいと思いますか？",type:"single",required:true,options:["はい","いいえ"]}
                ]
            }
        ]
    }
];

var editingSurvey = null;
var page = "list";
var selectedSurveyId = null;
var detailTab = "content";
var nextId = 10000;
var draggedQuestion = null;
var draggedGroup = null;

function clone(obj){
    return JSON.parse(JSON.stringify(obj));
}

function surveyById(id){
    for(var i=0;i<surveys.length;i++){
        if(surveys[i].id == id) return surveys[i];
    }
    return null;
}

function statusLabel(status){
    if(status==="open") return '<span class="status status-open">公開中</span>';
    if(status==="end") return '<span class="status status-end">終了</span>';
    return '<span class="status status-draft">下書き</span>';
}

function setNav(active){
    document.getElementById("nav-list").classList.toggle("active",active==="list");
    document.getElementById("nav-create").classList.toggle("active",active==="create");
}

function showPage(name){
    page=name;
    if(name==="list"){
        setNav("list");
        renderList();
    }
}

function renderList(){
    var html='';
    html += '<div class="page-title">';
    html += '<div><h1>アンケート一覧</h1><p>作成済みのアンケートを確認・編集します。</p></div>';
    html += '<button class="btn btn-primary" onclick="openCreate()">＋ アンケート作成</button>';
    html += '</div>';

    html += '<div class="card">';
    html += '<div class="card-body">';
    html += '<div class="toolbar"><div class="toolbar-left"><strong>'+surveys.length+'件</strong></div>';
    html += '<div class="toolbar-right"><button class="btn btn-small" onclick="showPage(\'list\')">一覧を更新</button></div></div>';
    html += '<div class="table-wrap"><table>';
    html += '<thead><tr><th>アンケート名</th><th>状態</th><th>作成日</th><th>公開期間</th><th>回答数</th><th>最終更新日</th><th>操作</th></tr></thead><tbody>';

    for(var i=0;i<surveys.length;i++){
        var s=surveys[i];
        html += '<tr>';
        html += '<td><button class="link-btn" onclick="openDetail('+s.id+')"><strong>'+esc(s.name)+'</strong></button></td>';
        html += '<td>'+statusLabel(s.status)+'</td>';
        html += '<td>'+s.created+'</td>';
        html += '<td>'+s.start.replace(/-/g,"/")+' ～ '+s.end.replace(/-/g,"/")+'</td>';
        html += '<td>'+s.responses+'件</td>';
        html += '<td>'+s.updated+'</td>';
        html += '<td><div style="display:flex;gap:5px;flex-wrap:wrap">';
        html += '<button class="btn btn-small" onclick="openEditor('+s.id+')">編集</button>';
        html += '<button class="btn btn-small" onclick="openDetail('+s.id+')">開く</button>';
        if(s.status==="open"){
            html += '<button class="btn btn-small btn-danger" onclick="finishSurvey('+s.id+')">終了</button>';
        }
        if(s.status==="draft"){
            html += '<button class="btn btn-small btn-danger" onclick="deleteSurvey('+s.id+')">削除</button>';
        }
        html += '</div></td>';
        html += '</tr>';
    }

    html += '</tbody></table></div></div></div>';
    document.getElementById("app").innerHTML=html;
}

function openCreate(){
    editingSurvey={
        id:null,
        name:"",
        description:"",
        status:"draft",
        created:"",
        start:"",
        end:"",
        responses:0,
        target:0,
        updated:"",
        numbering:"global",
        groups:[
            {
                id:nextId++,
                name:"基本情報",
                questions:[
                    {
                        id:nextId++,
                        text:"",
                        type:"single",
                        required:true,
                        options:[""]
                    }
                ]
            }
        ]
    };
    page="create";
    setNav("create");
    renderEditor();
}

function openEditor(id){
    var s=surveyById(id);
    if(!s)return;
    editingSurvey=clone(s);
    page="create";
    setNav("create");
    renderEditor();
}

function renderEditor(){
    var s=editingSurvey;
    var html='';

    html += '<div class="page-title">';
    html += '<div><h1>'+(s.id ? 'アンケート編集' : 'アンケート作成')+'</h1>';
    html += '<p>アンケート全体を1画面で確認しながら編集できます。</p></div>';
    html += '</div>';

    html += '<div class="editor-actions">';
    html += '<button class="btn" onclick="leaveEditor()">一覧へ戻る</button>';
    html += '<div class="actions">';
    html += '<button class="btn" onclick="previewSurvey()">内容を確認</button>';
    html += '<button class="btn btn-primary" onclick="saveSurvey()">保存</button>';
    html += '</div></div>';

    html += '<div class="card"><div class="card-body">';
    html += '<div class="form-grid">';

    html += '<div class="form-group full"><label>アンケート名<span class="required">*</span></label>';
    html += '<input type="text" id="survey-name" value="'+attr(s.name)+'" placeholder="例：サービス満足度アンケート" oninput="editingSurvey.name=this.value"></div>';

    html += '<div class="form-group full"><label>説明</label>';
    html += '<textarea id="survey-description" oninput="editingSurvey.description=this.value">'+esc(s.description)+'</textarea></div>';

    html += '<div class="form-group"><label>公開開始日</label>';
    html += '<input type="date" value="'+attr(s.start)+'" onchange="editingSurvey.start=this.value"></div>';

    html += '<div class="form-group"><label>公開終了日</label>';
    html += '<input type="date" value="'+attr(s.end)+'" onchange="editingSurvey.end=this.value"></div>';

    html += '</div>';

    html += '<div class="numbering"><strong>質問番号の形式</strong>';
    html += '<div class="radio-list">';
    html += '<label><input type="radio" name="numbering" value="global" '+(s.numbering==="global"?'checked':'')+' onchange="editingSurvey.numbering=this.value;renderEditor()"> 全体で通番（Q1、Q2、Q3…）</label>';
    html += '<label><input type="radio" name="numbering" value="group" '+(s.numbering==="group"?'checked':'')+' onchange="editingSurvey.numbering=this.value;renderEditor()"> ブロックごと（Q1-1、Q1-2、Q2-1…）</label>';
    html += '</div></div>';

    html += '<div class="section-title">質問グループ</div>';
    html += '<div class="group-list" id="group-list">';

    for(var gi=0;gi<s.groups.length;gi++){
        html += renderGroup(s.groups[gi],gi);
    }

    html += '</div>';
    html += '<button class="add-group" onclick="addGroup()">＋ グループ追加</button>';
    html += '</div></div>';

    document.getElementById("app").innerHTML=html;
}

function renderGroup(g,gi){
    var html='';
    html += '<div class="group-card" draggable="true" data-group="'+g.id+'"';
    html += ' ondragstart="groupDragStart(event,'+gi+')" ondragover="groupDragOver(event)" ondrop="groupDrop(event,'+gi+')" ondragend="groupDragEnd(event)">';
    html += '<div class="group-head">';
    html += '<span class="drag-handle" title="ドラッグして並べ替え">☷</span>';
    html += '<div class="group-name"><input type="text" value="'+attr(g.name)+'" onchange="editingSurvey.groups['+gi+'].name=this.value"></div>';
    html += '<div class="group-actions">';
    html += '<button class="btn btn-small btn-danger" onclick="removeGroup('+gi+')">グループ削除</button>';
    html += '</div></div>';

    html += '<div class="questions">';
    for(var qi=0;qi<g.questions.length;qi++){
        html += renderQuestion(g.questions[qi],gi,qi);
    }
    html += '<button class="add-question" onclick="addQuestion('+gi+')">＋ 質問追加</button>';
    html += '</div></div>';

    return html;
}

function renderQuestion(q,gi,qi){
    var number=getQuestionNumber(gi,qi);
    var html='';
    html += '<div class="question-card" draggable="true" data-question="'+q.id+'"';
    html += ' ondragstart="questionDragStart(event,'+gi+','+qi+')"';
    html += ' ondragover="questionDragOver(event)"';
    html += ' ondrop="questionDrop(event,'+gi+','+qi+')"';
    html += ' ondragend="questionDragEnd(event)">';

    html += '<div class="question-top">';
    html += '<div class="question-number">'+number+'</div>';
    html += '<div class="question-main">';
    html += '<div class="question-fields">';

    html += '<div><label>質問文</label>';
    html += '<input type="text" value="'+attr(q.text)+'" placeholder="質問を入力してください" onchange="updateQuestion('+gi+','+qi+',\'text\',this.value)"></div>';

    html += '<div><label>回答形式</label>';
    html += '<select onchange="updateQuestionType('+gi+','+qi+',this.value)">';
    html += '<option value="text" '+(q.type==="text"?'selected':'')+'>自由記述</option>';
    html += '<option value="single" '+(q.type==="single"?'selected':'')+'>単一選択</option>';
    html += '<option value="multiple" '+(q.type==="multiple"?'selected':'')+'>複数選択</option>';
    html += '</select></div>';

    html += '<div class="question-required"><label style="margin:0"><input type="checkbox" '+(q.required?'checked':'')+' onchange="updateQuestion('+gi+','+qi+',\'required\',this.checked)"> 必須回答</label></div>';

    html += '</div>';

    if(q.type==="single" || q.type==="multiple"){
        html += '<div class="options"><label>回答選択肢</label>';
        for(var oi=0;oi<q.options.length;oi++){
            html += '<div class="option-row">';
            html += '<input type="text" value="'+attr(q.options[oi])+'" onchange="updateOption('+gi+','+qi+','+oi+',this.value)" placeholder="選択肢">';
            html += '<button class="option-delete" onclick="removeOption('+gi+','+qi+','+oi+')">×</button>';
            html += '</div>';
        }
        html += '<button class="add-option" onclick="addOption('+gi+','+qi+')">＋ 選択肢を追加</button>';
        html += '</div>';
    }

    html += '</div>';
    html += '<div class="question-actions">';
    html += '<span class="drag-handle" title="ドラッグして並べ替え">☷</span>';
    html += '<button class="btn btn-small btn-danger" onclick="removeQuestion('+gi+','+qi+')">削除</button>';
    html += '</div>';
    html += '</div></div>';

    return html;
}

function getQuestionNumber(gi,qi){
    if(editingSurvey.numbering==="group"){
        return "Q"+(gi+1)+"-"+(qi+1);
    }
    var n=0;
    for(var i=0;i<gi;i++) n+=editingSurvey.groups[i].questions.length;
    n+=qi+1;
    return "Q"+n;
}

function updateQuestion(gi,qi,key,value){
    editingSurvey.groups[gi].questions[qi][key]=value;
}

function updateQuestionType(gi,qi,value){
    var q=editingSurvey.groups[gi].questions[qi];
    q.type=value;
    if((value==="single" || value==="multiple") && (!q.options || !q.options.length)){
        q.options=[""];
    }
    if(value==="text")q.options=[];
    renderEditor();
}

function updateOption(gi,qi,oi,value){
    editingSurvey.groups[gi].questions[qi].options[oi]=value;
}

function addOption(gi,qi){
    editingSurvey.groups[gi].questions[qi].options.push("");
    renderEditor();
}

function removeOption(gi,qi,oi){
    var options=editingSurvey.groups[gi].questions[qi].options;
    if(options.length<=1){
        showToast("選択肢は1つ以上必要です。");
        return;
    }
    options.splice(oi,1);
    renderEditor();
}

function addQuestion(gi){
    editingSurvey.groups[gi].questions.push({
        id:nextId++,
        text:"",
        type:"single",
        required:false,
        options:[""]
    });
    renderEditor();
    showToast("質問を追加しました。");
}

function removeQuestion(gi,qi){
    confirmAction(
        "質問を削除しますか？",
        "この質問を削除します。削除後は質問番号が自動的に整理されます。",
        function(){
            editingSurvey.groups[gi].questions.splice(qi,1);
            renderEditor();
            showToast("質問を削除しました。");
        }
    );
}

function addGroup(){
    editingSurvey.groups.push({
        id:nextId++,
        name:"新しいグループ",
        questions:[]
    });
    renderEditor();
    showToast("グループを追加しました。");
}

function removeGroup(gi){
    var g=editingSurvey.groups[gi];
    var message="グループ「"+g.name+"」を削除します。";
    if(g.questions.length){
        message+="\nこのグループに含まれる"+g.questions.length+"件の質問も削除されます。";
    }
    confirmAction("グループを削除しますか？",message,function(){
        editingSurvey.groups.splice(gi,1);
        renderEditor();
        showToast("グループを削除しました。");
    });
}

/* 質問ドラッグ */
function questionDragStart(e,gi,qi){
    draggedQuestion={gi:gi,qi:qi};
    e.currentTarget.classList.add("dragging");
    e.dataTransfer.effectAllowed="move";
}
function questionDragOver(e){
    e.preventDefault();
    e.dataTransfer.dropEffect="move";
    e.currentTarget.classList.add("drag-over");
}
function questionDrop(e,targetGi,targetQi){
    e.preventDefault();
    e.stopPropagation();
    clearDragStyles();
    if(!draggedQuestion)return;

    var from=draggedQuestion;
    if(from.gi!==targetGi){
        showToast("質問は同じグループ内でのみ並べ替えできます。");
        draggedQuestion=null;
        return;
    }
    if(from.qi===targetQi){
        draggedQuestion=null;
        return;
    }

    var list=editingSurvey.groups[from.gi].questions;
    var item=list.splice(from.qi,1)[0];
    list.splice(targetQi,0,item);
    draggedQuestion=null;
    renderEditor();
    showToast("質問の順番を変更しました。");
}
function questionDragEnd(){
    clearDragStyles();
    draggedQuestion=null;
}

/* グループドラッグ */
function groupDragStart(e,gi){
    draggedGroup={gi:gi};
    e.currentTarget.style.opacity=".45";
    e.dataTransfer.effectAllowed="move";
}
function groupDragOver(e){
    e.preventDefault();
    e.dataTransfer.dropEffect="move";
    e.currentTarget.classList.add("drag-over");
}
function groupDrop(e,targetGi){
    e.preventDefault();
    e.stopPropagation();
    clearDragStyles();
    if(!draggedGroup)return;

    var from=draggedGroup.gi;
    if(from===targetGi){
        draggedGroup=null;
        return;
    }
    var item=editingSurvey.groups.splice(from,1)[0];
    editingSurvey.groups.splice(targetGi,0,item);
    draggedGroup=null;
    renderEditor();
    showToast("グループの順番を変更しました。");
}
function groupDragEnd(){
    clearDragStyles();
    draggedGroup=null;
}
function clearDragStyles(){
    var cards=document.querySelectorAll(".question-card,.group-card");
    for(var i=0;i<cards.length;i++){
        cards[i].classList.remove("drag-over");
        cards[i].classList.remove("dragging");
        cards[i].style.opacity="";
    }
}

function saveSurvey(){
    var s=editingSurvey;
    if(!s.name.trim()){
        showToast("アンケート名を入力してください。");
        var el=document.getElementById("survey-name");
        if(el)el.focus();
        return;
    }

    for(var gi=0;gi<s.groups.length;gi++){
        if(!s.groups[gi].name.trim()){
            showToast("グループ名を入力してください。");
            return;
        }
        for(var qi=0;qi<s.groups[gi].questions.length;qi++){
            var q=s.groups[gi].questions[qi];
            if(!q.text.trim()){
                showToast(getQuestionNumber(gi,qi)+"の質問文を入力してください。");
                return;
            }
            if((q.type==="single" || q.type==="multiple") && q.options.length===0){
                showToast(getQuestionNumber(gi,qi)+"の選択肢を設定してください。");
                return;
            }
        }
    }

    if(s.id){
        var old=surveyById(s.id);
        Object.assign(old,clone(s));
        old.updated="2026/09/24";
    }else{
        s.id=nextId++;
        s.created="2026/09/24";
        s.updated="2026/09/24";
        surveys.unshift(clone(s));
    }

    editingSurvey=clone(s);
    showToast("保存しました。");
}

function leaveEditor(){
    showPage("list");
}

function openDetail(id){
    selectedSurveyId=id;
    detailTab="content";
    page="detail";
    setNav("");
    renderDetail();
}

function renderDetail(){
    var s=surveyById(selectedSurveyId);
    if(!s){showPage("list");return;}

    var html='';
    html += '<div class="sub-header">';
    html += '<button class="back-link" onclick="showPage(\'list\')">← アンケート一覧に戻る</button>';
    html += '<div class="survey-heading">';
    html += '<div><h1>'+esc(s.name)+'</h1><p>'+esc(s.description)+'</p></div>';
    html += '<div style="display:flex;gap:7px">';
    html += '<button class="btn" onclick="openEditor('+s.id+')">編集</button>';
    if(s.status==="draft"){
        html += '<button class="btn btn-success" onclick="publishSurvey('+s.id+')">公開する</button>';
    }
    if(s.status==="open"){
        html += '<button class="btn btn-danger" onclick="finishSurvey('+s.id+')">終了する</button>';
    }
    html += '</div></div></div>';

    html += '<div class="tabs">';
    html += '<button class="'+(detailTab==="content"?'active':'')+'" onclick="detailTab=\'content\';renderDetail()">アンケート内容</button>';
    html += '<button class="'+(detailTab==="status"?'active':'')+'" onclick="detailTab=\'status\';renderDetail()">回答状況</button>';
    html += '<button class="'+(detailTab==="result"?'active':'')+'" onclick="detailTab=\'result\';renderDetail()">回答結果</button>';
    html += '</div>';

    if(detailTab==="content")html+=renderContent(s);
    if(detailTab==="status")html+=renderStatus(s);
    if(detailTab==="result")html+=renderResults(s);

    document.getElementById("app").innerHTML=html;
}

function renderContent(s){
    var html='';
    html += '<div class="card"><div class="card-body">';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:18px">';
    html += '<div><strong>アンケート内容</strong><div style="color:#77858e;margin-top:5px">'+statusLabel(s.status)+'　公開期間：'+s.start.replace(/-/g,"/")+' ～ '+s.end.replace(/-/g,"/")+'</div></div>';
    html += '<button class="btn" onclick="openEditor('+s.id+')">編集</button></div>';

    for(var gi=0;gi<s.groups.length;gi++){
        var g=s.groups[gi];
        html += '<div class="preview-group">';
        html += '<h3 style="margin:0 0 8px">'+esc(g.name)+'</h3>';
        for(var qi=0;qi<g.questions.length;qi++){
            var q=g.questions[qi];
            html += '<div class="preview-question">';
            html += '<div class="preview-q">'+getNumberForSurvey(s,gi,qi)+' '+esc(q.text)+' '+(q.required?'<span class="required">*</span>':'')+'</div>';
            if(q.type==="text"){
                html += '<div style="color:#89959d;border:1px solid #e0e5e8;border-radius:4px;padding:9px">回答欄</div>';
            }else{
                for(var oi=0;oi<q.options.length;oi++){
                    html += '<div class="choice">'+(q.type==="single"?'○':'□')+' '+esc(q.options[oi])+'</div>';
                }
            }
            html += '</div>';
        }
        html += '</div>';
    }

    html += '</div></div>';
    return html;
}

function getNumberForSurvey(s,gi,qi){
    if(s.numbering==="group")return "Q"+(gi+1)+"-"+(qi+1);
    var n=0;
    for(var i=0;i<gi;i++)n+=s.groups[i].questions.length;
    return "Q"+(n+qi+1);
}

function renderStatus(s){
    var rate=s.target ? Math.round(s.responses/s.target*100) : 0;
    var html='';
    html += '<div class="metric-grid">';
    html += metric("回答数",s.responses+"件","現在までの回答");
    html += metric("回答率",rate+"%","対象者に対する回答率");
    html += metric("未回答数",Math.max(0,s.target-s.responses)+"件","回答対象者");
    html += metric("公開期間",s.status==="open"?"公開中":s.status==="end"?"終了":"未公開",s.start.replace(/-/g,"/")+" ～ "+s.end.replace(/-/g,"/"));
    html += '</div>';

    html += '<div class="dashboard-grid">';
    html += '<div class="card"><div class="card-body">';
    html += '<h3 style="margin:0">回答状況の推移</h3>';
    html += '<div class="chart">';
    var vals=[12,18,23,31,27,17];
    var labels=["9/15","9/16","9/17","9/18","9/19","9/20"];
    for(var i=0;i<vals.length;i++){
        html += '<div class="bar-wrap"><div class="bar-value">'+vals[i]+'</div><div class="bar" style="height:'+(vals[i]*5)+'px"></div><div class="bar-label">'+labels[i]+'</div></div>';
    }
    html += '</div></div></div>';

    html += '<div class="card"><div class="card-body">';
    html += '<h3 style="margin:0 0 16px">回答状況</h3>';
    html += '<div class="result-row"><span>回答済み</span><strong>'+s.responses+'件</strong></div>';
    html += '<div class="progress"><span style="width:'+Math.min(rate,100)+'%"></span></div>';
    html += '<div class="result-row"><span>未回答</span><strong>'+Math.max(0,s.target-s.responses)+'件</strong></div>';
    html += '<div class="progress"><span style="width:'+(100-Math.min(rate,100))+'%"></span></div>';
    html += '<p style="color:#77858e;margin-top:20px">公開期間：'+s.start.replace(/-/g,"/")+' ～ '+s.end.replace(/-/g,"/")+'</p>';
    html += '</div></div></div>';

    return html;
}

function metric(label,value,sub){
    return '<div class="card metric"><div class="metric-label">'+label+'</div><div class="metric-value">'+value+'</div><div class="metric-sub">'+sub+'</div></div>';
}

function renderResults(s){
    var html='';
    html += '<div class="card"><div class="card-body">';
    html += '<h3 style="margin-top:0">質問ごとの回答結果</h3>';
    html += '<p style="color:#77858e">各質問の回答数・割合、および自由記述の回答内容を確認できます。</p>';

    var sampleAnswers=[
        ["サービスA",58],["サービスB",43],["サービスC",27]
    ];

    for(var gi=0;gi<s.groups.length;gi++){
        var g=s.groups[gi];
        html += '<div class="section-title">'+esc(g.name)+'</div>';

        for(var qi=0;qi<g.questions.length;qi++){
            var q=g.questions[qi];
            html += '<div style="border:1px solid #e0e5e8;border-radius:6px;padding:16px;margin-bottom:12px">';
            html += '<div style="font-weight:700;margin-bottom:13px">'+getNumberForSurvey(s,gi,qi)+' '+esc(q.text)+'</div>';

            if(q.type==="text"){
                html += '<div class="answer-list">';
                html += '<div class="answer-item">とても使いやすく、満足しています。</div>';
                html += '<div class="answer-item">サポートの対応がよかったです。</div>';
                html += '<div class="answer-item">もう少し料金体系を分かりやすくしてほしいです。</div>';
                html += '</div>';
            }else{
                for(var oi=0;oi<q.options.length;oi++){
                    var count=Math.max(2,Math.round((q.options.length-oi)*23));
                    var pct=Math.round(count/s.responses*100);
                    html += '<div class="result-row"><span>'+esc(q.options[oi])+'</span><strong>'+count+'件（'+pct+'%）</strong></div>';
                    html += '<div class="progress" style="margin-bottom:11px"><span style="width:'+Math.min(pct,100)+'%"></span></div>';
                }
            }
            html += '</div>';
        }
    }

    html += '</div></div>';
    return html;
}

function previewSurvey(){
    var s=editingSurvey;
    var html='';
    html += '<div class="modal-bg show" id="previewModal"><div class="modal" style="width:min(850px,calc(100% - 30px));max-height:90vh;overflow:auto">';
    html += '<div class="modal-head">アンケート内容の確認</div>';
    html += '<div class="modal-body">';
    html += '<h2 style="margin-top:0">'+esc(s.name||"未入力")+'</h2>';
    html += '<p style="color:#73818a">'+esc(s.description)+'</p>';
    html += '<div class="preview-box">';

    for(var gi=0;gi<s.groups.length;gi++){
        html += '<div class="preview-group"><h3 style="margin-top:0">'+esc(s.groups[gi].name)+'</h3>';
        for(var qi=0;qi<s.groups[gi].questions.length;qi++){
            var q=s.groups[gi].questions[qi];
            html += '<div class="preview-question">';
            html += '<div class="preview-q">'+getQuestionNumber(gi,qi)+' '+esc(q.text||"未入力")+'</div>';
            if(q.type==="text"){
                html += '<div style="border:1px solid #d8dfe3;padding:10px;color:#89959d">自由記述欄</div>';
            }else{
                for(var oi=0;oi<q.options.length;oi++){
                    html += '<div class="choice">'+(q.type==="single"?'○':'□')+' '+esc(q.options[oi]||"未入力")+'</div>';
                }
            }
            html += '</div>';
        }
        html += '</div>';
    }

    html += '</div></div>';
    html += '<div class="modal-foot"><button class="btn" onclick="closePreview()">閉じる</button></div>';
    html += '</div></div>';

    document.body.insertAdjacentHTML("beforeend",html);
}

function closePreview(){
    var el=document.getElementById("previewModal");
    if(el)el.remove();
}

function publishSurvey(id){
    confirmAction("アンケートを公開しますか？","公開すると回答を受け付ける状態になります。",function(){
        var s=surveyById(id);
        if(s){
            s.status="open";
            s.updated="2026/09/24";
            renderDetail();
            showToast("アンケートを公開しました。");
        }
    });
}

function finishSurvey(id){
    confirmAction("アンケートを終了しますか？","終了すると回答受付を終了します。",function(){
        var s=surveyById(id);
        if(s){
            s.status="end";
            s.updated="2026/09/24";
            if(page==="list")renderList();
            else renderDetail();
            showToast("アンケートを終了しました。");
        }
    });
}

function deleteSurvey(id){
    confirmAction("下書きを削除しますか？","この下書きを削除します。",function(){
        for(var i=0;i<surveys.length;i++){
            if(surveys[i].id===id){
                surveys.splice(i,1);
                break;
            }
        }
        renderList();
        showToast("下書きを削除しました。");
    });
}

function confirmAction(title,message,callback){
    document.getElementById("modalTitle").innerText=title;
    document.getElementById("modalMessage").innerHTML=esc(message).replace(/\n/g,"<br>");
    document.getElementById("confirmModal").classList.add("show");
    document.getElementById("modalOk").onclick=function(){
        closeModal();
        callback();
    };
}

function closeModal(){
    document.getElementById("confirmModal").classList.remove("show");
}

function showToast(message){
    var el=document.getElementById("toast");
    el.innerText=message;
    el.classList.add("show");
    clearTimeout(window.toastTimer);
    window.toastTimer=setTimeout(function(){
        el.classList.remove("show");
    },2200);
}

function esc(value){
    return String(value==null?"":value)
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;")
        .replace(/'/g,"&#39;");
}
function attr(value){
    return esc(value);
}

window.showPage=showPage;
window.openCreate=openCreate;
window.openEditor=openEditor;
window.openDetail=openDetail;
window.renderDetail=renderDetail;
window.detailTab=detailTab;
window.saveSurvey=saveSurvey;
window.leaveEditor=leaveEditor;
window.previewSurvey=previewSurvey;
window.closePreview=closePreview;
window.addGroup=addGroup;
window.removeGroup=removeGroup;
window.addQuestion=addQuestion;
window.removeQuestion=removeQuestion;
window.addOption=addOption;
window.removeOption=removeOption;
window.updateQuestion=updateQuestion;
window.updateQuestionType=updateQuestionType;
window.updateOption=updateOption;
window.publishSurvey=publishSurvey;
window.finishSurvey=finishSurvey;
window.deleteSurvey=deleteSurvey;
window.closeModal=closeModal;
window.questionDragStart=questionDragStart;
window.questionDragOver=questionDragOver;
window.questionDrop=questionDrop;
window.questionDragEnd=questionDragEnd;
window.groupDragStart=groupDragStart;
window.groupDragOver=groupDragOver;
window.groupDrop=groupDrop;
window.groupDragEnd=groupDragEnd;

showPage("list");

})();
</script>
</body>
</html>

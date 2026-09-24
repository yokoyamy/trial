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
<title>アンケート業務運営</title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif;
    color:#263238;
    background:#f4f6f8;
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.topbar{
    height:58px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px;
}
.logo{
    font-size:18px;
    font-weight:bold;
    white-space:nowrap;
}
.main-nav{
    display:flex;
    height:100%;
    align-items:center;
    gap:4px;
}
.main-nav button{
    height:100%;
    padding:0 18px;
    color:#dce7f3;
    background:transparent;
    border:0;
}
.main-nav button:hover,
.main-nav button.active{
    background:#31557f;
    color:#fff;
}
.app{
    max-width:1400px;
    margin:0 auto;
    padding:24px;
}
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
}
.page-header h1{
    margin:0;
    font-size:25px;
}
.subtext{
    color:#718096;
    font-size:13px;
    margin-top:5px;
}
.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px;
}
.btn:hover{background:#f7fafc}
.btn-primary{
    background:#2878c8;
    border-color:#2878c8;
    color:#fff;
}
.btn-primary:hover{background:#2068ad}
.btn-danger{
    background:#fff;
    border-color:#e05a5a;
    color:#c53f3f;
}
.btn-small{
    padding:5px 10px;
    font-size:12px;
}
.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    box-shadow:0 1px 2px rgba(0,0,0,.04);
}
.table{
    width:100%;
    border-collapse:collapse;
}
.table th,
.table td{
    padding:13px 14px;
    border-bottom:1px solid #e8edf1;
    text-align:left;
    vertical-align:middle;
}
.table th{
    background:#f8fafc;
    color:#52606d;
    font-size:13px;
}
.table tr:last-child td{border-bottom:0}
.link-button{
    border:0;
    background:none;
    padding:0;
    color:#2878c8;
    cursor:pointer;
    text-align:left;
}
.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:20px;
    font-size:12px;
}
.badge-draft{background:#edf2f7;color:#536274}
.badge-open{background:#e5f7ed;color:#18794e}
.badge-end{background:#f2f2f2;color:#777}
.empty{
    text-align:center;
    padding:50px 20px;
    color:#718096;
}
.hidden{display:none!important}

/* editor */
.editor-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:15px;
}
.editor-actions{
    display:flex;
    gap:8px;
}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:15px;
}
.field{margin-bottom:15px}
.field label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:6px;
}
.field input,
.field textarea,
.field select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:9px 10px;
    background:#fff;
}
.field textarea{
    min-height:80px;
    resize:vertical;
}
.radio-row{
    display:flex;
    gap:20px;
    align-items:center;
    padding-top:5px;
}
.editor-card{
    padding:20px;
    margin-bottom:18px;
}
.group-card{
    border:1px solid #cfd8e3;
    border-radius:7px;
    background:#fff;
    margin-bottom:18px;
}
.group-header{
    background:#f5f8fb;
    border-bottom:1px solid #dfe6ee;
    padding:12px 14px;
    display:flex;
    align-items:center;
    gap:10px;
}
.drag-handle{
    color:#8796a5;
    cursor:grab;
    font-size:18px;
}
.group-title{
    flex:1;
}
.group-title input{
    width:100%;
    border:1px solid transparent;
    background:transparent;
    font-weight:bold;
    padding:5px;
}
.group-title input:focus{
    background:#fff;
    border-color:#b9c7d5;
}
.group-actions{
    display:flex;
    gap:6px;
}
.questions{
    padding:14px;
    min-height:20px;
}
.question-card{
    border:1px solid #dce3ea;
    border-radius:6px;
    padding:15px;
    margin-bottom:10px;
    background:#fff;
}
.question-card.dragging{
    opacity:.45;
}
.question-head{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:12px;
}
.question-number{
    font-weight:bold;
    color:#2878c8;
    min-width:55px;
}
.question-title{
    flex:1;
}
.question-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px;
}
.question-tools{
    display:flex;
    gap:5px;
}
.question-options{
    margin-top:12px;
    padding-left:65px;
}
.option-row{
    display:flex;
    align-items:center;
    gap:7px;
    margin-bottom:7px;
}
.option-row input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px;
}
.branch-select{
    width:190px!important;
    flex:none;
}
.question-meta{
    display:flex;
    gap:15px;
    align-items:center;
    margin-top:10px;
    padding-left:65px;
    color:#657786;
    font-size:13px;
}
.add-question-area{
    padding:0 14px 14px;
}
.add-group-area{
    text-align:center;
    margin-top:8px;
}
.notice{
    padding:11px 13px;
    border-radius:5px;
    background:#edf6ff;
    border:1px solid #c9e2fa;
    color:#2b5f8a;
    font-size:13px;
    margin-bottom:15px;
}

/* detail */
.detail-tabs{
    display:flex;
    border-bottom:1px solid #dfe5eb;
    margin-bottom:18px;
}
.detail-tabs button{
    border:0;
    background:transparent;
    padding:12px 20px;
    color:#687887;
    border-bottom:3px solid transparent;
}
.detail-tabs button.active{
    color:#2878c8;
    border-bottom-color:#2878c8;
}
.detail-content{min-height:300px}
.detail-summary{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px;
}
.stat-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:17px;
}
.stat-label{
    color:#718096;
    font-size:12px;
}
.stat-value{
    font-size:27px;
    font-weight:bold;
    margin-top:5px;
}
.result-item{
    padding:18px;
    border-bottom:1px solid #e6ebef;
}
.result-item:last-child{border-bottom:0}
.bar{
    height:9px;
    background:#e8edf2;
    border-radius:5px;
    overflow:hidden;
    margin-top:6px;
}
.bar span{
    display:block;
    height:100%;
    background:#4285c5;
}
.preview-question{
    padding:15px 0;
    border-bottom:1px solid #e6ebef;
}
.preview-question:last-child{border-bottom:0}
.preview-question-title{
    font-weight:bold;
    margin-bottom:9px;
}
.preview-option{
    margin:6px 0;
    color:#52606d;
}

/* toast */
.toast{
    position:fixed;
    right:25px;
    bottom:25px;
    background:#263238;
    color:#fff;
    padding:12px 18px;
    border-radius:5px;
    box-shadow:0 5px 20px rgba(0,0,0,.2);
    opacity:0;
    transform:translateY(10px);
    transition:.2s;
    pointer-events:none;
    z-index:1000;
}
.toast.show{
    opacity:1;
    transform:translateY(0);
}

@media(max-width:800px){
    .topbar{padding:0 10px;gap:10px}
    .main-nav button{padding:0 9px}
    .app{padding:14px}
    .form-grid{grid-template-columns:1fr}
    .detail-summary{grid-template-columns:1fr 1fr}
    .question-options,.question-meta{padding-left:0}
    .question-head{align-items:flex-start}
}
</style>
</head>
<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>
    <nav class="main-nav">
        <button id="nav-list" onclick="showList()">アンケート一覧</button>
        <button id="nav-create" onclick="openCreate()">アンケート作成</button>
    </nav>
</header>

<main class="app">

    <!-- 一覧 -->
    <section id="page-list">
        <div class="page-header">
            <div>
                <h1>アンケート一覧</h1>
                <div class="subtext">作成済みのアンケートを管理します</div>
            </div>
            <button class="btn btn-primary" onclick="openCreate()">＋ アンケート作成</button>
        </div>

        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>アンケート名</th>
                        <th>状態</th>
                        <th>公開期間</th>
                        <th>回答数</th>
                        <th>最終更新</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="survey-list-body"></tbody>
            </table>
        </div>
    </section>

    <!-- 作成・編集 -->
    <section id="page-editor" class="hidden">
        <div class="page-header">
            <div>
                <h1 id="editor-page-title">アンケート作成</h1>
                <div class="subtext">アンケート全体を1画面で編集できます</div>
            </div>
        </div>

        <div class="notice">
            質問はドラッグ＆ドロップで並べ替えできます。質問番号は設定した方式に応じて自動更新されます。
        </div>

        <div class="card editor-card">
            <div class="form-grid">
                <div class="field">
                    <label>アンケート名 *</label>
                    <input id="survey-name" type="text" value="">
                </div>
                <div class="field">
                    <label>公開状態</label>
                    <select id="survey-status">
                        <option value="draft">下書き</option>
                        <option value="open">公開中</option>
                        <option value="end">終了</option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label>説明</label>
                <textarea id="survey-description"></textarea>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label>公開開始日</label>
                    <input id="survey-start" type="date">
                </div>
                <div class="field">
                    <label>公開終了日</label>
                    <input id="survey-end" type="date">
                </div>
            </div>

            <div class="field">
                <label>質問番号</label>
                <div class="radio-row">
                    <label>
                        <input type="radio" name="numbering" value="global" checked onchange="changeNumbering(this.value)">
                        全体で通番（Q1、Q2、Q3…）
                    </label>
                    <label>
                        <input type="radio" name="numbering" value="group" onchange="changeNumbering(this.value)">
                        グループごと（Q1-1、Q1-2、Q2-1…）
                    </label>
                </div>
            </div>
        </div>

        <div id="groups"></div>

        <div class="add-group-area">
            <button class="btn btn-primary" onclick="addGroup()">＋ グループ追加</button>
        </div>

        <div class="editor-toolbar" style="margin-top:20px">
            <button class="btn" onclick="showList()">一覧へ戻る</button>
            <div class="editor-actions">
                <button class="btn btn-primary" onclick="saveSurvey()">保存</button>
            </div>
        </div>
    </section>

    <!-- 個別アンケート -->
    <section id="page-detail" class="hidden">
        <div class="page-header">
            <div>
                <h1 id="detail-title"></h1>
                <div class="subtext" id="detail-subtitle"></div>
            </div>
            <div>
                <button class="btn" onclick="editCurrentSurvey()">編集</button>
                <button class="btn" onclick="showList()">一覧へ戻る</button>
            </div>
        </div>

        <div class="detail-tabs">
            <button id="tab-content" onclick="showDetailTab('content')">アンケート内容</button>
            <button id="tab-status" onclick="showDetailTab('status')">回答状況</button>
            <button id="tab-result" onclick="showDetailTab('result')">回答結果</button>
        </div>

        <div id="detail-content" class="detail-content"></div>
    </section>

</main>

<div id="toast" class="toast"></div>

<script>
var surveys = [
    {
        id: 1,
        name: '新商品アンケート',
        description: '新商品の利用状況とご意見をお聞きするアンケートです。',
        status: 'open',
        start: '2026-09-01',
        end: '2026-09-30',
        answers: 128,
        target: 200,
        updated: '2026-09-20',
        numbering: 'global',
        groups: [
            {
                id: 101,
                name: 'ご利用状況',
                questions: [
                    {
                        id: 1001,
                        text: '当社の商品を利用したことがありますか？',
                        type: 'single',
                        required: true,
                        options: [
                            {text:'はい', branch:''},
                            {text:'いいえ', branch:'1003'}
                        ]
                    },
                    {
                        id: 1002,
                        text: '商品についての満足度を教えてください。',
                        type: 'single',
                        required: true,
                        options: [
                            {text:'満足', branch:''},
                            {text:'普通', branch:''},
                            {text:'不満', branch:''}
                        ]
                    }
                ]
            },
            {
                id: 102,
                name: 'ご意見',
                questions: [
                    {
                        id: 1003,
                        text: '今後の商品についてご意見をお聞かせください。',
                        type: 'free',
                        required: false,
                        options: []
                    }
                ]
            }
        ]
    },
    {
        id: 2,
        name: 'サービス利用後アンケート',
        description: 'サービスをご利用いただいた感想をお聞きします。',
        status: 'draft',
        start: '',
        end: '',
        answers: 0,
        target: 0,
        updated: '2026-09-21',
        numbering: 'group',
        groups: [
            {
                id: 201,
                name: 'サービスについて',
                questions: [
                    {
                        id: 2001,
                        text: 'サービスについての感想を教えてください。',
                        type: 'multiple',
                        required: false,
                        options: [
                            {text:'便利だった', branch:''},
                            {text:'分かりやすかった', branch:''},
                            {text:'また利用したい', branch:''}
                        ]
                    }
                ]
            }
        ]
    }
];

var editingSurvey = null;
var currentSurveyId = null;
var nextGroupId = 500;
var nextQuestionId = 5000;
var draggedQuestion = null;
var draggedGroup = null;

function $(id){
    return document.getElementById(id);
}

function escapeHtml(str){
    return String(str || '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function showPage(id){
    ['page-list','page-editor','page-detail'].forEach(function(x){
        $(x).classList.add('hidden');
    });
    $(id).classList.remove('hidden');

    $('nav-list').classList.remove('active');
    $('nav-create').classList.remove('active');

    if(id === 'page-list') $('nav-list').classList.add('active');
    if(id === 'page-editor') $('nav-create').classList.add('active');
}

function showList(){
    renderList();
    showPage('page-list');
}

function statusBadge(status){
    if(status === 'open') return '<span class="badge badge-open">公開中</span>';
    if(status === 'end') return '<span class="badge badge-end">終了</span>';
    return '<span class="badge badge-draft">下書き</span>';
}

function renderList(){
    var body = $('survey-list-body');

    if(!surveys.length){
        body.innerHTML =
            '<tr><td colspan="6" class="empty">アンケートがありません。</td></tr>';
        return;
    }

    body.innerHTML = surveys.map(function(s){
        var period = s.start || s.end
            ? escapeHtml(s.start || '未設定') + ' ～ ' + escapeHtml(s.end || '未設定')
            : '未設定';

        return '<tr>' +
            '<td><button class="link-button" onclick="openDetail('+s.id+')">'+escapeHtml(s.name)+'</button></td>' +
            '<td>'+statusBadge(s.status)+'</td>' +
            '<td>'+period+'</td>' +
            '<td>'+s.answers+'件</td>' +
            '<td>'+escapeHtml(s.updated)+'</td>' +
            '<td>' +
                '<button class="btn btn-small" onclick="editSurvey('+s.id+')">編集</button> ' +
                '<button class="btn btn-small" onclick="openDetail('+s.id+')">確認</button> ' +
                (s.status === 'open'
                    ? '<button class="btn btn-small btn-danger" onclick="endSurvey('+s.id+')">終了</button>'
                    : '') +
                (s.status === 'draft'
                    ? ' <button class="btn btn-small btn-danger" onclick="deleteSurvey('+s.id+')">削除</button>'
                    : '') +
            '</td>' +
        '</tr>';
    }).join('');
}

function cloneSurvey(s){
    return JSON.parse(JSON.stringify(s));
}

function openCreate(){
    editingSurvey = {
        id: null,
        name: '',
        description: '',
        status: 'draft',
        start: '',
        end: '',
        answers: 0,
        target: 0,
        updated: '',
        numbering: 'global',
        groups: [
            {
                id: nextGroupId++,
                name: 'グループ1',
                questions: [
                    {
                        id: nextQuestionId++,
                        text: '',
                        type: 'free',
                        required: false,
                        options: []
                    }
                ]
            }
        ]
    };

    $('editor-page-title').textContent = 'アンケート作成';
    loadEditor();
    showPage('page-editor');
}

function editSurvey(id){
    var survey = surveys.find(function(s){return s.id === id;});
    if(!survey) return;

    editingSurvey = cloneSurvey(survey);
    $('editor-page-title').textContent = 'アンケート編集';
    loadEditor();
    showPage('page-editor');
}

function editCurrentSurvey(){
    if(currentSurveyId !== null){
        editSurvey(currentSurveyId);
    }
}

function loadEditor(){
    $('survey-name').value = editingSurvey.name || '';
    $('survey-description').value = editingSurvey.description || '';
    $('survey-status').value = editingSurvey.status || 'draft';
    $('survey-start').value = editingSurvey.start || '';
    $('survey-end').value = editingSurvey.end || '';

    document.querySelectorAll('input[name="numbering"]').forEach(function(r){
        r.checked = r.value === editingSurvey.numbering;
    });

    renderEditor();
}

function changeNumbering(value){
    editingSurvey.numbering = value;
    renderEditor();
}

function renderEditor(){
    var html = '';

    editingSurvey.groups.forEach(function(group, gi){
        html += '<div class="group-card" draggable="true" data-group-id="'+group.id+'" ' +
                'ondragstart="dragGroupStart(event,'+group.id+')" ' +
                'ondragover="allowDrop(event)" ' +
                'ondrop="dropGroup(event,'+group.id+')">';

        html += '<div class="group-header">';
        html += '<span class="drag-handle" title="ドラッグしてグループを移動">☷</span>';
        html += '<div class="group-title">';
        html += '<input value="'+escapeHtml(group.name)+'" ' +
                'oninput="updateGroupName('+group.id+',this.value)">';
        html += '</div>';
        html += '<div class="group-actions">';
        html += '<button class="btn btn-small btn-danger" onclick="deleteGroup('+group.id+')">グループ削除</button>';
        html += '</div>';
        html += '</div>';

        html += '<div class="questions">';

        group.questions.forEach(function(q, qi){
            var qNo = getQuestionNumber(gi, qi);
            html += renderQuestion(group, q, qNo);
        });

        html += '</div>';

        html += '<div class="add-question-area">';
        html += '<button class="btn btn-small btn-primary" onclick="addQuestion('+group.id+')">＋ 質問追加</button>';
        html += '</div>';

        html += '</div>';
    });

    $('groups').innerHTML = html;
}

function getQuestionNumber(groupIndex, questionIndex){
    if(editingSurvey.numbering === 'group'){
        return 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1);
    }

    var n = 0;
    for(var i=0;i<groupIndex;i++){
        n += editingSurvey.groups[i].questions.length;
    }
    n += questionIndex + 1;
    return 'Q' + n;
}

function getQuestionLabelById(id){
    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        for(var qi=0;qi<editingSurvey.groups[gi].questions.length;qi++){
            if(String(editingSurvey.groups[gi].questions[qi].id) === String(id)){
                return getQuestionNumber(gi,qi) + '：' + editingSurvey.groups[gi].questions[qi].text;
            }
        }
    }
    return '';
}

function getQuestionOptions(q){
    var html = '';

    if(q.type === 'single' || q.type === 'multiple'){
        html += '<div class="question-options">';
        html += '<div style="font-size:12px;color:#718096;margin-bottom:7px;">選択肢</div>';

        q.options.forEach(function(opt, oi){
            html += '<div class="option-row">';
            html += '<span style="width:18px;color:#718096;">'+(oi+1)+'.</span>';
            html += '<input value="'+escapeHtml(opt.text)+'" oninput="updateOption('+q.id+','+oi+',this.value)">';
            if(q.type === 'single'){
                html += '<select class="branch-select" onchange="updateBranch('+q.id+','+oi+',this.value)">';
                html += '<option value="">次の質問へ（通常）</option>';

                editingSurvey.groups.forEach(function(g, gi){
                    g.questions.forEach(function(target, ti){
                        if(target.id !== q.id){
                            var selected = String(opt.branch) === String(target.id) ? ' selected' : '';
                            html += '<option value="'+target.id+'"'+selected+'>'+
                                escapeHtml(getQuestionNumber(gi,ti)+'：'+(target.text || '（未入力）'))+
                                '</option>';
                        }
                    });
                });

                html += '</select>';
            }
            html += '<button class="btn btn-small btn-danger" onclick="deleteOption('+q.id+','+oi+')">削除</button>';
            html += '</div>';
        });

        html += '<button class="btn btn-small" onclick="addOption('+q.id+')">＋ 選択肢追加</button>';

        if(q.type === 'single'){
            html += '<div style="font-size:12px;color:#718096;margin-top:8px;">単一選択では、選択肢ごとに次の質問への分岐を設定できます。</div>';
        }

        html += '</div>';
    }

    return html;
}

function renderQuestion(group,q,qNo){
    var typeLabel = {
        free:'自由記述',
        single:'単一選択',
        multiple:'複数選択'
    }[q.type] || '';

    var html = '';
    html += '<div class="question-card" draggable="true" ' +
            'data-question-id="'+q.id+'" ' +
            'ondragstart="dragQuestionStart(event,'+group.id+','+q.id+')" ' +
            'ondragover="allowDrop(event)" ' +
            'ondrop="dropQuestion(event,'+group.id+','+q.id+')">';

    html += '<div class="question-head">';
    html += '<span class="drag-handle" title="ドラッグして質問を移動">☷</span>';
    html += '<span class="question-number">'+qNo+'</span>';
    html += '<div class="question-title">';
    html += '<input placeholder="質問文を入力してください" value="'+escapeHtml(q.text)+'" ' +
            'oninput="updateQuestionText('+q.id+',this.value)">';
    html += '</div>';

    html += '<div class="question-tools">';
    html += '<select onchange="updateQuestionType('+q.id+',this.value)">';
    html += '<option value="free"'+(q.type==='free'?' selected':'')+'>自由記述</option>';
    html += '<option value="single"'+(q.type==='single'?' selected':'')+'>単一選択</option>';
    html += '<option value="multiple"'+(q.type==='multiple'?' selected':'')+'>複数選択</option>';
    html += '</select>';
    html += '<button class="btn btn-small btn-danger" onclick="deleteQuestion('+group.id+','+q.id+')">削除</button>';
    html += '</div>';
    html += '</div>';

    html += '<div class="question-meta">';
    html += '<label><input type="checkbox" '+(q.required?'checked':'')+' onchange="updateRequired('+q.id+',this.checked)"> 必須</label>';
    html += '<span>回答形式：'+typeLabel+'</span>';
    html += '</div>';

    html += getQuestionOptions(q);
    html += '</div>';

    return html;
}

function findQuestion(id){
    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        for(var qi=0;qi<editingSurvey.groups[gi].questions.length;qi++){
            if(editingSurvey.groups[gi].questions[qi].id == id){
                return {
                    group:editingSurvey.groups[gi],
                    question:editingSurvey.groups[gi].questions[qi],
                    groupIndex:gi,
                    questionIndex:qi
                };
            }
        }
    }
    return null;
}

function updateGroupName(id,value){
    editingSurvey.groups.forEach(function(g){
        if(g.id == id) g.name = value;
    });
}

function updateQuestionText(id,value){
    var f=findQuestion(id);
    if(f) f.question.text=value;
}

function updateQuestionType(id,value){
    var f=findQuestion(id);
    if(!f) return;

    f.question.type=value;

    if(value === 'free'){
        f.question.options=[];
    }else if(!f.question.options.length){
        f.question.options=[
            {text:'選択肢1',branch:''},
            {text:'選択肢2',branch:''}
        ];
    }

    renderEditor();
}

function updateRequired(id,value){
    var f=findQuestion(id);
    if(f) f.question.required=value;
}

function updateOption(qid,index,value){
    var f=findQuestion(qid);
    if(f && f.question.options[index]){
        f.question.options[index].text=value;
    }
}

function updateBranch(qid,index,value){
    var f=findQuestion(qid);
    if(f && f.question.options[index]){
        f.question.options[index].branch=value;
    }
}

function addOption(qid){
    var f=findQuestion(qid);
    if(!f) return;

    f.question.options.push({
        text:'選択肢'+(f.question.options.length+1),
        branch:''
    });

    renderEditor();
}

function deleteOption(qid,index){
    var f=findQuestion(qid);
    if(!f) return;

    if(f.question.options.length <= 1){
        showToast('選択肢は1つ以上必要です');
        return;
    }

    f.question.options.splice(index,1);
    renderEditor();
}

function addQuestion(groupId){
    var group = editingSurvey.groups.find(function(g){return g.id == groupId;});
    if(!group) return;

    group.questions.push({
        id:nextQuestionId++,
        text:'',
        type:'free',
        required:false,
        options:[]
    });

    renderEditor();

    setTimeout(function(){
        var cards=document.querySelectorAll('.question-card');
        if(cards.length) cards[cards.length-1].scrollIntoView({behavior:'smooth',block:'center'});
    },50);
}

function deleteQuestion(groupId,qid){
    var group=editingSurvey.groups.find(function(g){return g.id==groupId;});
    if(!group) return;

    if(!confirm('この質問を削除しますか？')) return;

    group.questions=group.questions.filter(function(q){return q.id!=qid;});

    if(group.questions.length===0){
        showToast('質問がなくなりました。必要に応じて質問を追加してください。');
    }

    renderEditor();
}

function addGroup(){
    editingSurvey.groups.push({
        id:nextGroupId++,
        name:'新しいグループ',
        questions:[]
    });

    renderEditor();

    setTimeout(function(){
        var cards=document.querySelectorAll('.group-card');
        if(cards.length) cards[cards.length-1].scrollIntoView({behavior:'smooth',block:'center'});
    },50);
}

function deleteGroup(groupId){
    var index=editingSurvey.groups.findIndex(function(g){return g.id==groupId;});
    if(index<0) return;

    var group=editingSurvey.groups[index];

    if(group.questions.length){
        if(!confirm('このグループと、グループ内の質問をすべて削除しますか？')) return;
    }else{
        if(!confirm('このグループを削除しますか？')) return;
    }

    editingSurvey.groups.splice(index,1);
    renderEditor();
}

function dragQuestionStart(event,groupId,qid){
    draggedQuestion={
        groupId:groupId,
        questionId:qid
    };
    draggedGroup=null;
    event.dataTransfer.effectAllowed='move';
    event.dataTransfer.setData('text/plain','question:'+qid);
    event.currentTarget.classList.add('dragging');
}

function dragGroupStart(event,gid){
    draggedGroup=gid;
    draggedQuestion=null;
    event.dataTransfer.effectAllowed='move';
    event.dataTransfer.setData('text/plain','group:'+gid);
}

function allowDrop(event){
    event.preventDefault();
    event.dataTransfer.dropEffect='move';
}

function dropQuestion(event,targetGroupId,targetQuestionId){
    event.preventDefault();
    if(!draggedQuestion) return;

    var sourceGroup=editingSurvey.groups.find(function(g){return g.id==draggedQuestion.groupId;});
    var targetGroup=editingSurvey.groups.find(function(g){return g.id==targetGroupId;});

    if(!sourceGroup || !targetGroup) return;

    var sourceIndex=sourceGroup.questions.findIndex(function(q){return q.id==draggedQuestion.questionId;});
    var targetIndex=targetGroup.questions.findIndex(function(q){return q.id==targetQuestionId;});

    if(sourceIndex<0 || targetIndex<0) return;

    var moved=sourceGroup.questions.splice(sourceIndex,1)[0];

    if(sourceGroup===targetGroup && sourceIndex<targetIndex){
        targetIndex--;
    }

    targetGroup.questions.splice(targetIndex,0,moved);

    draggedQuestion=null;
    renderEditor();
}

function dropGroup(event,targetGroupId){
    event.preventDefault();
    if(draggedGroup===null || draggedGroup==targetGroupId) return;

    var sourceIndex=editingSurvey.groups.findIndex(function(g){return g.id==draggedGroup;});
    var targetIndex=editingSurvey.groups.findIndex(function(g){return g.id==targetGroupId;});

    if(sourceIndex<0 || targetIndex<0) return;

    var moved=editingSurvey.groups.splice(sourceIndex,1)[0];

    if(sourceIndex<targetIndex) targetIndex--;

    editingSurvey.groups.splice(targetIndex,0,moved);

    draggedGroup=null;
    renderEditor();
}

function saveSurvey(){
    var name=$('survey-name').value.trim();

    if(!name){
        showToast('アンケート名を入力してください');
        $('survey-name').focus();
        return;
    }

    editingSurvey.name=name;
    editingSurvey.description=$('survey-description').value;
    editingSurvey.status=$('survey-status').value;
    editingSurvey.start=$('survey-start').value;
    editingSurvey.end=$('survey-end').value;
    editingSurvey.updated=new Date().toISOString().slice(0,10);

    if(editingSurvey.id===null){
        editingSurvey.id=Date.now();
        surveys.unshift(cloneSurvey(editingSurvey));
        currentSurveyId=editingSurvey.id;
        showToast('アンケートを作成しました');
    }else{
        var index=surveys.findIndex(function(s){return s.id===editingSurvey.id;});
        if(index>=0){
            surveys[index]=cloneSurvey(editingSurvey);
        }
        currentSurveyId=editingSurvey.id;
        showToast('アンケートを保存しました');
    }

    setTimeout(function(){
        openDetail(currentSurveyId);
    },300);
}

function openDetail(id){
    var survey=surveys.find(function(s){return s.id===id;});
    if(!survey) return;

    currentSurveyId=id;

    $('detail-title').textContent=survey.name;
    $('detail-subtitle').textContent=
        (survey.status==='open'?'公開中':survey.status==='end'?'終了':'下書き')+
        '　｜　最終更新 '+survey.updated;

    showDetailTab('content');
    showPage('page-detail');
}

function showDetailTab(tab){
    ['content','status','result'].forEach(function(t){
        $('tab-'+t).classList.remove('active');
    });
    $('tab-'+tab).classList.add('active');

    var survey=surveys.find(function(s){return s.id===currentSurveyId;});
    if(!survey) return;

    if(tab==='content'){
        renderDetailContent(survey);
    }else if(tab==='status'){
        renderDetailStatus(survey);
    }else{
        renderDetailResult(survey);
    }
}

function renderDetailContent(survey){
    var html='<div class="card" style="padding:20px">';

    html+='<div style="margin-bottom:18px;color:#52606d;">'+
        escapeHtml(survey.description || '説明はありません。')+
        '</div>';

    html+='<div style="margin-bottom:15px;font-size:13px;color:#718096;">質問番号：'+
        (survey.numbering==='group'
            ? 'グループごと（Q1-1、Q1-2…）'
            : '全体で通番（Q1、Q2…）')+
        '</div>';

    survey.groups.forEach(function(g,gi){
        html+='<div style="margin-top:20px;font-weight:bold;color:#34495e;">'+
            escapeHtml(g.name)+'</div>';

        g.questions.forEach(function(q,qi){
            var qNo=getQuestionNumberForSurvey(survey,gi,qi);

            html+='<div class="preview-question">';
            html+='<div class="preview-question-title">'+
                qNo+'　'+escapeHtml(q.text || '（質問文未入力）')+
                (q.required ? ' <span style="color:#d9534f;font-size:12px;">必須</span>':'')+
                '</div>';

            if(q.type==='free'){
                html+='<div class="preview-option">自由記述</div>';
            }else{
                html+='<div class="preview-option">回答形式：'+
                    (q.type==='single'?'単一選択':'複数選択')+
                    '</div>';

                q.options.forEach(function(o){
                    html+='<div class="preview-option">・'+escapeHtml(o.text);
                    if(q.type==='single' && o.branch){
                        var label=findQuestionLabelInSurvey(survey,o.branch);
                        html+='　→ '+escapeHtml(label);
                    }
                    html+='</div>';
                });
            }

            html+='</div>';
        });
    });

    html+='</div>';

    $('detail-content').innerHTML=html;
}

function getQuestionNumberForSurvey(survey,gi,qi){
    if(survey.numbering==='group'){
        return 'Q'+(gi+1)+'-'+(qi+1);
    }

    var n=0;
    for(var i=0;i<gi;i++){
        n+=survey.groups[i].questions.length;
    }
    return 'Q'+(n+qi+1);
}

function findQuestionLabelInSurvey(survey,id){
    for(var gi=0;gi<survey.groups.length;gi++){
        for(var qi=0;qi<survey.groups[gi].questions.length;qi++){
            if(String(survey.groups[gi].questions[qi].id)===String(id)){
                return getQuestionNumberForSurvey(survey,gi,qi);
            }
        }
    }
    return '無効な分岐先';
}

function renderDetailStatus(survey){
    var target=survey.target || 200;
    var answer=survey.answers || 0;
    var rate=target ? Math.round(answer/target*100) : 0;
    if(rate>100) rate=100;

    var html='<div class="detail-summary">';
    html+=statCard('回答数',answer+'件');
    html+=statCard('回答率',rate+'%');
    html+=statCard('未回答',Math.max(target-answer,0)+'件');
    html+=statCard('公開期間',(survey.start||'未設定')+' ～ '+(survey.end||'未設定'));
    html+='</div>';

    html+='<div class="card" style="padding:20px">';
    html+='<h3 style="margin-top:0">回答状況の推移</h3>';
    html+='<div style="height:170px;display:flex;align-items:flex-end;gap:10px;border-bottom:1px solid #ccd5de;padding:0 20px;">';

    var values=[18,25,31,43,57,76,91,105,116,128];
    values.forEach(function(v,i){
        html+='<div style="flex:1;text-align:center">';
        html+='<div style="height:'+(v/140*130)+'px;background:#4285c5;border-radius:3px 3px 0 0;max-width:45px;margin:0 auto;"></div>';
        html+='<div style="font-size:10px;color:#718096;margin-top:5px;">'+(i+1)+'</div>';
        html+='</div>';
    });

    html+='</div>';
    html+='<div style="margin-top:15px;color:#718096;font-size:12px;">日別の回答数を表示しています（モック表示）</div>';
    html+='</div>';

    $('detail-content').innerHTML=html;
}

function statCard(label,value){
    return '<div class="stat-card"><div class="stat-label">'+escapeHtml(label)+'</div><div class="stat-value">'+escapeHtml(value)+'</div></div>';
}

function renderDetailResult(survey){
    var html='<div class="card">';

    var questionNo=0;

    survey.groups.forEach(function(g,gi){
        g.questions.forEach(function(q,qi){
            questionNo++;

            var no=getQuestionNumberForSurvey(survey,gi,qi);

            html+='<div class="result-item">';
            html+='<div style="font-weight:bold;margin-bottom:12px;">'+
                no+'　'+escapeHtml(q.text || '（質問文未入力）')+'</div>';

            if(q.type==='free'){
                html+='<div style="background:#f7f9fb;padding:10px;border-radius:4px;margin-bottom:6px;">とても参考になりました。今後も利用したいです。</div>';
                html+='<div style="background:#f7f9fb;padding:10px;border-radius:4px;margin-bottom:6px;">サービスが分かりやすかったです。</div>';
                html+='<div style="background:#f7f9fb;padding:10px;border-radius:4px;">もう少し説明があるとよいと思います。</div>';
            }else{
                var total=survey.answers || 128;
                if(!total) total=1;

                q.options.forEach(function(o,oi){
                    var count=Math.max(1,Math.round(total*(0.55-(oi*0.12))));
                    var pct=Math.round(count/total*100);

                    html+='<div style="margin-top:10px;">';
                    html+='<div style="display:flex;justify-content:space-between;font-size:13px;">';
                    html+='<span>'+escapeHtml(o.text)+'</span>';
                    html+='<span>'+count+'件（'+pct+'%）</span>';
                    html+='</div>';
                    html+='<div class="bar"><span style="width:'+pct+'%"></span></div>';
                    html+='</div>';
                });
            }

            html+='</div>';
        });
    });

    html+='</div>';

    $('detail-content').innerHTML=html;
}

function endSurvey(id){
    var survey=surveys.find(function(s){return s.id===id;});
    if(!survey) return;

    if(!confirm('このアンケートを終了しますか？')) return;

    survey.status='end';
    survey.updated=new Date().toISOString().slice(0,10);

    renderList();
    showToast('アンケートを終了しました');
}

function deleteSurvey(id){
    if(!confirm('この下書きを削除しますか？')) return;

    surveys=surveys.filter(function(s){return s.id!==id;});
    renderList();
    showToast('アンケートを削除しました');
}

function showToast(message){
    var toast=$('toast');
    toast.textContent=message;
    toast.classList.add('show');

    clearTimeout(window.toastTimer);
    window.toastTimer=setTimeout(function(){
        toast.classList.remove('show');
    },2200);
}

document.addEventListener('dragend',function(){
    document.querySelectorAll('.dragging').forEach(function(el){
        el.classList.remove('dragging');
    });
});

showList();
</script>
</body>
</html>

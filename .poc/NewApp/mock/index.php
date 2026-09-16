<?php
/*
 * アンケート管理アプリ モック
 * Apache + PHP 5.8
 * HTML / CSS / JavaScript を1ファイルに収録
 *
 * ※モック確認用のため、データはブラウザ上で保持する。
 */

$initialData = array(
    array(
        'id' => 1,
        'title' => '社内満足度アンケート',
        'description' => '職場環境や業務についてのアンケートです。',
        'status' => 'active',
        'start' => '2026/09/01 09:00',
        'end' => '2026/09/30 18:00',
        'answers' => 42,
        'updated' => '2026/09/10'
    ),
    array(
        'id' => 2,
        'title' => '新サービス利用意向調査',
        'description' => '新サービスについてのご意見をお聞かせください。',
        'status' => 'waiting',
        'start' => '2026/09/20 09:00',
        'end' => '2026/10/05 18:00',
        'answers' => 0,
        'updated' => '2026/09/12'
    ),
    array(
        'id' => 3,
        'title' => 'イベント参加者アンケート',
        'description' => 'イベントにご参加いただいた皆様へのアンケートです。',
        'status' => 'ended',
        'start' => '2026/08/01 10:00',
        'end' => '2026/08/15 18:00',
        'answers' => 86,
        'updated' => '2026/08/16'
    ),
    array(
        'id' => 4,
        'title' => '昨年度研修アンケート',
        'description' => '昨年度に実施した研修のアンケートです。',
        'status' => 'archived',
        'start' => '2025/10/01 09:00',
        'end' => '2025/10/31 18:00',
        'answers' => 128,
        'updated' => '2025/11/01'
    ),
    array(
        'id' => 5,
        'title' => '新入社員向け意識調査',
        'description' => '新入社員向けの意識調査です。',
        'status' => 'draft',
        'start' => '',
        'end' => '',
        'answers' => 0,
        'updated' => '2026/09/15'
    )
);
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
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif;
    color:#263238;
    background:#f5f7fa;
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.header{
    height:64px;
    background:#17365d;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 28px;
}
.logo{font-size:20px;font-weight:bold}
.header-right{font-size:13px;opacity:.9}
.layout{display:flex;min-height:calc(100vh - 64px)}
.sidebar{
    width:220px;
    background:#fff;
    border-right:1px solid #dde3ea;
    padding:18px 12px;
}
.nav-title{
    color:#8a96a3;
    font-size:11px;
    font-weight:bold;
    margin:10px 10px 8px;
}
.nav button{
    width:100%;
    border:0;
    background:none;
    text-align:left;
    padding:11px 12px;
    border-radius:7px;
    color:#34495e;
    margin-bottom:3px;
}
.nav button:hover,.nav button.active{
    background:#eaf2fb;
    color:#1769aa;
    font-weight:bold;
}
.main{flex:1;padding:28px;max-width:1400px}
.page-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:22px;
}
.page-title h1{font-size:25px;margin:0}
.page-title p{margin:5px 0 0;color:#75818d;font-size:13px}
.primary{
    background:#1769aa;
    color:#fff;
    border:0;
    border-radius:6px;
    padding:10px 18px;
    font-weight:bold;
}
.primary:hover{background:#12578d}
.secondary{
    background:#fff;
    color:#34495e;
    border:1px solid #cbd4dd;
    border-radius:6px;
    padding:9px 15px;
}
.danger{
    background:#fff;
    color:#c0392b;
    border:1px solid #e0aaa5;
    border-radius:6px;
    padding:9px 15px;
}
.card{
    background:#fff;
    border:1px solid #dde3ea;
    border-radius:9px;
    padding:20px;
    margin-bottom:18px;
    box-shadow:0 1px 2px rgba(0,0,0,.03);
}
.stats{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:14px;
    margin-bottom:20px;
}
.stat{
    background:#fff;
    border:1px solid #dde3ea;
    border-radius:9px;
    padding:17px;
}
.stat-label{font-size:12px;color:#71808e}
.stat-value{font-size:28px;font-weight:bold;margin-top:5px}
.stat-note{font-size:11px;color:#8995a0;margin-top:4px}
.stat.wait .stat-value{color:#b7791f}
.stat.active .stat-value{color:#218838}
.stat.end .stat-value{color:#68737d}
.stat.draft .stat-value{color:#5b6b8c}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse}
th,td{
    padding:13px 12px;
    border-bottom:1px solid #e6ebf0;
    text-align:left;
    font-size:13px;
    vertical-align:middle;
}
th{font-size:12px;color:#667582;background:#fafbfd}
tr:last-child td{border-bottom:0}
.status{
    display:inline-flex;
    align-items:center;
    padding:5px 9px;
    border-radius:20px;
    font-size:11px;
    font-weight:bold;
    white-space:nowrap;
}
.status:before{
    content:"";
    width:7px;height:7px;border-radius:50%;
    margin-right:6px;background:currentColor;
}
.status.draft{background:#eef1f6;color:#64748b}
.status.waiting{background:#fff4dc;color:#a66b00}
.status.active{background:#e8f6ed;color:#218838}
.status.ended{background:#eef0f2;color:#67727c}
.status.archived{background:#f1edf8;color:#7255a5}
.actions{display:flex;gap:6px;flex-wrap:wrap}
.small-btn{
    border:1px solid #ccd5de;
    background:#fff;
    border-radius:5px;
    padding:6px 9px;
    color:#41515f;
    font-size:11px;
}
.small-btn:hover{background:#f4f7fa}
.notice{
    border-radius:7px;
    padding:13px 15px;
    margin-bottom:18px;
    font-size:13px;
}
.notice.info{background:#edf6ff;border:1px solid #c9e3fa;color:#24577e}
.notice.wait{background:#fff8e8;border:1px solid #f2d59b;color:#765311}
.notice.success{background:#edf9f1;border:1px solid #bde2c7;color:#246b35}
.notice.end{background:#f1f3f5;border:1px solid #d8dde2;color:#58636d}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
.form-group{margin-bottom:16px}
.form-group.full{grid-column:1/-1}
label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:7px;
}
.required{color:#c0392b;font-size:11px;margin-left:5px}
input[type=text],input[type=datetime-local],textarea,select{
    width:100%;
    border:1px solid #cbd4dd;
    border-radius:6px;
    padding:10px 11px;
    background:#fff;
}
textarea{min-height:90px;resize:vertical}
.help{font-size:11px;color:#7b8792;margin-top:5px}
.question{
    border:1px solid #dce3e9;
    border-radius:8px;
    padding:16px;
    margin-bottom:12px;
    background:#fbfcfd;
}
.question-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
}
.q-number{font-weight:bold;color:#536779}
.question-actions{display:flex;gap:5px}
.option-row{display:flex;gap:6px;margin-top:7px}
.option-row input{flex:1}
.preview-question{
    padding:17px 0;
    border-bottom:1px solid #e5e9ed;
}
.preview-question:last-child{border-bottom:0}
.preview-q-title{font-weight:bold;margin-bottom:9px}
.badge-required{
    display:inline-block;
    font-size:10px;
    color:#b13b32;
    background:#fff0ee;
    padding:3px 6px;
    border-radius:4px;
    margin-left:7px;
}
.answer-box{margin-top:8px}
.choice{margin:8px 0}
.choice label{font-weight:normal;display:flex;gap:8px;align-items:center}
.progress{
    height:8px;background:#e8edf2;border-radius:5px;overflow:hidden
}
.progress-bar{height:100%;background:#287bb5}
.modal{
    position:fixed;inset:0;
    background:rgba(16,30,44,.48);
    display:none;align-items:center;justify-content:center;
    z-index:1000;padding:20px;
}
.modal.show{display:flex}
.modal-box{
    background:#fff;border-radius:10px;
    width:100%;max-width:540px;
    padding:25px;
    box-shadow:0 10px 35px rgba(0,0,0,.2)
}
.modal-box h2{font-size:19px;margin:0 0 12px}
.modal-box p{font-size:13px;line-height:1.7;color:#52616d}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}
.hidden{display:none!important}
.empty{text-align:center;padding:40px;color:#7b8792}
.step{
    display:flex;gap:0;margin-bottom:20px;
}
.step-item{flex:1;text-align:center;position:relative}
.step-item:before{
    content:"";position:absolute;top:13px;left:0;right:0;
    height:2px;background:#dce3e9;z-index:0
}
.step-item:first-child:before{left:50%}
.step-item:last-child:before{right:50%}
.step-dot{
    width:27px;height:27px;border-radius:50%;
    background:#dce3e9;color:#64727d;
    display:inline-flex;align-items:center;justify-content:center;
    position:relative;z-index:1;font-size:11px;font-weight:bold
}
.step-item.done .step-dot,.step-item.current .step-dot{
    background:#1769aa;color:#fff
}
.step-label{font-size:10px;color:#74818d;margin-top:5px}
.answer-card{
    border:1px solid #dfe5ea;
    border-radius:7px;padding:15px;margin-bottom:10px
}
.answer-card h4{margin:0 0 8px;font-size:13px}
@media(max-width:900px){
    .sidebar{width:180px}
    .stats{grid-template-columns:repeat(2,1fr)}
    .form-grid{grid-template-columns:1fr}
}
@media(max-width:650px){
    .sidebar{display:none}
    .main{padding:16px}
    .stats{grid-template-columns:1fr 1fr}
    .page-title{align-items:flex-start;gap:10px}
    th:nth-child(3),td:nth-child(3){display:none}
}
</style>
</head>
<body>

<header class="header">
    <div class="logo">アンケート管理</div>
    <div class="header-right">運営管理者　山田 太郎</div>
</header>

<div class="layout">
<aside class="sidebar">
    <div class="nav-title">管理メニュー</div>
    <div class="nav">
        <button onclick="showPage('home')" id="nav-home">ホーム</button>
        <button onclick="showPage('list')" id="nav-list">アンケート一覧</button>
        <button onclick="showPage('create')" id="nav-create">新しいアンケート</button>
    </div>
    <div class="nav-title">確認</div>
    <div class="nav">
        <button onclick="showPage('status')" id="nav-status">回答状況</button>
        <button onclick="showPage('answers')" id="nav-answers">回答内容</button>
    </div>
    <div class="nav-title">回答者画面</div>
    <div class="nav">
        <button onclick="showPage('respond')" id="nav-respond">回答者として見る</button>
    </div>
</aside>

<main class="main">

<!-- ホーム -->
<section id="page-home" class="page">
    <div class="page-title">
        <div>
            <h1>ホーム</h1>
            <p>アンケートの運営状況を確認できます。</p>
        </div>
        <button class="primary" onclick="showPage('create')">＋ 新しいアンケートを作成</button>
    </div>

    <div class="stats">
        <div class="stat draft">
            <div class="stat-label">作成中</div>
            <div class="stat-value" id="count-draft">0</div>
            <div class="stat-note">公開前のアンケート</div>
        </div>
        <div class="stat wait">
            <div class="stat-label">回答開始待ち</div>
            <div class="stat-value" id="count-wait">0</div>
            <div class="stat-note">公開済み・回答開始前</div>
        </div>
        <div class="stat active">
            <div class="stat-label">回答受付中</div>
            <div class="stat-value" id="count-active">0</div>
            <div class="stat-note">現在回答できます</div>
        </div>
        <div class="stat end">
            <div class="stat-label">回答受付終了</div>
            <div class="stat-value" id="count-ended">0</div>
            <div class="stat-note">回答確認ができます</div>
        </div>
        <div class="stat">
            <div class="stat-label">保管</div>
            <div class="stat-value" id="count-archived">0</div>
            <div class="stat-note">過去のアンケート</div>
        </div>
    </div>

    <div class="card">
        <h2 style="font-size:17px;margin-top:0">現在の運営状況</h2>
        <div id="home-alerts"></div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>アンケート</th>
                        <th>状態</th>
                        <th>回答受付期間</th>
                        <th>回答数</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="home-table"></tbody>
            </table>
        </div>
    </div>
</section>

<!-- 一覧 -->
<section id="page-list" class="page hidden">
    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <p>作成したアンケートを確認・管理します。</p>
        </div>
        <button class="primary" onclick="showPage('create')">＋ 新しいアンケート</button>
    </div>
    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>アンケート名</th>
                        <th>状態</th>
                        <th>回答受付期間</th>
                        <th>回答数</th>
                        <th>更新日</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="list-table"></tbody>
            </table>
        </div>
    </div>
</section>

<!-- 作成 -->
<section id="page-create" class="page hidden">
    <div class="page-title">
        <div>
            <h1>アンケートを作成</h1>
            <p>公開前に内容を確認できます。</p>
        </div>
    </div>

    <div class="step">
        <div class="step-item current"><span class="step-dot">1</span><div class="step-label">基本情報</div></div>
        <div class="step-item"><span class="step-dot">2</span><div class="step-label">質問</div></div>
        <div class="step-item"><span class="step-dot">3</span><div class="step-label">公開前確認</div></div>
    </div>

    <div class="card">
        <div class="form-grid">
            <div class="form-group full">
                <label>アンケート名 <span class="required">必須</span></label>
                <input type="text" id="new-title" placeholder="例：社員満足度アンケート">
            </div>
            <div class="form-group full">
                <label>説明文</label>
                <textarea id="new-description" placeholder="回答者への説明を入力してください。"></textarea>
            </div>
            <div class="form-group">
                <label>回答受付開始日時</label>
                <input type="datetime-local" id="new-start">
                <div class="help">公開後、この日時までは「回答開始待ち」となります。</div>
            </div>
            <div class="form-group">
                <label>回答受付終了日時</label>
                <input type="datetime-local" id="new-end">
            </div>
            <div class="form-group full">
                <label>回答者への案内</label>
                <textarea id="new-guide" placeholder="回答にあたっての注意事項など"></textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h2 style="font-size:17px;margin:0">質問</h2>
            <button class="secondary" onclick="addQuestion()">＋ 質問を追加</button>
        </div>
        <div id="question-editor"></div>
    </div>

    <div class="card">
        <div class="notice info">
            <strong>公開前に確認できます</strong><br>
            公開すると、回答受付開始日時に応じて「回答開始待ち」または「回答受付中」になります。
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px">
            <button class="secondary" onclick="showPage('list')">キャンセル</button>
            <button class="primary" onclick="previewNew()">公開前の内容を確認</button>
        </div>
    </div>
</section>

<!-- プレビュー -->
<section id="page-preview" class="page hidden">
    <div class="page-title">
        <div>
            <h1>公開前確認</h1>
            <p>回答者から見える内容を確認してください。</p>
        </div>
    </div>
    <div class="notice wait">
        <strong>公開前です</strong><br>
        内容に問題がなければ「この内容で公開する」を選択してください。
    </div>
    <div class="card" id="preview-content"></div>
    <div class="card">
        <div style="display:flex;justify-content:flex-end;gap:8px">
            <button class="secondary" onclick="showPage('create')">編集画面に戻る</button>
            <button class="primary" onclick="publishNew()">この内容で公開する</button>
        </div>
    </div>
</section>

<!-- 回答者 -->
<section id="page-respond" class="page hidden">
    <div class="page-title">
        <div>
            <h1>回答者として見る</h1>
            <p>回答者から見たアンケートの状態を確認できます。</p>
        </div>
        <select id="respond-select" onchange="renderRespond()"></select>
    </div>
    <div id="respond-content"></div>
</section>

<!-- 回答状況 -->
<section id="page-status" class="page hidden">
    <div class="page-title">
        <div>
            <h1>回答状況</h1>
            <p>アンケートごとの回答数・受付状態を確認します。</p>
        </div>
        <select id="status-select" onchange="renderStatus()"></select>
    </div>
    <div id="status-content"></div>
</section>

<!-- 回答内容 -->
<section id="page-answers" class="page hidden">
    <div class="page-title">
        <div>
            <h1>回答内容</h1>
            <p>実際に送信された回答を確認します。</p>
        </div>
        <select id="answers-select" onchange="renderAnswers()"></select>
    </div>
    <div id="answers-content"></div>
</section>

</main>
</div>

<!-- 確認モーダル -->
<div class="modal" id="confirm-modal">
    <div class="modal-box">
        <h2 id="modal-title">確認</h2>
        <p id="modal-message"></p>
        <div class="modal-actions">
            <button class="secondary" onclick="closeModal()">キャンセル</button>
            <button class="primary" id="modal-ok">実行する</button>
        </div>
    </div>
</div>

<script>
var surveys = <?php echo json_encode($initialData, JSON_UNESCAPED_UNICODE); ?>;

var questions = [
    {
        text:"現在の仕事に満足していますか？",
        type:"single",
        required:true,
        options:["とても満足","満足","どちらともいえない","不満","とても不満"]
    },
    {
        text:"改善してほしいことがあれば教えてください。",
        type:"text",
        required:false,
        options:[]
    }
];

var newSurveyQuestions = JSON.parse(JSON.stringify(questions));
var modalAction = null;

function statusName(status){
    var map = {
        draft:"作成中",
        waiting:"公開済み・回答開始待ち",
        active:"回答受付中",
        ended:"回答受付終了",
        archived:"保管"
    };
    return map[status] || status;
}

function statusClass(status){
    return status;
}

function statusBadge(status){
    return '<span class="status '+statusClass(status)+'">'+statusName(status)+'</span>';
}

function showPage(page){
    var pages=document.querySelectorAll('.page');
    for(var i=0;i<pages.length;i++) pages[i].classList.add('hidden');

    var target=document.getElementById('page-'+page);
    if(target) target.classList.remove('hidden');

    var navs=document.querySelectorAll('.nav button');
    for(var j=0;j<navs.length;j++) navs[j].classList.remove('active');

    var nav=document.getElementById('nav-'+page);
    if(nav) nav.classList.add('active');

    if(page==='home') renderHome();
    if(page==='list') renderList();
    if(page==='create') renderQuestionEditor();
    if(page==='respond') setupRespond();
    if(page==='status') setupStatus();
    if(page==='answers') setupAnswers();

    window.scrollTo(0,0);
}

function renderHome(){
    var counts={draft:0,waiting:0,active:0,ended:0,archived:0};
    surveys.forEach(function(s){if(counts[s.status]!==undefined)counts[s.status]++;});

    document.getElementById('count-draft').textContent=counts.draft;
    document.getElementById('count-wait').textContent=counts.waiting;
    document.getElementById('count-active').textContent=counts.active;
    document.getElementById('count-ended').textContent=counts.ended;
    document.getElementById('count-archived').textContent=counts.archived;

    var tbody=document.getElementById('home-table');
    var html='';

    surveys.filter(function(s){
        return s.status==='draft'||s.status==='waiting'||s.status==='active';
    }).forEach(function(s){
        html += surveyRow(s);
    });

    tbody.innerHTML=html || '<tr><td colspan="5" class="empty">現在運営中のアンケートはありません。</td></tr>';

    var alerts=document.getElementById('home-alerts');
    var waiting=surveys.filter(function(s){return s.status==='waiting';});
    if(waiting.length){
        alerts.innerHTML='<div class="notice wait"><strong>回答開始待ちのアンケートがあります。</strong> 公開済みですが、まだ回答受付開始日時になっていません。</div>';
    }else{
        alerts.innerHTML='';
    }
}

function surveyRow(s){
    return '<tr>'+
        '<td><strong>'+esc(s.title)+'</strong><br><span style="font-size:11px;color:#8995a0">'+esc(s.description)+'</span></td>'+
        '<td>'+statusBadge(s.status)+'</td>'+
        '<td>'+dateText(s.start,s.end)+'</td>'+
        '<td>'+s.answers+'件</td>'+
        '<td><div class="actions">'+actionButtons(s)+'</div></td>'+
        '</tr>';
}

function renderList(){
    var tbody=document.getElementById('list-table');
    var html='';
    surveys.forEach(function(s){
        html+='<tr>'+
            '<td><strong>'+esc(s.title)+'</strong></td>'+
            '<td>'+statusBadge(s.status)+'</td>'+
            '<td>'+dateText(s.start,s.end)+'</td>'+
            '<td>'+s.answers+'件</td>'+
            '<td>'+esc(s.updated)+'</td>'+
            '<td><div class="actions">'+actionButtons(s)+'</div></td>'+
            '</tr>';
    });
    tbody.innerHTML=html;
}

function actionButtons(s){
    var b='';
    b+='<button class="small-btn" onclick="viewSurvey('+s.id+')">内容を見る</button>';

    if(s.status==='draft'){
        b+='<button class="small-btn" onclick="editSurvey('+s.id+')">編集する</button>';
        b+='<button class="small-btn" onclick="editSurvey('+s.id+')">公開準備</button>';
    }

    if(s.status==='waiting'||s.status==='active'){
        b+='<button class="small-btn" onclick="openStatus('+s.id+')">回答状況</button>';
        b+='<button class="small-btn" onclick="openAnswers('+s.id+')">回答内容</button>';
    }

    if(s.status==='active'){
        b+='<button class="small-btn" onclick="confirmEnd('+s.id+')">回答受付を終了</button>';
    }

    if(s.status==='ended'){
        b+='<button class="small-btn" onclick="openStatus('+s.id+')">回答状況</button>';
        b+='<button class="small-btn" onclick="openAnswers('+s.id+')">回答内容</button>';
        b+='<button class="small-btn" onclick="confirmArchive('+s.id+')">保管する</button>';
    }

    if(s.status==='archived'){
        b+='<button class="small-btn" onclick="openStatus('+s.id+')">回答状況</button>';
        b+='<button class="small-btn" onclick="openAnswers('+s.id+')">回答内容</button>';
    }

    if(s.status==='draft'){
        b+='<button class="small-btn" onclick="confirmDelete('+s.id+')">削除</button>';
    }

    return b;
}

function dateText(start,end){
    if(!start && !end) return '<span style="color:#9aa4ad">未設定</span>';
    return '<span style="font-size:11px">開始：'+(start||'未設定')+'<br>終了：'+(end||'未設定')+'</span>';
}

function addQuestion(){
    newSurveyQuestions.push({
        text:"",
        type:"single",
        required:true,
        options:["選択肢1","選択肢2"]
    });
    renderQuestionEditor();
}

function removeQuestion(index){
    if(newSurveyQuestions.length<=1){
        alert('質問は1問以上必要です。');
        return;
    }
    newSurveyQuestions.splice(index,1);
    renderQuestionEditor();
}

function moveQuestion(index,dir){
    var to=index+dir;
    if(to<0||to>=newSurveyQuestions.length)return;
    var temp=newSurveyQuestions[index];
    newSurveyQuestions[index]=newSurveyQuestions[to];
    newSurveyQuestions[to]=temp;
    renderQuestionEditor();
}

function updateQuestion(index,key,value){
    newSurveyQuestions[index][key]=value;
}

function addOption(index){
    newSurveyQuestions[index].options.push('新しい選択肢');
    renderQuestionEditor();
}

function removeOption(index,opt){
    if(newSurveyQuestions[index].options.length<=1)return;
    newSurveyQuestions[index].options.splice(opt,1);
    renderQuestionEditor();
}

function renderQuestionEditor(){
    var box=document.getElementById('question-editor');
    var html='';

    newSurveyQuestions.forEach(function(q,i){
        html+='<div class="question">'+
            '<div class="question-head">'+
                '<div class="q-number">質問 '+(i+1)+'</div>'+
                '<div class="question-actions">'+
                    '<button class="small-btn" onclick="moveQuestion('+i+',-1)">↑</button>'+
                    '<button class="small-btn" onclick="moveQuestion('+i+',1)">↓</button>'+
                    '<button class="small-btn" onclick="removeQuestion('+i+')">削除</button>'+
                '</div>'+
            '</div>'+
            '<div class="form-group">'+
                '<label>質問文 <span class="required">必須</span></label>'+
                '<input type="text" value="'+escAttr(q.text)+'" oninput="updateQuestion('+i+',\'text\',this.value)" placeholder="質問を入力してください">'+
            '</div>'+
            '<div class="form-grid">'+
                '<div class="form-group">'+
                    '<label>回答形式</label>'+
                    '<select onchange="changeQuestionType('+i+',this.value)">'+
                        '<option value="text" '+(q.type==='text'?'selected':'')+'>文章を入力</option>'+
                        '<option value="single" '+(q.type==='single'?'selected':'')+'>1つだけ選ぶ</option>'+
                        '<option value="multi" '+(q.type==='multi'?'selected':'')+'>複数選ぶ</option>'+
                        '<option value="rating" '+(q.type==='rating'?'selected':'')+'>5段階で評価</option>'+
                    '</select>'+
                '</div>'+
                '<div class="form-group">'+
                    '<label>回答</label>'+
                    '<label style="font-weight:normal"><input type="checkbox" '+(q.required?'checked':'')+' onchange="updateQuestion('+i+',\'required\',this.checked)"> 必須回答にする</label>'+
                '</div>'+
            '</div>';

        if(q.type==='single'||q.type==='multi'){
            html+='<div class="form-group"><label>選択肢</label>';
            q.options.forEach(function(o,k){
                html+='<div class="option-row">'+
                    '<input type="text" value="'+escAttr(o)+'" oninput="newSurveyQuestions['+i+'].options['+k+']=this.value">'+
                    '<button class="small-btn" onclick="removeOption('+i+','+k+')">削除</button>'+
                    '</div>';
            });
            html+='<button class="small-btn" style="margin-top:8px" onclick="addOption('+i+')">＋ 選択肢を追加</button></div>';
        }

        html+='<div class="form-group"><label>補足説明</label><input type="text" placeholder="必要に応じて補足説明を入力"></div>';
        html+='</div>';
    });

    box.innerHTML=html;
}

function changeQuestionType(index,value){
    newSurveyQuestions[index].type=value;
    if(value==='single'||value==='multi'){
        if(!newSurveyQuestions[index].options.length)
            newSurveyQuestions[index].options=['選択肢1','選択肢2'];
    }
    renderQuestionEditor();
}

function previewNew(){
    var title=document.getElementById('new-title').value.trim();
    if(!title){
        alert('アンケート名を入力してください。');
        document.getElementById('new-title').focus();
        return;
    }

    for(var i=0;i<newSurveyQuestions.length;i++){
        if(!newSurveyQuestions[i].text.trim()){
            alert('質問 '+(i+1)+' の質問文を入力してください。');
            return;
        }
        if((newSurveyQuestions[i].type==='single'||newSurveyQuestions[i].type==='multi') &&
           newSurveyQuestions[i].options.length===0){
            alert('質問 '+(i+1)+' の選択肢を設定してください。');
            return;
        }
    }

    var start=document.getElementById('new-start').value;
    var end=document.getElementById('new-end').value;

    if(start && end && new Date(start)>=new Date(end)){
        alert('回答受付終了日時は、開始日時より後に設定してください。');
        return;
    }

    renderPreview();
    showPage('preview');
}

function renderPreview(){
    var title=document.getElementById('new-title').value;
    var desc=document.getElementById('new-description').value;
    var guide=document.getElementById('new-guide').value;
    var start=document.getElementById('new-start').value;
    var end=document.getElementById('new-end').value;

    var html='<h2 style="margin-top:0">'+esc(title)+'</h2>';
    if(desc) html+='<p style="line-height:1.7;color:#596773">'+nl2br(desc)+'</p>';

    if(start || end){
        html+='<div class="notice info"><strong>回答受付期間</strong><br>'+
            '開始：'+(start||'未設定')+'<br>'+
            '終了：'+(end||'未設定')+'</div>';
    }

    if(guide) html+='<div class="notice">'+nl2br(guide)+'</div>';

    newSurveyQuestions.forEach(function(q,i){
        html+='<div class="preview-question">'+
            '<div class="preview-q-title">'+
                (i+1)+'. '+esc(q.text)+
                (q.required?'<span class="badge-required">必須</span>':'')+
            '</div>';

        if(q.type==='text'){
            html+='<div class="answer-box"><textarea placeholder="回答を入力してください"></textarea></div>';
        }else if(q.type==='rating'){
            html+='<div class="choice">'+
                '<label><input type="radio" name="rating'+i+'"> 1</label>'+
                '<label><input type="radio" name="rating'+i+'"> 2</label>'+
                '<label><input type="radio" name="rating'+i+'"> 3</label>'+
                '<label><input type="radio" name="rating'+i+'"> 4</label>'+
                '<label><input type="radio" name="rating'+i+'"> 5</label>'+
                '</div>';
        }else{
            q.options.forEach(function(o){
                var type=q.type==='multi'?'checkbox':'radio';
                html+='<div class="choice"><label><input type="'+type+'" name="preview'+i+'"> '+esc(o)+'</label></div>';
            });
        }

        html+='</div>';
    });

    document.getElementById('preview-content').innerHTML=html;
}

function publishNew(){
    openModal(
        'アンケートを公開します',
        '公開後は、回答受付開始日時が未来の場合「公開済み・回答開始待ち」、開始日時が現在以前の場合「回答受付中」になります。公開してよろしいですか？',
        function(){
            var start=document.getElementById('new-start').value;
            var end=document.getElementById('new-end').value;
            var status='active';

            if(start && new Date(start)>new Date()) status='waiting';

            var newId=Date.now();
            surveys.unshift({
                id:newId,
                title:document.getElementById('new-title').value,
                description:document.getElementById('new-description').value,
                status:status,
                start:start || '未設定',
                end:end || '未設定',
                answers:0,
                updated:'2026/09/16'
            });

            alert('アンケートを公開しました。現在の状態：'+statusName(status));
            resetCreate();
            showPage('list');
        }
    );
}

function resetCreate(){
    document.getElementById('new-title').value='';
    document.getElementById('new-description').value='';
    document.getElementById('new-start').value='';
    document.getElementById('new-end').value='';
    document.getElementById('new-guide').value='';
    newSurveyQuestions=JSON.parse(JSON.stringify(questions));
}

function viewSurvey(id){
    var s=findSurvey(id);
    if(!s)return;
    document.getElementById('respond-select').value=id;
    showPage('respond');
    renderRespond();
}

function editSurvey(id){
    var s=findSurvey(id);
    if(!s)return;
    alert('モックでは、編集画面への遷移を確認するための動作です。\n\n対象：'+s.title);
    showPage('create');
}

function openStatus(id){
    document.getElementById('status-select').value=id;
    showPage('status');
    renderStatus();
}

function openAnswers(id){
    document.getElementById('answers-select').value=id;
    showPage('answers');
    renderAnswers();
}

function setupRespond(){
    var select=document.getElementById('respond-select');
    select.innerHTML='';
    surveys.forEach(function(s){
        var op=document.createElement('option');
        op.value=s.id;
        op.textContent=s.title+'（'+statusName(s.status)+'）';
        select.appendChild(op);
    });
    if(!select.value && surveys.length)select.value=surveys[0].id;
    renderRespond();
}

function renderRespond(){
    var id=document.getElementById('respond-select').value;
    var s=findSurvey(id);
    if(!s)return;

    var box=document.getElementById('respond-content');

    if(s.status==='draft'){
        box.innerHTML='<div class="notice info"><strong>このアンケートはまだ公開されていません。</strong><br>現在は回答できません。</div>';
        return;
    }

    if(s.status==='waiting'){
        box.innerHTML=
            '<div class="card">'+
            '<span class="status waiting">公開済み・回答開始待ち</span>'+
            '<h2>'+esc(s.title)+'</h2>'+
            '<p style="line-height:1.7">'+esc(s.description)+'</p>'+
            '<div class="notice wait"><strong>回答受付開始前です。</strong><br>回答受付開始：'+esc(s.start)+'</div>'+
            '</div>';
        return;
    }

    if(s.status==='ended'||s.status==='archived'){
        box.innerHTML=
            '<div class="card">'+
            statusBadge(s.status)+
            '<h2>'+esc(s.title)+'</h2>'+
            '<p style="line-height:1.7">'+esc(s.description)+'</p>'+
            '<div class="notice end"><strong>回答受付は終了しています。</strong><br>現在、新しい回答は受け付けていません。</div>'+
            '</div>';
        return;
    }

    box.innerHTML=
        '<div class="card">'+
        '<div class="step">'+
            '<div class="step-item done"><span class="step-dot">1</span><div class="step-label">回答</div></div>'+
            '<div class="step-item"><span class="step-dot">2</span><div class="step-label">確認</div></div>'+
            '<div class="step-item"><span class="step-dot">3</span><div class="step-label">完了</div></div>'+
        '</div>'+
        '<h2>'+esc(s.title)+'</h2>'+
        '<p style="line-height:1.7">'+esc(s.description)+'</p>'+
        '<div class="notice success">現在、回答を受け付けています。</div>'+
        '<form id="response-form">'+
        '<div class="preview-question">'+
            '<div class="preview-q-title">1. 現在の仕事に満足していますか？ <span class="badge-required">必須</span></div>'+
            '<div class="choice"><label><input type="radio" name="q1"> とても満足</label></div>'+
            '<div class="choice"><label><input type="radio" name="q1"> 満足</label></div>'+
            '<div class="choice"><label><input type="radio" name="q1"> どちらともいえない</label></div>'+
            '<div class="choice"><label><input type="radio" name="q1"> 不満</label></div>'+
            '<div class="choice"><label><input type="radio" name="q1"> とても不満</label></div>'+
        '</div>'+
        '<div class="preview-question">'+
            '<div class="preview-q-title">2. 改善してほしいことがあれば教えてください。</div>'+
            '<textarea name="q2" placeholder="回答を入力してください"></textarea>'+
        '</div>'+
        '<div style="display:flex;justify-content:flex-end;margin-top:18px">'+
            '<button type="button" class="primary" onclick="checkResponse()">回答内容を確認する</button>'+
        '</div>'+
        '</form>'+
        '</div>';
}

function checkResponse(){
    var radios=document.getElementsByName('q1');
    var checked=false;
    for(var i=0;i<radios.length;i++)if(radios[i].checked)checked=true;

    if(!checked){
        alert('未回答の必須項目があります。\n「現在の仕事に満足していますか？」を回答してください。');
        return;
    }

    var box=document.getElementById('respond-content');
    box.innerHTML=
        '<div class="card">'+
        '<div class="step">'+
            '<div class="step-item done"><span class="step-dot">1</span><div class="step-label">回答</div></div>'+
            '<div class="step-item current"><span class="step-dot">2</span><div class="step-label">確認</div></div>'+
            '<div class="step-item"><span class="step-dot">3</span><div class="step-label">完了</div></div>'+
        '</div>'+
        '<h2>回答内容の確認</h2>'+
        '<div class="answer-card"><h4>現在の仕事に満足していますか？</h4><div>満足</div></div>'+
        '<div class="answer-card"><h4>改善してほしいことがあれば教えてください。</h4><div>特にありません。</div></div>'+
        '<div style="display:flex;justify-content:flex-end;gap:8px">'+
        '<button class="secondary" onclick="renderRespond()">回答を修正する</button>'+
        '<button class="primary" onclick="completeResponse()">回答を送信する</button>'+
        '</div>'+
        '</div>';
}

function completeResponse(){
    var s=findSurvey(document.getElementById('respond-select').value);
    if(s)s.answers++;
    document.getElementById('respond-content').innerHTML=
        '<div class="card" style="text-align:center;padding:55px 20px">'+
        '<div style="font-size:44px;color:#218838">✓</div>'+
        '<h2>回答が完了しました</h2>'+
        '<p style="color:#667582">ご回答ありがとうございました。<br>回答は正常に送信されました。</p>'+
        '</div>';
}

function setupStatus(){
    var select=document.getElementById('status-select');
    select.innerHTML='';
    surveys.forEach(function(s){
        var op=document.createElement('option');
        op.value=s.id;
        op.textContent=s.title;
        select.appendChild(op);
    });
    renderStatus();
}

function renderStatus(){
    var s=findSurvey(document.getElementById('status-select').value);
    if(!s)return;

    var percent=Math.min(100,Math.round((s.answers/100)*100));

    document.getElementById('status-content').innerHTML=
        '<div class="card">'+
        '<div style="display:flex;justify-content:space-between;align-items:center">'+
        '<div><h2 style="margin:0 0 6px">'+esc(s.title)+'</h2>'+statusBadge(s.status)+'</div>'+
        '<button class="secondary" onclick="showPage(\'list\')">一覧へ戻る</button>'+
        '</div>'+
        '<div class="stats" style="margin-top:20px;grid-template-columns:repeat(3,1fr)">'+
            '<div class="stat"><div class="stat-label">回答数</div><div class="stat-value">'+s.answers+'</div><div class="stat-note">件</div></div>'+
            '<div class="stat"><div class="stat-label">回答受付開始</div><div class="stat-value" style="font-size:17px">'+esc(s.start||'未設定')+'</div></div>'+
            '<div class="stat"><div class="stat-label">回答受付終了</div><div class="stat-value" style="font-size:17px">'+esc(s.end||'未設定')+'</div></div>'+
        '</div>'+
        '<h3 style="font-size:15px">回答状況</h3>'+
        '<div class="progress"><div class="progress-bar" style="width:'+percent+'%"></div></div>'+
        '<div style="font-size:12px;color:#74818d;margin-top:7px">回答 '+s.answers+'件</div>'+
        '</div>';
}

function setupAnswers(){
    var select=document.getElementById('answers-select');
    select.innerHTML='';
    surveys.forEach(function(s){
        var op=document.createElement('option');
        op.value=s.id;
        op.textContent=s.title;
        select.appendChild(op);
    });
    renderAnswers();
}

function renderAnswers(){
    var s=findSurvey(document.getElementById('answers-select').value);
    if(!s)return;

    if(s.answers===0){
        document.getElementById('answers-content').innerHTML=
            '<div class="card"><div class="empty">まだ回答はありません。</div></div>';
        return;
    }

    var html='<div class="card">'+
        '<h2 style="margin-top:0">'+esc(s.title)+'</h2>'+
        '<div class="notice info">回答数：'+s.answers+'件</div>';

    var count=Math.min(s.answers,5);
    for(var i=1;i<=count;i++){
        html+='<div class="answer-card">'+
            '<h4>回答 #'+i+'　<span style="font-weight:normal;color:#8a96a3">2026/09/'+(10+i)+' 10:2'+i+'</span></h4>'+
            '<p><strong>現在の仕事に満足していますか？</strong><br>満足</p>'+
            '<p><strong>改善してほしいことがあれば教えてください。</strong><br>特にありません。</p>'+
            '</div>';
    }

    if(s.answers>5)
        html+='<p style="font-size:12px;color:#7a8792">ほか '+(s.answers-5)+' 件の回答があります。</p>';

    html+='</div>';
    document.getElementById('answers-content').innerHTML=html;
}

function confirmEnd(id){
    var s=findSurvey(id);
    openModal(
        '回答受付を終了します',
        '「'+s.title+'」の回答受付を終了します。終了すると新しい回答を受け付けなくなります。よろしいですか？',
        function(){
            s.status='ended';
            s.updated='2026/09/16';
            alert('回答受付を終了しました。');
            renderHome();
            renderList();
        }
    );
}

function confirmArchive(id){
    var s=findSurvey(id);
    openModal(
        'アンケートを保管します',
        '「'+s.title+'」を保管します。保管後も回答状況と回答内容は確認できます。',
        function(){
            s.status='archived';
            s.updated='2026/09/16';
            alert('アンケートを保管しました。');
            renderHome();
            renderList();
        }
    );
}

function confirmDelete(id){
    var s=findSurvey(id);
    openModal(
        'アンケートを削除します',
        '「'+s.title+'」を削除します。この操作は取り消せません。削除してよろしいですか？',
        function(){
            surveys=surveys.filter(function(x){return x.id!==id;});
            alert('アンケートを削除しました。');
            renderHome();
            renderList();
        }
    );
}

function openModal(title,message,action){
    document.getElementById('modal-title').textContent=title;
    document.getElementById('modal-message').textContent=message;
    modalAction=action;
    document.getElementById('confirm-modal').classList.add('show');
    document.getElementById('modal-ok').onclick=function(){
        closeModal();
        if(modalAction)modalAction();
    };
}

function closeModal(){
    document.getElementById('confirm-modal').classList.remove('show');
    modalAction=null;
}

function findSurvey(id){
    id=parseInt(id,10);
    for(var i=0;i<surveys.length;i++){
        if(parseInt(surveys[i].id,10)===id)return surveys[i];
    }
    return null;
}

function esc(str){
    return String(str||'')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function escAttr(str){
    return esc(str);
}

function nl2br(str){
    return esc(str).replace(/\n/g,'<br>');
}

/*
 * モック上で「回答開始待ち → 回答受付中」を確認するため、
 * 回答開始日時が現在時刻を過ぎたアンケートは状態を更新する。
 */
function updateWaitingStates(){
    var now=new Date();

    surveys.forEach(function(s){
        if(s.status==='waiting' && s.start){
            var d=new Date(s.start.replace(/\//g,'-'));
            if(!isNaN(d.getTime()) && d<=now){
                s.status='active';
            }
        }
    });
}

updateWaitingStates();
showPage('home');
</script>

</body>
</html>
<?php
/*
 * アンケート業務運営アプリ モック
 * 1ファイル完結
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
button:disabled{cursor:not-allowed;opacity:.5}

.topbar{
    height:60px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px;
}
.logo{font-size:18px;font-weight:bold;white-space:nowrap}
.main-nav{display:flex;height:100%;align-items:center;gap:2px}
.main-nav button{
    height:100%;
    padding:0 17px;
    color:#dce7f3;
    background:transparent;
    border:0;
}
.main-nav button:hover,.main-nav button.active{
    background:#31557f;color:#fff
}

.app{max-width:1440px;margin:0 auto;padding:24px}
.hidden{display:none!important}

.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:20px;
}
.page-header h1{margin:0;font-size:25px}
.subtext{color:#718096;font-size:13px;margin-top:5px}

.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px;
}
.btn:hover{background:#f7fafc}
.btn-primary{background:#2878c8;border-color:#2878c8;color:#fff}
.btn-primary:hover{background:#2068ad}
.btn-danger{border-color:#e05a5a;color:#c53f3f;background:#fff}
.btn-small{padding:5px 10px;font-size:12px}
.btn-success{background:#2f855a;border-color:#2f855a;color:#fff}

.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:18px;
}
.card-title{font-size:17px;font-weight:bold;margin-bottom:15px}
.card-title small{font-size:12px;color:#718096;font-weight:normal;margin-left:8px}

.table{width:100%;border-collapse:collapse}
.table th,.table td{
    padding:12px 10px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    vertical-align:middle;
    font-size:13px;
}
.table th{background:#f8fafc;color:#52606d;font-weight:bold}
.table tr:hover td{background:#fbfdff}
.empty{text-align:center!important;color:#8a98a5;padding:40px!important}
.link-button{
    border:0;background:none;padding:0;color:#2878c8;cursor:pointer;text-align:left
}
.link-button:hover{text-decoration:underline}

.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:12px;
    font-size:11px;
    font-weight:bold;
}
.badge-open{background:#e6f6ed;color:#237a49}
.badge-draft{background:#edf2f7;color:#66788a}
.badge-end{background:#fdecec;color:#b43b3b}
.badge-ok{background:#e6f6ed;color:#237a49}
.badge-warn{background:#fff5d9;color:#9a6800}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
.field{margin-bottom:15px}
.field:last-child{margin-bottom:0}
.field label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:6px;
    color:#455563;
}
.field input,.field textarea,.field select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:9px 10px;
    background:#fff;
}
.field textarea{min-height:90px;resize:vertical}
.radio-row{display:flex;gap:22px;flex-wrap:wrap}
.radio-row label{font-weight:normal;display:inline-flex;align-items:center;gap:5px}

.notice{
    padding:11px 13px;
    border-radius:5px;
    background:#edf6ff;
    border:1px solid #c9e2fa;
    color:#2b5f8a;
    font-size:13px;
    margin-bottom:15px;
}
.notice.success{background:#edf9f1;border-color:#c9ead5;color:#267348}
.notice.warning{background:#fff8e6;border-color:#f0dfae;color:#8a6408}

.editor-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-top:20px;
}
.editor-actions{display:flex;gap:8px}

.group-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    margin-bottom:16px;
}
.group-card.dragging{opacity:.45}
.group-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:13px 15px;
    background:#f7f9fb;
    border-bottom:1px solid #e3e8ed;
}
.group-title{flex:1}
.group-title input{
    border:1px solid transparent;
    background:transparent;
    padding:5px 7px;
    font-weight:bold;
    font-size:16px;
    width:100%;
}
.group-title input:focus{border-color:#cbd5e0;background:#fff}
.drag-handle{color:#9aa7b3;cursor:grab;font-size:18px}
.group-actions{display:flex;gap:5px}

.question-card{
    padding:15px;
    border-bottom:1px solid #e6ebef;
}
.question-card:last-child{border-bottom:0}
.question-card.dragging{opacity:.45}
.question-head{
    display:flex;
    align-items:center;
    gap:8px;
}
.question-number{
    width:58px;
    color:#2878c8;
    font-weight:bold;
    flex:none;
}
.question-title{flex:1}
.question-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px;
}
.question-tools{display:flex;gap:6px}
.question-tools select{
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:6px;
}
.question-meta{
    display:flex;
    gap:18px;
    align-items:center;
    margin-top:10px;
    padding-left:66px;
    color:#657786;
    font-size:13px;
}
.question-options{
    margin-top:12px;
    padding-left:66px;
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
    width:245px!important;
    flex:none;
}
.branch-label{
    font-size:11px;
    color:#718096;
    width:50px;
    flex:none;
}
.invalid-branch{
    border-color:#d9534f!important;
    background:#fff5f5!important;
}
.add-question-area{padding:12px 14px}
.add-group-area{text-align:center;margin-top:8px}

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
.stat-label{color:#718096;font-size:12px}
.stat-value{font-size:27px;font-weight:bold;margin-top:5px}
.stat-note{font-size:11px;color:#8a98a5;margin-top:3px}

.preview-question{
    padding:15px 0;
    border-bottom:1px solid #e6ebef;
}
.preview-question:last-child{border-bottom:0}
.preview-question-title{font-weight:bold;margin-bottom:9px}
.preview-option{margin:6px 0;color:#52606d}

.send-layout{
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:18px;
}
.customer-toolbar{
    display:flex;
    gap:8px;
    margin-bottom:12px;
}
.customer-toolbar input{flex:1}
.customer-toolbar input,.customer-toolbar select{
    border:1px solid #cbd5e0;border-radius:5px;padding:8px
}
.selection-summary{
    padding:10px 12px;
    background:#edf6ff;
    color:#2b5f8a;
    border-radius:5px;
    margin-bottom:12px;
    font-size:13px;
}
.email-preview{
    border:1px solid #dfe5eb;
    border-radius:6px;
    background:#fafbfc;
    padding:15px;
    white-space:pre-wrap;
    min-height:150px;
    font-size:13px;
}
.recipient-chip{
    display:inline-block;
    padding:5px 8px;
    margin:3px;
    border-radius:4px;
    background:#edf2f7;
    font-size:12px;
}

.progress{
    height:10px;
    background:#e8edf2;
    border-radius:5px;
    overflow:hidden;
    margin-top:8px;
}
.progress span{
    display:block;height:100%;background:#4285c5
}
.result-item{
    padding:18px;
    border-bottom:1px solid #e6ebef;
}
.result-item:last-child{border-bottom:0}
.bar{
    height:9px;background:#e8edf2;border-radius:5px;overflow:hidden;margin-top:6px
}
.bar span{display:block;height:100%;background:#4285c5}
.result-answer{
    padding:8px 10px;
    background:#f7f9fb;
    border:1px solid #e6ebef;
    border-radius:4px;
    margin:5px 0;
    font-size:13px;
}

.settings-tabs{
    display:flex;
    gap:5px;
    margin-bottom:18px;
}
.settings-tabs button{
    border:1px solid #d6dee6;
    background:#fff;
    padding:9px 16px;
    border-radius:5px;
}
.settings-tabs button.active{
    background:#2878c8;color:#fff;border-color:#2878c8
}

.status-line{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    background:#f7f9fb;
    border-radius:5px;
    margin-bottom:15px;
}
.status-dot{
    width:9px;height:9px;border-radius:50%;background:#9aa7b3
}
.status-dot.ok{background:#2f9e61}
.status-dot.warn{background:#d39b25}

.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(20,35,50,.45);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:1000;
}
.modal{
    width:min(760px,calc(100% - 30px));
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:8px;
    box-shadow:0 15px 50px rgba(0,0,0,.25);
}
.modal-header{
    padding:16px 20px;
    border-bottom:1px solid #e3e8ed;
    display:flex;
    justify-content:space-between;
}
.modal-body{padding:20px}
.modal-footer{
    padding:13px 20px;
    border-top:1px solid #e3e8ed;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

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
    z-index:2000;
}
.toast.show{opacity:1;transform:translateY(0)}

@media(max-width:950px){
    .send-layout{grid-template-columns:1fr}
    .detail-summary{grid-template-columns:1fr 1fr}
}
@media(max-width:800px){
    .topbar{padding:0 10px;gap:8px}
    .logo{font-size:15px}
    .main-nav button{padding:0 8px;font-size:12px}
    .app{padding:14px}
    .form-grid{grid-template-columns:1fr}
    .question-head{align-items:flex-start;flex-wrap:wrap}
    .question-title{min-width:70%}
    .question-meta,.question-options{padding-left:0}
    .branch-select{width:100%!important}
    .option-row{flex-wrap:wrap}
    .table{min-width:800px}
    .card{overflow-x:auto}
}
</style>
</head>

<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>
    <nav class="main-nav">
        <button id="nav-list" onclick="showList()">アンケート一覧</button>
        <button id="nav-create" onclick="openCreate()">アンケート作成</button>
        <button id="nav-customers" onclick="showCustomers()">顧客一覧</button>
        <button id="nav-settings" onclick="showSettings()">設定</button>
    </nav>
</header>

<main class="app">

<!-- =========================
     アンケート一覧
========================= -->
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
                    <th>作成日</th>
                    <th>公開期間</th>
                    <th>回答数</th>
                    <th>最終更新日</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody id="survey-list-body"></tbody>
        </table>
    </div>
</section>

<!-- =========================
     作成・編集
========================= -->
<section id="page-editor" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="editor-page-title">アンケート作成</h1>
            <div class="subtext">アンケート全体を1画面で編集できます</div>
        </div>
    </div>

    <div class="notice">
        質問とグループはドラッグ＆ドロップで並べ替えできます。質問番号は自動的に更新されます。
    </div>

    <div class="card">
        <div class="form-grid">
            <div class="field">
                <label>アンケート名 *</label>
                <input id="survey-name" type="text" placeholder="例：新商品アンケート">
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
            <textarea id="survey-description" placeholder="回答者への説明を入力してください"></textarea>
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
                    <input type="radio" name="numbering" value="global" onchange="changeNumbering(this.value)">
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

    <div class="editor-toolbar">
        <button class="btn" onclick="showList()">一覧へ戻る</button>
        <div class="editor-actions">
            <button class="btn" onclick="previewEditor()">内容確認</button>
            <button class="btn btn-primary" onclick="saveSurvey()">保存</button>
        </div>
    </div>
</section>

<!-- =========================
     個別アンケート
========================= -->
<section id="page-detail" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="detail-title"></h1>
            <div class="subtext" id="detail-subtitle"></div>
        </div>
        <div>
            <button class="btn" onclick="editCurrentSurvey()">編集</button>
            <button class="btn btn-primary" onclick="showDetailTab('send')">送信</button>
            <button class="btn" onclick="showList()">一覧へ戻る</button>
        </div>
    </div>

    <div class="detail-tabs">
        <button id="tab-content" onclick="showDetailTab('content')">アンケート内容</button>
        <button id="tab-send" onclick="showDetailTab('send')">送信</button>
        <button id="tab-status" onclick="showDetailTab('status')">回答状況</button>
        <button id="tab-result" onclick="showDetailTab('result')">回答結果</button>
    </div>

    <div id="detail-content" class="detail-content"></div>
</section>

<!-- =========================
     顧客一覧
========================= -->
<section id="page-customers" class="hidden">
    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">キントーンの顧客管理アプリから取得した顧客です</div>
        </div>
        <button class="btn" onclick="showSettings('kintone')">キントーン設定</button>
    </div>

    <div id="customer-status"></div>

    <div class="card">
        <div class="customer-toolbar">
            <input id="customer-search" placeholder="顧客名・メールアドレスで検索" oninput="renderCustomers()">
            <button class="btn" onclick="refreshCustomers()">顧客一覧を更新</button>
        </div>
        <table class="table">
            <thead>
                <tr>
                    <th>顧客名</th>
                    <th>メールアドレス</th>
                    <th>会社名</th>
                    <th>顧客番号</th>
                </tr>
            </thead>
            <tbody id="customer-body"></tbody>
        </table>
    </div>
</section>

<!-- =========================
     設定
========================= -->
<section id="page-settings" class="hidden">
    <div class="page-header">
        <div>
            <h1>設定</h1>
            <div class="subtext">メール送信と顧客一覧取得に必要な設定を管理します</div>
        </div>
    </div>

    <div class="settings-tabs">
        <button id="settings-tab-mail" onclick="showSettings('mail')">メール送信設定</button>
        <button id="settings-tab-kintone" onclick="showSettings('kintone')">キントーン設定</button>
    </div>

    <div id="settings-content"></div>
</section>

</main>

<!-- モーダル -->
<div id="modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <strong id="modal-title"></strong>
            <button class="btn btn-small" onclick="closeModal()">閉じる</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-footer" id="modal-footer"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
var surveys = [
    {
        id:1,
        name:'新商品アンケート',
        description:'新商品の利用状況とご意見をお聞きするアンケートです。',
        status:'open',
        created:'2026-09-01',
        start:'2026-09-01',
        end:'2026-09-30',
        answers:128,
        target:200,
        sent:195,
        updated:'2026-09-20',
        numbering:'global',
        groups:[
            {
                id:101,
                name:'ご利用状況',
                questions:[
                    {
                        id:1001,
                        text:'当社の商品を利用したことがありますか？',
                        type:'single',
                        required:true,
                        options:[
                            {text:'はい',branch:''},
                            {text:'いいえ',branch:'1003'}
                        ]
                    },
                    {
                        id:1002,
                        text:'商品についての満足度を教えてください。',
                        type:'single',
                        required:true,
                        options:[
                            {text:'満足',branch:''},
                            {text:'普通',branch:''},
                            {text:'不満',branch:'1003'}
                        ]
                    }
                ]
            },
            {
                id:102,
                name:'ご意見',
                questions:[
                    {
                        id:1003,
                        text:'今後の商品についてご意見をお聞かせください。',
                        type:'free',
                        required:false,
                        options:[]
                    }
                ]
            }
        ]
    },
    {
        id:2,
        name:'サービス利用後アンケート',
        description:'サービスをご利用いただいた感想をお聞きします。',
        status:'draft',
        created:'2026-09-10',
        start:'',
        end:'',
        answers:0,
        target:0,
        sent:0,
        updated:'2026-09-21',
        numbering:'group',
        groups:[
            {
                id:201,
                name:'サービスについて',
                questions:[
                    {
                        id:2001,
                        text:'サービスについての感想を教えてください。',
                        type:'multiple',
                        required:false,
                        options:[
                            {text:'便利だった',branch:''},
                            {text:'分かりやすかった',branch:''},
                            {text:'また利用したい',branch:''}
                        ]
                    }
                ]
            }
        ]
    }
];

var customers = [
    {id:1,name:'山田 太郎',email:'taro.yamada@example.com',company:'株式会社サンプル',code:'C0001'},
    {id:2,name:'佐藤 花子',email:'hanako.sato@example.com',company:'株式会社サンプル',code:'C0002'},
    {id:3,name:'鈴木 一郎',email:'ichiro.suzuki@example.com',company:'株式会社テスト',code:'C0003'},
    {id:4,name:'田中 美咲',email:'misaki.tanaka@example.com',company:'株式会社テスト',code:'C0004'},
    {id:5,name:'高橋 健',email:'ken.takahashi@example.com',company:'有限会社デモ',code:'C0005'},
    {id:6,name:'伊藤 明',email:'akira.ito@example.com',company:'有限会社デモ',code:'C0006'}
];

var mailSettings = {
    smtp:'',
    port:'587',
    security:'STARTTLS',
    username:'',
    password:'',
    from:'',
    fromName:'アンケート事務局',
    ready:false
};

var kintoneSettings = {
    domain:'',
    appId:'',
    loginName:'',
    password:'',
    proxyHost:'',
    proxyPort:'',
    proxyAuth:false,
    sslVerify:false,
    ready:false
};

var editingSurvey = null;
var currentSurveyId = null;
var currentSettingsTab = 'mail';
var nextGroupId = 500;
var nextQuestionId = 5000;
var draggedQuestion = null;
var draggedGroup = null;
var selectedCustomers = [];

function $(id){return document.getElementById(id)}

function escapeHtml(str){
    return String(str || '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function showPage(id){
    ['page-list','page-editor','page-detail','page-customers','page-settings'].forEach(function(x){
        $(x).classList.add('hidden');
    });
    $(id).classList.remove('hidden');

    ['nav-list','nav-create','nav-customers','nav-settings'].forEach(function(x){
        $(x).classList.remove('active');
    });

    if(id === 'page-list') $('nav-list').classList.add('active');
    if(id === 'page-editor') $('nav-create').classList.add('active');
    if(id === 'page-customers') $('nav-customers').classList.add('active');
    if(id === 'page-settings') $('nav-settings').classList.add('active');
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
        body.innerHTML='<tr><td colspan="7" class="empty">アンケートがありません。</td></tr>';
        return;
    }

    body.innerHTML = surveys.map(function(s){
        var period = s.start || s.end
            ? escapeHtml(s.start || '未設定') + ' ～ ' + escapeHtml(s.end || '未設定')
            : '未設定';

        return '<tr>' +
            '<td><button class="link-button" onclick="openDetail('+s.id+')">'+escapeHtml(s.name)+'</button></td>' +
            '<td>'+statusBadge(s.status)+'</td>' +
            '<td>'+escapeHtml(s.created || '')+'</td>' +
            '<td>'+period+'</td>' +
            '<td>'+s.answers+'件</td>' +
            '<td>'+escapeHtml(s.updated || '')+'</td>' +
            '<td>' +
            '<button class="btn btn-small" onclick="editSurvey('+s.id+')">編集</button> ' +
            '<button class="btn btn-small" onclick="openDetail('+s.id+')">確認</button> ' +
            (s.status === 'draft'
                ? '<button class="btn btn-small btn-success" onclick="publishSurvey('+s.id+')">公開</button> '
                : '') +
            (s.status === 'open'
                ? '<button class="btn btn-small btn-danger" onclick="endSurvey('+s.id+')">終了</button> '
                : '') +
            (s.status === 'draft'
                ? '<button class="btn btn-small btn-danger" onclick="deleteSurvey('+s.id+')">削除</button>'
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
        id:null,
        name:'',
        description:'',
        status:'draft',
        created:'',
        start:'',
        end:'',
        answers:0,
        target:0,
        sent:0,
        updated:'',
        numbering:'global',
        groups:[
            {
                id:nextGroupId++,
                name:'グループ1',
                questions:[
                    {
                        id:nextQuestionId++,
                        text:'',
                        type:'free',
                        required:false,
                        options:[]
                    }
                ]
            }
        ]
    };

    $('editor-page-title').textContent='アンケート作成';
    loadEditor();
    showPage('page-editor');
}

function editSurvey(id){
    var survey = surveys.find(function(s){return s.id === id});
    if(!survey)return;

    editingSurvey=cloneSurvey(survey);
    $('editor-page-title').textContent='アンケート編集';
    loadEditor();
    showPage('page-editor');
}

function editCurrentSurvey(){
    if(currentSurveyId !== null)editSurvey(currentSurveyId);
}

function loadEditor(){
    $('survey-name').value=editingSurvey.name || '';
    $('survey-description').value=editingSurvey.description || '';
    $('survey-status').value=editingSurvey.status || 'draft';
    $('survey-start').value=editingSurvey.start || '';
    $('survey-end').value=editingSurvey.end || '';

    document.querySelectorAll('input[name="numbering"]').forEach(function(r){
        r.checked=(r.value===editingSurvey.numbering);
    });

    renderEditor();
}

function changeNumbering(value){
    if(!editingSurvey)return;
    editingSurvey.numbering=value;
    renderEditor();
}

function renderEditor(){
    var html='';

    editingSurvey.groups.forEach(function(group,gi){
        html += '<div class="group-card" draggable="true" data-group-id="'+group.id+'" ' +
            'ondragstart="dragGroupStart(event,'+group.id+')" ' +
            'ondragover="allowDrop(event)" ' +
            'ondrop="dropGroup(event,'+group.id+')">';

        html += '<div class="group-header">';
        html += '<span class="drag-handle" title="ドラッグしてグループを移動">☷</span>';
        html += '<div class="group-title">';
        html += '<input value="'+escapeHtml(group.name)+'" oninput="updateGroupName('+group.id+',this.value)">';
        html += '</div>';
        html += '<div class="group-actions">';
        html += '<button class="btn btn-small btn-danger" onclick="deleteGroup('+group.id+')">グループ削除</button>';
        html += '</div>';
        html += '</div>';

        html += '<div class="questions">';

        group.questions.forEach(function(q,qi){
            html += renderQuestion(group,q,getQuestionNumber(gi,qi));
        });

        html += '</div>';

        html += '<div class="add-question-area">';
        html += '<button class="btn btn-small btn-primary" onclick="addQuestion('+group.id+')">＋ 質問追加</button>';
        html += '</div>';

        html += '</div>';
    });

    $('groups').innerHTML=html;
}

function getQuestionNumber(groupIndex,questionIndex){
    if(editingSurvey.numbering==='group'){
        return 'Q'+(groupIndex+1)+'-'+(questionIndex+1);
    }

    var n=0;
    for(var i=0;i<groupIndex;i++){
        n += editingSurvey.groups[i].questions.length;
    }
    return 'Q'+(n+questionIndex+1);
}

function findQuestion(id){
    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        for(var qi=0;qi<editingSurvey.groups[gi].questions.length;qi++){
            if(String(editingSurvey.groups[gi].questions[qi].id)===String(id)){
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

function getQuestionLabelById(id){
    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        for(var qi=0;qi<editingSurvey.groups[gi].questions.length;qi++){
            var q=editingSurvey.groups[gi].questions[qi];
            if(String(q.id)===String(id)){
                return getQuestionNumber(gi,qi)+'：'+(q.text || '（未入力）');
            }
        }
    }
    return '';
}

function getBranchOptions(currentQuestionId,currentBranch){
    var html='';
    html += '<option value="">通常の次の質問へ</option>';
    html += '<option value="END"'+(currentBranch==='END'?' selected':'')+'>アンケート終了</option>';

    editingSurvey.groups.forEach(function(g,gi){
        g.questions.forEach(function(target,ti){
            if(String(target.id)!==String(currentQuestionId)){
                var selected=String(currentBranch)===String(target.id)?' selected':'';
                html += '<option value="'+target.id+'"'+selected+'>'+
                    escapeHtml(getQuestionNumber(gi,ti)+'：'+(target.text || '（未入力）'))+
                    '</option>';
            }
        });
    });

    return html;
}

function renderQuestion(group,q,qNo){
    var typeLabel={
        free:'自由記述',
        single:'単一選択',
        multiple:'複数選択'
    }[q.type];

    var html='';

    html += '<div class="question-card" draggable="true" ' +
        'ondragstart="dragQuestionStart(event,'+group.id+','+q.id+')" ' +
        'ondragover="allowDrop(event)" ' +
        'ondrop="dropQuestion(event,'+group.id+','+q.id+')">';

    html += '<div class="question-head">';
    html += '<span class="drag-handle" title="ドラッグして質問を移動">☷</span>';
    html += '<span class="question-number">'+qNo+'</span>';
    html += '<div class="question-title">';
    html += '<input placeholder="質問文を入力してください" value="'+escapeHtml(q.text)+'" oninput="updateQuestionText('+q.id+',this.value)">';
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

    if(q.type==='single' || q.type==='multiple'){
        html += '<div class="question-options">';
        html += '<div style="font-size:12px;color:#718096;margin-bottom:7px;">選択肢</div>';

        q.options.forEach(function(opt,oi){
            html += '<div class="option-row">';
            html += '<span style="width:18px;color:#718096;">'+(oi+1)+'.</span>';
            html += '<input value="'+escapeHtml(opt.text)+'" oninput="updateOption('+q.id+','+oi+',this.value)">';

            if(q.type==='single'){
                var branchValue=opt.branch || '';
                var invalid='';
                if(branchValue && branchValue!=='END' && !getQuestionLabelById(branchValue)){
                    invalid=' invalid-branch';
                }

                html += '<span class="branch-label">分岐先</span>';
                html += '<select class="branch-select'+invalid+'" onchange="updateBranch('+q.id+','+oi+',this.value)">';
                html += getBranchOptions(q.id,branchValue);
                html += '</select>';

                if(invalid){
                    html += '<span style="font-size:11px;color:#c53f3f">分岐先が存在しません</span>';
                }
            }

            html += '<button class="btn btn-small btn-danger" onclick="deleteOption('+q.id+','+oi+')">削除</button>';
            html += '</div>';
        });

        html += '<button class="btn btn-small" onclick="addOption('+q.id+')">＋ 選択肢追加</button>';

        if(q.type==='single'){
            html += '<div style="font-size:12px;color:#718096;margin-top:8px;">単一選択では、選択肢ごとに「通常の次の質問」「指定した質問」「アンケート終了」を設定できます。</div>';
        }

        html += '</div>';
    }

    html += '</div>';
    return html;
}

function updateGroupName(id,value){
    var g=editingSurvey.groups.find(function(x){return x.id==id});
    if(g)g.name=value;
}
function updateQuestionText(id,value){
    var f=findQuestion(id);
    if(f)f.question.text=value;
}
function updateQuestionType(id,value){
    var f=findQuestion(id);
    if(!f)return;

    f.question.type=value;

    if(value==='free'){
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
    if(f)f.question.required=value;
}
function updateOption(qid,index,value){
    var f=findQuestion(qid);
    if(f && f.question.options[index])f.question.options[index].text=value;
}
function updateBranch(qid,index,value){
    var f=findQuestion(qid);
    if(f && f.question.options[index])f.question.options[index].branch=value;
}

function addOption(qid){
    var f=findQuestion(qid);
    if(!f)return;

    f.question.options.push({
        text:'選択肢'+(f.question.options.length+1),
        branch:''
    });
    renderEditor();
}

function deleteOption(qid,index){
    var f=findQuestion(qid);
    if(!f)return;
    if(f.question.options.length<=1){
        showToast('選択肢は1つ以上必要です');
        return;
    }
    f.question.options.splice(index,1);
    renderEditor();
}

function addGroup(){
    editingSurvey.groups.push({
        id:nextGroupId++,
        name:'新しいグループ',
        questions:[
            {
                id:nextQuestionId++,
                text:'',
                type:'free',
                required:false,
                options:[]
            }
        ]
    });
    renderEditor();
}

function deleteGroup(id){
    var idx=editingSurvey.groups.findIndex(function(g){return g.id==id});
    if(idx<0)return;

    if(editingSurvey.groups.length<=1){
        showToast('グループは1つ以上必要です');
        return;
    }

    var count=editingSurvey.groups[idx].questions.length;
    var msg='このグループを削除しますか？';
    if(count>0)msg+='\\nグループ内の質問'+count+'件も削除されます。';

    if(!confirm(msg))return;

    editingSurvey.groups.splice(idx,1);
    renderEditor();
}

function addQuestion(groupId){
    var g=editingSurvey.groups.find(function(x){return x.id==groupId});
    if(!g)return;

    g.questions.push({
        id:nextQuestionId++,
        text:'',
        type:'free',
        required:false,
        options:[]
    });
    renderEditor();
}

function deleteQuestion(groupId,qid){
    var g=editingSurvey.groups.find(function(x){return x.id==groupId});
    if(!g)return;

    if(!confirm('この質問を削除しますか？'))return;

    var idx=g.questions.findIndex(function(q){return q.id==qid});
    if(idx>=0)g.questions.splice(idx,1);

    renderEditor();
}

function dragGroupStart(e,id){
    draggedGroup=id;
    e.dataTransfer.effectAllowed='move';
}
function dragQuestionStart(e,groupId,qid){
    draggedQuestion={groupId:groupId,qid:qid};
    e.dataTransfer.effectAllowed='move';
}
function allowDrop(e){
    e.preventDefault();
    e.dataTransfer.dropEffect='move';
}

function dropGroup(e,targetId){
    e.preventDefault();
    if(draggedGroup===null || draggedGroup==targetId)return;

    var from=editingSurvey.groups.findIndex(function(g){return g.id==draggedGroup});
    var to=editingSurvey.groups.findIndex(function(g){return g.id==targetId});
    if(from<0 || to<0)return;

    var item=editingSurvey.groups.splice(from,1)[0];
    editingSurvey.groups.splice(to,0,item);
    draggedGroup=null;
    renderEditor();
}

function dropQuestion(e,targetGroupId,targetQid){
    e.preventDefault();
    if(!draggedQuestion)return;

    var sourceGroup=editingSurvey.groups.find(function(g){return g.id==draggedQuestion.groupId});
    var targetGroup=editingSurvey.groups.find(function(g){return g.id==targetGroupId});
    if(!sourceGroup || !targetGroup)return;

    var sourceIndex=sourceGroup.questions.findIndex(function(q){return q.id==draggedQuestion.qid});
    var targetIndex=targetGroup.questions.findIndex(function(q){return q.id==targetQid});
    if(sourceIndex<0 || targetIndex<0)return;

    var q=sourceGroup.questions.splice(sourceIndex,1)[0];

    if(sourceGroup.id===targetGroup.id && sourceIndex<targetIndex){
        targetIndex--;
    }

    targetGroup.questions.splice(targetIndex,0,q);
    draggedQuestion=null;
    renderEditor();
}

function validateSurvey(s){
    var errors=[];

    if(!s.name.trim())errors.push('アンケート名を入力してください。');
    if(s.start && s.end && s.start>s.end)errors.push('公開開始日は公開終了日以前にしてください。');

    s.groups.forEach(function(g,gi){
        if(!g.name.trim())errors.push('グループ'+(gi+1)+'の名前を入力してください。');

        g.questions.forEach(function(q,qi){
            if(!q.text.trim())errors.push(getQuestionNumber(gi,qi)+'の質問文を入力してください。');

            if((q.type==='single' || q.type==='multiple') && q.options.length===0){
                errors.push(getQuestionNumber(gi,qi)+'に選択肢を設定してください。');
            }

            q.options.forEach(function(o,oi){
                if(!o.text.trim())errors.push(getQuestionNumber(gi,qi)+'の選択肢'+(oi+1)+'を入力してください。');

                if(q.type==='single' && o.branch && o.branch!=='END'){
                    if(!getQuestionLabelById(o.branch)){
                        errors.push(getQuestionNumber(gi,qi)+'の分岐先が存在しません。');
                    }
                }
            });
        });
    });

    return errors;
}

function saveSurvey(){
    editingSurvey.name=$('survey-name').value;
    editingSurvey.description=$('survey-description').value;
    editingSurvey.status=$('survey-status').value;
    editingSurvey.start=$('survey-start').value;
    editingSurvey.end=$('survey-end').value;

    var errors=validateSurvey(editingSurvey);

    if(errors.length){
        alert('以下を確認してください。\\n\\n・'+errors.join('\\n・'));
        return;
    }

    var now='2026-09-24';

    if(editingSurvey.id===null){
        editingSurvey.id=Date.now();
        editingSurvey.created=now;
        editingSurvey.updated=now;
        surveys.push(cloneSurvey(editingSurvey));
        currentSurveyId=editingSurvey.id;
        showToast('アンケートを保存しました');
    }else{
        editingSurvey.updated=now;
        var idx=surveys.findIndex(function(s){return s.id==editingSurvey.id});
        if(idx>=0)surveys[idx]=cloneSurvey(editingSurvey);
        currentSurveyId=editingSurvey.id;
        showToast('アンケートを更新しました');
    }

    renderEditor();
}

function previewEditor(){
    editingSurvey.name=$('survey-name').value;
    editingSurvey.description=$('survey-description').value;
    editingSurvey.status=$('survey-status').value;
    editingSurvey.start=$('survey-start').value;
    editingSurvey.end=$('survey-end').value;

    var errors=validateSurvey(editingSurvey);
    if(errors.length){
        alert('内容確認の前に以下を確認してください。\\n\\n・'+errors.join('\\n・'));
        return;
    }

    var html='<div class="card">';
    html+='<h3 style="margin-top:0">'+escapeHtml(editingSurvey.name)+'</h3>';
    html+='<p style="color:#687887">'+escapeHtml(editingSurvey.description)+'</p>';

    editingSurvey.groups.forEach(function(g,gi){
        html+='<h4 style="border-bottom:1px solid #e6ebef;padding-bottom:8px">'+escapeHtml(g.name)+'</h4>';

        g.questions.forEach(function(q,qi){
            html+='<div class="preview-question">';
            html+='<div class="preview-question-title">'+getQuestionNumber(gi,qi)+'　'+escapeHtml(q.text);
            if(q.required)html+=' <span class="badge badge-warn">必須</span>';
            html+='</div>';

            if(q.type==='free'){
                html+='<div style="color:#8a98a5">自由記述</div>';
            }else{
                q.options.forEach(function(o){
                    html+='<div class="preview-option">・'+escapeHtml(o.text);
                    if(q.type==='single' && o.branch){
                        html+=' → '+(o.branch==='END'?'アンケート終了':escapeHtml(getQuestionLabelById(o.branch)));
                    }
                    html+='</div>';
                });
            }
            html+='</div>';
        });
    });

    html+='</div>';

    openModal('アンケート内容確認',html,
        '<button class="btn" onclick="closeModal()">閉じる</button>');
}

function openDetail(id){
    currentSurveyId=id;
    showDetailTab('content');
    showPage('page-detail');
}

function getCurrentSurvey(){
    return surveys.find(function(s){return s.id==currentSurveyId});
}

function showDetailTab(tab){
    var s=getCurrentSurvey();
    if(!s)return;

    ['content','send','status','result'].forEach(function(x){
        var b=$('tab-'+x);
        if(b)b.classList.remove('active');
    });

    var active=$('tab-'+tab);
    if(active)active.classList.add('active');

    if(tab==='content')renderDetailContent(s);
    if(tab==='send')renderSend(s);
    if(tab==='status')renderStatus(s);
    if(tab==='result')renderResult(s);
}

function renderDetailContent(s){
    var html='';

    html+='<div class="card">';
    html+='<div class="card-title">アンケート基本情報</div>';
    html+='<div class="form-grid">';
    html+='<div><strong>説明</strong><div style="margin-top:6px;color:#687887">'+escapeHtml(s.description || 'なし')+'</div></div>';
    html+='<div><strong>公開期間</strong><div style="margin-top:6px">'+escapeHtml(s.start || '未設定')+' ～ '+escapeHtml(s.end || '未設定')+'</div></div>';
    html+='</div>';
    html+='<div style="margin-top:15px"><strong>質問番号：</strong>'+(s.numbering==='group'?'グループごと':'全体で通番')+'</div>';
    html+='</div>';

    html+='<div class="card">';
    html+='<div class="card-title">質問内容</div>';

    s.groups.forEach(function(g,gi){
        html+='<h3 style="font-size:16px;border-bottom:1px solid #e6ebef;padding-bottom:8px">'+escapeHtml(g.name)+'</h3>';

        g.questions.forEach(function(q,qi){
            html+='<div class="preview-question">';
            html+='<div class="preview-question-title">'+getSavedQuestionNumber(s,gi,qi)+'　'+escapeHtml(q.text);
            if(q.required)html+=' <span class="badge badge-warn">必須</span>';
            html+='</div>';

            html+='<div style="font-size:12px;color:#718096">回答形式：'+
                ({free:'自由記述',single:'単一選択',multiple:'複数選択'}[q.type])+
                '</div>';

            q.options.forEach(function(o){
                html+='<div class="preview-option">・'+escapeHtml(o.text);

                if(q.type==='single' && o.branch){
                    var branchLabel='';
                    if(o.branch==='END'){
                        branchLabel='アンケート終了';
                    }else{
                        branchLabel=getSavedQuestionLabel(s,o.branch);
                    }
                    html+=' <span style="color:#2878c8">→ '+escapeHtml(branchLabel || '存在しない質問')+'</span>';
                }

                html+='</div>';
            });

            html+='</div>';
        });
    });

    html+='</div>';

    $('detail-content').innerHTML=html;
}

function getSavedQuestionNumber(s,gi,qi){
    if(s.numbering==='group')return 'Q'+(gi+1)+'-'+(qi+1);

    var n=0;
    for(var i=0;i<gi;i++)n+=s.groups[i].questions.length;
    return 'Q'+(n+qi+1);
}

function getSavedQuestionLabel(s,id){
    for(var gi=0;gi<s.groups.length;gi++){
        for(var qi=0;qi<s.groups[gi].questions.length;qi++){
            if(String(s.groups[gi].questions[qi].id)===String(id)){
                return getSavedQuestionNumber(s,gi,qi)+'：'+s.groups[gi].questions[qi].text;
            }
        }
    }
    return '';
}

function renderSend(s){
    selectedCustomers=[];
    var html='';

    if(s.status!=='open'){
        html+='<div class="notice warning">このアンケートは現在「'+
            (s.status==='draft'?'下書き':'終了')+
            '」です。公開中のアンケートのみ送信できます。</div>';
    }

    if(!mailSettings.ready){
        html+='<div class="notice warning">メール送信設定が未完了です。送信する前に設定画面でSMTP設定を保存してください。</div>';
    }

    html+='<div class="send-layout">';

    html+='<div class="card">';
    html+='<div class="card-title">送信対象者</div>';
    html+='<div class="selection-summary">選択中：<strong id="selected-count">0</strong>名</div>';

    html+='<div class="customer-toolbar">';
    html+='<input id="send-customer-search" placeholder="顧客名・メールアドレスで検索" oninput="renderSendCustomers()">';
    html+='<button class="btn btn-small" onclick="selectAllVisibleCustomers()">表示中を全選択</button>';
    html+='</div>';

    html+='<div id="send-customer-list"></div>';
    html+='</div>';

    html+='<div class="card">';
    html+='<div class="card-title">メール内容</div>';

    html+='<div class="field">';
    html+='<label>件名</label>';
    html+='<input id="send-subject" value="'+escapeHtml(s.name+' ご回答のお願い')+'">';
    html+='</div>';

    html+='<div class="field">';
    html+='<label>本文</label>';
    html+='<textarea id="send-body" style="min-height:210px">いつもお世話になっております。\\n\\n「'+escapeHtml(s.name)+'」へのご協力をお願いいたします。\\n\\n下記の案内からアンケートへアクセスしてご回答ください。\\n\\n回答期限：'+escapeHtml(s.end || '設定なし')+'\\n\\nよろしくお願いいたします。</textarea>';
    html+='</div>';

    html+='<div class="notice">顧客一覧に登録されていない方でも、案内からアンケートへアクセスすれば回答できます。</div>';

    html+='<button class="btn btn-primary" onclick="confirmSend()">送信内容を確認</button>';
    html+='</div>';

    html+='</div>';

    $('detail-content').innerHTML=html;
    renderSendCustomers();
}

function renderSendCustomers(){
    var search=($('send-customer-search') ? $('send-customer-search').value : '').toLowerCase();
    var target=$('send-customer-list');
    if(!target)return;

    var filtered=customers.filter(function(c){
        return !search ||
            c.name.toLowerCase().indexOf(search)>=0 ||
            c.email.toLowerCase().indexOf(search)>=0 ||
            c.company.toLowerCase().indexOf(search)>=0;
    });

    var html='<table class="table">';
    html+='<thead><tr><th style="width:40px"></th><th>顧客名</th><th>メールアドレス</th><th>会社名</th></tr></thead><tbody>';

    filtered.forEach(function(c){
        var checked=selectedCustomers.indexOf(c.id)>=0?' checked':'';

        html+='<tr>';
        html+='<td><input type="checkbox"'+checked+' onchange="toggleCustomer('+c.id+',this.checked)"></td>';
        html+='<td>'+escapeHtml(c.name)+'</td>';
        html+='<td>'+escapeHtml(c.email)+'</td>';
        html+='<td>'+escapeHtml(c.company)+'</td>';
        html+='</tr>';
    });

    html+='</tbody></table>';

    if(!filtered.length)html='<div class="empty">該当する顧客がありません。</div>';

    target.innerHTML=html;

    if($('selected-count'))$('selected-count').textContent=selectedCustomers.length;
}

function toggleCustomer(id,checked){
    var idx=selectedCustomers.indexOf(id);

    if(checked && idx<0)selectedCustomers.push(id);
    if(!checked && idx>=0)selectedCustomers.splice(idx,1);

    if($('selected-count'))$('selected-count').textContent=selectedCustomers.length;
}

function selectAllVisibleCustomers(){
    var search=($('send-customer-search') ? $('send-customer-search').value : '').toLowerCase();

    customers.forEach(function(c){
        var visible=!search ||
            c.name.toLowerCase().indexOf(search)>=0 ||
            c.email.toLowerCase().indexOf(search)>=0 ||
            c.company.toLowerCase().indexOf(search)>=0;

        if(visible && selectedCustomers.indexOf(c.id)<0)selectedCustomers.push(c.id);
    });

    renderSendCustomers();
}

function confirmSend(){
    var s=getCurrentSurvey();

    if(s.status!=='open'){
        alert('公開中のアンケートのみ送信できます。');
        return;
    }

    if(!mailSettings.ready){
        alert('メール送信設定を完了してください。');
        return;
    }

    if(selectedCustomers.length===0){
        alert('送信対象者を1名以上選択してください。');
        return;
    }

    var subject=$('send-subject').value;
    var body=$('send-body').value;

    var recipients=customers.filter(function(c){
        return selectedCustomers.indexOf(c.id)>=0;
    });

    var html='<div class="notice">以下の内容でメールを送信します。</div>';
    html+='<p><strong>アンケート：</strong>'+escapeHtml(s.name)+'</p>';
    html+='<p><strong>送信対象者：</strong>'+recipients.length+'名</p>';

    html+='<div style="margin:10px 0">';
    recipients.forEach(function(c){
        html+='<span class="recipient-chip">'+escapeHtml(c.name)+' &lt;'+escapeHtml(c.email)+'&gt;</span>';
    });
    html+='</div>';

    html+='<p><strong>件名：</strong>'+escapeHtml(subject)+'</p>';
    html+='<div class="email-preview">'+escapeHtml(body)+'</div>';

    html+='<div class="notice" style="margin-top:15px">送信先にはアンケート回答用の案内が含まれます。</div>';

    openModal(
        'アンケート送信確認',
        html,
        '<button class="btn" onclick="closeModal()">戻る</button>'+
        '<button class="btn btn-primary" onclick="sendSurveyMail()">メールを送信する</button>'
    );
}

function sendSurveyMail(){
    var s=getCurrentSurvey();
    var count=selectedCustomers.length;

    closeModal();

    var html='<div class="notice success">メール送信が完了しました。</div>';
    html+='<div class="card">';
    html+='<div class="card-title">送信結果</div>';
    html+='<p>アンケート：'+escapeHtml(s.name)+'</p>';
    html+='<p>送信対象：'+count+'名</p>';
    html+='<p style="color:#267348">送信成功：'+count+'名</p>';
    html+='<p style="color:#718096">送信失敗：0名</p>';
    html+='<div class="progress"><span style="width:100%"></span></div>';
    html+='</div>';

    s.target=Math.max(s.target,count);
    s.sent+=count;

    $('detail-content').innerHTML=html;
    showToast('メールを送信しました');
}

function renderStatus(s){
    var target=s.target || 0;
    var answers=s.answers || 0;
    var rate=target ? Math.round(answers/target*100) : 0;
    var unanswered=Math.max(target-answers,0);

    var html='<div class="detail-summary">';
    html+=statCard('回答数',answers+'件','');
    html+=statCard('回答率',rate+'%','');
    html+=statCard('未回答数',unanswered+'名','');
    html+=statCard('送信済み',s.sent+'名','');
    html+='</div>';

    html+='<div class="card">';
    html+='<div class="card-title">公開・回答状況</div>';
    html+='<p>公開期間：'+escapeHtml(s.start || '未設定')+' ～ '+escapeHtml(s.end || '未設定')+'</p>';
    html+='<p>メール送信対象者：'+target+'名</p>';
    html+='<p>メール送信済み：'+s.sent+'名</p>';
    html+='<p>未回答者：'+unanswered+'名</p>';
    html+='<div style="margin-top:18px">回答率</div>';
    html+='<div class="progress"><span style="width:'+Math.min(rate,100)+'%"></span></div>';
    html+='<div style="text-align:right;font-size:12px;color:#718096;margin-top:5px">'+rate+'%</div>';
    html+='</div>';

    html+='<div class="card">';
    html+='<div class="card-title">回答状況の推移</div>';
    html+='<div style="height:180px;display:flex;align-items:flex-end;gap:12px;border-bottom:1px solid #dfe5eb;padding:15px">';
    var vals=[18,29,42,56,72,91,108,answers];
    vals.forEach(function(v,i){
        var h=answers ? Math.max(8,Math.round(v/answers*140)) : 8;
        html+='<div style="flex:1;text-align:center">';
        html+='<div style="height:'+h+'px;background:#4285c5;border-radius:4px 4px 0 0"></div>';
        html+='<div style="font-size:10px;color:#718096;margin-top:5px">9/'+(17+i)+'</div>';
        html+='</div>';
    });
    html+='</div></div>';

    $('detail-content').innerHTML=html;
}

function statCard(label,value,note){
    return '<div class="stat-card">'+
        '<div class="stat-label">'+label+'</div>'+
        '<div class="stat-value">'+value+'</div>'+
        '<div class="stat-note">'+note+'</div>'+
        '</div>';
}

function renderResult(s){
    var html='';

    html+='<div class="detail-summary">';
    html+=statCard('総回答数',s.answers+'件','');
    html+=statCard('回答者数',s.answers+'名','');
    html+=statCard('質問数',countQuestions(s)+'問','');
    html+=statCard('集計対象',s.answers+'件','');
    html+='</div>';

    html+='<div class="card">';
    html+='<div class="card-title">質問ごとの回答結果</div>';

    s.groups.forEach(function(g,gi){
        html+='<h3 style="font-size:16px;border-bottom:1px solid #e6ebef;padding-bottom:8px">'+escapeHtml(g.name)+'</h3>';

        g.questions.forEach(function(q,qi){
            var qNo=getSavedQuestionNumber(s,gi,qi);

            html+='<div class="result-item">';
            html+='<div style="font-weight:bold">'+qNo+'　'+escapeHtml(q.text)+'</div>';

            if(q.type==='single'){
                var vals=[Math.round(s.answers*.52),Math.round(s.answers*.31),Math.max(0,s.answers-Math.round(s.answers*.52)-Math.round(s.answers*.31))];
                q.options.forEach(function(o,oi){
                    var count=vals[oi] || Math.round(s.answers/q.options.length);
                    var pct=s.answers ? Math.round(count/s.answers*100) : 0;

                    html+='<div style="margin-top:13px">';
                    html+='<div style="display:flex;justify-content:space-between;font-size:13px">';
                    html+='<span>'+escapeHtml(o.text)+'</span><span>'+count+'件（'+pct+'%）</span>';
                    html+='</div>';
                    html+='<div class="bar"><span style="width:'+pct+'%"></span></div>';
                    html+='</div>';
                });
            }else if(q.type==='multiple'){
                q.options.forEach(function(o,oi){
                    var count=Math.round(s.answers*(.7-(oi*.12)));
                    var pct=s.answers ? Math.round(count/s.answers*100) : 0;

                    html+='<div style="margin-top:13px">';
                    html+='<div style="display:flex;justify-content:space-between;font-size:13px">';
                    html+='<span>'+escapeHtml(o.text)+'</span><span>'+count+'回（'+pct+'%）</span>';
                    html+='</div>';
                    html+='<div class="bar"><span style="width:'+Math.min(pct,100)+'%"></span></div>';
                    html+='</div>';
                });
            }else{
                html+='<div style="margin-top:12px">';
                html+='<div class="result-answer">「とても参考になりました。今後も利用したいです。」</div>';
                html+='<div class="result-answer">「もう少し選択肢があると回答しやすいと思います。」</div>';
                html+='<div class="result-answer">「商品の説明が分かりやすかったです。」</div>';
                html+='</div>';
            }

            html+='</div>';
        });
    });

    html+='</div>';

    $('detail-content').innerHTML=html;
}

function countQuestions(s){
    var n=0;
    s.groups.forEach(function(g){n+=g.questions.length});
    return n;
}

function publishSurvey(id){
    var s=surveys.find(function(x){return x.id==id});
    if(!s)return;

    if(!s.name || !countQuestions(s)){
        alert('公開するにはアンケート内容を設定してください。');
        return;
    }

    if(!confirm('「'+s.name+'」を公開しますか？'))return;

    s.status='open';
    s.updated='2026-09-24';
    showToast('アンケートを公開しました');
    renderList();
}

function endSurvey(id){
    var s=surveys.find(function(x){return x.id==id});
    if(!s)return;

    if(!confirm('「'+s.name+'」の回答受付を終了しますか？'))return;

    s.status='end';
    s.updated='2026-09-24';
    showToast('アンケートを終了しました');
    renderList();
}

function deleteSurvey(id){
    var s=surveys.find(function(x){return x.id==id});
    if(!s)return;

    if(!confirm('下書き「'+s.name+'」を削除しますか？'))return;

    surveys=surveys.filter(function(x){return x.id!=id});
    showToast('アンケートを削除しました');
    renderList();
}

function showCustomers(){
    showPage('page-customers');
    renderCustomers();
    renderCustomerStatus();
}

function renderCustomerStatus(){
    var el=$('customer-status');

    if(kintoneSettings.ready){
        el.innerHTML='<div class="notice success">キントーンから顧客一覧を取得できる状態です。現在 '+customers.length+' 件の顧客を表示しています。</div>';
    }else{
        el.innerHTML='<div class="notice warning">キントーン設定が未完了です。設定画面から接続先・顧客管理アプリ・ログイン情報を設定してください。</div>';
    }
}

function renderCustomers(){
    var search=($('customer-search') ? $('customer-search').value : '').toLowerCase();

    var filtered=customers.filter(function(c){
        return !search ||
            c.name.toLowerCase().indexOf(search)>=0 ||
            c.email.toLowerCase().indexOf(search)>=0 ||
            c.company.toLowerCase().indexOf(search)>=0 ||
            c.code.toLowerCase().indexOf(search)>=0;
    });

    var body=$('customer-body');

    if(!filtered.length){
        body.innerHTML='<tr><td colspan="4" class="empty">該当する顧客がありません。</td></tr>';
        return;
    }

    body.innerHTML=filtered.map(function(c){
        return '<tr>'+
            '<td>'+escapeHtml(c.name)+'</td>'+
            '<td>'+escapeHtml(c.email)+'</td>'+
            '<td>'+escapeHtml(c.company)+'</td>'+
            '<td>'+escapeHtml(c.code)+'</td>'+
            '</tr>';
    }).join('');
}

function refreshCustomers(){
    if(!kintoneSettings.ready){
        alert('先にキントーン設定を保存してください。');
        return;
    }

    showToast('顧客一覧を更新しました');
    renderCustomerStatus();
    renderCustomers();
}

function showSettings(tab){
    currentSettingsTab=tab || currentSettingsTab || 'mail';
    showPage('page-settings');
    renderSettings();
}

function renderSettings(){
    $('settings-tab-mail').classList.toggle('active',currentSettingsTab==='mail');
    $('settings-tab-kintone').classList.toggle('active',currentSettingsTab==='kintone');

    if(currentSettingsTab==='mail')renderMailSettings();
    else renderKintoneSettings();
}

function renderMailSettings(){
    var html='';

    html+='<div class="card">';
    html+='<div class="card-title">メール送信設定</div>';

    html+='<div class="status-line">';
    html+='<span class="status-dot '+(mailSettings.ready?'ok':'warn')+'"></span>';
    html+='<span>'+(mailSettings.ready?'メール送信可能な設定が保存されています。':'メール送信設定が未完了です。')+'</span>';
    html+='</div>';

    html+='<div class="form-grid">';
    html+='<div class="field">';
    html+='<label>SMTPサーバ *</label>';
    html+='<input id="smtp-server" value="'+escapeHtml(mailSettings.smtp)+'" placeholder="smtp.example.com">';
    html+='</div>';

    html+='<div class="field">';
    html+='<label>ポート番号 *</label>';
    html+='<input id="smtp-port" value="'+escapeHtml(mailSettings.port)+'" placeholder="587">';
    html+='</div>';
    html+='</div>';

    html+='<div class="field">';
    html+='<label>接続方式</label>';
    html+='<select id="smtp-security">';
    html+='<option value="なし"'+(mailSettings.security==='なし'?' selected':'')+'>なし</option>';
    html+='<option value="STARTTLS"'+(mailSettings.security==='STARTTLS'?' selected':'')+'>STARTTLS</option>';
    html+='<option value="SSL/TLS"'+(mailSettings.security==='SSL/TLS'?' selected':'')+'>SSL/TLS</option>';
    html+='</select>';
    html+='</div>';

    html+='<div class="form-grid">';
    html+='<div class="field">';
    html+='<label>認証ユーザー名</label>';
    html+='<input id="smtp-user" value="'+escapeHtml(mailSettings.username)+'">';
    html+='</div>';

    html+='<div class="field">';
    html+='<label>認証パスワード</label>';
    html+='<input id="smtp-password" type="password" value="'+escapeHtml(mailSettings.password)+'">';
    html+='</div>';
    html+='</div>';

    html+='<div class="form-grid">';
    html+='<div class="field">';
    html+='<label>送信元メールアドレス *</label>';
    html+='<input id="smtp-from" value="'+escapeHtml(mailSettings.from)+'" placeholder="survey@example.com">';
    html+='</div>';

    html+='<div class="field">';
    html+='<label>送信元名</label>';
    html+='<input id="smtp-from-name" value="'+escapeHtml(mailSettings.fromName)+'">';
    html+='</div>';
    html+='</div>';

    html+='<div style="display:flex;gap:8px;margin-top:10px">';
    html+='<button class="btn btn-primary" onclick="saveMailSettings()">設定を保存</button>';
    html+='<button class="btn" onclick="testMailSettings()">送信設定を確認</button>';
    html+='</div>';

    html+='</div>';

    $('settings-content').innerHTML=html;
}

function saveMailSettings(){
    mailSettings.smtp=$('smtp-server').value.trim();
    mailSettings.port=$('smtp-port').value.trim();
    mailSettings.security=$('smtp-security').value;
    mailSettings.username=$('smtp-user').value.trim();
    mailSettings.password=$('smtp-password').value;
    mailSettings.from=$('smtp-from').value.trim();
    mailSettings.fromName=$('smtp-from-name').value.trim();

    if(!mailSettings.smtp || !mailSettings.port || !mailSettings.from){
        alert('SMTPサーバ、ポート番号、送信元メールアドレスを入力してください。');
        return;
    }

    mailSettings.ready=true;
    renderMailSettings();
    showToast('メール送信設定を保存しました');
}

function testMailSettings(){
    if(!mailSettings.ready){
        alert('先に設定を保存してください。');
        return;
    }

    openModal(
        'メール送信設定の確認',
        '<div class="notice success">SMTPサーバへの接続設定を確認しました。</div>'+
        '<p><strong>SMTPサーバ：</strong>'+escapeHtml(mailSettings.smtp)+'</p>'+
        '<p><strong>ポート：</strong>'+escapeHtml(mailSettings.port)+'</p>'+
        '<p><strong>接続方式：</strong>'+escapeHtml(mailSettings.security)+'</p>'+
        '<p><strong>送信元：</strong>'+escapeHtml(mailSettings.fromName)+' &lt;'+escapeHtml(mailSettings.from)+'&gt;</p>'+
        '<div class="notice">これはモック上の確認結果です。実際のメールは送信していません。</div>',
        '<button class="btn" onclick="closeModal()">閉じる</button>'
    );
}

function renderKintoneSettings(){
    var html='';

    html+='<div class="card">';
    html+='<div class="card-title">キントーン設定</div>';

    html+='<div class="status-line">';
    html+='<span class="status-dot '+(kintoneSettings.ready?'ok':'warn')+'"></span>';
    html+='<span>'+(kintoneSettings.ready?'顧客一覧を取得できる設定が保存されています。':'キントーン設定が未完了です。')+'</span>';
    html+='</div>';

    html+='<div class="notice">顧客一覧の取得には、キントーンのログイン名・パスワードを使用します。APIトークンは使用しません。</div>';

    html+='<div class="field">';
    html+='<label>キントーンの利用先 *</label>';
    html+='<input id="kt-domain" value="'+escapeHtml(kintoneSettings.domain)+'" placeholder="https://example.cybozu.com">';
    html+='</div>';

    html+='<div class="field">';
    html+='<label>顧客管理アプリID *</label>';
    html+='<input id="kt-appid" value="'+escapeHtml(kintoneSettings.appId)+'" placeholder="123">';
    html+='</div>';

    html+='<div class="form-grid">';
    html+='<div class="field">';
    html+='<label>ログイン名 *</label>';
    html+='<input id="kt-user" value="'+escapeHtml(kintoneSettings.loginName)+'">';
    html+='</div>';

    html+='<div class="field">';
    html+='<label>パスワード *</label>';
    html+='<input id="kt-password" type="password" value="'+escapeHtml(kintoneSettings.password)+'">';
    html+='</div>';
    html+='</div>';

    html+='<div class="card" style="background:#fafbfc;margin-top:20px;margin-bottom:0">';
    html+='<div class="card-title">接続経路</div>';

    html+='<div class="form-grid">';
    html+='<div class="field">';
    html+='<label>プロキシ ホスト名</label>';
    html+='<input id="kt-proxy-host" value="'+escapeHtml(kintoneSettings.proxyHost)+'" placeholder="proxy.example.local">';
    html+='</div>';

    html+='<div class="field">';
    html+='<label>プロキシ ポート番号</label>';
    html+='<input id="kt-proxy-port" value="'+escapeHtml(kintoneSettings.proxyPort)+'" placeholder="8080">';
    html+='</div>';
    html+='</div>';

    html+='<div class="field">';
    html+='<label><input type="checkbox" checked disabled> プロキシ認証は使用しない</label>';
    html+='</div>';

    html+='<div class="field">';
    html+='<label><input type="checkbox" checked disabled> SSL証明書の検証を無効にする</label>';
    html+='</div>';

    html+='</div>';

    html+='<div style="display:flex;gap:8px;margin-top:18px">';
    html+='<button class="btn btn-primary" onclick="saveKintoneSettings()">設定を保存</button>';
    html+='<button class="btn" onclick="testKintoneSettings()">接続設定を確認</button>';
    html+='</div>';

    html+='</div>';

    $('settings-content').innerHTML=html;
}

function saveKintoneSettings(){
    kintoneSettings.domain=$('kt-domain').value.trim();
    kintoneSettings.appId=$('kt-appid').value.trim();
    kintoneSettings.loginName=$('kt-user').value.trim();
    kintoneSettings.password=$('kt-password').value;
    kintoneSettings.proxyHost=$('kt-proxy-host').value.trim();
    kintoneSettings.proxyPort=$('kt-proxy-port').value.trim();

    if(!kintoneSettings.domain ||
       !kintoneSettings.appId ||
       !kintoneSettings.loginName ||
       !kintoneSettings.password){
        alert('利用先、顧客管理アプリID、ログイン名、パスワードを入力してください。');
        return;
    }

    if(kintoneSettings.proxyHost && !kintoneSettings.proxyPort){
        alert('プロキシのホスト名を入力した場合は、ポート番号も入力してください。');
        return;
    }

    kintoneSettings.proxyAuth=false;
    kintoneSettings.sslVerify=false;
    kintoneSettings.ready=true;

    renderKintoneSettings();
    showToast('キントーン設定を保存しました');
}

function testKintoneSettings(){
    if(!kintoneSettings.ready){
        alert('先に設定を保存してください。');
        return;
    }

    openModal(
        'キントーン接続設定の確認',
        '<div class="notice success">キントーンへの接続設定を確認しました。</div>'+
        '<p><strong>利用先：</strong>'+escapeHtml(kintoneSettings.domain)+'</p>'+
        '<p><strong>顧客管理アプリ：</strong>'+escapeHtml(kintoneSettings.appId)+'</p>'+
        '<p><strong>ログイン名：</strong>'+escapeHtml(kintoneSettings.loginName)+'</p>'+
        '<p><strong>プロキシ：</strong>'+
            (kintoneSettings.proxyHost
                ? escapeHtml(kintoneSettings.proxyHost)+':'+escapeHtml(kintoneSettings.proxyPort)
                : '使用しない')+
        '</p>'+
        '<p><strong>プロキシ認証：</strong>使用しない</p>'+
        '<p><strong>SSL証明書検証：</strong>無効</p>'+
        '<div class="notice">これはモック上の確認結果です。実際のキントーンには接続していません。</div>',
        '<button class="btn" onclick="closeModal()">閉じる</button>'
    );
}

function openModal(title,body,footer){
    $('modal-title').textContent=title;
    $('modal-body').innerHTML=body;
    $('modal-footer').innerHTML=footer || '';
    $('modal').classList.remove('hidden');
}

function closeModal(){
    $('modal').classList.add('hidden');
}

function showToast(message){
    var t=$('toast');
    t.textContent=message;
    t.classList.add('show');

    setTimeout(function(){
        t.classList.remove('show');
    },2200);
}

function init(){
    renderList();
    showPage('page-list');
}

init();
 </script>
</body>
</html>

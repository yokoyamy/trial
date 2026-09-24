<?php
namespace yokoyamy\trial\newapp;

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['yokoyamy_trial_newapp']['csrf_token'])) {
    $_SESSION['yokoyamy_trial_newapp']['csrf_token'] = bin2hex(random_bytes(32));
}

function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート業務運営</title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    color:#263238;
    background:#f4f6f8;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.55}
.topbar{
    height:60px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px
}
.logo{font-size:18px;font-weight:bold;white-space:nowrap}
.main-nav{height:100%;display:flex;align-items:center;gap:2px}
.main-nav button{
    height:100%;
    padding:0 17px;
    border:0;
    background:transparent;
    color:#dce7f3
}
.main-nav button.active,
.main-nav button:hover{background:#31557f;color:#fff}
.app{max-width:1440px;margin:0 auto;padding:24px}
.hidden{display:none!important}
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:20px
}
.page-header h1{margin:0;font-size:25px}
.subtext{color:#718096;font-size:13px;margin-top:5px}
.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:18px
}
.card-title{font-size:17px;font-weight:bold;margin-bottom:15px}
.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px
}
.btn-primary{background:#2878c8;border-color:#2878c8;color:#fff}
.btn-danger{border-color:#e05a5a;color:#c53f3f}
.btn-success{background:#2f855a;border-color:#2f855a;color:#fff}
.btn-small{padding:5px 10px;font-size:12px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{
    padding:12px 10px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    vertical-align:middle;
    font-size:13px
}
.table th{background:#f8fafc;color:#52606d}
.table tr:hover td{background:#fbfdff}
.empty{text-align:center!important;color:#8a98a5;padding:40px!important}
.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:12px;
    font-size:11px;
    font-weight:bold
}
.badge-open{background:#e6f6ed;color:#237a49}
.badge-draft{background:#edf2f7;color:#66788a}
.badge-end{background:#fdecec;color:#b43b3b}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px
}
.field{margin-bottom:15px}
.field label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:6px;
    color:#455563
}
.field input,.field textarea,.field select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:9px 10px;
    background:#fff
}
.field textarea{min-height:90px;resize:vertical}
.radio-row{display:flex;gap:22px;flex-wrap:wrap}
.radio-row label{
    font-weight:normal;
    display:inline-flex;
    align-items:center;
    gap:5px
}
.notice{
    padding:11px 13px;
    border-radius:5px;
    background:#edf6ff;
    border:1px solid #c9e2fa;
    color:#2b5f8a;
    font-size:13px;
    margin-bottom:15px
}
.notice.success{background:#edf9f1;border-color:#c9ead5;color:#267348}
.notice.warning{background:#fff8e6;border-color:#f0dfae;color:#8a6408}
.group-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    margin-bottom:16px
}
.group-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:13px 15px;
    background:#f7f9fb;
    border-bottom:1px solid #e3e8ed
}
.group-title{flex:1}
.group-title input{
    width:100%;
    border:1px solid transparent;
    background:transparent;
    padding:5px 7px;
    font-weight:bold;
    font-size:16px
}
.group-actions{display:flex;gap:5px}
.question-card{
    padding:15px;
    border-bottom:1px solid #e6ebef
}
.question-card:last-child{border-bottom:0}
.question-head{
    display:flex;
    align-items:center;
    gap:8px
}
.question-number{
    width:65px;
    color:#2878c8;
    font-weight:bold;
    flex:none
}
.question-title{flex:1}
.question-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px
}
.question-tools{display:flex;gap:6px}
.question-tools select{
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:6px
}
.question-meta{
    display:flex;
    gap:18px;
    align-items:center;
    margin-top:10px;
    padding-left:65px;
    color:#657786;
    font-size:13px
}
.question-options{margin-top:12px;padding-left:65px}
.option-row{
    display:flex;
    align-items:center;
    gap:7px;
    margin-bottom:7px
}
.option-row input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px
}
.add-question-area{padding:12px 14px}
.add-group-area{text-align:center;margin-top:8px}
.editor-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-top:20px
}
.editor-actions{display:flex;gap:8px}
.detail-tabs{
    display:flex;
    border-bottom:1px solid #dfe5eb;
    margin-bottom:18px
}
.detail-tabs button{
    border:0;
    background:transparent;
    padding:12px 20px;
    color:#687887;
    border-bottom:3px solid transparent
}
.detail-tabs button.active{
    color:#2878c8;
    border-bottom-color:#2878c8
}
.detail-summary{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px
}
.stat-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:17px
}
.stat-label{color:#718096;font-size:12px}
.stat-value{font-size:27px;font-weight:bold;margin-top:5px}
.stat-note{font-size:11px;color:#8a98a5;margin-top:3px}
.send-layout{
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:18px
}
.customer-toolbar{display:flex;gap:8px;margin-bottom:12px}
.customer-toolbar input{flex:1}
.customer-toolbar input,.customer-toolbar select{
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px
}
.selection-summary{
    padding:10px 12px;
    background:#edf6ff;
    color:#2b5f8a;
    border-radius:5px;
    margin-bottom:12px;
    font-size:13px
}
.email-preview{
    border:1px solid #dfe5eb;
    border-radius:6px;
    background:#fafbfc;
    padding:15px;
    white-space:pre-wrap;
    min-height:150px;
    font-size:13px
}
.recipient-chip{
    display:inline-block;
    padding:5px 8px;
    margin:3px;
    border-radius:4px;
    background:#edf2f7;
    font-size:12px
}
.progress{
    height:10px;
    background:#e8edf2;
    border-radius:5px;
    overflow:hidden;
    margin-top:8px
}
.progress span{display:block;height:100%;background:#4285c5}
.bar{
    height:9px;
    background:#e8edf2;
    border-radius:5px;
    overflow:hidden;
    margin-top:6px
}
.bar span{display:block;height:100%;background:#4285c5}
.result-item{
    padding:18px;
    border-bottom:1px solid #e6ebef
}
.result-answer{
    padding:8px 10px;
    background:#f7f9fb;
    border:1px solid #e6ebef;
    border-radius:4px;
    margin:5px 0;
    font-size:13px
}
.settings-tabs{display:flex;gap:5px;margin-bottom:18px}
.settings-tabs button{
    border:1px solid #d6dee6;
    background:#fff;
    padding:9px 16px;
    border-radius:5px
}
.settings-tabs button.active{
    background:#2878c8;
    color:#fff;
    border-color:#2878c8
}
.status-line{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    background:#f7f9fb;
    border-radius:5px;
    margin-bottom:15px
}
.status-dot{
    width:9px;
    height:9px;
    border-radius:50%;
    background:#9aa7b3
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
    z-index:1000
}
.modal{
    width:min(760px,calc(100% - 30px));
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:8px;
    box-shadow:0 15px 50px rgba(0,0,0,.25)
}
.modal-header{
    padding:16px 20px;
    border-bottom:1px solid #e3e8ed;
    display:flex;
    justify-content:space-between
}
.modal-body{padding:20px}
.modal-footer{
    padding:13px 20px;
    border-top:1px solid #e3e8ed;
    display:flex;
    justify-content:flex-end;
    gap:8px
}
.toast{
    position:fixed;
    right:25px;
    bottom:25px;
    background:#263238;
    color:#fff;
    padding:12px 18px;
    border-radius:5px;
    opacity:0;
    transform:translateY(10px);
    transition:.2s;
    pointer-events:none;
    z-index:2000
}
.toast.show{opacity:1;transform:translateY(0)}
.loading{
    position:relative;
    pointer-events:none
}
.loading:after{
    content:"";
    width:14px;
    height:14px;
    margin-left:7px;
    display:inline-block;
    vertical-align:-2px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite
}
@keyframes spin{to{transform:rotate(360deg)}}
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
    .table{min-width:800px}
    .card{overflow-x:auto}
}
</style>
</head>
<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>
    <nav class="main-nav">
        <button id="nav-list">アンケート一覧</button>
        <button id="nav-create">アンケート作成</button>
        <button id="nav-customers">顧客一覧</button>
        <button id="nav-settings">設定</button>
    </nav>
</header>

<main class="app">

<section id="page-list">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>
        <button id="btn-create" class="btn btn-primary">＋ アンケート作成</button>
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

<section id="page-editor" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="editor-page-title">アンケート作成</h1>
            <div class="subtext">アンケート全体を編集できます</div>
        </div>
    </div>

    <div class="notice">
        質問とグループを編集できます。質問番号は自動的に更新されます。
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
                    <input type="radio" name="numbering" value="global">
                    全体で通番（Q1、Q2、Q3…）
                </label>
                <label>
                    <input type="radio" name="numbering" value="group">
                    グループごと（Q1-1、Q1-2、Q2-1…）
                </label>
            </div>
        </div>
    </div>

    <div id="groups"></div>

    <div class="add-group-area">
        <button id="btn-add-group" class="btn btn-primary">＋ グループ追加</button>
    </div>

    <div class="editor-toolbar">
        <button id="btn-editor-back" class="btn">一覧へ戻る</button>
        <div class="editor-actions">
            <button id="btn-preview" class="btn">内容確認</button>
            <button id="btn-save" class="btn btn-primary">保存</button>
        </div>
    </div>
</section>

<section id="page-detail" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="detail-title"></h1>
            <div class="subtext" id="detail-subtitle"></div>
        </div>
        <div>
            <button id="btn-detail-edit" class="btn">編集</button>
            <button id="btn-detail-send" class="btn btn-primary">送信</button>
            <button id="btn-detail-back" class="btn">一覧へ戻る</button>
        </div>
    </div>

    <div class="detail-tabs">
        <button id="tab-content">アンケート内容</button>
        <button id="tab-send">送信</button>
        <button id="tab-status">回答状況</button>
        <button id="tab-result">回答結果</button>
    </div>

    <div id="detail-content"></div>
</section>

<section id="page-customers" class="hidden">
    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">キントーンの顧客管理アプリから取得した顧客です</div>
        </div>
        <button id="btn-customer-settings" class="btn">キントーン設定</button>
    </div>

    <div id="customer-status"></div>

    <div class="card">
        <div class="customer-toolbar">
            <input id="customer-search" placeholder="顧客名・メールアドレスで検索">
            <button id="btn-refresh-customers" class="btn">顧客一覧を更新</button>
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

<section id="page-settings" class="hidden">
    <div class="page-header">
        <div>
            <h1>設定</h1>
            <div class="subtext">メール送信と顧客一覧取得に必要な設定を管理します</div>
        </div>
    </div>

    <div class="settings-tabs">
        <button id="settings-tab-mail">メール送信設定</button>
        <button id="settings-tab-kintone">キントーン設定</button>
    </div>

    <div id="settings-content"></div>
</section>

</main>

<div id="modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <strong id="modal-title"></strong>
            <button id="modal-close" class="btn btn-small">閉じる</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-footer" id="modal-footer"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

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
        {id:1,name:'山田 太郎',email:'taro@example.com',company:'株式会社サンプル',code:'C001'},
        {id:2,name:'佐藤 花子',email:'hanako@example.com',company:'サンプル商事',code:'C002'},
        {id:3,name:'鈴木 一郎',email:'ichiro@example.com',company:'株式会社テスト',code:'C003'},
        {id:4,name:'田中 美咲',email:'misaki@example.com',company:'テスト株式会社',code:'C004'},
        {id:5,name:'高橋 健',email:'ken@example.com',company:'株式会社サンプル',code:'C005'}
    ];

    var mailSettings = {
        ready:false,
        smtp:'',
        port:'',
        security:'STARTTLS',
        username:'',
        password:'',
        from:'',
        fromName:''
    };

    var kintoneSettings = {
        ready:false,
        domain:'',
        appId:'',
        loginName:'',
        password:'',
        proxyHost:'',
        proxyPort:'',
        proxyAuth:false,
        sslVerify:false
    };

    var currentSurveyId = null;
    var currentEditingId = null;
    var currentSettingsTab = 'mail';
    var selectedCustomers = [];

    function $(id) {
        return document.getElementById(id);
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function setLoading(button, loading) {
        if (!button) {
            return;
        }

        if (loading) {
            button.disabled = true;
            button.classList.add('loading');
        } else {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    function showPage(id) {
        var pages = [
            'page-list',
            'page-editor',
            'page-detail',
            'page-customers',
            'page-settings'
        ];

        pages.forEach(function (pageId) {
            var page = $(pageId);
            if (page) {
                page.classList.toggle('hidden', pageId !== id);
            }
        });

        var navs = {
            'page-list':'nav-list',
            'page-editor':'nav-create',
            'page-detail':'nav-list',
            'page-customers':'nav-customers',
            'page-settings':'nav-settings'
        };

        Object.keys(navs).forEach(function (pageId) {
            var nav = $(navs[pageId]);
            if (nav) {
                nav.classList.toggle('active', pageId === id);
            }
        });
    }

    function getSurvey(id) {
        return surveys.find(function (survey) {
            return survey.id === id;
        }) || null;
    }

    function getCurrentSurvey() {
        return getSurvey(currentSurveyId);
    }

    function countQuestions(survey) {
        var count = 0;

        if (!survey || !Array.isArray(survey.groups)) {
            return count;
        }

        survey.groups.forEach(function (group) {
            if (group && Array.isArray(group.questions)) {
                count += group.questions.length;
            }
        });

        return count;
    }

    function questionNumber(survey, groupIndex, questionIndex) {
        if (!survey || survey.numbering === 'group') {
            return 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1);
        }

        var number = 0;

        for (var i = 0; i < groupIndex; i++) {
            number += survey.groups[i].questions.length;
        }

        number += questionIndex + 1;

        return 'Q' + number;
    }

    function renderList() {
        var body = $('survey-list-body');

        if (!body) {
            return;
        }

        body.textContent = '';

        if (!surveys.length) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 7;
            emptyCell.className = 'empty';
            emptyCell.textContent = 'アンケートがありません。';
            emptyRow.appendChild(emptyCell);
            body.appendChild(emptyRow);
            return;
        }

        surveys.forEach(function (survey) {
            var row = document.createElement('tr');

            var name = document.createElement('td');
            name.textContent = survey.name;

            var status = document.createElement('td');
            var badge = document.createElement('span');
            badge.className = 'badge';

            if (survey.status === 'open') {
                badge.classList.add('badge-open');
                badge.textContent = '公開中';
            } else if (survey.status === 'end') {
                badge.classList.add('badge-end');
                badge.textContent = '終了';
            } else {
                badge.classList.add('badge-draft');
                badge.textContent = '下書き';
            }

            status.appendChild(badge);

            var created = document.createElement('td');
            created.textContent = survey.created || '';

            var period = document.createElement('td');
            period.textContent =
                (survey.start || '未設定') +
                ' ～ ' +
                (survey.end || '未設定');

            var answers = document.createElement('td');
            answers.textContent = String(survey.answers || 0) + '件';

            var updated = document.createElement('td');
            updated.textContent = survey.updated || '';

            var actions = document.createElement('td');

            var detailButton = document.createElement('button');
            detailButton.className = 'btn btn-small';
            detailButton.textContent = '詳細';
            detailButton.dataset.id = String(survey.id);
            detailButton.addEventListener('click', function () {
                showDetail(Number(this.dataset.id));
            });

            actions.appendChild(detailButton);

            if (survey.status === 'draft') {
                var publishButton = document.createElement('button');
                publishButton.className = 'btn btn-small btn-success';
                publishButton.textContent = '公開';
                publishButton.style.marginLeft = '5px';
                publishButton.dataset.id = String(survey.id);

                publishButton.addEventListener('click', function () {
                    publishSurvey(Number(this.dataset.id), this);
                });

                actions.appendChild(publishButton);

                var deleteButton = document.createElement('button');
                deleteButton.className = 'btn btn-small btn-danger';
                deleteButton.textContent = '削除';
                deleteButton.style.marginLeft = '5px';
                deleteButton.dataset.id = String(survey.id);

                deleteButton.addEventListener('click', function () {
                    deleteSurvey(Number(this.dataset.id), this);
                });

                actions.appendChild(deleteButton);
            }

            if (survey.status === 'open') {
                var endButton = document.createElement('button');
                endButton.className = 'btn btn-small';
                endButton.textContent = '終了';
                endButton.style.marginLeft = '5px';
                endButton.dataset.id = String(survey.id);

                endButton.addEventListener('click', function () {
                    endSurvey(Number(this.dataset.id), this);
                });

                actions.appendChild(endButton);
            }

            row.appendChild(name);
            row.appendChild(status);
            row.appendChild(created);
            row.appendChild(period);
            row.appendChild(answers);
            row.appendChild(updated);
            row.appendChild(actions);

            body.appendChild(row);
        });
    }

    function openCreate() {
        currentEditingId = null;

        var title = $('editor-page-title');
        if (title) {
            title.textContent = 'アンケート作成';
        }

        setEditorValues({
            name:'',
            description:'',
            status:'draft',
            start:'',
            end:'',
            numbering:'global',
            groups:[
                {
                    id:Date.now(),
                    name:'グループ1',
                    questions:[
                        {
                            id:Date.now() + 1,
                            text:'',
                            type:'single',
                            required:false,
                            options:[
                                {text:'選択肢1',branch:''},
                                {text:'選択肢2',branch:''}
                            ]
                        }
                    ]
                }
            ]
        });

        showPage('page-editor');
    }

    function editSurvey(id) {
        var survey = getSurvey(id);

        if (!survey) {
            return;
        }

        currentEditingId = id;

        var title = $('editor-page-title');
        if (title) {
            title.textContent = 'アンケート編集';
        }

        setEditorValues(JSON.parse(JSON.stringify(survey)));
        showPage('page-editor');
    }

    function setEditorValues(survey) {
        var name = $('survey-name');
        var description = $('survey-description');
        var status = $('survey-status');
        var start = $('survey-start');
        var end = $('survey-end');

        if (name) name.value = survey.name || '';
        if (description) description.value = survey.description || '';
        if (status) status.value = survey.status || 'draft';
        if (start) start.value = survey.start || '';
        if (end) end.value = survey.end || '';

        var numbering = document.querySelector(
            'input[name="numbering"][value="' +
            (survey.numbering || 'global') +
            '"]'
        );

        document.querySelectorAll('input[name="numbering"]').forEach(function (radio) {
            radio.checked = radio === numbering;
        });

        renderEditorGroups(survey);
    }

    function renderEditorGroups(survey) {
        var container = $('groups');

        if (!container) {
            return;
        }

        container.textContent = '';

        survey.groups.forEach(function (group, groupIndex) {
            var card = document.createElement('div');
            card.className = 'group-card';

            var header = document.createElement('div');
            header.className = 'group-header';

            var title = document.createElement('div');
            title.className = 'group-title';

            var groupInput = document.createElement('input');
            groupInput.value = group.name || '';
            groupInput.dataset.groupIndex = String(groupIndex);

            title.appendChild(groupInput);

            var actions = document.createElement('div');
            actions.className = 'group-actions';

            var addQuestion = document.createElement('button');
            addQuestion.className = 'btn btn-small';
            addQuestion.textContent = '質問追加';
            addQuestion.dataset.groupIndex = String(groupIndex);

            addQuestion.addEventListener('click', function () {
                addQuestionToGroup(Number(this.dataset.groupIndex));
            });

            var removeGroup = document.createElement('button');
            removeGroup.className = 'btn btn-small btn-danger';
            removeGroup.textContent = 'グループ削除';
            removeGroup.dataset.groupIndex = String(groupIndex);

            removeGroup.addEventListener('click', function () {
                removeGroupAt(Number(this.dataset.groupIndex));
            });

            actions.appendChild(addQuestion);
            actions.appendChild(removeGroup);

            header.appendChild(title);
            header.appendChild(actions);
            card.appendChild(header);

            group.questions.forEach(function (question, questionIndex) {
                card.appendChild(
                    createQuestionEditor(
                        question,
                        groupIndex,
                        questionIndex,
                        survey
                    )
                );
            });

            var addArea = document.createElement('div');
            addArea.className = 'add-question-area';

            var addButton = document.createElement('button');
            addButton.className = 'btn btn-small';
            addButton.textContent = '＋ 質問を追加';
            addButton.dataset.groupIndex = String(groupIndex);

            addButton.addEventListener('click', function () {
                addQuestionToGroup(Number(this.dataset.groupIndex));
            });

            addArea.appendChild(addButton);
            card.appendChild(addArea);

            container.appendChild(card);
        });
    }

    function createQuestionEditor(question, groupIndex, questionIndex, survey) {
        var wrapper = document.createElement('div');
        wrapper.className = 'question-card';

        var head = document.createElement('div');
        head.className = 'question-head';

        var number = document.createElement('div');
        number.className = 'question-number';
        number.textContent = questionNumber(survey, groupIndex, questionIndex);

        var title = document.createElement('div');
        title.className = 'question-title';

        var input = document.createElement('input');
        input.value = question.text || '';
        input.dataset.groupIndex = String(groupIndex);
        input.dataset.questionIndex = String(questionIndex);

        input.addEventListener('input', function () {
            var s = getEditorObject();
            var gi = Number(this.dataset.groupIndex);
            var qi = Number(this.dataset.questionIndex);

            if (s.groups[gi] && s.groups[gi].questions[qi]) {
                s.groups[gi].questions[qi].text = this.value;
                window.currentEditor = s;
            }
        });

        title.appendChild(input);

        var tools = document.createElement('div');
        tools.className = 'question-tools';

        var type = document.createElement('select');
        type.dataset.groupIndex = String(groupIndex);
        type.dataset.questionIndex = String(questionIndex);

        [
            ['single','単一選択'],
            ['multiple','複数選択'],
            ['free','自由記述']
        ].forEach(function (item) {
            var option = document.createElement('option');
            option.value = item[0];
            option.textContent = item[1];

            if (question.type === item[0]) {
                option.selected = true;
            }

            type.appendChild(option);
        });

        type.addEventListener('change', function () {
            var s = getEditorObject();
            var gi = Number(this.dataset.groupIndex);
            var qi = Number(this.dataset.questionIndex);

            if (!s.groups[gi] || !s.groups[gi].questions[qi]) {
                return;
            }

            s.groups[gi].questions[qi].type = this.value;

            if (this.value === 'free') {
                s.groups[gi].questions[qi].options = [];
            } else if (!s.groups[gi].questions[qi].options.length) {
                s.groups[gi].questions[qi].options = [
                    {text:'選択肢1',branch:''},
                    {text:'選択肢2',branch:''}
                ];
            }

            window.currentEditor = s;
            renderEditorGroups(s);
        });

        var remove = document.createElement('button');
        remove.className = 'btn btn-small btn-danger';
        remove.textContent = '削除';
        remove.dataset.groupIndex = String(groupIndex);
        remove.dataset.questionIndex = String(questionIndex);

        remove.addEventListener('click', function () {
            removeQuestion(
                Number(this.dataset.groupIndex),
                Number(this.dataset.questionIndex)
            );
        });

        tools.appendChild(type);
        tools.appendChild(remove);

        head.appendChild(number);
        head.appendChild(title);
        head.appendChild(tools);

        wrapper.appendChild(head);

        var meta = document.createElement('div');
        meta.className = 'question-meta';

        var requiredLabel = document.createElement('label');

        var required = document.createElement('input');
        required.type = 'checkbox';
        required.checked = !!question.required;
        required.dataset.groupIndex = String(groupIndex);
        required.dataset.questionIndex = String(questionIndex);

        required.addEventListener('change', function () {
            var s = getEditorObject();
            var gi = Number(this.dataset.groupIndex);
            var qi = Number(this.dataset.questionIndex);

            if (s.groups[gi] && s.groups[gi].questions[qi]) {
                s.groups[gi].questions[qi].required = this.checked;
                window.currentEditor = s;
            }
        });

        requiredLabel.appendChild(required);
        requiredLabel.appendChild(document.createTextNode(' 必須'));

        meta.appendChild(requiredLabel);
        wrapper.appendChild(meta);

        if (question.type !== 'free') {
            var options = document.createElement('div');
            options.className = 'question-options';

            question.options.forEach(function (opt, optionIndex) {
                var row = document.createElement('div');
                row.className = 'option-row';

                var optionInput = document.createElement('input');
                optionInput.value = opt.text || '';
                optionInput.dataset.groupIndex = String(groupIndex);
                optionInput.dataset.questionIndex = String(questionIndex);
                optionInput.dataset.optionIndex = String(optionIndex);

                optionInput.addEventListener('input', function () {
                    var s = getEditorObject();
                    var gi = Number(this.dataset.groupIndex);
                    var qi = Number(this.dataset.questionIndex);
                    var oi = Number(this.dataset.optionIndex);

                    if (
                        s.groups[gi] &&
                        s.groups[gi].questions[qi] &&
                        s.groups[gi].questions[qi].options[oi]
                    ) {
                        s.groups[gi].questions[qi].options[oi].text = this.value;
                        window.currentEditor = s;
                    }
                });

                var removeOption = document.createElement('button');
                removeOption.className = 'btn btn-small';
                removeOption.textContent = '削除';
                removeOption.dataset.groupIndex = String(groupIndex);
                removeOption.dataset.questionIndex = String(questionIndex);
                removeOption.dataset.optionIndex = String(optionIndex);

                removeOption.addEventListener('click', function () {
                    var s = getEditorObject();
                    var gi = Number(this.dataset.groupIndex);
                    var qi = Number(this.dataset.questionIndex);
                    var oi = Number(this.dataset.optionIndex);

                    if (
                        s.groups[gi] &&
                        s.groups[gi].questions[qi]
                    ) {
                        s.groups[gi].questions[qi].options.splice(oi, 1);
                        window.currentEditor = s;
                        renderEditorGroups(s);
                    }
                });

                row.appendChild(optionInput);
                row.appendChild(removeOption);
                options.appendChild(row);
            });

            var addOption = document.createElement('button');
            addOption.className = 'btn btn-small';
            addOption.textContent = '＋ 選択肢追加';
            addOption.dataset.groupIndex = String(groupIndex);
            addOption.dataset.questionIndex = String(questionIndex);

            addOption.addEventListener('click', function () {
                var s = getEditorObject();
                var gi = Number(this.dataset.groupIndex);
                var qi = Number(this.dataset.questionIndex);

                if (s.groups[gi] && s.groups[gi].questions[qi]) {
                    s.groups[gi].questions[qi].options.push({
                        text:'新しい選択肢',
                        branch:''
                    });

                    window.currentEditor = s;
                    renderEditorGroups(s);
                }
            });

            options.appendChild(addOption);
            wrapper.appendChild(options);
        }

        return wrapper;
    }

    window.currentEditor = null;

    function getEditorObject() {
        if (!window.currentEditor) {
            window.currentEditor = {
                name:'',
                description:'',
                status:'draft',
                start:'',
                end:'',
                numbering:'global',
                groups:[]
            };
        }

        return window.currentEditor;
    }

    function syncEditorHeader() {
        var s = getEditorObject();

        var name = $('survey-name');
        var description = $('survey-description');
        var status = $('survey-status');
        var start = $('survey-start');
        var end = $('survey-end');

        if (name) s.name = name.value;
        if (description) s.description = description.value;
        if (status) s.status = status.value;
        if (start) s.start = start.value;
        if (end) s.end = end.value;

        var radio = document.querySelector('input[name="numbering"]:checked');
        s.numbering = radio ? radio.value : 'global';

        return s;
    }

    function addGroup() {
        var s = syncEditorHeader();

        s.groups.push({
            id:Date.now() + s.groups.length,
            name:'新しいグループ',
            questions:[]
        });

        window.currentEditor = s;
        renderEditorGroups(s);
    }

    function addQuestionToGroup(groupIndex) {
        var s = syncEditorHeader();

        if (!s.groups[groupIndex]) {
            return;
        }

        s.groups[groupIndex].questions.push({
            id:Date.now(),
            text:'新しい質問',
            type:'single',
            required:false,
            options:[
                {text:'選択肢1',branch:''},
                {text:'選択肢2',branch:''}
            ]
        });

        window.currentEditor = s;
        renderEditorGroups(s);
    }

    function removeGroupAt(groupIndex) {
        var s = syncEditorHeader();

        if (!confirm('このグループを削除しますか？')) {
            return;
        }

        s.groups.splice(groupIndex, 1);
        window.currentEditor = s;
        renderEditorGroups(s);
    }

    function removeQuestion(groupIndex, questionIndex) {
        var s = syncEditorHeader();

        if (!confirm('この質問を削除しますか？')) {
            return;
        }

        if (s.groups[groupIndex]) {
            s.groups[groupIndex].questions.splice(questionIndex, 1);
        }

        window.currentEditor = s;
        renderEditorGroups(s);
    }

    function saveSurvey(button) {
        var s = syncEditorHeader();

        if (!s.name.trim()) {
            alert('アンケート名を入力してください。');
            return;
        }

        if (!s.groups.length || countQuestions(s) === 0) {
            alert('質問を1問以上設定してください。');
            return;
        }

        setLoading(button, true);

        setTimeout(function () {
            if (currentEditingId !== null) {
                var existing = getSurvey(currentEditingId);

                if (existing) {
                    s.id = existing.id;
                    s.created = existing.created;
                    s.answers = existing.answers || 0;
                    s.target = existing.target || 0;
                    s.sent = existing.sent || 0;
                    s.updated = '2026-09-24';

                    var index = surveys.findIndex(function (item) {
                        return item.id === currentEditingId;
                    });

                    if (index >= 0) {
                        surveys[index] = JSON.parse(JSON.stringify(s));
                    }
                }
            } else {
                s.id = Date.now();
                s.created = '2026-09-24';
                s.answers = 0;
                s.target = 0;
                s.sent = 0;
                s.updated = '2026-09-24';
                surveys.push(JSON.parse(JSON.stringify(s)));
            }

            setLoading(button, false);
            showToast('アンケートを保存しました');
            renderList();
            showPage('page-list');
        }, 250);
    }

    function previewEditor() {
        var s = syncEditorHeader();

        var html = '<div class="card">';
        html += '<div class="card-title">' + escapeHtml(s.name) + '</div>';

        if (s.description) {
            html += '<p>' + escapeHtml(s.description) + '</p>';
        }

        s.groups.forEach(function (group, gi) {
            html += '<h3>' + escapeHtml(group.name) + '</h3>';

            group.questions.forEach(function (question, qi) {
                html += '<div class="result-item">';
                html += '<strong>' +
                    escapeHtml(questionNumber(s, gi, qi)) +
                    '　' +
                    escapeHtml(question.text) +
                    '</strong>';

                question.options.forEach(function (option) {
                    html += '<div class="result-answer">' +
                        escapeHtml(option.text) +
                        '</div>';
                });

                html += '</div>';
            });
        });

        html += '</div>';

        openModal(
            'アンケート内容確認',
            html,
            '<button class="btn" id="preview-close">閉じる</button>'
        );

        var close = $('preview-close');

        if (close) {
            close.addEventListener('click', closeModal);
        }
    }

    function showDetail(id) {
        var survey = getSurvey(id);

        if (!survey) {
            return;
        }

        currentSurveyId = id;

        var title = $('detail-title');
        var subtitle = $('detail-subtitle');

        if (title) title.textContent = survey.name;
        if (subtitle) subtitle.textContent = 'アンケート詳細';

        showPage('page-detail');
        showDetailTab('content');
    }

    function editCurrentSurvey() {
        if (currentSurveyId !== null) {
            editSurvey(currentSurveyId);
        }
    }

    function showDetailTab(tab) {
        var survey = getCurrentSurvey();

        if (!survey) {
            return;
        }

        var tabs = {
            content:'tab-content',
            send:'tab-send',
            status:'tab-status',
            result:'tab-result'
        };

        Object.keys(tabs).forEach(function (key) {
            var button = $(tabs[key]);

            if (button) {
                button.classList.toggle('active', key === tab);
            }
        });

        if (tab === 'content') {
            renderDetailContent(survey);
        } else if (tab === 'send') {
            renderSend(survey);
        } else if (tab === 'status') {
            renderStatus(survey);
        } else if (tab === 'result') {
            renderResult(survey);
        }
    }

    function renderDetailContent(survey) {
        var target = $('detail-content');

        if (!target) {
            return;
        }

        var html = '<div class="card">';

        html += '<div class="card-title">アンケート内容</div>';

        if (survey.description) {
            html += '<p>' + escapeHtml(survey.description) + '</p>';
        }

        survey.groups.forEach(function (group, gi) {
            html += '<h3>' + escapeHtml(group.name) + '</h3>';

            group.questions.forEach(function (question, qi) {
                html += '<div class="result-item">';
                html += '<div><strong>' +
                    escapeHtml(questionNumber(survey, gi, qi)) +
                    '　' +
                    escapeHtml(question.text) +
                    '</strong></div>';

                if (question.required) {
                    html += '<div style="font-size:12px;color:#c53f3f">必須</div>';
                }

                question.options.forEach(function (option) {
                    html += '<div class="result-answer">' +
                        escapeHtml(option.text) +
                        '</div>';
                });

                html += '</div>';
            });
        });

        html += '</div>';

        target.innerHTML = html;
    }

    function renderSend(survey) {
        var target = $('detail-content');

        if (!target) {
            return;
        }

        selectedCustomers = [];

        var html = '<div class="send-layout">';

        html += '<div class="card">';
        html += '<div class="card-title">送信対象者</div>';
        html += '<div class="selection-summary">選択中：<span id="selected-count">0</span>名</div>';
        html += '<div class="customer-toolbar">';
        html += '<input id="send-customer-search" placeholder="顧客名・メールアドレスで検索">';
        html += '<button id="btn-select-all" class="btn">表示中を全選択</button>';
        html += '</div>';
        html += '<div id="send-customers"></div>';
        html += '</div>';

        html += '<div class="card">';
        html += '<div class="card-title">メール内容</div>';
        html += '<div class="field"><label>件名</label>';
        html += '<input id="send-subject" value="' +
            escapeHtml(survey.name + ' 回答のお願い') +
            '"></div>';
        html += '<div class="field"><label>本文</label>';
        html += '<textarea id="send-body">「' +
            escapeHtml(survey.name) +
            '」へのご回答をお願いいたします。</textarea></div>';
        html += '<button id="btn-confirm-send" class="btn btn-primary">送信内容を確認</button>';
        html += '</div>';

        html += '</div>';

        target.innerHTML = html;

        renderSendCustomers();

        var search = $('send-customer-search');

        if (search) {
            search.addEventListener('input', function () {
                renderSendCustomers();
            });
        }

        var selectAll = $('btn-select-all');

        if (selectAll) {
            selectAll.addEventListener('click', function () {
                setLoading(this, true);

                setTimeout(function () {
                    selectAllVisibleCustomers();
                    setLoading(selectAll, false);
                }, 100);
            });
        }

        var confirm = $('btn-confirm-send');

        if (confirm) {
            confirm.addEventListener('click', function () {
                setLoading(this, true);

                setTimeout(function () {
                    setLoading(confirm, false);
                    confirmSend();
                }, 100);
            });
        }
    }

    function renderSendCustomers() {
        var target = $('send-customers');

        if (!target) {
            return;
        }

        var searchInput = $('send-customer-search');
        var search = searchInput ? searchInput.value.toLowerCase() : '';

        var filtered = customers.filter(function (customer) {
            return !search ||
                customer.name.toLowerCase().indexOf(search) >= 0 ||
                customer.email.toLowerCase().indexOf(search) >= 0 ||
                customer.company.toLowerCase().indexOf(search) >= 0;
        });

        var table = document.createElement('table');
        table.className = 'table';

        var head = document.createElement('thead');
        var headRow = document.createElement('tr');

        ['','顧客名','メールアドレス','会社名'].forEach(function (text) {
            var th = document.createElement('th');
            th.textContent = text;
            headRow.appendChild(th);
        });

        head.appendChild(headRow);
        table.appendChild(head);

        var body = document.createElement('tbody');

        filtered.forEach(function (customer) {
            var row = document.createElement('tr');

            var checkCell = document.createElement('td');
            var checkbox = document.createElement('input');

            checkbox.type = 'checkbox';
            checkbox.checked =
                selectedCustomers.indexOf(customer.id) >= 0;
            checkbox.dataset.id = String(customer.id);

            checkbox.addEventListener('change', function () {
                toggleCustomer(
                    Number(this.dataset.id),
                    this.checked
                );
            });

            checkCell.appendChild(checkbox);

            var name = document.createElement('td');
            name.textContent = customer.name;

            var email = document.createElement('td');
            email.textContent = customer.email;

            var company = document.createElement('td');
            company.textContent = customer.company;

            row.appendChild(checkCell);
            row.appendChild(name);
            row.appendChild(email);
            row.appendChild(company);

            body.appendChild(row);
        });

        table.appendChild(body);
        target.textContent = '';

        if (!filtered.length) {
            var empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = '該当する顧客がありません。';
            target.appendChild(empty);
        } else {
            target.appendChild(table);
        }

        var count = $('selected-count');

        if (count) {
            count.textContent = String(selectedCustomers.length);
        }
    }

    function toggleCustomer(id, checked) {
        var index = selectedCustomers.indexOf(id);

        if (checked && index < 0) {
            selectedCustomers.push(id);
        }

        if (!checked && index >= 0) {
            selectedCustomers.splice(index, 1);
        }

        var count = $('selected-count');

        if (count) {
            count.textContent = String(selectedCustomers.length);
        }
    }

    function selectAllVisibleCustomers() {
        var searchInput = $('send-customer-search');
        var search = searchInput ? searchInput.value.toLowerCase() : '';

        customers.forEach(function (customer) {
            var visible =
                !search ||
                customer.name.toLowerCase().indexOf(search) >= 0 ||
                customer.email.toLowerCase().indexOf(search) >= 0 ||
                customer.company.toLowerCase().indexOf(search) >= 0;

            if (
                visible &&
                selectedCustomers.indexOf(customer.id) < 0
            ) {
                selectedCustomers.push(customer.id);
            }
        });

        renderSendCustomers();
    }

    function confirmSend() {
        var survey = getCurrentSurvey();

        if (!survey) {
            return;
        }

        if (survey.status !== 'open') {
            alert('公開中のアンケートのみ送信できます。');
            return;
        }

        if (!mailSettings.ready) {
            alert('メール送信設定を完了してください。');
            return;
        }

        if (!selectedCustomers.length) {
            alert('送信対象者を1名以上選択してください。');
            return;
        }

        var subject = $('send-subject');
        var body = $('send-body');

        var recipients = customers.filter(function (customer) {
            return selectedCustomers.indexOf(customer.id) >= 0;
        });

        var html = '<div class="notice">以下の内容でメールを送信します。</div>';
        html += '<p><strong>アンケート：</strong>' +
            escapeHtml(survey.name) +
            '</p>';

        html += '<p><strong>送信対象者：</strong>' +
            recipients.length +
            '名</p>';

        recipients.forEach(function (customer) {
            html += '<span class="recipient-chip">' +
                escapeHtml(customer.name) +
                ' &lt;' +
                escapeHtml(customer.email) +
                '&gt;</span>';
        });

        html += '<p><strong>件名：</strong>' +
            escapeHtml(subject ? subject.value : '') +
            '</p>';

        html += '<div class="email-preview">' +
            escapeHtml(body ? body.value : '') +
            '</div>';

        openModal(
            'アンケート送信確認',
            html,
            '<button class="btn" id="send-back">戻る</button>' +
            '<button class="btn btn-primary" id="send-execute">メールを送信する</button>'
        );

        var back = $('send-back');

        if (back) {
            back.addEventListener('click', closeModal);
        }

        var execute = $('send-execute');

        if (execute) {
            execute.addEventListener('click', function () {
                setLoading(this, true);

                setTimeout(function () {
                    setLoading(execute, false);
                    sendSurveyMail();
                }, 300);
            });
        }
    }

    function sendSurveyMail() {
        var survey = getCurrentSurvey();

        if (!survey) {
            return;
        }

        var count = selectedCustomers.length;

        closeModal();

        survey.target = Math.max(survey.target || 0, count);
        survey.sent = (survey.sent || 0) + count;
        survey.updated = '2026-09-24';

        var html = '<div class="notice success">メール送信が完了しました。</div>';
        html += '<div class="card">';
        html += '<div class="card-title">送信結果</div>';
        html += '<p>アンケート：' +
            escapeHtml(survey.name) +
            '</p>';
        html += '<p>送信対象：' + count + '名</p>';
        html += '<p style="color:#267348">送信成功：' +
            count +
            '名</p>';
        html += '<p style="color:#718096">送信失敗：0名</p>';
        html += '<div class="progress"><span style="width:100%"></span></div>';
        html += '</div>';

        var target = $('detail-content');

        if (target) {
            target.innerHTML = html;
        }

        showToast('メールを送信しました');
    }

    function renderStatus(survey) {
        var target = $('detail-content');

        if (!target) {
            return;
        }

        var targetCount = survey.target || 0;
        var answers = survey.answers || 0;
        var rate = targetCount
            ? Math.round(answers / targetCount * 100)
            : 0;

        var unanswered = Math.max(
            targetCount - answers,
            0
        );

        var html = '<div class="detail-summary">';
        html += statCard('回答数', answers + '件', '');
        html += statCard('回答率', rate + '%', '');
        html += statCard('未回答数', unanswered + '名', '');
        html += statCard('送信済み', (survey.sent || 0) + '名', '');
        html += '</div>';

        html += '<div class="card">';
        html += '<div class="card-title">公開・回答状況</div>';
        html += '<p>公開期間：' +
            escapeHtml(survey.start || '未設定') +
            ' ～ ' +
            escapeHtml(survey.end || '未設定') +
            '</p>';
        html += '<p>メール送信対象者：' +
            targetCount +
            '名</p>';
        html += '<p>メール送信済み：' +
            (survey.sent || 0) +
            '名</p>';
        html += '<p>未回答者：' +
            unanswered +
            '名</p>';
        html += '<div>回答率</div>';
        html += '<div class="progress"><span style="width:' +
            Math.min(rate, 100) +
            '%"></span></div>';
        html += '<div style="text-align:right">' +
            rate +
            '%</div>';
        html += '</div>';

        target.innerHTML = html;
    }

    function statCard(label, value, note) {
        return '<div class="stat-card">' +
            '<div class="stat-label">' +
            escapeHtml(label) +
            '</div>' +
            '<div class="stat-value">' +
            escapeHtml(value) +
            '</div>' +
            '<div class="stat-note">' +
            escapeHtml(note) +
            '</div>' +
            '</div>';
    }

    function renderResult(survey) {
        var target = $('detail-content');

        if (!target) {
            return;
        }

        var html = '<div class="detail-summary">';
        html += statCard('総回答数', survey.answers + '件', '');
        html += statCard('回答者数', survey.answers + '名', '');
        html += statCard('質問数', countQuestions(survey) + '問', '');
        html += statCard('集計対象', survey.answers + '件', '');
        html += '</div>';

        html += '<div class="card">';
        html += '<div class="card-title">質問ごとの回答結果</div>';

        survey.groups.forEach(function (group, gi) {
            html += '<h3>' +
                escapeHtml(group.name) +
                '</h3>';

            group.questions.forEach(function (question, qi) {
                html += '<div class="result-item">';
                html += '<div style="font-weight:bold">' +
                    escapeHtml(questionNumber(survey, gi, qi)) +
                    '　' +
                    escapeHtml(question.text) +
                    '</div>';

                if (question.type === 'single' ||
                    question.type === 'multiple') {

                    question.options.forEach(function (option, oi) {
                        var count = survey.answers
                            ? Math.max(
                                0,
                                Math.round(
                                    survey.answers *
                                    (0.52 - oi * 0.12)
                                )
                            )
                            : 0;

                        var percent = survey.answers
                            ? Math.min(
                                100,
                                Math.round(
                                    count / survey.answers * 100
                                )
                            )
                            : 0;

                        html += '<div style="margin-top:13px">';
                        html += '<div style="display:flex;justify-content:space-between">';
                        html += '<span>' +
                            escapeHtml(option.text) +
                            '</span>';
                        html += '<span>' +
                            count +
                            '件（' +
                            percent +
                            '%）</span>';
                        html += '</div>';
                        html += '<div class="bar"><span style="width:' +
                            percent +
                            '%"></span></div>';
                        html += '</div>';
                    });
                } else {
                    html += '<div class="result-answer">「とても参考になりました。今後も利用したいです。」</div>';
                    html += '<div class="result-answer">「もう少し選択肢があると回答しやすいと思います。」</div>';
                    html += '<div class="result-answer">「商品の説明が分かりやすかったです。」</div>';
                }

                html += '</div>';
            });
        });

        html += '</div>';

        target.innerHTML = html;
    }

    function publishSurvey(id, button) {
        var survey = getSurvey(id);

        if (!survey) {
            return;
        }

        if (!survey.name || !countQuestions(survey)) {
            alert('公開するにはアンケート内容を設定してください。');
            return;
        }

        if (!confirm('「' + survey.name + '」を公開しますか？')) {
            return;
        }

        setLoading(button, true);

        setTimeout(function () {
            survey.status = 'open';
            survey.updated = '2026-09-24';

            setLoading(button, false);
            renderList();
            showToast('アンケートを公開しました');
        }, 200);
    }

    function endSurvey(id, button) {
        var survey = getSurvey(id);

        if (!survey) {
            return;
        }

        if (!confirm('「' + survey.name + '」の回答受付を終了しますか？')) {
            return;
        }

        setLoading(button, true);

        setTimeout(function () {
            survey.status = 'end';
            survey.updated = '2026-09-24';

            setLoading(button, false);
            renderList();
            showToast('アンケートを終了しました');
        }, 200);
    }

    function deleteSurvey(id, button) {
        var survey = getSurvey(id);

        if (!survey) {
            return;
        }

        if (!confirm('下書き「' + survey.name + '」を削除しますか？')) {
            return;
        }

        setLoading(button, true);

        setTimeout(function () {
            surveys = surveys.filter(function (item) {
                return item.id !== id;
            });

            setLoading(button, false);
            renderList();
            showToast('アンケートを削除しました');
        }, 200);
    }

    function showCustomers() {
        showPage('page-customers');
        renderCustomers();
        renderCustomerStatus();
    }

    function renderCustomerStatus() {
        var target = $('customer-status');

        if (!target) {
            return;
        }

        target.textContent = '';

        var notice = document.createElement('div');
        notice.className = 'notice';

        if (kintoneSettings.ready) {
            notice.classList.add('success');
            notice.textContent =
                'キントーンから顧客一覧を取得できる状態です。現在 ' +
                customers.length +
                ' 件の顧客を表示しています。';
        } else {
            notice.classList.add('warning');
            notice.textContent =
                'キントーン設定が未完了です。設定画面から設定してください。';
        }

        target.appendChild(notice);
    }

    function renderCustomers() {
        var searchInput = $('customer-search');
        var body = $('customer-body');

        if (!body) {
            return;
        }

        var search = searchInput
            ? searchInput.value.toLowerCase()
            : '';

        var filtered = customers.filter(function (customer) {
            return !search ||
                customer.name.toLowerCase().indexOf(search) >= 0 ||
                customer.email.toLowerCase().indexOf(search) >= 0 ||
                customer.company.toLowerCase().indexOf(search) >= 0 ||
                customer.code.toLowerCase().indexOf(search) >= 0;
        });

        body.textContent = '';

        if (!filtered.length) {
            var row = document.createElement('tr');
            var cell = document.createElement('td');
            cell.colSpan = 4;
            cell.className = 'empty';
            cell.textContent = '該当する顧客がありません。';
            row.appendChild(cell);
            body.appendChild(row);
            return;
        }

        filtered.forEach(function (customer) {
            var row = document.createElement('tr');

            [
                customer.name,
                customer.email,
                customer.company,
                customer.code
            ].forEach(function (value) {
                var cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            });

            body.appendChild(row);
        });
    }

    function refreshCustomers(button) {
        if (!kintoneSettings.ready) {
            alert('先にキントーン設定を保存してください。');
            return;
        }

        setLoading(button, true);

        setTimeout(function () {
            setLoading(button, false);
            renderCustomerStatus();
            renderCustomers();
            showToast('顧客一覧を更新しました');
        }, 300);
    }

    function showSettings(tab) {
        currentSettingsTab = tab || currentSettingsTab || 'mail';
        showPage('page-settings');
        renderSettings();
    }

    function renderSettings() {
        var mailTab = $('settings-tab-mail');
        var kintoneTab = $('settings-tab-kintone');

        if (mailTab) {
            mailTab.classList.toggle(
                'active',
                currentSettingsTab === 'mail'
            );
        }

        if (kintoneTab) {
            kintoneTab.classList.toggle(
                'active',
                currentSettingsTab === 'kintone'
            );
        }

        if (currentSettingsTab === 'mail') {
            renderMailSettings();
        } else {
            renderKintoneSettings();
        }
    }

    function renderMailSettings() {
        var target = $('settings-content');

        if (!target) {
            return;
        }

        var html = '<div class="card">';
        html += '<div class="card-title">メール送信設定</div>';

        html += '<div class="status-line">';
        html += '<span class="status-dot ' +
            (mailSettings.ready ? 'ok' : 'warn') +
            '"></span>';
        html += '<span>' +
            (mailSettings.ready
                ? 'メール送信可能な設定が保存されています。'
                : 'メール送信設定が未完了です。') +
            '</span>';
        html += '</div>';

        html += '<div class="form-grid">';
        html += '<div class="field">';
        html += '<label>SMTPサーバ *</label>';
        html += '<input id="smtp-server">';
        html += '</div>';

        html += '<div class="field">';
        html += '<label>ポート番号 *</label>';
        html += '<input id="smtp-port">';
        html += '</div>';
        html += '</div>';

        html += '<div class="field">';
        html += '<label>接続方式</label>';
        html += '<select id="smtp-security">';
        html += '<option value="なし">なし</option>';
        html += '<option value="STARTTLS">STARTTLS</option>';
        html += '<option value="SSL/TLS">SSL/TLS</option>';
        html += '</select>';
        html += '</div>';

        html += '<div class="form-grid">';
        html += '<div class="field">';
        html += '<label>認証ユーザー名</label>';
        html += '<input id="smtp-user">';
        html += '</div>';

        html += '<div class="field">';
        html += '<label>認証パスワード</label>';
        html += '<input id="smtp-password" type="password">';
        html += '</div>';
        html += '</div>';

        html += '<div class="form-grid">';
        html += '<div class="field">';
        html += '<label>送信元メールアドレス *</label>';
        html += '<input id="smtp-from">';
        html += '</div>';

        html += '<div class="field">';
        html += '<label>送信元名</label>';
        html += '<input id="smtp-from-name">';
        html += '</div>';
        html += '</div>';

        html += '<button id="btn-save-mail" class="btn btn-primary">設定を保存</button> ';
        html += '<button id="btn-test-mail" class="btn">送信設定を確認</button>';

        html += '</div>';

        target.innerHTML = html;

        var smtp = $('smtp-server');
        var port = $('smtp-port');
        var security = $('smtp-security');
        var user = $('smtp-user');
        var password = $('smtp-password');
        var from = $('smtp-from');
        var fromName = $('smtp-from-name');

        if (smtp) smtp.value = mailSettings.smtp;
        if (port) port.value = mailSettings.port;
        if (security) security.value = mailSettings.security;
        if (user) user.value = mailSettings.username;
        if (password) password.value = '';
        if (from) from.value = mailSettings.from;
        if (fromName) fromName.value = mailSettings.fromName;

        var save = $('btn-save-mail');

        if (save) {
            save.addEventListener('click', function () {
                setLoading(this, true);

                setTimeout(function () {
                    saveMailSettings(save);
                    setLoading(save, false);
                }, 150);
            });
        }

        var test = $('btn-test-mail');

        if (test) {
            test.addEventListener('click', function () {
                setLoading(this, true);

                setTimeout(function () {
                    setLoading(test, false);
                    testMailSettings();
                }, 150);
            });
        }
    }

    function saveMailSettings() {
        var smtp = $('smtp-server');
        var port = $('smtp-port');
        var security = $('smtp-security');
        var user = $('smtp-user');
        var password = $('smtp-password');
        var from = $('smtp-from');
        var fromName = $('smtp-from-name');

        mailSettings.smtp = smtp ? smtp.value.trim() : '';
        mailSettings.port = port ? port.value.trim() : '';
        mailSettings.security = security ? security.value : 'STARTTLS';
        mailSettings.username = user ? user.value.trim() : '';

        if (password && password.value !== '') {
            mailSettings.password = password.value;
        }

        mailSettings.from = from ? from.value.trim() : '';
        mailSettings.fromName = fromName ? fromName.value.trim() : '';

        if (
            !mailSettings.smtp ||
            !mailSettings.port ||
            !mailSettings.from
        ) {
            alert('SMTPサーバ、ポート番号、送信元メールアドレスを入力してください。');
            return;
        }

        mailSettings.ready = true;
        renderMailSettings();
        showToast('メール送信設定を保存しました');
    }

    function testMailSettings() {
        if (!mailSettings.ready) {
            alert('先に設定を保存してください。');
            return;
        }

        openModal(
            'メール送信設定の確認',
            '<div class="notice success">メール送信設定を確認しました。</div>' +
            '<p><strong>SMTPサーバ：</strong>' +
            escapeHtml(mailSettings.smtp) +
            '</p>' +
            '<p><strong>ポート：</strong>' +
            escapeHtml(mailSettings.port) +
            '</p>' +
            '<p><strong>接続方式：</strong>' +
            escapeHtml(mailSettings.security) +
            '</p>' +
            '<p><strong>送信元：</strong>' +
            escapeHtml(mailSettings.fromName) +
            ' &lt;' +
            escapeHtml(mailSettings.from) +
            '&gt;</p>',
            '<button class="btn" id="mail-test-close">閉じる</button>'
        );

        var close = $('mail-test-close');

        if (close) {
            close.addEventListener('click', closeModal);
        }
    }

    function renderKintoneSettings() {
        var target = $('settings-content');

        if (!target) {
            return;
        }

        var html = '<div class="card">';
        html += '<div class="card-title">キントーン設定</div>';

        html += '<div class="status-line">';
        html += '<span class="status-dot ' +
            (kintoneSettings.ready ? 'ok' : 'warn') +
            '"></span>';
        html += '<span>' +
            (kintoneSettings.ready
                ? '顧客一覧を取得できる設定が保存されています。'
                : 'キントーン設定が未完了です。') +
            '</span>';
        html += '</div>';

        html += '<div class="notice">';
        html += '顧客一覧の取得には、キントーンのログイン名・パスワードを使用します。';
        html += '</div>';

        html += '<div class="field">';
        html += '<label>キントーンの利用先 *</label>';
        html += '<input id="kt-domain">';
        html += '</div>';

        html += '<div class="field">';
        html += '<label>顧客管理アプリID *</label>';
        html += '<input id="kt-appid">';
        html += '</div>';

        html += '<div class="form-grid">';
        html += '<div class="field">';
        html += '<label>ログイン名 *</label>';
        html += '<input id="kt-user">';
        html += '</div>';

        html += '<div class="field">';
        html += '<label>パスワード *</label>';
        html += '<input id="kt-password" type="password">';
        html += '</div>';
        html += '</div>';

        html += '<div class="card" style="background:#fafbfc">';
        html += '<div class="card-title">接続経路</div>';

        html += '<div class="field">';
        html += '<label>プロキシ ホスト名</label>';
        html += '<input id="kt-proxy-host">';
        html += '</div>';

        html += '<div class="field">';
        html += '<label>プロキシ ポート番号</label>';
        html += '<input id="kt-proxy-port">';
        html += '</div>';

        html += '<p>SSL証明書の検証：無効</p>';
        html += '</div>';

        html += '<button id="btn-save-kintone" class="btn btn-primary">設定を保存</button> ';
        html += '<button id="btn-test-kintone" class="btn">接続設定を確認</button>';

        html += '</div>';

        target.innerHTML = html;

        var domain = $('kt-domain');
        var appId = $('kt-appid');
        var user = $('kt-user');
        var password = $('kt-password');
        var proxyHost = $('kt-proxy-host');
        var proxyPort = $('kt-proxy-port');

        if (domain) domain.value = kintoneSettings.domain;
        if (appId) appId.value = kintoneSettings.appId;
        if (user) user.value = kintoneSettings.loginName;
        if (password) password.value = '';
        if (proxyHost) proxyHost.value = kintoneSettings.proxyHost;
        if (proxyPort) proxyPort.value = kintoneSettings.proxyPort;

        var save = $('btn-save-kintone');

        if (save) {
            save.addEventListener('click', function () {
                setLoading(this, true);

                setTimeout(function () {
                    saveKintoneSettings();
                    setLoading(save, false);
                }, 150);
            });
        }

        var test = $('btn-test-kintone');

        if (test) {
            test.addEventListener('click', function () {
                setLoading(this, true);

                setTimeout(function () {
                    setLoading(test, false);
                    testKintoneSettings();
                }, 150);
            });
        }
    }

    function saveKintoneSettings() {
        var domain = $('kt-domain');
        var appId = $('kt-appid');
        var user = $('kt-user');
        var password = $('kt-password');
        var proxyHost = $('kt-proxy-host');
        var proxyPort = $('kt-proxy-port');

        kintoneSettings.domain = domain ? domain.value.trim() : '';
        kintoneSettings.appId = appId ? appId.value.trim() : '';
        kintoneSettings.loginName = user ? user.value.trim() : '';

        if (password && password.value !== '') {
            kintoneSettings.password = password.value;
        }

        kintoneSettings.proxyHost =
            proxyHost ? proxyHost.value.trim() : '';

        kintoneSettings.proxyPort =
            proxyPort ? proxyPort.value.trim() : '';

        if (
            !kintoneSettings.domain ||
            !kintoneSettings.appId ||
            !kintoneSettings.loginName ||
            !kintoneSettings.password
        ) {
            alert('利用先、顧客管理アプリID、ログイン名、パスワードを入力してください。');
            return;
        }

        if (
            kintoneSettings.proxyHost &&
            !kintoneSettings.proxyPort
        ) {
            alert('プロキシのホスト名を入力した場合は、ポート番号も入力してください。');
            return;
        }

        kintoneSettings.proxyAuth = false;
        kintoneSettings.sslVerify = false;
        kintoneSettings.ready = true;

        renderKintoneSettings();
        showToast('キントーン設定を保存しました');
    }

    function testKintoneSettings() {
        if (!kintoneSettings.ready) {
            alert('先に設定を保存してください。');
            return;
        }

        var proxy = kintoneSettings.proxyHost
            ? kintoneSettings.proxyHost + ':' +
              kintoneSettings.proxyPort
            : '使用しない';

        openModal(
            'キントーン接続設定の確認',
            '<div class="notice success">キントーン接続設定を確認しました。</div>' +
            '<p><strong>利用先：</strong>' +
            escapeHtml(kintoneSettings.domain) +
            '</p>' +
            '<p><strong>顧客管理アプリ：</strong>' +
            escapeHtml(kintoneSettings.appId) +
            '</p>' +
            '<p><strong>ログイン名：</strong>' +
            escapeHtml(kintoneSettings.loginName) +
            '</p>' +
            '<p><strong>プロキシ：</strong>' +
            escapeHtml(proxy) +
            '</p>' +
            '<p><strong>SSL証明書検証：</strong>無効</p>',
            '<button class="btn" id="kintone-test-close">閉じる</button>'
        );

        var close = $('kintone-test-close');

        if (close) {
            close.addEventListener('click', closeModal);
        }
    }

    function openModal(title, body, footer) {
        var modal = $('modal');
        var modalTitle = $('modal-title');
        var modalBody = $('modal-body');
        var modalFooter = $('modal-footer');

        if (!modal || !modalTitle || !modalBody || !modalFooter) {
            return;
        }

        modalTitle.textContent = title;
        modalBody.innerHTML = body;
        modalFooter.innerHTML = footer || '';
        modal.classList.remove('hidden');
    }

    function closeModal() {
        var modal = $('modal');

        if (modal) {
            modal.classList.add('hidden');
        }
    }

    function showToast(message) {
        var toast = $('toast');

        if (!toast) {
            return;
        }

        toast.textContent = message;
        toast.classList.add('show');

        setTimeout(function () {
            if (toast) {
                toast.classList.remove('show');
            }
        }, 2200);
    }

    var navList = $('nav-list');
    if (navList) {
        navList.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    var navCreate = $('nav-create');
    if (navCreate) {
        navCreate.addEventListener('click', openCreate);
    }

    var navCustomers = $('nav-customers');
    if (navCustomers) {
        navCustomers.addEventListener('click', showCustomers);
    }

    var navSettings = $('nav-settings');
    if (navSettings) {
        navSettings.addEventListener('click', function () {
            showSettings('mail');
        });
    }

    var createButton = $('btn-create');
    if (createButton) {
        createButton.addEventListener('click', openCreate);
    }

    var addGroupButton = $('btn-add-group');
    if (addGroupButton) {
        addGroupButton.addEventListener('click', addGroup);
    }

    var editorBack = $('btn-editor-back');
    if (editorBack) {
        editorBack.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    var previewButton = $('btn-preview');
    if (previewButton) {
        previewButton.addEventListener('click', previewEditor);
    }

    var saveButton = $('btn-save');
    if (saveButton) {
        saveButton.addEventListener('click', function () {
            saveSurvey(saveButton);
        });
    }

    ['survey-name','survey-description','survey-status',
     'survey-start','survey-end'].forEach(function (id) {
        var element = $(id);

        if (element) {
            element.addEventListener('input', syncEditorHeader);
            element.addEventListener('change', syncEditorHeader);
        }
    });

    document.querySelectorAll('input[name="numbering"]').forEach(function (radio) {
        if (radio) {
            radio.addEventListener('change', function () {
                var s = syncEditorHeader();
                renderEditorGroups(s);
            });
        }
    });

    var detailEdit = $('btn-detail-edit');
    if (detailEdit) {
        detailEdit.addEventListener('click', editCurrentSurvey);
    }

    var detailSend = $('btn-detail-send');
    if (detailSend) {
        detailSend.addEventListener('click', function () {
            showDetailTab('send');
        });
    }

    var detailBack = $('btn-detail-back');
    if (detailBack) {
        detailBack.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    ['tab-content','tab-send','tab-status','tab-result'].forEach(function (id) {
        var button = $(id);

        if (button) {
            button.addEventListener('click', function () {
                var tab = id.replace('tab-', '');
                showDetailTab(tab);
            });
        }
    });

    var customerSettings = $('btn-customer-settings');
    if (customerSettings) {
        customerSettings.addEventListener('click', function () {
            showSettings('kintone');
        });
    }

    var customerSearch = $('customer-search');
    if (customerSearch) {
        customerSearch.addEventListener('input', renderCustomers);
    }

    var refreshCustomersButton = $('btn-refresh-customers');
    if (refreshCustomersButton) {
        refreshCustomersButton.addEventListener('click', function () {
            refreshCustomers(refreshCustomersButton);
        });
    }

    var mailSettingsTab = $('settings-tab-mail');
    if (mailSettingsTab) {
        mailSettingsTab.addEventListener('click', function () {
            showSettings('mail');
        });
    }

    var kintoneSettingsTab = $('settings-tab-kintone');
    if (kintoneSettingsTab) {
        kintoneSettingsTab.addEventListener('click', function () {
            showSettings('kintone');
        });
    }

    var modalClose = $('modal-close');
    if (modalClose) {
        modalClose.addEventListener('click', closeModal);
    }

    var modal = $('modal');
    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    renderList();
    showPage('page-list');
});
</script>
</body>
</html>

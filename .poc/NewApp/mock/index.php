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
.hidden{display:none!important}

.topbar{
    min-height:58px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:28px;
}
.logo{
    font-size:18px;
    font-weight:bold;
    white-space:nowrap;
}
.main-nav{
    display:flex;
    min-height:58px;
    align-items:center;
    gap:2px;
    flex-wrap:wrap;
}
.main-nav button{
    min-height:58px;
    padding:0 15px;
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
    gap:15px;
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
.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
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
.btn-success{
    background:#2d8a58;
    border-color:#2d8a58;
    color:#fff;
}

.table{
    width:100%;
    border-collapse:collapse;
}
.table th,
.table td{
    padding:12px 14px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    font-size:13px;
}
.table th{
    background:#f7f9fb;
    color:#607080;
}
.link-button{
    border:0;
    background:none;
    color:#2878c8;
    padding:0;
    text-align:left;
}
.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:20px;
    font-size:11px;
}
.badge-open{background:#e5f6ec;color:#267247}
.badge-end{background:#edf0f3;color:#596775}
.badge-draft{background:#fff3d9;color:#8b6418}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:15px;
}
.field{
    margin-bottom:15px;
}
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
    flex-wrap:wrap;
    align-items:center;
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
.success-notice{
    padding:12px;
    border-radius:5px;
    background:#e9f8ef;
    border:1px solid #bde4ca;
    color:#267247;
    margin-bottom:15px;
}
.warning-notice{
    padding:12px;
    border-radius:5px;
    background:#fff7df;
    border:1px solid #f0dda5;
    color:#7c5d15;
    margin-bottom:15px;
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
.group-card.dragging{
    opacity:.45;
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
.question-card.drop-target{
    border:2px dashed #2878c8;
    background:#f2f8ff;
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
.question-tools select{
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:6px;
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
    width:300px!important;
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

.send-layout{
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:18px;
}
.customer-list{
    max-height:480px;
    overflow:auto;
}
.customer-row{
    display:flex;
    align-items:center;
    gap:10px;
    padding:11px 14px;
    border-bottom:1px solid #e6ebef;
}
.customer-row:last-child{border-bottom:0}
.customer-info{
    flex:1;
}
.customer-name{
    font-weight:bold;
}
.customer-email{
    font-size:12px;
    color:#718096;
}
.selected-count{
    padding:10px 12px;
    background:#f5f8fb;
    border-bottom:1px solid #e1e7ed;
    font-size:13px;
}
.confirm-box{
    background:#f7f9fb;
    border:1px solid #dfe5eb;
    padding:15px;
    border-radius:6px;
    margin-bottom:15px;
}
.selected-customer{
    display:inline-block;
    background:#e9f2fb;
    color:#2d5d89;
    border-radius:4px;
    padding:4px 8px;
    margin:3px;
    font-size:12px;
}

.setting-tabs{
    display:flex;
    gap:5px;
    margin-bottom:18px;
}
.setting-tabs button.active{
    background:#2878c8;
    color:#fff;
    border-color:#2878c8;
}
.setting-panel{
    max-width:900px;
    padding:22px;
}
.setting-status{
    display:inline-block;
    padding:5px 9px;
    border-radius:15px;
    font-size:12px;
    margin-bottom:15px;
}
.status-ok{
    background:#e6f6ec;
    color:#267247;
}
.status-ng{
    background:#fff0ef;
    color:#a43e37;
}

.answer-page{
    max-width:800px;
    margin:0 auto;
    padding:35px 20px;
}
.answer-page .answer-header{
    margin-bottom:30px;
}
.answer-question{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:18px;
}
.answer-question h3{
    margin:0 0 15px;
}
.answer-question textarea{
    width:100%;
    min-height:110px;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:10px;
}
.answer-option{
    display:block;
    padding:9px 0;
}
.answer-complete{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:35px;
    text-align:center;
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
    z-index:1000;
}
.toast.show{
    opacity:1;
    transform:translateY(0);
}

@media(max-width:900px){
    .topbar{
        padding:0 10px;
        gap:10px;
        align-items:stretch;
    }
    .logo{
        display:none;
    }
    .main-nav button{
        padding:0 8px;
    }
    .app{
        padding:14px;
    }
    .form-grid,
    .send-layout{
        grid-template-columns:1fr;
    }
    .detail-summary{
        grid-template-columns:1fr 1fr;
    }
    .question-options,
    .question-meta{
        padding-left:0;
    }
    .question-head{
        align-items:flex-start;
        flex-wrap:wrap;
    }
    .branch-select{
        width:100%!important;
    }
    .table{
        min-width:900px;
    }
}
</style>
</head>
<body>

<header id="operator-header" class="topbar">
    <div class="logo">アンケート業務運営</div>
    <nav class="main-nav">
        <button id="nav-list" onclick="showList()">アンケート一覧</button>
        <button id="nav-create" onclick="openCreate()">アンケート作成</button>
        <button id="nav-customers" onclick="showCustomers()">顧客一覧</button>
        <button id="nav-settings" onclick="showSettings()">設定</button>
    </nav>
</header>

<main id="operator-main" class="app">

<!-- ======================================================
     アンケート一覧
====================================================== -->
<section id="page-list">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>
        <button class="btn btn-primary" onclick="openCreate()">＋ アンケート作成</button>
    </div>

    <div class="card" style="overflow:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>アンケート名</th>
                    <th>状態</th>
                    <th>作成日</th>
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

<!-- ======================================================
     アンケート作成・編集
====================================================== -->
<section id="page-editor" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="editor-page-title">アンケート作成</h1>
            <div class="subtext">アンケート全体を1画面で編集できます</div>
        </div>
    </div>

    <div class="notice">
        質問とグループはドラッグ＆ドロップで並べ替えできます。
        質問番号は設定した方式に応じて自動更新されます。
    </div>

    <div class="card editor-card">
        <div class="form-grid">
            <div class="field">
                <label>アンケート名 *</label>
                <input id="survey-name" type="text">
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
                    <input type="radio" name="numbering" value="global"
                           checked onchange="changeNumbering(this.value)">
                    全体で通番（Q1、Q2、Q3…）
                </label>
                <label>
                    <input type="radio" name="numbering" value="group"
                           onchange="changeNumbering(this.value)">
                    グループごと（Q1-1、Q1-2、Q2-1…）
                </label>
            </div>
        </div>
    </div>

    <div id="groups"></div>

    <div class="add-group-area">
        <button class="btn btn-primary" onclick="addGroup()">＋ グループ追加</button>
    </div>

    <div style="display:flex;justify-content:space-between;margin-top:20px">
        <button class="btn" onclick="showList()">一覧へ戻る</button>
        <button class="btn btn-primary" onclick="saveSurvey()">保存</button>
    </div>
</section>

<!-- ======================================================
     個別アンケート
====================================================== -->
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
        <button id="tab-send" onclick="showDetailTab('send')">送信</button>
        <button id="tab-status" onclick="showDetailTab('status')">回答状況</button>
        <button id="tab-result" onclick="showDetailTab('result')">回答結果</button>
    </div>

    <div id="detail-content"></div>
</section>

<!-- ======================================================
     顧客一覧
====================================================== -->
<section id="page-customers" class="hidden">
    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">キントーンの顧客管理アプリから取得した顧客です</div>
        </div>
        <button class="btn btn-primary" onclick="loadCustomers()">顧客一覧を取得</button>
    </div>

    <div id="customer-status"></div>

    <div class="card" style="padding:15px;margin-bottom:15px">
        <div class="field" style="margin:0">
            <label>顧客検索</label>
            <input id="customer-search" type="text"
                   placeholder="顧客名・メールアドレスで検索"
                   oninput="renderCustomers()">
        </div>
    </div>

    <div class="card" style="overflow:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>顧客名</th>
                    <th>メールアドレス</th>
                    <th>会社名</th>
                    <th>顧客ID</th>
                </tr>
            </thead>
            <tbody id="customer-list-body"></tbody>
        </table>
    </div>
</section>

<!-- ======================================================
     設定
====================================================== -->
<section id="page-settings" class="hidden">
    <div class="page-header">
        <div>
            <h1>設定</h1>
            <div class="subtext">メール送信と顧客一覧取得に必要な設定を行います</div>
        </div>
    </div>

    <div class="setting-tabs">
        <button id="setting-tab-smtp" class="btn active"
                onclick="showSettingTab('smtp')">メール送信設定</button>
        <button id="setting-tab-kintone" class="btn"
                onclick="showSettingTab('kintone')">キントーン設定</button>
    </div>

    <div id="setting-smtp" class="card setting-panel">
        <h2 style="margin-top:0">メール送信設定</h2>
        <div id="smtp-status"></div>

        <div class="form-grid">
            <div class="field">
                <label>SMTPサーバ *</label>
                <input id="smtp-host" type="text" placeholder="smtp.example.com">
            </div>
            <div class="field">
                <label>ポート番号 *</label>
                <input id="smtp-port" type="text" placeholder="587">
            </div>
        </div>

        <div class="field">
            <label>接続方式</label>
            <select id="smtp-security">
                <option value="tls">STARTTLS</option>
                <option value="ssl">SSL/TLS</option>
                <option value="none">暗号化なし</option>
            </select>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>認証ユーザー名</label>
                <input id="smtp-user" type="text">
            </div>
            <div class="field">
                <label>認証パスワード</label>
                <input id="smtp-password" type="password">
            </div>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>送信元メールアドレス *</label>
                <input id="smtp-from" type="email">
            </div>
            <div class="field">
                <label>送信元名</label>
                <input id="smtp-from-name" type="text">
            </div>
        </div>

        <div>
            <button class="btn btn-primary" onclick="saveSmtp()">メール設定を保存</button>
            <button class="btn" onclick="testSmtp()">送信可能か確認</button>
        </div>
    </div>

    <div id="setting-kintone" class="card setting-panel hidden">
        <h2 style="margin-top:0">キントーン設定</h2>
        <div id="kintone-status"></div>

        <div class="field">
            <label>キントーンの利用先 *</label>
            <input id="kintone-domain" type="text"
                   placeholder="example.cybozu.com">
        </div>

        <div class="field">
            <label>顧客管理アプリID *</label>
            <input id="kintone-app-id" type="text"
                   placeholder="123">
        </div>

        <div class="field">
            <label>顧客一覧取得に必要な設定情報</label>
            <textarea id="kintone-token"
                      placeholder="顧客管理アプリとの接続に使用する設定情報"></textarea>
        </div>

        <div>
            <button class="btn btn-primary" onclick="saveKintone()">キントーン設定を保存</button>
            <button class="btn" onclick="testKintone()">顧客一覧を取得できるか確認</button>
        </div>
    </div>
</section>

</main>

<!-- ======================================================
     回答者画面
     運営者用ヘッダーは表示しない
====================================================== -->
<section id="page-answer" class="hidden">
    <div class="answer-page">
        <div id="answer-body"></div>
    </div>
</section>

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
        sent:180,
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
                            {text:'不満',branch:''}
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
        created:'2026-09-05',
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
    {id:'C001',name:'山田 太郎',email:'yamada@example.com',company:'株式会社サンプル'},
    {id:'C002',name:'佐藤 花子',email:'sato@example.com',company:'株式会社テスト'},
    {id:'C003',name:'鈴木 一郎',email:'suzuki@example.com',company:'サンプル商事'},
    {id:'C004',name:'田中 美咲',email:'tanaka@example.com',company:'株式会社サンプル'},
    {id:'C005',name:'高橋 健',email:'takahashi@example.com',company:'テスト株式会社'},
    {id:'C006',name:'伊藤 直子',email:'ito@example.com',company:'株式会社サンプル'}
];

var smtpSettings = {
    host:'',
    port:'587',
    security:'tls',
    user:'',
    password:'',
    from:'',
    fromName:''
};

var kintoneSettings = {
    domain:'',
    appId:'',
    token:''
};

var editingSurvey = null;
var currentSurveyId = null;
var currentDetailTab = 'content';
var nextGroupId = 500;
var nextQuestionId = 5000;
var draggedQuestion = null;
var draggedGroup = null;
var selectedCustomers = [];
var sendMail = {
    subject:'',
    body:''
};

function $(id){
    return document.getElementById(id);
}

function escapeHtml(str){
    return String(str == null ? '' : str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function clone(obj){
    return JSON.parse(JSON.stringify(obj));
}

function hideAllPages(){
    [
        'page-list',
        'page-editor',
        'page-detail',
        'page-customers',
        'page-settings',
        'page-answer'
    ].forEach(function(id){
        $(id).classList.add('hidden');
    });
}

function clearNav(){
    ['nav-list','nav-create','nav-customers','nav-settings'].forEach(function(id){
        $(id).classList.remove('active');
    });
}

function showPage(id,navId){
    hideAllPages();
    clearNav();
    $(id).classList.remove('hidden');
    if(navId){
        $(navId).classList.add('active');
    }
}

function showList(){
    renderList();
    showPage('page-list','nav-list');
}

function statusBadge(status){
    if(status === 'open'){
        return '<span class="badge badge-open">公開中</span>';
    }
    if(status === 'end'){
        return '<span class="badge badge-end">終了</span>';
    }
    return '<span class="badge badge-draft">下書き</span>';
}

function renderList(){
    var body = $('survey-list-body');

    if(!surveys.length){
        body.innerHTML='<tr><td colspan="7">アンケートがありません。</td></tr>';
        return;
    }

    body.innerHTML=surveys.map(function(s){
        var period=(s.start || s.end)
            ? escapeHtml(s.start || '未設定')+' ～ '+escapeHtml(s.end || '未設定')
            : '未設定';

        return '<tr>'+
            '<td><button class="link-button" onclick="openDetail('+s.id+')">'+
                escapeHtml(s.name)+'</button></td>'+
            '<td>'+statusBadge(s.status)+'</td>'+
            '<td>'+escapeHtml(s.created || '-')+'</td>'+
            '<td>'+period+'</td>'+
            '<td>'+s.answers+'件</td>'+
            '<td>'+escapeHtml(s.updated || '-')+'</td>'+
            '<td>'+
                '<button class="btn btn-small" onclick="editSurvey('+s.id+')">編集</button> '+
                '<button class="btn btn-small" onclick="openDetail('+s.id+')">確認</button> '+
                (s.status === 'open'
                    ? '<button class="btn btn-small btn-danger" onclick="endSurvey('+s.id+')">終了</button>'
                    : '')+
                (s.status === 'draft'
                    ? '<button class="btn btn-small btn-danger" onclick="deleteSurvey('+s.id+')">削除</button>'
                    : '')+
            '</td>'+
        '</tr>';
    }).join('');
}

/* ======================================================
   作成・編集
====================================================== */

function openCreate(){
    editingSurvey={
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
    showPage('page-editor','nav-create');
}

function editSurvey(id){
    var survey=findSurvey(id);
    if(!survey){return;}

    editingSurvey=clone(survey);
    $('editor-page-title').textContent='アンケート編集';
    loadEditor();
    showPage('page-editor','nav-create');
}

function editCurrentSurvey(){
    if(currentSurveyId !== null){
        editSurvey(currentSurveyId);
    }
}

function findSurvey(id){
    for(var i=0;i<surveys.length;i++){
        if(String(surveys[i].id) === String(id)){
            return surveys[i];
        }
    }
    return null;
}

function loadEditor(){
    $('survey-name').value=editingSurvey.name || '';
    $('survey-description').value=editingSurvey.description || '';
    $('survey-status').value=editingSurvey.status || 'draft';
    $('survey-start').value=editingSurvey.start || '';
    $('survey-end').value=editingSurvey.end || '';

    var radios=document.querySelectorAll('input[name="numbering"]');
    for(var i=0;i<radios.length;i++){
        radios[i].checked=(radios[i].value === editingSurvey.numbering);
    }

    renderEditor();
}

function changeNumbering(value){
    editingSurvey.numbering=value;
    renderEditor();
}

function getQuestionNumber(groupIndex,questionIndex){
    if(editingSurvey.numbering === 'group'){
        return 'Q'+(groupIndex+1)+'-'+(questionIndex+1);
    }

    var n=0;
    for(var i=0;i<groupIndex;i++){
        n+=editingSurvey.groups[i].questions.length;
    }
    return 'Q'+(n+questionIndex+1);
}

function getQuestionLabelById(id){
    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        for(var qi=0;qi<editingSurvey.groups[gi].questions.length;qi++){
            if(String(editingSurvey.groups[gi].questions[qi].id) === String(id)){
                return getQuestionNumber(gi,qi)+'：'+
                    (editingSurvey.groups[gi].questions[qi].text || '（未入力）');
            }
        }
    }
    return '無効な質問';
}

function renderEditor(){
    var html='';

    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        var group=editingSurvey.groups[gi];

        html+='<div class="group-card" draggable="true" '+
            'data-group-id="'+group.id+'" '+
            'ondragstart="dragGroupStart(event,'+group.id+')" '+
            'ondragover="allowDrop(event)" '+
            'ondrop="dropGroup(event,'+group.id+')">';

        html+='<div class="group-header">';
        html+='<span class="drag-handle" title="ドラッグしてグループを移動">☷</span>';
        html+='<div class="group-title">';
        html+='<input value="'+escapeHtml(group.name)+'" '+
            'oninput="updateGroupName('+group.id+',this.value)">';
        html+='</div>';
        html+='<div class="group-actions">';
        html+='<button class="btn btn-small btn-danger" '+
            'onclick="deleteGroup('+group.id+')">グループ削除</button>';
        html+='</div>';
        html+='</div>';

        html+='<div class="questions">';

        for(var qi=0;qi<group.questions.length;qi++){
            html+=renderQuestion(group,group.questions[qi],
                getQuestionNumber(gi,qi));
        }

        html+='</div>';

        html+='<div class="add-question-area">';
        html+='<button class="btn btn-small btn-primary" '+
            'onclick="addQuestion('+group.id+')">＋ 質問追加</button>';
        html+='</div>';

        html+='</div>';
    }

    $('groups').innerHTML=html;
}

function findQuestion(id){
    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        for(var qi=0;qi<editingSurvey.groups[gi].questions.length;qi++){
            if(String(editingSurvey.groups[gi].questions[qi].id) === String(id)){
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

function renderBranchOptions(q,opt){
    var html='';

    html+='<select class="branch-select" '+
        'onchange="updateBranch('+q.id+','+opt.index+',this.value)">';

    html+='<option value="">分岐なし（次の質問へ）</option>';

    html+='<option value="__NEXT__"'+
        (opt.branch === '__NEXT__' ? ' selected':'')+
        '>次の質問へ</option>';

    html+='<option value="__END__"'+
        (opt.branch === '__END__' ? ' selected':'')+
        '>アンケート終了</option>';

    html+='<optgroup label="指定した質問へ">';

    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        for(var qi=0;qi<editingSurvey.groups[gi].questions.length;qi++){
            var target=editingSurvey.groups[gi].questions[qi];

            if(target.id === q.id){
                continue;
            }

            var selected=String(opt.branch) === String(target.id)
                ? ' selected':'';

            html+='<option value="'+target.id+'"'+selected+'>'+
                escapeHtml(getQuestionNumber(gi,qi)+'：'+
                (target.text || '（未入力）'))+
                '</option>';
        }
    }

    html+='</optgroup>';
    html+='</select>';

    return html;
}

function renderQuestion(group,q,qNo){
    var typeLabel={
        free:'自由記述',
        single:'単一選択',
        multiple:'複数選択'
    }[q.type] || '';

    var html='';

    html+='<div class="question-card" draggable="true" '+
        'data-question-id="'+q.id+'" '+
        'ondragstart="dragQuestionStart(event,'+group.id+','+q.id+')" '+
        'ondragover="allowDrop(event)" '+
        'ondrop="dropQuestion(event,'+group.id+','+q.id+')">';

    html+='<div class="question-head">';
    html+='<span class="drag-handle">☷</span>';
    html+='<span class="question-number">'+qNo+'</span>';

    html+='<div class="question-title">';
    html+='<input placeholder="質問文を入力してください" '+
        'value="'+escapeHtml(q.text)+'" '+
        'oninput="updateQuestionText('+q.id+',this.value)">';
    html+='</div>';

    html+='<div class="question-tools">';
    html+='<select onchange="updateQuestionType('+q.id+',this.value)">';
    html+='<option value="free"'+
        (q.type==='free'?' selected':'')+'>自由記述</option>';
    html+='<option value="single"'+
        (q.type==='single'?' selected':'')+'>単一選択</option>';
    html+='<option value="multiple"'+
        (q.type==='multiple'?' selected':'')+'>複数選択</option>';
    html+='</select>';
    html+='<button class="btn btn-small btn-danger" '+
        'onclick="deleteQuestion('+group.id+','+q.id+')">削除</button>';
    html+='</div>';
    html+='</div>';

    html+='<div class="question-meta">';
    html+='<label><input type="checkbox" '+
        (q.required?'checked':'')+
        ' onchange="updateRequired('+q.id+',this.checked)"> 必須</label>';
    html+='<span>回答形式：'+typeLabel+'</span>';
    html+='</div>';

    if(q.type === 'single' || q.type === 'multiple'){
        html+='<div class="question-options">';
        html+='<div style="font-size:12px;color:#718096;margin-bottom:7px;">選択肢</div>';

        for(var oi=0;oi<q.options.length;oi++){
            var opt=q.options[oi];

            html+='<div class="option-row">';
            html+='<span style="width:18px;color:#718096;">'+
                (oi+1)+'.</span>';
            html+='<input value="'+escapeHtml(opt.text)+'" '+
                'oninput="updateOption('+q.id+','+oi+',this.value)">';

            if(q.type === 'single'){
                opt.index=oi;
                html+=renderBranchOptions(q,opt);
            }

            html+='<button class="btn btn-small btn-danger" '+
                'onclick="deleteOption('+q.id+','+oi+')">削除</button>';
            html+='</div>';
        }

        html+='<button class="btn btn-small" '+
            'onclick="addOption('+q.id+')">＋ 選択肢追加</button>';

        if(q.type === 'single'){
            html+='<div style="font-size:12px;color:#718096;margin-top:8px;">'+
                '単一選択では、選択肢ごとに「次の質問」「指定した質問」'+
                '「アンケート終了」を設定できます。</div>';
        }

        html+='</div>';
    }

    html+='</div>';

    return html;
}

function updateGroupName(id,value){
    for(var i=0;i<editingSurvey.groups.length;i++){
        if(editingSurvey.groups[i].id === id){
            editingSurvey.groups[i].name=value;
        }
    }
}

function updateQuestionText(id,value){
    var f=findQuestion(id);
    if(f){
        f.question.text=value;
    }
}

function updateQuestionType(id,value){
    var f=findQuestion(id);
    if(!f){return;}

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
    if(f){
        f.question.required=value;
    }
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
    if(!f){return;}

    f.question.options.push({
        text:'選択肢'+(f.question.options.length+1),
        branch:''
    });

    renderEditor();
}

function deleteOption(qid,index){
    var f=findQuestion(qid);
    if(!f){return;}

    if(f.question.options.length <= 1){
        showToast('選択肢は1つ以上必要です');
        return;
    }

    f.question.options.splice(index,1);
    renderEditor();
}

function addQuestion(groupId){
    var group=null;

    for(var i=0;i<editingSurvey.groups.length;i++){
        if(editingSurvey.groups[i].id === groupId){
            group=editingSurvey.groups[i];
            break;
        }
    }

    if(!group){return;}

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
        if(cards.length){
            cards[cards.length-1].scrollIntoView({
                behavior:'smooth',
                block:'center'
            });
        }
    },50);
}

function deleteQuestion(groupId,qid){
    var group=null;

    for(var i=0;i<editingSurvey.groups.length;i++){
        if(editingSurvey.groups[i].id === groupId){
            group=editingSurvey.groups[i];
            break;
        }
    }

    if(!group){return;}
    if(!confirm('この質問を削除しますか？')){return;}

    group.questions=group.questions.filter(function(q){
        return q.id !== qid;
    });

    clearInvalidBranches();
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
        if(cards.length){
            cards[cards.length-1].scrollIntoView({
                behavior:'smooth',
                block:'center'
            });
        }
    },50);
}

function deleteGroup(groupId){
    var index=-1;

    for(var i=0;i<editingSurvey.groups.length;i++){
        if(editingSurvey.groups[i].id === groupId){
            index=i;
            break;
        }
    }

    if(index < 0){return;}

    var group=editingSurvey.groups[index];

    if(group.questions.length){
        if(!confirm('このグループと、グループ内の質問をすべて削除しますか？')){
            return;
        }
    }else{
        if(!confirm('このグループを削除しますか？')){
            return;
        }
    }

    editingSurvey.groups.splice(index,1);
    clearInvalidBranches();
    renderEditor();
}

function clearInvalidBranches(){
    var valid={};

    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        for(var qi=0;qi<editingSurvey.groups[gi].questions.length;qi++){
            valid[editingSurvey.groups[gi].questions[qi].id]=true;
        }
    }

    for(var g=0;g<editingSurvey.groups.length;g++){
        for(var q=0;q<editingSurvey.groups[g].questions.length;q++){
            var question=editingSurvey.groups[g].questions[q];

            if(question.type !== 'single'){
                continue;
            }

            for(var o=0;o<question.options.length;o++){
                var branch=question.options[o].branch;

                if(branch && branch !== '__NEXT__' &&
                   branch !== '__END__' && !valid[branch]){
                    question.options[o].branch='';
                }
            }
        }
    }
}

/* ======================================================
   ドラッグ＆ドロップ
====================================================== */

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
    event.currentTarget.classList.add('dragging');
}

function allowDrop(event){
    event.preventDefault();
    event.dataTransfer.dropEffect='move';
}

function dropQuestion(event,targetGroupId,targetQuestionId){
    event.preventDefault();

    if(!draggedQuestion){return;}

    var sourceGroup=null;
    var targetGroup=null;

    for(var i=0;i<editingSurvey.groups.length;i++){
        if(editingSurvey.groups[i].id === draggedQuestion.groupId){
            sourceGroup=editingSurvey.groups[i];
        }
        if(editingSurvey.groups[i].id === targetGroupId){
            targetGroup=editingSurvey.groups[i];
        }
    }

    if(!sourceGroup || !targetGroup){return;}

    var sourceIndex=-1;
    var targetIndex=-1;

    for(var s=0;s<sourceGroup.questions.length;s++){
        if(sourceGroup.questions[s].id === draggedQuestion.questionId){
            sourceIndex=s;
        }
    }

    for(var t=0;t<targetGroup.questions.length;t++){
        if(targetGroup.questions[t].id === targetQuestionId){
            targetIndex=t;
        }
    }

    if(sourceIndex < 0 || targetIndex < 0){return;}

    var moved=sourceGroup.questions.splice(sourceIndex,1)[0];

    if(sourceGroup === targetGroup && sourceIndex < targetIndex){
        targetIndex--;
    }

    targetGroup.questions.splice(targetIndex,0,moved);

    draggedQuestion=null;
    clearInvalidBranches();
    renderEditor();
}

function dropGroup(event,targetGroupId){
    event.preventDefault();

    if(draggedGroup === null || draggedGroup === targetGroupId){
        return;
    }

    var sourceIndex=-1;
    var targetIndex=-1;

    for(var i=0;i<editingSurvey.groups.length;i++){
        if(editingSurvey.groups[i].id === draggedGroup){
            sourceIndex=i;
        }
        if(editingSurvey.groups[i].id === targetGroupId){
            targetIndex=i;
        }
    }

    if(sourceIndex<0 || targetIndex<0){return;}

    var moved=editingSurvey.groups.splice(sourceIndex,1)[0];

    if(sourceIndex<targetIndex){
        targetIndex--;
    }

    editingSurvey.groups.splice(targetIndex,0,moved);

    draggedGroup=null;
    clearInvalidBranches();
    renderEditor();
}

/* ======================================================
   保存
====================================================== */

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

    if(editingSurvey.id === null){
        editingSurvey.id=Date.now();
        editingSurvey.created=editingSurvey.updated;
        surveys.unshift(clone(editingSurvey));
        currentSurveyId=editingSurvey.id;
        showToast('アンケートを作成しました');
    }else{
        for(var i=0;i<surveys.length;i++){
            if(surveys[i].id === editingSurvey.id){
                surveys[i]=clone(editingSurvey);
                break;
            }
        }

        currentSurveyId=editingSurvey.id;
        showToast('アンケートを保存しました');
    }

    setTimeout(function(){
        openDetail(currentSurveyId);
    },300);
}

/* ======================================================
   個別アンケート
====================================================== */

function openDetail(id){
    var survey=findSurvey(id);
    if(!survey){return;}

    currentSurveyId=id;

    $('detail-title').textContent=survey.name;
    $('detail-subtitle').textContent=
        (survey.status==='open'?'公開中':
         survey.status==='end'?'終了':'下書き')+
        '　｜　最終更新 '+(survey.updated || '-');

    showDetailTab('content');
    showPage('page-detail',null);
}

function showDetailTab(tab){
    currentDetailTab=tab;

    ['content','send','status','result'].forEach(function(t){
        $('tab-'+t).classList.remove('active');
    });

    $('tab-'+tab).classList.add('active');

    var survey=findSurvey(currentSurveyId);
    if(!survey){return;}

    if(tab==='content'){
        renderDetailContent(survey);
    }else if(tab==='send'){
        renderSendPage(survey);
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

    html+='<div style="margin-bottom:15px;font-size:13px;color:#718096;">'+
        '質問番号：'+
        (survey.numbering==='group'
            ? 'グループごと（Q1-1、Q1-2…）'
            : '全体で通番（Q1、Q2…）')+
        '</div>';

    for(var gi=0;gi<survey.groups.length;gi++){
        var g=survey.groups[gi];

        html+='<div style="margin-top:20px;font-weight:bold;color:#34495e;">'+
            escapeHtml(g.name)+'</div>';

        for(var qi=0;qi<g.questions.length;qi++){
            var q=g.questions[qi];
            var qNo=getQuestionNumberForSurvey(survey,gi,qi);

            html+='<div class="preview-question">';
            html+='<div class="preview-question-title">'+
                qNo+'　'+escapeHtml(q.text || '（質問文未入力）')+
                (q.required
                    ? ' <span style="color:#d9534f;font-size:12px;">必須</span>'
                    :'')+
                '</div>';

            if(q.type==='free'){
                html+='<div class="preview-option">自由記述</div>';
            }else{
                html+='<div class="preview-option">回答形式：'+
                    (q.type==='single'?'単一選択':'複数選択')+
                    '</div>';

                for(var oi=0;oi<q.options.length;oi++){
                    var o=q.options[oi];

                    html+='<div class="preview-option">・'+
                        escapeHtml(o.text);

                    if(q.type==='single' && o.branch){
                        html+='　→ '+escapeHtml(
                            getBranchLabel(survey,o.branch)
                        );
                    }

                    html+='</div>';
                }
            }

            html+='</div>';
        }
    }

    html+='</div>';

    html+='<div style="margin-top:15px">';
    html+='<button class="btn btn-primary" onclick="openAnswerer('+survey.id+')">'+
        '回答画面を確認</button>';
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

function getBranchLabel(survey,branch){
    if(branch === '__NEXT__'){
        return '次の質問';
    }

    if(branch === '__END__'){
        return 'アンケート終了';
    }

    for(var gi=0;gi<survey.groups.length;gi++){
        for(var qi=0;qi<survey.groups[gi].questions.length;qi++){
            if(String(survey.groups[gi].questions[qi].id) === String(branch)){
                return getQuestionNumberForSurvey(survey,gi,qi);
            }
        }
    }

    return '無効な分岐先';
}

function renderDetailStatus(survey){
    var target=survey.target || 0;
    var answer=survey.answers || 0;
    var sent=survey.sent || 0;
    var rate=target ? Math.round(answer/target*100) : 0;

    if(rate>100){rate=100;}

    var html='<div class="detail-summary">';
    html+=statCard('回答数',answer+'件');
    html+=statCard('回答率',rate+'%');
    html+=statCard('未回答',Math.max(target-answer,0)+'件');
    html+=statCard('送信済み',sent+'件');
    html+='</div>';

    html+='<div class="card" style="padding:20px">';
    html+='<h3 style="margin-top:0">回答状況の推移</h3>';
    html+='<div style="height:170px;display:flex;align-items:flex-end;gap:10px;border-bottom:1px solid #ccd5de;padding:0 20px;">';

    var values=[18,25,31,43,57,76,91,105,116,128];

    for(var i=0;i<values.length;i++){
        html+='<div style="flex:1;text-align:center">';
        html+='<div style="height:'+(values[i]/140*130)+
            'px;background:#4285c5;border-radius:3px 3px 0 0;max-width:45px;margin:0 auto;"></div>';
        html+='<div style="font-size:10px;color:#718096;margin-top:5px;">'+
            (i+1)+'</div>';
        html+='</div>';
    }

    html+='</div>';
    html+='<div style="margin-top:15px;color:#718096;font-size:12px;">'+
        '日別の回答数を表示しています（モック表示）</div>';
    html+='</div>';

    $('detail-content').innerHTML=html;
}

function statCard(label,value){
    return '<div class="stat-card">'+
        '<div class="stat-label">'+escapeHtml(label)+'</div>'+
        '<div class="stat-value">'+escapeHtml(value)+'</div>'+
        '</div>';
}

function renderDetailResult(survey){
    var html='<div class="card">';

    for(var gi=0;gi<survey.groups.length;gi++){
        for(var qi=0;qi<survey.groups[gi].questions.length;qi++){
            var q=survey.groups[gi].questions[qi];
            var no=getQuestionNumberForSurvey(survey,gi,qi);

            html+='<div class="result-item">';
            html+='<div style="font-weight:bold;margin-bottom:12px;">'+
                no+'　'+escapeHtml(q.text || '（質問文未入力）')+
                '</div>';

            if(q.type==='free'){
                html+='<div style="background:#f7f9fb;padding:10px;border-radius:4px;margin-bottom:6px;">'+
                    'とても参考になりました。今後も利用したいです。</div>';
                html+='<div style="background:#f7f9fb;padding:10px;border-radius:4px;margin-bottom:6px;">'+
                    'サービスが分かりやすかったです。</div>';
                html+='<div style="background:#f7f9fb;padding:10px;border-radius:4px;">'+
                    'もう少し説明があるとよいと思います。</div>';
            }else{
                var total=survey.answers || 1;

                for(var oi=0;oi<q.options.length;oi++){
                    var count=Math.max(1,
                        Math.round(total*(0.55-(oi*0.12))));
                    var pct=Math.round(count/total*100);

                    html+='<div style="margin-top:10px;">';
                    html+='<div style="display:flex;justify-content:space-between;font-size:13px;">';
                    html+='<span>'+escapeHtml(q.options[oi].text)+'</span>';
                    html+='<span>'+count+'件（'+pct+'%）</span>';
                    html+='</div>';
                    html+='<div class="bar"><span style="width:'+pct+'%"></span></div>';
                    html+='</div>';
                }
            }

            html+='</div>';
        }
    }

    html+='</div>';

    $('detail-content').innerHTML=html;
}

/* ======================================================
   アンケート送信
====================================================== */

function renderSendPage(survey){
    selectedCustomers=[];

    var html='<div class="send-layout">';

    html+='<div>';
    html+='<div class="card">';
    html+='<div class="selected-count" id="selected-customer-count">選択：0名</div>';

    html+='<div style="padding:12px;border-bottom:1px solid #e6ebef">';
    html+='<input id="send-customer-search" type="text" '+
        'style="width:100%;padding:9px;border:1px solid #cbd5e0;border-radius:4px" '+
        'placeholder="顧客名・メールアドレスで検索" '+
        'oninput="renderSendCustomers()">';
    html+='</div>';

    html+='<div class="customer-list" id="send-customer-list"></div>';
    html+='</div>';
    html+='</div>';

    html+='<div>';
    html+='<div class="card" style="padding:20px">';
    html+='<h3 style="margin-top:0">メール内容</h3>';

    html+='<div class="field">';
    html+='<label>件名</label>';
    html+='<input id="send-subject" type="text" value="'+
        escapeHtml(survey.name+'のご案内')+'">';
    html+='</div>';

    html+='<div class="field">';
    html+='<label>本文</label>';
    html+='<textarea id="send-body" style="min-height:220px;">'+
        escapeHtml(
            '「'+survey.name+'」へのご協力をお願いいたします。\n\n'+
            '以下の回答用画面からアンケートへご回答ください。\n'+
            '回答用案内：アンケート回答ページ'
        )+
        '</textarea>';
    html+='</div>';

    html+='<div class="notice">';
    html+='顧客一覧に登録されていない人も、回答用案内から回答できます。';
    html+='</div>';

    html+='<button class="btn btn-primary" onclick="confirmSend('+survey.id+')">'+
        '送信内容を確認</button>';

    html+='</div>';
    html+='</div>';

    html+='</div>';

    $('detail-content').innerHTML=html;

    renderSendCustomers();
}

function renderSendCustomers(){
    var search=($('send-customer-search') || {value:''}).value.toLowerCase();
    var html='';

    for(var i=0;i<customers.length;i++){
        var c=customers[i];
        var target=(c.name+' '+c.email+' '+c.company).toLowerCase();

        if(search && target.indexOf(search)<0){
            continue;
        }

        var checked=selectedCustomers.indexOf(c.id)>=0;

        html+='<label class="customer-row">';
        html+='<input type="checkbox" '+
            (checked?'checked':'')+
            ' onchange="toggleCustomer(\''+c.id+'\')">';
        html+='<div class="customer-info">';
        html+='<div class="customer-name">'+escapeHtml(c.name)+'</div>';
        html+='<div class="customer-email">'+
            escapeHtml(c.email)+'　'+escapeHtml(c.company)+'</div>';
        html+='</div>';
        html+='</label>';
    }

    if(!html){
        html='<div style="padding:20px;color:#718096">該当する顧客がありません。</div>';
    }

    $('send-customer-list').innerHTML=html;

    var count=$('selected-customer-count');
    if(count){
        count.textContent='選択：'+selectedCustomers.length+'名';
    }
}

function toggleCustomer(id){
    var index=selectedCustomers.indexOf(id);

    if(index>=0){
        selectedCustomers.splice(index,1);
    }else{
        selectedCustomers.push(id);
    }

    renderSendCustomers();
}

function confirmSend(surveyId){
    var survey=findSurvey(surveyId);

    if(!survey){return;}

    if(selectedCustomers.length === 0){
        showToast('送信対象者を選択してください');
        return;
    }

    sendMail.subject=$('send-subject').value;
    sendMail.body=$('send-body').value;

    var html='<div class="card" style="padding:20px">';

    html+='<h2 style="margin-top:0">送信内容の確認</h2>';

    html+='<div class="confirm-box">';
    html+='<div><strong>アンケート：</strong>'+
        escapeHtml(survey.name)+'</div>';
    html+='<div style="margin-top:8px"><strong>送信対象：</strong>'+
        selectedCustomers.length+'名</div>';
    html+='</div>';

    html+='<div class="confirm-box">';
    html+='<strong>送信対象者</strong><div style="margin-top:8px">';

    for(var i=0;i<selectedCustomers.length;i++){
        var c=findCustomer(selectedCustomers[i]);

        if(c){
            html+='<span class="selected-customer">'+
                escapeHtml(c.name)+'（'+escapeHtml(c.email)+'）</span>';
        }
    }

    html+='</div></div>';

    html+='<div class="field">';
    html+='<label>件名</label>';
    html+='<div class="confirm-box">'+escapeHtml(sendMail.subject)+'</div>';
    html+='</div>';

    html+='<div class="field">';
    html+='<label>本文</label>';
    html+='<div class="confirm-box" style="white-space:pre-wrap;">'+
        escapeHtml(sendMail.body)+'</div>';
    html+='</div>';

    html+='<div style="display:flex;gap:8px">';
    html+='<button class="btn" onclick="renderSendPage(findSurvey('+surveyId+'))">戻る</button>';
    html+='<button class="btn btn-primary" onclick="executeSend('+surveyId+')">メールを送信する</button>';
    html+='</div>';

    html+='</div>';

    $('detail-content').innerHTML=html;
}

function executeSend(surveyId){
    var survey=findSurvey(surveyId);

    if(!survey){return;}

    if(!smtpSettings.host || !smtpSettings.from){
        showToast('メール送信設定を先に完了してください');
        return;
    }

    survey.sent=(survey.sent || 0)+selectedCustomers.length;
    survey.target=Math.max(survey.target || 0, survey.sent);
    survey.updated=new Date().toISOString().slice(0,10);

    showToast(selectedCustomers.length+'名へ送信しました');

    setTimeout(function(){
        showDetailTab('status');
    },700);
}

function findCustomer(id){
    for(var i=0;i<customers.length;i++){
        if(customers[i].id === id){
            return customers[i];
        }
    }
    return null;
}

/* ======================================================
   顧客一覧
====================================================== */

function showCustomers(){
    renderCustomers();
    showPage('page-customers','nav-customers');
}

function renderCustomers(){
    var search=($('customer-search') || {value:''}).value.toLowerCase();
    var html='';

    for(var i=0;i<customers.length;i++){
        var c=customers[i];
        var target=(c.name+' '+c.email+' '+c.company+' '+c.id).toLowerCase();

        if(search && target.indexOf(search)<0){
            continue;
        }

        html+='<tr>';
        html+='<td>'+escapeHtml(c.name)+'</td>';
        html+='<td>'+escapeHtml(c.email)+'</td>';
        html+='<td>'+escapeHtml(c.company)+'</td>';
        html+='<td>'+escapeHtml(c.id)+'</td>';
        html+='</tr>';
    }

    if(!html){
        html='<tr><td colspan="4">顧客がありません。</td></tr>';
    }

    $('customer-list-body').innerHTML=html;
}

function loadCustomers(){
    if(!kintoneSettings.domain || !kintoneSettings.appId){
        $('customer-status').innerHTML=
            '<div class="warning-notice">'+
            'キントーン設定が未完了です。設定画面で利用先と顧客管理アプリIDを入力してください。'+
            '</div>';
        return;
    }

    customers=[
        {id:'C001',name:'山田 太郎',email:'yamada@example.com',company:'株式会社サンプル'},
        {id:'C002',name:'佐藤 花子',email:'sato@example.com',company:'株式会社テスト'},
        {id:'C003',name:'鈴木 一郎',email:'suzuki@example.com',company:'サンプル商事'},
        {id:'C004',name:'田中 美咲',email:'tanaka@example.com',company:'株式会社サンプル'},
        {id:'C005',name:'高橋 健',email:'takahashi@example.com',company:'テスト株式会社'},
        {id:'C006',name:'伊藤 直子',email:'ito@example.com',company:'株式会社サンプル'},
        {id:'C007',name:'中村 陽子',email:'nakamura@example.com',company:'モック株式会社'}
    ];

    $('customer-status').innerHTML=
        '<div class="success-notice">'+
        'キントーンの顧客管理アプリから顧客一覧を取得しました。'+
        '（モック表示）</div>';

    renderCustomers();
}

/* ======================================================
   設定
====================================================== */

function showSettings(){
    showPage('page-settings','nav-settings');

    $('smtp-host').value=smtpSettings.host;
    $('smtp-port').value=smtpSettings.port;
    $('smtp-security').value=smtpSettings.security;
    $('smtp-user').value=smtpSettings.user;
    $('smtp-password').value=smtpSettings.password;
    $('smtp-from').value=smtpSettings.from;
    $('smtp-from-name').value=smtpSettings.fromName;

    $('kintone-domain').value=kintoneSettings.domain;
    $('kintone-app-id').value=kintoneSettings.appId;
    $('kintone-token').value=kintoneSettings.token;

    renderSettingStatus();
}

function showSettingTab(tab){
    $('setting-tab-smtp').classList.remove('active');
    $('setting-tab-kintone').classList.remove('active');
    $('setting-smtp').classList.add('hidden');
    $('setting-kintone').classList.add('hidden');

    if(tab === 'smtp'){
        $('setting-tab-smtp').classList.add('active');
        $('setting-smtp').classList.remove('hidden');
    }else{
        $('setting-tab-kintone').classList.add('active');
        $('setting-kintone').classList.remove('hidden');
    }
}

function saveSmtp(){
    smtpSettings.host=$('smtp-host').value.trim();
    smtpSettings.port=$('smtp-port').value.trim();
    smtpSettings.security=$('smtp-security').value;
    smtpSettings.user=$('smtp-user').value.trim();
    smtpSettings.password=$('smtp-password').value;
    smtpSettings.from=$('smtp-from').value.trim();
    smtpSettings.fromName=$('smtp-from-name').value.trim();

    if(!smtpSettings.host || !smtpSettings.port || !smtpSettings.from){
        showToast('SMTPサーバ、ポート番号、送信元メールアドレスを入力してください');
        renderSettingStatus();
        return;
    }

    showToast('メール送信設定を保存しました');
    renderSettingStatus();
}

function testSmtp(){
    saveSmtp();

    if(smtpSettings.host && smtpSettings.port && smtpSettings.from){
        $('smtp-status').innerHTML=
            '<span class="setting-status status-ok">'+
            'メール送信可能な設定です（モック確認）</span>';
    }
}

function saveKintone(){
    kintoneSettings.domain=$('kintone-domain').value.trim();
    kintoneSettings.appId=$('kintone-app-id').value.trim();
    kintoneSettings.token=$('kintone-token').value.trim();

    if(!kintoneSettings.domain || !kintoneSettings.appId){
        showToast('キントーンの利用先と顧客管理アプリIDを入力してください');
        renderSettingStatus();
        return;
    }

    showToast('キントーン設定を保存しました');
    renderSettingStatus();
}

function testKintone(){
    saveKintone();

    if(kintoneSettings.domain && kintoneSettings.appId){
        $('kintone-status').innerHTML=
            '<span class="setting-status status-ok">'+
            '顧客一覧を取得できる設定です（モック確認）</span>';
    }
}

function renderSettingStatus(){
    if(smtpSettings.host && smtpSettings.port && smtpSettings.from){
        $('smtp-status').innerHTML=
            '<span class="setting-status status-ok">'+
            'メール送信設定済み</span>';
    }else{
        $('smtp-status').innerHTML=
            '<span class="setting-status status-ng">'+
            'メール送信設定が未完了です</span>';
    }

    if(kintoneSettings.domain && kintoneSettings.appId){
        $('kintone-status').innerHTML=
            '<span class="setting-status status-ok">'+
            'キントーン設定済み</span>';
    }else{
        $('kintone-status').innerHTML=
            '<span class="setting-status status-ng">'+
            'キントーン設定が未完了です</span>';
    }
}

/* ======================================================
   回答者画面
====================================================== */

function openAnswerer(surveyId){
    var survey=findSurvey(surveyId);
    if(!survey){return;}

    hideAllPages();

    $('operator-header').classList.add('hidden');
    $('operator-main').classList.add('hidden');

    renderAnswerer(survey);
    $('page-answer').classList.remove('hidden');
}

function renderAnswerer(survey){
    var html='';

    html+='<div class="answer-header">';
    html+='<h1>'+escapeHtml(survey.name)+'</h1>';
    html+='<div style="color:#718096">'+
        escapeHtml(survey.description || '')+'</div>';
    html+='</div>';

    html+='<div id="answer-form">';

    var qIndex=0;

    for(var gi=0;gi<survey.groups.length;gi++){
        var g=survey.groups[gi];

        html+='<h2 style="font-size:19px;margin-top:30px;">'+
            escapeHtml(g.name)+'</h2>';

        for(var qi=0;qi<g.questions.length;qi++){
            var q=g.questions[qi];
            var no=getQuestionNumberForSurvey(survey,gi,qi);

            html+='<div class="answer-question" data-question-id="'+q.id+'">';
            html+='<h3>'+no+'　'+escapeHtml(q.text || '質問')+
                (q.required
                    ? ' <span style="color:#d9534f;font-size:12px;">必須</span>'
                    :'')+
                '</h3>';

            if(q.type==='free'){
                html+='<textarea name="answer_'+q.id+'" '+
                    (q.required?'data-required="1"':'')+
                    '></textarea>';
            }else if(q.type==='single'){
                for(var oi=0;oi<q.options.length;oi++){
                    html+='<label class="answer-option">';
                    html+='<input type="radio" name="answer_'+q.id+'" '+
                        'value="'+escapeHtml(q.options[oi].text)+'" '+
                        'data-branch="'+escapeHtml(q.options[oi].branch || '')+'" '+
                        (q.required?'data-required="1"':'')+
                        ' onchange="applyAnswerBranch(this,'+survey.id+')"> '+
                        escapeHtml(q.options[oi].text);
                    html+='</label>';
                }
            }else{
                for(var oi2=0;oi2<q.options.length;oi2++){
                    html+='<label class="answer-option">';
                    html+='<input type="checkbox" name="answer_'+q.id+'[]" '+
                        'value="'+escapeHtml(q.options[oi2].text)+'" '+
                        (q.required?'data-required="1"':'')+
                        '> '+
                        escapeHtml(q.options[oi2].text);
                    html+='</label>';
                }
            }

            html+='</div>';

            qIndex++;
        }
    }

    html+='<div style="text-align:center;margin-top:30px">';
    html+='<button class="btn btn-primary" onclick="submitAnswer('+survey.id+')">'+
        '回答を送信する</button>';
    html+='</div>';

    html+='</div>';

    $('answer-body').innerHTML=html;
}

function applyAnswerBranch(input,surveyId){
    var branch=input.getAttribute('data-branch');

    if(!branch){
        return;
    }

    var survey=findSurvey(surveyId);
    if(!survey){return;}

    if(branch === '__END__'){
        showToast('この回答ではアンケート終了へ進みます');
        return;
    }

    if(branch === '__NEXT__'){
        return;
    }

    var target=document.querySelector(
        '.answer-question[data-question-id="'+branch+'"]'
    );

    if(target){
        target.scrollIntoView({
            behavior:'smooth',
            block:'center'
        });
    }
}

function submitAnswer(surveyId){
    var survey=findSurvey(surveyId);
    if(!survey){return;}

    var required=document.querySelectorAll('[data-required="1"]');

    for(var i=0;i<required.length;i++){
        var el=required[i];

        if(el.type === 'radio'){
            var name=el.name;
            var checked=document.querySelector(
                'input[name="'+name+'"]:checked'
            );

            if(!checked){
                showToast('必須質問に回答してください');
                el.closest('.answer-question').scrollIntoView({
                    behavior:'smooth',
                    block:'center'
                });
                return;
            }
        }else if(el.type === 'checkbox'){
            var group=document.querySelectorAll(
                'input[name="'+el.name+'"]:checked'
            );

            if(!group.length){
                showToast('必須質問に回答してください');
                return;
            }
        }else if(!el.value.trim()){
            showToast('必須質問に回答してください');
            el.focus();
            return;
        }
    }

    survey.answers=(survey.answers || 0)+1;

    $('answer-body').innerHTML=
        '<div class="answer-complete">'+
        '<h1>回答ありがとうございました</h1>'+
        '<p style="color:#718096;margin-top:15px;">'+
        'アンケートへの回答が完了しました。'+
        '</p>'+
        '</div>';
}

/* ======================================================
   アンケート終了・削除
====================================================== */

function endSurvey(id){
    var survey=findSurvey(id);
    if(!survey){return;}

    if(!confirm('このアンケートを終了しますか？')){
        return;
    }

    survey.status='end';
    survey.updated=new Date().toISOString().slice(0,10);

    renderList();
    showToast('アンケートを終了しました');
}

function deleteSurvey(id){
    if(!confirm('この下書きを削除しますか？')){
        return;
    }

    surveys=surveys.filter(function(s){
        return s.id !== id;
    });

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
    },2500);
}

document.addEventListener('dragend',function(){
    var elements=document.querySelectorAll('.dragging');

    for(var i=0;i<elements.length;i++){
        elements[i].classList.remove('dragging');
    }
});

showList();
</script>
</body>
</html>

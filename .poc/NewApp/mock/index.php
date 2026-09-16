<?php
/*
 * アンケート管理アプリ モック
 * Apache + PHP / index.php 1ファイル完結
 *
 * - DB / kintone / SMTPには接続しない
 * - localStorage が利用できる場合は保存
 * - sandbox iframe等でlocalStorageが利用できない場合はメモリ保存へフォールバック
 * - HTML / CSS / JavaScriptを本ファイルに同梱
 */

$appTitle = 'アンケート管理アプリ';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($appTitle, ENT_QUOTES, 'UTF-8') ?></title>

<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --info:#0891b2;
    --gray-50:#f8fafc;
    --gray-100:#f1f5f9;
    --gray-200:#e2e8f0;
    --gray-300:#cbd5e1;
    --gray-400:#94a3b8;
    --gray-500:#64748b;
    --gray-600:#475569;
    --gray-700:#334155;
    --gray-800:#1e293b;
    --gray-900:#0f172a;
    --white:#fff;
    --shadow:0 2px 10px rgba(15,23,42,.08);
    --radius:10px;
}

*{box-sizing:border-box}

html,body{
    margin:0;
    min-height:100%;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    background:var(--gray-50);
    color:var(--gray-800);
    font-size:14px;
}

button,input,select,textarea{
    font:inherit;
}

button{
    cursor:pointer;
}

button:disabled{
    cursor:not-allowed;
    opacity:.5;
}

a{
    color:inherit;
    text-decoration:none;
}

/* =========================
   Layout
========================= */

.app{
    min-height:100vh;
    display:flex;
}

.sidebar{
    position:fixed;
    inset:0 auto 0 0;
    width:250px;
    background:#172033;
    color:#fff;
    overflow-y:auto;
    z-index:30;
}

.logo{
    min-height:68px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    padding:0 22px;
    border-bottom:1px solid rgba(255,255,255,.1);
    font-size:18px;
    font-weight:700;
}

.logo small{
    display:block;
    margin-top:3px;
    font-size:10px;
    font-weight:400;
    color:#94a3b8;
}

.nav{
    padding:14px 10px;
}

.nav-section{
    color:#64748b;
    font-size:11px;
    margin:15px 10px 7px;
    font-weight:700;
}

.nav button{
    width:100%;
    border:0;
    background:transparent;
    color:#cbd5e1;
    text-align:left;
    padding:10px 12px;
    border-radius:7px;
    margin-bottom:2px;
}

.nav button:hover,
.nav button.active{
    background:#26344f;
    color:#fff;
}

.nav .icon{
    width:22px;
    display:inline-block;
    text-align:center;
}

.main{
    margin-left:250px;
    width:calc(100% - 250px);
    min-height:100vh;
}

.topbar{
    position:sticky;
    top:0;
    z-index:20;
    height:68px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 28px;
    background:#fff;
    border-bottom:1px solid var(--gray-200);
}

.topbar-title{
    font-size:17px;
    font-weight:700;
}

.user{
    color:var(--gray-500);
    font-size:13px;
}

.content{
    padding:28px;
    max-width:1600px;
    margin:auto;
}

/* =========================
   Common
========================= */

.page-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:22px;
}

.page-title{
    margin:0;
    font-size:24px;
    color:var(--gray-900);
}

.page-description{
    margin:7px 0 0;
    color:var(--gray-500);
}

.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.btn{
    border:1px solid var(--gray-300);
    background:#fff;
    color:var(--gray-700);
    padding:8px 13px;
    border-radius:7px;
    font-weight:600;
    line-height:1.2;
}

.btn:hover{
    background:var(--gray-50);
}

.btn-primary{
    color:#fff;
    background:var(--primary);
    border-color:var(--primary);
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-success{
    color:#fff;
    background:var(--success);
    border-color:var(--success);
}

.btn-warning{
    color:#fff;
    background:var(--warning);
    border-color:var(--warning);
}

.btn-danger{
    color:#fff;
    background:var(--danger);
    border-color:var(--danger);
}

.btn-info{
    color:#fff;
    background:var(--info);
    border-color:var(--info);
}

.btn-sm{
    padding:6px 9px;
    font-size:12px;
}

.btn-link{
    border:0;
    background:transparent;
    color:var(--primary);
    padding:2px 4px;
    font-weight:600;
}

.card{
    background:#fff;
    border:1px solid var(--gray-200);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.card-head{
    padding:16px 18px;
    border-bottom:1px solid var(--gray-200);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
}

.card-title{
    margin:0;
    font-size:16px;
    font-weight:700;
}

.card-body{
    padding:18px;
}

.muted{
    color:var(--gray-500);
}

.small{
    font-size:12px;
}

.text-danger{color:var(--danger)}
.text-success{color:var(--success)}
.text-warning{color:var(--warning)}
.text-primary{color:var(--primary)}

.info-box,
.success-box,
.error-box{
    border-radius:8px;
    padding:12px 14px;
    margin-bottom:15px;
}

.info-box{
    border:1px solid #bae6fd;
    background:#f0f9ff;
    color:#075985;
}

.success-box{
    border:1px solid #bbf7d0;
    background:#f0fdf4;
    color:#166534;
}

.error-box{
    border:1px solid #fecaca;
    background:#fef2f2;
    color:#991b1b;
}

.error-box ul{
    margin:7px 0 0 18px;
    padding:0;
}

/* =========================
   Status
========================= */

.status{
    display:inline-flex;
    align-items:center;
    padding:4px 8px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}

.status-draft{
    color:#475569;
    background:#f1f5f9;
    border:1px solid #cbd5e1;
}

.status-wait{
    color:#92400e;
    background:#fef3c7;
    border:1px solid #fcd34d;
}

.status-active{
    color:#166534;
    background:#dcfce7;
    border:1px solid #86efac;
}

.status-ended{
    color:#1e40af;
    background:#dbeafe;
    border:1px solid #93c5fd;
}

.status-archived{
    color:#475569;
    background:#e2e8f0;
    border:1px solid #cbd5e1;
}

/* =========================
   Dashboard
========================= */

.stat-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:14px;
    margin-bottom:24px;
}

.stat-card{
    background:#fff;
    border:1px solid var(--gray-200);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:18px;
    cursor:pointer;
}

.stat-card:hover{
    border-color:var(--primary);
}

.stat-label{
    color:var(--gray-500);
    font-size:12px;
}

.stat-number{
    font-size:30px;
    font-weight:800;
    margin-top:7px;
}

.dashboard-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
}

/* =========================
   Tables
========================= */

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:760px;
}

th,td{
    padding:11px 12px;
    border-bottom:1px solid var(--gray-200);
    vertical-align:middle;
    text-align:left;
}

th{
    background:var(--gray-50);
    color:var(--gray-600);
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}

tr:last-child td{
    border-bottom:0;
}

.actions-cell{
    white-space:nowrap;
}

.actions-cell .btn{
    margin:2px;
}

/* =========================
   Forms
========================= */

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-grid .full{
    grid-column:1/-1;
}

.form-group{
    margin-bottom:14px;
}

.form-label{
    display:block;
    font-weight:700;
    margin-bottom:6px;
}

.required{
    color:var(--danger);
    margin-left:4px;
}

input[type=text],
input[type=email],
input[type=datetime-local],
input[type=number],
select,
textarea{
    width:100%;
    border:1px solid var(--gray-300);
    border-radius:7px;
    padding:9px 10px;
    background:#fff;
    color:var(--gray-800);
}

textarea{
    min-height:100px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus{
    outline:none;
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.1);
}

.readonly-field{
    background:var(--gray-100)!important;
}

/* =========================
   Editor
========================= */

.editor-layout{
    display:grid;
    grid-template-columns:240px minmax(0,1fr);
    gap:18px;
}

.editor-nav-item{
    width:100%;
    border:0;
    background:transparent;
    padding:10px 12px;
    text-align:left;
    border-radius:7px;
    margin-bottom:3px;
}

.editor-nav-item:hover,
.editor-nav-item.active{
    background:#eff6ff;
    color:var(--primary);
}

.group-list{
    display:flex;
    flex-direction:column;
    gap:12px;
}

.group-box{
    border:1px solid var(--gray-200);
    border-radius:9px;
    background:#fff;
}

.group-head{
    padding:12px 14px;
    background:var(--gray-50);
    border-bottom:1px solid var(--gray-200);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
}

.question-list{
    padding:8px;
    min-height:30px;
}

.question-card{
    border:1px solid var(--gray-200);
    border-radius:8px;
    margin-bottom:8px;
    padding:12px;
    background:#fff;
}

.question-card:last-child{
    margin-bottom:0;
}

.question-head{
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
}

.question-number{
    color:var(--primary);
    font-weight:800;
}

.question-actions{
    white-space:nowrap;
}

.choice-row{
    display:flex;
    gap:7px;
    margin-bottom:7px;
}

.choice-row input{
    flex:1;
}

.branch-box{
    margin-top:10px;
    padding:10px;
    background:var(--gray-50);
    border-radius:7px;
}

/* =========================
   Preview / Answer
========================= */

.preview-shell{
    max-width:900px;
    margin:auto;
}

.preview-header,
.answer-question{
    background:#fff;
    border:1px solid var(--gray-200);
    border-radius:var(--radius);
    padding:20px;
    margin-bottom:16px;
}

.answer-question-title{
    font-weight:700;
    margin-bottom:12px;
    line-height:1.7;
}

.required-label{
    color:var(--danger);
    font-size:11px;
    border:1px solid #fecaca;
    background:#fef2f2;
    padding:2px 6px;
    border-radius:999px;
    margin-left:6px;
}

.option{
    margin:8px 0;
    display:flex;
    align-items:flex-start;
    gap:8px;
}

.answer-progress{
    margin:15px 0 20px;
}

.progress-track{
    height:8px;
    background:var(--gray-200);
    border-radius:999px;
    overflow:hidden;
}

.progress-bar{
    height:100%;
    background:var(--primary);
}

/* =========================
   KPI
========================= */

.kpi-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
}

.kpi{
    border:1px solid var(--gray-200);
    border-radius:9px;
    padding:15px;
}

.kpi-label{
    font-size:12px;
    color:var(--gray-500);
}

.kpi-value{
    font-size:26px;
    font-weight:800;
    margin-top:5px;
}

/* =========================
   Modal / Toast
========================= */

.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.48);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:100;
    padding:20px;
}

.modal-backdrop.show{
    display:flex;
}

.modal{
    width:min(650px,100%);
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:12px;
    box-shadow:0 20px 60px rgba(15,23,42,.25);
}

.modal-head{
    padding:17px 20px;
    border-bottom:1px solid var(--gray-200);
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.modal-body{
    padding:20px;
}

.modal-footer{
    padding:14px 20px;
    border-top:1px solid var(--gray-200);
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.modal-close{
    border:0;
    background:transparent;
    color:var(--gray-500);
    font-size:22px;
}

.toast{
    position:fixed;
    right:20px;
    bottom:20px;
    background:#172033;
    color:#fff;
    padding:12px 16px;
    border-radius:8px;
    box-shadow:var(--shadow);
    z-index:200;
    display:none;
}

.toast.show{
    display:block;
}

/* =========================
   Responsive
========================= */

@media(max-width:1100px){
    .stat-grid{
        grid-template-columns:repeat(3,1fr);
    }

    .editor-layout{
        grid-template-columns:1fr;
    }
}

@media(max-width:800px){
    .sidebar{
        width:68px;
    }

    .logo{
        padding:0;
        align-items:center;
        font-size:0;
    }

    .logo:before{
        content:"A";
        font-size:22px;
    }

    .logo small,
    .nav-section,
    .nav button span:not(.icon){
        display:none;
    }

    .nav button{
        text-align:center;
    }

    .main{
        margin-left:68px;
        width:calc(100% - 68px);
    }

    .content{
        padding:18px;
    }

    .dashboard-grid{
        grid-template-columns:1fr;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .stat-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .kpi-grid{
        grid-template-columns:repeat(2,1fr);
    }
}

@media(max-width:500px){
    .stat-grid{
        grid-template-columns:1fr;
    }

    .page-head{
        flex-direction:column;
    }

    .topbar{
        padding:0 14px;
    }

    .content{
        padding:12px;
    }

    .kpi-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<div class="app">

<aside class="sidebar">
    <div class="logo">
        アンケート管理
        <small>Questionnaire Management</small>
    </div>

    <nav class="nav">

        <div class="nav-section">管理</div>

        <button data-page="home" onclick="navigate('home')">
            <span class="icon">⌂</span>
            <span>ホーム</span>
        </button>

        <button data-page="surveys" onclick="navigate('surveys')">
            <span class="icon">▤</span>
            <span>アンケート一覧</span>
        </button>

        <button data-page="editor" onclick="newSurvey()">
            <span class="icon">＋</span>
            <span>新規作成</span>
        </button>

        <div class="nav-section">回答</div>

        <button data-page="responses" onclick="navigate('responses')">
            <span class="icon">▥</span>
            <span>回答状況</span>
        </button>

        <button data-page="response-detail" onclick="openResponseDetail()">
            <span class="icon">☷</span>
            <span>回答内容</span>
        </button>

        <div class="nav-section">送付</div>

        <button data-page="send" onclick="openSend()">
            <span class="icon">✉</span>
            <span>アンケート送付</span>
        </button>

        <button data-page="send-result" onclick="navigate('send-result')">
            <span class="icon">✓</span>
            <span>送付結果</span>
        </button>

        <div class="nav-section">設定</div>

        <button data-page="settings" onclick="navigate('settings')">
            <span class="icon">⚙</span>
            <span>各種設定</span>
        </button>

        <div class="nav-section">回答者確認</div>

        <button data-page="answer" onclick="startAnswer()">
            <span class="icon">▣</span>
            <span>回答者画面</span>
        </button>

    </nav>
</aside>

<main class="main">

<header class="topbar">
    <div id="topbarTitle" class="topbar-title">ホーム</div>
    <div class="user">アンケート運営管理者</div>
</header>

<div id="appContent" class="content"></div>

</main>
</div>

<div id="modalBackdrop" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <strong id="modalTitle">確認</strong>
            <button class="modal-close" type="button" onclick="closeModal()">×</button>
        </div>
        <div id="modalBody" class="modal-body"></div>
        <div id="modalFooter" class="modal-footer"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
'use strict';

/* =========================================================
   Storage
========================================================= */

const STORAGE_KEY = 'questionnaire_mock_v3';

let memoryStore = null;
let storageAvailable = false;

function initStorage(){
    try{
        const testKey = '__questionnaire_storage_test__';
        window.localStorage.setItem(testKey,'1');
        window.localStorage.removeItem(testKey);
        storageAvailable = true;
    }catch(e){
        storageAvailable = false;
        console.warn(
            'localStorage は利用できません。メモリ上でモック状態を保持します。',
            e
        );
    }
}

function storageGet(key){
    if(storageAvailable){
        try{
            return window.localStorage.getItem(key);
        }catch(e){
            storageAvailable = false;
            console.warn('localStorage の読み込みに失敗しました。',e);
        }
    }

    return memoryStore;
}

function storageSet(key,value){
    if(storageAvailable){
        try{
            window.localStorage.setItem(key,value);
            return;
        }catch(e){
            storageAvailable = false;
            console.warn(
                'localStorage への保存に失敗しました。メモリ保存へ切り替えます。',
                e
            );
        }
    }

    memoryStore = value;
}

/* =========================================================
   Default Data
========================================================= */

function createDefaultData(){

    return {
        currentSurveyId:1,
        currentPage:'home',
        listFilter:'all',

        settings:{
            kintone:{
                host:'https://example.cybozu.com',
                app:'123',
                nameField:'顧客名',
                contactField:'担当者名',
                emailField:'メールアドレス',
                connected:true,
                updatedAt:'2026-09-16 09:30'
            },

            smtp:{
                host:'smtp.example.jp',
                port:'587',
                from:'questionnaire@example.jp',
                encryption:'STARTTLS',
                configured:true
            }
        },

        customers:[
            {
                id:1,
                name:'株式会社青山商事',
                contact:'田中 太郎',
                email:'tanaka@example.jp'
            },
            {
                id:2,
                name:'株式会社赤坂商会',
                contact:'佐藤 花子',
                email:'sato@example.jp'
            },
            {
                id:3,
                name:'東京サンプル株式会社',
                contact:'鈴木 一郎',
                email:'suzuki@example.jp'
            },
            {
                id:4,
                name:'港区ソリューションズ',
                contact:'高橋 次郎',
                email:'takahashi@example.jp'
            },
            {
                id:5,
                name:'サンプル製作所',
                contact:'伊藤 三郎',
                email:'ito@example.jp'
            },
            {
                id:6,
                name:'見本産業株式会社',
                contact:'渡辺 美咲',
                email:'watanabe@example.jp'
            }
        ],

        surveys:[
            {
                id:1,
                name:'2026年度 顧客満足度アンケート',
                description:'サービスをご利用いただいたお客様への満足度調査です。',
                guidance:'各質問にご回答ください。所要時間は約5分です。',
                completeMessage:'ご回答ありがとうございました。',
                status:'active',
                createdAt:'2026-08-01',
                updatedAt:'2026-09-15 16:20',
                startAt:'2026-09-01T09:00',
                endAt:'2026-09-30T18:00',
                numberMode:'global',
                sentCount:80,
                responseCount:42,
                selectedCustomerIds:[1,2,3,4,5,6],
                groups:[
                    {id:'g1',name:'基本情報'},
                    {id:'g2',name:'サービス評価'}
                ],
                questions:[
                    {
                        id:'q1',
                        groupId:'g1',
                        text:'当社サービスを利用したことがありますか？',
                        type:'single',
                        required:true,
                        help:'',
                        choices:[
                            {id:'c1',text:'はい'},
                            {id:'c2',text:'いいえ'}
                        ],
                        branches:{
                            c1:{type:'next'},
                            c2:{type:'question',target:'q4'}
                        }
                    },
                    {
                        id:'q2',
                        groupId:'g2',
                        text:'サービスの満足度を教えてください。',
                        type:'rating',
                        required:true,
                        help:'1が最低、5が最高です。',
                        choices:[
                            {id:'r1',text:'1'},
                            {id:'r2',text:'2'},
                            {id:'r3',text:'3'},
                            {id:'r4',text:'4'},
                            {id:'r5',text:'5'}
                        ],
                        branches:{}
                    },
                    {
                        id:'q3',
                        groupId:'g2',
                        text:'改善してほしい点があれば教えてください。',
                        type:'text',
                        required:false,
                        help:'',
                        choices:[],
                        branches:{}
                    },
                    {
                        id:'q4',
                        groupId:'g2',
                        text:'今後利用してみたいサービスを教えてください。',
                        type:'multiple',
                        required:false,
                        help:'',
                        choices:[
                            {id:'m1',text:'オンラインサポート'},
                            {id:'m2',text:'レポート機能'},
                            {id:'m3',text:'コンサルティング'}
                        ],
                        branches:{}
                    }
                ],

                answers:[
                    {
                        id:1,
                        answeredAt:'2026-09-12 14:05',
                        respondent:'佐藤 花子',
                        values:{
                            q1:'はい',
                            q2:'4',
                            q3:'サポート時間を増やしてほしい。',
                            q4:['オンラインサポート']
                        }
                    },
                    {
                        id:2,
                        answeredAt:'2026-09-13 10:22',
                        respondent:'鈴木 一郎',
                        values:{
                            q1:'いいえ',
                            q4:['コンサルティング']
                        }
                    }
                ]
            },

            {
                id:2,
                name:'新サービス利用意向調査',
                description:'新サービスについての利用意向を確認します。',
                guidance:'簡単なアンケートです。',
                completeMessage:'ご回答ありがとうございました。',
                status:'wait',
                createdAt:'2026-09-03',
                updatedAt:'2026-09-14 11:10',
                startAt:'2026-09-20T09:00',
                endAt:'2026-10-10T18:00',
                numberMode:'group',
                sentCount:25,
                responseCount:0,
                selectedCustomerIds:[1,2,3],
                groups:[
                    {id:'g1',name:'利用意向'},
                    {id:'g2',name:'ご意見'}
                ],
                questions:[
                    {
                        id:'q1',
                        groupId:'g1',
                        text:'新サービスを利用したいと思いますか？',
                        type:'single',
                        required:true,
                        help:'',
                        choices:[
                            {id:'c1',text:'ぜひ利用したい'},
                            {id:'c2',text:'検討したい'},
                            {id:'c3',text:'利用予定はない'}
                        ],
                        branches:{}
                    },
                    {
                        id:'q2',
                        groupId:'g2',
                        text:'ご意見があれば教えてください。',
                        type:'text',
                        required:false,
                        help:'',
                        choices:[],
                        branches:{}
                    }
                ],
                answers:[]
            },

            {
                id:3,
                name:'2026年 上期サービス調査',
                description:'上期のサービス利用状況調査です。',
                guidance:'',
                completeMessage:'ご協力ありがとうございました。',
                status:'ended',
                createdAt:'2026-04-01',
                updatedAt:'2026-09-01 18:00',
                startAt:'2026-04-10T09:00',
                endAt:'2026-08-31T18:00',
                numberMode:'global',
                sentCount:120,
                responseCount:95,
                selectedCustomerIds:[],
                groups:[
                    {id:'g1',name:'サービス評価'}
                ],
                questions:[
                    {
                        id:'q1',
                        groupId:'g1',
                        text:'サービス全体の満足度を教えてください。',
                        type:'rating',
                        required:true,
                        help:'',
                        choices:[
                            {id:'r1',text:'1'},
                            {id:'r2',text:'2'},
                            {id:'r3',text:'3'},
                            {id:'r4',text:'4'},
                            {id:'r5',text:'5'}
                        ],
                        branches:{}
                    }
                ],
                answers:[]
            },

            {
                id:4,
                name:'2025年度 利用者アンケート',
                description:'昨年度の利用者アンケートです。',
                guidance:'',
                completeMessage:'ありがとうございました。',
                status:'archived',
                createdAt:'2025-04-01',
                updatedAt:'2026-04-01 10:00',
                startAt:'2025-04-10T09:00',
                endAt:'2025-09-30T18:00',
                numberMode:'global',
                sentCount:100,
                responseCount:81,
                selectedCustomerIds:[],
                groups:[
                    {id:'g1',name:'アンケート'}
                ],
                questions:[
                    {
                        id:'q1',
                        groupId:'g1',
                        text:'サービスに満足していますか？',
                        type:'single',
                        required:true,
                        help:'',
                        choices:[
                            {id:'c1',text:'はい'},
                            {id:'c2',text:'いいえ'}
                        ],
                        branches:{}
                    }
                ],
                answers:[]
            }
        ],

        sendResults:[
            {
                id:1,
                surveyId:1,
                target:80,
                success:78,
                failed:2,
                sentAt:'2026-09-10 10:00',
                failedCustomers:[
                    'メールアドレス不備：株式会社青山商事',
                    '送信エラー：港区ソリューションズ'
                ]
            }
        ],

        sendDraft:null
    };
}

/* =========================================================
   State
========================================================= */

let state = null;

function cloneDefaultData(){
    return JSON.parse(JSON.stringify(createDefaultData()));
}

function loadState(){

    const raw = storageGet(STORAGE_KEY);

    if(raw){
        try{
            const parsed = JSON.parse(raw);

            if(parsed && Array.isArray(parsed.surveys)){
                return parsed;
            }
        }catch(e){
            console.warn('保存データの解析に失敗しました。初期データを使用します。',e);
        }
    }

    return cloneDefaultData();
}

function saveState(){

    try{
        storageSet(
            STORAGE_KEY,
            JSON.stringify(state)
        );
    }catch(e){
        console.warn('モック状態の保存に失敗しました。',e);
    }
}

function resetMock(){

    if(!window.confirm(
        'モックデータを初期状態へ戻します。よろしいですか？'
    )){
        return;
    }

    state = cloneDefaultData();
    saveState();

    navigate('home');

    toast('モックデータを初期化しました。');
}

/* =========================================================
   Helpers
========================================================= */

function escapeHtml(value){

    return String(value ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function uid(prefix){

    return prefix + '_' +
        Date.now().toString(36) +
        Math.random().toString(36).slice(2,7);
}

function currentSurvey(){

    return state.surveys.find(
        s => Number(s.id) === Number(state.currentSurveyId)
    ) || state.surveys[0] || null;
}

function setCurrentSurvey(id){

    state.currentSurveyId = Number(id);
    saveState();
}

function statusLabel(status){

    return {
        draft:'作成中',
        wait:'公開済み・回答開始待ち',
        active:'回答受付中',
        ended:'回答受付終了',
        archived:'保管'
    }[status] || status;
}

function statusClass(status){

    return {
        draft:'status-draft',
        wait:'status-wait',
        active:'status-active',
        ended:'status-ended',
        archived:'status-archived'
    }[status] || 'status-draft';
}

function statusBadge(status){

    return '<span class="status ' +
        statusClass(status) +
        '">' +
        escapeHtml(statusLabel(status)) +
        '</span>';
}

function formatDate(value){

    if(!value){
        return '-';
    }

    return String(value).replace('T',' ');
}

function responseRate(survey){

    if(!survey.sentCount){
        return null;
    }

    return Math.round(
        (Number(survey.responseCount || 0) /
        Number(survey.sentCount)) * 100
    );
}

function questionTypeLabel(type){

    return {
        text:'文章を入力する',
        single:'1つだけ選ぶ',
        multiple:'複数選ぶ',
        rating:'段階で評価する'
    }[type] || type;
}

function questionNumber(survey,q){

    if(survey.numberMode === 'group'){

        const sameGroup = survey.questions.filter(
            x => x.groupId === q.groupId
        );

        return sameGroup.findIndex(
            x => x.id === q.id
        ) + 1;
    }

    return survey.questions.findIndex(
        x => x.id === q.id
    ) + 1;
}

function getGroup(survey,id){

    return survey.groups.find(
        g => g.id === id
    );
}

function canEdit(survey){

    return survey.status === 'draft' ||
           survey.status === 'wait';
}

function canStructuralEdit(survey){

    return survey.status === 'draft';
}

function countByStatus(status){

    return state.surveys.filter(
        s => s.status === status
    ).length;
}

function nowString(){

    const d = new Date();

    const pad = n =>
        String(n).padStart(2,'0');

    return [
        d.getFullYear(),
        pad(d.getMonth()+1),
        pad(d.getDate())
    ].join('-') + ' ' +
    [
        pad(d.getHours()),
        pad(d.getMinutes())
    ].join(':');
}

/* =========================================================
   Navigation
========================================================= */

const pageTitles = {
    home:'ホーム',
    surveys:'アンケート一覧',
    editor:'アンケート編集',
    preview:'公開前確認',
    responses:'回答状況',
    'response-detail':'回答内容',
    send:'アンケート送付',
    customers:'顧客選択',
    'send-confirm':'送付確認',
    'send-result':'送付結果',
    settings:'各種設定',
    answer:'回答者向けアンケート',
    'answer-confirm':'回答確認',
    'answer-complete':'回答完了'
};

function navigate(page){

    if(!pageTitles[page]){
        page = 'home';
    }

    state.currentPage = page;
    saveState();

    const title =
        pageTitles[page] || 'アンケート管理';

    const titleEl =
        document.getElementById('topbarTitle');

    if(titleEl){
        titleEl.textContent = title;
    }

    document.querySelectorAll('.nav button')
        .forEach(btn => {

            btn.classList.toggle(
                'active',
                btn.dataset.page === page
            );
        });

    renderPage();

    window.scrollTo({
        top:0,
        behavior:'smooth'
    });
}

/*
 * 重要：
 * renderPage は初期化処理より前に定義する。
 * これにより navigate() から必ず利用できる。
 */
function renderPage(){

    const root =
        document.getElementById('appContent');

    if(!root){
        console.error('appContent が見つかりません。');
        return;
    }

    try{

        switch(state.currentPage){

            case 'home':
                root.innerHTML = renderHome();
                break;

            case 'surveys':
                root.innerHTML = renderSurveyList();
                break;

            case 'editor':
                root.innerHTML = renderEditor();
                break;

            case 'preview':
                root.innerHTML = renderPreview();
                break;

            case 'responses':
                root.innerHTML = renderResponses();
                break;

            case 'response-detail':
                root.innerHTML = renderResponseDetail();
                break;

            case 'send':
                root.innerHTML = renderSend();
                break;

            case 'customers':
                root.innerHTML = renderCustomers();
                break;

            case 'send-confirm':
                root.innerHTML = renderSendConfirm();
                break;

            case 'send-result':
                root.innerHTML = renderSendResult();
                break;

            case 'settings':
                root.innerHTML = renderSettings();
                break;

            case 'answer':
                root.innerHTML = renderAnswer();
                break;

            case 'answer-confirm':
                root.innerHTML = renderAnswerConfirm();
                break;

            case 'answer-complete':
                root.innerHTML = renderAnswerComplete();
                break;

            default:
                state.currentPage = 'home';
                root.innerHTML = renderHome();
        }

        bindPageEvents();

    }catch(error){

        console.error(
            '画面表示中にエラーが発生しました。',
            error
        );

        root.innerHTML =
            '<div class="error-box">' +
            '<strong>画面表示中にエラーが発生しました。</strong>' +
            '<p>' +
            escapeHtml(error.message || error) +
            '</p>' +
            '</div>';
    }
}

/* =========================================================
   Home
========================================================= */

function renderHome(){

    const surveys = state.surveys;

    const recent = [...surveys]
        .sort((a,b) =>
            String(b.updatedAt)
                .localeCompare(String(a.updatedAt))
        )
        .slice(0,5);

    const needSend = surveys.filter(s =>
        ['wait','active'].includes(s.status) &&
        Number(s.sentCount || 0) === 0
    );

    return `
        <div class="page-head">
            <div>
                <h1 class="page-title">ホーム</h1>
                <p class="page-description">
                    アンケートの運営状況と次に行う操作を確認できます。
                </p>
            </div>

            <div class="actions">
                <button class="btn btn-primary"
                    onclick="newSurvey()">
                    ＋ 新しいアンケートを作成する
                </button>
            </div>
        </div>

        <div class="stat-grid">
            ${statCard('作成中',countByStatus('draft'),'draft')}
            ${statCard('公開済み・回答開始待ち',countByStatus('wait'),'wait')}
            ${statCard('回答受付中',countByStatus('active'),'active')}
            ${statCard('回答受付終了',countByStatus('ended'),'ended')}
            ${statCard('保管',countByStatus('archived'),'archived')}
        </div>

        <div class="dashboard-grid">

            <div class="card">
                <div class="card-head">
                    <h2 class="card-title">
                        最近更新したアンケート
                    </h2>

                    <button class="btn btn-sm"
                        onclick="navigate('surveys')">
                        一覧を見る
                    </button>
                </div>

                <div class="card-body">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>アンケート名</th>
                                    <th>状態</th>
                                    <th>更新日</th>
                                    <th>回答数</th>
                                    <th>操作</th>
                                </tr>
                            </thead>

                            <tbody>
                                ${recent.map(s => `
                                    <tr>
                                        <td>
                                            <strong>
                                                ${escapeHtml(s.name)}
                                            </strong>
                                        </td>

                                        <td>
                                            ${statusBadge(s.status)}
                                        </td>

                                        <td>
                                            ${escapeHtml(s.updatedAt)}
                                        </td>

                                        <td>
                                            ${s.responseCount}件
                                        </td>

                                        <td>
                                            <button
                                                class="btn-link"
                                                onclick="openSurvey(${s.id})">
                                                内容を見る
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2 class="card-title">
                        次に行う操作
                    </h2>
                </div>

                <div class="card-body">

                    ${needSend.length
                        ? needSend.map(s => `
                            <div style="padding:12px 0">
                                <strong>
                                    ${escapeHtml(s.name)}
                                </strong>

                                <div style="margin-top:8px">
                                    ${statusBadge(s.status)}
                                </div>

                                <div style="margin-top:10px">
                                    <button
                                        class="btn btn-primary btn-sm"
                                        onclick="openSend(${s.id})">
                                        アンケートを送付する
                                    </button>
                                </div>
                            </div>
                        `).join('')
                        : `
                            <div class="success-box">
                                現在、送付が必要なアンケートはありません。
                            </div>
                        `
                    }

                    <button class="btn"
                        onclick="navigate('responses')">
                        回答状況を確認する
                    </button>

                </div>
            </div>

        </div>

        <div class="card">
            <div class="card-head">
                <h2 class="card-title">
                    モック環境
                </h2>
            </div>

            <div class="card-body">

                <div class="info-box">
                    DB、kintone、SMTPには接続しません。
                    localStorageが利用できない環境では
                    メモリ上でモック状態を保持します。
                </div>

                <button class="btn btn-danger"
                    onclick="resetMock()">
                    モックデータを初期化する
                </button>

            </div>
        </div>
    `;
}

function statCard(label,number,status){

    return `
        <div class="stat-card"
             onclick="filterStatus('${status}')">

            <div class="stat-label">
                ${escapeHtml(label)}
            </div>

            <div class="stat-number">
                ${number}
            </div>

        </div>
    `;
}

function filterStatus(status){

    state.listFilter = status;
    navigate('surveys');
}

/* =========================================================
   Survey List
========================================================= */

function renderSurveyList(){

    const filter = state.listFilter || 'all';

    let surveys = state.surveys;

    if(filter !== 'all'){
        surveys = surveys.filter(
            s => s.status === filter
        );
    }

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    アンケート一覧
                </h1>

                <p class="page-description">
                    作成したアンケートの状態、送付数、回答状況を確認できます。
                </p>
            </div>

            <div class="actions">

                <button class="btn"
                    onclick="state.listFilter='all';renderPage()">
                    すべて
                </button>

                <button class="btn btn-primary"
                    onclick="newSurvey()">
                    ＋ 新規作成
                </button>

            </div>
        </div>

        <div class="card">

            <div class="card-head">

                <div>
                    <strong>
                        ${filter === 'all'
                            ? 'すべてのアンケート'
                            : statusLabel(filter)}
                    </strong>

                    <span class="small muted">
                        （${surveys.length}件）
                    </span>
                </div>

                <select
                    onchange="state.listFilter=this.value;renderPage()">

                    <option value="all"
                        ${filter === 'all' ? 'selected':''}>
                        すべて
                    </option>

                    <option value="draft"
                        ${filter === 'draft' ? 'selected':''}>
                        作成中
                    </option>

                    <option value="wait"
                        ${filter === 'wait' ? 'selected':''}>
                        公開済み・回答開始待ち
                    </option>

                    <option value="active"
                        ${filter === 'active' ? 'selected':''}>
                        回答受付中
                    </option>

                    <option value="ended"
                        ${filter === 'ended' ? 'selected':''}>
                        回答受付終了
                    </option>

                    <option value="archived"
                        ${filter === 'archived' ? 'selected':''}>
                        保管
                    </option>

                </select>

            </div>

            <div class="card-body">

                <div class="table-wrap">

                    <table>

                        <thead>
                            <tr>
                                <th>アンケート名</th>
                                <th>状態</th>
                                <th>作成日</th>
                                <th>更新日</th>
                                <th>回答受付期間</th>
                                <th>送付数</th>
                                <th>回答数</th>
                                <th>回答率</th>
                                <th>操作</th>
                            </tr>
                        </thead>

                        <tbody>

                        ${
                            surveys.length
                            ? surveys.map(s => surveyRow(s)).join('')
                            : `
                                <tr>
                                    <td colspan="9"
                                        class="muted"
                                        style="text-align:center;padding:30px">
                                        該当するアンケートはありません。
                                    </td>
                                </tr>
                            `
                        }

                        </tbody>

                    </table>

                </div>

            </div>
        </div>
    `;
}

function surveyRow(s){

    const rate = responseRate(s);

    let actions = `
        <button class="btn btn-sm"
            onclick="openSurvey(${s.id})">
            内容を見る
        </button>
    `;

    if(s.status === 'draft'){

        actions += `
            <button class="btn btn-sm"
                onclick="editSurvey(${s.id})">
                編集する
            </button>

            <button class="btn btn-sm btn-primary"
                onclick="openPreview(${s.id})">
                公開前確認
            </button>

            <button class="btn btn-sm btn-danger"
                onclick="deleteSurvey(${s.id})">
                削除する
            </button>
        `;
    }

    if(s.status === 'wait'){

        actions += `
            <button class="btn btn-sm"
                onclick="startSurvey(${s.id})">
                回答受付を開始する
            </button>

            <button class="btn btn-sm"
                onclick="openSend(${s.id})">
                送付する
            </button>
        `;
    }

    if(s.status === 'active'){

        actions += `
            <button class="btn btn-sm"
                onclick="openResponses(${s.id})">
                回答状況
            </button>

            <button class="btn btn-sm"
                onclick="openResponseDetail(${s.id})">
                回答内容
            </button>

            <button class="btn btn-sm"
                onclick="openSend(${s.id})">
                送付する
            </button>

            <button class="btn btn-sm btn-warning"
                onclick="endSurvey(${s.id})">
                回答受付を終了する
            </button>
        `;
    }

    if(s.status === 'ended'){

        actions += `
            <button class="btn btn-sm"
                onclick="openResponses(${s.id})">
                回答状況
            </button>

            <button class="btn btn-sm"
                onclick="openResponseDetail(${s.id})">
                回答内容
            </button>

            <button class="btn btn-sm btn-primary"
                onclick="archiveSurvey(${s.id})">
                アンケートを保管する
            </button>
        `;
    }

    if(s.status === 'archived'){

        actions += `
            <button class="btn btn-sm"
                onclick="openResponses(${s.id})">
                回答状況
            </button>

            <button class="btn btn-sm"
                onclick="openResponseDetail(${s.id})">
                回答内容
            </button>
        `;
    }

    return `
        <tr>

            <td>
                <strong>${escapeHtml(s.name)}</strong>

                <div class="small muted">
                    ${s.questions.length}問
                </div>
            </td>

            <td>${statusBadge(s.status)}</td>

            <td>${escapeHtml(s.createdAt)}</td>

            <td>${escapeHtml(s.updatedAt)}</td>

            <td>
                ${formatDate(s.startAt)}
                ～
                ${formatDate(s.endAt)}
            </td>

            <td>${s.sentCount}件</td>

            <td>${s.responseCount}件</td>

            <td>
                ${rate === null ? '-' : rate + '%'}
            </td>

            <td class="actions-cell">
                ${actions}
            </td>

        </tr>
    `;
}

/* =========================================================
   Survey Create / Edit
========================================================= */

function newSurvey(){

    const maxId =
        state.surveys.reduce(
            (max,s) => Math.max(max,Number(s.id)||0),
            0
        );

    const survey = {
        id:maxId + 1,
        name:'',
        description:'',
        guidance:'',
        completeMessage:'ご回答ありがとうございました。',
        status:'draft',
        createdAt:nowString().slice(0,10),
        updatedAt:nowString(),
        startAt:'',
        endAt:'',
        numberMode:'global',
        sentCount:0,
        responseCount:0,
        selectedCustomerIds:[],
        groups:[
            {
                id:uid('g'),
                name:'基本情報'
            }
        ],
        questions:[],
        answers:[]
    };

    state.surveys.push(survey);
    state.currentSurveyId = survey.id;

    saveState();
    navigate('editor');
}

function editSurvey(id){

    const survey =
        state.surveys.find(
            s => Number(s.id) === Number(id)
        );

    if(!survey){
        return;
    }

    if(!canEdit(survey)){

        showModal(
            '編集できません',
            `
                <div class="error-box">
                    このアンケートは現在の状態では
                    編集できません。
                </div>
            `,
            `<button class="btn"
                onclick="closeModal()">
                閉じる
            </button>`
        );

        return;
    }

    setCurrentSurvey(id);
    navigate('editor');
}

function openSurvey(id){

    setCurrentSurvey(id);

    const survey = currentSurvey();

    if(!survey){
        return;
    }

    if(survey.status === 'draft'){
        navigate('editor');
    }else{
        navigate('preview');
    }
}

function renderEditor(){

    const survey = currentSurvey();

    if(!survey){
        return `
            <div class="error-box">
                アンケートがありません。
            </div>
        `;
    }

    const structural =
        canStructuralEdit(survey);

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    アンケート編集
                </h1>

                <p class="page-description">
                    ${escapeHtml(survey.name || '新しいアンケート')}
                </p>
            </div>

            <div class="actions">

                <button class="btn"
                    onclick="navigate('surveys')">
                    一覧へ戻る
                </button>

                <button class="btn btn-primary"
                    onclick="saveSurvey()">
                    保存する
                </button>

                <button class="btn btn-info"
                    onclick="openPreview(${survey.id})">
                    公開前確認
                </button>

            </div>
        </div>

        <div id="editorErrors"></div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    アンケート基本情報
                </h2>

                ${statusBadge(survey.status)}
            </div>

            <div class="card-body">

                <div class="form-grid">

                    <div class="form-group full">
                        <label class="form-label">
                            アンケート名
                            <span class="required">*</span>
                        </label>

                        <input id="surveyName"
                            type="text"
                            value="${escapeHtml(survey.name)}">
                    </div>

                    <div class="form-group full">
                        <label class="form-label">
                            説明文
                        </label>

                        <textarea id="surveyDescription">${escapeHtml(survey.description)}</textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            回答受付開始日時
                        </label>

                        <input id="surveyStartAt"
                            type="datetime-local"
                            value="${escapeHtml(survey.startAt)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            回答受付終了日時
                        </label>

                        <input id="surveyEndAt"
                            type="datetime-local"
                            value="${escapeHtml(survey.endAt)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            回答案内
                        </label>

                        <textarea id="surveyGuidance">${escapeHtml(survey.guidance)}</textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            完了メッセージ
                        </label>

                        <textarea id="surveyCompleteMessage">${escapeHtml(survey.completeMessage)}</textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            質問番号
                        </label>

                        <select id="numberMode">
                            <option value="global"
                                ${survey.numberMode === 'global' ? 'selected':''}>
                                アンケート全体で連番
                            </option>

                            <option value="group"
                                ${survey.numberMode === 'group' ? 'selected':''}>
                                グループごとに連番
                            </option>
                        </select>
                    </div>

                </div>

            </div>
        </div>

        <div class="card">

            <div class="card-head">

                <h2 class="card-title">
                    質問
                </h2>

                <div class="actions">

                    ${
                        structural
                        ? `
                            <button class="btn"
                                onclick="addGroup()">
                                グループを追加する
                            </button>

                            <button class="btn btn-primary"
                                onclick="addQuestion()">
                                質問を追加する
                            </button>
                        `
                        : `
                            <span class="muted small">
                                公開後は質問構造を変更できません。
                            </span>
                        `
                    }

                </div>
            </div>

            <div class="card-body">

                <div class="group-list">

                    ${survey.groups.map(
                        group => renderGroupEditor(
                            survey,
                            group,
                            structural
                        )
                    ).join('')}

                </div>

                ${
                    survey.questions.filter(
                        q => !survey.groups.some(
                            g => g.id === q.groupId
                        )
                    ).length
                    ? `
                        <div class="error-box" style="margin-top:15px">
                            グループ未所属の質問があります。
                        </div>
                    `
                    : ''
                }

            </div>
        </div>
    `;
}

function renderGroupEditor(survey,group,structural){

    const questions =
        survey.questions.filter(
            q => q.groupId === group.id
        );

    return `
        <div class="group-box">

            <div class="group-head">

                <div>
                    <strong>
                        ${escapeHtml(group.name)}
                    </strong>

                    <span class="small muted">
                        (${questions.length}問)
                    </span>
                </div>

                ${
                    structural
                    ? `
                        <div class="actions">

                            <button
                                class="btn btn-sm"
                                onclick="renameGroup('${group.id}')">
                                グループ名を変更する
                            </button>

                            <button
                                class="btn btn-sm btn-danger"
                                onclick="deleteGroup('${group.id}')">
                                グループを削除する
                            </button>

                        </div>
                    `
                    : ''
                }

            </div>

            <div class="question-list">

                ${
                    questions.length
                    ? questions.map(
                        q => renderQuestionEditor(
                            survey,
                            q,
                            structural
                        )
                    ).join('')
                    : `
                        <div class="muted"
                            style="padding:12px">
                            このグループには質問がありません。
                        </div>
                    `
                }

            </div>

        </div>
    `;
}

function renderQuestionEditor(survey,q,structural){

    return `
        <div class="question-card">

            <div class="question-head">

                <div style="flex:1">

                    <div class="question-number">
                        質問${questionNumber(survey,q)}
                    </div>

                    <div style="margin-top:5px;font-weight:700">
                        ${escapeHtml(q.text || '未入力')}
                    </div>

                    <div class="small muted"
                        style="margin-top:4px">
                        ${escapeHtml(questionTypeLabel(q.type))}
                        ／
                        ${q.required ? '必須':'任意'}
                    </div>

                </div>

                <div class="question-actions">

                    <button class="btn btn-sm"
                        onclick="editQuestion('${q.id}')"
                        ${structural ? '' : 'disabled'}>
                        編集する
                    </button>

                    <button class="btn btn-sm btn-danger"
                        onclick="deleteQuestion('${q.id}')"
                        ${structural ? '' : 'disabled'}>
                        削除する
                    </button>

                </div>

            </div>

            ${
                q.choices && q.choices.length
                ? `
                    <div style="margin-top:10px">
                        <div class="small muted">
                            選択肢
                        </div>

                        <ul>
                            ${q.choices.map(c =>
                                `<li>${escapeHtml(
                                    typeof c === 'string'
                                    ? c
                                    : c.text
                                )}</li>`
                            ).join('')}
                        </ul>
                    </div>
                `
                : ''
            }

            ${
                q.help
                ? `
                    <div class="info-box"
                        style="margin-top:10px;margin-bottom:0">
                        ${escapeHtml(q.help)}
                    </div>
                `
                : ''
            }

        </div>
    `;
}

function saveSurvey(){

    const survey = currentSurvey();

    if(!survey){
        return;
    }

    const name =
        document.getElementById('surveyName')?.value.trim() || '';

    const description =
        document.getElementById('surveyDescription')?.value || '';

    const startAt =
        document.getElementById('surveyStartAt')?.value || '';

    const endAt =
        document.getElementById('surveyEndAt')?.value || '';

    const guidance =
        document.getElementById('surveyGuidance')?.value || '';

    const completeMessage =
        document.getElementById('surveyCompleteMessage')?.value || '';

    const numberMode =
        document.getElementById('numberMode')?.value || 'global';

    const errors = [];

    if(!name){
        errors.push('アンケート名を入力してください。');
    }

    if(startAt && endAt && startAt >= endAt){
        errors.push(
            '回答受付開始日時は終了日時より前にしてください。'
        );
    }

    if(errors.length){

        showValidationErrors(
            errors,
            'editorErrors'
        );

        return;
    }

    survey.name = name;
    survey.description = description;
    survey.startAt = startAt;
    survey.endAt = endAt;
    survey.guidance = guidance;
    survey.completeMessage = completeMessage;
    survey.numberMode = numberMode;
    survey.updatedAt = nowString();

    saveState();
    renderPage();

    toast('保存しました。');
}

/* =========================================================
   Group / Question
========================================================= */

function addGroup(){

    const survey = currentSurvey();

    if(!survey || !canStructuralEdit(survey)){
        return;
    }

    const name = window.prompt(
        'グループ名を入力してください。',
        '新しいグループ'
    );

    if(name === null){
        return;
    }

    const value = name.trim();

    if(!value){
        toast('グループ名を入力してください。');
        return;
    }

    survey.groups.push({
        id:uid('g'),
        name:value
    });

    survey.updatedAt = nowString();

    saveState();
    renderPage();

    toast('グループを追加しました。');
}

function renameGroup(id){

    const survey = currentSurvey();

    if(!survey || !canStructuralEdit(survey)){
        return;
    }

    const group =
        survey.groups.find(g => g.id === id);

    if(!group){
        return;
    }

    const name = window.prompt(
        'グループ名を入力してください。',
        group.name
    );

    if(name === null){
        return;
    }

    if(!name.trim()){
        toast('グループ名を入力してください。');
        return;
    }

    group.name = name.trim();
    survey.updatedAt = nowString();

    saveState();
    renderPage();

    toast('グループ名を変更しました。');
}

function deleteGroup(id){

    const survey = currentSurvey();

    if(!survey || !canStructuralEdit(survey)){
        return;
    }

    const group =
        survey.groups.find(g => g.id === id);

    if(!group){
        return;
    }

    const questions =
        survey.questions.filter(
            q => q.groupId === id
        );

    showConfirm(
        'グループを削除する',
        `
            <p>
                「${escapeHtml(group.name)}」を削除します。
            </p>

            ${
                questions.length
                ? `
                    <p class="text-danger">
                        所属している質問も削除対象になります。
                    </p>
                `
                : ''
            }
        `,
        '削除する',
        function(){

            survey.groups =
                survey.groups.filter(
                    g => g.id !== id
                );

            survey.questions =
                survey.questions.filter(
                    q => q.groupId !== id
                );

            survey.updatedAt = nowString();

            saveState();
            closeModal();
            renderPage();

            toast('グループを削除しました。');
        },
        'danger'
    );
}

function addQuestion(){

    const survey = currentSurvey();

    if(!survey || !canStructuralEdit(survey)){
        return;
    }

    if(!survey.groups.length){
        toast('先にグループを追加してください。');
        return;
    }

    survey.questions.push({
        id:uid('q'),
        groupId:survey.groups[0].id,
        text:'',
        type:'text',
        required:false,
        help:'',
        choices:[],
        branches:{}
    });

    survey.updatedAt = nowString();

    saveState();
    renderPage();

    const question =
        survey.questions[survey.questions.length-1];

    editQuestion(question.id);
}

function editQuestion(id){

    const survey = currentSurvey();

    if(!survey || !canStructuralEdit(survey)){
        return;
    }

    const q =
        survey.questions.find(
            x => x.id === id
        );

    if(!q){
        return;
    }

    const choiceText =
        (q.choices || []).map(
            c => typeof c === 'string' ? c : c.text
        ).join('\n');

    showModal(
        '質問を編集する',
        `
            <div class="form-group">
                <label class="form-label">
                    質問文
                </label>

                <textarea id="questionText">${escapeHtml(q.text)}</textarea>
            </div>

            <div class="form-group">
                <label class="form-label">
                    質問の種類
                </label>

                <select id="questionType">
                    <option value="text"
                        ${q.type === 'text' ? 'selected':''}>
                        文章を入力する
                    </option>

                    <option value="single"
                        ${q.type === 'single' ? 'selected':''}>
                        1つだけ選ぶ
                    </option>

                    <option value="multiple"
                        ${q.type === 'multiple' ? 'selected':''}>
                        複数選ぶ
                    </option>

                    <option value="rating"
                        ${q.type === 'rating' ? 'selected':''}>
                        段階で評価する
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label>
                    <input id="questionRequired"
                        type="checkbox"
                        ${q.required ? 'checked':''}>
                    必須回答
                </label>
            </div>

            <div class="form-group">
                <label class="form-label">
                    補足説明
                </label>

                <textarea id="questionHelp">${escapeHtml(q.help || '')}</textarea>
            </div>

            <div class="form-group">
                <label class="form-label">
                    選択肢
                </label>

                <textarea id="questionChoices"
                    placeholder="1行に1つ">${escapeHtml(choiceText)}</textarea>

                <div class="help">
                    「文章を入力する」の場合は不要です。
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">
                    所属グループ
                </label>

                <select id="questionGroup">
                    ${survey.groups.map(g => `
                        <option value="${escapeHtml(g.id)}"
                            ${q.groupId === g.id ? 'selected':''}>
                            ${escapeHtml(g.name)}
                        </option>
                    `).join('')}
                </select>
            </div>
        `,
        `
            <button class="btn"
                onclick="closeModal()">
                キャンセル
            </button>

            <button class="btn btn-primary"
                onclick="saveQuestion('${q.id}')">
                保存する
            </button>
        `
    );
}

function saveQuestion(id){

    const survey = currentSurvey();

    if(!survey || !canStructuralEdit(survey)){
        return;
    }

    const q =
        survey.questions.find(
            x => x.id === id
        );

    if(!q){
        return;
    }

    const text =
        document.getElementById('questionText')
            ?.value.trim() || '';

    const type =
        document.getElementById('questionType')
            ?.value || 'text';

    const required =
        !!document.getElementById('questionRequired')
            ?.checked;

    const help =
        document.getElementById('questionHelp')
            ?.value || '';

    const choicesText =
        document.getElementById('questionChoices')
            ?.value || '';

    const groupId =
        document.getElementById('questionGroup')
            ?.value || survey.groups[0]?.id;

    if(!text){

        toast('質問文を入力してください。');
        return;
    }

    q.text = text;
    q.type = type;
    q.required = required;
    q.help = help;
    q.groupId = groupId;

    if(['single','multiple','rating'].includes(type)){

        let lines =
            choicesText
                .split(/\r?\n/)
                .map(x => x.trim())
                .filter(Boolean);

        if(type === 'rating' && lines.length === 0){
            lines = ['1','2','3','4','5'];
        }

        q.choices = lines.map((text,index) => ({
            id:uid('c') + '_' + index,
            text
        }));

    }else{
        q.choices = [];
    }

    survey.updatedAt = nowString();

    saveState();
    closeModal();
    renderPage();

    toast('質問を保存しました。');
}

function deleteQuestion(id){

    const survey = currentSurvey();

    if(!survey || !canStructuralEdit(survey)){
        return;
    }

    const q =
        survey.questions.find(
            x => x.id === id
        );

    if(!q){
        return;
    }

    const referenced =
        survey.questions.some(other =>
            other.branches &&
            Object.values(other.branches).some(
                b =>
                    b &&
                    b.type === 'question' &&
                    b.target === id
            )
        );

    showConfirm(
        '質問を削除する',
        `
            <p>
                「${escapeHtml(q.text || '未入力')}」
                を削除します。
            </p>

            ${
                referenced
                ? `
                    <p class="text-danger">
                        この質問は分岐先として使用されています。
                        削除すると分岐設定の見直しが必要です。
                    </p>
                `
                : ''
            }
        `,
        '削除する',
        function(){

            survey.questions =
                survey.questions.filter(
                    x => x.id !== id
                );

            survey.updatedAt = nowString();

            saveState();
            closeModal();
            renderPage();

            toast('質問を削除しました。');
        },
        'danger'
    );
}

/* =========================================================
   Preview / Validation
========================================================= */

function validateSurvey(survey){

    const errors = [];

    if(!survey.name?.trim()){
        errors.push('アンケート名が設定されていません。');
    }

    if(!survey.questions.length){
        errors.push('質問が1件以上必要です。');
    }

    survey.questions.forEach((q,index) => {

        if(!q.text?.trim()){
            errors.push(
                `質問${index + 1}：質問文を入力してください。`
            );
        }

        if(
            ['single','multiple','rating'].includes(q.type) &&
            (!Array.isArray(q.choices) || q.choices.length === 0)
        ){
            errors.push(
                `質問${index + 1}：選択肢を設定してください。`
            );
        }

        if(q.type === 'single' && q.branches){

            Object.entries(q.branches).forEach(
                ([choiceId,branch]) => {

                    if(!branch){
                        errors.push(
                            `質問${index + 1}：分岐設定が未設定です。`
                        );
                        return;
                    }

                    if(
                        branch.type === 'question' &&
                        !survey.questions.some(
                            x => x.id === branch.target
                        )
                    ){
                        errors.push(
                            `質問${index + 1}：存在しない質問が分岐先になっています。`
                        );
                    }

                    if(
                        branch.type === 'group' &&
                        !survey.groups.some(
                            g => g.id === branch.target
                        )
                    ){
                        errors.push(
                            `質問${index + 1}：存在しないグループが分岐先になっています。`
                        );
                    }
                }
            );
        }
    });

    if(
        survey.startAt &&
        survey.endAt &&
        survey.startAt >= survey.endAt
    ){
        errors.push(
            '回答受付開始日時は終了日時より前にしてください。'
        );
    }

    return {
        valid:errors.length === 0,
        errors
    };
}

function openPreview(id){

    if(id){
        setCurrentSurvey(id);
    }

    const survey = currentSurvey();

    if(!survey){
        return;
    }

    const validation =
        validateSurvey(survey);

    if(validation.errors.length){

        showValidationErrors(
            validation.errors
        );

        return;
    }

    navigate('preview');
}

function renderPreview(){

    const survey = currentSurvey();

    if(!survey){
        return `
            <div class="error-box">
                アンケートがありません。
            </div>
        `;
    }

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    公開前確認
                </h1>

                <p class="page-description">
                    回答者から見た内容を確認してください。
                </p>
            </div>

            <div class="actions">

                <button class="btn"
                    onclick="editSurvey(${survey.id})">
                    編集画面に戻る
                </button>

                ${
                    survey.status === 'draft'
                    ? `
                        <button class="btn btn-primary"
                            onclick="publishSurvey(${survey.id})">
                            公開する
                        </button>
                    `
                    : ''
                }

            </div>
        </div>

        <div class="preview-shell">

            <div class="preview-header">

                <h1>
                    ${escapeHtml(survey.name)}
                </h1>

                <p>
                    ${escapeHtml(survey.description)}
                </p>

                ${
                    survey.guidance
                    ? `
                        <div class="info-box">
                            ${escapeHtml(survey.guidance)}
                        </div>
                    `
                    : ''
                }

                <div>
                    ${statusBadge(survey.status)}
                </div>

            </div>

            ${survey.groups.map(group => `

                <div class="card">

                    <div class="card-head">
                        <h2 class="card-title">
                            ${escapeHtml(group.name)}
                        </h2>
                    </div>

                    <div class="card-body">

                        ${
                            survey.questions
                                .filter(q => q.groupId === group.id)
                                .map(q => renderPreviewQuestion(survey,q))
                                .join('')
                        }

                    </div>

                </div>

            `).join('')}

            <div class="card">

                <div class="card-head">
                    <h2 class="card-title">
                        分岐設定
                    </h2>
                </div>

                <div class="card-body">

                    ${renderBranchSummary(survey)}

                </div>

            </div>

            <div class="card">

                <div class="card-head">
                    <h2 class="card-title">
                        完了時の表示
                    </h2>
                </div>

                <div class="card-body">
                    ${escapeHtml(survey.completeMessage)}
                </div>

            </div>

        </div>
    `;
}

function renderPreviewQuestion(survey,q){

    let input = '';

    if(q.type === 'text'){

        input = `
            <textarea
                placeholder="回答を入力してください。"
                disabled></textarea>
        `;

    }else if(q.type === 'single'){

        input = q.choices.map(c => `
            <label class="option">
                <input type="radio"
                    name="preview_${escapeHtml(q.id)}"
                    disabled>

                <span>
                    ${escapeHtml(
                        typeof c === 'string'
                        ? c
                        : c.text
                    )}
                </span>
            </label>
        `).join('');

    }else if(q.type === 'multiple'){

        input = q.choices.map(c => `
            <label class="option">
                <input type="checkbox" disabled>

                <span>
                    ${escapeHtml(
                        typeof c === 'string'
                        ? c
                        : c.text
                    )}
                </span>
            </label>
        `).join('');

    }else if(q.type === 'rating'){

        input = `
            <div class="actions">
                ${q.choices.map(c => `
                    <label class="btn">
                        <input type="radio"
                            name="rating_${escapeHtml(q.id)}"
                            disabled>

                        ${escapeHtml(
                            typeof c === 'string'
                            ? c
                            : c.text
                        )}
                    </label>
                `).join('')}
            </div>
        `;
    }

    return `
        <div class="answer-question">

            <div class="answer-question-title">

                質問${questionNumber(survey,q)}：
                ${escapeHtml(q.text)}

                ${
                    q.required
                    ? `<span class="required-label">必須</span>`
                    : ''
                }

            </div>

            ${input}

            ${
                q.help
                ? `
                    <div class="help">
                        ${escapeHtml(q.help)}
                    </div>
                `
                : ''
            }

        </div>
    `;
}

function renderBranchSummary(survey){

    const rows = [];

    survey.questions.forEach(q => {

        if(q.type !== 'single'){
            return;
        }

        const choices = q.choices || [];

        choices.forEach(choice => {

            const choiceId =
                typeof choice === 'string'
                ? choice
                : choice.id;

            const choiceText =
                typeof choice === 'string'
                ? choice
                : choice.text;

            const branch =
                q.branches?.[choiceId];

            let destination = '次の質問';

            if(!branch){
                destination = '未設定';
            }else if(branch.type === 'question'){

                const target =
                    survey.questions.find(
                        x => x.id === branch.target
                    );

                destination =
                    target
                    ? `質問${questionNumber(survey,target)}：${target.text}`
                    : '存在しない質問';
            }else if(branch.type === 'group'){

                const target =
                    survey.groups.find(
                        g => g.id === branch.target
                    );

                destination =
                    target
                    ? `グループ：${target.name}`
                    : '存在しないグループ';

            }else if(branch.type === 'end'){

                destination = '回答終了';
            }

            rows.push(`
                <tr>
                    <td>
                        質問${questionNumber(survey,q)}
                    </td>
                    <td>
                        ${escapeHtml(choiceText)}
                    </td>
                    <td>
                        ${escapeHtml(destination)}
                    </td>
                </tr>
            `);
        });
    });

    if(!rows.length){
        return `
            <div class="muted">
                分岐設定はありません。
            </div>
        `;
    }

    return `
        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>対象質問</th>
                        <th>選択肢</th>
                        <th>分岐先</th>
                    </tr>
                </thead>

                <tbody>
                    ${rows.join('')}
                </tbody>

            </table>

        </div>
    `;
}

function publishSurvey(id){

    const survey =
        state.surveys.find(
            s => Number(s.id) === Number(id)
        );

    if(!survey){
        return;
    }

    const validation =
        validateSurvey(survey);

    if(validation.errors.length){

        showValidationErrors(
            validation.errors
        );

        return;
    }

    showConfirm(
        'アンケートを公開する',
        `
            <p>
                「${escapeHtml(survey.name)}」
                を公開します。
            </p>

            <p>
                公開後は質問構造や分岐などの変更に
                制限があります。
            </p>

            <p>
                回答開始日時：
                <strong>
                    ${formatDate(survey.startAt) ||
                    '未設定（公開後すぐ開始）'}
                </strong>
            </p>
        `,
        '公開する',
        function(){

            const now = new Date();

            if(survey.startAt){

                const start =
                    new Date(survey.startAt);

                survey.status =
                    start > now
                    ? 'wait'
                    : 'active';

            }else{
                survey.status = 'active';
            }

            survey.updatedAt = nowString();

            saveState();
            closeModal();
            navigate('surveys');

            toast('アンケートを公開しました。');
        },
        'primary'
    );
}

/* =========================================================
   Survey State
========================================================= */

function startSurvey(id){

    const survey =
        state.surveys.find(
            s => Number(s.id) === Number(id)
        );

    if(!survey){
        return;
    }

    if(survey.status !== 'wait'){

        toast(
            '回答受付開始できる状態ではありません。'
        );

        return;
    }

    showConfirm(
        '回答受付を開始する',
        `
            <p>
                「${escapeHtml(survey.name)}」
                の回答受付を開始します。
            </p>

            <p>
                開始後は回答者がアンケートに
                回答できるようになります。
            </p>
        `,
        '回答受付を開始する',
        function(){

            survey.status = 'active';
            survey.updatedAt = nowString();

            saveState();
            closeModal();
            navigate('surveys');

            toast('回答受付を開始しました。');
        },
        'primary'
    );
}

function endSurvey(id){

    const survey =
        state.surveys.find(
            s => Number(s.id) === Number(id)
        );

    if(!survey){
        return;
    }

    showConfirm(
        '回答受付を終了する',
        `
            <p>
                「${escapeHtml(survey.name)}」
                の回答受付を終了します。
            </p>

            <p>
                終了すると新しい回答を
                受け付けなくなります。
            </p>
        `,
        '回答受付を終了する',
        function(){

            survey.status = 'ended';
            survey.updatedAt = nowString();

            saveState();
            closeModal();
            navigate('surveys');

            toast('回答受付を終了しました。');
        },
        'warning'
    );
}

function archiveSurvey(id){

    const survey =
        state.surveys.find(
            s => Number(s.id) === Number(id)
        );

    if(!survey){
        return;
    }

    showConfirm(
        'アンケートを保管する',
        `
            <p>
                「${escapeHtml(survey.name)}」
                を保管します。
            </p>

            <p>
                保管後は再公開・再送付できません。
            </p>
        `,
        '保管する',
        function(){

            survey.status = 'archived';
            survey.updatedAt = nowString();

            saveState();
            closeModal();
            navigate('surveys');

            toast('アンケートを保管しました。');
        },
        'primary'
    );
}

function deleteSurvey(id){

    const survey =
        state.surveys.find(
            s => Number(s.id) === Number(id)
        );

    if(!survey){
        return;
    }

    showConfirm(
        'アンケートを削除する',
        `
            <p>
                「${escapeHtml(survey.name || '名称未設定')}」
                を削除します。
            </p>

            <p class="text-danger">
                このモックでは削除後の復元はできません。
            </p>
        `,
        '削除する',
        function(){

            state.surveys =
                state.surveys.filter(
                    s => Number(s.id) !== Number(id)
                );

            state.currentSurveyId =
                state.surveys[0]?.id || null;

            saveState();
            closeModal();
            navigate('surveys');

            toast('アンケートを削除しました。');
        },
        'danger'
    );
}

/* =========================================================
   Responses
========================================================= */

function openResponses(id){

    if(id){
        setCurrentSurvey(id);
    }

    navigate('responses');
}

function renderResponses(){

    const survey = currentSurvey();

    if(!survey){
        return `
            <div class="error-box">
                アンケートがありません。
            </div>
        `;
    }

    const rate = responseRate(survey);

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    回答状況
                </h1>

                <p class="page-description">
                    ${escapeHtml(survey.name)}
                </p>
            </div>

            <div class="actions">

                <button class="btn"
                    onclick="navigate('surveys')">
                    アンケート一覧
                </button>

                <button class="btn"
                    onclick="openResponseDetail(${survey.id})">
                    回答内容を見る
                </button>

            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    現在の状態
                </h2>

                ${statusBadge(survey.status)}
            </div>

            <div class="card-body">

                <div class="kpi-grid">

                    <div class="kpi">
                        <div class="kpi-label">
                            送付数
                        </div>
                        <div class="kpi-value">
                            ${survey.sentCount}
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            回答数
                        </div>
                        <div class="kpi-value">
                            ${survey.responseCount}
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            回答率
                        </div>
                        <div class="kpi-value">
                            ${rate === null ? '-' : rate + '%'}
                        </div>
                    </div>

                    <div class="kpi">
                        <div class="kpi-label">
                            回答受付期間
                        </div>
                        <div style="margin-top:8px">
                            ${formatDate(survey.startAt)}
                            ～<br>
                            ${formatDate(survey.endAt)}
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    回答状況の概要
                </h2>
            </div>

            <div class="card-body">

                <div class="progress-track">
                    <div class="progress-bar"
                        style="width:${Math.min(rate || 0,100)}%">
                    </div>
                </div>

                <p class="muted">
                    ${survey.sentCount}件送付中、
                    ${survey.responseCount}件回答済み
                </p>

                <div class="actions">

                    <button class="btn btn-primary"
                        onclick="openResponseDetail(${survey.id})">
                        回答内容を見る
                    </button>

                    ${
                        ['wait','active'].includes(survey.status)
                        ? `
                            <button class="btn"
                                onclick="openSend(${survey.id})">
                                アンケートを送付する
                            </button>
                        `
                        : ''
                    }

                    ${
                        survey.status === 'active'
                        ? `
                            <button class="btn btn-warning"
                                onclick="endSurvey(${survey.id})">
                                回答受付を終了する
                            </button>
                        `
                        : ''
                    }

                </div>

            </div>
        </div>
    `;
}

/* =========================================================
   Response Detail
========================================================= */

function openResponseDetail(id){

    if(id){
        setCurrentSurvey(id);
    }

    navigate('response-detail');
}

function renderResponseDetail(){

    const survey = currentSurvey();

    if(!survey){
        return `
            <div class="error-box">
                アンケートがありません。
            </div>
        `;
    }

    const answers = survey.answers || [];

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    回答内容
                </h1>

                <p class="page-description">
                    ${escapeHtml(survey.name)}
                </p>
            </div>

            <button class="btn"
                onclick="openResponses(${survey.id})">
                回答状況へ戻る
            </button>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    回答一覧
                </h2>

                <span class="muted">
                    ${answers.length}件
                </span>
            </div>

            <div class="card-body">

                ${
                    answers.length
                    ? `
                        <div class="table-wrap">

                            <table>

                                <thead>
                                    <tr>
                                        <th>回答番号</th>
                                        <th>回答日時</th>
                                        <th>回答者</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    ${answers.map(a => `
                                        <tr>

                                            <td>
                                                ${a.id}
                                            </td>

                                            <td>
                                                ${escapeHtml(a.answeredAt)}
                                            </td>

                                            <td>
                                                ${escapeHtml(a.respondent)}
                                            </td>

                                            <td>
                                                <button
                                                    class="btn btn-sm"
                                                    onclick="showAnswer(${a.id})">
                                                    回答内容を見る
                                                </button>
                                            </td>

                                        </tr>
                                    `).join('')}

                                </tbody>

                            </table>

                        </div>
                    `
                    : `
                        <div class="muted">
                            回答はありません。
                        </div>
                    `
                }

            </div>
        </div>
    `;
}

function showAnswer(answerId){

    const survey = currentSurvey();

    if(!survey){
        return;
    }

    const answer =
        survey.answers.find(
            a => Number(a.id) === Number(answerId)
        );

    if(!answer){
        return;
    }

    const html =
        survey.questions.map(q => {

            const value =
                answer.values?.[q.id];

            let display = '';

            if(Array.isArray(value)){
                display = value.join('、');
            }else{
                display = value ?? '未回答';
            }

            return `
                <div style="
                    border-bottom:1px solid var(--gray-200);
                    padding:10px 0">

                    <div class="small muted">
                        質問${questionNumber(survey,q)}
                    </div>

                    <div style="
                        font-weight:700;
                        margin-top:3px">
                        ${escapeHtml(q.text)}
                    </div>

                    <div style="
                        margin-top:6px;
                        white-space:pre-wrap">
                        ${escapeHtml(display)}
                    </div>

                </div>
            `;
        }).join('');

    showModal(
        `回答 #${answer.id}`,
        `
            <p>
                回答日時：
                ${escapeHtml(answer.answeredAt)}
            </p>

            <p>
                回答者：
                ${escapeHtml(answer.respondent)}
            </p>

            ${html}
        `,
        `
            <button class="btn"
                onclick="closeModal()">
                閉じる
            </button>
        `
    );
}

/* =========================================================
   Send
========================================================= */

function openSend(id){

    if(id){
        setCurrentSurvey(id);
    }

    const survey = currentSurvey();

    if(!survey){
        return;
    }

    if(survey.status === 'archived'){

        toast(
            '保管済みアンケートは送付できません。'
        );

        return;
    }

    if(!['wait','active'].includes(survey.status)){

        toast(
            '現在の状態ではアンケートを送付できません。'
        );

        return;
    }

    state.sendDraft = {
        surveyId:survey.id,
        customerIds:[
            ...(survey.selectedCustomerIds || [])
        ],
        subject:`${survey.name}のご案内`,
        body:
            `いつもお世話になっております。\n\n` +
            `以下のアンケートへのご回答をお願いいたします。\n\n` +
            `${survey.name}\n\n` +
            `よろしくお願いいたします。`
    };

    saveState();

    navigate('send');
}

function renderSend(){

    const survey = currentSurvey();

    if(!survey){
        return `
            <div class="error-box">
                アンケートがありません。
            </div>
        `;
    }

    const draft =
        state.sendDraft || {
            surveyId:survey.id,
            customerIds:[],
            subject:`${survey.name}のご案内`,
            body:''
        };

    const selected =
        state.customers.filter(
            c => draft.customerIds.includes(c.id)
        );

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    アンケート送付
                </h1>

                <p class="page-description">
                    ${escapeHtml(survey.name)}
                </p>
            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    送付対象
                </h2>
            </div>

            <div class="card-body">

                <div class="info-box">
                    選択中：
                    <strong>${selected.length}件</strong>
                </div>

                <div class="actions">

                    <button class="btn btn-primary"
                        onclick="navigate('customers')">
                        顧客を選択する
                    </button>

                    <button class="btn"
                        onclick="selectAllCustomers()">
                        全選択する
                    </button>

                    <button class="btn"
                        onclick="clearCustomers()">
                        全選択を解除する
                    </button>

                </div>

                ${
                    selected.length
                    ? `
                        <div style="margin-top:15px">

                            ${selected.map(c => `
                                <span style="
                                    display:inline-flex;
                                    align-items:center;
                                    gap:5px;
                                    background:#eff6ff;
                                    color:#1d4ed8;
                                    border-radius:999px;
                                    padding:5px 9px;
                                    margin:3px">

                                    ${escapeHtml(c.name)}

                                    <button
                                        style="
                                            border:0;
                                            background:transparent;
                                            color:#1d4ed8"
                                        onclick="toggleCustomer(${c.id})">
                                        ×
                                    </button>

                                </span>
                            `).join('')}

                        </div>
                    `
                    : ''
                }

            </div>
        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    メール内容
                </h2>
            </div>

            <div class="card-body">

                <div class="form-group">

                    <label class="form-label">
                        件名
                    </label>

                    <input id="sendSubject"
                        type="text"
                        value="${escapeHtml(draft.subject)}"
                        onchange="updateSendDraft()">

                </div>

                <div class="form-group">

                    <label class="form-label">
                        本文
                    </label>

                    <textarea id="sendBody"
                        style="min-height:220px"
                        onchange="updateSendDraft()">${escapeHtml(draft.body)}</textarea>

                </div>

                <div class="info-box">
                    実際のSMTP送信は行わず、
                    モック上で送付結果を生成します。
                </div>

                <button class="btn btn-primary"
                    onclick="openSendConfirm()">
                    送付内容を確認する
                </button>

            </div>
        </div>
    `;
}

function updateSendDraft(){

    if(!state.sendDraft){
        return;
    }

    state.sendDraft.subject =
        document.getElementById('sendSubject')
            ?.value || '';

    state.sendDraft.body =
        document.getElementById('sendBody')
            ?.value || '';

    saveState();
}

function selectAllCustomers(){

    if(!state.sendDraft){
        return;
    }

    state.sendDraft.customerIds =
        state.customers.map(c => c.id);

    saveState();
    renderPage();
}

function clearCustomers(){

    if(!state.sendDraft){
        return;
    }

    state.sendDraft.customerIds = [];

    saveState();
    renderPage();
}

function toggleCustomer(id){

    if(!state.sendDraft){
        return;
    }

    const index =
        state.sendDraft.customerIds.indexOf(id);

    if(index >= 0){
        state.sendDraft.customerIds.splice(index,1);
    }else{
        state.sendDraft.customerIds.push(id);
    }

    saveState();
    renderPage();
}

/* =========================================================
   Customers
========================================================= */

function renderCustomers(){

    const draft =
        state.sendDraft || {
            customerIds:[]
        };

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    顧客選択
                </h1>

                <p class="page-description">
                    アンケートの送付先を選択してください。
                </p>
            </div>

            <div class="actions">

                <button class="btn"
                    onclick="navigate('send')">
                    送付画面へ戻る
                </button>

                <button class="btn btn-primary"
                    onclick="selectAllCustomers()">
                    全選択する
                </button>

                <button class="btn"
                    onclick="clearCustomers()">
                    全選択を解除する
                </button>

            </div>
        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    顧客一覧
                </h2>

                <strong>
                    選択中：
                    ${draft.customerIds.length}件
                </strong>
            </div>

            <div class="card-body">

                <div class="table-wrap">

                    <table>

                        <thead>
                            <tr>
                                <th>選択</th>
                                <th>顧客名</th>
                                <th>担当者名</th>
                                <th>メールアドレス</th>
                            </tr>
                        </thead>

                        <tbody>

                            ${state.customers.map(c => `
                                <tr>

                                    <td>
                                        <input
                                            type="checkbox"
                                            ${draft.customerIds.includes(c.id)
                                                ? 'checked':''}
                                            onchange="toggleCustomer(${c.id})">
                                    </td>

                                    <td>
                                        ${escapeHtml(c.name)}
                                    </td>

                                    <td>
                                        ${escapeHtml(c.contact)}
                                    </td>

                                    <td>
                                        ${escapeHtml(c.email)}
                                    </td>

                                </tr>
                            `).join('')}

                        </tbody>

                    </table>

                </div>

            </div>
        </div>
    `;
}

/* =========================================================
   Send Confirm / Result
========================================================= */

function openSendConfirm(){

    if(!state.sendDraft){
        return;
    }

    const survey = currentSurvey();

    const selected =
        state.customers.filter(
            c =>
                state.sendDraft.customerIds
                    .includes(c.id)
        );

    if(!selected.length){

        showModal(
            '送付先を確認してください',
            `
                <div class="error-box">
                    送付先を1件以上選択してください。
                </div>
            `,
            `
                <button class="btn"
                    onclick="closeModal()">
                    閉じる
                </button>
            `
        );

        return;
    }

    if(!state.sendDraft.subject.trim()){

        showModal(
            'メール内容を確認してください',
            `
                <div class="error-box">
                    メール件名を入力してください。
                </div>
            `,
            `
                <button class="btn"
                    onclick="closeModal()">
                    閉じる
                </button>
            `
        );

        return;
    }

    navigate('send-confirm');
}

function renderSendConfirm(){

    const survey = currentSurvey();
    const draft = state.sendDraft;

    if(!survey || !draft){
        return `
            <div class="error-box">
                送付内容がありません。
            </div>
        `;
    }

    const selected =
        state.customers.filter(
            c => draft.customerIds.includes(c.id)
        );

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    送付確認
                </h1>

                <p class="page-description">
                    送信前に内容を確認してください。
                </p>
            </div>

            <button class="btn"
                onclick="navigate('send')">
                送付内容を修正する
            </button>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    送付内容
                </h2>
            </div>

            <div class="card-body">

                <p>
                    <strong>アンケート：</strong>
                    ${escapeHtml(survey.name)}
                </p>

                <p>
                    <strong>送付先：</strong>
                    ${selected.length}件
                </p>

                <p>
                    <strong>件名：</strong>
                    ${escapeHtml(draft.subject)}
                </p>

                <div>
                    <strong>本文：</strong>

                    <div style="
                        white-space:pre-wrap;
                        margin-top:8px;
                        padding:15px;
                        background:var(--gray-50);
                        border-radius:8px">
                        ${escapeHtml(draft.body)}
                    </div>
                </div>

            </div>
        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    送付先
                </h2>
            </div>

            <div class="card-body">

                <ul>
                    ${selected.map(c => `
                        <li>
                            ${escapeHtml(c.name)}
                            /
                            ${escapeHtml(c.email)}
                        </li>
                    `).join('')}
                </ul>

                <div class="actions">

                    <button class="btn"
                        onclick="navigate('send')">
                        送付先を変更する
                    </button>

                    <button class="btn btn-primary"
                        onclick="sendSurvey()">
                        アンケートを送付する
                    </button>

                </div>

            </div>
        </div>
    `;
}

function sendSurvey(){

    const survey = currentSurvey();
    const draft = state.sendDraft;

    if(!survey || !draft){
        return;
    }

    const selected =
        state.customers.filter(
            c => draft.customerIds.includes(c.id)
        );

    if(!selected.length){
        toast('送付先を選択してください。');
        return;
    }

    showConfirm(
        'アンケートを送付する',
        `
            <p>
                ${selected.length}件へ
                「${escapeHtml(survey.name)}」
                を送付します。
            </p>

            <p>
                このモックでは実際のメール送信は行わず、
                送付結果だけを生成します。
            </p>
        `,
        'アンケートを送付する',
        function(){

            const resultId =
                state.sendResults.reduce(
                    (max,r) =>
                        Math.max(max,Number(r.id)||0),
                    0
                ) + 1;

            const success =
                Math.max(
                    0,
                    selected.length -
                    (selected.length >= 3 ? 1 : 0)
                );

            const failed =
                selected.length - success;

            const failedCustomers =
                failed
                ? [selected[selected.length-1].name]
                : [];

            state.sendResults.unshift({
                id:resultId,
                surveyId:survey.id,
                target:selected.length,
                success,
                failed,
                sentAt:nowString(),
                failedCustomers
            });

            survey.sentCount =
                Number(survey.sentCount || 0) +
                success;

            survey.selectedCustomerIds =
                [...draft.customerIds];

            survey.updatedAt = nowString();

            state.sendDraft = null;

            saveState();

            closeModal();
            navigate('send-result');

            toast('アンケートを送付しました。');
        },
        'primary'
    );
}

function renderSendResult(){

    const results =
        state.sendResults || [];

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    送付結果
                </h1>

                <p class="page-description">
                    アンケート送付結果を確認できます。
                </p>
            </div>

            <button class="btn"
                onclick="navigate('surveys')">
                アンケート一覧
            </button>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    送付履歴
                </h2>
            </div>

            <div class="card-body">

                ${
                    results.length
                    ? `
                        <div class="table-wrap">

                            <table>

                                <thead>
                                    <tr>
                                        <th>アンケート</th>
                                        <th>対象</th>
                                        <th>送付成功</th>
                                        <th>送付失敗</th>
                                        <th>送付日時</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>

                                <tbody>

                                ${results.map(r => {

                                    const survey =
                                        state.surveys.find(
                                            s => Number(s.id) ===
                                                Number(r.surveyId)
                                        );

                                    return `
                                        <tr>

                                            <td>
                                                ${escapeHtml(
                                                    survey?.name || '-'
                                                )}
                                            </td>

                                            <td>${r.target}件</td>

                                            <td class="text-success">
                                                ${r.success}件
                                            </td>

                                            <td class="text-danger">
                                                ${r.failed}件
                                            </td>

                                            <td>
                                                ${escapeHtml(r.sentAt)}
                                            </td>

                                            <td>
                                                <button
                                                    class="btn btn-sm"
                                                    onclick="showSendResult(${r.id})">
                                                    結果を見る
                                                </button>
                                            </td>

                                        </tr>
                                    `;
                                }).join('')}

                                </tbody>

                            </table>

                        </div>
                    `
                    : `
                        <div class="muted">
                            送付履歴はありません。
                        </div>
                    `
                }

            </div>
        </div>
    `;
}

function showSendResult(id){

    const result =
        state.sendResults.find(
            r => Number(r.id) === Number(id)
        );

    if(!result){
        return;
    }

    showModal(
        '送付結果',
        `
            <p>
                対象件数：
                <strong>${result.target}件</strong>
            </p>

            <p class="text-success">
                送付成功：
                <strong>${result.success}件</strong>
            </p>

            <p class="text-danger">
                送付失敗：
                <strong>${result.failed}件</strong>
            </p>

            <p>
                送付日時：
                ${escapeHtml(result.sentAt)}
            </p>

            ${
                result.failedCustomers?.length
                ? `
                    <div class="error-box">
                        <strong>送付できなかった顧客</strong>
                        <ul>
                            ${result.failedCustomers.map(
                                x => `<li>${escapeHtml(x)}</li>`
                            ).join('')}
                        </ul>
                    </div>
                `
                : ''
            }
        `,
        `
            <button class="btn"
                onclick="closeModal()">
                閉じる
            </button>
        `
    );
}

/* =========================================================
   Settings
========================================================= */

function renderSettings(){

    const k = state.settings.kintone;
    const s = state.settings.smtp;

    return `
        <div class="page-head">

            <div>
                <h1 class="page-title">
                    各種設定
                </h1>

                <p class="page-description">
                    kintone顧客情報とメール送信設定を確認・変更できます。
                </p>
            </div>

        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    kintone設定
                </h2>

                ${
                    k.connected
                    ? `<span class="status status-active">接続確認済み</span>`
                    : `<span class="status status-ended">未接続</span>`
                }
            </div>

            <div class="card-body">

                <div class="form-grid">

                    <div class="form-group">
                        <label class="form-label">
                            接続先
                        </label>

                        <input id="kHost"
                            type="text"
                            value="${escapeHtml(k.host)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            対象アプリ
                        </label>

                        <input id="kApp"
                            type="text"
                            value="${escapeHtml(k.app)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            顧客名
                        </label>

                        <input id="kName"
                            type="text"
                            value="${escapeHtml(k.nameField)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            担当者名
                        </label>

                        <input id="kContact"
                            type="text"
                            value="${escapeHtml(k.contactField)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            メールアドレス
                        </label>

                        <input id="kEmail"
                            type="text"
                            value="${escapeHtml(k.emailField)}">
                    </div>

                </div>

                <div class="actions">

                    <button class="btn btn-primary"
                        onclick="saveKintoneSettings()">
                        設定を保存する
                    </button>

                    <button class="btn"
                        onclick="testKintone()">
                        接続を確認する
                    </button>

                    <button class="btn"
                        onclick="refreshCustomers()">
                        顧客一覧を更新する
                    </button>

                </div>

                <p class="small muted">
                    最終更新：
                    ${escapeHtml(k.updatedAt || '-')}
                </p>

            </div>
        </div>

        <div class="card">

            <div class="card-head">
                <h2 class="card-title">
                    メール設定
                </h2>

                ${
                    s.configured
                    ? `<span class="status status-active">設定済み</span>`
                    : `<span class="status status-ended">未設定</span>`
                }
            </div>

            <div class="card-body">

                <div class="form-grid">

                    <div class="form-group">
                        <label class="form-label">
                            SMTPサーバー
                        </label>

                        <input id="smtpHost"
                            type="text"
                            value="${escapeHtml(s.host)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            ポート
                        </label>

                        <input id="smtpPort"
                            type="number"
                            value="${escapeHtml(s.port)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            送信元メールアドレス
                        </label>

                        <input id="smtpFrom"
                            type="email"
                            value="${escapeHtml(s.from)}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            暗号化方式
                        </label>

                        <select id="smtpEncryption">
                            <option
                                ${s.encryption === 'STARTTLS' ? 'selected':''}>
                                STARTTLS
                            </option>

                            <option
                                ${s.encryption === 'SSL/TLS' ? 'selected':''}>
                                SSL/TLS
                            </option>

                            <option
                                ${s.encryption === 'なし' ? 'selected':''}>
                                なし
                            </option>
                        </select>
                    </div>

                </div>

                <div class="actions">

                    <button class="btn btn-primary"
                        onclick="saveSmtpSettings()">
                        設定を保存する
                    </button>

                    <button class="btn"
                        onclick="testSmtp()">
                        設定を確認する
                    </button>

                </div>

            </div>
        </div>
    `;
}

function saveKintoneSettings(){

    const k = state.settings.kintone;

    k.host =
        document.getElementById('kHost')?.value || '';

    k.app =
        document.getElementById('kApp')?.value || '';

    k.nameField =
        document.getElementById('kName')?.value || '';

    k.contactField =
        document.getElementById('kContact')?.value || '';

    k.emailField =
        document.getElementById('kEmail')?.value || '';

    k.updatedAt = nowString();

    saveState();
    renderPage();

    toast('kintone設定を保存しました。');
}

function testKintone(){

    const k = state.settings.kintone;

    k.connected = true;
    k.updatedAt = nowString();

    saveState();
    renderPage();

    showModal(
        '接続確認',
        `
            <div class="success-box">
                kintone接続設定を確認しました。
            </div>

            <p>
                このモックでは実際のkintone APIへ接続していません。
            </p>
        `,
        `
            <button class="btn"
                onclick="closeModal()">
                閉じる
            </button>
        `
    );
}

function refreshCustomers(){

    state.settings.kintone.updatedAt = nowString();

    saveState();

    toast(
        '顧客一覧を更新しました（モック）。'
    );
}

function saveSmtpSettings(){

    const s = state.settings.smtp;

    s.host =
        document.getElementById('smtpHost')?.value || '';

    s.port =
        document.getElementById('smtpPort')?.value || '';

    s.from =
        document.getElementById('smtpFrom')?.value || '';

    s.encryption =
        document.getElementById('smtpEncryption')?.value || '';

    s.configured = true;

    saveState();
    renderPage();

    toast('メール設定を保存しました。');
}

function testSmtp(){

    state.settings.smtp.configured = true;

    saveState();

    showModal(
        'メール設定確認',
        `
            <div class="success-box">
                SMTP設定を確認しました。
            </div>

            <p>
                このモックでは実際のSMTP接続・送信は行いません。
            </p>
        `,
        `
            <button class="btn"
                onclick="closeModal()">
                閉じる
            </button>
        `
    );
}

/* =========================================================
   Answerer
========================================================= */

let answerState = {
    surveyId:null,
    currentIndex:0,
    values:{},
    visibleQuestions:[]
};

function startAnswer(id){

    const survey =
        id
        ? state.surveys.find(
            s => Number(s.id) === Number(id)
          )
        : currentSurvey();

    if(!survey){
        return;
    }

    if(survey.status !== 'active'){

        showModal(
            '回答できません',
            `
                <div class="error-box">
                    現在の状態では回答を受け付けていません。
                </div>
            `,
            `
                <button class="btn"
                    onclick="closeModal()">
                    閉じる
                </button>
            `
        );

        return;
    }

    answerState = {
        surveyId:survey.id,
        currentIndex:0,
        values:{},
        visibleQuestions:calculateVisibleQuestions(
            survey,
            {}
        )
    };

    navigate('answer');
}

function calculateVisibleQuestions(survey,values){

    if(!survey){
        return [];
    }

    const all = survey.questions || [];
    const result = [];

    let i = 0;
    const visited = new Set();

    while(i < all.length){

        if(visited.has(all[i].id)){
            break;
        }

        visited.add(all[i].id);

        const q = all[i];

        result.push(q);

        let nextIndex = i + 1;

        if(q.type === 'single'){

            const selected = values[q.id];

            const choice =
                (q.choices || []).find(
                    c =>
                        (typeof c === 'string'
                            ? c
                            : c.id) === selected
                );

            const choiceId =
                choice
                ? (typeof choice === 'string'
                    ? choice
                    : choice.id)
                : selected;

            const branch =
                q.branches?.[choiceId];

            if(branch){

                if(branch.type === 'question'){

                    const targetIndex =
                        all.findIndex(
                            x => x.id === branch.target
                        );

                    if(targetIndex >= 0){
                        nextIndex = targetIndex;
                    }

                }else if(branch.type === 'group'){

                    const groupIndex =
                        all.findIndex(
                            x => x.groupId === branch.target
                        );

                    if(groupIndex >= 0){
                        nextIndex = groupIndex;
                    }

                }else if(branch.type === 'end'){

                    break;
                }
            }
        }

        i = nextIndex;
    }

    return result;
}

function renderAnswer(){

    const survey =
        state.surveys.find(
            s => Number(s.id) ===
                Number(answerState.surveyId)
        );

    if(!survey){
        return `
            <div class="error-box">
                回答対象のアンケートがありません。
            </div>
        `;
    }

    answerState.visibleQuestions =
        calculateVisibleQuestions(
            survey,
            answerState.values
        );

    const questions =
        answerState.visibleQuestions;

    if(!questions.length){
        return `
            <div class="success-box">
                回答可能な質問がありません。
            </div>
        `;
    }

    const q =
        questions[answerState.currentIndex];

    if(!q){
        return renderAnswerConfirm();
    }

    const progress =
        Math.round(
            ((answerState.currentIndex + 1) /
            questions.length) * 100
        );

    return `
        <div class="preview-shell">

            <div class="preview-header">

                <h1>
                    ${escapeHtml(survey.name)}
                </h1>

                <p>
                    ${escapeHtml(survey.description)}
                </p>

                ${
                    survey.guidance
                    ? `
                        <div class="info-box">
                            ${escapeHtml(survey.guidance)}
                        </div>
                    `
                    : ''
                }

                <div class="answer-progress">

                    <div class="small muted">
                        現在の回答状況：
                        ${answerState.currentIndex + 1}
                        /
                        ${questions.length}
                    </div>

                    <div class="progress-track"
                        style="margin-top:6px">

                        <div class="progress-bar"
                            style="width:${progress}%">
                        </div>

                    </div>

                </div>

            </div>

            <div id="answerError"></div>

            <div class="answer-question">

                <div class="answer-question-title">

                    質問${questionNumber(survey,q)}：
                    ${escapeHtml(q.text)}

                    ${
                        q.required
                        ? `<span class="required-label">必須</span>`
                        : ''
                    }

                </div>

                ${renderAnswerInput(q)}

                ${
                    q.help
                    ? `
                        <div class="help">
                            ${escapeHtml(q.help)}
                        </div>
                    `
                    : ''
                }

            </div>

            <div class="actions"
                style="justify-content:space-between">

                <button class="btn"
                    onclick="answerBack()"
                    ${answerState.currentIndex === 0
                        ? 'disabled':''}>
                    前の質問へ戻る
                </button>

                <button class="btn btn-primary"
                    onclick="answerNext()">
                    ${
                        answerState.currentIndex + 1 >= questions.length
                        ? '回答内容を確認する'
                        : '次の質問へ進む'
                    }
                </button>

            </div>

        </div>
    `;
}

function renderAnswerInput(q){

    const value =
        answerState.values[q.id];

    if(q.type === 'text'){

        return `
            <textarea
                id="answer_${escapeHtml(q.id)}"
                onchange="saveAnswerValue('${q.id}',this.value)"
                oninput="saveAnswerValue('${q.id}',this.value)"
            >${escapeHtml(value || '')}</textarea>
        `;
    }

    if(q.type === 'single'){

        return (q.choices || []).map(c => {

            const id =
                typeof c === 'string'
                ? c
                : c.id;

            const text =
                typeof c === 'string'
                ? c
                : c.text;

            return `
                <label class="option">

                    <input type="radio"
                        name="answer_${escapeHtml(q.id)}"
                        value="${escapeHtml(id)}"
                        ${value === id ? 'checked':''}
                        onchange="saveAnswerValue('${q.id}',this.value)">

                    <span>
                        ${escapeHtml(text)}
                    </span>

                </label>
            `;
        }).join('');
    }

    if(q.type === 'multiple'){

        const current =
            Array.isArray(value)
            ? value
            : [];

        return (q.choices || []).map(c => {

            const id =
                typeof c === 'string'
                ? c
                : c.id;

            const text =
                typeof c === 'string'
                ? c
                : c.text;

            return `
                <label class="option">

                    <input type="checkbox"
                        value="${escapeHtml(id)}"
                        ${current.includes(id)
                            ? 'checked':''}
                        onchange="
                            toggleAnswerMultiple(
                                '${q.id}',
                                this.value,
                                this.checked
                            )
                        ">

                    <span>
                        ${escapeHtml(text)}
                    </span>

                </label>
            `;
        }).join('');
    }

    if(q.type === 'rating'){

        return `
            <div class="actions">

                ${(q.choices || []).map(c => {

                    const id =
                        typeof c === 'string'
                        ? c
                        : c.id;

                    const text =
                        typeof c === 'string'
                        ? c
                        : c.text;

                    return `
                        <label class="btn">

                            <input type="radio"
                                name="answer_${escapeHtml(q.id)}"
                                value="${escapeHtml(id)}"
                                ${value === id ? 'checked':''}
                                onchange="
                                    saveAnswerValue(
                                        '${q.id}',
                                        this.value
                                    )
                                ">

                            ${escapeHtml(text)}

                        </label>
                    `;
                }).join('')}

            </div>
        `;
    }

    return '';
}

function saveAnswerValue(questionId,value){

    answerState.values[questionId] = value;
}

function toggleAnswerMultiple(
    questionId,
    value,
    checked
){

    let current =
        Array.isArray(
            answerState.values[questionId]
        )
        ? answerState.values[questionId]
        : [];

    if(checked){

        if(!current.includes(value)){
            current.push(value);
        }

    }else{

        current =
            current.filter(
                v => v !== value
            );
    }

    answerState.values[questionId] =
        current;
}

function validateAnswerQuestion(q){

    if(!q.required){
        return true;
    }

    const value =
        answerState.values[q.id];

    if(q.type === 'multiple'){

        return Array.isArray(value) &&
               value.length > 0;
    }

    return value !== undefined &&
           value !== null &&
           String(value).trim() !== '';
}

function answerNext(){

    const survey =
        state.surveys.find(
            s => Number(s.id) ===
                Number(answerState.surveyId)
        );

    if(!survey){
        return;
    }

    const q =
        answerState.visibleQuestions[
            answerState.currentIndex
        ];

    if(!q){
        return;
    }

    if(!validateAnswerQuestion(q)){

        const error =
            document.getElementById('answerError');

        if(error){

            error.innerHTML = `
                <div class="error-box">
                    この質問は必須です。
                    回答を入力してから次へ進んでください。
                </div>
            `;

            error.scrollIntoView({
                behavior:'smooth',
                block:'center'
            });
        }

        return;
    }

    answerState.visibleQuestions =
        calculateVisibleQuestions(
            survey,
            answerState.values
        );

    if(
        answerState.currentIndex + 1 >=
        answerState.visibleQuestions.length
    ){

        navigate('answer-confirm');

    }else{

        answerState.currentIndex++;
        renderPage();
    }
}

function answerBack(){

    if(answerState.currentIndex > 0){

        answerState.currentIndex--;
        renderPage();
    }
}

function renderAnswerConfirm(){

    const survey =
        state.surveys.find(
            s => Number(s.id) ===
                Number(answerState.surveyId)
        );

    if(!survey){
        return `
            <div class="error-box">
                回答対象がありません。
            </div>
        `;
    }

    const visible =
        calculateVisibleQuestions(
            survey,
            answerState.values
        );

    const errors =
        visible.filter(
            q => !validateAnswerQuestion(q)
        );

    if(errors.length){

        return `
            <div class="preview-shell">

                <div class="error-box">
                    必須質問に未回答があります。
                </div>

                <button class="btn"
                    onclick="navigate('answer')">
                    回答画面へ戻る
                </button>

            </div>
        `;
    }

    return `
        <div class="preview-shell">

            <div class="page-head">

                <div>
                    <h1 class="page-title">
                        回答確認
                    </h1>

                    <p class="page-description">
                        送信前に回答内容を確認してください。
                    </p>
                </div>

            </div>

            <div class="card">

                <div class="card-head">
                    <h2 class="card-title">
                        ${escapeHtml(survey.name)}
                    </h2>
                </div>

                <div class="card-body">

                    ${visible.map(q => {

                        const value =
                            answerState.values[q.id];

                        let display =
                            Array.isArray(value)
                            ? value.join('、')
                            : value;

                        if(
                            display === undefined ||
                            display === null ||
                            display === ''
                        ){
                            display = '未回答';
                        }

                        return `
                            <div style="
                                border-bottom:1px solid var(--gray-200);
                                padding:12px 0">

                                <div class="small muted">
                                    質問${questionNumber(survey,q)}
                                </div>

                                <div style="
                                    font-weight:700;
                                    margin-top:4px">
                                    ${escapeHtml(q.text)}
                                </div>

                                <div style="
                                    margin-top:7px;
                                    white-space:pre-wrap">
                                    ${escapeHtml(display)}
                                </div>

                            </div>
                        `;
                    }).join('')}

                </div>
            </div>

            <div class="actions"
                style="justify-content:flex-end">

                <button class="btn"
                    onclick="navigate('answer')">
                    回答を修正する
                </button>

                <button class="btn btn-primary"
                    onclick="submitAnswer()">
                    回答を送信する
                </button>

            </div>

        </div>
    `;
}

function submitAnswer(){

    const survey =
        state.surveys.find(
            s => Number(s.id) ===
                Number(answerState.surveyId)
        );

    if(!survey){
        return;
    }

    const visible =
        calculateVisibleQuestions(
            survey,
            answerState.values
        );

    const errors =
        visible.filter(
            q => !validateAnswerQuestion(q)
        );

    if(errors.length){

        showModal(
            '未回答の質問があります',
            `
                <div class="error-box">

                    以下の必須質問に回答してください。

                    <ul>
                        ${errors.map(q =>
                            `<li>
                                質問${questionNumber(survey,q)}：
                                ${escapeHtml(q.text)}
                            </li>`
                        ).join('')}
                    </ul>

                </div>
            `,
            `
                <button class="btn"
                    onclick="closeModal()">
                    閉じる
                </button>
            `
        );

        return;
    }

    showConfirm(
        '回答を送信する',
        `
            <p>
                入力した回答を送信します。
            </p>

            <p>
                送信後はこのモック上では
                回答内容を変更できません。
            </p>
        `,
        '回答を送信する',
        function(){

            const nextId =
                survey.answers.reduce(
                    (max,a) =>
                        Math.max(max,Number(a.id)||0),
                    0
                ) + 1;

            survey.answers.push({
                id:nextId,
                answeredAt:nowString(),
                respondent:'モック回答者',
                values:
                    JSON.parse(
                        JSON.stringify(
                            answerState.values
                        )
                    )
            });

            survey.responseCount =
                Number(survey.responseCount || 0) + 1;

            saveState();
            closeModal();

            navigate('answer-complete');
        },
        'primary'
    );
}

function renderAnswerComplete(){

    const survey =
        state.surveys.find(
            s => Number(s.id) ===
                Number(answerState.surveyId)
        );

    return `
        <div class="preview-shell">

            <div class="card">

                <div class="card-body"
                    style="text-align:center;padding:50px 25px">

                    <div style="
                        font-size:48px;
                        color:var(--success)">
                        ✓
                    </div>

                    <h1>
                        回答が完了しました
                    </h1>

                    <p>
                        ${escapeHtml(
                            survey?.completeMessage ||
                            'ご回答ありがとうございました。'
                        )}
                    </p>

                </div>

            </div>

        </div>
    `;
}

/* =========================================================
   Modal
========================================================= */

function showModal(
    title,
    body,
    footer
){

    document.getElementById('modalTitle')
        .textContent = title;

    document.getElementById('modalBody')
        .innerHTML = body;

    document.getElementById('modalFooter')
        .innerHTML = footer || '';

    document.getElementById('modalBackdrop')
        .classList.add('show');
}

function showConfirm(
    title,
    body,
    confirmLabel,
    onConfirm,
    buttonClass='primary'
){

    showModal(
        title,
        body,
        `
            <button class="btn"
                onclick="closeModal()">
                キャンセル
            </button>

            <button
                class="btn btn-${buttonClass}"
                id="modalConfirmButton">
                ${escapeHtml(confirmLabel)}
            </button>
        `
    );

    const button =
        document.getElementById(
            'modalConfirmButton'
        );

    if(button){
        button.onclick = function(){
            onConfirm();
        };
    }
}

function closeModal(){

    const modal =
        document.getElementById(
            'modalBackdrop'
        );

    if(modal){
        modal.classList.remove('show');
    }
}

function showValidationErrors(
    errors,
    targetId=null
){

    const html = `
        <div class="error-box">

            <strong>
                入力内容を確認してください。
            </strong>

            <ul>
                ${errors.map(
                    e => `<li>${escapeHtml(e)}</li>`
                ).join('')}
            </ul>

        </div>
    `;

    if(
        targetId &&
        document.getElementById(targetId)
    ){

        document.getElementById(targetId)
            .innerHTML = html;

        document.getElementById(targetId)
            .scrollIntoView({
                behavior:'smooth',
                block:'center'
            });

        return;
    }

    showModal(
        '公開前チェック',
        html,
        `
            <button class="btn"
                onclick="closeModal()">
                閉じる
            </button>
        `
    );
}

function toast(message){

    const el =
        document.getElementById('toast');

    if(!el){
        return;
    }

    el.textContent = message;
    el.classList.add('show');

    clearTimeout(
        window.__toastTimer
    );

    window.__toastTimer =
        setTimeout(
            () => el.classList.remove('show'),
            2500
        );
}

/* =========================================================
   Events
========================================================= */

function bindPageEvents(){

    const backdrop =
        document.getElementById(
            'modalBackdrop'
        );

    if(
        backdrop &&
        !backdrop.dataset.bound
    ){

        backdrop.dataset.bound = '1';

        backdrop.addEventListener(
            'click',
            function(e){

                if(e.target === backdrop){
                    closeModal();
                }
            }
        );
    }
}

/* =========================================================
   Global Error Handling
========================================================= */

window.addEventListener(
    'error',
    function(event){

        console.error(
            'JavaScriptエラー:',
            event.error || event.message
        );
    }
);

window.addEventListener(
    'unhandledrejection',
    function(event){

        console.error(
            '未処理Promiseエラー:',
            event.reason
        );
    }
);

/* =========================================================
   Initialization
========================================================= */

(function initializeMock(){

    /*
     * 必ず
     * 1. storage初期化
     * 2. stateロード
     * 3. currentSurvey補正
     * 4. renderPage
     * の順で実行する。
     */

    initStorage();

    state = loadState();

    if(
        !state.currentSurveyId &&
        state.surveys.length
    ){
        state.currentSurveyId =
            state.surveys[0].id;
    }

    if(!state.currentPage){
        state.currentPage = 'home';
    }

    saveState();

    navigate(state.currentPage);

})();

</script>

</body>
</html>
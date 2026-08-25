<?php
/*
 * アンケート管理システム
 * 1ファイル・インタラクティブモック
 *
 * 本番実装ではありません。
 * DB / kintone API / SMTP / 認証 / 実ファイル生成は使用しません。
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>アンケート管理システム モック</title>

<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --gray:#64748b;
    --border:#e2e8f0;
    --bg:#f8fafc;
    --white:#fff;
    --text:#0f172a;
    --muted:#64748b;
    --shadow:0 2px 10px rgba(15,23,42,.07);
    --radius:10px;
}

*{box-sizing:border-box}

body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",
        "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
    color:var(--text);
    background:var(--bg);
}

button,input,textarea,select{
    font:inherit;
}

button{
    cursor:pointer;
}

.admin-header{
    height:64px;
    background:#0f172a;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:28px;
    position:sticky;
    top:0;
    z-index:50;
}

.admin-logo{
    font-weight:700;
    white-space:nowrap;
}

.admin-nav{
    display:flex;
    gap:4px;
    flex:1;
}

.admin-nav button{
    background:transparent;
    color:#cbd5e1;
    border:0;
    padding:10px 14px;
    border-radius:7px;
}

.admin-nav button:hover,
.admin-nav button.active{
    background:#1e293b;
    color:#fff;
}

.logout{
    color:#cbd5e1;
    white-space:nowrap;
}

.main{
    max-width:1440px;
    margin:0 auto;
    padding:28px;
}

.page-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    margin-bottom:24px;
}

.page-title h1{
    margin:0;
    font-size:26px;
}

.page-title p{
    color:var(--muted);
    margin:7px 0 0;
    font-size:14px;
}

.btn{
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    border-radius:7px;
    padding:9px 14px;
    min-height:40px;
}

.btn:hover{
    background:#f1f5f9;
}

.btn-primary{
    background:var(--primary);
    border-color:var(--primary);
    color:#fff;
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-success{
    background:var(--success);
    border-color:var(--success);
    color:#fff;
}

.btn-danger{
    background:var(--danger);
    border-color:var(--danger);
    color:#fff;
}

.btn-warning{
    background:#f59e0b;
    border-color:#f59e0b;
    color:#fff;
}

.btn-small{
    padding:6px 9px;
    min-height:32px;
    font-size:13px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:20px;
}

.card h2{
    font-size:18px;
    margin:0 0 18px;
}

.card h3{
    font-size:16px;
    margin:0;
}

.toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
    margin-bottom:18px;
}

.search-box{
    flex:1;
    min-width:220px;
}

input[type="text"],
input[type="email"],
input[type="password"],
input[type="datetime-local"],
input[type="number"],
textarea,
select{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:7px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

textarea{
    min-height:100px;
    resize:vertical;
}

label{
    display:block;
    font-size:14px;
    font-weight:600;
    margin-bottom:7px;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-full{
    grid-column:1/-1;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:1000px;
}

th,td{
    padding:13px 12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
    font-size:14px;
}

th{
    background:#f8fafc;
    color:#475569;
    white-space:nowrap;
}

.status{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:4px 10px;
    font-size:12px;
    font-weight:700;
}

.status-public{
    color:#166534;
    background:#dcfce7;
}

.status-draft{
    color:#475569;
    background:#e2e8f0;
}

.status-stop{
    color:#92400e;
    background:#fef3c7;
}

.status-end{
    color:#991b1b;
    background:#fee2e2;
}

.action-group{
    display:flex;
    flex-wrap:wrap;
    gap:5px;
}

.notice{
    border-radius:8px;
    padding:12px 15px;
    margin-bottom:18px;
    font-size:14px;
}

.notice-info{
    background:#eff6ff;
    color:#1e40af;
    border:1px solid #bfdbfe;
}

.notice-success{
    background:#f0fdf4;
    color:#166534;
    border:1px solid #bbf7d0;
}

.notice-warning{
    background:#fffbeb;
    color:#92400e;
    border:1px solid #fde68a;
}

.notice-danger{
    background:#fef2f2;
    color:#991b1b;
    border:1px solid #fecaca;
}

.editor-actions{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:15px 18px;
    margin-bottom:18px;
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    box-shadow:var(--shadow);
}

.editor-actions .state-wrap{
    display:flex;
    align-items:center;
    gap:8px;
    margin-left:auto;
}

.editor-actions .state-wrap label{
    margin:0;
    white-space:nowrap;
}

.editor-actions .state-wrap select{
    width:auto;
    min-width:150px;
}

.question-group{
    border:1px solid #cbd5e1;
    border-radius:10px;
    background:#fff;
    margin-bottom:18px;
    overflow:hidden;
}

.group-header{
    padding:14px 16px;
    background:#f1f5f9;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    gap:10px;
}

.drag-handle{
    cursor:grab;
    color:#64748b;
    font-size:18px;
}

.group-header input{
    flex:1;
    font-weight:700;
}

.group-actions{
    display:flex;
    gap:5px;
}

.questions{
    padding:15px;
    min-height:50px;
}

.question{
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:12px;
    padding:15px;
    background:#fff;
}

.question:last-child{
    margin-bottom:0;
}

.question.dragging,
.question-group.dragging{
    opacity:.45;
}

.question-head{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:12px;
}

.question-number{
    color:var(--primary);
    font-weight:800;
    min-width:55px;
}

.question-head input{
    flex:1;
}

.question-grid{
    display:grid;
    grid-template-columns:1fr 180px 100px;
    gap:12px;
}

.choice-list{
    margin-top:12px;
    display:grid;
    gap:7px;
}

.choice-row{
    display:flex;
    gap:7px;
}

.choice-row input{
    flex:1;
}

.question-footer{
    margin-top:12px;
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
}

.check-label{
    display:flex;
    align-items:center;
    gap:6px;
    margin:0;
    font-weight:400;
}

.branch-row{
    margin-top:12px;
    padding:10px;
    background:#f8fafc;
    border-radius:7px;
}

.branch-item{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
    margin-bottom:7px;
}

.add-area{
    text-align:center;
    padding:14px;
    border-top:1px dashed var(--border);
}

.add-group{
    width:100%;
    margin-top:10px;
}

.preview-frame{
    background:#e2e8f0;
    padding:30px;
    min-height:600px;
}

.preview-device{
    background:#fff;
    margin:auto;
    padding:28px;
    border-radius:10px;
    box-shadow:var(--shadow);
    max-width:900px;
}

.preview-device.mobile{
    max-width:390px;
}

.preview-question{
    border-bottom:1px solid var(--border);
    padding:18px 0;
}

.preview-question h4{
    margin:0 0 10px;
}

.required{
    color:#dc2626;
    font-size:12px;
    font-weight:700;
}

.answer-options{
    display:grid;
    gap:9px;
}

.answer-option{
    display:flex;
    gap:8px;
    align-items:center;
    padding:10px;
    border:1px solid var(--border);
    border-radius:7px;
}

.summary-grid{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:15px;
}

.summary-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
}

.summary-card .label{
    color:var(--muted);
    font-size:13px;
}

.summary-card .value{
    font-size:28px;
    font-weight:800;
    margin-top:6px;
}

.bar-row{
    display:grid;
    grid-template-columns:180px 1fr 60px;
    gap:10px;
    align-items:center;
    margin:10px 0;
}

.bar{
    height:12px;
    border-radius:99px;
    background:#dbeafe;
    overflow:hidden;
}

.bar span{
    display:block;
    height:100%;
    background:var(--primary);
}

.modal-overlay{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.55);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:100;
    padding:20px;
}

.modal{
    width:min(520px,100%);
    background:#fff;
    border-radius:12px;
    box-shadow:0 20px 60px rgba(0,0,0,.2);
    overflow:hidden;
}

.modal-header{
    padding:18px 20px;
    border-bottom:1px solid var(--border);
    font-weight:700;
}

.modal-body{
    padding:20px;
}

.modal-footer{
    padding:14px 20px;
    border-top:1px solid var(--border);
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.toast{
    position:fixed;
    right:22px;
    bottom:22px;
    z-index:200;
    background:#0f172a;
    color:#fff;
    padding:13px 18px;
    border-radius:8px;
    box-shadow:var(--shadow);
    display:none;
}

.answer-header{
    background:#fff;
    border-bottom:1px solid var(--border);
    padding:22px;
}

.answer-header-inner{
    max-width:900px;
    margin:auto;
}

.answer-main{
    max-width:900px;
    margin:auto;
    padding:25px 20px 60px;
}

.answer-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:22px;
    margin-bottom:18px;
}

.answer-card h3{
    margin:0 0 14px;
}

.answer-footer{
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:20px;
}

.answer-choice{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:13px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:8px;
}

.confirm-answer{
    padding:15px;
    border-bottom:1px solid var(--border);
}

.center{
    text-align:center;
}

.empty{
    padding:50px 20px;
    text-align:center;
    color:var(--muted);
}

.mobile-only-note{
    display:none;
}

@media(max-width:900px){
    .admin-header{
        padding:0 12px;
        gap:8px;
        overflow-x:auto;
    }

    .admin-nav button{
        padding:8px 9px;
        font-size:12px;
    }

    .main{
        padding:18px 12px;
    }

    .form-grid,
    .question-grid{
        grid-template-columns:1fr;
    }

    .summary-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .editor-actions .state-wrap{
        margin-left:0;
        width:100%;
    }

    .editor-actions .state-wrap select{
        flex:1;
    }
}

@media(max-width:600px){
    .admin-logo{
        display:none;
    }

    .admin-header{
        position:relative;
        height:auto;
        padding:8px;
    }

    .admin-nav{
        overflow-x:auto;
    }

    .page-title{
        align-items:flex-start;
        flex-direction:column;
    }

    .summary-grid{
        grid-template-columns:1fr 1fr;
    }

    .summary-card .value{
        font-size:22px;
    }

    .preview-frame{
        padding:10px;
    }

    .preview-device{
        padding:18px;
    }

    .mobile-only-note{
        display:block;
    }
}
</style>
</head>

<body>

<div id="app"></div>

<div id="modalRoot"></div>
<div id="toast" class="toast"></div>

<script>
/* =========================================================
   モックデータ
========================================================= */

const state = {
    page: "list",
    previousPage: "list",
    editingId: null,
    previewMobile: false,
    answerStep: 1,
    answerData: {},
    confirmAnswers: false,

    surveys: [
        {
            id: 1,
            title: "2026年度 顧客満足度アンケート",
            description: "サービスについてのご意見をお聞かせください。",
            start: "2026-04-01T09:00",
            end: "2026-09-30T18:00",
            status: "公開中",
            answers: 128,
            numbering: "global",
            allowReAnswer: false,
            groups: [
                {
                    id: 101,
                    title: "サービスについて",
                    questions: [
                        {
                            id: 1001,
                            text: "サービスを利用したことがありますか？",
                            type: "single",
                            required: true,
                            choices: ["はい", "いいえ"],
                            branches: {
                                "はい": 2,
                                "いいえ": 4
                            }
                        },
                        {
                            id: 1002,
                            text: "サービスの満足度を教えてください。",
                            type: "single",
                            required: true,
                            choices: ["非常に満足", "満足", "普通", "不満", "非常に不満"],
                            branches: {}
                        }
                    ]
                },
                {
                    id: 102,
                    title: "ご意見",
                    questions: [
                        {
                            id: 1003,
                            text: "今後改善してほしい点があれば教えてください。",
                            type: "multi",
                            required: false,
                            choices: ["料金", "機能", "サポート", "操作性", "その他"],
                            branches: {}
                        },
                        {
                            id: 1004,
                            text: "その他、ご意見をご自由にお書きください。",
                            type: "textarea",
                            required: false,
                            choices: [],
                            branches: {}
                        }
                    ]
                }
            ]
        },
        {
            id: 2,
            title: "新サービス事前ヒアリング",
            description: "新サービスに関する事前アンケートです。",
            start: "2026-08-01T09:00",
            end: "2026-08-31T18:00",
            status: "下書き",
            answers: 0,
            numbering: "group",
            allowReAnswer: false,
            groups: [
                {
                    id: 201,
                    title: "基本情報",
                    questions: [
                        {
                            id: 2001,
                            text: "現在利用しているサービスを教えてください。",
                            type: "single",
                            required: true,
                            choices: ["サービスA", "サービスB", "その他"],
                            branches: {}
                        }
                    ]
                }
            ]
        },
        {
            id: 3,
            title: "2025年度 サポート満足度調査",
            description: "昨年度のサポートについてのアンケートです。",
            start: "2025-04-01T09:00",
            end: "2026-03-31T18:00",
            status: "終了",
            answers: 342,
            numbering: "global",
            allowReAnswer: false,
            groups: []
        },
        {
            id: 4,
            title: "展示会来場者アンケート",
            description: "展示会ご来場者向けアンケートです。",
            start: "2026-07-01T09:00",
            end: "2026-07-31T18:00",
            status: "停止",
            answers: 47,
            numbering: "global",
            allowReAnswer: true,
            groups: []
        }
    ],

    customers: [
        {
            id: 1,
            org: "株式会社サンプル",
            name: "山田 太郎",
            email: "yamada@example.com",
            tel: "03-0000-0000",
            address: "東京都港区",
            sent: "2026-08-20 10:30",
            count: 1,
            status: "回答済み",
            kintone: true
        },
        {
            id: 2,
            org: "株式会社テスト",
            name: "佐藤 花子",
            email: "sato@example.com",
            tel: "03-1111-1111",
            address: "東京都渋谷区",
            sent: "2026-08-21 14:20",
            count: 1,
            status: "送信済み / 未回答",
            kintone: true
        },
        {
            id: 3,
            org: "合同会社モック",
            name: "鈴木 一郎",
            email: "suzuki@example.com",
            tel: "03-2222-2222",
            address: "東京都新宿区",
            sent: "",
            count: 0,
            status: "未送信",
            kintone: false
        },
        {
            id: 4,
            org: "未登録企業",
            name: "田中 次郎",
            email: "tanaka@example.com",
            tel: "090-0000-0000",
            address: "東京都中央区",
            sent: "2026-08-22 09:00",
            count: 1,
            status: "回答済み",
            kintone: false
        }
    ],

    settings: {
        kintone: {
            subdomain: "example.cybozu.com",
            appId: "123",
            login: "admin",
            password: "password",
            ssl: true,
            connected: true
        },
        smtp: {
            server: "smtp.example.com",
            port: "587",
            encryption: "TLS",
            auth: true,
            username: "mail@example.com",
            password: "password",
            from: "survey@example.com",
            fromName: "アンケート事務局",
            reply: "support@example.com",
            status: "接続確認済み"
        }
    },

    mailHistory: [
        {
            date: "2026-08-22 09:00",
            type: "一括送信",
            count: 4,
            subject: "アンケートご協力のお願い",
            executor: "管理者"
        },
        {
            date: "2026-08-23 10:30",
            type: "リマインド",
            count: 2,
            subject: "【再送】アンケートご協力のお願い",
            executor: "管理者"
        }
    ]
};


/* =========================================================
   ユーティリティ
========================================================= */

function uid(){
    return Date.now() + Math.floor(Math.random() * 10000);
}

function getSurvey(id){
    return state.surveys.find(s => s.id == id);
}

function escapeHtml(str){
    if(str === undefined || str === null) return "";
    return String(str)
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;")
        .replace(/'/g,"&#039;");
}

function formatDate(value){
    if(!value) return "";
    return value.replace("T"," ");
}

function statusClass(status){
    if(status === "公開中") return "status-public";
    if(status === "下書き") return "status-draft";
    if(status === "停止") return "status-stop";
    return "status-end";
}

function toast(message){
    const el = document.getElementById("toast");
    el.textContent = message;
    el.style.display = "block";
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => {
        el.style.display = "none";
    },2500);
}

function render(){
    const app = document.getElementById("app");

    if(state.page.startsWith("answer")){
        app.innerHTML = renderAnswerLayout();
        return;
    }

    app.innerHTML = renderAdminLayout();
}

function navigate(page){
    state.previousPage = state.page;
    state.page = page;
    render();
    window.scrollTo(0,0);
}

function adminHeader(){
    return `
        <header class="admin-header">
            <div class="admin-logo">アンケート管理</div>

            <nav class="admin-nav">
                <button class="${state.page==='list'||state.page==='editor'?'active':''}"
                        onclick="navigate('list')">アンケート一覧</button>
                <button class="${state.page==='kintone'?'active':''}"
                        onclick="navigate('kintone')">kintone連携設定</button>
                <button class="${state.page==='smtp'?'active':''}"
                        onclick="navigate('smtp')">メールサーバ設定</button>
            </nav>

            <div class="logout">ログアウト</div>
        </header>
    `;
}

function renderAdminLayout(){
    return adminHeader() + `
        <main class="main">
            ${renderPage()}
        </main>
    `;
}

function renderPage(){
    switch(state.page){
        case "list": return renderList();
        case "editor": return renderEditor();
        case "preview": return renderPreview();
        case "send": return renderSend();
        case "history": return renderHistory();
        case "analysis": return renderAnalysis();
        case "kintone": return renderKintone();
        case "smtp": return renderSMTP();
        default: return renderList();
    }
}


/* =========================================================
   アンケート一覧
========================================================= */

function renderList(){
    return `
        <div class="page-title">
            <div>
                <h1>アンケート一覧</h1>
                <p>登録されているアンケートを管理します。</p>
            </div>
            <button class="btn btn-primary" onclick="newSurvey()">＋ 新規アンケート作成</button>
        </div>

        <div class="card">
            <div class="toolbar">
                <input id="surveySearch"
                       class="search-box"
                       type="text"
                       placeholder="タイトルで検索"
                       onkeydown="if(event.key==='Enter') filterSurveys()">

                <select id="surveyStatus" onchange="filterSurveys()">
                    <option value="">すべて</option>
                    <option>公開中</option>
                    <option>下書き</option>
                    <option>停止</option>
                    <option>終了</option>
                </select>

                <select id="surveySort" onchange="filterSurveys()">
                    <option value="updatedDesc">更新日：新しい順</option>
                    <option value="updatedAsc">更新日：古い順</option>
                    <option value="answersDesc">回答数：多い順</option>
                    <option value="answersAsc">回答数：少ない順</option>
                    <option value="startDesc">期間開始日：新しい順</option>
                    <option value="startAsc">期間開始日：古い順</option>
                </select>

                <button class="btn" onclick="filterSurveys()">検索</button>
            </div>

            <div id="surveyTable"></div>
        </div>
    `;
}

function filterSurveys(){
    const keyword = (document.getElementById("surveySearch")?.value || "").toLowerCase();
    const status = document.getElementById("surveyStatus")?.value || "";
    const sort = document.getElementById("surveySort")?.value || "updatedDesc";

    let list = state.surveys.filter(s =>
        s.title.toLowerCase().includes(keyword) &&
        (!status || s.status === status)
    );

    list.sort((a,b)=>{
        if(sort==="answersDesc") return b.answers-a.answers;
        if(sort==="answersAsc") return a.answers-b.answers;

        const ad = new Date(a.start);
        const bd = new Date(b.start);

        if(sort==="startDesc") return bd-ad;
        if(sort==="startAsc") return ad-bd;

        const aid = a.id;
        const bid = b.id;

        return sort==="updatedAsc" ? aid-bid : bid-aid;
    });

    const el = document.getElementById("surveyTable");

    if(!list.length){
        el.innerHTML = `<div class="empty">該当するアンケートはありません。</div>`;
        return;
    }

    el.innerHTML = `
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>作成日 / 更新日</th>
                    <th>タイトル</th>
                    <th>アンケート期間</th>
                    <th>ステータス</th>
                    <th>回答数</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                ${list.map(s=>`
                    <tr>
                        <td>
                            2026/08/01<br>
                            <small>2026/08/${String((s.id*3)%24+1).padStart(2,"0")}</small>
                        </td>
                        <td><strong>${escapeHtml(s.title)}</strong></td>
                        <td>
                            ${escapeHtml(formatDate(s.start))}
                            ～<br>
                            ${escapeHtml(formatDate(s.end))}
                        </td>
                        <td>
                            <span class="status ${statusClass(s.status)}">${s.status}</span>
                        </td>
                        <td>${s.answers}</td>
                        <td>
                            <div class="action-group">
                                <button class="btn btn-small"
                                    onclick="editSurvey(${s.id})">確認・編集</button>

                                <button class="btn btn-small"
                                    onclick="openAnalysis(${s.id})">集計</button>

                                <button class="btn btn-small"
                                    onclick="openSend(${s.id})">送信</button>

                                <button class="btn btn-small"
                                    onclick="duplicateSurvey(${s.id})">複製</button>

                                <button class="btn btn-small btn-danger"
                                    onclick="deleteSurvey(${s.id})">削除</button>
                            </div>
                        </td>
                    </tr>
                `).join("")}
            </tbody>
        </table>
        </div>
    `;
}

setTimeout(filterSurveys,0);


/* =========================================================
   作成・編集
========================================================= */

function newSurvey(){
    const id = uid();

    const survey = {
        id,
        title:"",
        description:"",
        start:"",
        end:"",
        status:"下書き",
        answers:0,
        numbering:"global",
        allowReAnswer:false,
        groups:[
            {
                id:uid(),
                title:"グループ1",
                questions:[]
            }
        ]
    };

    state.surveys.push(survey);
    state.editingId = id;
    navigate("editor");
}

function editSurvey(id){
    state.editingId = id;
    navigate("editor");
}

function renderEditor(){
    const s = getSurvey(state.editingId);

    if(!s) return renderList();

    return `
        <div class="page-title">
            <div>
                <h1>アンケート作成・編集</h1>
                <p>基本情報、グループ、質問、公開状態を設定します。</p>
            </div>

            <button class="btn" onclick="previewSurvey()">プレビュー</button>
        </div>

        <div id="editorNotice"></div>

        <div class="editor-actions">
            <button class="btn btn-primary" onclick="saveDraft()">下書き保存</button>

            <button class="btn" onclick="saveAndBack()">保存して一覧へ戻る</button>

            <button class="btn" onclick="cancelEditor()">キャンセル</button>

            <div class="state-wrap">
                <label for="stateSelect">状態：</label>
                ${renderStateSelect(s)}
            </div>
        </div>

        <div class="card">
            <h2>基本情報</h2>

            <div class="form-grid">
                <div class="form-full">
                    <label>アンケートタイトル</label>
                    <input type="text"
                           value="${escapeHtml(s.title)}"
                           oninput="updateSurveyField('title',this.value)">
                </div>

                <div class="form-full">
                    <label>アンケート説明</label>
                    <textarea oninput="updateSurveyField('description',this.value)">${escapeHtml(s.description)}</textarea>
                </div>

                <div>
                    <label>開始日時</label>
                    <input type="datetime-local"
                           value="${escapeHtml(s.start)}"
                           oninput="updateSurveyField('start',this.value)">
                </div>

                <div>
                    <label>終了日時</label>
                    <input type="datetime-local"
                           value="${escapeHtml(s.end)}"
                           oninput="updateSurveyField('end',this.value)">
                </div>

                <div class="form-full">
                    <label>質問番号の採番方式</label>

                    <label class="check-label">
                        <input type="radio"
                               name="numbering"
                               value="global"
                               ${s.numbering==="global"?"checked":""}
                               onchange="changeNumbering('global')">
                        アンケート全体で通番
                    </label>

                    <label class="check-label">
                        <input type="radio"
                               name="numbering"
                               value="group"
                               ${s.numbering==="group"?"checked":""}
                               onchange="changeNumbering('group')">
                        グループ毎に採番
                    </label>
                </div>

                <div class="form-full">
                    <label class="check-label">
                        <input type="checkbox"
                               ${s.allowReAnswer?"checked":""}
                               onchange="updateSurveyField('allowReAnswer',this.checked)">
                        回答済みURLからの再回答を許可する
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>質問・グループ</h2>
            <div id="groupsArea">
                ${s.groups.map((g,index)=>renderGroup(s,g,index)).join("")}
            </div>

            <button class="btn btn-primary add-group" onclick="addGroup()">
                ＋ グループを追加
            </button>
        </div>
    `;
}

function renderStateSelect(s){
    if(s.status==="終了"){
        return `<select disabled><option>終了</option></select>`;
    }

    let options = [];

    if(s.status==="下書き"){
        options = ["下書き","公開"];
    }else if(s.status==="公開中"){
        options = ["公開","停止"];
    }else if(s.status==="停止"){
        options = ["停止","再開"];
    }

    return `
        <select onchange="requestStateChange(this.value)">
            ${options.map(o=>`
                <option value="${o}"
                    ${((s.status==="公開中"&&o==="公開") ||
                       (s.status==="停止"&&o==="停止") ||
                       (s.status==="下書き"&&o==="下書き"))?"selected":""}>
                    ${o==="公開"?"公開":o==="再開"?"再開":o}
                </option>
            `).join("")}
        </select>
    `;
}

function updateSurveyField(field,value){
    const s = getSurvey(state.editingId);
    if(!s) return;
    s[field] = value;
}

function saveDraft(){
    const s = getSurvey(state.editingId);
    s.status = "下書き";
    render();
    toast("下書きを保存しました");
}

function saveAndBack(){
    toast("保存しました");
    setTimeout(()=>navigate("list"),300);
}

function cancelEditor(){
    showModal(
        "変更内容の破棄",
        "入力した変更内容を破棄して前の画面へ戻りますか？",
        ()=>{
            navigate("list");
        }
    );
}

function requestStateChange(target){
    const s = getSurvey(state.editingId);

    if(!s || target===""){
        render();
        return;
    }

    if(target==="公開"){
        showModal(
            "公開確認",
            "このアンケートを公開しますか？",
            ()=>{
                s.status="公開中";
                render();
                toast("アンケートを公開しました");
            },
            ()=>render()
        );
    }else if(target==="停止"){
        showModal(
            "停止確認",
            "このアンケートを停止しますか？",
            ()=>{
                s.status="停止";
                render();
                toast("アンケートを停止しました");
            },
            ()=>render()
        );
    }else if(target==="再開"){
        showModal(
            "再開確認",
            "このアンケートを再開しますか？",
            ()=>{
                s.status="公開中";
                render();
                toast("アンケートを再開しました");
            },
            ()=>render()
        );
    }else{
        render();
    }
}

function changeNumbering(mode){
    const s = getSurvey(state.editingId);
    s.numbering = mode;
    render();
}

function questionNumber(s,gIndex,qIndex){
    if(s.numbering==="global"){
        let n=0;

        for(let i=0;i<s.groups.length;i++){
            for(let j=0;j<s.groups[i].questions.length;j++){
                n++;
                if(i===gIndex && j===qIndex){
                    return "Q"+n;
                }
            }
        }

        return "Q"+(n+1);
    }

    return "Q"+(gIndex+1)+"-"+(qIndex+1);
}


/* =========================================================
   グループ
========================================================= */

function renderGroup(s,g,gIndex){
    return `
        <div class="question-group"
             draggable="true"
             ondragstart="dragGroupStart(${g.id})"
             ondragover="event.preventDefault()"
             ondrop="dropGroup(${g.id})">

            <div class="group-header">
                <span class="drag-handle">☷</span>

                <input type="text"
                       value="${escapeHtml(g.title)}"
                       onchange="updateGroupTitle(${g.id},this.value)">

                <span class="status status-draft">
                    グループ ${gIndex+1}
                </span>

                <div class="group-actions">
                    <button class="btn btn-small btn-danger"
                            onclick="deleteGroup(${g.id})">削除</button>
                </div>
            </div>

            <div class="questions"
                 ondragover="event.preventDefault()"
                 ondrop="dropQuestionToGroup(event,${g.id})">

                ${g.questions.length
                    ? g.questions.map((q,qIndex)=>
                        renderQuestion(s,g,gIndex,q,qIndex)
                    ).join("")
                    : `<div class="empty">質問はまだありません。</div>`
                }
            </div>

            <div class="add-area">
                <button class="btn" onclick="addQuestion(${g.id})">
                    ＋ 質問を追加
                </button>
            </div>
        </div>
    `;
}

function addGroup(){
    const s = getSurvey(state.editingId);

    s.groups.push({
        id:uid(),
        title:"グループ"+(s.groups.length+1),
        questions:[]
    });

    render();
}

function updateGroupTitle(id,value){
    const s = getSurvey(state.editingId);
    const g = s.groups.find(x=>x.id===id);
    if(g) g.title=value;
}

function deleteGroup(id){
    const s = getSurvey(state.editingId);
    const g = s.groups.find(x=>x.id===id);

    if(!g) return;

    const message = g.questions.length
        ? `「${g.title}」には ${g.questions.length} 件の質問があります。このグループを削除しますか？`
        : `「${g.title}」を削除しますか？`;

    showModal("グループ削除",message,()=>{
        s.groups = s.groups.filter(x=>x.id!==id);
        render();
        toast("グループを削除しました");
    });
}

let draggingGroupId = null;

function dragGroupStart(id){
    draggingGroupId=id;
}

function dropGroup(targetId){
    if(!draggingGroupId || draggingGroupId===targetId) return;

    const s = getSurvey(state.editingId);

    const from = s.groups.findIndex(g=>g.id===draggingGroupId);
    const to = s.groups.findIndex(g=>g.id===targetId);

    const [item] = s.groups.splice(from,1);
    s.groups.splice(to,0,item);

    draggingGroupId=null;
    render();
}


/* =========================================================
   質問
========================================================= */

function renderQuestion(s,g,gIndex,q,qIndex){
    const choices = q.choices || [];

    return `
        <div class="question"
             draggable="true"
             ondragstart="dragQuestionStart(${g.id},${q.id})"
             ondragover="event.preventDefault()"
             ondrop="dropQuestion(event,${g.id},${q.id})">

            <div class="question-head">
                <span class="drag-handle">☷</span>

                <span class="question-number">
                    ${questionNumber(s,gIndex,qIndex)}
                </span>

                <input type="text"
                       value="${escapeHtml(q.text)}"
                       placeholder="質問文"
                       onchange="updateQuestion(${g.id},${q.id},'text',this.value)">

                <button class="btn btn-small btn-danger"
                        onclick="deleteQuestion(${g.id},${q.id})">削除</button>
            </div>

            <div class="question-grid">
                <div>
                    <label>質問文</label>
                    <textarea
                        oninput="updateQuestion(${g.id},${q.id},'text',this.value)">${escapeHtml(q.text)}</textarea>
                </div>

                <div>
                    <label>回答形式</label>
                    <select onchange="changeQuestionType(${g.id},${q.id},this.value)">
                        <option value="single" ${q.type==="single"?"selected":""}>単一選択</option>
                        <option value="multi" ${q.type==="multi"?"selected":""}>複数選択</option>
                        <option value="text" ${q.type==="text"?"selected":""}>1行テキスト</option>
                        <option value="textarea" ${q.type==="textarea"?"selected":""}>複数行テキスト</option>
                    </select>
                </div>

                <div>
                    <label>回答</label>
                    <label class="check-label">
                        <input type="checkbox"
                               ${q.required?"checked":""}
                               onchange="updateQuestion(${g.id},${q.id},'required',this.checked)">
                        必須
                    </label>
                </div>
            </div>

            ${(q.type==="single" || q.type==="multi") ? `
                <div class="choice-list">
                    <strong>選択肢</strong>

                    ${choices.map((c,index)=>`
                        <div class="choice-row">
                            <input type="text"
                                   value="${escapeHtml(c)}"
                                   onchange="updateChoice(${g.id},${q.id},${index},this.value)">
                            <button class="btn btn-small"
                                    onclick="deleteChoice(${g.id},${q.id},${index})">削除</button>
                        </div>
                    `).join("")}

                    <button class="btn btn-small"
                            onclick="addChoice(${g.id},${q.id})">
                        ＋ 選択肢を追加
                    </button>
                </div>
            ` : ""}

            ${q.type==="single" ? `
                <div class="branch-row">
                    <strong>条件分岐</strong>
                    <p style="font-size:13px;color:#64748b">
                        選択肢ごとに次に表示する質問を設定できます。
                    </p>

                    ${choices.map((choice,index)=>`
                        <div class="branch-item">
                            <div>
                                <label>${escapeHtml(choice)}</label>
                                <select onchange="updateBranch(${g.id},${q.id},${index},this.value)">
                                    <option value="">指定なし</option>
                                    ${allQuestionOptions(s,q.id).map(opt=>`
                                        <option value="${opt.id}"
                                            ${String(q.branches?.[choice]||"")===String(opt.id)?"selected":""}>
                                            ${opt.number} ${escapeHtml(opt.text)}
                                        </option>
                                    `).join("")}
                                </select>
                            </div>
                            <div></div>
                        </div>
                    `).join("")}
                </div>
            ` : ""}

            <div class="question-footer">
                <span style="color:#64748b;font-size:13px">
                    ${q.type==="single"?"単一選択":
                      q.type==="multi"?"複数選択":
                      q.type==="text"?"1行テキスト":"複数行テキスト"}
                </span>
            </div>
        </div>
    `;
}

function allQuestionOptions(s,excludeId){
    const result=[];
    let global=0;

    s.groups.forEach((g,gi)=>{
        g.questions.forEach((q,qi)=>{
            global++;

            if(q.id===excludeId) return;

            result.push({
                id:q.id,
                text:q.text || "未入力の質問",
                number:s.numbering==="global"
                    ? "Q"+global
                    : "Q"+(gi+1)+"-"+(qi+1)
            });
        });
    });

    return result;
}

function findQuestion(gid,qid){
    const s = getSurvey(state.editingId);
    const g = s.groups.find(g=>g.id===gid);
    if(!g) return null;
    return g.questions.find(q=>q.id===qid) || null;
}

function addQuestion(gid){
    const s = getSurvey(state.editingId);
    const g = s.groups.find(g=>g.id===gid);

    if(!g) return;

    g.questions.push({
        id:uid(),
        text:"",
        type:"single",
        required:false,
        choices:["選択肢1","選択肢2"],
        branches:{}
    });

    render();
}

function deleteQuestion(gid,qid){
    showModal("質問削除","この質問を削除しますか？",()=>{
        const s = getSurvey(state.editingId);
        const g = s.groups.find(g=>g.id===gid);
        g.questions=g.questions.filter(q=>q.id!==qid);
        render();
        toast("質問を削除しました");
    });
}

function updateQuestion(gid,qid,field,value){
    const q=findQuestion(gid,qid);
    if(q) q[field]=value;
}

function changeQuestionType(gid,qid,type){
    const q=findQuestion(gid,qid);
    if(!q) return;

    q.type=type;

    if(type==="single" || type==="multi"){
        if(!q.choices.length){
            q.choices=["選択肢1","選択肢2"];
        }
    }else{
        q.choices=[];
        q.branches={};
    }

    render();
}

function addChoice(gid,qid){
    const q=findQuestion(gid,qid);
    if(!q) return;

    q.choices.push("選択肢"+(q.choices.length+1));
    render();
}

function deleteChoice(gid,qid,index){
    const q=findQuestion(gid,qid);
    if(!q) return;

    q.choices.splice(index,1);
    render();
}

function updateChoice(gid,qid,index,value){
    const q=findQuestion(gid,qid);
    if(!q) return;

    const old=q.choices[index];
    q.choices[index]=value;

    if(q.branches && q.branches[old]){
        q.branches[value]=q.branches[old];
        delete q.branches[old];
    }
}

function updateBranch(gid,qid,index,value){
    const q=findQuestion(gid,qid);
    if(!q) return;

    const choice=q.choices[index];

    if(value){
        q.branches[choice]=Number(value);
    }else{
        delete q.branches[choice];
    }
}

let draggingQuestion=null;

function dragQuestionStart(gid,qid){
    draggingQuestion={gid,qid};
}

function dropQuestion(event,targetGid,targetQid){
    event.stopPropagation();

    if(!draggingQuestion) return;

    const s=getSurvey(state.editingId);

    const fromG=s.groups.find(g=>g.id===draggingQuestion.gid);
    const toG=s.groups.find(g=>g.id===targetGid);

    if(!fromG || !toG) return;

    const index=fromG.questions.findIndex(q=>q.id===draggingQuestion.qid);

    if(index<0) return;

    const [item]=fromG.questions.splice(index,1);

    const targetIndex=toG.questions.findIndex(q=>q.id===targetQid);

    if(targetIndex<0){
        toG.questions.push(item);
    }else{
        toG.questions.splice(targetIndex,0,item);
    }

    draggingQuestion=null;
    render();
}

function dropQuestionToGroup(event,targetGid){
    if(!draggingQuestion) return;

    const s=getSurvey(state.editingId);

    const fromG=s.groups.find(g=>g.id===draggingQuestion.gid);
    const toG=s.groups.find(g=>g.id===targetGid);

    if(!fromG || !toG) return;

    const index=fromG.questions.findIndex(q=>q.id===draggingQuestion.qid);

    if(index<0) return;

    const [item]=fromG.questions.splice(index,1);
    toG.questions.push(item);

    draggingQuestion=null;
    render();
}


/* =========================================================
   複製 / 削除
========================================================= */

function duplicateSurvey(id){
    const s=getSurvey(id);

    showModal(
        "アンケート複製",
        `「${escapeHtml(s.title)}」を複製しますか？<br><br>
         複製後は下書きアンケートとして一覧に追加されます。`,
        ()=>{
            const copy=JSON.parse(JSON.stringify(s));

            copy.id=uid();
            copy.title=s.title+"（複製）";
            copy.status="下書き";
            copy.answers=0;

            copy.groups.forEach(g=>{
                g.id=uid();
                g.questions.forEach(q=>{
                    q.id=uid();
                    q.branches={};
                });
            });

            state.surveys.push(copy);
            render();
            toast("アンケートを複製しました");
        }
    );
}

function deleteSurvey(id){
    const s=getSurvey(id);

    showModal(
        "アンケート削除",
        `「${escapeHtml(s.title)}」を削除しますか？<br><br>
         この操作はモック上で即時反映されます。`,
        ()=>{
            state.surveys=state.surveys.filter(x=>x.id!==id);
            render();
            toast("アンケートを削除しました");
        }
    );
}


/* =========================================================
   プレビュー
========================================================= */

function previewSurvey(){
    state.previousPage="editor";
    navigate("preview");
}

function renderPreview(){
    const s=getSurvey(state.editingId);

    return `
        <div class="page-title">
            <div>
                <h1>プレビュー</h1>
                <p>実際の送信は行われません。</p>
            </div>

            <div>
                <button class="btn ${!state.previewMobile?'btn-primary':''}"
                        onclick="state.previewMobile=false;render()">
                    PC
                </button>

                <button class="btn ${state.previewMobile?'btn-primary':''}"
                        onclick="state.previewMobile=true;render()">
                    スマートフォン
                </button>

                <button class="btn"
                        onclick="navigate('editor')">
                    編集画面へ戻る
                </button>
            </div>
        </div>

        <div class="notice notice-info">
            これはプレビュー表示のため送信されません。
        </div>

        <div class="preview-frame">
            <div class="preview-device ${state.previewMobile?'mobile':''}">
                <h1>${escapeHtml(s.title || "アンケートタイトル")}</h1>

                <p>${escapeHtml(s.description)}</p>

                ${s.groups.map((g,gi)=>`
                    <section style="margin-top:30px">
                        <h2>${escapeHtml(g.title)}</h2>

                        ${g.questions.map((q,qi)=>renderPreviewQuestion(
                            s,g,q,gi,qi
                        )).join("")}
                    </section>
                `).join("")}

                <div class="center" style="margin-top:25px">
                    <button class="btn btn-primary">回答を確認する</button>
                </div>
            </div>
        </div>
    `;
}

function renderPreviewQuestion(s,g,q,gi,qi){
    return `
        <div class="preview-question">
            <h4>
                ${questionNumber(
                    s,
                    s.groups.indexOf(g),
                    g.questions.indexOf(q)
                )}
                ${q.required?'<span class="required">必須</span>':''}
            </h4>

            <p>${escapeHtml(q.text || "質問文")}</p>

            ${q.type==="single" || q.type==="multi"
                ? `
                    <div class="answer-options">
                        ${q.choices.map(c=>`
                            <label class="answer-option">
                                <input type="${q.type==='single'?'radio':'checkbox'}"
                                       name="preview_${q.id}">
                                ${escapeHtml(c)}
                            </label>
                        `).join("")}
                    </div>
                `
                : q.type==="text"
                ? `<input type="text" placeholder="回答を入力してください">`
                : `<textarea placeholder="回答を入力してください"></textarea>`
            }
        </div>
    `;
}


/* =========================================================
   顧客選択・メール送信
========================================================= */

function openSend(id){
    state.editingId=id;
    navigate("send");
}

function renderSend(){
    const s=getSurvey(state.editingId);

    return `
        <div class="page-title">
            <div>
                <h1>顧客選択・メール送信</h1>
                <p>${escapeHtml(s.title)}</p>
            </div>
            <button class="btn" onclick="navigate('list')">一覧へ戻る</button>
        </div>

        <div class="card">
            <h2>メールテンプレート</h2>

            <div class="form-grid">
                <div class="form-full">
                    <label>メール件名</label>
                    <input id="mailSubject"
                           value="アンケートご協力のお願い">
                </div>

                <div class="form-full">
                    <label>メール本文</label>
                    <textarea id="mailBody"> {顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <input id="customerSearch"
                       class="search-box"
                       placeholder="顧客名・組織名・メールアドレスで検索"
                       oninput="renderCustomerTable()">

                <button class="btn" onclick="selectAllCustomers(true)">すべて選択</button>
                <button class="btn" onclick="selectAllCustomers(false)">すべて解除</button>
                <button class="btn btn-primary" onclick="sendSelected()">選択した顧客へ送信</button>
                <button class="btn btn-warning" onclick="remindCustomers()">未回答者へリマインド</button>
            </div>

            <div id="customerTable"></div>
        </div>
    `;
}

setTimeout(()=>{
    if(state.page==="send") renderCustomerTable();
},0);

function renderCustomerTable(){
    const el=document.getElementById("customerTable");
    if(!el) return;

    const keyword=(document.getElementById("customerSearch")?.value||"").toLowerCase();

    const list=state.customers.filter(c=>
        `${c.name}${c.org}${c.email}`.toLowerCase().includes(keyword)
    );

    el.innerHTML=`
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>選択</th>
                    <th>組織名</th>
                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th>電話番号</th>
                    <th>住所</th>
                    <th>最終送信日時</th>
                    <th>送信回数</th>
                    <th>回答ステータス</th>
                    <th>送信文を確認</th>
                    <th>kintone登録状態</th>
                </tr>
            </thead>
            <tbody>
                ${list.map(c=>`
                    <tr>
                        <td>
                            <input type="checkbox"
                                   class="customer-check"
                                   value="${c.id}">
                        </td>
                        <td>${escapeHtml(c.org)}</td>
                        <td>${escapeHtml(c.name)}</td>
                        <td>${escapeHtml(c.email)}</td>
                        <td>${escapeHtml(c.tel)}</td>
                        <td>${escapeHtml(c.address)}</td>
                        <td>${escapeHtml(c.sent)}</td>
                        <td>${c.count}</td>
                        <td>${escapeHtml(c.status)}</td>
                        <td>
                            <button class="btn btn-small"
                                    onclick="showMailPreview(${c.id})">
                                確認
                            </button>
                        </td>
                        <td>
                            ${c.kintone
                                ? '<span class="status status-public">✓ kintone登録完了</span>'
                                : '<span class="status status-draft">未登録</span>'}
                        </td>
                    </tr>
                `).join("")}
            </tbody>
        </table>
        </div>
    `;
}

function selectAllCustomers(flag){
    document.querySelectorAll(".customer-check")
        .forEach(el=>el.checked=flag);
}

function selectedCustomerIds(){
    return [...document.querySelectorAll(".customer-check:checked")]
        .map(el=>Number(el.value));
}

function sendSelected(){
    const ids=selectedCustomerIds();

    if(!ids.length){
        toast("送信対象を選択してください");
        return;
    }

    const already=ids.filter(id=>{
        const c=state.customers.find(x=>x.id===id);
        return c.count>0;
    });

    if(already.length){
        showModal(
            "再送確認",
            "既に送信済みの宛先が含まれています。再送しますか？",
            ()=>performSend(ids)
        );
    }else{
        showModal(
            "メール一括送信",
            `${ids.length}件の顧客へメールを送信しますか？`,
            ()=>performSend(ids)
        );
    }
}

function performSend(ids){
    const now=new Date();
    const date=now.getFullYear()+"/"+
        String(now.getMonth()+1).padStart(2,"0")+"/"+
        String(now.getDate()).padStart(2,"0")+" "+
        String(now.getHours()).padStart(2,"0")+":"+
        String(now.getMinutes()).padStart(2,"0");

    ids.forEach(id=>{
        const c=state.customers.find(x=>x.id===id);
        if(c){
            c.sent=date;
            c.count++;
            if(c.status!=="回答済み"){
                c.status="送信済み / 未回答";
            }
        }
    });

    state.mailHistory.unshift({
        date,
        type:"一括送信",
        count:ids.length,
        subject:document.getElementById("mailSubject")?.value || "",
        executor:"管理者"
    });

    render();
    toast("メール送信成功（モック）");
}

function remindCustomers(){
    const ids=state.customers
        .filter(c=>c.status==="送信済み / 未回答")
        .map(c=>c.id);

    if(!ids.length){
        toast("未回答者はいません");
        return;
    }

    showModal(
        "リマインド",
        `${ids.length}名の未回答者へリマインドを送信しますか？`,
        ()=>performSend(ids)
    );
}

function showMailPreview(id){
    const c=state.customers.find(x=>x.id===id);

    showModal(
        "送信文を確認",
        `<strong>${escapeHtml(c.name)} 様</strong><br><br>
         アンケートへのご協力をお願いいたします。<br><br>
         https://example.com/survey/${state.editingId}?token=MOCK-${c.id}`,
        null,
        null,
        "閉じる"
    );
}


/* =========================================================
   送信履歴
========================================================= */

function renderHistory(){
    return `
        <div class="page-title">
            <div>
                <h1>送信履歴</h1>
                <p>アンケートメールの送信履歴を確認できます。</p>
            </div>
        </div>

        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>送信日時</th>
                            <th>送信種別</th>
                            <th>送信件数</th>
                            <th>送信件名</th>
                            <th>送信実行者</th>
                            <th>詳細</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${state.mailHistory.map((h,i)=>`
                            <tr>
                                <td>${h.date}</td>
                                <td>${h.type}</td>
                                <td>${h.count}</td>
                                <td>${escapeHtml(h.subject)}</td>
                                <td>${h.executor}</td>
                                <td>
                                    <button class="btn btn-small"
                                        onclick="showModal(
                                            '送信内容',
                                            '件名：${escapeHtml(h.subject)}<br><br>顧客名差し込み後のメール本文を確認できます。<br><br>個別アンケートURL：モックURL',
                                            null,
                                            null,
                                            '閉じる'
                                        )">
                                        確認
                                    </button>
                                </td>
                            </tr>
                        `).join("")}
                    </tbody>
                </table>
            </div>
        </div>
    `;
}


/* =========================================================
   集計・分析
========================================================= */

function openAnalysis(id){
    state.editingId=id;
    navigate("analysis");
}

function renderAnalysis(){
    const s=getSurvey(state.editingId);

    const total=180;
    const answers=s.answers;
    const unanswered=Math.max(total-answers,0);
    const rate=Math.round((answers/total)*100);

    return `
        <div class="page-title">
            <div>
                <h1>回答集計・分析</h1>
                <p>${escapeHtml(s.title)}</p>
            </div>

            <div>
                <button class="btn" onclick="mockExport('CSV')">CSVダウンロード</button>
                <button class="btn" onclick="mockExport('PDF')">PDF出力</button>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="label">送信対象者数</div>
                <div class="value">${total}</div>
            </div>

            <div class="summary-card">
                <div class="label">回答数</div>
                <div class="value">${answers}</div>
            </div>

            <div class="summary-card">
                <div class="label">未登録顧客からの回答数</div>
                <div class="value">4</div>
            </div>

            <div class="summary-card">
                <div class="label">未回答数</div>
                <div class="value">${unanswered}</div>
            </div>

            <div class="summary-card">
                <div class="label">回答率</div>
                <div class="value">${rate}%</div>
            </div>
        </div>

        <div class="card" style="margin-top:20px">
            <h2>設問フィルター</h2>

            <label class="check-label">
                <input type="checkbox" checked>
                すべて選択
            </label>

            <label class="check-label">
                <input type="checkbox">
                すべて解除
            </label>

            ${s.groups.flatMap(g=>g.questions).map((q,i)=>`
                <label class="check-label">
                    <input type="checkbox" checked>
                    ${escapeHtml(q.text || "未入力の質問")}
                </label>
            `).join("")}
        </div>

        ${renderAnalysisQuestions(s)}

        <div class="card">
            <h2>個別回答</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>組織名</th>
                            <th>氏名</th>
                            <th>回答日時</th>
                            <th>回答概要</th>
                            <th>詳細</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>株式会社サンプル</td>
                            <td>山田 太郎</td>
                            <td>2026/08/20 10:20</td>
                            <td>満足 / サポート</td>
                            <td><button class="btn btn-small">全回答を表示</button></td>
                        </tr>
                        <tr>
                            <td>未登録企業</td>
                            <td>田中 次郎</td>
                            <td>2026/08/22 09:20</td>
                            <td>非常に満足 / 機能</td>
                            <td><button class="btn btn-small">全回答を表示</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

function renderAnalysisQuestions(s){
    const questions=s.groups.flatMap(g=>g.questions);

    if(!questions.length){
        return `
            <div class="card">
                <div class="empty">現在、回答データはありません</div>
            </div>
        `;
    }

    return questions.map(q=>{
        if(q.type==="single" || q.type==="multi"){
            const counts=q.choices.map((c,i)=>({
                choice:c,
                count:[65,52,31,18,12][i%5] || 3
            }));

            const max=Math.max(...counts.map(x=>x.count));

            return `
                <div class="card">
                    <h2>${escapeHtml(q.text || "質問")}</h2>

                    ${counts.map(x=>`
                        <div class="bar-row">
                            <div>${escapeHtml(x.choice)}</div>
                            <div class="bar">
                                <span style="width:${(x.count/max)*100}%"></span>
                            </div>
                            <div>${x.count}件</div>
                        </div>
                    `).join("")}
                </div>
            `;
        }

        return `
            <div class="card">
                <h2>${escapeHtml(q.text || "質問")}</h2>
                <div class="notice notice-info">
                    自由記述回答一覧
                </div>
                <ul>
                    <li>サポートが丁寧でした。</li>
                    <li>操作性をさらに改善してほしいです。</li>
                    <li>料金プランを増やしてほしいです。</li>
                </ul>
            </div>
        `;
    }).join("");
}

function mockExport(type){
    toast(`${type}出力を実行しました（モック）`);
}


/* =========================================================
   kintone設定
========================================================= */

function renderKintone(){
    const k=state.settings.kintone;

    return `
        <div class="page-title">
            <div>
                <h1>kintone連携設定</h1>
                <p>顧客情報との連携設定を行います。</p>
            </div>
        </div>

        <div class="card">
            <h2>接続設定</h2>

            <div class="form-grid">
                <div>
                    <label>サブドメイン</label>
                    <input value="${escapeHtml(k.subdomain)}"
                           onchange="kintoneField('subdomain',this.value)">
                </div>

                <div>
                    <label>顧客管理アプリID</label>
                    <input value="${escapeHtml(k.appId)}"
                           onchange="kintoneField('appId',this.value)">
                </div>

                <div>
                    <label>ログイン名</label>
                    <input value="${escapeHtml(k.login)}"
                           onchange="kintoneField('login',this.value)">
                </div>

                <div>
                    <label>パスワード</label>
                    <input type="password"
                           value="${escapeHtml(k.password)}"
                           onchange="kintoneField('password',this.value)">
                </div>

                <div class="form-full">
                    <label class="check-label">
                        <input type="checkbox"
                               ${k.ssl?"checked":""}
                               onchange="kintoneField('ssl',this.checked)">
                        SSL証明書を検証する
                    </label>
                </div>
            </div>

            <div style="margin-top:20px">
                <span class="status ${k.connected?'status-public':'status-end'}">
                    ${k.connected?'接続確認済み':'接続できません'}
                </span>
            </div>

            <div class="toolbar" style="margin-top:18px">
                <button class="btn btn-primary"
                        onclick="testKintone()">
                    接続確認
                </button>

                <button class="btn"
                        onclick="getKintoneFields()">
                    項目一覧を再取得
                </button>

                <button class="btn"
                        onclick="syncCustomers()">
                    顧客情報を同期
                </button>
            </div>
        </div>

        <div class="card">
            <h2>フィールドマッピング</h2>

            ${renderMapping("組織名",["会社名","組織名","企業名"])}
            ${renderMapping("氏名",["氏名","担当者名","名前"])}
            ${renderMapping("メールアドレス",["メールアドレス","Email","メール"])}
            ${renderMapping("部署名",["部署名","所属"])}
            ${renderMapping("電話番号",["電話番号","TEL","携帯電話"])}
        </div>

        <div class="card">
            <h2>住所マッピング</h2>

            <p style="color:#64748b">
                複数のフィールドを組み合わせて住所として扱います。
            </p>

            ${["都道府県","市区町村","番地","建物名","郵便番号"].map((x,i)=>`
                <label class="check-label">
                    <input type="checkbox" ${i<3?"checked":""}>
                    ${x}
                </label>
            `).join("")}
        </div>

        <div id="kintoneFields"></div>
    `;
}

function renderMapping(label,options){
    return `
        <div style="display:grid;grid-template-columns:180px 1fr;gap:15px;align-items:center;margin-bottom:12px">
            <strong>${label}</strong>
            <select>
                ${options.map(x=>`<option>${x}</option>`).join("")}
            </select>
        </div>
    `;
}

function kintoneField(field,value){
    state.settings.kintone[field]=value;
}

function testKintone(){
    state.settings.kintone.connected=true;
    render();
    toast("kintone接続確認済み（モック）");
}

function getKintoneFields(){
    const el=document.getElementById("kintoneFields");

    if(!el) return;

    el.innerHTML=`
        <div class="card">
            <h2>kintone項目一覧</h2>
            <div class="notice notice-success">
                項目一覧を取得しました（モック）
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>フィールドコード</th>
                            <th>日本語ラベル</th>
                            <th>種類</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>company</td><td>会社名</td><td>文字列</td></tr>
                        <tr><td>name</td><td>氏名</td><td>文字列</td></tr>
                        <tr><td>email</td><td>メールアドレス</td><td>文字列</td></tr>
                        <tr><td>department</td><td>部署名</td><td>文字列</td></tr>
                        <tr><td>tel</td><td>電話番号</td><td>文字列</td></tr>
                        <tr><td>pref</td><td>都道府県</td><td>文字列</td></tr>
                        <tr><td>city</td><td>市区町村</td><td>文字列</td></tr>
                        <tr><td>address</td><td>番地</td><td>文字列</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    `;

    toast("項目一覧を再取得しました");
}

function syncCustomers(){
    toast("顧客情報を同期しました（モック）");
}


/* =========================================================
   メールサーバ設定
========================================================= */

function renderSMTP(){
    const s=state.settings.smtp;

    return `
        <div class="page-title">
            <div>
                <h1>メールサーバ設定</h1>
                <p>アンケートメール送信用SMTP設定です。</p>
            </div>
        </div>

        <div class="card">
            <h2>SMTP設定</h2>

            <div class="form-grid">
                <div>
                    <label>SMTPサーバ</label>
                    <input value="${escapeHtml(s.server)}"
                           onchange="smtpField('server',this.value)">
                </div>

                <div>
                    <label>SMTPポート</label>
                    <input value="${escapeHtml(s.port)}"
                           onchange="smtpField('port',this.value)">
                </div>

                <div>
                    <label>暗号化方式</label>
                    <select onchange="smtpField('encryption',this.value)">
                        <option ${s.encryption==="SSL"?"selected":""}>SSL</option>
                        <option ${s.encryption==="TLS"?"selected":""}>TLS</option>
                        <option ${s.encryption==="なし"?"selected":""}>なし</option>
                    </select>
                </div>

                <div>
                    <label class="check-label">
                        <input type="checkbox"
                               ${s.auth?"checked":""}
                               onchange="smtpField('auth',this.checked)">
                        SMTP認証
                    </label>
                </div>

                <div>
                    <label>SMTPユーザー名</label>
                    <input value="${escapeHtml(s.username)}"
                           onchange="smtpField('username',this.value)">
                </div>

                <div>
                    <label>SMTPパスワード</label>
                    <input type="password"
                           value="${escapeHtml(s.password)}"
                           onchange="smtpField('password',this.value)">
                </div>

                <div>
                    <label>送信元メールアドレス</label>
                    <input type="email"
                           value="${escapeHtml(s.from)}"
                           onchange="smtpField('from',this.value)">
                </div>

                <div>
                    <label>送信元名</label>
                    <input value="${escapeHtml(s.fromName)}"
                           onchange="smtpField('fromName',this.value)">
                </div>

                <div>
                    <label>返信先メールアドレス</label>
                    <input type="email"
                           value="${escapeHtml(s.reply)}"
                           onchange="smtpField('reply',this.value)">
                </div>
            </div>

            <div style="margin-top:20px">
                <span class="status status-public">
                    ${s.status}
                </span>
            </div>

            <div class="toolbar" style="margin-top:18px">
                <button class="btn btn-primary"
                        onclick="testSMTP()">
                    接続確認
                </button>

                <button class="btn"
                        onclick="testMail()">
                    テストメール送信
                </button>
            </div>
        </div>
    `;
}

function smtpField(field,value){
    state.settings.smtp[field]=value;
}

function testSMTP(){
    state.settings.smtp.status="接続確認済み";
    render();
    toast("メールサーバ接続確認済み（モック）");
}

function testMail(){
    showModal(
        "テストメール送信",
        "テストメールを送信しますか？<br><br>モックのため実際のメールは送信されません。",
        ()=>{
            toast("テストメール送信成功（モック）");
        }
    );
}


/* =========================================================
   回答者画面
   ※ここには管理者ヘッダーを絶対に表示しない
========================================================= */

function startAnswer(id){
    state.editingId=id;
    state.answerStep=1;
    state.answerData={};
    state.page="answer";
    render();
}

function renderAnswerLayout(){
    switch(state.page){
        case "answer":
            return renderAnswer();
        case "answer-confirm":
            return renderAnswerConfirm();
        case "answer-complete":
            return renderAnswerComplete();
        default:
            return renderAnswer();
    }
}

function renderAnswer(){
    const s=getSurvey(state.editingId);

    const questions=s.groups.flatMap((g,gi)=>
        g.questions.map((q,qi)=>({
            ...q,
            groupIndex:gi,
            questionIndex:qi,
            number:questionNumber(s,gi,qi)
        }))
    );

    const visible=questions.filter(q=>isQuestionVisible(s,q));

    return `
        <div class="answer-header">
            <div class="answer-header-inner">
                <h1>${escapeHtml(s.title || "アンケート")}</h1>
                <p>${escapeHtml(s.description)}</p>
            </div>
        </div>

        <main class="answer-main">
            <div class="notice notice-info">
                このアンケートは回答者専用画面です。
            </div>

            ${s.groups.map((g,gi)=>`
                <section>
                    <div class="answer-card">
                        <h2>${escapeHtml(g.title)}</h2>
                    </div>

                    ${g.questions.map((q,qi)=>{
                        if(!isQuestionVisible(s,q)) return "";

                        return renderAnswerQuestion(s,q,gi,qi);
                    }).join("")}
                </section>
            `).join("")}

            <div class="answer-footer">
                <button class="btn"
                        onclick="answerBack()">
                    戻る
                </button>

                <button class="btn btn-primary"
                        onclick="goAnswerConfirm()">
                    回答確認
                </button>
            </div>
        </main>
    `;
}

function renderAnswerQuestion(s,q,gi,qi){
    const value=state.answerData[q.id];

    return `
        <div class="answer-card" id="answer-${q.id}">
            <h3>
                ${questionNumber(s,gi,qi)}
                ${q.required?'<span class="required">必須</span>':''}
            </h3>

            <p>${escapeHtml(q.text || "質問文")}</p>

            ${q.type==="single" ? `
                ${q.choices.map(c=>`
                    <label class="answer-choice">
                        <input type="radio"
                               name="answer_${q.id}"
                               value="${escapeHtml(c)}"
                               ${value===c?"checked":""}
                               onchange="setAnswer(${q.id},this.value)">
                        <span>${escapeHtml(c)}</span>
                    </label>
                `).join("")}
            ` : ""}

            ${q.type==="multi" ? `
                ${q.choices.map(c=>`
                    <label class="answer-choice">
                        <input type="checkbox"
                               value="${escapeHtml(c)}"
                               ${(Array.isArray(value)&&value.includes(c))?"checked":""}
                               onchange="toggleMultiAnswer(${q.id},this.value,this.checked)">
                        <span>${escapeHtml(c)}</span>
                    </label>
                `).join("")}
            ` : ""}

            ${q.type==="text" ? `
                <input type="text"
                       value="${escapeHtml(value||"")}"
                       oninput="setAnswer(${q.id},this.value)">
            ` : ""}

            ${q.type==="textarea" ? `
                <textarea
                    oninput="setAnswer(${q.id},this.value)">${escapeHtml(value||"")}</textarea>
            ` : ""}
        </div>
    `;
}

function setAnswer(id,value){
    state.answerData[id]=value;
}

function toggleMultiAnswer(id,value,checked){
    if(!Array.isArray(state.answerData[id])){
        state.answerData[id]=[];
    }

    if(checked){
        if(!state.answerData[id].includes(value)){
            state.answerData[id].push(value);
        }
    }else{
        state.answerData[id]=state.answerData[id]
            .filter(x=>x!==value);
    }
}

function isQuestionVisible(s,q){
    for(const g of s.groups){
        for(const parent of g.questions){
            if(!parent.branches) continue;

            for(const choice in parent.branches){
                if(Number(parent.branches[choice])===q.id){
                    const answer=state.answerData[parent.id];

                    if(Array.isArray(answer)){
                        if(answer.includes(choice)) return true;
                    }else{
                        if(answer===choice) return true;
                    }

                    return false;
                }
            }
        }
    }

    return true;
}

function answerBack(){
    window.scrollTo(0,0);
}

function goAnswerConfirm(){
    const s=getSurvey(state.editingId);

    const requiredQuestions=s.groups.flatMap(g=>g.questions)
        .filter(q=>q.required && isQuestionVisible(s,q));

    const missing=requiredQuestions.filter(q=>{
        const v=state.answerData[q.id];

        if(Array.isArray(v)) return v.length===0;
        return v===undefined || v===null || String(v).trim()==="";
    });

    if(missing.length){
        showModal(
            "未回答項目があります",
            `${missing.length}件の必須項目が未回答です。<br>
             回答内容を確認してください。`,
            null,
            null,
            "閉じる"
        );

        return;
    }

    state.page="answer-confirm";
    render();
    window.scrollTo(0,0);
}


/* =========================================================
   回答確認
========================================================= */

function renderAnswerConfirm(){
    const s=getSurvey(state.editingId);

    return `
        <div class="answer-header">
            <div class="answer-header-inner">
                <h1>回答確認</h1>
                <p>${escapeHtml(s.title)}</p>
            </div>
        </div>

        <main class="answer-main">
            <div class="notice notice-info">
                回答内容をご確認ください。
            </div>

            <div class="answer-card">
                ${s.groups.flatMap((g,gi)=>
                    g.questions.map((q,qi)=>`
                        <div class="confirm-answer">
                            <strong>
                                ${questionNumber(s,gi,qi)}
                                ${escapeHtml(q.text)}
                            </strong>

                            <div style="margin-top:8px">
                                ${formatAnswer(state.answerData[q.id])}
                            </div>
                        </div>
                    `)
                ).join("")}
            </div>

            <div class="answer-footer">
                <button class="btn"
                        onclick="state.page='answer';render()">
                    修正する
                </button>

                <button class="btn btn-primary"
                        onclick="confirmAnswerSend()">
                    回答を送信する
                </button>
            </div>
        </main>
    `;
}

function formatAnswer(value){
    if(value===undefined || value===null || value===""){
        return "未回答";
    }

    if(Array.isArray(value)){
        return escapeHtml(value.join("、"));
    }

    return escapeHtml(value).replace(/\n/g,"<br>");
}

function confirmAnswerSend(){
    showModal(
        "回答送信",
        "回答を送信しますか？<br><br>送信後は回答完了画面へ進みます。",
        ()=>{
            const s=getSurvey(state.editingId);
            s.answers++;
            state.page="answer-complete";
            render();
            window.scrollTo(0,0);
        }
    );
}


/* =========================================================
   回答完了
========================================================= */

function renderAnswerComplete(){
    return `
        <div class="answer-header">
            <div class="answer-header-inner">
                <h1>回答完了</h1>
            </div>
        </div>

        <main class="answer-main">
            <div class="answer-card center" style="padding:60px 25px">
                <div style="font-size:54px;color:#16a34a">✓</div>

                <h2>回答ありがとうございました</h2>

                <p>
                    アンケートへのご回答を受け付けました。
                </p>
            </div>
        </main>
    `;
}


/* =========================================================
   モーダル
========================================================= */

function showModal(title,body,onConfirm,onCancel,confirmText="実行"){
    const root=document.getElementById("modalRoot");

    root.innerHTML=`
        <div class="modal-overlay" onclick="modalBackground(event)">
            <div class="modal">
                <div class="modal-header">
                    ${title}
                </div>

                <div class="modal-body">
                    ${body}
                </div>

                <div class="modal-footer">
                    <button class="btn"
                            onclick="closeModal();${onCancel?'window.__modalCancel()':''}">
                        ${onConfirm?"キャンセル":"閉じる"}
                    </button>

                    ${onConfirm?`
                        <button class="btn btn-primary"
                                onclick="closeModal();window.__modalConfirm()">
                            ${confirmText}
                        </button>
                    `:""}
                </div>
            </div>
        </div>
    `;

    window.__modalConfirm=onConfirm || function(){};
    window.__modalCancel=onCancel || function(){};
}

function closeModal(){
    document.getElementById("modalRoot").innerHTML="";
}

function modalBackground(event){
    if(event.target.classList.contains("modal-overlay")){
        closeModal();
    }
}


/* =========================================================
   開発確認用
========================================================= */

/*
 * 回答者画面へ入るための簡易入口。
 * 本番では個別URL / 公開URLから回答画面を開く想定。
 *
 * 管理者画面から回答者画面へ直接戻る導線ではありません。
 * モック確認用としてコンソールから：
 *
 * startAnswer(1)
 *
 * を実行できます。
 */

window.mock={
    startAnswer,
    surveys:state.surveys,
    customers:state.customers
};


/* 初期表示 */
render();

</script>

</body>
</html>
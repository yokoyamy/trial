<?php
declare(strict_types=1);

/*
 * アンケート管理システム UI モック
 * Apache + PHP / 1ファイル構成
 *
 * 実サービスには接続しません。
 * kintone / SMTP / 顧客 / 回答データはブラウザ上のモックデータです。
 */
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム - UI Mock</title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --bg:#f5f7fb;
    --surface:#fff;
    --border:#dfe3eb;
    --text:#172033;
    --muted:#667085;
    --danger:#dc2626;
    --warning:#d97706;
    --success:#15803d;
    --purple:#7c3aed;
    --shadow:0 2px 10px rgba(16,24,40,.06);
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:var(--text);background:var(--bg)}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
a{color:var(--primary)}
.app{min-height:100vh}
.header{
    height:64px;background:#fff;border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;padding:0 24px;
    position:sticky;top:0;z-index:20;
}
.logo{font-weight:800;font-size:18px}
.logo small{font-weight:500;color:var(--muted);margin-left:8px}
.header-right{display:flex;align-items:center;gap:12px;font-size:13px;color:var(--muted)}
.layout{display:flex;min-height:calc(100vh - 64px)}
.sidebar{
    width:230px;background:#172033;color:#fff;padding:18px 12px;flex:none;
}
.nav-title{font-size:11px;color:#98a2b3;margin:12px 10px 7px}
.nav-btn{
    width:100%;border:0;background:transparent;color:#dbe3f0;text-align:left;
    padding:11px 12px;border-radius:8px;margin-bottom:3px;
}
.nav-btn:hover,.nav-btn.active{background:#273653;color:#fff}
.main{flex:1;padding:28px;min-width:0}
.container{max-width:1400px;margin:0 auto}
.page-head{
    display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:22px;
}
.page-head h1{margin:0 0 6px;font-size:26px}
.page-head p{margin:0;color:var(--muted)}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{
    border:1px solid var(--border);background:#fff;color:var(--text);
    border-radius:7px;padding:9px 14px;min-height:40px;
}
.btn:hover{background:#f8fafc}
.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn.primary:hover{background:var(--primary-dark)}
.btn.danger{background:var(--danger);border-color:var(--danger);color:#fff}
.btn.warning{background:#fff7ed;border-color:#fdba74;color:#9a3412}
.btn.success{background:#15803d;border-color:#15803d;color:#fff}
.btn.small{padding:6px 10px;min-height:32px;font-size:13px}
.btn:disabled{opacity:.45;cursor:not-allowed}
.grid{display:grid;gap:16px}
.grid-5{grid-template-columns:repeat(5,minmax(0,1fr))}
.grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}
.grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
.grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
.card{
    background:var(--surface);border:1px solid var(--border);border-radius:10px;
    box-shadow:var(--shadow);padding:18px;
}
.card h2,.card h3{margin:0 0 14px}
.stat{cursor:pointer}
.stat .label{font-size:13px;color:var(--muted)}
.stat .num{font-size:30px;font-weight:800;margin-top:6px}
.stat:hover{border-color:#a9c1f7}
.section{margin-top:22px}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{padding:12px 13px;border-bottom:1px solid var(--border);text-align:left;vertical-align:middle}
th{background:#f8fafc;font-size:12px;color:#475467;white-space:nowrap}
td{font-size:14px}
tr:last-child td{border-bottom:0}
.badge{
    display:inline-flex;align-items:center;gap:4px;border-radius:999px;
    padding:4px 9px;font-size:12px;font-weight:700;white-space:nowrap;
}
.badge.draft{background:#eef2f7;color:#475467}
.badge.wait{background:#fff7ed;color:#9a3412}
.badge.open{background:#ecfdf3;color:#166534}
.badge.closed{background:#eff6ff;color:#1d4ed8}
.badge.archived{background:#f3e8ff;color:#6b21a8}
.notice{
    border:1px solid #bfdbfe;background:#eff6ff;color:#1e40af;
    padding:12px 14px;border-radius:8px;margin-bottom:16px;
}
.notice.warning{border-color:#fed7aa;background:#fff7ed;color:#9a3412}
.notice.success{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.notice.danger{border-color:#fecaca;background:#fef2f2;color:#991b1b}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{margin-bottom:14px}
.form-group.full{grid-column:1/-1}
label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
.required{color:var(--danger)}
input,textarea,select{
    width:100%;border:1px solid #cbd5e1;border-radius:7px;padding:9px 10px;
    background:#fff;color:var(--text);
}
textarea{min-height:100px;resize:vertical}
input:focus,textarea:focus,select:focus{outline:2px solid #bfdbfe;border-color:var(--primary)}
.error-text{color:var(--danger);font-size:12px;margin-top:5px}
.checkbox{display:flex;align-items:center;gap:7px}
.checkbox input{width:auto}
.question{
    border:1px solid var(--border);border-radius:10px;background:#fff;
    padding:16px;margin-bottom:12px;
}
.question-head{display:flex;justify-content:space-between;gap:15px;align-items:flex-start}
.question-title{font-weight:700}
.question-meta{font-size:12px;color:var(--muted);margin-top:5px}
.option-row{display:flex;gap:8px;margin:7px 0}
.option-row input{flex:1}
.flex{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.space-between{display:flex;justify-content:space-between;align-items:center;gap:12px}
.muted{color:var(--muted)}
.kpi{font-size:25px;font-weight:800}
.progress{height:9px;background:#e5e7eb;border-radius:999px;overflow:hidden}
.progress > span{display:block;height:100%;background:var(--primary)}
.timeline{display:flex;gap:0;margin:20px 0}
.timeline .step{flex:1;text-align:center;position:relative}
.timeline .circle{
    width:30px;height:30px;border-radius:50%;background:#dbe2ea;color:#475467;
    display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;
}
.timeline .step.done .circle,.timeline .step.current .circle{background:var(--primary);color:#fff}
.timeline .caption{font-size:11px;margin-top:5px;color:var(--muted)}
.tabs{display:flex;gap:3px;border-bottom:1px solid var(--border);margin-bottom:18px}
.tab{border:0;background:transparent;padding:10px 15px;color:var(--muted);border-bottom:2px solid transparent}
.tab.active{color:var(--primary);border-bottom-color:var(--primary);font-weight:700}
.empty{text-align:center;padding:40px;color:var(--muted)}
.modal-backdrop{
    position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;
    align-items:center;justify-content:center;z-index:100;padding:20px;
}
.modal-backdrop.show{display:flex}
.modal{
    background:#fff;border-radius:12px;max-width:600px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.2);
}
.modal-head{padding:18px 20px;border-bottom:1px solid var(--border);font-weight:800}
.modal-body{padding:20px}
.modal-foot{padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
.toast{
    position:fixed;right:22px;bottom:22px;background:#172033;color:#fff;
    padding:12px 16px;border-radius:8px;box-shadow:var(--shadow);display:none;z-index:200;
}
.toast.show{display:block}
.respond-wrap{max-width:900px;margin:30px auto}
.respond-header{background:#172033;color:#fff;padding:20px;border-radius:12px 12px 0 0}
.respond-body{background:#fff;padding:25px;border:1px solid var(--border);border-top:0}
.answer-option{margin:9px 0}
.answer-option label{font-weight:400;display:flex;gap:8px;align-items:center}
.answer-option input{width:auto}
.review-item{padding:12px 0;border-bottom:1px solid var(--border)}
.review-item:last-child{border-bottom:0}
pre.debug{
    background:#111827;color:#d1d5db;padding:14px;border-radius:8px;
    overflow:auto;font-size:12px;
}
@media(max-width:1100px){
    .grid-5{grid-template-columns:repeat(3,1fr)}
    .sidebar{width:190px}
}
@media(max-width:760px){
    .sidebar{display:none}
    .main{padding:15px}
    .grid-5,.grid-4,.grid-3,.grid-2,.form-grid{grid-template-columns:1fr}
    .page-head{display:block}
    .page-head .actions{margin-top:12px}
    .header{padding:0 14px}
}
</style>
</head>
<body>
<div class="app">
<header class="header">
    <div class="logo">アンケート管理 <small>UI Mock</small></div>
    <div class="header-right">
        <span>モック環境</span>
        <button class="btn small" data-action="reset">データ初期化</button>
    </div>
</header>

<div class="layout">
<aside class="sidebar">
    <div class="nav-title">管理</div>
    <button class="nav-btn" data-nav="home">ホーム</button>
    <button class="nav-btn" data-nav="list">アンケート一覧</button>
    <button class="nav-btn" data-nav="new">新規作成</button>

    <div class="nav-title">設定</div>
    <button class="nav-btn" data-nav="settings-kintone">kintone設定</button>
    <button class="nav-btn" data-nav="settings-smtp">SMTP設定</button>

    <div class="nav-title">回答者向け</div>
    <button class="nav-btn" data-nav="respond">回答者画面</button>
</aside>

<main class="main">
<div class="container" id="app"></div>
</main>
</div>
</div>

<div class="modal-backdrop" id="modal">
    <div class="modal">
        <div class="modal-head" id="modalTitle"></div>
        <div class="modal-body" id="modalBody"></div>
        <div class="modal-foot" id="modalFoot"></div>
    </div>
</div>
<div class="toast" id="toast"></div>

<script>
(function () {
'use strict';

/* =========================
 * Mock Data
 * ========================= */
const STORAGE_KEY = 'survey_mock_v3';

const DEFAULT_STATE = {
    currentView: 'home',
    currentSurveyId: null,
    currentResponseId: null,
    respondStep: 'input',
    selectedCustomerIds: [],
    sendSurveyId: null,
    sendSubject: 'アンケートご協力のお願い',
    sendBody: 'いつもお世話になっております。\\n以下のアンケートへのご協力をお願いいたします。',
    kintone: {
        host: 'https://example.cybozu.com',
        app: '12',
        nameField: '顧客名',
        personField: '担当者名',
        emailField: 'メールアドレス',
        connected: true,
        updatedAt: '2026-09-16 09:30'
    },
    smtp: {
        host: 'smtp.example.jp',
        port: '587',
        from: 'survey@example.jp',
        encryption: 'STARTTLS',
        connected: true
    },
    customers: [
        {id:'c1',name:'株式会社青山商事',person:'山田 太郎',email:'yamada@example.jp'},
        {id:'c2',name:'株式会社みなと',person:'佐藤 花子',email:'sato@example.jp'},
        {id:'c3',name:'東京システム株式会社',person:'鈴木 一郎',email:'suzuki@example.jp'},
        {id:'c4',name:'サンプル製作所',person:'田中 次郎',email:'tanaka@example.jp'},
        {id:'c5',name:'中央サービス株式会社',person:'高橋 美咲',email:'takahashi@example.jp'}
    ],
    surveys: [
        {
            id:'s1',
            name:'2026年度 サービス満足度アンケート',
            description:'サービスの利用状況と満足度についてお聞きします。',
            guide:'所要時間は約5分です。',
            completeMessage:'ご回答ありがとうございました。',
            start:'2026-09-01T09:00',
            end:'2026-09-30T18:00',
            status:'open',
            created:'2026-08-20',
            updated:'2026-09-16',
            numbering:'global',
            sent:5,
            target:5,
            answers:3,
            groups:[
                {id:'g1',name:'基本情報'},
                {id:'g2',name:'満足度'}
            ],
            questions:[
                {id:'q1',groupId:'g1',text:'サービスを利用したことがありますか？',type:'single',required:true,help:'',options:[
                    {id:'o1',text:'はい',next:{type:'question',id:'q2'}},
                    {id:'o2',text:'いいえ',next:{type:'question',id:'q3'}}
                ]},
                {id:'q2',groupId:'g2',text:'サービスの満足度を教えてください。',type:'rating',required:true,help:'1が低く、5が高い評価です。',options:[]},
                {id:'q3',groupId:'g2',text:'今後利用したいと思いますか？',type:'single',required:true,help:'',options:[
                    {id:'o3',text:'はい',next:{type:'end'}},
                    {id:'o4',text:'いいえ',next:{type:'end'}}
                ]}
            ],
            responses:[
                {id:'r1',date:'2026-09-10 10:20',customer:'山田 太郎',answers:{q1:'はい',q2:'5'}},
                {id:'r2',date:'2026-09-11 14:05',customer:'佐藤 花子',answers:{q1:'はい',q2:'4'}},
                {id:'r3',date:'2026-09-12 09:15',customer:'鈴木 一郎',answers:{q1:'いいえ',q3:'はい'}}
            ],
            sends:[
                {date:'2026-09-01 09:00',target:5,success:5,failed:0}
            ]
        },
        {
            id:'s2',
            name:'新機能ご利用意向調査',
            description:'新機能に関するご意見をお聞かせください。',
            guide:'率直なご意見をお聞かせください。',
            completeMessage:'ご回答ありがとうございました。',
            start:'2026-10-01T09:00',
            end:'2026-10-31T18:00',
            status:'wait',
            created:'2026-09-01',
            updated:'2026-09-15',
            numbering:'group',
            sent:0,
            target:5,
            answers:0,
            groups:[{id:'g3',name:'新機能'}],
            questions:[
                {id:'q4',groupId:'g3',text:'新機能に興味がありますか？',type:'single',required:true,help:'',options:[
                    {id:'o5',text:'興味がある',next:{type:'end'}},
                    {id:'o6',text:'興味はない',next:{type:'end'}}
                ]}
            ],
            responses:[],
            sends:[]
        },
        {
            id:'s3',
            name:'営業対応改善アンケート',
            description:'営業担当者の対応についてお聞きします。',
            guide:'',
            completeMessage:'ご協力ありがとうございました。',
            start:'',
            end:'',
            status:'draft',
            created:'2026-09-14',
            updated:'2026-09-14',
            numbering:'global',
            sent:0,
            target:null,
            answers:0,
            groups:[{id:'g4',name:'評価'}],
            questions:[
                {id:'q5',groupId:'g4',text:'営業担当者の対応はいかがでしたか？',type:'single',required:true,help:'',options:[
                    {id:'o7',text:'良かった',next:null},
                    {id:'o8',text:'普通',next:null},
                    {id:'o9',text:'改善してほしい',next:null}
                ]}
            ],
            responses:[],
            sends:[]
        },
        {
            id:'s4',
            name:'2025年度 利用者アンケート',
            description:'過年度アンケートです。',
            guide:'',
            completeMessage:'ありがとうございました。',
            start:'2025-04-01T09:00',
            end:'2025-04-30T18:00',
            status:'closed',
            created:'2025-03-01',
            updated:'2025-05-01',
            numbering:'global',
            sent:100,
            target:100,
            answers:42,
            groups:[{id:'g5',name:'回答'}],
            questions:[
                {id:'q6',groupId:'g5',text:'満足度を教えてください。',type:'rating',required:true,help:'',options:[]}
            ],
            responses:[],
            sends:[{date:'2025-04-01 09:00',target:100,success:100,failed:0}]
        },
        {
            id:'s5',
            name:'2024年度 社内調査',
            description:'保管済みアンケートです。',
            guide:'',
            completeMessage:'ありがとうございました。',
            start:'2024-04-01T09:00',
            end:'2024-04-30T18:00',
            status:'archived',
            created:'2024-03-01',
            updated:'2024-05-01',
            numbering:'global',
            sent:50,
            target:50,
            answers:45,
            groups:[{id:'g6',name:'回答'}],
            questions:[],
            responses:[],
            sends:[]
        }
    ]
};

let state = loadState();

/* =========================
 * Utilities
 * ========================= */
function deepClone(obj) {
    return JSON.parse(JSON.stringify(obj));
}

function loadState() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            return Object.assign(deepClone(DEFAULT_STATE), parsed);
        }
    } catch (e) {
        console.warn('state load failed', e);
    }
    return deepClone(DEFAULT_STATE);
}

function saveState() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}

function esc(value) {
    return String(value == null ? '' : value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function nl2br(value) {
    return esc(value).replace(/\n/g,'<br>');
}

function uid(prefix) {
    return prefix + Math.random().toString(36).slice(2,9);
}

function getSurvey(id) {
    return state.surveys.find(s => s.id === id) || null;
}

function statusLabel(status) {
    return {
        draft:'作成中',
        wait:'公開済み・回答開始待ち',
        open:'回答受付中',
        closed:'回答受付終了',
        archived:'保管'
    }[status] || status;
}

function statusClass(status) {
    return {
        draft:'draft',
        wait:'wait',
        open:'open',
        closed:'closed',
        archived:'archived'
    }[status] || 'draft';
}

function statusBadge(status) {
    return '<span class="badge ' + statusClass(status) + '">' + esc(statusLabel(status)) + '</span>';
}

function formatDate(value) {
    if (!value) return '-';
    return value.replace('T',' ');
}

function percent(survey) {
    if (survey.target == null || survey.target <= 0) return null;
    return Math.round((survey.answers / survey.target) * 100);
}

function answerStatus(survey) {
    const p = percent(survey);
    return p == null
        ? esc(survey.answers) + '件'
        : esc(survey.answers) + ' / ' + esc(survey.target) + '件<br><span class="muted">回答率 ' + p + '%</span>';
}

function updateAutomaticStatus(survey) {
    if (!survey) return;

    if (survey.status === 'wait' || survey.status === 'open') {
        const now = new Date();
        const start = survey.start ? new Date(survey.start) : null;
        const end = survey.end ? new Date(survey.end) : null;

        if (end && now >= end) {
            survey.status = 'closed';
        } else if (start && now < start) {
            survey.status = 'wait';
        } else {
            survey.status = 'open';
        }
    }
}

function updateAllAutomaticStatuses() {
    state.surveys.forEach(updateAutomaticStatus);
}

function currentSurvey() {
    return getSurvey(state.currentSurveyId);
}

function toast(message) {
    const el = document.getElementById('toast');
    el.textContent = message;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2200);
}

function navigate(view, surveyId) {
    state.currentView = view;
    if (surveyId !== undefined) state.currentSurveyId = surveyId;
    saveState();
    render();
    window.scrollTo({top:0,behavior:'smooth'});
}

function openModal(title, body, footer) {
    document.getElementById('modalTitle').innerHTML = title;
    document.getElementById('modalBody').innerHTML = body;
    document.getElementById('modalFoot').innerHTML = footer || '';
    document.getElementById('modal').classList.add('show');
}

function closeModal() {
    document.getElementById('modal').classList.remove('show');
}

function confirmAction(title, message, actionLabel, callback, danger) {
    openModal(
        esc(title),
        '<p>' + esc(message) + '</p>',
        '<button class="btn" data-action="modal-close">キャンセル</button>' +
        '<button class="btn ' + (danger ? 'danger' : 'primary') + '" id="modalConfirm">' +
        esc(actionLabel) + '</button>'
    );
    const button = document.getElementById('modalConfirm');
    button.addEventListener('click', function () {
        closeModal();
        callback();
    }, {once:true});
}

/* =========================
 * Rendering
 * ========================= */
function render() {
    updateAllAutomaticStatuses();
    saveState();

    const app = document.getElementById('app');
    app.innerHTML = getViewHtml(state.currentView);

    document.querySelectorAll('[data-nav]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.nav === state.currentView);
    });

    bindDynamic();
}

function getViewHtml(view) {
    switch (view) {
        case 'home': return homeView();
        case 'list': return listView();
        case 'new': return editView(null);
        case 'edit': return editView(currentSurvey());
        case 'preview': return previewView(currentSurvey());
        case 'send': return sendView(currentSurvey());
        case 'status': return statusView(currentSurvey());
        case 'responses': return responsesView(currentSurvey());
        case 'respond': return respondentView(currentSurvey() || getSurvey('s1'));
        case 'complete': return completeView(currentSurvey() || getSurvey('s1'));
        case 'settings-kintone': return kintoneView();
        case 'settings-smtp': return smtpView();
        default: return homeView();
    }
}

function pageHead(title, subtitle, actions) {
    return '<div class="page-head">' +
        '<div><h1>' + esc(title) + '</h1><p>' + esc(subtitle || '') + '</p></div>' +
        '<div class="actions">' + (actions || '') + '</div>' +
        '</div>';
}

function homeView() {
    const counts = {draft:0,wait:0,open:0,closed:0,archived:0};
    state.surveys.forEach(s => counts[s.status]++);

    const recent = [...state.surveys]
        .sort((a,b) => String(b.updated).localeCompare(String(a.updated)))
        .slice(0,5);

    const active = state.surveys.filter(s => s.status === 'wait' || s.status === 'open');
    const needSend = state.surveys.filter(s => (s.status === 'wait' || s.status === 'open') && s.sent < (s.target || 0));

    return pageHead(
        'ホーム',
        'アンケート運営状況を確認できます。',
        '<button class="btn primary" data-nav="new">＋ 新しいアンケートを作成する</button>'
    ) +
    '<div class="grid grid-5">' +
        statCard('作成中',counts.draft,'draft') +
        statCard('回答開始待ち',counts.wait,'wait') +
        statCard('回答受付中',counts.open,'open') +
        statCard('回答受付終了',counts.closed,'closed') +
        statCard('保管',counts.archived,'archived') +
    '</div>' +

    '<div class="section grid grid-2">' +
        '<section class="card">' +
            '<div class="space-between"><h2>最近更新したアンケート</h2><button class="btn small" data-nav="list">一覧を見る</button></div>' +
            tableSurveys(recent,true) +
        '</section>' +
        '<section class="card">' +
            '<div class="space-between"><h2>現在運営中のアンケート</h2><button class="btn small" data-nav="list">一覧を見る</button></div>' +
            (active.length ? tableSurveys(active,true) : '<div class="empty">現在運営中のアンケートはありません。</div>') +
        '</section>' +
    '</div>' +

    '<div class="section grid grid-2">' +
        '<section class="card">' +
            '<div class="space-between"><h2>送付が必要なアンケート</h2></div>' +
            (needSend.length ? tableSurveys(needSend,true) : '<div class="empty">送付が必要なアンケートはありません。</div>') +
        '</section>' +
        '<section class="card">' +
            '<div class="space-between"><h2>回答状況を確認したいアンケート</h2></div>' +
            tableSurveyStatus(active.concat(state.surveys.filter(s => s.status === 'closed')).slice(0,5)) +
        '</section>' +
    '</div>';
}

function statCard(label,num,status) {
    return '<div class="card stat" data-filter-status="' + status + '">' +
        '<div class="label">' + esc(label) + '</div><div class="num">' + num + '</div>' +
        '<div class="muted" style="font-size:12px;margin-top:5px">クリックして一覧を見る</div>' +
    '</div>';
}

function tableSurveys(surveys, compact) {
    if (!surveys.length) return '<div class="empty">データがありません。</div>';
    let html = '<div class="table-wrap"><table><thead><tr>' +
        '<th>アンケート名</th><th>状態</th>' +
        (compact ? '<th>回答数</th><th>操作</th>' : '<th>更新日</th><th>回答状況</th><th>操作</th>') +
        '</tr></thead><tbody>';

    surveys.forEach(s => {
        html += '<tr>' +
            '<td><strong>' + esc(s.name) + '</strong></td>' +
            '<td>' + statusBadge(s.status) + '</td>' +
            (compact
                ? '<td>' + answerStatus(s) + '</td>'
                : '<td>' + esc(s.updated) + '</td><td>' + answerStatus(s) + '</td>') +
            '<td><button class="btn small" data-action="view" data-id="' + esc(s.id) + '">開く</button></td>' +
        '</tr>';
    });

    return html + '</tbody></table></div>';
}

function tableSurveyStatus(surveys) {
    if (!surveys.length) return '<div class="empty">確認対象がありません。</div>';
    return '<div class="table-wrap"><table><thead><tr><th>アンケート名</th><th>状態</th><th>回答数</th><th>回答率</th><th>操作</th></tr></thead><tbody>' +
        surveys.map(s => {
            const p = percent(s);
            return '<tr><td>' + esc(s.name) + '</td><td>' + statusBadge(s.status) + '</td>' +
                '<td>' + s.answers + '件</td><td>' + (p == null ? '-' : p + '%') + '</td>' +
                '<td><button class="btn small" data-action="status" data-id="' + esc(s.id) + '">回答状況を見る</button></td></tr>';
        }).join('') +
        '</tbody></table></div>';
}

function listView() {
    const rows = [...state.surveys].sort((a,b) => String(b.updated).localeCompare(String(a.updated)));

    return pageHead(
        'アンケート一覧',
        'アンケートを状態・回答状況ごとに管理できます。',
        '<button class="btn primary" data-nav="new">＋ 新しいアンケートを作成する</button>'
    ) +
    '<div class="card">' +
        '<div class="flex" style="margin-bottom:15px">' +
            '<select id="listFilter" style="max-width:240px">' +
                '<option value="">すべての状態</option>' +
                '<option value="draft">作成中</option>' +
                '<option value="wait">公開済み・回答開始待ち</option>' +
                '<option value="open">回答受付中</option>' +
                '<option value="closed">回答受付終了</option>' +
                '<option value="archived">保管</option>' +
            '</select>' +
            '<input id="listSearch" placeholder="アンケート名で検索" style="max-width:300px">' +
        '</div>' +
        '<div id="surveyListTable">' + listTableRows(rows) + '</div>' +
    '</div>';
}

function listTableRows(rows) {
    if (!rows.length) return '<div class="empty">該当するアンケートがありません。</div>';

    return '<div class="table-wrap"><table><thead><tr>' +
        '<th>アンケート名</th><th>状態</th><th>作成日</th><th>更新日</th>' +
        '<th>回答受付期間</th><th>送付数</th><th>回答状況</th><th>主な操作</th>' +
        '</tr></thead><tbody>' +
        rows.map(s => '<tr>' +
            '<td><strong>' + esc(s.name) + '</strong></td>' +
            '<td>' + statusBadge(s.status) + '</td>' +
            '<td>' + esc(s.created) + '</td>' +
            '<td>' + esc(s.updated) + '</td>' +
            '<td>' + esc(formatDate(s.start)) + '<br>～ ' + esc(formatDate(s.end)) + '</td>' +
            '<td>' + s.sent + '件</td>' +
            '<td>' + answerStatus(s) + '</td>' +
            '<td><div class="flex">' + actionButtons(s) + '</div></td>' +
        '</tr>').join('') +
        '</tbody></table></div>';
}

function actionButtons(s) {
    let h = '<button class="btn small" data-action="view" data-id="' + esc(s.id) + '">内容を見る</button>';

    if (s.status === 'draft') {
        h += '<button class="btn small" data-action="edit" data-id="' + esc(s.id) + '">編集する</button>';
        h += '<button class="btn small primary" data-action="preview" data-id="' + esc(s.id) + '">公開前確認</button>';
        h += '<button class="btn small danger" data-action="delete" data-id="' + esc(s.id) + '">削除</button>';
    }

    if (s.status === 'wait') {
        h += '<button class="btn small" data-action="status" data-id="' + esc(s.id) + '">回答状況</button>';
        h += '<button class="btn small" data-action="responses" data-id="' + esc(s.id) + '">回答内容</button>';
        h += '<button class="btn small" data-action="send" data-id="' + esc(s.id) + '">アンケートを送付する</button>';
        h += '<button class="btn small primary" data-action="start" data-id="' + esc(s.id) + '">回答受付を開始する</button>';
    }

    if (s.status === 'open') {
        h += '<button class="btn small" data-action="status" data-id="' + esc(s.id) + '">回答状況</button>';
        h += '<button class="btn small" data-action="responses" data-id="' + esc(s.id) + '">回答内容</button>';
        h += '<button class="btn small" data-action="send" data-id="' + esc(s.id) + '">アンケートを送付する</button>';
        h += '<button class="btn small danger" data-action="close" data-id="' + esc(s.id) + '">回答受付を終了する</button>';
    }

    if (s.status === 'closed') {
        h += '<button class="btn small" data-action="status" data-id="' + esc(s.id) + '">回答状況</button>';
        h += '<button class="btn small" data-action="responses" data-id="' + esc(s.id) + '">回答内容</button>';
        h += '<button class="btn small warning" data-action="archive" data-id="' + esc(s.id) + '">保管する</button>';
    }

    if (s.status === 'archived') {
        h += '<button class="btn small" data-action="status" data-id="' + esc(s.id) + '">回答状況</button>';
        h += '<button class="btn small" data-action="responses" data-id="' + esc(s.id) + '">回答内容</button>';
    }

    return h;
}

/* =========================
 * Editor
 * ========================= */
function editView(survey) {
    const isNew = !survey;
    if (isNew) {
        survey = {
            id:null,name:'',description:'',guide:'',completeMessage:'ご回答ありがとうございました。',
            start:'',end:'',status:'draft',numbering:'global',
            groups:[{id:'new-group',name:'基本情報'}],
            questions:[]
        };
    }

    const locked = survey.status !== 'draft';
    const title = isNew ? 'アンケート新規作成' : 'アンケート編集';
    const action = isNew
        ? '<button class="btn primary" data-action="save-new">保存する</button>'
        : '<button class="btn primary" data-action="save-edit" data-id="' + esc(survey.id) + '">保存する</button>';

    return pageHead(title, locked ? '公開後は回答データとの整合性に影響する項目を変更できません。' : '基本情報・質問・グループを設定します。',
        '<button class="btn" data-nav="list">一覧へ戻る</button>' + action
    ) +
    (locked ? '<div class="notice warning">このアンケートは公開済みです。質問構造・選択肢・分岐などは編集できません。変更可能な項目のみ編集できます。</div>' : '') +
    '<div class="card">' +
        '<h2>基本情報</h2>' +
        '<div class="form-grid">' +
            '<div class="form-group full"><label>アンケート名 <span class="required">*</span></label><input id="surveyName" value="' + esc(survey.name) + '" ' + (locked ? 'disabled' : '') + '></div>' +
            '<div class="form-group full"><label>説明文</label><textarea id="surveyDescription">' + esc(survey.description) + '</textarea></div>' +
            '<div class="form-group"><label>回答受付開始日時</label><input type="datetime-local" id="surveyStart" value="' + esc(survey.start) + '"></div>' +
            '<div class="form-group"><label>回答受付終了日時</label><input type="datetime-local" id="surveyEnd" value="' + esc(survey.end) + '"></div>' +
            '<div class="form-group full"><label>回答者への案内文</label><textarea id="surveyGuide">' + esc(survey.guide) + '</textarea></div>' +
            '<div class="form-group full"><label>完了時のメッセージ</label><textarea id="surveyComplete">' + esc(survey.completeMessage) + '</textarea></div>' +
            '<div class="form-group"><label>質問番号</label><select id="numbering">' +
                '<option value="global" ' + (survey.numbering === 'global' ? 'selected' : '') + '>アンケート全体で連番</option>' +
                '<option value="group" ' + (survey.numbering === 'group' ? 'selected' : '') + '>グループごとに連番</option>' +
            '</select></div>' +
        '</div>' +
    '</div>' +

    '<div class="section card">' +
        '<div class="space-between"><h2>質問グループ</h2><button class="btn small" data-action="add-group">＋ グループを追加</button></div>' +
        '<div id="groupEditor">' + groupEditor(survey) + '</div>' +
    '</div>' +

    '<div class="section card">' +
        '<div class="space-between"><h2>質問</h2><button class="btn small primary" data-action="add-question">＋ 質問を追加</button></div>' +
        '<div id="questionEditor">' + questionEditor(survey) + '</div>' +
    '</div>';
}

function groupEditor(survey) {
    return survey.groups.map(g =>
        '<div class="flex" style="margin-bottom:8px">' +
            '<input data-group-id="' + esc(g.id) + '" value="' + esc(g.name) + '" placeholder="グループ名">' +
            '<button class="btn small danger" data-action="remove-group" data-group-id="' + esc(g.id) + '">削除</button>' +
        '</div>'
    ).join('');
}

function questionEditor(survey) {
    if (!survey.questions.length) {
        return '<div class="empty">質問がありません。「質問を追加」から登録してください。</div>';
    }

    return survey.questions.map((q,index) => {
        const group = survey.groups.find(g => g.id === q.groupId);
        const typeOptions = [
            ['text','文章を入力する'],
            ['single','1つだけ選ぶ'],
            ['multi','複数選ぶ'],
            ['rating','段階で評価する']
        ];

        let options = '';
        if (q.type === 'single' || q.type === 'multi') {
            options = '<div style="margin-top:12px"><label>選択肢</label>' +
                q.options.map(o =>
                    '<div class="option-row">' +
                    '<input data-option-text="' + esc(q.id) + '" data-option-id="' + esc(o.id) + '" value="' + esc(o.text) + '">' +
                    '<button class="btn small danger" data-action="remove-option" data-qid="' + esc(q.id) + '" data-oid="' + esc(o.id) + '">削除</button>' +
                    '</div>'
                ).join('') +
                '<button class="btn small" data-action="add-option" data-qid="' + esc(q.id) + '">＋ 選択肢</button>' +
            '</div>';
        }

        let branch = '';
        if (q.type === 'single') {
            branch = '<div style="margin-top:12px"><label>分岐設定</label>' +
                q.options.map(o => branchRow(survey,q,o)).join('') +
                '</div>';
        }

        return '<div class="question" data-question="' + esc(q.id) + '">' +
            '<div class="question-head">' +
                '<div><div class="question-title">質問 ' + (index + 1) + '</div>' +
                '<div class="question-meta">' + esc(group ? group.name : '未所属') + '</div></div>' +
                '<div class="flex">' +
                    '<button class="btn small" data-action="move-up" data-qid="' + esc(q.id) + '">↑</button>' +
                    '<button class="btn small" data-action="move-down" data-qid="' + esc(q.id) + '">↓</button>' +
                    '<button class="btn small danger" data-action="remove-question" data-qid="' + esc(q.id) + '">削除</button>' +
                '</div>' +
            '</div>' +
            '<div class="form-group" style="margin-top:12px"><label>質問文 <span class="required">*</span></label>' +
                '<textarea data-q-text="' + esc(q.id) + '" ' + (survey.status !== 'draft' ? 'disabled' : '') + '>' + esc(q.text) + '</textarea></div>' +
            '<div class="form-grid">' +
                '<div class="form-group"><label>質問の種類</label><select data-q-type="' + esc(q.id) + '" ' + (survey.status !== 'draft' ? 'disabled' : '') + '>' +
                typeOptions.map(t => '<option value="' + t[0] + '" ' + (q.type === t[0] ? 'selected' : '') + '>' + t[1] + '</option>').join('') +
                '</select></div>' +
                '<div class="form-group"><label>所属グループ</label><select data-q-group="' + esc(q.id) + '" ' + (survey.status !== 'draft' ? 'disabled' : '') + '>' +
                survey.groups.map(g => '<option value="' + esc(g.id) + '" ' + (q.groupId === g.id ? 'selected' : '') + '>' + esc(g.name) + '</option>').join('') +
                '</select></div>' +
            '</div>' +
            '<label class="checkbox"><input type="checkbox" data-q-required="' + esc(q.id) + '" ' + (q.required ? 'checked' : '') + ' ' + (survey.status !== 'draft' ? 'disabled' : '') + '> 必須回答</label>' +
            '<div class="form-group" style="margin-top:10px"><label>補足説明</label><input data-q-help="' + esc(q.id) + '" value="' + esc(q.help) + '"></div>' +
            options +
            branch +
        '</div>';
    }).join('');
}

function branchRow(survey,q,o) {
    const next = o.next || null;
    const key = next ? next.type + ':' + next.id : 'none';
    let options = '<option value="none" ' + (key === 'none' ? 'selected' : '') + '>設定なし（次の質問へ）</option>' +
        '<option value="end" ' + (key === 'end:' ? 'selected' : '') + '>回答終了</option>';

    survey.questions.forEach(target => {
        if (target.id === q.id) return;
        options += '<option value="question:' + esc(target.id) + '" ' +
            (key === 'question:' + target.id ? 'selected' : '') + '>質問：' + esc(target.text || '(未入力)') + '</option>';
    });

    survey.groups.forEach(g => {
        options += '<option value="group:' + esc(g.id) + '" ' +
            (key === 'group:' + g.id ? 'selected' : '') + '>グループ：' + esc(g.name) + '</option>';
    });

    return '<div class="form-grid" style="margin-bottom:8px">' +
        '<div><span class="muted">' + esc(o.text || '(選択肢未入力)') + '</span></div>' +
        '<select data-branch-q="' + esc(q.id) + '" data-branch-o="' + esc(o.id) + '">' + options + '</select>' +
        '</div>';
}

/* =========================
 * Preview
 * ========================= */
function previewView(survey) {
    if (!survey) return '<div class="empty">アンケートが選択されていません。</div>';

    const checks = validateSurvey(survey);
    const errorHtml = checks.length
        ? '<div class="notice danger"><strong>公開できません。</strong><ul>' +
            checks.map(e => '<li>' + esc(e) + '</li>').join('') +
            '</ul></div>'
        : '<div class="notice success">公開前チェックをすべて通過しています。この内容で公開できます。</div>';

    return pageHead(
        '公開前確認',
        survey.name || '名称未設定',
        '<button class="btn" data-action="edit" data-id="' + esc(survey.id) + '">編集画面に戻る</button>' +
        '<button class="btn primary" data-action="publish" data-id="' + esc(survey.id) + '" ' + (checks.length ? 'disabled' : '') + '>この内容で公開する</button>'
    ) +
    errorHtml +
    '<div class="card">' +
        '<h2>公開内容</h2>' +
        '<div class="grid grid-2">' +
            '<div><strong>アンケート名</strong><p>' + esc(survey.name || '-') + '</p></div>' +
            '<div><strong>公開後の状態</strong><p>' + (survey.start && new Date(survey.start) > new Date() ? statusBadge('wait') : statusBadge('open')) + '</p></div>' +
            '<div><strong>説明</strong><p>' + nl2br(survey.description || '-') + '</p></div>' +
            '<div><strong>回答受付期間</strong><p>' + esc(formatDate(survey.start)) + ' ～ ' + esc(formatDate(survey.end)) + '</p></div>' +
        '</div>' +
    '</div>' +
    '<div class="section card"><h2>質問一覧</h2>' +
        previewQuestions(survey) +
    '</div>' +
    '<div class="section card"><h2>分岐設定</h2>' +
        branchSummary(survey) +
    '</div>';
}

function previewQuestions(survey) {
    if (!survey.questions.length) return '<div class="empty">質問がありません。</div>';
    let n = 0;
    return survey.groups.map(g => {
        const qs = survey.questions.filter(q => q.groupId === g.id);
        if (!qs.length) return '';
        let html = '<h3>' + esc(g.name) + '</h3>';
        qs.forEach(q => {
            n++;
            html += '<div class="question">' +
                '<div class="question-title">' + (survey.numbering === 'global' ? n : (qs.indexOf(q)+1)) + '. ' + esc(q.text || '(質問文未入力)') +
                ' ' + (q.required ? '<span class="badge wait">必須</span>' : '<span class="badge draft">任意</span>') +
                '</div>' +
                '<div class="question-meta">' + esc(typeName(q.type)) + '</div>' +
                (q.help ? '<p class="muted">' + esc(q.help) + '</p>' : '') +
                ((q.type === 'single' || q.type === 'multi') ? '<ul>' + q.options.map(o => '<li>' + esc(o.text) + '</li>').join('') + '</ul>' : '') +
                '</div>';
        });
        return html;
    }).join('');
}

function branchSummary(survey) {
    const rows = [];
    survey.questions.forEach(q => {
        if (q.type !== 'single') return;
        q.options.forEach(o => {
            let target = '次の質問';
            if (o.next) {
                if (o.next.type === 'end') target = '回答終了';
                else if (o.next.type === 'question') {
                    const t = survey.questions.find(x => x.id === o.next.id);
                    target = t ? '質問：' + t.text : '存在しない質問';
                } else if (o.next.type === 'group') {
                    const g = survey.groups.find(x => x.id === o.next.id);
                    target = g ? 'グループ：' + g.name : '存在しないグループ';
                }
            }
            rows.push('<tr><td>' + esc(q.text) + '</td><td>' + esc(o.text) + '</td><td>' + esc(target) + '</td></tr>');
        });
    });

    if (!rows.length) return '<div class="empty">分岐設定はありません。</div>';

    return '<div class="table-wrap"><table><thead><tr><th>対象質問</th><th>選択肢</th><th>分岐先</th></tr></thead><tbody>' +
        rows.join('') + '</tbody></table></div>';
}

function typeName(type) {
    return {
        text:'文章を入力する',
        single:'1つだけ選ぶ',
        multi:'複数選ぶ',
        rating:'段階で評価する'
    }[type] || type;
}

function validateSurvey(survey) {
    const errors = [];

    if (!survey.name.trim()) errors.push('アンケート名を入力してください。');
    if (!survey.questions.length) errors.push('質問を1件以上登録してください。');

    survey.questions.forEach((q,i) => {
        if (!q.text.trim()) errors.push('質問 ' + (i+1) + ' の質問文を入力してください。');
        if ((q.type === 'single' || q.type === 'multi') && q.options.length === 0) {
            errors.push('「' + (q.text || '質問 ' + (i+1)) + '」に選択肢を1件以上設定してください。');
        }
        if (q.type === 'single') {
            q.options.forEach(o => {
                if (!o.text.trim()) errors.push('「' + (q.text || '質問') + '」に未入力の選択肢があります。');
                if (o.next && o.next.type === 'question' && !survey.questions.some(x => x.id === o.next.id)) {
                    errors.push('分岐先の質問が存在しません。');
                }
                if (o.next && o.next.type === 'group' && !survey.groups.some(x => x.id === o.next.id)) {
                    errors.push('分岐先のグループが存在しません。');
                }
            });
        }
    });

    if (survey.start && survey.end && new Date(survey.start) >= new Date(survey.end)) {
        errors.push('回答受付開始日時は終了日時より前に設定してください。');
    }

    const graphErrors = detectBranchCycles(survey);
    errors.push(...graphErrors);

    return [...new Set(errors)];
}

function detectBranchCycles(survey) {
    const errors = [];
    const graph = {};

    survey.questions.forEach(q => {
        graph[q.id] = [];
        if (q.type === 'single') {
            q.options.forEach(o => {
                if (o.next && o.next.type === 'question' && survey.questions.some(x => x.id === o.next.id)) {
                    graph[q.id].push(o.next.id);
                }
            });
        }
    });

    const visiting = new Set();
    const visited = new Set();

    function dfs(id) {
        if (visiting.has(id)) return true;
        if (visited.has(id)) return false;

        visiting.add(id);
        for (const next of graph[id] || []) {
            if (dfs(next)) return true;
        }
        visiting.delete(id);
        visited.add(id);
        return false;
    }

    for (const id of Object.keys(graph)) {
        if (dfs(id)) {
            errors.push('分岐設定に循環があります。分岐先を見直してください。');
            break;
        }
    }

    return errors;
}

/* =========================
 * Send
 * ========================= */
function sendView(survey) {
    if (!survey) return '<div class="empty">アンケートが選択されていません。</div>';

    const eligible = survey.status === 'wait' || survey.status === 'open';
    const selected = state.selectedCustomerIds
        .map(id => state.customers.find(c => c.id === id))
        .filter(Boolean);

    return pageHead(
        'アンケート送付',
        survey.name,
        '<button class="btn" data-nav="list">一覧へ戻る</button>'
    ) +
    (!eligible ? '<div class="notice warning">この状態ではアンケートを送付できません。</div>' : '') +
    '<div class="card">' +
        '<div class="space-between"><h2>送付対象者</h2><strong>' + selected.length + '名 選択中</strong></div>' +
        '<div class="flex" style="margin:10px 0">' +
            '<button class="btn small" data-action="select-all">全員を選択</button>' +
            '<button class="btn small" data-action="clear-all">全選択を解除</button>' +
            '<button class="btn small" data-action="refresh-customers">顧客一覧を更新</button>' +
        '</div>' +
        '<div class="table-wrap"><table><thead><tr><th></th><th>顧客名</th><th>担当者名</th><th>メールアドレス</th></tr></thead><tbody>' +
        state.customers.map(c =>
            '<tr><td><input type="checkbox" data-customer="' + esc(c.id) + '" ' + (state.selectedCustomerIds.includes(c.id) ? 'checked' : '') + '></td>' +
            '<td>' + esc(c.name) + '</td><td>' + esc(c.person) + '</td><td>' + esc(c.email) + '</td></tr>'
        ).join('') +
        '</tbody></table></div>' +
    '</div>' +

    '<div class="section card">' +
        '<h2>メール内容</h2>' +
        '<div class="form-group"><label>メール件名</label><input id="sendSubject" value="' + esc(state.sendSubject) + '"></div>' +
        '<div class="form-group"><label>メール本文</label><textarea id="sendBody">' + esc(state.sendBody) + '</textarea></div>' +
        '<div class="actions"><button class="btn primary" data-action="send-preview" ' + (!eligible || selected.length === 0 ? 'disabled' : '') + '>送信前確認へ進む</button></div>' +
    '</div>';
}

function sendPreview(survey) {
    const selected = state.selectedCustomerIds
        .map(id => state.customers.find(c => c.id === id))
        .filter(Boolean);

    openModal(
        'アンケート送付の最終確認',
        '<p><strong>アンケート名：</strong>' + esc(survey.name) + '</p>' +
        '<p><strong>送付先：</strong>' + selected.length + '名</p>' +
        '<p><strong>件名：</strong>' + esc(state.sendSubject) + '</p>' +
        '<p><strong>本文：</strong><br>' + nl2br(state.sendBody) + '</p>' +
        '<div class="notice warning">この操作は送付処理として扱います。モックでは実際のメールは送信されません。</div>',
        '<button class="btn" data-action="modal-close">送付先を変更する</button>' +
        '<button class="btn primary" id="modalSend">アンケートを送付する</button>'
    );

    document.getElementById('modalSend').addEventListener('click', function () {
        closeModal();
        const success = selected.length;
        survey.sent += success;
        if (survey.target == null) survey.target = success;
        survey.updated = new Date().toISOString().slice(0,10);
        survey.sends = survey.sends || [];
        survey.sends.push({
            date:new Date().toLocaleString('ja-JP'),
            target:success,
            success:success,
            failed:0
        });
        state.selectedCustomerIds = [];
        saveState();
        toast('モック送付が完了しました。');
        render();
    }, {once:true});
}

/* =========================
 * Status / Responses
 * ========================= */
function statusView(survey) {
    if (!survey) return '<div class="empty">アンケートが選択されていません。</div>';
    const p = percent(survey);

    return pageHead(
        '回答状況',
        survey.name,
        '<button class="btn" data-action="responses" data-id="' + esc(survey.id) + '">回答内容を見る</button>' +
        '<button class="btn" data-nav="list">一覧へ戻る</button>'
    ) +
    '<div class="notice">' + statusBadge(survey.status) + '　現在のアンケート状態</div>' +
    '<div class="grid grid-4">' +
        metric('送付数',survey.sent + '件') +
        metric('回答数',survey.answers + '件') +
        metric('回答対象者数',survey.target == null ? '取得不可' : survey.target + '件') +
        metric('回答率',p == null ? '算出不可' : p + '%') +
    '</div>' +
    '<div class="section card">' +
        '<h2>回答受付期間</h2>' +
        '<p>' + esc(formatDate(survey.start)) + ' ～ ' + esc(formatDate(survey.end)) + '</p>' +
        '<div class="progress"><span style="width:' + (p == null ? 0 : p) + '%"></span></div>' +
    '</div>' +
    '<div class="section card">' +
        '<h2>回答状況の推移</h2>' +
        '<div class="grid grid-3">' +
            '<div><div class="muted">送付</div><div class="kpi">' + survey.sent + '</div></div>' +
            '<div><div class="muted">回答</div><div class="kpi">' + survey.answers + '</div></div>' +
            '<div><div class="muted">未回答</div><div class="kpi">' + (survey.target == null ? '-' : Math.max(0,survey.target-survey.answers)) + '</div></div>' +
        '</div>' +
    '</div>';
}

function metric(label,value) {
    return '<div class="card"><div class="muted">' + esc(label) + '</div><div class="kpi">' + esc(value) + '</div></div>';
}

function responsesView(survey) {
    if (!survey) return '<div class="empty">アンケートが選択されていません。</div>';

    const responses = survey.responses || [];

    return pageHead(
        '回答内容',
        survey.name,
        '<button class="btn" data-action="status" data-id="' + esc(survey.id) + '">回答状況を見る</button>' +
        '<button class="btn" data-nav="list">一覧へ戻る</button>'
    ) +
    '<div class="card">' +
        '<div class="space-between"><h2>回答一覧</h2><span class="muted">' + responses.length + '件</span></div>' +
        (responses.length ? '<div class="table-wrap"><table><thead><tr><th>回答番号</th><th>回答日時</th><th>回答者</th><th>回答</th></tr></thead><tbody>' +
            responses.map((r,i) =>
                '<tr><td>#' + (i+1) + '</td><td>' + esc(r.date) + '</td><td>' + esc(r.customer) + '</td>' +
                '<td><button class="btn small" data-action="response-detail" data-rid="' + esc(r.id) + '">回答内容を見る</button></td></tr>'
            ).join('') +
            '</tbody></table></div>'
            : '<div class="empty">回答データがありません。</div>') +
    '</div>';
}

function responseDetail(survey,response) {
    if (!response) return;
    const items = Object.keys(response.answers || {}).map(qid => {
        const q = survey.questions.find(x => x.id === qid);
        return '<div class="review-item"><strong>' + esc(q ? q.text : qid) + '</strong><div style="margin-top:5px">' + esc(response.answers[qid]) + '</div></div>';
    }).join('');

    openModal(
        '回答 #' + esc(response.id),
        '<p><strong>回答日時：</strong>' + esc(response.date) + '</p>' +
        '<p><strong>回答者：</strong>' + esc(response.customer) + '</p>' +
        '<div>' + items + '</div>',
        '<button class="btn" data-action="modal-close">閉じる</button>'
    );
}

/* =========================
 * Respondent
 * ========================= */
function respondentView(survey) {
    if (!survey) return '<div class="empty">回答可能なアンケートがありません。</div>';

    if (survey.status !== 'open') {
        return '<div class="respond-wrap">' +
            '<div class="notice warning">' +
            'このアンケートは現在回答を受け付けていません。<br>' +
            '現在の状態：' + statusLabel(survey.status) +
            '</div>' +
            '<button class="btn" data-nav="home">管理画面へ戻る</button>' +
            '</div>';
    }

    const questions = respondentQuestions(survey);
    const step = state.respondStep;

    return '<div class="respond-wrap">' +
        '<div class="respond-header"><h1 style="margin:0 0 7px">' + esc(survey.name) + '</h1><div>' + esc(survey.guide || survey.description) + '</div></div>' +
        '<div class="respond-body">' +
            '<div class="timeline">' +
                timelineStep('1','回答入力',step === 'input' ? 'current' : 'done') +
                timelineStep('2','回答確認',step === 'review' ? 'current' : (step === 'complete' ? 'done' : '')) +
                timelineStep('3','回答完了',step === 'complete' ? 'current' : '') +
            '</div>' +
            (step === 'input' ? respondentInput(survey,questions) : '') +
            (step === 'review' ? respondentReview(survey,questions) : '') +
        '</div>' +
        '</div>';
}

function timelineStep(num,label,cls) {
    return '<div class="step ' + cls + '"><span class="circle">' + num + '</span><div class="caption">' + label + '</div></div>';
}

function respondentQuestions(survey) {
    let list = [];
    let current = survey.questions[0];
    const visited = new Set();

    while (current && !visited.has(current.id)) {
        visited.add(current.id);
        list.push(current);

        let nextId = null;
        if (current.type === 'single') {
            const answer = window.mockAnswers ? window.mockAnswers[current.id] : null;
            const option = current.options.find(o => o.text === answer);
            if (option && option.next && option.next.type === 'question') {
                nextId = option.next.id;
            }
        }

        if (nextId) {
            current = survey.questions.find(q => q.id === nextId) || null;
        } else {
            const idx = survey.questions.findIndex(q => q.id === current.id);
            current = survey.questions[idx + 1] || null;
        }
    }

    return list;
}

window.mockAnswers = {};

function respondentInput(survey,questions) {
    return '<div>' +
        '<h2>回答入力</h2>' +
        '<p class="muted">' + esc(survey.description) + '</p>' +
        questions.map((q,i) => answerField(q,i)).join('') +
        '<div class="actions" style="margin-top:20px"><button class="btn primary" data-action="respond-review">回答内容を確認する</button></div>' +
    '</div>';
}

function answerField(q,index) {
    const val = window.mockAnswers[q.id] || '';
    let input = '';

    if (q.type === 'text') {
        input = '<textarea data-answer="' + esc(q.id) + '">' + esc(val) + '</textarea>';
    } else if (q.type === 'single') {
        input = q.options.map(o =>
            '<div class="answer-option"><label><input type="radio" name="answer_' + esc(q.id) + '" data-answer-radio="' + esc(q.id) + '" value="' + esc(o.text) + '" ' + (val === o.text ? 'checked' : '') + '>' +
            esc(o.text) + '</label></div>'
        ).join('');
    } else if (q.type === 'multi') {
        const values = Array.isArray(val) ? val : [];
        input = q.options.map(o =>
            '<div class="answer-option"><label><input type="checkbox" data-answer-multi="' + esc(q.id) + '" value="' + esc(o.text) + '" ' + (values.includes(o.text) ? 'checked' : '') + '>' +
            esc(o.text) + '</label></div>'
        ).join('');
    } else if (q.type === 'rating') {
        input = '<select data-answer="' + esc(q.id) + '"><option value="">選択してください</option>' +
            [1,2,3,4,5].map(n => '<option value="' + n + '" ' + (String(val) === String(n) ? 'selected' : '') + '>' + n + '</option>').join('') +
            '</select>';
    }

    return '<div class="question">' +
        '<div class="question-title">' + (index+1) + '. ' + esc(q.text) +
        (q.required ? ' <span class="badge wait">必須</span>' : ' <span class="badge draft">任意</span>') +
        '</div>' +
        (q.help ? '<p class="muted">' + esc(q.help) + '</p>' : '') +
        '<div style="margin-top:10px">' + input + '</div>' +
        '<div class="error-text" data-error-for="' + esc(q.id) + '"></div>' +
    '</div>';
}

function collectAnswers() {
    document.querySelectorAll('[data-answer]').forEach(el => {
        window.mockAnswers[el.dataset.answer] = el.value;
    });

    document.querySelectorAll('[data-answer-radio]').forEach(el => {
        if (el.checked) window.mockAnswers[el.dataset.answerRadio] = el.value;
    });

    const multi = {};
    document.querySelectorAll('[data-answer-multi]').forEach(el => {
        const id = el.dataset.answerMulti;
        if (!multi[id]) multi[id] = [];
        if (el.checked) multi[id].push(el.value);
    });
    Object.keys(multi).forEach(id => window.mockAnswers[id] = multi[id]);
}

function respondentReview(survey,questions) {
    return '<div>' +
        '<h2>回答確認</h2>' +
        '<div class="notice">送信前に入力内容を確認してください。分岐によって表示されなかった質問は含まれていません。</div>' +
        questions.map((q,i) => {
            const val = window.mockAnswers[q.id];
            return '<div class="review-item"><strong>' + (i+1) + '. ' + esc(q.text) + '</strong><div style="margin-top:5px">' +
                esc(Array.isArray(val) ? val.join(', ') : (val || '未回答')) + '</div></div>';
        }).join('') +
        '<div class="actions" style="margin-top:20px">' +
            '<button class="btn" data-action="respond-back">回答を修正する</button>' +
            '<button class="btn primary" data-action="respond-submit">回答を送信する</button>' +
        '</div>' +
    '</div>';
}

function completeView(survey) {
    return '<div class="respond-wrap"><div class="respond-body" style="text-align:center;padding:60px 30px">' +
        '<div style="font-size:54px;color:var(--success)">✓</div>' +
        '<h1>回答が完了しました</h1>' +
        '<p>' + nl2br(survey ? survey.completeMessage : 'ご回答ありがとうございました。') + '</p>' +
        '<button class="btn" data-nav="home">管理画面へ戻る</button>' +
        '</div></div>';
}

/* =========================
 * Settings
 * ========================= */
function kintoneView() {
    const k = state.kintone;
    return pageHead(
        'kintone設定',
        '顧客一覧取得に利用する接続情報をモックします。',
        '<button class="btn primary" data-action="save-kintone">設定を保存する</button>'
    ) +
    '<div class="card">' +
        '<div class="notice">モック環境のため実際のkintoneには接続しません。</div>' +
        '<div class="form-grid">' +
            '<div class="form-group"><label>接続先</label><input id="kHost" value="' + esc(k.host) + '"></div>' +
            '<div class="form-group"><label>対象アプリ</label><input id="kApp" value="' + esc(k.app) + '"></div>' +
            '<div class="form-group"><label>顧客名として利用する項目</label><input id="kName" value="' + esc(k.nameField) + '"></div>' +
            '<div class="form-group"><label>担当者名として利用する項目</label><input id="kPerson" value="' + esc(k.personField) + '"></div>' +
            '<div class="form-group"><label>メールアドレスとして利用する項目</label><input id="kEmail" value="' + esc(k.emailField) + '"></div>' +
        '</div>' +
        '<div class="flex"><button class="btn" data-action="test-kintone">接続確認</button><button class="btn" data-action="refresh-customers">顧客一覧を更新</button></div>' +
        '<p class="muted" style="margin-top:12px">最終更新：' + esc(k.updatedAt) + '</p>' +
    '</div>';
}

function smtpView() {
    const s = state.smtp;
    return pageHead(
        'SMTP設定',
        'アンケート案内メールの送信設定をモックします。',
        '<button class="btn primary" data-action="save-smtp">設定を保存する</button>'
    ) +
    '<div class="card">' +
        '<div class="notice">モック環境のため実際のSMTPサーバーには接続しません。</div>' +
        '<div class="form-grid">' +
            '<div class="form-group"><label>SMTPサーバー</label><input id="smtpHost" value="' + esc(s.host) + '"></div>' +
            '<div class="form-group"><label>ポート</label><input id="smtpPort" value="' + esc(s.port) + '"></div>' +
            '<div class="form-group"><label>送信元メールアドレス</label><input id="smtpFrom" value="' + esc(s.from) + '"></div>' +
            '<div class="form-group"><label>暗号化方式</label><select id="smtpEncryption">' +
                ['なし','STARTTLS','SSL/TLS'].map(v => '<option ' + (s.encryption === v ? 'selected' : '') + '>' + v + '</option>').join('') +
            '</select></div>' +
        '</div>' +
        '<button class="btn" data-action="test-smtp">接続確認</button>' +
    '</div>';
}

/* =========================
 * Event Handling
 * ========================= */
function bindDynamic() {
    document.querySelectorAll('[data-nav]').forEach(el => {
        el.addEventListener('click', function () {
            const target = this.dataset.nav;
            if (target === 'respond') {
                state.respondStep = 'input';
                window.mockAnswers = {};
            }
            navigate(target);
        });
    });

    document.querySelectorAll('[data-filter-status]').forEach(el => {
        el.addEventListener('click', function () {
            const status = this.dataset.filterStatus;
            navigate('list');
            setTimeout(() => {
                const filter = document.getElementById('listFilter');
                if (filter) {
                    filter.value = status;
                    filter.dispatchEvent(new Event('change'));
                }
            }, 0);
        });
    });

    const search = document.getElementById('listSearch');
    const filter = document.getElementById('listFilter');

    if (search) search.addEventListener('input', filterList);
    if (filter) filter.addEventListener('change', filterList);

    document.querySelectorAll('[data-action]').forEach(el => {
        el.addEventListener('click', handleAction);
    });

    document.querySelectorAll('[data-customer]').forEach(el => {
        el.addEventListener('change', function () {
            if (this.checked) {
                if (!state.selectedCustomerIds.includes(this.dataset.customer)) {
                    state.selectedCustomerIds.push(this.dataset.customer);
                }
            } else {
                state.selectedCustomerIds = state.selectedCustomerIds.filter(id => id !== this.dataset.customer);
            }
            saveState();
            render();
        });
    });

    bindEditorEvents();
}

function handleAction(e) {
    const action = this.dataset.action;
    const id = this.dataset.id || state.currentSurveyId;
    const survey = getSurvey(id);

    switch (action) {
        case 'view':
            if (survey) navigate(survey.status === 'draft' ? 'edit' : 'status', survey.id);
            break;

        case 'edit':
            if (survey) navigate('edit', survey.id);
            break;

        case 'preview':
            if (survey) navigate('preview', survey.id);
            break;

        case 'status':
            if (survey) navigate('status', survey.id);
            break;

        case 'responses':
            if (survey) navigate('responses', survey.id);
            break;

        case 'send':
            if (survey) {
                state.selectedCustomerIds = [];
                state.sendSurveyId = survey.id;
                navigate('send', survey.id);
            }
            break;

        case 'start':
            if (survey) {
                confirmAction(
                    '回答受付を開始',
                    survey.name + ' の回答受付を開始します。',
                    '回答受付を開始する',
                    function () {
                        survey.status = 'open';
                        survey.updated = new Date().toISOString().slice(0,10);
                        saveState();
                        toast('回答受付を開始しました。');
                        render();
                    }
                );
            }
            break;

        case 'close':
            if (survey) {
                confirmAction(
                    '回答受付を終了',
                    survey.name + ' の回答受付を終了します。終了後は新しい回答を受け付けません。',
                    '回答受付を終了する',
                    function () {
                        survey.status = 'closed';
                        survey.updated = new Date().toISOString().slice(0,10);
                        saveState();
                        toast('回答受付を終了しました。');
                        render();
                    },
                    true
                );
            }
            break;

        case 'archive':
            if (survey) {
                confirmAction(
                    'アンケートを保管',
                    survey.name + ' を保管します。保管後は再公開・再送付できません。',
                    '保管する',
                    function () {
                        survey.status = 'archived';
                        survey.updated = new Date().toISOString().slice(0,10);
                        saveState();
                        toast('アンケートを保管しました。');
                        render();
                    }
                );
            }
            break;

        case 'delete':
            if (survey) {
                confirmAction(
                    'アンケートを削除',
                    survey.name + ' を削除します。この操作はモック上でもデータから削除されます。',
                    '削除する',
                    function () {
                        state.surveys = state.surveys.filter(s => s.id !== survey.id);
                        state.currentSurveyId = null;
                        saveState();
                        toast('アンケートを削除しました。');
                        navigate('list');
                    },
                    true
                );
            }
            break;

        case 'publish':
            if (survey) {
                confirmAction(
                    'アンケートを公開',
                    survey.name + ' を公開します。公開後は質問構造等の変更が制限されます。',
                    'この内容で公開する',
                    function () {
                        survey.status = survey.start && new Date(survey.start) > new Date() ? 'wait' : 'open';
                        survey.updated = new Date().toISOString().slice(0,10);
                        saveState();
                        toast('アンケートを公開しました。');
                        navigate('list');
                    }
                );
            }
            break;

        case 'add-group':
            addGroup();
            break;

        case 'remove-group':
            removeGroup(this.dataset.groupId);
            break;

        case 'add-question':
            addQuestion();
            break;

        case 'remove-question':
            removeQuestion(this.dataset.qid);
            break;

        case 'move-up':
            moveQuestion(this.dataset.qid,-1);
            break;

        case 'move-down':
            moveQuestion(this.dataset.qid,1);
            break;

        case 'add-option':
            addOption(this.dataset.qid);
            break;

        case 'remove-option':
            removeOption(this.dataset.qid,this.dataset.oid);
            break;

        case 'save-new':
            saveNew();
            break;

        case 'save-edit':
            saveEdit(this.dataset.id);
            break;

        case 'select-all':
            state.selectedCustomerIds = state.customers.map(c => c.id);
            saveState();
            render();
            break;

        case 'clear-all':
            state.selectedCustomerIds = [];
            saveState();
            render();
            break;

        case 'refresh-customers':
            state.kintone.updatedAt = new Date().toLocaleString('ja-JP');
            saveState();
            toast('顧客一覧を更新しました。');
            break;

        case 'send-preview':
            if (survey) {
                state.sendSubject = document.getElementById('sendSubject').value;
                state.sendBody = document.getElementById('sendBody').value;
                saveState();
                sendPreview(survey);
            }
            break;

        case 'response-detail':
            if (survey) {
                const r = (survey.responses || []).find(x => x.id === this.dataset.rid);
                responseDetail(survey,r);
            }
            break;

        case 'respond-review':
            collectAnswers();
            if (validateAnswers(survey)) {
                state.respondStep = 'review';
                render();
            }
            break;

        case 'respond-back':
            state.respondStep = 'input';
            render();
            break;

        case 'respond-submit':
            if (survey) {
                confirmAction(
                    '回答を送信',
                    '入力した回答を送信します。送信後は回答完了画面へ進みます。',
                    '回答を送信する',
                    function () {
                        const response = {
                            id:uid('r'),
                            date:new Date().toLocaleString('ja-JP'),
                            customer:'モック回答者',
                            answers:deepClone(window.mockAnswers)
                        };
                        survey.responses = survey.responses || [];
                        survey.responses.push(response);
                        survey.answers = survey.responses.length;
                        if (survey.target == null) survey.target = survey.answers;
                        survey.updated = new Date().toISOString().slice(0,10);
                        state.respondStep = 'complete';
                        saveState();
                        render();
                    }
                );
            }
            break;

        case 'save-kintone':
            state.kintone.host = document.getElementById('kHost').value;
            state.kintone.app = document.getElementById('kApp').value;
            state.kintone.nameField = document.getElementById('kName').value;
            state.kintone.personField = document.getElementById('kPerson').value;
            state.kintone.emailField = document.getElementById('kEmail').value;
            state.kintone.updatedAt = new Date().toLocaleString('ja-JP');
            saveState();
            toast('kintone設定を保存しました。');
            break;

        case 'test-kintone':
            toast('接続確認：成功（モック）');
            break;

        case 'save-smtp':
            state.smtp.host = document.getElementById('smtpHost').value;
            state.smtp.port = document.getElementById('smtpPort').value;
            state.smtp.from = document.getElementById('smtpFrom').value;
            state.smtp.encryption = document.getElementById('smtpEncryption').value;
            state.smtp.connected = true;
            saveState();
            toast('SMTP設定を保存しました。');
            break;

        case 'test-smtp':
            toast('接続確認：成功（モック）');
            break;

        case 'modal-close':
            closeModal();
            break;

        case 'reset':
            confirmAction(
                'モックデータを初期化',
                '保存されているモックデータを初期状態へ戻します。',
                '初期化する',
                function () {
                    localStorage.removeItem(STORAGE_KEY);
                    state = deepClone(DEFAULT_STATE);
                    window.mockAnswers = {};
                    toast('初期化しました。');
                    render();
                },
                true
            );
            break;
    }
}

function filterList() {
    const filter = document.getElementById('listFilter');
    const search = document.getElementById('listSearch');
    if (!filter || !search) return;

    const status = filter.value;
    const keyword = search.value.toLowerCase();

    const rows = state.surveys.filter(s => {
        return (!status || s.status === status) &&
            (!keyword || s.name.toLowerCase().includes(keyword));
    });

    const target = document.getElementById('surveyListTable');
    if (target) target.innerHTML = listTableRows(rows);
    bindDynamic();
}

/* =========================
 * Editor Operations
 * ========================= */
function getEditorSurvey() {
    const s = currentSurvey();
    if (!s) return null;
    return s;
}

function syncEditor() {
    const s = getEditorSurvey();
    if (!s) return;

    s.name = document.getElementById('surveyName')?.value || s.name;
    s.description = document.getElementById('surveyDescription')?.value || '';
    s.start = document.getElementById('surveyStart')?.value || '';
    s.end = document.getElementById('surveyEnd')?.value || '';
    s.guide = document.getElementById('surveyGuide')?.value || '';
    s.completeMessage = document.getElementById('surveyComplete')?.value || '';
    s.numbering = document.getElementById('numbering')?.value || 'global';

    document.querySelectorAll('[data-group-id]').forEach(el => {
        const g = s.groups.find(x => x.id === el.dataset.groupId);
        if (g) g.name = el.value;
    });

    document.querySelectorAll('[data-q-text]').forEach(el => {
        const q = s.questions.find(x => x.id === el.dataset.qText);
        if (q) q.text = el.value;
    });

    document.querySelectorAll('[data-q-type]').forEach(el => {
        const q = s.questions.find(x => x.id === el.dataset.qType);
        if (q) q.type = el.value;
    });

    document.querySelectorAll('[data-q-group]').forEach(el => {
        const q = s.questions.find(x => x.id === el.dataset.qGroup);
        if (q) q.groupId = el.value;
    });

    document.querySelectorAll('[data-q-required]').forEach(el => {
        const q = s.questions.find(x => x.id === el.dataset.qRequired);
        if (q) q.required = el.checked;
    });

    document.querySelectorAll('[data-q-help]').forEach(el => {
        const q = s.questions.find(x => x.id === el.dataset.qHelp);
        if (q) q.help = el.value;
    });

    document.querySelectorAll('[data-option-text]').forEach(el => {
        const q = s.questions.find(x => x.id === el.dataset.optionText);
        if (!q) return;
        const o = q.options.find(x => x.id === el.dataset.optionId);
        if (o) o.text = el.value;
    });

    document.querySelectorAll('[data-branch-q]').forEach(el => {
        const q = s.questions.find(x => x.id === el.dataset.branchQ);
        if (!q) return;
        const o = q.options.find(x => x.id === el.dataset.branchO);
        if (!o) return;

        const value = el.value;
        if (value === 'none') {
            o.next = null;
        } else if (value === 'end') {
            o.next = {type:'end'};
        } else {
            const parts = value.split(':');
            o.next = {type:parts[0],id:parts.slice(1).join(':')};
        }
    });

    saveState();
}

function bindEditorEvents() {
    if (state.currentView !== 'edit' && state.currentView !== 'new') return;

    [
        'surveyName','surveyDescription','surveyStart','surveyEnd',
        'surveyGuide','surveyComplete','numbering'
    ].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', syncEditor);
    });

    document.querySelectorAll('[data-group-id],[data-q-text],[data-q-type],[data-q-group],[data-q-required],[data-q-help],[data-option-text],[data-branch-q]').forEach(el => {
        el.addEventListener('change', syncEditor);
    });
}

function addGroup() {
    syncEditor();
    const s = getEditorSurvey();
    if (!s) return;
    s.groups.push({id:uid('g'),name:'新しいグループ'});
    saveState();
    render();
}

function removeGroup(groupId) {
    syncEditor();
    const s = getEditorSurvey();
    if (!s) return;

    const questions = s.questions.filter(q => q.groupId === groupId);
    const action = function () {
        s.groups = s.groups.filter(g => g.id !== groupId);
        questions.forEach(q => q.groupId = s.groups[0] ? s.groups[0].id : null);
        saveState();
        render();
    };

    if (questions.length) {
        confirmAction(
            'グループを削除',
            'このグループには ' + questions.length + ' 件の質問があります。削除すると質問は別グループへ移動します。',
            'グループを削除する',
            action,
            true
        );
    } else {
        action();
    }
}

function addQuestion() {
    syncEditor();
    const s = getEditorSurvey();
    if (!s) return;

    const groupId = s.groups[0] ? s.groups[0].id : null;
    s.questions.push({
        id:uid('q'),
        groupId:groupId,
        text:'',
        type:'text',
        required:false,
        help:'',
        options:[]
    });

    saveState();
    render();
}

function removeQuestion(qid) {
    syncEditor();
    const s = getEditorSurvey();
    if (!s) return;

    const q = s.questions.find(x => x.id === qid);
    if (!q) return;

    const used = [];
    s.questions.forEach(other => {
        other.options.forEach(o => {
            if (o.next && o.next.type === 'question' && o.next.id === qid) {
                used.push(other.text || '質問');
            }
        });
    });

    let message = '「' + (q.text || '未入力の質問') + '」を削除します。';
    if (used.length) {
        message += ' この質問は分岐先として使用されています。削除すると分岐設定も見直しが必要です。';
    }

    confirmAction(
        '質問を削除',
        message,
        '質問を削除する',
        function () {
            s.questions = s.questions.filter(x => x.id !== qid);
            s.questions.forEach(other => {
                other.options.forEach(o => {
                    if (o.next && o.next.type === 'question' && o.next.id === qid) {
                        o.next = null;
                    }
                });
            });
            saveState();
            render();
        },
        true
    );
}

function moveQuestion(qid,direction) {
    syncEditor();
    const s = getEditorSurvey();
    if (!s) return;

    const index = s.questions.findIndex(q => q.id === qid);
    const next = index + direction;

    if (index < 0 || next < 0 || next >= s.questions.length) return;

    const tmp = s.questions[index];
    s.questions[index] = s.questions[next];
    s.questions[next] = tmp;

    saveState();
    render();
}

function addOption(qid) {
    syncEditor();
    const s = getEditorSurvey();
    if (!s) return;

    const q = s.questions.find(x => x.id === qid);
    if (!q) return;

    q.options.push({id:uid('o'),text:'',next:null});
    saveState();
    render();
}

function removeOption(qid,oid) {
    syncEditor();
    const s = getEditorSurvey();
    if (!s) return;

    const q = s.questions.find(x => x.id === qid);
    if (!q) return;

    q.options = q.options.filter(o => o.id !== oid);
    saveState();
    render();
}

function saveNew() {
    const temp = {
        id:uid('s'),
        name:document.getElementById('surveyName')?.value || '',
        description:document.getElementById('surveyDescription')?.value || '',
        start:document.getElementById('surveyStart')?.value || '',
        end:document.getElementById('surveyEnd')?.value || '',
        guide:document.getElementById('surveyGuide')?.value || '',
        completeMessage:document.getElementById('surveyComplete')?.value || '',
        numbering:document.getElementById('numbering')?.value || 'global',
        status:'draft',
        created:new Date().toISOString().slice(0,10),
        updated:new Date().toISOString().slice(0,10),
        sent:0,
        target:null,
        answers:0,
        groups:[{id:uid('g'),name:'基本情報'}],
        questions:[]
    };

    state.surveys.push(temp);
    state.currentSurveyId = temp.id;
    saveState();
    toast('アンケートを作成しました。');
    navigate('edit',temp.id);
}

function saveEdit(id) {
    const s = getSurvey(id);
    if (!s) return;

    syncEditor();

    const errors = [];
    if (!s.name.trim()) errors.push('アンケート名を入力してください。');
    if (s.start && s.end && new Date(s.start) >= new Date(s.end)) {
        errors.push('回答受付開始日時は終了日時より前に設定してください。');
    }

    if (errors.length) {
        openModal(
            '入力内容を確認してください',
            '<div class="notice danger"><ul>' + errors.map(e => '<li>' + esc(e) + '</li>').join('') + '</ul></div>',
            '<button class="btn" data-action="modal-close">閉じる</button>'
        );
        return;
    }

    s.updated = new Date().toISOString().slice(0,10);
    saveState();
    toast('保存しました。');

    if (s.status === 'draft') {
        navigate('preview',s.id);
    } else {
        navigate('list');
    }
}

function validateAnswers(survey) {
    let ok = true;
    const questions = respondentQuestions(survey);

    questions.forEach(q => {
        const value = window.mockAnswers[q.id];
        const empty = value == null ||
            value === '' ||
            (Array.isArray(value) && value.length === 0);

        const error = document.querySelector('[data-error-for="' + CSS.escape(q.id) + '"]');

        if (q.required && empty) {
            ok = false;
            if (error) error.textContent = 'この質問は必須です。回答を入力してください。';
        } else if (error) {
            error.textContent = '';
        }
    });

    if (!ok) {
        const first = document.querySelector('.error-text:not(:empty)');
        if (first) first.scrollIntoView({behavior:'smooth',block:'center'});
    }

    return ok;
}

/* =========================
 * Modal close / outside click
 * ========================= */
document.getElementById('modal').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});

/* =========================
 * Initial startup
 * ========================= */
window.addEventListener('beforeunload', function () {
    try {
        syncEditor();
    } catch (e) {}
});

render();

})();
</script>
</body>
</html>
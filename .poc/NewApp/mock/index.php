<?php
declare(strict_types=1);
namespace Jacic\Gojacic\QuestionnaireMock;

date_default_timezone_set('Asia/Tokyo');

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$individualToken = isset($_GET['individual']) ? trim((string)$_GET['individual']) : '';
$answerSurvey = isset($_GET['answer']) ? trim((string)$_GET['answer']) : '';
$previewSurvey = isset($_GET['preview']) ? trim((string)$_GET['preview']) : '';

if ($individualToken !== '' || $answerSurvey !== '' || $previewSurvey !== '') {
    $mode = 'respondent';
} else {
    $mode = 'admin';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif;background:#f4f6f8;color:#263238}
body{min-height:100vh}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.6}
.app{min-height:100vh}
.topbar{height:64px;background:#17324d;color:#fff;display:flex;align-items:center;padding:0 24px;gap:24px;position:sticky;top:0;z-index:30}
.logo{font-size:19px;font-weight:700;white-space:nowrap}
.topbar-sub{font-size:13px;opacity:.78}
.layout{display:flex;min-height:calc(100vh - 64px)}
.sidebar{width:230px;background:#fff;border-right:1px solid #dce2e7;padding:18px 12px;flex:none}
.nav-title{font-size:11px;color:#8a969f;padding:8px 10px 6px;font-weight:700}
.nav-btn{width:100%;border:0;background:transparent;text-align:left;padding:11px 12px;border-radius:7px;color:#40515e;margin-bottom:3px}
.nav-btn:hover,.nav-btn.active{background:#eaf2f8;color:#0b5b91;font-weight:700}
.main{flex:1;min-width:0;padding:28px}
.page{max-width:1280px;margin:0 auto}
.page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:22px}
.page-head h1{font-size:25px;margin:0 0 7px}
.page-head p{margin:0;color:#697780;font-size:14px}
.actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.btn{border:1px solid #cbd5db;background:#fff;color:#344650;border-radius:6px;padding:9px 14px;min-height:40px}
.btn:hover{background:#f3f6f8}
.btn.primary{background:#146da5;border-color:#146da5;color:#fff}
.btn.primary:hover{background:#0d5d90}
.btn.success{background:#21865b;border-color:#21865b;color:#fff}
.btn.warning{background:#b7791f;border-color:#b7791f;color:#fff}
.btn.danger{background:#b83a3a;border-color:#b83a3a;color:#fff}
.btn.small{padding:6px 10px;min-height:32px;font-size:13px}
.card{background:#fff;border:1px solid #dce2e7;border-radius:9px;box-shadow:0 1px 2px rgba(0,0,0,.03);margin-bottom:18px}
.card-head{padding:16px 18px;border-bottom:1px solid #e3e8eb;display:flex;align-items:center;justify-content:space-between;gap:12px}
.card-head h2,.card-head h3{margin:0;font-size:17px}
.card-body{padding:18px}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:12px 13px;border-bottom:1px solid #e5e9ec;text-align:left;vertical-align:middle;font-size:13px}
th{background:#f7f9fa;color:#56656e;font-weight:700;white-space:nowrap}
tr:last-child td{border-bottom:0}
.status{display:inline-flex;align-items:center;border-radius:20px;padding:4px 10px;font-size:12px;font-weight:700;white-space:nowrap}
.status.draft{background:#eef1f3;color:#5f6b72}
.status.published{background:#e5f5ec;color:#20704d}
.status.closed{background:#f4e7e7;color:#963b3b}
.status.unanswered{background:#f0f2f4;color:#65727a}
.status.info{background:#e6f0f8;color:#17608b}
.status.answered{background:#e2f4ea;color:#1d704b}
.status.input{background:#fff1dc;color:#8c5b18}
.muted{color:#7a8790}
.alert{padding:12px 14px;border-radius:7px;margin-bottom:14px}
.alert.info{background:#edf5fa;border:1px solid #cfe4f2;color:#205b7c}
.alert.ok{background:#edf8f1;border:1px solid #cde8d7;color:#216b45}
.alert.error{background:#fff0f0;border:1px solid #edcaca;color:#9a3030}
.alert.warn{background:#fff8e8;border:1px solid #efdca7;color:#7b5a12}
.toast{position:fixed;right:24px;bottom:24px;z-index:100;background:#263b48;color:#fff;padding:13px 17px;border-radius:7px;box-shadow:0 6px 22px rgba(0,0,0,.2);display:none}
.toast.show{display:block}
.grid{display:grid;gap:16px}
.grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
.grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
.kpi{padding:18px}
.kpi-label{font-size:12px;color:#76838b}
.kpi-value{font-size:29px;font-weight:700;margin-top:5px}
.form-row{display:grid;grid-template-columns:170px minmax(0,1fr);gap:15px;align-items:start;margin-bottom:16px}
.form-label{font-weight:700;font-size:13px;padding-top:9px}
input[type=text],input[type=email],input[type=password],input[type=datetime-local],input[type=number],textarea,select{
width:100%;border:1px solid #cbd5db;border-radius:6px;padding:9px 10px;background:#fff;color:#263238;outline:none
}
input:focus,textarea:focus,select:focus{border-color:#4a93c1;box-shadow:0 0 0 3px rgba(42,125,176,.1)}
textarea{min-height:100px;resize:vertical}
.checkbox-line{display:flex;align-items:center;gap:8px;padding-top:8px}
.tabbar{display:flex;gap:3px;border-bottom:1px solid #d8e0e5;margin-bottom:18px;overflow:auto}
.tab{border:0;background:transparent;padding:11px 15px;color:#63717a;white-space:nowrap;border-bottom:3px solid transparent}
.tab.active{color:#0b5f92;border-bottom-color:#1672a7;font-weight:700}
.group-box{border:1px solid #d9e1e5;border-radius:8px;margin-bottom:16px;background:#fff}
.group-head{background:#f6f8f9;padding:13px 15px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #dfe5e8}
.group-title-input{font-weight:700;flex:1}
.question-box{margin:12px;border:1px solid #e0e5e8;border-radius:7px;background:#fafcfd}
.question-head{padding:11px 12px;display:flex;gap:10px;align-items:center;border-bottom:1px solid #e4e9eb}
.drag-handle{padding:5px 8px;border:1px solid #cdd6db;border-radius:5px;background:#fff;color:#65747d;cursor:grab;font-size:12px}
.question-no{font-weight:700;color:#175f8b;white-space:nowrap}
.question-body{padding:14px}
.choice-row{display:flex;gap:7px;align-items:center;margin:7px 0}
.choice-row input{flex:1}
.branch-row{display:grid;grid-template-columns:minmax(220px,1fr) minmax(250px,1fr);gap:10px;align-items:center;margin:8px 0}
.branch-help{font-size:12px;color:#74818a;margin-top:8px}
.editor-actions{display:flex;justify-content:space-between;gap:10px;margin-top:18px}
.sticky-actions{position:sticky;bottom:0;background:#fff;border-top:1px solid #dce2e6;padding:12px 0;margin-top:18px;z-index:10}
.recipient-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
.search{max-width:320px}
.link-box{display:flex;gap:8px;align-items:center}
.link-box input{font-size:12px}
.individual-card{border:2px solid #d5e5ef;background:#f8fcff}
.individual-url{background:#eef7fc;border:1px solid #cce2ef;padding:12px;border-radius:6px;font-size:13px;word-break:break-all}
.answer-shell{max-width:900px;margin:35px auto;padding:0 18px}
.answer-header{background:#17324d;color:#fff;padding:24px;border-radius:10px 10px 0 0}
.answer-header h1{margin:0 0 8px;font-size:23px}
.answer-header p{margin:0;opacity:.85;font-size:13px}
.answer-card{background:#fff;border:1px solid #dce2e7;padding:22px;margin-bottom:14px;border-radius:8px}
.answer-card h2{font-size:16px;margin:0 0 17px}
.answer-question{padding:16px 0;border-top:1px solid #e4e9ec}
.answer-question:first-child{border-top:0;padding-top:0}
.required{color:#bd3535;font-size:12px;margin-left:5px}
.radio-row,.check-row{display:flex;gap:8px;align-items:center;margin:9px 0}
.answer-footer{text-align:center;margin:20px 0 50px}
.preview-label{background:#fff3cd;color:#775c12;padding:8px 12px;text-align:center;font-size:12px}
.modal-backdrop{position:fixed;inset:0;background:rgba(20,31,39,.5);display:none;align-items:center;justify-content:center;padding:20px;z-index:80}
.modal-backdrop.show{display:flex}
.modal{background:#fff;border-radius:9px;width:min(760px,100%);max-height:90vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.25)}
.modal-head{padding:16px 18px;border-bottom:1px solid #e0e5e8;display:flex;justify-content:space-between;align-items:center}
.modal-head h2{margin:0;font-size:18px}
.modal-body{padding:18px}
.modal-foot{padding:13px 18px;border-top:1px solid #e0e5e8;display:flex;justify-content:flex-end;gap:8px}
.close{border:0;background:transparent;font-size:24px;color:#687780}
.loading{position:relative}
.loading:after{content:"";display:inline-block;width:12px;height:12px;margin-left:7px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;vertical-align:-2px;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.empty{padding:35px;text-align:center;color:#77858d}
@media(max-width:900px){
.sidebar{width:190px}
.grid-3{grid-template-columns:1fr}
.form-row{grid-template-columns:1fr;gap:5px}
.form-label{padding-top:0}
}
@media(max-width:700px){
.topbar{padding:0 14px}
.sidebar{display:none}
.main{padding:16px}
.page-head{display:block}
.actions{justify-content:flex-start;margin-top:14px}
.grid-2{grid-template-columns:1fr}
.branch-row{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div id="app"></div>
<div id="toast" class="toast"></div>

<div id="modal" class="modal-backdrop">
  <div class="modal">
    <div class="modal-head">
      <h2 id="modalTitle">個別回答URL</h2>
      <button class="close" data-action="close-modal">×</button>
    </div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-foot">
      <button class="btn" data-action="close-modal">閉じる</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
'use strict';

const APP_MODE = <?php echo json_encode($mode, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const INDIVIDUAL_TOKEN = <?php echo json_encode($individualToken, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const ANSWER_SURVEY = <?php echo json_encode($answerSurvey, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const PREVIEW_SURVEY = <?php echo json_encode($previewSurvey, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

const STORAGE_KEY = 'questionnaire_mock_full_v3';

const $ = (selector, root=document) => root.querySelector(selector);
const $$ = (selector, root=document) => Array.from(root.querySelectorAll(selector));

function uid(prefix='id'){
    return prefix + Math.random().toString(36).slice(2,10) + Date.now().toString(36).slice(-4);
}

function escapeHtml(value){
    return String(value ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

function clone(value){
    return JSON.parse(JSON.stringify(value));
}

function showToast(message){
    const toast = $('#toast');
    if(!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => toast.classList.remove('show'), 2600);
}

function setLoading(button, loading){
    if(!button) return;
    if(loading){
        button.disabled = true;
        button.classList.add('loading');
    }else{
        button.disabled = false;
        button.classList.remove('loading');
    }
}

function nowLocal(){
    const d = new Date();
    const p = n => String(n).padStart(2,'0');
    return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

function isoNow(){
    return new Date().toISOString();
}

function seedData(){
    const surveyId = 'sv_demo_001';
    const g1 = 'g_usage';
    const g2 = 'g_comment';

    return {
        surveys:[
            {
                id:surveyId,
                name:'新サービス利用満足度調査 2026',
                description:'新サービスをご利用いただいた皆さまから、利用状況や満足度についてお伺いします。',
                status:'published',
                startAt:'2026-01-01T00:00',
                endAt:'2026-12-31T23:59',
                numberingFormat:'group',
                groups:[
                    {
                        id:g1,
                        name:'利用状況',
                        questions:[
                            {
                                id:'q_usage',
                                text:'サービスをどのくらいの頻度で利用していますか？',
                                type:'single',
                                required:true,
                                choices:[
                                    {id:'c_daily',label:'毎日'},
                                    {id:'c_weekly',label:'週に数回'},
                                    {id:'c_monthly',label:'月に数回'},
                                    {id:'c_rarely',label:'ほとんど利用しない'}
                                ],
                                branches:{}
                            },
                            {
                                id:'q_sat',
                                text:'総合的な満足度を教えてください。',
                                type:'single',
                                required:true,
                                choices:[
                                    {id:'c_vgood',label:'とても満足'},
                                    {id:'c_good',label:'満足'},
                                    {id:'c_neutral',label:'どちらともいえない'},
                                    {id:'c_bad',label:'不満'},
                                    {id:'c_vbad',label:'とても不満'}
                                ],
                                branches:{}
                            }
                        ]
                    },
                    {
                        id:g2,
                        name:'ご意見',
                        questions:[
                            {
                                id:'q_comment',
                                text:'今後改善してほしい点があれば教えてください。',
                                type:'text',
                                required:false,
                                choices:[],
                                branches:{}
                            }
                        ]
                    }
                ],
                createdAt:isoNow(),
                updatedAt:isoNow()
            },
            {
                id:'sv_demo_002',
                name:'ユーザー会参加希望アンケート',
                description:'次回ユーザー会への参加希望についてお聞かせください。',
                status:'draft',
                startAt:'2026-09-01T09:00',
                endAt:'2026-10-31T18:00',
                numberingFormat:'global',
                groups:[
                    {
                        id:'g_join',
                        name:'参加希望',
                        questions:[
                            {
                                id:'q_join',
                                text:'次回ユーザー会に参加したいですか？',
                                type:'single',
                                required:true,
                                choices:[
                                    {id:'yes',label:'参加したい'},
                                    {id:'no',label:'参加しない'}
                                ],
                                branches:{}
                            },
                            {
                                id:'q_reason',
                                text:'参加したい理由を教えてください。',
                                type:'text',
                                required:false,
                                choices:[],
                                branches:{}
                            }
                        ]
                    }
                ],
                createdAt:isoNow(),
                updatedAt:isoNow()
            }
        ],
        customers:[
            {
                id:'cust_001',
                name:'山田 太郎',
                company:'サンプル株式会社',
                department:'企画部',
                email:'taro@example.com',
                phone:'090-1111-2222',
                address:'東京都港区赤坂1-1-1'
            },
            {
                id:'cust_002',
                name:'佐藤 花子',
                company:'テスト商事',
                department:'営業部',
                email:'hanako@example.com',
                phone:'03-1234-5678',
                address:'東京都千代田区1-2-3'
            },
            {
                id:'cust_003',
                name:'鈴木 一郎',
                company:'デモ企業',
                department:'総務部',
                email:'ichiro@example.com',
                phone:'080-3333-4444',
                address:'大阪府大阪市北区4-5-6'
            }
        ],
        tokens:[
            {
                tokenId:'tok_customer_001',
                surveyId:surveyId,
                customerId:'cust_001',
                email:'taro@example.com',
                issuedAt:'2026-09-01T10:00:00+09:00',
                usedAt:null,
                status:'未回答'
            },
            {
                tokenId:'tok_customer_002',
                surveyId:surveyId,
                customerId:'cust_002',
                email:'hanako@example.com',
                issuedAt:'2026-09-01T10:05:00+09:00',
                usedAt:'2026-09-10T14:20:00+09:00',
                status:'回答済み'
            }
        ],
        individual:[
            {
                tokenId:'tok_individual_demo',
                surveyId:surveyId,
                organization:'',
                department:'',
                email:'',
                issuedAt:'2026-09-20T10:00:00+09:00',
                infoEnteredAt:null,
                answeredAt:null,
                status:'未使用',
                answers:{}
            }
        ],
        responses:[
            {
                responseId:'res_demo_001',
                surveyId:surveyId,
                tokenId:'tok_customer_002',
                respondentType:'customer',
                customerId:'cust_002',
                organization:'テスト商事',
                department:'営業部',
                email:'hanako@example.com',
                answeredAt:'2026-09-10T14:20:00+09:00',
                answers:{
                    q_usage:'c_weekly',
                    q_sat:'c_good',
                    q_comment:'説明がわかりやすく、継続して利用したいです。'
                }
            }
        ],
        sendLogs:[
            {
                tokenId:'tok_customer_001',
                surveyId:surveyId,
                email:'taro@example.com',
                status:'送信済み',
                sentAt:'2026-09-01T10:00:00+09:00',
                error:''
            },
            {
                tokenId:'tok_customer_002',
                surveyId:surveyId,
                email:'hanako@example.com',
                status:'送信済み',
                sentAt:'2026-09-01T10:05:00+09:00',
                error:''
            }
        ],
        settings:{
            smtp:{
                host:'smtp.example.jp',
                port:'587',
                encryption:'tls',
                username:'survey@example.jp',
                password:'********',
                fromEmail:'survey@example.jp',
                fromName:'アンケート事務局'
            },
            kintone:{
                subdomain:'example',
                appId:'123',
                login:'survey-user',
                password:'********',
                proxy:'proxy.example.jp:8080',
                nameField:'会社名',
                emailField:'メールアドレス',
                phoneField:'電話番号',
                addressFields:['郵便番号','都道府県','市区町村','住所']
            },
            mappingFetched:true
        }
    };
}

function loadData(){
    const raw = localStorage.getItem(STORAGE_KEY);
    if(!raw){
        const d = seedData();
        localStorage.setItem(STORAGE_KEY, JSON.stringify(d));
        return d;
    }
    try{
        const d = JSON.parse(raw);
        if(!d.individual) d.individual=[];
        if(!d.responses) d.responses=[];
        if(!d.tokens) d.tokens=[];
        if(!d.customers) d.customers=[];
        if(!d.sendLogs) d.sendLogs=[];
        return d;
    }catch(e){
        const d = seedData();
        localStorage.setItem(STORAGE_KEY, JSON.stringify(d));
        return d;
    }
}

let db = loadData();

function saveData(){
    localStorage.setItem(STORAGE_KEY, JSON.stringify(db));
}

function getSurvey(id){
    return db.surveys.find(s => s.id === id) || null;
}

function getQuestions(survey){
    const result=[];
    (survey?.groups || []).forEach((group,gi)=>{
        (group.questions || []).forEach((q,qi)=>{
            result.push({
                question:q,
                group,
                groupIndex:gi,
                questionIndex:qi
            });
        });
    });
    return result;
}

function questionNumberMap(survey){
    const map={};
    let global=1;
    (survey?.groups || []).forEach((group,gi)=>{
        (group.questions || []).forEach((q,qi)=>{
            map[q.id] = survey.numberingFormat === 'global'
                ? `Q${global++}`
                : `Q${gi+1}-${qi+1}`;
        });
    });
    return map;
}

function questionLabel(survey,q){
    const numbers = questionNumberMap(survey);
    const text = q.text?.trim() || '質問文未入力';
    const group = (survey.groups || []).find(g => (g.questions || []).some(x => x.id === q.id));
    return `${numbers[q.id] || ''}：${text}（${group?.name || 'グループ名未入力'}）`;
}

function surveyStatusLabel(status){
    if(status==='published') return '公開中';
    if(status==='closed') return '終了';
    return '下書き';
}

function surveyStatusClass(status){
    if(status==='published') return 'published';
    if(status==='closed') return 'closed';
    return 'draft';
}

function openModal(title,html){
    const modal=$('#modal');
    const titleEl=$('#modalTitle');
    const body=$('#modalBody');
    if(!modal||!titleEl||!body)return;
    titleEl.textContent=title;
    body.innerHTML=html;
    modal.classList.add('show');
}

function closeModal(){
    const modal=$('#modal');
    if(modal)modal.classList.remove('show');
}

function renderAdmin(){
    const app=$('#app');
    if(!app)return;

    app.innerHTML=`
        <div class="app">
            <div class="topbar">
                <div class="logo">アンケート管理システム</div>
                <div class="topbar-sub">運営者画面 モック</div>
            </div>
            <div class="layout">
                <aside class="sidebar">
                    <div class="nav-title">アンケート</div>
                    <button class="nav-btn active" data-page="surveys">アンケート一覧</button>
                    <button class="nav-btn" data-page="customers">顧客一覧</button>
                    <button class="nav-btn" data-page="results">回答結果</button>
                    <div class="nav-title">設定</div>
                    <button class="nav-btn" data-page="settings">送信・連携設定</button>
                </aside>
                <main class="main">
                    <div id="adminContent"></div>
                </main>
            </div>
        </div>
    `;

    const path = new URLSearchParams(location.search).get('page') || 'surveys';
    setAdminPage(path);
    bindAdminNavigation();
}

function bindAdminNavigation(){
    $$('.nav-btn').forEach(btn=>{
        if(btn.dataset.bound==='1')return;
        btn.dataset.bound='1';
        btn.addEventListener('click',()=>{
            const page=btn.dataset.page || 'surveys';
            history.replaceState({},'',`?page=${encodeURIComponent(page)}`);
            $$('.nav-btn').forEach(x=>x.classList.toggle('active',x===btn));
            setAdminPage(page);
        });
    });
}

function setAdminPage(page){
    $$('.nav-btn').forEach(btn=>btn.classList.toggle('active',btn.dataset.page===page));
    if(page==='customers') renderCustomers();
    else if(page==='results') renderResults();
    else if(page==='settings') renderSettings();
    else renderSurveys();
}

function renderSurveys(){
    const target=$('#adminContent');
    if(!target)return;

    target.innerHTML=`
        <div class="page">
            <div class="page-head">
                <div>
                    <h1>アンケート一覧</h1>
                    <p>作成・編集・公開・送信・回答状況・集計をここから管理します。</p>
                </div>
                <div class="actions">
                    <button class="btn primary" data-action="new-survey">＋ アンケートを作成</button>
                </div>
            </div>

            <div class="grid grid-3">
                <div class="card kpi"><div class="kpi-label">アンケート数</div><div class="kpi-value">${db.surveys.length}</div></div>
                <div class="card kpi"><div class="kpi-label">公開中</div><div class="kpi-value">${db.surveys.filter(x=>x.status==='published').length}</div></div>
                <div class="card kpi"><div class="kpi-label">下書き</div><div class="kpi-value">${db.surveys.filter(x=>x.status==='draft').length}</div></div>
            </div>

            <div class="card">
                <div class="card-head"><h2>アンケート</h2></div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>アンケート名</th>
                                <th>状態</th>
                                <th>公開期間</th>
                                <th>質問数</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${db.surveys.map(s=>`
                                <tr>
                                    <td>
                                        <strong>${escapeHtml(s.name || '名称未入力')}</strong>
                                        <div class="muted">${escapeHtml(s.description || '')}</div>
                                    </td>
                                    <td><span class="status ${surveyStatusClass(s.status)}">${surveyStatusLabel(s.status)}</span></td>
                                    <td>${escapeHtml(s.startAt || '')}<br>～ ${escapeHtml(s.endAt || '')}</td>
                                    <td>${getQuestions(s).length}</td>
                                    <td>
                                        <div class="actions" style="justify-content:flex-start">
                                            <button class="btn small" data-action="survey-content" data-id="${escapeHtml(s.id)}">内容</button>
                                            <button class="btn small" data-action="survey-send" data-id="${escapeHtml(s.id)}">送信</button>
                                            <button class="btn small" data-action="survey-results" data-id="${escapeHtml(s.id)}">回答状況</button>
                                            <button class="btn small" data-action="survey-aggregate" data-id="${escapeHtml(s.id)}">集計</button>
                                            <button class="btn small" data-action="survey-preview" data-id="${escapeHtml(s.id)}">回答画面</button>
                                            <button class="btn small" data-action="survey-edit" data-id="${escapeHtml(s.id)}">編集</button>
                                            ${s.status==='draft'
                                                ? `<button class="btn small primary" data-action="publish" data-id="${escapeHtml(s.id)}">公開する</button>`
                                                : s.status==='published'
                                                    ? `<button class="btn small warning" data-action="close-survey" data-id="${escapeHtml(s.id)}">終了する</button>`
                                                    : ''
                                            }
                                        </div>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="alert info">
                <strong>個別回答者にも対応しています。</strong><br>
                顧客一覧に登録されていない人には、個別URLを発行して送付できます。
                回答者はURLを開いたあと、組織名・部署名・メールアドレスを入力してから回答します。
            </div>
        </div>
    `;

    bindSurveyActions();
}

function validateSurveyForPublish(survey){
    const errors=[];
    if(!survey.name?.trim()) errors.push('アンケート名を入力してください。');
    if(!survey.groups?.length) errors.push('グループを1つ以上設定してください。');

    const qs=getQuestions(survey);
    if(!qs.length) errors.push('質問を1つ以上設定してください。');

    survey.groups.forEach((g,gi)=>{
        if(!g.name?.trim()) errors.push(`グループ${gi+1}のグループ名を入力してください。`);
        (g.questions || []).forEach((q,qi)=>{
            if(!q.text?.trim()) errors.push(`Q${gi+1}-${qi+1}の質問文を入力してください。`);
            if(q.type!=='text' && !(q.choices || []).length) errors.push(`「${q.text || `Q${gi+1}-${qi+1}`}」の選択肢を設定してください。`);
            if(q.type==='single'){
                Object.entries(q.branches || {}).forEach(([choiceId,target])=>{
                    if(!target)return;
                    if(target==='next'||target==='end')return;
                    const id=String(target).replace(/^question:/,'');
                    if(!qs.some(x=>x.question.id===id)){
                        errors.push(`「${q.text}」の分岐先が存在しません。`);
                    }
                });
            }
        });
    });
    return errors;
}

function bindSurveyActions(){
    $$('[data-action]').forEach(btn=>{
        if(btn.dataset.bound==='1')return;
        btn.dataset.bound='1';
        btn.addEventListener('click',()=>{
            const action=btn.dataset.action;
            const id=btn.dataset.id;

            if(action==='new-survey'){
                location.href='?page=editor';
            }else if(action==='survey-content'){
                location.href=`?page=survey&id=${encodeURIComponent(id)}&tab=content`;
            }else if(action==='survey-send'){
                location.href=`?page=survey&id=${encodeURIComponent(id)}&tab=send`;
            }else if(action==='survey-results'){
                location.href=`?page=survey&id=${encodeURIComponent(id)}&tab=results`;
            }else if(action==='survey-aggregate'){
                location.href=`?page=survey&id=${encodeURIComponent(id)}&tab=aggregate`;
            }else if(action==='survey-preview'){
                location.href=`?preview=${encodeURIComponent(id)}`;
            }else if(action==='survey-edit'){
                location.href=`?page=editor&id=${encodeURIComponent(id)}`;
            }else if(action==='publish'){
                const survey=getSurvey(id);
                if(!survey)return;
                const errors=validateSurveyForPublish(survey);
                if(errors.length){
                    openModal('公開できません',`
                        <div class="alert error">
                            ${errors.map(x=>`<div>・${escapeHtml(x)}</div>`).join('')}
                        </div>
                    `);
                    return;
                }
                setLoading(btn,true);
                setTimeout(()=>{
                    survey.status='published';
                    survey.updatedAt=isoNow();
                    saveData();
                    setLoading(btn,false);
                    showToast('アンケートを公開しました');
                    renderSurveys();
                },350);
            }else if(action==='close-survey'){
                if(!confirm('このアンケートを終了しますか？'))return;
                setLoading(btn,true);
                setTimeout(()=>{
                    const survey=getSurvey(id);
                    if(survey)survey.status='closed';
                    saveData();
                    setLoading(btn,false);
                    showToast('アンケートを終了しました');
                    renderSurveys();
                },350);
            }
        });
    });
}

function emptySurvey(){
    return {
        id:uid('sv_'),
        name:'',
        description:'',
        status:'draft',
        startAt:nowLocal(),
        endAt:nowLocal(),
        numberingFormat:'group',
        groups:[
            {
                id:uid('g_'),
                name:'新しいグループ',
                questions:[
                    {
                        id:uid('q_'),
                        text:'',
                        type:'single',
                        required:true,
                        choices:[
                            {id:uid('c_'),label:'選択肢1'},
                            {id:uid('c_'),label:'選択肢2'}
                        ],
                        branches:{}
                    }
                ]
            }
        ],
        createdAt:isoNow(),
        updatedAt:isoNow()
    };
}

function renderEditor(){
    const target=$('#adminContent');
    if(!target)return;

    const params=new URLSearchParams(location.search);
    const id=params.get('id');
    let survey=id ? getSurvey(id) : null;
    if(!survey) survey=emptySurvey();

    let working=clone(survey);

    function draw(){
        target.innerHTML=`
            <div class="page">
                <div class="page-head">
                    <div>
                        <h1>${id ? 'アンケート編集' : 'アンケート作成'}</h1>
                        <p>下書きでは未完成でも保存できます。公開時に内容を確認します。</p>
                    </div>
                    <div class="actions">
                        <button class="btn" data-action="editor-back">一覧へ戻る</button>
                        <button class="btn primary" data-action="save-editor">下書きを保存</button>
                        <button class="btn success" data-action="publish-editor">公開する</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><h2>基本情報</h2></div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-label">アンケート名</div>
                            <div><input type="text" data-field="name" value="${escapeHtml(working.name)}"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-label">説明</div>
                            <div><textarea data-field="description">${escapeHtml(working.description)}</textarea></div>
                        </div>
                        <div class="form-row">
                            <div class="form-label">公開開始</div>
                            <div><input type="datetime-local" data-field="startAt" value="${escapeHtml(working.startAt)}"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-label">公開終了</div>
                            <div><input type="datetime-local" data-field="endAt" value="${escapeHtml(working.endAt)}"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-label">質問番号</div>
                            <div>
                                <select data-field="numberingFormat">
                                    <option value="group" ${working.numberingFormat==='group'?'selected':''}>グループ単位：Q1-1、Q1-2、Q2-1</option>
                                    <option value="global" ${working.numberingFormat==='global'?'selected':''}>全体通し：Q1、Q2、Q3</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="groupsArea">
                    ${working.groups.map((group,gi)=>renderEditorGroup(group,gi,working)).join('')}
                </div>

                <div style="margin:16px 0 25px">
                    <button class="btn primary" data-action="add-group">＋ グループを追加</button>
                </div>

                <div class="sticky-actions">
                    <div class="actions" style="justify-content:flex-end">
                        <button class="btn" data-action="editor-back">一覧へ戻る</button>
                        <button class="btn primary" data-action="save-editor">下書きを保存</button>
                        <button class="btn success" data-action="publish-editor">公開する</button>
                    </div>
                </div>
            </div>
        `;

        bindEditor();
    }

    function renderEditorGroup(group,gi,survey){
        return `
            <div class="group-box" data-group="${escapeHtml(group.id)}">
                <div class="group-head">
                    <span style="font-weight:700">グループ ${gi+1}</span>
                    <input class="group-title-input" data-group-name="${escapeHtml(group.id)}" value="${escapeHtml(group.name)}">
                    <button class="btn small danger" data-action="delete-group" data-group-id="${escapeHtml(group.id)}">削除</button>
                </div>
                <div class="card-body">
                    ${(group.questions || []).map((q,qi)=>renderEditorQuestion(q,gi,qi,survey)).join('')}
                    <button class="btn small" data-action="add-question" data-group-id="${escapeHtml(group.id)}">＋ 質問を追加</button>
                </div>
            </div>
        `;
    }

    function renderEditorQuestion(q,gi,qi,survey){
        const numbers=questionNumberMap(survey);
        const branchChoices=(q.choices || []).map(c=>{
            const current=(q.branches || {})[c.id] || 'next';
            return `
                <div class="branch-row">
                    <div><strong>${escapeHtml(c.label || '選択肢')}</strong> のとき</div>
                    <select data-branch-question="${escapeHtml(q.id)}" data-branch-choice="${escapeHtml(c.id)}">
                        ${renderBranchOptions(survey,q,current)}
                    </select>
                </div>
            `;
        }).join('');

        return `
            <div class="question-box" data-question="${escapeHtml(q.id)}">
                <div class="question-head">
                    <span class="drag-handle" title="移動">移動</span>
                    <span class="question-no">${escapeHtml(numbers[q.id] || `Q${gi+1}-${qi+1}`)}</span>
                    <strong style="flex:1">質問</strong>
                    <button class="btn small danger" data-action="delete-question" data-group-id="${escapeHtml(survey.groups[gi].id)}" data-question-id="${escapeHtml(q.id)}">削除</button>
                </div>
                <div class="question-body">
                    <div class="form-row">
                        <div class="form-label">質問文</div>
                        <div><input type="text" data-question-text="${escapeHtml(q.id)}" value="${escapeHtml(q.text)}"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-label">回答形式</div>
                        <div>
                            <select data-question-type="${escapeHtml(q.id)}">
                                <option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
                                <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
                                <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label">必須</div>
                        <div class="checkbox-line">
                            <input type="checkbox" data-question-required="${escapeHtml(q.id)}" ${q.required?'checked':''}>
                            <span>必須回答</span>
                        </div>
                    </div>

                    ${q.type==='text' ? '' : `
                        <div class="form-row">
                            <div class="form-label">選択肢</div>
                            <div>
                                <div data-choices="${escapeHtml(q.id)}">
                                    ${(q.choices || []).map((c,ci)=>`
                                        <div class="choice-row">
                                            <span>${ci+1}.</span>
                                            <input type="text" data-choice-label="${escapeHtml(q.id)}" data-choice-id="${escapeHtml(c.id)}" value="${escapeHtml(c.label)}">
                                            <button class="btn small danger" data-action="delete-choice" data-question-id="${escapeHtml(q.id)}" data-choice-id="${escapeHtml(c.id)}">削除</button>
                                        </div>
                                    `).join('')}
                                </div>
                                <button class="btn small" data-action="add-choice" data-question-id="${escapeHtml(q.id)}">＋ 選択肢を追加</button>
                            </div>
                        </div>
                    `}

                    ${q.type==='single' && q.choices?.length ? `
                        <div class="form-row">
                            <div class="form-label">分岐</div>
                            <div>
                                <div class="alert info">
                                    分岐先は「Q番号：質問文（グループ名）」で表示します。
                                </div>
                                ${branchChoices}
                            </div>
                        </div>
                    ` : ''}

                    ${q.type!=='single' ? `
                        <div class="form-row">
                            <div class="form-label">分岐</div>
                            <div class="muted">単一選択の場合のみ設定できます。</div>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }

    function renderBranchOptions(survey,q,current){
        const options=[
            `<option value="next" ${current==='next'?'selected':''}>次の質問へ</option>`,
            `<option value="end" ${current==='end'?'selected':''}>回答終了</option>`
        ];
        getQuestions(survey).forEach(x=>{
            if(x.question.id===q.id)return;
            const value=`question:${x.question.id}`;
            options.push(`<option value="${escapeHtml(value)}" ${current===value?'selected':''}>${escapeHtml(questionLabel(survey,x.question))}</option>`);
        });
        return options.join('');
    }

    function sync(){
        const name=$('[data-field="name"]',target);
        const description=$('[data-field="description"]',target);
        const startAt=$('[data-field="startAt"]',target);
        const endAt=$('[data-field="endAt"]',target);
        const numbering=$('[data-field="numberingFormat"]',target);

        if(name)working.name=name.value;
        if(description)working.description=description.value;
        if(startAt)working.startAt=startAt.value;
        if(endAt)working.endAt=endAt.value;
        if(numbering)working.numberingFormat=numbering.value;

        working.groups.forEach(group=>{
            const gn=$(`[data-group-name="${CSS.escape(group.id)}"]`,target);
            if(gn)group.name=gn.value;

            group.questions.forEach(q=>{
                const textEl=$(`[data-question-text="${CSS.escape(q.id)}"]`,target);
                const typeEl=$(`[data-question-type="${CSS.escape(q.id)}"]`,target);
                const reqEl=$(`[data-question-required="${CSS.escape(q.id)}"]`,target);
                if(textEl)q.text=textEl.value;
                if(typeEl)q.type=typeEl.value;
                if(reqEl)q.required=reqEl.checked;

                q.choices=(q.choices || []).map(c=>{
                    const el=$(`[data-choice-label="${CSS.escape(q.id)}"][data-choice-id="${CSS.escape(c.id)}"]`,target);
                    return {...c,label:el ? el.value : c.label};
                });

                q.branches=q.branches || {};
                $$(`[data-branch-question="${CSS.escape(q.id)}"]`,target).forEach(select=>{
                    const choiceId=select.dataset.branchChoice;
                    if(choiceId)q.branches[choiceId]=select.value;
                });
            });
        });
    }

    function bindEditor(){
        $$('[data-action]').forEach(btn=>{
            if(btn.dataset.bound==='1')return;
            btn.dataset.bound='1';

            btn.addEventListener('click',()=>{
                const action=btn.dataset.action;

                if(action==='editor-back'){
                    location.href='?page=surveys';
                    return;
                }

                if(action==='save-editor'){
                    sync();
                    setLoading(btn,true);
                    setTimeout(()=>{
                        working.updatedAt=isoNow();
                        const existingIndex=db.surveys.findIndex(s=>s.id===working.id);
                        if(existingIndex>=0)db.surveys[existingIndex]=clone(working);
                        else db.surveys.push(clone(working));
                        saveData();
                        setLoading(btn,false);
                        showToast('下書きを保存しました');
                        location.href=`?page=editor&id=${encodeURIComponent(working.id)}`;
                    },350);
                    return;
                }

                if(action==='publish-editor'){
                    sync();
                    const errors=validateSurveyForPublish(working);
                    if(errors.length){
                        openModal('公開できません',`
                            <div class="alert error">
                                ${errors.map(x=>`<div>・${escapeHtml(x)}</div>`).join('')}
                            </div>
                        `);
                        return;
                    }

                    setLoading(btn,true);
                    setTimeout(()=>{
                        working.status='published';
                        working.updatedAt=isoNow();
                        const existingIndex=db.surveys.findIndex(s=>s.id===working.id);
                        if(existingIndex>=0)db.surveys[existingIndex]=clone(working);
                        else db.surveys.push(clone(working));
                        saveData();
                        setLoading(btn,false);
                        showToast('アンケートを公開しました');
                        location.href=`?page=survey&id=${encodeURIComponent(working.id)}&tab=content`;
                    },400);
                    return;
                }

                if(action==='add-group'){
                    sync();
                    working.groups.push({
                        id:uid('g_'),
                        name:`新しいグループ ${working.groups.length+1}`,
                        questions:[]
                    });
                    draw();
                    return;
                }

                if(action==='delete-group'){
                    if(working.groups.length<=1){
                        showToast('グループは1つ以上必要です');
                        return;
                    }
                    if(!confirm('このグループを削除しますか？'))return;
                    sync();
                    working.groups=working.groups.filter(g=>g.id!==btn.dataset.groupId);
                    draw();
                    return;
                }

                if(action==='add-question'){
                    sync();
                    const group=working.groups.find(g=>g.id===btn.dataset.groupId);
                    if(!group)return;
                    group.questions.push({
                        id:uid('q_'),
                        text:'',
                        type:'single',
                        required:false,
                        choices:[
                            {id:uid('c_'),label:'選択肢1'},
                            {id:uid('c_'),label:'選択肢2'}
                        ],
                        branches:{}
                    });
                    draw();
                    return;
                }

                if(action==='delete-question'){
                    sync();
                    const group=working.groups.find(g=>g.id===btn.dataset.groupId);
                    if(!group)return;
                    group.questions=group.questions.filter(q=>q.id!==btn.dataset.questionId);
                    draw();
                    return;
                }

                if(action==='add-choice'){
                    sync();
                    const q=getQuestions(working).find(x=>x.question.id===btn.dataset.questionId)?.question;
                    if(!q)return;
                    q.choices.push({id:uid('c_'),label:`選択肢${q.choices.length+1}`});
                    draw();
                    return;
                }

                if(action==='delete-choice'){
                    sync();
                    const q=getQuestions(working).find(x=>x.question.id===btn.dataset.questionId)?.question;
                    if(!q)return;
                    if(q.choices.length<=1){
                        showToast('選択肢は1つ以上必要です');
                        return;
                    }
                    const cid=btn.dataset.choiceId;
                    q.choices=q.choices.filter(c=>c.id!==cid);
                    delete q.branches[cid];
                    draw();
                    return;
                }
            });
        });

        $$('[data-question-type]').forEach(select=>{
            select.addEventListener('change',()=>{
                sync();
                const q=getQuestions(working).find(x=>x.question.id===select.dataset.questionType)?.question;
                if(!q)return;
                if(q.type==='text'){
                    q.choices=[];
                    q.branches={};
                }else if(!q.choices.length){
                    q.choices=[
                        {id:uid('c_'),label:'選択肢1'},
                        {id:uid('c_'),label:'選択肢2'}
                    ];
                }
                draw();
            });
        });
    }

    draw();
}

function renderCustomers(){
    const target=$('#adminContent');
    if(!target)return;

    target.innerHTML=`
        <div class="page">
            <div class="page-head">
                <div>
                    <h1>顧客一覧</h1>
                    <p>通常の回答者として送信する顧客を管理します。</p>
                </div>
                <div class="actions">
                    <button class="btn primary" data-action="add-customer">＋ 顧客を追加</button>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>登録顧客</h2>
                    <input class="search" id="customerSearch" type="text" placeholder="氏名・会社名・メールで検索">
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>氏名</th>
                                <th>組織名</th>
                                <th>部署</th>
                                <th>メールアドレス</th>
                                <th>電話</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="customerRows"></tbody>
                    </table>
                </div>
            </div>

            <div class="card individual-card">
                <div class="card-head">
                    <h2>顧客一覧にない人への回答</h2>
                    <button class="btn primary" data-action="issue-individual-from-customer">個別回答URLを発行</button>
                </div>
                <div class="card-body">
                    顧客一覧に登録されていない回答者には、個別URLを発行できます。
                    URLを受け取った人は、回答開始時に<strong>組織名・部署名・メールアドレス</strong>を入力してから回答します。
                </div>
            </div>
        </div>
    `;

    renderCustomerRows();

    const search=$('#customerSearch');
    if(search){
        search.addEventListener('input',renderCustomerRows);
    }

    $$('[data-action]').forEach(btn=>{
        btn.addEventListener('click',()=>{
            if(btn.dataset.action==='add-customer'){
                openModal('顧客を追加',`
                    <div class="form-row"><div class="form-label">氏名</div><div><input id="newCustomerName"></div></div>
                    <div class="form-row"><div class="form-label">組織名</div><div><input id="newCustomerCompany"></div></div>
                    <div class="form-row"><div class="form-label">部署</div><div><input id="newCustomerDepartment"></div></div>
                    <div class="form-row"><div class="form-label">メール</div><div><input id="newCustomerEmail" type="email"></div></div>
                    <div style="text-align:right"><button class="btn primary" data-action="save-new-customer">登録する</button></div>
                `);
            }
            if(btn.dataset.action==='save-new-customer'){
                const name=$('#newCustomerName')?.value.trim();
                const company=$('#newCustomerCompany')?.value.trim();
                const department=$('#newCustomerDepartment')?.value.trim();
                const email=$('#newCustomerEmail')?.value.trim();

                if(!name||!company||!email){
                    showToast('氏名・組織名・メールアドレスを入力してください');
                    return;
                }

                setLoading(btn,true);
                setTimeout(()=>{
                    db.customers.push({
                        id:uid('cust_'),
                        name,
                        company,
                        department,
                        email,
                        phone:'',
                        address:''
                    });
                    saveData();
                    setLoading(btn,false);
                    closeModal();
                    renderCustomers();
                    showToast('顧客を登録しました');
                },300);
            }
            if(btn.dataset.action==='issue-individual-from-customer'){
                openIndividualIssueModal();
            }
        });
    });
}

function renderCustomerRows(){
    const rows=$('#customerRows');
    if(!rows)return;
    const search=($('#customerSearch')?.value || '').toLowerCase();

    const list=db.customers.filter(c=>{
        const s=`${c.name} ${c.company} ${c.department} ${c.email}`.toLowerCase();
        return !search || s.includes(search);
    });

    rows.innerHTML=list.map(c=>`
        <tr>
            <td>${escapeHtml(c.name)}</td>
            <td>${escapeHtml(c.company)}</td>
            <td>${escapeHtml(c.department)}</td>
            <td>${escapeHtml(c.email)}</td>
            <td>${escapeHtml(c.phone)}</td>
            <td><button class="btn small" data-customer-id="${escapeHtml(c.id)}" data-action="customer-send">送信対象にする</button></td>
        </tr>
    `).join('');

    $$('[data-action="customer-send"]').forEach(btn=>{
        btn.addEventListener('click',()=>{
            openCustomerSendModal(btn.dataset.customerId);
        });
    });
}

function openCustomerSendModal(customerId){
    const customer=db.customers.find(c=>c.id===customerId);
    if(!customer)return;

    openModal('アンケート送信',`
        <div class="alert info">
            <strong>${escapeHtml(customer.name)}</strong><br>
            ${escapeHtml(customer.company)} / ${escapeHtml(customer.email)}
        </div>
        <div class="form-row">
            <div class="form-label">アンケート</div>
            <div>
                <select id="customerSendSurvey">
                    ${db.surveys.filter(s=>s.status==='published').map(s=>`<option value="${escapeHtml(s.id)}">${escapeHtml(s.name)}</option>`).join('')}
                </select>
            </div>
        </div>
        <div style="text-align:right">
            <button class="btn primary" id="customerSendButton">送信する</button>
        </div>
    `);

    const btn=$('#customerSendButton');
    if(btn){
        btn.addEventListener('click',()=>{
            const surveyId=$('#customerSendSurvey')?.value;
            const survey=getSurvey(surveyId);
            if(!survey)return;
            setLoading(btn,true);
            setTimeout(()=>{
                const token={
                    tokenId:uid('tok_'),
                    surveyId,
                    customerId:customer.id,
                    email:customer.email,
                    issuedAt:isoNow(),
                    usedAt:null,
                    status:'未回答'
                };
                db.tokens.push(token);
                db.sendLogs.push({
                    tokenId:token.tokenId,
                    surveyId,
                    email:customer.email,
                    status:'送信済み',
                    sentAt:isoNow(),
                    error:''
                });
                saveData();
                setLoading(btn,false);
                closeModal();
                showToast('アンケートを送信しました');
            },500);
        });
    }
}

function openIndividualIssueModal(){
    openModal('個別回答URLを発行',`
        <div class="alert info">
            顧客一覧にない人向けです。発行後に表示されるURLをメール等で回答者へ送付します。
        </div>
        <div class="form-row">
            <div class="form-label">アンケート</div>
            <div>
                <select id="individualSurvey">
                    ${db.surveys.filter(s=>s.status==='published').map(s=>`
                        <option value="${escapeHtml(s.id)}">${escapeHtml(s.name)}</option>
                    `).join('')}
                </select>
            </div>
        </div>
        <div style="text-align:right">
            <button class="btn primary" id="issueIndividualButton">URLを発行する</button>
        </div>
    `);

    const btn=$('#issueIndividualButton');
    if(btn){
        btn.addEventListener('click',()=>{
            const surveyId=$('#individualSurvey')?.value;
            if(!surveyId)return;

            setLoading(btn,true);
            setTimeout(()=>{
                const token=uid('tok_ind_');
                db.individual.push({
                    tokenId:token,
                    surveyId,
                    organization:'',
                    department:'',
                    email:'',
                    issuedAt:isoNow(),
                    infoEnteredAt:null,
                    answeredAt:null,
                    status:'未使用',
                    answers:{}
                });
                saveData();
                setLoading(btn,false);

                const url=`${location.origin}${location.pathname}?individual=${encodeURIComponent(token)}`;
                openModal('個別回答URLを発行しました',`
                    <div class="alert ok">
                        個別回答者用URLを発行しました。
                    </div>
                    <div class="form-row">
                        <div class="form-label">アンケート</div>
                        <div>${escapeHtml(getSurvey(surveyId)?.name || '')}</div>
                    </div>
                    <div class="form-row">
                        <div class="form-label">URL</div>
                        <div>
                            <div class="individual-url">${escapeHtml(url)}</div>
                            <button class="btn primary small" id="copyIndividualUrl" style="margin-top:8px">URLをコピー</button>
                        </div>
                    </div>
                    <div class="alert info">
                        回答者はこのURLを開き、最初に組織名・部署名・メールアドレスを入力してから回答します。
                    </div>
                `);

                const copy=$('#copyIndividualUrl');
                if(copy){
                    copy.addEventListener('click',()=>{
                        navigator.clipboard?.writeText(url).then(()=>{
                            showToast('URLをコピーしました');
                        }).catch(()=>{
                            showToast('URLを選択してコピーしてください');
                        });
                    });
                }
            },350);
        });
    }
}

function renderResults(){
    const target=$('#adminContent');
    if(!target)return;

    const surveyId=new URLSearchParams(location.search).get('id') || db.surveys[0]?.id;
    const survey=getSurvey(surveyId);

    if(!survey){
        target.innerHTML='<div class="page"><div class="alert error">アンケートがありません。</div></div>';
        return;
    }

    const tokens=db.tokens.filter(t=>t.surveyId===surveyId);
    const individual=db.individual.filter(t=>t.surveyId===surveyId);
    const responses=db.responses.filter(r=>r.surveyId===surveyId);

    const answeredCustomer=tokens.filter(t=>t.status==='回答済み').length;
    const answeredIndividual=individual.filter(t=>t.status==='回答済み').length;
    const total=tokens.length+individual.length;
    const answered=answeredCustomer+answeredIndividual;

    target.innerHTML=`
        <div class="page">
            <div class="page-head">
                <div>
                    <h1>回答結果</h1>
                    <p>${escapeHtml(survey.name)}</p>
                </div>
                <div class="actions">
                    <button class="btn" data-action="back-surveys">アンケート一覧</button>
                    <button class="btn primary" data-action="aggregate" data-id="${escapeHtml(survey.id)}">集計を見る</button>
                </div>
            </div>

            <div class="grid grid-3">
                <div class="card kpi"><div class="kpi-label">送信対象</div><div class="kpi-value">${total}</div></div>
                <div class="card kpi"><div class="kpi-label">回答済み</div><div class="kpi-value">${answered}</div></div>
                <div class="card kpi"><div class="kpi-label">回答率</div><div class="kpi-value">${total ? Math.round(answered/total*100) : 0}%</div></div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>送信者・回答状況一覧</h2>
                    <span class="muted">顧客一覧の回答者と個別回答者をまとめて表示</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>回答者区分</th>
                                <th>組織名</th>
                                <th>部署</th>
                                <th>メールアドレス</th>
                                <th>送信日時</th>
                                <th>回答日時</th>
                                <th>回答状況</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${renderResultRows(survey,tokens,individual)}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>回答内容</h2></div>
                <div class="card-body">
                    ${responses.length
                        ? responses.map(r=>renderResponseSummary(survey,r)).join('')
                        : '<div class="empty">まだ回答はありません。</div>'
                    }
                </div>
            </div>
        </div>
    `;

    $$('[data-action="back-surveys"]').forEach(btn=>btn.addEventListener('click',()=>location.href='?page=surveys'));
    $$('[data-action="aggregate"]').forEach(btn=>btn.addEventListener('click',()=>location.href=`?page=survey&id=${encodeURIComponent(btn.dataset.id)}&tab=aggregate`));

    $$('[data-action="copy-individual"]').forEach(btn=>{
        btn.addEventListener('click',()=>{
            const token=btn.dataset.token;
            const url=`${location.origin}${location.pathname}?individual=${encodeURIComponent(token)}`;
            navigator.clipboard?.writeText(url).then(()=>showToast('個別回答URLをコピーしました'));
        });
    });
}

function renderResultRows(survey,tokens,individual){
    const rows=[];

    tokens.forEach(t=>{
        const c=db.customers.find(x=>x.id===t.customerId);
        const response=db.responses.find(r=>r.tokenId===t.tokenId);
        rows.push(`
            <tr>
                <td>顧客一覧</td>
                <td>${escapeHtml(c?.company || '')}</td>
                <td>${escapeHtml(c?.department || '')}</td>
                <td>${escapeHtml(t.email || c?.email || '')}</td>
                <td>${escapeHtml(t.issuedAt || '')}</td>
                <td>${escapeHtml(response?.answeredAt || '')}</td>
                <td><span class="status ${response?'answered':'unanswered'}">${response?'回答済み':'未回答'}</span></td>
                <td>${response?'<span class="muted">回答あり</span>':'<button class="btn small">再送</button>'}</td>
            </tr>
        `);
    });

    individual.forEach(t=>{
        const response=db.responses.find(r=>r.tokenId===t.tokenId);
        const status=response?'回答済み':t.infoEnteredAt?'回答者情報入力済み':'未使用';
        const cls=response?'answered':t.infoEnteredAt?'input':'unanswered';

        rows.push(`
            <tr>
                <td><strong>個別回答者</strong></td>
                <td>${escapeHtml(t.organization || '未入力')}</td>
                <td>${escapeHtml(t.department || '未入力')}</td>
                <td>${escapeHtml(t.email || '未入力')}</td>
                <td>${escapeHtml(t.issuedAt || '')}</td>
                <td>${escapeHtml(response?.answeredAt || '')}</td>
                <td><span class="status ${cls}">${status}</span></td>
                <td>
                    ${!response
                        ? `<button class="btn small" data-action="copy-individual" data-token="${escapeHtml(t.tokenId)}">URLをコピー</button>`
                        : '<span class="muted">回答済み</span>'
                    }
                </td>
            </tr>
        `);
    });

    return rows.join('');
}

function renderResponseSummary(survey,response){
    const customer=response.customerId ? db.customers.find(c=>c.id===response.customerId) : null;
    return `
        <div class="card" style="box-shadow:none;border-color:#e0e6e9">
            <div class="card-head">
                <div>
                    <strong>${escapeHtml(response.organization || customer?.company || '')}</strong>
                    <span class="muted"> / ${escapeHtml(response.department || customer?.department || '')}</span>
                </div>
                <span class="muted">${escapeHtml(response.answeredAt || '')}</span>
            </div>
            <div class="card-body">
                ${getQuestions(survey).map(x=>{
                    const value=response.answers?.[x.question.id];
                    let display='';
                    if(Array.isArray(value)){
                        display=value.map(v=>x.question.choices.find(c=>c.id===v)?.label || v).join('、');
                    }else if(x.question.type!=='text'){
                        display=x.question.choices.find(c=>c.id===value)?.label || value || '';
                    }else{
                        display=value || '';
                    }
                    return `
                        <div style="padding:8px 0;border-bottom:1px solid #edf0f2">
                            <div class="muted" style="font-size:12px">${escapeHtml(questionLabel(survey,x.question))}</div>
                            <div>${escapeHtml(display || '未回答')}</div>
                        </div>
                    `;
                }).join('')}
            </div>
        </div>
    `;
}

function renderSettings(){
    const target=$('#adminContent');
    if(!target)return;

    const s=db.settings;

    target.innerHTML=`
        <div class="page">
            <div class="page-head">
                <div>
                    <h1>送信・連携設定</h1>
                    <p>メール送信設定とkintone連携設定の確認画面です。</p>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>SMTP設定</h2></div>
                <div class="card-body">
                    <div class="form-row"><div class="form-label">SMTPホスト</div><div><input id="smtpHost" value="${escapeHtml(s.smtp.host)}"></div></div>
                    <div class="form-row"><div class="form-label">ポート</div><div><input id="smtpPort" value="${escapeHtml(s.smtp.port)}"></div></div>
                    <div class="form-row"><div class="form-label">暗号化</div><div><select id="smtpEncryption">
                        <option value="none" ${s.smtp.encryption==='none'?'selected':''}>なし</option>
                        <option value="ssl" ${s.smtp.encryption==='ssl'?'selected':''}>SSL</option>
                        <option value="tls" ${s.smtp.encryption==='tls'?'selected':''}>TLS</option>
                    </select></div></div>
                    <div class="form-row"><div class="form-label">ユーザー名</div><div><input id="smtpUsername" value="${escapeHtml(s.smtp.username)}"></div></div>
                    <div class="form-row"><div class="form-label">パスワード</div><div><input id="smtpPassword" type="password" value="${escapeHtml(s.smtp.password)}"></div></div>
                    <div class="form-row"><div class="form-label">送信元メール</div><div><input id="smtpFromEmail" type="email" value="${escapeHtml(s.smtp.fromEmail)}"></div></div>
                    <div class="form-row"><div class="form-label">送信元名</div><div><input id="smtpFromName" value="${escapeHtml(s.smtp.fromName)}"></div></div>
                    <div class="actions" style="justify-content:flex-start">
                        <button class="btn primary" id="smtpSave">設定を保存</button>
                        <button class="btn" id="smtpTest">接続テスト</button>
                        <button class="btn" id="smtpTestMail">テストメール送信</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>kintone設定</h2>
                    <span class="status info">フィールド定義取得済み</span>
                </div>
                <div class="card-body">
                    <div class="form-row"><div class="form-label">サブドメイン</div><div><input id="kSubdomain" value="${escapeHtml(s.kintone.subdomain)}"></div></div>
                    <div class="form-row"><div class="form-label">アプリID</div><div><input id="kAppId" value="${escapeHtml(s.kintone.appId)}"></div></div>
                    <div class="form-row"><div class="form-label">ログイン名</div><div><input id="kLogin" value="${escapeHtml(s.kintone.login)}"></div></div>
                    <div class="form-row"><div class="form-label">パスワード</div><div><input id="kPassword" type="password" value="${escapeHtml(s.kintone.password)}"></div></div>
                    <div class="form-row"><div class="form-label">プロキシ</div><div><input id="kProxy" value="${escapeHtml(s.kintone.proxy)}" placeholder="host:port"></div></div>

                    <div class="alert info">
                        回答者情報の連携項目<br>
                        氏名・組織名・メールアドレス・電話番号・住所を個別に設定できます。<br>
                        住所は複数のkintone項目へ分けて連携できます。
                    </div>

                    <div class="form-row"><div class="form-label">氏名</div><div><input id="mapName" value="${escapeHtml(s.kintone.nameField)}"></div></div>
                    <div class="form-row"><div class="form-label">メール</div><div><input id="mapEmail" value="${escapeHtml(s.kintone.emailField)}"></div></div>
                    <div class="form-row"><div class="form-label">電話番号</div><div><input id="mapPhone" value="${escapeHtml(s.kintone.phoneField)}"></div></div>
                    <div class="form-row"><div class="form-label">住所</div><div>
                        <input id="mapAddress" value="${escapeHtml(s.kintone.addressFields.join(' / '))}">
                        <div class="branch-help">複数項目は「 / 」で並び順を指定します。</div>
                    </div></div>

                    <div class="actions" style="justify-content:flex-start">
                        <button class="btn primary" id="kSave">設定を保存</button>
                        <button class="btn" id="kFetch">フィールド定義を取得</button>
                        <button class="btn" id="kPreview">同期内容を確認</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    const smtpSave=$('#smtpSave');
    if(smtpSave){
        smtpSave.addEventListener('click',()=>{
            setLoading(smtpSave,true);
            setTimeout(()=>{
                db.settings.smtp.host=$('#smtpHost')?.value || '';
                db.settings.smtp.port=$('#smtpPort')?.value || '';
                db.settings.smtp.encryption=$('#smtpEncryption')?.value || 'tls';
                db.settings.smtp.username=$('#smtpUsername')?.value || '';
                db.settings.smtp.password=$('#smtpPassword')?.value || '';
                db.settings.smtp.fromEmail=$('#smtpFromEmail')?.value || '';
                db.settings.smtp.fromName=$('#smtpFromName')?.value || '';
                saveData();
                setLoading(smtpSave,false);
                showToast('SMTP設定を保存しました');
            },300);
        });
    }

    const smtpTest=$('#smtpTest');
    if(smtpTest){
        smtpTest.addEventListener('click',()=>{
            setLoading(smtpTest,true);
            setTimeout(()=>{
                setLoading(smtpTest,false);
                openModal('SMTP接続テスト',`
                    <div class="alert ok">
                        SMTP接続テストに成功しました。
                    </div>
                    <p class="muted">モックでは実際のメール送信は行わず、接続成功状態を確認できます。</p>
                `);
            },600);
        });
    }

    const smtpTestMail=$('#smtpTestMail');
    if(smtpTestMail){
        smtpTestMail.addEventListener('click',()=>{
            openModal('テストメール送信',`
                <div class="form-row">
                    <div class="form-label">送信先</div>
                    <div><input id="testMailAddress" type="email" placeholder="test@example.jp"></div>
                </div>
                <div style="text-align:right">
                    <button class="btn primary" id="testMailSend">送信する</button>
                </div>
            `);
            const send=$('#testMailSend');
            if(send){
                send.addEventListener('click',()=>{
                    const email=$('#testMailAddress')?.value.trim();
                    if(!email){
                        showToast('送信先メールアドレスを入力してください');
                        return;
                    }
                    setLoading(send,true);
                    setTimeout(()=>{
                        setLoading(send,false);
                        closeModal();
                        showToast('テストメールを送信しました');
                    },600);
                });
            }
        });
    }

    const kSave=$('#kSave');
    if(kSave){
        kSave.addEventListener('click',()=>{
            setLoading(kSave,true);
            setTimeout(()=>{
                db.settings.kintone.subdomain=$('#kSubdomain')?.value || '';
                db.settings.kintone.appId=$('#kAppId')?.value || '';
                db.settings.kintone.login=$('#kLogin')?.value || '';
                db.settings.kintone.password=$('#kPassword')?.value || '';
                db.settings.kintone.proxy=$('#kProxy')?.value || '';
                db.settings.kintone.nameField=$('#mapName')?.value || '';
                db.settings.kintone.emailField=$('#mapEmail')?.value || '';
                db.settings.kintone.phoneField=$('#mapPhone')?.value || '';
                db.settings.kintone.addressFields=($('#mapAddress')?.value || '').split('/').map(x=>x.trim()).filter(Boolean);
                saveData();
                setLoading(kSave,false);
                showToast('kintone設定を保存しました');
            },300);
        });
    }

    const kFetch=$('#kFetch');
    if(kFetch){
        kFetch.addEventListener('click',()=>{
            setLoading(kFetch,true);
            setTimeout(()=>{
                db.settings.mappingFetched=true;
                saveData();
                setLoading(kFetch,false);
                showToast('kintoneフィールド定義を取得しました');
            },700);
        });
    }

    const kPreview=$('#kPreview');
    if(kPreview){
        kPreview.addEventListener('click',()=>{
            openModal('kintone同期プレビュー',`
                <div class="alert info">回答者情報を以下の項目へ連携します。</div>
                <table>
                    <thead><tr><th>回答者情報</th><th>kintone項目</th></tr></thead>
                    <tbody>
                        <tr><td>氏名</td><td>${escapeHtml(s.kintone.nameField)}</td></tr>
                        <tr><td>メールアドレス</td><td>${escapeHtml(s.kintone.emailField)}</td></tr>
                        <tr><td>電話番号</td><td>${escapeHtml(s.kintone.phoneField)}</td></tr>
                        <tr><td>住所</td><td>${escapeHtml(s.kintone.addressFields.join(' / '))}</td></tr>
                    </tbody>
                </table>
            `);
        });
    }
}

function renderSurveyPage(){
    const params=new URLSearchParams(location.search);
    const id=params.get('id');
    const tab=params.get('tab') || 'content';
    const survey=getSurvey(id);

    const target=$('#adminContent');
    if(!target||!survey)return;

    target.innerHTML=`
        <div class="page">
            <div class="page-head">
                <div>
                    <h1>${escapeHtml(survey.name)}</h1>
                    <p>${escapeHtml(survey.description || '')}</p>
                </div>
                <div class="actions">
                    <span class="status ${surveyStatusClass(survey.status)}">${surveyStatusLabel(survey.status)}</span>
                    ${survey.status==='draft'
                        ? `<button class="btn primary" data-action="survey-publish-detail" data-id="${escapeHtml(survey.id)}">公開する</button>`
                        : survey.status==='published'
                            ? `<button class="btn warning" data-action="survey-close-detail" data-id="${escapeHtml(survey.id)}">終了する</button>`
                            : ''
                    }
                    <button class="btn" data-action="back-surveys">一覧へ戻る</button>
                </div>
            </div>

            <div class="tabbar">
                <button class="tab ${tab==='content'?'active':''}" data-tab="content">内容</button>
                <button class="tab ${tab==='send'?'active':''}" data-tab="send">送信</button>
                <button class="tab ${tab==='results'?'active':''}" data-tab="results">回答状況</button>
                <button class="tab ${tab==='aggregate'?'active':''}" data-tab="aggregate">集計</button>
            </div>

            <div id="surveyTabContent"></div>
        </div>
    `;

    $$('.tab').forEach(btn=>{
        btn.addEventListener('click',()=>{
            location.href=`?page=survey&id=${encodeURIComponent(id)}&tab=${encodeURIComponent(btn.dataset.tab)}`;
        });
    });

    const back=$('[data-action="back-surveys"]');
    if(back)back.addEventListener('click',()=>location.href='?page=surveys');

    const publish=$('[data-action="survey-publish-detail"]');
    if(publish){
        publish.addEventListener('click',()=>{
            const errors=validateSurveyForPublish(survey);
            if(errors.length){
                openModal('公開できません',`<div class="alert error">${errors.map(x=>`<div>・${escapeHtml(x)}</div>`).join('')}</div>`);
                return;
            }
            setLoading(publish,true);
            setTimeout(()=>{
                survey.status='published';
                saveData();
                setLoading(publish,false);
                showToast('公開しました');
                renderSurveyPage();
            },350);
        });
    }

    const close=$('[data-action="survey-close-detail"]');
    if(close){
        close.addEventListener('click',()=>{
            setLoading(close,true);
            setTimeout(()=>{
                survey.status='closed';
                saveData();
                setLoading(close,false);
                showToast('終了しました');
                renderSurveyPage();
            },350);
        });
    }

    if(tab==='send')renderSurveySendTab(survey);
    else if(tab==='results')renderSurveyResultsTab(survey);
    else if(tab==='aggregate')renderAggregateTab(survey);
    else renderSurveyContentTab(survey);
}

function renderSurveyContentTab(survey){
    const area=$('#surveyTabContent');
    if(!area)return;

    const numbers=questionNumberMap(survey);

    area.innerHTML=`
        <div class="card">
            <div class="card-head">
                <h2>アンケート内容</h2>
                <button class="btn primary" data-action="edit-survey" data-id="${escapeHtml(survey.id)}">編集</button>
            </div>
            <div class="card-body">
                <div class="grid grid-2">
                    <div>
                        <div class="muted">公開期間</div>
                        <strong>${escapeHtml(survey.startAt)} ～ ${escapeHtml(survey.endAt)}</strong>
                    </div>
                    <div>
                        <div class="muted">質問番号</div>
                        <strong>${survey.numberingFormat==='global'?'全体通し':'グループ単位'}</strong>
                    </div>
                </div>
            </div>
        </div>

        ${(survey.groups || []).map((g,gi)=>`
            <div class="group-box">
                <div class="group-head">
                    <strong>グループ ${gi+1}：${escapeHtml(g.name)}</strong>
                </div>
                <div class="card-body">
                    ${(g.questions || []).map(q=>`
                        <div style="padding:12px 0;border-bottom:1px solid #e7ebed">
                            <div class="question-no">${escapeHtml(numbers[q.id] || '')}</div>
                            <div style="font-weight:700;margin:5px 0">${escapeHtml(q.text || '質問文未入力')}</div>
                            <div class="muted">${q.type==='text'?'自由記述':q.type==='single'?'単一選択':'複数選択'} / ${q.required?'必須':'任意'}</div>
                            ${q.choices?.length ? `<div style="margin-top:7px">${q.choices.map(c=>`<span class="status info" style="margin:3px">${escapeHtml(c.label)}</span>`).join('')}</div>`:''}
                        </div>
                    `).join('')}
                </div>
            </div>
        `).join('')}
    `;

    const edit=$('[data-action="edit-survey"]');
    if(edit)edit.addEventListener('click',()=>location.href=`?page=editor&id=${encodeURIComponent(survey.id)}`);
}

function renderSurveySendTab(survey){
    const area=$('#surveyTabContent');
    if(!area)return;

    const tokens=db.tokens.filter(t=>t.surveyId===survey.id);
    const individual=db.individual.filter(t=>t.surveyId===survey.id);

    area.innerHTML=`
        <div class="card">
            <div class="card-head">
                <h2>顧客一覧への送信</h2>
                <button class="btn primary" data-action="send-all-customers">一括送信</button>
            </div>
            <div class="card-body">
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>氏名</th><th>組織名</th><th>メール</th><th>送信状況</th><th>操作</th></tr></thead>
                        <tbody>
                        ${db.customers.map(c=>{
                            const t=tokens.find(x=>x.customerId===c.id);
                            const sent=tokens.some(x=>x.customerId===c.id);
                            return `
                                <tr>
                                    <td>${escapeHtml(c.name)}</td>
                                    <td>${escapeHtml(c.company)}</td>
                                    <td>${escapeHtml(c.email)}</td>
                                    <td><span class="status ${sent?'answered':'unanswered'}">${sent?'送信済み':'未送信'}</span></td>
                                    <td><button class="btn small" data-action="send-one" data-customer="${escapeHtml(c.id)}">送信</button></td>
                                </tr>
                            `;
                        }).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card individual-card">
            <div class="card-head">
                <div>
                    <h2>個別回答者</h2>
                    <div class="muted">顧客一覧にない人へ個別URLを発行</div>
                </div>
                <button class="btn primary" data-action="issue-individual-survey">個別回答URLを発行</button>
            </div>
            <div class="card-body">
                ${individual.length ? `
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>URL発行日時</th><th>組織名</th><th>部署</th><th>メール</th><th>状態</th><th>操作</th></tr></thead>
                            <tbody>
                            ${individual.map(t=>{
                                const response=db.responses.find(r=>r.tokenId===t.tokenId);
                                const status=response?'回答済み':t.infoEnteredAt?'回答者情報入力済み':'未使用';
                                const cls=response?'answered':t.infoEnteredAt?'input':'unanswered';
                                return `
                                    <tr>
                                        <td>${escapeHtml(t.issuedAt)}</td>
                                        <td>${escapeHtml(t.organization || '未入力')}</td>
                                        <td>${escapeHtml(t.department || '未入力')}</td>
                                        <td>${escapeHtml(t.email || '未入力')}</td>
                                        <td><span class="status ${cls}">${status}</span></td>
                                        <td>
                                            <button class="btn small" data-action="copy-individual-survey" data-token="${escapeHtml(t.tokenId)}">URLをコピー</button>
                                        </td>
                                    </tr>
                                `;
                            }).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : '<div class="empty">まだ個別回答URLは発行されていません。</div>'}
            </div>
        </div>
    `;

    const issue=$('[data-action="issue-individual-survey"]');
    if(issue){
        issue.addEventListener('click',()=>{
            issueIndividualForSurvey(survey);
        });
    }

    $$('[data-action="copy-individual-survey"]').forEach(btn=>{
        btn.addEventListener('click',()=>{
            const url=`${location.origin}${location.pathname}?individual=${encodeURIComponent(btn.dataset.token)}`;
            navigator.clipboard?.writeText(url).then(()=>showToast('URLをコピーしました'));
        });
    });

    $$('[data-action="send-one"]').forEach(btn=>{
        btn.addEventListener('click',()=>{
            setLoading(btn,true);
            setTimeout(()=>{
                const customer=db.customers.find(c=>c.id===btn.dataset.customer);
                if(customer){
                    db.tokens.push({
                        tokenId:uid('tok_'),
                        surveyId:survey.id,
                        customerId:customer.id,
                        email:customer.email,
                        issuedAt:isoNow(),
                        usedAt:null,
                        status:'未回答'
                    });
                    db.sendLogs.push({
                        tokenId:db.tokens.at(-1).tokenId,
                        surveyId:survey.id,
                        email:customer.email,
                        status:'送信済み',
                        sentAt:isoNow(),
                        error:''
                    });
                    saveData();
                }
                setLoading(btn,false);
                showToast('送信しました');
                renderSurveyPage();
            },500);
        });
    });

    const all=$('[data-action="send-all-customers"]');
    if(all){
        all.addEventListener('click',()=>{
            setLoading(all,true);
            setTimeout(()=>{
                db.customers.forEach(c=>{
                    if(db.tokens.some(t=>t.surveyId===survey.id && t.customerId===c.id))return;
                    const token={
                        tokenId:uid('tok_'),
                        surveyId:survey.id,
                        customerId:c.id,
                        email:c.email,
                        issuedAt:isoNow(),
                        usedAt:null,
                        status:'未回答'
                    };
                    db.tokens.push(token);
                    db.sendLogs.push({
                        tokenId:token.tokenId,
                        surveyId:survey.id,
                        email:c.email,
                        status:'送信済み',
                        sentAt:isoNow(),
                        error:''
                    });
                });
                saveData();
                setLoading(all,false);
                showToast('一括送信しました');
                renderSurveyPage();
            },700);
        });
    }
}

function issueIndividualForSurvey(survey){
    const token=uid('tok_ind_');
    db.individual.push({
        tokenId:token,
        surveyId:survey.id,
        organization:'',
        department:'',
        email:'',
        issuedAt:isoNow(),
        infoEnteredAt:null,
        answeredAt:null,
        status:'未使用',
        answers:{}
    });
    saveData();

    const url=`${location.origin}${location.pathname}?individual=${encodeURIComponent(token)}`;

    openModal('個別回答URLを発行しました',`
        <div class="alert ok">個別回答者用URLを発行しました。</div>
        <div class="form-row"><div class="form-label">アンケート</div><div>${escapeHtml(survey.name)}</div></div>
        <div class="form-row">
            <div class="form-label">個別URL</div>
            <div>
                <div class="individual-url">${escapeHtml(url)}</div>
                <button class="btn primary small" id="copyIssuedUrl" style="margin-top:8px">URLをコピー</button>
            </div>
        </div>
        <div class="alert info">
            このURLは特定の回答者用です。回答者はURLを開くと、組織名・部署名・メールアドレスを入力してから回答します。
        </div>
    `);

    const copy=$('#copyIssuedUrl');
    if(copy){
        copy.addEventListener('click',()=>{
            navigator.clipboard?.writeText(url).then(()=>showToast('URLをコピーしました'));
        });
    }
}

function renderSurveyResultsTab(survey){
    const area=$('#surveyTabContent');
    if(!area)return;

    const tokens=db.tokens.filter(t=>t.surveyId===survey.id);
    const individuals=db.individual.filter(t=>t.surveyId===survey.id);

    area.innerHTML=`
        <div class="grid grid-3">
            <div class="card kpi"><div class="kpi-label">顧客一覧送信</div><div class="kpi-value">${tokens.length}</div></div>
            <div class="card kpi"><div class="kpi-label">個別URL発行</div><div class="kpi-value">${individuals.length}</div></div>
            <div class="card kpi"><div class="kpi-label">回答済み</div><div class="kpi-value">${db.responses.filter(r=>r.surveyId===survey.id).length}</div></div>
        </div>

        <div class="card">
            <div class="card-head"><h2>回答状況</h2></div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>区分</th><th>回答者</th><th>メール</th><th>状態</th><th>回答日時</th></tr>
                    </thead>
                    <tbody>
                        ${renderResultRows(survey,tokens,individuals)}
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

function renderAggregateTab(survey){
    const area=$('#surveyTabContent');
    if(!area)return;

    const responses=db.responses.filter(r=>r.surveyId===survey.id);

    area.innerHTML=`
        <div class="card">
            <div class="card-head">
                <h2>集計</h2>
                <span class="muted">回答済み ${responses.length}件</span>
            </div>
            <div class="card-body">
                ${getQuestions(survey).map(x=>{
                    const q=x.question;
                    if(q.type==='text'){
                        const texts=responses.map(r=>r.answers?.[q.id]).filter(Boolean);
                        return `
                            <div style="margin-bottom:25px">
                                <h3>${escapeHtml(questionLabel(survey,q))}</h3>
                                ${texts.length
                                    ? texts.map(t=>`<div class="alert info">${escapeHtml(t)}</div>`).join('')
                                    : '<div class="empty">回答なし</div>'
                                }
                            </div>
                        `;
                    }

                    const counts={};
                    q.choices.forEach(c=>counts[c.id]=0);

                    responses.forEach(r=>{
                        const value=r.answers?.[q.id];
                        if(Array.isArray(value)){
                            value.forEach(v=>{if(counts[v]!==undefined)counts[v]++;});
                        }else if(counts[value]!==undefined){
                            counts[value]++;
                        }
                    });

                    return `
                        <div style="margin-bottom:25px">
                            <h3>${escapeHtml(questionLabel(survey,q))}</h3>
                            ${q.choices.map(c=>{
                                const count=counts[c.id] || 0;
                                const rate=responses.length ? Math.round(count/responses.length*100) : 0;
                                return `
                                    <div style="margin:10px 0">
                                        <div style="display:flex;justify-content:space-between;font-size:13px">
                                            <span>${escapeHtml(c.label)}</span>
                                            <strong>${count}件 / ${rate}%</strong>
                                        </div>
                                        <div style="height:9px;background:#e9eef1;border-radius:5px;overflow:hidden;margin-top:4px">
                                            <div style="height:100%;width:${rate}%;background:#3282ae"></div>
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    `;
                }).join('')}
            </div>
        </div>
    `;
}

function renderRespondent(){
    const app=$('#app');
    if(!app)return;

    let token=null;
    let survey=null;
    let individual=false;
    let info={organization:'',department:'',email:''};

    if(INDIVIDUAL_TOKEN){
        individual=true;
        token=db.individual.find(x=>x.tokenId===INDIVIDUAL_TOKEN);
        survey=token ? getSurvey(token.surveyId) : null;
        info={
            organization:token?.organization || '',
            department:token?.department || '',
            email:token?.email || ''
        };
    }else if(ANSWER_SURVEY){
        survey=getSurvey(ANSWER_SURVEY);
    }else if(PREVIEW_SURVEY){
        survey=getSurvey(PREVIEW_SURVEY);
    }

    if(!survey){
        app.innerHTML=`
            <div class="answer-shell">
                <div class="alert error">
                    回答対象のアンケートが見つかりません。
                </div>
            </div>
        `;
        return;
    }

    if(survey.status!=='published' && !PREVIEW_SURVEY){
        app.innerHTML=`
            <div class="answer-shell">
                <div class="answer-card">
                    <h2>このアンケートは現在回答できません。</h2>
                    <p class="muted">公開期間または公開状態をご確認ください。</p>
                </div>
            </div>
        `;
        return;
    }

    if(token?.answeredAt){
        app.innerHTML=`
            <div class="answer-shell">
                <div class="answer-card">
                    <div class="alert ok">このURLではすでに回答済みです。</div>
                    <h1>${escapeHtml(survey.name)}</h1>
                    <p>回答日時：${escapeHtml(token.answeredAt)}</p>
                </div>
            </div>
        `;
        return;
    }

    if(individual && !token.infoEnteredAt){
        renderIndividualInfoStep();
        return;
    }

    renderAnswerForm();

    function renderIndividualInfoStep(){
        app.innerHTML=`
            <div class="answer-shell">
                <div class="answer-header">
                    <h1>${escapeHtml(survey.name)}</h1>
                    <p>回答者情報を入力してください</p>
                </div>

                <div class="answer-card">
                    <div class="alert info">
                        このアンケートは個別回答用URLです。<br>
                        回答を開始する前に、組織名・部署名・メールアドレスを入力してください。
                    </div>

                    <div class="form-row">
                        <div class="form-label">組織名 <span class="required">必須</span></div>
                        <div><input id="respondOrganization" type="text" value="${escapeHtml(info.organization)}"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-label">部署名 <span class="required">必須</span></div>
                        <div><input id="respondDepartment" type="text" value="${escapeHtml(info.department)}"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-label">メールアドレス <span class="required">必須</span></div>
                        <div><input id="respondEmail" type="email" value="${escapeHtml(info.email)}"></div>
                    </div>

                    <div class="answer-footer">
                        <button class="btn primary" id="startIndividualAnswer">入力して回答を開始</button>
                    </div>
                </div>
            </div>
        `;

        const start=$('#startIndividualAnswer');
        if(start){
            start.addEventListener('click',()=>{
                const organization=$('#respondOrganization')?.value.trim();
                const department=$('#respondDepartment')?.value.trim();
                const email=$('#respondEmail')?.value.trim();

                if(!organization||!department||!email){
                    showToast('組織名・部署名・メールアドレスをすべて入力してください');
                    return;
                }

                if(!email.includes('@')){
                    showToast('メールアドレスを確認してください');
                    return;
                }

                setLoading(start,true);
                setTimeout(()=>{
                    token.organization=organization;
                    token.department=department;
                    token.email=email;
                    token.infoEnteredAt=isoNow();
                    token.status='回答者情報入力済み';
                    info={organization,department,email};
                    saveData();
                    setLoading(start,false);
                    renderAnswerForm();
                },400);
            });
        }
    }

    function renderAnswerForm(){
        const questions=getQuestions(survey);
        const numbers=questionNumberMap(survey);
        const values=clone(token?.answers || {});

        app.innerHTML=`
            <div class="answer-shell">
                ${PREVIEW_SURVEY ? '<div class="preview-label">回答画面プレビュー</div>' : ''}
                <div class="answer-header">
                    <h1>${escapeHtml(survey.name)}</h1>
                    <p>${escapeHtml(survey.description || '')}</p>
                </div>

                ${individual ? `
                    <div class="answer-card">
                        <div class="muted">回答者</div>
                        <strong>${escapeHtml(info.organization)}</strong>
                        <span class="muted"> / ${escapeHtml(info.department)} / ${escapeHtml(info.email)}</span>
                    </div>
                ` : ''}

                <form id="answerForm">
                    ${survey.groups.map((g,gi)=>`
                        <div class="answer-card">
                            <h2>グループ ${gi+1}：${escapeHtml(g.name)}</h2>
                            ${(g.questions || []).map(q=>{
                                const value=values[q.id];
                                return `
                                    <div class="answer-question" data-answer-question="${escapeHtml(q.id)}">
                                        <div>
                                            <strong>${escapeHtml(numbers[q.id] || '')}：${escapeHtml(q.text || '質問文未入力')}</strong>
                                            ${q.required ? '<span class="required">必須</span>' : '<span class="muted"> 任意</span>'}
                                        </div>

                                        <div style="margin-top:12px">
                                            ${q.type==='text'
                                                ? `<textarea data-answer="${escapeHtml(q.id)}">${escapeHtml(value || '')}</textarea>`
                                                : q.type==='single'
                                                    ? q.choices.map(c=>`
                                                        <label class="radio-row">
                                                            <input type="radio" name="q_${escapeHtml(q.id)}" value="${escapeHtml(c.id)}" ${value===c.id?'checked':''}>
                                                            <span>${escapeHtml(c.label)}</span>
                                                        </label>
                                                    `).join('')
                                                    : q.choices.map(c=>`
                                                        <label class="check-row">
                                                            <input type="checkbox" name="q_${escapeHtml(q.id)}" value="${escapeHtml(c.id)}" ${Array.isArray(value)&&value.includes(c.id)?'checked':''}>
                                                            <span>${escapeHtml(c.label)}</span>
                                                        </label>
                                                    `).join('')
                                            }
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    `).join('')}

                    <div class="answer-footer">
                        ${PREVIEW_SURVEY
                            ? '<button type="button" class="btn" id="previewClose">管理画面へ戻る</button>'
                            : '<button type="submit" class="btn primary" id="submitAnswer">回答を送信する</button>'
                        }
                    </div>
                </form>
            </div>
        `;

        const previewClose=$('#previewClose');
        if(previewClose){
            previewClose.addEventListener('click',()=>location.href='?page=surveys');
        }

        const form=$('#answerForm');
        if(form && !PREVIEW_SURVEY){
            form.addEventListener('submit',(event)=>{
                event.preventDefault();

                const button=$('#submitAnswer');
                if(!button)return;

                setLoading(button,true);

                const answers={};
                questions.forEach(x=>{
                    const q=x.question;

                    if(q.type==='text'){
                        const el=$(`[data-answer="${CSS.escape(q.id)}"]`);
                        answers[q.id]=el?.value || '';
                    }else if(q.type==='single'){
                        const el=$(`input[name="q_${CSS.escape(q.id)}"]:checked`);
                        answers[q.id]=el?.value || '';
                    }else{
                        answers[q.id]=$$(`input[name="q_${CSS.escape(q.id)}"]:checked`).map(x=>x.value);
                    }
                });

                const errors=[];
                questions.forEach(x=>{
                    const q=x.question;
                    const value=answers[q.id];

                    if(q.required){
                        if(q.type==='multiple'){
                            if(!Array.isArray(value)||!value.length)errors.push(`${q.text} は必須です。`);
                        }else if(!value){
                            errors.push(`${q.text} は必須です。`);
                        }
                    }
                });

                if(errors.length){
                    setLoading(button,false);
                    openModal('入力内容を確認してください',`
                        <div class="alert error">
                            ${errors.map(x=>`<div>・${escapeHtml(x)}</div>`).join('')}
                        </div>
                    `);
                    return;
                }

                setTimeout(()=>{
                    if(individual){
                        token.answers=answers;
                        token.answeredAt=isoNow();
                        token.status='回答済み';

                        db.responses.push({
                            responseId:uid('res_'),
                            surveyId:survey.id,
                            tokenId:token.tokenId,
                            respondentType:'individual',
                            customerId:null,
                            organization:token.organization,
                            department:token.department,
                            email:token.email,
                            answeredAt:token.answeredAt,
                            answers
                        });
                        saveData();
                    }else{
                        db.responses.push({
                            responseId:uid('res_'),
                            surveyId:survey.id,
                            tokenId:null,
                            respondentType:'anonymous',
                            customerId:null,
                            organization:'',
                            department:'',
                            email:'',
                            answeredAt:isoNow(),
                            answers
                        });
                        saveData();
                    }

                    setLoading(button,false);

                    app.innerHTML=`
                        <div class="answer-shell">
                            <div class="answer-card">
                                <div class="alert ok">回答を送信しました。</div>
                                <h1>ご回答ありがとうございました</h1>
                                <p class="muted">回答内容が正常に登録されました。</p>
                            </div>
                        </div>
                    `;
                },600);
            });
        }
    }
}

function init(){
    const modal=$('#modal');
    if(modal){
        modal.addEventListener('click',event=>{
            if(event.target===modal)closeModal();
        });
    }

    $$('[data-action="close-modal"]').forEach(btn=>{
        btn.addEventListener('click',closeModal);
    });

    if(APP_MODE==='respondent'){
        renderRespondent();
        return;
    }

    renderAdmin();

    const params=new URLSearchParams(location.search);
    if(params.get('page')==='editor'){
        renderEditor();
    }else if(params.get('page')==='survey' && params.get('id')){
        renderSurveyPage();
    }
}

init();

});
</script>
</body>
</html>
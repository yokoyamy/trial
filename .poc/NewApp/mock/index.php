<?php
/*
 * アンケート業務運営アプリ モック
 * Apache + PHP 5.8 / index.php 1ファイル
 * モック確認用のため、データはブラウザ上で保持します。
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート業務運営アプリ</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif;color:#263238;background:#f5f7fa}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.app-header{height:58px;background:#263b53;color:#fff;display:flex;align-items:center;padding:0 22px;gap:28px}
.app-title{font-size:18px;font-weight:700;white-space:nowrap}
.main-menu{display:flex;height:100%;align-items:center;gap:4px}
.main-menu button{height:100%;border:0;background:transparent;color:#dbe5ef;padding:0 17px}
.main-menu button:hover,.main-menu button.active{background:#1c2e42;color:#fff}
.main{max-width:1320px;margin:0 auto;padding:24px}
.page-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.page-title h1{font-size:24px;margin:0}
.subtle{color:#6b7785;font-size:13px}
.btn{border:1px solid #c8d1dc;border-radius:6px;background:#fff;color:#334155;padding:8px 15px}
.btn:hover{background:#f1f5f9}
.btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.btn-primary:hover{background:#1d4ed8}
.btn-success{background:#168a55;color:#fff;border-color:#168a55}
.btn-danger{color:#c62828;border-color:#efb2b2}
.btn-small{padding:5px 9px;font-size:12px}
.card{background:#fff;border:1px solid #dce3eb;border-radius:8px;padding:20px;margin-bottom:18px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:13px 12px;border-bottom:1px solid #e6ebf0;font-size:14px}
th{background:#f8fafc;color:#52606d;font-weight:600}
tr:hover td{background:#fafcff}
.status{display:inline-block;padding:4px 9px;border-radius:20px;font-size:12px}
.status.draft{background:#eef2f7;color:#536273}
.status.open{background:#e3f6ed;color:#137449}
.status.end{background:#f1f1f1;color:#777}
.actions{display:flex;gap:6px;flex-wrap:wrap}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group.full{grid-column:1/-1}
label{font-size:13px;font-weight:600;color:#465564}
input[type=text],input[type=email],input[type=password],input[type=number],input[type=date],textarea,select{
 width:100%;border:1px solid #cbd5df;border-radius:6px;padding:9px 10px;background:#fff;color:#263238
}
textarea{min-height:80px;resize:vertical}
.page{display:none}
.page.active{display:block}
.toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.toolbar-left,.toolbar-right{display:flex;gap:8px;align-items:center}
.survey-tabs{display:flex;border-bottom:1px solid #d8e0e8;margin-bottom:18px}
.survey-tabs button{border:0;background:transparent;padding:11px 18px;color:#5b6773;border-bottom:3px solid transparent}
.survey-tabs button.active{color:#2563eb;border-bottom-color:#2563eb;font-weight:600}
.question-group{border:1px solid #ccd7e2;border-radius:8px;background:#fff;margin-bottom:16px}
.group-head{background:#f7f9fb;padding:11px 13px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #dce3eb}
.drag-handle{color:#8795a3;cursor:grab;font-size:18px}
.group-name{flex:1;font-weight:700}
.group-body{padding:12px}
.question{border:1px solid #d9e1e9;border-radius:7px;background:#fff;margin-bottom:10px;padding:13px}
.question.dragging{opacity:.45}
.question.drag-over{border:2px dashed #2563eb;background:#eff6ff}
.question-top{display:flex;align-items:center;gap:9px;margin-bottom:9px}
.question-number{width:30px;height:30px;border-radius:50%;background:#e9eff6;color:#38526b;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px}
.question-text{flex:1}
.question-tools{display:flex;gap:5px}
.question-fields{display:grid;grid-template-columns:2fr 1fr auto;gap:10px;align-items:start}
.required{display:flex;align-items:center;gap:6px;white-space:nowrap;padding-top:9px;font-size:13px}
.options{margin-top:10px;border-top:1px solid #edf0f3;padding-top:10px}
.option-row{display:flex;gap:6px;margin-bottom:6px}
.option-row input{flex:1}
.add-option{margin-top:3px}
.add-question,.add-group{width:100%;border:1px dashed #b8c6d4;background:#fbfcfe;color:#55708a;padding:9px;border-radius:6px}
.add-question:hover,.add-group:hover{background:#f0f6fb}
.add-group{margin-top:3px;padding:12px}
.branch-box{margin-top:10px;padding:10px;background:#fff9e8;border:1px solid #f0d48a;border-radius:6px}
.branch-row{display:flex;gap:8px;align-items:center;margin-top:6px}
.editor-footer{position:sticky;bottom:0;background:rgba(255,255,255,.96);border-top:1px solid #dce3eb;padding:12px 0;display:flex;justify-content:flex-end;gap:8px;margin-top:20px}
.notice{padding:11px 13px;border-radius:6px;background:#eef6ff;color:#28527a;margin-bottom:15px}
.success{background:#e8f7ef;color:#17683f}
.warning{background:#fff7df;color:#765a08}
.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:13px}
.metric{border:1px solid #dce3eb;border-radius:7px;padding:16px;background:#fff}
.metric .label{color:#667482;font-size:12px}
.metric .value{font-size:28px;font-weight:700;margin-top:6px}
.bar-chart{height:190px;display:flex;align-items:end;gap:12px;padding:15px;border-bottom:1px solid #ccd5de}
.bar-item{flex:1;text-align:center;font-size:11px;color:#65717c}
.bar{background:#4c8bf5;border-radius:4px 4px 0 0;min-height:5px}
.result-item{padding:14px 0;border-bottom:1px solid #e4e9ee}
.result-title{font-weight:700;margin-bottom:8px}
.result-bars{display:flex;flex-direction:column;gap:6px}
.result-bar-row{display:grid;grid-template-columns:150px 1fr 70px;gap:8px;align-items:center;font-size:13px}
.result-track{height:10px;background:#edf1f5;border-radius:10px;overflow:hidden}
.result-fill{height:100%;background:#4c8bf5}
.text-answer{padding:9px 11px;background:#f7f9fb;border-radius:5px;margin-top:5px}
.send-targets{max-height:300px;overflow:auto;border:1px solid #d6dee7;border-radius:6px}
.target-row{display:flex;gap:10px;align-items:center;padding:9px 12px;border-bottom:1px solid #edf0f3}
.target-row:last-child{border-bottom:0}
.target-info{flex:1}
.target-name{font-weight:600;font-size:13px}
.target-email{font-size:12px;color:#6d7985}
.settings-section{max-width:850px}
.preview-wrap{background:#edf1f5;padding:25px;border-radius:8px}
.respondent-page{display:none;min-height:100vh;background:#fff}
.respondent-page.active{display:block}
.respondent-body{max-width:780px;margin:0 auto;padding:45px 20px}
.respondent-title{font-size:27px;font-weight:700;margin-bottom:8px}
.respondent-question{border-top:1px solid #e0e5ea;padding:22px 0}
.respondent-question label{font-size:16px;color:#263238}
.answer-option{display:block;margin:10px 0;font-weight:400;color:#263238}
.answer-option input{margin-right:8px}
.respondent-actions{display:flex;justify-content:flex-end;margin-top:20px}
.modal-back{display:none;position:fixed;inset:0;background:rgba(20,30,40,.45);align-items:center;justify-content:center;z-index:100}
.modal-back.show{display:flex}
.modal{width:min(520px,90vw);background:#fff;border-radius:9px;padding:22px}
.modal h2{font-size:19px;margin-top:0}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}
.empty{padding:35px;text-align:center;color:#74808c}
@media(max-width:800px){
 .form-grid,.question-fields{grid-template-columns:1fr}
 .metric-grid{grid-template-columns:1fr 1fr}
 .app-header{gap:8px;overflow:auto}.main-menu button{padding:0 9px}
 .main{padding:14px}
}
</style>
</head>
<body>

<div id="operatorApp">
<header class="app-header">
 <div class="app-title">アンケート業務運営</div>
 <nav class="main-menu">
  <button id="menu-list" class="active" onclick="showPage('list')">アンケート一覧</button>
  <button id="menu-create" onclick="openCreate()">アンケート作成</button>
  <button id="menu-settings" onclick="showPage('settings')">メール設定</button>
 </nav>
</header>

<main class="main">

<section id="page-list" class="page active">
 <div class="page-title">
  <div>
   <h1>アンケート一覧</h1>
   <div class="subtle">作成済みのアンケートを管理します</div>
  </div>
  <button class="btn btn-primary" onclick="openCreate()">＋ 新しいアンケート</button>
 </div>
 <div class="card">
  <table>
   <thead><tr><th>アンケート名</th><th>状態</th><th>公開期間</th><th>回答数</th><th>最終更新</th><th>操作</th></tr></thead>
   <tbody id="survey-list"></tbody>
  </table>
 </div>
</section>

<section id="page-editor" class="page">
 <div class="page-title">
  <div><h1 id="editor-title">アンケート作成</h1><div class="subtle">アンケート全体を1画面で編集できます</div></div>
  <button class="btn" onclick="backToList()">一覧へ戻る</button>
 </div>

 <div class="card">
  <div class="form-grid">
   <div class="form-group">
    <label>アンケート名 *</label>
    <input id="survey-name" type="text" placeholder="例：商品満足度アンケート">
   </div>
   <div class="form-group">
    <label>公開状態</label>
    <select id="survey-status">
     <option value="draft">下書き</option>
     <option value="open">公開中</option>
     <option value="end">終了</option>
    </select>
   </div>
   <div class="form-group full">
    <label>説明</label>
    <textarea id="survey-desc" placeholder="回答者への説明を入力してください"></textarea>
   </div>
   <div class="form-group">
    <label>公開開始日</label>
    <input id="survey-start" type="date">
   </div>
   <div class="form-group">
    <label>公開終了日</label>
    <input id="survey-end" type="date">
   </div>
  </div>
 </div>

 <div id="editor-groups"></div>
 <div style="margin-bottom:18px">
  <button class="add-group" onclick="addGroup()">＋ グループ追加</button>
 </div>

 <div class="editor-footer">
  <button class="btn" onclick="backToList()">キャンセル</button>
  <button class="btn btn-primary" onclick="saveSurvey()">保存</button>
 </div>
</section>

<section id="page-detail" class="page">
 <div class="page-title">
  <div><h1 id="detail-title"></h1><div class="subtle" id="detail-status"></div></div>
  <button class="btn" onclick="backToList()">一覧へ戻る</button>
 </div>
 <div class="survey-tabs">
  <button id="tab-content" onclick="showDetailTab('content')">アンケート内容</button>
  <button id="tab-status" onclick="showDetailTab('status')">回答状況</button>
  <button id="tab-result" onclick="showDetailTab('result')">回答結果</button>
  <button id="tab-send" onclick="showDetailTab('send')">アンケート送信</button>
 </div>
 <div id="detail-content"></div>
</section>

<section id="page-settings" class="page">
 <div class="page-title">
  <div><h1>メール設定</h1><div class="subtle">アンケート送信用のメール設定</div></div>
 </div>
 <div class="card settings-section">
  <div class="notice">アンケートの送信には、ここで設定したメール送信先を使用します。</div>
  <div class="form-grid">
   <div class="form-group full"><label>SMTPサーバー</label><input id="smtp-host" value="smtp.example.jp"></div>
   <div class="form-group"><label>ポート</label><input id="smtp-port" value="587"></div>
   <div class="form-group"><label>暗号化</label><select id="smtp-secure"><option>STARTTLS</option><option>SSL/TLS</option><option>なし</option></select></div>
   <div class="form-group"><label>ユーザー名</label><input id="smtp-user" value="survey@example.jp"></div>
   <div class="form-group"><label>パスワード</label><input id="smtp-pass" type="password" value="********"></div>
   <div class="form-group"><label>送信者名</label><input id="smtp-from-name" value="アンケート事務局"></div>
   <div class="form-group"><label>送信元メールアドレス</label><input id="smtp-from" type="email" value="survey@example.jp"></div>
  </div>
  <div style="margin-top:18px;display:flex;gap:8px">
   <button class="btn btn-primary" onclick="saveMailSettings()">設定を保存</button>
   <button class="btn" onclick="testMail()">テスト送信</button>
  </div>
 </div>
</section>

</main>
</div>

<!-- 回答者画面：運営者メニューを持たない -->
<section id="respondentApp" class="respondent-page">
 <div class="respondent-body">
  <div class="respondent-title" id="respondent-title"></div>
  <div class="subtle" id="respondent-desc"></div>
  <div id="respondent-form" style="margin-top:30px"></div>
  <div class="respondent-actions">
   <button class="btn btn-primary" onclick="submitResponse()">回答を送信する</button>
  </div>
 </div>
</section>

<div id="modal" class="modal-back">
 <div class="modal">
  <h2 id="modal-title">確認</h2>
  <div id="modal-message"></div>
  <div class="modal-actions">
   <button class="btn" onclick="closeModal()">キャンセル</button>
   <button class="btn btn-danger" id="modal-ok">実行</button>
  </div>
 </div>
</div>

<script>
var surveys = [
 {
  id:1,name:'商品満足度アンケート',status:'open',
  desc:'商品をご利用いただいた皆様へのアンケートです。',
  start:'2026-09-01',end:'2026-09-30',answers:128,updated:'2026-09-20',
  groups:[
   {id:101,name:'基本情報',questions:[
    {id:1001,text:'今回の商品をどこで知りましたか？',type:'single',required:true,
     options:['Webサイト','紹介','店舗','その他'],branches:{}},
    {id:1002,text:'ご利用いただいたサービスについて教えてください。',type:'multi',required:true,
     options:['商品購入','問い合わせ','サポート'],branches:{}}
   ]},
   {id:102,name:'商品について',questions:[
    {id:1003,text:'商品の満足度を教えてください。',type:'rating',required:true,options:['1','2','3','4','5'],branches:{}},
    {id:1004,text:'ご意見・ご感想をお聞かせください。',type:'text',required:false,options:[],branches:{}}
   ]}
  ]
 },
 {
  id:2,name:'新サービス利用後アンケート',status:'draft',
  desc:'新サービスについてのご意見をお聞かせください。',
  start:'2026-10-01',end:'2026-10-31',answers:0,updated:'2026-09-22',
  groups:[
   {id:201,name:'サービスについて',questions:[
    {id:2001,text:'サービスを利用しましたか？',type:'single',required:true,options:['はい','いいえ'],branches:{}}
   ]}
  ]
 }
];

var currentSurveyId = null;
var editingSurvey = null;
var currentDetailTab = 'content';
var draggedQuestion = null;
var draggedGroup = null;
var modalAction = null;

var customers = [
 {id:1,name:'山田 太郎',email:'yamada@example.jp',company:'株式会社サンプル'},
 {id:2,name:'佐藤 花子',email:'sato@example.jp',company:'株式会社サンプル'},
 {id:3,name:'鈴木 一郎',email:'suzuki@example.jp',company:'株式会社テスト'},
 {id:4,name:'田中 美咲',email:'tanaka@example.jp',company:'株式会社テスト'},
 {id:5,name:'高橋 健',email:'takahashi@example.jp',company:'株式会社デモ'}
];

function clone(obj){return JSON.parse(JSON.stringify(obj));}

function showPage(name){
 document.querySelectorAll('.page').forEach(function(p){p.classList.remove('active');});
 document.querySelectorAll('.main-menu button').forEach(function(b){b.classList.remove('active');});
 var page=document.getElementById('page-'+name);
 if(page)page.classList.add('active');
 var menu=document.getElementById('menu-'+name);
 if(menu)menu.classList.add('active');
 if(name==='list')renderSurveyList();
}

function renderSurveyList(){
 var html='';
 surveys.forEach(function(s){
  var statusText=s.status==='open'?'公開中':(s.status==='end'?'終了':'下書き');
  html+='<tr>';
  html+='<td><strong>'+esc(s.name)+'</strong></td>';
  html+='<td><span class="status '+s.status+'">'+statusText+'</span></td>';
  html+='<td>'+esc(s.start||'未設定')+' ～ '+esc(s.end||'未設定')+'</td>';
  html+='<td>'+s.answers+'件</td>';
  html+='<td>'+esc(s.updated)+'</td>';
  html+='<td><div class="actions">';
  html+='<button class="btn btn-small" onclick="openDetail('+s.id+')">開く</button>';
  html+='<button class="btn btn-small" onclick="openEdit('+s.id+')">編集</button>';
  if(s.status==='open')html+='<button class="btn btn-small btn-danger" onclick="confirmEnd('+s.id+')">終了</button>';
  if(s.status==='draft')html+='<button class="btn btn-small btn-danger" onclick="confirmDelete('+s.id+')">削除</button>';
  html+='</div></td></tr>';
 });
 document.getElementById('survey-list').innerHTML=html;
}

function openCreate(){
 editingSurvey={
  id:null,name:'',status:'draft',desc:'',start:'',end:'',answers:0,updated:'',
  groups:[{id:Date.now(),name:'基本情報',questions:[newQuestion()]}]
 };
 document.getElementById('editor-title').textContent='アンケート作成';
 loadEditor();
 showPage('editor');
}

function openEdit(id){
 var s=findSurvey(id);
 if(!s)return;
 editingSurvey=clone(s);
 document.getElementById('editor-title').textContent='アンケート編集';
 loadEditor();
 showPage('editor');
}

function loadEditor(){
 document.getElementById('survey-name').value=editingSurvey.name||'';
 document.getElementById('survey-desc').value=editingSurvey.desc||'';
 document.getElementById('survey-status').value=editingSurvey.status||'draft';
 document.getElementById('survey-start').value=editingSurvey.start||'';
 document.getElementById('survey-end').value=editingSurvey.end||'';
 renderEditor();
}

function renderEditor(){
 var box=document.getElementById('editor-groups');
 var html='';
 editingSurvey.groups.forEach(function(g,gi){
  html+='<div class="question-group" data-group="'+g.id+'" draggable="true" ondragstart="groupDragStart(event,'+g.id+')" ondragover="groupDragOver(event)" ondrop="groupDrop(event,'+g.id+')">';
  html+='<div class="group-head"><span class="drag-handle">☷</span>';
  html+='<input class="group-name" value="'+escAttr(g.name)+'" onchange="updateGroupName('+g.id+',this.value)">';
  html+='<button class="btn btn-small btn-danger" onclick="deleteGroup('+g.id+')">グループ削除</button></div>';
  html+='<div class="group-body">';
  g.questions.forEach(function(q,qi){html+=renderQuestion(g,q,qi);});
  html+='<button class="add-question" onclick="addQuestion('+g.id+')">＋ 質問追加</button>';
  html+='</div></div>';
 });
 box.innerHTML=html;
}

function renderQuestion(g,q,qi){
 var h='<div class="question" draggable="true" data-question="'+q.id+'" ';
 h+='ondragstart="questionDragStart(event,'+g.id+','+q.id+')" ondragover="questionDragOver(event)" ondrop="questionDrop(event,'+g.id+','+q.id+')">';
 h+='<div class="question-top"><span class="drag-handle">☷</span><span class="question-number">'+(qi+1)+'</span>';
 h+='<input class="question-text" value="'+escAttr(q.text)+'" placeholder="質問文を入力" onchange="updateQuestion('+g.id+','+q.id+',\'text\',this.value)">';
 h+='<div class="question-tools"><button class="btn btn-small btn-danger" onclick="deleteQuestion('+g.id+','+q.id+')">削除</button></div></div>';
 h+='<div class="question-fields">';
 h+='<select onchange="updateQuestion('+g.id+','+q.id+',\'type\',this.value)">';
 ['single','multi','text','number','rating'].forEach(function(t){
  var names={single:'単一選択',multi:'複数選択',text:'記述式',number:'数値',rating:'段階評価'};
  h+='<option value="'+t+'" '+(q.type===t?'selected':'')+'>'+names[t]+'</option>';
 });
 h+='</select>';
 h+='<div class="required"><input type="checkbox" '+(q.required?'checked':'')+' onchange="updateQuestion('+g.id+','+q.id+',\'required\',this.checked)">必須</div>';
 h+='</div>';

 if(q.type==='single'||q.type==='multi'||q.type==='rating'){
  h+='<div class="options"><div class="subtle">回答選択肢</div>';
  q.options.forEach(function(o,oi){
   h+='<div class="option-row"><input value="'+escAttr(o)+'" onchange="updateOption('+g.id+','+q.id+','+oi+',this.value)"><button class="btn btn-small" onclick="removeOption('+g.id+','+q.id+','+oi+')">削除</button></div>';
  });
  if(q.type!=='rating')h+='<button class="btn btn-small add-option" onclick="addOption('+g.id+','+q.id+')">＋ 選択肢追加</button>';
  h+='</div>';
 }
 if(q.type==='single'){
  h+='<div class="branch-box"><strong>回答による次の質問への分岐</strong>';
  h+='<div class="subtle">選択肢ごとに、次に表示する質問を指定できます。</div>';
  q.options.forEach(function(o,oi){
   h+='<div class="branch-row"><span style="width:150px">'+esc(o)+'</span><span>→</span>';
   h+='<select onchange="setBranch('+g.id+','+q.id+','+oi+',this.value)">';
   h+='<option value="">次の質問へ</option>';
   g.questions.forEach(function(target,tindex){
    if(target.id!==q.id)h+='<option value="'+target.id+'" '+(String(q.branches&&q.branches[oi])===String(target.id)?'selected':'')+'>'+(tindex+1)+'：'+esc(target.text||'未入力')+'</option>';
   });
   h+='</select></div>';
  });
  h+='</div>';
 }
 h+='</div>';
 return h;
}

function newQuestion(){
 return {id:Date.now()+Math.floor(Math.random()*10000),text:'',type:'single',required:false,options:['はい','いいえ'],branches:{}};
}

function addGroup(){
 editingSurvey.groups.push({id:Date.now()+Math.floor(Math.random()*10000),name:'新しいグループ',questions:[newQuestion()]});
 renderEditor();
}

function addQuestion(gid){
 var g=findGroup(gid);
 if(g){g.questions.push(newQuestion());renderEditor();}
}

function updateGroupName(gid,value){
 var g=findGroup(gid);if(g)g.name=value;
}

function updateQuestion(gid,qid,key,value){
 var q=findQuestion(gid,qid);if(!q)return;
 q[key]=value;
 if(key==='type'){
  if(value==='text'||value==='number')q.options=[];
  if(value==='rating')q.options=['1','2','3','4','5'];
  if(value==='single'&&(!q.options||!q.options.length))q.options=['はい','いいえ'];
  if(value==='multi'&&(!q.options||!q.options.length))q.options=['選択肢1','選択肢2'];
 }
 renderEditor();
}

function addOption(gid,qid){
 var q=findQuestion(gid,qid);if(q){q.options.push('新しい選択肢');renderEditor();}
}
function removeOption(gid,qid,oi){
 var q=findQuestion(gid,qid);if(q&&q.options.length>1){q.options.splice(oi,1);renderEditor();}
}
function updateOption(gid,qid,oi,value){
 var q=findQuestion(gid,qid);if(q)q.options[oi]=value;
}
function setBranch(gid,qid,oi,value){
 var q=findQuestion(gid,qid);
 if(q){if(!q.branches)q.branches={};q.branches[oi]=value;}
}
function deleteQuestion(gid,qid){
 askConfirm('質問を削除しますか？','削除した質問の内容は戻せません。',function(){
  var g=findGroup(gid);g.questions=g.questions.filter(function(q){return q.id!==qid;});renderEditor();
 });
}
function deleteGroup(gid){
 var g=findGroup(gid);
 askConfirm('グループを削除しますか？',g.questions.length?'グループ内の質問もすべて削除されます。':'このグループを削除します。',function(){
  editingSurvey.groups=editingSurvey.groups.filter(function(x){return x.id!==gid;});renderEditor();
 });
}

function questionDragStart(e,gid,qid){
 draggedQuestion={gid:gid,qid:qid};
 draggedGroup=null;
 e.dataTransfer.effectAllowed='move';
}
function questionDragOver(e){
 e.preventDefault();
 var el=e.currentTarget;
 el.classList.add('drag-over');
 setTimeout(function(){el.classList.remove('drag-over')},150);
}
function questionDrop(e,targetGid,targetQid){
 e.preventDefault();
 if(!draggedQuestion)return;
 if(draggedQuestion.gid!==targetGid){draggedQuestion=null;return;}
 var g=findGroup(targetGid);
 var from=g.questions.findIndex(function(q){return q.id===draggedQuestion.qid});
 var to=g.questions.findIndex(function(q){return q.id===targetQid});
 if(from>=0&&to>=0&&from!==to){
  var item=g.questions.splice(from,1)[0];
  g.questions.splice(to,0,item);
  renderEditor();
 }
 draggedQuestion=null;
}
function groupDragStart(e,gid){
 draggedGroup=gid;draggedQuestion=null;e.dataTransfer.effectAllowed='move';
}
function groupDragOver(e){e.preventDefault();}
function groupDrop(e,targetGid){
 e.preventDefault();
 if(!draggedGroup||draggedGroup===targetGid)return;
 var from=editingSurvey.groups.findIndex(function(g){return g.id===draggedGroup});
 var to=editingSurvey.groups.findIndex(function(g){return g.id===targetGid});
 if(from>=0&&to>=0){
  var item=editingSurvey.groups.splice(from,1)[0];
  editingSurvey.groups.splice(to,0,item);
  renderEditor();
 }
 draggedGroup=null;
}

function saveSurvey(){
 editingSurvey.name=document.getElementById('survey-name').value.trim();
 editingSurvey.desc=document.getElementById('survey-desc').value;
 editingSurvey.status=document.getElementById('survey-status').value;
 editingSurvey.start=document.getElementById('survey-start').value;
 editingSurvey.end=document.getElementById('survey-end').value;
 if(!editingSurvey.name){alert('アンケート名を入力してください。');return;}
 var invalid=false;
 editingSurvey.groups.forEach(function(g){
  g.questions.forEach(function(q){
   if(!q.text.trim())invalid=true;
   if((q.type==='single'||q.type==='multi')&&q.options.some(function(o){return !o.trim();}))invalid=true;
  });
 });
 if(invalid){alert('質問文または選択肢に未入力があります。');return;}
 editingSurvey.updated='2026-09-24';
 if(!editingSurvey.id){
  editingSurvey.id=Date.now();surveys.push(clone(editingSurvey));
 }else{
  var index=surveys.findIndex(function(s){return s.id===editingSurvey.id;});
  if(index>=0)surveys[index]=clone(editingSurvey);
 }
 alert('保存しました。');
 showPage('list');
}

function openDetail(id){
 currentSurveyId=id;
 showPage('detail');
 showDetailTab('content');
}

function showDetailTab(tab){
 currentDetailTab=tab;
 document.querySelectorAll('.survey-tabs button').forEach(function(b){b.classList.remove('active');});
 var tabEl=document.getElementById('tab-'+tab);
 if(tabEl)tabEl.classList.add('active');
 var s=findSurvey(currentSurveyId);
 if(!s)return;
 document.getElementById('detail-title').textContent=s.name;
 document.getElementById('detail-status').textContent='状態：'+statusText(s.status);
 var box=document.getElementById('detail-content');
 if(tab==='content')renderDetailContent(s,box);
 if(tab==='status')renderStatus(s,box);
 if(tab==='result')renderResult(s,box);
 if(tab==='send')renderSend(s,box);
}

function renderDetailContent(s,box){
 var h='<div class="card"><div class="toolbar"><h2 style="margin:0">アンケート内容</h2><button class="btn btn-primary" onclick="openEdit('+s.id+')">編集</button></div>';
 h+='<p>'+esc(s.desc)+'</p>';
 s.groups.forEach(function(g){
  h+='<div style="margin-top:18px"><strong>'+esc(g.name)+'</strong>';
  g.questions.forEach(function(q,i){
   h+='<div style="padding:9px 0;border-bottom:1px solid #eee">'+(i+1)+'. '+esc(q.text)+' <span class="subtle">['+typeName(q.type)+']</span></div>';
  });
  h+='</div>';
 });
 h+='</div>';
 box.innerHTML=h;
}

function renderStatus(s,box){
 var total=200;
 var rate=Math.round((s.answers/total)*100);
 var h='<div class="metric-grid">';
 h+=metric('回答数',s.answers+'件');
 h+=metric('回答率',rate+'%');
 h+=metric('未回答',Math.max(0,total-s.answers)+'件');
 h+=metric('公開期間',(s.start||'-')+' ～ '+(s.end||'-'));
 h+='</div>';
 h+='<div class="card" style="margin-top:18px"><h2 style="font-size:18px">回答状況の推移</h2><div class="bar-chart">';
 [12,24,35,51,67,82,101,118,128].forEach(function(v,i){
  h+='<div class="bar-item"><div class="bar" style="height:'+Math.max(5,v/130*140)+'px"></div><div>9/'+(12+i)+'</div><div>'+v+'</div></div>';
 });
 h+='</div></div>';
 box.innerHTML=h;
}

function renderResult(s,box){
 var h='<div class="card"><h2 style="font-size:18px">質問ごとの回答結果</h2>';
 var colors=['#4c8bf5','#64b58b','#f0a64b','#a879e8'];
 var ci=0;
 s.groups.forEach(function(g){
  g.questions.forEach(function(q){
   h+='<div class="result-item"><div class="result-title">'+esc(q.text)+'</div>';
   if(q.type==='single'||q.type==='multi'||q.type==='rating'){
    q.options.forEach(function(o,i){
     var n=Math.max(1,Math.round(s.answers*(0.12+i*0.06)));
     var pct=Math.min(100,Math.round(n/s.answers*100));
     h+='<div class="result-bar-row"><span>'+esc(o)+'</span><div class="result-track"><div class="result-fill" style="width:'+pct+'%;background:'+colors[ci%colors.length]+'"></div></div><span>'+n+'件 ('+pct+'%)</span></div>';
     ci++;
    });
   }else if(q.type==='text'){
    h+='<div class="text-answer">とても使いやすく、満足しています。</div>';
    h+='<div class="text-answer">今後も継続して利用したいです。</div>';
    h+='<div class="text-answer">説明が分かりやすかったです。</div>';
   }else{
    h+='<div class="subtle">回答データを集計して表示します。</div>';
   }
   h+='</div>';
  });
 });
 h+='</div>';
 box.innerHTML=h;
}

function renderSend(s,box){
 var h='<div class="card"><h2 style="font-size:18px">アンケート送信</h2>';
 h+='<div class="notice">キントーンの顧客管理アプリから取得した顧客一覧を表示しています。送信対象を選択できます。顧客一覧にない方にも回答用画面を共有できます。</div>';
 h+='<div style="display:flex;gap:8px;margin-bottom:12px"><button class="btn btn-small" onclick="selectAllTargets(true)">全選択</button><button class="btn btn-small" onclick="selectAllTargets(false)">全解除</button><button class="btn btn-small" onclick="showRespondent()">回答画面を確認</button></div>';
 h+='<div class="send-targets" id="send-targets">';
 customers.forEach(function(c){
  h+='<label class="target-row"><input class="target-check" type="checkbox" value="'+c.id+'"><span class="target-info"><span class="target-name">'+esc(c.name)+' / '+esc(c.company)+'</span><br><span class="target-email">'+esc(c.email)+'</span></span></label>';
 });
 h+='</div>';
 h+='<div style="margin-top:15px"><label>メール件名</label><input id="mail-subject" value="【アンケートのお願い】'+escAttr(s.name)+'"></div>';
 h+='<div style="margin-top:10px"><label>メール本文</label><textarea id="mail-body">いつもお世話になっております。以下のアンケートへのご回答をお願いいたします。</textarea></div>';
 h+='<div style="margin-top:15px;display:flex;gap:8px"><button class="btn btn-primary" onclick="sendMail()">選択した顧客へ送信</button><button class="btn" onclick="showRespondent()">回答者画面を開く</button></div>';
 h+='</div>';
 box.innerHTML=h;
}

function selectAllTargets(flag){
 document.querySelectorAll('.target-check').forEach(function(c){c.checked=flag;});
}

function sendMail(){
 var selected=document.querySelectorAll('.target-check:checked');
 if(!selected.length){alert('送信先を選択してください。');return;}
 alert(selected.length+'名にメールを送信する操作を確認しました。\\n※モックでは実際のメールは送信されません。');
}

function showRespondent(){
 var s=findSurvey(currentSurveyId);
 if(!s)return;
 document.getElementById('operatorApp').style.display='none';
 document.getElementById('respondentApp').classList.add('active');
 document.getElementById('respondent-title').textContent=s.name;
 document.getElementById('respondent-desc').textContent=s.desc;
 var h='';
 var no=0;
 s.groups.forEach(function(g){
  h+='<div style="margin-top:28px;font-size:18px;font-weight:700">'+esc(g.name)+'</div>';
  g.questions.forEach(function(q){
   no++;
   h+='<div class="respondent-question"><label>'+no+'. '+esc(q.text)+(q.required?' <span style="color:#d33">*</span>':'')+'</label>';
   if(q.type==='single'){
    q.options.forEach(function(o,i){h+='<label class="answer-option"><input type="radio" name="q'+q.id+'" value="'+i+'">'+esc(o)+'</label>';});
   }else if(q.type==='multi'){
    q.options.forEach(function(o,i){h+='<label class="answer-option"><input type="checkbox" name="q'+q.id+'" value="'+i+'">'+esc(o)+'</label>';});
   }else if(q.type==='text'){
    h+='<textarea style="margin-top:10px;min-height:120px"></textarea>';
   }else if(q.type==='number'){
    h+='<input type="number" style="margin-top:10px">';
   }else{
    h+='<div style="display:flex;gap:10px;margin-top:12px">';
    q.options.forEach(function(o){h+='<label class="answer-option"><input type="radio" name="q'+q.id+'" value="'+o+'">'+o+'</label>';});
    h+='</div>';
   }
   h+='</div>';
  });
 });
 document.getElementById('respondent-form').innerHTML=h;
}

function submitResponse(){
 alert('回答を送信しました。ご協力ありがとうございました。');
 document.getElementById('respondentApp').classList.remove('active');
 document.getElementById('operatorApp').style.display='';
 showPage('list');
}

function backToList(){
 if(editingSurvey&&editingSurvey.id===null){
  editingSurvey=null;
 }
 showPage('list');
}

function confirmEnd(id){
 askConfirm('アンケートを終了しますか？','終了すると回答受付を終了します。',function(){
  var s=findSurvey(id);if(s){s.status='end';s.updated='2026-09-24';renderSurveyList();}
 });
}
function confirmDelete(id){
 askConfirm('下書きを削除しますか？','削除したアンケートは一覧からなくなります。',function(){
  surveys=surveys.filter(function(s){return s.id!==id;});renderSurveyList();
 });
}

function askConfirm(title,message,fn){
 document.getElementById('modal-title').textContent=title;
 document.getElementById('modal-message').textContent=message;
 modalAction=fn;
 document.getElementById('modal').classList.add('show');
 document.getElementById('modal-ok').onclick=function(){closeModal();if(modalAction)modalAction();};
}
function closeModal(){document.getElementById('modal').classList.remove('show');modalAction=null;}

function saveMailSettings(){alert('メール設定を保存しました。');}
function testMail(){alert('テスト送信を実行する画面です。\\n※モックでは実際の送信は行いません。');}

function findSurvey(id){return surveys.find(function(s){return s.id===id;});}
function findGroup(id){return editingSurvey.groups.find(function(g){return g.id===id;});}
function findQuestion(gid,qid){
 var g=findGroup(gid);return g?g.questions.find(function(q){return q.id===qid;}):null;
}
function statusText(s){return s==='open'?'公開中':(s==='end'?'終了':'下書き');}
function typeName(t){return {single:'単一選択',multi:'複数選択',text:'記述式',number:'数値',rating:'段階評価'}[t]||t;}
function metric(label,value){return '<div class="metric"><div class="label">'+label+'</div><div class="value">'+value+'</div></div>';}
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function escAttr(s){return esc(s);}

document.addEventListener('click',function(e){
 if(!e.target.closest('.question'))document.querySelectorAll('.drag-over').forEach(function(x){x.classList.remove('drag-over');});
});

renderSurveyList();
</script>
</body>
</html>

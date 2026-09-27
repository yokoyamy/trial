<?php
declare(strict_types=1);

namespace Yokoyamy\QuestionnaireOperationMock;

header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート運営アプリ モック</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:"Hiragino Kaku Gothic ProN","Yu Gothic","Meiryo",sans-serif;
  background:#f4f6f9;
  color:#333;
  line-height:1.5;
}
button,input,select,textarea{font-family:inherit}
button{cursor:pointer}
.main-header{
  position:sticky;
  top:0;
  z-index:1000;
  height:58px;
  display:flex;
  align-items:center;
  gap:32px;
  padding:0 22px;
  background:#263746;
  color:#fff;
  box-shadow:0 2px 5px rgba(0,0,0,.15);
}
.logo{
  flex:0 0 auto;
  font-size:15px;
  font-weight:700;
  white-space:nowrap;
}
.main-nav{
  display:flex;
  align-items:stretch;
  height:100%;
  gap:4px;
}
.main-nav a{
  display:flex;
  align-items:center;
  padding:0 15px;
  color:#cbd5df;
  text-decoration:none;
  font-size:13px;
  cursor:pointer;
  border-bottom:3px solid transparent;
  white-space:nowrap;
}
.main-nav a:hover,
.main-nav a.active{
  color:#fff;
  background:#304758;
  border-bottom-color:#4aa3ff;
}
.page{
  display:none;
  max-width:1240px;
  margin:0 auto;
  padding:26px 24px 80px;
}
.page.active{display:block}
.page-title{
  font-size:22px;
  margin-bottom:18px;
}
.page-subtitle{
  color:#777;
  font-size:13px;
  margin-top:-10px;
  margin-bottom:18px;
}
.toolbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:16px;
}
.toolbar-left,
.toolbar-right{
  display:flex;
  align-items:center;
  gap:8px;
}
.card{
  background:#fff;
  border:1px solid #e2e6ea;
  border-radius:7px;
  padding:20px;
  margin-bottom:16px;
  box-shadow:0 1px 3px rgba(0,0,0,.05);
}
.card h3{
  font-size:16px;
  margin-bottom:14px;
}
.btn{
  border:0;
  border-radius:5px;
  padding:9px 16px;
  background:#347fc4;
  color:#fff;
  font-size:13px;
  min-height:36px;
}
.btn:hover{filter:brightness(.95)}
.btn.secondary{
  background:#eef1f4;
  color:#333;
  border:1px solid #d7dce1;
}
.btn.success{background:#2d9b63}
.btn.warning{background:#d98a19}
.btn.danger{background:#d9534f}
.btn.small{
  min-height:30px;
  padding:5px 10px;
  font-size:12px;
}
.btn.loading{
  position:relative;
  color:transparent!important;
  pointer-events:none;
}
.btn.loading:after{
  content:"";
  position:absolute;
  width:14px;
  height:14px;
  left:50%;
  top:50%;
  margin:-7px 0 0 -7px;
  border:2px solid rgba(255,255,255,.45);
  border-top-color:#fff;
  border-radius:50%;
  animation:spin .7s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}
.badge{
  display:inline-block;
  padding:3px 9px;
  border-radius:12px;
  font-size:11px;
  white-space:nowrap;
}
.badge.draft{background:#e7eaed;color:#56616b}
.badge.open{background:#d9f3e4;color:#207848}
.badge.closed{background:#e4e6e8;color:#555}
.badge.error{background:#fde0df;color:#a92d29}
.badge.info{background:#dcecff;color:#235b91}
.badge.used{background:#fff0d7;color:#9a5a00}
table{
  width:100%;
  border-collapse:collapse;
  background:#fff;
  border:1px solid #e0e4e8;
}
th,td{
  padding:11px 12px;
  border-bottom:1px solid #e9ecef;
  font-size:13px;
  text-align:left;
  vertical-align:middle;
}
th{
  background:#f1f3f5;
  font-weight:700;
  white-space:nowrap;
}
tr:hover td{background:#fafcff}
.action-link{
  color:#2777b7;
  cursor:pointer;
  text-decoration:underline;
  font-size:12px;
  margin-right:10px;
  white-space:nowrap;
}
.action-link.danger{color:#c43b37}
.form-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:14px 18px;
}
.form-group{margin-bottom:14px}
.form-group.full{grid-column:1/-1}
.form-group label{
  display:block;
  color:#5d6670;
  font-size:12px;
  margin-bottom:5px;
}
input[type=text],
input[type=email],
input[type=password],
input[type=datetime-local],
input[type=number],
select,
textarea{
  width:100%;
  padding:9px 10px;
  border:1px solid #cfd5da;
  border-radius:5px;
  background:#fff;
  font-size:13px;
  color:#333;
}
textarea{resize:vertical}
input:focus,
select:focus,
textarea:focus{
  outline:0;
  border-color:#4aa3ff;
  box-shadow:0 0 0 2px rgba(74,163,255,.12);
}
.notice{
  padding:12px 14px;
  border-radius:5px;
  font-size:13px;
  margin-bottom:14px;
}
.notice.info{
  background:#edf6ff;
  border:1px solid #c9e3fb;
  color:#285d87;
}
.notice.warning{
  background:#fff7e8;
  border:1px solid #f0d39b;
  color:#765316;
}
.notice.error{
  background:#fff;
  border:2px solid #d9534f;
  color:#9f2722;
}
.notice.success{
  background:#fff;
  border:2px solid #38a169;
  color:#246b47;
}
.message-area{
  position:fixed;
  left:20px;
  right:20px;
  bottom:18px;
  z-index:2000;
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:8px;
  pointer-events:none;
}
.message{
  width:min(900px,calc(100vw - 40px));
  background:#fff;
  border:2px solid #d9534f;
  color:#8e2622;
  border-radius:6px;
  padding:11px 14px;
  box-shadow:0 3px 14px rgba(0,0,0,.18);
  pointer-events:auto;
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:14px;
  font-size:13px;
}
.message.success{
  border-color:#38a169;
  color:#236746;
}
.message button{
  border:0;
  background:transparent;
  font-size:18px;
  color:inherit;
}
.breadcrumb{
  color:#66727d;
  font-size:12px;
  margin-bottom:10px;
  cursor:pointer;
}
.tabs{
  display:flex;
  border-bottom:2px solid #dce1e5;
  margin-bottom:18px;
  overflow-x:auto;
}
.tab{
  padding:10px 18px;
  color:#68737d;
  font-size:13px;
  cursor:pointer;
  white-space:nowrap;
  border-bottom:3px solid transparent;
}
.tab.active{
  color:#287bbd;
  font-weight:700;
  border-bottom-color:#347fc4;
}
.tab-content{display:none}
.tab-content.active{display:block}
.summary-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:12px;
  margin-bottom:16px;
}
.summary-card{
  background:#fff;
  border:1px solid #e2e6ea;
  border-radius:7px;
  padding:17px;
  text-align:center;
}
.summary-card .num{
  font-size:25px;
  font-weight:700;
  color:#263746;
}
.summary-card .label{
  color:#78828b;
  font-size:11px;
  margin-top:3px;
}
.status-table td,
.status-table th{font-size:12px}
.editor-actions{
  position:sticky;
  bottom:0;
  z-index:20;
  background:#fff;
  border-top:1px solid #dfe4e8;
  padding:12px 0;
  margin-top:20px;
  display:flex;
  justify-content:flex-end;
  gap:8px;
}
.group-box{
  background:#fafbfc;
  border:1px solid #d9dee3;
  border-radius:7px;
  padding:15px;
  margin-bottom:15px;
}
.group-header{
  display:flex;
  align-items:center;
  gap:10px;
  margin-bottom:12px;
}
.drag-handle{
  cursor:grab;
  color:#89939d;
  font-size:17px;
  user-select:none;
}
.group-name{
  flex:1;
  font-weight:700;
}
.question-box{
  background:#fff;
  border:1px solid #dfe3e7;
  border-radius:6px;
  padding:13px;
  margin-bottom:10px;
}
.question-head{
  display:flex;
  align-items:flex-start;
  gap:10px;
}
.question-main{
  flex:1;
}
.question-title-row{
  display:flex;
  align-items:center;
  gap:8px;
  margin-bottom:8px;
}
.question-number{
  color:#287bbd;
  font-weight:700;
  min-width:45px;
}
.question-text{
  flex:1;
  font-weight:600;
}
.question-tools{
  display:flex;
  gap:5px;
  align-items:center;
}
.question-meta{
  color:#777;
  font-size:11px;
  margin-bottom:8px;
}
.choice-list{
  margin:8px 0 0 45px;
}
.choice-row{
  display:flex;
  align-items:center;
  gap:7px;
  margin-bottom:6px;
}
.choice-id{
  width:42px;
  color:#777;
  font-size:11px;
}
.choice-row input{
  flex:1;
}
.branch-target{
  width:250px;
}
.move-select{
  width:150px;
}
.add-row{
  margin:8px 0 0 45px;
}
.link-button{
  border:0;
  background:transparent;
  color:#287bbd;
  font-size:12px;
  cursor:pointer;
  padding:2px 0;
}
.group-add{
  width:100%;
  padding:13px;
  border:2px dashed #b8c4ce;
  border-radius:6px;
  background:#fff;
  color:#287bbd;
  font-size:13px;
  cursor:pointer;
}
.question-editor{
  display:none;
  background:#f8fafc;
  border:1px solid #cbd7e0;
  padding:14px;
  margin-top:10px;
  border-radius:6px;
}
.question-editor.open{display:block}
.send-grid{
  display:grid;
  grid-template-columns:1.1fr .9fr;
  gap:16px;
}
.customer-select-list{
  border:1px solid #dfe4e8;
  border-radius:6px;
  max-height:300px;
  overflow:auto;
}
.customer-row{
  display:flex;
  align-items:center;
  gap:9px;
  padding:9px 11px;
  border-bottom:1px solid #edf0f2;
  font-size:12px;
}
.customer-row:last-child{border-bottom:0}
.customer-row .customer-main{flex:1}
.customer-row .customer-sub{
  display:block;
  color:#888;
  font-size:11px;
}
.url-box{
  display:flex;
  gap:8px;
}
.url-box input{flex:1}
.chart-row{
  display:flex;
  align-items:center;
  gap:10px;
  margin-bottom:9px;
  font-size:12px;
}
.chart-label{
  width:150px;
  flex:0 0 150px;
}
.chart-bg{
  flex:1;
  height:16px;
  background:#e9edf0;
  border-radius:4px;
  overflow:hidden;
}
.chart-fill{
  height:100%;
  background:#4aa3ff;
}
.chart-value{
  width:75px;
  text-align:right;
}
.settings-nav{
  display:flex;
  gap:6px;
  margin-bottom:16px;
}
.settings-nav button.active{
  background:#347fc4;
  color:#fff;
}
.respondent-wrap{
  min-height:calc(100vh - 58px);
  background:#eef2f5;
  padding:38px 20px 60px;
}
.respondent-page{
  max-width:680px;
  margin:0 auto;
  background:#fff;
  border:1px solid #dde2e6;
  border-radius:9px;
  padding:30px;
  box-shadow:0 3px 14px rgba(0,0,0,.07);
}
.respondent-page h2{
  font-size:21px;
  margin-bottom:7px;
}
.respondent-desc{
  color:#707a83;
  font-size:13px;
  margin-bottom:24px;
}
.respondent-progress{
  background:#e9edf0;
  height:7px;
  border-radius:4px;
  overflow:hidden;
  margin-bottom:24px;
}
.respondent-progress div{
  height:100%;
  width:66%;
  background:#347fc4;
}
.r-question{
  margin-bottom:24px;
}
.r-question-title{
  font-size:14px;
  font-weight:700;
  margin-bottom:10px;
}
.required{
  color:#d9534f;
  font-size:10px;
  margin-left:5px;
}
.r-choice{
  display:block;
  padding:7px 0;
  font-size:13px;
}
.respondent-actions{
  display:flex;
  justify-content:flex-end;
  gap:8px;
  margin-top:24px;
}
.individual-info{
  max-width:680px;
  margin:0 auto;
  background:#fff;
  border:1px solid #dde2e6;
  border-radius:9px;
  padding:30px;
}
.center-message{
  text-align:center;
  padding:50px 20px;
}
.center-message h2{margin-bottom:12px}
.empty{
  padding:35px;
  text-align:center;
  color:#7b858d;
}
.modal-overlay{
  display:none;
  position:fixed;
  inset:0;
  z-index:1500;
  background:rgba(20,30,40,.48);
  align-items:center;
  justify-content:center;
  padding:20px;
}
.modal-overlay.active{display:flex}
.modal{
  width:min(620px,100%);
  max-height:90vh;
  overflow:auto;
  background:#fff;
  border-radius:8px;
  padding:22px;
  box-shadow:0 10px 40px rgba(0,0,0,.25);
}
.modal h3{
  font-size:17px;
  margin-bottom:12px;
}
.modal p{
  font-size:13px;
  color:#606a73;
  margin-bottom:8px;
}
.modal-actions{
  display:flex;
  justify-content:flex-end;
  gap:8px;
  margin-top:20px;
}
.detail-actions{
  display:flex;
  flex-wrap:wrap;
  gap:7px;
}
.small-text{
  color:#7b858d;
  font-size:11px;
}
.inline-flex{
  display:flex;
  align-items:center;
  gap:8px;
}
.filter-bar{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin-bottom:13px;
}
.filter-bar input{max-width:330px}
.mapping-table td input{min-width:130px}
@media(max-width:900px){
  .main-header{gap:14px;padding:0 12px}
  .logo{font-size:13px;margin-right:0}
  .main-nav a{padding:0 8px;font-size:12px}
  .summary-grid{grid-template-columns:repeat(2,1fr)}
  .send-grid{grid-template-columns:1fr}
}
@media(max-width:650px){
  .main-header{
    height:auto;
    min-height:58px;
    flex-wrap:wrap;
    padding:8px 10px;
  }
  .main-nav{
    width:100%;
    height:40px;
    overflow-x:auto;
  }
  .main-nav a{padding:0 9px}
  .page{padding:20px 12px 80px}
  .form-grid{grid-template-columns:1fr}
  .form-group.full{grid-column:auto}
  .summary-grid{grid-template-columns:1fr 1fr}
  .group-header{flex-wrap:wrap}
  .question-head{display:block}
  .question-tools{margin-top:8px}
  .choice-list,.add-row{margin-left:0}
}
</style>
</head>

<body>

<header class="main-header" id="mainHeader">
  <div class="logo">📋 アンケート運営</div>
  <nav class="main-nav">
    <a href="#" id="nav-list" class="active" data-page="list">アンケート一覧</a>
    <a href="#" id="nav-new" data-page="editor">新規アンケート作成</a>
    <a href="#" id="nav-customers" data-page="customers">顧客一覧</a>
    <a href="#" id="nav-settings" data-page="settings">設定</a>
    <a href="#" id="nav-respondent" data-page="respondent">回答画面確認</a>
  </nav>
</header>

<main>

<!-- =========================================================
     アンケート一覧
========================================================= -->
<section class="page active" id="page-list">
  <h1 class="page-title">アンケート一覧</h1>
  <p class="page-subtitle">作成・公開・送信・回答状況・集計を一つの画面から管理します。</p>

  <div class="toolbar">
    <div class="toolbar-left">
      <select id="surveyStatusFilter">
        <option value="">すべての状態</option>
        <option value="draft">下書き</option>
        <option value="published">公開</option>
        <option value="closed">終了</option>
      </select>
      <input type="text" id="surveySearch" placeholder="アンケート名で検索">
    </div>
    <div class="toolbar-right">
      <button class="btn" id="newSurveyButton">＋ 新規作成</button>
    </div>
  </div>

  <div class="card" style="padding:0;overflow:auto">
    <table id="surveyTable">
      <thead>
        <tr>
          <th>アンケート名</th>
          <th>状態</th>
          <th>開始日時</th>
          <th>終了日時</th>
          <th>作成日時</th>
          <th>更新日時</th>
          <th>回答数</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr data-status="published">
          <td><strong>顧客満足度調査 2026上期</strong></td>
          <td><span class="badge open">公開</span></td>
          <td>2026/09/10 09:00</td>
          <td>2026/10/10 18:00</td>
          <td>2026/09/01 10:00</td>
          <td>2026/09/20 15:30</td>
          <td>115</td>
          <td>
            <span class="action-link" data-open-detail="content">内容</span>
            <span class="action-link" data-open-detail="send">送信</span>
            <span class="action-link" data-open-detail="status">回答状況</span>
            <span class="action-link" data-open-detail="result">集計</span>
            <span class="action-link" data-edit-survey>編集</span>
            <span class="action-link danger" data-close-survey>終了</span>
          </td>
        </tr>

        <tr data-status="draft">
          <td><strong>新商品コンセプトアンケート</strong></td>
          <td><span class="badge draft">下書き</span></td>
          <td>未設定</td>
          <td>未設定</td>
          <td>2026/09/22 09:12</td>
          <td>2026/09/24 18:00</td>
          <td>0</td>
          <td>
            <span class="action-link" data-open-detail="content">内容</span>
            <span class="action-link" data-edit-survey>編集</span>
            <span class="action-link" data-publish-survey>公開</span>
            <span class="action-link danger" data-delete-survey>削除</span>
          </td>
        </tr>

        <tr data-status="closed">
          <td><strong>社内イベント参加意向調査</strong></td>
          <td><span class="badge closed">終了</span></td>
          <td>2026/07/05 09:00</td>
          <td>2026/08/01 18:00</td>
          <td>2026/07/01 09:00</td>
          <td>2026/08/05 12:00</td>
          <td>342</td>
          <td>
            <span class="action-link" data-open-detail="content">内容</span>
            <span class="action-link" data-open-detail="status">回答状況</span>
            <span class="action-link" data-open-detail="result">集計</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</section>


<!-- =========================================================
     アンケート編集
========================================================= -->
<section class="page" id="page-editor">
  <div class="breadcrumb" data-go-page="list">← アンケート一覧に戻る</div>
  <h1 class="page-title">アンケート作成・編集</h1>
  <p class="page-subtitle">下書き保存では未完成の状態を許容し、公開時に全体を確認します。</p>

  <div id="editorNotice"></div>

  <div class="card">
    <h3>基本情報</h3>
    <div class="form-grid">
      <div class="form-group full">
        <label>アンケート名</label>
        <input type="text" id="surveyName" value="顧客満足度調査 2026上期">
      </div>
      <div class="form-group full">
        <label>説明文</label>
        <textarea id="surveyDescription" rows="3">日頃のご利用に関するご意見をお聞かせください。</textarea>
      </div>
      <div class="form-group">
        <label>状態</label>
        <select id="surveyState">
          <option value="draft">下書き</option>
          <option value="published">公開</option>
          <option value="closed">終了</option>
        </select>
      </div>
      <div class="form-group">
        <label>質問番号形式</label>
        <select id="numberingFormat">
          <option value="all">全体通番（Q1、Q2、Q3）</option>
          <option value="group">グループ別（Q1-1、Q1-2）</option>
        </select>
      </div>
      <div class="form-group">
        <label>公開開始日時</label>
        <input type="datetime-local" value="2026-09-10T09:00">
      </div>
      <div class="form-group">
        <label>公開終了日時</label>
        <input type="datetime-local" value="2026-10-10T18:00">
      </div>
    </div>
  </div>

  <div class="card">
    <h3>質問・グループ</h3>
    <div class="notice info">
      質問は「移動」からグループ間の移動を確認できます。単一選択では選択肢ごとの分岐先も設定できます。
    </div>

    <div id="groupsContainer">

      <div class="group-box" data-group>
        <div class="group-header">
          <span class="drag-handle" title="移動">☷</span>
          <input class="group-name" value="基本属性について">
          <button class="btn secondary small" data-rename-group>名称変更</button>
          <button class="btn danger small" data-delete-group>グループ削除</button>
        </div>

        <div class="question-box" data-question data-type="single">
          <div class="question-head">
            <span class="drag-handle" title="質問を移動">⋮⋮</span>
            <div class="question-main">
              <div class="question-title-row">
                <span class="question-number">Q1</span>
                <span class="question-text">お住まいの地域を教えてください</span>
              </div>
              <div class="question-meta">単一選択・必須　／　分岐設定あり</div>

              <div class="choice-list">
                <div class="choice-row">
                  <span class="choice-id">A</span>
                  <input type="text" value="北海道・東北">
                  <select class="branch-target">
                    <option>次の質問</option>
                    <option>Q2：ご利用中のサービスをすべてお選びください（基本属性について）</option>
                    <option>Q3：ご自由にご意見をお書きください（ご意見・ご感想）</option>
                    <option>終了</option>
                  </select>
                </div>
                <div class="choice-row">
                  <span class="choice-id">B</span>
                  <input type="text" value="関東">
                  <select class="branch-target">
                    <option selected>次の質問</option>
                    <option>Q2：ご利用中のサービスをすべてお選びください（基本属性について）</option>
                    <option>Q3：ご自由にご意見をお書きください（ご意見・ご感想）</option>
                    <option>終了</option>
                  </select>
                </div>
                <div class="choice-row">
                  <span class="choice-id">C</span>
                  <input type="text" value="その他">
                  <select class="branch-target">
                    <option>次の質問</option>
                    <option>Q2：ご利用中のサービスをすべてお選びください（基本属性について）</option>
                    <option>Q3：ご自由にご意見をお書きください（ご意見・ご感想）</option>
                    <option selected>終了</option>
                  </select>
                </div>
              </div>

              <div class="add-row">
                <button class="link-button" data-add-choice>＋ 選択肢を追加</button>
              </div>
            </div>

            <div class="question-tools">
              <select class="move-select" title="質問を移動">
                <option>移動</option>
                <option>ご意見・ご感想へ</option>
              </select>
              <button class="btn secondary small" data-edit-question>編集</button>
              <button class="btn danger small" data-delete-question>削除</button>
            </div>
          </div>
        </div>

        <div class="question-box" data-question data-type="multiple">
          <div class="question-head">
            <span class="drag-handle" title="質問を移動">⋮⋮</span>
            <div class="question-main">
              <div class="question-title-row">
                <span class="question-number">Q2</span>
                <span class="question-text">ご利用中のサービスをすべてお選びください</span>
              </div>
              <div class="question-meta">複数選択・任意</div>
              <div class="choice-list">
                <div class="choice-row">
                  <span class="choice-id">A</span>
                  <input type="text" value="サービスA">
                </div>
                <div class="choice-row">
                  <span class="choice-id">B</span>
                  <input type="text" value="サービスB">
                </div>
              </div>
              <div class="add-row">
                <button class="link-button" data-add-choice>＋ 選択肢を追加</button>
              </div>
            </div>
            <div class="question-tools">
              <select class="move-select">
                <option>移動</option>
                <option>ご意見・ご感想へ</option>
              </select>
              <button class="btn secondary small" data-edit-question>編集</button>
              <button class="btn danger small" data-delete-question>削除</button>
            </div>
          </div>
        </div>

        <button class="link-button" data-add-question>＋ 質問を追加</button>
      </div>


      <div class="group-box" data-group>
        <div class="group-header">
          <span class="drag-handle">☷</span>
          <input class="group-name" value="ご意見・ご感想">
          <button class="btn secondary small" data-rename-group>名称変更</button>
          <button class="btn danger small" data-delete-group>グループ削除</button>
        </div>

        <div class="question-box" data-question data-type="text">
          <div class="question-head">
            <span class="drag-handle">⋮⋮</span>
            <div class="question-main">
              <div class="question-title-row">
                <span class="question-number">Q3</span>
                <span class="question-text">ご自由にご意見をお書きください</span>
              </div>
              <div class="question-meta">テキスト・任意・文字数上限500文字</div>
              <textarea rows="2" disabled placeholder="回答者が入力します"></textarea>
            </div>
            <div class="question-tools">
              <select class="move-select">
                <option>移動</option>
                <option>基本属性についてへ</option>
              </select>
              <button class="btn secondary small" data-edit-question>編集</button>
              <button class="btn danger small" data-delete-question>削除</button>
            </div>
          </div>
        </div>

        <button class="link-button" data-add-question>＋ 質問を追加</button>
      </div>

    </div>

    <button class="group-add" id="addGroupButton">＋ グループを追加</button>
  </div>

  <div class="editor-actions">
    <button class="btn secondary" id="cancelEditorButton">キャンセル</button>
    <button class="btn" id="saveDraftButton">下書き保存</button>
    <button class="btn success" id="publishEditorButton">公開する</button>
  </div>
</section>


<!-- =========================================================
     アンケート詳細
========================================================= -->
<section class="page" id="page-detail">
  <div class="breadcrumb" data-go-page="list">← アンケート一覧に戻る</div>

  <div class="toolbar">
    <div>
      <h1 class="page-title" style="margin-bottom:4px">顧客満足度調査 2026上期</h1>
      <span class="badge open">公開</span>
    </div>
    <div class="detail-actions">
      <button class="btn secondary" id="detailEditButton">編集する</button>
      <button class="btn" id="detailPublishButton">公開する</button>
      <button class="btn danger" id="detailCloseButton">終了する</button>
    </div>
  </div>

  <div class="tabs" id="detailTabs">
    <div class="tab active" data-tab="content">アンケート内容</div>
    <div class="tab" data-tab="send">送信</div>
    <div class="tab" data-tab="status">回答状況</div>
    <div class="tab" data-tab="result">回答結果・集計</div>
  </div>

  <!-- 内容 -->
  <div class="tab-content active" id="tab-content">
    <div class="card">
      <h3>基本情報</h3>
      <div class="form-grid">
        <div>
          <div class="small-text">アンケート名</div>
          <strong>顧客満足度調査 2026上期</strong>
        </div>
        <div>
          <div class="small-text">公開期間</div>
          2026/09/10 09:00 ～ 2026/10/10 18:00
        </div>
        <div>
          <div class="small-text">説明</div>
          日頃のご利用に関するご意見をお聞かせください。
        </div>
        <div>
          <div class="small-text">質問番号形式</div>
          全体通番
        </div>
      </div>
    </div>

    <div class="card">
      <h3>グループ1：基本属性について</h3>
      <p><strong>Q1.</strong> お住まいの地域を教えてください　<span class="small-text">単一選択・必須</span></p>
      <p class="small-text">A 北海道・東北 → 次の質問　／　B 関東 → 次の質問　／　C その他 → 終了</p>
      <hr style="border:0;border-top:1px solid #eee;margin:13px 0">
      <p><strong>Q2.</strong> ご利用中のサービスをすべてお選びください　<span class="small-text">複数選択・任意</span></p>
      <p class="small-text">A サービスA　／　B サービスB</p>
    </div>

    <div class="card">
      <h3>グループ2：ご意見・ご感想</h3>
      <p><strong>Q3.</strong> ご自由にご意見をお書きください　<span class="small-text">テキスト・任意</span></p>
    </div>
  </div>

  <!-- 送信 -->
  <div class="tab-content" id="tab-send">
    <div class="send-grid">
      <div>
        <div class="card">
          <h3>通常回答者への回答依頼</h3>
          <div class="filter-bar">
            <input type="text" id="customerSearch" placeholder="氏名・メール・組織名で検索">
            <button class="btn secondary small" id="selectAllCustomers">全選択</button>
            <button class="btn secondary small" id="clearAllCustomers">選択解除</button>
          </div>

          <div class="customer-select-list" id="customerSelectList">
            <label class="customer-row">
              <input type="checkbox" class="customer-check" checked>
              <span class="customer-main">山田 太郎<span class="customer-sub">株式会社サンプル / taro.yamada@example.com</span></span>
              <span class="badge open">送信可能</span>
            </label>
            <label class="customer-row">
              <input type="checkbox" class="customer-check" checked>
              <span class="customer-main">佐藤 花子<span class="customer-sub">株式会社サンプル / hanako.sato@example.com</span></span>
              <span class="badge open">送信可能</span>
            </label>
            <label class="customer-row">
              <input type="checkbox" class="customer-check">
              <span class="customer-main">鈴木 次郎<span class="customer-sub">株式会社テスト / jiro.suzuki@example.com</span></span>
              <span class="badge info">未送信</span>
            </label>
            <label class="customer-row">
              <input type="checkbox" class="customer-check">
              <span class="customer-main">田中 一郎<span class="customer-sub">株式会社サンプル / ichiro.tanaka@example.com</span></span>
              <span class="badge error">前回送信失敗</span>
            </label>
          </div>

          <p class="small-text" style="margin-top:9px">選択中：<span id="selectedCustomerCount">2</span>名</p>
        </div>

        <div class="card">
          <h3>個別回答URL</h3>
          <p class="small-text" style="margin-bottom:10px">
            顧客一覧に登録されていない人へ送る場合に使用します。
          </p>
          <button class="btn" id="issueIndividualUrlButton">個別回答URLを発行</button>

          <div id="issuedUrlArea" style="display:none;margin-top:14px">
            <label class="small-text">発行されたURL</label>
            <div class="url-box">
              <input type="text" id="issuedUrl" readonly>
              <button class="btn secondary" id="copyUrlButton">コピー</button>
              <button class="btn success" id="openIndividualButton">回答画面を開く</button>
            </div>
            <p class="small-text" style="margin-top:7px">
              状態：<span class="badge draft" id="individualTokenStatus">未使用</span>
            </p>
          </div>
        </div>
      </div>

      <div>
        <div class="card">
          <h3>回答依頼メール</h3>
          <div class="form-group">
            <label>件名</label>
            <input type="text" value="【アンケートご協力のお願い】顧客満足度調査 2026上期">
          </div>
          <div class="form-group">
            <label>本文</label>
            <textarea rows="11">日頃より大変お世話になっております。

下記URLよりアンケートにご協力ください。

回答用URL：
{{回答専用URL}}

※このURLは対象者専用です。</textarea>
          </div>
          <button class="btn secondary" id="previewMailButton">メールプレビュー</button>
          <button class="btn success" id="sendMailButton">回答依頼を送信</button>
        </div>

        <div class="card">
          <h3>送信状況</h3>
          <table>
            <thead>
              <tr>
                <th>対象者</th>
                <th>送信日時</th>
                <th>結果</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>山田 太郎</td>
                <td>2026/09/20 15:30</td>
                <td><span class="badge open">成功</span></td>
                <td><span class="action-link">ログ</span></td>
              </tr>
              <tr>
                <td>佐藤 花子</td>
                <td>2026/09/20 15:30</td>
                <td><span class="badge open">成功</span></td>
                <td><span class="action-link">ログ</span></td>
              </tr>
              <tr>
                <td>田中 一郎</td>
                <td>2026/09/20 15:31</td>
                <td><span class="badge error">失敗</span></td>
                <td><span class="action-link">再送</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- 回答状況 -->
  <div class="tab-content" id="tab-status">
    <div class="summary-grid">
      <div class="summary-card">
        <div class="num" id="statusSent">120</div>
        <div class="label">送信対象者</div>
      </div>
      <div class="summary-card">
        <div class="num" id="statusAnswered">115</div>
        <div class="label">回答済み</div>
      </div>
      <div class="summary-card">
        <div class="num" id="statusUnanswered">5</div>
        <div class="label">未回答</div>
      </div>
      <div class="summary-card">
        <div class="num">95.8%</div>
        <div class="label">回答率</div>
      </div>
    </div>

    <div class="card">
      <h3>回答者一覧</h3>
      <table class="status-table">
        <thead>
          <tr>
            <th>種別</th>
            <th>組織名</th>
            <th>部署名</th>
            <th>氏名</th>
            <th>メールアドレス</th>
            <th>送信</th>
            <th>回答</th>
            <th>回答日時</th>
          </tr>
        </thead>
        <tbody id="respondentStatusTable">
          <tr>
            <td>通常</td>
            <td>株式会社サンプル</td>
            <td>営業部</td>
            <td>山田 太郎</td>
            <td>taro.yamada@example.com</td>
            <td>2026/09/20 15:30</td>
            <td><span class="badge open">回答済み</span></td>
            <td>2026/09/21 10:12</td>
          </tr>
          <tr>
            <td>通常</td>
            <td>株式会社サンプル</td>
            <td>総務部</td>
            <td>佐藤 花子</td>
            <td>hanako.sato@example.com</td>
            <td>2026/09/20 15:30</td>
            <td><span class="badge used">未回答</span></td>
            <td>－</td>
          </tr>
          <tr id="individualStatusRow" style="display:none">
            <td>個別</td>
            <td id="individualOrg">－</td>
            <td id="individualDept">－</td>
            <td>個別回答者</td>
            <td id="individualEmail">－</td>
            <td>URL発行</td>
            <td><span class="badge draft" id="individualAnswerStatus">未回答</span></td>
            <td id="individualAnswerDate">－</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h3>日別回答受付件数</h3>
      <div class="chart-row">
        <div class="chart-label">09/20</div>
        <div class="chart-bg"><div class="chart-fill" style="width:40%"></div></div>
        <div class="chart-value">24件</div>
      </div>
      <div class="chart-row">
        <div class="chart-label">09/21</div>
        <div class="chart-bg"><div class="chart-fill" style="width:75%"></div></div>
        <div class="chart-value">45件</div>
      </div>
      <div class="chart-row">
        <div class="chart-label">09/22</div>
        <div class="chart-bg"><div class="chart-fill" style="width:58%"></div></div>
        <div class="chart-value">35件</div>
      </div>
      <div class="chart-row">
        <div class="chart-label">09/23</div>
        <div class="chart-bg"><div class="chart-fill" style="width:18%"></div></div>
        <div class="chart-value">11件</div>
      </div>
    </div>
  </div>

  <!-- 回答結果 -->
  <div class="tab-content" id="tab-result">
    <div class="summary-grid">
      <div class="summary-card">
        <div class="num">115</div>
        <div class="label">回答件数</div>
      </div>
      <div class="summary-card">
        <div class="num">2.4分</div>
        <div class="label">平均回答時間</div>
      </div>
      <div class="summary-card">
        <div class="num">3</div>
        <div class="label">質問数</div>
      </div>
      <div class="summary-card">
        <div class="num">2</div>
        <div class="label">分岐あり</div>
      </div>
    </div>

    <div class="card">
      <h3>Q1. お住まいの地域を教えてください</h3>
      <div class="chart-row">
        <div class="chart-label">北海道・東北</div>
        <div class="chart-bg"><div class="chart-fill" style="width:23%"></div></div>
        <div class="chart-value">26件 / 23%</div>
      </div>
      <div class="chart-row">
        <div class="chart-label">関東</div>
        <div class="chart-bg"><div class="chart-fill" style="width:58%"></div></div>
        <div class="chart-value">67件 / 58%</div>
      </div>
      <div class="chart-row">
        <div class="chart-label">その他</div>
        <div class="chart-bg"><div class="chart-fill" style="width:19%"></div></div>
        <div class="chart-value">22件 / 19%</div>
      </div>
    </div>

    <div class="card">
      <h3>Q2. ご利用中のサービス</h3>
      <div class="chart-row">
        <div class="chart-label">サービスA</div>
        <div class="chart-bg"><div class="chart-fill" style="width:65%"></div></div>
        <div class="chart-value">75件 / 65%</div>
      </div>
      <div class="chart-row">
        <div class="chart-label">サービスB</div>
        <div class="chart-bg"><div class="chart-fill" style="width:39%"></div></div>
        <div class="chart-value">45件 / 39%</div>
      </div>
      <p class="small-text">※分岐によって到達しなかった質問は集計対象から除外しています。</p>
    </div>

    <div class="card">
      <h3>Q3. ご自由にご意見をお書きください</h3>
      <table>
        <thead><tr><th>回答内容</th><th>回答者</th><th>回答日時</th></tr></thead>
        <tbody>
          <tr>
            <td>対応が丁寧で満足しています。</td>
            <td>山田 太郎</td>
            <td>2026/09/21 10:12</td>
          </tr>
          <tr>
            <td>もう少し価格を抑えてほしいです。</td>
            <td>佐藤 花子</td>
            <td>2026/09/21 11:04</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>


<!-- =========================================================
     顧客一覧
========================================================= -->
<section class="page" id="page-customers">
  <h1 class="page-title">顧客一覧</h1>
  <p class="page-subtitle">通常回答者として回答依頼を送る対象者を管理します。</p>

  <div class="toolbar">
    <div class="toolbar-left">
      <input type="text" id="customerListSearch" placeholder="氏名・メール・組織名で検索">
    </div>
    <div class="toolbar-right">
      <button class="btn secondary" id="kintoneCustomerSyncButton">kintoneから最新情報を取得</button>
    </div>
  </div>

  <div class="card" style="padding:0;overflow:auto">
    <table>
      <thead>
        <tr>
          <th>氏名</th>
          <th>組織名</th>
          <th>部署名</th>
          <th>メールアドレス</th>
          <th>登録日</th>
          <th>状態</th>
        </tr>
      </thead>
      <tbody id="customerTable">
        <tr>
          <td>山田 太郎</td>
          <td>株式会社サンプル</td>
          <td>営業部</td>
          <td>taro.yamada@example.com</td>
          <td>2025/04/01</td>
          <td><span class="badge open">有効</span></td>
        </tr>
        <tr>
          <td>佐藤 花子</td>
          <td>株式会社サンプル</td>
          <td>総務部</td>
          <td>hanako.sato@example.com</td>
          <td>2025/05/12</td>
          <td><span class="badge open">有効</span></td>
        </tr>
        <tr>
          <td>鈴木 次郎</td>
          <td>株式会社テスト</td>
          <td>企画部</td>
          <td>jiro.suzuki@example.com</td>
          <td>2025/06/20</td>
          <td><span class="badge open">有効</span></td>
        </tr>
        <tr>
          <td>田中 一郎</td>
          <td>株式会社サンプル</td>
          <td>開発部</td>
          <td>ichiro.tanaka@example.com</td>
          <td>2025/07/03</td>
          <td><span class="badge open">有効</span></td>
        </tr>
      </tbody>
    </table>
  </div>
</section>


<!-- =========================================================
     設定
========================================================= -->
<section class="page" id="page-settings">
  <h1 class="page-title">設定</h1>

  <div class="settings-nav">
    <button class="btn secondary active" data-settings-tab="smtp">SMTP設定</button>
    <button class="btn secondary" data-settings-tab="kintone">kintone設定</button>
    <button class="btn secondary" data-settings-tab="mapping">kintone項目マッピング</button>
  </div>

  <!-- SMTP -->
  <div class="settings-panel" id="settings-smtp">
    <div class="card">
      <h3>SMTP設定</h3>
      <div class="form-grid">
        <div class="form-group">
          <label>SMTPホスト</label>
          <input type="text" value="smtp.example.com">
        </div>
        <div class="form-group">
          <label>ポート</label>
          <input type="number" value="587">
        </div>
        <div class="form-group">
          <label>暗号化方式</label>
          <select>
            <option>なし</option>
            <option>SSL</option>
            <option selected>TLS</option>
          </select>
        </div>
        <div class="form-group">
          <label>ユーザー名</label>
          <input type="text" value="notify@example.com">
        </div>
        <div class="form-group">
          <label>パスワード</label>
          <input type="password" value="mock-password">
        </div>
        <div class="form-group">
          <label>送信元メールアドレス</label>
          <input type="email" value="notify@example.com">
        </div>
        <div class="form-group full">
          <label>送信元名</label>
          <input type="text" value="アンケート事務局">
        </div>
      </div>

      <div class="toolbar" style="margin-top:5px">
        <div class="toolbar-left">
          <button class="btn secondary" id="smtpTestButton">SMTP接続確認</button>
          <button class="btn secondary" id="smtpTestMailButton">テストメール送信</button>
        </div>
        <button class="btn" id="smtpSaveButton">保存</button>
      </div>

      <div class="notice info">
        最終接続確認：2026/09/20 09:00　／　接続成功
      </div>
    </div>

    <div class="card">
      <h3>送信ログ</h3>
      <table>
        <thead>
          <tr>
            <th>日時</th>
            <th>対象者</th>
            <th>結果</th>
            <th>エラー情報</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>2026/09/20 15:30</td>
            <td>山田 太郎</td>
            <td><span class="badge open">成功</span></td>
            <td>－</td>
            <td>－</td>
          </tr>
          <tr>
            <td>2026/09/20 15:31</td>
            <td>田中 一郎</td>
            <td><span class="badge error">失敗</span></td>
            <td>SMTPサーバへの接続に失敗しました。</td>
            <td><span class="action-link">再送</span></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- kintone -->
  <div class="settings-panel" id="settings-kintone" style="display:none">
    <div class="card">
      <h3>kintone接続設定</h3>
      <div class="form-grid">
        <div class="form-group">
          <label>サブドメイン</label>
          <input type="text" value="example">
        </div>
        <div class="form-group">
          <label>アプリID</label>
          <input type="number" value="12">
        </div>
        <div class="form-group">
          <label>ログイン名</label>
          <input type="text" value="kintone_user">
        </div>
        <div class="form-group">
          <label>パスワード</label>
          <input type="password" value="mock-password">
        </div>
        <div class="form-group">
          <label>アプリ名</label>
          <input type="text" value="顧客管理">
        </div>
        <div class="form-group">
          <label>メール項目</label>
          <input type="text" value="email">
        </div>
        <div class="form-group full">
          <label>プロキシ設定（host:port）</label>
          <input type="text" value="proxy.example.com:8080" placeholder="proxy.example.com:8080">
        </div>
      </div>

      <div class="notice warning">
        モックでは実際のkintone通信は行わず、接続成功・失敗・項目取得・同期の画面動作を確認できます。
      </div>

      <div class="toolbar">
        <div class="toolbar-left">
          <button class="btn secondary" id="kintoneTestButton">接続確認</button>
          <button class="btn secondary" id="kintoneFieldsButton">フィールド定義を取得</button>
        </div>
        <button class="btn" id="kintoneSaveButton">保存</button>
      </div>

      <div id="kintoneStatus" class="notice success">
        接続確認：成功　／　最終確認 2026/09/20 09:00
      </div>
    </div>

    <div class="card">
      <h3>kintone同期</h3>
      <p class="small-text" style="margin-bottom:12px">
        顧客情報をkintoneから取得して、アンケートの通常回答者一覧へ反映します。
      </p>
      <button class="btn success" id="kintoneSyncButton">kintoneから同期</button>
    </div>
  </div>

  <!-- mapping -->
  <div class="settings-panel" id="settings-mapping" style="display:none">
    <div class="card">
      <h3>kintone項目マッピング</h3>
      <div class="notice info">
        顧客情報の各項目をkintoneのフィールドへ対応付けます。
      </div>

      <table class="mapping-table">
        <thead>
          <tr>
            <th>アンケート側項目</th>
            <th>kintoneフィールドコード</th>
            <th>kintone表示名</th>
            <th>状態</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>組織名</td>
            <td><input type="text" value="company_name"></td>
            <td>会社名</td>
            <td><span class="badge open">設定済み</span></td>
          </tr>
          <tr>
            <td>部署名</td>
            <td><input type="text" value="department"></td>
            <td>部署</td>
            <td><span class="badge open">設定済み</span></td>
          </tr>
          <tr>
            <td>氏名</td>
            <td><input type="text" value="customer_name"></td>
            <td>氏名</td>
            <td><span class="badge open">設定済み</span></td>
          </tr>
          <tr>
            <td>メールアドレス</td>
            <td><input type="text" value="email"></td>
            <td>メールアドレス</td>
            <td><span class="badge open">設定済み</span></td>
          </tr>
          <tr>
            <td>住所</td>
            <td><input type="text" value="address"></td>
            <td>住所</td>
            <td><span class="badge open">設定済み</span></td>
          </tr>
          <tr>
            <td>電話番号</td>
            <td><input type="text" value="phone"></td>
            <td>電話番号</td>
            <td><span class="badge open">設定済み</span></td>
          </tr>
        </tbody>
      </table>

      <div class="editor-actions">
        <button class="btn" id="mappingSaveButton">マッピングを保存</button>
      </div>
    </div>
  </div>
</section>


<!-- =========================================================
     回答者画面
========================================================= -->
<section class="page" id="page-respondent">
  <div class="respondent-wrap">

    <div class="individual-info" id="individualInfoStep">
      <h2>回答者情報を入力</h2>
      <p class="respondent-desc">
        アンケート回答前に、組織名・部署名・メールアドレスを入力してください。
      </p>

      <div class="notice info">
        この画面は個別回答URLからアクセスした回答者を想定しています。
      </div>

      <div class="form-group">
        <label>組織名</label>
        <input type="text" id="respondentOrg" placeholder="株式会社○○">
      </div>

      <div class="form-group">
        <label>部署名</label>
        <input type="text" id="respondentDept" placeholder="営業部">
      </div>

      <div class="form-group">
        <label>メールアドレス</label>
        <input type="email" id="respondentEmail" placeholder="example@example.com">
      </div>

      <div class="respondent-actions">
        <button class="btn" id="respondentNextButton">回答へ進む</button>
      </div>
    </div>

    <div class="individual-info" id="individualConfirmStep" style="display:none">
      <h2>入力内容の確認</h2>
      <p class="respondent-desc">
        入力内容を確認してください。
      </p>

      <div class="card">
        <p><strong>組織名：</strong><span id="confirmOrg"></span></p>
        <p><strong>部署名：</strong><span id="confirmDept"></span></p>
        <p><strong>メールアドレス：</strong><span id="confirmEmail"></span></p>
      </div>

      <div class="respondent-actions">
        <button class="btn secondary" id="respondentBackButton">修正する</button>
        <button class="btn" id="respondentStartButton">アンケート回答へ進む</button>
      </div>
    </div>

    <div class="respondent-page" id="questionnaireStep" style="display:none">
      <h2>顧客満足度調査 2026上期</h2>
      <p class="respondent-desc">
        日頃のご利用に関するご意見をお聞かせください。
      </p>

      <div class="respondent-progress">
        <div></div>
      </div>

      <div class="r-question">
        <div class="r-question-title">
          Q1. お住まいの地域を教えてください
          <span class="required">必須</span>
        </div>
        <label class="r-choice"><input type="radio" name="rq1" value="北海道・東北"> 北海道・東北</label>
        <label class="r-choice"><input type="radio" name="rq1" value="関東"> 関東</label>
        <label class="r-choice"><input type="radio" name="rq1" value="その他"> その他</label>
      </div>

      <div class="r-question">
        <div class="r-question-title">Q2. ご利用中のサービスをすべてお選びください</div>
        <label class="r-choice"><input type="checkbox" name="rq2" value="サービスA"> サービスA</label>
        <label class="r-choice"><input type="checkbox" name="rq2" value="サービスB"> サービスB</label>
      </div>

      <div class="r-question">
        <div class="r-question-title">Q3. ご自由にご意見をお書きください</div>
        <textarea id="respondentFreeText" rows="5" maxlength="500" placeholder="ご意見をご入力ください"></textarea>
        <div class="small-text">500文字以内</div>
      </div>

      <div class="respondent-actions">
        <button class="btn success" id="submitAnswerButton">回答を送信</button>
      </div>
    </div>

    <div class="respondent-page" id="respondentCompleteStep" style="display:none">
      <div class="center-message">
        <h2>回答ありがとうございました</h2>
        <p>アンケートの回答を受け付けました。</p>
        <p class="small-text" style="margin-top:8px">
          この回答URLは回答済みのため、同じURLから再度回答することはできません。
        </p>
      </div>
    </div>

    <div class="respondent-page" id="respondentUsedStep" style="display:none">
      <div class="center-message">
        <h2>回答済みです</h2>
        <p>この回答URLでは、すでに回答が完了しています。</p>
      </div>
    </div>

  </div>
</section>

</main>


<!-- =========================================================
     モーダル
========================================================= -->
<div class="modal-overlay" id="publishModal">
  <div class="modal">
    <h3>アンケートを公開しますか？</h3>
    <p>公開すると回答者が回答できる状態になります。</p>
    <div class="notice warning">
      公開前に質問・選択肢・分岐・回答期間などを確認します。
    </div>
    <div class="modal-actions">
      <button class="btn secondary" data-close-modal="publishModal">キャンセル</button>
      <button class="btn success" id="modalPublishButton">公開する</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="closeModal">
  <div class="modal">
    <h3>アンケートを終了しますか？</h3>
    <p>終了すると新しい回答を受け付けなくなります。</p>
    <div class="modal-actions">
      <button class="btn secondary" data-close-modal="closeModal">キャンセル</button>
      <button class="btn danger" id="modalCloseButton">終了する</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <h3>アンケートを削除しますか？</h3>
    <p>この操作は取り消せません。</p>
    <div class="modal-actions">
      <button class="btn secondary" data-close-modal="deleteModal">キャンセル</button>
      <button class="btn danger" id="modalDeleteButton">削除する</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="mailPreviewModal">
  <div class="modal">
    <h3>メールプレビュー</h3>
    <div class="card">
      <p><strong>件名：</strong>【アンケートご協力のお願い】顧客満足度調査 2026上期</p>
      <hr style="border:0;border-top:1px solid #eee;margin:12px 0">
      <p>日頃より大変お世話になっております。</p>
      <p>下記URLよりアンケートにご協力ください。</p>
      <p style="margin-top:12px">回答用URL：</p>
      <p>https://example.com/questionnaire/mock/token/xxxxxxxx</p>
    </div>
    <div class="modal-actions">
      <button class="btn secondary" data-close-modal="mailPreviewModal">閉じる</button>
    </div>
  </div>
</div>

<div class="message-area" id="messageArea"></div>


<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const state = {
    currentPage: 'list',
    individualIssued: false,
    individualInfoEntered: false,
    individualAnswered: false,
    detailTab: 'content'
  };

  function byId(id) {
    return document.getElementById(id);
  }

  function queryAll(selector, root) {
    return Array.from((root || document).querySelectorAll(selector));
  }

  function escapeText(value) {
    return String(value ?? '');
  }

  function showMessage(message, type) {
    const area = byId('messageArea');
    if (!area) return;

    const item = document.createElement('div');
    item.className = 'message' + (type === 'success' ? ' success' : '');

    const text = document.createElement('span');
    text.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = '×';
    close.addEventListener('click', function () {
      item.remove();
    });

    item.appendChild(text);
    item.appendChild(close);
    area.appendChild(item);
  }

  function setLoading(button, loading) {
    if (!button) return;

    if (loading) {
      button.disabled = true;
      button.classList.add('loading');
    } else {
      button.disabled = false;
      button.classList.remove('loading');
    }
  }

  function simulateAction(button, callback, delay) {
    if (!button || button.disabled) return;

    setLoading(button, true);

    window.setTimeout(function () {
      try {
        callback();
      } finally {
        setLoading(button, false);
      }
    }, delay || 500);
  }

  function showPage(pageId) {
    queryAll('.page').forEach(function (page) {
      page.classList.remove('active');
    });

    const target = byId('page-' + pageId);
    if (target) {
      target.classList.add('active');
    }

    queryAll('.main-nav a').forEach(function (link) {
      link.classList.remove('active');
    });

    const nav = byId('nav-' + pageId);
    if (nav) {
      nav.classList.add('active');
    }

    state.currentPage = pageId;

    window.scrollTo({
      top: 0,
      behavior: 'smooth'
    });
  }

  function openModal(id) {
    const modal = byId(id);
    if (modal) {
      modal.classList.add('active');
    }
  }

  function closeModal(id) {
    const modal = byId(id);
    if (modal) {
      modal.classList.remove('active');
    }
  }

  function switchDetailTab(tabName) {
    state.detailTab = tabName;

    queryAll('#detailTabs .tab').forEach(function (tab) {
      tab.classList.toggle('active', tab.getAttribute('data-tab') === tabName);
    });

    queryAll('#page-detail .tab-content').forEach(function (content) {
      content.classList.remove('active');
    });

    const target = byId('tab-' + tabName);
    if (target) {
      target.classList.add('active');
    }
  }

  function validateSurveyForPublish() {
    const errors = [];

    const surveyName = byId('surveyName');
    if (!surveyName || !surveyName.value.trim()) {
      errors.push('アンケート名が入力されていません。');
    }

    const groups = queryAll('#groupsContainer [data-group]');
    if (groups.length === 0) {
      errors.push('グループが1つ以上必要です。');
    }

    groups.forEach(function (group, groupIndex) {
      const groupName = group.querySelector('.group-name');

      if (!groupName || !groupName.value.trim()) {
        errors.push('グループ' + (groupIndex + 1) + 'の名称が入力されていません。');
      }

      const questions = group.querySelectorAll('[data-question]');

      questions.forEach(function (question, questionIndex) {
        const title = question.querySelector('.question-text');

        if (!title || !title.textContent.trim()) {
          errors.push(
            'グループ' + (groupIndex + 1) +
            'の質問' + (questionIndex + 1) +
            'の質問文が未入力です。'
          );
        }

        const type = question.getAttribute('data-type');

        if (type === 'single' || type === 'multiple') {
          const choices = question.querySelectorAll('.choice-row input[type="text"]');

          if (choices.length === 0) {
            errors.push(
              '「' + (title ? title.textContent.trim() : '質問') +
              '」に選択肢がありません。'
            );
          }

          const ids = [];
          choices.forEach(function (choice) {
            if (!choice.value.trim()) {
              errors.push('空の選択肢があります。');
            }
          });

          queryAll('.choice-id', question).forEach(function (idElement) {
            const id = idElement.textContent.trim();
            if (ids.indexOf(id) >= 0) {
              errors.push('選択肢IDが重複しています：' + id);
            }
            ids.push(id);
          });
        }
      });
    });

    return errors;
  }

  function addQuestion(group) {
    if (!group) return;

    const container = group.querySelector('.question-box');
    if (!container) {
      const addButton = group.querySelector('[data-add-question]');
      if (addButton) {
        const newQuestion = createQuestionElement('新しい質問');
        group.insertBefore(newQuestion, addButton);
      }
      return;
    }

    const newQuestion = createQuestionElement('新しい質問');
    const addButton = group.querySelector('[data-add-question]');

    if (addButton) {
      group.insertBefore(newQuestion, addButton);
    } else {
      group.appendChild(newQuestion);
    }

    showMessage('質問を追加しました。質問文を入力してください。', 'success');
  }

  function createQuestionElement(title) {
    const box = document.createElement('div');
    box.className = 'question-box';
    box.setAttribute('data-question', '');
    box.setAttribute('data-type', 'text');

    const head = document.createElement('div');
    head.className = 'question-head';

    const handle = document.createElement('span');
    handle.className = 'drag-handle';
    handle.textContent = '⋮⋮';

    const main = document.createElement('div');
    main.className = 'question-main';

    const titleRow = document.createElement('div');
    titleRow.className = 'question-title-row';

    const number = document.createElement('span');
    number.className = 'question-number';
    number.textContent = 'Q';

    const questionText = document.createElement('span');
    questionText.className = 'question-text';
    questionText.textContent = title;

    titleRow.appendChild(number);
    titleRow.appendChild(questionText);

    const meta = document.createElement('div');
    meta.className = 'question-meta';
    meta.textContent = 'テキスト・任意';

    main.appendChild(titleRow);
    main.appendChild(meta);

    const tools = document.createElement('div');
    tools.className = 'question-tools';

    const move = document.createElement('select');
    move.className = 'move-select';
    move.innerHTML =
      '<option>移動</option>' +
      '<option>基本属性についてへ</option>' +
      '<option>ご意見・ご感想へ</option>';

    const edit = document.createElement('button');
    edit.className = 'btn secondary small';
    edit.type = 'button';
    edit.textContent = '編集';
    edit.setAttribute('data-edit-question', '');

    const del = document.createElement('button');
    del.className = 'btn danger small';
    del.type = 'button';
    del.textContent = '削除';
    del.setAttribute('data-delete-question', '');

    tools.appendChild(move);
    tools.appendChild(edit);
    tools.appendChild(del);

    head.appendChild(handle);
    head.appendChild(main);
    head.appendChild(tools);
    box.appendChild(head);

    return box;
  }

  function addChoice(question) {
    if (!question) return;

    let list = question.querySelector('.choice-list');

    if (!list) {
      list = document.createElement('div');
      list.className = 'choice-list';

      const main = question.querySelector('.question-main');
      const addRow = question.querySelector('.add-row');

      if (main) {
        if (addRow) {
          main.insertBefore(list, addRow);
        } else {
          main.appendChild(list);
        }
      }
    }

    const count = list.querySelectorAll('.choice-row').length;
    const row = document.createElement('div');
    row.className = 'choice-row';

    const id = document.createElement('span');
    id.className = 'choice-id';
    id.textContent = String.fromCharCode(65 + count);

    const input = document.createElement('input');
    input.type = 'text';
    input.placeholder = '選択肢';

    const branch = document.createElement('select');
    branch.className = 'branch-target';
    branch.innerHTML =
      '<option>次の質問</option>' +
      '<option>Q2：ご利用中のサービスをすべてお選びください（基本属性について）</option>' +
      '<option>Q3：ご自由にご意見をお書きください（ご意見・ご感想）</option>' +
      '<option>終了</option>';

    row.appendChild(id);
    row.appendChild(input);

    if (question.getAttribute('data-type') === 'single') {
      row.appendChild(branch);
    }

    list.appendChild(row);

    showMessage('選択肢を追加しました。', 'success');
  }

  function renumberQuestions() {
    let number = 1;

    queryAll('#groupsContainer [data-question]').forEach(function (question) {
      const numberElement = question.querySelector('.question-number');
      if (numberElement) {
        numberElement.textContent = 'Q' + number;
      }
      number++;
    });
  }

  function openEditor() {
    showPage('editor');
    renumberQuestions();
  }

  function openDetail(tab) {
    showPage('detail');
    switchDetailTab(tab || 'content');
  }

  function updateSelectedCustomerCount() {
    const countElement = byId('selectedCustomerCount');
    if (!countElement) return;

    const count = queryAll('.customer-check:checked').length;
    countElement.textContent = String(count);
  }

  function filterTableRows(inputId, tableId) {
    const input = byId(inputId);
    const table = byId(tableId);

    if (!input || !table) return;

    const keyword = input.value.trim().toLowerCase();

    table.querySelectorAll('tbody tr').forEach(function (row) {
      const text = row.textContent.toLowerCase();
      row.style.display = !keyword || text.indexOf(keyword) >= 0 ? '' : 'none';
    });
  }

  function setupNavigation() {
    queryAll('.main-nav a').forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();

        const page = link.getAttribute('data-page');
        if (!page) return;

        if (page === 'editor') {
          openEditor();
        } else {
          showPage(page);
        }
      });
    });

    queryAll('[data-go-page]').forEach(function (element) {
      element.addEventListener('click', function () {
        const page = element.getAttribute('data-go-page');
        if (page) showPage(page);
      });
    });
  }

  setupNavigation();

  const newSurveyButton = byId('newSurveyButton');
  if (newSurveyButton) {
    newSurveyButton.addEventListener('click', function () {
      openEditor();
    });
  }

  const surveySearch = byId('surveySearch');
  if (surveySearch) {
    surveySearch.addEventListener('input', function () {
      const keyword = surveySearch.value.trim().toLowerCase();

      queryAll('#surveyTable tbody tr').forEach(function (row) {
        const text = row.textContent.toLowerCase();
        const status = row.getAttribute('data-status') || '';
        const filter = byId('surveyStatusFilter');
        const selectedStatus = filter ? filter.value : '';

        const keywordMatch = !keyword || text.indexOf(keyword) >= 0;
        const statusMatch = !selectedStatus || status === selectedStatus;

        row.style.display = keywordMatch && statusMatch ? '' : 'none';
      });
    });
  }

  const surveyStatusFilter = byId('surveyStatusFilter');
  if (surveyStatusFilter) {
    surveyStatusFilter.addEventListener('change', function () {
      if (surveySearch) {
        surveySearch.dispatchEvent(new Event('input'));
      }
    });
  }

  queryAll('[data-open-detail]').forEach(function (element) {
    element.addEventListener('click', function () {
      openDetail(element.getAttribute('data-open-detail'));
    });
  });

  queryAll('[data-edit-survey]').forEach(function (element) {
    element.addEventListener('click', function () {
      openEditor();
    });
  });

  queryAll('[data-publish-survey]').forEach(function (element) {
    element.addEventListener('click', function () {
      openModal('publishModal');
    });
  });

  queryAll('[data-close-survey]').forEach(function (element) {
    element.addEventListener('click', function () {
      openModal('closeModal');
    });
  });

  queryAll('[data-delete-survey]').forEach(function (element) {
    element.addEventListener('click', function () {
      openModal('deleteModal');
    });
  });

  queryAll('[data-close-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
      const id = button.getAttribute('data-close-modal');
      if (id) closeModal(id);
    });
  });

  const detailEditButton = byId('detailEditButton');
  if (detailEditButton) {
    detailEditButton.addEventListener('click', function () {
      openEditor();
    });
  }

  const detailPublishButton = byId('detailPublishButton');
  if (detailPublishButton) {
    detailPublishButton.addEventListener('click', function () {
      openModal('publishModal');
    });
  }

  const detailCloseButton = byId('detailCloseButton');
  if (detailCloseButton) {
    detailCloseButton.addEventListener('click', function () {
      openModal('closeModal');
    });
  }

  queryAll('#detailTabs .tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      switchDetailTab(tab.getAttribute('data-tab') || 'content');
    });
  });

  const saveDraftButton = byId('saveDraftButton');
  if (saveDraftButton) {
    saveDraftButton.addEventListener('click', function () {
      simulateAction(saveDraftButton, function () {
        const stateSelect = byId('surveyState');
        if (stateSelect) {
          stateSelect.value = 'draft';
        }

        showMessage('アンケートを下書き保存しました。', 'success');
      });
    });
  }

  const publishEditorButton = byId('publishEditorButton');
  if (publishEditorButton) {
    publishEditorButton.addEventListener('click', function () {
      const errors = validateSurveyForPublish();

      if (errors.length > 0) {
        const notice = byId('editorNotice');

        if (notice) {
          notice.innerHTML = '';
          const box = document.createElement('div');
          box.className = 'notice error';

          const title = document.createElement('strong');
          title.textContent = '公開できません。以下を確認してください。';

          box.appendChild(title);

          errors.forEach(function (error) {
            const p = document.createElement('div');
            p.textContent = '・' + error;
            box.appendChild(p);
          });

          notice.appendChild(box);
        }

        showMessage('公開できない項目があります。画面上のエラーを確認してください。', 'error');
        return;
      }

      openModal('publishModal');
    });
  }

  const modalPublishButton = byId('modalPublishButton');
  if (modalPublishButton) {
    modalPublishButton.addEventListener('click', function () {
      simulateAction(modalPublishButton, function () {
        closeModal('publishModal');

        const stateSelect = byId('surveyState');
        if (stateSelect) {
          stateSelect.value = 'published';
        }

        const notice = byId('editorNotice');
        if (notice) {
          notice.innerHTML = '';
        }

        showMessage('アンケートを公開しました。', 'success');
      });
    });
  }

  const modalCloseButton = byId('modalCloseButton');
  if (modalCloseButton) {
    modalCloseButton.addEventListener('click', function () {
      simulateAction(modalCloseButton, function () {
        closeModal('closeModal');
        showMessage('アンケートを終了しました。', 'success');
      });
    });
  }

  const modalDeleteButton = byId('modalDeleteButton');
  if (modalDeleteButton) {
    modalDeleteButton.addEventListener('click', function () {
      simulateAction(modalDeleteButton, function () {
        closeModal('deleteModal');
        showMessage('アンケートを削除しました。', 'success');
        showPage('list');
      });
    });
  }

  const addGroupButton = byId('addGroupButton');
  if (addGroupButton) {
    addGroupButton.addEventListener('click', function () {
      const container = byId('groupsContainer');
      if (!container) return;

      const group = document.createElement('div');
      group.className = 'group-box';
      group.setAttribute('data-group', '');

      group.innerHTML =
        '<div class="group-header">' +
          '<span class="drag-handle">☷</span>' +
          '<input class="group-name" value="新しいグループ">' +
          '<button class="btn secondary small" data-rename-group>名称変更</button>' +
          '<button class="btn danger small" data-delete-group>グループ削除</button>' +
        '</div>' +
        '<button class="link-button" data-add-question>＋ 質問を追加</button>';

      container.appendChild(group);
      showMessage('グループを追加しました。', 'success');
    });
  }

  document.addEventListener('click', function (event) {
    const target = event.target;

    if (!(target instanceof Element)) return;

    const addQuestionButton = target.closest('[data-add-question]');
    if (addQuestionButton) {
      const group = addQuestionButton.closest('[data-group]');
      addQuestion(group);
      return;
    }

    const addChoiceButton = target.closest('[data-add-choice]');
    if (addChoiceButton) {
      const question = addChoiceButton.closest('[data-question]');
      addChoice(question);
      return;
    }

    const deleteQuestionButton = target.closest('[data-delete-question]');
    if (deleteQuestionButton) {
      const question = deleteQuestionButton.closest('[data-question]');
      if (!question) return;

      const questionText = question.querySelector('.question-text');
      const name = questionText ? questionText.textContent : '質問';

      if (window.confirm('「' + name + '」を削除しますか？')) {
        question.remove();
        renumberQuestions();
        showMessage('質問を削除しました。', 'success');
      }
      return;
    }

    const editQuestionButton = target.closest('[data-edit-question]');
    if (editQuestionButton) {
      const question = editQuestionButton.closest('[data-question]');
      if (!question) return;

      const editor = question.querySelector('.question-editor');

      if (editor) {
        editor.classList.toggle('open');
      } else {
        const panel = document.createElement('div');
        panel.className = 'question-editor open';

        panel.innerHTML =
          '<div class="form-grid">' +
            '<div class="form-group full">' +
              '<label>質問文</label>' +
              '<input type="text" class="question-edit-title" value="' +
                escapeText((question.querySelector('.question-text') || {}).textContent || '') +
              '">' +
            '</div>' +
            '<div class="form-group">' +
              '<label>質問形式</label>' +
              '<select class="question-edit-type">' +
                '<option value="text">テキスト</option>' +
                '<option value="single">単一選択</option>' +
                '<option value="multiple">複数選択</option>' +
              '</select>' +
            '</div>' +
            '<div class="form-group">' +
              '<label>回答</label>' +
              '<select><option>任意</option><option>必須</option></select>' +
            '</div>' +
          '</div>' +
          '<div style="text-align:right">' +
            '<button type="button" class="btn secondary small question-edit-cancel">閉じる</button> ' +
            '<button type="button" class="btn small question-edit-save">反映</button>' +
          '</div>';

        question.appendChild(panel);

        const save = panel.querySelector('.question-edit-save');
        const cancel = panel.querySelector('.question-edit-cancel');

        if (save) {
          save.addEventListener('click', function () {
            const titleInput = panel.querySelector('.question-edit-title');
            const titleElement = question.querySelector('.question-text');

            if (titleInput && titleElement) {
              titleElement.textContent = titleInput.value.trim() || '未入力の質問';
            }

            panel.remove();
            showMessage('質問を編集しました。', 'success');
          });
        }

        if (cancel) {
          cancel.addEventListener('click', function () {
            panel.remove();
          });
        }
      }
      return;
    }

    const deleteGroupButton = target.closest('[data-delete-group]');
    if (deleteGroupButton) {
      const group = deleteGroupButton.closest('[data-group]');
      if (!group) return;

      const groups = queryAll('#groupsContainer [data-group]');

      if (groups.length <= 1) {
        showMessage('グループは最低1つ必要です。', 'error');
        return;
      }

      if (window.confirm('このグループとグループ内の質問を削除しますか？')) {
        group.remove();
        renumberQuestions();
        showMessage('グループを削除しました。', 'success');
      }
      return;
    }

    const renameGroupButton = target.closest('[data-rename-group]');
    if (renameGroupButton) {
      const group = renameGroupButton.closest('[data-group]');
      if (!group) return;

      const input = group.querySelector('.group-name');
      if (!input) return;

      const value = window.prompt('グループ名を入力してください。', input.value);

      if (value !== null && value.trim()) {
        input.value = value.trim();
        showMessage('グループ名を変更しました。', 'success');
      }
    }
  });

  queryAll('.move-select').forEach(function (select) {
    select.addEventListener('change', function () {
      if (!select.value || select.value === '移動') return;

      const question = select.closest('[data-question]');
      if (!question) return;

      const groups = queryAll('#groupsContainer [data-group]');
      if (groups.length < 2) return;

      let destination = null;

      groups.forEach(function (group) {
        const name = group.querySelector('.group-name');
        if (!name) return;

        if (select.value.indexOf(name.value) >= 0) {
          destination = group;
        }
      });

      if (!destination) {
        destination = groups[1];
      }

      const addButton = destination.querySelector('[data-add-question]');

      if (addButton) {
        destination.insertBefore(question, addButton);
      } else {
        destination.appendChild(question);
      }

      select.selectedIndex = 0;
      renumberQuestions();
      showMessage('質問をグループ間で移動しました。', 'success');
    });
  });

  queryAll('.customer-check').forEach(function (checkbox) {
    checkbox.addEventListener('change', updateSelectedCustomerCount);
  });

  const selectAllCustomers = byId('selectAllCustomers');
  if (selectAllCustomers) {
    selectAllCustomers.addEventListener('click', function () {
      queryAll('.customer-check').forEach(function (checkbox) {
        checkbox.checked = true;
      });
      updateSelectedCustomerCount();
    });
  }

  const clearAllCustomers = byId('clearAllCustomers');
  if (clearAllCustomers) {
    clearAllCustomers.addEventListener('click', function () {
      queryAll('.customer-check').forEach(function (checkbox) {
        checkbox.checked = false;
      });
      updateSelectedCustomerCount();
    });
  }

  const customerSearch = byId('customerSearch');
  if (customerSearch) {
    customerSearch.addEventListener('input', function () {
      const keyword = customerSearch.value.trim().toLowerCase();

      queryAll('#customerSelectList .customer-row').forEach(function (row) {
        const text = row.textContent.toLowerCase();
        row.style.display = !keyword || text.indexOf(keyword) >= 0 ? 'flex' : 'none';
      });
    });
  }

  const customerListSearch = byId('customerListSearch');
  if (customerListSearch) {
    customerListSearch.addEventListener('input', function () {
      filterTableRows('customerListSearch', 'customerTable');
    });
  }

  const issueIndividualUrlButton = byId('issueIndividualUrlButton');
  if (issueIndividualUrlButton) {
    issueIndividualUrlButton.addEventListener('click', function () {
      simulateAction(issueIndividualUrlButton, function () {
        state.individualIssued = true;

        const area = byId('issuedUrlArea');
        const url = byId('issuedUrl');
        const status = byId('individualTokenStatus');

        if (area) area.style.display = 'block';

        if (url) {
          url.value =
            'https://example.com/questionnaire/mock/' +
            'individual/7F4A-2026-XXXX';
        }

        if (status) {
          status.textContent = '未使用';
          status.className = 'badge draft';
        }

        showMessage('個別回答URLを発行しました。', 'success');
      });
    });
  }

  const copyUrlButton = byId('copyUrlButton');
  if (copyUrlButton) {
    copyUrlButton.addEventListener('click', function () {
      const url = byId('issuedUrl');
      if (!url) return;

      simulateAction(copyUrlButton, function () {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url.value).then(function () {
            showMessage('個別回答URLをコピーしました。', 'success');
          }).catch(function () {
            url.select();
            showMessage('URLを選択しました。コピーしてください。', 'success');
          });
        } else {
          url.select();
          showMessage('URLを選択しました。コピーしてください。', 'success');
        }
      }, 300);
    });
  }

  const openIndividualButton = byId('openIndividualButton');
  if (openIndividualButton) {
    openIndividualButton.addEventListener('click', function () {
      if (!state.individualIssued) {
        showMessage('先に個別回答URLを発行してください。', 'error');
        return;
      }

      showPage('respondent');

      const infoStep = byId('individualInfoStep');
      const confirmStep = byId('individualConfirmStep');
      const questionnaireStep = byId('questionnaireStep');
      const completeStep = byId('respondentCompleteStep');
      const usedStep = byId('respondentUsedStep');

      if (completeStep) completeStep.style.display = 'none';
      if (usedStep) usedStep.style.display = 'none';

      if (state.individualAnswered) {
        if (infoStep) infoStep.style.display = 'none';
        if (confirmStep) confirmStep.style.display = 'none';
        if (questionnaireStep) questionnaireStep.style.display = 'none';
        if (usedStep) usedStep.style.display = 'block';
        return;
      }

      if (state.individualInfoEntered) {
        if (infoStep) infoStep.style.display = 'none';
        if (confirmStep) confirmStep.style.display = 'block';
        if (questionnaireStep) questionnaireStep.style.display = 'none';
      } else {
        if (infoStep) infoStep.style.display = 'block';
        if (confirmStep) confirmStep.style.display = 'none';
        if (questionnaireStep) questionnaireStep.style.display = 'none';
      }
    });
  }

  const sendMailButton = byId('sendMailButton');
  if (sendMailButton) {
    sendMailButton.addEventListener('click', function () {
      const selected = queryAll('.customer-check:checked').length;

      if (selected === 0) {
        showMessage('送信対象者を1名以上選択してください。', 'error');
        return;
      }

      simulateAction(sendMailButton, function () {
        showMessage(
          selected + '名へ回答依頼を送信しました。二重送信防止のため送信済み状態を記録しました。',
          'success'
        );
      }, 800);
    });
  }

  const previewMailButton = byId('previewMailButton');
  if (previewMailButton) {
    previewMailButton.addEventListener('click', function () {
      openModal('mailPreviewModal');
    });
  }

  const respondentNextButton = byId('respondentNextButton');
  if (respondentNextButton) {
    respondentNextButton.addEventListener('click', function () {
      const org = byId('respondentOrg');
      const dept = byId('respondentDept');
      const email = byId('respondentEmail');

      if (!org || !dept || !email) return;

      if (!org.value.trim() || !dept.value.trim() || !email.value.trim()) {
        showMessage('組織名・部署名・メールアドレスを入力してください。', 'error');
        return;
      }

      if (!email.checkValidity()) {
        showMessage('メールアドレスの形式を確認してください。', 'error');
        return;
      }

      state.individualInfoEntered = true;

      const confirmOrg = byId('confirmOrg');
      const confirmDept = byId('confirmDept');
      const confirmEmail = byId('confirmEmail');

      if (confirmOrg) confirmOrg.textContent = org.value.trim();
      if (confirmDept) confirmDept.textContent = dept.value.trim();
      if (confirmEmail) confirmEmail.textContent = email.value.trim();

      const infoStep = byId('individualInfoStep');
      const confirmStep = byId('individualConfirmStep');

      if (infoStep) infoStep.style.display = 'none';
      if (confirmStep) confirmStep.style.display = 'block';

      showMessage('回答者情報を登録しました。内容を確認してください。', 'success');

      const individualStatusRow = byId('individualStatusRow');
      if (individualStatusRow) individualStatusRow.style.display = '';

      const individualOrg = byId('individualOrg');
      const individualDept = byId('individualDept');
      const individualEmail = byId('individualEmail');

      if (individualOrg) individualOrg.textContent = org.value.trim();
      if (individualDept) individualDept.textContent = dept.value.trim();
      if (individualEmail) individualEmail.textContent = email.value.trim();

      const tokenStatus = byId('individualTokenStatus');
      if (tokenStatus) {
        tokenStatus.textContent = '回答者情報入力済み';
        tokenStatus.className = 'badge used';
      }
    });
  }

  const respondentBackButton = byId('respondentBackButton');
  if (respondentBackButton) {
    respondentBackButton.addEventListener('click', function () {
      const infoStep = byId('individualInfoStep');
      const confirmStep = byId('individualConfirmStep');

      if (infoStep) infoStep.style.display = 'block';
      if (confirmStep) confirmStep.style.display = 'none';
    });
  }

  const respondentStartButton = byId('respondentStartButton');
  if (respondentStartButton) {
    respondentStartButton.addEventListener('click', function () {
      const infoStep = byId('individualInfoStep');
      const confirmStep = byId('individualConfirmStep');
      const questionnaireStep = byId('questionnaireStep');

      if (infoStep) infoStep.style.display = 'none';
      if (confirmStep) confirmStep.style.display = 'none';
      if (questionnaireStep) questionnaireStep.style.display = 'block';

      showMessage('アンケート回答画面へ進みました。', 'success');
    });
  }

  const submitAnswerButton = byId('submitAnswerButton');
  if (submitAnswerButton) {
    submitAnswerButton.addEventListener('click', function () {
      const selected = document.querySelector('input[name="rq1"]:checked');

      if (!selected) {
        showMessage('Q1は必須です。回答を選択してください。', 'error');
        return;
      }

      simulateAction(submitAnswerButton, function () {
        state.individualAnswered = true;

        const questionnaireStep = byId('questionnaireStep');
        const completeStep = byId('respondentCompleteStep');

        if (questionnaireStep) questionnaireStep.style.display = 'none';
        if (completeStep) completeStep.style.display = 'block';

        const tokenStatus = byId('individualTokenStatus');
        if (tokenStatus) {
          tokenStatus.textContent = '回答済み';
          tokenStatus.className = 'badge open';
        }

        const answerStatus = byId('individualAnswerStatus');
        if (answerStatus) {
          answerStatus.textContent = '回答済み';
          answerStatus.className = 'badge open';
        }

        const answerDate = byId('individualAnswerDate');
        if (answerDate) {
          answerDate.textContent = '2026/09/25 14:20';
        }

        showMessage('回答を送信しました。回答済みとして記録しました。', 'success');
      }, 800);
    });
  }

  queryAll('[data-settings-tab]').forEach(function (button) {
    button.addEventListener('click', function () {
      const tab = button.getAttribute('data-settings-tab');

      queryAll('[data-settings-tab]').forEach(function (item) {
        item.classList.remove('active');
      });

      button.classList.add('active');

      queryAll('.settings-panel').forEach(function (panel) {
        panel.style.display = 'none';
      });

      const target = byId('settings-' + tab);
      if (target) {
        target.style.display = 'block';
      }
    });
  });

  const smtpTestButton = byId('smtpTestButton');
  if (smtpTestButton) {
    smtpTestButton.addEventListener('click', function () {
      simulateAction(smtpTestButton, function () {
        showMessage('SMTP接続に成功しました。', 'success');
      });
    });
  }

  const smtpTestMailButton = byId('smtpTestMailButton');
  if (smtpTestMailButton) {
    smtpTestMailButton.addEventListener('click', function () {
      const address = window.prompt('テスト送信先メールアドレスを入力してください。', 'test@example.com');

      if (!address) return;

      simulateAction(smtpTestMailButton, function () {
        showMessage(address + ' へテストメールを送信しました。', 'success');
      });
    });
  }

  const smtpSaveButton = byId('smtpSaveButton');
  if (smtpSaveButton) {
    smtpSaveButton.addEventListener('click', function () {
      simulateAction(smtpSaveButton, function () {
        showMessage('SMTP設定を保存しました。', 'success');
      });
    });
  }

  const kintoneTestButton = byId('kintoneTestButton');
  if (kintoneTestButton) {
    kintoneTestButton.addEventListener('click', function () {
      simulateAction(kintoneTestButton, function () {
        const status = byId('kintoneStatus');

        if (status) {
          status.className = 'notice success';
          status.textContent =
            '接続確認：成功　／　kintoneへの接続を確認しました。';
        }

        showMessage('kintone接続確認に成功しました。', 'success');
      });
    });
  }

  const kintoneFieldsButton = byId('kintoneFieldsButton');
  if (kintoneFieldsButton) {
    kintoneFieldsButton.addEventListener('click', function () {
      simulateAction(kintoneFieldsButton, function () {
        showMessage('kintoneのフィールド定義を取得しました。', 'success');
      });
    });
  }

  const kintoneSaveButton = byId('kintoneSaveButton');
  if (kintoneSaveButton) {
    kintoneSaveButton.addEventListener('click', function () {
      simulateAction(kintoneSaveButton, function () {
        showMessage('kintone設定を保存しました。', 'success');
      });
    });
  }

  const kintoneSyncButton = byId('kintoneSyncButton');
  if (kintoneSyncButton) {
    kintoneSyncButton.addEventListener('click', function () {
      simulateAction(kintoneSyncButton, function () {
        showMessage('kintoneから顧客情報を取得し、顧客一覧を更新しました。', 'success');
      }, 900);
    });
  }

  const kintoneCustomerSyncButton = byId('kintoneCustomerSyncButton');
  if (kintoneCustomerSyncButton) {
    kintoneCustomerSyncButton.addEventListener('click', function () {
      simulateAction(kintoneCustomerSyncButton, function () {
        showMessage('kintoneから最新の顧客情報を取得しました。', 'success');
      });
    });
  }

  const mappingSaveButton = byId('mappingSaveButton');
  if (mappingSaveButton) {
    mappingSaveButton.addEventListener('click', function () {
      simulateAction(mappingSaveButton, function () {
        showMessage('kintone項目マッピングを保存しました。', 'success');
      });
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;

    queryAll('.modal-overlay.active').forEach(function (modal) {
      modal.classList.remove('active');
    });
  });

  queryAll('.modal-overlay').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        modal.classList.remove('active');
      }
    });
  });

  updateSelectedCustomerCount();
  renumberQuestions();
  switchDetailTab('content');
});
</script>

</body>
</html>
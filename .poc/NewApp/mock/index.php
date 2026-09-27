<?php
// アンケート管理システム モック
// Apache / PHP でそのまま表示可能
// モックのため、データはブラウザ内のJavaScriptで保持します。
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート運営モック</title>
<style>
* { box-sizing:border-box; }
html,body { margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif; color:#222; background:#f5f6f8; font-size:14px; }
button,input,textarea,select { font:inherit; }
button { cursor:pointer; }

.main-header {
  position:sticky;
  top:0;
  z-index:1000;
  height:62px;
  display:flex;
  align-items:center;
  gap:30px;
  padding:0 24px;
  color:#fff;
  background:#243447;
  box-shadow:0 2px 8px rgba(0,0,0,.15);
}
.logo {
  font-size:17px;
  font-weight:bold;
  white-space:nowrap;
}
.main-header nav {
  display:flex;
  align-items:stretch;
  height:100%;
  gap:4px;
}
.main-header nav a {
  display:flex;
  align-items:center;
  padding:0 17px;
  color:#dfe7ee;
  text-decoration:none;
  cursor:pointer;
  white-space:nowrap;
  border-bottom:3px solid transparent;
}
.main-header nav a:hover { background:#30485d; color:#fff; }
.main-header nav a.active {
  color:#fff;
  background:#30485d;
  border-bottom-color:#4aa3ff;
}

.page {
  display:none;
  max-width:1280px;
  margin:0 auto;
  padding:28px 28px 80px;
}
.page.active { display:block; }

.page-title {
  margin:0 0 20px;
  font-size:25px;
  font-weight:bold;
}
.page-subtitle {
  color:#666;
  margin:-12px 0 20px;
}
.toolbar {
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  margin-bottom:14px;
}
.toolbar-left,.toolbar-right {
  display:flex;
  align-items:center;
  gap:8px;
}

.card {
  background:#fff;
  border:1px solid #ddd;
  border-radius:7px;
  padding:20px;
  margin-bottom:18px;
  box-shadow:0 1px 3px rgba(0,0,0,.04);
}
.card h2 {
  margin:0 0 15px;
  font-size:18px;
}
.card h3 {
  margin:0 0 12px;
  font-size:15px;
}

table {
  width:100%;
  border-collapse:collapse;
  background:#fff;
  border:1px solid #ddd;
}
th,td {
  border-bottom:1px solid #e6e6e6;
  padding:12px 11px;
  text-align:left;
  vertical-align:middle;
}
th {
  background:#f7f8fa;
  font-weight:bold;
  white-space:nowrap;
}
tr:last-child td { border-bottom:none; }

.btn {
  border:1px solid #2788d9;
  border-radius:5px;
  background:#2788d9;
  color:#fff;
  padding:8px 14px;
  min-height:36px;
}
.btn:hover { background:#176faf; }
.btn.secondary {
  background:#fff;
  border-color:#bbb;
  color:#333;
}
.btn.secondary:hover { background:#f3f3f3; }
.btn.success {
  background:#198754;
  border-color:#198754;
}
.btn.warning {
  background:#e08a00;
  border-color:#e08a00;
}
.btn.danger {
  background:#d9534f;
  border-color:#d9534f;
}
.btn.small {
  min-height:30px;
  padding:5px 9px;
  font-size:12px;
}

.action-link {
  color:#1673b8;
  cursor:pointer;
  margin-right:12px;
  white-space:nowrap;
}
.action-link:hover { text-decoration:underline; }

.badge {
  display:inline-block;
  padding:4px 8px;
  border-radius:12px;
  font-size:12px;
  white-space:nowrap;
}
.badge.draft { background:#eee; color:#555; }
.badge.open { background:#d9f4e3; color:#18733c; }
.badge.closed { background:#e6e6e6; color:#666; }
.badge.sent { background:#dbeeff; color:#176da8; }
.badge.done { background:#d9f4e3; color:#18733c; }
.badge.error { background:#ffe0de; color:#b52d28; }
.badge.pending { background:#fff0c9; color:#8a6200; }

.form-grid {
  display:grid;
  grid-template-columns:180px 1fr;
  gap:12px 20px;
  align-items:center;
}
.form-grid > label {
  font-weight:bold;
}
.form-grid input,
.form-grid textarea,
.form-grid select,
.field input,
.field textarea,
.field select {
  width:100%;
  border:1px solid #ccc;
  border-radius:5px;
  padding:9px 10px;
  background:#fff;
}
.form-grid textarea { min-height:90px; resize:vertical; }

.form-actions {
  display:flex;
  justify-content:flex-end;
  gap:8px;
  margin-top:20px;
}

.notice {
  padding:12px 14px;
  border-radius:5px;
  margin-bottom:15px;
}
.notice.info {
  background:#edf6ff;
  border:1px solid #b9dcfa;
}
.notice.warning {
  background:#fff7df;
  border:1px solid #f0d88a;
}
.notice.error {
  background:#fff;
  border:1px solid #d9534f;
  color:#a12622;
}
.notice.success {
  background:#fff;
  border:1px solid #39a866;
  color:#196d3b;
}

.tabs {
  display:flex;
  border-bottom:1px solid #ccc;
  margin-bottom:18px;
  gap:3px;
}
.tab {
  padding:11px 18px;
  background:#f0f1f3;
  border:1px solid #d5d5d5;
  border-bottom:none;
  border-radius:6px 6px 0 0;
  cursor:pointer;
}
.tab.active {
  background:#fff;
  font-weight:bold;
  color:#1673b8;
}

.stat-grid {
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:14px;
  margin-bottom:18px;
}
.stat {
  background:#fff;
  border:1px solid #ddd;
  border-radius:7px;
  padding:18px;
}
.stat .label { color:#666; font-size:12px; }
.stat .value { font-size:28px; font-weight:bold; margin-top:6px; }

.group-card {
  background:#fff;
  border:1px solid #ccc;
  border-radius:7px;
  margin-bottom:16px;
  overflow:hidden;
}
.group-header {
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:13px 15px;
  background:#f2f5f8;
  border-bottom:1px solid #ddd;
}
.group-header strong { font-size:15px; }
.group-actions {
  display:flex;
  gap:6px;
}
.question-card {
  margin:12px;
  border:1px solid #ddd;
  border-radius:6px;
  background:#fff;
}
.question-header {
  display:flex;
  align-items:center;
  gap:10px;
  padding:10px 12px;
  border-bottom:1px solid #eee;
}
.move-handle {
  color:#777;
  cursor:grab;
  font-size:18px;
}
.question-body { padding:12px; }
.question-number {
  color:#777;
  font-size:12px;
}
.question-type {
  margin-left:auto;
  color:#666;
  font-size:12px;
}
.choice {
  padding:4px 0;
  color:#555;
}
.branch {
  margin-top:10px;
  padding:8px;
  background:#fafafa;
  border-left:3px solid #4aa3ff;
  font-size:12px;
}

.two-column {
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:18px;
}
.three-column {
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:14px;
}

.customer-select-list {
  max-height:330px;
  overflow:auto;
  border:1px solid #ddd;
  border-radius:5px;
}
.customer-row {
  display:flex;
  align-items:center;
  gap:10px;
  padding:10px 12px;
  border-bottom:1px solid #eee;
}
.customer-row:last-child { border-bottom:none; }
.customer-row input { width:auto; }

.url-box {
  display:flex;
  gap:8px;
  margin:15px 0;
}
.url-box input {
  flex:1;
  padding:10px;
  border:1px solid #ccc;
  border-radius:5px;
  background:#f8f8f8;
}

.respondent-page {
  max-width:760px;
  margin:35px auto;
  background:#fff;
  border:1px solid #ddd;
  border-radius:8px;
  padding:30px;
  box-shadow:0 2px 8px rgba(0,0,0,.06);
}
.respondent-page h1 {
  margin:0 0 8px;
  font-size:23px;
}
.respondent-desc {
  color:#666;
  margin-bottom:25px;
}
.r-question {
  margin-bottom:25px;
  padding-bottom:20px;
  border-bottom:1px solid #eee;
}
.r-question:last-child { border-bottom:none; }
.r-title {
  font-weight:bold;
  margin-bottom:10px;
}
.required { color:#d9534f; margin-left:5px; font-size:12px; }
.r-question input[type=text],
.r-question input[type=email],
.r-question textarea {
  width:100%;
  padding:10px;
  border:1px solid #ccc;
  border-radius:5px;
}
.r-question textarea { min-height:100px; }
.r-choice {
  display:block;
  margin:9px 0;
}
.center { text-align:center; }

.modal {
  display:none;
  position:fixed;
  inset:0;
  z-index:2000;
  background:rgba(0,0,0,.45);
  align-items:center;
  justify-content:center;
  padding:20px;
}
.modal.active { display:flex; }
.modal-box {
  width:min(600px,100%);
  max-height:90vh;
  overflow:auto;
  background:#fff;
  border-radius:8px;
  padding:24px;
  box-shadow:0 8px 30px rgba(0,0,0,.25);
}
.modal-box h2 {
  margin:0 0 15px;
  font-size:19px;
}
.modal-actions {
  display:flex;
  justify-content:flex-end;
  gap:8px;
  margin-top:20px;
}

.toast-area {
  position:fixed;
  left:20px;
  right:20px;
  bottom:20px;
  z-index:3000;
  pointer-events:none;
}
.toast {
  max-width:900px;
  margin:0 auto 10px;
  padding:13px 16px;
  border-radius:6px;
  background:#fff;
  border:2px solid #d9534f;
  color:#222;
  box-shadow:0 4px 15px rgba(0,0,0,.15);
  pointer-events:auto;
  position:relative;
}
.toast.success { border-color:#39a866; }
.toast .close-toast {
  float:right;
  border:0;
  background:transparent;
  font-size:18px;
  color:#555;
}

.empty {
  text-align:center;
  color:#777;
  padding:40px 20px;
}

.log-row-error { background:#fff7f6; }

@media(max-width:900px) {
  .main-header {
    height:auto;
    min-height:62px;
    flex-wrap:wrap;
    padding:10px 15px;
    gap:8px;
  }
  .main-header nav {
    height:45px;
    width:100%;
    overflow-x:auto;
  }
  .main-header nav a { padding:0 12px; }
  .stat-grid { grid-template-columns:repeat(2,1fr); }
  .two-column,.three-column { grid-template-columns:1fr; }
  .form-grid { grid-template-columns:1fr; gap:5px; }
  .page { padding:20px 15px 70px; }
  table { font-size:12px; }
  th,td { padding:9px 7px; }
}
</style>
</head>

<body>

<header class="main-header">
  <div class="logo">📋 アンケート運営</div>
  <nav>
    <a id="nav-list" class="active" onclick="showPage('list')">アンケート一覧</a>
    <a id="nav-new" onclick="openNewSurvey()">新規アンケート作成</a>
    <a id="nav-customers" onclick="showPage('customers')">顧客一覧</a>
    <a id="nav-settings" onclick="showPage('settings')">設定</a>
  </nav>
</header>

<!-- =========================================================
     アンケート一覧
========================================================= -->
<section class="page active" id="page-list">
  <h1 class="page-title">アンケート一覧</h1>
  <div class="toolbar">
    <div>
      <span style="color:#666;">登録されているアンケートを管理します。</span>
    </div>
    <button class="btn" onclick="openNewSurvey()">＋ 新規アンケート作成</button>
  </div>

  <table>
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
    <tbody id="survey-list-body"></tbody>
  </table>
</section>

<!-- =========================================================
     アンケート編集
========================================================= -->
<section class="page" id="page-editor">
  <h1 class="page-title" id="editor-title">アンケート作成</h1>

  <div class="toolbar">
    <div>
      <button class="btn secondary" onclick="showPage('list')">← 一覧へ戻る</button>
    </div>
    <div class="toolbar-right">
      <button class="btn secondary" onclick="saveDraft()">下書き保存</button>
      <button class="btn success" onclick="publishFromEditor()">公開する</button>
    </div>
  </div>

  <div id="editor-message"></div>

  <div class="card">
    <h2>基本情報</h2>
    <div class="form-grid">
      <label>アンケート名</label>
      <input id="survey-name" value="新規アンケート">

      <label>説明</label>
      <textarea id="survey-description">アンケートへのご協力をお願いいたします。</textarea>

      <label>開始日時</label>
      <input id="survey-start" type="datetime-local" value="2026-10-01T09:00">

      <label>終了日時</label>
      <input id="survey-end" type="datetime-local" value="2026-10-31T18:00">

      <label>質問番号形式</label>
      <select id="numbering-format">
        <option value="group">グループごとに Q1-1、Q1-2</option>
        <option value="global">全体で Q1、Q2、Q3</option>
      </select>

      <label>状態</label>
      <div><span class="badge draft">下書き</span></div>
    </div>
  </div>

  <div class="card">
    <h2>質問・グループ</h2>
    <div id="groups-container"></div>

    <button class="btn secondary" onclick="addGroup()">＋ グループを追加</button>
  </div>

  <div class="card">
    <div class="form-actions">
      <button class="btn secondary" onclick="showPage('list')">キャンセル</button>
      <button class="btn secondary" onclick="saveDraft()">下書き保存</button>
      <button class="btn success" onclick="publishFromEditor()">公開する</button>
    </div>
  </div>
</section>

<!-- =========================================================
     アンケート詳細
========================================================= -->
<section class="page" id="page-detail">
  <div class="toolbar">
    <div>
      <h1 class="page-title" style="margin-bottom:4px;">顧客満足度調査 2026上期</h1>
      <div><span class="badge open">公開中</span></div>
    </div>
    <div class="toolbar-right">
      <button class="btn secondary" onclick="openEditor()">編集</button>
      <button class="btn warning" onclick="endSurvey()">終了する</button>
    </div>
  </div>

  <div class="tabs" id="detail-tabs">
    <div class="tab active" data-tab="content" onclick="switchDetailTab('content')">アンケート内容</div>
    <div class="tab" data-tab="send" onclick="switchDetailTab('send')">送信</div>
    <div class="tab" data-tab="status" onclick="switchDetailTab('status')">回答状況</div>
    <div class="tab" data-tab="result" onclick="switchDetailTab('result')">回答結果</div>
    <div class="tab" data-tab="summary" onclick="switchDetailTab('summary')">集計</div>
  </div>

  <div id="tab-content" class="tab-content">
    <div class="card">
      <h2>アンケート内容</h2>
      <p>顧客満足度調査のサンプルアンケートです。</p>
      <div id="detail-question-list"></div>
    </div>
  </div>

  <div id="tab-send" class="tab-content" style="display:none;">
    <div class="card">
      <h2>回答依頼</h2>
      <div class="notice info">
        公開済みアンケートの回答依頼を行います。通常回答者への送信と、個別回答URLの発行ができます。
      </div>

      <div class="two-column">
        <div class="card" style="margin:0;">
          <h3>通常回答者への回答依頼</h3>
          <p style="color:#666;">顧客一覧から送信対象者を選択します。</p>
          <button class="btn" onclick="openSendModal()">回答依頼を作成</button>
        </div>

        <div class="card" style="margin:0;">
          <h3>個別回答URL</h3>
          <p style="color:#666;">顧客一覧に登録されていない人にも回答してもらえます。</p>
          <button class="btn" onclick="issueIndividualUrl()">個別回答URLを発行</button>
        </div>
      </div>

      <div class="card" style="margin-top:18px;">
        <h3>送信ログ</h3>
        <table>
          <thead>
            <tr>
              <th>対象者</th>
              <th>メールアドレス</th>
              <th>送信日時</th>
              <th>結果</th>
              <th>エラー</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody id="send-log-body">
            <tr>
              <td>株式会社サンプル　山田 太郎</td>
              <td>yamada@example.com</td>
              <td>2026/09/20 10:30</td>
              <td><span class="badge sent">送信済み</span></td>
              <td>—</td>
              <td>—</td>
            </tr>
            <tr class="log-row-error">
              <td>株式会社テスト　佐藤 花子</td>
              <td>sato@example.com</td>
              <td>2026/09/20 10:31</td>
              <td><span class="badge error">送信失敗</span></td>
              <td>SMTP接続エラー</td>
              <td><button class="btn small" onclick="showToast('佐藤 花子さんへ再送しました。','success')">再送</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div id="tab-status" class="tab-content" style="display:none;">
    <div class="stat-grid">
      <div class="stat">
        <div class="label">送信対象</div>
        <div class="value">5</div>
      </div>
      <div class="stat">
        <div class="label">送信済み</div>
        <div class="value">5</div>
      </div>
      <div class="stat">
        <div class="label">回答済み</div>
        <div class="value" id="answered-count">3</div>
      </div>
      <div class="stat">
        <div class="label">未回答</div>
        <div class="value" id="unanswered-count">2</div>
      </div>
    </div>

    <div class="card">
      <h2>回答状況</h2>
      <table>
        <thead>
          <tr>
            <th>回答者種別</th>
            <th>組織名</th>
            <th>部署名</th>
            <th>氏名</th>
            <th>メールアドレス</th>
            <th>状態</th>
            <th>送信日時</th>
            <th>回答日時</th>
          </tr>
        </thead>
        <tbody id="status-body"></tbody>
      </table>
    </div>
  </div>

  <div id="tab-result" class="tab-content" style="display:none;">
    <div class="card">
      <h2>回答結果一覧</h2>
      <table>
        <thead>
          <tr>
            <th>回答ID</th>
            <th>組織名</th>
            <th>部署名</th>
            <th>メールアドレス</th>
            <th>回答日時</th>
            <th>結果</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>R-00001</td>
            <td>株式会社サンプル</td>
            <td>営業部</td>
            <td>yamada@example.com</td>
            <td>2026/09/21 11:22</td>
            <td><button class="btn small" onclick="showResultDetail('R-00001')">回答を見る</button></td>
          </tr>
          <tr>
            <td>R-00002</td>
            <td>株式会社テスト</td>
            <td>管理部</td>
            <td>tanaka@example.com</td>
            <td>2026/09/22 09:14</td>
            <td><button class="btn small" onclick="showResultDetail('R-00002')">回答を見る</button></td>
          </tr>
          <tr>
            <td>R-00003</td>
            <td>合同会社サンプル</td>
            <td>企画部</td>
            <td>suzuki@example.com</td>
            <td>2026/09/23 15:40</td>
            <td><button class="btn small" onclick="showResultDetail('R-00003')">回答を見る</button></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div id="tab-summary" class="tab-content" style="display:none;">
    <div class="stat-grid">
      <div class="stat"><div class="label">回答数</div><div class="value">3</div></div>
      <div class="stat"><div class="label">回答率</div><div class="value">60%</div></div>
      <div class="stat"><div class="label">未回答</div><div class="value">2</div></div>
      <div class="stat"><div class="label">集計対象</div><div class="value">3</div></div>
    </div>

    <div class="card">
      <h2>集計</h2>
      <h3>Q1-1　今回のサービスに満足していますか？</h3>
      <table>
        <thead>
          <tr><th>選択肢</th><th>回答数</th><th>割合</th></tr>
        </thead>
        <tbody>
          <tr><td>とても満足</td><td>2</td><td>66.7%</td></tr>
          <tr><td>満足</td><td>1</td><td>33.3%</td></tr>
          <tr><td>普通</td><td>0</td><td>0%</td></tr>
          <tr><td>不満</td><td>0</td><td>0%</td></tr>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h3>Q1-2　ご意見・ご要望</h3>
      <p>「担当者の対応が丁寧でした。」</p>
      <p>「今後も継続して利用したいです。」</p>
      <p>「回答画面が分かりやすかったです。」</p>
    </div>
  </div>
</section>

<!-- =========================================================
     顧客一覧
========================================================= -->
<section class="page" id="page-customers">
  <h1 class="page-title">顧客一覧</h1>

  <div class="toolbar">
    <div class="toolbar-left">
      <input id="customer-search" placeholder="組織名・氏名・メールアドレスで検索" style="width:320px;padding:9px;border:1px solid #ccc;border-radius:5px;" oninput="filterCustomers()">
      <button class="btn secondary" onclick="filterCustomers()">検索</button>
    </div>
    <button class="btn" onclick="openCustomerModal()">＋ 顧客を追加</button>
  </div>

  <table>
    <thead>
      <tr>
        <th><input type="checkbox" onclick="toggleAllCustomers(this)"></th>
        <th>組織名</th>
        <th>部署名</th>
        <th>氏名</th>
        <th>メールアドレス</th>
        <th>回答依頼</th>
        <th>操作</th>
      </tr>
    </thead>
    <tbody id="customer-body"></tbody>
  </table>
</section>

<!-- =========================================================
     設定
========================================================= -->
<section class="page" id="page-settings">
  <h1 class="page-title">設定</h1>

  <div class="tabs">
    <div class="tab active" data-setting-tab="smtp" onclick="switchSettingTab('smtp')">SMTP設定</div>
    <div class="tab" data-setting-tab="kintone" onclick="switchSettingTab('kintone')">kintone設定</div>
    <div class="tab" data-setting-tab="mapping" onclick="switchSettingTab('mapping')">kintone項目マッピング</div>
  </div>

  <div id="setting-smtp">
    <div class="card">
      <h2>SMTP設定</h2>
      <div class="form-grid">
        <label>SMTPホスト</label>
        <input value="smtp.example.com">

        <label>ポート</label>
        <input value="587">

        <label>暗号化方式</label>
        <select>
          <option>なし</option>
          <option selected>TLS</option>
          <option>SSL</option>
        </select>

        <label>ユーザー名</label>
        <input value="mailer@example.com">

        <label>パスワード</label>
        <input type="password" value="password">

        <label>送信元メールアドレス</label>
        <input value="mailer@example.com">

        <label>送信元名</label>
        <input value="アンケート運営事務局">
      </div>

      <div class="form-actions">
        <button class="btn secondary" onclick="testSmtpConnection()">SMTP接続確認</button>
        <button class="btn secondary" onclick="openTestMailModal()">テストメール送信</button>
        <button class="btn" onclick="saveSettings('SMTP設定を保存しました。')">保存する</button>
      </div>
    </div>

    <div class="card">
      <h2>SMTP送信ログ</h2>
      <table>
        <thead>
          <tr>
            <th>日時</th>
            <th>処理</th>
            <th>結果</th>
            <th>内容</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>2026/09/24 15:10</td>
            <td>接続確認</td>
            <td><span class="badge done">成功</span></td>
            <td>SMTPサーバーへ接続しました。</td>
          </tr>
          <tr>
            <td>2026/09/24 15:12</td>
            <td>テストメール</td>
            <td><span class="badge done">成功</span></td>
            <td>テストメールを送信しました。</td>
          </tr>
          <tr class="log-row-error">
            <td>2026/09/20 10:31</td>
            <td>アンケート送信</td>
            <td><span class="badge error">失敗</span></td>
            <td>SMTP接続エラー</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div id="setting-kintone" style="display:none;">
    <div class="card">
      <h2>kintone設定</h2>
      <div class="notice info">
        kintone連携先を設定します。モックでは接続・項目取得・同期操作を画面上で確認できます。
      </div>

      <div class="form-grid">
        <label>サブドメイン</label>
        <input value="example">

        <label>アプリID</label>
        <input value="123">

        <label>ログイン名</label>
        <input value="kintone_user">

        <label>パスワード</label>
        <input type="password" value="password">

        <label>アプリ名</label>
        <input value="顧客管理">

        <label>メール項目</label>
        <input value="メールアドレス">

        <label>プロキシホスト</label>
        <input placeholder="proxy.example.com">

        <label>プロキシポート</label>
        <input placeholder="8080">
      </div>

      <div class="form-actions">
        <button class="btn secondary" onclick="testKintone()">接続確認</button>
        <button class="btn secondary" onclick="fetchKintoneFields()">kintone項目を取得</button>
        <button class="btn" onclick="saveSettings('kintone設定を保存しました。')">保存する</button>
      </div>
    </div>

    <div class="card">
      <h2>kintone同期</h2>
      <div class="toolbar">
        <div>
          <span class="status-dot ok"></span>
          最終同期：2026/09/24 14:20
        </div>
        <button class="btn" onclick="syncKintone()">kintoneへ同期</button>
      </div>

      <table>
        <thead>
          <tr>
            <th>日時</th>
            <th>対象</th>
            <th>結果</th>
            <th>件数</th>
            <th>エラー</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>2026/09/24 14:20</td>
            <td>顧客情報</td>
            <td><span class="badge done">成功</span></td>
            <td>128</td>
            <td>—</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div id="setting-mapping" style="display:none;">
    <div class="card">
      <h2>kintone項目マッピング</h2>
      <div class="notice warning">
        kintoneから取得した項目をアンケート側の項目へ割り当てます。
      </div>

      <table>
        <thead>
          <tr>
            <th>アンケート項目</th>
            <th>kintone項目</th>
            <th>説明</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>組織名</td>
            <td>
              <select>
                <option>会社名</option>
                <option>顧客名</option>
              </select>
            </td>
            <td>組織名を同期</td>
          </tr>
          <tr>
            <td>部署名</td>
            <td>
              <select>
                <option>部署名</option>
                <option>所属</option>
              </select>
            </td>
            <td>部署名を同期</td>
          </tr>
          <tr>
            <td>メールアドレス</td>
            <td>
              <select>
                <option>メールアドレス</option>
                <option>連絡先メール</option>
              </select>
            </td>
            <td>メールアドレスを同期</td>
          </tr>
          <tr>
            <td>電話番号</td>
            <td>
              <select>
                <option>電話番号</option>
                <option>携帯電話</option>
              </select>
            </td>
            <td>電話番号を同期</td>
          </tr>
        </tbody>
      </table>

      <div class="form-actions">
        <button class="btn" onclick="saveSettings('kintone項目マッピングを保存しました。')">マッピングを保存</button>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================
     個別回答者：情報入力
========================================================= -->
<section class="page" id="page-individual-info">
  <div class="respondent-page">
    <h1>顧客満足度調査 2026上期</h1>
    <div class="respondent-desc">
      回答を開始する前に、回答者情報を入力してください。
    </div>

    <div class="notice info">
      この画面は個別回答URLからアクセスした回答者向け画面です。
    </div>

    <div class="r-question">
      <div class="r-title">組織名<span class="required">必須</span></div>
      <input id="individual-org" value="">
    </div>

    <div class="r-question">
      <div class="r-title">部署名<span class="required">必須</span></div>
      <input id="individual-dept" value="">
    </div>

    <div class="r-question">
      <div class="r-title">メールアドレス<span class="required">必須</span></div>
      <input id="individual-email" type="email" value="">
    </div>

    <div class="form-actions">
      <button class="btn" onclick="confirmIndividualInfo()">回答へ進む</button>
    </div>
  </div>
</section>

<!-- =========================================================
     個別回答者：確認
========================================================= -->
<section class="page" id="page-individual-confirm">
  <div class="respondent-page">
    <h1>入力内容の確認</h1>
    <div class="respondent-desc">
      以下の内容で登録して回答へ進みます。
    </div>

    <div class="card">
      <table>
        <tr><th style="width:180px;">組織名</th><td id="confirm-org"></td></tr>
        <tr><th>部署名</th><td id="confirm-dept"></td></tr>
        <tr><th>メールアドレス</th><td id="confirm-email"></td></tr>
      </table>
    </div>

    <div class="form-actions">
      <button class="btn secondary" onclick="showPage('individual-info')">修正する</button>
      <button class="btn" onclick="startIndividualAnswer()">アンケート回答へ進む</button>
    </div>
  </div>
</section>

<!-- =========================================================
     回答画面
========================================================= -->
<section class="page" id="page-answer">
  <div class="respondent-page">
    <h1>顧客満足度調査 2026上期</h1>
    <div class="respondent-desc">
      株式会社サンプル　営業部<br>
      yamada@example.com
    </div>

    <div class="r-question">
      <div class="r-title">Q1-1　今回のサービスに満足していますか？<span class="required">必須</span></div>
      <label class="r-choice"><input type="radio" name="q1"> とても満足</label>
      <label class="r-choice"><input type="radio" name="q1"> 満足</label>
      <label class="r-choice"><input type="radio" name="q1"> 普通</label>
      <label class="r-choice"><input type="radio" name="q1"> 不満</label>
    </div>

    <div class="r-question">
      <div class="r-title">Q1-2　今後も利用したいと思いますか？<span class="required">必須</span></div>
      <label class="r-choice"><input type="radio" name="q2"> はい</label>
      <label class="r-choice"><input type="radio" name="q2"> いいえ</label>
    </div>

    <div class="r-question">
      <div class="r-title">Q1-3　ご意見・ご要望</div>
      <textarea id="answer-comment"></textarea>
    </div>

    <div class="form-actions">
      <button class="btn" onclick="submitAnswer()">回答を送信</button>
    </div>
  </div>
</section>

<!-- =========================================================
     回答完了
========================================================= -->
<section class="page" id="page-answer-complete">
  <div class="respondent-page center">
    <div style="font-size:48px;">✓</div>
    <h1>回答ありがとうございました</h1>
    <p class="respondent-desc">
      アンケートの回答を受け付けました。
    </p>
    <div class="notice success">
      回答ID：R-IND-00001
    </div>
    <button class="btn secondary" onclick="showPage('list')">管理画面を表示</button>
  </div>
</section>

<!-- =========================================================
     モーダル：公開
========================================================= -->
<div class="modal" id="modal-publish">
  <div class="modal-box">
    <h2>アンケートを公開</h2>
    <div class="notice warning">
      公開すると回答者が回答できる状態になります。公開時にアンケート内容を検証します。
    </div>
    <ul>
      <li>アンケート名：顧客満足度調査 2026上期</li>
      <li>開始：2026/10/01 09:00</li>
      <li>終了：2026/10/31 18:00</li>
    </ul>
    <div class="modal-actions">
      <button class="btn secondary" onclick="closeModal('modal-publish')">キャンセル</button>
      <button class="btn success" onclick="publishSurvey()">公開する</button>
    </div>
  </div>
</div>

<!-- =========================================================
     モーダル：終了
========================================================= -->
<div class="modal" id="modal-close">
  <div class="modal-box">
    <h2>アンケートを終了</h2>
    <p>終了すると新しい回答を受け付けなくなります。</p>
    <div class="modal-actions">
      <button class="btn secondary" onclick="closeModal('modal-close')">キャンセル</button>
      <button class="btn warning" onclick="endSurvey()">終了する</button>
    </div>
  </div>
</div>

<!-- =========================================================
     モーダル：削除
========================================================= -->
<div class="modal" id="modal-delete">
  <div class="modal-box">
    <h2>アンケートを削除</h2>
    <p>アンケートを削除します。モックでは実データは削除されません。</p>
    <div class="modal-actions">
      <button class="btn secondary" onclick="closeModal('modal-delete')">キャンセル</button>
      <button class="btn danger" onclick="deleteSurvey()">削除する</button>
    </div>
  </div>
</div>

<!-- =========================================================
     モーダル：送信
========================================================= -->
<div class="modal" id="modal-send">
  <div class="modal-box">
    <h2>回答依頼</h2>
    <p>回答依頼を送信する対象者を選択してください。</p>

    <div class="customer-select-list">
      <label class="customer-row">
        <input type="checkbox" class="send-target" value="1" checked>
        <span>株式会社サンプル　山田 太郎（yamada@example.com）</span>
      </label>
      <label class="customer-row">
        <input type="checkbox" class="send-target" value="2" checked>
        <span>株式会社テスト　佐藤 花子（sato@example.com）</span>
      </label>
      <label class="customer-row">
        <input type="checkbox" class="send-target" value="3">
        <span>合同会社サンプル　鈴木 一郎（suzuki@example.com）</span>
      </label>
      <label class="customer-row">
        <input type="checkbox" class="send-target" value="4">
        <span>株式会社ABC　田中 次郎（tanaka@example.com）</span>
      </label>
    </div>

    <div class="notice warning" style="margin-top:15px;">
      送信済みの回答者へは二重送信しないよう管理します。
    </div>

    <div class="modal-actions">
      <button class="btn secondary" onclick="closeModal('modal-send')">キャンセル</button>
      <button class="btn" onclick="sendSurvey()">送信する</button>
    </div>
  </div>
</div>

<!-- =========================================================
     モーダル：個別URL
========================================================= -->
<div class="modal" id="modal-individual-url">
  <div class="modal-box">
    <h2>個別回答URLを発行しました</h2>

    <div class="notice success">
      個別回答URLを発行しました。
    </div>

    <div class="url-box">
      <input id="individual-url" value="https://example.com/index.php?token=IND-20260925-A8F31" readonly>
      <button class="btn" onclick="copyIndividualUrl()">コピー</button>
    </div>

    <p style="font-size:12px;color:#666;">
      このURLを対象者へ連絡してください。個別回答者はURLから組織名・部署名・メールアドレスを入力して回答できます。
    </p>

    <div class="modal-actions">
      <button class="btn secondary" onclick="closeModal('modal-individual-url')">閉じる</button>
      <button class="btn" onclick="openIndividualAnswer()">個別回答画面を開く</button>
    </div>
  </div>
</div>

<!-- =========================================================
     モーダル：顧客追加
========================================================= -->
<div class="modal" id="modal-customer">
  <div class="modal-box">
    <h2>顧客を追加</h2>
    <div class="form-grid">
      <label>組織名</label>
      <input id="new-org">

      <label>部署名</label>
      <input id="new-dept">

      <label>氏名</label>
      <input id="new-name">

      <label>メールアドレス</label>
      <input id="new-email" type="email">
    </div>

    <div class="modal-actions">
      <button class="btn secondary" onclick="closeModal('modal-customer')">キャンセル</button>
      <button class="btn" onclick="addCustomer()">追加する</button>
    </div>
  </div>
</div>

<!-- =========================================================
     モーダル：テストメール
========================================================= -->
<div class="modal" id="modal-testmail">
  <div class="modal-box">
    <h2>テストメール送信</h2>
    <p>テストメール送信先を入力してください。</p>
    <input id="test-mail" type="email" value="test@example.com" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:5px;">
    <div class="modal-actions">
      <button class="btn secondary" onclick="closeModal('modal-testmail')">キャンセル</button>
      <button class="btn" onclick="sendTestMail()">送信する</button>
    </div>
  </div>
</div>

<!-- =========================================================
     トースト
========================================================= -->
<div class="toast-area" id="toast-area"></div>

<script>
/* =========================================================
   モックデータ
========================================================= */

let currentSurveyId = 1;
let editingSurvey = false;
let individualInfo = {
  org: '',
  dept: '',
  email: ''
};

let surveys = [
  {
    id:1,
    name:'顧客満足度調査 2026上期',
    description:'顧客満足度を確認するためのアンケートです。',
    status:'published',
    start:'2026-09-10T09:00',
    end:'2026-10-10T18:00',
    created:'2026/09/01 10:00',
    updated:'2026/09/20 15:30',
    answers:3,
    groups:[
      {
        id:'g1',
        name:'サービスについて',
        questions:[
          {
            id:'q1',
            text:'今回のサービスに満足していますか？',
            type:'single',
            required:true,
            choices:[
              {id:'a1',text:'とても満足'},
              {id:'a2',text:'満足'},
              {id:'a3',text:'普通'},
              {id:'a4',text:'不満'}
            ],
            branch:'next'
          },
          {
            id:'q2',
            text:'今後も利用したいと思いますか？',
            type:'single',
            required:true,
            choices:[
              {id:'b1',text:'はい'},
              {id:'b2',text:'いいえ'}
            ],
            branch:'next'
          },
          {
            id:'q3',
            text:'ご意見・ご要望',
            type:'text',
            required:false,
            choices:[],
            branch:'end'
          }
        ]
      }
    ]
  },
  {
    id:2,
    name:'新商品コンセプトアンケート',
    description:'新商品のコンセプトについて確認します。',
    status:'draft',
    start:'',
    end:'',
    created:'2026/09/22 09:12',
    updated:'2026/09/24 18:00',
    answers:0,
    groups:[
      {
        id:'g2',
        name:'基本情報',
        questions:[
          {
            id:'q4',
            text:'',
            type:'text',
            required:true,
            choices:[],
            branch:'next'
          }
        ]
      }
    ]
  },
  {
    id:3,
    name:'社内イベント参加意向調査',
    description:'社内イベントについてのアンケートです。',
    status:'closed',
    start:'2026-07-05T09:00',
    end:'2026-08-01T18:00',
    created:'2026/07/01 09:00',
    updated:'2026/08/05 12:00',
    answers:342,
    groups:[
      {
        id:'g3',
        name:'イベント',
        questions:[
          {
            id:'q5',
            text:'イベントに参加しましたか？',
            type:'single',
            required:true,
            choices:[
              {id:'c1',text:'参加した'},
              {id:'c2',text:'参加していない'}
            ],
            branch:'end'
          }
        ]
      }
    ]
  }
];

let customers = [
  {id:1,org:'株式会社サンプル',dept:'営業部',name:'山田 太郎',email:'yamada@example.com',sent:true,answered:true},
  {id:2,org:'株式会社テスト',dept:'管理部',name:'佐藤 花子',email:'sato@example.com',sent:true,answered:false},
  {id:3,org:'合同会社サンプル',dept:'企画部',name:'鈴木 一郎',email:'suzuki@example.com',sent:true,answered:true},
  {id:4,org:'株式会社ABC',dept:'総務部',name:'田中 次郎',email:'tanaka@example.com',sent:false,answered:false},
  {id:5,org:'株式会社XYZ',dept:'開発部',name:'高橋 美咲',email:'takahashi@example.com',sent:false,answered:false}
];

/* =========================================================
   共通
========================================================= */

function showPage(id) {
  document.querySelectorAll('.page').forEach(function(page) {
    page.classList.remove('active');
  });

  const target = document.getElementById('page-' + id);
  if (target) target.classList.add('active');

  document.querySelectorAll('.main-header nav a').forEach(function(a) {
    a.classList.remove('active');
  });

  if (id === 'list' || id === 'detail' || id === 'editor') {
    document.getElementById('nav-list').classList.add('active');
  }

  if (id === 'customers') {
    document.getElementById('nav-customers').classList.add('active');
  }

  if (id === 'settings') {
    document.getElementById('nav-settings').classList.add('active');
  }

  window.scrollTo(0,0);
}

function showToast(message,type) {
  const area = document.getElementById('toast-area');
  const div = document.createElement('div');
  div.className = 'toast ' + (type === 'success' ? 'success' : '');
  div.innerHTML =
    '<button class="close-toast" onclick="this.parentElement.remove()">×</button>' +
    '<strong>' + (type === 'success' ? '成功' : 'エラー') + '</strong><br>' +
    escapeHtml(message);
  area.appendChild(div);
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#039;');
}

function openModal(id) {
  document.getElementById(id).classList.add('active');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('active');
}

/* =========================================================
   一覧
========================================================= */

function renderSurveyList() {
  const tbody = document.getElementById('survey-list-body');

  tbody.innerHTML = surveys.map(function(s) {
    let statusText = s.status === 'published' ? '公開中' : (s.status === 'draft' ? '下書き' : '終了');
    let badgeClass = s.status === 'published' ? 'open' : (s.status === 'draft' ? 'draft' : 'closed');

    let operations = '';
    operations += '<span class="action-link" onclick="openSurveyDetail(' + s.id + ',\'content\')">内容</span>';
    operations += '<span class="action-link" onclick="openSurveyDetail(' + s.id + ',\'send\')">送信</span>';
    operations += '<span class="action-link" onclick="openSurveyDetail(' + s.id + ',\'status\')">回答状況</span>';
    operations += '<span class="action-link" onclick="openSurveyDetail(' + s.id + ',\'summary\')">集計</span>';
    operations += '<span class="action-link" onclick="openEditor(' + s.id + ')">編集</span>';

    if (s.status === 'draft') {
      operations += '<span class="action-link" onclick="openPublishModal(' + s.id + ')">公開</span>';
      operations += '<span class="action-link" onclick="openDeleteModal(' + s.id + ')">削除</span>';
    }

    if (s.status === 'published') {
      operations += '<span class="action-link" onclick="openEndModal(' + s.id + ')">終了</span>';
    }

    return '<tr>' +
      '<td><strong>' + escapeHtml(s.name) + '</strong></td>' +
      '<td><span class="badge ' + badgeClass + '">' + statusText + '</span></td>' +
      '<td>' + (s.start ? formatDate(s.start) : '未設定') + '</td>' +
      '<td>' + (s.end ? formatDate(s.end) : '未設定') + '</td>' +
      '<td>' + s.created + '</td>' +
      '<td>' + s.updated + '</td>' +
      '<td>' + s.answers + '</td>' +
      '<td>' + operations + '</td>' +
    '</tr>';
  }).join('');
}

function formatDate(value) {
  if (!value) return '';
  return value.replace('T',' ');
}

function openSurveyDetail(id,tab) {
  currentSurveyId = id;
  const survey = surveys.find(function(s){ return s.id === id; });

  if (!survey) {
    showToast('アンケートが見つかりません。');
    return;
  }

  document.querySelector('#page-detail .page-title').textContent = survey.name;

  const badge = document.querySelector('#page-detail .toolbar .badge');
  badge.textContent = survey.status === 'published' ? '公開中' : (survey.status === 'draft' ? '下書き' : '終了');
  badge.className = 'badge ' + (survey.status === 'published' ? 'open' : survey.status === 'draft' ? 'draft' : 'closed');

  renderDetailQuestions();
  renderStatus();

  showPage('detail');
  switchDetailTab(tab || 'content');
}

/* =========================================================
   編集
========================================================= */

function openNewSurvey() {
  editingSurvey = false;
  currentSurveyId = null;

  document.getElementById('editor-title').textContent = 'アンケート作成';
  document.getElementById('survey-name').value = '';
  document.getElementById('survey-description').value = '';
  document.getElementById('survey-start').value = '';
  document.getElementById('survey-end').value = '';
  document.getElementById('numbering-format').value = 'group';

  renderGroups([
    {
      id:'new-g1',
      name:'グループ1',
      questions:[
        {
          id:'new-q1',
          text:'',
          type:'text',
          required:true,
          choices:[],
          branch:'next'
        }
      ]
    }
  ]);

  document.getElementById('editor-message').innerHTML = '';
  showPage('editor');
}

function openEditor(id) {
  if (id !== undefined) currentSurveyId = id;
  editingSurvey = true;

  const survey = surveys.find(function(s){ return s.id === currentSurveyId; });

  if (!survey) {
    openNewSurvey();
    return;
  }

  document.getElementById('editor-title').textContent = 'アンケート編集';
  document.getElementById('survey-name').value = survey.name;
  document.getElementById('survey-description').value = survey.description;
  document.getElementById('survey-start').value = survey.start;
  document.getElementById('survey-end').value = survey.end;
  renderGroups(JSON.parse(JSON.stringify(survey.groups)));
  document.getElementById('editor-message').innerHTML = '';

  showPage('editor');
}

function renderGroups(groups) {
  const container = document.getElementById('groups-container');

  container.innerHTML = groups.map(function(group,gIndex) {
    return `
      <div class="group-card" data-group-id="${escapeHtml(group.id)}">
        <div class="group-header">
          <div>
            <strong>${escapeHtml(group.name)}</strong>
            <span style="color:#888;margin-left:8px;">グループ ${gIndex + 1}</span>
          </div>
          <div class="group-actions">
            <button class="btn small secondary" onclick="renameGroup(this)">名称変更</button>
            <button class="btn small danger" onclick="deleteGroup(this)">削除</button>
          </div>
        </div>

        <div class="questions">
          ${group.questions.map(function(q,qIndex) {
            return renderQuestionHtml(q,gIndex,qIndex);
          }).join('')}
        </div>

        <div style="padding:12px;">
          <button class="btn small secondary" onclick="addQuestion(this)">＋ 質問を追加</button>
        </div>
      </div>
    `;
  }).join('');
}

function renderQuestionHtml(q,gIndex,qIndex) {
  let choicesHtml = '';

  if (q.type === 'single' || q.type === 'multiple') {
    choicesHtml = `
      <div style="margin-top:12px;">
        <strong style="font-size:12px;">選択肢</strong>
        <div class="choices">
          ${(q.choices || []).map(function(c) {
            return `
              <div class="choice-row" style="display:flex;gap:6px;margin-top:6px;">
                <input class="choice-id" style="width:90px;padding:7px;border:1px solid #ccc;border-radius:4px;" value="${escapeHtml(c.id)}">
                <input class="choice-text" style="flex:1;padding:7px;border:1px solid #ccc;border-radius:4px;" value="${escapeHtml(c.text)}">
                <button class="btn small danger" onclick="this.parentElement.remove()">削除</button>
              </div>
            `;
          }).join('')}
        </div>
        <button class="btn small secondary" style="margin-top:7px;" onclick="addChoice(this)">＋ 選択肢</button>
      </div>
    `;
  }

  return `
    <div class="question-card" data-question-id="${escapeHtml(q.id)}">
      <div class="question-header">
        <span class="move-handle" title="移動">☷</span>
        <span class="question-number">Q${gIndex + 1}-${qIndex + 1}</span>
        <strong class="question-preview">${escapeHtml(q.text || '質問文未入力')}</strong>
        <span class="question-type">${typeLabel(q.type)}</span>
        <button class="btn small secondary" onclick="editQuestion(this)">編集</button>
        <button class="btn small danger" onclick="deleteQuestion(this)">削除</button>
      </div>

      <div class="question-body">
        <div class="question-editor">
          <div class="field">
            <label>質問文</label>
            <input class="question-text" value="${escapeHtml(q.text)}">
          </div>

          <div class="three-column" style="margin-top:10px;">
            <div class="field">
              <label>質問形式</label>
              <select class="question-type-select" onchange="changeQuestionType(this)">
                <option value="text" ${q.type==='text'?'selected':''}>テキスト</option>
                <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
                <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
              </select>
            </div>

            <div class="field">
              <label>必須</label>
              <select class="question-required">
                <option value="1" ${q.required?'selected':''}>必須</option>
                <option value="0" ${!q.required?'selected':''}>任意</option>
              </select>
            </div>

            <div class="field">
              <label>分岐先</label>
              <select class="question-branch">
                <option value="next" ${q.branch==='next'?'selected':''}>次の質問</option>
                <option value="question:q2" ${q.branch==='question:q2'?'selected':''}>Q2-1：今後も利用したいと思いますか？（サービスについて）</option>
                <option value="end" ${q.branch==='end'?'selected':''}>終了</option>
              </select>
            </div>
          </div>

          ${choicesHtml}

          <div class="branch">
            分岐設定：単一選択質問の場合のみ利用できます。削除された質問や存在しない質問を指定した場合は公開時にエラーになります。
          </div>
        </div>
      </div>
    </div>
  `;
}

function typeLabel(type) {
  if (type === 'single') return '単一選択';
  if (type === 'multiple') return '複数選択';
  return 'テキスト';
}

function addGroup() {
  const container = document.getElementById('groups-container');
  const count = container.querySelectorAll('.group-card').length + 1;

  const wrapper = document.createElement('div');
  wrapper.innerHTML = `
    <div class="group-card" data-group-id="g-new-${Date.now()}">
      <div class="group-header">
        <div>
          <strong>グループ${count}</strong>
          <span style="color:#888;margin-left:8px;">グループ ${count}</span>
        </div>
        <div class="group-actions">
          <button class="btn small secondary" onclick="renameGroup(this)">名称変更</button>
          <button class="btn small danger" onclick="deleteGroup(this)">削除</button>
        </div>
      </div>
      <div class="questions"></div>
      <div style="padding:12px;">
        <button class="btn small secondary" onclick="addQuestion(this)">＋ 質問を追加</button>
      </div>
    </div>
  `;

  container.appendChild(wrapper.firstElementChild);
  addQuestion(container.lastElementChild.querySelector('.btn.small.secondary'));
  updateQuestionNumbers();
}

function renameGroup(button) {
  const group = button.closest('.group-card');
  const title = group.querySelector('.group-header strong');
  const name = prompt('グループ名を入力してください。',title.textContent);

  if (name === null) return;

  if (!name.trim()) {
    showToast('グループ名を入力してください。');
    return;
  }

  title.textContent = name.trim();
  showToast('グループ名を変更しました。','success');
}

function deleteGroup(button) {
  const group = button.closest('.group-card');
  const questionCount = group.querySelectorAll('.question-card').length;

  if (!confirm('このグループを削除します。質問 ' + questionCount + ' 件も削除されます。よろしいですか？')) return;

  group.remove();
  updateQuestionNumbers();
  showToast('グループを削除しました。','success');
}

function addQuestion(button) {
  const group = button.closest('.group-card');
  const questions = group.querySelector('.questions');

  const div = document.createElement('div');
  div.className = 'question-card';
  div.dataset.questionId = 'q-new-' + Date.now();

  div.innerHTML = renderQuestionHtml({
    id:div.dataset.questionId,
    text:'',
    type:'text',
    required:true,
    choices:[],
    branch:'next'
  },0,questions.children.length);

  questions.appendChild(div);
  updateQuestionNumbers();
}

function deleteQuestion(button) {
  const question = button.closest('.question-card');

  if (!confirm('この質問を削除します。よろしいですか？')) return;

  question.remove();
  updateQuestionNumbers();
  showToast('質問を削除しました。','success');
}

function editQuestion(button) {
  const question = button.closest('.question-card');
  question.querySelector('.question-editor').scrollIntoView({behavior:'smooth',block:'center'});
  question.querySelector('.question-text').focus();
}

function addChoice(button) {
  const choices = button.previousElementSibling;
  const row = document.createElement('div');
  row.className = 'choice-row';
  row.style.cssText = 'display:flex;gap:6px;margin-top:6px;';
  row.innerHTML = `
    <input class="choice-id" style="width:90px;padding:7px;border:1px solid #ccc;border-radius:4px;" placeholder="ID">
    <input class="choice-text" style="flex:1;padding:7px;border:1px solid #ccc;border-radius:4px;" placeholder="選択肢">
    <button class="btn small danger" onclick="this.parentElement.remove()">削除</button>
  `;
  choices.appendChild(row);
}

function changeQuestionType(select) {
  const question = select.closest('.question-card');
  const currentChoices = question.querySelector('.choices');

  if (select.value === 'text') {
    if (currentChoices) {
      currentChoices.parentElement.remove();
    }
  } else {
    if (!currentChoices) {
      const body = question.querySelector('.question-editor');
      const branch = body.querySelector('.branch');

      const wrapper = document.createElement('div');
      wrapper.style.marginTop = '12px';
      wrapper.innerHTML = `
        <strong style="font-size:12px;">選択肢</strong>
        <div class="choices"></div>
        <button class="btn small secondary" style="margin-top:7px;" onclick="addChoice(this)">＋ 選択肢</button>
      `;
      body.insertBefore(wrapper,branch);

      addChoice(wrapper.querySelector('button'));
      addChoice(wrapper.querySelector('button'));
    }
  }
}

function updateQuestionNumbers() {
  document.querySelectorAll('#groups-container .group-card').forEach(function(group,gIndex) {
    group.querySelectorAll('.question-card').forEach(function(q,qIndex) {
      const number = q.querySelector('.question-number');
      if (number) number.textContent = 'Q' + (gIndex + 1) + '-' + (qIndex + 1);

      const input = q.querySelector('.question-text');
      const preview = q.querySelector('.question-preview');

      if (input && preview) {
        preview.textContent = input.value || '質問文未入力';
        input.oninput = function() {
          preview.textContent = input.value || '質問文未入力';
        };
      }
    });
  });
}

function collectEditorData() {
  const groups = [];

  document.querySelectorAll('#groups-container .group-card').forEach(function(group,gIndex) {
    const name = group.querySelector('.group-header strong').textContent;
    const questions = [];

    group.querySelectorAll('.question-card').forEach(function(q) {
      const text = q.querySelector('.question-text')?.value || '';
      const type = q.querySelector('.question-type-select')?.value || 'text';
      const required = q.querySelector('.question-required')?.value === '1';
      const branch = q.querySelector('.question-branch')?.value || 'next';

      const choices = [];
      q.querySelectorAll('.choice-row').forEach(function(row) {
        choices.push({
          id:row.querySelector('.choice-id')?.value || '',
          text:row.querySelector('.choice-text')?.value || ''
        });
      });

      questions.push({
        id:q.dataset.questionId,
        text:text,
        type:type,
        required:required,
        choices:choices,
        branch:branch
      });
    });

    groups.push({
      id:group.dataset.groupId,
      name:name,
      questions:questions
    });
  });

  return {
    name:document.getElementById('survey-name').value.trim(),
    description:document.getElementById('survey-description').value,
    start:document.getElementById('survey-start').value,
    end:document.getElementById('survey-end').value,
    groups:groups
  };
}

function saveDraft() {
  const data = collectEditorData();

  if (!data.name) {
    showToast('アンケート名を入力してください。');
    return;
  }

  if (editingSurvey && currentSurveyId) {
    const survey = surveys.find(function(s){ return s.id === currentSurveyId; });
    Object.assign(survey,data);
    survey.updated = new Date().toLocaleString('ja-JP');
  } else {
    const id = Math.max.apply(null,surveys.map(function(s){return s.id;})) + 1;

    surveys.push({
      id:id,
      name:data.name,
      description:data.description,
      status:'draft',
      start:data.start,
      end:data.end,
      created:new Date().toLocaleString('ja-JP'),
      updated:new Date().toLocaleString('ja-JP'),
      answers:0,
      groups:data.groups
    });

    currentSurveyId = id;
    editingSurvey = true;
  }

  renderSurveyList();

  document.getElementById('editor-message').innerHTML =
    '<div class="notice success">下書きを保存しました。</div>';

  showToast('アンケートの下書きを保存しました。','success');
}

function validateSurvey(survey) {
  const errors = [];

  if (!survey.name) errors.push('アンケート名が入力されていません。');
  if (!survey.start) errors.push('開始日時が設定されていません。');
  if (!survey.end) errors.push('終了日時が設定されていません。');

  if (survey.start && survey.end && survey.start >= survey.end) {
    errors.push('終了日時は開始日時より後に設定してください。');
  }

  if (!survey.groups.length) {
    errors.push('グループが1つ以上必要です。');
  }

  survey.groups.forEach(function(group,gIndex) {
    if (!group.name.trim()) {
      errors.push('グループ' + (gIndex + 1) + 'の名称が空です。');
    }

    group.questions.forEach(function(q,qIndex) {
      if (!q.text.trim()) {
        errors.push('Q' + (gIndex + 1) + '-' + (qIndex + 1) + 'の質問文が入力されていません。');
      }

      if (q.type === 'single' || q.type === 'multiple') {
        if (!q.choices.length) {
          errors.push('Q' + (gIndex + 1) + '-' + (qIndex + 1) + 'に選択肢がありません。');
        }

        const ids = {};
        q.choices.forEach(function(c) {
          if (!c.id.trim()) errors.push('Q' + (gIndex + 1) + '-' + (qIndex + 1) + 'に空の選択肢IDがあります。');
          if (!c.text.trim()) errors.push('Q' + (gIndex + 1) + '-' + (qIndex + 1) + 'に空の選択肢があります。');
          if (ids[c.id]) errors.push('Q' + (gIndex + 1) + '-' + (qIndex + 1) + 'で選択肢IDが重複しています。');
          ids[c.id] = true;
        });
      }

      if (q.branch && q.branch.indexOf('question:') === 0 && q.type !== 'single') {
        errors.push('Q' + (gIndex + 1) + '-' + (qIndex + 1) + 'の分岐は単一選択質問でのみ使用できます。');
      }
    });
  });

  return errors;
}

function publishFromEditor() {
  const data = collectEditorData();

  const survey = {
    id:currentSurveyId || 0,
    name:data.name,
    description:data.description,
    status:'draft',
    start:data.start,
    end:data.end,
    groups:data.groups
  };

  const errors = validateSurvey(survey);

  if (errors.length) {
    document.getElementById('editor-message').innerHTML =
      '<div class="notice error"><strong>公開できません。</strong><ul>' +
      errors.map(function(e){return '<li>'+escapeHtml(e)+'</li>';}).join('') +
      '</ul></div>';

    showToast('入力内容を確認してください。');
    return;
  }

  if (editingSurvey && currentSurveyId) {
    Object.assign(surveys.find(function(s){return s.id===currentSurveyId;}),data);
  } else {
    const id = Math.max.apply(null,surveys.map(function(s){return s.id;})) + 1;
    currentSurveyId = id;

    surveys.push({
      id:id,
      name:data.name,
      description:data.description,
      status:'published',
      start:data.start,
      end:data.end,
      created:new Date().toLocaleString('ja-JP'),
      updated:new Date().toLocaleString('ja-JP'),
      answers:0,
      groups:data.groups
    });

    renderSurveyList();
    showPage('list');
    showToast('アンケートを公開しました。','success');
    return;
  }

  const target = surveys.find(function(s){return s.id===currentSurveyId;});
  target.status = 'published';
  target.updated = new Date().toLocaleString('ja-JP');

  renderSurveyList();
  showPage('list');
  showToast('アンケートを公開しました。','success');
}

/* =========================================================
   公開・終了・削除
========================================================= */

function openPublishModal(id) {
  currentSurveyId = id;
  openModal('modal-publish');
}

function publishSurvey() {
  const survey = surveys.find(function(s){return s.id===currentSurveyId;});

  if (!survey) return;

  const errors = validateSurvey(survey);

  if (errors.length) {
    closeModal('modal-publish');
    showToast('公開できません：' + errors[0]);
    return;
  }

  survey.status = 'published';
  survey.updated = new Date().toLocaleString('ja-JP');

  closeModal('modal-publish');
  renderSurveyList();
  showToast('アンケートを公開しました。','success');
}

function openEndModal(id) {
  currentSurveyId = id;
  openModal('modal-close');
}

function endSurvey() {
  const survey = surveys.find(function(s){return s.id===currentSurveyId;});

  if (!survey) return;

  survey.status = 'closed';
  survey.updated = new Date().toLocaleString('ja-JP');

  closeModal('modal-close');
  renderSurveyList();
  showPage('list');
  showToast('アンケートを終了しました。','success');
}

function openDeleteModal(id) {
  currentSurveyId = id;
  openModal('modal-delete');
}

function deleteSurvey() {
  surveys = surveys.filter(function(s){return s.id !== currentSurveyId;});

  closeModal('modal-delete');
  renderSurveyList();
  showToast('アンケートを削除しました。','success');
}

/* =========================================================
   詳細
========================================================= */

function switchDetailTab(tab) {
  document.querySelectorAll('#detail-tabs .tab').forEach(function(t) {
    t.classList.toggle('active',t.dataset.tab === tab);
  });

  document.querySelectorAll('#page-detail .tab-content').forEach(function(c) {
    c.style.display = 'none';
  });

  const target = document.getElementById('tab-' + tab);
  if (target) target.style.display = 'block';

  if (tab === 'status') renderStatus();
}

function renderDetailQuestions() {
  const survey = surveys.find(function(s){return s.id===currentSurveyId;});
  if (!survey) return;

  const area = document.getElementById('detail-question-list');

  let html = '';

  survey.groups.forEach(function(group,gIndex) {
    html += '<div class="group-card">';
    html += '<div class="group-header"><strong>' + escapeHtml(group.name) + '</strong></div>';

    group.questions.forEach(function(q,qIndex) {
      html += '<div class="question-card">';
      html += '<div class="question-header">';
      html += '<span class="question-number">Q' + (gIndex+1) + '-' + (qIndex+1) + '</span>';
      html += '<strong>' + escapeHtml(q.text || '質問文未入力') + '</strong>';
      html += '<span class="question-type">' + typeLabel(q.type) + '</span>';
      html += '</div>';
      html += '<div class="question-body">';

      if (q.choices.length) {
        q.choices.forEach(function(c) {
          html += '<div class="choice">・' + escapeHtml(c.text) + '</div>';
        });
      }

      html += '</div></div>';
    });

    html += '</div>';
  });

  area.innerHTML = html;
}

function renderStatus() {
  const tbody = document.getElementById('status-body');

  tbody.innerHTML = customers.map(function(c) {
    const individual = c.id === 5;

    return '<tr>' +
      '<td>' + (individual ? '<span class="badge pending">個別回答</span>' : '通常回答者') + '</td>' +
      '<td>' + escapeHtml(c.org) + '</td>' +
      '<td>' + escapeHtml(c.dept) + '</td>' +
      '<td>' + escapeHtml(c.name) + '</td>' +
      '<td>' + escapeHtml(c.email) + '</td>' +
      '<td>' +
        (c.answered ? '<span class="badge done">回答済み</span>' :
          c.sent ? '<span class="badge sent">未回答</span>' :
          '<span class="badge draft">未送信</span>') +
      '</td>' +
      '<td>' + (c.sent ? '2026/09/20 10:30' : '—') + '</td>' +
      '<td>' + (c.answered ? '2026/09/23 15:40' : '—') + '</td>' +
    '</tr>';
  }).join('');
}

function showResultDetail(id) {
  showToast('回答ID ' + id + ' の回答内容を表示しました。','success');
}

/* =========================================================
   送信
========================================================= */

function openSendModal() {
  openModal('modal-send');
}

function sendSurvey() {
  const targets = document.querySelectorAll('.send-target:checked');

  if (!targets.length) {
    showToast('送信対象者を選択してください。');
    return;
  }

  closeModal('modal-send');

  showToast('送信処理中…');

  setTimeout(function() {
    targets.forEach(function(box) {
      const customer = customers.find(function(c){return c.id === Number(box.value);});
      if (customer) customer.sent = true;
    });

    renderStatus();
    showToast(targets.length + '名への回答依頼を送信しました。','success');
  },700);
}

function issueIndividualUrl() {
  openModal('modal-individual-url');
}

function copyIndividualUrl() {
  const input = document.getElementById('individual-url');

  if (navigator.clipboard) {
    navigator.clipboard.writeText(input.value).then(function() {
      showToast('個別回答URLをコピーしました。','success');
    });
  } else {
    input.select();
    document.execCommand('copy');
    showToast('個別回答URLをコピーしました。','success');
  }
}

function openIndividualAnswer() {
  closeModal('modal-individual-url');
  showPage('individual-info');
}

function confirmIndividualInfo() {
  const org = document.getElementById('individual-org').value.trim();
  const dept = document.getElementById('individual-dept').value.trim();
  const email = document.getElementById('individual-email').value.trim();

  if (!org || !dept || !email) {
    showToast('組織名・部署名・メールアドレスをすべて入力してください。');
    return;
  }

  individualInfo = {
    org:org,
    dept:dept,
    email:email
  };

  document.getElementById('confirm-org').textContent = org;
  document.getElementById('confirm-dept').textContent = dept;
  document.getElementById('confirm-email').textContent = email;

  showPage('individual-confirm');
}

function startIndividualAnswer() {
  showPage('answer');
}

function submitAnswer() {
  const selected = document.querySelector('input[name="q1"]:checked');
  const selected2 = document.querySelector('input[name="q2"]:checked');

  if (!selected || !selected2) {
    showToast('必須項目に回答してください。');
    return;
  }

  showToast('回答を送信しています…');

  setTimeout(function() {
    showPage('answer-complete');
    showToast('回答を送信しました。','success');
  },700);
}

/* =========================================================
   顧客
========================================================= */

function renderCustomers() {
  const tbody = document.getElementById('customer-body');

  tbody.innerHTML = customers.map(function(c) {
    return '<tr>' +
      '<td><input type="checkbox" class="customer-check" value="' + c.id + '"></td>' +
      '<td>' + escapeHtml(c.org) + '</td>' +
      '<td>' + escapeHtml(c.dept) + '</td>' +
      '<td>' + escapeHtml(c.name) + '</td>' +
      '<td>' + escapeHtml(c.email) + '</td>' +
      '<td>' +
        (c.sent ?
          '<span class="badge sent">送信済み</span>' :
          '<button class="btn small" onclick="sendCustomer(' + c.id + ')">回答依頼</button>') +
      '</td>' +
      '<td>' +
        '<span class="action-link" onclick="editCustomer(' + c.id + ')">編集</span>' +
        '<span class="action-link" onclick="removeCustomer(' + c.id + ')">削除</span>' +
      '</td>' +
    '</tr>';
  }).join('');
}

function filterCustomers() {
  const keyword = document.getElementById('customer-search').value.toLowerCase().trim();

  document.querySelectorAll('#customer-body tr').forEach(function(row) {
    row.style.display = row.textContent.toLowerCase().indexOf(keyword) >= 0 ? '' : 'none';
  });
}

function toggleAllCustomers(source) {
  document.querySelectorAll('.customer-check').forEach(function(box) {
    box.checked = source.checked;
  });
}

function openCustomerModal() {
  document.getElementById('new-org').value = '';
  document.getElementById('new-dept').value = '';
  document.getElementById('new-name').value = '';
  document.getElementById('new-email').value = '';
  openModal('modal-customer');
}

function addCustomer() {
  const org = document.getElementById('new-org').value.trim();
  const dept = document.getElementById('new-dept').value.trim();
  const name = document.getElementById('new-name').value.trim();
  const email = document.getElementById('new-email').value.trim();

  if (!org || !dept || !name || !email) {
    showToast('すべての項目を入力してください。');
    return;
  }

  customers.push({
    id:Date.now(),
    org:org,
    dept:dept,
    name:name,
    email:email,
    sent:false,
    answered:false
  });

  closeModal('modal-customer');
  renderCustomers();
  showToast('顧客を追加しました。','success');
}

function editCustomer(id) {
  const c = customers.find(function(x){return x.id===id;});
  if (!c) return;

  const org = prompt('組織名',c.org);
  if (org === null) return;

  const dept = prompt('部署名',c.dept);
  if (dept === null) return;

  const name = prompt('氏名',c.name);
  if (name === null) return;

  c.org = org;
  c.dept = dept;
  c.name = name;

  renderCustomers();
  showToast('顧客情報を更新しました。','success');
}

function removeCustomer(id) {
  if (!confirm('この顧客を削除します。よろしいですか？')) return;

  customers = customers.filter(function(c){return c.id !== id;});
  renderCustomers();
  showToast('顧客を削除しました。','success');
}

function sendCustomer(id) {
  const c = customers.find(function(x){return x.id===id;});
  if (!c) return;

  if (c.sent) {
    showToast('この回答者にはすでに送信済みです。二重送信を防止しました。');
    return;
  }

  c.sent = true;
  renderCustomers();
  showToast(c.name + 'さんへ回答依頼を送信しました。','success');
}

/* =========================================================
   設定
========================================================= */

function switchSettingTab(tab) {
  document.querySelectorAll('[data-setting-tab]').forEach(function(t) {
    t.classList.toggle('active',t.dataset.settingTab === tab);
  });

  document.getElementById('setting-smtp').style.display = tab === 'smtp' ? 'block' : 'none';
  document.getElementById('setting-kintone').style.display = tab === 'kintone' ? 'block' : 'none';
  document.getElementById('setting-mapping').style.display = tab === 'mapping' ? 'block' : 'none';
}

function saveSettings(message) {
  showToast(message,'success');
}

function testSmtpConnection() {
  showToast('SMTP接続を確認しています…');

  setTimeout(function() {
    showToast('SMTPサーバーへの接続に成功しました。','success');
  },700);
}

function openTestMailModal() {
  openModal('modal-testmail');
}

function sendTestMail() {
  const mail = document.getElementById('test-mail').value.trim();

  if (!mail) {
    showToast('テストメール送信先を入力してください。');
    return;
  }

  closeModal('modal-testmail');
  showToast('テストメールを送信しています…');

  setTimeout(function() {
    showToast(mail + ' へテストメールを送信しました。','success');
  },700);
}

function testKintone() {
  showToast('kintoneへの接続を確認しています…');

  setTimeout(function() {
    showToast('kintoneへの接続に成功しました。','success');
  },700);
}

function fetchKintoneFields() {
  showToast('kintoneのフィールド情報を取得しています…');

  setTimeout(function() {
    showToast('kintoneから12項目を取得しました。','success');
  },800);
}

function syncKintone() {
  showToast('kintoneへ同期しています…');

  setTimeout(function() {
    showToast('kintoneへの同期が完了しました。128件を処理しました。','success');
  },900);
}

/* =========================================================
   初期化
========================================================= */

document.addEventListener('DOMContentLoaded',function() {
  renderSurveyList();
  renderCustomers();
  updateQuestionNumbers();
});
</script>

</body>
</html>
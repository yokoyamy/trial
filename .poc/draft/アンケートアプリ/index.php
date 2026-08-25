<?php
/*
 * アンケート管理システム - インタラクティブモック
 * Apache 2.4 / PHP 8.5
 * 1ファイル構成: index.php
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム - Mock</title>
<style>
:root{
  --primary:#2563eb;
  --primary-dark:#1d4ed8;
  --success:#16a34a;
  --warning:#d97706;
  --danger:#dc2626;
  --gray-50:#f8fafc;
  --gray-100:#f1f5f9;
  --gray-200:#e2e8f0;
  --gray-300:#cbd5e1;
  --gray-500:#64748b;
  --gray-600:#475569;
  --gray-700:#334155;
  --gray-900:#0f172a;
  --white:#fff;
  --radius:10px;
  --shadow:0 2px 8px rgba(15,23,42,.08);
}

*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:var(--gray-900);background:var(--gray-50)}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
a{text-decoration:none;color:inherit}

.admin-header{
  height:64px;background:#0f172a;color:#fff;
  display:flex;align-items:center;padding:0 24px;
  position:sticky;top:0;z-index:50;
}
.brand{font-size:18px;font-weight:700;margin-right:35px}
.nav{display:flex;gap:4px;flex:1}
.nav button{
  background:transparent;border:0;color:#cbd5e1;
  padding:10px 14px;border-radius:7px;
}
.nav button:hover,.nav button.active{background:#1e293b;color:#fff}
.logout{background:transparent;color:#cbd5e1;border:1px solid #475569;padding:8px 13px;border-radius:7px}

main{max-width:1440px;margin:auto;padding:24px}
.page{display:none}
.page.active{display:block}

.page-title{
  display:flex;align-items:center;justify-content:space-between;
  gap:16px;margin-bottom:20px;
}
.page-title h1{font-size:25px;margin:0}
.page-title p{margin:5px 0 0;color:var(--gray-500);font-size:13px}

.btn{
  border:1px solid var(--gray-300);background:#fff;
  color:var(--gray-700);padding:9px 15px;border-radius:7px;
  transition:.15s;
}
.btn:hover{background:var(--gray-100)}
.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-dark)}
.btn-success{background:var(--success);border-color:var(--success);color:#fff}
.btn-danger{background:var(--danger);border-color:var(--danger);color:#fff}
.btn-warning{background:#f59e0b;border-color:#f59e0b;color:#fff}
.btn-sm{font-size:12px;padding:6px 9px}
.btn:disabled{opacity:.5;cursor:not-allowed}

.card{
  background:#fff;border:1px solid var(--gray-200);
  border-radius:var(--radius);box-shadow:var(--shadow);
}
.card-header{
  padding:16px 18px;border-bottom:1px solid var(--gray-200);
  display:flex;justify-content:space-between;align-items:center;
}
.card-body{padding:18px}

.toolbar{
  display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;
}
.search{display:flex;gap:6px;min-width:280px}
.search input{flex:1}
input,select,textarea{
  width:100%;border:1px solid var(--gray-300);border-radius:7px;
  padding:9px 11px;background:#fff;color:var(--gray-900);
}
textarea{resize:vertical;min-height:100px}
label{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
.form-group{margin-bottom:16px}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.radio-row{display:flex;gap:18px;align-items:center}
.radio-row label{font-weight:400;margin:0;display:flex;gap:6px;align-items:center}
.radio-row input{width:auto}

.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:1050px}
th,td{padding:12px 10px;border-bottom:1px solid var(--gray-200);text-align:left;font-size:13px;vertical-align:middle}
th{background:var(--gray-50);font-weight:700;white-space:nowrap}
tr:hover td{background:#fafcff}
.actions{display:flex;gap:5px;flex-wrap:wrap}

.badge{
  display:inline-flex;align-items:center;border-radius:999px;
  padding:4px 9px;font-size:11px;font-weight:700;white-space:nowrap;
}
.badge-draft{background:#e2e8f0;color:#475569}
.badge-public{background:#dcfce7;color:#166534}
.badge-stop{background:#fef3c7;color:#92400e}
.badge-end{background:#fee2e2;color:#991b1b}
.badge-info{background:#dbeafe;color:#1d4ed8}

.notice{
  padding:12px 14px;border-radius:8px;margin-bottom:16px;
  font-size:13px;border:1px solid;
}
.notice-success{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.notice-error{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.notice-info{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.notice-warning{background:#fffbeb;color:#92400e;border-color:#fde68a}

.edit-actions{
  background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius);
  padding:15px 18px;margin-bottom:18px;display:flex;
  align-items:center;justify-content:space-between;gap:15px;
  position:sticky;top:74px;z-index:20;box-shadow:var(--shadow);
}
.edit-actions-left,.edit-actions-right{display:flex;align-items:center;gap:8px}
.status-control{display:flex;align-items:center;gap:8px}
.status-control label{margin:0;white-space:nowrap}
.status-control select{width:150px}

.section{margin-bottom:20px}
.section-title{font-size:18px;margin:0 0 14px}
.group{
  background:#fff;border:1px solid var(--gray-200);
  border-radius:var(--radius);margin-bottom:16px;
}
.group-header{
  padding:13px 15px;background:#f8fafc;border-bottom:1px solid var(--gray-200);
  display:flex;gap:10px;align-items:center;
}
.drag-handle{cursor:grab;color:var(--gray-500);font-size:18px}
.group-title-input{flex:1}
.question{
  margin:12px 15px;padding:14px;border:1px solid var(--gray-200);
  border-radius:8px;background:#fff;
}
.question.dragging,.group.dragging{opacity:.45}
.question-head{
  display:flex;gap:8px;align-items:center;margin-bottom:10px;
}
.q-number{font-weight:700;color:var(--primary);min-width:55px}
.question-controls{margin-left:auto;display:flex;gap:5px}
.question-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px}
.choice-row{display:flex;gap:6px;margin-top:7px}
.choice-row input{flex:1}
.group-footer{padding:0 15px 15px}
.add-question{width:100%;border:1px dashed var(--gray-300);background:#fff;padding:9px;color:var(--primary);border-radius:7px}
.add-group{
  width:100%;padding:13px;border:1px dashed var(--gray-300);
  background:#fff;color:var(--primary);border-radius:8px;
}

.preview-shell{max-width:820px;margin:auto}
.preview-switch{display:flex;justify-content:center;gap:6px;margin-bottom:16px}
.preview-device{transition:.2s}
.preview-device.mobile{max-width:390px;margin:auto;border:8px solid #111;border-radius:28px;overflow:hidden}
.preview-device.mobile .preview-content{padding:18px}
.preview-content{background:#fff;padding:28px}

.answer-question{
  margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid var(--gray-200);
}
.answer-choice{display:block;padding:10px;border:1px solid var(--gray-200);border-radius:7px;margin:7px 0}
.answer-choice input{width:auto;margin-right:8px}
.required{color:var(--danger);font-size:11px;margin-left:5px}

.stepper{display:flex;gap:0;margin-bottom:20px}
.step{
  flex:1;text-align:center;padding:9px;background:#e2e8f0;
  color:#64748b;font-size:12px;
}
.step:first-child{border-radius:7px 0 0 7px}
.step:last-child{border-radius:0 7px 7px 0}
.step.active{background:var(--primary);color:#fff}

.summary-grid{
  display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px;
}
.summary-card{background:#fff;border:1px solid var(--gray-200);border-radius:10px;padding:16px}
.summary-card .label{font-size:12px;color:var(--gray-500)}
.summary-card .value{font-size:25px;font-weight:700;margin-top:5px}

.bar{height:10px;background:#e2e8f0;border-radius:99px;overflow:hidden}
.bar span{display:block;height:100%;background:var(--primary)}

.tabbar{display:flex;gap:2px;border-bottom:1px solid var(--gray-200);margin-bottom:16px}
.tab{
  border:0;background:transparent;padding:11px 17px;
  color:var(--gray-500);border-bottom:2px solid transparent;
}
.tab.active{color:var(--primary);border-bottom-color:var(--primary);font-weight:700}

.mail-layout{display:grid;grid-template-columns:1.2fr .8fr;gap:18px}
.customer-row{display:grid;grid-template-columns:30px 1.2fr 1fr 1.2fr .8fr .8fr;gap:8px;align-items:center;padding:10px;border-bottom:1px solid var(--gray-200);font-size:12px}
.customer-row.header{background:var(--gray-50);font-weight:700}
.customer-list{border:1px solid var(--gray-200);border-radius:8px;overflow:hidden}

.mapping-grid{display:grid;grid-template-columns:180px 1fr;gap:10px;align-items:center}
.mapping-grid label{margin:0}
.address-checks{display:flex;flex-wrap:wrap;gap:12px}
.address-checks label{font-weight:400;margin:0;display:flex;align-items:center;gap:5px}
.address-checks input{width:auto}

.status-box{padding:12px;border-radius:8px;margin-top:10px}
.status-box.success{background:#f0fdf4;color:#166534}
.status-box.error{background:#fef2f2;color:#991b1b}
.status-box.neutral{background:#f8fafc;color:#64748b}

.modal-backdrop{
  display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);
  z-index:100;align-items:center;justify-content:center;padding:20px;
}
.modal-backdrop.show{display:flex}
.modal{
  width:min(520px,100%);background:#fff;border-radius:12px;
  box-shadow:0 20px 50px rgba(0,0,0,.25);
}
.modal-header{padding:17px 20px;border-bottom:1px solid var(--gray-200);font-weight:700}
.modal-body{padding:20px}
.modal-footer{padding:14px 20px;border-top:1px solid var(--gray-200);display:flex;justify-content:flex-end;gap:8px}

.toast{
  position:fixed;right:20px;bottom:20px;z-index:200;
  background:#0f172a;color:#fff;padding:12px 16px;border-radius:8px;
  box-shadow:var(--shadow);display:none;max-width:360px;font-size:13px;
}
.toast.show{display:block}

.chart{
  display:flex;align-items:flex-end;gap:18px;height:220px;
  padding:15px;border-left:1px solid #ddd;border-bottom:1px solid #ddd;
}
.chart-col{flex:1;text-align:center;height:100%;display:flex;flex-direction:column;justify-content:flex-end}
.chart-bar{background:var(--primary);border-radius:5px 5px 0 0;min-height:3px}
.chart-label{font-size:11px;margin-top:6px;color:var(--gray-600)}

.muted{color:var(--gray-500)}
.small{font-size:12px}
.center{text-align:center}
.right{text-align:right}

@media(max-width:900px){
  .nav button{padding:8px}
  .summary-grid{grid-template-columns:repeat(2,1fr)}
  .mail-layout{grid-template-columns:1fr}
  .form-grid{grid-template-columns:1fr}
  .question-grid{grid-template-columns:1fr}
}
@media(max-width:600px){
  .admin-header{padding:0 12px;height:auto;min-height:58px;flex-wrap:wrap}
  .brand{width:100%;padding-top:10px;margin:0}
  .nav{overflow:auto;order:3;width:100%}
  .logout{margin-left:auto}
  main{padding:12px}
  .page-title{align-items:flex-start;flex-direction:column}
  .edit-actions{top:0;flex-wrap:wrap}
  .summary-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<header class="admin-header" id="adminHeader">
  <div class="brand">アンケート管理システム</div>
  <nav class="nav">
    <button onclick="showPage('list')" data-nav="list">アンケート一覧</button>
    <button onclick="showPage('kintone')" data-nav="kintone">kintone連携設定</button>
    <button onclick="showPage('smtp')" data-nav="smtp">メールサーバ設定</button>
  </nav>
  <button class="logout" onclick="toast('モックのためログアウト処理はありません')">ログアウト</button>
</header>

<main>

<!-- =========================================================
     一覧
========================================================= -->
<section id="page-list" class="page active">
  <div class="page-title">
    <div>
      <h1>アンケート一覧</h1>
      <p>全アンケートの管理起点です。集計・送信は対象アンケートを引き継いで開きます。</p>
    </div>
    <button class="btn btn-primary" onclick="newSurvey()">＋ 新規アンケート作成</button>
  </div>

  <div class="card">
    <div class="card-body">
      <div class="toolbar">
        <div class="search">
          <input id="searchInput" placeholder="タイトルで検索（Enterでも検索）">
          <button class="btn" onclick="renderSurveyList()">検索</button>
        </div>
        <select id="statusFilter" style="width:150px" onchange="renderSurveyList()">
          <option value="all">すべて</option>
          <option value="公開中">公開中</option>
          <option value="下書き">下書き</option>
          <option value="停止">停止</option>
          <option value="終了">終了</option>
        </select>
        <select id="sortSelect" style="width:200px" onchange="renderSurveyList()">
          <option value="updatedDesc">更新日：新しい順</option>
          <option value="updatedAsc">更新日：古い順</option>
          <option value="answersDesc">回答数：多い順</option>
          <option value="answersAsc">回答数：少ない順</option>
          <option value="startDesc">開始日：新しい順</option>
          <option value="startAsc">開始日：古い順</option>
        </select>
      </div>

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
          <tbody id="surveyTable"></tbody>
        </table>
      </div>
    </div>
  </div>
</section>


<!-- =========================================================
     作成・編集
========================================================= -->
<section id="page-editor" class="page">
  <div class="page-title">
    <div>
      <h1 id="editorTitle">アンケート作成・編集</h1>
      <p>保存操作と状態変更操作は独立しています。</p>
    </div>
  </div>

  <div class="edit-actions">
    <div class="edit-actions-left">
      <button class="btn" onclick="cancelEditor()">キャンセル</button>
      <button class="btn btn-primary" onclick="saveSurvey()">保存して一覧へ</button>
    </div>
    <div class="status-control">
      <label for="editorStatus">状態：</label>
      <select id="editorStatus" onchange="requestStatusChange(this.value)"></select>
    </div>
  </div>

  <div class="card section">
    <div class="card-header"><strong>基本情報</strong></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1">
          <label>アンケートタイトル</label>
          <input id="surveyTitle">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>アンケート説明</label>
          <textarea id="surveyDescription"></textarea>
        </div>
        <div class="form-group">
          <label>開始日時</label>
          <input type="datetime-local" id="surveyStart">
        </div>
        <div class="form-group">
          <label>終了日時</label>
          <input type="datetime-local" id="surveyEnd">
        </div>
      </div>

      <div class="form-group">
        <label>質問番号の採番方式</label>
        <div class="radio-row">
          <label><input type="radio" name="numbering" value="global" onchange="renumber()"> アンケート全体で通番</label>
          <label><input type="radio" name="numbering" value="group" onchange="renumber()"> グループ毎に採番</label>
        </div>
      </div>
    </div>
  </div>

  <div class="section">
    <h2 class="section-title">グループ・質問</h2>
    <div id="groups"></div>
    <button class="add-group" onclick="addGroup()">＋ グループを追加</button>
  </div>
</section>


<!-- =========================================================
     プレビュー
========================================================= -->
<section id="page-preview" class="page">
  <div class="page-title">
    <div>
      <h1>アンケートプレビュー</h1>
      <p>実際のメール送信・回答保存は行いません。</p>
    </div>
    <button class="btn" onclick="backFromPreview()">編集画面へ戻る</button>
  </div>

  <div class="preview-switch">
    <button class="btn" onclick="setPreviewDevice('pc')">PC表示</button>
    <button class="btn" onclick="setPreviewDevice('mobile')">スマートフォン表示</button>
  </div>

  <div id="previewDevice" class="preview-device">
    <div class="preview-content" id="previewContent"></div>
  </div>
</section>


<!-- =========================================================
     顧客選択・メール送信
========================================================= -->
<section id="page-mail" class="page">
  <div class="page-title">
    <div>
      <h1>顧客選択・メール送信</h1>
      <p>対象アンケートを固定した状態で送信業務を行います。</p>
    </div>
    <button class="btn" onclick="showPage('list')">一覧へ戻る</button>
  </div>

  <div class="notice notice-info">
    <strong>送信対象アンケート：</strong>
    <span id="mailSurveyTitle"></span>
  </div>

  <div class="tabbar">
    <button class="tab active" id="mailTabCustomers" onclick="switchMailTab('customers')">顧客選択・送信</button>
    <button class="tab" id="mailTabHistory" onclick="switchMailTab('history')">送信履歴</button>
  </div>

  <div id="mailCustomers">
    <div id="sendResult"></div>

    <div class="mail-layout">
      <div class="card">
        <div class="card-header">
          <strong>送信対象顧客</strong>
          <div style="display:flex;gap:6px">
            <input id="customerSearch" placeholder="顧客名・組織名・メール" oninput="renderCustomers()" style="width:220px">
            <select id="customerStatusFilter" onchange="renderCustomers()" style="width:130px">
              <option value="all">すべて</option>
              <option value="未送信">未送信</option>
              <option value="送信済み / 未回答">送信済み / 未回答</option>
              <option value="回答済み">回答済み</option>
            </select>
          </div>
        </div>
        <div class="card-body" style="padding:0">
          <div class="customer-list" style="border:0;border-radius:0">
            <div class="customer-row header">
              <span><input type="checkbox" id="selectAllCustomers" onchange="toggleAllCustomers(this.checked)"></span>
              <span>組織名 / 氏名</span>
              <span>メール</span>
              <span>電話 / 住所</span>
              <span>回答状況</span>
              <span>kintone</span>
            </div>
            <div id="customerRows"></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><strong>メール内容</strong></div>
        <div class="card-body">
          <div class="form-group">
            <label>メール件名</label>
            <input id="mailSubject" value="{アンケート名} ご回答のお願い">
          </div>
          <div class="form-group">
            <label>メール本文</label>
            <textarea id="mailBody" style="min-height:220px">{顧客名} 様

いつもお世話になっております。

下記アンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力のほど、よろしくお願いいたします。</textarea>
          </div>

          <div class="notice notice-info small">
            利用可能な変数：
            <code>{顧客名}</code>
            <code>{アンケートURL}</code>
          </div>

          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn" onclick="previewSelectedMail()">送信文を確認</button>
            <button class="btn btn-warning" onclick="sendMail('remind')">リマインド</button>
            <button class="btn btn-primary" onclick="sendMail('normal')">一括送信</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="mailHistory" style="display:none">
    <div class="card">
      <div class="card-header">
        <strong>送信履歴</strong>
        <span class="small muted">この画面内で確認できます。専用画面へ遷移しません。</span>
      </div>
      <div class="card-body">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>送信日時</th>
                <th>種別</th>
                <th>件数</th>
                <th>件名</th>
                <th>実行者</th>
                <th>対象顧客</th>
                <th>内容</th>
              </tr>
            </thead>
            <tbody id="historyTable"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</section>


<!-- =========================================================
     集計
========================================================= -->
<section id="page-analysis" class="page">
  <div class="page-title">
    <div>
      <h1>回答集計・分析</h1>
      <p>対象アンケートは一覧画面から引き継がれ、画面内では変更できません。</p>
    </div>
    <button class="btn" onclick="showPage('list')">一覧へ戻る</button>
  </div>

  <div class="notice notice-info">
    <strong>集計対象アンケート：</strong>
    <span id="analysisSurveyTitle"></span>
  </div>

  <div class="summary-grid">
    <div class="summary-card"><div class="label">送信対象者数</div><div class="value" id="sumTarget">0</div></div>
    <div class="summary-card"><div class="label">回答数</div><div class="value" id="sumAnswers">0</div></div>
    <div class="summary-card"><div class="label">未登録回答</div><div class="value" id="sumUnregistered">0</div></div>
    <div class="summary-card"><div class="label">未回答数</div><div class="value" id="sumUnanswered">0</div></div>
    <div class="summary-card"><div class="label">回答率</div><div class="value" id="sumRate">0%</div></div>
  </div>

  <div class="card section">
    <div class="card-header">
      <strong>出力</strong>
      <div style="display:flex;gap:7px">
        <button class="btn" onclick="exportCsv()">CSVダウンロード</button>
        <button class="btn" onclick="exportPdf()">PDF出力</button>
      </div>
    </div>
  </div>

  <div class="card section">
    <div class="card-header"><strong>設問フィルター</strong></div>
    <div class="card-body">
      <div style="display:flex;gap:8px;margin-bottom:12px">
        <button class="btn btn-sm" onclick="selectAllQuestions(true)">すべて選択</button>
        <button class="btn btn-sm" onclick="selectAllQuestions(false)">すべて解除</button>
      </div>
      <div id="questionFilters"></div>
    </div>
  </div>

  <div class="card section">
    <div class="card-header"><strong>設問別集計</strong></div>
    <div class="card-body" id="analysisQuestions"></div>
  </div>

  <div class="card">
    <div class="card-header"><strong>個別回答</strong></div>
    <div class="card-body" id="individualAnswers"></div>
  </div>
</section>


<!-- =========================================================
     kintone
========================================================= -->
<section id="page-kintone" class="page">
  <div class="page-title">
    <div>
      <h1>kintone連携設定</h1>
      <p>接続テスト・項目取得・顧客同期はそれぞれ独立した操作です。</p>
    </div>
  </div>

  <div class="card section">
    <div class="card-header"><strong>接続設定</strong></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>サブドメイン</label>
          <input id="kinDomain" placeholder="example.cybozu.com">
        </div>
        <div class="form-group">
          <label>顧客管理アプリID</label>
          <input id="kinAppId" placeholder="123">
        </div>
        <div class="form-group">
          <label>ログイン名</label>
          <input id="kinLogin">
        </div>
        <div class="form-group">
          <label>パスワード</label>
          <input type="password" id="kinPassword">
        </div>
      </div>

      <div class="form-group">
        <label>SSL検証設定</label>
        <select id="kinSsl" style="max-width:250px">
          <option>検証する</option>
          <option>検証しない</option>
        </select>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary" onclick="testKintone()">接続テスト</button>
        <button class="btn" onclick="saveKintone()">設定を保存</button>
        <button class="btn" onclick="getKintoneFields()">項目一覧を再取得</button>
        <button class="btn btn-success" onclick="syncKintone()">顧客情報を同期</button>
      </div>

      <div id="kinStatus" class="status-box neutral">未設定</div>
    </div>
  </div>

  <div class="card section">
    <div class="card-header"><strong>項目一覧</strong></div>
    <div class="card-body">
      <div id="kinFields" class="muted">「項目一覧を再取得」を実行してください。</div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><strong>フィールドマッピング</strong></div>
    <div class="card-body">
      <div class="mapping-grid">
        <label>組織名</label><select><option>組織名</option><option>会社名</option><option>未設定</option></select>
        <label>氏名</label><select><option>氏名</option><option>名前</option><option>未設定</option></select>
        <label>メールアドレス</label><select><option>メールアドレス</option><option>Email</option><option>未設定</option></select>
        <label>部署名</label><select><option>部署名</option><option>部署</option><option>未設定</option></select>
        <label>電話番号</label><select><option>電話番号</option><option>TEL</option><option>未設定</option></select>
        <label>住所</label>
        <div class="address-checks">
          <label><input type="checkbox"> 都道府県</label>
          <label><input type="checkbox"> 市区町村</label>
          <label><input type="checkbox"> 番地</label>
          <label><input type="checkbox"> 建物名</label>
          <label><input type="checkbox"> 郵便番号</label>
        </div>
      </div>
    </div>
  </div>
</section>


<!-- =========================================================
     SMTP
========================================================= -->
<section id="page-smtp" class="page">
  <div class="page-title">
    <div>
      <h1>メールサーバ設定</h1>
      <p>モックでは実際のSMTP接続・メール送信は行いません。</p>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><strong>SMTP設定</strong></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>SMTPサーバ</label>
          <input id="smtpServer" placeholder="smtp.example.com">
        </div>
        <div class="form-group">
          <label>SMTPポート</label>
          <input id="smtpPort" value="587">
        </div>
        <div class="form-group">
          <label>暗号化方式</label>
          <select id="smtpEncryption">
            <option>TLS</option>
            <option>SSL</option>
            <option>なし</option>
          </select>
        </div>
        <div class="form-group">
          <label>SMTP認証</label>
          <select><option>使用する</option><option>使用しない</option></select>
        </div>
        <div class="form-group">
          <label>SMTPユーザー名</label>
          <input>
        </div>
        <div class="form-group">
          <label>SMTPパスワード</label>
          <input type="password">
        </div>
        <div class="form-group">
          <label>送信元メールアドレス</label>
          <input type="email">
        </div>
        <div class="form-group">
          <label>送信元名</label>
          <input>
        </div>
        <div class="form-group">
          <label>返信先メールアドレス</label>
          <input type="email">
        </div>
      </div>

      <div style="display:flex;gap:8px">
        <button class="btn btn-primary" onclick="saveSmtp()">設定を保存</button>
        <button class="btn" onclick="testSmtp(true)">接続テスト：成功を再現</button>
        <button class="btn btn-danger" onclick="testSmtp(false)">接続テスト：失敗を再現</button>
      </div>
      <div id="smtpStatus" class="status-box neutral">未設定</div>
    </div>
  </div>
</section>


<!-- =========================================================
     回答者画面
========================================================= -->
<section id="page-answer" class="page">
  <div class="preview-shell">
    <div class="card">
      <div class="card-body" id="answerContent"></div>
    </div>
  </div>
</section>


<!-- =========================================================
     回答確認
========================================================= -->
<section id="page-answer-confirm" class="page">
  <div class="preview-shell">
    <div class="stepper">
      <div class="step">回答</div>
      <div class="step active">確認</div>
      <div class="step">完了</div>
    </div>
    <div class="card">
      <div class="card-header"><strong>回答内容の確認</strong></div>
      <div class="card-body" id="answerConfirmContent"></div>
      <div class="card-body" style="border-top:1px solid #e2e8f0">
        <button class="btn" onclick="backToAnswer()">修正する</button>
        <button class="btn btn-primary" onclick="submitAnswer()">回答を送信する</button>
      </div>
    </div>
  </div>
</section>


<!-- =========================================================
     完了
========================================================= -->
<section id="page-answer-complete" class="page">
  <div class="preview-shell">
    <div class="card">
      <div class="card-body center" style="padding:60px 20px">
        <div style="font-size:52px;color:#16a34a">✓</div>
        <h1>回答ありがとうございました</h1>
        <p class="muted">ご回答を受け付けました。</p>
      </div>
    </div>
  </div>
</section>

</main>


<!-- =========================================================
     Modal
========================================================= -->
<div id="modalBackdrop" class="modal-backdrop">
  <div class="modal">
    <div class="modal-header" id="modalTitle"></div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal()">キャンセル</button>
      <button class="btn btn-primary" id="modalExecute">実行</button>
    </div>
  </div>
</div>

<div id="toast" class="toast"></div>


<script>
/* ============================================================
   サンプルデータ
============================================================ */

const now = new Date();

let surveys = [
  {
    id: "s1",
    createdAt: "2026-07-20T10:00",
    updatedAt: "2026-08-24T15:20",
    title: "2026年度 顧客満足度アンケート",
    description: "サービスに関する満足度をお聞かせください。",
    start: "2026-08-01T09:00",
    end: "2026-09-30T18:00",
    status: "公開中",
    numbering: "global",
    answers: 28,
    groups: [
      {
        id:"g1",
        title:"サービスについて",
        questions:[
          {
            id:"q1",text:"現在のサービスに満足していますか？",
            type:"single",required:true,
            choices:["とても満足","満足","普通","不満","とても不満"],
            branch:{}
          },
          {
            id:"q2",text:"改善してほしい点を教えてください。",
            type:"free",required:false,choices:[],branch:{}
          }
        ]
      },
      {
        id:"g2",
        title:"今後について",
        questions:[
          {
            id:"q3",text:"今後も利用したいと思いますか？",
            type:"single",required:true,
            choices:["はい","いいえ"],branch:{}
          }
        ]
      }
    ]
  },
  {
    id: "s2",
    createdAt: "2026-08-10T09:00",
    updatedAt: "2026-08-22T11:10",
    title: "新サービスご利用意向調査",
    description: "新サービスについてのご意見をお聞かせください。",
    start: "2026-08-15T09:00",
    end: "2026-12-31T18:00",
    status: "下書き",
    numbering: "global",
    answers: 0,
    groups: [
      {
        id:"g3",
        title:"ご利用意向",
        questions:[
          {
            id:"q4",text:"新サービスを利用したいですか？",
            type:"single",required:true,
            choices:["利用したい","検討したい","利用しない"],branch:{}
          }
        ]
      }
    ]
  },
  {
    id: "s3",
    createdAt: "2026-07-01T10:00",
    updatedAt: "2026-08-21T12:00",
    title: "終了済みアンケート（公開中＋期限経過）",
    description: "終了状態の確認用サンプルです。",
    start: "2026-07-01T09:00",
    end: "2026-08-01T18:00",
    status: "公開中",
    numbering: "global",
    answers: 15,
    groups: [
      {
        id:"g4",
        title:"アンケート",
        questions:[
          {
            id:"q5",text:"サンプル質問です。",
            type:"single",required:true,
            choices:["はい","いいえ"],branch:{}
          }
        ]
      }
    ]
  },
  {
    id: "s4",
    createdAt: "2026-07-05T10:00",
    updatedAt: "2026-08-20T12:00",
    title: "停止中（期限経過しても終了しない）",
    description: "停止状態の終了判定確認用です。",
    start: "2026-07-05T09:00",
    end: "2026-08-01T18:00",
    status: "停止",
    numbering: "global",
    answers: 5,
    groups: [
      {
        id:"g5",
        title:"確認",
        questions:[
          {
            id:"q6",text:"停止状態の質問です。",
            type:"free",required:false,
            choices:[],branch:{}
          }
        ]
      }
    ]
  },
  {
    id: "s5",
    createdAt: "2026-07-02T10:00",
    updatedAt: "2026-08-19T12:00",
    title: "下書き（期限経過しても終了しない）",
    description: "下書き状態の終了判定確認用です。",
    start: "2026-07-02T09:00",
    end: "2026-08-01T18:00",
    status: "下書き",
    numbering: "global",
    answers: 0,
    groups: [
      {
        id:"g6",
        title:"確認",
        questions:[
          {
            id:"q7",text:"下書き状態の質問です。",
            type:"single",required:false,
            choices:["A","B"],branch:{}
          }
        ]
      }
    ]
  }
];

let customers = [
  {id:"c1",org:"株式会社サンプル",name:"山田 太郎",email:"yamada@example.com",phone:"03-1111-1111",address:"東京都千代田区",status:"未送信",lastSend:"",count:0,kintone:true},
  {id:"c2",org:"株式会社テスト",name:"佐藤 花子",email:"sato@example.com",phone:"03-2222-2222",address:"東京都港区",status:"送信済み / 未回答",lastSend:"2026-08-20 10:00",count:1,kintone:true},
  {id:"c3",org:"合同会社サンプル",name:"鈴木 一郎",email:"suzuki@example.com",phone:"03-3333-3333",address:"大阪府大阪市",status:"回答済み",lastSend:"2026-08-18 09:30",count:1,kintone:false},
  {id:"c4",org:"ABC株式会社",name:"田中 美咲",email:"tanaka@example.com",phone:"03-4444-4444",address:"神奈川県横浜市",status:"未送信",lastSend:"",count:0,kintone:true},
  {id:"c5",org:"XYZ株式会社",name:"高橋 健",email:"takahashi@example.com",phone:"03-5555-5555",address:"埼玉県さいたま市",status:"回答済み",lastSend:"2026-08-15 14:20",count:2,kintone:false}
];

let sendHistories = [
  {
    id:"h1",surveyId:"s1",date:"2026-08-20 10:00",
    type:"一括送信",count:3,
    subject:"2026年度 顧客満足度アンケート ご回答のお願い",
    executor:"管理者",
    customers:["佐藤 花子","鈴木 一郎","高橋 健"],
    body:"山田 様\nアンケートURL: https://mock.local/answer/s1/c2"
  }
];

let editorSurvey = null;
let editorOriginal = null;
let currentTargetSurveyId = null;
let currentMailTab = "customers";
let currentAnswerSurvey = null;
let answerValues = {};
let answerVisibleIds = [];
let currentAnswerStep = 1;


/* ============================================================
   共通
============================================================ */

function clone(obj){
  return JSON.parse(JSON.stringify(obj));
}

function uid(prefix="id"){
  return prefix + Math.random().toString(36).slice(2,9) + Date.now().toString(36);
}

function formatDate(v){
  if(!v) return "-";
  return v.replace("T"," ");
}

function escapeHtml(v){
  return String(v ?? "")
    .replaceAll("&","&amp;")
    .replaceAll("<","&lt;")
    .replaceAll(">","&gt;")
    .replaceAll('"',"&quot;")
    .replaceAll("'","&#039;");
}

function statusBadge(status){
  const map={
    "下書き":"badge-draft",
    "公開中":"badge-public",
    "停止":"badge-stop",
    "終了":"badge-end"
  };
  return `<span class="badge ${map[status] || "badge-info"}">${escapeHtml(status)}</span>`;
}

function toast(message){
  const el=document.getElementById("toast");
  el.textContent=message;
  el.classList.add("show");
  clearTimeout(window.__toastTimer);
  window.__toastTimer=setTimeout(()=>el.classList.remove("show"),2800);
}

function showPage(page){
  const pages=document.querySelectorAll(".page");
  pages.forEach(p=>p.classList.remove("active"));

  const el=document.getElementById("page-"+page);
  if(!el) return;
  el.classList.add("active");

  document.querySelectorAll(".nav button").forEach(b=>b.classList.remove("active"));
  const nav=document.querySelector(`[data-nav="${page}"]`);
  if(nav) nav.classList.add("active");

  if(page==="list") renderSurveyList();
  if(page==="kintone") {}
  if(page==="smtp") {}
}

function openModal(title,body,onExecute){
  document.getElementById("modalTitle").textContent=title;
  document.getElementById("modalBody").innerHTML=body;
  document.getElementById("modalExecute").onclick=()=>{
    closeModal();
    onExecute();
  };
  document.getElementById("modalBackdrop").classList.add("show");
}

function closeModal(){
  document.getElementById("modalBackdrop").classList.remove("show");
}


/* ============================================================
   終了状態自動判定
============================================================ */

function applyAutomaticEnd(survey){
  if(
    survey.status==="公開中" &&
    survey.end &&
    new Date() > new Date(survey.end)
  ){
    survey.status="終了";
    survey.updatedAt=new Date().toISOString().slice(0,16);
    return true;
  }
  return false;
}

function applyAllAutomaticEnds(){
  surveys.forEach(applyAutomaticEnd);
}


/* ============================================================
   一覧
============================================================ */

function renderSurveyList(){
  applyAllAutomaticEnds();

  const search=(document.getElementById("searchInput").value || "").trim().toLowerCase();
  const filter=document.getElementById("statusFilter").value;
  const sort=document.getElementById("sortSelect").value;

  let list=surveys.filter(s=>{
    const okSearch=!search || s.title.toLowerCase().includes(search);
    const okStatus=filter==="all" || s.status===filter;
    return okSearch && okStatus;
  });

  list.sort((a,b)=>{
    if(sort==="updatedDesc") return b.updatedAt.localeCompare(a.updatedAt);
    if(sort==="updatedAsc") return a.updatedAt.localeCompare(b.updatedAt);
    if(sort==="answersDesc") return b.answers-a.answers;
    if(sort==="answersAsc") return a.answers-b.answers;
    if(sort==="startDesc") return b.start.localeCompare(a.start);
    if(sort==="startAsc") return a.start.localeCompare(b.start);
    return 0;
  });

  const tbody=document.getElementById("surveyTable");

  if(!list.length){
    tbody.innerHTML=`<tr><td colspan="6" class="center muted">該当するアンケートはありません。</td></tr>`;
    return;
  }

  tbody.innerHTML=list.map(s=>`
    <tr>
      <td>
        <div>${formatDate(s.createdAt)}</div>
        <div class="small muted">更新：${formatDate(s.updatedAt)}</div>
      </td>
      <td>
        <strong>${escapeHtml(s.title)}</strong>
        <div class="small muted">${escapeHtml(s.description).slice(0,55)}</div>
      </td>
      <td>${formatDate(s.start)}<br>～ ${formatDate(s.end)}</td>
      <td>${statusBadge(s.status)}</td>
      <td>${s.answers}</td>
      <td>
        <div class="actions">
          <button class="btn btn-sm" onclick="editSurvey('${s.id}')">確認・編集</button>
          <button class="btn btn-sm btn-primary" onclick="openAnalysis('${s.id}')">集計</button>
          <button class="btn btn-sm btn-success" onclick="openMail('${s.id}')">送信</button>
          <button class="btn btn-sm" onclick="duplicateSurvey('${s.id}')">複製</button>
          <button class="btn btn-sm btn-danger" onclick="deleteSurvey('${s.id}')">削除</button>
        </div>
      </td>
    </tr>
  `).join("");
}

document.getElementById("searchInput").addEventListener("keydown",e=>{
  if(e.key==="Enter") renderSurveyList();
});


/* ============================================================
   作成・編集
============================================================ */

function newSurvey(){
  editorSurvey={
    id:uid("s"),
    createdAt:new Date().toISOString().slice(0,16),
    updatedAt:new Date().toISOString().slice(0,16),
    title:"",
    description:"",
    start:new Date().toISOString().slice(0,16),
    end:"",
    status:"下書き",
    numbering:"global",
    answers:0,
    groups:[
      {
        id:uid("g"),
        title:"新しいグループ",
        questions:[
          {
            id:uid("q"),
            text:"",
            type:"single",
            required:true,
            choices:["選択肢1","選択肢2"],
            branch:{}
          }
        ]
      }
    ]
  };
  editorOriginal=clone(editorSurvey);
  renderEditor();
  showPage("editor");
}

function editSurvey(id){
  const s=surveys.find(x=>x.id===id);
  if(!s) return;
  applyAutomaticEnd(s);
  editorSurvey=clone(s);
  editorOriginal=clone(s);
  renderEditor();
  showPage("editor");
}

function renderEditor(){
  document.getElementById("editorTitle").textContent=
    editorSurvey.title ? "アンケート作成・編集" : "アンケート作成・編集";

  document.getElementById("surveyTitle").value=editorSurvey.title;
  document.getElementById("surveyDescription").value=editorSurvey.description;
  document.getElementById("surveyStart").value=editorSurvey.start;
  document.getElementById("surveyEnd").value=editorSurvey.end;

  document.querySelectorAll('input[name="numbering"]').forEach(r=>{
    r.checked=r.value===editorSurvey.numbering;
  });

  const status=document.getElementById("editorStatus");

  if(editorSurvey.status==="終了"){
    status.innerHTML=`<option value="終了">終了</option>`;
    status.disabled=true;
  }else{
    status.disabled=false;
    let options=[];
    if(editorSurvey.status==="下書き"){
      options=[["下書き","下書き"],["公開中","公開"]];
    }else if(editorSurvey.status==="公開中"){
      options=[["公開中","公開中"],["停止","停止"]];
    }else if(editorSurvey.status==="停止"){
      options=[["停止","停止"],["公開中","再開"]];
    }
    status.innerHTML=options.map(x=>`<option value="${x[0]}">${x[1]}</option>`).join("");
    status.value=editorSurvey.status;
  }

  renderGroups();
}

function collectBasic(){
  editorSurvey.title=document.getElementById("surveyTitle").value;
  editorSurvey.description=document.getElementById("surveyDescription").value;
  editorSurvey.start=document.getElementById("surveyStart").value;
  editorSurvey.end=document.getElementById("surveyEnd").value;
  const numbering=document.querySelector('input[name="numbering"]:checked');
  editorSurvey.numbering=numbering ? numbering.value : "global";
}

function saveSurvey(){
  collectBasic();

  if(!editorSurvey.title.trim()){
    toast("アンケートタイトルを入力してください");
    return;
  }

  editorSurvey.updatedAt=new Date().toISOString().slice(0,16);

  const idx=surveys.findIndex(x=>x.id===editorSurvey.id);

  if(idx<0){
    editorSurvey.status="下書き";
    surveys.push(clone(editorSurvey));
  }else{
    // 状態は状態操作で変更された現在状態を維持
    surveys[idx]=clone(editorSurvey);
  }

  toast("保存しました");
  showPage("list");
}

function cancelEditor(){
  openModal(
    "変更内容を破棄しますか？",
    "現在の編集内容は保存されません。",
    ()=>{
      editorSurvey=clone(editorOriginal);
      showPage("list");
    }
  );
}

function requestStatusChange(newStatus){
  if(!editorSurvey || newStatus===editorSurvey.status) return;

  const messages={
    "公開中":"このアンケートを公開しますか？",
    "停止":"このアンケートを停止しますか？"
  };

  if(!messages[newStatus]){
    renderEditor();
    return;
  }

  const oldStatus=editorSurvey.status;

  openModal(messages[newStatus],"状態を変更すると画面上へ即時反映されます。",()=>{
    editorSurvey.status=newStatus;
    editorSurvey.updatedAt=new Date().toISOString().slice(0,16);
    renderEditor();
    toast(`状態を「${newStatus}」へ変更しました`);
  });
}


/* ============================================================
   グループ・質問
============================================================ */

function renderGroups(){
  const root=document.getElementById("groups");

  root.innerHTML=editorSurvey.groups.map((g,gi)=>`
    <div class="group" draggable="true"
      data-group-id="${g.id}"
      ondragstart="groupDragStart(event,'${g.id}')"
      ondragover="event.preventDefault()"
      ondrop="groupDrop(event,'${g.id}')">

      <div class="group-header">
        <span class="drag-handle">☷</span>
        <strong>グループ ${gi+1}</strong>
        <input class="group-title-input"
          value="${escapeHtml(g.title)}"
          onchange="updateGroupTitle('${g.id}',this.value)">
        <button class="btn btn-sm btn-danger" onclick="deleteGroup('${g.id}')">削除</button>
      </div>

      <div>
        ${g.questions.map((q,qi)=>questionHtml(g,q,qi)).join("")}
      </div>

      <div class="group-footer">
        <button class="add-question" onclick="addQuestion('${g.id}')">＋ 質問を追加</button>
      </div>
    </div>
  `).join("");

  renumber();
}

function questionHtml(group,q,index){
  const num=getQuestionNumber(q.id);

  let typeOptions=[
    ["single","単一選択"],
    ["multi","複数選択"],
    ["free","自由記述"]
  ];

  let choicesHtml="";
  if(q.type==="single" || q.type==="multi"){
    choicesHtml=`
      <div style="margin-top:10px">
        <label>選択肢</label>
        ${q.choices.map((c,i)=>`
          <div class="choice-row">
            <input value="${escapeHtml(c)}"
              onchange="updateChoice('${group.id}','${q.id}',${i},this.value)">
            <button class="btn btn-sm btn-danger"
              onclick="deleteChoice('${group.id}','${q.id}',${i})">削除</button>
          </div>
        `).join("")}
        <button class="btn btn-sm" style="margin-top:7px"
          onclick="addChoice('${group.id}','${q.id}')">＋ 選択肢追加</button>
      </div>
    `;
  }

  return `
    <div class="question"
      draggable="true"
      data-question-id="${q.id}"
      ondragstart="questionDragStart(event,'${group.id}','${q.id}')"
      ondragover="event.preventDefault()"
      ondrop="questionDrop(event,'${group.id}','${q.id}')">

      <div class="question-head">
        <span class="drag-handle">☷</span>
        <span class="q-number" id="num-${q.id}">${num}</span>
        <strong>質問 ${index+1}</strong>
        <div class="question-controls">
          <button class="btn btn-sm btn-danger"
            onclick="deleteQuestion('${group.id}','${q.id}')">削除</button>
        </div>
      </div>

      <div class="question-grid">
        <div>
          <label>質問文</label>
          <input value="${escapeHtml(q.text)}"
            onchange="updateQuestion('${group.id}','${q.id}','text',this.value)">
        </div>
        <div>
          <label>回答形式</label>
          <select onchange="updateQuestion('${group.id}','${q.id}','type',this.value)">
            ${typeOptions.map(x=>`<option value="${x[0]}" ${q.type===x[0]?"selected":""}>${x[1]}</option>`).join("")}
          </select>
        </div>
        <div>
          <label>回答</label>
          <select onchange="updateQuestion('${group.id}','${q.id}','required',this.value==='true')">
            <option value="true" ${q.required?"selected":""}>必須</option>
            <option value="false" ${!q.required?"selected":""}>任意</option>
          </select>
        </div>
      </div>

      ${choicesHtml}

      ${q.type==="single" ? branchHtml(group,q) : ""}
    </div>
  `;
}

function branchHtml(group,q){
  return `
    <div style="margin-top:12px">
      <label>条件分岐</label>
      ${q.choices.map((choice,i)=>`
        <div class="choice-row">
          <span style="width:160px;padding:9px 0;font-size:12px">${escapeHtml(choice)}</span>
          <select onchange="setBranch('${q.id}',${i},this.value)">
            <option value="">次に表示する質問：指定なし</option>
            ${allQuestionOptions(q.id)}
          </select>
        </div>
      `).join("")}
    </div>
  `;
}

function allQuestionOptions(currentId){
  let html="";
  surveys; // mock
  editorSurvey.groups.forEach(g=>{
    g.questions.forEach(q=>{
      if(q.id===currentId) return;
      html+=`<option value="${q.id}">${getQuestionNumber(q.id)} ${escapeHtml(q.text || "(未入力)")}</option>`;
    });
  });
  return html;
}

function updateGroupTitle(gid,value){
  const g=editorSurvey.groups.find(x=>x.id===gid);
  if(g) g.title=value;
}

function addGroup(){
  editorSurvey.groups.push({
    id:uid("g"),
    title:"新しいグループ",
    questions:[]
  });
  renderGroups();
}

function deleteGroup(gid){
  const g=editorSurvey.groups.find(x=>x.id===gid);
  if(!g) return;

  const message=g.questions.length
    ? `「${g.title}」には${g.questions.length}件の質問があります。グループと質問を削除しますか？`
    : `「${g.title}」を削除しますか？`;

  openModal("グループ削除",message,()=>{
    editorSurvey.groups=editorSurvey.groups.filter(x=>x.id!==gid);
    renderGroups();
  });
}

function addQuestion(gid){
  const g=editorSurvey.groups.find(x=>x.id===gid);
  if(!g) return;

  g.questions.push({
    id:uid("q"),
    text:"",
    type:"single",
    required:true,
    choices:["選択肢1","選択肢2"],
    branch:{}
  });

  renderGroups();
}

function deleteQuestion(gid,qid){
  openModal("質問削除","この質問を削除しますか？",()=>{
    const g=editorSurvey.groups.find(x=>x.id===gid);
    if(!g) return;
    g.questions=g.questions.filter(q=>q.id!==qid);
    renderGroups();
  });
}

function updateQuestion(gid,qid,key,value){
  const q=findQuestion(gid,qid);
  if(!q) return;

  q[key]=value;

  if(key==="type"){
    if(value==="free") q.choices=[];
    else if(!q.choices.length) q.choices=["選択肢1","選択肢2"];
    renderGroups();
  }
}

function findQuestion(gid,qid){
  const g=editorSurvey.groups.find(x=>x.id===gid);
  return g?.questions.find(q=>q.id===qid);
}

function addChoice(gid,qid){
  const q=findQuestion(gid,qid);
  if(q){
    q.choices.push(`選択肢${q.choices.length+1}`);
    renderGroups();
  }
}

function updateChoice(gid,qid,index,value){
  const q=findQuestion(gid,qid);
  if(q) q.choices[index]=value;
}

function deleteChoice(gid,qid,index){
  const q=findQuestion(gid,qid);
  if(!q) return;
  q.choices.splice(index,1);
  renderGroups();
}

function setBranch(qid,index,target){
  for(const g of editorSurvey.groups){
    const q=g.questions.find(x=>x.id===qid);
    if(q){
      q.branch[index]=target;
      break;
    }
  }
}

function getQuestionNumber(qid){
  let global=0;
  for(let gi=0;gi<editorSurvey.groups.length;gi++){
    const g=editorSurvey.groups[gi];

    for(let qi=0;qi<g.questions.length;qi++){
      const q=g.questions[qi];
      global++;

      if(q.id===qid){
        return editorSurvey.numbering==="global"
          ? `Q${global}`
          : `Q${gi+1}-${qi+1}`;
      }
    }
  }
  return "";
}

function renumber(){
  if(!editorSurvey) return;

  let global=0;
  editorSurvey.groups.forEach((g,gi)=>{
    g.questions.forEach((q,qi)=>{
      global++;
      const el=document.getElementById("num-"+q.id);
      if(el){
        el.textContent=editorSurvey.numbering==="global"
          ? `Q${global}`
          : `Q${gi+1}-${qi+1}`;
      }
    });
  });
}


/* ============================================================
   ドラッグ＆ドロップ
============================================================ */

let draggedQuestion=null;
let draggedGroup=null;

function questionDragStart(e,gid,qid){
  draggedQuestion={gid,qid};
  e.dataTransfer.effectAllowed="move";
}

function questionDrop(e,targetGid,targetQid){
  e.preventDefault();
  if(!draggedQuestion) return;

  const sourceG=editorSurvey.groups.find(g=>g.id===draggedQuestion.gid);
  const targetG=editorSurvey.groups.find(g=>g.id===targetGid);
  if(!sourceG || !targetG) return;

  const idx=sourceG.questions.findIndex(q=>q.id===draggedQuestion.qid);
  if(idx<0) return;

  const [q]=sourceG.questions.splice(idx,1);
  let targetIndex=targetG.questions.findIndex(x=>x.id===targetQid);

  if(targetIndex<0) targetIndex=targetG.questions.length;
  targetG.questions.splice(targetIndex,0,q);

  draggedQuestion=null;
  renderGroups();
  toast("質問の並び・所属グループを更新しました");
}

function groupDragStart(e,gid){
  draggedGroup=gid;
  e.dataTransfer.effectAllowed="move";
}

function groupDrop(e,targetGid){
  e.preventDefault();
  if(!draggedGroup || draggedGroup===targetGid) return;

  const from=editorSurvey.groups.findIndex(g=>g.id===draggedGroup);
  const to=editorSurvey.groups.findIndex(g=>g.id===targetGid);
  if(from<0 || to<0) return;

  const [g]=editorSurvey.groups.splice(from,1);
  editorSurvey.groups.splice(to,0,g);
  draggedGroup=null;

  renderGroups();
  toast("グループの並び順を更新しました");
}


/* ============================================================
   複製・削除
============================================================ */

function duplicateSurvey(id){
  const s=surveys.find(x=>x.id===id);
  if(!s) return;

  openModal(
    "アンケートを複製しますか？",
    "タイトル・説明・期間・グループ・質問・選択肢・必須設定・条件分岐を複製します。<br><br>公開状態・回答データ・送信履歴は複製されません。",
    ()=>{
      const copy=clone(s);
      copy.id=uid("s");
      copy.createdAt=new Date().toISOString().slice(0,16);
      copy.updatedAt=new Date().toISOString().slice(0,16);
      copy.title=s.title+"（コピー）";
      copy.status="下書き";
      copy.answers=0;

      copy.groups.forEach(g=>{
        g.id=uid("g");
        g.questions.forEach(q=>{
          q.id=uid("q");
        });
      });

      surveys.push(copy);
      renderSurveyList();
      toast("下書きとして複製しました");
    }
  );
}

function deleteSurvey(id){
  const s=surveys.find(x=>x.id===id);
  if(!s) return;

  openModal(
    "アンケートを削除しますか？",
    `「${escapeHtml(s.title)}」を一覧から削除します。`,
    ()=>{
      surveys=surveys.filter(x=>x.id!==id);
      renderSurveyList();
      toast("削除しました");
    }
  );
}


/* ============================================================
   プレビュー
============================================================ */

function openPreview(){
  collectBasic();
  renderPreview();
  showPage("preview");
}

function renderPreview(){
  const s=editorSurvey;
  let html=`
    <h1>${escapeHtml(s.title || "アンケートタイトル")}</h1>
    <p class="muted">${escapeHtml(s.description)}</p>
    <p class="small muted">${formatDate(s.start)} ～ ${formatDate(s.end)}</p>
    <hr style="border:0;border-top:1px solid #e2e8f0;margin:20px 0">
  `;

  let global=0;
  s.groups.forEach((g,gi)=>{
    html+=`<h2 style="font-size:19px;margin-top:25px">${escapeHtml(g.title)}</h2>`;

    g.questions.forEach((q,qi)=>{
      global++;
      const num=s.numbering==="global"
        ? `Q${global}`
        : `Q${gi+1}-${qi+1}`;

      html+=`
        <div class="answer-question">
          <div>
            <strong>${num}. ${escapeHtml(q.text || "質問文未入力")}</strong>
            ${q.required ? '<span class="required">必須</span>' : ''}
          </div>
      `;

      if(q.type==="single"){
        q.choices.forEach(c=>{
          html+=`<label class="answer-choice"><input type="radio" name="p${q.id}"> ${escapeHtml(c)}</label>`;
        });
      }else if(q.type==="multi"){
        q.choices.forEach(c=>{
          html+=`<label class="answer-choice"><input type="checkbox"> ${escapeHtml(c)}</label>`;
        });
      }else{
        html+=`<textarea placeholder="回答を入力してください"></textarea>`;
      }

      html+=`</div>`;
    });
  });

  html+=`
    <div class="right">
      <button class="btn btn-primary" onclick="toast('プレビュー送信：実際には送信されません')">回答を送信する</button>
    </div>
  `;

  document.getElementById("previewContent").innerHTML=html;
}

function setPreviewDevice(device){
  const el=document.getElementById("previewDevice");
  el.classList.toggle("mobile",device==="mobile");
}

function backFromPreview(){
  renderEditor();
  showPage("editor");
}

// プレビューを編集画面から開けるようにキーボード以外でも提供
document.addEventListener("keydown",e=>{
  if(e.ctrlKey && e.key==="p"){
    e.preventDefault();
    if(editorSurvey) openPreview();
  }
});


/* ============================================================
   メール送信
============================================================ */

function openMail(id){
  const s=surveys.find(x=>x.id===id);
  if(!s) return;

  currentTargetSurveyId=id;
  document.getElementById("mailSurveyTitle").textContent=s.title;
  renderCustomers();
  renderHistory();
  switchMailTab("customers");
  showPage("mail");
}

function renderCustomers(){
  const search=(document.getElementById("customerSearch").value||"").toLowerCase();
  const status=document.getElementById("customerStatusFilter").value;

  const list=customers.filter(c=>{
    const str=(c.name+c.org+c.email).toLowerCase();
    return (!search || str.includes(search))
      && (status==="all" || c.status===status);
  });

  const root=document.getElementById("customerRows");

  root.innerHTML=list.map(c=>`
    <div class="customer-row">
      <span>
        <input type="checkbox" class="customer-check" data-id="${c.id}">
      </span>
      <span>
        <strong>${escapeHtml(c.org)}</strong><br>${escapeHtml(c.name)}
      </span>
      <span>${escapeHtml(c.email)}</span>
      <span>${escapeHtml(c.phone)}<br>${escapeHtml(c.address)}</span>
      <span>${escapeHtml(c.status)}<br><span class="small muted">${c.lastSend||""}</span></span>
      <span>
        ${c.kintone
          ? '<span class="badge badge-info">✓ kintone登録</span>'
          : '<span class="badge badge-stop">未登録</span>'}
      </span>
    </div>
  `).join("");

  if(!list.length){
    root.innerHTML=`<div class="center muted" style="padding:25px">該当する顧客はいません。</div>`;
  }
}

function toggleAllCustomers(checked){
  document.querySelectorAll(".customer-check").forEach(x=>x.checked=checked);
}

function selectedCustomers(){
  const ids=[...document.querySelectorAll(".customer-check:checked")].map(x=>x.dataset.id);
  return customers.filter(c=>ids.includes(c.id));
}

function previewSelectedMail(){
  const selected=selectedCustomers();
  if(!selected.length){
    toast("顧客を選択してください");
    return;
  }

  const s=surveys.find(x=>x.id===currentTargetSurveyId);
  const c=selected[0];

  const subject=document.getElementById("mailSubject").value
    .replaceAll("{顧客名}",c.name)
    .replaceAll("{アンケートURL}",`https://mock.local/answer/${s.id}/${c.id}`);

  const body=document.getElementById("mailBody").value
    .replaceAll("{顧客名}",c.name)
    .replaceAll("{アンケートURL}",`https://mock.local/answer/${s.id}/${c.id}`);

  openModal(
    "送信文を確認",
    `<strong>宛先：</strong>${escapeHtml(c.email)}
     <hr>
     <strong>件名：</strong>${escapeHtml(subject)}
     <br><br>
     <strong>本文：</strong><pre style="white-space:pre-wrap;font-family:inherit">${escapeHtml(body)}</pre>`,
    ()=>{}
  );

  document.getElementById("modalExecute").style.display="none";
}

function sendMail(type){
  const selected=selectedCustomers();

  if(!selected.length){
    toast("顧客を選択してください");
    return;
  }

  const already=selected.filter(c=>c.count>0);

  if(type==="normal" && already.length){
    openModal(
      "送信済み顧客が含まれています",
      `既に送信済みの宛先が ${already.length} 件含まれています。再送しますか？`,
      ()=>executeMail(selected,"再送")
    );
    return;
  }

  if(type==="remind"){
    const nonAnswered=selected.filter(c=>c.status==="送信済み / 未回答");
    if(!nonAnswered.length){
      toast("選択した顧客に未回答者はいません");
      return;
    }
    openModal(
      "リマインド送信",
      `${nonAnswered.length}件の未回答者へリマインドを送信します。`,
      ()=>executeMail(nonAnswered,"リマインド")
    );
    return;
  }

  openModal(
    "メールを一括送信しますか？",
    `${selected.length}件の顧客へメールを送信します。<br><br>モックでは実際のメール送信は行いません。`,
    ()=>executeMail(selected,"一括送信")
  );
}

function executeMail(selected,type){
  const success=selected.filter(()=>Math.random()>.12);
  const failed=selected.filter(c=>!success.includes(c));

  selected.forEach(c=>{
    c.count++;
    c.lastSend=new Date().toLocaleString("ja-JP");
    if(success.includes(c)) c.status="送信済み / 未回答";
  });

  const s=surveys.find(x=>x.id===currentTargetSurveyId);

  sendHistories.unshift({
    id:uid("h"),
    surveyId:currentTargetSurveyId,
    date:new Date().toLocaleString("ja-JP"),
    type,
    count:selected.length,
    subject:document.getElementById("mailSubject").value,
    executor:"管理者",
    customers:selected.map(c=>c.name),
    body:document.getElementById("mailBody").value
  });

  document.getElementById("sendResult").innerHTML=`
    <div class="notice ${failed.length ? "notice-warning":"notice-success"}">
      <strong>送信結果</strong><br>
      対象件数：${selected.length}件　
      成功：${success.length}件　
      失敗：${failed.length}件　
      送信日時：${new Date().toLocaleString("ja-JP")}
    </div>
  `;

  renderCustomers();
  renderHistory();
  toast("送信処理を実行しました。送信履歴画面へは遷移しません。");
}

function switchMailTab(tab){
  currentMailTab=tab;

  document.getElementById("mailCustomers").style.display=tab==="customers"?"block":"none";
  document.getElementById("mailHistory").style.display=tab==="history"?"block":"none";

  document.getElementById("mailTabCustomers").classList.toggle("active",tab==="customers");
  document.getElementById("mailTabHistory").classList.toggle("active",tab==="history");

  if(tab==="history") renderHistory();
}

function renderHistory(){
  const rows=sendHistories.filter(h=>h.surveyId===currentTargetSurveyId);
  const tbody=document.getElementById("historyTable");

  if(!rows.length){
    tbody.innerHTML=`<tr><td colspan="7" class="center muted">送信履歴はありません。</td></tr>`;
    return;
  }

  tbody.innerHTML=rows.map(h=>`
    <tr>
      <td>${escapeHtml(h.date)}</td>
      <td>${escapeHtml(h.type)}</td>
      <td>${h.count}</td>
      <td>${escapeHtml(h.subject)}</td>
      <td>${escapeHtml(h.executor)}</td>
      <td>${h.customers.map(escapeHtml).join("<br>")}</td>
      <td><button class="btn btn-sm" onclick="viewHistory('${h.id}')">メール確認</button></td>
    </tr>
  `).join("");
}

function viewHistory(id){
  const h=sendHistories.find(x=>x.id===id);
  if(!h) return;

  const s=surveys.find(x=>x.id===h.surveyId);

  openModal(
    "送信済みメール内容",
    `<strong>件名：</strong>${escapeHtml(h.subject)}
     <br><br>
     <strong>本文：</strong>
     <pre style="white-space:pre-wrap;font-family:inherit">${escapeHtml(h.body)}</pre>
     <hr>
     <strong>対象アンケート：</strong>${escapeHtml(s?.title||"")}
     <br><strong>対象顧客：</strong>${h.customers.map(escapeHtml).join("、")}
     <br><br>
     <span class="small muted">顧客名差し込み後の個別URLもモック上で確認できます。</span>`,
    ()=>{}
  );

  document.getElementById("modalExecute").style.display="none";
}


/* ============================================================
   集計
============================================================ */

function openAnalysis(id){
  const s=surveys.find(x=>x.id===id);
  if(!s) return;

  currentTargetSurveyId=id;
  document.getElementById("analysisSurveyTitle").textContent=s.title;

  renderAnalysis(s);
  showPage("analysis");
}

function renderAnalysis(s){
  const target=customers.length;
  const answers=s.answers;
  const unanswered=Math.max(target-answers,0);
  const unregistered=customers.filter(c=>!c.kintone && c.status==="回答済み").length;
  const rate=target ? Math.round(answers/target*100) : 0;

  document.getElementById("sumTarget").textContent=target;
  document.getElementById("sumAnswers").textContent=answers;
  document.getElementById("sumUnregistered").textContent=unregistered;
  document.getElementById("sumUnanswered").textContent=unanswered;
  document.getElementById("sumRate").textContent=rate+"%";

  let qs=[];
  s.groups.forEach(g=>g.questions.forEach(q=>qs.push(q)));

  document.getElementById("questionFilters").innerHTML=qs.length
    ? qs.map(q=>`
      <label style="display:inline-flex;margin:0 14px 8px 0;font-weight:400">
        <input type="checkbox" class="analysis-q" checked value="${q.id}" style="width:auto;margin-right:5px">
        ${escapeHtml(getSurveyQuestionNumber(s,q.id))} ${escapeHtml(q.text)}
      </label>
    `).join("")
    : "設問はありません。";

  document.getElementById("analysisQuestions").innerHTML=qs.map(q=>{
    const n=getSurveyQuestionNumber(s,q.id);

    if(q.type==="free"){
      return `
        <div class="section">
          <h3>${n} ${escapeHtml(q.text)}</h3>
          <p class="muted">自由記述回答内容一覧</p>
          <div class="notice notice-info">「サービスが良かった」「今後も利用したい」などの回答を表示する想定です。</div>
        </div>
      `;
    }

    const values=q.choices.map((c,i)=>({
      label:c,count:Math.max(0,Math.round(answers*(i===0?.45:i===1?.28:i===2?.15:.12)))
    }));

    const max=Math.max(...values.map(v=>v.count),1);

    return `
      <div class="section">
        <h3>${n} ${escapeHtml(q.text)}</h3>
        ${values.map(v=>`
          <div style="margin:12px 0">
            <div style="display:flex;justify-content:space-between;font-size:12px">
              <span>${escapeHtml(v.label)}</span>
              <strong>${v.count}件</strong>
            </div>
            <div class="bar"><span style="width:${v.count/max*100}%"></span></div>
          </div>
        `).join("")}
      </div>
    `;
  }).join("") || `<div class="muted">現在、回答データはありません</div>`;

  document.getElementById("individualAnswers").innerHTML=`
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>組織名</th><th>氏名</th><th>回答日時</th><th>回答概要</th><th>操作</th></tr>
        </thead>
        <tbody>
          ${customers.filter(c=>c.status==="回答済み").map(c=>`
            <tr>
              <td>${escapeHtml(c.org)}</td>
              <td>${escapeHtml(c.name)}</td>
              <td>${escapeHtml(c.lastSend)}</td>
              <td>回答済み</td>
              <td><button class="btn btn-sm" onclick="viewIndividualAnswer('${c.id}')">全回答を表示</button></td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>
  `;
}

function getSurveyQuestionNumber(s,qid){
  let n=0;
  for(let gi=0;gi<s.groups.length;gi++){
    for(let qi=0;qi<s.groups[gi].questions.length;qi++){
      const q=s.groups[gi].questions[qi];
      n++;
      if(q.id===qid){
        return s.numbering==="global" ? `Q${n}` : `Q${gi+1}-${qi+1}`;
      }
    }
  }
  return "";
}

function selectAllQuestions(value){
  document.querySelectorAll(".analysis-q").forEach(x=>x.checked=value);
}

function viewIndividualAnswer(cid){
  const c=customers.find(x=>x.id===cid);
  const s=surveys.find(x=>x.id===currentTargetSurveyId);
  if(!c||!s)return;

  let html=`<strong>${escapeHtml(c.org)} / ${escapeHtml(c.name)}</strong><hr>`;

  s.groups.forEach(g=>{
    g.questions.forEach(q=>{
      html+=`
        <div style="margin-bottom:15px">
          <strong>${getSurveyQuestionNumber(s,q.id)} ${escapeHtml(q.text)}</strong>
          <div class="notice notice-info">サンプル回答：${q.type==="free"?"サービスについての回答です。":escapeHtml(q.choices[0]||"回答済み")}</div>
        </div>
      `;
    });
  });

  openModal("個別回答",html,()=>{});
  document.getElementById("modalExecute").style.display="none";
}

function exportCsv(){
  toast("CSV出力操作を実行しました（モック）");
}

function exportPdf(){
  toast("PDF出力操作を実行しました（モック）");
}


/* ============================================================
   kintone
============================================================ */

function testKintone(){
  const success=Math.random()>.3;
  const el=document.getElementById("kinStatus");

  if(success){
    el.className="status-box success";
    el.innerHTML="✓ kintoneへの接続に成功しました";
  }else{
    el.className="status-box error";
    el.innerHTML="✕ kintoneへの接続に失敗しました";
  }
}

function saveKintone(){
  toast("kintone接続設定を保存しました（モック）");
}

function getKintoneFields(){
  document.getElementById("kinFields").innerHTML=`
    <div class="notice notice-success">項目一覧を取得しました。</div>
    <table>
      <thead><tr><th>フィールドコード</th><th>日本語フィールドラベル</th><th>種類</th></tr></thead>
      <tbody>
        <tr><td>company</td><td>組織名</td><td>文字列</td></tr>
        <tr><td>name</td><td>氏名</td><td>文字列</td></tr>
        <tr><td>email</td><td>メールアドレス</td><td>文字列</td></tr>
        <tr><td>department</td><td>部署名</td><td>文字列</td></tr>
        <tr><td>tel</td><td>電話番号</td><td>文字列</td></tr>
        <tr><td>prefecture</td><td>都道府県</td><td>文字列</td></tr>
        <tr><td>city</td><td>市区町村</td><td>文字列</td></tr>
        <tr><td>address</td><td>番地</td><td>文字列</td></tr>
        <tr><td>building</td><td>建物名</td><td>文字列</td></tr>
        <tr><td>zipcode</td><td>郵便番号</td><td>文字列</td></tr>
      </tbody>
    </table>
  `;
  toast("項目一覧を再取得しました");
}

function syncKintone(){
  const unregistered=customers.filter(c=>!c.kintone);
  unregistered.forEach(c=>c.kintone=true);

  toast(`${unregistered.length}件の顧客を同期しました（モック）`);
  if(document.getElementById("customerRows")) renderCustomers();
}


/* ============================================================
   SMTP
============================================================ */

function saveSmtp(){
  toast("メールサーバ設定を保存しました（モック）");
}

function testSmtp(success){
  const el=document.getElementById("smtpStatus");

  if(success){
    el.className="status-box success";
    el.innerHTML="✓ 接続確認済み";
  }else{
    el.className="status-box error";
    el.innerHTML="✕ 接続できません";
  }
}


/* ============================================================
   回答者フロー
   管理者ヘッダーを表示しない
============================================================ */

function startAnswer(surveyId,customerId=null){
  const s=surveys.find(x=>x.id===surveyId);
  if(!s)return;

  applyAutomaticEnd(s);

  if(s.status!=="公開中"){
    alert("このアンケートは現在回答できません。");
    return;
  }

  currentAnswerSurvey=s;
  answerValues={};
  answerVisibleIds=[];
  currentAnswerStep=1;

  // 回答者画面では管理者ナビゲーションを完全に非表示
  document.getElementById("adminHeader").style.display="none";

  renderAnswer();
  showPage("answer");
}

function renderAnswer(){
  const s=currentAnswerSurvey;
  let allQuestions=[];

  s.groups.forEach(g=>g.questions.forEach(q=>allQuestions.push(q)));

  // 条件分岐のモック
  answerVisibleIds=allQuestions.map(q=>q.id);

  const startIndex=(currentAnswerStep-1)*4;
  const pageQuestions=allQuestions.slice(startIndex,startIndex+4);

  const totalPages=Math.max(1,Math.ceil(allQuestions.length/4));

  let html=`
    <div class="stepper">
      <div class="step active">回答</div>
      <div class="step">確認</div>
      <div class="step">完了</div>
    </div>
    <h1>${escapeHtml(s.title)}</h1>
    <p class="muted">${escapeHtml(s.description)}</p>
    <hr style="border:0;border-top:1px solid #e2e8f0;margin:20px 0">
  `;

  pageQuestions.forEach(q=>{
    const num=getSurveyQuestionNumber(s,q.id);

    html+=`
      <div class="answer-question">
        <div style="margin-bottom:10px">
          <strong>${num}. ${escapeHtml(q.text || "質問文未入力")}</strong>
          ${q.required?'<span class="required">必須</span>':''}
        </div>
    `;

    if(q.type==="single"){
      q.choices.forEach(c=>{
        const checked=answerValues[q.id]===c;
        html+=`
          <label class="answer-choice">
            <input type="radio" name="answer-${q.id}" value="${escapeHtml(c)}"
              ${checked?"checked":""}
              onchange="setAnswer('${q.id}',this.value)">
            ${escapeHtml(c)}
          </label>`;
      });
    }else if(q.type==="multi"){
      const values=Array.isArray(answerValues[q.id])?answerValues[q.id]:[];
      q.choices.forEach(c=>{
        html+=`
          <label class="answer-choice">
            <input type="checkbox" value="${escapeHtml(c)}"
              ${values.includes(c)?"checked":""}
              onchange="toggleMultiAnswer('${q.id}',this.value,this.checked)">
            ${escapeHtml(c)}
          </label>`;
      });
    }else{
      html+=`
        <textarea rows="5"
          onchange="setAnswer('${q.id}',this.value)"
          placeholder="回答を入力してください">${escapeHtml(answerValues[q.id]||"")}</textarea>`;
    }

    html+=`</div>`;
  });

  html+=`
    <div style="display:flex;justify-content:space-between;gap:10px">
      <button class="btn" ${currentAnswerStep<=1?"disabled":""} onclick="answerBack()">戻る</button>
      ${
        currentAnswerStep<totalPages
        ? `<button class="btn btn-primary" onclick="answerNext()">次へ</button>`
        : `<button class="btn btn-primary" onclick="answerToConfirm()">回答確認</button>`
      }
    </div>
  `;

  document.getElementById("answerContent").innerHTML=html;
}

function setAnswer(qid,value){
  answerValues[qid]=value;
}

function toggleMultiAnswer(qid,value,checked){
  if(!Array.isArray(answerValues[qid])) answerValues[qid]=[];
  if(checked){
    if(!answerValues[qid].includes(value)) answerValues[qid].push(value);
  }else{
    answerValues[qid]=answerValues[qid].filter(x=>x!==value);
  }
}

function currentPageQuestions(){
  const all=[];
  currentAnswerSurvey.groups.forEach(g=>g.questions.forEach(q=>all.push(q)));
  const start=(currentAnswerStep-1)*4;
  return all.slice(start,start+4);
}

function validateQuestions(qs){
  let invalid=[];
  qs.forEach(q=>{
    if(!q.required)return;

    const v=answerValues[q.id];

    if(v===undefined || v===null || v==="" ||
      (Array.isArray(v)&&!v.length)){
      invalid.push(getSurveyQuestionNumber(currentAnswerSurvey,q.id));
    }
  });

  if(invalid.length){
    toast("未回答の必須項目があります：" + invalid.join(", "));
    return false;
  }

  return true;
}

function answerNext(){
  if(!validateQuestions(currentPageQuestions())) return;
  currentAnswerStep++;
  renderAnswer();
}

function answerBack(){
  if(currentAnswerStep>1){
    currentAnswerStep--;
    renderAnswer();
  }
}

function answerToConfirm(){
  if(!validateQuestions(currentPageQuestions())) return;

  let all=[];
  currentAnswerSurvey.groups.forEach(g=>g.questions.forEach(q=>all.push(q)));

  if(!all.every(q=>!q.required || (
    answerValues[q.id]!==undefined &&
    answerValues[q.id]!=="" &&
    (!Array.isArray(answerValues[q.id])||answerValues[q.id].length)
  ))){
    toast("未回答の必須項目があります");
    return;
  }

  let html="";

  currentAnswerSurvey.groups.forEach(g=>{
    g.questions.forEach(q=>{
      const v=answerValues[q.id];
      html+=`
        <div style="margin-bottom:18px">
          <strong>${getSurveyQuestionNumber(currentAnswerSurvey,q.id)} ${escapeHtml(q.text)}</strong>
          <div class="notice notice-info" style="margin-top:7px">
            ${escapeHtml(Array.isArray(v)?v.join("、"):(v||"未回答"))}
          </div>
        </div>
      `;
    });
  });

  document.getElementById("answerConfirmContent").innerHTML=html;
  showPage("answer-confirm");
}

function backToAnswer(){
  renderAnswer();
  showPage("answer");
}

function submitAnswer(){
  openModal(
    "回答を送信しますか？",
    "送信後は回答を受け付けた状態になります。",
    ()=>{
      currentAnswerSurvey.answers++;
      const s=surveys.find(x=>x.id===currentAnswerSurvey.id);
      if(s) s.answers=currentAnswerSurvey.answers;

      document.getElementById("adminHeader").style.display="none";
      showPage("answer-complete");
    }
  );
}


/* ============================================================
   回答者画面から管理者画面へ戻らないための処理
============================================================ */

function leaveAnswerToAdmin(){
  // 意図的に管理者画面へ戻す導線を実装しない。
}


/* ============================================================
   初期化
============================================================ */

function init(){
  applyAllAutomaticEnds();
  renderSurveyList();

  // プレビューへの導線を作成
  const observer=new MutationObserver(()=>{
    const actions=document.querySelector(".edit-actions");
    if(actions && !document.getElementById("previewEditorButton")){
      const btn=document.createElement("button");
      btn.id="previewEditorButton";
      btn.className="btn";
      btn.textContent="プレビュー";
      btn.onclick=openPreview;
      actions.querySelector(".edit-actions-left").appendChild(btn);
    }
  });

  observer.observe(document.body,{childList:true,subtree:true});

  // デモ用：回答者URLをコンソールから呼び出せる
  window.openMockAnswer=startAnswer;
}

init();


/* ============================================================
   画面外から回答者画面を開いた場合の管理者ヘッダー制御
============================================================ */

const originalShowPage=showPage;

window.showPage=function(page){
  if(page==="answer" || page==="answer-confirm" || page==="answer-complete"){
    document.getElementById("adminHeader").style.display="none";
  }else{
    document.getElementById("adminHeader").style.display="";
  }

  originalShowPage(page);
};


/* ============================================================
   サンプル回答者URL相当
   ブラウザURL ?answer=s1 で回答画面を開ける
============================================================ */

(function(){
  const params=new URLSearchParams(location.search);
  const answer=params.get("answer");

  if(answer){
    setTimeout(()=>{
      startAnswer(answer);
    },100);
  }
})();
</script>

</body>
</html>
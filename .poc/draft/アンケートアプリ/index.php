<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム モック</title>

<!-- SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

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
  --card:#fff;
  --text:#0f172a;
  --muted:#64748b;
  --shadow:0 2px 10px rgba(15,23,42,.06);
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

.app-header{
  position:sticky;
  top:0;
  z-index:50;
  height:64px;
  background:#0f172a;
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:0 24px;
  box-shadow:0 2px 10px rgba(0,0,0,.15);
}

.logo{
  font-weight:800;
  font-size:17px;
  display:flex;
  align-items:center;
  gap:10px;
}

.logo-mark{
  width:32px;
  height:32px;
  border-radius:8px;
  background:#2563eb;
  display:grid;
  place-items:center;
  font-weight:900;
}

.nav{
  display:flex;
  gap:4px;
  align-items:center;
}

.nav button{
  border:0;
  color:#cbd5e1;
  background:transparent;
  padding:10px 14px;
  border-radius:7px;
}

.nav button:hover,
.nav button.active{
  color:#fff;
  background:#1e293b;
}

.container{
  width:min(1440px,calc(100% - 40px));
  margin:0 auto;
  padding:28px 0 60px;
}

.page{
  display:none;
}

.page.active{
  display:block;
}

.page-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:20px;
  margin-bottom:22px;
}

.breadcrumb{
  color:var(--muted);
  font-size:13px;
  margin-bottom:8px;
}

h1{
  margin:0;
  font-size:27px;
}

h2{
  margin:0;
  font-size:19px;
}

h3{
  margin:0;
  font-size:16px;
}

.actions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}

.btn{
  border:1px solid var(--border);
  background:#fff;
  color:#334155;
  padding:9px 14px;
  border-radius:7px;
  min-height:40px;
  font-weight:600;
}

.btn:hover{
  background:#f1f5f9;
}

.btn.primary{
  background:var(--primary);
  border-color:var(--primary);
  color:#fff;
}

.btn.primary:hover{
  background:var(--primary-dark);
}

.btn.success{
  background:var(--success);
  border-color:var(--success);
  color:#fff;
}

.btn.danger{
  color:var(--danger);
  border-color:#fecaca;
  background:#fff;
}

.btn.warning{
  color:#92400e;
  border-color:#fed7aa;
  background:#fff;
}

.btn.small{
  min-height:32px;
  padding:6px 9px;
  font-size:12px;
}

.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:12px;
  box-shadow:var(--shadow);
}

.card-body{
  padding:20px;
}

.toolbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  flex-wrap:wrap;
  margin-bottom:14px;
}

.filters{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}

.input,
.select,
.textarea{
  width:100%;
  border:1px solid #cbd5e1;
  border-radius:7px;
  padding:10px 12px;
  background:#fff;
  outline:none;
}

.input:focus,
.select:focus,
.textarea:focus{
  border-color:var(--primary);
  box-shadow:0 0 0 3px rgba(37,99,235,.1);
}

.search{
  width:300px;
}

.table-wrap{
  overflow-x:auto;
}

table{
  width:100%;
  border-collapse:collapse;
  min-width:1050px;
}

th,td{
  padding:14px 12px;
  border-bottom:1px solid var(--border);
  text-align:left;
  vertical-align:middle;
  font-size:13px;
}

th{
  background:#f8fafc;
  color:#475569;
  font-weight:700;
  white-space:nowrap;
}

tr:hover td{
  background:#fafcff;
}

.title-cell{
  min-width:230px;
}

.title-cell strong{
  display:block;
  font-size:14px;
  margin-bottom:4px;
}

.date{
  color:var(--muted);
  font-size:12px;
  line-height:1.7;
}

.badge{
  display:inline-flex;
  align-items:center;
  gap:4px;
  border-radius:999px;
  padding:4px 9px;
  font-size:11px;
  font-weight:700;
  white-space:nowrap;
}

.badge.green{background:#dcfce7;color:#166534}
.badge.yellow{background:#fef3c7;color:#92400e}
.badge.gray{background:#e2e8f0;color:#475569}
.badge.red{background:#fee2e2;color:#991b1b}
.badge.blue{background:#dbeafe;color:#1e40af}

.row-actions{
  display:flex;
  gap:5px;
  flex-wrap:wrap;
  min-width:330px;
}

.empty{
  padding:70px 20px;
  text-align:center;
  color:var(--muted);
}

.empty-icon{
  font-size:40px;
  margin-bottom:10px;
}

/* Editor */
.editor-top{
  display:flex;
  gap:10px;
  align-items:center;
  margin-bottom:20px;
}

.title-editor{
  flex:1;
  font-size:22px;
  font-weight:700;
  border:0;
  border-bottom:2px solid #cbd5e1;
  background:transparent;
  padding:10px 2px;
  outline:none;
}

.title-editor:focus{
  border-color:var(--primary);
}

.group{
  margin-bottom:18px;
  overflow:hidden;
}

.group-head{
  background:#f1f5f9;
  padding:12px 15px;
  display:flex;
  align-items:center;
  gap:10px;
  border-bottom:1px solid var(--border);
}

.drag-handle{
  cursor:grab;
  color:#64748b;
  font-size:20px;
  user-select:none;
}

.group-title{
  flex:1;
  border:0;
  background:transparent;
  font-weight:700;
  outline:none;
  padding:6px;
}

.question-list{
  padding:10px;
  min-height:30px;
}

.question{
  border:1px solid var(--border);
  border-radius:9px;
  margin:9px 0;
  background:#fff;
  padding:15px;
  transition:.15s;
}

.question.sortable-ghost,
.group.sortable-ghost{
  opacity:.35;
  background:#dbeafe;
}

.question-top{
  display:flex;
  gap:10px;
  align-items:flex-start;
}

.question-number{
  color:var(--primary);
  font-weight:800;
  min-width:38px;
}

.question-content{
  flex:1;
}

.question-text{
  font-weight:700;
  border:0;
  width:100%;
  outline:none;
  padding:5px;
  border-bottom:1px solid transparent;
}

.question-text:focus{
  border-color:#cbd5e1;
}

.question-controls{
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
  margin-top:12px;
}

.option-list{
  margin-top:10px;
  padding-left:48px;
}

.option-row{
  display:flex;
  gap:7px;
  align-items:center;
  margin:6px 0;
}

.option-row input{
  flex:1;
}

.question-footer{
  margin-top:12px;
  padding-top:10px;
  border-top:1px solid #f1f5f9;
  display:flex;
  justify-content:space-between;
  align-items:center;
}

.toggle{
  display:inline-flex;
  align-items:center;
  gap:7px;
  cursor:pointer;
  font-size:13px;
}

.toggle input{
  width:38px;
  height:20px;
  accent-color:var(--primary);
}

/* Preview */
.preview-device{
  margin:0 auto;
  background:#fff;
  border:1px solid #cbd5e1;
  border-radius:15px;
  box-shadow:0 10px 35px rgba(0,0,0,.12);
  padding:20px;
  transition:.25s;
}

.preview-device.pc{
  width:min(850px,100%);
}

.preview-device.mobile{
  width:390px;
  max-width:100%;
}

.preview-question{
  margin:20px 0;
}

.preview-question label{
  display:block;
  font-weight:700;
  margin-bottom:10px;
}

.preview-option{
  padding:9px 0;
}

/* Modal */
.modal-overlay{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.55);
  display:none;
  align-items:center;
  justify-content:center;
  padding:20px;
  z-index:100;
}

.modal-overlay.show{
  display:flex;
}

.modal{
  background:#fff;
  border-radius:14px;
  width:min(800px,100%);
  max-height:90vh;
  overflow:auto;
  box-shadow:0 20px 60px rgba(0,0,0,.25);
}

.modal.large{
  width:min(1100px,100%);
}

.modal-head{
  position:sticky;
  top:0;
  z-index:2;
  background:#fff;
  border-bottom:1px solid var(--border);
  padding:17px 20px;
  display:flex;
  justify-content:space-between;
  align-items:center;
}

.modal-body{
  padding:20px;
}

.modal-foot{
  padding:15px 20px;
  border-top:1px solid var(--border);
  display:flex;
  justify-content:flex-end;
  gap:8px;
}

/* Mail */
.mail-grid{
  display:grid;
  grid-template-columns:390px 1fr;
  gap:18px;
}

.form-group{
  margin-bottom:15px;
}

.form-group label{
  display:block;
  font-weight:700;
  font-size:13px;
  margin-bottom:7px;
}

.mail-preview{
  background:#f8fafc;
  border:1px dashed #cbd5e1;
  border-radius:10px;
  padding:20px;
  min-height:240px;
  white-space:pre-wrap;
  line-height:1.8;
}

.alert{
  padding:13px 15px;
  border-radius:9px;
  margin-bottom:16px;
  font-size:13px;
}

.alert.warning{
  background:#fffbeb;
  border:1px solid #fde68a;
  color:#92400e;
}

.customer{
  min-width:210px;
}

.customer strong{
  display:block;
}

.customer small{
  display:block;
  color:var(--muted);
  margin-top:2px;
}

.status-dot{
  display:inline-block;
  width:7px;
  height:7px;
  border-radius:50%;
  margin-right:4px;
}

/* Analytics */
.summary{
  display:grid;
  grid-template-columns:repeat(5,1fr);
  gap:12px;
  margin-bottom:18px;
}

.summary-card{
  padding:18px;
}

.summary-label{
  font-size:12px;
  color:var(--muted);
  margin-bottom:8px;
}

.summary-value{
  font-size:26px;
  font-weight:800;
}

.summary-sub{
  font-size:11px;
  color:var(--muted);
  margin-top:4px;
}

.question-filter{
  padding:16px;
  margin-bottom:18px;
}

.filter-head{
  display:flex;
  justify-content:space-between;
  gap:10px;
  margin-bottom:12px;
}

.checkbox-grid{
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:8px;
}

.checkbox-item{
  border:1px solid var(--border);
  border-radius:8px;
  padding:9px;
  display:flex;
  gap:8px;
  align-items:center;
  cursor:pointer;
}

.chart-card{
  margin-bottom:16px;
  overflow:hidden;
}

.chart-head{
  padding:16px 18px;
  border-bottom:1px solid var(--border);
  display:flex;
  justify-content:space-between;
  gap:10px;
}

.chart-body{
  padding:18px;
}

.bar-row{
  display:grid;
  grid-template-columns:180px 1fr 100px;
  gap:10px;
  align-items:center;
  margin:13px 0;
}

.bar{
  height:20px;
  background:#e2e8f0;
  border-radius:999px;
  overflow:hidden;
}

.bar > span{
  display:block;
  height:100%;
  background:linear-gradient(90deg,#2563eb,#60a5fa);
  border-radius:999px;
  transition:.5s;
}

.other-box{
  margin-top:15px;
  border:1px solid #fde68a;
  background:#fffbeb;
  border-radius:9px;
  overflow:hidden;
}

.other-head{
  padding:10px 13px;
  display:flex;
  justify-content:space-between;
  cursor:pointer;
}

.other-list{
  display:none;
  padding:0 13px 13px;
}

.other-list.open{
  display:block;
}

.answer-note{
  border-top:1px solid #f3f4f6;
  padding:10px 0;
}

.answer-note small{
  color:var(--muted);
}

.timeline{
  max-height:300px;
  overflow:auto;
}

.timeline-item{
  display:grid;
  grid-template-columns:12px 1fr;
  gap:10px;
  margin-bottom:15px;
}

.timeline-dot{
  width:10px;
  height:10px;
  margin-top:6px;
  border-radius:50%;
  background:var(--primary);
}

.timeline-content{
  border-left:1px solid #dbeafe;
  padding-left:12px;
}

.answer-text{
  background:#f8fafc;
  padding:10px;
  border-radius:7px;
  margin-top:6px;
}

/* Kintone */
.settings-section{
  margin-bottom:18px;
}

.settings-title{
  padding:15px 18px;
  border-bottom:1px solid var(--border);
  font-weight:800;
}

.settings-body{
  padding:20px;
}

.form-grid{
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:15px;
}

.mapping-table td:first-child{
  width:220px;
  font-weight:700;
}

.multi-select{
  min-height:95px;
}

.sync-box{
  background:#f8fafc;
  padding:15px;
  border-radius:9px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:15px;
}

/* Toast */
.toast{
  position:fixed;
  right:20px;
  bottom:20px;
  background:#0f172a;
  color:#fff;
  padding:13px 18px;
  border-radius:9px;
  box-shadow:0 10px 30px rgba(0,0,0,.2);
  opacity:0;
  transform:translateY(20px);
  pointer-events:none;
  transition:.25s;
  z-index:200;
}

.toast.show{
  opacity:1;
  transform:translateY(0);
}

/* Responsive */
@media(max-width:1100px){
  .summary{grid-template-columns:repeat(3,1fr)}
  .mail-grid{grid-template-columns:1fr}
}

@media(max-width:760px){
  .app-header{
    height:auto;
    min-height:64px;
    padding:10px 12px;
    flex-wrap:wrap;
  }

  .nav{
    width:100%;
    overflow-x:auto;
  }

  .nav button{
    white-space:nowrap;
  }

  .container{
    width:calc(100% - 20px);
    padding-top:18px;
  }

  .page-head{
    flex-direction:column;
  }

  .actions{
    width:100%;
  }

  .actions .btn{
    flex:1;
  }

  .summary{
    grid-template-columns:repeat(2,1fr);
  }

  .form-grid{
    grid-template-columns:1fr;
  }

  .checkbox-grid{
    grid-template-columns:1fr;
  }

  .bar-row{
    grid-template-columns:100px 1fr 70px;
  }

  .search{
    width:100%;
  }

  .filters{
    width:100%;
  }

  .filters > *{
    flex:1;
  }
}

@media(max-width:480px){
  .summary{
    grid-template-columns:1fr;
  }

  h1{
    font-size:22px;
  }
}
</style>
</head>

<body>

<header class="app-header">
  <div class="logo">
    <div class="logo-mark">A</div>
    アンケート管理システム
  </div>

  <nav class="nav">
    <button class="active" onclick="showPage('list',this)">アンケート一覧</button>
    <button onclick="showPage('kintone',this)">キントーン連携設定</button>
    <button onclick="logout()">ログアウト</button>
  </nav>
</header>

<main class="container">

<!-- =========================================================
     ① アンケート一覧
========================================================= -->
<section id="page-list" class="page active">

  <div class="page-head">
    <div>
      <div class="breadcrumb">ホーム</div>
      <h1>アンケート一覧</h1>
    </div>

    <div class="actions">
      <button class="btn primary" onclick="newSurvey()">
        ＋ 新規アンケート作成
      </button>
    </div>
  </div>

  <div class="card">
    <div class="card-body">

      <div class="toolbar">
        <div class="filters">
          <input
            id="surveySearch"
            class="input search"
            placeholder="アンケートタイトルを検索..."
            onkeydown="if(event.key==='Enter')renderSurveys()"
            oninput="renderSurveys()">

          <select id="statusFilter" class="select" onchange="renderSurveys()">
            <option value="all">すべて</option>
            <option value="published">公開中</option>
            <option value="draft">下書き</option>
            <option value="ended">終了</option>
          </select>

          <select id="sortFilter" class="select" onchange="renderSurveys()">
            <option value="updatedDesc">更新日：新しい順</option>
            <option value="updatedAsc">更新日：古い順</option>
            <option value="answersDesc">回答数：多い順</option>
            <option value="answersAsc">回答数：少ない順</option>
            <option value="startDesc">期間開始日：新しい順</option>
            <option value="startAsc">期間開始日：古い順</option>
          </select>
        </div>

        <span id="surveyCount" style="color:#64748b;font-size:13px"></span>
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
     ② アンケート作成・編集
========================================================= -->
<section id="page-editor" class="page">

  <div class="breadcrumb">ホーム ＞ アンケート一覧 ＞ アンケート作成・編集</div>

  <div class="page-head">
    <div style="flex:1">
      <h1>アンケート作成</h1>
    </div>

    <div class="actions">
      <button class="btn" onclick="openPreview()">プレビュー</button>
      <button class="btn" onclick="cancelEditor()">キャンセル</button>
      <button class="btn primary" onclick="saveSurvey()">保存して一覧へ戻る</button>
    </div>
  </div>

  <div class="card" style="margin-bottom:18px">
    <div class="card-body">
      <label style="font-weight:700;font-size:13px">アンケートタイトル</label>
      <input id="editorTitle"
             class="title-editor"
             value="【顧客満足度調査】サービスに関するアンケート"
             oninput="refreshPreviewIfOpen()">
    </div>
  </div>

  <div id="editorGroups"></div>

  <button class="btn primary" onclick="addGroup()" style="margin-bottom:20px">
    ＋ グループを追加
  </button>

</section>


<!-- =========================================================
     ③ 顧客選択・メール送信
========================================================= -->
<section id="page-mail" class="page">

  <div class="breadcrumb">
    ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
  </div>

  <div class="page-head">
    <div>
      <h1 id="mailSurveyTitle">顧客選択・メール送信</h1>
      <div style="color:#64748b;margin-top:5px;font-size:13px">
        対象アンケート：顧客満足度調査
      </div>
    </div>

    <div class="actions">
      <button class="btn warning" onclick="selectUnanswered()">
        未回答者を選択
      </button>
      <button class="btn primary" onclick="sendMail()">
        選択者へ一括送信
      </button>
    </div>
  </div>

  <div class="alert warning">
    <strong>⚠ kintone未登録の回答者があります</strong><br>
    Web公開フォーム経由で回答した顧客のうち、2名がkintoneに登録されていません。
  </div>

  <div class="mail-grid">

    <div class="card">
      <div class="card-body">
        <h3 style="margin-bottom:15px">送信メールテンプレート</h3>

        <div class="form-group">
          <label>メール件名</label>
          <input id="mailSubject"
                 class="input"
                 value="【顧客満足度調査】アンケートご協力のお願い"
                 oninput="updateMailPreview()">
        </div>

        <div class="form-group">
          <label>メール本文</label>
          <textarea id="mailBody"
                    class="textarea"
                    rows="12"
                    oninput="updateMailPreview()"> {顧客名} 様

いつもお世話になっております。

このたび、サービス向上を目的としてアンケートを実施しております。

以下のURLよりご回答をお願いいたします。

{アンケートURL}

ご協力のほど、よろしくお願いいたします。</textarea>
        </div>

        <div style="font-size:12px;color:#64748b">
          使用可能な変数：
          <code>{顧客名}</code>
          <code>{アンケートURL}</code>
        </div>

        <hr style="border:0;border-top:1px solid #e2e8f0;margin:18px 0">

        <h3 style="margin-bottom:10px">メールプレビュー</h3>
        <div id="mailPreview" class="mail-preview"></div>
      </div>
    </div>


    <div class="card">
      <div class="card-body">

        <div class="toolbar">
          <div class="filters">
            <input
              id="customerSearch"
              class="input search"
              placeholder="会社名・氏名・メールアドレス..."
              oninput="renderCustomers()">

            <select id="customerStatus" class="select" onchange="renderCustomers()">
              <option value="all">すべて</option>
              <option value="unsent">未送信</option>
              <option value="unanswered">送信済み・未回答</option>
              <option value="answered">回答済み</option>
              <option value="web">Web直接回答</option>
            </select>
          </div>

          <button class="btn small" onclick="toggleAllCustomers()">
            全選択 / 解除
          </button>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>選択</th>
                <th>会社名 / 氏名等</th>
                <th>送信履歴</th>
                <th>回答状況</th>
                <th>kintone対応</th>
              </tr>
            </thead>
            <tbody id="customerTable"></tbody>
          </table>
        </div>

      </div>
    </div>
  </div>


  <div class="card" style="margin-top:18px">
    <div class="card-body">
      <h3 style="margin-bottom:14px">一括送信ログ・履歴</h3>

      <div class="table-wrap">
        <table style="min-width:700px">
          <thead>
            <tr>
              <th>日時</th>
              <th>送信種別</th>
              <th>件数</th>
              <th>件名</th>
              <th>実行者</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="mailLogs"></tbody>
        </table>
      </div>
    </div>
  </div>

</section>


<!-- =========================================================
     ④ 集計・分析
========================================================= -->
<section id="page-analytics" class="page">

  <div class="page-head">
    <div>
      <div class="breadcrumb">ホーム ＞ アンケート一覧 ＞ 回答集計・分析</div>
      <h1>【顧客満足度調査】回答集計・分析</h1>
    </div>

    <div class="actions">
      <button class="btn" onclick="downloadCSV()">CSVダウンロード</button>
      <button class="btn" onclick="exportPDF()">PDF出力</button>
    </div>
  </div>

  <div class="summary">

    <div class="card summary-card">
      <div class="summary-label">送信対象者数</div>
      <div class="summary-value">200</div>
      <div class="summary-sub">人</div>
    </div>

    <div class="card summary-card">
      <div class="summary-label">回答数</div>
      <div class="summary-value">128</div>
      <div class="summary-sub">件</div>
    </div>

    <div class="card summary-card">
      <div class="summary-label">未登録顧客からの回答</div>
      <div class="summary-value">8</div>
      <div class="summary-sub">件</div>
    </div>

    <div class="card summary-card">
      <div class="summary-label">未回答数</div>
      <div class="summary-value">80</div>
      <div class="summary-sub">人</div>
    </div>

    <div class="card summary-card">
      <div class="summary-label">回答率</div>
      <div class="summary-value">60.0%</div>
      <div class="summary-sub">送信対象者ベース</div>
    </div>

  </div>


  <div class="card question-filter">
    <div class="filter-head">
      <h3>集計対象の設問</h3>

      <div>
        <button class="btn small" onclick="selectAllQuestions(true)">
          すべて選択
        </button>
        <button class="btn small" onclick="selectAllQuestions(false)">
          すべて解除
        </button>
      </div>
    </div>

    <div class="checkbox-grid">

      <label class="checkbox-item">
        <input type="checkbox" checked onchange="renderAnalytics()">
        Q1. サービス全体の満足度
        <span class="badge blue">単一選択</span>
      </label>

      <label class="checkbox-item">
        <input type="checkbox" checked onchange="renderAnalytics()">
        Q2. 良かった点
        <span class="badge blue">複数選択</span>
      </label>

      <label class="checkbox-item">
        <input type="checkbox" checked onchange="renderAnalytics()">
        Q3. 改善してほしい点
        <span class="badge gray">テキスト</span>
      </label>

      <label class="checkbox-item">
        <input type="checkbox" checked onchange="renderAnalytics()">
        Q4. 今後も利用したいですか？
        <span class="badge blue">単一選択</span>
      </label>

    </div>
  </div>


  <div id="analyticsContent"></div>


  <div class="card" style="margin-top:18px">
    <div class="card-body">

      <div class="toolbar">
        <h3>個別回答一覧</h3>

        <input
          id="answerSearch"
          class="input search"
          placeholder="会社名・氏名で検索..."
          oninput="renderAnswerTable()">
      </div>

      <div class="table-wrap">
        <table style="min-width:800px">
          <thead>
            <tr>
              <th>会社名 / 氏名</th>
              <th>回答日時</th>
              <th>回答概要</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody id="answerTable"></tbody>
        </table>
      </div>

    </div>
  </div>

</section>


<!-- =========================================================
     ⑤ kintone連携設定
========================================================= -->
<section id="page-kintone" class="page">

  <div class="page-head">
    <div>
      <div class="breadcrumb">ホーム ＞ システム設定 ＞ kintone連携設定</div>
      <h1>kintone連携設定</h1>
    </div>

    <div class="actions">
      <button class="btn" onclick="testKintone()">接続テスト</button>
      <button class="btn primary" onclick="saveKintone()">設定を保存する</button>
    </div>
  </div>


  <div class="card settings-section">
    <div class="settings-title">
      ① アカウント認証・アプリ接続
    </div>

    <div class="settings-body">

      <div class="form-grid">

        <div class="form-group">
          <label>サブドメイン</label>
          <div style="display:flex;align-items:center;gap:5px">
            <span>https://</span>
            <input id="kSubdomain" class="input" value="example">
            <span>.cybozu.com</span>
          </div>
        </div>

        <div class="form-group">
          <label>顧客管理アプリID</label>
          <div style="display:flex;gap:8px">
            <input id="kAppId" class="input" value="123">
            <button class="btn" onclick="fetchKintoneFields()">
              項目一覧を再取得
            </button>
          </div>
        </div>

        <div class="form-group">
          <label>ログイン名</label>
          <input id="kLogin" class="input" value="admin">
        </div>

        <div class="form-group">
          <label>パスワード</label>
          <input id="kPassword" class="input" type="password" value="password">
        </div>

      </div>

      <label class="toggle">
        <input id="sslSkip" type="checkbox">
        SSL証明書検証をスキップする
        <span style="color:#64748b;font-size:12px">
          ※開発・特定ネットワーク用
        </span>
      </label>

      <div id="kintoneFetchStatus"
           style="margin-top:14px;color:#64748b;font-size:13px">
        項目一覧：未取得
      </div>

    </div>
  </div>


  <div class="card settings-section">
    <div class="settings-title">
      ② 顧客情報フィールドマッピング
    </div>

    <div class="settings-body">

      <div class="alert" style="background:#eff6ff;color:#1e40af">
        kintoneの日本語フィールド名を選択してください。
        内部的には対応するフィールドコードを保存します。
      </div>

      <div class="table-wrap">
        <table class="mapping-table" style="min-width:700px">
          <thead>
            <tr>
              <th>システム項目</th>
              <th>用途</th>
              <th>kintone項目</th>
            </tr>
          </thead>

          <tbody>

            <tr>
              <td>会社名 (Company)</td>
              <td>顧客の所属企業・団体名</td>
              <td>
                <select id="mapCompany" class="select">
                  <option>会社名</option>
                  <option>企業名</option>
                  <option>勤務先</option>
                </select>
              </td>
            </tr>

            <tr>
              <td>氏名 (Name)</td>
              <td>担当者氏名</td>
              <td>
                <select id="mapName" class="select">
                  <option>氏名</option>
                  <option>担当者名</option>
                  <option>名前</option>
                </select>
              </td>
            </tr>

            <tr>
              <td>メールアドレス (Email)</td>
              <td>送信・顧客照合キー</td>
              <td>
                <select id="mapEmail" class="select">
                  <option>メールアドレス</option>
                  <option>Email</option>
                  <option>連絡先メール</option>
                </select>
              </td>
            </tr>

            <tr>
              <td>部署名 (Department)</td>
              <td>部署・役職</td>
              <td>
                <select id="mapDepartment" class="select">
                  <option>部署名</option>
                  <option>部署</option>
                  <option>所属部署</option>
                </select>
              </td>
            </tr>

            <tr>
              <td>電話番号 (Phone)</td>
              <td>連絡先電話番号</td>
              <td>
                <select id="mapPhone" class="select">
                  <option>電話番号</option>
                  <option>電話</option>
                  <option>携帯電話</option>
                </select>
              </td>
            </tr>

            <tr>
              <td>
                住所 (Address)
                <div style="font-size:11px;color:#64748b;margin-top:3px">
                  複数選択可
                </div>
              </td>

              <td>郵便番号・所在地・送付先</td>

              <td>
                <select id="mapAddress"
                        class="select multi-select"
                        multiple>
                  <option selected>郵便番号</option>
                  <option selected>都道府県</option>
                  <option selected>市区町村</option>
                  <option>番地</option>
                  <option>建物名</option>
                </select>

                <div style="font-size:11px;color:#64748b;margin-top:5px">
                  Ctrl / ⌘ を押しながら複数選択
                </div>
              </td>
            </tr>

          </tbody>
        </table>
      </div>

    </div>
  </div>


  <div class="card settings-section">
    <div class="settings-title">
      ③ 手動同期
    </div>

    <div class="settings-body">

      <div class="sync-box">

        <div>
          <strong>データ同期</strong>
          <div style="color:#64748b;font-size:12px;margin-top:4px">
            自動同期は行わず、管理者が必要なタイミングで実行します。
          </div>
        </div>

        <button class="btn primary" onclick="manualSync()">
          今すぐ同期する
        </button>

      </div>

      <div id="syncResult"
           style="margin-top:12px;font-size:13px;color:#64748b">
        最終同期：未実行
      </div>

    </div>
  </div>

</section>

</main>


<!-- =========================================================
     共通モーダル
========================================================= -->
<div id="modalOverlay" class="modal-overlay" onclick="closeModalOutside(event)">
  <div id="modal" class="modal">
    <div class="modal-head">
      <h2 id="modalTitle">確認</h2>
      <button class="btn small" onclick="closeModal()">✕</button>
    </div>

    <div id="modalBody" class="modal-body"></div>
    <div id="modalFoot" class="modal-foot"></div>
  </div>
</div>

<div id="toast" class="toast"></div>


<script>
/* =========================================================
   データ
========================================================= */

let surveys = [
  {
    id:1,
    title:"【顧客満足度調査】サービスに関するアンケート",
    status:"published",
    created:"2026/07/25",
    updated:"2026/08/10",
    start:"2026/08/01",
    end:"2026/08/31",
    answers:128
  },
  {
    id:2,
    title:"新サービス利用意向調査",
    status:"draft",
    created:"2026/08/12",
    updated:"2026/08/15",
    start:"",
    end:"",
    answers:0
  },
  {
    id:3,
    title:"2026年度 サービス改善アンケート",
    status:"ended",
    created:"2026/06/01",
    updated:"2026/07/01",
    start:"2026/06/05",
    end:"2026/06/30",
    answers:256
  }
];

let groups = [
  {
    id:1,
    title:"サービスについて",
    questions:[
      {
        id:101,
        type:"single",
        text:"サービス全体の満足度を教えてください。",
        required:true,
        options:["とても満足","満足","普通","やや不満","不満"]
      },
      {
        id:102,
        type:"multi",
        text:"良かった点を教えてください。",
        required:false,
        options:["価格","機能","サポート","使いやすさ","その他"]
      }
    ]
  },
  {
    id:2,
    title:"ご意見・今後について",
    questions:[
      {
        id:103,
        type:"text",
        text:"改善してほしい点があれば教えてください。",
        required:false,
        options:[]
      },
      {
        id:104,
        type:"single",
        text:"今後もこのサービスを利用したいですか？",
        required:true,
        options:["ぜひ利用したい","利用したい","どちらともいえない","利用したくない"]
      }
    ]
  }
];

let customers = [
  {
    id:1,
    company:"株式会社サンプル商事",
    name:"山田 太郎",
    email:"taro@example.co.jp",
    phone:"03-1234-5678",
    address:"東京都港区",
    sent:true,
    sentAt:"2026/08/05 10:21",
    count:1,
    answered:true,
    web:false,
    kintone:true
  },
  {
    id:2,
    company:"株式会社ABC",
    name:"佐藤 花子",
    email:"hanako@abc.co.jp",
    phone:"03-9876-5432",
    address:"東京都千代田区",
    sent:true,
    sentAt:"2026/08/05 10:25",
    count:1,
    answered:false,
    web:false,
    kintone:true
  },
  {
    id:3,
    company:"株式会社XYZ",
    name:"鈴木 一郎",
    email:"ichiro@xyz.co.jp",
    phone:"06-1234-5678",
    address:"大阪府大阪市",
    sent:false,
    sentAt:"",
    count:0,
    answered:false,
    web:false,
    kintone:true
  },
  {
    id:4,
    company:"個人回答",
    name:"田中 次郎",
    email:"jiro@example.com",
    phone:"090-1111-2222",
    address:"東京都渋谷区",
    sent:false,
    sentAt:"2026/08/09 15:12",
    count:0,
    answered:true,
    web:true,
    kintone:false
  },
  {
    id:5,
    company:"株式会社DEF",
    name:"高橋 美咲",
    email:"misaki@def.co.jp",
    phone:"03-5555-6666",
    address:"東京都新宿区",
    sent:true,
    sentAt:"2026/08/06 09:40",
    count:2,
    answered:false,
    web:false,
    kintone:true
  }
];

let mailLogs = [
  {
    date:"2026/08/06 09:40",
    type:"リマインド送信",
    count:32,
    subject:"【顧客満足度調査】ご回答のお願い（再送）",
    user:"管理者"
  },
  {
    date:"2026/08/05 10:21",
    type:"初回一括送信",
    count:168,
    subject:"【顧客満足度調査】アンケートご協力のお願い",
    user:"管理者"
  }
];

let answers = [
  {
    id:1,
    company:"株式会社サンプル商事",
    name:"山田 太郎",
    date:"2026/08/08 13:24",
    other:"サポートの対応が非常に丁寧でした。",
    all:[
      ["Q1. サービス全体の満足度","とても満足"],
      ["Q2. 良かった点","価格、サポート"],
      ["Q3. 改善してほしい点","管理画面をさらに使いやすくしてほしい"],
      ["Q4. 今後も利用したいですか？","ぜひ利用したい"]
    ]
  },
  {
    id:2,
    company:"株式会社ABC",
    name:"佐藤 花子",
    date:"2026/08/08 15:02",
    other:"スマートフォンから回答しやすかったです。",
    all:[
      ["Q1. サービス全体の満足度","満足"],
      ["Q2. 良かった点","使いやすさ、機能"],
      ["Q3. 改善してほしい点","検索機能を増やしてほしい"],
      ["Q4. 今後も利用したいですか？","利用したい"]
    ]
  },
  {
    id:3,
    company:"個人回答",
    name:"田中 次郎",
    date:"2026/08/09 15:12",
    other:"公開フォームから回答しました。",
    all:[
      ["Q1. サービス全体の満足度","普通"],
      ["Q2. 良かった点","価格、その他"],
      ["Q3. 改善してほしい点","特になし"],
      ["Q4. 今後も利用したいですか？","どちらともいえない"]
    ]
  }
];


/* =========================================================
   共通
========================================================= */

function showPage(page, navButton=null){
  document.querySelectorAll(".page").forEach(p=>p.classList.remove("active"));

  const el=document.getElementById("page-"+page);
  if(el) el.classList.add("active");

  document.querySelectorAll(".nav button").forEach(b=>b.classList.remove("active"));
  if(navButton) navButton.classList.add("active");

  if(page==="list") renderSurveys();
  if(page==="mail"){
    renderCustomers();
    renderMailLogs();
    updateMailPreview();
  }
  if(page==="analytics"){
    renderAnalytics();
    renderAnswerTable();
  }
}

function toast(message){
  const el=document.getElementById("toast");
  el.textContent=message;
  el.classList.add("show");

  setTimeout(()=>{
    el.classList.remove("show");
  },2200);
}

function openModal(title,body,foot=""){
  document.getElementById("modalTitle").textContent=title;
  document.getElementById("modalBody").innerHTML=body;
  document.getElementById("modalFoot").innerHTML=foot;
  document.getElementById("modalOverlay").classList.add("show");
}

function closeModal(){
  document.getElementById("modalOverlay").classList.remove("show");
}

function closeModalOutside(e){
  if(e.target.id==="modalOverlay") closeModal();
}

function confirmAction(title,message,callback){
  openModal(
    title,
    `<p style="line-height:1.8">${message}</p>`,
    `
      <button class="btn" onclick="closeModal()">キャンセル</button>
      <button class="btn danger" id="confirmOk">実行する</button>
    `
  );

  document.getElementById("confirmOk").onclick=()=>{
    closeModal();
    callback();
  };
}

function logout(){
  confirmAction(
    "ログアウト",
    "ログアウトしますか？",
    ()=>toast("ログアウトしました")
  );
}


/* =========================================================
   ① 一覧
========================================================= */

function statusBadge(status){
  if(status==="published") return `<span class="badge green">● 公開中</span>`;
  if(status==="draft") return `<span class="badge yellow">● 下書き</span>`;
  return `<span class="badge gray">● 終了</span>`;
}

function renderSurveys(){

  const keyword=document.getElementById("surveySearch").value.toLowerCase();
  const status=document.getElementById("statusFilter").value;
  const sort=document.getElementById("sortFilter").value;

  let list=surveys.filter(s=>{
    const keywordMatch=s.title.toLowerCase().includes(keyword);
    const statusMatch=status==="all" || s.status===status;
    return keywordMatch && statusMatch;
  });

  list.sort((a,b)=>{
    if(sort==="updatedDesc") return b.updated.localeCompare(a.updated);
    if(sort==="updatedAsc") return a.updated.localeCompare(b.updated);
    if(sort==="answersDesc") return b.answers-a.answers;
    if(sort==="answersAsc") return a.answers-b.answers;
    if(sort==="startDesc") return (b.start||"").localeCompare(a.start||"");
    if(sort==="startAsc") return (a.start||"").localeCompare(b.start||"");
  });

  document.getElementById("surveyCount").textContent=
    `${list.length}件`;

  const tbody=document.getElementById("surveyTable");

  if(!list.length){
    tbody.innerHTML=`
      <tr>
        <td colspan="6">
          <div class="empty">
            <div class="empty-icon">🔎</div>
            該当するアンケートがありません
          </div>
        </td>
      </tr>`;
    return;
  }

  tbody.innerHTML=list.map(s=>{

    let actions="";

    if(s.status==="published"){
      actions=`
        <button class="btn small" onclick="editSurvey(${s.id})">確認・編集</button>
        <button class="btn small" onclick="openAnalytics(${s.id})">集計</button>
        <button class="btn small primary" onclick="openMail(${s.id})">送信</button>
        <button class="btn small warning" onclick="stopSurvey(${s.id})">停止</button>
        <button class="btn small" onclick="duplicateSurvey(${s.id})">複製</button>
      `;
    }

    if(s.status==="draft"){
      actions=`
        <button class="btn small" onclick="editSurvey(${s.id})">確認・編集</button>
        <button class="btn small danger" onclick="deleteSurvey(${s.id})">削除</button>
        <button class="btn small" onclick="duplicateSurvey(${s.id})">複製</button>
      `;
    }

    if(s.status==="ended"){
      actions=`
        <button class="btn small" onclick="editSurvey(${s.id})">確認・編集</button>
        <button class="btn small" onclick="openAnalytics(${s.id})">集計</button>
        <button class="btn small" onclick="duplicateSurvey(${s.id})">複製</button>
      `;
    }

    return `
      <tr>
        <td>
          <div class="date">
            ${s.created}<br>
            <strong>更新: ${s.updated}</strong>
          </div>
        </td>

        <td class="title-cell">
          <strong>${escapeHtml(s.title)}</strong>
          <span style="color:#64748b;font-size:12px">
            survey_id: ${s.id}
          </span>
        </td>

        <td>
          ${s.start
            ? `${s.start}<br>〜 ${s.end}`
            : `<span style="color:#94a3b8">未設定</span>`
          }
        </td>

        <td>${statusBadge(s.status)}</td>

        <td>
          <strong style="font-size:16px">${s.answers}</strong> 件
        </td>

        <td>
          <div class="row-actions">${actions}</div>
        </td>
      </tr>
    `;
  }).join("");
}

function newSurvey(){
  groups=[
    {
      id:Date.now(),
      title:"新しいグループ",
      questions:[
        {
          id:Date.now()+1,
          type:"single",
          text:"質問文を入力してください。",
          required:true,
          options:["選択肢1","選択肢2"]
        }
      ]
    }
  ];

  document.getElementById("editorTitle").value="";
  renderEditor();
  showPage("editor");
}

function editSurvey(id){
  const survey=surveys.find(s=>s.id===id);

  if(survey.status==="ended"){
    toast("終了したアンケートを閲覧します");
  }

  document.getElementById("editorTitle").value=survey.title;

  renderEditor();
  showPage("editor");
}

function duplicateSurvey(id){
  const original=surveys.find(s=>s.id===id);

  const duplicate={
    ...original,
    id:Date.now(),
    title:original.title+"（コピー）",
    status:"draft",
    created:"2026/08/24",
    updated:"2026/08/24",
    answers:0
  };

  surveys.unshift(duplicate);
  renderSurveys();
  toast("アンケートを複製しました（下書きとして追加）");
}

function stopSurvey(id){
  confirmAction(
    "アンケートを停止",
    "このアンケートを停止します。停止後は回答受付ができなくなります。よろしいですか？",
    ()=>{
      const survey=surveys.find(s=>s.id===id);
      survey.status="ended";
      survey.updated="2026/08/24";
      renderSurveys();
      toast("アンケートを停止しました");
    }
  );
}

function deleteSurvey(id){
  confirmAction(
    "アンケートを削除",
    "このアンケートを削除しますか？削除後は一覧から表示されなくなります。",
    ()=>{
      surveys=surveys.filter(s=>s.id!==id);
      renderSurveys();
      toast("アンケートを削除しました");
    }
  );
}


/* =========================================================
   ② エディター
========================================================= */

function renderEditor(){

  const root=document.getElementById("editorGroups");

  root.innerHTML=groups.map((group,gIndex)=>`

    <div class="card group"
         data-group-id="${group.id}">

      <div class="group-head">

        <span class="drag-handle">⠿</span>

        <input
          class="group-title"
          value="${escapeAttr(group.title)}"
          onchange="groups[${gIndex}].title=this.value;refreshPreviewIfOpen()">

        <span class="badge gray">
          ${group.questions.length}問
        </span>

        <button class="btn small" onclick="addQuestion(${gIndex})">
          ＋ 質問
        </button>

        <button class="btn small danger"
                onclick="deleteGroup(${gIndex})">
          グループ削除
        </button>

      </div>

      <div
        class="question-list"
        data-group-index="${gIndex}">

        ${group.questions.map((q,qIndex)=>questionHtml(q,gIndex,qIndex)).join("")}

      </div>

    </div>
  `).join("");

  initializeSortable();
  renumberQuestions();
}

function questionHtml(q,gIndex,qIndex){

  let options="";

  if(q.type==="single" || q.type==="multi"){
    options=`
      <div class="option-list">
        ${q.options.map((option,i)=>`
          <div class="option-row">
            <span style="color:#64748b">↳</span>
            <input
              class="input"
              value="${escapeAttr(option)}"
              onchange="groups[${gIndex}].questions[${qIndex}].options[${i}]=this.value;refreshPreviewIfOpen()">

            <button
              class="btn small danger"
              onclick="removeOption(${gIndex},${qIndex},${i})">
              ×
            </button>
          </div>
        `).join("")}

        <button class="btn small" onclick="addOption(${gIndex},${qIndex})">
          ＋ 選択肢を追加
        </button>
      </div>
    `;
  }

  return `
    <div class="question"
         data-question-id="${q.id}">

      <div class="question-top">

        <span class="drag-handle">⠿</span>

        <span class="question-number"></span>

        <div class="question-content">

          <input
            class="question-text"
            value="${escapeAttr(q.text)}"
            oninput="groups[${gIndex}].questions[${qIndex}].text=this.value;refreshPreviewIfOpen()">

          <div class="question-controls">

            <select
              class="select"
              style="width:180px"
              onchange="changeQuestionType(${gIndex},${qIndex},this.value)">

              <option value="single" ${q.type==="single"?"selected":""}>
                単一選択
              </option>

              <option value="multi" ${q.type==="multi"?"selected":""}>
                複数選択
              </option>

              <option value="text" ${q.type==="text"?"selected":""}>
                自由記述
              </option>

            </select>

            ${
              q.type==="single"
              ? `
                <select class="select"
                        style="width:210px"
                        onchange="refreshPreviewIfOpen()">
                  <option>分岐なし</option>
                  <option>「とても満足」→ Q2</option>
                  <option>「不満」→ Q3</option>
                </select>
              `
              :""
            }

          </div>

          ${options}

        </div>

      </div>

      <div class="question-footer">

        <label class="toggle">
          <input
            type="checkbox"
            ${q.required?"checked":""}
            onchange="groups[${gIndex}].questions[${qIndex}].required=this.checked;refreshPreviewIfOpen()">
          必須回答
        </label>

        <button class="btn small danger"
                onclick="deleteQuestion(${gIndex},${qIndex})">
          質問を削除
        </button>

      </div>

    </div>
  `;
}

function initializeSortable(){

  const groupContainer=document.getElementById("editorGroups");

  new Sortable(groupContainer,{
    animation:180,
    handle:".group-head .drag-handle",
    ghostClass:"sortable-ghost",
    onEnd:function(evt){
      if(evt.oldIndex===evt.newIndex)return;

      const moved=groups.splice(evt.oldIndex,1)[0];
      groups.splice(evt.newIndex,0,moved);

      renderEditor();
      toast("グループの順番を変更しました");
    }
  });

  document.querySelectorAll(".question-list").forEach((list,groupIndex)=>{

    new Sortable(list,{
      group:"questions",
      animation:180,
      handle:".question .drag-handle",
      ghostClass:"sortable-ghost",

      onEnd:function(evt){

        const fromGroup=Number(evt.from.dataset.groupIndex);
        const toGroup=Number(evt.to.dataset.groupIndex);

        const moved=groups[fromGroup].questions.splice(evt.oldIndex,1)[0];

        groups[toGroup].questions.splice(evt.newIndex,0,moved);

        renderEditor();

        toast(
          fromGroup===toGroup
            ? "質問の順番を変更しました"
            : "質問を別グループへ移動しました"
        );
      }
    });

  });
}

function renumberQuestions(){

  let number=1;

  document.querySelectorAll(".question-number").forEach(el=>{
    el.textContent="Q"+number+".";
    number++;
  });
}

function addGroup(){

  groups.push({
    id:Date.now(),
    title:"新しいグループ",
    questions:[]
  });

  renderEditor();
}

function deleteGroup(index){

  confirmAction(
    "グループ削除",
    `「${groups[index].title}」と含まれる質問をすべて削除しますか？`,
    ()=>{
      groups.splice(index,1);
      renderEditor();
    }
  );
}

function addQuestion(groupIndex){

  groups[groupIndex].questions.push({
    id:Date.now(),
    type:"single",
    text:"新しい質問",
    required:false,
    options:["選択肢1","選択肢2"]
  });

  renderEditor();
}

function deleteQuestion(groupIndex,questionIndex){

  groups[groupIndex].questions.splice(questionIndex,1);
  renderEditor();
}

function addOption(groupIndex,questionIndex){

  groups[groupIndex].questions[questionIndex].options.push("新しい選択肢");
  renderEditor();
}

function removeOption(groupIndex,questionIndex,optionIndex){

  groups[groupIndex].questions[questionIndex].options.splice(optionIndex,1);
  renderEditor();
}

function changeQuestionType(g,q,type){

  groups[g].questions[q].type=type;

  if(type==="text"){
    groups[g].questions[q].options=[];
  }

  if((type==="single" || type==="multi") &&
     groups[g].questions[q].options.length===0){
    groups[g].questions[q].options=["選択肢1","選択肢2"];
  }

  renderEditor();
}

function saveSurvey(){

  const title=document.getElementById("editorTitle").value.trim();

  if(!title){
    toast("アンケートタイトルを入力してください");
    return;
  }

  toast("保存しています...");

  setTimeout(()=>{
    surveys.unshift({
      id:Date.now(),
      title,
      status:"draft",
      created:"2026/08/24",
      updated:"2026/08/24",
      start:"",
      end:"",
      answers:0
    });

    showPage("list");
    toast("アンケートを保存しました");
  },500);
}

function cancelEditor(){

  confirmAction(
    "変更を破棄",
    "保存していない変更を破棄して一覧へ戻りますか？",
    ()=>{
      showPage("list");
    }
  );
}


/* =========================================================
   Preview
========================================================= */

let previewOpen=false;

function openPreview(){

  previewOpen=true;

  const body=`
    <div style="display:flex;justify-content:center;gap:8px;margin-bottom:18px">
      <button class="btn small primary" onclick="setPreviewDevice('pc')">
        PC表示
      </button>
      <button class="btn small" onclick="setPreviewDevice('mobile')">
        スマートフォン表示
      </button>
    </div>

    <div id="previewDevice" class="preview-device pc">
      <div id="previewContent"></div>
    </div>
  `;

  openModal(
    "プレビュー",
    body,
    `<button class="btn" onclick="closePreview()">閉じる</button>`
  );

  renderPreview();
}

function closePreview(){
  previewOpen=false;
  closeModal();
}

function setPreviewDevice(device){

  const el=document.getElementById("previewDevice");

  if(!el)return;

  el.className="preview-device "+device;
}

function renderPreview(){

  const content=document.getElementById("previewContent");

  if(!content)return;

  const title=document.getElementById("editorTitle").value;

  content.innerHTML=`
    <div style="text-align:center;margin-bottom:30px">
      <div class="badge blue" style="margin-bottom:10px">アンケート</div>
      <h2>${escapeHtml(title || "アンケート")}</h2>
    </div>

    ${groups.map((g,gi)=>`

      <div style="margin-top:30px">
        <h3 style="padding-bottom:8px;border-bottom:2px solid #e2e8f0">
          ${escapeHtml(g.title)}
        </h3>

        ${g.questions.map((q,qi)=>`

          <div class="preview-question">

            <label>
              Q${getGlobalQuestionNumber(gi,qi)}.
              ${escapeHtml(q.text)}
              ${q.required
                ? `<span style="color:#dc2626">*</span>`
                : ""
              }
            </label>

            ${
              q.type==="text"
              ? `<textarea class="textarea"
                           rows="5"
                           placeholder="回答を入力してください"></textarea>`
              :
              q.options.map((o,i)=>`
                <div class="preview-option">
                  <label style="font-weight:400">
                    <input
                      type="${q.type==="single"?"radio":"checkbox"}"
                      name="preview-${q.id}">
                    ${escapeHtml(o)}
                  </label>
                </div>
              `).join("")
            }

          </div>

        `).join("")}
      </div>

    `).join("")}

    <button class="btn primary"
            style="width:100%;margin-top:20px"
            onclick="previewSubmit()">
      回答を送信する
    </button>
  `;
}

function getGlobalQuestionNumber(gIndex,qIndex){

  let count=1;

  for(let i=0;i<gIndex;i++){
    count+=groups[i].questions.length;
  }

  return count+qIndex;
}

function refreshPreviewIfOpen(){
  if(previewOpen) renderPreview();
}

function previewSubmit(){

  openModal(
    "プレビュー",
    `
      <div style="text-align:center;padding:20px">
        <div style="font-size:40px">👀</div>
        <h3 style="margin:12px 0">これはプレビューです</h3>
        <p style="color:#64748b">
          プレビュー画面からは実際の回答送信は行われません。
        </p>
      </div>
    `,
    `<button class="btn primary" onclick="closeModal()">OK</button>`
  );
}


/* =========================================================
   ③ メール
========================================================= */

function openMail(id){

  const survey=surveys.find(s=>s.id===id);

  document.getElementById("mailSurveyTitle").textContent=
    "顧客選択・メール送信";

  showPage("mail");
}

function renderCustomers(){

  const keyword=
    document.getElementById("customerSearch").value.toLowerCase();

  const status=
    document.getElementById("customerStatus").value;

  let list=customers.filter(c=>{

    const match=
      c.company.toLowerCase().includes(keyword) ||
      c.name.toLowerCase().includes(keyword) ||
      c.email.toLowerCase().includes(keyword);

    let statusMatch=true;

    if(status==="unsent") statusMatch=!c.sent && !c.web;
    if(status==="unanswered") statusMatch=c.sent && !c.answered;
    if(status==="answered") statusMatch=c.answered;
    if(status==="web") statusMatch=c.web;

    return match && statusMatch;
  });

  document.getElementById("customerTable").innerHTML=list.map(c=>`

    <tr>

      <td>
        ${
          c.web
          ? `<span style="color:#94a3b8">対象外</span>`
          :
          `<input
             type="checkbox"
             class="customer-check"
             data-id="${c.id}"
             ${c.selected?"checked":""}
             onchange="toggleCustomer(${c.id},this.checked)">`
        }
      </td>

      <td class="customer">
        <strong>${escapeHtml(c.company)}</strong>
        <span>${escapeHtml(c.name)}</span>
        <small>${escapeHtml(c.email)}</small>
        <small>${escapeHtml(c.phone)} / ${escapeHtml(c.address)}</small>
      </td>

      <td>
        ${
          c.sent
          ? `
            <div>
              ${c.sentAt}<br>
              <span style="color:#64748b">
                ${c.count}回送信
              </span>
            </div>
            <button class="btn small"
                    onclick="showCustomerMail(${c.id})">
              送信文を確認
            </button>
          `
          :
          c.web
          ? `<span style="color:#64748b">Web直接回答</span>`
          : `<span style="color:#94a3b8">未送信</span>`
        }
      </td>

      <td>
        ${
          c.answered
          ? `<span class="badge green">● 回答済み</span>`
          : c.sent
            ? `<span class="badge yellow">● 送信済み・未回答</span>`
            : `<span class="badge gray">未送信</span>`
        }
      </td>

      <td>
        ${
          c.kintone
          ? `<span style="color:#16a34a;font-weight:700">
               ✓ kintone登録完了
             </span>`
          :
          `<span class="badge red">未登録</span>
           <br>
           <button class="btn small success"
                   style="margin-top:6px"
                   onclick="completeKintone(${c.id})">
             kintone登録完了
           </button>`
        }
      </td>

    </tr>
  `).join("");
}

function toggleCustomer(id,checked){

  const customer=customers.find(c=>c.id===id);
  customer.selected=checked;
}

function toggleAllCustomers(){

  const visibleCheckboxes=document.querySelectorAll(".customer-check");

  const shouldCheck=
    [...visibleCheckboxes].some(c=>!c.checked);

  visibleCheckboxes.forEach(box=>{
    box.checked=shouldCheck;

    const customer=customers.find(
      c=>c.id==box.dataset.id
    );

    if(customer)customer.selected=shouldCheck;
  });
}

function selectUnanswered(){

  customers.forEach(c=>{
    c.selected=!c.answered && !c.web;
  });

  renderCustomers();
  toast("未回答者を選択しました");
}

function sendMail(){

  const selected=customers.filter(c=>c.selected && !c.web);

  if(!selected.length){
    toast("送信対象を選択してください");
    return;
  }

  const alreadySent=selected.filter(c=>c.sent);

  if(alreadySent.length){

    confirmAction(
      "再送確認",
      `既に送信済みの宛先が ${alreadySent.length} 件含まれています。再送しますか？`,
      ()=>performSend(selected,true)
    );

  }else{
    confirmAction(
      "一括送信確認",
      `${selected.length}件の顧客へメールを送信します。よろしいですか？`,
      ()=>performSend(selected,false)
    );
  }
}

function performSend(selected,resend){

  const now="2026/08/24 14:"+String(
    Math.floor(Math.random()*50)
  ).padStart(2,"0");

  selected.forEach(c=>{
    c.sent=true;
    c.sentAt=now;
    c.count++;
    c.selected=false;
  });

  mailLogs.unshift({
    date:now,
    type:resend?"リマインド送信":"初回一括送信",
    count:selected.length,
    subject:resend
      ?"【顧客満足度調査】ご回答のお願い（再送）"
      :document.getElementById("mailSubject").value,
    user:"管理者"
  });

  renderCustomers();
  renderMailLogs();

  toast(`${selected.length}件のメールを送信しました`);
}

function updateMailPreview(){

  const body=document.getElementById("mailBody").value;

  const preview=body
    .replaceAll("{顧客名}","山田 太郎")
    .replaceAll(
      "{アンケートURL}",
      "https://survey.example.com/r/abc123"
    );

  document.getElementById("mailPreview").textContent=preview;
}

function showCustomerMail(id){

  const c=customers.find(c=>c.id===id);

  const subject=document.getElementById("mailSubject").value
    .replaceAll("{顧客名}",c.name);

  const body=document.getElementById("mailBody").value
    .replaceAll("{顧客名}",c.name)
    .replaceAll(
      "{アンケートURL}",
      "https://survey.example.com/r/"+c.id+"abc"
    );

  openModal(
    "実際に送信したメール",
    `
      <div class="form-group">
        <label>宛先</label>
        <input class="input" readonly value="${escapeAttr(c.email)}">
      </div>

      <div class="form-group">
        <label>件名</label>
        <input class="input" readonly value="${escapeAttr(subject)}">
      </div>

      <div class="form-group">
        <label>本文</label>
        <div class="mail-preview">${escapeHtml(body)}</div>
      </div>
    `,
    `<button class="btn" onclick="closeModal()">閉じる</button>`
  );
}

function renderMailLogs(){

  document.getElementById("mailLogs").innerHTML=
    mailLogs.map((log,index)=>`
      <tr>
        <td>${log.date}</td>
        <td>
          <span class="badge ${log.type.includes("リマインド")?"yellow":"blue"}">
            ${log.type}
          </span>
        </td>
        <td>${log.count}件</td>
        <td>${escapeHtml(log.subject)}</td>
        <td>${escapeHtml(log.user)}</td>
        <td>
          <button class="btn small"
                  onclick="showLogMail(${index})">
            送信文を確認
          </button>
        </td>
      </tr>
    `).join("");
}

function showLogMail(index){

  const log=mailLogs[index];

  openModal(
    "送信履歴の詳細",
    `
      <div class="form-group">
        <label>送信日時</label>
        <input class="input" readonly value="${log.date}">
      </div>

      <div class="form-group">
        <label>送信種別</label>
        <input class="input" readonly value="${log.type}">
      </div>

      <div class="form-group">
        <label>送信件数</label>
        <input class="input" readonly value="${log.count}件">
      </div>

      <div class="form-group">
        <label>件名</label>
        <input class="input" readonly value="${escapeAttr(log.subject)}">
      </div>

      <div class="form-group">
        <label>本文</label>
        <div class="mail-preview">
${escapeHtml(
`山田 太郎 様

いつもお世話になっております。

アンケートへのご回答をお願いいたします。

https://survey.example.com/r/abc123

よろしくお願いいたします。`
)}
        </div>
      </div>
    `,
    `<button class="btn" onclick="closeModal()">閉じる</button>`
  );
}

function completeKintone(id){

  const c=customers.find(c=>c.id===id);

  confirmAction(
    "kintone登録完了",
    `${c.name} さんのkintone登録が完了したことを記録しますか？`,
    ()=>{
      c.kintone=true;
      renderCustomers();
      toast("kintone登録完了として更新しました");
    }
  );
}


/* =========================================================
   ④ 集計
========================================================= */

function openAnalytics(id){
  showPage("analytics");
}

function selectAllQuestions(flag){

  document
    .querySelectorAll(".question-filter input[type=checkbox]")
    .forEach(cb=>cb.checked=flag);

  renderAnalytics();
}

function renderAnalytics(){

  const checked=[
    ...document.querySelectorAll(".question-filter input[type=checkbox]")
  ].map((el,index)=>({checked:el.checked,index}));

  const root=document.getElementById("analyticsContent");

  let html="";

  if(checked[0]?.checked){

    html+=`
      <div class="card chart-card">

        <div class="chart-head">
          <div>
            <strong>Q1. サービス全体の満足度</strong>
            <div style="font-size:12px;color:#64748b;margin-top:3px">
              単一選択 / 128回答
            </div>
          </div>

          <span class="badge blue">単一選択</span>
        </div>

        <div class="chart-body">

          ${bar("とても満足",52,67)}
          ${bar("満足",31,40)}
          ${bar("普通",11,14)}
          ${bar("やや不満",5,6)}
          ${bar("不満",1,1)}

        </div>
      </div>
    `;
  }

  if(checked[1]?.checked){

    html+=`
      <div class="card chart-card">

        <div class="chart-head">
          <div>
            <strong>Q2. 良かった点</strong>
            <div style="font-size:12px;color:#64748b;margin-top:3px">
              複数選択 / 128回答
            </div>
          </div>

          <span class="badge blue">複数選択</span>
        </div>

        <div class="chart-body">

          ${bar("使いやすさ",62,79)}
          ${bar("機能",55,70)}
          ${bar("サポート",48,61)}
          ${bar("価格",35,45)}
          ${bar("その他",8,10)}

          <div class="other-box">
            <div class="other-head" onclick="toggleOther(this)">
              <strong>
                その他
                <span class="badge yellow">自由記述 10件</span>
              </strong>
              <span>▼</span>
            </div>

            <div class="other-list">
              <div class="answer-note">
                <strong>株式会社サンプル商事 / 山田 太郎</strong>
                <div>サポートの対応が非常に丁寧でした。</div>
              </div>

              <div class="answer-note">
                <strong>株式会社ABC / 佐藤 花子</strong>
                <div>スマートフォンから回答しやすかったです。</div>
              </div>

              <div class="answer-note">
                <strong>個人回答 / 田中 次郎</strong>
                <div>公開フォームから回答しました。</div>
              </div>
            </div>
          </div>

        </div>
      </div>
    `;
  }

  if(checked[2]?.checked){

    html+=`
      <div class="card chart-card">

        <div class="chart-head">
          <div>
            <strong>Q3. 改善してほしい点</strong>
            <div style="font-size:12px;color:#64748b;margin-top:3px">
              自由記述
            </div>
          </div>

          <span class="badge gray">テキスト</span>
        </div>

        <div class="chart-body">

          <div class="timeline">

            <div class="timeline-item">
              <div class="timeline-dot"></div>
              <div class="timeline-content">
                <strong>株式会社サンプル商事 / 山田 太郎</strong>
                <small>2026/08/08 13:24</small>
                <div class="answer-text">
                  管理画面をさらに使いやすくしてほしい
                </div>
              </div>
            </div>

            <div class="timeline-item">
              <div class="timeline-dot"></div>
              <div class="timeline-content">
                <strong>株式会社ABC / 佐藤 花子</strong>
                <small>2026/08/08 15:02</small>
                <div class="answer-text">
                  検索機能を増やしてほしい
                </div>
              </div>
            </div>

            <div class="timeline-item">
              <div class="timeline-dot"></div>
              <div class="timeline-content">
                <strong>個人回答 / 田中 次郎</strong>
                <small>2026/08/09 15:12</small>
                <div class="answer-text">
                  特になし
                </div>
              </div>
            </div>

          </div>

        </div>
      </div>
    `;
  }

  if(checked[3]?.checked){

    html+=`
      <div class="card chart-card">

        <div class="chart-head">
          <div>
            <strong>Q4. 今後も利用したいですか？</strong>
          </div>
          <span class="badge blue">単一選択</span>
        </div>

        <div class="chart-body">

          ${bar("ぜひ利用したい",58,74)}
          ${bar("利用したい",29,37)}
          ${bar("どちらともいえない",10,13)}
          ${bar("利用したくない",3,4)}

        </div>
      </div>
    `;
  }

  if(!html){
    html=`
      <div class="card empty">
        <div class="empty-icon">📊</div>
        集計対象の設問を選択してください
      </div>
    `;
  }

  root.innerHTML=html;
}

function bar(label,percent,count){

  return `
    <div class="bar-row">
      <div>${label}</div>

      <div class="bar">
        <span style="width:${percent}%"></span>
      </div>

      <div style="text-align:right">
        <strong>${percent}%</strong>
        <span style="color:#64748b"> / ${count}件</span>
      </div>
    </div>
  `;
}

function toggleOther(head){
  const list=head.nextElementSibling;
  list.classList.toggle("open");
}

function renderAnswerTable(){

  const keyword=
    document.getElementById("answerSearch").value.toLowerCase();

  const list=answers.filter(a=>
    a.company.toLowerCase().includes(keyword) ||
    a.name.toLowerCase().includes(keyword)
  );

  document.getElementById("answerTable").innerHTML=
    list.map(a=>`
      <tr>

        <td class="customer">
          <strong>${escapeHtml(a.company)}</strong>
          <span>${escapeHtml(a.name)}</span>
        </td>

        <td>${a.date}</td>

        <td>
          <span class="badge yellow">その他</span>
          <strong>${escapeHtml(a.other)}</strong>
        </td>

        <td>
          <button class="btn small primary"
                  onclick="showFullAnswer(${a.id})">
            全回答を表示
          </button>
        </td>

      </tr>
    `).join("");
}

function showFullAnswer(id){

  const a=answers.find(a=>a.id===id);

  openModal(
    `${a.company} / ${a.name} の全回答`,
    `
      <div style="color:#64748b;font-size:13px;margin-bottom:15px">
        回答日時：${a.date}
      </div>

      ${a.all.map(item=>`
        <div style="padding:13px 0;border-bottom:1px solid #e2e8f0">
          <div style="font-weight:700;margin-bottom:5px">
            ${escapeHtml(item[0])}
          </div>

          <div style="background:#f8fafc;padding:10px;border-radius:7px">
            ${escapeHtml(item[1])}
          </div>
        </div>
      `).join("")}
    `,
    `<button class="btn" onclick="closeModal()">閉じる</button>`
  );
}

function downloadCSV(){

  const rows=[
    ["回答ID","回答日時","顧客ID","会社名","氏名",
     "設問1","設問2","設問3","設問4"],

    ...answers.map(a=>[
      a.id,
      a.date,
      a.id,
      a.company,
      a.name,
      a.all[0][1],
      a.all[1][1],
      a.all[2][1],
      a.all[3][1]
    ])
  ];

  const csv="\uFEFF"+
    rows.map(row=>
      row.map(v=>`"${String(v).replaceAll('"','""')}"`).join(",")
    ).join("\r\n");

  const blob=new Blob([csv],{
    type:"text/csv;charset=utf-8;"
  });

  const url=URL.createObjectURL(blob);
  const a=document.createElement("a");

  a.href=url;
  a.download="survey_answers.csv";
  a.click();

  URL.revokeObjectURL(url);

  toast("CSVをダウンロードしました");
}

function exportPDF(){

  const selected=[
    ...document.querySelectorAll(".question-filter input")
  ].filter(x=>x.checked).length;

  if(!selected){
    toast("PDF出力対象の設問を選択してください");
    return;
  }

  window.print();
}


/* =========================================================
   ⑤ kintone
========================================================= */

function fetchKintoneFields(){

  const appId=document.getElementById("kAppId").value;

  if(!appId){
    toast("アプリIDを入力してください");
    return;
  }

  const status=document.getElementById("kintoneFetchStatus");

  status.innerHTML=
    `<span style="color:#2563eb">● kintone APIへ接続中...</span>`;

  setTimeout(()=>{

    status.innerHTML=`
      <span style="color:#16a34a">
        ✓ 項目一覧を取得しました
      </span>
      <span style="margin-left:10px">
        12項目
      </span>
    `;

    populateKintoneOptions();

    toast("kintoneの項目一覧を取得しました");

  },900);
}

function populateKintoneOptions(){

  const fields=[
    "会社名",
    "氏名",
    "メールアドレス",
    "部署名",
    "電話番号",
    "郵便番号",
    "都道府県",
    "市区町村",
    "番地",
    "建物名",
    "担当者名",
    "企業名"
  ];

  const selectIds=[
    "mapCompany",
    "mapName",
    "mapEmail",
    "mapDepartment",
    "mapPhone"
  ];

  selectIds.forEach(id=>{

    const select=document.getElementById(id);

    select.innerHTML=
      fields.map(f=>`
        <option>${f}</option>
      `).join("");

  });

  const address=document.getElementById("mapAddress");

  address.innerHTML=
    fields.map(f=>`
      <option
        ${["郵便番号","都道府県","市区町村"].includes(f)
          ?"selected":""
        }>
        ${f}
      </option>
    `).join("");
}

function testKintone(){

  openModal(
    "kintone接続テスト",
    `
      <div style="text-align:center;padding:20px">

        <div style="font-size:42px;margin-bottom:10px">
          🔄
        </div>

        <p>kintoneへの接続を確認しています...</p>

      </div>
    `
  );

  setTimeout(()=>{

    openModal(
      "kintone接続テスト",
      `
        <div style="text-align:center;padding:20px">

          <div style="font-size:42px">✓</div>

          <h3 style="color:#16a34a;margin:10px 0">
            接続成功
          </h3>

          <p style="color:#64748b">
            kintone APIとの通信に成功しました。
          </p>

        </div>
      `,
      `<button class="btn primary" onclick="closeModal()">OK</button>`
    );

  },1000);
}

function saveKintone(){

  const selectedAddress=
    [...document.getElementById("mapAddress").selectedOptions]
      .map(o=>o.textContent.trim());

  const mapping={
    company:document.getElementById("mapCompany").value,
    name:document.getElementById("mapName").value,
    email:document.getElementById("mapEmail").value,
    department:document.getElementById("mapDepartment").value,
    phone:document.getElementById("mapPhone").value,
    address:selectedAddress
  };

  console.log("保存されるマッピング:",mapping);

  toast("kintone連携設定を保存しました");
}

function manualSync(){

  const result=document.getElementById("syncResult");

  result.innerHTML=
    `<span style="color:#2563eb">
      ● kintoneと同期中...
    </span>`;

  setTimeout(()=>{

    result.innerHTML=`
      <span style="color:#16a34a">
        ✓ 同期成功
      </span>
      <span style="margin-left:10px">
        顧客 200件を同期しました。
        最終同期：2026/08/24 14:32
      </span>
    `;

    toast("kintoneとの同期が完了しました");

  },1200);
}


/* =========================================================
   Utility
========================================================= */

function escapeHtml(value){

  return String(value ?? "")
    .replaceAll("&","&amp;")
    .replaceAll("<","&lt;")
    .replaceAll(">","&gt;")
    .replaceAll('"',"&quot;")
    .replaceAll("'","&#039;");
}

function escapeAttr(value){
  return escapeHtml(value);
}


/* =========================================================
   初期化
========================================================= */

document.addEventListener("DOMContentLoaded",()=>{

  renderSurveys();
  renderEditor();
  renderCustomers();
  renderMailLogs();
  renderAnalytics();
  renderAnswerTable();
  updateMailPreview();

});

</script>

</body>
</html>
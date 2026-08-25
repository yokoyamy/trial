<?php
/*
 * アンケート管理システム
 * インタラクティブ・モック
 * Apache 2.4 / PHP 8.5
 *
 * 1ファイル構成：index.php
 * 実DB / kintone API / SMTP / 認証は使用しない。
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム - Mock</title>
<style>
*{box-sizing:border-box}
:root{
  --primary:#2563eb;--primary-dark:#1d4ed8;--bg:#f5f7fb;
  --card:#fff;--text:#172033;--muted:#667085;--border:#dfe4ec;
  --danger:#dc2626;--success:#16a34a;--warning:#d97706;
}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:var(--text);background:var(--bg)}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.app-header{height:60px;background:#172033;color:#fff;display:flex;align-items:center;padding:0 24px;gap:28px;position:sticky;top:0;z-index:50}
.logo{font-weight:700;font-size:18px;margin-right:10px}
.nav{display:flex;gap:4px;height:100%}
.nav button{border:0;background:transparent;color:#cbd5e1;padding:0 14px}
.nav button:hover,.nav button.active{background:#26344d;color:#fff}
.header-spacer{flex:1}
.container{max-width:1440px;margin:0 auto;padding:28px 24px}
.page{display:none}
.page.active{display:block}
.page-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.page-title h1{font-size:25px;margin:0}
.subtitle{color:var(--muted);font-size:13px;margin-top:5px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 2px 8px rgba(20,30,50,.03)}
.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btn{border:1px solid var(--border);background:#fff;color:var(--text);border-radius:8px;padding:9px 14px;font-weight:600}
.btn:hover{background:#f8fafc}
.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn.primary:hover{background:var(--primary-dark)}
.btn.danger{color:#fff;background:var(--danger);border-color:var(--danger)}
.btn.success{color:#fff;background:var(--success);border-color:var(--success)}
.btn.warning{color:#fff;background:var(--warning);border-color:var(--warning)}
.btn.sm{padding:6px 10px;font-size:13px}
.btn:disabled{opacity:.45;cursor:not-allowed}
input,textarea,select{border:1px solid #cfd6e2;border-radius:8px;padding:9px 11px;background:#fff;color:var(--text)}
input:focus,textarea:focus,select:focus{outline:2px solid #bfdbfe;border-color:var(--primary)}
textarea{resize:vertical}
.search{min-width:280px}
table{width:100%;border-collapse:collapse}
th,td{padding:13px 11px;border-bottom:1px solid #edf0f5;text-align:left;vertical-align:middle}
th{font-size:13px;color:#596579;background:#fafbfc;white-space:nowrap}
td{font-size:14px}
.badge{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:700}
.badge.draft{background:#eef2ff;color:#4338ca}
.badge.live{background:#dcfce7;color:#15803d}
.badge.stop{background:#fff7ed;color:#c2410c}
.badge.end{background:#e5e7eb;color:#4b5563}
.badge.ok{background:#dcfce7;color:#15803d}
.badge.ng{background:#fee2e2;color:#b91c1c}
.badge.gray{background:#f1f5f9;color:#475569}
.empty{text-align:center;color:var(--muted);padding:40px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-field{display:flex;flex-direction:column;gap:6px}
.form-field.full{grid-column:1/-1}
.form-label{font-weight:700;font-size:13px}
.form-help{font-size:12px;color:var(--muted)}
.top-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:22px}
.top-actions .state{margin-left:auto;display:flex;align-items:center;gap:8px;font-weight:600}
.section-title{font-size:18px;font-weight:700;margin:0 0 15px}
.question-card{border:1px solid var(--border);border-radius:10px;background:#fff;margin:12px 0;padding:15px}
.group-card{border:1px solid #cfd8e6;border-radius:12px;background:#f9fbfe;margin:18px 0;padding:18px}
.group-header{display:flex;align-items:center;gap:10px}
.group-header input{font-weight:700;flex:1}
.question-head{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.question-number{font-weight:800;color:var(--primary);min-width:52px}
.question-head .spacer{flex:1}
.choice-row{display:flex;gap:7px;margin:7px 0}
.choice-row input{flex:1}
.inline{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.radio-row{display:flex;gap:20px;flex-wrap:wrap}
.preview-frame{max-width:800px;margin:0 auto;background:#fff;border:1px solid var(--border);border-radius:14px;padding:30px}
.preview-mobile{max-width:390px}
.notice{padding:12px 14px;border-radius:8px;background:#eff6ff;color:#1d4ed8;margin-bottom:14px}
.success-box{padding:14px;border-radius:8px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.error-box{padding:14px;border-radius:8px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.stat{background:#fff;border:1px solid var(--border);border-radius:10px;padding:18px}
.stat .num{font-size:25px;font-weight:800;margin-top:5px}
.tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin:-20px -20px 20px;padding:0 20px}
.tab{border:0;background:transparent;padding:14px 18px;border-bottom:3px solid transparent;font-weight:700;color:var(--muted)}
.tab.active{color:var(--primary);border-bottom-color:var(--primary)}
.history-row{cursor:pointer}
.history-row:hover{background:#f8fafc}
.customer-selected{background:#eff6ff}
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:100;align-items:center;justify-content:center;padding:20px}
.modal-backdrop.show{display:flex}
.modal{background:#fff;border-radius:12px;width:min(560px,100%);max-height:90vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-header{padding:18px 20px;border-bottom:1px solid var(--border);font-weight:800}
.modal-body{padding:20px}
.modal-footer{padding:15px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
.toast{position:fixed;right:20px;bottom:20px;background:#172033;color:#fff;padding:13px 17px;border-radius:9px;display:none;z-index:200;box-shadow:0 10px 30px rgba(0,0,0,.2)}
.toast.show{display:block}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.small{font-size:12px;color:var(--muted)}
hr{border:0;border-top:1px solid var(--border);margin:20px 0}
.mail-preview{white-space:pre-wrap;background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:15px}
.mapping{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:center}
@media(max-width:900px){
 .app-header{overflow:auto}.nav{flex:none}
 .form-grid,.stats,.kpi-grid{grid-template-columns:1fr 1fr}
 table{min-width:1000px}
 .table-wrap{overflow:auto}
}
@media(max-width:600px){
 .container{padding:18px 12px}.form-grid,.stats,.kpi-grid{grid-template-columns:1fr}
 .page-title{align-items:flex-start;gap:10px;flex-direction:column}
 .top-actions .state{margin-left:0}
 .preview-frame{padding:18px}
}
</style>
</head>
<body>

<header class="app-header" id="adminHeader">
  <div class="logo">アンケート管理</div>
  <nav class="nav">
    <button data-page="list" class="active">アンケート一覧</button>
    <button data-page="kintone">kintone連携設定</button>
    <button data-page="smtp">メールサーバ設定</button>
  </nav>
  <div class="header-spacer"></div>
  <button class="btn sm" onclick="showToast('ログアウトはモックです')">ログアウト</button>
</header>

<main class="container">

<!-- =========================================================
     アンケート一覧
========================================================= -->
<section id="page-list" class="page active">
  <div class="page-title">
    <div>
      <h1>アンケート一覧</h1>
      <div class="subtitle">登録済みアンケートの確認・管理</div>
    </div>
    <button class="btn primary" onclick="newSurvey()">＋ 新規アンケート作成</button>
  </div>

  <div class="card">
    <div class="toolbar">
      <input id="surveySearch" class="search" placeholder="タイトルで検索" onkeydown="if(event.key==='Enter')renderSurveyList()">
      <select id="surveyStatusFilter" onchange="renderSurveyList()">
        <option value="">すべて</option>
        <option value="公開中">公開中</option>
        <option value="下書き">下書き</option>
        <option value="停止">停止</option>
        <option value="終了">終了</option>
      </select>
      <select id="surveySort" onchange="renderSurveyList()">
        <option value="updatedDesc">更新日：新しい順</option>
        <option value="updatedAsc">更新日：古い順</option>
        <option value="answersDesc">回答数：多い順</option>
        <option value="answersAsc">回答数：少ない順</option>
        <option value="startDesc">期間開始日：新しい順</option>
        <option value="startAsc">期間開始日：古い順</option>
      </select>
    </div>
  </div>

  <div class="card">
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
</section>

<!-- =========================================================
     作成・編集
========================================================= -->
<section id="page-editor" class="page">
  <div class="top-actions">
    <h1 style="margin:0;font-size:25px">アンケート作成・編集</h1>
    <div class="state">
      状態：
      <select id="editorState" onchange="requestStateChange(this.value)"></select>
    </div>
    <button class="btn" onclick="cancelEditor()">キャンセル</button>
    <button class="btn primary" onclick="saveSurvey()">保存して一覧へ</button>
  </div>

  <div class="card">
    <h2 class="section-title">基本情報</h2>
    <div class="form-grid">
      <div class="form-field full">
        <label class="form-label">アンケートタイトル</label>
        <input id="surveyTitle">
      </div>
      <div class="form-field full">
        <label class="form-label">アンケート説明</label>
        <textarea id="surveyDescription" rows="3"></textarea>
      </div>
      <div class="form-field">
        <label class="form-label">開始日時</label>
        <input id="surveyStart" type="datetime-local">
      </div>
      <div class="form-field">
        <label class="form-label">終了日時</label>
        <input id="surveyEnd" type="datetime-local">
      </div>
      <div class="form-field full">
        <label class="form-label">質問番号の採番方式</label>
        <div class="radio-row">
          <label><input type="radio" name="numbering" value="global" onchange="renumber()"> アンケート全体で通番</label>
          <label><input type="radio" name="numbering" value="group" onchange="renumber()"> グループ毎に採番</label>
        </div>
      </div>
    </div>
  </div>

  <div id="groups"></div>

  <div class="card">
    <button class="btn primary" onclick="addGroup()">＋ グループを追加</button>
  </div>
</section>

<!-- =========================================================
     プレビュー
========================================================= -->
<section id="page-preview" class="page">
  <div class="page-title">
    <div>
      <h1>プレビュー</h1>
      <div class="subtitle">実際の送信は行われません</div>
    </div>
    <div class="toolbar">
      <button class="btn" onclick="setPreviewMode('pc')">PC</button>
      <button class="btn" onclick="setPreviewMode('mobile')">スマートフォン</button>
      <button class="btn" onclick="showPage('editor')">編集へ戻る</button>
    </div>
  </div>
  <div class="notice">これはプレビュー表示のため送信されません</div>
  <div id="previewFrame" class="preview-frame"></div>
</section>

<!-- =========================================================
     顧客選択・メール送信
========================================================= -->
<section id="page-send" class="page">
  <div class="page-title">
    <div>
      <h1>顧客選択・メール送信</h1>
      <div class="subtitle" id="sendSurveyName"></div>
    </div>
    <button class="btn" onclick="showPage('list')">アンケート一覧へ</button>
  </div>

  <div class="card">
    <div class="tabs">
      <button class="tab active" id="tabCustomers" onclick="switchSendTab('customers')">顧客選択・送信</button>
      <button class="tab" id="tabHistory" onclick="switchSendTab('history')">送信履歴</button>
    </div>

    <div id="sendCustomers">
      <div class="toolbar" style="margin-bottom:15px">
        <input id="customerSearch" class="search" placeholder="顧客名・組織名・メールで検索" oninput="renderCustomers()">
        <select id="customerFilter" onchange="renderCustomers()">
          <option value="">すべて</option>
          <option value="未送信">未送信</option>
          <option value="送信済み / 未回答">送信済み / 未回答</option>
          <option value="回答済み">回答済み</option>
        </select>
        <button class="btn sm" onclick="selectAllVisible()">表示中を全選択</button>
        <span id="selectedCount" class="small"></span>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>選択</th><th>組織名</th><th>氏名</th><th>メール</th>
              <th>最終送信日時</th><th>送信回数</th><th>回答ステータス</th>
              <th>kintone登録</th><th>送信文</th>
            </tr>
          </thead>
          <tbody id="customerTable"></tbody>
        </table>
      </div>

      <hr>

      <h2 class="section-title">メール内容</h2>
      <div class="form-grid">
        <div class="form-field full">
          <label class="form-label">メール件名</label>
          <input id="mailSubject" value="アンケートご回答のお願い">
        </div>
        <div class="form-field full">
          <label class="form-label">メール本文</label>
          <textarea id="mailBody" rows="8">{顧客名} 様

いつもお世話になっております。

以下のアンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
        </div>
      </div>

      <div class="toolbar" style="margin-top:15px">
        <button class="btn" onclick="previewMail()">送信文を確認</button>
        <button class="btn warning" onclick="remindUnanswered()">未回答者へリマインド</button>
        <button class="btn primary" onclick="sendSelected()">選択顧客へ一括送信</button>
      </div>

      <div id="sendResult" style="margin-top:18px"></div>
    </div>

    <div id="sendHistory" style="display:none">
      <div class="notice">
        送信履歴はこの画面内の機能です。別画面へ遷移しません。
      </div>
      <div id="historyList"></div>
      <div id="historyDetail"></div>
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
      <div class="subtitle" id="analysisTitle"></div>
    </div>
    <div class="toolbar">
      <button class="btn" onclick="mockExport('CSV')">CSVダウンロード</button>
      <button class="btn" onclick="mockExport('PDF')">PDF出力</button>
      <button class="btn" onclick="showPage('list')">一覧へ</button>
    </div>
  </div>
  <div id="analysisContent"></div>
</section>

<!-- =========================================================
     kintone
========================================================= -->
<section id="page-kintone" class="page">
  <div class="page-title">
    <div>
      <h1>kintone連携設定</h1>
      <div class="subtitle">接続テスト・項目取得・顧客同期はそれぞれ独立した操作です</div>
    </div>
  </div>

  <div class="card">
    <div class="form-grid">
      <div class="form-field">
        <label class="form-label">サブドメイン</label>
        <input id="kSubdomain" value="example.cybozu.com">
      </div>
      <div class="form-field">
        <label class="form-label">顧客管理アプリID</label>
        <input id="kAppId" value="123">
      </div>
      <div class="form-field">
        <label class="form-label">ログイン名</label>
        <input id="kLogin" value="admin">
      </div>
      <div class="form-field">
        <label class="form-label">パスワード</label>
        <input id="kPassword" type="password" value="password">
      </div>
      <div class="form-field full">
        <label><input type="checkbox" id="kSSL" checked> SSL証明書を検証する</label>
      </div>
    </div>

    <hr>

    <div class="toolbar">
      <button class="btn primary" onclick="kintoneTest()">接続テスト</button>
      <button class="btn" onclick="saveKintone()">設定を保存</button>
      <button class="btn" onclick="getKintoneFields()">項目一覧を再取得</button>
      <button class="btn success" onclick="syncKintone()">顧客情報を同期</button>
    </div>

    <div id="kStatus" style="margin-top:15px"></div>
  </div>

  <div class="card">
    <h2 class="section-title">フィールドマッピング</h2>
    <div id="mapping"></div>
  </div>
</section>

<!-- =========================================================
     SMTP
========================================================= -->
<section id="page-smtp" class="page">
  <div class="page-title">
    <div>
      <h1>メールサーバ設定</h1>
      <div class="subtitle">モックでは実際のメール送信を行いません</div>
    </div>
  </div>

  <div class="card">
    <div class="form-grid">
      <div class="form-field"><label class="form-label">SMTPサーバ</label><input value="smtp.example.com"></div>
      <div class="form-field"><label class="form-label">SMTPポート</label><input value="587"></div>
      <div class="form-field">
        <label class="form-label">暗号化方式</label>
        <select><option>SSL</option><option selected>TLS</option><option>なし</option></select>
      </div>
      <div class="form-field"><label class="form-label">SMTPユーザー名</label><input value="mail@example.com"></div>
      <div class="form-field"><label class="form-label">SMTPパスワード</label><input type="password" value="password"></div>
      <div class="form-field"><label class="form-label">送信元メールアドレス</label><input value="mail@example.com"></div>
      <div class="form-field"><label class="form-label">送信元名</label><input value="アンケート事務局"></div>
      <div class="form-field"><label class="form-label">返信先メールアドレス</label><input value="support@example.com"></div>
    </div>
    <hr>
    <div class="toolbar">
      <button class="btn primary" onclick="smtpTest()">接続確認</button>
      <button class="btn" onclick="showToast('メールサーバ設定を保存しました')">設定を保存</button>
      <button class="btn" onclick="smtpMailTest()">テストメール送信</button>
    </div>
    <div id="smtpStatus" style="margin-top:15px"></div>
  </div>
</section>

<!-- =========================================================
     回答者
========================================================= -->
<section id="page-answer" class="page">
  <div class="page-title">
    <div>
      <h1>アンケート回答</h1>
      <div class="subtitle">回答者向け画面</div>
    </div>
    <button class="btn" onclick="startAnswer()">回答を最初からやり直す</button>
  </div>
  <div id="answerContent"></div>
</section>

<section id="page-confirm" class="page">
  <div class="page-title">
    <h1>回答確認</h1>
  </div>
  <div class="card" id="confirmContent"></div>
</section>

<section id="page-complete" class="page">
  <div class="preview-frame" style="text-align:center;margin-top:60px">
    <div style="font-size:55px">✓</div>
    <h1>回答ありがとうございました</h1>
    <p class="small">回答は正常に送信されました。</p>
  </div>
</section>

</main>

<!-- Modal -->
<div id="modalBackdrop" class="modal-backdrop">
  <div class="modal">
    <div class="modal-header" id="modalTitle"></div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-footer" id="modalFooter"></div>
  </div>
</div>

<div id="toast" class="toast"></div>

<script>
/* =========================================================
   Mock Data
========================================================= */

const state = {
  surveys: [
    {
      id:1,title:"2026年度 顧客満足度アンケート",
      description:"サービスをご利用いただいた皆様へのアンケートです。",
      start:"2026-04-01T09:00",end:"2026-09-30T18:00",
      status:"公開中",created:"2026/03/10",updated:"2026/08/20",answers:128,
      numbering:"global",
      groups:[
        {id:11,title:"ご利用状況",questions:[
          {id:101,text:"サービスを利用したことがありますか？",type:"single",required:true,
           choices:["はい","いいえ"],branch:{"はい":null,"いいえ":null}},
          {id:102,text:"現在の利用頻度を教えてください。",type:"single",required:true,
           choices:["毎日","週数回","月数回","ほとんど利用しない"],branch:{}}
        ]},
        {id:12,title:"満足度",questions:[
          {id:103,text:"サービス全体の満足度を教えてください。",type:"single",required:true,
           choices:["非常に満足","満足","普通","不満","非常に不満"],branch:{}},
          {id:104,text:"ご意見・ご要望があればご記入ください。",type:"text",required:false,
           choices:[],branch:{}}
        ]}
      ]
    },
    {
      id:2,title:"新サービス利用意向調査",
      description:"新サービスについてのご意見をお聞かせください。",
      start:"2026-08-01T09:00",end:"2026-10-31T18:00",
      status:"下書き",created:"2026/07/12",updated:"2026/08/18",answers:0,
      numbering:"global",
      groups:[
        {id:21,title:"基本質問",questions:[
          {id:201,text:"新サービスに興味がありますか？",type:"single",required:true,
           choices:["とても興味がある","興味がある","あまりない","ない"],branch:{}}
        ]}
      ]
    },
    {
      id:3,title:"2025年度 サービス改善アンケート",
      description:"昨年度のサービス改善に関するアンケートです。",
      start:"2025-04-01T09:00",end:"2026-03-31T18:00",
      status:"終了",created:"2025/03/01",updated:"2026/04/01",answers:302,
      numbering:"group",
      groups:[
        {id:31,title:"評価",questions:[
          {id:301,text:"総合評価を教えてください。",type:"single",required:true,
           choices:["5","4","3","2","1"],branch:{}}
        ]}
      ]
    }
  ],
  customers:[
    {id:1,org:"株式会社サンプル",name:"山田 太郎",email:"yamada@example.com",phone:"03-0000-0001",address:"東京都港区",
     status:"未送信",lastSend:"-",count:0,kintone:true},
    {id:2,org:"株式会社サンプル",name:"佐藤 花子",email:"sato@example.com",phone:"03-0000-0002",address:"東京都千代田区",
     status:"送信済み / 未回答",lastSend:"2026/08/20 10:30",count:1,kintone:true},
    {id:3,org:"テスト商事株式会社",name:"鈴木 一郎",email:"suzuki@example.com",phone:"03-0000-0003",address:"東京都新宿区",
     status:"回答済み",lastSend:"2026/08/18 09:15",count:1,kintone:true},
    {id:4,org:"未登録株式会社",name:"田中 次郎",email:"tanaka@example.com",phone:"03-0000-0004",address:"東京都渋谷区",
     status:"未送信",lastSend:"-",count:0,kintone:false},
    {id:5,org:"サンプル合同会社",name:"高橋 美咲",email:"takahashi@example.com",phone:"03-0000-0005",address:"東京都中央区",
     status:"送信済み / 未回答",lastSend:"2026/08/21 14:00",count:2,kintone:true}
  ],
  histories:[
    {id:1,date:"2026/08/21 14:00",type:"一括送信",count:2,subject:"アンケートご回答のお願い",
     executor:"管理者",customers:[5,2],
     body:"{顧客名} 様\n\nいつもお世話になっております。\n\n以下のアンケートへのご回答をお願いいたします。\n\n{アンケートURL}"},
    {id:2,date:"2026/08/20 10:30",type:"リマインド",count:1,subject:"アンケートご回答のお願い（再送）",
     executor:"管理者",customers:[2],
     body:"{顧客名} 様\n\nまだご回答いただいていないため、再度ご案内いたします。\n\n{アンケートURL}"}
  ],
  selectedCustomers:new Set(),
  currentSurveyId:null,
  editorOriginal:null,
  currentSendSurveyId:null,
  currentHistoryId:null,
  kintone:{
    connection:"未テスト",
    fieldsLoaded:false,
    synced:false
  }
};

let nextId=1000;

/* =========================================================
   Common
========================================================= */

function showPage(name){
  document.querySelectorAll(".page").forEach(x=>x.classList.remove("active"));
  const page=document.getElementById("page-"+name);
  if(page) page.classList.add("active");

  document.querySelectorAll(".nav button").forEach(b=>b.classList.remove("active"));
  const nav=document.querySelector(`.nav button[data-page="${name}"]`);
  if(nav) nav.classList.add("active");

  if(name==="list") renderSurveyList();
  if(name==="preview") renderPreview();
  if(name==="analysis") renderAnalysis();
  if(name==="kintone") renderMapping();
}

document.querySelectorAll(".nav button").forEach(b=>{
  b.addEventListener("click",()=>showPage(b.dataset.page));
});

function showToast(msg){
  const t=document.getElementById("toast");
  t.textContent=msg;t.classList.add("show");
  clearTimeout(window.__toast);
  window.__toast=setTimeout(()=>t.classList.remove("show"),2500);
}

function escapeHtml(s){
  return String(s??"").replace(/[&<>"']/g,m=>({
    "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
  }[m]));
}

function statusBadge(status){
  const cls={ "公開中":"live","下書き":"draft","停止":"stop","終了":"end"}[status]||"gray";
  return `<span class="badge ${cls}">${escapeHtml(status)}</span>`;
}

function clone(obj){return JSON.parse(JSON.stringify(obj))}

function findSurvey(id){return state.surveys.find(s=>s.id===Number(id))}

function confirmDialog(title,body,onOk){
  document.getElementById("modalTitle").textContent=title;
  document.getElementById("modalBody").innerHTML=body;
  document.getElementById("modalFooter").innerHTML=
    `<button class="btn" onclick="closeModal()">キャンセル</button>
     <button class="btn primary" id="modalOk">実行</button>`;
  document.getElementById("modalOk").onclick=()=>{closeModal();onOk()};
  document.getElementById("modalBackdrop").classList.add("show");
}
function closeModal(){document.getElementById("modalBackdrop").classList.remove("show")}

/* =========================================================
   Survey List
========================================================= */

function renderSurveyList(){
  const q=document.getElementById("surveySearch").value.trim().toLowerCase();
  const filter=document.getElementById("surveyStatusFilter").value;
  const sort=document.getElementById("surveySort").value;

  let arr=state.surveys.filter(s=>{
    return (!q||s.title.toLowerCase().includes(q)) &&
           (!filter||s.status===filter);
  });

  arr.sort((a,b)=>{
    if(sort==="updatedDesc")return b.updated.localeCompare(a.updated);
    if(sort==="updatedAsc")return a.updated.localeCompare(b.updated);
    if(sort==="answersDesc")return b.answers-a.answers;
    if(sort==="answersAsc")return a.answers-b.answers;
    if(sort==="startDesc")return b.start.localeCompare(a.start);
    return a.start.localeCompare(b.start);
  });

  document.getElementById("surveyTable").innerHTML=arr.length?arr.map(s=>`
    <tr>
      <td>${escapeHtml(s.created)}<br><span class="small">更新 ${escapeHtml(s.updated)}</span></td>
      <td><strong>${escapeHtml(s.title)}</strong><br><span class="small">${escapeHtml(s.description)}</span></td>
      <td>${escapeHtml(s.start.replace("T"," "))}<br>〜 ${escapeHtml(s.end.replace("T"," "))}</td>
      <td>${statusBadge(s.status)}</td>
      <td>${s.answers}</td>
      <td>
        <div class="toolbar">
          <button class="btn sm" onclick="editSurvey(${s.id})">確認・編集</button>
          <button class="btn sm" onclick="openAnalysis(${s.id})">集計</button>
          <button class="btn sm primary" onclick="openSend(${s.id})">送信</button>
          <button class="btn sm" onclick="duplicateSurvey(${s.id})">複製</button>
          <button class="btn sm" onclick="deleteSurvey(${s.id})">削除</button>
        </div>
      </td>
    </tr>
  `).join(""):`<tr><td colspan="6" class="empty">該当するアンケートがありません</td></tr>`;
}

function newSurvey(){
  const s={
    id:++nextId,title:"",description:"",start:"",end:"",
    status:"下書き",created:new Date().toLocaleDateString("ja-JP"),
    updated:new Date().toLocaleDateString("ja-JP"),answers:0,
    numbering:"global",
    groups:[{id:++nextId,title:"グループ1",questions:[
      {id:++nextId,text:"",type:"single",required:true,choices:["選択肢1","選択肢2"],branch:{}}
    ]}]
  };
  state.surveys.push(s);
  editSurvey(s.id);
}

function editSurvey(id){
  state.currentSurveyId=id;
  const s=findSurvey(id);
  state.editorOriginal=clone(s);
  document.getElementById("surveyTitle").value=s.title;
  document.getElementById("surveyDescription").value=s.description;
  document.getElementById("surveyStart").value=s.start;
  document.getElementById("surveyEnd").value=s.end;
  document.querySelectorAll('input[name="numbering"]').forEach(r=>r.checked=r.value===s.numbering);
  updateStateSelect();
  renderGroups();
  showPage("editor");
}

function updateStateSelect(){
  const s=findSurvey(state.currentSurveyId);
  if(!s)return;
  const el=document.getElementById("editorState");
  let options=[];
  if(s.status==="下書き")options=["下書き","公開"];
  else if(s.status==="公開中")options=["公開中","停止"];
  else if(s.status==="停止")options=["停止","再開"];
  else options=["終了"];
  el.innerHTML=options.map(x=>`<option value="${x}" ${x===s.status?"selected":""}>${x}</option>`).join("");
}

function requestStateChange(newState){
  const s=findSurvey(state.currentSurveyId);
  if(!s||newState===s.status)return;
  const text={
    "公開":"このアンケートを公開しますか？",
    "停止":"このアンケートを停止しますか？",
    "再開":"このアンケートを再開しますか？"
  }[newState];
  if(!text){updateStateSelect();return}
  confirmDialog(newState,text,()=>{
    s.status=newState==="公開"?"公開中":newState==="再開"?"公開中":newState;
    s.updated=new Date().toLocaleDateString("ja-JP");
    updateStateSelect();
    showToast("状態を変更しました");
  });
}

function saveSurvey(){
  const s=findSurvey(state.currentSurveyId);
  if(!s)return;
  s.title=document.getElementById("surveyTitle").value;
  s.description=document.getElementById("surveyDescription").value;
  s.start=document.getElementById("surveyStart").value;
  s.end=document.getElementById("surveyEnd").value;
  s.numbering=document.querySelector('input[name="numbering"]:checked')?.value||"global";
  s.updated=new Date().toLocaleDateString("ja-JP");
  showToast("保存しました");
  showPage("list");
}

function cancelEditor(){
  const original=state.editorOriginal;
  confirmDialog("編集内容を破棄しますか？","未保存の変更内容は失われます。",()=>{
    if(original){
      const i=state.surveys.findIndex(s=>s.id===original.id);
      if(i>=0)state.surveys[i]=original;
    }
    showPage("list");
  });
}

function duplicateSurvey(id){
  const s=findSurvey(id);
  confirmDialog("アンケートを複製しますか?",
    `<p><strong>${escapeHtml(s.title)}</strong></p>
     <p class="small">公開状態・回答データ・送信履歴は複製されません。</p>`,()=>{
      const copy=clone(s);
      copy.id=++nextId;
      copy.title=s.title+"（複製）";
      copy.status="下書き";
      copy.answers=0;
      copy.created=new Date().toLocaleDateString("ja-JP");
      copy.updated=copy.created;
      copy.groups.forEach(g=>{
        g.id=++nextId;
        g.questions.forEach(q=>q.id=++nextId);
      });
      state.surveys.push(copy);
      showToast("複製しました。下書きとして一覧へ追加しました");
      renderSurveyList();
    });
}

function deleteSurvey(id){
  const s=findSurvey(id);
  confirmDialog("アンケートを削除しますか?",
    `<p>「${escapeHtml(s.title)}」を削除します。</p>`,()=>{
      state.surveys=state.surveys.filter(x=>x.id!==id);
      showToast("削除しました");
      renderSurveyList();
    });
}

/* =========================================================
   Groups / Questions
========================================================= */

function getNumbering(){
  return document.querySelector('input[name="numbering"]:checked')?.value||"global";
}

function renderGroups(){
  const s=findSurvey(state.currentSurveyId);
  const root=document.getElementById("groups");
  root.innerHTML=s.groups.map((g,gi)=>`
    <div class="group-card" data-group="${g.id}">
      <div class="group-header">
        <span class="small">グループ</span>
        <input value="${escapeHtml(g.title)}" onchange="updateGroupTitle(${g.id},this.value)">
        <button class="btn sm" onclick="moveGroup(${g.id},-1)">↑</button>
        <button class="btn sm" onclick="moveGroup(${g.id},1)">↓</button>
        <button class="btn sm danger" onclick="removeGroup(${g.id})">削除</button>
      </div>
      <div id="questions-${g.id}">
        ${g.questions.map((q,qi)=>questionHtml(g, q, qi)).join("")}
      </div>
      <button class="btn sm primary" onclick="addQuestion(${g.id})">＋ 質問を追加</button>
    </div>
  `).join("");
  renumber();
}

function questionHtml(g,q,qi){
  return `<div class="question-card" draggable="true"
      ondragstart="dragQuestion(event,${g.id},${q.id})"
      ondragover="event.preventDefault()"
      ondrop="dropQuestion(event,${g.id},${q.id})">
    <div class="question-head">
      <span class="question-number" id="qn-${q.id}">Q</span>
      <span class="small">ドラッグで並び替え / グループ移動</span>
      <span class="spacer"></span>
      <button class="btn sm" onclick="moveQuestion(${g.id},${q.id},-1)">↑</button>
      <button class="btn sm" onclick="moveQuestion(${g.id},${q.id},1)">↓</button>
      <button class="btn sm danger" onclick="removeQuestion(${g.id},${q.id})">削除</button>
    </div>
    <div class="form-field">
      <label class="form-label">質問文</label>
      <textarea rows="2" onchange="updateQuestion(${g.id},${q.id},'text',this.value)">${escapeHtml(q.text)}</textarea>
    </div>
    <div class="form-grid" style="margin-top:10px">
      <div class="form-field">
        <label class="form-label">回答形式</label>
        <select onchange="changeQuestionType(${g.id},${q.id},this.value)">
          <option value="single" ${q.type==="single"?"selected":""}>単一選択</option>
          <option value="multi" ${q.type==="multi"?"selected":""}>複数選択</option>
          <option value="text" ${q.type==="text"?"selected":""}>自由記述</option>
        </select>
      </div>
      <div class="form-field">
        <label class="form-label">回答</label>
        <label><input type="checkbox" ${q.required?"checked":""}
          onchange="updateQuestion(${g.id},${q.id},'required',this.checked)"> 必須回答</label>
      </div>
    </div>
    ${q.type!=="text"?`
      <div style="margin-top:12px">
        <div class="form-label">選択肢</div>
        ${q.choices.map((c,ci)=>`
          <div class="choice-row">
            <input value="${escapeHtml(c)}" onchange="updateChoice(${g.id},${q.id},${ci},this.value)">
            <button class="btn sm" onclick="removeChoice(${g.id},${q.id},${ci})">削除</button>
          </div>
        `).join("")}
        <button class="btn sm" onclick="addChoice(${g.id},${q.id})">＋ 選択肢追加</button>
      </div>
      ${q.type==="single"?`
        <div style="margin-top:15px">
          <div class="form-label">条件分岐</div>
          <div class="small" style="margin-bottom:7px">選択肢ごとに次に表示する質問を設定できます。</div>
          ${q.choices.map(c=>`
            <div class="inline" style="margin:6px 0">
              <span style="min-width:130px">${escapeHtml(c)}</span>
              <select onchange="setBranch(${g.id},${q.id},'${escapeHtml(c)}',this.value)">
                <option value="">次の質問</option>
                ${allQuestionsExcept(q.id).map(x=>`
                  <option value="${x.id}" ${String(q.branch?.[c]||"")===String(x.id)?"selected":""}>
                    ${x.number} ${escapeHtml(x.text||"(未入力)")}
                  </option>
                `).join("")}
              </select>
            </div>
          `).join("")}
        </div>`:""}
    `:""}
  </div>`;
}

function allQuestionsExcept(exceptId){
  const s=findSurvey(state.currentSurveyId),arr=[];
  let n=1;
  s.groups.forEach((g,gi)=>g.questions.forEach((q,qi)=>{
    if(q.id!==exceptId)arr.push({id:q.id,text:q.text,number:`Q${n}`});
    n++;
  }));
  return arr;
}

function updateGroupTitle(id,value){
  const s=findSurvey(state.currentSurveyId);
  const g=s.groups.find(x=>x.id===id);if(g)g.title=value;
}

function addGroup(){
  const s=findSurvey(state.currentSurveyId);
  s.groups.push({id:++nextId,title:`グループ${s.groups.length+1}`,questions:[]});
  renderGroups();
}

function removeGroup(id){
  const s=findSurvey(state.currentSurveyId),g=s.groups.find(x=>x.id===id);
  confirmDialog("グループを削除しますか?",
    `<p>「${escapeHtml(g.title)}」を削除します。</p>
     ${g.questions.length?`<p class="error-box">このグループには質問が ${g.questions.length} 件あります。</p>`:""}`,()=>{
      s.groups=s.groups.filter(x=>x.id!==id);renderGroups();
    });
}

function moveGroup(id,dir){
  const s=findSurvey(state.currentSurveyId);
  const i=s.groups.findIndex(g=>g.id===id),j=i+dir;
  if(j<0||j>=s.groups.length)return;
  [s.groups[i],s.groups[j]]=[s.groups[j],s.groups[i]];
  renderGroups();
}

function addQuestion(groupId){
  const s=findSurvey(state.currentSurveyId),g=s.groups.find(x=>x.id===groupId);
  g.questions.push({
    id:++nextId,text:"",type:"single",required:false,choices:["選択肢1","選択肢2"],branch:{}
  });
  renderGroups();
}

function removeQuestion(groupId,qid){
  confirmDialog("質問を削除しますか?","この質問を削除します。",()=>{
    const s=findSurvey(state.currentSurveyId),g=s.groups.find(x=>x.id===groupId);
    g.questions=g.questions.filter(q=>q.id!==qid);renderGroups();
  });
}

function updateQuestion(gid,qid,key,value){
  const q=getQuestion(gid,qid);if(q)q[key]=value;
}

function getQuestion(gid,qid){
  const s=findSurvey(state.currentSurveyId),g=s.groups.find(x=>x.id===gid);
  return g?.questions.find(q=>q.id===qid);
}

function changeQuestionType(gid,qid,type){
  const q=getQuestion(gid,qid);if(!q)return;
  q.type=type;
  if(type==="text")q.choices=[];
  else if(!q.choices.length)q.choices=["選択肢1","選択肢2"];
  renderGroups();
}

function addChoice(gid,qid){
  const q=getQuestion(gid,qid);q.choices.push(`選択肢${q.choices.length+1}`);renderGroups();
}
function removeChoice(gid,qid,i){
  const q=getQuestion(gid,qid);q.choices.splice(i,1);renderGroups();
}
function updateChoice(gid,qid,i,v){
  const q=getQuestion(gid,qid);q.choices[i]=v;
}
function setBranch(gid,qid,choice,target){
  const q=getQuestion(gid,qid);
  q.branch=q.branch||{};q.branch[choice]=target?Number(target):null;
}

function moveQuestion(gid,qid,dir){
  const s=findSurvey(state.currentSurveyId),g=s.groups.find(x=>x.id===gid);
  const i=g.questions.findIndex(q=>q.id===qid),j=i+dir;
  if(j<0||j>=g.questions.length)return;
  [g.questions[i],g.questions[j]]=[g.questions[j],g.questions[i]];
  renderGroups();
}

let draggedQuestion=null;
function dragQuestion(e,gid,qid){
  draggedQuestion={gid,qid};e.dataTransfer.effectAllowed="move";
}
function dropQuestion(e,targetGid,targetQid){
  e.preventDefault();
  if(!draggedQuestion)return;
  const s=findSurvey(state.currentSurveyId);
  const src=s.groups.find(g=>g.id===draggedQuestion.gid);
  const dst=s.groups.find(g=>g.id===targetGid);
  const idx=src.questions.findIndex(q=>q.id===draggedQuestion.qid);
  if(idx<0)return;
  const [q]=src.questions.splice(idx,1);
  const targetIdx=dst.questions.findIndex(x=>x.id===targetQid);
  dst.questions.splice(targetIdx<0?dst.questions.length:targetIdx,0,q);
  draggedQuestion=null;renderGroups();
}

function renumber(){
  const s=findSurvey(state.currentSurveyId);if(!s)return;
  const mode=getNumbering();
  let global=1;
  s.groups.forEach((g,gi)=>{
    g.questions.forEach((q,qi)=>{
      const num=mode==="group"?`Q${gi+1}-${qi+1}`:`Q${global}`;
      q.number=num;
      const el=document.getElementById("qn-"+q.id);if(el)el.textContent=num;
      global++;
    });
  });
}

/* =========================================================
   Preview
========================================================= */

function renderPreview(){
  const s=findSurvey(state.currentSurveyId);
  const frame=document.getElementById("previewFrame");
  frame.innerHTML=`
    <h1>${escapeHtml(s.title||"アンケートタイトル")}</h1>
    <p class="small">${escapeHtml(s.description||"アンケート説明")}</p>
    <hr>
    ${s.groups.map(g=>`
      <section style="margin:25px 0">
        <h2>${escapeHtml(g.title)}</h2>
        ${g.questions.map(q=>`
          <div style="margin:22px 0">
            <div style="font-weight:700;margin-bottom:9px">${q.number||""}. ${escapeHtml(q.text||"質問文")}${q.required?" <span style='color:#dc2626'>*</span>":""}</div>
            ${q.type==="single"?q.choices.map(c=>`<label style="display:block;margin:9px 0"><input type="radio" name="p${q.id}"> ${escapeHtml(c)}</label>`).join("")
            :q.type==="multi"?q.choices.map(c=>`<label style="display:block;margin:9px 0"><input type="checkbox"> ${escapeHtml(c)}</label>`).join("")
            :`<textarea rows="4" style="width:100%" placeholder="回答を入力してください"></textarea>`}
          </div>
        `).join("")}
      </section>
    `).join("")}
    <button class="btn primary" onclick="showToast('プレビューのため送信されません')">回答を送信する</button>
  `;
}

function setPreviewMode(mode){
  const frame=document.getElementById("previewFrame");
  frame.classList.toggle("preview-mobile",mode==="mobile");
}

/* =========================================================
   Send
========================================================= */

function openSend(id){
  state.currentSendSurveyId=id;
  state.selectedCustomers=new Set();
  const s=findSurvey(id);
  document.getElementById("sendSurveyName").textContent=s.title;
  document.getElementById("sendResult").innerHTML="";
  switchSendTab("customers");
  renderCustomers();
  showPage("send");
}

function renderCustomers(){
  const q=document.getElementById("customerSearch").value.toLowerCase();
  const filter=document.getElementById("customerFilter").value;
  let arr=state.customers.filter(c=>{
    const text=(c.org+c.name+c.email).toLowerCase();
    return (!q||text.includes(q))&&(!filter||c.status===filter);
  });

  document.getElementById("customerTable").innerHTML=arr.map(c=>`
    <tr class="${state.selectedCustomers.has(c.id)?"customer-selected":""}">
      <td><input type="checkbox" ${state.selectedCustomers.has(c.id)?"checked":""}
        onchange="toggleCustomer(${c.id},this.checked)"></td>
      <td>${escapeHtml(c.org)}</td>
      <td>${escapeHtml(c.name)}</td>
      <td>${escapeHtml(c.email)}</td>
      <td>${escapeHtml(c.lastSend)}</td>
      <td>${c.count}</td>
      <td>${c.status==="回答済み"?'<span class="badge ok">回答済み</span>':c.status==="未送信"?'<span class="badge gray">未送信</span>':'<span class="badge draft">送信済み / 未回答</span>'}</td>
      <td>${c.kintone?'<span class="badge ok">✓ kintone登録完了</span>':'<span class="badge ng">未登録</span>'}</td>
      <td><button class="btn sm" onclick="previewCustomerMail(${c.id})">確認</button></td>
    </tr>
  `).join("");
  document.getElementById("selectedCount").textContent=`選択：${state.selectedCustomers.size}件`;
}

function toggleCustomer(id,on){
  if(on)state.selectedCustomers.add(id);else state.selectedCustomers.delete(id);
  renderCustomers();
}
function selectAllVisible(){
  const q=document.getElementById("customerSearch").value.toLowerCase();
  const filter=document.getElementById("customerFilter").value;
  state.customers.forEach(c=>{
    const text=(c.org+c.name+c.email).toLowerCase();
    if((!q||text.includes(q))&&(!filter||c.status===filter))state.selectedCustomers.add(c.id);
  });
  renderCustomers();
}

function mailContentFor(c){
  const s=findSurvey(state.currentSendSurveyId);
  return document.getElementById("mailBody").value
    .replaceAll("{顧客名}",c.name)
    .replaceAll("{アンケートURL}",`https://example.local/survey/${s.id}?customer=${c.id}`);
}

function previewMail(){
  const ids=[...state.selectedCustomers];
  if(!ids.length){showToast("顧客を選択してください");return}
  previewCustomerMail(ids[0]);
}

function previewCustomerMail(id){
  const c=state.customers.find(x=>x.id===id);
  confirmDialog("送信文を確認",
    `<p><strong>宛先：</strong>${escapeHtml(c.email)}</p>
     <p><strong>件名：</strong>${escapeHtml(document.getElementById("mailSubject").value)}</p>
     <div class="mail-preview">${escapeHtml(mailContentFor(c))}</div>`,
    ()=>{});
  document.getElementById("modalFooter").innerHTML=
    `<button class="btn primary" onclick="closeModal()">閉じる</button>`;
}

function sendSelected(){
  const ids=[...state.selectedCustomers];
  if(!ids.length){showToast("送信対象を選択してください");return}
  const already=ids.filter(id=>state.customers.find(c=>c.id===id).count>0);
  const msg=already.length?
    `<p>選択した ${ids.length} 件へ送信します。</p>
     <p class="error-box">既に送信済みの宛先が ${already.length} 件含まれています。再送しますか？</p>`:
    `<p>選択した ${ids.length} 件へメールを送信します。</p>`;
  confirmDialog("メール一括送信",msg,()=>performSend(ids,"一括送信"));
}

function performSend(ids,type){
  const now=new Date().toLocaleString("ja-JP");
  const subject=document.getElementById("mailSubject").value;
  ids.forEach(id=>{
    const c=state.customers.find(x=>x.id===id);
    c.status="送信済み / 未回答";c.lastSend=now;c.count++;
  });
  state.histories.unshift({
    id:++nextId,date:now,type,count:ids.length,subject,
    executor:"管理者",customers:ids,body:document.getElementById("mailBody").value
  });
  document.getElementById("sendResult").innerHTML=`
    <div class="success-box">
      <strong>送信結果</strong><br>
      対象件数：${ids.length}件　/　送信成功：${ids.length}件　/　送信失敗：0件<br>
      送信日時：${escapeHtml(now)}<br>
      <span class="small">※モックのため実際のメールは送信されていません。</span>
    </div>`;
  state.selectedCustomers.clear();
  renderCustomers();
  showToast("送信処理を再現しました");
}

function remindUnanswered(){
  const ids=state.customers.filter(c=>c.status==="送信済み / 未回答").map(c=>c.id);
  if(!ids.length){showToast("未回答者はいません");return}
  ids.forEach(id=>state.selectedCustomers.add(id));
  renderCustomers();
  showToast(`${ids.length}件をリマインド対象として選択しました`);
}

function switchSendTab(tab){
  const customers=tab==="customers";
  document.getElementById("sendCustomers").style.display=customers?"block":"none";
  document.getElementById("sendHistory").style.display=customers?"none":"block";
  document.getElementById("tabCustomers").classList.toggle("active",customers);
  document.getElementById("tabHistory").classList.toggle("active",!customers);
  if(!customers)renderHistory();
}

function renderHistory(){
  const root=document.getElementById("historyList");
  root.innerHTML=state.histories.length?`
    <div class="table-wrap">
      <table>
        <thead><tr><th>送信日時</th><th>送信種別</th><th>送信件数</th><th>送信件名</th><th>送信実行者</th><th>対象顧客</th></tr></thead>
        <tbody>
          ${state.histories.map(h=>`
            <tr class="history-row" onclick="showHistoryDetail(${h.id})">
              <td>${escapeHtml(h.date)}</td>
              <td>${escapeHtml(h.type)}</td>
              <td>${h.count}件</td>
              <td>${escapeHtml(h.subject)}</td>
              <td>${escapeHtml(h.executor)}</td>
              <td>${h.customers.map(id=>state.customers.find(c=>c.id===id)?.name||"").join("、")}</td>
            </tr>`).join("")}
        </tbody>
      </table>
    </div>`:
    `<div class="empty">送信履歴はありません</div>`;
}

function showHistoryDetail(id){
  const h=state.histories.find(x=>x.id===id);if(!h)return;
  state.currentHistoryId=id;
  const customers=h.customers.map(cid=>{
    const c=state.customers.find(x=>x.id===cid);
    return `<div class="card" style="margin-top:10px">
      <strong>${escapeHtml(c.name)}</strong>（${escapeHtml(c.email)}）
      <div class="mail-preview" style="margin-top:8px">${escapeHtml(
        h.body.replaceAll("{顧客名}",c.name).replaceAll("{アンケートURL}",
        `https://example.local/survey/${state.currentSendSurveyId}?customer=${c.id}`)
      )}</div>
    </div>`;
  }).join("");
  document.getElementById("historyDetail").innerHTML=`
    <hr>
    <h2 class="section-title">送信内容詳細</h2>
    <p><strong>件名：</strong>${escapeHtml(h.subject)}</p>
    ${customers}
    <div class="notice">履歴確認後も、この画面の「顧客選択・送信」タブから送信業務を継続できます。</div>`;
}

/* =========================================================
   Analysis
========================================================= */

function openAnalysis(id){
  state.currentSurveyId=id;
  renderAnalysis();
  showPage("analysis");
}

function renderAnalysis(){
  const s=findSurvey(state.currentSurveyId)||state.surveys[0];
  document.getElementById("analysisTitle").textContent=s.title;
  const total=s.answers+40;
  document.getElementById("analysisContent").innerHTML=`
    <div class="stats">
      <div class="stat"><div class="small">送信対象者数</div><div class="num">${total}</div></div>
      <div class="stat"><div class="small">回答数</div><div class="num">${s.answers}</div></div>
      <div class="stat"><div class="small">未登録顧客からの回答数</div><div class="num">8</div></div>
      <div class="stat"><div class="small">未回答数</div><div class="num">${Math.max(0,total-s.answers)}</div></div>
      <div class="stat"><div class="small">回答率</div><div class="num">${total?Math.round(s.answers/total*100):0}%</div></div>
    </div>
    <div class="card" style="margin-top:18px">
      <h2 class="section-title">設問フィルター</h2>
      <div class="toolbar">
        <button class="btn sm" onclick="showToast('すべて選択')">すべて選択</button>
        <button class="btn sm" onclick="showToast('すべて解除')">すべて解除</button>
      </div>
      ${s.groups.map(g=>g.questions.map(q=>`
        <label style="display:block;margin:10px 0">
          <input type="checkbox" checked>
          ${escapeHtml(q.number||"")} ${escapeHtml(q.text||"質問")}
        </label>`).join("")).join("")}
    </div>
    <div class="card">
      <h2 class="section-title">設問別集計</h2>
      ${s.groups.map(g=>`
        <h3>${escapeHtml(g.title)}</h3>
        ${g.questions.map(q=>`
          <div class="card" style="background:#fafbfc">
            <strong>${escapeHtml(q.number||"")} ${escapeHtml(q.text||"質問")}</strong>
            ${q.type==="text"
              ?`<p class="small">回答内容一覧</p><ul><li>とても参考になりました。</li><li>今後も改善を期待しています。</li></ul>`
              :q.choices.map((c,i)=>`
                <div style="margin:10px 0">
                  <div class="inline"><span style="min-width:180px">${escapeHtml(c)}</span><strong>${35-i*6}件</strong><span class="small">${50-i*8}%</span></div>
                  <div style="height:8px;background:#e5e7eb;border-radius:10px;overflow:hidden">
                    <div style="height:100%;width:${50-i*8}%;background:#2563eb"></div>
                  </div>
                </div>`).join("")}
          </div>`).join("")}
      `).join("")}
    </div>`;
}

function mockExport(type){showToast(`${type}出力操作を実行しました（モック）`)}

/* =========================================================
   kintone
========================================================= */

function kintoneTest(){
  document.getElementById("kStatus").innerHTML=`
    <div class="notice">接続テストを実行しています……</div>`;
  setTimeout(()=>{
    state.kintone.connection="成功";
    document.getElementById("kStatus").innerHTML=`
      <div class="success-box">
        <strong>接続テスト成功</strong><br>
        kintoneへの接続に成功しました。<br>
        <span class="small">※モックのため実際のkintone APIには接続していません。</span>
      </div>`;
  },500);
}

function saveKintone(){
  showToast("kintone接続設定を保存しました（接続テストとは別操作です）");
}

function getKintoneFields(){
  state.kintone.fieldsLoaded=true;
  renderMapping();
  document.getElementById("kStatus").innerHTML=`
    <div class="success-box">
      項目一覧を取得しました。接続テスト・顧客同期とは別の操作です。
    </div>`;
}

function syncKintone(){
  if(state.kintone.connection!=="成功"){
    confirmDialog("顧客情報を同期しますか?",
      `<div class="error-box">
        現在、接続テストが成功していません。<br>
        それでもモック上で同期結果を再現しますか？
      </div>`,()=>doSync());
    return;
  }
  doSync();
}
function doSync(){
  state.kintone.synced=true;
  state.customers.forEach(c=>{if(c.id!==4)c.kintone=true});
  document.getElementById("kStatus").innerHTML=`
    <div class="success-box">
      顧客情報の同期が完了しました。<br>同期件数：${state.customers.length}件
    </div>`;
  showToast("顧客情報を同期しました");
}

function renderMapping(){
  const fields=state.kintone.fieldsLoaded
    ?["会社名","氏名","メールアドレス","部署名","電話番号","都道府県","市区町村","番地","建物名","郵便番号"]
    :[];
  document.getElementById("mapping").innerHTML=state.kintone.fieldsLoaded?`
    <div class="mapping">
      <strong>組織名</strong><select><option>会社名</option></select>
      <strong>氏名</strong><select><option>氏名</option></select>
      <strong>メールアドレス</strong><select><option>メールアドレス</option></select>
      <strong>部署名</strong><select><option>部署名</option></select>
      <strong>電話番号</strong><select><option>電話番号</option></select>
    </div>
    <hr>
    <h3>住所マッピング</h3>
    <div class="radio-row">
      ${["都道府県","市区町村","番地","建物名","郵便番号"].map(x=>
        `<label><input type="checkbox"> ${x}</label>`).join("")}
    </div>
  `:`<div class="empty">「項目一覧を再取得」を実行するとkintone項目を表示します。</div>`;
}

/* =========================================================
   SMTP
========================================================= */

function smtpTest(){
  document.getElementById("smtpStatus").innerHTML=
    `<div class="success-box">メールサーバへの接続を確認しました（モック）。</div>`;
}
function smtpMailTest(){
  confirmDialog("テストメール送信","テストメールを送信します。モックでは実際のメール送信は行いません。",()=>{
    document.getElementById("smtpStatus").innerHTML=
      `<div class="success-box">テストメール送信成功（モック）</div>`;
  });
}

/* =========================================================
   Answerer Flow
========================================================= */

let answerState={surveyId:1,step:0,answers:{}};

function startAnswer(){
  answerState={surveyId:1,step:0,answers:{}};
  showPage("answer");
  renderAnswer();
}

function flattenQuestions(s){
  const arr=[];
  s.groups.forEach(g=>g.questions.forEach(q=>arr.push({...q,groupTitle:g.title})));
  return arr;
}

function renderAnswer(){
  const s=findSurvey(answerState.surveyId);
  const qs=flattenQuestions(s);
  const q=qs[answerState.step];
  if(!q){showAnswerConfirm();return}
  document.getElementById("answerContent").innerHTML=`
    <div class="preview-frame">
      <div class="small">${escapeHtml(q.groupTitle)}</div>
      <h2>${escapeHtml(q.number)} ${escapeHtml(q.text||"質問")}</h2>
      ${q.required?'<span class="badge stop">必須</span>':'<span class="badge gray">任意</span>'}
      <div style="margin:25px 0">
        ${q.type==="single"?q.choices.map(c=>`
          <label style="display:block;padding:13px;margin:8px 0;border:1px solid var(--border);border-radius:8px">
            <input type="radio" name="answer" value="${escapeHtml(c)}"
              ${answerState.answers[q.id]===c?"checked":""}> ${escapeHtml(c)}
          </label>`).join("")
        :q.type==="multi"?q.choices.map(c=>`
          <label style="display:block;padding:13px;margin:8px 0;border:1px solid var(--border);border-radius:8px">
            <input type="checkbox" name="answer" value="${escapeHtml(c)}"
              ${Array.isArray(answerState.answers[q.id])&&answerState.answers[q.id].includes(c)?"checked":""}> ${escapeHtml(c)}
          </label>`).join("")
        :`<textarea id="answerText" rows="6" style="width:100%" placeholder="回答を入力してください">${escapeHtml(answerState.answers[q.id]||"")}</textarea>`}
      </div>
      <div class="toolbar">
        <button class="btn" onclick="answerBack()" ${answerState.step===0?"disabled":""}>戻る</button>
        <button class="btn primary" onclick="answerNext()">次へ</button>
      </div>
    </div>`;
}

function captureAnswer(){
  const s=findSurvey(answerState.surveyId),q=flattenQuestions(s)[answerState.step];
  if(q.type==="single"){
    const v=document.querySelector('input[name="answer"]:checked')?.value||"";
    answerState.answers[q.id]=v;
  }else if(q.type==="multi"){
    answerState.answers[q.id]=[...document.querySelectorAll('input[name="answer"]:checked')].map(x=>x.value);
  }else{
    answerState.answers[q.id]=document.getElementById("answerText")?.value||"";
  }
  return q;
}

function answerNext(){
  const q=captureAnswer();
  const value=answerState.answers[q.id];
  const empty=Array.isArray(value)?value.length===0:!String(value||"").trim();
  if(q.required&&empty){
    showToast("必須項目を回答してください");
    return;
  }
  answerState.step++;
  renderAnswer();
}

function answerBack(){
  captureAnswer();answerState.step=Math.max(0,answerState.step-1);renderAnswer();
}

function showAnswerConfirm(){
  const s=findSurvey(answerState.surveyId),qs=flattenQuestions(s);
  document.getElementById("confirmContent").innerHTML=`
    <h2>${escapeHtml(s.title)}</h2>
    ${qs.map(q=>`
      <div style="padding:15px 0;border-bottom:1px solid var(--border)">
        <div class="small">${escapeHtml(q.number)}</div>
        <strong>${escapeHtml(q.text)}</strong>
        <div style="margin-top:7px">${escapeHtml(
          Array.isArray(answerState.answers[q.id])
          ?answerState.answers[q.id].join("、")
          :(answerState.answers[q.id]||"未回答")
        )}</div>
        <button class="btn sm" style="margin-top:8px" onclick="answerState.step=${qs.indexOf(q)};showPage('answer');renderAnswer()">修正</button>
      </div>`).join("")}
    <div class="toolbar" style="margin-top:20px">
      <button class="btn" onclick="answerState.step=qs.length-1;showPage('answer');renderAnswer()">戻る</button>
      <button class="btn primary" onclick="submitAnswer()">回答を送信する</button>
    </div>`;
  showPage("confirm");
}

function submitAnswer(){
  confirmDialog("回答を送信しますか?","入力内容を送信します。送信後は回答完了画面へ進みます。",()=>{
    const s=findSurvey(answerState.surveyId);s.answers++;
    showPage("complete");
  });
}

/* =========================================================
   Initialization
========================================================= */

function init(){
  renderSurveyList();
  startAnswer();
  showPage("list");
}
init();
</script>
</body>
</html>
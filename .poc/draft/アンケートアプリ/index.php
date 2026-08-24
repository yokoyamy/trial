<?php
/**
 * アンケート管理システム
 * 動作確認用モック / index.php
 *
 * ※DB・API・kintone API・メール送信はモック動作です。
 * ※画面遷移はSPA風にJavaScriptで切り替えています。
 * ※ドラッグ＆ドロップはモックのためHTML5 Drag & Drop APIを使用。
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<style>
*{box-sizing:border-box}
body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
    background:#f5f7fa;
    color:#172033;
    font-size:14px;
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.5}

.header{
    height:64px;
    background:#172033;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 28px;
    gap:30px;
    position:sticky;
    top:0;
    z-index:100;
}
.logo{font-weight:700;font-size:17px;white-space:nowrap}
.nav{display:flex;gap:6px;height:100%}
.nav button{
    background:none;
    border:0;
    color:#cbd2df;
    padding:0 16px;
}
.nav button:hover,.nav button.active{
    color:#fff;
    background:#26334a;
}
.header-right{margin-left:auto;display:flex;gap:10px}

.main{max-width:1440px;margin:auto;padding:28px}
.screen{display:none}
.screen.active{display:block}

.page-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:22px;
}
.page-title h1{font-size:24px;margin:0}
.page-title p{margin:5px 0 0;color:#6b7280}

.btn{
    border:1px solid #d7dce5;
    background:#fff;
    border-radius:7px;
    padding:9px 15px;
    min-height:40px;
    color:#243047;
}
.btn:hover{background:#f1f4f8}
.btn-primary{
    background:#2563eb;
    color:#fff;
    border-color:#2563eb;
}
.btn-primary:hover{background:#1d4ed8}
.btn-danger{color:#dc2626;border-color:#fecaca}
.btn-success{background:#059669;color:#fff;border-color:#059669}
.btn-warning{color:#92400e;background:#fff7ed;border-color:#fed7aa}
.btn-sm{min-height:32px;padding:5px 9px;font-size:12px}

.card{
    background:#fff;
    border:1px solid #e1e6ee;
    border-radius:10px;
    box-shadow:0 2px 7px rgba(20,30,50,.04);
    padding:20px;
    margin-bottom:18px;
}
.card-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    font-size:16px;
    font-weight:700;
    margin-bottom:16px;
}
.card-title small{font-weight:400;color:#718096}

.toolbar{
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
}
.search{
    height:40px;
    border:1px solid #d5dbe5;
    border-radius:7px;
    padding:0 12px;
    min-width:260px;
    outline:none;
}
.search:focus,input:focus,textarea:focus,select:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.1);
}

select{
    height:40px;
    border:1px solid #d5dbe5;
    border-radius:7px;
    padding:0 10px;
    background:#fff;
}
input[type=text],input[type=email],input[type=password],input[type=datetime-local]{
    width:100%;
    border:1px solid #d5dbe5;
    border-radius:7px;
    height:40px;
    padding:0 11px;
}
textarea{
    width:100%;
    min-height:150px;
    border:1px solid #d5dbe5;
    border-radius:7px;
    padding:11px;
    resize:vertical;
}

.table-wrap{
    overflow-x:auto;
}
table{
    width:100%;
    border-collapse:collapse;
    min-width:1050px;
}
th{
    background:#f8fafc;
    color:#667085;
    font-weight:600;
    text-align:left;
    padding:12px;
    border-bottom:1px solid #e5e7eb;
    white-space:nowrap;
}
td{
    padding:14px 12px;
    border-bottom:1px solid #edf0f4;
    vertical-align:middle;
}
tr:hover td{background:#fbfcfe}

.badge{
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:600;
    white-space:nowrap;
}
.badge-public{background:#dcfce7;color:#166534}
.badge-draft{background:#fef3c7;color:#92400e}
.badge-end{background:#e5e7eb;color:#4b5563}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.badge-red{background:#fee2e2;color:#b91c1c}
.badge-gray{background:#f1f5f9;color:#475569}
.badge-purple{background:#ede9fe;color:#6d28d9}

.actions{display:flex;gap:5px;flex-wrap:wrap}
.link{color:#2563eb;text-decoration:none;cursor:pointer}
.muted{color:#7b8494}
.small{font-size:12px}

.alert{
    padding:14px 16px;
    border-radius:8px;
    margin-bottom:18px;
}
.alert-warning{
    background:#fff7ed;
    border:1px solid #fed7aa;
    color:#9a3412;
}
.alert-success{
    background:#ecfdf5;
    border:1px solid #a7f3d0;
    color:#065f46;
}
.alert-info{
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1e40af;
}

.grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:15px}
.grid5{display:grid;grid-template-columns:repeat(5,1fr);gap:14px}

.form-row{margin-bottom:16px}
.form-row label{
    display:block;
    font-weight:600;
    margin-bottom:7px;
}
.required{color:#dc2626}

.stat{
    background:#fff;
    border:1px solid #e1e6ee;
    border-radius:10px;
    padding:18px;
}
.stat-label{font-size:12px;color:#6b7280}
.stat-value{font-size:27px;font-weight:700;margin-top:7px}

.progress{
    height:10px;
    background:#e8edf4;
    border-radius:99px;
    overflow:hidden;
}
.progress div{
    height:100%;
    background:#2563eb;
    border-radius:99px;
}

.modal{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.55);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:500;
    padding:20px;
}
.modal.show{display:flex}
.modal-box{
    width:min(850px,100%);
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:12px;
    box-shadow:0 25px 70px rgba(0,0,0,.25);
}
.modal-head{
    padding:17px 20px;
    border-bottom:1px solid #e5e7eb;
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.modal-head h3{margin:0}
.modal-body{padding:20px}
.modal-foot{
    padding:14px 20px;
    border-top:1px solid #e5e7eb;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.toast{
    position:fixed;
    right:25px;
    bottom:25px;
    background:#172033;
    color:#fff;
    padding:14px 18px;
    border-radius:8px;
    box-shadow:0 10px 30px rgba(0,0,0,.2);
    transform:translateY(100px);
    opacity:0;
    transition:.25s;
    z-index:1000;
}
.toast.show{transform:translateY(0);opacity:1}

.breadcrumb{
    color:#7b8494;
    margin-bottom:18px;
}
.breadcrumb span{color:#172033}

.question{
    border:1px solid #dfe5ed;
    border-radius:9px;
    padding:16px;
    margin-bottom:12px;
    background:#fff;
}
.question.dragging{opacity:.4}
.drag{
    cursor:grab;
    font-size:20px;
    color:#9aa4b2;
    padding-right:8px;
}
.q-head{
    display:flex;
    align-items:center;
    gap:8px;
}
.q-no{font-weight:700;color:#2563eb}
.q-title-input{flex:1}
.option-row{
    display:flex;
    gap:8px;
    margin:8px 0;
}
.option-row input{flex:1}
.group{
    border:1px solid #dbe1ea;
    border-radius:10px;
    margin-bottom:18px;
    background:#f8fafc;
}
.group-head{
    display:flex;
    align-items:center;
    gap:8px;
    padding:13px 15px;
    background:#f1f5f9;
    border-radius:10px 10px 0 0;
}
.group-head input{flex:1;font-weight:700}
.group-body{padding:14px}

.preview{
    background:#f4f6f8;
    min-height:500px;
    padding:30px;
}
.preview-device{
    background:#fff;
    margin:auto;
    border-radius:12px;
    min-height:400px;
    padding:30px;
    box-shadow:0 5px 25px rgba(0,0,0,.08);
    transition:.3s;
}
.preview-device.mobile{
    width:390px;
    max-width:100%;
}
.preview-device.pc{width:100%}
.preview-q{
    padding:18px 0;
    border-bottom:1px solid #eee;
}
.preview-q label{display:block;margin:8px 0}
.preview-submit{
    margin-top:25px;
    text-align:center;
}

.chart{
    padding:10px 0;
}
.bar-row{
    display:grid;
    grid-template-columns:150px 1fr 80px;
    gap:10px;
    align-items:center;
    margin:12px 0;
}
.bar{
    height:20px;
    background:#2563eb;
    border-radius:4px;
}
.other-box{
    background:#fafafa;
    border:1px solid #e5e7eb;
    border-radius:7px;
    padding:12px;
    margin-top:10px;
}
.timeline{
    max-height:280px;
    overflow-y:auto;
}
.comment{
    padding:12px;
    border-bottom:1px solid #eee;
}
.comment:last-child{border-bottom:0}

.mapping-table{
    width:100%;
    min-width:0;
}
.mapping-table td:first-child{width:230px;font-weight:600}

.check-list{
    max-height:250px;
    overflow:auto;
    border:1px solid #e1e6ee;
    border-radius:7px;
}
.check-item{
    display:flex;
    align-items:center;
    gap:9px;
    padding:11px 13px;
    border-bottom:1px solid #edf0f4;
}
.check-item:last-child{border-bottom:0}

.empty{
    padding:60px 20px;
    text-align:center;
    color:#8a94a5;
}

.sync-status{
    display:flex;
    gap:15px;
    align-items:center;
    background:#f8fafc;
    padding:14px;
    border-radius:8px;
}

@media(max-width:1000px){
    .grid5{grid-template-columns:repeat(2,1fr)}
    .grid3{grid-template-columns:1fr 1fr}
}
@media(max-width:700px){
    .header{padding:0 12px;gap:8px}
    .logo{font-size:14px}
    .nav button{padding:0 8px;font-size:12px}
    .header-right{display:none}
    .main{padding:15px}
    .page-title{align-items:flex-start;gap:10px}
    .page-title h1{font-size:20px}
    .grid2,.grid3,.grid5{grid-template-columns:1fr}
    .search{min-width:0;width:100%}
    .toolbar{align-items:stretch}
    .toolbar>*{flex:1}
    .bar-row{grid-template-columns:90px 1fr 55px}
}
</style>
</head>

<body>

<header class="header">
    <div class="logo">アンケート管理システム</div>

    <nav class="nav">
        <button class="active" data-nav="list">アンケート一覧</button>
        <button data-nav="kintone">キントーン連携設定</button>
    </nav>

    <div class="header-right">
        <button class="btn btn-sm" onclick="showToast('ログアウトしました（モック）')">ログアウト</button>
    </div>
</header>

<main class="main">

<!-- =========================================================
     アンケート一覧
========================================================= -->
<section id="screen-list" class="screen active">

    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <p>アンケートの作成・公開・送信・集計を管理します。</p>
        </div>
        <button class="btn btn-primary" onclick="openEditor()">＋ 新規アンケート作成</button>
    </div>

    <div class="card">
        <div class="toolbar">
            <input id="listSearch" class="search"
                   placeholder="アンケートタイトルを検索"
                   oninput="renderSurveys()"
                   onkeydown="if(event.key==='Enter')renderSurveys()">

            <select id="statusFilter" onchange="renderSurveys()">
                <option value="">すべて</option>
                <option value="公開中">公開中</option>
                <option value="下書き">下書き</option>
                <option value="終了">終了</option>
            </select>

            <select id="sortFilter" onchange="renderSurveys()">
                <option value="updated-desc">更新日：新しい順</option>
                <option value="updated-asc">更新日：古い順</option>
                <option value="answers-desc">回答数：多い順</option>
                <option value="answers-asc">回答数：少ない順</option>
                <option value="start-desc">期間開始日：新しい順</option>
                <option value="start-asc">期間開始日：古い順</option>
            </select>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            <span>アンケート一覧</span>
            <small id="surveyCount"></small>
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
</section>


<!-- =========================================================
     アンケート作成・編集
========================================================= -->
<section id="screen-editor" class="screen">

    <div class="breadcrumb">
        ホーム ＞ アンケート一覧 ＞ <span>アンケート作成</span>
    </div>

    <div class="page-title">
        <div>
            <h1 id="editorTitle">アンケート作成</h1>
            <p>設問を追加・編集し、回答者向けプレビューを確認できます。</p>
        </div>

        <div class="actions">
            <button class="btn" onclick="openPreview()">プレビュー</button>
            <button class="btn" onclick="cancelEditor()">キャンセル</button>
            <button class="btn btn-primary" onclick="saveSurvey()">保存して一覧へ戻る</button>
        </div>
    </div>

    <div class="card">
        <div class="card-title">アンケート基本情報</div>

        <div class="form-row">
            <label>アンケートタイトル <span class="required">*</span></label>
            <input id="editorSurveyTitle" type="text"
                   value="【顧客満足度調査】サービスに関するアンケート"
                   oninput="editorDirty=true">
        </div>

        <div class="grid2">
            <div class="form-row">
                <label>開始日時</label>
                <input id="editorStart" type="datetime-local"
                       value="2026-08-01T09:00"
                       oninput="editorDirty=true">
            </div>
            <div class="form-row">
                <label>終了日時</label>
                <input id="editorEnd" type="datetime-local"
                       value="2026-08-31T18:00"
                       oninput="editorDirty=true">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            <span>グループ・設問</span>
            <button class="btn btn-primary btn-sm" onclick="addGroup()">＋ グループ追加</button>
        </div>

        <div id="editorGroups"></div>
    </div>

</section>


<!-- =========================================================
     顧客送信
========================================================= -->
<section id="screen-send" class="screen">

    <div class="breadcrumb">
        ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
    </div>

    <div class="page-title">
        <div>
            <h1>顧客選択・メール送信</h1>
            <p id="sendSurveyName">アンケート</p>
        </div>
        <button class="btn btn-primary" onclick="executeBulkSend()">選択した顧客へ一括送信</button>
    </div>

    <div id="kintoneAlert" class="alert alert-warning">
        ⚠ キントーン未登録の回答者が <strong>1名</strong> います。
        顧客一覧から登録状況を確認してください。
    </div>

    <div class="card">
        <div class="card-title">
            <span>送信メールテンプレート</span>
            <small>{顧客名} / {アンケートURL} が使用できます</small>
        </div>

        <div class="form-row">
            <label>メール件名</label>
            <input id="mailSubject"
                   value="【アンケートご協力のお願い】サービスに関するアンケート">
        </div>

        <div class="form-row">
            <label>メール本文</label>
            <textarea id="mailBody">{$顧客名} 様

いつもお世話になっております。

下記URLよりアンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力のほど、よろしくお願いいたします。</textarea>
        </div>

        <button class="btn btn-sm" onclick="showToast('テンプレートを保存しました（モック）')">
            テンプレートを保存
        </button>
    </div>

    <div class="card">
        <div class="card-title">
            <span>顧客一覧</span>
            <div class="toolbar">
                <input class="search" style="min-width:220px"
                       placeholder="会社名・氏名・メール検索"
                       oninput="filterCustomers(this.value)">
                <select onchange="filterCustomerStatus(this.value)">
                    <option value="">すべて</option>
                    <option value="未送信">未送信</option>
                    <option value="未回答">未回答</option>
                    <option value="回答済み">回答済み</option>
                    <option value="未登録">未登録</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>
                        <input type="checkbox" id="selectAllCustomers"
                               onchange="toggleAllCustomers(this.checked)">
                    </th>
                    <th>会社名 / 氏名等</th>
                    <th>送信履歴</th>
                    <th>回答ステータス</th>
                    <th>キントーン対応</th>
                </tr>
                </thead>
                <tbody id="customerTable"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-title">一括送信ログ・履歴</div>
        <div class="table-wrap">
            <table style="min-width:800px">
                <thead>
                <tr>
                    <th>日時</th>
                    <th>送信種別</th>
                    <th>送信件数</th>
                    <th>件名</th>
                    <th>実行者</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody id="sendLogTable"></tbody>
            </table>
        </div>
    </div>
</section>


<!-- =========================================================
     集計・分析
========================================================= -->
<section id="screen-analytics" class="screen">

    <div class="breadcrumb">
        ホーム ＞ アンケート一覧 ＞ <span>回答集計・分析</span>
    </div>

    <div class="page-title">
        <div>
            <h1>回答集計・分析</h1>
            <p id="analyticsSurveyName">アンケート</p>
        </div>
        <div class="actions">
            <button class="btn" onclick="downloadCSV()">CSVダウンロード</button>
            <button class="btn btn-primary" onclick="exportPDF()">PDF出力</button>
        </div>
    </div>

    <div class="grid5">
        <div class="stat">
            <div class="stat-label">送信対象者数</div>
            <div class="stat-value">200<span class="small"> 人</span></div>
        </div>
        <div class="stat">
            <div class="stat-label">回答数</div>
            <div class="stat-value">128<span class="small"> 件</span></div>
        </div>
        <div class="stat">
            <div class="stat-label">未登録顧客からの回答</div>
            <div class="stat-value">8<span class="small"> 件</span></div>
        </div>
        <div class="stat">
            <div class="stat-label">未回答数</div>
            <div class="stat-value">80<span class="small"> 人</span></div>
        </div>
        <div class="stat">
            <div class="stat-label">回答率</div>
            <div class="stat-value">60.0<span class="small"> %</span></div>
        </div>
    </div>

    <div class="card" style="margin-top:18px">
        <div class="card-title">
            <span>集計対象の設問</span>
            <div class="actions">
                <button class="btn btn-sm" onclick="selectQuestions(true)">すべて選択</button>
                <button class="btn btn-sm" onclick="selectQuestions(false)">すべて解除</button>
            </div>
        </div>

        <div class="check-list">
            <label class="check-item">
                <input class="question-filter" type="checkbox" checked
                       onchange="renderAnalytics()">
                <span>Q1. サービス全体の満足度を教えてください</span>
                <span class="badge badge-blue">単一選択</span>
            </label>
            <label class="check-item">
                <input class="question-filter" type="checkbox" checked
                       onchange="renderAnalytics()">
                <span>Q2. 利用した機能を教えてください</span>
                <span class="badge badge-purple">複数選択</span>
            </label>
            <label class="check-item">
                <input class="question-filter" type="checkbox" checked
                       onchange="renderAnalytics()">
                <span>Q3. 改善してほしい点を教えてください</span>
                <span class="badge badge-gray">テキスト</span>
            </label>
        </div>
    </div>

    <div id="analyticsCharts"></div>

    <div class="card">
        <div class="card-title">
            <span>個別回答一覧</span>
            <input class="search" placeholder="会社名・氏名で検索"
                   oninput="filterAnswers(this.value)">
        </div>

        <div class="table-wrap">
            <table>
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
</section>


<!-- =========================================================
     kintone連携設定
========================================================= -->
<section id="screen-kintone" class="screen">

    <div class="breadcrumb">
        ホーム ＞ システム設定 ＞ <span>kintone連携設定</span>
    </div>

    <div class="page-title">
        <div>
            <h1>kintone連携設定</h1>
            <p>kintoneの顧客情報とアンケート管理システムの項目を連携します。</p>
        </div>
        <button class="btn btn-primary" onclick="saveKintone()">設定を保存する</button>
    </div>

    <div class="card">
        <div class="card-title">アカウント認証・アプリ接続</div>

        <div class="grid2">
            <div class="form-row">
                <label>サブドメイン</label>
                <input id="kSubdomain" value="example">
                <div class="small muted" style="margin-top:5px">
                    https://example.cybozu.com
                </div>
            </div>

            <div class="form-row">
                <label>顧客管理アプリID</label>
                <input id="kAppId" value="12">
            </div>

            <div class="form-row">
                <label>cybozu.com ログイン名</label>
                <input id="kLogin" value="admin@example.co.jp">
            </div>

            <div class="form-row">
                <label>パスワード</label>
                <input id="kPassword" type="password" value="password">
            </div>
        </div>

        <div class="toolbar">
            <button class="btn btn-primary" onclick="reloadKintoneFields()">
                項目一覧を再取得
            </button>

            <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" id="sslSkip">
                SSL証明書検証をスキップする
            </label>
        </div>

        <div id="kintoneConnectionStatus" style="margin-top:15px"></div>
    </div>

    <div class="card">
        <div class="card-title">
            <span>顧客情報フィールドマッピング</span>
            <small>kintoneの日本語項目名から選択できます</small>
        </div>

        <div class="table-wrap">
            <table class="mapping-table">
                <thead>
                <tr>
                    <th>システム項目</th>
                    <th>用途</th>
                    <th>kintone項目</th>
                </tr>
                </thead>
                <tbody id="mappingTable"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-title">同期について</div>

        <div class="sync-status">
            <span class="badge badge-blue">手動同期</span>
            <span>自動同期は行いません。必要なタイミングで項目一覧を取得してください。</span>
        </div>

        <div class="alert alert-info" style="margin-top:15px;margin-bottom:0">
            メール送信時には対象顧客の最新情報をkintoneから取得する想定です。
            Web公開フォームから未登録顧客の回答を受信した場合は、新規顧客登録処理を行います。
        </div>
    </div>
</section>

</main>


<!-- =========================================================
     共通モーダル
========================================================= -->
<div id="modal" class="modal" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="modalTitle">確認</h3>
            <button class="btn btn-sm" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <div class="modal-foot" id="modalFoot"></div>
    </div>
</div>

<div id="toast" class="toast"></div>


<script>
/* =========================================================
   モックデータ
========================================================= */

let surveys = [
    {
        id:1,
        title:"【顧客満足度調査】サービスに関するアンケート",
        created:"2026/07/25",
        updated:"2026/08/10",
        start:"2026/08/01",
        end:"2026/08/31",
        status:"公開中",
        answers:128
    },
    {
        id:2,
        title:"2026年度 新サービス利用状況調査",
        created:"2026/08/03",
        updated:"2026/08/12",
        start:"2026/09/01",
        end:"2026/09/30",
        status:"下書き",
        answers:0
    },
    {
        id:3,
        title:"2026年上期 お客様アンケート",
        created:"2026/05/01",
        updated:"2026/07/31",
        start:"2026/05/01",
        end:"2026/06/30",
        status:"終了",
        answers:312
    }
];

let customers = [
    {
        id:1,company:"株式会社サンプル商事",name:"山田 太郎",
        email:"yamada@example.co.jp",phone:"03-1234-5678",
        address:"東京都港区",
        send:"2026/08/10 10:20",count:1,status:"未回答",
        kintone:true
    },
    {
        id:2,company:"ABC株式会社",name:"佐藤 花子",
        email:"sato@example.co.jp",phone:"03-2222-3333",
        address:"東京都千代田区",
        send:"2026/08/10 10:21",count:2,status:"回答済み",
        kintone:true
    },
    {
        id:3,company:"株式会社テスト",name:"鈴木 一郎",
        email:"suzuki@example.co.jp",phone:"06-1234-5678",
        address:"大阪府大阪市",
        send:"未送信",count:0,status:"未送信",
        kintone:true
    },
    {
        id:4,company:"Web回答者",name:"田中 次郎",
        email:"tanaka@example.com",phone:"090-1111-2222",
        address:"東京都新宿区",
        send:"Web直接回答",count:0,status:"回答済み",
        kintone:false,
        web:true
    }
];

let sendLogs = [
    {
        date:"2026/08/10 10:20",
        type:"初回一括送信",
        count:2,
        subject:"【アンケートご協力のお願い】サービスに関するアンケート",
        user:"管理者"
    }
];

let answers = [
    {
        id:1,company:"株式会社サンプル商事",name:"山田 太郎",
        date:"2026/08/11 14:22",
        summary:"満足 / 検索機能, レポート / 改善：検索結果をもっと見やすくしてほしい"
    },
    {
        id:2,company:"ABC株式会社",name:"佐藤 花子",
        date:"2026/08/12 09:15",
        summary:"とても満足 / ダッシュボード, 検索機能 / 特になし"
    },
    {
        id:3,company:"Web回答企業",name:"田中 次郎",
        date:"2026/08/13 16:30",
        summary:"普通 / その他 / 「スマートフォン表示を改善してほしい」"
    }
];


/* =========================================================
   画面切替
========================================================= */

function showScreen(name){
    document.querySelectorAll(".screen").forEach(x=>x.classList.remove("active"));
    const target=document.getElementById("screen-"+name);
    if(target) target.classList.add("active");

    document.querySelectorAll(".nav button").forEach(x=>{
        x.classList.toggle("active",x.dataset.nav===name);
    });

    window.scrollTo({top:0,behavior:"smooth"});
}

document.querySelectorAll(".nav button").forEach(btn=>{
    btn.onclick=()=>{
        if(btn.dataset.nav==="list") showScreen("list");
        if(btn.dataset.nav==="kintone"){
            showScreen("kintone");
            renderMappings();
        }
    };
});


/* =========================================================
   一覧
========================================================= */

function renderSurveys(){
    const keyword=(document.getElementById("listSearch").value||"").toLowerCase();
    const status=document.getElementById("statusFilter").value;
    const sort=document.getElementById("sortFilter").value;

    let data=surveys.filter(s=>{
        return (!keyword || s.title.toLowerCase().includes(keyword))
            && (!status || s.status===status);
    });

    data.sort((a,b)=>{
        if(sort==="answers-desc") return b.answers-a.answers;
        if(sort==="answers-asc") return a.answers-b.answers;
        if(sort==="updated-asc") return a.updated.localeCompare(b.updated);
        if(sort==="start-desc") return b.start.localeCompare(a.start);
        if(sort==="start-asc") return a.start.localeCompare(b.start);
        return b.updated.localeCompare(a.updated);
    });

    document.getElementById("surveyCount").textContent=data.length+"件";

    document.getElementById("surveyTable").innerHTML=data.map(s=>`
        <tr>
            <td>
                ${s.created}<br>
                <span class="small muted">更新: ${s.updated}</span>
            </td>
            <td><strong>${escapeHtml(s.title)}</strong></td>
            <td>${s.start || "未設定"}<br>～ ${s.end || "未設定"}</td>
            <td>${statusBadge(s.status)}</td>
            <td><strong>${s.answers}</strong> 件</td>
            <td>
                <div class="actions">
                    <button class="btn btn-sm" onclick="openSurvey(${s.id})">
                        確認・編集
                    </button>

                    ${s.status!=="下書き"
                    ? `<button class="btn btn-sm" onclick="openAnalytics(${s.id})">集計</button>`
                    : ""}

                    ${s.status==="公開中"
                    ? `<button class="btn btn-sm" onclick="openSend(${s.id})">送信</button>
                       <button class="btn btn-sm btn-warning" onclick="stopSurvey(${s.id})">停止</button>`
                    : ""}

                    ${s.status==="下書き"
                    ? `<button class="btn btn-sm btn-danger" onclick="deleteSurvey(${s.id})">削除</button>`
                    : ""}

                    <button class="btn btn-sm" onclick="duplicateSurvey(${s.id})">複製</button>
                </div>
            </td>
        </tr>
    `).join("");
}

function statusBadge(status){
    if(status==="公開中") return `<span class="badge badge-public">公開中</span>`;
    if(status==="下書き") return `<span class="badge badge-draft">下書き</span>`;
    return `<span class="badge badge-end">終了</span>`;
}

function openSurvey(id){
    const s=surveys.find(x=>x.id===id);
    if(!s)return;

    document.getElementById("editorTitle").textContent=
        s.status==="下書き" ? "アンケート編集" : "アンケート詳細・編集";

    document.getElementById("editorSurveyTitle").value=s.title;
    document.getElementById("editorStart").value=s.start+"T09:00";
    document.getElementById("editorEnd").value=s.end+"T18:00";

    showScreen("editor");
    renderEditorGroups();
}

function duplicateSurvey(id){
    const s=surveys.find(x=>x.id===id);
    const copy={
        ...s,
        id:Date.now(),
        title:s.title+"（複製）",
        status:"下書き",
        answers:0,
        created:"2026/08/24",
        updated:"2026/08/24"
    };

    surveys.unshift(copy);
    renderSurveys();
    showToast("アンケートを複製しました。下書きとして追加されています。");
}

function stopSurvey(id){
    const s=surveys.find(x=>x.id===id);

    openConfirm(
        "アンケートを停止しますか？",
        `<p><strong>${escapeHtml(s.title)}</strong></p>
         <p class="muted">停止すると回答受付を停止します。詳細画面から再開できます。</p>`,
        ()=>{
            s.status="終了";
            renderSurveys();
            showToast("アンケートを停止しました");
        }
    );
}

function deleteSurvey(id){
    const s=surveys.find(x=>x.id===id);

    openConfirm(
        "アンケートを削除しますか？",
        `<p><strong>${escapeHtml(s.title)}</strong></p>
         <p class="muted">論理削除として扱います。</p>`,
        ()=>{
            surveys=surveys.filter(x=>x.id!==id);
            renderSurveys();
            showToast("アンケートを削除しました");
        }
    );
}


/* =========================================================
   アンケート編集
========================================================= */

let editorDirty=false;

let editorGroups=[
    {
        id:1,
        title:"基本情報について",
        questions:[
            {
                id:101,
                title:"サービス全体の満足度を教えてください",
                type:"radio",
                required:true,
                options:["とても満足","満足","普通","不満","とても不満"]
            },
            {
                id:102,
                title:"利用した機能を教えてください",
                type:"checkbox",
                required:false,
                options:["ダッシュボード","検索機能","レポート","その他"]
            }
        ]
    },
    {
        id:2,
        title:"ご意見・ご要望",
        questions:[
            {
                id:103,
                title:"改善してほしい点を教えてください",
                type:"textarea",
                required:false,
                options:[]
            }
        ]
    }
];

function openEditor(){
    editorDirty=false;
    document.getElementById("editorTitle").textContent="アンケート作成";
    document.getElementById("editorSurveyTitle").value=
        "【顧客満足度調査】サービスに関するアンケート";
    document.getElementById("editorStart").value="2026-09-01T09:00";
    document.getElementById("editorEnd").value="2026-09-30T18:00";

    editorGroups=[
        {
            id:Date.now(),
            title:"基本情報",
            questions:[
                {
                    id:Date.now()+1,
                    title:"サービス全体の満足度を教えてください",
                    type:"radio",
                    required:true,
                    options:["とても満足","満足","普通"]
                }
            ]
        }
    ];

    showScreen("editor");
    renderEditorGroups();
}

function renderEditorGroups(){
    const container=document.getElementById("editorGroups");

    let qNo=0;

    container.innerHTML=editorGroups.map((g,gi)=>`
        <div class="group"
             draggable="true"
             data-group-id="${g.id}"
             ondragstart="dragGroupStart(event,${g.id})"
             ondragover="event.preventDefault()"
             ondrop="dropGroup(event,${g.id})">

            <div class="group-head">
                <span class="drag">⠿</span>

                <input value="${escapeAttr(g.title)}"
                       onchange="editorGroups[${gi}].title=this.value;editorDirty=true">

                <button class="btn btn-sm"
                        onclick="addQuestion(${gi})">＋ 質問追加</button>

                <button class="btn btn-sm btn-danger"
                        onclick="deleteGroup(${gi})">グループ削除</button>
            </div>

            <div class="group-body"
                 data-group="${g.id}"
                 ondragover="event.preventDefault()"
                 ondrop="dropQuestion(event,${gi})">

                ${g.questions.map((q,qi)=>{
                    qNo++;

                    return `
                    <div class="question"
                         draggable="true"
                         data-question-id="${q.id}"
                         ondragstart="dragQuestionStart(event,${q.id},${gi})">

                        <div class="q-head">
                            <span class="drag">⠿</span>
                            <span class="q-no">Q${qNo}.</span>

                            <input class="q-title-input"
                                   value="${escapeAttr(q.title)}"
                                   onchange="updateQuestionTitle(${gi},${qi},this.value)">

                            <select onchange="changeQuestionType(${gi},${qi},this.value)">
                                <option value="radio" ${q.type==="radio"?"selected":""}>単一選択</option>
                                <option value="checkbox" ${q.type==="checkbox"?"selected":""}>複数選択</option>
                                <option value="textarea" ${q.type==="textarea"?"selected":""}>自由記述</option>
                            </select>

                            <button class="btn btn-sm btn-danger"
                                    onclick="deleteQuestion(${gi},${qi})">
                                削除
                            </button>
                        </div>

                        <div style="margin:13px 0 0 30px">
                            ${q.type==="textarea"
                            ? `<textarea placeholder="回答者の入力欄（プレビューで表示）" disabled></textarea>`
                            : `
                                <div>
                                    ${q.options.map((op,oi)=>`
                                        <div class="option-row">
                                            <span style="padding-top:10px">
                                                ${q.type==="radio"?"◯":"□"}
                                            </span>
                                            <input value="${escapeAttr(op)}"
                                                   onchange="updateOption(${gi},${qi},${oi},this.value)">
                                            <button class="btn btn-sm"
                                                    onclick="deleteOption(${gi},${qi},${oi})">
                                                ×
                                            </button>
                                        </div>
                                    `).join("")}

                                    <button class="btn btn-sm"
                                            onclick="addOption(${gi},${qi})">
                                        ＋ 選択肢を追加
                                    </button>
                                </div>
                            `}

                            <label style="display:block;margin-top:12px">
                                <input type="checkbox"
                                    ${q.required?"checked":""}
                                    onchange="editorGroups[${gi}].questions[${qi}].required=this.checked;editorDirty=true">
                                必須回答
                            </label>

                            ${q.type==="radio"
                            ? `
                              <div style="margin-top:12px">
                                <label class="small muted">回答による分岐</label>
                                <select>
                                  <option>分岐なし</option>
                                  <option>「不満」→ Q3へ</option>
                                  <option>「とても満足」→ Q4へ</option>
                                </select>
                              </div>`
                            : ""}
                        </div>
                    </div>
                    `;
                }).join("")}

                ${g.questions.length===0
                    ? `<div class="empty">ここに質問をドラッグできます</div>`
                    : ""}
            </div>
        </div>
    `).join("");
}

function addGroup(){
    editorGroups.push({
        id:Date.now(),
        title:"新しいグループ",
        questions:[]
    });
    editorDirty=true;
    renderEditorGroups();
}

function deleteGroup(index){
    openConfirm(
        "グループを削除しますか？",
        `<p>グループ内の質問もすべて削除されます。</p>`,
        ()=>{
            editorGroups.splice(index,1);
            editorDirty=true;
            renderEditorGroups();
        }
    );
}

function addQuestion(groupIndex){
    editorGroups[groupIndex].questions.push({
        id:Date.now(),
        title:"新しい質問",
        type:"radio",
        required:false,
        options:["選択肢1","選択肢2"]
    });
    editorDirty=true;
    renderEditorGroups();
}

function deleteQuestion(gi,qi){
    editorGroups[gi].questions.splice(qi,1);
    editorDirty=true;
    renderEditorGroups();
}

function updateQuestionTitle(gi,qi,value){
    editorGroups[gi].questions[qi].title=value;
    editorDirty=true;
}

function changeQuestionType(gi,qi,type){
    editorGroups[gi].questions[qi].type=type;
    if(type==="textarea"){
        editorGroups[gi].questions[qi].options=[];
    }else if(!editorGroups[gi].questions[qi].options.length){
        editorGroups[gi].questions[qi].options=["選択肢1","選択肢2"];
    }
    editorDirty=true;
    renderEditorGroups();
}

function addOption(gi,qi){
    editorGroups[gi].questions[qi].options.push("新しい選択肢");
    editorDirty=true;
    renderEditorGroups();
}

function deleteOption(gi,qi,oi){
    editorGroups[gi].questions[qi].options.splice(oi,1);
    editorDirty=true;
    renderEditorGroups();
}

function updateOption(gi,qi,oi,value){
    editorGroups[gi].questions[qi].options[oi]=value;
    editorDirty=true;
}


/* =========================================================
   Drag & Drop
========================================================= */

let dragGroupId=null;
let dragQuestionId=null;
let dragQuestionFromGroup=null;

function dragGroupStart(e,id){
    dragGroupId=id;
    e.dataTransfer.effectAllowed="move";
}

function dropGroup(e,targetId){
    e.preventDefault();

    if(dragGroupId===targetId)return;

    const from=editorGroups.findIndex(g=>g.id===dragGroupId);
    const to=editorGroups.findIndex(g=>g.id===targetId);

    const [item]=editorGroups.splice(from,1);
    editorGroups.splice(to,0,item);

    dragGroupId=null;
    editorDirty=true;
    renderEditorGroups();
}

function dragQuestionStart(e,qid,groupIndex){
    dragQuestionId=qid;
    dragQuestionFromGroup=groupIndex;
    e.dataTransfer.effectAllowed="move";
}

function dropQuestion(e,targetGroupIndex){
    e.preventDefault();

    if(!dragQuestionId)return;

    const fromGroup=editorGroups[dragQuestionFromGroup];
    const qIndex=fromGroup.questions.findIndex(q=>q.id===dragQuestionId);

    if(qIndex<0)return;

    const [question]=fromGroup.questions.splice(qIndex,1);

    editorGroups[targetGroupIndex].questions.push(question);

    dragQuestionId=null;
    dragQuestionFromGroup=null;

    editorDirty=true;
    renderEditorGroups();

    showToast("質問を移動しました。Q番号を自動更新しました。");
}


/* =========================================================
   プレビュー
========================================================= */

function openPreview(){
    const title=document.getElementById("editorSurveyTitle").value;

    let html=`
        <div class="preview">
            <div id="previewDevice" class="preview-device pc">
                <h2>${escapeHtml(title)}</h2>
                <p class="muted">回答者向けプレビュー</p>
    `;

    editorGroups.forEach((g,gi)=>{
        html+=`
            <div style="margin-top:25px">
                <h3>${escapeHtml(g.title)}</h3>
        `;

        g.questions.forEach((q,i)=>{
            html+=`
                <div class="preview-q">
                    <strong>${escapeHtml(q.title)}
                    ${q.required ? '<span class="required"> *</span>' : ''}
                    </strong>
            `;

            if(q.type==="radio"){
                q.options.forEach(op=>{
                    html+=`
                        <label>
                            <input type="radio" name="preview-${q.id}">
                            ${escapeHtml(op)}
                        </label>
                    `;
                });
            }else if(q.type==="checkbox"){
                q.options.forEach(op=>{
                    html+=`
                        <label>
                            <input type="checkbox">
                            ${escapeHtml(op)}
                        </label>
                    `;
                });
            }else{
                html+=`<textarea placeholder="回答を入力してください"></textarea>`;
            }

            html+=`</div>`;
        });

        html+=`</div>`;
    });

    html+=`
                <div class="preview-submit">
                    <button class="btn btn-primary"
                            onclick="showToast('※これはプレビュー表示のため送信されません')">
                        送信
                    </button>
                </div>
            </div>
        </div>
    `;

    openModal(
        "アンケートプレビュー",
        html,
        `
        <button class="btn" onclick="setPreviewDevice('pc')">PC表示</button>
        <button class="btn" onclick="setPreviewDevice('mobile')">スマートフォン表示</button>
        <button class="btn btn-primary" onclick="closeModal()">閉じる</button>
        `
    );
}

function setPreviewDevice(type){
    const el=document.getElementById("previewDevice");
    if(el) el.className="preview-device "+type;
}


/* =========================================================
   保存・キャンセル
========================================================= */

function saveSurvey(){
    const title=document.getElementById("editorSurveyTitle").value.trim();

    if(!title){
        showToast("アンケートタイトルを入力してください");
        return;
    }

    showToast("保存中...");

    setTimeout(()=>{
        surveys.unshift({
            id:Date.now(),
            title:title,
            created:"2026/08/24",
            updated:"2026/08/24",
            start:document.getElementById("editorStart").value.slice(0,10).replaceAll("-","/"),
            end:document.getElementById("editorEnd").value.slice(0,10).replaceAll("-","/"),
            status:"下書き",
            answers:0
        });

        editorDirty=false;
        renderSurveys();
        showScreen("list");
        showToast("保存しました。アンケート一覧へ戻ります。");
    },700);
}

function cancelEditor(){
    if(editorDirty){
        openConfirm(
            "変更を破棄しますか？",
            `<p>保存されていない変更は失われます。</p>`,
            ()=>{
                editorDirty=false;
                showScreen("list");
            }
        );
    }else{
        showScreen("list");
    }
}


/* =========================================================
   顧客送信
========================================================= */

let customerKeyword="";
let customerStatus="";

function openSend(id){
    const s=surveys.find(x=>x.id===id);

    document.getElementById("sendSurveyName").textContent=
        s.title+" / 顧客宛先選択・メール送信";

    renderCustomers();
    renderSendLogs();
    showScreen("send");
}

function renderCustomers(){
    let data=customers.filter(c=>{
        const str=(c.company+c.name+c.email).toLowerCase();
        return (!customerKeyword || str.includes(customerKeyword.toLowerCase()))
            && (!customerStatus || c.status===customerStatus);
    });

    document.getElementById("customerTable").innerHTML=data.map(c=>`
        <tr>
            <td>
                ${c.web
                    ? `<span class="small muted">Web直接回答<br>選択不可</span>`
                    : `<input type="checkbox"
                              class="customer-check"
                              value="${c.id}"
                              ${c.status==="回答済み"?"":"checked"}>`
                }
            </td>

            <td>
                <strong>${escapeHtml(c.company)}</strong><br>
                ${escapeHtml(c.name)}<br>
                <span class="small muted">${escapeHtml(c.email)}</span><br>
                <span class="small muted">${escapeHtml(c.phone)} / ${escapeHtml(c.address)}</span>
            </td>

            <td>
                ${c.send==="未送信"
                    ? `<span class="muted">未送信</span>`
                    : `<span class="small">${c.send}</span><br>
                       <span class="small">${c.count}回送信</span><br>
                       <span class="link" onclick="viewCustomerMail(${c.id})">送信文を確認</span>`
                }
            </td>

            <td>
                ${c.status==="回答済み"
                    ? `<span class="badge badge-public">回答済み</span>`
                    : c.status==="未送信"
                    ? `<span class="badge badge-gray">未送信</span>`
                    : `<span class="badge badge-draft">送信済み（未回答）</span>`
                }
            </td>

            <td>
                ${c.kintone
                    ? `<span style="color:#059669;font-weight:600">✓ キントーン登録完了</span>`
                    : `<span class="badge badge-red">未登録</span>
                       <br><button class="btn btn-sm btn-success"
                          style="margin-top:6px"
                          onclick="completeKintone(${c.id})">
                          キントーン登録完了
                       </button>`
                }
            </td>
        </tr>
    `).join("");
}

function toggleAllCustomers(checked){
    document.querySelectorAll(".customer-check").forEach(x=>{
        x.checked=checked;
    });
}

function filterCustomers(value){
    customerKeyword=value;
    renderCustomers();
}

function filterCustomerStatus(value){
    customerStatus=value;
    renderCustomers();
}

function completeKintone(id){
    const c=customers.find(x=>x.id===id);

    c.kintone=true;

    renderCustomers();

    const unregistered=customers.filter(x=>!x.kintone).length;

    document.getElementById("kintoneAlert").innerHTML=
        unregistered
        ? `⚠ キントーン未登録の回答者が <strong>${unregistered}名</strong> います。`
        : `✓ キントーン未登録の回答者はいません。`;

    document.getElementById("kintoneAlert").className=
        unregistered ? "alert alert-warning" : "alert alert-success";

    showToast("キントーン登録完了として更新しました");
}

function executeBulkSend(){
    const selected=[...document.querySelectorAll(".customer-check:checked")]
        .map(x=>Number(x.value));

    if(!selected.length){
        showToast("送信対象を選択してください");
        return;
    }

    const alreadySent=selected
        .map(id=>customers.find(c=>c.id===id))
        .filter(c=>c.count>0);

    if(alreadySent.length){
        openConfirm(
            "既に送信済みの宛先が含まれています。",
            `<p>${alreadySent.length}名はすでに送信済みです。</p>
             <p>再送しますか？</p>`,
            ()=>doSend(selected),
            "再送する"
        );
    }else{
        openConfirm(
            "メールを一括送信しますか？",
            `<p><strong>${selected.length}名</strong>にメールを送信します。</p>
             <p class="muted">送信時にはkintoneから最新顧客情報を取得する想定です。</p>`,
            ()=>doSend(selected),
            "送信する"
        );
    }
}

function doSend(ids){
    const now=new Date().toLocaleString("ja-JP");

    ids.forEach(id=>{
        const c=customers.find(x=>x.id===id);
        if(!c)return;

        c.send=now;
        c.count++;
        c.status="未回答";
    });

    sendLogs.unshift({
        date:now,
        type:"一括送信",
        count:ids.length,
        subject:document.getElementById("mailSubject").value,
        user:"管理者"
    });

    renderCustomers();
    renderSendLogs();

    showToast(ids.length+"件のメール送信が完了しました");
}

function renderSendLogs(){
    document.getElementById("sendLogTable").innerHTML=sendLogs.map((log,i)=>`
        <tr>
            <td>${log.date}</td>
            <td><span class="badge badge-blue">${log.type}</span></td>
            <td>${log.count}件</td>
            <td>${escapeHtml(log.subject)}</td>
            <td>${escapeHtml(log.user)}</td>
            <td>
                <button class="btn btn-sm"
                        onclick="viewLog(${i})">
                    送信文を確認
                </button>
            </td>
        </tr>
    `).join("");
}

function viewCustomerMail(id){
    const c=customers.find(x=>x.id===id);

    const body=document.getElementById("mailBody").value
        .replaceAll("{顧客名}",c.name)
        .replaceAll("{アンケートURL}",
            "https://example.com/survey/personal/"+c.id);

    const subject=document.getElementById("mailSubject").value
        .replaceAll("{顧客名}",c.name);

    openModal(
        "送信済みメール",
        `
        <div class="form-row">
            <label>送信先</label>
            <div>${escapeHtml(c.email)}</div>
        </div>
        <div class="form-row">
            <label>件名</label>
            <div style="padding:12px;background:#f8fafc;border-radius:7px">
                ${escapeHtml(subject)}
            </div>
        </div>
        <div class="form-row">
            <label>本文</label>
            <div style="white-space:pre-wrap;background:#f8fafc;padding:15px;border-radius:7px">
                ${escapeHtml(body)}
            </div>
        </div>
        `,
        `<button class="btn btn-primary" onclick="closeModal()">閉じる</button>`
    );
}

function viewLog(index){
    const log=sendLogs[index];

    openModal(
        "送信履歴詳細",
        `
        <p><strong>送信日時：</strong>${log.date}</p>
        <p><strong>送信種別：</strong>${log.type}</p>
        <p><strong>送信件数：</strong>${log.count}件</p>
        <p><strong>実行者：</strong>${log.user}</p>
        <hr>
        <p><strong>件名</strong></p>
        <div style="background:#f8fafc;padding:12px;border-radius:7px">
            ${escapeHtml(log.subject)}
        </div>
        <p style="margin-top:15px"><strong>本文テンプレート</strong></p>
        <div style="white-space:pre-wrap;background:#f8fafc;padding:12px;border-radius:7px">
            ${escapeHtml(document.getElementById("mailBody").value)}
        </div>
        `,
        `<button class="btn btn-primary" onclick="closeModal()">閉じる</button>`
    );
}


/* =========================================================
   集計・分析
========================================================= */

function openAnalytics(id){
    const s=surveys.find(x=>x.id===id);

    document.getElementById("analyticsSurveyName").textContent=s.title;

    renderAnalytics();
    renderAnswers();

    showScreen("analytics");
}

function renderAnalytics(){
    const checks=[...document.querySelectorAll(".question-filter")];

    let html="";

    if(checks[0]?.checked){
        html+=`
        <div class="card">
            <div class="card-title">
                <span>グループ：基本情報</span>
            </div>

            <h3>Q1. サービス全体の満足度を教えてください</h3>

            <div class="chart">
                ${bar("とても満足",45,58)}
                ${bar("満足",35,45)}
                ${bar("普通",15,19)}
                ${bar("不満",4,5)}
                ${bar("とても不満",1,1)}
            </div>
        </div>
        `;
    }

    if(checks[1]?.checked){
        html+=`
        <div class="card">
            <div class="card-title">
                <span>グループ：基本情報</span>
            </div>

            <h3>Q2. 利用した機能を教えてください</h3>

            <div class="chart">
                ${bar("ダッシュボード",70,90)}
                ${bar("検索機能",55,70)}
                ${bar("レポート",38,49)}
                ${bar("その他",8,10)}
            </div>

            <div class="other-box">
                <strong>その他：10件</strong>
                <span class="badge badge-blue">自由記述 7件</span>

                <div class="timeline" style="margin-top:10px">
                    <div class="comment">
                        <strong>株式会社サンプル商事 / 山田 太郎</strong><br>
                        <span class="muted">スマートフォン機能</span>
                    </div>
                    <div class="comment">
                        <strong>Web回答企業 / 田中 次郎</strong><br>
                        <span class="muted">API連携</span>
                    </div>
                    <div class="comment">
                        <strong>ABC株式会社 / 佐藤 花子</strong><br>
                        <span class="muted">通知機能</span>
                    </div>
                </div>
            </div>
        </div>
        `;
    }

    if(checks[2]?.checked){
        html+=`
        <div class="card">
            <div class="card-title">
                <span>グループ：ご意見・ご要望</span>
            </div>

            <h3>Q3. 改善してほしい点を教えてください</h3>

            <div class="timeline">
                <div class="comment">
                    <strong>株式会社サンプル商事 / 山田 太郎</strong>
                    <div style="margin-top:6px">
                        検索結果をもっと見やすくしてほしい
                    </div>
                    <div class="small muted">2026/08/11 14:22</div>
                </div>

                <div class="comment">
                    <strong>ABC株式会社 / 佐藤 花子</strong>
                    <div style="margin-top:6px">
                        特になし。とても使いやすいです。
                    </div>
                    <div class="small muted">2026/08/12 09:15</div>
                </div>

                <div class="comment">
                    <strong>Web回答企業 / 田中 次郎</strong>
                    <div style="margin-top:6px">
                        スマートフォン表示を改善してほしい
                    </div>
                    <div class="small muted">2026/08/13 16:30</div>
                </div>
            </div>
        </div>
        `;
    }

    if(!html){
        html=`
        <div class="card">
            <div class="empty">
                表示する設問を選択してください。
            </div>
        </div>
        `;
    }

    document.getElementById("analyticsCharts").innerHTML=html;
}

function bar(label,percent,count){
    return `
        <div class="bar-row">
            <div>${label}</div>
            <div>
                <div class="bar" style="width:${percent}%"></div>
            </div>
            <div>${percent}% / ${count}件</div>
        </div>
    `;
}

function selectQuestions(value){
    document.querySelectorAll(".question-filter").forEach(x=>{
        x.checked=value;
    });
    renderAnalytics();
}

function renderAnswers(data=answers){
    document.getElementById("answerTable").innerHTML=data.map(a=>`
        <tr>
            <td>
                <strong>${escapeHtml(a.company)}</strong><br>
                ${escapeHtml(a.name)}
            </td>
            <td>${a.date}</td>
            <td>${escapeHtml(a.summary)}</td>
            <td>
                <button class="btn btn-sm"
                        onclick="viewAnswer(${a.id})">
                    全回答を表示
                </button>
            </td>
        </tr>
    `).join("");
}

function filterAnswers(value){
    const keyword=value.toLowerCase();

    renderAnswers(
        answers.filter(a=>
            (a.company+a.name).toLowerCase().includes(keyword)
        )
    );
}

function viewAnswer(id){
    const a=answers.find(x=>x.id===id);

    openModal(
        "全回答",
        `
        <div class="card" style="margin:0">
            <strong>${escapeHtml(a.company)}</strong><br>
            ${escapeHtml(a.name)}<br>
            <span class="small muted">${a.date}</span>
        </div>

        <div style="margin-top:15px">
            <div class="form-row">
                <label>Q1. サービス全体の満足度</label>
                <div>満足</div>
            </div>

            <div class="form-row">
                <label>Q2. 利用した機能</label>
                <div>ダッシュボード、検索機能</div>
            </div>

            <div class="form-row">
                <label>Q3. 改善してほしい点</label>
                <div>検索結果をもっと見やすくしてほしい</div>
            </div>
        </div>
        `,
        `<button class="btn btn-primary" onclick="closeModal()">閉じる</button>`
    );
}

function downloadCSV(){
    const rows=[
        ["回答ID","回答日時","顧客ID","会社名","氏名","設問1","設問2","設問3"],
        [1,"2026/08/11 14:22",1,"株式会社サンプル商事","山田 太郎","満足","検索機能","検索結果をもっと見やすくしてほしい"],
        [2,"2026/08/12 09:15",2,"ABC株式会社","佐藤 花子","とても満足","ダッシュボード","特になし"]
    ];

    const csv="\uFEFF"+rows.map(row=>
        row.map(v=>`"${String(v).replaceAll('"','""')}"`).join(",")
    ).join("\n");

    const blob=new Blob([csv],{type:"text/csv;charset=utf-8"});
    const url=URL.createObjectURL(blob);
    const a=document.createElement("a");

    a.href=url;
    a.download="survey_answers.csv";
    a.click();

    URL.revokeObjectURL(url);

    showToast("UTF-8 BOM付きCSVをダウンロードしました");
}

function exportPDF(){
    showToast("PDF出力用の印刷画面を開きます（モック）");

    setTimeout(()=>{
        window.print();
    },400);
}


/* =========================================================
   kintone設定
========================================================= */

let kintoneFields=[
    {label:"会社名",code:"company_name"},
    {label:"氏名",code:"name"},
    {label:"メールアドレス",code:"email"},
    {label:"部署名",code:"department"},
    {label:"電話番号",code:"phone"},
    {label:"郵便番号",code:"zip"},
    {label:"都道府県",code:"prefecture"},
    {label:"住所",code:"address"}
];

let mappings={
    company:"会社名",
    name:"氏名",
    email:"メールアドレス",
    department:"部署名",
    phone:"電話番号",
    address:"住所"
};

function renderMappings(){
    const rows=[
        ["会社名 (Company)","顧客の所属企業・団体名","company"],
        ["氏名 (Name)","顧客の担当者氏名","name"],
        ["メールアドレス (Email)","案内送信・顧客検索キー","email"],
        ["部署名 (Department)","所属部署・役職","department"],
        ["電話番号 (Phone)","連絡先電話番号","phone"],
        ["住所 (Address)","郵便番号・所在地・送付先住所","address"]
    ];

    document.getElementById("mappingTable").innerHTML=rows.map(r=>`
        <tr>
            <td>${r[0]}</td>
            <td>${r[1]}</td>
            <td>
                <select style="width:100%"
                        onchange="mappings['${r[2]}']=this.value">
                    <option value="">-- 項目を選択 --</option>

                    ${kintoneFields.map(f=>`
                        <option
                            value="${escapeAttr(f.label)}"
                            ${mappings[r[2]]===f.label?"selected":""}>
                            ${escapeHtml(f.label)}
                        </option>
                    `).join("")}
                </select>

                ${r[2]==="address"
                ? `<div class="small muted" style="margin-top:6px">
                    複数項目が必要な場合は、住所関連項目を複数指定できます。
                   </div>`
                : ""}
            </td>
        </tr>
    `).join("");
}

function reloadKintoneFields(){
    const status=document.getElementById("kintoneConnectionStatus");

    status.innerHTML=`
        <div class="alert alert-info">
            kintoneへ接続して項目一覧を取得しています...
        </div>
    `;

    setTimeout(()=>{
        kintoneFields=[
            {label:"会社名",code:"company_name"},
            {label:"氏名",code:"customer_name"},
            {label:"メールアドレス",code:"mail"},
            {label:"部署",code:"department"},
            {label:"電話番号",code:"tel"},
            {label:"郵便番号",code:"postal_code"},
            {label:"都道府県",code:"prefecture"},
            {label:"住所",code:"address"}
        ];

        renderMappings();

        status.innerHTML=`
            <div class="alert alert-success">
                ✓ 項目一覧を取得しました。8項目が見つかりました。
            </div>
        `;

        showToast("kintoneの項目一覧を再取得しました");
    },900);
}

function saveKintone(){
    const sub=document.getElementById("kSubdomain").value;

    if(!sub){
        showToast("サブドメインを入力してください");
        return;
    }

    openConfirm(
        "kintone連携設定を保存しますか？",
        `
        <p>以下の設定を保存します。</p>
        <ul>
            <li>サブドメイン：${escapeHtml(sub)}.cybozu.com</li>
            <li>アプリID：${escapeHtml(document.getElementById("kAppId").value)}</li>
            <li>SSL検証スキップ：${document.getElementById("sslSkip").checked?"有効":"無効"}</li>
        </ul>
        `,
        ()=>{
            showToast("kintone連携設定を保存しました");
        },
        "保存する"
    );
}


/* =========================================================
   モーダル
========================================================= */

function openModal(title,body,footer){
    document.getElementById("modalTitle").textContent=title;
    document.getElementById("modalBody").innerHTML=body;
    document.getElementById("modalFoot").innerHTML=footer||"";
    document.getElementById("modal").classList.add("show");
}

function openConfirm(title,body,onOk,okText="実行する"){
    openModal(
        title,
        body,
        `
        <button class="btn" onclick="closeModal()">キャンセル</button>
        <button class="btn btn-primary"
                id="confirmExecute">
            ${okText}
        </button>
        `
    );

    document.getElementById("confirmExecute").onclick=()=>{
        closeModal();
        onOk();
    };
}

function closeModal(){
    document.getElementById("modal").classList.remove("show");
}


/* =========================================================
   Toast
========================================================= */

let toastTimer;

function showToast(message){
    const toast=document.getElementById("toast");

    toast.textContent=message;
    toast.classList.add("show");

    clearTimeout(toastTimer);

    toastTimer=setTimeout(()=>{
        toast.classList.remove("show");
    },2800);
}


/* =========================================================
   Utility
========================================================= */

function escapeHtml(str){
    return String(str??"")
        .replaceAll("&","&amp;")
        .replaceAll("<","&lt;")
        .replaceAll(">","&gt;")
        .replaceAll('"',"&quot;")
        .replaceAll("'","&#039;");
}

function escapeAttr(str){
    return escapeHtml(str);
}


/* =========================================================
   初期化
========================================================= */

renderSurveys();
renderCustomers();
renderSendLogs();
renderAnswers();
renderMappings();

</script>
</body>
</html>
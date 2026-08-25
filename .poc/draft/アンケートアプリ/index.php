<?php
/**
 * index.php
 * アンケート管理システム 動作モック
 *
 * PHP単体で配置可能なフロントエンド中心のモック。
 * DB / kintone API / メール送信APIは未接続。
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム</title>

<style>
*{box-sizing:border-box}
body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",
                 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
    background:#f5f7fb;
    color:#1f2937;
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}

.app-header{
    height:64px;
    background:#172033;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    position:sticky;
    top:0;
    z-index:100;
}
.logo{
    font-size:18px;
    font-weight:700;
    margin-right:40px;
}
.nav{
    display:flex;
    gap:4px;
    height:100%;
}
.nav button{
    border:0;
    background:transparent;
    color:#cbd5e1;
    padding:0 18px;
}
.nav button:hover,
.nav button.active{
    color:#fff;
    background:#253149;
}
.nav-spacer{flex:1}
.logout{
    border:1px solid #475569;
    background:transparent;
    color:#fff;
    padding:8px 14px;
    border-radius:7px;
}

.container{
    max-width:1500px;
    margin:0 auto;
    padding:28px;
}

.page{display:none}
.page.active{display:block}

.page-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    margin-bottom:22px;
}
.page-title{
    font-size:26px;
    font-weight:700;
    margin:0;
}
.page-description{
    color:#64748b;
    margin-top:5px;
}

.btn{
    border:1px solid #d1d5db;
    background:#fff;
    color:#374151;
    padding:9px 14px;
    border-radius:7px;
    transition:.15s;
}
.btn:hover{background:#f8fafc}
.btn-primary{
    background:#2563eb;
    border-color:#2563eb;
    color:#fff;
}
.btn-primary:hover{background:#1d4ed8}
.btn-success{
    background:#16a34a;
    border-color:#16a34a;
    color:#fff;
}
.btn-danger{
    background:#dc2626;
    border-color:#dc2626;
    color:#fff;
}
.btn-warning{
    background:#f59e0b;
    border-color:#f59e0b;
    color:#fff;
}
.btn-sm{
    padding:6px 9px;
    font-size:12px;
}
.btn-icon{
    width:36px;
    height:36px;
    padding:0;
}

.card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(15,23,42,.04);
    margin-bottom:20px;
}
.card-header{
    padding:17px 20px;
    border-bottom:1px solid #edf0f4;
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.card-body{padding:20px}

.toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
}
.search{
    min-width:300px;
    padding:10px 13px;
    border:1px solid #d1d5db;
    border-radius:7px;
}
select,
.form-control{
    border:1px solid #d1d5db;
    border-radius:7px;
    padding:9px 11px;
    background:#fff;
}
textarea.form-control{
    min-height:130px;
    resize:vertical;
    width:100%;
}

.table-wrap{
    overflow-x:auto;
}
table{
    width:100%;
    border-collapse:collapse;
    min-width:1100px;
}
th,td{
    border-bottom:1px solid #edf0f4;
    padding:14px 12px;
    text-align:left;
    vertical-align:middle;
}
th{
    background:#f8fafc;
    font-size:12px;
    color:#64748b;
    white-space:nowrap;
}
td{font-size:13px}
tr:hover td{background:#fafcff}

.title-cell{
    font-weight:700;
    min-width:240px;
}
.date-cell{
    white-space:nowrap;
    color:#64748b;
}
.period{
    white-space:nowrap;
}
.actions{
    display:flex;
    gap:5px;
    flex-wrap:wrap;
    min-width:270px;
}

.badge{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:4px 9px;
    font-size:11px;
    font-weight:700;
}
.badge-public{background:#dcfce7;color:#166534}
.badge-draft{background:#f1f5f9;color:#475569}
.badge-ended{background:#fee2e2;color:#991b1b}
.badge-answer{background:#dbeafe;color:#1d4ed8}
.badge-warning{background:#fef3c7;color:#92400e}
.badge-success{background:#dcfce7;color:#166534}

.empty{
    padding:60px 20px;
    text-align:center;
    color:#94a3b8;
}

/* ダッシュボード */
.stats{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:15px;
    margin-bottom:20px;
}
.stat{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:10px;
    padding:18px;
}
.stat-label{font-size:12px;color:#64748b}
.stat-value{
    font-size:26px;
    font-weight:700;
    margin-top:7px;
}
.stat-sub{
    color:#64748b;
    font-size:11px;
    margin-top:3px;
}

/* 作成画面 */
.editor-title{
    width:100%;
    border:0;
    border-bottom:2px solid #e5e7eb;
    padding:12px 4px;
    font-size:22px;
    font-weight:700;
    outline:none;
}
.editor-title:focus{border-color:#2563eb}

.group{
    border:1px solid #dbe2ea;
    border-radius:10px;
    margin-bottom:16px;
    background:#fff;
}
.group-header{
    background:#f8fafc;
    padding:12px 15px;
    display:flex;
    gap:10px;
    align-items:center;
    border-bottom:1px solid #e5e7eb;
}
.drag-handle{
    cursor:grab;
    color:#94a3b8;
    font-size:20px;
}
.group-title{
    flex:1;
    border:0;
    background:transparent;
    font-weight:700;
    outline:none;
}
.questions{padding:12px}
.question{
    border:1px solid #e5e7eb;
    border-radius:9px;
    padding:15px;
    margin-bottom:10px;
    background:#fff;
}
.question.dragging,
.group.dragging{
    opacity:.45;
    border:2px dashed #2563eb;
}
.question-top{
    display:flex;
    align-items:center;
    gap:8px;
}
.question-number{
    font-weight:700;
    color:#2563eb;
    min-width:38px;
}
.question-text{
    flex:1;
    padding:9px;
    border:1px solid #d1d5db;
    border-radius:6px;
}
.question-settings{
    display:grid;
    grid-template-columns:1fr 180px auto;
    gap:10px;
    margin-top:10px;
}
.options{
    margin-top:10px;
    padding-left:45px;
}
.option-row{
    display:flex;
    gap:8px;
    margin-bottom:7px;
}
.option-row input{flex:1}

.add-area{
    display:flex;
    gap:10px;
    padding:10px 0;
}

.preview-frame{
    background:#fff;
    border-radius:10px;
    padding:25px;
    max-width:720px;
    margin:auto;
}
.preview-question{
    margin-bottom:25px;
}
.preview-question h4{
    margin:0 0 10px;
}
.preview-option{
    display:block;
    padding:9px;
}

/* 顧客送信 */
.alert{
    padding:14px 16px;
    border-radius:8px;
    margin-bottom:18px;
}
.alert-warning{
    background:#fffbeb;
    border:1px solid #fde68a;
    color:#92400e;
}
.alert-info{
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1e40af;
}
.template-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
}
.mail-preview{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    padding:18px;
    border-radius:8px;
    white-space:pre-wrap;
    min-height:200px;
}

/* 集計 */
.chart{
    padding:5px 0;
}
.bar-row{
    display:grid;
    grid-template-columns:220px 1fr 60px;
    align-items:center;
    gap:10px;
    margin:11px 0;
}
.bar-bg{
    height:20px;
    background:#e5e7eb;
    border-radius:4px;
    overflow:hidden;
}
.bar{
    height:100%;
    background:#3b82f6;
}
.filter-questions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.question-filter{
    padding:7px 10px;
    border:1px solid #d1d5db;
    border-radius:7px;
    background:#fff;
}
.question-filter.active{
    background:#eff6ff;
    border-color:#60a5fa;
    color:#1d4ed8;
}

.answer-detail{
    max-height:60vh;
    overflow:auto;
}
.answer-item{
    border-bottom:1px solid #e5e7eb;
    padding:14px 0;
}
.answer-item:last-child{border-bottom:0}

/* 設定 */
.settings-grid{
    display:grid;
    grid-template-columns:260px 1fr;
    gap:15px;
    align-items:center;
}
.mapping-table{
    width:100%;
    min-width:0;
}
.mapping-table th,
.mapping-table td{padding:13px}
.sync-box{
    padding:15px;
    background:#f8fafc;
    border-radius:8px;
}

/* モーダル */
.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.55);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:1000;
    padding:20px;
}
.modal-backdrop.show{display:flex}
.modal{
    background:#fff;
    width:min(900px,100%);
    max-height:90vh;
    overflow:auto;
    border-radius:12px;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
}
.modal-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:18px 20px;
    border-bottom:1px solid #e5e7eb;
}
.modal-title{font-size:18px;font-weight:700}
.modal-body{padding:20px}
.modal-footer{
    padding:15px 20px;
    border-top:1px solid #e5e7eb;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.toast{
    position:fixed;
    right:20px;
    bottom:20px;
    background:#172033;
    color:#fff;
    padding:13px 18px;
    border-radius:8px;
    display:none;
    z-index:2000;
    box-shadow:0 8px 30px rgba(0,0,0,.2);
}
.toast.show{display:block}

.breadcrumb{
    color:#64748b;
    font-size:13px;
    margin-bottom:16px;
}

@media(max-width:900px){
    .stats{grid-template-columns:repeat(2,1fr)}
    .template-grid{grid-template-columns:1fr}
    .question-settings{grid-template-columns:1fr}
    .settings-grid{grid-template-columns:1fr}
    .app-header{padding:0 10px}
    .logo{margin-right:10px}
    .nav button{padding:0 8px;font-size:12px}
    .container{padding:15px}
}

@media(max-width:600px){
    .stats{grid-template-columns:1fr}
    .page-header{align-items:flex-start;flex-direction:column}
    .search{min-width:100%;width:100%}
    .toolbar{width:100%}
}
</style>
</head>

<body>

<header class="app-header">
    <div class="logo">アンケート管理</div>

    <nav class="nav">
        <button data-page="list" class="active">アンケート一覧</button>
        <button data-page="settings">kintone連携設定</button>
    </nav>

    <div class="nav-spacer"></div>
    <button class="logout" onclick="showToast('ログアウトしました（モック）')">
        ログアウト
    </button>
</header>

<main class="container">

<!-- =========================================================
     アンケート一覧
========================================================= -->
<section id="page-list" class="page active">

    <div class="page-header">
        <div>
            <h1 class="page-title">アンケート一覧</h1>
            <div class="page-description">
                アンケートの作成・公開・集計・送信を一元管理します。
            </div>
        </div>

        <button class="btn btn-primary" onclick="openCreatePage()">
            ＋ 新規アンケート作成
        </button>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="toolbar">
                <input
                    id="surveySearch"
                    class="search"
                    placeholder="アンケートタイトルを検索"
                    onkeydown="if(event.key==='Enter')renderSurveyList()"
                >

                <select id="surveyStatus" onchange="renderSurveyList()">
                    <option value="all">すべて</option>
                    <option value="public">公開中</option>
                    <option value="draft">下書き</option>
                    <option value="ended">終了</option>
                </select>

                <select id="surveySort" onchange="renderSurveyList()">
                    <option value="updated_desc">更新日：新しい順</option>
                    <option value="updated_asc">更新日：古い順</option>
                    <option value="answers_desc">回答数：多い順</option>
                    <option value="answers_asc">回答数：少ない順</option>
                    <option value="start_desc">期間開始日：新しい順</option>
                    <option value="start_asc">期間開始日：古い順</option>
                </select>
            </div>
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
                <tbody id="surveyTableBody"></tbody>
            </table>
        </div>
    </div>
</section>


<!-- =========================================================
     アンケート作成・編集
========================================================= -->
<section id="page-editor" class="page">

    <div class="breadcrumb">
        ホーム ＞ アンケート一覧 ＞ アンケート作成
    </div>

    <div class="page-header">
        <div>
            <h1 class="page-title" id="editorHeading">アンケート作成</h1>
        </div>

        <div class="toolbar">
            <button class="btn" onclick="openPreview()">
                プレビュー
            </button>
            <button class="btn" onclick="cancelEditor()">
                キャンセル
            </button>
            <button class="btn btn-primary" onclick="saveSurvey()">
                保存して一覧へ戻る
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <label>アンケートタイトル</label>
            <input
                id="editorTitle"
                class="editor-title"
                placeholder="アンケートタイトルを入力"
            >
        </div>
    </div>

    <div id="editorGroups"></div>

    <div class="card">
        <div class="card-body">
            <button class="btn btn-primary" onclick="addGroup()">
                ＋ グループを追加
            </button>
        </div>
    </div>

</section>


<!-- =========================================================
     顧客選択・メール送信
========================================================= -->
<section id="page-send" class="page">

    <div class="breadcrumb">
        ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
    </div>

    <div class="page-header">
        <div>
            <h1 class="page-title">顧客選択・メール送信</h1>
            <div id="sendSurveyTitle" class="page-description"></div>
        </div>

        <button class="btn btn-primary" onclick="executeSend()">
            選択した顧客へ一括送信
        </button>
    </div>

    <div id="unregisteredAlert"></div>

    <div class="card">
        <div class="card-header">
            <strong>送信メールテンプレート</strong>
            <span style="font-size:12px;color:#64748b">
                {顧客名} / {アンケートURL} が利用できます
            </span>
        </div>

        <div class="card-body">
            <div class="template-grid">
                <div>
                    <label>件名</label>
                    <input id="mailSubject"
                           class="form-control"
                           style="width:100%"
                           value="【アンケートのお願い】ご回答をお願いいたします">

                    <br>

                    <label>本文</label>
                    <textarea id="mailBody"
                              class="form-control"> {顧客名} 様

いつもお世話になっております。

下記アンケートへのご回答をお願いいたします。

アンケートURL：
{アンケートURL}

ご協力のほど、よろしくお願いいたします。</textarea>
                </div>

                <div>
                    <label>プレビュー</label>
                    <div class="mail-preview" id="mailPreview"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="toolbar">
                <input id="customerSearch"
                       class="search"
                       placeholder="会社名・氏名・メールアドレスを検索"
                       oninput="renderCustomers()">

                <select id="customerFilter" onchange="renderCustomers()">
                    <option value="all">すべて</option>
                    <option value="unsent">未送信</option>
                    <option value="sent_unanswered">送信済み・未回答</option>
                    <option value="answered">回答済み</option>
                    <option value="unregistered">kintone未登録</option>
                </select>

                <button class="btn btn-sm"
                        onclick="selectAllCustomers(true)">
                    すべて選択
                </button>

                <button class="btn btn-sm"
                        onclick="selectAllCustomers(false)">
                    すべて解除
                </button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>選択</th>
                    <th>会社名 / 氏名等</th>
                    <th>送信ステータス / 履歴</th>
                    <th>回答ステータス</th>
                    <th>kintone対応</th>
                </tr>
                </thead>
                <tbody id="customerTableBody"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <strong>一括送信ログ・履歴</strong>
        </div>
        <div class="table-wrap">
            <table>
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
                <tbody id="sendLogBody"></tbody>
            </table>
        </div>
    </div>

</section>


<!-- =========================================================
     集計・分析
========================================================= -->
<section id="page-analysis" class="page">

    <div class="breadcrumb">
        ホーム ＞ アンケート一覧 ＞ 回答集計・分析
    </div>

    <div class="page-header">
        <div>
            <h1 class="page-title" id="analysisTitle">回答集計・分析</h1>
        </div>

        <div class="toolbar">
            <button class="btn" onclick="downloadCSV()">
                CSVダウンロード
            </button>
            <button class="btn btn-primary" onclick="exportPDF()">
                PDF出力
            </button>
        </div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-label">送信対象者数</div>
            <div class="stat-value" id="statTargets">0</div>
            <div class="stat-sub">人</div>
        </div>

        <div class="stat">
            <div class="stat-label">回答数</div>
            <div class="stat-value" id="statAnswers">0</div>
            <div class="stat-sub">件</div>
        </div>

        <div class="stat">
            <div class="stat-label">未登録顧客からの回答数</div>
            <div class="stat-value" id="statUnregistered">0</div>
            <div class="stat-sub">件</div>
        </div>

        <div class="stat">
            <div class="stat-label">未回答数</div>
            <div class="stat-value" id="statUnanswered">0</div>
            <div class="stat-sub">人</div>
        </div>

        <div class="stat">
            <div class="stat-label">回答率</div>
            <div class="stat-value" id="statRate">0%</div>
            <div class="stat-sub">送信対象者ベース</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <strong>集計対象の設問</strong>

            <div class="toolbar">
                <button class="btn btn-sm" onclick="toggleAllQuestionFilters(true)">
                    すべて選択
                </button>
                <button class="btn btn-sm" onclick="toggleAllQuestionFilters(false)">
                    すべて解除
                </button>
            </div>
        </div>

        <div class="card-body">
            <div id="questionFilters" class="filter-questions"></div>
        </div>
    </div>

    <div id="analysisGroups"></div>

    <div class="card">
        <div class="card-header">
            <strong>個別回答一覧</strong>
            <input id="answerSearch"
                   class="form-control"
                   placeholder="会社名・氏名を検索"
                   oninput="renderAnswers()">
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
                <tbody id="answerTableBody"></tbody>
            </table>
        </div>
    </div>

</section>


<!-- =========================================================
     kintone設定
========================================================= -->
<section id="page-settings" class="page">

    <div class="breadcrumb">
        ホーム ＞ システム設定 ＞ kintone連携設定
    </div>

    <div class="page-header">
        <div>
            <h1 class="page-title">kintone連携設定</h1>
            <div class="page-description">
                kintoneの顧客情報とアンケートシステムの項目をマッピングします。
            </div>
        </div>

        <button class="btn btn-primary" onclick="saveKintoneSettings()">
            設定を保存する
        </button>
    </div>

    <div class="card">
        <div class="card-header">
            <strong>アカウント認証・アプリ接続</strong>
        </div>

        <div class="card-body">
            <div class="settings-grid">

                <label>サブドメイン</label>
                <input id="kSubdomain"
                       class="form-control"
                       value="example">

                <label>kintoneアプリID</label>
                <input id="kAppId"
                       class="form-control"
                       value="123">

                <label>ログイン名</label>
                <input id="kUsername"
                       class="form-control"
                       value="admin">

                <label>パスワード</label>
                <input id="kPassword"
                       class="form-control"
                       type="password"
                       value="********">

                <label>SSL証明書検証</label>
                <select id="kSsl" class="form-control">
                    <option value="on">検証する</option>
                    <option value="off">検証をスキップ</option>
                </select>

                <label>項目一覧</label>
                <button class="btn" onclick="fetchKintoneFields()">
                    項目一覧を再取得
                </button>

            </div>

            <br>

            <div class="sync-box">
                <strong>同期方式</strong>
                <p style="margin-bottom:0;color:#64748b">
                    現在のモックでは「手動同期」のみを表示しています。
                    実装時にはkintone APIへの接続処理を行います。
                </p>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <strong>顧客情報フィールドマッピング</strong>
        </div>

        <div class="card-body">
            <div class="table-wrap">
                <table class="mapping-table">
                    <thead>
                    <tr>
                        <th>システム項目</th>
                        <th>用途</th>
                        <th>kintone項目</th>
                    </tr>
                    </thead>
                    <tbody id="mappingBody"></tbody>
                </table>
            </div>
        </div>
    </div>

</section>

</main>


<!-- =========================================================
     共通モーダル
========================================================= -->
<div id="modalBackdrop" class="modal-backdrop">
    <div class="modal">

        <div class="modal-header">
            <div id="modalTitle" class="modal-title"></div>
            <button class="btn btn-icon" onclick="closeModal()">×</button>
        </div>

        <div id="modalBody" class="modal-body"></div>

        <div id="modalFooter" class="modal-footer"></div>

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
        title:"顧客満足度調査 2026",
        created:"2026/07/25",
        updated:"2026/08/10",
        start:"2026/08/01",
        end:"2026/08/31",
        status:"public",
        answers:128,
        groups:[
            {
                id:101,
                title:"サービスについて",
                questions:[
                    {
                        id:1001,
                        text:"サービスの満足度を教えてください。",
                        type:"single",
                        required:true,
                        options:["とても満足","満足","普通","不満","とても不満"]
                    },
                    {
                        id:1002,
                        text:"良かった点を教えてください。",
                        type:"multi",
                        required:false,
                        options:["価格","品質","サポート","操作性","その他"]
                    }
                ]
            },
            {
                id:102,
                title:"今後について",
                questions:[
                    {
                        id:1003,
                        text:"今後改善してほしい点があれば教えてください。",
                        type:"text",
                        required:false,
                        options:[]
                    }
                ]
            }
        ]
    },
    {
        id:2,
        title:"新サービス利用意向調査",
        created:"2026/08/01",
        updated:"2026/08/20",
        start:"2026/08/20",
        end:"2026/09/20",
        status:"draft",
        answers:0,
        groups:[
            {
                id:201,
                title:"利用意向",
                questions:[
                    {
                        id:2001,
                        text:"新サービスを利用したいと思いますか？",
                        type:"single",
                        required:true,
                        options:["ぜひ利用したい","利用したい","どちらともいえない","利用したくない"]
                    }
                ]
            }
        ]
    },
    {
        id:3,
        title:"2025年度 お客様アンケート",
        created:"2025/12/01",
        updated:"2026/01/10",
        start:"2025/12/01",
        end:"2025/12/31",
        status:"ended",
        answers:256,
        groups:[
            {
                id:301,
                title:"総合評価",
                questions:[
                    {
                        id:3001,
                        text:"総合的な満足度を教えてください。",
                        type:"single",
                        required:true,
                        options:["5","4","3","2","1"]
                    }
                ]
            }
        ]
    }
];

let customers = [
    {
        id:1,
        company:"株式会社サンプル商事",
        name:"山田 太郎",
        email:"yamada@example.com",
        phone:"03-1234-5678",
        address:"東京都港区",
        sent:true,
        sendCount:1,
        lastSent:"2026/08/12 10:15",
        answered:true,
        kintone:true,
        selected:false
    },
    {
        id:2,
        company:"株式会社東京サービス",
        name:"佐藤 花子",
        email:"sato@example.com",
        phone:"03-2345-6789",
        address:"東京都千代田区",
        sent:true,
        sendCount:1,
        lastSent:"2026/08/12 10:16",
        answered:false,
        kintone:true,
        selected:false
    },
    {
        id:3,
        company:"ABC株式会社",
        name:"鈴木 一郎",
        email:"suzuki@example.com",
        phone:"03-3456-7890",
        address:"東京都新宿区",
        sent:false,
        sendCount:0,
        lastSent:"",
        answered:false,
        kintone:true,
        selected:false
    },
    {
        id:4,
        company:"Web回答者",
        name:"田中 次郎",
        email:"tanaka@example.net",
        phone:"090-0000-0000",
        address:"東京都渋谷区",
        sent:false,
        sendCount:0,
        lastSent:"",
        answered:true,
        kintone:false,
        selected:false,
        webDirect:true
    }
];

let sendLogs = [
    {
        id:1,
        date:"2026/08/12 10:16",
        type:"初回一括送信",
        count:2,
        subject:"【アンケートのお願い】ご回答をお願いいたします",
        user:"管理者"
    }
];

let answers = [
    {
        id:10001,
        customerId:1,
        company:"株式会社サンプル商事",
        name:"山田 太郎",
        date:"2026/08/13 09:30",
        values:[
            "とても満足",
            "価格、品質",
            "今後も使い続けたいです。"
        ]
    },
    {
        id:10002,
        customerId:4,
        company:"Web回答者",
        name:"田中 次郎",
        date:"2026/08/14 13:20",
        values:[
            "満足",
            "サポート",
            "対応が良かったです。"
        ]
    }
];

let currentSurveyId = null;
let editorSurvey = null;
let editorMode = "create";
let currentAnalysisSurveyId = null;


/* =========================================================
   共通
========================================================= */

function showPage(pageName){

    document.querySelectorAll(".page").forEach(p=>{
        p.classList.remove("active");
    });

    const target = document.getElementById("page-"+pageName);

    if(target){
        target.classList.add("active");
    }

    document.querySelectorAll(".nav button").forEach(btn=>{
        btn.classList.remove("active");

        if(btn.dataset.page === pageName){
            btn.classList.add("active");
        }
    });

    window.scrollTo({
        top:0,
        behavior:"smooth"
    });
}

document.querySelectorAll(".nav button").forEach(btn=>{
    btn.addEventListener("click",()=>{
        showPage(btn.dataset.page);

        if(btn.dataset.page==="list"){
            renderSurveyList();
        }

        if(btn.dataset.page==="settings"){
            renderMapping();
        }
    });
});


function showToast(message){

    const toast = document.getElementById("toast");

    toast.textContent = message;
    toast.classList.add("show");

    setTimeout(()=>{
        toast.classList.remove("show");
    },2500);
}


function openModal(title,body,footer=""){

    document.getElementById("modalTitle").textContent = title;
    document.getElementById("modalBody").innerHTML = body;
    document.getElementById("modalFooter").innerHTML = footer;

    document.getElementById("modalBackdrop").classList.add("show");
}


function closeModal(){

    document.getElementById("modalBackdrop").classList.remove("show");
}


document.getElementById("modalBackdrop").addEventListener("click",e=>{
    if(e.target.id==="modalBackdrop"){
        closeModal();
    }
});


function escapeHtml(str){

    return String(str ?? "")
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;")
        .replace(/'/g,"&#039;");
}


/* =========================================================
   アンケート一覧
========================================================= */

function statusLabel(status){

    if(status==="public"){
        return '<span class="badge badge-public">公開中</span>';
    }

    if(status==="draft"){
        return '<span class="badge badge-draft">下書き</span>';
    }

    return '<span class="badge badge-ended">終了</span>';
}


function renderSurveyList(){

    const search =
        document.getElementById("surveySearch").value
        .toLowerCase();

    const status =
        document.getElementById("surveyStatus").value;

    const sort =
        document.getElementById("surveySort").value;

    let data = surveys.filter(s=>{

        const matchSearch =
            !search ||
            s.title.toLowerCase().includes(search);

        const matchStatus =
            status==="all" ||
            s.status===status;

        return matchSearch && matchStatus;
    });

    data.sort((a,b)=>{

        if(sort==="updated_desc"){
            return b.updated.localeCompare(a.updated);
        }

        if(sort==="updated_asc"){
            return a.updated.localeCompare(b.updated);
        }

        if(sort==="answers_desc"){
            return b.answers-a.answers;
        }

        if(sort==="answers_asc"){
            return a.answers-b.answers;
        }

        if(sort==="start_desc"){
            return b.start.localeCompare(a.start);
        }

        if(sort==="start_asc"){
            return a.start.localeCompare(b.start);
        }

        return 0;
    });

    const tbody =
        document.getElementById("surveyTableBody");

    if(data.length===0){

        tbody.innerHTML = `
            <tr>
                <td colspan="6">
                    <div class="empty">
                        該当するアンケートはありません
                    </div>
                </td>
            </tr>
        `;

        return;
    }

    tbody.innerHTML = data.map(s=>{

        let actions = `
            <button class="btn btn-sm"
                onclick="openEditor(${s.id})">
                確認・編集
            </button>
        `;

        if(s.status==="public"){
            actions += `
                <button class="btn btn-sm"
                    onclick="openAnalysis(${s.id})">
                    集計
                </button>

                <button class="btn btn-sm"
                    onclick="openSend(${s.id})">
                    送信
                </button>

                <button class="btn btn-sm btn-warning"
                    onclick="stopSurvey(${s.id})">
                    停止
                </button>

                <button class="btn btn-sm"
                    onclick="duplicateSurvey(${s.id})">
                    複製
                </button>
            `;
        }

        if(s.status==="draft"){
            actions += `
                <button class="btn btn-sm btn-danger"
                    onclick="deleteSurvey(${s.id})">
                    削除
                </button>

                <button class="btn btn-sm"
                    onclick="duplicateSurvey(${s.id})">
                    複製
                </button>
            `;
        }

        if(s.status==="ended"){
            actions += `
                <button class="btn btn-sm"
                    onclick="openAnalysis(${s.id})">
                    集計
                </button>

                <button class="btn btn-sm"
                    onclick="duplicateSurvey(${s.id})">
                    複製
                </button>
            `;
        }

        return `
            <tr>
                <td class="date-cell">
                    ${escapeHtml(s.created)}
                    <br>
                    <span style="font-size:11px">
                        更新: ${escapeHtml(s.updated)}
                    </span>
                </td>

                <td class="title-cell">
                    ${escapeHtml(s.title)}
                </td>

                <td class="period">
                    ${s.start && s.end
                        ? escapeHtml(s.start)+" ～ "+escapeHtml(s.end)
                        : "未設定"}
                </td>

                <td>${statusLabel(s.status)}</td>

                <td>
                    <strong>${s.answers}</strong> 件
                </td>

                <td>
                    <div class="actions">
                        ${actions}
                    </div>
                </td>
            </tr>
        `;

    }).join("");
}


function stopSurvey(id){

    const survey = surveys.find(s=>s.id===id);

    if(!survey) return;

    if(!confirm(
        `「${survey.title}」を停止しますか？\n\n停止後は回答受付が停止します。`
    )){
        return;
    }

    survey.status="ended";
    survey.updated=getToday();

    renderSurveyList();

    showToast("アンケートを停止しました");
}


function deleteSurvey(id){

    const survey = surveys.find(s=>s.id===id);

    if(!survey) return;

    if(!confirm(
        `「${survey.title}」を削除しますか？\n\n論理削除として処理します。`
    )){
        return;
    }

    surveys = surveys.filter(s=>s.id!==id);

    renderSurveyList();

    showToast("アンケートを削除しました");
}


function duplicateSurvey(id){

    const source = surveys.find(s=>s.id===id);

    if(!source) return;

    const clone =
        JSON.parse(JSON.stringify(source));

    clone.id =
        Math.max(0,...surveys.map(s=>s.id))+1;

    clone.title += "（複製）";
    clone.status="draft";
    clone.answers=0;
    clone.created=getToday();
    clone.updated=getToday();

    surveys.push(clone);

    renderSurveyList();

    showToast("アンケートを下書きとして複製しました");
}


/* =========================================================
   作成・編集
========================================================= */

function openCreatePage(){

    editorMode="create";

    editorSurvey={
        id:null,
        title:"",
        created:getToday(),
        updated:getToday(),
        start:"",
        end:"",
        status:"draft",
        answers:0,
        groups:[
            {
                id:Date.now(),
                title:"新しいグループ",
                questions:[]
            }
        ]
    };

    document.getElementById("editorHeading").textContent =
        "アンケート作成";

    showPage("editor");

    renderEditor();
}


function openEditor(id){

    const survey=surveys.find(s=>s.id===id);

    if(!survey) return;

    editorMode="edit";

    editorSurvey =
        JSON.parse(JSON.stringify(survey));

    currentSurveyId=id;

    document.getElementById("editorHeading").textContent =
        "アンケート詳細・編集";

    showPage("editor");

    renderEditor();
}


function renderEditor(){

    document.getElementById("editorTitle").value =
        editorSurvey.title;

    const container =
        document.getElementById("editorGroups");

    container.innerHTML =
        editorSurvey.groups.map((group,gIndex)=>{

            return `
                <div class="group"
                     draggable="true"
                     data-group-index="${gIndex}">

                    <div class="group-header">

                        <span class="drag-handle">⠿</span>

                        <input
                            class="group-title"
                            value="${escapeHtml(group.title)}"
                            onchange="updateGroupTitle(${gIndex},this.value)"
                        >

                        <button
                            class="btn btn-sm"
                            onclick="addQuestion(${gIndex})">
                            ＋ 質問
                        </button>

                        <button
                            class="btn btn-sm btn-danger"
                            onclick="deleteGroup(${gIndex})">
                            グループ削除
                        </button>

                    </div>

                    <div class="questions"
                         data-group="${gIndex}">

                        ${
                            group.questions.length
                            ?
                            group.questions.map(
                                (q,qIndex)=>
                                renderQuestion(q,gIndex,qIndex)
                            ).join("")
                            :
                            `
                            <div class="empty"
                                 style="padding:20px">
                                質問がありません
                            </div>
                            `
                        }

                    </div>
                </div>
            `;

        }).join("");

    setupDragAndDrop();

    renumberQuestions();
}


function renderQuestion(q,gIndex,qIndex){

    let options="";

    if(q.type==="single" || q.type==="multi"){

        options=`
            <div class="options">
                ${
                    q.options.map((option,oIndex)=>`
                        <div class="option-row">
                            <span>${q.type==="single"?"○":"□"}</span>
                            <input
                                class="form-control"
                                value="${escapeHtml(option)}"
                                onchange="updateOption(
                                    ${gIndex},
                                    ${qIndex},
                                    ${oIndex},
                                    this.value
                                )"
                            >
                            <button
                                class="btn btn-sm"
                                onclick="deleteOption(
                                    ${gIndex},
                                    ${qIndex},
                                    ${oIndex}
                                )">
                                ×
                            </button>
                        </div>
                    `).join("")
                }

                <button
                    class="btn btn-sm"
                    onclick="addOption(${gIndex},${qIndex})">
                    ＋ 選択肢
                </button>
            </div>
        `;
    }

    return `
        <div class="question"
             draggable="true"
             data-question-index="${qIndex}"
             data-group-index="${gIndex}">

            <div class="question-top">

                <span class="drag-handle">⠿</span>

                <span class="question-number">
                    Q${qIndex+1}.
                </span>

                <input
                    class="question-text"
                    value="${escapeHtml(q.text)}"
                    placeholder="質問文を入力"
                    onchange="updateQuestionText(
                        ${gIndex},
                        ${qIndex},
                        this.value
                    )"
                >

                <button
                    class="btn btn-sm btn-danger"
                    onclick="deleteQuestion(
                        ${gIndex},
                        ${qIndex}
                    )">
                    削除
                </button>

            </div>

            <div class="question-settings">

                <select
                    onchange="changeQuestionType(
                        ${gIndex},
                        ${qIndex},
                        this.value
                    )">

                    <option value="single"
                        ${q.type==="single"?"selected":""}>
                        単一選択
                    </option>

                    <option value="multi"
                        ${q.type==="multi"?"selected":""}>
                        複数選択
                    </option>

                    <option value="text"
                        ${q.type==="text"?"selected":""}>
                        自由記述
                    </option>

                </select>

                <label style="display:flex;align-items:center;gap:8px">
                    <input
                        type="checkbox"
                        ${q.required?"checked":""}
                        onchange="toggleRequired(
                            ${gIndex},
                            ${qIndex},
                            this.checked
                        )">
                    必須回答
                </label>

                ${
                    q.type==="single"
                    ?
                    `
                    <button
                        class="btn btn-sm"
                        onclick="setBranching(${gIndex},${qIndex})">
                        分岐設定
                    </button>
                    `
                    :
                    ""
                }

            </div>

            ${options}

        </div>
    `;
}


function updateGroupTitle(g,value){
    editorSurvey.groups[g].title=value;
}


function updateQuestionText(g,q,value){
    editorSurvey.groups[g].questions[q].text=value;
}


function changeQuestionType(g,q,type){

    editorSurvey.groups[g].questions[q].type=type;

    if(type==="text"){
        editorSurvey.groups[g].questions[q].options=[];
    }

    if(
        (type==="single" || type==="multi") &&
        !editorSurvey.groups[g].questions[q].options.length
    ){
        editorSurvey.groups[g].questions[q].options=[
            "選択肢1",
            "選択肢2"
        ];
    }

    renderEditor();
}


function toggleRequired(g,q,value){
    editorSurvey.groups[g].questions[q].required=value;
}


function addQuestion(gIndex){

    editorSurvey.groups[gIndex].questions.push({
        id:Date.now(),
        text:"新しい質問",
        type:"single",
        required:false,
        options:["選択肢1","選択肢2"]
    });

    renderEditor();
}


function deleteQuestion(g,q){

    if(!confirm("この質問を削除しますか？")){
        return;
    }

    editorSurvey.groups[g].questions.splice(q,1);

    renderEditor();
}


function addOption(g,q){

    editorSurvey.groups[g].questions[q].options.push(
        "選択肢"+(
            editorSurvey.groups[g].questions[q].options.length+1
        )
    );

    renderEditor();
}


function updateOption(g,q,o,value){

    editorSurvey.groups[g].questions[q].options[o]=value;
}


function deleteOption(g,q,o){

    editorSurvey.groups[g].questions[q].options.splice(o,1);

    renderEditor();
}


function addGroup(){

    editorSurvey.groups.push({
        id:Date.now(),
        title:"新しいグループ",
        questions:[]
    });

    renderEditor();
}


function deleteGroup(index){

    if(editorSurvey.groups.length<=1){

        showToast("最低1つのグループが必要です");
        return;
    }

    if(!confirm(
        "このグループと含まれる質問をすべて削除しますか？"
    )){
        return;
    }

    editorSurvey.groups.splice(index,1);

    renderEditor();
}


function setBranching(g,q){

    openModal(
        "分岐設定",
        `
        <p>
            「${escapeHtml(
                editorSurvey.groups[g].questions[q].text
            )}」の回答による分岐先を設定します。
        </p>

        <label>「とても満足」の場合</label>
        <select class="form-control" style="width:100%;margin-top:6px">
            <option>次の質問へ</option>
            <option>Q3へ</option>
            <option>アンケート終了</option>
        </select>

        <br>

        <label>「不満」の場合</label>
        <select class="form-control" style="width:100%;margin-top:6px">
            <option>次の質問へ</option>
            <option>Q3へ</option>
            <option>アンケート終了</option>
        </select>
        `,
        `<button class="btn btn-primary" onclick="closeModal();showToast('分岐設定を保存しました')">
            保存
        </button>`
    );
}


function setupDragAndDrop(){

    const groupContainer =
        document.getElementById("editorGroups");

    let draggedGroup=null;

    groupContainer
        .querySelectorAll(".group")
        .forEach(group=>{

            group.addEventListener("dragstart",()=>{
                draggedGroup=group;
                group.classList.add("dragging");
            });

            group.addEventListener("dragend",()=>{
                group.classList.remove("dragging");
                draggedGroup=null;
                syncGroupOrder();
            });

            group.addEventListener("dragover",e=>{
                e.preventDefault();

                if(!draggedGroup ||
                   draggedGroup===group){
                    return;
                }

                const rect=group.getBoundingClientRect();

                if(e.clientY < rect.top+rect.height/2){
                    groupContainer.insertBefore(
                        draggedGroup,
                        group
                    );
                }else{
                    groupContainer.insertBefore(
                        draggedGroup,
                        group.nextSibling
                    );
                }
            });
        });

    groupContainer
        .querySelectorAll(".question")
        .forEach(question=>{

            question.addEventListener("dragstart",e=>{
                e.stopPropagation();
                question.classList.add("dragging");
                question._dragSource =
                    parseInt(question.dataset.groupIndex);
            });

            question.addEventListener("dragend",e=>{
                e.stopPropagation();
                question.classList.remove("dragging");
                syncQuestionOrder();
            });

            question.addEventListener("dragover",e=>{

                e.preventDefault();
                e.stopPropagation();

                const dragging =
                    groupContainer.querySelector(
                        ".question.dragging"
                    );

                if(!dragging || dragging===question){
                    return;
                }

                const group =
                    question.closest(".questions");

                const rect =
                    question.getBoundingClientRect();

                if(e.clientY < rect.top+rect.height/2){
                    group.insertBefore(
                        dragging,
                        question
                    );
                }else{
                    group.insertBefore(
                        dragging,
                        question.nextSibling
                    );
                }
            });
        });
}


function syncGroupOrder(){

    const groups =
        [...document.querySelectorAll("#editorGroups > .group")];

    const newGroups=[];

    groups.forEach(group=>{

        const oldIndex =
            parseInt(group.dataset.groupIndex);

        newGroups.push(editorSurvey.groups[oldIndex]);
    });

    editorSurvey.groups=newGroups;

    renderEditor();
}


function syncQuestionOrder(){

    const newGroups =
        editorSurvey.groups.map(g=>({
            ...g,
            questions:[]
        }));

    document
        .querySelectorAll("#editorGroups .group")
        .forEach((groupElement,newGroupIndex)=>{

            const oldGroupIndex =
                parseInt(groupElement.dataset.groupIndex);

            const oldQuestions =
                editorSurvey.groups[oldGroupIndex].questions;

            groupElement
                .querySelectorAll(".question")
                .forEach(questionElement=>{

                    const oldQuestionIndex =
                        parseInt(
                            questionElement.dataset.questionIndex
                        );

                    newGroups[newGroupIndex]
                        .questions
                        .push(
                            oldQuestions[oldQuestionIndex]
                        );
                });
        });

    editorSurvey.groups=newGroups;

    renderEditor();
}


function renumberQuestions(){

    let number=1;

    document
        .querySelectorAll(".question-number")
        .forEach(el=>{
            el.textContent="Q"+number+".";
            number++;
        });
}


function saveSurvey(){

    const title =
        document.getElementById("editorTitle").value.trim();

    if(!title){

        showToast("アンケートタイトルを入力してください");
        return;
    }

    editorSurvey.title=title;
    editorSurvey.updated=getToday();

    if(editorMode==="create"){

        editorSurvey.id =
            Math.max(0,...surveys.map(s=>s.id))+1;

        surveys.push(
            JSON.parse(JSON.stringify(editorSurvey))
        );

    }else{

        const index =
            surveys.findIndex(
                s=>s.id===editorSurvey.id
            );

        if(index!==-1){
            surveys[index] =
                JSON.parse(JSON.stringify(editorSurvey));
        }
    }

    showToast("保存しました");

    setTimeout(()=>{
        showPage("list");
        renderSurveyList();
    },500);
}


function cancelEditor(){

    if(!confirm(
        "変更内容を破棄して一覧へ戻りますか？"
    )){
        return;
    }

    showPage("list");
    renderSurveyList();
}


/* =========================================================
   プレビュー
========================================================= */

function openPreview(){

    const survey =
        JSON.parse(JSON.stringify(editorSurvey));

    let questionNumber=1;

    let html=`
        <div style="display:flex;justify-content:flex-end;margin-bottom:15px">
            <button class="btn btn-sm"
                onclick="previewDevice('pc')">
                PC表示
            </button>
            <button class="btn btn-sm"
                onclick="previewDevice('mobile')">
                スマートフォン表示
            </button>
        </div>

        <div id="previewFrame" class="preview-frame">
            <h2>${escapeHtml(survey.title || "無題のアンケート")}</h2>
    `;

    survey.groups.forEach(group=>{

        html+=`
            <h3 style="margin-top:28px">
                ${escapeHtml(group.title)}
            </h3>
        `;

        group.questions.forEach(q=>{

            html+=`
                <div class="preview-question">

                    <h4>
                        Q${questionNumber}.
                        ${escapeHtml(q.text)}
                        ${q.required
                            ?'<span style="color:#dc2626"> *</span>'
                            :''}
                    </h4>
            `;

            if(q.type==="single"){

                q.options.forEach(option=>{
                    html+=`
                        <label class="preview-option">
                            <input type="radio"
                                   name="q${questionNumber}">
                            ${escapeHtml(option)}
                        </label>
                    `;
                });

            }else if(q.type==="multi"){

                q.options.forEach(option=>{
                    html+=`
                        <label class="preview-option">
                            <input type="checkbox">
                            ${escapeHtml(option)}
                        </label>
                    `;
                });

            }else{

                html+=`
                    <textarea
                        class="form-control"
                        placeholder="回答を入力してください"></textarea>
                `;
            }

            html+=`
                </div>
            `;

            questionNumber++;
        });
    });

    html+=`
            <button
                class="btn btn-primary"
                onclick="alert('※これはプレビュー表示のため送信されません')">
                送信
            </button>

        </div>
    `;

    openModal(
        "プレビュー",
        html,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}


function previewDevice(type){

    const frame =
        document.getElementById("previewFrame");

    if(!frame) return;

    if(type==="mobile"){
        frame.style.maxWidth="390px";
    }else{
        frame.style.maxWidth="720px";
    }
}


/* =========================================================
   顧客送信
========================================================= */

function openSend(id){

    currentSurveyId=id;

    const survey =
        surveys.find(s=>s.id===id);

    if(!survey) return;

    document.getElementById("sendSurveyTitle").textContent =
        survey.title;

    showPage("send");

    renderCustomers();
    renderSendLogs();
    updateMailPreview();
}


document.getElementById("mailSubject")
    .addEventListener("input",updateMailPreview);

document.getElementById("mailBody")
    .addEventListener("input",updateMailPreview);


function updateMailPreview(){

    const subject =
        document.getElementById("mailSubject").value;

    const body =
        document.getElementById("mailBody").value;

    const sample =
        body
        .replaceAll("{顧客名}","山田 太郎")
        .replaceAll(
            "{アンケートURL}",
            "https://example.com/survey/abc123"
        );

    document.getElementById("mailPreview").innerHTML =
        `
        <strong>件名：</strong>
        ${escapeHtml(subject)}
        <hr>
        ${escapeHtml(sample)}
        `;
}


function renderCustomers(){

    const search =
        document.getElementById("customerSearch").value
        .toLowerCase();

    const filter =
        document.getElementById("customerFilter").value;

    const data =
        customers.filter(c=>{

            const matchSearch =
                !search ||
                c.company.toLowerCase().includes(search) ||
                c.name.toLowerCase().includes(search) ||
                c.email.toLowerCase().includes(search);

            let matchFilter=true;

            if(filter==="unsent"){
                matchFilter=!c.sent && !c.webDirect;
            }

            if(filter==="sent_unanswered"){
                matchFilter=c.sent && !c.answered;
            }

            if(filter==="answered"){
                matchFilter=c.answered;
            }

            if(filter==="unregistered"){
                matchFilter=!c.kintone;
            }

            return matchSearch && matchFilter;
        });

    const tbody =
        document.getElementById("customerTableBody");

    tbody.innerHTML=data.map(c=>{

        let answerBadge =
            c.answered
            ? '<span class="badge badge-success">回答済み</span>'
            : c.sent
                ? '<span class="badge badge-warning">送信済み（未回答）</span>'
                : '<span class="badge badge-draft">未送信</span>';

        let kintone =
            c.kintone
            ?
            '<span class="badge badge-success">✓ 登録完了</span>'
            :
            `
            <span class="badge badge-warning">未登録</span>
            <br><br>
            <button
                class="btn btn-sm btn-success"
                onclick="completeKintone(${c.id})">
                kintone登録完了
            </button>
            `;

        let checkbox="";

        if(!c.webDirect){

            checkbox=`
                <input
                    type="checkbox"
                    ${c.selected?"checked":""}
                    onchange="toggleCustomer(
                        ${c.id},
                        this.checked
                    )">
            `;

        }else{

            checkbox=
                `<span style="color:#94a3b8">Web回答</span>`;
        }

        return `
            <tr>

                <td>${checkbox}</td>

                <td>
                    <strong>${escapeHtml(c.company)}</strong><br>
                    ${escapeHtml(c.name)}<br>
                    <span style="color:#64748b">
                        ${escapeHtml(c.email)}
                    </span><br>
                    <span style="color:#94a3b8;font-size:11px">
                        ${escapeHtml(c.phone)} /
                        ${escapeHtml(c.address)}
                    </span>
                </td>

                <td>
                    ${
                        c.lastSent
                        ? `
                        最終送信：
                        ${escapeHtml(c.lastSent)}
                        <br>
                        送信回数：${c.sendCount}回
                        <br>
                        <button
                            class="btn btn-sm"
                            onclick="viewCustomerMail(${c.id})">
                            送信文を確認
                        </button>
                        `
                        :
                        c.webDirect
                        ? "Webから直接回答"
                        : "送信未実施"
                    }
                </td>

                <td>${answerBadge}</td>

                <td>${kintone}</td>

            </tr>
        `;

    }).join("");

    const unregistered =
        customers.filter(c=>!c.kintone && c.answered);

    document.getElementById("unregisteredAlert").innerHTML =
        unregistered.length
        ?
        `
        <div class="alert alert-warning">
            ⚠ kintone未登録の回答者が
            <strong>${unregistered.length}名</strong>
            存在します。目視確認の上、kintoneへの登録を完了してください。
        </div>
        `
        :
        "";
}


function toggleCustomer(id,checked){

    const c=customers.find(c=>c.id===id);

    if(c){
        c.selected=checked;
    }
}


function selectAllCustomers(flag){

    customers.forEach(c=>{

        if(!c.webDirect){
            c.selected=flag;
        }

    });

    renderCustomers();
}


function executeSend(){

    const selected =
        customers.filter(c=>c.selected && !c.webDirect);

    if(selected.length===0){

        showToast("送信対象を選択してください");
        return;
    }

    const alreadySent =
        selected.filter(c=>c.sent);

    if(alreadySent.length){

        openModal(
            "二重送信確認",
            `
            <div class="alert alert-warning">
                既に送信済みの宛先が
                <strong>${alreadySent.length}件</strong>
                含まれています。
                <br><br>
                再送しますか？
            </div>

            <p>
                再送対象：
                ${alreadySent.map(c=>escapeHtml(c.name)).join("、")}
            </p>
            `,
            `
            <button class="btn" onclick="closeModal()">
                キャンセル
            </button>

            <button class="btn btn-warning"
                onclick="confirmSend(true)">
                再送する
            </button>
            `
        );

        return;
    }

    confirmSend(false);
}


function confirmSend(isReminder){

    closeModal();

    const selected =
        customers.filter(c=>c.selected && !c.webDirect);

    const subject =
        document.getElementById("mailSubject").value;

    const body =
        document.getElementById("mailBody").value;

    selected.forEach(c=>{

        c.sent=true;
        c.sendCount++;

        c.lastSent =
            new Date().toLocaleString(
                "ja-JP",
                {
                    year:"numeric",
                    month:"2-digit",
                    day:"2-digit",
                    hour:"2-digit",
                    minute:"2-digit"
                }
            );

        c.selected=false;
    });

    sendLogs.unshift({
        id:Date.now(),
        date:getCurrentDateTime(),
        type:isReminder
            ? "リマインド送信"
            : "初回一括送信",
        count:selected.length,
        subject:subject,
        user:"管理者"
    });

    showToast(
        `${selected.length}件に${isReminder?"再":""}送信しました`
    );

    renderCustomers();
    renderSendLogs();
}


function viewCustomerMail(id){

    const c=customers.find(c=>c.id===id);

    if(!c) return;

    const subject =
        document.getElementById("mailSubject").value;

    const body =
        document.getElementById("mailBody").value
        .replaceAll("{顧客名}",c.name)
        .replaceAll(
            "{アンケートURL}",
            "https://example.com/survey/"+currentSurveyId+"/"+c.id
        );

    openModal(
        "送信文を確認",
        `
        <p><strong>送信先：</strong>${escapeHtml(c.email)}</p>
        <p><strong>送信日時：</strong>${escapeHtml(c.lastSent)}</p>
        <hr>
        <p><strong>件名：</strong>${escapeHtml(subject)}</p>
        <div class="mail-preview">
            ${escapeHtml(body)}
        </div>
        `,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}


function renderSendLogs(){

    const tbody =
        document.getElementById("sendLogBody");

    tbody.innerHTML =
        sendLogs.map(log=>`
            <tr>
                <td>${escapeHtml(log.date)}</td>
                <td>${escapeHtml(log.type)}</td>
                <td>${log.count}件</td>
                <td>${escapeHtml(log.subject)}</td>
                <td>${escapeHtml(log.user)}</td>
                <td>
                    <button class="btn btn-sm"
                        onclick="viewSendLog(${log.id})">
                        送信文を確認
                    </button>
                </td>
            </tr>
        `).join("");
}


function viewSendLog(id){

    const log =
        sendLogs.find(l=>l.id===id);

    if(!log) return;

    openModal(
        "一括送信履歴",
        `
        <p><strong>日時：</strong>${escapeHtml(log.date)}</p>
        <p><strong>送信種別：</strong>${escapeHtml(log.type)}</p>
        <p><strong>送信件数：</strong>${log.count}件</p>
        <p><strong>実行者：</strong>${escapeHtml(log.user)}</p>
        <hr>
        <p><strong>件名：</strong>${escapeHtml(log.subject)}</p>
        <div class="mail-preview">
            ${escapeHtml(
                document.getElementById("mailBody").value
            )}
        </div>
        `,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}


function completeKintone(id){

    const c=customers.find(c=>c.id===id);

    if(!c) return;

    if(!confirm(
        `${c.name} さんのkintone登録が完了しましたか？`
    )){
        return;
    }

    c.kintone=true;

    renderCustomers();

    showToast("kintone登録完了として更新しました");
}


/* =========================================================
   集計・分析
========================================================= */

function openAnalysis(id){

    currentAnalysisSurveyId=id;

    const survey =
        surveys.find(s=>s.id===id);

    if(!survey) return;

    document.getElementById("analysisTitle").textContent =
        survey.title;

    showPage("analysis");

    renderAnalysis();
}


function renderAnalysis(){

    const survey =
        surveys.find(s=>s.id===currentAnalysisSurveyId);

    if(!survey) return;

    const targets =
        customers.filter(c=>c.sent && !c.webDirect).length;

    const surveyAnswers =
        answers.filter(a=>
            a.customerId &&
            survey.status !== "draft"
        );

    const answeredCustomers =
        new Set(
            surveyAnswers.map(a=>a.customerId)
        );

    const unregistered =
        surveyAnswers.filter(a=>{
            const c =
                customers.find(c=>c.id===a.customerId);

            return c && !c.kintone;
        }).length;

    const unanswered =
        Math.max(0,targets-answeredCustomers.size);

    const rate =
        targets
        ? ((answeredCustomers.size/targets)*100).toFixed(1)
        : "0.0";

    document.getElementById("statTargets").textContent =
        targets;

    document.getElementById("statAnswers").textContent =
        surveyAnswers.length;

    document.getElementById("statUnregistered").textContent =
        unregistered;

    document.getElementById("statUnanswered").textContent =
        unanswered;

    document.getElementById("statRate").textContent =
        rate+"%";

    renderQuestionFilters(survey);
    renderAnalysisGroups(survey);
    renderAnswers();
}


function renderQuestionFilters(survey){

    const container =
        document.getElementById("questionFilters");

    const questions=[];

    survey.groups.forEach((g,gi)=>{
        g.questions.forEach((q,qi)=>{
            questions.push({
                ...q,
                groupIndex:gi,
                questionIndex:qi
            });
        });
    });

    container.innerHTML =
        questions.map((q,index)=>`

            <button
                class="question-filter active"
                data-filter-question="${q.id}"
                onclick="toggleQuestionFilter(${q.id})">

                Q${index+1}
                ${escapeHtml(q.text)}

                <span class="badge badge-draft">
                    ${
                        q.type==="single"
                        ?"単一選択"
                        :q.type==="multi"
                            ?"複数選択"
                            :"テキスト"
                    }
                </span>

            </button>

        `).join("");
}


function toggleQuestionFilter(id){

    const button =
        document.querySelector(
            `[data-filter-question="${id}"]`
        );

    if(!button) return;

    button.classList.toggle("active");

    renderAnalysis();
}


function toggleAllQuestionFilters(flag){

    document
        .querySelectorAll(".question-filter")
        .forEach(btn=>{
            btn.classList.toggle("active",flag);
        });

    renderAnalysis();
}


function isQuestionVisible(id){

    const button =
        document.querySelector(
            `[data-filter-question="${id}"]`
        );

    return button?.classList.contains("active");
}


function renderAnalysisGroups(survey){

    const container =
        document.getElementById("analysisGroups");

    let globalIndex=0;

    container.innerHTML =
        survey.groups.map(group=>{

            let questionsHtml="";

            group.questions.forEach(q=>{

                const qIndex=globalIndex++;

                if(!isQuestionVisible(q.id)){
                    return;
                }

                questionsHtml +=
                    renderQuestionResult(
                        q,
                        qIndex
                    );
            });

            if(!questionsHtml){
                return "";
            }

            return `
                <div class="card">
                    <div class="card-header">
                        <strong>
                            ${escapeHtml(group.title)}
                        </strong>
                    </div>
                    <div class="card-body">
                        ${questionsHtml}
                    </div>
                </div>
            `;

        }).join("");

    if(!container.innerHTML){

        container.innerHTML=`
            <div class="card">
                <div class="empty">
                    集計対象の設問を選択してください
                </div>
            </div>
        `;
    }
}


function renderQuestionResult(q,index){

    const surveyAnswers =
        answers;

    if(q.type==="text"){

        return `
            <div style="margin-bottom:35px">
                <h3>
                    Q${index+1}.
                    ${escapeHtml(q.text)}
                </h3>

                <div>
                    ${
                        surveyAnswers.map(a=>`
                            <div class="answer-item">
                                <strong>
                                    ${escapeHtml(a.company)}
                                    /
                                    ${escapeHtml(a.name)}
                                </strong>

                                <div style="margin-top:5px">
                                    ${escapeHtml(
                                        a.values[index] || ""
                                    )}
                                </div>
                            </div>
                        `).join("")
                    }
                </div>
            </div>
        `;
    }

    const counts={};

    q.options.forEach(o=>{
        counts[o]=0;
    });

    surveyAnswers.forEach(a=>{

        const value =
            a.values[index];

        if(!value) return;

        if(q.type==="multi"){

            String(value)
                .split("、")
                .forEach(v=>{
                    if(counts[v]!==undefined){
                        counts[v]++;
                    }
                });

        }else{

            if(counts[value]!==undefined){
                counts[value]++;
            }
        }
    });

    const total =
        surveyAnswers.length || 1;

    return `
        <div style="margin-bottom:35px">

            <h3>
                Q${index+1}.
                ${escapeHtml(q.text)}
            </h3>

            <div class="chart">

                ${
                    Object.entries(counts)
                    .map(([label,count])=>{

                        const percent =
                            Math.round(
                                count/total*100
                            );

                        return `
                            <div class="bar-row">

                                <div>
                                    ${escapeHtml(label)}
                                </div>

                                <div class="bar-bg">
                                    <div
                                        class="bar"
                                        style="width:${percent}%">
                                    </div>
                                </div>

                                <div>
                                    ${count}件
                                    <small>
                                        (${percent}%)
                                    </small>
                                </div>

                            </div>
                        `;

                    }).join("")
                }

            </div>

        </div>
    `;
}


function renderAnswers(){

    const survey =
        surveys.find(s=>s.id===currentAnalysisSurveyId);

    if(!survey) return;

    const search =
        document.getElementById("answerSearch").value
        .toLowerCase();

    const data =
        answers.filter(a=>{

            return !search ||
                a.company.toLowerCase().includes(search) ||
                a.name.toLowerCase().includes(search);
        });

    const tbody =
        document.getElementById("answerTableBody");

    tbody.innerHTML =
        data.map(a=>`

            <tr>

                <td>
                    <strong>${escapeHtml(a.company)}</strong>
                    <br>
                    ${escapeHtml(a.name)}
                </td>

                <td>${escapeHtml(a.date)}</td>

                <td>
                    ${escapeHtml(a.values.join(" / "))}
                </td>

                <td>
                    <button class="btn btn-sm"
                        onclick="viewAnswer(${a.id})">
                        全回答を表示
                    </button>
                </td>

            </tr>

        `).join("");
}


function viewAnswer(id){

    const answer =
        answers.find(a=>a.id===id);

    if(!answer) return;

    const survey =
        surveys.find(s=>s.id===currentAnalysisSurveyId);

    let number=0;

    let html=`
        <div class="answer-detail">

            <p>
                <strong>会社名：</strong>
                ${escapeHtml(answer.company)}
            </p>

            <p>
                <strong>氏名：</strong>
                ${escapeHtml(answer.name)}
            </p>

            <p>
                <strong>回答日時：</strong>
                ${escapeHtml(answer.date)}
            </p>

            <hr>
    `;

    survey.groups.forEach(group=>{

        group.questions.forEach(q=>{

            html+=`
                <div class="answer-item">

                    <strong>
                        Q${number+1}.
                        ${escapeHtml(q.text)}
                    </strong>

                    <div style="margin-top:7px">
                        ${escapeHtml(
                            answer.values[number] || "未回答"
                        )}
                    </div>

                </div>
            `;

            number++;
        });

    });

    html+=`</div>`;

    openModal(
        "全回答を表示",
        html,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}


function downloadCSV(){

    const survey =
        surveys.find(s=>s.id===currentAnalysisSurveyId);

    if(!survey) return;

    const questions=[];

    survey.groups.forEach(g=>{
        g.questions.forEach(q=>{
            questions.push(q);
        });
    });

    const headers=[
        "回答ID",
        "回答日時",
        "顧客ID",
        "会社名",
        "氏名",
        ...questions.map((q,i)=>"設問"+(i+1))
    ];

    const rows=answers.map(a=>[
        a.id,
        a.date,
        a.customerId,
        a.company,
        a.name,
        ...a.values
    ]);

    const csv=[
        headers,
        ...rows
    ].map(row=>
        row.map(value=>
            `"${String(value??"").replaceAll('"','""')}"`
        ).join(",")
    ).join("\r\n");

    const bom="\uFEFF";

    const blob=
        new Blob(
            [bom+csv],
            {type:"text/csv;charset=utf-8"}
        );

    const url=
        URL.createObjectURL(blob);

    const a=
        document.createElement("a");

    a.href=url;
    a.download=
        "survey_"+currentAnalysisSurveyId+".csv";

    a.click();

    URL.revokeObjectURL(url);

    showToast("CSVをダウンロードしました");
}


function exportPDF(){

    showToast(
        "PDF出力モック：印刷ダイアログを開きます"
    );

    setTimeout(()=>{
        window.print();
    },300);
}


/* =========================================================
   kintone設定
========================================================= */

const kintoneFields=[
    {
        label:"会社名",
        code:"company_name"
    },
    {
        label:"氏名",
        code:"name"
    },
    {
        label:"メールアドレス",
        code:"email"
    },
    {
        label:"部署名",
        code:"department"
    },
    {
        label:"電話番号",
        code:"phone"
    },
    {
        label:"郵便番号",
        code:"postal_code"
    },
    {
        label:"住所",
        code:"address"
    },
    {
        label:"都道府県",
        code:"prefecture"
    }
];

let mappings={
    company:"company_name",
    name:"name",
    email:"email",
    department:"department",
    phone:"phone",
    address:"address"
};


function renderMapping(){

    const fields=
        document.getElementById("mappingBody");

    const systemFields=[
        ["company","会社名","顧客の所属企業・団体名"],
        ["name","氏名","顧客の担当者氏名"],
        ["email","メールアドレス","案内送信・検索キー"],
        ["department","部署名","所属部署・役職"],
        ["phone","電話番号","連絡先電話番号"],
        ["address","住所","郵便番号・所在地・送付先住所"]
    ];

    fields.innerHTML =
        systemFields.map(row=>`

            <tr>

                <td>
                    <strong>${row[1]}</strong>
                </td>

                <td>${row[2]}</td>

                <td>

                    <select
                        class="form-control"
                        style="width:100%"
                        onchange="mappings.${row[0]}=this.value">

                        <option value="">
                            -- 選択してください --
                        </option>

                        ${
                            kintoneFields.map(field=>`
                                <option
                                    value="${field.code}"
                                    ${mappings[row[0]]===field.code
                                        ?"selected"
                                        :""}>
                                    ${escapeHtml(field.label)}
                                </option>
                            `).join("")
                        }

                    </select>

                </td>

            </tr>

        `).join("");
}


function fetchKintoneFields(){

    const subdomain =
        document.getElementById("kSubdomain").value;

    const appId =
        document.getElementById("kAppId").value;

    if(!subdomain || !appId){

        showToast(
            "サブドメインとアプリIDを入力してください"
        );

        return;
    }

    showToast(
        "kintoneから項目一覧を取得しています..."
    );

    setTimeout(()=>{

        renderMapping();

        showToast(
            "項目一覧を取得しました（モック）"
        );

    },700);
}


function saveKintoneSettings(){

    showToast(
        "kintone連携設定を保存しました"
    );
}


/* =========================================================
   日付
========================================================= */

function getToday(){

    const d=new Date();

    return d.getFullYear()+"/"+
        String(d.getMonth()+1).padStart(2,"0")+"/"+
        String(d.getDate()).padStart(2,"0");
}


function getCurrentDateTime(){

    const d=new Date();

    return d.getFullYear()+"/"+
        String(d.getMonth()+1).padStart(2,"0")+"/"+
        String(d.getDate()).padStart(2,"0")+" "+
        String(d.getHours()).padStart(2,"0")+":"+
        String(d.getMinutes()).padStart(2,"0");
}


/* =========================================================
   初期化
========================================================= */

renderSurveyList();
renderMapping();

</script>

</body>
</html>
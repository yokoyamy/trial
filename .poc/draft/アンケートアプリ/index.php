<?php
/*
 * アンケート管理システム モック
 * index.php 1ファイル構成
 *
 * 実DB / kintone API / SMTP / 認証処理は使用しません。
 * サンプルデータをJavaScriptで保持し、画面操作をモックします。
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム モック</title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --muted:#64748b;
    --border:#dbe2ea;
    --bg:#f5f7fb;
    --card:#fff;
    --text:#1e293b;
    --sidebar:#172033;
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
    background:var(--bg);
    color:var(--text);
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.app{min-height:100vh}
.header{
    height:64px;
    background:#fff;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    padding:0 22px;
    position:sticky;
    top:0;
    z-index:20;
}
.logo{
    font-weight:800;
    font-size:18px;
    color:#172033;
    margin-right:35px;
}
.nav{
    display:flex;
    gap:5px;
    height:100%;
}
.nav button{
    border:0;
    background:transparent;
    padding:0 15px;
    color:#475569;
}
.nav button:hover,.nav button.active{
    color:var(--primary);
    background:#eff6ff;
}
.header-right{margin-left:auto;display:flex;align-items:center;gap:12px}
.user-badge{
    width:34px;height:34px;border-radius:50%;
    background:#dbeafe;color:#1d4ed8;
    display:flex;align-items:center;justify-content:center;
    font-weight:700;
}
.layout{display:flex}
.sidebar{
    width:230px;
    background:var(--sidebar);
    color:#cbd5e1;
    min-height:calc(100vh - 64px);
    padding:18px 12px;
    position:fixed;
    top:64px;
    bottom:0;
    left:0;
}
.sidebar-title{
    font-size:11px;
    color:#64748b;
    margin:10px 12px;
    text-transform:uppercase;
}
.sidebar button{
    width:100%;
    text-align:left;
    border:0;
    background:transparent;
    color:#cbd5e1;
    padding:12px;
    border-radius:8px;
    margin-bottom:3px;
}
.sidebar button:hover,.sidebar button.active{
    background:#27344d;
    color:#fff;
}
.main{
    margin-left:230px;
    width:calc(100% - 230px);
    padding:26px;
}
.page-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:22px;
}
.page-title h1{margin:0;font-size:24px}
.page-title p{margin:5px 0 0;color:var(--muted);font-size:13px}
.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:12px;
    padding:20px;
    margin-bottom:18px;
    box-shadow:0 1px 2px rgba(0,0,0,.03);
}
.card-title{
    font-weight:700;
    font-size:16px;
    margin-bottom:15px;
}
.btn{
    border:1px solid var(--border);
    background:#fff;
    color:#334155;
    padding:9px 15px;
    border-radius:7px;
    font-weight:600;
}
.btn:hover{background:#f8fafc}
.btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn-primary:hover{background:var(--primary-dark)}
.btn-success{background:var(--success);color:#fff;border-color:var(--success)}
.btn-danger{background:var(--danger);color:#fff;border-color:var(--danger)}
.btn-warning{background:var(--warning);color:#fff;border-color:var(--warning)}
.btn-sm{padding:6px 10px;font-size:12px}
.btn-group{display:flex;gap:8px;flex-wrap:wrap}
.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
}
.form-group{display:flex;flex-direction:column;gap:7px}
.form-group.full{grid-column:1/-1}
label{font-size:13px;font-weight:700}
input,textarea,select{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:7px;
    padding:10px 12px;
    background:#fff;
    color:#1e293b;
}
textarea{min-height:95px;resize:vertical}
input:focus,textarea:focus,select:focus{
    outline:2px solid #bfdbfe;
    border-color:var(--primary);
}
.toolbar{
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
}
.search-box{display:flex;gap:8px;flex:1;min-width:240px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:900px}
th,td{
    padding:13px 12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
    font-size:13px;
}
th{background:#f8fafc;color:#475569;font-weight:700}
tr:hover td{background:#fafcff}
.badge{
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
}
.badge-draft{background:#f1f5f9;color:#475569}
.badge-open{background:#dcfce7;color:#15803d}
.badge-stop{background:#fef3c7;color:#a16207}
.badge-end{background:#fee2e2;color:#b91c1c}
.empty{
    text-align:center;
    color:var(--muted);
    padding:40px 20px;
}
.toast{
    position:fixed;
    right:25px;
    bottom:25px;
    z-index:100;
    background:#172033;
    color:#fff;
    padding:13px 18px;
    border-radius:8px;
    box-shadow:0 8px 30px rgba(0,0,0,.2);
    display:none;
}
.modal-bg{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.48);
    z-index:80;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
}
.modal{
    background:#fff;
    width:min(620px,100%);
    max-height:90vh;
    overflow:auto;
    border-radius:12px;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
}
.modal-header{
    padding:18px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
}
.modal-header h3{margin:0}
.modal-body{padding:20px}
.modal-footer{
    padding:15px 20px;
    border-top:1px solid var(--border);
    display:flex;
    justify-content:flex-end;
    gap:8px;
}
.close{
    border:0;background:transparent;font-size:22px;color:#64748b;
}
.editor-actions{
    background:#fff;
    border:1px solid #bfdbfe;
    border-radius:12px;
    padding:14px;
    margin-bottom:16px;
    box-shadow:0 2px 5px rgba(37,99,235,.05);
}
.editor-actions-inner{
    display:flex;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
}
.editor-actions .status-area{
    display:flex;
    align-items:center;
    gap:8px;
    margin-left:auto;
    min-width:240px;
}
.status-select{
    font-weight:700;
    border-color:#93c5fd;
    background:#eff6ff;
}
.section-heading{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:14px;
}
.section-heading h2{font-size:17px;margin:0}
.radio-list{display:flex;gap:20px;flex-wrap:wrap}
.radio{
    display:flex;
    align-items:center;
    gap:7px;
    font-weight:500;
}
.radio input{width:auto}
.group-card{
    border:1px solid #cbd5e1;
    border-radius:12px;
    margin-bottom:18px;
    background:#fff;
}
.group-header{
    padding:13px 15px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    gap:10px;
}
.drag-handle{
    cursor:grab;
    color:#94a3b8;
    user-select:none;
}
.group-title-input{
    flex:1;
    font-weight:700;
    background:#fff;
}
.group-body{padding:15px}
.question{
    border:1px solid #e2e8f0;
    border-radius:10px;
    margin-bottom:12px;
    background:#fff;
}
.question.dragging,.group-card.dragging{opacity:.45}
.question-header{
    display:flex;
    align-items:center;
    gap:9px;
    padding:11px 13px;
    border-bottom:1px solid #e2e8f0;
    background:#fafcff;
}
.question-number{
    font-weight:800;
    color:var(--primary);
    min-width:55px;
}
.question-body{padding:14px}
.question-grid{
    display:grid;
    grid-template-columns:1fr 180px;
    gap:12px;
}
.question-options{
    margin-top:12px;
    padding:12px;
    background:#f8fafc;
    border-radius:8px;
}
.option-row{
    display:flex;
    gap:7px;
    margin-bottom:7px;
}
.option-row input{flex:1}
.required{
    display:flex;
    align-items:center;
    gap:6px;
    font-size:12px;
    margin-top:10px;
}
.required input{width:auto}
.add-question{
    width:100%;
    border:1px dashed #94a3b8;
    background:#f8fafc;
    padding:10px;
    color:#475569;
    border-radius:7px;
}
.add-question:hover{background:#eff6ff;color:var(--primary)}
.add-group{
    width:100%;
    padding:13px;
    border:1px dashed #60a5fa;
    background:#eff6ff;
    color:var(--primary);
    border-radius:8px;
    font-weight:700;
}
.preview-shell{
    background:#e2e8f0;
    padding:30px;
    border-radius:12px;
}
.preview-device{
    background:#fff;
    max-width:760px;
    margin:auto;
    border-radius:12px;
    overflow:hidden;
    box-shadow:0 10px 30px rgba(0,0,0,.12);
}
.preview-device.mobile{max-width:390px}
.preview-top{
    padding:22px;
    background:#172033;
    color:#fff;
}
.preview-content{padding:22px}
.preview-question{
    padding:18px;
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:14px;
}
.preview-option{
    display:block;
    padding:10px;
    border:1px solid #e2e8f0;
    margin-top:7px;
    border-radius:7px;
}
.kpi-grid{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
}
.kpi{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
}
.kpi .label{color:var(--muted);font-size:11px}
.kpi .value{font-size:25px;font-weight:800;margin-top:5px}
.bar-row{margin-bottom:15px}
.bar-label{display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px}
.bar-bg{height:12px;background:#e2e8f0;border-radius:999px;overflow:hidden}
.bar{height:100%;background:var(--primary);border-radius:999px}
.checkbox-list{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:8px;
}
.check-item{
    border:1px solid var(--border);
    padding:9px;
    border-radius:7px;
    display:flex;
    gap:7px;
    align-items:center;
}
.check-item input{width:auto}
.alert{
    padding:12px 14px;
    border-radius:8px;
    background:#eff6ff;
    color:#1d4ed8;
    margin-bottom:15px;
    font-size:13px;
}
.alert.success{background:#f0fdf4;color:#15803d}
.alert.warning{background:#fffbeb;color:#a16207}
.tabs{
    display:flex;
    gap:4px;
    border-bottom:1px solid var(--border);
    margin-bottom:18px;
}
.tab{
    border:0;
    background:transparent;
    padding:10px 15px;
    color:#64748b;
    border-bottom:2px solid transparent;
}
.tab.active{color:var(--primary);border-color:var(--primary);font-weight:700}
.hidden{display:none!important}
.muted{color:var(--muted)}
.small{font-size:12px}
.preview-toggle{display:flex;gap:7px}
.stat-row{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
}
.mail-preview{
    background:#f8fafc;
    padding:16px;
    border-radius:8px;
    white-space:pre-wrap;
    line-height:1.7;
}
.mapping-row{
    display:grid;
    grid-template-columns:170px 1fr;
    align-items:start;
    gap:15px;
    padding:13px 0;
    border-bottom:1px solid #e2e8f0;
}
@media(max-width:1000px){
    .sidebar{width:190px}
    .main{margin-left:190px;width:calc(100% - 190px)}
    .kpi-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:760px){
    .header{padding:0 12px}
    .logo{margin-right:8px}
    .nav{display:none}
    .sidebar{
        position:static;
        width:100%;
        min-height:auto;
        display:flex;
        overflow-x:auto;
        padding:7px;
    }
    .sidebar-title{display:none}
    .sidebar button{
        min-width:max-content;
        margin:0 3px;
    }
    .layout{display:block}
    .main{
        margin-left:0;
        width:100%;
        padding:15px;
    }
    .form-grid,.question-grid,.stat-row{grid-template-columns:1fr}
    .kpi-grid{grid-template-columns:1fr 1fr}
    .editor-actions .status-area{margin-left:0;width:100%}
    .mapping-row{grid-template-columns:1fr}
}
</style>
</head>

<body>
<div class="app">

<header class="header">
    <div class="logo">Survey Manager</div>

    <nav class="nav">
        <button id="nav-list" onclick="showPage('list')">アンケート一覧</button>
        <button id="nav-kintone" onclick="showPage('kintone')">kintone連携設定</button>
        <button id="nav-mailserver" onclick="showPage('mailserver')">メールサーバ設定</button>
    </nav>

    <div class="header-right">
        <span class="small muted">管理者</span>
        <div class="user-badge">A</div>
        <button class="btn btn-sm" onclick="logoutMock()">ログアウト</button>
    </div>
</header>

<div class="layout">

<aside class="sidebar">
    <div class="sidebar-title">MENU</div>
    <button onclick="showPage('list')" id="side-list">📋 アンケート一覧</button>
    <button onclick="showPage('kintone')" id="side-kintone">🔗 kintone連携設定</button>
    <button onclick="showPage('mailserver')" id="side-mailserver">✉ メールサーバ設定</button>
    <div class="sidebar-title">サンプル操作</div>
    <button onclick="openAnswerPage()">📱 回答者画面</button>
</aside>

<main class="main">

<!-- ======================================================
     一覧画面
====================================================== -->
<section id="page-list" class="page">

    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <p>アンケートの確認・編集、集計、送信を行います。</p>
        </div>
        <button class="btn btn-primary" onclick="newSurvey()">＋ 新規アンケート作成</button>
    </div>

    <div class="card">
        <div class="toolbar">
            <div class="search-box">
                <input id="surveySearch" placeholder="タイトルで検索（Enterで検索）"
                       onkeydown="if(event.key==='Enter')renderSurveyList()">
                <button class="btn" onclick="renderSurveyList()">検索</button>
            </div>

            <select id="statusFilter" onchange="renderSurveyList()">
                <option value="all">ステータス：すべて</option>
                <option value="公開中">公開中</option>
                <option value="下書き">下書き</option>
                <option value="停止">停止</option>
                <option value="終了">終了</option>
            </select>

            <select id="sortFilter" onchange="renderSurveyList()">
                <option value="updatedDesc">更新日：新しい順</option>
                <option value="updatedAsc">更新日：古い順</option>
                <option value="answersDesc">回答数：多い順</option>
                <option value="answersAsc">回答数：少ない順</option>
                <option value="startDesc">開始日：新しい順</option>
                <option value="startAsc">開始日：古い順</option>
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


<!-- ======================================================
     作成・編集画面
====================================================== -->
<section id="page-editor" class="page hidden">

    <div class="page-title">
        <div>
            <h1 id="editorTitle">アンケート作成</h1>
            <p>アンケートの内容と公開状態を管理します。</p>
        </div>
        <div class="btn-group">
            <button class="btn" onclick="openPreview()">プレビュー</button>
            <button class="btn" onclick="cancelEditor()">キャンセル</button>
            <button class="btn btn-primary" onclick="saveAndBack()">保存して一覧へ戻る</button>
        </div>
    </div>

    <!-- ★重要：基本情報より上 -->
    <div class="editor-actions">
        <div class="editor-actions-inner">

            <button class="btn btn-primary" onclick="saveDraft()">
                💾 下書き保存
            </button>

            <span class="small muted">
                入力内容を下書きとして保存
            </span>

            <div class="status-area">
                <label for="statusSelect">状態：</label>
                <select id="statusSelect" class="status-select"
                        onchange="statusSelected(this.value)">
                </select>
            </div>

        </div>
    </div>

    <!-- 基本情報 -->
    <div class="card">
        <div class="section-heading">
            <h2>基本情報</h2>
        </div>

        <div class="form-grid">

            <div class="form-group full">
                <label>アンケートタイトル</label>
                <input id="surveyTitle" placeholder="アンケートタイトル">
            </div>

            <div class="form-group full">
                <label>アンケート説明</label>
                <textarea id="surveyDescription"
                          placeholder="アンケートの説明を入力してください"></textarea>
            </div>

            <div class="form-group">
                <label>開始日時</label>
                <input type="datetime-local" id="surveyStart">
            </div>

            <div class="form-group">
                <label>終了日時</label>
                <input type="datetime-local" id="surveyEnd">
            </div>

            <div class="form-group full">
                <label>質問番号の採番方式</label>

                <div class="radio-list">
                    <label class="radio">
                        <input type="radio" name="numbering"
                               value="global"
                               onchange="setNumbering('global')">
                        アンケート全体で通番
                        <span class="muted small">（Q1、Q2、Q3…）</span>
                    </label>

                    <label class="radio">
                        <input type="radio" name="numbering"
                               value="group"
                               onchange="setNumbering('group')">
                        グループ毎に採番
                        <span class="muted small">（Q1-1、Q1-2…）</span>
                    </label>
                </div>
            </div>

        </div>
    </div>

    <!-- グループ -->
    <div class="card">
        <div class="section-heading">
            <h2>質問・グループ</h2>
            <span class="muted small">ドラッグ＆ドロップで並び替えできます</span>
        </div>

        <div id="groupsContainer"></div>

        <!-- ★グループ一覧の末尾 -->
        <button class="add-group" onclick="addGroup()">
            ＋ グループを追加
        </button>
    </div>

</section>


<!-- ======================================================
     プレビュー
====================================================== -->
<section id="page-preview" class="page hidden">

    <div class="page-title">
        <div>
            <h1>アンケートプレビュー</h1>
            <p>回答者から見た表示を確認できます。</p>
        </div>

        <div class="btn-group">
            <button class="btn" onclick="setPreviewDevice('pc')">PC表示</button>
            <button class="btn" onclick="setPreviewDevice('mobile')">スマートフォン表示</button>
            <button class="btn" onclick="showPage('editor')">編集へ戻る</button>
        </div>
    </div>

    <div class="alert">
        これはプレビュー表示です。送信操作を行っても実際の送信は行われません。
    </div>

    <div class="preview-shell">
        <div id="previewDevice" class="preview-device">
            <div class="preview-top">
                <div class="small">アンケート</div>
                <h2 id="previewTitle">アンケートタイトル</h2>
                <div id="previewDescription"></div>
            </div>
            <div class="preview-content" id="previewContent"></div>
        </div>
    </div>

</section>


<!-- ======================================================
     メール送信
====================================================== -->
<section id="page-send" class="page hidden">

    <div class="page-title">
        <div>
            <h1>顧客選択・メール送信</h1>
            <p id="sendSurveyName"></p>
        </div>
        <button class="btn" onclick="showPage('list')">一覧へ戻る</button>
    </div>

    <div class="card">
        <div class="section-heading">
            <h2>顧客選択</h2>
            <div class="btn-group">
                <button class="btn btn-sm" onclick="selectAllCustomers(true)">全選択</button>
                <button class="btn btn-sm" onclick="selectAllCustomers(false)">選択解除</button>
                <button class="btn btn-sm" onclick="filterUnanswered()">未回答者のみ</button>
            </div>
        </div>

        <div class="toolbar" style="margin-bottom:15px">
            <input id="customerSearch" placeholder="顧客名・組織名・メールアドレスで検索"
                   oninput="renderCustomers()">
            <select id="customerStatusFilter" onchange="renderCustomers()">
                <option value="all">すべて</option>
                <option value="未送信">未送信</option>
                <option value="送信済み / 未回答">送信済み / 未回答</option>
                <option value="回答済み">回答済み</option>
            </select>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th><input type="checkbox" onchange="selectAllCustomers(this.checked)"></th>
                    <th>組織名</th>
                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th>電話番号</th>
                    <th>回答ステータス</th>
                    <th>最終送信日時</th>
                    <th>送信回数</th>
                    <th>送信文</th>
                </tr>
                </thead>
                <tbody id="customerTable"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-title">メールテンプレート</div>

        <div class="form-grid">
            <div class="form-group full">
                <label>メール件名</label>
                <input id="mailSubject" value="アンケートご回答のお願い">
            </div>

            <div class="form-group full">
                <label>メール本文</label>
                <textarea id="mailBody" style="min-height:220px">{顧客名} 様

いつもお世話になっております。

以下のアンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力のほど、よろしくお願いいたします。</textarea>
            </div>
        </div>

        <div class="alert">
            使用可能な動的変数：{顧客名}　{アンケートURL}
        </div>

        <button class="btn btn-primary" onclick="sendSelectedCustomers()">
            選択した顧客へ一括送信
        </button>
    </div>

    <div class="card">
        <div class="section-heading">
            <h2>送信履歴</h2>
            <button class="btn btn-sm" onclick="showPage('history')">詳細を見る</button>
        </div>
        <div id="sendHistoryMini"></div>
    </div>

</section>


<!-- ======================================================
     送信履歴
====================================================== -->
<section id="page-history" class="page hidden">

    <div class="page-title">
        <div>
            <h1>送信履歴</h1>
            <p>アンケートメールの送信履歴を確認できます。</p>
        </div>
        <button class="btn" onclick="showPage('list')">一覧へ戻る</button>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>送信日時</th>
                    <th>送信種別</th>
                    <th>送信件数</th>
                    <th>送信件名</th>
                    <th>実行者</th>
                    <th>対象顧客</th>
                    <th>内容</th>
                </tr>
                </thead>
                <tbody id="historyTable"></tbody>
            </table>
        </div>
    </div>

</section>


<!-- ======================================================
     集計
====================================================== -->
<section id="page-analysis" class="page hidden">

    <div class="page-title">
        <div>
            <h1>回答集計・分析</h1>
            <p id="analysisSurveyName"></p>
        </div>

        <div class="btn-group">
            <button class="btn" onclick="exportCSV()">CSV出力</button>
            <button class="btn" onclick="exportPDF()">PDF出力</button>
            <button class="btn" onclick="showPage('list')">一覧へ戻る</button>
        </div>
    </div>

    <div class="kpi-grid" id="analysisKpi"></div>

    <div class="card">
        <div class="section-heading">
            <h2>設問フィルター</h2>
            <div class="btn-group">
                <button class="btn btn-sm" onclick="selectAllQuestions(true)">すべて選択</button>
                <button class="btn btn-sm" onclick="selectAllQuestions(false)">すべて解除</button>
            </div>
        </div>

        <div class="checkbox-list" id="questionFilter"></div>
    </div>

    <div class="card">
        <div class="card-title">設問別集計</div>
        <div id="analysisQuestions"></div>
    </div>

    <div class="card">
        <div class="card-title">個別回答一覧</div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>組織名</th>
                    <th>氏名</th>
                    <th>回答日時</th>
                    <th>回答概要</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody id="answersTable"></tbody>
            </table>
        </div>
    </div>

</section>


<!-- ======================================================
     kintone設定
====================================================== -->
<section id="page-kintone" class="page hidden">

    <div class="page-title">
        <div>
            <h1>kintone連携設定</h1>
            <p>顧客情報との連携設定を行います。</p>
        </div>
    </div>

    <div class="card">
        <div class="card-title">接続設定</div>

        <div class="form-grid">

            <div class="form-group full">
                <label>サブドメイン</label>
                <input id="kSubdomain" value="example.cybozu.com">
            </div>

            <div class="form-group">
                <label>顧客管理アプリID</label>
                <input id="kAppId" value="123">
            </div>

            <div class="form-group">
                <label>ログイン名</label>
                <input id="kLogin" value="admin">
            </div>

            <div class="form-group">
                <label>パスワード</label>
                <input id="kPassword" type="password" value="password">
            </div>

            <!-- メールアドレス入力欄は意図的に存在しない -->

            <div class="form-group full">
                <label class="radio">
                    <input type="checkbox" id="kSSL" checked>
                    SSL証明書を検証する
                </label>
            </div>

        </div>

        <div class="btn-group" style="margin-top:18px">
            <button class="btn btn-primary" onclick="saveKintone()">
                設定を保存
            </button>
            <button class="btn" onclick="testKintone()">
                接続確認
            </button>
        </div>

        <div id="kintoneStatus" style="margin-top:15px"></div>
    </div>

    <div class="card">
        <div class="section-heading">
            <h2>kintone項目</h2>
            <button class="btn btn-primary btn-sm" onclick="getKintoneFields()">
                項目一覧を再取得
            </button>
        </div>

        <div id="kintoneFields"></div>
    </div>

    <div class="card">
        <div class="card-title">フィールドマッピング</div>

        <div class="mapping-row">
            <strong>組織名</strong>
            <select>
                <option>会社名</option>
                <option>組織名</option>
                <option>未設定</option>
            </select>
        </div>

        <div class="mapping-row">
            <strong>氏名</strong>
            <select>
                <option>氏名</option>
                <option>担当者名</option>
                <option>未設定</option>
            </select>
        </div>

        <div class="mapping-row">
            <strong>メールアドレス</strong>
            <select>
                <option>メールアドレス</option>
                <option>Email</option>
                <option>未設定</option>
            </select>
        </div>

        <div class="mapping-row">
            <strong>部署名</strong>
            <select>
                <option>部署名</option>
                <option>所属</option>
                <option>未設定</option>
            </select>
        </div>

        <div class="mapping-row">
            <strong>電話番号</strong>
            <select>
                <option>電話番号</option>
                <option>TEL</option>
                <option>未設定</option>
            </select>
        </div>

        <div class="mapping-row">
            <strong>住所</strong>
            <div class="checkbox-list">
                <label class="check-item"><input type="checkbox" checked> 都道府県</label>
                <label class="check-item"><input type="checkbox" checked> 市区町村</label>
                <label class="check-item"><input type="checkbox"> 番地</label>
                <label class="check-item"><input type="checkbox"> 建物名</label>
                <label class="check-item"><input type="checkbox"> 郵便番号</label>
            </div>
        </div>

        <div class="btn-group" style="margin-top:18px">
            <button class="btn btn-primary" onclick="syncCustomers()">
                顧客情報を同期
            </button>
        </div>
    </div>

</section>


<!-- ======================================================
     メールサーバ設定
====================================================== -->
<section id="page-mailserver" class="page hidden">

    <div class="page-title">
        <div>
            <h1>メールサーバ設定</h1>
            <p>アンケートメール送信に利用するサーバを設定します。</p>
        </div>
    </div>

    <div class="card">

        <div class="form-grid">

            <div class="form-group full">
                <label>SMTPサーバ</label>
                <input id="smtpHost" value="smtp.example.jp">
            </div>

            <div class="form-group">
                <label>SMTPポート</label>
                <input id="smtpPort" value="587">
            </div>

            <div class="form-group">
                <label>暗号化方式</label>
                <select id="smtpEncryption">
                    <option>SSL</option>
                    <option selected>TLS</option>
                    <option>なし</option>
                </select>
            </div>

            <div class="form-group">
                <label>SMTPユーザー名</label>
                <input value="survey@example.jp">
            </div>

            <div class="form-group">
                <label>SMTPパスワード</label>
                <input type="password" value="password">
            </div>

            <div class="form-group">
                <label>送信元メールアドレス</label>
                <input value="survey@example.jp">
            </div>

            <div class="form-group">
                <label>送信元名</label>
                <input value="アンケート事務局">
            </div>

            <div class="form-group">
                <label>返信先メールアドレス</label>
                <input value="support@example.jp">
            </div>

        </div>

        <div class="btn-group" style="margin-top:18px">
            <button class="btn btn-primary" onclick="saveMailServer()">設定を保存</button>
            <button class="btn" onclick="testMailServer()">接続確認</button>
            <button class="btn" onclick="testMail()">テストメール送信</button>
        </div>

        <div id="mailServerStatus" style="margin-top:15px"></div>

    </div>

</section>


<!-- ======================================================
     回答者画面
====================================================== -->
<section id="page-answer" class="page hidden">

    <div class="page-title">
        <div>
            <h1>アンケート回答画面</h1>
            <p>回答者向け画面のモックです。</p>
        </div>

        <button class="btn" onclick="showPage('list')">管理画面へ戻る</button>
    </div>

    <div class="preview-shell">

        <div class="preview-device">

            <div class="preview-top">
                <div class="small">アンケート</div>
                <h2 id="answerTitle"></h2>
                <div id="answerDescription"></div>
            </div>

            <div class="preview-content">

                <div class="alert">
                    個別回答URLからアクセスしています。
                </div>

                <div id="answerQuestions"></div>

                <div class="btn-group" style="justify-content:space-between">
                    <button class="btn" onclick="toast('前のページへ戻ります')">戻る</button>
                    <button class="btn btn-primary" onclick="answerConfirm()">
                        回答確認
                    </button>
                </div>

            </div>
        </div>
    </div>

</section>


<!-- ======================================================
     回答完了
====================================================== -->
<section id="page-complete" class="page hidden">

    <div style="max-width:600px;margin:70px auto">
        <div class="card" style="text-align:center;padding:50px 30px">

            <div style="font-size:55px">✓</div>

            <h1>回答ありがとうございました</h1>

            <p class="muted">
                アンケートの回答が正常に送信されました。
            </p>

            <button class="btn btn-primary" onclick="showPage('list')">
                管理画面へ戻る
            </button>

        </div>
    </div>

</section>

</main>
</div>
</div>


<!-- ======================================================
     Modal
====================================================== -->
<div id="modalBg" class="modal-bg">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modalTitle">確認</h3>
            <button class="close" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <div class="modal-footer" id="modalFooter"></div>
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
        title:"2026年度 顧客満足度アンケート",
        description:"サービスについてのご意見をお聞かせください。",
        start:"2026-08-01T09:00",
        end:"2026-09-30T18:00",
        status:"公開中",
        numbering:"global",
        created:"2026/07/20",
        updated:"2026/08/20",
        answers:82,
        groups:[
            {
                id:101,
                title:"サービス利用について",
                questions:[
                    {
                        id:1001,
                        text:"サービスを利用したことがありますか？",
                        type:"single",
                        required:true,
                        options:["はい","いいえ"],
                        branch:{}
                    },
                    {
                        id:1002,
                        text:"サービスに満足していますか？",
                        type:"single",
                        required:true,
                        options:["非常に満足","満足","普通","不満","非常に不満"],
                        branch:{}
                    }
                ]
            },
            {
                id:102,
                title:"ご意見・ご要望",
                questions:[
                    {
                        id:1003,
                        text:"ご意見・ご要望がございましたらご記入ください。",
                        type:"textarea",
                        required:false,
                        options:[],
                        branch:{}
                    }
                ]
            }
        ]
    },
    {
        id:2,
        title:"新サービス利用意向調査",
        description:"新サービスに関するアンケートです。",
        start:"2026-09-01T09:00",
        end:"2026-10-15T18:00",
        status:"下書き",
        numbering:"group",
        created:"2026/08/10",
        updated:"2026/08/22",
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
                        options:["ぜひ利用したい","利用したい","わからない","利用したくない"],
                        branch:{}
                    }
                ]
            }
        ]
    },
    {
        id:3,
        title:"イベント参加者アンケート",
        description:"イベントにご参加いただきありがとうございました。",
        start:"2026-06-01T10:00",
        end:"2026-06-30T18:00",
        status:"終了",
        numbering:"global",
        created:"2026/05/01",
        updated:"2026/07/01",
        answers:126,
        groups:[
            {
                id:301,
                title:"イベントについて",
                questions:[
                    {
                        id:3001,
                        text:"イベントはいかがでしたか？",
                        type:"single",
                        required:true,
                        options:["とても良かった","良かった","普通","悪かった"],
                        branch:{}
                    }
                ]
            }
        ]
    }
];

let customers = [
    {
        id:1,
        org:"株式会社サンプル",
        name:"山田 太郎",
        email:"yamada@example.jp",
        tel:"03-1234-5678",
        status:"回答済み",
        sent:"2026/08/20 10:10",
        count:1
    },
    {
        id:2,
        org:"株式会社テスト",
        name:"佐藤 花子",
        email:"sato@example.jp",
        tel:"03-2345-6789",
        status:"送信済み / 未回答",
        sent:"2026/08/21 11:00",
        count:1
    },
    {
        id:3,
        org:"株式会社ABC",
        name:"鈴木 一郎",
        email:"suzuki@example.jp",
        tel:"03-3456-7890",
        status:"未送信",
        sent:"-",
        count:0
    },
    {
        id:4,
        org:"合同会社デモ",
        name:"田中 美咲",
        email:"tanaka@example.jp",
        tel:"03-4567-8901",
        status:"送信済み / 未回答",
        sent:"2026/08/23 15:30",
        count:2
    },
    {
        id:5,
        org:"株式会社サンプル2",
        name:"高橋 健",
        email:"takahashi@example.jp",
        tel:"03-5678-9012",
        status:"回答済み",
        sent:"2026/08/19 09:20",
        count:1
    }
];

let histories = [
    {
        date:"2026/08/23 15:30",
        type:"一括送信",
        count:1,
        subject:"アンケートご回答のお願い",
        user:"管理者",
        targets:"田中 美咲",
        surveyId:1
    },
    {
        date:"2026/08/21 11:00",
        type:"一括送信",
        count:1,
        subject:"アンケートご回答のお願い",
        user:"管理者",
        targets:"佐藤 花子",
        surveyId:1
    }
];

let currentSurveyId = null;
let currentEditorSurvey = null;
let currentSendSurveyId = null;
let currentAnalysisSurveyId = null;
let nextId = 10000;


/* =========================================================
   共通
========================================================= */

function clone(obj){
    return JSON.parse(JSON.stringify(obj));
}

function escapeHtml(str){
    if(str === undefined || str === null) return "";
    return String(str)
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;")
        .replace(/'/g,"&#039;");
}

function toast(message){
    const el=document.getElementById("toast");
    el.textContent=message;
    el.style.display="block";
    clearTimeout(window.toastTimer);
    window.toastTimer=setTimeout(()=>{
        el.style.display="none";
    },2500);
}

function openModal(title,body,buttons){
    document.getElementById("modalTitle").textContent=title;
    document.getElementById("modalBody").innerHTML=body;

    const footer=document.getElementById("modalFooter");
    footer.innerHTML="";

    buttons.forEach(b=>{
        const btn=document.createElement("button");
        btn.className="btn "+(b.className||"");
        btn.textContent=b.label;
        btn.onclick=()=>{
            if(b.action)b.action();
        };
        footer.appendChild(btn);
    });

    document.getElementById("modalBg").style.display="flex";
}

function closeModal(){
    document.getElementById("modalBg").style.display="none";
}

function confirmAction(title,message,action){
    openModal(
        title,
        `<p>${escapeHtml(message)}</p>`,
        [
            {label:"キャンセル",action:closeModal},
            {label:"実行",className:"btn-primary",action:()=>{
                closeModal();
                action();
            }}
        ]
    );
}


/* =========================================================
   ページ切替
========================================================= */

function showPage(page){
    document.querySelectorAll(".page").forEach(p=>p.classList.add("hidden"));

    const target=document.getElementById("page-"+page);
    if(target)target.classList.remove("hidden");

    document.querySelectorAll(".nav button,.sidebar button")
        .forEach(b=>b.classList.remove("active"));

    const nav=document.getElementById("nav-"+page);
    const side=document.getElementById("side-"+page);

    if(nav)nav.classList.add("active");
    if(side)side.classList.add("active");

    if(page==="list")renderSurveyList();
    if(page==="history")renderHistory();
    if(page==="kintone")renderKintoneFields();
    if(page==="mailserver"){}
}


/* =========================================================
   一覧
========================================================= */

function statusBadge(status){
    let cls="badge-draft";
    if(status==="公開中")cls="badge-open";
    if(status==="停止")cls="badge-stop";
    if(status==="終了")cls="badge-end";
    return `<span class="badge ${cls}">${escapeHtml(status)}</span>`;
}

function renderSurveyList(){

    const keyword=(document.getElementById("surveySearch")?.value||"")
        .trim().toLowerCase();

    const filter=document.getElementById("statusFilter")?.value||"all";
    const sort=document.getElementById("sortFilter")?.value||"updatedDesc";

    let data=surveys.filter(s=>{
        const matchKeyword=!keyword||s.title.toLowerCase().includes(keyword);
        const matchStatus=filter==="all"||s.status===filter;
        return matchKeyword&&matchStatus;
    });

    data.sort((a,b)=>{
        if(sort==="updatedDesc")return b.updated.localeCompare(a.updated);
        if(sort==="updatedAsc")return a.updated.localeCompare(b.updated);
        if(sort==="answersDesc")return b.answers-a.answers;
        if(sort==="answersAsc")return a.answers-b.answers;
        if(sort==="startDesc")return b.start.localeCompare(a.start);
        if(sort==="startAsc")return a.start.localeCompare(b.start);
        return 0;
    });

    const tbody=document.getElementById("surveyTable");

    if(!data.length){
        tbody.innerHTML=`
            <tr>
                <td colspan="6" class="empty">
                    該当するアンケートはありません。
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML=data.map(s=>`
        <tr>
            <td>
                <div>${escapeHtml(s.created)}</div>
                <div class="muted small">更新：${escapeHtml(s.updated)}</div>
            </td>

            <td>
                <strong>${escapeHtml(s.title)}</strong>
            </td>

            <td>
                ${formatDate(s.start)}<br>
                ～ ${formatDate(s.end)}
            </td>

            <td>${statusBadge(s.status)}</td>

            <td>${s.answers}件</td>

            <td>
                <div class="btn-group">
                    <button class="btn btn-sm"
                        onclick="editSurvey(${s.id})">
                        確認・編集
                    </button>

                    <button class="btn btn-sm"
                        onclick="openAnalysis(${s.id})">
                        集計
                    </button>

                    <button class="btn btn-sm"
                        onclick="openSend(${s.id})">
                        送信
                    </button>

                    <button class="btn btn-sm"
                        onclick="duplicateSurvey(${s.id})">
                        複製
                    </button>

                    <button class="btn btn-sm btn-danger"
                        onclick="deleteSurvey(${s.id})">
                        削除
                    </button>
                </div>
            </td>
        </tr>
    `).join("");
}

function formatDate(v){
    if(!v)return"-";
    return v.replace("T"," ");
}

function newSurvey(){

    currentSurveyId=null;

    currentEditorSurvey={
        id:null,
        title:"",
        description:"",
        start:"",
        end:"",
        status:"下書き",
        numbering:"global",
        created:"",
        updated:"",
        answers:0,
        groups:[
            {
                id:nextId++,
                title:"グループ1",
                questions:[]
            }
        ]
    };

    loadEditor();
    showPage("editor");
}

function editSurvey(id){

    const survey=surveys.find(s=>s.id===id);
    if(!survey)return;

    currentSurveyId=id;
    currentEditorSurvey=clone(survey);

    loadEditor();
    showPage("editor");
}

function duplicateSurvey(id){

    const original=surveys.find(s=>s.id===id);
    if(!original)return;

    confirmAction(
        "アンケートを複製",
        `「${original.title}」を下書きとして複製しますか？`,
        ()=>{
            const copy=clone(original);
            copy.id=nextId++;
            copy.title=original.title+"（コピー）";
            copy.status="下書き";
            copy.answers=0;
            copy.created=new Date().toLocaleDateString("ja-JP");
            copy.updated=new Date().toLocaleDateString("ja-JP");

            copy.groups.forEach(g=>{
                g.id=nextId++;
                g.questions.forEach(q=>q.id=nextId++);
            });

            surveys.unshift(copy);
            renderSurveyList();
            toast("アンケートを複製しました");
        }
    );
}

function deleteSurvey(id){

    const survey=surveys.find(s=>s.id===id);
    if(!survey)return;

    confirmAction(
        "アンケートを削除",
        `「${survey.title}」を削除しますか？この操作はモック上で反映されます。`,
        ()=>{
            surveys=surveys.filter(s=>s.id!==id);
            renderSurveyList();
            toast("アンケートを削除しました");
        }
    );
}


/* =========================================================
   エディタ
========================================================= */

function loadEditor(){

    const s=currentEditorSurvey;

    document.getElementById("editorTitle").textContent=
        s.id?"アンケート確認・編集":"アンケート作成";

    document.getElementById("surveyTitle").value=s.title||"";
    document.getElementById("surveyDescription").value=s.description||"";
    document.getElementById("surveyStart").value=s.start||"";
    document.getElementById("surveyEnd").value=s.end||"";

    document.querySelectorAll("input[name='numbering']").forEach(r=>{
        r.checked=r.value===s.numbering;
    });

    renderStatusSelect();
    renderGroups();
}

function updateEditorData(){

    currentEditorSurvey.title=document.getElementById("surveyTitle").value;
    currentEditorSurvey.description=document.getElementById("surveyDescription").value;
    currentEditorSurvey.start=document.getElementById("surveyStart").value;
    currentEditorSurvey.end=document.getElementById("surveyEnd").value;
}

function renderStatusSelect(){

    const select=document.getElementById("statusSelect");
    const status=currentEditorSurvey.status;

    let options=[];

    if(status==="下書き"){
        options=[
            ["下書き","下書き"],
            ["公開","公開"]
        ];
    }else if(status==="公開中"){
        options=[
            ["公開中","公開中"],
            ["停止","停止"]
        ];
    }else if(status==="停止"){
        options=[
            ["停止","停止"],
            ["再開","再開"]
        ];
    }else{
        options=[
            ["終了","終了"]
        ];
    }

    select.innerHTML=options.map(o=>
        `<option value="${escapeHtml(o[0])}">
            ${escapeHtml(o[1])}
        </option>`
    ).join("");

    select.value=status==="公開中"?"公開中":status;
    select.disabled=status==="終了";
}

function statusSelected(value){

    const current=currentEditorSurvey.status;

    if(value===current)return;

    if(current==="下書き" && value==="公開"){
        confirmStatusChange("公開","このアンケートを公開しますか？","公開中");
    }
    else if(current==="公開中" && value==="停止"){
        confirmStatusChange("停止","このアンケートを停止しますか？","停止");
    }
    else if(current==="停止" && value==="再開"){
        confirmStatusChange("再開","このアンケートを再開しますか？","公開中");
    }
    else{
        renderStatusSelect();
    }
}

function confirmStatusChange(type,message,newStatus){

    confirmAction(
        `アンケートを${type}`,
        message,
        ()=>{
            currentEditorSurvey.status=newStatus;
            currentEditorSurvey.updated=new Date().toLocaleDateString("ja-JP");

            renderStatusSelect();

            toast(
                newStatus==="公開中"
                    ?"ステータスを「公開中」に変更しました"
                    :`ステータスを「${newStatus}」に変更しました`
            );
        }
    );
}

function saveDraft(){

    updateEditorData();

    currentEditorSurvey.status="下書き";
    currentEditorSurvey.updated=new Date().toLocaleDateString("ja-JP");

    if(!currentEditorSurvey.id){
        currentEditorSurvey.id=nextId++;
        currentEditorSurvey.created=new Date().toLocaleDateString("ja-JP");
        surveys.unshift(clone(currentEditorSurvey));
        currentSurveyId=currentEditorSurvey.id;
    }else{
        const index=surveys.findIndex(s=>s.id===currentEditorSurvey.id);
        if(index>=0)surveys[index]=clone(currentEditorSurvey);
    }

    renderStatusSelect();

    toast("下書きを保存しました");
}

function saveAndBack(){

    updateEditorData();

    if(!currentEditorSurvey.id){
        currentEditorSurvey.id=nextId++;
        currentEditorSurvey.created=new Date().toLocaleDateString("ja-JP");
        currentEditorSurvey.updated=new Date().toLocaleDateString("ja-JP");
        surveys.unshift(clone(currentEditorSurvey));
    }else{
        currentEditorSurvey.updated=new Date().toLocaleDateString("ja-JP");
        const index=surveys.findIndex(s=>s.id===currentEditorSurvey.id);
        if(index>=0)surveys[index]=clone(currentEditorSurvey);
    }

    currentSurveyId=currentEditorSurvey.id;

    toast("保存しました");

    setTimeout(()=>showPage("list"),500);
}

function cancelEditor(){

    confirmAction(
        "変更を破棄",
        "現在の変更内容を破棄して一覧へ戻りますか？",
        ()=>{
            showPage("list");
        }
    );
}


/* =========================================================
   採番
========================================================= */

function setNumbering(mode){

    currentEditorSurvey.numbering=mode;

    renumberQuestions();
    renderGroups();

    toast(
        mode==="global"
            ?"アンケート全体で通番に変更しました"
            :"グループ毎に採番に変更しました"
    );
}

function renumberQuestions(){

    let globalNo=1;

    currentEditorSurvey.groups.forEach((group,gIndex)=>{

        let groupNo=1;

        group.questions.forEach(q=>{

            if(currentEditorSurvey.numbering==="global"){
                q.displayNo="Q"+globalNo;
                globalNo++;
            }else{
                q.displayNo=`Q${gIndex+1}-${groupNo}`;
                groupNo++;
            }

        });
    });
}


/* =========================================================
   グループ
========================================================= */

function addGroup(){

    currentEditorSurvey.groups.push({
        id:nextId++,
        title:`グループ${currentEditorSurvey.groups.length+1}`,
        questions:[]
    });

    renumberQuestions();
    renderGroups();
    toast("グループを追加しました");
}

function deleteGroup(index){

    const group=currentEditorSurvey.groups[index];

    confirmAction(
        "グループを削除",
        group.questions.length
            ? `「${group.title}」には${group.questions.length}件の質問があります。削除しますか？`
            : `「${group.title}」を削除しますか？`,
        ()=>{
            currentEditorSurvey.groups.splice(index,1);

            if(currentEditorSurvey.groups.length===0){
                currentEditorSurvey.groups.push({
                    id:nextId++,
                    title:"グループ1",
                    questions:[]
                });
            }

            renumberQuestions();
            renderGroups();
            toast("グループを削除しました");
        }
    );
}

function renderGroups(){

    renumberQuestions();

    const container=document.getElementById("groupsContainer");

    container.innerHTML=currentEditorSurvey.groups.map((g,gIndex)=>`

        <div class="group-card"
             draggable="true"
             data-group-index="${gIndex}"
             ondragstart="groupDragStart(event,${gIndex})"
             ondragover="groupDragOver(event)"
             ondrop="groupDrop(event,${gIndex})">

            <div class="group-header">

                <span class="drag-handle" title="ドラッグして並び替え">
                    ☷
                </span>

                <input class="group-title-input"
                       value="${escapeHtml(g.title)}"
                       onchange="updateGroupTitle(${gIndex},this.value)">

                <span class="badge badge-draft">
                    ${g.questions.length}問
                </span>

                <button class="btn btn-sm btn-danger"
                        onclick="deleteGroup(${gIndex})">
                    削除
                </button>

            </div>

            <div class="group-body">

                ${g.questions.map((q,qIndex)=>
                    renderQuestion(gIndex,qIndex,q)
                ).join("")}

                <!-- ★質問追加ボタンは各グループ末尾のみ -->
                <button class="add-question"
                        onclick="addQuestion(${gIndex})">
                    ＋ 質問を追加
                </button>

            </div>

        </div>
    `).join("");
}

function updateGroupTitle(index,value){
    currentEditorSurvey.groups[index].title=value;
}

let draggingGroupIndex=null;

function groupDragStart(e,index){
    draggingGroupIndex=index;
    e.currentTarget.classList.add("dragging");
}

function groupDragOver(e){
    e.preventDefault();
}

function groupDrop(e,targetIndex){

    e.preventDefault();

    if(draggingGroupIndex===null)return;
    if(draggingGroupIndex===targetIndex)return;

    const groups=currentEditorSurvey.groups;
    const item=groups.splice(draggingGroupIndex,1)[0];

    groups.splice(targetIndex,0,item);

    draggingGroupIndex=null;

    renumberQuestions();
    renderGroups();

    toast("グループの順番を変更しました");
}


/* =========================================================
   質問
========================================================= */

function renderQuestion(gIndex,qIndex,q){

    return `
    <div class="question"
         draggable="true"
         data-group="${gIndex}"
         data-question="${qIndex}"
         ondragstart="questionDragStart(event,${gIndex},${qIndex})"
         ondragover="questionDragOver(event)"
         ondrop="questionDrop(event,${gIndex},${qIndex})">

        <div class="question-header">

            <span class="drag-handle">
                ☷
            </span>

            <span class="question-number">
                ${escapeHtml(q.displayNo)}
            </span>

            <span class="small muted">
                ドラッグして並び替え / 移動
            </span>

            <span style="margin-left:auto"></span>

            <button class="btn btn-sm btn-danger"
                    onclick="deleteQuestion(${gIndex},${qIndex})">
                削除
            </button>

        </div>

        <div class="question-body">

            <div class="question-grid">

                <div class="form-group">
                    <label>質問文</label>
                    <textarea
                        onchange="updateQuestion(${gIndex},${qIndex},'text',this.value)"
                    >${escapeHtml(q.text)}</textarea>
                </div>

                <div class="form-group">
                    <label>回答形式</label>
                    <select
                        onchange="changeQuestionType(${gIndex},${qIndex},this.value)"
                    >
                        <option value="single" ${q.type==="single"?"selected":""}>
                            単一選択
                        </option>
                        <option value="multiple" ${q.type==="multiple"?"selected":""}>
                            複数選択
                        </option>
                        <option value="text" ${q.type==="text"?"selected":""}>
                            自由記述（1行）
                        </option>
                        <option value="textarea" ${q.type==="textarea"?"selected":""}>
                            自由記述（複数行）
                        </option>
                    </select>
                </div>

            </div>

            <label class="required">
                <input type="checkbox"
                       ${q.required?"checked":""}
                       onchange="updateQuestion(${gIndex},${qIndex},'required',this.checked)">
                必須回答
            </label>

            ${(q.type==="single"||q.type==="multiple") ? `
                <div class="question-options">

                    <div class="section-heading">
                        <strong>選択肢</strong>
                        <button class="btn btn-sm"
                                onclick="addOption(${gIndex},${qIndex})">
                            ＋ 選択肢追加
                        </button>
                    </div>

                    ${q.options.map((opt,oIndex)=>`
                        <div class="option-row">
                            <input value="${escapeHtml(opt)}"
                                   onchange="updateOption(${gIndex},${qIndex},${oIndex},this.value)">
                            <button class="btn btn-sm btn-danger"
                                    onclick="deleteOption(${gIndex},${qIndex},${oIndex})">
                                削除
                            </button>
                        </div>
                    `).join("")}

                    ${q.type==="single"?`
                        <div style="margin-top:15px">
                            <label>条件分岐設定</label>

                            ${q.options.map((opt,oIndex)=>`
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:7px">
                                    <span class="small" style="padding:9px">
                                        「${escapeHtml(opt)}」を選択した場合
                                    </span>
                                    <select
                                        onchange="setBranch(${gIndex},${qIndex},${oIndex},this.value)">
                                        ${branchOptions(gIndex,qIndex,q.branch?.[oIndex])}
                                    </select>
                                </div>
                            `).join("")}
                        </div>
                    `:""}

                </div>
            `:""}

        </div>
    </div>
    `;
}

function branchOptions(gIndex,qIndex,current){

    const options=[`<option value="">次の質問へ</option>`];

    currentEditorSurvey.groups.forEach((g,gi)=>{
        g.questions.forEach((q,qi)=>{
            if(gi===gIndex && qi===qIndex)return;
            options.push(
                `<option value="${q.id}" ${String(current)===String(q.id)?"selected":""}>
                    ${escapeHtml(q.displayNo)} - ${escapeHtml(q.text.substring(0,35))}
                </option>`
            );
        });
    });

    return options.join("");
}

function addQuestion(gIndex){

    const q={
        id:nextId++,
        text:"新しい質問を入力してください。",
        type:"single",
        required:false,
        options:["選択肢1","選択肢2"],
        branch:{}
    };

    currentEditorSurvey.groups[gIndex].questions.push(q);

    renumberQuestions();
    renderGroups();

    toast("質問を追加しました");
}

function deleteQuestion(gIndex,qIndex){

    const q=currentEditorSurvey.groups[gIndex].questions[qIndex];

    confirmAction(
        "質問を削除",
        `「${q.displayNo}」を削除しますか？`,
        ()=>{
            currentEditorSurvey.groups[gIndex].questions.splice(qIndex,1);
            renumberQuestions();
            renderGroups();
            toast("質問を削除しました");
        }
    );
}

function updateQuestion(g,q,key,value){
    currentEditorSurvey.groups[g].questions[q][key]=value;
}

function changeQuestionType(g,q,type){

    const question=currentEditorSurvey.groups[g].questions[q];
    question.type=type;

    if(type==="single"||type==="multiple"){
        if(!question.options.length){
            question.options=["選択肢1","選択肢2"];
        }
    }else{
        question.options=[];
    }

    renderGroups();
}

function addOption(g,q){

    currentEditorSurvey.groups[g].questions[q].options.push(
        "選択肢"+(currentEditorSurvey.groups[g].questions[q].options.length+1)
    );

    renderGroups();
}

function deleteOption(g,q,o){

    currentEditorSurvey.groups[g].questions[q].options.splice(o,1);
    renderGroups();
}

function updateOption(g,q,o,value){
    currentEditorSurvey.groups[g].questions[q].options[o]=value;
}

function setBranch(g,q,o,value){

    const question=currentEditorSurvey.groups[g].questions[q];

    if(!question.branch)question.branch={};

    if(value){
        question.branch[o]=Number(value);
    }else{
        delete question.branch[o];
    }

    toast("条件分岐を更新しました");
}


/* =========================================================
   質問ドラッグ＆ドロップ
========================================================= */

let draggingQuestion=null;

function questionDragStart(e,g,q){

    draggingQuestion={g,q};

    e.currentTarget.classList.add("dragging");

    e.dataTransfer.effectAllowed="move";
}

function questionDragOver(e){
    e.preventDefault();
    e.dataTransfer.dropEffect="move";
}

function questionDrop(e,targetG,targetQ){

    e.preventDefault();

    if(!draggingQuestion)return;

    const sourceG=draggingQuestion.g;
    const sourceQ=draggingQuestion.q;

    if(sourceG===targetG && sourceQ===targetQ){
        draggingQuestion=null;
        return;
    }

    const sourceQuestions=currentEditorSurvey.groups[sourceG].questions;

    const item=sourceQuestions.splice(sourceQ,1)[0];

    let insertIndex=targetQ;

    if(sourceG===targetG && sourceQ<targetQ){
        insertIndex--;
    }

    currentEditorSurvey.groups[targetG].questions
        .splice(insertIndex,0,item);

    draggingQuestion=null;

    renumberQuestions();
    renderGroups();

    if(sourceG===targetG){
        toast("質問の順番を変更しました");
    }else{
        toast("質問を別グループへ移動しました");
    }
}


/* =========================================================
   プレビュー
========================================================= */

function openPreview(){

    updateEditorData();
    renumberQuestions();

    document.getElementById("previewTitle").textContent=
        currentEditorSurvey.title||"アンケートタイトル";

    document.getElementById("previewDescription").textContent=
        currentEditorSurvey.description||"";

    let html="";

    currentEditorSurvey.groups.forEach(g=>{
        html+=`<h3>${escapeHtml(g.title)}</h3>`;

        g.questions.forEach(q=>{
            html+=`
                <div class="preview-question">
                    <div>
                        <strong>${escapeHtml(q.displayNo)} ${q.required?"*":""}</strong>
                    </div>

                    <p>${escapeHtml(q.text)}</p>

                    ${previewAnswerControl(q)}
                </div>
            `;
        });
    });

    html+=`
        <div class="btn-group">
            <button class="btn">戻る</button>
            <button class="btn btn-primary" onclick="previewSend()">
                回答を送信する
            </button>
        </div>
    `;

    document.getElementById("previewContent").innerHTML=html;

    showPage("preview");
}

function previewAnswerControl(q){

    if(q.type==="single"){
        return q.options.map(o=>`
            <label class="preview-option">
                <input type="radio" name="preview-${q.id}">
                ${escapeHtml(o)}
            </label>
        `).join("");
    }

    if(q.type==="multiple"){
        return q.options.map(o=>`
            <label class="preview-option">
                <input type="checkbox">
                ${escapeHtml(o)}
            </label>
        `).join("");
    }

    if(q.type==="text"){
        return `<input placeholder="回答を入力してください">`;
    }

    return `<textarea placeholder="回答を入力してください"></textarea>`;
}

function setPreviewDevice(type){

    const device=document.getElementById("previewDevice");

    if(type==="mobile"){
        device.classList.add("mobile");
    }else{
        device.classList.remove("mobile");
    }
}

function previewSend(){

    openModal(
        "プレビュー送信",
        "<p>これはプレビュー表示のため送信されません。</p>",
        [
            {label:"閉じる",className:"btn-primary",action:closeModal}
        ]
    );
}


/* =========================================================
   送信
========================================================= */

function openSend(id){

    currentSendSurveyId=id;

    const survey=surveys.find(s=>s.id===id);
    if(!survey)return;

    document.getElementById("sendSurveyName").textContent=
        `対象アンケート：${survey.title}`;

    renderCustomers();
    renderHistoryMini();

    showPage("send");
}

function renderCustomers(){

    const keyword=(document.getElementById("customerSearch")?.value||"")
        .toLowerCase();

    const filter=document.getElementById("customerStatusFilter")?.value||"all";

    const data=customers.filter(c=>{

        const text=(c.org+c.name+c.email).toLowerCase();

        const keywordMatch=!keyword||text.includes(keyword);

        const statusMatch=filter==="all"||c.status===filter;

        return keywordMatch&&statusMatch;
    });

    document.getElementById("customerTable").innerHTML=data.map(c=>`
        <tr>
            <td>
                <input type="checkbox"
                       class="customer-check"
                       value="${c.id}">
            </td>
            <td>${escapeHtml(c.org)}</td>
            <td>${escapeHtml(c.name)}</td>
            <td>${escapeHtml(c.email)}</td>
            <td>${escapeHtml(c.tel)}</td>
            <td>
                ${c.status==="回答済み"
                    ?'<span class="badge badge-open">回答済み</span>'
                    :c.status==="未送信"
                    ?'<span class="badge badge-draft">未送信</span>'
                    :'<span class="badge badge-stop">送信済み / 未回答</span>'
                }
            </td>
            <td>${escapeHtml(c.sent)}</td>
            <td>${c.count}</td>
            <td>
                <button class="btn btn-sm"
                        onclick="showMailPreview(${c.id})">
                    確認
                </button>
            </td>
        </tr>
    `).join("");
}

function selectAllCustomers(checked){

    document.querySelectorAll(".customer-check")
        .forEach(c=>c.checked=checked);
}

function filterUnanswered(){

    document.getElementById("customerStatusFilter").value=
        "送信済み / 未回答";

    renderCustomers();
}

function sendSelectedCustomers(){

    const selected=[...document.querySelectorAll(".customer-check:checked")]
        .map(x=>Number(x.value));

    if(!selected.length){
        toast("送信対象を選択してください");
        return;
    }

    const alreadySent=selected.filter(id=>{
        const c=customers.find(x=>x.id===id);
        return c && c.count>0;
    });

    const message=alreadySent.length
        ?`選択した${selected.length}件へ送信します。<br><br>
          既に送信済みの宛先が${alreadySent.length}件含まれています。再送しますか？`
        :`選択した${selected.length}件へメールを送信しますか？`;

    confirmAction(
        "メール一括送信",
        message,
        ()=>{
            const now=new Date().toLocaleString("ja-JP");

            selected.forEach(id=>{
                const c=customers.find(x=>x.id===id);

                if(c){
                    c.status="送信済み / 未回答";
                    c.sent=now;
                    c.count++;
                }
            });

            histories.unshift({
                date:now,
                type:alreadySent.length?"再送":"一括送信",
                count:selected.length,
                subject:document.getElementById("mailSubject").value,
                user:"管理者",
                targets:selected.map(id=>{
                    const c=customers.find(x=>x.id===id);
                    return c?.name;
                }).join("、"),
                surveyId:currentSendSurveyId
            });

            const survey=surveys.find(s=>s.id===currentSendSurveyId);

            if(survey){
                survey.answers=survey.answers;
                survey.updated=new Date().toLocaleDateString("ja-JP");
            }

            renderCustomers();
            renderHistoryMini();

            toast(`${selected.length}件のメールを送信しました`);
        }
    );
}

function showMailPreview(id){

    const c=customers.find(x=>x.id===id);
    if(!c)return;

    const url=
        "https://survey.example.jp/a/"
        +currentSendSurveyId
        +"/customer/"
        +c.id;

    const body=document.getElementById("mailBody").value
        .replaceAll("{顧客名}",c.name)
        .replaceAll("{アンケートURL}",url);

    openModal(
        "送信文を確認",
        `
        <div class="form-group">
            <label>宛先</label>
            <input value="${escapeHtml(c.email)}" readonly>
        </div>

        <div class="form-group" style="margin-top:15px">
            <label>件名</label>
            <input value="${escapeHtml(document.getElementById("mailSubject").value)}" readonly>
        </div>

        <div class="form-group" style="margin-top:15px">
            <label>本文</label>
            <div class="mail-preview">${escapeHtml(body)}</div>
        </div>
        `,
        [
            {label:"閉じる",className:"btn-primary",action:closeModal}
        ]
    );
}

function renderHistoryMini(){

    const data=histories
        .filter(h=>h.surveyId===currentSendSurveyId)
        .slice(0,5);

    document.getElementById("sendHistoryMini").innerHTML=
        data.length
        ?data.map(h=>`
            <div style="padding:10px 0;border-bottom:1px solid #e2e8f0">
                <strong>${escapeHtml(h.date)}</strong>
                ／ ${escapeHtml(h.type)}
                ／ ${h.count}件
                <div class="muted small">
                    ${escapeHtml(h.targets)}
                </div>
            </div>
        `).join("")
        :'<div class="empty">送信履歴はありません。</div>';
}


/* =========================================================
   履歴
========================================================= */

function renderHistory(){

    document.getElementById("historyTable").innerHTML=
        histories.map((h,i)=>`
            <tr>
                <td>${escapeHtml(h.date)}</td>
                <td>${escapeHtml(h.type)}</td>
                <td>${h.count}件</td>
                <td>${escapeHtml(h.subject)}</td>
                <td>${escapeHtml(h.user)}</td>
                <td>${escapeHtml(h.targets)}</td>
                <td>
                    <button class="btn btn-sm"
                            onclick="showHistoryDetail(${i})">
                        送信文を確認
                    </button>
                </td>
            </tr>
        `).join("");
}

function showHistoryDetail(index){

    const h=histories[index];

    openModal(
        "送信履歴詳細",
        `
        <p><strong>送信日時：</strong>${escapeHtml(h.date)}</p>
        <p><strong>送信種別：</strong>${escapeHtml(h.type)}</p>
        <p><strong>送信件数：</strong>${h.count}件</p>
        <p><strong>送信実行者：</strong>${escapeHtml(h.user)}</p>
        <p><strong>対象顧客：</strong>${escapeHtml(h.targets)}</p>
        <hr>
        <p><strong>件名</strong></p>
        <div class="mail-preview">${escapeHtml(h.subject)}</div>
        `,
        [
            {label:"閉じる",className:"btn-primary",action:closeModal}
        ]
    );
}


/* =========================================================
   集計
========================================================= */

function openAnalysis(id){

    currentAnalysisSurveyId=id;

    const survey=surveys.find(s=>s.id===id);
    if(!survey)return;

    document.getElementById("analysisSurveyName").textContent=
        `対象アンケート：${survey.title}`;

    renderAnalysis();

    showPage("analysis");
}

function getAllQuestions(survey){

    const result=[];

    survey.groups.forEach(g=>{
        g.questions.forEach(q=>{
            result.push({...q,groupTitle:g.title});
        });
    });

    return result;
}

function renderAnalysis(){

    const survey=surveys.find(s=>s.id===currentAnalysisSurveyId);
    if(!survey)return;

    const sent=customers.filter(c=>c.count>0).length;
    const answered=survey.answers;
    const unanswered=Math.max(sent-answered,0);
    const rate=sent?Math.round(answered/sent*100):0;

    document.getElementById("analysisKpi").innerHTML=`
        <div class="kpi">
            <div class="label">送信対象者数</div>
            <div class="value">${sent}</div>
        </div>
        <div class="kpi">
            <div class="label">回答数</div>
            <div class="value">${answered}</div>
        </div>
        <div class="kpi">
            <div class="label">未登録顧客からの回答数</div>
            <div class="value">4</div>
        </div>
        <div class="kpi">
            <div class="label">未回答数</div>
            <div class="value">${unanswered}</div>
        </div>
        <div class="kpi">
            <div class="label">回答率</div>
            <div class="value">${rate}%</div>
        </div>
    `;

    const questions=getAllQuestions(survey);

    document.getElementById("questionFilter").innerHTML=
        questions.map(q=>`
            <label class="check-item">
                <input type="checkbox"
                       class="analysis-q"
                       value="${q.id}"
                       checked
                       onchange="renderAnalysisQuestions()">
                ${escapeHtml(q.displayNo||questionDisplayNo(survey,q.id))}
                ${escapeHtml(q.text)}
            </label>
        `).join("");

    renderAnalysisQuestions();
    renderAnswers();
}

function questionDisplayNo(survey,id){

    let no=1;

    for(let gi=0;gi<survey.groups.length;gi++){

        let qi=1;

        for(let q of survey.groups[gi].questions){

            if(q.id===id){
                return survey.numbering==="global"
                    ?"Q"+no
                    :`Q${gi+1}-${qi}`;
            }

            no++;
            qi++;
        }
    }

    return "";
}

function selectAllQuestions(checked){

    document.querySelectorAll(".analysis-q")
        .forEach(c=>c.checked=checked);

    renderAnalysisQuestions();
}

function renderAnalysisQuestions(){

    const survey=surveys.find(s=>s.id===currentAnalysisSurveyId);
    if(!survey)return;

    const selected=[...document.querySelectorAll(".analysis-q:checked")]
        .map(x=>Number(x.value));

    const questions=getAllQuestions(survey)
        .filter(q=>selected.includes(q.id));

    document.getElementById("analysisQuestions").innerHTML=
        questions.length
        ?questions.map((q,index)=>{

            if(q.type==="single"||q.type==="multiple"){

                const total=100;
                const values=q.options.map((o,i)=>{
                    const count=Math.round(
                        total*(q.options.length-i)/(q.options.length*(q.options.length+1))
                    );
                    return {o,count};
                });

                return `
                <div style="margin-bottom:30px">
                    <h3>
                        ${escapeHtml(questionDisplayNo(survey,q.id))}
                        ${escapeHtml(q.text)}
                    </h3>

                    ${values.map(v=>`
                        <div class="bar-row">
                            <div class="bar-label">
                                <span>${escapeHtml(v.o)}</span>
                                <span>${v.count}件</span>
                            </div>
                            <div class="bar-bg">
                                <div class="bar"
                                     style="width:${v.count}%"></div>
                            </div>
                        </div>
                    `).join("")}

                    <button class="btn btn-sm"
                            onclick="showOtherAnswer('${escapeHtml(q.text)}')">
                        「その他」回答を確認
                    </button>
                </div>
                `;
            }

            return `
                <div style="margin-bottom:25px">
                    <h3>
                        ${escapeHtml(questionDisplayNo(survey,q.id))}
                        ${escapeHtml(q.text)}
                    </h3>
                    <div class="mail-preview">
                        サービスについて大変満足しています。<br>
                        今後も継続して利用したいと思います。
                    </div>
                </div>
            `;
        }).join("")
        :'<div class="empty">表示する設問を選択してください。</div>';
}

function showOtherAnswer(question){

    openModal(
        "「その他」回答",
        `
        <p><strong>設問：</strong>${escapeHtml(question)}</p>

        <div class="card" style="margin:15px 0">
            <strong>回答者：</strong>山田 太郎<br>
            <span class="muted">株式会社サンプル</span>
            <p>その他のサービスも利用したい。</p>
        </div>

        <div class="card" style="margin:0">
            <strong>回答者：</strong>佐藤 花子<br>
            <span class="muted">株式会社テスト</span>
            <p>サポート体制について詳しく知りたい。</p>
        </div>
        `,
        [
            {label:"閉じる",className:"btn-primary",action:closeModal}
        ]
    );
}

function renderAnswers(){

    const survey=surveys.find(s=>s.id===currentAnalysisSurveyId);

    if(!survey){
        return;
    }

    const answeredCustomers=customers
        .filter(c=>c.status==="回答済み");

    document.getElementById("answersTable").innerHTML=
        answeredCustomers.map(c=>`
            <tr>
                <td>${escapeHtml(c.org)}</td>
                <td>${escapeHtml(c.name)}</td>
                <td>${escapeHtml(c.sent)}</td>
                <td>満足度：満足 ／ 自由記述あり</td>
                <td>
                    <button class="btn btn-sm"
                            onclick="showFullAnswer(${c.id})">
                        全回答を表示
                    </button>
                </td>
            </tr>
        `).join("");
}

function showFullAnswer(customerId){

    const customer=customers.find(c=>c.id===customerId);
    const survey=surveys.find(s=>s.id===currentAnalysisSurveyId);

    const questions=getAllQuestions(survey);

    openModal(
        "全回答",
        `
        <p>
            <strong>${escapeHtml(customer.name)}</strong>
            （${escapeHtml(customer.org)}）
        </p>

        ${questions.map((q,i)=>`
            <div style="padding:12px 0;border-bottom:1px solid #e2e8f0">
                <strong>
                    ${escapeHtml(questionDisplayNo(survey,q.id))}
                    ${escapeHtml(q.text)}
                </strong>

                <p>
                    ${
                        q.type==="single"
                        ?escapeHtml(q.options[0]||"回答なし")
                        :"サンプル回答です。"
                    }
                </p>
            </div>
        `).join("")}
        `,
        [
            {label:"閉じる",className:"btn-primary",action:closeModal}
        ]
    );
}

function exportCSV(){

    toast("CSV出力を実行しました（モック）");

    setTimeout(()=>{
        openModal(
            "CSV出力",
            "<p>CSV出力操作を実行しました。</p><p class='muted'>モックのため実ファイルは生成していません。</p>",
            [
                {label:"閉じる",className:"btn-primary",action:closeModal}
            ]
        );
    },300);
}

function exportPDF(){

    toast("PDF出力を実行しました（モック）");

    setTimeout(()=>{
        openModal(
            "PDF出力",
            "<p>現在表示している集計内容をPDFとして出力する操作を実行しました。</p><p class='muted'>モックのため実ファイルは生成していません。</p>",
            [
                {label:"閉じる",className:"btn-primary",action:closeModal}
            ]
        );
    },300);
}


/* =========================================================
   kintone
========================================================= */

const kintoneFields=[
    "会社名",
    "組織名",
    "氏名",
    "担当者名",
    "メールアドレス",
    "部署名",
    "電話番号",
    "都道府県",
    "市区町村",
    "番地",
    "建物名",
    "郵便番号"
];

function saveKintone(){

    toast("kintone設定を保存しました");

    document.getElementById("kintoneStatus").innerHTML=`
        <div class="alert success">
            設定を保存しました。
        </div>
    `;
}

function testKintone(){

    document.getElementById("kintoneStatus").innerHTML=`
        <div class="alert success">
            ✓ kintone接続確認済み
        </div>
    `;

    toast("kintone接続確認に成功しました");
}

function getKintoneFields(){

    renderKintoneFields();

    toast("項目一覧を再取得しました");
}

function renderKintoneFields(){

    document.getElementById("kintoneFields").innerHTML=`
        <div class="alert success">
            項目一覧取得済み（${kintoneFields.length}項目）
        </div>

        <div class="checkbox-list">
            ${kintoneFields.map(f=>`
                <div class="check-item">
                    <span>✓</span>
                    ${escapeHtml(f)}
                </div>
            `).join("")}
        </div>
    `;
}

function syncCustomers(){

    toast("顧客情報を同期しました");

    setTimeout(()=>{
        openModal(
            "顧客情報同期",
            `
            <div class="alert success">
                ✓ 顧客情報の同期が完了しました。
            </div>

            <p>同期件数：128件</p>
            <p>更新件数：12件</p>
            <p>新規件数：3件</p>
            `,
            [
                {label:"閉じる",className:"btn-primary",action:closeModal}
            ]
        );
    },300);
}


/* =========================================================
   メールサーバ
========================================================= */

function saveMailServer(){

    document.getElementById("mailServerStatus").innerHTML=`
        <div class="alert success">
            メールサーバ設定を保存しました。
        </div>
    `;

    toast("メールサーバ設定を保存しました");
}

function testMailServer(){

    document.getElementById("mailServerStatus").innerHTML=`
        <div class="alert success">
            ✓ 接続確認済み
        </div>
    `;

    toast("メールサーバ接続確認に成功しました");
}

function testMail(){

    confirmAction(
        "テストメール送信",
        "設定された送信先へテストメールを送信しますか？",
        ()=>{
            document.getElementById("mailServerStatus").innerHTML=`
                <div class="alert success">
                    ✓ テストメール送信成功
                </div>
            `;

            toast("テストメールを送信しました");
        }
    );
}


/* =========================================================
   回答者画面
========================================================= */

function openAnswerPage(){

    const survey=
        surveys.find(s=>s.status==="公開中")||surveys[0];

    if(!survey)return;

    document.getElementById("answerTitle").textContent=survey.title;
    document.getElementById("answerDescription").textContent=survey.description;

    let html="";

    getAllQuestions(survey).forEach(q=>{

        const no=questionDisplayNo(survey,q.id);

        html+=`
            <div class="preview-question">
                <strong>${escapeHtml(no)} ${q.required?"*":""}</strong>
                <p>${escapeHtml(q.text)}</p>
        `;

        if(q.type==="single"){

            q.options.forEach((o,i)=>{
                html+=`
                    <label class="preview-option">
                        <input type="radio"
                               name="answer-${q.id}"
                               value="${escapeHtml(o)}">
                        ${escapeHtml(o)}
                    </label>
                `;
            });

        }else if(q.type==="multiple"){

            q.options.forEach(o=>{
                html+=`
                    <label class="preview-option">
                        <input type="checkbox"
                               value="${escapeHtml(o)}">
                        ${escapeHtml(o)}
                    </label>
                `;
            });

        }else if(q.type==="text"){

            html+=`
                <input placeholder="回答を入力してください"
                       id="answer-${q.id}">
            `;

        }else{

            html+=`
                <textarea placeholder="回答を入力してください"
                          id="answer-${q.id}"></textarea>
            `;
        }

        html+=`</div>`;
    });

    document.getElementById("answerQuestions").innerHTML=html;

    currentAnswerSurveyId=survey.id;

    showPage("answer");
}

let currentAnswerSurveyId=null;

function answerConfirm(){

    const survey=surveys.find(s=>s.id===currentAnswerSurveyId);

    if(!survey)return;

    const requiredQuestions=getAllQuestions(survey)
        .filter(q=>q.required);

    /*
     * モックでは必須チェックを簡易実装。
     * 回答UIの操作確認を優先します。
     */

    let missing=false;

    requiredQuestions.forEach(q=>{
        if(q.type==="single"){
            const checked=document.querySelector(
                `input[name="answer-${q.id}"]:checked`
            );
            if(!checked)missing=true;
        }
    });

    if(missing){

        openModal(
            "必須項目未入力",
            "<p>必須項目に未回答の質問があります。</p>",
            [
                {label:"閉じる",className:"btn-primary",action:closeModal}
            ]
        );

        return;
    }

    openModal(
        "回答確認",
        `
        <div class="alert">
            以下の内容で回答を送信します。
        </div>

        ${getAllQuestions(survey).map(q=>`
            <div style="padding:12px 0;border-bottom:1px solid #e2e8f0">
                <strong>${escapeHtml(q.displayNo||questionDisplayNo(survey,q.id))}
                ${escapeHtml(q.text)}</strong>
                <div class="muted small">回答内容を確認してください。</div>
            </div>
        `).join("")}
        `,
        [
            {label:"修正する",action:closeModal},
            {label:"回答を送信する",className:"btn-primary",action:()=>{
                closeModal();

                confirmAction(
                    "回答送信",
                    "回答を送信しますか？",
                    ()=>{
                        const survey=surveys.find(s=>s.id===currentAnswerSurveyId);

                        if(survey){
                            survey.answers++;
                        }

                        showPage("complete");
                    }
                );
            }}
        ]
    );
}


/* =========================================================
   ログアウト
========================================================= */

function logoutMock(){

    confirmAction(
        "ログアウト",
        "ログアウトしますか？",
        ()=>{
            toast("ログアウトしました（モック）");
        }
    );
}


/* =========================================================
   初期表示
========================================================= */

document.addEventListener("DOMContentLoaded",()=>{

    renderSurveyList();

    document.getElementById("side-list").classList.add("active");
    document.getElementById("nav-list").classList.add("active");

});
</script>

</body>
</html>
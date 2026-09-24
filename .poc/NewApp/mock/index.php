<?php
/*
 * アンケート業務運営アプリ モック
 * 1ファイル完結
 * Apache + PHP 5.8 対応
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
html,body{margin:0;padding:0;background:#f4f6f8;color:#263238;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;font-size:14px}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.app{min-height:100vh}

/* 横型メニューバー */
.topbar{
    height:62px;
    background:#17324d;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:28px;
    box-shadow:0 2px 8px rgba(0,0,0,.12);
}
.brand{font-size:18px;font-weight:700;white-space:nowrap}
.main-nav{display:flex;align-items:stretch;height:100%;gap:4px}
.main-nav button{
    border:0;
    background:transparent;
    color:#dce7ef;
    padding:0 18px;
    font-weight:600;
    border-bottom:3px solid transparent;
}
.main-nav button:hover{background:#234763;color:#fff}
.main-nav button.active{color:#fff;border-bottom-color:#4db6ac;background:#234763}
.top-right{margin-left:auto;display:flex;align-items:center;gap:12px;color:#dce7ef}
.operator{font-size:13px}

.content{max-width:1440px;margin:0 auto;padding:26px 30px 60px}
.page{display:none}
.page.active{display:block}

.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:22px}
.page-title{margin:0;font-size:26px;color:#17324d}
.page-subtitle{margin:7px 0 0;color:#687780}
.header-actions{display:flex;gap:8px;flex-wrap:wrap}

.btn{
    border:1px solid #c8d1d8;
    background:#fff;
    color:#344955;
    border-radius:6px;
    padding:9px 15px;
    font-weight:600;
}
.btn:hover{background:#f2f5f7}
.btn-primary{background:#1976a8;border-color:#1976a8;color:#fff}
.btn-primary:hover{background:#125f88}
.btn-success{background:#258a68;border-color:#258a68;color:#fff}
.btn-danger{background:#c94c4c;border-color:#c94c4c;color:#fff}
.btn-warning{background:#c8861a;border-color:#c8861a;color:#fff}
.btn-small{padding:6px 10px;font-size:12px}

.card{
    background:#fff;
    border:1px solid #dce2e6;
    border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
}
.card + .card{margin-top:16px}
.card-head{
    padding:16px 18px;
    border-bottom:1px solid #e4e8eb;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}
.card-head h2,.card-head h3{margin:0;color:#263f52}
.card-body{padding:18px}

.notice{
    border-radius:6px;
    padding:12px 15px;
    margin-bottom:16px;
}
.notice.info{background:#eaf4fa;color:#245b77;border:1px solid #c9e2ef}
.notice.success{background:#edf8f3;color:#21634c;border:1px solid #c7e8d9}
.notice.warning{background:#fff8e8;color:#765516;border:1px solid #f0dcaa}
.notice.error{background:#fff0f0;color:#9a3636;border:1px solid #edc8c8}

.toolbar{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:16px;
}
.search{min-width:280px;flex:1}
input[type=text],input[type=datetime-local],input[type=email],textarea,select{
    width:100%;
    border:1px solid #cbd5db;
    border-radius:5px;
    padding:9px 10px;
    background:#fff;
    color:#263238;
}
textarea{resize:vertical;min-height:88px}
label.field-label{display:block;font-weight:700;margin-bottom:6px;color:#405563}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid .full{grid-column:1/-1}
.field{margin-bottom:14px}

.badge{
    display:inline-flex;
    align-items:center;
    border-radius:20px;
    padding:4px 9px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}
.badge-draft{background:#eef1f3;color:#5d6a72}
.badge-wait{background:#fff4d8;color:#8a6617}
.badge-open{background:#e6f6ee;color:#207050}
.badge-closed{background:#fbe9e9;color:#9a4444}
.badge-archive{background:#e9e9f4;color:#565a79}

.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:1050px}
th,td{padding:12px 11px;border-bottom:1px solid #e7ebee;text-align:left;vertical-align:middle}
th{background:#f8fafb;color:#52636d;font-size:12px;white-space:nowrap}
tbody tr:hover{background:#fafcfd}
.action-group{display:flex;gap:5px;flex-wrap:wrap}

.stats{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
    margin-bottom:18px;
}
.stat{
    background:#fff;
    border:1px solid #dce2e6;
    border-radius:8px;
    padding:17px;
}
.stat-label{font-size:12px;color:#687780;margin-bottom:7px}
.stat-value{font-size:25px;font-weight:700;color:#17324d}
.stat-note{font-size:11px;color:#7b898f;margin-top:5px}

.tabs{
    display:flex;
    gap:2px;
    border-bottom:1px solid #cfd8dc;
    margin-bottom:18px;
}
.tab{
    border:0;
    background:transparent;
    padding:11px 17px;
    color:#60717b;
    border-bottom:3px solid transparent;
    font-weight:700;
}
.tab.active{color:#1976a8;border-bottom-color:#1976a8}

.hidden{display:none!important}
.muted{color:#77858c}
.text-danger{color:#b33e3e}
.text-success{color:#237452}
.text-right{text-align:right}

/* 作成画面 */
.editor-section{margin-bottom:18px}
.editor-section-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:10px;
}
.editor-section-title h2{font-size:17px;margin:0;color:#29495d}

.group-list{display:flex;flex-direction:column;gap:14px}
.group-card{
    border:1px solid #ccd7dd;
    border-radius:8px;
    background:#fff;
    overflow:hidden;
}
.group-card.dragging{opacity:.55;border:2px dashed #1976a8}
.group-head{
    background:#eef4f7;
    padding:13px 14px;
    display:flex;
    align-items:center;
    gap:10px;
}
.drag-handle{
    width:25px;
    text-align:center;
    color:#78909c;
    cursor:grab;
    user-select:none;
    font-size:18px;
}
.group-title-input{flex:1;font-weight:700;background:#fff}
.group-actions{display:flex;gap:5px}
.questions{padding:13px 14px 4px}
.question-card{
    border:1px solid #dce3e7;
    border-radius:7px;
    padding:14px;
    margin-bottom:10px;
    background:#fff;
}
.question-card.dragging{opacity:.5;border:2px dashed #1976a8}
.question-head{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:12px;
}
.question-number{
    min-width:31px;height:31px;border-radius:50%;
    background:#1976a8;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-weight:700;
}
.question-head .drag-handle{font-size:17px}
.question-title{font-weight:700;flex:1}
.question-actions{display:flex;gap:5px}
.question-grid{display:grid;grid-template-columns:2fr 180px 100px;gap:12px;align-items:end}
.question-options{margin-top:12px;padding:12px;background:#f8fafb;border-radius:6px}
.option-row{display:flex;gap:7px;margin-bottom:7px}
.option-row input{flex:1}
.option-row button{width:32px}
.add-question{
    width:100%;
    margin:2px 0 9px;
    border:1px dashed #a9b9c2;
    background:#fafcfd;
    color:#1976a8;
    padding:10px;
    border-radius:6px;
    font-weight:700;
}
.add-question:hover{background:#edf6fa}
.add-group{
    width:100%;
    margin-top:4px;
    border:1px dashed #1976a8;
    background:#f5fbfe;
    color:#1976a8;
    padding:12px;
    border-radius:7px;
    font-weight:700;
}
.branch-box{
    margin-top:10px;
    padding:10px;
    border-left:3px solid #c8861a;
    background:#fffaf0;
    display:none;
}
.branch-box.show{display:block}
.branch-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}

/* 詳細 */
.detail-title{display:flex;align-items:center;gap:10px}
.detail-title h1{margin:0}
.detail-nav{
    display:flex;gap:8px;flex-wrap:wrap;
    margin-bottom:18px;
}
.detail-nav button{border:1px solid #ccd7dd;background:#fff;padding:9px 15px;border-radius:6px;font-weight:700;color:#4b5f69}
.detail-nav button.active{background:#17324d;color:#fff;border-color:#17324d}

/* 回答状況 */
.progress{
    height:9px;background:#e9eef0;border-radius:20px;overflow:hidden;
}
.progress > span{display:block;height:100%;background:#2d9975;border-radius:20px}
.person-status{display:flex;align-items:center;gap:6px}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.dot.green{background:#2d9975}.dot.gray{background:#9aa7ad}.dot.orange{background:#d5962b}

/* 集計 */
.chart-row{display:grid;grid-template-columns:220px 1fr 70px;align-items:center;gap:12px;margin:12px 0}
.bar{height:18px;background:#edf1f3;border-radius:3px;overflow:hidden}
.bar span{display:block;height:100%;background:#3c91b5}
.pie-placeholder{
    width:150px;height:150px;border-radius:50%;
    margin:auto;
    background:conic-gradient(#3c91b5 0 48%,#65b99b 48% 75%,#d69b42 75% 91%,#c9d1d5 91% 100%);
}

/* プレビュー */
.preview-shell{max-width:820px;margin:0 auto;background:#fff;border:1px solid #d8e0e4;border-radius:9px;overflow:hidden}
.preview-top{padding:24px 28px;background:#17324d;color:#fff}
.preview-top h2{margin:0 0 7px}
.preview-body{padding:25px 28px}
.preview-question{border-bottom:1px solid #e7ebee;padding:0 0 20px;margin-bottom:20px}
.preview-question h3{font-size:15px;margin:0 0 12px}
.required{color:#c44545;font-size:11px;font-weight:700;margin-left:5px}
.preview-option{margin:8px 0}
.preview-actions{display:flex;justify-content:space-between;margin-top:22px}

/* モーダル */
.modal-backdrop{
    position:fixed;inset:0;background:rgba(16,32,43,.48);
    display:none;align-items:center;justify-content:center;
    padding:20px;z-index:100;
}
.modal-backdrop.show{display:flex}
.modal{
    width:min(720px,100%);
    max-height:90vh;
    overflow:auto;
    background:#fff;border-radius:9px;
    box-shadow:0 18px 60px rgba(0,0,0,.25);
}
.modal-head{padding:16px 20px;border-bottom:1px solid #e1e7ea;display:flex;justify-content:space-between;align-items:center}
.modal-head h2{margin:0;font-size:18px}
.modal-body{padding:20px}
.modal-foot{padding:13px 20px;border-top:1px solid #e1e7ea;display:flex;justify-content:flex-end;gap:8px}
.close-x{border:0;background:transparent;font-size:22px;color:#60717b}

/* 回答者画面 */
.respondent-wrap{max-width:820px;margin:0 auto}
.respondent-header{background:#17324d;color:#fff;padding:24px;border-radius:9px 9px 0 0}
.respondent-body{background:#fff;border:1px solid #dbe2e6;border-top:0;padding:25px}
.respondent-q{padding:18px 0;border-bottom:1px solid #e3e8eb}
.respondent-q:last-child{border-bottom:0}
.respondent-q label.title{display:block;font-weight:700;margin-bottom:10px}
.respondent-progress{margin:0 0 18px}
.respondent-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}

.toast{
    position:fixed;right:22px;bottom:22px;
    background:#17324d;color:#fff;padding:12px 18px;border-radius:7px;
    box-shadow:0 5px 20px rgba(0,0,0,.2);
    opacity:0;transform:translateY(10px);
    transition:.2s;z-index:200;
}
.toast.show{opacity:1;transform:translateY(0)}

@media(max-width:900px){
    .topbar{padding:0 12px;gap:10px;overflow:auto}
    .main-nav button{padding:0 10px}
    .operator{display:none}
    .content{padding:18px 14px 40px}
    .stats{grid-template-columns:repeat(2,1fr)}
    .form-grid,.question-grid,.branch-grid{grid-template-columns:1fr}
    .page-header{flex-direction:column}
}
</style>
</head>
<body>
<div class="app">

<header class="topbar">
    <div class="brand">アンケート業務運営</div>
    <nav class="main-nav">
        <button id="nav-list" class="active" onclick="showPage('list')">アンケート一覧</button>
        <button id="nav-create" onclick="openCreate()">アンケート作成</button>
        <button id="nav-respondent" onclick="showPage('respondent')">回答者画面</button>
    </nav>
    <div class="top-right">
        <span class="operator">アンケート運営者</span>
    </div>
</header>

<main class="content">

<!-- =====================================================
     アンケート一覧
===================================================== -->
<section id="page-list" class="page active">
    <div class="page-header">
        <div>
            <h1 class="page-title">アンケート一覧</h1>
            <p class="page-subtitle">作成中から保管まで、アンケートの業務状況を管理します。</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="openCreate()">＋ アンケートを作成</button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="toolbar">
                <input id="searchInput" class="search" type="text" placeholder="アンケート名で検索" oninput="filterSurveys()">
                <select id="statusFilter" style="width:190px" onchange="filterSurveys()">
                    <option value="">すべての状態</option>
                    <option value="作成中">作成中</option>
                    <option value="回答開始待ち">回答開始待ち</option>
                    <option value="回答受付中">回答受付中</option>
                    <option value="回答受付終了">回答受付終了</option>
                    <option value="保管">保管</option>
                </select>
                <button class="btn" onclick="clearFilters()">条件を解除</button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>アンケート名</th>
                    <th>状態</th>
                    <th>受付期間</th>
                    <th>対象者</th>
                    <th>回答済み</th>
                    <th>未回答</th>
                    <th>回答率</th>
                    <th>最終更新</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody id="surveyRows"></tbody>
            </table>
        </div>
    </div>
</section>

<!-- =====================================================
     アンケート作成
===================================================== -->
<section id="page-create" class="page">
    <div class="page-header">
        <div>
            <h1 class="page-title" id="createTitle">アンケート作成</h1>
            <p class="page-subtitle">アンケート全体を1画面で編集できます。</p>
        </div>
        <div class="header-actions">
            <button class="btn" onclick="showPage('list')">一覧へ戻る</button>
            <button class="btn" onclick="saveDraft()">下書き保存</button>
            <button class="btn btn-primary" onclick="openPreview()">回答プレビュー</button>
            <button class="btn btn-success" onclick="openPublishCheck()">公開前確認</button>
        </div>
    </div>

    <div id="createNotice" class="notice info">
        質問を追加・編集し、必要に応じてドラッグ＆ドロップで順番を変更してください。
    </div>

    <div class="card editor-section">
        <div class="card-head">
            <h2>基本情報</h2>
        </div>
        <div class="card-body">
            <div class="form-grid">
                <div class="field">
                    <label class="field-label">アンケート名 <span class="text-danger">必須</span></label>
                    <input id="surveyName" type="text" value="新商品に関するお客様アンケート">
                </div>
                <div class="field">
                    <label class="field-label">回答受付開始日時</label>
                    <input id="startDate" type="datetime-local" value="2026-10-01T09:00">
                </div>
                <div class="field">
                    <label class="field-label">回答者向け説明・案内文</label>
                    <textarea id="surveyDescription">日頃のサービスご利用について、率直なご意見をお聞かせください。</textarea>
                </div>
                <div class="field">
                    <label class="field-label">回答受付終了日時</label>
                    <input id="endDate" type="datetime-local" value="2026-10-31T18:00">
                </div>
            </div>
        </div>
    </div>

    <div class="card editor-section">
        <div class="card-head">
            <div>
                <h2>質問・グループ</h2>
                <div class="muted" style="margin-top:4px">質問・グループはドラッグ＆ドロップで並べ替えできます。</div>
            </div>
        </div>
        <div class="card-body">
            <div id="groupList" class="group-list"></div>
            <button class="add-group" onclick="addGroup()">＋ グループを追加</button>
        </div>
    </div>

    <div class="card editor-section">
        <div class="card-head">
            <h2>分岐設定</h2>
            <button class="btn btn-small" onclick="addBranch()">＋ 分岐を追加</button>
        </div>
        <div class="card-body" id="branchList">
            <div class="notice info" id="noBranchNotice">必要な場合のみ分岐を設定してください。分岐条件は単一選択の質問を対象とします。</div>
        </div>
    </div>
</section>

<!-- =====================================================
     個別アンケート管理
===================================================== -->
<section id="page-detail" class="page">
    <div class="page-header">
        <div class="detail-title">
            <button class="btn" onclick="showPage('list')">← 一覧</button>
            <div>
                <h1 class="page-title" id="detailName">お客様満足度アンケート</h1>
                <div id="detailStatus"></div>
            </div>
        </div>
        <div class="header-actions" id="detailActions"></div>
    </div>

    <div class="detail-nav">
        <button id="detail-content" onclick="detailTab('content')">アンケート内容</button>
        <button id="detail-status" onclick="detailTab('status')">回答状況</button>
        <button id="detail-answer" onclick="detailTab('answers')">回答内容</button>
        <button id="detail-summary" onclick="detailTab('summary')">回答集計</button>
        <button id="detail-send" onclick="detailTab('send')">送付</button>
    </div>

    <div id="detail-content-panel" class="detail-panel"></div>
    <div id="detail-status-panel" class="detail-panel hidden"></div>
    <div id="detail-answers-panel" class="detail-panel hidden"></div>
    <div id="detail-summary-panel" class="detail-panel hidden"></div>
    <div id="detail-send-panel" class="detail-panel hidden"></div>
</section>

<!-- =====================================================
     回答者画面
===================================================== -->
<section id="page-respondent" class="page">
    <div class="page-header">
        <div>
            <h1 class="page-title">回答者画面</h1>
            <p class="page-subtitle">実際の回答者から見える流れを確認できます。</p>
        </div>
        <button class="btn" onclick="showPage('list')">運営者画面へ戻る</button>
    </div>

    <div class="respondent-wrap">
        <div class="respondent-header">
            <h2 style="margin:0 0 8px">お客様満足度アンケート</h2>
            <div>サービスご利用についてのアンケートです。</div>
        </div>
        <div class="respondent-body">
            <div class="respondent-progress">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px">
                    <span>回答の進捗</span><strong id="respondentProgressText">1 / 4</strong>
                </div>
                <div class="progress"><span id="respondentProgress" style="width:25%"></span></div>
            </div>

            <div id="respondentStep1">
                <h3>基本情報</h3>
                <div class="respondent-q">
                    <label class="title">1. ご利用頻度を教えてください。 <span class="required">必須</span></label>
                    <label class="preview-option"><input type="radio" name="r1"> 初めて</label>
                    <label class="preview-option"><input type="radio" name="r1"> 月に1〜2回</label>
                    <label class="preview-option"><input type="radio" name="r1"> 週に1回以上</label>
                </div>
                <div class="respondent-q">
                    <label class="title">2. ご利用サービスを教えてください。 <span class="required">必須</span></label>
                    <select><option>選択してください</option><option>サービスA</option><option>サービスB</option><option>サービスC</option></select>
                </div>
                <div class="respondent-actions">
                    <button class="btn btn-primary" onclick="respondentNext()">次へ</button>
                </div>
            </div>

            <div id="respondentStep2" class="hidden">
                <h3>サービスについて</h3>
                <div class="respondent-q">
                    <label class="title">3. サービスの満足度を教えてください。 <span class="required">必須</span></label>
                    <label class="preview-option"><input type="radio" name="r3"> とても満足</label>
                    <label class="preview-option"><input type="radio" name="r3"> 満足</label>
                    <label class="preview-option"><input type="radio" name="r3"> どちらともいえない</label>
                    <label class="preview-option"><input type="radio" name="r3"> 不満</label>
                    <label class="preview-option"><input type="radio" name="r3"> とても不満</label>
                </div>
                <div class="respondent-q">
                    <label class="title">4. その他ご意見</label>
                    <textarea placeholder="ご自由にご記入ください"></textarea>
                </div>
                <div class="respondent-actions">
                    <button class="btn" onclick="respondentBack()">戻る</button>
                    <button class="btn btn-primary" onclick="respondentConfirm()">回答内容を確認</button>
                </div>
            </div>

            <div id="respondentStep3" class="hidden">
                <h3>回答内容の確認</h3>
                <div class="notice info">送信前に回答内容をご確認ください。</div>
                <div id="respondentConfirmBody">
                    <p><strong>1. ご利用頻度</strong><br>月に1〜2回</p>
                    <p><strong>2. ご利用サービス</strong><br>サービスA</p>
                    <p><strong>3. サービスの満足度</strong><br>満足</p>
                    <p><strong>4. その他ご意見</strong><br>今後も利用したいです。</p>
                </div>
                <div class="respondent-actions">
                    <button class="btn" onclick="respondentBack2()">回答画面へ戻る</button>
                    <button class="btn btn-success" onclick="respondentComplete()">回答を送信する</button>
                </div>
            </div>

            <div id="respondentStep4" class="hidden">
                <div class="notice success" style="text-align:center;margin:30px 0">
                    <strong style="font-size:18px">回答が完了しました</strong>
                    <div style="margin-top:8px">ご回答ありがとうございました。</div>
                </div>
            </div>
        </div>
    </div>
</section>

</main>

<!-- =====================================================
     モーダル
===================================================== -->
<div id="modalBackdrop" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2 id="modalTitle">確認</h2>
            <button class="close-x" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <div class="modal-foot" id="modalFoot"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
/* =========================================================
   サンプルデータ
========================================================= */
var surveys = [
    {
        id:1,
        name:"お客様満足度アンケート",
        description:"サービスご利用についてのアンケートです。",
        status:"回答受付中",
        start:"2026/09/01 09:00",
        end:"2026/09/30 18:00",
        target:120,
        sent:116,
        answered:87,
        updated:"2026/09/24",
        groups:[
            {
                id:"g1",name:"基本情報",description:"ご利用状況について",
                questions:[
                    {id:"q1",text:"ご利用頻度を教えてください。",type:"単一選択",required:true,options:["初めて","月に1〜2回","週に1回以上"]},
                    {id:"q2",text:"ご利用サービスを教えてください。",type:"単一選択",required:true,options:["サービスA","サービスB","サービスC"]}
                ]
            },
            {
                id:"g2",name:"サービスについて",description:"満足度について",
                questions:[
                    {id:"q3",text:"サービスの満足度を教えてください。",type:"単一選択",required:true,options:["とても満足","満足","どちらともいえない","不満","とても不満"]},
                    {id:"q4",text:"その他ご意見があればご記入ください。",type:"長文入力",required:false,options:[]}
                ]
            }
        ]
    },
    {
        id:2,
        name:"新商品に関するアンケート",
        description:"新商品の試用結果を確認します。",
        status:"回答開始待ち",
        start:"2026/10/01 09:00",
        end:"2026/10/31 18:00",
        target:80,
        sent:80,
        answered:0,
        updated:"2026/09/22",
        groups:[
            {
                id:"g3",name:"商品評価",description:"",
                questions:[
                    {id:"q5",text:"商品の第一印象を教えてください。",type:"単一選択",required:true,options:["とても良い","良い","普通","悪い"]},
                    {id:"q6",text:"改善してほしい点を教えてください。",type:"長文入力",required:false,options:[]}
                ]
            }
        ]
    },
    {
        id:3,
        name:"社内研修アンケート",
        description:"研修終了後のアンケートです。",
        status:"回答受付終了",
        start:"2026/08/01 09:00",
        end:"2026/08/31 18:00",
        target:45,
        sent:45,
        answered:41,
        updated:"2026/09/01",
        groups:[
            {
                id:"g4",name:"研修内容",description:"",
                questions:[
                    {id:"q7",text:"研修内容はいかがでしたか。",type:"単一選択",required:true,options:["非常に良い","良い","普通","悪い"]},
                    {id:"q8",text:"今後取り上げてほしいテーマ",type:"短文入力",required:false,options:[]}
                ]
            }
        ]
    },
    {
        id:4,
        name:"昨年度サービス調査",
        description:"昨年度実施済みの調査です。",
        status:"保管",
        start:"2025/10/01 09:00",
        end:"2025/10/31 18:00",
        target:200,
        sent:200,
        answered:168,
        updated:"2025/11/01",
        groups:[
            {
                id:"g5",name:"全体評価",description:"",
                questions:[
                    {id:"q9",text:"総合的な満足度を教えてください。",type:"単一選択",required:true,options:["満足","やや満足","普通","やや不満","不満"]}
                ]
            }
        ]
    },
    {
        id:5,
        name:"営業訪問後アンケート",
        description:"営業訪問後の簡易アンケートです。",
        status:"作成中",
        start:"",
        end:"",
        target:0,
        sent:0,
        answered:0,
        updated:"2026/09/24",
        groups:[
            {
                id:"g6",name:"訪問について",description:"",
                questions:[
                    {id:"q10",text:"訪問担当者の対応はいかがでしたか。",type:"単一選択",required:true,options:["良い","普通","改善してほしい"]}
                ]
            }
        ]
    }
];

var editingSurvey = null;
var detailSurvey = null;
var groupCounter = 100;
var questionCounter = 100;
var branchCounter = 1;
var respondentStep = 1;

/* =========================================================
   共通画面
========================================================= */
function showPage(name){
    var pages=document.querySelectorAll(".page");
    for(var i=0;i<pages.length;i++) pages[i].classList.remove("active");

    var target=document.getElementById("page-"+name);
    if(target) target.classList.add("active");

    var navs=document.querySelectorAll(".main-nav button");
    for(var j=0;j<navs.length;j++) navs[j].classList.remove("active");

    if(name==="list") document.getElementById("nav-list").classList.add("active");
    if(name==="create") document.getElementById("nav-create").classList.add("active");
    if(name==="respondent") document.getElementById("nav-respondent").classList.add("active");

    window.scrollTo(0,0);
}

function showToast(message){
    var t=document.getElementById("toast");
    t.innerText=message;
    t.classList.add("show");
    setTimeout(function(){t.classList.remove("show")},2200);
}

function escapeHtml(str){
    return String(str||"")
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;")
        .replace(/'/g,"&#039;");
}

function statusBadge(status){
    var cls="badge-draft";
    if(status==="回答開始待ち") cls="badge-wait";
    if(status==="回答受付中") cls="badge-open";
    if(status==="回答受付終了") cls="badge-closed";
    if(status==="保管") cls="badge-archive";
    return '<span class="badge '+cls+'">'+escapeHtml(status)+'</span>';
}

/* =========================================================
   一覧
========================================================= */
function renderSurveyList(){
    var body=document.getElementById("surveyRows");
    var keyword=(document.getElementById("searchInput").value||"").toLowerCase();
    var status=document.getElementById("statusFilter").value;
    var html="";

    for(var i=0;i<surveys.length;i++){
        var s=surveys[i];
        if(keyword && s.name.toLowerCase().indexOf(keyword)<0) continue;
        if(status && s.status!==status) continue;

        var rate=s.target ? Math.round(s.answered/s.target*100) : 0;
        html+='<tr>';
        html+='<td><strong>'+escapeHtml(s.name)+'</strong><div class="muted" style="margin-top:3px">'+escapeHtml(s.description)+'</div></td>';
        html+='<td>'+statusBadge(s.status)+'</td>';
        html+='<td><div>'+escapeHtml(s.start||"未設定")+'</div><div class="muted">〜 '+escapeHtml(s.end||"未設定")+'</div></td>';
        html+='<td>'+s.target+'人</td>';
        html+='<td>'+s.answered+'人</td>';
        html+='<td>'+(s.target-s.answered)+'人</td>';
        html+='<td><strong>'+rate+'%</strong></td>';
        html+='<td>'+escapeHtml(s.updated)+'</td>';
        html+='<td><div class="action-group">'+listActions(s)+'</div></td>';
        html+='</tr>';
    }

    if(!html){
        html='<tr><td colspan="9" style="text-align:center;padding:40px">該当するアンケートはありません。</td></tr>';
    }
    body.innerHTML=html;
}

function listActions(s){
    var h="";
    if(s.status==="作成中"){
        h+='<button class="btn btn-small" onclick="openEdit('+s.id+')">編集</button>';
        h+='<button class="btn btn-small" onclick="openPreviewFor('+s.id+')">公開前確認</button>';
        h+='<button class="btn btn-small btn-danger" onclick="deleteSurvey('+s.id+')">削除</button>';
    }else if(s.status==="回答開始待ち" || s.status==="回答受付中"){
        h+='<button class="btn btn-small" onclick="openDetail('+s.id+',\'content\')">内容確認</button>';
        h+='<button class="btn btn-small" onclick="openDetail('+s.id+',\'send\')">送付</button>';
        h+='<button class="btn btn-small" onclick="openDetail('+s.id+',\'status\')">回答状況</button>';
        h+='<button class="btn btn-small btn-warning" onclick="closeSurvey('+s.id+')">受付終了</button>';
    }else if(s.status==="回答受付終了"){
        h+='<button class="btn btn-small" onclick="openDetail('+s.id+',\'content\')">内容確認</button>';
        h+='<button class="btn btn-small" onclick="openDetail('+s.id+',\'status\')">回答状況</button>';
        h+='<button class="btn btn-small btn-primary" onclick="archiveSurvey('+s.id+')">保管</button>';
    }else if(s.status==="保管"){
        h+='<button class="btn btn-small" onclick="openDetail('+s.id+',\'content\')">内容確認</button>';
        h+='<button class="btn btn-small" onclick="openDetail('+s.id+',\'answers\')">回答内容</button>';
    }
    return h;
}

function filterSurveys(){renderSurveyList()}
function clearFilters(){
    document.getElementById("searchInput").value="";
    document.getElementById("statusFilter").value="";
    renderSurveyList();
}

/* =========================================================
   作成・編集
========================================================= */
function openCreate(){
    editingSurvey={
        id:null,
        name:"",
        description:"",
        status:"作成中",
        start:"",
        end:"",
        target:0,
        sent:0,
        answered:0,
        updated:"2026/09/24",
        groups:[
            {id:"g"+groupCounter++,name:"基本情報",description:"",questions:[
                {id:"q"+questionCounter++,text:"",type:"短文入力",required:true,options:[]}
            ]}
        ]
    };
    document.getElementById("createTitle").innerText="アンケート作成";
    showPage("create");
    renderEditor();
}

function openEdit(id){
    for(var i=0;i<surveys.length;i++){
        if(surveys[i].id===id){
            editingSurvey=JSON.parse(JSON.stringify(surveys[i]));
            break;
        }
    }
    if(!editingSurvey)return;
    document.getElementById("createTitle").innerText="アンケート編集";
    showPage("create");
    renderEditor();
}

function renderEditor(){
    document.getElementById("surveyName").value=editingSurvey.name||"";
    document.getElementById("surveyDescription").value=editingSurvey.description||"";
    document.getElementById("startDate").value=toDateInput(editingSurvey.start);
    document.getElementById("endDate").value=toDateInput(editingSurvey.end);

    var list=document.getElementById("groupList");
    var html="";

    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        var g=editingSurvey.groups[gi];
        html+='<div class="group-card" draggable="true" data-group-index="'+gi+'" ondragstart="groupDragStart(event)" ondragover="allowDrop(event)" ondrop="groupDrop(event)">';
        html+='<div class="group-head">';
        html+='<div class="drag-handle" title="ドラッグしてグループを移動">☷</div>';
        html+='<input class="group-title-input" data-group-name="'+gi+'" value="'+escapeHtml(g.name)+'" onchange="updateGroupName('+gi+',this.value)">';
        html+='<div class="group-actions">';
        html+='<button class="btn btn-small" onclick="moveGroupUp('+gi+')">↑</button>';
        html+='<button class="btn btn-small" onclick="moveGroupDown('+gi+')">↓</button>';
        html+='<button class="btn btn-small btn-danger" onclick="removeGroup('+gi+')">削除</button>';
        html+='</div></div>';

        html+='<div class="questions">';
        for(var qi=0;qi<g.questions.length;qi++){
            html+=renderQuestion(g.questions[qi],gi,qi);
        }
        html+='<button class="add-question" onclick="addQuestion('+gi+')">＋ 質問を追加</button>';
        html+='</div></div>';
    }

    list.innerHTML=html;
    renderBranches();
}

function renderQuestion(q,gi,qi){
    var html='';
    html+='<div class="question-card" draggable="true" data-group-index="'+gi+'" data-question-index="'+qi+'" ondragstart="questionDragStart(event)" ondragover="allowDrop(event)" ondrop="questionDrop(event)">';
    html+='<div class="question-head">';
    html+='<div class="drag-handle" title="ドラッグして質問を移動">☷</div>';
    html+='<div class="question-number">'+(getQuestionNumber(gi,qi))+'</div>';
    html+='<div class="question-title">質問</div>';
    html+='<div class="question-actions">';
    html+='<button class="btn btn-small" onclick="moveQuestionUp('+gi+','+qi+')">↑</button>';
    html+='<button class="btn btn-small" onclick="moveQuestionDown('+gi+','+qi+')">↓</button>';
    html+='<button class="btn btn-small btn-danger" onclick="removeQuestion('+gi+','+qi+')">削除</button>';
    html+='</div></div>';

    html+='<div class="question-grid">';
    html+='<div><label class="field-label">質問文</label><input type="text" value="'+escapeHtml(q.text)+'" onchange="updateQuestionText('+gi+','+qi+',this.value)"></div>';
    html+='<div><label class="field-label">質問形式</label><select onchange="updateQuestionType('+gi+','+qi+',this.value)">';
    var types=["短文入力","長文入力","単一選択","複数選択"];
    for(var ti=0;ti<types.length;ti++){
        html+='<option '+(q.type===types[ti]?'selected':'')+'>'+types[ti]+'</option>';
    }
    html+='</select></div>';
    html+='<div><label class="field-label">回答</label><label style="display:flex;align-items:center;gap:6px;height:38px"><input type="checkbox" '+(q.required?'checked':'')+' onchange="updateQuestionRequired('+gi+','+qi+',this.checked)"> 必須</label></div>';
    html+='</div>';

    if(q.type==="単一選択" || q.type==="複数選択"){
        html+='<div class="question-options"><div class="field-label">選択肢</div>';
        for(var oi=0;oi<q.options.length;oi++){
            html+='<div class="option-row">';
            html+='<input type="text" value="'+escapeHtml(q.options[oi])+'" onchange="updateOption('+gi+','+qi+','+oi+',this.value)">';
            html+='<button class="btn btn-small" onclick="removeOption('+gi+','+qi+','+oi+')">×</button>';
            html+='</div>';
        }
        html+='<button class="btn btn-small" onclick="addOption('+gi+','+qi+')">＋ 選択肢を追加</button>';
        html+='</div>';
    }

    html+='</div>';
    return html;
}

function getQuestionNumber(gi,qi){
    var n=0;
    for(var i=0;i<editingSurvey.groups.length;i++){
        if(i>gi)break;
        for(var j=0;j<editingSurvey.groups[i].questions.length;j++){
            if(i===gi && j>qi)break;
            n++;
        }
    }
    return n;
}

function toDateInput(v){
    if(!v)return "";
    var s=v.replace(/\//g,"-");
    if(s.length>=16){
        return s.substring(0,10)+"T"+s.substring(11,16);
    }
    return "";
}

function updateGroupName(i,v){editingSurvey.groups[i].name=v}
function updateQuestionText(gi,qi,v){editingSurvey.groups[gi].questions[qi].text=v}
function updateQuestionRequired(gi,qi,v){editingSurvey.groups[gi].questions[qi].required=v}

function updateQuestionType(gi,qi,v){
    var q=editingSurvey.groups[gi].questions[qi];
    q.type=v;
    if((v==="単一選択"||v==="複数選択") && q.options.length===0){
        q.options=["選択肢1","選択肢2"];
    }
    renderEditor();
}

function updateOption(gi,qi,oi,v){editingSurvey.groups[gi].questions[qi].options[oi]=v}

function addOption(gi,qi){
    editingSurvey.groups[gi].questions[qi].options.push("新しい選択肢");
    renderEditor();
}

function removeOption(gi,qi,oi){
    editingSurvey.groups[gi].questions[qi].options.splice(oi,1);
    renderEditor();
}

function addGroup(){
    editingSurvey.groups.push({
        id:"g"+groupCounter++,
        name:"新しいグループ",
        description:"",
        questions:[]
    });
    renderEditor();
    setTimeout(function(){
        var cards=document.querySelectorAll(".group-card");
        if(cards.length)cards[cards.length-1].scrollIntoView({behavior:"smooth",block:"center"});
    },50);
    showToast("グループを末尾に追加しました");
}

function addQuestion(gi){
    editingSurvey.groups[gi].questions.push({
        id:"q"+questionCounter++,
        text:"",
        type:"短文入力",
        required:false,
        options:[]
    });
    renderEditor();
    showToast("質問を末尾に追加しました");
}

function removeQuestion(gi,qi){
    openConfirm("質問を削除しますか？","削除した質問は元に戻せません。",function(){
        editingSurvey.groups[gi].questions.splice(qi,1);
        renderEditor();
        showToast("質問を削除しました");
    });
}

function removeGroup(gi){
    var count=editingSurvey.groups[gi].questions.length;
    openConfirm("グループを削除しますか？",count+"件の質問も削除されます。",function(){
        editingSurvey.groups.splice(gi,1);
        renderEditor();
        showToast("グループを削除しました");
    });
}

function moveQuestionUp(gi,qi){
    if(qi>0){
        var a=editingSurvey.groups[gi].questions;
        var t=a[qi];a[qi]=a[qi-1];a[qi-1]=t;
        renderEditor();
    }
}

function moveQuestionDown(gi,qi){
    var a=editingSurvey.groups[gi].questions;
    if(qi<a.length-1){
        var t=a[qi];a[qi]=a[qi+1];a[qi+1]=t;
        renderEditor();
    }
}

function moveGroupUp(gi){
    if(gi>0){
        var t=editingSurvey.groups[gi];
        editingSurvey.groups[gi]=editingSurvey.groups[gi-1];
        editingSurvey.groups[gi-1]=t;
        renderEditor();
    }
}

function moveGroupDown(gi){
    if(gi<editingSurvey.groups.length-1){
        var t=editingSurvey.groups[gi];
        editingSurvey.groups[gi]=editingSurvey.groups[gi+1];
        editingSurvey.groups[gi+1]=t;
        renderEditor();
    }
}

/* =========================================================
   ドラッグ＆ドロップ
========================================================= */
var dragGroupIndex=null;
var dragQuestion={gi:null,qi:null};

function groupDragStart(e){
    dragGroupIndex=parseInt(e.currentTarget.getAttribute("data-group-index"),10);
    e.currentTarget.classList.add("dragging");
}
function questionDragStart(e){
    dragQuestion.gi=parseInt(e.currentTarget.getAttribute("data-group-index"),10);
    dragQuestion.qi=parseInt(e.currentTarget.getAttribute("data-question-index"),10);
    e.currentTarget.classList.add("dragging");
}
function allowDrop(e){
    e.preventDefault();
}
function groupDrop(e){
    e.preventDefault();
    var target=e.currentTarget;
    var targetIndex=parseInt(target.getAttribute("data-group-index"),10);
    if(dragGroupIndex===null || dragGroupIndex===targetIndex)return;

    var moved=editingSurvey.groups.splice(dragGroupIndex,1)[0];
    editingSurvey.groups.splice(targetIndex,0,moved);
    dragGroupIndex=null;
    renderEditor();
    showToast("グループの順番を変更しました");
}
function questionDrop(e){
    e.preventDefault();
    var target=e.currentTarget;
    var targetGi=parseInt(target.getAttribute("data-group-index"),10);
    var targetQi=parseInt(target.getAttribute("data-question-index"),10);

    if(dragQuestion.gi===null)return;
    var sourceGroup=editingSurvey.groups[dragQuestion.gi];
    var moved=sourceGroup.questions.splice(dragQuestion.qi,1)[0];

    if(dragQuestion.gi===targetGi && dragQuestion.qi<targetQi){
        targetQi--;
    }

    editingSurvey.groups[targetGi].questions.splice(targetQi,0,moved);
    dragQuestion={gi:null,qi:null};
    renderEditor();
    showToast("質問の順番を変更しました");
}

/* =========================================================
   分岐
========================================================= */
function addBranch(){
    var box=document.getElementById("noBranchNotice");
    if(box)box.remove();

    var list=document.getElementById("branchList");
    var div=document.createElement("div");
    div.className="branch-box show";
    div.innerHTML=
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'+
        '<strong>分岐条件 '+branchCounter+'</strong>'+
        '<button class="btn btn-small btn-danger" onclick="this.parentNode.parentNode.remove()">削除</button>'+
        '</div>'+
        '<div class="branch-grid">'+
        '<div><label class="field-label">条件となる質問</label><select><option>ご利用頻度を教えてください。</option><option>サービスの満足度を教えてください。</option></select></div>'+
        '<div><label class="field-label">回答</label><select><option>初めて</option><option>月に1〜2回</option><option>週に1回以上</option></select></div>'+
        '<div><label class="field-label">分岐先</label><select><option>サービスについて</option><option>次の質問へ</option></select></div>'+
        '</div>';
    list.appendChild(div);
    branchCounter++;
}

/* =========================================================
   保存・公開
========================================================= */
function collectEditor(){
    editingSurvey.name=document.getElementById("surveyName").value.trim();
    editingSurvey.description=document.getElementById("surveyDescription").value.trim();
    editingSurvey.start=document.getElementById("startDate").value.replace("T"," ");
    editingSurvey.end=document.getElementById("endDate").value.replace("T"," ");
}

function validateSurvey(){
    collectEditor();
    var errors=[];

    if(!editingSurvey.name)errors.push("アンケート名を入力してください。");

    var questionCount=0;
    for(var gi=0;gi<editingSurvey.groups.length;gi++){
        var g=editingSurvey.groups[gi];
        for(var qi=0;qi<g.questions.length;qi++){
            var q=g.questions[qi];
            questionCount++;
            if(!q.text.trim())errors.push("質問"+(questionCount)+"の質問文を入力してください。");
            if((q.type==="単一選択"||q.type==="複数選択") && q.options.length<2){
                errors.push("質問"+questionCount+"の選択肢を2件以上設定してください。");
            }
        }
    }

    if(questionCount===0)errors.push("質問を1件以上追加してください。");

    if(editingSurvey.start && editingSurvey.end){
        if(new Date(editingSurvey.start)>new Date(editingSurvey.end)){
            errors.push("回答受付終了日時は開始日時より後にしてください。");
        }
    }
    return errors;
}

function saveDraft(){
    collectEditor();
    if(!editingSurvey.name){
        showToast("アンケート名を入力してください");
        document.getElementById("surveyName").focus();
        return;
    }

    if(editingSurvey.id===null){
        editingSurvey.id=Date.now();
        surveys.unshift(JSON.parse(JSON.stringify(editingSurvey)));
    }else{
        replaceSurvey(editingSurvey);
    }
    editingSurvey.status="作成中";
    editingSurvey.updated="2026/09/24";
    replaceSurvey(editingSurvey);
    renderSurveyList();
    showToast("下書きを保存しました");
}

function replaceSurvey(s){
    for(var i=0;i<surveys.length;i++){
        if(surveys[i].id===s.id){
            surveys[i]=JSON.parse(JSON.stringify(s));
            return;
        }
    }
    surveys.unshift(JSON.parse(JSON.stringify(s)));
}

function openPublishCheck(){
    var errors=validateSurvey();
    if(errors.length){
        document.getElementById("createNotice").className="notice error";
        document.getElementById("createNotice").innerHTML="<strong>公開できません</strong><br>"+errors.join("<br>");
        window.scrollTo(0,0);
        return;
    }

    var qCount=0;
    for(var i=0;i<editingSurvey.groups.length;i++)qCount+=editingSurvey.groups[i].questions.length;

    openModal(
        "公開前確認",
        '<div class="notice info">以下の内容で公開します。公開後は質問・選択肢・グループ・分岐などの構造を変更できません。</div>'+
        '<dl style="line-height:2">'+
        '<dt class="muted">アンケート名</dt><dd><strong>'+escapeHtml(editingSurvey.name)+'</strong></dd>'+
        '<dt class="muted">質問数</dt><dd>'+qCount+'問</dd>'+
        '<dt class="muted">回答受付期間</dt><dd>'+escapeHtml(editingSurvey.start||"公開後すぐ")+' 〜 '+escapeHtml(editingSurvey.end||"手動終了")+'</dd>'+
        '</dl>',
        '<button class="btn" onclick="closeModal()">戻る</button><button class="btn btn-success" onclick="publishSurvey()">公開する</button>'
    );
}

function publishSurvey(){
    collectEditor();
    if(!editingSurvey.id){
        editingSurvey.id=Date.now();
        surveys.unshift(JSON.parse(JSON.stringify(editingSurvey)));
    }
    editingSurvey.status=editingSurvey.start ? "回答開始待ち" : "回答受付中";
    editingSurvey.updated="2026/09/24";
    replaceSurvey(editingSurvey);
    closeModal();
    renderSurveyList();
    showPage("list");
    showToast("アンケートを公開しました");
}

function deleteSurvey(id){
    openConfirm("アンケートを削除しますか？","作成中のアンケートを削除します。",function(){
        for(var i=0;i<surveys.length;i++){
            if(surveys[i].id===id){
                surveys.splice(i,1);
                break;
            }
        }
        renderSurveyList();
        showToast("アンケートを削除しました");
    });
}

function closeSurvey(id){
    openConfirm("回答受付を終了しますか？","終了後は新しい回答・送付・再送ができなくなります。",function(){
        for(var i=0;i<surveys.length;i++){
            if(surveys[i].id===id){
                surveys[i].status="回答受付終了";
                surveys[i].updated="2026/09/24";
                break;
            }
        }
        renderSurveyList();
        if(detailSurvey && detailSurvey.id===id){
            detailSurvey=surveys[i];
            renderDetailHeader();
        }
        showToast("回答受付を終了しました");
    });
}

function archiveSurvey(id){
    openConfirm("アンケートを保管しますか？","保管後は通常の運用対象から外れます。",function(){
        for(var i=0;i<surveys.length;i++){
            if(surveys[i].id===id){
                surveys[i].status="保管";
                surveys[i].updated="2026/09/24";
                break;
            }
        }
        renderSurveyList();
        showToast("アンケートを保管しました");
    });
}

/* =========================================================
   プレビュー
========================================================= */
function openPreview(){
    collectEditor();
    openPreviewContent(editingSurvey);
}

function openPreviewFor(id){
    var s=null;
    for(var i=0;i<surveys.length;i++)if(surveys[i].id===id)s=surveys[i];
    if(s)openPreviewContent(s);
}

function openPreviewContent(s){
    var html='<div class="preview-shell">';
    html+='<div class="preview-top"><h2>'+escapeHtml(s.name||"アンケート")+'</h2><div>'+escapeHtml(s.description||"")+'</div></div>';
    html+='<div class="preview-body">';

    var number=0;
    for(var gi=0;gi<s.groups.length;gi++){
        var g=s.groups[gi];
        html+='<div style="margin-bottom:25px"><h3 style="margin-bottom:5px">'+escapeHtml(g.name)+'</h3>';
        if(g.description)html+='<div class="muted">'+escapeHtml(g.description)+'</div>';

        for(var qi=0;qi<g.questions.length;qi++){
            var q=g.questions[qi];number++;
            html+='<div class="preview-question"><h3>'+number+'. '+escapeHtml(q.text||"（質問文未入力）")+(q.required?'<span class="required">必須</span>':'')+'</h3>';
            if(q.type==="短文入力"){
                html+='<input type="text" placeholder="回答を入力してください">';
            }else if(q.type==="長文入力"){
                html+='<textarea placeholder="回答を入力してください"></textarea>';
            }else{
                for(var oi=0;oi<q.options.length;oi++){
                    var type=q.type==="複数選択"?"checkbox":"radio";
                    html+='<label class="preview-option"><input type="'+type+'" name="preview_'+number+'"> '+escapeHtml(q.options[oi])+'</label>';
                }
            }
            html+='</div>';
        }
        html+='</div>';
    }

    html+='<div class="preview-actions"><button class="btn">戻る</button><button class="btn btn-primary">回答内容を確認</button></div>';
    html+='</div></div>';

    openModal("回答プレビュー",html,'<button class="btn" onclick="closeModal()">閉じる</button>');
}

/* =========================================================
   個別アンケート
========================================================= */
function openDetail(id,tab){
    for(var i=0;i<surveys.length;i++){
        if(surveys[i].id===id){detailSurvey=surveys[i];break;}
    }
    if(!detailSurvey)return;
    showPage("detail");
    renderDetailHeader();
    detailTab(tab||"content");
}

function renderDetailHeader(){
    document.getElementById("detailName").innerText=detailSurvey.name;
    document.getElementById("detailStatus").innerHTML=statusBadge(detailSurvey.status);

    var a=document.getElementById("detailActions");
    var h="";
    if(detailSurvey.status==="回答受付中"||detailSurvey.status==="回答開始待ち"){
        h+='<button class="btn btn-warning" onclick="closeSurvey('+detailSurvey.id+')">受付終了</button>';
    }
    if(detailSurvey.status==="回答受付終了"){
        h+='<button class="btn btn-primary" onclick="archiveSurvey('+detailSurvey.id+')">保管</button>';
    }
    a.innerHTML=h;
}

function detailTab(tab){
    var tabs=["content","status","answers","summary","send"];
    for(var i=0;i<tabs.length;i++){
        document.getElementById("detail-"+tabs[i]).classList.remove("active");
        document.getElementById("detail-"+tabs[i]+"-panel").classList.add("hidden");
    }

    document.getElementById("detail-"+tab).classList.add("active");
    document.getElementById("detail-"+tab+"-panel").classList.remove("hidden");

    if(tab==="content")renderDetailContent();
    if(tab==="status")renderStatus();
    if(tab==="answers")renderAnswers();
    if(tab==="summary")renderSummary();
    if(tab==="send")renderSend();
}

function renderDetailContent(){
    var s=detailSurvey;
    var h='<div class="card"><div class="card-head"><h2>アンケート内容</h2>';
    if(s.status==="作成中")h+='<button class="btn btn-primary" onclick="openEdit('+s.id+')">編集</button>';
    h+='</div><div class="card-body">';
    h+='<div class="form-grid"><div><div class="muted">アンケート名</div><strong>'+escapeHtml(s.name)+'</strong></div>';
    h+='<div><div class="muted">回答受付期間</div>'+escapeHtml(s.start||"公開後すぐ")+' 〜 '+escapeHtml(s.end||"手動終了")+'</div>';
    h+='<div class="full"><div class="muted">説明</div>'+escapeHtml(s.description)+'</div></div>';
    h+='</div></div>';

    h+='<div class="card"><div class="card-head"><h2>質問一覧</h2></div><div class="card-body">';
    var n=0;
    for(var gi=0;gi<s.groups.length;gi++){
        var g=s.groups[gi];
        h+='<div style="margin-bottom:20px"><h3>'+escapeHtml(g.name)+'</h3>';
        for(var qi=0;qi<g.questions.length;qi++){
            var q=g.questions[qi];n++;
            h+='<div style="padding:12px;border:1px solid #e0e6e9;border-radius:6px;margin:7px 0">';
            h+='<strong>'+n+'. '+escapeHtml(q.text)+'</strong>';
            h+='<span class="badge" style="margin-left:8px;background:#edf3f6;color:#54656f">'+escapeHtml(q.type)+'</span>';
            if(q.required)h+='<span class="required">必須</span>';
            if(q.options.length)h+='<div class="muted" style="margin-top:7px">選択肢：'+q.options.map(escapeHtml).join(" / ")+'</div>';
            h+='</div>';
        }
        h+='</div>';
    }
    h+='</div></div>';
    document.getElementById("detail-content-panel").innerHTML=h;
}

function renderStatus(){
    var s=detailSurvey;
    var rate=s.target?Math.round(s.answered/s.target*100):0;
    var notAnswered=s.target-s.answered;

    var h='<div class="stats">';
    h+=statBox("回答対象者",s.target+"人","");
    h+=statBox("送付済み",s.sent+"人","");
    h+=statBox("未送付",Math.max(0,s.target-s.sent)+"人","");
    h+=statBox("回答済み",s.answered+"人","");
    h+=statBox("回答率",rate+"%","対象者に対する回答済み人数");
    h+='</div>';

    h+='<div class="card"><div class="card-head"><h2>回答状況</h2></div><div class="card-body">';
    h+='<div style="display:flex;justify-content:space-between;margin-bottom:7px"><span>回答率</span><strong>'+rate+'%</strong></div>';
    h+='<div class="progress"><span style="width:'+rate+'%"></span></div>';
    h+='<div style="margin-top:14px" class="muted">未回答者 '+notAnswered+'人</div>';
    h+='</div></div>';

    h+='<div class="card"><div class="card-head"><h2>対象者別状況</h2><button class="btn btn-small" onclick="detailTab(\'send\')">未回答者へ再送</button></div>';
    h+='<div class="table-wrap"><table><thead><tr><th>顧客名</th><th>担当者</th><th>送付状況</th><th>回答状況</th><th>回答日時</th><th>操作</th></tr></thead><tbody>';

    var people=[
        ["株式会社青空商事","田中","送付済み","回答済み","2026/09/03 10:21"],
        ["株式会社みどり","佐藤","送付済み","回答済み","2026/09/05 15:12"],
        ["株式会社中央","鈴木","送付済み","未回答","—"],
        ["株式会社未来","高橋","送付済み","回答済み","2026/09/12 09:40"],
        ["株式会社東都","伊藤","未送付","未回答","—"],
        ["株式会社西日本","山本","送付済み","未回答","—"]
    ];

    for(var i=0;i<people.length;i++){
        var p=people[i];
        h+='<tr><td>'+p[0]+'</td><td>'+p[1]+'</td><td><span class="person-status"><i class="dot '+(p[2]==="送付済み"?"green":"gray")+'"></i>'+p[2]+'</span></td><td><span class="person-status"><i class="dot '+(p[3]==="回答済み"?"green":"orange")+'"></i>'+p[3]+'</span></td><td>'+p[4]+'</td><td>';
        if(p[3]==="回答済み")h+='<button class="btn btn-small" onclick="showAnswer(\''+p[0]+'\')">回答を見る</button>';
        else if(s.status==="回答受付中")h+='<button class="btn btn-small" onclick="resendPerson(\''+p[0]+'\')">再送</button>';
        h+='</td></tr>';
    }
    h+='</tbody></table></div></div>';
    document.getElementById("detail-status-panel").innerHTML=h;
}

function statBox(label,value,note){
    return '<div class="stat"><div class="stat-label">'+label+'</div><div class="stat-value">'+value+'</div><div class="stat-note">'+note+'</div></div>';
}

function renderAnswers(){
    var h='<div class="card"><div class="card-head"><h2>回答内容</h2><select style="width:260px"><option>株式会社青空商事 / 田中</option><option>株式会社みどり / 佐藤</option><option>株式会社未来 / 高橋</option></select></div><div class="card-body">';
    h+='<div class="notice info">回答者ごとの回答内容を確認できます。未回答の任意項目は「回答なし」として表示します。</div>';
    h+='<div style="border-bottom:1px solid #e2e7ea;padding-bottom:12px;margin-bottom:12px"><span class="muted">回答日時</span>　2026/09/03 10:21</div>';

    var answers=[
        ["1","ご利用頻度を教えてください。","月に1〜2回"],
        ["2","ご利用サービスを教えてください。","サービスA"],
        ["3","サービスの満足度を教えてください。","満足"],
        ["4","その他ご意見があればご記入ください。","今後も利用したいです。"]
    ];
    for(var i=0;i<answers.length;i++){
        h+='<div style="padding:13px 0;border-bottom:1px solid #edf0f2"><div class="muted">質問'+answers[i][0]+'</div><strong>'+answers[i][1]+'</strong><div style="margin-top:7px">'+answers[i][2]+'</div></div>';
    }
    h+='</div></div>';
    document.getElementById("detail-answers-panel").innerHTML=h;
}

function renderSummary(){
    var h='<div class="stats">';
    h+=statBox("回答総数","87件","回答済みのみ");
    h+=statBox("平均回答時間","3分42秒","");
    h+=statBox("満足","58件","66.7%");
    h+=statBox("普通","19件","21.8%");
    h+=statBox("不満","10件","11.5%");
    h+='</div>';

    h+='<div class="card"><div class="card-head"><h2>質問3：サービスの満足度</h2><span class="muted">回答総数 87件</span></div><div class="card-body">';
    var vals=[["とても満足",28],["満足",30],["どちらともいえない",19],["不満",7],["とても不満",3]];
    for(var i=0;i<vals.length;i++){
        var pct=Math.round(vals[i][1]/87*100);
        h+='<div class="chart-row"><div>'+vals[i][0]+'</div><div class="bar"><span style="width:'+pct+'%"></span></div><div>'+vals[i][1]+'件</div></div>';
    }
    h+='</div></div>';

    h+='<div class="card"><div class="card-head"><h2>回答傾向</h2></div><div class="card-body" style="display:grid;grid-template-columns:190px 1fr;gap:30px;align-items:center">';
    h+='<div class="pie-placeholder"></div><div><p><span class="dot green"></span> 満足系　66.7%</p><p><span class="dot orange"></span> 中立　21.8%</p><p><span class="dot gray"></span> 不満系　11.5%</p></div>';
    h+='</div></div>';

    h+='<div class="card"><div class="card-head"><h2>自由記述回答</h2></div><div class="card-body">';
    h+='<div style="padding:10px 0;border-bottom:1px solid #eee">「スタッフの対応が丁寧で安心して利用できました。」</div>';
    h+='<div style="padding:10px 0;border-bottom:1px solid #eee">「予約がもう少し取りやすいと嬉しいです。」</div>';
    h+='<div style="padding:10px 0">「今後も継続して利用したいと思います。」</div>';
    h+='</div></div>';

    document.getElementById("detail-summary-panel").innerHTML=h;
}

function renderSend(){
    var s=detailSurvey;
    var canSend=(s.status==="回答開始待ち"||s.status==="回答受付中");

    var h='<div class="card"><div class="card-head"><h2>送付対象者</h2>';
    if(canSend)h+='<button class="btn btn-primary" onclick="openSendConfirm()">選択した対象者へ送付</button>';
    h+='</div><div class="card-body">';

    if(!canSend)h+='<div class="notice warning">回答受付が終了しているため、新規送付・再送はできません。</div>';

    h+='<div class="toolbar"><input type="checkbox" id="selectAllPeople" onchange="togglePeople(this.checked)"> <label for="selectAllPeople">全選択</label><span class="muted">未回答者を選択して再送できます。</span></div>';

    var people=[
        ["株式会社青空商事","田中","tanaka@example.com","送付済み","回答済み"],
        ["株式会社みどり","佐藤","sato@example.com","送付済み","回答済み"],
        ["株式会社中央","鈴木","suzuki@example.com","送付済み","未回答"],
        ["株式会社未来","高橋","takahashi@example.com","送付済み","回答済み"],
        ["株式会社東都","伊藤","ito@example.com","未送付","未回答"],
        ["株式会社西日本","山本","yamamoto@example.com","送付済み","未回答"]
    ];

    h+='<div class="table-wrap"><table><thead><tr><th></th><th>顧客名</th><th>担当者</th><th>メールアドレス</th><th>送付状況</th><th>回答状況</th></tr></thead><tbody>';
    for(var i=0;i<people.length;i++){
        var p=people[i];
        var disabled=!canSend || p[4]==="回答済み";
        h+='<tr><td><input class="person-check" type="checkbox" '+(disabled?'disabled':'')+'></td><td>'+p[0]+'</td><td>'+p[1]+'</td><td>'+p[2]+'</td><td>'+p[3]+'</td><td>'+p[4]+'</td></tr>';
    }
    h+='</tbody></table></div></div></div>';

    document.getElementById("detail-send-panel").innerHTML=h;
}

function togglePeople(checked){
    var boxes=document.querySelectorAll(".person-check");
    for(var i=0;i<boxes.length;i++)if(!boxes[i].disabled)boxes[i].checked=checked;
}

function openSendConfirm(){
    var boxes=document.querySelectorAll(".person-check:checked");
    if(boxes.length===0){
        showToast("送付対象者を選択してください");
        return;
    }
    openModal(
        "送付前確認",
        '<div class="notice warning">以下の対象者へアンケートを送付します。</div>'+
        '<p><strong>アンケート：</strong>'+escapeHtml(detailSurvey.name)+'</p>'+
        '<p><strong>送付対象者：</strong>'+boxes.length+'人</p>'+
        '<p class="muted">送付済みの対象者を選択した場合は再送として扱います。</p>',
        '<button class="btn" onclick="closeModal()">戻る</button><button class="btn btn-primary" onclick="executeSend()">送付する</button>'
    );
}

function executeSend(){
    closeModal();
    showToast("アンケートを送付しました");
    renderSend();
}

function resendPerson(name){
    openConfirm("再送しますか？",name+"さんへアンケートを再送します。",function(){
        showToast(name+"さんへ再送しました");
    });
}

function showAnswer(name){
    openModal("回答内容",
        '<div class="notice info">'+escapeHtml(name)+'さんの回答です。</div>'+
        '<p><strong>ご利用頻度</strong><br>月に1〜2回</p>'+
        '<p><strong>ご利用サービス</strong><br>サービスA</p>'+
        '<p><strong>サービスの満足度</strong><br>満足</p>'+
        '<p><strong>その他ご意見</strong><br>今後も利用したいです。</p>',
        '<button class="btn" onclick="closeModal()">閉じる</button>'
    );
}

/* =========================================================
   モーダル
========================================================= */
function openModal(title,body,foot){
    document.getElementById("modalTitle").innerText=title;
    document.getElementById("modalBody").innerHTML=body;
    document.getElementById("modalFoot").innerHTML=foot||'<button class="btn" onclick="closeModal()">閉じる</button>';
    document.getElementById("modalBackdrop").classList.add("show");
}
function closeModal(){
    document.getElementById("modalBackdrop").classList.remove("show");
}
function openConfirm(title,message,callback){
    openModal(title,
        '<p>'+escapeHtml(message)+'</p>',
        '<button class="btn" onclick="closeModal()">キャンセル</button><button class="btn btn-danger" id="modalConfirmButton">実行する</button>'
    );
    document.getElementById("modalConfirmButton").onclick=function(){
        closeModal();
        callback();
    };
}

/* =========================================================
   回答者画面
========================================================= */
function respondentNext(){
    respondentStep=2;
    document.getElementById("respondentStep1").classList.add("hidden");
    document.getElementById("respondentStep2").classList.remove("hidden");
    document.getElementById("respondentProgress").style.width="50%";
    document.getElementById("respondentProgressText").innerText="2 / 4";
}
function respondentBack(){
    respondentStep=1;
    document.getElementById("respondentStep2").classList.add("hidden");
    document.getElementById("respondentStep1").classList.remove("hidden");
    document.getElementById("respondentProgress").style.width="25%";
    document.getElementById("respondentProgressText").innerText="1 / 4";
}
function respondentConfirm(){
    respondentStep=3;
    document.getElementById("respondentStep2").classList.add("hidden");
    document.getElementById("respondentStep3").classList.remove("hidden");
    document.getElementById("respondentProgress").style.width="75%";
    document.getElementById("respondentProgressText").innerText="確認";
}
function respondentBack2(){
    respondentStep=2;
    document.getElementById("respondentStep3").classList.add("hidden");
    document.getElementById("respondentStep2").classList.remove("hidden");
    document.getElementById("respondentProgress").style.width="50%";
    document.getElementById("respondentProgressText").innerText="2 / 4";
}
function respondentComplete(){
    respondentStep=4;
    document.getElementById("respondentStep3").classList.add("hidden");
    document.getElementById("respondentStep4").classList.remove("hidden");
    document.getElementById("respondentProgress").style.width="100%";
    document.getElementById("respondentProgressText").innerText="完了";
}

/* =========================================================
   初期化
========================================================= */
document.getElementById("modalBackdrop").addEventListener("click",function(e){
    if(e.target===this)closeModal();
});

renderSurveyList();
</script>
</body>
</html>

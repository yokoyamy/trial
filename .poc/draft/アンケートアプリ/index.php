<?php
/*
 * アンケート管理システム モック
 * index.php 1ファイル完結版
 *
 * ・HTML / CSS / JavaScript を本ファイルに内包
 * ・DB / kintone API / SMTP / 認証等は未実装
 * ・サンプルデータをJavaScriptで管理
 * ・画面遷移、追加、編集、削除、D&D、採番、プレビュー等を実際に操作可能
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム</title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --gray:#64748b;
    --light:#f8fafc;
    --border:#e2e8f0;
    --text:#1e293b;
    --sidebar:#0f172a;
    --white:#fff;
    --shadow:0 2px 10px rgba(15,23,42,.08);
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:var(--text);background:#f1f5f9}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.app{min-height:100vh}
.header{
    height:64px;background:#fff;border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;padding:0 24px;
    position:sticky;top:0;z-index:30;
}
.logo{font-size:19px;font-weight:800;color:#0f172a}
.logo span{color:var(--primary)}
.header-right{display:flex;align-items:center;gap:18px;color:#64748b;font-size:13px}
.layout{display:flex;min-height:calc(100vh - 64px)}
.sidebar{
    width:230px;background:var(--sidebar);color:#cbd5e1;flex:none;
    padding:18px 12px;
}
.nav-title{font-size:11px;color:#64748b;padding:10px 12px 7px;font-weight:700}
.nav-btn{
    width:100%;border:0;background:transparent;color:#cbd5e1;
    padding:11px 12px;text-align:left;border-radius:7px;margin-bottom:3px;
}
.nav-btn:hover,.nav-btn.active{background:#1e293b;color:#fff}
.main{flex:1;min-width:0;padding:24px}
.page{display:none}
.page.active{display:block}
.page-title{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:20px;gap:15px;flex-wrap:wrap;
}
.page-title h1{font-size:24px;margin:0}
.page-title p{margin:5px 0 0;color:var(--gray);font-size:13px}
.card{background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);margin-bottom:18px}
.card-header{
    padding:15px 18px;border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;gap:10px;
}
.card-header h2,.card-header h3{margin:0;font-size:16px}
.card-body{padding:18px}
.btn{
    border:1px solid var(--border);background:#fff;color:#334155;
    border-radius:7px;padding:8px 13px;font-weight:600;font-size:13px;
}
.btn:hover{background:#f8fafc}
.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-dark)}
.btn-success{background:var(--success);border-color:var(--success);color:#fff}
.btn-danger{background:var(--danger);border-color:var(--danger);color:#fff}
.btn-warning{background:var(--warning);border-color:var(--warning);color:#fff}
.btn-sm{padding:6px 9px;font-size:12px}
.btn-group{display:flex;gap:7px;flex-wrap:wrap}
.badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}
.badge-draft{background:#e2e8f0;color:#475569}
.badge-published{background:#dcfce7;color:#166534}
.badge-stopped{background:#fef3c7;color:#92400e}
.badge-ended{background:#fee2e2;color:#991b1b}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.badge-green{background:#dcfce7;color:#166534}
.badge-red{background:#fee2e2;color:#991b1b}
.badge-gray{background:#f1f5f9;color:#475569}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:12px;font-weight:700;color:#475569}
.form-control{
    border:1px solid #cbd5e1;border-radius:7px;padding:9px 10px;
    background:#fff;color:#1e293b;width:100%;
}
textarea.form-control{min-height:90px;resize:vertical}
.form-help{font-size:11px;color:#64748b}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:920px}
th,td{border-bottom:1px solid var(--border);padding:12px 10px;text-align:left;font-size:12px;vertical-align:middle}
th{background:#f8fafc;color:#475569;font-weight:700;white-space:nowrap}
tr:hover td{background:#fafcff}
.toolbar{
    display:flex;align-items:center;gap:9px;flex-wrap:wrap;
    padding:14px 16px;background:#fff;border:1px solid var(--border);
    border-radius:10px;margin-bottom:15px;
}
.toolbar .search{flex:1;min-width:220px}
.empty{text-align:center;padding:50px 20px;color:#64748b}
.alert{padding:12px 14px;border-radius:7px;font-size:13px;margin-bottom:15px}
.alert-info{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.alert-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px}
.stat{background:#fff;border:1px solid var(--border);border-radius:10px;padding:17px;box-shadow:var(--shadow)}
.stat-label{font-size:11px;color:#64748b}
.stat-value{font-size:25px;font-weight:800;margin-top:5px}
.editor-top{
    background:#fff;border:1px solid var(--border);border-radius:10px;
    padding:18px;margin-bottom:18px;box-shadow:var(--shadow)
}
.status-line{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.editor-actions{display:flex;gap:7px;flex-wrap:wrap}
.group-card{
    background:#fff;border:1px solid var(--border);border-radius:10px;
    margin-bottom:16px;box-shadow:var(--shadow)
}
.group-card.drag-over{border:2px dashed var(--primary);background:#eff6ff}
.group-header{
    padding:12px 15px;background:#f8fafc;border-bottom:1px solid var(--border);
    display:flex;align-items:center;gap:10px
}
.drag-handle{cursor:grab;color:#94a3b8;font-size:18px}
.group-title-input{
    flex:1;border:0;background:transparent;font-weight:800;font-size:15px;
    padding:6px;border-radius:5px
}
.group-title-input:focus{outline:1px solid #93c5fd;background:#fff}
.question-list{padding:12px}
.question-card{
    border:1px solid #dbe2ea;border-radius:8px;margin-bottom:9px;background:#fff;
    transition:.15s
}
.question-card.dragging{opacity:.45}
.question-card.drag-over{border:2px dashed var(--primary);background:#eff6ff}
.question-header{
    padding:10px 12px;border-bottom:1px solid #edf2f7;
    display:flex;align-items:center;gap:9px
}
.question-number{font-weight:800;color:var(--primary);min-width:55px}
.question-preview{flex:1;font-weight:700;font-size:13px}
.question-body{padding:12px}
.question-actions{display:flex;gap:6px;margin-left:auto}
.option-row{display:flex;gap:7px;margin-bottom:7px;align-items:center}
.option-row input{flex:1}
.add-question{
    width:100%;border:1px dashed #94a3b8;background:#f8fafc;
    color:#475569;padding:10px;border-radius:7px;font-weight:700
}
.add-question:hover{background:#eff6ff;border-color:#60a5fa;color:#1d4ed8}
.add-group{
    width:100%;border:1px dashed #60a5fa;background:#eff6ff;color:#1d4ed8;
    padding:13px;border-radius:8px;font-weight:800
}
.radio-list{display:flex;gap:20px;flex-wrap:wrap}
.radio-item{display:flex;align-items:center;gap:7px;font-size:13px}
.checkbox-list{display:flex;flex-direction:column;gap:9px}
.checkbox-item{display:flex;align-items:center;gap:8px;font-size:13px}
.modal-backdrop{
    display:none;position:fixed;inset:0;background:rgba(15,23,42,.52);
    z-index:100;align-items:center;justify-content:center;padding:18px
}
.modal-backdrop.show{display:flex}
.modal{
    background:#fff;border-radius:12px;width:min(680px,100%);max-height:90vh;
    overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,.2)
}
.modal-header{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-header h3{margin:0;font-size:16px}
.modal-body{padding:18px}
.modal-footer{padding:13px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
.close{border:0;background:transparent;font-size:22px;color:#64748b}
.preview-frame{
    background:#e2e8f0;border-radius:10px;padding:25px;
    min-height:500px
}
.preview-device{
    background:#fff;margin:auto;box-shadow:0 5px 25px rgba(0,0,0,.12);
    border-radius:10px;overflow:hidden
}
.preview-device.pc{max-width:900px}
.preview-device.mobile{max-width:390px}
.preview-inner{padding:24px}
.preview-q{margin:20px 0}
.preview-q-title{font-weight:800;margin-bottom:9px}
.required{color:#dc2626;font-size:11px;margin-left:5px}
.preview-option{display:flex;gap:8px;padding:9px 0;align-items:center}
.preview-actions{display:flex;justify-content:space-between;gap:10px;margin-top:25px}
.bar{height:8px;background:#e2e8f0;border-radius:5px;overflow:hidden;margin-top:7px}
.bar span{height:100%;display:block;background:var(--primary)}
.kintone-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.mapping-box{border:1px solid var(--border);border-radius:8px;padding:14px;background:#f8fafc}
.mapping-box h4{margin:0 0 10px;font-size:13px}
.history-row{display:flex;justify-content:space-between;gap:10px;padding:11px 0;border-bottom:1px solid var(--border);font-size:12px}
.mobile-preview-toggle{display:flex;gap:5px}
.toast{
    position:fixed;right:20px;bottom:20px;background:#0f172a;color:#fff;
    padding:12px 16px;border-radius:8px;box-shadow:0 8px 25px rgba(0,0,0,.2);
    z-index:300;display:none;font-size:13px
}
.toast.show{display:block}
.confirm-text{font-size:14px;line-height:1.8}
.send-result{padding:9px;border-radius:6px;background:#f8fafc;margin-top:8px;font-size:12px}
.customer-row-unregistered{background:#fffdf5}
.tabs{display:flex;gap:3px;border-bottom:1px solid var(--border);margin-bottom:15px}
.tab{border:0;background:transparent;padding:10px 14px;color:#64748b;font-weight:700;border-bottom:2px solid transparent}
.tab.active{color:var(--primary);border-bottom-color:var(--primary)}
.chart-row{margin-bottom:16px}
.chart-label{display:flex;justify-content:space-between;font-size:12px}
.radio-card{
    border:1px solid var(--border);border-radius:8px;padding:13px;
    display:flex;gap:10px;align-items:flex-start;margin-bottom:8px
}
.radio-card.selected{border-color:#60a5fa;background:#eff6ff}
.muted{color:#64748b}
.small{font-size:11px}
@media(max-width:900px){
    .sidebar{width:190px}
    .stats{grid-template-columns:repeat(2,1fr)}
    .form-grid,.kintone-grid{grid-template-columns:1fr}
}
@media(max-width:680px){
    .header{padding:0 13px}
    .header-right{display:none}
    .sidebar{
        width:100%;height:auto;padding:7px;position:sticky;top:64px;z-index:20;
        display:flex;overflow-x:auto
    }
    .nav-title{display:none}
    .nav-btn{white-space:nowrap;width:auto;margin:0}
    .layout{display:block}
    .main{padding:13px}
    .page-title h1{font-size:20px}
    .stats{grid-template-columns:1fr 1fr}
    .stat-value{font-size:20px}
    .editor-actions{width:100%}
    .editor-actions .btn{flex:1}
    .preview-frame{padding:10px}
    .preview-inner{padding:16px}
}
</style>
</head>
<body>

<div class="app">
<header class="header">
    <div class="logo">アンケート<span>管理システム</span></div>
    <div class="header-right">
        <span>モック環境</span>
        <span>管理者</span>
        <button class="btn btn-sm" onclick="showToast('ログアウトしました（モック）')">ログアウト</button>
    </div>
</header>

<div class="layout">
<aside class="sidebar">
    <div class="nav-title">MENU</div>
    <button class="nav-btn active" data-page="list" onclick="showPage('list')">📋 アンケート一覧</button>
    <button class="nav-btn" data-page="kintone" onclick="showPage('kintone')">🔗 kintone連携設定</button>
    <button class="nav-btn" data-page="mailserver" onclick="showPage('mailserver')">✉ メールサーバ設定</button>
</aside>

<main class="main">

<!-- =========================================================
     アンケート一覧
========================================================= -->
<section id="page-list" class="page active">
    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <p>登録されているアンケートを管理します。</p>
        </div>
        <button class="btn btn-primary" onclick="newSurvey()">＋ 新規アンケート作成</button>
    </div>

    <div class="toolbar">
        <input id="surveySearch" class="form-control search" placeholder="タイトルを検索（Enterで検索）"
               onkeydown="if(event.key==='Enter')renderSurveyList()">
        <select id="statusFilter" class="form-control" style="width:140px" onchange="renderSurveyList()">
            <option value="">すべて</option>
            <option value="公開中">公開中</option>
            <option value="下書き">下書き</option>
            <option value="停止">停止</option>
            <option value="終了">終了</option>
        </select>
        <select id="sortFilter" class="form-control" style="width:150px" onchange="renderSurveyList()">
            <option value="updatedDesc">更新日：新しい順</option>
            <option value="updatedAsc">更新日：古い順</option>
            <option value="answersDesc">回答数：多い順</option>
            <option value="answersAsc">回答数：少ない順</option>
            <option value="startDesc">期間開始日：新しい順</option>
            <option value="startAsc">期間開始日：古い順</option>
        </select>
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
     アンケート作成・編集
========================================================= -->
<section id="page-editor" class="page">
    <div class="page-title">
        <div>
            <h1 id="editorHeading">アンケート作成</h1>
            <p>アンケート内容と公開状態を管理します。</p>
        </div>
        <button class="btn" onclick="showPage('list')">← 一覧へ戻る</button>
    </div>

    <div class="editor-top">
        <div class="status-line">
            <strong>現在のステータス：</strong>
            <span id="editorStatus"></span>
            <span class="small muted" id="editorId"></span>
        </div>

        <div class="editor-actions" id="editorActions"></div>

        <div class="alert alert-info" style="margin:14px 0 0">
            状態変更はこの画面からのみ行います。一覧画面では公開・停止・再開操作を行いません。
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>基本情報</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group full">
                    <label>アンケートタイトル</label>
                    <input id="surveyTitle" class="form-control" oninput="updateSurveyField('title',this.value)">
                </div>
                <div class="form-group full">
                    <label>アンケート説明</label>
                    <textarea id="surveyDescription" class="form-control"
                              oninput="updateSurveyField('description',this.value)"></textarea>
                </div>
                <div class="form-group">
                    <label>開始日時</label>
                    <input id="surveyStart" type="datetime-local" class="form-control"
                           onchange="updateSurveyField('start',this.value)">
                </div>
                <div class="form-group">
                    <label>終了日時</label>
                    <input id="surveyEnd" type="datetime-local" class="form-control"
                           onchange="updateSurveyField('end',this.value)">
                </div>
                <div class="form-group full">
                    <label>質問番号の採番方式</label>
                    <div class="radio-list">
                        <label class="radio-card" style="flex:1;min-width:250px">
                            <input type="radio" name="numbering" value="global"
                                   onchange="changeNumbering('global')">
                            <span>
                                <strong>アンケート全体で通番</strong><br>
                                <span class="small muted">例：Q1、Q2、Q3、Q4…</span>
                            </span>
                        </label>
                        <label class="radio-card" style="flex:1;min-width:250px">
                            <input type="radio" name="numbering" value="group"
                                   onchange="changeNumbering('group')">
                            <span>
                                <strong>グループ毎に採番</strong><br>
                                <span class="small muted">例：Q1-1、Q1-2、Q2-1、Q2-2…</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="groupsContainer"></div>

    <button class="add-group" onclick="addGroup()">＋ グループを追加</button>

    <div class="card" style="margin-top:18px">
        <div class="card-body">
            <div class="btn-group">
                <button class="btn btn-primary" onclick="saveDraft()">下書き保存</button>
                <button class="btn btn-success" onclick="changeStatus('publish')">公開</button>
                <button class="btn btn-warning" onclick="changeStatus('stop')">停止</button>
                <button class="btn btn-success" onclick="changeStatus('resume')">再開</button>
                <button class="btn" onclick="openPreview()">プレビュー</button>
                <button class="btn" onclick="saveAndBack()">保存して一覧へ戻る</button>
                <button class="btn" onclick="cancelEditor()">キャンセル</button>
            </div>
        </div>
    </div>
</section>

<!-- =========================================================
     プレビュー
========================================================= -->
<section id="page-preview" class="page">
    <div class="page-title">
        <div>
            <h1>アンケートプレビュー</h1>
            <p>回答者から見た表示を確認できます。</p>
        </div>
        <div class="mobile-preview-toggle">
            <button class="btn btn-sm" onclick="setPreviewDevice('pc')">PC</button>
            <button class="btn btn-sm" onclick="setPreviewDevice('mobile')">スマートフォン</button>
            <button class="btn btn-sm" onclick="showPage('editor')">← 編集へ戻る</button>
        </div>
    </div>
    <div class="preview-frame">
        <div id="previewDevice" class="preview-device pc">
            <div id="previewContent" class="preview-inner"></div>
        </div>
    </div>
</section>

<!-- =========================================================
     顧客選択・メール送信
========================================================= -->
<section id="page-send" class="page">
    <div class="page-title">
        <div>
            <h1>顧客選択・メール送信</h1>
            <p id="sendSurveyTitle"></p>
        </div>
        <button class="btn" onclick="showPage('list')">← 一覧へ戻る</button>
    </div>

    <div class="card">
        <div class="card-header"><h2>メールテンプレート</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group full">
                    <label>メール件名</label>
                    <input id="mailSubject" class="form-control" value="アンケートご回答のお願い">
                </div>
                <div class="form-group full">
                    <label>メール本文</label>
                    <textarea id="mailBody" class="form-control" style="min-height:150px">【アンケートのお願い】

{顧客名} 様

以下のURLよりアンケートへご回答ください。

{アンケートURL}

ご協力をお願いいたします。</textarea>
                </div>
                <div class="form-group full">
                    <div class="alert alert-info" style="margin:0">
                        使用できる動的変数：<strong>{顧客名}</strong>　<strong>{アンケートURL}</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="toolbar">
        <input id="customerSearch" class="form-control search"
               placeholder="顧客名・組織名・メールアドレスを検索"
               oninput="renderCustomers()">
        <select id="customerStatus" class="form-control" style="width:170px" onchange="renderCustomers()">
            <option value="">すべて</option>
            <option value="未送信">未送信</option>
            <option value="送信済み / 未回答">送信済み / 未回答</option>
            <option value="回答済み">回答済み</option>
            <option value="未登録">未登録</option>
        </select>
        <button class="btn" onclick="selectAllCustomers()">全選択</button>
        <button class="btn" onclick="clearCustomers()">選択解除</button>
        <button class="btn btn-primary" onclick="sendSelected()">選択顧客へ一括送信</button>
        <button class="btn btn-warning" onclick="sendReminder()">未回答者へリマインド</button>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th><input type="checkbox" id="customerAll" onchange="toggleVisibleCustomers(this.checked)"></th>
                    <th>組織名</th>
                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th>電話番号</th>
                    <th>住所</th>
                    <th>最終送信日時</th>
                    <th>送信回数</th>
                    <th>回答ステータス</th>
                    <th>送信文</th>
                    <th>kintone</th>
                </tr>
                </thead>
                <tbody id="customerTable"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>送信履歴</h2>
            <button class="btn btn-sm" onclick="showPage('history')">履歴を詳しく見る</button>
        </div>
        <div class="card-body" id="sendHistoryMini"></div>
    </div>
</section>

<!-- =========================================================
     送信履歴
========================================================= -->
<section id="page-history" class="page">
    <div class="page-title">
        <div>
            <h1>送信履歴</h1>
            <p>メール送信履歴と送信内容を確認します。</p>
        </div>
        <button class="btn" onclick="showPage('send')">← 送信画面へ戻る</button>
    </div>

    <div class="card">
        <div class="card-body" id="historyContainer"></div>
    </div>
</section>

<!-- =========================================================
     集計・分析
========================================================= -->
<section id="page-analysis" class="page">
    <div class="page-title">
        <div>
            <h1>回答集計・分析</h1>
            <p id="analysisTitle"></p>
        </div>
        <div class="btn-group">
            <button class="btn" onclick="exportMock('CSV')">CSVダウンロード</button>
            <button class="btn" onclick="exportMock('PDF')">PDF出力</button>
            <button class="btn" onclick="showPage('list')">← 一覧へ戻る</button>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><div class="stat-label">送信対象者数</div><div class="stat-value" id="statTargets">0</div></div>
        <div class="stat"><div class="stat-label">回答数</div><div class="stat-value" id="statAnswers">0</div></div>
        <div class="stat"><div class="stat-label">未登録顧客からの回答</div><div class="stat-value" id="statUnregistered">0</div></div>
        <div class="stat"><div class="stat-label">未回答数</div><div class="stat-value" id="statUnanswered">0</div></div>
        <div class="stat"><div class="stat-label">回答率</div><div class="stat-value" id="statRate">0%</div></div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>設問フィルター</h2>
            <div class="btn-group">
                <button class="btn btn-sm" onclick="selectAllQuestions(true)">すべて選択</button>
                <button class="btn btn-sm" onclick="selectAllQuestions(false)">すべて解除</button>
            </div>
        </div>
        <div class="card-body" id="questionFilter"></div>
    </div>

    <div class="card">
        <div class="card-header"><h2>設問別集計</h2></div>
        <div class="card-body" id="questionAnalysis"></div>
    </div>

    <div class="card">
        <div class="card-header"><h2>個別回答一覧</h2></div>
        <div class="table-wrap">
            <table style="min-width:700px">
                <thead>
                <tr>
                    <th>組織名</th>
                    <th>氏名</th>
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
     回答者向けアンケート
========================================================= -->
<section id="page-answer" class="page">
    <div class="page-title">
        <div>
            <h1>アンケート回答</h1>
            <p>回答者向け画面（モック）</p>
        </div>
        <button class="btn" onclick="showPage('list')">管理画面へ</button>
    </div>
    <div class="preview-frame">
        <div class="preview-device pc" id="answerDevice">
            <div id="answerContent" class="preview-inner"></div>
        </div>
    </div>
</section>

<!-- =========================================================
     回答完了
========================================================= -->
<section id="page-complete" class="page">
    <div style="max-width:650px;margin:70px auto">
        <div class="card">
            <div class="card-body" style="text-align:center;padding:55px 25px">
                <div style="font-size:55px">✓</div>
                <h1 style="margin:15px 0">回答ありがとうございました</h1>
                <p class="muted">アンケートの回答を受け付けました。</p>
                <button class="btn btn-primary" onclick="showPage('list')" style="margin-top:20px">
                    管理画面へ戻る
                </button>
            </div>
        </div>
    </div>
</section>

<!-- =========================================================
     kintone連携設定
========================================================= -->
<section id="page-kintone" class="page">
    <div class="page-title">
        <div>
            <h1>kintone連携設定</h1>
            <p>kintoneの顧客情報とのマッピングを設定します。</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>接続設定</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>サブドメイン</label>
                    <input class="form-control" id="kinDomain" value="example">
                </div>
                <div class="form-group">
                    <label>顧客管理アプリID</label>
                    <input class="form-control" id="kinApp" value="123">
                </div>
                <div class="form-group">
                    <label>ログイン情報</label>
                    <input class="form-control" value="mock-admin">
                </div>
                <div class="form-group">
                    <label>SSL検証</label>
                    <select class="form-control"><option>有効</option><option>無効</option></select>
                </div>
            </div>
            <div class="btn-group" style="margin-top:15px">
                <button class="btn btn-primary" onclick="mockKintoneConnect()">接続確認</button>
                <button class="btn" onclick="mockKintoneFields()">項目一覧を再取得</button>
                <button class="btn btn-success" onclick="mockSync()">顧客情報を同期</button>
            </div>
            <div id="kinStatus" style="margin-top:12px"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>フィールドマッピング</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                通常項目はkintoneフィールドを指定します。住所は複数フィールドを組み合わせるため、
                <strong>プルダウンではなくチェックボックス</strong>で複数選択できます。
            </div>

            <div class="kintone-grid">
                <div class="mapping-box">
                    <h4>組織名</h4>
                    <select class="form-control">
                        <option>会社名</option>
                        <option>組織名</option>
                        <option>顧客組織</option>
                    </select>
                </div>
                <div class="mapping-box">
                    <h4>氏名</h4>
                    <select class="form-control">
                        <option>氏名</option>
                        <option>担当者名</option>
                    </select>
                </div>
                <div class="mapping-box">
                    <h4>メールアドレス</h4>
                    <select class="form-control">
                        <option>メールアドレス</option>
                        <option>メール</option>
                    </select>
                </div>
                <div class="mapping-box">
                    <h4>部署名</h4>
                    <select class="form-control">
                        <option>部署名</option>
                        <option>所属部署</option>
                    </select>
                </div>
                <div class="mapping-box">
                    <h4>電話番号</h4>
                    <select class="form-control">
                        <option>電話番号</option>
                        <option>携帯電話</option>
                    </select>
                </div>

                <div class="mapping-box">
                    <h4>住所マッピング（複数選択可）</h4>
                    <div class="checkbox-list">
                        <label class="checkbox-item">
                            <input type="checkbox" class="address-field" value="郵便番号" checked>
                            郵便番号
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" class="address-field" value="都道府県" checked>
                            都道府県
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" class="address-field" value="市区町村" checked>
                            市区町村
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" class="address-field" value="番地">
                            番地
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" class="address-field" value="建物名">
                            建物名
                        </label>
                    </div>
                    <div class="form-help" style="margin-top:10px">
                        選択されたフィールドを結合して住所として扱います。
                    </div>
                </div>
            </div>

            <button class="btn btn-primary" style="margin-top:15px" onclick="saveKintoneMapping()">
                マッピングを保存
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>取得済みkintone項目</h2></div>
        <div class="card-body" id="kinFields">
            <div class="muted">「項目一覧を再取得」を押すと表示されます。</div>
        </div>
    </div>
</section>

<!-- =========================================================
     メールサーバ設定
========================================================= -->
<section id="page-mailserver" class="page">
    <div class="page-title">
        <div>
            <h1>メールサーバ設定</h1>
            <p>アンケート案内・リマインドメールの送信設定です。</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>メールサーバ情報</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>SMTPサーバ</label>
                    <input class="form-control" value="smtp.example.jp">
                </div>
                <div class="form-group">
                    <label>SMTPポート</label>
                    <input class="form-control" value="587">
                </div>
                <div class="form-group">
                    <label>暗号化方式</label>
                    <select class="form-control">
                        <option>TLS</option>
                        <option>SSL</option>
                        <option>なし</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>SMTP認証</label>
                    <select class="form-control">
                        <option>使用する</option>
                        <option>使用しない</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>SMTPユーザー名</label>
                    <input class="form-control" value="survey@example.jp">
                </div>
                <div class="form-group">
                    <label>SMTPパスワード</label>
                    <input class="form-control" type="password" value="password">
                </div>
                <div class="form-group">
                    <label>送信元メールアドレス</label>
                    <input class="form-control" value="survey@example.jp">
                </div>
                <div class="form-group">
                    <label>送信元名</label>
                    <input class="form-control" value="アンケート事務局">
                </div>
                <div class="form-group">
                    <label>返信先メールアドレス</label>
                    <input class="form-control" value="support@example.jp">
                </div>
            </div>

            <div class="btn-group" style="margin-top:15px">
                <button class="btn btn-primary" onclick="testMailConnection()">接続確認</button>
                <button class="btn" onclick="testMail()">テスト送信</button>
                <button class="btn btn-success" onclick="saveMailSettings()">設定を保存</button>
            </div>
            <div id="mailStatus" style="margin-top:12px"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>送信者情報</h2></div>
        <div class="card-body">
            <p><strong>送信元：</strong> アンケート事務局 &lt;survey@example.jp&gt;</p>
            <p><strong>返信先：</strong> support@example.jp</p>
        </div>
    </div>
</section>

</main>
</div>
</div>

<!-- =========================================================
     モーダル
========================================================= -->
<div id="modalBackdrop" class="modal-backdrop">
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
   データ
========================================================= */

let surveys = [
    {
        id: 1,
        title: "2026年度 顧客満足度アンケート",
        description: "サービスについてのご意見・ご感想をお聞かせください。",
        start: "2026-04-01T09:00",
        end: "2026-09-30T18:00",
        status: "公開中",
        numbering: "global",
        created: "2026-03-15",
        updated: "2026-08-20",
        answers: 128,
        groups: [
            {
                id: "g1",
                title: "基本情報",
                questions: [
                    {id:"q1",text:"当社サービスを利用したことがありますか？",type:"single",required:true,
                     options:["はい","いいえ"],branch:{"はい":"next","いいえ":"q5"}},
                    {id:"q2",text:"サービスの満足度を教えてください。",type:"single",required:true,
                     options:["非常に満足","満足","普通","不満","非常に不満"],branch:{}}
                ]
            },
            {
                id: "g2",
                title: "サービスについて",
                questions: [
                    {id:"q3",text:"良かった点を教えてください。",type:"multi",required:false,
                     options:["価格","品質","サポート","使いやすさ","その他"],branch:{}},
                    {id:"q4",text:"改善してほしい点を教えてください。",type:"textarea",required:false,
                     options:[],branch:{}}
                ]
            },
            {
                id: "g3",
                title: "今後について",
                questions: [
                    {id:"q5",text:"今後もサービスを利用したいですか？",type:"single",required:true,
                     options:["ぜひ利用したい","利用したい","わからない","利用したくない"],branch:{}}
                ]
            }
        ]
    },
    {
        id: 2,
        title: "新サービス利用意向調査",
        description: "新サービスに関するアンケートです。",
        start: "2026-08-01T09:00",
        end: "2026-08-31T18:00",
        status: "公開中",
        numbering: "group",
        created: "2026-07-10",
        updated: "2026-08-22",
        answers: 74,
        groups: [
            {
                id:"g10",
                title:"サービス利用意向",
                questions:[
                    {id:"q10",text:"新サービスに興味がありますか？",type:"single",required:true,
                     options:["非常に興味がある","興味がある","あまりない","ない"],branch:{}},
                    {id:"q11",text:"ご意見があればご記入ください。",type:"textarea",required:false,options:[],branch:{}}
                ]
            },
            {
                id:"g11",
                title:"属性",
                questions:[
                    {id:"q12",text:"所属する組織の業種を教えてください。",type:"single",required:true,
                     options:["製造業","IT","サービス業","その他"],branch:{}}
                ]
            }
        ]
    },
    {
        id: 3,
        title: "イベント参加者アンケート",
        description: "イベント終了後のご感想をお聞かせください。",
        start: "2026-07-01T10:00",
        end: "2026-07-31T18:00",
        status: "終了",
        numbering: "global",
        created: "2026-06-10",
        updated: "2026-08-01",
        answers: 52,
        groups: [
            {
                id:"g20",
                title:"イベント評価",
                questions:[
                    {id:"q20",text:"イベント全体の満足度を教えてください。",type:"single",required:true,
                     options:["5","4","3","2","1"],branch:{}},
                    {id:"q21",text:"自由にご意見をお聞かせください。",type:"textarea",required:false,options:[],branch:{}}
                ]
            }
        ]
    }
];

let customers = [
    {id:1,org:"株式会社サンプル商事",name:"山田 太郎",email:"yamada@example.jp",phone:"03-1234-5678",
     address:"東京都港区赤坂1-1-1",sent: "2026-08-20 10:00",count:1,status:"回答済み",kintone:true},
    {id:2,org:"ABC株式会社",name:"佐藤 花子",email:"sato@example.jp",phone:"03-2345-6789",
     address:"東京都千代田区丸の内2-2-2",sent:"2026-08-21 14:20",count:1,status:"送信済み / 未回答",kintone:true},
    {id:3,org:"東京テクノロジー",name:"鈴木 一郎",email:"suzuki@example.jp",phone:"03-3456-7890",
     address:"東京都新宿区西新宿3-3-3",sent:"",count:0,status:"未送信",kintone:true},
    {id:4,org:"未登録企業",name:"高橋 次郎",email:"takahashi@example.jp",phone:"090-1111-2222",
     address:"東京都渋谷区渋谷4-4-4",sent:"2026-08-22 09:15",count:1,status:"未登録",kintone:false},
    {id:5,org:"未来産業株式会社",name:"田中 美咲",email:"tanaka@example.jp",phone:"03-4567-8901",
     address:"東京都港区六本木5-5-5",sent:"",count:0,status:"未送信",kintone:true},
    {id:6,org:"グローバル株式会社",name:"伊藤 健",email:"ito@example.jp",phone:"03-5678-9012",
     address:"東京都中央区銀座6-6-6",sent:"2026-08-18 16:00",count:2,status:"回答済み",kintone:true}
];

let sendHistories = [
    {
        id:1,date:"2026-08-22 09:15",type:"一括送信",count:3,
        subject:"2026年度 顧客満足度アンケートのお願い",
        surveyId:1,
        user:"管理者",
        customers:[1,2,4]
    },
    {
        id:2,date:"2026-08-20 10:00",type:"リマインド",count:1,
        subject:"【再送】アンケートご回答のお願い",
        surveyId:1,
        user:"管理者",
        customers:[1]
    }
];

let answers = [
    {
        id:1001,customerId:1,surveyId:1,date:"2026-08-21 13:10",
        values:{q1:"はい",q2:"満足",q3:["品質","サポート"],q4:"問い合わせ対応が丁寧でした。",q5:"ぜひ利用したい"}
    },
    {
        id:1002,customerId:4,surveyId:1,date:"2026-08-22 11:20",
        values:{q1:"いいえ",q5:"わからない"}
    }
];

let currentSurveyId = null;
let editorSnapshot = null;
let previewDevice = "pc";
let answerSurveyId = null;
let answerStep = 0;
let answerValues = {};
let selectedCustomerIds = new Set();
let questionFilter = new Set();

/* =========================================================
   共通
========================================================= */

function esc(str){
    return String(str ?? "")
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;")
        .replace(/'/g,"&#039;");
}

function clone(obj){
    return JSON.parse(JSON.stringify(obj));
}

function uid(prefix){
    return prefix + Date.now() + Math.floor(Math.random()*1000);
}

function nowString(){
    const d = new Date();
    const pad = n => String(n).padStart(2,"0");
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function showToast(message){
    const el=document.getElementById("toast");
    el.textContent=message;
    el.classList.add("show");
    clearTimeout(window.__toast);
    window.__toast=setTimeout(()=>el.classList.remove("show"),2500);
}

function showPage(page){
    document.querySelectorAll(".page").forEach(x=>x.classList.remove("active"));
    const target=document.getElementById("page-"+page);
    if(target) target.classList.add("active");

    document.querySelectorAll(".nav-btn").forEach(x=>x.classList.remove("active"));
    const nav=document.querySelector(`[data-page="${page}"]`);
    if(nav) nav.classList.add("active");

    window.scrollTo({top:0,behavior:"smooth"});

    if(page==="list") renderSurveyList();
    if(page==="kintone"){}
    if(page==="send") {
        renderCustomers();
        renderSendHistoryMini();
    }
    if(page==="history") renderHistory();
}

function openModal(title,body,buttons){
    document.getElementById("modalTitle").textContent=title;
    document.getElementById("modalBody").innerHTML=body;
    const footer=document.getElementById("modalFooter");
    footer.innerHTML="";
    buttons.forEach(b=>{
        const btn=document.createElement("button");
        btn.className="btn "+(b.className||"");
        btn.textContent=b.text;
        btn.onclick=()=>{
            if(b.action) b.action();
            if(b.close!==false) closeModal();
        };
        footer.appendChild(btn);
    });
    document.getElementById("modalBackdrop").classList.add("show");
}

function closeModal(){
    document.getElementById("modalBackdrop").classList.remove("show");
}

function confirmAction(title,message,action,actionText="実行",danger=false){
    openModal(title,
        `<div class="confirm-text">${message}</div>`,
        [
            {text:"キャンセル"},
            {text:actionText,className:danger?"btn-danger":"btn-primary",action}
        ]
    );
}

function getSurvey(id){
    return surveys.find(s=>s.id===Number(id));
}

function statusBadge(status){
    const map={
        "公開中":"badge-published",
        "下書き":"badge-draft",
        "停止":"badge-stopped",
        "終了":"badge-ended"
    };
    return `<span class="badge ${map[status]||"badge-gray"}">${esc(status)}</span>`;
}

function flattenQuestions(survey){
    const arr=[];
    survey.groups.forEach((g,gi)=>{
        g.questions.forEach((q,qi)=>{
            arr.push({...q,groupIndex:gi,questionIndex:qi,groupId:g.id});
        });
    });
    return arr;
}

function renumberSurvey(survey){
    let n=1;
    survey.groups.forEach((g,gi)=>{
        g.questions.forEach((q,qi)=>{
            if(survey.numbering==="global"){
                q.number=`Q${n}`;
                n++;
            }else{
                q.number=`Q${gi+1}-${qi+1}`;
            }
        });
    });
}

/* =========================================================
   一覧
========================================================= */

function renderSurveyList(){
    const search=(document.getElementById("surveySearch")?.value||"").toLowerCase();
    const status=document.getElementById("statusFilter")?.value||"";
    const sort=document.getElementById("sortFilter")?.value||"updatedDesc";

    let list=surveys.filter(s=>{
        return (!search || s.title.toLowerCase().includes(search))
            && (!status || s.status===status);
    });

    list.sort((a,b)=>{
        if(sort==="updatedDesc") return b.updated.localeCompare(a.updated);
        if(sort==="updatedAsc") return a.updated.localeCompare(b.updated);
        if(sort==="answersDesc") return b.answers-a.answers;
        if(sort==="answersAsc") return a.answers-b.answers;
        if(sort==="startDesc") return b.start.localeCompare(a.start);
        if(sort==="startAsc") return a.start.localeCompare(b.start);
        return 0;
    });

    const tbody=document.getElementById("surveyTable");
    if(!list.length){
        tbody.innerHTML=`<tr><td colspan="6"><div class="empty">該当するアンケートがありません。</div></td></tr>`;
        return;
    }

    tbody.innerHTML=list.map(s=>`
        <tr>
            <td>${esc(s.created)}<br><span class="muted">更新 ${esc(s.updated)}</span></td>
            <td><strong>${esc(s.title)}</strong><br><span class="small muted">${esc(s.description)}</span></td>
            <td>${esc(s.start.replace("T"," "))}<br>～ ${esc(s.end.replace("T"," "))}</td>
            <td>${statusBadge(s.status)}</td>
            <td><strong>${s.answers}</strong> 件</td>
            <td>
                <div class="btn-group">
                    <button class="btn btn-sm" onclick="editSurvey(${s.id})">確認・編集</button>
                    <button class="btn btn-sm" onclick="openAnalysis(${s.id})">集計</button>
                    <button class="btn btn-sm" onclick="openSend(${s.id})">送信</button>
                    <button class="btn btn-sm" onclick="duplicateSurvey(${s.id})">複製</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteSurvey(${s.id})">削除</button>
                </div>
            </td>
        </tr>
    `).join("");
}

/* =========================================================
   新規・編集
========================================================= */

function newSurvey(){
    const s={
        id:Date.now(),
        title:"",
        description:"",
        start:"",
        end:"",
        status:"下書き",
        numbering:"global",
        created:new Date().toISOString().slice(0,10),
        updated:new Date().toISOString().slice(0,10),
        answers:0,
        groups:[
            {
                id:uid("g"),
                title:"グループ1",
                questions:[
                    {
                        id:uid("q"),
                        text:"",
                        type:"single",
                        required:false,
                        options:["選択肢1","選択肢2"],
                        branch:{}
                    }
                ]
            }
        ]
    };
    surveys.unshift(s);
    currentSurveyId=s.id;
    openEditor(s.id,true);
}

function editSurvey(id){
    openEditor(id,false);
}

function openEditor(id,isNew=false){
    const s=getSurvey(id);
    if(!s)return;
    currentSurveyId=id;
    renumberSurvey(s);

    editorSnapshot=clone(s);

    document.getElementById("editorHeading").textContent=isNew?"アンケート作成":"アンケート確認・編集";
    document.getElementById("surveyTitle").value=s.title;
    document.getElementById("surveyDescription").value=s.description;
    document.getElementById("surveyStart").value=s.start;
    document.getElementById("surveyEnd").value=s.end;

    document.querySelectorAll('input[name="numbering"]').forEach(r=>{
        r.checked=r.value===s.numbering;
    });

    renderEditor();
    showPage("editor");
}

function updateSurveyField(field,value){
    const s=getSurvey(currentSurveyId);
    if(!s)return;
    s[field]=value;
}

function renderEditor(){
    const s=getSurvey(currentSurveyId);
    if(!s)return;
    renumberSurvey(s);

    document.getElementById("editorStatus").innerHTML=statusBadge(s.status);
    document.getElementById("editorId").textContent=`ID: ${s.id}`;

    let actions="";
    if(s.status==="下書き"){
        actions+=`<button class="btn btn-primary" onclick="saveDraft()">下書き保存</button>`;
        actions+=`<button class="btn btn-success" onclick="changeStatus('publish')">公開</button>`;
    }else if(s.status==="公開中"){
        actions+=`<button class="btn btn-warning" onclick="changeStatus('stop')">停止</button>`;
    }else if(s.status==="停止"){
        actions+=`<button class="btn btn-success" onclick="changeStatus('resume')">再開</button>`;
    }
    actions+=`<button class="btn" onclick="openPreview()">プレビュー</button>`;
    document.getElementById("editorActions").innerHTML=actions;

    document.getElementById("groupsContainer").innerHTML=s.groups.map((g,gi)=>renderGroup(g,gi)).join("");

    enableDragAndDrop();
}

function renderGroup(g,gi){
    return `
    <div class="group-card" draggable="true" data-group-id="${g.id}" data-group-index="${gi}">
        <div class="group-header">
            <span class="drag-handle" title="ドラッグしてグループを並び替え">☷</span>
            <input class="group-title-input" value="${esc(g.title)}"
                   onchange="updateGroupTitle('${g.id}',this.value)">
            <span class="small muted">グループ ${gi+1}</span>
            <button class="btn btn-sm" onclick="moveGroupUp('${g.id}')">↑</button>
            <button class="btn btn-sm" onclick="moveGroupDown('${g.id}')">↓</button>
            <button class="btn btn-sm btn-danger" onclick="deleteGroup('${g.id}')">削除</button>
        </div>
        <div class="question-list" data-group-id="${g.id}">
            ${g.questions.map((q,qi)=>renderQuestion(q,g,gi,qi)).join("")}
            <button class="add-question" onclick="addQuestion('${g.id}')">＋ 質問を追加</button>
        </div>
    </div>`;
}

function renderQuestion(q,g,gi,qi){
    let typeLabel={
        single:"単一選択",
        multi:"複数選択",
        text:"1行テキスト",
        textarea:"複数行テキスト"
    }[q.type]||q.type;

    return `
    <div class="question-card" draggable="true"
         data-question-id="${q.id}" data-group-id="${g.id}">
        <div class="question-header">
            <span class="drag-handle">☷</span>
            <span class="question-number">${esc(q.number||"")}</span>
            <span class="question-preview">${esc(q.text||"（質問文未入力）")}</span>
            ${q.required?'<span class="badge badge-red">必須</span>':'<span class="badge badge-gray">任意</span>'}
            <span class="badge badge-blue">${typeLabel}</span>
            <div class="question-actions">
                <button class="btn btn-sm" onclick="toggleQuestionBody('${q.id}')">編集</button>
                <button class="btn btn-sm btn-danger" onclick="deleteQuestion('${g.id}','${q.id}')">削除</button>
            </div>
        </div>
        <div class="question-body" id="question-body-${q.id}">
            <div class="form-grid">
                <div class="form-group full">
                    <label>質問文</label>
                    <input class="form-control" value="${esc(q.text)}"
                           onchange="updateQuestion('${g.id}','${q.id}','text',this.value)">
                </div>
                <div class="form-group">
                    <label>回答形式</label>
                    <select class="form-control"
                            onchange="updateQuestion('${g.id}','${q.id}','type',this.value)">
                        <option value="single" ${q.type==="single"?"selected":""}>単一選択（ラジオボタン）</option>
                        <option value="multi" ${q.type==="multi"?"selected":""}>複数選択（チェックボックス）</option>
                        <option value="text" ${q.type==="text"?"selected":""}>1行テキスト</option>
                        <option value="textarea" ${q.type==="textarea"?"selected":""}>複数行テキスト</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>必須設定</label>
                    <label class="checkbox-item" style="height:38px">
                        <input type="checkbox" ${q.required?"checked":""}
                               onchange="updateQuestion('${g.id}','${q.id}','required',this.checked)">
                        必須回答にする
                    </label>
                </div>
            </div>

            ${q.type==="single"||q.type==="multi"?`
                <div style="margin-top:14px">
                    <label style="font-size:12px;font-weight:700;color:#475569">選択肢</label>
                    <div style="margin-top:8px">
                        ${(q.options||[]).map((op,oi)=>`
                            <div class="option-row">
                                <input class="form-control" value="${esc(op)}"
                                       onchange="updateOption('${g.id}','${q.id}',${oi},this.value)">
                                <button class="btn btn-sm btn-danger"
                                        onclick="deleteOption('${g.id}','${q.id}',${oi})">削除</button>
                            </div>
                        `).join("")}
                        <button class="btn btn-sm" onclick="addOption('${g.id}','${q.id}')">＋ 選択肢追加</button>
                    </div>
                </div>
            `:""}

            ${q.type==="single"?`
                <div style="margin-top:15px;padding-top:15px;border-top:1px solid var(--border)">
                    <label style="font-size:12px;font-weight:700;color:#475569">
                        条件分岐設定
                    </label>
                    <div class="form-help" style="margin:5px 0 9px">
                        選択肢ごとに次に表示する質問を設定できます。
                    </div>
                    ${(q.options||[]).map((op,oi)=>{
                        const current=q.branch?.[op]||"next";
                        return `
                        <div class="option-row">
                            <span style="width:160px;font-size:12px">${esc(op)}</span>
                            <select class="form-control"
                                    onchange="setBranch('${g.id}','${q.id}',${oi},this.value)">
                                ${branchOptions(getSurvey(currentSurveyId),q.id,current)}
                            </select>
                        </div>`;
                    }).join("")}
                </div>
            `:""}
        </div>
    </div>`;
}

function branchOptions(survey,currentQuestionId,current){
    const qs=flattenQuestions(survey);
    return `<option value="next" ${current==="next"?"selected":""}>次の質問へ</option>
            <option value="end" ${current==="end"?"selected":""}>回答終了</option>
            ${qs.filter(q=>q.id!==currentQuestionId).map(q=>
                `<option value="${q.id}" ${current===q.id?"selected":""}>${esc(q.number)} ${esc(q.text||"（未入力）")}</option>`
            ).join("")}`;
}

function toggleQuestionBody(id){
    const el=document.getElementById("question-body-"+id);
    if(el)el.style.display=el.style.display==="none"?"block":"none";
}

function updateGroupTitle(groupId,value){
    const s=getSurvey(currentSurveyId);
    const g=s.groups.find(x=>x.id===groupId);
    if(g)g.title=value;
    renumberSurvey(s);
}

function addGroup(){
    const s=getSurvey(currentSurveyId);
    s.groups.push({
        id:uid("g"),
        title:`グループ${s.groups.length+1}`,
        questions:[]
    });
    renumberSurvey(s);
    renderEditor();
    showToast("グループを追加しました");
}

function deleteGroup(groupId){
    const s=getSurvey(currentSurveyId);
    const g=s.groups.find(x=>x.id===groupId);
    if(!g)return;

    confirmAction(
        "グループ削除",
        g.questions.length
            ? `「${esc(g.title)}」には ${g.questions.length} 件の質問があります。<br>グループと質問を削除しますか？`
            : `「${esc(g.title)}」を削除しますか？`,
        ()=>{
            s.groups=s.groups.filter(x=>x.id!==groupId);
            if(!s.groups.length){
                s.groups.push({id:uid("g"),title:"グループ1",questions:[]});
            }
            renumberSurvey(s);
            renderEditor();
            showToast("グループを削除しました");
        },
        "削除",
        true
    );
}

function moveGroupUp(id){
    const s=getSurvey(currentSurveyId);
    const i=s.groups.findIndex(g=>g.id===id);
    if(i>0){
        [s.groups[i-1],s.groups[i]]=[s.groups[i],s.groups[i-1]];
        renumberSurvey(s);renderEditor();
    }
}

function moveGroupDown(id){
    const s=getSurvey(currentSurveyId);
    const i=s.groups.findIndex(g=>g.id===id);
    if(i<s.groups.length-1){
        [s.groups[i],s.groups[i+1]]=[s.groups[i+1],s.groups[i]];
        renumberSurvey(s);renderEditor();
    }
}

function addQuestion(groupId){
    const s=getSurvey(currentSurveyId);
    const g=s.groups.find(x=>x.id===groupId);
    if(!g)return;

    g.questions.push({
        id:uid("q"),
        text:"",
        type:"single",
        required:false,
        options:["選択肢1","選択肢2"],
        branch:{}
    });
    renumberSurvey(s);
    renderEditor();
    showToast("質問を追加しました");
}

function findQuestion(groupId,qid){
    const s=getSurvey(currentSurveyId);
    const g=s.groups.find(x=>x.id===groupId);
    return g?.questions.find(q=>q.id===qid);
}

function updateQuestion(groupId,qid,field,value){
    const q=findQuestion(groupId,qid);
    if(!q)return;
    q[field]=value;
    if(field==="type"){
        if(value==="single"||value==="multi"){
            if(!q.options?.length)q.options=["選択肢1","選択肢2"];
        }else{
            q.options=[];
        }
    }
    renderEditor();
}

function updateOption(groupId,qid,index,value){
    const q=findQuestion(groupId,qid);
    if(q)q.options[index]=value;
    renderEditor();
}

function addOption(groupId,qid){
    const q=findQuestion(groupId,qid);
    if(q){
        q.options.push(`選択肢${q.options.length+1}`);
        renderEditor();
    }
}

function deleteOption(groupId,qid,index){
    const q=findQuestion(groupId,qid);
    if(!q)return;
    if(q.options.length<=1){
        showToast("選択肢は1つ以上必要です");
        return;
    }
    q.options.splice(index,1);
    renderEditor();
}

function setBranch(groupId,qid,index,value){
    const q=findQuestion(groupId,qid);
    if(!q)return;
    const option=q.options[index];
    q.branch=q.branch||{};
    q.branch[option]=value;
    showToast(`${option} の分岐先を更新しました`);
}

function deleteQuestion(groupId,qid){
    const q=findQuestion(groupId,qid);
    confirmAction(
        "質問削除",
        `「${esc(q?.text||"質問") }」を削除しますか？`,
        ()=>{
            const s=getSurvey(currentSurveyId);
            const g=s.groups.find(x=>x.id===groupId);
            g.questions=g.questions.filter(x=>x.id!==qid);
            renumberSurvey(s);
            renderEditor();
            showToast("質問を削除しました");
        },
        "削除",
        true
    );
}

function changeNumbering(mode){
    const s=getSurvey(currentSurveyId);
    if(!s)return;
    s.numbering=mode;
    renumberSurvey(s);
    renderEditor();
    showToast(mode==="global"?"アンケート全体で通番に変更しました":"グループ毎の採番に変更しました");
}

/* =========================================================
   ドラッグ＆ドロップ
========================================================= */

let dragData=null;

function enableDragAndDrop(){
    document.querySelectorAll(".group-card").forEach(el=>{
        el.addEventListener("dragstart",e=>{
            dragData={kind:"group",id:el.dataset.groupId};
            el.style.opacity=".45";
        });
        el.addEventListener("dragend",()=>{
            el.style.opacity="";
            document.querySelectorAll(".drag-over").forEach(x=>x.classList.remove("drag-over"));
        });
        el.addEventListener("dragover",e=>{
            if(dragData?.kind==="group"){
                e.preventDefault();
                el.classList.add("drag-over");
            }
        });
        el.addEventListener("dragleave",()=>el.classList.remove("drag-over"));
        el.addEventListener("drop",e=>{
            e.preventDefault();
            el.classList.remove("drag-over");
            if(!dragData || dragData.id===el.dataset.groupId)return;
            const s=getSurvey(currentSurveyId);
            const from=s.groups.findIndex(g=>g.id===dragData.id);
            const to=s.groups.findIndex(g=>g.id===el.dataset.groupId);
            if(from<0||to<0)return;
            const [item]=s.groups.splice(from,1);
            s.groups.splice(to,0,item);
            renumberSurvey(s);
            renderEditor();
            showToast("グループを並び替えました");
        });
    });

    document.querySelectorAll(".question-card").forEach(el=>{
        el.addEventListener("dragstart",e=>{
            e.stopPropagation();
            dragData={
                kind:"question",
                id:el.dataset.questionId,
                fromGroup:el.dataset.groupId
            };
            el.classList.add("dragging");
        });
        el.addEventListener("dragend",()=>{
            el.classList.remove("dragging");
            document.querySelectorAll(".drag-over").forEach(x=>x.classList.remove("drag-over"));
        });
        el.addEventListener("dragover",e=>{
            e.preventDefault();
            e.stopPropagation();
            if(dragData?.kind==="question" && dragData.id!==el.dataset.questionId)
                el.classList.add("drag-over");
        });
        el.addEventListener("dragleave",()=>el.classList.remove("drag-over"));
        el.addEventListener("drop",e=>{
            e.preventDefault();
            e.stopPropagation();
            el.classList.remove("drag-over");
            if(!dragData||dragData.kind!=="question"||dragData.id===el.dataset.questionId)return;
            moveQuestion(dragData.id,dragData.fromGroup,el.dataset.groupId,el.dataset.questionId);
        });
    });

    document.querySelectorAll(".question-list").forEach(el=>{
        el.addEventListener("dragover",e=>{
            if(dragData?.kind==="question")e.preventDefault();
        });
        el.addEventListener("drop",e=>{
            e.preventDefault();
            e.stopPropagation();
            if(!dragData||dragData.kind!=="question")return;
            if(e.target.closest(".question-card"))return;
            moveQuestion(dragData.id,dragData.fromGroup,el.dataset.groupId,null);
        });
    });
}

function moveQuestion(qid,fromGroupId,toGroupId,beforeQid){
    const s=getSurvey(currentSurveyId);
    const from=s.groups.find(g=>g.id===fromGroupId);
    const to=s.groups.find(g=>g.id===toGroupId);
    if(!from||!to)return;

    const index=from.questions.findIndex(q=>q.id===qid);
    if(index<0)return;

    const [q]=from.questions.splice(index,1);

    if(beforeQid){
        let idx=to.questions.findIndex(x=>x.id===beforeQid);
        if(idx<0)idx=to.questions.length;
        to.questions.splice(idx,0,q);
    }else{
        to.questions.push(q);
    }

    renumberSurvey(s);
    renderEditor();
    showToast("質問を移動・並び替えしました");
}

/* =========================================================
   保存・状態変更
========================================================= */

function saveDraft(){
    const s=getSurvey(currentSurveyId);
    if(!s)return;
    s.status="下書き";
    s.updated=new Date().toISOString().slice(0,10);
    renumberSurvey(s);
    renderEditor();
    showToast("下書きを保存しました");
}

function changeStatus(action){
    const s=getSurvey(currentSurveyId);
    if(!s)return;

    let target="",message="";
    if(action==="publish"){
        target="公開中";
        message="このアンケートを公開しますか？";
    }else if(action==="stop"){
        target="停止";
        message="このアンケートを停止しますか？";
    }else if(action==="resume"){
        target="公開中";
        message="このアンケートを再開しますか？";
    }else return;

    confirmAction("ステータス変更",message,()=>{
        s.status=target;
        s.updated=new Date().toISOString().slice(0,10);
        renderEditor();
        showToast(`アンケートを「${target}」に変更しました`);
    },"実行");
}

function saveAndBack(){
    const s=getSurvey(currentSurveyId);
    s.updated=new Date().toISOString().slice(0,10);
    renumberSurvey(s);
    showToast("保存しました");
    setTimeout(()=>showPage("list"),400);
}

function cancelEditor(){
    confirmAction(
        "変更を破棄",
        "作成・編集内容を破棄して一覧へ戻りますか？",
        ()=>{
            const idx=surveys.findIndex(s=>s.id===currentSurveyId);
            if(idx>=0 && editorSnapshot){
                surveys[idx]=clone(editorSnapshot);
            }
            showPage("list");
        },
        "破棄して戻る",
        true
    );
}

function duplicateSurvey(id){
    const s=getSurvey(id);
    if(!s)return;

    confirmAction(
        "アンケート複製",
        `「${esc(s.title)}」を複製して下書きとして追加しますか？<br>
         回答データ・送信履歴・公開状態は複製されません。`,
        ()=>{
            const copy=clone(s);
            copy.id=Date.now();
            copy.title=s.title+"（複製）";
            copy.status="下書き";
            copy.created=new Date().toISOString().slice(0,10);
            copy.updated=copy.created;
            copy.answers=0;
            copy.groups.forEach(g=>{
                g.id=uid("g");
                g.questions.forEach(q=>q.id=uid("q"));
            });
            surveys.unshift(copy);
            renderSurveyList();
            showToast("アンケートを複製しました");
        },
        "複製する"
    );
}

function deleteSurvey(id){
    const s=getSurvey(id);
    if(!s)return;

    if(s.status==="公開中"){
        openModal(
            "アンケート削除",
            `<div class="confirm-text">
                公開中のアンケートです。<br>
                モックでは削除操作を確認できますが、本番システムでは
                削除可否を状態に応じて制御してください。
            </div>`,
            [
                {text:"キャンセル"},
                {text:"削除する",className:"btn-danger",action:()=>{
                    surveys=surveys.filter(x=>x.id!==id);
                    renderSurveyList();
                    showToast("アンケートを削除しました");
                }}
            ]
        );
    }else{
        confirmAction(
            "アンケート削除",
            `「${esc(s.title)}」を削除しますか？`,
            ()=>{
                surveys=surveys.filter(x=>x.id!==id);
                renderSurveyList();
                showToast("アンケートを削除しました");
            },
            "削除",
            true
        );
    }
}

/* =========================================================
   プレビュー
========================================================= */

function openPreview(){
    const s=getSurvey(currentSurveyId);
    if(!s)return;
    renumberSurvey(s);
    renderPreview();
    showPage("preview");
}

function setPreviewDevice(device){
    previewDevice=device;
    const el=document.getElementById("previewDevice");
    el.className="preview-device "+device;
    renderPreview();
}

function renderPreview(){
    const s=getSurvey(currentSurveyId);
    if(!s)return;
    renumberSurvey(s);

    let html=`
        <div>
            <span class="badge badge-blue">プレビュー</span>
            <h1 style="font-size:24px;margin:12px 0 7px">${esc(s.title||"アンケートタイトル")}</h1>
            <p class="muted" style="font-size:13px">${esc(s.description)}</p>
            <div class="alert alert-info">これはプレビュー表示のため送信されません。</div>
        </div>
    `;

    s.groups.forEach(g=>{
        html+=`<div style="margin-top:25px">
            <h2 style="font-size:17px;border-left:4px solid var(--primary);padding-left:9px">
                ${esc(g.title)}
            </h2>`;
        g.questions.forEach(q=>{
            html+=renderPreviewQuestion(q);
        });
        html+="</div>";
    });

    html+=`
        <div class="preview-actions">
            <button class="btn">戻る</button>
            <button class="btn btn-primary" onclick="showToast('これはプレビュー表示のため送信されません')">
                回答を送信する
            </button>
        </div>
    `;

    document.getElementById("previewContent").innerHTML=html;
}

function renderPreviewQuestion(q){
    let input="";
    if(q.type==="single"){
        input=(q.options||[]).map(o=>`
            <label class="preview-option">
                <input type="radio" name="pv-${q.id}">
                <span>${esc(o)}</span>
            </label>`).join("");
    }else if(q.type==="multi"){
        input=(q.options||[]).map(o=>`
            <label class="preview-option">
                <input type="checkbox">
                <span>${esc(o)}</span>
            </label>`).join("");
    }else if(q.type==="text"){
        input=`<input class="form-control" placeholder="回答を入力してください">`;
    }else{
        input=`<textarea class="form-control" placeholder="回答を入力してください"></textarea>`;
    }

    return `
        <div class="preview-q">
            <div class="preview-q-title">
                ${esc(q.number)}　${esc(q.text||"質問文未入力")}
                ${q.required?'<span class="required">必須</span>':''}
            </div>
            ${input}
        </div>`;
}

/* =========================================================
   顧客・メール送信
========================================================= */

function openSend(id){
    currentSurveyId=id;
    selectedCustomerIds=new Set();
    const s=getSurvey(id);
    document.getElementById("sendSurveyTitle").textContent=s?.title||"";
    showPage("send");
}

function renderCustomers(){
    const search=(document.getElementById("customerSearch")?.value||"").toLowerCase();
    const status=document.getElementById("customerStatus")?.value||"";

    const list=customers.filter(c=>{
        const text=`${c.org} ${c.name} ${c.email}`.toLowerCase();
        return (!search||text.includes(search))&&(!status||c.status===status);
    });

    document.getElementById("customerTable").innerHTML=list.map(c=>`
        <tr class="${!c.kintone?"customer-row-unregistered":""}">
            <td>
                <input type="checkbox" class="customer-check"
                       data-id="${c.id}" ${selectedCustomerIds.has(c.id)?"checked":""}
                       onchange="toggleCustomer(${c.id},this.checked)">
            </td>
            <td><strong>${esc(c.org)}</strong></td>
            <td>${esc(c.name)}</td>
            <td>${esc(c.email)}</td>
            <td>${esc(c.phone)}</td>
            <td>${esc(c.address)}</td>
            <td>${esc(c.sent||"－")}</td>
            <td>${c.count}</td>
            <td>
                <span class="badge ${
                    c.status==="回答済み"?"badge-green":
                    c.status==="未登録"?"badge-red":
                    c.status==="未送信"?"badge-gray":"badge-blue"
                }">${esc(c.status)}</span>
            </td>
            <td>
                <button class="btn btn-sm" onclick="viewCustomerMail(${c.id})">送信文を確認</button>
            </td>
            <td>
                ${c.kintone
                    ? '<span class="badge badge-green">✓ 登録済み</span>'
                    : '<span class="badge badge-red">未登録</span>'}
            </td>
        </tr>
    `).join("")||`<tr><td colspan="11"><div class="empty">該当する顧客がありません。</div></td></tr>`;

    document.getElementById("customerAll").checked=
        list.length>0&&list.every(c=>selectedCustomerIds.has(c.id));
}

function toggleCustomer(id,checked){
    if(checked)selectedCustomerIds.add(id);
    else selectedCustomerIds.delete(id);
}

function selectAllCustomers(){
    customers.forEach(c=>selectedCustomerIds.add(c.id));
    renderCustomers();
}

function clearCustomers(){
    selectedCustomerIds.clear();
    renderCustomers();
}

function toggleVisibleCustomers(checked){
    document.querySelectorAll(".customer-check").forEach(el=>{
        const id=Number(el.dataset.id);
        if(checked)selectedCustomerIds.add(id);
        else selectedCustomerIds.delete(id);
    });
    renderCustomers();
}

function buildMail(customer){
    const s=getSurvey(currentSurveyId);
    const url=`https://example.jp/survey/${s.id}/customer/${customer.id}`;
    const subject=document.getElementById("mailSubject").value
        .replaceAll("{顧客名}",customer.name)
        .replaceAll("{アンケートURL}",url);
    const body=document.getElementById("mailBody").value
        .replaceAll("{顧客名}",customer.name)
        .replaceAll("{アンケートURL}",url);
    return {subject,body,url};
}

function sendSelected(){
    if(selectedCustomerIds.size===0){
        showToast("送信対象を選択してください");
        return;
    }

    const selected=customers.filter(c=>selectedCustomerIds.has(c.id));
    const already=selected.filter(c=>c.count>0);

    const message=already.length
        ? `選択した ${selected.length} 件のうち、${already.length} 件は既に送信済みです。<br>
           既に送信済みの宛先が含まれています。再送しますか？`
        : `${selected.length} 件の顧客へメールを一括送信しますか？`;

    confirmAction(
        "メール一括送信",
        message,
        ()=>{
            executeSend(selected,false);
        },
        "送信する"
    );
}

function executeSend(list,isReminder){
    const s=getSurvey(currentSurveyId);
    const time=nowString();

    list.forEach(c=>{
        c.sent=time;
        c.count++;
        if(c.status!=="回答済み")c.status="送信済み / 未回答";
    });

    sendHistories.unshift({
        id:Date.now(),
        date:time,
        type:isReminder?"リマインド":"一括送信",
        count:list.length,
        subject:document.getElementById("mailSubject").value,
        surveyId:s.id,
        user:"管理者",
        customers:list.map(c=>c.id)
    });

    renderCustomers();
    renderSendHistoryMini();
    selectedCustomerIds.clear();

    showToast(`${list.length} 件のメール送信に成功しました（モック）`);
}

function sendReminder(){
    const list=customers.filter(c=>c.status==="送信済み / 未回答");
    if(!list.length){
        showToast("リマインド対象の未回答者はいません");
        return;
    }

    confirmAction(
        "リマインド送信",
        `未回答者 ${list.length} 件へリマインドを送信しますか？`,
        ()=>{
            document.getElementById("mailSubject").value="【再送】アンケートご回答のお願い";
            executeSend(list,true);
        },
        "リマインド送信"
    );
}

function viewCustomerMail(id){
    const c=customers.find(x=>x.id===id);
    const mail=buildMail(c);

    openModal(
        "送信文を確認",
        `
        <div class="form-group">
            <label>件名</label>
            <div class="mapping-box">${esc(mail.subject)}</div>
        </div>
        <div class="form-group" style="margin-top:12px">
            <label>本文</label>
            <div class="mapping-box" style="white-space:pre-wrap">${esc(mail.body)}</div>
        </div>
        <div class="form-group" style="margin-top:12px">
            <label>個別アンケートURL</label>
            <div class="mapping-box">${esc(mail.url)}</div>
        </div>
        `,
        [{text:"閉じる"}]
    );
}

function renderSendHistoryMini(){
    const list=sendHistories.filter(h=>h.surveyId===currentSurveyId).slice(0,5);
    document.getElementById("sendHistoryMini").innerHTML=list.length
        ?list.map(h=>`
            <div class="history-row">
                <span><strong>${esc(h.type)}</strong>　${esc(h.date)}</span>
                <span>${h.count}件　${esc(h.subject)}</span>
            </div>
        `).join("")
        :'<div class="muted">送信履歴はありません。</div>';
}

function renderHistory(){
    const list=sendHistories.filter(h=>h.surveyId===currentSurveyId||!currentSurveyId);
    document.getElementById("historyContainer").innerHTML=list.length
        ?list.map(h=>`
            <div class="history-row">
                <div>
                    <strong>${esc(h.type)}</strong><br>
                    <span class="muted">${esc(h.date)} / 実行者：${esc(h.user)}</span>
                </div>
                <div>
                    ${h.count}件<br>
                    <button class="btn btn-sm" onclick="viewHistoryDetail(${h.id})">送信内容・対象顧客</button>
                </div>
            </div>
        `).join("")
        :'<div class="empty">送信履歴はありません。</div>';
}

function viewHistoryDetail(id){
    const h=sendHistories.find(x=>x.id===id);
    const cs=customers.filter(c=>h.customers.includes(c.id));
    openModal(
        "送信履歴詳細",
        `
        <p><strong>送信日時：</strong>${esc(h.date)}</p>
        <p><strong>送信種別：</strong>${esc(h.type)}</p>
        <p><strong>件名：</strong>${esc(h.subject)}</p>
        <p><strong>送信実行者：</strong>${esc(h.user)}</p>
        <hr>
        <strong>対象顧客</strong>
        <ul>${cs.map(c=>`<li>${esc(c.org)} / ${esc(c.name)} / ${esc(c.email)}</li>`).join("")}</ul>
        `,
        [{text:"閉じる"}]
    );
}

/* =========================================================
   集計
========================================================= */

function openAnalysis(id){
    currentSurveyId=id;
    const s=getSurvey(id);
    renumberSurvey(s);
    questionFilter=new Set(flattenQuestions(s).map(q=>q.id));
    renderAnalysis();
    showPage("analysis");
}

function renderAnalysis(){
    const s=getSurvey(currentSurveyId);
    if(!s)return;

    renumberSurvey(s);

    document.getElementById("analysisTitle").textContent=s.title;

    const targets=customers.length;
    const surveyAnswers=answers.filter(a=>a.surveyId===s.id);
    const unregistered=surveyAnswers.filter(a=>{
        const c=customers.find(x=>x.id===a.customerId);
        return !c||!c.kintone;
    }).length;
    const unanswered=Math.max(targets-surveyAnswers.length,0);
    const rate=targets?Math.round(surveyAnswers.length/targets*100):0;

    document.getElementById("statTargets").textContent=targets;
    document.getElementById("statAnswers").textContent=surveyAnswers.length;
    document.getElementById("statUnregistered").textContent=unregistered;
    document.getElementById("statUnanswered").textContent=unanswered;
    document.getElementById("statRate").textContent=rate+"%";

    const qs=flattenQuestions(s);

    document.getElementById("questionFilter").innerHTML=qs.map(q=>`
        <label class="checkbox-item" style="margin-bottom:8px">
            <input type="checkbox" ${questionFilter.has(q.id)?"checked":""}
                   onchange="toggleQuestionFilter('${q.id}',this.checked)">
            <strong>${esc(q.number)}</strong>　${esc(q.text||"質問文未入力")}
        </label>
    `).join("");

    const selected=qs.filter(q=>questionFilter.has(q.id));
    document.getElementById("questionAnalysis").innerHTML=selected.length
        ?selected.map(q=>renderQuestionAnalysis(q,s)).join("")
        :'<div class="empty">表示対象の設問を選択してください。</div>';

    document.getElementById("answerTable").innerHTML=surveyAnswers.length
        ?surveyAnswers.map(a=>{
            const c=customers.find(x=>x.id===a.customerId)||{org:"未登録",name:"未登録回答者"};
            const vals=Object.values(a.values||{}).flat().filter(Boolean);
            return `<tr>
                <td>${esc(c.org)}</td>
                <td>${esc(c.name)}</td>
                <td>${esc(a.date)}</td>
                <td>${esc(vals.slice(0,3).join(" / "))}</td>
                <td><button class="btn btn-sm" onclick="viewAnswer(${a.id})">全回答を表示</button></td>
            </tr>`;
        }).join("")
        :`<tr><td colspan="5"><div class="empty">現在、回答データはありません</div></td></tr>`;
}

function toggleQuestionFilter(id,checked){
    if(checked)questionFilter.add(id);
    else questionFilter.delete(id);
    renderAnalysis();
}

function selectAllQuestions(flag){
    const s=getSurvey(currentSurveyId);
    if(flag)questionFilter=new Set(flattenQuestions(s).map(q=>q.id));
    else questionFilter.clear();
    renderAnalysis();
}

function renderQuestionAnalysis(q,s){
    const relevant=answers.filter(a=>a.surveyId===s.id);
    const counts={};
    (q.options||[]).forEach(o=>counts[o]=0);

    relevant.forEach(a=>{
        let v=a.values?.[q.id];
        if(Array.isArray(v))v.forEach(x=>{if(counts[x]!==undefined)counts[x]++});
        else if(v&&counts[v]!==undefined)counts[v]++;
    });

    const total=Object.values(counts).reduce((a,b)=>a+b,0);

    if(q.type==="single"||q.type==="multi"){
        return `
        <div style="margin-bottom:28px">
            <h3 style="font-size:14px">${esc(q.number)}　${esc(q.text)}</h3>
            ${Object.entries(counts).map(([label,count])=>{
                const pct=total?Math.round(count/total*100):0;
                return `
                <div class="chart-row">
                    <div class="chart-label"><span>${esc(label)}</span><span>${count}件（${pct}%）</span></div>
                    <div class="bar"><span style="width:${pct}%"></span></div>
                </div>`;
            }).join("")}
            ${q.options?.includes("その他")?`
                <div class="alert alert-info">
                    「その他」回答：選択件数 ${counts["その他"]||0} 件 /
                    自由記述件数 ${relevant.filter(a=>String(a.values?.[q.id]||"").includes("その他")).length} 件
                </div>`:""}
        </div>`;
    }

    const texts=relevant.map(a=>a.values?.[q.id]).filter(v=>v);
    return `
        <div style="margin-bottom:28px">
            <h3 style="font-size:14px">${esc(q.number)}　${esc(q.text)}</h3>
            ${texts.length
                ?texts.map((v,i)=>{
                    const c=customers.find(x=>x.id===relevant[i]?.customerId);
                    return `<div class="mapping-box" style="margin-bottom:6px">
                        ${esc(Array.isArray(v)?v.join(", "):v)}
                        <div class="small muted">${esc(c?.name||"回答者")}</div>
                    </div>`;
                }).join("")
                :'<div class="muted">回答はありません。</div>'}
        </div>`;
}

function viewAnswer(answerId){
    const a=answers.find(x=>x.id===answerId);
    const s=getSurvey(a.surveyId);
    const c=customers.find(x=>x.id===a.customerId);

    let html=`
        <p><strong>回答ID：</strong>${a.id}</p>
        <p><strong>回答日時：</strong>${esc(a.date)}</p>
        <p><strong>組織名：</strong>${esc(c?.org||"未登録")}</p>
        <p><strong>氏名：</strong>${esc(c?.name||"未登録回答者")}</p>
        <hr>
    `;

    flattenQuestions(s).forEach(q=>{
        const v=a.values?.[q.id];
        html+=`
            <div style="padding:10px 0;border-bottom:1px solid var(--border)">
                <strong>${esc(q.number)}　${esc(q.text)}</strong><br>
                <span>${esc(Array.isArray(v)?v.join(", "):(v||"未回答"))}</span>
            </div>`;
    });

    openModal("全回答表示",html,[{text:"閉じる"}]);
}

function exportMock(type){
    openModal(
        `${type}出力`,
        `<div class="alert alert-success">${type}出力を実行しました（モック）。</div>
         <p class="muted">実際のファイル生成は行わず、出力操作が完了したことを確認するモックです。</p>
         <div class="mapping-box">
            <strong>出力内容</strong><br>
            アンケートタイトル<br>
            質問番号<br>
            回答日時<br>
            顧客情報<br>
            回答結果
         </div>`,
        [{text:"閉じる"}]
    );
}

/* =========================================================
   kintone
========================================================= */

function mockKintoneConnect(){
    document.getElementById("kinStatus").innerHTML=
        '<div class="alert alert-success">✓ kintone接続確認済み（モック）</div>';
}

function mockKintoneFields(){
    document.getElementById("kinFields").innerHTML=`
        <div class="checkbox-list">
            ${["組織名","氏名","メールアドレス","部署名","電話番号","郵便番号","都道府県","市区町村","番地","建物名"].map(x=>
                `<label class="checkbox-item"><input type="checkbox" checked> ${x}</label>`
            ).join("")}
        </div>`;
    showToast("kintone項目一覧を取得しました（モック）");
}

function mockSync(){
    document.getElementById("kinStatus").innerHTML=
        '<div class="alert alert-success">✓ 顧客情報を同期しました（モック）</div>';
    showToast("顧客情報を同期しました");
}

function saveKintoneMapping(){
    const address=[...document.querySelectorAll(".address-field:checked")].map(x=>x.value);
    showToast("マッピングを保存しました："+address.join(" / "));
}

/* =========================================================
   メールサーバ
========================================================= */

function testMailConnection(){
    document.getElementById("mailStatus").innerHTML=
        '<div class="alert alert-success">✓ 接続確認済み（モック）</div>';
}

function testMail(){
    openModal(
        "テストメール送信",
        `
        <div class="form-group">
            <label>送信先メールアドレス</label>
            <input id="testMailTo" class="form-control" value="admin@example.jp">
        </div>
        `,
        [
            {text:"キャンセル"},
            {text:"テスト送信",className:"btn-primary",action:()=>{
                document.getElementById("mailStatus").innerHTML=
                    '<div class="alert alert-success">✓ テストメール送信成功（モック）</div>';
                showToast("テストメールを送信しました");
            }}
        ]
    );
}

function saveMailSettings(){
    showToast("メールサーバ設定を保存しました");
}

/* =========================================================
   回答者画面
========================================================= */

function openAnswerer(surveyId){
    answerSurveyId=surveyId;
    answerStep=0;
    answerValues={};
    renderAnswerer();
    showPage("answer");
}

function getVisibleQuestions(survey){
    const all=flattenQuestions(survey);
    if(!Object.keys(answerValues).length)return all;

    const result=[];
    let i=0;
    while(i<all.length){
        const q=all[i];
        result.push(q);

        const v=answerValues[q.id];
        if(q.type==="single"&&v&&q.branch?.[v]){
            const target=q.branch[v];
            if(target==="end")break;
            if(target!=="next"){
                const idx=all.findIndex(x=>x.id===target);
                if(idx>=0){
                    i=idx;
                    continue;
                }
            }
        }
        i++;
    }
    return result;
}

function renderAnswerer(){
    const s=getSurvey(answerSurveyId);
    if(!s)return;
    renumberSurvey(s);

    const visible=getVisibleQuestions(s);
    const start=Math.min(answerStep,Math.max(visible.length-1,0));
    const q=visible[start];

    let html=`
        <span class="badge badge-blue">回答画面</span>
        <h1 style="font-size:23px;margin:12px 0">${esc(s.title)}</h1>
        <p class="muted">${esc(s.description)}</p>
        <div class="bar"><span style="width:${Math.round((start+1)/Math.max(visible.length,1)*100)}%"></span></div>
        <div class="small muted" style="margin-top:5px">${start+1} / ${visible.length}</div>
    `;

    if(!q){
        html+=`<div class="empty">回答する質問がありません。</div>`;
    }else{
        html+=`
            <div class="preview-q">
                <div class="preview-q-title">
                    ${esc(q.number)}　${esc(q.text||"質問文未入力")}
                    ${q.required?'<span class="required">必須</span>':''}
                </div>
                ${renderAnswerInput(q)}
            </div>
        `;
    }

    html+=`
        <div class="preview-actions">
            <button class="btn" onclick="answerBack()" ${start===0?"disabled":""}>戻る</button>
            ${start<visible.length-1
                ?'<button class="btn btn-primary" onclick="answerNext()">次へ</button>'
                :'<button class="btn btn-primary" onclick="answerConfirm()">回答確認</button>'}
        </div>
    `;

    document.getElementById("answerContent").innerHTML=html;
}

function renderAnswerInput(q){
    const value=answerValues[q.id];

    if(q.type==="single"){
        return (q.options||[]).map(o=>`
            <label class="preview-option">
                <input type="radio" name="answer-${q.id}" value="${esc(o)}"
                       ${value===o?"checked":""}
                       onchange="setAnswer('${q.id}',this.value)">
                <span>${esc(o)}</span>
            </label>`).join("");
    }

    if(q.type==="multi"){
        const vals=Array.isArray(value)?value:[];
        return (q.options||[]).map(o=>`
            <label class="preview-option">
                <input type="checkbox" value="${esc(o)}"
                       ${vals.includes(o)?"checked":""}
                       onchange="setMultiAnswer('${q.id}',this.value,this.checked)">
                <span>${esc(o)}</span>
            </label>`).join("");
    }

    if(q.type==="text"){
        return `<input class="form-control" value="${esc(value||"")}"
                onchange="setAnswer('${q.id}',this.value)" placeholder="回答を入力してください">`;
    }

    return `<textarea class="form-control" onchange="setAnswer('${q.id}',this.value)"
                placeholder="回答を入力してください">${esc(value||"")}</textarea>`;
}

function setAnswer(id,value){
    answerValues[id]=value;
}

function setMultiAnswer(id,value,checked){
    if(!Array.isArray(answerValues[id]))answerValues[id]=[];
    if(checked){
        if(!answerValues[id].includes(value))answerValues[id].push(value);
    }else{
        answerValues[id]=answerValues[id].filter(x=>x!==value);
    }
}

function answerNext(){
    const s=getSurvey(answerSurveyId);
    const visible=getVisibleQuestions(s);
    const q=visible[answerStep];

    if(q.required){
        const v=answerValues[q.id];
        if(v===undefined||v===null||v===""||(Array.isArray(v)&&!v.length)){
            showToast("必須項目に回答してください");
            return;
        }
    }

    answerStep++;
    renderAnswerer();
}

function answerBack(){
    if(answerStep>0)answerStep--;
    renderAnswerer();
}

function answerConfirm(){
    const s=getSurvey(answerSurveyId);
    const visible=getVisibleQuestions(s);

    const missing=visible.find(q=>{
        if(!q.required)return false;
        const v=answerValues[q.id];
        return v===undefined||v===null||v===""||(Array.isArray(v)&&!v.length);
    });

    if(missing){
        showToast(`${missing.number} は必須項目です`);
        return;
    }

    let html=`
        <h2 style="font-size:18px">${esc(s.title)}</h2>
        <p class="muted">回答内容をご確認ください。</p>
    `;

    visible.forEach(q=>{
        const v=answerValues[q.id];
        html+=`
            <div style="padding:12px 0;border-bottom:1px solid var(--border)">
                <strong>${esc(q.number)}　${esc(q.text)}</strong><br>
                <span>${esc(Array.isArray(v)?v.join(", "):(v||"未回答"))}</span>
            </div>`;
    });

    html+=`
        <div class="preview-actions">
            <button class="btn" onclick="answerStep=0;renderAnswerer()">修正する</button>
            <button class="btn btn-primary" onclick="submitAnswer()">回答を送信する</button>
        </div>`;

    document.getElementById("answerContent").innerHTML=html;
}

function submitAnswer(){
    confirmAction(
        "回答送信",
        "回答を送信します。よろしいですか？",
        ()=>{
            const c=customers.find(x=>x.id===1);
            answers.push({
                id:Date.now(),
                customerId:c?.id||null,
                surveyId:answerSurveyId,
                date:nowString(),
                values:clone(answerValues)
            });
            if(c){
                c.status="回答済み";
                c.sent=c.sent||nowString();
            }
            showPage("complete");
            showToast("回答を送信しました");
        },
        "送信する"
    );
}

/* =========================================================
   初期化
========================================================= */

surveys.forEach(renumberSurvey);
renderSurveyList();

/* モーダル背景クリック */
document.getElementById("modalBackdrop").addEventListener("click",function(e){
    if(e.target===this)closeModal();
});
</script>

</body>
</html>
<?php
/**
 * アンケート管理システム
 * 動作モック / 1ファイル版
 *
 * 本ファイルはUI・画面遷移・操作感を確認するためのモックです。
 * DB / kintone API / SMTP / 認証等の実処理は行いません。
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム - 動作モック</title>

<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --info:#0891b2;
    --dark:#1e293b;
    --text:#334155;
    --muted:#64748b;
    --border:#e2e8f0;
    --bg:#f8fafc;
    --white:#fff;
    --shadow:0 2px 10px rgba(15,23,42,.07);
    --radius:10px;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Hiragino Kaku Gothic ProN",
        "Yu Gothic",
        Meiryo,
        sans-serif;
    background:var(--bg);
    color:var(--text);
}

button,
input,
select,
textarea{
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
    background:#fff;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    padding:0 24px;
    justify-content:space-between;
}

.logo{
    display:flex;
    align-items:center;
    gap:10px;
    font-weight:800;
    color:var(--dark);
    white-space:nowrap;
}

.logo-mark{
    width:34px;
    height:34px;
    border-radius:9px;
    background:linear-gradient(135deg,#2563eb,#7c3aed);
    color:white;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
}

.nav{
    display:flex;
    align-items:center;
    gap:4px;
}

.nav button{
    border:0;
    background:transparent;
    color:#475569;
    padding:10px 14px;
    border-radius:8px;
}

.nav button:hover,
.nav button.active{
    background:#eff6ff;
    color:var(--primary);
}

.main{
    max-width:1500px;
    margin:auto;
    padding:26px;
}

.page{
    display:none;
}

.page.active{
    display:block;
}

.page-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:22px;
}

.page-title{
    margin:0;
    color:var(--dark);
    font-size:25px;
}

.page-subtitle{
    color:var(--muted);
    margin-top:5px;
    font-size:13px;
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
    border-radius:8px;
    font-weight:600;
    transition:.15s;
}

.btn:hover{
    transform:translateY(-1px);
    box-shadow:0 2px 8px rgba(0,0,0,.06);
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

.btn.warning{
    background:var(--warning);
    border-color:var(--warning);
    color:#fff;
}

.btn.danger{
    background:var(--danger);
    border-color:var(--danger);
    color:#fff;
}

.btn.ghost{
    background:#f8fafc;
}

.btn.small{
    padding:6px 9px;
    font-size:12px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
}

.card-header{
    padding:17px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.card-body{
    padding:20px;
}

.toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    padding:16px;
    margin-bottom:16px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
}

.input,
.select,
.textarea{
    width:100%;
    border:1px solid #cbd5e1;
    background:#fff;
    border-radius:7px;
    padding:9px 11px;
    color:var(--text);
    outline:none;
}

.input:focus,
.select:focus,
.textarea:focus{
    border-color:#60a5fa;
    box-shadow:0 0 0 3px rgba(37,99,235,.1);
}

.search-box{
    width:320px;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:1000px;
}

th,
td{
    border-bottom:1px solid var(--border);
    padding:13px 12px;
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
    font-weight:700;
    color:#1e293b;
}

.date-cell{
    color:#64748b;
    line-height:1.7;
}

.badge{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:4px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    white-space:nowrap;
}

.badge.public{
    color:#166534;
    background:#dcfce7;
}

.badge.draft{
    color:#475569;
    background:#e2e8f0;
}

.badge.end{
    color:#991b1b;
    background:#fee2e2;
}

.badge.answer{
    color:#0369a1;
    background:#e0f2fe;
}

.badge.unanswered{
    color:#92400e;
    background:#fef3c7;
}

.badge.unregistered{
    color:#991b1b;
    background:#fee2e2;
}

.badge.registered{
    color:#166534;
    background:#dcfce7;
}

.badge.info{
    color:#1e40af;
    background:#dbeafe;
}

.actions-cell{
    white-space:nowrap;
}

.action-row{
    display:flex;
    gap:5px;
    flex-wrap:wrap;
}

.empty{
    padding:50px 20px;
    text-align:center;
    color:var(--muted);
}

.stats{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:14px;
    margin-bottom:20px;
}

.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:19px;
    box-shadow:var(--shadow);
}

.stat-label{
    font-size:12px;
    color:var(--muted);
    margin-bottom:8px;
}

.stat-value{
    font-size:27px;
    font-weight:800;
    color:var(--dark);
}

.stat-value small{
    font-size:13px;
    font-weight:500;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:16px;
}

.form-group{
    margin-bottom:15px;
}

.form-group.full{
    grid-column:1/-1;
}

.form-label{
    display:block;
    font-size:13px;
    font-weight:700;
    margin-bottom:6px;
    color:#475569;
}

.required{
    color:var(--danger);
}

.builder{
    display:flex;
    flex-direction:column;
    gap:16px;
}

.group-card{
    border:1px solid #cbd5e1;
    border-radius:10px;
    background:#f8fafc;
    overflow:hidden;
}

.group-head{
    background:#eef2ff;
    padding:12px;
    display:flex;
    align-items:center;
    gap:9px;
}

.drag-handle{
    cursor:grab;
    font-size:20px;
    color:#64748b;
}

.group-title{
    flex:1;
}

.questions{
    padding:12px;
    display:flex;
    flex-direction:column;
    gap:10px;
    min-height:30px;
}

.question{
    background:#fff;
    border:1px solid var(--border);
    border-radius:9px;
    padding:14px;
}

.question-head{
    display:flex;
    align-items:center;
    gap:8px;
}

.question-number{
    color:var(--primary);
    font-weight:800;
    min-width:35px;
}

.question-title{
    flex:1;
}

.question-body{
    padding:14px 0 0 43px;
}

.option-row{
    display:flex;
    gap:7px;
    margin-bottom:7px;
}

.option-row .input{
    flex:1;
}

.question-footer{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-top:12px;
    padding-top:10px;
    border-top:1px dashed #e2e8f0;
}

.switch{
    display:inline-flex;
    align-items:center;
    gap:8px;
    font-size:12px;
    color:#475569;
}

.switch input{
    accent-color:var(--primary);
}

.preview-shell{
    max-width:780px;
    margin:auto;
    background:#fff;
    border-radius:12px;
    box-shadow:0 10px 30px rgba(15,23,42,.1);
    min-height:500px;
}

.preview-mobile{
    width:390px;
    max-width:100%;
    border:10px solid #1e293b;
    border-radius:25px;
    margin:auto;
    overflow:hidden;
}

.preview-inner{
    padding:25px;
}

.preview-question{
    margin:24px 0;
}

.preview-options{
    display:flex;
    flex-direction:column;
    gap:10px;
}

.preview-option{
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:12px;
    cursor:pointer;
}

.preview-option:hover{
    border-color:#60a5fa;
    background:#eff6ff;
}

.modal-bg{
    display:none;
    position:fixed;
    inset:0;
    z-index:100;
    background:rgba(15,23,42,.55);
    padding:30px;
    overflow:auto;
}

.modal-bg.show{
    display:flex;
    align-items:center;
    justify-content:center;
}

.modal{
    width:min(900px,100%);
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:12px;
    box-shadow:0 20px 70px rgba(0,0,0,.25);
}

.modal.small{
    width:min(520px,100%);
}

.modal-head{
    padding:17px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.modal-title{
    font-size:17px;
    font-weight:800;
    color:var(--dark);
}

.modal-body{
    padding:20px;
}

.modal-foot{
    padding:14px 20px;
    border-top:1px solid var(--border);
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.close{
    border:0;
    background:transparent;
    font-size:23px;
    color:#64748b;
}

.alert{
    padding:13px 15px;
    border-radius:8px;
    margin-bottom:16px;
    font-size:13px;
}

.alert.warning{
    background:#fffbeb;
    border:1px solid #fde68a;
    color:#92400e;
}

.alert.info{
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1e40af;
}

.alert.success{
    background:#f0fdf4;
    border:1px solid #bbf7d0;
    color:#166534;
}

.alert.danger{
    background:#fef2f2;
    border:1px solid #fecaca;
    color:#991b1b;
}

.kpi-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px;
}

.question-filter{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
}

.filter-question{
    display:flex;
    align-items:center;
    gap:7px;
    padding:8px 10px;
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:7px;
    font-size:12px;
}

.bar-chart{
    display:flex;
    flex-direction:column;
    gap:10px;
}

.bar-row{
    display:grid;
    grid-template-columns:140px 1fr 60px;
    align-items:center;
    gap:10px;
    font-size:12px;
}

.bar{
    height:16px;
    background:#e2e8f0;
    border-radius:999px;
    overflow:hidden;
}

.bar > div{
    height:100%;
    background:linear-gradient(90deg,#2563eb,#60a5fa);
    border-radius:999px;
}

.timeline{
    max-height:280px;
    overflow:auto;
    display:flex;
    flex-direction:column;
    gap:10px;
}

.timeline-item{
    border-left:3px solid #93c5fd;
    padding:8px 12px;
    background:#f8fafc;
}

.breadcrumb{
    color:#64748b;
    font-size:12px;
    margin-bottom:15px;
}

.settings-tabs{
    display:flex;
    gap:5px;
    border-bottom:1px solid var(--border);
    margin-bottom:20px;
}

.settings-tabs button{
    border:0;
    background:transparent;
    padding:11px 15px;
    color:#64748b;
    border-bottom:2px solid transparent;
}

.settings-tabs button.active{
    color:var(--primary);
    border-bottom-color:var(--primary);
}

.sync-status{
    display:flex;
    align-items:center;
    gap:10px;
    background:#f8fafc;
    padding:12px;
    border-radius:8px;
    margin-bottom:15px;
}

.dot{
    width:10px;
    height:10px;
    border-radius:50%;
    background:#94a3b8;
}

.dot.green{
    background:#22c55e;
}

.dot.red{
    background:#ef4444;
}

.mail-preview{
    background:#f8fafc;
    border:1px solid var(--border);
    padding:20px;
    border-radius:8px;
    white-space:pre-wrap;
    line-height:1.8;
}

.answer-progress{
    height:7px;
    background:#e2e8f0;
    border-radius:99px;
    overflow:hidden;
    margin:15px 0 25px;
}

.answer-progress div{
    height:100%;
    background:var(--primary);
}

.answer-nav{
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:25px;
}

.answer-choice{
    display:block;
    padding:13px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    margin-bottom:9px;
}

.answer-choice:hover{
    background:#eff6ff;
    border-color:#93c5fd;
}

.toast{
    position:fixed;
    right:22px;
    bottom:22px;
    z-index:200;
    background:#1e293b;
    color:white;
    padding:13px 18px;
    border-radius:8px;
    box-shadow:0 10px 30px rgba(0,0,0,.2);
    transform:translateY(100px);
    opacity:0;
    transition:.25s;
}

.toast.show{
    transform:translateY(0);
    opacity:1;
}

@media(max-width:1000px){
    .stats{
        grid-template-columns:repeat(2,1fr);
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .kpi-grid{
        grid-template-columns:1fr;
    }

    .nav button{
        padding:8px;
        font-size:12px;
    }
}

@media(max-width:700px){
    .app-header{
        height:auto;
        padding:10px 12px;
        align-items:flex-start;
        gap:8px;
        flex-direction:column;
    }

    .nav{
        width:100%;
        overflow-x:auto;
    }

    .nav button{
        white-space:nowrap;
    }

    .main{
        padding:14px;
    }

    .page-header{
        flex-direction:column;
        align-items:flex-start;
    }

    .stats{
        grid-template-columns:1fr;
    }

    .search-box{
        width:100%;
    }

    .toolbar > *{
        width:100%;
    }

    .bar-row{
        grid-template-columns:90px 1fr 45px;
    }

    .modal-bg{
        padding:10px;
    }
}
</style>
</head>

<body>

<header class="app-header">
    <div class="logo">
        <div class="logo-mark">Q</div>
        <div>アンケート管理システム</div>
    </div>

    <nav class="nav">
        <button data-nav="list" onclick="showPage('list')">アンケート一覧</button>
        <button data-nav="kintone" onclick="showPage('kintone')">kintone連携設定</button>
        <button data-nav="mail" onclick="showPage('mail')">メールサーバ設定</button>
        <button onclick="alert('ログアウトのモックです。')">ログアウト</button>
    </nav>
</header>

<main class="main">

<!-- =========================================================
     アンケート一覧
========================================================= -->
<section id="page-list" class="page active">

    <div class="page-header">
        <div>
            <h1 class="page-title">アンケート一覧</h1>
            <div class="page-subtitle">
                アンケートの作成・公開・送信・集計を一元管理します。
            </div>
        </div>

        <div class="actions">
            <button class="btn primary" onclick="openCreateSurvey()">
                ＋ 新規アンケート作成
            </button>
        </div>
    </div>

    <div class="toolbar">
        <input
            id="surveySearch"
            class="input search-box"
            placeholder="タイトルを検索..."
            onkeyup="if(event.key==='Enter') renderSurveys()"
        >

        <select id="surveyStatus" class="select" style="width:180px" onchange="renderSurveys()">
            <option value="">すべて</option>
            <option value="公開中">公開中</option>
            <option value="下書き">下書き</option>
            <option value="終了">終了</option>
        </select>

        <select id="surveySort" class="select" style="width:230px" onchange="renderSurveys()">
            <option value="updated_desc">更新日：新しい順</option>
            <option value="updated_asc">更新日：古い順</option>
            <option value="answers_desc">回答数：多い順</option>
            <option value="answers_asc">回答数：少ない順</option>
            <option value="start_desc">開始日：新しい順</option>
            <option value="start_asc">開始日：古い順</option>
        </select>

        <button class="btn" onclick="renderSurveys()">検索</button>
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

    <div class="page-header">
        <div>
            <h1 class="page-title" id="editorTitle">アンケート作成</h1>
            <div class="page-subtitle">グループと設問を自由に編集できます。</div>
        </div>

        <div class="actions">
            <button class="btn" onclick="openPreview()">プレビュー</button>
            <button class="btn" onclick="cancelEditor()">キャンセル</button>
            <button class="btn primary" onclick="saveSurvey()">保存して一覧へ戻る</button>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header">
            <strong>アンケート基本情報</strong>
        </div>

        <div class="card-body">
            <div class="form-grid">

                <div class="form-group full">
                    <label class="form-label">
                        アンケートタイトル <span class="required">*</span>
                    </label>
                    <input id="editSurveyTitle" class="input"
                           value="【顧客満足度調査】サービスに関するアンケート">
                </div>

                <div class="form-group">
                    <label class="form-label">開始日時</label>
                    <input id="editStart" class="input" type="datetime-local"
                           value="2026-08-01T09:00">
                </div>

                <div class="form-group">
                    <label class="form-label">終了日時</label>
                    <input id="editEnd" class="input" type="datetime-local"
                           value="2026-08-31T23:59">
                </div>

                <div class="form-group full">
                    <label class="form-label">アンケート説明</label>
                    <textarea id="editDescription" class="textarea" rows="3">サービスについてのご意見をお聞かせください。</textarea>
                </div>

            </div>
        </div>
    </div>

    <div class="builder" id="builder"></div>

    <div style="margin-top:15px">
        <button class="btn primary" onclick="addGroup()">＋ グループを追加</button>
    </div>

</section>


<!-- =========================================================
     集計・分析
========================================================= -->
<section id="page-analysis" class="page">

    <div class="page-header">
        <div>
            <div class="breadcrumb">ホーム ＞ アンケート一覧 ＞ 集計・分析</div>
            <h1 class="page-title" id="analysisTitle">顧客満足度調査</h1>
        </div>

        <div class="actions">
            <button class="btn" onclick="downloadCSV()">CSVダウンロード</button>
            <button class="btn" onclick="exportPDF()">PDF出力</button>
            <button class="btn" onclick="showPage('list')">一覧へ戻る</button>
        </div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-label">送信対象者数</div>
            <div class="stat-value">200 <small>人</small></div>
        </div>
        <div class="stat">
            <div class="stat-label">回答数</div>
            <div class="stat-value">128 <small>件</small></div>
        </div>
        <div class="stat">
            <div class="stat-label">未登録顧客からの回答数</div>
            <div class="stat-value">8 <small>件</small></div>
        </div>
        <div class="stat">
            <div class="stat-label">未回答数</div>
            <div class="stat-value">80 <small>人</small></div>
        </div>
        <div class="stat">
            <div class="stat-label">回答率</div>
            <div class="stat-value">60.0 <small>%</small></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:20px">
        <div class="card-header">
            <strong>集計対象の設問</strong>
            <div>
                <button class="btn small" onclick="toggleAllQuestions(true)">すべて選択</button>
                <button class="btn small" onclick="toggleAllQuestions(false)">すべて解除</button>
            </div>
        </div>

        <div class="card-body">
            <div class="question-filter" id="questionFilter"></div>
        </div>
    </div>

    <div id="analysisQuestions"></div>

    <div class="card" style="margin-top:20px">
        <div class="card-header">
            <strong>個別回答一覧</strong>

            <input id="answerSearch"
                   class="input"
                   style="max-width:300px"
                   placeholder="会社名・氏名で検索"
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
                <tbody id="answerTable"></tbody>
            </table>
        </div>
    </div>

</section>


<!-- =========================================================
     顧客選択・メール送信
========================================================= -->
<section id="page-mail-send" class="page">

    <div class="page-header">
        <div>
            <div class="breadcrumb">ホーム ＞ アンケート一覧 ＞ 顧客選択・送信</div>
            <h1 class="page-title" id="sendSurveyTitle">顧客選択・メール送信</h1>
        </div>

        <div class="actions">
            <button class="btn" onclick="showSendHistory()">送信履歴</button>
            <button class="btn" onclick="showPage('list')">一覧へ戻る</button>
        </div>
    </div>

    <div class="alert warning">
        <strong>⚠ kintone未登録回答者があります。</strong><br>
        Web公開URLから回答した顧客のうち、kintoneに登録されていない回答者を確認してください。
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header">
            <strong>送信メールテンプレート</strong>

            <button class="btn small" onclick="insertMailVariable('{顧客名}')">
                ＋ 顧客名
            </button>
        </div>

        <div class="card-body">
            <div class="form-group">
                <label class="form-label">メール件名</label>
                <input id="mailSubject" class="input"
                       value="【アンケートご協力のお願い】サービスに関するアンケート">
            </div>

            <div class="form-group">
                <label class="form-label">メール本文</label>
                <textarea id="mailBody" class="textarea" rows="9">{顧客名} 様

いつもお世話になっております。
アンケートへのご協力をお願いいたします。

以下のURLよりご回答ください。
{アンケートURL}

ご回答をよろしくお願いいたします。</textarea>
            </div>

            <div class="alert info">
                利用可能な変数：
                <strong>{顧客名}</strong>　
                <strong>{アンケートURL}</strong>
            </div>
        </div>
    </div>

    <div class="toolbar">
        <input id="customerSearch"
               class="input search-box"
               placeholder="会社名・氏名・メールアドレス"
               oninput="renderCustomers()">

        <select id="customerFilter" class="select" style="width:200px" onchange="renderCustomers()">
            <option value="">すべて</option>
            <option value="未送信">未送信</option>
            <option value="未回答">送信済み / 未回答</option>
            <option value="回答済み">回答済み</option>
            <option value="未登録">kintone未登録</option>
        </select>

        <button class="btn" onclick="selectVisibleCustomers()">表示中を選択</button>
        <button class="btn" onclick="clearCustomers()">選択解除</button>
        <button class="btn primary" onclick="sendSelectedCustomers()">一括送信</button>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>
                        <input type="checkbox" id="customerAll"
                               onchange="toggleAllCustomers(this.checked)">
                    </th>
                    <th>会社名 / 氏名等</th>
                    <th>送信ステータス / 履歴</th>
                    <th>回答ステータス</th>
                    <th>kintone対応</th>
                </tr>
                </thead>
                <tbody id="customerTable"></tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top:20px">
        <div class="card-header">
            <strong>一括送信ログ</strong>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>日時</th>
                    <th>種別</th>
                    <th>件数</th>
                    <th>件名</th>
                    <th>実行者</th>
                </tr>
                </thead>
                <tbody id="sendLogTable"></tbody>
            </table>
        </div>
    </div>

</section>


<!-- =========================================================
     kintone設定
========================================================= -->
<section id="page-kintone" class="page">

    <div class="page-header">
        <div>
            <div class="breadcrumb">ホーム ＞ システム設定 ＞ kintone連携設定</div>
            <h1 class="page-title">kintone連携設定</h1>
        </div>

        <button class="btn primary" onclick="saveKintone()">設定を保存する</button>
    </div>

    <div class="card">
        <div class="card-header">
            <strong>kintone接続設定</strong>
        </div>

        <div class="card-body">

            <div class="form-grid">

                <div class="form-group">
                    <label class="form-label">サブドメイン</label>
                    <input id="kSubdomain" class="input" value="example">
                </div>

                <div class="form-group">
                    <label class="form-label">顧客管理アプリID</label>
                    <input id="kAppId" class="input" value="123">
                </div>

                <div class="form-group">
                    <label class="form-label">ログイン名</label>
                    <input id="kUser" class="input" value="admin">
                </div>

                <div class="form-group">
                    <label class="form-label">パスワード</label>
                    <input id="kPassword" class="input" type="password" value="********">
                </div>

                <div class="form-group">
                    <label class="form-label">SSL証明書検証</label>
                    <select id="kSsl" class="select">
                        <option>検証する</option>
                        <option>検証しない（開発用）</option>
                    </select>
                </div>

            </div>

            <div class="sync-status">
                <span id="kDot" class="dot"></span>
                <span id="kStatus">未接続</span>
            </div>

            <button class="btn" onclick="loadKintoneFields()">
                項目一覧を再取得
            </button>

            <button class="btn" onclick="syncKintone()">
                顧客情報を同期
            </button>

        </div>
    </div>

    <div class="card" style="margin-top:18px">
        <div class="card-header">
            <strong>顧客情報フィールドマッピング</strong>
        </div>

        <div class="card-body">

            <div class="alert info">
                kintoneの日本語項目名を選択するだけでマッピングできます。
                「項目一覧を再取得」でサンプル項目を読み込みます。
            </div>

            <div id="mappingArea"></div>

        </div>
    </div>

</section>


<!-- =========================================================
     メールサーバ設定
========================================================= -->
<section id="page-mail" class="page">

    <div class="page-header">
        <div>
            <div class="breadcrumb">ホーム ＞ システム設定 ＞ メールサーバ設定</div>
            <h1 class="page-title">メールサーバ設定</h1>
        </div>

        <button class="btn primary" onclick="saveMailServer()">設定を保存する</button>
    </div>

    <div class="card">
        <div class="card-header">
            <strong>SMTPサーバ設定</strong>
        </div>

        <div class="card-body">

            <div class="form-grid">

                <div class="form-group">
                    <label class="form-label">SMTPサーバ</label>
                    <input id="smtpHost" class="input" value="smtp.example.jp">
                </div>

                <div class="form-group">
                    <label class="form-label">SMTPポート</label>
                    <input id="smtpPort" class="input" value="587">
                </div>

                <div class="form-group">
                    <label class="form-label">暗号化方式</label>
                    <select id="smtpEncryption" class="select">
                        <option>TLS</option>
                        <option>SSL</option>
                        <option>なし</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">SMTP認証</label>
                    <select class="select">
                        <option>使用する</option>
                        <option>使用しない</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">SMTPユーザー名</label>
                    <input id="smtpUser" class="input" value="survey@example.jp">
                </div>

                <div class="form-group">
                    <label class="form-label">SMTPパスワード</label>
                    <input id="smtpPassword" class="input" type="password" value="********">
                </div>

                <div class="form-group">
                    <label class="form-label">送信元メールアドレス</label>
                    <input id="fromMail" class="input" value="survey@example.jp">
                </div>

                <div class="form-group">
                    <label class="form-label">送信元名</label>
                    <input id="fromName" class="input" value="アンケート事務局">
                </div>

                <div class="form-group">
                    <label class="form-label">返信先メールアドレス</label>
                    <input id="replyMail" class="input" value="support@example.jp">
                </div>

            </div>

            <div class="sync-status">
                <span id="mailDot" class="dot"></span>
                <span id="mailStatus">未設定</span>
            </div>

            <button class="btn" onclick="testMailConnection()">
                接続テスト
            </button>

            <button class="btn" onclick="openTestMail()">
                テストメール送信
            </button>

        </div>
    </div>

</section>


<!-- =========================================================
     回答者画面
========================================================= -->
<section id="page-answer" class="page">

    <div class="page-header">
        <div>
            <div class="breadcrumb">アンケート回答</div>
            <h1 class="page-title">アンケート回答</h1>
        </div>

        <button class="btn" onclick="showPage('list')">
            管理画面へ戻る
        </button>
    </div>

    <div id="answerApp"></div>

</section>

</main>


<!-- =========================================================
     モーダル
========================================================= -->
<div id="modalBg" class="modal-bg">
    <div class="modal" id="modalBox">
        <div class="modal-head">
            <div class="modal-title" id="modalTitle">確認</div>
            <button class="close" onclick="closeModal()">×</button>
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

let surveys = [
    {
        id:1,
        title:"【顧客満足度調査】サービスに関するアンケート",
        created:"2026/07/25",
        updated:"2026/08/10",
        start:"2026/08/01",
        end:"2026/08/31",
        status:"公開中",
        answers:128,
        description:"サービスについてのご意見をお聞かせください。",
        groups:[
            {
                id:101,
                title:"サービス全般について",
                questions:[
                    {
                        id:1001,
                        text:"サービス全体の満足度を教えてください。",
                        type:"single",
                        required:true,
                        options:["非常に満足","満足","普通","やや不満","不満"]
                    },
                    {
                        id:1002,
                        text:"今後もサービスを利用したいと思いますか？",
                        type:"single",
                        required:true,
                        options:["はい","いいえ"]
                    }
                ]
            },
            {
                id:102,
                title:"ご意見・ご要望",
                questions:[
                    {
                        id:1003,
                        text:"サービスについてご意見があればご記入ください。",
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
        title:"新商品に関するアンケート",
        created:"2026/07/20",
        updated:"2026/08/08",
        start:"2026/08/05",
        end:"2026/09/05",
        status:"下書き",
        answers:0,
        description:"新商品のご意見をお聞かせください。",
        groups:[
            {
                id:201,
                title:"新商品について",
                questions:[
                    {
                        id:2001,
                        text:"新商品に興味がありますか？",
                        type:"single",
                        required:true,
                        options:["非常に興味がある","興味がある","あまりない","ない"]
                    }
                ]
            }
        ]
    },
    {
        id:3,
        title:"2026年度サービス利用状況調査",
        created:"2026/06/01",
        updated:"2026/07/31",
        start:"2026/06/10",
        end:"2026/07/31",
        status:"終了",
        answers:245,
        description:"2026年度のサービス利用状況調査です。",
        groups:[
            {
                id:301,
                title:"利用状況",
                questions:[
                    {
                        id:3001,
                        text:"サービスを利用した頻度を教えてください。",
                        type:"single",
                        required:true,
                        options:["毎日","週数回","月数回","ほとんど利用しない"]
                    },
                    {
                        id:3002,
                        text:"改善してほしい点があればご記入ください。",
                        type:"text",
                        required:false,
                        options:[]
                    }
                ]
            }
        ]
    }
];

let customers = [
    {
        id:1,
        company:"株式会社サンプル",
        name:"山田 太郎",
        email:"yamada@example.jp",
        phone:"03-1234-5678",
        address:"東京都港区",
        sent:true,
        answered:true,
        sentAt:"2026/08/05 10:30",
        count:1,
        registered:true
    },
    {
        id:2,
        company:"株式会社ABC",
        name:"佐藤 花子",
        email:"sato@example.jp",
        phone:"03-2345-6789",
        address:"東京都千代田区",
        sent:true,
        answered:false,
        sentAt:"2026/08/05 10:32",
        count:1,
        registered:true
    },
    {
        id:3,
        company:"合同会社テスト",
        name:"鈴木 一郎",
        email:"suzuki@example.jp",
        phone:"03-3456-7890",
        address:"東京都渋谷区",
        sent:false,
        answered:false,
        sentAt:"",
        count:0,
        registered:true
    },
    {
        id:4,
        company:"Web回答者",
        name:"田中 次郎",
        email:"tanaka-web@example.jp",
        phone:"090-1111-2222",
        address:"東京都新宿区",
        sent:false,
        answered:true,
        sentAt:"",
        count:0,
        registered:false,
        web:true
    },
    {
        id:5,
        company:"株式会社XYZ",
        name:"高橋 美咲",
        email:"takahashi@example.jp",
        phone:"03-4567-8901",
        address:"東京都品川区",
        sent:true,
        answered:false,
        sentAt:"2026/08/08 14:20",
        count:1,
        registered:true
    }
];

let sendLogs = [
    {
        date:"2026/08/08 14:20",
        type:"初回一括送信",
        count:120,
        subject:"【アンケートご協力のお願い】サービスに関するアンケート",
        user:"管理者"
    },
    {
        date:"2026/08/15 09:15",
        type:"リマインド送信",
        count:32,
        subject:"【再送】アンケートご回答のお願い",
        user:"管理者"
    }
];

let currentSurveyId = null;
let editorSurvey = null;
let currentSendSurveyId = null;
let currentAnswerQuestion = 0;
let answerMode = "input";
let answerValues = {};

let kintoneFields = [
    {label:"会社名",code:"company_name"},
    {label:"氏名",code:"name"},
    {label:"メールアドレス",code:"email"},
    {label:"部署名",code:"department"},
    {label:"電話番号",code:"phone"},
    {label:"郵便番号",code:"zip"},
    {label:"都道府県",code:"prefecture"},
    {label:"住所",code:"address"}
];

let mapping = {
    company:"会社名",
    name:"氏名",
    email:"メールアドレス",
    department:"部署名",
    phone:"電話番号",
    address:["郵便番号","都道府県","住所"]
};


/* =========================================================
   共通
========================================================= */

function showPage(page){
    document.querySelectorAll(".page").forEach(p => p.classList.remove("active"));

    const el = document.getElementById("page-" + page);

    if(el){
        el.classList.add("active");
    }

    document.querySelectorAll(".nav button[data-nav]").forEach(b=>{
        b.classList.remove("active");
    });

    const nav = document.querySelector('.nav button[data-nav="'+page+'"]');

    if(nav){
        nav.classList.add("active");
    }

    window.scrollTo({top:0,behavior:"smooth"});
}

function toast(message){
    const t = document.getElementById("toast");

    t.textContent = message;
    t.classList.add("show");

    clearTimeout(window.__toastTimer);

    window.__toastTimer = setTimeout(()=>{
        t.classList.remove("show");
    },2500);
}

function openModal(title,body,buttons){
    document.getElementById("modalTitle").textContent = title;
    document.getElementById("modalBody").innerHTML = body;
    document.getElementById("modalFoot").innerHTML = buttons || "";

    document.getElementById("modalBg").classList.add("show");
}

function closeModal(){
    document.getElementById("modalBg").classList.remove("show");
}

document.getElementById("modalBg").addEventListener("click",function(e){
    if(e.target === this){
        closeModal();
    }
});


/* =========================================================
   一覧
========================================================= */

function renderSurveys(){

    const keyword = document.getElementById("surveySearch").value.trim().toLowerCase();
    const status = document.getElementById("surveyStatus").value;
    const sort = document.getElementById("surveySort").value;

    let list = [...surveys];

    list = list.filter(s=>{
        const matchKeyword = !keyword || s.title.toLowerCase().includes(keyword);
        const matchStatus = !status || s.status === status;

        return matchKeyword && matchStatus;
    });

    list.sort((a,b)=>{

        if(sort === "updated_desc")
            return b.updated.localeCompare(a.updated);

        if(sort === "updated_asc")
            return a.updated.localeCompare(b.updated);

        if(sort === "answers_desc")
            return b.answers - a.answers;

        if(sort === "answers_asc")
            return a.answers - b.answers;

        if(sort === "start_desc")
            return b.start.localeCompare(a.start);

        if(sort === "start_asc")
            return a.start.localeCompare(b.start);

        return 0;
    });

    const tbody = document.getElementById("surveyTable");

    if(!list.length){
        tbody.innerHTML = `
            <tr>
                <td colspan="6">
                    <div class="empty">該当するアンケートがありません。</div>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = list.map(s=>{

        let badge = "";

        if(s.status === "公開中")
            badge = `<span class="badge public">● 公開中</span>`;

        if(s.status === "下書き")
            badge = `<span class="badge draft">● 下書き</span>`;

        if(s.status === "終了")
            badge = `<span class="badge end">● 終了</span>`;

        let actions = `
            <button class="btn small" onclick="openEditor(${s.id})">
                確認・編集
            </button>
        `;

        if(s.status === "公開中"){
            actions += `
                <button class="btn small" onclick="openAnalysis(${s.id})">集計</button>
                <button class="btn small" onclick="openSend(${s.id})">送信</button>
                <button class="btn small warning" onclick="stopSurvey(${s.id})">停止</button>
                <button class="btn small" onclick="duplicateSurvey(${s.id})">複製</button>
            `;
        }

        if(s.status === "下書き"){
            actions += `
                <button class="btn small danger" onclick="deleteSurvey(${s.id})">削除</button>
                <button class="btn small" onclick="duplicateSurvey(${s.id})">複製</button>
            `;
        }

        if(s.status === "終了"){
            actions += `
                <button class="btn small" onclick="openAnalysis(${s.id})">集計</button>
                <button class="btn small" onclick="duplicateSurvey(${s.id})">複製</button>
            `;
        }

        return `
            <tr>
                <td class="date-cell">
                    ${s.created}<br>
                    <span>更新: ${s.updated}</span>
                </td>

                <td class="title-cell">
                    ${escapeHtml(s.title)}
                </td>

                <td>
                    ${s.start || "未設定"}<br>
                    ～ ${s.end || "未設定"}
                </td>

                <td>${badge}</td>

                <td>
                    <strong>${s.answers}</strong> 件
                </td>

                <td class="actions-cell">
                    <div class="action-row">${actions}</div>
                </td>
            </tr>
        `;
    }).join("");
}

function escapeHtml(str){
    return String(str ?? "")
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;")
        .replace(/'/g,"&#039;");
}


/* =========================================================
   作成・編集
========================================================= */

function openCreateSurvey(){

    editorSurvey = {
        id:null,
        title:"新しいアンケート",
        created:"",
        updated:"",
        start:"",
        end:"",
        status:"下書き",
        answers:0,
        description:"",
        groups:[
            {
                id:Date.now(),
                title:"基本情報",
                questions:[
                    {
                        id:Date.now()+1,
                        text:"質問文を入力してください。",
                        type:"single",
                        required:true,
                        options:["選択肢1","選択肢2"]
                    }
                ]
            }
        ]
    };

    document.getElementById("editorTitle").textContent = "アンケート作成";

    loadEditor();

    showPage("editor");
}

function openEditor(id){

    const survey = surveys.find(s=>s.id === id);

    if(!survey) return;

    editorSurvey = JSON.parse(JSON.stringify(survey));

    currentSurveyId = id;

    document.getElementById("editorTitle").textContent =
        survey.status === "終了"
        ? "アンケート確認"
        : "アンケート編集";

    loadEditor();

    showPage("editor");
}

function loadEditor(){

    document.getElementById("editSurveyTitle").value =
        editorSurvey.title || "";

    document.getElementById("editStart").value =
        editorSurvey.start ? editorSurvey.start.replace(" ","T") : "";

    document.getElementById("editEnd").value =
        editorSurvey.end ? editorSurvey.end.replace(" ","T") : "";

    document.getElementById("editDescription").value =
        editorSurvey.description || "";

    renderBuilder();
}

function syncEditorBasic(){

    editorSurvey.title =
        document.getElementById("editSurveyTitle").value;

    editorSurvey.start =
        document.getElementById("editStart").value.replace("T"," ");

    editorSurvey.end =
        document.getElementById("editEnd").value.replace("T"," ");

    editorSurvey.description =
        document.getElementById("editDescription").value;
}

document.getElementById("editSurveyTitle").addEventListener("input",()=>{
    if(editorSurvey) editorSurvey.title =
        document.getElementById("editSurveyTitle").value;
});

function renderBuilder(){

    const builder = document.getElementById("builder");

    builder.innerHTML = editorSurvey.groups.map((g,gi)=>`

        <div class="group-card" data-group="${g.id}">

            <div class="group-head">

                <span class="drag-handle"
                      draggable="true"
                      ondragstart="dragGroup(${gi})">⠿</span>

                <input class="input group-title"
                       value="${escapeHtml(g.title)}"
                       oninput="updateGroupTitle(${gi},this.value)">

                <button class="btn small"
                        onclick="addQuestion(${gi})">
                    ＋ 質問
                </button>

                <button class="btn small danger"
                        onclick="deleteGroup(${gi})">
                    グループ削除
                </button>

            </div>

            <div class="questions"
                 data-group-index="${gi}"
                 ondragover="event.preventDefault()"
                 ondrop="dropQuestion(event,${gi})">

                ${g.questions.map((q,qi)=>renderQuestion(q,gi,qi)).join("")}

            </div>

        </div>

    `).join("");

    updateQuestionNumbers();
}

function renderQuestion(q,gi,qi){

    let optionsHtml = "";

    if(q.type === "single" || q.type === "multi"){

        optionsHtml = `
            <div>
                <label class="form-label">選択肢</label>

                ${q.options.map((op,oi)=>`
                    <div class="option-row">
                        <input class="input"
                               value="${escapeHtml(op)}"
                               oninput="updateOption(${gi},${qi},${oi},this.value)">

                        <button class="btn small danger"
                                onclick="removeOption(${gi},${qi},${oi})">
                            ×
                        </button>
                    </div>
                `).join("")}

                <button class="btn small"
                        onclick="addOption(${gi},${qi})">
                    ＋ 選択肢追加
                </button>
            </div>
        `;
    }

    let branching = "";

    if(q.type === "single"){
        branching = `
            <div style="margin-top:12px">
                <label class="form-label">分岐設定（モック）</label>
                <select class="select"
                        onchange="toast('分岐先を変更しました（モック）')">
                    <option>分岐なし（次の質問へ）</option>
                    <option>「はい」→ 次の質問</option>
                    <option>「いいえ」→ Q5</option>
                </select>
            </div>
        `;
    }

    return `
        <div class="question"
             draggable="true"
             ondragstart="dragQuestion(event,${gi},${qi})">

            <div class="question-head">

                <span class="drag-handle">⠿</span>

                <span class="question-number" id="qnum-${gi}-${qi}">
                    Q${qi+1}.
                </span>

                <input class="input question-title"
                       value="${escapeHtml(q.text)}"
                       oninput="updateQuestionText(${gi},${qi},this.value)">

                <button class="btn small danger"
                        onclick="deleteQuestion(${gi},${qi})">
                    削除
                </button>

            </div>

            <div class="question-body">

                <div class="form-grid">

                    <div class="form-group">
                        <label class="form-label">回答形式</label>

                        <select class="select"
                                onchange="changeQuestionType(${gi},${qi},this.value)">

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
                    </div>

                    <div class="form-group">
                        <label class="form-label">設定</label>

                        <label class="switch">
                            <input type="checkbox"
                                   ${q.required?"checked":""}
                                   onchange="toggleRequired(${gi},${qi},this.checked)">
                            必須回答
                        </label>
                    </div>

                </div>

                ${optionsHtml}
                ${branching}

            </div>

            <div class="question-footer">
                <span style="font-size:12px;color:#64748b">
                    グループ間ドラッグ移動にも対応するモックです
                </span>

                <button class="btn small"
                        onclick="duplicateQuestion(${gi},${qi})">
                    複製
                </button>
            </div>

        </div>
    `;
}

function updateQuestionNumbers(){

    let no = 1;

    editorSurvey.groups.forEach((g,gi)=>{
        g.questions.forEach((q,qi)=>{
            const el = document.getElementById(`qnum-${gi}-${qi}`);

            if(el){
                el.textContent = "Q" + no + ".";
            }

            no++;
        });
    });
}

function addGroup(){

    editorSurvey.groups.push({
        id:Date.now()+Math.random(),
        title:"新しいグループ",
        questions:[]
    });

    renderBuilder();

    toast("グループを追加しました");
}

function deleteGroup(gi){

    const group = editorSurvey.groups[gi];

    openModal(
        "グループ削除の確認",
        `
            <p>「${escapeHtml(group.title)}」を削除しますか？</p>
            <p style="color:#dc2626">
                含まれている質問もすべて削除されます。
            </p>
        `,
        `
            <button class="btn" onclick="closeModal()">キャンセル</button>
            <button class="btn danger" onclick="
                editorSurvey.groups.splice(${gi},1);
                renderBuilder();
                closeModal();
                toast('グループを削除しました');
            ">削除する</button>
        `
    );
}

function updateGroupTitle(gi,value){
    editorSurvey.groups[gi].title = value;
}

function addQuestion(gi){

    editorSurvey.groups[gi].questions.push({
        id:Date.now()+Math.random(),
        text:"新しい質問",
        type:"single",
        required:false,
        options:["選択肢1","選択肢2"]
    });

    renderBuilder();

    toast("質問を追加しました");
}

function deleteQuestion(gi,qi){

    editorSurvey.groups[gi].questions.splice(qi,1);

    renderBuilder();

    toast("質問を削除しました");
}

function duplicateQuestion(gi,qi){

    const copy = JSON.parse(
        JSON.stringify(editorSurvey.groups[gi].questions[qi])
    );

    copy.id = Date.now()+Math.random();

    editorSurvey.groups[gi].questions.splice(qi+1,0,copy);

    renderBuilder();

    toast("質問を複製しました");
}

function updateQuestionText(gi,qi,value){
    editorSurvey.groups[gi].questions[qi].text = value;
}

function toggleRequired(gi,qi,value){
    editorSurvey.groups[gi].questions[qi].required = value;
}

function changeQuestionType(gi,qi,value){

    editorSurvey.groups[gi].questions[qi].type = value;

    if(value === "text"){
        editorSurvey.groups[gi].questions[qi].options = [];
    }

    if((value === "single" || value === "multi") &&
       !editorSurvey.groups[gi].questions[qi].options.length){

        editorSurvey.groups[gi].questions[qi].options =
            ["選択肢1","選択肢2"];
    }

    renderBuilder();
}

function addOption(gi,qi){

    editorSurvey.groups[gi].questions[qi].options.push(
        "新しい選択肢"
    );

    renderBuilder();
}

function removeOption(gi,qi,oi){

    editorSurvey.groups[gi].questions[qi].options.splice(oi,1);

    renderBuilder();
}

function updateOption(gi,qi,oi,value){

    editorSurvey.groups[gi].questions[qi].options[oi] = value;
}

let dragData = null;

function dragQuestion(event,gi,qi){

    dragData = {
        type:"question",
        gi,
        qi
    };
}

function dropQuestion(event,targetGi){

    event.preventDefault();

    if(!dragData || dragData.type !== "question"){
        return;
    }

    const sourceGroup =
        editorSurvey.groups[dragData.gi];

    const question =
        sourceGroup.questions.splice(dragData.qi,1)[0];

    editorSurvey.groups[targetGi].questions.push(question);

    dragData = null;

    renderBuilder();

    toast("質問を移動しました");
}

function dragGroup(gi){
    dragData = {
        type:"group",
        gi
    };
}


/* =========================================================
   保存・キャンセル
========================================================= */

function saveSurvey(){

    syncEditorBasic();

    if(!editorSurvey.title.trim()){
        toast("アンケートタイトルを入力してください");
        return;
    }

    if(editorSurvey.id){

        const index =
            surveys.findIndex(s=>s.id === editorSurvey.id);

        if(index >= 0){

            surveys[index] =
                JSON.parse(JSON.stringify(editorSurvey));

            surveys[index].updated =
                new Date().toISOString().slice(0,10).replaceAll("-","/");

        }

    }else{

        editorSurvey.id = Date.now();

        editorSurvey.created =
            new Date().toISOString().slice(0,10).replaceAll("-","/");

        editorSurvey.updated =
            editorSurvey.created;

        surveys.unshift(
            JSON.parse(JSON.stringify(editorSurvey))
        );
    }

    closeModal();

    renderSurveys();

    showPage("list");

    toast("アンケートを保存しました");
}

function cancelEditor(){

    openModal(
        "変更を破棄しますか？",
        `
            <p>編集中の内容は保存されません。</p>
        `,
        `
            <button class="btn" onclick="closeModal()">戻る</button>
            <button class="btn danger" onclick="
                closeModal();
                showPage('list');
            ">変更を破棄</button>
        `
    );
}


/* =========================================================
   プレビュー
========================================================= */

function openPreview(){

    syncEditorBasic();

    const survey = editorSurvey;

    let device = "pc";

    function renderPreview(){

        const html = survey.groups.map(g=>`

            <div style="margin-bottom:30px">

                <h3 style="
                    border-left:4px solid #2563eb;
                    padding-left:10px;
                ">
                    ${escapeHtml(g.title)}
                </h3>

                ${g.questions.map((q,qi)=>{

                    let input = "";

                    if(q.type === "single"){
                        input = q.options.map(op=>`
                            <label class="preview-option">
                                <input type="radio" name="preview-${q.id}">
                                ${escapeHtml(op)}
                            </label>
                        `).join("");
                    }

                    if(q.type === "multi"){
                        input = q.options.map(op=>`
                            <label class="preview-option">
                                <input type="checkbox">
                                ${escapeHtml(op)}
                            </label>
                        `).join("");
                    }

                    if(q.type === "text"){
                        input = `
                            <textarea class="textarea"
                                      rows="5"
                                      placeholder="回答を入力してください"></textarea>
                        `;
                    }

                    return `
                        <div class="preview-question">

                            <strong>
                                Q${qi+1}. ${escapeHtml(q.text)}
                                ${q.required
                                    ? `<span class="required"> *</span>`
                                    : ""}
                            </strong>

                            <div style="margin-top:12px">
                                ${input}
                            </div>

                        </div>
                    `;

                }).join("")}

            </div>

        `).join("");

        const wrapperClass =
            device === "mobile"
            ? "preview-mobile"
            : "preview-shell";

        document.getElementById("modalBody").innerHTML = `
            <div style="display:flex;justify-content:center;gap:8px;margin-bottom:15px">
                <button class="btn small ${device==="pc"?"primary":""}"
                        onclick="previewDevice('pc')">
                    PC表示
                </button>

                <button class="btn small ${device==="mobile"?"primary":""}"
                        onclick="previewDevice('mobile')">
                    スマートフォン表示
                </button>
            </div>

            <div class="${wrapperClass}">
                <div class="preview-inner">

                    <h1 style="font-size:24px">
                        ${escapeHtml(survey.title)}
                    </h1>

                    <p style="color:#64748b">
                        ${escapeHtml(survey.description || "")}
                    </p>

                    ${html}

                    <button class="btn primary"
                            style="width:100%"
                            onclick="
                                alert('これはプレビュー表示のため送信されません');
                            ">
                        回答を送信する
                    </button>

                </div>
            </div>
        `;

        document.getElementById("modalTitle").textContent =
            "アンケートプレビュー";

        document.getElementById("modalFoot").innerHTML = `
            <button class="btn" onclick="closeModal()">閉じる</button>
        `;

        document.getElementById("modalBg").classList.add("show");
    }

    window.previewDevice = function(d){
        device = d;
        renderPreview();
    };

    renderPreview();
}


/* =========================================================
   ステータス操作
========================================================= */

function stopSurvey(id){

    openModal(
        "アンケート停止",
        `
            <p>このアンケートを停止しますか？</p>
            <p>停止後は回答者から回答できなくなります。</p>
        `,
        `
            <button class="btn" onclick="closeModal()">キャンセル</button>
            <button class="btn warning" onclick="
                changeSurveyStatus(${id},'終了');
                closeModal();
            ">
                停止する
            </button>
        `
    );
}

function changeSurveyStatus(id,status){

    const survey = surveys.find(s=>s.id===id);

    if(!survey) return;

    survey.status = status;

    renderSurveys();

    toast(
        status === "終了"
        ? "アンケートを停止しました"
        : "アンケートを再開しました"
    );
}

function duplicateSurvey(id){

    const survey = surveys.find(s=>s.id===id);

    if(!survey) return;

    const copy =
        JSON.parse(JSON.stringify(survey));

    copy.id = Date.now();

    copy.title = survey.title + "（複製）";

    copy.status = "下書き";

    copy.answers = 0;

    copy.created =
        new Date().toISOString().slice(0,10).replaceAll("-","/");

    copy.updated = copy.created;

    surveys.unshift(copy);

    renderSurveys();

    toast("アンケートを複製しました。下書きとして追加しました。");
}

function deleteSurvey(id){

    openModal(
        "アンケート削除",
        `<p>このアンケートを削除しますか？</p>
         <p style="color:#dc2626">削除は論理削除を想定したモックです。</p>`,
        `
            <button class="btn" onclick="closeModal()">キャンセル</button>
            <button class="btn danger" onclick="
                surveys = surveys.filter(s=>s.id!==${id});
                renderSurveys();
                closeModal();
                toast('アンケートを削除しました');
            ">
                削除する
            </button>
        `
    );
}


/* =========================================================
   集計
========================================================= */

function openAnalysis(id){

    currentSurveyId = id;

    const survey = surveys.find(s=>s.id===id);

    if(!survey) return;

    document.getElementById("analysisTitle").textContent =
        survey.title;

    renderQuestionFilter(survey);

    renderAnalysisQuestions(survey);

    renderAnswers();

    showPage("analysis");
}

function renderQuestionFilter(survey){

    let no = 1;

    document.getElementById("questionFilter").innerHTML =
        survey.groups.flatMap(g=>g.questions).map(q=>{

            const current = no++;

            return `
                <label class="filter-question">
                    <input type="checkbox"
                           class="analysis-q"
                           data-q="${q.id}"
                           checked
                           onchange="renderAnalysisQuestions()">
                    Q${current}. ${escapeHtml(q.text)}
                    <span class="badge info">${q.type==="text"?"テキスト":q.type==="multi"?"複数選択":"単一選択"}</span>
                </label>
            `;

        }).join("");
}

function toggleAllQuestions(value){

    document.querySelectorAll(".analysis-q").forEach(el=>{
        el.checked = value;
    });

    renderAnalysisQuestions();
}

function renderAnalysisQuestions(){

    const survey =
        surveys.find(s=>s.id===currentSurveyId);

    if(!survey) return;

    const selected = [...document.querySelectorAll(".analysis-q")]
        .filter(el=>el.checked)
        .map(el=>Number(el.dataset.q));

    const questions =
        survey.groups.flatMap(g=>g.questions);

    const target =
        selected.length
        ? questions.filter(q=>selected.includes(q.id))
        : [];

    if(!target.length){

        document.getElementById("analysisQuestions").innerHTML = `
            <div class="card">
                <div class="empty">
                    集計対象の設問を選択してください。
                </div>
            </div>
        `;

        return;
    }

    document.getElementById("analysisQuestions").innerHTML =
        target.map(q=>renderAnalysisCard(q)).join("");
}

function renderAnalysisCard(q){

    if(q.type === "text"){

        return `
            <div class="card" style="margin-bottom:16px">
                <div class="card-header">
                    <strong>${escapeHtml(q.text)}</strong>
                    <span class="badge info">自由記述</span>
                </div>

                <div class="card-body">

                    <div class="timeline">

                        <div class="timeline-item">
                            <strong>山田 太郎 / 株式会社サンプル</strong>
                            <div>非常に使いやすく、今後も利用したいです。</div>
                        </div>

                        <div class="timeline-item">
                            <strong>佐藤 花子 / 株式会社ABC</strong>
                            <div>サポート対応が丁寧で助かりました。</div>
                        </div>

                        <div class="timeline-item">
                            <strong>高橋 美咲 / 株式会社XYZ</strong>
                            <div>スマートフォンからも使いやすくしてほしいです。</div>
                        </div>

                    </div>

                </div>
            </div>
        `;
    }

    const data = [
        ["非常に満足",42],
        ["満足",35],
        ["普通",15],
        ["やや不満",6],
        ["不満",2]
    ];

    const total = data.reduce((a,b)=>a+b[1],0);

    return `
        <div class="card" style="margin-bottom:16px">

            <div class="card-header">
                <strong>${escapeHtml(q.text)}</strong>

                <span class="badge info">
                    ${q.type==="multi"?"複数選択":"単一選択"}
                </span>
            </div>

            <div class="card-body">

                <div class="bar-chart">

                    ${data.map(item=>{

                        const percent =
                            Math.round(item[1] / total * 100);

                        return `
                            <div class="bar-row">
                                <span>${item[0]}</span>

                                <div class="bar">
                                    <div style="width:${percent}%"></div>
                                </div>

                                <span>
                                    ${item[1]}件
                                    <small>(${percent}%)</small>
                                </span>
                            </div>
                        `;

                    }).join("")}

                </div>

                <div style="margin-top:15px">
                    <button class="btn small"
                            onclick="showOtherAnswers()">
                        「その他」の回答を見る
                    </button>
                </div>

            </div>
        </div>
    `;
}

function showOtherAnswers(){

    openModal(
        "「その他」回答",
        `
            <div class="timeline">

                <div class="timeline-item">
                    <strong>田中 次郎 / Web回答者</strong>
                    <div style="margin-top:5px">
                        他社サービスとの連携機能が欲しいです。
                    </div>
                </div>

                <div class="timeline-item">
                    <strong>高橋 美咲 / 株式会社XYZ</strong>
                    <div style="margin-top:5px">
                        操作マニュアルを増やしてほしいです。
                    </div>
                </div>

            </div>
        `,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}

function renderAnswers(){

    const keyword =
        (document.getElementById("answerSearch")?.value || "")
        .toLowerCase();

    const data = [
        {
            company:"株式会社サンプル",
            name:"山田 太郎",
            date:"2026/08/06 11:25",
            summary:"非常に満足 / 今後も利用したい"
        },
        {
            company:"株式会社ABC",
            name:"佐藤 花子",
            date:"2026/08/07 09:12",
            summary:"満足 / サポート対応が丁寧"
        },
        {
            company:"株式会社XYZ",
            name:"高橋 美咲",
            date:"2026/08/09 15:31",
            summary:"普通 / 操作マニュアルを増やしてほしい"
        },
        {
            company:"Web回答者",
            name:"田中 次郎",
            date:"2026/08/10 17:04",
            summary:"満足 / 他社サービスとの連携機能が欲しい"
        }
    ];

    const filtered = data.filter(a=>
        !keyword ||
        (a.company+a.name).toLowerCase().includes(keyword)
    );

    document.getElementById("answerTable").innerHTML =
        filtered.map((a,i)=>`

            <tr>
                <td>
                    <strong>${a.company}</strong><br>
                    ${a.name}
                </td>

                <td>${a.date}</td>

                <td>${a.summary}</td>

                <td>
                    <button class="btn small"
                            onclick="showAnswerDetail(${i})">
                        全回答を表示
                    </button>
                </td>
            </tr>

        `).join("");
}

function showAnswerDetail(index){

    openModal(
        "回答詳細",
        `
            <div class="form-group">
                <label class="form-label">回答者</label>
                <div>山田 太郎 / 株式会社サンプル</div>
            </div>

            <div class="form-group">
                <label class="form-label">Q1. サービス全体の満足度</label>
                <div class="alert success">非常に満足</div>
            </div>

            <div class="form-group">
                <label class="form-label">Q2. 今後も利用したいですか？</label>
                <div class="alert success">はい</div>
            </div>

            <div class="form-group">
                <label class="form-label">Q3. ご意見</label>
                <div class="mail-preview">
                    非常に使いやすく、今後も利用したいです。
                </div>
            </div>
        `,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}

function downloadCSV(){

    const csv =
        "\uFEFF回答ID,回答日時,顧客ID,会社名,氏名,設問1,設問2\n" +
        "1,2026/08/06 11:25,1,株式会社サンプル,山田 太郎,非常に満足,はい\n" +
        "2,2026/08/07 09:12,2,株式会社ABC,佐藤 花子,満足,はい\n";

    const blob =
        new Blob([csv],{type:"text/csv;charset=utf-8;"});

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement("a");

    a.href = url;
    a.download = "survey_answers.csv";
    a.click();

    URL.revokeObjectURL(url);

    toast("CSVを出力しました");
}

function exportPDF(){

    openModal(
        "PDF出力",
        `
            <div class="alert success">
                集計レポートのPDF出力を実行するモックです。
            </div>

            <p>
                現在選択されている設問、サマリー、グラフを
                PDFレポートとして出力する想定です。
            </p>
        `,
        `
            <button class="btn" onclick="closeModal()">閉じる</button>
            <button class="btn primary"
                    onclick="closeModal();toast('PDF出力を実行しました（モック）')">
                PDF出力
            </button>
        `
    );
}


/* =========================================================
   顧客送信
========================================================= */

function openSend(id){

    currentSendSurveyId = id;

    const survey = surveys.find(s=>s.id===id);

    document.getElementById("sendSurveyTitle").textContent =
        survey ? survey.title : "顧客選択・メール送信";

    renderCustomers();
    renderSendLogs();

    showPage("mail-send");
}

function customerStatus(c){

    if(c.web && c.answered)
        return "未登録";

    if(c.answered)
        return "回答済み";

    if(c.sent)
        return "未回答";

    return "未送信";
}

function renderCustomers(){

    const keyword =
        (document.getElementById("customerSearch")?.value || "")
        .toLowerCase();

    const filter =
        document.getElementById("customerFilter")?.value || "";

    const list =
        customers.filter(c=>{

            const text =
                (c.company+c.name+c.email).toLowerCase();

            const matchKeyword =
                !keyword || text.includes(keyword);

            const status =
                customerStatus(c);

            const matchFilter =
                !filter ||
                (filter==="未回答" && status==="未回答") ||
                status===filter;

            return matchKeyword && matchFilter;
        });

    document.getElementById("customerTable").innerHTML =
        list.map(c=>{

            const status = customerStatus(c);

            let answerBadge = "";

            if(status === "回答済み")
                answerBadge =
                    `<span class="badge public">回答済み</span>`;

            if(status === "未回答")
                answerBadge =
                    `<span class="badge unanswered">送信済み / 未回答</span>`;

            if(status === "未送信")
                answerBadge =
                    `<span class="badge draft">未送信</span>`;

            if(status === "未登録")
                answerBadge =
                    `<span class="badge unregistered">未登録</span>`;

            let kBadge =
                c.registered
                ? `<span class="badge registered">✓ kintone登録完了</span>`
                : `
                    <span class="badge unregistered">未登録</span>
                    <button class="btn small"
                            onclick="completeKintone(${c.id})">
                        kintone登録完了
                    </button>
                `;

            return `
                <tr>

                    <td>
                        ${
                            c.web
                            ? `<span style="color:#94a3b8">対象外</span>`
                            : `<input type="checkbox"
                                      class="customer-check"
                                      data-id="${c.id}"
                                      ${c.selected?"checked":""}
                                      onchange="toggleCustomer(${c.id},this.checked)">`
                        }
                    </td>

                    <td>
                        <strong>${c.company}</strong><br>
                        ${c.name}<br>
                        <span style="color:#64748b">${c.email}</span><br>
                        <span style="color:#64748b">${c.phone}</span><br>
                        <span style="color:#64748b">${c.address}</span>
                    </td>

                    <td>
                        ${
                            c.sent
                            ? `
                                最終送信：
                                <strong>${c.sentAt}</strong><br>
                                送信回数：${c.count}回
                                <br>
                                <button class="btn small"
                                        onclick="showCustomerMail(${c.id})">
                                    送信文を確認
                                </button>
                            `
                            : "送信未実施"
                        }
                    </td>

                    <td>${answerBadge}</td>

                    <td>${kBadge}</td>

                </tr>
            `;
        }).join("");
}

function toggleCustomer(id,value){

    const c = customers.find(c=>c.id===id);

    if(c){
        c.selected = value;
    }
}

function toggleAllCustomers(value){

    customers.forEach(c=>{
        if(!c.web){
            c.selected = value;
        }
    });

    renderCustomers();
}

function selectVisibleCustomers(){

    const keyword =
        (document.getElementById("customerSearch")?.value || "")
        .toLowerCase();

    customers.forEach(c=>{
        if(c.web) return;

        const text =
            (c.company+c.name+c.email).toLowerCase();

        if(!keyword || text.includes(keyword)){
            c.selected = true;
        }
    });

    renderCustomers();
}

function clearCustomers(){

    customers.forEach(c=>c.selected=false);

    renderCustomers();
}

function insertMailVariable(variable){

    const textarea =
        document.getElementById("mailBody");

    textarea.value += "\n" + variable;

    textarea.focus();
}

function sendSelectedCustomers(){

    const selected =
        customers.filter(c=>c.selected && !c.web);

    if(!selected.length){
        toast("送信対象を選択してください");
        return;
    }

    const alreadySent =
        selected.filter(c=>c.sent);

    if(alreadySent.length){

        openModal(
            "再送確認",
            `
                <div class="alert warning">
                    既に送信済みの宛先が含まれています。
                    再送しますか？
                </div>

                <p>
                    対象：${selected.length}件<br>
                    うち既送信：${alreadySent.length}件
                </p>
            `,
            `
                <button class="btn" onclick="closeModal()">
                    キャンセル
                </button>

                <button class="btn warning"
                        onclick="executeSend(true)">
                    再送する
                </button>
            `
        );

    }else{

        openModal(
            "一括送信確認",
            `
                <p>
                    <strong>${selected.length}件</strong>
                    にメールを送信します。
                </p>

                <div class="mail-preview">
                    ${escapeHtml(
                        document.getElementById("mailSubject").value
                    )}
                </div>
            `,
            `
                <button class="btn" onclick="closeModal()">キャンセル</button>
                <button class="btn primary" onclick="executeSend(false)">
                    送信する
                </button>
            `
        );
    }
}

function executeSend(resend){

    const subject =
        document.getElementById("mailSubject").value;

    const body =
        document.getElementById("mailBody").value;

    const selected =
        customers.filter(c=>c.selected && !c.web);

    const now =
        new Date().toLocaleString("ja-JP");

    selected.forEach(c=>{

        c.sent = true;
        c.sentAt = now;
        c.count++;

        c.selected = false;
    });

    sendLogs.unshift({
        date:now,
        type:resend ? "リマインド送信" : "初回一括送信",
        count:selected.length,
        subject:resend
            ? "【再送】" + subject
            : subject,
        user:"管理者"
    });

    closeModal();

    renderCustomers();
    renderSendLogs();

    toast(
        selected.length + "件に" +
        (resend ? "再送" : "送信") +
        "しました（モック）"
    );
}

function renderSendLogs(){

    document.getElementById("sendLogTable").innerHTML =
        sendLogs.map(log=>`

            <tr>
                <td>${log.date}</td>
                <td>${log.type}</td>
                <td>${log.count}件</td>
                <td>${escapeHtml(log.subject)}</td>
                <td>${log.user}</td>
            </tr>

        `).join("");
}

function showSendHistory(){

    openModal(
        "送信履歴",
        `
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>日時</th>
                            <th>種別</th>
                            <th>件数</th>
                            <th>件名</th>
                            <th>実行者</th>
                        </tr>
                    </thead>

                    <tbody>
                        ${sendLogs.map(log=>`
                            <tr>
                                <td>${log.date}</td>
                                <td>${log.type}</td>
                                <td>${log.count}件</td>
                                <td>${escapeHtml(log.subject)}</td>
                                <td>${log.user}</td>
                            </tr>
                        `).join("")}
                    </tbody>
                </table>
            </div>
        `,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}

function showCustomerMail(id){

    const c =
        customers.find(c=>c.id===id);

    if(!c) return;

    const subject =
        document.getElementById("mailSubject").value;

    const body =
        document.getElementById("mailBody").value
        .replaceAll("{顧客名}",c.name)
        .replaceAll(
            "{アンケートURL}",
            "https://survey.example.jp/a/individual/" + c.id
        );

    openModal(
        "送信文を確認",
        `
            <div class="form-group">
                <label class="form-label">送信先</label>
                <div>
                    ${c.name} / ${c.email}
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">件名</label>
                <div class="mail-preview">
                    ${escapeHtml(subject)}
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">本文</label>
                <div class="mail-preview">
                    ${escapeHtml(body)}
                </div>
            </div>
        `,
        `<button class="btn" onclick="closeModal()">閉じる</button>`
    );
}

function completeKintone(id){

    const c =
        customers.find(c=>c.id===id);

    if(!c) return;

    c.registered = true;

    renderCustomers();

    toast("kintone登録完了に変更しました");
}


/* =========================================================
   kintone
========================================================= */

function loadKintoneFields(){

    document.getElementById("kDot").className =
        "dot green";

    document.getElementById("kStatus").textContent =
        "項目情報を取得しました（モック）";

    renderMapping();

    toast("kintone項目一覧を取得しました");
}

function renderMapping(){

    const items = [
        ["company","会社名"],
        ["name","氏名"],
        ["email","メールアドレス"],
        ["department","部署名"],
        ["phone","電話番号"],
        ["address","住所"]
    ];

    document.getElementById("mappingArea").innerHTML = `
        <div class="table-wrap">
            <table style="min-width:700px">
                <thead>
                    <tr>
                        <th>システム項目</th>
                        <th>用途</th>
                        <th>kintone項目</th>
                    </tr>
                </thead>

                <tbody>
                    ${items.map(item=>{

                        const key = item[0];
                        const label = item[1];

                        if(key === "address"){

                            return `
                                <tr>
                                    <td><strong>${label}</strong></td>
                                    <td>郵便番号・所在地・住所</td>
                                    <td>
                                        <select multiple
                                                class="select"
                                                style="height:110px"
                                                onchange="updateAddressMapping(this)">
                                            ${kintoneFields.map(f=>`
                                                <option
                                                    ${mapping.address.includes(f.label)?"selected":""}>
                                                    ${f.label}
                                                </option>
                                            `).join("")}
                                        </select>
                                        <small>
                                            Ctrl / Commandで複数選択できます
                                        </small>
                                    </td>
                                </tr>
                            `;
                        }

                        return `
                            <tr>
                                <td><strong>${label}</strong></td>
                                <td>顧客情報として利用</td>
                                <td>
                                    <select class="select"
                                            onchange="mapping.${key}=this.value">

                                        <option value="">選択してください</option>

                                        ${kintoneFields.map(f=>`
                                            <option
                                                ${mapping[key]===f.label?"selected":""}>
                                                ${f.label}
                                            </option>
                                        `).join("")}

                                    </select>
                                </td>
                            </tr>
                        `;

                    }).join("")}
                </tbody>
            </table>
        </div>
    `;
}

function updateAddressMapping(select){

    mapping.address =
        [...select.selectedOptions].map(o=>o.value);
}

function syncKintone(){

    document.getElementById("kDot").className =
        "dot green";

    document.getElementById("kStatus").textContent =
        "顧客情報を同期しました（モック）";

    toast("顧客情報を同期しました");
}

function saveKintone(){

    toast("kintone連携設定を保存しました（モック）");
}


/* =========================================================
   メールサーバ
========================================================= */

function saveMailServer(){

    document.getElementById("mailDot").className =
        "dot green";

    document.getElementById("mailStatus").textContent =
        "設定済み";

    toast("メールサーバ設定を保存しました");
}

function testMailConnection(){

    document.getElementById("mailDot").className =
        "dot green";

    document.getElementById("mailStatus").textContent =
        "接続確認済み";

    toast("メールサーバに接続できました（モック）");
}

function openTestMail(){

    openModal(
        "テストメール送信",
        `
            <div class="form-group">
                <label class="form-label">送信先</label>
                <input id="testMailTo"
                       class="input"
                       value="admin@example.jp">
            </div>

            <div class="alert info">
                実際のメールは送信されません。
            </div>
        `,
        `
            <button class="btn" onclick="closeModal()">キャンセル</button>
            <button class="btn primary"
                    onclick="
                        const mail=document.getElementById('testMailTo').value;
                        closeModal();
                        toast(mail+'へテストメールを送信しました（モック）');
                    ">
                テスト送信
            </button>
        `
    );
}


/* =========================================================
   回答者向け画面
========================================================= */

function openAnswerPage(id){

    const survey =
        surveys.find(s=>s.id===id);

    if(!survey) return;

    currentSurveyId = id;

    currentAnswerQuestion = 0;
    answerMode = "input";
    answerValues = {};

    renderAnswerPage();

    showPage("answer");
}

function getAnswerQuestions(){

    const survey =
        surveys.find(s=>s.id===currentSurveyId);

    if(!survey) return [];

    return survey.groups.flatMap(g=>g.questions);
}

function renderAnswerPage(){

    const survey =
        surveys.find(s=>s.id===currentSurveyId);

    if(!survey) return;

    const questions = getAnswerQuestions();

    const total = questions.length;

    if(answerMode === "complete"){

        document.getElementById("answerApp").innerHTML = `
            <div class="card">
                <div class="card-body"
                     style="text-align:center;padding:70px 25px">

                    <div style="
                        width:70px;
                        height:70px;
                        margin:auto;
                        border-radius:50%;
                        background:#dcfce7;
                        color:#16a34a;
                        display:flex;
                        align-items:center;
                        justify-content:center;
                        font-size:35px;
                    ">✓</div>

                    <h2>回答ありがとうございました</h2>

                    <p style="color:#64748b">
                        アンケートの回答を受け付けました。
                    </p>

                    <button class="btn primary"
                            onclick="showPage('list')">
                        終了
                    </button>

                </div>
            </div>
        `;

        return;
    }

    if(answerMode === "confirm"){

        renderAnswerConfirm();

        return;
    }

    const q = questions[currentAnswerQuestion];

    const percent =
        ((currentAnswerQuestion+1)/total)*100;

    let input = "";

    if(q.type === "single"){

        input = q.options.map(op=>`
            <label class="answer-choice">
                <input type="radio"
                       name="answer"
                       value="${escapeHtml(op)}"
                       ${answerValues[q.id]===op?"checked":""}
                       onchange="setAnswer('${q.id}',this.value)">
                ${escapeHtml(op)}
            </label>
        `).join("");
    }

    if(q.type === "multi"){

        const selected =
            answerValues[q.id] || [];

        input = q.options.map(op=>`
            <label class="answer-choice">
                <input type="checkbox"
                       value="${escapeHtml(op)}"
                       ${selected.includes(op)?"checked":""}
                       onchange="toggleMultiAnswer('${q.id}',this.value,this.checked)">
                ${escapeHtml(op)}
            </label>
        `).join("");
    }

    if(q.type === "text"){

        input = `
            <textarea class="textarea"
                      rows="7"
                      placeholder="回答を入力してください"
                      oninput="setAnswer('${q.id}',this.value)"
            >${escapeHtml(answerValues[q.id] || "")}</textarea>
        `;
    }

    document.getElementById("answerApp").innerHTML = `

        <div class="card">

            <div class="card-body">

                <h1 style="margin-top:0">
                    ${escapeHtml(survey.title)}
                </h1>

                <p style="color:#64748b">
                    ${escapeHtml(survey.description)}
                </p>

                <div class="answer-progress">
                    <div style="width:${percent}%"></div>
                </div>

                <div style="font-size:12px;color:#64748b">
                    ${currentAnswerQuestion+1} / ${total}
                </div>

                <div class="preview-question">

                    <h2 style="font-size:19px">
                        ${escapeHtml(q.text)}
                        ${q.required
                            ? `<span class="required"> *</span>`
                            : ""}
                    </h2>

                    <div style="margin-top:20px">
                        ${input}
                    </div>

                </div>

                <div class="answer-nav">

                    ${
                        currentAnswerQuestion > 0
                        ? `
                            <button class="btn"
                                    onclick="answerPrev()">
                                ← 戻る
                            </button>
                        `
                        : `<span></span>`
                    }

                    ${
                        currentAnswerQuestion < total-1
                        ? `
                            <button class="btn primary"
                                    onclick="answerNext()">
                                次へ →
                            </button>
                        `
                        : `
                            <button class="btn primary"
                                    onclick="goAnswerConfirm()">
                                回答を確認する
                            </button>
                        `
                    }

                </div>

            </div>
        </div>
    `;
}

function setAnswer(qid,value){
    answerValues[qid] = value;
}

function toggleMultiAnswer(qid,value,checked){

    if(!Array.isArray(answerValues[qid])){
        answerValues[qid] = [];
    }

    if(checked){
        answerValues[qid].push(value);
    }else{
        answerValues[qid] =
            answerValues[qid].filter(v=>v!==value);
    }
}

function answerNext(){

    const questions = getAnswerQuestions();

    const q = questions[currentAnswerQuestion];

    if(q.required){

        const value = answerValues[q.id];

        const invalid =
            value === undefined ||
            value === "" ||
            (Array.isArray(value) && value.length===0);

        if(invalid){

            toast("必須項目に回答してください");

            return;
        }
    }

    currentAnswerQuestion++;

    renderAnswerPage();
}

function answerPrev(){

    if(currentAnswerQuestion > 0){
        currentAnswerQuestion--;
        renderAnswerPage();
    }
}

function goAnswerConfirm(){

    const questions = getAnswerQuestions();

    const q = questions[currentAnswerQuestion];

    if(q.required){

        const value = answerValues[q.id];

        const invalid =
            value === undefined ||
            value === "" ||
            (Array.isArray(value) && value.length===0);

        if(invalid){
            toast("必須項目に回答してください");
            return;
        }
    }

    answerMode = "confirm";

    renderAnswerPage();
}

function renderAnswerConfirm(){

    const questions = getAnswerQuestions();

    document.getElementById("answerApp").innerHTML = `

        <div class="card">

            <div class="card-header">
                <strong>回答内容の確認</strong>
            </div>

            <div class="card-body">

                <div class="alert info">
                    回答内容をご確認ください。
                    修正する場合は「戻る」で回答画面へ戻れます。
                </div>

                ${questions.map((q,i)=>{

                    let value =
                        answerValues[q.id];

                    if(Array.isArray(value)){
                        value = value.join("、");
                    }

                    return `
                        <div style="
                            padding:15px 0;
                            border-bottom:1px solid #e2e8f0;
                        ">
                            <strong>
                                Q${i+1}. ${escapeHtml(q.text)}
                            </strong>

                            <div style="
                                margin-top:8px;
                                white-space:pre-wrap;
                            ">
                                ${escapeHtml(value || "未回答")}
                            </div>
                        </div>
                    `;

                }).join("")}

                <div class="answer-nav">

                    <button class="btn"
                            onclick="
                                answerMode='input';
                                currentAnswerQuestion=0;
                                renderAnswerPage();
                            ">
                        ← 修正する
                    </button>

                    <button class="btn primary"
                            onclick="submitAnswer()">
                        回答を送信する
                    </button>

                </div>

            </div>
        </div>
    `;
}

function submitAnswer(){

    openModal(
        "回答送信確認",
        `
            <p>
                入力した内容を送信します。
            </p>

            <div class="alert warning">
                送信後はアンケートの設定によっては
                再回答できない場合があります。
            </div>
        `,
        `
            <button class="btn" onclick="closeModal()">
                キャンセル
            </button>

            <button class="btn primary"
                    onclick="
                        closeModal();
                        answerMode='complete';
                        renderAnswerPage();
                        toast('回答を送信しました');
                    ">
                回答を送信する
            </button>
        `
    );
}


/* =========================================================
   初期化
========================================================= */

renderSurveys();
renderMapping();

</script>

</body>
</html>
<?php
/*
 * アンケート管理システム モック
 * Apache 2.4 + PHP 8.5
 * index.php 1ファイル構成
 *
 * DB / kintone API / SMTP / 認証 / 実メール送信は未実装。
 * JavaScriptのサンプルデータで画面・操作・状態変化を再現する。
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
    --gray:#64748b;
    --border:#dbe1ea;
    --bg:#f5f7fb;
    --card:#fff;
    --text:#172033;
}

*{box-sizing:border-box}

body{
    margin:0;
    font-family:
        -apple-system,BlinkMacSystemFont,"Segoe UI",
        "Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;
    color:var(--text);
    background:var(--bg);
}

button,input,textarea,select{
    font:inherit;
}

button{
    cursor:pointer;
}

.admin-header{
    height:64px;
    background:#172033;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    position:sticky;
    top:0;
    z-index:50;
}

.admin-logo{
    font-weight:700;
    margin-right:35px;
    white-space:nowrap;
}

.admin-nav{
    display:flex;
    gap:4px;
    flex:1;
}

.admin-nav button{
    background:transparent;
    color:#cbd5e1;
    border:0;
    padding:10px 14px;
    border-radius:7px;
}

.admin-nav button:hover,
.admin-nav button.active{
    background:#29354a;
    color:#fff;
}

.logout{
    color:#cbd5e1;
    font-size:13px;
}

.container{
    max-width:1400px;
    margin:0 auto;
    padding:28px;
}

.page-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:22px;
}

.page-title h1{
    margin:0;
    font-size:26px;
}

.page-title p{
    color:var(--gray);
    margin:7px 0 0;
    font-size:13px;
}

.btn{
    border:1px solid var(--border);
    background:#fff;
    color:#263247;
    padding:9px 15px;
    border-radius:7px;
    font-weight:600;
}

.btn:hover{
    background:#f8fafc;
}

.btn-primary{
    color:#fff;
    background:var(--primary);
    border-color:var(--primary);
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-danger{
    color:#fff;
    background:var(--danger);
    border-color:var(--danger);
}

.btn-success{
    color:#fff;
    background:var(--success);
    border-color:var(--success);
}

.btn-small{
    padding:6px 10px;
    font-size:12px;
}

.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:10px;
    box-shadow:0 2px 7px rgba(15,23,42,.03);
}

.toolbar{
    padding:16px;
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    margin-bottom:15px;
}

.search{
    min-width:280px;
    padding:9px 12px;
    border:1px solid var(--border);
    border-radius:7px;
}

select,
input[type=text],
input[type=email],
input[type=password],
input[type=datetime-local],
input[type=number],
textarea{
    border:1px solid #cfd7e3;
    border-radius:7px;
    padding:9px 11px;
    background:#fff;
    color:var(--text);
}

textarea{
    resize:vertical;
    min-height:90px;
    width:100%;
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
    padding:13px 14px;
    border-bottom:1px solid #e8edf3;
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    color:#475569;
    font-size:12px;
    white-space:nowrap;
}

td{
    font-size:13px;
}

.status{
    display:inline-flex;
    padding:4px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
}

.status-draft{
    background:#eef2f7;
    color:#64748b;
}

.status-public{
    background:#dcfce7;
    color:#15803d;
}

.status-stop{
    background:#fef3c7;
    color:#a16207;
}

.status-end{
    background:#fee2e2;
    color:#b91c1c;
}

.action-group{
    display:flex;
    gap:5px;
    flex-wrap:wrap;
}

.form-card{
    padding:22px;
    margin-bottom:20px;
}

.edit-actions{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:20px;
}

.edit-actions-left,
.edit-actions-right{
    display:flex;
    gap:8px;
    align-items:center;
}

.state-control{
    display:flex;
    align-items:center;
    gap:8px;
    padding:8px 11px;
    border:1px solid var(--border);
    background:#fff;
    border-radius:7px;
}

.section-title{
    font-size:18px;
    margin:0 0 17px;
    padding-bottom:11px;
    border-bottom:1px solid #e7ebf0;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}

.form-group{
    display:flex;
    flex-direction:column;
    gap:7px;
}

.form-group.full{
    grid-column:1 / -1;
}

.form-label{
    font-size:13px;
    font-weight:700;
}

.radio-row{
    display:flex;
    gap:22px;
    padding:9px 0;
}

.group{
    border:1px solid #d9e0e9;
    border-radius:10px;
    background:#fafcff;
    margin-bottom:16px;
    overflow:hidden;
}

.group-header{
    padding:13px 15px;
    background:#f1f5f9;
    display:flex;
    align-items:center;
    gap:10px;
}

.drag-handle{
    cursor:grab;
    color:#94a3b8;
    font-size:18px;
}

.group-title{
    flex:1;
    font-weight:700;
    border:0!important;
    background:transparent!important;
    font-size:16px;
}

.question-list{
    padding:10px;
}

.question{
    background:#fff;
    border:1px solid #e0e6ee;
    border-radius:8px;
    padding:14px;
    margin-bottom:9px;
}

.question:last-child{
    margin-bottom:0;
}

.question-top{
    display:flex;
    align-items:center;
    gap:10px;
}

.question-number{
    min-width:65px;
    font-weight:800;
    color:var(--primary);
}

.question-text{
    flex:1;
}

.question-controls{
    display:flex;
    gap:5px;
}

.question-options{
    margin:13px 0 0 75px;
}

.option-row{
    display:flex;
    gap:6px;
    margin:6px 0;
}

.option-row input{
    flex:1;
}

.add-option{
    margin-top:5px;
}

.question-bottom{
    margin:12px 0 0 75px;
    padding-top:10px;
    border-top:1px solid #eef2f6;
    display:flex;
    gap:20px;
    align-items:center;
    flex-wrap:wrap;
}

.condition-box{
    margin:10px 0 0 75px;
    padding:10px;
    background:#f8fafc;
    border-radius:6px;
    display:none;
}

.add-question{
    width:100%;
    border:1px dashed #aeb9c8;
    background:#fff;
    padding:10px;
    border-radius:7px;
    color:var(--primary);
    margin-top:10px;
}

.add-group{
    width:100%;
    padding:13px;
    border:1px dashed #94a3b8;
    background:#fff;
    color:var(--primary);
    border-radius:8px;
    font-weight:700;
}

.preview-frame{
    max-width:760px;
    margin:0 auto;
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:30px;
}

.preview-mobile{
    max-width:390px;
}

.preview-note{
    background:#eff6ff;
    color:#1d4ed8;
    padding:11px;
    border-radius:7px;
    margin-bottom:20px;
    font-size:13px;
}

.answer-question{
    margin:0 0 25px;
}

.answer-question h3{
    font-size:16px;
    margin-bottom:10px;
}

.required{
    color:#dc2626;
    font-size:12px;
    margin-left:6px;
}

.choice{
    display:flex;
    gap:9px;
    padding:11px;
    margin:6px 0;
    border:1px solid #e0e6ee;
    border-radius:7px;
    align-items:center;
}

.choice:hover{
    background:#f8fafc;
}

.answer-actions{
    display:flex;
    justify-content:space-between;
    margin-top:30px;
}

.summary-grid{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
    margin-bottom:20px;
}

.summary-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:9px;
    padding:17px;
}

.summary-card .label{
    font-size:12px;
    color:var(--gray);
}

.summary-card .value{
    font-size:25px;
    font-weight:800;
    margin-top:5px;
}

.bar{
    height:10px;
    border-radius:10px;
    background:#e5e7eb;
    overflow:hidden;
}

.bar span{
    display:block;
    height:100%;
    background:var(--primary);
}

.setting-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}

.mapping-list{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:8px;
    margin-top:10px;
}

.mapping-item{
    border:1px solid var(--border);
    padding:10px;
    border-radius:7px;
}

.connection{
    padding:12px;
    border-radius:7px;
    margin-bottom:18px;
    background:#f8fafc;
}

.connection.ok{
    background:#ecfdf5;
    color:#166534;
}

.connection.error{
    background:#fef2f2;
    color:#991b1b;
}

.customer-row{
    display:flex;
    gap:8px;
    align-items:center;
}

.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.48);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:100;
    padding:20px;
}

.modal-backdrop.show{
    display:flex;
}

.modal{
    background:#fff;
    width:min(500px,100%);
    border-radius:11px;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
    overflow:hidden;
}

.modal-header{
    padding:17px 20px;
    border-bottom:1px solid #e5e7eb;
    font-weight:800;
}

.modal-body{
    padding:20px;
    line-height:1.7;
}

.modal-footer{
    padding:13px 20px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
    border-top:1px solid #e5e7eb;
}

.toast{
    position:fixed;
    right:20px;
    bottom:20px;
    background:#172033;
    color:#fff;
    padding:12px 17px;
    border-radius:8px;
    display:none;
    z-index:200;
    box-shadow:0 8px 30px rgba(0,0,0,.2);
}

.toast.show{
    display:block;
}

.hidden{
    display:none!important;
}

.empty{
    text-align:center;
    padding:55px 20px;
    color:#64748b;
}

@media(max-width:900px){
    .admin-nav button{
        font-size:11px;
        padding:8px;
    }

    .container{
        padding:18px;
    }

    .summary-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .form-grid,
    .setting-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:650px){
    .admin-header{
        padding:0 12px;
    }

    .admin-logo{
        margin-right:8px;
        font-size:13px;
    }

    .admin-nav{
        overflow-x:auto;
    }

    .logout{
        display:none;
    }

    .page-title{
        align-items:flex-start;
        gap:10px;
    }

    .page-title h1{
        font-size:21px;
    }

    .edit-actions{
        flex-direction:column;
        align-items:stretch;
    }

    .edit-actions-left,
    .edit-actions-right{
        justify-content:space-between;
    }

    .question-top{
        flex-wrap:wrap;
    }

    .question-options,
    .question-bottom,
    .condition-box{
        margin-left:0;
    }

    .summary-grid{
        grid-template-columns:1fr 1fr;
    }

    .preview-frame{
        padding:18px;
    }
}
</style>
</head>

<body>

<!-- =========================================================
     管理者ヘッダー
========================================================= -->
<header class="admin-header" id="adminHeader">
    <div class="admin-logo">アンケート管理</div>

    <nav class="admin-nav">
        <button data-page="list" onclick="showPage('list')">アンケート一覧</button>
        <button data-page="kintone" onclick="showPage('kintone')">kintone連携設定</button>
        <button data-page="mail" onclick="showPage('mail')">メールサーバ設定</button>
    </nav>

    <div class="logout">ログアウト</div>
</header>


<!-- =========================================================
     メイン
========================================================= -->
<main class="container">


<!-- =========================================================
     1. アンケート一覧
========================================================= -->
<section id="page-list" class="page">

    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <p>登録されているアンケートを管理します。</p>
        </div>

        <button class="btn btn-primary" onclick="newSurvey()">
            ＋ 新規アンケート作成
        </button>
    </div>

    <div class="card toolbar">

        <input
            id="searchInput"
            class="search"
            type="text"
            placeholder="タイトルを検索"
            onkeydown="if(event.key==='Enter') renderSurveyList()"
        >

        <select id="statusFilter" onchange="renderSurveyList()">
            <option value="">すべて</option>
            <option value="公開中">公開中</option>
            <option value="下書き">下書き</option>
            <option value="停止">停止</option>
            <option value="終了">終了</option>
        </select>

        <select id="sortSelect" onchange="renderSurveyList()">
            <option value="updatedDesc">更新日：新しい順</option>
            <option value="updatedAsc">更新日：古い順</option>
            <option value="answersDesc">回答数：多い順</option>
            <option value="answersAsc">回答数：少ない順</option>
            <option value="startDesc">期間開始日：新しい順</option>
            <option value="startAsc">期間開始日：古い順</option>
        </select>

    </div>

    <div class="card table-wrap">
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

</section>


<!-- =========================================================
     2. 作成・編集
========================================================= -->
<section id="page-editor" class="page hidden">

    <div class="page-title">
        <div>
            <h1 id="editorTitle">アンケート作成・編集</h1>
        </div>
    </div>

    <!-- 要件上、基本情報より上に配置 -->
    <div class="edit-actions">

        <div class="edit-actions-left">
            <button class="btn" onclick="cancelEdit()">キャンセル</button>

            <button class="btn btn-primary" onclick="saveSurvey()">
                保存して一覧へ
            </button>
        </div>

        <div class="edit-actions-right">
            <div class="state-control">
                <strong>状態：</strong>
                <select id="stateSelect" onchange="requestStateChange(this.value)">
                </select>
            </div>

            <button class="btn" onclick="openPreview()">
                プレビュー
            </button>
        </div>

    </div>


    <div class="card form-card">

        <h2 class="section-title">基本情報</h2>

        <div class="form-grid">

            <div class="form-group full">
                <label class="form-label">アンケートタイトル</label>
                <input id="surveyTitle" type="text">
            </div>

            <div class="form-group full">
                <label class="form-label">アンケート説明</label>
                <textarea id="surveyDescription"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">開始日時</label>
                <input id="surveyStart" type="datetime-local">
            </div>

            <div class="form-group">
                <label class="form-label">終了日時</label>
                <input id="surveyEnd" type="datetime-local">
            </div>

            <div class="form-group full">
                <label class="form-label">質問番号の採番方式</label>

                <div class="radio-row">
                    <label>
                        <input
                            type="radio"
                            name="numbering"
                            value="global"
                            onchange="renumber()"
                        >
                        アンケート全体で通番
                    </label>

                    <label>
                        <input
                            type="radio"
                            name="numbering"
                            value="group"
                            onchange="renumber()"
                        >
                        グループ毎に採番
                    </label>
                </div>
            </div>

        </div>

    </div>


    <div class="card form-card">

        <h2 class="section-title">質問構成</h2>

        <div id="groups"></div>

        <!-- グループ追加は一覧末尾のみ -->
        <button class="add-group" onclick="addGroup()">
            ＋ グループを追加
        </button>

    </div>

</section>


<!-- =========================================================
     3. プレビュー
========================================================= -->
<section id="page-preview" class="page hidden">

    <div class="page-title">
        <div>
            <h1>プレビュー</h1>
            <p>これはプレビュー表示のため送信されません。</p>
        </div>

        <div>
            <button class="btn" onclick="setPreviewMode('pc')">PC</button>
            <button class="btn" onclick="setPreviewMode('mobile')">スマートフォン</button>
            <button class="btn" onclick="showPage('editor')">編集へ戻る</button>
        </div>
    </div>

    <div id="previewFrame" class="preview-frame">
        <div class="preview-note">
            これはプレビュー表示のため送信されません。
        </div>

        <h2 id="previewTitle"></h2>
        <p id="previewDescription"></p>

        <div id="previewQuestions"></div>

        <div class="answer-actions">
            <button class="btn">戻る</button>
            <button class="btn btn-primary"
                    onclick="toast('プレビューのため送信されません')">
                次へ
            </button>
        </div>
    </div>

</section>


<!-- =========================================================
     4. 顧客選択・メール送信
========================================================= -->
<section id="page-send" class="page hidden">

    <div class="page-title">
        <div>
            <h1>顧客選択・メール送信</h1>
            <p id="sendSurveyName"></p>
        </div>

        <button class="btn" onclick="showPage('list')">一覧へ</button>
    </div>

    <div class="card toolbar">

        <input class="search"
               id="customerSearch"
               type="text"
               placeholder="顧客名・組織名・メールアドレス"
               oninput="renderCustomers()">

        <select id="customerStatus" onchange="renderCustomers()">
            <option value="">すべて</option>
            <option value="未送信">未送信</option>
            <option value="送信済み / 未回答">送信済み / 未回答</option>
            <option value="回答済み">回答済み</option>
        </select>

        <button class="btn" onclick="selectAllCustomers(true)">すべて選択</button>
        <button class="btn" onclick="selectAllCustomers(false)">すべて解除</button>

    </div>

    <div class="card table-wrap">
        <table>
            <thead>
            <tr>
                <th></th>
                <th>組織名</th>
                <th>氏名</th>
                <th>メールアドレス</th>
                <th>電話番号</th>
                <th>最終送信日時</th>
                <th>送信回数</th>
                <th>回答ステータス</th>
                <th>kintone</th>
            </tr>
            </thead>
            <tbody id="customerTable"></tbody>
        </table>
    </div>

    <div class="card form-card" style="margin-top:15px">

        <h2 class="section-title">メールテンプレート</h2>

        <div class="form-group">
            <label class="form-label">メール件名</label>
            <input id="mailSubject" type="text"
                   value="アンケートご回答のお願い">
        </div>

        <br>

        <div class="form-group">
            <label class="form-label">メール本文</label>
            <textarea id="mailBody" style="min-height:150px"> {顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
        </div>

        <br>

        <button class="btn btn-primary" onclick="confirmSend()">
            選択した顧客へ一括送信
        </button>

        <button class="btn" onclick="showPage('history')">
            送信履歴
        </button>

    </div>

</section>


<!-- =========================================================
     5. 送信履歴
========================================================= -->
<section id="page-history" class="page hidden">

    <div class="page-title">
        <div>
            <h1>送信履歴</h1>
        </div>
    </div>

    <div class="card table-wrap">
        <table>
            <thead>
            <tr>
                <th>送信日時</th>
                <th>送信種別</th>
                <th>送信件数</th>
                <th>送信件名</th>
                <th>送信実行者</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody id="historyTable"></tbody>
        </table>
    </div>

</section>


<!-- =========================================================
     6. 集計
========================================================= -->
<section id="page-analysis" class="page hidden">

    <div class="page-title">
        <div>
            <h1>回答集計・分析</h1>
            <p id="analysisSurveyName"></p>
        </div>

        <div>
            <button class="btn" onclick="exportMock('CSV')">CSVダウンロード</button>
            <button class="btn" onclick="exportMock('PDF')">PDF出力</button>
        </div>
    </div>

    <div class="summary-grid">

        <div class="summary-card">
            <div class="label">送信対象者数</div>
            <div class="value">128</div>
        </div>

        <div class="summary-card">
            <div class="label">回答数</div>
            <div class="value">84</div>
        </div>

        <div class="summary-card">
            <div class="label">未登録顧客からの回答数</div>
            <div class="value">7</div>
        </div>

        <div class="summary-card">
            <div class="label">未回答数</div>
            <div class="value">44</div>
        </div>

        <div class="summary-card">
            <div class="label">回答率</div>
            <div class="value">65.6%</div>
        </div>

    </div>

    <div class="card form-card">

        <h2 class="section-title">設問フィルター</h2>

        <button class="btn btn-small" onclick="checkQuestions(true)">
            すべて選択
        </button>

        <button class="btn btn-small" onclick="checkQuestions(false)">
            すべて解除
        </button>

        <div id="questionFilters" style="margin-top:12px"></div>

    </div>

    <div class="card form-card">

        <h2 class="section-title">設問別集計</h2>

        <div style="margin-bottom:25px">
            <strong>Q1　サービスを利用したことがありますか？</strong>

            <div style="margin-top:13px">
                <div>はい　58件（69.0%）</div>
                <div class="bar"><span style="width:69%"></span></div>
            </div>

            <div style="margin-top:10px">
                <div>いいえ　26件（31.0%）</div>
                <div class="bar"><span style="width:31%"></span></div>
            </div>
        </div>

        <div>
            <strong>Q2　ご意見・ご要望</strong>

            <div style="margin-top:12px">
                <div class="card" style="padding:12px;margin-bottom:7px">
                    「操作が分かりやすく、便利でした。」
                </div>

                <div class="card" style="padding:12px;margin-bottom:7px">
                    「スマートフォンでも回答しやすかったです。」
                </div>

                <div class="card" style="padding:12px">
                    「今後も利用したいと思います。」
                </div>
            </div>
        </div>

    </div>

</section>


<!-- =========================================================
     7. kintone設定
========================================================= -->
<section id="page-kintone" class="page hidden">

    <div class="page-title">
        <div>
            <h1>kintone連携設定</h1>
            <p>顧客情報との連携設定を行います。</p>
        </div>
    </div>

    <div id="kintoneConnection" class="connection">
        未設定
    </div>

    <div class="card form-card">

        <div class="setting-grid">

            <div class="form-group">
                <label class="form-label">サブドメイン</label>
                <input id="kinSubdomain" type="text"
                       value="example.cybozu.com">
            </div>

            <div class="form-group">
                <label class="form-label">顧客管理アプリID</label>
                <input id="kinAppId" type="number" value="123">
            </div>

            <div class="form-group">
                <label class="form-label">ログイン名</label>
                <input id="kinLogin" type="text" value="admin">
            </div>

            <div class="form-group">
                <label class="form-label">パスワード</label>
                <input id="kinPassword" type="password" value="password">
            </div>

            <div class="form-group full">
                <label>
                    <input id="kinSSL" type="checkbox" checked>
                    SSL証明書を検証する
                </label>
            </div>

        </div>

        <hr style="border:0;border-top:1px solid #e5e7eb;margin:25px 0">

        <button class="btn btn-primary" onclick="getKintoneFields()">
            項目一覧を再取得
        </button>

        <button class="btn" onclick="syncCustomers()">
            顧客情報を同期
        </button>

        <div id="kinFields" style="margin-top:20px"></div>

    </div>

</section>


<!-- =========================================================
     8. メールサーバ設定
========================================================= -->
<section id="page-mail" class="page hidden">

    <div class="page-title">
        <div>
            <h1>メールサーバ設定</h1>
        </div>
    </div>

    <div id="mailConnection" class="connection">
        未設定
    </div>

    <div class="card form-card">

        <div class="setting-grid">

            <div class="form-group">
                <label class="form-label">SMTPサーバ</label>
                <input id="smtpHost" type="text" value="smtp.example.com">
            </div>

            <div class="form-group">
                <label class="form-label">SMTPポート</label>
                <input id="smtpPort" type="number" value="587">
            </div>

            <div class="form-group">
                <label class="form-label">暗号化方式</label>
                <select>
                    <option>SSL</option>
                    <option selected>TLS</option>
                    <option>なし</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">SMTP認証</label>
                <select>
                    <option selected>あり</option>
                    <option>なし</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">SMTPユーザー名</label>
                <input type="text" value="mailer@example.com">
            </div>

            <div class="form-group">
                <label class="form-label">SMTPパスワード</label>
                <input type="password" value="password">
            </div>

            <div class="form-group">
                <label class="form-label">送信元メールアドレス</label>
                <input type="email" value="info@example.com">
            </div>

            <div class="form-group">
                <label class="form-label">送信元名</label>
                <input type="text" value="アンケート事務局">
            </div>

            <div class="form-group">
                <label class="form-label">返信先メールアドレス</label>
                <input type="email" value="support@example.com">
            </div>

        </div>

        <br>

        <button class="btn btn-primary" onclick="testMail(true)">
            接続テスト
        </button>

        <button class="btn" onclick="testMail(false)">
            テストメールを送信
        </button>

    </div>

</section>


<!-- =========================================================
     9. 回答者アンケート
     ※管理者ヘッダーはshowPage()側で非表示にする
========================================================= -->
<section id="page-answer" class="page hidden">

    <div class="preview-frame">

        <h1 id="answerTitle">アンケート</h1>

        <p id="answerDescription">
            アンケートへのご協力をお願いいたします。
        </p>

        <hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0">

        <div id="answerQuestions"></div>

        <div class="answer-actions">
            <button class="btn" onclick="toast('最初のページです')">
                戻る
            </button>

            <button class="btn btn-primary" onclick="validateAnswer()">
                次へ
            </button>
        </div>

    </div>

</section>


<!-- =========================================================
     10. 回答確認
========================================================= -->
<section id="page-confirm-answer" class="page hidden">

    <div class="preview-frame">

        <h1>回答確認</h1>

        <p>入力内容をご確認ください。</p>

        <div id="answerConfirmBody"></div>

        <div class="answer-actions">
            <button class="btn" onclick="showPage('answer')">
                修正する
            </button>

            <button class="btn btn-primary" onclick="confirmAnswerSend()">
                回答を送信する
            </button>
        </div>

    </div>

</section>


<!-- =========================================================
     11. 回答完了
     ※管理者ヘッダーなし
========================================================= -->
<section id="page-complete" class="page hidden">

    <div class="preview-frame" style="text-align:center;padding:70px 25px">

        <div style="font-size:55px;color:#16a34a">✓</div>

        <h1>回答ありがとうございました</h1>

        <p>
            アンケートの回答を受け付けました。
        </p>

        <!-- 管理者画面へのリンクは意図的に存在しない -->

    </div>

</section>

</main>


<!-- =========================================================
     モーダル
========================================================= -->
<div id="modalBackdrop" class="modal-backdrop">

    <div class="modal">

        <div id="modalTitle" class="modal-header">
            確認
        </div>

        <div id="modalBody" class="modal-body">
        </div>

        <div class="modal-footer">

            <button class="btn" onclick="closeModal()">
                キャンセル
            </button>

            <button id="modalExecute" class="btn btn-primary">
                実行
            </button>

        </div>

    </div>

</div>


<div id="toast" class="toast"></div>


<script>
/* ============================================================
   サンプルデータ
============================================================ */

let surveys = [
    {
        id:1,
        title:"サービス満足度アンケート",
        description:"サービスについてのご意見をお聞かせください。",
        start:"2026-08-01T09:00",
        end:"2026-08-31T23:59",
        status:"公開中",
        created:"2026-07-15",
        updated:"2026-08-20",
        answers:84,
        numbering:"global",
        groups:[
            {
                id:101,
                title:"サービスについて",
                questions:[
                    {
                        id:1001,
                        text:"サービスを利用したことがありますか？",
                        type:"single",
                        required:true,
                        options:["はい","いいえ"],
                        condition:""
                    },
                    {
                        id:1002,
                        text:"特に良かった点を教えてください。",
                        type:"free",
                        required:false,
                        options:[],
                        condition:""
                    }
                ]
            },
            {
                id:102,
                title:"ご意見・ご要望",
                questions:[
                    {
                        id:1003,
                        text:"今後のご意見・ご要望をお聞かせください。",
                        type:"free",
                        required:false,
                        options:[],
                        condition:""
                    }
                ]
            }
        ]
    },
    {
        id:2,
        title:"新サービス事前調査",
        description:"新サービスに関するアンケートです。",
        start:"2026-09-01T09:00",
        end:"2026-09-30T23:59",
        status:"下書き",
        created:"2026-08-18",
        updated:"2026-08-22",
        answers:0,
        numbering:"global",
        groups:[
            {
                id:201,
                title:"基本アンケート",
                questions:[
                    {
                        id:2001,
                        text:"興味がありますか？",
                        type:"single",
                        required:true,
                        options:["非常にある","ある","あまりない","ない"],
                        condition:""
                    }
                ]
            }
        ]
    },
    {
        id:3,
        title:"旧サービス利用者アンケート",
        description:"旧サービス終了に伴うアンケートです。",
        start:"2026-05-01T09:00",
        end:"2026-06-30T23:59",
        status:"終了",
        created:"2026-04-10",
        updated:"2026-07-01",
        answers:128,
        numbering:"group",
        groups:[
            {
                id:301,
                title:"利用状況",
                questions:[
                    {
                        id:3001,
                        text:"利用頻度を教えてください。",
                        type:"single",
                        required:true,
                        options:["毎日","週数回","月数回","ほぼ利用しない"],
                        condition:""
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
        email:"yamada@example.com",
        phone:"03-1111-2222",
        address:"東京都港区",
        sent:"2026-08-10",
        count:1,
        status:"回答済み",
        kintone:true
    },
    {
        id:2,
        org:"テスト株式会社",
        name:"佐藤 花子",
        email:"sato@example.com",
        phone:"03-3333-4444",
        address:"東京都新宿区",
        sent:"2026-08-12",
        count:1,
        status:"送信済み / 未回答",
        kintone:true
    },
    {
        id:3,
        org:"株式会社ABC",
        name:"鈴木 一郎",
        email:"suzuki@example.com",
        phone:"03-5555-6666",
        address:"東京都千代田区",
        sent:"",
        count:0,
        status:"未送信",
        kintone:false
    },
    {
        id:4,
        org:"未登録回答者",
        name:"田中 次郎",
        email:"tanaka@example.net",
        phone:"090-7777-8888",
        address:"東京都渋谷区",
        sent:"",
        count:0,
        status:"未送信",
        kintone:false
    }
];

let histories = [];

let editorSurvey = null;
let editorOriginal = null;
let previewMode = "pc";
let answerSurvey = null;
let answerValues = {};


/* ============================================================
   初期化
============================================================ */

document.addEventListener("DOMContentLoaded",function(){
    renderSurveyList();
    showPage("list");
});


/* ============================================================
   共通
============================================================ */

function clone(obj){
    return JSON.parse(JSON.stringify(obj));
}

function escapeHtml(value){
    return String(value ?? "")
        .replaceAll("&","&amp;")
        .replaceAll("<","&lt;")
        .replaceAll(">","&gt;")
        .replaceAll('"',"&quot;")
        .replaceAll("'","&#039;");
}

function toast(message){
    const el=document.getElementById("toast");
    el.textContent=message;
    el.classList.add("show");

    setTimeout(()=>{
        el.classList.remove("show");
    },2200);
}

function openModal(title,body,callback){
    document.getElementById("modalTitle").textContent=title;
    document.getElementById("modalBody").innerHTML=body;
    document.getElementById("modalBackdrop").classList.add("show");

    document.getElementById("modalExecute").onclick=function(){
        closeModal();
        callback();
    };
}

function closeModal(){
    document.getElementById("modalBackdrop").classList.remove("show");
}


/* ============================================================
   画面遷移
============================================================ */

const answerPages=[
    "answer",
    "confirm-answer",
    "complete"
];

function showPage(page){

    document.querySelectorAll(".page").forEach(el=>{
        el.classList.add("hidden");
    });

    const target=document.getElementById("page-"+page);

    if(target){
        target.classList.remove("hidden");
    }

    const isAnswerPage=answerPages.includes(page);

    /*
     * 回答者画面では管理者ヘッダーを完全に非表示。
     */
    document.getElementById("adminHeader").style.display=
        isAnswerPage ? "none" : "flex";

    window.scrollTo({top:0,behavior:"smooth"});

    if(!isAnswerPage){
        document.querySelectorAll(".admin-nav button").forEach(btn=>{
            btn.classList.toggle(
                "active",
                btn.dataset.page===page
            );
        });
    }

    if(page==="list"){
        renderSurveyList();
    }

    if(page==="history"){
        renderHistory();
    }

    if(page==="kintone"){
        // 設定画面
    }

    if(page==="mail"){
        // 設定画面
    }
}


/* ============================================================
   ステータス
============================================================ */

function statusClass(status){

    if(status==="公開中") return "status-public";
    if(status==="下書き") return "status-draft";
    if(status==="停止") return "status-stop";
    if(status==="終了") return "status-end";

    return "status-draft";
}

function statusBadge(status){
    return `<span class="status ${statusClass(status)}">${escapeHtml(status)}</span>`;
}


/* ============================================================
   アンケート一覧
============================================================ */

function renderSurveyList(){

    const keyword=document
        .getElementById("searchInput")
        .value
        .trim()
        .toLowerCase();

    const filter=document.getElementById("statusFilter").value;
    const sort=document.getElementById("sortSelect").value;

    let list=surveys.filter(s=>{

        const matchKeyword=
            !keyword ||
            s.title.toLowerCase().includes(keyword);

        const matchStatus=
            !filter || s.status===filter;

        return matchKeyword && matchStatus;
    });

    list.sort((a,b)=>{

        if(sort==="updatedDesc")
            return b.updated.localeCompare(a.updated);

        if(sort==="updatedAsc")
            return a.updated.localeCompare(b.updated);

        if(sort==="answersDesc")
            return b.answers-a.answers;

        if(sort==="answersAsc")
            return a.answers-b.answers;

        if(sort==="startDesc")
            return b.start.localeCompare(a.start);

        if(sort==="startAsc")
            return a.start.localeCompare(b.start);

        return 0;
    });

    const tbody=document.getElementById("surveyTable");

    if(!list.length){
        tbody.innerHTML=`
            <tr>
                <td colspan="6" class="empty">
                    該当するアンケートはありません
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML=list.map(s=>{

        /*
         * 一覧には公開・停止・再開などの状態変更操作を
         * 意図的に配置しない。
         */

        return `
        <tr>

            <td>
                ${escapeHtml(s.created)}<br>
                <span style="color:#64748b">
                    更新 ${escapeHtml(s.updated)}
                </span>
            </td>

            <td>
                <strong>${escapeHtml(s.title)}</strong>
            </td>

            <td>
                ${escapeHtml(s.start.replace("T"," "))}
                ～<br>
                ${escapeHtml(s.end.replace("T"," "))}
            </td>

            <td>
                ${statusBadge(s.status)}
            </td>

            <td>
                ${s.answers}
            </td>

            <td>
                <div class="action-group">

                    <button class="btn btn-small"
                            onclick="editSurvey(${s.id})">
                        確認・編集
                    </button>

                    <button class="btn btn-small"
                            onclick="openAnalysis(${s.id})">
                        集計
                    </button>

                    <button class="btn btn-small"
                            onclick="openSend(${s.id})">
                        送信
                    </button>

                    <button class="btn btn-small"
                            onclick="duplicateSurvey(${s.id})">
                        複製
                    </button>

                    <button class="btn btn-small"
                            onclick="deleteSurvey(${s.id})">
                        削除
                    </button>

                </div>
            </td>

        </tr>
        `;
    }).join("");
}


/* ============================================================
   新規作成
============================================================ */

function newSurvey(){

    editorSurvey={
        id:null,
        title:"",
        description:"",
        start:"",
        end:"",
        status:"下書き",
        created:new Date().toISOString().slice(0,10),
        updated:new Date().toISOString().slice(0,10),
        answers:0,
        numbering:"global",
        groups:[
            {
                id:Date.now(),
                title:"新しいグループ",
                questions:[]
            }
        ]
    };

    editorOriginal=clone(editorSurvey);

    document.getElementById("editorTitle").textContent=
        "アンケート作成";

    loadEditor();

    showPage("editor");
}


/* ============================================================
   編集
============================================================ */

function editSurvey(id){

    const survey=surveys.find(s=>s.id===id);

    if(!survey) return;

    editorSurvey=clone(survey);
    editorOriginal=clone(survey);

    document.getElementById("editorTitle").textContent=
        "アンケート作成・編集";

    loadEditor();

    showPage("editor");
}

function loadEditor(){

    document.getElementById("surveyTitle").value=
        editorSurvey.title;

    document.getElementById("surveyDescription").value=
        editorSurvey.description;

    document.getElementById("surveyStart").value=
        editorSurvey.start;

    document.getElementById("surveyEnd").value=
        editorSurvey.end;

    document.querySelectorAll(
        'input[name="numbering"]'
    ).forEach(r=>{
        r.checked=r.value===editorSurvey.numbering;
    });

    loadStateSelect();

    renderGroups();

    renumber();
}


/* ============================================================
   状態プルダウン
============================================================ */

function loadStateSelect(){

    const select=document.getElementById("stateSelect");

    let options=[];

    if(editorSurvey.status==="下書き"){
        options=["下書き","公開"];
    }
    else if(editorSurvey.status==="公開中"){
        options=["公開中","停止"];
    }
    else if(editorSurvey.status==="停止"){
        options=["停止","再開"];
    }
    else if(editorSurvey.status==="終了"){
        options=["終了"];
    }

    select.innerHTML=options.map(v=>{

        const label=
            v==="公開" ? "公開" :
            v==="再開" ? "再開" :
            v;

        return `<option value="${escapeHtml(v)}">${label}</option>`;
    }).join("");

    select.value=editorSurvey.status;
    select.disabled=editorSurvey.status==="終了";
}

function requestStateChange(newState){

    const oldState=editorSurvey.status;

    if(newState===oldState){
        return;
    }

    let message="";

    if(newState==="公開"){
        message="このアンケートを公開しますか？";
    }

    if(newState==="停止"){
        message="このアンケートを停止しますか？";
    }

    if(newState==="再開"){
        message="このアンケートを再開しますか？";
    }

    openModal(
        "状態変更の確認",
        message,
        ()=>{
            if(newState==="公開"){
                editorSurvey.status="公開中";
            }
            else if(newState==="再開"){
                editorSurvey.status="公開中";
            }
            else{
                editorSurvey.status=newState;
            }

            loadStateSelect();
            toast("状態を変更しました");
        }
    );
}


/* ============================================================
   保存
============================================================ */

function saveSurvey(){

    editorSurvey.title=
        document.getElementById("surveyTitle").value.trim();

    editorSurvey.description=
        document.getElementById("surveyDescription").value.trim();

    editorSurvey.start=
        document.getElementById("surveyStart").value;

    editorSurvey.end=
        document.getElementById("surveyEnd").value;

    editorSurvey.numbering=
        document.querySelector(
            'input[name="numbering"]:checked'
        ).value;

    if(!editorSurvey.title){
        toast("アンケートタイトルを入力してください");
        return;
    }

    renumber();

    editorSurvey.updated=
        new Date().toISOString().slice(0,10);

    /*
     * 新規保存は必ず下書き。
     * 既存編集は現在の状態を維持。
     */
    if(editorSurvey.id===null){
        editorSurvey.status="下書き";
        editorSurvey.id=
            Math.max(0,...surveys.map(s=>s.id))+1;

        surveys.unshift(clone(editorSurvey));
    }
    else{

        const index=
            surveys.findIndex(s=>s.id===editorSurvey.id);

        if(index>=0){
            surveys[index]=clone(editorSurvey);
        }
    }

    toast("保存しました");

    showPage("list");
}


/* ============================================================
   キャンセル
============================================================ */

function cancelEdit(){

    openModal(
        "編集内容を破棄しますか？",
        "保存していない変更内容は破棄されます。",
        ()=>{
            editorSurvey=clone(editorOriginal);
            showPage("list");
        }
    );
}


/* ============================================================
   グループ
============================================================ */

function renderGroups(){

    const container=document.getElementById("groups");

    container.innerHTML=editorSurvey.groups.map((g,gi)=>{

        return `
        <div class="group"
             draggable="true"
             data-group-id="${g.id}"
             ondragstart="dragGroupStart(event,${g.id})"
             ondragover="event.preventDefault()"
             ondrop="dropGroup(event,${g.id})">

            <div class="group-header">

                <span class="drag-handle">☷</span>

                <input
                    class="group-title"
                    value="${escapeHtml(g.title)}"
                    onchange="updateGroupTitle(${g.id},this.value)"
                >

                <button class="btn btn-small"
                        onclick="deleteGroup(${g.id})">
                    グループ削除
                </button>

            </div>

            <div class="question-list">

                ${
                    g.questions.length
                    ? g.questions.map(q=>renderQuestion(g,q)).join("")
                    : `
                        <div class="empty"
                             style="padding:20px">
                            質問はありません
                        </div>
                    `
                }

                <!-- 質問追加はグループ末尾だけ -->
                <button class="add-question"
                        onclick="addQuestion(${g.id})">
                    ＋ 質問を追加
                </button>

            </div>

        </div>
        `;
    }).join("");
}

function addGroup(){

    editorSurvey.groups.push({
        id:Date.now()+Math.random(),
        title:"新しいグループ",
        questions:[]
    });

    renumber();
    renderGroups();
}

function updateGroupTitle(id,title){

    const g=editorSurvey.groups.find(x=>x.id===id);

    if(g){
        g.title=title;
    }
}

function deleteGroup(id){

    const group=
        editorSurvey.groups.find(g=>g.id===id);

    if(!group) return;

    const hasQuestions=group.questions.length>0;

    openModal(
        "グループ削除",
        hasQuestions
            ? `このグループには質問が ${group.questions.length} 件あります。削除しますか？`
            : "このグループを削除しますか？",
        ()=>{
            editorSurvey.groups=
                editorSurvey.groups.filter(g=>g.id!==id);

            renumber();
            renderGroups();
        }
    );
}


/* ============================================================
   グループドラッグ＆ドロップ
============================================================ */

let draggingGroupId=null;

function dragGroupStart(event,id){
    draggingGroupId=id;
}

function dropGroup(event,targetId){

    if(draggingGroupId===targetId) return;

    const from=
        editorSurvey.groups.findIndex(
            g=>g.id===draggingGroupId
        );

    const to=
        editorSurvey.groups.findIndex(
            g=>g.id===targetId
        );

    if(from<0 || to<0) return;

    const [item]=editorSurvey.groups.splice(from,1);

    editorSurvey.groups.splice(to,0,item);

    draggingGroupId=null;

    renumber();
    renderGroups();
}


/* ============================================================
   質問
============================================================ */

function renderQuestion(group,q){

    const typeOptions=`
        <option value="single" ${q.type==="single"?"selected":""}>
            単一選択
        </option>

        <option value="multiple" ${q.type==="multiple"?"selected":""}>
            複数選択
        </option>

        <option value="free" ${q.type==="free"?"selected":""}>
            自由記述
        </option>
    `;

    let optionsHtml="";

    /*
     * 自由記述は1種類のみ。
     * 1行テキスト / 複数行テキストは存在しない。
     */
    if(q.type==="single" || q.type==="multiple"){

        optionsHtml=`
            <div class="question-options">

                ${q.options.map((o,i)=>`
                    <div class="option-row">
                        <input
                            type="text"
                            value="${escapeHtml(o)}"
                            onchange="updateOption(
                                ${q.id},
                                ${i},
                                this.value
                            )"
                        >

                        <button class="btn btn-small"
                                onclick="deleteOption(
                                    ${q.id},
                                    ${i}
                                )">
                            削除
                        </button>
                    </div>
                `).join("")}

                <button class="btn btn-small add-option"
                        onclick="addOption(${q.id})">
                    ＋ 選択肢を追加
                </button>

            </div>
        `;
    }

    return `
    <div class="question"
         draggable="true"
         data-question-id="${q.id}"
         data-group-id="${group.id}"
         ondragstart="dragQuestionStart(event,${group.id},${q.id})"
         ondragover="event.preventDefault()"
         ondrop="dropQuestion(event,${group.id},${q.id})">

        <div class="question-top">

            <span class="drag-handle">☷</span>

            <span class="question-number"
                  id="number-${q.id}">
                Q?
            </span>

            <input
                class="question-text"
                type="text"
                value="${escapeHtml(q.text)}"
                placeholder="質問文"
                onchange="updateQuestionText(
                    ${q.id},
                    this.value
                )"
            >

            <select
                onchange="changeQuestionType(
                    ${q.id},
                    this.value
                )">
                ${typeOptions}
            </select>

            <button class="btn btn-small"
                    onclick="deleteQuestion(
                        ${q.id}
                    )">
                削除
            </button>

        </div>

        ${optionsHtml}

        <div class="question-bottom">

            <label>
                <input
                    type="checkbox"
                    ${q.required?"checked":""}
                    onchange="toggleRequired(
                        ${q.id},
                        this.checked
                    )"
                >
                必須
            </label>

            ${
                q.type==="single"
                ? `
                <label>
                    条件分岐：
                    <select onchange="updateCondition(
                        ${q.id},
                        this.value
                    )">
                        <option value="">なし</option>
                        ${editorSurvey.groups.flatMap(
                            g=>g.questions
                        )
                        .filter(x=>x.id!==q.id)
                        .map(x=>`
                            <option
                                value="${x.id}"
                                ${String(q.condition)===String(x.id)
                                    ?"selected":""}>
                                ${getQuestionNumber(x.id)}
                            </option>
                        `).join("")}
                    </select>
                </label>
                `
                : ""
            }

        </div>

    </div>
    `;
}


/* ============================================================
   質問操作
============================================================ */

function findQuestion(id){

    for(const g of editorSurvey.groups){

        const q=g.questions.find(q=>q.id===id);

        if(q){
            return {group:g,question:q};
        }
    }

    return null;
}

function addQuestion(groupId){

    const group=
        editorSurvey.groups.find(g=>g.id===groupId);

    if(!group) return;

    group.questions.push({
        id:Date.now()+Math.random(),
        text:"",
        type:"single",
        required:false,
        options:["選択肢1","選択肢2"],
        condition:""
    });

    renumber();
    renderGroups();
}

function deleteQuestion(id){

    openModal(
        "質問削除",
        "この質問を削除しますか？",
        ()=>{
            for(const g of editorSurvey.groups){

                g.questions=
                    g.questions.filter(q=>q.id!==id);
            }

            renumber();
            renderGroups();
        }
    );
}

function updateQuestionText(id,text){

    const found=findQuestion(id);

    if(found){
        found.question.text=text;
    }
}

function changeQuestionType(id,type){

    const found=findQuestion(id);

    if(!found) return;

    found.question.type=type;

    /*
     * 回答形式は3種類だけ。
     * freeに変更した場合、選択肢は持たせない。
     */
    if(type==="free"){
        found.question.options=[];
        found.question.condition="";
    }

    if(type==="single" || type==="multiple"){

        if(!found.question.options.length){
            found.question.options=[
                "選択肢1",
                "選択肢2"
            ];
        }
    }

    renderGroups();
    renumber();
}

function toggleRequired(id,value){

    const found=findQuestion(id);

    if(found){
        found.question.required=value;
    }
}

function addOption(questionId){

    const found=findQuestion(questionId);

    if(!found) return;

    found.question.options.push(
        "新しい選択肢"
    );

    renderGroups();
}

function updateOption(questionId,index,value){

    const found=findQuestion(questionId);

    if(found){
        found.question.options[index]=value;
    }
}

function deleteOption(questionId,index){

    const found=findQuestion(questionId);

    if(!found) return;

    if(found.question.options.length<=1){
        toast("選択肢は1つ以上必要です");
        return;
    }

    found.question.options.splice(index,1);

    renderGroups();
}

function updateCondition(id,value){

    const found=findQuestion(id);

    if(found){
        found.question.condition=value;
    }
}


/* ============================================================
   質問ドラッグ＆ドロップ
============================================================ */

let draggingQuestion=null;

function dragQuestionStart(event,groupId,questionId){

    draggingQuestion={
        groupId,
        questionId
    };
}

function dropQuestion(event,targetGroupId,targetQuestionId){

    if(!draggingQuestion) return;

    const sourceGroup=
        editorSurvey.groups.find(
            g=>g.id===draggingQuestion.groupId
        );

    const targetGroup=
        editorSurvey.groups.find(
            g=>g.id===targetGroupId
        );

    if(!sourceGroup || !targetGroup){
        return;
    }

    const sourceIndex=
        sourceGroup.questions.findIndex(
            q=>q.id===draggingQuestion.questionId
        );

    if(sourceIndex<0) return;

    const [question]=
        sourceGroup.questions.splice(sourceIndex,1);

    let targetIndex=
        targetGroup.questions.findIndex(
            q=>q.id===targetQuestionId
        );

    if(targetIndex<0){
        targetIndex=targetGroup.questions.length;
    }

    /*
     * 同じグループ内で下方向へ移動する場合の補正。
     */
    if(sourceGroup===targetGroup &&
       sourceIndex<targetIndex){
        targetIndex--;
    }

    targetGroup.questions.splice(
        targetIndex,
        0,
        question
    );

    draggingQuestion=null;

    renumber();
    renderGroups();
}


/* ============================================================
   自動採番
============================================================ */

function renumber(){

    if(!editorSurvey) return;

    let globalNo=1;

    editorSurvey.groups.forEach((group,gi)=>{

        group.questions.forEach((q,qi)=>{

            if(editorSurvey.numbering==="global"){

                q.displayNumber=
                    "Q"+globalNo;

                globalNo++;
            }
            else{

                q.displayNumber=
                    "Q"+(gi+1)+"-"+(qi+1);
            }
        });
    });

    document
        .querySelectorAll(".question-number")
        .forEach(el=>{

            const id=parseFloat(
                el.id.replace("number-","")
            );

            const found=findQuestion(id);

            if(found){
                el.textContent=
                    found.question.displayNumber;
            }
        });
}

function getQuestionNumber(id){

    const found=findQuestion(id);

    return found
        ? found.question.displayNumber
        : "Q?";
}


/* ============================================================
   プレビュー
============================================================ */

function openPreview(){

    editorSurvey.title=
        document.getElementById("surveyTitle").value;

    editorSurvey.description=
        document.getElementById("surveyDescription").value;

    editorSurvey.numbering=
        document.querySelector(
            'input[name="numbering"]:checked'
        ).value;

    renumber();

    document.getElementById("previewTitle").textContent=
        editorSurvey.title || "アンケート";

    document.getElementById("previewDescription").textContent=
        editorSurvey.description;

    const box=
        document.getElementById("previewQuestions");

    box.innerHTML=
        editorSurvey.groups.map(g=>{

            return `
                <div style="margin-top:30px">
                    <h3>${escapeHtml(g.title)}</h3>

                    ${g.questions.map(q=>{

                        let input="";

                        if(q.type==="single"){
                            input=q.options.map(o=>`
                                <label class="choice">
                                    <input type="radio"
                                           name="preview-${q.id}">
                                    ${escapeHtml(o)}
                                </label>
                            `).join("");
                        }

                        else if(q.type==="multiple"){
                            input=q.options.map(o=>`
                                <label class="choice">
                                    <input type="checkbox">
                                    ${escapeHtml(o)}
                                </label>
                            `).join("");
                        }

                        else{
                            input=`
                                <textarea
                                    placeholder="回答を入力してください"
                                    style="min-height:110px">
                                </textarea>
                            `;
                        }

                        return `
                            <div class="answer-question">
                                <h3>
                                    ${escapeHtml(q.displayNumber)}
                                    ${escapeHtml(q.text)}
                                    ${q.required
                                        ?'<span class="required">必須</span>'
                                        :''}
                                </h3>

                                ${input}
                            </div>
                        `;

                    }).join("")}

                </div>
            `;

        }).join("");

    setPreviewMode("pc");

    showPage("preview");
}

function setPreviewMode(mode){

    previewMode=mode;

    const frame=
        document.getElementById("previewFrame");

    frame.classList.toggle(
        "preview-mobile",
        mode==="mobile"
    );
}


/* ============================================================
   複製
============================================================ */

function duplicateSurvey(id){

    const source=
        surveys.find(s=>s.id===id);

    if(!source) return;

    openModal(
        "アンケート複製",
        `「${escapeHtml(source.title)}」を複製しますか？<br><br>
         複製後は下書きとして一覧に追加されます。`,
        ()=>{

            const copy=clone(source);

            copy.id=
                Math.max(0,...surveys.map(s=>s.id))+1;

            copy.title=
                source.title+"（複製）";

            copy.status="下書き";
            copy.answers=0;
            copy.created=
                new Date().toISOString().slice(0,10);

            copy.updated=copy.created;

            /*
             * 回答データ・送信履歴は複製しない。
             */
            surveys.unshift(copy);

            toast("アンケートを複製しました");

            renderSurveyList();
        }
    );
}


/* ============================================================
   削除
============================================================ */

function deleteSurvey(id){

    const survey=
        surveys.find(s=>s.id===id);

    if(!survey) return;

    openModal(
        "アンケート削除",
        `「${escapeHtml(survey.title)}」を削除しますか？`,
        ()=>{

            surveys=
                surveys.filter(s=>s.id!==id);

            toast("削除しました");

            renderSurveyList();
        }
    );
}


/* ============================================================
   集計
============================================================ */

function openAnalysis(id){

    const survey=
        surveys.find(s=>s.id===id);

    if(!survey) return;

    document.getElementById("analysisSurveyName").textContent=
        "対象アンケート：" + survey.title;

    const allQuestions=
        survey.groups.flatMap(g=>g.questions);

    document.getElementById("questionFilters").innerHTML=
        allQuestions.map(q=>`
            <label style="display:block;margin:8px 0">
                <input type="checkbox"
                       class="question-filter"
                       checked>
                ${escapeHtml(q.displayNumber || "Q?")}
                ${escapeHtml(q.text)}
            </label>
        `).join("");

    showPage("analysis");
}

function checkQuestions(value){

    document
        .querySelectorAll(".question-filter")
        .forEach(x=>x.checked=value);
}

function exportMock(type){

    toast(type+"出力操作を実行しました（モック）");
}


/* ============================================================
   顧客送信
============================================================ */

let currentSendSurveyId=null;

function openSend(id){

    const survey=
        surveys.find(s=>s.id===id);

    if(!survey) return;

    currentSendSurveyId=id;

    document.getElementById("sendSurveyName").textContent=
        "対象アンケート：" + survey.title;

    renderCustomers();

    showPage("send");
}

function renderCustomers(){

    const keyword=
        document.getElementById("customerSearch")
        .value
        .trim()
        .toLowerCase();

    const status=
        document.getElementById("customerStatus").value;

    const list=customers.filter(c=>{

        const matchKeyword=
            !keyword ||
            c.name.toLowerCase().includes(keyword) ||
            c.org.toLowerCase().includes(keyword) ||
            c.email.toLowerCase().includes(keyword);

        const matchStatus=
            !status || c.status===status;

        return matchKeyword && matchStatus;
    });

    document.getElementById("customerTable").innerHTML=
        list.map(c=>`
            <tr>

                <td>
                    <input type="checkbox"
                           class="customer-check"
                           value="${c.id}">
                </td>

                <td>${escapeHtml(c.org)}</td>
                <td>${escapeHtml(c.name)}</td>
                <td>${escapeHtml(c.email)}</td>
                <td>${escapeHtml(c.phone)}</td>
                <td>${escapeHtml(c.sent || "-")}</td>
                <td>${c.count}</td>
                <td>${escapeHtml(c.status)}</td>

                <td>
                    ${
                        c.kintone
                        ? '<span class="status status-public">✓ 登録完了</span>'
                        : '<span class="status status-draft">未登録</span>'
                    }
                </td>

            </tr>
        `).join("");
}

function selectAllCustomers(value){

    document
        .querySelectorAll(".customer-check")
        .forEach(c=>c.checked=value);
}

function confirmSend(){

    const ids=
        [...document.querySelectorAll(
            ".customer-check:checked"
        )].map(x=>Number(x.value));

    if(!ids.length){
        toast("送信対象を選択してください");
        return;
    }

    const alreadySent=
        customers.some(
            c=>ids.includes(c.id) &&
               c.count>0
        );

    if(alreadySent){

        openModal(
            "再送確認",
            "既に送信済みの宛先が含まれています。再送しますか？",
            ()=>executeSend(ids)
        );

    }else{

        openModal(
            "一括送信確認",
            `${ids.length}件の顧客へメールを送信しますか？`,
            ()=>executeSend(ids)
        );
    }
}

function executeSend(ids){

    const now=
        new Date().toLocaleString("ja-JP");

    ids.forEach(id=>{

        const c=customers.find(x=>x.id===id);

        if(!c) return;

        c.sent=now;
        c.count++;
        c.status="送信済み / 未回答";
    });

    histories.unshift({
        date:now,
        type:"通常送信",
        count:ids.length,
        subject:
            document.getElementById("mailSubject").value,
        user:"管理者"
    });

    toast("メール送信成功（モック）");

    renderCustomers();
}


/* ============================================================
   送信履歴
============================================================ */

function renderHistory(){

    const tbody=
        document.getElementById("historyTable");

    if(!histories.length){

        tbody.innerHTML=`
            <tr>
                <td colspan="6" class="empty">
                    送信履歴はありません
                </td>
            </tr>
        `;

        return;
    }

    tbody.innerHTML=
        histories.map((h,i)=>`
            <tr>

                <td>${escapeHtml(h.date)}</td>
                <td>${escapeHtml(h.type)}</td>
                <td>${h.count}</td>
                <td>${escapeHtml(h.subject)}</td>
                <td>${escapeHtml(h.user)}</td>

                <td>
                    <button class="btn btn-small"
                            onclick="showHistoryDetail(${i})">
                        内容確認
                    </button>
                </td>

            </tr>
        `).join("");
}

function showHistoryDetail(index){

    const h=histories[index];

    if(!h) return;

    openModal(
        "送信内容",
        `
            <strong>件名</strong><br>
            ${escapeHtml(h.subject)}
            <br><br>

            <strong>本文</strong><br>
            ${escapeHtml(
                document.getElementById("mailBody").value
            ).replaceAll("\n","<br>")}
            <br><br>

            <strong>個別アンケートURL</strong><br>
            https://example.test/survey/abc123
        `,
        ()=>{}
    );
}


/* ============================================================
   kintone
============================================================ */

function getKintoneFields(){

    const fields=[
        "会社名",
        "氏名",
        "メールアドレス",
        "部署名",
        "電話番号",
        "都道府県",
        "市区町村",
        "番地",
        "建物名",
        "郵便番号"
    ];

    document.getElementById("kinFields").innerHTML=`

        <h3>項目一覧</h3>

        <div class="mapping-list">

            ${fields.map(f=>`
                <div class="mapping-item">
                    ${escapeHtml(f)}
                </div>
            `).join("")}

        </div>

        <hr style="border:0;border-top:1px solid #e5e7eb;margin:25px 0">

        <h3>フィールドマッピング</h3>

        <div class="setting-grid">

            <div>
                <strong>組織名</strong>
                <select style="width:100%;margin-top:6px">
                    <option>会社名</option>
                </select>
            </div>

            <div>
                <strong>氏名</strong>
                <select style="width:100%;margin-top:6px">
                    <option>氏名</option>
                </select>
            </div>

            <div>
                <strong>メールアドレス</strong>
                <select style="width:100%;margin-top:6px">
                    <option>メールアドレス</option>
                </select>
            </div>

            <div>
                <strong>部署名</strong>
                <select style="width:100%;margin-top:6px">
                    <option>部署名</option>
                </select>
            </div>

            <div>
                <strong>電話番号</strong>
                <select style="width:100%;margin-top:6px">
                    <option>電話番号</option>
                </select>
            </div>

        </div>

        <br>

        <h3>住所マッピング</h3>

        <div class="mapping-list">

            <label class="mapping-item">
                <input type="checkbox" checked>
                都道府県
            </label>

            <label class="mapping-item">
                <input type="checkbox" checked>
                市区町村
            </label>

            <label class="mapping-item">
                <input type="checkbox">
                番地
            </label>

            <label class="mapping-item">
                <input type="checkbox">
                建物名
            </label>

            <label class="mapping-item">
                <input type="checkbox">
                郵便番号
            </label>

        </div>
    `;

    const connection=
        document.getElementById("kintoneConnection");

    connection.className="connection ok";
    connection.textContent=
        "✓ kintone接続済み・項目取得済み";

    toast("項目一覧を取得しました（モック）");
}

function syncCustomers(){

    const connection=
        document.getElementById("kintoneConnection");

    connection.className="connection ok";
    connection.textContent=
        "✓ 顧客情報同期済み";

    toast("顧客情報を同期しました（モック）");
}


/* ============================================================
   メール設定
============================================================ */

function testMail(success){

    const box=
        document.getElementById("mailConnection");

    if(success){

        box.className="connection ok";
        box.textContent=
            "✓ 接続確認済み";

        toast("SMTP接続成功（モック）");

    }else{

        box.className="connection ok";
        box.textContent=
            "✓ テストメール送信成功（モック）";

        toast("テストメール送信成功（モック）");
    }
}


/* ============================================================
   回答者フロー
============================================================ */

function openAnswerSurvey(id){

    const survey=
        surveys.find(s=>s.id===id);

    if(!survey) return;

    answerSurvey=clone(survey);
    answerValues={};

    document.getElementById("answerTitle").textContent=
        answerSurvey.title;

    document.getElementById("answerDescription").textContent=
        answerSurvey.description;

    renderAnswerQuestions();

    showPage("answer");
}

function renderAnswerQuestions(){

    const container=
        document.getElementById("answerQuestions");

    let html="";

    answerSurvey.groups.forEach(group=>{

        html+=`
            <h2 style="font-size:19px;margin-top:25px">
                ${escapeHtml(group.title)}
            </h2>
        `;

        group.questions.forEach(q=>{

            /*
             * 条件分岐
             * モックでは指定された質問の回答内容を確認して表示制御。
             */
            let visible=true;

            if(q.condition){

                visible=
                    Object.values(answerValues)
                        .includes(q.condition);
            }

            if(!visible) return;

            let input="";

            if(q.type==="single"){

                input=q.options.map((o,i)=>`
                    <label class="choice">

                        <input
                            type="radio"
                            name="answer-${q.id}"
                            value="${i}"
                            onchange="setAnswer(
                                ${q.id},
                                '${i}'
                            )"
                        >

                        ${escapeHtml(o)}

                    </label>
                `).join("");
            }

            else if(q.type==="multiple"){

                input=q.options.map((o,i)=>`
                    <label class="choice">

                        <input
                            type="checkbox"
                            value="${i}"
                            onchange="setMultipleAnswer(
                                ${q.id},
                                this
                            )"
                        >

                        ${escapeHtml(o)}

                    </label>
                `).join("");
            }

            else{

                /*
                 * 自由記述は1種類。
                 * textareaを使っているが、
                 * 回答形式自体は「自由記述」。
                 */
                input=`
                    <textarea
                        placeholder="回答を入力してください"
                        oninput="setAnswer(
                            ${q.id},
                            this.value
                        )"
                    ></textarea>
                `;
            }

            html+=`
                <div
                    class="answer-question"
                    data-answer-question="${q.id}"
                >

                    <h3>
                        ${escapeHtml(q.displayNumber)}
                        ${escapeHtml(q.text)}

                        ${
                            q.required
                            ? '<span class="required">必須</span>'
                            : ''
                        }
                    </h3>

                    ${input}

                </div>
            `;
        });
    });

    container.innerHTML=html;
}

function setAnswer(id,value){

    answerValues[id]=value;

    renderAnswerQuestions();
}

function setMultipleAnswer(id,element){

    if(!Array.isArray(answerValues[id])){
        answerValues[id]=[];
    }

    const value=element.value;

    if(element.checked){

        if(!answerValues[id].includes(value)){
            answerValues[id].push(value);
        }

    }else{

        answerValues[id]=
            answerValues[id].filter(x=>x!==value);
    }
}


/* ============================================================
   必須チェック
============================================================ */

function validateAnswer(){

    const missing=[];

    answerSurvey.groups.forEach(group=>{

        group.questions.forEach(q=>{

            if(!q.required) return;

            const value=answerValues[q.id];

            const empty=
                value===undefined ||
                value===null ||
                value==="" ||
                (Array.isArray(value) && value.length===0);

            if(empty){
                missing.push(q.displayNumber);
            }
        });
    });

    if(missing.length){

        toast(
            "未回答の必須項目があります：" +
            missing.join("、")
        );

        return;
    }

    renderAnswerConfirmation();

    showPage("confirm-answer");
}


/* ============================================================
   回答確認
============================================================ */

function renderAnswerConfirmation(){

    const box=
        document.getElementById("answerConfirmBody");

    box.innerHTML=
        answerSurvey.groups.map(g=>{

            return `
                <div style="margin-bottom:25px">

                    <h3>${escapeHtml(g.title)}</h3>

                    ${g.questions.map(q=>{

                        const value=
                            answerValues[q.id];

                        let answer="未回答";

                        if(Array.isArray(value)){

                            answer=
                                value.map(i=>
                                    q.options[Number(i)]
                                ).join("、");
                        }
                        else if(
                            value!==undefined &&
                            value!==""
                        ){

                            if(
                                q.type==="single" &&
                                q.options[Number(value)]
                            ){
                                answer=
                                    q.options[Number(value)];
                            }
                            else{
                                answer=value;
                            }
                        }

                        return `
                            <div class="card"
                                 style="padding:13px;margin-top:8px">

                                <strong>
                                    ${escapeHtml(q.displayNumber)}
                                    ${escapeHtml(q.text)}
                                </strong>

                                <div style="margin-top:7px">
                                    ${escapeHtml(answer)}
                                </div>

                            </div>
                        `;

                    }).join("")}

                </div>
            `;

        }).join("");
}

function confirmAnswerSend(){

    openModal(
        "回答送信",
        "回答を送信します。よろしいですか？",
        ()=>{

            /*
             * 実際の送信は行わない。
             */
            showPage("complete");
        }
    );
}


/* ============================================================
   デモ用：回答者URLからの入口
============================================================ */

/*
 * 実際の個別URL処理ではなく、モック確認用。
 * 管理者一覧から直接回答者画面を開けるボタンを
 * 作ると「回答者画面から管理者へ戻る導線」と誤認しやすいため、
 * 通常UIには表示しない。
 *
 * ブラウザコンソールから
 * openAnswerSurvey(1)
 * と実行すれば回答者画面を確認できる。
 */


/* ============================================================
   初期サンプルの質問番号を確定
============================================================ */

surveys.forEach(s=>{

    let no=1;

    s.groups.forEach((g,gi)=>{

        g.questions.forEach((q,qi)=>{

            q.displayNumber=
                s.numbering==="group"
                ? "Q"+(gi+1)+"-"+(qi+1)
                : "Q"+(no++);

        });

    });

});
</script>

</body>
</html>
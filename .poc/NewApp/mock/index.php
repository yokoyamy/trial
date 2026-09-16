<?php
/*
 * アンケート管理アプリ モック
 * Apache + PHP 5.8
 * HTML / CSS / JavaScript を1ファイルに収録
 *
 * モック確認対象
 * ・アンケート一覧
 * ・新規作成 / 編集
 * ・質問グループ
 * ・ドラッグ＆ドロップによる質問移動
 * ・質問番号方式（全体連番 / グループ別）
 * ・単一選択による分岐設定
 * ・公開 / 回答開始待ち / 回答受付中 / 終了 / 保管
 * ・顧客一覧
 * ・送付先選択
 * ・メール送信確認
 * ・キントーン設定
 * ・SMTP設定
 */

$initialSurveys = array(
    array(
        'id'=>1,
        'title'=>'顧客満足度アンケート',
        'description'=>'サービスをご利用いただいているお客様へのアンケートです。',
        'status'=>'active',
        'start'=>'2026/09/01 09:00',
        'end'=>'2026/09/30 18:00',
        'answers'=>42,
        'updated'=>'2026/09/15'
    ),
    array(
        'id'=>2,
        'title'=>'新サービス利用意向調査',
        'description'=>'新サービスについてのご意見をお聞かせください。',
        'status'=>'waiting',
        'start'=>'2026/09/20 09:00',
        'end'=>'2026/10/05 18:00',
        'answers'=>0,
        'updated'=>'2026/09/12'
    ),
    array(
        'id'=>3,
        'title'=>'イベント参加者アンケート',
        'description'=>'イベント参加者へのアンケートです。',
        'status'=>'ended',
        'start'=>'2026/08/01 10:00',
        'end'=>'2026/08/15 18:00',
        'answers'=>86,
        'updated'=>'2026/08/16'
    ),
    array(
        'id'=>4,
        'title'=>'昨年度研修アンケート',
        'description'=>'昨年度研修についてのアンケートです。',
        'status'=>'archived',
        'start'=>'2025/10/01 09:00',
        'end'=>'2025/10/31 18:00',
        'answers'=>128,
        'updated'=>'2025/11/01'
    )
);

$initialCustomers = array(
    array('id'=>101,'name'=>'株式会社青山商事','person'=>'山田 太郎','email'=>'yamada@example.co.jp','selected'=>true),
    array('id'=>102,'name'=>'赤坂株式会社','person'=>'佐藤 花子','email'=>'sato@example.co.jp','selected'=>true),
    array('id'=>103,'name'=>'東京サービス株式会社','person'=>'鈴木 一郎','email'=>'suzuki@example.co.jp','selected'=>false),
    array('id'=>104,'name'=>'港区商事株式会社','person'=>'田中 次郎','email'=>'tanaka@example.co.jp','selected'=>false),
    array('id'=>105,'name'=>'サンプル工業株式会社','person'=>'高橋 美咲','email'=>'takahashi@example.co.jp','selected'=>false),
    array('id'=>106,'name'=>'日本ビジネス株式会社','person'=>'伊藤 健','email'=>'ito@example.co.jp','selected'=>false),
    array('id'=>107,'name'=>'新宿サービス株式会社','person'=>'渡辺 明','email'=>'watanabe@example.co.jp','selected'=>false),
    array('id'=>108,'name'=>'中央商事株式会社','person'=>'中村 優','email'=>'nakamura@example.co.jp','selected'=>false)
);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>アンケート管理</title>

<style>
*{box-sizing:border-box}
body{
    margin:0;
    background:#f5f7fa;
    color:#263238;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}

.header{
    height:64px;
    background:#17365d;
    color:#fff;
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:0 28px
}
.logo{font-size:20px;font-weight:bold}
.header-right{font-size:13px}

.layout{display:flex;min-height:calc(100vh - 64px)}
.sidebar{
    width:225px;
    background:#fff;
    border-right:1px solid #dce3ea;
    padding:16px 12px
}
.nav-title{
    font-size:11px;
    color:#8a96a3;
    font-weight:bold;
    margin:10px 10px 7px
}
.nav button{
    width:100%;
    border:0;
    background:none;
    text-align:left;
    padding:10px 12px;
    border-radius:7px;
    margin-bottom:2px;
    color:#34495e
}
.nav button:hover,
.nav button.active{
    background:#eaf2fb;
    color:#1769aa;
    font-weight:bold
}

.main{
    flex:1;
    padding:28px;
    max-width:1450px
}
.page-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:20px
}
.page-title h1{font-size:25px;margin:0}
.page-title p{font-size:13px;color:#75818d;margin:5px 0 0}

.card{
    background:#fff;
    border:1px solid #dce3ea;
    border-radius:9px;
    padding:20px;
    margin-bottom:18px;
    box-shadow:0 1px 2px rgba(0,0,0,.03)
}

.primary{
    border:0;
    background:#1769aa;
    color:#fff;
    padding:10px 17px;
    border-radius:6px;
    font-weight:bold
}
.primary:hover{background:#12578d}
.secondary{
    border:1px solid #cbd4dd;
    background:#fff;
    color:#34495e;
    padding:9px 14px;
    border-radius:6px
}
.danger{
    border:1px solid #dfaaa5;
    background:#fff;
    color:#bd382d;
    padding:9px 14px;
    border-radius:6px
}
.small-btn{
    border:1px solid #cbd4dd;
    background:#fff;
    color:#41515f;
    padding:6px 9px;
    border-radius:5px;
    font-size:11px
}
.small-btn:hover{background:#f4f7fa}

.stats{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:13px;
    margin-bottom:20px
}
.stat{
    background:#fff;
    border:1px solid #dce3ea;
    border-radius:9px;
    padding:17px
}
.stat-label{font-size:12px;color:#71808e}
.stat-value{font-size:27px;font-weight:bold;margin-top:4px}
.stat-note{font-size:11px;color:#8995a0;margin-top:3px}

.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse}
th,td{
    padding:12px;
    border-bottom:1px solid #e6ebf0;
    text-align:left;
    font-size:13px;
    vertical-align:middle
}
th{
    font-size:11px;
    color:#667582;
    background:#fafbfd
}
.actions{display:flex;gap:5px;flex-wrap:wrap}

.status{
    display:inline-flex;
    align-items:center;
    border-radius:20px;
    padding:5px 9px;
    font-size:11px;
    font-weight:bold;
    white-space:nowrap
}
.status:before{
    content:"";
    width:7px;
    height:7px;
    border-radius:50%;
    background:currentColor;
    margin-right:6px
}
.status.draft{background:#eef1f6;color:#64748b}
.status.waiting{background:#fff4dc;color:#a66b00}
.status.active{background:#e8f6ed;color:#218838}
.status.ended{background:#eef0f2;color:#67727c}
.status.archived{background:#f1edf8;color:#7255a5}

.notice{
    padding:13px 15px;
    border-radius:7px;
    margin-bottom:16px;
    font-size:13px;
    line-height:1.6
}
.notice.info{background:#edf6ff;border:1px solid #c9e3fa;color:#24577e}
.notice.wait{background:#fff8e8;border:1px solid #f2d59b;color:#765311}
.notice.success{background:#edf9f1;border:1px solid #bde2c7;color:#246b35}
.notice.end{background:#f1f3f5;border:1px solid #d8dde2;color:#58636d}
.notice.warn{background:#fff3f1;border:1px solid #efc4bd;color:#96382d}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px
}
.form-group{margin-bottom:15px}
.form-group.full{grid-column:1/-1}
label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:7px
}
.required{
    color:#c0392b;
    font-size:10px;
    margin-left:5px
}
input[type=text],
input[type=password],
input[type=datetime-local],
input[type=email],
input[type=number],
textarea,
select{
    width:100%;
    border:1px solid #cbd4dd;
    border-radius:6px;
    padding:9px 10px;
    background:#fff
}
textarea{min-height:90px;resize:vertical}
.help{
    font-size:11px;
    color:#7b8792;
    margin-top:5px;
    line-height:1.5
}

.step{
    display:flex;
    margin-bottom:22px
}
.step-item{
    flex:1;
    text-align:center;
    position:relative
}
.step-item:before{
    content:"";
    position:absolute;
    top:13px;
    left:0;
    right:0;
    height:2px;
    background:#dce3e9
}
.step-item:first-child:before{left:50%}
.step-item:last-child:before{right:50%}
.step-dot{
    width:27px;
    height:27px;
    display:inline-flex;
    justify-content:center;
    align-items:center;
    border-radius:50%;
    background:#dce3e9;
    color:#64727d;
    position:relative;
    z-index:1;
    font-size:11px;
    font-weight:bold
}
.step-item.done .step-dot,
.step-item.current .step-dot{
    background:#1769aa;
    color:#fff
}
.step-label{
    font-size:10px;
    color:#74818d;
    margin-top:5px
}

.group{
    border:1px solid #cfd9e2;
    border-radius:9px;
    background:#fafcfe;
    margin-bottom:15px
}
.group-head{
    background:#f0f5f9;
    padding:12px 14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    border-bottom:1px solid #d9e1e8
}
.group-title{
    display:flex;
    align-items:center;
    gap:9px
}
.drag-handle{
    cursor:grab;
    color:#82909c;
    font-size:18px
}
.group-body{padding:12px}

.question{
    background:#fff;
    border:1px solid #dce3e9;
    border-radius:8px;
    padding:14px;
    margin-bottom:10px;
    box-shadow:0 1px 1px rgba(0,0,0,.02)
}
.question:last-child{margin-bottom:0}
.question.dragging{
    opacity:.45;
    border:2px dashed #1769aa
}
.question-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-bottom:10px
}
.question-label{
    display:flex;
    align-items:center;
    gap:8px
}
.question-number{
    min-width:36px;
    height:27px;
    display:inline-flex;
    justify-content:center;
    align-items:center;
    background:#eaf2fb;
    color:#1769aa;
    border-radius:5px;
    font-size:11px;
    font-weight:bold
}
.option-row{
    display:flex;
    gap:6px;
    margin-top:7px
}
.option-row input{flex:1}
.question-actions{
    display:flex;
    gap:5px
}

.branch-box{
    margin-top:12px;
    background:#f7fbff;
    border:1px solid #cce1f3;
    border-radius:6px;
    padding:11px
}
.branch-title{
    font-size:11px;
    font-weight:bold;
    color:#1769aa;
    margin-bottom:8px
}
.branch-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
    margin-top:6px
}

.number-mode{
    background:#f8fafc;
    border:1px solid #dce4eb;
    padding:13px;
    border-radius:7px
}

.customer-toolbar{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:13px
}
.customer-selected{
    background:#edf8f1;
    color:#246b35;
    border:1px solid #bde2c7;
    border-radius:5px;
    padding:6px 10px;
    font-size:12px
}

.mail-preview{
    border:1px solid #dce3e9;
    border-radius:7px;
    background:#fff;
    padding:17px;
    line-height:1.7
}
.mail-header{
    border-bottom:1px solid #e4e9ed;
    padding-bottom:12px;
    margin-bottom:13px
}

.modal{
    position:fixed;
    inset:0;
    background:rgba(16,30,44,.48);
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    z-index:1000
}
.modal.show{display:flex}
.modal-box{
    background:#fff;
    border-radius:10px;
    width:100%;
    max-width:620px;
    max-height:90vh;
    overflow:auto;
    padding:24px;
    box-shadow:0 10px 35px rgba(0,0,0,.2)
}
.modal-box h2{
    margin:0 0 12px;
    font-size:19px
}
.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:20px
}

.hidden{display:none!important}
.empty{
    text-align:center;
    padding:40px;
    color:#7b8792
}
.muted{color:#7b8792;font-size:12px}
.tag{
    display:inline-block;
    padding:4px 7px;
    border-radius:4px;
    background:#eef2f6;
    color:#64727d;
    font-size:10px;
    margin-right:4px
}

.setting-card{
    border:1px solid #dce3e9;
    border-radius:8px;
    padding:17px;
    margin-bottom:14px
}
.setting-card h3{
    margin:0 0 8px;
    font-size:15px
}
.setting-status{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:13px
}
.connected{
    color:#218838;
    font-size:12px;
    font-weight:bold
}

@media(max-width:950px){
    .stats{grid-template-columns:repeat(2,1fr)}
    .form-grid{grid-template-columns:1fr}
}
@media(max-width:700px){
    .sidebar{display:none}
    .main{padding:16px}
    .stats{grid-template-columns:1fr 1fr}
    .branch-row{grid-template-columns:1fr}
}
</style>
</head>

<body>

<header class="header">
    <div class="logo">アンケート管理</div>
    <div class="header-right">運営管理者　山田 太郎</div>
</header>

<div class="layout">

<aside class="sidebar">
    <div class="nav-title">管理</div>
    <div class="nav">
        <button onclick="showPage('home')" id="nav-home">ホーム</button>
        <button onclick="showPage('list')" id="nav-list">アンケート一覧</button>
        <button onclick="showPage('create')" id="nav-create">新しいアンケート</button>
    </div>

    <div class="nav-title">回答管理</div>
    <div class="nav">
        <button onclick="showPage('status')" id="nav-status">回答状況</button>
        <button onclick="showPage('answers')" id="nav-answers">回答内容</button>
        <button onclick="showPage('send')" id="nav-send">アンケート送付</button>
    </div>

    <div class="nav-title">設定</div>
    <div class="nav">
        <button onclick="showPage('settings')" id="nav-settings">キントーン・SMTP設定</button>
    </div>

    <div class="nav-title">回答者確認</div>
    <div class="nav">
        <button onclick="showPage('respond')" id="nav-respond">回答者として見る</button>
    </div>
</aside>

<main class="main">

<!-- ================= HOME ================= -->
<section id="page-home" class="page">
    <div class="page-title">
        <div>
            <h1>ホーム</h1>
            <p>アンケートの運営状況を確認できます。</p>
        </div>
        <button class="primary" onclick="newSurvey()">＋ 新しいアンケートを作成</button>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-label">作成中</div>
            <div class="stat-value" id="count-draft">0</div>
            <div class="stat-note">公開前</div>
        </div>
        <div class="stat">
            <div class="stat-label">回答開始待ち</div>
            <div class="stat-value" id="count-wait">0</div>
            <div class="stat-note">公開済み・開始前</div>
        </div>
        <div class="stat">
            <div class="stat-label">回答受付中</div>
            <div class="stat-value" id="count-active">0</div>
            <div class="stat-note">現在回答可能</div>
        </div>
        <div class="stat">
            <div class="stat-label">回答受付終了</div>
            <div class="stat-value" id="count-ended">0</div>
            <div class="stat-note">回答確認可能</div>
        </div>
        <div class="stat">
            <div class="stat-label">保管</div>
            <div class="stat-value" id="count-archived">0</div>
            <div class="stat-note">過去のアンケート</div>
        </div>
    </div>

    <div class="card">
        <div id="home-notice"></div>
        <h2 style="font-size:17px">現在運営中のアンケート</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>アンケート</th>
                        <th>状態</th>
                        <th>受付期間</th>
                        <th>回答数</th>
                        <th>主な操作</th>
                    </tr>
                </thead>
                <tbody id="home-table"></tbody>
            </table>
        </div>
    </div>
</section>

<!-- ================= LIST ================= -->
<section id="page-list" class="page hidden">
    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <p>アンケートの内容・状態・回答状況を管理します。</p>
        </div>
        <button class="primary" onclick="newSurvey()">＋ 新しいアンケート</button>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>アンケート名</th>
                        <th>状態</th>
                        <th>受付期間</th>
                        <th>回答数</th>
                        <th>更新日</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="list-table"></tbody>
            </table>
        </div>
    </div>
</section>

<!-- ================= CREATE ================= -->
<section id="page-create" class="page hidden">

    <div class="page-title">
        <div>
            <h1 id="create-title">アンケートを作成</h1>
            <p>基本情報、質問、分岐、公開条件を設定します。</p>
        </div>
        <div>
            <button class="secondary" onclick="showPage('list')">一覧へ戻る</button>
        </div>
    </div>

    <div class="step">
        <div class="step-item current">
            <span class="step-dot">1</span>
            <div class="step-label">基本情報</div>
        </div>
        <div class="step-item current">
            <span class="step-dot">2</span>
            <div class="step-label">質問</div>
        </div>
        <div class="step-item">
            <span class="step-dot">3</span>
            <div class="step-label">公開前確認</div>
        </div>
    </div>

    <div class="card">
        <h2 style="font-size:17px;margin-top:0">基本情報</h2>

        <div class="form-grid">
            <div class="form-group full">
                <label>アンケート名 <span class="required">必須</span></label>
                <input type="text" id="survey-title" placeholder="例：顧客満足度アンケート">
            </div>

            <div class="form-group full">
                <label>説明文</label>
                <textarea id="survey-description" placeholder="回答者への説明を入力してください。"></textarea>
            </div>

            <div class="form-group">
                <label>回答受付開始日時</label>
                <input type="datetime-local" id="survey-start">
                <div class="help">公開後、この日時までは「公開済み・回答開始待ち」です。</div>
            </div>

            <div class="form-group">
                <label>回答受付終了日時</label>
                <input type="datetime-local" id="survey-end">
            </div>

            <div class="form-group">
                <label>質問番号</label>
                <div class="number-mode">
                    <label style="font-weight:normal">
                        <input type="radio" name="numberMode" value="global" checked>
                        全体で連番
                    </label>
                    <br>
                    <label style="font-weight:normal">
                        <input type="radio" name="numberMode" value="group">
                        グループごとに連番
                    </label>
                    <div class="help">
                        例：全体連番「1,2,3,4」／グループ別「1,2,1,2」
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>回答完了時のメッセージ</label>
                <textarea id="survey-complete" placeholder="ご回答ありがとうございました。"></textarea>
            </div>

            <div class="form-group full">
                <label>回答者への案内</label>
                <textarea id="survey-guide" placeholder="回答にあたっての注意事項など"></textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
            <div>
                <h2 style="font-size:17px;margin:0">質問構成</h2>
                <p class="muted">
                    グループを追加し、質問をドラッグして順番を変更できます。
                </p>
            </div>
            <div>
                <button class="secondary" onclick="addGroup()">＋ グループを追加</button>
                <button class="primary" onclick="addQuestion()">＋ 質問を追加</button>
            </div>
        </div>

        <div class="notice info" style="margin-top:15px">
            <strong>質問の移動：</strong>
            質問左上の「☷」部分をドラッグして、同じグループ内または別のグループへ移動できます。
            グループ間の移動も確認できます。
        </div>

        <div id="groups-container"></div>
    </div>

    <div class="card">
        <div class="notice info">
            <strong>公開前の確認</strong><br>
            公開すると、開始日時が未来の場合は「公開済み・回答開始待ち」、
            開始日時を過ぎている場合は「回答受付中」になります。
        </div>

        <div style="display:flex;justify-content:flex-end;gap:8px">
            <button class="secondary" onclick="saveDraft()">作成中として保存</button>
            <button class="primary" onclick="previewSurvey()">公開前の内容を確認</button>
        </div>
    </div>

</section>

<!-- ================= PREVIEW ================= -->
<section id="page-preview" class="page hidden">
    <div class="page-title">
        <div>
            <h1>公開前確認</h1>
            <p>回答者から見える内容を確認してください。</p>
        </div>
    </div>

    <div class="notice wait">
        <strong>公開前です。</strong>
        内容に問題がなければ「この内容で公開する」を選択してください。
    </div>

    <div class="card" id="preview-content"></div>

    <div class="card">
        <div style="display:flex;justify-content:flex-end;gap:8px">
            <button class="secondary" onclick="showPage('create')">編集画面に戻る</button>
            <button class="primary" onclick="publishSurvey()">この内容で公開する</button>
        </div>
    </div>
</section>

<!-- ================= RESPOND ================= -->
<section id="page-respond" class="page hidden">
    <div class="page-title">
        <div>
            <h1>回答者として見る</h1>
            <p>回答者から見た状態を確認します。</p>
        </div>
        <select id="respond-select" onchange="renderRespond()"></select>
    </div>

    <div id="respond-content"></div>
</section>

<!-- ================= STATUS ================= -->
<section id="page-status" class="page hidden">
    <div class="page-title">
        <div>
            <h1>回答状況</h1>
            <p>回答数や現在の受付状態を確認します。</p>
        </div>
        <select id="status-select" onchange="renderStatus()"></select>
    </div>

    <div id="status-content"></div>
</section>

<!-- ================= ANSWERS ================= -->
<section id="page-answers" class="page hidden">
    <div class="page-title">
        <div>
            <h1>回答内容</h1>
            <p>送信された回答を確認します。</p>
        </div>
        <select id="answers-select" onchange="renderAnswers()"></select>
    </div>

    <div id="answers-content"></div>
</section>

<!-- ================= SEND ================= -->
<section id="page-send" class="page hidden">

    <div class="page-title">
        <div>
            <h1>アンケート送付</h1>
            <p>顧客一覧から送付先を選択し、アンケート案内を送信します。</p>
        </div>
    </div>

    <div id="send-config-notice"></div>

    <div class="card">
        <h2 style="font-size:17px;margin-top:0">1. アンケートを選択</h2>

        <div class="form-group">
            <label>送付するアンケート</label>
            <select id="send-survey" onchange="renderSendSummary()"></select>
        </div>

        <div id="send-survey-summary"></div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
                <h2 style="font-size:17px;margin:0">2. 送付先を選択</h2>
                <p class="muted">顧客一覧はキントーンから取得した想定です。</p>
            </div>
            <button class="secondary" onclick="refreshCustomers()">顧客一覧を更新</button>
        </div>

        <div class="customer-toolbar">
            <button class="small-btn" onclick="selectAllCustomers()">全員を選択</button>
            <button class="small-btn" onclick="clearCustomers()">選択を解除</button>
            <span class="customer-selected" id="selected-count">0件選択</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:45px"><input type="checkbox" id="customer-all" onchange="toggleAllCustomers(this.checked)"></th>
                        <th>顧客名</th>
                        <th>担当者</th>
                        <th>メールアドレス</th>
                    </tr>
                </thead>
                <tbody id="customer-table"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 style="font-size:17px;margin-top:0">3. メール内容</h2>

        <div class="form-group">
            <label>件名</label>
            <input type="text" id="mail-subject" value="アンケートご協力のお願い">
        </div>

        <div class="form-group">
            <label>本文</label>
            <textarea id="mail-body" style="min-height:160px">いつもお世話になっております。

このたび、アンケートへのご協力をお願いしております。

下記アンケートへのご回答をお願いいたします。

アンケート名：
{{アンケート名}}

ご協力のほど、よろしくお願いいたします。</textarea>
        </div>

        <div class="mail-preview">
            <div class="mail-header">
                <strong>メール送信イメージ</strong>
            </div>
            <div id="mail-preview-text"></div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:18px">
            <button class="primary" onclick="confirmSendMail()">選択した顧客へメールを送信する</button>
        </div>
    </div>
</section>

<!-- ================= SETTINGS ================= -->
<section id="page-settings" class="page hidden">

    <div class="page-title">
        <div>
            <h1>キントーン・SMTP設定</h1>
            <p>顧客取得とメール送信に必要な設定を確認します。</p>
        </div>
    </div>

    <div class="notice info">
        実際の接続を行う画面では、登録した設定の状態と接続確認結果を分かりやすく表示します。
        このモックでは接続確認の操作をシミュレーションします。
    </div>

    <div class="card">
        <div class="setting-card">
            <div class="setting-status">
                <div>
                    <h3>キントーン 顧客一覧設定</h3>
                    <div class="muted">顧客一覧を取得するための設定</div>
                </div>
                <div class="connected" id="kintone-status">● 設定済み</div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>接続先</label>
                    <input type="text" id="kintone-url" value="https://example.cybozu.com">
                </div>
                <div class="form-group">
                    <label>アプリ番号</label>
                    <input type="number" id="kintone-app" value="123">
                </div>
                <div class="form-group">
                    <label>ログイン情報</label>
                    <input type="text" id="kintone-user" value="survey-admin">
                </div>
                <div class="form-group">
                    <label>接続情報</label>
                    <input type="password" value="********">
                </div>
            </div>

            <div class="help">
                顧客名、担当者名、メールアドレスなど、アンケート送付に必要な顧客情報を取得する想定です。
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:14px">
                <button class="secondary" onclick="testKintone()">キントーン接続を確認する</button>
            </div>
        </div>

        <div class="setting-card">
            <div class="setting-status">
                <div>
                    <h3>SMTPメール設定</h3>
                    <div class="muted">アンケート案内メールを送信するための設定</div>
                </div>
                <div class="connected" id="smtp-status">● 設定済み</div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>SMTPサーバー</label>
                    <input type="text" id="smtp-host" value="smtp.example.co.jp">
                </div>
                <div class="form-group">
                    <label>ポート番号</label>
                    <input type="number" id="smtp-port" value="587">
                </div>
                <div class="form-group">
                    <label>送信元メールアドレス</label>
                    <input type="email" id="smtp-from" value="survey@example.co.jp">
                </div>
                <div class="form-group">
                    <label>送信元表示名</label>
                    <input type="text" id="smtp-name" value="アンケート事務局">
                </div>
                <div class="form-group">
                    <label>認証情報</label>
                    <input type="text" value="survey-admin">
                </div>
                <div class="form-group">
                    <label>認証パスワード</label>
                    <input type="password" value="********">
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:14px">
                <button class="secondary" onclick="testSMTP()">SMTP接続を確認する</button>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end">
            <button class="primary" onclick="saveSettings()">設定を保存する</button>
        </div>
    </div>

</section>

</main>
</div>

<!-- ================= MODAL ================= -->
<div class="modal" id="modal">
    <div class="modal-box">
        <h2 id="modal-title">確認</h2>
        <div id="modal-message"></div>
        <div class="modal-actions">
            <button class="secondary" onclick="closeModal()">キャンセル</button>
            <button class="primary" id="modal-ok">実行する</button>
        </div>
    </div>
</div>

<script>
var surveys = <?php echo json_encode($initialSurveys,JSON_UNESCAPED_UNICODE); ?>;
var customers = <?php echo json_encode($initialCustomers,JSON_UNESCAPED_UNICODE); ?>;

var groups = [];
var editingSurveyId = null;
var modalAction = null;
var dragQuestion = null;
var kintoneConfigured = true;
var smtpConfigured = true;

/* ---------------------------------------
   初期質問
--------------------------------------- */
function defaultGroups(){
    return [
        {
            id:'g1',
            name:'基本情報',
            questions:[
                {
                    id:'q1',
                    text:'現在のサービスに満足していますか？',
                    type:'single',
                    required:true,
                    options:['とても満足','満足','どちらともいえない','不満','とても不満'],
                    branch:[]
                },
                {
                    id:'q2',
                    text:'サービスを利用した期間を教えてください。',
                    type:'single',
                    required:true,
                    options:['1年未満','1～3年','3～5年','5年以上'],
                    branch:[]
                }
            ]
        },
        {
            id:'g2',
            name:'ご意見',
            questions:[
                {
                    id:'q3',
                    text:'改善してほしいことがあれば教えてください。',
                    type:'text',
                    required:false,
                    options:[],
                    branch:[]
                }
            ]
        }
    ];
}

/* ---------------------------------------
   画面切替
--------------------------------------- */
function showPage(page){
    var pages=document.querySelectorAll('.page');
    for(var i=0;i<pages.length;i++){
        pages[i].classList.add('hidden');
    }

    var target=document.getElementById('page-'+page);
    if(target)target.classList.remove('hidden');

    var navs=document.querySelectorAll('.nav button');
    for(var j=0;j<navs.length;j++)navs[j].classList.remove('active');

    var nav=document.getElementById('nav-'+page);
    if(nav)nav.classList.add('active');

    if(page==='home')renderHome();
    if(page==='list')renderList();
    if(page==='create')renderGroups();
    if(page==='respond')setupRespond();
    if(page==='status')setupStatus();
    if(page==='answers')setupAnswers();
    if(page==='send')setupSend();
    if(page==='settings')updateSettings();

    window.scrollTo(0,0);
}

/* ---------------------------------------
   状態
--------------------------------------- */
function statusName(status){
    var map={
        draft:'作成中',
        waiting:'公開済み・回答開始待ち',
        active:'回答受付中',
        ended:'回答受付終了',
        archived:'保管'
    };
    return map[status]||status;
}

function badge(status){
    return '<span class="status '+status+'">'+statusName(status)+'</span>';
}

function findSurvey(id){
    id=parseInt(id,10);
    for(var i=0;i<surveys.length;i++){
        if(parseInt(surveys[i].id,10)===id)return surveys[i];
    }
    return null;
}

/* ---------------------------------------
   HOME
--------------------------------------- */
function renderHome(){
    var count={draft:0,waiting:0,active:0,ended:0,archived:0};

    surveys.forEach(function(s){
        if(count[s.status]!==undefined)count[s.status]++;
    });

    document.getElementById('count-draft').textContent=count.draft;
    document.getElementById('count-wait').textContent=count.waiting;
    document.getElementById('count-active').textContent=count.active;
    document.getElementById('count-ended').textContent=count.ended;
    document.getElementById('count-archived').textContent=count.archived;

    var html='';
    surveys.forEach(function(s){
        if(s.status==='draft'||s.status==='waiting'||s.status==='active'){
            html+=surveyRow(s);
        }
    });

    document.getElementById('home-table').innerHTML=html||
        '<tr><td colspan="5" class="empty">現在運営中のアンケートはありません。</td></tr>';

    if(count.waiting>0){
        document.getElementById('home-notice').innerHTML=
            '<div class="notice wait"><strong>回答開始待ちがあります。</strong><br>'+
            '公開済みですが、まだ回答受付開始日時になっていないアンケートがあります。</div>';
    }else{
        document.getElementById('home-notice').innerHTML='';
    }
}

function surveyRow(s){
    return '<tr>'+
        '<td><strong>'+esc(s.title)+'</strong><br>'+
        '<span class="muted">'+esc(s.description)+'</span></td>'+
        '<td>'+badge(s.status)+'</td>'+
        '<td>'+esc(s.start||'未設定')+'<br>'+esc(s.end||'未設定')+'</td>'+
        '<td>'+s.answers+'件</td>'+
        '<td>'+actionsFor(s)+'</td>'+
        '</tr>';
}

/* ---------------------------------------
   LIST
--------------------------------------- */
function renderList(){
    var html='';

    surveys.forEach(function(s){
        html+='<tr>'+
            '<td><strong>'+esc(s.title)+'</strong></td>'+
            '<td>'+badge(s.status)+'</td>'+
            '<td>'+esc(s.start||'未設定')+'<br>'+esc(s.end||'未設定')+'</td>'+
            '<td>'+s.answers+'件</td>'+
            '<td>'+esc(s.updated)+'</td>'+
            '<td>'+actionsFor(s)+'</td>'+
            '</tr>';
    });

    document.getElementById('list-table').innerHTML=html;
}

function actionsFor(s){
    var html='<div class="actions">';

    html+='<button class="small-btn" onclick="viewSurvey('+s.id+')">内容を見る</button>';

    if(s.status==='draft'){
        html+='<button class="small-btn" onclick="editSurvey('+s.id+')">編集する</button>';
        html+='<button class="small-btn" onclick="editQuestions('+s.id+')">質問を編集</button>';
    }

    if(s.status==='waiting'){
        html+='<button class="small-btn" onclick="editQuestions('+s.id+')">質問を確認</button>';
        html+='<button class="small-btn" onclick="openSend('+s.id+')">アンケート送付</button>';
    }

    if(s.status==='active'){
        html+='<button class="small-btn" onclick="editQuestions('+s.id+')">質問を確認</button>';
        html+='<button class="small-btn" onclick="openSend('+s.id+')">アンケート送付</button>';
        html+='<button class="small-btn" onclick="openStatus('+s.id+')">回答状況</button>';
        html+='<button class="small-btn" onclick="openAnswers('+s.id+')">回答内容</button>';
        html+='<button class="small-btn" onclick="confirmEnd('+s.id+')">回答受付を終了</button>';
    }

    if(s.status==='ended'){
        html+='<button class="small-btn" onclick="openStatus('+s.id+')">回答状況</button>';
        html+='<button class="small-btn" onclick="openAnswers('+s.id+')">回答内容</button>';
        html+='<button class="small-btn" onclick="confirmArchive('+s.id+')">保管する</button>';
    }

    if(s.status==='archived'){
        html+='<button class="small-btn" onclick="openStatus('+s.id+')">回答状況</button>';
        html+='<button class="small-btn" onclick="openAnswers('+s.id+')">回答内容</button>';
    }

    html+='</div>';
    return html;
}

/* ---------------------------------------
   NEW / EDIT
--------------------------------------- */
function newSurvey(){
    editingSurveyId=null;
    document.getElementById('create-title').textContent='アンケートを作成';

    document.getElementById('survey-title').value='';
    document.getElementById('survey-description').value='';
    document.getElementById('survey-start').value='';
    document.getElementById('survey-end').value='';
    document.getElementById('survey-complete').value='ご回答ありがとうございました。';
    document.getElementById('survey-guide').value='';

    groups=defaultGroups();

    var radios=document.getElementsByName('numberMode');
    for(var i=0;i<radios.length;i++){
        radios[i].checked=radios[i].value==='global';
    }

    showPage('create');
}

function editSurvey(id){
    var s=findSurvey(id);
    if(!s)return;

    if(s.status!=='draft'){
        alert('公開後のアンケートは自由に編集できません。\\n回答内容との整合性を保つため、質問編集は公開前に行ってください。');
        return;
    }

    editingSurveyId=id;
    document.getElementById('create-title').textContent='アンケートを編集';

    document.getElementById('survey-title').value=s.title;
    document.getElementById('survey-description').value=s.description;
    document.getElementById('survey-start').value='';
    document.getElementById('survey-end').value='';
    document.getElementById('survey-complete').value='ご回答ありがとうございました。';
    document.getElementById('survey-guide').value='';

    groups=defaultGroups();
    showPage('create');
}

function editQuestions(id){
    var s=findSurvey(id);
    if(!s)return;

    if(s.status!=='draft'){
        alert('公開済みのアンケートは質問内容を変更できません。\\n内容を確認する場合は「内容を見る」を使用してください。');
        return;
    }

    editingSurveyId=id;
    document.getElementById('create-title').textContent='アンケートを編集';

    document.getElementById('survey-title').value=s.title;
    document.getElementById('survey-description').value=s.description;

    groups=defaultGroups();
    showPage('create');
    setTimeout(function(){
        document.getElementById('groups-container').scrollIntoView();
    },100);
}

/* ---------------------------------------
   GROUP
--------------------------------------- */
function addGroup(){
    groups.push({
        id:'g'+Date.now(),
        name:'新しいグループ',
        questions:[]
    });
    renderGroups();
}

function renameGroup(index,value){
    groups[index].name=value;
}

function removeGroup(index){
    if(groups.length<=1){
        alert('グループは1つ以上必要です。');
        return;
    }

    if(groups[index].questions.length>0){
        if(!confirm('このグループには質問があります。グループと質問を削除しますか？'))return;
    }

    groups.splice(index,1);
    renderGroups();
}

/* ---------------------------------------
   QUESTION
--------------------------------------- */
function addQuestion(groupIndex){
    if(typeof groupIndex==='undefined')groupIndex=0;

    if(!groups.length){
        addGroup();
        groupIndex=0;
    }

    groups[groupIndex].questions.push({
        id:'q'+Date.now()+Math.floor(Math.random()*1000),
        text:'',
        type:'single',
        required:true,
        options:['選択肢1','選択肢2'],
        branch:[]
    });

    renderGroups();
}

function deleteQuestion(gi,qi){
    if(!confirm('この質問を削除しますか？'))return;

    groups[gi].questions.splice(qi,1);
    renderGroups();
}

function updateQuestion(gi,qi,key,value){
    groups[gi].questions[qi][key]=value;
}

function updateOption(gi,qi,oi,value){
    groups[gi].questions[qi].options[oi]=value;
}

function addOption(gi,qi){
    groups[gi].questions[qi].options.push('新しい選択肢');
    renderGroups();
}

function removeOption(gi,qi,oi){
    if(groups[gi].questions[qi].options.length<=1){
        alert('選択肢は1つ以上必要です。');
        return;
    }
    groups[gi].questions[qi].options.splice(oi,1);
    renderGroups();
}

function changeQuestionType(gi,qi,value){
    groups[gi].questions[qi].type=value;

    if(value==='single'||value==='multi'){
        if(groups[gi].questions[qi].options.length===0){
            groups[gi].questions[qi].options=['選択肢1','選択肢2'];
        }
    }

    if(value!=='single'){
        groups[gi].questions[qi].branch=[];
    }

    renderGroups();
}

/* ---------------------------------------
   BRANCH
--------------------------------------- */
function updateBranch(gi,qi,oi,target){
    var q=groups[gi].questions[qi];

    if(!q.branch)q.branch=[];

    q.branch[oi]=target;
}

function renderBranch(gi,qi){
    var q=groups[gi].questions[qi];

    if(q.type!=='single')return '';

    var html='<div class="branch-box">'+
        '<div class="branch-title">回答による分岐</div>'+
        '<div class="help">選択肢ごとに、次に表示する質問またはグループを指定できます。</div>';

    q.options.forEach(function(option,oi){
        var current=(q.branch&&q.branch[oi])?q.branch[oi]:'next';

        html+='<div class="branch-row">'+
            '<div><strong style="font-size:11px">'+esc(option)+'</strong></div>'+
            '<select onchange="updateBranch('+gi+','+qi+','+oi+',this.value)">'+
                '<option value="next" '+(current==='next'?'selected':'')+'>次の質問へ</option>'+
                '<option value="end" '+(current==='end'?'selected':'')+'>回答終了へ</option>';

        groups.forEach(function(g,gidx){
            html+='<option value="group:'+gidx+'" '+(current==='group:'+gidx?'selected':'')+'>'+
                '「'+esc(g.name)+'」へ</option>';

            g.questions.forEach(function(other,oq){
                if(gidx===gi && oq===qi)return;

                html+='<option value="question:'+gidx+':'+oq+'" '+
                    (current==='question:'+gidx+':'+oq?'selected':'')+'>'+
                    '質問 '+(getQuestionNumber(gidx,oq))+' へ</option>';
            });
        });

        html+='</select></div>';
    });

    html+='</div>';
    return html;
}

/* ---------------------------------------
   NUMBER
--------------------------------------- */
function numberMode(){
    var radios=document.getElementsByName('numberMode');
    for(var i=0;i<radios.length;i++){
        if(radios[i].checked)return radios[i].value;
    }
    return 'global';
}

function getQuestionNumber(gi,qi){
    if(numberMode()==='group'){
        return qi+1;
    }

    var n=0;
    for(var g=0;g<groups.length;g++){
        for(var q=0;q<groups[g].questions.length;q++){
            n++;
            if(g===gi && q===qi)return n;
        }
    }
    return '';
}

/* ---------------------------------------
   DRAG DROP
--------------------------------------- */
function startDrag(e,gi,qi){
    dragQuestion={gi:gi,qi:qi};
    e.dataTransfer.effectAllowed='move';
    e.dataTransfer.setData('text/plain',gi+':'+qi);

    setTimeout(function(){
        e.currentTarget.classList.add('dragging');
    },0);
}

function endDrag(e){
    e.currentTarget.classList.remove('dragging');
    dragQuestion=null;
}

function allowDrop(e){
    e.preventDefault();
}

function dropQuestion(e,targetGi,targetQi){
    e.preventDefault();

    if(!dragQuestion)return;

    var fromGi=dragQuestion.gi;
    var fromQi=dragQuestion.qi;

    if(fromGi===targetGi && fromQi===targetQi){
        dragQuestion=null;
        renderGroups();
        return;
    }

    var item=groups[fromGi].questions.splice(fromQi,1)[0];

    if(fromGi===targetGi && fromQi<targetQi){
        targetQi--;
    }

    groups[targetGi].questions.splice(targetQi,0,item);

    dragQuestion=null;
    renderGroups();
}

function dropToGroup(e,targetGi){
    e.preventDefault();

    if(!dragQuestion)return;

    var fromGi=dragQuestion.gi;
    var fromQi=dragQuestion.qi;

    var item=groups[fromGi].questions.splice(fromQi,1)[0];

    groups[targetGi].questions.push(item);

    dragQuestion=null;
    renderGroups();
}

/* ---------------------------------------
   RENDER GROUPS
--------------------------------------- */
function renderGroups(){
    var container=document.getElementById('groups-container');

    if(!groups.length){
        container.innerHTML='<div class="empty">グループがありません。</div>';
        return;
    }

    var html='';

    groups.forEach(function(g,gi){

        html+='<div class="group" ondragover="allowDrop(event)" ondrop="dropToGroup(event,'+gi+')">'+

            '<div class="group-head">'+
                '<div class="group-title">'+
                    '<span class="drag-handle">☷</span>'+
                    '<input type="text" value="'+escAttr(g.name)+'" '+
                        'oninput="renameGroup('+gi+',this.value)" '+
                        'style="width:220px;padding:6px 8px">'+
                '</div>'+
                '<div class="actions">'+
                    '<button class="small-btn" onclick="addQuestion('+gi+')">＋ 質問を追加</button>'+
                    '<button class="small-btn" onclick="removeGroup('+gi+')">グループを削除</button>'+
                '</div>'+
            '</div>'+

            '<div class="group-body">';

        if(!g.questions.length){
            html+='<div class="empty" style="padding:20px">ここに質問をドラッグできます。</div>';
        }

        g.questions.forEach(function(q,qi){

            html+='<div class="question" draggable="true" '+
                'ondragstart="startDrag(event,'+gi+','+qi+')" '+
                'ondragend="endDrag(event)" '+
                'ondragover="allowDrop(event)" '+
                'ondrop="dropQuestion(event,'+gi+','+qi+')">'+

                '<div class="question-head">'+
                    '<div class="question-label">'+
                        '<span class="drag-handle">☷</span>'+
                        '<span class="question-number">Q'+getQuestionNumber(gi,qi)+'</span>'+
                        '<strong>質問</strong>'+
                    '</div>'+
                    '<div class="question-actions">'+
                        '<button class="small-btn" onclick="deleteQuestion('+gi+','+qi+')">削除</button>'+
                    '</div>'+
                '</div>'+

                '<div class="form-group">'+
                    '<label>質問文 <span class="required">必須</span></label>'+
                    '<input type="text" value="'+escAttr(q.text)+'" '+
                    'oninput="updateQuestion('+gi+','+qi+',\'text\',this.value)" '+
                    'placeholder="質問を入力してください">'+
                '</div>'+

                '<div class="form-grid">'+

                    '<div class="form-group">'+
                        '<label>回答形式</label>'+
                        '<select onchange="changeQuestionType('+gi+','+qi+',this.value)">'+
                            '<option value="text" '+(q.type==='text'?'selected':'')+'>文章を入力する</option>'+
                            '<option value="single" '+(q.type==='single'?'selected':'')+'>1つだけ選ぶ</option>'+
                            '<option value="multi" '+(q.type==='multi'?'selected':'')+'>複数選ぶ</option>'+
                            '<option value="rating" '+(q.type==='rating'?'selected':'')+'>段階的に評価する</option>'+
                        '</select>'+
                    '</div>'+

                    '<div class="form-group">'+
                        '<label>回答設定</label>'+
                        '<label style="font-weight:normal">'+
                            '<input type="checkbox" '+(q.required?'checked':'')+
                            ' onchange="updateQuestion('+gi+','+qi+',\'required\',this.checked)"> '+
                            '必須回答にする'+
                        '</label>'+
                    '</div>'+

                '</div>';

            if(q.type==='single'||q.type==='multi'){

                html+='<div class="form-group">'+
                    '<label>選択肢</label>';

                q.options.forEach(function(o,oi){
                    html+='<div class="option-row">'+
                        '<input type="text" value="'+escAttr(o)+'" '+
                        'oninput="updateOption('+gi+','+qi+','+oi+',this.value)">'+
                        '<button class="small-btn" onclick="removeOption('+gi+','+qi+','+oi+')">削除</button>'+
                        '</div>';
                });

                html+='<button class="small-btn" style="margin-top:8px" onclick="addOption('+gi+','+qi+')">＋ 選択肢を追加</button>'+
                    '</div>';
            }

            if(q.type==='single'){
                html+=renderBranch(gi,qi);
            }

            html+='<div class="form-group" style="margin-top:12px">'+
                '<label>補足説明</label>'+
                '<input type="text" placeholder="必要に応じて補足説明を入力">'+
                '</div>';

            html+='</div>';
        });

        html+='</div></div>';
    });

    container.innerHTML=html;
}

/* ---------------------------------------
   SAVE / PREVIEW / PUBLISH
--------------------------------------- */
function validateSurvey(){
    var title=document.getElementById('survey-title').value.trim();

    if(!title){
        alert('アンケート名を入力してください。');
        return false;
    }

    var total=0;

    for(var g=0;g<groups.length;g++){
        if(!groups[g].name.trim()){
            alert('グループ名を入力してください。');
            return false;
        }

        for(var q=0;q<groups[g].questions.length;q++){
            total++;

            if(!groups[g].questions[q].text.trim()){
                alert('質問 '+getQuestionNumber(g,q)+' の質問文を入力してください。');
                return false;
            }

            var type=groups[g].questions[q].type;

            if((type==='single'||type==='multi')&&groups[g].questions[q].options.length===0){
                alert('質問 '+getQuestionNumber(g,q)+' の選択肢を設定してください。');
                return false;
            }
        }
    }

    if(total===0){
        alert('質問を1問以上登録してください。');
        return false;
    }

    var start=document.getElementById('survey-start').value;
    var end=document.getElementById('survey-end').value;

    if(start&&end&&new Date(start)>=new Date(end)){
        alert('回答受付終了日時は、開始日時より後に設定してください。');
        return false;
    }

    return true;
}

function saveDraft(){
    if(!validateSurvey())return;

    var id=editingSurveyId||Date.now();
    var old=findSurvey(id);

    if(old){
        old.title=document.getElementById('survey-title').value;
        old.description=document.getElementById('survey-description').value;
        old.updated='2026/09/16';
    }else{
        surveys.unshift({
            id:id,
            title:document.getElementById('survey-title').value,
            description:document.getElementById('survey-description').value,
            status:'draft',
            start:document.getElementById('survey-start').value||'',
            end:document.getElementById('survey-end').value||'',
            answers:0,
            updated:'2026/09/16'
        });
    }

    alert('作成中として保存しました。');
    showPage('list');
}

function previewSurvey(){
    if(!validateSurvey())return;

    var title=document.getElementById('survey-title').value;
    var description=document.getElementById('survey-description').value;

    var html='<h2 style="margin-top:0">'+esc(title)+'</h2>';

    if(description){
        html+='<p style="line-height:1.7">'+nl2br(description)+'</p>';
    }

    html+='<div class="notice info">'+
        '<strong>質問番号：</strong>'+
        (numberMode()==='global'?'全体で連番':'グループごとに連番')+
        '</div>';

    groups.forEach(function(g,gi){

        html+='<div style="margin-top:22px">'+
            '<h3 style="font-size:15px;border-left:4px solid #1769aa;padding-left:9px">'+
            esc(g.name)+'</h3>';

        g.questions.forEach(function(q,qi){

            html+='<div style="padding:14px 0;border-bottom:1px solid #e5e9ed">'+
                '<div style="font-weight:bold;margin-bottom:9px">'+
                'Q'+getQuestionNumber(gi,qi)+'. '+esc(q.text)+
                (q.required?'<span class="tag" style="color:#b13b32">必須</span>':'')+
                '</div>';

            if(q.type==='text'){
                html+='<textarea placeholder="回答を入力してください"></textarea>';
            }else if(q.type==='rating'){
                for(var r=1;r<=5;r++){
                    html+='<label style="display:inline-block;margin-right:15px;font-weight:normal">'+
                        '<input type="radio" name="preview_'+gi+'_'+qi+'"> '+r+
                        '</label>';
                }
            }else{
                q.options.forEach(function(o){
                    html+='<div style="margin:7px 0">'+
                        '<label style="font-weight:normal">'+
                        '<input type="'+(q.type==='multi'?'checkbox':'radio')+'" '+
                        'name="preview_'+gi+'_'+qi+'"> '+esc(o)+
                        '</label></div>';
                });
            }

            html+='</div>';
        });

        html+='</div>';
    });

    document.getElementById('preview-content').innerHTML=html;
    showPage('preview');
}

function publishSurvey(){
    openModal(
        'アンケートを公開します',
        '<div class="notice wait">'+
        '公開後は、回答受付開始日時によって状態が決まります。<br><br>'+
        '<strong>開始日時が未来：</strong> 公開済み・回答開始待ち<br>'+
        '<strong>開始日時が現在以前：</strong> 回答受付中'+
        '</div>'+
        'この内容で公開してよろしいですか？',
        function(){

            var start=document.getElementById('survey-start').value;
            var status='active';

            if(start&&new Date(start)>new Date()){
                status='waiting';
            }

            var id=editingSurveyId||Date.now();
            var old=findSurvey(id);

            if(old){
                old.title=document.getElementById('survey-title').value;
                old.description=document.getElementById('survey-description').value;
                old.start=start||'未設定';
                old.end=document.getElementById('survey-end').value||'未設定';
                old.status=status;
                old.updated='2026/09/16';
            }else{
                surveys.unshift({
                    id:id,
                    title:document.getElementById('survey-title').value,
                    description:document.getElementById('survey-description').value,
                    status:status,
                    start:start||'未設定',
                    end:document.getElementById('survey-end').value||'未設定',
                    answers:0,
                    updated:'2026/09/16'
                });
            }

            alert('アンケートを公開しました。\\n現在の状態：'+statusName(status));
            showPage('list');
        }
    );
}

/* ---------------------------------------
   RESPOND
--------------------------------------- */
function setupRespond(){
    var select=document.getElementById('respond-select');
    select.innerHTML='';

    surveys.forEach(function(s){
        select.innerHTML+=
            '<option value="'+s.id+'">'+esc(s.title)+'（'+statusName(s.status)+'）</option>';
    });

    renderRespond();
}

function renderRespond(){
    var s=findSurvey(document.getElementById('respond-select').value);
    if(!s)return;

    var box=document.getElementById('respond-content');

    if(s.status==='draft'){
        box.innerHTML='<div class="notice info"><strong>このアンケートはまだ公開されていません。</strong></div>';
        return;
    }

    if(s.status==='waiting'){
        box.innerHTML='<div class="card">'+
            badge(s.status)+
            '<h2>'+esc(s.title)+'</h2>'+
            '<p>'+esc(s.description)+'</p>'+
            '<div class="notice wait"><strong>回答受付開始前です。</strong><br>'+
            '回答受付開始：'+esc(s.start)+'</div>'+
            '</div>';
        return;
    }

    if(s.status==='ended'||s.status==='archived'){
        box.innerHTML='<div class="card">'+
            badge(s.status)+
            '<h2>'+esc(s.title)+'</h2>'+
            '<p>'+esc(s.description)+'</p>'+
            '<div class="notice end"><strong>回答受付は終了しています。</strong></div>'+
            '</div>';
        return;
    }

    box.innerHTML='<div class="card">'+
        '<div class="notice success">現在、回答を受け付けています。</div>'+
        '<h2>'+esc(s.title)+'</h2>'+
        '<p>'+esc(s.description)+'</p>'+
        '<div class="notice info">'+
        'このモックでは分岐回答も確認できます。'+
        '</div>'+
        '<div style="display:flex;justify-content:flex-end">'+
        '<button class="primary" onclick="alert(\'回答入力 → 確認 → 送信の流れを確認できます。\\n実際の質問内容はアンケートごとに表示されます。\')">'+
        '回答を開始する</button>'+
        '</div>'+
        '</div>';
}

/* ---------------------------------------
   STATUS / ANSWERS
--------------------------------------- */
function setupStatus(){
    var s=document.getElementById('status-select');
    s.innerHTML='';
    surveys.forEach(function(x){
        s.innerHTML+='<option value="'+x.id+'">'+esc(x.title)+'</option>';
    });
    renderStatus();
}

function renderStatus(){
    var s=findSurvey(document.getElementById('status-select').value);
    if(!s)return;

    document.getElementById('status-content').innerHTML=
        '<div class="card">'+
        '<div style="display:flex;justify-content:space-between">'+
            '<div><h2 style="margin:0 0 7px">'+esc(s.title)+'</h2>'+badge(s.status)+'</div>'+
            '<button class="secondary" onclick="showPage(\'list\')">一覧へ戻る</button>'+
        '</div>'+
        '<div class="stats" style="grid-template-columns:repeat(3,1fr);margin-top:20px">'+
            '<div class="stat"><div class="stat-label">回答数</div><div class="stat-value">'+s.answers+'</div></div>'+
            '<div class="stat"><div class="stat-label">開始</div><div class="stat-value" style="font-size:15px">'+esc(s.start)+'</div></div>'+
            '<div class="stat"><div class="stat-label">終了</div><div class="stat-value" style="font-size:15px">'+esc(s.end)+'</div></div>'+
        '</div>'+
        '</div>';
}

function openStatus(id){
    document.getElementById('status-select').value=id;
    showPage('status');
    renderStatus();
}

function setupAnswers(){
    var s=document.getElementById('answers-select');
    s.innerHTML='';
    surveys.forEach(function(x){
        s.innerHTML+='<option value="'+x.id+'">'+esc(x.title)+'</option>';
    });
    renderAnswers();
}

function renderAnswers(){
    var s=findSurvey(document.getElementById('answers-select').value);
    if(!s)return;

    if(s.answers===0){
        document.getElementById('answers-content').innerHTML=
            '<div class="card"><div class="empty">まだ回答はありません。</div></div>';
        return;
    }

    var html='<div class="card">'+
        '<h2>'+esc(s.title)+'</h2>'+
        '<div class="notice info">回答数：'+s.answers+'件</div>';

    for(var i=1;i<=Math.min(s.answers,5);i++){
        html+='<div class="question">'+
            '<strong>回答 #'+i+'</strong>'+
            '<div class="muted" style="margin-top:5px">2026/09/'+(10+i)+' 10:2'+i+'</div>'+
            '<p><strong>Q1</strong><br>満足</p>'+
            '<p><strong>Q2</strong><br>特にありません。</p>'+
            '</div>';
    }

    html+='</div>';
    document.getElementById('answers-content').innerHTML=html;
}

function openAnswers(id){
    document.getElementById('answers-select').value=id;
    showPage('answers');
    renderAnswers();
}

/* ---------------------------------------
   END / ARCHIVE
--------------------------------------- */
function confirmEnd(id){
    var s=findSurvey(id);

    openModal(
        '回答受付を終了します',
        '「'+esc(s.title)+'」の回答受付を終了します。<br><br>'+
        '終了すると、新しい回答を受け付けなくなります。<br>'+
        '終了後も回答内容と回答状況は確認できます。',
        function(){
            s.status='ended';
            s.updated='2026/09/16';
            alert('回答受付を終了しました。');
            showPage('list');
        }
    );
}

function confirmArchive(id){
    var s=findSurvey(id);

    openModal(
        'アンケートを保管します',
        '「'+esc(s.title)+'」を保管します。<br><br>'+
        '保管後も回答内容と回答状況は確認できます。',
        function(){
            s.status='archived';
            s.updated='2026/09/16';
            alert('アンケートを保管しました。');
            showPage('list');
        }
    );
}

/* ---------------------------------------
   SEND
--------------------------------------- */
function setupSend(){
    var select=document.getElementById('send-survey');
    select.innerHTML='';

    surveys.forEach(function(s){
        if(s.status==='waiting'||s.status==='active'){
            select.innerHTML+=
                '<option value="'+s.id+'">'+esc(s.title)+'（'+statusName(s.status)+'）</option>';
        }
    });

    renderCustomers();
    renderSendSummary();
    renderMailPreview();
}

function openSend(id){
    showPage('send');
    setTimeout(function(){
        document.getElementById('send-survey').value=id;
        renderSendSummary();
    },50);
}

function renderSendSummary(){
    var s=findSurvey(document.getElementById('send-survey').value);

    if(!s){
        document.getElementById('send-survey-summary').innerHTML=
            '<div class="notice warn">送付可能なアンケートがありません。</div>';
        return;
    }

    document.getElementById('send-survey-summary').innerHTML=
        '<div class="notice info">'+
        '<strong>'+esc(s.title)+'</strong><br>'+
        '状態：'+statusName(s.status)+'<br>'+
        '回答受付：'+esc(s.start)+' ～ '+esc(s.end)+
        '</div>';

    renderMailPreview();
}

function renderCustomers(){
    var html='';

    customers.forEach(function(c){
        html+='<tr>'+
            '<td><input type="checkbox" class="customer-check" data-id="'+c.id+'" '+
            (c.selected?'checked':'')+
            ' onchange="toggleCustomer('+c.id+',this.checked)"></td>'+
            '<td><strong>'+esc(c.name)+'</strong></td>'+
            '<td>'+esc(c.person)+'</td>'+
            '<td>'+esc(c.email)+'</td>'+
            '</tr>';
    });

    document.getElementById('customer-table').innerHTML=html;
    updateSelectedCount();
}

function toggleCustomer(id,checked){
    customers.forEach(function(c){
        if(c.id===id)c.selected=checked;
    });
    updateSelectedCount();
}

function toggleAllCustomers(checked){
    customers.forEach(function(c){c.selected=checked;});
    renderCustomers();
}

function selectAllCustomers(){
    customers.forEach(function(c){c.selected=true;});
    renderCustomers();
}

function clearCustomers(){
    customers.forEach(function(c){c.selected=false;});
    renderCustomers();
}

function updateSelectedCount(){
    var count=0;
    customers.forEach(function(c){
        if(c.selected)count++;
    });
    document.getElementById('selected-count').textContent=count+'件選択';
}

function refreshCustomers(){
    openModal(
        '顧客一覧を更新します',
        'キントーンから顧客一覧を取得する想定です。<br><br>'+
        'モックでは最新の顧客一覧が取得されたものとして表示を更新します。',
        function(){
            alert('キントーンから顧客一覧を取得しました。');
            renderCustomers();
        }
    );
}

function renderMailPreview(){
    var s=findSurvey(document.getElementById('send-survey').value);
    if(!s)return;

    var subject=document.getElementById('mail-subject').value;
    var body=document.getElementById('mail-body').value;

    body=body.replace(/\{\{アンケート名\}\}/g,s.title);

    document.getElementById('mail-preview-text').innerHTML=
        '<strong>件名：</strong>'+esc(subject)+
        '<br><br>'+nl2br(body);
}

function confirmSendMail(){
    var s=findSurvey(document.getElementById('send-survey').value);

    var selected=customers.filter(function(c){return c.selected;});

    if(!s){
        alert('送付するアンケートを選択してください。');
        return;
    }

    if(selected.length===0){
        alert('送付先を1件以上選択してください。');
        return;
    }

    if(!kintoneConfigured){
        alert('キントーンの設定を確認してください。');
        return;
    }

    if(!smtpConfigured){
        alert('SMTPの設定を確認してください。');
        return;
    }

    var names=[];
    selected.forEach(function(c){
        names.push(c.name+'（'+c.email+'）');
    });

    openModal(
        'アンケート案内を送信します',
        '<strong>アンケート：</strong>'+esc(s.title)+
        '<br><strong>送付先：</strong>'+selected.length+'件'+
        '<br><br>'+names.join('<br>')+
        '<br><br>'+
        '<div class="notice info">送信後は、送信対象となった顧客を確認できるようにする想定です。</div>',
        function(){
            alert('メールを送信しました。\\n送信件数：'+selected.length+'件');
        }
    );
}

/* ---------------------------------------
   SETTINGS
--------------------------------------- */
function updateSettings(){
    document.getElementById('kintone-status').textContent=
        kintoneConfigured?'● 設定済み':'● 未設定';

    document.getElementById('kintone-status').style.color=
        kintoneConfigured?'#218838':'#bd382d';

    document.getElementById('smtp-status').textContent=
        smtpConfigured?'● 設定済み':'● 未設定';

    document.getElementById('smtp-status').style.color=
        smtpConfigured?'#218838':'#bd382d';
}

function testKintone(){
    var url=document.getElementById('kintone-url').value;
    var app=document.getElementById('kintone-app').value;

    if(!url||!app){
        alert('キントーンの接続先とアプリ番号を入力してください。');
        return;
    }

    openModal(
        'キントーン接続確認',
        '以下の設定で顧客一覧を取得する想定です。<br><br>'+
        '接続先：'+esc(url)+'<br>'+
        'アプリ番号：'+esc(app),
        function(){
            kintoneConfigured=true;
            updateSettings();
            alert('キントーンへの接続を確認しました。');
        }
    );
}

function testSMTP(){
    var host=document.getElementById('smtp-host').value;
    var port=document.getElementById('smtp-port').value;
    var from=document.getElementById('smtp-from').value;

    if(!host||!port||!from){
        alert('SMTPサーバー、ポート、送信元メールアドレスを入力してください。');
        return;
    }

    openModal(
        'SMTP接続確認',
        '以下の設定でメール送信を行う想定です。<br><br>'+
        'SMTPサーバー：'+esc(host)+'<br>'+
        'ポート：'+esc(port)+'<br>'+
        '送信元：'+esc(from),
        function(){
            smtpConfigured=true;
            updateSettings();
            alert('SMTPサーバーへの接続を確認しました。');
        }
    );
}

function saveSettings(){
    kintoneConfigured=true;
    smtpConfigured=true;
    updateSettings();
    alert('設定を保存しました。');
}

/* ---------------------------------------
   MODAL
--------------------------------------- */
function openModal(title,message,action){
    document.getElementById('modal-title').textContent=title;
    document.getElementById('modal-message').innerHTML=message;
    modalAction=action;
    document.getElementById('modal').classList.add('show');

    document.getElementById('modal-ok').onclick=function(){
        var a=modalAction;
        closeModal();
        if(a)a();
    };
}

function closeModal(){
    document.getElementById('modal').classList.remove('show');
    modalAction=null;
}

/* ---------------------------------------
   Utility
--------------------------------------- */
function esc(str){
    return String(str||'')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function escAttr(str){
    return esc(str);
}

function nl2br(str){
    return esc(str).replace(/\n/g,'<br>');
}

/* ---------------------------------------
   INITIALIZE
--------------------------------------- */
document.getElementById('mail-subject').addEventListener('input',renderMailPreview);
document.getElementById('mail-body').addEventListener('input',renderMailPreview);

showPage('home');
</script>

</body>
</html>
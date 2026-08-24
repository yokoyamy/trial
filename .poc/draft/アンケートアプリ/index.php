<?php
/*
 * アンケート管理システム モック
 * file: index.php
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート一覧</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Hiragino Kaku Gothic ProN",
        "Hiragino Sans",
        Meiryo,
        sans-serif;
    color: #1f2937;
    background: #f5f7fa;
}

/* =========================
   Header
========================= */

.header {
    height: 64px;
    background: #ffffff;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    padding: 0 28px;
    position: sticky;
    top: 0;
    z-index: 50;
}

.logo {
    font-size: 18px;
    font-weight: 700;
    margin-right: 42px;
    color: #111827;
}

.nav {
    display: flex;
    height: 100%;
    align-items: center;
    gap: 6px;
}

.nav a {
    height: 100%;
    display: flex;
    align-items: center;
    padding: 0 16px;
    text-decoration: none;
    color: #6b7280;
    font-size: 14px;
}

.nav a:hover {
    color: #111827;
    background: #f9fafb;
}

.nav a.active {
    color: #2563eb;
    font-weight: 600;
    border-bottom: 2px solid #2563eb;
}

.header-right {
    margin-left: auto;
}

.logout {
    color: #6b7280;
    text-decoration: none;
    font-size: 14px;
}

/* =========================
   Main
========================= */

.container {
    max-width: 1440px;
    margin: 0 auto;
    padding: 32px;
}

.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}

.page-title {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    color: #111827;
}

.page-description {
    margin-top: 7px;
    color: #6b7280;
    font-size: 14px;
}

.primary-button {
    border: 0;
    background: #2563eb;
    color: #fff;
    height: 42px;
    padding: 0 20px;
    border-radius: 7px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

.primary-button:hover {
    background: #1d4ed8;
}

/* =========================
   Search Area
========================= */

.search-panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 18px;
}

.search-row {
    display: flex;
    gap: 12px;
    align-items: end;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.form-group.keyword {
    flex: 1;
}

.form-label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 600;
}

input,
select {
    height: 40px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 0 12px;
    background: #fff;
    color: #111827;
    font-size: 14px;
    outline: none;
}

input:focus,
select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.10);
}

.search-button {
    height: 40px;
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 6px;
    padding: 0 20px;
    cursor: pointer;
    font-weight: 600;
}

.search-button:hover {
    background: #f9fafb;
}

/* =========================
   Table
========================= */

.table-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
}

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 1120px;
    border-collapse: collapse;
}

thead {
    background: #f9fafb;
}

th {
    height: 48px;
    padding: 0 16px;
    text-align: left;
    font-size: 12px;
    color: #6b7280;
    font-weight: 700;
    border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
}

td {
    padding: 16px;
    border-bottom: 1px solid #edf0f3;
    font-size: 13px;
    vertical-align: middle;
}

tbody tr:hover {
    background: #fafcff;
}

tbody tr:last-child td {
    border-bottom: 0;
}

.title {
    font-weight: 700;
    color: #111827;
    cursor: pointer;
}

.title:hover {
    color: #2563eb;
}

.date {
    line-height: 1.7;
    color: #6b7280;
    white-space: nowrap;
}

.period {
    line-height: 1.7;
    white-space: nowrap;
}

.answers {
    font-weight: 700;
    white-space: nowrap;
}

.answer-unit {
    font-weight: 400;
    color: #6b7280;
    margin-left: 2px;
}

/* =========================
   Status
========================= */

.status {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.status.public {
    color: #047857;
    background: #d1fae5;
}

.status.draft {
    color: #92400e;
    background: #fef3c7;
}

.status.finished {
    color: #4b5563;
    background: #e5e7eb;
}

/* =========================
   Action Buttons
========================= */

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    min-width: 300px;
}

.action-button {
    height: 32px;
    padding: 0 10px;
    border-radius: 5px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #374151;
    font-size: 12px;
    cursor: pointer;
    white-space: nowrap;
}

.action-button:hover {
    background: #f3f4f6;
}

.action-button.blue {
    border-color: #bfdbfe;
    color: #1d4ed8;
    background: #eff6ff;
}

.action-button.blue:hover {
    background: #dbeafe;
}

.action-button.red {
    border-color: #fecaca;
    color: #dc2626;
    background: #fff;
}

.action-button.red:hover {
    background: #fef2f2;
}

.action-button.green {
    border-color: #a7f3d0;
    color: #047857;
    background: #ecfdf5;
}

/* =========================
   Empty
========================= */

.empty {
    text-align: center;
    padding: 70px 20px;
    color: #6b7280;
}

/* =========================
   Modal
========================= */

.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .45);
    z-index: 100;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-overlay.show {
    display: flex;
}

.modal {
    width: 100%;
    max-width: 480px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 20px 50px rgba(0,0,0,.2);
    overflow: hidden;
}

.modal-header {
    padding: 20px 22px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
}

.modal-close {
    width: 32px;
    height: 32px;
    border: 0;
    background: transparent;
    font-size: 22px;
    color: #6b7280;
    cursor: pointer;
    border-radius: 5px;
}

.modal-close:hover {
    background: #f3f4f6;
}

.modal-body {
    padding: 22px;
    line-height: 1.8;
    color: #4b5563;
    font-size: 14px;
}

.modal-footer {
    padding: 16px 22px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.modal-button {
    height: 38px;
    padding: 0 18px;
    border-radius: 6px;
    border: 1px solid #d1d5db;
    background: #fff;
    cursor: pointer;
    font-weight: 600;
}

.modal-button.primary {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
}

.modal-button.danger {
    background: #dc2626;
    border-color: #dc2626;
    color: #fff;
}

/* =========================
   Toast
========================= */

.toast {
    position: fixed;
    right: 24px;
    bottom: 24px;
    background: #111827;
    color: #fff;
    padding: 13px 18px;
    border-radius: 8px;
    font-size: 13px;
    box-shadow: 0 10px 30px rgba(0,0,0,.2);
    opacity: 0;
    transform: translateY(15px);
    pointer-events: none;
    transition: .25s;
    z-index: 200;
}

.toast.show {
    opacity: 1;
    transform: translateY(0);
}

/* =========================
   Responsive
========================= */

@media (max-width: 768px) {

    .header {
        padding: 0 14px;
    }

    .logo {
        margin-right: 12px;
        font-size: 15px;
    }

    .nav a {
        padding: 0 9px;
        font-size: 12px;
    }

    .header-right {
        display: none;
    }

    .container {
        padding: 20px 14px;
    }

    .page-header {
        align-items: flex-start;
        gap: 15px;
    }

    .page-title {
        font-size: 22px;
    }

    .primary-button {
        white-space: nowrap;
    }

    .search-row {
        flex-direction: column;
        align-items: stretch;
    }

    .search-button {
        width: 100%;
    }

    .table-card {
        border-radius: 8px;
    }

    .toast {
        left: 14px;
        right: 14px;
        bottom: 14px;
        text-align: center;
    }
}
</style>
</head>

<body>

<!-- =========================
     Header
========================= -->
<header class="header">

    <div class="logo">
        Survey Admin
    </div>

    <nav class="nav">
        <a href="#" class="active" onclick="return false;">
            アンケート一覧
        </a>

        <a href="#" onclick="showToast('キントーン連携設定画面へ遷移します（モック）'); return false;">
            キントーン連携設定
        </a>
    </nav>

    <div class="header-right">
        <a href="#" class="logout"
           onclick="showToast('ログアウトしました（モック）'); return false;">
            ログアウト
        </a>
    </div>

</header>


<!-- =========================
     Main
========================= -->
<main class="container">

    <div class="page-header">

        <div>
            <h1 class="page-title">
                アンケート一覧
            </h1>

            <div class="page-description">
                アンケートの作成・管理・集計・送信を行います。
            </div>
        </div>

        <button
            class="primary-button"
            onclick="createSurvey()">
            ＋ 新規アンケート作成
        </button>

    </div>


    <!-- =========================
         Search
    ========================= -->
    <section class="search-panel">

        <div class="search-row">

            <div class="form-group keyword">

                <label class="form-label">
                    キーワード
                </label>

                <input
                    type="text"
                    id="keyword"
                    placeholder="アンケートタイトルを入力"
                    onkeydown="if(event.key === 'Enter') searchSurveys();">

            </div>


            <div class="form-group">

                <label class="form-label">
                    ステータス
                </label>

                <select id="statusFilter">
                    <option value="all">すべて</option>
                    <option value="public">公開中</option>
                    <option value="draft">下書き</option>
                    <option value="finished">終了</option>
                </select>

            </div>


            <div class="form-group">

                <label class="form-label">
                    ソート
                </label>

                <select id="sortOrder" onchange="renderTable()">
                    <option value="updated_desc">
                        更新日：新しい順
                    </option>

                    <option value="updated_asc">
                        更新日：古い順
                    </option>

                    <option value="answers_desc">
                        回答数：多い順
                    </option>

                    <option value="answers_asc">
                        回答数：少ない順
                    </option>

                    <option value="start_desc">
                        開始日：新しい順
                    </option>

                    <option value="start_asc">
                        開始日：古い順
                    </option>
                </select>

            </div>


            <button
                class="search-button"
                onclick="searchSurveys()">
                検索
            </button>

        </div>

    </section>


    <!-- =========================
         Table
    ========================= -->
    <section class="table-card">

        <div class="table-wrapper">

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

                <tbody id="surveyTableBody">
                </tbody>

            </table>

        </div>

    </section>

</main>


<!-- =========================
     Modal
========================= -->
<div
    id="modalOverlay"
    class="modal-overlay"
    onclick="overlayClick(event)">

    <div class="modal">

        <div class="modal-header">

            <h2
                class="modal-title"
                id="modalTitle">
                確認
            </h2>

            <button
                class="modal-close"
                onclick="closeModal()">
                ×
            </button>

        </div>


        <div
            class="modal-body"
            id="modalBody">
        </div>


        <div class="modal-footer">

            <button
                class="modal-button"
                onclick="closeModal()">
                キャンセル
            </button>

            <button
                class="modal-button primary"
                id="modalConfirmButton">
                OK
            </button>

        </div>

    </div>

</div>


<!-- =========================
     Toast
========================= -->
<div
    id="toast"
    class="toast">
</div>


<script>

/* ========================================
   Mock Data
======================================== */

let surveys = [

    {
        id: 1,
        created: "2026/08/10",
        updated: "2026/08/24",
        title: "顧客満足度調査｜2026年夏",
        start: "2026/08/01 09:00",
        end: "2026/08/31 23:59",
        status: "public",
        answers: 128
    },

    {
        id: 2,
        created: "2026/08/18",
        updated: "2026/08/23",
        title: "新サービス利用者アンケート",
        start: "2026/09/01 09:00",
        end: "2026/09/30 23:59",
        status: "draft",
        answers: 0
    },

    {
        id: 3,
        created: "2026/07/01",
        updated: "2026/08/20",
        title: "セミナー参加者アンケート",
        start: "2026/07/10 10:00",
        end: "2026/07/31 23:59",
        status: "finished",
        answers: 86
    },

    {
        id: 4,
        created: "2026/07/25",
        updated: "2026/08/19",
        title: "製品に関するご意見調査",
        start: "",
        end: "",
        status: "public",
        answers: 52
    },

    {
        id: 5,
        created: "2026/08/05",
        updated: "2026/08/15",
        title: "営業担当者対応満足度調査",
        start: "",
        end: "",
        status: "draft",
        answers: 0
    }

];


/* ========================================
   Status
======================================== */

function statusLabel(status) {

    const labels = {
        public: "公開中",
        draft: "下書き",
        finished: "終了"
    };

    return labels[status];

}


function statusClass(status) {

    return status;

}


/* ========================================
   Render
======================================== */

function renderTable() {

    const tbody =
        document.getElementById("surveyTableBody");

    const keyword =
        document.getElementById("keyword")
            .value
            .trim()
            .toLowerCase();

    const status =
        document.getElementById("statusFilter")
            .value;

    const sort =
        document.getElementById("sortOrder")
            .value;


    let data = surveys.filter(survey => {

        const matchesKeyword =
            survey.title
                .toLowerCase()
                .includes(keyword);

        const matchesStatus =
            status === "all" ||
            survey.status === status;

        return matchesKeyword && matchesStatus;

    });


    /* Sort */

    data.sort((a, b) => {

        if (sort === "answers_desc") {
            return b.answers - a.answers;
        }

        if (sort === "answers_asc") {
            return a.answers - b.answers;
        }

        if (sort === "updated_desc") {
            return b.updated.localeCompare(a.updated);
        }

        if (sort === "updated_asc") {
            return a.updated.localeCompare(b.updated);
        }

        if (sort === "start_desc") {
            return b.start.localeCompare(a.start);
        }

        if (sort === "start_asc") {
            return a.start.localeCompare(b.start);
        }

        return 0;

    });


    tbody.innerHTML = "";


    if (data.length === 0) {

        tbody.innerHTML = `
            <tr>
                <td colspan="6">
                    <div class="empty">
                        該当するアンケートがありません。
                    </div>
                </td>
            </tr>
        `;

        return;
    }


    data.forEach(survey => {

        const tr =
            document.createElement("tr");

        tr.innerHTML = `

            <td>
                <div class="date">
                    ${survey.created}
                    <br>
                    更新: ${survey.updated}
                </div>
            </td>

            <td>
                <div
                    class="title"
                    onclick="editSurvey(${survey.id})">
                    ${escapeHtml(survey.title)}
                </div>
            </td>

            <td>
                <div class="period">
                    ${
                        survey.start
                        ? survey.start
                        : "未設定"
                    }

                    ${
                        survey.end
                        ? `<br>〜 ${survey.end}`
                        : ""
                    }
                </div>
            </td>

            <td>
                <span class="status ${statusClass(survey.status)}">
                    ${statusLabel(survey.status)}
                </span>
            </td>

            <td>
                <span class="answers">
                    ${survey.answers}
                    <span class="answer-unit">件</span>
                </span>
            </td>

            <td>
                <div class="actions">

                    ${getActionButtons(survey)}

                </div>
            </td>

        `;

        tbody.appendChild(tr);

    });

}


/* ========================================
   Action Buttons
======================================== */

function getActionButtons(survey) {

    let html = "";


    /* 確認・編集 */

    html += `
        <button
            class="action-button blue"
            onclick="editSurvey(${survey.id})">
            確認・編集
        </button>
    `;


    /* 公開中 */

    if (survey.status === "public") {

        html += `
            <button
                class="action-button"
                onclick="showAggregate(${survey.id})">
                集計
            </button>

            <button
                class="action-button"
                onclick="showSend(${survey.id})">
                送信
            </button>

            <button
                class="action-button red"
                onclick="stopSurvey(${survey.id})">
                停止
            </button>

            <button
                class="action-button"
                onclick="duplicateSurvey(${survey.id})">
                複製
            </button>
        `;

    }


    /* 下書き */

    if (survey.status === "draft") {

        html += `
            <button
                class="action-button red"
                onclick="deleteSurvey(${survey.id})">
                削除
            </button>

            <button
                class="action-button"
                onclick="duplicateSurvey(${survey.id})">
                複製
            </button>
        `;

    }


    /* 終了 */

    if (survey.status === "finished") {

        html += `
            <button
                class="action-button"
                onclick="showAggregate(${survey.id})">
                集計
            </button>

            <button
                class="action-button"
                onclick="duplicateSurvey(${survey.id})">
                複製
            </button>
        `;

    }


    return html;

}


/* ========================================
   New Survey
======================================== */

function createSurvey() {

    showModal(
        "新規アンケート作成",
        `
        新しいアンケートを作成します。<br><br>
        「OK」を押すとアンケート作成画面へ遷移します。
        `,
        () => {

            closeModal();

            showToast(
                "アンケート作成画面へ遷移します（モック）"
            );

        },
        "作成画面へ"
    );

}


/* ========================================
   Edit
======================================== */

function editSurvey(id) {

    const survey =
        surveys.find(s => s.id === id);

    if (!survey) return;


    showModal(
        "アンケート詳細・編集",
        `
        <strong>${escapeHtml(survey.title)}</strong>
        <br><br>

        ステータス：
        ${statusLabel(survey.status)}

        <br>

        回答数：
        ${survey.answers} 件

        <br><br>

        「編集画面へ」を押すと、
        アンケート作成・編集画面へ遷移します。
        `,
        () => {

            closeModal();

            showToast(
                "「" + survey.title + "」の編集画面へ遷移します（モック）"
            );

        },
        "編集画面へ"
    );

}


/* ========================================
   Aggregate
======================================== */

function showAggregate(id) {

    const survey =
        surveys.find(s => s.id === id);

    if (!survey) return;


    showModal(
        "回答集計",
        `
        <strong>${escapeHtml(survey.title)}</strong>
        <br><br>

        現在の回答数：
        <strong>${survey.answers} 件</strong>

        <br><br>

        集計画面では以下を確認できます。

        <ul>
            <li>設問ごとの回答結果</li>
            <li>棒グラフ・円グラフ</li>
            <li>自由記述回答</li>
            <li>CSV / Excel出力</li>
        </ul>
        `,
        () => {

            closeModal();

            showToast(
                "回答集計画面へ遷移します（モック）"
            );

        },
        "集計画面へ"
    );

}


/* ========================================
   Send
======================================== */

function showSend(id) {

    const survey =
        surveys.find(s => s.id === id);

    if (!survey) return;


    showModal(
        "顧客宛先選択・メール送信",
        `
        <strong>${escapeHtml(survey.title)}</strong>
        <br><br>

        顧客リストから送信先を選択します。

        <ul>
            <li>顧客検索</li>
            <li>全選択 / 個別選択</li>
            <li>送信済み / 未送信</li>
            <li>回答済み / 未回答</li>
        </ul>

        <small>
        ※送信済み顧客を選択した場合は
        再送確認ダイアログを表示します。
        </small>
        `,
        () => {

            closeModal();

            showToast(
                "顧客宛先選択・メール送信画面へ遷移します（モック）"
            );

        },
        "送信画面へ"
    );

}


/* ========================================
   Stop
======================================== */

function stopSurvey(id) {

    const survey =
        surveys.find(s => s.id === id);

    if (!survey) return;


    showModal(
        "アンケートを停止しますか？",
        `
        <strong>${escapeHtml(survey.title)}</strong>
        <br><br>

        停止すると、回答者はこのアンケートへ
        回答できなくなります。
        <br><br>

        停止後は詳細画面から再開できます。
        `,
        () => {

            survey.status = "finished";

            closeModal();

            renderTable();

            showToast(
                "アンケートを停止しました。"
            );

        },
        "停止する",
        "danger"
    );

}


/* ========================================
   Delete
======================================== */

function deleteSurvey(id) {

    const survey =
        surveys.find(s => s.id === id);

    if (!survey) return;


    showModal(
        "アンケートを削除しますか？",
        `
        <strong>${escapeHtml(survey.title)}</strong>
        <br><br>

        このアンケートを削除します。
        <br>
        削除後は一覧から表示されなくなります。
        <br><br>

        ※実際のシステムでは論理削除を想定しています。
        `,
        () => {

            surveys =
                surveys.filter(
                    s => s.id !== id
                );

            closeModal();

            renderTable();

            showToast(
                "アンケートを削除しました。"
            );

        },
        "削除する",
        "danger"
    );

}


/* ========================================
   Duplicate
======================================== */

function duplicateSurvey(id) {

    const survey =
        surveys.find(s => s.id === id);

    if (!survey) return;


    showModal(
        "アンケートを複製しますか？",
        `
        <strong>${escapeHtml(survey.title)}</strong>
        <br><br>

        複製したアンケートは
        「下書き」ステータスで新規追加されます。
        <br><br>

        複製後、編集画面への遷移は行いません。
        `,
        () => {

            const newSurvey = {

                ...survey,

                id: Date.now(),

                title:
                    survey.title + "（複製）",

                created:
                    formatToday(),

                updated:
                    formatToday(),

                status:
                    "draft",

                answers:
                    0

            };


            surveys.unshift(newSurvey);

            closeModal();

            renderTable();

            showToast(
                "アンケートを複製しました。下書きとして追加しました。"
            );

        },
        "複製する"
    );

}


/* ========================================
   Search
======================================== */

function searchSurveys() {

    renderTable();

    showToast(
        "検索結果を更新しました。"
    );

}


/* ========================================
   Modal
======================================== */

function showModal(
    title,
    body,
    callback,
    confirmText = "OK",
    buttonType = "primary"
) {

    document.getElementById("modalTitle")
        .textContent = title;

    document.getElementById("modalBody")
        .innerHTML = body;


    const button =
        document.getElementById(
            "modalConfirmButton"
        );


    button.textContent = confirmText;

    button.className =
        "modal-button " + buttonType;

    button.onclick = callback;


    document
        .getElementById("modalOverlay")
        .classList.add("show");

}


function closeModal() {

    document
        .getElementById("modalOverlay")
        .classList.remove("show");

}


function overlayClick(event) {

    if (
        event.target.id ===
        "modalOverlay"
    ) {
        closeModal();
    }

}


/* ========================================
   Toast
======================================== */

let toastTimer;

function showToast(message) {

    const toast =
        document.getElementById("toast");

    toast.textContent = message;

    toast.classList.add("show");


    clearTimeout(toastTimer);

    toastTimer = setTimeout(() => {

        toast.classList.remove("show");

    }, 2800);

}


/* ========================================
   Utilities
======================================== */

function formatToday() {

    const d = new Date();

    const y = d.getFullYear();

    const m =
        String(d.getMonth() + 1)
            .padStart(2, "0");

    const day =
        String(d.getDate())
            .padStart(2, "0");

    return `${y}/${m}/${day}`;

}


function escapeHtml(str) {

    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");

}


/* ========================================
   Initial Render
======================================== */

renderTable();

</script>

</body>
</html>
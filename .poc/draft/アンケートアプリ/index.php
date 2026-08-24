<?php
/*
 * 顧客宛先選択・メール送信 / 回答フォロー モック
 * file: index.php
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>顧客選択・送信・送信履歴</title>

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

button,
input,
textarea,
select {
    font-family: inherit;
}

/* =====================================
   Header
===================================== */

.header {
    height: 64px;
    background: #fff;
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
    color: #111827;
    margin-right: 40px;
}

.nav {
    height: 100%;
    display: flex;
    gap: 5px;
}

.nav a {
    height: 100%;
    display: flex;
    align-items: center;
    padding: 0 15px;
    text-decoration: none;
    color: #6b7280;
    font-size: 14px;
}

.nav a:hover {
    background: #f9fafb;
    color: #111827;
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
    font-size: 14px;
    text-decoration: none;
}

/* =====================================
   Main
===================================== */

.container {
    max-width: 1450px;
    margin: 0 auto;
    padding: 26px 30px 60px;
}

.breadcrumb {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 18px;
}

.breadcrumb span {
    margin: 0 7px;
    color: #9ca3af;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 22px;
}

.page-title {
    margin: 0;
    font-size: 26px;
    color: #111827;
}

.page-subtitle {
    margin-top: 6px;
    font-size: 13px;
    color: #6b7280;
}

.primary-button {
    height: 42px;
    padding: 0 20px;
    border: 0;
    border-radius: 7px;
    background: #2563eb;
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}

.primary-button:hover {
    background: #1d4ed8;
}

.primary-button:disabled {
    background: #9ca3af;
    cursor: not-allowed;
}

/* =====================================
   Alert
===================================== */

.alert {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #9a3412;
    border-radius: 9px;
    padding: 15px 18px;
    margin-bottom: 18px;
}

.alert-icon {
    font-size: 20px;
}

.alert-title {
    font-weight: 700;
    margin-bottom: 3px;
}

.alert-text {
    font-size: 13px;
    line-height: 1.6;
}

/* =====================================
   Card
===================================== */

.card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    margin-bottom: 18px;
}

.card-header {
    padding: 17px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #111827;
}

.card-description {
    margin-top: 4px;
    font-size: 12px;
    color: #6b7280;
}

.card-body {
    padding: 20px;
}

/* =====================================
   Template
===================================== */

.template-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.form-item {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.form-item.full {
    grid-column: 1 / -1;
}

.form-label {
    font-size: 12px;
    font-weight: 700;
    color: #4b5563;
}

.form-label .required {
    color: #dc2626;
    margin-left: 3px;
}

input[type="text"],
textarea,
select {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 10px 12px;
    outline: none;
    font-size: 14px;
    background: #fff;
}

input[type="text"]:focus,
textarea:focus,
select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.10);
}

textarea {
    min-height: 175px;
    resize: vertical;
    line-height: 1.7;
}

.variables {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 5px;
}

.variable {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 5px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 11px;
    cursor: pointer;
    border: 1px solid #bfdbfe;
}

.variable:hover {
    background: #dbeafe;
}

/* =====================================
   Search
===================================== */

.filter-row {
    display: grid;
    grid-template-columns: 1fr 1fr 180px auto;
    gap: 10px;
    align-items: end;
}

.filter-buttons {
    display: flex;
    gap: 7px;
}

.secondary-button {
    height: 40px;
    padding: 0 15px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: #fff;
    color: #374151;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.secondary-button:hover {
    background: #f9fafb;
}

.small-label {
    display: block;
    font-size: 11px;
    color: #6b7280;
    font-weight: 700;
    margin-bottom: 6px;
}

/* =====================================
   Selection summary
===================================== */

.selection-bar {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 7px;
    margin-bottom: 12px;
    font-size: 13px;
}

.selection-count {
    color: #1d4ed8;
    font-weight: 700;
}

.clear-selection {
    border: 0;
    background: transparent;
    color: #2563eb;
    cursor: pointer;
    font-size: 12px;
}

/* =====================================
   Table
===================================== */

.table-wrapper {
    overflow-x: auto;
}

.customer-table {
    width: 100%;
    min-width: 1250px;
    border-collapse: collapse;
}

.customer-table th {
    background: #f9fafb;
    height: 48px;
    padding: 0 13px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    font-size: 11px;
    color: #6b7280;
    white-space: nowrap;
}

.customer-table td {
    padding: 14px 13px;
    border-bottom: 1px solid #edf0f3;
    vertical-align: middle;
    font-size: 12px;
}

.customer-table tbody tr:hover {
    background: #fafcff;
}

.customer-table tbody tr:last-child td {
    border-bottom: 0;
}

.customer-table tr.web-row {
    background: #fafafa;
}

.customer-table tr.web-row:hover {
    background: #f5f5f5;
}

.customer-info {
    line-height: 1.55;
    min-width: 210px;
}

.company {
    font-weight: 700;
    color: #111827;
}

.name {
    font-weight: 600;
    color: #374151;
}

.contact {
    color: #6b7280;
    font-size: 11px;
}

.history {
    line-height: 1.7;
    min-width: 180px;
}

.history-date {
    color: #374151;
}

.history-count {
    color: #6b7280;
}

.link-button {
    border: 0;
    background: transparent;
    padding: 0;
    color: #2563eb;
    cursor: pointer;
    font-size: 11px;
    text-decoration: underline;
}

.link-button:hover {
    color: #1d4ed8;
}

/* =====================================
   Badges
===================================== */

.badge {
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
}

.badge.unanswered {
    background: #fef3c7;
    color: #92400e;
}

.badge.answered {
    background: #d1fae5;
    color: #047857;
}

.badge.not-sent {
    background: #f3f4f6;
    color: #6b7280;
}

.badge.web {
    background: #ede9fe;
    color: #6d28d9;
}

.badge.unregistered {
    background: #fee2e2;
    color: #b91c1c;
}

.badge.registered {
    background: #d1fae5;
    color: #047857;
}

/* =====================================
   Register Button
===================================== */

.register-button {
    height: 31px;
    padding: 0 10px;
    border-radius: 5px;
    border: 1px solid #fecaca;
    background: #fff;
    color: #dc2626;
    cursor: pointer;
    font-size: 11px;
    font-weight: 700;
}

.register-button:hover {
    background: #fef2f2;
}

.registered-text {
    color: #047857;
    font-weight: 700;
    white-space: nowrap;
}

/* =====================================
   History
===================================== */

.log-table {
    width: 100%;
    border-collapse: collapse;
}

.log-table th {
    background: #f9fafb;
    padding: 11px 13px;
    text-align: left;
    font-size: 11px;
    color: #6b7280;
    border-bottom: 1px solid #e5e7eb;
}

.log-table td {
    padding: 13px;
    border-bottom: 1px solid #edf0f3;
    font-size: 12px;
}

.log-table tr:last-child td {
    border-bottom: 0;
}

.type-badge {
    display: inline-flex;
    padding: 4px 8px;
    border-radius: 5px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 10px;
    font-weight: 700;
}

/* =====================================
   Modal
===================================== */

.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.48);
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
    max-width: 560px;
    max-height: 90vh;
    overflow: auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0,0,0,.22);
}

.modal.large {
    max-width: 760px;
}

.modal-header {
    padding: 18px 21px;
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
    border-radius: 5px;
    background: transparent;
    color: #6b7280;
    font-size: 22px;
    cursor: pointer;
}

.modal-close:hover {
    background: #f3f4f6;
}

.modal-body {
    padding: 21px;
    font-size: 13px;
    line-height: 1.8;
    color: #4b5563;
}

.modal-footer {
    padding: 15px 21px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.modal-button {
    height: 38px;
    padding: 0 17px;
    border-radius: 6px;
    border: 1px solid #d1d5db;
    background: #fff;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}

.modal-button.primary {
    color: #fff;
    background: #2563eb;
    border-color: #2563eb;
}

.modal-button.primary:hover {
    background: #1d4ed8;
}

.modal-button.danger {
    color: #fff;
    background: #dc2626;
    border-color: #dc2626;
}

.mail-preview {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f9fafb;
    padding: 16px;
    white-space: pre-wrap;
    line-height: 1.8;
    max-height: 380px;
    overflow: auto;
}

.mail-subject {
    background: #fff;
    border: 1px solid #e5e7eb;
    padding: 12px;
    border-radius: 7px;
    margin-bottom: 10px;
    font-weight: 700;
}

/* =====================================
   Toast
===================================== */

.toast {
    position: fixed;
    right: 24px;
    bottom: 24px;
    background: #111827;
    color: #fff;
    padding: 13px 18px;
    border-radius: 8px;
    font-size: 13px;
    box-shadow: 0 10px 30px rgba(0,0,0,.22);
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

/* =====================================
   Responsive
===================================== */

@media (max-width: 900px) {

    .template-grid {
        grid-template-columns: 1fr;
    }

    .form-item.full {
        grid-column: auto;
    }

    .filter-row {
        grid-template-columns: 1fr 1fr;
    }

    .filter-buttons {
        grid-column: 1 / -1;
    }

}

@media (max-width: 700px) {

    .header {
        padding: 0 14px;
    }

    .logo {
        margin-right: 12px;
        font-size: 15px;
    }

    .nav a {
        padding: 0 8px;
        font-size: 11px;
    }

    .header-right {
        display: none;
    }

    .container {
        padding: 20px 14px 40px;
    }

    .page-header {
        align-items: flex-start;
        gap: 12px;
    }

    .page-title {
        font-size: 21px;
    }

    .primary-button {
        padding: 0 12px;
        font-size: 12px;
        white-space: nowrap;
    }

    .filter-row {
        grid-template-columns: 1fr;
    }

    .filter-buttons {
        grid-column: auto;
    }

    .selection-bar {
        align-items: flex-start;
        gap: 8px;
        flex-direction: column;
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

<!-- =====================================
     Header
===================================== -->

<header class="header">

    <div class="logo">
        Survey Admin
    </div>

    <nav class="nav">

        <a href="#"
           onclick="showToast('アンケート一覧へ戻ります（モック）'); return false;">
            アンケート一覧
        </a>

        <a href="#"
           onclick="showToast('キントーン連携設定画面へ遷移します（モック）'); return false;">
            キントーン連携設定
        </a>

    </nav>

    <div class="header-right">
        <a href="#"
           class="logout"
           onclick="showToast('ログアウトしました（モック）'); return false;">
            ログアウト
        </a>
    </div>

</header>


<!-- =====================================
     Main
===================================== -->

<main class="container">

    <!-- Breadcrumb -->

    <div class="breadcrumb">
        ホーム
        <span>›</span>
        アンケート一覧
        <span>›</span>
        顧客選択・送信・送信履歴
    </div>


    <!-- Header -->

    <div class="page-header">

        <div>

            <h1 class="page-title">
                顧客選択・送信・送信履歴
            </h1>

            <div class="page-subtitle">
                顧客へのアンケート送信、回答状況、送信履歴を管理します。
            </div>

        </div>

        <button
            id="sendButton"
            class="primary-button"
            onclick="bulkSend()"
            disabled>
            選択した顧客へ一括送信
        </button>

    </div>


    <!-- =====================================
         Kintone Alert
    ===================================== -->

    <div
        id="unregisteredAlert"
        class="alert">

        <div class="alert-icon">
            ⚠
        </div>

        <div>

            <div class="alert-title">
                キントーン未登録の回答者がいます
            </div>

            <div class="alert-text">
                Web公開フォームから回答した顧客のうち、
                キントーンに未登録の顧客が
                <strong id="unregisteredCount">2</strong>名います。
                顧客情報を確認し、登録完了後に「キントーン登録完了」を押してください。
            </div>

        </div>

    </div>


    <!-- =====================================
         Mail Template
    ===================================== -->

    <section class="card">

        <div class="card-header">

            <div>
                <h2 class="card-title">
                    送信メールテンプレート
                </h2>

                <div class="card-description">
                    今回送信するメール内容を設定してください。
                </div>
            </div>

            <button
                class="secondary-button"
                onclick="previewTemplate()">
                メールプレビュー
            </button>

        </div>


        <div class="card-body">

            <div class="template-grid">

                <div class="form-item full">

                    <label class="form-label">
                        メール件名
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="mailSubject"
                        value="【アンケートのお願い】顧客満足度調査へのご協力をお願いします">

                </div>


                <div class="form-item full">

                    <label class="form-label">
                        メール本文
                        <span class="required">*</span>
                    </label>

                    <textarea
                        id="mailBody">{顧客名} 様

いつもお世話になっております。

この度、サービス向上を目的としてアンケートを実施しております。

以下のURLよりアンケートへのご回答をお願いいたします。

{アンケートURL}

お忙しいところ恐れ入りますが、
ご協力のほどよろしくお願いいたします。</textarea>

                    <div>

                        <div class="small-label">
                            利用可能な差し込み変数
                        </div>

                        <div class="variables">

                            <button
                                class="variable"
                                onclick="insertVariable('{顧客名}')">
                                {顧客名}
                            </button>

                            <button
                                class="variable"
                                onclick="insertVariable('{アンケートURL}')">
                                {アンケートURL}
                            </button>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>


    <!-- =====================================
         Customer Search
    ===================================== -->

    <section class="card">

        <div class="card-header">

            <div>
                <h2 class="card-title">
                    顧客検索・絞り込み
                </h2>

                <div class="card-description">
                    顧客名、メールアドレス、送信・回答状況などから絞り込みできます。
                </div>
            </div>

        </div>


        <div class="card-body">

            <div class="filter-row">

                <div>

                    <label class="small-label">
                        顧客名・会社名
                    </label>

                    <input
                        type="text"
                        id="customerKeyword"
                        placeholder="例：山田 / 株式会社〇〇"
                        onkeydown="if(event.key==='Enter') filterCustomers();">

                </div>


                <div>

                    <label class="small-label">
                        メールアドレス
                    </label>

                    <input
                        type="text"
                        id="emailKeyword"
                        placeholder="example@example.com"
                        onkeydown="if(event.key==='Enter') filterCustomers();">

                </div>


                <div>

                    <label class="small-label">
                        回答ステータス
                    </label>

                    <select id="answerFilter">

                        <option value="all">
                            すべて
                        </option>

                        <option value="not_sent">
                            未送信
                        </option>

                        <option value="unanswered">
                            送信済み（未回答）
                        </option>

                        <option value="answered">
                            回答済み
                        </option>

                    </select>

                </div>


                <div class="filter-buttons">

                    <button
                        class="secondary-button"
                        onclick="filterCustomers()">
                        検索
                    </button>

                    <button
                        class="secondary-button"
                        onclick="resetFilter()">
                        リセット
                    </button>

                </div>

            </div>

        </div>

    </section>


    <!-- =====================================
         Customer Table
    ===================================== -->

    <section class="card">

        <div class="card-header">

            <div>

                <h2 class="card-title">
                    顧客一覧・送信追跡
                </h2>

                <div class="card-description">
                    Web直接回答者は送信対象として選択できません。
                </div>

            </div>

            <div style="font-size:12px;color:#6b7280;">
                全 <strong id="customerTotal">0</strong> 件
            </div>

        </div>


        <div class="card-body">

            <div
                id="selectionBar"
                class="selection-bar"
                style="display:none;">

                <div>
                    <span id="selectedCount"
                          class="selection-count">
                        0
                    </span>
                    件選択中
                </div>

                <button
                    class="clear-selection"
                    onclick="clearSelection()">
                    選択を解除
                </button>

            </div>


            <div class="table-wrapper">

                <table class="customer-table">

                    <thead>

                    <tr>

                        <th style="width:45px;">
                            <input
                                type="checkbox"
                                id="selectAll"
                                onchange="toggleAll(this)">
                        </th>

                        <th>
                            会社名 / 氏名等
                        </th>

                        <th>
                            送信ステータス / 履歴
                        </th>

                        <th>
                            回答ステータス
                        </th>

                        <th>
                            キントーン対応
                        </th>

                    </tr>

                    </thead>

                    <tbody id="customerTableBody">
                    </tbody>

                </table>

            </div>

        </div>

    </section>


    <!-- =====================================
         Send Logs
    ===================================== -->

    <section class="card">

        <div class="card-header">

            <div>

                <h2 class="card-title">
                    一括送信ログ・履歴
                </h2>

                <div class="card-description">
                    過去に実行したメール送信の履歴を確認できます。
                </div>

            </div>

        </div>


        <div class="table-wrapper">

            <table class="log-table">

                <thead>

                <tr>
                    <th>送信日時</th>
                    <th>送信種別</th>
                    <th>送信件数</th>
                    <th>送信件名</th>
                    <th>送信実行者</th>
                    <th>送信文</th>
                </tr>

                </thead>

                <tbody id="logTableBody">
                </tbody>

            </table>

        </div>

    </section>

</main>


<!-- =====================================
     Modal
===================================== -->

<div
    id="modalOverlay"
    class="modal-overlay"
    onclick="overlayClick(event)">

    <div
        id="modal"
        class="modal">

        <div class="modal-header">

            <h2
                id="modalTitle"
                class="modal-title">
                確認
            </h2>

            <button
                class="modal-close"
                onclick="closeModal()">
                ×
            </button>

        </div>

        <div
            id="modalBody"
            class="modal-body">
        </div>

        <div class="modal-footer">

            <button
                class="modal-button"
                onclick="closeModal()">
                キャンセル
            </button>

            <button
                id="modalConfirm"
                class="modal-button primary">
                OK
            </button>

        </div>

    </div>

</div>


<!-- =====================================
     Toast
===================================== -->

<div
    id="toast"
    class="toast">
</div>


<script>

/* =====================================================
   Mock Customer Data
===================================================== */

let customers = [

    {
        id: 1,
        company: "株式会社サンプル商事",
        name: "山田 太郎",
        email: "yamada@example.co.jp",
        phone: "03-1234-5678",
        address: "東京都港区赤坂1-1-1",
        type: "customer",
        sentAt: "2026/08/20 10:12",
        sendCount: 1,
        answerStatus: "unanswered",
        kintone: "registered"
    },

    {
        id: 2,
        company: "株式会社ABC",
        name: "佐藤 花子",
        email: "sato@example.co.jp",
        phone: "03-2222-3333",
        address: "東京都千代田区丸の内2-2-2",
        type: "customer",
        sentAt: "2026/08/19 14:30",
        sendCount: 1,
        answerStatus: "answered",
        kintone: "registered"
    },

    {
        id: 3,
        company: "〇〇株式会社",
        name: "鈴木 一郎",
        email: "suzuki@example.co.jp",
        phone: "03-4444-5555",
        address: "東京都新宿区西新宿3-3-3",
        type: "customer",
        sentAt: "",
        sendCount: 0,
        answerStatus: "not_sent",
        kintone: "registered"
    },

    {
        id: 4,
        company: "△△商事株式会社",
        name: "高橋 美咲",
        email: "takahashi@example.co.jp",
        phone: "03-6666-7777",
        address: "東京都渋谷区渋谷4-4-4",
        type: "customer",
        sentAt: "2026/08/18 09:15",
        sendCount: 2,
        answerStatus: "unanswered",
        kintone: "registered"
    },

    {
        id: 5,
        company: "Webフォーム回答者",
        name: "田中 健一",
        email: "tanaka.web@example.com",
        phone: "090-1111-2222",
        address: "東京都港区",
        type: "web",
        sentAt: "",
        sendCount: 0,
        answerStatus: "answered",
        kintone: "unregistered"
    },

    {
        id: 6,
        company: "Webフォーム回答者",
        name: "伊藤 由美",
        email: "ito.web@example.com",
        phone: "090-3333-4444",
        address: "東京都品川区",
        type: "web",
        sentAt: "",
        sendCount: 0,
        answerStatus: "answered",
        kintone: "unregistered"
    },

    {
        id: 7,
        company: "株式会社テスト",
        name: "渡辺 翔太",
        email: "watanabe@example.com",
        phone: "03-8888-9999",
        address: "東京都中央区銀座5-5-5",
        type: "customer",
        sentAt: "2026/08/10 11:00",
        sendCount: 1,
        answerStatus: "answered",
        kintone: "registered"
    }

];


/* =====================================================
   Send Logs
===================================================== */

let sendLogs = [

    {
        date: "2026/08/20 10:12",
        type: "初回一括送信",
        count: 3,
        subject: "【アンケートのお願い】顧客満足度調査へのご協力をお願いします",
        user: "管理者"
    },

    {
        date: "2026/08/18 09:15",
        type: "リマインド送信",
        count: 1,
        subject: "【再送】アンケートご回答のお願い",
        user: "田中 太郎"
    },

    {
        date: "2026/08/10 11:00",
        type: "初回一括送信",
        count: 5,
        subject: "サービスに関するアンケートのお願い",
        user: "管理者"
    }

];


/* =====================================================
   Render Customers
===================================================== */

function renderCustomers(data = customers) {

    const tbody =
        document.getElementById("customerTableBody");

    tbody.innerHTML = "";

    document.getElementById("customerTotal")
        .textContent = data.length;


    data.forEach(customer => {

        const tr =
            document.createElement("tr");

        if (customer.type === "web") {
            tr.classList.add("web-row");
        }


        const selectable =
            customer.type !== "web";


        tr.innerHTML = `

            <td>
                ${
                    selectable
                    ?
                    `
                    <input
                        type="checkbox"
                        class="customer-check"
                        value="${customer.id}"
                        onchange="updateSelection()">
                    `
                    :
                    `
                    <input
                        type="checkbox"
                        disabled
                        title="Web直接回答者は送信対象外です">
                    `
                }
            </td>


            <td>

                <div class="customer-info">

                    <div class="company">
                        ${escapeHtml(customer.company)}
                    </div>

                    <div class="name">
                        ${escapeHtml(customer.name)}
                    </div>

                    <div class="contact">
                        ${escapeHtml(customer.email)}
                    </div>

                    <div class="contact">
                        ${escapeHtml(customer.phone)}
                    </div>

                    <div class="contact">
                        ${escapeHtml(customer.address)}
                    </div>

                    ${
                        customer.type === "web"
                        ?
                        `
                        <div style="margin-top:5px;">
                            <span class="badge web">
                                Web直接回答
                            </span>
                        </div>
                        `
                        :
                        ""
                    }

                </div>

            </td>


            <td>

                <div class="history">

                    ${
                        customer.sentAt
                        ?
                        `
                        <div class="history-date">
                            最終送信：
                            ${customer.sentAt}
                        </div>

                        <div class="history-count">
                            送信回数：
                            ${customer.sendCount}回
                        </div>

                        <button
                            class="link-button"
                            onclick="viewCustomerMail(${customer.id})">
                            送信文を確認
                        </button>
                        `
                        :
                        `
                        <div style="color:#9ca3af;">
                            ${
                                customer.type === "web"
                                ? "Web直接回答"
                                : "送信未実施"
                            }
                        </div>
                        `
                    }

                </div>

            </td>


            <td>

                ${getAnswerBadge(customer)}

            </td>


            <td>

                ${getKintoneStatus(customer)}

            </td>

        `;

        tbody.appendChild(tr);

    });


    updateSelection();

}


/* =====================================================
   Answer Badge
===================================================== */

function getAnswerBadge(customer) {

    if (customer.type === "web") {

        return `
            <span class="badge answered">
                回答済み
            </span>
        `;

    }


    if (customer.answerStatus === "answered") {

        return `
            <span class="badge answered">
                回答済み
            </span>
        `;

    }


    if (customer.answerStatus === "unanswered") {

        return `
            <span class="badge unanswered">
                送信済み（未回答）
            </span>
        `;

    }


    return `
        <span class="badge not-sent">
            未送信
        </span>
    `;

}


/* =====================================================
   Kintone
===================================================== */

function getKintoneStatus(customer) {

    if (customer.kintone === "registered") {

        return `
            <span class="registered-text">
                ✓ キントーン登録完了
            </span>
        `;

    }


    return `
        <div>

            <span class="badge unregistered">
                未登録
            </span>

            <div style="margin-top:7px;">

                <button
                    class="register-button"
                    onclick="completeKintone(${customer.id})">
                    キントーン登録完了
                </button>

            </div>

        </div>
    `;

}


/* =====================================================
   Selection
===================================================== */

function updateSelection() {

    const checked =
        document.querySelectorAll(
            ".customer-check:checked"
        );

    const count =
        checked.length;


    document.getElementById("selectedCount")
        .textContent = count;


    document.getElementById("selectionBar")
        .style.display =
            count > 0
            ? "flex"
            : "none";


    document.getElementById("sendButton")
        .disabled = count === 0;


    const selectable =
        document.querySelectorAll(
            ".customer-check"
        );


    const selectAll =
        document.getElementById("selectAll");


    if (selectable.length === 0) {

        selectAll.checked = false;

    } else {

        selectAll.checked =
            checked.length === selectable.length;

    }

}


function toggleAll(checkbox) {

    document
        .querySelectorAll(".customer-check")
        .forEach(item => {

            item.checked =
                checkbox.checked;

        });

    updateSelection();

}


function clearSelection() {

    document
        .querySelectorAll(".customer-check")
        .forEach(item => {

            item.checked = false;

        });


    document.getElementById("selectAll")
        .checked = false;

    updateSelection();

}


/* =====================================================
   Bulk Send
===================================================== */

function bulkSend() {

    const ids =
        Array.from(
            document.querySelectorAll(
                ".customer-check:checked"
            )
        )
        .map(el => Number(el.value));


    if (ids.length === 0) {

        showToast("送信対象を選択してください。");

        return;

    }


    const selected =
        customers.filter(
            customer => ids.includes(customer.id)
        );


    const alreadySent =
        selected.filter(
            customer =>
                customer.sendCount > 0
        );


    let warning = "";


    if (alreadySent.length > 0) {

        warning = `
            <div style="
                background:#fff7ed;
                border:1px solid #fed7aa;
                padding:12px;
                border-radius:7px;
                margin-top:15px;
                color:#9a3412;
            ">
                <strong>
                    既に送信済みの宛先が
                    ${alreadySent.length}件含まれています。
                </strong>
                <br>
                再送しますか？
            </div>
        `;

    }


    showModal(
        "メールを一括送信しますか？",
        `
        <strong>${selected.length}名</strong>
        にメールを送信します。
        <br><br>

        <strong>件名：</strong><br>
        ${escapeHtml(
            document.getElementById("mailSubject").value
        )}

        ${warning}

        <br><br>

        送信後は各顧客の
        「最終送信日時」と「送信回数」が更新されます。
        `,
        () => {

            executeBulkSend(ids);

        },
        alreadySent.length > 0
            ? "再送信する"
            : "送信する"
    );

}


/* =====================================================
   Execute Send
===================================================== */

function executeBulkSend(ids) {

    const subject =
        document.getElementById("mailSubject").value;

    const now =
        formatDateTime();


    ids.forEach(id => {

        const customer =
            customers.find(
                c => c.id === id
            );


        if (!customer) return;


        customer.sentAt = now;

        customer.sendCount++;

        customer.answerStatus =
            "unanswered";

    });


    sendLogs.unshift({

        date: now,

        type:
            ids.some(id => {
                const c =
                    customers.find(
                        x => x.id === id
                    );

                return c &&
                    c.sendCount > 1;
            })
            ? "リマインド送信"
            : "初回一括送信",

        count: ids.length,

        subject: subject,

        user: "管理者"

    });


    closeModal();

    clearSelection();

    renderCustomers();

    renderLogs();

    showToast(
        `${ids.length}件のメールを送信しました。`
    );

}


/* =====================================================
   Customer Mail
===================================================== */

function viewCustomerMail(id) {

    const customer =
        customers.find(
            c => c.id === id
        );

    if (!customer) return;


    const subject =
        document.getElementById(
            "mailSubject"
        ).value;


    const body =
        document.getElementById(
            "mailBody"
        ).value;


    const replacedSubject =
        replaceVariables(
            subject,
            customer
        );


    const replacedBody =
        replaceVariables(
            body,
            customer
        );


    showModal(
        "送信文を確認",
        `
        <div style="
            font-size:12px;
            color:#6b7280;
            margin-bottom:6px;
        ">
            ${escapeHtml(customer.name)} 様へ送信した内容
        </div>

        <div class="mail-subject">
            ${escapeHtml(replacedSubject)}
        </div>

        <div class="mail-preview">
            ${escapeHtml(replacedBody)}
        </div>

        <div style="
            margin-top:12px;
            font-size:11px;
            color:#6b7280;
        ">
            最終送信日時：
            ${customer.sentAt || "未送信"}
            <br>
            送信回数：
            ${customer.sendCount}回
        </div>
        `,
        () => closeModal(),
        "閉じる"
    );

}


/* =====================================================
   Replace Variables
===================================================== */

function replaceVariables(text, customer) {

    return text
        .replace(
            /\{顧客名\}/g,
            customer.name
        )
        .replace(
            /\{アンケートURL\}/g,
            "https://example.com/survey/abc123/" + customer.id
        );

}


/* =====================================================
   Preview Template
===================================================== */

function previewTemplate() {

    const subject =
        document.getElementById(
            "mailSubject"
        ).value;


    const body =
        document.getElementById(
            "mailBody"
        ).value;


    const sampleCustomer =
        customers.find(
            c => c.type === "customer"
        );


    showModal(
        "メールプレビュー",
        `
        <div style="
            font-size:12px;
            color:#6b7280;
            margin-bottom:7px;
        ">
            サンプル顧客：
            ${escapeHtml(sampleCustomer.name)}
        </div>

        <div class="mail-subject">
            ${escapeHtml(
                replaceVariables(
                    subject,
                    sampleCustomer
                )
            )}
        </div>

        <div class="mail-preview">
            ${escapeHtml(
                replaceVariables(
                    body,
                    sampleCustomer
                )
            )}
        </div>
        `,
        () => closeModal(),
        "閉じる"
    );

}


/* =====================================================
   Insert Variable
===================================================== */

function insertVariable(variable) {

    const textarea =
        document.getElementById(
            "mailBody"
        );


    const start =
        textarea.selectionStart;

    const end =
        textarea.selectionEnd;


    textarea.value =
        textarea.value.substring(0, start)
        +
        variable
        +
        textarea.value.substring(end);


    textarea.focus();


    textarea.selectionStart =
        textarea.selectionEnd =
            start + variable.length;


    showToast(
        variable + " を本文に挿入しました。"
    );

}


/* =====================================================
   Kintone Complete
===================================================== */

function completeKintone(id) {

    const customer =
        customers.find(
            c => c.id === id
        );

    if (!customer) return;


    showModal(
        "キントーン登録完了",
        `
        <strong>${escapeHtml(customer.name)}</strong>
        様の顧客情報について、
        キントーンへの登録が完了したことを記録します。
        <br><br>
        「登録完了」を押すと、この顧客のステータスが
        「✓ キントーン登録完了」に変わります。
        `,
        () => {

            customer.kintone =
                "registered";


            closeModal();

            renderCustomers();

            updateUnregisteredAlert();

            showToast(
                "キントーン登録完了として記録しました。"
            );

        },
        "登録完了"
    );

}


/* =====================================================
   Filter
===================================================== */

function filterCustomers() {

    const keyword =
        document.getElementById(
            "customerKeyword"
        ).value
        .toLowerCase()
        .trim();


    const email =
        document.getElementById(
            "emailKeyword"
        ).value
        .toLowerCase()
        .trim();


    const answerStatus =
        document.getElementById(
            "answerFilter"
        ).value;


    const filtered =
        customers.filter(customer => {

            const text =
                (
                    customer.company
                    +
                    customer.name
                ).toLowerCase();


            const matchesKeyword =
                !keyword ||
                text.includes(keyword);


            const matchesEmail =
                !email ||
                customer.email
                    .toLowerCase()
                    .includes(email);


            const matchesAnswer =
                answerStatus === "all"
                ||
                customer.answerStatus ===
                    answerStatus;


            return (
                matchesKeyword
                &&
                matchesEmail
                &&
                matchesAnswer
            );

        });


    clearSelection();

    renderCustomers(filtered);

    showToast(
        `${filtered.length}件の顧客を表示しています。`
    );

}


function resetFilter() {

    document.getElementById(
        "customerKeyword"
    ).value = "";

    document.getElementById(
        "emailKeyword"
    ).value = "";

    document.getElementById(
        "answerFilter"
    ).value = "all";


    clearSelection();

    renderCustomers();

    showToast(
        "検索条件をリセットしました。"
    );

}


/* =====================================================
   Logs
===================================================== */

function renderLogs() {

    const tbody =
        document.getElementById(
            "logTableBody"
        );


    tbody.innerHTML = "";


    sendLogs.forEach((log, index) => {

        const tr =
            document.createElement("tr");


        tr.innerHTML = `

            <td>
                ${escapeHtml(log.date)}
            </td>

            <td>
                <span class="type-badge">
                    ${escapeHtml(log.type)}
                </span>
            </td>

            <td>
                <strong>
                    ${log.count}
                </strong>
                件
            </td>

            <td>
                ${escapeHtml(log.subject)}
            </td>

            <td>
                ${escapeHtml(log.user)}
            </td>

            <td>
                <button
                    class="link-button"
                    onclick="viewLog(${index})">
                    送信文を確認
                </button>
            </td>

        `;


        tbody.appendChild(tr);

    });

}


/* =====================================================
   Log Detail
===================================================== */

function viewLog(index) {

    const log =
        sendLogs[index];

    if (!log) return;


    const body =
        document.getElementById(
            "mailBody"
        ).value;


    showModal(
        "一括送信履歴・送信文",
        `
        <div style="margin-bottom:13px;">

            <strong>送信日時：</strong>
            ${escapeHtml(log.date)}

            <br>

            <strong>送信種別：</strong>
            ${escapeHtml(log.type)}

            <br>

            <strong>送信件数：</strong>
            ${log.count}件

            <br>

            <strong>送信実行者：</strong>
            ${escapeHtml(log.user)}

        </div>

        <div
            class="small-label">
            件名
        </div>

        <div class="mail-subject">
            ${escapeHtml(log.subject)}
        </div>

        <div class="small-label">
            本文
        </div>

        <div class="mail-preview">
            ${escapeHtml(body)}
        </div>
        `,
        () => closeModal(),
        "閉じる"
    );

}


/* =====================================================
   Modal
===================================================== */

function showModal(
    title,
    body,
    callback,
    confirmText = "OK"
) {

    document.getElementById(
        "modalTitle"
    ).textContent = title;


    document.getElementById(
        "modalBody"
    ).innerHTML = body;


    const button =
        document.getElementById(
            "modalConfirm"
        );


    button.textContent =
        confirmText;


    button.onclick =
        callback;


    document.getElementById(
        "modalOverlay"
    ).classList.add("show");

}


function closeModal() {

    document.getElementById(
        "modalOverlay"
    ).classList.remove("show");

}


function overlayClick(event) {

    if (
        event.target.id ===
        "modalOverlay"
    ) {
        closeModal();
    }

}


/* =====================================================
   Alert Count
===================================================== */

function updateUnregisteredAlert() {

    const count =
        customers.filter(
            c => c.kintone === "unregistered"
        ).length;


    document.getElementById(
        "unregisteredCount"
    ).textContent = count;


    const alert =
        document.getElementById(
            "unregisteredAlert"
        );


    alert.style.display =
        count > 0
        ? "flex"
        : "none";

}


/* =====================================================
   Toast
===================================================== */

let toastTimer;

function showToast(message) {

    const toast =
        document.getElementById(
            "toast"
        );


    toast.textContent =
        message;


    toast.classList.add("show");


    clearTimeout(toastTimer);


    toastTimer =
        setTimeout(() => {

            toast.classList.remove(
                "show"
            );

        }, 3000);

}


/* =====================================================
   Utilities
===================================================== */

function formatDateTime() {

    const d =
        new Date();


    const y =
        d.getFullYear();


    const m =
        String(
            d.getMonth() + 1
        ).padStart(2, "0");


    const day =
        String(
            d.getDate()
        ).padStart(2, "0");


    const h =
        String(
            d.getHours()
        ).padStart(2, "0");


    const min =
        String(
            d.getMinutes()
        ).padStart(2, "0");


    return `${y}/${m}/${day} ${h}:${min}`;

}


function escapeHtml(str) {

    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");

}


/* =====================================================
   Initial Render
===================================================== */

renderCustomers();

renderLogs();

updateUnregisteredAlert();

</script>

</body>
</html>
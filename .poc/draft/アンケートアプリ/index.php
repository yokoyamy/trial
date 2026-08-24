<?php
// ============================================================
// kintone連携設定 モック
// index.php
// ※実際のkintone API通信は行わないデモ用モックです。
// ============================================================

// デモ用kintoneフィールド
$mockFields = [
    ['code' => 'company_name', 'label' => '会社名'],
    ['code' => 'name',         'label' => '氏名'],
    ['code' => 'email',        'label' => 'メールアドレス'],
    ['code' => 'department',   'label' => '部署名'],
    ['code' => 'phone',        'label' => '電話番号'],
    ['code' => 'postal_code',  'label' => '郵便番号'],
    ['code' => 'address',      'label' => '住所'],
    ['code' => 'address2',     'label' => '住所（建物名）'],
    ['code' => 'customer_id',  'label' => '顧客ID'],
];

// デモ用初期設定
$initialMapping = [
    'company'    => 'company_name',
    'name'       => 'name',
    'email'      => 'email',
    'department' => 'department',
    'phone'      => 'phone',
    'address'    => ['postal_code', 'address', 'address2'],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>kintone連携設定</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f5f7fa;
    color: #172033;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Hiragino Kaku Gothic ProN",
        "Yu Gothic",
        Meiryo,
        sans-serif;
}

button,
input,
select {
    font: inherit;
}

button {
    cursor: pointer;
}

/* =========================
   Header
========================= */

.header {
    height: 64px;
    background: #fff;
    border-bottom: 1px solid #e5e9f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 32px;
    position: sticky;
    top: 0;
    z-index: 50;
}

.logo {
    font-size: 18px;
    font-weight: 700;
}

.nav {
    display: flex;
    gap: 8px;
}

.nav a {
    color: #667085;
    text-decoration: none;
    padding: 9px 14px;
    border-radius: 7px;
    font-size: 14px;
}

.nav a:hover,
.nav a.active {
    color: #2563eb;
    background: #eff6ff;
}

/* =========================
   Layout
========================= */

.container {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 24px 60px;
}

.breadcrumb {
    color: #7b8494;
    font-size: 13px;
    margin-bottom: 20px;
}

.breadcrumb span {
    margin: 0 7px;
}

.page-title-area {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 24px;
}

.page-title {
    margin: 0;
    font-size: 28px;
    letter-spacing: -0.5px;
}

.page-description {
    margin: 8px 0 0;
    color: #667085;
    font-size: 14px;
}

/* =========================
   Cards
========================= */

.card {
    background: #fff;
    border: 1px solid #e5e9f0;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 1px 2px rgba(16,24,40,.03);
}

.card-header {
    padding: 20px 24px;
    border-bottom: 1px solid #edf0f4;
}

.card-title {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
}

.card-description {
    color: #667085;
    font-size: 13px;
    margin-top: 6px;
}

.card-body {
    padding: 24px;
}

/* =========================
   Form
========================= */

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group {
    margin-bottom: 18px;
}

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 7px;
}

.required {
    color: #ef4444;
    margin-left: 4px;
}

.input,
.select {
    width: 100%;
    min-height: 44px;
    border: 1px solid #d0d5dd;
    border-radius: 7px;
    padding: 9px 12px;
    background: #fff;
    color: #172033;
    outline: none;
}

.input:focus,
.select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}

.input:disabled {
    background: #f2f4f7;
    color: #98a2b3;
}

.helper {
    font-size: 12px;
    color: #667085;
    margin-top: 6px;
}

/* =========================
   Connection status
========================= */

.connection-box {
    margin-top: 20px;
    padding: 14px 16px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f8fafc;
    border: 1px solid #e4e7ec;
}

.status-dot {
    width: 9px;
    height: 9px;
    background: #98a2b3;
    border-radius: 50%;
}

.status-dot.success {
    background: #12b76a;
}

.status-dot.loading {
    background: #f79009;
    animation: blink .8s infinite;
}

@keyframes blink {
    50% { opacity: .35; }
}

.status-text {
    font-size: 13px;
}

/* =========================
   SSL
========================= */

.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid #edf0f4;
    padding-top: 18px;
    margin-top: 2px;
}

.toggle-label strong {
    display: block;
    font-size: 14px;
}

.toggle-label span {
    display: block;
    color: #667085;
    font-size: 12px;
    margin-top: 3px;
}

.switch {
    position: relative;
    width: 46px;
    height: 26px;
}

.switch input {
    display: none;
}

.slider {
    position: absolute;
    inset: 0;
    border-radius: 20px;
    background: #d0d5dd;
    transition: .2s;
}

.slider:before {
    content: "";
    position: absolute;
    width: 20px;
    height: 20px;
    left: 3px;
    top: 3px;
    background: #fff;
    border-radius: 50%;
    transition: .2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}

.switch input:checked + .slider {
    background: #2563eb;
}

.switch input:checked + .slider:before {
    transform: translateX(20px);
}

/* =========================
   Buttons
========================= */

.btn {
    min-height: 42px;
    padding: 0 16px;
    border-radius: 7px;
    border: 1px solid transparent;
    font-weight: 700;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
}

.btn-primary {
    color: #fff;
    background: #2563eb;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-secondary {
    color: #344054;
    background: #fff;
    border-color: #d0d5dd;
}

.btn-secondary:hover {
    background: #f9fafb;
}

.btn-success {
    color: #fff;
    background: #12b76a;
}

.btn-danger {
    color: #b42318;
    background: #fff;
    border-color: #fecdca;
}

.btn:disabled {
    cursor: not-allowed;
    opacity: .55;
}

/* =========================
   Mapping
========================= */

.mapping-table {
    width: 100%;
    border-collapse: collapse;
}

.mapping-table th {
    text-align: left;
    padding: 13px 14px;
    background: #f8fafc;
    border-bottom: 1px solid #e4e7ec;
    font-size: 12px;
    color: #667085;
}

.mapping-table td {
    padding: 14px;
    border-bottom: 1px solid #edf0f4;
    vertical-align: middle;
}

.system-field {
    font-weight: 700;
}

.system-code {
    display: block;
    color: #98a2b3;
    font-size: 11px;
    margin-top: 3px;
    font-family: monospace;
}

.mapping-select {
    max-width: 430px;
}

.address-mapping {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.address-item {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #f2f4f7;
    padding: 5px 9px;
    border-radius: 5px;
    font-size: 12px;
}

.address-item button {
    border: 0;
    background: transparent;
    color: #98a2b3;
    font-size: 15px;
    padding: 0;
}

/* =========================
   Fetch panel
========================= */

.fetch-panel {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 15px 16px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    margin-top: 20px;
}

.fetch-message {
    font-size: 13px;
    color: #1e40af;
}

/* =========================
   Footer actions
========================= */

.action-footer {
    position: sticky;
    bottom: 0;
    background: rgba(255,255,255,.94);
    backdrop-filter: blur(8px);
    border-top: 1px solid #e5e9f0;
    padding: 14px 24px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    z-index: 40;
}

/* =========================
   Toast
========================= */

.toast {
    position: fixed;
    right: 24px;
    bottom: 85px;
    background: #172033;
    color: #fff;
    padding: 14px 18px;
    border-radius: 8px;
    box-shadow: 0 8px 25px rgba(0,0,0,.18);
    font-size: 13px;
    transform: translateY(20px);
    opacity: 0;
    pointer-events: none;
    transition: .25s;
    z-index: 100;
}

.toast.show {
    transform: translateY(0);
    opacity: 1;
}

.toast.success {
    background: #087443;
}

/* =========================
   Modal
========================= */

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(16,24,40,.55);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 200;
}

.modal-overlay.show {
    display: flex;
}

.modal {
    width: 100%;
    max-width: 480px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 20px 50px rgba(0,0,0,.25);
}

.modal-header {
    padding: 20px 22px;
    border-bottom: 1px solid #edf0f4;
    font-weight: 700;
}

.modal-body {
    padding: 22px;
    color: #667085;
    font-size: 14px;
    line-height: 1.7;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 14px 22px 20px;
}

/* =========================
   Loading
========================= */

.loading-overlay {
    position: fixed;
    inset: 0;
    background: rgba(255,255,255,.72);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 150;
}

.loading-overlay.show {
    display: flex;
}

.loading-box {
    background: #fff;
    padding: 22px 30px;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,.12);
    text-align: center;
}

.spinner {
    width: 30px;
    height: 30px;
    border: 3px solid #dbeafe;
    border-top-color: #2563eb;
    border-radius: 50%;
    animation: spin .7s linear infinite;
    margin: 0 auto 12px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* =========================
   Saved state
========================= */

.saved-info {
    display: none;
    padding: 14px 16px;
    background: #ecfdf3;
    border: 1px solid #abefc6;
    border-radius: 8px;
    color: #067647;
    font-size: 13px;
    margin-bottom: 20px;
}

.saved-info.show {
    display: block;
}

/* =========================
   Responsive
========================= */

@media (max-width: 760px) {

    .header {
        padding: 0 16px;
    }

    .nav a:not(.active) {
        display: none;
    }

    .container {
        padding: 20px 14px 100px;
    }

    .page-title {
        font-size: 23px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .card-body {
        padding: 18px;
    }

    .mapping-wrapper {
        overflow-x: auto;
    }

    .mapping-table {
        min-width: 700px;
    }

    .action-footer {
        padding: 12px 14px;
    }

    .action-footer .btn {
        flex: 1;
    }

    .fetch-panel {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
</head>

<body>

<!-- =========================
     Header
========================= -->
<header class="header">
    <div class="logo">アンケート管理システム</div>

    <nav class="nav">
        <a href="#">アンケート一覧</a>
        <a href="#" class="active">kintone連携設定</a>
        <a href="#">ログアウト</a>
    </nav>
</header>


<main class="container">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        ホーム <span>›</span>
        システム設定 <span>›</span>
        <strong>kintone連携設定</strong>
    </div>


    <!-- Title -->
    <div class="page-title-area">
        <div>
            <h1 class="page-title">kintone連携設定</h1>
            <p class="page-description">
                kintoneの顧客情報とアンケート管理システムの項目を紐付けます。
            </p>
        </div>
    </div>


    <!-- 保存完了 -->
    <div id="savedInfo" class="saved-info">
        ✓ 設定を保存しました。現在のマッピング設定が適用されています。
    </div>


    <!-- =========================
         Connection
    ========================= -->
    <section class="card">

        <div class="card-header">
            <h2 class="card-title">kintoneアプリ接続設定</h2>
            <div class="card-description">
                接続先のkintone環境と顧客管理アプリを設定してください。
            </div>
        </div>

        <div class="card-body">

            <div class="form-grid">

                <div class="form-group">
                    <label class="form-label">
                        サブドメイン<span class="required">*</span>
                    </label>

                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="color:#667085;">https://</span>

                        <input
                            id="subdomain"
                            class="input"
                            type="text"
                            value="example"
                            placeholder="subdomain"
                        >

                        <span style="color:#667085;white-space:nowrap;">
                            .cybozu.com
                        </span>
                    </div>

                    <div class="helper">
                        cybozu.comのサブドメインを入力してください。
                    </div>
                </div>


                <div class="form-group">
                    <label class="form-label">
                        顧客管理アプリID<span class="required">*</span>
                    </label>

                    <input
                        id="appId"
                        class="input"
                        type="text"
                        value="123"
                        placeholder="例：123"
                    >

                    <div class="helper">
                        kintoneで顧客情報を管理しているアプリIDです。
                    </div>
                </div>


                <div class="form-group">
                    <label class="form-label">
                        kintoneログイン名<span class="required">*</span>
                    </label>

                    <input
                        id="username"
                        class="input"
                        type="text"
                        value="admin"
                        placeholder="ログイン名"
                    >
                </div>


                <div class="form-group">
                    <label class="form-label">
                        パスワード<span class="required">*</span>
                    </label>

                    <input
                        id="password"
                        class="input"
                        type="password"
                        value="password"
                        placeholder="パスワード"
                    >
                </div>

            </div>


            <div class="toggle-row">

                <div class="toggle-label">
                    <strong>SSL証明書検証をスキップする</strong>
                    <span>
                        開発環境・特定ネットワークでのみ使用してください。
                    </span>
                </div>

                <label class="switch">
                    <input type="checkbox" id="sslSkip">
                    <span class="slider"></span>
                </label>

            </div>


            <div class="fetch-panel">

                <div>
                    <div style="font-weight:700;font-size:13px;margin-bottom:3px;">
                        kintoneフィールド情報
                    </div>

                    <div id="fetchMessage" class="fetch-message">
                        現在取得済み：9項目
                    </div>
                </div>

                <button
                    type="button"
                    id="fetchButton"
                    class="btn btn-secondary"
                    onclick="fetchFields()"
                >
                    ↻ 項目一覧を再取得
                </button>

            </div>


            <div id="connectionStatus" class="connection-box">

                <span id="statusDot" class="status-dot success"></span>

                <span id="statusText" class="status-text">
                    kintoneへの接続情報が設定されています
                </span>

            </div>

        </div>
    </section>



    <!-- =========================
         Mapping
    ========================= -->
    <section class="card">

        <div class="card-header">
            <h2 class="card-title">顧客情報フィールドマッピング</h2>

            <div class="card-description">
                システム上の顧客情報とkintoneの項目を選択してください。
                kintone内部のフィールドコードを直接入力する必要はありません。
            </div>
        </div>


        <div class="card-body mapping-wrapper">

            <table class="mapping-table">

                <thead>
                    <tr>
                        <th style="width:28%;">システム項目</th>
                        <th>kintone項目</th>
                        <th style="width:25%;">現在の設定</th>
                    </tr>
                </thead>

                <tbody>

                    <!-- Company -->
                    <tr>
                        <td>
                            <div class="system-field">会社名</div>
                            <span class="system-code">Company</span>
                        </td>

                        <td>
                            <select
                                class="select mapping-select"
                                data-field="company"
                                onchange="mappingChanged(this)"
                            >
                            </select>
                        </td>

                        <td id="current-company">
                            会社名
                        </td>
                    </tr>


                    <!-- Name -->
                    <tr>
                        <td>
                            <div class="system-field">氏名</div>
                            <span class="system-code">Name</span>
                        </td>

                        <td>
                            <select
                                class="select mapping-select"
                                data-field="name"
                                onchange="mappingChanged(this)"
                            >
                            </select>
                        </td>

                        <td id="current-name">
                            氏名
                        </td>
                    </tr>


                    <!-- Email -->
                    <tr>
                        <td>
                            <div class="system-field">メールアドレス</div>
                            <span class="system-code">Email</span>
                        </td>

                        <td>
                            <select
                                class="select mapping-select"
                                data-field="email"
                                onchange="mappingChanged(this)"
                            >
                            </select>
                        </td>

                        <td id="current-email">
                            メールアドレス
                        </td>
                    </tr>


                    <!-- Department -->
                    <tr>
                        <td>
                            <div class="system-field">部署名</div>
                            <span class="system-code">Department</span>
                        </td>

                        <td>
                            <select
                                class="select mapping-select"
                                data-field="department"
                                onchange="mappingChanged(this)"
                            >
                            </select>
                        </td>

                        <td id="current-department">
                            部署名
                        </td>
                    </tr>


                    <!-- Phone -->
                    <tr>
                        <td>
                            <div class="system-field">電話番号</div>
                            <span class="system-code">Phone</span>
                        </td>

                        <td>
                            <select
                                class="select mapping-select"
                                data-field="phone"
                                onchange="mappingChanged(this)"
                            >
                            </select>
                        </td>

                        <td id="current-phone">
                            電話番号
                        </td>
                    </tr>


                    <!-- Address -->
                    <tr>
                        <td>
                            <div class="system-field">住所</div>
                            <span class="system-code">Address</span>
                            <div class="helper">
                                複数フィールド選択可
                            </div>
                        </td>

                        <td>

                            <div class="address-mapping" id="addressMapping">
                                <!-- JSで生成 -->
                            </div>

                            <select
                                id="addressSelect"
                                class="select"
                                style="margin-top:10px;max-width:430px;"
                                onchange="addAddressField(this)"
                            >
                            </select>

                        </td>

                        <td id="current-address">
                            郵便番号 / 住所 / 住所（建物名）
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </section>



    <!-- =========================
         Sync behavior
    ========================= -->
    <section class="card">

        <div class="card-header">
            <h2 class="card-title">同期について</h2>
        </div>

        <div class="card-body">

            <div style="
                background:#fffaeb;
                border:1px solid #fedf89;
                border-radius:8px;
                padding:16px;
                color:#93370d;
                font-size:13px;
                line-height:1.7;
            ">
                <strong>⚠ 手動同期のみ</strong><br>
                本システムでは自動同期を行いません。<br>
                メール送信時や回答受信時など、システム上の処理が発生したタイミングで
                必要なkintone情報を取得・連携します。
            </div>

        </div>
    </section>

</main>



<!-- =========================
     Footer
========================= -->

<div class="action-footer">

    <button
        class="btn btn-secondary"
        onclick="cancelSetting()"
    >
        キャンセル
    </button>

    <button
        id="saveButton"
        class="btn btn-primary"
        onclick="saveSetting()"
    >
        ✓ 設定を保存する
    </button>

</div>



<!-- =========================
     Loading
========================= -->

<div id="loadingOverlay" class="loading-overlay">

    <div class="loading-box">

        <div class="spinner"></div>

        <strong id="loadingText">
            kintoneから項目一覧を取得しています...
        </strong>

        <div style="
            color:#667085;
            font-size:12px;
            margin-top:7px;
        ">
            しばらくお待ちください
        </div>

    </div>

</div>



<!-- =========================
     Modal
========================= -->

<div id="modalOverlay" class="modal-overlay">

    <div class="modal">

        <div class="modal-header" id="modalTitle">
            確認
        </div>

        <div class="modal-body" id="modalBody">
        </div>

        <div class="modal-footer">

            <button
                class="btn btn-secondary"
                onclick="closeModal()"
            >
                キャンセル
            </button>

            <button
                id="modalOk"
                class="btn btn-primary"
            >
                OK
            </button>

        </div>

    </div>

</div>



<!-- =========================
     Toast
========================= -->

<div id="toast" class="toast">
    保存しました
</div>



<script>

// ============================================================
// Mock Data
// ============================================================

const mockFields = <?= json_encode($mockFields, JSON_UNESCAPED_UNICODE) ?>;

let mapping = <?= json_encode($initialMapping, JSON_UNESCAPED_UNICODE) ?>;

let fetched = true;


// ============================================================
// 初期化
// ============================================================

document.addEventListener("DOMContentLoaded", function() {

    renderSelects();

    renderAddressMapping();

});


// ============================================================
// kintoneフィールド選択肢描画
// ============================================================

function renderSelects() {

    document.querySelectorAll(".mapping-select").forEach(select => {

        const field = select.dataset.field;

        select.innerHTML = "";

        const empty = document.createElement("option");

        empty.value = "";

        empty.textContent = "選択してください";

        select.appendChild(empty);


        mockFields.forEach(item => {

            const option = document.createElement("option");

            option.value = item.code;

            option.textContent =
                item.label + "（" + item.code + "）";

            if (mapping[field] === item.code) {
                option.selected = true;
            }

            select.appendChild(option);

        });

        updateCurrentText(field, select.value);
    });


    // 住所
    const addressSelect =
        document.getElementById("addressSelect");

    addressSelect.innerHTML = "";

    const empty = document.createElement("option");

    empty.value = "";

    empty.textContent = "＋ 住所項目を追加";

    addressSelect.appendChild(empty);


    mockFields.forEach(item => {

        const option = document.createElement("option");

        option.value = item.code;

        option.textContent =
            item.label + "（" + item.code + "）";

        addressSelect.appendChild(option);

    });
}


// ============================================================
// 現在の設定表示
// ============================================================

function updateCurrentText(field, code) {

    const target =
        document.getElementById("current-" + field);

    if (!target) return;

    const item =
        mockFields.find(x => x.code === code);

    target.textContent =
        item ? item.label : "未設定";
}


// ============================================================
// マッピング変更
// ============================================================

function mappingChanged(select) {

    const field = select.dataset.field;

    mapping[field] = select.value;

    updateCurrentText(field, select.value);

    showToast(
        "「" +
        fieldLabel(field) +
        "」のマッピングを変更しました"
    );
}


// ============================================================
// システム項目ラベル
// ============================================================

function fieldLabel(field) {

    const labels = {
        company: "会社名",
        name: "氏名",
        email: "メールアドレス",
        department: "部署名",
        phone: "電話番号",
        address: "住所"
    };

    return labels[field] || field;
}


// ============================================================
// 住所マッピング
// ============================================================

function renderAddressMapping() {

    const container =
        document.getElementById("addressMapping");

    container.innerHTML = "";

    mapping.address.forEach(code => {

        const item =
            mockFields.find(x => x.code === code);

        if (!item) return;

        const div =
            document.createElement("div");

        div.className = "address-item";

        div.innerHTML = `
            ${item.label}
            <button
                type="button"
                title="削除"
                onclick="removeAddressField('${code}')"
            >×</button>
        `;

        container.appendChild(div);

    });

    updateAddressCurrentText();
}


// ============================================================
// 住所項目追加
// ============================================================

function addAddressField(select) {

    const code = select.value;

    if (!code) return;

    if (!mapping.address.includes(code)) {

        mapping.address.push(code);

        renderAddressMapping();

        showToast("住所項目を追加しました");

    } else {

        showToast("この項目はすでに追加されています");

    }

    select.value = "";
}


// ============================================================
// 住所項目削除
// ============================================================

function removeAddressField(code) {

    mapping.address =
        mapping.address.filter(x => x !== code);

    renderAddressMapping();

    showToast("住所項目を削除しました");
}


// ============================================================
// 住所現在設定
// ============================================================

function updateAddressCurrentText() {

    const labels =
        mapping.address.map(code => {

            const item =
                mockFields.find(x => x.code === code);

            return item ? item.label : "";

        }).filter(Boolean);

    document.getElementById("current-address")
        .textContent =
        labels.length
            ? labels.join(" / ")
            : "未設定";
}


// ============================================================
// 項目一覧再取得
// ============================================================

function fetchFields() {

    const subdomain =
        document.getElementById("subdomain").value.trim();

    const appId =
        document.getElementById("appId").value.trim();

    if (!subdomain || !appId) {

        openModal(
            "入力内容を確認してください",
            "サブドメインと顧客管理アプリIDを入力してから、項目一覧を再取得してください。",
            null
        );

        return;
    }


    const button =
        document.getElementById("fetchButton");

    button.disabled = true;

    document.getElementById("loadingOverlay")
        .classList.add("show");


    document.getElementById("loadingText")
        .textContent =
        "kintoneから項目一覧を取得しています...";


    document.getElementById("statusDot")
        .className =
        "status-dot loading";

    document.getElementById("statusText")
        .textContent =
        "kintoneへ接続しています";


    // API通信を模した待ち時間
    setTimeout(function() {

        document.getElementById("loadingOverlay")
            .classList.remove("show");

        button.disabled = false;

        fetched = true;

        document.getElementById("fetchMessage")
            .textContent =
            "✓ kintoneから9項目を取得しました（最終取得：たった今）";


        document.getElementById("statusDot")
            .className =
            "status-dot success";

        document.getElementById("statusText")
            .textContent =
            "kintoneへの接続に成功しました。フィールド情報を更新しました";


        renderSelects();

        renderAddressMapping();

        showToast(
            "kintoneの項目一覧を再取得しました",
            true
        );

    }, 1200);
}


// ============================================================
// 設定保存
// ============================================================

function saveSetting() {

    const subdomain =
        document.getElementById("subdomain")
            .value.trim();

    const appId =
        document.getElementById("appId")
            .value.trim();

    const username =
        document.getElementById("username")
            .value.trim();

    const password =
        document.getElementById("password")
            .value.trim();


    if (!subdomain ||
        !appId ||
        !username ||
        !password) {

        openModal(
            "入力内容を確認してください",
            "必須項目が入力されていません。",
            null
        );

        return;
    }


    // マッピングチェック

    if (!mapping.company ||
        !mapping.name ||
        !mapping.email) {

        openModal(
            "マッピングを確認してください",
            "会社名・氏名・メールアドレスは必須マッピングです。",
            null
        );

        return;
    }


    openModal(
        "設定を保存しますか？",

        `
        以下の内容で保存します。

        <br><br>

        <strong>接続先：</strong>
        https://${escapeHtml(subdomain)}.cybozu.com

        <br>

        <strong>アプリID：</strong>
        ${escapeHtml(appId)}

        <br><br>

        保存後、このマッピング設定がメール送信・回答連携で使用されます。
        `,

        function() {

            executeSave();

        }
    );
}


// ============================================================
// 保存処理
// ============================================================

function executeSave() {

    closeModal();

    document.getElementById("loadingOverlay")
        .classList.add("show");

    document.getElementById("loadingText")
        .textContent =
        "設定を保存しています...";


    setTimeout(function() {

        document.getElementById("loadingOverlay")
            .classList.remove("show");

        document.getElementById("savedInfo")
            .classList.add("show");

        showToast(
            "kintone連携設定を保存しました",
            true
        );

    }, 900);
}


// ============================================================
// キャンセル
// ============================================================

function cancelSetting() {

    openModal(
        "設定を破棄しますか？",

        "変更内容は保存されません。<br><br>設定画面を離れますか？",

        function() {

            closeModal();

            showToast(
                "設定画面を終了しました"
            );

            // 実際のシステムでは一覧等へ遷移
            // location.href = "index.php";

        }
    );
}


// ============================================================
// Modal
// ============================================================

let modalCallback = null;

function openModal(title, body, callback) {

    document.getElementById("modalTitle")
        .textContent = title;

    document.getElementById("modalBody")
        .innerHTML = body;

    modalCallback = callback;

    document.getElementById("modalOverlay")
        .classList.add("show");


    const okButton =
        document.getElementById("modalOk");

    okButton.onclick = function() {

        if (modalCallback) {
            modalCallback();
        } else {
            closeModal();
        }

    };
}


function closeModal() {

    document.getElementById("modalOverlay")
        .classList.remove("show");

    modalCallback = null;
}


// ============================================================
// Toast
// ============================================================

function showToast(message, success = false) {

    const toast =
        document.getElementById("toast");

    toast.textContent = message;

    toast.className =
        "toast show" +
        (success ? " success" : "");

    clearTimeout(window.toastTimer);

    window.toastTimer =
        setTimeout(function() {

            toast.classList.remove("show");

        }, 2800);
}


// ============================================================
// HTML escape
// ============================================================

function escapeHtml(value) {

    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

</script>

</body>
</html>
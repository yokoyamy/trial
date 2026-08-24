<?php
// ============================================================
// アンケート管理システム：回答集計・分析画面 モック
// index.php
// ============================================================
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>回答集計・分析 | アンケート管理システム</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family:
        -apple-system, BlinkMacSystemFont,
        "Segoe UI", "Hiragino Kaku Gothic ProN",
        "Hiragino Sans", Meiryo, sans-serif;
    background: #f5f7fa;
    color: #1f2937;
}

/* =========================
   Header
========================= */

.header {
    height: 64px;
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 28px;
    position: sticky;
    top: 0;
    z-index: 50;
}

.logo {
    font-size: 18px;
    font-weight: 700;
    color: #2563eb;
}

.header-right {
    display: flex;
    gap: 8px;
}

.nav-btn {
    border: 0;
    background: transparent;
    color: #4b5563;
    padding: 9px 14px;
    border-radius: 7px;
    cursor: pointer;
}

.nav-btn:hover {
    background: #f3f4f6;
}

/* =========================
   Layout
========================= */

.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 28px;
}

.breadcrumb {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 18px;
}

.breadcrumb span {
    color: #2563eb;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 24px;
}

.page-title {
    margin: 0;
    font-size: 26px;
    font-weight: 700;
}

.survey-title {
    margin-top: 8px;
    font-size: 16px;
    color: #4b5563;
}

.header-actions {
    display: flex;
    gap: 10px;
}

/* =========================
   Buttons
========================= */

.btn {
    border: 1px solid #d1d5db;
    background: #fff;
    color: #374151;
    border-radius: 7px;
    padding: 10px 16px;
    min-height: 42px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: .15s;
}

.btn:hover {
    background: #f9fafb;
}

.btn-primary {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-success {
    background: #059669;
    border-color: #059669;
    color: #fff;
}

/* =========================
   Cards
========================= */

.card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 1px 2px rgba(0,0,0,.03);
}

.card-header {
    padding: 18px 20px;
    border-bottom: 1px solid #edf0f3;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.card-title {
    font-weight: 700;
    font-size: 16px;
}

.card-body {
    padding: 20px;
}

/* =========================
   Summary
========================= */

.summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
}

.summary-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 20px;
}

.summary-label {
    color: #6b7280;
    font-size: 13px;
    margin-bottom: 10px;
}

.summary-value {
    font-size: 27px;
    font-weight: 700;
}

.summary-value small {
    font-size: 14px;
    font-weight: 500;
    margin-left: 3px;
}

.summary-sub {
    font-size: 12px;
    color: #9ca3af;
    margin-top: 7px;
}

.blue {
    color: #2563eb;
}

.green {
    color: #059669;
}

.orange {
    color: #d97706;
}

.red {
    color: #dc2626;
}

/* =========================
   Question filter
========================= */

.filter-actions {
    display: flex;
    gap: 8px;
}

.question-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}

.question-check {
    border: 1px solid #e5e7eb;
    padding: 12px;
    border-radius: 7px;
    display: flex;
    gap: 10px;
    align-items: flex-start;
    cursor: pointer;
}

.question-check:hover {
    background: #f9fafb;
}

.question-check input {
    margin-top: 3px;
}

.question-number {
    font-weight: 700;
    color: #2563eb;
}

.question-text {
    font-size: 14px;
}

.badge {
    display: inline-flex;
    align-items: center;
    border-radius: 20px;
    padding: 3px 8px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 5px;
}

.badge-blue {
    background: #eff6ff;
    color: #2563eb;
}

.badge-green {
    background: #ecfdf5;
    color: #047857;
}

.badge-gray {
    background: #f3f4f6;
    color: #4b5563;
}

.badge-orange {
    background: #fff7ed;
    color: #c2410c;
}

/* =========================
   Charts
========================= */

.group-title {
    font-size: 18px;
    margin: 0;
    font-weight: 700;
}

.chart-card {
    border: 1px solid #e5e7eb;
    border-radius: 9px;
    margin-bottom: 16px;
    overflow: hidden;
}

.chart-header {
    background: #fafafa;
    padding: 16px;
    border-bottom: 1px solid #eee;
}

.chart-body {
    padding: 20px;
}

.bar-row {
    display: grid;
    grid-template-columns: 180px 1fr 90px;
    align-items: center;
    gap: 14px;
    margin: 14px 0;
}

.bar-label {
    font-size: 14px;
}

.bar-bg {
    height: 18px;
    background: #eef2f7;
    border-radius: 20px;
    overflow: hidden;
}

.bar {
    height: 100%;
    border-radius: 20px;
    background: #3b82f6;
    transition: width .4s ease;
}

.bar.green-bar {
    background: #10b981;
}

.bar.orange-bar {
    background: #f59e0b;
}

.bar-value {
    font-size: 13px;
    text-align: right;
    color: #4b5563;
}

/* =========================
   Other answers
========================= */

.other-box {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    margin-top: 20px;
    overflow: hidden;
}

.other-header {
    padding: 12px 15px;
    font-weight: 700;
    color: #92400e;
    cursor: pointer;
}

.other-content {
    display: none;
    border-top: 1px solid #fde68a;
    background: #fff;
}

.other-content.open {
    display: block;
}

.other-item {
    padding: 13px 15px;
    border-bottom: 1px solid #f3f4f6;
}

.other-item:last-child {
    border-bottom: 0;
}

.customer-name {
    font-weight: 700;
}

.customer-company {
    color: #6b7280;
    font-size: 12px;
}

.answer-text {
    margin-top: 5px;
}

/* =========================
   Timeline
========================= */

.timeline {
    max-height: 330px;
    overflow-y: auto;
}

.timeline-item {
    position: relative;
    padding: 0 0 20px 22px;
    margin-left: 5px;
    border-left: 2px solid #dbeafe;
}

.timeline-item:last-child {
    border-left-color: transparent;
}

.timeline-item::before {
    content: "";
    position: absolute;
    left: -6px;
    top: 2px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #3b82f6;
}

.timeline-date {
    color: #9ca3af;
    font-size: 12px;
}

.timeline-answer {
    margin-top: 6px;
    line-height: 1.7;
}

/* =========================
   Individual answers
========================= */

.search-box {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
}

.search-box input {
    flex: 1;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    padding: 11px 13px;
    font-size: 14px;
}

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}

th {
    background: #f8fafc;
    color: #4b5563;
    font-size: 12px;
    text-align: left;
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
}

td {
    padding: 14px 12px;
    border-bottom: 1px solid #edf0f3;
    font-size: 13px;
    vertical-align: top;
}

.answer-highlight {
    color: #c2410c;
    font-weight: 600;
}

.link-btn {
    border: 0;
    background: none;
    color: #2563eb;
    cursor: pointer;
    font-weight: 600;
    padding: 0;
}

/* =========================
   Modal
========================= */

.modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.55);
    z-index: 100;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-backdrop.show {
    display: flex;
}

.modal {
    background: #fff;
    width: min(760px, 100%);
    max-height: 85vh;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
}

.modal-header {
    padding: 18px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    font-weight: 700;
}

.modal-close {
    border: 0;
    background: #f3f4f6;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    cursor: pointer;
}

.modal-body {
    padding: 20px;
    overflow-y: auto;
    max-height: 65vh;
}

.answer-detail {
    border-bottom: 1px solid #eee;
    padding: 15px 0;
}

.answer-detail:last-child {
    border-bottom: 0;
}

.answer-detail-q {
    font-weight: 700;
    margin-bottom: 7px;
}

.answer-detail-a {
    color: #4b5563;
    line-height: 1.7;
}

/* =========================
   Empty
========================= */

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-icon {
    font-size: 42px;
    margin-bottom: 10px;
}

/* =========================
   Toast
========================= */

.toast {
    position: fixed;
    right: 25px;
    bottom: 25px;
    background: #111827;
    color: #fff;
    padding: 13px 18px;
    border-radius: 8px;
    opacity: 0;
    transform: translateY(20px);
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

@media (max-width: 1000px) {
    .summary-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 700px) {
    .header {
        padding: 0 15px;
    }

    .header-right .nav-btn:not(:first-child) {
        display: none;
    }

    .container {
        padding: 18px 12px;
    }

    .page-header {
        flex-direction: column;
    }

    .header-actions {
        width: 100%;
    }

    .header-actions .btn {
        flex: 1;
    }

    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .question-list {
        grid-template-columns: 1fr;
    }

    .bar-row {
        grid-template-columns: 110px 1fr 70px;
        gap: 8px;
    }

    .bar-label {
        font-size: 12px;
    }
}

@media (max-width: 450px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .summary-value {
        font-size: 23px;
    }
}

/* =========================
   Print / PDF
========================= */

@media print {
    .header,
    .header-actions,
    .filter-actions,
    .search-box,
    .individual-card,
    .modal-backdrop {
        display: none !important;
    }

    body {
        background: white;
    }

    .container {
        max-width: none;
        padding: 0;
    }

    .card,
    .summary-card {
        box-shadow: none;
        break-inside: avoid;
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

    <div class="header-right">
        <button class="nav-btn" onclick="goList()">アンケート一覧</button>
        <button class="nav-btn" onclick="alert('キントーン連携設定画面へ遷移します')">
            キントーン連携設定
        </button>
        <button class="nav-btn" onclick="alert('ログアウトしました')">
            ログアウト
        </button>
    </div>
</header>


<main class="container">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        ホーム　&gt;　<span>アンケート一覧</span>　&gt;
        回答集計・分析
    </div>


    <!-- Page Header -->
    <section class="page-header">

        <div>
            <h1 class="page-title">回答集計・分析</h1>

            <div class="survey-title">
                対象アンケート：
                <strong>2026年度 顧客満足度調査</strong>
            </div>
        </div>

        <div class="header-actions">
            <button class="btn" onclick="downloadCSV()">
                CSVダウンロード
            </button>

            <button class="btn btn-primary" onclick="exportPDF()">
                PDF出力
            </button>
        </div>

    </section>


    <!-- =========================
         Summary
    ========================= -->

    <section class="summary-grid">

        <div class="summary-card">
            <div class="summary-label">送信対象者数</div>
            <div class="summary-value blue">
                200 <small>人</small>
            </div>
            <div class="summary-sub">メール送信対象</div>
        </div>

        <div class="summary-card">
            <div class="summary-label">回答数</div>
            <div class="summary-value green">
                128 <small>件</small>
            </div>
            <div class="summary-sub">有効回答数</div>
        </div>

        <div class="summary-card">
            <div class="summary-label">未登録顧客からの回答数</div>
            <div class="summary-value orange">
                8 <small>件</small>
            </div>
            <div class="summary-sub">Web公開URL経由</div>
        </div>

        <div class="summary-card">
            <div class="summary-label">未回答数</div>
            <div class="summary-value red">
                80 <small>人</small>
            </div>
            <div class="summary-sub">送信済み・未回答</div>
        </div>

        <div class="summary-card">
            <div class="summary-label">回答率</div>
            <div class="summary-value blue">
                60.0 <small>%</small>
            </div>
            <div class="summary-sub">
                128 / 200
            </div>
        </div>

    </section>


    <!-- =========================
         Question Filter
    ========================= -->

    <section class="card">

        <div class="card-header">

            <div class="card-title">
                集計対象の設問
            </div>

            <div class="filter-actions">
                <button class="btn" onclick="selectAllQuestions()">
                    すべて選択
                </button>

                <button class="btn" onclick="clearAllQuestions()">
                    すべて解除
                </button>
            </div>

        </div>

        <div class="card-body">

            <div class="question-list">

                <label class="question-check">
                    <input
                        type="checkbox"
                        class="question-filter"
                        data-target="q1"
                        checked
                        onchange="toggleQuestion('q1')"
                    >

                    <div>
                        <div>
                            <span class="question-number">Q1.</span>
                            サービス全体の満足度を教えてください
                        </div>

                        <span class="badge badge-blue">
                            単一選択
                        </span>
                    </div>
                </label>


                <label class="question-check">
                    <input
                        type="checkbox"
                        class="question-filter"
                        data-target="q2"
                        checked
                        onchange="toggleQuestion('q2')"
                    >

                    <div>
                        <div>
                            <span class="question-number">Q2.</span>
                            当社サービスをどの程度おすすめしますか？
                        </div>

                        <span class="badge badge-blue">
                            単一選択
                        </span>
                    </div>
                </label>


                <label class="question-check">
                    <input
                        type="checkbox"
                        class="question-filter"
                        data-target="q3"
                        checked
                        onchange="toggleQuestion('q3')"
                    >

                    <div>
                        <div>
                            <span class="question-number">Q3.</span>
                            利用しているサービスを選択してください
                        </div>

                        <span class="badge badge-green">
                            複数選択
                        </span>
                    </div>
                </label>


                <label class="question-check">
                    <input
                        type="checkbox"
                        class="question-filter"
                        data-target="q4"
                        checked
                        onchange="toggleQuestion('q4')"
                    >

                    <div>
                        <div>
                            <span class="question-number">Q4.</span>
                            改善してほしい点があれば教えてください
                        </div>

                        <span class="badge badge-gray">
                            テキスト
                        </span>
                    </div>
                </label>

            </div>

        </div>

    </section>


    <!-- =========================
         Group 1
    ========================= -->

    <section class="card">

        <div class="card-header">
            <div class="group-title">
                グループ1：サービス評価
            </div>
        </div>

        <div class="card-body">


            <!-- Q1 -->

            <div class="chart-card" id="q1">

                <div class="chart-header">
                    <strong>Q1. サービス全体の満足度を教えてください</strong>
                    <span class="badge badge-blue">単一選択</span>
                </div>

                <div class="chart-body">

                    <div class="bar-row">
                        <div class="bar-label">非常に満足</div>
                        <div class="bar-bg">
                            <div class="bar" style="width:72%"></div>
                        </div>
                        <div class="bar-value">72件 / 56.3%</div>
                    </div>

                    <div class="bar-row">
                        <div class="bar-label">満足</div>
                        <div class="bar-bg">
                            <div class="bar green-bar" style="width:38%"></div>
                        </div>
                        <div class="bar-value">38件 / 29.7%</div>
                    </div>

                    <div class="bar-row">
                        <div class="bar-label">普通</div>
                        <div class="bar-bg">
                            <div class="bar orange-bar" style="width:14%"></div>
                        </div>
                        <div class="bar-value">14件 / 10.9%</div>
                    </div>

                    <div class="bar-row">
                        <div class="bar-label">不満</div>
                        <div class="bar-bg">
                            <div class="bar" style="width:4%;background:#ef4444"></div>
                        </div>
                        <div class="bar-value">4件 / 3.1%</div>
                    </div>

                </div>

            </div>


            <!-- Q2 -->

            <div class="chart-card" id="q2">

                <div class="chart-header">
                    <strong>
                        Q2. 当社サービスをどの程度おすすめしますか？
                    </strong>
                    <span class="badge badge-blue">単一選択</span>
                </div>

                <div class="chart-body">

                    <div class="bar-row">
                        <div class="bar-label">ぜひおすすめしたい</div>
                        <div class="bar-bg">
                            <div class="bar" style="width:65%"></div>
                        </div>
                        <div class="bar-value">65件 / 50.8%</div>
                    </div>

                    <div class="bar-row">
                        <div class="bar-label">おすすめしたい</div>
                        <div class="bar-bg">
                            <div class="bar green-bar" style="width:43%"></div>
                        </div>
                        <div class="bar-value">43件 / 33.6%</div>
                    </div>

                    <div class="bar-row">
                        <div class="bar-label">どちらともいえない</div>
                        <div class="bar-bg">
                            <div class="bar orange-bar" style="width:15%"></div>
                        </div>
                        <div class="bar-value">15件 / 11.7%</div>
                    </div>

                    <div class="bar-row">
                        <div class="bar-label">おすすめしない</div>
                        <div class="bar-bg">
                            <div class="bar" style="width:5%;background:#ef4444"></div>
                        </div>
                        <div class="bar-value">5件 / 3.9%</div>
                    </div>

                </div>

            </div>


            <!-- Q3 -->

            <div class="chart-card" id="q3">

                <div class="chart-header">
                    <strong>
                        Q3. 利用しているサービスを選択してください
                    </strong>
                    <span class="badge badge-green">複数選択</span>
                </div>

                <div class="chart-body">

                    <div class="bar-row">
                        <div class="bar-label">クラウドサービス</div>
                        <div class="bar-bg">
                            <div class="bar" style="width:80%"></div>
                        </div>
                        <div class="bar-value">102件 / 79.7%</div>
                    </div>

                    <div class="bar-row">
                        <div class="bar-label">業務システム</div>
                        <div class="bar-bg">
                            <div class="bar green-bar" style="width:58%"></div>
                        </div>
                        <div class="bar-value">74件 / 57.8%</div>
                    </div>

                    <div class="bar-row">
                        <div class="bar-label">サポートサービス</div>
                        <div class="bar-bg">
                            <div class="bar orange-bar" style="width:36%"></div>
                        </div>
                        <div class="bar-value">46件 / 35.9%</div>
                    </div>


                    <div class="other-box">

                        <div
                            class="other-header"
                            onclick="toggleOther(this)"
                        >
                            その他　4件
                            <span class="badge badge-orange">
                                自由記述 4件
                            </span>
                            <span style="float:right">▼</span>
                        </div>

                        <div class="other-content">

                            <div class="other-item">
                                <div class="customer-name">
                                    株式会社サンプル
                                </div>
                                <div class="customer-company">
                                    山田 太郎
                                </div>
                                <div class="answer-text">
                                    新しい分析サービス
                                </div>
                            </div>

                            <div class="other-item">
                                <div class="customer-name">
                                    株式会社ABC
                                </div>
                                <div class="customer-company">
                                    佐藤 花子
                                </div>
                                <div class="answer-text">
                                    データ連携サービス
                                </div>
                            </div>

                            <div class="other-item">
                                <div class="customer-name">
                                    株式会社XYZ
                                </div>
                                <div class="customer-company">
                                    鈴木 一郎
                                </div>
                                <div class="answer-text">
                                    AI関連サービス
                                </div>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>


    <!-- =========================
         Group 2
    ========================= -->

    <section class="card" id="q4">

        <div class="card-header">

            <div class="group-title">
                グループ2：改善要望
            </div>

        </div>

        <div class="card-body">

            <div class="chart-card">

                <div class="chart-header">
                    <strong>
                        Q4. 改善してほしい点があれば教えてください
                    </strong>

                    <span class="badge badge-gray">
                        テキスト
                    </span>
                </div>

                <div class="chart-body">

                    <div class="timeline">

                        <div class="timeline-item">

                            <div class="timeline-date">
                                2026/08/24 10:32
                            </div>

                            <div class="customer-name">
                                株式会社サンプル　山田 太郎
                            </div>

                            <div class="timeline-answer">
                                管理画面の検索機能をもう少し
                                高速にしてほしいです。
                            </div>

                        </div>


                        <div class="timeline-item">

                            <div class="timeline-date">
                                2026/08/23 16:18
                            </div>

                            <div class="customer-name">
                                株式会社ABC　佐藤 花子
                            </div>

                            <div class="timeline-answer">
                                スマートフォンからも
                                操作しやすくしてほしいです。
                            </div>

                        </div>


                        <div class="timeline-item">

                            <div class="timeline-date">
                                2026/08/23 13:04
                            </div>

                            <div class="customer-name">
                                株式会社XYZ　鈴木 一郎
                            </div>

                            <div class="timeline-answer">
                                レポートの出力形式を
                                増やしてほしいです。
                            </div>

                        </div>


                        <div class="timeline-item">

                            <div class="timeline-date">
                                2026/08/22 09:51
                            </div>

                            <div class="customer-name">
                                株式会社DEF　高橋 美咲
                            </div>

                            <div class="timeline-answer">
                                通知メールのテンプレートを
                                複数登録したいです。
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>


    <!-- =========================
         Individual Answers
    ========================= -->

    <section class="card individual-card">

        <div class="card-header">

            <div class="card-title">
                個別回答一覧
            </div>

            <div style="font-size:13px;color:#6b7280;">
                128件
            </div>

        </div>

        <div class="card-body">

            <div class="search-box">

                <input
                    type="text"
                    id="answerSearch"
                    placeholder="会社名・氏名で検索..."
                    oninput="filterAnswers()"
                >

                <button class="btn" onclick="clearSearch()">
                    クリア
                </button>

            </div>


            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>
                            <th>会社名 / 氏名</th>
                            <th>回答日時</th>
                            <th>回答概要</th>
                            <th>操作</th>
                        </tr>

                    </thead>

                    <tbody id="answerTable">

                        <tr data-search="株式会社サンプル 山田太郎">

                            <td>
                                <strong>株式会社サンプル</strong><br>
                                山田 太郎
                            </td>

                            <td>
                                2026/08/24<br>
                                10:32
                            </td>

                            <td>
                                満足 / ぜひおすすめしたい<br>
                                <span class="answer-highlight">
                                    その他：新しい分析サービス
                                </span>
                            </td>

                            <td>
                                <button
                                    class="link-btn"
                                    onclick="openAnswer(1)"
                                >
                                    全回答を表示
                                </button>
                            </td>

                        </tr>


                        <tr data-search="株式会社ABC 佐藤花子">

                            <td>
                                <strong>株式会社ABC</strong><br>
                                佐藤 花子
                            </td>

                            <td>
                                2026/08/23<br>
                                16:18
                            </td>

                            <td>
                                非常に満足 / おすすめしたい<br>
                                その他なし
                            </td>

                            <td>
                                <button
                                    class="link-btn"
                                    onclick="openAnswer(2)"
                                >
                                    全回答を表示
                                </button>
                            </td>

                        </tr>


                        <tr data-search="株式会社XYZ 鈴木一郎">

                            <td>
                                <strong>株式会社XYZ</strong><br>
                                鈴木 一郎
                            </td>

                            <td>
                                2026/08/23<br>
                                13:04
                            </td>

                            <td>
                                満足 / ぜひおすすめしたい<br>
                                <span class="answer-highlight">
                                    その他：AI関連サービス
                                </span>
                            </td>

                            <td>
                                <button
                                    class="link-btn"
                                    onclick="openAnswer(3)"
                                >
                                    全回答を表示
                                </button>
                            </td>

                        </tr>


                        <tr data-search="株式会社DEF 高橋美咲">

                            <td>
                                <strong>株式会社DEF</strong><br>
                                高橋 美咲
                            </td>

                            <td>
                                2026/08/22<br>
                                09:51
                            </td>

                            <td>
                                普通 / どちらともいえない<br>
                                その他なし
                            </td>

                            <td>
                                <button
                                    class="link-btn"
                                    onclick="openAnswer(4)"
                                >
                                    全回答を表示
                                </button>
                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

        </div>

    </section>

</main>


<!-- =========================
     Answer Modal
========================= -->

<div
    class="modal-backdrop"
    id="answerModal"
    onclick="closeModalByBackdrop(event)"
>

    <div class="modal">

        <div class="modal-header">

            <div class="modal-title" id="modalCustomer">
                全回答
            </div>

            <button
                class="modal-close"
                onclick="closeAnswerModal()"
            >
                ×
            </button>

        </div>


        <div class="modal-body">

            <div class="answer-detail">

                <div class="answer-detail-q">
                    Q1. サービス全体の満足度を教えてください
                </div>

                <div class="answer-detail-a">
                    <strong>満足</strong>
                </div>

            </div>


            <div class="answer-detail">

                <div class="answer-detail-q">
                    Q2. 当社サービスをどの程度おすすめしますか？
                </div>

                <div class="answer-detail-a">
                    <strong>ぜひおすすめしたい</strong>
                </div>

            </div>


            <div class="answer-detail">

                <div class="answer-detail-q">
                    Q3. 利用しているサービスを選択してください
                </div>

                <div class="answer-detail-a">
                    クラウドサービス<br>
                    業務システム<br>
                    <span class="answer-highlight">
                        その他：新しい分析サービス
                    </span>
                </div>

            </div>


            <div class="answer-detail">

                <div class="answer-detail-q">
                    Q4. 改善してほしい点があれば教えてください
                </div>

                <div class="answer-detail-a">
                    管理画面の検索機能をもう少し
                    高速にしてほしいです。
                </div>

            </div>

        </div>

    </div>

</div>


<!-- Toast -->

<div class="toast" id="toast"></div>


<script>
// ============================================================
// JavaScript
// ============================================================


// -----------------------------
// Toast
// -----------------------------

function showToast(message) {

    const toast = document.getElementById("toast");

    toast.textContent = message;
    toast.classList.add("show");

    setTimeout(() => {
        toast.classList.remove("show");
    }, 2500);
}


// -----------------------------
// CSV Download
// -----------------------------

function downloadCSV() {

    const rows = [
        [
            "回答ID",
            "回答日時",
            "顧客ID",
            "会社名",
            "氏名",
            "設問1",
            "設問2",
            "設問3",
            "設問4"
        ],
        [
            "A0001",
            "2026/08/24 10:32",
            "C001",
            "株式会社サンプル",
            "山田 太郎",
            "満足",
            "ぜひおすすめしたい",
            "クラウドサービス、業務システム、その他：新しい分析サービス",
            "管理画面の検索機能をもう少し高速にしてほしいです。"
        ],
        [
            "A0002",
            "2026/08/23 16:18",
            "C002",
            "株式会社ABC",
            "佐藤 花子",
            "非常に満足",
            "おすすめしたい",
            "クラウドサービス",
            "スマートフォンからも操作しやすくしてほしいです。"
        ]
    ];

    // UTF-8 BOM
    const bom = "\uFEFF";

    const csv = bom + rows
        .map(row =>
            row.map(value =>
                '"' + String(value).replace(/"/g, '""') + '"'
            ).join(",")
        )
        .join("\r\n");

    const blob = new Blob(
        [csv],
        { type: "text/csv;charset=utf-8;" }
    );

    const url = URL.createObjectURL(blob);

    const link = document.createElement("a");

    link.href = url;
    link.download = "アンケート回答データ.csv";

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    URL.revokeObjectURL(url);

    showToast("CSVをダウンロードしました");
}


// -----------------------------
// PDF
// -----------------------------

function exportPDF() {

    showToast("PDF出力用の印刷画面を開きます");

    setTimeout(() => {
        window.print();
    }, 500);
}


// -----------------------------
// Question filter
// -----------------------------

function toggleQuestion(id) {

    const checkbox = document.querySelector(
        '[data-target="' + id + '"]'
    );

    const element = document.getElementById(id);

    if (!checkbox || !element) return;

    if (checkbox.checked) {

        element.style.display = "";

    } else {

        element.style.display = "none";

    }

}


function selectAllQuestions() {

    document.querySelectorAll(".question-filter")
        .forEach(checkbox => {

            checkbox.checked = true;

            const id = checkbox.dataset.target;

            const element = document.getElementById(id);

            if (element) {
                element.style.display = "";
            }

        });

    showToast("すべての設問を選択しました");
}


function clearAllQuestions() {

    document.querySelectorAll(".question-filter")
        .forEach(checkbox => {

            checkbox.checked = false;

            const id = checkbox.dataset.target;

            const element = document.getElementById(id);

            if (element) {
                element.style.display = "none";
            }

        });

    showToast("すべての設問を解除しました");
}


// -----------------------------
// Other toggle
// -----------------------------

function toggleOther(header) {

    const content = header.nextElementSibling;

    content.classList.toggle("open");

    const arrow =
        header.querySelector("span:last-child");

    if (content.classList.contains("open")) {
        arrow.textContent = "▲";
    } else {
        arrow.textContent = "▼";
    }
}


// -----------------------------
// Search
// -----------------------------

function filterAnswers() {

    const keyword =
        document.getElementById("answerSearch")
        .value
        .toLowerCase()
        .trim();

    document
        .querySelectorAll("#answerTable tr")
        .forEach(row => {

            const text =
                row.dataset.search.toLowerCase();

            if (text.includes(keyword)) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }

        });

}


function clearSearch() {

    document.getElementById("answerSearch").value = "";

    filterAnswers();

}


// -----------------------------
// Answer modal
// -----------------------------

const customers = {
    1: "株式会社サンプル　山田 太郎",
    2: "株式会社ABC　佐藤 花子",
    3: "株式会社XYZ　鈴木 一郎",
    4: "株式会社DEF　高橋 美咲"
};


function openAnswer(id) {

    document.getElementById("modalCustomer")
        .textContent =
        "全回答： " + customers[id];

    document.getElementById("answerModal")
        .classList.add("show");

}


function closeAnswerModal() {

    document.getElementById("answerModal")
        .classList.remove("show");

}


function closeModalByBackdrop(event) {

    if (event.target.id === "answerModal") {
        closeAnswerModal();
    }

}


// ESCでモーダルを閉じる

document.addEventListener("keydown", function(event) {

    if (event.key === "Escape") {
        closeAnswerModal();
    }

});


// -----------------------------
// Back to list
// -----------------------------

function goList() {

    if (
        confirm(
            "アンケート一覧画面へ戻りますか？"
        )
    ) {

        // 実際のシステムでは
        // location.href = "/survey/index.php";
        showToast(
            "アンケート一覧画面へ遷移します"
        );

    }

}
</script>

</body>
</html>
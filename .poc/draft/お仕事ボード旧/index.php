<?php
if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>お仕事管理 付箋ボード</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #eef2f6;
            --panel: #ffffff;
            --line: #d8dee9;
            --text: #1f2937;
            --muted: #667085;
            --dark: #263238;
            --blue: #1976d2;
            --green: #2e7d32;
            --yellow: #f9a825;
            --red: #d32f2f;

            --status-unassigned: #fff4c2;
            --status-doing: #dff0ff;
            --status-hold: #ffe3aa;
            --status-done: #dcf8df;
            --status-overdue: #ffdde1;

            --sunday-bg: #fff1f2;
            --saturday-bg: #eff6ff;
            --today-bg: #fff7cc;
            --today-border: #f59e0b;

            --shadow: 0 6px 14px rgba(15, 23, 42, .07);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 13px;
        }

        header {
            background: var(--dark);
            color: #fff;
            padding: 10px 14px;
        }

        h1 { margin: 0; font-size: 18px; }
        h2 { margin: 0 0 6px; font-size: 16px; }
        h3 { margin: 0 0 8px; font-size: 15px; }

        main { padding: 10px; }

        button, input, select, textarea { font: inherit; }

        input, select, textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 6px 8px;
            background: #fff;
        }

        textarea { resize: vertical; }

        button {
            border: 0;
            border-radius: 8px;
            padding: 6px 9px;
            background: var(--blue);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            font-size: 12px;
        }

        button.secondary { background: #546e7a; }
        button.success { background: var(--green); }
        button.warning { background: var(--yellow); color: #263238; }
        button.danger { background: var(--red); }
        button.ghost { background: #edf2f7; color: #263238; }

        button.active {
            outline: 2px solid rgba(255,255,255,.45);
        }

        .header-inner { display: grid; gap: 8px; }

        .header-title-row,
        .header-member-row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .header-title-row { justify-content: space-between; }

        .top-actions {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }

        .member-manage-box {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,.1);
            padding: 6px;
            border-radius: 10px;
            flex-wrap: wrap;
        }

        .member-manage-box input {
            width: auto;
            min-width: 150px;
        }

        .member-list {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }

        .member-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.22);
            color: #fff;
            border-radius: 999px;
            padding: 4px 6px 4px 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .member-pill button {
            padding: 1px 6px;
            border-radius: 999px;
            background: #ef5350;
            font-size: 11px;
        }

        .hint {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        header .hint { color: #d8dee9; }

        .panel {
            background: var(--panel);
            border-radius: 12px;
            padding: 10px;
            box-shadow: var(--shadow);
        }

        #boardView {
            overflow-x: hidden;
        }

        .board-scale-wrap {
            width: 100%;
            overflow: hidden;
        }

        .board-by-owner {
            display: grid;
            gap: 8px;
            transform-origin: top left;
            will-change: transform;
        }

        .owner-lane {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #f8fafc;
            padding: 7px;
        }

        .owner-lane-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
            gap: 6px;
            flex-wrap: wrap;
        }

        .owner-lane-title strong { font-size: 14px; }

        .status-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
            gap: 7px;
        }

        .column {
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 10px;
            min-height: 92px;
            padding: 6px;
            display: flex;
            flex-direction: column;
        }

        .column-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
            font-weight: 900;
            font-size: 12px;
        }

        .dropzone {
            min-height: 55px;
            height: 100%;
            flex: 1;
            border-radius: 8px;
            padding: 2px;
            transition: .15s;
        }

        .dropzone:hover { background: #f8fbff; }

        .dropzone.drag-over {
            outline: 2px dashed var(--blue);
            background: #e7f2ff;
        }

        .note {
            position: relative;
            border-radius: 8px;
            padding: 6px 7px;
            margin-bottom: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,.12);
            cursor: grab;
            min-height: 46px;
            border: 1px solid rgba(0,0,0,.05);
        }

        .note.unassigned { background: var(--status-unassigned); }
        .note.doing { background: var(--status-doing); }
        .note.hold { background: var(--status-hold); }
        .note.done { background: var(--status-done); }

        .note.overdue {
            background: var(--status-overdue);
            border: 1px solid #e53935;
        }

        .note-title {
            font-size: 13px;
            font-weight: 900;
            line-height: 1.25;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .note-meta {
            font-size: 10px;
            color: #475467;
            line-height: 1.3;
        }

        .note-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 4px;
        }

        .small {
            font-size: 10px;
            padding: 3px 6px;
            border-radius: 6px;
        }

        .chip {
            display: inline-flex;
            padding: 2px 6px;
            border-radius: 999px;
            background: rgba(255,255,255,.75);
            font-size: 10px;
            color: #334155;
            font-weight: 700;
        }

        .danger-text {
            color: #c62828;
            font-weight: 900;
        }

        .context-menu {
            position: fixed;
            display: none;
            z-index: 10001;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 10px;
            box-shadow: 0 12px 30px rgba(0,0,0,.18);
            overflow: hidden;
            min-width: 150px;
        }

        .context-menu button {
            display: block;
            width: 100%;
            background: #fff;
            color: #263238;
            border-radius: 0;
            text-align: left;
            padding: 9px 11px;
        }

        .context-menu button:hover { background: #f1f5f9; }
        .context-menu button.danger-item { color: #c62828; }

        .calendar-head,
        .week-head {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }

        .calendar-nav,
        .week-nav,
        .calendar-mode-nav {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }

        .calendar-title,
        .week-title {
            font-size: 16px;
            font-weight: 900;
            min-width: 180px;
            text-align: center;
        }

        .calendar {
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }

        .week-row,
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }

        .week-cell {
            background: #e9eef5;
            padding: 7px;
            text-align: center;
            font-weight: 900;
            border-right: 1px solid var(--line);
        }

        .week-cell.sun {
            background: var(--sunday-bg);
            color: #c62828;
        }

        .week-cell.sat {
            background: var(--saturday-bg);
            color: #1565c0;
        }

        .week-cell:last-child { border-right: 0; }

        .day-cell {
            min-height: 105px;
            border-right: 1px solid var(--line);
            border-top: 1px solid var(--line);
            padding: 5px;
            background: #fff;
        }

        .day-cell:nth-child(7n) { border-right: 0; }

        .day-cell.other {
            background: #f7f8fa;
            color: #98a2b3;
        }

        .day-cell.sun,
        .day-cell.holiday { background: var(--sunday-bg); }

        .day-cell.sat { background: var(--saturday-bg); }

        .day-cell.today {
            background: var(--today-bg) !important;
            outline: 3px solid var(--today-border);
            outline-offset: -3px;
        }

        .day-number {
            font-weight: 900;
            margin-bottom: 4px;
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: wrap;
        }

        .today-label {
            display: inline-flex;
            padding: 1px 6px;
            border-radius: 999px;
            background: var(--today-border);
            color: #fff;
            font-size: 10px;
            font-weight: 900;
        }

        .holiday-name {
            color: #c62828;
            font-size: 10px;
            font-weight: 800;
        }

        .cal-note,
        .week-note {
            padding: 4px 5px;
            border-radius: 7px;
            margin-bottom: 3px;
            font-size: 11px;
            cursor: pointer;
            border-left: 3px solid var(--blue);
        }

        .cal-note.unassigned,
        .week-note.unassigned {
            background: var(--status-unassigned);
            border-left-color: #f9a825;
        }

        .cal-note.doing,
        .week-note.doing {
            background: var(--status-doing);
            border-left-color: var(--blue);
        }

        .cal-note.hold,
        .week-note.hold {
            background: var(--status-hold);
            border-left-color: #ef8f00;
        }

        .cal-note.done,
        .week-note.done {
            background: var(--status-done);
            border-left-color: var(--green);
        }

        .weekly-board {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
        }

        .weekly-grid {
            min-width: 980px;
            display: grid;
            grid-template-columns: 130px repeat(7, minmax(120px, 1fr));
        }

        .weekly-header-cell,
        .weekly-owner-cell,
        .weekly-day-cell {
            border-right: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
            padding: 6px;
        }

        .weekly-header-cell {
            background: #e9eef5;
            font-weight: 900;
            text-align: center;
        }

        .weekly-header-cell.sun {
            background: var(--sunday-bg);
            color: #c62828;
        }

        .weekly-header-cell.sat {
            background: var(--saturday-bg);
            color: #1565c0;
        }

        .weekly-header-cell.today {
            background: var(--today-bg) !important;
            color: #92400e;
            outline: 3px solid var(--today-border);
            outline-offset: -3px;
        }

        .weekly-owner-cell {
            background: #f8fafc;
            font-weight: 900;
        }

        .weekly-day-cell {
            min-height: 95px;
            background: #fff;
        }

        .weekly-day-cell.sun,
        .weekly-day-cell.holiday { background: var(--sunday-bg); }

        .weekly-day-cell.sat { background: var(--saturday-bg); }

        .weekly-day-cell.today {
            background: var(--today-bg) !important;
            outline: 3px solid var(--today-border);
            outline-offset: -3px;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            z-index: 9999;
        }

        .modal-backdrop.show { display: flex; }

        .modal {
            width: min(900px, 96vw);
            max-height: 90vh;
            overflow: auto;
            background: #fff;
            border-radius: 16px;
            padding: 14px;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            margin-bottom: 10px;
        }

        .modal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 150px 140px 140px;
            gap: 8px;
            align-items: end;
        }

        .form-grid label {
            font-size: 11px;
            color: var(--muted);
            font-weight: 700;
        }

        .box {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px;
            background: #fbfcfe;
        }

        .history-list,
        .subtask-list,
        .relation-list,
        .template-list {
            display: grid;
            gap: 6px;
        }

        .list-item {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 7px;
            font-size: 12px;
        }

        .subtask-item {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
        }

        .subtask-item.done span {
            text-decoration: line-through;
            color: var(--muted);
        }

        .subtask-add,
        .relation-add {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 7px;
            margin-top: 7px;
        }

        .template-edit-grid {
            display: grid;
            grid-template-columns: 160px 1fr auto auto;
            gap: 6px;
            align-items: center;
        }

        .toast {
            position: fixed;
            right: 14px;
            bottom: 14px;
            background: #263238;
            color: #fff;
            padding: 10px 12px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            opacity: 0;
            transform: translateY(10px);
            transition: .2s;
            pointer-events: none;
            z-index: 10000;
        }

        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .hidden { display: none !important; }

        @media (max-width: 1200px) {
            .modal-grid { grid-template-columns: 1fr; }
            .status-row { grid-template-columns: repeat(4, minmax(150px, 1fr)); }
        }

        @media (max-width: 760px) {
            .status-row { grid-template-columns: repeat(4, minmax(150px, 1fr)); }

            .form-grid,
            .template-edit-grid {
                grid-template-columns: 1fr;
            }

            .member-manage-box input {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="header-inner">
        <div class="header-title-row">
            <div>
                <h1>お仕事管理 付箋ボード</h1>
                <div class="hint">行・状態欄、カレンダー日付、週表示の日付欄を右クリックして付箋を貼れます。</div>
            </div>

            <div class="top-actions">
                <button type="button" id="boardViewBtn" class="active">タイル表示</button>
                <button type="button" id="calendarViewBtn" class="secondary">カレンダー表示</button>
                <button type="button" class="secondary" id="templateSettingBtn">テンプレート設定</button>
                <button type="button" class="warning" id="resetBtn">テストデータ再生成</button>
            </div>
        </div>

        <div class="header-member-row hidden">
            <div class="member-manage-box">
                <strong>担当行</strong>
                <input type="text" id="newMemberName" placeholder="担当名を入力">
                <button type="button" class="success" id="addMemberBtn">担当追加</button>
            </div>

            <div class="member-list" id="memberList"></div>
        </div>
    </div>
</header>

<main>
    <section id="boardView" class="panel" style="margin-top:10px;">
        <h2>担当行別タイル表示</h2>
        <div class="board-scale-wrap" id="boardScaleWrap">
            <div class="board-by-owner" id="board"></div>
        </div>
    </section>

    <section id="calendarView" class="panel hidden" style="margin-top:10px;">
        <div class="calendar-head">
            <h2>カレンダー</h2>
            <div class="calendar-mode-nav">
                <button type="button" class="ghost" id="calendarMonthModeBtn">月表示</button>
                <button type="button" class="secondary" id="calendarWeekModeBtn">週表示</button>
            </div>
        </div>

        <div id="monthCalendarArea">
            <div class="calendar-head">
                <div class="hint">日付セルを右クリックすると、その日付を期限にした付箋を作成できます。当日は黄色で表示されます。</div>

                <div class="calendar-nav">
                    <button type="button" class="secondary" id="prevMonthBtn">前月</button>
                    <div class="calendar-title" id="calendarTitle"></div>
                    <button type="button" class="secondary" id="nextMonthBtn">次月</button>
                    <button type="button" class="ghost" id="todayBtn">今月</button>
                </div>
            </div>

            <div class="calendar">
                <div class="week-row">
                    <div class="week-cell sun">日</div>
                    <div class="week-cell">月</div>
                    <div class="week-cell">火</div>
                    <div class="week-cell">水</div>
                    <div class="week-cell">木</div>
                    <div class="week-cell">金</div>
                    <div class="week-cell sat">土</div>
                </div>
                <div class="calendar-grid" id="calendarGrid"></div>
            </div>
        </div>

        <div id="weekCalendarArea" class="hidden">
            <div class="week-head">
                <div class="hint">初期表示は今日から7日間です。前日・翌日ボタンで1日ずつ表示範囲がシフトします。</div>

                <div class="week-nav">
                    <button type="button" class="secondary" id="prevDayBtn">前日</button>
                    <div class="week-title" id="weekTitle"></div>
                    <button type="button" class="secondary" id="nextDayBtn">翌日</button>
                    <button type="button" class="ghost" id="todayWeekBtn">今日開始</button>
                </div>
            </div>

            <div class="weekly-board">
                <div class="weekly-grid" id="weeklyGrid"></div>
            </div>
        </div>
    </section>
</main>

<div class="modal-backdrop" id="modalBackdrop">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitle">詳細</h2>
            <button type="button" class="secondary" id="closeModalBtn">閉じる</button>
        </div>

        <div id="modalBody"></div>
    </div>
</div>

<div class="context-menu" id="noteContextMenu">
    <button type="button" id="contextDetailBtn">詳細を開く</button>
    <button type="button" class="danger-item" id="contextDeleteBtn">削除</button>
</div>

<div class="context-menu" id="createContextMenu">
    <button type="button" id="contextCreateBtn">ここに付箋を貼る</button>
</div>

<div class="toast" id="toast"></div>

<script>
    const STORAGE_KEY = 'oshigoto_fusen_board_v9_week_template_setting';
    const MODERATOR_ID = 'moderator';

    const statuses = {
        unassigned: '未対応',
        doing: '対応中',
        hold: '保留',
        done: '完了'
    };

    const defaultTemplates = [
        { id: 'none', label: 'テンプレートなし', first_name: '', memo: '' },
        { id: 'estimate', label: '見積作成', first_name: '見積作成', memo: '見積内容を確認し、金額と納期を整理する。' },
        { id: 'call', label: '電話連絡', first_name: '電話連絡', memo: '先方へ電話連絡し、要件を確認する。' },
        { id: 'mail', label: 'メール返信', first_name: 'メール返信', memo: '受信内容を確認し、必要事項を返信する。' },
        { id: 'schedule', label: '日程調整', first_name: '日程調整', memo: '候補日を確認し、日程を確定する。' },
        { id: 'invoice', label: '請求確認', first_name: '請求確認', memo: '請求内容・入金予定・処理状況を確認する。' },
        { id: 'delivery', label: '納品確認', first_name: '納品確認', memo: '納品物の内容と完了条件を確認する。' }
    ];

    let appData = loadData();
    let draggedId = null;
    let currentMonth = new Date();

    /*
     * 改良点：
     * 週表示は「日曜始まり」ではなく、currentWeekBaseDate を開始日として
     * そこから7日間を表示する。
     * 初期値は当日。
     */
    let currentWeekBaseDate = new Date();

    let calendarMode = 'month';
    let openedNoteId = null;
    let holidayMap = {};
    let contextNoteId = null;
    let createContext = null;

    const board = document.getElementById('board');
    const toast = document.getElementById('toast');
    const memberList = document.getElementById('memberList');
    const modalBackdrop = document.getElementById('modalBackdrop');
    const modalTitle = document.getElementById('modalTitle');
    const modalBody = document.getElementById('modalBody');
    const noteContextMenu = document.getElementById('noteContextMenu');
    const createContextMenu = document.getElementById('createContextMenu');

    function defaultMembers() {
        return [
            { id: 'tanaka', name: '田中' },
            { id: 'sato', name: '佐藤' },
            { id: 'suzuki', name: '鈴木' },
            { id: 'kato', name: '加藤' },
            { id: 'yamada', name: '山田' }
        ];
    }

    function seedData() {
        const today = new Date();

        return {
            next_id: 11,
            next_member_id: 6,
            next_template_id: 1,
            members: defaultMembers(),
            templates: JSON.parse(JSON.stringify(defaultTemplates)),
            notes: [
                makeSeedNote(1, '見積作成', 'tanaka', 'doing', addDays(today, 2), '初回連絡済み。資料送付待ち。'),
                makeSeedNote(2, '契約確認', 'sato', 'done', addDays(today, 1), '対応完了。'),
                makeSeedNote(3, '新規相談', MODERATOR_ID, 'unassigned', addDays(today, 0), '未対応の新規案件。'),
                makeSeedNote(4, '請求確認', 'kato', 'hold', addDays(today, -1), '先方確認待ち。期限超過。'),
                makeSeedNote(5, '資料修正', 'suzuki', 'doing', addDays(today, 5), '資料を作成中。'),
                makeSeedNote(6, '入金確認', 'tanaka', 'done', addDays(today, 0), '本日完了。'),
                makeSeedNote(7, '担当決定', MODERATOR_ID, 'unassigned', addDays(today, 3), '担当者を決める。'),
                makeSeedNote(8, '再連絡', 'sato', 'doing', addDays(today, -2), '期限超過の対応中。'),
                makeSeedNote(9, '日程調整', 'yamada', 'doing', addDays(today, 4), '面談候補日確認。'),
                makeSeedNote(10, '納品確認', 'yamada', 'hold', addDays(today, 6), '納品待ち。')
            ]
        };
    }

    function makeSeedNote(id, title, owner, status, deadline, memo) {
        return {
            id,
            first_name: title,
            owner,
            status,
            deadline,
            memo,
            subtasks: [],
            relations: [],
            history: [historyRow('作成', '', statuses[status], MODERATOR_ID, owner)],
            created_at: nowText()
        };
    }

    function loadData() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);

            if (!raw) {
                const data = seedData();
                localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
                return data;
            }

            const data = JSON.parse(raw);

            if (!data || !Array.isArray(data.notes)) {
                const seeded = seedData();
                localStorage.setItem(STORAGE_KEY, JSON.stringify(seeded));
                return seeded;
            }

            if (!Array.isArray(data.members)) data.members = defaultMembers();
            if (!Array.isArray(data.templates)) data.templates = JSON.parse(JSON.stringify(defaultTemplates));

            data.next_member_id = data.next_member_id || 100;
            data.next_template_id = data.next_template_id || 100;
            data.next_id = data.next_id || Math.max(0, ...data.notes.map(note => note.id || 0)) + 1;

            data.notes.forEach(note => normalizeNote(note));
            cleanupOwners(data);

            return data;
        } catch (e) {
            const seeded = seedData();
            localStorage.setItem(STORAGE_KEY, JSON.stringify(seeded));
            return seeded;
        }
    }

    function cleanupOwners(data) {
        const ownerIds = [MODERATOR_ID, ...data.members.map(member => member.id)];

        data.notes.forEach(note => {
            if (!ownerIds.includes(note.owner)) {
                note.owner = MODERATOR_ID;
            }
        });
    }

    function saveData() {
        cleanupOwners(appData);
        localStorage.setItem(STORAGE_KEY, JSON.stringify(appData));
    }

    function normalizeNote(note) {
        note.id = Number(note.id || 0);
        note.first_name = String(note.first_name || '');
        note.owner = note.owner || MODERATOR_ID;
        note.status = note.status || 'unassigned';
        note.deadline = note.deadline || '';
        note.memo = note.memo || '';
        note.created_at = note.created_at || nowText();
        note.history = Array.isArray(note.history) ? note.history : [];
        note.subtasks = Array.isArray(note.subtasks) ? note.subtasks : [];
        note.relations = Array.isArray(note.relations) ? note.relations : [];
        return note;
    }

    function nowText() {
        const d = new Date();
        const pad = n => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    function toYmd(date) {
        const d = new Date(date);
        const pad = n => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }

    function todayYmd() {
        return toYmd(new Date());
    }

    function addDays(date, days) {
        const d = new Date(date);
        d.setDate(d.getDate() + days);
        return toYmd(d);
    }

    function historyRow(action, fromStatus, toStatus, fromOwner, toOwner) {
        return {
            action,
            from_status: fromStatus,
            to_status: toStatus,
            from_owner: fromOwner,
            to_owner: toOwner,
            at: nowText()
        };
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char]));
    }

    function showToast(message) {
        toast.textContent = message;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 1600);
    }

    function ownerRows() {
        return [
            { id: MODERATOR_ID, name: 'モデレータ' },
            ...appData.members
        ];
    }

    function ownerName(ownerId) {
        const owner = ownerRows().find(row => row.id === ownerId);
        return owner ? owner.name : 'モデレータ';
    }

    function ownerOptions(selected = '') {
        return ownerRows().map(owner => `
            <option value="${escapeHtml(owner.id)}" ${selected === owner.id ? 'selected' : ''}>
                ${escapeHtml(owner.name)}
            </option>
        `).join('');
    }

    function templateOptions(selected = 'estimate') {
        return appData.templates.map(template => `
            <option value="${escapeHtml(template.id)}" ${selected === template.id ? 'selected' : ''}>
                ${escapeHtml(template.label)}
            </option>
        `).join('');
    }

    function templateById(id) {
        return appData.templates.find(template => template.id === id) || appData.templates[0] || defaultTemplates[0];
    }

    function renderMembers() {
        memberList.innerHTML = appData.members.length
            ? appData.members.map(member => `
                <span class="member-pill">
                    ${escapeHtml(member.name)}
                    <button type="button" class="delete-member-btn" data-id="${escapeHtml(member.id)}">削除</button>
                </span>
            `).join('')
            : '<span class="hint">担当はいません。</span>';

        document.querySelectorAll('.delete-member-btn').forEach(btn => {
            btn.addEventListener('click', () => deleteMember(btn.dataset.id));
        });
    }

    function addMember() {
        const input = document.getElementById('newMemberName');
        const name = input.value.trim();

        if (!name) {
            showToast('担当名を入力してください');
            return;
        }

        if (appData.members.some(member => member.name === name)) {
            showToast('同じ名前の担当がいます');
            return;
        }

        const id = `member_${appData.next_member_id++}`;
        appData.members.push({ id, name });
        input.value = '';

        saveData();
        renderAll();
        showToast('担当行を追加しました');
    }

    function deleteMember(id) {
        const member = appData.members.find(m => m.id === id);
        if (!member) return;

        if (!confirm(`${member.name} を削除しますか？この担当の付箋はモデレータ行へ移動します。`)) return;

        appData.members = appData.members.filter(m => m.id !== id);

        appData.notes.forEach(note => {
            if (note.owner === id) {
                note.owner = MODERATOR_ID;
                note.history.push(historyRow('担当削除により移動', '', statuses[note.status], id, MODERATOR_ID));
            }
        });

        saveData();
        renderAll();
        showToast('担当行を削除しました');
    }

    function findNote(id) {
        return appData.notes.find(note => Number(note.id) === Number(id));
    }

    function noteIsOverdue(note) {
        if (!note.deadline || note.status === 'done') return false;
        return note.deadline < todayYmd();
    }

    function filteredNotes() {
        return appData.notes;
    }

    function noteHtml(note) {
        normalizeNote(note);

        const overdue = noteIsOverdue(note);

        return `
            <article class="note ${escapeHtml(note.status)} ${overdue ? 'overdue' : ''}" draggable="true" data-id="${note.id}" title="右クリックで削除 / 詳細を開く">
                <div class="note-title">${escapeHtml(note.first_name || '無題')}</div>
                <div class="note-meta">
                    期限：${escapeHtml(note.deadline || '未設定')}
                    ${overdue ? `<span class="danger-text"> / 期限超過</span>` : ''}
                </div>
                <div class="note-actions">
                    <button type="button" class="small detail-btn" data-id="${note.id}">詳細</button>
                </div>
            </article>
        `;
    }

    function renderBoard() {
        const notes = filteredNotes();
        const rows = ownerRows();

        board.innerHTML = rows.map(owner => {
            const ownerNotes = notes.filter(note => note.owner === owner.id);
            const total = ownerNotes.length;

            return `
                <section class="owner-lane">
                    <div class="owner-lane-title">
                        <strong>${escapeHtml(owner.name)} 行</strong>
                        <span class="chip">${total}件</span>
                    </div>

                    <div class="status-row">
                        ${Object.entries(statuses).map(([status, label]) => {
                            const list = ownerNotes.filter(note => note.status === status);

                            return `
                                <div class="column">
                                    <div class="column-title">
                                        <span>${escapeHtml(label)}</span>
                                        <span class="chip">${list.length}</span>
                                    </div>
                                    <div class="dropzone" data-status="${escapeHtml(status)}" data-owner="${escapeHtml(owner.id)}">
                                        ${list.length ? list.map(noteHtml).join('') : '<div class="hint">右クリックで付箋作成</div>'}
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                </section>
            `;
        }).join('');

        bindBoardEvents();
        requestAnimationFrame(scaleBoard);
    }

    function scaleBoard() {
        const wrap = document.getElementById('boardScaleWrap');
        const boardEl = document.getElementById('board');

        if (!wrap || !boardEl) return;

        boardEl.style.transform = 'none';
        boardEl.style.width = '100%';

        const wrapWidth = wrap.clientWidth;
        const minBoardWidth = 700;
        const baseWidth = Math.max(wrapWidth, minBoardWidth);
        const scale = wrapWidth < minBoardWidth ? wrapWidth / minBoardWidth : 1;

        boardEl.style.width = `${baseWidth}px`;
        boardEl.style.transform = `scale(${scale})`;

        const realHeight = boardEl.scrollHeight;
        wrap.style.height = `${realHeight * scale}px`;
    }

    function bindBoardEvents() {
        document.querySelectorAll('.note[draggable="true"]').forEach(noteEl => {
            noteEl.addEventListener('dragstart', event => {
                draggedId = noteEl.dataset.id;
                event.dataTransfer.setData('text/plain', draggedId);
            });

            noteEl.addEventListener('dragend', () => {
                draggedId = null;
                document.querySelectorAll('.dropzone').forEach(zone => zone.classList.remove('drag-over'));
            });

            noteEl.addEventListener('contextmenu', event => {
                event.preventDefault();
                event.stopPropagation();
                openNoteContextMenu(event.clientX, event.clientY, noteEl.dataset.id);
            });
        });

        document.querySelectorAll('.dropzone').forEach(zone => {
            zone.addEventListener('dragover', event => {
                event.preventDefault();
                zone.classList.add('drag-over');
            });

            zone.addEventListener('dragleave', () => {
                zone.classList.remove('drag-over');
            });

            zone.addEventListener('drop', event => {
                event.preventDefault();
                zone.classList.remove('drag-over');

                const id = event.dataTransfer.getData('text/plain') || draggedId;
                moveNote(id, zone.dataset.status, zone.dataset.owner);
            });

            zone.addEventListener('contextmenu', event => {
                event.preventDefault();
                event.stopPropagation();

                openCreateContextMenu(event.clientX, event.clientY, {
                    owner: zone.dataset.owner,
                    status: zone.dataset.status,
                    deadline: todayYmd()
                });
            });
        });

        document.querySelectorAll('.detail-btn').forEach(btn => {
            btn.addEventListener('click', event => {
                event.stopPropagation();
                openDetail(btn.dataset.id);
            });
        });
    }

    function moveNote(id, newStatus, newOwner) {
        const note = findNote(id);
        if (!note) return;

        const oldStatus = note.status;
        const oldOwner = note.owner;

        note.status = newStatus;
        note.owner = newOwner || note.owner;

        if (oldStatus === note.status && oldOwner === note.owner) return;

        note.history.push(historyRow(
            '移動',
            statuses[oldStatus],
            statuses[note.status],
            oldOwner,
            note.owner
        ));

        saveData();
        renderAll();
        showToast('移動しました');
    }

    function deleteNote(id) {
        if (!confirm('この付箋を削除しますか？')) return;

        appData.notes = appData.notes.filter(note => Number(note.id) !== Number(id));

        appData.notes.forEach(note => {
            note.relations = note.relations.filter(relId => Number(relId) !== Number(id));
        });

        saveData();
        renderAll();
        closeModal();
        closeAllMenus();
        showToast('削除しました');
    }

    async function loadHolidays() {
        try {
            const cacheKey = 'jp_holidays_cache_v1';
            const cached = localStorage.getItem(cacheKey);

            if (cached) {
                holidayMap = JSON.parse(cached);
                renderCalendar();
                renderWeek();
                return;
            }

            const url = 'https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv';
            const response = await fetch(url);

            if (!response.ok) throw new Error('holiday fetch failed');

            const buffer = await response.arrayBuffer();
            const text = new TextDecoder('shift_jis').decode(buffer);
            const lines = text.split(/\r?\n/).slice(1);
            const map = {};

            lines.forEach(line => {
                if (!line.trim()) return;

                const commaIndex = line.indexOf(',');
                if (commaIndex === -1) return;

                const dateText = line.slice(0, commaIndex).trim().replaceAll('/', '-');
                const name = line.slice(commaIndex + 1).trim();
                const parts = dateText.split('-').map(Number);

                if (parts.length !== 3) return;

                const ymd = `${parts[0]}-${String(parts[1]).padStart(2, '0')}-${String(parts[2]).padStart(2, '0')}`;
                map[ymd] = name;
            });

            holidayMap = map;
            localStorage.setItem(cacheKey, JSON.stringify(map));
            renderCalendar();
            renderWeek();
        } catch (e) {
            holidayMap = {};
        }
    }

    function renderCalendar() {
        const grid = document.getElementById('calendarGrid');
        const title = document.getElementById('calendarTitle');

        if (!grid || !title) return;

        const year = currentMonth.getFullYear();
        const month = currentMonth.getMonth();
        const today = todayYmd();

        title.textContent = `${year}年 ${month + 1}月`;

        const first = new Date(year, month, 1);
        const start = new Date(first);
        start.setDate(1 - first.getDay());

        const cells = [];

        for (let i = 0; i < 42; i++) {
            const d = new Date(start);
            d.setDate(start.getDate() + i);

            const ymd = toYmd(d);
            const other = d.getMonth() !== month;
            const day = d.getDay();
            const holidayName = holidayMap[ymd] || '';
            const dayNotes = filteredNotes().filter(note => note.deadline === ymd);
            const isToday = ymd === today;

            const classes = [
                'day-cell',
                other ? 'other' : '',
                day === 0 ? 'sun' : '',
                day === 6 ? 'sat' : '',
                holidayName ? 'holiday' : '',
                isToday ? 'today' : ''
            ].filter(Boolean).join(' ');

            cells.push(`
                <div class="${classes}" data-date="${ymd}">
                    <div class="day-number">
                        <span>${d.getDate()}</span>
                        ${isToday ? `<span class="today-label">今日</span>` : ''}
                        ${holidayName ? `<span class="holiday-name">${escapeHtml(holidayName)}</span>` : ''}
                    </div>
                    ${dayNotes.map(note => `
                        <div class="cal-note ${escapeHtml(note.status)}" data-id="${note.id}">
                            ${escapeHtml(ownerName(note.owner))}：${escapeHtml(note.first_name || '無題')}
                        </div>
                    `).join('')}
                </div>
            `);
        }

        grid.innerHTML = cells.join('');

        document.querySelectorAll('.cal-note').forEach(el => {
            el.addEventListener('click', event => {
                event.stopPropagation();
                openDetail(el.dataset.id);
            });

            el.addEventListener('contextmenu', event => {
                event.preventDefault();
                event.stopPropagation();
                openNoteContextMenu(event.clientX, event.clientY, el.dataset.id);
            });
        });

        document.querySelectorAll('.day-cell').forEach(cell => {
            cell.addEventListener('contextmenu', event => {
                event.preventDefault();
                event.stopPropagation();

                openCreateContextMenu(event.clientX, event.clientY, {
                    owner: MODERATOR_ID,
                    status: 'doing',
                    deadline: cell.dataset.date
                });
            });
        });
    }

    /*
     * 改良点：
     * 以前は「週の日曜始まり」でしたが、
     * 現在は baseDate から連続7日間を返します。
     * これにより初期表示が今日開始になり、
     * 前日・翌日で1日ずつ表示範囲がシフトします。
     */
    function getWeekDates(baseDate) {
        const start = new Date(baseDate);

        return Array.from({ length: 7 }).map((_, i) => {
            const d = new Date(start);
            d.setDate(start.getDate() + i);
            return d;
        });
    }

    function renderWeek() {
        const grid = document.getElementById('weeklyGrid');
        const title = document.getElementById('weekTitle');

        if (!grid || !title) return;

        const weekDates = getWeekDates(currentWeekBaseDate);
        const startYmd = toYmd(weekDates[0]);
        const endYmd = toYmd(weekDates[6]);
        const today = todayYmd();

        title.textContent = `${startYmd} 〜 ${endYmd}`;

        let html = `<div class="weekly-header-cell">担当</div>`;

        weekDates.forEach(d => {
            const ymd = toYmd(d);
            const day = d.getDay();
            const holidayName = holidayMap[ymd] || '';
            const isToday = ymd === today;

            const cls = [
                'weekly-header-cell',
                day === 0 ? 'sun' : '',
                day === 6 ? 'sat' : '',
                isToday ? 'today' : ''
            ].filter(Boolean).join(' ');

            html += `
                <div class="${cls}">
                    ${d.getMonth() + 1}/${d.getDate()}<br>
                    ${['日', '月', '火', '水', '木', '金', '土'][day]}
                    ${isToday ? `<div class="today-label" style="margin-top:3px;">今日</div>` : ''}
                    ${holidayName ? `<div class="holiday-name">${escapeHtml(holidayName)}</div>` : ''}
                </div>
            `;
        });

        ownerRows().forEach(owner => {
            html += `<div class="weekly-owner-cell">${escapeHtml(owner.name)}</div>`;

            weekDates.forEach(d => {
                const ymd = toYmd(d);
                const day = d.getDay();
                const holidayName = holidayMap[ymd] || '';
                const isToday = ymd === today;
                const notes = filteredNotes().filter(note => note.owner === owner.id && note.deadline === ymd);

                const cls = [
                    'weekly-day-cell',
                    day === 0 ? 'sun' : '',
                    day === 6 ? 'sat' : '',
                    holidayName ? 'holiday' : '',
                    isToday ? 'today' : ''
                ].filter(Boolean).join(' ');

                html += `
                    <div class="${cls}" data-owner="${escapeHtml(owner.id)}" data-date="${ymd}">
                        ${notes.length ? notes.map(note => `
                            <div class="week-note ${escapeHtml(note.status)}" data-id="${note.id}">
                                ${escapeHtml(note.first_name || '無題')}
                            </div>
                        `).join('') : '<div class="hint">右クリックで作成</div>'}
                    </div>
                `;
            });
        });

        grid.innerHTML = html;

        document.querySelectorAll('.week-note').forEach(el => {
            el.addEventListener('click', event => {
                event.stopPropagation();
                openDetail(el.dataset.id);
            });

            el.addEventListener('contextmenu', event => {
                event.preventDefault();
                event.stopPropagation();
                openNoteContextMenu(event.clientX, event.clientY, el.dataset.id);
            });
        });

        document.querySelectorAll('.weekly-day-cell').forEach(cell => {
            cell.addEventListener('contextmenu', event => {
                event.preventDefault();
                event.stopPropagation();

                openCreateContextMenu(event.clientX, event.clientY, {
                    owner: cell.dataset.owner,
                    status: 'doing',
                    deadline: cell.dataset.date
                });
            });
        });
    }

    function openAddModal(initial = {}) {
        const defaultTemplate = templateById(initial.template_id || 'estimate');

        const initialOwner = initial.owner || MODERATOR_ID;
        const initialStatus = initial.status || 'unassigned';
        const initialDeadline = initial.deadline || todayYmd();
        const initialFirstName = initial.first_name ?? defaultTemplate.first_name;
        const initialMemo = initial.memo ?? defaultTemplate.memo;

        modalTitle.textContent = '付箋を貼る';

        modalBody.innerHTML = `
            <form id="addForm">
                <div class="form-grid">
                    <div>
                        <label>テンプレート</label>
                        <select id="templateSelect" name="template_id">
                            ${templateOptions(defaultTemplate.id)}
                        </select>
                    </div>

                    <div>
                        <label>期限</label>
                        <input type="date" name="deadline" value="${escapeHtml(initialDeadline)}">
                    </div>

                    <div>
                        <label>担当行</label>
                        <select name="owner">
                            ${ownerOptions(initialOwner)}
                        </select>
                    </div>

                    <div>
                        <label>状態</label>
                        <select name="status">
                            ${Object.entries(statuses).map(([key, label]) => `
                                <option value="${key}" ${initialStatus === key ? 'selected' : ''}>${escapeHtml(label)}</option>
                            `).join('')}
                        </select>
                    </div>

                    <div style="grid-column: 1 / -1;">
                        <label>件名 first_name</label>
                        <input type="text" id="firstNameInput" name="first_name" value="${escapeHtml(initialFirstName)}" placeholder="未入力ならテンプレート名、または無題になります">
                    </div>

                    <div style="grid-column: 1 / -1;">
                        <label>メモ</label>
                        <textarea id="memoInput" name="memo" rows="4" placeholder="省略可">${escapeHtml(initialMemo)}</textarea>
                    </div>
                </div>

                <div class="hint" style="margin-top:8px;">
                    件名とメモは省略できます。テンプレート選択で自動入力されます。
                </div>

                <div style="margin-top:10px; display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" class="secondary" id="cancelAddBtn">キャンセル</button>
                    <button type="submit" class="success">貼る</button>
                </div>
            </form>
        `;

        modalBackdrop.classList.add('show');

        document.getElementById('cancelAddBtn').addEventListener('click', closeModal);

        document.getElementById('templateSelect').addEventListener('change', event => {
            const template = templateById(event.target.value);
            document.getElementById('firstNameInput').value = template.first_name;
            document.getElementById('memoInput').value = template.memo;
        });

        document.getElementById('addForm').addEventListener('submit', event => {
            event.preventDefault();

            const fd = new FormData(event.currentTarget);
            const template = templateById(String(fd.get('template_id') || 'none'));

            let firstName = String(fd.get('first_name') || '').trim();
            let memo = String(fd.get('memo') || '').trim();

            if (!firstName) firstName = template.first_name || template.label || '無題';
            if (!memo) memo = template.memo || '';

            const note = {
                id: appData.next_id++,
                first_name: firstName,
                owner: String(fd.get('owner') || MODERATOR_ID),
                status: String(fd.get('status') || 'unassigned'),
                deadline: String(fd.get('deadline') || ''),
                memo,
                subtasks: [],
                relations: [],
                history: [],
                created_at: nowText()
            };

            note.history.push(historyRow('作成', '', statuses[note.status], MODERATOR_ID, note.owner));

            appData.notes.push(note);
            saveData();
            renderAll();
            closeModal();
            closeAllMenus();
            showToast('付箋を貼りました');
        });
    }

    function openTemplateSetting() {
        modalTitle.textContent = 'テンプレート設定';

        modalBody.innerHTML = `
            <div class="box">
                <h3>テンプレート追加</h3>
                <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                    <div>
                        <label>表示名</label>
                        <input type="text" id="newTemplateLabel" placeholder="例：現地確認">
                    </div>
                    <div>
                        <label>件名</label>
                        <input type="text" id="newTemplateFirstName" placeholder="例：現地確認">
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label>メモ</label>
                        <textarea id="newTemplateMemo" rows="3" placeholder="テンプレートの初期メモ"></textarea>
                    </div>
                </div>
                <div style="margin-top:8px; display:flex; justify-content:flex-end;">
                    <button type="button" class="success" id="addTemplateBtn">追加</button>
                </div>
            </div>

            <div class="box" style="margin-top:10px;">
                <h3>テンプレート編集・削除</h3>
                <div class="template-list">
                    ${appData.templates.map(template => `
                        <div class="list-item">
                            <div class="template-edit-grid">
                                <input type="text" class="template-label-input" data-id="${escapeHtml(template.id)}" value="${escapeHtml(template.label)}" placeholder="表示名">
                                <input type="text" class="template-name-input" data-id="${escapeHtml(template.id)}" value="${escapeHtml(template.first_name)}" placeholder="件名">
                                <button type="button" class="small success save-template-btn" data-id="${escapeHtml(template.id)}">保存</button>
                                <button type="button" class="small danger delete-template-btn" data-id="${escapeHtml(template.id)}">削除</button>
                            </div>
                            <div style="margin-top:6px;">
                                <textarea class="template-memo-input" data-id="${escapeHtml(template.id)}" rows="3" placeholder="メモ">${escapeHtml(template.memo)}</textarea>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;

        modalBackdrop.classList.add('show');

        document.getElementById('addTemplateBtn').addEventListener('click', () => {
            const label = document.getElementById('newTemplateLabel').value.trim();
            const firstName = document.getElementById('newTemplateFirstName').value.trim();
            const memo = document.getElementById('newTemplateMemo').value.trim();

            if (!label) {
                showToast('表示名を入力してください');
                return;
            }

            appData.templates.push({
                id: `template_${appData.next_template_id++}`,
                label,
                first_name: firstName || label,
                memo
            });

            saveData();
            openTemplateSetting();
            showToast('テンプレートを追加しました');
        });

        document.querySelectorAll('.save-template-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                const template = appData.templates.find(t => t.id === id);
                if (!template) return;

                const labelInput = document.querySelector(`.template-label-input[data-id="${CSS.escape(id)}"]`);
                const nameInput = document.querySelector(`.template-name-input[data-id="${CSS.escape(id)}"]`);
                const memoInput = document.querySelector(`.template-memo-input[data-id="${CSS.escape(id)}"]`);

                template.label = labelInput.value.trim() || '無題テンプレート';
                template.first_name = nameInput.value.trim();
                template.memo = memoInput.value;

                saveData();
                openTemplateSetting();
                showToast('テンプレートを保存しました');
            });
        });

        document.querySelectorAll('.delete-template-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;

                if (appData.templates.length <= 1) {
                    showToast('テンプレートは最低1件必要です');
                    return;
                }

                if (!confirm('このテンプレートを削除しますか？')) return;

                appData.templates = appData.templates.filter(t => t.id !== id);

                saveData();
                openTemplateSetting();
                showToast('テンプレートを削除しました');
            });
        });
    }

    function openDetail(id) {
        const note = findNote(id);
        if (!note) return;

        openedNoteId = Number(id);
        modalTitle.textContent = `${note.first_name || '無題'} の詳細`;

        const relationOptions = appData.notes
            .filter(n => n.id !== note.id && !note.relations.includes(n.id))
            .map(n => `<option value="${n.id}">${escapeHtml(n.first_name || '無題')} / ${escapeHtml(statuses[n.status])}</option>`)
            .join('');

        const historyHtml = note.history.length
            ? note.history.slice().reverse().map(h => `
                <div class="list-item">
                    <strong>${escapeHtml(h.action || '操作')}</strong><br>
                    ${escapeHtml(h.from_status || '')} → ${escapeHtml(h.to_status || '')}<br>
                    担当行：${escapeHtml(ownerName(h.from_owner))} → ${escapeHtml(ownerName(h.to_owner))}<br>
                    ${escapeHtml(h.at || '')}
                </div>
            `).join('')
            : '<div class="hint">履歴はありません。</div>';

        const subtasksHtml = note.subtasks.length
            ? note.subtasks.map(task => `
                <div class="list-item subtask-item ${task.done ? 'done' : ''}">
                    <span>${escapeHtml(task.title)}</span>
                    <button type="button" class="small ${task.done ? 'secondary' : 'success'} toggle-subtask-btn" data-task-id="${task.id}">
                        ${task.done ? '未完了へ' : '完了'}
                    </button>
                </div>
            `).join('')
            : '<div class="hint">サブタスクはありません。</div>';

        const relationsHtml = note.relations.length
            ? note.relations.map(id => findNote(id)).filter(Boolean).map(rel => `
                <div class="list-item">
                    <strong>${escapeHtml(rel.first_name || '無題')}</strong>
                    <div class="hint">${escapeHtml(statuses[rel.status])} / 担当行 ${escapeHtml(ownerName(rel.owner))}</div>
                    <button type="button" class="small secondary open-related-btn" data-id="${rel.id}">開く</button>
                    <button type="button" class="small danger remove-relation-btn" data-id="${rel.id}">関連解除</button>
                </div>
            `).join('')
            : '<div class="hint">関連付箋はありません。</div>';

        modalBody.innerHTML = `
            <div class="modal-grid">
                <div class="box">
                    <h3>基本情報</h3>

                    <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                        <div>
                            <label>件名</label>
                            <input type="text" id="editName" value="${escapeHtml(note.first_name || '')}">
                        </div>

                        <div>
                            <label>期限</label>
                            <input type="date" id="editDeadline" value="${escapeHtml(note.deadline)}">
                        </div>

                        <div>
                            <label>担当行</label>
                            <select id="editOwner">
                                ${ownerOptions(note.owner)}
                            </select>
                        </div>

                        <div>
                            <label>状態</label>
                            <select id="editStatus">
                                ${Object.entries(statuses).map(([key, label]) => `
                                    <option value="${key}" ${note.status === key ? 'selected' : ''}>${escapeHtml(label)}</option>
                                `).join('')}
                            </select>
                        </div>

                        <div style="grid-column:1 / -1;">
                            <label>メモ</label>
                            <textarea id="editMemo" rows="4">${escapeHtml(note.memo)}</textarea>
                        </div>
                    </div>

                    <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="button" class="success" id="saveDetailBtn">基本情報を保存</button>
                        <button type="button" class="danger" id="modalDeleteBtn">削除</button>
                    </div>
                </div>

                <div class="box">
                    <h3>履歴</h3>
                    <div class="history-list">${historyHtml}</div>
                </div>

                <div class="box">
                    <h3>サブタスク</h3>
                    <div class="subtask-list">${subtasksHtml}</div>

                    <div class="subtask-add">
                        <input type="text" id="newSubtaskTitle" placeholder="サブタスク名">
                        <button type="button" id="addSubtaskBtn">追加</button>
                    </div>
                </div>

                <div class="box">
                    <h3>関連付箋</h3>
                    <div class="relation-list">${relationsHtml}</div>

                    <div class="relation-add">
                        <select id="relationSelect">
                            <option value="">関連付箋を選択</option>
                            ${relationOptions}
                        </select>
                        <button type="button" id="addRelationBtn">関連付け</button>
                    </div>
                </div>
            </div>
        `;

        modalBackdrop.classList.add('show');
        bindModalEvents();
    }

    function bindModalEvents() {
        const note = findNote(openedNoteId);
        if (!note) return;

        document.getElementById('saveDetailBtn').addEventListener('click', () => {
            const oldStatus = note.status;
            const oldOwner = note.owner;

            note.first_name = document.getElementById('editName').value.trim() || '無題';
            note.deadline = document.getElementById('editDeadline').value;
            note.owner = document.getElementById('editOwner').value;
            note.status = document.getElementById('editStatus').value;
            note.memo = document.getElementById('editMemo').value;

            note.history.push({
                action: '編集',
                from_status: statuses[oldStatus],
                to_status: statuses[note.status],
                from_owner: oldOwner,
                to_owner: note.owner,
                at: nowText()
            });

            saveData();
            renderAll();
            openDetail(note.id);
            showToast('保存しました');
        });

        document.getElementById('modalDeleteBtn').addEventListener('click', () => deleteNote(note.id));

        document.querySelectorAll('.toggle-subtask-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const task = note.subtasks.find(t => Number(t.id) === Number(btn.dataset.taskId));
                if (!task) return;

                task.done = !task.done;

                note.history.push({
                    action: 'サブタスク更新',
                    from_status: task.done ? '未完了' : '完了',
                    to_status: task.done ? '完了' : '未完了',
                    from_owner: note.owner,
                    to_owner: note.owner,
                    at: nowText()
                });

                saveData();
                renderAll();
                openDetail(note.id);
            });
        });

        document.getElementById('addSubtaskBtn').addEventListener('click', () => {
            const input = document.getElementById('newSubtaskTitle');
            const title = input.value.trim();
            if (!title) return;

            const nextId = Math.max(0, ...note.subtasks.map(t => Number(t.id || 0))) + 1;

            note.subtasks.push({ id: nextId, title, done: false });

            note.history.push({
                action: 'サブタスク追加',
                from_status: '',
                to_status: title,
                from_owner: note.owner,
                to_owner: note.owner,
                at: nowText()
            });

            saveData();
            renderAll();
            openDetail(note.id);
        });

        document.getElementById('addRelationBtn').addEventListener('click', () => {
            const select = document.getElementById('relationSelect');
            const relId = Number(select.value);
            if (!relId) return;

            const relNote = findNote(relId);
            if (!relNote) return;

            if (!note.relations.includes(relId)) note.relations.push(relId);
            if (!relNote.relations.includes(note.id)) relNote.relations.push(note.id);

            note.history.push({
                action: '関連付箋追加',
                from_status: '',
                to_status: relNote.first_name || '無題',
                from_owner: note.owner,
                to_owner: note.owner,
                at: nowText()
            });

            saveData();
            renderAll();
            openDetail(note.id);
        });

        document.querySelectorAll('.remove-relation-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const relId = Number(btn.dataset.id);
                const relNote = findNote(relId);

                note.relations = note.relations.filter(id => Number(id) !== relId);

                if (relNote) {
                    relNote.relations = relNote.relations.filter(id => Number(id) !== Number(note.id));
                }

                note.history.push({
                    action: '関連付箋解除',
                    from_status: relNote ? relNote.first_name || '無題' : '',
                    to_status: '',
                    from_owner: note.owner,
                    to_owner: note.owner,
                    at: nowText()
                });

                saveData();
                renderAll();
                openDetail(note.id);
            });
        });

        document.querySelectorAll('.open-related-btn').forEach(btn => {
            btn.addEventListener('click', () => openDetail(btn.dataset.id));
        });
    }

    function openNoteContextMenu(x, y, noteId) {
        closeCreateContextMenu();
        contextNoteId = Number(noteId);

        noteContextMenu.style.left = `${x}px`;
        noteContextMenu.style.top = `${y}px`;
        noteContextMenu.style.display = 'block';

        fitMenu(noteContextMenu);
    }

    function closeNoteContextMenu() {
        noteContextMenu.style.display = 'none';
        contextNoteId = null;
    }

    function openCreateContextMenu(x, y, context) {
        closeNoteContextMenu();
        createContext = context;

        createContextMenu.style.left = `${x}px`;
        createContextMenu.style.top = `${y}px`;
        createContextMenu.style.display = 'block';

        fitMenu(createContextMenu);
    }

    function closeCreateContextMenu() {
        createContextMenu.style.display = 'none';
        createContext = null;
    }

    function closeAllMenus() {
        closeNoteContextMenu();
        closeCreateContextMenu();
    }

    function fitMenu(menu) {
        const rect = menu.getBoundingClientRect();

        if (rect.right > window.innerWidth) {
            menu.style.left = `${window.innerWidth - rect.width - 8}px`;
        }

        if (rect.bottom > window.innerHeight) {
            menu.style.top = `${window.innerHeight - rect.height - 8}px`;
        }
    }

    function closeModal() {
        modalBackdrop.classList.remove('show');
        openedNoteId = null;
    }

    function switchCalendarMode(mode) {
        calendarMode = mode;

        document.getElementById('monthCalendarArea').classList.toggle('hidden', mode !== 'month');
        document.getElementById('weekCalendarArea').classList.toggle('hidden', mode !== 'week');

        document.getElementById('calendarMonthModeBtn').classList.toggle('ghost', mode === 'month');
        document.getElementById('calendarMonthModeBtn').classList.toggle('secondary', mode !== 'month');

        document.getElementById('calendarWeekModeBtn').classList.toggle('ghost', mode === 'week');
        document.getElementById('calendarWeekModeBtn').classList.toggle('secondary', mode !== 'week');

        if (mode === 'month') renderCalendar();
        if (mode === 'week') renderWeek();
    }

    function switchView(viewName) {
        document.getElementById('boardView').classList.toggle('hidden', viewName !== 'board');
        document.getElementById('calendarView').classList.toggle('hidden', viewName !== 'calendar');

        document.getElementById('boardViewBtn').classList.toggle('active', viewName === 'board');
        document.getElementById('calendarViewBtn').classList.toggle('active', viewName === 'calendar');

        if (viewName === 'board') requestAnimationFrame(scaleBoard);
        if (viewName === 'calendar') switchCalendarMode(calendarMode);
    }

    function renderAll() {
        renderMembers();
        renderBoard();
        renderCalendar();
        renderWeek();
        requestAnimationFrame(scaleBoard);
    }

    document.getElementById('addMemberBtn').addEventListener('click', addMember);

    document.getElementById('newMemberName').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            addMember();
        }
    });

    document.getElementById('boardViewBtn').addEventListener('click', () => switchView('board'));
    document.getElementById('calendarViewBtn').addEventListener('click', () => switchView('calendar'));

    document.getElementById('calendarMonthModeBtn').addEventListener('click', () => switchCalendarMode('month'));
    document.getElementById('calendarWeekModeBtn').addEventListener('click', () => switchCalendarMode('week'));

    document.getElementById('templateSettingBtn').addEventListener('click', openTemplateSetting);

    document.getElementById('prevMonthBtn').addEventListener('click', () => {
        currentMonth.setMonth(currentMonth.getMonth() - 1);
        renderCalendar();
    });

    document.getElementById('nextMonthBtn').addEventListener('click', () => {
        currentMonth.setMonth(currentMonth.getMonth() + 1);
        renderCalendar();
    });

    document.getElementById('todayBtn').addEventListener('click', () => {
        currentMonth = new Date();
        renderCalendar();
    });

    /*
     * 改良点：
     * 前日・翌日は currentWeekBaseDate を1日単位で移動。
     * 表示も1日ずつシフトします。
     */
    document.getElementById('prevDayBtn').addEventListener('click', () => {
        currentWeekBaseDate.setDate(currentWeekBaseDate.getDate() - 1);
        renderWeek();
    });

    document.getElementById('nextDayBtn').addEventListener('click', () => {
        currentWeekBaseDate.setDate(currentWeekBaseDate.getDate() + 1);
        renderWeek();
    });

    /*
     * 今日開始に戻します。
     */
    document.getElementById('todayWeekBtn').addEventListener('click', () => {
        currentWeekBaseDate = new Date();
        renderWeek();
    });

    document.getElementById('resetBtn').addEventListener('click', () => {
        if (!confirm('テストデータを再生成しますか？現在のデータは消えます。')) return;

        appData = seedData();
        saveData();

        currentMonth = new Date();
        currentWeekBaseDate = new Date();

        renderAll();
        closeModal();
        closeAllMenus();
        showToast('再生成しました');
    });

    document.getElementById('closeModalBtn').addEventListener('click', closeModal);

    modalBackdrop.addEventListener('click', event => {
        if (event.target === modalBackdrop) closeModal();
    });

    document.addEventListener('click', event => {
        if (!noteContextMenu.contains(event.target) && !createContextMenu.contains(event.target)) {
            closeAllMenus();
        }
    });

    window.addEventListener('scroll', closeAllMenus);
    window.addEventListener('resize', () => {
        closeAllMenus();
        scaleBoard();
    });

    document.getElementById('contextDetailBtn').addEventListener('click', () => {
        if (contextNoteId) {
            openDetail(contextNoteId);
            closeAllMenus();
        }
    });

    document.getElementById('contextDeleteBtn').addEventListener('click', () => {
        if (contextNoteId) {
            deleteNote(contextNoteId);
        }
    });

    document.getElementById('contextCreateBtn').addEventListener('click', () => {
        if (createContext) {
            openAddModal(createContext);
            closeCreateContextMenu();
        }
    });

    renderAll();
    loadHolidays();
</script>

</body>
</html>
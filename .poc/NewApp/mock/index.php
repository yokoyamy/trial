<script>
'use strict';

/* =========================================================
   Storage
========================================================= */

const STORAGE_KEY = 'questionnaire_mock_v2';

/*
 * localStorage が利用できない sandbox iframe 等でも
 * モック自体は動作させるためのフォールバック。
 */
let storageAvailable = false;
let memoryStorage = null;

function initStorage() {
    try {
        const testKey = '__questionnaire_mock_test__';

        window.localStorage.setItem(testKey, '1');
        window.localStorage.removeItem(testKey);

        storageAvailable = true;
        return true;
    } catch (e) {
        storageAvailable = false;

        console.warn(
            'localStorage は利用できません。メモリ上でモック状態を保持します。',
            e
        );

        return false;
    }
}

function readStorage() {
    if (storageAvailable) {
        try {
            return window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            console.warn(
                'localStorage の読み込みに失敗しました。メモリストレージへ切り替えます。',
                e
            );

            storageAvailable = false;
        }
    }

    return memoryStorage;
}

function writeStorage(value) {
    if (storageAvailable) {
        try {
            window.localStorage.setItem(
                STORAGE_KEY,
                value
            );

            memoryStorage = value;
            return true;

        } catch (e) {
            console.warn(
                'localStorage の保存に失敗しました。メモリストレージへ切り替えます。',
                e
            );

            storageAvailable = false;
        }
    }

    /*
     * sandbox iframe 等ではここに到達する。
     * ページを開いている間は状態を維持する。
     */
    memoryStorage = value;

    return false;
}


/* =========================================================
   Default Data
========================================================= */

/*
 * ★重要
 *
 * defaultData は loadState() より前に定義する。
 *
 * ここには現在の index.php に存在する
 * 「const defaultData = { ... };」
 * をそのまま置く。
 *
 * 既存の defaultData の内容は変更しない。
 */
const defaultData = {
    settings: {
        kintone: {
            host: '',
            app: '',
            nameField: '',
            contactField: '',
            emailField: '',
            connected: false,
            updatedAt: ''
        },

        smtp: {
            host: '',
            port: '587',
            from: '',
            encryption: 'STARTTLS',
            configured: false,
            updatedAt: ''
        }
    },

    currentPage: 'home',
    currentSurveyId: 1,

    surveys: [
        /*
         * =================================================
         * ここは既存 index.php の defaultData.surveys
         * の内容をそのまま使用する。
         * =================================================
         */
    ],

    customers: [
        /*
         * ここも既存 defaultData.customers の内容を
         * そのまま使用する。
         */
    ],

    sendResults: []
};


/* =========================================================
   State
========================================================= */

function cloneDefaultData() {
    /*
     * defaultData が存在しない状態で呼ばれることを
     * 防止する。
     */
    if (
        typeof defaultData === 'undefined' ||
        !defaultData
    ) {
        throw new Error(
            'defaultData が初期化されていません。' +
            'defaultData は loadState() より前に定義してください。'
        );
    }

    return JSON.parse(
        JSON.stringify(defaultData)
    );
}

function loadState() {
    /*
     * 最初に Storage の利用可否を確認。
     */
    initStorage();

    try {
        const raw = readStorage();

        if (raw) {
            const parsed = JSON.parse(raw);

            if (
                parsed &&
                typeof parsed === 'object' &&
                Array.isArray(parsed.surveys)
            ) {
                return normalizeState(parsed);
            }

            console.warn(
                '保存されているモックデータの形式が不正です。初期データを使用します。'
            );
        }

    } catch (e) {
        console.warn(
            '保存データの読み込みに失敗しました。初期データを使用します。',
            e
        );
    }

    /*
     * localStorage が使用できない場合でも、
     * defaultData から状態を生成して続行する。
     */
    return cloneDefaultData();
}

function saveState() {
    if (
        typeof state === 'undefined' ||
        !state ||
        typeof state !== 'object'
    ) {
        console.warn(
            'state が存在しないため保存をスキップしました。'
        );

        return false;
    }

    try {
        const value = JSON.stringify(state);

        writeStorage(value);

        return true;

    } catch (e) {
        console.error(
            'モック状態の保存に失敗しました。',
            e
        );

        /*
         * 保存できなくても UI 操作自体は継続させる。
         */
        return false;
    }
}

function normalizeState(data) {
    const base = cloneDefaultData();

    const result = {
        ...base,
        ...data
    };

    result.settings = {
        ...base.settings,
        ...(data.settings || {})
    };

    result.settings.kintone = {
        ...base.settings.kintone,
        ...((data.settings || {}).kintone || {})
    };

    result.settings.smtp = {
        ...base.settings.smtp,
        ...((data.settings || {}).smtp || {})
    };

    result.surveys =
        Array.isArray(data.surveys)
            ? data.surveys
            : base.surveys;

    result.customers =
        Array.isArray(data.customers)
            ? data.customers
            : base.customers;

    result.sendResults =
        Array.isArray(data.sendResults)
            ? data.sendResults
            : base.sendResults;

    return result;
}


/*
 * =========================================================
 * ★ここで初めて state を生成する
 * =========================================================
 */

let state = loadState();


/* =========================================================
   Runtime State
========================================================= */

if (
    !state.currentSurveyId &&
    state.surveys.length
) {
    state.currentSurveyId =
        state.surveys[0].id;
}

if (!state.currentPage) {
    state.currentPage = 'home';
}


/* =========================================================
   Navigation
========================================================= */

function navigate(page) {
    try {
        state.currentPage = page;

        /*
         * localStorage のエラーで画面遷移まで
         * 止まらないようにする。
         */
        saveState();

        const titleMap = {
            home: 'ホーム',
            surveys: 'アンケート一覧',
            editor: 'アンケート編集',
            preview: '公開前確認',
            responses: '回答状況',
            'response-detail': '回答内容',
            send: 'アンケート送付',
            customers: '顧客選択',
            'send-confirm': '送付確認',
            'send-result': '送付結果',
            settings: '各種設定',
            answer: '回答者向けアンケート',
            'answer-confirm': '回答確認',
            'answer-complete': '回答完了'
        };

        const title =
            document.getElementById('topbarTitle');

        if (title) {
            title.textContent =
                titleMap[page] || 'アンケート管理';
        }

        document
            .querySelectorAll('.nav button')
            .forEach(button => {
                button.classList.toggle(
                    'active',
                    button.dataset.page === page
                );
            });

        renderPage();

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });

    } catch (e) {
        console.error(
            '画面遷移エラー:',
            e
        );

        showFatalError(
            '画面表示中にエラーが発生しました。',
            e
        );
    }
}


/* =========================================================
   Fatal Error
========================================================= */

function showFatalError(message, error) {
    console.error(message, error);

    const root =
        document.getElementById('appContent');

    if (!root) {
        return;
    }

    root.innerHTML = `
        <div class="error-box">
            <strong>
                ${escapeHtml(message)}
            </strong>

            <p>
                モックデータを初期化して
                再度お試しください。
            </p>

            <button
                type="button"
                class="btn btn-danger"
                onclick="resetMock()">
                モックデータを初期化する
            </button>
        </div>
    `;
}


/* =========================================================
   Reset
========================================================= */

function resetMock() {
    if (!window.confirm(
        'モックデータを初期状態に戻します。よろしいですか？'
    )) {
        return;
    }

    state = cloneDefaultData();

    saveState();

    navigate('home');

    toast(
        storageAvailable
            ? 'モックデータを初期化しました。'
            : 'モックデータを初期化しました（メモリ上で保持しています）。'
    );
}


/* =========================================================
   Initialization
========================================================= */

function initializeMock() {
    try {
        /*
         * state が正常に生成されていることを確認。
         */
        if (
            !state ||
            !Array.isArray(state.surveys)
        ) {
            state = cloneDefaultData();
        }

        if (
            !state.currentSurveyId &&
            state.surveys.length
        ) {
            state.currentSurveyId =
                state.surveys[0].id;
        }

        if (!state.currentPage) {
            state.currentPage = 'home';
        }

        saveState();

        navigate(
            state.currentPage
        );

    } catch (e) {
        console.error(
            'モック初期化エラー:',
            e
        );

        /*
         * 一度完全な初期データで再起動する。
         */
        try {
            state = cloneDefaultData();
            state.currentPage = 'home';

            saveState();
            navigate('home');

        } catch (retryError) {
            showFatalError(
                'モックの初期化に失敗しました。',
                retryError
            );
        }
    }
}


/* =========================================================
   Global API
========================================================= */

/*
 * 現在のHTMLでは onclick="..." を多数利用しているため、
 * inline handler から確実に呼べるよう window に公開する。
 *
 * 既存関数を上書きする必要はない。
 */
window.navigate = navigate;
window.resetMock = resetMock;
window.initializeMock = initializeMock;


/* =========================================================
   Start
========================================================= */

if (
    document.readyState === 'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        initializeMock,
        { once: true }
    );
} else {
    initializeMock();
}
</script>
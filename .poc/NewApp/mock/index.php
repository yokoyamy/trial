<script>
'use strict';

/*
 * =========================================================
 * Questionnaire Management Mock
 * Apache + PHP / single file
 *
 * JavaScript initialization / storage robustness fix
 * =========================================================
 */

const STORAGE_KEY = 'questionnaire_mock_v2';

/*
 * ---------------------------------------------------------
 * Storage
 * ---------------------------------------------------------
 *
 * localStorage が利用できない環境でも画面操作自体が
 * 全停止しないようにする。
 */
let memoryState = null;

function cloneDefaultData() {
    return JSON.parse(JSON.stringify(defaultData));
}

function loadState() {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);

        if (raw) {
            const parsed = JSON.parse(raw);

            /*
             * 古い/壊れた localStorage をそのまま採用しない。
             */
            if (
                parsed &&
                Array.isArray(parsed.surveys) &&
                Array.isArray(parsed.customers)
            ) {
                return normalizeState(parsed);
            }
        }
    } catch (e) {
        console.warn('localStorage の読み込みに失敗しました:', e);
    }

    return cloneDefaultData();
}

function saveState() {
    /*
     * state が壊れていても UI 操作を停止させない。
     */
    if (!state || typeof state !== 'object') {
        return false;
    }

    try {
        window.localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify(state)
        );

        memoryState = state;
        return true;
    } catch (e) {
        /*
         * Safari Private Browsing 等で localStorage が
         * 利用できない場合もモック自体は動かす。
         */
        console.warn('localStorage の保存に失敗しました。メモリ上で保持します:', e);
        memoryState = state;
        return false;
    }
}

function normalizeState(data) {
    const base = cloneDefaultData();

    const normalized = {
        ...base,
        ...data,

        settings: {
            ...base.settings,
            ...(data.settings || {}),

            kintone: {
                ...base.settings.kintone,
                ...((data.settings || {}).kintone || {})
            },

            smtp: {
                ...base.settings.smtp,
                ...((data.settings || {}).smtp || {})
            }
        },

        customers: Array.isArray(data.customers)
            ? data.customers
            : base.customers,

        surveys: Array.isArray(data.surveys)
            ? data.surveys
            : base.surveys,

        sendResults: Array.isArray(data.sendResults)
            ? data.sendResults
            : base.sendResults
    };

    /*
     * 質問・グループ・回答配列が欠落している古いデータにも対応。
     */
    normalized.surveys = normalized.surveys.map(survey => ({
        ...survey,
        groups: Array.isArray(survey.groups)
            ? survey.groups
            : [],
        questions: Array.isArray(survey.questions)
            ? survey.questions
            : [],
        answers: Array.isArray(survey.answers)
            ? survey.answers
            : [],
        selectedCustomerIds: Array.isArray(survey.selectedCustomerIds)
            ? survey.selectedCustomerIds
            : [],
        sentCount: Number(survey.sentCount || 0),
        responseCount: Number(survey.responseCount || 0)
    }));

    return normalized;
}


/*
 * ---------------------------------------------------------
 * State
 * ---------------------------------------------------------
 */

let state = loadState();

/*
 * 質問編集対象を state に明示的に保持する。
 */
if (typeof state.editingQuestionId === 'undefined') {
    state.editingQuestionId = null;
}


/*
 * ---------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------
 */

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function uid(prefix) {
    return prefix +
        '_' +
        Date.now().toString(36) +
        Math.random().toString(36).slice(2, 7);
}

function currentSurvey() {
    if (!state || !Array.isArray(state.surveys)) {
        return null;
    }

    const currentId = Number(state.currentSurveyId);

    let survey = state.surveys.find(
        s => Number(s.id) === currentId
    );

    if (!survey) {
        survey = state.surveys[0] || null;
    }

    /*
     * 現在のアンケートが存在する状態を維持。
     */
    if (survey) {
        state.currentSurveyId = survey.id;
    }

    return survey;
}

function setCurrentSurvey(id) {
    const survey = state.surveys.find(
        s => Number(s.id) === Number(id)
    );

    if (!survey) {
        return false;
    }

    state.currentSurveyId = survey.id;
    saveState();

    return true;
}

function nowString() {
    const d = new Date();

    const pad = n => String(n).padStart(2, '0');

    return [
        d.getFullYear(),
        pad(d.getMonth() + 1),
        pad(d.getDate())
    ].join('-') + ' ' +
    [
        pad(d.getHours()),
        pad(d.getMinutes())
    ].join(':');
}


/*
 * ---------------------------------------------------------
 * Reset
 * ---------------------------------------------------------
 */

function resetMock() {
    if (!window.confirm(
        'モックデータを初期状態へ戻します。よろしいですか？'
    )) {
        return;
    }

    state = cloneDefaultData();
    state.editingQuestionId = null;

    saveState();

    navigate('home');

    toast('モックデータを初期化しました。');
}


/*
 * ---------------------------------------------------------
 * Navigation
 * ---------------------------------------------------------
 */

const pageTitles = {
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

function navigate(page) {
    try {
        state.currentPage = page;
        saveState();

        const title =
            pageTitles[page] || 'アンケート管理';

        const topbarTitle =
            document.getElementById('topbarTitle');

        if (topbarTitle) {
            topbarTitle.textContent = title;
        }

        document
            .querySelectorAll('.nav button')
            .forEach(btn => {
                btn.classList.toggle(
                    'active',
                    btn.dataset.page === page
                );
            });

        renderPage();

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });

    } catch (e) {
        console.error('画面遷移エラー:', e);

        showFatalError(
            '画面の表示中にエラーが発生しました。',
            e
        );
    }
}

function renderPage() {
    const root =
        document.getElementById('appContent');

    if (!root) {
        throw new Error(
            'appContent が見つかりません。'
        );
    }

    switch (state.currentPage) {
        case 'home':
            root.innerHTML = renderHome();
            break;

        case 'surveys':
            root.innerHTML = renderSurveyList();
            break;

        case 'editor':
            root.innerHTML = renderEditor();
            break;

        case 'preview':
            root.innerHTML = renderPreview();
            break;

        case 'responses':
            root.innerHTML = renderResponses();
            break;

        case 'response-detail':
            root.innerHTML = renderResponseDetail();
            break;

        case 'send':
            root.innerHTML = renderSend();
            break;

        case 'customers':
            root.innerHTML = renderCustomers();
            break;

        case 'send-confirm':
            root.innerHTML = renderSendConfirm();
            break;

        case 'send-result':
            root.innerHTML = renderSendResult();
            break;

        case 'settings':
            root.innerHTML = renderSettings();
            break;

        case 'answer':
            root.innerHTML = renderAnswer();
            break;

        case 'answer-confirm':
            root.innerHTML = renderAnswerConfirm();
            break;

        case 'answer-complete':
            root.innerHTML = renderAnswerComplete();
            break;

        default:
            state.currentPage = 'home';
            root.innerHTML = renderHome();
            break;
    }

    bindPageEvents();
}


/*
 * ---------------------------------------------------------
 * Survey creation / editing
 * ---------------------------------------------------------
 */

function newSurvey() {
    const ids = state.surveys
        .map(s => Number(s.id))
        .filter(Number.isFinite);

    const newId =
        (ids.length ? Math.max(...ids) : 0) + 1;

    const survey = {
        id: newId,
        name: '',
        description: '',
        guidance: '',
        completeMessage:
            'ご回答ありがとうございました。',
        status: 'draft',
        createdAt:
            new Date().toISOString().slice(0, 10),
        updatedAt: nowString(),
        startAt: '',
        endAt: '',
        numberMode: 'global',
        sentCount: 0,
        responseCount: 0,
        selectedCustomerIds: [],
        lastSentAt: '',
        groups: [
            {
                id: uid('g'),
                name: '基本情報'
            }
        ],
        questions: [],
        answers: []
    };

    state.surveys.unshift(survey);
    state.currentSurveyId = newId;
    state.editingQuestionId = null;

    saveState();

    navigate('editor');
}

function editQuestion(questionId) {
    const survey = currentSurvey();

    if (!survey) {
        toast('アンケートが見つかりません。');
        return;
    }

    const q = survey.questions.find(
        x => x.id === questionId
    );

    if (!q) {
        toast('質問が見つかりません。');
        return;
    }

    /*
     * ここが既存コードの重要な修正点。
     */
    state.editingQuestionId = q.id;

    saveState();

    const locked = !canStructuralEdit(survey);

    if (locked) {
        showModal(
            '質問内容',
            renderQuestionReadonly(survey, q),
            '<button type="button" class="btn" onclick="closeModal()">閉じる</button>'
        );
        return;
    }

    showModal(
        '質問を編集する',
        renderQuestionForm(survey, q),
        `
            <button type="button"
                    class="btn"
                    onclick="closeModal()">
                キャンセル
            </button>

            <button type="button"
                    class="btn btn-primary"
                    onclick="saveQuestionEdit('${escapeHtml(q.id)}')">
                保存する
            </button>
        `
    );

    bindQuestionTypeEvents();
}


/*
 * ---------------------------------------------------------
 * Modal
 * ---------------------------------------------------------
 */

function showModal(title, body, footer) {
    const titleEl =
        document.getElementById('modalTitle');

    const bodyEl =
        document.getElementById('modalBody');

    const footerEl =
        document.getElementById('modalFooter');

    const backdrop =
        document.getElementById('modalBackdrop');

    if (!titleEl || !bodyEl || !footerEl || !backdrop) {
        console.error('モーダル要素が見つかりません。');
        return;
    }

    titleEl.innerHTML = title;
    bodyEl.innerHTML = body;
    footerEl.innerHTML = footer || '';

    backdrop.classList.add('show');
}

function closeModal() {
    const backdrop =
        document.getElementById('modalBackdrop');

    if (backdrop) {
        backdrop.classList.remove('show');
    }

    window.__modalConfirm = null;
    state.editingQuestionId = null;
}

function showConfirm(
    title,
    body,
    confirmLabel,
    onConfirm,
    kind = 'primary'
) {
    const buttonClass =
        kind === 'danger'
            ? 'btn-danger'
            : kind === 'warning'
                ? 'btn-warning'
                : 'btn-primary';

    showModal(
        title,
        body,
        `
            <button type="button"
                    class="btn"
                    onclick="closeModal()">
                キャンセル
            </button>

            <button type="button"
                    class="btn ${buttonClass}"
                    onclick="window.__modalConfirm && window.__modalConfirm()">
                ${escapeHtml(confirmLabel)}
            </button>
        `
    );

    window.__modalConfirm = function() {
        const callback = window.__modalConfirm;

        window.__modalConfirm = null;

        try {
            onConfirm();
        } catch (e) {
            console.error('確認操作エラー:', e);
            showFatalError(
                '操作中にエラーが発生しました。',
                e
            );
        }
    };
}


/*
 * ---------------------------------------------------------
 * Question editor
 * ---------------------------------------------------------
 */

function bindQuestionTypeEvents() {
    const type =
        document.getElementById('editQType');

    if (!type) {
        return;
    }

    type.addEventListener(
        'change',
        renderChoiceEditorFromModal
    );

    renderChoiceEditorFromModal();
}

function renderChoiceEditorFromModal() {
    const type =
        document.getElementById('editQType')?.value;

    const box =
        document.getElementById('choiceEditor');

    if (!box) {
        return;
    }

    if (
        !['single', 'multiple', 'rating']
            .includes(type)
    ) {
        box.innerHTML = '';
        return;
    }

    const survey = currentSurvey();

    if (!survey) {
        box.innerHTML = '';
        return;
    }

    /*
     * editQuestion() でセットした値を使用。
     */
    const q =
        survey.questions.find(
            x => x.id === state.editingQuestionId
        );

    if (!q) {
        box.innerHTML = '';
        return;
    }

    let choices = Array.isArray(q.choices)
        ? q.choices
        : [];

    if (!choices.length && type === 'rating') {
        choices =
            ['1', '2', '3', '4', '5']
                .map((x, i) => ({
                    id: 'r' + (i + 1),
                    text: x
                }));
    }

    box.innerHTML = `
        <label class="form-label">
            選択肢
        </label>

        <div id="modalChoices">
            ${choices.map((c, index) => {
                const text =
                    typeof c === 'string'
                        ? c
                        : c.text;

                const id =
                    typeof c === 'string'
                        ? 'choice_' + index
                        : c.id;

                return `
                    <div class="choice-row"
                         data-choice-id="${escapeHtml(id)}">

                        <input type="text"
                               value="${escapeHtml(text)}">

                        <button type="button"
                                class="btn btn-sm btn-danger"
                                onclick="this.parentElement.remove()">
                            削除
                        </button>
                    </div>
                `;
            }).join('')}
        </div>

        ${type !== 'rating' ? `
            <button type="button"
                    class="btn btn-sm"
                    onclick="addModalChoice()">
                ＋ 選択肢を追加
            </button>
        ` : ''}
    `;
}

function addModalChoice() {
    const list =
        document.getElementById('modalChoices');

    if (!list) {
        return;
    }

    const id = uid('c');

    const div =
        document.createElement('div');

    div.className = 'choice-row';
    div.dataset.choiceId = id;

    div.innerHTML = `
        <input type="text" value="">

        <button type="button"
                class="btn btn-sm btn-danger"
                onclick="this.parentElement.remove()">
            削除
        </button>
    `;

    list.appendChild(div);
}


/*
 * ---------------------------------------------------------
 * Error handling
 * ---------------------------------------------------------
 */

function showFatalError(message, error = null) {
    console.error(message, error);

    const root =
        document.getElementById('appContent');

    if (!root) {
        return;
    }

    root.innerHTML = `
        <div class="error-box">
            <strong>${escapeHtml(message)}</strong>

            <p>
                ページを再読み込みしても改善しない場合は、
                「モックデータを初期化する」を実行してください。
            </p>

            <button type="button"
                    class="btn btn-danger"
                    onclick="resetMock()">
                モックデータを初期化する
            </button>
        </div>
    `;
}

function showValidationErrors(errors, targetId = null) {
    const html = `
        <div class="error-box">
            <strong>
                入力内容を確認してください。
            </strong>

            <ul>
                ${errors.map(e =>
                    `<li>${escapeHtml(e)}</li>`
                ).join('')}
            </ul>
        </div>
    `;

    if (
        targetId &&
        document.getElementById(targetId)
    ) {
        document.getElementById(targetId)
            .innerHTML = html;

        document.getElementById(targetId)
            .scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });

        return;
    }

    showModal(
        '公開前チェック',
        html,
        `
            <button type="button"
                    class="btn"
                    onclick="closeModal()">
                閉じる
            </button>
        `
    );
}

function toast(message) {
    const el =
        document.getElementById('toast');

    if (!el) {
        return;
    }

    el.textContent = message;
    el.classList.add('show');

    clearTimeout(window.__toastTimer);

    window.__toastTimer =
        setTimeout(() => {
            el.classList.remove('show');
        }, 2500);
}


/*
 * ---------------------------------------------------------
 * Events
 * ---------------------------------------------------------
 */

function bindPageEvents() {
    const backdrop =
        document.getElementById('modalBackdrop');

    if (
        backdrop &&
        !backdrop.dataset.bound
    ) {
        backdrop.dataset.bound = '1';

        backdrop.addEventListener(
            'click',
            function(e) {
                if (e.target === backdrop) {
                    closeModal();
                }
            }
        );
    }
}


/*
 * ---------------------------------------------------------
 * Make functions explicitly available to inline handlers
 * ---------------------------------------------------------
 *
 * Apache/PHP 環境、ブラウザ、CSP/拡張機能等による
 * グローバルスコープ差異の影響を受けにくくする。
 */

Object.assign(window, {
    navigate,
    renderPage,
    newSurvey,
    editSurvey,
    openSurvey,
    openPreview,
    openResponses,
    openResponseDetail,
    openSend,
    startAnswer,
    resetMock,

    addGroup,
    renameGroup,
    deleteGroup,
    addQuestion,
    editQuestion,
    saveQuestionEdit,
    deleteQuestion,

    dragQuestion,
    allowDrop,
    questionDragOver,
    questionDragLeave,
    dragQuestionEnd,
    dropQuestion,
    dropQuestionToGroup,

    publishSurvey,
    startSurvey,
    endSurvey,
    archiveSurvey,
    deleteSurvey,
    saveSurvey,

    selectAllCustomers,
    clearCustomers,
    toggleCustomer,
    executeSend,

    saveKintone,
    testKintone,
    refreshCustomers,
    saveSmtp,
    testSmtp,

    answerNext,
    goAnswerConfirm,

    showModal,
    showConfirm,
    closeModal,

    showValidationErrors,
    toast
});


/*
 * ---------------------------------------------------------
 * Initialization
 * ---------------------------------------------------------
 */

function initializeMock() {
    try {
        /*
         * 壊れた/旧形式データを正規化。
         */
        state = normalizeState(state);

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
            state.currentPage || 'home'
        );

    } catch (e) {
        console.error(
            'モック初期化エラー:',
            e
        );

        /*
         * 壊れた localStorage が原因でも
         * 初期データへ戻して画面を起動できるようにする。
         */
        try {
            state = cloneDefaultData();
            state.editingQuestionId = null;

            saveState();

            navigate('home');

        } catch (retryError) {
            console.error(
                'モック再初期化にも失敗:',
                retryError
            );

            showFatalError(
                'モック画面の初期化に失敗しました。',
                retryError
            );
        }
    }
}


/*
 * DOM 構築完了後に初期化する。
 */
if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        initializeMock,
        { once: true }
    );
} else {
    initializeMock();
}
</script>
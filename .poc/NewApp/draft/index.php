<body>

<header class="topbar" id="operator-header">
    <div class="logo">アンケート業務運営</div>

    <nav class="topnav" aria-label="メインメニュー">
        <button
            id="nav-list"
            type="button"
            class="nav-button active"
        >
            アンケート一覧
        </button>

        <button
            id="nav-create"
            type="button"
            class="nav-button"
        >
            アンケート作成
        </button>

        <button
            id="nav-customers"
            type="button"
            class="nav-button"
        >
            顧客一覧
        </button>

        <button
            id="nav-settings"
            type="button"
            class="nav-button"
        >
            設定
        </button>
    </nav>
</header>

<main class="container">

<section id="page-list">

    <div class="page-header">

        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">
                作成したアンケートの管理を行います
            </div>
        </div>

        <button
            id="btn-create-survey"
            class="btn btn-primary"
            type="button"
        >
            ＋ アンケート作成
        </button>

    </div>

    <div id="list-notice"></div>

    <div class="card">

        <div class="table-wrap">

            <table class="table">

                <thead>
                <tr>
                    <th>アンケート名</th>
                    <th>状態</th>
                    <th>作成日</th>
                    <th>公開期間</th>
                    <th>回答数</th>
                    <th>最終更新日</th>
                    <th>操作</th>
                </tr>
                </thead>

                <tbody id="survey-list-body"></tbody>

            </table>

        </div>

    </div>

</section>


<section id="page-editor" class="hidden">

    <div class="page-header">

        <div>
            <h1 id="editor-heading">アンケート作成</h1>

            <div class="subtext">
                アンケート全体を確認しながら作成・編集します
            </div>
        </div>

        <div class="editor-actions">

            <button
                id="btn-editor-back"
                class="btn"
                type="button"
            >
                一覧へ戻る
            </button>

        </div>

    </div>


    <div id="editor-notice"></div>


    <div class="card">

        <div class="form-grid">

            <div class="field">

                <label for="survey-name">
                    アンケート名 *
                </label>

                <input
                    id="survey-name"
                    type="text"
                    placeholder="例：新商品アンケート"
                >

            </div>


            <div class="field">

                <label for="survey-status">
                    公開状態
                </label>

                <select id="survey-status">

                    <option value="draft">
                        下書き
                    </option>

                    <option value="open">
                        公開中
                    </option>

                    <option value="end">
                        終了
                    </option>

                </select>

            </div>

        </div>


        <div class="field">

            <label for="survey-description">
                説明
            </label>

            <textarea
                id="survey-description"
                placeholder="回答者への説明を入力してください"
            ></textarea>

        </div>


        <div class="form-grid">

            <div class="field">

                <label for="survey-start">
                    公開開始日
                </label>

                <input
                    id="survey-start"
                    type="date"
                >

            </div>


            <div class="field">

                <label for="survey-end">
                    公開終了日
                </label>

                <input
                    id="survey-end"
                    type="date"
                >

            </div>

        </div>


        <div class="field">

            <label>
                質問番号
            </label>

            <div class="radio-row">

                <label>

                    <input
                        type="radio"
                        name="numbering"
                        id="numbering-global"
                        value="global"
                    >

                    全体で通番（Q1、Q2、Q3…）

                </label>


                <label>

                    <input
                        type="radio"
                        name="numbering"
                        id="numbering-group"
                        value="group"
                    >

                    グループごと（Q1-1、Q1-2、Q2-1…）

                </label>

            </div>

        </div>

    </div>


    <div id="editor-groups"></div>


    <div class="add-group-area">

        <button
            id="btn-add-group"
            class="btn btn-primary"
            type="button"
        >
            ＋ グループ追加
        </button>

    </div>


    <div class="editor-toolbar">

        <button
            id="btn-editor-back-bottom"
            class="btn"
            type="button"
        >
            一覧へ戻る
        </button>


        <div class="editor-actions">

            <button
                id="btn-preview-editor"
                class="btn"
                type="button"
            >
                内容確認
            </button>


            <button
                id="btn-save-survey"
                class="btn btn-primary"
                type="button"
            >
                <span
                    class="loading-spinner"
                    aria-hidden="true"
                ></span>
                保存
            </button>

        </div>

    </div>

</section>


<section id="page-detail" class="hidden">

    <div class="page-header">

        <div>

            <h1 id="detail-title"></h1>

            <div
                class="subtext"
                id="detail-subtitle"
            ></div>

        </div>


        <div>

            <button
                id="btn-detail-edit"
                class="btn"
                type="button"
            >
                編集
            </button>


            <button
                id="btn-detail-send"
                class="btn btn-primary"
                type="button"
            >
                送信
            </button>


            <button
                id="btn-detail-back"
                class="btn"
                type="button"
            >
                一覧へ戻る
            </button>

        </div>

    </div>


    <div id="detail-notice"></div>


    <div class="detail-tabs">

        <button
            id="tab-content"
            type="button"
            data-tab="content"
        >
            アンケート内容
        </button>


        <button
            id="tab-send"
            type="button"
            data-tab="send"
        >
            送信
        </button>


        <button
            id="tab-status"
            type="button"
            data-tab="status"
        >
            回答状況
        </button>


        <button
            id="tab-result"
            type="button"
            data-tab="result"
        >
            回答結果
        </button>

    </div>


    <div id="detail-content"></div>

</section>


<section id="page-customers" class="hidden">

    <div class="page-header">

        <div>

            <h1>顧客一覧</h1>

            <div class="subtext">
                kintoneから取得した顧客です
            </div>

        </div>


        <button
            id="btn-customer-settings"
            class="btn"
            type="button"
        >
            kintone設定
        </button>

    </div>


    <div id="customer-notice"></div>


    <div class="card">

        <div class="customer-toolbar">

            <input
                id="customer-search"
                type="search"
                placeholder="顧客名・メールアドレスで検索"
                autocomplete="off"
            >


            <button
                id="btn-refresh-customers"
                class="btn"
                type="button"
            >
                <span
                    class="loading-spinner"
                    aria-hidden="true"
                ></span>
                顧客一覧を更新
            </button>

        </div>


        <div class="table-wrap">

            <table class="table">

                <thead>

                <tr>

                    <th>
                        顧客名
                    </th>

                    <th>
                        メールアドレス
                    </th>

                    <th>
                        会社名
                    </th>

                    <th>
                        顧客番号
                    </th>

                </tr>

                </thead>


                <tbody id="customer-body"></tbody>

            </table>

        </div>

    </div>

</section>


<section id="page-settings" class="hidden">

    <div class="page-header">

        <div>

            <h1>設定</h1>

            <div class="subtext">
                メール送信と顧客一覧取得に必要な設定を管理します
            </div>

        </div>

    </div>


    <div class="settings-tabs">

        <button
            id="settings-tab-mail"
            type="button"
            data-settings-tab="mail"
        >
            メール送信設定
        </button>


        <button
            id="settings-tab-kintone"
            type="button"
            data-settings-tab="kintone"
        >
            kintone設定
        </button>

    </div>


    <div id="settings-content"></div>

</section>

</main>


<div
    id="modal"
    class="modal-backdrop hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="modal-title"
>

    <div class="modal">

        <div class="modal-header">

            <strong id="modal-title"></strong>

            <button
                id="modal-close"
                class="btn btn-small"
                type="button"
            >
                閉じる
            </button>

        </div>


        <div
            id="modal-body"
            class="modal-body"
        ></div>


        <div
            id="modal-footer"
            class="modal-footer"
        ></div>

    </div>

</div>


<div
    id="toast"
    class="toast"
    role="status"
    aria-live="polite"
></div>


<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        'use strict';

        const csrfMeta =
            document.querySelector(
                'meta[name="csrf-token"]'
            );

        const apiMeta =
            document.querySelector(
                'meta[name="api-path"]'
            );

        const csrfToken =
            csrfMeta instanceof HTMLMetaElement
                ? csrfMeta.content
                : '';

        const apiPath =
            apiMeta instanceof HTMLMetaElement &&
            apiMeta.content.trim() !== ''
                ? apiMeta.content
                : 'index.php';


        let surveys = [];
        let customers = [];

        let currentSurvey = null;
        let currentSurveyId = '';

        let editingSurvey = null;

        let currentSettingsTab = 'mail';
        let currentDetailTab = 'content';

        let draggedGroupId = '';
        let draggedQuestionId = '';

        let nextGroupNo = 1;
        let nextQuestionNo = 1;

        let toastTimer = null;


        const $ = function (id) {
            return document.getElementById(id);
        };


        function escapeHtml(value) {

            const str =
                String(value ?? '');

            return str
                .replace(
                    /&/g,
                    '&amp;'
                )
                .replace(
                    /</g,
                    '&lt;'
                )
                .replace(
                    />/g,
                    '&gt;'
                )
                .replace(
                    /"/g,
                    '&quot;'
                )
                .replace(
                    /'/g,
                    '&#039;'
                );
        }


        function showToast(message) {

            const toast =
                $('toast');

            if (!toast) {
                return;
            }

            toast.textContent =
                String(message ?? '');

            toast.classList.add('show');

            if (toastTimer !== null) {
                window.clearTimeout(
                    toastTimer
                );
            }

            toastTimer =
                window.setTimeout(
                    function () {

                        if (toast) {
                            toast.classList.remove(
                                'show'
                            );
                        }

                    },
                    2800
                );
        }


        function showNotice(
            target,
            message,
            type
        ) {

            if (!target) {
                return;
            }

            target.textContent = '';

            if (
                message === null ||
                message === undefined ||
                String(message) === ''
            ) {
                return;
            }

            const div =
                document.createElement(
                    'div'
                );

            div.className =
                'notice' +
                (
                    type
                        ? ' ' + String(type)
                        : ''
                );

            div.textContent =
                String(message);

            target.appendChild(div);
        }


        function setButtonLoading(
            button,
            loading
        ) {

            if (!button) {
                return;
            }

            if (loading) {

                button.disabled = true;

                button.classList.add(
                    'loading'
                );

            } else {

                button.disabled = false;

                button.classList.remove(
                    'loading'
                );
            }
        }


        function buildApiUrl(
            action,
            params
        ) {

            const query =
                new URLSearchParams();

            query.set(
                'action',
                String(action)
            );

            if (
                params &&
                typeof params === 'object'
            ) {

                Object.keys(params)
                    .forEach(
                        function (key) {

                            const value =
                                params[key];

                            if (
                                value !== undefined &&
                                value !== null &&
                                String(value) !== ''
                            ) {

                                query.set(
                                    key,
                                    String(value)
                                );
                            }
                        }
                    );
            }

            return (
                apiPath +
                (
                    apiPath.includes('?')
                        ? '&'
                        : '?'
                ) +
                query.toString()
            );
        }


        async function apiGet(
            action,
            params
        ) {

            const url =
                buildApiUrl(
                    action,
                    params
                );

            const response =
                await fetch(
                    url,
                    {
                        method: 'GET',
                        credentials:
                            'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Accept':
                                'application/json'
                        }
                    }
                );

            const text =
                await response.text();

            let data = null;

            try {

                data =
                    JSON.parse(text);

            } catch (error) {

                throw new Error(
                    'サーバーから正しい応答を取得できませんでした。'
                );
            }


            if (
                !response.ok ||
                !data ||
                data.success !== true
            ) {

                throw new Error(
                    data &&
                    typeof data.message === 'string' &&
                    data.message
                        ? data.message
                        : '処理に失敗しました。'
                );
            }

            return data;
        }


        async function apiPost(
            button,
            action,
            payload
        ) {

            if (button) {

                button.disabled = true;

                button.classList.add(
                    'loading'
                );
            }

            try {

                const url =
                    buildApiUrl(action);

                const response =
                    await fetch(
                        url,
                        {
                            method: 'POST',
                            credentials:
                                'same-origin',
                            cache: 'no-store',

                            headers: {
                                'Content-Type':
                                    'application/json; charset=utf-8',

                                'Accept':
                                    'application/json',

                                'X-CSRF-Token':
                                    csrfToken
                            },

                            body:
                                JSON.stringify(
                                    payload || {}
                                )
                        }
                    );


                const text =
                    await response.text();

                let data = null;

                try {

                    data =
                        JSON.parse(text);

                } catch (error) {

                    throw new Error(
                        'サーバーから正しい応答を取得できませんでした。'
                    );
                }


                if (
                    !response.ok ||
                    !data ||
                    data.success !== true
                ) {

                    throw new Error(
                        data &&
                        typeof data.message === 'string' &&
                        data.message
                            ? data.message
                            : '処理に失敗しました。'
                    );
                }


                return data;

            } finally {

                if (button) {

                    button.disabled = false;

                    button.classList.remove(
                        'loading'
                    );
                }
            }
        }


        function showPage(id) {

            const pageIds = [
                'page-list',
                'page-editor',
                'page-detail',
                'page-customers',
                'page-settings'
            ];


            pageIds.forEach(
                function (pageId) {

                    const element =
                        $(pageId);

                    if (element) {

                        element.classList.toggle(
                            'hidden',
                            pageId !== id
                        );
                    }
                }
            );


            const navIds = [
                'nav-list',
                'nav-create',
                'nav-customers',
                'nav-settings'
            ];


            navIds.forEach(
                function (navId) {

                    const element =
                        $(navId);

                    if (element) {

                        element.classList.remove(
                            'active'
                        );
                    }
                }
            );


            if (id === 'page-list') {

                const nav =
                    $('nav-list');

                if (nav) {

                    nav.classList.add(
                        'active'
                    );
                }
            }


            if (id === 'page-editor') {

                const nav =
                    $('nav-create');

                if (nav) {

                    nav.classList.add(
                        'active'
                    );
                }
            }


            if (id === 'page-customers') {

                const nav =
                    $('nav-customers');

                if (nav) {

                    nav.classList.add(
                        'active'
                    );
                }
            }


            if (id === 'page-settings') {

                const nav =
                    $('nav-settings');

                if (nav) {

                    nav.classList.add(
                        'active'
                    );
                }
            }
        }


        function openModal(
            title,
            body,
            footer
        ) {

            const modal =
                $('modal');

            const modalTitle =
                $('modal-title');

            const modalBody =
                $('modal-body');

            const modalFooter =
                $('modal-footer');


            if (!modal) {
                return;
            }


            if (modalTitle) {

                modalTitle.textContent =
                    String(title || '');
            }


            if (modalBody) {

                modalBody.textContent = '';

                if (body instanceof Node) {

                    modalBody.appendChild(
                        body
                    );
                }
            }


            if (modalFooter) {

                modalFooter.textContent = '';

                if (footer instanceof Node) {

                    modalFooter.appendChild(
                        footer
                    );
                }
            }


            modal.classList.remove(
                'hidden'
            );
        }


        function closeModal() {

            const modal =
                $('modal');

            if (modal) {

                modal.classList.add(
                    'hidden'
                );
            }
        }


        function createButton(
            text,
            className
        ) {

            const button =
                document.createElement(
                    'button'
                );

            button.type =
                'button';

            button.className =
                className || 'btn';

            button.textContent =
                String(text || '');

            return button;
        }


        function createLoadingButton(
            text,
            className
        ) {

            const button =
                createButton(
                    '',
                    className || 'btn'
                );


            const spinner =
                document.createElement(
                    'span'
                );

            spinner.className =
                'loading-spinner';

            button.appendChild(
                spinner
            );


            const label =
                document.createElement(
                    'span'
                );

            label.textContent =
                String(text || '');

            button.appendChild(
                label
            );

            return button;
        }


        function cloneSurvey(source) {

            try {

                return JSON.parse(
                    JSON.stringify(
                        source
                    )
                );

            } catch (error) {

                return null;
            }
        }


        function createEmptySurvey() {

            return {
                id: '',
                name: '',
                description: '',
                status: 'draft',
                created: '',
                start: '',
                end: '',
                answers: 0,
                target: 0,
                sent: 0,
                updated: '',
                numbering: 'global',
                groups: []
            };
        }


        function createEmptyGroup() {

            const group = {

                id:
                    'group_' +
                    Date.now() +
                    '_' +
                    Math.random()
                        .toString(16)
                        .slice(2),

                name:
                    'グループ ' +
                    String(nextGroupNo),

                questions: []

            };

            nextGroupNo += 1;

            return group;
        }


        function createEmptyQuestion() {

            const question = {

                id:
                    'question_' +
                    Date.now() +
                    '_' +
                    Math.random()
                        .toString(16)
                        .slice(2),

                text: '',
                type: 'free',
                required: false,
                options: []

            };

            nextQuestionNo += 1;

            return question;
        }


        function questionNumber(
            groupIndex,
            questionIndex
        ) {

            if (
                !editingSurvey ||
                editingSurvey.numbering !==
                    'group'
            ) {

                return (
                    'Q' +
                    String(
                        calculateGlobalQuestionNumber(
                            groupIndex,
                            questionIndex
                        )
                    )
                );
            }


            return (
                'Q' +
                String(groupIndex + 1) +
                '-' +
                String(questionIndex + 1)
            );
        }


        function calculateGlobalQuestionNumber(
            groupIndex,
            questionIndex
        ) {

            if (
                !editingSurvey ||
                !Array.isArray(
                    editingSurvey.groups
                )
            ) {

                return 1;
            }


            let number = 1;


            for (
                let i = 0;
                i < groupIndex;
                i += 1
            ) {

                const group =
                    editingSurvey.groups[i];

                if (
                    group &&
                    Array.isArray(
                        group.questions
                    )
                ) {

                    number +=
                        group.questions.length;
                }
            }


            return (
                number +
                questionIndex
            );
        }


        function statusLabel(
            status
        ) {

            const value =
                String(status || 'draft');


            if (value === 'open') {
                return '公開中';
            }


            if (value === 'end') {
                return '終了';
            }


            return '下書き';
        }


        function statusClass(
            status
        ) {

            const value =
                String(status || 'draft');


            if (value === 'open') {
                return 'badge-open';
            }


            if (value === 'end') {
                return 'badge-end';
            }


            return 'badge-draft';
        }


        function appendStatusBadge(
            parent,
            status
        ) {

            if (!parent) {
                return;
            }


            const badge =
                document.createElement(
                    'span'
                );

            badge.className =
                'badge ' +
                statusClass(status);

            badge.textContent =
                statusLabel(status);

            parent.appendChild(
                badge
            );
        }


        async function loadSurveyList() {

            try {

                const result =
                    await apiGet(
                        'list_surveys'
                    );


                surveys =
                    Array.isArray(
                        result.data?.surveys
                    )
                        ? result.data.surveys
                        : [];


                renderSurveyList();

            } catch (error) {

                const notice =
                    $('list-notice');

                showNotice(
                    notice,
                    error instanceof Error
                        ? error.message
                        : 'アンケート一覧を取得できません。',
                    'error'
                );
            }
        }


        function renderSurveyList() {

            const body =
                $('survey-list-body');

            if (!body) {
                return;
            }


            body.textContent = '';


            if (surveys.length === 0) {

                const tr =
                    document.createElement(
                        'tr'
                    );

                const td =
                    document.createElement(
                        'td'
                    );

                td.colSpan = 7;

                td.className =
                    'empty';

                td.textContent =
                    'アンケートがありません。';

                tr.appendChild(td);

                body.appendChild(tr);

                return;
            }


            surveys.forEach(
                function (survey) {

                    if (!survey) {
                        return;
                    }


                    const tr =
                        document.createElement(
                            'tr'
                        );


                    const nameTd =
                        document.createElement(
                            'td'
                        );


                    const nameButton =
                        document.createElement(
                            'button'
                        );

                    nameButton.type =
                        'button';

                    nameButton.className =
                        'link-button';

                    nameButton.textContent =
                        String(
                            survey?.name ||
                            '名称未設定'
                        );


                    nameButton.addEventListener(
                        'click',
                        function () {

                            const surveyId =
                                String(
                                    survey?.id ||
                                    ''
                                );

                            if (!surveyId) {
                                return;
                            }

                            openDetail(
                                surveyId
                            );
                        }
                    );


                    nameTd.appendChild(
                        nameButton
                    );


                    const statusTd =
                        document.createElement(
                            'td'
                        );

                    appendStatusBadge(
                        statusTd,
                        String(
                            survey?.status ||
                            'draft'
                        )
                    );


                    const createdTd =
                        document.createElement(
                            'td'
                        );

                    createdTd.textContent =
                        String(
                            survey?.created ||
                            ''
                        );


                    const periodTd =
                        document.createElement(
                            'td'
                        );

                    const start =
                        String(
                            survey?.start ||
                            ''
                        );

                    const end =
                        String(
                            survey?.end ||
                            ''
                        );


                    if (
                        start &&
                        end
                    ) {

                        periodTd.textContent =
                            start +
                            ' ～ ' +
                            end;

                    } else if (start) {

                        periodTd.textContent =
                            start +
                            ' ～';

                    } else if (end) {

                        periodTd.textContent =
                            '～ ' +
                            end;

                    } else {

                        periodTd.textContent =
                            '未設定';
                    }


                    const answerTd =
                        document.createElement(
                            'td'
                        );

                    answerTd.textContent =
                        String(
                            survey?.answers ??
                            0
                        );


                    const updatedTd =
                        document.createElement(
                            'td'
                        );

                    updatedTd.textContent =
                        String(
                            survey?.updated ||
                            ''
                        );


                    const actionTd =
                        document.createElement(
                            'td'
                        );


                    const editButton =
                        createButton(
                            '編集',
                            'btn btn-small'
                        );


                    editButton.addEventListener(
                        'click',
                        function () {

                            const id =
                                String(
                                    survey?.id ||
                                    ''
                                );

                            if (!id) {
                                return;
                            }

                            openEditor(
                                id
                            );
                        }
                    );


                    actionTd.appendChild(
                        editButton
                    );


                    actionTd.appendChild(
                        document.createTextNode(
                            ' '
                        )
                    );


                    const deleteButton =
                        createLoadingButton(
                            '削除',
                            'btn btn-small btn-danger'
                        );


                    deleteButton.addEventListener(
                        'click',
                        function () {

                            const id =
                                String(
                                    survey?.id ||
                                    ''
                                );

                            deleteSurvey(
                                id,
                                deleteButton
                            );
                        }
                    );


                    actionTd.appendChild(
                        deleteButton
                    );


                    if (
                        String(
                            survey?.status ||
                            'draft'
                        ) === 'open'
                    ) {

                        actionTd.appendChild(
                            document.createTextNode(
                                ' '
                            )
                        );


                        const closeButton =
                            createLoadingButton(
                                '終了',
                                'btn btn-small'
                            );


                        closeButton.addEventListener(
                            'click',
                            function () {

                                const id =
                                    String(
                                        survey?.id ||
                                        ''
                                    );

                                closeSurvey(
                                    id,
                                    closeButton
                                );
                            }
                        );


                        actionTd.appendChild(
                            closeButton
                        );
                    }


                    tr.appendChild(
                        nameTd
                    );

                    tr.appendChild(
                        statusTd
                    );

                    tr.appendChild(
                        createdTd
                    );

                    tr.appendChild(
                        periodTd
                    );

                    tr.appendChild(
                        answerTd
                    );

                    tr.appendChild(
                        updatedTd
                    );

                    tr.appendChild(
                        actionTd
                    );


                    body.appendChild(
                        tr
                    );
                }
            );
        }

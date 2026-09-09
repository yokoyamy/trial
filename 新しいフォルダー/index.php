/* ==========================================================
 * GOJACIC MANAGER
 * 修正版 JavaScript 全文
 *
 * 修正内容
 * - APP_BASE_PATH の再宣言をしない
 * - ツリー表示を維持
 * - GitHub設定APIがHTMLを返してもツリーを止めない
 * - GitHub設定エラーを console.error ではなく warning にする
 * - get_tree の poc / published に対応
 * ========================================================== */

const APP_BASE_PATH_VALUE =
    window.__GOJACIC_APP_BASE_PATH__ ||
    document
        .querySelector('meta[name="gojacic-base-path"]')
        ?.getAttribute('content') ||
    '/';

window.__GOJACIC_APP_BASE_PATH__ =
    APP_BASE_PATH_VALUE;


/* ==========================================================
 * State
 * ========================================================== */

let currentPath = '';
let currentIsPublic = false;
let currentIsMock = false;

let isSaving = false;

let contextMenuPath = '';
let contextMenuIsPublic = false;

let draggedPath = '';


/* ==========================================================
 * DOM
 * ========================================================== */

const $ = id =>
    document.getElementById(id);

const editorTextArea =
    $('editor-content');

const lineNumbersDiv =
    $('line-numbers');

const btnSave =
    $('btn-save-current') ||
    $('btn-save');


/* ==========================================================
 * 共通
 * ========================================================== */

function normalizePath(path) {
    return String(path ?? '')
        .replace(/\\/g, '/')
        .replace(/^\/+|\/+$/g, '');
}


function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


function setButtonVisible(
    id,
    visible
) {
    const button = $(id);

    if (button) {
        button.style.display =
            visible ? '' : 'none';
    }
}


/* ==========================================================
 * Loading
 * ========================================================== */

function startBlocking(
    message = '処理中...'
) {
    const overlay =
        $('loading-overlay');

    const text =
        $('loading-text');

    if (!overlay) {
        return;
    }

    if (text) {
        text.textContent =
            message;
    }

    overlay.classList.add(
        'visible'
    );
}


function stopBlocking() {
    const overlay =
        $('loading-overlay');

    if (overlay) {
        overlay.classList.remove(
            'visible'
        );
    }
}


/* ==========================================================
 * Line Numbers
 * ========================================================== */

function updateLineNumbers() {
    if (
        !editorTextArea ||
        !lineNumbersDiv
    ) {
        return;
    }

    const lines =
        editorTextArea.value
            .split('\n')
            .length;

    lineNumbersDiv.textContent =
        Array.from(
            {
                length: lines
            },
            (_, index) =>
                index + 1
        ).join('\n');
}


/* ==========================================================
 * API
 * ========================================================== */

async function apiCall(
    action,
    data = {}
) {
    const response =
        await fetch(
            `?api=${encodeURIComponent(action)}`,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/json',
                    'Accept':
                        'application/json'
                },
                body:
                    JSON.stringify(data)
            }
        );

    const text =
        await response.text();

    let result;

    try {
        result =
            text
                ? JSON.parse(text)
                : {};
    } catch (error) {

        /*
         * PHPがHTMLを返した場合。
         *
         * GitHub設定など任意のAPIでは
         * 呼び出し側で握りつぶせるよう、
         * エラー内容を維持してthrowする。
         */
        throw new Error(
            `JSONではない応答を受信しました: ${text.slice(0, 300)}`
        );
    }

    if (!response.ok) {
        throw new Error(
            result?.error ||
            `HTTP ${response.status}`
        );
    }

    return result;
}


/* ==========================================================
 * Editor
 * ========================================================== */

function setEditorContent(
    content = ''
) {
    if (!editorTextArea) {
        return;
    }

    editorTextArea.value =
        content;

    updateLineNumbers();
}


function getEditorContent() {
    return editorTextArea
        ? editorTextArea.value
        : '';
}


if (editorTextArea) {

    editorTextArea.addEventListener(
        'input',
        () => {
            updateLineNumbers();

            if (
                !currentIsPublic &&
                btnSave
            ) {
                btnSave.style.display =
                    'inline-block';
            }
        }
    );


    editorTextArea.addEventListener(
        'scroll',
        () => {
            if (lineNumbersDiv) {
                lineNumbersDiv.scrollTop =
                    editorTextArea.scrollTop;
            }
        }
    );


    editorTextArea.addEventListener(
        'keydown',
        event => {

            if (
                (event.ctrlKey ||
                    event.metaKey) &&
                event.key.toLowerCase() ===
                    's'
            ) {
                event.preventDefault();

                if (
                    typeof saveFile ===
                    'function'
                ) {
                    saveFile();
                }
            }
        }
    );


    updateLineNumbers();
}


/* ==========================================================
 * GitHub Settings DOM
 * ========================================================== */

function githubElement(key) {
    return (
        $(`gh-${key}`) ||
        $(`github-${key}`) ||
        $(`github_${key}`)
    );
}


function githubValue(key) {
    const element =
        githubElement(key);

    return element?.value?.trim() ||
        '';
}


function githubRawValue(key) {
    const element =
        githubElement(key);

    return element?.value || '';
}


function githubChecked(key) {
    const element =
        githubElement(key);

    return Boolean(
        element?.checked
    );
}


function setGithubValue(
    key,
    value = ''
) {
    const element =
        githubElement(key);

    if (element) {
        element.value =
            value ?? '';
    }
}


function setGithubChecked(
    key,
    value
) {
    const element =
        githubElement(key);

    if (element) {
        element.checked =
            Boolean(value);
    }
}


/* ==========================================================
 * GitHub Settings
 *
 * ここは重要。
 *
 * 現在のPHPが github_settings_get APIを
 * JSONとして返さない場合、
 * apiCall() はHTMLを検出してErrorを投げる。
 *
 * そのエラーをここで受け止め、
 * ツリー読み込みには影響させない。
 * ========================================================== */

async function loadGithubSettings(
    targetPath = ''
) {
    const status =
        $('github-settings-status') ||
        $('github_settings_status');

    try {

        const data =
            await apiCall(
                'github_settings_get',
                {
                    path:
                        targetPath ||
                        currentPath ||
                        ''
                }
            );


        if (
            !data ||
            typeof data !== 'object'
        ) {
            throw new Error(
                'GitHub設定APIから不正な応答が返されました。'
            );
        }


        if (!data.success) {
            throw new Error(
                data.error ||
                'GitHub設定の取得に失敗しました。'
            );
        }


        setGithubValue(
            'owner',
            data.owner || ''
        );


        setGithubValue(
            'repo',
            data.repo || ''
        );


        setGithubValue(
            'branch',
            data.branch ||
                'main'
        );


        setGithubChecked(
            'proxy-enabled',
            data.proxy_enabled
        );


        setGithubValue(
            'proxy-host',
            data.proxy_host || ''
        );


        setGithubValue(
            'proxy-port',
            data.proxy_port || ''
        );


        setGithubChecked(
            'app-enabled',
            data.enabled
        );


        const token =
            githubElement('token');


        if (token) {

            token.value = '';


            if (data.hasToken) {

                token.placeholder =
                    '（設定済み：変更時のみ入力）';

            } else {

                token.placeholder =
                    'GitHub Token';
            }
        }


        if (status) {

            if (data.hasToken) {

                status.textContent =
                    data.app_name
                        ? `✓ [${data.app_name}] の設定を読み込みました`
                        : '✓ GitHub設定を読み込みました';

                status.style.color =
                    '#28a745';

            } else {

                status.textContent =
                    'GitHub Tokenが設定されていません。';

                status.style.color =
                    '#666';
            }
        }


        return data;

    } catch (error) {

        /*
         * ここではconsole.errorを使わない。
         *
         * GitHub設定APIが存在しない、
         * またはHTMLを返す環境でも
         * ツリー自体は正常に表示させる。
         */
        console.warn(
            'GitHub設定読み込みをスキップ:',
            error.message ||
                error
        );


        if (status) {

            status.textContent =
                'GitHub設定は利用できません。';

            status.style.color =
                '#999';
        }


        return null;
    }
}


async function loadGithubSettingsSafe(
    targetPath = ''
) {
    try {

        return await loadGithubSettings(
            targetPath
        );

    } catch (error) {

        console.warn(
            'GitHub設定取得をスキップ:',
            error
        );

        return null;
    }
}


/* ==========================================================
 * Tree Options
 * ========================================================== */

function treeOptions(options) {

    if (
        typeof options ===
        'boolean'
    ) {
        return {
            isPublic: options
        };
    }

    return options || {};
}


function childNodes(node) {

    return Array.isArray(
        node?.children
    )
        ? node.children
        : [];
}


function isAppNode(node) {
    return (
        node?.type === 'app'
    );
}


function isMockOnly(node) {

    if (
        node?.status === 'mock' ||
        node?.isMock === true ||
        /\/mock$/i.test(
            node?.path || ''
        )
    ) {
        return true;
    }


    const children =
        childNodes(node);


    const hasDraft =
        children.some(
            child =>
                child?.name ===
                    'draft' ||
                /\/draft$/i.test(
                    child?.path || ''
                )
        );


    const hasMock =
        children.some(
            child =>
                child?.name ===
                    'mock' ||
                /\/mock$/i.test(
                    child?.path || ''
                )
        );


    return (
        hasMock &&
        !hasDraft
    );
}


/* ==========================================================
 * Tree HTML
 * ========================================================== */

function buildHTML(
    nodes,
    options = {}
) {
    options =
        treeOptions(options);


    if (!Array.isArray(nodes)) {
        return '';
    }


    const isPublic =
        Boolean(
            options.isPublic
        );


    const defaultStatus =
        options.status ||
        (
            isPublic
                ? 'published'
                : 'draft'
        );


    const hidden =
        new Set([
            '.poc',
            'poc',
            'published',
            '.history',
            '.prompt',
            '.github',
            '.harness',
            'spec',
            'index.php',
            'config.json'
        ]);


    return nodes
        .filter(
            node => {

                if (!node) {
                    return false;
                }


                if (
                    hidden.has(
                        node.name
                    )
                ) {
                    return false;
                }


                return true;
            }
        )
        .map(
            node =>
                buildTreeNode(
                    node,
                    isPublic,
                    defaultStatus
                )
        )
        .join('');
}


/* ==========================================================
 * Tree Node
 * ========================================================== */

function buildTreeNode(
    node,
    isPublic,
    defaultStatus
) {
    if (!node) {
        return '';
    }


    const rawPath =
        normalizePath(
            node.path ||
            node.name ||
            ''
        );


    if (!rawPath) {
        return '';
    }


    const displayName =
        node.name ||
        rawPath
            .split('/')
            .pop() ||
        '';


    const children =
        childNodes(node);


    const published =
        isPublic ||
        node.published === true ||
        node.published === 'true' ||
        node.published === 1;


    const nodeIsApp =
        node.type === 'app' ||
        (
            !isPublic &&
            (
                children.length > 0 ||
                !/\/(draft|mock)$/i.test(
                    rawPath
                )
            )
        );


    const isStage =
        /\/(draft|mock)$/i.test(
            rawPath
        );


    let childrenHTML =
        '';


    if (
        !isPublic &&
        nodeIsApp &&
        !isStage
    ) {

        childrenHTML =
            buildAppStages(
                rawPath,
                published,
                children
            );

    } else if (
        children.length
    ) {

        childrenHTML =
            buildHTML(
                children,
                {
                    isPublic,
                    status:
                        defaultStatus
                }
            );
    }


    const badge =
        !isPublic &&
        published
            ? '<span class="published-badge badge-public">🌐公開中</span>'
            : '';


    const icon =
        isPublic
            ? '🌐'
            : (
                nodeIsApp
                    ? '📁'
                    : '📂'
            );


    const type =
        nodeIsApp
            ? 'app-group'
            : (
                node.type ||
                'folder'
            );


    const status =
        isMockOnly(node)
            ? 'mock'
            : defaultStatus;


    const safePath =
        escapeHtml(
            rawPath
        );


    const jsPath =
        JSON.stringify(
            rawPath
        );


    const hasChildren =
        Boolean(
            childrenHTML
        );


    return `
        <details
            data-path="${safePath}"
            data-type="${escapeHtml(type)}"
            data-status="${escapeHtml(status)}"
            data-published="${published}"
            class="${
                nodeIsApp
                    ? 'item-app-group'
                    : 'item-folder'
            }"
            ${
                hasChildren
                    ? 'open'
                    : ''
            }
            ontoggle="typeof saveTreeState === 'function' && saveTreeState()"
        >

            <summary
                draggable="true"
                ondragstart="typeof drag === 'function' && drag(event, ${jsPath})"
                ondragover="typeof allowDrop === 'function' && allowDrop(event)"
                ondragleave="typeof dragLeave === 'function' && dragLeave(event)"
                ondrop="typeof drop === 'function' && drop(event, ${jsPath})"
                oncontextmenu="typeof showContext === 'function' && showContext(event, ${jsPath}, ${published})"
                onclick="typeof selectFolder === 'function' && selectFolder(event, ${jsPath}, ${published})"
            >

                <span
                    style="
                        margin-right:6px;
                        display:inline-block;
                        width:18px;
                        text-align:center;
                    "
                >${icon}</span>

                <span class="item-name">
                    ${escapeHtml(
                        displayName
                    )}
                </span>

                ${badge}

            </summary>

            ${
                childrenHTML
                    ? `
                        <div class="tree-children">
                            ${childrenHTML}
                        </div>
                    `
                    : ''
            }

        </details>
    `;
}


/* ==========================================================
 * App Stages
 * ========================================================== */

function buildAppStages(
    appPath,
    published,
    children
) {
    const normalizedPath =
        normalizePath(
            appPath
        );


    if (!normalizedPath) {
        return '';
    }


    const draftPath =
        `${normalizedPath}/draft`;


    const mockPath =
        `${normalizedPath}/mock`;


    const list =
        Array.isArray(children)
            ? children
            : [];


    const nestedNodes =
        list.filter(
            child =>
                child &&
                child.name !==
                    'draft' &&
                child.name !==
                    'mock' &&
                child.name !==
                    'index.php'
        );


    let html = '';


    html +=
        createStageItem(
            draftPath,
            '🔨',
            '開発中',
            published,
            false
        );


    html +=
        createStageItem(
            mockPath,
            '📐',
            'モック',
            published,
            false
        );


    if (
        nestedNodes.length
    ) {

        html +=
            buildHTML(
                nestedNodes,
                {
                    isPublic: false,
                    status: 'draft'
                }
            );
    }


    return html;
}


/* ==========================================================
 * Stage Item
 * ========================================================== */

function createStageItem(
    path,
    icon,
    label,
    published = false,
    isPublic = false
) {
    const normalized =
        normalizePath(
            path
        );


    if (!normalized) {
        return '';
    }


    const safePath =
        escapeHtml(
            normalized
        );


    const jsPath =
        JSON.stringify(
            normalized
        );


    const status =
        label === 'モック'
            ? 'mock'
            : 'draft';


    return `
        <details
            data-path="${safePath}"
            data-type="app-item"
            data-status="${status}"
            data-published="${Boolean(published)}"
            class="item-app"
            ontoggle="typeof saveTreeState === 'function' && saveTreeState()"
        >

            <summary
                draggable="true"
                ondragstart="typeof drag === 'function' && drag(event, ${jsPath})"
                ondragover="typeof allowDrop === 'function' && allowDrop(event)"
                ondragleave="typeof dragLeave === 'function' && dragLeave(event)"
                ondrop="typeof drop === 'function' && drop(event, ${jsPath})"
                oncontextmenu="typeof showContext === 'function' && showContext(event, ${jsPath}, ${isPublic})"
                onclick="typeof selectFolder === 'function' && selectFolder(event, ${jsPath}, ${isPublic})"
            >

                <span
                    style="
                        margin-right:6px;
                        display:inline-block;
                        width:18px;
                        text-align:center;
                    "
                >${icon}</span>

                <span class="item-name">
                    ${escapeHtml(label)}
                </span>

            </summary>

        </details>
    `;
}


/* ==========================================================
 * Tree Load
 * ========================================================== */

async function loadTrees() {

    try {

        console.log(
            '[GOJACIC] ツリー読み込み開始'
        );


        const data =
            await apiCall(
                'get_tree'
            );


        console.log(
            '[GOJACIC] get_tree response:',
            data
        );


        if (
            !data?.success
        ) {
            throw new Error(
                data?.error ||
                'ツリー取得に失敗しました。'
            );
        }


        /* --------------------------------------------------
         * Public Tree
         * -------------------------------------------------- */

        const publicTree =
            $('tree-pub') ||
            $('tree-public');


        if (publicTree) {

            const published =
                Array.isArray(
                    data.published
                )
                    ? data.published
                    : (
                        Array.isArray(
                            data.public
                        )
                            ? data.public
                            : []
                    );


            publicTree.innerHTML =
                buildHTML(
                    published,
                    {
                        isPublic: true,
                        status:
                            'published'
                    }
                );
        }


        /* --------------------------------------------------
         * Development Tree
         * -------------------------------------------------- */

        const devTree =
            $('tree-dev') ||
            $('tree-draft');


        if (devTree) {

            let apps = [];


            if (
                Array.isArray(
                    data.poc
                )
            ) {

                apps =
                    data.poc;


            } else if (
                Array.isArray(
                    data.tree
                )
            ) {

                apps =
                    data.tree;


            } else {

                const drafts =
                    Array.isArray(
                        data.draft
                    )
                        ? data.draft
                        : [];


                const mocks =
                    Array.isArray(
                        data.mock
                    )
                        ? data.mock
                        : [];


                const map =
                    new Map();


                drafts.forEach(
                    item => {

                        if (!item) {
                            return;
                        }


                        const key =
                            item.path ||
                            item.name;


                        if (key) {
                            map.set(
                                key,
                                item
                            );
                        }
                    }
                );


                mocks.forEach(
                    item => {

                        if (!item) {
                            return;
                        }


                        const key =
                            item.path ||
                            item.name;


                        if (
                            key &&
                            !map.has(key)
                        ) {
                            map.set(
                                key,
                                item
                            );
                        }
                    }
                );


                apps =
                    Array.from(
                        map.values()
                    );
            }


            apps =
                apps.filter(
                    item =>
                        item &&
                        typeof item ===
                            'object'
                );


            apps.sort(
                (a, b) =>
                    String(
                        a?.name || ''
                    ).localeCompare(
                        String(
                            b?.name || ''
                        ),
                        'ja'
                    )
            );


            console.log(
                '[GOJACIC] development tree:',
                apps
            );


            devTree.innerHTML =
                buildHTML(
                    apps,
                    {
                        isPublic: false,
                        status: 'draft'
                    }
                );


            /*
             * データが空だった場合だけ警告。
             * JSエラーにはしない。
             */
            if (
                apps.length === 0
            ) {

                console.warn(
                    '[GOJACIC] 開発ツリーのデータが空です。',
                    data
                );
            }
        }


        /* --------------------------------------------------
         * GitHub設定
         *
         * 失敗してもloadTrees()を失敗させない。
         * -------------------------------------------------- */

        await loadGithubSettingsSafe(
            ''
        );


        /* --------------------------------------------------
         * Tree state
         * -------------------------------------------------- */

        restoreTreeState();

        updateSelectedStyles();


        console.log(
            '[GOJACIC] ツリー読み込み完了'
        );


    } catch (error) {

        console.error(
            '[GOJACIC] ツリー読み込み失敗:',
            error
        );


        const devTree =
            $('tree-dev') ||
            $('tree-draft');


        if (devTree) {

            devTree.innerHTML = `
                <div
                    style="
                        padding:10px;
                        color:#dc3545;
                        font-size:13px;
                    "
                >
                    ツリーを読み込めませんでした。<br>
                    ${escapeHtml(
                        error.message ||
                        String(error)
                    )}
                </div>
            `;
        }
    }
}


/* ==========================================================
 * Tree State
 * ========================================================== */

function saveTreeState() {

    const paths =
        Array.from(
            document.querySelectorAll(
                'details[open][data-path]'
            )
        )
        .map(
            element =>
                element.getAttribute(
                    'data-path'
                )
        )
        .filter(Boolean);


    try {

        localStorage.setItem(
            'treeState_gojacic',
            JSON.stringify(paths)
        );

    } catch (error) {

        console.warn(
            '[GOJACIC] ツリー状態保存失敗:',
            error
        );
    }
}


function restoreTreeState() {

    let paths = [];


    try {

        const saved =
            localStorage.getItem(
                'treeState_gojacic'
            );


        if (saved) {

            const parsed =
                JSON.parse(
                    saved
                );


            if (
                Array.isArray(
                    parsed
                )
            ) {
                paths =
                    parsed;
            }
        }

    } catch (error) {

        console.warn(
            '[GOJACIC] ツリー状態復元失敗:',
            error
        );

        paths = [];
    }


    const pathSet =
        new Set(
            paths.map(
                path =>
                    normalizePath(
                        path
                    )
            )
        );


    document
        .querySelectorAll(
            'details[data-path]'
        )
        .forEach(
            element => {

                const path =
                    normalizePath(
                        element.getAttribute(
                            'data-path'
                        ) || ''
                    );


                if (
                    pathSet.has(
                        path
                    )
                ) {
                    element.open =
                        true;
                }
            }
        );


    updateSelectedStyles();
}


/* ==========================================================
 * Selected Style
 * ========================================================== */

function updateSelectedStyles() {

    document
        .querySelectorAll(
            'summary.active,' +
            'summary.selected'
        )
        .forEach(
            element => {

                element.classList.remove(
                    'active',
                    'selected'
                );
            }
        );


    if (!currentPath) {
        return;
    }


    const targetPath =
        normalizePath(
            currentPath
        );


    document
        .querySelectorAll(
            'details[data-path]'
        )
        .forEach(
            details => {

                const path =
                    normalizePath(
                        details.dataset.path ||
                        ''
                    );


                if (
                    path ===
                    targetPath
                ) {

                    const summary =
                        details.querySelector(
                            ':scope > summary'
                        );


                    if (summary) {

                        summary.classList.add(
                            'active',
                            'selected'
                        );
                    }


                    let parent =
                        details.parentElement;


                    while (
                        parent
                    ) {

                        if (
                            parent.matches?.(
                                'details[data-path]'
                            )
                        ) {
                            parent.open =
                                true;
                        }


                        parent =
                            parent.parentElement;
                    }
                }
            }
        );
}


/* ==========================================================
 * Preview
 * ========================================================== */

function updatePreviewFrame(
    rawPath,
    isPublic
) {
    const frame =
        $('preview-frame');


    if (
        !frame ||
        !rawPath
    ) {
        return;
    }


    let path =
        normalizePath(
            rawPath
        );


    if (
        !isPublic &&
        !/(\/draft|\/mock)$/i.test(
            path
        )
    ) {
        path += '/draft';
    }


    const encodedPath =
        path
            .split('/')
            .map(
                segment =>
                    encodeURIComponent(
                        segment
                    )
            )
            .join('/');


    const base =
        window.__GOJACIC_APP_BASE_PATH__ ||
        APP_BASE_PATH_VALUE ||
        '/';


    try {

        frame.src =
            new URL(
                `${encodedPath}/`,
                new URL(
                    base,
                    window.location.origin
                )
            ).href;

    } catch (error) {

        console.error(
            'プレビューURL生成エラー:',
            error
        );
    }
}


function showWelcomeMessage() {

    const frame =
        $('preview-frame');

    const welcome =
        $('welcome-message-area');


    if (frame) {
        frame.style.display =
            'none';
    }


    if (welcome) {
        welcome.style.display =
            'flex';
    }
}


function hideWelcomeMessage() {

    const frame =
        $('preview-frame');

    const welcome =
        $('welcome-message-area');


    if (frame) {
        frame.style.display =
            'block';
    }


    if (welcome) {
        welcome.style.display =
            'none';
    }
}


/* ==========================================================
 * iframe Message
 * ========================================================== */

if (
    !window.__gojacicMessageListener
) {

    window.addEventListener(
        'message',
        event => {

            if (
                event.data?.type ===
                'SELECT_APP_PATH'
            ) {

                selectFolder(
                    null,
                    event.data.path,
                    Boolean(
                        event.data.isPublic
                    )
                );
            }
        }
    );


    window.__gojacicMessageListener =
        true;
}


/* ==========================================================
 * Path Resolver
 * ========================================================== */

function resolveFolderPath(
    target,
    fallback = ''
) {

    if (
        typeof target ===
            'string' &&
        target.trim()
    ) {
        return target.trim();
    }


    if (
        target?.target
    ) {

        return resolveFolderPath(
            target.target,
            fallback
        );
    }


    if (
        target instanceof
        HTMLElement
    ) {

        const element =
            target.closest(
                '[data-path]'
            ) ||
            target;


        return (
            element.dataset.path ||
            element.getAttribute(
                'data-path'
            ) ||
            fallback
        );
    }


    return fallback;
}


/* ==========================================================
 * Select Folder
 * ========================================================== */

async function selectFolder(
    eventOrTarget,
    targetPath = null,
    isPublic = false
) {

    const path =
        normalizePath(
            resolveFolderPath(
                targetPath ||
                eventOrTarget
            )
        );


    if (!path) {

        console.warn(
            'selectFolder: 有効なパスがありません'
        );

        return;
    }


    currentPath =
        path;


    currentIsPublic =
        Boolean(
            isPublic
        );


    currentIsMock =
        /\/mock$/i.test(
            path
        );


    document
        .querySelectorAll(
            'summary.active,' +
            'summary.selected'
        )
        .forEach(
            element =>
                element.classList.remove(
                    'active',
                    'selected'
                )
        );


    let activeElement =
        null;


    document
        .querySelectorAll(
            'details[data-path]'
        )
        .forEach(
            details => {

                const detailPath =
                    normalizePath(
                        details.dataset.path ||
                        ''
                    );


                if (
                    detailPath ===
                    path
                ) {
                    activeElement =
                        details;
                }
            }
        );


    if (activeElement) {

        activeElement
            .querySelector(
                ':scope > summary'
            )
            ?.classList.add(
                'active',
                'selected'
            );


        let parent =
            activeElement.parentElement;


        while (
            parent
        ) {

            if (
                parent.matches?.(
                    'details[data-path]'
                )
            ) {
                parent.open =
                    true;
            }


            parent =
                parent.parentElement;
        }
    }


    const rootPath =
        path.replace(
            /\/(draft|mock)$/i,
            ''
        );


    if (
        typeof window
            .setAppGitHubSyncTarget ===
        'function'
    ) {

        window.setAppGitHubSyncTarget(
            rootPath
        );
    }


    const pathDisplay =
        $('current-path-display');


    if (pathDisplay) {
        pathDisplay.textContent =
            path;
    }


    const preview =
        $('preview-frame');


    const welcome =
        $('welcome-message-area');


    const overlay =
        $('folder-status-overlay');


    const isExecutable =
        /\/(draft|mock)$/i.test(
            path
        );


    if (!isExecutable) {

        showFolderStatus(
            path,
            currentIsPublic,
            preview,
            welcome,
            overlay
        );


        updateActionButtons(
            path,
            currentIsPublic
        );


        return;
    }


    if (overlay) {
        overlay.style.display =
            'none';
    }


    if (welcome) {
        welcome.style.display =
            'none';
    }


    updateActionButtons(
        path,
        currentIsPublic
    );


    if (
        typeof loadPromptHistory ===
        'function'
    ) {

        try {

            await loadPromptHistory(
                path
            );

        } catch (error) {

            console.warn(
                '履歴読み込みエラー:',
                error
            );
        }
    }


    if (preview) {

        preview.style.display =
            'block';


        updatePreviewFrame(
            path,
            currentIsPublic
        );
    }
}


/* ==========================================================
 * Folder Status
 * ========================================================== */

function showFolderStatus(
    path,
    isPublic,
    preview,
    welcome,
    overlay
) {

    if (preview) {
        preview.style.display =
            'none';
    }


    if (welcome) {
        welcome.style.display =
            'none';
    }


    if (!overlay) {
        return;
    }


    overlay.style.display =
        'flex';


    const title =
        overlay.querySelector(
            '[data-folder-status-title]'
        );


    const message =
        overlay.querySelector(
            '[data-folder-status-message]'
        );


    if (title) {

        title.textContent =
            isPublic
                ? '公開フォルダ'
                : 'フォルダ';
    }


    if (message) {

        message.textContent =
            path
                ? `${path} を選択しています`
                : 'フォルダを選択しています';
    }
}


/* ==========================================================
 * Action Buttons
 * ========================================================== */

function updateActionButtons(
    path = currentPath,
    isPublic = currentIsPublic
) {

    const normalized =
        normalizePath(
            path
        );


    const isDraft =
        /\/draft$/i.test(
            normalized
        );


    const isMock =
        /\/mock$/i.test(
            normalized
        );


    const isAppItem =
        isDraft ||
        isMock;


    setButtonVisible(
        'btn-save',
        !isPublic &&
            isAppItem
    );


    setButtonVisible(
        'btn-save-current',
        !isPublic &&
            isAppItem
    );


    setButtonVisible(
        'btn-publish',
        !isPublic &&
            isDraft
    );


    setButtonVisible(
        'btn-edit-mock',
        !isPublic &&
            isMock
    );


    setButtonVisible(
        'btn-github-sync',
        isPublic ||
            isAppItem
    );
}


/* ==========================================================
 * File Content
 * ========================================================== */

async function loadFileContent(
    path
) {

    if (!path) {
        return null;
    }


    try {

        const data =
            await apiCall(
                'get_file',
                {
                    path
                }
            );


        if (
            !data?.success
        ) {

            throw new Error(
                data?.error ||
                'ファイル取得に失敗しました。'
            );
        }


        return data;

    } catch (error) {

        console.error(
            'ファイル取得エラー:',
            error
        );


        alert(
            `ファイルを取得できませんでした。\n${error.message}`
        );


        return null;
    }
}


/* ==========================================================
 * Save
 * ========================================================== */

async function saveFile() {

    if (isSaving) {
        return;
    }


    if (!currentPath) {

        alert(
            '保存するフォルダを選択してください。'
        );

        return;
    }


    if (currentIsPublic) {

        alert(
            '公開側のファイルはここから保存できません。'
        );

        return;
    }


    isSaving = true;


    startBlocking(
        '保存中...'
    );


    try {

        const data =
            await apiCall(
                'save_file',
                {
                    path:
                        currentPath,
                    content:
                        getEditorContent()
                }
            );


        if (
            !data?.success
        ) {

            throw new Error(
                data?.error ||
                '保存に失敗しました。'
            );
        }


        const status =
            $('prompt-status');


        if (status) {

            status.textContent =
                '✓ 保存しました';

            status.style.color =
                '#28a745';
        }


        await loadTrees();


    } catch (error) {

        console.error(
            '保存エラー:',
            error
        );


        alert(
            `保存に失敗しました。\n${error.message}`
        );


    } finally {

        isSaving = false;

        stopBlocking();
    }
}


/* ==========================================================
 * Prompt History
 * ========================================================== */

function getHistoryElements() {

    return {
        modal:
            $('history-modal'),

        list:
            $('history-list') ||
            $('prompt-history-list'),

        status:
            $('history-status')
    };
}


function closeHistoryModal() {

    const {
        modal
    } =
        getHistoryElements();


    if (modal) {

        modal.style.display =
            'none';
    }
}


async function loadPromptHistory(
    path = currentPath
) {

    if (!path) {
        return [];
    }


    const {
        list,
        status
    } =
        getHistoryElements();


    try {

        const data =
            await apiCall(
                'get_prompt_history',
                {
                    path
                }
            );


        if (
            !data?.success
        ) {

            throw new Error(
                data?.error ||
                '履歴取得に失敗しました。'
            );
        }


        const history =
            Array.isArray(
                data.history
            )
                ? data.history
                : [];


        if (list) {

            list.innerHTML =
                history.length
                    ? history
                        .map(
                            item =>
                                createHistoryItem(
                                    item,
                                    path
                                )
                        )
                        .join('')
                    : '<div class="empty-history">履歴はありません。</div>';
        }


        if (status) {

            status.textContent =
                `${history.length}件`;
        }


        return history;


    } catch (error) {

        console.warn(
            '履歴取得エラー:',
            error
        );


        if (list) {

            list.innerHTML =
                '<div class="empty-history">履歴を取得できませんでした。</div>';
        }


        return [];
    }
}


function createHistoryItem(
    item,
    path
) {

    const filename =
        item?.filename ||
        item?.name ||
        '';


    const title =
        item?.title ||
        item?.memo ||
        filename;


    const date =
        item?.date ||
        item?.created_at ||
        '';


    const jsFilename =
        JSON.stringify(
            filename
        );


    return `
        <button
            type="button"
            class="history-item"
            onclick="selectPromptHistory(${jsFilename})"
            data-path="${escapeHtml(path)}"
        >
            <span class="history-item-title">
                ${escapeHtml(title)}
            </span>

            ${
                date
                    ? `
                        <span class="history-item-date">
                            ${escapeHtml(date)}
                        </span>
                    `
                    : ''
            }
        </button>
    `;
}


async function selectPromptHistory(
    filename
) {

    if (!filename) {
        return;
    }


    try {

        const data =
            await apiCall(
                'get_prompt_content',
                {
                    path:
                        currentPath,
                    filename
                }
            );


        if (
            !data?.success
        ) {

            throw new Error(
                data?.error ||
                '履歴の読み込みに失敗しました。'
            );
        }


        setEditorContent(
            data.content || ''
        );


        if (
            typeof openModal ===
            'function'
        ) {

            openModal(
                data.memo ||
                    filename,
                data.content ||
                    ''
            );
        }


    } catch (error) {

        console.error(
            '履歴内容取得エラー:',
            error
        );


        alert(
            `履歴を読み込めませんでした。\n${error.message}`
        );
    }
}


/* ==========================================================
 * Context Menu
 * ========================================================== */

function getContextMenu() {

    return (
        $('context-menu') ||
        $('file-context-menu')
    );
}


function hideContextMenu() {

    const menu =
        getContextMenu();


    if (menu) {

        menu.style.display =
            'none';
    }


    contextMenuPath =
        '';

    contextMenuIsPublic =
        false;
}


function showContext(
    event,
    path,
    isPublic = false
) {

    event?.preventDefault();
    event?.stopPropagation();


    const menu =
        getContextMenu();


    if (!menu) {
        return;
    }


    contextMenuPath =
        String(
            path || ''
        );


    contextMenuIsPublic =
        Boolean(
            isPublic
        );


    menu.style.display =
        'block';


    const rect =
        menu.getBoundingClientRect();


    const x =
        event?.clientX || 0;


    const y =
        event?.clientY || 0;


    const left =
        Math.min(
            x,
            window.innerWidth -
                rect.width -
                8
        );


    const top =
        Math.min(
            y,
            window.innerHeight -
                rect.height -
                8
        );


    menu.style.left =
        `${Math.max(8, left)}px`;


    menu.style.top =
        `${Math.max(8, top)}px`;
}


document.addEventListener(
    'click',
    event => {

        const menu =
            getContextMenu();


        if (
            menu &&
            !menu.contains(
                event.target
            )
        ) {

            hideContextMenu();
        }
    }
);


/* ==========================================================
 * Drag & Drop
 * ========================================================== */

function drag(
    event,
    path
) {

    draggedPath =
        normalizePath(
            path
        );


    if (
        event?.dataTransfer
    ) {

        event.dataTransfer.effectAllowed =
            'move';

        event.dataTransfer.setData(
            'text/plain',
            draggedPath
        );
    }
}


function allowDrop(event) {

    event?.preventDefault();


    if (
        event?.dataTransfer
    ) {

        event.dataTransfer.dropEffect =
            'move';
    }


    event?.currentTarget
        ?.classList.add(
            'drag-over'
        );
}


function dragLeave(event) {

    event?.currentTarget
        ?.classList.remove(
            'drag-over'
        );
}


async function drop(
    event,
    targetPath
) {

    event?.preventDefault();


    event?.currentTarget
        ?.classList.remove(
            'drag-over'
        );


    const sourcePath =
        normalizePath(
            draggedPath ||
            event?.dataTransfer?.getData(
                'text/plain'
            ) ||
            ''
        );


    const destinationPath =
        normalizePath(
            targetPath ||
            ''
        );


    if (
        !sourcePath ||
        !destinationPath ||
        sourcePath ===
            destinationPath
    ) {
        return;
    }


    try {

        const data =
            await apiCall(
                'move',
                {
                    source:
                        sourcePath,
                    destination:
                        destinationPath
                }
            );


        if (
            !data?.success
        ) {

            throw new Error(
                data?.error ||
                '移動に失敗しました。'
            );
        }


        await loadTrees();


    } catch (error) {

        console.error(
            '移動エラー:',
            error
        );


        alert(
            `移動に失敗しました。\n${error.message}`
        );
    }


    draggedPath =
        '';
}


/* ==========================================================
 * Init
 * ========================================================== */

function initGojacicManager() {

    updateLineNumbers();


    /*
     * ツリーを最優先で読み込む。
     *
     * GitHub設定が失敗しても
     * loadTrees()内で握りつぶすため、
     * ツリーは表示される。
     */
    loadTrees()
        .catch(
            error =>
                console.error(
                    '[GOJACIC] 初期化エラー:',
                    error
                )
        );
}


if (
    document.readyState ===
    'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        initGojacicManager,
        {
            once: true
        }
    );

} else {

    initGojacicManager();
}


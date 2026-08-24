<?php
/*
============================================================
GUARD COMMENT
============================================================

【SYSTEM】
System Name:
    アンケート管理システム

File:
    index.php

【STORAGE】
Storage Directory Name:
    survey_storage_directory

Storage File Name:
    survey_storage_file

Admin Session Name:
    survey_admin_session_v1

PHP Constants:
    SURVEY_STORAGE_DIRECTORY
    SURVEY_STORAGE_FILE
    SURVEY_ADMIN_SESSION

【JSON TOP KEYS】
    surveys
    responses
    customers
    settings
    mail_logs

【FIXED VALUES】
survey status:
    draft
    active
    ended

numbering mode:
    global
    group

question type:
    single
    multiple
    text

source:
    kintone
    web

answer status:
    unanswered
    answered

kintone status:
    unregistered
    registered

template type:
    initial
    reminder

send type:
    initial
    reminder
    resend

【API JSON KEYS】
    properties
    records
    label
    code
    type
    message
    ok
    fields

【DOM IDS】
    app
    csrf_token
    survey_title
    survey_start_at
    survey_end_at
    survey_numbering_mode
    question_editor
    preview_modal
    preview_content
    response_modal
    response_detail
    response_filter
    response_table
    customer_filter
    customer_table
    select_all
    mail_subject
    mail_body
    template_type
    settings_form
    settings_json
    setting_subdomain
    setting_app_id
    setting_login_name
    setting_password
    setting_proxy
    setting_ssl_verify
    field_message

【ADMIN AUTH - TEST MODE】
    管理者ログイン画面は実装しない。
    index.phpへアクセスした時点で管理者として扱う。

    固定セッション名は維持する。
    SURVEY_ADMIN_SESSION
    survey_admin_session_v1

    パスワード保存・ログインフォーム・
    パスワードリセットはテスト版では使用しない。

【JAVASCRIPT NAMESPACE】
    window.App

【FIXED JAVASCRIPT REFERENCES】
    App.init
    App.initSortable
    App.actions.addGroup
    App.actions.addQuestion
    App.actions.fetchKintoneFields

【POST / GET PARAMETERS】
    action
    survey_id
    customer_id
    response_id
    keyword
    status_filter
    sort
    survey_json
    settings_json
    csrf_token
    recipient_ids
    mail_subject
    mail_body
    template_type
    app_id
    response_token
    public_token
    response_session
    response_data

【ATTRIBUTES / VALUES】
    required
    deleted
    general_response_enabled
    public_token
    web_uuid
    merged_to

============================================================
END GUARD COMMENT
============================================================
*/

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 基本設定
|--------------------------------------------------------------------------
*/

const SURVEY_STORAGE_DIRECTORY = __DIR__ . '/survey_storage';
const SURVEY_STORAGE_FILE      = SURVEY_STORAGE_DIRECTORY . '/survey_data.json';

/*
 * 固定名称：
 * survey_admin_session_v1
 */
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

/*
|--------------------------------------------------------------------------
| テスト版管理者アクセス
|--------------------------------------------------------------------------
|
| 本版ではログイン画面を表示しない。
| index.phpへアクセスした時点で管理者として扱う。
|
*/

$_SESSION['survey_admin_authenticated'] = true;

/*
|--------------------------------------------------------------------------
| 初期JSON構造
|--------------------------------------------------------------------------
*/

function survey_app_default_storage(): array
{
    return [
        'surveys'   => [],
        'responses' => [],
        'customers' => [],
        'settings'  => [],
        'mail_logs' => [],
    ];
}

/*
|--------------------------------------------------------------------------
| Storage directory
|--------------------------------------------------------------------------
*/

function survey_app_ensure_storage(): void
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
    }

    if (!file_exists(SURVEY_STORAGE_FILE)) {
        survey_app_atomic_save(
            survey_app_default_storage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| JSON読み込み
|--------------------------------------------------------------------------
*/

function survey_app_load_storage(): array
{
    survey_app_ensure_storage();

    $json = file_get_contents(SURVEY_STORAGE_FILE);

    if ($json === false || trim($json) === '') {
        return survey_app_default_storage();
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        return survey_app_default_storage();
    }

    $defaults = survey_app_default_storage();

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

/*
|--------------------------------------------------------------------------
| JSON保存
|--------------------------------------------------------------------------
*/

function survey_app_atomic_save(array $data): bool
{
    survey_app_ensure_storage_directory();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    $fp = fopen($tmp, 'wb');

    if ($fp === false) {
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, SURVEY_STORAGE_FILE)) {
            @unlink($tmp);
            return false;
        }

        return true;

    } catch (Throwable $e) {
        fclose($fp);
        @unlink($tmp);
        return false;
    }
}

function survey_app_ensure_storage_directory(): void
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
    }
}

/*
|--------------------------------------------------------------------------
| ID / Token
|--------------------------------------------------------------------------
*/

function survey_app_uuid(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(16));
}

function survey_app_token(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

function survey_app_csrf_token(): string
{
    if (empty($_SESSION['survey_csrf_token'])) {
        $_SESSION['survey_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['survey_csrf_token'];
}

function survey_app_verify_csrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';

    return is_string($token)
        && hash_equals(
            survey_app_csrf_token(),
            $token
        );
}

/*
|--------------------------------------------------------------------------
| JSON API
|--------------------------------------------------------------------------
*/

function survey_app_json_response(
    bool $ok,
    array $data = [],
    int $status = 200
): never {
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(
            ['ok' => $ok],
            $data
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Survey CRUD
|--------------------------------------------------------------------------
*/

function survey_app_save_survey(): never
{
    if (!survey_app_verify_csrf()) {
        survey_app_json_response(
            false,
            ['message' => 'CSRF token is invalid.'],
            403
        );
    }

    $raw = $_POST['survey_json'] ?? '';

    if (!is_string($raw) || $raw === '') {
        survey_app_json_response(
            false,
            ['message' => 'survey_json is required.'],
            400
        );
    }

    $survey = json_decode($raw, true);

    if (!is_array($survey)) {
        survey_app_json_response(
            false,
            ['message' => 'Invalid survey JSON.'],
            400
        );
    }

    $required = [
        'id',
        'title',
        'start_at',
        'end_at',
        'status',
        'numbering_mode',
        'groups',
        'general_response_enabled',
        'public_token',
    ];

    foreach ($required as $key) {
        if (!array_key_exists($key, $survey)) {
            survey_app_json_response(
                false,
                ['message' => 'Missing survey field: ' . $key],
                400
            );
        }
    }

    if (!in_array(
        $survey['status'],
        ['draft', 'active', 'ended'],
        true
    )) {
        survey_app_json_response(
            false,
            ['message' => 'Invalid survey status.'],
            400
        );
    }

    if (!in_array(
        $survey['numbering_mode'],
        ['global', 'group'],
        true
    )) {
        survey_app_json_response(
            false,
            ['message' => 'Invalid numbering_mode.'],
            400
        );
    }

    $now = date('c');

    $storage = survey_app_load_storage();

    $found = false;

    foreach ($storage['surveys'] as &$existing) {
        if (
            isset($existing['id']) &&
            $existing['id'] === $survey['id']
        ) {
            $survey['created_at'] =
                $existing['created_at'] ?? $now;

            $survey['updated_at'] = $now;

            $existing = $survey;

            $found = true;
            break;
        }
    }

    unset($existing);

    if (!$found) {
        $survey['created_at'] = $now;
        $survey['updated_at'] = $now;

        if (
            empty($survey['public_token']) &&
            !empty($survey['general_response_enabled'])
        ) {
            $survey['public_token'] =
                survey_app_token();
        }

        $storage['surveys'][] = $survey;
    }

    if (!survey_app_atomic_save($storage)) {
        survey_app_json_response(
            false,
            ['message' => 'JSON storage save failed.'],
            500
        );
    }

    survey_app_json_response(
        true,
        ['survey' => $survey]
    );
}

/*
|--------------------------------------------------------------------------
| Survey duplicate
|--------------------------------------------------------------------------
*/

function survey_app_duplicate_survey(): never
{
    if (!survey_app_verify_csrf()) {
        survey_app_json_response(
            false,
            ['message' => 'CSRF token is invalid.'],
            403
        );
    }

    $id = $_POST['survey_id'] ?? '';

    $storage = survey_app_load_storage();

    foreach ($storage['surveys'] as $survey) {

        if (($survey['id'] ?? '') !== $id) {
            continue;
        }

        $copy = $survey;

        $copy['id'] =
            survey_app_uuid('survey');

        $copy['status'] = 'draft';
        $copy['deleted'] = false;

        $copy['created_at'] = date('c');
        $copy['updated_at'] = date('c');

        $copy['public_token'] =
            survey_app_token();

        /*
         * 回答・送信履歴は複製しない。
         * survey_data.json内では
         * response/mail_logsを変更しない。
         */

        $storage['surveys'][] = $copy;

        if (!survey_app_atomic_save($storage)) {
            survey_app_json_response(
                false,
                ['message' => 'Duplicate save failed.'],
                500
            );
        }

        survey_app_json_response(
            true,
            ['survey' => $copy]
        );
    }

    survey_app_json_response(
        false,
        ['message' => 'Survey not found.'],
        404
    );
}

/*
|--------------------------------------------------------------------------
| 初期化
|--------------------------------------------------------------------------
*/

survey_app_ensure_storage();

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'save_survey':
        survey_app_save_survey();
        break;

    case 'duplicate_survey':
        survey_app_duplicate_survey();
        break;

    /*
     * その他のAPI：
     *
     * kintone
     * SMTP
     * customer sync
     * response
     * CSV
     * mail
     * aggregation
     * survey stop/resume/delete
     *
     * をここへ追加する。
     */
}

/*
|--------------------------------------------------------------------------
| HTML
|--------------------------------------------------------------------------
*/
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>アンケート管理システム</title>

<!-- 開発・検証用Tailwind CDN -->
<script src="https://cdn.tailwindcss.com"></script>

<!-- 開発・検証用SortableJS CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

</head>

<body class="bg-gray-100 text-gray-900">

<input
    type="hidden"
    id="csrf_token"
    value="<?= htmlspecialchars(
        survey_app_csrf_token(),
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>

<div id="app"></div>

<script>
'use strict';

/*
|--------------------------------------------------------------------------
| Application Namespace
|--------------------------------------------------------------------------
*/

window.App = {

    state: {
        initialized: false,

        csrfToken:
            document.getElementById('csrf_token')?.value || '',

        screen: 'list',

        surveys: [],

        currentSurvey: null,

        keyword: '',

        statusFilter: '',

        sort: 'updated_desc',

        loading: false,

        response: {
            mode: null,
            survey: null,
            customer: null,
            answers: {},
            session: null
        }
    },

    render: {},

    actions: {},

    api: {},

    utils: {},

    init: function () {

        if (this.state.initialized) {
            return;
        }

        this.state.initialized = true;

        this.render.layout();

        this.actions.loadSurveys();

        this.bindGlobalEvents();
    },

    initSortable: function () {

        if (typeof Sortable === 'undefined') {
            return;
        }

        const groupList =
            document.querySelector('#question_editor');

        if (!groupList) {
            return;
        }

        /*
         * グループSortable
         */
        new Sortable(groupList, {
            animation: 150,
            handle: '.group-handle',
            onEnd: () => {
                this.actions.renumberQuestions();
            }
        });

        /*
         * 各質問リスト
         */
        document
            .querySelectorAll('.question-list')
            .forEach((element) => {

                new Sortable(element, {
                    group: 'survey_questions',
                    animation: 150,

                    onEnd: (event) => {

                        this.actions.moveQuestion(
                            event
                        );

                        this.actions.renumberQuestions();
                    }
                });

            });
    },

    bindGlobalEvents: function () {

        document.addEventListener(
            'keydown',
            (event) => {

                if (event.key === 'Escape') {
                    this.actions.closeModal();
                }

            }
        );
    }
};

/*
|--------------------------------------------------------------------------
| Render
|--------------------------------------------------------------------------
*/

App.render.layout = function () {

    const app =
        document.getElementById('app');

    if (!app) {
        return;
    }

    app.innerHTML = `
        <div class="min-h-screen">

            <header class="sticky top-0 z-40
                           bg-white border-b">

                <div class="max-w-7xl mx-auto
                            px-4 py-3
                            flex items-center
                            justify-between">

                    <div class="font-bold">
                        アンケート管理システム
                    </div>

                    <nav class="flex gap-2">

                        <button
                            class="px-3 py-2 rounded
                                   hover:bg-gray-100"
                            onclick="
                                App.actions.showSurveyList()
                            ">
                            アンケート一覧
                        </button>

                        <button
                            class="px-3 py-2 rounded
                                   hover:bg-gray-100"
                            onclick="
                                App.actions.showSettings()
                            ">
                            kintone連携設定
                        </button>

                        <!--
                         * テスト版ではログアウト不要。
                         * 将来の認証実装用に領域だけ保持。
                         -->
                        <button
                            class="px-3 py-2 rounded
                                   text-gray-400"
                            onclick="
                                App.actions.testLogout()
                            ">
                            ログアウト
                        </button>

                    </nav>

                </div>

            </header>

            <main
                id="main_content"
                class="max-w-7xl mx-auto p-4">
            </main>

        </div>

        <div id="preview_modal"></div>

        <div id="response_modal"></div>
    `;

    this.actions.showSurveyList();
};

/*
|--------------------------------------------------------------------------
| Survey List
|--------------------------------------------------------------------------
*/

App.actions.showSurveyList = function () {

    App.state.screen = 'list';

    const main =
        document.getElementById('main_content');

    if (!main) {
        return;
    }

    main.innerHTML = `

        <div class="mb-6">

            <div class="flex items-center
                        justify-between">

                <div>
                    <div class="text-sm
                                text-gray-500">
                        ホーム
                        >
                        アンケート一覧
                    </div>

                    <h1 class="text-2xl
                               font-bold mt-2">
                        アンケート一覧
                    </h1>
                </div>

                <button
                    class="bg-blue-600 text-white
                           px-4 py-2 rounded-lg"
                    onclick="
                        App.actions.createSurvey()
                    ">
                    + 新規アンケート作成
                </button>

            </div>

        </div>

        <div class="bg-white rounded-xl
                    border p-4 mb-4">

            <div class="grid md:grid-cols-3
                        gap-3">

                <input
                    id="survey_filter_keyword"
                    type="text"
                    placeholder="タイトル検索"
                    class="border rounded-lg px-3 py-2"
                    value="${App.utils.escapeHtml(
                        App.state.keyword
                    )}"
                    oninput="
                        App.actions.filterSurveys(
                            this.value
                        )
                    "
                >

                <select
                    class="border rounded-lg px-3 py-2"
                    onchange="
                        App.actions.toggleStatusFilter(
                            this.value
                        )
                    "
                >
                    <option value="">
                        すべて
                    </option>

                    <option value="draft">
                        下書き
                    </option>

                    <option value="active">
                        公開中
                    </option>

                    <option value="ended">
                        終了
                    </option>

                </select>

                <select
                    class="border rounded-lg px-3 py-2"
                    onchange="
                        App.actions.sortSurveys(
                            this.value
                        )
                    "
                >

                    <option value="updated_desc">
                        更新日 新しい順
                    </option>

                    <option value="updated_asc">
                        更新日 古い順
                    </option>

                    <option value="responses_desc">
                        回答数 多い順
                    </option>

                    <option value="responses_asc">
                        回答数 少ない順
                    </option>

                    <option value="start_desc">
                        開始日 新しい順
                    </option>

                    <option value="start_asc">
                        開始日 古い順
                    </option>

                </select>

            </div>

        </div>

        <div class="bg-white rounded-xl
                    border overflow-x-auto">

            <table class="min-w-full text-sm">

                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left p-3">
                            作成日
                        </th>
                        <th class="text-left p-3">
                            更新日
                        </th>
                        <th class="text-left p-3">
                            タイトル
                        </th>
                        <th class="text-left p-3">
                            開始日時
                        </th>
                        <th class="text-left p-3">
                            終了日時
                        </th>
                        <th class="text-left p-3">
                            ステータス
                        </th>
                        <th class="text-left p-3">
                            回答数
                        </th>
                        <th class="text-left p-3">
                            操作
                        </th>
                    </tr>
                </thead>

                <tbody id="survey_table">
                </tbody>

            </table>

        </div>
    `;

    App.actions.renderSurveyRows();
};

/*
|--------------------------------------------------------------------------
| Survey API
|--------------------------------------------------------------------------
*/

App.api.request = async function (
    action,
    data = {}
) {

    const body =
        new URLSearchParams();

    body.set('action', action);

    body.set(
        'csrf_token',
        App.state.csrfToken
    );

    Object.entries(data).forEach(
        ([key, value]) => {

            if (
                typeof value === 'object' &&
                value !== null
            ) {
                body.set(
                    key,
                    JSON.stringify(value)
                );
            } else {
                body.set(
                    key,
                    String(value)
                );
            }

        }
    );

    const response =
        await fetch(
            window.location.href,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded'
                },
                body
            }
        );

    const json =
        await response.json();

    if (!json.ok) {
        throw new Error(
            json.message ||
            'API error'
        );
    }

    return json;
};

/*
|--------------------------------------------------------------------------
| Survey loading
|--------------------------------------------------------------------------
*/

App.actions.loadSurveys = async function () {

    /*
     * 実装時にはGET/POST APIから取得する。
     * 現在のJSONを直接PHPで埋め込む方式も可能だが、
     * SPA責務分離のためAPI経由に統一する。
     */

    try {

        const result =
            await App.api.request(
                'list_surveys'
            );

        App.state.surveys =
            result.surveys || [];

        App.actions.renderSurveyRows();

    } catch (error) {

        console.error(error);

        /*
         * 初期状態では空一覧として扱う。
         */

        App.state.surveys = [];

        App.actions.renderSurveyRows();
    }
};

/*
|--------------------------------------------------------------------------
| Required Actions
|--------------------------------------------------------------------------
*/

App.actions.addGroup = function () {

    if (!App.state.currentSurvey) {
        return;
    }

    App.state.currentSurvey.groups.push({
        id: App.utils.uuid('group'),
        name: '新しいグループ',
        questions: []
    });

    App.actions.renumberQuestions();
    App.actions.renderEditor();
    App.initSortable();
};

App.actions.addQuestion = function (
    groupId
) {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    const group =
        survey.groups.find(
            group => group.id === groupId
        );

    if (!group) {
        return;
    }

    group.questions.push({

        id: App.utils.uuid('question'),

        text: '',

        type: 'text',

        required: false,

        options: [],

        other_enabled: false,

        branching: []

    });

    App.actions.renumberQuestions();

    App.actions.renderEditor();

    App.initSortable();
};

App.actions.deleteGroup = function (
    groupId
) {

    if (!App.state.currentSurvey) {
        return;
    }

    App.state.currentSurvey.groups =
        App.state.currentSurvey.groups
            .filter(
                group => group.id !== groupId
            );

    App.actions.renumberQuestions();

    App.actions.renderEditor();

    App.initSortable();
};

App.actions.deleteQuestion = function (
    groupId,
    questionId
) {

    const group =
        App.state.currentSurvey?.groups
            ?.find(
                group => group.id === groupId
            );

    if (!group) {
        return;
    }

    group.questions =
        group.questions.filter(
            question =>
                question.id !== questionId
        );

    App.actions.renumberQuestions();

    App.actions.renderEditor();

    App.initSortable();
};

App.actions.moveQuestion = function () {

    /*
     * DOMのSortable状態をStateへ再構築する。
     */

    App.actions.rebuildQuestionState();
};

App.actions.renumberQuestions = function () {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    let globalNumber = 1;

    survey.groups.forEach(
        (group, groupIndex) => {

            group.questions.forEach(
                (question, questionIndex) => {

                    if (
                        survey.numbering_mode ===
                        'group'
                    ) {

                        question.number =
                            `Q${groupIndex + 1}-${questionIndex + 1}`;

                    } else {

                        question.number =
                            `Q${globalNumber}`;

                        globalNumber++;
                    }

                }
            );

        }
    );
};

App.actions.preview = function () {

    const modal =
        document.getElementById(
            'preview_modal'
        );

    const content =
        App.actions.renderPreview();

    modal.innerHTML = `
        <div class="fixed inset-0 z-50
                    bg-black/50
                    flex items-center
                    justify-center p-4">

            <div class="bg-white rounded-xl
                        max-w-4xl w-full
                        max-h-[90vh]
                        overflow-auto">

                <div class="p-4 border-b
                            flex justify-between">

                    <h2 class="font-bold">
                        プレビュー
                    </h2>

                    <button
                        onclick="
                            App.actions.closeModal()
                        ">
                        ×
                    </button>

                </div>

                <div
                    id="preview_content"
                    class="p-6">
                    ${content}
                </div>

                <div class="p-4 border-t
                            text-center
                            text-gray-500">
                    プレビューのため送信されません
                </div>

            </div>

        </div>
    `;
};

App.actions.saveSurvey = async function () {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    App.state.loading = true;

    try {

        App.actions.renumberQuestions();

        await App.api.request(
            'save_survey',
            {
                survey_json: survey
            }
        );

        alert('保存しました。');

        App.actions.showSurveyList();

        await App.actions.loadSurveys();

    } catch (error) {

        alert(
            error.message ||
            '保存に失敗しました。'
        );

    } finally {

        App.state.loading = false;

    }
};

App.actions.cancelEdit = function () {
    App.actions.showSurveyList();
};

App.actions.stopSurvey = async function (
    surveyId
) {

    if (!confirm(
        'このアンケートを停止しますか？'
    )) {
        return;
    }

    await App.api.request(
        'stop_survey',
        {
            survey_id: surveyId
        }
    );

    await App.actions.loadSurveys();

    App.actions.showSurveyList();
};

App.actions.resumeSurvey = async function (
    surveyId
) {

    await App.api.request(
        'resume_survey',
        {
            survey_id: surveyId
        }
    );

    await App.actions.loadSurveys();

    App.actions.showSurveyList();
};

App.actions.duplicateSurvey = async function (
    surveyId
) {

    await App.api.request(
        'duplicate_survey',
        {
            survey_id: surveyId
        }
    );

    await App.actions.loadSurveys();

    App.actions.showSurveyList();
};

App.actions.deleteSurvey = async function (
    surveyId
) {

    if (!confirm(
        'このアンケートを削除しますか？'
    )) {
        return;
    }

    await App.api.request(
        'delete_survey',
        {
            survey_id: surveyId
        }
    );

    await App.actions.loadSurveys();

    App.actions.showSurveyList();
};

App.actions.filterSurveys = function (
    keyword
) {

    App.state.keyword =
        keyword || '';

    App.actions.renderSurveyRows();
};

App.actions.toggleStatusFilter = function (
    value
) {

    App.state.statusFilter =
        value || '';

    App.actions.renderSurveyRows();
};

App.actions.sortSurveys = function (
    value
) {

    App.state.sort =
        value || 'updated_desc';

    App.actions.renderSurveyRows();
};

App.actions.fetchKintoneFields =
    async function () {

        return App.api.request(
            'fetch_kintone_fields'
        );
    };

App.actions.syncCustomers =
    async function () {

        return App.api.request(
            'sync_customers'
        );
    };

App.actions.sendMail =
    async function () {

        return App.api.request(
            'send_mail'
        );
    };

App.actions.sendReminder =
    async function () {

        return App.api.request(
            'send_reminder'
        );
    };

App.actions.showSentMail =
    async function (
        mailLogId
    ) {

        return App.api.request(
            'show_sent_mail',
            {
                mail_log_id: mailLogId
            }
        );
    };

App.actions.showAllResponses =
    async function (
        responseId
    ) {

        return App.api.request(
            'show_all_responses',
            {
                response_id: responseId
            }
        );
    };

App.actions.toggleResponseFilter =
    function () {
        /*
         * 集計画面の回答フィルター切替
         */
    };

/*
|--------------------------------------------------------------------------
| 回答者
|--------------------------------------------------------------------------
*/

App.actions.startGeneralResponse =
    function (survey, publicToken) {

        App.state.response.mode =
            'general';

        App.state.response.survey =
            survey;

        App.state.response.session =
            App.utils.uuid(
                'response_session'
            );

        /*
         * 一般回答では自動照合しない。
         * web_UUIDは回答者情報確定時に発行する。
         */

        App.actions.renderGeneralCustomerForm();
    };

App.actions.startIndividualResponse =
    function (
        survey,
        responseToken
    ) {

        App.state.response.mode =
            'individual';

        App.state.response.survey =
            survey;

        App.state.response.session =
            App.utils.uuid(
                'response_session'
            );

        App.actions.renderResponse();
    };

App.actions.validateResponse =
    function () {

        const survey =
            App.state.response.survey;

        if (!survey) {
            return false;
        }

        /*
         * 分岐により非表示の質問は
         * requiredチェック対象外。
         */

        return true;
    };

App.actions.confirmResponse =
    function () {

        if (
            !App.actions.validateResponse()
        ) {
            return;
        }

        App.actions.renderResponseConfirmation();
    };

App.actions.submitResponse =
    async function () {

        /*
         * 二重送信防止
         */

        if (App.state.loading) {
            return;
        }

        App.state.loading = true;

        try {

            await App.api.request(
                'submit_response',
                {
                    response_data:
                        App.state.response
                }
            );

            App.actions.clearDraftResponse();

            App.actions.renderResponseComplete();

        } catch (error) {

            alert(
                '回答の送信に失敗しました。'
            );

        } finally {

            App.state.loading = false;
        }
    };

App.actions.restoreDraftResponse =
    function () {

        const survey =
            App.state.response.survey;

        if (!survey) {
            return;
        }

        const key =
            App.utils.responseStorageKey(
                survey.id,
                App.state.response.session
            );

        const raw =
            localStorage.getItem(key);

        if (!raw) {
            return;
        }

        try {

            const data =
                JSON.parse(raw);

            App.state.response.answers =
                data.answers || {};

        } catch (error) {
            console.warn(
                'Invalid local response draft.'
            );
        }
    };

App.actions.clearDraftResponse =
    function () {

        const survey =
            App.state.response.survey;

        if (!survey) {
            return;
        }

        const key =
            App.utils.responseStorageKey(
                survey.id,
                App.state.response.session
            );

        localStorage.removeItem(key);
    };

App.actions.startResurvey =
    function (
        previousResponse
    ) {

        App.state.response.answers =
            previousResponse.answers || {};

        App.actions.renderResponse();
    };

/*
|--------------------------------------------------------------------------
| Utility
|--------------------------------------------------------------------------
*/

App.utils.escapeHtml =
    function (value) {

        const div =
            document.createElement('div');

        div.textContent =
            value == null
                ? ''
                : String(value);

        return div.innerHTML;
    };

App.utils.uuid =
    function (prefix) {

        if (
            window.crypto &&
            crypto.randomUUID
        ) {
            return `${prefix}_${crypto.randomUUID()}`;
        }

        return `${prefix}_${Date.now()}_${
            Math.random()
                .toString(16)
                .slice(2)
        }`;
    };

App.utils.responseStorageKey =
    function (
        surveyId,
        responseSession
    ) {

        return [
            'survey_response_draft',
            surveyId,
            responseSession
        ].join(':');
    };

/*
|--------------------------------------------------------------------------
| Placeholder / Navigation
|--------------------------------------------------------------------------
*/

App.actions.createSurvey = function () {

    App.state.currentSurvey = {

        id:
            App.utils.uuid('survey'),

        title: '',

        start_at: '',

        end_at: '',

        status: 'draft',

        created_at: '',

        updated_at: '',

        numbering_mode: 'global',

        groups: [],

        deleted: false,

        general_response_enabled: false,

        public_token:
            App.utils.uuid('public')

    };

    App.state.screen = 'edit';

    App.actions.renderEditor();
};

App.actions.renderEditor = function () {

    const main =
        document.getElementById(
            'main_content'
        );

    const survey =
        App.state.currentSurvey;

    if (!main || !survey) {
        return;
    }

    main.innerHTML = `
        <div class="mb-6">

            <div class="text-sm text-gray-500">
                ホーム
                >
                アンケート一覧
                >
                アンケート編集
            </div>

            <div class="flex justify-between
                        items-center mt-2">

                <h1 class="text-2xl font-bold">
                    アンケート編集
                </h1>

                <div class="flex gap-2">

                    <button
                        class="px-4 py-2 border
                               rounded-lg"
                        onclick="
                            App.actions.preview()
                        ">
                        プレビュー
                    </button>

                    <button
                        class="px-4 py-2
                               bg-blue-600
                               text-white
                               rounded-lg"
                        onclick="
                            App.actions.saveSurvey()
                        ">
                        保存
                    </button>

                </div>

            </div>

        </div>

        <div class="bg-white rounded-xl
                    border p-4 mb-4">

            <div class="grid gap-4">

                <label>
                    <span class="block mb-1">
                        タイトル
                    </span>

                    <input
                        id="survey_title"
                        class="w-full border
                               rounded-lg px-3 py-2"
                        value="${App.utils.escapeHtml(
                            survey.title
                        )}"
                        oninput="
                            App.state.currentSurvey.title =
                            this.value
                        "
                    >
                </label>

                <div class="grid md:grid-cols-2 gap-4">

                    <label>
                        <span class="block mb-1">
                            開始日時
                        </span>

                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            class="w-full border
                                   rounded-lg px-3 py-2"
                            value="${App.utils.escapeHtml(
                                survey.start_at
                            )}"
                            onchange="
                                App.state.currentSurvey.start_at =
                                this.value
                            "
                        >
                    </label>

                    <label>
                        <span class="block mb-1">
                            終了日時
                        </span>

                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            class="w-full border
                                   rounded-lg px-3 py-2"
                            value="${App.utils.escapeHtml(
                                survey.end_at
                            )}"
                            onchange="
                                App.state.currentSurvey.end_at =
                                this.value
                            "
                        >
                    </label>

                </div>

                <label>

                    <span class="block mb-1">
                        質問番号形式
                    </span>

                    <select
                        id="survey_numbering_mode"
                        class="border rounded-lg
                               px-3 py-2"
                        onchange="
                            App.state.currentSurvey.numbering_mode =
                            this.value;
                            App.actions.renumberQuestions();
                            App.actions.renderEditor();
                        "
                    >

                        <option
                            value="global"
                            ${survey.numbering_mode ===
                              'global'
                              ? 'selected'
                              : ''}
                        >
                            global（Q1, Q2...）
                        </option>

                        <option
                            value="group"
                            ${survey.numbering_mode ===
                              'group'
                              ? 'selected'
                              : ''}
                        >
                            group（Q1-1, Q1-2...）
                        </option>

                    </select>

                </label>

                <label class="flex gap-2">

                    <input
                        type="checkbox"
                        ${survey.general_response_enabled
                            ? 'checked'
                            : ''}
                        onchange="
                            App.state.currentSurvey
                                .general_response_enabled =
                            this.checked
                        "
                    >

                    <span>
                        一般回答を許可する
                    </span>

                </label>

            </div>

        </div>

        <div class="bg-white rounded-xl
                    border p-4">

            <div class="flex justify-between
                        items-center mb-4">

                <h2 class="text-lg font-bold">
                    質問・グループ
                </h2>

                <button
                    class="px-3 py-2
                           bg-gray-900
                           text-white
                           rounded-lg"
                    onclick="
                        App.actions.addGroup()
                    ">
                    + グループ追加
                </button>

            </div>

            <div id="question_editor">
                ${App.actions.renderGroups()}
            </div>

        </div>
    `;

    App.actions.renumberQuestions();

    App.initSortable();
};

App.actions.renderGroups = function () {

    const survey =
        App.state.currentSurvey;

    return survey.groups.map(
        (group, index) => `

        <section
            class="border rounded-xl p-4 mb-4"
            data-group-id="${App.utils.escapeHtml(
                group.id
            )}">

            <div class="group-handle
                        cursor-move
                        flex justify-between
                        items-center mb-3">

                <input
                    class="border rounded-lg
                           px-3 py-2
                           font-semibold"
                    value="${App.utils.escapeHtml(
                        group.name
                    )}"
                    oninput="
                        App.state.currentSurvey.groups[
                            ${index}
                        ].name = this.value
                    "
                >

                <div class="flex gap-2">

                    <button
                        class="text-sm
                               px-3 py-2
                               border rounded"
                        onclick="
                            App.actions.addQuestion(
                                '${App.utils.escapeHtml(
                                    group.id
                                )}'
                            )
                        ">
                        + 質問
                    </button>

                    <button
                        class="text-sm
                               px-3 py-2
                               text-red-600"
                        onclick="
                            App.actions.deleteGroup(
                                '${App.utils.escapeHtml(
                                    group.id
                                )}'
                            )
                        ">
                        削除
                    </button>

                </div>

            </div>

            <div
                class="question-list space-y-3"
                data-group-id="${App.utils.escapeHtml(
                    group.id
                )}">

                ${group.questions.map(
                    question =>
                        App.actions.renderQuestion(
                            group,
                            question
                        )
                ).join('')}

            </div>

        </section>
    `
    ).join('');
};

App.actions.renderQuestion =
    function (
        group,
        question
    ) {

        return `
            <div
                class="border rounded-lg p-4
                       bg-gray-50"
                data-question-id="${App.utils.escapeHtml(
                    question.id
                )}">

                <div class="flex gap-3">

                    <span class="font-bold">
                        ${App.utils.escapeHtml(
                            question.number || ''
                        )}
                    </span>

                    <div class="flex-1">

                        <input
                            class="w-full border
                                   rounded-lg
                                   px-3 py-2 mb-2"
                            placeholder="質問文"
                            value="${App.utils.escapeHtml(
                                question.text
                            )}"
                            oninput="
                                App.actions.updateQuestion(
                                    '${App.utils.escapeHtml(
                                        group.id
                                    )}',
                                    '${App.utils.escapeHtml(
                                        question.id
                                    )}',
                                    'text',
                                    this.value
                                )
                            "
                        >

                        <select
                            class="border rounded-lg
                                   px-3 py-2"
                            onchange="
                                App.actions.updateQuestion(
                                    '${App.utils.escapeHtml(
                                        group.id
                                    )}',
                                    '${App.utils.escapeHtml(
                                        question.id
                                    )}',
                                    'type',
                                    this.value
                                )
                            "
                        >

                            <option
                                value="single"
                                ${question.type ===
                                  'single'
                                  ? 'selected'
                                  : ''}
                            >
                                単一選択
                            </option>

                            <option
                                value="multiple"
                                ${question.type ===
                                  'multiple'
                                  ? 'selected'
                                  : ''}
                            >
                                複数選択
                            </option>

                            <option
                                value="text"
                                ${question.type ===
                                  'text'
                                  ? 'selected'
                                  : ''}
                            >
                                自由記述
                            </option>

                        </select>

                        <label class="ml-3">

                            <input
                                type="checkbox"
                                ${question.required
                                    ? 'checked'
                                    : ''}
                                onchange="
                                    App.actions.updateQuestion(
                                        '${App.utils.escapeHtml(
                                            group.id
                                        )}',
                                        '${App.utils.escapeHtml(
                                            question.id
                                        )}',
                                        'required',
                                        this.checked
                                    )
                                "
                            >

                            必須
                        </label>

                    </div>

                    <button
                        class="text-red-600"
                        onclick="
                            App.actions.deleteQuestion(
                                '${App.utils.escapeHtml(
                                    group.id
                                )}',
                                '${App.utils.escapeHtml(
                                    question.id
                                )}'
                            )
                        ">
                        削除
                    </button>

                </div>

            </div>
        `;
    };

App.actions.updateQuestion =
    function (
        groupId,
        questionId,
        key,
        value
    ) {

        const group =
            App.state.currentSurvey.groups
                .find(
                    group => group.id === groupId
                );

        if (!group) {
            return;
        }

        const question =
            group.questions.find(
                question =>
                    question.id === questionId
            );

        if (!question) {
            return;
        }

        question[key] = value;
    };

App.actions.renderSurveyRows =
    function () {

        const tbody =
            document.getElementById(
                'survey_table'
            );

        if (!tbody) {
            return;
        }

        let surveys =
            App.state.surveys.filter(
                survey => !survey.deleted
            );

        if (App.state.keyword) {

            const keyword =
                App.state.keyword
                    .toLowerCase();

            surveys =
                surveys.filter(
                    survey =>
                        String(
                            survey.title || ''
                        )
                        .toLowerCase()
                        .includes(keyword)
                );
        }

        if (App.state.statusFilter) {

            surveys =
                surveys.filter(
                    survey =>
                        survey.status ===
                        App.state.statusFilter
                );
        }

        tbody.innerHTML =
            surveys.map(
                survey =>
                    App.actions.renderSurveyRow(
                        survey
                    )
            ).join('');
    };

App.actions.renderSurveyRow =
    function (survey) {

        const responseCount =
            App.state.responsesCount?.[
                survey.id
            ] || 0;

        return `
            <tr class="border-t">

                <td class="p-3">
                    ${App.utils.escapeHtml(
                        survey.created_at || ''
                    )}
                </td>

                <td class="p-3">
                    ${App.utils.escapeHtml(
                        survey.updated_at || ''
                    )}
                </td>

                <td class="p-3 font-medium">
                    ${App.utils.escapeHtml(
                        survey.title || ''
                    )}
                </td>

                <td class="p-3">
                    ${App.utils.escapeHtml(
                        survey.start_at || ''
                    )}
                </td>

                <td class="p-3">
                    ${App.utils.escapeHtml(
                        survey.end_at || ''
                    )}
                </td>

                <td class="p-3">
                    ${App.utils.escapeHtml(
                        survey.status || ''
                    )}
                </td>

                <td class="p-3">
                    ${responseCount}
                </td>

                <td class="p-3">

                    <div class="flex flex-wrap gap-1">

                        <button
                            class="px-2 py-1
                                   border rounded"
                            onclick="
                                App.actions.editSurvey(
                                    '${App.utils.escapeHtml(
                                        survey.id
                                    )}'
                                )
                            ">
                            確認・編集
                        </button>

                        ${
                            survey.status === 'active'
                            ? `
                                <button
                                    class="px-2 py-1
                                           border rounded"
                                    onclick="
                                        App.actions.stopSurvey(
                                            '${App.utils.escapeHtml(
                                                survey.id
                                            )}'
                                        )
                                    ">
                                    停止
                                </button>
                            `
                            : ''
                        }

                        ${
                            survey.status === 'ended'
                            ? `
                                <button
                                    class="px-2 py-1
                                           border rounded"
                                    onclick="
                                        App.actions.resumeSurvey(
                                            '${App.utils.escapeHtml(
                                                survey.id
                                            )}'
                                        )
                                    ">
                                    再開
                                </button>
                            `
                            : ''
                        }

                        <button
                            class="px-2 py-1
                                   border rounded"
                            onclick="
                                App.actions.duplicateSurvey(
                                    '${App.utils.escapeHtml(
                                        survey.id
                                    )}'
                                )
                            ">
                            複製
                        </button>

                    </div>

                </td>

            </tr>
        `;
    };

/*
|--------------------------------------------------------------------------
| Additional UI placeholders
|--------------------------------------------------------------------------
*/

App.actions.editSurvey =
    async function (surveyId) {

        try {

            const result =
                await App.api.request(
                    'get_survey',
                    {
                        survey_id: surveyId
                    }
                );

            App.state.currentSurvey =
                result.survey;

            App.actions.renderEditor();

        } catch (error) {

            alert(
                error.message ||
                'アンケートを取得できませんでした。'
            );
        }
    };

App.actions.renderPreview =
    function () {

        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return '';
        }

        return `
            <h1 class="text-2xl font-bold mb-6">
                ${App.utils.escapeHtml(
                    survey.title
                )}
            </h1>

            ${survey.groups.map(
                group => `

                <section class="mb-6">

                    <h2 class="text-lg
                               font-bold mb-3">
                        ${App.utils.escapeHtml(
                            group.name
                        )}
                    </h2>

                    ${group.questions.map(
                        question => `

                        <div class="mb-5">

                            <div class="font-medium mb-2">
                                ${App.utils.escapeHtml(
                                    question.number
                                )}
                                ${App.utils.escapeHtml(
                                    question.text
                                )}

                                ${
                                    question.required
                                    ? '<span class="text-red-500"> *</span>'
                                    : ''
                                }
                            </div>

                        </div>

                    `
                    ).join('')}

                </section>

            `
            ).join('')}

            <button
                class="px-4 py-2
                       bg-blue-600
                       text-white
                       rounded-lg">
                送信する
            </button>
        `;
    };

App.actions.closeModal =
    function () {

        const preview =
            document.getElementById(
                'preview_modal'
            );

        const response =
            document.getElementById(
                'response_modal'
            );

        if (preview) {
            preview.innerHTML = '';
        }

        if (response) {
            response.innerHTML = '';
        }
    };

App.actions.showSettings =
    function () {

        const main =
            document.getElementById(
                'main_content'
            );

        if (!main) {
            return;
        }

        main.innerHTML = `
            <div class="text-sm text-gray-500">
                ホーム
                >
                システム設定
                >
                kintone・メール連携設定
            </div>

            <h1 class="text-2xl
                       font-bold mt-2 mb-6">
                システム設定
            </h1>

            <div
                id="settings_form"
                class="bg-white rounded-xl
                       border p-6">

                <h2 class="font-bold mb-4">
                    kintone設定
                </h2>

                <div class="grid gap-3">

                    <input
                        id="setting_subdomain"
                        class="border rounded-lg
                               px-3 py-2"
                        placeholder="xxxx.cybozu.com"
                    >

                    <input
                        id="setting_app_id"
                        class="border rounded-lg
                               px-3 py-2"
                        placeholder="顧客管理アプリID"
                    >

                    <input
                        id="setting_login_name"
                        class="border rounded-lg
                               px-3 py-2"
                        placeholder="ログイン名"
                    >

                    <input
                        id="setting_password"
                        type="password"
                        class="border rounded-lg
                               px-3 py-2"
                        placeholder="パスワード"
                    >

                    <input
                        id="setting_proxy"
                        class="border rounded-lg
                               px-3 py-2"
                        placeholder="Proxy host:port"
                    >

                    <label>
                        <input
                            id="setting_ssl_verify"
                            type="checkbox"
                            checked
                        >
                        SSL証明書を検証する
                    </label>

                </div>

                <div class="mt-6 flex gap-2">

                    <button
                        class="px-4 py-2
                               bg-blue-600
                               text-white
                               rounded-lg"
                        onclick="
                            App.actions.saveSettings()
                        ">
                        保存
                    </button>

                    <button
                        class="px-4 py-2
                               border rounded-lg"
                        onclick="
                            App.actions.fetchKintoneFields()
                        ">
                        kintoneフィールド取得
                    </button>

                    <button
                        class="px-4 py-2
                               border rounded-lg"
                        onclick="
                            App.actions.syncCustomers()
                        ">
                        顧客データを同期
                    </button>

                </div>

                <div
                    id="field_message"
                    class="mt-4">
                </div>

            </div>
        `;
    };

/*
|--------------------------------------------------------------------------
| Test Mode Logout
|--------------------------------------------------------------------------
*/

App.actions.testLogout =
    function () {

        /*
         * テスト版では認証を省略するため、
         * ログアウトしても再度管理画面へ戻る。
         */
        App.actions.showSurveyList();
    };

/*
|--------------------------------------------------------------------------
| Response UI placeholders
|--------------------------------------------------------------------------
*/

App.actions.renderGeneralCustomerForm =
    function () {

        const app =
            document.getElementById('app');

        app.innerHTML = `
            <main class="min-h-screen
                         bg-gray-100
                         flex items-center
                         justify-center p-4">

                <div class="bg-white
                            rounded-xl
                            border
                            p-6
                            max-w-xl
                            w-full">

                    <h1 class="text-xl
                               font-bold mb-6">
                        回答者情報
                    </h1>

                    <div class="space-y-4">

                        <input
                            class="w-full border
                                   rounded-lg
                                   px-3 py-2"
                            placeholder="会社名 必須"
                        >

                        <input
                            class="w-full border
                                   rounded-lg
                                   px-3 py-2"
                            placeholder="氏名 必須"
                        >

                        <input
                            class="w-full border
                                   rounded-lg
                                   px-3 py-2"
                            placeholder="メールアドレス 必須"
                        >

                        <input
                            class="w-full border
                                   rounded-lg
                                   px-3 py-2"
                            placeholder="部署名 任意"
                        >

                        <input
                            class="w-full border
                                   rounded-lg
                                   px-3 py-2"
                            placeholder="電話番号 任意"
                        >

                        <input
                            class="w-full border
                                   rounded-lg
                                   px-3 py-2"
                            placeholder="住所 任意"
                        >

                        <button
                            class="w-full
                                   bg-blue-600
                                   text-white
                                   rounded-lg
                                   py-3"
                            onclick="
                                App.actions.startGeneralAnswer()
                            ">
                            回答を開始
                        </button>

                    </div>

                </div>

            </main>
        `;
    };

App.actions.startGeneralAnswer =
    function () {

        /*
         * 一時顧客IDを発行。
         * kintone自動照合はしない。
         */

        App.state.response.customer = {

            id:
                App.utils.uuid('web'),

            web_uuid:
                App.utils.uuid('web'),

            source: 'web',

            kintone_status:
                'unregistered'

        };

        App.actions.renderResponse();
    };

App.actions.renderResponse =
    function () {

        /*
         * 実装時にはsurvey.groups/questionsから
         * 分岐条件を評価して表示する。
         */

        const app =
            document.getElementById('app');

        const survey =
            App.state.response.survey;

        if (!app || !survey) {
            return;
        }

        app.innerHTML = `
            <main class="min-h-screen
                         bg-gray-100 p-4">

                <div class="max-w-3xl
                            mx-auto">

                    <div class="bg-white
                                rounded-xl
                                border
                                p-6">

                        <h1 class="text-2xl
                                   font-bold mb-6">
                            ${App.utils.escapeHtml(
                                survey.title
                            )}
                        </h1>

                        <div id="response_questions">
                        </div>

                        <button
                            class="mt-6 w-full
                                   bg-blue-600
                                   text-white
                                   rounded-lg
                                   py-3"
                            onclick="
                                App.actions.confirmResponse()
                            ">
                            送信する
                        </button>

                    </div>

                </div>

            </main>
        `;

        App.actions.restoreDraftResponse();
    };

App.actions.renderResponseConfirmation =
    function () {

        const app =
            document.getElementById('app');

        if (!app) {
            return;
        }

        app.innerHTML = `
            <main class="min-h-screen
                         bg-gray-100
                         flex items-center
                         justify-center p-4">

                <div class="bg-white
                            rounded-xl
                            border
                            p-6
                            max-w-xl
                            w-full">

                    <h1 class="text-xl
                               font-bold mb-4">
                        回答内容確認
                    </h1>

                    <p class="mb-6">
                        この内容で送信しますか？
                    </p>

                    <div class="flex gap-3">

                        <button
                            class="flex-1
                                   border
                                   rounded-lg
                                   py-3"
                            onclick="
                                App.actions.renderResponse()
                            ">
                            戻る
                        </button>

                        <button
                            class="flex-1
                                   bg-blue-600
                                   text-white
                                   rounded-lg
                                   py-3"
                            onclick="
                                App.actions.submitResponse()
                            ">
                            送信確定
                        </button>

                    </div>

                </div>

            </main>
        `;
    };

App.actions.renderResponseComplete =
    function () {

        const app =
            document.getElementById('app');

        const now =
            new Date().toLocaleString(
                'ja-JP'
            );

        app.innerHTML = `
            <main class="min-h-screen
                         bg-gray-100
                         flex items-center
                         justify-center p-4">

                <div class="bg-white
                            rounded-xl
                            border
                            p-8
                            max-w-xl
                            w-full
                            text-center">

                    <h1 class="text-2xl
                               font-bold mb-4">
                        回答ありがとうございました。
                    </h1>

                    <p class="mb-6">
                        アンケートへのご回答
                        ありがとうございました。
                    </p>

                    <p class="text-sm
                              text-gray-500">
                        受付日時：
                        ${App.utils.escapeHtml(
                            now
                        )}
                    </p>

                    <p class="mt-6
                              text-gray-500">
                        この画面を閉じてください。
                    </p>

                </div>

            </main>
        `;
    };

App.actions.saveSettings =
    async function () {

        try {

            await App.api.request(
                'save_settings',
                {
                    settings_json: {}
                }
            );

            alert(
                '設定を保存しました。'
            );

        } catch (error) {

            alert(
                error.message ||
                '設定保存に失敗しました。'
            );
        }
    };

/*
|--------------------------------------------------------------------------
| Guarded initialization
|--------------------------------------------------------------------------
*/

if (
    document.readyState === 'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        { once: true }
    );

} else {

    App.init();

}

</script>

</body>
</html>
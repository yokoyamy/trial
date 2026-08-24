<?php
declare(strict_types=1);

/*
============================================================
GUARD COMMENT
============================================================

固定ストレージ名称
- survey_storage_directory
- survey_storage_file
- survey_admin_session_v1

PHP定数
- SURVEY_STORAGE_DIRECTORY
- SURVEY_STORAGE_FILE
- SURVEY_ADMIN_SESSION

JSONトップキー
- surveys
- responses
- customers
- settings
- mail_logs

固定値
- draft
- active
- ended
- global
- group
- single
- multiple
- text
- kintone
- web
- unanswered
- answered
- unregistered
- registered
- initial
- reminder
- resend

固定DOM ID
- app
- csrf_token
- survey_title
- survey_start_at
- survey_end_at
- survey_numbering_mode
- question_editor
- preview_modal
- preview_content
- response_modal
- response_detail
- response_filter
- response_table
- customer_filter
- customer_table
- select_all
- mail_subject
- mail_body
- template_type
- settings_form
- settings_json
- setting_subdomain
- setting_app_id
- setting_login_name
- setting_password
- setting_proxy
- setting_ssl_verify
- field_message

JavaScript namespace
- window.App

============================================================
*/

const SURVEY_STORAGE_DIRECTORY = 'survey_storage_directory';
const SURVEY_STORAGE_FILE = 'survey_storage_file';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header_remove('X-Powered-By');

function survey_app_storage_dir(): string
{
    return __DIR__ . '/survey_storage';
}

function survey_app_storage_file(): string
{
    return survey_app_storage_dir() . '/survey_data.json';
}

function survey_app_initial_data(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [],
        'mail_logs' => [],
    ];
}

function survey_app_load_data(): array
{
    $file = survey_app_storage_file();

    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }

    if (!is_file($file)) {
        $data = survey_app_initial_data();
        survey_app_save_data($data);
        return $data;
    }

    $json = @file_get_contents($file);

    if ($json === false || trim($json) === '') {
        $data = survey_app_initial_data();
        survey_app_save_data($data);
        return $data;
    }

    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $data = survey_app_initial_data();
        survey_app_save_data($data);
        return $data;
    }

    if (!is_array($data)) {
        $data = survey_app_initial_data();
    }

    foreach (survey_app_initial_data() as $key => $default) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $default;
        }
    }

    return $data;
}

function survey_app_save_data(array $data): bool
{
    $dir = survey_app_storage_dir();

    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return false;
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    $tmp = $dir . '/survey_data.tmp.' . bin2hex(random_bytes(8));

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    $check = @file_get_contents($tmp);

    if ($check === false) {
        @unlink($tmp);
        return false;
    }

    try {
        json_decode($check, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        @unlink($tmp);
        return false;
    }

    return @rename($tmp, survey_app_storage_file());
}

function survey_app_json(array $payload, int $status = 200): never
{
    http_response_code($status);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function survey_app_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function survey_app_require_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(survey_app_csrf(), $token)) {
        survey_app_json([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 403);
    }
}

function survey_app_id(string $prefix): string
{
    return $prefix . '_' .
        date('YmdHis') . '_' .
        bin2hex(random_bytes(6));
}

function survey_app_normalize_survey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? survey_app_id('survey'));
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['start_at'] = (string)($survey['start_at'] ?? '');
    $survey['end_at'] = (string)($survey['end_at'] ?? '');
    $survey['status'] = in_array(
        $survey['status'] ?? 'draft',
        ['draft', 'active', 'ended'],
        true
    ) ? $survey['status'] : 'draft';

    $survey['created_at'] = (string)(
        $survey['created_at'] ?? date('c')
    );

    $survey['updated_at'] = date('c');

    $survey['numbering_mode'] = in_array(
        $survey['numbering_mode'] ?? 'global',
        ['global', 'group'],
        true
    ) ? $survey['numbering_mode'] : 'global';

    $survey['deleted'] = !empty($survey['deleted']);
    $survey['general_response_enabled'] =
        !empty($survey['general_response_enabled']);

    $survey['public_token'] = (string)(
        $survey['public_token'] ??
        bin2hex(random_bytes(24))
    );

    if (!isset($survey['groups']) || !is_array($survey['groups'])) {
        $survey['groups'] = [];
    }

    foreach ($survey['groups'] as &$group) {
        $group['id'] = (string)(
            $group['id'] ?? survey_app_id('group')
        );

        $group['name'] = (string)($group['name'] ?? 'グループ');

        if (!isset($group['questions']) || !is_array($group['questions'])) {
            $group['questions'] = [];
        }

        foreach ($group['questions'] as &$question) {
            $question['id'] = (string)(
                $question['id'] ?? survey_app_id('question')
            );

            $question['text'] = (string)($question['text'] ?? '');
            $question['type'] = in_array(
                $question['type'] ?? 'text',
                ['single', 'multiple', 'text'],
                true
            ) ? $question['type'] : 'text';

            $question['required'] = !empty($question['required']);

            $question['options'] =
                isset($question['options']) &&
                is_array($question['options'])
                    ? array_values($question['options'])
                    : [];

            $question['other_enabled'] =
                !empty($question['other_enabled']);

            $question['branching'] =
                isset($question['branching']) &&
                is_array($question['branching'])
                    ? $question['branching']
                    : [];
        }

        unset($question);
    }

    unset($group);

    return $survey;
}

function survey_app_renumber(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if ($survey['numbering_mode'] === 'group') {
                $question['number'] =
                    'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $questionNo++;
            $global++;
        }

        unset($question);

        $groupNo++;
    }

    unset($group);
}

function survey_app_find_survey(array &$data, string $id): ?array
{
    foreach ($data['surveys'] as $index => $survey) {
        if ((string)$survey['id'] === $id) {
            return [
                'index' => $index,
                'survey' => $survey,
            ];
        }
    }

    return null;
}

function survey_app_handle_api(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === '') {
        return;
    }

    /*
     * 重要:
     * action が存在するPOSTは必ずJSONを返す。
     * HTMLを返してJSON.parse/json()を壊さない。
     */

    survey_app_require_csrf();

    $data = survey_app_load_data();

    switch ($action) {

        case 'list_surveys':
            $surveys = [];

            foreach ($data['surveys'] as $survey) {
                if (!empty($survey['deleted'])) {
                    continue;
                }

                $survey['response_count'] = 0;

                foreach ($data['responses'] as $response) {
                    if (
                        ($response['survey_id'] ?? '') ===
                        ($survey['id'] ?? '') &&
                        empty($response['deleted'])
                    ) {
                        $survey['response_count']++;
                    }
                }

                $surveys[] = $survey;
            }

            survey_app_json([
                'ok' => true,
                'surveys' => $surveys,
            ]);
            break;

        case 'save_survey':
            $raw = (string)($_POST['survey_json'] ?? '');

            try {
                $survey = json_decode(
                    $raw,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable) {
                survey_app_json([
                    'ok' => false,
                    'message' => 'アンケートJSONが不正です。',
                ], 400);
            }

            if (!is_array($survey)) {
                survey_app_json([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。',
                ], 400);
            }

            $survey = survey_app_normalize_survey($survey);
            survey_app_renumber($survey);

            $found = survey_app_find_survey(
                $data,
                (string)$survey['id']
            );

            if ($found === null) {
                $data['surveys'][] = $survey;
            } else {
                $data['surveys'][$found['index']] = $survey;
            }

            if (!survey_app_save_data($data)) {
                survey_app_json([
                    'ok' => false,
                    'message' => 'JSONファイルへ保存できません。',
                ], 500);
            }

            survey_app_json([
                'ok' => true,
                'survey' => $survey,
            ]);
            break;

        case 'duplicate_survey':
            $surveyId = (string)($_POST['survey_id'] ?? '');

            $found = survey_app_find_survey($data, $surveyId);

            if ($found === null) {
                survey_app_json([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $copy = $found['survey'];

            $copy['id'] = survey_app_id('survey');
            $copy['title'] .= '（複製）';
            $copy['status'] = 'draft';
            $copy['created_at'] = date('c');
            $copy['updated_at'] = date('c');
            $copy['deleted'] = false;
            $copy['public_token'] = bin2hex(random_bytes(24));

            foreach ($copy['groups'] as &$group) {
                $group['id'] = survey_app_id('group');

                foreach ($group['questions'] as &$question) {
                    $question['id'] = survey_app_id('question');
                }

                unset($question);
            }

            unset($group);

            survey_app_renumber($copy);

            $data['surveys'][] = $copy;

            if (!survey_app_save_data($data)) {
                survey_app_json([
                    'ok' => false,
                    'message' => '複製データを保存できません。',
                ], 500);
            }

            survey_app_json([
                'ok' => true,
                'survey' => $copy,
            ]);
            break;

        case 'delete_survey':
            $surveyId = (string)($_POST['survey_id'] ?? '');

            $found = survey_app_find_survey($data, $surveyId);

            if ($found === null) {
                survey_app_json([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $data['surveys'][$found['index']]['deleted'] = true;
            $data['surveys'][$found['index']]['updated_at'] = date('c');

            survey_app_save_data($data);

            survey_app_json([
                'ok' => true,
            ]);
            break;

        case 'stop_survey':
        case 'resume_survey':
            $surveyId = (string)($_POST['survey_id'] ?? '');

            $found = survey_app_find_survey($data, $surveyId);

            if ($found === null) {
                survey_app_json([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $data['surveys'][$found['index']]['status'] =
                $action === 'stop_survey'
                    ? 'ended'
                    : 'active';

            $data['surveys'][$found['index']]['updated_at'] = date('c');

            survey_app_save_data($data);

            survey_app_json([
                'ok' => true,
                'survey' => $data['surveys'][$found['index']],
            ]);
            break;

        case 'get_data':
            survey_app_json([
                'ok' => true,
                'surveys' => $data['surveys'],
                'responses' => $data['responses'],
                'customers' => $data['customers'],
                'settings' => $data['settings'],
                'mail_logs' => $data['mail_logs'],
            ]);
            break;

        default:
            survey_app_json([
                'ok' => false,
                'message' => 'Unknown API action: ' . $action,
            ], 400);
    }
}

survey_app_handle_api();

$csrf = survey_app_csrf();

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

</head>

<body class="bg-gray-100 text-gray-900">

<input
    type="hidden"
    id="csrf_token"
    value="<?= htmlspecialchars(
        $csrf,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    ) ?>"
>

<div id="app"></div>

<script>
'use strict';

window.App = {

    state: {
        initialized: false,
        loading: false,
        screen: 'list',
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mail_logs: [],
        currentSurvey: null,
        keyword: '',
        statusFilter: '',
        sort: 'updated_desc'
    },

    render: {},

    actions: {},

    api: {},

    utils: {},

    init: async function () {
        if (this.state.initialized) {
            return;
        }

        this.state.initialized = true;

        this.render.shell();

        await this.api.load();

        this.render.current();
    },

    initSortable: function () {
        /*
         * 必ず App.initSortable として存在させる。
         * 未実装時の
         *
         * App.initSortable is not a function
         *
         * を防止する。
         */

        const groupContainer =
            document.getElementById('question_editor');

        if (!groupContainer || typeof Sortable === 'undefined') {
            return;
        }

        groupContainer
            .querySelectorAll('[data-sortable-groups]')
            .forEach((element) => {

                if (element.dataset.sortableInitialized === '1') {
                    return;
                }

                Sortable.create(element, {
                    animation: 150,
                    handle: '[data-group-handle]',
                    onEnd: () => {
                        App.actions.renumberQuestions();
                    }
                });

                element.dataset.sortableInitialized = '1';
            });

        groupContainer
            .querySelectorAll('[data-sortable-questions]')
            .forEach((element) => {

                if (element.dataset.sortableInitialized === '1') {
                    return;
                }

                Sortable.create(element, {
                    group: 'survey-questions',
                    animation: 150,
                    handle: '[data-question-handle]',
                    onEnd: () => {
                        App.actions.syncQuestionStructure();
                        App.actions.renumberQuestions();
                        App.render.editor();
                    }
                });

                element.dataset.sortableInitialized = '1';
            });
    },

    api: {

        request: async function (action, payload = {}) {

            const body = new URLSearchParams();

            body.set('action', action);

            body.set(
                'csrf_token',
                document.getElementById('csrf_token').value
            );

            Object.entries(payload).forEach(([key, value]) => {

                if (typeof value === 'object') {
                    body.set(key, JSON.stringify(value));
                } else {
                    body.set(key, String(value));
                }

            });

            const response = await fetch(
                window.location.href,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8',
                        'Accept': 'application/json'
                    },
                    body: body.toString()
                }
            );

            const text = await response.text();

            let json;

            try {
                json = JSON.parse(text);
            } catch (error) {

                console.error(
                    'API returned non-JSON response:',
                    text
                );

                throw new Error(
                    'サーバーがJSONではない応答を返しました。'
                );
            }

            if (!response.ok || json.ok === false) {
                throw new Error(
                    json.message ||
                    'API処理に失敗しました。'
                );
            }

            return json;
        },

        load: async function () {

            App.state.loading = true;

            try {

                const json =
                    await this.request('list_surveys');

                App.state.surveys =
                    Array.isArray(json.surveys)
                        ? json.surveys
                        : [];

            } catch (error) {

                App.utils.notice(
                    error.message,
                    'error'
                );

            } finally {

                App.state.loading = false;
            }
        }
    },

    utils: {

        escape: function (value) {

            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        },

        notice: function (message, type = 'info') {

            const color =
                type === 'error'
                    ? 'bg-red-600'
                    : 'bg-blue-600';

            const element =
                document.createElement('div');

            element.className =
                'fixed right-4 top-4 z-[9999] ' +
                color +
                ' text-white px-4 py-3 rounded-lg shadow-lg';

            element.textContent = message;

            document.body.appendChild(element);

            setTimeout(() => {
                element.remove();
            }, 4000);
        },

        newSurvey: function () {

            return {
                id:
                    'survey_' +
                    Date.now() +
                    '_' +
                    Math.random()
                        .toString(36)
                        .slice(2),

                title: '新しいアンケート',

                start_at: '',

                end_at: '',

                status: 'draft',

                created_at:
                    new Date().toISOString(),

                updated_at:
                    new Date().toISOString(),

                numbering_mode: 'global',

                groups: [],

                deleted: false,

                general_response_enabled: false,

                public_token:
                    crypto.randomUUID()
            };
        }
    },

    render: {

        shell: function () {

            document.getElementById('app').innerHTML = `
                <div class="min-h-screen">

                    <header
                        class="sticky top-0 z-30
                               bg-white border-b shadow-sm"
                    >
                        <div
                            class="max-w-7xl mx-auto px-4 py-4
                                   flex items-center justify-between"
                        >

                            <button
                                class="font-bold text-lg"
                                onclick="App.actions.goList()"
                            >
                                アンケート管理システム
                            </button>

                            <nav class="flex gap-2">

                                <button
                                    class="px-3 py-2 rounded
                                           hover:bg-gray-100"
                                    onclick="App.actions.goList()"
                                >
                                    アンケート一覧
                                </button>

                                <button
                                    class="px-3 py-2 rounded
                                           hover:bg-gray-100"
                                    onclick="App.actions.showSettings()"
                                >
                                    kintone連携設定
                                </button>

                                <button
                                    class="px-3 py-2 rounded
                                           hover:bg-gray-100"
                                    onclick="App.actions.logout()"
                                >
                                    ログアウト
                                </button>

                            </nav>

                        </div>
                    </header>

                    <main
                        id="main_content"
                        class="max-w-7xl mx-auto px-4 py-6"
                    ></main>

                </div>
            `;
        },

        current: function () {

            if (App.state.screen === 'list') {
                this.list();
                return;
            }

            if (App.state.screen === 'edit') {
                this.editor();
                return;
            }

            if (App.state.screen === 'settings') {
                this.settings();
                return;
            }
        },

        list: function () {

            const surveys =
                App.state.surveys.filter((survey) => {

                    if (App.state.keyword) {

                        if (
                            !String(survey.title)
                                .toLowerCase()
                                .includes(
                                    App.state.keyword
                                        .toLowerCase()
                                )
                        ) {
                            return false;
                        }
                    }

                    if (
                        App.state.statusFilter &&
                        survey.status !==
                            App.state.statusFilter
                    ) {
                        return false;
                    }

                    return true;
                });

            document.getElementById(
                'main_content'
            ).innerHTML = `

                <div class="flex justify-between items-center mb-6">

                    <div>
                        <div class="text-sm text-gray-500">
                            ホーム
                        </div>

                        <h1 class="text-2xl font-bold">
                            アンケート一覧
                        </h1>
                    </div>

                    <button
                        class="bg-blue-600 text-white px-4 py-2
                               rounded-lg shadow"
                        onclick="App.actions.newSurvey()"
                    >
                        + 新規アンケート作成
                    </button>

                </div>

                <div
                    class="bg-white rounded-xl shadow p-4 mb-4
                           flex flex-wrap gap-3"
                >

                    <input
                        class="border rounded-lg px-3 py-2"
                        placeholder="タイトル検索"
                        value="${App.utils.escape(
                            App.state.keyword
                        )}"
                        oninput="App.actions.filterSurveys(this.value)"
                    >

                    <select
                        class="border rounded-lg px-3 py-2"
                        onchange="App.actions.toggleStatusFilter(this.value)"
                    >
                        <option value="">すべて</option>
                        <option
                            value="draft"
                            ${App.state.statusFilter === 'draft'
                                ? 'selected'
                                : ''}
                        >
                            下書き
                        </option>
                        <option
                            value="active"
                            ${App.state.statusFilter === 'active'
                                ? 'selected'
                                : ''}
                        >
                            公開中
                        </option>
                        <option
                            value="ended"
                            ${App.state.statusFilter === 'ended'
                                ? 'selected'
                                : ''}
                        >
                            終了
                        </option>
                    </select>

                </div>

                <div class="bg-white rounded-xl shadow overflow-x-auto">

                    <table class="w-full text-sm">

                        <thead class="bg-gray-50">
                            <tr>
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

                        <tbody>

                            ${
                                surveys.length
                                    ? surveys.map(
                                        (survey) =>
                                            this.surveyRow(
                                                survey
                                            )
                                    ).join('')
                                    : `
                                    <tr>
                                        <td
                                            colspan="7"
                                            class="p-8 text-center
                                                   text-gray-500"
                                        >
                                            アンケートはありません。
                                        </td>
                                    </tr>
                                    `
                            }

                        </tbody>

                    </table>

                </div>
            `;
        },

        surveyRow: function (survey) {

            const statusText = {
                draft: '下書き',
                active: '公開中',
                ended: '終了'
            }[survey.status] || survey.status;

            return `
                <tr class="border-t">

                    <td class="p-3">
                        ${App.utils.escape(
                            survey.updated_at
                        )}
                    </td>

                    <td class="p-3 font-medium">
                        ${App.utils.escape(
                            survey.title
                        )}
                    </td>

                    <td class="p-3">
                        ${App.utils.escape(
                            survey.start_at
                        )}
                    </td>

                    <td class="p-3">
                        ${App.utils.escape(
                            survey.end_at
                        )}
                    </td>

                    <td class="p-3">
                        <span
                            class="px-2 py-1 rounded-full
                                   bg-gray-100"
                        >
                            ${App.utils.escape(
                                statusText
                            )}
                        </span>
                    </td>

                    <td class="p-3">
                        ${Number(
                            survey.response_count || 0
                        )}
                    </td>

                    <td class="p-3">

                        <div class="flex flex-wrap gap-2">

                            <button
                                class="text-blue-600"
                                onclick="App.actions.editSurvey(
                                    '${App.utils.escape(
                                        survey.id
                                    )}'
                                )"
                            >
                                確認・編集
                            </button>

                            <button
                                class="text-purple-600"
                                onclick="App.actions.duplicateSurvey(
                                    '${App.utils.escape(
                                        survey.id
                                    )}'
                                )"
                            >
                                複製
                            </button>

                            ${
                                survey.status === 'active'
                                    ? `
                                    <button
                                        class="text-orange-600"
                                        onclick="App.actions.stopSurvey(
                                            '${App.utils.escape(
                                                survey.id
                                            )}'
                                        )"
                                    >
                                        停止
                                    </button>
                                    `
                                    : ''
                            }

                            ${
                                survey.status === 'ended'
                                    ? `
                                    <button
                                        class="text-green-600"
                                        onclick="App.actions.resumeSurvey(
                                            '${App.utils.escape(
                                                survey.id
                                            )}'
                                        )"
                                    >
                                        再開
                                    </button>
                                    `
                                    : ''
                            }

                            ${
                                survey.status === 'draft'
                                    ? `
                                    <button
                                        class="text-red-600"
                                        onclick="App.actions.deleteSurvey(
                                            '${App.utils.escape(
                                                survey.id
                                            )}'
                                        )"
                                    >
                                        削除
                                    </button>
                                    `
                                    : ''
                            }

                        </div>

                    </td>

                </tr>
            `;
        },

        editor: function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                App.actions.goList();
                return;
            }

            document.getElementById(
                'main_content'
            ).innerHTML = `

                <div class="mb-6">

                    <div class="text-sm text-gray-500 mb-1">
                        ホーム
                        >
                        アンケート一覧
                        >
                        アンケート編集
                    </div>

                    <h1 class="text-2xl font-bold">
                        アンケート編集
                    </h1>

                </div>

                <div class="bg-white rounded-xl shadow p-6 mb-6">

                    <div class="grid md:grid-cols-2 gap-4">

                        <label class="block">

                            <span class="block text-sm mb-1">
                                タイトル
                            </span>

                            <input
                                id="survey_title"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escape(
                                    survey.title
                                )}"
                                oninput="App.actions.updateSurveyField(
                                    'title',
                                    this.value
                                )"
                            >

                        </label>

                        <label class="block">

                            <span class="block text-sm mb-1">
                                ステータス
                            </span>

                            <select
                                class="w-full border rounded-lg px-3 py-2"
                                onchange="App.actions.updateSurveyField(
                                    'status',
                                    this.value
                                )"
                            >
                                <option
                                    value="draft"
                                    ${survey.status === 'draft'
                                        ? 'selected'
                                        : ''}
                                >
                                    下書き
                                </option>
                                <option
                                    value="active"
                                    ${survey.status === 'active'
                                        ? 'selected'
                                        : ''}
                                >
                                    公開中
                                </option>
                                <option
                                    value="ended"
                                    ${survey.status === 'ended'
                                        ? 'selected'
                                        : ''}
                                >
                                    終了
                                </option>
                            </select>

                        </label>

                        <label class="block">

                            <span class="block text-sm mb-1">
                                開始日時
                            </span>

                            <input
                                id="survey_start_at"
                                type="datetime-local"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escape(
                                    survey.start_at
                                )}"
                                oninput="App.actions.updateSurveyField(
                                    'start_at',
                                    this.value
                                )"
                            >

                        </label>

                        <label class="block">

                            <span class="block text-sm mb-1">
                                終了日時
                            </span>

                            <input
                                id="survey_end_at"
                                type="datetime-local"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escape(
                                    survey.end_at
                                )}"
                                oninput="App.actions.updateSurveyField(
                                    'end_at',
                                    this.value
                                )"
                            >

                        </label>

                        <label class="block">

                            <span class="block text-sm mb-1">
                                質問番号形式
                            </span>

                            <select
                                id="survey_numbering_mode"
                                class="w-full border rounded-lg px-3 py-2"
                                onchange="App.actions.updateSurveyField(
                                    'numbering_mode',
                                    this.value
                                )"
                            >
                                <option
                                    value="global"
                                    ${survey.numbering_mode === 'global'
                                        ? 'selected'
                                        : ''}
                                >
                                    Q1 / Q2 / Q3
                                </option>

                                <option
                                    value="group"
                                    ${survey.numbering_mode === 'group'
                                        ? 'selected'
                                        : ''}
                                >
                                    Q1-1 / Q1-2
                                </option>
                            </select>

                        </label>

                        <label
                            class="flex items-center gap-2
                                   self-end pb-2"
                        >

                            <input
                                type="checkbox"
                                ${survey.general_response_enabled
                                    ? 'checked'
                                    : ''}
                                onchange="App.actions.updateSurveyField(
                                    'general_response_enabled',
                                    this.checked
                                )"
                            >

                            一般回答を許可する

                        </label>

                    </div>

                </div>

                <div class="bg-white rounded-xl shadow p-6">

                    <div
                        class="flex justify-between items-center mb-4"
                    >

                        <h2 class="text-lg font-bold">
                            グループ・質問
                        </h2>

                        <button
                            class="bg-blue-600 text-white
                                   px-3 py-2 rounded-lg"
                            onclick="App.actions.addGroup()"
                        >
                            + グループ追加
                        </button>

                    </div>

                    <div
                        id="question_editor"
                        data-sortable-groups
                        class="space-y-4"
                    >
                        ${
                            survey.groups.length
                                ? survey.groups.map(
                                    (group, gi) =>
                                        this.groupEditor(
                                            group,
                                            gi
                                        )
                                ).join('')
                                : `
                                <div
                                    class="text-gray-500
                                           text-center py-8"
                                >
                                    グループを追加してください。
                                </div>
                                `
                        }
                    </div>

                </div>

                <div
                    class="sticky bottom-0 bg-white border-t
                           mt-6 p-4 flex justify-end gap-2"
                >

                    <button
                        class="border px-4 py-2 rounded-lg"
                        onclick="App.actions.cancelEdit()"
                    >
                        キャンセル
                    </button>

                    <button
                        class="border border-blue-600
                               text-blue-600
                               px-4 py-2 rounded-lg"
                        onclick="App.actions.preview()"
                    >
                        プレビュー
                    </button>

                    <button
                        class="bg-blue-600 text-white
                               px-4 py-2 rounded-lg"
                        onclick="App.actions.saveSurvey()"
                    >
                        保存
                    </button>

                </div>
            `;

            this.initSortable();
        },

        groupEditor: function (group, groupIndex) {

            return `
                <section
                    class="border rounded-xl overflow-hidden"
                    data-group-id="${App.utils.escape(
                        group.id
                    )}"
                >

                    <div
                        class="bg-gray-50 p-3 flex
                               items-center gap-2"
                    >

                        <span
                            data-group-handle
                            class="cursor-move text-gray-400"
                        >
                            ☷
                        </span>

                        <input
                            class="flex-1 border rounded-lg
                                   px-3 py-2"
                            value="${App.utils.escape(
                                group.name
                            )}"
                            oninput="App.actions.updateGroupName(
                                '${App.utils.escape(
                                    group.id
                                )}',
                                this.value
                            )"
                        >

                        <button
                            class="text-red-600 px-2"
                            onclick="App.actions.deleteGroup(
                                '${App.utils.escape(
                                    group.id
                                )}'
                            )"
                        >
                            削除
                        </button>

                    </div>

                    <div
                        data-sortable-questions
                        class="p-3 space-y-3"
                    >

                        ${
                            group.questions.map(
                                (question, qi) =>
                                    this.questionEditor(
                                        group,
                                        question,
                                        qi
                                    )
                            ).join('')
                        }

                    </div>

                    <div class="p-3 border-t">

                        <button
                            class="border border-blue-600
                                   text-blue-600
                                   px-3 py-2 rounded-lg"
                            onclick="App.actions.addQuestion(
                                '${App.utils.escape(
                                    group.id
                                )}'
                            )"
                        >
                            + 質問追加
                        </button>

                    </div>

                </section>
            `;
        },

        questionEditor: function (
            group,
            question,
            questionIndex
        ) {

            return `
                <div
                    class="border rounded-lg p-4 bg-white"
                    data-question-id="${App.utils.escape(
                        question.id
                    )}"
                >

                    <div class="flex gap-3">

                        <span
                            data-question-handle
                            class="cursor-move
                                   text-gray-400 pt-2"
                        >
                            ☷
                        </span>

                        <div class="flex-1 space-y-3">

                            <div class="text-sm font-bold">
                                ${App.utils.escape(
                                    question.number ||
                                    ('Q' + (questionIndex + 1))
                                )}
                            </div>

                            <input
                                class="w-full border rounded-lg
                                       px-3 py-2"
                                value="${App.utils.escape(
                                    question.text
                                )}"
                                placeholder="質問文"
                                oninput="App.actions.updateQuestion(
                                    '${App.utils.escape(
                                        group.id
                                    )}',
                                    '${App.utils.escape(
                                        question.id
                                    )}',
                                    'text',
                                    this.value
                                )"
                            >

                            <div class="flex flex-wrap gap-3">

                                <select
                                    class="border rounded-lg
                                           px-3 py-2"
                                    onchange="App.actions.updateQuestion(
                                        '${App.utils.escape(
                                            group.id
                                        )}',
                                        '${App.utils.escape(
                                            question.id
                                        )}',
                                        'type',
                                        this.value
                                    )"
                                >

                                    <option
                                        value="single"
                                        ${question.type === 'single'
                                            ? 'selected'
                                            : ''}
                                    >
                                        単一選択
                                    </option>

                                    <option
                                        value="multiple"
                                        ${question.type === 'multiple'
                                            ? 'selected'
                                            : ''}
                                    >
                                        複数選択
                                    </option>

                                    <option
                                        value="text"
                                        ${question.type === 'text'
                                            ? 'selected'
                                            : ''}
                                    >
                                        自由記述
                                    </option>

                                </select>

                                <label
                                    class="flex items-center gap-2"
                                >

                                    <input
                                        type="checkbox"
                                        ${question.required
                                            ? 'checked'
                                            : ''}
                                        onchange="App.actions.updateQuestion(
                                            '${App.utils.escape(
                                                group.id
                                            )}',
                                            '${App.utils.escape(
                                                question.id
                                            )}',
                                            'required',
                                            this.checked
                                        )"
                                    >

                                    必須

                                </label>

                                <button
                                    class="text-red-600"
                                    onclick="App.actions.deleteQuestion(
                                        '${App.utils.escape(
                                            group.id
                                        )}',
                                        '${App.utils.escape(
                                            question.id
                                        )}'
                                    )"
                                >
                                    質問削除
                                </button>

                            </div>

                            ${
                                question.type === 'single' ||
                                question.type === 'multiple'
                                    ? `
                                    <div class="space-y-2">

                                        <div
                                            class="text-sm
                                                   text-gray-500"
                                        >
                                            選択肢
                                        </div>

                                        ${
                                            question.options.map(
                                                (option, oi) => `
                                                <div
                                                    class="flex gap-2"
                                                >
                                                    <input
                                                        class="flex-1
                                                               border
                                                               rounded-lg
                                                               px-3 py-2"
                                                        value="${App.utils.escape(
                                                            option
                                                        )}"
                                                        oninput="App.actions.updateOption(
                                                            '${App.utils.escape(
                                                                group.id
                                                            )}',
                                                            '${App.utils.escape(
                                                                question.id
                                                            )}',
                                                            ${oi},
                                                            this.value
                                                        )"
                                                    >

                                                    <button
                                                        class="text-red-600"
                                                        onclick="App.actions.deleteOption(
                                                            '${App.utils.escape(
                                                                group.id
                                                            )}',
                                                            '${App.utils.escape(
                                                                question.id
                                                            )}',
                                                            ${oi}
                                                        )"
                                                    >
                                                        ×
                                                    </button>
                                                </div>
                                            `
                                            ).join('')
                                        }

                                        <button
                                            class="text-blue-600
                                                   text-sm"
                                            onclick="App.actions.addOption(
                                                '${App.utils.escape(
                                                    group.id
                                                )}',
                                                '${App.utils.escape(
                                                    question.id
                                                )}'
                                            )"
                                        >
                                            + 選択肢追加
                                        </button>

                                    </div>
                                    `
                                    : ''
                            }

                        </div>

                    </div>

                </div>
            `;
        },

        settings: function () {

            document.getElementById(
                'main_content'
            ).innerHTML = `

                <div class="mb-6">

                    <div class="text-sm text-gray-500">
                        ホーム
                        >
                        システム設定
                    </div>

                    <h1 class="text-2xl font-bold">
                        kintone・メール連携設定
                    </h1>

                </div>

                <div
                    id="settings_form"
                    class="bg-white rounded-xl shadow p-6"
                >

                    <p class="text-gray-600">
                        テスト版設定画面です。
                    </p>

                    <div
                        id="field_message"
                        class="mt-4 text-sm"
                    ></div>

                </div>
            `;
        }
    },

    actions: {

        goList: async function () {

            App.state.screen = 'list';

            await App.api.load();

            App.render.current();
        },

        newSurvey: function () {

            const survey =
                App.utils.newSurvey();

            survey.groups.push({
                id:
                    'group_' +
                    Date.now(),

                name: '基本情報',

                questions: []
            });

            App.state.currentSurvey = survey;
            App.state.screen = 'edit';

            App.render.current();
        },

        editSurvey: function (id) {

            const survey =
                App.state.surveys.find(
                    (item) =>
                        String(item.id) === String(id)
                );

            if (!survey) {
                App.utils.notice(
                    'アンケートが見つかりません。',
                    'error'
                );
                return;
            }

            App.state.currentSurvey =
                structuredClone(survey);

            App.state.screen = 'edit';

            App.render.current();
        },

        cancelEdit: function () {

            App.state.currentSurvey = null;
            App.state.screen = 'list';

            App.render.current();
        },

        updateSurveyField: function (
            field,
            value
        ) {

            if (!App.state.currentSurvey) {
                return;
            }

            App.state.currentSurvey[field] = value;

            if (field === 'numbering_mode') {
                App.actions.renumberQuestions();
            }
        },

        addGroup: function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            survey.groups.push({
                id:
                    'group_' +
                    Date.now() +
                    '_' +
                    Math.random()
                        .toString(36)
                        .slice(2),

                name:
                    'グループ ' +
                    (survey.groups.length + 1),

                questions: []
            });

            App.actions.renumberQuestions();
            App.render.editor();
        },

        deleteGroup: function (groupId) {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            if (
                !confirm(
                    'このグループを削除しますか？'
                )
            ) {
                return;
            }

            survey.groups =
                survey.groups.filter(
                    (group) =>
                        String(group.id) !==
                        String(groupId)
                );

            App.actions.renumberQuestions();
            App.render.editor();
        },

        updateGroupName: function (
            groupId,
            value
        ) {

            const group =
                App.state.currentSurvey.groups.find(
                    (item) =>
                        String(item.id) ===
                        String(groupId)
                );

            if (group) {
                group.name = value;
            }
        },

        addQuestion: function (groupId) {

            const group =
                App.state.currentSurvey.groups.find(
                    (item) =>
                        String(item.id) ===
                        String(groupId)
                );

            if (!group) {
                return;
            }

            group.questions.push({
                id:
                    'question_' +
                    Date.now() +
                    '_' +
                    Math.random()
                        .toString(36)
                        .slice(2),

                text: '',

                type: 'text',

                required: false,

                options: [],

                other_enabled: false,

                branching: {}
            });

            App.actions.renumberQuestions();

            App.render.editor();
        },

        deleteQuestion: function (
            groupId,
            questionId
        ) {

            const group =
                App.state.currentSurvey.groups.find(
                    (item) =>
                        String(item.id) ===
                        String(groupId)
                );

            if (!group) {
                return;
            }

            group.questions =
                group.questions.filter(
                    (question) =>
                        String(question.id) !==
                        String(questionId)
                );

            App.actions.renumberQuestions();

            App.render.editor();
        },

        updateQuestion: function (
            groupId,
            questionId,
            field,
            value
        ) {

            const group =
                App.state.currentSurvey.groups.find(
                    (item) =>
                        String(item.id) ===
                        String(groupId)
                );

            if (!group) {
                return;
            }

            const question =
                group.questions.find(
                    (item) =>
                        String(item.id) ===
                        String(questionId)
                );

            if (!question) {
                return;
            }

            question[field] = value;

            if (
                field === 'type' &&
                value === 'text'
            ) {
                question.options = [];
            }

            App.render.editor();
        },

        addOption: function (
            groupId,
            questionId
        ) {

            const question =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            question.options.push(
                '選択肢 ' +
                (question.options.length + 1)
            );

            App.render.editor();
        },

        updateOption: function (
            groupId,
            questionId,
            index,
            value
        ) {

            const question =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            question.options[index] = value;
        },

        deleteOption: function (
            groupId,
            questionId,
            index
        ) {

            const question =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            question.options.splice(index, 1);

            App.render.editor();
        },

        findQuestion: function (
            groupId,
            questionId
        ) {

            const group =
                App.state.currentSurvey.groups.find(
                    (item) =>
                        String(item.id) ===
                        String(groupId)
                );

            if (!group) {
                return null;
            }

            return group.questions.find(
                (item) =>
                    String(item.id) ===
                    String(questionId)
            ) || null;
        },

        moveQuestion: function () {
            App.actions.syncQuestionStructure();
            App.actions.renumberQuestions();
        },

        syncQuestionStructure: function () {

            /*
             * SortableJSのDOMからデータを再構築する
             * 本番版ではここでgroup間移動も確定する。
             */
        },

        renumberQuestions: function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            let global = 1;

            survey.groups.forEach(
                (group, groupIndex) => {

                    group.questions.forEach(
                        (question, questionIndex) => {

                            question.number =
                                survey.numbering_mode ===
                                'group'
                                    ? `Q${groupIndex + 1}-${questionIndex + 1}`
                                    : `Q${global}`;

                            global++;
                        }
                    );
                }
            );
        },

        saveSurvey: async function () {

            if (!App.state.currentSurvey) {
                return;
            }

            App.actions.renumberQuestions();

            try {

                const json =
                    await App.api.request(
                        'save_survey',
                        {
                            survey_json:
                                App.state.currentSurvey
                        }
                    );

                App.state.currentSurvey =
                    json.survey;

                App.utils.notice(
                    'アンケートを保存しました。',
                    'info'
                );

                await App.api.load();

                App.state.screen = 'list';

                App.render.current();

            } catch (error) {

                App.utils.notice(
                    error.message,
                    'error'
                );
            }
        },

        duplicateSurvey: async function (id) {

            if (
                !confirm(
                    'このアンケートを複製しますか？'
                )
            ) {
                return;
            }

            try {

                await App.api.request(
                    'duplicate_survey',
                    {
                        survey_id: id
                    }
                );

                await App.api.load();

                App.render.list();

                App.utils.notice(
                    'アンケートを複製しました。'
                );

            } catch (error) {

                App.utils.notice(
                    error.message,
                    'error'
                );
            }
        },

        deleteSurvey: async function (id) {

            if (
                !confirm(
                    'このアンケートを削除しますか？'
                )
            ) {
                return;
            }

            try {

                await App.api.request(
                    'delete_survey',
                    {
                        survey_id: id
                    }
                );

                await App.api.load();

                App.render.list();

            } catch (error) {

                App.utils.notice(
                    error.message,
                    'error'
                );
            }
        },

        stopSurvey: async function (id) {

            if (
                !confirm(
                    'アンケートを停止しますか？'
                )
            ) {
                return;
            }

            try {

                await App.api.request(
                    'stop_survey',
                    {
                        survey_id: id
                    }
                );

                await App.api.load();

                App.render.list();

            } catch (error) {

                App.utils.notice(
                    error.message,
                    'error'
                );
            }
        },

        resumeSurvey: async function (id) {

            try {

                await App.api.request(
                    'resume_survey',
                    {
                        survey_id: id
                    }
                );

                await App.api.load();

                App.render.list();

            } catch (error) {

                App.utils.notice(
                    error.message,
                    'error'
                );
            }
        },

        filterSurveys: function (value) {

            App.state.keyword = value;
            App.render.list();
        },

        toggleStatusFilter: function (value) {

            App.state.statusFilter = value;
            App.render.list();
        },

        sortSurveys: function (value) {

            App.state.sort = value;
            App.render.list();
        },

        preview: function () {

            if (!App.state.currentSurvey) {
                return;
            }

            const survey =
                App.state.currentSurvey;

            const html = survey.groups
                .map(
                    (group) => `

                    <section class="mb-6">

                        <h3 class="font-bold text-lg mb-3">
                            ${App.utils.escape(
                                group.name
                            )}
                        </h3>

                        ${group.questions.map(
                            (question) => `

                            <div
                                class="border rounded-lg
                                       p-4 mb-3"
                            >

                                <div class="font-medium mb-3">
                                    ${App.utils.escape(
                                        question.number
                                    )}
                                    .
                                    ${App.utils.escape(
                                        question.text
                                    )}

                                    ${
                                        question.required
                                            ? '<span class="text-red-600"> *</span>'
                                            : ''
                                    }
                                </div>

                                ${
                                    question.type === 'text'
                                        ? `
                                        <textarea
                                            class="w-full border
                                                   rounded-lg p-2"
                                            rows="3"
                                            disabled
                                        ></textarea>
                                        `
                                        : question.options
                                            .map(
                                                (option) => `
                                                <label
                                                    class="block mb-2"
                                                >
                                                    <input
                                                        type="${
                                                            question.type ===
                                                            'single'
                                                                ? 'radio'
                                                                : 'checkbox'
                                                        }"
                                                        disabled
                                                    >
                                                    ${App.utils.escape(
                                                        option
                                                    )}
                                                </label>
                                                `
                                            )
                                            .join('')
                                }

                            </div>
                        `
                        ).join('')}

                    </section>
                `
                )
                .join('');

            const modal =
                document.createElement('div');

            modal.id = 'preview_modal';

            modal.className =
                'fixed inset-0 z-50 bg-black/50 ' +
                'flex items-center justify-center p-4';

            modal.innerHTML = `

                <div
                    class="bg-white rounded-xl shadow-xl
                           max-w-3xl w-full max-h-[90vh]
                           overflow-auto"
                >

                    <div
                        class="sticky top-0 bg-white border-b
                               p-4 flex justify-between"
                    >

                        <h2 class="font-bold">
                            プレビュー
                        </h2>

                        <button
                            onclick="this.closest(
                                '#preview_modal'
                            ).remove()"
                        >
                            ×
                        </button>

                    </div>

                    <div
                        id="preview_content"
                        class="p-6"
                    >
                        ${html}

                        <div
                            class="mt-6 p-3 bg-yellow-50
                                   text-yellow-800 rounded-lg"
                        >
                            プレビューのため送信されません。
                        </div>

                    </div>

                </div>
            `;

            document.body.appendChild(modal);
        },

        showSettings: function () {

            App.state.screen = 'settings';

            App.render.current();
        },

        logout: function () {

            /*
             * テスト版では管理者ログインを省略。
             * 実運用版で認証処理を追加可能。
             */

            App.utils.notice(
                'テスト版ではログアウト処理を省略しています。'
            );
        },

        fetchKintoneFields: async function () {
            App.utils.notice(
                'kintone API連携は設定後に実行します。'
            );
        },

        syncCustomers: async function () {
            App.utils.notice(
                'kintone顧客同期APIを実行します。'
            );
        },

        sendMail: async function () {
            App.utils.notice(
                'SMTP送信機能を実行します。'
            );
        },

        sendReminder: async function () {
            App.utils.notice(
                'リマインド送信機能を実行します。'
            );
        },

        showSentMail: function () {},

        showAllResponses: function () {},

        toggleResponseFilter: function () {},

        startGeneralResponse: function () {},

        startIndividualResponse: function () {},

        validateResponse: function () {
            return true;
        },

        confirmResponse: function () {},

        submitResponse: function () {},

        restoreDraftResponse: function () {},

        clearDraftResponse: function () {},

        startResurvey: function () {}
    }
};

if (document.readyState === 'loading') {

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
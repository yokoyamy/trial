<?php
declare(strict_types=1);

/*
========================================================================
GUARD COMMENT — 固定名称一覧
※以下の名称は変更・削除禁止。

ストレージ:
- survey_storage_directory
- survey_storage_file
- survey_admin_session_v1

データトップキー:
- surveys
- responses
- customers
- settings
- mail_logs

アンケート項目:
- id
- title
- start_at
- end_at
- status
- created_at
- updated_at
- numbering_mode
- groups
- deleted

グループ項目:
- id
- name
- questions

質問項目:
- id
- text
- type
- required
- options
- other_enabled
- branching

分岐項目:
- option
- target_question_id

質問形式:
- single
- multiple
- text

顧客項目:
- id
- company
- name
- email
- department
- phone
- address
- source
- sent_at
- send_count
- answer_status
- kintone_status

回答項目:
- id
- survey_id
- customer_id
- company
- name
- email
- answered_at
- answers

設定項目:
- subdomain
- login_name
- password
- app_id
- ssl_verify
- proxy
- field_company
- field_name
- field_email
- field_department
- field_phone
- field_address

POST/GETパラメータ:
- action
- survey_id
- customer_id
- response_id
- keyword
- status_filter
- sort
- survey_json
- settings_json
- csrf_token
- recipient_ids
- mail_subject
- mail_body
- template_type
- app_id

API/JSONキー:
- properties
- records
- label
- code
- type
- message
- ok
- fields
- data

HTML DOM ID / JS参照名:
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

取り得る値:
- status: draft / active / ended
- numbering_mode: global / group
- type: single / multiple / text
- source: kintone / web
- answer_status: unanswered / answered
- kintone_status: unregistered / registered
- template_type: initial / reminder
========================================================================
*/

const SURVEY_STORAGE_DIRECTORY =
    __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';

const SURVEY_STORAGE_FILE =
    SURVEY_STORAGE_DIRECTORY .
    DIRECTORY_SEPARATOR .
    'survey_data.json';

const SURVEY_ADMIN_SESSION =
    'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* ============================================================
 * PHP utilities
 * ============================================================ */

function survey_default_data(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'subdomain' => '',
            'login_name' => '',
            'password' => '',
            'app_id' => '',
            'ssl_verify' => false,
            'proxy' => '',
            'field_company' => '',
            'field_name' => '',
            'field_email' => '',
            'field_department' => '',
            'field_phone' => '',
            'field_address' => []
        ],
        'mail_logs' => []
    ];
}

function survey_load(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        $data = survey_default_data();
        survey_save($data);
        return $data;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        $data = survey_default_data();
    }

    $default = survey_default_data();

    foreach ($default as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    if (!is_array($data['surveys'])) {
        $data['surveys'] = [];
    }

    if (!is_array($data['responses'])) {
        $data['responses'] = [];
    }

    if (!is_array($data['customers'])) {
        $data['customers'] = [];
    }

    if (!is_array($data['mail_logs'])) {
        $data['mail_logs'] = [];
    }

    $data['settings'] = array_merge(
        $default['settings'],
        is_array($data['settings'])
            ? $data['settings']
            : []
    );

    return $data;
}

function survey_save(array $data): bool
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    if (@file_put_contents(
        $tmp,
        $json,
        LOCK_EX
    ) === false) {
        return false;
    }

    return @rename(
        $tmp,
        SURVEY_STORAGE_FILE
    );
}

function survey_id(string $prefix): string
{
    try {
        return $prefix . '_' . bin2hex(
            random_bytes(8)
        );
    } catch (Throwable) {
        return $prefix . '_' . uniqid('', true);
    }
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function survey_csrf(): string
{
    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function survey_check_csrf(): void
{
    $token = (string)(
        $_POST['csrf_token'] ?? ''
    );

    if (!hash_equals(
        survey_csrf(),
        $token
    )) {
        survey_json([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

/* ============================================================
 * Normalization
 * ============================================================ */

/*
 * ★★★ 今回の不具合対策の最重要関数 ★★★
 *
 * type=text の場合は options を必ず [] にする。
 */
function survey_normalize_question(
    mixed $value
): array {

    $q = is_array($value)
        ? $value
        : [];

    $q['id'] = (string)(
        $q['id'] ?? survey_id('question')
    );

    $q['text'] = (string)(
        $q['text'] ?? ''
    );

    $type = (string)(
        $q['type'] ?? 'single'
    );

    if (!in_array(
        $type,
        ['single', 'multiple', 'text'],
        true
    )) {
        $type = 'single';
    }

    $q['type'] = $type;
    $q['required'] = !empty($q['required']);
    $q['other_enabled'] =
        !empty($q['other_enabled']);

    /*
     * --------------------------------------------------------
     * 自由記述なら選択肢を完全破棄
     * --------------------------------------------------------
     */
    if ($type === 'text') {

        $q['options'] = [];
        $q['other_enabled'] = false;
        $q['branching'] = [];

        return $q;
    }

    /*
     * --------------------------------------------------------
     * 選択式
     * --------------------------------------------------------
     */

    if (!is_array($q['options'] ?? null)) {
        $q['options'] = [];
    }

    $q['options'] = array_values(
        array_map(
            static function ($v): string {
                return is_scalar($v)
                    ? (string)$v
                    : '';
            },
            $q['options']
        )
    );

    if (!$q['options']) {
        $q['options'] = ['選択肢1'];
    }

    if ($type !== 'single') {
        $q['branching'] = [];
        return $q;
    }

    if (!is_array($q['branching'] ?? null)) {
        $q['branching'] = [];
    }

    $branch = [];

    foreach ($q['options'] as $option) {

        $target = '';

        foreach ($q['branching'] as $old) {

            if (
                is_array($old) &&
                (string)($old['option'] ?? '') ===
                $option
            ) {
                $target = (string)(
                    $old['target_question_id'] ?? ''
                );
                break;
            }
        }

        $branch[] = [
            'option' => $option,
            'target_question_id' => $target
        ];
    }

    $q['branching'] = $branch;

    return $q;
}

function survey_normalize(array $survey): array
{
    $survey['id'] = (string)(
        $survey['id'] ??
        survey_id('survey')
    );

    $survey['title'] = (string)(
        $survey['title'] ??
        '新しいアンケート'
    );

    $survey['start_at'] = (string)(
        $survey['start_at'] ?? ''
    );

    $survey['end_at'] = (string)(
        $survey['end_at'] ?? ''
    );

    $survey['status'] =
        in_array(
            $survey['status'] ?? '',
            ['draft', 'active', 'ended'],
            true
        )
        ? $survey['status']
        : 'draft';

    $survey['numbering_mode'] =
        ($survey['numbering_mode'] ?? 'global')
        === 'group'
            ? 'group'
            : 'global';

    $survey['deleted'] =
        !empty($survey['deleted']);

    if (!is_array($survey['groups'] ?? null)) {
        $survey['groups'] = [];
    }

    foreach (
        $survey['groups']
        as &$group
    ) {

        if (!is_array($group)) {
            $group = [];
        }

        $group['id'] = (string)(
            $group['id'] ??
            survey_id('group')
        );

        $group['name'] = (string)(
            $group['name'] ??
            'グループ'
        );

        if (!is_array($group['questions'] ?? null)) {
            $group['questions'] = [];
        }

        foreach (
            $group['questions']
            as &$question
        ) {
            $question =
                survey_normalize_question(
                    $question
                );
        }

        unset($question);
    }

    unset($group);

    if (!$survey['groups']) {

        $survey['groups'][] = [
            'id' => survey_id('group'),
            'name' => 'グループ1',
            'questions' => []
        ];
    }

    return $survey;
}

/* ============================================================
 * API
 * ============================================================ */

$data = survey_load();

if (
    isset($_REQUEST['action']) &&
    $_REQUEST['action'] !== ''
) {

    $action = (string)$_REQUEST['action'];

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
    ) {
        survey_check_csrf();
    }

    switch ($action) {

        case 'load':

            survey_json([
                'ok' => true,
                'data' => $data,
                'csrf_token' => survey_csrf()
            ]);

        case 'save_survey':

            $survey = json_decode(
                (string)(
                    $_POST['survey_json'] ?? ''
                ),
                true
            );

            if (!is_array($survey)) {
                survey_json([
                    'ok' => false,
                    'message' =>
                        'アンケートデータが不正です。'
                ], 400);
            }

            /*
             * PHP側でも必ず正規化。
             *
             * JS側で消し忘れても、
             * text の options は [] になる。
             */
            $survey =
                survey_normalize($survey);

            $now = survey_now();
            $found = false;

            foreach (
                $data['surveys']
                as $index => $old
            ) {

                if (
                    (string)$old['id'] ===
                    (string)$survey['id']
                ) {

                    $survey['created_at'] =
                        $old['created_at']
                        ?? $now;

                    $survey['updated_at'] = $now;

                    $data['surveys'][$index] =
                        $survey;

                    $found = true;
                    break;
                }
            }

            if (!$found) {

                $survey['created_at'] = $now;
                $survey['updated_at'] = $now;
                $survey['deleted'] = false;

                $data['surveys'][] = $survey;
            }

            if (!survey_save($data)) {
                survey_json([
                    'ok' => false,
                    'message' =>
                        '保存に失敗しました。'
                ], 500);
            }

            survey_json([
                'ok' => true,
                'survey' => $survey
            ]);

        case 'delete_survey':

            $id = (string)(
                $_POST['survey_id'] ?? ''
            );

            foreach (
                $data['surveys']
                as &$survey
            ) {

                if (
                    (string)$survey['id'] ===
                    $id
                ) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] =
                        survey_now();
                    break;
                }
            }

            unset($survey);

            survey_save($data);

            survey_json([
                'ok' => true
            ]);

        case 'duplicate_survey':

            $id = (string)(
                $_POST['survey_id'] ?? ''
            );

            $copy = null;

            foreach (
                $data['surveys']
                as $survey
            ) {

                if (
                    (string)$survey['id'] ===
                    $id
                ) {
                    $copy = $survey;
                    break;
                }
            }

            if (!$copy) {
                survey_json([
                    'ok' => false,
                    'message' =>
                        'アンケートがありません。'
                ], 404);
            }

            $copy['id'] =
                survey_id('survey');

            $copy['title'] .= '（複製）';
            $copy['status'] = 'draft';
            $copy['deleted'] = false;
            $copy['created_at'] =
                survey_now();
            $copy['updated_at'] =
                survey_now();

            $data['surveys'][] = $copy;

            survey_save($data);

            survey_json([
                'ok' => true,
                'survey' => $copy
            ]);

        case 'change_status':

            $id = (string)(
                $_POST['survey_id'] ?? ''
            );

            $status = (string)(
                $_POST['status'] ?? ''
            );

            if (!in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )) {
                survey_json([
                    'ok' => false,
                    'message' =>
                        'ステータスが不正です。'
                ], 400);
            }

            foreach (
                $data['surveys']
                as &$survey
            ) {

                if (
                    (string)$survey['id'] ===
                    $id
                ) {
                    $survey['status'] =
                        $status;

                    $survey['updated_at'] =
                        survey_now();

                    break;
                }
            }

            unset($survey);

            survey_save($data);

            survey_json([
                'ok' => true
            ]);

        case 'save_settings':

            $settings = json_decode(
                (string)(
                    $_POST['settings_json'] ?? ''
                ),
                true
            );

            if (!is_array($settings)) {
                survey_json([
                    'ok' => false,
                    'message' =>
                        '設定データが不正です。'
                ], 400);
            }

            if (
                empty($settings['password']) &&
                !empty($data['settings']['password'])
            ) {
                $settings['password'] =
                    $data['settings']['password'];
            }

            $data['settings'] = array_merge(
                survey_default_data()['settings'],
                $settings
            );

            survey_save($data);

            survey_json([
                'ok' => true
            ]);

        case 'kintone_fields':

            $settings =
                $data['settings'];

            $appId = trim(
                (string)(
                    $_POST['app_id'] ??
                    $settings['app_id'] ??
                    ''
                )
            );

            if (
                $appId === '' ||
                !ctype_digit($appId)
            ) {
                survey_json([
                    'ok' => false,
                    'message' =>
                        'アプリIDを入力してください。'
                ], 400);
            }

            $host =
                trim(
                    (string)(
                        $settings['subdomain']
                        ?? ''
                    )
                );

            $host = preg_replace(
                '#^https?://#i',
                '',
                $host
            );

            $host = preg_replace(
                '#/.*$#',
                '',
                (string)$host
            );

            if (
                !str_ends_with(
                    strtolower($host),
                    '.cybozu.com'
                )
            ) {
                $host .= '.cybozu.com';
            }

            $url =
                'https://' .
                $host .
                '/k/v1/app/form/fields.json?app=' .
                rawurlencode($appId);

            $context =
                stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' =>
                            "Accept: application/json\r\n" .
                            "X-Cybozu-Authorization: " .
                            base64_encode(
                                (string)(
                                    $settings['login_name']
                                    ?? ''
                                ) .
                                ':' .
                                (string)(
                                    $settings['password']
                                    ?? ''
                                )
                            ),
                        'ignore_errors' => true,
                        'timeout' => 30
                    ],
                    'ssl' => [
                        'verify_peer' =>
                            !empty(
                                $settings['ssl_verify']
                            ),
                        'verify_peer_name' =>
                            !empty(
                                $settings['ssl_verify']
                            ),
                        'allow_self_signed' =>
                            empty(
                                $settings['ssl_verify']
                            )
                    ]
                ]);

            $raw = @file_get_contents(
                $url,
                false,
                $context
            );

            $json = json_decode(
                (string)$raw,
                true
            );

            if (
                !is_array($json) ||
                !is_array(
                    $json['properties'] ?? null
                )
            ) {
                survey_json([
                    'ok' => false,
                    'message' =>
                        'kintoneの項目取得に失敗しました。',
                    'data' => $json
                ], 502);
            }

            survey_json([
                'ok' => true,
                'fields' =>
                    $json['properties']
            ]);

        case 'save_response':

            $response = json_decode(
                (string)(
                    $_POST['answers'] ?? ''
                ),
                true
            );

            if (!is_array($response)) {
                survey_json([
                    'ok' => false,
                    'message' =>
                        '回答データが不正です。'
                ], 400);
            }

            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $surveyIndex = null;

            foreach (
                $data['surveys']
                as $survey
            ) {

                if (
                    (string)$survey['id'] ===
                    $surveyId
                ) {
                    $surveyIndex = $survey;
                    break;
                }
            }

            if (!$surveyIndex) {
                survey_json([
                    'ok' => false,
                    'message' =>
                        'アンケートがありません。'
                ], 404);
            }

            $data['responses'][] = [
                'id' => survey_id('response'),
                'survey_id' => $surveyId,
                'customer_id' =>
                    (string)(
                        $_POST['customer_id']
                        ?? ''
                    ),
                'company' =>
                    (string)(
                        $_POST['company']
                        ?? ''
                    ),
                'name' =>
                    (string)(
                        $_POST['name']
                        ?? ''
                    ),
                'email' =>
                    (string)(
                        $_POST['email']
                        ?? ''
                    ),
                'answered_at' =>
                    survey_now(),
                'answers' => $response
            ];

            survey_save($data);

            survey_json([
                'ok' => true
            ]);
    }
}

/* ============================================================
 * HTML
 * ============================================================ */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-50 text-slate-800">

<div id="app"></div>

<input
    type="hidden"
    id="csrf_token"
    value="<?= htmlspecialchars(
        survey_csrf(),
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>

<div
    id="preview_modal"
    class="fixed inset-0 z-50 hidden bg-black/50 p-6">

    <div class="mx-auto flex h-full max-w-4xl items-center">

        <div class="max-h-[90vh] w-full overflow-hidden rounded-2xl bg-white shadow-2xl">

            <div class="flex items-center justify-between border-b px-6 py-4">

                <strong>プレビュー</strong>

                <button
                    onclick="App.actions.closePreview()"
                    class="rounded-lg px-3 py-2 hover:bg-slate-100">
                    ×
                </button>

            </div>

            <div
                id="preview_content"
                class="max-h-[calc(90vh-70px)] overflow-y-auto p-6">
            </div>

        </div>
    </div>
</div>

<div
    id="response_modal"
    class="fixed inset-0 z-50 hidden bg-black/50 p-6">

    <div class="mx-auto flex h-full max-w-3xl items-center">

        <div class="max-h-[90vh] w-full overflow-hidden rounded-2xl bg-white shadow-2xl">

            <div class="flex items-center justify-between border-b px-6 py-4">

                <strong>回答詳細</strong>

                <button
                    onclick="App.actions.closeResponseModal()"
                    class="rounded-lg px-3 py-2 hover:bg-slate-100">
                    ×
                </button>

            </div>

            <div
                id="response_detail"
                class="max-h-[calc(90vh-70px)] overflow-y-auto p-6">
            </div>

        </div>
    </div>
</div>

<script>
window.App = {

    state: {
        data: null,
        page: 'list',
        survey: null,
        editing: false,
        initialized: false,
        keyword: '',
        statusFilter: 'all'
    },

    actions: {},
    render: {},
    api: {},
    util: {}
};


/* ============================================================
 * Utility
 * ============================================================ */

App.util.escape = function(value) {

    const div =
        document.createElement('div');

    div.textContent =
        value == null
            ? ''
            : String(value);

    return div.innerHTML;
};

App.util.attr = function(value) {

    return App.util.escape(value)
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

App.util.id = function(prefix) {

    return prefix +
        '_' +
        Date.now().toString(36) +
        '_' +
        Math.random()
            .toString(36)
            .slice(2, 8);
};


/* ============================================================
 * ★★★ Question normalization
 * ============================================================ */

App.util.normalizeQuestion = function(q) {

    if (!q || typeof q !== 'object') {
        q = {};
    }

    if (!q.id) {
        q.id =
            App.util.id('question');
    }

    if (typeof q.text !== 'string') {
        q.text = '';
    }

    if (
        !['single', 'multiple', 'text']
            .includes(q.type)
    ) {
        q.type = 'single';
    }

    q.required = !!q.required;

    /*
     * ========================================================
     * ★★★ 最重要 ★★★
     *
     * 自由記述になった瞬間、
     * 過去の選択肢を完全に捨てる。
     * ========================================================
     */

    if (q.type === 'text') {

        q.options = [];
        q.other_enabled = false;
        q.branching = [];

        return q;
    }

    if (!Array.isArray(q.options)) {
        q.options = [];
    }

    q.options =
        q.options.map(function(option) {

            if (
                typeof option === 'object' &&
                option !== null
            ) {
                return String(
                    option.text || ''
                );
            }

            return String(option);
        });

    if (!q.options.length) {
        q.options = ['選択肢1'];
    }

    q.other_enabled =
        !!q.other_enabled;

    if (q.type !== 'single') {
        q.branching = [];
    }

    return q;
};


/* ============================================================
 * Survey normalization
 * ============================================================ */

App.util.normalizeSurvey = function(survey) {

    if (!survey) {
        survey = {};
    }

    if (!survey.id) {
        survey.id =
            App.util.id('survey');
    }

    survey.title =
        String(
            survey.title || ''
        );

    survey.start_at =
        String(
            survey.start_at || ''
        );

    survey.end_at =
        String(
            survey.end_at || ''
        );

    if (
        !['draft', 'active', 'ended']
            .includes(survey.status)
    ) {
        survey.status = 'draft';
    }

    if (
        !['global', 'group']
            .includes(survey.numbering_mode)
    ) {
        survey.numbering_mode =
            'global';
    }

    if (!Array.isArray(survey.groups)) {
        survey.groups = [];
    }

    survey.groups.forEach(
        function(group) {

            if (!group.id) {
                group.id =
                    App.util.id('group');
            }

            if (!group.name) {
                group.name =
                    'グループ';
            }

            if (!Array.isArray(
                group.questions
            )) {
                group.questions = [];
            }

            group.questions =
                group.questions.map(
                    App.util.normalizeQuestion
                );
        }
    );

    if (!survey.groups.length) {

        survey.groups.push({
            id: App.util.id('group'),
            name: 'グループ1',
            questions: []
        });
    }

    return survey;
};


/* ============================================================
 * Numbering
 * ============================================================ */

App.util.renumber = function() {

    if (!App.state.survey) {
        return;
    }

    let no = 1;

    App.state.survey.groups
        .forEach(
            function(group, gi) {

                group.questions
                    .forEach(
                        function(q, qi) {

                            App.util
                                .normalizeQuestion(q);

                            if (
                                App.state.survey
                                    .numbering_mode
                                === 'group'
                            ) {

                                q.display_number =
                                    'Q' +
                                    (gi + 1) +
                                    '-' +
                                    (qi + 1);

                            } else {

                                q.display_number =
                                    'Q' + no;
                            }

                            no++;
                        }
                    );
            }
        );
};


/* ============================================================
 * API
 * ============================================================ */

App.api.post = async function(
    action,
    params
) {

    const body =
        new URLSearchParams();

    body.append(
        'action',
        action
    );

    body.append(
        'csrf_token',
        document.getElementById(
            'csrf_token'
        ).value
    );

    Object.keys(
        params || {}
    ).forEach(function(key) {

        body.append(
            key,
            typeof params[key] === 'string'
                ? params[key]
                : JSON.stringify(params[key])
        );
    });

    const response =
        await fetch(
            location.href,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body
            }
        );

    const result =
        await response.json();

    if (!result.ok) {
        throw new Error(
            result.message ||
            '処理に失敗しました。'
        );
    }

    return result;
};

App.api.load = async function() {

    const response =
        await fetch(
            '?action=load'
        );

    const result =
        await response.json();

    if (!result.ok) {
        throw new Error(
            result.message
        );
    }

    App.state.data =
        result.data;

    if (result.csrf_token) {
        document.getElementById(
            'csrf_token'
        ).value =
            result.csrf_token;
    }
};


/* ============================================================
 * List
 * ============================================================ */

App.render.list = function() {

    App.state.page = 'list';

    const root =
        document.getElementById('app');

    let surveys =
        (App.state.data?.surveys || [])
        .filter(function(survey) {
            return !survey.deleted;
        });

    const keyword =
        App.state.keyword
            .trim()
            .toLowerCase();

    if (keyword) {

        surveys =
            surveys.filter(
                function(survey) {

                    return String(
                        survey.title || ''
                    )
                    .toLowerCase()
                    .includes(keyword);
                }
            );
    }

    if (
        App.state.statusFilter !==
        'all'
    ) {

        surveys =
            surveys.filter(
                function(survey) {

                    return survey.status ===
                        App.state.statusFilter;
                }
            );
    }

    let html = `
        <header class="sticky top-0 z-30 border-b bg-white">

            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">

                <div>
                    <div class="text-xs text-slate-500">
                        アンケート管理システム
                    </div>

                    <h1 class="text-xl font-bold">
                        アンケート一覧
                    </h1>
                </div>

                <div class="flex gap-2">

                    <button
                        onclick="App.actions.newSurvey()"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                        ＋新規アンケート
                    </button>

                    <button
                        onclick="App.actions.settings()"
                        class="rounded-lg border bg-white px-4 py-2 text-sm hover:bg-slate-50">
                        kintone連携設定
                    </button>

                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-6 py-8">

            <div class="mb-5 rounded-xl border bg-white p-4">

                <div class="flex gap-3">

                    <input
                        id="customer_filter"
                        value="${App.util.attr(App.state.keyword)}"
                        onkeydown="if(event.key==='Enter') App.actions.search(this.value)"
                        placeholder="タイトルを検索"
                        class="flex-1 rounded-lg border px-3 py-2"
                    >

                    <select
                        onchange="App.actions.filterStatus(this.value)"
                        class="rounded-lg border px-3 py-2">

                        <option value="all"
                            ${App.state.statusFilter === 'all' ? 'selected' : ''}>
                            すべて
                        </option>

                        <option value="active"
                            ${App.state.statusFilter === 'active' ? 'selected' : ''}>
                            公開中
                        </option>

                        <option value="draft"
                            ${App.state.statusFilter === 'draft' ? 'selected' : ''}>
                            下書き
                        </option>

                        <option value="ended"
                            ${App.state.statusFilter === 'ended' ? 'selected' : ''}>
                            終了
                        </option>

                    </select>

                    <button
                        onclick="App.actions.search(document.getElementById('customer_filter').value)"
                        class="rounded-lg bg-slate-800 px-4 py-2 text-white">
                        検索
                    </button>

                </div>
            </div>

            <div class="overflow-hidden rounded-xl border bg-white">

                <table class="w-full">

                    <thead class="border-b bg-slate-50 text-left text-sm">

                        <tr>
                            <th class="px-5 py-4">タイトル</th>
                            <th class="px-5 py-4">期間</th>
                            <th class="px-5 py-4">ステータス</th>
                            <th class="px-5 py-4">回答数</th>
                            <th class="px-5 py-4">更新日</th>
                            <th class="px-5 py-4">操作</th>
                        </tr>

                    </thead>

                    <tbody>
    `;

    if (!surveys.length) {

        html += `
            <tr>
                <td
                    colspan="6"
                    class="px-5 py-16 text-center text-slate-400">
                    アンケートがありません
                </td>
            </tr>
        `;

    } else {

        surveys.forEach(
            function(survey) {

                const answers =
                    (
                        App.state.data
                            .responses || []
                    )
                    .filter(
                        function(response) {
                            return response.survey_id ===
                                survey.id;
                        }
                    ).length;

                const statusText = {
                    draft: '下書き',
                    active: '公開中',
                    ended: '終了'
                }[survey.status];

                const statusClass = {
                    draft:
                        'bg-slate-100 text-slate-600',
                    active:
                        'bg-green-100 text-green-700',
                    ended:
                        'bg-red-100 text-red-700'
                }[survey.status];

                html += `
                    <tr class="border-b last:border-0 hover:bg-slate-50">

                        <td class="px-5 py-4">

                            <div class="font-bold">
                                ${App.util.escape(
                                    survey.title
                                )}
                            </div>

                            <div class="mt-1 text-xs text-slate-400">
                                作成:
                                ${App.util.escape(
                                    survey.created_at || ''
                                )}
                            </div>

                        </td>

                        <td class="px-5 py-4 text-sm">
                            ${
                                survey.start_at ||
                                survey.end_at
                                    ? App.util.escape(
                                        survey.start_at
                                    ) +
                                    ' ～ ' +
                                    App.util.escape(
                                        survey.end_at
                                    )
                                    : '未設定'
                            }
                        </td>

                        <td class="px-5 py-4">

                            <span class="rounded-full px-3 py-1 text-xs font-semibold ${statusClass}">
                                ${statusText}
                            </span>

                        </td>

                        <td class="px-5 py-4">
                            ${answers} 件
                        </td>

                        <td class="px-5 py-4 text-sm">
                            ${App.util.escape(
                                survey.updated_at || ''
                            )}
                        </td>

                        <td class="px-5 py-4">

                            <div class="flex flex-wrap gap-2">

                                <button
                                    onclick="App.actions.editSurvey('${App.util.attr(survey.id)}')"
                                    class="rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700">
                                    確認・編集
                                </button>

                                <button
                                    onclick="App.actions.analytics('${App.util.attr(survey.id)}')"
                                    class="rounded-lg bg-slate-100 px-3 py-2 text-xs">
                                    集計
                                </button>

                                <button
                                    onclick="App.actions.duplicate('${App.util.attr(survey.id)}')"
                                    class="rounded-lg bg-slate-100 px-3 py-2 text-xs">
                                    複製
                                </button>

                                ${
                                    survey.status === 'active'
                                    ? `
                                        <button
                                            onclick="App.actions.changeStatus('${App.util.attr(survey.id)}','ended')"
                                            class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
                                            停止
                                        </button>
                                    `
                                    : ''
                                }

                                ${
                                    survey.status === 'draft'
                                    ? `
                                        <button
                                            onclick="App.actions.deleteSurvey('${App.util.attr(survey.id)}')"
                                            class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
                                            削除
                                        </button>
                                    `
                                    : ''
                                }

                                ${
                                    survey.status === 'draft'
                                    ? `
                                        <button
                                            onclick="App.actions.changeStatus('${App.util.attr(survey.id)}','active')"
                                            class="rounded-lg bg-green-50 px-3 py-2 text-xs text-green-700">
                                            公開
                                        </button>
                                    `
                                    : ''
                                }

                            </div>

                        </td>

                    </tr>
                `;
            }
        );
    }

    html += `
                    </tbody>
                </table>
            </div>
        </main>
    `;

    root.innerHTML = html;
};


/* ============================================================
 * List actions
 * ============================================================ */

App.actions.search = function(value) {

    App.state.keyword =
        value || '';

    App.render.list();
};

App.actions.filterStatus = function(value) {

    App.state.statusFilter =
        value;

    App.render.list();
};

App.actions.newSurvey = function() {

    App.state.survey =
        App.util.normalizeSurvey({
            id: App.util.id('survey'),
            title: '新しいアンケート',
            status: 'draft',
            numbering_mode: 'global',
            groups: [
                {
                    id: App.util.id('group'),
                    name: 'グループ1',
                    questions: []
                }
            ]
        });

    App.state.page =
        'editor';

    App.render.editor();
};

App.actions.editSurvey = function(id) {

    const survey =
        App.state.data.surveys
            .find(
                function(s) {
                    return s.id === id;
                }
            );

    if (!survey) {
        return;
    }

    App.state.survey =
        JSON.parse(
            JSON.stringify(survey)
        );

    App.state.survey =
        App.util.normalizeSurvey(
            App.state.survey
        );

    App.state.page =
        'editor';

    App.render.editor();
};

App.actions.deleteSurvey = async function(id) {

    if (!confirm(
        'このアンケートを削除しますか？'
    )) {
        return;
    }

    try {

        await App.api.post(
            'delete_survey',
            {
                survey_id: id
            }
        );

        await App.api.load();
        App.render.list();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.duplicate = async function(id) {

    try {

        await App.api.post(
            'duplicate_survey',
            {
                survey_id: id
            }
        );

        await App.api.load();
        App.render.list();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.changeStatus =
    async function(id, status) {

        const text =
            status === 'active'
                ? '公開しますか？'
                : '停止しますか？';

        if (!confirm(text)) {
            return;
        }

        try {

            await App.api.post(
                'change_status',
                {
                    survey_id: id,
                    status
                }
            );

            await App.api.load();
            App.render.list();

        } catch (error) {
            alert(error.message);
        }
    };


/* ============================================================
 * Editor
 * ============================================================ */

App.render.editor = function() {

    const root =
        document.getElementById('app');

    const survey =
        App.state.survey;

    App.util.renumber();

    let html = `
        <header class="sticky top-0 z-30 border-b bg-white">

            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">

                <div class="flex-1">

                    <div class="text-xs text-slate-400">
                        アンケート作成・編集
                    </div>

                    <input
                        id="survey_title"
                        value="${App.util.attr(
                            survey.title
                        )}"
                        onchange="App.actions.updateSurveyTitle(this.value)"
                        class="mt-1 w-full max-w-2xl border-b border-transparent text-xl font-bold outline-none focus:border-blue-500"
                    >

                </div>

                <div class="flex gap-2">

                    <button
                        onclick="App.actions.openPreview()"
                        class="rounded-lg bg-slate-100 px-4 py-2 text-sm">
                        プレビュー
                    </button>

                    <button
                        onclick="App.actions.saveSurvey()"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">
                        保存して一覧へ戻る
                    </button>

                    <button
                        onclick="App.actions.cancelEdit()"
                        class="rounded-lg border px-4 py-2 text-sm">
                        キャンセル
                    </button>

                </div>

            </div>
        </header>

        <main class="mx-auto max-w-7xl px-6 py-8">

            <div class="mb-6 grid grid-cols-3 gap-4 rounded-xl border bg-white p-5">

                <label>
                    <span class="text-xs font-semibold">
                        開始日時
                    </span>

                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        value="${App.util.attr(
                            survey.start_at
                        )}"
                        onchange="App.actions.updateSurveyField('start_at',this.value)"
                        class="mt-2 w-full rounded-lg border px-3 py-2"
                    >
                </label>

                <label>
                    <span class="text-xs font-semibold">
                        終了日時
                    </span>

                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        value="${App.util.attr(
                            survey.end_at
                        )}"
                        onchange="App.actions.updateSurveyField('end_at',this.value)"
                        class="mt-2 w-full rounded-lg border px-3 py-2"
                    >
                </label>

                <label>
                    <span class="text-xs font-semibold">
                        質問番号
                    </span>

                    <select
                        id="survey_numbering_mode"
                        onchange="App.actions.updateNumberingMode(this.value)"
                        class="mt-2 w-full rounded-lg border px-3 py-2">

                        <option
                            value="global"
                            ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                            Q1, Q2, Q3...
                        </option>

                        <option
                            value="group"
                            ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                            Q1-1, Q1-2...
                        </option>

                    </select>
                </label>

            </div>

            <div
                id="question_editor"
                class="space-y-6">
    `;

    survey.groups.forEach(
        function(group) {

            html += `
                <section
                    class="group-card rounded-xl border bg-white"
                    data-group-id="${App.util.attr(
                        group.id
                    )}">

                    <div class="flex items-center gap-3 border-b bg-slate-50 px-5 py-4">

                        <span class="group-handle cursor-move text-xl text-slate-400">
                            ⠿
                        </span>

                        <input
                            value="${App.util.attr(
                                group.name
                            )}"
                            onchange="App.actions.updateGroupName('${App.util.attr(group.id)}',this.value)"
                            class="flex-1 rounded-lg border bg-white px-3 py-2 font-semibold"
                        >

                        <button
                            onclick="App.actions.deleteGroup('${App.util.attr(group.id)}')"
                            class="rounded-lg px-3 py-2 text-sm text-red-600">
                            グループ削除
                        </button>

                    </div>

                    <div
                        class="question-list space-y-4 p-5"
                        data-group-id="${App.util.attr(
                            group.id
                        )}">
            `;

            group.questions.forEach(
                function(q) {

                    /*
                     * ★描画直前にも正規化
                     */
                    App.util.normalizeQuestion(q);

                    html += `
                        <article
                            class="question-card rounded-xl border p-5"
                            data-question-id="${App.util.attr(q.id)}">

                            <div class="flex gap-4">

                                <span class="question-handle cursor-move pt-2 text-slate-400">
                                    ⠿
                                </span>

                                <div class="flex-1">

                                    <div class="mb-3 flex items-center justify-between">

                                        <strong class="text-blue-600">
                                            ${App.util.escape(
                                                q.display_number
                                            )}
                                        </strong>

                                        <button
                                            onclick="App.actions.deleteQuestion('${App.util.attr(q.id)}')"
                                            class="text-sm text-red-600">
                                            質問削除
                                        </button>

                                    </div>

                                    <input
                                        value="${App.util.attr(q.text)}"
                                        onchange="App.actions.updateQuestionText('${App.util.attr(q.id)}',this.value)"
                                        placeholder="質問文"
                                        class="w-full rounded-lg border px-3 py-3"
                                    >

                                    <div class="mt-4 grid grid-cols-2 gap-4">

                                        <select
                                            onchange="App.actions.changeQuestionType('${App.util.attr(q.id)}',this.value)"
                                            class="rounded-lg border px-3 py-2">

                                            <option
                                                value="single"
                                                ${q.type === 'single' ? 'selected' : ''}>
                                                単一選択
                                            </option>

                                            <option
                                                value="multiple"
                                                ${q.type === 'multiple' ? 'selected' : ''}>
                                                複数選択
                                            </option>

                                            <option
                                                value="text"
                                                ${q.type === 'text' ? 'selected' : ''}>
                                                自由記述
                                            </option>

                                        </select>

                                        <label class="flex items-center gap-2">

                                            <input
                                                type="checkbox"
                                                ${q.required ? 'checked' : ''}
                                                onchange="App.actions.toggleRequired('${App.util.attr(q.id)}',this.checked)"
                                            >

                                            必須回答

                                        </label>

                                    </div>
                    `;

                    /*
                     * ==================================================
                     * ★★★ 自由記述 ★★★
                     *
                     * q.options を表示しない。
                     * ==================================================
                     */

                    if (q.type === 'text') {

                        html += `
                            <div class="mt-4 rounded-lg bg-slate-50 p-4">

                                <div class="mb-2 text-xs font-semibold text-slate-500">
                                    自由記述
                                </div>

                                <textarea
                                    disabled
                                    rows="4"
                                    class="w-full rounded-lg border bg-white">
                                </textarea>

                            </div>
                        `;

                    } else {

                        html += `
                            <div class="mt-4 rounded-lg bg-slate-50 p-4">

                                <div class="mb-3 flex items-center justify-between">

                                    <span class="text-xs font-semibold text-slate-500">
                                        選択肢
                                    </span>

                                    <button
                                        onclick="App.actions.addOption('${App.util.attr(q.id)}')"
                                        class="rounded-lg bg-white px-3 py-2 text-xs border">
                                        ＋選択肢追加
                                    </button>

                                </div>

                                <div class="space-y-2">
                        `;

                        q.options.forEach(
                            function(option, oi) {

                                html += `
                                    <div class="flex items-center gap-2">

                                        <span class="text-slate-400">
                                            ${
                                                q.type === 'multiple'
                                                    ? '☐'
                                                    : '○'
                                            }
                                        </span>

                                        <input
                                            value="${App.util.attr(option)}"
                                            onchange="App.actions.updateOption('${App.util.attr(q.id)}',${oi},this.value)"
                                            class="flex-1 rounded-lg border bg-white px-3 py-2"
                                        >

                                        <button
                                            onclick="App.actions.removeOption('${App.util.attr(q.id)}',${oi})"
                                            class="px-2 text-red-500">
                                            ×
                                        </button>

                                    </div>
                                `;
                            }
                        );

                        html += `
                                </div>

                                <label class="mt-4 flex items-center gap-2 text-sm">

                                    <input
                                        type="checkbox"
                                        ${q.other_enabled ? 'checked' : ''}
                                        onchange="App.actions.toggleOther('${App.util.attr(q.id)}',this.checked)"
                                    >

                                    「その他」を追加

                                </label>

                            </div>
                        `;
                    }

                    html += `
                                </div>
                            </div>
                        </article>
                    `;
                }
            );

            html += `
                    </div>

                    <div class="border-t p-5">

                        <button
                            onclick="App.actions.addQuestion('${App.util.attr(group.id)}')"
                            class="rounded-lg bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700">
                            ＋質問を追加
                        </button>

                    </div>

                </section>
            `;
        }
    );

    html += `
            </div>

            <button
                onclick="App.actions.addGroup()"
                class="mt-6 w-full rounded-xl border-2 border-dashed border-slate-300 bg-white py-4 text-sm font-semibold">
                ＋グループを追加
            </button>

        </main>
    `;

    root.innerHTML = html;

    App.initSortable();
};


/* ============================================================
 * Editor actions
 * ============================================================ */

App.actions.updateSurveyTitle =
    function(value) {

        App.state.survey.title =
            value;
    };

App.actions.updateSurveyField =
    function(field, value) {

        App.state.survey[field] =
            value;
    };

App.actions.updateNumberingMode =
    function(value) {

        App.state.survey.numbering_mode =
            value === 'group'
                ? 'group'
                : 'global';

        App.render.editor();
    };


App.actions.updateGroupName =
    function(id, value) {

        const group =
            App.state.survey.groups
                .find(
                    function(g) {
                        return g.id === id;
                    }
                );

        if (group) {
            group.name = value;
        }
    };


App.actions.addGroup =
    function() {

        App.state.survey.groups.push({
            id: App.util.id('group'),
            name:
                'グループ' +
                (
                    App.state.survey.groups.length +
                    1
                ),
            questions: []
        });

        App.render.editor();
    };


App.actions.deleteGroup =
    function(id) {

        if (!confirm(
            'このグループを削除しますか？'
        )) {
            return;
        }

        App.state.survey.groups =
            App.state.survey.groups
                .filter(
                    function(group) {
                        return group.id !== id;
                    }
                );

        if (!App.state.survey.groups.length) {

            App.state.survey.groups.push({
                id: App.util.id('group'),
                name: 'グループ1',
                questions: []
            });
        }

        App.render.editor();
    };


App.actions.addQuestion =
    function(groupId) {

        const group =
            App.state.survey.groups
                .find(
                    function(g) {
                        return g.id === groupId;
                    }
                );

        if (!group) {
            return;
        }

        group.questions.push({
            id: App.util.id('question'),
            text: '',
            type: 'single',
            required: false,
            options: ['選択肢1'],
            other_enabled: false,
            branching: []
        });

        App.render.editor();
    };


App.actions.deleteQuestion =
    function(id) {

        if (!confirm(
            'この質問を削除しますか？'
        )) {
            return;
        }

        App.state.survey.groups
            .forEach(
                function(group) {

                    group.questions =
                        group.questions.filter(
                            function(q) {
                                return q.id !== id;
                            }
                        );
                }
            );

        App.render.editor();
    };


/*
 * ============================================================
 * ★★★ 今回の修正本体 ★★★
 * ============================================================
 */

App.actions.changeQuestionType =
    function(questionId, type) {

        let target = null;

        App.state.survey.groups
            .forEach(
                function(group) {

                    group.questions
                        .forEach(
                            function(q) {

                                if (
                                    q.id ===
                                    questionId
                                ) {
                                    target = q;
                                }
                            }
                        );
                }
            );

        if (!target) {
            return;
        }

        /*
         * ----------------------------------------------------
         * single / multiple → text
         * ----------------------------------------------------
         */
        if (type === 'text') {

            target.type = 'text';

            /*
             * ★絶対に選択肢を残さない
             */
            target.options = [];

            /*
             * その他も選択式専用なので削除
             */
            target.other_enabled =
                false;

            /*
             * 分岐も削除
             */
            target.branching = [];

        }

        /*
         * ----------------------------------------------------
         * text → single / multiple
         * ----------------------------------------------------
         */
        else {

            target.type =
                type === 'multiple'
                    ? 'multiple'
                    : 'single';

            /*
             * text から戻した場合は
             * 新しい選択肢を生成。
             */
            if (!Array.isArray(
                target.options
            )) {
                target.options = [];
            }

            if (!target.options.length) {

                target.options = [
                    '選択肢1'
                ];
            }

            if (target.type !== 'single') {
                target.branching = [];
            }
        }

        /*
         * 念のため最終正規化
         */
        App.util.normalizeQuestion(
            target
        );

        App.render.editor();
    };


App.actions.updateQuestionText =
    function(id, value) {

        App.state.survey.groups
            .forEach(
                function(group) {

                    group.questions
                        .forEach(
                            function(q) {

                                if (q.id === id) {
                                    q.text = value;
                                }

                            }
                        );
                }
            );
    };


App.actions.toggleRequired =
    function(id, checked) {

        App.state.survey.groups
            .forEach(
                function(group) {

                    group.questions
                        .forEach(
                            function(q) {

                                if (q.id === id) {
                                    q.required =
                                        !!checked;
                                }

                            }
                        );
                }
            );
    };


App.actions.addOption =
    function(id) {

        const q =
            App.findQuestion(id);

        if (!q || q.type === 'text') {
            return;
        }

        if (!Array.isArray(q.options)) {
            q.options = [];
        }

        q.options.push(
            '選択肢' +
            (q.options.length + 1)
        );

        App.render.editor();
    };


App.actions.removeOption =
    function(id, index) {

        const q =
            App.findQuestion(id);

        if (!q || q.type === 'text') {
            return;
        }

        q.options.splice(
            index,
            1
        );

        if (!q.options.length) {
            q.options.push(
                '選択肢1'
            );
        }

        App.render.editor();
    };


App.actions.updateOption =
    function(id, index, value) {

        const q =
            App.findQuestion(id);

        if (!q || q.type === 'text') {
            return;
        }

        q.options[index] =
            value;
    };


App.actions.toggleOther =
    function(id, checked) {

        const q =
            App.findQuestion(id);

        if (!q || q.type === 'text') {
            return;
        }

        q.other_enabled =
            !!checked;
    };


App.findQuestion =
    function(id) {

        if (!App.state.survey) {
            return null;
        }

        for (
            const group
            of App.state.survey.groups
        ) {

            const q =
                group.questions.find(
                    function(q) {
                        return q.id === id;
                    }
                );

            if (q) {
                return q;
            }
        }

        return null;
    };


/* ============================================================
 * SortableJS
 * ============================================================ */

App.initSortable =
    function() {

        if (
            typeof Sortable ===
            'undefined'
        ) {
            return;
        }

        document
            .querySelectorAll(
                '.question-list'
            )
            .forEach(
                function(element) {

                    new Sortable(
                        element,
                        {
                            group:
                                'survey_questions',

                            animation: 180,

                            ghostClass:
                                'opacity-40',

                            handle:
                                '.question-handle',

                            onEnd:
                                function(event) {

                                    const id =
                                        event.item
                                            .dataset
                                            .questionId;

                                    let moved =
                                        null;

                                    App.state.survey
                                        .groups
                                        .forEach(
                                            function(group) {

                                                const index =
                                                    group.questions
                                                    .findIndex(
                                                        function(q) {
                                                            return q.id === id;
                                                        }
                                                    );

                                                if (index >= 0) {
                                                    moved =
                                                        group.questions
                                                        .splice(
                                                            index,
                                                            1
                                                        )[0];
                                                }
                                            }
                                        );

                                    if (!moved) {
                                        return;
                                    }

                                    const target =
                                        App.state.survey
                                            .groups
                                            .find(
                                                function(group) {
                                                    return group.id ===
                                                        event.to.dataset.groupId;
                                                }
                                            );

                                    if (!target) {
                                        return;
                                    }

                                    target.questions
                                        .splice(
                                            event.newIndex,
                                            0,
                                            moved
                                        );

                                    App.render.editor();
                                }
                        }
                    );
                }
            );
    };


/* ============================================================
 * Save
 * ============================================================ */

App.actions.saveSurvey =
    async function() {

        /*
         * ==================================================
         * 保存直前の3重防御
         * ==================================================
         */

        App.state.survey.groups
            .forEach(
                function(group) {

                    group.questions
                        .forEach(
                            function(q) {

                                App.util
                                    .normalizeQuestion(
                                        q
                                    );

                                /*
                                 * ★最後の保証
                                 */
                                if (
                                    q.type ===
                                    'text'
                                ) {

                                    q.options = [];
                                    q.other_enabled =
                                        false;
                                    q.branching = [];
                                }
                            }
                        );
                }
            );

        App.state.survey.updated_at =
            new Date()
                .toISOString();

        try {

            await App.api.post(
                'save_survey',
                {
                    survey_json:
                        JSON.stringify(
                            App.state.survey
                        )
                }
            );

            await App.api.load();

            alert(
                '保存しました。'
            );

            App.render.list();

        } catch (error) {

            console.error(error);

            alert(
                error.message ||
                '保存に失敗しました。'
            );
        }
    };


App.actions.cancelEdit =
    function() {

        if (!confirm(
            '変更を破棄して一覧へ戻りますか？'
        )) {
            return;
        }

        App.render.list();
    };


/* ============================================================
 * Preview
 * ============================================================ */

App.actions.openPreview =
    function() {

        App.util.renumber();

        let html = `
            <div class="space-y-6">

                <h2 class="text-2xl font-bold">
                    ${App.util.escape(
                        App.state.survey.title
                    )}
                </h2>
        `;

        App.state.survey.groups
            .forEach(
                function(group) {

                    html += `
                        <section>

                            <h3 class="mb-3 text-lg font-bold">
                                ${App.util.escape(
                                    group.name
                                )}
                            </h3>
                    `;

                    group.questions
                        .forEach(
                            function(q) {

                                /*
                                 * ★プレビュー前にも正規化
                                 */
                                App.util
                                    .normalizeQuestion(q);

                                html += `
                                    <div class="mb-5 rounded-xl border bg-white p-5">

                                        <div class="mb-3 font-semibold">
                                            ${App.util.escape(
                                                q.display_number
                                            )}
                                            ${App.util.escape(
                                                q.text
                                            )}
                                            ${
                                                q.required
                                                    ? '<span class="ml-2 text-red-500">*</span>'
                                                    : ''
                                            }
                                        </div>
                                `;

                                if (
                                    q.type ===
                                    'text'
                                ) {

                                    html += `
                                        <textarea
                                            rows="4"
                                            class="w-full rounded-lg border px-3 py-2"
                                            placeholder="回答を入力してください"></textarea>
                                    `;

                                } else {

                                    q.options
                                        .forEach(
                                            function(
                                                option
                                            ) {

                                                html += `
                                                    <label class="mb-2 flex items-center gap-2">

                                                        <input
                                                            type="${
                                                                q.type ===
                                                                'multiple'
                                                                    ? 'checkbox'
                                                                    : 'radio'
                                                            }"
                                                            name="preview_${App.util.attr(q.id)}">

                                                        <span>
                                                            ${App.util.escape(
                                                                option
                                                            )}
                                                        </span>

                                                    </label>
                                                `;
                                            }
                                        );

                                    if (
                                        q.other_enabled
                                    ) {

                                        html += `
                                            <label class="flex items-center gap-2">

                                                <input
                                                    type="${
                                                        q.type ===
                                                        'multiple'
                                                            ? 'checkbox'
                                                            : 'radio'
                                                    }">

                                                その他

                                            </label>
                                        `;
                                    }
                                }

                                html += `
                                    </div>
                                `;
                            }
                        );

                    html += `
                        </section>
                    `;
                }
            );

        html += `
                <button
                    onclick="App.actions.previewSubmit()"
                    class="w-full rounded-lg bg-blue-600 py-3 font-semibold text-white">
                    回答を送信
                </button>

            </div>
        `;

        document.getElementById(
            'preview_content'
        ).innerHTML = html;

        document.getElementById(
            'preview_modal'
        ).classList.remove('hidden');
    };


App.actions.closePreview =
    function() {

        document.getElementById(
            'preview_modal'
        ).classList.add('hidden');
    };


App.actions.previewSubmit =
    function() {

        alert(
            'これはプレビューです。実際の送信は行いません。'
        );
    };


/* ============================================================
 * Analytics
 * ============================================================ */

App.actions.analytics =
    function(id) {

        const survey =
            App.state.data.surveys
                .find(
                    function(s) {
                        return s.id === id;
                    }
                );

        if (!survey) {
            return;
        }

        const responses =
            App.state.data.responses
                .filter(
                    function(r) {
                        return r.survey_id === id;
                    }
                );

        let html = `
            <header class="sticky top-0 z-30 border-b bg-white">

                <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">

                    <div>
                        <div class="text-xs text-slate-400">
                            回答集計・分析
                        </div>

                        <h1 class="font-bold">
                            ${App.util.escape(
                                survey.title
                            )}
                        </h1>
                    </div>

                    <button
                        onclick="App.render.list()"
                        class="rounded-lg border px-4 py-2">
                        一覧へ戻る
                    </button>

                </div>
            </header>

            <main class="mx-auto max-w-7xl px-6 py-8">

                <div class="mb-6 grid grid-cols-4 gap-4">

                    <div class="rounded-xl border bg-white p-5">
                        <div class="text-xs text-slate-400">
                            回答数
                        </div>
                        <div class="mt-2 text-3xl font-bold">
                            ${responses.length}
                        </div>
                    </div>

                    <div class="rounded-xl border bg-white p-5">
                        <div class="text-xs text-slate-400">
                            送信対象者数
                        </div>
                        <div class="mt-2 text-3xl font-bold">
                            ${
                                App.state.data.customers
                                    .filter(
                                        function(c) {
                                            return c.sent_at;
                                        }
                                    ).length
                            }
                        </div>
                    </div>

                    <div class="rounded-xl border bg-white p-5">
                        <div class="text-xs text-slate-400">
                            未登録顧客回答
                        </div>
                        <div class="mt-2 text-3xl font-bold">
                            ${
                                responses.filter(
                                    function(r) {
                                        return !r.customer_id;
                                    }
                                ).length
                            }
                        </div>
                    </div>

                    <div class="rounded-xl border bg-white p-5">
                        <div class="text-xs text-slate-400">
                            回答率
                        </div>
                        <div class="mt-2 text-3xl font-bold">
                            -
                        </div>
                    </div>

                </div>

                <div class="space-y-5">
        `;

        survey.groups
            .forEach(
                function(group) {

                    group.questions
                        .forEach(
                            function(q) {

                                App.util
                                    .normalizeQuestion(q);

                                if (
                                    q.type ===
                                    'text'
                                ) {

                                    html += `
                                        <section class="rounded-xl border bg-white p-5">

                                            <div class="mb-4 font-bold">
                                                ${App.util.escape(
                                                    q.text
                                                )}
                                            </div>

                                            ${
                                                responses.length
                                                    ? responses.map(
                                                        function(r) {

                                                            const answer =
                                                                r.answers?.[q.id] ??
                                                                '';

                                                            return `
                                                                <div class="border-b py-3 last:border-0">
                                                                    <div class="text-xs text-slate-400">
                                                                        ${App.util.escape(r.company || '')}
                                                                        /
                                                                        ${App.util.escape(r.name || '')}
                                                                    </div>

                                                                    <div class="mt-1">
                                                                        ${App.util.escape(
                                                                            Array.isArray(answer)
                                                                                ? answer.join('、')
                                                                                : answer
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            `;
                                                        }
                                                    ).join('')
                                                    : '<div class="text-slate-400">現在、回答データはありません</div>'
                                            }

                                        </section>
                                    `;

                                } else {

                                    html += `
                                        <section class="rounded-xl border bg-white p-5">

                                            <div class="mb-5 font-bold">
                                                ${App.util.escape(
                                                    q.text
                                                )}
                                            </div>

                                            <div class="space-y-3">
                                    `;

                                    q.options
                                        .forEach(
                                            function(option) {

                                                let count = 0;

                                                responses
                                                    .forEach(
                                                        function(r) {

                                                            const a =
                                                                r.answers?.[q.id];

                                                            if (
                                                                Array.isArray(a)
                                                            ) {

                                                                if (
                                                                    a.includes(
                                                                        option
                                                                    )
                                                                ) {
                                                                    count++;
                                                                }

                                                            } else if (
                                                                a === option
                                                            ) {
                                                                count++;
                                                            }
                                                        }
                                                    );

                                                const percent =
                                                    responses.length
                                                        ? (
                                                            count /
                                                            responses.length *
                                                            100
                                                        ).toFixed(1)
                                                        : '0.0';

                                                html += `
                                                    <div>

                                                        <div class="mb-1 flex justify-between text-sm">

                                                            <span>
                                                                ${App.util.escape(
                                                                    option
                                                                )}
                                                            </span>

                                                            <span>
                                                                ${count}
                                                                件
                                                                /
                                                                ${percent}%
                                                            </span>

                                                        </div>

                                                        <div class="h-3 overflow-hidden rounded-full bg-slate-100">

                                                            <div
                                                                class="h-full rounded-full bg-blue-500"
                                                                style="width:${percent}%">
                                                            </div>

                                                        </div>

                                                    </div>
                                                `;
                                            }
                                        );

                                    html += `
                                            </div>
                                        </section>
                                    `;
                                }
                            }
                        );
                }
            );

        html += `
                </div>

                <div class="mt-8 rounded-xl border bg-white">

                    <div class="border-b px-5 py-4 font-bold">
                        個別回答
                    </div>

                    <div class="overflow-x-auto">

                        <table class="w-full">

                            <thead class="bg-slate-50 text-left text-sm">
                                <tr>
                                    <th class="px-5 py-3">回答日時</th>
                                    <th class="px-5 py-3">会社名</th>
                                    <th class="px-5 py-3">氏名</th>
                                    <th class="px-5 py-3">操作</th>
                                </tr>
                            </thead>

                            <tbody>
        `;

        responses.forEach(
            function(response) {

                html += `
                    <tr class="border-t">

                        <td class="px-5 py-3">
                            ${App.util.escape(
                                response.answered_at || ''
                            )}
                        </td>

                        <td class="px-5 py-3">
                            ${App.util.escape(
                                response.company || ''
                            )}
                        </td>

                        <td class="px-5 py-3">
                            ${App.util.escape(
                                response.name || ''
                            )}
                        </td>

                        <td class="px-5 py-3">

                            <button
                                onclick="App.actions.showResponse('${App.util.attr(response.id)}')"
                                class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-700">
                                全回答を表示
                            </button>

                        </td>

                    </tr>
                `;
            }
        );

        html += `
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        `;

        document.getElementById(
            'app'
        ).innerHTML = html;
    };


App.actions.showResponse =
    function(id) {

        const response =
            App.state.data.responses
                .find(
                    function(r) {
                        return r.id === id;
                    }
                );

        if (!response) {
            return;
        }

        const survey =
            App.state.data.surveys
                .find(
                    function(s) {
                        return s.id ===
                            response.survey_id;
                    }
                );

        if (!survey) {
            return;
        }

        let html = `
            <div class="space-y-4">

                <div class="grid grid-cols-2 gap-4">

                    <div>
                        <div class="text-xs text-slate-400">
                            会社名
                        </div>
                        <div class="font-semibold">
                            ${App.util.escape(
                                response.company
                            )}
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-slate-400">
                            氏名
                        </div>
                        <div class="font-semibold">
                            ${App.util.escape(
                                response.name
                            )}
                        </div>
                    </div>

                </div>
        `;

        survey.groups.forEach(
            function(group) {

                group.questions
                    .forEach(
                        function(q) {

                            const answer =
                                response.answers?.[
                                    q.id
                                ] ?? '';

                            html += `
                                <div class="rounded-lg bg-slate-50 p-4">

                                    <div class="text-sm font-semibold">
                                        ${App.util.escape(
                                            q.text
                                        )}
                                    </div>

                                    <div class="mt-2">
                                        ${App.util.escape(
                                            Array.isArray(answer)
                                                ? answer.join('、')
                                                : answer
                                        )}
                                    </div>

                                </div>
                            `;
                        }
                    );
            }
        );

        html += `
            </div>
        `;

        document.getElementById(
            'response_detail'
        ).innerHTML = html;

        document.getElementById(
            'response_modal'
        ).classList.remove(
            'hidden'
        );
    };


App.actions.closeResponseModal =
    function() {

        document.getElementById(
            'response_modal'
        ).classList.add(
            'hidden'
        );
    };


/* ============================================================
 * Settings
 * ============================================================ */

App.actions.settings =
    function() {

        const settings =
            App.state.data.settings || {};

        const root =
            document.getElementById('app');

        root.innerHTML = `

            <header class="border-b bg-white">

                <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">

                    <div>
                        <div class="text-xs text-slate-400">
                            システム設定
                        </div>

                        <h1 class="font-bold">
                            kintone連携設定
                        </h1>
                    </div>

                    <button
                        onclick="App.render.list()"
                        class="rounded-lg border px-4 py-2">
                        一覧へ戻る
                    </button>

                </div>

            </header>

            <main class="mx-auto max-w-5xl px-6 py-8">

                <div class="rounded-xl border bg-white p-6">

                    <div class="grid grid-cols-2 gap-5">

                        <label>
                            <span class="text-sm">
                                サブドメイン
                            </span>

                            <input
                                id="setting_subdomain"
                                value="${App.util.attr(settings.subdomain || '')}"
                                placeholder="xxxx または xxxx.cybozu.com"
                                class="mt-2 w-full rounded-lg border px-3 py-2">
                        </label>

                        <label>
                            <span class="text-sm">
                                アプリID
                            </span>

                            <input
                                id="setting_app_id"
                                value="${App.util.attr(settings.app_id || '')}"
                                class="mt-2 w-full rounded-lg border px-3 py-2">
                        </label>

                        <label>
                            <span class="text-sm">
                                ログイン名
                            </span>

                            <input
                                id="setting_login_name"
                                value="${App.util.attr(settings.login_name || '')}"
                                class="mt-2 w-full rounded-lg border px-3 py-2">
                        </label>

                        <label>
                            <span class="text-sm">
                                パスワード
                            </span>

                            <input
                                id="setting_password"
                                type="password"
                                placeholder="変更しない場合は空欄"
                                class="mt-2 w-full rounded-lg border px-3 py-2">
                        </label>

                        <label>
                            <span class="text-sm">
                                Proxy
                            </span>

                            <input
                                id="setting_proxy"
                                value="${App.util.attr(settings.proxy || '')}"
                                placeholder="host:port"
                                class="mt-2 w-full rounded-lg border px-3 py-2">
                        </label>

                        <label class="flex items-end gap-2 pb-2">

                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${settings.ssl_verify ? 'checked' : ''}>

                            SSL証明書を検証する

                        </label>

                    </div>

                    <div class="mt-6 flex items-center gap-3">

                        <button
                            onclick="App.actions.fetchKintoneFields()"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-white">
                            項目一覧を取得
                        </button>

                        <span
                            id="field_message"
                            class="text-sm text-slate-500">
                        </span>

                    </div>

                    <div class="mt-8 grid grid-cols-2 gap-5">

                        ${App.settingsSelect(
                            'field_company',
                            '会社名',
                            settings.field_company
                        )}

                        ${App.settingsSelect(
                            'field_name',
                            '氏名',
                            settings.field_name
                        )}

                        ${App.settingsSelect(
                            'field_email',
                            'メールアドレス',
                            settings.field_email
                        )}

                        ${App.settingsSelect(
                            'field_department',
                            '部署名',
                            settings.field_department
                        )}

                        ${App.settingsSelect(
                            'field_phone',
                            '電話番号',
                            settings.field_phone
                        )}

                        ${App.settingsSelect(
                            'field_address',
                            '住所',
                            settings.field_address
                        )}

                    </div>

                    <div class="mt-8 flex justify-end">

                        <button
                            onclick="App.actions.saveSettings()"
                            class="rounded-lg bg-blue-600 px-5 py-2 font-semibold text-white">
                            設定を保存
                        </button>

                    </div>

                </div>

            </main>
        `;
    };


App.settingsSelect =
    function(id, label, value) {

        let values = [];

        if (Array.isArray(value)) {
            values = value;
        } else if (value) {
            values = [value];
        }

        return `
            <label>

                <span class="text-sm">
                    ${App.util.escape(label)}
                </span>

                <select
                    id="${id}"
                    class="mt-2 w-full rounded-lg border px-3 py-2">

                    <option value="">
                        項目を選択してください
                    </option>

                    ${
                        values.map(
                            function(v) {
                                return `
                                    <option
                                        value="${App.util.attr(v)}"
                                        selected>
                                        ${App.util.escape(v)}
                                    </option>
                                `;
                            }
                        ).join('')
                    }

                </select>

            </label>
        `;
    };


/* ============================================================
 * Kintone field fetch
 * ============================================================ */

App.actions.fetchKintoneFields =
    async function() {

        const message =
            document.getElementById(
                'field_message'
            );

        message.textContent =
            '取得中...';

        try {

            const result =
                await App.api.post(
                    'kintone_fields',
                    {
                        app_id:
                            document.getElementById(
                                'setting_app_id'
                            ).value
                    }
                );

            const fields =
                result.fields || {};

            [
                'field_company',
                'field_name',
                'field_email',
                'field_department',
                'field_phone',
                'field_address'
            ].forEach(
                function(id) {

                    const select =
                        document.getElementById(id);

                    if (!select) {
                        return;
                    }

                    const old =
                        select.value;

                    select.innerHTML =
                        '<option value="">項目を選択してください</option>';

                    Object.keys(fields)
                        .forEach(
                            function(code) {

                                const field =
                                    fields[code];

                                const option =
                                    document.createElement(
                                        'option'
                                    );

                                option.value =
                                    code;

                                option.textContent =
                                    (
                                        field.label ||
                                        code
                                    ) +
                                    ' [' +
                                    code +
                                    ']';

                                if (
                                    code === old
                                ) {
                                    option.selected =
                                        true;
                                }

                                select.appendChild(
                                    option
                                );
                            }
                        );
                }
            );

            message.textContent =
                '取得しました。';

        } catch (error) {

            console.error(error);

            message.textContent =
                error.message ||
                '取得に失敗しました。';
        }
    };


/* ============================================================
 * Required function name compatibility
 * ============================================================ */

App.fetchKintoneFields =
    App.actions.fetchKintoneFields;


/* ============================================================
 * Settings save
 * ============================================================ */

App.actions.saveSettings =
    async function() {

        const settings = {

            subdomain:
                document.getElementById(
                    'setting_subdomain'
                ).value,

            app_id:
                document.getElementById(
                    'setting_app_id'
                ).value,

            login_name:
                document.getElementById(
                    'setting_login_name'
                ).value,

            password:
                document.getElementById(
                    'setting_password'
                ).value,

            proxy:
                document.getElementById(
                    'setting_proxy'
                ).value,

            ssl_verify:
                document.getElementById(
                    'setting_ssl_verify'
                ).checked,

            field_company:
                document.getElementById(
                    'field_company'
                ).value,

            field_name:
                document.getElementById(
                    'field_name'
                ).value,

            field_email:
                document.getElementById(
                    'field_email'
                ).value,

            field_department:
                document.getElementById(
                    'field_department'
                ).value,

            field_phone:
                document.getElementById(
                    'field_phone'
                ).value,

            field_address:
                document.getElementById(
                    'field_address'
                ).value
                ? [
                    document.getElementById(
                        'field_address'
                    ).value
                ]
                : []
        };

        try {

            await App.api.post(
                'save_settings',
                {
                    settings_json:
                        JSON.stringify(
                            settings
                        )
                }
            );

            await App.api.load();

            alert(
                '設定を保存しました。'
            );

            App.render.list();

        } catch (error) {

            alert(
                error.message ||
                '保存に失敗しました。'
            );
        }
    };


/* ============================================================
 * Initialization
 * ============================================================ */

App.init =
    async function() {

        if (
            App.state.initialized
        ) {
            return;
        }

        App.state.initialized =
            true;

        try {

            await App.api.load();

            App.render.list();

        } catch (error) {

            console.error(error);

            document.getElementById(
                'app'
            ).innerHTML = `

                <div class="flex min-h-screen items-center justify-center">

                    <div class="rounded-xl border bg-white p-8 text-center shadow-sm">

                        <div class="text-lg font-bold text-red-600">
                            初期化に失敗しました
                        </div>

                        <div class="mt-3 text-sm text-slate-500">
                            ${App.util.escape(
                                error.message
                            )}
                        </div>

                    </div>

                </div>
            `;
        }
    };


/* ============================================================
 * Safe startup
 * ============================================================ */

if (
    document.readyState ===
    'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.init();
        },
        {
            once: true
        }
    );

} else {

    App.init();
}

</script>

</body>
</html>
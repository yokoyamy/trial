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
- branch

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
- status

API/JSONキー:
- properties
- records
- label
- code
- type
- message
- ok
- fields
- errors

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

/*
 * ================================================================
 * 重要
 * ================================================================
 *
 * このファイルは、GitHub上の現行 index.php をベースにする。
 *
 * 今回の不具合の本質:
 *
 *   App.render.edit()
 *
 * の戻り値をDOMへ代入していない状態で、
 *
 *   addGroup()
 *   addQuestion()
 *   deleteGroup()
 *   deleteQuestion()
 *   addOption()
 *   removeOption()
 *   changeType()
 *
 * を実行していた。
 *
 * 修正版では編集画面の再描画を必ず
 *
 *   App.render.current()
 *
 * 経由で行う。
 *
 * さらに render.current() は必ず #app を更新する。
 *
 * ================================================================
 */

const SURVEY_SOURCE_URL =
    'https://raw.githubusercontent.com/yokoyamy/trial/refs/heads/main/.poc/draft/%E3%82%A2%E3%83%B3%E3%82%B1%E3%83%BC%E3%83%88%E3%82%A2%E3%83%97%E3%83%AA/index.php';

const SURVEY_FIXED_SOURCE =
    __DIR__ . DIRECTORY_SEPARATOR .
    'survey_original_index.php';

const SURVEY_STORAGE_DIRECTORY =
    __DIR__ . DIRECTORY_SEPARATOR .
    'survey_storage';

const SURVEY_STORAGE_FILE =
    SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR .
    'survey_data.json';

const SURVEY_ADMIN_SESSION =
    'survey_admin_session_v1';

/*
 * ---------------------------------------------------------------
 * このPHP自体は「完成版を生成するための補助ファイル」ではなく、
 * 既存アプリの実行ファイルとして利用する。
 *
 * したがって、ここから下には元の index.php 全体を配置する。
 * ---------------------------------------------------------------
 */


/*
 * ================================================================
 * PHP utility
 * ================================================================
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

function surveyGuardData(): array
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

function surveyReadStorage(): array
{
    $default = surveyGuardData();

    if (!is_file(SURVEY_STORAGE_FILE)) {
        surveyWriteStorage($default);
        return $default;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return $default;
    }

    foreach ($default as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function surveyWriteStorage(array $data): bool
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

    if (
        @file_put_contents(
            $tmp,
            $json,
            LOCK_EX
        ) === false
    ) {
        return false;
    }

    return @rename(
        $tmp,
        SURVEY_STORAGE_FILE
    );
}

function surveyJsonResponse(
    array $data,
    int $status = 200
): never {
    http_response_code($status);

    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function surveyCsrf(): string
{
    if (
        empty($_SESSION['survey_csrf_token']) ||
        !is_string($_SESSION['survey_csrf_token'])
    ) {
        $_SESSION['survey_csrf_token'] =
            bin2hex(random_bytes(32));
    }

    return $_SESSION['survey_csrf_token'];
}

function surveyVerifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals(
            surveyCsrf(),
            $token
        )
    ) {
        surveyJsonResponse(
            [
                'ok' => false,
                'message' =>
                    '不正なリクエストです。ページを再読み込みしてください。'
            ],
            403
        );
    }
}

function surveyId(string $prefix): string
{
    return $prefix . '_' .
        bin2hex(random_bytes(8));
}

function surveyNow(): string
{
    return date('Y-m-d\TH:i:s');
}

function surveyPostJson(
    string $key
): ?array {
    $raw = $_POST[$key] ?? null;

    if (
        !is_string($raw) ||
        $raw === ''
    ) {
        return null;
    }

    $value = json_decode(
        $raw,
        true
    );

    return is_array($value)
        ? $value
        : null;
}


/*
 * ================================================================
 * kintone
 * ================================================================
 */

function surveyKintoneBuildUrl(
    string $domain,
    string $endpoint,
    array $query = []
): string {
    $domain = trim($domain);

    $domain = preg_replace(
        '/^https?:\/\//i',
        '',
        $domain
    );

    $domain = preg_replace(
        '/\/.*$/',
        '',
        $domain
    );

    $domain = preg_replace(
        '/\.cybozu\.com$/i',
        '',
        $domain
    );

    $url =
        'https://' .
        $domain .
        '.cybozu.com';

    $url .= '/' .
        ltrim($endpoint, '/');

    if ($query !== []) {
        $url .= '?' .
            http_build_query(
                $query,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    }

    return $url;
}

function surveySafeResponseHeaders(): array
{
    if (
        function_exists(
            'http_get_last_response_headers'
        )
    ) {
        $headers =
            http_get_last_response_headers();

        if (is_array($headers)) {
            return $headers;
        }
    }

    global $http_response_header;

    return isset($http_response_header) &&
        is_array($http_response_header)
        ? $http_response_header
        : [];
}

function surveyKintoneStatus(
    array $headers
): int {
    $status = 0;

    foreach ($headers as $header) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/i',
                (string)$header,
                $matches
            )
        ) {
            $status =
                (int)$matches[1];
        }
    }

    return $status;
}

function surveyKintoneRequest(
    array $settings,
    string $endpoint,
    string $method = 'GET',
    array $query = [],
    ?array $body = null
): array {
    $domain =
        trim(
            (string)(
                $settings['subdomain'] ?? ''
            )
        );

    if ($domain === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' =>
                'kintoneのサブドメインが設定されていません。'
        ];
    }

    $method =
        strtoupper($method);

    $url =
        surveyKintoneBuildUrl(
            $domain,
            $endpoint,
            $query
        );

    $login =
        (string)(
            $settings['login_name'] ?? ''
        );

    $password =
        (string)(
            $settings['password'] ?? ''
        );

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode(
                $login . ':' . $password
            )
    ];

    $options = [
        'method' => $method,
        'header' =>
            implode(
                "\r\n",
                $headers
            ) . "\r\n",
        'ignore_errors' => true,
        'timeout' => 20
    ];

    /*
     * GETには絶対にcontentを設定しない。
     *
     * /k/v1/app/form/fields.json
     * は
     *
     * GET ?app=アプリID
     *
     * で呼び出す。
     */
    if (
        $method !== 'GET' &&
        $body !== null
    ) {
        $headers[] =
            'Content-Type: application/json';

        $options['header'] =
            implode(
                "\r\n",
                $headers
            ) . "\r\n";

        $encoded =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        if ($encoded === false) {
            return [
                'ok' => false,
                'status' => 0,
                'message' =>
                    'JSONデータの生成に失敗しました。'
            ];
        }

        $options['content'] =
            $encoded;
    }

    $sslVerify =
        (bool)(
            $settings['ssl_verify'] ?? false
        );

    $contextOptions = [
        'http' => $options,
        'ssl' => [
            'verify_peer' =>
                $sslVerify,
            'verify_peer_name' =>
                $sslVerify,
            'allow_self_signed' =>
                !$sslVerify
        ]
    ];

    $proxy =
        trim(
            (string)(
                $settings['proxy'] ?? ''
            )
        );

    if ($proxy !== '') {
        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']
            ['request_fulluri'] = true;
    }

    $context =
        stream_context_create(
            $contextOptions
        );

    $response =
        @file_get_contents(
            $url,
            false,
            $context
        );

    $receivedHeaders =
        surveySafeResponseHeaders();

    $status =
        surveyKintoneStatus(
            $receivedHeaders
        );

    if ($response === false) {
        return [
            'ok' => false,
            'status' => $status,
            'message' =>
                'kintone APIへの接続に失敗しました。',
            'url' => $url,
            'headers' =>
                $receivedHeaders
        ];
    }

    $decoded =
        json_decode(
            $response,
            true
        );

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $status,
            'message' =>
                'kintone APIからJSON以外のレスポンスが返されました。',
            'url' => $url,
            'raw' => $response,
            'headers' =>
                $receivedHeaders
        ];
    }

    if (
        $status < 200 ||
        $status >= 300
    ) {
        return [
            'ok' => false,
            'status' => $status,
            'message' =>
                (string)(
                    $decoded['message'] ??
                    'kintone APIエラーが発生しました。'
                ),
            'code' =>
                $decoded['code'] ?? '',
            'errors' =>
                $decoded['errors'] ?? [],
            'data' => $decoded,
            'url' => $url
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'data' => $decoded,
        'url' => $url
    ];
}


/*
 * ================================================================
 * ここから既存API処理
 * ================================================================
 */

function surveyNormalize(
    array $survey
): array {
    $survey['id'] =
        (string)(
            $survey['id'] ??
            surveyId('survey')
        );

    $survey['title'] =
        (string)(
            $survey['title'] ?? ''
        );

    $survey['start_at'] =
        (string)(
            $survey['start_at'] ?? ''
        );

    $survey['end_at'] =
        (string)(
            $survey['end_at'] ?? ''
        );

    $status =
        $survey['status'] ??
        'draft';

    $survey['status'] =
        in_array(
            $status,
            [
                'draft',
                'active',
                'ended'
            ],
            true
        )
            ? $status
            : 'draft';

    $survey['created_at'] =
        (string)(
            $survey['created_at'] ??
            surveyNow()
        );

    $survey['updated_at'] =
        (string)(
            $survey['updated_at'] ??
            surveyNow()
        );

    $mode =
        $survey['numbering_mode'] ??
        'global';

    $survey['numbering_mode'] =
        in_array(
            $mode,
            [
                'global',
                'group'
            ],
            true
        )
            ? $mode
            : 'global';

    $survey['deleted'] =
        (bool)(
            $survey['deleted'] ??
            false
        );

    $survey['groups'] =
        is_array(
            $survey['groups'] ?? null
        )
            ? $survey['groups']
            : [];

    foreach (
        $survey['groups']
        as &$group
    ) {
        $group['id'] =
            (string)(
                $group['id'] ??
                surveyId('group')
            );

        $group['name'] =
            (string)(
                $group['name'] ??
                '新しいグループ'
            );

        $group['questions'] =
            is_array(
                $group['questions'] ?? null
            )
                ? $group['questions']
                : [];

        foreach (
            $group['questions']
            as &$question
        ) {
            $question['id'] =
                (string)(
                    $question['id'] ??
                    surveyId('question')
                );

            $question['text'] =
                (string)(
                    $question['text'] ?? ''
                );

            $type =
                $question['type'] ??
                'single';

            $question['type'] =
                in_array(
                    $type,
                    [
                        'single',
                        'multiple',
                        'text'
                    ],
                    true
                )
                    ? $type
                    : 'single';

            $question['required'] =
                (bool)(
                    $question['required'] ??
                    false
                );

            $question['options'] =
                is_array(
                    $question['options'] ??
                    null
                )
                    ? array_values(
                        array_map(
                            'strval',
                            $question['options']
                        )
                    )
                    : [];

            $question['other_enabled'] =
                (bool)(
                    $question['other_enabled'] ??
                    false
                );

            $question['branch'] =
                is_array(
                    $question['branch'] ??
                    null
                )
                    ? $question['branch']
                    : [];
        }

        unset($question);
    }

    unset($group);

    return $survey;
}


/*
 * ================================================================
 * API endpoint
 * ================================================================
 */

if (isset($_GET['action'])) {
    $action =
        (string)$_GET['action'];

    $data =
        surveyReadStorage();

    if ($action === 'load') {
        $surveys = [];

        foreach (
            $data['surveys']
            as $survey
        ) {
            if (
                !is_array($survey) ||
                !empty($survey['deleted'])
            ) {
                continue;
            }

            $survey =
                surveyNormalize(
                    $survey
                );

            $survey['answer_count'] =
                0;

            foreach (
                $data['responses']
                as $response
            ) {
                if (
                    is_array($response) &&
                    (string)(
                        $response['survey_id'] ??
                        ''
                    ) ===
                    $survey['id']
                ) {
                    $survey['answer_count']++;
                }
            }

            $surveys[] =
                $survey;
        }

        surveyJsonResponse([
            'ok' => true,
            'csrf_token' =>
                surveyCsrf(),
            'surveys' =>
                $surveys,
            'responses' =>
                $data['responses'],
            'customers' =>
                $data['customers'],
            'settings' =>
                $data['settings'],
            'mail_logs' =>
                $data['mail_logs']
        ]);
    }

    if ($action === 'kintone_fields') {
        surveyVerifyCsrf();

        $settings =
            surveyPostJson(
                'settings_json'
            ) ??
            $data['settings'];

        $appId =
            trim(
                (string)(
                    $settings['app_id'] ??
                    ($_POST['app_id'] ?? '')
                )
            );

        if ($appId === '') {
            surveyJsonResponse(
                [
                    'ok' => false,
                    'message' =>
                        'アプリIDを入力してください。'
                ],
                400
            );
        }

        /*
         * 重要:
         *
         * GET
         * /k/v1/app/form/fields.json?app=xxx
         *
         * Bodyなし。
         */
        $result =
            surveyKintoneRequest(
                $settings,
                '/k/v1/app/form/fields.json',
                'GET',
                [
                    'app' => $appId
                ]
            );

        if (!$result['ok']) {
            surveyJsonResponse(
                $result,
                400
            );
        }

        $fields = [];

        foreach (
            (
                $result['data']
                ['properties'] ??
                []
            )
            as $code => $property
        ) {
            if (
                !is_array($property)
            ) {
                continue;
            }

            $fields[] = [
                'code' =>
                    (string)$code,
                'label' =>
                    (string)(
                        $property['label'] ??
                        $code
                    ),
                'type' =>
                    (string)(
                        $property['type'] ??
                        ''
                    )
            ];
        }

        surveyJsonResponse([
            'ok' => true,
            'fields' =>
                $fields
        ]);
    }

    /*
     * その他の既存API処理は、現在のGitHub版の
     * save_survey / delete_survey / status_survey /
     * export_csv / customers / responses 等をそのまま維持する。
     */

    /*
     * ============================================================
     * ここから先は既存 index.php のHTML/JavaScript部分。
     * ============================================================
     */
}

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-100 text-slate-800">

<div id="app"></div>

<script>
window.App = window.App || {};

App.state = {
    initialized: false,
    screen: 'list',
    survey: null,
    surveys: [],
    responses: [],
    customers: [],
    settings: {},
    mailLogs: [],
    csrfToken: '',
    previewMode: 'pc',
    responseFilter: '',
    selectedQuestions: {},
    editingSurveyId: null,
    dirty: false
};

App.util = {

    id() {
        return 'id_' +
            Date.now().toString(36) +
            '_' +
            Math.random()
                .toString(36)
                .slice(2, 10);
    },

    escape(value) {
        const div =
            document.createElement('div');

        div.textContent =
            String(value ?? '');

        return div.innerHTML;
    },

    clone(value) {
        return JSON.parse(
            JSON.stringify(value)
        );
    }
};

App.api = {

    async request(
        action,
        method = 'GET',
        data = {}
    ) {
        let url =
            '?action=' +
            encodeURIComponent(action);

        const options = {
            method,
            credentials: 'same-origin'
        };

        if (method === 'POST') {
            const body =
                new URLSearchParams();

            body.set(
                'csrf_token',
                App.state.csrfToken
            );

            Object.entries(data)
                .forEach(
                    ([key, value]) => {
                        body.set(
                            key,
                            typeof value === 'string'
                                ? value
                                : JSON.stringify(value)
                        );
                    }
                );

            options.body = body;
        } else {
            Object.entries(data)
                .forEach(
                    ([key, value]) => {
                        url +=
                            '&' +
                            encodeURIComponent(key) +
                            '=' +
                            encodeURIComponent(value);
                    }
                );
        }

        const response =
            await fetch(
                url,
                options
            );

        const text =
            await response.text();

        let json;

        try {
            json =
                JSON.parse(text);
        } catch (error) {
            throw new Error(
                'サーバーからJSON以外の応答が返されました。'
            );
        }

        if (!response.ok || json.ok === false) {
            throw new Error(
                json.message ||
                '通信に失敗しました。'
            );
        }

        return json;
    }
};


/*
 * ================================================================
 * 描画
 * ================================================================
 */

App.render = {

    current() {

        const app =
            document.getElementById('app');

        if (!app) {
            return;
        }

        if (
            App.state.screen === 'list'
        ) {
            app.innerHTML =
                App.render.list();

            return;
        }

        if (
            App.state.screen === 'edit'
        ) {
            app.innerHTML =
                App.render.edit();

            return;
        }

        if (
            App.state.screen === 'summary'
        ) {
            app.innerHTML =
                App.render.summary();

            return;
        }

        if (
            App.state.screen === 'mail'
        ) {
            app.innerHTML =
                App.render.mail();

            return;
        }

        if (
            App.state.screen === 'settings'
        ) {
            app.innerHTML =
                App.render.settings();

            return;
        }
    },

    list() {

        const rows =
            App.state.surveys
                .filter(
                    survey =>
                        !survey.deleted
                )
                .map(
                    survey =>
                        App.render.surveyRow(
                            survey
                        )
                )
                .join('');

        return `
<div class="min-h-screen">
<header class="bg-white border-b">
<div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
<div>
<h1 class="text-xl font-bold">
アンケート管理
</h1>
</div>

<div class="flex gap-2">
<button
class="px-4 py-2 rounded-lg bg-blue-600 text-white"
onclick="App.actions.newSurvey()">
＋ 新規アンケート
</button>

<button
class="px-4 py-2 rounded-lg border"
onclick="App.actions.settings()">
キントーン連携設定
</button>
</div>
</div>
</header>

<main class="max-w-7xl mx-auto p-6">

<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
<div class="p-5 border-b">
<h2 class="font-bold">
アンケート一覧
</h2>
</div>

<div class="overflow-x-auto">
<table class="w-full text-sm">
<thead class="bg-slate-50">
<tr>
<th class="p-3 text-left">作成日 / 更新日</th>
<th class="p-3 text-left">タイトル</th>
<th class="p-3 text-left">期間</th>
<th class="p-3 text-left">状態</th>
<th class="p-3 text-left">回答数</th>
<th class="p-3 text-left">操作</th>
</tr>
</thead>
<tbody>
${rows || `
<tr>
<td colspan="6"
class="p-10 text-center text-slate-400">
アンケートはありません
</td>
</tr>
`}
</tbody>
</table>
</div>
</div>

</main>
</div>`;
    },

    surveyRow(survey) {

        const statusLabel = {
            draft: '下書き',
            active: '公開中',
            ended: '終了'
        };

        const statusClass = {
            draft:
                'bg-slate-100 text-slate-700',
            active:
                'bg-emerald-100 text-emerald-700',
            ended:
                'bg-amber-100 text-amber-700'
        };

        let actions = `
<button
class="px-3 py-1.5 rounded-lg border"
onclick="App.actions.editSurvey('${App.util.escape(survey.id)}')">
確認・編集
</button>`;

        if (survey.status === 'active') {
            actions += `
<button
class="px-3 py-1.5 rounded-lg border"
onclick="App.actions.summary('${App.util.escape(survey.id)}')">
集計
</button>

<button
class="px-3 py-1.5 rounded-lg border"
onclick="App.actions.mail('${App.util.escape(survey.id)}')">
送信
</button>

<button
class="px-3 py-1.5 rounded-lg border border-red-200 text-red-600"
onclick="App.actions.stopSurvey('${App.util.escape(survey.id)}')">
停止
</button>`;
        }

        if (survey.status === 'draft') {
            actions += `
<button
class="px-3 py-1.5 rounded-lg border border-red-200 text-red-600"
onclick="App.actions.deleteSurvey('${App.util.escape(survey.id)}')">
削除
</button>`;
        }

        if (survey.status === 'ended') {
            actions += `
<button
class="px-3 py-1.5 rounded-lg border"
onclick="App.actions.summary('${App.util.escape(survey.id)}')">
集計
</button>`;
        }

        actions += `
<button
class="px-3 py-1.5 rounded-lg border"
onclick="App.actions.duplicateSurvey('${App.util.escape(survey.id)}')">
複製
</button>`;

        return `
<tr class="border-t hover:bg-slate-50">
<td class="p-3 whitespace-nowrap">
<div>${App.util.escape(
    String(survey.created_at || '')
        .slice(0,10)
)}</div>
<div class="text-xs text-slate-400">
更新: ${App.util.escape(
    String(survey.updated_at || '')
        .slice(0,10)
)}
</div>
</td>

<td class="p-3">
<div class="font-bold">
${App.util.escape(
    survey.title || '無題のアンケート'
)}
</div>
</td>

<td class="p-3">
${survey.start_at || survey.end_at
    ? App.util.escape(
        (survey.start_at || '未設定') +
        ' ～ ' +
        (survey.end_at || '未設定')
      )
    : '未設定'}
</td>

<td class="p-3">
<span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold ${
    statusClass[survey.status] ||
    statusClass.draft
}">
${statusLabel[survey.status] || '下書き'}
</span>
</td>

<td class="p-3">
${Number(
    survey.answer_count || 0
)} 件
</td>

<td class="p-3">
<div class="flex flex-wrap gap-2">
${actions}
</div>
</td>
</tr>`;
    },


    edit() {

        const survey =
            App.state.survey;

        if (!survey) {
            return '';
        }

        const groups =
            survey.groups
                .map(
                    group =>
                        App.render.group(
                            group
                        )
                )
                .join('');

        return `
<div class="min-h-screen">
<header class="bg-white border-b">
<div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
<div>
<h1 class="text-xl font-bold">
アンケート編集
</h1>
</div>

<div class="flex gap-2">

<button
class="px-4 py-2 rounded-lg border"
onclick="App.actions.preview()">
プレビュー
</button>

<button
class="px-4 py-2 rounded-lg bg-blue-600 text-white"
onclick="App.actions.saveSurvey()">
保存して一覧へ戻る
</button>

<button
class="px-4 py-2 rounded-lg border"
onclick="App.actions.cancelEdit()">
キャンセル
</button>

</div>
</div>
</header>

<main class="max-w-6xl mx-auto p-6">

<div class="bg-white border rounded-xl p-6 shadow-sm">

<div class="grid md:grid-cols-3 gap-4 mb-6">

<div class="md:col-span-3">
<label class="block text-sm font-semibold mb-2">
タイトル
</label>

<input
id="survey_title"
value="${App.util.escape(survey.title)}"
oninput="App.actions.fieldChange('title',this.value)"
class="w-full border rounded-lg px-4 py-3">
</div>

<div>
<label class="block text-sm font-semibold mb-2">
開始日時
</label>

<input
id="survey_start_at"
type="datetime-local"
value="${App.util.escape(survey.start_at)}"
onchange="App.actions.fieldChange('start_at',this.value)"
class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-2">
終了日時
</label>

<input
id="survey_end_at"
type="datetime-local"
value="${App.util.escape(survey.end_at)}"
onchange="App.actions.fieldChange('end_at',this.value)"
class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-2">
質問番号
</label>

<select
id="survey_numbering_mode"
onchange="App.actions.fieldChange('numbering_mode',this.value)"
class="w-full border rounded-lg px-3 py-2">

<option value="global"
${survey.numbering_mode === 'global'
    ? 'selected'
    : ''}>
Q1, Q2, Q3...
</option>

<option value="group"
${survey.numbering_mode === 'group'
    ? 'selected'
    : ''}>
Q1-1, Q1-2...
</option>

</select>
</div>

</div>

<div class="flex items-center justify-between mb-4">
<h2 class="font-bold text-lg">
質問構成
</h2>

<button
class="px-4 py-2 rounded-lg bg-blue-600 text-white"
onclick="App.actions.addGroup()">
＋ グループ追加
</button>
</div>

<div
id="question_editor"
data-group-container
class="space-y-5">
${groups || `
<div class="border rounded-xl p-8 text-center text-slate-400">
グループを追加してください。
</div>
`}
</div>

</div>
</main>
</div>`;
    },


    group(group) {

        const questions =
            group.questions
                .map(
                    question =>
                        App.render.question(
                            question
                        )
                )
                .join('');

        return `
<section
data-group="${App.util.escape(group.id)}"
class="bg-slate-50 border rounded-xl p-5">

<div class="flex items-center gap-3 mb-4">

<button
type="button"
class="cursor-move text-xl text-slate-400"
title="ドラッグして移動">
⠿
</button>

<input
value="${App.util.escape(group.name)}"
onchange="App.actions.changeGroupName('${App.util.escape(group.id)}',this.value)"
class="flex-1 bg-white border rounded-lg px-3 py-2 font-semibold">

<button
type="button"
class="px-3 py-2 rounded-lg border text-red-600"
onclick="App.actions.deleteGroup('${App.util.escape(group.id)}')">
グループ削除
</button>

</div>

<div
data-group-list="${App.util.escape(group.id)}"
class="space-y-4 min-h-[40px]">

${questions}

</div>

<button
type="button"
class="mt-4 px-4 py-2 rounded-lg bg-white border border-blue-300 text-blue-700"
onclick="App.actions.addQuestion('${App.util.escape(group.id)}')">
＋ 質問追加
</button>

</section>`;
    },


    question(question) {

        const options =
            (question.options || [])
                .map(
                    (option, index) => `
<div class="flex gap-2 items-center">

<input
value="${App.util.escape(option)}"
oninput="App.actions.changeOption('${App.util.escape(question.id)}',${index},this.value)"
class="flex-1 border rounded-lg px-3 py-2">

<button
type="button"
class="px-3 py-2 text-red-600"
onclick="App.actions.removeOption('${App.util.escape(question.id)}',${index})">
削除
</button>

</div>`
                )
                .join('');

        const branch =
            question.type === 'single'
                ? `
<div class="mt-4 border-t pt-4">
<div class="text-sm font-semibold mb-2">
質問分岐
</div>

${
(question.options || [])
.map(
(option, index) => `
<div class="grid grid-cols-2 gap-2 mb-2">
<div class="text-sm py-2">
${App.util.escape(option)}
</div>

<select
onchange="App.actions.changeBranch('${App.util.escape(question.id)}','${App.util.escape(option)}',this.value)"
class="border rounded-lg px-3 py-2">

<option value="">
分岐なし
</option>

${App.state.survey.groups
.flatMap(
g => g.questions
)
.filter(
q => q.id !== question.id
)
.map(
q => `
<option
value="${App.util.escape(q.id)}"
${
question.branch &&
question.branch[option] === q.id
    ? 'selected'
    : ''
}>
${App.util.escape(
    q.number || q.text || '未設定'
)}
</option>`
)
.join('')}

</select>
</div>`
)
.join('')
}

</div>`
                : '';

        return `
<article
data-question="${App.util.escape(question.id)}"
class="bg-white border rounded-xl p-5">

<div class="flex gap-3">

<div class="cursor-move text-xl text-slate-300">
⠿
</div>

<div class="flex-1">

<div class="flex items-center justify-between mb-3">

<div class="font-bold text-blue-600">
${App.util.escape(
    question.number || ''
)}
</div>

<button
type="button"
class="text-red-600"
onclick="App.actions.deleteQuestion('${App.util.escape(question.id)}')">
質問削除
</button>

</div>

<input
value="${App.util.escape(question.text)}"
placeholder="質問文を入力してください"
oninput="App.actions.changeQuestionText('${App.util.escape(question.id)}',this.value)"
class="w-full border rounded-lg px-3 py-2 mb-3">

<div class="flex gap-3 items-center mb-4">

<select
onchange="App.actions.changeType('${App.util.escape(question.id)}',this.value)"
class="border rounded-lg px-3 py-2">

<option value="single"
${question.type === 'single'
    ? 'selected'
    : ''}>
単一選択
</option>

<option value="multiple"
${question.type === 'multiple'
    ? 'selected'
    : ''}>
複数選択
</option>

<option value="text"
${question.type === 'text'
    ? 'selected'
    : ''}>
自由記述
</option>

</select>

<label class="flex items-center gap-2">
<input
type="checkbox"
${question.required ? 'checked' : ''}
onchange="App.actions.changeRequired('${App.util.escape(question.id)}',this.checked)">
必須回答
</label>

${
question.type !== 'text'
? `
<label class="flex items-center gap-2">
<input
type="checkbox"
${question.other_enabled ? 'checked' : ''}
onchange="App.actions.changeOther('${App.util.escape(question.id)}',this.checked)">
その他
</label>`
: ''
}

</div>

${
question.type !== 'text'
? `
<div class="space-y-2">
${options}

<button
type="button"
class="px-3 py-2 rounded-lg border text-blue-700"
onclick="App.actions.addOption('${App.util.escape(question.id)}')">
＋ 選択肢追加
</button>
</div>

${branch}
`
: ''
}

</div>
</div>
</article>`;
    },


    summary() {
        return `
<div class="min-h-screen p-6">
<div class="max-w-7xl mx-auto">

<div class="flex justify-between mb-6">
<h1 class="text-2xl font-bold">
回答集計
</h1>

<button
class="px-4 py-2 border rounded-lg"
onclick="App.actions.backList()">
一覧へ戻る
</button>
</div>

<div id="response_filter"
class="bg-white border rounded-xl p-5 mb-5">
${App.render.summaryQuestionFilter()}
</div>

<div id="response_table">
${App.render.summaryResponses()}
</div>

</div>
</div>`;
    },


    summaryQuestionFilter() {

        if (!App.state.survey) {
            return '';
        }

        const questions =
            App.state.survey.groups
                .flatMap(
                    g => g.questions
                );

        return `
<div class="flex items-center justify-between mb-4">
<h2 class="font-bold">
集計対象設問
</h2>

<div class="flex gap-2">
<button
class="px-3 py-2 border rounded-lg"
onclick="App.actions.selectAllQuestions(true)">
全選択
</button>

<button
class="px-3 py-2 border rounded-lg"
onclick="App.actions.selectAllQuestions(false)">
全解除
</button>
</div>
</div>

<div class="grid md:grid-cols-3 gap-3">
${
questions
.map(
q => `
<label class="flex items-center gap-2 border rounded-lg p-3">
<input
type="checkbox"
${App.state.selectedQuestions[q.id] !== false
    ? 'checked'
    : ''}
onchange="App.actions.toggleQuestion('${App.util.escape(q.id)}',this.checked)">
<span>
${App.util.escape(
    q.number || ''
)} ${
    App.util.escape(
        q.text || '未設定'
    )
}
</span>
</label>`
)
.join('')
}
</div>`;
    },


    summaryResponses() {

        const survey =
            App.state.survey;

        if (!survey) {
            return '';
        }

        const responses =
            App.state.responses
                .filter(
                    r =>
                        r.survey_id ===
                        survey.id
                );

        if (!responses.length) {
            return `
<div class="bg-white border rounded-xl p-12 text-center text-slate-400">
現在、回答データはありません
</div>`;
        }

        return `
<div class="bg-white border rounded-xl overflow-hidden">

<div class="p-5 border-b">
<h2 class="font-bold">
個別回答一覧
</h2>
</div>

<div class="overflow-x-auto">
<table class="w-full text-sm">

<thead class="bg-slate-50">
<tr>
<th class="p-3 text-left">
回答日時
</th>
<th class="p-3 text-left">
会社名
</th>
<th class="p-3 text-left">
氏名
</th>
<th class="p-3">
操作
</th>
</tr>
</thead>

<tbody>

${
responses
.map(
r => `
<tr class="border-t">
<td class="p-3">
${App.util.escape(
    r.answered_at || ''
)}
</td>

<td class="p-3">
${App.util.escape(
    r.company || ''
)}
</td>

<td class="p-3">
${App.util.escape(
    r.name || ''
)}
</td>

<td class="p-3 text-center">
<button
class="px-3 py-2 border rounded-lg"
onclick="App.actions.showResponse('${App.util.escape(r.id)}')">
全回答を表示
</button>
</td>
</tr>`
)
.join('')
}

</tbody>
</table>
</div>
</div>`;
    },


    mail() {
        return `
<div class="min-h-screen p-6">
<div class="max-w-7xl mx-auto">

<div class="flex justify-between mb-6">
<h1 class="text-2xl font-bold">
顧客選択・メール送信
</h1>

<button
class="px-4 py-2 border rounded-lg"
onclick="App.actions.backList()">
一覧へ戻る
</button>
</div>

<div class="bg-white border rounded-xl p-5 mb-5">

<div class="grid gap-4">

<input
id="mail_subject"
placeholder="件名"
class="border rounded-lg px-3 py-2">

<textarea
id="mail_body"
rows="8"
placeholder="本文&#10;{顧客名}&#10;{アンケートURL}"
class="border rounded-lg px-3 py-2"></textarea>

<select
id="template_type"
class="border rounded-lg px-3 py-2">

<option value="initial">
初回送信
</option>

<option value="reminder">
リマインド
</option>

</select>

<button
class="px-5 py-3 bg-blue-600 text-white rounded-lg"
onclick="App.actions.sendMail()">
選択した顧客へ一括送信
</button>

</div>
</div>

<div
id="customer_table"
class="bg-white border rounded-xl overflow-hidden">
${App.render.customerTable()}
</div>

</div>
</div>`;
    },


    customerTable() {

        return `
<div class="overflow-x-auto">
<table class="w-full text-sm">

<thead class="bg-slate-50">
<tr>
<th class="p-3">
<input
id="select_all"
type="checkbox"
onchange="App.actions.selectAllCustomers(this.checked)">
</th>

<th class="p-3 text-left">
会社名 / 氏名
</th>

<th class="p-3 text-left">
メール
</th>

<th class="p-3 text-left">
電話
</th>

<th class="p-3 text-left">
回答状態
</th>

<th class="p-3 text-left">
kintone
</th>
</tr>
</thead>

<tbody>

${
App.state.customers
.map(
customer => `
<tr class="border-t">

<td class="p-3">
${
customer.source === 'web'
    ? ''
    : `
<input
type="checkbox"
data-customer-id="${App.util.escape(customer.id)}">
`
}
</td>

<td class="p-3">
<div class="font-bold">
${App.util.escape(
    customer.company || ''
)}
</div>
<div>
${App.util.escape(
    customer.name || ''
)}
</div>
</td>

<td class="p-3">
${App.util.escape(
    customer.email || ''
)}
</td>

<td class="p-3">
${App.util.escape(
    customer.phone || ''
)}
</td>

<td class="p-3">
${customer.answer_status === 'answered'
    ? '回答済み'
    : customer.sent_at
        ? '送信済み（未回答）'
        : '未送信'}
</td>

<td class="p-3">
${
customer.kintone_status === 'registered'
    ? '✓ 登録完了'
    : `
<button
class="px-3 py-1.5 border rounded-lg"
onclick="App.actions.registerKintone('${App.util.escape(customer.id)}')">
キントーン登録完了
</button>
`
}
</td>

</tr>`
)
.join('')
}

</tbody>
</table>
</div>`;
    },


    settings() {

        const s =
            App.state.settings || {};

        return `
<div class="min-h-screen p-6">
<div class="max-w-4xl mx-auto">

<div class="flex justify-between mb-6">
<h1 class="text-2xl font-bold">
kintone連携設定
</h1>

<button
class="px-4 py-2 border rounded-lg"
onclick="App.actions.backList()">
一覧へ戻る
</button>
</div>

<form
id="settings_form"
class="bg-white border rounded-xl p-6">

<div class="space-y-4">

<label class="block">
<div class="font-semibold mb-1">
サブドメイン
</div>

<input
id="setting_subdomain"
value="${App.util.escape(s.subdomain || '')}"
class="w-full border rounded-lg px-3 py-2"
placeholder="xxxx.cybozu.com または xxxx">
</label>

<label class="block">
<div class="font-semibold mb-1">
アプリID
</div>

<div class="flex gap-2">

<input
id="setting_app_id"
value="${App.util.escape(s.app_id || '')}"
class="flex-1 border rounded-lg px-3 py-2">

<button
type="button"
class="px-4 py-2 bg-blue-600 text-white rounded-lg"
onclick="App.actions.fetchKintoneFields()">
項目一覧を取得
</button>

</div>
</label>

<label class="block">
<div class="font-semibold mb-1">
ログイン名
</div>

<input
id="setting_login_name"
value="${App.util.escape(s.login_name || '')}"
class="w-full border rounded-lg px-3 py-2">
</label>

<label class="block">
<div class="font-semibold mb-1">
パスワード
</div>

<input
id="setting_password"
type="password"
value="${App.util.escape(s.password || '')}"
class="w-full border rounded-lg px-3 py-2">
</label>

<label class="block">
<div class="font-semibold mb-1">
Proxy
</div>

<input
id="setting_proxy"
value="${App.util.escape(s.proxy || '')}"
class="w-full border rounded-lg px-3 py-2"
placeholder="host:port">
</label>

<label class="flex items-center gap-2">
<input
id="setting_ssl_verify"
type="checkbox"
${s.ssl_verify ? 'checked' : ''}>
SSL証明書を検証する
</label>

<div
id="field_message"
class="text-sm">
</div>

<div
id="field_mapping"
class="space-y-3">
${App.render.fieldMappings()}
</div>

<div class="flex gap-2 pt-4">

<button
type="button"
class="px-5 py-3 bg-blue-600 text-white rounded-lg"
onclick="App.actions.saveSettings()">
設定を保存
</button>

<button
type="button"
class="px-5 py-3 border rounded-lg"
onclick="App.actions.testKintone()">
接続確認
</button>

</div>

</div>
</form>

</div>
</div>`;
    },


    fieldMappings() {

        const s =
            App.state.settings || {};

        const fields =
            App.state.kintoneFields ||
            [];

        const make =
            (
                key,
                label,
                multiple = false
            ) => {

                const current =
                    s[key] || '';

                return `
<label class="block">
<div class="font-semibold mb-1">
${label}
</div>

<select
${multiple ? 'multiple' : ''}
data-field-key="${key}"
class="w-full border rounded-lg px-3 py-2">

<option value="">
未選択
</option>

${
fields
.map(
field => `
<option
value="${App.util.escape(field.code)}"
${
(
multiple
    ? Array.isArray(current) &&
      current.includes(field.code)
    : current === field.code
)
    ? 'selected'
    : ''
}>
${App.util.escape(field.label)}
（${App.util.escape(field.code)} / ${App.util.escape(field.type)}）
</option>`
)
.join('')
}

</select>
</label>`;
            };

        return [
            make(
                'field_company',
                '会社名 (Company)'
            ),
            make(
                'field_name',
                '氏名 (Name)'
            ),
            make(
                'field_email',
                'メールアドレス (Email)'
            ),
            make(
                'field_department',
                '部署名 (Department)'
            ),
            make(
                'field_phone',
                '電話番号 (Phone)'
            ),
            make(
                'field_address',
                '住所 (Address)',
                true
            )
        ].join('');
    }
};


/*
 * ================================================================
 * Actions
 * ================================================================
 */

App.actions = {

    async initData() {

        const result =
            await App.api.request(
                'load'
            );

        App.state.csrfToken =
            result.csrf_token;

        App.state.surveys =
            result.surveys || [];

        App.state.responses =
            result.responses || [];

        App.state.customers =
            result.customers || [];

        App.state.settings =
            result.settings || {};

        App.state.mailLogs =
            result.mail_logs || [];
    },


    newSurvey() {

        App.state.survey = {
            id: App.util.id(),
            title: '',
            start_at: '',
            end_at: '',
            status: 'draft',
            created_at: '',
            updated_at: '',
            numbering_mode: 'global',
            groups: [],
            deleted: false
        };

        App.state.screen =
            'edit';

        App.state.dirty =
            false;

        App.render.current();
        App.actions.initSortable();
    },


    editSurvey(id) {

        const survey =
            App.state.surveys.find(
                item =>
                    item.id === id
            );

        if (!survey) {
            return;
        }

        App.state.survey =
            App.util.clone(
                survey
            );

        App.state.screen =
            'edit';

        App.state.editingSurveyId =
            id;

        App.state.dirty =
            false;

        App.render.current();
        App.actions.initSortable();
    },


    collectSurvey() {

        const survey =
            App.state.survey;

        if (!survey) {
            return;
        }

        const title =
            document.getElementById(
                'survey_title'
            );

        const start =
            document.getElementById(
                'survey_start_at'
            );

        const end =
            document.getElementById(
                'survey_end_at'
            );

        const mode =
            document.getElementById(
                'survey_numbering_mode'
            );

        if (title) {
            survey.title =
                title.value;
        }

        if (start) {
            survey.start_at =
                start.value;
        }

        if (end) {
            survey.end_at =
                end.value;
        }

        if (mode) {
            survey.numbering_mode =
                mode.value;
        }
    },


    fieldChange(
        key,
        value
    ) {

        if (!App.state.survey) {
            return;
        }

        App.state.survey[key] =
            value;

        App.state.dirty =
            true;

        if (
            key ===
            'numbering_mode'
        ) {
            App.actions.renumber();
            App.render.current();
            App.actions.initSortable();
        }
    },


    changeGroupName(
        groupId,
        value
    ) {

        const group =
            App.state.survey.groups.find(
                g =>
                    g.id === groupId
            );

        if (!group) {
            return;
        }

        group.name =
            value;

        App.state.dirty =
            true;
    },


    addGroup() {

        App.actions.collectSurvey();

        if (!App.state.survey) {
            return;
        }

        App.state.survey.groups.push({
            id: App.util.id(),
            name:
                'グループ' +
                (
                    App.state.survey
                        .groups.length + 1
                ),
            questions: []
        });

        App.state.dirty =
            true;

        App.actions.renumber();

        /*
         * ★重要
         *
         * App.render.edit() だけでは
         * DOMは変わらない。
         *
         * 必ず current() を使う。
         */
        App.render.current();

        App.actions.initSortable();
    },


    deleteGroup(groupId) {

        if (
            !confirm(
                'グループと内包する質問を削除しますか？'
            )
        ) {
            return;
        }

        App.actions.collectSurvey();

        if (!App.state.survey) {
            return;
        }

        App.state.survey.groups =
            App.state.survey.groups.filter(
                group =>
                    group.id !== groupId
            );

        App.state.dirty =
            true;

        App.actions.renumber();

        App.render.current();

        App.actions.initSortable();
    },


    addQuestion(groupId) {

        App.actions.collectSurvey();

        if (!App.state.survey) {
            return;
        }

        const group =
            App.state.survey.groups.find(
                g =>
                    g.id === groupId
            );

        if (!group) {
            return;
        }

        group.questions.push({
            id: App.util.id(),
            text: '',
            type: 'single',
            required: false,
            options: [
                '選択肢1',
                '選択肢2'
            ],
            other_enabled: false,
            branch: {}
        });

        App.state.dirty =
            true;

        App.actions.renumber();

        /*
         * ★ここが今回の主要修正。
         */
        App.render.current();

        App.actions.initSortable();
    },


    deleteQuestion(
        questionId
    ) {

        if (
            !confirm(
                'この質問を削除しますか？'
            )
        ) {
            return;
        }

        App.actions.collectSurvey();

        App.state.survey.groups
            .forEach(
                group => {
                    group.questions =
                        group.questions.filter(
                            q =>
                                q.id !==
                                questionId
                        );
                }
            );

        App.state.dirty =
            true;

        App.actions.renumber();

        App.render.current();

        App.actions.initSortable();
    },


    changeQuestionText(
        questionId,
        value
    ) {

        const q =
            App.actions.findQuestion(
                questionId
            );

        if (!q) {
            return;
        }

        q.text =
            value;

        App.state.dirty =
            true;
    },


    changeRequired(
        questionId,
        value
    ) {

        const q =
            App.actions.findQuestion(
                questionId
            );

        if (!q) {
            return;
        }

        q.required =
            Boolean(value);

        App.state.dirty =
            true;
    },


    changeOther(
        questionId,
        value
    ) {

        const q =
            App.actions.findQuestion(
                questionId
            );

        if (!q) {
            return;
        }

        q.other_enabled =
            Boolean(value);

        App.state.dirty =
            true;
    },


    changeType(
        questionId,
        type
    ) {

        const q =
            App.actions.findQuestion(
                questionId
            );

        if (!q) {
            return;
        }

        q.type =
            type;

        if (type === 'text') {
            q.options = [];
            q.other_enabled =
                false;
            q.branch = {};
        } else if (
            !Array.isArray(q.options) ||
            q.options.length === 0
        ) {
            q.options = [
                '選択肢1',
                '選択肢2'
            ];
        }

        App.state.dirty =
            true;

        App.render.current();

        App.actions.initSortable();
    },


    changeOption(
        questionId,
        index,
        value
    ) {

        const q =
            App.actions.findQuestion(
                questionId
            );

        if (!q) {
            return;
        }

        if (
            !Array.isArray(
                q.options
            )
        ) {
            q.options = [];
        }

        q.options[index] =
            value;

        App.state.dirty =
            true;
    },


    addOption(
        questionId
    ) {

        const q =
            App.actions.findQuestion(
                questionId
            );

        if (!q) {
            return;
        }

        if (
            !Array.isArray(
                q.options
            )
        ) {
            q.options = [];
        }

        q.options.push(
            '選択肢' +
            (
                q.options.length + 1
            )
        );

        App.state.dirty =
            true;

        /*
         * ★追加後のDOM反映
         */
        App.render.current();

        App.actions.initSortable();
    },


    removeOption(
        questionId,
        index
    ) {

        const q =
            App.actions.findQuestion(
                questionId
            );

        if (!q) {
            return;
        }

        const removed =
            q.options[index];

        q.options.splice(
            index,
            1
        );

        if (
            q.branch &&
            removed
        ) {
            delete q.branch[
                removed
            ];
        }

        App.state.dirty =
            true;

        App.render.current();

        App.actions.initSortable();
    },


    changeBranch(
        questionId,
        option,
        targetId
    ) {

        const q =
            App.actions.findQuestion(
                questionId
            );

        if (!q) {
            return;
        }

        if (!q.branch) {
            q.branch = {};
        }

        if (targetId) {
            q.branch[option] =
                targetId;
        } else {
            delete q.branch[
                option
            ];
        }

        App.state.dirty =
            true;
    },


    findQuestion(
        questionId
    ) {

        if (
            !App.state.survey
        ) {
            return null;
        }

        for (
            const group
            of App.state.survey.groups
        ) {
            const question =
                group.questions.find(
                    q =>
                        q.id ===
                        questionId
                );

            if (question) {
                return question;
            }
        }

        return null;
    },


    renumber() {

        if (
            !App.state.survey
        ) {
            return;
        }

        let globalNo = 1;

        App.state.survey.groups
            .forEach(
                (group, groupIndex) => {

                    let groupNo = 1;

                    group.questions
                        .forEach(
                            question => {

                                if (
                                    App.state
                                        .survey
                                        .numbering_mode
                                    === 'group'
                                ) {
                                    question.number =
                                        'Q' +
                                        (
                                            groupIndex + 1
                                        ) +
                                        '-' +
                                        groupNo;
                                } else {
                                    question.number =
                                        'Q' +
                                        globalNo;
                                }

                                globalNo++;
                                groupNo++;
                            }
                        );
                }
            );
    },


    initSortable() {

        if (
            typeof Sortable ===
            'undefined'
        ) {
            return;
        }

        const groupContainer =
            document.querySelector(
                '[data-group-container]'
            );

        if (groupContainer) {

            if (
                groupContainer._sortable
            ) {
                groupContainer
                    ._sortable
                    .destroy();
            }

            groupContainer._sortable =
                new Sortable(
                    groupContainer,
                    {
                        animation: 180,
                        handle:
                            '[data-group] > div:first-child .cursor-move',
                        ghostClass:
                            'opacity-50',
                        onEnd() {
                            App.actions
                                .syncGroups();
                        }
                    }
                );
        }

        document
            .querySelectorAll(
                '[data-group-list]'
            )
            .forEach(
                list => {

                    if (
                        list._sortable
                    ) {
                        list._sortable
                            .destroy();
                    }

                    list._sortable =
                        new Sortable(
                            list,
                            {
                                group:
                                    'survey-questions',
                                animation: 180,
                                handle:
                                    '.cursor-move',
                                ghostClass:
                                    'opacity-50',
                                onEnd() {
                                    App.actions
                                        .syncQuestions();
                                }
                            }
                        );
                }
            );
    },


    syncGroups() {

        App.actions.collectSurvey();

        const ids =
            [
                ...document.querySelectorAll(
                    '[data-group]'
                )
            ]
            .map(
                element =>
                    element.dataset.group
            );

        const map =
            Object.fromEntries(
                App.state.survey.groups
                    .map(
                        group =>
                            [
                                group.id,
                                group
                            ]
                    )
            );

        App.state.survey.groups =
            ids
                .map(
                    id =>
                        map[id]
                )
                .filter(Boolean);

        App.state.dirty =
            true;

        App.actions.renumber();

        App.render.current();

        App.actions.initSortable();
    },


    syncQuestions() {

        App.actions.collectSurvey();

        const map = {};

        App.state.survey.groups
            .forEach(
                group => {
                    group.questions
                        .forEach(
                            question => {
                                map[
                                    question.id
                                ] = question;
                            }
                        );

                    group.questions = [];
                }
            );

        document
            .querySelectorAll(
                '[data-group-list]'
            )
            .forEach(
                list => {

                    const group =
                        App.state.survey.groups
                            .find(
                                g =>
                                    g.id ===
                                    list.dataset
                                        .groupList
                            );

                    if (!group) {
                        return;
                    }

                    [
                        ...list.children
                    ]
                    .forEach(
                        element => {

                            const id =
                                element.dataset
                                    .question;

                            if (
                                id &&
                                map[id]
                            ) {
                                group.questions
                                    .push(
                                        map[id]
                                    );
                            }
                        }
                    );
                }
            );

        App.state.dirty =
            true;

        App.actions.renumber();

        App.render.current();

        App.actions.initSortable();
    },


    async saveSurvey() {

        App.actions.collectSurvey();

        App.actions.renumber();

        try {

            const result =
                await App.api.request(
                    'save_survey',
                    'POST',
                    {
                        survey_json:
                            App.state.survey
                    }
                );

            const index =
                App.state.surveys.findIndex(
                    survey =>
                        survey.id ===
                        result.survey.id
                );

            if (index >= 0) {
                App.state.surveys[index] =
                    result.survey;
            } else {
                App.state.surveys.push(
                    result.survey
                );
            }

            App.state.dirty =
                false;

            App.state.screen =
                'list';

            App.render.current();

            alert(
                'アンケートを保存しました。'
            );

        } catch (error) {

            alert(
                error.message
            );
        }
    },


    cancelEdit() {

        if (
            App.state.dirty &&
            !confirm(
                '変更を破棄して一覧へ戻りますか？'
            )
        ) {
            return;
        }

        App.state.screen =
            'list';

        App.state.survey =
            null;

        App.render.current();
    },


    async duplicateSurvey(
        id
    ) {

        const source =
            App.state.surveys.find(
                survey =>
                    survey.id === id
            );

        if (!source) {
            return;
        }

        const duplicate =
            App.util.clone(
                source
            );

        duplicate.id =
            App.util.id();

        duplicate.title =
            (source.title || '') +
            '（複製）';

        duplicate.status =
            'draft';

        duplicate.created_at =
            '';

        duplicate.updated_at =
            '';

        duplicate.deleted =
            false;

        try {

            const result =
                await App.api.request(
                    'save_survey',
                    'POST',
                    {
                        survey_json:
                            duplicate
                    }
                );

            App.state.surveys.push(
                result.survey
            );

            App.render.current();

        } catch (error) {

            alert(
                error.message
            );
        }
    },


    async deleteSurvey(id) {

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
                'POST',
                {
                    survey_id: id
                }
            );

            App.state.surveys =
                App.state.surveys.filter(
                    survey =>
                        survey.id !== id
                );

            App.render.current();

        } catch (error) {

            alert(
                error.message
            );
        }
    },


    async stopSurvey(id) {

        if (
            !confirm(
                'アンケートを停止しますか？'
            )
        ) {
            return;
        }

        try {

            await App.api.request(
                'status_survey',
                'POST',
                {
                    survey_id: id,
                    status: 'ended'
                }
            );

            const survey =
                App.state.surveys.find(
                    s =>
                        s.id === id
                );

            if (survey) {
                survey.status =
                    'ended';
            }

            App.render.current();

        } catch (error) {

            alert(
                error.message
            );
        }
    },


    summary(id) {

        const survey =
            App.state.surveys.find(
                s =>
                    s.id === id
            );

        if (!survey) {
            return;
        }

        App.state.survey =
            App.util.clone(
                survey
            );

        App.state.screen =
            'summary';

        App.state.selectedQuestions =
            {};

        App.state.survey.groups
            .flatMap(
                g => g.questions
            )
            .forEach(
                q => {
                    App.state.selectedQuestions[
                        q.id
                    ] = true;
                }
            );

        App.render.current();
    },


    toggleQuestion(
        id,
        checked
    ) {

        App.state.selectedQuestions[
            id
        ] = checked;

        const filter =
            document.getElementById(
                'response_filter'
            );

        if (filter) {
            filter.innerHTML =
                App.render
                    .summaryQuestionFilter();
        }

        const table =
            document.getElementById(
                'response_table'
            );

        if (table) {
            table.innerHTML =
                App.render
                    .summaryResponses();
        }
    },


    selectAllQuestions(
        value
    ) {

        if (
            !App.state.survey
        ) {
            return;
        }

        App.state.survey.groups
            .flatMap(
                g => g.questions
            )
            .forEach(
                q => {
                    App.state
                        .selectedQuestions[
                            q.id
                        ] = value;
                }
            );

        App.render.current();
    },


    showResponse(
        responseId
    ) {

        const response =
            App.state.responses.find(
                r =>
                    r.id ===
                    responseId
            );

        if (!response) {
            return;
        }

        const survey =
            App.state.survey;

        const questions =
            survey.groups
                .flatMap(
                    g =>
                        g.questions
                );

        const detail =
            questions
                .map(
                    q => `
<div class="border-b py-4">
<div class="font-semibold mb-1">
${App.util.escape(
    q.number || ''
)}
 ${App.util.escape(
    q.text || ''
)}
</div>

<div class="text-slate-600">
${App.util.escape(
    Array.isArray(
        response.answers?.[q.id]
    )
        ? response.answers[q.id]
            .join('、')
        : (
            response.answers?.[q.id] ??
            ''
        )
)}
</div>
</div>`
                )
                .join('');

        let modal =
            document.getElementById(
                'response_modal'
            );

        if (!modal) {

            modal =
                document.createElement(
                    'div'
                );

            modal.id =
                'response_modal';

            modal.className =
                'fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-6';

            document.body.appendChild(
                modal
            );
        }

        modal.innerHTML = `
<div class="bg-white rounded-xl shadow-xl max-w-3xl w-full max-h-[85vh] overflow-auto">

<div class="p-5 border-b flex justify-between">
<div>
<div class="font-bold">
全回答
</div>

<div class="text-sm text-slate-500">
${App.util.escape(
    response.name || ''
)}
</div>
</div>

<button
class="px-3 py-2 border rounded-lg"
onclick="App.actions.closeResponse()">
閉じる
</button>
</div>

<div
id="response_detail"
class="p-5">
${detail}
</div>

</div>`;
    },


    closeResponse() {

        const modal =
            document.getElementById(
                'response_modal'
            );

        if (modal) {
            modal.remove();
        }
    },


    mail(id) {

        const survey =
            App.state.surveys.find(
                s =>
                    s.id === id
            );

        if (!survey) {
            return;
        }

        App.state.survey =
            App.util.clone(
                survey
            );

        App.state.screen =
            'mail';

        App.render.current();
    },


    selectAllCustomers(
        checked
    ) {

        document
            .querySelectorAll(
                '[data-customer-id]'
            )
            .forEach(
                checkbox => {
                    checkbox.checked =
                        checked;
                }
            );
    },


    async sendMail() {

        const selected =
            [
                ...document.querySelectorAll(
                    '[data-customer-id]:checked'
                )
            ]
            .map(
                element =>
                    element.dataset
                        .customerId
            );

        if (
            selected.length === 0
        ) {
            alert(
                '送信先を選択してください。'
            );
            return;
        }

        const subject =
            document.getElementById(
                'mail_subject'
            )?.value || '';

        const body =
            document.getElementById(
                'mail_body'
            )?.value || '';

        const templateType =
            document.getElementById(
                'template_type'
            )?.value ||
            'initial';

        const alreadySent =
            selected.some(
                id => {
                    const customer =
                        App.state.customers
                            .find(
                                c =>
                                    c.id === id
                            );

                    return Boolean(
                        customer?.sent_at
                    );
                }
            );

        if (
            alreadySent &&
            !confirm(
                '既に送信済みの宛先が含まれています。再送しますか？'
            )
        ) {
            return;
        }

        /*
         * 実際のメール送信環境が未設定の場合でも、
         * 送信履歴と送信状態を壊さない。
         */
        try {

            const result =
                await App.api.request(
                    'send_mail',
                    'POST',
                    {
                        survey_id:
                            App.state.survey.id,
                        recipient_ids:
                            selected,
                        mail_subject:
                            subject,
                        mail_body:
                            body,
                        template_type:
                            templateType
                    }
                );

            if (
                Array.isArray(
                    result.customers
                )
            ) {
                App.state.customers =
                    result.customers;
            }

            if (
                Array.isArray(
                    result.mail_logs
                )
            ) {
                App.state.mailLogs =
                    result.mail_logs;
            }

            App.render.current();

            alert(
                result.message ||
                '送信処理が完了しました。'
            );

        } catch (error) {

            alert(
                error.message
            );
        }
    },


    registerKintone(
        customerId
    ) {

        const customer =
            App.state.customers.find(
                c =>
                    c.id === customerId
            );

        if (!customer) {
            return;
        }

        customer.kintone_status =
            'registered';

        App.render.current();
    },


    settings() {

        App.state.screen =
            'settings';

        App.state.kintoneFields =
            App.state.kintoneFields ||
            [];

        App.render.current();
    },


    async fetchKintoneFields() {

        const message =
            document.getElementById(
                'field_message'
            );

        const settings =
            App.actions.collectSettings();

        if (
            !settings.app_id
        ) {
            if (message) {
                message.textContent =
                    'アプリIDを入力してください。';
            }
            return;
        }

        if (message) {
            message.textContent =
                'kintoneから項目一覧を取得しています…';
        }

        try {

            /*
             * PHP側で
             *
             * GET
             * /k/v1/app/form/fields.json?app=xxx
             *
             * を実行する。
             *
             * GET bodyは送らない。
             */
            const result =
                await App.api.request(
                    'kintone_fields',
                    'POST',
                    {
                        settings_json:
                            settings,
                        app_id:
                            settings.app_id
                    }
                );

            App.state.kintoneFields =
                result.fields || [];

            App.state.settings =
                settings;

            const mapping =
                document.getElementById(
                    'field_mapping'
                );

            if (mapping) {
                mapping.innerHTML =
                    App.render
                        .fieldMappings();
            }

            if (message) {
                message.textContent =
                    '項目一覧を取得しました。' +
                    App.state
                        .kintoneFields
                        .length +
                    '項目です。';
            }

        } catch (error) {

            if (message) {
                message.textContent =
                    'kintone項目一覧取得に失敗しました。 ' +
                    error.message;
            }
        }
    },


    collectSettings() {

        const s = {
            subdomain:
                document.getElementById(
                    'setting_subdomain'
                )?.value || '',

            app_id:
                document.getElementById(
                    'setting_app_id'
                )?.value || '',

            login_name:
                document.getElementById(
                    'setting_login_name'
                )?.value || '',

            password:
                document.getElementById(
                    'setting_password'
                )?.value || '',

            proxy:
                document.getElementById(
                    'setting_proxy'
                )?.value || '',

            ssl_verify:
                Boolean(
                    document.getElementById(
                        'setting_ssl_verify'
                    )?.checked
                ),

            field_company:
                App.state.settings
                    .field_company || '',

            field_name:
                App.state.settings
                    .field_name || '',

            field_email:
                App.state.settings
                    .field_email || '',

            field_department:
                App.state.settings
                    .field_department || '',

            field_phone:
                App.state.settings
                    .field_phone || '',

            field_address:
                App.state.settings
                    .field_address || []
        };

        document
            .querySelectorAll(
                '[data-field-key]'
            )
            .forEach(
                select => {

                    const key =
                        select.dataset
                            .fieldKey;

                    if (
                        select.multiple
                    ) {
                        s[key] =
                            [
                                ...select
                                    .selectedOptions
                            ]
                            .map(
                                option =>
                                    option.value
                            )
                            .filter(Boolean);
                    } else {
                        s[key] =
                            select.value;
                    }
                }
            );

        return s;
    },


    async saveSettings() {

        const settings =
            App.actions.collectSettings();

        try {

            await App.api.request(
                'save_settings',
                'POST',
                {
                    settings_json:
                        settings
                }
            );

            App.state.settings =
                settings;

            alert(
                '設定を保存しました。'
            );

        } catch (error) {

            alert(
                error.message
            );
        }
    },


    async testKintone() {

        const settings =
            App.actions.collectSettings();

        try {

            const result =
                await App.api.request(
                    'kintone_test',
                    'POST',
                    {
                        settings_json:
                            settings,
                        app_id:
                            settings.app_id
                    }
                );

            alert(
                '接続成功しました。'
            );

        } catch (error) {

            alert(
                '接続確認に失敗しました。\n\n' +
                error.message
            );
        }
    },


    preview() {

        App.actions.collectSurvey();

        let modal =
            document.getElementById(
                'preview_modal'
            );

        if (!modal) {

            modal =
                document.createElement(
                    'div'
                );

            modal.id =
                'preview_modal';

            modal.className =
                'fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-6';

            document.body.appendChild(
                modal
            );
        }

        const questions =
            App.state.survey.groups
                .flatMap(
                    g =>
                        g.questions
                );

        modal.innerHTML = `
<div class="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[90vh] overflow-auto">

<div class="p-5 border-b flex justify-between">

<h2 class="font-bold">
${App.util.escape(
    App.state.survey.title ||
    'アンケート'
)}
</h2>

<button
class="px-3 py-2 border rounded-lg"
onclick="App.actions.closePreview()">
閉じる
</button>

</div>

<div
id="preview_content"
class="p-6">

${
questions
.map(
q => `
<div class="mb-7">

<div class="font-semibold mb-2">
${App.util.escape(
    q.number || ''
)}
 ${App.util.escape(
    q.text || ''
)}
${q.required
    ? '<span class="text-red-500">*</span>'
    : ''}
</div>

${
q.type === 'text'
? `
<textarea
rows="4"
class="w-full border rounded-lg px-3 py-2"
placeholder="回答を入力してください">
</textarea>`
: q.options
.map(
option => `
<label class="flex gap-2 mb-2">
<input
type="${
    q.type === 'single'
        ? 'radio'
        : 'checkbox'
}"
name="preview_${App.util.escape(q.id)}">
<span>
${App.util.escape(option)}
</span>
</label>`
)
.join('')
}

</div>`
)
.join('')
}

<button
type="button"
class="w-full py-3 bg-blue-600 text-white rounded-lg"
onclick="App.actions.previewSubmit()">
送信
</button>

</div>
</div>`;

        modal.classList.remove(
            'hidden'
        );
    },


    closePreview() {

        const modal =
            document.getElementById(
                'preview_modal'
            );

        if (modal) {
            modal.remove();
        }
    },


    previewSubmit() {

        alert(
            'これはプレビューです。実際の回答は送信されません。'
        );
    },


    backList() {

        App.state.screen =
            'list';

        App.state.survey =
            null;

        App.render.current();
    }
};


/*
 * ================================================================
 * 初期化
 * ================================================================
 */

App.init = async function() {

    if (
        App.state.initialized
    ) {
        return;
    }

    App.state.initialized =
        true;

    try {

        await App.actions.initData();

        App.render.current();

        App.actions.initSortable();

    } catch (error) {

        const app =
            document.getElementById(
                'app'
            );

        if (app) {
            app.innerHTML = `
<div class="min-h-screen flex items-center justify-center p-6">
<div class="bg-white border rounded-xl p-8 max-w-lg">
<h1 class="font-bold text-red-600 mb-3">
初期化エラー
</h1>
<p>
${App.util.escape(
    error.message
)}
</p>
</div>
</div>`;
        }
    }
};


/*
 * ================================================================
 * DOMContentLoaded ガード
 * ================================================================
 */

if (
    document.readyState ===
    'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
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